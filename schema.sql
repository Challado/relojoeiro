-- Relógios 2: o banco. O código só tem mecanismos; o conteúdo é cadastro.
-- Instala no banco do config.php (vazio), seja ele MySQL/MariaDB, PostgreSQL ou SQLite: php instalar.php
-- (no MySQL também dá direto: mysql -u root -p --default-character-set=utf8mb4 relogios < schema.sql)
-- Depois, para trazer os dados do sistema antigo: php importar.php relogios   (veja o importar.php)
-- Um arquivo só para os três bancos: a estrutura (CREATE TABLE, ALTER TABLE) no dialeto do MySQL, que o banco.php
-- traduz para o PostgreSQL e o SQLite; os dados (INSERT, UPDATE) em SQL comum aos três. O mesmo vale para as migrações.
SET NAMES utf8mb4;

-- a árvore de classificação: cada relógio fica num ponto; cada ponto pode ter campos, lançamentos e fórmulas
CREATE TABLE no (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    pai_id  INT NULL,                    -- vazio: na raiz
    nome    VARCHAR(80) NOT NULL,
    ordem   INT NOT NULL DEFAULT 0,
    FOREIGN KEY (pai_id) REFERENCES no(id)
) ENGINE=InnoDB;

CREATE TABLE relogio (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(120) NOT NULL,
    no_id       INT NULL,                -- o ponto da árvore (vazio: na raiz)
    disponivel  TINYINT NOT NULL DEFAULT 1,
    criado      DATETIME NOT NULL,
    FOREIGN KEY (no_id) REFERENCES no(id)
) ENGINE=InnoDB;

