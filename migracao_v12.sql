-- Relógios 2, migração v12: os dias sem uso de um relógio que nunca foi ao pulso contam desde a compra. Antes valiam 9999
-- para todos os nunca usados: empatavam entre si na garantia de rodízio (saía o cadastrado primeiro), e um relógio comprado
-- ontem já passava na frente de todos, como se estivesse parado há 27 anos. Sem data de compra, continua 9999.
SET NAMES utf8mb4;

-- só na fórmula que ainda está como veio
UPDATE formula SET expressao = 'PADRAO(DIAS_DESDE_ULTIMO("pulso"); PADRAO(DIAS_DESDE(data_compra); 9999))'
    WHERE identificador = 'dias_sem_uso' AND expressao = 'PADRAO(DIAS_DESDE_ULTIMO("pulso"); 9999)';

-- a marca de que a v12 foi aplicada: por último, para uma v12 que parou no meio continuar pendente
INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v12', '1');
