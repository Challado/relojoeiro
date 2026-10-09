-- Relógios 2, migração v16: o banco no modelo relacional estrito. Toda ligação entre tabelas é uma chave estrangeira com a
-- regra do que acontece quando o registro de cima é apagado: o que é parte dele sai junto (ON DELETE CASCADE); a ligação
-- opcional (uma coluna que pode ficar vazia) se desfaz (ON DELETE SET NULL). As listas guardadas num campo só ("1,2,3")
-- viram tabelas; o que estava repetido sai; a configuração fica só com valores soltos. Os seus dados não mudam de sentido.
SET NAMES utf8mb4;

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
