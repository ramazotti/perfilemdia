UPDATE plans
SET description = 'Para quem posta de vez em quando, no serviço ou no produto.',
    updated_at = NOW()
WHERE slug = 'essencial';
