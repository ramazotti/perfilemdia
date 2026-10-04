# Plano Agência: multicontas no Telegram

Documento de produto e engenharia. Objetivo: um usuário do Telegram gerenciar vários Instagram, com limite e cobrança no plano **Agência (R$ 99/mês)**.

## Situação hoje

- Um `users` (Telegram) = no máximo um registro em `instagram_accounts` (`user_id` UNIQUE).
- Nova conexão OAuth **substitui** a conta anterior.
- Posts, tokens, `/status` e publicação usam `UserRepository::instagramAccount($userId)`.
- Tom, contato, hashtags ficam no **usuário**, não por `@`.

## Produto: plano Agência

| Item | Valor |
|------|--------|
| Slug | `agencia` |
| Preço cheio | R$ 99,00 (`9900` centavos) |
| Teste | 3 dias, R$ 9,99 (`999` centavos), igual aos outros planos |
| Posts/mês | **80**, contando **todas** as contas juntas |
| Instagram | **Até 5** por Telegram |
| Recursos | Tudo que o **Estúdio** já tem (IA, vídeo, frase na foto, Surpreenda-me, edição de foto) |
| Público | Social media, clínicas com várias unidades, franquias, assessorias |

Planos menores continuam com **1 Instagram** (`max_instagram_accounts = 1`).

A linha do plano entra na migração `031_agencia_plan.sql` com **`active = 0`** até a multicontas estar pronta. Depois: admin ou `UPDATE plans SET active = 1 WHERE slug = 'agencia'`.

## Regras de negócio

1. **Limite de posts** é único por assinatura, não por `@`.
2. **Conta ativa**: posts novos vão para o `@` selecionado; dá para trocar antes de `/novo` ou no início do fluxo.
3. **Perfil do bot** (tom, cidade, contato, hashtags, about): por padrão **por Instagram**; quem só tem 1 conta não muda nada na UX.
4. **OAuth**: cada `@` = uma autorização; não apagar as outras ao conectar mais uma.
5. **Downgrade** (Agência → outro): se tiver mais de 1 IG conectado, bloquear troca de plano ou pedir desconectar extras (definir copy no portal).
6. **Meta**: cada `@` profissional; em app em desenvolvimento, testador por cliente.

## Fase 1: Banco e plano (feito nesta entrega)

- [x] Coluna `plans.max_instagram_accounts` (default 1).
- [x] INSERT plano `agencia` (inativo até go-live).
- [ ] Deploy + `bin/migrate.php` em produção.

## Fase 2: Schema multicontas

Arquivo sugerido: `032_instagram_multicontas.sql`.

```sql
-- Remover 1:1 user -> instagram
ALTER TABLE instagram_accounts DROP INDEX user_id; -- nome real via SHOW INDEX
ALTER TABLE instagram_accounts ADD UNIQUE KEY uq_instagram_ig_user (ig_user_id);
ALTER TABLE instagram_accounts ADD UNIQUE KEY uq_instagram_user_ig (user_id, ig_user_id);

ALTER TABLE users ADD COLUMN active_instagram_account_id BIGINT UNSIGNED NULL AFTER pending_action;
ALTER TABLE users ADD CONSTRAINT fk_users_active_ig FOREIGN KEY (active_instagram_account_id) REFERENCES instagram_accounts(id);

ALTER TABLE posts ADD COLUMN instagram_account_id BIGINT UNSIGNED NULL AFTER user_id;
-- Backfill: instagram_account_id = única conta do user_id
-- INDEX (user_id, instagram_account_id, status)
```

Opcional (recomendado para Agência):

```sql
CREATE TABLE instagram_profiles (
  instagram_account_id BIGINT UNSIGNED PRIMARY KEY,
  tone ENUM(...) NULL,
  contact_cta VARCHAR(255) NULL,
  fixed_hashtags VARCHAR(255) NULL,
  about VARCHAR(500) NULL,
  profession VARCHAR(120) NULL,
  city VARCHAR(120) NULL,
  FOREIGN KEY (instagram_account_id) REFERENCES instagram_accounts(id)
);
```

