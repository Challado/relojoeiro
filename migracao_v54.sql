-- Relógios 2, migração v54: os relatórios cadastrados (Cadastros › Relatórios, e pela API: recurso=relatorios).
-- Um relatório é um cadastro da árvore, como os totais: o nome, a descrição, as colunas (cada uma com o título, a expressão
-- calculada em cada relógio, o formato e a conta do rodapé), o filtro (uma condição), a ordem, o agrupamento, quais relógios
-- entram (os da coleção, todos ou só os desejados) e o limite de linhas. Ele aparece no catálogo dos relatórios (Meus
-- relatórios) e sai no mesmo formato dos prontos, com o CSV.
SET NAMES utf8mb4;

CREATE TABLE relatorio (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    no_id        INT NOT NULL,
    nome         VARCHAR(120) NOT NULL,
    descricao    TEXT NULL,
    colunas      TEXT NOT NULL,
    filtro       TEXT NULL,
    ordenar      TEXT NULL,
    decrescente  TINYINT NOT NULL DEFAULT 0,
    agrupar      TEXT NULL,
    relogios     VARCHAR(20) NOT NULL DEFAULT 'colecao',
    limite       INT NULL,
    INDEX (no_id),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    CONSTRAINT ck_relatorio_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_relatorio_opcoes CHECK (decrescente IN (0, 1) AND relogios IN ('colecao','todos','desejados') AND (limite IS NULL OR limite > 0))
) ENGINE=InnoDB;
