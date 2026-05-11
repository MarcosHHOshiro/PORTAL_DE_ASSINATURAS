<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Auth\AuthMiddleware;
use App\Auth\AuthService;
use App\Http\ApiClient;
use App\PortalAssinaturas\DocumentService;
use App\Repositories\ApiLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentSignerRepository;
use App\Repositories\UserRepository;
use App\Support\Response;

require_once __DIR__ . '/includes/view.php';

$userRepository = new UserRepository();
$documentRepository = new DocumentRepository();
$documentSignerRepository = new DocumentSignerRepository();
$apiLogRepository = new ApiLogRepository();
$auth = new AuthService($userRepository);

// Apenas usuarios autenticados podem criar novos fluxos de assinatura.
AuthMiddleware::requireAuth($auth);

$currentUser = $auth->user();
$currentUserId = $auth->id();

if ($currentUser === null || $currentUserId === null) {
    Response::flash('error', 'Nao foi possivel identificar o usuario autenticado.');
    Response::redirect('/login.php');
}

$documentService = new DocumentService(new ApiClient($apiLogRepository), $currentUserId);
$uploadErrors = [];
//renderiza a tela com pelo menos um assinante
$formData = [
    'document_name' => '',
    'signers' => [
        [
            'name' => '',
            'email' => '',
            'cpf' => '',
        ],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Normaliza os dados recebidos antes de validar e enviar para a API.
    $formData = [
        'document_name' => trim((string) ($_POST['document_name'] ?? '')),
        'signers' => normalize_posted_signers($_POST['signers'] ?? []),
    ];

    // Validacoes locais evitam chamadas desnecessarias para a API externa.
    if (!isset($_FILES['pdf']) || (int) ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $uploadErrors[] = 'Selecione um arquivo PDF valido.';
    }

    if ($formData['document_name'] === '') {
        $uploadErrors[] = 'Informe o nome do documento.';
    }

    foreach ($formData['signers'] as $index => $signer) {
        $signerNumber = $index + 1;

        if ($signer['name'] === '') {
            $uploadErrors[] = 'Informe o nome do assinante ' . $signerNumber . '.';
        }

        if (!filter_var($signer['email'], FILTER_VALIDATE_EMAIL)) {
            $uploadErrors[] = 'Informe um e-mail valido para o assinante ' . $signerNumber . '.';
        }

        if (preg_replace('/\D+/', '', $signer['cpf']) === '') {
            $uploadErrors[] = 'Informe o CPF do assinante ' . $signerNumber . '.';
        }
    }

    if ($uploadErrors === []) {
        $primarySigner = $formData['signers'][0];

        // Cria primeiro o registro local para rastrear o fluxo mesmo se a API falhar.
        $localDocumentId = $documentRepository->create([
            'user_id' => $currentUserId,
            'document_name' => $formData['document_name'],
            'signer_name' => build_signer_summary($formData['signers']),
            'signer_email' => $primarySigner['email'],
            'signer_cpf' => $primarySigner['cpf'],
            'status' => 'CREATED',
        ]);
        $documentSignerRepository->createMany($localDocumentId, $formData['signers']);

        try {
            $uploadedFilePath = (string) $_FILES['pdf']['tmp_name'];
            $uploadedFileName = (string) ($_FILES['pdf']['name'] ?? ($formData['document_name'] . '.pdf'));

            // Envia o PDF para o Portal e salva o uploadId retornado.
            $uploadResponse = $documentService->uploadPdf($uploadedFilePath, $uploadedFileName);
            $documentRepository->updateAfterUpload($localDocumentId, $uploadResponse['uploadId']);

            // Cria o documento/lote de assinatura com os assinantes informados.
            $createBatchResponse = $documentService->createBatchWithSigners(
                $uploadResponse['uploadId'],
                $formData['document_name'],
                $formData['signers'],
                $uploadedFileName
            );

            //persistir no banco os dados do documento criado na API
            $documentRepository->updateAfterCreateBatch($localDocumentId, [
                'portal_document_id' => $createBatchResponse['portal_document_id'],
                'document_key' => $createBatchResponse['document_key'],
                'sign_url' => $createBatchResponse['sign_url'],
                'status' => 'SENT_TO_SIGNATURE',
            ]);
            $documentSignerRepository->replaceForDocument(
                $localDocumentId,
                $createBatchResponse['signers'] ?? $formData['signers']
            );

            Response::flash('success', 'Documento enviado para assinatura com sucesso.');
            Response::redirect('/index.php');
        } catch (Throwable $exception) {
            // Mantem o documento local marcado como erro para facilitar acompanhamento.
            $documentRepository->updateStatus($localDocumentId, 'ERROR');
            $uploadErrors[] = $exception->getMessage();
        }
    }
}

function normalize_posted_signers(mixed $rawSigners): array
{
    // Garante uma estrutura minima para a tela sempre ter pelo menos um assinante.
    if (!is_array($rawSigners)) {
        return [['name' => '', 'email' => '', 'cpf' => '']];
    }

    $signers = [];

    foreach ($rawSigners as $rawSigner) {
        if (!is_array($rawSigner)) {
            continue;
        }

        $signers[] = [
            'name' => trim((string) ($rawSigner['name'] ?? '')),
            'email' => trim((string) ($rawSigner['email'] ?? '')),
            'cpf' => trim((string) ($rawSigner['cpf'] ?? '')),
        ];
    }

    return $signers === [] ? [['name' => '', 'email' => '', 'cpf' => '']] : $signers;
}

function build_signer_summary(array $signers): string
{
    // Resumo usado na listagem quando existe mais de um assinante.
    $firstSignerName = (string) ($signers[0]['name'] ?? 'Assinante');
    $remainingSigners = max(count($signers) - 1, 0);

    if ($remainingSigners === 0) {
        return $firstSignerName;
    }

    return $firstSignerName . ' +' . $remainingSigners;
}

render_page_start('Novo Envio');
render_app_header($currentUser, 'novo-envio');
?>
<section class="panel document-config-page">
    <div class="section-heading">
        <div>
            <p class="section-kicker">Criacao</p>
            <h1>Criar Novo Fluxo de Assinatura</h1>
            <p>Carregue o seu ficheiro PDF e configure os destinatarios para recolha de assinaturas eletronicas.</p>
        </div>
    </div>

    <?php if ($uploadErrors !== []): ?>
        <div class="inline-errors">
            <ul>
                <?php foreach ($uploadErrors as $error): ?>
                    <li><?= Response::escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form class="workflow-form" method="post" enctype="multipart/form-data" novalidate>
        <section class="form-step" aria-labelledby="step-document-title">
            <div class="step-heading">
                <div>
                    <h2 id="step-document-title">1. Documento origem</h2>
                    <p>Arquivo PDF que sera enviado para assinatura.</p>
                </div>
            </div>

            <div class="field-full">
                <label class="upload-dropzone" for="pdf">
                    <input id="pdf" class="upload-input" name="pdf" type="file" accept="application/pdf,.pdf" required>
                    <span class="upload-icon" aria-hidden="true">PDF</span>
                    <span class="upload-title">Clique para selecionar ou arraste o PDF aqui</span>
                    <span class="upload-subtitle">Apenas arquivos PDF</span>
                    <span class="upload-file-name" data-empty-text="Nenhum arquivo selecionado">Nenhum arquivo selecionado</span>
                </label>
            </div>

            <div class="form-step-footer">
                <div class="field-full">
                    <label for="document_name">Titulo interno do documento</label>
                    <input id="document_name" name="document_name" type="text" value="<?= Response::escape($formData['document_name']) ?>" placeholder="Ex: Contrato de Prestacao de Servicos - Cliente X" required>
                </div>
            </div>
        </section>

        <section class="form-step" aria-labelledby="step-signers-title">
            <div class="step-heading">
                <div>
                    <h2 id="step-signers-title">2. Destinatarios e signatarios</h2>
                    <p>Cada assinante recebera um link proprio e codigo de acesso.</p>
                </div>
                <button class="button-primary button-inline" type="button" data-add-signer>+ Adicionar Assinante</button>
            </div>

            <div class="signers-list" data-signers-list>
                <?php foreach ($formData['signers'] as $index => $signer): ?>
                    <div class="signer-card <?= $index > 0 ? 'has-remove-action' : '' ?>" data-signer-card>
                        <strong class="signer-card-title" data-signer-title>Assinante <?= Response::escape((string) ($index + 1)) ?></strong>
                        <div class="form-grid signer-grid">
                            <div class="field">
                                <label for="signer_name_<?= Response::escape((string) $index) ?>">Nome completo</label>
                                <input id="signer_name_<?= Response::escape((string) $index) ?>" name="signers[<?= Response::escape((string) $index) ?>][name]" type="text" value="<?= Response::escape($signer['name']) ?>" placeholder="Joao da Silva" required>
                            </div>

                            <div class="field">
                                <label for="signer_email_<?= Response::escape((string) $index) ?>">E-mail corporativo</label>
                                <input id="signer_email_<?= Response::escape((string) $index) ?>" name="signers[<?= Response::escape((string) $index) ?>][email]" type="email" value="<?= Response::escape($signer['email']) ?>" placeholder="joao@empresa.com" required>
                            </div>

                            <div class="field">
                                <label for="signer_cpf_<?= Response::escape((string) $index) ?>">Documento (CPF/NIF)</label>
                                <input id="signer_cpf_<?= Response::escape((string) $index) ?>" name="signers[<?= Response::escape((string) $index) ?>][cpf]" type="text" value="<?= Response::escape($signer['cpf']) ?>" placeholder="000.000.000-00" required>
                            </div>

                            <?php if ($index > 0): ?>
                                <div class="field signer-remove-field">
                                    <button class="action-button danger-action" type="button" data-remove-signer aria-label="Remover assinante">Remover</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="button-row">
            <p class="action-note">O CPF sera utilizado como chave secundaria para compor o codigo de acesso unico do assinante.</p>
            <a class="button-link button-secondary" href="/index.php">Cancelar Operacao</a>
            <button class="button-primary" type="submit">Disparar para Assinatura</button>
        </div>
    </form>
</section>
<template data-signer-template>
    <div class="signer-card has-remove-action" data-signer-card>
        <strong class="signer-card-title" data-signer-title>Assinante __NUMBER__</strong>
        <div class="form-grid signer-grid">
            <div class="field">
                <label for="signer_name___INDEX__">Nome completo</label>
                <input id="signer_name___INDEX__" name="signers[__INDEX__][name]" type="text" placeholder="Joao da Silva" required>
            </div>

            <div class="field">
                <label for="signer_email___INDEX__">E-mail corporativo</label>
                <input id="signer_email___INDEX__" name="signers[__INDEX__][email]" type="email" placeholder="joao@empresa.com" required>
            </div>

            <div class="field">
                <label for="signer_cpf___INDEX__">Documento (CPF/NIF)</label>
                <input id="signer_cpf___INDEX__" name="signers[__INDEX__][cpf]" type="text" placeholder="000.000.000-00" required>
            </div>

            <div class="field signer-remove-field">
                <button class="action-button danger-action" type="button" data-remove-signer aria-label="Remover assinante">Remover</button>
            </div>
        </div>
    </div>
</template>
<script>
    const uploadInput = document.querySelector('.upload-input');
    const uploadDropzone = document.querySelector('.upload-dropzone');
    const uploadFileName = document.querySelector('.upload-file-name');
    const signersList = document.querySelector('[data-signers-list]');
    const signerTemplate = document.querySelector('[data-signer-template]');
    const addSignerButton = document.querySelector('[data-add-signer]');

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

    if (signersList && signerTemplate && addSignerButton) {
        const refreshSigners = () => {
            const cards = Array.from(signersList.querySelectorAll('[data-signer-card]'));

            cards.forEach((card, index) => {
                const number = index + 1;
                const title = card.querySelector('[data-signer-title]');
                const removeButton = card.querySelector('[data-remove-signer]');

                if (title) {
                    title.textContent = `Assinante ${number}`;
                }

                card.querySelectorAll('input').forEach((input) => {
                    const field = input.name.match(/\[(name|email|cpf)\]$/)?.[1];

                    if (field) {
                        input.name = `signers[${index}][${field}]`;
                        input.id = `signer_${field}_${index}`;
                    }
                });

                card.querySelectorAll('label').forEach((label) => {
                    const input = label.nextElementSibling;

                    if (input && input.id) {
                        label.setAttribute('for', input.id);
                    }
                });

                if (removeButton) {
                    removeButton.disabled = cards.length === 1;
                }
            });
        };

        addSignerButton.addEventListener('click', () => {
            const index = signersList.querySelectorAll('[data-signer-card]').length;
            const html = signerTemplate.innerHTML
                .replaceAll('__INDEX__', String(index))
                .replaceAll('__NUMBER__', String(index + 1));

            signersList.insertAdjacentHTML('beforeend', html);
            refreshSigners();
        });

        signersList.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement) || !target.matches('[data-remove-signer]')) {
                return;
            }

            target.closest('[data-signer-card]')?.remove();
            refreshSigners();
        });

        refreshSigners();
    }
</script>
<?php render_page_end(); ?>
