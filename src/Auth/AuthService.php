<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\UserRepository;
use InvalidArgumentException;

final class AuthService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function register(string $name, string $email, string $password): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $password = trim($password);

        if ($name === '') {
            throw new InvalidArgumentException('Informe o nome.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail valido.');
        }

        if (strlen($password) < 6) {
            throw new InvalidArgumentException('A senha deve ter pelo menos 6 caracteres.');
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new InvalidArgumentException('Ja existe um usuario cadastrado com este e-mail.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        return $this->users->create($name, $email, $passwordHash);
    }

    public function login(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        $password = trim($password);
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        //cria a sessão
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        return true;
    }

    public function logout(): void
    {
        //remove o usuário
        unset($_SESSION['user_id']);
        //regenera o id da sessão para evitar fixação de sessão
        session_regenerate_id(true);
    }

    public function user(): ?array
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        return $this->users->findById($id);
    }

    public function check(): bool
    {
        return $this->id() !== null;
    }

    public function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }
}
