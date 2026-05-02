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
$uploadErrors = [];
$formData = [
    'document_name' => '',
    'signer_name' => '',
    'signer_email' => '',
    'signer_cpf' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $documentId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : null;

    if ($action === 'upload_document') {
        $formData = [
            'document_name' => trim((string) ($_POST['document_name'] ?? '')),
            'signer_name' => trim((string) ($_POST['signer_name'] ?? '')),
            'signer_email' => trim((string) ($_POST['signer_email'] ?? '')),
            'signer_cpf' => trim((string) ($_POST['signer_cpf'] ?? '')),
        ];

        if (!isset($_FILES['pdf']) || (int) ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadErrors[] = 'Selecione um arquivo PDF valido.';
        }

        if ($formData['document_name'] === '') {
            $uploadErrors[] = 'Informe o nome do documento.';
        }

        if ($formData['signer_name'] === '') {
            $uploadErrors[] = 'Informe o nome do assinante.';
        }

        if (!filter_var($formData['signer_email'], FILTER_VALIDATE_EMAIL)) {
            $uploadErrors[] = 'Informe um e-mail valido para o assinante.';
        }

        if (preg_replace('/\D+/', '', $formData['signer_cpf']) === '') {
            $uploadErrors[] = 'Informe o CPF do assinante.';
        }

        if ($uploadErrors === []) {
            $localDocumentId = $documentRepository->create([
                'user_id' => $currentUserId,
                'document_name' => $formData['document_name'],
                'signer_name' => $formData['signer_name'],
                'signer_email' => $formData['signer_email'],
                'signer_cpf' => $formData['signer_cpf'],
                'status' => 'CREATED',
            ]);

            try {
                $uploadedFilePath = (string) $_FILES['pdf']['tmp_name'];
                $uploadedFileName = (string) ($_FILES['pdf']['name'] ?? ($formData['document_name'] . '.pdf'));

                $uploadResponse = $documentService->uploadPdf($uploadedFilePath, $uploadedFileName);
                $documentRepository->updateAfterUpload($localDocumentId, $uploadResponse['uploadId']);

                $createBatchResponse = $documentService->createBatch(
                    $uploadResponse['uploadId'],
                    $formData['document_name'],
                    $formData['signer_name'],
                    $formData['signer_email'],
                    $formData['signer_cpf']
                );

                $documentRepository->updateAfterCreateBatch($localDocumentId, [
                    'portal_document_id' => $createBatchResponse['portal_document_id'],
                    'document_key' => $createBatchResponse['document_key'],
                    'sign_url' => $createBatchResponse['sign_url'],
                    'status' => 'SENT_TO_SIGNATURE',
                ]);

                Response::flash('success', 'Documento enviado para assinatura com sucesso.');
                Response::redirect('/index.php');
            } catch (Throwable $exception) {
                $documentRepository->updateStatus($localDocumentId, 'ERROR');
                $uploadErrors[] = $exception->getMessage();
            }
        }
    }

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

render_page_start('Painel');
?>
<section class="hero-card">
    <div class="topbar">
        <div>
            <div class="hero-eyebrow">Fluxo completo do case</div>
            <h1>Portal de Assinaturas</h1>
            <p class="lead">Envie um PDF, gere o lote no sandbox, acompanhe o link de assinatura, valide o resultado e baixe o pacote final sem sair do painel.</p>
        </div>

        <div>
            <div class="topbar-meta">Usuario logado: <strong><?= Response::escape($currentUser['name'] ?? '') ?></strong></div>
            <div class="button-row">
                <a class="button-link button-secondary" href="/logout.php">Sair</a>
            </div>
        </div>
    </div>
</section>

<section class="grid-main">
    <div class="panel">
        <h2>Novo envio</h2>
        <p class="lead">O PDF e enviado para `/document/upload` e, na sequencia, o lote e criado via `/document/createBatch`.</p>

        <?php if ($uploadErrors !== []): ?>
            <div class="inline-errors">
                <ul>
                    <?php foreach ($uploadErrors as $error): ?>
                        <li><?= Response::escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="action" value="upload_document">

            <div class="form-grid">
                <div class="field-full">
                    <label for="pdf">Arquivo PDF</label>
                    <input id="pdf" name="pdf" type="file" accept="application/pdf,.pdf" required>
                </div>

                <div class="field-full">
                    <label for="document_name">Nome do documento</label>
                    <input id="document_name" name="document_name" type="text" value="<?= Response::escape($formData['document_name']) ?>" required>
                </div>

                <div class="field-full">
                    <label for="signer_name">Nome do assinante</label>
                    <input id="signer_name" name="signer_name" type="text" value="<?= Response::escape($formData['signer_name']) ?>" required>
                </div>

                <div class="field-full">
                    <label for="signer_email">E-mail do assinante</label>
                    <input id="signer_email" name="signer_email" type="email" value="<?= Response::escape($formData['signer_email']) ?>" required>
                </div>

                <div class="field-full">
                    <label for="signer_cpf">CPF do assinante</label>
                    <input id="signer_cpf" name="signer_cpf" type="text" value="<?= Response::escape($formData['signer_cpf']) ?>" required>
                </div>
            </div>

            <div class="button-row">
                <button class="button-primary" type="submit">Enviar para assinatura</button>
            </div>

            <p class="helper">Neste MVP o CPF tambem e usado para compor o codigo de acesso do assinante na assinatura eletronica.</p>
        </form>
    </div>

    <div class="panel">
        <h2>Documentos enviados</h2>
        <p class="lead">A listagem mostra apenas os registros do usuario autenticado e permite validar, baixar o pacote e excluir cada documento.</p>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID local</th>
                    <th>ID Portal</th>
                    <th>Documento</th>
                    <th>Assinante</th>
                    <th>Status</th>
                    <th>Assinatura</th>
                    <th>Acoes</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($documents === []): ?>
                    <tr>
                        <td colspan="7" class="muted">Nenhum documento enviado ainda.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td>#<?= Response::escape($document['id']) ?></td>
                        <td><?= Response::escape($document['portal_document_id'] ?? '-') ?></td>
                        <td>
                            <strong><?= Response::escape($document['document_name']) ?></strong><br>
                            <span class="muted">Criado em <?= Response::escape($document['created_at']) ?></span>
                        </td>
                        <td>
                            <?= Response::escape($document['signer_name']) ?><br>
                            <span class="muted"><?= Response::escape($document['signer_email']) ?></span>
                        </td>
                        <td>
                            <span class="badge <?= Response::escape(status_badge_class((string) $document['status'])) ?>">
                                <?= Response::escape($document['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($document['sign_url'])): ?>
                                <a href="<?= Response::escape((string) $document['sign_url']) ?>" target="_blank" rel="noreferrer">Abrir link</a>
                            <?php else: ?>
                                <span class="muted">Aguardando geracao</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <form method="post">
                                    <input type="hidden" name="action" value="validate_document">
                                    <input type="hidden" name="document_id" value="<?= Response::escape($document['id']) ?>">
                                    <button class="button-inline button-secondary" type="submit">Validar</button>
                                </form>

                                <form method="post">
                                    <input type="hidden" name="action" value="download_package">
                                    <input type="hidden" name="document_id" value="<?= Response::escape($document['id']) ?>">
                                    <button class="button-inline button-ghost" type="submit">Baixar pacote</button>
                                </form>

                                <form method="post" onsubmit="return confirm('Deseja excluir este documento?');">
                                    <input type="hidden" name="action" value="delete_document">
                                    <input type="hidden" name="document_id" value="<?= Response::escape($document['id']) ?>">
                                    <button class="button-inline button-danger" type="submit">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php render_page_end(); ?>
