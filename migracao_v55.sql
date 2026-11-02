-- Relógios 2, migração v55: o registro do SQL pela API (recurso=sql, só o master).
-- Cada pedido de SQL, o recusado, o que deu erro e o que deu certo, fica aqui: quando, quem pediu, o comando, o tipo
-- (select, insert, update, delete ou alter; vazio: não deu para saber), se deu certo, as linhas (as lidas no SELECT, as
-- mudadas no resto), o tempo e o erro. É do sistema inteiro, como as execuções do cron. Os parâmetros de operação do
-- config.php (o cadastro aberto, os dias da demonstração e do convite, o tamanho dos documentos, a espera das telas, o
-- lote, a cópia de segurança) e a aprovação do UPDATE e do DELETE pelo SQL passam a ser gravados no sistema, pela
-- Configuração ou pela API (as chaves param_ da tabela sistema): não há tabela nova para eles. O comando e o erro são
-- LONGTEXT (no Firebird, o BLOB de texto: dois TEXT não cabem numa linha); o comando vazio fica vazio (NULL).
SET NAMES utf8mb4;

CREATE TABLE sql_execucao (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    quando       DATETIME NOT NULL,
    usuario_id   INT NULL,
    comando      LONGTEXT NULL,
    tipo         VARCHAR(10) NULL,
    ok           TINYINT NOT NULL DEFAULT 0,
    linhas       INT NULL,
    duracao_ms   INT NOT NULL DEFAULT 0,
    erro         LONGTEXT NULL,
    INDEX (quando),
    FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE SET NULL,
    CONSTRAINT ck_sql_execucao_marcas CHECK (ok IN (0, 1) AND duracao_ms >= 0 AND (tipo IS NULL OR tipo IN ('select','insert','update','delete','alter')))
) ENGINE=InnoDB;
