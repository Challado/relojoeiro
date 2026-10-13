<?php
// Os dicionários dos idiomas (textos/<idioma>/<parte>.php) conferidos com o código: cada t("...") do PHP e do JavaScript é
// uma chave (o texto em português); cada idioma tem de ter todas as chaves da parte, com tradução, e nenhuma que o código não
// usa mais, e os mesmos parâmetros ({nome}) da chave. As partes: telas (os .js e as páginas), api (o núcleo, as gravações, a
// API e o cron), ajuda (api/ajuda.php), reconstrucao (reconstrucao.php) e manual (os textos do manual).
//   php testes/textos.php                       confere tudo (sai com 1 se faltar ou sobrar algo)
//   php testes/textos.php --faltam <parte>      as chaves da parte que faltam em algum idioma, em JSON (para traduzir)
//   php testes/textos.php --juntar <idioma> <parte> traducoes.json   junta as traduções (um objeto chave => tradução) ao
//                                                dicionário, e regrava o arquivo na ordem em que o código usa as chaves
$RAIZ = dirname(__DIR__);
require_once $RAIZ . "/textos/funcoes.php";

// a parte de cada arquivo do sistema
function parte_do_arquivo($rel)
{
    if (substr($rel, -3) === ".js") {
        return "telas";
    }
    if ($rel === "api/ajuda.php") {
        return "ajuda";
    }
    if ($rel === "reconstrucao.php") {
        return "reconstrucao";
    }
    if (strpos($rel, "/") === false && !in_array($rel, ["lib.php", "operacoes.php", "banco.php", "api.php", "cron.php", "instalar.php", "importar.php", "criar_usuario.php", "config.exemplo.php"], true)) {
        return "telas";
    }
    return "api";
}

