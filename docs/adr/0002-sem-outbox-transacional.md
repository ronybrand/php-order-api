# ADR 0002: Notificação publicada após o commit, sem outbox transacional

## Contexto

Confirmar ou cancelar um pedido deve avisar o cliente por e-mail. O e-mail é efeito colateral lento e sujeito a falha (SMTP), por isso é assíncrono: a API publica um evento no RabbitMQ e o worker (`php artisan orders:work`) envia o e-mail. Em PHP cada requisição abre sua própria conexão com o broker.

## Decisão

A API publica o evento **depois** do commit, em *best effort* e com confirmação do broker: se ele estiver indisponível, o erro é registrado (sem o e-mail do cliente) e a transição do pedido continua válida. Não há outbox transacional.

A entrega do worker é *at-least-once*: até 3 retentativas com espera fixa de 2 s (cabeçalho `x-retry-count`), depois a fila `order.status.changed.dlq`; falhas permanentes (destinatário inválido, mensagem malformada) vão direto à DLQ. A mensagem original só é reconhecida (`ack`) depois que o broker confirmou a cópia; sem confirmação ela volta para a fila. Reentregas são descartadas pelo `message_id` (conjunto em memória do processo, com TTL e tamanho limitados).

## Alternativas

- **Outbox transacional** (tabela na mesma transação + relay): elimina a perda de evento entre commit e publish. Descartado por ora: a notificação é informativa, uma perda ocasional é tolerável e o custo (tabela, relay, limpeza) não se justifica ainda.
- **E-mail síncrono na requisição**: simples, mas amarra a latência e a disponibilidade da API ao servidor SMTP.

## Consequências

Entre o commit e a publicação, uma queda do processo perde o evento. O dedupe em memória não sobrevive a um restart do worker; um e-mail duplicado raro é aceito. Se a notificação virar requisito de negócio, adotar o outbox.
