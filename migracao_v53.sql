-- Relógios 2, v53: os totais na coluna Marca.
-- A regra da coluna de um total (ck_total_coluna) passa a aceitar a coluna Marca da tabela dos relógios (a v52 criou a
-- coluna e não trocou a regra). O passo em PHP troca a regra nos quatro bancos e põe, em cada árvore que ainda não tem
-- total nessa coluna, o total pronto "Por marca" (AGRUPADO(marca), no rodapé e na página de totais), no idioma da coleção.
SET NAMES utf8mb4;
