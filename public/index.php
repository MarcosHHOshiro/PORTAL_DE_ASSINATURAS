<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Auth\AuthMiddleware;
use App\Auth\AuthService;
use App\Http\ApiClient;
use App\PortalAssinaturas\DocumentService;
use App\Repositories\ApiLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\UserRepository;
use App\Support\Response;

require_once __DIR__ . '/includes/view.php';

$userRepository = new UserRepository();
$documentRepository = new DocumentRepository();
$apiLogRepository = new ApiLogRepository();
$auth = new AuthService($userRepository);
AuthMiddleware::requireAuth($auth);

$currentUser = $auth->user();
$currentUserId = $auth->id();

if ($currentUser === null || $currentUserId === null) {
    Response::flash('error', 'Nao foi possivel identificar o usuario autenticado.');
    Response::redirect('/login.php');
}

$documentService = new DocumentService(new ApiClient($apiLogRepository), $currentUserId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $documentId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : null;

    if ($action === 'validate_document' && $documentId !== null) {
        $document = $documentRepository->findById($documentId, $currentUserId);

        if ($document === null) {
            Response::flash('error', 'Documento nao encontrado.');
            Response::redirect('/index.php');
        }

        try {
            $validation = $documentService->validateSignatures((string) ($document['document_key'] ?? ''));
            $documentRepository->updateValidation($documentId, (bool) $validation['isValid'], $validation);
            Response::flash('success', 'Validacao concluida com sucesso.');
        } catch (Throwable $exception) {
            $documentRepository->updateStatus($documentId, 'ERROR');
            Response::flash('error', $exception->getMessage());
        }

        Response::redirect('/index.php');
    }

    if ($action === 'download_package' && $documentId !== null) {
        $document = $documentRepository->findById($documentId, $currentUserId);

        if ($document === null) {
            Response::flash('error', 'Documento nao encontrado.');
            Response::redirect('/index.php');
        }

        try {
            $savedPath = $documentService->downloadPackage((string) ($document['document_key'] ?? ''), FILES_PATH . DIRECTORY_SEPARATOR . 'signed');
            Response::downloadFile($savedPath, basename($savedPath));
        } catch (Throwable $exception) {
            $documentRepository->updateStatus($documentId, 'ERROR');
            Response::flash('error', $exception->getMessage());
            Response::redirect('/index.php');
        }
    }

    if ($action === 'delete_document' && $documentId !== null) {
        $document = $documentRepository->findById($documentId, $currentUserId);

        if ($document === null) {
            Response::flash('error', 'Documento nao encontrado.');
            Response::redirect('/index.php');
        }

        if ((string) ($document['status'] ?? '') === 'SIGNED') {
            Response::flash('error', 'Documentos assinados nao podem ser excluidos.');
            Response::redirect('/index.php');
        }

        try {
            $portalDocumentId = $document['portal_document_id'] ?? null;

            if ($portalDocumentId !== null && (string) $portalDocumentId !== '') {
                $documentService->deleteDocument((string) $portalDocumentId);
            }

            $documentRepository->delete($documentId, $currentUserId);
            Response::flash('success', 'Documento excluido com sucesso.');
        } catch (Throwable $exception) {
            $documentRepository->updateStatus($documentId, 'ERROR');
            Response::flash('error', $exception->getMessage());
        }

        Response::redirect('/index.php');
    }
}

$documents = $documentRepository->findAllByUser($currentUserId);

