-- Relógios 2, migração v7: o ciclo no rodízio, uma opção de cada modo, desligada por padrão (o rodízio segue os critérios).
-- Com o ciclo, um relógio só volta a ser escolhido depois que todos os disponíveis do bloco tiverem passado na semana (na
-- escala, pelo período dela); dentro do ciclo, a forma de escolha do modo decide a ordem.
SET NAMES utf8mb4;

ALTER TABLE modo ADD COLUMN ciclo TINYINT NOT NULL DEFAULT 0 AFTER escala_dias;
