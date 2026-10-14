-- Relógios 2, migração v5: as telas e as mensagens do sistema antigo. Os canais (Telegram e Google Agenda) com a mensagem
-- padrão de cada um e a personalizada de cada aviso ("O que vai para onde"), os eventos personalizados dentro deles, os
-- avisos que o antigo tinha e o novo não (carga baixa, informar a carga), o limite do solar e a sessão no sol esquecida na
-- Configuração, e o registro completo das execuções do cron. Os seus dados não mudam; os textos dos avisos só mudam onde
-- ainda estão como vieram.
SET NAMES utf8mb4;

-- o cron: toda execução fica registrada, com a duração e se teve atividade (sem atividade: 7 dias; com: 1 ano)
ALTER TABLE cron_execucao ADD COLUMN duracao_ms INT NOT NULL DEFAULT 0 AFTER fim;
ALTER TABLE cron_execucao ADD COLUMN teve_atividade TINYINT NOT NULL DEFAULT 1 AFTER duracao_ms;

-- os números da Configuração que as fórmulas usam: CONFIG("sol_limiar")
INSERT IGNORE INTO config (chave, valor) VALUES ('sol_limiar', '70'), ('cron_ultima_execucao', ''), ('cron_erro', ''), ('cron_registro', '');
UPDATE aviso SET expressao = 'AGORA() + (energia - CONFIG("sol_limiar")) / (100 / reserva_dias)'
    WHERE identificador = 'sol' AND expressao = 'AGORA() + (energia - 70) / (100 / reserva_dias)';
UPDATE formula SET expressao = 'SE(E(VAZIO(ULTIMA_DATA("sol")); VAZIO(ULTIMA_DATA("sol_antigo"))); NADA(); ACUMULA(CONFIG("sol_limiar"); 100; 100 / (reserva_dias * 24); "sol"; 100 / carga_sol_horas; "pulso"; 0; "sol_antigo"; 100))'
    WHERE identificador = 'energia' AND expressao = 'SE(E(VAZIO(ULTIMA_DATA("sol")); VAZIO(ULTIMA_DATA("sol_antigo"))); NADA(); ACUMULA(70; 100; 100 / (reserva_dias * 24); "sol"; 100 / carga_sol_horas; "pulso"; 0; "sol_antigo"; 100))';

-- o limite da energia nas faixas dos critérios (a última faixa vai até 100), como no sistema antigo: o nome diz "(0 a 100)"
UPDATE formula SET nome = CONCAT(nome, ' (0 a 100)') WHERE identificador = 'energia' AND nome NOT LIKE '%(0 a %';

-- o texto de cada aviso passa a ser o motivo ({motivo} nas mensagens): "Dar corda: San Martin (a reserva acaba em 3h)"
UPDATE aviso SET texto = 'vence {quando}, {data}' WHERE identificador = 'garantia' AND texto = 'A garantia do {relogio} vence {quando} ({data}).';
UPDATE aviso SET texto = 'chega a 20% {quando}, {data}' WHERE identificador = 'carregar' AND texto = 'Carregar o {relogio}: chega a 20% {quando} ({data}).';
UPDATE aviso SET texto = 'a reserva acaba {quando}, {data}' WHERE identificador = 'corda' AND texto = 'Dar corda no {relogio}: a reserva acaba {quando} ({data}).';
UPDATE aviso SET texto = 'a luz chega ao limite {quando}, {data}' WHERE identificador = 'sol' AND texto = 'Pôr o {relogio} no sol: a luz chega a 70% {quando} ({data}).';
UPDATE aviso SET texto = 'vence {quando}, {data}' WHERE identificador = 'pilha' AND texto = 'Trocar a pilha do {relogio}: vence {quando} ({data}).';
UPDATE aviso SET texto = 'vence {quando}, {data}' WHERE identificador = 'revisao' AND texto = 'Revisão do {relogio}: {quando} ({data}).';

