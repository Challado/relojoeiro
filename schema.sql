-- Relojoeiro: o banco. O código só tem mecanismos; o conteúdo é cadastro.
-- Instala no banco do config.php (vazio), seja ele MySQL/MariaDB, PostgreSQL, SQLite ou Firebird: pela API
-- (recurso=instalacao, acao=instalar) ou php instalar.php. Um arquivo só para os quatro bancos: a estrutura no dialeto do
-- MySQL, que o banco.php traduz. Aqui só há a estrutura: o conjunto inicial de cada coleção (a árvore de grupos, os campos,
-- as fórmulas, os avisos, os critérios, os modos) está no modelo.json, que o sistema aplica ao criar cada coleção.
--
-- Várias pessoas, cada uma com as suas coleções (desde a migração v25). As regras:
--   - tudo amarrado por chave primária e chave estrangeira, e toda chave estrangeira apaga em cascata: apagou o usuário, vão
--     as coleções e as árvores dele, e tudo abaixo; nada fica solto (a referência opcional que só desfaz o vínculo, quando o
--     outro lado sai, é uma tabela de ligação: acessorio_relogio, evento_relogio, modo_bloco_relogio, plano_bloco);
--   - 3ª forma normal: o que dá para deduzir subindo uma chave estrangeira não se repete (o lançamento chega à coleção pelo
--     relógio; o campo chega ao dono pela árvore; o subgrupo não guarda a raiz nem o dono);
--   - o dono de uma árvore fica só na raiz dela (o grupo sem pai); a coleção aponta para a raiz da árvore que usa, e uma
--     árvore serve a várias coleções do mesmo dono. O cadastro (campos, tipos de lançamento, fórmulas, avisos, critérios de
--     grupo, categorias de documento) fica pendurado na árvore; os relógios, o uso, o plano e a configuração, na coleção.
SET NAMES utf8mb4;

-- =====================================================================================================================
-- Contas e acesso
-- =====================================================================================================================

-- quem entra no sistema: o login e o hash da senha (HTTP Basic; a API confere com password_verify). O papel master vê e
-- faz tudo; o comum vê as coleções dele e as que abriram para ele. expira_em: a conta demo (o cadastro aberto) vence nesse
-- momento e o cron a apaga; vazio = conta oficial
CREATE TABLE usuario (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    login          VARCHAR(60) NOT NULL UNIQUE,
    email          VARCHAR(200) NULL UNIQUE,
    senha_hash     VARCHAR(255) NOT NULL,
    papel          ENUM('master','comum') NOT NULL DEFAULT 'comum',
    idioma         VARCHAR(10) NULL,                    -- vazio: o da coleção
    fuso           VARCHAR(60) NULL,                    -- o fuso horário da pessoa ("America/Sao_Paulo"); vazio: o da coleção, senão o do sistema
    expira_em      DATETIME NULL,
    bloqueado      TINYINT NOT NULL DEFAULT 0,
    criado         DATETIME NOT NULL,
    ultimo_acesso  DATETIME NULL,
    CONSTRAINT ck_usuario_textos CHECK (TRIM(login) <> '' AND senha_hash <> ''),
    CONSTRAINT ck_usuario_email CHECK (email IS NULL OR email LIKE '%_@_%'),
    CONSTRAINT ck_usuario_bloqueado CHECK (bloqueado IN (0, 1))
) ENGINE=InnoDB;