Migração de dados: copiar campos de `users` para `instagram_profiles` na conta existente.

## Fase 3: Domínio e billing

| Arquivo | Mudança |
|---------|---------|
| `UserRepository` | `listInstagramAccounts`, `saveInstagramAccount` sem sobrescrever outras linhas; `setActiveInstagramAccount`; `instagramAccount` passa a resolver a **ativa** |
| `PlanAccess` | `maxInstagramAccounts($userId)` lê `plans.max_instagram_accounts`; `canCreateWithAi`, `canEditPhoto`, `canPublishVideo` incluem slug `agencia` |
| `PostRepository` | `create` grava `instagram_account_id` |
| `PostService` / publisher | Token e `ig_user_id` da conta do post (ou ativa na criação) |
| `cron/refresh_tokens.php` | Renovar **todas** as contas ativas do usuário |
| `CheckoutService` | Nenhuma mudança estrutural; plano já vem do `plans` |

Gate ao conectar:

- Se `count(accounts) >= max` → mensagem “Seu plano permite até N Instagram. Troque para Agência ou desconecte uma conta.”

## Fase 4: Bot e OAuth

**Comandos / callbacks**

- `/contas` ou botão em `/perfil`: lista `@` conectados, marca ativa, “Adicionar Instagram”, “Desconectar @”.
- Callbacks `ig:pick:{id}`, `ig:add`, `ig:off:{id}` (nomes a definir no padrão atual `pk:` / `wh:`).
- No `/novo` e na prévia: linha fixa **“Publicando em @usuario”** + botão trocar se `count > 1`.
- `Messages::status` e `/status`: posts do mês + “Contas: 2 de 5 · Ativa: @foo”.

**OAuth**

- `instagram-callback.php`: após `saveInstagramAccount`, se for a **primeira** conta ou não houver ativa, definir `active_instagram_account_id`.
- `conectar.php`: query `?add=1` para fluxo “adicionar” (copy diferente de onboarding).

**Onboarding**

- Primeira conta: fluxo atual.
- Agência com segunda conta: pular perguntas globais se `instagram_profiles` existir; opcional mini-onboarding só daquela `@`.

## Fase 5: Site e admin

- `PublicSite` / portal: card Agência (já aparece quando `active = 1`).
- FAQ: “Posso gerenciar vários Instagram?” → plano Agência, até 5, limite compartilhado.
- Admin: isenção e troca de plano para `agencia`; listar contas IG por `user_id`.

## Fase 6: Testes

- PHPUnit: usuário Agência conecta 2 contas (mock OAuth), troca ativa, `/novo` grava `instagram_account_id` certo, limite 80 soma posts das duas contas.
- Regressão: Essencial/Estúdio continuam com 1 conta; segunda conexão recusada ou substitui conforme regra do plano (Essencial: substituir **ou** recusar segunda; recomendado **recusar** com copy clara).

## Ordem de entrega sugerida

1. Deploy migração **031** (plano invisível no site).
2. Schema **032** + repositórios + gates Agência nos `PlanAccess`.
3. Bot `/contas` + OAuth add + posts com `instagram_account_id`.
4. Perfis por `@` (se entrar no MVP Agência).
5. `active = 1` no plano Agência + copy no site.
6. Comunicação para quem hoje usa vários Telegrams.

## Riscos

- `wakeDatabase()` / reconnect: posts devem carregar `instagram_account_id` antes de publicar.
- Conta desconectada com posts pendentes: falha clara ou bloqueio na prévia.
- Trial Agência antes do go-live: manter `active = 0` ou desabilitar slug no checkout.

## Checklist go-live Agência

- [ ] Migração 032 aplicada
- [ ] Testes automatizados verdes
- [ ] `plans.active = 1` para `agencia`
- [ ] Webhook e cron de token ok com N contas
- [ ] Texto em `/ajuda` e planos no site
