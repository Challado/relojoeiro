-- Relógios 2, migração v43: as regras do valor de um campo. Os valores possíveis (campo_opcao) passam a valer também no
-- texto e no número (o valor tem de ser um deles), o número ganha o mínimo e o máximo (campo.minimo, campo.maximo), e o
-- campo pode ser múltiplo (campo.multiplo: a lista múltipla, como as funções do relógio), com os valores um por linha
-- (campo_valor_item no relógio, calibre_valor_item no calibre). Os dados de antes continuam como estão.
SET NAMES utf8mb4;

ALTER TABLE campo ADD COLUMN minimo DECIMAL(18,6) NULL AFTER secao_id;
ALTER TABLE campo ADD COLUMN maximo DECIMAL(18,6) NULL AFTER minimo;
ALTER TABLE campo ADD COLUMN multiplo TINYINT NOT NULL DEFAULT 0 AFTER maximo;
ALTER TABLE campo ADD CONSTRAINT ck_campo_multiplo CHECK (multiplo IN (0, 1));
ALTER TABLE campo ADD CONSTRAINT ck_campo_faixa CHECK (minimo IS NULL OR maximo IS NULL OR minimo <= maximo);

CREATE TABLE campo_valor_item (
    relogio_id  INT NOT NULL,
    campo_id    INT NOT NULL,
    valor       VARCHAR(120) NOT NULL,
    PRIMARY KEY (relogio_id, campo_id, valor),
    INDEX (campo_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE,
    CONSTRAINT ck_campo_valor_item CHECK (TRIM(valor) <> '')
) ENGINE=InnoDB;

CREATE TABLE calibre_valor_item (
    calibre_id  INT NOT NULL,
    campo_id    INT NOT NULL,
    valor       VARCHAR(120) NOT NULL,
    PRIMARY KEY (calibre_id, campo_id, valor),
    INDEX (campo_id),
    FOREIGN KEY (calibre_id) REFERENCES calibre(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE,
    CONSTRAINT ck_calibre_valor_item CHECK (TRIM(valor) <> '')
) ENGINE=InnoDB;
