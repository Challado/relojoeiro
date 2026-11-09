-- Relógios 2, migração v62: as colunas de sempre da tabela dos relógios passam a morar no cadastro de colunas.
-- Até a v61 só as colunas que a pessoa montava estavam na tabela coluna; as 14 de sempre (código, relógio, marca, tipo, estado, carga,
-- última vez usado, tempo de uso, próxima manutenção, em, o que fazer, encostado, comprado em e valor) eram uma lista no código. Agora toda
-- coluna é uma linha do cadastro: as de sempre são colunas do sistema (sistema = 1: só mudam de lugar e de aparecer de início; a Relógio
-- é fixa) e vêm do modelo.json, como os totais. O passo em PHP cria as 14 em cada árvore que já existia (as montadas vão para depois).
SET NAMES utf8mb4;

ALTER TABLE coluna ADD COLUMN sistema TINYINT NOT NULL DEFAULT 0;
ALTER TABLE coluna ADD COLUMN fixa TINYINT NOT NULL DEFAULT 0;
ALTER TABLE coluna ADD CONSTRAINT ck_coluna_sistema CHECK (sistema IN (0, 1) AND fixa IN (0, 1));
