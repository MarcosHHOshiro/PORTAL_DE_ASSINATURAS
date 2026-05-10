<?php

declare(strict_types=1);

namespace App\PortalAssinaturas;

//Monta o corpo da requisição para a API
final class DocumentPayloadFactory
{
    private readonly SignerNormalizer $signerNormalizer;

    public function __construct(?SignerNormalizer $signerNormalizer = null)
    {
        $this->signerNormalizer = $signerNormalizer ?? new SignerNormalizer();
    }

    public function build(string $uploadId, string $documentName, string $uploadedFileName, array $signers): array
    {
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
                    'accessCode' => $this->signerNormalizer->buildAccessCode($signer['cpf']),
                ],
                $signers,
                array_keys($signers)
            ),
        ];
    }
}
