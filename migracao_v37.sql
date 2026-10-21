-- Relógios 2, migração v37: o servidor de e-mail próprio de cada pessoa.
-- Por padrão, o sistema manda e-mail pelo servidor do config.php (EMAIL_METODO, SMTP_*). Quem quiser põe o SMTP dele em
-- Minha conta (o Gmail, o Outlook, o do provedor): o servidor, a porta, a segurança, o usuário, a senha (cifrada, como as
-- das agendas) e o remetente. Os e-mails dessa pessoa saem por ele: os destinos de e-mail das coleções dela, os convites e
-- os pedidos de acesso que ela manda, e os da própria conta. Sem a linha, vale o servidor do sistema.
SET NAMES utf8mb4;

CREATE TABLE usuario_smtp (
    usuario_id     INT PRIMARY KEY,
    host           VARCHAR(200) NOT NULL,
    porta          INT NOT NULL,
    seguranca      VARCHAR(5) NOT NULL DEFAULT 'tls',
    usuario        VARCHAR(200) NULL,
    senha_cifrada  TEXT NULL,
    remetente      VARCHAR(200) NOT NULL,
    criado         DATETIME NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_usuario_smtp_seguranca CHECK (seguranca IN ('tls', 'ssl', 'nada')),
    CONSTRAINT ck_usuario_smtp_porta CHECK (porta BETWEEN 1 AND 65535),
    CONSTRAINT ck_usuario_smtp_remetente CHECK (remetente LIKE '%_@_%')
) ENGINE=InnoDB;
