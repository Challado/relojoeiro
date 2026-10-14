<?php
// O README.md nos outros idiomas (README.<idioma>.md, o padrão do GitHub), montado de duas fontes, para nada ser traduzido
// duas vezes:
//   - as seções que a página Ajuda mostra (MANUAL_TRECHOS): a tradução do manual, textos/manual/<idioma>.md;
//   - o resto (o começo, Experimente em 1 minuto, a API e a Instalação, Como é por dentro, Como contribuir, a Licença):
//     textos/readme/<idioma>.md, quatro partes separadas por "<!-- parte N -->", com a origem na primeira linha, como no
//     manual (o sha1 do resto do README.md em português, o original que ela traduz).
// Os títulos traduzidos levam a âncora do original ("## Installation {#instalação}"): aqui ela sai do título, e cada link
// para ela (#instalação) vira a âncora que o GitHub dá ao título traduzido (#installation).
//
//   php testes/readme.php              grava os README.<idioma>.md de todos os idiomas
//   php testes/readme.php --conferir   confere (sem gravar): a origem de cada textos/readme/<idioma>.md e se cada
//                                      README.<idioma>.md é o que o gerador monta; sai com 1 se algo não bate
//   php testes/readme.php --original   o resto do README.md em português, com a origem e as marcas das partes: o ponto
//                                      de partida de textos/readme/<idioma>.md

$raiz = dirname(__DIR__);
putenv("RELOGIOS_CONFIG=" . (getenv("RELOGIOS_CONFIG") ?: __DIR__ . "/config.teste.php"));
require $raiz . "/lib.php";

// O README.md em sete pedaços: o resto (R1..R4) e os trechos do manual (M1..M3), na ordem R1 M1 R2 M2 R3 M3 R4
function readme_pedacos($readme)
{
    $pedacos = [];
    $pos = 0;
    foreach (MANUAL_TRECHOS as $t) {
        $ini = strpos($readme, "\n## " . $t[0], $pos);
        $fim = strpos($readme, "\n## " . $t[1], $ini + 1);
        $pedacos[] = substr($readme, $pos, $ini - $pos);
        $pedacos[] = substr($readme, $ini, $fim - $ini);
        $pos = $fim;
    }
    $pedacos[] = substr($readme, $pos);
    return $pedacos;
}

function readme_resto($readme)
{
    $p = readme_pedacos($readme);
    $txt = "";
    foreach ([0, 2, 4, 6] as $n => $i) {
        $txt .= "<!-- parte " . ($n + 1) . " -->\n" . $p[$i] . "\n";
    }
    return $txt;
}

// A âncora que o GitHub dá a um título: minúsculas, sem o que não é letra, marca (os sinais do hindi), número, espaço,
// hífen ou sublinhado; os espaços viram hífens; repetida, ganha -1, -2...
function github_ancora($titulo, &$usadas)
{
    $t = mb_strtolower(trim($titulo), "UTF-8");
    $t = preg_replace('/[^\p{L}\p{M}\p{N} _-]/u', "", $t);
    $a = str_replace(" ", "-", $t);
    $base = $a;
    for ($n = 1; isset($usadas[$a]); $n++) {
        $a = $base . "-" . $n;
    }
    $usadas[$a] = true;
    return $a;
}

