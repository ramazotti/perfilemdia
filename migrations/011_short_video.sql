ALTER TABLE post_media
    ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'image' AFTER telegram_msg_id;

UPDATE plans
SET features = CONCAT(features, '\nVídeo curto, de 3 a 90 segundos'),
    updated_at = NOW()
WHERE slug = 'profissional'
  AND features NOT LIKE '%Vídeo curto%';
