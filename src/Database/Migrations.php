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
}
