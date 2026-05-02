<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Auth\AuthService;
use App\Repositories\UserRepository;
use App\Support\Response;

$auth = new AuthService(new UserRepository());
$auth->logout();

Response::flash('success', 'Sessao encerrada com sucesso.');
Response::redirect('/login.php');
