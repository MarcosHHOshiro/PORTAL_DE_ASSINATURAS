<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Env;
use App\Repositories\ApiLogRepository;

final class ApiClient
{
    public function __construct(private readonly ApiLogRepository $apiLogs)
    {
    }

    public function get(string $endpoint, ?int $userId = null): array
    {
        return $this->request('GET', $endpoint, null, $userId);
    }

    public function post(string $endpoint, array $payload = [], ?int $userId = null): array
    {
        return $this->request('POST', $endpoint, $payload, $userId);
    }

    public function delete(string $endpoint, ?int $userId = null): array
    {
        return $this->request('DELETE', $endpoint, null, $userId);
    }

    private function request(string $method, string $endpoint, ?array $payload, ?int $userId): array
    {
        $baseUrl = rtrim((string) Env::get('PORTAL_BASE_URL', ''), '/');
        $token = (string) Env::get('PORTAL_API_TOKEN', '');

        if ($baseUrl === '' || $token === '') {
            throw new ApiException('As variaveis PORTAL_BASE_URL e PORTAL_API_TOKEN precisam estar configuradas no .env.');
        }

        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        $curl = curl_init($url);
        $requestBody = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Ocp-Apim-Subscription-Key: ' . $token,
            'X-API-Token: ' . $token,
        ];

        if ($requestBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ]);

        if ($requestBody !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBody);
        }

        $rawResponse = curl_exec($curl);

        if ($rawResponse === false) {
            $message = curl_error($curl) ?: 'Falha desconhecida ao chamar a API.';
            curl_close($curl);
            $this->apiLogs->create($userId, $method, $endpoint, 0, $requestBody, $message);

            throw new ApiException($message, 0);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);
        $contentType = $this->extractHeaderValue($responseHeaders, 'Content-Type');
        curl_close($curl);

        $decoded = $this->decodeResponseBody($responseBody, $contentType, $statusCode);
        $loggedResponseBody = $this->stringifyForLog($decoded);

        $this->apiLogs->create($userId, $method, $endpoint, $statusCode, $requestBody, $loggedResponseBody);

        if ($statusCode >= 400) {
            $message = 'A API retornou erro HTTP ' . $statusCode . '.';

            if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
                $message = $decoded['message'];
            }

            throw new ApiException($message, $statusCode, $decoded);
        }

        return $decoded;
    }

    private function decodeResponseBody(string $responseBody, ?string $contentType, int $statusCode): array
    {
        $trimmed = trim($responseBody);

        if ($trimmed === '') {
            return ['statusCode' => $statusCode];
        }

        $isJson = $contentType !== null && str_contains(strtolower($contentType), 'application/json');

        if ($isJson || in_array($trimmed[0], ['{', '['], true)) {
            $decoded = json_decode($responseBody, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'rawBody' => base64_encode($responseBody),
            'contentType' => $contentType ?: 'application/octet-stream',
            'statusCode' => $statusCode,
        ];
    }

    private function extractHeaderValue(string $headers, string $headerName): ?string
    {
        foreach (preg_split("/\r\n|\n|\r/", $headers) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);

            if (strcasecmp(trim($name), $headerName) === 0) {
                return trim($value);
            }
        }

        return null;
    }

    private function stringifyForLog(array $decoded): string
    {
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }
}
