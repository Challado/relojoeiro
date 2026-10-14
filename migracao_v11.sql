-- Relógios 2, migração v11: o motivo de cada escolha do plano. Cada dia guarda, numa frase, por que aquele relógio saiu:
-- o relógio fixo do bloco, o mesmo da semana no bloco, a garantia de rodízio (parado além do limite), a maior nota, o
-- sorteio pela nota (com a chance que ele tinha), a fila, o sorteio simples, ou a escolha à mão. Os dias que já estão no
-- plano ficam sem motivo até serem sorteados de novo.
SET NAMES utf8mb4;

ALTER TABLE plano ADD COLUMN motivo VARCHAR(300) NULL AFTER acao;
