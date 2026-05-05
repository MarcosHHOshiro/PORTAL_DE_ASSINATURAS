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
            $documentRepository->updateValidation($documentId, (bool) $validation['isValid'], $validation, document_signers($document));
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

    <div class="documents-toolbar">
        <label class="search-field" for="document_search">
            <span>Pesquisar</span>
            <input id="document_search" type="search" placeholder="Buscar por titulo, chave, assinante ou e-mail" data-document-search>
        </label>
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
            <?php $documentStatus = (string) $document['status']; ?>
            <?php $signerCpfDigits = preg_replace('/\D+/', '', (string) ($document['signer_cpf'] ?? '')) ?: ''; ?>
            <?php $accessCode = $signerCpfDigits !== '' ? substr(str_pad($signerCpfDigits, 6, '0', STR_PAD_LEFT), -6) : ''; ?>
            <?php $documentSigners = document_signers($document); ?>
            <?php
                $searchText = strtolower(trim(implode(' ', array_filter([
                    (string) ($document['document_name'] ?? ''),
                    (string) ($document['document_key'] ?? ''),
                    (string) ($document['portal_document_id'] ?? ''),
                    (string) ($document['signer_name'] ?? ''),
                    (string) ($document['signer_email'] ?? ''),
                    ...array_map(
                        static fn (array $signer): string => implode(' ', [
                            (string) ($signer['name'] ?? ''),
                            (string) ($signer['email'] ?? ''),
                            (string) ($signer['cpf'] ?? ''),
                        ]),
                        $documentSigners
                    ),
                ]))));
            ?>
            <article class="document-card" data-document-card data-search="<?= Response::escape($searchText) ?>">
                <div class="document-card-header">
                    <div class="document-icon" aria-hidden="true">DOC</div>
                    <div class="document-title-block">
                        <strong class="document-title"><?= Response::escape($document['document_name']) ?></strong>
                        <div class="document-inline-meta">
                            <span><?= Response::escape($document['created_at']) ?></span>
                            <span>ID: <?= Response::escape($document['portal_document_id'] ?? '-') ?></span>
                            <span
                                class="badge <?= Response::escape(status_badge_class($documentStatus)) ?>"
                                title="Status tecnico: <?= Response::escape($documentStatus) ?>"
                            >
                                <?= Response::escape(status_label($documentStatus)) ?>
                            </span>
                        </div>
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
                </div>

                <div class="signer-block">
                    <div class="signer-block-heading">
                        <strong>Fluxo de assinatura</strong>
                    </div>
                    <div class="signer-list">
                        <?php foreach ($documentSigners as $signer): ?>
                            <?php $signerCpf = preg_replace('/\D+/', '', (string) ($signer['cpf'] ?? '')) ?: ''; ?>
                            <?php $signerAccessCode = $signerCpf !== '' ? substr(str_pad($signerCpf, 6, '0', STR_PAD_LEFT), -6) : ''; ?>
                            <?php $signerStatus = (string) ($signer['status'] ?? 'PENDING_SIGNATURE'); ?>
                            <div class="signer-row">
                                <span class="signer-status-dot <?= Response::escape(status_badge_class($signerStatus)) ?>" aria-hidden="true"><?= $signerStatus === 'SIGNED' ? 'OK' : '' ?></span>
                                <div class="signer-person">
                                    <span class="signer-name-line">
                                        <?= Response::escape((string) ($signer['name'] ?? 'Assinante')) ?>
                                    </span>
                                    <small><?= Response::escape((string) ($signer['email'] ?? '')) ?></small>
                                </div>

                                <div class="signer-quick-actions">
                                    <?php if ($signerAccessCode !== ''): ?>
                                        <span class="access-code"><small>Codigo acesso</small><?= Response::escape($signerAccessCode) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($signer['sign_url'])): ?>
                                        <a
                                            class="signer-link"
                                            href="<?= Response::escape((string) $signer['sign_url']) ?>"
                                            target="_blank"
                                            rel="noreferrer"
                                        >Assinar</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($documentSigners === [] && $accessCode !== ''): ?>
                        <span><?= Response::escape($document['signer_email']) ?></span>
                        <span class="access-code">Acesso <?= Response::escape($accessCode) ?></span>
                    <?php endif; ?>
                </div>

                <div class="document-hash-row">
                    <span>Hash de seguranca:</span>
                    <strong><?= Response::escape($hasDocumentKey ? substr((string) $document['document_key'], 0, 16) : ('LOCAL-' . $document['id'])) ?></strong>
                </div>
            </article>
        <?php endforeach; ?>

        <div class="empty-state empty-search-state" data-empty-search hidden>
            <strong>Nenhum documento encontrado.</strong>
            <span>Tente buscar por outro titulo, chave, assinante ou e-mail.</span>
        </div>
    </div>
</section>
<script>
    const documentSearchInput = document.querySelector('[data-document-search]');
    const documentCards = Array.from(document.querySelectorAll('[data-document-card]'));
    const emptySearchState = document.querySelector('[data-empty-search]');

    if (documentSearchInput && documentCards.length > 0) {
        documentSearchInput.addEventListener('input', () => {
            const query = documentSearchInput.value.trim().toLowerCase();
            let visibleCount = 0;

            documentCards.forEach((card) => {
                const matches = query === '' || (card.dataset.search || '').includes(query);
                card.hidden = !matches;

                if (matches) {
                    visibleCount += 1;
                }
            });

            if (emptySearchState) {
                emptySearchState.hidden = visibleCount > 0;
            }
        });
    }
</script>
<?php
function document_signers(array $document): array
{
    $signersJson = $document['signers_json'] ?? null;

    if (is_string($signersJson) && trim($signersJson) !== '') {
        $decoded = json_decode($signersJson, true);

        if (is_array($decoded) && $decoded !== []) {
            return array_values(array_filter($decoded, 'is_array'));
        }
    }

    return [
        [
            'name' => (string) ($document['signer_name'] ?? 'Assinante'),
            'email' => (string) ($document['signer_email'] ?? ''),
            'cpf' => (string) ($document['signer_cpf'] ?? ''),
        ],
    ];
}

render_page_end();
?>
