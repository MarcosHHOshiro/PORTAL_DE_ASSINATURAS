<?php

declare(strict_types=1);

namespace App\Support;

final class Response
{
    public static function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    public static function pullFlash(): ?array
    {
        if (!isset($_SESSION['flash'])) {
            return null;
        }

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return is_array($flash) ? $flash : null;
    }

    public static function downloadFile(string $absolutePath, ?string $downloadName = null, ?string $mimeType = null): void
    {
        if (!is_file($absolutePath)) {
            self::flash('error', 'Arquivo nao encontrado para download.');
            self::redirect('/index.php');
        }

        $downloadName ??= basename($absolutePath);
        $mimeType ??= mime_content_type($absolutePath) ?: 'application/octet-stream';

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');

        readfile($absolutePath);
        exit;
    }
}
