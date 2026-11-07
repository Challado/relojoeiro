-- Relógios 2, migração v59: o menos usado e a escala que escolhe o que usa.
-- 1. A forma de escolha "menos usado" (modo.selecao): vai o relógio com menos dias de uso no total (empate: o mais tempo sem uso).
-- 2. Os critérios que a nota de um modo usa (modo_criterio): o nome de cada parâmetro dos critérios; sem linha, todos (como era).
-- 3. A escala inteligente passa a escolher a forma (antes valia sempre a maior nota): as que já existiam ficam como estavam.
-- O passo em PHP põe a fórmula menos_usado em cada árvore e o parâmetro "Menos usado" (com peso 0: a nota de hoje não muda até
-- você dar peso a ele) em cada conjunto de critérios.
SET NAMES utf8mb4;

ALTER TABLE modo MODIFY COLUMN selecao ENUM('inteligente','ponderado','aleatorio','fifo','menos_usado') NOT NULL DEFAULT 'ponderado';

CREATE TABLE modo_criterio (
    modo_id  INT NOT NULL,
    nome     VARCHAR(80) NOT NULL,
    PRIMARY KEY (modo_id, nome),
    FOREIGN KEY (modo_id) REFERENCES modo(id) ON DELETE CASCADE,
    CONSTRAINT ck_modo_criterio_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

UPDATE modo SET selecao = 'inteligente' WHERE tipo = 'escala';
