-- Relógios 2, migração v56: rodízio em três níveis, sistema → dono → coleção.
-- Toda conta começa herdando o sistema. A preferência do dono vale em todas as suas coleções que a herdam,
-- inclusive pelo cron e pelos membros; a escolha explícita de cada coleção continua prevalecendo.
SET NAMES utf8mb4;

ALTER TABLE usuario ADD COLUMN rodizio_ativo TINYINT NULL;
ALTER TABLE usuario ADD COLUMN rodizio_retomado_em DATETIME NULL;
ALTER TABLE usuario ADD CONSTRAINT ck_usuario_rodizio CHECK (rodizio_ativo IS NULL OR rodizio_ativo IN (0, 1));
