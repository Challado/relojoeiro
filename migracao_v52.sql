-- Relógios 2, migração v52: o cadastro das marcas.
-- A marca (Casio, Seiko, Orient...) era um campo de texto livre de cada relógio. Agora é um cadastro da árvore, como os
-- calibres: vale em todas as coleções que usam a árvore, com o nome, o país, o site e a observação. Um relógio aponta para
-- uma marca (relogio_marca: a ligação opcional, que some sozinha quando um dos dois sai). O passo em PHP converte o texto
-- de cada relógio numa marca do cadastro (a mesma grafia, sem diferença de maiúsculas nem de espaços, vira uma marca
-- só), liga o relógio a ela e tira o campo de texto (a variável marca das fórmulas passa a ler o cadastro).
SET NAMES utf8mb4;

CREATE TABLE marca (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    no_id       INT NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    pais        VARCHAR(80) NULL,
    site        VARCHAR(500) NULL,
    observacao  TEXT NULL,
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_marca_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

CREATE TABLE relogio_marca (
    relogio_id  INT NOT NULL PRIMARY KEY,
    marca_id    INT NOT NULL,
    INDEX (marca_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (marca_id) REFERENCES marca(id) ON DELETE CASCADE
) ENGINE=InnoDB;
