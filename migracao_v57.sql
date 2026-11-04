-- Relógios 2, migração v57: passar o relógio de ontem para hoje, em três níveis, sistema → dono → coleção.
-- Ligada, a opção faz o relógio do plano de ontem que não foi ao pulso nem um pouquinho passar para hoje, e os outros dias do
-- plano andarem um dia. Toda conta começa herdando o sistema (desligado); a preferência do dono vale em todas as suas coleções
-- que a herdam, e a escolha explícita de cada coleção continua prevalecendo.
SET NAMES utf8mb4;

ALTER TABLE usuario ADD COLUMN recupera_ontem TINYINT NULL;
ALTER TABLE usuario ADD CONSTRAINT ck_usuario_recupera_ontem CHECK (recupera_ontem IS NULL OR recupera_ontem IN (0, 1));
