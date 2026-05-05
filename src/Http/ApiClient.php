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
        $basePath = trim((string) Env::get('PORTAL_API_BASE_PATH', ''), '/');
        $token = (string) Env::get('PORTAL_API_TOKEN', '');
        $code = trim((string) Env::get('PORTAL_API_CODE', ''));

        if ($baseUrl === '' || $token === '') {
            throw new ApiException('As variaveis PORTAL_BASE_URL e PORTAL_API_TOKEN precisam estar configuradas no .env.');
        }

        $url = $this->buildUrl($baseUrl, $basePath, $endpoint);
        $curl = curl_init($url);
        $requestBody = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

        $headers = [
            'Accept: application/json',
            'token: ' . $token,
        ];

        if ($code !== '') {
            $headers[] = 'code: ' . $code;
        }

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
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
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

        if ($statusCode >= 300) {
            $message = $this->buildErrorMessage($statusCode, $decoded, $baseUrl, $basePath, $endpoint);

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

    private function buildUrl(string $baseUrl, string $basePath, string $endpoint): string
    {
        $path = ltrim($endpoint, '/');

        if ($basePath !== '') {
            return $baseUrl . '/' . $basePath . '/' . $path;
        }

        return $baseUrl . '/' . $path;
    }

    private function buildErrorMessage(int $statusCode, array $decoded, string $baseUrl, string $basePath, string $endpoint): string
    {
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        if ($statusCode === 404 && $this->looksLikeHtmlResponse($decoded)) {
            $configuredUrl = rtrim($baseUrl . '/' . trim($basePath, '/'), '/');

            return 'A API retornou HTTP 404 ao chamar `'
                . $this->buildUrl($baseUrl, $basePath, $endpoint)
                . '`. Verifique se `PORTAL_BASE_URL` aponta para o gateway da API do ambiente correto e nao para o portal de documentacao. '
                . 'Se a sua integracao exigir um prefixo, configure `PORTAL_API_BASE_PATH` no `.env`. URL base atual: `'
                . $configuredUrl
                . '`.';
        }

        if ($statusCode >= 300 && $statusCode < 400 && $this->looksLikeHtmlResponse($decoded)) {
            $configuredUrl = rtrim($baseUrl . '/' . trim($basePath, '/'), '/');

            return 'A API retornou HTTP ' . $statusCode
                . ' ao chamar `'
                . $this->buildUrl($baseUrl, $basePath, $endpoint)
                . '`, redirecionando para uma pagina HTML do portal em vez de responder JSON. '
                . 'Isso indica que `PORTAL_BASE_URL` e/ou `PORTAL_API_BASE_PATH` nao apontam para o gateway correto da API. '
                . 'URL base atual: `'
                . $configuredUrl
                . '`.';
        }

        return 'A API retornou erro HTTP ' . $statusCode . '.';
    }

    private function looksLikeHtmlResponse(array $decoded): bool
    {
        $contentType = strtolower((string) ($decoded['contentType'] ?? ''));

        if (str_contains($contentType, 'text/html')) {
            return true;
        }

        $rawBody = $decoded['rawBody'] ?? null;

        if (!is_string($rawBody) || $rawBody === '') {
            return false;
        }

        $decodedBody = base64_decode($rawBody, true);

        if ($decodedBody === false) {
            return false;
        }

        $trimmed = ltrim($decodedBody);

        return str_starts_with($trimmed, '<!DOCTYPE html') || str_starts_with($trimmed, '<html');
    }
}
