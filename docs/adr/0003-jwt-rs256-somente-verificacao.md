# ADR 0003: JWT RS256, a API só verifica tokens

## Decisão

A API não emite tokens. Ela guarda apenas a chave **pública** do emissor (`JWT_PUBLIC_KEY_PATH`); vazar a configuração da API não permite forjar tokens.

O verificador (`App\Auth\JwtVerifier`) fixa o algoritmo `RS256` na própria chave (ataques `alg=none` e HS256 assinado com a chave pública falham), exige `exp`, `sub`, `aud` e `iss` válidos, tolera 30 s de relógio e rejeita `sub` vazio ou com mais de 200 caracteres (cabe nas colunas de auditoria). Sem a claim `roles`, o usuário recebe `["user"]`; `roles` com tipo errado invalida o token. O relógio é injetado, e a biblioteca (`firebase/php-jwt`), que guarda leeway e timestamp em propriedades estáticas globais, é restaurada ao estado anterior depois de cada verificação.

Em produção `JWT_AUDIENCE` e `JWT_ISSUER` são obrigatórios: a configuração falha na partida em vez de aceitar tokens de qualquer emissor. O motivo da falha de um token nunca é devolvido ao cliente (sempre `AUTH-01`).

## Autorização

Qualquer usuário autenticado lê e opera pedidos; criar, alterar e remover clientes exige o papel `admin` (ADR 0004).
