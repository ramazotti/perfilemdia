ALTER TABLE posts
    ADD COLUMN creative TINYINT(1) NOT NULL DEFAULT 0 AFTER image_edit_count;

ALTER TABLE subscriptions
    ADD COLUMN comp_forever TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN comp_until DATETIME NULL AFTER comp_forever;

INSERT INTO plans (slug, name, description, price_cents, posts_limit, trial_days, trial_price_cents, features, highlighted, active, sort_order, created_at, updated_at)
SELECT 'estudio', 'Estúdio', 'Para quem quer o post criado a partir de uma ideia.', 7490, 50, 3, 799, 'Até 50 posts por mês
Foto única e carrossel
Vídeo curto, de 3 a 90 segundos
Post criado pela IA a partir de uma ideia
Tratamento da foto por IA, com uma frase em cima
Legenda e hashtags no seu tom
Até 5 versões por post', 0, 1, 3, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug = 'estudio');