-- a árvore de grupos: cada relógio fica num grupo; cada grupo pode ter campos, tipos de lançamento, fórmulas e avisos. A raiz
-- (sem pai) é a árvore inteira e guarda o dono; os subgrupos apontam sempre para o pai
CREATE TABLE no (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    pai_id   INT NULL,
    dono_id  INT NULL,
    nome     VARCHAR(80) NOT NULL,
    ordem    INT NOT NULL DEFAULT 0,
    INDEX (pai_id),
    FOREIGN KEY (pai_id) REFERENCES no(id) ON DELETE CASCADE,
    FOREIGN KEY (dono_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_no_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_no_raiz CHECK ((pai_id IS NULL AND dono_id IS NOT NULL) OR (pai_id IS NOT NULL AND dono_id IS NULL))
) ENGINE=InnoDB;

-- a coleção: um dono, um nome e a raiz da árvore que ela usa (uma árvore do mesmo dono: a API confere)
CREATE TABLE colecao (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    dono_id  INT NOT NULL,
    no_id    INT NOT NULL,
    nome     VARCHAR(120) NOT NULL,
    criada   DATETIME NOT NULL,
    UNIQUE (dono_id, nome),
    FOREIGN KEY (dono_id) REFERENCES usuario(id) ON DELETE CASCADE,
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_colecao_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

-- os membros de uma coleção (o dono não entra aqui: é o colecao.dono_id)
CREATE TABLE colecao_usuario (
    colecao_id  INT NOT NULL,
    usuario_id  INT NOT NULL,
    desde       DATETIME NOT NULL,
    PRIMARY KEY (colecao_id, usuario_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- o acesso a todas as coleções de um dono, também as que ele criar depois
CREATE TABLE acesso_dono (
    dono_id     INT NOT NULL,
    usuario_id  INT NOT NULL,
    desde       DATETIME NOT NULL,
    PRIMARY KEY (dono_id, usuario_id),
    FOREIGN KEY (dono_id) REFERENCES usuario(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_acesso_dono_outro CHECK (dono_id <> usuario_id)
) ENGINE=InnoDB;

-- o convite para uma coleção ou para tudo de um dono (um ou outro). O do dono nasce aprovado; o de um membro fica pendente
-- até o dono aprovar. O convidado aceita pelo link (o token só existe no e-mail; aqui, o hash). Vencido, o cron apaga
CREATE TABLE convite (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    email          VARCHAR(200) NOT NULL,
    convidado_por  INT NOT NULL,
    colecao_id     INT NULL,
    dono_id        INT NULL,
    estado         ENUM('pendente','aprovado') NOT NULL,
    token_hash     CHAR(64) NOT NULL UNIQUE,
    criado         DATETIME NOT NULL,
    expira         DATETIME NOT NULL,
    FOREIGN KEY (convidado_por) REFERENCES usuario(id) ON DELETE CASCADE,
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    FOREIGN KEY (dono_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_convite_alvo CHECK ((colecao_id IS NULL AND dono_id IS NOT NULL) OR (colecao_id IS NOT NULL AND dono_id IS NULL)),
    CONSTRAINT ck_convite_email CHECK (email LIKE '%_@_%'),
    CONSTRAINT ck_convite_prazo CHECK (expira > criado)
) ENGINE=InnoDB;

-- o pedido de acesso de um usuário a uma coleção ou a tudo de um dono (um ou outro); o dono (ou o master) aprova
CREATE TABLE pedido (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    colecao_id  INT NULL,
    dono_id     INT NULL,
    mensagem    VARCHAR(500) NULL,
    criado      DATETIME NOT NULL,
    expira      DATETIME NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    FOREIGN KEY (dono_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_pedido_alvo CHECK ((colecao_id IS NULL AND dono_id IS NOT NULL) OR (colecao_id IS NOT NULL AND dono_id IS NULL)),
    CONSTRAINT ck_pedido_prazo CHECK (expira > criado)
) ENGINE=InnoDB;

-- os tokens de API de cada usuário (o token só aparece quando é criado; aqui, o hash)
CREATE TABLE api_token (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    criado      DATETIME NOT NULL,
    ultimo_uso  DATETIME NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_api_token_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

-- o link de "esqueci a senha" (vale pouco tempo; aqui, o hash do token)
CREATE TABLE usuario_token (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    criado      DATETIME NOT NULL,
    expira      DATETIME NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_usuario_token_prazo CHECK (expira > criado)
) ENGINE=InnoDB;

-- os aparelhos lembrados de um usuário (o aplicativo no celular): a chave gravada no aparelho (um cookie) entra sem a senha
CREATE TABLE usuario_aparelho (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    nome        VARCHAR(200) NULL,
    chave_hash  CHAR(64) NOT NULL UNIQUE,
    criado      DATETIME NOT NULL,
    ultimo_uso  DATETIME NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- os aparelhos (navegadores) de um usuário inscritos no Web Push
CREATE TABLE push_inscricao (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id     INT NOT NULL,
    endpoint       VARCHAR(1000) NOT NULL,
    endpoint_hash  CHAR(64) NOT NULL UNIQUE,
    p256dh         VARCHAR(200) NOT NULL,
    auth           VARCHAR(100) NOT NULL,
    aparelho       VARCHAR(200) NULL,
    criada         DATETIME NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
    CONSTRAINT ck_push_inscricao_textos CHECK (TRIM(endpoint) <> '' AND TRIM(p256dh) <> '' AND TRIM(auth) <> '')
) ENGINE=InnoDB;

-- os valores do sistema inteiro (as marcas do cron, da cópia de segurança, do aleatório e das migrações)
CREATE TABLE sistema (
    chave  VARCHAR(80) PRIMARY KEY,
    valor  TEXT NOT NULL,
    CONSTRAINT ck_sistema_chave CHECK (TRIM(chave) <> '')
) ENGINE=InnoDB;

-- =====================================================================================================================
-- O cadastro da árvore (vale para todas as coleções que usam a árvore)
-- =====================================================================================================================

-- os campos do cadastro de um relógio, por grupo (a raiz: todos). O identificador não se repete na árvore (a API confere)
CREATE TABLE campo (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL,
    nome           VARCHAR(120) NOT NULL,
    tipo           ENUM('inteiro','decimal','sim_nao','data','lista','texto') NOT NULL,
    unidade        VARCHAR(20) NOT NULL DEFAULT '',
    padrao         VARCHAR(200) NULL,
    no_id          INT NOT NULL,
    ordem          INT NOT NULL DEFAULT 0,
    so_desejado    TINYINT NOT NULL DEFAULT 0,
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_campo_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> ''),
    CONSTRAINT ck_campo_so_desejado CHECK (so_desejado IN (0, 1))
) ENGINE=InnoDB;

-- as opções de um campo do tipo lista
CREATE TABLE campo_opcao (
    campo_id  INT NOT NULL,
    ordem     INT NOT NULL,
    valor     VARCHAR(120) NOT NULL,
    PRIMARY KEY (campo_id, ordem),
    UNIQUE (campo_id, valor),
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE,
    CONSTRAINT ck_campo_opcao_ordem CHECK (ordem >= 1),
    CONSTRAINT ck_campo_opcao_valor CHECK (TRIM(valor) <> '')
) ENGINE=InnoDB;

-- os tipos de lançamento (pulso, carga, corda, winder, sol...), por grupo
CREATE TABLE lancamento_tipo (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL,
    nome           VARCHAR(120) NOT NULL,
    formato        ENUM('instantaneo','valor','sessao') NOT NULL,
    unidade        VARCHAR(20) NOT NULL DEFAULT '',
    fecha_as       TIME NULL,
    no_id          INT NOT NULL,
    ordem          INT NOT NULL DEFAULT 0,
    exclusiva      TINYINT NOT NULL DEFAULT 0,
    mede_gasto     TINYINT NOT NULL DEFAULT 0,
    marcha         VARCHAR(10) NULL,
    condicao       VARCHAR(500) NULL,
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_lancamento_tipo_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> ''),
    CONSTRAINT ck_lancamento_tipo_exclusiva CHECK (exclusiva IN (0, 1) AND (exclusiva = 0 OR formato = 'sessao')),
    CONSTRAINT ck_lancamento_tipo_mede_gasto CHECK (mede_gasto IN (0, 1) AND (mede_gasto = 0 OR formato = 'valor')),
    CONSTRAINT ck_lancamento_tipo_fecha_as CHECK (fecha_as IS NULL OR formato = 'sessao'),
    CONSTRAINT ck_lancamento_tipo_marcha CHECK (marcha IS NULL OR (marcha = 'desvio' AND formato = 'valor') OR (marcha = 'acerto' AND formato = 'instantaneo'))
) ENGINE=InnoDB;

-- as fórmulas: uma versão por grupo (vale a do grupo mais perto do relógio)
CREATE TABLE formula (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL,
    nome           VARCHAR(120) NOT NULL,
    expressao      TEXT NOT NULL,
    unidade        VARCHAR(20) NOT NULL DEFAULT '',
    no_id          INT NOT NULL,
    UNIQUE (identificador, no_id),
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_formula_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '' AND TRIM(expressao) <> '')
) ENGINE=InnoDB;

-- os totais (da árvore, presos à raiz dela, como o cadastro): cada um é uma expressão do motor que olha para os relógios
-- mostrados de uma vez (CONTA, SOMA, MEDIA, AGRUPADO, FAIXAS...); vai para o rodapé da tabela da página Hoje, embaixo da
-- coluna dele (rodape: só com coluna), e/ou para a página de totais (pagina), num quadro estreito ou largo
CREATE TABLE total (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    no_id      INT NOT NULL,
    coluna     VARCHAR(20) NULL,
    nome       VARCHAR(120) NOT NULL,
    expressao  TEXT NOT NULL,
    rodape     TINYINT NOT NULL DEFAULT 0,
    pagina     TINYINT NOT NULL DEFAULT 1,
    largura    ENUM('estreito','largo') NOT NULL DEFAULT 'estreito',
    formato    ENUM('numero','inteiro','decimal','moeda','porcentagem','data','dias') NOT NULL DEFAULT 'numero',
    ordem      INT NOT NULL DEFAULT 0,
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_total_textos CHECK (TRIM(nome) <> '' AND TRIM(expressao) <> ''),
    CONSTRAINT ck_total_lugares CHECK (rodape IN (0, 1) AND pagina IN (0, 1) AND (rodape = 0 OR coluna IS NOT NULL)),
    CONSTRAINT ck_total_coluna CHECK (coluna IS NULL OR coluna IN ('codigo','nome','tipo','estado','carga','ultimo','manut','em','fazer','encostado','compra','valor'))
) ENGINE=InnoDB;

-- os avisos: uma versão por grupo, como as fórmulas; o tipo de lançamento que resolve o aviso é opcional (saindo o tipo,
-- o aviso fica, sem ele)
CREATE TABLE aviso (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    identificador      VARCHAR(40) NOT NULL,
    nome               VARCHAR(120) NOT NULL,
    expressao          TEXT NOT NULL,
    antecedencia_dias  DECIMAL(8,2) NOT NULL DEFAULT 0,
    texto              VARCHAR(300) NOT NULL,
    ativo              TINYINT NOT NULL DEFAULT 1,
    no_id              INT NOT NULL,
    escala             ENUM('nao','uso','sempre') NOT NULL DEFAULT 'nao',
    simula_valor       DECIMAL(12,2) NULL,
    simula_horas       DECIMAL(6,2) NULL,
    agenda             ENUM('janela','sempre') NOT NULL DEFAULT 'janela',
    condicao           VARCHAR(500) NULL,
    resolve_tipo_id    INT NULL,
    na_hora            TINYINT NOT NULL DEFAULT 0,
    UNIQUE (identificador, no_id),
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    FOREIGN KEY (resolve_tipo_id) REFERENCES lancamento_tipo(id) ON DELETE SET NULL,
    CONSTRAINT ck_aviso_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '' AND TRIM(texto) <> '' AND TRIM(expressao) <> ''),
    CONSTRAINT ck_aviso_antecedencia CHECK (antecedencia_dias BETWEEN 0 AND 3650),
    CONSTRAINT ck_aviso_ativo CHECK (ativo IN (0, 1)),
    CONSTRAINT ck_aviso_simula_horas CHECK (simula_horas IS NULL OR simula_horas BETWEEN 0 AND 48),
    CONSTRAINT ck_aviso_na_hora CHECK (na_hora IN (0, 1))
) ENGINE=InnoDB;

-- as categorias dos documentos (manual, nota fiscal...), da árvore
CREATE TABLE documento_categoria (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL,
    nome           VARCHAR(120) NOT NULL,
    ordem          INT NOT NULL DEFAULT 0,
    no_id          INT NOT NULL,
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_documento_categoria_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '')
) ENGINE=InnoDB;

-- as famílias de arquivo que uma categoria aceita (nenhuma: todas)
CREATE TABLE documento_categoria_aceita (
    categoria_id  INT NOT NULL,
    familia       ENUM('imagem','video','audio','pdf','xml') NOT NULL,
    PRIMARY KEY (categoria_id, familia),
    FOREIGN KEY (categoria_id) REFERENCES documento_categoria(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================================================================
-- A coleção: os relógios e o uso
-- =====================================================================================================================

CREATE TABLE relogio (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id   INT NOT NULL,
    nome         VARCHAR(120) NOT NULL,
    apelido      VARCHAR(120) NULL,                -- opcional: aparece no lugar do nome ("o do pai")
    no_id        INT NOT NULL,                     -- o grupo (da árvore da coleção: a API confere)
    disponivel   TINYINT NOT NULL DEFAULT 1,
    criado       DATETIME NOT NULL,
    copia_banco  TINYINT NOT NULL DEFAULT 0,
    posse        VARCHAR(12) NOT NULL DEFAULT 'colecao',
    INDEX (colecao_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_relogio_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_relogio_apelido CHECK (apelido IS NULL OR TRIM(apelido) <> ''),
    CONSTRAINT ck_relogio_disponivel CHECK (disponivel IN (0, 1)),
    CONSTRAINT ck_relogio_copia_banco CHECK (copia_banco IN (0, 1)),
    CONSTRAINT ck_relogio_posse CHECK (posse IN ('colecao', 'desejado', 'emprestado', 'vendido', 'arquivado')),
    CONSTRAINT ck_relogio_posse_disponivel CHECK (posse = 'colecao' OR disponivel = 0)
) ENGINE=InnoDB;

CREATE TABLE foto (
    relogio_id  INT PRIMARY KEY,
    tipo        VARCHAR(40) NOT NULL,
    dados       MEDIUMBLOB NOT NULL,
    atualizado  DATETIME NOT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    CONSTRAINT ck_foto_tipo CHECK (tipo LIKE 'image/%')
) ENGINE=InnoDB;

CREATE TABLE campo_valor (
    relogio_id  INT NOT NULL,
    campo_id    INT NOT NULL,
    valor       TEXT NOT NULL,
    PRIMARY KEY (relogio_id, campo_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campo(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE lancamento (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id  INT NOT NULL,
    tipo_id     INT NOT NULL,
    inicio      DATETIME NOT NULL,
    fim         DATETIME NULL,
    valor       DECIMAL(12,2) NULL,
    estado      VARCHAR(10) NULL,
    posicao     VARCHAR(20) NULL,
    origem      ENUM('manual','rodizio','importado') NOT NULL DEFAULT 'manual',
    criado      DATETIME NOT NULL,
    INDEX (relogio_id, tipo_id, inicio),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (tipo_id) REFERENCES lancamento_tipo(id) ON DELETE CASCADE,
    CONSTRAINT ck_lancamento_periodo CHECK (fim IS NULL OR fim >= inicio),
    CONSTRAINT ck_lancamento_estado CHECK (estado IS NULL OR estado IN ('uso', 'repouso')),
    CONSTRAINT ck_lancamento_posicao CHECK (posicao IS NULL OR posicao IN ('pulso', 'misto', 'mostrador_cima', 'mostrador_baixo', 'coroa_cima', 'coroa_baixo', 'coroa_esquerda', 'coroa_direita', 'winder'))
) ENGINE=InnoDB;

-- o gasto medido entre duas leituras (chega ao relógio pelo lançamento)
CREATE TABLE medicao (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    lancamento_id   INT NOT NULL,
    medida          ENUM('uso','repouso') NOT NULL,
    taxa            DECIMAL(9,3) NOT NULL,
    de_valor        DECIMAL(12,2) NOT NULL,
    ate_valor       DECIMAL(12,2) NOT NULL,
    inicio          DATETIME NOT NULL,
    fim             DATETIME NOT NULL,
    horas_pulso     DECIMAL(9,2) NOT NULL,
    horas_guardado  DECIMAL(9,2) NOT NULL,
    peso_horas      DECIMAL(9,2) NOT NULL,
    usada           TINYINT NOT NULL DEFAULT 1,
    criado          DATETIME NOT NULL,
    INDEX (lancamento_id, medida, fim),
    FOREIGN KEY (lancamento_id) REFERENCES lancamento(id) ON DELETE CASCADE,
    CONSTRAINT ck_medicao_taxa CHECK (taxa BETWEEN 0 AND 100),
    CONSTRAINT ck_medicao_horas CHECK (horas_pulso >= 0 AND horas_guardado >= 0 AND peso_horas >= 0),
    CONSTRAINT ck_medicao_periodo CHECK (fim >= inicio),
    CONSTRAINT ck_medicao_usada CHECK (usada IN (0, 1))
) ENGINE=InnoDB;

-- os documentos de cada relógio (o arquivo na pasta dos documentos e, se pedido, em pedaços no banco)
CREATE TABLE documento (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id    INT NOT NULL,
    categoria_id  INT NOT NULL,
    titulo        VARCHAR(200) NOT NULL,
    data          DATE NULL,
    descricao     TEXT NULL,
    nome          VARCHAR(255) NOT NULL,
    tipo          VARCHAR(100) NOT NULL,
    tamanho       BIGINT NOT NULL,
    arquivo       VARCHAR(200) NOT NULL,
    miniatura     VARCHAR(200) NULL,
    criado        DATETIME NOT NULL,
    no_banco      TINYINT NOT NULL DEFAULT 0,
    hash          VARCHAR(64) NULL,
    copia_banco   TINYINT NOT NULL DEFAULT 0,
    INDEX (relogio_id, categoria_id),
    UNIQUE INDEX (arquivo),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES documento_categoria(id) ON DELETE CASCADE,
    CONSTRAINT ck_documento_titulo CHECK (TRIM(titulo) <> '' AND TRIM(nome) <> ''),
    CONSTRAINT ck_documento_tamanho CHECK (tamanho > 0),
    CONSTRAINT ck_documento_copia CHECK (no_banco IN (0, 1) AND copia_banco IN (0, 1)),
    CONSTRAINT ck_documento_hash CHECK (hash IS NULL OR LENGTH(hash) = 64)
) ENGINE=InnoDB;

CREATE TABLE documento_parte (
    documento_id  INT NOT NULL,
    parte         INT NOT NULL,
    dados         LONGBLOB NOT NULL,
    PRIMARY KEY (documento_id, parte),
    FOREIGN KEY (documento_id) REFERENCES documento(id) ON DELETE CASCADE,
    CONSTRAINT ck_documento_parte_parte CHECK (parte >= -1)
) ENGINE=InnoDB;

-- os acessórios (pulseiras, caixas, ferramentas...) da coleção; o de um relógio, pela ligação
CREATE TABLE acessorio (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id  INT NOT NULL,
    nome        VARCHAR(120) NOT NULL,
    tipo        VARCHAR(20) NOT NULL DEFAULT 'pulseira',
    observacao  VARCHAR(1000) NULL,
    criado      DATETIME NOT NULL,
    INDEX (colecao_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_acessorio_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_acessorio_tipo CHECK (tipo IN ('pulseira', 'caixa', 'estojo', 'ferramenta', 'carregador', 'peca', 'outro'))
) ENGINE=InnoDB;

CREATE TABLE acessorio_relogio (
    acessorio_id  INT PRIMARY KEY,
    relogio_id    INT NOT NULL,
    INDEX (relogio_id),
    FOREIGN KEY (acessorio_id) REFERENCES acessorio(id) ON DELETE CASCADE,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- os critérios de escolha: um conjunto por lugar, um grupo (a raiz: todos) ou um relógio (um ou outro)
CREATE TABLE criterio_parametro (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    escopo_no_id       INT NULL,
    escopo_relogio_id  INT NULL,
    nome               VARCHAR(80) NOT NULL,
    peso               DECIMAL(6,2) NOT NULL,
    ordem              INT NOT NULL DEFAULT 0,
    FOREIGN KEY (escopo_no_id) REFERENCES no(id) ON DELETE CASCADE,
    FOREIGN KEY (escopo_relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    CONSTRAINT ck_criterio_parametro_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_criterio_parametro_peso CHECK (peso BETWEEN 0 AND 100),
    CONSTRAINT ck_criterio_parametro_escopo CHECK ((escopo_no_id IS NULL AND escopo_relogio_id IS NOT NULL) OR (escopo_no_id IS NOT NULL AND escopo_relogio_id IS NULL))
) ENGINE=InnoDB;

CREATE TABLE criterio_sub (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    parametro_id  INT NOT NULL,
    nome          VARCHAR(80) NOT NULL,
    variavel      VARCHAR(40) NOT NULL,
    peso          DECIMAL(6,2) NOT NULL,
    ordem         INT NOT NULL DEFAULT 0,
    FOREIGN KEY (parametro_id) REFERENCES criterio_parametro(id) ON DELETE CASCADE,
    CONSTRAINT ck_criterio_sub_textos CHECK (TRIM(nome) <> '' AND TRIM(variavel) <> ''),
    CONSTRAINT ck_criterio_sub_peso CHECK (peso BETWEEN 0 AND 100)
) ENGINE=InnoDB;

CREATE TABLE criterio_faixa (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    sub_id     INT NOT NULL,
    de         DECIMAL(14,2) NULL,
    ate        DECIMAL(14,2) NULL,
    categoria  VARCHAR(80) NULL,
    nota       DECIMAL(6,2) NOT NULL,
    FOREIGN KEY (sub_id) REFERENCES criterio_sub(id) ON DELETE CASCADE,
    CONSTRAINT ck_criterio_faixa_nota CHECK (nota BETWEEN 0 AND 100),
    CONSTRAINT ck_criterio_faixa_tipo CHECK ((categoria IS NULL AND de IS NOT NULL) OR (categoria IS NOT NULL AND de IS NULL AND ate IS NULL)),
    CONSTRAINT ck_criterio_faixa_intervalo CHECK (de IS NULL OR (de >= 0 AND (ate IS NULL OR ate > de)))
) ENGINE=InnoDB;

-- a configuração de cada coleção (horários, limites, idioma...): valores soltos
CREATE TABLE config (
    colecao_id  INT NOT NULL,
    chave       VARCHAR(80) NOT NULL,
    valor       TEXT NOT NULL,
    PRIMARY KEY (colecao_id, chave),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_config_chave CHECK (TRIM(chave) <> '')
) ENGINE=InnoDB;

-- =====================================================================================================================
-- O rodízio e o plano
-- =====================================================================================================================

-- os modos de rodízio: cada um com blocos de dias; cada bloco sorteia dentro de um grupo, um por bloco ou um por dia, ou
-- usa um relógio fixo. No máximo um modo em uso por coleção (ativo = 1)
CREATE TABLE modo (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id   INT NOT NULL,
    nome         VARCHAR(80) NOT NULL,
    tipo         ENUM('semana','semana_fds','dia_semana','aleatorio','escala','personalizado') NOT NULL DEFAULT 'personalizado',
    selecao      ENUM('inteligente','ponderado','aleatorio','fifo') NOT NULL DEFAULT 'ponderado',
    ordem        INT NOT NULL DEFAULT 0,
    escala_dias  INT NULL,
    semanas      INT NOT NULL DEFAULT 1,
    ciclo        TINYINT NOT NULL DEFAULT 0,
    ativo        TINYINT NULL DEFAULT NULL,
    roteiro      TEXT NULL,
    UNIQUE (colecao_id, ativo),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_modo_ativo CHECK (ativo = 1),
    CONSTRAINT ck_modo_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_modo_ciclo CHECK (ciclo IN (0, 1)),
    CONSTRAINT ck_modo_escala_dias CHECK (escala_dias IS NULL OR escala_dias BETWEEN 7 AND 730),
    CONSTRAINT ck_modo_semanas CHECK (semanas BETWEEN 1 AND 8)
) ENGINE=InnoDB;

CREATE TABLE modo_bloco (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    modo_id  INT NOT NULL,
    nome     VARCHAR(80) NOT NULL,
    no_id    INT NOT NULL,
    um_por   ENUM('bloco','dia') NOT NULL DEFAULT 'dia',
    ordem    INT NOT NULL DEFAULT 0,
    FOREIGN KEY (modo_id) REFERENCES modo(id) ON DELETE CASCADE,
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_modo_bloco_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

-- o relógio fixo de um bloco (opcional; saindo o relógio, o bloco volta a sortear)
CREATE TABLE modo_bloco_relogio (
    bloco_id    INT PRIMARY KEY,
    relogio_id  INT NOT NULL,
    FOREIGN KEY (bloco_id) REFERENCES modo_bloco(id) ON DELETE CASCADE,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE modo_bloco_dia (
    bloco_id  INT NOT NULL,
    dia       INT NOT NULL,
    PRIMARY KEY (bloco_id, dia),
    FOREIGN KEY (bloco_id) REFERENCES modo_bloco(id) ON DELETE CASCADE,
    CONSTRAINT ck_modo_bloco_dia_dia CHECK (dia BETWEEN 1 AND 7)
) ENGINE=InnoDB;

-- o plano: o relógio de cada dia da coleção
CREATE TABLE plano (
    colecao_id  INT NOT NULL,
    data        DATE NOT NULL,
    relogio_id  INT NOT NULL,
    origem      ENUM('sorteio','manual') NOT NULL,
    criado      DATETIME NOT NULL,
    acao        VARCHAR(500) NULL,
    motivo      VARCHAR(300) NULL,
    PRIMARY KEY (colecao_id, data),
    INDEX (relogio_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- o bloco do modo que escolheu o dia (opcional; saindo o bloco, o dia fica)
CREATE TABLE plano_bloco (
    colecao_id  INT NOT NULL,
    data        DATE NOT NULL,
    bloco_id    INT NOT NULL,
    PRIMARY KEY (colecao_id, data),
    FOREIGN KEY (colecao_id, data) REFERENCES plano(colecao_id, data) ON DELETE CASCADE,
    FOREIGN KEY (bloco_id) REFERENCES modo_bloco(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- os dias deixados sem relógio
CREATE TABLE plano_livre (
    colecao_id  INT NOT NULL,
    data        DATE NOT NULL,
    criado      DATETIME NOT NULL,
    PRIMARY KEY (colecao_id, data),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================================================================
-- Eventos, destinos dos alertas e agendas
-- =====================================================================================================================

-- os eventos personalizados (lembretes com repetição); o de um relógio, pela ligação
CREATE TABLE evento_personalizado (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id      INT NOT NULL,
    nome            VARCHAR(120) NOT NULL,
    ativo           TINYINT NOT NULL DEFAULT 1,
    repeticao       ENUM('uma','diaria','semanal','mensal','intervalo') NOT NULL,
    data_inicio     DATE NULL,
    hora            TIME NOT NULL,
    dia_mes         TINYINT NULL,
    intervalo_dias  INT NULL,
    criado          DATETIME NOT NULL,
    INDEX (colecao_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_evento_personalizado_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_evento_personalizado_ativo CHECK (ativo IN (0, 1)),
    CONSTRAINT ck_evento_personalizado_dia_mes CHECK ((repeticao = 'mensal' AND dia_mes BETWEEN 1 AND 31) OR (repeticao <> 'mensal' AND dia_mes IS NULL)),
    CONSTRAINT ck_evento_personalizado_intervalo CHECK ((repeticao = 'intervalo' AND intervalo_dias BETWEEN 1 AND 3650) OR (repeticao <> 'intervalo' AND intervalo_dias IS NULL)),
    CONSTRAINT ck_evento_personalizado_data CHECK ((repeticao IN ('uma', 'intervalo') AND data_inicio IS NOT NULL) OR (repeticao NOT IN ('uma', 'intervalo') AND data_inicio IS NULL))
) ENGINE=InnoDB;

CREATE TABLE evento_relogio (
    evento_id   INT PRIMARY KEY,
    relogio_id  INT NOT NULL,
    INDEX (relogio_id),
    FOREIGN KEY (evento_id) REFERENCES evento_personalizado(id) ON DELETE CASCADE,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE evento_dia (
    evento_id  INT NOT NULL,
    dia        INT NOT NULL,
    PRIMARY KEY (evento_id, dia),
    FOREIGN KEY (evento_id) REFERENCES evento_personalizado(id) ON DELETE CASCADE,
    CONSTRAINT ck_evento_dia_dia CHECK (dia BETWEEN 1 AND 7)
) ENGINE=InnoDB;

CREATE TABLE evento_disparo (
    evento_id   INT NOT NULL,
    ocorrencia  DATETIME NOT NULL,
    disparado   DATETIME NOT NULL,
    PRIMARY KEY (evento_id, ocorrencia),
    FOREIGN KEY (evento_id) REFERENCES evento_personalizado(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- os destinos dos alertas da coleção: um webhook HTTP, um e-mail ou o Web Push, cada um com o seu modelo de mensagem (os
-- marcadores {relogio}, {acao}, {motivo}...) e, se quiser, um idioma próprio (vazio: o da coleção)
CREATE TABLE destino (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id  INT NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    tipo        ENUM('webhook','email','webpush') NOT NULL,
    ativo       TINYINT NOT NULL DEFAULT 1,
    modelo      TEXT NOT NULL,
    idioma      VARCHAR(10) NULL,
    fuso        VARCHAR(60) NULL,                    -- o fuso das horas no texto das mensagens; vazio: o da coleção
    criado      DATETIME NOT NULL,
    UNIQUE (colecao_id, nome),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_destino_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_destino_ativo CHECK (ativo IN (0, 1))
) ENGINE=InnoDB;

-- o webhook: o método, o endereço e como a mensagem vai (na URL, como formulário ou como JSON)
CREATE TABLE destino_webhook (
    destino_id  INT PRIMARY KEY,
    metodo      ENUM('GET','POST','PUT') NOT NULL DEFAULT 'GET',
    url         VARCHAR(1000) NOT NULL,
    formato     ENUM('query','form','json') NOT NULL DEFAULT 'query',
    corpo_json  TEXT NULL,                          -- só no json: o modelo do corpo (vazio: os campos viram o objeto)
    FOREIGN KEY (destino_id) REFERENCES destino(id) ON DELETE CASCADE,
    CONSTRAINT ck_destino_webhook_url CHECK (url LIKE 'http%'),
    CONSTRAINT ck_destino_webhook_get CHECK (metodo <> 'GET' OR formato = 'query')
) ENGINE=InnoDB;

-- os campos que o webhook manda (cada valor é um modelo, com os marcadores e {titulo} e {mensagem})
CREATE TABLE destino_webhook_campo (
    destino_id  INT NOT NULL,
    nome        VARCHAR(80) NOT NULL,
    valor       TEXT NOT NULL,
    ordem       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (destino_id, nome),
    FOREIGN KEY (destino_id) REFERENCES destino_webhook(destino_id) ON DELETE CASCADE,
    CONSTRAINT ck_destino_webhook_campo_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

-- os cabeçalhos HTTP do webhook (o valor cifrado: pode ser um token)
CREATE TABLE destino_webhook_cabecalho (
    destino_id     INT NOT NULL,
    nome           VARCHAR(80) NOT NULL,
    valor_cifrado  TEXT NOT NULL,
    PRIMARY KEY (destino_id, nome),
    FOREIGN KEY (destino_id) REFERENCES destino_webhook(destino_id) ON DELETE CASCADE,
    CONSTRAINT ck_destino_webhook_cabecalho_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

-- o e-mail: para quem (um ou mais endereços, separados por vírgula)
CREATE TABLE destino_email (
    destino_id  INT PRIMARY KEY,
    para        VARCHAR(500) NOT NULL,
    FOREIGN KEY (destino_id) REFERENCES destino(id) ON DELETE CASCADE,
    CONSTRAINT ck_destino_email_para CHECK (para LIKE '%_@_%')
) ENGINE=InnoDB;

-- o Web Push: os aparelhos inscritos de um usuário (a mensagem sai no idioma dele)
CREATE TABLE destino_webpush (
    destino_id  INT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    FOREIGN KEY (destino_id) REFERENCES destino(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- o que vai por um destino: o relógio do dia (dia), a véspera (vespera) e cada aviso (pelo identificador, que vale para
-- todas as versões dele), cada um com o modelo próprio, se quiser
CREATE TABLE destino_aviso (
    destino_id      INT NOT NULL,
    tipo_aviso      VARCHAR(60) NOT NULL,
    envia           TINYINT NOT NULL DEFAULT 0,
    modelo_proprio  TEXT NULL,
    PRIMARY KEY (destino_id, tipo_aviso),
    FOREIGN KEY (destino_id) REFERENCES destino(id) ON DELETE CASCADE,
    CONSTRAINT ck_destino_aviso_envia CHECK (envia IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE destino_evento (
    destino_id      INT NOT NULL,
    evento_id       INT NOT NULL,
    envia           TINYINT NOT NULL DEFAULT 0,
    modelo_proprio  TEXT NULL,
    PRIMARY KEY (destino_id, evento_id),
    FOREIGN KEY (destino_id) REFERENCES destino(id) ON DELETE CASCADE,
    FOREIGN KEY (evento_id) REFERENCES evento_personalizado(id) ON DELETE CASCADE,
    CONSTRAINT ck_destino_evento_envia CHECK (envia IN (0, 1))
) ENGINE=InnoDB;

-- as agendas da coleção: Google, CalDAV (Nextcloud, iCloud, Fastmail...), Outlook (Microsoft Graph) ou um link ICS para
-- assinar em qualquer app; cada uma com o modelo do evento e a antecedência
CREATE TABLE agenda (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id         INT NOT NULL,
    nome               VARCHAR(80) NOT NULL,
    tipo               ENUM('google','caldav','outlook','ics') NOT NULL,
    ativa              TINYINT NOT NULL DEFAULT 1,
    janela_dias        INT NOT NULL DEFAULT 30,           -- quantos dias à frente os eventos entram
    modelo             TEXT NOT NULL,
    idioma             VARCHAR(10) NULL,
    fuso               VARCHAR(60) NULL,                    -- o fuso das horas no texto dos eventos; vazio: o da coleção
    teste_id           VARCHAR(255) NULL,          -- o evento de teste que ficou na agenda (o próximo teste o apaga)
    criado             DATETIME NOT NULL,
    UNIQUE (colecao_id, nome),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_agenda_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_agenda_ativa CHECK (ativa IN (0, 1)),
    CONSTRAINT ck_agenda_janela CHECK (janela_dias BETWEEN 1 AND 365)
) ENGINE=InnoDB;

CREATE TABLE agenda_google (
    agenda_id           INT PRIMARY KEY,
    calendario_id       VARCHAR(255) NOT NULL,
    chave_json_cifrada  TEXT NOT NULL,
    FOREIGN KEY (agenda_id) REFERENCES agenda(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE agenda_caldav (
    agenda_id      INT PRIMARY KEY,
    url            VARCHAR(1000) NOT NULL,          -- o endereço do calendário (a coleção CalDAV)
    usuario        VARCHAR(200) NOT NULL,
    senha_cifrada  TEXT NOT NULL,
    FOREIGN KEY (agenda_id) REFERENCES agenda(id) ON DELETE CASCADE,
    CONSTRAINT ck_agenda_caldav_url CHECK (url LIKE 'http%')
) ENGINE=InnoDB;

CREATE TABLE agenda_outlook (
    agenda_id               INT PRIMARY KEY,
    conta                   VARCHAR(200) NULL,      -- a conta Microsoft conectada (vazio: ainda não conectou)
    calendario_id           VARCHAR(500) NULL,      -- vazio: o calendário principal
    refresh_token_cifrado   TEXT NULL,
    FOREIGN KEY (agenda_id) REFERENCES agenda(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- o link ICS: quem tem o link vê os eventos (o token, cifrado para mostrar de novo, e o hash, para achar)
CREATE TABLE agenda_ics (
    agenda_id       INT PRIMARY KEY,
    token_cifrado   TEXT NOT NULL,
    token_hash      CHAR(64) NOT NULL UNIQUE,
    FOREIGN KEY (agenda_id) REFERENCES agenda(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE agenda_aviso (
    agenda_id       INT NOT NULL,
    tipo_aviso      VARCHAR(60) NOT NULL,
    envia           TINYINT NOT NULL DEFAULT 0,
    modelo_proprio  TEXT NULL,
    PRIMARY KEY (agenda_id, tipo_aviso),
    FOREIGN KEY (agenda_id) REFERENCES agenda(id) ON DELETE CASCADE,
    CONSTRAINT ck_agenda_aviso_envia CHECK (envia IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE agenda_evento (
    agenda_id  INT NOT NULL,
    evento_id  INT NOT NULL,
    envia      TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (agenda_id, evento_id),
    FOREIGN KEY (agenda_id) REFERENCES agenda(id) ON DELETE CASCADE,
    FOREIGN KEY (evento_id) REFERENCES evento_personalizado(id) ON DELETE CASCADE,
    CONSTRAINT ck_agenda_evento_envia CHECK (envia IN (0, 1))
) ENGINE=InnoDB;

-- os eventos que a sincronização já criou numa agenda (para atualizar e apagar depois)
CREATE TABLE agenda_sincronizado (
    agenda_id   INT NOT NULL,
    chave       VARCHAR(160) NOT NULL,
    externo_id  VARCHAR(500) NOT NULL,
    data        DATE NOT NULL,
    titulo      VARCHAR(255) NOT NULL,
    assinatura  CHAR(32) NULL,
    criado      DATETIME NOT NULL,
    PRIMARY KEY (agenda_id, chave),
    FOREIGN KEY (agenda_id) REFERENCES agenda(id) ON DELETE CASCADE,
    CONSTRAINT ck_agenda_sincronizado_textos CHECK (TRIM(chave) <> '' AND TRIM(externo_id) <> '')
) ENGINE=InnoDB;

-- =====================================================================================================================
-- O cron
-- =====================================================================================================================

-- cada rodada do cron (a parte do sistema: cópia de segurança, limpeza das contas, documentos)
CREATE TABLE cron_execucao (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inicio          DATETIME NOT NULL,
    fim             DATETIME NULL,
    registro        TEXT NOT NULL,
    teve_erro       TINYINT NOT NULL DEFAULT 0,
    duracao_ms      INT NOT NULL DEFAULT 0,
    teve_atividade  TINYINT NOT NULL DEFAULT 1,
    CONSTRAINT ck_cron_execucao_periodo CHECK (fim IS NULL OR fim >= inicio),
    CONSTRAINT ck_cron_execucao_marcas CHECK (teve_erro IN (0, 1) AND teve_atividade IN (0, 1) AND duracao_ms >= 0)
) ENGINE=InnoDB;

-- o que a rodada fez em cada coleção
CREATE TABLE cron_execucao_colecao (
    execucao_id     INT NOT NULL,
    colecao_id      INT NOT NULL,
    registro        TEXT NOT NULL,
    teve_erro       TINYINT NOT NULL DEFAULT 0,
    teve_atividade  TINYINT NOT NULL DEFAULT 1,
    PRIMARY KEY (execucao_id, colecao_id),
    FOREIGN KEY (execucao_id) REFERENCES cron_execucao(id) ON DELETE CASCADE,
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    CONSTRAINT ck_cron_execucao_colecao_marcas CHECK (teve_erro IN (0, 1) AND teve_atividade IN (0, 1))
) ENGINE=InnoDB;
