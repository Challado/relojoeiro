-- Relógios 2, migração v41: as unidades. Cada árvore ganha o cadastro das unidades (presas à raiz, como os totais): o
-- símbolo, o nome e a conversão (a unidade base da mesma grandeza, o fator e o zero) e a unidade em que o valor também
-- aparece. O campo, o tipo de lançamento e a fórmula continuam guardando o símbolo, que agora tem de ser de uma unidade
-- cadastrada na árvore. O passo em PHP (migracao_v41_dados) põe as unidades do modelo.json em cada árvore, no idioma da
-- coleção, e as que a árvore já usa e o modelo não tem.
SET NAMES utf8mb4;

CREATE TABLE unidade (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    no_id          INT NOT NULL,
    simbolo        VARCHAR(20) NOT NULL,
    nome           VARCHAR(80) NOT NULL,
    base_id        INT NULL,
    fator          DECIMAL(18,9) NULL,
    zero           DECIMAL(18,9) NOT NULL DEFAULT 0,
    mostrar_em_id  INT NULL,
    UNIQUE (no_id, simbolo),
    FOREIGN KEY (no_id) REFERENCES no(id) ON DELETE CASCADE,
    FOREIGN KEY (base_id) REFERENCES unidade(id) ON DELETE CASCADE,
    FOREIGN KEY (mostrar_em_id) REFERENCES unidade(id) ON DELETE SET NULL,
    CONSTRAINT ck_unidade_textos CHECK (TRIM(simbolo) <> '' AND TRIM(nome) <> ''),
    CONSTRAINT ck_unidade_base CHECK ((base_id IS NULL AND fator IS NULL AND zero = 0) OR (base_id IS NOT NULL AND fator IS NOT NULL AND fator <> 0))
) ENGINE=InnoDB;
