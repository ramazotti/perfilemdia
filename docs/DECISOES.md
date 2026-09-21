# Decisões

## 2026-09-21: MySQL 5.7 no servidor, MariaDB 10.2 no Docker local

O plano pedia MySQL 8 ou MariaDB 10.6+. A hospedagem do `perfilemdia.com.br` (HostGator, Plano M, servidor `br930`) tem MySQL 5.7.44. O Docker local deste Mac usa MariaDB 10.2.

`migrations/001_init.sql` fica no que esses dois bancos aceitam: InnoDB, `utf8mb4`, `JSON`, `DATETIME` com `ON UPDATE CURRENT_TIMESTAMP`. Sem recurso exclusivo do MySQL 8.

Cada `CREATE TABLE` declara `ENGINE=InnoDB` e `utf8mb4_unicode_ci` porque o padrão do MariaDB local é `latin1`.
