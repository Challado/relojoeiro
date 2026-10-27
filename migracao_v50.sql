-- Relógios 2, v50: o aviso padrão usa a mesma projeção da ficha e respeita o fim das sessões.
-- Só a expressão padrão da v49 muda; o que foi personalizado permanece como estava.
SET NAMES utf8mb4;

UPDATE aviso SET expressao = 'SE(EM_SESSAO("carregador"); NADA(); QUANDO_ENERGIA(limite_carga; 366))' WHERE identificador = 'carregar' AND expressao = 'SE(EM_SESSAO("carregador"); NADA(); SE(energia <= limite_carga; AGORA(); SE(EM_SESSAO("pulso"); SE(taxa_uso > 0; SE((energia - limite_carga) * HORAS_USO() / (taxa_uso * 24) <= 366; AGORA() + (energia - limite_carga) * HORAS_USO() / (taxa_uso * 24); NADA()); NADA()); SE(taxa_repouso > 0; SE((energia - limite_carga) / taxa_repouso <= 366; AGORA() + (energia - limite_carga) / taxa_repouso; NADA()); NADA()))))';

INSERT IGNORE INTO sistema (chave, valor) VALUES ('migracao_v50', '1');
