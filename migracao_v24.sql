-- Relógios 2, migração v24: os acessórios. Pulseiras, caixas, estojos, ferramentas, carregadores e o que mais for, cada um com
-- o nome, o tipo, uma observação e, se quiser, o relógio de que ele é (vazio: solto, serve a vários). Excluir o relógio deixa o
-- acessório solto.
SET NAMES utf8mb4;

CREATE TABLE acessorio (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id  INT NULL,
    nome        VARCHAR(120) NOT NULL,
    tipo        VARCHAR(20) NOT NULL DEFAULT 'pulseira',
    observacao  VARCHAR(1000) NULL,
    criado      DATETIME NOT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE SET NULL,
    INDEX (relogio_id)
) ENGINE=InnoDB;
ALTER TABLE acessorio ADD CONSTRAINT ck_acessorio_nome CHECK (TRIM(nome) <> '');
ALTER TABLE acessorio ADD CONSTRAINT ck_acessorio_tipo CHECK (tipo IN ('pulseira', 'caixa', 'estojo', 'ferramenta', 'carregador', 'peca', 'outro'));
