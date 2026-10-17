<?php
// A conferência da arquitetura: as regras do sistema que não dependem de um banco no ar, conferidas no código. Cada uma
// tem um número e um nome; a que falha diz o arquivo e o motivo. Roda no teste de paridade (testes/paridade.sh) e no
// GitHub a cada push.
//   1. Sintaxe: todo .php passa no php -l e todo .js no node --check (sem o node, só o PHP).
//   2. As páginas só montam o esqueleto: incluem só o pagina.php e só chamam topo(), rodape() e t()/te(); nada de
//      $_GET/$_POST/$_REQUEST/$_COOKIE/$_SESSION nem SQL. O pagina.php só usa os idiomas (textos/funcoes.php) e, do núcleo,
//      só a porta do login (pagina_login(), do lib.php), que o topo() chama antes de qualquer saída.
//   3. A rede das telas é só a API: fetch e XMLHttpRequest só no api.js, no documentos.js (o envio com o andamento) e no
//      sw.js (o service worker do aplicativo: o cache dos arquivos das telas, nunca da API), sempre para o api.php; nenhum
//      .js cita um .php que não seja o api.php ou uma página; sem eval nem new Function.
//   4. O banco só é tocado pelo núcleo, pela API e pelo cron: as funções do banco.php só são chamadas no banco.php,
//      lib.php, operacoes.php, colecoes.php, alertas.php, contas.php, api.php, api/, cron.php e reconstrucao.php. Os scripts de linha de comando (instalar,
//      criar_usuario, importar) não tocam no banco: só chamam operações que a API também chama.
//   5. Os pedaços do api.php (api/*.php) não fazem nada abertos direto: começam pela guarda RELOJOEIRO_API.
//   6. Pela web abrem só as páginas, o api.php e o que elas carregam: todo .php interno está bloqueado no .htaccess e no
//      nginx-relogios.conf, e as pastas api/, textos/ e testes/ também; nenhuma página está bloqueada.
//   7. A API está toda documentada na ajuda: todo recurso que o api.php atende está na ajuda (consulta ou escrita), toda
//      ação de escrita do código está na ajuda e toda ação da ajuda existe no código.
//   8. Toda constante do config.php que o código lê está explicada no config.exemplo.php, no README.md e na reconstrução.
//   9. As migrações: todo migracao_v*.sql está registrado no $MIGRACOES do lib.php, e todo registrado existe.
//  10. O repositório: nada pessoal entra no git (config.php, bancos, chaves, .env), e todo arquivo do repositório sai
//      pelo recurso=codigo (está na lista da reconstrução, ou nos que ficam de fora de propósito).
//
//   php testes/arquitetura.php        confere tudo (sai com 1 se alguma regra falhar)

$RAIZ = dirname(__DIR__);
chdir($RAIZ);
putenv("RELOGIOS_CONFIG=" . (getenv("RELOGIOS_CONFIG") ?: __DIR__ . "/config.teste.php"));
require $RAIZ . "/lib.php";
require_once $RAIZ . "/reconstrucao.php";

$problemas = [];
function falha($regra, $texto)
{
    $GLOBALS["problemas"][] = $regra . ". " . $texto;
}

// os arquivos do repositório (o git; sem ele, os da pasta)
exec("git ls-files 2>/dev/null", $repo, $erro_git);
if ($erro_git !== 0 || count($repo) === 0) {
    $repo = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($RAIZ, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($RAIZ) + 1);
        if (strpos($rel, ".git/") !== 0 && strpos($rel, "node_modules/") === false) {
            $repo[] = $rel;
        }
    }
}
$php = array_values(array_filter($repo, function ($f) { return substr($f, -4) === ".php" && is_file($f); }));
$js = array_values(array_filter($repo, function ($f) { return substr($f, -3) === ".js" && strpos($f, "/") === false && is_file($f); }));

// os .php da raiz: os internos (núcleo, banco, scripts) e as páginas (o resto, menos o api.php)
const INTERNOS = ["lib.php", "banco.php", "config.php", "config.exemplo.php", "operacoes.php", "colecoes.php", "alertas.php", "contas.php", "pagina.php", "cron.php", "importar.php",
    "instalar.php", "criar_usuario.php", "reconstrucao.php"];
$raiz_php = array_values(array_filter($php, function ($f) { return strpos($f, "/") === false; }));
$paginas = array_values(array_filter($raiz_php, function ($f) { return !in_array($f, INTERNOS, true) && $f !== "api.php"; }));

