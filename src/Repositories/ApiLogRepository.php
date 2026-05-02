<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ApiLogRepository
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function create(?int $userId, string $method, string $endpoint, ?int $statusCode, ?string $requestBody, ?string $responseBody): void
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO api_logs (user_id, method, endpoint, status_code, request_body, response_body, created_at)
             VALUES (:user_id, :method, :endpoint, :status_code, :request_body, :response_body, :created_at)'
        );

        $statement->execute([
            ':user_id' => $userId,
            ':method' => strtoupper($method),
            ':endpoint' => $endpoint,
            ':status_code' => $statusCode,
            ':request_body' => $requestBody,
            ':response_body' => $responseBody,
            ':created_at' => date('c'),
        ]);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? Connection::getInstance();
    }
}