CREATE TABLE foto (
    relogio_id  INT PRIMARY KEY,
    tipo        VARCHAR(40) NOT NULL,    -- image/jpeg, image/png, image/webp
    dados       MEDIUMBLOB NOT NULL,
    atualizado  DATETIME NOT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- campos: definidos por você; valem para os relógios do ponto e de tudo abaixo dele (vazio: todos)
CREATE TABLE campo (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL UNIQUE,   -- o nome nas fórmulas
    nome           VARCHAR(120) NOT NULL,         -- o que aparece no cadastro
    tipo           ENUM('inteiro','decimal','sim_nao','data','lista','texto') NOT NULL,
    unidade        VARCHAR(20) NOT NULL DEFAULT '',
    opcoes         TEXT NULL,                     -- lista: uma opção por linha
    padrao         VARCHAR(200) NULL,             -- vale quando o relógio não tem valor
    no_id          INT NULL,
    ordem          INT NOT NULL DEFAULT 0,
    FOREIGN KEY (no_id) REFERENCES no(id)
) ENGINE=InnoDB;

CREATE TABLE campo_valor (
    relogio_id  INT NOT NULL,
    campo_id    INT NOT NULL,
    valor       TEXT NOT NULL,
    PRIMARY KEY (relogio_id, campo_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- tipos de lançamento: o que se registra num relógio (instantâneo, com valor, ou sessão com início e fim)
CREATE TABLE lancamento_tipo (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL UNIQUE,   -- o nome nas fórmulas: HORAS("pulso"; 30)
    nome           VARCHAR(120) NOT NULL,
    formato        ENUM('instantaneo','valor','sessao') NOT NULL,
    unidade        VARCHAR(20) NOT NULL DEFAULT '',
    fecha_as       TIME NULL,                     -- sessão esquecida aberta fecha sozinha a esta hora do dia em que começou
    no_id          INT NULL,                      -- em que ponto da árvore se lança (vazio: todos)
    ordem          INT NOT NULL DEFAULT 0,
    FOREIGN KEY (no_id) REFERENCES no(id)
) ENGINE=InnoDB;

CREATE TABLE lancamento (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id  INT NOT NULL,
    tipo_id     INT NOT NULL,
    inicio      DATETIME NOT NULL,          -- instantâneo: o momento; sessão: o começo
    fim         DATETIME NULL,              -- sessão: o fim (vazio: aberta)
    valor       DECIMAL(12,2) NULL,         -- com valor: a leitura
    origem      VARCHAR(20) NULL,           -- de onde veio: manual, rodizio, importado
    criado      DATETIME NOT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (tipo_id) REFERENCES lancamento_tipo(id),
    INDEX (relogio_id, tipo_id, inicio)
) ENGINE=InnoDB;

-- fórmulas: definidas por você; a mesma fórmula (o mesmo identificador) pode ter uma versão em cada ponto da árvore,
-- e vale para o relógio a do ponto mais perto dele
CREATE TABLE formula (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL,
    nome           VARCHAR(120) NOT NULL,
    expressao      TEXT NOT NULL,
    unidade        VARCHAR(20) NOT NULL DEFAULT '',
    no_id          INT NULL,
    FOREIGN KEY (no_id) REFERENCES no(id),
    INDEX (identificador)
) ENGINE=InnoDB;

CREATE TABLE usuario (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    login       VARCHAR(60) NOT NULL UNIQUE,
    senha_hash  VARCHAR(255) NOT NULL,
    criado      DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE config (
    chave  VARCHAR(80) PRIMARY KEY,
    valor  TEXT NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------------------------------------------------
-- O conjunto inicial: registros comuns, editáveis e excluíveis, que reproduzem o que o sistema antigo fazia
-- ---------------------------------------------------------------------------------------------------------------------
INSERT INTO no (id, pai_id, nome, ordem) VALUES
    (1, NULL, 'Smartwatch', 1), (2, NULL, 'Tradicional', 2),
    (3, 2, 'Mecânico', 1), (4, 3, 'Automático', 1), (5, 3, 'Corda manual', 2),
    (6, 2, 'Quartzo', 2), (7, 6, 'Pilha', 1), (8, 6, 'Solar', 2);

INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem) VALUES
    ('observacao', 'Observação', 'texto', '', NULL, NULL, 1),
    ('preferencia', 'Sua nota para o relógio (0 a 100)', 'inteiro', '', '50', NULL, 2),
    ('data_compra', 'Data da compra', 'data', '', NULL, NULL, 3),
    ('valor_compra', 'Valor pago', 'decimal', 'R$', NULL, NULL, 4),
    ('loja', 'Onde comprou', 'texto', '', NULL, NULL, 5),
    ('garantia_ate', 'Garantia até', 'data', '', NULL, NULL, 6),
    ('autonomia_dias', 'Autonomia em uso real', 'decimal', 'dias', '5', 1, 10),
    ('decaimento_uso', 'Decaimento em uso (vazio: 100 ÷ autonomia)', 'decimal', '% por dia', NULL, 1, 11),
    ('decaimento_repouso', 'Decaimento guardado', 'decimal', '% por dia', '0.1', 1, 12),
    ('data_revisao', 'Última revisão', 'data', '', NULL, 2, 20),
    ('intervalo_revisao_meses', 'Revisão a cada', 'inteiro', 'meses', NULL, 2, 21),
    ('reserva_horas', 'Reserva de marcha', 'inteiro', 'h', '40', 3, 30),
    ('corda_manual', 'Aceita corda pela coroa', 'sim_nao', '', '1', 3, 31),
    ('carga_pulso_horas', 'No pulso: horas de uso', 'decimal', 'h', '8', 4, 32),
    ('carga_pulso_reserva', 'No pulso: reserva que essas horas dão', 'decimal', 'h', '36', 4, 33),
    ('carga_winder_horas', 'No winder: horas', 'decimal', 'h', NULL, 4, 34),
    ('carga_winder_reserva', 'No winder: reserva que essas horas dão', 'decimal', 'h', NULL, 4, 35),
    ('reserva_dias', 'Reserva de energia (cheio até parar)', 'decimal', 'dias', '180', 8, 40),
    ('carga_sol_horas', 'Sol direto para encher, de parado', 'decimal', 'h', '10', 8, 41),
    ('data_pilha', 'Última troca de pilha', 'data', '', NULL, 7, 50),
    ('vida_pilha_meses', 'Vida da pilha', 'inteiro', 'meses', '24', 7, 51);

INSERT INTO lancamento_tipo (identificador, nome, formato, unidade, fecha_as, no_id, ordem) VALUES
    ('pulso', 'No pulso', 'sessao', '', '22:00', NULL, 1),
    ('carga', 'Leitura de carga', 'valor', '%', NULL, 1, 2),
    ('corda', 'Corda', 'instantaneo', '', NULL, 3, 3),
    ('winder', 'No winder', 'sessao', '', NULL, 4, 4),
    ('sol', 'No sol', 'sessao', '', '18:00', 8, 5),
    ('pilha', 'Troca de pilha', 'instantaneo', '', NULL, 7, 6),
    ('revisao', 'Revisão', 'instantaneo', '', NULL, 2, 7),
    ('pulso_antigo', 'No pulso (marcação antiga, sem duração)', 'instantaneo', '', NULL, NULL, 20),
    ('winder_antigo', 'Winder (marcação antiga, sem duração)', 'instantaneo', '', NULL, 4, 21),
    ('sol_antigo', 'Sol (marcação antiga, sem duração)', 'instantaneo', '', NULL, 8, 22);

INSERT INTO formula (identificador, nome, expressao, unidade, no_id) VALUES
    ('dias_sem_uso', 'Dias sem uso', 'PADRAO(DIAS_DESDE_ULTIMO("pulso"); 9999)', 'dias', NULL),
    ('uso_30d', 'Dias com uso nos últimos 30 dias', 'CONTAR("pulso"; 30)', 'dias', NULL),
    ('uso_vs_media', 'Uso nos últimos 30 dias, comparado com a média da coleção', 'SE(PADRAO(MEDIA_COLECAO("uso_30d"); 0) > 0; uso_30d * 100 / MEDIA_COLECAO("uso_30d"); 100)', '%', NULL),
    ('dias_desde_compra', 'Dias desde a compra', 'DIAS_DESDE(data_compra)', 'dias', NULL),
    ('energia', 'Energia agora: bateria do smartwatch', 'LIMITA(ULTIMO_VALOR("carga") - HORAS_APOS("pulso"; "carga") / 24 * PADRAO(decaimento_uso; 100 / autonomia_dias) - (DIAS_DESDE_ULTIMO("carga") - HORAS_APOS("pulso"; "carga") / 24) * decaimento_repouso; 0; 100)', '%', 1),
    ('dias_de_carga', 'Dias de uso que a carga aguenta', 'energia / PADRAO(decaimento_uso; 100 / autonomia_dias)', 'dias', 1),
    ('reserva_restante', 'Reserva de marcha que sobra', 'ACUMULA(0; reserva_horas; 1; "pulso"; carga_pulso_reserva / carga_pulso_horas; "winder"; PADRAO(carga_winder_reserva; carga_pulso_reserva) / PADRAO(carga_winder_horas; carga_pulso_horas); "corda"; reserva_horas; "winder_antigo"; reserva_horas; "pulso_antigo"; reserva_horas)', 'h', 3),
    ('energia', 'Energia agora: reserva de marcha do mecânico', 'LIMITA(reserva_restante * 100 / reserva_horas; 0; 100)', '%', 3),
    ('energia', 'Energia agora: carga de luz do solar (sem nenhum sol registrado: desconhecida)', 'SE(E(VAZIO(ULTIMA_DATA("sol")); VAZIO(ULTIMA_DATA("sol_antigo"))); NADA(); ACUMULA(70; 100; 100 / (reserva_dias * 24); "sol"; 100 / carga_sol_horas; "pulso"; 0; "sol_antigo"; 100))', '%', 8),
    ('energia', 'Energia agora: vida da pilha', 'LIMITA(DIAS_ATE(SOMA_MESES(data_pilha; vida_pilha_meses)) * 100 / (vida_pilha_meses * 30.44); 0; 100)', '%', 7),
    ('dias_ate_revisao', 'Dias até a revisão (0: vencida)', 'MAX(0; DIAS_ATE(SOMA_MESES(data_revisao; intervalo_revisao_meses)))', 'dias', 2);

--
-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v2 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v3 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
-- a configuração do rodízio e das mensagens (os valores do sistema antigo)
INSERT IGNORE INTO config (chave, valor) VALUES ('uso_inicio', '07:00'), ('uso_fim', '22:00'), ('horario_manha', '06:30'), ('horario_noite', '20:00'),
    ('mensagens_ativas', '1'), ('max_sem_uso', '21'), ('modo_ativo', '2'), ('ultima_manha', ''), ('ultima_noite', '');

-- modos de rodízio: cada um com blocos de dias; cada bloco sorteia dentro de um ponto da árvore (vazio: todos), um por
-- bloco (o mesmo relógio nos dias do bloco, na semana) ou um por dia, ou usa um relógio fixo
CREATE TABLE modo (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    nome     VARCHAR(80) NOT NULL,
    selecao  ENUM('inteligente','ponderado','aleatorio','fifo') NOT NULL DEFAULT 'ponderado',
    ordem    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE modo_bloco (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    modo_id     INT NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    dias        VARCHAR(20) NOT NULL,           -- dias da semana, 1 (segunda) a 7 (domingo), separados por vírgula
    no_id       INT NULL,                       -- de onde sortear (vazio: todos)
    um_por      ENUM('bloco','dia') NOT NULL DEFAULT 'dia',
    relogio_id  INT NULL,                       -- relógio fixo (vazio: sorteia)
    ordem       INT NOT NULL DEFAULT 0,
    FOREIGN KEY (modo_id) REFERENCES modo(id) ON DELETE CASCADE,
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE SET NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE SET NULL
) ENGINE=InnoDB;
INSERT INTO modo (id, nome, selecao, ordem) VALUES (1, 'Um por semana', 'ponderado', 1), (2, 'Semana e fim de semana', 'ponderado', 2),
    (3, 'Por dia da semana', 'ponderado', 3), (4, 'Aleatório todo dia', 'aleatorio', 4);
INSERT INTO modo_bloco (modo_id, nome, dias, no_id, um_por, ordem) SELECT 1, 'A semana', '1,2,3,4,5,6,7', id, 'bloco', 1 FROM no WHERE id = 2;
INSERT INTO modo_bloco (modo_id, nome, dias, no_id, um_por, ordem) SELECT 2, 'Segunda a sexta', '1,2,3,4,5', id, 'bloco', 1 FROM no WHERE id = 1;
INSERT INTO modo_bloco (modo_id, nome, dias, no_id, um_por, ordem) SELECT 2, 'Sábado e domingo', '6,7', id, 'dia', 2 FROM no WHERE id = 2;
INSERT INTO modo_bloco (modo_id, nome, dias, no_id, um_por, ordem) SELECT 3, 'Dias úteis', '1,2,3,4,5', id, 'dia', 1 FROM no WHERE id = 1;
INSERT INTO modo_bloco (modo_id, nome, dias, no_id, um_por, ordem) SELECT 3, 'Fim de semana', '6,7', id, 'dia', 2 FROM no WHERE id = 2;
INSERT INTO modo_bloco (modo_id, nome, dias, no_id, um_por, ordem) VALUES (4, 'Todo dia', '1,2,3,4,5,6,7', NULL, 'dia', 1);

-- o plano: o relógio de cada dia (sorteado ou escolhido)
CREATE TABLE plano (
    data        DATE PRIMARY KEY,
    relogio_id  INT NOT NULL,
    bloco_id    INT NULL,
    origem      VARCHAR(20) NOT NULL,           -- sorteio ou manual
    criado      DATETIME NOT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (bloco_id) REFERENCES modo_bloco(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- critérios de escolha, por lugar da árvore: o relógio usa o conjunto mais perto dele, inteiro; cada subparâmetro
-- mede uma variável (campo ou fórmula) e as faixas transformam o valor em nota de 0 a 100
CREATE TABLE criterio_parametro (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    escopo_no_id       INT NULL,
    escopo_relogio_id  INT NULL,
    nome               VARCHAR(80) NOT NULL,
    peso               DECIMAL(6,2) NOT NULL,
    ordem              INT NOT NULL DEFAULT 0,
    FOREIGN KEY (escopo_no_id) REFERENCES no(id) ON DELETE CASCADE,
    FOREIGN KEY (escopo_relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE criterio_sub (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    parametro_id  INT NOT NULL,
    nome          VARCHAR(80) NOT NULL,
    variavel      VARCHAR(40) NOT NULL,
    peso          DECIMAL(6,2) NOT NULL,
    ordem         INT NOT NULL DEFAULT 0,
    FOREIGN KEY (parametro_id) REFERENCES criterio_parametro(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE criterio_faixa (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    sub_id     INT NOT NULL,
    de         DECIMAL(14,2) NULL,
    ate        DECIMAL(14,2) NULL,
    categoria  VARCHAR(80) NULL,
    nota       DECIMAL(6,2) NOT NULL,
    FOREIGN KEY (sub_id) REFERENCES criterio_sub(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- as execuções do cron (o que ele fez, e os erros)
CREATE TABLE cron_execucao (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    inicio     DATETIME NOT NULL,
    fim        DATETIME NULL,
    registro   TEXT NOT NULL,
    teve_erro  TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v4 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v5 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
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
-- (numa instalação nova ainda não há eventos, a agenda do dia e da véspera estão desligadas e os avisos que vão para a
-- agenda são estes; a migracao_v5.sql, que atualiza um banco que já existe, monta as listas a partir do que ele tem)
INSERT IGNORE INTO config (chave, valor) VALUES
    ('alerta_tipos', 'dia,vespera,garantia,carregar,carga_baixa,leitura,corda,sol,pilha,revisao'),
    ('agenda_tipos', 'carregar,corda,pilha,revisao,sol');

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

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v6 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v7 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
ALTER TABLE modo ADD COLUMN ciclo TINYINT NOT NULL DEFAULT 0 AFTER escala_dias;

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v8 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
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
UPDATE config SET valor = CONCAT(valor, ',winder') WHERE chave IN ('alerta_tipos', 'agenda_tipos')
    AND CONCAT(',', valor, ',') LIKE '%,corda,%' AND CONCAT(',', valor, ',') NOT LIKE '%,winder,%';

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v9 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v10 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
-- só no aviso que ainda está como veio na v9, ou com a troca feita à mão pela fórmula sem a proteção
UPDATE aviso SET expressao = 'SE(energia <= limite_carga; AGORA(); AGORA() + (energia - limite_carga) / SE(EM_SESSAO("pulso"); taxa_uso; taxa_repouso))'
    WHERE identificador = 'carregar' AND expressao IN ('AGORA() + (energia - limite_carga) / taxa_uso',
        'AGORA() + (energia - limite_carga) / SE(EM_SESSAO("pulso"); taxa_uso; taxa_repouso)');

-- carregando, não precisa de corda nem de winder: no winder, ou no pulso quando o pulso dá corda (o automático, que tem
-- carga_pulso_reserva; o de corda manual não carrega no pulso e continua avisando). No sol, não precisa de sol
UPDATE aviso SET expressao = 'SE(OU(EM_SESSAO("winder"); E(EM_SESSAO("pulso"); PADRAO(carga_pulso_reserva; 0) > 0)); NADA(); AGORA() + autonomia_restante / 86400 - limite_carga / 100 * reserva_horas / 24)'
    WHERE identificador IN ('corda', 'winder') AND expressao = 'AGORA() + autonomia_restante / 86400 - limite_carga / 100 * reserva_horas / 24';
UPDATE aviso SET expressao = 'SE(EM_SESSAO("sol"); NADA(); AGORA() + (energia - limite_carga) / (100 / reserva_dias))'
    WHERE identificador = 'sol' AND expressao = 'AGORA() + (energia - limite_carga) / (100 / reserva_dias)';

-- a marca de que a v10 foi aplicada: por último, para uma v10 que parou no meio continuar pendente
INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v10', '1');

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v11 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
-- o motivo de cada escolha do plano, numa frase: o relógio fixo do bloco, o mesmo da semana no bloco, a garantia de
-- rodízio (parado além do limite), a maior nota, o sorteio pela nota (com a chance que ele tinha), a fila, o sorteio
-- simples, ou a escolha à mão
ALTER TABLE plano ADD COLUMN motivo VARCHAR(300) NULL AFTER acao;

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v12 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
-- os dias sem uso de um relógio que nunca foi ao pulso contam desde a compra (antes valiam 9999 para todos os nunca
-- usados: empatavam entre si na garantia de rodízio, e um relógio comprado ontem já passava na frente de todos). Sem data
-- de compra, continua 9999
UPDATE formula SET expressao = 'PADRAO(DIAS_DESDE_ULTIMO("pulso"); PADRAO(DIAS_DESDE(data_compra); 9999))'
    WHERE identificador = 'dias_sem_uso' AND expressao = 'PADRAO(DIAS_DESDE_ULTIMO("pulso"); 9999)';

-- a marca de que a v12 foi aplicada
INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v12', '1');

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v13 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
-- o manual de cada relógio: um arquivo (PDF ou imagem) guardado no banco, como a foto; um por relógio, e sai junto quando
-- o relógio é excluído
CREATE TABLE manual (
    relogio_id  INT PRIMARY KEY,
    nome        VARCHAR(200) NOT NULL,   -- o nome do arquivo enviado
    tipo        VARCHAR(40) NOT NULL,    -- application/pdf, image/jpeg, image/png, image/webp
    tamanho     INT NOT NULL,            -- em bytes
    dados       LONGBLOB NOT NULL,
    atualizado  DATETIME NOT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v14 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
-- os documentos de cada relógio: qualquer arquivo, numa categoria; o arquivo numa pasta do servidor (DOCUMENTOS_PASTA)
-- as categorias dos documentos: cadastro (página Cadastros). "aceita": vazio, qualquer arquivo; senão, os tipos aceitos,
-- separados por vírgula (imagem, video, audio, pdf, xml)
CREATE TABLE documento_categoria (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL UNIQUE,
    nome           VARCHAR(120) NOT NULL,
    aceita         VARCHAR(100) NOT NULL DEFAULT '',
    ordem          INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- os documentos: os dados de cada arquivo (o arquivo mesmo fica na pasta, com o nome em "arquivo"; a miniatura de uma
-- foto, quando o navegador mandou, em "miniatura"); saem junto com o relógio
CREATE TABLE documento (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id    INT NOT NULL,
    categoria_id  INT NOT NULL,
    titulo        VARCHAR(200) NOT NULL,
    data          DATE NULL,                 -- a data do documento: a ocasião da foto, a data da nota
    descricao     TEXT NULL,
    nome          VARCHAR(255) NOT NULL,     -- o nome do arquivo enviado
    tipo          VARCHAR(100) NOT NULL,     -- o tipo (MIME): image/jpeg, video/mp4, application/pdf...
    tamanho       BIGINT NOT NULL,           -- em bytes
    arquivo       VARCHAR(200) NOT NULL,     -- o caminho dentro da pasta dos documentos
    miniatura     VARCHAR(200) NULL,
    criado        DATETIME NOT NULL,
    INDEX (relogio_id, categoria_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES documento_categoria(id)
) ENGINE=InnoDB;

INSERT INTO documento_categoria (identificador, nome, aceita, ordem) VALUES
    ('manual', 'Manual', '', 1),
    ('nota_fiscal', 'Nota fiscal', 'pdf,imagem', 2),
    ('nota_xml', 'Nota fiscal (XML)', 'xml', 3),
    ('fotos', 'Fotos', 'imagem', 4),
    ('videos', 'Vídeos', 'video', 5),
    ('diversos', 'Diversos', '', 6);

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v15 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
-- a cópia de segurança de cada documento dentro do banco, em pedaços de 4 MB; o arquivo que sumir da pasta volta do banco
-- no_banco: 1, a cópia no banco está completa; hash: o SHA-256 do arquivo, para conferir a cópia ao recriar
ALTER TABLE documento ADD COLUMN no_banco TINYINT NOT NULL DEFAULT 0 AFTER miniatura;
ALTER TABLE documento ADD COLUMN hash VARCHAR(64) NULL AFTER no_banco;

-- os pedaços do arquivo de cada documento, na ordem (parte 0, 1, 2...); a parte -1 é a miniatura da foto
CREATE TABLE documento_parte (
    documento_id  INT NOT NULL,
    parte         INT NOT NULL,
    dados         MEDIUMBLOB NOT NULL,
    PRIMARY KEY (documento_id, parte),
    FOREIGN KEY (documento_id) REFERENCES documento(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------------------------------------------------
-- Migração v16 (já incluída na instalação nova)
-- ---------------------------------------------------------------------------------------------------------------------
-- o banco no modelo relacional estrito (o passo em PHP dela, migracao_v16_dados, roda depois do schema.sql, no instalar.php)
-- 1. as chaves estrangeiras que estavam sem regra passam a apagar em cascata: os grupos de dentro, os relógios, os campos,
-- os tipos de lançamento, as fórmulas e os avisos de um grupo; os lançamentos de um tipo; os documentos de uma categoria
ALTER TABLE no ALTER FOREIGN KEY (pai_id) REFERENCES no(id) ON DELETE CASCADE;
ALTER TABLE relogio ALTER FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE;
ALTER TABLE campo ALTER FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE;
ALTER TABLE lancamento_tipo ALTER FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE;
ALTER TABLE formula ALTER FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE;
ALTER TABLE aviso ALTER FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE;
ALTER TABLE lancamento ALTER FOREIGN KEY (tipo_id) REFERENCES lancamento_tipo(id) ON DELETE CASCADE;
ALTER TABLE documento ALTER FOREIGN KEY (categoria_id) REFERENCES documento_categoria(id) ON DELETE CASCADE;

-- 2. o tipo de lançamento que resolve um aviso: o número dele (chave estrangeira), no lugar do nome
ALTER TABLE aviso ADD COLUMN resolve_tipo_id INT NULL AFTER resolve;
UPDATE aviso SET resolve_tipo_id = (SELECT t.id FROM lancamento_tipo t WHERE t.identificador = aviso.resolve);
ALTER TABLE aviso ALTER FOREIGN KEY (resolve_tipo_id) REFERENCES lancamento_tipo(id) ON DELETE SET NULL;
ALTER TABLE aviso DROP COLUMN resolve;

-- 3. o modo de rodízio ativo: uma marca no próprio modo, no lugar do número guardado na configuração
ALTER TABLE modo ADD COLUMN ativo TINYINT NOT NULL DEFAULT 0;
UPDATE modo SET ativo = 1 WHERE CONCAT(id, '') = (SELECT valor FROM config WHERE chave = 'modo_ativo');
DELETE FROM config WHERE chave = 'modo_ativo';

-- 4. os dias da semana de cada bloco de um modo (1 segunda ... 7 domingo): uma linha por dia, no lugar da lista "1,2,3"
CREATE TABLE modo_bloco_dia (
    bloco_id  INT NOT NULL,
    dia       TINYINT NOT NULL,
    PRIMARY KEY (bloco_id, dia),
    FOREIGN KEY (bloco_id) REFERENCES modo_bloco(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT INTO modo_bloco_dia (bloco_id, dia) SELECT id, 1 FROM modo_bloco WHERE CONCAT(',', dias, ',') LIKE '%,1,%';
INSERT INTO modo_bloco_dia (bloco_id, dia) SELECT id, 2 FROM modo_bloco WHERE CONCAT(',', dias, ',') LIKE '%,2,%';
INSERT INTO modo_bloco_dia (bloco_id, dia) SELECT id, 3 FROM modo_bloco WHERE CONCAT(',', dias, ',') LIKE '%,3,%';
INSERT INTO modo_bloco_dia (bloco_id, dia) SELECT id, 4 FROM modo_bloco WHERE CONCAT(',', dias, ',') LIKE '%,4,%';
INSERT INTO modo_bloco_dia (bloco_id, dia) SELECT id, 5 FROM modo_bloco WHERE CONCAT(',', dias, ',') LIKE '%,5,%';
INSERT INTO modo_bloco_dia (bloco_id, dia) SELECT id, 6 FROM modo_bloco WHERE CONCAT(',', dias, ',') LIKE '%,6,%';
INSERT INTO modo_bloco_dia (bloco_id, dia) SELECT id, 7 FROM modo_bloco WHERE CONCAT(',', dias, ',') LIKE '%,7,%';
ALTER TABLE modo_bloco DROP COLUMN dias;

-- 5. os tipos de arquivo que uma categoria de documentos aceita: uma linha por tipo, no lugar da lista "pdf,imagem"
CREATE TABLE documento_categoria_aceita (
    categoria_id  INT NOT NULL,
    familia       ENUM('imagem','video','audio','pdf','xml') NOT NULL,
    PRIMARY KEY (categoria_id, familia),
    FOREIGN KEY (categoria_id) REFERENCES documento_categoria(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT INTO documento_categoria_aceita (categoria_id, familia) SELECT id, 'imagem' FROM documento_categoria WHERE CONCAT(',', aceita, ',') LIKE '%,imagem,%';
INSERT INTO documento_categoria_aceita (categoria_id, familia) SELECT id, 'video' FROM documento_categoria WHERE CONCAT(',', aceita, ',') LIKE '%,video,%';
INSERT INTO documento_categoria_aceita (categoria_id, familia) SELECT id, 'audio' FROM documento_categoria WHERE CONCAT(',', aceita, ',') LIKE '%,audio,%';
INSERT INTO documento_categoria_aceita (categoria_id, familia) SELECT id, 'pdf' FROM documento_categoria WHERE CONCAT(',', aceita, ',') LIKE '%,pdf,%';
INSERT INTO documento_categoria_aceita (categoria_id, familia) SELECT id, 'xml' FROM documento_categoria WHERE CONCAT(',', aceita, ',') LIKE '%,xml,%';
ALTER TABLE documento_categoria DROP COLUMN aceita;

-- 6. a medição pertence a uma leitura (o lançamento), que já diz de que relógio é: o relogio_id repetido nela sai
ALTER TABLE medicao DROP COLUMN relogio_id;
ALTER TABLE medicao ADD INDEX (lancamento_id, medida, fim);

-- 7. os manuais da v13 (a tabela manual) viram documentos da categoria Manual, com o arquivo na cópia do banco (ele volta
-- para a pasta dos documentos sozinho); a tabela manual sai
INSERT IGNORE INTO documento_categoria (identificador, nome, ordem) VALUES ('manual', 'Manual', 0);
INSERT INTO documento (relogio_id, categoria_id, titulo, data, descricao, nome, tipo, tamanho, arquivo, miniatura, no_banco, hash, criado)
    SELECT m.relogio_id, (SELECT c.id FROM documento_categoria c WHERE c.identificador = 'manual'), 'Manual', NULL, NULL, m.nome, m.tipo, m.tamanho,
        CONCAT('r', m.relogio_id, '/manual-v13-', m.relogio_id), NULL, 1, NULL, m.atualizado
    FROM manual m;
INSERT INTO documento_parte (documento_id, parte, dados)
    SELECT d.id, 0, m.dados FROM manual m JOIN documento d ON d.arquivo = CONCAT('r', m.relogio_id, '/manual-v13-', m.relogio_id);
DROP TABLE manual;

-- 8. o que vai por cada canal (tg: Telegram; ag: Google Agenda) e a mensagem própria de cada tipo: uma tabela, no lugar das
-- listas alerta_tipos e agenda_tipos e das chaves tg_proprio_<tipo>, tg_corpo_<tipo> (e ag_) da configuração. O tipo é o
-- dia, a véspera ou o identificador de um aviso; um evento personalizado entra pelo número dele (evento_id)
CREATE TABLE canal_aviso (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    canal      ENUM('tg','ag') NOT NULL,
    tipo       VARCHAR(60) NULL,
    evento_id  INT NULL,
    envia      TINYINT NOT NULL DEFAULT 0,
    propria    TINYINT NOT NULL DEFAULT 0,
    corpo      TEXT NULL,
    UNIQUE (canal, tipo),
    UNIQUE (canal, evento_id),
    CHECK ((tipo IS NULL AND evento_id IS NOT NULL) OR (tipo IS NOT NULL AND evento_id IS NULL)),
    FOREIGN KEY (evento_id) REFERENCES evento_personalizado(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT INTO canal_aviso (canal, tipo, envia, propria, corpo)
    SELECT 'tg', t.tipo,
        CASE WHEN CONCAT(',', COALESCE((SELECT valor FROM config WHERE chave = 'alerta_tipos'), ''), ',') LIKE CONCAT('%,', t.tipo, ',%') THEN 1 ELSE 0 END,
        CASE WHEN (SELECT valor FROM config WHERE chave = CONCAT('tg_proprio_', t.tipo)) = '1' THEN 1 ELSE 0 END,
        (SELECT valor FROM config WHERE chave = CONCAT('tg_corpo_', t.tipo))
    FROM (SELECT 'dia' AS tipo UNION SELECT 'vespera' UNION SELECT identificador FROM aviso) t;
INSERT INTO canal_aviso (canal, tipo, envia, propria, corpo)
    SELECT 'ag', t.tipo,
        CASE WHEN CONCAT(',', COALESCE((SELECT valor FROM config WHERE chave = 'agenda_tipos'), ''), ',') LIKE CONCAT('%,', t.tipo, ',%') THEN 1 ELSE 0 END,
        CASE WHEN (SELECT valor FROM config WHERE chave = CONCAT('ag_proprio_', t.tipo)) = '1' THEN 1 ELSE 0 END,
        (SELECT valor FROM config WHERE chave = CONCAT('ag_corpo_', t.tipo))
    FROM (SELECT 'dia' AS tipo UNION SELECT 'vespera' UNION SELECT identificador FROM aviso) t;
INSERT INTO canal_aviso (canal, evento_id, envia, propria, corpo)
    SELECT 'tg', e.id,
        CASE WHEN CONCAT(',', COALESCE((SELECT valor FROM config WHERE chave = 'alerta_tipos'), ''), ',') LIKE CONCAT('%,ev', e.id, ',%') THEN 1 ELSE 0 END,
        CASE WHEN (SELECT valor FROM config WHERE chave = CONCAT('tg_proprio_ev', e.id)) = '1' THEN 1 ELSE 0 END,
        (SELECT valor FROM config WHERE chave = CONCAT('tg_corpo_ev', e.id))
    FROM evento_personalizado e;
INSERT INTO canal_aviso (canal, evento_id, envia, propria, corpo)
    SELECT 'ag', e.id,
        CASE WHEN CONCAT(',', COALESCE((SELECT valor FROM config WHERE chave = 'agenda_tipos'), ''), ',') LIKE CONCAT('%,ev', e.id, ',%') THEN 1 ELSE 0 END,
        CASE WHEN (SELECT valor FROM config WHERE chave = CONCAT('ag_proprio_ev', e.id)) = '1' THEN 1 ELSE 0 END,
        (SELECT valor FROM config WHERE chave = CONCAT('ag_corpo_ev', e.id))
    FROM evento_personalizado e;
DELETE FROM config WHERE chave IN ('alerta_tipos', 'agenda_tipos') OR chave LIKE 'tg_proprio_%' OR chave LIKE 'tg_corpo_%'
    OR chave LIKE 'ag_proprio_%' OR chave LIKE 'ag_corpo_%';

-- 9. os dias da semana de um evento personalizado semanal (1 segunda ... 7 domingo): uma linha por dia, no lugar da lista
CREATE TABLE evento_dia (
    evento_id  INT NOT NULL,
    dia        TINYINT NOT NULL,
    PRIMARY KEY (evento_id, dia),
    FOREIGN KEY (evento_id) REFERENCES evento_personalizado(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT INTO evento_dia (evento_id, dia) SELECT id, 1 FROM evento_personalizado WHERE CONCAT(',', dias_semana, ',') LIKE '%,1,%';
INSERT INTO evento_dia (evento_id, dia) SELECT id, 2 FROM evento_personalizado WHERE CONCAT(',', dias_semana, ',') LIKE '%,2,%';
INSERT INTO evento_dia (evento_id, dia) SELECT id, 3 FROM evento_personalizado WHERE CONCAT(',', dias_semana, ',') LIKE '%,3,%';
INSERT INTO evento_dia (evento_id, dia) SELECT id, 4 FROM evento_personalizado WHERE CONCAT(',', dias_semana, ',') LIKE '%,4,%';
INSERT INTO evento_dia (evento_id, dia) SELECT id, 5 FROM evento_personalizado WHERE CONCAT(',', dias_semana, ',') LIKE '%,5,%';
INSERT INTO evento_dia (evento_id, dia) SELECT id, 6 FROM evento_personalizado WHERE CONCAT(',', dias_semana, ',') LIKE '%,6,%';
INSERT INTO evento_dia (evento_id, dia) SELECT id, 7 FROM evento_personalizado WHERE CONCAT(',', dias_semana, ',') LIKE '%,7,%';
ALTER TABLE evento_personalizado DROP COLUMN dias_semana;

-- 10. as opções de um campo do tipo lista: uma linha por opção, na ordem, no lugar do texto com uma por linha. A passagem
-- das opções que já existem (o texto quebrado por linha) e a saída da coluna opcoes são do passo em PHP desta migração
-- (migracao_v16_dados, no lib.php), que grava por último a marca de que a v16 foi aplicada
CREATE TABLE campo_opcao (
    campo_id  INT NOT NULL,
    ordem     INT NOT NULL,
    valor     VARCHAR(120) NOT NULL,
    PRIMARY KEY (campo_id, ordem),
    UNIQUE (campo_id, valor),
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- critérios iniciais (o "restaurar" da página Critérios lê daqui até o fim do arquivo)
-- critérios de todos os relógios
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (1, NULL, NULL, 'Tempo sem uso', 40, 1);
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 1, id, 'Dias sem uso', 'dias_sem_uso', 100, 1 FROM criterio_parametro WHERE id = 1;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 1, NULL, 0 FROM criterio_sub WHERE id = 1 UNION ALL SELECT id, 1, 3, NULL, 30 FROM criterio_sub WHERE id = 1 UNION ALL SELECT id, 3, 7, NULL, 60 FROM criterio_sub WHERE id = 1 UNION ALL SELECT id, 7, 14, NULL, 80 FROM criterio_sub WHERE id = 1 UNION ALL SELECT id, 14, 30, NULL, 95 FROM criterio_sub WHERE id = 1 UNION ALL SELECT id, 30, NULL, NULL, 100 FROM criterio_sub WHERE id = 1;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (2, NULL, NULL, 'Equilíbrio de uso', 30, 2);
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 2, id, 'Uso nos últimos 30 dias, comparado com a média', 'uso_vs_media', 100, 1 FROM criterio_parametro WHERE id = 2;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 25, NULL, 100 FROM criterio_sub WHERE id = 2 UNION ALL SELECT id, 25, 60, NULL, 80 FROM criterio_sub WHERE id = 2 UNION ALL SELECT id, 60, 100, NULL, 60 FROM criterio_sub WHERE id = 2 UNION ALL SELECT id, 100, 140, NULL, 35 FROM criterio_sub WHERE id = 2 UNION ALL SELECT id, 140, 200, NULL, 15 FROM criterio_sub WHERE id = 2 UNION ALL SELECT id, 200, NULL, NULL, 0 FROM criterio_sub WHERE id = 2;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (3, NULL, NULL, 'Preferência', 20, 3);
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 3, id, 'Sua nota para o relógio', 'preferencia', 100, 1 FROM criterio_parametro WHERE id = 3;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 20, NULL, 0 FROM criterio_sub WHERE id = 3 UNION ALL SELECT id, 20, 40, NULL, 25 FROM criterio_sub WHERE id = 3 UNION ALL SELECT id, 40, 60, NULL, 50 FROM criterio_sub WHERE id = 3 UNION ALL SELECT id, 60, 80, NULL, 75 FROM criterio_sub WHERE id = 3 UNION ALL SELECT id, 80, 100, NULL, 100 FROM criterio_sub WHERE id = 3;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (4, NULL, NULL, 'Novidade e valor', 10, 4);
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 4, id, 'Novidade: dias desde a compra', 'dias_desde_compra', 60, 1 FROM criterio_parametro WHERE id = 4;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 15, NULL, 100 FROM criterio_sub WHERE id = 4 UNION ALL SELECT id, 15, 45, NULL, 80 FROM criterio_sub WHERE id = 4 UNION ALL SELECT id, 45, 120, NULL, 55 FROM criterio_sub WHERE id = 4 UNION ALL SELECT id, 120, 365, NULL, 40 FROM criterio_sub WHERE id = 4 UNION ALL SELECT id, 365, NULL, NULL, 30 FROM criterio_sub WHERE id = 4;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 5, id, 'Aproveitar o investimento: valor pago', 'valor_compra', 40, 2 FROM criterio_parametro WHERE id = 4;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 300, NULL, 30 FROM criterio_sub WHERE id = 5 UNION ALL SELECT id, 300, 800, NULL, 50 FROM criterio_sub WHERE id = 5 UNION ALL SELECT id, 800, 1500, NULL, 70 FROM criterio_sub WHERE id = 5 UNION ALL SELECT id, 1500, NULL, NULL, 90 FROM criterio_sub WHERE id = 5;
-- critérios de Smartwatch
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 5, id, NULL, 'Energia', 30, 1 FROM no WHERE id = 1;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 6, id, 'Carga agora (%)', 'energia', 50, 1 FROM criterio_parametro WHERE id = 5;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 15, NULL, 0 FROM criterio_sub WHERE id = 6 UNION ALL SELECT id, 15, 30, NULL, 25 FROM criterio_sub WHERE id = 6 UNION ALL SELECT id, 30, 50, NULL, 55 FROM criterio_sub WHERE id = 6 UNION ALL SELECT id, 50, 80, NULL, 85 FROM criterio_sub WHERE id = 6 UNION ALL SELECT id, 80, 100, NULL, 100 FROM criterio_sub WHERE id = 6;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 7, id, 'Dias de uso que a carga aguenta', 'dias_de_carga', 50, 2 FROM criterio_parametro WHERE id = 5;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 1, NULL, 0 FROM criterio_sub WHERE id = 7 UNION ALL SELECT id, 1, 2, NULL, 30 FROM criterio_sub WHERE id = 7 UNION ALL SELECT id, 2, 4, NULL, 60 FROM criterio_sub WHERE id = 7 UNION ALL SELECT id, 4, 7, NULL, 85 FROM criterio_sub WHERE id = 7 UNION ALL SELECT id, 7, NULL, NULL, 100 FROM criterio_sub WHERE id = 7;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 6, id, NULL, 'Autonomia', 15, 2 FROM no WHERE id = 1;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 8, id, 'Autonomia do smartwatch', 'autonomia_dias', 100, 1 FROM criterio_parametro WHERE id = 6;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 2, NULL, 10 FROM criterio_sub WHERE id = 8 UNION ALL SELECT id, 2, 4, NULL, 40 FROM criterio_sub WHERE id = 8 UNION ALL SELECT id, 4, 7, NULL, 70 FROM criterio_sub WHERE id = 8 UNION ALL SELECT id, 7, 14, NULL, 90 FROM criterio_sub WHERE id = 8 UNION ALL SELECT id, 14, NULL, NULL, 100 FROM criterio_sub WHERE id = 8;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 7, id, NULL, 'Tempo sem uso', 25, 3 FROM no WHERE id = 1;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 9, id, 'Dias sem uso', 'dias_sem_uso', 100, 1 FROM criterio_parametro WHERE id = 7;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 1, NULL, 0 FROM criterio_sub WHERE id = 9 UNION ALL SELECT id, 1, 3, NULL, 30 FROM criterio_sub WHERE id = 9 UNION ALL SELECT id, 3, 7, NULL, 60 FROM criterio_sub WHERE id = 9 UNION ALL SELECT id, 7, 14, NULL, 80 FROM criterio_sub WHERE id = 9 UNION ALL SELECT id, 14, 30, NULL, 95 FROM criterio_sub WHERE id = 9 UNION ALL SELECT id, 30, NULL, NULL, 100 FROM criterio_sub WHERE id = 9;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 8, id, NULL, 'Equilíbrio de uso', 15, 4 FROM no WHERE id = 1;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 10, id, 'Uso nos últimos 30 dias, comparado com a média', 'uso_vs_media', 100, 1 FROM criterio_parametro WHERE id = 8;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 25, NULL, 100 FROM criterio_sub WHERE id = 10 UNION ALL SELECT id, 25, 60, NULL, 80 FROM criterio_sub WHERE id = 10 UNION ALL SELECT id, 60, 100, NULL, 60 FROM criterio_sub WHERE id = 10 UNION ALL SELECT id, 100, 140, NULL, 35 FROM criterio_sub WHERE id = 10 UNION ALL SELECT id, 140, 200, NULL, 15 FROM criterio_sub WHERE id = 10 UNION ALL SELECT id, 200, NULL, NULL, 0 FROM criterio_sub WHERE id = 10;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 9, id, NULL, 'Preferência', 10, 5 FROM no WHERE id = 1;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 11, id, 'Sua nota para o relógio', 'preferencia', 100, 1 FROM criterio_parametro WHERE id = 9;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 20, NULL, 0 FROM criterio_sub WHERE id = 11 UNION ALL SELECT id, 20, 40, NULL, 25 FROM criterio_sub WHERE id = 11 UNION ALL SELECT id, 40, 60, NULL, 50 FROM criterio_sub WHERE id = 11 UNION ALL SELECT id, 60, 80, NULL, 75 FROM criterio_sub WHERE id = 11 UNION ALL SELECT id, 80, 100, NULL, 100 FROM criterio_sub WHERE id = 11;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 10, id, NULL, 'Novidade e valor', 5, 6 FROM no WHERE id = 1;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 12, id, 'Novidade: dias desde a compra', 'dias_desde_compra', 60, 1 FROM criterio_parametro WHERE id = 10;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 15, NULL, 100 FROM criterio_sub WHERE id = 12 UNION ALL SELECT id, 15, 45, NULL, 80 FROM criterio_sub WHERE id = 12 UNION ALL SELECT id, 45, 120, NULL, 55 FROM criterio_sub WHERE id = 12 UNION ALL SELECT id, 120, 365, NULL, 40 FROM criterio_sub WHERE id = 12 UNION ALL SELECT id, 365, NULL, NULL, 30 FROM criterio_sub WHERE id = 12;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 13, id, 'Aproveitar o investimento: valor pago', 'valor_compra', 40, 2 FROM criterio_parametro WHERE id = 10;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 300, NULL, 30 FROM criterio_sub WHERE id = 13 UNION ALL SELECT id, 300, 800, NULL, 50 FROM criterio_sub WHERE id = 13 UNION ALL SELECT id, 800, 1500, NULL, 70 FROM criterio_sub WHERE id = 13 UNION ALL SELECT id, 1500, NULL, NULL, 90 FROM criterio_sub WHERE id = 13;
-- critérios de Mecânico
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 11, id, NULL, 'Reserva de marcha', 25, 1 FROM no WHERE id = 3;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 14, id, 'Reserva agora (%): vazia, precisa rodar', 'energia', 100, 1 FROM criterio_parametro WHERE id = 11;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 10, NULL, 100 FROM criterio_sub WHERE id = 14 UNION ALL SELECT id, 10, 30, NULL, 85 FROM criterio_sub WHERE id = 14 UNION ALL SELECT id, 30, 60, NULL, 60 FROM criterio_sub WHERE id = 14 UNION ALL SELECT id, 60, 90, NULL, 35 FROM criterio_sub WHERE id = 14 UNION ALL SELECT id, 90, 100, NULL, 20 FROM criterio_sub WHERE id = 14;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 12, id, NULL, 'Cuidado mecânico', 15, 2 FROM no WHERE id = 3;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 15, id, 'Parado precisa rodar (lubrificação)', 'dias_sem_uso', 50, 1 FROM criterio_parametro WHERE id = 12;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 7, NULL, 20 FROM criterio_sub WHERE id = 15 UNION ALL SELECT id, 7, 30, NULL, 60 FROM criterio_sub WHERE id = 15 UNION ALL SELECT id, 30, 90, NULL, 90 FROM criterio_sub WHERE id = 15 UNION ALL SELECT id, 90, NULL, NULL, 100 FROM criterio_sub WHERE id = 15;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 16, id, 'Revisão em dia', 'dias_ate_revisao', 50, 2 FROM criterio_parametro WHERE id = 12;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 30, NULL, 20 FROM criterio_sub WHERE id = 16 UNION ALL SELECT id, 30, 180, NULL, 60 FROM criterio_sub WHERE id = 16 UNION ALL SELECT id, 180, NULL, NULL, 100 FROM criterio_sub WHERE id = 16;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 13, id, NULL, 'Tempo sem uso', 25, 3 FROM no WHERE id = 3;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 17, id, 'Dias sem uso', 'dias_sem_uso', 100, 1 FROM criterio_parametro WHERE id = 13;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 1, NULL, 0 FROM criterio_sub WHERE id = 17 UNION ALL SELECT id, 1, 3, NULL, 30 FROM criterio_sub WHERE id = 17 UNION ALL SELECT id, 3, 7, NULL, 60 FROM criterio_sub WHERE id = 17 UNION ALL SELECT id, 7, 14, NULL, 80 FROM criterio_sub WHERE id = 17 UNION ALL SELECT id, 14, 30, NULL, 95 FROM criterio_sub WHERE id = 17 UNION ALL SELECT id, 30, NULL, NULL, 100 FROM criterio_sub WHERE id = 17;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 14, id, NULL, 'Equilíbrio de uso', 20, 4 FROM no WHERE id = 3;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 18, id, 'Uso nos últimos 30 dias, comparado com a média', 'uso_vs_media', 100, 1 FROM criterio_parametro WHERE id = 14;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 25, NULL, 100 FROM criterio_sub WHERE id = 18 UNION ALL SELECT id, 25, 60, NULL, 80 FROM criterio_sub WHERE id = 18 UNION ALL SELECT id, 60, 100, NULL, 60 FROM criterio_sub WHERE id = 18 UNION ALL SELECT id, 100, 140, NULL, 35 FROM criterio_sub WHERE id = 18 UNION ALL SELECT id, 140, 200, NULL, 15 FROM criterio_sub WHERE id = 18 UNION ALL SELECT id, 200, NULL, NULL, 0 FROM criterio_sub WHERE id = 18;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 15, id, NULL, 'Preferência', 10, 5 FROM no WHERE id = 3;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 19, id, 'Sua nota para o relógio', 'preferencia', 100, 1 FROM criterio_parametro WHERE id = 15;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 20, NULL, 0 FROM criterio_sub WHERE id = 19 UNION ALL SELECT id, 20, 40, NULL, 25 FROM criterio_sub WHERE id = 19 UNION ALL SELECT id, 40, 60, NULL, 50 FROM criterio_sub WHERE id = 19 UNION ALL SELECT id, 60, 80, NULL, 75 FROM criterio_sub WHERE id = 19 UNION ALL SELECT id, 80, 100, NULL, 100 FROM criterio_sub WHERE id = 19;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 16, id, NULL, 'Novidade e valor', 5, 6 FROM no WHERE id = 3;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 20, id, 'Novidade: dias desde a compra', 'dias_desde_compra', 60, 1 FROM criterio_parametro WHERE id = 16;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 15, NULL, 100 FROM criterio_sub WHERE id = 20 UNION ALL SELECT id, 15, 45, NULL, 80 FROM criterio_sub WHERE id = 20 UNION ALL SELECT id, 45, 120, NULL, 55 FROM criterio_sub WHERE id = 20 UNION ALL SELECT id, 120, 365, NULL, 40 FROM criterio_sub WHERE id = 20 UNION ALL SELECT id, 365, NULL, NULL, 30 FROM criterio_sub WHERE id = 20;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 21, id, 'Aproveitar o investimento: valor pago', 'valor_compra', 40, 2 FROM criterio_parametro WHERE id = 16;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 300, NULL, 30 FROM criterio_sub WHERE id = 21 UNION ALL SELECT id, 300, 800, NULL, 50 FROM criterio_sub WHERE id = 21 UNION ALL SELECT id, 800, 1500, NULL, 70 FROM criterio_sub WHERE id = 21 UNION ALL SELECT id, 1500, NULL, NULL, 90 FROM criterio_sub WHERE id = 21;
-- critérios de Pilha
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 17, id, NULL, 'Vida da pilha', 15, 1 FROM no WHERE id = 7;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 22, id, 'Vida da pilha (%)', 'energia', 100, 1 FROM criterio_parametro WHERE id = 17;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 10, NULL, 10 FROM criterio_sub WHERE id = 22 UNION ALL SELECT id, 10, 30, NULL, 50 FROM criterio_sub WHERE id = 22 UNION ALL SELECT id, 30, 100, NULL, 70 FROM criterio_sub WHERE id = 22;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 18, id, NULL, 'Revisão', 10, 2 FROM no WHERE id = 7;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 23, id, 'Revisão em dia', 'dias_ate_revisao', 100, 1 FROM criterio_parametro WHERE id = 18;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 30, NULL, 20 FROM criterio_sub WHERE id = 23 UNION ALL SELECT id, 30, 180, NULL, 60 FROM criterio_sub WHERE id = 23 UNION ALL SELECT id, 180, NULL, NULL, 100 FROM criterio_sub WHERE id = 23;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 19, id, NULL, 'Tempo sem uso', 35, 3 FROM no WHERE id = 7;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 24, id, 'Dias sem uso', 'dias_sem_uso', 100, 1 FROM criterio_parametro WHERE id = 19;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 1, NULL, 0 FROM criterio_sub WHERE id = 24 UNION ALL SELECT id, 1, 3, NULL, 30 FROM criterio_sub WHERE id = 24 UNION ALL SELECT id, 3, 7, NULL, 60 FROM criterio_sub WHERE id = 24 UNION ALL SELECT id, 7, 14, NULL, 80 FROM criterio_sub WHERE id = 24 UNION ALL SELECT id, 14, 30, NULL, 95 FROM criterio_sub WHERE id = 24 UNION ALL SELECT id, 30, NULL, NULL, 100 FROM criterio_sub WHERE id = 24;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 20, id, NULL, 'Equilíbrio de uso', 25, 4 FROM no WHERE id = 7;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 25, id, 'Uso nos últimos 30 dias, comparado com a média', 'uso_vs_media', 100, 1 FROM criterio_parametro WHERE id = 20;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 25, NULL, 100 FROM criterio_sub WHERE id = 25 UNION ALL SELECT id, 25, 60, NULL, 80 FROM criterio_sub WHERE id = 25 UNION ALL SELECT id, 60, 100, NULL, 60 FROM criterio_sub WHERE id = 25 UNION ALL SELECT id, 100, 140, NULL, 35 FROM criterio_sub WHERE id = 25 UNION ALL SELECT id, 140, 200, NULL, 15 FROM criterio_sub WHERE id = 25 UNION ALL SELECT id, 200, NULL, NULL, 0 FROM criterio_sub WHERE id = 25;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 21, id, NULL, 'Preferência', 10, 5 FROM no WHERE id = 7;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 26, id, 'Sua nota para o relógio', 'preferencia', 100, 1 FROM criterio_parametro WHERE id = 21;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 20, NULL, 0 FROM criterio_sub WHERE id = 26 UNION ALL SELECT id, 20, 40, NULL, 25 FROM criterio_sub WHERE id = 26 UNION ALL SELECT id, 40, 60, NULL, 50 FROM criterio_sub WHERE id = 26 UNION ALL SELECT id, 60, 80, NULL, 75 FROM criterio_sub WHERE id = 26 UNION ALL SELECT id, 80, 100, NULL, 100 FROM criterio_sub WHERE id = 26;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 22, id, NULL, 'Novidade e valor', 5, 6 FROM no WHERE id = 7;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 27, id, 'Novidade: dias desde a compra', 'dias_desde_compra', 60, 1 FROM criterio_parametro WHERE id = 22;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 15, NULL, 100 FROM criterio_sub WHERE id = 27 UNION ALL SELECT id, 15, 45, NULL, 80 FROM criterio_sub WHERE id = 27 UNION ALL SELECT id, 45, 120, NULL, 55 FROM criterio_sub WHERE id = 27 UNION ALL SELECT id, 120, 365, NULL, 40 FROM criterio_sub WHERE id = 27 UNION ALL SELECT id, 365, NULL, NULL, 30 FROM criterio_sub WHERE id = 27;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 28, id, 'Aproveitar o investimento: valor pago', 'valor_compra', 40, 2 FROM criterio_parametro WHERE id = 22;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 300, NULL, 30 FROM criterio_sub WHERE id = 28 UNION ALL SELECT id, 300, 800, NULL, 50 FROM criterio_sub WHERE id = 28 UNION ALL SELECT id, 800, 1500, NULL, 70 FROM criterio_sub WHERE id = 28 UNION ALL SELECT id, 1500, NULL, NULL, 90 FROM criterio_sub WHERE id = 28;
-- critérios de Solar
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 23, id, NULL, 'Luz', 25, 1 FROM no WHERE id = 8;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 29, id, 'Carga de luz (%)', 'energia', 100, 1 FROM criterio_parametro WHERE id = 23;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 30, NULL, 80 FROM criterio_sub WHERE id = 29 UNION ALL SELECT id, 30, 70, NULL, 65 FROM criterio_sub WHERE id = 29 UNION ALL SELECT id, 70, 100, NULL, 50 FROM criterio_sub WHERE id = 29;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 24, id, NULL, 'Revisão', 10, 2 FROM no WHERE id = 8;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 30, id, 'Revisão em dia', 'dias_ate_revisao', 100, 1 FROM criterio_parametro WHERE id = 24;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 30, NULL, 20 FROM criterio_sub WHERE id = 30 UNION ALL SELECT id, 30, 180, NULL, 60 FROM criterio_sub WHERE id = 30 UNION ALL SELECT id, 180, NULL, NULL, 100 FROM criterio_sub WHERE id = 30;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 25, id, NULL, 'Tempo sem uso', 30, 3 FROM no WHERE id = 8;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 31, id, 'Dias sem uso', 'dias_sem_uso', 100, 1 FROM criterio_parametro WHERE id = 25;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 1, NULL, 0 FROM criterio_sub WHERE id = 31 UNION ALL SELECT id, 1, 3, NULL, 30 FROM criterio_sub WHERE id = 31 UNION ALL SELECT id, 3, 7, NULL, 60 FROM criterio_sub WHERE id = 31 UNION ALL SELECT id, 7, 14, NULL, 80 FROM criterio_sub WHERE id = 31 UNION ALL SELECT id, 14, 30, NULL, 95 FROM criterio_sub WHERE id = 31 UNION ALL SELECT id, 30, NULL, NULL, 100 FROM criterio_sub WHERE id = 31;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 26, id, NULL, 'Equilíbrio de uso', 20, 4 FROM no WHERE id = 8;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 32, id, 'Uso nos últimos 30 dias, comparado com a média', 'uso_vs_media', 100, 1 FROM criterio_parametro WHERE id = 26;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 25, NULL, 100 FROM criterio_sub WHERE id = 32 UNION ALL SELECT id, 25, 60, NULL, 80 FROM criterio_sub WHERE id = 32 UNION ALL SELECT id, 60, 100, NULL, 60 FROM criterio_sub WHERE id = 32 UNION ALL SELECT id, 100, 140, NULL, 35 FROM criterio_sub WHERE id = 32 UNION ALL SELECT id, 140, 200, NULL, 15 FROM criterio_sub WHERE id = 32 UNION ALL SELECT id, 200, NULL, NULL, 0 FROM criterio_sub WHERE id = 32;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 27, id, NULL, 'Preferência', 10, 5 FROM no WHERE id = 8;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 33, id, 'Sua nota para o relógio', 'preferencia', 100, 1 FROM criterio_parametro WHERE id = 27;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 20, NULL, 0 FROM criterio_sub WHERE id = 33 UNION ALL SELECT id, 20, 40, NULL, 25 FROM criterio_sub WHERE id = 33 UNION ALL SELECT id, 40, 60, NULL, 50 FROM criterio_sub WHERE id = 33 UNION ALL SELECT id, 60, 80, NULL, 75 FROM criterio_sub WHERE id = 33 UNION ALL SELECT id, 80, 100, NULL, 100 FROM criterio_sub WHERE id = 33;
INSERT INTO criterio_parametro (id, escopo_no_id, escopo_relogio_id, nome, peso, ordem) SELECT 28, id, NULL, 'Novidade e valor', 5, 6 FROM no WHERE id = 8;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 34, id, 'Novidade: dias desde a compra', 'dias_desde_compra', 60, 1 FROM criterio_parametro WHERE id = 28;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 15, NULL, 100 FROM criterio_sub WHERE id = 34 UNION ALL SELECT id, 15, 45, NULL, 80 FROM criterio_sub WHERE id = 34 UNION ALL SELECT id, 45, 120, NULL, 55 FROM criterio_sub WHERE id = 34 UNION ALL SELECT id, 120, 365, NULL, 40 FROM criterio_sub WHERE id = 34 UNION ALL SELECT id, 365, NULL, NULL, 30 FROM criterio_sub WHERE id = 34;
INSERT INTO criterio_sub (id, parametro_id, nome, variavel, peso, ordem) SELECT 35, id, 'Aproveitar o investimento: valor pago', 'valor_compra', 40, 2 FROM criterio_parametro WHERE id = 28;
INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) SELECT id, 0, 300, NULL, 30 FROM criterio_sub WHERE id = 35 UNION ALL SELECT id, 300, 800, NULL, 50 FROM criterio_sub WHERE id = 35 UNION ALL SELECT id, 800, 1500, NULL, 70 FROM criterio_sub WHERE id = 35 UNION ALL SELECT id, 1500, NULL, NULL, 90 FROM criterio_sub WHERE id = 35;