// as funções chamadas num arquivo PHP (o nome seguido de "(", que não é método, declaração nem new)
function chamadas($arq)
{
    $t = token_get_all((string)file_get_contents($arq));
    $res = [];
    for ($i = 0, $n = count($t); $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_STRING) {
            continue;
        }
        $j = $i + 1;
        while (isset($t[$j]) && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($t[$j] ?? null) !== "(") {
            continue;
        }
        $k = $i - 1;
        while ($k >= 0 && is_array($t[$k]) && $t[$k][0] === T_WHITESPACE) {
            $k--;
        }
        $antes = $t[$k] ?? null;
        if (is_array($antes) && in_array($antes[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }
        $res[$t[$i][1]] = true;
    }
    return array_keys($res);
}

function definidas($arq)
{
    preg_match_all('/^\s*function\s+(\w+)\s*\(/m', (string)file_get_contents($arq), $m);
    return $m[1];
}

// os arquivos que um PHP inclui (require/include com __DIR__ . "/...")
function inclui($arq)
{
    preg_match_all('/(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*"\/([^"]+)"/', (string)file_get_contents($arq), $m);
    return $m[1];
}

// ---------- 1. sintaxe ----------
foreach ($php as $f) {
    exec(escapeshellarg(PHP_BINARY) . " -l " . escapeshellarg($f) . " 2>&1", $saida, $erro);
    if ($erro !== 0) {
        falha("1", $f . ": " . trim(implode(" ", array_slice($saida, 0, 2))));
    }
    $saida = [];
}
exec("command -v node", $tem_node, $sem_node);
if ($sem_node === 0) {
    foreach (array_values(array_filter($repo, function ($f) { return substr($f, -3) === ".js" || substr($f, -4) === ".cjs"; })) as $f) {
        exec("node --check " . escapeshellarg($f) . " 2>&1", $saida, $erro);
        if ($erro !== 0) {
            falha("1", $f . ": " . trim(implode(" ", array_slice($saida, 0, 3))));
        }
        $saida = [];
    }
}

// ---------- 2. as páginas só montam o esqueleto ----------
$superglobais = '/\$_(GET|POST|REQUEST|COOKIE|SESSION|FILES|SERVER)\b/';
$sql = '/\b(SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i';
foreach ($paginas as $f) {
    $txt = (string)file_get_contents($f);
    $fora = array_diff(chamadas($f), ["topo", "rodape", "t", "te"]);
    if ($fora) {
        falha("2", $f . ": a página chama " . implode(", ", $fora) . " (uma página só chama topo(), rodape() e t(): os dados vêm da API)");
    }
    $inc = array_diff(inclui($f), ["pagina.php"]);
    if ($inc) {
        falha("2", $f . ": a página inclui " . implode(", ", $inc) . " (só o pagina.php)");
    }
    if (preg_match($superglobais, $txt) === 1 || preg_match($sql, $txt) === 1) {
        falha("2", $f . ": a página lê o pedido ou escreve SQL (quem lê e grava é a API)");
    }
}
$idiomas = definidas("textos/funcoes.php");
$nucleo = [];
foreach (array_merge(["lib.php", "banco.php", "operacoes.php", "colecoes.php", "alertas.php", "contas.php", "cron.php", "reconstrucao.php"], glob("api/*.php")) as $f) {
    $nucleo = array_merge($nucleo, definidas($f));
}
$fora = array_diff(array_intersect(chamadas("pagina.php"), $nucleo), ["pagina_login"]);
if ($fora) {
    falha("2", "pagina.php chama " . implode(", ", $fora) . " (o topo e o rodapé só usam os idiomas, textos/funcoes.php, e a porta do login, pagina_login())");
}
$inc = array_diff(inclui("pagina.php"), ["textos/funcoes.php", "lib.php"]);
if ($inc) {
    falha("2", "pagina.php inclui " . implode(", ", $inc) . " (só o textos/funcoes.php e o lib.php, para o login)");
}
if (strpos((string)file_get_contents("pagina.php"), "pagina_login()") === false) {
    falha("2", "pagina.php não confere o login antes de mostrar a página (pagina_login() no topo())");
}
if (preg_match($sql, (string)file_get_contents("pagina.php")) === 1) {
    falha("2", "pagina.php escreve SQL");
}

// ---------- 3. a rede das telas é só a API ----------
$rede_permitida = ["api.js" => "fetch", "documentos.js" => "XMLHttpRequest", "sw.js" => "fetch"];
foreach ($js as $f) {
    $txt = (string)file_get_contents($f);
    foreach (["fetch" => '/\bfetch\s*\(/', "XMLHttpRequest" => '/\bXMLHttpRequest\b/', "sendBeacon" => '/\bsendBeacon\s*\(/',
        "EventSource" => '/\bEventSource\b/', "WebSocket" => '/\bWebSocket\b/'] as $nome => $re) {
        if (preg_match($re, $txt) === 1 && ($rede_permitida[$f] ?? "") !== $nome) {
            falha("3", $f . ": usa " . $nome . " (a rede das telas passa pelo api() e pelo gravar() do api.js)");
        }
    }
    if (preg_match('/\beval\s*\(|\bnew\s+Function\s*\(/', $txt) === 1) {
        falha("3", $f . ": usa eval ou new Function");
    }
    // os endereços que o fetch e o XMLHttpRequest pedem: só o api.php
    if (preg_match_all('/\bfetch\s*\(\s*"([^"?]*)/', $txt, $m)) {
        foreach ($m[1] as $u) {
            if ($u !== "api.php") {
                falha("3", $f . ": fetch para \"" . $u . "\" (só o api.php)");
            }
        }
    }
    if (preg_match_all('/\.open\s*\(\s*"[A-Z]+"\s*,\s*"([^"?]*)/', $txt, $m)) {
        foreach ($m[1] as $u) {
            if ($u !== "api.php") {
                falha("3", $f . ": XMLHttpRequest para \"" . $u . "\" (só o api.php)");
            }
        }
    }
    // todo endereço .php (um texto entre aspas que começa por ele): o api.php ou uma página (a navegação); o .php citado
    // no meio de uma mensagem ("no config.php") não é endereço
    if (preg_match_all('/["\'](?:\.\/)?([a-z_\/]+)\.php(?=[?#"\'])/', $txt, $m)) {
        foreach (array_unique($m[1]) as $p) {
            if ($p . ".php" !== "api.php" && !in_array($p . ".php", $paginas, true)) {
                falha("3", $f . ": cita " . $p . ".php (um .js só fala com o api.php e navega entre as páginas)");
            }
        }
    }
}

// ---------- 4. quem toca no banco ----------
$banco = definidas("banco.php");
$pode_banco = array_merge(["banco.php", "lib.php", "operacoes.php", "colecoes.php", "alertas.php", "contas.php", "api.php", "cron.php", "reconstrucao.php"], glob("api/*.php"));
foreach ($php as $f) {
    if (strpos($f, "testes/") === 0 || in_array($f, $pode_banco, true)) {
        continue;
    }
    $usa = array_intersect(chamadas($f), $banco);
    if ($usa) {
        falha("4", $f . ": chama " . implode(", ", $usa) . " do banco.php (o banco só é tocado pelo núcleo, pela API e pelo cron)");
    }
}
$da_api = [];
foreach (array_merge(["api.php"], glob("api/*.php")) as $f) {
    $da_api = array_merge($da_api, chamadas($f));
}
foreach (["instalar.php", "criar_usuario.php", "importar.php"] as $f) {
    foreach (array_intersect(chamadas($f), $nucleo) as $fn) {
        if (!in_array($fn, $da_api, true)) {
            falha("4", $f . ": chama " . $fn . "(), que a API não chama (o script de linha de comando é um atalho de uma operação da API)");
        }
    }
}

// ---------- 5. a guarda dos pedaços do api.php ----------
foreach (glob("api/*.php") as $f) {
    $inicio = implode("\n", array_slice(file($f), 0, 12));
    if (strpos($inicio, 'if (!defined("RELOJOEIRO_API"))') === false) {
        falha("5", $f . ": não começa pela guarda if (!defined(\"RELOJOEIRO_API\")) (aberto direto, rodaria fora do api.php)");
    }
}

// ---------- 6. o bloqueio pela web ----------
$htaccess = (string)file_get_contents(".htaccess");
$nginx = (string)file_get_contents("nginx-relogios.conf");
$re_ht = preg_match('/<FilesMatch "\^\(([^)]+)\)\\\\\.php\$">/', $htaccess, $m) === 1 ? '/^(' . $m[1] . ')\.php$/' : null;
$re_ng = preg_match('/location ~ \^\/relojoeiro\/\(([^)]+)\)\\\\\.php\$/', $nginx, $m) === 1 ? '/^(' . $m[1] . ')\.php$/' : null;
if ($re_ht === null || $re_ng === null) {
    falha("6", "não achei a lista dos .php bloqueados no .htaccess (FilesMatch) ou no nginx-relogios.conf (location)");
} else {
    foreach (INTERNOS as $f) {
        if (preg_match($re_ht, $f) !== 1) {
            falha("6", $f . ": não está bloqueado no .htaccess");
        }
        if (preg_match($re_ng, $f) !== 1) {
            falha("6", $f . ": não está bloqueado no nginx-relogios.conf");
        }
    }
    foreach (array_merge($paginas, ["api.php"]) as $f) {
        if (preg_match($re_ht, $f) === 1 || preg_match($re_ng, $f) === 1) {
            falha("6", $f . ": a página está bloqueada pela web");
        }
    }
}
foreach (array_unique(array_map(function ($f) { return explode("/", $f)[0]; }, array_filter($php, function ($f) { return strpos($f, "/") !== false; }))) as $pasta) {
    if (preg_match('/RedirectMatch 403 \/\([^)]*\b' . preg_quote($pasta, "/") . '\b/', $htaccess) !== 1) {
        falha("6", $pasta . "/: a pasta não está bloqueada no .htaccess");
    }
    if (strpos($nginx, "location ^~ /relojoeiro/" . $pasta . "/") === false) {
        falha("6", $pasta . "/: a pasta não está bloqueada no nginx-relogios.conf");
    }
}
// e um .php novo na raiz tem de ser página (com topo) ou interno (na lista)
foreach ($raiz_php as $f) {
    if (!in_array($f, INTERNOS, true) && $f !== "api.php" && !in_array("topo", chamadas($f), true)) {
        falha("6", $f . ": não é página (não chama topo()) nem está na lista dos internos bloqueados (INTERNOS, aqui, no .htaccess e no nginx)");
    }
}

// ---------- 7. a API documentada ----------
define("RELOJOEIRO_API", true);
$recurso = "ajuda";
$acao = "";
$d = [];
$codigo = 200;
require "api/ajuda.php";
$ajuda = $saida;
$consultas = array_keys($ajuda["consulta"]);
$escritas = $ajuda["escrita"];
$api = (string)file_get_contents("api.php");
preg_match_all('/\$recurso === "(\w+)"/', $api, $m);
foreach (array_unique($m[1]) as $r) {
    if (!in_array($r, $consultas, true) && !isset($escritas[$r])) {
        falha("7", "recurso=" . $r . ": o api.php atende, mas a ajuda não explica (consulta ou escrita, em api/ajuda.php)");
    }
}
// as ações de escrita: cada recurso do $ops vai para uma função op_*, e as ações são os $acao === "..." dela
$esc = (string)file_get_contents("api/escrita.php");
preg_match('/\$ops = \[(.*?)\];/s', $esc, $m);
preg_match_all('/"(\w+)" => "(op_\w+)"/', $m[1] ?? "", $mm, PREG_SET_ORDER);
$funcoes_op = [];
foreach ($mm as $x) {
    $funcoes_op[$x[1]] = $x[2];
}
$funcoes_op["usuarios"] = "op_usuarios";
$funcoes_op["importacao"] = "op_importacao";
$funcoes_op["cadastro"] = "op_cadastro";
$ops_txt = (string)file_get_contents("operacoes.php") . (string)file_get_contents("contas.php");
$acoes_codigo = [];
foreach ($funcoes_op as $r => $fn) {
    $ini = strpos($ops_txt, "function " . $fn . "(");
    if ($ini === false) {
        falha("7", $fn . "(): a função de escrita de " . $r . " não está no operacoes.php nem no contas.php");
        continue;
    }
    $fim = strpos($ops_txt, "\nfunction ", $ini + 10);
    $corpo = substr($ops_txt, $ini, $fim === false ? null : $fim - $ini);
    preg_match_all('/\$acao\s*[!=]==\s*"(\w+)"|case\s+"(\w+)"\s*:|in_array\(\$acao,\s*\[([^\]]+)\]/', $corpo, $a, PREG_SET_ORDER);
    $acoes = [];
    foreach ($a as $x) {
        if (($x[1] ?? "") !== "") {
            $acoes[] = $x[1];
        } elseif (($x[2] ?? "") !== "") {
            $acoes[] = $x[2];
        } elseif (($x[3] ?? "") !== "" && preg_match_all('/"(\w+)"/', $x[3], $y)) {
            $acoes = array_merge($acoes, $y[1]);
        }
    }
    $acoes_codigo[$r] = array_values(array_unique($acoes));
}
preg_match_all('/\$acao === "(\w+)"/', (string)file_get_contents("api/escrita.php") . (string)file_get_contents("api/migracoes.php"), $b);
$acoes_codigo["backup"] = array_values(array_intersect($b[1], ["fazer", "restaurar"]));
preg_match_all('/"(instalar)"/', $api . (string)file_get_contents("api/escrita.php"), $b);
foreach ($acoes_codigo as $r => $acoes) {
    if (!isset($escritas[$r])) {
        falha("7", "recurso=" . $r . " (escrita): a ajuda não explica as ações dele");
        continue;
    }
    $doc = is_array($escritas[$r]) ? array_keys($escritas[$r]) : [];
    foreach (array_diff($acoes, $doc) as $x) {
        falha("7", "recurso=" . $r . ", acao=" . $x . ": o código aceita, mas a ajuda não explica");
    }
    foreach (array_diff($doc, $acoes) as $x) {
        falha("7", "recurso=" . $r . ", acao=" . $x . ": a ajuda explica, mas o código não tem");
    }
}

// ---------- 8. as constantes do config.php ----------
$lidas = [];
foreach ($php as $f) {
    if (strpos($f, "testes/") === 0 || $f === "config.exemplo.php") {
        continue;
    }
    preg_match_all('/defined\(\s*"([A-Z][A-Z0-9_]+)"\s*\)/', (string)file_get_contents($f), $m);
    $lidas = array_merge($lidas, $m[1]);
}
$lidas = array_values(array_diff(array_unique($lidas), ["RELOJOEIRO_API"]));
$exemplo = (string)file_get_contents("config.exemplo.php");
$readme = (string)file_get_contents("README.md");
$rec = implode(" ", array_keys(reconstrucao_config_php()));
foreach ($lidas as $c) {
    if (strpos($exemplo, $c) === false) {
        falha("8", $c . ": o código lê, mas o config.exemplo.php não explica");
    }
    if (strpos($readme, "`" . $c . "`") === false) {
        falha("8", $c . ": o código lê, mas o README.md não explica (a tabela do config.php)");
    }
    if (preg_match('/\b' . $c . '\b/', $rec) !== 1) {
        falha("8", $c . ": o código lê, mas a reconstrução não explica (reconstrucao_config_php())");
    }
}

// ---------- 9. as migrações ----------
global $MIGRACOES;
$registradas = array_map(function ($m) { return $m[0]; }, $MIGRACOES);
foreach (glob("migracao_v*.sql") as $f) {
    if (!in_array($f, $registradas, true)) {
        falha("9", $f . ": não está registrada no \$MIGRACOES do lib.php");
    }
}
foreach ($registradas as $f) {
    if (!is_file($f)) {
        falha("9", $f . ": está no \$MIGRACOES, mas o arquivo não existe");
    }
}

// ---------- 10. o repositório ----------
foreach ($repo as $f) {
    if ($f === "config.php" || preg_match('/(\.sqlite(-wal|-shm)?|\.db|\.fdb|\.env|\.pem|\.key|\.p12)$/', $f) === 1
        || preg_match('/(conta-servico|service-account|credentials)[^\/]*\.json$/i', $f) === 1) {
        falha("10", $f . ": não pode estar no repositório (configuração, banco ou chave)");
    }
}
$listados = array_keys(codigo_arquivos());
$fora = array_map(function ($x) { return $x["arquivo"]; }, reconstrucao_nao_mostrados());
foreach ($repo as $f) {
    if (!in_array($f, $listados, true) && !in_array($f, $fora, true)) {
        falha("10", $f . ": não sai pelo recurso=codigo (ponha em reconstrucao_codigo(), no reconstrucao.php)");
    }
}

if ($problemas !== []) {
    echo implode("\n", $problemas), "\n";
    echo "A arquitetura não bate (" . count($problemas) . " problemas).\n";
    exit(1);
}
echo "A arquitetura confere (" . count($php) . " .php, " . count($js) . " .js, " . count($paginas) . " páginas, 10 regras).\n";
exit(0);
