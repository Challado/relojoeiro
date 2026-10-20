-- Relógios 2, migração v34: os documentos dos calibres e os campos do calibre.
-- 1. documento.calibre_id: um documento é de um relógio ou de um calibre (um ou outro; o PHP confere). O manual técnico do
--    movimento, a ficha de serviço, as fotos do movimento ficam no calibre e aparecem em todos os relógios com ele. Os
--    arquivos vão para a pasta c<id> dos documentos, como os do relógio vão para r<id>.
-- 2. campo.calibre: 1 se o campo é do movimento (a reserva, a marcha esperada, a corda pela coroa...): aparece no
--    formulário do calibre quando a Configuração (calibre_campos) pede só os do movimento, o padrão.
-- A categoria de documento Manual técnico e a marca dos campos do movimento entram pelo passo em PHP (migracao_v34_dados,
-- no lib.php): os campos do modelo.json marcados com calibre, em cada árvore, e a categoria onde ainda não existe.
SET NAMES utf8mb4;

ALTER TABLE documento MODIFY COLUMN relogio_id INT NULL;
ALTER TABLE documento ADD COLUMN calibre_id INT NULL AFTER relogio_id;
ALTER TABLE documento ADD INDEX documento_calibre (calibre_id);
ALTER TABLE documento ALTER FOREIGN KEY (calibre_id) REFERENCES calibre(id) ON DELETE CASCADE;
ALTER TABLE campo ADD COLUMN calibre TINYINT NOT NULL DEFAULT 0 AFTER so_desejado;
ALTER TABLE campo ADD CONSTRAINT ck_campo_calibre CHECK (calibre IN (0, 1));
