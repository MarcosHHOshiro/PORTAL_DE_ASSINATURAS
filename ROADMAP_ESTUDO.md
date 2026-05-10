# Roadmap de Estudo - Portal de Assinaturas

Use este arquivo como checklist enquanto estuda o sistema. A ideia e entender primeiro o fluxo geral e depois descer para os detalhes tecnicos.

## 1. Visao geral do projeto

- [ ] Ler o `README.md`
- [ ] Entender o objetivo do sistema
- [ ] Identificar o fluxo principal: cadastro, login, upload, envio, validacao, download e exclusao
- [ ] Ler o `composer.json`
- [ ] Ver quais dependencias o projeto usa
- [ ] Ler o `.env.example`
- [ ] Entender quais configuracoes sao necessarias para rodar o sistema
- [ ] Ler o `bootstrap.php`
- [ ] Entender como o autoload, a sessao e as migrations sao iniciadas

## 2. Autenticacao local

- [ ] Ler `src/Auth/AuthService.php`
- [ ] Entender como o cadastro valida nome, e-mail e senha
- [ ] Entender onde a senha e criptografada com `password_hash`
- [ ] Entender como o login usa `password_verify`
- [ ] Entender onde o sistema grava `$_SESSION['user_id']`
- [ ] Ler `src/Auth/AuthMiddleware.php`
- [ ] Entender como as paginas internas exigem usuario logado
- [ ] Ler `public/login.php`
- [ ] Ler `public/register.php`
- [ ] Saber explicar por que o sistema usa sessao PHP em vez de JWT

## 3. Banco de dados

- [ ] Ler `src/Database/Connection.php`
- [ ] Entender como a conexao PDO e criada
- [ ] Ler `src/Database/Migrations.php`
- [ ] Identificar as tabelas criadas pelo sistema
- [ ] Entender a tabela de usuarios
- [ ] Entender a tabela de documentos
- [ ] Entender a tabela de assinantes dos documentos
- [ ] Entender a tabela de logs da API
- [ ] Ler `src/Repositories/UserRepository.php`
- [ ] Ler `src/Repositories/DocumentRepository.php`
- [ ] Ler `src/Repositories/DocumentSignerRepository.php`
- [ ] Ler `src/Repositories/ApiLogRepository.php`
- [ ] Entender o papel do padrao Repository no projeto
- [ ] Entender o vinculo `documents.id` -> `document_signers.document_id`
- [ ] Identificar onde sao usados prepared statements

## 4. Novo envio de documento

- [ ] Ler `public/novo-envio.php`
- [ ] Entender como o formulario recebe PDF e assinantes
- [ ] Entender como os dados do formulario sao normalizados
- [ ] Entender a validacao local do nome do documento
- [ ] Entender a validacao local de nome, e-mail e CPF dos assinantes
- [ ] Entender quando o registro local do documento e criado
- [ ] Entender quando o status fica `CREATED`
- [ ] Entender quando o status fica `UPLOADED`
- [ ] Entender quando o status fica `SENT_TO_SIGNATURE`
- [ ] Entender quando o status fica `ERROR`

## 5. Servico de documentos

