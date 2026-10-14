-- Relógios 2, migração v22: a situação de cada relógio na coleção (a posse) e a lista de desejos. Um relógio está na coleção
-- (o de sempre), desejado (a lista de desejos: ainda não é seu), emprestado (é seu, mas está com outra pessoa), vendido ou
-- arquivado. Só o que está na coleção entra no rodízio: fora dela, o relógio fica indisponível (o banco garante), e com isso
-- sai do sorteio, da escala e dos avisos; a ficha e o histórico ficam. Campos novos no cadastro: a marca, a referência e o link
-- (de qualquer relógio), e, só nos desejados, o preço-alvo e a prioridade (a marca so_desejado do campo).
SET NAMES utf8mb4;

ALTER TABLE relogio ADD COLUMN posse VARCHAR(12) NOT NULL DEFAULT 'colecao' AFTER copia_banco;
ALTER TABLE relogio ADD CONSTRAINT ck_relogio_posse CHECK (posse IN ('colecao', 'desejado', 'emprestado', 'vendido', 'arquivado'));
ALTER TABLE relogio ADD CONSTRAINT ck_relogio_posse_disponivel CHECK (posse = 'colecao' OR disponivel = 0);
ALTER TABLE campo ADD COLUMN so_desejado TINYINT NOT NULL DEFAULT 0 AFTER ordem;
ALTER TABLE campo ADD CONSTRAINT ck_campo_so_desejado CHECK (so_desejado IN (0, 1));

-- os campos novos, de todos os relógios (na raiz), cada um só se ainda não existe um com o mesmo identificador
INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado)
    SELECT 'marca', 'Marca', 'texto', '', NULL, NULL, 7, 0 FROM (SELECT COUNT(*) AS n FROM campo WHERE identificador = 'marca') x WHERE x.n = 0;
INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado)
    SELECT 'referencia', 'Referência (o modelo)', 'texto', '', NULL, NULL, 8, 0 FROM (SELECT COUNT(*) AS n FROM campo WHERE identificador = 'referencia') x WHERE x.n = 0;
INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado)
    SELECT 'link', 'Link (a página do relógio)', 'texto', '', NULL, NULL, 9, 0 FROM (SELECT COUNT(*) AS n FROM campo WHERE identificador = 'link') x WHERE x.n = 0;
INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado)
    SELECT 'preco_alvo', 'Preço-alvo (até quanto pagar)', 'decimal', 'R$', NULL, NULL, 10, 1 FROM (SELECT COUNT(*) AS n FROM campo WHERE identificador = 'preco_alvo') x WHERE x.n = 0;
INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado)
    SELECT 'prioridade_desejo', 'Prioridade do desejo', 'lista', '', NULL, NULL, 11, 1 FROM (SELECT COUNT(*) AS n FROM campo WHERE identificador = 'prioridade_desejo') x WHERE x.n = 0;
INSERT INTO campo_opcao (campo_id, ordem, valor)
    SELECT c.id, 1, 'Alta' FROM campo c WHERE c.identificador = 'prioridade_desejo' AND NOT EXISTS (SELECT 1 FROM campo_opcao o WHERE o.campo_id = c.id);
INSERT INTO campo_opcao (campo_id, ordem, valor)
    SELECT c.id, 2, 'Média' FROM campo c WHERE c.identificador = 'prioridade_desejo' AND NOT EXISTS (SELECT 1 FROM campo_opcao o WHERE o.campo_id = c.id AND o.ordem = 2);
INSERT INTO campo_opcao (campo_id, ordem, valor)
    SELECT c.id, 3, 'Baixa' FROM campo c WHERE c.identificador = 'prioridade_desejo' AND NOT EXISTS (SELECT 1 FROM campo_opcao o WHERE o.campo_id = c.id AND o.ordem = 3);
