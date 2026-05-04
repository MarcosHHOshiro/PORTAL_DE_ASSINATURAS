<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = App\Database\Connection::getInstance();
$statement = $pdo->query("SELECT id, endpoint, status_code, request_body, response_body, created_at FROM api_logs WHERE endpoint = '/document/upload' ORDER BY id DESC LIMIT 1");
$row = $statement ? $statement->fetch() : false;

if ($row === false) {
    echo "NO_LOG\n";
    exit(0);
}

echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
