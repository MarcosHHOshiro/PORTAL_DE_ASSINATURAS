<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class DocumentRepository
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function create(array $data): int
    {
        $now = date('c');

        $statement = $this->pdo()->prepare(
            'INSERT INTO documents (
                user_id, portal_document_id, upload_id, document_key, document_name,
                signer_name, signer_email, signer_cpf, sign_url, status, is_valid,
                validation_response, created_at, updated_at
            ) VALUES (
                :user_id, :portal_document_id, :upload_id, :document_key, :document_name,
                :signer_name, :signer_email, :signer_cpf, :sign_url, :status, :is_valid,
                :validation_response, :created_at, :updated_at
            )'
        );

        $statement->execute([
            ':user_id' => $data['user_id'],
            ':portal_document_id' => $data['portal_document_id'] ?? null,
            ':upload_id' => $data['upload_id'] ?? null,
            ':document_key' => $data['document_key'] ?? null,
            ':document_name' => $data['document_name'],
            ':signer_name' => $data['signer_name'],
            ':signer_email' => $data['signer_email'],
            ':signer_cpf' => $data['signer_cpf'] ?? null,
            ':sign_url' => $data['sign_url'] ?? null,
            ':status' => $data['status'] ?? 'CREATED',
            ':is_valid' => $data['is_valid'] ?? 0,
            ':validation_response' => $data['validation_response'] ?? null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function updateAfterUpload(int $id, string $uploadId): void
    {
        $statement = $this->pdo()->prepare(
            'UPDATE documents SET upload_id = :upload_id, status = :status, updated_at = :updated_at WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':upload_id' => $uploadId,
            ':status' => 'UPLOADED',
            ':updated_at' => date('c'),
        ]);
    }

    public function updateAfterCreateBatch(int $id, array $data): void
    {
        $statement = $this->pdo()->prepare(
            'UPDATE documents
                SET portal_document_id = :portal_document_id,
                    document_key = :document_key,
                    sign_url = :sign_url,
                    status = :status,
                    updated_at = :updated_at
              WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':portal_document_id' => $data['portal_document_id'] ?? null,
            ':document_key' => $data['document_key'] ?? null,
            ':sign_url' => $data['sign_url'] ?? null,
            ':status' => $data['status'] ?? 'SENT_TO_SIGNATURE',
            ':updated_at' => date('c'),
        ]);
    }

    public function updateValidation(int $id, bool $isValid, array $validationResponse, array $signers = []): void
    {
        $electronicSignatures = $validationResponse['electronicSignatures'] ?? [];
        $hasElectronicSignature = is_array($electronicSignatures) && count($electronicSignatures) > 0;
        $updatedSigners = array_values(array_filter($signers, 'is_array'));
        $hasPendingSigner = $updatedSigners !== [] && in_array('PENDING_SIGNATURE', array_column($updatedSigners, 'status'), true);
        $allSignersSigned = $updatedSigners !== [] && !$hasPendingSigner;
        $status = 'PENDING_SIGNATURE';

        if ($isValid && $hasElectronicSignature && $allSignersSigned) {
            $status = 'SIGNED';
        } elseif (!$isValid && $hasElectronicSignature && $allSignersSigned) {
            $status = 'INVALID';
        } elseif (!$isValid && $hasElectronicSignature && $updatedSigners === []) {
            $status = 'INVALID';
        } elseif ($hasElectronicSignature === false || $hasPendingSigner) {
            $status = 'PENDING_SIGNATURE';
        }

        $statement = $this->pdo()->prepare(
            'UPDATE documents
                SET is_valid = :is_valid,
                    validation_response = :validation_response,
                    status = :status,
                    updated_at = :updated_at
              WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':is_valid' => $isValid ? 1 : 0,
            ':validation_response' => json_encode($validationResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ':status' => $status,
            ':updated_at' => date('c'),
        ]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $statement = $this->pdo()->prepare(
            'UPDATE documents SET status = :status, updated_at = :updated_at WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':status' => $status,
            ':updated_at' => date('c'),
        ]);
    }

    public function findById(int $id, int $userId): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM documents WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute([
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        $document = $statement->fetch();

        return $document ?: null;
    }

    public function findAllByUser(int $userId): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM documents WHERE user_id = :user_id ORDER BY id DESC');
        $statement->execute([':user_id' => $userId]);

        return $statement->fetchAll() ?: [];
    }

    public function delete(int $id, int $userId): void
    {
        $statement = $this->pdo()->prepare(
            'UPDATE documents SET status = :status, updated_at = :updated_at WHERE id = :id AND user_id = :user_id'
        );

        $statement->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':status' => 'DELETED',
            ':updated_at' => date('c'),
        ]);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? Connection::getInstance();
    }
}
