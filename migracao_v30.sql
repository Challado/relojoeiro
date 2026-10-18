-- Relógios 2, migração v30: os modos de rodízio com tipo, semanas e roteiro.
-- Cada modo ganha um tipo (a tela dele: um por semana, semana e fim de semana, por dia da semana, aleatório todo dia,
-- escala inteligente ou personalizado), quantas semanas à frente planeja (1 a 8; a escala usa o período dela) e, no
-- personalizado, o roteiro escrito à mão (as cinco linhas que o motor calcula: candidato, nota, peso, dias, garantia).
-- Os modos que existem recebem o tipo pela forma dos blocos (o passo em PHP, migracao_v30_dados).
SET NAMES utf8mb4;

ALTER TABLE modo ADD COLUMN tipo ENUM('semana','semana_fds','dia_semana','aleatorio','escala','personalizado') NOT NULL DEFAULT 'personalizado' AFTER nome;
ALTER TABLE modo ADD COLUMN semanas INT NOT NULL DEFAULT 1 AFTER escala_dias;
ALTER TABLE modo ADD COLUMN roteiro TEXT NULL;
ALTER TABLE modo ADD CONSTRAINT ck_modo_semanas CHECK (semanas BETWEEN 1 AND 8);
