<?php

declare(strict_types=1);

namespace App\PortalAssinaturas;

use App\Http\ApiClient;
use App\Http\ApiException;
use RuntimeException;

final class DocumentService
{
    private readonly ApiClient $apiClient;
    private readonly ?int $userId;
    private readonly PdfValidator $pdfValidator;
    private readonly SignerNormalizer $signerNormalizer;
    private readonly DocumentPayloadFactory $payloadFactory;
    private readonly CreateBatchResponseNormalizer $responseNormalizer;
    private readonly SignedPackageWriter $packageWriter;

    public function __construct(
        ApiClient $apiClient,
        ?int $userId = null,
        ?PdfValidator $pdfValidator = null,
        ?SignerNormalizer $signerNormalizer = null,
        ?DocumentPayloadFactory $payloadFactory = null,
        ?CreateBatchResponseNormalizer $responseNormalizer = null,
        ?SignedPackageWriter $packageWriter = null
    ) {
        $this->apiClient = $apiClient;
        $this->userId = $userId;
        $this->pdfValidator = $pdfValidator ?? new PdfValidator();
        $this->signerNormalizer = $signerNormalizer ?? new SignerNormalizer();
        $this->payloadFactory = $payloadFactory ?? new DocumentPayloadFactory($this->signerNormalizer);
        $this->responseNormalizer = $responseNormalizer ?? new CreateBatchResponseNormalizer($this->signerNormalizer);
        $this->packageWriter = $packageWriter ?? new SignedPackageWriter();
    }

    public function uploadPdf(string $filePath, ?string $fileName = null): array
    {
        $this->pdfValidator->assertValid($filePath, $fileName);

        $payload = [
            'fileName' => $fileName ?: basename($filePath),
            'bytes' => array_values(unpack('C*', (string) file_get_contents($filePath))),
        ];

        $response = $this->apiClient->post(PortalEndpoints::UPLOAD, $payload, $this->userId);
        $uploadId = $response['uploadId'] ?? $response['id'] ?? $response['upload']['id'] ?? null;

        if (!is_string($uploadId) || $uploadId === '') {
            throw new ApiException('A API nao retornou um uploadId valido no upload do PDF.', null, $response);
        }

        $response['uploadId'] = $uploadId;

        return $response;
    }

    public function createBatch(
        string $uploadId,
        string $documentName,
        string $signerName,
        string $signerEmail,
        ?string $signerCpf = null,
        ?string $uploadedFileName = null
    ): array {
        return $this->createBatchWithSigners($uploadId, $documentName, [
            [
                'name' => $signerName,
                'email' => $signerEmail,
                'cpf' => $signerCpf,
            ],
        ], $uploadedFileName);
    }

    public function createBatchWithSigners(
        string $uploadId,
        string $documentName,
        array $signers,
        ?string $uploadedFileName = null
    ): array {
        $documentName = trim($documentName);
        $normalizedSigners = $this->signerNormalizer->normalize($signers);
        $uploadedFileName = $this->ensurePdfFileName($uploadedFileName ?: $documentName);

        if ($documentName === '') {
            throw new RuntimeException('Dados do documento ou do assinante invalidos para criar o lote.');
        }

        $documentPayload = $this->payloadFactory->build($uploadId, $documentName, $uploadedFileName, $normalizedSigners);
        $response = $this->createDocumentThroughSupportedEndpoint($documentPayload);
        $normalized = $this->responseNormalizer->normalize($response);
        $normalized['signers'] = $this->responseNormalizer->mergeSignerLinks($normalizedSigners, $response);

        if (
            $normalized['portal_document_id'] === null
            || $normalized['document_key'] === null
            || $normalized['sign_url'] === null
        ) {
            throw new ApiException('A API nao retornou os dados minimos esperados para o lote, incluindo a chave do documento.', null, $response);
        }

        return $normalized + ['raw' => $response];
    }

    public function validateSignatures(string $documentKey): array
    {
        if (trim($documentKey) === '') {
            throw new RuntimeException('A chave do documento e obrigatoria para validar assinaturas.');
        }

        $response = $this->apiClient->get(
            PortalEndpoints::VALIDATE_SIGNATURES . '?key=' . rawurlencode($documentKey),
            $this->userId
        );

        $response['isValid'] = ($response['isValid'] ?? false) === true;
        $electronicSignatures = $response['electronicSignatures'] ?? [];
        $response['hasElectronicSignature'] = is_array($electronicSignatures) && count($electronicSignatures) > 0;

        return $response;
    }

    public function downloadPackage(string $documentKey, string $outputPath): string
    {
        if (trim($documentKey) === '') {
            throw new RuntimeException('A chave do documento e obrigatoria para baixar o pacote.');
        }

        $response = $this->apiClient->get(
            PortalEndpoints::PACKAGE
            . '?key=' . rawurlencode($documentKey)
            . '&includeOriginal=true&includeManifest=true&zipped=true',
            $this->userId
        );

        return $this->packageWriter->save($response, $documentKey, $outputPath);
    }

    public function deleteDocument(int|string $portalDocumentId): array
    {
        if ((string) $portalDocumentId === '') {
            throw new RuntimeException('O ID do documento no portal e obrigatorio para exclusao.');
        }

        return $this->apiClient->delete(
            PortalEndpoints::DELETE_DOCUMENT . '/' . rawurlencode((string) $portalDocumentId),
            $this->userId
        );
    }

    private function createDocumentThroughSupportedEndpoint(array $documentPayload): array
    {
        try {
            return $this->apiClient->post(
                PortalEndpoints::CREATE_BATCH,
                ['documents' => [$documentPayload]],
                $this->userId
            );
        } catch (ApiException $exception) {
            $responseBody = $exception->getResponseBody();
            $errorCode = is_array($responseBody) ? ($responseBody['code'] ?? null) : null;

            if ($exception->getStatusCode() !== 406 || $errorCode !== 739) {
                throw $exception;
            }
        }

        return $this->apiClient->post(PortalEndpoints::CREATE, $documentPayload, $this->userId);
    }

    private function ensurePdfFileName(string $fileName): string
    {
        $trimmed = trim($fileName);

        if ($trimmed === '') {
            return 'documento.pdf';
        }

        $extension = strtolower(pathinfo($trimmed, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return $trimmed;
        }

        return $trimmed . '.pdf';
    }
}
