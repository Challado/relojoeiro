-- Relógios 2, migração v60: a coluna Tempo de uso e a escolha das colunas da página Hoje.
-- 1. O formato de total "Tempo" (total.formato = duracao): segundos em dias, horas, minutos e segundos ("1d 4h 30m").
-- 2. A regra da coluna de um total (ck_total_coluna) aceita a coluna Tempo de uso: o passo em PHP troca a regra nos quatro bancos.
-- A escolha das colunas (a opção colunas_hoje) não tem tabela nova: vive no config da coleção, em usuario_opcao e no padrão do
-- sistema, como as outras opções em três níveis (vazio herda; sem escolha, todas as colunas, na ordem de sempre).
SET NAMES utf8mb4;

ALTER TABLE total MODIFY COLUMN formato ENUM('numero','inteiro','decimal','moeda','porcentagem','data','dias','duracao') NOT NULL DEFAULT 'numero';