render_page_start('Documentos');
render_app_header($currentUser, 'documentos');
?>
<section class="panel documents-panel">
    <div class="section-heading">
        <div>
            <p class="section-kicker">Acompanhamento</p>
            <h1>Documentos enviados</h1>
            <p class="lead">Acompanhe o status, abra o link do assinante e use o codigo de acesso exibido para concluir os testes no sandbox.</p>
        </div>

        <a class="button-link button-primary" href="/novo-envio.php">Novo envio</a>
    </div>

    <div class="document-list">
        <?php if ($documents === []): ?>
            <div class="empty-state">
                <strong>Nenhum documento enviado ainda.</strong>
                <span>Comece criando um envio para assinatura eletronica remota.</span>
                <a class="button-link button-primary" href="/novo-envio.php">Enviar primeiro documento</a>
            </div>
        <?php endif; ?>

        <?php foreach ($documents as $document): ?>
            <?php $hasDocumentKey = trim((string) ($document['document_key'] ?? '')) !== ''; ?>
            <?php $signerCpfDigits = preg_replace('/\D+/', '', (string) ($document['signer_cpf'] ?? '')) ?: ''; ?>
            <?php $accessCode = $signerCpfDigits !== '' ? substr(str_pad($signerCpfDigits, 6, '0', STR_PAD_LEFT), -6) : ''; ?>
            <article class="document-card">
                <div class="document-main">
                    <strong class="document-title"><?= Response::escape($document['document_name']) ?></strong>
                    <span class="document-date">Data: <?= Response::escape($document['created_at']) ?></span>
                    <div class="meta-row">
                        <span class="meta-chip">Portal: <?= Response::escape($document['portal_document_id'] ?? '-') ?></span>
                        <span class="meta-chip">Local: #<?= Response::escape($document['id']) ?></span>
                    </div>
                    <?php if ($hasDocumentKey): ?>
                        <span class="document-key">Chave: <?= Response::escape((string) $document['document_key']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="signer-block">
                    <strong><?= Response::escape($document['signer_name']) ?></strong>
                    <span><?= Response::escape($document['signer_email']) ?></span>
                    <?php if ($accessCode !== ''): ?>
                        <span class="access-code">Acesso: <?= Response::escape($accessCode) ?></span>
                    <?php endif; ?>
                </div>

                <?php $documentStatus = (string) $document['status']; ?>
                <div class="status-block">
                    <span
                        class="badge <?= Response::escape(status_badge_class($documentStatus)) ?>"
                        title="Status tecnico: <?= Response::escape($documentStatus) ?>"
                    >
                        <?= Response::escape(status_label($documentStatus)) ?>
                    </span>
                </div>

                <div class="card-actions">
                    <form method="post">
                        <input type="hidden" name="action" value="validate_document">
                        <input type="hidden" name="document_id" value="<?= Response::escape($document['id']) ?>">
                        <button
                            class="action-button"
                            type="submit"
                            title="<?= $hasDocumentKey ? 'Chamar a API para validar as assinaturas deste documento.' : 'A chave do documento e obrigatoria para validar assinaturas.' ?>"
                            aria-label="Validar assinaturas"
                            <?= $hasDocumentKey ? '' : 'disabled' ?>
                        >Validar</button>
                    </form>

                    <form method="post">
                        <input type="hidden" name="action" value="download_package">
                        <input type="hidden" name="document_id" value="<?= Response::escape($document['id']) ?>">
                        <button
                            class="action-button"
                            type="submit"
                            title="<?= $hasDocumentKey ? 'Baixar pacote final com documento e comprovantes.' : 'A chave do documento e obrigatoria para baixar o pacote.' ?>"
                            aria-label="Baixar pacote"
                            <?= $hasDocumentKey ? '' : 'disabled' ?>
                        >Baixar</button>
                    </form>

                    <?php if (!empty($document['sign_url'])): ?>
                        <a
                            class="action-button open-button"
                            href="<?= Response::escape((string) $document['sign_url']) ?>"
                            target="_blank"
                            rel="noreferrer"
                            title="Abrir tela de assinatura do Portal em uma nova aba."
                        >Abrir</a>
                    <?php endif; ?>

                    <?php if ($documentStatus !== 'SIGNED'): ?>
                        <form method="post" onsubmit="return confirm('Deseja excluir este documento?');">
                            <input type="hidden" name="action" value="delete_document">
                            <input type="hidden" name="document_id" value="<?= Response::escape($document['id']) ?>">
                            <button
                                class="action-button danger-action"
                                type="submit"
                                title="Excluir este documento do Portal e marcar como excluido localmente."
                            >Excluir</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php render_page_end(); ?>
