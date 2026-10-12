-- Relógios 2, migração v18: o carregador. Uma sessão nova, "No carregador", onde vale a leitura de carga (o smartwatch):
-- pôr e tirar do carregador como se põe e tira do sol ou do winder, e o estado do relógio mostra "No carregador" enquanto
-- ele carrega. É exclusiva, como o pulso, o sol e o winder: pôr no pulso tira do carregador. Carregando, o aviso
-- "Carregar" não aparece. Depois de tirar, informe a leitura de carga: é ela que diz quanto carregou.
SET NAMES utf8mb4;

-- onde vale a leitura de carga, logo depois dela; se o tipo já existir (criado à mão), fica como está
INSERT INTO lancamento_tipo (identificador, nome, formato, unidade, fecha_as, exclusiva, mede_gasto, condicao, no_id, ordem)
    SELECT 'carregador', 'No carregador', 'sessao', '', NULL, 1, 0, NULL, t.no_id, t.ordem FROM lancamento_tipo t
    WHERE t.identificador = 'carga' AND NOT EXISTS (SELECT 1 FROM lancamento_tipo x WHERE x.identificador = 'carregador');

-- no carregador, o aviso de carregar some (só no aviso que ainda está como veio na v10)
UPDATE aviso SET expressao = 'SE(EM_SESSAO("carregador"); NADA(); SE(energia <= limite_carga; AGORA(); AGORA() + (energia - limite_carga) / SE(EM_SESSAO("pulso"); taxa_uso; taxa_repouso)))'
    WHERE identificador = 'carregar' AND expressao = 'SE(energia <= limite_carga; AGORA(); AGORA() + (energia - limite_carga) / SE(EM_SESSAO("pulso"); taxa_uso; taxa_repouso))'
    AND EXISTS (SELECT 1 FROM lancamento_tipo x WHERE x.identificador = 'carregador');

-- a marca de aplicada, por último (se parar no meio, continua pendente)
INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v18', '1');
