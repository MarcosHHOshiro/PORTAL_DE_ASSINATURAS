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
                $formData['signer_cpf'],
                $uploadedFileName
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

render_page_start('Novo Envio');
render_app_header($currentUser, 'novo-envio');
?>
<section class="single-page-layout">
    <div class="panel upload-panel upload-panel-standalone">
        <p class="section-kicker">Criacao</p>
        <h1>Novo envio</h1>
        <p class="lead">Envie um PDF para assinatura eletronica remota e gere o link que sera usado pelo assinante.</p>

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
            <div class="form-grid">
                <div class="field-full">
                    <label for="pdf">Arquivo PDF</label>
                    <label class="upload-dropzone" for="pdf">
                        <input id="pdf" class="upload-input" name="pdf" type="file" accept="application/pdf,.pdf" required>
                        <span class="upload-icon" aria-hidden="true">PDF</span>
                        <span class="upload-title">Arraste o PDF aqui</span>
                        <span class="upload-subtitle">ou clique para selecionar</span>
                        <span class="upload-file-name" data-empty-text="Nenhum arquivo selecionado">Nenhum arquivo selecionado</span>
                    </label>
                    <span class="field-hint">Use um arquivo com extensao .pdf para que a API aceite a criacao do documento.</span>
                </div>

                <div class="field-full">
                    <label for="document_name">Nome do documento</label>
                    <input id="document_name" name="document_name" type="text" value="<?= Response::escape($formData['document_name']) ?>" required>
                </div>

                <div class="field">
                    <label for="signer_name">Nome do assinante</label>
                    <input id="signer_name" name="signer_name" type="text" value="<?= Response::escape($formData['signer_name']) ?>" required>
                </div>

                <div class="field">
                    <label for="signer_email">E-mail do assinante</label>
                    <input id="signer_email" name="signer_email" type="email" value="<?= Response::escape($formData['signer_email']) ?>" required>
                </div>

                <div class="field-full">
                    <label for="signer_cpf">CPF do assinante</label>
                    <input id="signer_cpf" name="signer_cpf" type="text" value="<?= Response::escape($formData['signer_cpf']) ?>" required>
                    <span class="field-hint">O codigo de acesso sera formado pelos ultimos 6 digitos do CPF.</span>
                </div>
            </div>

            <div class="button-row">
                <button class="button-primary" type="submit">Enviar para assinatura</button>
                <a class="button-link button-secondary" href="/index.php">Ver documentos</a>
            </div>

            <p class="helper">Neste MVP o CPF tambem e usado para compor o codigo de acesso do assinante na assinatura eletronica.</p>
        </form>
    </div>
</section>
<script>
    const uploadInput = document.querySelector('.upload-input');
    const uploadDropzone = document.querySelector('.upload-dropzone');
    const uploadFileName = document.querySelector('.upload-file-name');

    if (uploadInput && uploadDropzone && uploadFileName) {
        const syncFileName = () => {
            const file = uploadInput.files && uploadInput.files[0];
            uploadFileName.textContent = file ? file.name : uploadFileName.dataset.emptyText;
            uploadDropzone.classList.toggle('has-file', Boolean(file));
        };

        uploadInput.addEventListener('change', syncFileName);

        ['dragenter', 'dragover'].forEach((eventName) => {
            uploadDropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                uploadDropzone.classList.add('is-dragging');
            });
        });

        ['dragleave', 'drop'].forEach((eventName) => {
            uploadDropzone.addEventListener(eventName, () => {
                uploadDropzone.classList.remove('is-dragging');
            });
        });

        uploadDropzone.addEventListener('drop', (event) => {
            event.preventDefault();

            if (event.dataTransfer.files.length > 0) {
                uploadInput.files = event.dataTransfer.files;
                syncFileName();
            }
        });
    }
</script>
<?php render_page_end(); ?>
