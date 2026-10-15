# Instruções para agentes de código neste repositório

Leia `README.md`, `DOMAIN.md` e `docs/adr` antes de alterar código em `app/`.

## Como trabalhar

- **TDD, não retroativo**: escreva o teste, execute-o e veja-o falhar, só então escreva o código de produção. Todo teste novo precisa ter falhado antes por um motivo correto.
- **Camadas**: `Domain` não importa nada do Laravel; `Application` define as portas que consome; `Infrastructure` as implementa; controllers só traduzem HTTP (ADR 0001).
- **Erros do cliente** são `ApiException` com um `ErrorCode`; todo código novo entra em `ErrorCode`, no OpenAPI e no catálogo, e nunca reaproveita um código para outro significado.
- **Dados sensíveis** (documento, passaporte, e-mail, senhas, chaves) nunca vão para log, mensagem de erro, `params` ou resposta. Ao classificar um campo novo, trate-o como PII se houver dúvida.
- **SQL**: valores sempre como parâmetros; nomes de coluna só vindos de listas fixas (`Entities`). Coluna nova de tabela pesquisável entra em `Entities` (um teste de integração cobra isso).
- **Segurança de borda** (cabeçalhos, limite de corpo, rate limit, validação de `aud`/`iss`, checagens de produção em `Settings`) não se desliga para facilitar teste: diferenças de teste usam `instance()` no contêiner.
- Nada de API deprecated nas versões resolvidas em `composer.lock`; todo `@phpstan-ignore` ou `// phpcs:ignore` precisa de motivo escrito.

## Antes de considerar a mudança pronta

- `make verify` sem violações (audit, Pint, PHPStan nível max, testes unitários e de API).
- Os testes de integração (`make test-integration`) rodam no CI com PostgreSQL e RabbitMQ; rode-os localmente quando mexer em repositórios, SQL ou mensageria.
- Migration nova: `down()` completo e revisado à mão; o CI faz o ciclo `up`, `down`, `up`.
- Rota nova: no OpenAPI (`api/openapi.yaml`), dentro do grupo autenticado em `routes/api.php`, com ao menos um teste de 401 e, se houver papel, de 403.
- Atualize `CHANGELOG.md`.
