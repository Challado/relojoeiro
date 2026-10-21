-- Relógios 2, migração v38: o modo da interface de cada pessoa (Minha conta). Básico: o dia a dia (cadastrar o relógio,
-- marcar o uso, acompanhar a manutenção e escolher um rodízio pronto); avançado: também as fórmulas, os roteiros, os
-- critérios, as simulações e os totais. O motor é o mesmo nos dois: muda só o que as telas mostram (a API diz o que fica
-- de fora no básico). Quem já tem conta continua no avançado; a conta criada pelo cadastro aberto começa no básico.
SET NAMES utf8mb4;

ALTER TABLE usuario ADD COLUMN nivel VARCHAR(10) NOT NULL DEFAULT 'avancado' AFTER fuso;
ALTER TABLE usuario ADD CONSTRAINT ck_usuario_nivel CHECK (nivel IN ('basico', 'avancado'));
