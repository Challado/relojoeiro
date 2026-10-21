<?php
// Os arquivos dos idiomas (textos/<idioma>.php) conferidos com o código: cada t("...") do PHP e do JavaScript é uma chave
// (o texto em português); cada idioma tem de ter todas as chaves, com tradução, e nenhuma que o código não usa mais, e os
// mesmos parâmetros ({nome}) da chave. Lista o idioma, o arquivo e o texto de cada problema, de uma vez só. O arquivo é
// dividido em partes: telas (os .js e as páginas), api (o núcleo, as gravações, a API e o cron), ajuda (api/ajuda.php),
// reconstrucao (reconstrucao.php), manual (os textos do manual) e cadastro (os textos do cadastro inicial: os do
// modelo.json, pelo modelo_textos do colecoes.php). Confere também os textos longos: o
// manual de cada idioma (textos/manual/<idioma>.md) e o README de cada idioma (testes/readme.php --conferir).
//   php testes/textos.php                       confere tudo (sai com 1 se faltar ou sobrar algo)
//   php testes/textos.php --faltam <parte>      as chaves da parte que faltam em algum idioma, em JSON (para traduzir)
//   php testes/textos.php --manual               o manual em português (as seções do README.md que a Ajuda mostra), com a linha da
//                                                origem: o ponto de partida de textos/manual/<idioma>.md
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
    if (strpos($rel, "/") === false && !in_array($rel, ["lib.php", "operacoes.php", "colecoes.php", "alertas.php", "contas.php", "banco.php", "api.php", "cron.php", "instalar.php", "importar.php", "senha.php", "config.exemplo.php"], true)) {
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
    foreach (chaves_do_cadastro($raiz) as $c) {
        $partes["cadastro"][$c] = true;
    }
    return array_map("array_keys", $partes);
}

// os textos do cadastro inicial: os do modelo.json que entram no idioma de quem cria a coleção (modelo_textos, no
// colecoes.php); e a marca do manual (o sha1 do original, para conferir as traduções do manual)
function chaves_do_cadastro($raiz)
{
    $pasta = sys_get_temp_dir() . "/relojoeiro-textos-" . getmypid();
    @mkdir($pasta, 0775, true);
    $codigo = 'require "lib.php"; lingua_definir("pt-BR"); echo json_encode(["cadastro" => modelo_textos(), "manual" => sha1(manual_original())], JSON_UNESCAPED_UNICODE);';
    $cmd = "cd " . escapeshellarg($raiz) . " && RELOGIOS_CONFIG=" . escapeshellarg($raiz . "/testes/config.teste.php") . " RELOGIOS_DB=sqlite RELOGIOS_TESTE_PASTA="
        . escapeshellarg($pasta) . " php -r " . escapeshellarg($codigo);
    $saida = shell_exec($cmd);
    @rmdir($pasta);
    $lista = json_decode((string)$saida, true);
    $GLOBALS["MANUAL_SHA1"] = $lista["manual"] ?? "";
    $lista = $lista["cadastro"] ?? null;
    if (!is_array($lista)) {
        fwrite(STDERR, "Não consegui ler os textos do modelo.json.\n");
        exit(2);
    }
    $res = [];
    foreach ($lista as $v) {
        // só o que tem texto (as âncoras {relogio} sozinhas e os números não se traduzem)
        if (preg_match('/\p{L}{2}/u', preg_replace('/\{[a-z_]+\}/', "", $v)) === 1) {
            $res[$v] = true;
        }
    }
    return array_keys($res);
}

function parametros($s)
{
    preg_match_all("/\\{([a-z_0-9]+)\\}/", $s, $m);
    $p = array_unique($m[1]);
    sort($p);
    return $p;
}

// regrava o arquivo de um idioma com uma parte nova, cada parte na ordem das chaves do código
function gravar_parte($raiz, $lingua, $parte, $dic, $ordem)
{
    $partes = textos_arquivo($lingua);
    $partes[$parte] = [];
    foreach ($ordem as $c) {
        if (isset($dic[$c])) {
            $partes[$parte][$c] = $dic[$c];
        }
    }
    $saida = "<?php\n// " . LINGUAS[$lingua]["nome"] . " (" . $lingua . "): o texto em português => a tradução, por parte. Gerado e conferido por\n"
        . "// testes/textos.php: traduza aqui e rode a conferência (php testes/textos.php).\nreturn [\n";
    foreach (TEXTOS_PARTES as $p) {
        if (count($partes[$p] ?? []) === 0) {
            continue;
        }
        $saida .= "    " . var_export($p, true) . " => [\n";
        foreach ($partes[$p] as $c => $v) {
            $saida .= "        " . var_export($c, true) . " => " . var_export($v, true) . ",\n";
        }
        $saida .= "    ],\n";
    }
    file_put_contents($raiz . "/textos/" . $lingua . ".php", $saida . "];\n");
}

