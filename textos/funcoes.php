<?php
// Os idiomas do sistema. Todo texto que alguém lê (as telas, as mensagens da API, a ajuda, o manual, a reconstrução) passa
// por t("o texto em português", ["parametro" => valor]): o português é a chave (como no gettext) e cada idioma tem o seu
// dicionário em textos/<idioma>/<parte>.php (telas: o que as páginas e os scripts escrevem; api: as mensagens e os textos
// da API e do cron; ajuda, manual e reconstrucao: a documentação). O que falta num idioma sai em português. Os parâmetros
// entre chaves ({nome}) são trocados depois da tradução, em qualquer ordem que o idioma pedir.
// O idioma de um pedido: lingua= no pedido, senão o cookie lingua (o seletor do menu), senão o padrão da instalação (a
// Configuração), senão o Accept-Language do navegador, senão português. testes/textos.php confere os dicionários.
// Esta pasta não abre pelo navegador (o .htaccess e o nginx-relogios.conf bloqueiam); aberto direto, não faz nada.
if (count(get_included_files()) === 1) {
    http_response_code(404);
    exit;
}

// os idiomas, na ordem do menu: o código, o nome no próprio idioma, o curto (o seletor do menu), o locale (BCP 47: os números
// nas telas), a data (com o ano e a curta, sem ele; d, m e Y como no date() do PHP) e os separadores dos números (o decimal e o
// de milhar). Um idioma novo é uma linha aqui e os dicionários em textos/<código>/ (veja o README, "Os idiomas").
const LINGUAS = [
    "pt-BR" => ["nome" => "Português", "curto" => "PT", "locale" => "pt-BR", "data" => "d/m/Y", "data_curta" => "d/m", "decimal" => ",", "milhar" => "."],
    "en" => ["nome" => "English", "curto" => "EN", "locale" => "en-US", "data" => "m/d/Y", "data_curta" => "m/d", "decimal" => ".", "milhar" => ","],
    "zh-CN" => ["nome" => "中文（简体）", "curto" => "中文", "locale" => "zh-CN", "data" => "Y-m-d", "data_curta" => "m-d", "decimal" => ".", "milhar" => ","],
    "hi" => ["nome" => "हिन्दी", "curto" => "हिं", "locale" => "hi-IN", "data" => "d/m/Y", "data_curta" => "d/m", "decimal" => ".", "milhar" => ","],
    "es" => ["nome" => "Español", "curto" => "ES", "locale" => "es-ES", "data" => "d/m/Y", "data_curta" => "d/m", "decimal" => ",", "milhar" => "."],
    "fr" => ["nome" => "Français", "curto" => "FR", "locale" => "fr-FR", "data" => "d/m/Y", "data_curta" => "d/m", "decimal" => ",", "milhar" => " "],
];
// as partes dos dicionários: as duas primeiras sempre; as da documentação, quando um texto não é achado nelas
const TEXTOS_PARTES = ["telas", "api", "ajuda", "manual", "reconstrucao"];

$GLOBALS["LINGUA"] = "pt-BR";
$GLOBALS["TEXTOS"] = [];
$GLOBALS["TEXTOS_LIDAS"] = [];

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
    $GLOBALS["TEXTOS"] = [];
    $GLOBALS["TEXTOS_LIDAS"] = [];
}

function lingua_atual()
{
    return $GLOBALS["LINGUA"];
}

// uma parte do dicionário de um idioma (o arquivo devolve um array: o texto em português => a tradução)
function textos_parte($l, $parte)
{
    $arq = __DIR__ . "/" . $l . "/" . $parte . ".php";
    if ($l === "pt-BR" || !is_file($arq)) {
        return [];
    }
    $x = include $arq;
    return is_array($x) ? $x : [];
}

// o texto no idioma do pedido, com os parâmetros trocados ({nome} => o valor)
function t($s, $p = [])
{
    $s = (string)$s;
    $l = $GLOBALS["LINGUA"];
    if ($l !== "pt-BR") {
        if (count($GLOBALS["TEXTOS_LIDAS"]) === 0) {
            foreach (["api", "telas"] as $parte) {
                $GLOBALS["TEXTOS"] += textos_parte($l, $parte);
                $GLOBALS["TEXTOS_LIDAS"][$parte] = true;
            }
        }
        if (!isset($GLOBALS["TEXTOS"][$s]) && count($GLOBALS["TEXTOS_LIDAS"]) < count(TEXTOS_PARTES)) {
            foreach (TEXTOS_PARTES as $parte) {
                if (!isset($GLOBALS["TEXTOS_LIDAS"][$parte])) {
                    $GLOBALS["TEXTOS"] += textos_parte($l, $parte);
                    $GLOBALS["TEXTOS_LIDAS"][$parte] = true;
                }
            }
        }
        if (isset($GLOBALS["TEXTOS"][$s]) && $GLOBALS["TEXTOS"][$s] !== "") {
            $s = $GLOBALS["TEXTOS"][$s];
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

// marca um texto do dicionário que é traduzido depois, num t($variavel) (a conferência dos dicionários acha a chave aqui)
function tk($s)
{
    return $s;
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

function dinheiro_txt($v)
{
    return "R$ " . numero_txt($v, 2);
}
