-- Relógios 2, v49: corrige as unidades no aviso de carregamento padrão (gasto em dia de uso vs dia civil).
-- Sem alterar avisos que o usuário personalizou. Limita avisos sem descarga previsível em até 366 dias.
SET NAMES utf8mb4;

UPDATE aviso SET expressao = 'SE(EM_SESSAO("carregador"); NADA(); SE(energia <= limite_carga; AGORA(); SE(EM_SESSAO("pulso"); SE(taxa_uso > 0; SE((energia - limite_carga) * HORAS_USO() / (taxa_uso * 24) <= 366; AGORA() + (energia - limite_carga) * HORAS_USO() / (taxa_uso * 24); NADA()); NADA()); SE(taxa_repouso > 0; SE((energia - limite_carga) / taxa_repouso <= 366; AGORA() + (energia - limite_carga) / taxa_repouso; NADA()); NADA()))))'
    WHERE identificador = 'carregar' AND expressao = 'SE(EM_SESSAO("carregador"); NADA(); SE(energia <= limite_carga; AGORA(); AGORA() + (energia - limite_carga) / SE(EM_SESSAO("pulso"); taxa_uso; taxa_repouso)))';

-- A configuração sorteio_casas tem padrão 2 pelo modelo e fallback no PHP; não precisa de coluna nova.
INSERT IGNORE INTO sistema (chave, valor) VALUES ('migracao_v49', '1');
