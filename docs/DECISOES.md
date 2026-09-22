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
