ALTER TABLE plans
    ADD COLUMN max_instagram_accounts TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER posts_limit;

UPDATE plans SET max_instagram_accounts = 1;

INSERT INTO plans (
    slug,
    name,
    description,
    price_cents,
    posts_limit,
    max_instagram_accounts,
    trial_days,
    trial_price_cents,
    features,
    highlighted,
    active,
    sort_order,
    created_at,
    updated_at
)
SELECT
    'agencia',
    'Agência',
    'Para quem gerencia vários Instagram no mesmo Telegram.',
    9900,
    80,
    5,
    3,
    999,
    'Até 80 posts por mês (soma de todas as contas)
Até 5 Instagram conectados no mesmo Telegram
Troca de @ antes de cada publicação
Tudo do plano Estúdio: IA, vídeo, frase na foto, Surpreenda-me
Legenda e hashtags no tom de cada perfil
Até 5 versões por post
Suporte prioritário pelo Telegram',
    0,
    0,
    4,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug = 'agencia');
