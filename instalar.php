<?php
// Instala o banco: roda o schema.sql no banco do config.php (MySQL/MariaDB, PostgreSQL, SQLite ou Firebird, pelo
// DB_TIPO), cada comando traduzido para ele. O banco tem de estar vazio.
//   php instalar.php                 em português
//   php instalar.php --lingua=en     noutro idioma (en, zh-CN, hi, es, fr): o cadastro inicial nele, e ele vira o padrão
// No MySQL, no Postgres e no Firebird, crie antes o banco vazio (veja o README); no SQLite, o arquivo é criado aqui. É a mesma
// instalação da API (POST recurso=instalacao, acao=instalar, com o token): instalar_banco(), no lib.php.
if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit;
}
require_once __DIR__ . "/lib.php";
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, "--lingua=") === 0) {
        lingua_definir(substr($a, 9));
    }
}

try {
    $res = instalar_banco();
} catch (BancoErro $e) {
    $res = ["ok" => false, "erros" => [t("Erro na instalação: {1}", [1 => $e->getMessage()])]];
}
if (!$res["ok"]) {
    fwrite(STDERR, implode("\n", $res["erros"]) . "\n");
    exit(1);
}
echo t("{1}\nPela linha de comando: php criar_usuario.php <login>\n", [1 => $res["mensagem"]]);
