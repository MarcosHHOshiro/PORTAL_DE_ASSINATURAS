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
<div class="login-shell">
    <section class="login-card" aria-label="Acesso ao Portal de Assinaturas">
        <aside class="login-brand-panel">
            <div class="login-brand-top">
                <div class="login-brand-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M12 3.25l7 2.8v5.45c0 4.36-2.8 7.94-7 9.25-4.2-1.31-7-4.89-7-9.25V6.05l7-2.8z" />
                        <path d="M9.25 12.05l1.8 1.8 3.9-4" />
                    </svg>
                </div>
                <div>
                    <strong>Portal de Assinaturas</strong>
                    <span>Estudo de caso</span>
                </div>
            </div>

            <div class="login-brand-copy">
                <h1>Gente que assina cresce.</h1>
                <p>Apresentacao da solucao tecnica para o comite da regional Centro-Sul MS | BA.</p>
            </div>

            <div class="login-security-note">
                <span aria-hidden="true"></span>
                Ambiente sandbox seguro
            </div>
        </aside>

        <div class="login-form-panel">
            <div class="login-form-heading">
                <h2>Acesse sua conta</h2>
                <p>Insira suas credenciais para continuar.</p>
            </div>

            <?php if ($error !== null): ?>
                <div class="inline-errors">
                    <ul>
                        <li><?= Response::escape($error) ?></li>
                    </ul>
                </div>
            <?php endif; ?>

            <form class="login-form" method="post" novalidate>
                <div class="login-field">
                    <label for="email">E-mail</label>
                    <div class="login-input-wrap">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M4.75 6.75h14.5v10.5H4.75z" />
                            <path d="M5.25 7.25l6.75 5.2 6.75-5.2" />
                        </svg>
                        <input id="email" name="email" type="email" value="<?= Response::escape($email) ?>" placeholder="seu.email@exemplo.com" required>
                    </div>
                </div>

                <div class="login-field">
                    <label for="password">Senha</label>
                    <div class="login-input-wrap">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M7 10.25V8.5a5 5 0 0 1 10 0v1.75" />
                            <path d="M5.75 10.25h12.5v8H5.75z" />
                        </svg>
                        <input id="password" name="password" type="password" placeholder="Sua senha" required>
                        <button class="login-password-toggle" type="button" aria-label="Mostrar senha" data-password-toggle>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M3.75 12s2.8-5 8.25-5 8.25 5 8.25 5-2.8 5-8.25 5-8.25-5-8.25-5z" />
                                <circle cx="12" cy="12" r="2.25" />
                            </svg>
                        </button>
                    </div>
                </div>

                <a class="login-forgot-link" href="/register.php">Solicitar acesso</a>

                <button class="login-submit" type="submit">
                    Acessar
                    <span aria-hidden="true">-&gt;</span>
                </button>
            </form>

            <div class="login-create-account">
                <span>Ainda nao tem acesso?</span>
                <a href="/register.php">Abra sua conta</a>
            </div>
        </div>
    </section>
</div>
<script>
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.login-input-wrap')?.querySelector('input');

            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            button.setAttribute('aria-label', isPassword ? 'Ocultar senha' : 'Mostrar senha');
        });
    });
</script>
<?php render_page_end(); ?>
