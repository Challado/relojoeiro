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
//      senha, importar) não tocam no banco: só chamam operações que a API também chama (o senha.php, a troca de senha pelo
//      dono do servidor sem a força da Configuração, também as duas funções dele, que a API não tem de propósito).
//   5. Os pedaços do api.php (api/*.php) não fazem nada abertos direto: começam pela guarda RELOJOEIRO_API.
//   6. Pela web abrem só as páginas, o api.php e o que elas carregam: todo .php interno está bloqueado no .htaccess e no
//      nginx-relogios.conf, e as pastas api/, textos/ e testes/ também; nenhuma página está bloqueada.
//   7. A API está toda documentada na ajuda: todo recurso que o api.php atende está na ajuda (consulta ou escrita), toda
//      ação de escrita do código está na ajuda e toda ação da ajuda existe no código.
//   8. Toda constante do config.php que o código lê está explicada no config.exemplo.php, no README.md e na reconstrução.
//   9. As migrações: todo migracao_v*.sql está registrado no $MIGRACOES do lib.php, e todo registrado existe.
//  10. O repositório: nada pessoal entra no git (config.php, bancos, chaves, .env), e todo arquivo do repositório sai
//      pelo recurso=codigo (está na lista da reconstrução, ou nos que ficam de fora de propósito).
//  11. Nenhum texto fixo nas telas: todo texto que um .js põe na tela passa pelo t() (o dicionário vem da API, no idioma de
//      quem usa). Um texto entre aspas, fora do t()/tk(), é recusado onde ele vira texto na tela: o conteúdo do el()
//      (el("a", {...}, "cancelar")), um lado de um "?:" cujo outro lado é texto (t(), el(), h()), os atributos title,
//      placeholder, aria-label e alt, o textContent/innerHTML e o alert/confirm/prompt. E nenhum .js compara com um texto
//      (=== "sem grupo"): o texto muda com o idioma; compara-se o código que a API manda. O que não se traduz de propósito
//      (a marca, "24H") vai no fixo("..."). A conferência nas telas (testes/texto-fixo.cjs) pega o resto, com o sistema
//      inteiro em outro idioma.
//  12. As listas do sistema têm uma definição só: as formas de escolha do sorteio (formas_de_escolha_definicao, no lib.php)
//      e os tipos de modo (modo_tipos_lista) saem do núcleo pela API (formas, tipos_de_modo, escolha), e as telas mostram o
//      que vem. Nenhuma tela (um .js ou o script de uma página) escreve a chave de uma forma: uma lista escrita na tela fica
//      para trás quando uma forma nova entra. E toda forma tem o roteiro dela (formas_roteiros). A conferência no navegador
//      (testes/sorteio-interface.cjs) confere que cada lista da tela é a da API e que cada forma sorteia um dia.
//      O mesmo vale para as listas fixas (listas_fixas, no lib.php: o "Para" de um campo, o sentido de um programa de winder, a
//      segurança do e-mail...): saem com o dicionário (recurso=textos: listas) e as telas as leem com listaFixa, opcoesDaLista
//      e nomeNaLista. Uma tela que escreve dois ou mais itens da mesma lista é recusada, e também um in_array, no núcleo ou
//      na API, com as chaves de uma lista (a validação é lista_fixa_tem). No navegador: testes/listas-interface.cjs.
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
const INTERNOS = ["lib.php", "banco.php", "config.php", "config.exemplo.php", "operacoes.php", "colecoes.php", "colunas.php", "simulador_motor.php", "fontes.php", "opcoes.php", "alertas.php", "contas.php", "pagina.php", "cron.php", "importar.php",
    "instalar.php", "senha.php", "reconstrucao.php"];
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
foreach (array_merge(["lib.php", "banco.php", "operacoes.php", "colecoes.php", "colunas.php", "simulador_motor.php", "fontes.php", "opcoes.php", "alertas.php", "contas.php", "cron.php", "reconstrucao.php"], glob("api/*.php")) as $f) {
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
$pode_banco = array_merge(["banco.php", "lib.php", "operacoes.php", "colecoes.php", "colunas.php", "simulador_motor.php", "fontes.php", "opcoes.php", "alertas.php", "contas.php", "api.php", "cron.php", "reconstrucao.php"], glob("api/*.php"));
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
foreach (["instalar.php", "senha.php", "importar.php"] as $f) {
    foreach (array_intersect(chamadas($f), $nucleo) as $fn) {
        if (!in_array($fn, $da_api, true) && !($f === "senha.php" && in_array($fn, ["senha_servidor_contas", "senha_servidor_trocar"], true))) {
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

// ---------- 11. nenhum texto fixo nas telas ----------
// os textos entre aspas de um .js, fora dos comentários e das expressões regulares: [início, fim, o texto]
function literais_js($codigo)
{
    $res = [];
    $n = strlen($codigo);
    $antes = "";
    for ($i = 0; $i < $n; $i++) {
        $c = $codigo[$i];
        if ($c === "/" && ($codigo[$i + 1] ?? "") === "/") {
            $f = strpos($codigo, "\n", $i);
            $i = $f === false ? $n : $f;
            continue;
        }
        if ($c === "/" && ($codigo[$i + 1] ?? "") === "*") {
            $f = strpos($codigo, "*/", $i + 2);
            $i = $f === false ? $n : $f + 1;
            continue;
        }
        if ($c === "/" && preg_match('/(^|[(,=:\[!&|?{};+]|return)\s*$/', $antes) === 1) {
            // uma expressão regular: até a barra que a fecha, fora de um [...]
            $classe = false;
            for ($i++; $i < $n && ($codigo[$i] !== "/" || $classe); $i++) {
                if ($codigo[$i] === "\\") {
                    $i++;
                } elseif ($codigo[$i] === "[") {
                    $classe = true;
                } elseif ($codigo[$i] === "]") {
                    $classe = false;
                }
            }
            $antes = "x";
            continue;
        }
        if ($c === '"' || $c === "'" || $c === "`") {
            $j = $i + 1;
            while ($j < $n && $codigo[$j] !== $c) {
                $j += $codigo[$j] === "\\" ? 2 : 1;
            }
            $res[] = [$i, $j + 1, substr($codigo, $i + 1, $j - $i - 1)];
            $i = $j;
            $antes = "x";
            continue;
        }
        if (!ctype_space($c)) {
            $antes = substr($antes . $c, -12);
        }
    }
    return $res;
}
foreach ($js as $f) {
    $codigo = (string)file_get_contents($f);
    foreach (literais_js($codigo) as [$ini, $fim, $txt]) {
        // com os escapes resolvidos (" " é um espaço, não letras)
        $txt = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($m) { return mb_chr(hexdec($m[1]), "UTF-8"); }, $txt);
        if (preg_match('/\p{L}/u', $txt) !== 1) {
            continue;
        }
        $antes = substr($codigo, max(0, $ini - 300), $ini - max(0, $ini - 300));
        $depois = substr($codigo, $fim, 200);
        $linha = substr_count($codigo, "\n", 0, $ini) + 1;
        // o primeiro argumento do t(), do tk() e do fixo(): é o próprio dicionário (ou o que não se traduz)
        if (preg_match('/(?<![\w$.])(t|tk|fixo)\(\s*$/', $antes) === 1) {
            continue;
        }
        // o conteúdo de um <code>: é código (um nome de variável, um comando), igual em todo idioma
        if (preg_match('/el\("code",\s*\{[^{}]*\},\s*$/', $antes) === 1) {
            continue;
        }
        $motivo = null;
        if (preg_match('/\}\s*,\s*$/', $antes) === 1 && preg_match('/^\s*[)+]/', $depois) === 1) {
            $motivo = "vira o conteúdo de um el()";
        } elseif ((preg_match('/\}\s*,\s*[\w$.!()\[\]\s=<>&|]*\?\s*$/', $antes) === 1 && preg_match('/^\s*:/', $depois) === 1)
            || (preg_match('/\}\s*,\s*[\w$.!()\[\]\s=<>&|]*\?\s*"(?:[^"\\\\]|\\\\.)*"\s*:\s*$/', $antes) === 1 && preg_match('/^\s*[)+]/', $depois) === 1)) {
            // el("td", {}, x ? "com" : "sem")
            $motivo = "vira o conteúdo de um el() (num ?:)";
        } elseif (preg_match('/(?<![\w$.])h\(\s*$/', $antes) === 1) {
            $motivo = "vira texto na tela (h())";
        } elseif (preg_match('/"(title|placeholder|aria-label|alt)"\s*:\s*$/', $antes) === 1) {
            $motivo = "vira um atributo que se lê (title, placeholder, aria-label, alt)";
        } elseif (preg_match('/(textContent|innerText|innerHTML|\.title|\.placeholder)\s*\+?=\s*$|(?<![\w$.])(alert|confirm|prompt)\(\s*$/', $antes) === 1) {
            $motivo = "vira texto na tela";
        } elseif (preg_match('/\?\s*(?:t\("(?:[^"\\\\]|\\\\.)*"(?:\s*,\s*\{[^{}]*\})?\)|h\([\w$.]*\))\s*:\s*$/', $antes) === 1) {
            // ? t("...") : "texto"   ou   ? h(x) : "texto"
            $motivo = "é o outro lado de um ?: que mostra texto";
        } elseif (preg_match('/\?\s*$/', $antes) === 1 && preg_match('/^\s*:\s*(t|h)\(/', $depois) === 1) {
            $motivo = "é o outro lado de um ?: que mostra texto";
        } elseif (preg_match('/[{,]\s*\d+\s*:\s*(?:[^,{}"]*\?\s*(?:"(?:[^"\\\\]|\\\\.)*"\s*:\s*)?)?$/', $antes) === 1) {
            // {1: "texto"} ou {2: n === 1 ? "registro" : "registros"}: os parâmetros do t() vão para a tela
            $motivo = "vira um parâmetro do t()";
        } elseif (preg_match('/[{,]\s*[A-Za-z_$][\w$]*\s*:\s*$/', $antes) === 1 && preg_match('/^\s*[,}]/', $depois) === 1
            && preg_match('/^\s*(?:var|const|let)\s+[\w$]+\s*=\s*\{\s*(?:[A-Za-z_$][\w$]*\s*:\s*(?:t\("(?:[^"\\\\]|\\\\.)*"\)|"(?:[^"\\\\]|\\\\.)*")\s*,?\s*)+\};?\s*$/', $linha_txt = explode("\n", $codigo)[$linha - 1], $m_obj) === 1
            && strpos($m_obj[0], ": t(") !== false) {
            // {imagem: "imagens", video: t("vídeos")}: uma lista de nomes em que os outros são do dicionário
            $motivo = "é um nome numa lista em que os outros passam pelo t()";
        } elseif ((preg_match('/[!=]==?\s*$/', $antes) === 1 || preg_match('/^\s*[!=]==?/', $depois) === 1)
            && preg_match('/[^\x00-\x7F]|\p{L} \p{L}/u', $txt) === 1) {
            $motivo = "compara com um texto, que muda com o idioma (compare com o código que a API manda)";
        }
        if ($motivo !== null) {
            falha("11", $f . ":" . $linha . ": \"" . mb_substr($txt, 0, 60) . "\" fora do t(): " . $motivo);
        }
    }
}

// ---------- 12. as listas do sistema têm uma definição só ----------
// As formas de escolha do sorteio vivem em formas_de_escolha_definicao() (lib.php): a API as manda (formas, tipos_de_modo,
// escolha) e as telas mostram o que vem. Uma tela (um .js ou o script de uma página) que escreve a chave de uma forma tem
// uma lista dela, que fica para trás quando uma forma nova entra (foi assim que o "Sortear este dia por" ficou sem o menos
// usado). E toda forma da definição tem o roteiro dela (formas_roteiros).
$lib_12 = (string)file_get_contents("lib.php");
$corpo_12 = function ($nome) use ($lib_12) {
    $i = strpos($lib_12, "function " . $nome . "(");
    $f = $i === false ? false : strpos($lib_12, "\n}\n", $i);
    return $i === false || $f === false ? "" : substr($lib_12, $i, $f - $i);
};
preg_match_all('/^\s*"([a-z_]+)" => \["nome" =>/m', $corpo_12("formas_de_escolha_definicao"), $m_12);
$formas_12 = $m_12[1];
preg_match_all('/"([a-z_]+)" => "/', $corpo_12("formas_roteiros"), $m_12);
$roteiros_12 = $m_12[1];
if (count($formas_12) < 5) {
    falha("12", "não achei as formas de escolha em formas_de_escolha_definicao() (lib.php)");
}
foreach (array_diff($formas_12, $roteiros_12) as $k) {
    falha("12", "a forma de escolha " . $k . " não tem roteiro em formas_roteiros() (lib.php): sortearia como outra, em silêncio");
}
foreach (array_diff($roteiros_12, $formas_12) as $k) {
    falha("12", "formas_roteiros() tem o roteiro de " . $k . ", que não é uma forma de formas_de_escolha_definicao()");
}
// "aleatorio" também é um tipo de modo (as telas dos tipos o citam): as outras chaves só existem como forma
$so_formas_12 = array_values(array_diff($formas_12, ["aleatorio"]));
$telas_12 = $js;
foreach ($paginas as $pg) {
    $telas_12[] = $pg;
}
foreach ($telas_12 as $f) {
    $codigo = (string)file_get_contents($f);
    if (substr($f, -4) === ".php") {
        // de uma página, só os scripts dela
        preg_match_all('/<script>(.*?)<\/script>/s', $codigo, $m_s);
        $codigo = implode("\n", $m_s[1]);
    }
    foreach (literais_js($codigo) as [$ini, $fim, $txt]) {
        if (in_array($txt, $so_formas_12, true)) {
            falha("12", $f . ":" . (substr_count($codigo, "\n", 0, $ini) + 1) . ": \"" . $txt . "\" é a chave de uma forma de escolha escrita na tela (as formas vêm da API: formas, tipos_de_modo[].formas, escolha)");
        }
    }
    foreach (array_merge($so_formas_12, ["aleatorio"]) as $k) {
        // a chave como nome de propriedade ({ponderado: ...}) também é uma lista
        if (preg_match('/[{,]\s*' . $k . '\s*:/', $codigo) === 1) {
            falha("12", $f . ": {" . $k . ": ...} é uma lista das formas de escolha escrita na tela (as formas vêm da API)");
        }
    }
    // as listas fixas (listas_fixas, no lib.php): a tela pode perguntar por uma chave (papel === "master"), mas não pode
    // escrever a lista. Duas ou mais chaves da mesma lista escritas onde se monta uma lista (o value de uma opção ou de um
    // campo, um item de uma lista entre colchetes, o nome de uma propriedade que guarda um texto) são uma lista da tela
    foreach (listas_fixas() as $nome_l => $itens_l) {
        $achadas = [];
        foreach (array_keys($itens_l) as $k) {
            $q = preg_quote((string)$k, "/");
            if (preg_match('/"value":\s*"' . $q . '"|[\[,]\s*"' . $q . '"\s*[,\]]|[{,]\s*' . $q . '\s*:\s*(?:t\(|")/', $codigo) === 1) {
                $achadas[] = (string)$k;
            }
        }
        if (count($achadas) >= 2) {
            falha("12", $f . ": escreve " . implode(", ", $achadas) . ", da lista fixa " . $nome_l . " (as listas fixas vêm com o dicionário: listaFixa(\"" . $nome_l . "\"), opcoesDaLista, nomeNaLista, no api.js)");
        }
    }
}
// e as listas fixas em si: cada uma com itens, cada item com chave e nome, e a API manda todas (recurso=textos: listas)
$api_12 = listas_fixas_api();
foreach (listas_fixas() as $nome_l => $itens_l) {
    if (!is_array($itens_l) || count($itens_l) === 0) {
        falha("12", "a lista fixa " . $nome_l . " não tem itens");
        continue;
    }
    foreach (lista_fixa($nome_l) as $k => $n) {
        if (!is_string($n) || trim($n) === "") {
            falha("12", "a lista fixa " . $nome_l . ": o item " . $k . " não tem nome");
        }
        if (!lista_fixa_tem($nome_l, $k) || lista_fixa_tem($nome_l, $k . "_que_nao_existe")) {
            falha("12", "a lista fixa " . $nome_l . ": lista_fixa_tem não confere a chave " . $k);
        }
    }
    if (array_column($api_12[$nome_l] ?? [], "chave") !== array_map("strval", array_keys($itens_l)) || array_column($api_12[$nome_l] ?? [], "nome") !== array_values(lista_fixa($nome_l))) {
        falha("12", "a lista fixa " . $nome_l . " não sai inteira, na ordem, em listas_fixas_api()");
    }
}
// nenhuma validação com a lista escrita de novo: um in_array com as chaves de uma lista fixa, no núcleo ou na API, é a mesma
// lista em outro lugar (use lista_fixa_tem)
foreach ($php as $f) {
    if (strpos($f, "testes/") === 0 || strpos($f, "textos/") === 0) {
        continue;
    }
    $codigo = (string)file_get_contents($f);
    if (preg_match_all('/in_array\(\s*[^,()]+(?:\([^()]*\))?[^,()]*,\s*\[((?:\s*"[A-Za-z_0-9]+"\s*,?)+)\]/', $codigo, $m_in, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($m_in[1] as [$dentro, $pos]) {
            preg_match_all('/"([A-Za-z_0-9]+)"/', $dentro, $m_k);
            $chaves_in = $m_k[1];
            sort($chaves_in);
            foreach (listas_fixas() as $nome_l => $itens_l) {
                $chaves_l = array_map("strval", array_keys($itens_l));
                sort($chaves_l);
                if ($chaves_in === $chaves_l) {
                    falha("12", $f . ":" . (substr_count($codigo, "\n", 0, $pos) + 1) . ": in_array com as chaves da lista fixa " . $nome_l . " (use lista_fixa_tem(\"" . $nome_l . "\", ...))");
                }
            }
        }
    }
}

if ($problemas !== []) {
    echo implode("\n", $problemas), "\n";
    echo "A arquitetura não bate (" . count($problemas) . " problemas).\n";
    exit(1);
}
echo "A arquitetura confere (" . count($php) . " .php, " . count($js) . " .js, " . count($paginas) . " páginas, 12 regras).\n";
exit(0);
