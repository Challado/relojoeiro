<?php
// Copie para config.php e preencha. O config.php não vai nos pacotes: o seu fica.

// o banco: "mysql" (MySQL ou MariaDB, o padrão), "pgsql" (PostgreSQL) ou "sqlite" (SQLite, um arquivo só, sem servidor)
define("DB_TIPO", "mysql");
// MySQL e PostgreSQL: o servidor, a porta (0: a padrão, 3306 ou 5432), o banco, o usuário e a senha
define("DB_HOST", "127.0.0.1");
define("DB_PORTA", 0);
define("DB_NOME", "relogios2");
define("DB_USUARIO", "relogios");
define("DB_SENHA", "a senha do banco");
// SQLite: o caminho do arquivo do banco, FORA da pasta que o servidor web publica (ele é criado pelo instalar.php)
define("DB_ARQUIVO", "/var/lib/relogios2/relogios2.sqlite");

// obrigatório, com pelo menos 10 caracteres: sem ele o sistema não abre
define("API_TOKEN", "uma-chave-longa-e-secreta");
// o fuso horário do sistema (e das datas que o banco grava); vazio: o do PHP (date.timezone no php.ini)
define("FUSO", "America/Sao_Paulo");
// a API de mensagem (Telegram): o endereço, o destinatário e o título das mensagens; sem o endereço, nada é enviado
define("MSG_ENDPOINT", "");
define("MSG_DESTINATARIO", "");
define("MSG_TITULO", "Relógios");

// os documentos dos relógios (o manual, a nota fiscal, fotos, vídeos...): a pasta onde os arquivos ficam, FORA da pasta que
// o servidor web publica, com permissão de escrita para o usuário do PHP (sem ela, a página Documentos só avisa que falta)
define("DOCUMENTOS_PASTA", "/var/lib/relogios2/documentos");
// o maior documento aceito, em bytes. Sem esta linha: 100 MB (104857600). 0 ou -1: sem limite do sistema. Valem sempre
// também o limite do PHP (upload_max_filesize e post_max_size do php.ini) e o do nginx (client_max_body_size). O
// MANUAL_LIMITE, o nome antigo, ainda vale quando esta linha não existe. Ex.: 524288000 = 500 MB
// define("DOCUMENTOS_LIMITE", 524288000);
// a cópia de segurança dos documentos dentro do banco: o arquivo vai para a pasta e também para o banco (em pedaços de
// 4 MB); se um dia ele sumir da pasta (um backup do banco restaurado noutro servidor), volta sozinho do banco. Sem esta
// linha: ligada. false: os arquivos ficam só na pasta (o banco fica menor)
// define("DOCUMENTOS_COPIA_BANCO", false);

// só para o importar.php, com este sistema no PostgreSQL ou no SQLite: onde está o MySQL do sistema antigo
// define("ANTIGO_HOST", "127.0.0.1");
// define("ANTIGO_PORTA", 3306);
// define("ANTIGO_USUARIO", "relogios");
// define("ANTIGO_SENHA", "a senha do banco antigo");
