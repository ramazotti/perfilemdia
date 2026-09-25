UPDATE plans
SET features = CONCAT(features, '\nIdeia do dia, a partir das 8h, e lembrete se passar de 1 dia sem postar'),
    updated_at = NOW()
WHERE slug IN ('essencial', 'profissional')
  AND features NOT LIKE '%Ideia do dia%';

UPDATE plans
SET description = 'Para quem quer o post criado a partir de uma ideia, ou o Surpreenda-me.',
    features = REPLACE(
        features,
        'Post criado pela IA a partir de uma ideia',
        'Post criado pela IA, ou Surpreenda-me: foto, texto e marca, e você só aprova\nIdeia do dia, a partir das 8h, e lembrete se passar de 1 dia sem postar'
    ),
    updated_at = NOW()
WHERE slug = 'estudio'
  AND features NOT LIKE '%Surpreenda-me%';
