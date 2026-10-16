# Arquitetura técnica: php-order-api

Explicação técnica de como a API de pedidos em PHP/Laravel é montada. O contrato vem do `fastapi-order-api`. Decisões formais em `docs/adr/` (0001 a 0005).

## 1. Visão geral

PHP ^8.4 com `declare(strict_types=1)` em todo arquivo, Laravel 13, `brick/math` para dinheiro, `firebase/php-jwt` 7, `php-amqplib` 3.7, `symfony/clock` (PSR-20). Análise estática com larastan e `phpstan-strict-rules` em **nível máximo**, Pint para estilo, PHPUnit 12. Imagem `php:8.5-fpm-alpine` com `pdo_pgsql` e `sockets`, rodando como `www-data`.

## 2. Camadas

```
app/Domain          Customer, Order, Item, erros (ApiException, ErrorCode). Sem framework.
app/Application     CustomerService, OrderService, Store, Repositories, EventPublisher, SearchRequest
app/Infrastructure
  Postgres          PostgresStore, PostgresCustomers/Orders, SearchQueryBuilder, PostgresErrors
  Messaging         AmqpPublisher, NullPublisher, consumidor e e-mail
app/Auth            JwtVerifier, Claims
app/Config          Settings (valida o ambiente)
app/Http            Controllers, Middleware (AuthenticateBearer, RequireRole, LimitRequestsPerUser)
app/Console         OrdersWork (o worker, comando artisan)
app/Providers       AppServiceProvider: o único ponto de composição
```

O Laravel é usado como casca HTTP e container. O domínio e os casos de uso não conhecem Eloquent nem facades.

## 3. Composição

`AppServiceProvider::register` registra tudo como singleton: `Settings`, `ClockInterface`, `Store`, `HealthCheck`, `JwtVerifier`, `EventPublisher` (usa `NullPublisher` se `BROKER_URL` estiver vazio, assim a API funciona sem broker) e os dois serviços. `boot` chama `Settings::assertValid()` e, fora do console, `assertValidForApi()`: configuração insegura em produção derruba o processo na partida e não na primeira requisição.

## 4. Fluxo de uma requisição

`routes/api.php`: `/health` é a única rota pública. Todas as outras estão num grupo com `AuthenticateBearer` e `LimitRequestsPerUser`; rotas de escrita de cliente adicionam `RequireRole:admin`. Rotas estáticas `/search` vêm antes de `/{id}`. Controller converte a requisição, chama o serviço e devolve JSON; erros de negócio são `ApiException` convertidas num envelope único com `request_id`.

## 5. Persistência (ADR 0005)

Sem Eloquent. Os repositórios usam `ConnectionInterface` com SQL explícito, porque as regras dependem de `FOR SHARE`/`FOR UPDATE` na mesma transação, `UPDATE ... WHERE version = ?`, índices únicos parciais com `deleted_at IS NULL` e `CHECK`s. Devolvem objetos de domínio que não sabem de banco.

- `PostgresStore::inTransaction` usa uma única tentativa (`transaction($work, 1)`): repetir após deadlock é decisão de quem chama.
- Valores nunca são concatenados; colunas vêm de uma lista permitida (`Entities`/`SearchEntity`). O `SearchQueryBuilder` ordena os filtros para gerar SQL determinístico, ignora filtro de campo desconhecido e trata ordenação desconhecida como `VALIDATION-06`.
- Dinheiro trafega como texto e é `numeric` no servidor; `brick/math` no PHP, nenhum `float`.
- Migrations do Laravel escritas em SQL puro em `up()`/`down()`; o CI roda `up`, `down`, `up`. É a única stack da família com ciclo de rollback exercitado.

## 6. Mensageria

`AmqpPublisher` publica `order.status.changed`; o worker é o comando `OrdersWork` (processo longo, `php artisan`), com retry, DLQ e envio de e-mail. Sem outbox. O PHP é um processo por requisição sob FPM, então não há estado em memória compartilhado entre requisições: o rate limit usa o cache do Laravel.

## 7. Segurança

JWT RS256 só com chave pública; `aud`/`iss` obrigatórios em produção; PII fora das respostas; cabeçalhos de segurança; limite de corpo; `HardeningTest` cobre o conjunto.

## 8. Testes

Suítes `Unit` (domínio, serviços, config, auth, infraestrutura), `Feature` (HTTP: clientes, pedidos, busca, envelope de erro, hardening, rotas protegidas, deriva do OpenAPI, wiring do worker) e `Integration` (PostgreSQL e RabbitMQ reais: `StoreTest`, `AmqpTest`, `EndToEndTest`, e `SearchColumnsTest`, que falha se uma coluna nova não entrar nos campos pesquisáveis). `InMemoryStore` e `RecordingPublisher` são os dublês.

## 9. CI

PHPStan nível max, Pint, PHPUnit com cobertura, migrations em ciclo, build da imagem, dependabot com auto merge. Há `AGENTS.md` com o checklist para agentes.

## 10. Trade-offs

| Escolha | Ganho | Custo |
|---|---|---|
| SQL explícito sem Eloquent | locks e concorrência visíveis e testáveis | mais mapeamento, nenhuma conveniência de relacionamento |
| Laravel só como casca | domínio sem framework | parte do que o Laravel oferece fica sem uso |
| PHPStan max + strict rules | erros de tipo pegos cedo | falsos positivos do framework precisam de exceção documentada |
| Um processo por requisição | isolamento simples | sem estado em memória, cache externo para limites |
| Sem outbox | simples | evento pode se perder |
