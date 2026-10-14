<?php
// Traz os dados do sistema antigo para este banco: php importar.php <banco antigo> [--substituir]
// É a mesma importação da API (POST recurso=importacao, acao=importar, banco=..., substituir=1): op_importacao(), no
// operacoes.php, que diz o que vem e de onde. Com --substituir, apaga antes os relógios e o histórico deste banco.
require_once __DIR__ . "/lib.php";

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit;
}
$banco = $argv[1] ?? "";
if (preg_match("/^[A-Za-z0-9_]+\$/", $banco) !== 1) {
    fwrite(STDERR, "Uso: php importar.php <banco antigo> [--substituir]\n");
    exit(1);
}
$res = op_importacao("importar", ["banco" => $banco, "substituir" => in_array("--substituir", $argv, true) ? "1" : ""]);
if (!$res["ok"]) {
    fwrite(STDERR, implode("\n", $res["erros"]) . "\n");
    exit(1);
}
echo "Importado de " . $banco . ":\n";
foreach ($res["contagem"] as $o_que => $n) {
    echo "  " . $o_que . ": " . $n . "\n";
}
