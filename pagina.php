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
// lingua (o seletor), senão o do navegador; a página não lê o banco, então o padrão da instalação chega pelo api.js (ele
// grava o cookie e abre a página de novo, uma vez). O api.js completa o menu com o que é de quem entrou (recurso=conta,
// parte=menu): o seletor da coleção, o aviso da conta de demonstração, as aprovações que esperam, a faixa "Vendo como" e o
// Usuários (só o master). A página pública ($publica: o cadastro) não tem menu, só o idioma
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
                . "</a></p>\n</body>\n</html>\n";
            exit;
        }
    }
    $menu = $publica ? [] : ["index.php" => t("Hoje"), "plano.php" => t("Plano"), "desejos.php" => t("Desejos"), "estatisticas.php" => t("Estatísticas"), "hora.php" => t("Hora certa"),
        "acessorios.php" => t("Acessórios"), "galeria.php" => t("Galeria"), "avisos.php" => t("Avisos"), "configuracao.php" => t("Configuração"), "criterios.php" => t("Critérios"), "grupos.php" => t("Grupos"),
        "execucoes.php" => t("Execuções do cron"), "conta.php" => t("Conta"), "usuarios.php" => t("Usuários"), "cadastros.php" => t("Cadastros"), "ajuda.php" => t("Ajuda")];
    echo "<!doctype html>\n<html lang=\"" . lingua_atual() . "\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<meta name=\"theme-color\" content=\"#1c2530\">\n<title>" . htmlspecialchars(t($titulo), ENT_QUOTES, "UTF-8") . "</title>\n"
        . "<link rel=\"stylesheet\" href=\"estilo.css?v=" . (int)@filemtime(__DIR__ . "/estilo.css") . "\">\n</head>\n<body>\n<nav class=\"topo\">\n";
    // no celular o menu fica recolhido: uma barra com a página atual e o botão que abre a lista (na tela grande o botão some)
    echo "  <button type=\"button\" class=\"menu-botao\" aria-expanded=\"false\" aria-controls=\"menu-itens\"><span class=\"menu-pagina\">" . ($menu[$ativo] ?? htmlspecialchars(t($titulo), ENT_QUOTES, "UTF-8")) . "</span><span class=\"menu-abre\">" . t("Menu") . "</span></button>\n"
        . "  <div class=\"menu-itens\" id=\"menu-itens\">\n";
    foreach ($menu as $url => $nome) {
        echo "    <a href=\"" . $url . "\"" . ($url === $ativo ? " class=\"ativo\"" : "") . ($url === "usuarios.php" ? " data-so-master hidden" : "") . ">" . htmlspecialchars($nome, ENT_QUOTES, "UTF-8") . "</a>\n";
    }
    if (!$publica) {
        echo "    <span class=\"menu-conta\" data-menu-conta></span>\n";
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
    $arq = __DIR__ . "/textos/" . lingua_atual() . ".php";
    echo "<script src=\"api.php?recurso=textos&amp;formato=js&amp;lingua=" . lingua_atual() . "&amp;v=" . (is_file($arq) ? (int)filemtime($arq) : 0) . "\"></script>\n";
    foreach ($scripts as $js) {
        echo "<script src=\"" . $js . "?v=" . (int)@filemtime(__DIR__ . "/" . $js) . "\"></script>\n";
    }
    echo "</body>\n</html>\n";
}