// as chaves de um arquivo, na ordem: os t("...") com o primeiro argumento literal
function chaves_do_arquivo($caminho, $rel)
{
    $res = [];
    $codigo = file_get_contents($caminho);
    if (substr($rel, -3) === ".js") {
        preg_match_all('/(?<![A-Za-z0-9_$.])tk?\(\s*("(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\')/', $codigo, $m);
        foreach ($m[1] as $lit) {
            if ($lit[0] === "'") {
                $lit = '"' . str_replace(['\\\'', '"'], ["'", '\\"'], substr($lit, 1, -1)) . '"';
            }
            $v = json_decode($lit);
            if (is_string($v)) {
                $res[] = $v;
            }
        }
        return $res;
    }
    $tk = token_get_all($codigo);
    $n = count($tk);
    for ($i = 0; $i < $n; $i++) {
        // t("..."), e o título da página em topo("...") (o topo traduz)
        if (is_array($tk[$i]) && $tk[$i][0] === T_STRING && in_array($tk[$i][1], ["t", "te", "tk", "topo"], true)) {
            $antes = $i - 1;
            while ($antes >= 0 && is_array($tk[$antes]) && $tk[$antes][0] === T_WHITESPACE) {
                $antes--;
            }
            // não é uma chamada da função t (um método ->t, uma declaração function t)
            if ($antes >= 0 && is_array($tk[$antes]) && in_array($tk[$antes][0], [T_OBJECT_OPERATOR, T_FUNCTION, T_DOUBLE_COLON], true)) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && is_array($tk[$j]) && $tk[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $n || $tk[$j] !== "(") {
                continue;
            }
            $j++;
            while ($j < $n && is_array($tk[$j]) && $tk[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $n && is_array($tk[$j]) && $tk[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                // o literal sozinho (seguido de , ou )): um texto feito de pedaços (concatenado) não é uma chave
                $k = $j + 1;
                while ($k < $n && is_array($tk[$k]) && $tk[$k][0] === T_WHITESPACE) {
                    $k++;
                }
                if ($k < $n && ($tk[$k] === "," || $tk[$k] === ")")) {
                    $res[] = eval("return " . $tk[$j][1] . ";");
                }
            }
        }
    }
    return $res;
}

// todas as chaves do código, por parte, na ordem em que aparecem
function chaves_do_codigo($raiz)
{
    $partes = array_fill_keys(TEXTOS_PARTES, []);
    $arquivos = array_merge(glob($raiz . "/*.php"), glob($raiz . "/*.js"), glob($raiz . "/api/*.php"));
    sort($arquivos);
    foreach ($arquivos as $arq) {
        $rel = substr($arq, strlen($raiz) + 1);
        foreach (chaves_do_arquivo($arq, $rel) as $c) {
            $partes[parte_do_arquivo($rel)][$c] = true;
        }
    }
    // o manual: os textos das seções do README que a ajuda mostra (textos/manual.php diz quais)
    if (is_file($raiz . "/textos/manual_chaves.php")) {
        foreach ((include $raiz . "/textos/manual_chaves.php") as $c) {
            $partes["manual"][$c] = true;
        }
    }
    return array_map("array_keys", $partes);
}

function parametros($s)
{
    preg_match_all("/\\{([a-z_0-9]+)\\}/", $s, $m);
    $p = array_unique($m[1]);
    sort($p);
    return $p;
}

// regrava o dicionário de um idioma e parte, na ordem das chaves do código
function gravar_parte($raiz, $lingua, $parte, $dic, $ordem)
{
    $linhas = [];
    foreach ($ordem as $c) {
        if (isset($dic[$c])) {
            $linhas[] = "    " . var_export($c, true) . " => " . var_export($dic[$c], true) . ",";
        }
    }
    $pasta = $raiz . "/textos/" . $lingua;
    if (!is_dir($pasta)) {
        mkdir($pasta, 0775, true);
    }
    file_put_contents($pasta . "/" . $parte . ".php", "<?php\n// " . LINGUAS[$lingua]["nome"] . " · " . $parte . ": o texto em português => a tradução (gerado e conferido por testes/textos.php)\n"
        . "return [\n" . implode("\n", $linhas) . (count($linhas) > 0 ? "\n" : "") . "];\n");
}

$codigo = chaves_do_codigo($RAIZ);
$outras = array_values(array_filter(array_keys(LINGUAS), function ($l) { return $l !== "pt-BR"; }));

if (($argv[1] ?? "") === "--faltam") {
    $parte = $argv[2] ?? "";
    $faltam = [];
    foreach ($codigo[$parte] ?? [] as $c) {
        foreach ($outras as $l) {
            $dic = textos_parte($l, $parte);
            if (!isset($dic[$c]) || $dic[$c] === "") {
                $faltam[] = $c;
                break;
            }
        }
    }
    echo json_encode($faltam, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if (($argv[1] ?? "") === "--juntar") {
    [$l, $parte, $arq] = [$argv[2] ?? "", $argv[3] ?? "", $argv[4] ?? ""];
    if (!in_array($l, $outras, true) || !in_array($parte, TEXTOS_PARTES, true) || !is_file($arq)) {
        fwrite(STDERR, "Uso: php testes/textos.php --juntar <" . implode("|", $outras) . "> <" . implode("|", TEXTOS_PARTES) . "> traducoes.json\n");
        exit(2);
    }
    $novas = json_decode((string)file_get_contents($arq), true);
    if (!is_array($novas)) {
        fwrite(STDERR, "O arquivo não é um objeto JSON: " . json_last_error_msg() . "\n");
        exit(2);
    }
    $dic = array_merge(textos_parte($l, $parte), $novas);
    gravar_parte($RAIZ, $l, $parte, $dic, $codigo[$parte]);
    $usadas = array_intersect(array_keys($novas), $codigo[$parte]);
    echo $l . " · " . $parte . ": " . count($usadas) . " de " . count($novas) . " traduções juntadas (" . (count($novas) - count($usadas)) . " que o código não usa ficaram de fora)\n";
    exit(0);
}

// a conferência
$problemas = [];
$total = 0;
foreach (TEXTOS_PARTES as $parte) {
    $usadas = array_flip($codigo[$parte]);
    $total += count($usadas);
    foreach ($outras as $l) {
        $dic = textos_parte($l, $parte);
        $falta = 0;
        foreach ($codigo[$parte] as $c) {
            if (!isset($dic[$c]) || trim((string)$dic[$c]) === "") {
                $falta++;
                if ($falta <= 5) {
                    $problemas[] = $l . " · " . $parte . ": falta \"" . mb_substr($c, 0, 90) . "\"";
                }
            } elseif (parametros($c) !== parametros($dic[$c])) {
                $problemas[] = $l . " · " . $parte . ": os parâmetros não batem em \"" . mb_substr($c, 0, 90) . "\" (" . implode(", ", parametros($dic[$c])) . ")";
            }
        }
        if ($falta > 5) {
            $problemas[] = $l . " · " . $parte . ": faltam " . $falta . " no total";
        }
        foreach (array_keys($dic) as $c) {
            if (!isset($usadas[$c])) {
                $problemas[] = $l . " · " . $parte . ": sobra \"" . mb_substr($c, 0, 90) . "\" (o código não usa mais)";
            }
        }
    }
}
if (count($problemas) > 0) {
    echo implode("\n", $problemas) . "\n";
    echo "Os dicionários não batem com o código (" . count($problemas) . " problemas).\n";
    exit(1);
}
echo "Os dicionários dos " . count($outras) . " idiomas têm os " . $total . " textos do código.\n";
