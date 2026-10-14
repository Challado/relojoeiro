<?php
// Cria um usuário ou troca a senha de um existente:
//   php criar_usuario.php lucas            (pede a senha sem mostrar)
//   php criar_usuario.php lucas minhasenha
require_once __DIR__ . "/lib.php";

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit;
}
$login = trim($argv[1] ?? "");
$senha = $argv[2] ?? "";
if ($login === "") {
    fwrite(STDERR, "Uso: php criar_usuario.php <login> [senha]\n");
    exit(1);
}
if ($senha === "") {
    echo "Senha para " . $login . ": ";
    system("stty -echo");
    $senha = trim((string)fgets(STDIN));
    system("stty echo");
    echo "\n";
}
$res = op_usuarios("salvar", ["login" => $login, "senha" => $senha], "");
if ($res["ok"]) {
    echo $res["mensagem"] . "\n";
} else {
    fwrite(STDERR, implode(" ", $res["erros"]) . "\n");
    exit(1);
}
