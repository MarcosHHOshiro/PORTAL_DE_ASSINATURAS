<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Auth\AuthMiddleware;
use App\Auth\AuthService;
use App\Repositories\UserRepository;
use App\Support\Response;

require_once __DIR__ . '/includes/view.php';

$auth = new AuthService(new UserRepository());
AuthMiddleware::requireGuest($auth);

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $auth->register($name, $email, $password);
        Response::flash('success', 'Cadastro realizado com sucesso. Agora faca login.');
        Response::redirect('/login.php');
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

render_page_start('Cadastro');
?>
<div class="auth-wrap">
    <section class="auth-card">
        <div class="hero-eyebrow">Portal de Assinaturas</div>
        <h1>Criar conta</h1>
        <p class="lead">Cadastre um usuario interno para enviar contratos em PDF, acompanhar assinaturas e baixar o pacote final assinado.</p>

        <?php if ($errors !== []): ?>
            <div class="inline-errors">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= Response::escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <div class="form-grid">
                <div class="field-full">
                    <label for="name">Nome</label>
                    <input id="name" name="name" type="text" value="<?= Response::escape($name) ?>" required>
                </div>

                <div class="field-full">
                    <label for="email">E-mail</label>
                    <input id="email" name="email" type="email" value="<?= Response::escape($email) ?>" required>
                </div>

                <div class="field-full">
                    <label for="password">Senha</label>
                    <input id="password" name="password" type="password" required>
                </div>
            </div>

            <div class="button-row">
                <button class="button-primary" type="submit">Cadastrar usuario</button>
                <a class="button-link button-secondary" href="/login.php">Ja tenho conta</a>
            </div>
        </form>
    </section>
</div>
<?php render_page_end(); ?>
