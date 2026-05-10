<?php

declare(strict_types=1);

namespace App\PortalAssinaturas;

use App\Http\ApiException;

final class SignedPackageWriter
{
    public function save(array $response, string $documentKey, string $outputPath): string
    {
        $bytes = $this->extractBytes($response);
        $fileName = $this->sanitizeFileName($response['name'] ?? ('documento-assinado-' . $documentKey . '.zip'));
        $targetPath = $this->resolveOutputPath($outputPath, $fileName);
        $directory = dirname($targetPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($targetPath, $bytes);

        return $targetPath;
    }

    private function extractBytes(array $response): string
    {
        if (isset($response['bytes']) && is_array($response['bytes'])) {
            return pack('C*', ...$response['bytes']);
        }

        if (isset($response['bytes']) && is_string($response['bytes'])) {
            $decoded = base64_decode($response['bytes'], true);

            if ($decoded !== false) {
                return $decoded;
            }

            return $response['bytes'];
        }

        if (isset($response['rawBody']) && is_string($response['rawBody'])) {
            $decoded = base64_decode($response['rawBody'], true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        throw new ApiException('A resposta do pacote assinado nao trouxe bytes utilizaveis.', null, $response);
    }

    private function resolveOutputPath(string $outputPath, string $fileName): string
    {
        $normalized = rtrim($outputPath, DIRECTORY_SEPARATOR);

        if ($normalized === '' || is_dir($normalized) || pathinfo($normalized, PATHINFO_EXTENSION) === '') {
            return $normalized . DIRECTORY_SEPARATOR . $fileName;
        }

        return $normalized;
    }

    private function sanitizeFileName(string $fileName): string
    {
        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '-', $fileName) ?: 'documento-assinado.zip';
        $fileName = trim($fileName, '-');

        return $fileName === '' ? 'documento-assinado.zip' : $fileName;
    }
}
