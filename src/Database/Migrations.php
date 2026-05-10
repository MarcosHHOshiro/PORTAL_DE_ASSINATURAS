<?php

declare(strict_types=1);

namespace App\Database;

final class Migrations
{
    public static function run(): void
    {
        $pdo = Connection::getInstance();

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                portal_document_id INTEGER,
                upload_id TEXT,
                document_key TEXT,
                document_name TEXT NOT NULL,
                signer_name TEXT NOT NULL,
                signer_email TEXT NOT NULL,
                signer_cpf TEXT,
                sign_url TEXT,
                status TEXT DEFAULT 'CREATED',
                is_valid INTEGER DEFAULT 0,
                validation_response TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )"
        );

        self::addColumnIfMissing('documents', 'signers_json', 'TEXT');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS document_signers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                cpf TEXT,
                access_code TEXT,
                sign_url TEXT,
                status TEXT DEFAULT 'PENDING_SIGNATURE',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_document_signers_document_id ON document_signers(document_id)');
        self::backfillDocumentSigners();

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS api_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                method TEXT NOT NULL,
                endpoint TEXT NOT NULL,
                status_code INTEGER,
                request_body TEXT,
                response_body TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )"
        );
    }

    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $pdo = Connection::getInstance();
        $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();

        foreach ($columns as $existingColumn) {
            if (($existingColumn['name'] ?? '') === $column) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private static function backfillDocumentSigners(): void
    {
        $pdo = Connection::getInstance();
        $documents = $pdo->query(
            'SELECT d.*
               FROM documents d
              WHERE NOT EXISTS (
                    SELECT 1
                      FROM document_signers ds
                     WHERE ds.document_id = d.id
              )'
        )->fetchAll();

        foreach ($documents as $document) {
            $signers = self::extractSignersFromDocument($document);

            foreach ($signers as $signer) {
                self::insertDocumentSigner((int) $document['id'], $signer);
            }
        }
    }

    private static function extractSignersFromDocument(array $document): array
    {
        $signersJson = $document['signers_json'] ?? null;

        if (is_string($signersJson) && trim($signersJson) !== '') {
            $decoded = json_decode($signersJson, true);

            if (is_array($decoded) && $decoded !== []) {
                return array_values(array_filter($decoded, 'is_array'));
            }
        }

        return [
            [
                'name' => (string) ($document['signer_name'] ?? 'Assinante'),
                'email' => (string) ($document['signer_email'] ?? ''),
                'cpf' => (string) ($document['signer_cpf'] ?? ''),
                'sign_url' => $document['sign_url'] ?? null,
                'status' => $document['status'] === 'SIGNED' ? 'SIGNED' : 'PENDING_SIGNATURE',
            ],
        ];
    }

    private static function insertDocumentSigner(int $documentId, array $signer): void
    {
        $pdo = Connection::getInstance();
        $now = date('c');
        $cpf = preg_replace('/\D+/', '', (string) ($signer['cpf'] ?? '')) ?: '';
        $accessCode = (string) ($signer['access_code'] ?? ($cpf !== '' ? substr(str_pad($cpf, 6, '0', STR_PAD_LEFT), -6) : ''));
        $statement = $pdo->prepare(
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
}