// O README de um idioma: null se falta a tradução do resto ou do manual
function readme_montar($raiz, $l)
{
    $arq_r = $raiz . "/textos/readme/" . $l . ".md";
    $man = manual_traduzido($l);
    if (!is_file($arq_r) || $man === null) {
        return null;
    }
    $r = str_replace("\r\n", "\n", (string)file_get_contents($arq_r));
    $r = preg_replace('/^<!-- origem: [0-9a-f]+ -->\n/', "", $r);
    $partes = preg_split('/^<!-- parte \d -->\n/m', $r, -1, PREG_SPLIT_NO_EMPTY);
    // o manual traduzido nos três trechos: cada um começa no título com a âncora do primeiro título do trecho original
    $m = $man[1];
    $cortes = [];
    foreach (array_slice(MANUAL_TRECHOS, 1) as $t) {
        $cortes[] = preg_match('/\n## [^\n]*\{#' . preg_quote(manual_ancora($t[0]), "/") . '\}\n/u', $m, $x, PREG_OFFSET_CAPTURE) === 1 ? $x[0][1] : false;
    }
    if (count($partes) !== 4 || in_array(false, $cortes, true)) {
        return null;
    }
    $trechos = [substr($m, 0, $cortes[0]), substr($m, $cortes[0], $cortes[1] - $cortes[0]), substr($m, $cortes[1])];
    $md = "";
    foreach ($partes as $i => $p) {
        $md .= rtrim($p, "\n") . "\n" . (isset($trechos[$i]) ? rtrim($trechos[$i], "\n") . "\n" : "");
    }
    // os títulos: a âncora do original sai do título, e os links para ela passam à âncora do título traduzido
    $usadas = [];
    $mapa = [];
    $no_codigo = false;
    $linhas = explode("\n", $md);
    foreach ($linhas as $k => $linha) {
        if (strpos($linha, "```") === 0) {
            $no_codigo = !$no_codigo;
        }
        if (!$no_codigo && preg_match('/^(#{1,6}) (.*)$/u', $linha, $x) === 1) {
            [$titulo, $ancora] = preg_match('/^(.*?)\s*\{#([^}]+)\}$/u', $x[2], $y) === 1 ? [$y[1], $y[2]] : [$x[2], null];
            $gh = github_ancora(preg_replace('/[`*_]/u', "", $titulo), $usadas);
            if ($ancora !== null) {
                $mapa[$ancora] = $gh;
            }
            $linhas[$k] = $x[1] . " " . $titulo;
        }
    }
    $md = implode("\n", $linhas);
    return preg_replace_callback('/\]\(#([^)\s]+)\)/u', function ($x) use ($mapa) {
        return "](#" . ($mapa[$x[1]] ?? $x[1]) . ")";
    }, $md);
}

$readme = str_replace("\r\n", "\n", (string)file_get_contents($raiz . "/README.md"));
$resto = readme_resto($readme);
$origem = sha1($resto);
$modo = $argv[1] ?? "";

if ($modo === "--original") {
    echo "<!-- origem: " . $origem . " -->\n" . $resto;
    exit(0);
}

$problemas = [];
foreach (array_keys(LINGUAS) as $l) {
    if ($l === "pt-BR") {
        continue;
    }
    $arq_r = $raiz . "/textos/readme/" . $l . ".md";
    if (!is_file($arq_r)) {
        $problemas[] = "textos/readme/" . $l . ".md: falta a tradução do README (o resto do README.md, fora do manual; php testes/readme.php --original dá o ponto de partida)";
        continue;
    }
    $o = preg_match('/^<!-- origem: ([0-9a-f]+) -->/', (string)file_get_contents($arq_r), $x) === 1 ? $x[1] : "";
    if ($o !== $origem) {
        $problemas[] = "textos/readme/" . $l . ".md: o README.md mudou depois desta tradução (origem " . substr($o, 0, 10) . ", agora " . substr($origem, 0, 10)
            . "): traduza as mudanças, ponha a origem nova na primeira linha e rode php testes/readme.php";
    }
    $md = readme_montar($raiz, $l);
    if ($md === null) {
        $problemas[] = "README." . $l . ".md: não deu para montar (as 4 partes de textos/readme/" . $l . ".md e os trechos de textos/manual/" . $l . ".md)";
        continue;
    }
    $arq = $raiz . "/README." . $l . ".md";
    if ($modo === "--conferir") {
        if (!is_file($arq) || file_get_contents($arq) !== $md) {
            $problemas[] = "README." . $l . ".md: não é o que textos/readme/" . $l . ".md e textos/manual/" . $l . ".md montam: rode php testes/readme.php";
        }
    } else {
        file_put_contents($arq, $md);
        echo "README." . $l . ".md gravado\n";
    }
}
foreach ($problemas as $p) {
    echo $p, "\n";
}
exit($problemas === [] ? 0 : 1);
