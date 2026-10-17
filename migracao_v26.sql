-- Relógios 2, migração v26: o gasto da bateria em cinco motores, e a marcação das leituras.
-- 1. A marcação de cada leitura de carga, em uso ou em repouso (o "Informar ... em uso" e o "Informar ... em repouso" do
--    quadro Marcar, e os botões Em uso e Em repouso do Hoje), gravada na leitura. Vazio: a leitura de antes da marcação (ou
--    de um script que não diz): vale o que o pulso registrado dizia naquele instante.
-- 2. O campo do cadastro "Como calcular o gasto da bateria" (gasto_motor), na raiz de cada árvore, uma lista com os cinco
--    motores: horas (o motor original), robusto, marcacao, cadastro, formulas. A escolha: a Configuração da coleção
--    (gasto_motor), senão o campo no relógio, senão o padrão da versão do campo mais perto do relógio na árvore (o grupo),
--    senão horas.
-- 3. A Configuração de cada coleção ganha gasto_motor (vazio: conforme o relógio e o grupo), documentos_copia (a cópia dos
--    documentos no banco: vazio, todos ou nenhum; no lugar do DOCUMENTOS_COPIA_BANCO do config.php, que valia para todas as
--    coleções do servidor e não vale mais), medicao_base (dias ou leituras) e medicao_janela_leituras.
-- O campo e as chaves entram pelo passo em PHP (migracao_v26_dados, no lib.php): uma árvore e uma coleção de cada vez, o
-- campo no idioma da coleção (o modelo.json), e só onde ainda não existem.
SET NAMES utf8mb4;

ALTER TABLE lancamento ADD COLUMN estado VARCHAR(10) NULL AFTER valor;
ALTER TABLE lancamento ADD CONSTRAINT ck_lancamento_estado CHECK (estado IS NULL OR estado IN ('uso', 'repouso'));
