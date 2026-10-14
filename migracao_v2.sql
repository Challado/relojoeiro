-- Relógios 2, migração v2: lançamentos pela API, com sessões exclusivas (o relógio num lugar só), avisos cadastráveis e a autonomia restante.
-- Aplique pela API (POST recurso=migracoes, acao=aplicar) ou: mysql -u root -p relogios2 < migracao_v2.sql
SET NAMES utf8mb4;

-- sessões exclusivas: o relógio fica num lugar só (abrir uma fecha a outra exclusiva aberta; períodos não se sobrepõem)
ALTER TABLE lancamento_tipo ADD COLUMN exclusiva TINYINT NOT NULL DEFAULT 0 AFTER fecha_as;
UPDATE lancamento_tipo SET exclusiva = 1 WHERE identificador IN ('pulso', 'winder', 'sol');

-- autonomia_restante: o tempo, em segundos, até o relógio se esgotar completamente, no regime em que cada tipo se esgota
-- (o smartwatch usando; o mecânico parado, sem uso nem corda; o solar guardado no escuro; a pilha até o fim da vida)
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_restante', 'Autonomia restante do smartwatch, usando', 'energia / PADRAO(decaimento_uso; 100 / autonomia_dias) * 86400', 's', id FROM no WHERE id = 1;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_restante', 'Autonomia restante do mecânico, parado', 'reserva_restante * 3600', 's', id FROM no WHERE id = 3;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_restante', 'Autonomia restante do solar, no escuro', 'energia / (100 / (reserva_dias * 24)) * 3600', 's', id FROM no WHERE id = 8;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_restante', 'Autonomia restante da pilha', 'MAX(0; DIAS_ATE(SOMA_MESES(MAX(ULTIMA_DATA("pilha"); data_pilha); vida_pilha_meses))) * 86400', 's', id FROM no WHERE id = 7;

-- avisos: a data prevista é uma fórmula; a mesma chave pode ter uma versão por ponto da árvore (vale a do mais perto)
CREATE TABLE aviso (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    identificador      VARCHAR(40) NOT NULL,
    nome               VARCHAR(120) NOT NULL,
    expressao          TEXT NOT NULL,                 -- a data prevista (em dias, como as datas das fórmulas)
    antecedencia_dias  DECIMAL(8,2) NOT NULL DEFAULT 0,
    texto              VARCHAR(300) NOT NULL,         -- com {relogio}, {data} e {quando}
    resolve            VARCHAR(40) NULL,              -- o tipo de lançamento que resolve (identificador)
    ativo              TINYINT NOT NULL DEFAULT 1,
    no_id              INT NULL,
    FOREIGN KEY (no_id) REFERENCES no(id),
    INDEX (identificador)
) ENGINE=InnoDB;

INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, resolve, no_id) VALUES
    ('garantia', 'Garantia vencendo', 'garantia_ate', 30, 'A garantia do {relogio} vence {quando} ({data}).', NULL, NULL);
INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, resolve, no_id)
    SELECT 'carregar', 'Carregar', 'AGORA() + (energia - 20) / PADRAO(decaimento_uso; 100 / autonomia_dias)', 1, 'Carregar o {relogio}: chega a 20% {quando} ({data}).', 'carga', id FROM no WHERE id = 1;
INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, resolve, no_id)
    SELECT 'corda', 'Dar corda', 'AGORA() + autonomia_restante / 86400', 0.5, 'Dar corda no {relogio}: a reserva acaba {quando} ({data}).', 'corda', id FROM no WHERE id = 3;
INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, resolve, no_id)
    SELECT 'sol', 'Pôr no sol', 'AGORA() + (energia - 70) / (100 / reserva_dias)', 7, 'Pôr o {relogio} no sol: a luz chega a 70% {quando} ({data}).', 'sol', id FROM no WHERE id = 8;
INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, resolve, no_id)
    SELECT 'pilha', 'Trocar a pilha', 'SOMA_MESES(MAX(ULTIMA_DATA("pilha"); data_pilha); vida_pilha_meses)', 30, 'Trocar a pilha do {relogio}: vence {quando} ({data}).', 'pilha', id FROM no WHERE id = 7;
INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, resolve, no_id)
    SELECT 'revisao', 'Revisão', 'SOMA_MESES(MAX(ULTIMA_DATA("revisao"); data_revisao); intervalo_revisao_meses)', 30, 'Revisão do {relogio}: {quando} ({data}).', 'revisao', id FROM no WHERE id = 2;

-- a troca de pilha e a revisão lançadas também contam (vale a mais recente: a do lançamento ou a do cadastro);
-- só nas fórmulas que ainda estão como vieram
UPDATE formula SET expressao = 'LIMITA(DIAS_ATE(SOMA_MESES(MAX(ULTIMA_DATA("pilha"); data_pilha); vida_pilha_meses)) * 100 / (vida_pilha_meses * 30.44); 0; 100)'
    WHERE identificador = 'energia' AND expressao = 'LIMITA(DIAS_ATE(SOMA_MESES(data_pilha; vida_pilha_meses)) * 100 / (vida_pilha_meses * 30.44); 0; 100)';
UPDATE formula SET expressao = 'MAX(0; DIAS_ATE(SOMA_MESES(MAX(ULTIMA_DATA("revisao"); data_revisao); intervalo_revisao_meses)))'
    WHERE identificador = 'dias_ate_revisao' AND expressao = 'MAX(0; DIAS_ATE(SOMA_MESES(data_revisao; intervalo_revisao_meses)))';
