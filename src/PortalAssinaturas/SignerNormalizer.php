<?php

declare(strict_types=1);

namespace App\PortalAssinaturas;

use RuntimeException;

//assinantes
final class SignerNormalizer
{
    public function normalize(array $signers): array
    {
        $normalized = [];

        foreach ($signers as $signer) {
            if (!is_array($signer)) {
                continue;
            }

            $name = trim((string) ($signer['name'] ?? ''));
            $email = strtolower(trim((string) ($signer['email'] ?? '')));
            $cpf = $this->sanitizeCpf(isset($signer['cpf']) ? (string) $signer['cpf'] : null);

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $cpf === null) {
                throw new RuntimeException('Todos os assinantes precisam ter nome, e-mail valido e CPF.');
            }

            $normalized[] = [
                'name' => $name,
                'email' => $email,
                'cpf' => $cpf,
                'access_code' => $this->buildAccessCode($cpf),
            ];
        }

        if ($normalized === []) {
            throw new RuntimeException('Adicione pelo menos um assinante ao documento.');
        }

        return $normalized;
    }

    public function sanitizeCpf(?string $cpf): ?string
    {
        if ($cpf === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $cpf) ?: '';

        return $digits === '' ? null : $digits;
    }

    public function buildAccessCode(string $cpf): string
    {
        return substr(str_pad($cpf, 6, '0', STR_PAD_LEFT), -6);
    }
}
