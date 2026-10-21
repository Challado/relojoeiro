-- Relógios 2, migração v39: o timegrapher. Cada medição de um timegrapher de bancada é uma linha: o relógio, quando, a
-- posição (a do aparelho: mostrador para cima ou para baixo, a coroa para cima, para baixo, para a esquerda ou para a
-- direita) e as grandezas que ele mostra: a marcha (s/dia), a amplitude (graus), o erro de batida (ms), as batidas por hora
-- e o ângulo de levantamento (graus). Digitada na mão ou importada do CSV do aparelho (origem). A sessão é o dia: as
-- medições do mesmo dia, uma por posição, dão a média e a diferença entre as posições.
SET NAMES utf8mb4;

CREATE TABLE timegrapher (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id   INT NOT NULL,
    quando       DATETIME NOT NULL,
    posicao      VARCHAR(16) NULL,
    marcha       DECIMAL(6,1) NULL,
    amplitude    DECIMAL(5,1) NULL,
    erro_batida  DECIMAL(5,2) NULL,
    bph          INT NULL,
    angulo       DECIMAL(4,1) NULL,
    origem       VARCHAR(8) NOT NULL DEFAULT 'manual',
    observacao   VARCHAR(500) NULL,
    criado       DATETIME NOT NULL,
    INDEX (relogio_id, quando),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    CONSTRAINT ck_timegrapher_posicao CHECK (posicao IS NULL OR posicao IN ('mostrador_cima', 'mostrador_baixo', 'coroa_cima', 'coroa_baixo', 'coroa_esquerda', 'coroa_direita')),
    CONSTRAINT ck_timegrapher_origem CHECK (origem IN ('manual', 'csv')),
    CONSTRAINT ck_timegrapher_algo CHECK (marcha IS NOT NULL OR amplitude IS NOT NULL OR erro_batida IS NOT NULL),
    CONSTRAINT ck_timegrapher_marcha CHECK (marcha IS NULL OR (marcha >= -999 AND marcha <= 999)),
    CONSTRAINT ck_timegrapher_amplitude CHECK (amplitude IS NULL OR (amplitude >= 0 AND amplitude <= 360)),
    CONSTRAINT ck_timegrapher_erro CHECK (erro_batida IS NULL OR (erro_batida >= 0 AND erro_batida <= 99)),
    CONSTRAINT ck_timegrapher_bph CHECK (bph IS NULL OR (bph >= 3600 AND bph <= 72000)),
    CONSTRAINT ck_timegrapher_angulo CHECK (angulo IS NULL OR (angulo >= 10 AND angulo <= 90))
) ENGINE=InnoDB;