- [ ] Ler `src/PortalAssinaturas/DocumentService.php`
- [ ] Entender que `DocumentService` agora e um orquestrador do fluxo
- [ ] Identificar quais chamadas ainda ficam no `DocumentService`: upload, criacao, validacao, download e exclusao
- [ ] Entender por que a refatoracao reduziu a sobrecarga do `DocumentService`
- [ ] Ler `src/PortalAssinaturas/PdfValidator.php`
- [ ] Entender que `PdfValidator` concentra a validacao fisica do arquivo PDF
- [ ] Ler `src/PortalAssinaturas/SignerNormalizer.php`
- [ ] Entender que `SignerNormalizer` normaliza nome, e-mail e CPF
- [ ] Entender como `SignerNormalizer` gera o codigo de acesso
- [ ] Ler `src/PortalAssinaturas/DocumentPayloadFactory.php`
- [ ] Entender que `DocumentPayloadFactory` monta o corpo enviado para a API
- [ ] Ler `src/PortalAssinaturas/CreateBatchResponseNormalizer.php`
- [ ] Entender que `CreateBatchResponseNormalizer` extrai dados de respostas diferentes da API
- [ ] Entender como os links dos assinantes sao associados por e-mail ou CPF
- [ ] Ler `src/PortalAssinaturas/SignedPackageWriter.php`
- [ ] Entender que `SignedPackageWriter` extrai bytes e salva o pacote final em disco
- [ ] Entender o metodo `uploadPdf`
- [ ] Entender o metodo `PdfValidator::assertValid`
- [ ] Ver como o sistema valida extensao `.pdf`
- [ ] Ver como o sistema valida assinatura `%PDF`
- [ ] Ver como o sistema valida MIME type
- [ ] Entender o metodo `createBatchWithSigners`
- [ ] Entender como os assinantes sao normalizados
- [ ] Entender como o CPF vira codigo de acesso
- [ ] Entender como o payload para a API e montado
- [ ] Entender o fallback entre `createBatch` e `create`
- [ ] Entender como o sistema extrai `document_key`, `portal_document_id` e `sign_url`
- [ ] Saber explicar o que e `uploadId`
- [ ] Saber explicar o que e `document_key`
- [ ] Saber explicar por que `document_key` e importante para validar e baixar o pacote

### Perguntas para fixar o topico 5

- [ ] Qual era o problema do `DocumentService` antes da refatoracao?
- [ ] Qual responsabilidade ficou com `PdfValidator`?
- [ ] Qual responsabilidade ficou com `SignerNormalizer`?
- [ ] Qual responsabilidade ficou com `DocumentPayloadFactory`?
- [ ] Qual responsabilidade ficou com `CreateBatchResponseNormalizer`?
- [ ] Qual responsabilidade ficou com `SignedPackageWriter`?
- [ ] O que o `DocumentService` ainda faz?
- [ ] Por que separar responsabilidades melhora manutencao?

## 6. Integracao com a API do Portal

- [ ] Ler `src/Http/ApiClient.php`
- [ ] Entender como a URL da API e montada
- [ ] Entender o uso de `PORTAL_BASE_URL`
- [ ] Entender o uso de `PORTAL_API_BASE_PATH`
- [ ] Entender o uso de `PORTAL_API_TOKEN`
- [ ] Entender o uso opcional de `PORTAL_API_CODE`
- [ ] Ver quais headers sao enviados para a API
- [ ] Confirmar que a API usa token em header, nao JWT local
- [ ] Entender como o payload JSON e enviado
- [ ] Entender como a resposta JSON e decodificada
- [ ] Entender como respostas binarias sao tratadas
- [ ] Entender como erros HTTP viram `ApiException`
- [ ] Ler `src/Http/ApiException.php`
- [ ] Ler `src/PortalAssinaturas/PortalEndpoints.php`
- [ ] Decorar os endpoints principais: upload, create, validate, package e delete

## 7. Validacao de assinaturas

- [ ] Ler o fluxo do botao `Validar` em `public/index.php`
- [ ] Entender como o documento e buscado pelo usuario logado
- [ ] Entender por que a `document_key` e obrigatoria
- [ ] Entender o metodo `DocumentService::validateSignatures`
- [ ] Entender a chamada para `/document/ValidateSignatures?key=...`
- [ ] Entender que a validacao criptografica nao e feita localmente
- [ ] Entender que o sistema confia na resposta da API do Portal
- [ ] Entender o campo `isValid`
- [ ] Entender o campo `electronicSignatures`
- [ ] Ler `DocumentRepository::updateValidation`
- [ ] Entender quando o documento vira `SIGNED`
- [ ] Entender quando o documento vira `INVALID`
- [ ] Entender quando o documento continua `PENDING_SIGNATURE`
- [ ] Entender como cada assinante recebe status `SIGNED` ou `PENDING_SIGNATURE`

