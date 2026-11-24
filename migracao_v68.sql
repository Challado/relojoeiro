-- Relógios 2, migração v68: a próxima vistoria ganha uma coluna própria na tabela dos relógios da página Hoje. A vistoria
-- periódica (v66) vence de tantos em tantos dias e, misturada aos avisos de manutenção, tomava o lugar da manutenção de verdade
-- na Próxima manutenção, no Em e no O que fazer. Agora ela sai dessas três e fica na coluna Próxima vistoria (aparece ou não
-- como as outras: a lista Colunas da página e a opção colunas_hoje; com a vistoria desligada, a coluna e o filtro somem).
-- A coluna é cadastro (Cadastros › Colunas, uma coluna do sistema do modelo.json): entra em cada árvore que já existe, logo
-- depois da Encostado, pelo passo em PHP (migracao_v68_dados), que grava a marca de aplicada no fim.
SET NAMES utf8mb4;
