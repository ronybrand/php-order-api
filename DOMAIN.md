# Domínio: gestão de pedidos

A especificação funcional (entidades, invariantes, eventos e catálogo de erros) é a do [`fastapi-order-api`](https://github.com/ronybrand/fastapi-order-api/blob/main/DOMAIN.md); este projeto a implementa em PHP e não a duplica, para que haja uma única fonte. Este arquivo mapeia a especificação para o código e registra onde esta implementação acrescenta ou escolhe diferente.

## Onde cada regra vive

| Regra da especificação | Código |
|---|---|
| Validação de cliente (nome, documento canônico, passaporte, e-mail) | `app/Domain/Customer.php` |
| Itens, total derivado, máquina de estados OPEN, CONFIRMED, CANCELED | `app/Domain/Order.php`, `OrderStatus.php` |
| Evento `OrderStatusChanged` (contrato da fila) | `app/Domain/OrderStatusChanged.php` |
| Criar pedido exige cliente existente; excluir cliente exige zero pedidos ativos | `app/Application/OrderService.php`, `CustomerService.php` |
| Unicidade de documento e passaporte entre ativos | índices parciais em `database/migrations` |
| Lock otimista (`version`) | `app/Infrastructure/Postgres/PostgresCustomers.php`, `PostgresOrders.php` |
| Busca, filtros e limites | `app/Application/SearchRequest.php`, `app/Infrastructure/Postgres/SearchQueryBuilder.php` |
| Códigos de erro | `app/Domain/Error/ErrorCode.php` |
| Retry, DLQ e deduplicação das notificações | `app/Infrastructure/Messaging/Processor.php` |

## Diferenças em relação à especificação

- **Resposta de cliente**: só `id` e `name`. Documento, passaporte e e-mail são PII e não voltam pela API.
- **Códigos acrescentados**: `RESOURCE-NOT-FOUND-00` (rota inexistente) e `VALIDATION-13` (método não permitido), para que 404 e 405 do roteador saiam no mesmo envelope `{message, code, params, request_id}`.
- **Campos pesquisáveis**: `customers` aceita `id, name, tax_id, passport_number, email, version, created_at, updated_at, created_by, updated_by`; `orders` aceita `id, customer_id, status, version, created_at, updated_at, created_by, updated_by`. A lista está em `app/Infrastructure/Postgres/Entities.php`. Colunas de exclusão lógica não são pesquisáveis nem ordenáveis.
- **Dinheiro**: calculado com `brick/math` (decimal exato) e devolvido como número JSON arredondado a duas casas. Uma soma que ultrapasse 2^53 centavos perderia precisão no JSON, o que o teto de itens, de preço e de quantidade torna impossível.
- **`size` na busca**: ausente ou `0` usa o padrão de 20.
- **Notificação**: publicada depois do commit, sem outbox (ADR 0002).
