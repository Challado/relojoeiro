<?php
// A base das páginas: o topo com o menu e o fim com os scripts. As páginas não leem nem gravam nada, nem conferem o
// login: o JavaScript delas lê e grava pelo api.php (o back-end único) e monta a tela, e quem chega sem login recebe o
// 401 da API, que o api.js manda para o login da própria API (api.php?recurso=entrar&volta=<a página>): o navegador pede
// o usuário e a senha e volta para a página. Banco desatualizado: a API responde 503 e o JavaScript leva para a
// Configuração, que aplica as migrações.

// O começo de uma página, como no sistema antigo: o cabeçalho e o menu (Cadastros e Ajuda no fim, as páginas que o antigo
// não tinha). Cada página abre o seu <main> e mostra as suas mensagens.
function topo($titulo, $ativo)
{
    $menu = ["index.php" => "Hoje", "plano.php" => "Plano", "configuracao.php" => "Configuração", "criterios.php" => "Critérios", "grupos.php" => "Grupos",
        "execucoes.php" => "Execuções do cron", "usuarios.php" => "Usuários", "cadastros.php" => "Cadastros",
        "ajuda.php" => "Ajuda"];
    echo "<!doctype html>\n<html lang=\"pt-BR\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<meta name=\"theme-color\" content=\"#1c2530\">\n<title>" . htmlspecialchars($titulo, ENT_QUOTES, "UTF-8") . "</title>\n"
        . "<link rel=\"stylesheet\" href=\"estilo.css?v=" . (int)@filemtime(__DIR__ . "/estilo.css") . "\">\n</head>\n<body>\n<nav class=\"topo\">\n";
    // no celular o menu fica recolhido: uma barra com a página atual e o botão que abre a lista (na tela grande o botão some)
    echo "  <button type=\"button\" class=\"menu-botao\" aria-expanded=\"false\" aria-controls=\"menu-itens\"><span class=\"menu-pagina\">" . ($menu[$ativo] ?? htmlspecialchars($titulo, ENT_QUOTES, "UTF-8")) . "</span><span class=\"menu-abre\">Menu</span></button>\n"
        . "  <div class=\"menu-itens\" id=\"menu-itens\">\n";
    foreach ($menu as $url => $nome) {
        echo "    <a href=\"" . $url . "\"" . ($url === $ativo ? " class=\"ativo\"" : "") . ">" . $nome . "</a>\n";
    }
    echo "  </div>\n</nav>\n"
        . "<script>document.querySelector(\".menu-botao\").addEventListener(\"click\", function () { this.setAttribute(\"aria-expanded\", this.parentNode.classList.toggle(\"aberto\") ? \"true\" : \"false\"); });</script>\n";
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
