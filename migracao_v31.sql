-- Relógios 2, migração v31: os totais.
-- Os totais da tabela dos relógios e da página de totais deixam de ser fixos: cada um é uma expressão do motor de
-- fórmulas que olha para os relógios mostrados de uma vez (CONTA, SOMA, MEDIA, MEDIANA, MENOR, MAIOR, DISTINTOS,
-- CONTA_SE, QUEM_MAIOR, QUEM_MENOR, AGRUPADO, FAIXAS), cadastrado na aba Totais dos Cadastros (da árvore, como as
-- fórmulas). Cada total vai para o rodapé da tabela, embaixo de uma coluna, e/ou para a página de totais. Os totais
-- prontos (os do modelo.json) entram em cada árvore pelo passo em PHP (migracao_v31_dados).
SET NAMES utf8mb4;

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
