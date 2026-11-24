-- Relógios 2, migração v69: o reparo da v67 que se deu por aplicada antes de terminar. A versão antiga da v67 copiava os
-- dados (o padrão do sistema, a preferência de cada dono e a retomada do rodízio de cada coleção) e depois tirava as regras e
-- as colunas antigas do usuário (rodizio_ativo, rodizio_retomado_em e recupera_ontem) numa leva só; quando a leva parava no
-- meio, a marca de aplicada era gravada mesmo assim, com colunas antigas no banco. Esta migração só aparece onde sobrou
-- alguma dessas colunas (a marca é conferida pela estrutura: migracao_v69_feita) e tira o que sobrou pelo passo em PHP
-- (migracao_v69_dados), sem mexer nas preferências nem nas retomadas já migradas.
SET NAMES utf8mb4;
