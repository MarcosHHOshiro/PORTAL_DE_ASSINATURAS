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

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($auth->login($email, $password)) {
        Response::redirect('/index.php');
    }

    $error = 'E-mail ou senha invalidos.';
}

render_page_start('Login');
?>
<div class="auth-wrap">
    <section class="auth-card">
        <div class="hero-eyebrow">Ambiente interno</div>
        <h1>Entrar</h1>
        <p class="lead">Acesse a area protegida para enviar PDFs ao sandbox, acompanhar o status e baixar o pacote assinado.</p>

        <?php if ($error !== null): ?>
            <div class="inline-errors">
                <ul>
                    <li><?= Response::escape($error) ?></li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <div class="form-grid">
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
                <button class="button-primary" type="submit">Entrar</button>
                <a class="button-link button-secondary" href="/register.php">Criar conta</a>
            </div>
        </form>
    </section>
</div>
<?php render_page_end(); ?>
