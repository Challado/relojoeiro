-- Relógios 2, migração v67: o rodízio ligado e o passar o relógio de ontem sem uso para hoje entram no registro único das
-- opções em três níveis (opcoes.php), como todas as outras: o padrão do sistema sai dos parâmetros de operação (página
-- Sistema) e vira o padrão da opção (a aba Sistema da página Rodízio › Ajustes do rodízio); a preferência de cada dono sai
-- das colunas do usuário e vai para usuario_opcao (a aba Minhas coleções); a escolha de cada coleção continua onde estava.
-- A retomada do rodízio (o instante em que ele voltou a valer, para não contar uso automático nas horas pausadas) passa a
-- ficar só na coleção: cada uma guarda a que valia para ela.
-- Tudo pelo passo em PHP (migracao_v67_dados): primeiro copia os valores, depois tira as colunas usuario.rodizio_ativo,
-- usuario.rodizio_retomado_em e usuario.recupera_ontem (com as regras delas), e grava a marca de aplicada no fim.
SET NAMES utf8mb4;
