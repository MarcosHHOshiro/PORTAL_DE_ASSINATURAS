<?php

declare(strict_types=1);

use App\Support\Response;

if (!function_exists('render_page_start')) {
    function render_page_start(string $title): void
    {
        $flash = Response::pullFlash();
        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= Response::escape($title) ?></title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="/assets/app.css">
        </head>
        <body>
        <div class="app-shell">
            <div class="ambient ambient-left"></div>
            <div class="ambient ambient-right"></div>
            <main class="page page-<?= Response::escape(strtolower(str_replace(' ', '-', $title))) ?>">
                <?php if ($flash !== null): ?>
                    <?php $flashType = (string) ($flash['type'] ?? 'info'); ?>
                    <div
                        class="alert alert-<?= Response::escape($flashType) ?>"
                        role="alert"
                        aria-live="polite"
                        data-flash-alert
                    >
                        <span class="alert-icon" aria-hidden="true"><?= $flashType === 'success' ? 'OK' : '!' ?></span>
                        <span class="alert-message"><?= Response::escape($flash['message'] ?? '') ?></span>
                        <button class="alert-close" type="button" aria-label="Fechar alerta" data-alert-close>&times;</button>
                    </div>
                <?php endif; ?>
        <?php
    }
}

if (!function_exists('render_page_end')) {
    function render_page_end(): void
    {
        ?>
            </main>
        </div>
        <script>
            document.querySelectorAll('[data-alert-close]').forEach((button) => {
                button.addEventListener('click', () => {
                    button.closest('[data-flash-alert]')?.remove();
                });
            });
        </script>
        </body>
        </html>
        <?php
    }
}

if (!function_exists('render_app_header')) {
    function render_app_header(array $currentUser, string $activePage): void
    {
        ?>
        <header class="app-header">
            <div class="app-header-inner">
                <div class="brand-block">
                    <div class="brand-mark">PA</div>
                    <div>
                        <div class="brand-title">Portal de Assinaturas</div>
                        <div class="brand-subtitle">Sandbox V2 conectado</div>
                    </div>
                </div>

                <nav class="header-nav" aria-label="Navegacao principal">
                    <a class="<?= $activePage === 'novo-envio' ? 'is-active' : '' ?>" href="/novo-envio.php">Novo envio</a>
                    <a class="<?= $activePage === 'documentos' ? 'is-active' : '' ?>" href="/index.php">Documentos</a>
                </nav>

                <div class="user-menu">
                    <span><?= Response::escape($currentUser['name'] ?? '') ?></span>
                    <a class="button-link button-secondary" href="/logout.php">Sair</a>
                </div>
            </div>
        </header>
        <?php
    }
}

if (!function_exists('status_badge_class')) {
    function status_badge_class(string $status): string
    {
        return match ($status) {
            'SIGNED' => 'badge-success',
            'INVALID', 'ERROR', 'DELETED' => 'badge-danger',
            'PENDING_SIGNATURE' => 'badge-warning',
            default => 'badge-neutral',
        };
    }
}

if (!function_exists('status_label')) {
    function status_label(string $status): string
    {
        return match ($status) {
            'CREATED' => 'Criado',
            'UPLOADED' => 'Enviado',
            'SENT_TO_SIGNATURE' => 'Em assinatura',
            'PENDING_SIGNATURE' => 'Pendente',
            'SIGNED' => 'Assinado',
            'INVALID' => 'Invalido',
            'ERROR' => 'Erro',
            'DELETED' => 'Excluido',
            default => str_replace('_', ' ', strtolower($status)),
        };
    }
}
