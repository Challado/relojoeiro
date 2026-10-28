-- Relógios 2, v51: a validade do intervalo é independente da escolha de usá-lo.
-- O passo em PHP reconstrói as medições pelas leituras sem mudar as fontes nem reativar exclusões manuais.
SET NAMES utf8mb4;
ALTER TABLE medicao ADD COLUMN valida TINYINT NOT NULL DEFAULT 1;
ALTER TABLE medicao ADD CONSTRAINT ck_medicao_valida CHECK (valida IN (0, 1));
