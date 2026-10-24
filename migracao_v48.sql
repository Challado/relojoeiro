-- Relógios 2, migração v48: a manutenção e as listas que viram cadastro.
-- 1. As listas (lista_item, por árvore, como as categorias de documento): a situação do relógio na coleção, os tipos de
--    acessório, os tipos de serviço, os resultados da estanqueidade, os jeitos de carregar no teste da reserva, as
--    observações da reserva, as situações da manutenção, os tipos de oficina e os tipos de contato. Cada item tem o
--    identificador (fixo: é o que fica gravado nas colunas de antes e o que a API e as fórmulas citam), o nome, a ordem, o
--    que ele faz (comportamento) e, conforme a lista, um texto (o tipo de lançamento que o serviço marca, a unidade, o texto
--    do aviso) e uma cor. As listas antigas no código (situação, tipo de acessório, tipo de serviço, estanqueidade, protocolo
--    e observação da reserva) saem das regras CHECK: o valor gravado é o identificador de um item da lista.
-- 2. As oficinas (oficina, da coleção) e os contatos de cada uma (oficina_contato: quantos quiser, cada um com o tipo).
-- 3. A manutenção (manutencao: o relógio, a oficina, a situação, o tipo de serviço pedido, o problema, a OS, o envio, a
--    previsão, o orçamento, o encerramento e a disponibilidade de antes), com cada mudança de situação (manutencao_evento),
--    o que foi junto (manutencao_acessorio) e os documentos do envio (manutencao_documento). Todo serviço é o encerramento
--    de uma manutenção (servico.manutencao_id).
-- O passo em PHP (migracao_v48_dados) põe as listas iniciais em cada árvore (no idioma da coleção), a oficina "Eu mesmo (em
-- casa)" em cada coleção, uma oficina para cada responsável diferente dos serviços de antes, uma manutenção encerrada para
-- cada serviço de antes (na oficina dele), tira a coluna servico.responsavel e grava a marca. Pode rodar de novo.
SET NAMES utf8mb4;

CREATE TABLE lista_item (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    no_id          INT NOT NULL,
    lista          VARCHAR(30) NOT NULL,
    identificador  VARCHAR(20) NOT NULL,
    nome           VARCHAR(80) NOT NULL,
    ordem          INT NOT NULL DEFAULT 0,
    comportamento  VARCHAR(20) NULL,
    texto          VARCHAR(200) NULL,
    cor            VARCHAR(7) NULL,
    UNIQUE KEY lista_item_unico (no_id, lista, identificador),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_lista_item_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> ''),
    CONSTRAINT ck_lista_item_lista CHECK (lista IN ('relogio_situacao', 'acessorio_tipo', 'servico_tipo', 'estanqueidade', 'reserva_protocolo',
        'reserva_observacao', 'manutencao_situacao', 'oficina_tipo', 'contato_tipo')),
    CONSTRAINT ck_lista_item_comportamento CHECK ((lista = 'relogio_situacao' AND comportamento IN ('colecao', 'emprestado', 'desejado', 'fora'))
        OR (lista = 'estanqueidade' AND comportamento IN ('aprova', 'reprova'))
        OR (lista = 'manutencao_situacao' AND comportamento IN ('aberta', 'encerra', 'cancela'))
        OR (lista = 'oficina_tipo' AND comportamento IN ('comum', 'propria'))
        OR (lista = 'contato_tipo' AND comportamento IN ('telefone', 'whatsapp', 'email', 'link', 'texto'))
        OR (lista IN ('acessorio_tipo', 'servico_tipo', 'reserva_protocolo', 'reserva_observacao') AND comportamento IS NULL)),
    CONSTRAINT ck_lista_item_cor CHECK (cor IS NULL OR cor LIKE '#______')
) ENGINE=InnoDB;

CREATE TABLE oficina (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    colecao_id   INT NOT NULL,
    nome         VARCHAR(120) NOT NULL,
    tipo_id      INT NOT NULL,
    endereco     VARCHAR(200) NULL,
    numero       VARCHAR(20) NULL,
    complemento  VARCHAR(80) NULL,
    bairro       VARCHAR(80) NULL,
    cidade       VARCHAR(80) NULL,
    uf           VARCHAR(40) NULL,
    cep          VARCHAR(20) NULL,
    horario      VARCHAR(200) NULL,
    observacao   VARCHAR(2000) NULL,
    criado       DATETIME NOT NULL,
    INDEX (colecao_id),
    INDEX oficina_tipo (tipo_id),
    FOREIGN KEY (colecao_id) REFERENCES colecao(id) ON DELETE CASCADE,
    FOREIGN KEY (tipo_id) REFERENCES lista_item(id) ON DELETE CASCADE,
    CONSTRAINT ck_oficina_nome CHECK (TRIM(nome) <> '')
) ENGINE=InnoDB;

