<?php

declare(strict_types=1);

namespace App\PortalAssinaturas;

use App\Http\ApiClient;
use App\Http\ApiException;
use RuntimeException;

final class DocumentService
{
    public function __construct(private readonly ApiClient $apiClient, private readonly ?int $userId = null)
    {
    }

    public function uploadPdf(string $filePath, ?string $fileName = null): array
    {
        $this->assertValidPdf($filePath, $fileName);

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
        $normalizedSigners = $this->normalizeSigners($signers);
        $uploadedFileName = $this->ensurePdfFileName($uploadedFileName ?: $documentName);

        if ($documentName === '') {
            throw new RuntimeException('Dados do documento ou do assinante invalidos para criar o lote.');
        }

        $documentPayload = $this->buildDocumentPayload($uploadId, $documentName, $uploadedFileName, $normalizedSigners);
        $response = $this->createDocumentThroughSupportedEndpoint($documentPayload);
        $normalized = $this->normalizeCreateBatchResponse($response);
        $normalized['signers'] = $this->mergeSignerLinks($normalizedSigners, $response);

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

        $bytes = $this->extractPackageBytes($response);
        $fileName = $this->sanitizeFileName($response['name'] ?? ('documento-assinado-' . $documentKey . '.zip'));
        $targetPath = $this->resolveOutputPath($outputPath, $fileName);

        $directory = dirname($targetPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($targetPath, $bytes);

        return $targetPath;
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

    private function assertValidPdf(string $filePath, ?string $fileName = null): void
    {
        if ($filePath === '' || !is_file($filePath)) {
            throw new RuntimeException('O arquivo PDF enviado nao foi encontrado.');
        }

        if ((int) filesize($filePath) <= 0) {
            throw new RuntimeException('O arquivo PDF nao pode estar vazio.');
        }

        $extension = strtolower(pathinfo($fileName ?: $filePath, PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            throw new RuntimeException('O arquivo enviado deve possuir extensao .pdf.');
        }

        $signature = file_get_contents($filePath, false, null, 0, 4);
        $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : null;

        if ($signature !== '%PDF' || ($mime !== false && $mime !== null && !in_array($mime, ['application/pdf', 'application/octet-stream'], true))) {
            throw new RuntimeException('O arquivo enviado nao e um PDF valido.');
        }
    }

    private function normalizeCreateBatchResponse(array $response): array
    {
        $document = $response['documents'][0] ?? $response['document'] ?? [];
        $attendee = $response['attendees'][0] ?? [];
        $signUrl = $document['signUrl'] ?? $response['signUrl'] ?? $response['batchSignUrl'] ?? $attendee['signUrl'] ?? null;

        return [
            'portal_document_id' => $document['id'] ?? $response['id'] ?? null,
            'document_key' => $document['key']
                ?? $document['chave']
                ?? $response['key']
                ?? $response['chave']
                ?? $attendee['key']
                ?? $attendee['chave']
                ?? $this->inferDocumentKeyFromSignUrl($signUrl),
            'sign_url' => $signUrl,
            'errors' => $response['errors'] ?? [],
        ];
    }

    private function buildDocumentPayload(
        string $uploadId,
        string $documentName,
        string $uploadedFileName,
        array $signers
    ): array {
        return [
            'document' => [
                'name' => $documentName,
                'upload' => [
                    'id' => $uploadId,
                    'name' => $uploadedFileName,
                ],
            ],
            'electronicSigners' => array_map(
                fn (array $signer, int $index): array => [
                    'step' => 1,
                    'title' => 'Assinante ' . ($index + 1),
                    'name' => $signer['name'],
                    'email' => $signer['email'],
                    'individualIdentificationCode' => $signer['cpf'],
                    'identificationType' => [
                        'accessCode' => true,
                    ],
                    'accessCode' => $this->buildAccessCode($signer['cpf']),
                ],
                $signers,
                array_keys($signers)
            ),
        ];
    }

    private function mergeSignerLinks(array $signers, array $response): array
    {
        $attendees = $this->extractAttendees($response);

        foreach ($signers as $index => $signer) {
            foreach ($attendees as $attendee) {
                if (!is_array($attendee)) {
                    continue;
                }

                $attendeeEmail = strtolower(trim((string) ($attendee['email'] ?? $attendee['mail'] ?? '')));
                $attendeeCpf = $this->sanitizeCpf((string) ($attendee['individualIdentificationCode'] ?? $attendee['cpf'] ?? ''));
                $matchesEmail = $attendeeEmail !== '' && $attendeeEmail === $signer['email'];
                $matchesCpf = $attendeeCpf !== null && $attendeeCpf === $signer['cpf'];

                if (!$matchesEmail && !$matchesCpf) {
                    continue;
                }

                $signers[$index]['sign_url'] = $attendee['signUrl']
                    ?? $attendee['link']
                    ?? $attendee['linkFrame']
                    ?? null;
                break;
            }
        }

        return $signers;
    }

    private function extractAttendees(array $response): array
    {
        $document = $response['documents'][0] ?? $response['document'] ?? [];
        $attendees = [];

        foreach ([$response['attendees'] ?? null, $document['attendees'] ?? null] as $candidate) {
            if (is_array($candidate)) {
                $attendees = array_merge($attendees, $candidate);
            }
        }

        if (isset($response['steps']) && is_array($response['steps'])) {
            foreach ($response['steps'] as $step) {
                if (isset($step['attendees']) && is_array($step['attendees'])) {
                    $attendees = array_merge($attendees, $step['attendees']);
                }
            }
        }

        return $attendees;
    }

    private function normalizeSigners(array $signers): array
    {
        $normalized = [];

        foreach ($signers as $signer) {
            if (!is_array($signer)) {
                continue;
            }

            $name = trim((string) ($signer['name'] ?? ''));
            $email = strtolower(trim((string) ($signer['email'] ?? '')));
            $cpf = $this->sanitizeCpf(isset($signer['cpf']) ? (string) $signer['cpf'] : null);

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $cpf === null) {
                throw new RuntimeException('Todos os assinantes precisam ter nome, e-mail valido e CPF.');
            }

            $normalized[] = [
                'name' => $name,
                'email' => $email,
                'cpf' => $cpf,
                'access_code' => $this->buildAccessCode($cpf),
            ];
        }

        if ($normalized === []) {
            throw new RuntimeException('Adicione pelo menos um assinante ao documento.');
        }

        return $normalized;
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

    private function inferDocumentKeyFromSignUrl(?string $signUrl): ?string
    {
        if (!is_string($signUrl) || trim($signUrl) === '') {
            return null;
        }

        $query = parse_url($signUrl, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);

        foreach (['key', 'documentKey', 'document_key'] as $candidate) {
            $value = $params[$candidate] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractPackageBytes(array $response): string
    {
        if (isset($response['bytes']) && is_array($response['bytes'])) {
            return pack('C*', ...$response['bytes']);
        }

        if (isset($response['bytes']) && is_string($response['bytes'])) {
            $decoded = base64_decode($response['bytes'], true);

            if ($decoded !== false) {
                return $decoded;
            }

            return $response['bytes'];
        }

        if (isset($response['rawBody']) && is_string($response['rawBody'])) {
            $decoded = base64_decode($response['rawBody'], true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        throw new ApiException('A resposta do pacote assinado nao trouxe bytes utilizaveis.', null, $response);
    }

    private function resolveOutputPath(string $outputPath, string $fileName): string
    {
        $normalized = rtrim($outputPath, DIRECTORY_SEPARATOR);

        if ($normalized === '' || is_dir($normalized) || pathinfo($normalized, PATHINFO_EXTENSION) === '') {
            return $normalized . DIRECTORY_SEPARATOR . $fileName;
        }

        return $normalized;
    }

    private function sanitizeFileName(string $fileName): string
    {
        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '-', $fileName) ?: 'documento-assinado.zip';
        $fileName = trim($fileName, '-');

        return $fileName === '' ? 'documento-assinado.zip' : $fileName;
    }

    private function sanitizeCpf(?string $cpf): ?string
    {
        if ($cpf === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $cpf) ?: '';

        return $digits === '' ? null : $digits;
    }

    private function buildAccessCode(string $cpf): string
    {
        return substr(str_pad($cpf, 6, '0', STR_PAD_LEFT), -6);
    }
}
