-- Relógios 2, migração v6: o gasto medido pelas leituras (como no sistema antigo, com a média dos últimos dias) e as
-- autonomias em segundos. Os seus dados não mudam; as fórmulas e os avisos só mudam onde ainda estão como vieram.
SET NAMES utf8mb4;

-- cada medição do gasto: entre duas leituras de um lançamento que mede o gasto (a leitura de carga), quanto caiu por dia de
-- uso (medida "uso") ou por dia guardado ("repouso"). "usada": entra na média (a caixa "Atualizar o gasto com esta medição"
-- marcada); desmarcada, fica só no histórico. O peso na média são as horas que a medição cobriu.
CREATE TABLE medicao (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id      INT NOT NULL,
    lancamento_id   INT NOT NULL,                       -- a leitura que fechou a medição
    medida          ENUM('uso','repouso') NOT NULL,
    taxa            DECIMAL(9,3) NOT NULL,              -- % por dia de uso (uso) ou % por dia guardado (repouso)
    de_valor        DECIMAL(12,2) NOT NULL,             -- a leitura de antes
    ate_valor       DECIMAL(12,2) NOT NULL,             -- a leitura de agora
    inicio          DATETIME NOT NULL,                  -- quando foi a leitura de antes
    fim             DATETIME NOT NULL,                  -- quando foi a leitura de agora
    horas_pulso     DECIMAL(9,2) NOT NULL,
    horas_guardado  DECIMAL(9,2) NOT NULL,
    peso_horas      DECIMAL(9,2) NOT NULL,              -- as horas que a medição cobriu: no pulso (uso) ou guardado (repouso)
    usada           TINYINT NOT NULL DEFAULT 1,
    criado          DATETIME NOT NULL,
    INDEX (relogio_id, medida, fim),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (lancamento_id) REFERENCES lancamento(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- qual tipo de lançamento mede o gasto: a leitura de carga
ALTER TABLE lancamento_tipo ADD COLUMN mede_gasto TINYINT NOT NULL DEFAULT 0 AFTER exclusiva;
UPDATE lancamento_tipo SET mede_gasto = 1 WHERE identificador = 'carga';

-- a média do gasto medido usa as medições destes últimos dias (sem nenhuma na janela: vale a última medição)
INSERT IGNORE INTO config (chave, valor) VALUES ('medicao_janela_dias', '90');

-- o gasto que vale no smartwatch: o medido; senão o informado no cadastro; senão o que sai da autonomia (em uso) ou o
-- padrão do campo (guardado)
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'taxa_uso', 'Gasto em uso que vale: o medido, senão o informado, senão 100 ÷ autonomia', 'PADRAO(MEDIDO("uso"); PADRAO(decaimento_uso; 100 / autonomia_dias))', '% por dia', id FROM no WHERE id = 1;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'taxa_repouso', 'Gasto guardado que vale: o medido, senão o do cadastro', 'PADRAO(MEDIDO("repouso"); decaimento_repouso)', '% por dia', id FROM no WHERE id = 1;
UPDATE formula SET expressao = 'LIMITA(ULTIMO_VALOR("carga") - HORAS_APOS("pulso"; "carga") / HORAS_USO() * taxa_uso - (DIAS_DESDE_ULTIMO("carga") - HORAS_APOS("pulso"; "carga") / 24) * taxa_repouso; 0; 100)'
    WHERE identificador = 'energia' AND expressao = 'LIMITA(ULTIMO_VALOR("carga") - HORAS_APOS("pulso"; "carga") / HORAS_USO() * PADRAO(decaimento_uso; 100 / autonomia_dias) - (DIAS_DESDE_ULTIMO("carga") - HORAS_APOS("pulso"; "carga") / 24) * decaimento_repouso; 0; 100)';
UPDATE formula SET expressao = 'energia / taxa_uso' WHERE identificador = 'dias_de_carga' AND expressao = 'energia / PADRAO(decaimento_uso; 100 / autonomia_dias)';
UPDATE formula SET expressao = 'energia / taxa_uso * 86400' WHERE identificador = 'autonomia_restante' AND expressao = 'energia / PADRAO(decaimento_uso; 100 / autonomia_dias) * 86400';
UPDATE formula SET expressao = 'LIMITA(ARREDONDA(100 / taxa_uso - 0,5); 1; 7)' WHERE identificador = 'dias_seguidos' AND expressao = 'LIMITA(ARREDONDA(100 / PADRAO(decaimento_uso; 100 / autonomia_dias) - 0,5); 1; 7)';
UPDATE aviso SET expressao = 'AGORA() + (energia - 20) / taxa_uso' WHERE identificador = 'carregar' AND expressao = 'AGORA() + (energia - 20) / PADRAO(decaimento_uso; 100 / autonomia_dias)';
UPDATE aviso SET expressao = 'AGORA() + (energia - 20) / taxa_repouso' WHERE identificador = 'carga_baixa' AND expressao = 'AGORA() + (energia - 20) / decaimento_repouso';

-- autonomia prevista: quanto dura com a carga cheia, pelo cadastro, em segundos
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_prevista', 'Autonomia prevista do smartwatch: a autonomia do cadastro', 'autonomia_dias * 86400', 's', id FROM no WHERE id = 1;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_prevista', 'Autonomia prevista do mecânico: a reserva de marcha do cadastro', 'reserva_horas * 3600', 's', id FROM no WHERE id = 3;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_prevista', 'Autonomia prevista da pilha: a vida da pilha do cadastro', 'vida_pilha_meses * 30,44 * 86400', 's', id FROM no WHERE id = 7;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_prevista', 'Autonomia prevista do solar: a reserva de energia do cadastro', 'reserva_dias * 86400', 's', id FROM no WHERE id = 8;

-- autonomia atual: quanto dura com a carga cheia, pela conta do sistema, em segundos (no smartwatch, pelo gasto que vale:
-- o medido pelas leituras, quando há; nos outros, a conta é a do cadastro)
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_atual', 'Autonomia atual do smartwatch: 100% pelo gasto em uso que vale (o medido, quando há)', '100 / taxa_uso * 86400', 's', id FROM no WHERE id = 1;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_atual', 'Autonomia atual do mecânico: a reserva de marcha', 'reserva_horas * 3600', 's', id FROM no WHERE id = 3;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_atual', 'Autonomia atual da pilha: a vida da pilha', 'vida_pilha_meses * 30,44 * 86400', 's', id FROM no WHERE id = 7;
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'autonomia_atual', 'Autonomia atual do solar: a reserva de energia', 'reserva_dias * 86400', 's', id FROM no WHERE id = 8;
