# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/). O projeto não versiona releases (`main` é a linha de entrega); as entradas agrupam o histórico por tema, não por tag.

## [Unreleased]

### Added

- API de pedidos e clientes em PHP 8.4 e Laravel 13, portada da especificação do `fastapi-order-api`: domínio, casos de uso, repositórios PostgreSQL com SQL explícito, busca com filtros tipados, JWT RS256, worker de e-mail via RabbitMQ.
- Testes: unitários (domínio, serviços, JWT, busca, mensageria), de API sobre a aplicação real com store em memória, e de integração com PostgreSQL e RabbitMQ reais (repositórios, ponta a ponta, retry e DLQ).
- OpenAPI mantido igual às rotas, aos códigos de erro e às colunas pesquisáveis por testes.
- CI com PostgreSQL e RabbitMQ, piso de cobertura, ciclo `up`/`down`/`up` das migrations e build da imagem.

### Security

- Actions fixadas por SHA, Dependabot semanal com auto-merge de patch e minor, `composer audit` no CI.
- A resposta de cliente expõe só `id` e `name`; documento, passaporte e e-mail ficam fora da API e dos logs.
- A rota `/storage` do framework foi desligada: a API não serve arquivos.