-- os avisos do antigo que faltavam: carga baixa em relógio guardado, e informar a carga (leitura antiga ou ausente)
INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, resolve, no_id)
    SELECT 'carga_baixa', 'Carga baixa', 'AGORA() + (energia - 20) / decaimento_repouso', 0, 'chega a 20% guardado {quando}, {data}', 'carga', id FROM no WHERE id = 1
    AND NOT EXISTS (SELECT 1 FROM aviso WHERE identificador = 'carga_baixa');
INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, resolve, no_id)
    SELECT 'leitura', 'Informar a carga', 'PADRAO(ULTIMA_DATA("carga"); AGORA() - 3) + 3', 0, '3 dias sem leitura {quando}, {data}', 'carga', id FROM no WHERE id = 1
    AND NOT EXISTS (SELECT 1 FROM aviso WHERE identificador = 'leitura');

-- os canais: a mensagem padrão de cada um, os avisos que vão por ele, e a personalizada do relógio do dia e da véspera
INSERT IGNORE INTO config (chave, valor) VALUES
    ('tg_padrao', '{acao}: {relogio}\n{motivo}'),
    ('tg_proprio_dia', '1'), ('tg_corpo_dia', 'Hoje é o dia do {relogio}, {ate}. Carga: {carga}.'),
    ('tg_proprio_vespera', '1'), ('tg_corpo_vespera', 'Amanhã é o dia do {relogio}, {ate}.\n{motivo}');
INSERT IGNORE INTO config (chave, valor)
    SELECT 'ag_padrao', COALESCE((SELECT valor FROM config WHERE chave = 'agenda_modelo'),
        '{acao}: {relogio}\n{motivo}\n\n{relogio} (código {codigo}, {tipo}) · {estado} · carga {carga}\n{link}');
INSERT IGNORE INTO config (chave, valor)
    SELECT 'alerta_tipos', CONCAT('dia,vespera,garantia,carregar,carga_baixa,leitura,corda,sol,pilha,revisao',
        COALESCE((SELECT CONCAT(',', GROUP_CONCAT(CONCAT('ev', id) ORDER BY id)) FROM evento_personalizado WHERE telegram = 1), ''));
INSERT IGNORE INTO config (chave, valor)
    SELECT 'agenda_tipos', CONCAT_WS(',',
        IF((SELECT valor FROM config WHERE chave = 'agenda_dia') = '1', 'dia', NULL),
        IF((SELECT valor FROM config WHERE chave = 'agenda_vespera') = '1', 'vespera', NULL),
        (SELECT GROUP_CONCAT(DISTINCT identificador ORDER BY identificador) FROM aviso WHERE agenda <> 'nao'),
        (SELECT GROUP_CONCAT(CONCAT('ev', id) ORDER BY id) FROM evento_personalizado WHERE agenda = 1));

-- o texto que um evento personalizado tinha vira a personalizada dele no Telegram; os canais de cada evento ficam nas
-- listas acima, e o texto e as caixas saem do evento (no antigo, o evento só tem quando dispara)
INSERT IGNORE INTO config (chave, valor) SELECT CONCAT('tg_proprio_ev', id), '1' FROM evento_personalizado WHERE COALESCE(texto, '') <> '';
INSERT IGNORE INTO config (chave, valor) SELECT CONCAT('tg_corpo_ev', id), texto FROM evento_personalizado WHERE COALESCE(texto, '') <> '';
ALTER TABLE evento_personalizado DROP COLUMN texto;
ALTER TABLE evento_personalizado DROP COLUMN telegram;
ALTER TABLE evento_personalizado DROP COLUMN agenda;
DELETE FROM config WHERE chave IN ('agenda_modelo', 'agenda_dia', 'agenda_vespera');

-- na agenda, cada aviso tem só a regra da data: dentro da antecedência da agenda (a rotina: carregar, corda, sol) ou
-- sempre (a manutenção: pilha, revisão); se ele vai ou não para a agenda, é a tabela "O que vai para onde"
UPDATE aviso SET agenda = 'janela' WHERE agenda = 'nao';
ALTER TABLE aviso MODIFY COLUMN agenda ENUM('janela','sempre') NOT NULL DEFAULT 'janela';
