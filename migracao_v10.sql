-- Relógios 2, migração v10: os avisos de carga pelo estado de agora. No winder ou no pulso (o automático carrega no pulso),
-- sem aviso de pôr no winder nem de dar corda; no sol, sem aviso de pôr no sol (antes a conta seguia como se o relógio
-- estivesse guardado, e o aviso aparecia como a próxima atividade de um relógio que já estava carregando). E a data do aviso "Carregar" pelo gasto do estado de agora: no
-- pulso, pelo gasto em uso; guardado, pelo gasto guardado (antes era sempre o de uso: o smartwatch guardado com 89% mostrava
-- "chega a 20% em 1 dia" como próxima manutenção, uma data que andava junto com o tempo e nunca chegava). Abaixo do limite,
-- a data é agora, qualquer que seja o gasto (com o gasto guardado zerado, a conta dividiria por zero e o aviso sumiria).
SET NAMES utf8mb4;

-- só no aviso que ainda está como veio na v9, ou com a troca feita à mão pela fórmula sem a proteção
UPDATE aviso SET expressao = 'SE(energia <= limite_carga; AGORA(); AGORA() + (energia - limite_carga) / SE(EM_SESSAO("pulso"); taxa_uso; taxa_repouso))'
    WHERE identificador = 'carregar' AND expressao IN ('AGORA() + (energia - limite_carga) / taxa_uso',
        'AGORA() + (energia - limite_carga) / SE(EM_SESSAO("pulso"); taxa_uso; taxa_repouso)');

-- carregando, não precisa de corda nem de winder: no winder, ou no pulso quando o pulso dá corda (o automático, que tem
-- carga_pulso_reserva; o de corda manual não carrega no pulso e continua avisando). No sol, não precisa de sol
UPDATE aviso SET expressao = 'SE(OU(EM_SESSAO("winder"); E(EM_SESSAO("pulso"); PADRAO(carga_pulso_reserva; 0) > 0)); NADA(); AGORA() + autonomia_restante / 86400 - limite_carga / 100 * reserva_horas / 24)'
    WHERE identificador IN ('corda', 'winder') AND expressao = 'AGORA() + autonomia_restante / 86400 - limite_carga / 100 * reserva_horas / 24';
UPDATE aviso SET expressao = 'SE(EM_SESSAO("sol"); NADA(); AGORA() + (energia - limite_carga) / (100 / reserva_dias))'
    WHERE identificador = 'sol' AND expressao = 'AGORA() + (energia - limite_carga) / (100 / reserva_dias)';

-- a marca de que a v10 foi aplicada: por último, para uma v10 que parou no meio continuar pendente
INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v10', '1');
