-- Migração v17: o banco valida o que guarda (CHECK, NOT NULL, UNIQUE do SQL padrão) e a cópia dos documentos no banco
-- passa a ter três níveis (o sistema, o relógio e o próprio arquivo).
-- Aplique pela página Configuração (o botão aparece quando falta). Cada regra é uma restrição com nome (ck_<tabela>_<o quê>):
-- o banco recusa a gravação que não a cumpre, venha ela da tela, da API ou de quem mexe direto no banco.
-- (MySQL 8: uma regra não pode usar a coluna id nem uma coluna de chave estrangeira com ON DELETE SET NULL; por isso
-- essas colunas ficam de fora das regras.)

-- 1. a cópia de segurança no banco, por relógio e por arquivo (o do sistema é o DOCUMENTOS_COPIA_BANCO do config.php).
--    Os documentos que já têm a cópia ficam pedindo a cópia: nada do que já está no banco sai.
ALTER TABLE relogio ADD COLUMN copia_banco TINYINT NOT NULL DEFAULT 0 AFTER disponivel;
ALTER TABLE documento ADD COLUMN copia_banco TINYINT NOT NULL DEFAULT 0 AFTER no_banco;
UPDATE documento SET copia_banco = 1 WHERE no_banco = 1;

-- 2. os dados que não cabem nas regras, acertados antes (só o que não muda nada na prática)
UPDATE lancamento SET origem = 'manual' WHERE origem IS NULL OR origem NOT IN ('manual', 'rodizio', 'importado');
UPDATE plano SET origem = 'manual' WHERE origem NOT IN ('manual', 'sorteio');
UPDATE evento_personalizado SET dia_mes = NULL WHERE repeticao <> 'mensal';
UPDATE evento_personalizado SET intervalo_dias = NULL WHERE repeticao <> 'intervalo';
UPDATE evento_personalizado SET data_inicio = NULL WHERE repeticao NOT IN ('uma', 'intervalo');
UPDATE lancamento_tipo SET exclusiva = 0 WHERE formato <> 'sessao';
UPDATE lancamento_tipo SET mede_gasto = 0 WHERE formato <> 'valor';
UPDATE lancamento_tipo SET fecha_as = NULL WHERE formato <> 'sessao';

-- 3. a origem do lançamento e a do dia do plano: uma lista fechada, sempre preenchida
ALTER TABLE lancamento MODIFY COLUMN origem ENUM('manual','rodizio','importado') NOT NULL DEFAULT 'manual';
ALTER TABLE plano MODIFY COLUMN origem ENUM('sorteio','manual') NOT NULL;

-- 4. o modo em uso: no máximo um. A marca é 1 no modo em uso e vazia (NULL) nos outros; a chave única não deixa haver
--    dois 1 (e aceita quantos vazios houver, como manda o SQL padrão)
ALTER TABLE modo MODIFY COLUMN ativo TINYINT NULL DEFAULT NULL;
UPDATE modo SET ativo = NULL WHERE ativo IS NOT NULL AND ativo <> 1;
ALTER TABLE modo ADD CONSTRAINT ck_modo_ativo CHECK (ativo = 1);
ALTER TABLE modo ADD UNIQUE INDEX modo_ativo_idx (ativo);

-- 5. o arquivo de cada documento é só dele
ALTER TABLE documento ADD UNIQUE INDEX documento_arquivo_idx (arquivo);

-- 6. as regras de cada tabela
-- a árvore e os relógios
ALTER TABLE no ADD CONSTRAINT ck_no_nome CHECK (TRIM(nome) <> '');
ALTER TABLE relogio ADD CONSTRAINT ck_relogio_nome CHECK (TRIM(nome) <> '');
ALTER TABLE relogio ADD CONSTRAINT ck_relogio_disponivel CHECK (disponivel IN (0, 1));
ALTER TABLE relogio ADD CONSTRAINT ck_relogio_copia_banco CHECK (copia_banco IN (0, 1));
ALTER TABLE foto ADD CONSTRAINT ck_foto_tipo CHECK (tipo LIKE 'image/%');

