-- Relógios 2, migração v44: a construção pronta e os campos dos acessórios. O campo passa a ser de relógio ou de acessório
-- (campo.alvo; no acessório, o tipo a que ele vale, campo.acessorio_tipo, e o campo do relógio com que ele tem de bater
-- para o acessório servir no relógio, campo.serve_campo_id: a largura da pulseira com o entre alças), e cada acessório
-- guarda os valores dele (acessorio_valor; o múltiplo, um por linha no acessorio_valor_item). O passo em PHP
-- (migracao_v44_dados) põe em cada árvore os campos novos do modelo.json que ela ainda não tem: os da construção (material
-- da caixa, diâmetro, espessura, alça a alça, entre alças, peso, vidro, mostrador e a cor dele, luminescência, coroa,
-- fundo e as funções, múltiplo) e os da pulseira, no idioma da coleção.
SET NAMES utf8mb4;

ALTER TABLE campo ADD COLUMN alvo VARCHAR(10) NOT NULL DEFAULT 'relogio' AFTER multiplo;
ALTER TABLE campo ADD COLUMN acessorio_tipo VARCHAR(20) NULL AFTER alvo;
ALTER TABLE campo ADD COLUMN serve_campo_id INT NULL AFTER acessorio_tipo;
ALTER TABLE campo ADD INDEX campo_serve (serve_campo_id);
ALTER TABLE campo ALTER FOREIGN KEY (serve_campo_id) REFERENCES campo(id) ON DELETE SET NULL;
ALTER TABLE campo ADD CONSTRAINT ck_campo_alvo CHECK (alvo IN ('relogio', 'acessorio'));
ALTER TABLE campo ADD CONSTRAINT ck_campo_acessorio CHECK (alvo = 'acessorio' OR acessorio_tipo IS NULL);

CREATE TABLE acessorio_valor (
    acessorio_id  INT NOT NULL,
    campo_id      INT NOT NULL,
    valor         TEXT NOT NULL,
    PRIMARY KEY (acessorio_id, campo_id),
    INDEX (campo_id),
    FOREIGN KEY (acessorio_id) REFERENCES acessorio(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE acessorio_valor_item (
    acessorio_id  INT NOT NULL,
    campo_id      INT NOT NULL,
    valor         VARCHAR(120) NOT NULL,
    PRIMARY KEY (acessorio_id, campo_id, valor),
    INDEX (campo_id),
    FOREIGN KEY (acessorio_id) REFERENCES acessorio(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE,
    CONSTRAINT ck_acessorio_valor_item CHECK (TRIM(valor) <> '')
) ENGINE=InnoDB;
