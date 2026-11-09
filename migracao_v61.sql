-- Relógios 2, migração v61: o construtor de colunas da tabela dos relógios (Cadastros › Colunas).
-- Uma coluna cadastrada é da árvore, como os totais: o nome, de onde vem o valor e como ele aparece. No modo guiado, a pessoa escolhe
-- a fonte (a tabela e o campo do dicionário de fontes.php, ou um campo de cadastro, ou uma coluna da própria tabela) e a função que
-- junta os registros do relógio (soma, média, menor, maior, contagem); no modo expert, escreve a expressão. Nos dois a expressão fica
-- gravada (a conta de cada relógio, a mesma dos totais e dos relatórios). O formato diz como o valor sai (casas decimais, o símbolo
-- da moeda, tempo, data...). A coluna entra na escolha das colunas da página Hoje (colunas_hoje) com a chave c_<identificador>.
SET NAMES utf8mb4;

CREATE TABLE coluna (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    no_id          INT NOT NULL,
    identificador  VARCHAR(40) NOT NULL,
    nome           VARCHAR(80) NOT NULL,
    modo           VARCHAR(10) NOT NULL DEFAULT 'guiado',
    tabela         VARCHAR(40) NULL,
    campo          VARCHAR(60) NULL,
    funcao         VARCHAR(10) NULL,
    expressao      TEXT NOT NULL,
    formato        VARCHAR(12) NOT NULL DEFAULT 'numero',
    casas          TINYINT NULL,
    simbolo        VARCHAR(8) NULL,
    padrao         TINYINT NOT NULL DEFAULT 0,
    ordem          INT NOT NULL DEFAULT 0,
    UNIQUE (no_id, identificador),
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_coluna_textos CHECK (TRIM(identificador) <> '' AND TRIM(nome) <> '' AND TRIM(expressao) <> ''),
    CONSTRAINT ck_coluna_modo CHECK (modo IN ('guiado', 'expert')),
    CONSTRAINT ck_coluna_casas CHECK (casas IS NULL OR (casas >= 0 AND casas <= 6)),
    CONSTRAINT ck_coluna_padrao CHECK (padrao IN (0, 1))
) ENGINE=InnoDB;
