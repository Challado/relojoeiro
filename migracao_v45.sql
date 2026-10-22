-- Relógios 2, migração v45: os winders. A coleção cadastra os winders dela (winder: o nome e a observação; o resto, a marca,
-- o modelo..., são campos com campo.alvo = winder, com os valores no winder_valor e no winder_valor_item) e as posições de
-- cada um (winder_posicao: o nome, a ordem e se está ligada; o programa, as rotações por dia, o sentido, o ciclo..., são
-- campos com campo.alvo = posicao, nos valores winder_posicao_valor e winder_posicao_valor_item). O tipo de lançamento
-- marcado com winder (lancamento_tipo.winder; o "No winder" do modelo) pede a posição ao abrir a sessão, gravada no
-- lancamento_winder. O passo em PHP (migracao_v45_dados) põe em cada árvore a unidade TPD, os campos do modelo.json do
-- relógio (as rotações por dia que ele pede, o sentido em que dá corda), do winder e da posição, as fórmulas winder_ganho
-- (quanto cada hora no winder carrega, pela posição) e winder_falta (as horas até encher), e a reserva_restante passa a
-- usar a winder_ganho, onde ainda é a do modelo.
SET NAMES utf8mb4;

ALTER TABLE lancamento_tipo ADD COLUMN winder TINYINT NOT NULL DEFAULT 0 AFTER marcha;
ALTER TABLE lancamento_tipo ADD CONSTRAINT ck_lancamento_tipo_winder CHECK (winder IN (0, 1) AND (winder = 0 OR formato = 'sessao'));
ALTER TABLE campo ADD CONSTRAINT ck_campo_alvo CHECK (alvo IN ('relogio', 'acessorio', 'winder', 'posicao'));
UPDATE lancamento_tipo SET winder = 1 WHERE identificador = 'winder' AND formato = 'sessao';

CREATE TABLE winder (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id  INT NOT NULL,
    nome        VARCHAR(120) NOT NULL,
    observacao  VARCHAR(1000) NULL,
    criado      DATETIME NOT NULL,
    INDEX (colecao_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_winder_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

CREATE TABLE winder_valor (
    winder_id  INT NOT NULL,
    campo_id   INT NOT NULL,
    valor      TEXT NOT NULL,
    PRIMARY KEY (winder_id, campo_id),
    INDEX (campo_id),
    FOREIGN KEY (winder_id) REFERENCES winder(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE winder_valor_item (
    winder_id  INT NOT NULL,
    campo_id   INT NOT NULL,
    valor      VARCHAR(120) NOT NULL,
    PRIMARY KEY (winder_id, campo_id, valor),
    INDEX (campo_id),
    FOREIGN KEY (winder_id) REFERENCES winder(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE,
    CONSTRAINT ck_winder_valor_item CHECK (TRIM(valor) <> '')
) ENGINE=InnoDB;

CREATE TABLE winder_posicao (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    winder_id  INT NOT NULL,
    nome       VARCHAR(60) NOT NULL,
    ordem      INT NOT NULL DEFAULT 0,
    ligada     TINYINT NOT NULL DEFAULT 1,
    UNIQUE (winder_id, nome),
    FOREIGN KEY (winder_id) REFERENCES winder(id) ON DELETE CASCADE,
    CONSTRAINT ck_winder_posicao_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_winder_posicao_ligada CHECK (ligada IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE winder_posicao_valor (
    posicao_id  INT NOT NULL,
    campo_id    INT NOT NULL,
    valor       TEXT NOT NULL,
    PRIMARY KEY (posicao_id, campo_id),
    INDEX (campo_id),
    FOREIGN KEY (posicao_id) REFERENCES winder_posicao(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE winder_posicao_valor_item (
    posicao_id  INT NOT NULL,
    campo_id    INT NOT NULL,
    valor       VARCHAR(120) NOT NULL,
    PRIMARY KEY (posicao_id, campo_id, valor),
    INDEX (campo_id),
    FOREIGN KEY (posicao_id) REFERENCES winder_posicao(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE,
    CONSTRAINT ck_winder_posicao_valor_item CHECK (TRIM(valor) <> '')
) ENGINE=InnoDB;

CREATE TABLE lancamento_winder (
    lancamento_id  INT PRIMARY KEY,
    posicao_id     INT NOT NULL,
    INDEX (posicao_id),
    FOREIGN KEY (lancamento_id) REFERENCES lancamento(id) ON DELETE CASCADE,
    FOREIGN KEY (posicao_id) REFERENCES winder_posicao(id) ON DELETE CASCADE
) ENGINE=InnoDB;