CREATE TABLE oficina_contato (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    oficina_id  INT NOT NULL,
    tipo_id     INT NOT NULL,
    valor       VARCHAR(200) NOT NULL,
    pessoa      VARCHAR(120) NULL,
    observacao  VARCHAR(200) NULL,
    principal   TINYINT NOT NULL DEFAULT 0,
    ordem       INT NOT NULL DEFAULT 0,
    INDEX (oficina_id),
    INDEX oficina_contato_tipo (tipo_id),
    FOREIGN KEY (oficina_id) REFERENCES oficina(id) ON DELETE CASCADE,
    FOREIGN KEY (tipo_id) REFERENCES lista_item(id) ON DELETE CASCADE,
    CONSTRAINT ck_oficina_contato_valor CHECK (TRIM(valor) <> ''),
    CONSTRAINT ck_oficina_contato_principal CHECK (principal IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE manutencao (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id        INT NOT NULL,
    oficina_id        INT NOT NULL,
    situacao_id       INT NOT NULL,
    tipo_id           INT NOT NULL,
    problema          VARCHAR(2000) NULL,
    os                VARCHAR(60) NULL,
    enviado           DATE NOT NULL,
    previsao          DATE NULL,
    orcamento         DECIMAL(12,2) NULL,
    orcamento_data    DATE NULL,
    encerrado         DATE NULL,
    disponivel_antes  TINYINT NOT NULL DEFAULT 1,
    observacao        VARCHAR(2000) NULL,
    criado            DATETIME NOT NULL,
    INDEX (relogio_id, enviado),
    INDEX manutencao_oficina (oficina_id),
    INDEX manutencao_situacao (situacao_id),
    INDEX manutencao_tipo (tipo_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (oficina_id) REFERENCES oficina(id) ON DELETE CASCADE,
    FOREIGN KEY (situacao_id) REFERENCES lista_item(id) ON DELETE CASCADE,
    FOREIGN KEY (tipo_id) REFERENCES lista_item(id) ON DELETE CASCADE,
    CONSTRAINT ck_manutencao_datas CHECK ((previsao IS NULL OR previsao >= enviado) AND (encerrado IS NULL OR encerrado >= enviado)
        AND (orcamento_data IS NULL OR orcamento_data >= enviado)),
    CONSTRAINT ck_manutencao_orcamento CHECK (orcamento IS NULL OR orcamento >= 0),
    CONSTRAINT ck_manutencao_disponivel CHECK (disponivel_antes IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE manutencao_evento (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    manutencao_id  INT NOT NULL,
    situacao_id    INT NOT NULL,
    quando         DATETIME NOT NULL,
    observacao     VARCHAR(500) NULL,
    INDEX (manutencao_id, quando),
    INDEX manutencao_evento_situacao (situacao_id),
    FOREIGN KEY (manutencao_id) REFERENCES manutencao(id) ON DELETE CASCADE,
    FOREIGN KEY (situacao_id) REFERENCES lista_item(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE manutencao_acessorio (
    manutencao_id  INT NOT NULL,
    acessorio_id   INT NOT NULL,
    PRIMARY KEY (manutencao_id, acessorio_id),
    INDEX (acessorio_id),
    FOREIGN KEY (manutencao_id) REFERENCES manutencao(id) ON DELETE CASCADE,
    FOREIGN KEY (acessorio_id) REFERENCES acessorio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE manutencao_documento (
    manutencao_id  INT NOT NULL,
    documento_id   INT NOT NULL,
    PRIMARY KEY (manutencao_id, documento_id),
    INDEX (documento_id),
    FOREIGN KEY (manutencao_id) REFERENCES manutencao(id) ON DELETE CASCADE,
    FOREIGN KEY (documento_id) REFERENCES documento(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- o serviço é o encerramento de uma manutenção (o passo em PHP liga os de antes e torna a coluna obrigatória)
ALTER TABLE servico ADD COLUMN manutencao_id INT NULL AFTER relogio_id;
ALTER TABLE servico ADD INDEX servico_manutencao (manutencao_id);
ALTER TABLE servico ALTER FOREIGN KEY (manutencao_id) REFERENCES manutencao(id) ON DELETE CASCADE;

-- as listas antigas saem das regras: o valor gravado é o identificador de um item da lista (até 20 letras); primeiro as
-- regras novas, depois as colunas mais largas
ALTER TABLE relogio ADD CONSTRAINT ck_relogio_posse CHECK (TRIM(posse) <> '');
ALTER TABLE relogio ADD CONSTRAINT ck_relogio_posse_disponivel CHECK (disponivel IN (0, 1));
ALTER TABLE servico ADD CONSTRAINT ck_servico_tipo CHECK (TRIM(tipo) <> '');
ALTER TABLE servico ADD CONSTRAINT ck_servico_estanqueidade CHECK (estanqueidade IS NULL OR TRIM(estanqueidade) <> '');
ALTER TABLE reserva_teste ADD CONSTRAINT ck_reserva_teste_tipo CHECK (TRIM(tipo) <> '');
ALTER TABLE reserva_teste ADD CONSTRAINT ck_reserva_teste_protocolo CHECK (protocolo IS NULL OR TRIM(protocolo) <> '');
ALTER TABLE acessorio ADD CONSTRAINT ck_acessorio_tipo CHECK (TRIM(tipo) <> '');
ALTER TABLE relogio MODIFY COLUMN posse VARCHAR(20) NOT NULL DEFAULT 'colecao';
ALTER TABLE servico MODIFY COLUMN estanqueidade VARCHAR(20) NULL;
ALTER TABLE reserva_teste MODIFY COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'teste';
ALTER TABLE reserva_teste MODIFY COLUMN protocolo VARCHAR(20) NULL;
