-- Relógios 2, migração v36: a reserva medida.
-- Cada teste da reserva de marcha de um relógio mecânico é uma linha: quando ele ficou cheio (inicio), por qual protocolo
-- (a corda pela coroa, com quantas voltas; o winder ou o pulso, com quantas horas; outro jeito) e quando ele parou (fim;
-- vazio: o teste está em andamento). O tempo de cheio até parado é a reserva medida; a mediana dos testes é a estimativa
-- do relógio, com a confiança (quantos testes, a idade do último, quanto eles variam), sem mexer no valor do calibre.
-- As observações da energia (o solar e também o mecânico) ficam na mesma tabela, sem fim e sem protocolo: parou, o
-- indicador de carga baixa ou outra nota. No solar não há calibração: a recarga depende da luz, que o sistema não mede.
SET NAMES utf8mb4;

CREATE TABLE reserva_teste (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id       INT NOT NULL,
    tipo             VARCHAR(12) NOT NULL DEFAULT 'teste',
    inicio           DATETIME NOT NULL,
    fim              DATETIME NULL,
    protocolo        VARCHAR(10) NULL,
    protocolo_valor  DECIMAL(8,2) NULL,
    observacao       VARCHAR(500) NULL,
    criado           DATETIME NOT NULL,
    INDEX (relogio_id, inicio),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    CONSTRAINT ck_reserva_teste_tipo CHECK (tipo IN ('teste', 'parou', 'carga_baixa', 'nota')),
    CONSTRAINT ck_reserva_teste_protocolo CHECK (protocolo IS NULL OR protocolo IN ('corda', 'winder', 'pulso', 'outro')),
    CONSTRAINT ck_reserva_teste_fim CHECK (fim IS NULL OR fim > inicio),
    CONSTRAINT ck_reserva_teste_observacao CHECK (tipo = 'teste' OR (fim IS NULL AND protocolo IS NULL AND protocolo_valor IS NULL)),
    CONSTRAINT ck_reserva_teste_valor CHECK (protocolo_valor IS NULL OR protocolo_valor > 0)
) ENGINE=InnoDB;
