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
define("FUSO", "America/Sao_Paulo");
// a API de mensagem (Telegram): o endereço, o destinatário e o título das mensagens; sem o endereço, nada é enviado
define("MSG_ENDPOINT", "");
define("MSG_DESTINATARIO", "");
define("MSG_TITULO", "Relógios");

// só para o importar.php, com este sistema no PostgreSQL ou no SQLite: onde está o MySQL do sistema antigo
// define("ANTIGO_HOST", "127.0.0.1");
// define("ANTIGO_PORTA", 3306);
// define("ANTIGO_USUARIO", "relogios");
// define("ANTIGO_SENHA", "a senha do banco antigo");
