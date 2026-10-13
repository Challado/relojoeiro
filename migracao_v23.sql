-- Relógios 2, migração v23: o aviso "Encostado": um relógio da coleção que passou do limite de dias sem ir ao pulso (a
-- Configuração, "Aviso Encostado: dias sem ir ao pulso", encostado_dias, padrão 30; a conta parte da última vez no pulso,
-- sem nunca ter ido, da data da compra). Resolve com o Pôs no pulso. Como todo aviso, só vale para o relógio disponível
-- (os de fora da coleção não recebem) e se desliga em Cadastros › Avisos.
SET NAMES utf8mb4;

INSERT INTO aviso (identificador, nome, expressao, condicao, antecedencia_dias, texto, resolve_tipo_id, ativo, escala, simula_valor, simula_horas, agenda, na_hora, no_id)
    SELECT 'encostado', 'Encostado', 'PADRAO(ULTIMA_DATA("pulso"); data_compra) + CONFIG("encostado_dias")', NULL, 0,
        'o limite de dias sem ir ao pulso vence {quando}, {data}', (SELECT id FROM lancamento_tipo WHERE identificador = 'pulso'), 1, 'nao', NULL, NULL, 'janela', 0, NULL
    FROM (SELECT COUNT(*) AS n FROM aviso WHERE identificador = 'encostado') x WHERE x.n = 0;

-- o limite (e a marca de aplicada, por último: se parar no meio, continua pendente)
INSERT IGNORE INTO config (chave, valor) VALUES ('encostado_dias', '30');
