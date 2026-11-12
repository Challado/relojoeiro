<?php
// A conferência das consultas ao banco: nenhum pedido passa a consultar mais do que devia. Com o banco noutra máquina, cada
// consulta é uma ida e volta de rede, e o que pesa numa tela é a QUANTIDADE delas: a página Hoje já fez 3.800 para 28 relógios,
// lendo os mesmos valores dezenas de vezes (o banco estava em ordem: chave primária e índice em tudo). O teste de paridade não
// vê isso, porque a resposta sai certa.
//
// Lê o registro que o sistema grava com a variável de ambiente RELOGIOS_CONSULTAS (uma linha por pedido: banco.php,
// banco_consultas_ligar) durante o roteiro (testes/cenario.php) e confere três coisas:
//   1. O orçamento por pedido: cada consulta e cada gravação da API (GET hoje, POST relogio.salvar, o cron...) tem um teto de
//      comandos e um teto de leituras idênticas repetidas (o mesmo texto com os mesmos parâmetros no mesmo pedido: trabalho
//      perdido), guardados no testes/consultas.json. Passou do teto com a folga, falha. O pedido que o arquivo não conhece
//      (um recurso novo) vale até o "teto_sem_orcamento".
//   2. O crescimento por relógio: cadastra mais relógios (com uma leitura de carga cada) e mede quantas consultas A MAIS cada
//      pedido faz por relógio. É o que pega a consulta dentro de um laço: não depende do tamanho do banco do roteiro.
//   3. O relatório: os pedidos mais caros e os comandos mais repetidos, para saber onde otimizar (sai sempre, passe ou não).
//
//   php testes/consultas.php <endereço do api.php> <registro> [--gravar]
// --gravar: grava o testes/consultas.json com o que mediu (depois de otimizar, ou de um recurso novo: confira o relatório
// antes, porque o arquivo passa a aceitar o que está aí). Termina com 0 se tudo cabe no orçamento, 1 se algo passou.
// O testes/paridade.sh --consultas sqlite faz a rodada inteira (o roteiro com o registro ligado e esta conferência).
// O token: RELOGIOS_TOKEN (padrão: o do testes/config.teste.php).
if (PHP_SAPI !== "cli" || count($argv) < 3) {
    fwrite(STDERR, "Uso: php testes/consultas.php <endereço do api.php> <registro> [--gravar]\n");
    exit(2);
}
$API = $argv[1];
$REGISTRO = $argv[2];
$GRAVAR = in_array("--gravar", $argv, true);
$TOKEN = getenv("RELOGIOS_TOKEN") ?: "token-de-teste-123";
$ARQUIVO = __DIR__ . "/consultas.json";
// quantos relógios a mais, e os pedidos medidos antes e depois deles (a consulta sem recurso devolve tudo)
const RELOGIOS_A_MAIS = 10;
const PEDIDOS_POR_RELOGIO = ["hoje", "", "totais", "previsao", "plano", "criterios", "cadastros", "estatisticas", "autonomia", "galeria", "relatorios"];

function sair($texto)
{
    fwrite(STDERR, $texto . "\n");
    exit(1);
}

// A chave de um pedido no orçamento: "GET hoje", "GET conta/menu", "GET relatorios:custos", "POST relogio.salvar", "cli cron.php"
function chave($l)
{
    if ($l["metodo"] === "POST") {
        return "POST " . $l["recurso"] . ($l["acao"] !== "" ? "." . $l["acao"] : "");
    }
    return $l["metodo"] . " " . $l["recurso"] . ($l["parte"] !== "" ? "/" . $l["parte"] : "") . ($l["metodo"] === "GET" && $l["tipo"] !== "" ? ":" . $l["tipo"] : "");
}

function ler_registro($arquivo)
{
    $linhas = [];
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $t) {
        $l = json_decode($t, true);
        if (is_array($l) && isset($l["consultas"])) {
            $linhas[] = $l;
        }
    }
    return $linhas;
}

function chamar($metodo, $params)
{
    global $API, $TOKEN;
    $ch = curl_init($metodo === "GET" ? $API . (count($params) > 0 ? "?" . http_build_query($params) : "") : $API);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["X-Api-Token: " . $TOKEN], CURLOPT_TIMEOUT => 120]);
    if ($metodo === "POST") {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $corpo = (string)curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json = json_decode($corpo, true);
    if ($codigo !== 200 || !is_array($json)) {
        sair("consultas: " . $metodo . " " . http_build_query($params) . " respondeu " . $codigo . ": " . substr($corpo, 0, 300));
    }
    return $json;
}

