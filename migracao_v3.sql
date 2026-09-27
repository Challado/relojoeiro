-- Relógios 2, migração v3: modos de rodízio cadastráveis, o plano, os critérios por lugar e o cron (com a configuração
-- do sistema antigo). Aplique pela API (POST recurso=migracoes, acao=aplicar).
SET NAMES utf8mb4;

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
