-- Relógios 2, migração v27: o aplicativo no celular (PWA).
-- Os aparelhos lembrados de cada usuário: em Minha conta, "Lembrar este aparelho" grava uma chave no aparelho (um cookie) e
-- aqui o hash dela; o aplicativo instalado entra sem a senha por ela. "Esquecer" (ou trocar a senha) apaga. O manifesto e o
-- service worker saem pela API (recurso=manifest e recurso=sw): nada mais no banco.
SET NAMES utf8mb4;

-- os aparelhos lembrados de um usuário (o aplicativo no celular): a chave gravada no aparelho (um cookie) entra sem a senha
CREATE TABLE usuario_aparelho (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    nome        VARCHAR(200) NULL,
    chave_hash  CHAR(64) NOT NULL UNIQUE,
    criado      DATETIME NOT NULL,
    ultimo_uso  DATETIME NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB;
