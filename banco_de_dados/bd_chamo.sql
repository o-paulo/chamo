DROP DATABASE IF EXISTS sistema_tickets;
CREATE DATABASE sistema_tickets
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_tickets;

CREATE TABLE perfis         (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(30) NOT NULL UNIQUE);
CREATE TABLE status_ticket  (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(40) NOT NULL UNIQUE);
CREATE TABLE prioridades    (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(20) NOT NULL UNIQUE);
CREATE TABLE tipos_erro     (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) NOT NULL UNIQUE);
CREATE TABLE departamentos  (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) NOT NULL UNIQUE);
CREATE TABLE categorias     (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) NOT NULL UNIQUE);
CREATE TABLE classificacoes (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) NOT NULL UNIQUE);

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    perfil_id INT NOT NULL,
    nome VARCHAR(80) NOT NULL,
    sobrenome VARCHAR(120) NOT NULL,
    nome_usuario VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    celular VARCHAR(20) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por INT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (perfil_id)  REFERENCES perfis(id),
    FOREIGN KEY (criado_por) REFERENCES usuarios(id)
);

CREATE TABLE tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    titulo VARCHAR(120) NOT NULL,
    tipo_erro_id INT NOT NULL,
    departamento_id INT NOT NULL,
    categoria_id INT NOT NULL,
    classificacao_id INT NOT NULL,
    gravidade ENUM('BAIXO','MODERADO','ALTO','MUITO ALTO') NOT NULL,
    prioridade_id INT NULL,
    descricao TEXT NOT NULL,
    status_id INT NOT NULL DEFAULT 1,
    atendente_id INT NULL,
    resolvido_por_id INT NULL,
    resolvido_em DATETIME NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id)       REFERENCES usuarios(id),
    FOREIGN KEY (tipo_erro_id)     REFERENCES tipos_erro(id),
    FOREIGN KEY (departamento_id)  REFERENCES departamentos(id),
    FOREIGN KEY (categoria_id)     REFERENCES categorias(id),
    FOREIGN KEY (classificacao_id) REFERENCES classificacoes(id),
    FOREIGN KEY (prioridade_id)    REFERENCES prioridades(id),
    FOREIGN KEY (status_id)        REFERENCES status_ticket(id),
    FOREIGN KEY (atendente_id)     REFERENCES usuarios(id),
    FOREIGN KEY (resolvido_por_id) REFERENCES usuarios(id)
);

CREATE TABLE anexos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    nome_original VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_arquivo VARCHAR(100),
    tamanho_bytes INT,
    enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);

CREATE TABLE comentarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    usuario_id INT NOT NULL,
    texto TEXT NOT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id)  REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE historico_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    usuario_id INT NOT NULL,
    acao VARCHAR(60) NOT NULL,
    campo VARCHAR(60) NULL,
    valor_anterior VARCHAR(255) NULL,
    valor_novo VARCHAR(255) NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id)  REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

INSERT INTO perfis (nome) VALUES ('ADMINISTRADOR'),('ATENDENTE'),('USUARIO');

INSERT INTO status_ticket (nome) VALUES
('ABERTO'),('EM ANÁLISE'),('EM ATENDIMENTO'),('AGUARDANDO USUÁRIO'),
('RESOLVIDO'),('ENCERRADO'),('CANCELADO');

INSERT INTO prioridades (nome) VALUES ('BAIXA'),('MÉDIA'),('ALTA'),('CRÍTICA');

INSERT INTO tipos_erro (nome) VALUES
('Hardware'),('Software'),('BIOS / Firmware'),('Rede / Internet'),('Acesso / Conta'),('Outro');
INSERT INTO departamentos (nome) VALUES
('Tecnologia da Informação'),('Recursos Humanos'),('Financeiro'),('Operações'),('Comercial');
INSERT INTO categorias (nome) VALUES
('Acesso e Permissões'),('Sistemas e Aplicações'),('Equipamentos'),('Solicitações'),('Outros');
INSERT INTO classificacoes (nome) VALUES
('Incidente'),('Solicitação de serviço'),('Dúvida'),('Alteração');