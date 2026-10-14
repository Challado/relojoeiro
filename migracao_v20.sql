-- Relógios 2, migração v20: o smartwatch carrega no carregador, o aviso de tirar do carregador sai na hora, e o custo por uso.
--   1. No carregador, a bateria sobe pelo campo novo "No carregador: horas para encher, de vazio" (padrão 2 h): a fórmula da
--      energia do smartwatch passa a percorrer o histórico (ACUMULA): a leitura de carga vale como está, no pulso perde o
--      gasto em uso, no carregador ganha, guardado perde o gasto guardado.
--   2. O aviso "Tirar do carregador": vence quando a bateria enche no carregador. Avisos podem sair "na hora" pelo Telegram
--      (a coluna nova na_hora: o cron manda no minuto em que vencem, uma vez), e este já vem assim.
--   3. As fórmulas "Dias de uso" e "Custo por uso" (o valor pago dividido pelos dias que o relógio foi ao pulso).
--   4. No MySQL/MariaDB, as tabelas com outra collation passam para utf8mb4_unicode_ci (o passo em PHP desta migração).
SET NAMES utf8mb4;

-- 1. o campo, onde vale a leitura de carga (o smartwatch); se já existir, fica como está
INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem)
    SELECT 'carga_carregador_horas', 'No carregador: horas para encher, de vazio', 'decimal', 'h', '2', t.no_id, 15 FROM lancamento_tipo t
    WHERE t.identificador = 'carga' AND NOT EXISTS (SELECT 1 FROM campo x WHERE x.identificador = 'carga_carregador_horas');

-- a energia do smartwatch, só na fórmula que ainda está como veio (a da v6); sem nenhuma leitura de carga: desconhecida
UPDATE formula SET expressao = 'SE(VAZIO(ULTIMO_VALOR("carga")); NADA(); ACUMULA(100; 100; taxa_repouso / 24; "carga"; 1; "pulso"; 0 - taxa_uso / HORAS_USO(); "carregador"; 100 / PADRAO(carga_carregador_horas; 2)))'
    WHERE identificador = 'energia' AND expressao = 'LIMITA(ULTIMO_VALOR("carga") - HORAS_APOS("pulso"; "carga") / HORAS_USO() * taxa_uso - (DIAS_DESDE_ULTIMO("carga") - HORAS_APOS("pulso"; "carga") / 24) * taxa_repouso; 0; 100)'
    AND EXISTS (SELECT 1 FROM campo x WHERE x.identificador = 'carga_carregador_horas')
    AND EXISTS (SELECT 1 FROM lancamento_tipo x WHERE x.identificador = 'carregador');

-- 2. os avisos que saem na hora pelo Telegram
ALTER TABLE aviso ADD COLUMN na_hora TINYINT NOT NULL DEFAULT 0 AFTER agenda;
ALTER TABLE aviso ADD CONSTRAINT ck_aviso_na_hora CHECK (na_hora IN (0, 1));

-- o aviso de tirar do carregador, onde vale o aviso de carregar: vence quando a bateria enche; resolve tirando do carregador
INSERT INTO aviso (identificador, nome, expressao, condicao, antecedencia_dias, texto, resolve_tipo_id, ativo, escala, simula_valor, simula_horas, agenda, na_hora, no_id)
    SELECT 'carregado', 'Tirar do carregador', 'SE(EM_SESSAO("carregador"); AGORA() + (100 - energia) / (100 / PADRAO(carga_carregador_horas; 2)) / 24; NADA())', NULL, 0,
        'bateria cheia {quando}, {data}', (SELECT id FROM lancamento_tipo WHERE identificador = 'carregador'), 1, 'nao', NULL, NULL, 'janela', 1, a.no_id
    FROM aviso a WHERE a.identificador = 'carregar' AND EXISTS (SELECT 1 FROM lancamento_tipo x WHERE x.identificador = 'carregador')
    AND EXISTS (SELECT 1 FROM campo x WHERE x.identificador = 'carga_carregador_horas')
    AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM aviso WHERE identificador = 'carregado') y) LIMIT 1;

-- ele vai pelo Telegram se o de carregar vai
INSERT INTO canal_aviso (canal, tipo, envia, propria, corpo)
    SELECT 'tg', 'carregado', c.envia, 0, NULL FROM canal_aviso c WHERE c.canal = 'tg' AND c.tipo = 'carregar'
    AND EXISTS (SELECT 1 FROM aviso x WHERE x.identificador = 'carregado')
    AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM canal_aviso WHERE canal = 'tg' AND tipo = 'carregado') y);

-- 3. os dias de uso (dias com o relógio no pulso, também as marcações antigas) e o custo por uso, para todos os relógios
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'dias_de_uso', 'Dias de uso: os dias em que ele foi ao pulso', 'CONTAR("pulso"; 36500) + CONTAR("pulso_antigo"; 36500)', 'dias', NULL
    FROM config WHERE chave = 'horario_manha' AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM formula WHERE identificador = 'dias_de_uso') y);
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'custo_por_uso', 'Custo por uso: o valor pago dividido pelos dias de uso', 'SE(E(PADRAO(valor_compra; 0) > 0; dias_de_uso > 0); valor_compra / dias_de_uso; NADA())', 'R$', NULL
    FROM config WHERE chave = 'horario_manha' AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM formula WHERE identificador = 'custo_por_uso') y);

-- a marca de aplicada fica no passo em PHP (migracao_v20_dados, no lib.php), depois da collation do MySQL
