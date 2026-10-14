-- Relógios 2, migração v9: o limite de carga, geral e por relógio. O aviso para carregar (e o de pôr no sol, e o de dar corda
-- ou pôr no winder) sai quando a carga chega ao limite, e não mais pela previsão de um dia inteiro no pulso a partir de agora
-- (que avisava para carregar um smartwatch guardado com 89%). O limite: o campo carga_minima do relógio; vazio, o geral do tipo
-- (smartwatch: carga_limiar da Configuração; solar: sol_limiar; mecânico: 0, a reserva acabando).
SET NAMES utf8mb4;

-- o limite de cada relógio, no cadastro (vazio: o geral)
INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem) VALUES
    ('carga_minima', 'Avisar quando a carga chegar a (vazio: o geral)', 'inteiro', '%', NULL, NULL, 7);

-- o geral, na Configuração
INSERT IGNORE INTO config (chave, valor) VALUES ('carga_limiar', '20');

-- o limite que vale: o do relógio, senão o geral do tipo
INSERT INTO formula (identificador, nome, expressao, unidade, no_id) VALUES
    ('limite_carga', 'Limite de carga: o do relógio, senão o geral da Configuração', 'PADRAO(carga_minima; CONFIG("carga_limiar"))', '%', NULL);
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'limite_carga', 'Limite de carga: o do relógio, senão o do solar na Configuração', 'PADRAO(carga_minima; CONFIG("sol_limiar"))', '%', id FROM no WHERE id = 8;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'limite_carga', 'Limite de carga: o do relógio, senão 0 (a reserva acabando)', 'PADRAO(carga_minima; 0)', '%', id FROM no WHERE id = 3;

-- carregar: quando a carga chega ao limite (sem antecedência). Só nos avisos que ainda estão como vieram
UPDATE aviso SET expressao = 'AGORA() + (energia - limite_carga) / taxa_uso' WHERE identificador = 'carregar' AND expressao = 'AGORA() + (energia - 20) / taxa_uso';
UPDATE aviso SET antecedencia_dias = 0 WHERE identificador = 'carregar' AND antecedencia_dias = 1 AND expressao = 'AGORA() + (energia - limite_carga) / taxa_uso';
UPDATE aviso SET texto = 'chega a {limite}% {quando}, {data}' WHERE identificador = 'carregar' AND texto = 'chega a 20% {quando}, {data}';

-- carga baixa guardado: com o carregar no limite, é o mesmo aviso duas vezes; fica desligado (e no mesmo limite, se religar)
UPDATE aviso SET expressao = 'AGORA() + (energia - limite_carga) / taxa_repouso', texto = 'chega a {limite}% guardado {quando}, {data}', ativo = 0
    WHERE identificador = 'carga_baixa' AND expressao = 'AGORA() + (energia - 20) / taxa_repouso';

-- pôr no sol: quando a luz chega ao limite (sem antecedência)
UPDATE aviso SET expressao = 'AGORA() + (energia - limite_carga) / (100 / reserva_dias)'
    WHERE identificador = 'sol' AND expressao = 'AGORA() + (energia - CONFIG("sol_limiar")) / (100 / reserva_dias)';
UPDATE aviso SET antecedencia_dias = 0 WHERE identificador = 'sol' AND antecedencia_dias = 7 AND expressao = 'AGORA() + (energia - limite_carga) / (100 / reserva_dias)';
UPDATE aviso SET texto = 'a luz chega a {limite}% {quando}, {data}' WHERE identificador = 'sol' AND texto = 'a luz chega ao limite {quando}, {data}';

-- dar corda e pôr no winder: quando a reserva chega ao limite (no geral, 0: a reserva acabando, como antes; a antecedência
-- de meio dia continua, para dar tempo antes de parar)
UPDATE aviso SET expressao = 'AGORA() + autonomia_restante / 86400 - limite_carga / 100 * reserva_horas / 24'
    WHERE identificador IN ('corda', 'winder') AND expressao = 'AGORA() + autonomia_restante / 86400';
UPDATE aviso SET texto = 'a reserva chega a {limite}% {quando}, {data}' WHERE identificador IN ('corda', 'winder') AND texto = 'a reserva acaba {quando}, {data}';
