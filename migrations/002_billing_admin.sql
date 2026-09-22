CREATE TABLE plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    posts_limit INT UNSIGNED NOT NULL,
    features TEXT NOT NULL,
    highlighted TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    document VARCHAR(20) NOT NULL,
    document_type ENUM('cpf', 'cnpj') NOT NULL,
    user_id INT UNSIGNED NULL,
    status ENUM('aguardando_ativacao', 'ativo', 'inadimplente', 'cancelado', 'excluido') NOT NULL DEFAULT 'aguardando_ativacao',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_document (document),
    KEY idx_customers_email (email),
    KEY idx_customers_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    cycle ENUM('mensal', 'anual') NOT NULL,
    status ENUM('pendente', 'ativa', 'inadimplente', 'cancelada') NOT NULL DEFAULT 'pendente',
    price_cents INT UNSIGNED NOT NULL,
    current_period_end DATETIME NULL,
    grace_until DATETIME NULL,
    gateway_subscription_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sub_customer (customer_id),
    KEY idx_sub_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE checkouts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    subscription_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    cycle ENUM('mensal', 'anual') NOT NULL,
    coupon_code VARCHAR(40) NULL,
    amount_cents INT UNSIGNED NOT NULL,
    activation_code VARCHAR(16) NULL,
    status ENUM('aberto', 'pago', 'recusado', 'expirado') NOT NULL DEFAULT 'aberto',
    created_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checkouts_public (public_id),
    UNIQUE KEY uq_checkouts_code (activation_code),
    KEY idx_checkouts_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    checkout_id INT UNSIGNED NULL,
    subscription_id INT UNSIGNED NULL,
    method ENUM('pix', 'cartao', 'cupom') NOT NULL,
    status ENUM('pendente', 'pago', 'recusado', 'estornado') NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    gateway VARCHAR(40) NOT NULL,
    external_id VARCHAR(80) NULL,
    brand VARCHAR(20) NULL,
    last4 CHAR(4) NULL,
    pix_payload TEXT NULL,
    pix_expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pay_external (gateway, external_id),
    KEY idx_pay_checkout (checkout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_webhooks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    gateway VARCHAR(40) NOT NULL,
    external_id VARCHAR(120) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hook (gateway, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    skey VARCHAR(80) NOT NULL,
    svalue TEXT NOT NULL,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deletion_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    protocol VARCHAR(16) NOT NULL,
    email VARCHAR(190) NOT NULL,
    document VARCHAR(20) NULL,
    status ENUM('aberto', 'concluido') NOT NULL DEFAULT 'aberto',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deletion_protocol (protocol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO plans (slug, name, description, price_cents, posts_limit, features, highlighted, active, sort_order, created_at, updated_at) VALUES
('essencial', 'Essencial', 'Para quem posta quando termina um trabalho.', 2900, 16, 'Até 16 posts por mês\nFoto única e carrossel\nLegenda e hashtags no seu tom\nAté 5 versões por post', 0, 1, 1, NOW(), NOW()),
('profissional', 'Profissional', 'Para quem quer aparecer quase todo dia.', 4900, 40, 'Até 40 posts por mês\nFoto única e carrossel\nLegenda e hashtags no seu tom\nAté 5 versões por post\nSuporte prioritário pelo Telegram', 1, 1, 2, NOW(), NOW());

INSERT INTO settings (skey, svalue) VALUES
('support_email', 'ajuda@perfilemdia.com.br'),
('regen_limit', '5'),
('media_ttl_hours', '48'),
('coupon_code', 'BETA'),
('coupon_percent', '100'),
('coupon_active', '1'),
('ai_model', ''),
('ai_model_fallback', ''),
('ai_price_in_usd', '0'),
('ai_price_out_usd', '0'),
('usd_brl', '5.50'),
('ai_system_prompt', ''),
('instagram_mode', 'instagram_login');