-- os campos do cadastro
ALTER TABLE campo ADD CONSTRAINT ck_campo_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '');
ALTER TABLE campo_opcao ADD CONSTRAINT ck_campo_opcao_ordem CHECK (ordem >= 1);
ALTER TABLE campo_opcao ADD CONSTRAINT ck_campo_opcao_valor CHECK (TRIM(valor) <> '');

-- os tipos de lançamento e os lançamentos
ALTER TABLE lancamento_tipo ADD CONSTRAINT ck_lancamento_tipo_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '');
ALTER TABLE lancamento_tipo ADD CONSTRAINT ck_lancamento_tipo_exclusiva CHECK (exclusiva IN (0, 1) AND (exclusiva = 0 OR formato = 'sessao'));
ALTER TABLE lancamento_tipo ADD CONSTRAINT ck_lancamento_tipo_mede_gasto CHECK (mede_gasto IN (0, 1) AND (mede_gasto = 0 OR formato = 'valor'));
ALTER TABLE lancamento_tipo ADD CONSTRAINT ck_lancamento_tipo_fecha_as CHECK (fecha_as IS NULL OR formato = 'sessao');
ALTER TABLE lancamento ADD CONSTRAINT ck_lancamento_periodo CHECK (fim IS NULL OR fim >= inicio);
ALTER TABLE medicao ADD CONSTRAINT ck_medicao_taxa CHECK (taxa BETWEEN 0 AND 100);
ALTER TABLE medicao ADD CONSTRAINT ck_medicao_horas CHECK (horas_pulso >= 0 AND horas_guardado >= 0 AND peso_horas >= 0);
ALTER TABLE medicao ADD CONSTRAINT ck_medicao_periodo CHECK (fim >= inicio);
ALTER TABLE medicao ADD CONSTRAINT ck_medicao_usada CHECK (usada IN (0, 1));

-- as fórmulas e os avisos
ALTER TABLE formula ADD CONSTRAINT ck_formula_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '' AND TRIM(expressao) <> '');
ALTER TABLE aviso ADD CONSTRAINT ck_aviso_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '' AND TRIM(texto) <> '' AND TRIM(expressao) <> '');
ALTER TABLE aviso ADD CONSTRAINT ck_aviso_antecedencia CHECK (antecedencia_dias BETWEEN 0 AND 3650);
ALTER TABLE aviso ADD CONSTRAINT ck_aviso_ativo CHECK (ativo IN (0, 1));
ALTER TABLE aviso ADD CONSTRAINT ck_aviso_simula_horas CHECK (simula_horas IS NULL OR simula_horas BETWEEN 0 AND 48);

-- os critérios da nota (pesos em %, notas de 0 a 100; uma faixa é de número, "de" até "até", ou de categoria)
ALTER TABLE criterio_parametro ADD CONSTRAINT ck_criterio_parametro_nome CHECK (TRIM(nome) <> '');
ALTER TABLE criterio_parametro ADD CONSTRAINT ck_criterio_parametro_peso CHECK (peso BETWEEN 0 AND 100);
ALTER TABLE criterio_parametro ADD CONSTRAINT ck_criterio_parametro_escopo CHECK (escopo_no_id IS NULL OR escopo_relogio_id IS NULL);
ALTER TABLE criterio_sub ADD CONSTRAINT ck_criterio_sub_textos CHECK (TRIM(nome) <> '' AND TRIM(variavel) <> '');
ALTER TABLE criterio_sub ADD CONSTRAINT ck_criterio_sub_peso CHECK (peso BETWEEN 0 AND 100);
ALTER TABLE criterio_faixa ADD CONSTRAINT ck_criterio_faixa_nota CHECK (nota BETWEEN 0 AND 100);
ALTER TABLE criterio_faixa ADD CONSTRAINT ck_criterio_faixa_tipo CHECK ((categoria IS NULL AND de IS NOT NULL) OR (categoria IS NOT NULL AND de IS NULL AND ate IS NULL));
ALTER TABLE criterio_faixa ADD CONSTRAINT ck_criterio_faixa_intervalo CHECK (de IS NULL OR (de >= 0 AND (ate IS NULL OR ate > de)));

