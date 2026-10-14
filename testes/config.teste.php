<?php
// O config.php dos testes: o testes/paridade.sh aponta o sistema para ele (RELOGIOS_CONFIG), e o config.php de verdade fica
// intocado. Tudo vem do ambiente, com o padrão de uma máquina de desenvolvimento:
//   RELOGIOS_DB            o banco desta rodada: sqlite, pgsql, mysql ou firebird
//   RELOGIOS_TESTE_PASTA   a pasta temporária da rodada (o arquivo do SQLite, os documentos e as cópias de segurança)
//   TESTE_BANCO            o nome do banco no MySQL e no PostgreSQL (padrão relogios_teste; o paridade.sh apaga e recria)
//   TESTE_MYSQL_HOST, TESTE_MYSQL_PORTA, TESTE_MYSQL_USUARIO, TESTE_MYSQL_SENHA   (127.0.0.1, 3306, teste, teste)
//   TESTE_PG_HOST, TESTE_PG_PORTA, TESTE_PG_USUARIO, TESTE_PG_SENHA               (127.0.0.1, 5432, postgres, teste)
//   TESTE_FB_ARQUIVO, TESTE_FB_USUARIO, TESTE_FB_SENHA       (/var/lib/firebird/3.0/data/relogios_teste.fdb, SYSDBA, masterkey)
$teste_env = function ($nome, $padrao) {
    $v = getenv($nome);
    return $v === false || $v === "" ? $padrao : $v;
};
$teste_db = $teste_env("RELOGIOS_DB", "sqlite");
$teste_pasta = rtrim($teste_env("RELOGIOS_TESTE_PASTA", sys_get_temp_dir() . "/relojoeiro-teste"), "/");
define("DB_TIPO", $teste_db);
if ($teste_db === "pgsql") {
    define("DB_HOST", $teste_env("TESTE_PG_HOST", "127.0.0.1"));
    define("DB_PORTA", (int)$teste_env("TESTE_PG_PORTA", "5432"));
    define("DB_NOME", $teste_env("TESTE_BANCO", "relogios_teste"));
    define("DB_USUARIO", $teste_env("TESTE_PG_USUARIO", "postgres"));
    define("DB_SENHA", $teste_env("TESTE_PG_SENHA", "teste"));
} elseif ($teste_db === "firebird") {
    define("DB_HOST", $teste_env("TESTE_FB_HOST", "localhost"));
    define("DB_PORTA", 0);
    define("DB_NOME", $teste_env("TESTE_FB_ARQUIVO", "/var/lib/firebird/3.0/data/relogios_teste.fdb"));
    define("DB_USUARIO", $teste_env("TESTE_FB_USUARIO", "SYSDBA"));
    define("DB_SENHA", $teste_env("TESTE_FB_SENHA", "masterkey"));
} else {
    define("DB_HOST", $teste_env("TESTE_MYSQL_HOST", "127.0.0.1"));
    define("DB_PORTA", (int)$teste_env("TESTE_MYSQL_PORTA", "3306"));
    define("DB_NOME", $teste_env("TESTE_BANCO", "relogios_teste"));
    define("DB_USUARIO", $teste_env("TESTE_MYSQL_USUARIO", "teste"));
    define("DB_SENHA", $teste_env("TESTE_MYSQL_SENHA", "teste"));
}
define("DB_ARQUIVO", $teste_pasta . "/" . $teste_db . ".sqlite");
// o token que o cenário manda (o padrão dele)
define("API_TOKEN", "token-de-teste-123");
define("FUSO", $teste_env("RELOGIOS_FUSO", "America/Sao_Paulo"));
// sem mensagens de verdade: o Telegram fica sem endereço (a API de alerta recusa, e o cron registra o erro)
define("MSG_ENDPOINT", "");
define("MSG_DESTINATARIO", "");
define("MSG_TITULO", "Relógios");
define("DOCUMENTOS_PASTA", $teste_pasta . "/documentos_" . $teste_db);
define("BACKUP_PASTA", $teste_pasta . "/copias_" . $teste_db);
