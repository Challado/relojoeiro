-- Relógios 2, migração v64: as saídas da coleção e as abas por situação.
-- A situação de cada relógio na coleção já é um item do cadastro (Cadastros › Listas: na coleção, emprestado, desejado, vendido,
-- arquivado, presenteado e os da pessoa). Faltava guardar o que aconteceu quando ele saiu: cada saída (o relógio emprestado ou fora
-- da coleção) vira um registro com a situação (o motivo), a data, o valor recebido, para quem foi e a observação; quando ele volta
-- (ou muda de situação), o registro ganha o fim e fica no histórico. O relógio que está emprestado ou fora tem sempre um registro
-- aberto (sem fim). O passo em PHP abre o registro dos relógios que já estavam assim (sem a data, que ninguém anotou: a pessoa
-- preenche na ficha).
SET NAMES utf8mb4;

CREATE TABLE relogio_saida (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id  INT NOT NULL,
    situacao    VARCHAR(20) NOT NULL,
    data        DATE NULL,
    fim         DATE NULL,
    valor       DECIMAL(12,2) NULL,
    destino     VARCHAR(120) NULL,
    observacao  VARCHAR(2000) NULL,
    criado      DATETIME NOT NULL,
    INDEX (relogio_id, data),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    CONSTRAINT ck_relogio_saida_situacao CHECK (TRIM(situacao) <> ''),
    CONSTRAINT ck_relogio_saida_datas CHECK (data IS NULL OR fim IS NULL OR fim >= data),
    CONSTRAINT ck_relogio_saida_valor CHECK (valor IS NULL OR valor >= 0),
    CONSTRAINT ck_relogio_saida_destino CHECK (destino IS NULL OR TRIM(destino) <> '')
) ENGINE=InnoDB;
