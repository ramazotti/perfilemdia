# Perfil em Dia

Bot do Telegram que recebe a foto real de um trabalho, escreve a legenda e publica no Instagram do profissional.

A fase 0 deixa o projeto instalável: configuração, banco, log e criptografia. O bot em si entra nas fases seguintes.

## Requisitos

- PHP 8.2 ou mais novo, com `curl`, `mbstring`, `pdo_mysql`, `sodium`, `imagick` (ou `gd`), `exif`, `fileinfo` e `json`
- Composer
- MySQL 5.7+ ou MariaDB 10.2+, InnoDB, `utf8mb4`
- HTTPS no ar (o Telegram e a Meta exigem)
- Cron

No servidor de produção (`br930`, Plano M) o PHP 8.3 já tem essas extensões. O MySQL de lá é 5.7.44. O schema desta fase foi escrito para esse banco.

## Instalação local

```bash
composer install
cp .env.example .env
php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
```

Cole a chave gerada em `APP_ENCRYPTION_KEY`. Crie o banco `perfilemdia` em utf8mb4 e preencha `DB_HOST`, `DB_USER` e `DB_PASS`.

```bash
php bin/migrate.php
composer test
```

A segunda execução de `php bin/migrate.php` não reaplica o que já rodou.

## cPanel (perfilemdia.com.br)

1. Envie o projeto para fora da pasta pública, por exemplo `/home1/juriss02/apps/perfilemdia`.
2. Aponte o document root do domínio para `public/`.
3. `storage/` fica fora desse document root. O `.htaccess` de `storage/` nega acesso se a pasta acabar exposta.
4. Copie `.env.example` para `.env` no servidor e preencha os segredos. Não envie o `.env` no Git.
5. Rode `php bin/migrate.php` por SSH.

Crontab previsto (os scripts de `cron/` chegam nas fases 1 e 5; não ative antes disso):

```
* * * * *   /opt/cpanel/ea-php83/root/usr/bin/php /home1/juriss02/apps/perfilemdia/cron/worker.php
0 * * * *   /opt/cpanel/ea-php83/root/usr/bin/php /home1/juriss02/apps/perfilemdia/cron/cleanup_media.php
30 3 * * *  /opt/cpanel/ea-php83/root/usr/bin/php /home1/juriss02/apps/perfilemdia/cron/refresh_tokens.php
```

## O que esta fase cobre

- Autoload PSR-4 no namespace `PerfilEmDia`
- `Config`, `Db`, `Logger`, `Http::finishRequest()` e `Security\Crypto`
- `migrations/001_init.sql` com as tabelas do MVP
- Teste de cifra e decifra
