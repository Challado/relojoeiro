-- Relógios 2, migração v42: as divisórias da ficha. Cada árvore ganha as seções dos campos (presas à raiz: o nome e a
-- ordem), e o campo aponta para a dele (campo.secao_id; vazio: no fim, sem divisória; apagar a seção deixa o campo sem
-- ela). O cadastro, o painel, o passaporte e os relatórios mostram os campos agrupados por seção. O passo em PHP
-- (migracao_v42_dados) põe as seções do modelo.json em cada árvore, no idioma da coleção, e os campos do modelo nelas.
SET NAMES utf8mb4;

CREATE TABLE campo_secao (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    no_id  INT NOT NULL,
    nome   VARCHAR(80) NOT NULL,
    ordem  INT NOT NULL DEFAULT 0,
    UNIQUE (no_id, nome),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_campo_secao_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

ALTER TABLE campo ADD COLUMN secao_id INT NULL AFTER calibre;
ALTER TABLE campo ADD INDEX campo_secao (secao_id);
ALTER TABLE campo ALTER FOREIGN KEY (secao_id) REFERENCES campo_secao(id) ON DELETE SET NULL;
