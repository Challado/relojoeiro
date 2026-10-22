-- Relógios 2, migração v46: as rotações por dia das posições dos winders com valores possíveis. O campo pos_tpd (Rotações
-- por dia) ganha os valores 650, 850, 1050 e 1250 (os da chave TPD dos winders comuns), que a tabela da página Winders mostra
-- num menu em cada posição; como todo valor possível, eles se mudam em Cadastros › Campos. O passo de dados
-- (migracao_v46_dados) só põe os valores no campo que ainda não tem nenhum; a marca da migração é a linha do sistema.
SET NAMES utf8mb4;

INSERT IGNORE INTO sistema (chave, valor) VALUES ('migracao_v46', '1');
