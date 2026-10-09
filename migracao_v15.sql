-- Relógios 2, migração v15: a cópia de segurança de cada documento dentro do banco. O arquivo continua na pasta dos
-- documentos e vai também para o banco, inteiro, em pedaços de 4 MB (assim um vídeo grande não esbarra no limite de um
-- comando do MySQL nem na memória do PHP). Se o arquivo sumir da pasta (um disco trocado, um backup do banco restaurado
-- noutro servidor), o sistema o recria a partir do banco. Os documentos que já existem ganham a cópia aos poucos, pelo cron.
SET NAMES utf8mb4;

-- no_banco: 1, a cópia no banco está completa; hash: o SHA-256 do arquivo, para conferir a cópia ao recriar
ALTER TABLE documento ADD COLUMN no_banco TINYINT NOT NULL DEFAULT 0 AFTER miniatura;
ALTER TABLE documento ADD COLUMN hash VARCHAR(64) NULL AFTER no_banco;

-- os pedaços do arquivo de cada documento, na ordem (parte 0, 1, 2...); a parte -1 é a miniatura da foto
CREATE TABLE documento_parte (
    documento_id  INT NOT NULL,
    parte         INT NOT NULL,
    dados         MEDIUMBLOB NOT NULL,
    PRIMARY KEY (documento_id, parte),
    FOREIGN KEY (documento_id) REFERENCES documento(id) ON DELETE CASCADE
) ENGINE=InnoDB;