## 8. Listagem e acoes dos documentos

- [ ] Ler `public/index.php`
- [ ] Entender a listagem de documentos do usuario
- [ ] Entender os filtros por busca e status
- [ ] Entender como os badges de status sao exibidos
- [ ] Entender como o link de assinatura e mostrado
- [ ] Entender como o codigo de acesso e exibido
- [ ] Entender o fluxo do botao `Baixar`
- [ ] Entender o metodo `downloadPackage`
- [ ] Entender onde o pacote assinado e salvo
- [ ] Entender o fluxo do botao `Excluir`
- [ ] Entender por que documentos `SIGNED` nao podem ser excluidos pela interface

## 9. Camada de visualizacao

- [ ] Ler `public/includes/view.php`
- [ ] Entender `render_page_start`
- [ ] Entender `render_app_header`
- [ ] Entender `render_page_end`
- [ ] Entender como mensagens flash sao exibidas
- [ ] Entender `Response::escape`
- [ ] Ler `src/Support/Response.php`
- [ ] Entender redirects
- [ ] Entender downloads
- [ ] Ler `public/assets/app.css`
- [ ] Identificar os principais blocos visuais da interface

## 10. Seguranca

- [ ] Confirmar que senhas nao sao salvas em texto puro
- [ ] Confirmar que o login usa sessao PHP
- [ ] Confirmar que paginas internas exigem autenticacao
- [ ] Confirmar que SQL usa prepared statements
- [ ] Confirmar que saidas HTML usam escape
- [ ] Confirmar que o token da API fica no `.env`
- [ ] Confirmar que o upload valida arquivo PDF
- [ ] Avaliar falta de CSRF token nos formularios
- [ ] Avaliar falta de limite maximo de tamanho do PDF
- [ ] Avaliar se logs da API podem guardar dados sensiveis
- [ ] Avaliar configuracoes de cookie: `HttpOnly`, `Secure` e `SameSite`

## 11. Melhorias possiveis

- [ ] Adicionar CSRF token nos formularios
- [ ] Validar CPF com algoritmo oficial
- [ ] Limitar tamanho maximo do upload
- [ ] Evoluir consultas e relatorios por assinante
- [ ] Criar testes automatizados
- [ ] Separar controllers das views
- [ ] Adicionar roteador simples
- [ ] Melhorar organizacao dos status do documento
- [ ] Melhorar logs para evitar dados sensiveis
- [ ] Criar pagina de detalhes do documento
- [ ] Adicionar historico visual de eventos do documento

## 12. Explicacao oral do sistema

- [ ] Conseguir explicar a arquitetura geral em ate 1 minuto
- [ ] Conseguir explicar autenticacao local com sessao
- [ ] Conseguir explicar por que nao usa JWT
- [ ] Conseguir explicar como o PDF e validado
- [ ] Conseguir explicar como o sistema chama a API externa
- [ ] Conseguir explicar como a validacao de assinatura funciona
- [ ] Conseguir explicar os status do documento
- [ ] Conseguir explicar onde os dados ficam persistidos
- [ ] Conseguir citar pelo menos 3 pontos de seguranca existentes
- [ ] Conseguir citar pelo menos 3 melhorias futuras

## Resumo para revisar antes de apresentar

- [ ] O sistema e PHP puro orientado a objetos
- [ ] Usa sessao PHP para login local
- [ ] Usa SQLite com PDO
- [ ] Usa repositories para persistencia
- [ ] Relaciona documentos e assinantes pela tabela `document_signers`
- [ ] Usa token no header para acessar a API do Portal
- [ ] Nao usa JWT
- [ ] Valida PDF antes de enviar
- [ ] Cria documento no Portal e guarda `document_key`
- [ ] Valida assinatura chamando a API do Portal
- [ ] Atualiza status local com base na resposta da API
- [ ] Permite baixar pacote final assinado
- [ ] Permite excluir documentos que ainda nao estao assinados
