# ADR 0005: SQL explícito com o Query Builder, sem Eloquent

## Contexto

O Eloquent é Active Record: o modelo conhece a tabela e se persiste. As regras deste domínio dependem de SQL preciso: `FOR SHARE`/`FOR UPDATE` na mesma transação da verificação de pedidos, `UPDATE ... WHERE version = ?` para a trava otimista, índices únicos parciais com `deleted_at IS NULL`, atualização de itens em um único comando.

## Decisão

Os repositórios (`PostgresCustomers`, `PostgresOrders`) usam `ConnectionInterface` com SQL explícito e devolvem objetos de domínio (`Customer`, `Order`) que não sabem de banco. Valores nunca são concatenados: filtros e ordenação passam por uma lista de colunas permitidas (`Entities`) e os valores vão sempre como parâmetros. Dinheiro trafega como texto e é convertido para `numeric` pelo servidor (`brick/math` no PHP): nenhum `float` toca valores monetários.

As migrations são do Laravel, escritas em SQL puro dentro de `up()`/`down()` (índices parciais e `CHECK` ficam mais claros e revisáveis assim), e o CI exercita o ciclo `up`, `down`, `up`.

## Consequências

Mais código de mapeamento e nenhuma conveniência de relacionamentos, em troca de locks visíveis, concorrência testável e um domínio sem dependência do framework. Um teste de integração falha se uma coluna nova da tabela não entrar na lista de campos pesquisáveis.
