-- Relógios 2, migração v19: o solar carrega também no pulso. Antes, no pulso a carga de luz ficava parada (a luz do dia só
-- repunha o que ele gastava); agora ganha uma parte do que ganharia no sol direto, pelo campo novo "No pulso: quanto
-- carrega, comparado ao sol direto" (padrão 25%: com o sol enchendo em 10 h, 10% por hora no sol e 2,5% por hora no pulso).
-- 0 volta ao de antes. Cada relógio pode ter o seu valor, no cadastro.
SET NAMES utf8mb4;

-- o campo, onde vale o "Sol direto para encher", logo depois dele; se já existir (criado à mão), fica como está
INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem)
    SELECT 'luz_pulso', 'No pulso: quanto carrega, comparado ao sol direto', 'decimal', '%', '25', c.no_id, c.ordem + 1 FROM campo c
    WHERE c.identificador = 'carga_sol_horas' AND NOT EXISTS (SELECT 1 FROM campo x WHERE x.identificador = 'luz_pulso');

-- a carga de luz: no pulso, essa parte do ganho do sol por hora (só na fórmula que ainda está como veio)
UPDATE formula SET expressao = 'SE(E(VAZIO(ULTIMA_DATA("sol")); VAZIO(ULTIMA_DATA("sol_antigo"))); NADA(); ACUMULA(CONFIG("sol_limiar"); 100; 100 / (reserva_dias * 24); "sol"; 100 / carga_sol_horas; "pulso"; PADRAO(luz_pulso; 0) / carga_sol_horas; "sol_antigo"; 100))'
    WHERE identificador = 'energia' AND expressao = 'SE(E(VAZIO(ULTIMA_DATA("sol")); VAZIO(ULTIMA_DATA("sol_antigo"))); NADA(); ACUMULA(CONFIG("sol_limiar"); 100; 100 / (reserva_dias * 24); "sol"; 100 / carga_sol_horas; "pulso"; 0; "sol_antigo"; 100))'
    AND EXISTS (SELECT 1 FROM campo x WHERE x.identificador = 'luz_pulso');

-- a marca de aplicada, por último (se parar no meio, continua pendente)
INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v19', '1');
