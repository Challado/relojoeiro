-- Relógios 2, migração v40: o login e o e-mail de cada conta sem espaços nas pontas, também no próprio banco (a API já
-- aparava; agora o banco recusa: ck_usuario_aparados). O UNIQUE do login e o do e-mail já existiam, e a collation sem
-- maiúsculas faz "Lucas" e "lucas" serem o mesmo login para eles. A API confere, além disso, que o login de uma conta
-- nunca é o e-mail de outra (os dois servem para entrar). Antes da regra, os espaços que houver saem.
SET NAMES utf8mb4;

UPDATE usuario SET login = TRIM(login) WHERE login <> TRIM(login);
UPDATE usuario SET email = TRIM(email) WHERE email IS NOT NULL AND email <> TRIM(email);
ALTER TABLE usuario ADD CONSTRAINT ck_usuario_aparados CHECK (login = TRIM(login) AND (email IS NULL OR email = TRIM(email)));

-- a marca de que a v40 foi aplicada: por último, para uma v40 que parou no meio continuar pendente
INSERT IGNORE INTO sistema (chave, valor) VALUES ('migracao_v40', '1');
