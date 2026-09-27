-- Relógios 2, migração v4: a escala inteligente, os eventos personalizados, o Google Agenda, a previsão do smartwatch
-- e a linha do tempo (portados do sistema antigo). Aplique pela API (POST recurso=migracoes, acao=aplicar) ou pela
-- tela de Configuração. Os seus dados não mudam; as fórmulas e os avisos só mudam onde ainda estão como vieram.
SET NAMES utf8mb4;

-- escala inteligente: um modo com escala_dias planeja esse horizonte inteiro de uma vez (de 7 dias a 2 anos), pela maior
-- nota, simulando as fórmulas dia a dia; vazio: o modo sorteia pelos blocos, como antes
ALTER TABLE modo ADD COLUMN escala_dias INT NULL AFTER selecao;
INSERT INTO modo (nome, selecao, escala_dias, ordem) VALUES ('Escala inteligente', 'inteligente', 30, 5);
INSERT INTO modo_bloco (modo_id, nome, dias, no_id, um_por, ordem) SELECT id, 'Todo dia', '1,2,3,4,5,6,7', NULL, 'dia', 1 FROM modo WHERE nome = 'Escala inteligente' AND escala_dias = 30 ORDER BY id DESC LIMIT 1;

-- o que fazer em cada dia do plano (a escala anota: carregar antes de usar, dar corda, pôr no sol)
ALTER TABLE plano ADD COLUMN acao VARCHAR(500) NULL AFTER origem;

-- quantos dias seguidos o relógio escolhido fica na escala (vazio: 1). O smartwatch fica o que a bateria dá, até 7 dias.
INSERT INTO formula (identificador, nome, expressao, unidade, no_id)
    SELECT 'dias_seguidos', 'Dias seguidos na escala: o que a bateria dá, até 7', 'LIMITA(ARREDONDA(100 / PADRAO(decaimento_uso; 100 / autonomia_dias) - 0,5); 1; 7)', 'dias', id FROM no WHERE id = 1;

-- o decaimento em uso do smartwatch é por dia de uso (o horário de uso da Configuração), não por 24 horas, como no
-- sistema antigo; só na fórmula que ainda está como veio
UPDATE formula SET expressao = 'LIMITA(ULTIMO_VALOR("carga") - HORAS_APOS("pulso"; "carga") / HORAS_USO() * PADRAO(decaimento_uso; 100 / autonomia_dias) - (DIAS_DESDE_ULTIMO("carga") - HORAS_APOS("pulso"; "carga") / 24) * decaimento_repouso; 0; 100)'
    WHERE identificador = 'energia' AND expressao = 'LIMITA(ULTIMO_VALOR("carga") - HORAS_APOS("pulso"; "carga") / 24 * PADRAO(decaimento_uso; 100 / autonomia_dias) - (DIAS_DESDE_ULTIMO("carga") - HORAS_APOS("pulso"; "carga") / 24) * decaimento_repouso; 0; 100)';

-- avisos na escala e na agenda. escala: nao; uso (conferido no relógio escolhido, do começo ao fim do bloco dele: se vence,
-- a escala anota a ação e simula o lançamento que resolve); sempre (também nos guardados, todo dia). simula_valor: o
-- valor do lançamento simulado (carga: 100); simula_horas: a duração da sessão simulada (sol: 6 h). agenda: nao; janela
-- (só o que cai dentro dos dias de antecedência da agenda); sempre (qualquer data).
ALTER TABLE aviso ADD COLUMN escala ENUM('nao','uso','sempre') NOT NULL DEFAULT 'nao' AFTER ativo;
ALTER TABLE aviso ADD COLUMN simula_valor DECIMAL(12,2) NULL AFTER escala;
ALTER TABLE aviso ADD COLUMN simula_horas DECIMAL(6,2) NULL AFTER simula_valor;
ALTER TABLE aviso ADD COLUMN agenda ENUM('nao','janela','sempre') NOT NULL DEFAULT 'nao' AFTER simula_horas;
UPDATE aviso SET escala = 'uso', simula_valor = 100, agenda = 'janela' WHERE identificador = 'carregar';
UPDATE aviso SET escala = 'uso', agenda = 'janela' WHERE identificador = 'corda';
UPDATE aviso SET escala = 'sempre', simula_horas = 6, agenda = 'janela' WHERE identificador = 'sol';
UPDATE aviso SET agenda = 'sempre' WHERE identificador IN ('pilha', 'revisao');

-- eventos personalizados: nome, quando dispara e, se quiser, de qual relógio; saem no Telegram e na agenda
CREATE TABLE evento_personalizado (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(120) NOT NULL,
    texto           VARCHAR(500) NULL,                  -- a mensagem, com {relogio} e {data} (vazio: o nome)
    ativo           TINYINT NOT NULL DEFAULT 1,
    repeticao       ENUM('uma','diaria','semanal','mensal','intervalo') NOT NULL,
    data_inicio     DATE NULL,                          -- uma vez: a data; a cada N dias: a partir de quando
    hora            TIME NOT NULL,
    dias_semana     VARCHAR(20) NULL,                   -- semanal: 1 (segunda) a 7 (domingo), separados por vírgula
    dia_mes         TINYINT NULL,                       -- mensal: o dia (mês mais curto: o último dia)
    intervalo_dias  INT NULL,                           -- a cada N dias
    relogio_id      INT NULL,
    telegram        TINYINT NOT NULL DEFAULT 1,
    agenda          TINYINT NOT NULL DEFAULT 1,
    criado          DATETIME NOT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- cada ocorrência disparada: nenhuma sai duas vezes
CREATE TABLE evento_disparo (
    evento_id   INT NOT NULL,
    ocorrencia  DATETIME NOT NULL,
    disparado   DATETIME NOT NULL,
    PRIMARY KEY (evento_id, ocorrencia),
    FOREIGN KEY (evento_id) REFERENCES evento_personalizado(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- os eventos criados no Google Agenda, para sincronizar sem duplicar
CREATE TABLE agenda_evento (
    chave       VARCHAR(160) PRIMARY KEY,
    google_id   VARCHAR(255) NOT NULL,
    data        DATE NOT NULL,
    titulo      VARCHAR(255) NOT NULL,
    assinatura  CHAR(32) NULL,                          -- md5 do título e da descrição: mudou, o evento é atualizado
    criado      DATETIME NOT NULL
) ENGINE=InnoDB;

-- a configuração da agenda, da escala e da previsão (os valores do sistema antigo)
INSERT IGNORE INTO config (chave, valor) VALUES ('agenda_ativa', '0'), ('agenda_id', ''), ('agenda_chave', ''), ('agenda_antecedencia', '30'),
    ('agenda_dia', '0'), ('agenda_vespera', '0'), ('agenda_teste_id', ''), ('url_sistema', ''), ('escala_fim', ''), ('escala_gerada', ''),
    ('previsao_limite', '20'),
    ('agenda_modelo', '{acao}: {relogio}\n{motivo}\n\n{relogio} (código {codigo}, {tipo}) · {estado} · carga {carga}\n{link}');
