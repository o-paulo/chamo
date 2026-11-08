CREATE DATABASE IF NOT EXISTS chamo
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE chamo;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    photo_data_url MEDIUMTEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    session_version INT NOT NULL DEFAULT 1,
    created_at VARCHAR(35) NOT NULL,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_no VARCHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    error_type VARCHAR(100) NOT NULL,
    department VARCHAR(100) NOT NULL,
    category VARCHAR(100) NOT NULL,
    classification VARCHAR(100) NOT NULL,
    severity VARCHAR(30) NOT NULL,
    priority VARCHAR(20) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    resolved_by BIGINT UNSIGNED NULL,
    created_at VARCHAR(35) NOT NULL,
    updated_at VARCHAR(35) NOT NULL,
    first_response_at VARCHAR(35) NULL,
    resolved_at VARCHAR(35) NULL,
    UNIQUE KEY uq_tickets_ticket_no (ticket_no),
    KEY idx_tickets_user_created (user_id, created_at),
    KEY idx_tickets_status_assignee (status, assigned_to),
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_tickets_assignee FOREIGN KEY (assigned_to) REFERENCES users(id),
    CONSTRAINT fk_tickets_resolver FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at VARCHAR(35) NOT NULL,
    KEY idx_messages_ticket_created (ticket_id, created_at),
    CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    created_at VARCHAR(35) NOT NULL,
    UNIQUE KEY uq_attachments_stored_name (stored_name),
    CONSTRAINT fk_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_ratings (
    ticket_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    requester_id BIGINT UNSIGNED NOT NULL,
    attendant_id BIGINT UNSIGNED NOT NULL,
    rating DECIMAL(2,1) NOT NULL,
    comment VARCHAR(1000) NOT NULL DEFAULT '',
    created_at VARCHAR(35) NOT NULL,
    CONSTRAINT fk_ratings_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_requester FOREIGN KEY (requester_id) REFERENCES users(id),
    CONSTRAINT fk_ratings_attendant FOREIGN KEY (attendant_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    created_at VARCHAR(35) NOT NULL,
    KEY idx_login_attempts_window (email, ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at VARCHAR(35) NOT NULL,
    used_at VARCHAR(35) NULL,
    created_at VARCHAR(35) NOT NULL,
    UNIQUE KEY uq_reset_token_hash (token_hash),
    CONSTRAINT fk_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id VARCHAR(100) NOT NULL,
    details TEXT NOT NULL,
    ip_hash CHAR(64) NOT NULL DEFAULT '',
    created_at VARCHAR(35) NOT NULL,
    KEY idx_audit_log_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(254) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    created_at VARCHAR(35) NOT NULL,
    sent_at VARCHAR(35) NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    KEY idx_mail_queue_pending (sent_at, attempts, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_drafts (
    id VARCHAR(100) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    values_json MEDIUMTEXT NOT NULL,
    saved_at VARCHAR(35) NOT NULL,
    updated_at VARCHAR(35) NOT NULL,
    KEY idx_ticket_drafts_user (user_id, updated_at),
    CONSTRAINT fk_drafts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('sla_first_response_very_high', '2'),
    ('sla_first_response_high', '4'),
    ('sla_first_response_medium', '8'),
    ('sla_first_response_low', '24');

INSERT IGNORE INTO users (id, email, name, role, password_hash, phone, is_active, session_version, created_at) VALUES
    (1, 'demo@chamo.com', 'Arthur Holanda', 'user', '$2y$10$NDMiunrNsUWJUk7ocnpVO.c0mQiuAR4xdc4GbbqCtWc0I/qAzP426', '5511990000001', 1, 1, '2026-10-25T09:00:00-03:00'),
    (2, 'atendente@chamo.sup.com', 'Alex Silva', 'attendant', '$2y$10$ofLEP.E4711m.qygVpMhm.c6jLEgUpPiOhf6XRwRX5nBK1A9Ke9CW', '5511990000002', 1, 1, '2026-10-25T09:00:00-03:00'),
    (3, 'admin@chamo.adm.com', 'Equipe Administrativa', 'admin', '$2y$10$ustyE8R9rWAT2tcEnjaz4OuqpSQb79.j0TJGwFNrBjWEOEnRyMH0C', '5511990000003', 1, 1, '2026-10-25T09:00:00-03:00'),
    (4, 'atendente@chamo.com', 'Alex Silva', 'attendant', '$2y$10$ofLEP.E4711m.qygVpMhm.c6jLEgUpPiOhf6XRwRX5nBK1A9Ke9CW', '5511990000002', 1, 1, '2026-10-25T09:00:00-03:00'),
    (5, 'admin@chamo.com', 'Equipe Administrativa', 'admin', '$2y$10$ustyE8R9rWAT2tcEnjaz4OuqpSQb79.j0TJGwFNrBjWEOEnRyMH0C', '5511990000003', 1, 1, '2026-10-25T09:00:00-03:00');

INSERT IGNORE INTO tickets (id, ticket_no, user_id, title, error_type, department, category, classification, severity, priority, description, status, assigned_to, resolved_by, created_at, updated_at, resolved_at) VALUES
    (1, 'CH-5101', 1, 'Falha ao acessar o sistema de estoque', 'Software', 'Operações', 'Sistemas e Aplicações', 'Incidente', 'Alto', 'high', 'O sistema exibe uma mensagem de erro ao entrar na área de estoque. Já tentei novamente e o problema continua.', 'progress', 2, NULL, '2026-10-24T09:00:00-03:00', '2026-10-24T09:00:00-03:00', NULL),
    (2, 'CH-5102', 1, 'Acesso ao painel de relatórios', 'Acesso / Conta', 'Financeiro', 'Acesso e Permissões', 'Solicitação de serviço', 'Moderado', 'medium', 'Preciso consultar os relatórios mensais, mas meu perfil não mostra essa opção.', 'waiting', 2, NULL, '2026-10-23T09:00:00-03:00', '2026-10-23T09:00:00-03:00', NULL),
    (3, 'CH-5103', 1, 'Impressora do setor sem conexão', 'Hardware', 'Recursos Humanos', 'Equipamentos', 'Incidente', 'Alto', 'high', 'A impressora compartilhada aparece como desconectada para toda a equipe do setor.', 'unassigned', NULL, NULL, '2026-10-25T09:00:00-03:00', '2026-10-25T09:00:00-03:00', NULL),
    (4, 'CH-5104', 1, 'Instalação de aplicativo de videoconferência', 'Software', 'Comercial', 'Solicitações', 'Solicitação de serviço', 'Baixo', 'low', 'Solicito a instalação do aplicativo aprovado para reuniões remotas neste computador.', 'unassigned', NULL, NULL, '2026-10-22T09:00:00-03:00', '2026-10-22T09:00:00-03:00', NULL),
    (5, 'CH-5105', 1, 'Dúvida sobre o sistema de ponto', 'Software', 'Recursos Humanos', 'Sistemas e Aplicações', 'Dúvida', 'Moderado', 'medium', 'Preciso de orientação para consultar o espelho de ponto mensal.', 'resolved', NULL, 2, '2026-10-21T09:00:00-03:00', '2026-10-21T09:00:00-03:00', '2026-10-21T09:00:00-03:00');
