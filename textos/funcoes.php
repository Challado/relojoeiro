<?php
// Os idiomas do sistema. Todo texto que alguém lê (as telas, as mensagens da API e do cron, a ajuda, o manual, a reconstrução)
// passa por t("o texto em português", ["parametro" => valor]): o português é a chave (como no gettext), e cada idioma tem um
// arquivo só, textos/<idioma>.php, com as traduções divididas em partes (telas: o que as páginas e os scripts escrevem, e que
// vai para o navegador; api: as mensagens da API e do cron; cadastro: o cadastro inicial, traduzido na instalação; ajuda,
// manual e reconstrucao: a documentação). Os parâmetros
// entre chaves ({nome}) são trocados depois da tradução, em qualquer ordem que o idioma pedir.
// O que falta num idioma sai em português; com TEXTOS_ESTRITO = true no config.php (para quem traduz), o t() para com o erro
// "Falta a tradução em en (textos/en.php): ..." e as telas mostram o texto entre ⟦ ⟧. php testes/textos.php confere tudo de
// uma vez, sem abrir nenhuma tela.
// O idioma de um pedido: lingua= no pedido, senão o cookie lingua (o seletor do menu), senão o padrão da instalação (a
// Configuração), senão o Accept-Language do navegador, senão português.
// Esta pasta não abre pelo navegador (o .htaccess e o nginx-relogios.conf bloqueiam); aberto direto, não faz nada.
if (count(get_included_files()) === 1) {
    http_response_code(404);
    exit;
}

// os idiomas, na ordem do menu: o código, o nome no próprio idioma, o curto (o seletor do menu), o locale (BCP 47: os números
// nas telas), a data (com o ano e a curta, sem ele; d, m e Y como no date() do PHP) e os separadores dos números (o decimal e o
// de milhar). Um idioma novo é uma linha aqui e o arquivo textos/<código>.php (veja o README, "Os idiomas").
const LINGUAS = [
    "pt-BR" => ["nome" => "Português", "curto" => "PT", "locale" => "pt-BR", "data" => "d/m/Y", "data_curta" => "d/m", "decimal" => ",", "milhar" => "."],
    "en" => ["nome" => "English", "curto" => "EN", "locale" => "en-US", "data" => "m/d/Y", "data_curta" => "m/d", "decimal" => ".", "milhar" => ","],
    "zh-CN" => ["nome" => "中文（简体）", "curto" => "中文", "locale" => "zh-CN", "data" => "Y-m-d", "data_curta" => "m-d", "decimal" => ".", "milhar" => ","],
    "hi" => ["nome" => "हिन्दी", "curto" => "हिं", "locale" => "hi-IN", "data" => "d/m/Y", "data_curta" => "d/m", "decimal" => ".", "milhar" => ","],
    "es" => ["nome" => "Español", "curto" => "ES", "locale" => "es-ES", "data" => "d/m/Y", "data_curta" => "d/m", "decimal" => ",", "milhar" => "."],
    "fr" => ["nome" => "Français", "curto" => "FR", "locale" => "fr-FR", "data" => "d/m/Y", "data_curta" => "d/m", "decimal" => ",", "milhar" => " "],
];
// as partes do arquivo de cada idioma
const TEXTOS_PARTES = ["telas", "api", "cadastro", "ajuda", "manual", "reconstrucao"];

$GLOBALS["LINGUA"] = "pt-BR";
$GLOBALS["TEXTOS"] = null;
$GLOBALS["TEXTOS_TK"] = [];
$GLOBALS["TEXTOS_GLOBAIS"] = [];

// um código de idioma conhecido (aceita en-GB, es-MX, zh, pt...: o mais perto que o sistema tem); null se nenhum
function lingua_valida($l)
{
    $l = trim((string)$l);
    if ($l === "") {
        return null;
    }
    foreach (array_keys(LINGUAS) as $k) {
        if (strcasecmp($k, $l) === 0) {
            return $k;
        }
    }
    $base = strtolower(substr($l, 0, 2));
    foreach (array_keys(LINGUAS) as $k) {
        if (strtolower(substr($k, 0, 2)) === $base) {
            return $k;
        }
    }
    return null;
}

// o idioma de um pedido: lingua=, o cookie, o padrão da instalação ($padrao), o Accept-Language, português
function lingua_do_pedido($padrao = null)
{
    foreach ([$_REQUEST["lingua"] ?? null, $_COOKIE["lingua"] ?? null, $padrao] as $l) {
        if (($v = lingua_valida($l)) !== null) {
            return $v;
        }
    }
    foreach (explode(",", (string)($_SERVER["HTTP_ACCEPT_LANGUAGE"] ?? "")) as $parte) {
        if (($v = lingua_valida(explode(";", $parte)[0])) !== null) {
            return $v;
        }
    }
    return "pt-BR";
}

function lingua_definir($l)
{
    $GLOBALS["LINGUA"] = lingua_valida($l) ?? "pt-BR";
    $GLOBALS["TEXTOS"] = null;
    // as listas do código montadas ao carregar (antes do idioma do pedido) saem de novo, no idioma
    foreach ($GLOBALS["TEXTOS_GLOBAIS"] as $nome => $original) {
        $GLOBALS[$nome] = textos_traduzir_lista($original);
    }
}

function lingua_atual()
{
    return $GLOBALS["LINGUA"];
}

