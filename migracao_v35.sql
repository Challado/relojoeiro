-- Relógios 2, migração v35: os serviços e diagnósticos.
-- Cada serviço é uma intervenção num relógio: a data, o tipo (revisão, troca de pilha, teste de estanqueidade, regulagem,
-- polimento, reparo, diagnóstico ou outro), quem fez, o que foi feito, a mão de obra, as peças trocadas (cada uma com o
-- custo, se houver), o teste de estanqueidade (aprovado ou reprovado, e a pressão), a marcha que o profissional mediu antes
-- e depois, a garantia, a observação e os documentos do relógio que são dele (a nota, o orçamento, as fotos). O custo do
-- serviço é a mão de obra mais as peças.
-- A resistência à água declarada (do fabricante) é um campo do cadastro; a estanqueidade comprovada é a do último serviço
-- com o teste: uma coisa não vale pela outra.
-- A revisão e a troca de pilha também viram a marcação do tipo de lançamento de mesmo nome (lancamento_id), para os avisos e
-- as fórmulas de sempre (ULTIMA_DATA("revisao")) contarem o serviço sem lançar duas vezes.
-- O campo da resistência declarada e o total Custo dos serviços entram pelo passo em PHP (migracao_v35_dados, no lib.php).
SET NAMES utf8mb4;

CREATE TABLE servico (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id         INT NOT NULL,
    data               DATE NOT NULL,
    tipo               VARCHAR(20) NOT NULL DEFAULT 'revisao',
    responsavel        VARCHAR(120) NULL,
    descricao          TEXT NULL,
    mao_de_obra        DECIMAL(12,2) NULL,
    estanqueidade      VARCHAR(10) NULL,
    estanqueidade_bar  DECIMAL(6,2) NULL,
    marcha_antes       DECIMAL(8,2) NULL,
    marcha_depois      DECIMAL(8,2) NULL,
    garantia_ate       DATE NULL,
    observacao         LONGTEXT NULL,
    lancamento_id      INT NULL,
    criado             DATETIME NOT NULL,
    INDEX (relogio_id, data),
    INDEX servico_lancamento (lancamento_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (lancamento_id) REFERENCES lancamento(id) ON DELETE SET NULL,
    CONSTRAINT ck_servico_tipo CHECK (tipo IN ('revisao', 'pilha', 'estanqueidade', 'regulagem', 'polimento', 'reparo', 'diagnostico', 'outro')),
    CONSTRAINT ck_servico_estanqueidade CHECK (estanqueidade IS NULL OR estanqueidade IN ('aprovado', 'reprovado')),
    CONSTRAINT ck_servico_valores CHECK ((mao_de_obra IS NULL OR mao_de_obra >= 0) AND (estanqueidade_bar IS NULL OR estanqueidade_bar > 0)),
    CONSTRAINT ck_servico_garantia CHECK (garantia_ate IS NULL OR garantia_ate >= data)
) ENGINE=InnoDB;

CREATE TABLE servico_peca (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    servico_id  INT NOT NULL,
    nome        VARCHAR(120) NOT NULL,
    custo       DECIMAL(12,2) NULL,
    ordem       INT NOT NULL DEFAULT 0,
    INDEX (servico_id),
    FOREIGN KEY (servico_id) REFERENCES servico(id) ON DELETE CASCADE,
    CONSTRAINT ck_servico_peca_nome CHECK (TRIM(nome) <> ''),
    CONSTRAINT ck_servico_peca_custo CHECK (custo IS NULL OR custo >= 0)
) ENGINE=InnoDB;

CREATE TABLE servico_documento (
    servico_id    INT NOT NULL,
    documento_id  INT NOT NULL,
    PRIMARY KEY (servico_id, documento_id),
    INDEX (documento_id),
    FOREIGN KEY (servico_id) REFERENCES servico(id) ON DELETE CASCADE,
    FOREIGN KEY (documento_id) REFERENCES documento(id) ON DELETE CASCADE
) ENGINE=InnoDB;
