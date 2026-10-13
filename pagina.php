<?php
require_once __DIR__ . "/textos/funcoes.php";
// A base das páginas: o topo com o menu e o fim com os scripts. As páginas não leem nem gravam nada, nem conferem o
// login: o JavaScript delas lê e grava pelo api.php (o back-end único) e monta a tela, e quem chega sem login recebe o
// 401 da API, que o api.js manda para o login da própria API (api.php?recurso=entrar&volta=<a página>): o navegador pede
// o usuário e a senha e volta para a página. Banco desatualizado: a API responde 503 e o JavaScript leva para a
// Configuração, que aplica as migrações.

// O começo de uma página, como no sistema antigo: o cabeçalho e o menu (Cadastros e Ajuda no fim, as páginas que o antigo
// não tinha) e o seletor do idioma. Cada página abre o seu <main> e mostra as suas mensagens. O idioma da página: o cookie
// lingua (o seletor), senão o do navegador; a página não lê o banco, então o padrão da instalação chega pelo api.js (ele
// grava o cookie e abre a página de novo, uma vez)
function topo($titulo, $ativo)
{
    lingua_definir(lingua_do_pedido());
    $menu = ["index.php" => t("Hoje"), "plano.php" => t("Plano"), "desejos.php" => t("Desejos"), "estatisticas.php" => t("Estatísticas"), "hora.php" => t("Hora certa"),
        "acessorios.php" => t("Acessórios"), "galeria.php" => t("Galeria"), "configuracao.php" => t("Configuração"), "criterios.php" => t("Critérios"), "grupos.php" => t("Grupos"),
        "execucoes.php" => t("Execuções do cron"), "usuarios.php" => t("Usuários"), "cadastros.php" => t("Cadastros"), "ajuda.php" => t("Ajuda")];
    echo "<!doctype html>\n<html lang=\"" . lingua_atual() . "\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<meta name=\"theme-color\" content=\"#1c2530\">\n<title>" . htmlspecialchars(t($titulo), ENT_QUOTES, "UTF-8") . "</title>\n"
        . "<link rel=\"stylesheet\" href=\"estilo.css?v=" . (int)@filemtime(__DIR__ . "/estilo.css") . "\">\n</head>\n<body>\n<nav class=\"topo\">\n";
    // no celular o menu fica recolhido: uma barra com a página atual e o botão que abre a lista (na tela grande o botão some)
    echo "  <button type=\"button\" class=\"menu-botao\" aria-expanded=\"false\" aria-controls=\"menu-itens\"><span class=\"menu-pagina\">" . ($menu[$ativo] ?? htmlspecialchars(t($titulo), ENT_QUOTES, "UTF-8")) . "</span><span class=\"menu-abre\">" . t("Menu") . "</span></button>\n"
        . "  <div class=\"menu-itens\" id=\"menu-itens\">\n";
    foreach ($menu as $url => $nome) {
        echo "    <a href=\"" . $url . "\"" . ($url === $ativo ? " class=\"ativo\"" : "") . ">" . htmlspecialchars($nome, ENT_QUOTES, "UTF-8") . "</a>\n";
    }
    // o idioma: grava o cookie lingua (um ano) e abre a página de novo
    echo "    <label class=\"menu-lingua\"><span class=\"visualmente-oculto\">" . t("Idioma") . "</span><select aria-label=\"" . htmlspecialchars(t("Idioma"), ENT_QUOTES, "UTF-8") . "\" onchange=\"document.cookie = 'lingua=' + this.value + '; path=/; max-age=31536000; SameSite=Lax'; window.location.reload()\">";
    foreach (LINGUAS as $codigo => $l) {
        echo "<option value=\"" . $codigo . "\" title=\"" . htmlspecialchars($l["nome"], ENT_QUOTES, "UTF-8") . "\"" . ($codigo === lingua_atual() ? " selected" : "") . ">" . htmlspecialchars($l["curto"], ENT_QUOTES, "UTF-8") . "</option>";
    }
    echo "</select></label>\n";
    echo "  </div>\n</nav>\n"
        . "<script>document.querySelector(\".menu-botao\").addEventListener(\"click\", function () { this.setAttribute(\"aria-expanded\", this.parentNode.classList.toggle(\"aberto\") ? \"true\" : \"false\"); });</script>\n";
}

// Os scripts da página e o fim dela. Cada script leva a data do arquivo no endereço, para o navegador não usar uma versão
// antiga guardada em cache depois de uma atualização.
function rodape($scripts)
{
    // os textos das telas no idioma da página, antes de tudo (o t() do api.js já sai traduzido desde a primeira linha)
    $arq = __DIR__ . "/textos/" . lingua_atual() . "/telas.php";
    echo "<script src=\"api.php?recurso=textos&amp;formato=js&amp;lingua=" . lingua_atual() . "&amp;v=" . (is_file($arq) ? (int)filemtime($arq) : 0) . "\"></script>\n";
    foreach ($scripts as $js) {
        echo "<script src=\"" . $js . "?v=" . (int)@filemtime(__DIR__ . "/" . $js) . "\"></script>\n";
    }
    echo "</body>\n</html>\n";
}