// o arquivo de um idioma (textos/<idioma>.php: as partes, cada uma o texto em português => a tradução)
function textos_arquivo($l)
{
    static $lidos = [];
    if (!isset($lidos[$l])) {
        $arq = __DIR__ . "/" . $l . ".php";
        $x = $l !== "pt-BR" && is_file($arq) ? include $arq : [];
        $lidos[$l] = is_array($x) ? $x : [];
    }
    return $lidos[$l];
}

// uma parte do arquivo de um idioma
function textos_parte($l, $parte)
{
    $x = textos_arquivo($l)[$parte] ?? [];
    return is_array($x) ? $x : [];
}

// o modo estrito (TEXTOS_ESTRITO = true no config.php): a tradução que falta para com erro, em vez de sair em português
function textos_estrito()
{
    return defined("TEXTOS_ESTRITO") && TEXTOS_ESTRITO === true;
}

// um texto que não se traduz de propósito (o alfabeto da OTAN, os meses do grupo data-hora militar): sai como está. A
// conferência dos textos fixos (testes/texto-fixo.cjs) aceita o que passa por aqui
function fixo($s)
{
    return $s;
}

// o texto no idioma do pedido, com os parâmetros trocados ({nome} => o valor)
function t($s, $p = [])
{
    $s = (string)$s;
    $l = $GLOBALS["LINGUA"];
    if ($l !== "pt-BR" && $s !== "") {
        if ($GLOBALS["TEXTOS"] === null) {
            $GLOBALS["TEXTOS"] = [];
            foreach (textos_arquivo($l) as $parte) {
                $GLOBALS["TEXTOS"] += is_array($parte) ? $parte : [];
            }
        }
        if (isset($GLOBALS["TEXTOS"][$s]) && $GLOBALS["TEXTOS"][$s] !== "") {
            $s = $GLOBALS["TEXTOS"][$s];
        } elseif (textos_estrito()) {
            throw new Error("Falta a tradução em " . $l . " (textos/" . $l . ".php): \"" . $s . "\"");
        }
    }
    if (count($p) > 0) {
        $troca = [];
        foreach ($p as $k => $v) {
            $troca["{" . $k . "}"] = (string)$v;
        }
        $s = strtr($s, $troca);
    }
    return $s;
}

// marca um texto do dicionário que é traduzido depois, num t($variavel) ou numa lista registrada (textos_globais) (a
// conferência dos dicionários acha a chave aqui)
function tk($s)
{
    $GLOBALS["TEXTOS_TK"][$s] = true;
    return $s;
}

// as listas globais do código com textos marcados por tk() (os dias, os canais, as funções das fórmulas...): montadas ao
// carregar o arquivo, em português; registradas aqui, o lingua_definir() as refaz no idioma do pedido
function textos_globais($nomes)
{
    foreach ($nomes as $nome) {
        if (!isset($GLOBALS["TEXTOS_GLOBAIS"][$nome])) {
            $GLOBALS["TEXTOS_GLOBAIS"][$nome] = $GLOBALS[$nome];
        }
        $GLOBALS[$nome] = textos_traduzir_lista($GLOBALS["TEXTOS_GLOBAIS"][$nome]);
    }
}

// uma lista com os textos marcados por tk() traduzidos (o resto, como os identificadores, fica como está)
function textos_traduzir_lista($x)
{
    if (is_array($x)) {
        foreach ($x as $k => $v) {
            $x[$k] = textos_traduzir_lista($v);
        }
        return $x;
    }
    return is_string($x) && isset($GLOBALS["TEXTOS_TK"][$x]) ? t($x) : $x;
}

// o mesmo, pronto para o HTML (o texto e os atributos das páginas): os < > & " escapados
function te($s, $p = [])
{
    return htmlspecialchars(t($s, $p), ENT_QUOTES, "UTF-8");
}

// as datas e os números no formato do idioma (o de LINGUAS): 13/10/2026 (pt, es, fr, hi), 10/13/2026 (en), 2026-10-13 (zh)
function data_txt($quando, $com_hora = false, $curta = false)
{
    $ts = is_numeric($quando) ? (int)$quando : strtotime((string)$quando);
    if ($ts === false) {
        return (string)$quando;
    }
    $l = LINGUAS[$GLOBALS["LINGUA"]];
    return date(($curta ? $l["data_curta"] : $l["data"]) . ($com_hora ? " H:i" : ""), $ts);
}

function numero_txt($n, $casas = 2, $minimo = null)
{
    $dec = LINGUAS[$GLOBALS["LINGUA"]]["decimal"];
    $mil = LINGUAS[$GLOBALS["LINGUA"]]["milhar"];
    $s = number_format((float)$n, $casas, $dec, $mil);
    if ($minimo !== null && $casas > $minimo && strpos($s, $dec) !== false) {
        $s = rtrim($s, "0");
        $sobra = strlen($s) - strpos($s, $dec) - 1;
        $s = $sobra < $minimo ? $s . str_repeat("0", $minimo - $sobra) : $s;
        $s = rtrim($s, $dec);
    }
    return $s;
}

// um número sem o separador de milhar, com até $casas decimais (sem os zeros que sobram), no separador decimal do idioma
function decimal_txt($n, $casas = 2)
{
    $s = rtrim(rtrim(number_format(round((float)$n, (int)$casas), (int)$casas, ".", ""), "0"), ".");
    return str_replace(".", LINGUAS[$GLOBALS["LINGUA"]]["decimal"], $s === "-0" ? "0" : $s);
}

function dinheiro_txt($v)
{
    return "R$ " . numero_txt($v, 2);
}
