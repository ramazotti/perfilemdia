# Decisões

## 2026-09-21: MySQL 5.7 no servidor, MariaDB 10.2 no Docker local

O plano pedia MySQL 8 ou MariaDB 10.6+. A hospedagem do `perfilemdia.com.br` (HostGator, Plano M, servidor `br930`) tem MySQL 5.7.44. O Docker local deste Mac usa MariaDB 10.2.

`migrations/001_init.sql` fica no que esses dois bancos aceitam: InnoDB, `utf8mb4`, `JSON`, `DATETIME` com `ON UPDATE CURRENT_TIMESTAMP`. Sem recurso exclusivo do MySQL 8.

Cada `CREATE TABLE` declara `ENGINE=InnoDB` e `utf8mb4_unicode_ci` porque o padrão do MariaDB local é `latin1`.

## 2026-09-22: cobrança pela interface do gateway

O checkout de produção fala com o Asaas atrás de `PaymentGateway`. Sem `PAYMENT_API_KEY`, o ambiente local usa `SandboxGateway`: o Pix só fica pago quando o webhook confirma, e o cartão não é gravado (só bandeira e os 4 últimos dígitos). Mercado Pago entra como outro implementador da mesma interface, se for preciso trocar.

No Docker local, o PHP do Mac usa `DB_HOST=127.0.0.1`. O Apache do container não alcança esse endereço, então `Db` tenta o serviço `db` quando `APP_ENV=local` e a primeira conexão falha.

No endpoint `GET /{version}/me?fields=user_id,username,account_type`, o campo usado como IG_ID na publicação (`/{ig-user-id}/media`) é `user_id`, não `id`. É a Instagram API with Instagram Login.

## 2026-09-22: legendas pelo OpenRouter

A legenda sai pelo OpenRouter, no formato da API da OpenAI, o mesmo caminho do SigTerceiros. O modelo padrão é openai/gpt-4o-mini e a foto vai em base64. A reserva é google/gemini-2.5-flash. A chave fica em OPENROUTER_API_KEY e não aparece no admin. O campo de modelo em Configurações, quando preenchido, substitui o do .env.

## 2026-09-22: cobrança pela AppMax

O checkout de produção usa a AppMax quando `PAYMENT_GATEWAY=appmax` e a loja já tem `client_id` e `client_secret`. Sem essas credenciais, o ambiente local continua no sandbox.

O número do cartão é tokenizado no navegador pelo appmax.js. O servidor só recebe o token de uso único. O IP do comprador também vem desse script, porque a AppMax exige isso para criar o cliente.

A primeira cobrança é o valor do checkout (teste de 3 dias ou ciclo cheio). No cartão, a assinatura na AppMax fica no preço cheio do plano. No fim do teste, a renovação antecipa essa cobrança. Nos ciclos seguintes, a data local acompanha a próxima cobrança da AppMax, para não cobrar duas vezes. Pix confirma pelo webhook, depois que a API confirma o pedido pago.

## 2026-09-22: AppMax pela API v3

A loja recebeu um access-token da API antiga, em `https://admin.appmax.com.br/api/v3`. O par `client_id` e `client_secret` da API nova não estava disponível neste painel. O checkout de produção usa esse token quando `APPMAX_ACCESS_TOKEN` está preenchido.

Pix, pedido e cartão saem dessa API. O número do cartão passa pela tokenização da v3 e não é gravado. A renovação no cartão usa o `upsell_hash` devolvido na primeira cobrança. Sem o token, o ambiente local continua no sandbox.

## 2026-09-22: conta do cliente

A pessoa entra em /minha-conta por um link de 12 horas que o bot manda em /assinatura e quando a publicação pausa. A página mostra plano, posts, pagamentos, Instagram e chamados. Dá para pagar o período em aberto, guardar outro cartão para a próxima cobrança, marcar troca de plano ou de ciclo, e cancelar no fim do período já pago. O cron não cobra uma assinatura com cancelamento marcado.

