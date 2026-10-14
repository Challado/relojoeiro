-- Relógios 2, migração v14: os documentos de cada relógio. Qualquer arquivo (o manual, a nota fiscal em PDF e em XML, as
-- fotos de recordação, os vídeos...), numa categoria. O arquivo fica numa pasta do servidor (DOCUMENTOS_PASTA, no
-- config.php), e o banco guarda os dados dele. Os manuais da v13 passam sozinhos para a categoria Manual quando a pasta
-- estiver configurada. Os seus dados não mudam.
SET NAMES utf8mb4;

-- as categorias dos documentos: cadastro (página Cadastros). "aceita": vazio, qualquer arquivo; senão, os tipos aceitos,
-- separados por vírgula (imagem, video, audio, pdf, xml)
CREATE TABLE documento_categoria (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    identificador  VARCHAR(40) NOT NULL UNIQUE,
    nome           VARCHAR(120) NOT NULL,
    aceita         VARCHAR(100) NOT NULL DEFAULT '',
    ordem          INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- os documentos: os dados de cada arquivo (o arquivo mesmo fica na pasta, com o nome em "arquivo"; a miniatura de uma
-- foto, quando o navegador mandou, em "miniatura"); saem junto com o relógio
CREATE TABLE documento (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    relogio_id    INT NOT NULL,
    categoria_id  INT NOT NULL,
    titulo        VARCHAR(200) NOT NULL,
    data          DATE NULL,                 -- a data do documento: a ocasião da foto, a data da nota
    descricao     TEXT NULL,
    nome          VARCHAR(255) NOT NULL,     -- o nome do arquivo enviado
    tipo          VARCHAR(100) NOT NULL,     -- o tipo (MIME): image/jpeg, video/mp4, application/pdf...
    tamanho       BIGINT NOT NULL,           -- em bytes
    arquivo       VARCHAR(200) NOT NULL,     -- o caminho dentro da pasta dos documentos
    miniatura     VARCHAR(200) NULL,
    criado        DATETIME NOT NULL,
    INDEX (relogio_id, categoria_id),
    FOREIGN KEY (relogio_id) REFERENCES relogio(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES documento_categoria(id)
) ENGINE=InnoDB;

INSERT INTO documento_categoria (identificador, nome, aceita, ordem) VALUES
    ('manual', 'Manual', '', 1),
    ('nota_fiscal', 'Nota fiscal', 'pdf,imagem', 2),
    ('nota_xml', 'Nota fiscal (XML)', 'xml', 3),
    ('fotos', 'Fotos', 'imagem', 4),
    ('videos', 'Vídeos', 'video', 5),
    ('diversos', 'Diversos', '', 6);
