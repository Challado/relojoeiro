<?php
// Copie para config.php e preencha. O config.php não vai nos pacotes: o seu fica.
define("DB_HOST", "127.0.0.1");
define("DB_NOME", "relogios2");
define("DB_USUARIO", "relogios");
define("DB_SENHA", "a senha do banco");
// obrigatório, com pelo menos 10 caracteres: sem ele o sistema não abre
define("API_TOKEN", "uma-chave-longa-e-secreta");
define("FUSO", "America/Sao_Paulo");
// a API de mensagem (Telegram): o endereço, o destinatário e o título das mensagens; sem o endereço, nada é enviado
define("MSG_ENDPOINT", "");
define("MSG_DESTINATARIO", "");
define("MSG_TITULO", "Relógios");