if (($argv[1] ?? "") === "--manual") {
    $pasta = sys_get_temp_dir() . "/relojoeiro-manual-" . getmypid();
    @mkdir($pasta);
    echo shell_exec("cd " . escapeshellarg($RAIZ) . " && RELOGIOS_CONFIG=" . escapeshellarg($RAIZ . "/testes/config.teste.php") . " RELOGIOS_DB=sqlite RELOGIOS_TESTE_PASTA="
        . escapeshellarg($pasta) . " php -r " . escapeshellarg('require "lib.php"; $md = manual_original(); echo "<!-- origem: " . sha1($md) . " -->\n" . $md;'));
    @rmdir($pasta);
    exit(0);
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
                    $problemas[] = "textos/" . $l . ".php · " . $parte . ": falta \"" . mb_substr($c, 0, 90) . "\"";
                }
            } elseif (parametros($c) !== parametros($dic[$c])) {
                $problemas[] = "textos/" . $l . ".php · " . $parte . ": os parâmetros não batem em \"" . mb_substr($c, 0, 90) . "\" (" . implode(", ", parametros($dic[$c])) . ")";
            }
        }
        if ($falta > 5) {
            $problemas[] = "textos/" . $l . ".php · " . $parte . ": faltam " . $falta . " no total";
        }
        foreach (array_keys($dic) as $c) {
            if (!isset($usadas[$c])) {
                $problemas[] = "textos/" . $l . ".php · " . $parte . ": sobra \"" . mb_substr($c, 0, 90) . "\" (o código não usa mais)";
            }
        }
    }
}
// as listas traduzidas no uso, t(LISTA[...]): cada nome de uma constante assim tem de estar no código como tk("...") (a
// conferência acima só lê os literais; sem o tk, o nome sairia sem tradução em todo idioma)
$todas = [];
foreach (TEXTOS_PARTES as $parte) {
    $todas += array_flip($codigo[$parte]);
}
$listas = [];
$fontes = array_merge(glob($RAIZ . "/*.php"), glob($RAIZ . "/api/*.php"));
foreach ($fontes as $arq) {
    if (preg_match_all('/(?<![A-Za-z0-9_>:$])t\(\s*([A-Z][A-Z0-9_]+)\[/', (string)file_get_contents($arq), $m)) {
        foreach ($m[1] as $nome) {
            $listas[$nome] = true;
        }
    }
}
foreach ($fontes as $arq) {
    $tk = token_get_all((string)file_get_contents($arq));
    for ($i = 0, $n = count($tk); $i < $n; $i++) {
        if (!is_array($tk[$i]) || $tk[$i][0] !== T_CONST) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tk[$j]) && $tk[$j][0] === T_WHITESPACE) {
            $j++;
        }
        $nome = is_array($tk[$j]) ? $tk[$j][1] : "";
        if (!isset($listas[$nome])) {
            continue;
        }
        // os valores (não as chaves: o literal seguido de =>) até o ; do fim da constante
        for ($k = $j + 1; $k < $n && $tk[$k] !== ";"; $k++) {
            if (!is_array($tk[$k]) || $tk[$k][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $q = $k + 1;
            while ($q < $n && is_array($tk[$q]) && $tk[$q][0] === T_WHITESPACE) {
                $q++;
            }
            if (is_array($tk[$q]) && $tk[$q][0] === T_DOUBLE_ARROW) {
                continue;
            }
            $v = eval("return " . $tk[$k][1] . ";");
            // as unidades e os vazios ("h", "") não são texto
            if (preg_match('/\pL.*\pL/u', $v) === 1 && !isset($todas[$v])) {
                $problemas[] = substr($arq, strlen($RAIZ) + 1) . " · " . $nome . ": \"" . mb_substr($v, 0, 90) . "\" sai por t(" . $nome . "[...]) e não tem o tk(\"...\") que o põe nos dicionários";
            }
        }
    }
}
// o manual (a página Ajuda): um arquivo traduzido por idioma, da versão atual do original
foreach ($outras as $l) {
    $arq = $RAIZ . "/textos/manual/" . $l . ".md";
    $origem = is_file($arq) && preg_match('/^<!-- origem: ([0-9a-f]+) -->/', (string)file_get_contents($arq), $m) === 1 ? $m[1] : null;
    if ($origem === null) {
        $problemas[] = "textos/manual/" . $l . ".md: falta a tradução do manual (as seções do README.md que a Ajuda mostra)";
    } elseif ($origem !== $GLOBALS["MANUAL_SHA1"]) {
        $problemas[] = "textos/manual/" . $l . ".md: o manual mudou no README.md depois desta tradução (origem " . substr($origem, 0, 10) . ", agora "
            . substr($GLOBALS["MANUAL_SHA1"], 0, 10) . "): traduza as mudanças e ponha a origem nova na primeira linha";
    }
}
// o README de cada idioma (README.<idioma>.md): o resto traduzido em dia com o README.md, e o arquivo montado em dia
// (testes/readme.php, num processo à parte, como o cadastro)
exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($RAIZ . "/testes/readme.php") . " --conferir 2>&1", $saida_readme, $erro_readme);
if ($erro_readme !== 0) {
    foreach ($saida_readme as $linha) {
        $problemas[] = $linha;
    }
}
if (count($problemas) > 0) {
    echo implode("\n", $problemas) . "\n";
    echo "Os dicionários não batem com o código (" . count($problemas) . " problemas).\n";
    exit(1);
}
echo "Os dicionários dos " . count($outras) . " idiomas têm os " . $total . " textos do código.\n";
