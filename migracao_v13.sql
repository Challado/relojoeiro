-- Relógios 2, migração v13: o manual de cada relógio, um arquivo (PDF ou imagem) guardado no banco, como a foto. Os seus
-- dados não mudam.
SET NAMES utf8mb4;

-- o manual: um por relógio; sai junto quando o relógio é excluído
CREATE TABLE manual (
    relogio_id  INT PRIMARY KEY,
    nome        VARCHAR(200) NOT NULL,   -- o nome do arquivo enviado
    tipo        VARCHAR(40) NOT NULL,    -- application/pdf, image/jpeg, image/png, image/webp
    tamanho     INT NOT NULL,            -- em bytes
    dados       LONGBLOB NOT NULL,
    atualizado  DATETIME NOT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;
