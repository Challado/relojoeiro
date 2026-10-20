-- Relógios 2, migração v32: a precisão (a marcha de cada relógio).
-- 1. lancamento_tipo.marcha: o tipo de lançamento que mede a marcha. "desvio": uma leitura (formato valor, em segundos) de
--    quanto o relógio está adiantado (+) ou atrasado (-) em relação à hora certa; duas leituras seguidas dão a marcha, em
--    segundos por dia. "acerto": um lançamento instantâneo, o acerto da hora (o relógio volta a 0 s; a conta recomeça dele).
--    Vazio: o tipo não tem nada a ver com a marcha.
-- 2. lancamento.posicao: onde o relógio ficou desde a leitura anterior (a condição da medição, gravada na leitura do
--    desvio): pulso, misto (pulso e guardado, como o rodízio fez), mostrador para cima ou para baixo, coroa para cima, para
--    baixo, para a esquerda ou para a direita, winder. Vazio: não informada.
-- Os tipos Desvio da hora e Acerto da hora, os campos da marcha esperada e do limite do desvio, as fórmulas e os avisos da
-- precisão entram pelo passo em PHP (migracao_v32_dados, no lib.php): uma árvore de cada vez, no idioma da coleção (o
-- modelo.json), no grupo do tipo Revisão (o Tradicional), e só onde ainda não existem.
SET NAMES utf8mb4;

ALTER TABLE lancamento_tipo ADD COLUMN marcha VARCHAR(10) NULL AFTER mede_gasto;
ALTER TABLE lancamento_tipo ADD CONSTRAINT ck_lancamento_tipo_marcha CHECK (marcha IS NULL OR (marcha = 'desvio' AND formato = 'valor') OR (marcha = 'acerto' AND formato = 'instantaneo'));
ALTER TABLE lancamento ADD COLUMN posicao VARCHAR(20) NULL AFTER estado;
ALTER TABLE lancamento ADD CONSTRAINT ck_lancamento_posicao CHECK (posicao IS NULL OR posicao IN ('pulso', 'misto', 'mostrador_cima', 'mostrador_baixo', 'coroa_cima', 'coroa_baixo', 'coroa_esquerda', 'coroa_direita', 'winder'));
