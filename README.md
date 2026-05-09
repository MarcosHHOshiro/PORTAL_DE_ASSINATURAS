# Portal de Assinaturas em PHP Puro

Aplicacao web desenvolvida em PHP puro orientado a objetos para demonstrar a integracao com o sandbox do Portal de Assinaturas. O projeto cobre o fluxo principal do case: cadastro, login, upload de PDF, criacao do documento para assinatura, exibicao do link de assinatura, validacao do documento assinado, download do pacote final e exclusao do registro.

## Objetivo do case

Demonstrar dominio de:

- PHP orientado a objetos sem framework
- Integracao com API externa usando cURL nativo
- Autenticacao simples com sessao
- Persistencia local com SQLite
- Upload e validacao de arquivos PDF
- Organizacao em camadas
- Registro tecnico de chamadas de API

## Tecnologias utilizadas

- PHP 8+
- SQLite com PDO
- cURL nativo
- Composer apenas para autoload PSR-4
- HTML e CSS sem framework

## Estrutura do projeto

```text
portal-assinaturas-case/
|-- public/
|   |-- assets/
|   |   `-- app.css
|   |-- includes/
|   |   `-- view.php
|   |-- index.php
|   |-- login.php
|   |-- logout.php
|   `-- register.php
|-- src/
|   |-- Auth/
|   |-- Config/
|   |-- Database/
|   |-- Http/
|   |-- PortalAssinaturas/
|   |-- Repositories/
|   `-- Support/
|-- files/
|   |-- contrato.pdf
|   `-- signed/
|-- storage/
|-- bootstrap.php
|-- composer.json
|-- .env.example
`-- README.md
```

## Configuracao do ambiente

1. Copie o arquivo de exemplo:

```powershell
Copy-Item .env.example .env
```

2. Ajuste as variaveis no `.env`:

```env
PORTAL_BASE_URL=https://api-sbx.portaldeassinaturas.com.br
PORTAL_API_TOKEN=seu_token_aqui
PORTAL_API_CODE=
PORTAL_API_BASE_PATH=api/v2
PORTAL_SSL_VERIFY=true
PORTAL_SSL_CAINFO=
DATABASE_PATH=storage/database.sqlite
```

`PORTAL_BASE_URL` deve apontar para a base do gateway da API do ambiente escolhido. Nao use a URL do portal de documentacao como valor padrao apenas por ela exibir o Swagger.

Na API V2, o Swagger informa autenticacao por cabecalhos `token` e `code`. O `code` pode ficar vazio se o seu ambiente nao exigir esse segundo cabecalho.

No sandbox V2 deste case, o gateway exposto no Swagger usa `https://api-sbx.portaldeassinaturas.com.br/api/v2`.

Se no Windows a chamada HTTPS falhar com `SSL peer certificate or SSH remote key was not OK`, informe um bundle CA confiavel em `PORTAL_SSL_CAINFO`, por exemplo `C:/caminho/cacert.pem`. Como ultimo recurso para desenvolvimento local, use `PORTAL_SSL_VERIFY=false` temporariamente.

Embora o Swagger marque `typeId` como obrigatorio, o comportamento real validado no sandbox V2 deste case foi o oposto: o fluxo de `document/create` funcionou quando o campo foi omitido.

## Como obter o token sandbox

1. Acesse o portal de desenvolvedores do Portal de Assinaturas.
2. Solicite um token para o ambiente sandbox.
3. Use esse token no arquivo `.env`.

Observacao: a documentacao publica informa que sandbox e producao usam tokens diferentes.

## Instalacao

1. Gere o autoload do Composer:

```bash
composer dump-autoload
```

2. Inicie o servidor embutido do PHP:

```bash
php -S localhost:8000 -t public
```

3. Acesse no navegador:

```text
http://localhost:8000/register.php
```

## Fluxo de uso

1. Cadastre um usuario em `/register.php`.
2. Faca login em `/login.php`.
3. Envie um arquivo PDF pela tela principal.
4. O sistema chama `/document/upload`.
5. Em seguida chama `/document/createBatch`.
6. O link de assinatura retornado pela API passa a aparecer na listagem.
7. Abra o `signUrl` em nova aba e conclua a assinatura no sandbox.
8. Volte ao painel e clique em `Validar`.
9. Quando o documento estiver assinado corretamente, clique em `Baixar pacote`.

## Funcionalidades implementadas

- Cadastro de usuario com `password_hash`
- Login com `password_verify`
- Logout com encerramento da sessao autenticada
- Protecao da pagina principal
- Upload de PDF com validacoes basicas
- Envio do arquivo para a API do Portal de Assinaturas
- Criacao do lote/documento para assinatura
- Exibicao do link de assinatura
- Validacao do documento assinado
- Download do pacote assinado em `files/signed/`
- Exclusao logica local com tentativa de exclusao na API
- Persistencia em SQLite
- Logs tecnicos das chamadas HTTP em `api_logs`

## Banco de dados

O banco SQLite e criado automaticamente em:

```text
storage/database.sqlite
```

As tabelas criadas na primeira execucao sao:

- `users`
- `documents`
- `api_logs`

## Decisoes tecnicas

- `bootstrap.php` centraliza sessao, autoload, carregamento do `.env` e execucao das migracoes.
- A camada `ApiClient` concentra a comunicacao HTTP e o registro dos logs.
- A camada `DocumentService` encapsula as regras da integracao com o portal.
- O upload usa a conversao exigida no case:

```php
$bytes = array_values(unpack('C*', file_get_contents($filePath)));
```

- O token e enviado em mais de um cabecalho para ampliar compatibilidade com o gateway da API:
  `Authorization: Bearer`, `Ocp-Apim-Subscription-Key` e `X-API-Token`.
- Cada documento e sempre filtrado pelo `user_id` do usuario autenticado.

## Logs da API

Toda chamada para a API externa e registrada na tabela `api_logs` com:

- usuario
- metodo HTTP
- endpoint
- status HTTP
- corpo da requisicao
- corpo da resposta
- data e hora

## Limitacoes atuais

- O payload de `createBatch` foi montado a partir do PRD e da documentacao publica; pode exigir pequenos ajustes conforme o token sandbox utilizado.
- O sistema nao possui webhook/callback para atualizacao automatica de status.
- Nao ha recuperacao de senha, paginacao nem filtros por status.
- O caminho do pacote baixado nao e persistido no banco.

## Melhorias futuras

- Webhook para atualizacao automatica de status
- Suporte a multiplos assinantes
- Filtros por status e paginacao
- Persistencia do caminho do arquivo assinado
- Testes automatizados
- Melhor refinamento visual da interface
