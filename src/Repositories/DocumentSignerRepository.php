<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class DocumentSignerRepository
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function createMany(int $documentId, array $signers): void
    {
        foreach ($signers as $signer) {
            if (!is_array($signer)) {
                continue;
            }

            $this->create($documentId, $signer);
        }
    }

    public function replaceForDocument(int $documentId, array $signers): void
    {
        $this->deleteByDocument($documentId);
        $this->createMany($documentId, $signers);
    }

    public function findByDocument(int $documentId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT * FROM document_signers WHERE document_id = :document_id ORDER BY id ASC'
        );
        $statement->execute([':document_id' => $documentId]);

        return $statement->fetchAll() ?: [];
    }

    public function findByDocuments(array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));

        if ($documentIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($documentIds), '?'));
        $statement = $this->pdo()->prepare(
            'SELECT * FROM document_signers WHERE document_id IN (' . $placeholders . ') ORDER BY document_id ASC, id ASC'
        );
        $statement->execute($documentIds);

        $grouped = [];

        foreach ($statement->fetchAll() ?: [] as $signer) {
            $grouped[(int) $signer['document_id']][] = $signer;
        }

        return $grouped;
    }

    public function updateStatusesFromValidation(int $documentId, array $validationResponse): array
    {
        $signers = $this->findByDocument($documentId);

        if ($signers === []) {
            return [];
        }

        $updatedSigners = $this->applyValidationStatuses($signers, $validationResponse);

        foreach ($updatedSigners as $signer) {
            $this->updateSignerStatus((int) $signer['id'], (string) $signer['status']);
        }

        return $updatedSigners;
    }

    private function create(int $documentId, array $signer): void
    {
        $now = date('c');
        $cpf = $this->digits((string) ($signer['cpf'] ?? ''));
        $accessCode = (string) ($signer['access_code'] ?? ($cpf !== '' ? substr(str_pad($cpf, 6, '0', STR_PAD_LEFT), -6) : ''));

        $statement = $this->pdo()->prepare(
            'INSERT INTO document_signers (
                document_id, name, email, cpf, access_code, sign_url, status, created_at, updated_at
            ) VALUES (
                :document_id, :name, :email, :cpf, :access_code, :sign_url, :status, :created_at, :updated_at
            )'
        );

        $statement->execute([
            ':document_id' => $documentId,
            ':name' => (string) ($signer['name'] ?? 'Assinante'),
            ':email' => strtolower(trim((string) ($signer['email'] ?? ''))),
            ':cpf' => $cpf !== '' ? $cpf : null,
            ':access_code' => $accessCode !== '' ? $accessCode : null,
            ':sign_url' => $signer['sign_url'] ?? null,
            ':status' => $signer['status'] ?? 'PENDING_SIGNATURE',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function deleteByDocument(int $documentId): void
    {
        $statement = $this->pdo()->prepare('DELETE FROM document_signers WHERE document_id = :document_id');
        $statement->execute([':document_id' => $documentId]);
    }

    private function updateSignerStatus(int $id, string $status): void
    {
        $statement = $this->pdo()->prepare(
            'UPDATE document_signers SET status = :status, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':id' => $id,
            ':status' => $status,
            ':updated_at' => date('c'),
        ]);
    }

    private function applyValidationStatuses(array $signers, array $validationResponse): array
    {
        $signatures = $validationResponse['electronicSignatures'] ?? [];

        if (!is_array($signatures)) {
            $signatures = [];
        }

        $hasEnoughSignaturesForEveryone = ($validationResponse['isValid'] ?? false) === true
            && count($signatures) >= count($signers)
            && count($signers) > 0;

        return array_map(function (array $signer) use ($signatures, $signers, $hasEnoughSignaturesForEveryone): array {
            $signer['status'] = $hasEnoughSignaturesForEveryone
                || (count($signers) === 1 && $signatures !== [])
                || $this->signerHasSignature($signer, $signatures)
                ? 'SIGNED'
                : 'PENDING_SIGNATURE';

            return $signer;
        }, $signers);
    }

    private function signerHasSignature(array $signer, array $signatures): bool
    {
        foreach ($signatures as $signature) {
            if ($this->signatureMatchesSigner($signature, $signer)) {
                return true;
            }
        }

        return false;
    }

    private function signatureMatchesSigner(mixed $signature, array $signer): bool
    {
        $email = strtolower(trim((string) ($signer['email'] ?? '')));
        $cpf = $this->digits((string) ($signer['cpf'] ?? ''));
        $name = strtolower(trim((string) ($signer['name'] ?? '')));

        if (is_array($signature)) {
            $evidences = $this->signatureEvidences($signature);
            $signatureEmail = strtolower(trim((string) (
                $signature['email']
                ?? $signature['mail']
                ?? $signature['signerEmail']
                ?? $evidences['email']
                ?? $evidences['externalEmail']
                ?? ''
            )));
            $signatureCpf = $this->digits((string) (
                $signature['individualIdentificationCode']
                ?? $signature['cpf']
                ?? $signature['document']
                ?? $signature['identifier']
                ?? $evidences['signerIdentifier']
                ?? ''
            ));
            $signatureName = strtolower(trim((string) (
                $signature['name']
                ?? $signature['signerName']
                ?? $signature['user']
                ?? $evidences['name']
                ?? ''
            )));

            return ($email !== '' && $signatureEmail === $email)
                || ($cpf !== '' && $signatureCpf === $cpf)
                || ($name !== '' && $signatureName === $name);
        }

        $signatureText = strtolower((string) $signature);
        $signatureDigits = $this->digits($signatureText);

        return ($email !== '' && str_contains($signatureText, $email))
            || ($cpf !== '' && str_contains($signatureDigits, $cpf))
            || ($name !== '' && str_contains($signatureText, $name));
    }

    private function signatureEvidences(array $signature): array
    {
        $evidences = $signature['evidences'] ?? [];
        $normalized = [];

        if (!is_array($evidences)) {
            return $normalized;
        }

        foreach ($evidences as $evidence) {
            if (!is_array($evidence)) {
                continue;
            }

            $name = (string) ($evidence['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $normalized[$name] = (string) ($evidence['value'] ?? '');
        }

        return $normalized;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? Connection::getInstance();
    }
}
