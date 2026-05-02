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
            <link rel="stylesheet" href="/assets/app.css">
        </head>
        <body>
        <div class="app-shell">
            <div class="ambient ambient-left"></div>
            <div class="ambient ambient-right"></div>
            <main class="page page-<?= Response::escape(strtolower(str_replace(' ', '-', $title))) ?>">
                <?php if ($flash !== null): ?>
                    <div class="alert alert-<?= Response::escape($flash['type'] ?? 'info') ?>">
                        <?= Response::escape($flash['message'] ?? '') ?>
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
        </body>
        </html>
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
