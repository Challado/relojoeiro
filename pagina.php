<?php
require_once __DIR__ . "/textos/funcoes.php";
// o núcleo, só para o topo() conferir o login (aqui fora: o lib.php define variáveis globais). Sem o config.php não há
// sistema nem dado: a página sai e a API explica
if (is_file(getenv("RELOGIOS_CONFIG") ?: __DIR__ . "/config.php")) {
    require_once __DIR__ . "/lib.php";
}
// A base das páginas: o topo com o menu e o fim com os scripts. As páginas não leem nem gravam nada: o JavaScript delas
// lê e grava pelo api.php (o back-end único) e monta a tela. Mas nenhuma sai antes do login: o topo() confere o usuário e a
// senha (pagina_login(), no lib.php) antes de mandar a primeira linha, e sem eles responde 401 com o pedido de senha do
// navegador (o mesmo realm da API, que guarda o login para as duas). Se o login cair depois (a senha trocada noutro
// lugar), o 401 da API leva para o login dela (api.php?recurso=entrar&volta=<a página>). Banco desatualizado: a API
// responde 503 e o JavaScript leva para a Configuração, que aplica as migrações.

// O começo de uma página, como no sistema antigo: o cabeçalho e o menu (Cadastros e Ajuda no fim, as páginas que o antigo
// não tinha) e o seletor do idioma. Cada página abre o seu <main> e mostra as suas mensagens. O idioma da página: o cookie
// lingua (o seletor), senão o do navegador; a página não lê o banco, então o idioma padrão (o da conta, da coleção ou da
// instalação) chega pelo api.js, no idioma_padrao do menu (ele grava o cookie e abre a página de novo, uma vez). O api.js
// completa o menu com o que é de quem entrou (recurso=conta, parte=menu): o seletor da coleção, o aviso da conta de
// demonstração, as aprovações que esperam, a faixa "Vendo como", o Usuários (só o master) e o idioma padrão. A página pública ($publica: o cadastro) não tem menu, só o idioma
function topo($titulo, $ativo, $publica = false)
{
    lingua_definir(lingua_do_pedido());
    // antes de qualquer saída: o login (a página pública, o cadastro, não pede)
    if (!$publica && function_exists("pagina_login")) {
        if (!pagina_login()) {
            http_response_code(401);
            header("WWW-Authenticate: Basic realm=\"Relogios\", charset=\"UTF-8\"");
            header("Cache-Control: no-store");
            header("Content-Type: text/html; charset=utf-8");
            // só o que o navegador mostra se a pessoa cancelar o pedido de senha
            echo "<!doctype html>\n<html lang=\"" . lingua_atual() . "\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>"
                . htmlspecialchars(t("Entrar"), ENT_QUOTES, "UTF-8") . "</title>\n</head>\n<body style=\"font-family: system-ui, sans-serif; max-width: 34rem; margin: 3rem auto; padding: 0 1rem\">\n<h1>"
                . htmlspecialchars(t("Entrar"), ENT_QUOTES, "UTF-8") . "</h1>\n<p>" . htmlspecialchars(t("Para abrir o sistema, entre com o seu login e a sua senha."), ENT_QUOTES, "UTF-8")
                . "</p>\n<p><a href=\"\">" . htmlspecialchars(t("Tentar de novo"), ENT_QUOTES, "UTF-8") . "</a> · <a href=\"cadastro.php\">" . htmlspecialchars(t("Criar conta"), ENT_QUOTES, "UTF-8")
                . "</a> / <a href=\"cadastro.php?esqueci=1\">" . htmlspecialchars(t("Recuperar senha"), ENT_QUOTES, "UTF-8") . "</a></p>\n</body>\n</html>\n";
            exit;
        }
    }
    // o menu em grupos: o que é do mesmo assunto fica junto (um item solto é uma página; um grupo abre a lista dele)
    $menu = $publica ? [] : [
        "index.php" => t("Hoje"),
        "avisos.php" => t("Avisos"),
        "hora.php" => t("Hora certa"),
        t("Rodízio") => ["plano.php" => t("Plano"), "modos.php" => t("Modos de rodízio"), "criterios.php" => t("Critérios de escolha")],
        t("Coleção") => ["galeria.php" => t("Galeria"), "ficha.php" => t("Ficha"), "acessorios.php" => t("Acessórios"), "winders.php" => t("Winders"), "programas.php" => t("Programas"), "movimentos.php" => t("Movimentos"), "desejos.php" => t("Desejos"), "estatisticas.php" => t("Estatísticas"), "totais.php" => t("Totais"),
            "grupos.php" => t("Grupos")],
        "relatorios.php" => t("Relatórios"),
        t("Sistema") => ["configuracao.php" => t("Configuração"), "cadastros.php" => t("Cadastros"), "execucoes.php" => t("Execuções do cron"), "usuarios.php" => t("Usuários")],
        t("Conta") => ["conta.php" => t("Minha conta"), "aprovacoes.php" => t("Aprovações")],
        "ajuda.php" => t("Ajuda"),
    ];
    // o nome da página atual (a barra do celular mostra ele)
    $atual = null;
    foreach ($menu as $chave => $item) {
        if (is_array($item) && isset($item[$ativo])) {
            $atual = $item[$ativo];
        } elseif (!is_array($item) && $chave === $ativo) {
            $atual = $item;
        }
    }
    echo "<!doctype html>\n<html lang=\"" . lingua_atual() . "\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<meta name=\"theme-color\" content=\"#1c2530\">\n<title>" . htmlspecialchars(t($titulo), ENT_QUOTES, "UTF-8") . "</title>\n"
        . "<link rel=\"stylesheet\" href=\"estilo.css?v=" . (int)@filemtime(__DIR__ . "/estilo.css") . "\">\n"
        // o aplicativo no celular (PWA): o manifesto pela API (com as credenciais: o start_url entra pelo aparelho lembrado), o
        // ícone, e o que o iPhone ainda lê fora do manifesto (tela cheia e o nome na tela de início)
        . "<link rel=\"manifest\" href=\"api.php?recurso=manifest\" crossorigin=\"use-credentials\">\n<link rel=\"icon\" href=\"icone-192.png\" type=\"image/png\">\n"
        . "<link rel=\"apple-touch-icon\" href=\"icone-180.png\">\n<meta name=\"mobile-web-app-capable\" content=\"yes\">\n<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n"
        . "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">\n<meta name=\"apple-mobile-web-app-title\" content=\"Relojoeiro\">\n</head>\n<body>\n<nav class=\"topo\">\n";
    // o reloginho no canto da barra (o relojinho.js desenha nele a hora certa, com um modelo por hora): um clique abre a Hora certa
    echo "  <a class=\"relojinho\" href=\"hora.php\" aria-label=\"" . htmlspecialchars(t("Hora certa"), ENT_QUOTES, "UTF-8") . "\"><canvas width=\"60\" height=\"60\" aria-hidden=\"true\"></canvas></a>\n";
    // no celular o menu fica recolhido: uma barra com a página atual e o botão que abre a lista (na tela grande o botão some)
    echo "  <button type=\"button\" class=\"menu-botao\" aria-expanded=\"false\" aria-controls=\"menu-itens\"><span class=\"menu-pagina\">" . htmlspecialchars($atual ?? t($titulo), ENT_QUOTES, "UTF-8") . "</span><span class=\"menu-abre\">" . t("Menu") . "</span></button>\n"
        . "  <div class=\"menu-itens\" id=\"menu-itens\">\n";
    $link = function ($url, $nome) use ($ativo) {
        return "<a href=\"" . $url . "\"" . ($url === $ativo ? " class=\"ativo\" aria-current=\"page\"" : "") . ($url === "usuarios.php" ? " data-so-master hidden" : "") . ">" . htmlspecialchars($nome, ENT_QUOTES, "UTF-8") . "</a>";
    };
    foreach ($menu as $chave => $item) {
        if (is_array($item)) {
            // um grupo: o botão abre a lista (na tela grande, por cima da página; no celular, a lista fica sempre aberta)
            $dentro = isset($item[$ativo]);
            echo "    <div class=\"menu-grupo" . ($dentro ? " ativo" : "") . "\"><button type=\"button\" class=\"menu-grupo-botao\" aria-expanded=\"false\">" . htmlspecialchars($chave, ENT_QUOTES, "UTF-8")
                . "</button><div class=\"menu-sub\">";
            foreach ($item as $url => $nome) {
                echo $link($url, $nome);
            }
            echo "</div></div>\n";
        } else {
            echo "    " . $link($chave, $item) . "\n";
        }
    }
    if (!$publica) {
        echo "    <span class=\"menu-conta\" data-menu-conta></span>\n";
    }
    // o idioma: grava o cookie lingua (um ano) e abre a página de novo
    echo "    <label class=\"menu-lingua\"><span class=\"visualmente-oculto\">" . t("Idioma") . "</span><select aria-label=\"" . htmlspecialchars(t("Idioma"), ENT_QUOTES, "UTF-8") . "\" onchange=\"document.cookie = 'lingua=' + this.value + '; path=/; max-age=31536000; SameSite=Lax'; document.cookie = 'lingua_auto=; path=/; max-age=0; SameSite=Lax'; window.location.reload()\">";
    foreach (LINGUAS as $codigo => $l) {
        echo "<option value=\"" . $codigo . "\" title=\"" . htmlspecialchars($l["nome"], ENT_QUOTES, "UTF-8") . "\"" . ($codigo === lingua_atual() ? " selected" : "") . ">" . htmlspecialchars($l["curto"], ENT_QUOTES, "UTF-8") . "</option>";
    }
    echo "</select></label>\n";
    echo "  </div>\n</nav>\n"
        . "<script>document.querySelector(\".menu-botao\").addEventListener(\"click\", function () { this.setAttribute(\"aria-expanded\", this.parentNode.classList.toggle(\"aberto\") ? \"true\" : \"false\"); });\n"
        // os grupos: um clique abre (e fecha os outros); clicar fora ou o Esc fecha
        . "document.querySelectorAll(\".menu-grupo-botao\").forEach(function (b) { b.addEventListener(\"click\", function () { var g = b.parentNode, abre = !g.classList.contains(\"aberto\"); "
        . "document.querySelectorAll(\".menu-grupo.aberto\").forEach(function (x) { x.classList.remove(\"aberto\"); x.firstChild.setAttribute(\"aria-expanded\", \"false\"); }); "
        . "if (abre) { g.classList.add(\"aberto\"); b.setAttribute(\"aria-expanded\", \"true\"); } }); });\n"
        . "document.addEventListener(\"click\", function (e) { if (!e.target.closest(\".menu-grupo\")) { document.querySelectorAll(\".menu-grupo.aberto\").forEach(function (x) { x.classList.remove(\"aberto\"); x.firstChild.setAttribute(\"aria-expanded\", \"false\"); }); } });\n"
        . "document.addEventListener(\"keydown\", function (e) { if (e.key === \"Escape\") { document.querySelectorAll(\".menu-grupo.aberto\").forEach(function (x) { x.classList.remove(\"aberto\"); x.firstChild.setAttribute(\"aria-expanded\", \"false\"); }); } });</script>\n";
}

// Os scripts da página e o fim dela. Cada script leva a data do arquivo no endereço, para o navegador não usar uma versão
// antiga guardada em cache depois de uma atualização.
function rodape($scripts)
{
    // os textos das telas no idioma da página, antes de tudo (o t() do api.js já sai traduzido desde a primeira linha)
    $arq = __DIR__ . "/textos/" . lingua_atual() . ".php";
    echo "<script src=\"api.php?recurso=textos&amp;formato=js&amp;lingua=" . lingua_atual() . "&amp;v=" . (is_file($arq) ? (int)filemtime($arq) : 0) . "\"></script>\n";
    foreach ($scripts as $js) {
        echo "<script src=\"" . $js . "?v=" . (int)@filemtime(__DIR__ . "/" . $js) . "\"></script>\n";
    }
    // o reloginho da barra e do favicon, em toda página (depois do api.js, pelo t() dos nomes dos modelos)
    echo "<script src=\"relojinho.js?v=" . (int)@filemtime(__DIR__ . "/relojinho.js") . "\"></script>\n";
    echo "</body>\n</html>\n";
}
