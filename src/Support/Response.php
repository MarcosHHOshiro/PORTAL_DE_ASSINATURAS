<?php

declare(strict_types=1);

namespace App\Support;

final class Response
{
    // Redireciona o navegador para outra rota e encerra a execucao atual.
    public static function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }

    // ajuda a evitar XSS
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    // Guarda uma mensagem temporaria na sessao para ser exibida na proxima tela.
    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    // Recupera a mensagem flash da sessao e a remove para evitar repeticao.
    public static function pullFlash(): ?array
    {
        if (!isset($_SESSION['flash'])) {
            return null;
        }

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return is_array($flash) ? $flash : null;
    }

    // Envia um arquivo salvo em disco para download pelo navegador do usuario.
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
