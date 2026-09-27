-- Relógios 2, migração v8: a condição "vale quando" nos tipos de lançamento e nos avisos (uma fórmula: 1 vale, 0 ou vazio
-- não vale; a condição vazia vale sempre). Com ela, como no sistema antigo: o automático sem corda pela coroa não tem o botão
-- de corda nem o aviso "Dar corda", e recebe o aviso "Pôr no winder".
SET NAMES utf8mb4;

ALTER TABLE lancamento_tipo ADD COLUMN condicao VARCHAR(500) NULL AFTER mede_gasto;
ALTER TABLE aviso ADD COLUMN condicao VARCHAR(500) NULL AFTER expressao;

-- a corda só para quem aceita corda pela coroa (o campo corda_manual do cadastro)
UPDATE lancamento_tipo SET condicao = 'corda_manual' WHERE identificador = 'corda' AND condicao IS NULL;
UPDATE aviso SET condicao = 'corda_manual' WHERE identificador = 'corda' AND condicao IS NULL;

-- o automático sem corda: pôr no winder quando a reserva acaba (a mesma data do aviso de corda); na escala, simula uma noite
-- (8 horas) no winder para resolver
INSERT INTO aviso (identificador, nome, expressao, condicao, antecedencia_dias, texto, resolve, ativo, escala, simula_valor, simula_horas, agenda, no_id)
    SELECT 'winder', 'Pôr no winder', expressao, 'corda_manual = 0', antecedencia_dias, texto, 'winder', ativo, escala, NULL, 8, agenda, (SELECT id FROM no WHERE id = 4)
    FROM aviso WHERE identificador = 'corda' AND no_id = 3 AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM aviso WHERE identificador = 'winder') x) LIMIT 1;

-- o aviso novo vai pelos mesmos canais que o de corda
UPDATE config SET valor = CONCAT(valor, ',winder') WHERE chave IN ('alerta_tipos', 'agenda_tipos') AND FIND_IN_SET('corda', valor) > 0 AND FIND_IN_SET('winder', valor) = 0;