-- os modos de rodízio
ALTER TABLE modo ADD CONSTRAINT ck_modo_nome CHECK (TRIM(nome) <> '');
ALTER TABLE modo ADD CONSTRAINT ck_modo_ciclo CHECK (ciclo IN (0, 1));
ALTER TABLE modo ADD CONSTRAINT ck_modo_escala_dias CHECK (escala_dias IS NULL OR escala_dias BETWEEN 7 AND 730);
ALTER TABLE modo_bloco ADD CONSTRAINT ck_modo_bloco_nome CHECK (TRIM(nome) <> '');
ALTER TABLE modo_bloco_dia ADD CONSTRAINT ck_modo_bloco_dia_dia CHECK (dia BETWEEN 1 AND 7);

-- os eventos personalizados (cada repetição com o que ela usa, e só isso)
ALTER TABLE evento_personalizado ADD CONSTRAINT ck_evento_personalizado_nome CHECK (TRIM(nome) <> '');
ALTER TABLE evento_personalizado ADD CONSTRAINT ck_evento_personalizado_ativo CHECK (ativo IN (0, 1));
ALTER TABLE evento_personalizado ADD CONSTRAINT ck_evento_personalizado_dia_mes CHECK ((repeticao = 'mensal' AND dia_mes BETWEEN 1 AND 31) OR (repeticao <> 'mensal' AND dia_mes IS NULL));
ALTER TABLE evento_personalizado ADD CONSTRAINT ck_evento_personalizado_intervalo CHECK ((repeticao = 'intervalo' AND intervalo_dias BETWEEN 1 AND 3650) OR (repeticao <> 'intervalo' AND intervalo_dias IS NULL));
ALTER TABLE evento_personalizado ADD CONSTRAINT ck_evento_personalizado_data CHECK ((repeticao IN ('uma', 'intervalo') AND data_inicio IS NOT NULL) OR (repeticao NOT IN ('uma', 'intervalo') AND data_inicio IS NULL));
ALTER TABLE evento_dia ADD CONSTRAINT ck_evento_dia_dia CHECK (dia BETWEEN 1 AND 7);

-- os canais dos avisos
ALTER TABLE canal_aviso ADD CONSTRAINT ck_canal_aviso_marcas CHECK (envia IN (0, 1) AND propria IN (0, 1));

-- os documentos
ALTER TABLE documento_categoria ADD CONSTRAINT ck_documento_categoria_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '');
ALTER TABLE documento ADD CONSTRAINT ck_documento_titulo CHECK (TRIM(titulo) <> '' AND TRIM(nome) <> '');
ALTER TABLE documento ADD CONSTRAINT ck_documento_tamanho CHECK (tamanho > 0);
ALTER TABLE documento ADD CONSTRAINT ck_documento_copia CHECK (no_banco IN (0, 1) AND copia_banco IN (0, 1));
ALTER TABLE documento ADD CONSTRAINT ck_documento_hash CHECK (hash IS NULL OR LENGTH(hash) = 64);
ALTER TABLE documento_parte ADD CONSTRAINT ck_documento_parte_parte CHECK (parte >= -1);

-- o resto
ALTER TABLE config ADD CONSTRAINT ck_config_chave CHECK (TRIM(chave) <> '');
ALTER TABLE usuario ADD CONSTRAINT ck_usuario_textos CHECK (TRIM(login) <> '' AND senha_hash <> '');
ALTER TABLE cron_execucao ADD CONSTRAINT ck_cron_execucao_periodo CHECK (fim IS NULL OR fim >= inicio);
ALTER TABLE cron_execucao ADD CONSTRAINT ck_cron_execucao_marcas CHECK (teve_erro IN (0, 1) AND teve_atividade IN (0, 1) AND duracao_ms >= 0);
ALTER TABLE agenda_evento ADD CONSTRAINT ck_agenda_evento_textos CHECK (TRIM(chave) <> '' AND TRIM(google_id) <> '');

-- a marca de aplicada, por último (se parar no meio, continua pendente; as regras podem rodar de novo)
INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v17', '1');
