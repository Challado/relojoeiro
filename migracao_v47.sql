-- Relógios 2, migração v47: os programas dos winders, o lugar fixo e os documentos do winder.
-- 1. Os movimentos (winder_movimento: um giro horário ou anti-horário, em voltas ou em tempo, ou uma parada) e os programas
--    (winder_programa: o tipo de cadastro, por voltas ou por tempo, a velocidade do berço ou as voltas por dia do manual, e
--    se funciona o dia todo ou em blocos), com a sequência dos movimentos (winder_programa_passo). Da coleção, e um serve
--    em quantos lugares se quiser. As voltas por dia são sempre calculadas da sequência.
-- 2. O programa de cada posição (winder_posicao_programa) e o lugar fixo (winder_lugar: de quem é a posição; fora dela, a
--    foto apagada no berço; pôr no winder o devolve para lá).
-- 3. Os avisos de limite dispensados (winder_limite_ok: a caixa ou o peso do relógio passa do que a almofada aguenta, e a
--    pessoa deixa assim).
-- 4. documento.winder_id: o manual, a nota, as fotos do winder (a pasta w<id> dos documentos).
-- O passo em PHP (migracao_v47_dados) converte o programa antigo de cada posição (os campos Rotações por dia, os dois
-- sentidos e o ciclo) em programa, põe o lugar de quem está no winder agora, tira os campos antigos, põe os campos novos do
-- winder (o link do manual e os limites da almofada) e a fórmula winder_ganho nova (pelas voltas no sentido em que o relógio
-- dá corda), e grava a marca. Os CREATE TABLE e o resto deixam rodar de novo, se parar no meio.
SET NAMES utf8mb4;

CREATE TABLE winder_movimento (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id  INT NOT NULL,
    nome        VARCHAR(60) NOT NULL,
    tipo        ENUM('horario','anti_horario','parado') NOT NULL,
    voltas      DECIMAL(10,2) NULL,
    segundos    INT NULL,
    INDEX (colecao_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_winder_movimento_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_winder_movimento_medida CHECK ((voltas IS NULL AND segundos IS NOT NULL AND segundos > 0)
        OR (tipo <> 'parado' AND segundos IS NULL AND voltas IS NOT NULL AND voltas > 0))
) ENGINE=InnoDB;

CREATE TABLE winder_programa (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id  INT NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    medida      ENUM('voltas','tempo') NOT NULL,
    velocidade  DECIMAL(8,3) NULL,
    tpd_manual  INT NULL,
    liga_horas  DECIMAL(6,2) NULL,
    para_horas  DECIMAL(6,2) NULL,
    observacao  VARCHAR(1000) NULL,
    criado      DATETIME NOT NULL,
    INDEX (colecao_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_winder_programa_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_winder_programa_velocidade CHECK ((velocidade IS NULL OR velocidade > 0) AND (tpd_manual IS NULL OR tpd_manual > 0)
        AND (velocidade IS NULL OR tpd_manual IS NULL)),
    CONSTRAINT ck_winder_programa_bloco CHECK ((liga_horas IS NULL AND para_horas IS NULL)
        OR (liga_horas IS NOT NULL AND para_horas IS NOT NULL AND liga_horas > 0 AND para_horas > 0))
) ENGINE=InnoDB;

CREATE TABLE winder_programa_passo (
    programa_id   INT NOT NULL,
    ordem         INT NOT NULL,
    movimento_id  INT NOT NULL,
    PRIMARY KEY (programa_id, ordem),
    INDEX (movimento_id),
    FOREIGN KEY (programa_id) REFERENCES winder_programa(id) ON DELETE CASCADE,
    FOREIGN KEY (movimento_id) REFERENCES winder_movimento(id) ON DELETE CASCADE,
    CONSTRAINT ck_winder_programa_passo_ordem CHECK (ordem > 0)
) ENGINE=InnoDB;

CREATE TABLE winder_posicao_programa (
    posicao_id   INT PRIMARY KEY,
    programa_id  INT NOT NULL,
    INDEX (programa_id),
    FOREIGN KEY (posicao_id) REFERENCES winder_posicao(id) ON DELETE CASCADE,
    FOREIGN KEY (programa_id) REFERENCES winder_programa(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE winder_lugar (
    posicao_id  INT PRIMARY KEY,
    relogio_id  INT NOT NULL,
    UNIQUE (relogio_id),
    FOREIGN KEY (posicao_id) REFERENCES winder_posicao(id) ON DELETE CASCADE,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE documento ADD COLUMN winder_id INT NULL AFTER calibre_id;
ALTER TABLE documento ADD INDEX documento_winder (winder_id);
ALTER TABLE documento ALTER FOREIGN KEY (winder_id) REFERENCES winder(id) ON DELETE CASCADE;

CREATE TABLE winder_limite_ok (
    winder_id   INT NOT NULL,
    relogio_id  INT NOT NULL,
    PRIMARY KEY (winder_id, relogio_id),
    INDEX (relogio_id),
    FOREIGN KEY (winder_id) REFERENCES winder(id) ON DELETE CASCADE,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;