// Pede uma consulta e devolve quantos comandos ela mandou ao banco: a linha que o pedido acrescenta ao registro (o sistema a
// grava quando o pedido termina, logo depois da resposta: espera até 10 s por ela)
function medir($recurso)
{
    global $REGISTRO;
    $antes = count(ler_registro($REGISTRO));
    chamar("GET", $recurso === "" ? [] : ["recurso" => $recurso]);
    for ($i = 0; $i < 100; $i++) {
        clearstatcache();
        $linhas = ler_registro($REGISTRO);
        for ($k = count($linhas) - 1; $k >= $antes; $k--) {
            if ($linhas[$k]["metodo"] === "GET" && $linhas[$k]["recurso"] === $recurso) {
                return (int)$linhas[$k]["consultas"];
            }
        }
        usleep(100000);
    }
    sair("consultas: o pedido de " . ($recurso === "" ? "(tudo)" : $recurso) . " não apareceu no registro " . $REGISTRO . " em 10 s (o servidor está com RELOGIOS_CONSULTAS?)");
}

if (!is_file($REGISTRO)) {
    sair("consultas: não achei o registro " . $REGISTRO . ": o servidor tem de rodar com RELOGIOS_CONSULTAS apontando para ele");
}
$guardado = is_file($ARQUIVO) ? json_decode((string)file_get_contents($ARQUIVO), true) : null;
if (!$GRAVAR && !is_array($guardado)) {
    sair("consultas: falta o testes/consultas.json (rode com --gravar)");
}
$folga = $guardado["folga"] ?? ["vezes" => 1.3, "mais" => 20];
$teto_novo = (int)($guardado["teto_sem_orcamento"] ?? 600);
$folga_relogio = (float)($guardado["folga_por_relogio"] ?? 1.0);

// ---------- 1. o que o roteiro mediu, por pedido ----------
$linhas = ler_registro($REGISTRO);
if (count($linhas) < 100) {
    sair("consultas: o registro só tem " . count($linhas) . " pedidos: o roteiro rodou com RELOGIOS_CONSULTAS?");
}
$medido = [];
$repetidos = [];
$identicos = [];
foreach ($linhas as $l) {
    $k = chave($l);
    $m = $medido[$k] ?? ["vezes" => 0, "consultas" => 0, "identicas" => 0];
    $medido[$k] = ["vezes" => $m["vezes"] + 1, "consultas" => max($m["consultas"], (int)$l["consultas"]), "identicas" => max($m["identicas"], (int)$l["identicas"])];
    foreach ($l["repetidas"] as $texto => $n) {
        if ($n > 1) {
            $repetidos[$texto] = max($repetidos[$texto] ?? 0, $n);
        }
    }
    foreach ($l["as_identicas"] as $texto => $n) {
        $identicos[$texto] = max($identicos[$texto] ?? 0, $n);
    }
}
ksort($medido);

