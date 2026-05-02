<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(string $message, private readonly ?int $statusCode = null, private readonly mixed $responseBody = null)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): mixed
    {
        return $this->responseBody;
    }
}
