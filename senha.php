<?php
// Troca a senha de um usuário pelo servidor (a linha de comando: só quem tem o servidor na mão). A senha pode ser qualquer
// uma: não passa pela força da Configuração. Criar usuário não é aqui: é pela API (recurso=usuarios, acao=salvar; o
// primeiro, com o token) ou pelas telas, com as regras de sempre.
//   php senha.php lucas novasenha     troca direto
//   php senha.php                     (ou faltando um dos dois) o menu com os usuários: escolhe um e digita a senha
//                                     nova duas vezes, sem aparecer
require_once __DIR__ . "/lib.php";

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit;
}

// uma linha digitada; $escondida: sem aparecer na tela (a senha)
function senha_ler($pergunta, $escondida = false)
{
    echo $pergunta;
    if ($escondida) {
        system("stty -echo 2>/dev/null");
    }
    $linha = fgets(STDIN);
    if ($escondida) {
        system("stty echo 2>/dev/null");
        echo "\n";
    }
    return $linha === false ? null : rtrim($linha, "\r\n");
}

function senha_fim($res)
{
    if ($res["ok"]) {
        echo $res["mensagem"] . "\n";
        exit(0);
    }
    fwrite(STDERR, implode(" ", $res["erros"]) . "\n");
    exit(1);
}

$login = trim((string)($argv[1] ?? ""));
$senha = (string)($argv[2] ?? "");
if ($login !== "" && $senha !== "") {
    senha_fim(senha_servidor_trocar($login, $senha));
}

// o menu: os usuários, um por linha, com o número para escolher
$contas = senha_servidor_contas();
if (count($contas) === 0) {
    fwrite(STDERR, t("Nenhum usuário cadastrado: crie o primeiro pela API (recurso=usuarios, acao=salvar, com o token) ou pelas telas.") . "\n");
    exit(1);
}
echo t("Trocar a senha de qual usuário?") . "\n";
foreach ($contas as $i => $c) {
    echo sprintf("  %2d. ", $i + 1) . $c["login"] . ($c["papel"] === "master" ? " " . t("(master)") : "") . ((int)$c["bloqueado"] === 1 ? " " . t("(bloqueado)") : "") . "\n";
}
$escolha = senha_ler(t("Número (ou vazio para sair): "));
if ($escolha === null || trim($escolha) === "") {
    exit(0);
}
$n = ctype_digit(trim($escolha)) ? (int)trim($escolha) : 0;
if ($n < 1 || $n > count($contas)) {
    fwrite(STDERR, t("Escolha um número da lista.") . "\n");
    exit(1);
}
$alvo = $contas[$n - 1]["login"];
$nova = senha_ler(t("Senha nova para {1}: ", [1 => $alvo]), true);
$outra = senha_ler(t("A mesma senha de novo: "), true);
if ($nova === null || $nova !== $outra) {
    fwrite(STDERR, t("As duas senhas não batem: nada mudou.") . "\n");
    exit(1);
}
senha_fim(senha_servidor_trocar($alvo, $nova));
