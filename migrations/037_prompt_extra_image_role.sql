-- Uso da imagem do extra: logo (carimbada na arte) ou style (referência visual para a IA).
ALTER TABLE instagram_prompt_extras
    ADD COLUMN image_role VARCHAR(16) NOT NULL DEFAULT 'logo' AFTER image_path;
