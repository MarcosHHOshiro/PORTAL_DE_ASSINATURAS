<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class UserRepository
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function create(string $name, string $email, string $passwordHash): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, created_at) VALUES (:name, :email, :password_hash, :created_at)'
        );

        $statement->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':created_at' => date('c'),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute([':email' => $email]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? Connection::getInstance();
    }
}
