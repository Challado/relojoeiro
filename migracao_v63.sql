-- Relógios 2, migração v63: o total vale embaixo de qualquer coluna do cadastro de colunas.
-- Até a v62 a regra do banco (ck_total_coluna) só aceitava as 14 colunas de sempre pelo nome. Agora a coluna de um total é a chave de uma
-- coluna do cadastro (as de sempre e as que a pessoa monta: c_<identificador>), e quem confere que ela existe e que o formato do total
-- combina com o que ela mostra é a API. O passo em PHP troca a regra do banco (nos quatro bancos) por uma que só pede um texto não
-- vazio; o tamanho da coluna não muda (por isso o identificador de uma coluna montada tem até 18 letras).
SET NAMES utf8mb4;
