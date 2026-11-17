-- Relógios 2, migração v65: o relógio que fica fora do sorteio do rodízio.
-- O "Disponível para o rodízio" do cadastro tira o relógio de tudo: do sorteio, da carga, dos avisos de "Hoje é dia de", dos
-- alertas, do "trocar por…" e do "Usando hoje". Faltava o meio-termo: o relógio que continua na coleção, sendo usado e
-- cuidado, mas que o rodízio nunca escolhe sozinho (o de ocasião, o que você só põe quando quer). A marca nova, sorteio, vale
-- só para o sorteio (o plano, a escala, o "Decida por mim", o "Sortear este dia por" e a Sugestão para agora); ele ainda pode
-- ser escolhido à mão. Todo relógio que já existe continua entrando no sorteio (1).
SET NAMES utf8mb4;

ALTER TABLE relogio ADD COLUMN sorteio TINYINT NOT NULL DEFAULT 1;
ALTER TABLE relogio ADD CONSTRAINT ck_relogio_sorteio CHECK (sorteio IN (0, 1));
