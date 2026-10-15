# ADR 0001: Camadas e direção das dependências

## Contexto

O projeto implementa em PHP/Laravel a especificação do `fastapi-order-api`. O Laravel incentiva Eloquent e lógica nos controllers; para um domínio com bloqueios de linha, travas otimistas e regras de transição, isso esconderia o que importa.

## Decisão

- `app/Domain`: entidades, regras e erros. Não conhece Laravel, SQL, HTTP nem broker.
- `app/Application`: casos de uso (`CustomerService`, `OrderService`) e as **portas** que eles consomem (`Store`, `CustomerRepository`, `OrderRepository`, `EventPublisher`), declaradas onde são usadas.
- `app/Infrastructure`: PostgreSQL (Query Builder com SQL explícito), mensageria (php-amqplib, Symfony Mailer).
- `app/Http`, `app/Console`, `app/Providers`, `config`, `routes`: a camada do framework. Controllers só traduzem HTTP para os casos de uso.
- Injeção por construtor; o `AppServiceProvider` é o único ponto que escolhe implementações.
- O usuário autenticado (`Actor`) é passado explicitamente aos casos de uso e é a única fonte das colunas de auditoria.
- `declare(strict_types=1)` em todo arquivo, `readonly` e visibilidade assimétrica do PHP 8.4 nas entidades, enums para status e códigos de erro, PHPStan nível máximo com regras estritas.

## Consequências

Os casos de uso são testados com um store em memória transacional e o domínio sem framework algum. O custo é mapear linhas para objetos à mão, aceito porque o domínio é pequeno e os locks dependem de SQL preciso.