// ---------- 2. o crescimento por relógio ----------
$antes = [];
foreach (PEDIDOS_POR_RELOGIO as $p) {
    $antes[$p] = medir($p);
}
// o grupo e o tipo de um relógio com leitura de carga (o smartwatch do roteiro): os novos nascem iguais a ele
$modelo = null;
foreach (chamar("GET", ["recurso" => "hoje"])["relogios"] ?? [] as $r) {
    $f = chamar("GET", ["recurso" => "ficha", "relogio" => $r["id"]])["relogio"] ?? [];
    // (no_id 0: o relógio fica na raiz da árvore)
    if (($f["previsao"]["aplica"] ?? false) === true && count($f["leituras"] ?? []) > 0 && array_key_exists("no_id", $f)) {
        $modelo = $f;
        break;
    }
}
if ($modelo === null) {
    sair("consultas: o roteiro não deixou nenhum relógio com leitura de carga para servir de modelo");
}
for ($i = 1; $i <= RELOGIOS_A_MAIS; $i++) {
    $novo = chamar("POST", ["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Consultas " . $i, "no_id" => $modelo["no_id"], "disponivel" => 1]);
    if (empty($novo["ok"]) || empty($novo["id"])) {
        sair("consultas: não criei o relógio " . $i . ": " . json_encode($novo, JSON_UNESCAPED_UNICODE));
    }
    $leitura = chamar("POST", ["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $novo["id"], "tipo" => "carga", "valor" => 80, "estado" => "repouso"]);
    if (empty($leitura["ok"])) {
        sair("consultas: não lancei a leitura do relógio " . $i . ": " . json_encode($leitura, JSON_UNESCAPED_UNICODE));
    }
}
$por_relogio = [];
foreach (PEDIDOS_POR_RELOGIO as $p) {
    $por_relogio[$p === "" ? "(tudo)" : $p] = round((medir($p) - $antes[$p]) / RELOGIOS_A_MAIS, 1);
}

// ---------- 3. o relatório ----------
echo "Consultas ao banco: " . count($linhas) . " pedidos do roteiro, " . count($medido) . " tipos de pedido, " . array_sum(array_column($linhas, "consultas")) . " comandos no total.\n\n";
echo "Os pedidos mais caros (o máximo de comandos num pedido):\n";
$caros = $medido;
uasort($caros, function ($a, $b) { return $b["consultas"] <=> $a["consultas"]; });
foreach (array_slice($caros, 0, 15, true) as $k => $m) {
    echo sprintf("  %6d comandos  %5d idênticas  %4d vezes  %s\n", $m["consultas"], $m["identicas"], $m["vezes"], $k);
}
echo "\nA mais por relógio (com " . RELOGIOS_A_MAIS . " relógios a mais):\n";
foreach ($por_relogio as $p => $n) {
    echo sprintf("  %6.1f comandos por relógio  %s\n", $n, $p);
}
arsort($identicos);
echo "\nAs leituras idênticas mais repetidas num pedido (o mesmo comando com os mesmos parâmetros):\n";
foreach (array_slice($identicos, 0, 8, true) as $texto => $n) {
    echo sprintf("  %5d vezes a mais  %s\n", $n, substr($texto, 0, 130));
}
arsort($repetidos);
echo "\nOs comandos mais repetidos num pedido (o mesmo texto, com outros parâmetros):\n";
foreach (array_slice($repetidos, 0, 8, true) as $texto => $n) {
    echo sprintf("  %5d vezes  %s\n", $n, substr($texto, 0, 130));
}
echo "\n";

// ---------- gravar o orçamento ----------
if ($GRAVAR) {
    $novo = [
        "como" => "O orçamento das consultas ao banco (testes/consultas.php): o máximo de comandos e de leituras idênticas repetidas que cada pedido fez no roteiro, e quantos comandos a mais cada pedido faz por relógio. Gravado por php testes/consultas.php ... --gravar (testes/paridade.sh --consultas --gravar sqlite). Um pedido falha quando passa de teto x folga.vezes + folga.mais; o que não está aqui vale até teto_sem_orcamento; por relógio, até o gravado + folga_por_relogio.",
        "folga" => $folga, "teto_sem_orcamento" => $teto_novo, "folga_por_relogio" => $folga_relogio, "por_relogio" => $por_relogio,
        "por_pedido" => array_map(function ($m) { return ["consultas" => $m["consultas"], "identicas" => $m["identicas"]]; }, $medido),
    ];
    file_put_contents($ARQUIVO, json_encode($novo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "Orçamento gravado em testes/consultas.json: " . count($medido) . " tipos de pedido.\n";
    exit(0);
}

// ---------- conferir ----------
$problemas = [];
$teto = function ($base) use ($folga) { return (int)ceil($base * (float)$folga["vezes"]) + (int)$folga["mais"]; };
foreach ($medido as $k => $m) {
    $o = $guardado["por_pedido"][$k] ?? null;
    if ($o === null) {
        if ($m["consultas"] > $teto_novo) {
            $problemas[] = $k . ": " . $m["consultas"] . " comandos num pedido que o orçamento não conhece (o teto sem orçamento é " . $teto_novo . ")";
        }
        continue;
    }
    if ($m["consultas"] > $teto((int)$o["consultas"])) {
        $problemas[] = $k . ": " . $m["consultas"] . " comandos (o orçamento é " . $o["consultas"] . ", com a folga " . $teto((int)$o["consultas"]) . ")";
    }
    if ($m["identicas"] > $teto((int)$o["identicas"])) {
        $problemas[] = $k . ": " . $m["identicas"] . " leituras idênticas repetidas (o orçamento é " . $o["identicas"] . ", com a folga " . $teto((int)$o["identicas"]) . ")";
    }
}
foreach ($por_relogio as $p => $n) {
    $o = $guardado["por_relogio"][$p] ?? null;
    if ($o !== null && $n > (float)$o + $folga_relogio) {
        $problemas[] = $p . ": " . $n . " comandos a mais por relógio (o orçamento é " . $o . ", com a folga " . ((float)$o + $folga_relogio) . "): tem consulta dentro de um laço";
    }
}
if (count($problemas) > 0) {
    echo "PASSOU DO ORÇAMENTO (" . count($problemas) . "):\n  " . implode("\n  ", $problemas) . "\n\n"
        . "Veja acima o que se repete. Se a conta nova é devida (um recurso novo, mais trabalho de verdade), grave o orçamento:\n"
        . "  testes/paridade.sh --consultas --gravar sqlite\n";
    exit(1);
}
echo "As consultas cabem no orçamento: " . count($medido) . " tipos de pedido e " . count($por_relogio) . " medidas por relógio.\n";
