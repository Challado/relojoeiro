<?php
// Instala o banco: roda o schema.sql no banco do config.php (MySQL/MariaDB, PostgreSQL ou SQLite, pelo DB_TIPO),
// cada comando traduzido para ele. O banco tem de estar vazio.
//   php instalar.php
// No MySQL e no Postgres, crie antes o banco vazio (veja o README); no SQLite, o arquivo é criado aqui.
if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit;
}
require_once __DIR__ . "/lib.php";

try {
    $tabelas = banco_tabelas();
    if (count($tabelas) > 0) {
        fwrite(STDERR, "O banco já tem tabelas (" . implode(", ", array_slice($tabelas, 0, 5)) . (count($tabelas) > 5 ? "..." : "") . "): a instalação é só num banco vazio.\n"
            . "Para atualizar um banco que já existe, aplique as migrações pela página de Configuração.\n");
        exit(1);
    }
    if (banco_tipo() === "pgsql") {
        // maiúsculas e acentos não contam nas colunas de texto curto, como no MySQL: "Relógio" = "RELOGIO"
        banco_direto("CREATE COLLATION IF NOT EXISTS ci (provider = icu, locale = 'und-u-ks-level1', deterministic = false)");
    }
    $inicio = microtime(true);
    $n = banco_script((string)file_get_contents(__DIR__ . "/schema.sql"));
    // os passos em PHP das migrações (o schema.sql já traz o SQL delas)
    foreach ($MIGRACOES as $m) {
        if (isset($m[3])) {
            call_user_func($m[3]);
        }
    }
    $pendentes = migracoes_pendentes();
    if (count($pendentes) > 0) {
        fwrite(STDERR, "A instalação terminou, mas faltam as migrações " . implode(", ", array_keys($pendentes)) . ": o schema.sql está incompleto.\n");
        exit(1);
    }
    echo "Banco instalado (" . ["mysql" => "MySQL/MariaDB", "pgsql" => "PostgreSQL", "sqlite" => "SQLite"][banco_tipo()] . "): " . $n . " comandos, "
        . count(banco_tabelas()) . " tabelas, em " . round(microtime(true) - $inicio, 1) . " s.\n"
        . "Agora crie o primeiro usuário: php criar_usuario.php <login>\n";
} catch (BancoErro $e) {
    fwrite(STDERR, "Erro na instalação: " . $e->getMessage() . "\n");
    exit(1);
}
