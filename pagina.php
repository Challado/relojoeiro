<?php
// A base das páginas (o auth.php do sistema antigo): só o login do site (HTTP Basic) e o topo com o menu. As páginas não
// leem nem gravam nada: o JavaScript delas lê e grava pelo api.php (o back-end único) e monta a tela. Banco desatualizado:
// a API responde 503 e o JavaScript leva para a Configuração, que aplica as migrações.
require_once __DIR__ . "/lib.php";

$USUARIO = usuario_autenticado();
if ($USUARIO === "") {
    header("WWW-Authenticate: Basic realm=\"Relogios\", charset=\"UTF-8\"");
    http_response_code(401);
    header("Content-Type: text/plain; charset=utf-8");
    echo (int)valor("SELECT COUNT(*) FROM usuario") === 0 ? "Nenhum usuário cadastrado. No servidor, rode: php criar_usuario.php <login>\n" : "Acesso restrito.\n";
    exit;
}

// O começo de uma página, como no sistema antigo: o cabeçalho e o menu (Cadastros no fim, a única página que o antigo não
// tinha). Cada página abre o seu <main> e mostra as suas mensagens.
function topo($titulo, $ativo)
{
    $menu = ["index.php" => "Hoje", "configuracao.php" => "Configuração", "criterios.php" => "Critérios", "grupos.php" => "Grupos",
        "execucoes.php" => "Execuções do cron", "usuarios.php" => "Usuários", "cadastros.php" => "Cadastros"];
    echo "<!doctype html>\n<html lang=\"pt-BR\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . htmlspecialchars($titulo, ENT_QUOTES, "UTF-8") . "</title>\n"
        . "<link rel=\"stylesheet\" href=\"estilo.css?v=" . (int)@filemtime(__DIR__ . "/estilo.css") . "\">\n</head>\n<body>\n<nav class=\"topo\">\n";
    foreach ($menu as $url => $nome) {
        echo "  <a href=\"" . $url . "\"" . ($url === $ativo ? " class=\"ativo\"" : "") . ">" . $nome . "</a>\n";
    }
    echo "</nav>\n";
}

// Os scripts da página e o fim dela. Cada script leva a data do arquivo no endereço, para o navegador não usar uma versão
// antiga guardada em cache depois de uma atualização.
function rodape($scripts)
{
    foreach ($scripts as $js) {
        echo "<script src=\"" . $js . "?v=" . (int)@filemtime(__DIR__ . "/" . $js) . "\"></script>\n";
    }
    echo "</body>\n</html>\n";
}
