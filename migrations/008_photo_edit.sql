ALTER TABLE posts
    ADD COLUMN image_edit_count INT NOT NULL DEFAULT 0 AFTER regen_count;

UPDATE plans
SET features = 'Até 40 posts por mês
Foto única e carrossel
Legenda e hashtags no seu tom
Até 5 versões por post
Tratamento da foto por IA, com uma frase em cima
Suporte prioritário pelo Telegram'
WHERE slug = 'profissional';
