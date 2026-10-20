-- Relógios 2, migração v33: os calibres.
-- O calibre (o movimento: NH35, ST2130, 469, B620...) é um cadastro da árvore, como os campos e as fórmulas: vale em todas as
-- coleções que usam a árvore. Ele tem nome, fabricante, o link do manual técnico, as recomendações e os valores dos campos
-- do cadastro que ele define (a reserva de marcha, a marcha esperada, aceita corda pela coroa...). Um relógio aponta para um
-- calibre (relogio_calibre: a ligação opcional, que some sozinha quando um dos dois sai) e herda dele os valores que ele
-- mesmo não tem: o valor do relógio, senão o do calibre, senão o padrão do campo (o do grupo).
SET NAMES utf8mb4;

CREATE TABLE calibre (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    no_id       INT NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    fabricante  VARCHAR(80) NULL,
    link        VARCHAR(500) NULL,
    observacao  TEXT NULL,
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_calibre_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

CREATE TABLE calibre_valor (
    calibre_id  INT NOT NULL,
    campo_id    INT NOT NULL,
    valor       TEXT NOT NULL,
    PRIMARY KEY (calibre_id, campo_id),
    FOREIGN KEY (calibre_id) REFERENCES calibre(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE relogio_calibre (
    relogio_id  INT NOT NULL PRIMARY KEY,
    calibre_id  INT NOT NULL,
    INDEX (calibre_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (calibre_id) REFERENCES calibre(id) ON DELETE CASCADE
) ENGINE=InnoDB;
