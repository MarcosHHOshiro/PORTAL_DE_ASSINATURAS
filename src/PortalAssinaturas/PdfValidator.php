<?php

declare(strict_types=1);

namespace App\PortalAssinaturas;

use RuntimeException;

final class PdfValidator
{
    public function assertValid(string $filePath, ?string $fileName = null): void
    {
        if ($filePath === '' || !is_file($filePath)) {
            throw new RuntimeException('O arquivo PDF enviado nao foi encontrado.');
        }

        if ((int) filesize($filePath) <= 0) {
            throw new RuntimeException('O arquivo PDF nao pode estar vazio.');
        }

        $extension = strtolower(pathinfo($fileName ?: $filePath, PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            throw new RuntimeException('O arquivo enviado deve possuir extensao .pdf.');
        }

        $signature = file_get_contents($filePath, false, null, 0, 4);
        $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : null;

        if ($signature !== '%PDF' || ($mime !== false && $mime !== null && !in_array($mime, ['application/pdf', 'application/octet-stream'], true))) {
            throw new RuntimeException('O arquivo enviado nao e um PDF valido.');
        }
    }
}
