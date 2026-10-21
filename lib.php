<?php
// Relógios 2: o núcleo. O código só tem mecanismos genéricos; o conteúdo (a árvore, os campos, os lançamentos, as
// fórmulas, os critérios, os modos) é tudo cadastro. Aqui: o banco, o token obrigatório, o login, a árvore, os campos com
// os seus valores, os tipos de lançamento e o motor de cálculo (as fórmulas).
// O config.php da pasta do sistema; RELOGIOS_CONFIG (uma variável de ambiente do servidor) aponta outro, para os testes rodarem
// sem tocar no config.php de verdade (testes/paridade.sh). Sem ela, o de sempre
require_once getenv("RELOGIOS_CONFIG") ?: __DIR__ . "/config.php";
// os idiomas: t() e os formatos de data e número (textos/funcoes.php)
require_once __DIR__ . "/textos/funcoes.php";

// O fuso: o FUSO do config.php; sem ele (ou vazio), o do PHP (date.timezone no php.ini; sem nada lá, UTC). Um nome que o
// PHP não conhece para o sistema, como o token abaixo: rodar com a hora errada é pior que não rodar.
/** @var mixed $fuso_cfg */
$fuso_cfg = defined("FUSO") ? trim((string)constant("FUSO")) : "";
$ERRO_FUSO = "";
if ($fuso_cfg !== "") {
    if (in_array($fuso_cfg, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        date_default_timezone_set($fuso_cfg);
    } else {
        $ERRO_FUSO = t("Sistema parado: FUSO no config.php (\"{1}\") não é um fuso que o PHP conheça. Use um nome como America/Sao_Paulo, ou deixe vazio para usar o do PHP (date.timezone no php.ini, agora {2}).",
            [1 => $fuso_cfg, 2 => date_default_timezone_get()]);
    }
}
// o fuso do sistema: o das datas gravadas no banco (o FUSO do config.php, ou o do PHP). Cada coleção pode ter o seu (a
// Configuração, "Fuso horário") e cada usuário o dele (Minha conta): o pedido roda nesse fuso (fuso_definir), e a camada do
// banco converte as datas com hora ao ler e ao gravar (banco.php, banco_fuso_*): no banco, tudo fica no fuso do sistema
$GLOBALS["FUSO_SISTEMA"] = date_default_timezone_get();
$GLOBALS["FUSO_PEDIDO"] = $GLOBALS["FUSO_SISTEMA"];
$GLOBALS["FUSO_ORIGEM"] = "sistema";

function fuso_sistema()
{
    return $GLOBALS["FUSO_SISTEMA"];
}

// um nome de fuso que o PHP conhece ("America/Sao_Paulo", "Europe/Lisbon", "UTC"), com a caixa certa; null se não é
function fuso_valido($f)
{
    static $todos = null;
    $f = trim((string)$f);
    if ($f === "") {
        return null;
    }
    if ($todos === null) {
        $todos = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $todos[strtolower($id)] = $id;
        }
        $todos["utc"] = "UTC";
    }
    return $todos[strtolower($f)] ?? null;
}

// os fusos que o PHP conhece, com o deslocamento de agora (as listas de Minha conta, da Configuração e de Usuários)
function fusos_lista()
{
    static $lista = null;
    if ($lista === null) {
        $lista = [];
        $agora = new DateTimeImmutable("now", new DateTimeZone("UTC"));
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $lista[] = ["nome" => $id, "deslocamento" => $agora->setTimezone(new DateTimeZone($id))->format("P")];
        }
    }
    return $lista;
}

// O fuso do pedido: o do usuário (Minha conta), senão o da coleção (a Configuração), senão o do sistema. Vale para o
// date() e o strtotime() de tudo, e liga a conversão do banco quando é outro que não o do sistema
function fuso_definir($f, $origem = "sistema")
{
    $f = fuso_valido($f) ?? fuso_sistema();
    date_default_timezone_set($f);
    $GLOBALS["FUSO_PEDIDO"] = $f;
    $GLOBALS["FUSO_ORIGEM"] = $f === fuso_sistema() ? "sistema" : $origem;
    banco_fuso($f === fuso_sistema() ? null : $f);
}

// o fuso em que o pedido roda e de onde ele veio (usuario, colecao ou sistema)
function fuso_atual()
{
    return $GLOBALS["FUSO_PEDIDO"];
}

function fuso_origem()
{
    return $GLOBALS["FUSO_ORIGEM"];
}

// o fuso de um pedido com usuário e coleção (e o da coleção sozinha, no cron): [fuso, origem]. O usuário escolhe em Minha
// conta: vazio (o da coleção), "navegador" (o fuso do aparelho, que o api.js manda no cookie fuso_navegador; um script
// manda fuso_pedido=; sem nenhum dos dois, o da coleção) ou um fuso fixo
function fuso_do_pedido($usuario = null)
{
    $f = trim((string)($usuario["fuso"] ?? ""));
    if ($f === "navegador") {
        $nav = fuso_valido($_REQUEST["fuso_pedido"] ?? ($_COOKIE["fuso_navegador"] ?? null));
        if ($nav !== null) {
            return [$nav, "navegador"];
        }
    } elseif (fuso_valido($f) !== null) {
        return [fuso_valido($f), "usuario"];
    }
    if (colecao_atual() !== null && fuso_valido(cfg("fuso")) !== null) {
        return [fuso_valido(cfg("fuso")), "colecao"];
    }
    return [fuso_sistema(), "sistema"];
}

// ---------------------------------------------------------------------------------------------------------------------
// O relógio da coleção: o dia do rodízio (o plano, o relógio do dia, as rodadas do cron, o horário de uso, a escala, as
// estatísticas por dia) é sempre o da coleção, no fuso dela (a Configuração; sem ele, o do sistema), para qualquer usuário:
// o plano é um só, por data, de todos os membros. O fuso do usuário vale para os instantes que ele vê e digita. Daqui
// saem o "hoje" e o "agora" da coleção e os instantes das horas de parede dela (uso_inicio, uso_fim, fecha_as...)
// ---------------------------------------------------------------------------------------------------------------------
function fuso_da_colecao()
{
    return (colecao_atual() !== null ? fuso_valido(cfg("fuso")) : null) ?? fuso_sistema();
}

function fuso_colecao_tz()
{
    static $tz = [];
    $f = fuso_da_colecao();
    return $tz[$f] ?? ($tz[$f] = new DateTimeZone($f));
}

// a meia-noite de hoje na coleção (um DateTimeImmutable no fuso dela: format("Y-m-d") é o dia, getTimestamp() o instante)
function hoje_da_colecao()
{
    return new DateTimeImmutable("today", fuso_colecao_tz());
}

// a hora de parede de agora na coleção ("HH:MM")
function hora_da_colecao()
{
    return (new DateTimeImmutable("now", fuso_colecao_tz()))->format("H:i");
}

// o dia da coleção em que cai um instante (AAAA-MM-DD)
function dia_da_colecao($instante)
{
    return (new DateTimeImmutable("@" . (int)$instante))->setTimezone(fuso_colecao_tz())->format("Y-m-d");
}

// o instante de uma hora de parede da coleção num dia ("2026-10-17", "07:30" ou "07:30:00")
function instante($data, $hora)
{
    $hora = (string)$hora;
    $d = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $data . " " . (strlen($hora) === 5 ? $hora . ":00" : $hora), fuso_colecao_tz());
    return $d === false ? (int)strtotime($data . " " . $hora) : $d->getTimestamp();
}

// a próxima meia-noite da coleção depois de um instante
function meia_noite_seguinte($instante)
{
    return (new DateTimeImmutable(dia_da_colecao($instante), fuso_colecao_tz()))->modify("+1 day")->getTimestamp();
}

// ---------------------------------------------------------------------------------------------------------------------
// O token da API é obrigatório: API_TOKEN no config.php, texto com pelo menos 10 caracteres sem contar os espaços das
// pontas. Sem isso o sistema inteiro para (a página e a API respondem o erro; a linha de comando mostra e sai com 1).
// ---------------------------------------------------------------------------------------------------------------------
$ERRO_TOKEN = "";
// o valor pode ser qualquer coisa que alguém escreveu no config.php; o @var diz isso às ferramentas de análise
/** @var mixed $token_cfg */
$token_cfg = defined("API_TOKEN") ? constant("API_TOKEN") : null;
if (!defined("API_TOKEN")) {
    $ERRO_TOKEN = t("o config.php não define API_TOKEN");
} elseif ($token_cfg === null) {
    $ERRO_TOKEN = t("API_TOKEN está nulo no config.php");
} elseif (!is_string($token_cfg)) {
    $ERRO_TOKEN = t("API_TOKEN no config.php tem de ser um texto, entre aspas");
} elseif (trim($token_cfg) === "") {
    $ERRO_TOKEN = t("API_TOKEN está em branco no config.php");
} elseif (strlen(trim($token_cfg)) < 10) {
    $ERRO_TOKEN = t("API_TOKEN no config.php tem {1} caracteres; o mínimo é 10", [1 => strlen(trim($token_cfg))]);
}
if ($ERRO_TOKEN !== "") {
    $ERRO_TOKEN = t("Sistema parado: {1}. Defina no config.php, por exemplo: define('API_TOKEN', 'uma-chave-longa-e-secreta'); com pelo menos 10 caracteres (sem contar espaços nas pontas).",
        [1 => $ERRO_TOKEN]);
}
// $ERRO_TOKEN é o motivo de o sistema estar parado (o cron e a API conferem ele): o token ou o fuso
if ($ERRO_TOKEN === "" && $ERRO_FUSO !== "") {
    $ERRO_TOKEN = $ERRO_FUSO;
}
if ($ERRO_TOKEN !== "") {
    if (PHP_SAPI === "cli" && basename((string)($_SERVER["SCRIPT_FILENAME"] ?? "")) === "cron.php") {
        // o cron fica mudo: registra o motivo nas execuções e não faz mais nada (cron.php confere $ERRO_TOKEN)
    } elseif (PHP_SAPI === "cli") {
        fwrite(STDERR, $ERRO_TOKEN . "\n");
        exit(1);
    } else {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["erro" => $ERRO_TOKEN], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Banco: MySQL/MariaDB, PostgreSQL ou SQLite (DB_TIPO no config.php). db(), sql(), linhas(), linha(), valor(), ultimo_id()
// e a tradução do SQL estão no banco.php
// ---------------------------------------------------------------------------------------------------------------------
require_once __DIR__ . "/banco.php";

// O sorteio: um número inteiro de $min a $max, todos com a mesma chance. A aleatoriedade vem de fora, nesta ordem:
//   1. RANDOM.ORG (www.random.org): ruído atmosférico (a variação das micro-ondas do ar, convertida em bits);
//   2. fora do ar, o ANU QRNG (qrng.anu.edu.au, o endereço antigo, que responde sem chave): flutuações quânticas da luz;
//   3. sem os dois (sem internet, cota esgotada), o gerador seguro do PHP (random_int: o do sistema operacional, como o
//      secrets do Python).
// Os números vêm num lote de 16 bits cada (aleatorio_lote), guardado na memória do pedido; o sorteio junta quantos pedaços
// de 16 bits precisar e descarta o que passa do último múltiplo inteiro da faixa (sem viés: cada resultado com a mesma
// chance). Se os dois serviços falham, o sistema não tenta de novo por 10 minutos (config aleatorio_fora) e usa o do PHP.
// Com a variável de ambiente RELOGIOS_SEMENTE (os testes de paridade entre os bancos), repetível: a mesma semente dá os
// mesmos sorteios em cada pedido e em cada rodada do cron, sem rede
function sorteio($min, $max)
{
    static $semente = null;
    if ($semente === null) {
        $s = getenv("RELOGIOS_SEMENTE");
        $semente = $s === false || $s === "" ? false : (int)$s;
        if ($semente !== false) {
            mt_srand($semente);
        }
    }
    $min = (int)$min;
    $max = (int)$max;
    if ($max <= $min) {
        return $min;
    }
    if ($semente !== false) {
        aleatorio_fonte(t("semente de teste"));
        return mt_rand($min, $max);
    }
    $faixa = $max - $min + 1;
    $pedacos = 1;
    while ($pedacos < 3 && 65536 ** $pedacos < $faixa) {
        $pedacos++;
    }
    $espaco = 65536 ** $pedacos;
    $limite = $espaco - ($espaco % $faixa);
    for ($tentativa = 0; $tentativa < 100; $tentativa++) {
        $v = 0;
        for ($i = 0; $i < $pedacos; $i++) {
            $v = $v * 65536 + aleatorio_16();
        }
        if ($v < $limite) {
            return $min + $v % $faixa;
        }
    }
    return random_int($min, $max);
}

// A fonte do último número sorteado (RANDOM.ORG, ANU QRNG, o gerador do PHP ou a semente de teste): o motivo do sorteio
// diz de onde veio
function aleatorio_fonte($nova = null)
{
    static $fonte = "";
    if ($nova !== null) {
        $fonte = $nova;
    }
    return $fonte;
}

// Os endereços das fontes (o config.php pode trocar, para testes)
if (!defined("ALEATORIO_RANDOMORG_URL")) {
    define("ALEATORIO_RANDOMORG_URL", "https://www.random.org/integers/");
}
if (!defined("ALEATORIO_ANU_URL")) {
    define("ALEATORIO_ANU_URL", "https://qrng.anu.edu.au/API/jsonI.php");
}

// Um número de 16 bits (0 a 65535) do lote; o lote vazio busca outro (64 números) na primeira fonte que responder
function aleatorio_16()
{
    static $lote = [];
    static $fonte = "";
    if (count($lote) === 0) {
        [$lote, $fonte] = aleatorio_lote(64);
    }
    aleatorio_fonte($fonte);
    return array_shift($lote);
}

// Um lote de $n números de 16 bits: [números, fonte]. RANDOM.ORG; senão o ANU QRNG; senão o random_int do PHP. Cada
// serviço tem 4 segundos; a falha dos dois fica anotada (aleatorio_fora) e por 10 minutos nem se tenta. A fonte de cada
// lote buscado fica na configuração (aleatorio_ultima: a fonte e quando), para a Configuração e a API mostrarem
function aleatorio_lote($n)
{
    static $falhou = [];
    $fora = function_exists("sis") ? (int)sis("aleatorio_fora") : 0;
    $tentar = $fora === 0 || time() - $fora > 600;
    $pegar = function ($url) {
        if (!function_exists("curl_init")) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 4, CURLOPT_USERAGENT => "Relojoeiro/1.0 (rodizio de relogios)"]);
        $corpo = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return $codigo === 200 && is_string($corpo) ? $corpo : null;
    };
    $valido = function ($lista) use ($n) {
        if (!is_array($lista) || count($lista) !== $n) {
            return null;
        }
        $res = [];
        foreach ($lista as $x) {
            if (!is_numeric($x) || (int)$x < 0 || (int)$x > 65535 || (string)(int)$x !== trim((string)$x)) {
                return null;
            }
            $res[] = (int)$x;
        }
        return $res;
    };
    $lote = null;
    $fonte = "";
    if ($tentar && !isset($falhou["random.org"])) {
        $corpo = $pegar(ALEATORIO_RANDOMORG_URL . "?" . http_build_query(["num" => $n, "min" => 0, "max" => 65535, "col" => 1, "base" => 10, "format" => "plain", "rnd" => "new"]));
        $lote = $corpo !== null ? $valido(preg_split("/\\s+/", trim($corpo))) : null;
        if ($lote !== null) {
            $fonte = "RANDOM.ORG";
        } else {
            $falhou["random.org"] = true;
        }
    }
    if ($lote === null && $tentar && !isset($falhou["anu"])) {
        $corpo = $pegar(ALEATORIO_ANU_URL . "?" . http_build_query(["length" => $n, "type" => "uint16"]));
        $j = $corpo !== null ? json_decode($corpo, true) : null;
        $lote = is_array($j) && ($j["success"] ?? false) === true ? $valido($j["data"] ?? null) : null;
        if ($lote !== null) {
            $fonte = "ANU QRNG";
        } else {
            $falhou["anu"] = true;
        }
    }
    if ($lote === null) {
        $lote = [];
        for ($i = 0; $i < $n; $i++) {
            $lote[] = random_int(0, 65535);
        }
        $fonte = t("gerador seguro do PHP (random_int)");
        if ($tentar && function_exists("cfg_set")) {
            try {
                sis_set("aleatorio_fora", (string)time());
            } catch (Throwable $e) {
                // sem banco: só não anota
            }
        }
    } elseif ($fora !== 0 && function_exists("cfg_set")) {
        try {
            sis_set("aleatorio_fora", "");
        } catch (Throwable $e) {
        }
    }
    if (function_exists("cfg_set")) {
        try {
            sis_set("aleatorio_ultima", t("{1} em {2}", [1 => $fonte, 2 => date("Y-m-d H:i:s")]));
        } catch (Throwable $e) {
        }
    }
    return [$lote, $fonte];
}

// Dois nomes iguais, sem diferenciar maiúsculas de minúsculas, inclusive acentuadas (É = é), sem depender do mbstring
function mesmo_nome($a, $b)
{
    return preg_match("/^" . preg_quote(trim((string)$a), "/") . "\$/iu", trim((string)$b)) === 1;
}

// ---------------------------------------------------------------------------------------------------------------------
// A árvore de classificação: cada relógio fica num ponto dela; cada ponto pode ter campos, lançamentos e fórmulas
// ---------------------------------------------------------------------------------------------------------------------

// Os pontos da árvore, por id (lidos uma vez por requisição; $recarregar depois de mudar a árvore)
function nos_todos($recarregar = false)
{
    // guardado por coleção (o cron passa por todas)
    static $todos_ = [];
    $k_ = (int)colecao_atual();
    $n = $todos_[$k_] ?? null;
    if ($n === null || $recarregar) {
        $n = [];
        foreach (linhas("SELECT * FROM no ORDER BY ordem, nome") as $x) {
            $n[(int)$x["id"]] = $x;
        }
    }
    $todos_[$k_] = $n;
    return $n;
}

// Os pontos da raiz até $id: [id, id, ...] (vazio: na raiz, ou ponto que não existe)
function no_cadeia($id)
{
    $n = nos_todos();
    $cadeia = [];
    $atual = (int)$id;
    // o limite de voltas só protege de uma árvore corrompida (um ciclo); as operações não deixam criar um
    while ($atual > 0 && isset($n[$atual]) && count($cadeia) < 1000) {
        array_unshift($cadeia, $atual);
        $atual = (int)$n[$atual]["pai_id"];
    }
    return $cadeia;
}

// Os pontos em ordem de árvore (cada um seguido dos de dentro dele): [[id, profundidade], ...], profundidade 0 = na raiz
function nos_em_ordem()
{
    $n = nos_todos();
    $filhos = [];
    foreach ($n as $id => $x) {
        $filhos[(int)$x["pai_id"]][] = $id;
    }
    $lista = [];
    $pilha = [];
    foreach (array_reverse($filhos[0] ?? []) as $id) {
        $pilha[] = [$id, 0];
    }
    // o limite de voltas só protege de uma árvore corrompida (um ciclo)
    while (count($pilha) > 0 && count($lista) <= count($n)) {
        $atual = array_pop($pilha);
        $lista[] = $atual;
        foreach (array_reverse($filhos[$atual[0]] ?? []) as $id) {
            $pilha[] = [$id, $atual[1] + 1];
        }
    }
    return $lista;
}

// O caminho por extenso: "Smartwatch › Xiaomi › HyperOS"; na raiz: "todos os relógios"
function no_caminho($id)
{
    $n = nos_todos();
    $cadeia = no_cadeia($id);
    return count($cadeia) > 0 ? implode(" › ", array_map(function ($x) use ($n) { return $n[$x]["nome"]; }, $cadeia)) : t("todos os relógios");
}

// ---------------------------------------------------------------------------------------------------------------------
// Campos: definidos por você, em algum ponto da árvore (ou para todos); valem para os relógios daquele ponto e de
// tudo abaixo dele (os de cada nível somam). O valor de cada relógio fica em campo_valor.
// ---------------------------------------------------------------------------------------------------------------------
$TIPOS_CAMPO = [
    "inteiro" => tk("Número inteiro"),
    "decimal" => tk("Número decimal"),
    "sim_nao" => tk("Sim ou não (nas contas: 1 ou 0)"),
    "data" => tk("Data (nas contas: por DIAS_DESDE, DIAS_ATE e SOMA_MESES)"),
    "lista" => tk("Lista de opções (compara e dá faixa por categoria)"),
    "texto" => tk("Texto (compara e dá faixa por categoria)"),
];

// Todos os campos, por identificador (uma vez por requisição)
function campos_todos($recarregar = false)
{
    // guardado por coleção (o cron passa por todas)
    static $todos_ = [];
    $k_ = (int)colecao_atual();
    $c = $todos_[$k_] ?? null;
    if ($c === null || $recarregar) {
        $c = [];
        foreach (campos_com_opcoes(linhas("SELECT * FROM campo ORDER BY ordem, nome")) as $x) {
            $c[$x["identificador"]] = $x;
        }
    }
    $todos_[$k_] = $c;
    return $c;
}

// Os campos com as opções de cada lista (a tabela campo_opcao) em "opcoes": uma por linha, na ordem (null: nenhuma)
function campos_com_opcoes($campos)
{
    $ops = [];
    foreach (linhas("SELECT campo_id, valor FROM campo_opcao ORDER BY campo_id, ordem") as $o) {
        $ops[(int)$o["campo_id"]][] = $o["valor"];
    }
    return array_map(function ($c) use ($ops) {
        $c["opcoes"] = isset($ops[(int)$c["id"]]) ? implode("\n", $ops[(int)$c["id"]]) : null;
        return $c;
    }, $campos);
}

// Grava as opções de um campo do tipo lista (o texto, uma por linha; as repetidas e as vazias saem): a tabela campo_opcao
function campo_gravar_opcoes($campo_id, $texto)
{
    sql("DELETE FROM campo_opcao WHERE campo_id = ?", [(int)$campo_id]);
    $ordem = 0;
    foreach (explode("\n", str_replace("\r", "", (string)$texto)) as $op) {
        $op = mb_substr(trim($op), 0, 120);
        // a repetida fica de fora; quem diz se é repetida é o próprio banco (maiúsculas e acentos não contam, como na chave única)
        if ($op !== "" && (int)valor("SELECT COUNT(*) FROM campo_opcao WHERE campo_id = ? AND valor = ?", [(int)$campo_id, $op]) === 0) {
            sql("INSERT INTO campo_opcao (campo_id, ordem, valor) VALUES (?, ?, ?)", [(int)$campo_id, ++$ordem, $op]);
        }
    }
}

// Os campos que valem para um relógio: os de todos e os de cada ponto da cadeia dele
function campos_do_relogio($r)
{
    $cadeia = no_cadeia($r["no_id"] ?? 0);
    // os campos da lista de desejos (o preço-alvo, a prioridade) só valem para o relógio desejado
    $desejado = ($r["posse"] ?? "colecao") === "desejado";
    $res = [];
    foreach (campos_todos() as $ident => $c) {
        if ((int)($c["so_desejado"] ?? 0) === 1 && !$desejado) {
            continue;
        }
        if ($c["no_id"] === null || in_array((int)$c["no_id"], $cadeia, true)) {
            $res[$ident] = $c;
        }
    }
    return $res;
}

// Os valores gravados de um relógio: [identificador => texto]
function valores_proprios($id)
{
    $res = [];
    foreach (linhas("SELECT c.identificador, v.valor FROM campo_valor v JOIN campo c ON c.id = v.campo_id WHERE v.relogio_id = ?", [$id]) as $x) {
        $res[$x["identificador"]] = $x["valor"];
    }
    return $res;
}

// Os valores do relógio para as contas: os do calibre dele e, por cima, os dele mesmo (o relógio manda); o que nenhum dos
// dois tem fica com o padrão do campo (o do grupo), como sempre
function valores_do_relogio($id)
{
    return array_merge(valores_do_calibre_do_relogio($id), valores_proprios($id));
}

// Os calibres dos relógios da coleção, lidos uma vez por requisição (e de novo depois de uma gravação: FORMULAS_VERSAO):
// [relogio_id => ["calibre" => a linha do calibre, "valores" => [identificador => valor]]]. As contas pedem os valores de
// cada relógio muitas vezes; uma consulta por pedido pesava (no Firebird, minutos a mais no cenário)
function calibres_dos_relogios()
{
    static $cache = [];
    $k = (int)colecao_atual() . "/" . ($GLOBALS["FORMULAS_VERSAO"] ?? 0);
    if (!isset($cache[$k])) {
        $res = [];
        foreach (linhas("SELECT rc.relogio_id, c.* FROM relogio_calibre rc JOIN calibre c ON c.id = rc.calibre_id") as $x) {
            $rid = (int)$x["relogio_id"];
            unset($x["relogio_id"]);
            $res[$rid] = ["calibre" => $x, "valores" => []];
        }
        foreach (linhas("SELECT rc.relogio_id, c.identificador, v.valor FROM relogio_calibre rc JOIN calibre_valor v ON v.calibre_id = rc.calibre_id
            JOIN campo c ON c.id = v.campo_id") as $x) {
            $res[(int)$x["relogio_id"]]["valores"][$x["identificador"]] = $x["valor"];
        }
        $cache = [$k => $res];
    }
    return $cache[$k];
}

// O calibre de um relógio (a linha do calibre), ou null
function calibre_do_relogio($rid)
{
    return calibres_dos_relogios()[(int)$rid]["calibre"] ?? null;
}

// Os valores que um calibre define: [identificador => valor]
function valores_do_calibre($cid)
{
    $res = [];
    foreach (linhas("SELECT c.identificador, v.valor FROM calibre_valor v JOIN campo c ON c.id = v.campo_id WHERE v.calibre_id = ?", [(int)$cid]) as $x) {
        $res[$x["identificador"]] = $x["valor"];
    }
    return $res;
}

// Os valores que o calibre de um relógio define: [identificador => valor] (vazio sem calibre)
function valores_do_calibre_do_relogio($rid)
{
    return calibres_dos_relogios()[(int)$rid]["valores"] ?? [];
}

// De onde vem o valor de um campo num relógio: informado (o do relógio), calibre (o do calibre dele), padrao (o padrão do
// campo, $usado não vazio) ou vazio
function origem_valor($rid, $nome, $usado)
{
    static $cache = [];
    $k = (int)$rid . "/" . ($GLOBALS["FORMULAS_VERSAO"] ?? 0);
    if (!isset($cache[$k])) {
        $cache[$k] = [valores_proprios((int)$rid), valores_do_calibre_do_relogio((int)$rid)];
    }
    [$p, $c] = $cache[$k];
    return ($p[$nome] ?? "") !== "" ? "informado" : (($c[$nome] ?? "") !== "" ? "calibre" : ($usado === null || $usado === "" ? "vazio" : "padrao"));
}

// Os campos do formulário do calibre (a Configuração calibre_campos): "movimento" (o padrão: só os campos marcados como do
// movimento, campo.calibre) ou "todos" (todos os do cadastro, menos os da lista de desejos)
function calibre_campos()
{
    return cfg("calibre_campos") === "todos" ? "todos" : "movimento";
}

// Os identificadores dos campos que o formulário de um calibre mostra, na ordem do cadastro: os da Configuração e, com só
// os do movimento, também os que o calibre já define (para poder tirar)
function calibre_campos_no_formulario($valores = [])
{
    $todos = calibre_campos() === "todos";
    $res = [];
    foreach (campos_todos() as $ident => $c) {
        if ((int)($c["so_desejado"] ?? 0) !== 1 && ($todos || (int)($c["calibre"] ?? 0) === 1 || ($valores[$ident] ?? "") !== "")) {
            $res[] = $ident;
        }
    }
    return $res;
}

// Os calibres da árvore, por nome, cada um com os valores que define e os relógios da coleção que apontam para ele
function calibres_todos()
{
    $rels = [];
    foreach (linhas("SELECT rc.calibre_id, r.id, r.nome FROM relogio_calibre rc JOIN relogio r ON r.id = rc.relogio_id ORDER BY r.nome, r.id") as $x) {
        $rels[(int)$x["calibre_id"]][] = ["id" => (int)$x["id"], "nome" => $x["nome"]];
    }
    $docs = [];
    foreach (linhas("SELECT calibre_id, COUNT(*) AS n FROM documento WHERE calibre_id IS NOT NULL GROUP BY calibre_id") as $x) {
        $docs[(int)$x["calibre_id"]] = (int)$x["n"];
    }
    return array_map(function ($c) use ($rels, $docs) {
        $v = valores_do_calibre((int)$c["id"]);
        return ["id" => (int)$c["id"], "nome" => $c["nome"], "fabricante" => $c["fabricante"], "link" => $c["link"], "observacao" => $c["observacao"],
            "valores" => (object)$v, "relogios" => $rels[(int)$c["id"]] ?? [], "documentos" => $docs[(int)$c["id"]] ?? 0, "campos_no_formulario" => calibre_campos_no_formulario($v)];
    }, linhas("SELECT * FROM calibre ORDER BY nome, id"));
}

// Confere e normaliza um valor digitado para o tipo do campo. Devolve [valor normalizado (texto) ou null se vazio, erro]
function valor_para_campo($c, $v)
{
    $v = trim((string)$v);
    $res = [null, ""];
    if ($v !== "") {
        if ($c["tipo"] === "inteiro") {
            $res = preg_match("/^-?[0-9]+\$/", $v) === 1 ? [(string)(int)$v, ""] : [null, t("tem de ser um número inteiro")];
        } elseif ($c["tipo"] === "decimal") {
            // aceita "1.079,99", "1079,99" ou "1079.99"
            $n = strpos($v, ',') !== false ? str_replace(',', '.', str_replace('.', "", $v)) : $v;
            $res = is_numeric($n) ? [(string)(0 + (float)$n), ""] : [null, t("tem de ser um número")];
        } elseif ($c["tipo"] === "sim_nao") {
            $s = strtolower($v);
            $res = in_array($s, ["1", "sim", "s", "true"], true) ? ["1", ""] : (in_array($s, ["0", "não", "nao", "n", "false"], true) ? ["0", ""] : [null, t("tem de ser sim ou não")]);
        } elseif ($c["tipo"] === "data") {
            $d = DateTimeImmutable::createFromFormat("!Y-m-d", $v);
            $res = $d && $d->format("Y-m-d") === $v ? [$v, ""] : [null, t("tem de ser uma data AAAA-MM-DD")];
        } elseif ($c["tipo"] === "lista") {
            $opcoes = array_values(array_filter(array_map("trim", explode("\n", (string)$c["opcoes"]))));
            $res = in_array($v, $opcoes, true) ? [$v, ""] : [null, t("tem de ser uma das opções: {1}", [1 => implode(", ", $opcoes)])];
        } else {
            $res = strlen($v) <= 1000 ? [$v, ""] : [null, t("passa de 1000 caracteres")];
        }
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// Tipos de lançamento: o que se registra num relógio. Formato: instantaneo (corda), valor (leitura de carga em %) ou
// sessao (no pulso, no winder, no sol: com início e fim). Os lançamentos ficam em "lancamento".
// ---------------------------------------------------------------------------------------------------------------------
$FORMATOS_LANCAMENTO = ["instantaneo" => tk("Instantâneo"), "valor" => tk("Instantâneo com valor"), "sessao" => tk("Sessão, com início e fim")];

function lancamento_tipos($recarregar = false)
{
    // guardado por coleção (o cron passa por todas)
    static $todos_ = [];
    $k_ = (int)colecao_atual();
    $t = $todos_[$k_] ?? null;
    if ($t === null || $recarregar) {
        $t = [];
        foreach (linhas("SELECT * FROM lancamento_tipo ORDER BY ordem, nome") as $x) {
            $t[$x["identificador"]] = $x;
        }
    }
    $todos_[$k_] = $t;
    return $t;
}

// Os tipos de lançamento que valem para um relógio: os de todos e os de cada ponto da cadeia dele
function lancamento_tipos_do_relogio($r)
{
    $cadeia = no_cadeia($r["no_id"] ?? 0);
    $res = [];
    foreach (lancamento_tipos() as $ident => $t) {
        if (($t["no_id"] === null || in_array((int)$t["no_id"], $cadeia, true)) && condicao_vale($t["condicao"] ?? null, $r, time())) {
            $res[$ident] = $t;
        }
    }
    return $res;
}

// A condição "vale quando" de um tipo de lançamento ou de um aviso, para um relógio num instante: uma fórmula que dá 1 (vale)
// ou 0; vazia, vale sempre; com erro ou sem valor (um campo vazio sem padrão), não vale
function condicao_vale($expressao, $r, $momento)
{
    $res = true;
    if ($expressao !== null && trim((string)$expressao) !== "") {
        $ctx = ["r" => $r, "momento" => $momento, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
        try {
            $v = formula_calcular(formula_ler((string)$expressao), $ctx);
        } catch (ErroFormula $e) {
            $v = null;
        }
        $res = is_numeric($v) && (float)$v != 0;
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// Migrações: as mudanças de estrutura do banco depois da instalação, em arquivos migracao_vN.sql, aplicadas pela API
// (recurso=migracoes, acao=aplicar). Cada uma: [arquivo, o que traz, a marca de que já foi aplicada: uma tabela ou
// tabela.coluna]. Com alguma pendente, a API só responde as migrações (a conferência fica no api.php). O arquivo é um
// só para os quatro bancos: o DDL no dialeto do MySQL (o banco.php traduz) e os dados em SQL comum aos quatro.
// ---------------------------------------------------------------------------------------------------------------------
$MIGRACOES = [
    "v2" => ["migracao_v2.sql", tk("lançamentos pela API, com sessões exclusivas (o relógio num lugar só), avisos cadastráveis e a autonomia restante"), "aviso"],
    "v3" => ["migracao_v3.sql", tk("modos de rodízio cadastráveis, o plano, os critérios por lugar e o cron"), "modo"],
    "v4" => ["migracao_v4.sql", tk("a escala inteligente, os eventos personalizados, o Google Agenda, a previsão do smartwatch e a linha do tempo"), "evento_personalizado"],
    "v5" => ["migracao_v5.sql", tk("as telas e as mensagens do sistema antigo: canais com mensagem padrão e personalizada, avisos de carga baixa e de leitura, limite do solar, e o registro completo do cron"), "cron_execucao.teve_atividade"],
    "v6" => ["migracao_v6.sql", tk("o gasto medido pelas leituras (a média dos últimos dias) e as autonomias em segundos"), "medicao"],
    "v7" => ["migracao_v7.sql", tk("o ciclo no rodízio (opção de cada modo, desligada por padrão): um relógio só volta depois que todos do bloco passaram"), "modo.ciclo"],
    "v8" => ["migracao_v8.sql", tk("a condição \"vale quando\" nos tipos de lançamento e nos avisos; o automático sem corda recebe \"Pôr no winder\" em vez de \"Dar corda\""), "aviso.condicao"],
    "v9" => ["migracao_v9.sql", tk("o limite de carga, geral e por relógio: carregar, pôr no sol e dar corda só quando a carga chega a ele"), "campo.identificador=carga_minima"],
    "v10" => ["migracao_v10.sql", tk("os avisos de carga pelo estado de agora: no winder ou no pulso (o automático carrega no pulso), sem aviso de winder ou de corda; no sol, sem aviso de sol; a data do \"Carregar\" pelo gasto de agora (no pulso, o de uso; guardado, o de guardado)"), "config.chave=migracao_v10"],
    "v11" => ["migracao_v11.sql", tk("o motivo de cada escolha do plano: por que aquele relógio saiu naquele dia (a garantia de rodízio, a nota, o sorteio, a escolha à mão)"), "plano.motivo"],
    "v12" => ["migracao_v12.sql", tk("os dias sem uso de um relógio nunca usado contam desde a compra (antes valiam 9999 para todos: empatavam na garantia de rodízio)"), "config.chave=migracao_v12"],
    "v13" => ["migracao_v13.sql", tk("o manual de cada relógio: um arquivo (PDF ou imagem) guardado no banco, que se envia e se abre pela ficha"), "manual|documento"],
    "v14" => ["migracao_v14.sql", tk("os documentos de cada relógio: qualquer arquivo (manual, nota fiscal em PDF e em XML, fotos, vídeos, diversos), numa categoria, com a página Documentos (galeria, vídeos em sequência, visualizador de PDF, resumo da nota); os manuais da v13 passam para lá"), "documento"],
    "v15" => ["migracao_v15.sql", tk("a cópia de segurança de cada documento dentro do banco (em pedaços de 4 MB): o arquivo que sumir da pasta volta sozinho do banco; o cron copia os que já existem e limpa a pasta (relógio excluído, arquivo sem documento)"), "documento_parte"],
    "v16" => ["migracao_v16.sql", tk("o banco no modelo relacional estrito: toda ligação é chave estrangeira, com cascata (ou, se opcional, desfazendo a ligação); as listas guardadas num campo (dias dos blocos e dos eventos, tipos aceitos, opções de um campo) viram tabelas; as mensagens e os canais saem da configuração para a tabela canal_aviso; o modo ativo vira uma marca no modo; a tabela manual sai (os manuais viram documentos)"), "config.chave=migracao_v16", "migracao_v16_dados"],
    "v17" => ["migracao_v17.sql", tk("o banco valida o que guarda: regras (CHECK) em cada tabela, a origem dos lançamentos e do plano numa lista fechada, no máximo um modo em uso, um arquivo por documento; e a cópia dos documentos no banco em três níveis: o sistema (config.php), o relógio e o próprio arquivo"), "config.chave=migracao_v17"],
    "v18" => ["migracao_v18.sql", tk("o carregador: a sessão \"No carregador\" onde vale a leitura de carga (o smartwatch), o estado do relógio mostra a sessão aberta (no sol, no winder, no carregador) e, no carregador, o aviso \"Carregar\" não aparece"), "config.chave=migracao_v18"],
    "v19" => ["migracao_v19.sql", tk("o solar carrega também no pulso: uma parte do que ganharia no sol direto, pelo campo \"No pulso: quanto carrega, comparado ao sol direto\" (padrão 25%; 0 é o de antes, a carga parada)"), "config.chave=migracao_v19"],
    "v20" => ["migracao_v20.sql", tk("o smartwatch carrega no carregador (o campo \"No carregador: horas para encher\"), o aviso \"Tirar do carregador\" que sai na hora pelo Telegram (os avisos \"na hora\"), as fórmulas \"Dias de uso\" e \"Custo por uso\", e no MySQL/MariaDB as tabelas em utf8mb4_unicode_ci"), "config.chave=migracao_v20", "migracao_v20_dados"],
    "v21" => ["migracao_v21.sql", tk("os dias livres do plano: o \"Deixar o período livre\" da página Plano apaga o que foi gerado e deixa os dias sem relógio, sem sorteio, para você escolher à mão"), "plano_livre"],
    "v22" => ["migracao_v22.sql", tk("a situação de cada relógio na coleção (na coleção, desejado, emprestado, vendido ou arquivado: fora da coleção, ele sai do rodízio e dos avisos, e a ficha e o histórico ficam) e a lista de desejos; os campos Marca, Referência e Link, e, só nos desejados, Preço-alvo e Prioridade"), "relogio.posse"],
    "v23" => ["migracao_v23.sql", tk("o aviso Encostado: um relógio da coleção que passou do limite de dias sem ir ao pulso (a Configuração, \"Aviso Encostado: dias sem ir ao pulso\", padrão 30)"), "config.chave=encostado_dias"],
    "v24" => ["migracao_v24.sql", tk("os acessórios: pulseiras, caixas, estojos, ferramentas, carregadores, peças e o que mais for, de um relógio ou soltos (a página Acessórios e o quadro Acessórios do relógio)"), "acessorio"],
    "v25" => ["migracao_v25.sql", tk("várias pessoas, cada uma com as suas coleções: usuários (master e comum, conta demo e oficial), coleções com membros, convites e pedidos de acesso, árvores de grupos que servem a várias coleções do mesmo dono, destinos de alerta (webhook, e-mail, Web Push) e agendas (Google, CalDAV, Outlook, link ICS); o banco inteiro em cascata, na 3ª forma normal. O banco antigo é copiado para um arquivo antes, e o usuário mais antigo vira o master e o dono da coleção"), "sistema.chave=migracao_v25", "migracao_v25"],
    "v26" => ["migracao_v26.sql", tk("o gasto da bateria em cinco motores, escolhidos na Configuração da coleção, no relógio ou no grupo (o campo \"Como calcular o gasto da bateria\"): pelas horas registradas (o original), robusto, pela marcação das leituras (em uso e em repouso, gravada em cada leitura), só o cadastro, ou pelas fórmulas do cadastro; e a cópia dos documentos no banco escolhida na Configuração da coleção, no lugar do config.php"), "lancamento.estado", "migracao_v26_dados"],
    "v27" => ["migracao_v27.sql", tk("o aplicativo no celular (PWA): os aparelhos lembrados de cada usuário (Minha conta: o aplicativo instalado entra sem a senha pela chave gravada no aparelho), o manifesto e o service worker pela API"), "usuario_aparelho"],
    "v28" => ["migracao_v28.sql", tk("o fuso horário de cada usuário (Minha conta) e de cada coleção (a Configuração): o pedido roda no fuso da pessoa, e no banco as datas continuam no fuso do sistema; e o relógio da página Hora certa escolhido na Configuração (analógico, digital, anadigi ou os dois)"), "usuario.fuso"],
    "v29" => ["migracao_v29.sql", tk("o fuso horário de cada destino e de cada agenda (Destinos e Agendas, \"Fuso horário\"; vazio: o da coleção): as horas no texto das mensagens e dos eventos saem nele, como já saem no idioma deles"), "destino.fuso"],
    "v30" => ["migracao_v30.sql", tk("os modos de rodízio com tipo (a tela de cada um: um por semana, semana e fim de semana, por dia da semana, aleatório todo dia, escala inteligente ou personalizado), semanas à frente e o roteiro (as cinco linhas que o motor calcula: candidato, nota, peso, dias, garantia; escrito à mão no personalizado, gerado dos blocos nos outros); um montador do plano só para todos os modos"), "modo.roteiro", "migracao_v30_dados"],
    "v31" => ["migracao_v31.sql", tk("os totais: os da tabela dos relógios (o rodapé, embaixo de cada coluna) e os da página de totais deixam de ser fixos e viram expressões do motor de fórmulas sobre os relógios mostrados (CONTA, SOMA, MEDIA, AGRUPADO, FAIXAS...), cadastradas na aba Totais dos Cadastros; os prontos entram em cada árvore"), "total", "migracao_v31_dados"],
    "v32" => ["migracao_v32.sql", tk("a precisão: o desvio da hora (quanto o relógio está adiantado ou atrasado, lido contra a hora certa, com a posição em que ele ficou) e o acerto da hora; a marcha em segundos por dia, o gráfico, as funções MARCHA, MARCHA_DP, MARCHA_N, MARCHA_ULTIMA e DESVIO_AGORA, e os avisos Acertar a hora e Marcha fora do esperado; os tipos, campos, fórmulas e avisos entram em cada árvore"), "lancamento.posicao", "migracao_v32_dados"],
    "v33" => ["migracao_v33.sql", tk("os calibres: o movimento (NH35, ST2130, B620...) como um cadastro da árvore, com fabricante, o link do manual técnico, as recomendações e os valores dos campos que ele define; cada relógio aponta para um e herda dele o que não tem (o valor do relógio, senão o do calibre, senão o padrão do campo); a aba Calibres dos Cadastros, a função CALIBRE e recurso=calibres"), "calibre"],
    "v34" => ["migracao_v34.sql", tk("os documentos dos calibres (o manual técnico, a ficha de serviço, as fotos do movimento: na página Documentos do calibre, e em todos os relógios com ele), a categoria Manual técnico, e os campos do movimento (campo.calibre: os que o formulário do calibre mostra, com a Configuração calibre_campos em só os do movimento, o padrão)"), "campo.calibre", "migracao_v34_dados"],
    "v35" => ["migracao_v35.sql", tk("os serviços e diagnósticos: cada intervenção num relógio (revisão, troca de pilha, teste de estanqueidade, regulagem, polimento, reparo, diagnóstico), com quem fez, o que foi feito, a mão de obra, as peças trocadas e o custo de cada uma, o teste de estanqueidade (aprovado ou reprovado e a pressão), a marcha antes e depois, a garantia e os documentos; a resistência à água declarada vira um campo do cadastro, separada da estanqueidade comprovada; as funções ULTIMO_SERVICO e CUSTO_SERVICOS, e o total Custo dos serviços"), "servico", "migracao_v35_dados"],
    "v36" => ["migracao_v36.sql", tk("a reserva medida: o teste da reserva de marcha do mecânico (cheio, por qual protocolo, até parar), com a mediana dos testes como a estimativa do relógio e a confiança, sem mexer no valor do calibre; as observações da energia (parou, o indicador de carga baixa), também no solar, que não se calibra; e as funções RESERVA_MEDIDA e RESERVA_TESTES"), "reserva_teste"],
    "v37" => ["migracao_v37.sql", tk("o servidor de e-mail próprio de cada pessoa (Minha conta): quem quiser põe o SMTP dele (o Gmail, o Outlook, o do provedor), e os e-mails dele saem por ele (os destinos de e-mail das coleções dele, os convites e os pedidos que ele manda, os da própria conta); sem ele, vale o servidor do sistema, o do config.php"), "usuario_smtp"],
    "v38" => ["migracao_v38.sql", tk("o modo da interface de cada pessoa (Minha conta): o básico mostra o dia a dia (cadastrar, marcar o uso, a manutenção, escolher um rodízio pronto) e o avançado também as fórmulas, os roteiros, os critérios, as simulações e os totais; o motor é o mesmo"), "usuario.nivel"],
];

// O passo em PHP da v30, depois do SQL dela: cada modo que existe ganha o tipo pela forma dos blocos (modo_tipo_inferir);
// o roteiro dos modos que não são personalizados é gerado a cada uso, dos blocos
function migracao_v30_dados($nova = false)
{
    if ($nova) {
        return;
    }
    sem_escopo(function () {
        foreach (linhas("SELECT * FROM modo ORDER BY id") as $m) {
            $blocos = [];
            foreach (linhas("SELECT * FROM modo_bloco WHERE modo_id = ? ORDER BY ordem, id", [(int)$m["id"]]) as $b) {
                $blocos[] = [array_map("intval", array_column(linhas("SELECT dia FROM modo_bloco_dia WHERE bloco_id = ? ORDER BY dia", [(int)$b["id"]]), "dia")), $b["um_por"]];
            }
            sql("UPDATE modo SET tipo = ? WHERE id = ?", [modo_tipo_inferir($m["escala_dias"], $m["selecao"], $blocos), (int)$m["id"]]);
        }
    });
}

// O passo em PHP da v31: os totais prontos (modelo.json) em cada árvore que ainda não tem nenhum, no idioma da instalação
// (numa instalação nova, a árvore já nasce com eles: arvore_criar)
function migracao_v31_dados($nova = false)
{
    if ($nova) {
        return;
    }
    sem_escopo(function () {
        $tr = modelo_tradutor(sis("idioma") !== "" ? sis("idioma") : "pt-BR");
        foreach (linhas("SELECT id FROM no WHERE pai_id IS NULL ORDER BY id") as $raiz) {
            if ((int)valor("SELECT COUNT(*) FROM total WHERE no_id = ?", [(int)$raiz["id"]]) === 0) {
                totais_do_modelo((int)$raiz["id"], $tr);
            }
        }
    });
}

// O passo em PHP da v32: em cada árvore, os tipos Desvio da hora e Acerto da hora, os campos da marcha esperada e do limite
// do desvio, as fórmulas marcha e desvio_agora e os avisos Acertar a hora e Marcha fora do esperado (modelo.json), no grupo
// do tipo Revisão (o Tradicional; sem ele, a raiz), no idioma da coleção; cada um só onde o identificador ainda não existe
function migracao_v32_dados($nova = false)
{
    if ($nova) {
        return;
    }
    sem_escopo(function () {
        $m = modelo_ler();
        $do_modelo = function ($lista, $idents) use ($m) {
            return array_values(array_filter($m[$lista], function ($x) use ($idents) { return in_array($x["identificador"], $idents, true); }));
        };
        foreach (linhas("SELECT id FROM no WHERE pai_id IS NULL ORDER BY id") as $raiz) {
            $arvore = [(int)$raiz["id"]];
            $nivel = $arvore;
            while (count($nivel) > 0) {
                $nivel = array_map("intval", array_column(linhas("SELECT id FROM no WHERE pai_id IN (" . implode(", ", $nivel) . ")"), "id"));
                $arvore = array_merge($arvore, $nivel);
            }
            $em = " AND no_id IN (" . implode(", ", $arvore) . ")";
            $grupo = valor("SELECT no_id FROM lancamento_tipo WHERE identificador = 'revisao'" . $em);
            $grupo = $grupo === null ? (int)$raiz["id"] : (int)$grupo;
            $lingua = lingua_valida((string)valor("SELECT valor FROM config WHERE chave = 'idioma' AND colecao_id IN (SELECT id FROM colecao WHERE no_id = ?) ORDER BY colecao_id", [(int)$raiz["id"]])) ?? "pt-BR";
            $tr = modelo_tradutor($lingua);
            $existe = function ($tabela, $ident) use ($em) {
                return (int)valor("SELECT COUNT(*) FROM " . $tabela . " WHERE identificador = ?" . $em, [$ident]) > 0;
            };
            foreach ($do_modelo("tipos", ["desvio", "acerto"]) as $x) {
                if (!$existe("lancamento_tipo", $x["identificador"])) {
                    sql("INSERT INTO lancamento_tipo (identificador, nome, formato, unidade, fecha_as, no_id, ordem, exclusiva, mede_gasto, marcha, condicao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$x["identificador"], $tr($x["nome"]), $x["formato"], $x["unidade"], $x["fecha_as"], $grupo, (int)$x["ordem"], (int)$x["exclusiva"], (int)$x["mede_gasto"], $x["marcha"], $x["condicao"]]);
                }
            }
            foreach ($do_modelo("campos", ["marcha_min", "marcha_max", "desvio_limite"]) as $c) {
                if (!$existe("campo", $c["identificador"])) {
                    sql("INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [$c["identificador"], $tr($c["nome"]), $c["tipo"], $c["unidade"], $c["padrao"], $grupo, (int)$c["ordem"], (int)$c["so_desejado"]]);
                }
            }
            foreach ($do_modelo("formulas", ["marcha", "desvio_agora"]) as $f) {
                if (!$existe("formula", $f["identificador"])) {
                    sql("INSERT INTO formula (identificador, nome, expressao, unidade, no_id) VALUES (?, ?, ?, ?, ?)", [$f["identificador"], $tr($f["nome"]), $f["expressao"], $f["unidade"], $grupo]);
                }
            }
            foreach ($do_modelo("avisos", ["acertar_hora", "marcha_fora"]) as $a) {
                if (!$existe("aviso", $a["identificador"])) {
                    $resolve = $a["resolve"] !== null ? valor("SELECT id FROM lancamento_tipo WHERE identificador = ?" . $em, [$a["resolve"]]) : null;
                    sql("INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, ativo, no_id, escala, simula_valor, simula_horas, agenda, condicao, resolve_tipo_id, na_hora)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$a["identificador"], $tr($a["nome"]), $a["expressao"], $a["antecedencia_dias"], $tr($a["texto"]), (int)$a["ativo"],
                        $grupo, $a["escala"], $a["simula_valor"], $a["simula_horas"], $a["agenda"], $a["condicao"], $resolve === null ? null : (int)$resolve, (int)$a["na_hora"]]);
                }
            }
        }
    });
}

// O passo em PHP da v34: em cada árvore, os campos do modelo.json marcados com calibre (os do movimento) ganham a marca, e
// a categoria de documento Manual técnico entra onde ainda não existe (no idioma da coleção)
function migracao_v34_dados($nova = false)
{
    if ($nova) {
        return;
    }
    sem_escopo(function () {
        $m = modelo_ler();
        $do_movimento = array_values(array_map(function ($c) { return $c["identificador"]; }, array_filter($m["campos"], function ($c) { return (int)($c["calibre"] ?? 0) === 1; })));
        $categorias = array_values(array_filter($m["categorias"], function ($c) { return $c["identificador"] === "manual_tecnico"; }));
        foreach (linhas("SELECT id FROM no WHERE pai_id IS NULL ORDER BY id") as $raiz) {
            $arvore = [(int)$raiz["id"]];
            $nivel = $arvore;
            while (count($nivel) > 0) {
                $nivel = array_map("intval", array_column(linhas("SELECT id FROM no WHERE pai_id IN (" . implode(", ", $nivel) . ")"), "id"));
                $arvore = array_merge($arvore, $nivel);
            }
            $em = " AND no_id IN (" . implode(", ", $arvore) . ")";
            if (count($do_movimento) > 0) {
                sql("UPDATE campo SET calibre = 1 WHERE identificador IN (" . implode(", ", array_fill(0, count($do_movimento), "?")) . ")" . $em, $do_movimento);
            }
            $lingua = lingua_valida((string)valor("SELECT valor FROM config WHERE chave = 'idioma' AND colecao_id IN (SELECT id FROM colecao WHERE no_id = ?) ORDER BY colecao_id", [(int)$raiz["id"]])) ?? "pt-BR";
            $tr = modelo_tradutor($lingua);
            foreach ($categorias as $c) {
                if ((int)valor("SELECT COUNT(*) FROM documento_categoria WHERE identificador = ?" . $em, [$c["identificador"]]) === 0) {
                    $ordem = (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM documento_categoria WHERE no_id IN (" . implode(", ", $arvore) . ")");
                    sql("INSERT INTO documento_categoria (identificador, nome, ordem, no_id) VALUES (?, ?, ?, ?)", [$c["identificador"], $tr($c["nome"]), $ordem, (int)$raiz["id"]]);
                    $cid = ultimo_id();
                    foreach ($c["aceita"] as $f) {
                        sql("INSERT INTO documento_categoria_aceita (categoria_id, familia) VALUES (?, ?)", [$cid, $f]);
                    }
                }
            }
        }
    });
}

// O passo em PHP da v35: em cada árvore, o campo da resistência à água declarada (na raiz) e o total Custo dos serviços
// (modelo.json), no idioma da coleção; cada um só onde ainda não existe (o campo pelo identificador, o total pela expressão)
function migracao_v35_dados($nova = false)
{
    if ($nova) {
        return;
    }
    sem_escopo(function () {
        $m = modelo_ler();
        $campos = array_values(array_filter($m["campos"], function ($c) { return $c["identificador"] === "resistencia_agua_declarada"; }));
        $totais = array_values(array_filter($m["totais"], function ($x) { return $x["expressao"] === "SOMA(custo_servicos)"; }));
        foreach (linhas("SELECT id FROM no WHERE pai_id IS NULL ORDER BY id") as $raiz) {
            $arvore = [(int)$raiz["id"]];
            $nivel = $arvore;
            while (count($nivel) > 0) {
                $nivel = array_map("intval", array_column(linhas("SELECT id FROM no WHERE pai_id IN (" . implode(", ", $nivel) . ")"), "id"));
                $arvore = array_merge($arvore, $nivel);
            }
            $em = " AND no_id IN (" . implode(", ", $arvore) . ")";
            $lingua = lingua_valida((string)valor("SELECT valor FROM config WHERE chave = 'idioma' AND colecao_id IN (SELECT id FROM colecao WHERE no_id = ?) ORDER BY colecao_id", [(int)$raiz["id"]])) ?? "pt-BR";
            $tr = modelo_tradutor($lingua);
            foreach ($campos as $c) {
                if ((int)valor("SELECT COUNT(*) FROM campo WHERE identificador = ?" . $em, [$c["identificador"]]) === 0) {
                    sql("INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado, calibre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$c["identificador"], $tr($c["nome"]), $c["tipo"], $c["unidade"], $c["padrao"], (int)$raiz["id"], (int)$c["ordem"], (int)$c["so_desejado"], (int)$c["calibre"]]);
                }
            }
            foreach ($totais as $x) {
                if ((int)valor("SELECT COUNT(*) FROM total WHERE expressao = ?" . $em, [$x["expressao"]]) === 0) {
                    $ordem = (int)valor("SELECT COALESCE(MAX(ordem), 0) + 10 FROM total WHERE no_id IN (" . implode(", ", $arvore) . ")");
                    sql("INSERT INTO total (no_id, coluna, nome, expressao, rodape, pagina, largura, formato, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [(int)$raiz["id"], $x["coluna"], $tr($x["nome"]), $x["expressao"], (int)$x["rodape"], (int)$x["pagina"], $x["largura"], $x["formato"], $ordem]);
                }
            }
        }
    });
}

// O tipo de um modo pela forma dos blocos ($blocos: [[dias (1 a 7), um_por], ...]): escala (com escala_dias); um bloco com
// os sete dias: um por semana (um por bloco), aleatório (um por dia e a forma aleatória) ou por dia da semana; dois blocos,
// segunda a sexta e sábado e domingo, o primeiro um por bloco: semana e fim de semana; todos um por dia: por dia da
// semana; o resto: personalizado (o roteiro gerado dos blocos)
function modo_tipo_inferir($escala_dias, $selecao, $blocos)
{
    if ($escala_dias !== null && $escala_dias !== "") {
        return "escala";
    }
    $dias = array_map(function ($b) { $d = $b[0]; sort($d); return array_values(array_unique($d)); }, $blocos);
    $um_por = array_column($blocos, 1);
    if (count($blocos) === 1 && $dias[0] === [1, 2, 3, 4, 5, 6, 7]) {
        return $um_por[0] === "bloco" ? "semana" : ($selecao === "aleatorio" ? "aleatorio" : "dia_semana");
    }
    if (count($blocos) === 2 && $dias[0] === [1, 2, 3, 4, 5] && $dias[1] === [6, 7] && $um_por[0] === "bloco") {
        return "semana_fds";
    }
    if (count($blocos) > 0 && count(array_filter($um_por, function ($x) { return $x !== "dia"; })) === 0) {
        return "dia_semana";
    }
    return "personalizado";
}

// O passo em PHP da v16, depois do SQL dela: as opções de cada campo do tipo lista (o texto, uma por linha) viram linhas da
// tabela campo_opcao, e a coluna opcoes sai; por último, a marca de que a v16 foi aplicada. Pode rodar de novo (na
// instalação nova, sem opção nenhuma, só tira a coluna e grava a marca)
function migracao_v16_dados()
{
    if (banco_tem_coluna("campo", "opcoes")) {
        foreach (linhas("SELECT id, opcoes FROM campo WHERE opcoes IS NOT NULL") as $c) {
            campo_gravar_opcoes($c["id"], $c["opcoes"]);
        }
        banco_script("ALTER TABLE campo DROP COLUMN opcoes");
    }
    sql("INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v16', '1')");
}

// O passo em PHP da v26, depois do SQL dela: o campo "Como calcular o gasto da bateria" (gasto_motor, uma lista com os
// cinco motores) na raiz de cada árvore que ainda não o tem em grupo nenhum, no idioma da coleção da árvore; e as chaves
// novas da configuração (gasto_motor, documentos_copia, medicao_base, medicao_janela_leituras) em cada coleção que não as
// tem. Na instalação nova não faz nada (o modelo.json já traz os dois). Pode rodar de novo
function migracao_v26_dados($nova = false)
{
    if ($nova) {
        return;
    }
    sem_escopo(function () {
        $m = modelo_ler();
        $campo = null;
        foreach ($m["campos"] as $c) {
            if ($c["identificador"] === "gasto_motor") {
                $campo = $c;
            }
        }
        foreach (linhas("SELECT id FROM no WHERE pai_id IS NULL ORDER BY id") as $raiz) {
            // a árvore inteira: a raiz e os grupos abaixo dela, nível a nível
            $arvore = [(int)$raiz["id"]];
            $nivel = $arvore;
            while (count($nivel) > 0) {
                $nivel = array_map("intval", array_column(linhas("SELECT id FROM no WHERE pai_id IN (" . implode(", ", $nivel) . ")"), "id"));
                $arvore = array_merge($arvore, $nivel);
            }
            if ($campo === null || (int)valor("SELECT COUNT(*) FROM campo WHERE identificador = 'gasto_motor' AND no_id IN (" . implode(", ", $arvore) . ")") > 0) {
                continue;
            }
            $lingua = lingua_valida((string)valor("SELECT valor FROM config WHERE chave = 'idioma' AND colecao_id IN (SELECT id FROM colecao WHERE no_id = ?) ORDER BY colecao_id", [(int)$raiz["id"]])) ?? "pt-BR";
            $tr = modelo_tradutor($lingua);
            sql("INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$campo["identificador"], $tr($campo["nome"]), $campo["tipo"], $campo["unidade"], $campo["padrao"], (int)$raiz["id"], (int)$campo["ordem"], (int)$campo["so_desejado"]]);
            $cid = ultimo_id();
            foreach (array_values($campo["opcoes"] ?? []) as $i => $o) {
                sql("INSERT INTO campo_opcao (campo_id, ordem, valor) VALUES (?, ?, ?)", [$cid, $i + 1, $tr($o)]);
            }
        }
        foreach (linhas("SELECT id FROM colecao ORDER BY id") as $c) {
            foreach (["gasto_motor", "documentos_copia", "medicao_base", "medicao_janela_leituras"] as $k) {
                if ((int)valor("SELECT COUNT(*) FROM config WHERE colecao_id = ? AND chave = ?", [(int)$c["id"], $k]) === 0) {
                    sql("INSERT INTO config (colecao_id, chave, valor) VALUES (?, ?, ?)", [(int)$c["id"], $k, (string)($m["config"][$k] ?? "")]);
                }
            }
        }
    });
}

// O passo em PHP da v20, depois do SQL dela: no MySQL/MariaDB, as tabelas com colunas de texto noutra collation que não a
// do Unicode (a utf8mb4_general_ci, padrão do MariaDB, ordena "dias_de_carga" depois de "dias_desde_compra", e as listas
// saíam numa ordem diferente da dos outros bancos) passam para utf8mb4_unicode_ci. As tabelas novas já nascem nela (o
// banco.php põe a collation em todo CREATE TABLE do MySQL). Por último, a marca de que a v20 foi aplicada
function migracao_v20_dados()
{
    if (banco_tipo() === "mysql") {
        foreach (banco_tabelas_fora_da_collation() as $t) {
            banco_direto("ALTER TABLE `" . str_replace("`", "``", $t) . "` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }
    sql("INSERT IGNORE INTO config (chave, valor) VALUES ('migracao_v20', '1')");
}

// As migrações que faltam aplicar neste banco: as da lista cuja marca ainda não existe. A marca: uma tabela, uma coluna
// (tabela.coluna) ou uma linha (tabela.coluna=valor, para a migração que só acrescenta cadastro); várias separadas por |
// valem uma ou outra (a v13 criou a tabela manual, que a v16 tira: depois dela, a marca é a tabela documento)
// O catálogo de usuários (recurso=usuarios e a resposta sem recurso): o login, quando foi criado e o hash da senha, com o
// algoritmo e o custo. A senha não existe no sistema: o login passa a senha digitada pelo mesmo cálculo (password_hash) e
// confere com o hash (password_verify). Levando o login e o hash para outro sistema que confira do mesmo jeito (bcrypt),
// cada um entra lá com a mesma senha de sempre; e o caminho de volta é o usuarios/salvar com senha_hash
function usuarios_catalogo()
{
    return array_map(function ($u) {
        $info = senha_hash_info((string)$u["senha_hash"]);
        return ["login" => $u["login"], "criado" => $u["criado"], "senha_hash" => $u["senha_hash"], "senha_algoritmo" => $info[0], "senha_custo" => $info[1]];
    }, sem_escopo(function () {
        // o master vê todos; os outros, só a si mesmos
        $u = usuario_atual();
        return usuario_master() || $u === null ? linhas("SELECT login, criado, senha_hash FROM usuario ORDER BY login")
            : linhas("SELECT login, criado, senha_hash FROM usuario WHERE id = ?", [(int)$u["id"]]);
    }));
}

// O algoritmo e o custo de um hash de senha: bcrypt ($2y$, $2b$ ou $2a$, o custo no começo: $2y$12$...) ou o que o
// password_get_info do PHP reconhecer (argon2i, argon2id); [null, null] para o que não é hash de senha
function senha_hash_info($hash)
{
    if (preg_match("#^\\$2[aby]\\$(\\d{2})\\$[./A-Za-z0-9]{53}\$#", $hash, $m) === 1) {
        return ["bcrypt", (int)$m[1]];
    }
    $info = password_get_info($hash);
    return $info["algoName"] !== "unknown" ? [$info["algoName"], isset($info["options"]["cost"]) ? (int)$info["options"]["cost"] : null] : [null, null];
}

// A instalação: o schema.sql no banco do config.php, cada comando traduzido para ele, e os passos em PHP das migrações
// (o schema.sql já traz o SQL delas). Só num banco vazio. A API (recurso=instalacao, acao=instalar) e o instalar.php
// chamam esta. Devolve {ok, mensagem, erros, comandos, tabelas, segundos}
function instalar_banco()
{
    global $MIGRACOES;
    $tabelas = banco_tabelas();
    if (count($tabelas) > 0) {
        return ["ok" => false, "mensagem" => "", "erros" => [t("O banco já tem tabelas ({1}{2}): a instalação é só num banco vazio. Para atualizar um banco que já existe, aplique as migrações (recurso=migracoes, acao=aplicar).", [1 => implode(", ", array_slice($tabelas, 0, 5)), 2 => count($tabelas) > 5 ? "..." : ""])]];
    }
    if (banco_tipo() === "pgsql") {
        // maiúsculas e acentos não contam nas colunas de texto curto, como no MySQL: "Relógio" = "RELOGIO"
        banco_direto("CREATE COLLATION IF NOT EXISTS ci (provider = icu, locale = 'und-u-ks-level1', deterministic = false)");
    }
    $inicio = microtime(true);
    $n = banco_script((string)file_get_contents(__DIR__ . "/schema.sql"));
    // o schema.sql já é a estrutura de agora: as migrações ficam só com a marca (a v25 em diante, que têm a marca no banco
    // novo; o passo em PHP de cada uma sabe que é uma instalação nova)
    foreach ($MIGRACOES as $v => $m) {
        if ((int)substr($v, 1) >= 25 && isset($m[3])) {
            call_user_func($m[3], true);
        }
    }
    $pendentes = migracoes_pendentes();
    if (count($pendentes) > 0) {
        return ["ok" => false, "mensagem" => "", "erros" => [t("A instalação terminou, mas faltam as migrações {1}: o schema.sql está incompleto.", [1 => implode(", ", array_keys($pendentes))])]];
    }
    // o idioma da instalação (o padrão de quem não escolheu): o do pedido. O conjunto inicial entra com o primeiro usuário,
    // na coleção dele (o modelo.json, no idioma dele)
    sis_set("idioma", lingua_atual());
    $seg = round(microtime(true) - $inicio, 1);
    return ["ok" => true, "mensagem" => t("Banco instalado ({1}): {2} comandos, {3} tabelas, em {4} s. Agora crie o primeiro usuário (recurso=usuarios, acao=salvar, com o token).", [1 => ["mysql" => "MySQL/MariaDB", "pgsql" => "PostgreSQL", "sqlite" => "SQLite", "firebird" => t("Firebird")][banco_tipo()], 2 => $n, 3 => count(banco_tabelas()), 4 => decimal_txt($seg, 6)]),
        "erros" => [], "comandos" => $n, "tabelas" => count(banco_tabelas()), "segundos" => $seg];
}

// A situação da instalação (recurso=instalacao): o banco, se está vazio, instalado e em dia, e quantos usuários tem
function instalacao_situacao()
{
    $tabelas = banco_tabelas();
    $vazio = count($tabelas) === 0;
    $pendentes = $vazio ? [] : array_keys(migracoes_pendentes());
    $usuarios = !$vazio && banco_tem_tabela("usuario") ? (int)valor("SELECT COUNT(*) FROM usuario") : 0;
    $proximo = $vazio ? t("instalar: POST recurso=instalacao, acao=instalar")
        : (count($pendentes) > 0 ? t("aplicar as migrações: POST recurso=migracoes, acao=aplicar")
            : ($usuarios === 0 ? t("criar o primeiro usuário: POST recurso=usuarios, acao=salvar, login e senha (com o token)") : t("nada: o sistema está pronto")));
    return ["banco" => banco_tipo(), "vazio" => $vazio, "instalado" => !$vazio, "tabelas" => count($tabelas), "em_dia" => !$vazio && count($pendentes) === 0,
        "migracoes_pendentes" => array_values($pendentes), "usuarios" => $usuarios, "proximo_passo" => $proximo];
}

function migracoes_pendentes()
{
    global $MIGRACOES;
    // a v25 refaz o banco inteiro: na estrutura nova (com a tabela sistema), as de antes ficam para trás (as marcas delas
    // não existem mais); a estrutura nova sem a marca da v25 é a v25 que parou no meio
    $nova = banco_tem_tabela("sistema");
    return sem_escopo(function () use ($MIGRACOES, $nova) {
        return array_filter($MIGRACOES, function ($m, $v) use ($nova) {
            if ($nova && (int)substr($v, 1) < 25) {
                return false;
            }
            foreach (explode("|", $m[2]) as $uma) {
                $linha = explode("=", $uma, 2);
                $marca = explode(".", $linha[0]);
                if (count($linha) === 2) {
                    $existe = banco_tem_tabela($marca[0]) && (int)valor("SELECT COUNT(*) FROM " . $marca[0] . " WHERE " . $marca[1] . " = ?", [$linha[1]]) > 0;
                } else {
                    $existe = count($marca) === 2 ? banco_tem_coluna($marca[0], $marca[1]) : banco_tem_tabela($marca[0]);
                }
                if ($existe) {
                    return false;
                }
            }
            return true;
        }, ARRAY_FILTER_USE_BOTH);
    });
}


// ---------------------------------------------------------------------------------------------------------------------
// O motor de cálculo. Uma fórmula é lida por um interpretador próprio (nunca vira código PHP) e vira uma árvore de
// operações, que é calculada para um relógio num instante (agora, ou uma data da simulação).
//   Números: 12  12,5  12.5      Texto: "carga"      Operações: + - * / ^ ( )      Comparações: = <> < <= > >=
//   Argumentos separados por ponto e vírgula: LIMITA(x; 0; 100)
//   Variáveis: os identificadores dos campos e das fórmulas.
//   Vazio se propaga: se falta um dado, o resultado é vazio ("não se aplica"). Divisão por zero: vazio.
//   Datas viram números de dias (desde 1970-01-01), para entrar em contas.
// ---------------------------------------------------------------------------------------------------------------------

// As funções: nome => [mínimo de argumentos, máximo (null: sem limite), descrição, histórico (o 1º argumento é um tipo
// de lançamento, entre aspas)]
$FUNCOES = [
    "MIN" => [1, null, tk("o menor dos valores (ignora os vazios)"), false],
    "MAX" => [1, null, tk("o maior dos valores (ignora os vazios)"), false],
    "SE" => [3, 3, tk("SE(condição; se verdadeira; se falsa)"), false],
    "E" => [1, null, tk("1 se todas as condições são verdadeiras"), false],
    "OU" => [1, null, tk("1 se alguma condição é verdadeira"), false],
    "NAO" => [1, 1, tk("inverte a condição"), false],
    "LIMITA" => [3, 3, tk("LIMITA(x; mínimo; máximo)"), false],
    "ARREDONDA" => [1, 2, "ARREDONDA(x; casas)", false],
    "ABS" => [1, 1, tk("o valor sem sinal"), false],
    "PADRAO" => [2, 2, tk("PADRAO(x; valor se x estiver vazio)"), false],
    "VAZIO" => [1, 1, tk("1 se o valor está vazio"), false],
    "NADA" => [0, 0, tk("o vazio, de propósito (ex.: SE(condição; NADA(); conta))"), false],
    "HOJE" => [0, 0, tk("a data de hoje (em dias)"), false],
    "AGORA" => [0, 0, tk("o instante de agora (em dias, com a fração do dia)"), false],
    "HORAS_USO" => [0, 0, tk("as horas de um dia de uso, pelo horário de uso da Configuração (das 7h às 22h: 15)"), false],
    "MEDIDO" => [1, 1, tk("MEDIDO(\"uso\") ou MEDIDO(\"repouso\"): o gasto medido pelas leituras, em % por dia (de uso ou fora do pulso): os dois saem juntos das medições dos últimos dias da Configuração (cada intervalo entre duas leituras: a queda = dias de uso × gasto em uso + dias fora × gasto fora), na conta feita nas leituras de cada trecho entre recargas, todas com o mesmo peso, a que não bate com as outras pesando menos (regressão robusta) e partindo do gasto do cadastro; sem nenhuma nesses dias, as de antes; sem nenhuma, vazio"), false],
    "CONFIG" => [1, 1, tk("CONFIG(\"chave\"): um número da Configuração (ex.: CONFIG(\"sol_limiar\"), o limite do solar); vazio se não for número"), false],
    "DIAS_DESDE" => [1, 1, tk("dias desde uma data"), false],
    "DIAS_ATE" => [1, 1, tk("dias até uma data (negativo: já passou)"), false],
    "SOMA_MESES" => [2, 2, tk("SOMA_MESES(data; meses): a data somada de meses, pelo calendário"), false],
    "ULTIMO_VALOR" => [1, 1, tk("ULTIMO_VALOR(\"tipo\"): o valor do último lançamento daquele tipo"), true],
    "ULTIMA_DATA" => [1, 1, tk("ULTIMA_DATA(\"tipo\"): quando foi o último (numa sessão, o fim; aberta: agora)"), true],
    "DIAS_DESDE_ULTIMO" => [1, 1, tk("DIAS_DESDE_ULTIMO(\"tipo\"): dias desde o último (sessão aberta: 0)"), true],
    "HORAS" => [2, 2, tk("HORAS(\"sessão\"; dias): horas naquela sessão nos últimos N dias"), true],
    "HORAS_APOS" => [2, 2, tk("HORAS_APOS(\"sessão\"; \"tipo\"): horas naquela sessão desde o último lançamento do outro tipo"), true],
    "CONTAR" => [2, 2, tk("CONTAR(\"tipo\"; dias): quantos lançamentos (ou dias com sessão) nos últimos N dias"), true],
    "EM_SESSAO" => [1, 1, tk("EM_SESSAO(\"sessão\"): 1 se a sessão está aberta agora"), true],
    "ACUMULA" => [3, null, tk("ACUMULA(início; máximo; perda por hora; \"tipo\"; efeito; ...): um saldo que percorre o histórico: numa sessão da lista, ganha o efeito por hora (negativo: perde); num lançamento instantâneo da lista, passa a valer o efeito; num lançamento com valor (uma leitura), passa a valer a leitura vezes o efeito (1: a própria leitura); no resto, perde a perda por hora; sempre entre 0 e o máximo"), true],
    "MEDIA_COLECAO" => [1, 1, tk("MEDIA_COLECAO(\"variável\"): a média daquela variável nos relógios disponíveis"), false],
    // as peças do gasto medido, pela marcação das leituras (em uso ou em repouso), para montar a própria conta (o motor
    // "pelas fórmulas"): cada medição com as duas leituras marcadas igual; N dias (0: a janela da Configuração)
    "LEITURAS" => [1, 2, tk("LEITURAS(\"uso\" ou \"repouso\"; dias): quantas medições com as duas leituras marcadas assim há nos últimos N dias (sem dias, ou 0: a janela da Configuração)"), false],
    "QUEDA" => [1, 2, tk("QUEDA(\"uso\" ou \"repouso\"; dias): a soma das quedas dessas medições, em pontos de carga"), false],
    "DIAS_MEDIDOS" => [1, 2, tk("DIAS_MEDIDOS(\"uso\" ou \"repouso\"; dias): a soma do tempo delas, em dias de uso (em repouso: dias guardado)"), false],
    "MEDIA_TAXA" => [1, 2, tk("MEDIA_TAXA(\"uso\" ou \"repouso\"; dias): a média simples das taxas de cada uma (% por dia); vazio sem nenhuma"), false],
    "MEDIANA_TAXA" => [1, 2, tk("MEDIANA_TAXA(\"uso\" ou \"repouso\"; dias): a mediana das taxas (resiste a uma medição errada); vazio sem nenhuma"), false],
    "ULTIMA_TAXA" => [1, 1, tk("ULTIMA_TAXA(\"uso\" ou \"repouso\"): a taxa da medição mais recente assim (% por dia); vazio sem nenhuma"), false],
    // a precisão: as leituras do desvio da hora (o tipo de lançamento que mede a marcha) e os acertos, até o instante da conta
    "MARCHA" => [0, 2, tk("MARCHA(dias; \"posição\"): a marcha medida, em segundos por dia (+ adianta, − atrasa): os intervalos entre as leituras do desvio da hora nos últimos N dias (0 ou sem: todos), pesados pelo tempo de cada um; com a posição (pulso, misto, mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda, coroa_direita, winder), só os medidos nela; vazio sem nenhum"), false],
    "MARCHA_DP" => [0, 2, tk("MARCHA_DP(dias; \"posição\"): quanto as marchas desses intervalos variam entre si (o desvio padrão, pesado pelo tempo, em s/dia); vazio com menos de dois"), false],
    "MARCHA_N" => [0, 2, tk("MARCHA_N(dias; \"posição\"): quantos intervalos medidos assim"), false],
    "MARCHA_ULTIMA" => [0, 0, tk("MARCHA_ULTIMA(): a marcha do intervalo mais recente, em s/dia; vazio sem nenhum"), false],
    "DESVIO_AGORA" => [0, 0, tk("DESVIO_AGORA(): o desvio estimado no instante da conta, em segundos (+ adiantado): a última leitura (0 depois de um acerto da hora) mais a marcha de todos os intervalos vezes o tempo desde ela; vazio sem leitura nenhuma"), false],
    // o roteiro dos modos de rodízio: o dia que está sendo montado e o plano
    "DIA_SEMANA" => [0, 0, tk("o dia da semana do dia que está sendo montado (fora do rodízio: o do instante da conta): 1 segunda ... 7 domingo"), false],
    "SEMANA_DO_ANO" => [0, 0, tk("o número da semana do ano (ISO) do dia que está sendo montado"), false],
    "NO_GRUPO" => [1, 1, tk("NO_GRUPO(\"grupo\"): 1 se o relógio está nesse ponto da árvore ou abaixo (o nome, o caminho \"Tradicional › Quartzo\" ou #id; \"todos\": 1 para todos)"), false],
    "RELOGIO" => [1, 1, tk("RELOGIO(\"nome\" ou id): 1 se é esse relógio"), false],
    "CALIBRE" => [1, 1, tk("CALIBRE(\"nome\" ou id): 1 se o relógio tem esse calibre (o movimento)"), false],
    // os serviços e diagnósticos do relógio (até o instante da conta)
    "ULTIMO_SERVICO" => [0, 1, tk("ULTIMO_SERVICO(\"tipo\"): a data do último serviço desse tipo (revisao, pilha, estanqueidade, regulagem, polimento, reparo, diagnostico, outro; \"estanqueidade\" conta também o teste feito numa revisão; sem tipo: qualquer); vazio sem nenhum"), false],
    "CUSTO_SERVICOS" => [0, 1, tk("CUSTO_SERVICOS(dias): quanto os serviços custaram (a mão de obra mais as peças) nos últimos N dias (0 ou sem: todos)"), false],
    // a reserva medida do relógio (os testes terminados até o instante da conta)
    "RESERVA_MEDIDA" => [0, 0, tk("RESERVA_MEDIDA(): a reserva de marcha medida nos testes do relógio, em horas (a mediana dos testes terminados; vazio sem nenhum). PADRAO(RESERVA_MEDIDA(); reserva_horas) usa a medida e, sem ela, o cadastro"), false],
    "RESERVA_TESTES" => [0, 0, tk("RESERVA_TESTES(): quantos testes da reserva o relógio tem terminados"), false],
    "ESCOLHIDO_HA" => [1, 1, tk("ESCOLHIDO_HA(n): 1 se o relógio é o do plano há n dias (1: ontem; -1: amanhã, quando já está no plano)"), false],
    "VEZES_NO_PLANO" => [1, 1, tk("VEZES_NO_PLANO(n): quantos dias o relógio teve no plano nos últimos n dias (antes do dia que está sendo montado)"), false],
    "ESCOLHA" => [2, null, tk("ESCOLHA(n; a; b; c...): o n-ésimo dos valores (só ele é calculado); fora da lista, vazio. Ex.: ESCOLHA(DIA_SEMANA(); ...) dá um valor por dia da semana"), false],
    "SORTEIO" => [0, 0, tk("um número aleatório de 0 a 1 (do lote do RANDOM.ORG, como os sorteios)"), false],
    // as partes de uma data (para agrupar por mês ou por ano nos totais, e para qualquer fórmula)
    "MES" => [1, 1, tk("MES(data): o mês da data, de 1 a 12"), false],
    "ANO" => [1, 1, tk("ANO(data): o ano da data"), false],
    "MES_ANO" => [1, 1, tk("MES_ANO(data): o mês e o ano da data, como texto AAAA-MM (os totais mostram \"ago/2026\")"), false],
];

// As variáveis do motor, que não são campos nem fórmulas do cadastro: o nome => o que devolve
$VARIAVEIS_MOTOR = [
    "nota_criterios" => tk("a nota do relógio pelos critérios (a página Critérios), no instante da conta"),
];

class ErroFormula extends Exception
{
}

// Um instante (segundos) em dias, contados no horário local: a meia-noite local de qualquer data é um número inteiro,
// e a fração é a hora do dia. Assim uma data e o agora entram na mesma conta sem o fuso aparecer.
function dias_de($ts)
{
    return ((int)$ts + (int)date("Z", (int)$ts)) / 86400;
}

// Lê a fórmula e devolve a árvore de operações. Nós: ["n", número], ["t", texto], ["v", variável],
// ["f", FUNÇÃO, [argumentos]], ["op", operador, a, b], ["neg", a]. Erro de escrita: ErroFormula, com a posição.
function formula_ler($f)
{
    // os pedaços: [tipo, valor, posição]; tipos: num, txt, id, op, (, ), ;
    $p = [];
    $i = 0;
    $n = strlen($f);
    while ($i < $n) {
        $c = $f[$i];
        if (ctype_space($c)) {
            $i++;
        } elseif (ctype_digit($c)) {
            preg_match("/^[0-9]+([.,][0-9]+)?/", substr($f, $i), $m);
            $p[] = ["num", (float)str_replace(',', '.', $m[0]), $i];
            $i += strlen($m[0]);
        } elseif ($c === '"') {
            $fim = strpos($f, '"', $i + 1);
            if ($fim === false) {
                throw new ErroFormula(t("texto aberto na posição {1}: falta fechar as aspas", [1 => $i + 1]));
            }
            $p[] = ["txt", substr($f, $i + 1, $fim - $i - 1), $i];
            $i = $fim + 1;
        } elseif (ctype_alpha($c) || $c === '_') {
            preg_match("/^[A-Za-z_][A-Za-z0-9_]*/", substr($f, $i), $m);
            $p[] = ["id", $m[0], $i];
            $i += strlen($m[0]);
        } elseif (in_array(substr($f, $i, 2), ["<=", ">=", "<>"], true)) {
            $p[] = ["op", substr($f, $i, 2), $i];
            $i += 2;
        } elseif (strpos("+-*/^=<>", $c) !== false) {
            $p[] = ["op", $c, $i];
            $i++;
        } elseif (strpos("();", $c) !== false) {
            $p[] = [$c, $c, $i];
            $i++;
        } else {
            throw new ErroFormula(t("caractere inesperado \"{1}\" na posição {2}", [1 => $c, 2 => $i + 1]));
        }
    }
    $st = ["p" => $p, "i" => 0];
    if (count($p) === 0) {
        throw new ErroFormula(t("a fórmula está vazia"));
    }
    $arvore = formula_ler_nivel($st, 0);
    if ($st["i"] < count($p)) {
        throw new ErroFormula(t("\"{1}\" sobrando na posição {2}", [1 => $p[$st["i"]][1], 2 => $p[$st["i"]][2] + 1]));
    }
    return $arvore;
}

// Lê um nível da fórmula, da precedência mais fraca para a mais forte: 0 comparação, 1 soma e subtração, 2 produto e
// divisão, 3 potência (à direita: 2 ^ 3 ^ 2 = 2 ^ 9), 4 sinal e o resto (número, texto, parênteses, variável, função).
// $st: os pedaços e a posição da leitura.
function formula_ler_nivel(&$st, $nivel)
{
    global $FUNCOES;
    $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
    $onde = $t[2] >= 0 ? t("na posição {1}", [1 => $t[2] + 1]) : t("no fim");
    if ($nivel === 0) {
        $res = formula_ler_nivel($st, 1);
        $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
        if ($t[0] === "op" && in_array($t[1], ["=", "<>", "<", "<=", ">", ">="], true)) {
            $st["i"]++;
            $res = ["op", $t[1], $res, formula_ler_nivel($st, 1)];
        }
    } elseif ($nivel === 1 || $nivel === 2) {
        $ops = $nivel === 1 ? ["+", "-"] : ["*", "/"];
        $res = formula_ler_nivel($st, $nivel + 1);
        $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
        while ($t[0] === "op" && in_array($t[1], $ops, true)) {
            $st["i"]++;
            $res = ["op", $t[1], $res, formula_ler_nivel($st, $nivel + 1)];
            $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
        }
    } elseif ($nivel === 3) {
        $res = formula_ler_nivel($st, 4);
        $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
        if ($t[0] === "op" && $t[1] === "^") {
            $st["i"]++;
            $res = ["op", "^", $res, formula_ler_nivel($st, 3)];
        }
    } else {
        $st["i"]++;
        if ($t[0] === "op" && $t[1] === "-") {
            $res = ["neg", formula_ler_nivel($st, 4)];
        } elseif ($t[0] === "num" || $t[0] === "txt") {
            $res = [$t[0] === "num" ? "n" : "t", $t[1]];
        } elseif ($t[0] === "(") {
            $res = formula_ler_nivel($st, 0);
            if (($st["p"][$st["i"]][0] ?? "") !== ")") {
                throw new ErroFormula(t("falta fechar o parêntese {1}", [1 => isset($st["p"][$st["i"]]) ? t("na posição {1}", [1 => $st["p"][$st["i"]][2] + 1]) : t("no fim")]));
            }
            $st["i"]++;
        } elseif ($t[0] === "id" && ($st["p"][$st["i"]][0] ?? "") === "(") {
            $nome = strtoupper($t[1]);
            $de_total = !empty($GLOBALS["LENDO_TOTAL"]) && isset($GLOBALS["FUNCOES_TOTAIS"][$nome]);
            if (!isset($FUNCOES[$nome]) && !$de_total) {
                throw new ErroFormula(t("função desconhecida {1} {2}", [1 => $t[1], 2 => $onde]));
            }
            $st["i"]++;
            $args = [];
            if (($st["p"][$st["i"]][0] ?? "") !== ")") {
                $args[] = formula_ler_nivel($st, 0);
                while (($st["p"][$st["i"]][0] ?? "") === ";") {
                    $st["i"]++;
                    $args[] = formula_ler_nivel($st, 0);
                }
            }
            if (($st["p"][$st["i"]][0] ?? "") !== ")") {
                throw new ErroFormula(t("falta fechar os argumentos de {1} {2} (os argumentos se separam com ponto e vírgula)", [1 => $nome, 2 => isset($st["p"][$st["i"]]) ? t("na posição {1}", [1 => $st["p"][$st["i"]][2] + 1]) : t("no fim")]));
            }
            $st["i"]++;
            $d = $de_total ? $GLOBALS["FUNCOES_TOTAIS"][$nome] : $FUNCOES[$nome];
            if (count($args) < $d[0] || ($d[1] !== null && count($args) > $d[1])) {
                throw new ErroFormula(t("{1} recebe {2} argumento(s), e recebeu {3} {4}", [1 => $nome, 2 => $d[1] === null ? t("pelo menos {1}", [1 => $d[0]]) : ($d[0] === $d[1] ? $d[0] : t("de {1} a {2}", [1 => $d[0], 2 => $d[1]])), 3 => count($args), 4 => $onde]));
            }
            $res = ["f", $nome, $args];
        } elseif ($t[0] === "id") {
            $res = ["v", $t[1]];
        } else {
            throw new ErroFormula($t[0] === "fim" ? t("a fórmula acabou antes do esperado") : t("\"{1}\" fora do lugar {2}", [1 => $t[1], 2 => $onde]));
        }
    }
    return $res;
}

// As variáveis e os tipos de lançamento que uma fórmula usa: ["variaveis" => [...], "lancamentos" => [...]]
function formula_referencias($arvore)
{
    global $FUNCOES;
    $res = ["variaveis" => [], "lancamentos" => []];
    $pilha = [$arvore];
    while (count($pilha) > 0) {
        $no = array_pop($pilha);
        if ($no[0] === "v") {
            $res["variaveis"][$no[1]] = true;
        } elseif ($no[0] === "f") {
            foreach ($no[2] as $k => $a) {
                // nas funções do histórico, o 1º argumento (e, no HORAS_APOS e no ACUMULA, os outros textos) é um tipo de lançamento;
                // no MEDIA_COLECAO, o argumento é uma variável
                if ($a[0] === "t" && $no[1] === "MEDIA_COLECAO") {
                    $res["variaveis"][$a[1]] = true;
                } elseif ($a[0] === "t" && ($FUNCOES[$no[1]][3] ?? false)) {
                    $res["lancamentos"][$a[1]] = true;
                }
                $pilha[] = $a;
            }
        } elseif ($no[0] === "op") {
            $pilha[] = $no[2];
            $pilha[] = $no[3];
        } elseif ($no[0] === "neg") {
            $pilha[] = $no[1];
        }
    }
    return ["variaveis" => array_keys($res["variaveis"]), "lancamentos" => array_keys($res["lancamentos"])];
}

// Todas as fórmulas: [identificador => [linhas, uma por ponto da árvore em que foi definida]]
function formulas_todas($recarregar = false)
{
    // guardado por coleção (o cron passa por todas)
    static $todos_ = [];
    $k_ = (int)colecao_atual();
    $f = $todos_[$k_] ?? null;
    if ($f === null || $recarregar) {
        $f = [];
        foreach (linhas("SELECT * FROM formula ORDER BY identificador, id") as $x) {
            $f[$x["identificador"]][] = $x;
        }
    }
    $todos_[$k_] = $f;
    return $f;
}

// De várias versões de uma mesma coisa (fórmula ou aviso), cada uma num ponto da árvore (no_id; vazio: todos), a que vale
// para um relógio: a do ponto mais perto dele (a do ponto dele; senão a do de cima; ...; senão a de todos). null se
// nenhuma vale para ele.
function mais_perto($versoes, $r)
{
    $cadeia = no_cadeia($r["no_id"] ?? 0);
    $res = null;
    $melhor = -1;
    foreach ($versoes as $v) {
        $nivel = $v["no_id"] === null ? 0 : array_search((int)$v["no_id"], $cadeia, true);
        if ($nivel !== false) {
            $nivel = $v["no_id"] === null ? 0 : $nivel + 1;
            if ($nivel > $melhor) {
                $melhor = $nivel;
                $res = $v;
            }
        }
    }
    return $res;
}

// Como calcular o gasto da bateria: os cinco motores, e de onde vem a escolha (coleção › relógio › grupo › padrão):
//   horas     1. o motor original: cada medição pesa pelas horas no pulso e fora que o registro do pulso deu (gasto_medido_horas)
//   robusto   2. a conta nas leituras de cada trecho entre recargas, todas com o mesmo peso, a que não bate pesando menos,
//                partindo do cadastro, com incerteza (gasto_medido_desde), pelas horas registradas
//   marcacao  3. a mesma conta, mas a medição só entra se as duas leituras dela têm a mesma marcação (em uso ou em
//                repouso): o gasto em uso só das leituras em uso, o em repouso só das em repouso, nunca cruzadas
//   cadastro  4. só o decaimento e a autonomia do cadastro; as medições ficam no histórico (MEDIDO dá vazio)
//   formulas  5. o que as fórmulas taxa_uso e taxa_repouso derem, com as funções do motor (LEITURAS, QUEDA, DIAS_MEDIDOS,
//                MEDIA_TAXA, MEDIANA_TAXA, ULTIMA_TAXA) para a pessoa montar a conta dela; nelas, MEDIDO é a conta 3
// A escolha: a Configuração da coleção (gasto_motor; vazio: conforme o relógio e o grupo), senão o campo do cadastro
// gasto_motor no relógio, senão o padrão (padrao) da versão desse campo mais perto do relógio na árvore (o grupo), senão
// "horas". Devolve [o motor, de onde veio: colecao, relogio, grupo ou padrao].
const GASTO_MOTORES = ["horas", "robusto", "marcacao", "cadastro", "formulas"];
function gasto_motor_de($r)
{
    $c = (string)cfg("gasto_motor");
    if (in_array($c, GASTO_MOTORES, true)) {
        return [$c, "colecao"];
    }
    $v = strtolower(trim((string)(valores_do_relogio((int)($r["id"] ?? 0))["gasto_motor"] ?? "")));
    if (in_array($v, GASTO_MOTORES, true)) {
        return [$v, "relogio"];
    }
    static $versoes_ = [];
    $k_ = (int)colecao_atual();
    if (!isset($versoes_[$k_])) {
        $versoes_[$k_] = linhas("SELECT no_id, padrao FROM campo WHERE identificador = 'gasto_motor'");
    }
    $p = mais_perto($versoes_[$k_], $r);
    $g = $p === null ? "" : strtolower(trim((string)$p["padrao"]));
    return in_array($g, GASTO_MOTORES, true) ? [$g, "grupo"] : ["horas", "padrao"];
}

// O nome de cada motor na tela
function gasto_motor_nome($motor)
{
    $n = ["horas" => t("Pelas horas registradas (o motor original)"), "robusto" => t("Pelas horas registradas, com leituras e cadastro (robusto)"),
        "marcacao" => t("Pela marcação: duas taxas separadas"), "cadastro" => t("Só o cadastro"), "formulas" => t("Pelas fórmulas do cadastro")];
    return $n[$motor] ?? $motor;
}

function gasto_medido_horas($medicoes, $momento)
{
    $h_dia = (strtotime("2000-01-01 " . cfg("uso_fim")) - strtotime("2000-01-01 " . cfg("uso_inicio"))) / 3600;
    $h_dia = $h_dia > 0 ? $h_dia : 24;
    $n = 0;
    $usadas = [];
    $saa = $sbb = $sab = $saq = $sbq = 0.0;
    $media = ["uso" => [0.0, 0.0], "repouso" => [0.0, 0.0]];
    foreach ($medicoes as $m) {
        $fim = strtotime($m["fim"]);
        if ($fim > $momento) {
            break;
        }
        $n++;
        $usadas[] = (int)$m["id"];
        $hp = (float)$m["horas_pulso"];
        $hg = (float)$m["horas_guardado"];
        $w = $hp + $hg;
        $a = $hp / $h_dia;
        $b = $hg / 24;
        $q = (float)$m["de_valor"] - (float)$m["ate_valor"];
        $saa += $w * $a * $a;
        $sbb += $w * $b * $b;
        $sab += $w * $a * $b;
        $saq += $w * $a * $q;
        $sbq += $w * $b * $q;
        $media[$m["medida"]][0] += (float)$m["taxa"] * (float)$m["peso_horas"];
        $media[$m["medida"]][1] += (float)$m["peso_horas"];
    }
    // todas as medições da janela entram, cada uma pesando as horas que cobre (não há leitura que pese menos)
    $res = ["uso" => null, "repouso" => null, "uso_dp" => null, "repouso_dp" => null, "n" => $n, "conjunta" => false, "usadas" => $usadas, "peso" => [], "fora" => [], "erro" => [], "leitura" => []];
    if ($n === 0) {
        return $res;
    }
    $det = $saa * $sbb - $sab * $sab;
    // separa bem quando os intervalos têm proporções diferentes de pulso e de fora (1 - a correlação² entre elas)
    if ($saa > 0 && $sbb > 0 && $det / ($saa * $sbb) >= 0.02) {
        $u = ($saq * $sbb - $sbq * $sab) / $det;
        $r = ($sbq * $saa - $saq * $sab) / $det;
        if ($r < 0) {
            $r = 0.0;
            $u = $saq / $saa;
        } elseif ($u < 0) {
            $u = 0.0;
            $r = $sbq / $sbb;
        }
        return array_merge($res, ["uso" => round(max(0, $u), 3), "repouso" => round(max(0, $r), 3), "conjunta" => true]);
    }
    foreach (["uso", "repouso"] as $medida) {
        if ($media[$medida][1] > 0) {
            $res[$medida] = round($media[$medida][0] / $media[$medida][1], 3);
        }
    }
    return $res;
}

// O gasto medido pelas leituras de carga, num instante, pelo motor do relógio ($motor: horas, robusto, marcacao ou
// formulas; cadastro: vazio), com as medições da janela da Configuração (nenhuma nela: todas as de antes). $prior: o gasto
// do cadastro (["uso" => %, "repouso" => %], do gasto_prior), que a conta robusta junta com as medições.
function gasto_medido($medicoes, $momento, $prior = null, $motor = "robusto")
{
    $vazio = ["uso" => null, "repouso" => null, "uso_dp" => null, "repouso_dp" => null, "n" => 0, "conjunta" => false, "usadas" => [], "peso" => [], "fora" => [], "erro" => [], "leitura" => []];
    if ($motor === "cadastro") {
        return $vazio;
    }
    $janela = medicoes_janela($medicoes, $momento, 0);
    $ms = count($janela) > 0 ? $janela : $medicoes;
    if ($motor === "horas") {
        return gasto_medido_horas($ms, $momento);
    }
    return gasto_medido_desde($ms, $momento, null, $prior);
}

// As medições (em ordem de fim) que entram na conta num instante, pelo "contar por" da Configuração (medicao_base). $qual:
// 0, a janela de agora; 1, a anterior (para comparar: a bateria envelhecendo). Por dias: as que terminam nos últimos
// medicao_janela_dias (a anterior: os mesmos dias antes desses). Por leituras: as últimas medicao_janela_leituras de cada
// tipo, em uso e em repouso (a anterior: as tantas antes dessas), para muitas de um tipo não empurrarem as do outro.
function medicoes_janela($medicoes, $momento, $qual = 0)
{
    $ate = array_values(array_filter($medicoes, function ($m) use ($momento) { return strtotime($m["fim"]) <= $momento; }));
    if (cfg("medicao_base") === "leituras") {
        $n = (int)cfg("medicao_janela_leituras");
        $n = $n >= 2 ? $n : 20;
        $por = ["uso" => [], "repouso" => []];
        foreach ($ate as $k => $m) {
            $por[$m["medida"] === "uso" ? "uso" : "repouso"][] = $k;
        }
        $fica = [];
        foreach ($por as $ks) {
            $ini = max(0, count($ks) - ($qual + 1) * $n);
            $fim = max(0, count($ks) - $qual * $n);
            $fica = array_merge($fica, array_slice($ks, $ini, $fim - $ini));
        }
        sort($fica);
        return array_map(function ($k) use ($ate) { return $ate[$k]; }, $fica);
    }
    $j = max(1, (int)cfg("medicao_janela_dias")) * 86400;
    return array_values(array_filter($ate, function ($m) use ($momento, $j, $qual) {
        $f = strtotime($m["fim"]);
        return $f > $momento - ($qual + 1) * $j && $f <= $momento - $qual * $j;
    }));
}

// O texto da janela do gasto medido (para as mensagens e as telas): "dos últimos 90 dias" ou "das últimas 20 leituras de cada tipo"
function medicoes_janela_texto()
{
    if (cfg("medicao_base") === "leituras") {
        $n = (int)cfg("medicao_janela_leituras");
        return t("das últimas {1} leituras de cada tipo", [1 => $n >= 2 ? $n : 20]);
    }
    return t("dos últimos {1} dias", [1 => max(1, (int)cfg("medicao_janela_dias"))]);
}

// O gasto do cadastro de um relógio (as fórmulas taxa_uso e taxa_repouso sem as medições): o ponto de partida da conta
const GASTO_LEITURA_ERRO = 0.5;
function gasto_prior($r, $momento)
{
    $p = [];
    foreach (["uso" => "taxa_uso", "repouso" => "taxa_repouso"] as $medida => $ident) {
        $ctx = ["r" => $r, "momento" => $momento, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"]), "sem_medido" => true];
        $v = isset(formulas_todas()[$ident]) ? variavel_valor($ident, $ctx) : null;
        $p[$medida] = is_numeric($v) ? (float)$v : null;
    }
    return $p;
}

// A conta do gasto medido, com as medições que terminam depois de $desde (null: todas) e até $momento.
// O modelo: entre duas recargas, a carga cai em linha reta com o tempo de uso e o tempo guardado:
//     leitura = carga do começo − gasto em uso × dias de uso − gasto guardado × dias guardado + erro da leitura
// Medições seguidas (o fim de uma é o começo da outra) formam um trecho entre recargas; cada trecho tem a carga do começo
// dele (desconhecida), e os dois gastos valem para todos. A conta é feita nas LEITURAS, e não nas quedas: uma leitura
// errada estraga duas quedas seguidas, mas é um ponto só aqui. Três cuidados, cada um com o seu motivo:
// 1. Todas as leituras pesam igual (mínimos quadrados): o erro de uma leitura é o do mostrador (números inteiros) e o do
//    medidor do relógio, o mesmo numa leitura de 2 horas depois ou de 2 semanas depois.
// 2. Regressão robusta (Huber, por mínimos quadrados repesados): a leitura que não bate com as outras pesa menos, e
//    tanto menos quanto mais longe ela fica, em vez de ser cortada. O tamanho do erro normal sai das próprias leituras
//    (a mediana dos desvios), nunca abaixo de GASTO_LEITURA_ERRO ponto. Ex.: o 100% logo depois de carregar, que o relógio
//    segura um tempo enquanto a carga já cai, pesa pouco.
// 3. O cadastro entra como ponto de partida (média a priori), com uma folga de metade do valor (no mínimo 2% por dia de
//    uso e 0,5% por dia guardado): com poucas leituras, o gasto fica perto do cadastro; com muitas, as leituras mandam.
//    Sem leituras que separem o uso do guardado (só leituras guardado, por exemplo), o gasto que elas não medem fica o
//    do cadastro.
// Resposta: uso e repouso (% por dia de uso e por dia guardado), uso_dp e repouso_dp (a incerteza: um desvio padrão),
// n (as medições na conta), conjunta (as leituras separam os dois), usadas (os ids), e de cada medição a leitura dela
// que menos bate com as outras (a do começo ou a do fim): peso (id => 0 a 1), erro (id => o quanto a conta erra essa
// leitura, em pontos) e leitura (id => o valor dela); fora: as medições com uma leitura que pesa menos da metade.
function gasto_medido_desde($medicoes, $momento, $desde, $prior = null)
{
    $h_dia = (strtotime("2000-01-01 " . cfg("uso_fim")) - strtotime("2000-01-01 " . cfg("uso_inicio"))) / 3600;
    $h_dia = $h_dia > 0 ? $h_dia : 24;
    // os trechos: cada ponto é uma leitura, com o tempo de uso (u) e guardado (g) acumulado desde o começo do trecho
    $trechos = [];
    $ids = [];
    $ultimo = null;
    foreach ($medicoes as $k => $m) {
        $fim = strtotime($m["fim"]);
        if ($fim > $momento) {
            break;
        }
        if ($desde !== null && $fim <= $desde) {
            continue;
        }
        $id = isset($m["id"]) ? (int)$m["id"] : $k + 1;
        $ids[] = $id;
        $de = (float)$m["de_valor"];
        $segue = $ultimo !== null && isset($m["inicio"]) && $m["inicio"] === $ultimo["fim"] && abs($de - $ultimo["valor"]) < 0.001;
        if (!$segue) {
            $trechos[] = [["r" => $de, "u" => 0.0, "g" => 0.0, "fecha" => null, "abre" => null]];
        }
        $t = count($trechos) - 1;
        $n_t = count($trechos[$t]) - 1;
        $antes = $trechos[$t][$n_t];
        $trechos[$t][$n_t]["abre"] = $id;
        $trechos[$t][] = ["r" => (float)$m["ate_valor"], "u" => $antes["u"] + (float)$m["horas_pulso"] / $h_dia,
            "g" => $antes["g"] + (float)$m["horas_guardado"] / 24, "fecha" => $id, "abre" => null];
        $ultimo = ["fim" => $m["fim"], "valor" => (float)$m["ate_valor"]];
    }
    $res = ["uso" => null, "repouso" => null, "uso_dp" => null, "repouso_dp" => null, "n" => count($ids), "conjunta" => false,
        "usadas" => $ids, "peso" => [], "fora" => [], "erro" => [], "leitura" => []];
    if (count($ids) === 0) {
        return $res;
    }
    // a priori: o cadastro, com a folga (sem cadastro, nenhuma: as leituras sozinhas)
    $m0 = [(float)($prior["uso"] ?? 0), (float)($prior["repouso"] ?? 0)];
    $folga = [isset($prior["uso"]) ? max(0.5 * $prior["uso"], 2.0) : null, isset($prior["repouso"]) ? max(0.5 * $prior["repouso"], 0.5) : null];
    $w = [];
    foreach ($trechos as $t => $pts) {
        foreach ($pts as $i => $p) {
            $w[$t][$i] = 1.0;
        }
    }
    $sigma = 1.0;
    $beta = [0.0, 0.0];
    $cov = [[0.0, 0.0], [0.0, 0.0]];
    $separa = false;
    for ($volta = 0; $volta < 40; $volta++) {
        // cada trecho em torno da média dele (a carga do começo some da conta), com os pesos de agora
        $a = [[0.0, 0.0], [0.0, 0.0]];
        $b = [0.0, 0.0];
        foreach ($trechos as $t => $pts) {
            $sw = $su = $sg = $sr = 0.0;
            foreach ($pts as $i => $p) {
                $sw += $w[$t][$i];
                $su += $w[$t][$i] * $p["u"];
                $sg += $w[$t][$i] * $p["g"];
                $sr += $w[$t][$i] * $p["r"];
            }
            foreach ($pts as $i => $p) {
                $x = [-($p["u"] - $su / $sw), -($p["g"] - $sg / $sw)];
                $y = $p["r"] - $sr / $sw;
                for ($j = 0; $j < 2; $j++) {
                    $b[$j] += $w[$t][$i] * $x[$j] * $y;
                    for ($l = 0; $l < 2; $l++) {
                        $a[$j][$l] += $w[$t][$i] * $x[$j] * $x[$l];
                    }
                }
            }
        }
        $separa = $a[0][0] > 0 && $a[1][1] > 0 && ($a[0][0] * $a[1][1] - $a[0][1] * $a[1][0]) / ($a[0][0] * $a[1][1]) >= 0.02;
        // a conta com o a priori: (XᵀWX/σ² + Λ) β = XᵀWy/σ² + Λ β0
        $s2 = $sigma * $sigma;
        $lam = [$folga[0] !== null ? 1 / ($folga[0] * $folga[0]) : 0.0, $folga[1] !== null ? 1 / ($folga[1] * $folga[1]) : 0.0];
        $m = [[$a[0][0] / $s2 + $lam[0], $a[0][1] / $s2], [$a[1][0] / $s2, $a[1][1] / $s2 + $lam[1]]];
        $v = [$b[0] / $s2 + $lam[0] * $m0[0], $b[1] / $s2 + $lam[1] * $m0[1]];
        $det = $m[0][0] * $m[1][1] - $m[0][1] * $m[1][0];
        if (abs($det) < 1e-12) {
            // sem cadastro e sem leituras que separem os dois: o que dá para medir, cada um sozinho
            $beta = [$m[0][0] > 0 ? $v[0] / $m[0][0] : null, $m[1][1] > 0 ? $v[1] / $m[1][1] : null];
            $cov = [[$m[0][0] > 0 ? 1 / $m[0][0] : 0.0, 0.0], [0.0, $m[1][1] > 0 ? 1 / $m[1][1] : 0.0]];
        } else {
            $beta = [($v[0] * $m[1][1] - $v[1] * $m[0][1]) / $det, ($m[0][0] * $v[1] - $m[1][0] * $v[0]) / $det];
            $cov = [[$m[1][1] / $det, -$m[0][1] / $det], [-$m[1][0] / $det, $m[0][0] / $det]];
            // nunca negativo: o que sair abaixo de zero fica zero, e o outro se refaz sozinho
            if ($beta[0] < 0 || $beta[1] < 0) {
                $j = $beta[0] < 0 ? 0 : 1;
                $o = 1 - $j;
                $beta[$j] = 0.0;
                $beta[$o] = $m[$o][$o] > 0 ? max(0, $v[$o] / $m[$o][$o]) : 0.0;
            }
        }
        // os desvios de cada leitura e os pesos novos (Huber: 1 até 1,345 σ, depois σ·1,345/|desvio|)
        $desv = [];
        $abs = [];
        foreach ($trechos as $t => $pts) {
            $sw = $sc = 0.0;
            foreach ($pts as $i => $p) {
                $sw += $w[$t][$i];
                $sc += $w[$t][$i] * ($p["r"] + (float)$beta[0] * $p["u"] + (float)$beta[1] * $p["g"]);
            }
            foreach ($pts as $i => $p) {
                $desv[$t][$i] = $p["r"] - ($sc / $sw - (float)$beta[0] * $p["u"] - (float)$beta[1] * $p["g"]);
                if (count($pts) > 1) {
                    $abs[] = abs($desv[$t][$i]);
                }
            }
        }
        sort($abs);
        $sigma = max(GASTO_LEITURA_ERRO, count($abs) > 0 ? 1.4826 * $abs[intdiv(count($abs), 2)] : GASTO_LEITURA_ERRO);
        $mudou = 0.0;
        foreach ($trechos as $t => $pts) {
            foreach ($pts as $i => $p) {
                $novo = abs($desv[$t][$i]) > 1.345 * $sigma ? 1.345 * $sigma / abs($desv[$t][$i]) : 1.0;
                $mudou = max($mudou, abs($novo - $w[$t][$i]));
                $w[$t][$i] = $novo;
            }
        }
        if ($mudou < 1e-4) {
            break;
        }
    }
    // cada medição tem duas leituras (a do começo e a do fim): vale a que pesa menos, a que menos bate com as outras
    foreach ($trechos as $t => $pts) {
        foreach ($pts as $i => $p) {
            foreach ([$p["fecha"], $p["abre"]] as $mid) {
                if ($mid !== null && (!isset($res["peso"][$mid]) || $w[$t][$i] < $res["peso"][$mid])) {
                    $res["peso"][$mid] = round($w[$t][$i], 2);
                    $res["erro"][$mid] = round($desv[$t][$i], 1);
                    $res["leitura"][$mid] = $p["r"];
                }
            }
        }
    }
    foreach ($res["peso"] as $mid => $peso) {
        if ($peso < 0.5) {
            $res["fora"][] = $mid;
        }
    }
    $res["conjunta"] = $separa;
    // o gasto que as leituras não medem (nenhum tempo no pulso, ou nenhum guardado, nas medições: no motor da marcação, nenhuma
    // medição desse tipo) fica vazio, e não o cadastro repetido como se fosse medido: vale o cadastro, dito como cadastro
    $mede = [$a[0][0] > 1e-9, $a[1][1] > 1e-9];
    $res["uso"] = $beta[0] === null || !$mede[0] ? null : round($beta[0], 3);
    $res["repouso"] = $beta[1] === null || !$mede[1] ? null : round($beta[1], 3);
    $res["uso_dp"] = $res["uso"] === null ? null : round(sqrt(max(0, $cov[0][0])), 3);
    $res["repouso_dp"] = $res["repouso"] === null ? null : round(sqrt(max(0, $cov[1][1])), 3);
    return $res;
}

// Calcula uma árvore de operações para um relógio. $ctx: ["r" => relógio, "momento" => segundos, "rastro" => lista das
// partes da conta (o nome e o valor de cada variável e de cada função do histórico), "pilha" => fórmulas em cálculo
// (para não entrar em círculo)]. Devolve número, texto ou null (vazio).
function formula_calcular($no, &$ctx)
{
    global $FUNCOES;
    // os lançamentos lidos de cada relógio e as médias da coleção de cada instante, guardados para a requisição inteira;
    // quando algo é gravado no meio da requisição (a escala refeita depois de um lançamento), FORMULAS_VERSAO muda e o
    // guardado sai. As médias também dependem da simulação da escala (SIMULACAO_VERSAO, na chave).
    static $cache_l = [];
    static $cache_m = [];
    static $cache_med = [];
    static $cache_pecas = [];
    static $cache_precisao = [];
    static $versao = 0;
    if ($versao !== ($GLOBALS["FORMULAS_VERSAO"] ?? 0)) {
        $cache_l = [];
        $cache_m = [];
        $cache_med = [];
        $versao = $GLOBALS["FORMULAS_VERSAO"] ?? 0;
    }
    $res = null;
    if ($no[0] === "n" || $no[0] === "t") {
        $res = $no[1];
    } elseif ($no[0] === "neg") {
        $a = formula_calcular($no[1], $ctx);
        $res = is_numeric($a) ? -$a : null;
    } elseif ($no[0] === "v") {
        $res = variavel_valor($no[1], $ctx);
        $ctx["rastro"][$no[1]] = $res;
    } elseif ($no[0] === "op") {
        $a = formula_calcular($no[2], $ctx);
        $b = formula_calcular($no[3], $ctx);
        if ($a !== null && $b !== null) {
            if (in_array($no[1], ["=", "<>"], true)) {
                $igual = is_numeric($a) && is_numeric($b) ? abs((float)$a - (float)$b) < 1e-9 : (string)$a === (string)$b;
                $res = ($no[1] === "=") === $igual ? 1 : 0;
            } elseif (is_numeric($a) && is_numeric($b)) {
                $a = (float)$a;
                $b = (float)$b;
                if ($no[1] === "+") {
                    $res = $a + $b;
                } elseif ($no[1] === "-") {
                    $res = $a - $b;
                } elseif ($no[1] === "*") {
                    $res = $a * $b;
                } elseif ($no[1] === "/") {
                    $res = abs($b) < 1e-12 ? null : $a / $b;
                } elseif ($no[1] === "^") {
                    $res = ($a < 0 && floor($b) != $b) ? null : pow($a, $b);
                } elseif ($no[1] === "<") {
                    $res = $a < $b ? 1 : 0;
                } elseif ($no[1] === "<=") {
                    $res = $a <= $b ? 1 : 0;
                } elseif ($no[1] === ">") {
                    $res = $a > $b ? 1 : 0;
                } elseif ($no[1] === ">=") {
                    $res = $a >= $b ? 1 : 0;
                }
            }
        }
    } elseif ($no[0] === "f") {
        $nome = $no[1];
        $args = $no[2];
        // SE calcula só o lado escolhido; as outras calculam todos os argumentos
        if ($nome === "SE") {
            $c = formula_calcular($args[0], $ctx);
            $res = $c === null ? null : formula_calcular(((float)$c != 0) ? $args[1] : $args[2], $ctx);
        } elseif ($nome === "ESCOLHA") {
            // o n-ésimo dos valores, e só ele é calculado (o resto pode nem fazer sentido naquele dia)
            $n = formula_calcular($args[0], $ctx);
            $k = is_numeric($n) ? (int)floor((float)$n) : 0;
            $res = $k >= 1 && isset($args[$k]) ? formula_calcular($args[$k], $ctx) : null;
        } elseif (in_array($nome, ["MARCHA", "MARCHA_DP", "MARCHA_N", "MARCHA_ULTIMA", "DESVIO_AGORA"], true)) {
            // a precisão: os pontos do relógio (lidos uma vez por requisição, de novo depois de uma gravação) até o instante da conta
            $v = array_map(function ($a) use (&$ctx) { return formula_calcular($a, $ctx); }, $args);
            $id = (int)$ctx["r"]["id"];
            $chave_p = $id . "/" . ($GLOBALS["FORMULAS_VERSAO"] ?? 0);
            if (!isset($cache_precisao[$chave_p])) {
                $cache_precisao[$chave_p] = precisao_pontos($id);
            }
            $momento_p = $ctx["momento"];
            $pontos = array_values(array_filter($cache_precisao[$chave_p], function ($p) use ($momento_p) { return $p["instante"] <= $momento_p; }));
            $ints = precisao_intervalos($pontos);
            if ($nome === "DESVIO_AGORA") {
                $res = precisao_desvio_em($pontos, $ints, $momento_p);
            } elseif ($nome === "MARCHA_ULTIMA") {
                $res = count($ints) > 0 ? $ints[count($ints) - 1]["marcha"] : null;
            } else {
                $mm = precisao_marcha($ints, isset($v[0]) && is_numeric($v[0]) ? (float)$v[0] : 0.0, isset($v[1]) ? (string)$v[1] : "", $momento_p);
                $res = $nome === "MARCHA_N" ? (float)$mm["n"] : ($nome === "MARCHA" ? $mm["marcha"] : $mm["dp"]);
            }
        } elseif (in_array($nome, ["LEITURAS", "QUEDA", "DIAS_MEDIDOS", "MEDIA_TAXA", "MEDIANA_TAXA", "ULTIMA_TAXA"], true)) {
            // as peças do gasto medido pela marcação: as medições do relógio (lidas uma vez por requisição) em que as duas
            // leituras foram marcadas como pedido, até o instante da conta e dentro de N dias (0: a janela da Configuração)
            $v = array_map(function ($a) use (&$ctx) { return formula_calcular($a, $ctx); }, $args);
            $id = (int)$ctx["r"]["id"];
            if (!isset($cache_pecas[$id])) {
                $cache_pecas[$id] = medicoes_da_conta($id, true)[0];
            }
            $medida = $v[0] === "uso" ? "uso" : ($v[0] === "repouso" ? "repouso" : null);
            $dias = isset($v[1]) && is_numeric($v[1]) ? (float)$v[1] : 0.0;
            $h_dia_p = (strtotime("2000-01-01 " . cfg("uso_fim")) - strtotime("2000-01-01 " . cfg("uso_inicio"))) / 3600;
            $h_dia_p = $h_dia_p > 0 ? $h_dia_p : 24;
            $sel = [];
            if ($medida !== null) {
                $todas = $dias > 0 ? array_values(array_filter($cache_pecas[$id], function ($m) use ($ctx, $dias) {
                    $f = strtotime($m["fim"]);
                    return $f <= $ctx["momento"] && $f > $ctx["momento"] - $dias * 86400;
                })) : medicoes_janela($cache_pecas[$id], $ctx["momento"], 0);
                $sel = array_values(array_filter($todas, function ($m) use ($medida) { return $m["medida"] === $medida; }));
            }
            $taxas = array_map(function ($m) use ($h_dia_p) {
                $d = $m["medida"] === "uso" ? (float)$m["horas_pulso"] / $h_dia_p : (float)$m["horas_guardado"] / 24;
                return $d > 0 ? ((float)$m["de_valor"] - (float)$m["ate_valor"]) / $d : null;
            }, $sel);
            $taxas = array_values(array_filter($taxas, function ($x) { return $x !== null; }));
            if ($nome === "LEITURAS") {
                $res = (float)count($sel);
            } elseif ($nome === "QUEDA") {
                $res = array_sum(array_map(function ($m) { return (float)$m["de_valor"] - (float)$m["ate_valor"]; }, $sel));
            } elseif ($nome === "DIAS_MEDIDOS") {
                $res = array_sum(array_map(function ($m) use ($h_dia_p) { return $m["medida"] === "uso" ? (float)$m["horas_pulso"] / $h_dia_p : (float)$m["horas_guardado"] / 24; }, $sel));
            } elseif ($nome === "MEDIA_TAXA") {
                $res = count($taxas) > 0 ? array_sum($taxas) / count($taxas) : null;
            } elseif ($nome === "MEDIANA_TAXA") {
                sort($taxas);
                $n_t = count($taxas);
                $res = $n_t === 0 ? null : ($n_t % 2 === 1 ? $taxas[intdiv($n_t, 2)] : ($taxas[$n_t / 2 - 1] + $taxas[$n_t / 2]) / 2);
            } else {
                $res = count($taxas) > 0 ? $taxas[count($taxas) - 1] : null;
            }
        } elseif ($FUNCOES[$nome][3]) {
            // as funções do histórico: os lançamentos do relógio até o instante, por tipo (lidos uma vez por relógio e
            // requisição). Sessão aberta vale até o instante; se o tipo tem "fecha sozinho às", no máximo até essa hora do
            // dia em que começou (a sessão esquecida aberta não vira dias de uso).
            $ate = (int)$ctx["momento"];
            $id = (int)$ctx["r"]["id"];
            if (!isset($cache_l[$id])) {
                $cache_l[$id] = [];
                foreach (linhas("SELECT l.*, t.identificador AS tipo, t.formato, t.fecha_as FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? ORDER BY l.inicio, l.id", [$id]) as $l) {
                    $ini = strtotime($l["inicio"]);
                    $limite = null;
                    if ($l["fim"] === null && $l["fecha_as"] !== null) {
                        $limite = max($ini, instante(dia_da_colecao(strtotime($l["inicio"])), $l["fecha_as"]));
                    }
                    $cache_l[$id][$l["tipo"]][] = ["ini" => $ini, "fim" => $l["fim"] === null ? $limite : strtotime($l["fim"]),
                        "valor" => $l["valor"] === null ? null : (float)$l["valor"], "formato" => $l["formato"]];
                }
            }
            // os gravados, e depois deles os simulados pela escala e pela previsão (SIMULACAO: [relógio => [tipo => lançamentos]],
            // no mesmo formato e em ordem, todos depois de agora). O simulado "só uso" (o do sorteio: não se sabe se o relógio vai
            // ser carregado) conta para as funções de uso, mas não entra nas contas de saldo da carga (HORAS_APOS e ACUMULA)
            $saldo = $nome === "HORAS_APOS" || $nome === "ACUMULA";
            $ls = [];
            foreach ([$cache_l[$id], $GLOBALS["SIMULACAO"][$id] ?? []] as $fonte) {
                foreach ($fonte as $tipo => $do_cache) {
                    foreach ($do_cache as $l) {
                        if ($l["ini"] <= $ate && !($saldo && !empty($l["so_uso"]))) {
                            $l["fim_ate"] = $l["formato"] === "sessao" ? min($l["fim"] === null ? $ate : $l["fim"], $ate) : $l["ini"];
                            $ls[$tipo][] = $l;
                        }
                    }
                }
            }
            $tipo = $args[0][0] === "t" ? $args[0][1] : "";
            $do_tipo = $ls[$tipo] ?? [];
            $ultimo = count($do_tipo) > 0 ? $do_tipo[count($do_tipo) - 1] : null;
            if ($nome === "ULTIMO_VALOR") {
                $res = $ultimo ? $ultimo["valor"] : null;
            } elseif ($nome === "ULTIMA_DATA") {
                $res = $ultimo ? dias_de($ultimo["fim_ate"]) : null;
            } elseif ($nome === "DIAS_DESDE_ULTIMO") {
                $res = $ultimo ? ($ate - $ultimo["fim_ate"]) / 86400 : null;
            } elseif ($nome === "EM_SESSAO") {
                $res = $ultimo && $ultimo["formato"] === "sessao" && ($ultimo["fim"] === null || $ultimo["fim"] > $ate) ? 1 : 0;
            } elseif ($nome === "HORAS" || $nome === "CONTAR") {
                $dias = formula_calcular($args[1], $ctx);
                if (is_numeric($dias)) {
                    $desde = $ate - (float)$dias * 86400;
                    $horas = 0.0;
                    $conta = 0;
                    $dias_com = [];
                    foreach ($do_tipo as $l) {
                        if ($l["fim_ate"] >= $desde) {
                            $conta++;
                            if ($l["formato"] === "sessao") {
                                $horas += max(0, $l["fim_ate"] - max($l["ini"], $desde)) / 3600;
                                for ($t = max($l["ini"], (int)$desde); $t <= $l["fim_ate"]; $t += 86400) {
                                    $dias_com[date("Y-m-d", $t)] = true;
                                }
                                $dias_com[date("Y-m-d", $l["fim_ate"])] = true;
                            }
                        }
                    }
                    $formato = $ultimo ? $ultimo["formato"] : (lancamento_tipos()[$tipo]["formato"] ?? "instantaneo");
                    $res = $nome === "HORAS" ? $horas : ($formato === "sessao" ? count($dias_com) : $conta);
                }
            } elseif ($nome === "HORAS_APOS") {
                $outro = $args[1][0] === "t" ? $args[1][1] : "";
                $ref = $ls[$outro] ?? [];
                if (count($ref) > 0) {
                    $desde = $ref[count($ref) - 1]["ini"];
                    $horas = 0.0;
                    foreach ($do_tipo as $l) {
                        $horas += max(0, $l["fim_ate"] - max($l["ini"], $desde)) / 3600;
                    }
                    $res = $horas;
                }
            } elseif ($nome === "ACUMULA") {
                // um saldo que percorre o histórico dos tipos da lista, do primeiro lançamento deles até o instante. Numa sessão da
                // lista, ganha o efeito dela por hora (duas ao mesmo tempo: vale a de maior ganho; um efeito negativo é uma perda);
                // num lançamento instantâneo da lista, o saldo passa a valer o efeito; num com valor, a leitura vezes o efeito;
                // fora de sessão, perde a perda por hora. Sempre entre 0 e o máximo. Sem
                // nenhum lançamento dos tipos da lista: vazio.
                $inicio = formula_calcular($args[0], $ctx);
                $maximo = formula_calcular($args[1], $ctx);
                $perda = formula_calcular($args[2], $ctx);
                if (is_numeric($inicio) && is_numeric($maximo) && is_numeric($perda) && count($args) >= 5 && count($args) % 2 === 1) {
                    $sessoes = [];
                    $marcas = [];
                    for ($k = 3; $k + 1 < count($args); $k += 2) {
                        $tipo = $args[$k][0] === "t" ? $args[$k][1] : "";
                        $efeito = formula_calcular($args[$k + 1], $ctx);
                        if (is_numeric($efeito)) {
                            foreach ($ls[$tipo] ?? [] as $l) {
                                if ($l["formato"] === "sessao") {
                                    $sessoes[] = [$l["ini"], $l["fim_ate"], (float)$efeito];
                                } else {
                                    // o lançamento com valor (uma leitura): o saldo passa a valer a leitura vezes o efeito (1: a
                                    // própria leitura); o instantâneo: o saldo passa a valer o efeito
                                    $marcas[] = [$l["ini"], $l["formato"] === "valor" ? (float)$l["valor"] * (float)$efeito : (float)$efeito];
                                }
                            }
                        }
                    }
                    if (count($sessoes) + count($marcas) > 0) {
                        // os instantes em que algo muda: início e fim de cada sessão, cada marca, e o instante do cálculo
                        $pontos = [$ate];
                        foreach ($sessoes as $s) {
                            $pontos[] = $s[0];
                            $pontos[] = $s[1];
                        }
                        foreach ($marcas as $m) {
                            $pontos[] = $m[0];
                        }
                        $pontos = array_values(array_unique(array_filter($pontos, function ($t) use ($ate) { return $t <= $ate; })));
                        sort($pontos);
                        // percorrido uma vez só: as marcas por instante (duas no mesmo instante: vale a última da lista) e as
                        // sessões por início, entrando nas ativas quando começam e saindo quando acabam
                        $marca_em = [];
                        foreach ($marcas as $m) {
                            $marca_em[$m[0]] = $m[1];
                        }
                        usort($sessoes, function ($x, $y) { return $x[0] <=> $y[0]; });
                        $proxima = 0;
                        $ativas = [];
                        $saldo = max(0, min((float)$maximo, (float)$inicio));
                        for ($i = 0; $i < count($pontos); $i++) {
                            if (isset($marca_em[$pontos[$i]])) {
                                $saldo = max(0, min((float)$maximo, $marca_em[$pontos[$i]]));
                            }
                            if ($i + 1 < count($pontos)) {
                                $a = $pontos[$i];
                                $b = $pontos[$i + 1];
                                $meio = ($a + $b) / 2;
                                while ($proxima < count($sessoes) && $sessoes[$proxima][0] <= $meio) {
                                    $ativas[] = $sessoes[$proxima];
                                    $proxima++;
                                }
                                $ganho = null;
                                $ficam = [];
                                foreach ($ativas as $s) {
                                    if ($meio < $s[1]) {
                                        $ficam[] = $s;
                                        $ganho = $ganho === null ? $s[2] : max($ganho, $s[2]);
                                    }
                                }
                                $ativas = $ficam;
                                $horas = ($b - $a) / 3600;
                                $saldo = $ganho === null ? $saldo - (float)$perda * $horas : $saldo + $ganho * $horas;
                                $saldo = max(0, min((float)$maximo, $saldo));
                            }
                        }
                        $res = $saldo;
                    }
                }
            }
            $ctx["rastro"][$nome . "(" . implode("; ", array_map(function ($a) { return $a[0] === "t" ? "\"" . $a[1] . "\"" : "…"; }, $args)) . ")"] = $res;
        } elseif ($nome === "MEDIA_COLECAO") {
            // a média da variável nos relógios disponíveis (os vazios não entram), calculada uma vez por instante; dentro
            // dela, outra MEDIA_COLECAO vale vazio (não há média de média)
            $ident = $args[0][0] === "t" ? $args[0][1] : "";
            $chave = $ident . "@" . (int)$ctx["momento"] . "@" . ($GLOBALS["SIMULACAO_VERSAO"] ?? 0);
            if (!isset($cache_m[$chave])) {
                $cache_m[$chave] = null;
                if (empty($ctx["na_media"])) {
                    $soma = 0.0;
                    $n = 0;
                    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1") as $r) {
                        $c = ["r" => $r, "momento" => $ctx["momento"], "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"]), "na_media" => true];
                        $v = variavel_valor($ident, $c);
                        if (is_numeric($v)) {
                            $soma += (float)$v;
                            $n++;
                        }
                    }
                    $cache_m[$chave] = $n > 0 ? $soma / $n : null;
                }
            }
            $res = $cache_m[$chave];
            $ctx["rastro"]["MEDIA_COLECAO(\"" . ($args[0][1] ?? "") . "\")"] = $res;
        } else {
            $v = array_map(function ($a) use (&$ctx) {
                return formula_calcular($a, $ctx);
            }, $args);
            $numeros = array_values(array_filter($v, function ($x) { return is_numeric($x); }));
            $agora_d = dias_de($ctx["momento"]);
            if ($nome === "MIN") {
                $res = count($numeros) > 0 ? min(array_map("floatval", $numeros)) : null;
            } elseif ($nome === "MAX") {
                $res = count($numeros) > 0 ? max(array_map("floatval", $numeros)) : null;
            } elseif ($nome === "E") {
                $res = in_array(null, $v, true) ? null : (count(array_filter($v, function ($x) { return (float)$x == 0; })) === 0 ? 1 : 0);
            } elseif ($nome === "OU") {
                $res = in_array(null, $v, true) ? null : (count(array_filter($v, function ($x) { return (float)$x != 0; })) > 0 ? 1 : 0);
            } elseif ($nome === "NAO") {
                $res = $v[0] === null ? null : ((float)$v[0] == 0 ? 1 : 0);
            } elseif ($nome === "LIMITA") {
                $res = count($numeros) === 3 ? max((float)$v[1], min((float)$v[2], (float)$v[0])) : null;
            } elseif ($nome === "ARREDONDA") {
                $res = is_numeric($v[0]) ? round((float)$v[0], isset($v[1]) && is_numeric($v[1]) ? (int)$v[1] : 0) : null;
            } elseif ($nome === "ABS") {
                $res = is_numeric($v[0]) ? abs((float)$v[0]) : null;
            } elseif ($nome === "PADRAO") {
                $res = $v[0] ?? $v[1];
            } elseif ($nome === "VAZIO") {
                $res = $v[0] === null ? 1 : 0;
            } elseif ($nome === "NADA") {
                $res = null;
            } elseif ($nome === "HOJE") {
                $res = floor($agora_d);
            } elseif ($nome === "AGORA") {
                $res = $agora_d;
            } elseif ($nome === "MEDIDO" && (!empty($ctx["sem_medido"]) || gasto_motor_de($ctx["r"])[0] === "cadastro")) {
                // a conta só com o cadastro: o que valeria sem as medições (a tabela dos gastos da previsão), ou o motor
                // "só o cadastro" (as medições ficam no histórico; a previsão usa o cadastro)
                $res = null;
            } elseif ($nome === "MEDIDO") {
                // as medições usadas do relógio, lidas uma vez por requisição; os gastos são os da janela que termina no
                // instante da conta (gasto_medido), guardados pelo trecho de medições que entrou (a escala pede o mesmo muitas vezes)
                $id = (int)$ctx["r"]["id"];
                if (!isset($cache_med[$id])) {
                    $cache_med[$id] = ["linhas" => medicoes_da_conta($id)[0], "contas" => [], "motor" => gasto_motor_de($ctx["r"])[0]];
                }
                $janela = max(1, (int)cfg("medicao_janela_dias")) * 86400;
                $ini = 0;
                $fim = 0;
                foreach ($cache_med[$id]["linhas"] as $k => $m) {
                    $t = strtotime($m["fim"]);
                    if ($t <= $ctx["momento"] - $janela && cfg("medicao_base") !== "leituras") {
                        $ini = $k + 1;
                    }
                    if ($t <= $ctx["momento"]) {
                        $fim = $k + 1;
                    }
                }
                $chave = $ini . "-" . $fim;
                if (!isset($cache_med[$id]["contas"][$chave])) {
                    $cache_med[$id]["prior"] = $cache_med[$id]["prior"] ?? gasto_prior($ctx["r"], $ctx["momento"]);
                    // no motor "pelas fórmulas", MEDIDO é a conta pela marcação (o 3): a fórmula parte dela se quiser
                    $cache_med[$id]["contas"][$chave] = gasto_medido($cache_med[$id]["linhas"], $ctx["momento"], $cache_med[$id]["prior"],
                        $cache_med[$id]["motor"] === "formulas" ? "marcacao" : $cache_med[$id]["motor"]);
                }
                $res = in_array($v[0], ["uso", "repouso"], true) ? $cache_med[$id]["contas"][$chave][$v[0]] : null;
            } elseif ($nome === "CONFIG") {
                $res = is_string($v[0]) && is_numeric(cfg($v[0])) ? (float)cfg($v[0]) : null;
            } elseif ($nome === "HORAS_USO") {
                $res = (strtotime("2000-01-01 " . cfg("uso_fim")) - strtotime("2000-01-01 " . cfg("uso_inicio"))) / 3600;
                $res = $res > 0 ? $res : null;
            } elseif ($nome === "DIA_SEMANA") {
                $res = (int)dia_da_conta($ctx)->format("N");
            } elseif ($nome === "SEMANA_DO_ANO") {
                $res = (int)dia_da_conta($ctx)->format("W");
            } elseif ($nome === "NO_GRUPO") {
                $no = no_por_nome($v[0]);
                $res = $no === 0 ? 1 : ($no === null ? 0 : (in_array($no, no_cadeia($ctx["r"]["no_id"] ?? 0), true) ? 1 : 0));
            } elseif ($nome === "RELOGIO") {
                $res = $v[0] === null ? 0 : ((is_numeric($v[0]) ? (int)$v[0] === (int)$ctx["r"]["id"] : mb_strtolower(trim((string)$v[0]), "UTF-8") === mb_strtolower((string)$ctx["r"]["nome"], "UTF-8")) ? 1 : 0);
            } elseif ($nome === "CALIBRE") {
                $cal = calibre_do_relogio((int)($ctx["r"]["id"] ?? 0));
                $res = $v[0] === null || $cal === null ? 0 : ((is_numeric($v[0]) ? (int)$v[0] === (int)$cal["id"] : mb_strtolower(trim((string)$v[0]), "UTF-8") === mb_strtolower((string)$cal["nome"], "UTF-8")) ? 1 : 0);
            } elseif ($nome === "ULTIMO_SERVICO" || $nome === "CUSTO_SERVICOS") {
                // os serviços do relógio até o instante da conta
                $do_r = array_values(array_filter(servicos_das_contas()[(int)($ctx["r"]["id"] ?? 0)] ?? [], function ($x) use ($ctx) { return $x["quando"] <= $ctx["momento"]; }));
                if ($nome === "ULTIMO_SERVICO") {
                    $tipo_s = isset($v[0]) && $v[0] !== null ? (string)$v[0] : "";
                    $res = null;
                    foreach ($do_r as $x) {
                        if ($tipo_s === "" || $x["tipo"] === $tipo_s || ($tipo_s === "estanqueidade" && $x["estanqueidade"] !== null)) {
                            $res = dias_de($x["quando"]);
                        }
                    }
                } else {
                    $dias_s = isset($v[0]) && is_numeric($v[0]) ? (float)$v[0] : 0.0;
                    $res = 0.0;
                    foreach ($do_r as $x) {
                        if ($x["custo"] !== null && ($dias_s <= 0 || $x["quando"] > $ctx["momento"] - $dias_s * 86400)) {
                            $res += $x["custo"];
                        }
                    }
                }
            } elseif ($nome === "RESERVA_MEDIDA" || $nome === "RESERVA_TESTES") {
                $m_r = reserva_medida((int)($ctx["r"]["id"] ?? 0), (int)$ctx["momento"]);
                $res = $nome === "RESERVA_TESTES" ? $m_r["n"] : $m_r["mediana"];
            } elseif ($nome === "ESCOLHIDO_HA") {
                $d = is_numeric($v[0]) ? dia_da_conta($ctx)->modify((-(int)$v[0]) . " days") : null;
                $res = $d === null ? null : ((int)(valor("SELECT relogio_id FROM plano WHERE data = ?", [$d->format("Y-m-d")]) ?? 0) === (int)$ctx["r"]["id"] ? 1 : 0);
            } elseif ($nome === "VEZES_NO_PLANO") {
                $d = dia_da_conta($ctx);
                $res = is_numeric($v[0]) && (int)$v[0] > 0 ? (int)valor("SELECT COUNT(*) FROM plano WHERE relogio_id = ? AND data BETWEEN ? AND ?",
                    [(int)$ctx["r"]["id"], $d->modify("-" . (int)$v[0] . " days")->format("Y-m-d"), $d->modify("-1 day")->format("Y-m-d")]) : null;
            } elseif ($nome === "SORTEIO") {
                $res = sorteio(0, 1000000) / 1000000;
            } elseif ($nome === "MES" || $nome === "ANO" || $nome === "MES_ANO") {
                // a data em dias (a meia-noite local é um inteiro): o dia do calendário dela
                $res = is_numeric($v[0]) ? gmdate($nome === "MES" ? "n" : ($nome === "ANO" ? "Y" : "Y-m"), (int)(floor((float)$v[0]) * 86400)) : null;
                $res = $res !== null && $nome !== "MES_ANO" ? (int)$res : $res;
            } elseif ($nome === "DIAS_DESDE") {
                $res = is_numeric($v[0]) ? $agora_d - (float)$v[0] : null;
            } elseif ($nome === "DIAS_ATE") {
                $res = is_numeric($v[0]) ? (float)$v[0] - $agora_d : null;
            } elseif ($nome === "SOMA_MESES") {
                if (is_numeric($v[0]) && is_numeric($v[1])) {
                    // a data (o dia inteiro) somada de meses pelo calendário: o dia fica limitado ao último dia do mês de
                    // destino (31/01 + 1 mês = 28/02 ou 29/02, não 03/03); a hora do dia, se houver, é mantida
                    $dia = floor((float)$v[0]);
                    $partes = explode("-", gmdate("Y-n-j", (int)($dia * 86400)));
                    $total = (int)$partes[0] * 12 + (int)$partes[1] - 1 + (int)$v[1];
                    $ano = intdiv($total, 12);
                    $mes = $total % 12 + 1;
                    $res = gmmktime(0, 0, 0, $mes, min((int)$partes[2], (int)gmdate("t", gmmktime(0, 0, 0, $mes, 1, $ano))), $ano) / 86400 + ((float)$v[0] - $dia);
                }
            }
        }
    }
    return $res;
}

// O valor de uma variável para o relógio: o campo (convertido para o tipo), ou a fórmula que vale para ele
function variavel_valor($ident, &$ctx)
{
    static $arvores = [];
    $res = null;
    $campos = campos_do_relogio($ctx["r"]);
    if (isset($ctx["locais"][$ident])) {
        // uma linha do roteiro do modo (nome = expressão): vale por cima de campo ou fórmula de mesmo nome; o círculo dá vazio
        if (!isset($ctx["pilha"]["local:" . $ident])) {
            $ctx["pilha"]["local:" . $ident] = true;
            $res = formula_calcular($ctx["locais"][$ident], $ctx);
            unset($ctx["pilha"]["local:" . $ident]);
        }
    } elseif (isset($ctx["tabela"]) && array_key_exists($ident, $ctx["tabela"])) {
        // nos totais: o que a coluna da tabela dos relógios mostra (codigo, nome, tipo, estado, carga...)
        $res = $ctx["tabela"][$ident];
    } elseif ($ident === "nota_criterios") {
        if (!isset($ctx["pilha"]["nota_criterios"])) {
            $ctx["pilha"]["nota_criterios"] = true;
            $res = nota_do_relogio($ctx["r"], $ctx["momento"])["nota"];
            unset($ctx["pilha"]["nota_criterios"]);
        }
    } elseif (isset($campos[$ident])) {
        $bruto = $ctx["valores"][$ident] ?? null;
        if ($bruto === null || $bruto === "") {
            $bruto = $campos[$ident]["padrao"];
        }
        if ($bruto !== null && $bruto !== "") {
            $tipo = $campos[$ident]["tipo"];
            if ($tipo === "inteiro" || $tipo === "decimal" || $tipo === "sim_nao") {
                $res = (float)$bruto;
            } elseif ($tipo === "data") {
                $res = dias_de(strtotime($bruto . " 00:00:00"));
            } else {
                $res = (string)$bruto;
            }
        }
    } elseif (isset(formulas_todas()[$ident])) {
        $f = mais_perto(formulas_todas()[$ident], $ctx["r"]);
        if ($f !== null) {
            if (isset($ctx["pilha"][$ident])) {
                // círculo: uma fórmula que depende dela mesma (a validação não deixa gravar; isto só protege)
                $res = null;
            } else {
                $ctx["pilha"][$ident] = true;
                // a árvore de cada expressão é lida uma vez por requisição (a escala calcula a mesma fórmula milhares de vezes)
                if (!array_key_exists($f["expressao"], $arvores)) {
                    try {
                        $arvores[$f["expressao"]] = formula_ler($f["expressao"]);
                    } catch (ErroFormula $e) {
                        $arvores[$f["expressao"]] = null;
                    }
                }
                $res = $arvores[$f["expressao"]] === null ? null : formula_calcular($arvores[$f["expressao"]], $ctx);
                unset($ctx["pilha"][$ident]);
            }
        }
    }
    return $res;
}

// Confere uma fórmula antes de gravar: a escrita, as variáveis (campos ou fórmulas que existem), os tipos de lançamento,
// e se ela não depende dela mesma (A usa B, que usa A). $ident: o identificador da fórmula que se grava. Devolve a
// lista de erros (vazia: pode gravar).
function formula_validar($ident, $expressao)
{
    $erros = [];
    try {
        $refs = formula_referencias(formula_ler($expressao));
        $conhecidas = array_merge(array_keys(campos_todos()), array_keys(formulas_todas()), [$ident]);
        foreach ($refs["variaveis"] as $v) {
            if (!in_array($v, $conhecidas, true)) {
                $erros[] = t("variável desconhecida: {1} (não é campo nem fórmula)", [1 => $v]);
            }
        }
        foreach ($refs["lancamentos"] as $t) {
            if (!isset(lancamento_tipos()[$t])) {
                $erros[] = t("tipo de lançamento desconhecido: \"{1}\"", [1 => $t]);
            }
        }
        // o círculo: a partir das variáveis desta fórmula, seguindo as fórmulas (todas as versões de cada uma), chega-se a ela?
        if (count($erros) === 0) {
            // cada item a visitar leva o caminho até ele, para a mensagem mostrar o círculo inteiro
            $visitar = [];
            foreach ($refs["variaveis"] as $v) {
                $visitar[] = [$v, [$ident, $v]];
            }
            $vistas = [];
            while (count($visitar) > 0) {
                $item = array_pop($visitar);
                $v = $item[0];
                if ($v === $ident) {
                    $erros[] = t("a fórmula depende dela mesma: {1}", [1 => implode(" → ", $item[1])]);
                    $visitar = [];
                } elseif (!isset($vistas[$v]) && isset(formulas_todas()[$v])) {
                    $vistas[$v] = true;
                    foreach (formulas_todas()[$v] as $f) {
                        try {
                            foreach (formula_referencias(formula_ler($f["expressao"]))["variaveis"] as $prox) {
                                $visitar[] = [$prox, array_merge($item[1], [$prox])];
                            }
                        } catch (ErroFormula $e) {
                            $erros[] = t("a fórmula {1} usada aqui está com erro de escrita", [1 => $v]);
                        }
                    }
                }
            }
        }
    } catch (ErroFormula $e) {
        $erros[] = t("erro de escrita: {1}", [1 => $e->getMessage()]);
    }
    return $erros;
}

// Uma linha da tabela dos relógios da página Hoje (recurso=hoje, relogios[]): o estado, a carga, a última vez usado, a próxima
// manutenção, o Encostado e a compra de um relógio da coleção. $extra: nos (nos_todos()), hoje (o dia da coleção), dia (o
// plano de hoje), pendentes (os ids com aviso de hoje) e fotos (id => atualizada); o que faltar sai do banco (os totais
// usam só a linha)
function tabela_linha($r, $agora, $extra = [])
{
    $nos = $extra["nos"] ?? nos_todos();
    $hoje = $extra["hoje"] ?? hoje_da_colecao();
    $dia = array_key_exists("dia", $extra) ? $extra["dia"] : plano_do_dia($hoje->format("Y-m-d"));
    $pendentes = $extra["pendentes"] ?? [];
    $fotos = $extra["fotos"] ?? [];
    $v = visao_relogio($r, $agora);
    // o estado na tabela: o indisponível parado aparece como indisponível (o emprestado, como emprestado); no pulso ou numa
    // sessão, o que ele está fazendo
    $estado_h = $v["estado"] === "repouso" && (int)$r["disponivel"] !== 1 ? "indisponivel" : $v["estado"];
    $val = valores_do_relogio((int)$r["id"]);
    $leitura = linha("SELECT l.inicio, l.valor, t.unidade FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.formato = 'valor'
            AND t.marcha IS NULL ORDER BY l.inicio DESC, l.id DESC LIMIT 1", [(int)$r["id"]]);
    return ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1, "tipo" => isset($nos[(int)$r["no_id"]]) ? $nos[(int)$r["no_id"]]["nome"] : "—",
        "posse" => posse_de($r), "posse_texto" => t(POSSES[posse_de($r)]),
        "em_uso" => $v["em_uso"], "estado" => $estado_h, "estado_texto" => $estado_h === "indisponivel" ? (posse_de($r) === "emprestado" ? t("Emprestado") : t("Indisponível")) : $v["estado_texto"],
        "estado_ordem" => ["uso" => 0, "sessao" => 1, "repouso" => 2, "indisponivel" => 3][$estado_h],
        "agora" => $v["texto"], "carga" => (int)$r["disponivel"] === 1 ? $v["energia"] : null, "carga_de" => $v["de"], "ultimo" => $v["ultimo"],
        "ultimo_txt" => $v["em_uso"] ? t("agora · {1}", [1 => date("H:i", $agora)]) : ($v["ultimo"] > 0 ? data_txt($v["ultimo"], true, date("Y", $v["ultimo"]) === date("Y", $agora)) : t("nunca")),
        "situacao" => $v["situacao"], "manutencao" => $v["prox"] ? ["nome" => $v["prox"]["nome"], "data" => max($hoje->format("Y-m-d"), substr($v["prox"]["data"], 0, 10)),
            "momento" => $v["prox_momento"], "falta" => $v["prox_falta"]] : null,
        "encostado" => $v["encostado"] ? ["data" => max($hoje->format("Y-m-d"), substr($v["encostado"]["data"], 0, 10)),
            "momento" => $v["encostado_momento"], "falta" => $v["encostado_falta"], "estado" => $v["encostado"]["estado"]] : null,
        "compra" => ["data" => $val["data_compra"] ?? null, "valor" => isset($val["valor_compra"]) ? (float)$val["valor_compra"] : null, "loja" => $val["loja"] ?? null,
            "garantia_ate" => $val["garantia_ate"] ?? null],
        "leitura" => $leitura ? ["inicio" => $leitura["inicio"], "valor" => (float)$leitura["valor"], "unidade" => $leitura["unidade"]] : null,
        "de_hoje" => $dia !== null && (int)$dia["relogio_id"] === (int)$r["id"], "com_aviso" => isset($pendentes[(int)$r["id"]]), "foto" => $fotos[(int)$r["id"]] ?? null];
}

// ---------------------------------------------------------------------------------------------------------------------
// Os totais (a tabela total, da árvore, como as fórmulas): cada um é uma expressão do motor que olha para um conjunto de
// relógios de uma vez (os que a tabela da página Hoje mostra, pelo filtro dela). As funções de total só valem aí: dentro
// delas, a conta de cada relógio é a de uma fórmula (os campos, as fórmulas, SE, DIAS_DESDE...) mais as variáveis da
// tabela (o que cada coluna mostra). Fora delas, só números, textos e as funções que não precisam de relógio (SE, MIN,
// ARREDONDA...). AGRUPADO, FAIXAS e QUEM_MAIOR/QUEM_MENOR dão uma tabelinha ou um relógio: só como a expressão inteira.
// Cada total vai para o rodapé da tabela, embaixo da coluna dele (rodape = 1, só com coluna), e/ou para a página de
// totais (pagina = 1), como um quadro estreito ou largo; formato diz como os números saem.
// ---------------------------------------------------------------------------------------------------------------------

// As funções de total: nome => [mínimo de argumentos, máximo (null: sem limite), descrição]
$FUNCOES_TOTAIS = [
    "CONTA" => [0, 1, tk("CONTA(): quantos relógios; CONTA(x): quantos têm x preenchido")],
    "CONTA_SE" => [1, 1, tk("CONTA_SE(condição): quantos passam na condição, como CONTA_SE(carga <= 20)")],
    "DISTINTOS" => [1, 1, tk("DISTINTOS(x): quantos valores diferentes de x (os vazios não contam)")],
    "SOMA" => [1, 1, tk("SOMA(x): a soma de x nos relógios que têm x")],
    "MEDIA" => [1, 1, tk("MEDIA(x): a média de x nos relógios que têm x (de uma data, a data média)")],
    "MEDIANA" => [1, 1, tk("MEDIANA(x): a mediana de x (o do meio, resiste a um valor fora da curva)")],
    "MENOR" => [1, 1, tk("MENOR(x): o menor valor de x")],
    "MAIOR" => [1, 1, tk("MAIOR(x): o maior valor de x")],
    "QUEM_MENOR" => [1, 1, tk("QUEM_MENOR(x): o relógio com o menor x, com o valor ao lado (só como a expressão inteira)")],
    "QUEM_MAIOR" => [1, 1, tk("QUEM_MAIOR(x): o relógio com o maior x, com o valor ao lado (só como a expressão inteira)")],
    "AGRUPADO" => [1, 3, tk("AGRUPADO(chave; conta; limite): uma tabelinha, uma linha por valor da chave e a linha de total; conta (sem ela, CONTA()) é a conta de cada linha, feita só com os relógios dela, e a do total, com todos; limite mostra as que mais têm e junta o resto em \"outros\" (só como a expressão inteira)")],
    "FAIXAS" => [2, null, tk("FAIXAS(x; limite1; limite2; ...): uma tabelinha por faixas de x (até o limite1, do limite1 ao limite2, ..., mais que o último quando houver), os sem x numa linha \"sem valor\" e o total (só como a expressão inteira)")],
];
// as que dão uma tabelinha ou um relógio: só como a expressão inteira
const TOTAIS_DO_TOPO = ["AGRUPADO", "FAIXAS", "QUEM_MENOR", "QUEM_MAIOR"];
// as funções do motor que precisam de um relógio: nos totais, só dentro de uma função de total
const FUNCOES_DO_RELOGIO = ["NO_GRUPO", "RELOGIO", "CALIBRE", "ULTIMO_SERVICO", "CUSTO_SERVICOS", "RESERVA_MEDIDA", "RESERVA_TESTES", "ESCOLHIDO_HA", "VEZES_NO_PLANO", "MEDIDO", "MEDIA_COLECAO", "LEITURAS", "QUEDA", "DIAS_MEDIDOS", "MEDIA_TAXA",
    "MEDIANA_TAXA", "ULTIMA_TAXA", "MARCHA", "MARCHA_DP", "MARCHA_N", "MARCHA_ULTIMA", "DESVIO_AGORA", "DIA_SEMANA", "SEMANA_DO_ANO", "SORTEIO"];

// As variáveis da tabela, que os totais têm em cada relógio além dos campos e das fórmulas: nome => [o que é, unidade]
$VARIAVEIS_TABELA = [
    "codigo" => [tk("o código do relógio (a coluna Código)"), ""],
    "nome" => [tk("o nome do relógio"), ""],
    "tipo" => [tk("o tipo (o grupo da árvore onde ele está: a coluna Tipo)"), ""],
    "estado" => [tk("o estado agora, por extenso (Em uso, Em repouso, No winder, Indisponível...)"), ""],
    "disponivel" => [tk("1 se o relógio está disponível para o rodízio, 0 se não"), ""],
    "em_uso" => [tk("1 se está no pulso agora"), ""],
    "carga" => [tk("a carga agora, em % (vazio: sem leitura ou indisponível)"), "%"],
    "ultimo_uso" => [tk("a data da última vez no pulso (agora, se está no pulso; vazio: nunca)"), ""],
    "manutencao_data" => [tk("a data da próxima manutenção (vazio: nenhuma prevista)"), ""],
    "manutencao_nome" => [tk("o que é a próxima manutenção (a coluna O que fazer: Carregar, Pôr no sol...)"), ""],
    "encostado_data" => [tk("a data em que ele passa (ou passou) do limite de dias sem ir ao pulso (vazio: sem data ou o aviso desligado)"), ""],
    "avisos" => [tk("quantos avisos o relógio tem agora (todos, não só o próximo)"), ""],
    "posse" => [tk("a posse: Na coleção ou Emprestado"), ""],
    "servicos" => [tk("quantos serviços e diagnósticos o relógio tem"), ""],
    "custo_servicos" => [tk("quanto os serviços do relógio custaram, somados (a mão de obra mais as peças; vazio: nenhum com custo)"), "R$"],
];

// As colunas da tabela dos relógios onde um total pode ir (a chave é a do cabeçalho da página Hoje), na ordem dela
const TOTAL_COLUNAS = ["codigo" => "Código", "nome" => "Relógio", "tipo" => "Tipo", "estado" => "Estado", "carga" => "Carga", "ultimo" => "Última vez usado",
    "manut" => "Próxima manutenção", "em" => "Em", "fazer" => "O que fazer", "encostado" => "Encostado", "compra" => "Comprado em", "valor" => "Valor"];
// Como os números de um total saem: número (inteiro quando é, senão até 2 casas), inteiro, decimal (2 casas), moeda (R$),
// porcentagem, data e dias
const TOTAL_FORMATOS = ["numero" => "Número", "inteiro" => "Inteiro", "decimal" => "Com 2 casas", "moeda" => "Dinheiro (R$)", "porcentagem" => "Porcentagem (%)",
    "data" => "Data", "dias" => "Dias"];

// Os nomes das colunas e dos formatos, no idioma do pedido
function total_colunas_nomes()
{
    return ["codigo" => t("Código"), "nome" => t("Relógio"), "tipo" => t("Tipo"), "estado" => t("Estado"), "carga" => t("Carga"), "ultimo" => t("Última vez usado"),
        "manut" => t("Próxima manutenção"), "em" => t("Em"), "fazer" => t("O que fazer"), "encostado" => t("Encostado"), "compra" => t("Comprado em"), "valor" => t("Valor")];
}

function total_formatos_nomes()
{
    return ["numero" => t("Número"), "inteiro" => t("Inteiro"), "decimal" => t("Com 2 casas"), "moeda" => t("Dinheiro (R$)"), "porcentagem" => t("Porcentagem (%)"),
        "data" => t("Data"), "dias" => t("Dias")];
}

// Lê a expressão de um total (o leitor das fórmulas, aceitando as funções de total)
function total_ler($expressao)
{
    $GLOBALS["LENDO_TOTAL"] = true;
    try {
        return formula_ler($expressao);
    } finally {
        $GLOBALS["LENDO_TOTAL"] = false;
    }
}

// Confere a expressão de um total antes de gravar (ou de testar): a escrita, as variáveis (campos, fórmulas, as da tabela),
// os tipos de lançamento, as variáveis só dentro de uma função de total, as funções de total uma dentro da outra só no
// AGRUPADO (a conta de cada linha) e as de tabelinha só como a expressão inteira. Devolve a lista de erros
function total_validar($expressao)
{
    global $FUNCOES_TOTAIS, $VARIAVEIS_TABELA, $VARIAVEIS_MOTOR;
    $erros = [];
    try {
        $arvore = total_ler($expressao);
    } catch (ErroFormula $e) {
        return [t("erro de escrita: {1}", [1 => $e->getMessage()])];
    }
    $conhecidas = array_merge(array_keys(campos_todos()), array_keys(formulas_todas()), array_keys($VARIAVEIS_TABELA), array_keys($VARIAVEIS_MOTOR));
    // $no: o nó; $nivel: "conjunto" (fora de uma função de total) ou "relogio" (dentro); $topo: é a expressão inteira
    $andar = function ($no, $nivel, $topo) use (&$andar, &$erros, $conhecidas, $FUNCOES_TOTAIS) {
        if ($no[0] === "v") {
            if ($nivel === "conjunto") {
                $erros[] = t("a variável {1} só vale dentro de uma função de total (CONTA, SOMA, MEDIA, AGRUPADO...)", [1 => $no[1]]);
            } elseif (!in_array($no[1], $conhecidas, true)) {
                $erros[] = t("variável desconhecida: {1} (não é campo, fórmula nem coluna da tabela)", [1 => $no[1]]);
            }
        } elseif ($no[0] === "op") {
            $andar($no[2], $nivel, false);
            $andar($no[3], $nivel, false);
        } elseif ($no[0] === "neg") {
            $andar($no[1], $nivel, false);
        } elseif ($no[0] === "f") {
            $nome = $no[1];
            if (isset($FUNCOES_TOTAIS[$nome])) {
                if ($nivel === "relogio") {
                    $erros[] = t("{1} não vale dentro de outra função de total (a não ser como a conta do AGRUPADO)", [1 => $nome]);
                } elseif (in_array($nome, TOTAIS_DO_TOPO, true) && !$topo) {
                    $erros[] = t("{1} dá uma tabelinha ou um relógio: só vale como a expressão inteira", [1 => $nome]);
                }
                foreach ($no[2] as $k => $a) {
                    if ($nome === "AGRUPADO" && $k >= 1) {
                        // a conta de cada linha (uma função de total) e o limite: no nível do conjunto
                        $andar($a, "conjunto", false);
                    } elseif ($nome === "FAIXAS" && $k >= 1) {
                        $andar($a, "conjunto", false);
                    } else {
                        $andar($a, "relogio", false);
                    }
                }
            } else {
                if ($nivel === "conjunto" && in_array($nome, FUNCOES_DO_RELOGIO, true)) {
                    $erros[] = t("{1} precisa de um relógio: só vale dentro de uma função de total", [1 => $nome]);
                }
                if ($nivel === "conjunto" && ($GLOBALS["FUNCOES"][$nome][3] ?? false)) {
                    $erros[] = t("{1} lê o histórico de um relógio: só vale dentro de uma função de total", [1 => $nome]);
                }
                foreach ($no[2] as $a) {
                    $andar($a, $nivel, false);
                }
            }
        }
    };
    $andar($arvore, "conjunto", true);
    foreach (formula_referencias($arvore)["lancamentos"] as $tl) {
        if (!isset(lancamento_tipos()[$tl])) {
            $erros[] = t("tipo de lançamento desconhecido: \"{1}\"", [1 => $tl]);
        }
    }
    return array_values(array_unique($erros));
}

// As variáveis da tabela de um relógio, a partir da linha da tabela (tabela_linha); as datas em dias, como nas fórmulas
function tabela_variaveis($linha, $r, $agora)
{
    $em_dias = function ($data) { return $data === null || $data === "" ? null : dias_de(strtotime(substr((string)$data, 0, 10) . " 00:00:00")); };
    return ["codigo" => (int)$linha["id"], "nome" => $linha["nome"], "tipo" => $linha["tipo"], "estado" => $linha["estado_texto"], "disponivel" => $linha["disponivel"] ? 1 : 0,
        "em_uso" => $linha["em_uso"] ? 1 : 0, "carga" => $linha["carga"] === null ? null : (float)$linha["carga"],
        "ultimo_uso" => $linha["em_uso"] ? dias_de($agora) : ((int)$linha["ultimo"] > 0 ? dias_de((int)$linha["ultimo"]) : null),
        "manutencao_data" => $linha["manutencao"] ? $em_dias($linha["manutencao"]["data"]) : null, "manutencao_nome" => $linha["manutencao"]["nome"] ?? null,
        "encostado_data" => $linha["encostado"] ? $em_dias($linha["encostado"]["data"]) : null, "avisos" => count(avisos_do_relogio($r, $agora)),
        "posse" => $linha["posse_texto"], "servicos" => count(servicos_das_contas()[(int)$r["id"]] ?? []), "custo_servicos" => (function ($ss) {
            $cs = array_values(array_filter(array_column($ss, "custo"), function ($c) { return $c !== null; }));
            return count($cs) > 0 ? round(array_sum($cs), 2) : null;
        })(servicos_das_contas()[(int)$r["id"]] ?? [])];
}

// Os relógios de um total: os da tabela da página Hoje (os da coleção: na coleção e emprestados), ou só os de $ids; cada um
// com o contexto da conta de uma fórmula e as variáveis da tabela. Guardados por requisição
function totais_relogios($ids = null)
{
    static $cache = [];
    $agora = time();
    $chave = (int)colecao_atual() . "|" . ($ids === null ? "*" : implode(",", $ids)) . "|" . ($GLOBALS["FORMULAS_VERSAO"] ?? 0);
    if (!isset($cache[$chave])) {
        $extra = ["nos" => nos_todos(), "hoje" => hoje_da_colecao(), "dia" => null];
        $res = [];
        foreach (linhas("SELECT * FROM relogio ORDER BY nome, id") as $r) {
            if (!da_colecao($r) || ($ids !== null && !in_array((int)$r["id"], $ids, true))) {
                continue;
            }
            $linha = tabela_linha($r, $agora, $extra);
            $res[] = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"]),
                "tabela" => tabela_variaveis($linha, $r, $agora)];
        }
        $cache[$chave] = $res;
    }
    return $cache[$chave];
}

// Um valor como um nó da árvore (para as funções comuns calcularem por cima dos resultados das de total)
function total_no($v)
{
    return $v === null ? ["f", "NADA", []] : (is_numeric($v) ? ["n", (float)$v] : ["t", (string)$v]);
}

// O valor de x em cada relógio do conjunto (a conta de uma fórmula): [índice => valor]
function total_por_relogio($no, &$conj)
{
    $res = [];
    foreach ($conj as $k => &$c) {
        $res[$k] = formula_calcular($no, $c);
    }
    unset($c);
    return $res;
}

// Uma expressão de total sobre um conjunto de relógios, no nível do conjunto: as funções de total contam, as outras calculam
// por cima dos resultados (como numa fórmula). Devolve um número, um texto ou null
function total_valor($no, &$conj)
{
    global $FUNCOES_TOTAIS;
    if ($no[0] === "n" || $no[0] === "t") {
        return $no[1];
    }
    if ($no[0] === "v") {
        return null;
    }
    $vazio = ["r" => ["id" => 0, "nome" => "", "no_id" => 0], "momento" => time(), "rastro" => [], "pilha" => [], "valores" => []];
    if ($no[0] === "neg") {
        return formula_calcular(["neg", total_no(total_valor($no[1], $conj))], $vazio);
    }
    if ($no[0] === "op") {
        return formula_calcular(["op", $no[1], total_no(total_valor($no[2], $conj)), total_no(total_valor($no[3], $conj))], $vazio);
    }
    $nome = $no[1];
    $args = $no[2];
    if (!isset($FUNCOES_TOTAIS[$nome])) {
        // SE e ESCOLHA calculam só o lado escolhido; as outras, todos os argumentos
        if ($nome === "SE") {
            $c = total_valor($args[0], $conj);
            return $c === null ? null : total_valor(((float)$c != 0) ? $args[1] : $args[2], $conj);
        }
        if ($nome === "ESCOLHA") {
            $n = total_valor($args[0], $conj);
            $k = is_numeric($n) ? (int)floor((float)$n) : 0;
            return $k >= 1 && isset($args[$k]) ? total_valor($args[$k], $conj) : null;
        }
        return formula_calcular(["f", $nome, array_map(function ($a) use (&$conj) { return total_no(total_valor($a, $conj)); }, $args)], $vazio);
    }
    if ($nome === "CONTA" && count($args) === 0) {
        return count($conj);
    }
    $v = total_por_relogio($args[0], $conj);
    $cheios = array_values(array_filter($v, function ($x) { return $x !== null && $x !== ""; }));
    $numeros = array_values(array_map("floatval", array_filter($cheios, "is_numeric")));
    if ($nome === "CONTA") {
        return count($cheios);
    }
    if ($nome === "CONTA_SE") {
        return count(array_filter($cheios, function ($x) { return is_numeric($x) && (float)$x != 0; }));
    }
    if ($nome === "DISTINTOS") {
        return count(array_unique(array_map(function ($x) { return is_numeric($x) ? (string)(float)$x : "t:" . $x; }, $cheios)));
    }
    if (count($numeros) === 0) {
        return null;
    }
    if ($nome === "SOMA") {
        return array_sum($numeros);
    }
    if ($nome === "MEDIA") {
        return array_sum($numeros) / count($numeros);
    }
    if ($nome === "MEDIANA") {
        sort($numeros);
        $m = intdiv(count($numeros), 2);
        return count($numeros) % 2 === 1 ? $numeros[$m] : ($numeros[$m - 1] + $numeros[$m]) / 2;
    }
    if ($nome === "MENOR") {
        return min($numeros);
    }
    if ($nome === "MAIOR") {
        return max($numeros);
    }
    return null;
}

// Um total sobre um conjunto de relógios (totais_relogios): ["tipo" => "valor", "valor"], ["tipo" => "relogio", "relogio_id",
// "relogio", "valor"] (QUEM_MAIOR, QUEM_MENOR) ou ["tipo" => "tabela", "linhas" => [["rotulo", "valor", "relogios"], ...],
// "total"] (AGRUPADO, FAIXAS). Os rótulos dos meses (MES_ANO) saem como "ago/2026"; a unidade de x (a da coluna, do campo
// ou da fórmula) vai nos rótulos das faixas
function total_resultado($arvore, $conj)
{
    if ($arvore[0] === "f" && ($arvore[1] === "QUEM_MAIOR" || $arvore[1] === "QUEM_MENOR")) {
        $v = total_por_relogio($arvore[2][0], $conj);
        $achado = null;
        foreach ($v as $k => $x) {
            if (is_numeric($x) && ($achado === null || ($arvore[1] === "QUEM_MAIOR" ? (float)$x > (float)$v[$achado] : (float)$x < (float)$v[$achado]))) {
                $achado = $k;
            }
        }
        return $achado === null ? ["tipo" => "relogio", "relogio_id" => null, "relogio" => null, "valor" => null]
            : ["tipo" => "relogio", "relogio_id" => (int)$conj[$achado]["r"]["id"], "relogio" => $conj[$achado]["r"]["nome"], "valor" => (float)$v[$achado]];
    }
    if ($arvore[0] === "f" && $arvore[1] === "AGRUPADO") {
        $args = $arvore[2];
        $conta = $args[1] ?? ["f", "CONTA", []];
        $limite = isset($args[2]) ? total_valor($args[2], $conj) : null;
        $chaves = total_por_relogio($args[0], $conj);
        $grupos = [];
        $sem = [];
        foreach ($chaves as $k => $x) {
            if ($x === null || $x === "") {
                $sem[] = $conj[$k];
            } else {
                $grupos[is_numeric($x) ? (string)(float)$x : (string)$x][] = $conj[$k];
            }
        }
        $linhas = [];
        foreach ($grupos as $chave => $g) {
            $linhas[] = ["chave" => (string)$chave, "rotulo" => total_rotulo((string)$chave), "valor" => total_valor($conta, $g), "relogios" => count($g), "grupo" => $g];
        }
        // números e meses (AAAA-MM) na ordem deles; textos do que mais tem para o que menos tem (empate: pelo nome)
        $ordem_natural = count($linhas) > 0 && count(array_filter($linhas, function ($l) { return !is_numeric($l["chave"]) && preg_match("/^\\d{4}-\\d{2}$/", $l["chave"]) !== 1; })) === 0;
        usort($linhas, function ($a, $b) use ($ordem_natural) {
            if ($ordem_natural) {
                return is_numeric($a["chave"]) && is_numeric($b["chave"]) ? ((float)$a["chave"] <=> (float)$b["chave"]) : strcmp($a["chave"], $b["chave"]);
            }
            $va = is_numeric($a["valor"]) ? (float)$a["valor"] : -INF;
            $vb = is_numeric($b["valor"]) ? (float)$b["valor"] : -INF;
            return $vb <=> $va ?: strcmp(mb_strtolower($a["rotulo"], "UTF-8"), mb_strtolower($b["rotulo"], "UTF-8"));
        });
        if (is_numeric($limite) && (int)$limite >= 1 && count($linhas) > (int)$limite) {
            // as que mais têm ficam (na ordem da tabelinha); o resto vira "outros", com a conta feita com todos eles
            $por_valor = $linhas;
            usort($por_valor, function ($a, $b) {
                return (is_numeric($b["valor"]) ? (float)$b["valor"] : -INF) <=> (is_numeric($a["valor"]) ? (float)$a["valor"] : -INF) ?: strcmp($a["rotulo"], $b["rotulo"]);
            });
            $ficam = array_column(array_slice($por_valor, 0, (int)$limite), "chave");
            $resto = [];
            $mantidas = [];
            foreach ($linhas as $l) {
                if (in_array($l["chave"], $ficam, true)) {
                    $mantidas[] = $l;
                } else {
                    $resto = array_merge($resto, $l["grupo"]);
                }
            }
            $mantidas[] = ["chave" => "", "rotulo" => t("outros"), "valor" => total_valor($conta, $resto), "relogios" => count($resto)];
            $linhas = $mantidas;
        }
        if (count($sem) > 0) {
            $linhas[] = ["chave" => "", "rotulo" => t("sem valor"), "valor" => total_valor($conta, $sem), "relogios" => count($sem), "sem" => true];
        }
        return ["tipo" => "tabela", "linhas" => array_map(function ($l) {
            return ["rotulo" => $l["rotulo"], "valor" => $l["valor"], "relogios" => $l["relogios"], "sem" => !empty($l["sem"])];
        }, $linhas), "total" => total_valor($conta, $conj)];
    }
    if ($arvore[0] === "f" && $arvore[1] === "FAIXAS") {
        $args = $arvore[2];
        $limites = [];
        foreach (array_slice($args, 1) as $a) {
            $l = total_valor($a, $conj);
            if (!is_numeric($l) || (count($limites) > 0 && (float)$l <= end($limites))) {
                return ["tipo" => "erro", "erro" => t("FAIXAS: os limites são números, do menor para o maior")];
            }
            $limites[] = (float)$l;
        }
        $unidade = total_unidade($args[0]);
        $v = total_por_relogio($args[0], $conj);
        $contas = array_fill(0, count($limites) + 1, 0);
        $sem = 0;
        foreach ($v as $x) {
            if (!is_numeric($x)) {
                $sem++;
                continue;
            }
            $i = 0;
            while ($i < count($limites) && (float)$x > $limites[$i] + 1e-9) {
                $i++;
            }
            $contas[$i]++;
        }
        $inteiros = count(array_filter($limites, function ($l) { return floor($l) != $l; })) === 0;
        $num = function ($n) use ($unidade) { return decimal_txt($n, 2) . ($unidade === "" ? "" : ($unidade === "%" ? "%" : " " . $unidade)); };
        $linhas = [];
        foreach ($limites as $i => $l) {
            $rot = $i === 0 ? t("até {1}", [1 => $num($l)])
                : ($inteiros ? t("{1} a {2}", [1 => decimal_txt($limites[$i - 1] + 1, 2), 2 => $num($l)]) : t("mais de {1} até {2}", [1 => decimal_txt($limites[$i - 1], 2), 2 => $num($l)]));
            $linhas[] = ["rotulo" => $rot, "valor" => $contas[$i], "relogios" => $contas[$i], "sem" => false];
        }
        if ($contas[count($limites)] > 0) {
            $linhas[] = ["rotulo" => t("mais de {1}", [1 => $num(end($limites))]), "valor" => $contas[count($limites)], "relogios" => $contas[count($limites)], "sem" => false];
        }
        if ($sem > 0) {
            $linhas[] = ["rotulo" => t("sem valor"), "valor" => $sem, "relogios" => $sem, "sem" => true];
        }
        return ["tipo" => "tabela", "linhas" => $linhas, "total" => count($conj)];
    }
    return ["tipo" => "valor", "valor" => total_valor($arvore, $conj)];
}

// A unidade de x numa expressão de total, quando x é só uma variável (a da coluna da tabela, a do campo ou a da fórmula)
function total_unidade($no)
{
    global $VARIAVEIS_TABELA;
    if ($no[0] !== "v") {
        return $no[0] === "f" && in_array($no[1], ["DIAS_DESDE", "DIAS_ATE"], true) ? t("dias") : "";
    }
    if (isset($VARIAVEIS_TABELA[$no[1]])) {
        return $VARIAVEIS_TABELA[$no[1]][1];
    }
    if (isset(campos_todos()[$no[1]])) {
        return (string)(campos_todos()[$no[1]]["unidade"] ?? "");
    }
    $f = formulas_todas()[$no[1]] ?? [];
    return count($f) > 0 ? (string)(reset($f)["unidade"] ?? "") : "";
}

// O rótulo de uma linha do AGRUPADO: o mês (AAAA-MM) por extenso curto ("ago/2026"), o número no formato do idioma, o texto
function total_rotulo($chave)
{
    if (preg_match("/^(\\d{4})-(\\d{2})$/", $chave, $m) === 1) {
        $meses = [t("jan"), t("fev"), t("mar"), t("abr"), t("mai"), t("jun"), t("jul"), t("ago"), t("set"), t("out"), t("nov"), t("dez")];
        return ($meses[(int)$m[2] - 1] ?? $m[2]) . "/" . $m[1];
    }
    return is_numeric($chave) ? decimal_txt((float)$chave, 2) : $chave;
}

// Um número de um total no formato dele: número (inteiro quando é, senão até 2 casas), inteiro, decimal (2 casas), moeda,
// porcentagem, data (os dias das fórmulas) e dias; o vazio sai "—"; um texto sai como está
function total_texto($v, $formato)
{
    if ($v === null || $v === "") {
        return "—";
    }
    if (!is_numeric($v)) {
        return (string)$v;
    }
    $v = (float)$v;
    if ($formato === "inteiro") {
        return numero_txt(round($v), 0);
    }
    if ($formato === "decimal") {
        return numero_txt($v, 2);
    }
    if ($formato === "moeda") {
        return dinheiro_txt($v);
    }
    if ($formato === "porcentagem") {
        return numero_txt(round($v), 0) . "%";
    }
    if ($formato === "data") {
        return data_txt(gmdate("Y-m-d", (int)(floor($v) * 86400)));
    }
    if ($formato === "dias") {
        $n = (int)round($v);
        return $n === 1 ? t("1 dia") : t("{1} dias", [1 => numero_txt($n, 0)]);
    }
    return abs($v - round($v)) < 1e-9 ? numero_txt(round($v), 0) : numero_txt($v, 2, 0);
}

// Um total calculado e pronto para a tela: o resultado com os textos no formato dele (texto, linhas[].texto, total_texto,
// e no QUEM_MAIOR/QUEM_MENOR o relógio e o valor); com erro na expressão, tipo "erro" e o erro
function total_calcular($expressao, $formato, $conj)
{
    $erros = total_validar($expressao);
    if (count($erros) > 0) {
        return ["tipo" => "erro", "erro" => implode("; ", $erros)];
    }
    $arvore = total_ler($expressao);
    $res = total_resultado($arvore, $conj);
    if ($res["tipo"] === "valor") {
        $res["texto"] = total_texto($res["valor"], $formato);
    } elseif ($res["tipo"] === "relogio") {
        $res["texto"] = $res["relogio"] === null ? "—" : $res["relogio"] . " · " . total_texto($res["valor"], $formato);
        $res["valor_texto"] = total_texto($res["valor"], $formato);
    } elseif ($res["tipo"] === "tabela") {
        // nas faixas e no AGRUPADO sem conta, cada linha é uma contagem: inteiro; com a conta, o formato do total
        $conta = !($arvore[1] === "FAIXAS" || ($arvore[1] === "AGRUPADO" && count($arvore[2]) < 2));
        $f_linha = $conta ? $formato : "inteiro";
        foreach ($res["linhas"] as $i => $l) {
            $res["linhas"][$i]["texto"] = total_texto($l["valor"], $f_linha);
        }
        $res["total_texto"] = total_texto($res["total"], $f_linha);
    }
    return $res;
}

// Os totais da árvore da coleção, na ordem (com a coluna por extenso)
function totais_todos()
{
    return array_map(function ($x) {
        return ["id" => (int)$x["id"], "coluna" => $x["coluna"], "coluna_nome" => total_colunas_nomes()[$x["coluna"] ?? ""] ?? null,
            "nome" => $x["nome"], "expressao" => $x["expressao"], "rodape" => (int)$x["rodape"] === 1, "pagina" => (int)$x["pagina"] === 1,
            "largura" => $x["largura"], "formato" => $x["formato"], "ordem" => (int)$x["ordem"]];
    }, linhas("SELECT * FROM total ORDER BY ordem, id"));
}

// ---------------------------------------------------------------------------------------------------------------------
// Avisos: cada um com a fórmula da data prevista (em dias, como as datas das fórmulas), a antecedência em dias, o texto
// e o tipo de lançamento que o resolve. Como as fórmulas, a mesma chave pode ter uma versão por ponto da árvore, e vale
// a do mais perto do relógio.
// ---------------------------------------------------------------------------------------------------------------------

// Um tempo em dias por extenso, pelas duas maiores partes: "2a 3m", "1m 10d", "5d 3h", "2h 40min", "30min" (o sinal não
// importa); menos de um minuto: "" (quem chama decide o que mostrar)
function duracao_texto($dias)
{
    $min = (int)round(abs((float)$dias) * 1440);
    $partes = [];
    foreach ([[tk("{n}a"), 525960], [tk("{n}m"), 43830], [tk("{n}d"), 1440], [tk("{n}h"), 60], [tk("{n}min"), 1]] as $u) {
        if (count($partes) < 2 && ($min >= $u[1] || count($partes) > 0)) {
            $n = intdiv($min, $u[1]);
            $min -= $n * $u[1];
            if ($n > 0) {
                $partes[] = t($u[0], ["n" => $n]);
            }
        }
    }
    return implode(" ", $partes);
}

// Todos os avisos: [identificador => [versões]]
// O aviso Encostado está ligado? A caixa "Avisar quando um relógio fica encostado" da Configuração liga e desliga o aviso
// encostado do cadastro (aviso.ativo); desligado, some tudo dele: o aviso, a coluna Encostado da Hoje, o filtro e as linhas
function encostado_ativo()
{
    return (int)valor("SELECT COUNT(*) FROM aviso WHERE identificador = 'encostado' AND ativo = 1") > 0;
}

function avisos_todos($recarregar = false)
{
    // guardado por coleção (o cron passa por todas)
    static $todos_ = [];
    $k_ = (int)colecao_atual();
    $a = $todos_[$k_] ?? null;
    if ($a === null || $recarregar) {
        $a = [];
        foreach (linhas(AVISO_SELECT . " ORDER BY a.identificador, a.id") as $x) {
            $a[$x["identificador"]][] = $x;
        }
    }
    $todos_[$k_] = $a;
    return $a;
}

// Os avisos de um relógio num instante: cada aviso que vale para ele e tem data prevista (sem data: não se aplica agora).
// estado: atrasado (a data passou), em_breve (dentro da antecedência) ou ok. texto: o do cadastro, com {relogio},
// {data} (dd/mm/aaaa hh:mm), {quando} ("em 2d 3h", "há 5h", "agora") e {limite} (o limite de carga do relógio) trocados.
function avisos_do_relogio($r, $momento, $so = null)
{
    $res = [];
    // fora da coleção (desejado, vendido, arquivado), nenhum aviso: o relógio não está com você (o emprestado continua)
    if (array_key_exists("posse", $r) && !da_colecao($r)) {
        return $res;
    }
    $agora_d = dias_de($momento);
    foreach (avisos_todos() as $ident => $versoes) {
        // $so: só estes avisos (os identificadores), para não calcular os outros
        if ($so !== null && !in_array($ident, $so, true)) {
            continue;
        }
        $a = mais_perto($versoes, $r);
        if ($a !== null && (int)$a["ativo"] === 1 && condicao_vale($a["condicao"] ?? null, $r, $momento)) {
            $ctx = ["r" => $r, "momento" => $momento, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
            try {
                $data = formula_calcular(formula_ler($a["expressao"]), $ctx);
            } catch (ErroFormula $e) {
                $data = null;
            }
            if (is_numeric($data)) {
                // {limite}: o limite de carga que vale para o relógio (o dele, senão o geral), trocado já no modelo
                $modelo = $a["texto"];
                if (strpos($modelo, "{limite}") !== false) {
                    $lim = isset(formulas_todas()["limite_carga"]) ? variavel_valor("limite_carga", $ctx) : null;
                    $modelo = is_numeric($lim) ? str_replace("{limite}", decimal_txt((float)$lim, 1), $modelo)
                        : str_replace(["{limite}%", "{limite}"], "o limite", $modelo);
                }
                $falta = (float)$data - $agora_d;
                // o quanto falta (ou passou), por extenso (as duas maiores partes)
                $quando = duracao_texto($falta) === "" ? t("agora") : ($falta >= 0 ? t("em {1}", [1 => duracao_texto($falta)]) : t("há {1}", [1 => duracao_texto($falta)]));
                $ts = (int)round((float)$data * 86400);
                $data_txt = gmdate("d/m/Y H:i", $ts);
                // escala, simula_*, agenda e modelo: para a escala inteligente e a agenda (o modelo é o texto sem as trocas, só com o {limite})
                $res[] = ["identificador" => $ident, "nome" => $a["nome"], "data" => gmdate("Y-m-d H:i:s", $ts), "falta_dias" => round($falta, 4),
                    "estado" => $falta < 0 ? "atrasado" : ($falta <= (float)$a["antecedencia_dias"] ? "em_breve" : "ok"),
                    "texto" => str_replace(["{relogio}", "{data}", "{quando}"], [$r["nome"], $data_txt, $quando], $modelo),
                    "resolve" => $a["resolve"], "versao" => no_caminho($a["no_id"]), "escala" => $a["escala"],
                    "simula_valor" => $a["simula_valor"] === null ? null : (float)$a["simula_valor"], "simula_horas" => $a["simula_horas"] === null ? null : (float)$a["simula_horas"],
                    "agenda" => $a["agenda"], "na_hora" => (int)($a["na_hora"] ?? 0) === 1, "modelo" => $modelo];
            }
        }
    }
    // o desempate (dois avisos vencendo no mesmo instante) é fixo, para a lista sair igual em qualquer banco
    usort($res, function ($x, $y) {
        return [aviso_ordem($x["falta_dias"]), $x["identificador"]] <=> [aviso_ordem($y["falta_dias"]), $y["identificador"]];
    });
    return $res;
}

// A chave da ordem de um aviso pelo quanto falta (em dias): o que vence "agora" (menos de um minuto, para lá ou para cá)
// conta como zero, e desempata pelo relógio e pelo identificador. Senão, dois avisos vencidos agora mudavam de ordem
// conforme o segundo em que cada um foi calculado
function aviso_ordem($falta)
{
    return duracao_texto((float)$falta) === "" ? 0.0 : (float)$falta;
}

// Os avisos "na hora" vencidos agora, de todos os relógios (também os indisponíveis: um relógio fora do rodízio ainda pode
// estar no carregador): cada um [relógio, aviso]. O cron manda cada um pelo Telegram uma vez, até ele deixar de vencer
function avisos_na_hora_vencidos($momento)
{
    $idents = [];
    foreach (avisos_todos() as $ident => $versoes) {
        foreach ($versoes as $a) {
            if ((int)($a["na_hora"] ?? 0) === 1 && (int)$a["ativo"] === 1) {
                $idents[] = $ident;
                break;
            }
        }
    }
    $res = [];
    if (count($idents) > 0) {
        foreach (linhas("SELECT * FROM relogio ORDER BY nome, id") as $r) {
            foreach (avisos_do_relogio($r, $momento, $idents) as $a) {
                if ($a["na_hora"] && $a["falta_dias"] <= 0) {
                    $res[] = [$r, $a];
                }
            }
        }
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// Configuração (chave e valor), o login do site, e o envio pela API de mensagem
// ---------------------------------------------------------------------------------------------------------------------
function cfg($chave, $novo = null)
{
    // a configuração da coleção do pedido (sem coleção, só o idioma da instalação)
    static $cache = [];
    $c = colecao_atual();
    if ($c === null) {
        return $chave === "idioma" ? sis("idioma") : "";
    }
    if (!isset($cache[$c])) {
        $cache[$c] = [];
        foreach (linhas("SELECT chave, valor FROM config") as $x) {
            $cache[$c][$x["chave"]] = $x["valor"];
        }
    }
    if ($novo !== null) {
        $cache[$c][$chave] = $novo;
    }
    return $cache[$c][$chave] ?? "";
}

function cfg_set($chave, $valor)
{
    sql("REPLACE INTO config (colecao_id, chave, valor) VALUES (?, ?, ?)", [colecao_atual(), $chave, (string)$valor]);
    cfg($chave, (string)$valor);
}

// O usuário do site que veio no pedido (HTTP Basic, com a senha conferida), ou "" (as páginas e a API)
function usuario_autenticado()
{
    $login = $_SERVER["PHP_AUTH_USER"] ?? "";
    $senha = $_SERVER["PHP_AUTH_PW"] ?? "";
    if ($login === "") {
        $cab = $_SERVER["HTTP_AUTHORIZATION"] ?? ($_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "");
        if (stripos($cab, "Basic ") === 0) {
            $par = explode(':', (string)base64_decode(substr($cab, 6)), 2);
            if (count($par) === 2) {
                $login = $par[0];
                $senha = $par[1];
            }
        }
    }
    $res = "";
    // o aplicativo no celular: sem o login, a chave do aparelho lembrado (o cookie que o "Lembrar este aparelho" gravou)
    if ($login === "" && isset($_COOKIE["aparelho"]) && function_exists("usuario_por_aparelho")) {
        return usuario_por_aparelho((string)$_COOKIE["aparelho"]);
    }
    // banco vazio (antes da instalação): ninguém entra pelo login, só pelo token. A conta bloqueada pelo master, ou a demo
    // que venceu (o cron a apaga logo), não entra
    if ($login !== "" && banco_tem_tabela("usuario")) {
        $u = sem_escopo(function () use ($login) { return linha("SELECT * FROM usuario WHERE login = ?", [$login]); });
        if ($u && password_verify($senha, (string)$u["senha_hash"]) && (int)($u["bloqueado"] ?? 0) === 0
            && (($u["expira_em"] ?? null) === null || strtotime((string)$u["expira_em"]) > time())) {
            $res = (string)$u["login"];
            if (array_key_exists("papel", $u)) {
                usuario_definir($u);
                // o cookie de um aparelho lembrado veio junto com o login: a conta sabe qual é "este aparelho"
                if (function_exists("aparelho_deste_pedido")) {
                    aparelho_deste_pedido((int)$u["id"]);
                }
                // o último acesso (no máximo uma gravação por minuto)
                if ($u["ultimo_acesso"] === null || strtotime((string)$u["ultimo_acesso"]) < time() - 60) {
                    sem_escopo(function () use ($u) { sql("UPDATE usuario SET ultimo_acesso = NOW() WHERE id = ?", [(int)$u["id"]]); });
                }
            }
        }
    }
    return $res;
}

// A porta das páginas (o topo() do pagina.php chama antes de mandar qualquer coisa): o login do site (HTTP Basic) tem de
// estar certo, senão a página responde 401 com o pedido de senha do navegador e nada dela sai. Deixa passar sem login só
// quando ainda não há ninguém para entrar (o banco vazio ou sem usuários: a página mostra como instalar e criar o primeiro)
// e quando o banco não responde (a página mostra o erro que a API dá); nesses casos a página não tem dado nenhum.
function pagina_login()
{
    try {
        if (!banco_tem_tabela("usuario") || (int)sem_escopo(function () { return valor("SELECT COUNT(*) FROM usuario"); }) === 0) {
            return true;
        }
        // o aplicativo instalado abre pelo start_url do manifesto, que traz a chave do aparelho (?aparelho=): ela vira o
        // cookie deste navegador (o do aplicativo, separado do navegador comum no iPhone) e a página abre limpa
        if (isset($_GET["aparelho"]) && function_exists("aparelho_pela_url")) {
            aparelho_pela_url((string)$_GET["aparelho"]);
        }
        return usuario_autenticado() !== "";
    } catch (Throwable $e) {
        return true;
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// O aplicativo no celular (PWA): o manifesto (recurso=manifest) e o service worker (recurso=sw), os dois pela API. As
// páginas apontam o manifesto no <head> (pagina.php) e registram o service worker (api.js); o navegador instala o sistema
// como um aplicativo (um ícone, tela cheia, as notificações do Web Push) e o service worker guarda os arquivos das telas
// (o estilo, os scripts, os ícones e os textos do idioma) para abrir rápido, com a página "sem conexão" quando a rede cai.
// ---------------------------------------------------------------------------------------------------------------------

// O manifesto do aplicativo. O start_url leva a chave do aparelho lembrado quando o pedido vem com o cookie dela (o <link>
// do manifesto pede com as credenciais): o aplicativo instalado abre já entrando, mesmo onde o navegador do aplicativo
// não compartilha os cookies com o navegador comum (o iPhone)
function app_manifesto()
{
    $inicio = "index.php";
    if (isset($_COOKIE["aparelho"]) && function_exists("usuario_por_aparelho") && usuario_por_aparelho((string)$_COOKIE["aparelho"]) !== "") {
        $inicio .= "?aparelho=" . rawurlencode((string)$_COOKIE["aparelho"]);
    }
    return ["id" => "./", "name" => t("Relojoeiro"), "short_name" => t("Relojoeiro"),
        "description" => t("O rodízio dos seus relógios: o relógio do dia, a carga, a corda, os avisos e a coleção inteira."),
        "lang" => lingua_atual(), "start_url" => $inicio, "scope" => "./", "display" => "standalone", "orientation" => "any",
        "background_color" => "#e4e8ec", "theme_color" => "#1c2530",
        "icons" => [["src" => "icone-192.png", "sizes" => "192x192", "type" => "image/png"], ["src" => "icone-512.png", "sizes" => "512x512", "type" => "image/png"],
            ["src" => "icone-512.png", "sizes" => "512x512", "type" => "image/png", "purpose" => "maskable"]]];
}

// O service worker: o sw.js com, na frente, a versão (muda quando qualquer arquivo das telas muda: o navegador instala o
// service worker novo e o cache antigo sai) e a lista dos arquivos guardados na instalação (com o ?v= que as páginas usam)
function app_service_worker()
{
    $arquivos = ["estilo.css", "offline.html", "icone-192.png", "icone-512.png"];
    foreach (glob(__DIR__ . "/*.js") ?: [] as $js) {
        if (basename($js) !== "sw.js") {
            $arquivos[] = basename($js);
        }
    }
    sort($arquivos);
    $marca = "";
    $lista = [];
    foreach ($arquivos as $a) {
        $m = (int)@filemtime(__DIR__ . "/" . $a);
        $marca .= $a . ":" . $m . ":" . (int)@filesize(__DIR__ . "/" . $a) . ";";
        $lista[] = preg_match("/\\.(css|js)$/", $a) === 1 ? $a . "?v=" . $m : $a;
    }
    return "var VERSAO = " . json_encode(substr(sha1($marca), 0, 12)) . ";\nvar ARQUIVOS = " . json_encode($lista, JSON_UNESCAPED_SLASHES) . ";\n" . (string)file_get_contents(__DIR__ . "/sw.js");
}

// ---------------------------------------------------------------------------------------------------------------------
// Critérios de escolha: um conjunto por lugar da árvore ("" todos, "g:<no>" um ponto, "r:<relógio>" um relógio). O
// relógio usa o conjunto mais perto dele, inteiro. Cada subparâmetro mede uma variável (campo ou fórmula); as faixas
// transformam o valor numa nota de 0 a 100.
// ---------------------------------------------------------------------------------------------------------------------
function criterios_config($recarregar = false)
{
    // guardado por coleção (o cron passa por todas)
    static $todos_ = [];
    $k_ = (int)colecao_atual();
    $cfg = $todos_[$k_] ?? null;
    if ($cfg === null || $recarregar) {
        $cfg = [];
        foreach (linhas("SELECT * FROM criterio_parametro ORDER BY ordem, id") as $p) {
            $p["subs"] = [];
            foreach (linhas("SELECT * FROM criterio_sub WHERE parametro_id = ? ORDER BY ordem, id", [$p["id"]]) as $sb) {
                $sb["faixas"] = linhas("SELECT * FROM criterio_faixa WHERE sub_id = ? ORDER BY categoria, de", [$sb["id"]]);
                $p["subs"][] = $sb;
            }
            $chave = $p["escopo_relogio_id"] !== null ? "r:" . (int)$p["escopo_relogio_id"] : ($p["escopo_no_id"] !== null ? "g:" . (int)$p["escopo_no_id"] : "");
            $cfg[$chave][] = $p;
        }
    }
    $todos_[$k_] = $cfg;
    return $cfg;
}

// O lugar por extenso: "todos os relógios", o caminho do ponto, ou "relógio: nome"
function escopo_texto($chave)
{
    $res = t("todos os relógios");
    if (strpos((string)$chave, "r:") === 0) {
        $res = t("relógio: {1}", [1 => valor("SELECT nome FROM relogio WHERE id = ?", [(int)substr($chave, 2)]) ?? t("excluído")]);
    } elseif (strpos((string)$chave, "g:") === 0) {
        $res = no_caminho((int)substr($chave, 2));
    }
    return $res;
}

// A nota de 0 a 100 de um relógio, pelo conjunto mais perto dele, com a conta. Subparâmetro sem valor sai e os pesos dos
// que ficam se reescalam. Sem conjunto nenhum: 50.
function nota_do_relogio($r, $momento)
{
    $cfg = criterios_config();
    $chave = isset($cfg[""]) ? "" : null;
    foreach (no_cadeia($r["no_id"] ?? 0) as $g) {
        if (isset($cfg["g:" . $g])) {
            $chave = "g:" . $g;
        }
    }
    if (isset($cfg["r:" . (int)$r["id"]])) {
        $chave = "r:" . (int)$r["id"];
    }
    $ctx = ["r" => $r, "momento" => $momento, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
    $params = [];
    foreach ($chave !== null ? $cfg[$chave] : [] as $p) {
        $subs = [];
        foreach ($p["subs"] as $sb) {
            $v = variavel_valor($sb["variavel"], $ctx);
            $nota = null;
            $faixa = "";
            foreach ($sb["faixas"] as $i => $f) {
                if ($nota === null && $v !== null) {
                    if ($f["categoria"] !== null) {
                        if ((string)$v === $f["categoria"]) {
                            $nota = (float)$f["nota"];
                            $faixa = $f["categoria"];
                        }
                    } elseif (is_numeric($v) && (float)$v >= (float)$f["de"] && ($f["ate"] === null || (float)$v < (float)$f["ate"] || ($i === count($sb["faixas"]) - 1 && (float)$v <= (float)$f["ate"]))) {
                        $nota = (float)$f["nota"];
                        $faixa = $f["ate"] === null ? t("{1} ou mais", [1 => decimal_txt((float)$f["de"], 6)]) : t("{1} a {2}", [1 => decimal_txt((float)$f["de"], 6), 2 => decimal_txt((float)$f["ate"], 6)]);
                    }
                }
            }
            if ($nota !== null) {
                $subs[] = ["sb" => $sb, "valor" => $v, "faixa" => $faixa, "nota" => $nota];
            }
        }
        if (count($subs) > 0) {
            $params[] = ["p" => $p, "subs" => $subs, "soma" => array_sum(array_map(function ($x) { return (float)$x["sb"]["peso"]; }, $subs))];
        }
    }
    $total = 50.0;
    $conta = [];
    if (count($params) > 0) {
        $soma_p = array_sum(array_map(function ($x) { return (float)$x["p"]["peso"]; }, $params));
        $total = 0.0;
        foreach ($params as $x) {
            $fp = $soma_p > 0 ? (float)$x["p"]["peso"] / $soma_p : 1 / count($params);
            foreach ($x["subs"] as $s) {
                $fs = $x["soma"] > 0 ? (float)$s["sb"]["peso"] / $x["soma"] : 1 / count($x["subs"]);
                $total += $s["nota"] * $fp * $fs;
                $conta[] = ["parametro" => $x["p"]["nome"], "subparametro" => $s["sb"]["nome"], "variavel" => $s["sb"]["variavel"], "valor" => $s["valor"],
                    "faixa" => $s["faixa"], "nota" => $s["nota"], "peso_efetivo" => round($fp * $fs * 100, 2), "pontos" => round($s["nota"] * $fp * $fs, 2)];
            }
        }
    }
    return ["nota" => round($total, 2), "conjunto" => $chave, "conjunto_texto" => $chave !== null ? escopo_texto($chave) : "nenhum", "conta" => $conta];
}

// ---------------------------------------------------------------------------------------------------------------------
// O rodízio: o plano (o relógio de cada dia), pelo modo ativo, e a sessão no pulso do relógio do dia
// ---------------------------------------------------------------------------------------------------------------------

// O plano de um dia (Y-m-d), com o nome do relógio; null se não há
// O ciclo do rodízio: dos candidatos de um bloco ($ids), os que já passaram no ciclo atual, pela sequência dos relógios que
// entraram no rodízio que está sendo montado (do mais antigo para o mais recente: o plano desde a segunda-feira da semana, e
// o que a escala acabou de escolher; o uso de antes pesa pela nota e pelos dias sem uso). A sequência é lida em ordem:
// cada candidato que aparece entra no ciclo; quando todos entraram, o ciclo fecha e começa outro. Os que estão no ciclo
// aberto não voltam enquanto houver candidato que ainda não passou. Os relógios que não são candidatos do bloco não contam.
function ciclo_atual($sequencia, $ids)
{
    $ciclo = [];
    foreach ($sequencia as $x) {
        if (in_array((int)$x, $ids, true)) {
            $ciclo[(int)$x] = true;
            if (count($ciclo) >= count($ids)) {
                $ciclo = [];
            }
        }
    }
    return array_keys($ciclo);
}

function plano_do_dia($data)
{
    return linha("SELECT p.*, r.nome FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data = ?", [$data]);
}

// Para o motivo de uma escolha do plano (a frase que diz por que aquele relógio saiu): os dias sem uso por extenso
// ("nunca usado", "parado há 23 dias") e a nota com vírgula ("91,2")
function motivo_parado($dias_sem_uso, $nunca_usado = false)
{
    $dias = t("{1} dias", [1 => decimal_txt($dias_sem_uso, $dias_sem_uso < 10 ? 1 : 0)]);
    if ($dias_sem_uso >= 9999) {
        return t("nunca usado");
    }
    return $nunca_usado ? t("nunca usado, na coleção há {1}", [1 => $dias]) : t("parado há {1}", [1 => $dias]);
}

// O relógio nunca foi ao pulso (nem nos dias do plano já simulados)? Pela mesma conta das fórmulas. Os dias sem uso de um
// relógio assim vêm da fórmula dias_sem_uso (desde a compra, ou 9999), e o motivo diz que ele nunca foi usado
function nunca_no_pulso(&$ctx)
{
    static $arvore = null;
    if ($arvore === null) {
        $arvore = formula_ler("DIAS_DESDE_ULTIMO(\"pulso\")");
    }
    return formula_calcular($arvore, $ctx) === null;
}

function motivo_nota($nota)
{
    return decimal_txt((float)$nota, 1);
}


// ---------------------------------------------------------------------------------------------------------------------
// O rodízio: os tipos de modo, o roteiro de cada modo (as cinco linhas que o motor calcula relógio a relógio, dia a dia) e o
// montador do plano, um só para todos os modos (sorteio pelos blocos, escala inteligente e personalizado).
// ---------------------------------------------------------------------------------------------------------------------

// Os tipos de modo: o que a tela de cada um pede. Todos geram o mesmo roteiro; o personalizado é o roteiro escrito à mão
const MODO_TIPOS = ["semana" => "Um por semana", "semana_fds" => "Semana e fim de semana", "dia_semana" => "Por dia da semana",
    "aleatorio" => "Aleatório todo dia", "escala" => "Escala inteligente", "personalizado" => "Personalizado"];
tk("Um por semana"); tk("Semana e fim de semana"); tk("Por dia da semana"); tk("Aleatório todo dia"); tk("Escala inteligente"); tk("Personalizado");

// As saídas do roteiro (os nomes que o motor lê) e a expressão de cada uma quando o roteiro não a define
const ROTEIRO_SAIDAS = ["candidato" => "1", "nota" => "nota_criterios", "peso" => "0", "dias" => "1",
    "garantia" => "E(CONFIG(\"max_sem_uso\") > 0; dias_sem_uso > CONFIG(\"max_sem_uso\"))"];

// O dia da conta de uma fórmula: o dia que está sendo montado no rodízio (ctx dia), senão o dia do instante da conta
function dia_da_conta(&$ctx)
{
    return isset($ctx["dia"]) ? $ctx["dia"] : dia_da_colecao((int)$ctx["momento"]);
}

// Um ponto da árvore pelo que a fórmula escreveu: o nome ("Quartzo"), o caminho ("Tradicional › Quartzo"), "#id" ou o id;
// "", "todos" ou a raiz: 0 (todos os relógios); desconhecido: null. Sem maiúsculas nem acentos na comparação
function no_por_nome($txt)
{
    static $cache = [];
    $k = (int)colecao_atual() . "|" . (string)$txt;
    if (!array_key_exists($k, $cache)) {
        $t = trim((string)$txt);
        $res = null;
        if ($t === "" || mb_strtolower($t, "UTF-8") === "todos" || (ctype_digit($t) && (int)$t === no_raiz()) || $t === "#" . no_raiz()) {
            $res = 0;
        } elseif (ctype_digit($t) || preg_match("/^#(\\d+)$/", $t, $m) === 1) {
            $id = (int)($m[1] ?? $t);
            $res = isset(nos_todos()[$id]) ? $id : null;
        } else {
            $alvo = sem_acentos(mb_strtolower($t, "UTF-8"));
            foreach (nos_todos() as $id => $no) {
                if ($res === null && (sem_acentos(mb_strtolower((string)$no["nome"], "UTF-8")) === $alvo || sem_acentos(mb_strtolower(no_caminho($id), "UTF-8")) === $alvo)) {
                    $res = (int)$id === no_raiz() ? 0 : (int)$id;
                }
            }
        }
        $cache[$k] = $res;
    }
    return $cache[$k];
}

// Texto sem acentos (para comparar nomes), em minúsculas já
function sem_acentos($t)
{
    $de = ["á", "à", "â", "ã", "ä", "é", "è", "ê", "ë", "í", "ì", "î", "ï", "ó", "ò", "ô", "õ", "ö", "ú", "ù", "û", "ü", "ç", "ñ"];
    $para = ["a", "a", "a", "a", "a", "e", "e", "e", "e", "i", "i", "i", "i", "o", "o", "o", "o", "o", "u", "u", "u", "u", "c", "n"];
    return str_replace($de, $para, $t);
}

// Lê um roteiro (o texto de um modo): uma linha por nome, "nome = expressão" (a expressão na língua das fórmulas do
// cadastro); linhas vazias e as que começam com # são ignoradas. Devolve ["linhas" => [[nome, expressão], ...],
// "arvores" => [nome => árvore], "erros" => [...]]. Os erros: a escrita de cada expressão, nome repetido ou inválido,
// variável desconhecida (não é campo, fórmula, linha do roteiro nem variável do motor), tipo de lançamento desconhecido,
// uma linha que depende dela mesma. Com erros, as árvores das linhas boas vêm mesmo assim
function roteiro_ler($texto)
{
    global $VARIAVEIS_MOTOR;
    $linhas = [];
    $arvores = [];
    $erros = [];
    $n = 0;
    foreach (preg_split("/\\r\\n|\\r|\\n/", (string)$texto) as $l) {
        $n++;
        $l = trim($l);
        if ($l === "" || $l[0] === "#") {
            continue;
        }
        if (preg_match("/^([A-Za-z_][A-Za-z0-9_]*)\\s*=(?!=)\\s*(.*)$/u", $l, $m) !== 1) {
            $erros[] = t("linha {1}: escreva \"nome = expressão\"", [1 => $n]);
            continue;
        }
        $nome = strtolower($m[1]);
        $expr = trim($m[2]);
        if (isset($arvores[$nome]) || in_array($nome, array_column($linhas, 0), true)) {
            $erros[] = t("linha {1}: o nome {2} já foi definido", [1 => $n, 2 => $nome]);
            continue;
        }
        if ($expr === "") {
            $erros[] = t("linha {1}: a expressão de {2} está vazia", [1 => $n, 2 => $nome]);
            continue;
        }
        $linhas[] = [$nome, $expr];
        try {
            $arvores[$nome] = formula_ler($expr);
        } catch (ErroFormula $e) {
            $erros[] = t("linha {1} ({2}): erro de escrita: {3}", [1 => $n, 2 => $nome, 3 => $e->getMessage()]);
        }
    }
    // as variáveis: campos, fórmulas, as linhas do roteiro e as do motor; os tipos de lançamento; o círculo entre as linhas
    $conhecidas = array_merge(array_keys(campos_todos()), array_keys(formulas_todas()), array_keys($arvores), array_keys($VARIAVEIS_MOTOR));
    $usa = [];
    foreach ($arvores as $nome => $arv) {
        $refs = formula_referencias($arv);
        $usa[$nome] = $refs["variaveis"];
        foreach ($refs["variaveis"] as $v) {
            if (!in_array($v, $conhecidas, true)) {
                $erros[] = t("{1}: variável desconhecida: {2} (não é campo, fórmula nem linha do roteiro)", [1 => $nome, 2 => $v]);
            }
        }
        foreach ($refs["lancamentos"] as $tl) {
            if (!isset(lancamento_tipos()[$tl])) {
                $erros[] = t("{1}: tipo de lançamento desconhecido: \"{2}\"", [1 => $nome, 2 => $tl]);
            }
        }
    }
    foreach (array_keys($arvores) as $nome) {
        $visitar = array_map(function ($v) use ($nome) { return [$v, [$nome, $v]]; }, $usa[$nome]);
        $vistas = [];
        while (count($visitar) > 0) {
            $item = array_pop($visitar);
            if ($item[0] === $nome) {
                $erros[] = t("a linha {1} depende dela mesma: {2}", [1 => $nome, 2 => implode(" → ", $item[1])]);
                $visitar = [];
            } elseif (!isset($vistas[$item[0]]) && isset($usa[$item[0]])) {
                $vistas[$item[0]] = true;
                foreach ($usa[$item[0]] as $prox) {
                    $visitar[] = [$prox, array_merge($item[1], [$prox])];
                }
            }
        }
    }
    return ["linhas" => $linhas, "arvores" => $arvores, "erros" => array_values(array_unique($erros))];
}

// As árvores de um roteiro completas: as saídas que ele não define ganham a expressão padrão
function roteiro_completo($arvores)
{
    static $padrao = null;
    if ($padrao === null) {
        $padrao = [];
        foreach (ROTEIRO_SAIDAS as $nome => $expr) {
            $padrao[$nome] = formula_ler($expr);
        }
    }
    foreach ($padrao as $nome => $arv) {
        if (!isset($arvores[$nome])) {
            $arvores[$nome] = $arv;
        }
    }
    return $arvores;
}

// O roteiro de uma forma de escolha sozinha (o "Sortear este dia por", o critério do quadro Modo de rodízio): todos são
// candidatos; a nota e o peso conforme a forma; a garantia de rodízio, menos no aleatório
function roteiro_da_forma($forma)
{
    $linhas = ["inteligente" => "nota = nota_criterios\npeso = 0", "ponderado" => "nota = nota_criterios\npeso = MAX(1; nota_criterios)",
        "aleatorio" => "peso = 1\ngarantia = 0", "fifo" => "nota = dias_sem_uso\npeso = 0"];
    return $linhas[$forma] ?? $linhas["ponderado"];
}

// Num bloco de um relógio para o bloco inteiro, quantos dias ele fica a partir de um dia da semana ($dia, 1 segunda ... 7
// domingo): até o último dia do bloco, contado na ordem da semana (que começa no semana_inicio). O bloco que atravessa a
// virada da semana sem ter os sete dias (sábado e domingo, com a semana começando no domingo) conta do primeiro dia dele
// depois de um dia de fora: o fim de semana continua um trecho só
function bloco_seguidos($dias, $dia)
{
    $ini = semana_comeca();
    $antes = $ini === 1 ? 7 : $ini - 1;
    if (count(array_unique($dias)) < 7 && in_array($ini, $dias, true) && in_array($antes, $dias, true)) {
        for ($x = 1; $x <= 7; $x++) {
            if (!in_array($x, $dias, true) && in_array($x % 7 + 1, $dias, true)) {
                $ini = $x % 7 + 1;
                break;
            }
        }
    }
    $pos = function ($x) use ($ini) { return ($x - $ini + 7) % 7 + 1; };
    return max(array_map($pos, $dias)) - $pos($dia) + 1;
}

// O texto do roteiro de um modo que não é personalizado, gerado dos blocos e da forma de escolha: quem é candidato em
// cada dia da semana (o grupo ou o relógio fixo do bloco do dia), quantos dias fica (um por bloco: até o fim do bloco;
// na escala, a fórmula dias_seguidos), a nota, o peso e a garantia pela forma. Gerado a cada uso (os nomes dos grupos e
// dos relógios são os de agora)
function modo_roteiro_texto($modo, $blocos = null)
{
    if (($modo["tipo"] ?? "personalizado") === "personalizado" && trim((string)($modo["roteiro"] ?? "")) !== "") {
        return (string)$modo["roteiro"];
    }
    $blocos = $blocos === null ? modo_blocos((int)$modo["id"]) : $blocos;
    $escala = ($modo["escala_dias"] ?? null) !== null;
    $alvo = function ($b) {
        if (($b["relogio_id"] ?? null) !== null) {
            $nome = valor("SELECT nome FROM relogio WHERE id = ?", [(int)$b["relogio_id"]]);
            return $nome === null ? "0" : "RELOGIO(\"" . str_replace("\"", "'", $nome) . "\")";
        }
        $no = (int)($b["no_id"] ?? 0);
        return $no === 0 || $no === no_raiz() ? "1" : "NO_GRUPO(\"" . str_replace("\"", "'", no_caminho($no)) . "\")";
    };
    // por dia da semana: o alvo e os dias seguidos de cada dia
    $por_dia = array_fill(1, 7, ["0", 1]);
    foreach ($blocos as $b) {
        $dias = array_values(array_filter(array_map("intval", array_filter(explode(",", (string)$b["dias"]), "strlen")), function ($d) { return $d >= 1 && $d <= 7; }));
        foreach ($dias as $d) {
            if ($por_dia[$d][0] === "0") {
                $por_dia[$d] = [$alvo($b), ($b["um_por"] ?? "dia") === "bloco" ? bloco_seguidos($dias, $d) : 1];
            }
        }
    }
    $alvos = array_column($por_dia, 0);
    $seguidos = array_column($por_dia, 1);
    if (count(array_unique($alvos)) === 1) {
        $candidato = $alvos[0];
    } elseif ($alvos[0] === $alvos[1] && $alvos[1] === $alvos[2] && $alvos[2] === $alvos[3] && $alvos[3] === $alvos[4] && $alvos[5] === $alvos[6]) {
        $candidato = "SE(DIA_SEMANA() <= 5; " . $alvos[0] . "; " . $alvos[5] . ")";
    } else {
        $candidato = "ESCOLHA(DIA_SEMANA(); " . implode("; ", $alvos) . ")";
    }
    if ($escala) {
        $dias = "PADRAO(dias_seguidos; 1)";
    } elseif (count(array_unique($seguidos)) === 1) {
        $dias = (string)$seguidos[0];
    } elseif ($seguidos === [5, 4, 3, 2, 1, 1, 1]) {
        $dias = "SE(DIA_SEMANA() <= 5; 6 - DIA_SEMANA(); 1)";
    } elseif ($seguidos === [7, 6, 5, 4, 3, 2, 1]) {
        $dias = "8 - DIA_SEMANA()";
    } else {
        $dias = "ESCOLHA(DIA_SEMANA(); " . implode("; ", $seguidos) . ")";
    }
    $forma = $escala ? "inteligente" : (string)($modo["selecao"] ?? "ponderado");
    return "candidato = " . $candidato . "\n" . roteiro_da_forma($forma) . "\ndias = " . $dias . ($forma === "aleatorio" ? "" : "\ngarantia = " . ROTEIRO_SAIDAS["garantia"]);
}

// As árvores do roteiro que vale para um modo (o personalizado: o texto dele; os outros: o gerado), completas; guardadas
// por requisição. Um roteiro personalizado com erro: as linhas boas valem e as outras ficam no padrão
function modo_roteiro($modo)
{
    static $cache = [];
    $k = (int)colecao_atual() . "|" . (int)$modo["id"] . "|" . md5(modo_roteiro_texto($modo));
    if (!isset($cache[$k])) {
        $cache[$k] = roteiro_completo(roteiro_ler(modo_roteiro_texto($modo))["arvores"]);
    }
    return $cache[$k];
}

// As formas de escolha do sorteio (a selecao do modo), com o nome que as telas e os motivos usam
function formas_de_escolha()
{
    return ["inteligente" => t("a maior nota"), "ponderado" => t("sorteio pela nota"), "aleatorio" => t("aleatório, todos com a mesma chance"), "fifo" => t("fila: o mais tempo sem uso")];
}

// O roteiro calculado para um relógio num dia: as cinco saídas e as linhas do roteiro, como números (null: vazio)
function roteiro_calcular($arvores, $r, $dia, $m_ini)
{
    $ctx = ["r" => $r, "momento" => $m_ini, "dia" => $dia, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"]), "locais" => $arvores];
    $res = [];
    foreach (array_keys($arvores) as $nome) {
        $v = variavel_valor($nome, $ctx);
        $res[$nome] = is_numeric($v) ? (float)$v : ($v === null ? null : $v);
    }
    $dsu = variavel_valor("dias_sem_uso", $ctx);
    $res["dias_sem_uso"] = is_numeric($dsu) ? (float)$dsu : 9999.0;
    $res["nunca"] = nunca_no_pulso($ctx);
    return $res;
}

// A escolha de um dia pelo roteiro, entre os candidatos ($cands: id => relógio, já sem o que ficou de fora à mão, com as
// frases disso em $fora). Nesta ordem: o roteiro diz quem é candidato no dia (candidato = 0 sai); entre os candidatos, o de
// ontem fica de fora (pelo plano, se houver outro candidato; $ontem_fora) e o ciclo do modo, se o modo usa, tira quem já
// passou na semana (se sobrar algum); quem tem
// garantia = 1 passa na frente (o mais tempo parado primeiro; empate: o cadastrado primeiro); senão o peso: todos 0, a
// maior nota (empate: o cadastrado primeiro); todos iguais, sorteio simples; senão, sorteio com o peso de cada um como
// chance. $roteiro: as árvores completas, ou a chave de uma forma de escolha (inteligente, ponderado, aleatorio, fifo), que
// vira o roteiro dela. $estilo: a forma, para a frase do motivo (null: pelo modo; "roteiro": as frases gerais). A nota e
// os dias sem uso são os do começo do dia ($m_ini), com os dias anteriores do plano já simulados por quem chama. $modo
// pode ser null (sem ciclo). Devolve [o id ou null, o motivo, quantos dias seguidos o escolhido fica]
function escolha_do_dia($modo, $dia, $m_ini, $cands, $roteiro, $fora = [], $bloco_nome = "", $ontem_fora = true, $estilo = null)
{
    if (is_string($roteiro)) {
        $estilo = $estilo ?? $roteiro;
        $roteiro = roteiro_completo(roteiro_ler(roteiro_da_forma($roteiro))["arvores"]);
    } elseif ($estilo === null) {
        $estilo = $modo && ($modo["tipo"] ?? "personalizado") !== "personalizado" ? (($modo["escala_dias"] ?? null) !== null ? "inteligente" : (string)$modo["selecao"]) : "roteiro";
    }
    $escolhido = null;
    $motivo = "";
    // o roteiro em cada relógio: quem não é candidato no dia sai. Só depois, e só entre os candidatos, as regras de não
    // repetir (o de ontem, o ciclo): cada uma só tira se sobrar outro candidato; senão ele repete, e o motivo diz por quê
    $info = [];
    foreach ($cands as $cid => $r) {
        $c = roteiro_calcular($roteiro, $r, $dia, $m_ini);
        if (($c["candidato"] ?? null) !== null && (float)$c["candidato"] != 0) {
            $info[$cid] = ["nota" => (float)($c["nota"] ?? 0), "peso" => max(0.0, (float)($c["peso"] ?? 0)), "garantia" => ($c["garantia"] ?? null) !== null && (float)$c["garantia"] != 0,
                "dias" => is_numeric($c["dias"] ?? null) ? max(1, min(366, (int)floor((float)$c["dias"]))) : 1, "dsu" => $c["dias_sem_uso"], "nunca" => $c["nunca"]];
        }
    }
    if (count($info) === 0) {
        return [null, "", 1];
    }
    // o de ontem fica de fora (pelo plano), se houver outro candidato; o único candidato repete. $base: os relógios do ciclo
    // (os disponíveis, menos o de ontem quando ele saiu)
    $base = $cands;
    if ($ontem_fora) {
        $ontem = valor("SELECT relogio_id FROM plano WHERE data = ?", [$dia->modify("-1 day")->format("Y-m-d")]);
        if ($ontem !== null && isset($info[(int)$ontem])) {
            if (count($info) > 1) {
                $fora[] = t("o de ontem ({1}) ficou de fora", [1 => $cands[(int)$ontem]["nome"]]);
                unset($info[(int)$ontem], $base[(int)$ontem]);
            } else {
                $fora[] = t("o de ontem ({1}) repetiu: é o único candidato do dia", [1 => $cands[(int)$ontem]["nome"]]);
            }
        }
    }
    // o ciclo (se o modo usa): os candidatos que já passaram no ciclo atual ficam de fora, pelo plano desde o primeiro dia da
    // semana (semana_inicio: a segunda ou o domingo, pela Configuração); se todos os candidatos já passaram, nenhum sai
    if ($modo && (int)($modo["ciclo"] ?? 0) === 1 && count($info) > 1) {
        $seq = array_map(function ($x) { return (int)$x["relogio_id"]; }, linhas("SELECT relogio_id FROM plano WHERE data BETWEEN ? AND ? ORDER BY data",
            [semana_inicio($dia)->format("Y-m-d"), $dia->modify("-1 day")->format("Y-m-d")]));
        $restam = array_diff_key($info, array_flip(ciclo_atual($seq, array_keys($base))));
        if (count($restam) > 0 && count($restam) < count($info)) {
            $fora[] = t("pelo ciclo, {1} que já passaram na semana ficaram de fora", [1 => count($info) - count($restam)]);
            $info = $restam;
        } elseif (count($restam) === 0) {
            $fora[] = t("todos os candidatos do dia já passaram na semana: o ciclo não tirou nenhum");
        }
    }
    $entre = count($info) === 1 ? ($bloco_nome !== "" ? t("único candidato do bloco {1}", [1 => $bloco_nome]) : t("único candidato")) : t("entre {1} candidatos", [1 => count($info)]);
    $max = (int)cfg("max_sem_uso");
    $parados = array_filter($info, function ($x) { return $x["garantia"]; });
    if (count($parados) > 0) {
        uasort($parados, function ($x, $y) { return $y["dsu"] <=> $x["dsu"]; });
        $escolhido = (int)array_key_first($parados);
        // os empatados com ele no tempo parado (os nunca usados empatam entre si): sai o cadastrado primeiro
        $empatados = [];
        foreach ($parados as $cid => $x) {
            if ($cid !== $escolhido && $x["dsu"] == $parados[$escolhido]["dsu"]) {
                $empatados[] = $cands[$cid]["nome"];
            }
        }
        $empate = count($empatados) > 0 ? t(" Empatado com {1}: saiu o cadastrado primeiro.", [1 => implode(", ", $empatados)]) : "";
        $motivo = $estilo === "roteiro"
            ? t("Garantia do roteiro: {1}{2}.{3}", [1 => motivo_parado($parados[$escolhido]["dsu"], $parados[$escolhido]["nunca"]), 2 => count($parados) > 1 ? t(" ({1} com a garantia; sai o mais tempo parado)", [1 => count($parados)]) : "", 3 => $empate])
            : t("Garantia de rodízio: {1}, além do limite de {2} dias sem uso{3}.{4}", [1 => motivo_parado($parados[$escolhido]["dsu"], $parados[$escolhido]["nunca"]), 2 => $max, 3 => count($parados) > 1 ? t(" ({1} além do limite; sai o mais tempo parado)", [1 => count($parados)]) : "", 4 => $empate]);
    } else {
        $pesos = array_column($info, "peso");
        if (max($pesos) <= 0) {
            // sem peso: a maior nota (a ordem dos candidatos é a do cadastro: o empate fica com o cadastrado primeiro)
            uasort($info, function ($x, $y) { return $y["nota"] <=> $x["nota"]; });
            $escolhido = (int)array_key_first($info);
            $motivo = $estilo === "fifo"
                ? t("Fila: o mais tempo sem uso ({1}), {2}.", [1 => motivo_parado($info[$escolhido]["dsu"], $info[$escolhido]["nunca"]), 2 => $entre])
                : t("A maior nota ({1}), {2}.", [1 => motivo_nota($info[$escolhido]["nota"]), 2 => $entre]);
        } elseif (count(array_unique(array_map(function ($p) { return (int)round($p * 100); }, $pesos))) === 1) {
            $ids = array_keys($info);
            $escolhido = $ids[sorteio(0, count($ids) - 1)];
            $motivo = t("Sorteio simples, {1}, todos com a mesma chance ({2}).", [1 => $entre, 2 => aleatorio_fonte()]);
        } else {
            // com sorteio: o peso de cada um é a chance dele (peso 0: nenhuma)
            $total = 0;
            foreach ($info as $x) {
                $total += (int)round($x["peso"] * 100);
            }
            $alvo = sorteio(1, max(1, $total));
            foreach ($info as $cid => $x) {
                $alvo -= (int)round($x["peso"] * 100);
                if ($escolhido === null && $alvo <= 0 && $x["peso"] > 0) {
                    $escolhido = $cid;
                }
            }
            if ($escolhido !== null) {
                $chance = motivo_nota(round($info[$escolhido]["peso"] * 100) * 100 / max(1, $total));
                $motivo = $estilo === "ponderado"
                    ? t("Sorteio pela nota, {1}: nota {2}, {3}% de chance ({4}).", [1 => $entre, 2 => motivo_nota($info[$escolhido]["nota"]), 3 => $chance, 4 => aleatorio_fonte()])
                    : t("Sorteio pelo peso, {1}: peso {2}, {3}% de chance ({4}).", [1 => $entre, 2 => motivo_nota($info[$escolhido]["peso"]), 3 => $chance, 4 => aleatorio_fonte()]);
            }
        }
    }
    if (count($fora) > 0 && $motivo !== "") {
        $motivo .= " " . ucfirst(implode("; ", $fora)) . ".";
    }
    return [$escolhido, $motivo, $escolhido !== null ? $info[$escolhido]["dias"] : 1];
}

// O dia em que a semana começa (a Configuração, semana_inicio: segunda, o padrão, ou domingo), no número do DIA_SEMANA
// (1 segunda ... 7 domingo). Vale para o rodízio inteiro: o horizonte do plano, o ciclo, a próxima semana e os blocos
function semana_comeca()
{
    return cfg("semana_inicio") === "domingo" ? 7 : 1;
}

// O primeiro e o último dia da semana de um dia, pela semana_comeca
function semana_inicio($dia)
{
    return $dia->modify("-" . (((int)$dia->format("N") - semana_comeca() + 7) % 7) . " days");
}

function semana_fim($dia)
{
    return semana_inicio($dia)->modify("+6 days");
}

// Até que dia o plano de um modo sem escala vem montado: o último dia da última das semanas do modo (semanas: 1, esta
// semana; 2, também a que vem...; a semana começa no dia da Configuração, semana_inicio). Com uma semana só, no último dia
// da semana o plano traz também o primeiro da seguinte (para o aviso da véspera)
function horizonte_do_modo($modo, $hoje)
{
    $semanas = max(1, min(8, (int)($modo["semanas"] ?? 1)));
    $fim = semana_fim($hoje);
    if ($semanas === 1) {
        return $fim->format("Y-m-d") === $hoje->format("Y-m-d") ? $hoje->modify("+1 day") : $fim;
    }
    return $fim->modify("+" . (($semanas - 1) * 7) . " days");
}

// Os dias que o sorteio não mexe, de $de em diante (até $ate, se vier): os escolhidos à mão e os livres; ["mao" => N,
// "livres" => N] (o Sortear de novo diz quantos ficaram, ou quantos saíram com sobrescrever)
function dias_guardados($de, $ate = null)
{
    $faixa = $ate === null ? " >= ?" : " BETWEEN ? AND ?";
    $p = $ate === null ? [$de->format("Y-m-d")] : [$de->format("Y-m-d"), $ate->format("Y-m-d")];
    return ["mao" => (int)valor("SELECT COUNT(*) FROM plano WHERE origem = 'manual' AND data" . $faixa, $p),
        "livres" => (int)valor("SELECT COUNT(*) FROM plano_livre WHERE data" . $faixa, $p)];
}

// O fim da mensagem do Sortear de novo: os dias à mão e os livres que ficaram como estavam (e como sortear também esses)
// ou, com sobrescrever, os que saíram
function dias_guardados_texto($g, $sobrescrever)
{
    $partes = [];
    if ($g["mao"] > 0) {
        $partes[] = $g["mao"] === 1 ? t("1 dia escolhido à mão") : t("{1} dias escolhidos à mão", [1 => $g["mao"]]);
    }
    if ($g["livres"] > 0) {
        $partes[] = $g["livres"] === 1 ? t("1 dia livre") : t("{1} dias livres", [1 => $g["livres"]]);
    }
    if (count($partes) === 0) {
        return "";
    }
    return $sobrescrever ? " " . t("Sobrescritos: {1}.", [1 => implode(t(" e "), $partes)])
        : " " . t("Ficaram como estavam: {1} (para sortear também esses, marque Sobrescrever tudo).", [1 => implode(t(" e "), $partes)]);
}

// As posições de uma leitura do desvio da hora (onde o relógio ficou desde a leitura anterior: a condição da medição),
// código => texto, na ordem das listas
function posicoes_marcha()
{
    return ["misto" => t("pulso e guardado (como o rodízio fez)"), "pulso" => t("no pulso"), "mostrador_cima" => t("guardado, mostrador para cima"),
        "mostrador_baixo" => t("guardado, mostrador para baixo"), "coroa_cima" => t("guardado, coroa para cima"), "coroa_baixo" => t("guardado, coroa para baixo"),
        "coroa_esquerda" => t("guardado, coroa para a esquerda"), "coroa_direita" => t("guardado, coroa para a direita"), "winder" => t("no winder")];
}

// Os pontos da precisão de um relógio, na ordem: as leituras do desvio da hora (desvio em segundos, + adiantado) e os acertos
// da hora (o relógio volta a 0 s), dos tipos de lançamento que medem a marcha (lancamento_tipo.marcha)
function precisao_pontos($rid)
{
    return array_map(function ($l) {
        return ["id" => (int)$l["id"], "instante" => strtotime($l["inicio"]), "inicio" => $l["inicio"], "tipo" => $l["marcha"],
            "desvio" => $l["marcha"] === "acerto" ? 0.0 : (float)$l["valor"], "posicao" => $l["posicao"]];
    }, linhas("SELECT l.id, l.inicio, l.valor, l.posicao, t.marcha FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.relogio_id = ? AND t.marcha IS NOT NULL ORDER BY l.inicio, l.id", [(int)$rid]));
}

// Os intervalos medidos: de cada ponto até a próxima leitura do desvio (um acerto da hora fecha a conta de antes e recomeça
// de 0 s nele). A marcha do intervalo é a diferença dos desvios sobre o tempo, em segundos por dia; a posição é a da leitura
// do fim (onde ele ficou no intervalo)
function precisao_intervalos($pontos)
{
    $res = [];
    $ant = null;
    foreach ($pontos as $p) {
        if ($ant !== null && $p["tipo"] === "desvio" && $p["instante"] > $ant["instante"]) {
            $dias = ($p["instante"] - $ant["instante"]) / 86400;
            $res[] = ["id" => $p["id"], "de" => $ant["instante"], "ate" => $p["instante"], "dias" => $dias, "desvio_de" => $ant["desvio"], "desvio_ate" => $p["desvio"],
                "marcha" => ($p["desvio"] - $ant["desvio"]) / $dias, "posicao" => $p["posicao"], "depois_do_acerto" => $ant["tipo"] === "acerto"];
        }
        $ant = $p;
    }
    return $res;
}

// A marcha dos intervalos que terminam nos últimos $dias dias antes de $momento (0: todos), na $posicao ("": todas): a média
// pesada pelo tempo (a soma das diferenças sobre a soma dos dias: um intervalo de 10 dias pesa dez vezes o de 1 dia, e a
// leitura errada por 1 s quase não pesa), o desvio padrão das marchas (pesado igual; null com menos de dois) e quantos
function precisao_marcha($ints, $dias, $posicao, $momento)
{
    $sel = array_values(array_filter($ints, function ($x) use ($dias, $posicao, $momento) {
        return ($dias <= 0 || $x["ate"] > $momento - $dias * 86400) && ($posicao === "" || $x["posicao"] === $posicao);
    }));
    $soma_dias = array_sum(array_column($sel, "dias"));
    if (count($sel) === 0 || $soma_dias <= 0) {
        return ["n" => 0, "marcha" => null, "dp" => null, "dias" => 0.0];
    }
    $media = array_sum(array_map(function ($x) { return $x["desvio_ate"] - $x["desvio_de"]; }, $sel)) / $soma_dias;
    $dp = count($sel) < 2 ? null : sqrt(array_sum(array_map(function ($x) use ($media) { return $x["dias"] * ($x["marcha"] - $media) ** 2; }, $sel)) / $soma_dias);
    return ["n" => count($sel), "marcha" => $media, "dp" => $dp, "dias" => $soma_dias];
}

// O desvio estimado num instante: o último ponto até ele (a leitura, ou 0 do acerto) mais a marcha de todos os intervalos
// vezes o tempo desde o ponto; sem intervalo medido, o do último ponto; null sem ponto nenhum
function precisao_desvio_em($pontos, $ints, $momento)
{
    if (count($pontos) === 0) {
        return null;
    }
    $u = $pontos[count($pontos) - 1];
    $m = precisao_marcha($ints, 0, "", $momento)["marcha"];
    return $u["desvio"] + ($m === null ? 0.0 : $m * ($momento - $u["instante"]) / 86400);
}

// O desvio pela hora que o relógio mostrava ($marca: HH:MM, HH:MM:SS ou HH:MM:SS.d) no instante da leitura ($instante_ms: o
// da hora certa na tela, em milissegundos, de até 10 minutos atrás; vazio ou fora disso: agora), na hora local do pedido.
// O mostrador dá a volta em 12 horas: o desvio fica entre −6 h e +6 h. Devolve [desvio em segundos, o instante (segundos
// inteiros)], ou [null, null] com a marca inválida
function desvio_da_marca($marca, $instante_ms, $agora)
{
    if (preg_match("/^\\s*(\\d{1,2}):(\\d{2})(?::(\\d{2})(?:[.,](\\d+))?)?\\s*$/", $marca, $m) !== 1 || (int)$m[1] > 23 || (int)$m[2] > 59 || (int)($m[3] ?? 0) > 59) {
        return [null, null];
    }
    $marca_s = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)($m[3] ?? 0) + (isset($m[4]) && $m[4] !== "" ? (float)("0." . $m[4]) : 0.0);
    $inst = is_numeric($instante_ms) && (float)$instante_ms / 1000 >= $agora - 600 && (float)$instante_ms / 1000 <= $agora + 60 ? (float)$instante_ms / 1000 : (float)$agora;
    $base = (int)floor($inst);
    $ref_s = (int)date("H", $base) * 3600 + (int)date("i", $base) * 60 + (int)date("s", $base) + ($inst - $base);
    $dif = fmod($marca_s - $ref_s, 43200);
    $dif = $dif > 21600 ? $dif - 43200 : ($dif <= -21600 ? $dif + 43200 : $dif);
    return [round($dif, 1), $base];
}

// O fim da mensagem de uma leitura do desvio ou de um acerto da hora: a marcha medida desde a leitura anterior, ou o que falta
function precisao_recado($rid, $id, $marcha)
{
    if ($marcha === "acerto") {
        return t(" A conta da marcha recomeça daqui, de 0 s.");
    }
    $ints = precisao_intervalos(precisao_pontos($rid));
    foreach ($ints as $x) {
        if ($x["id"] === (int)$id) {
            return t(" Marcha desde {1}: {2} em {3} dias.", [1 => data_txt($x["de"], true, true), 2 => marcha_txt($x["marcha"]), 3 => decimal_txt($x["dias"], 2)])
                . ($x["dias"] < 1 ? t(" Com menos de um dia, a leitura errada por 1 s pesa muito: a média pesa cada intervalo pelo tempo.") : "");
        }
    }
    return t(" Primeira leitura: leia de novo daqui a pelo menos um dia para medir a marcha.");
}

// Textos da precisão: a marcha com sinal ("+12,3 s/dia"), o desvio por extenso ("34 s adiantado", "na hora")
function marcha_txt($m)
{
    return $m === null ? null : t("{1} s/dia", [1 => ($m > 0.05 ? "+" : "") . decimal_txt($m, 1)]);
}

function desvio_txt($d)
{
    if ($d === null) {
        return null;
    }
    return abs($d) < 0.5 ? t("na hora") : ($d > 0 ? t("{1} s adiantado", [1 => decimal_txt(abs($d), 0)]) : t("{1} s atrasado", [1 => decimal_txt(abs($d), 0)]));
}

// A precisão de um relógio (o quadro Precisão do painel e recurso=precisao) até $agora; null se nenhum tipo de lançamento dele
// mede a marcha. Os pontos (as leituras do desvio e os acertos da hora), os intervalos (do mais novo ao mais velho), a marcha
// de todos (pesada pelo tempo), quanto as marchas variam, a última, o desvio estimado agora, a marcha em cada posição, a marcha
// esperada (os campos marcha_min e marcha_max, de onde vierem: o relógio, o grupo) e as posições para a lista da tela
function precisao_do_relogio($r, $agora)
{
    $desvio = null;
    $acerto = null;
    foreach (lancamento_tipos_do_relogio($r) as $ident => $t) {
        if (($t["marcha"] ?? null) === "desvio" && $desvio === null) {
            $desvio = $ident;
        } elseif (($t["marcha"] ?? null) === "acerto" && $acerto === null) {
            $acerto = $ident;
        }
    }
    if ($desvio === null) {
        return null;
    }
    $pos = posicoes_marcha();
    $pontos = array_values(array_filter(precisao_pontos((int)$r["id"]), function ($p) use ($agora) { return $p["instante"] <= $agora; }));
    $ints = precisao_intervalos($pontos);
    $todos = precisao_marcha($ints, 0, "", $agora);
    $ultima = count($ints) > 0 ? $ints[count($ints) - 1]["marcha"] : null;
    $agora_d = precisao_desvio_em($pontos, $ints, $agora);
    $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
    $esperada = [];
    foreach (["de" => "marcha_min", "ate" => "marcha_max"] as $k => $campo) {
        $x = isset(campos_todos()[$campo]) ? variavel_valor($campo, $ctx) : null;
        $esperada[$k] = is_numeric($x) ? (float)$x : null;
    }
    $esperada["texto"] = $esperada["de"] === null && $esperada["ate"] === null ? null
        : ($esperada["ate"] === null ? t("a partir de {1}", [1 => marcha_txt($esperada["de"])]) : ($esperada["de"] === null ? t("até {1}", [1 => marcha_txt($esperada["ate"])])
        : t("de {1} a {2}", [1 => marcha_txt($esperada["de"]), 2 => marcha_txt($esperada["ate"])])));
    $dentro = $ultima === null || $esperada["texto"] === null ? null
        : (($esperada["de"] === null || $ultima >= $esperada["de"]) && ($esperada["ate"] === null || $ultima <= $esperada["ate"]));
    $por_posicao = [];
    foreach ($pos as $k => $txt) {
        $m = precisao_marcha($ints, 0, $k, $agora);
        if ($m["n"] > 0) {
            $por_posicao[] = ["posicao" => $k, "posicao_texto" => $txt, "medicoes" => $m["n"], "dias" => round($m["dias"], 2), "marcha" => round($m["marcha"], 2), "marcha_texto" => marcha_txt($m["marcha"])];
        }
    }
    if ($todos["n"] === 0) {
        $resumo = count($pontos) === 0 ? t("Nenhuma leitura ainda: leia o desvio, espere pelo menos um dia e leia de novo.")
            : t("Uma leitura só: leia de novo daqui a pelo menos um dia para medir a marcha.");
    } else {
        $m = $todos["marcha"];
        $resumo = (abs($m) < 0.05 ? t("Na hora: não adianta nem atrasa") : ($m > 0 ? t("Adianta {1} s por dia", [1 => decimal_txt($m, 1)]) : t("Atrasa {1} s por dia", [1 => decimal_txt(abs($m), 1)])))
            . ($todos["n"] === 1 ? t(" (uma medição, de {1} dias).", [1 => decimal_txt($todos["dias"], 1)])
            : t(" (média de {1} medições em {2} dias; elas variam ±{3} s/dia).", [1 => $todos["n"], 2 => decimal_txt($todos["dias"], 1), 3 => decimal_txt($todos["dp"], 1)]));
    }
    return ["tipo_desvio" => $desvio, "tipo_acerto" => $acerto, "resumo" => $resumo,
        "medicoes" => $todos["n"], "dias" => round($todos["dias"], 2),
        "marcha" => $todos["marcha"] === null ? null : round($todos["marcha"], 2), "marcha_texto" => marcha_txt($todos["marcha"]),
        "marcha_dp" => $todos["dp"] === null ? null : round($todos["dp"], 2), "marcha_dp_texto" => $todos["dp"] === null ? null : t("±{1} s/dia", [1 => decimal_txt($todos["dp"], 1)]),
        "ultima" => $ultima === null ? null : round($ultima, 2), "ultima_texto" => marcha_txt($ultima),
        "desvio_agora" => $agora_d === null ? null : round($agora_d, 1), "desvio_agora_texto" => desvio_txt($agora_d),
        "esperada" => $esperada, "dentro" => $dentro, "por_posicao" => $por_posicao,
        "pontos" => array_map(function ($p) use ($pos) {
            return ["id" => $p["id"], "inicio" => date("Y-m-d H:i:s", $p["instante"]), "tipo" => $p["tipo"], "desvio" => round($p["desvio"], 2),
                "posicao" => $p["posicao"], "posicao_texto" => $p["posicao"] === null ? null : ($pos[$p["posicao"]] ?? $p["posicao"])];
        }, $pontos),
        "intervalos" => array_reverse(array_map(function ($x) use ($pos) {
            return ["id" => $x["id"], "de" => date("Y-m-d H:i:s", $x["de"]), "ate" => date("Y-m-d H:i:s", $x["ate"]), "dias" => round($x["dias"], 3),
                "desvio_de" => round($x["desvio_de"], 2), "desvio_ate" => round($x["desvio_ate"], 2), "marcha" => round($x["marcha"], 2), "marcha_texto" => marcha_txt($x["marcha"]),
                "posicao" => $x["posicao"], "posicao_texto" => $x["posicao"] === null ? t("não informada") : ($pos[$x["posicao"]] ?? $x["posicao"]), "depois_do_acerto" => $x["depois_do_acerto"]];
        }, $ints)),
        "posicoes" => array_map(function ($k) use ($pos) { return ["valor" => $k, "texto" => $pos[$k]]; }, array_keys($pos))];
}

// O plano de $desde em diante, para comparar antes e depois de uma gravação do rodízio: [AAAA-MM-DD => [id, nome, motivo]],
// com id null num dia livre. Um dia sem relógio e sem ser livre não entra
function plano_retrato($desde)
{
    $r = [];
    foreach (linhas("SELECT p.data, p.relogio_id, p.motivo, r.nome FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data >= ?", [$desde->format("Y-m-d")]) as $l) {
        $r[substr((string)$l["data"], 0, 10)] = [(int)$l["relogio_id"], (string)$l["nome"], $l["motivo"]];
    }
    foreach (dias_livres($desde->format("Y-m-d"), "") as $l) {
        $r[$l] = $r[$l] ?? [null, "", null];
    }
    ksort($r);
    return $r;
}

// O que uma gravação do rodízio mudou no plano (dois retratos do plano_retrato): cada dia que trocou de relógio, virou livre,
// deixou de ser livre, ganhou ou perdeu o relógio, com o de antes, o de depois e o motivo do novo; e quantos dias ficaram com o
// mesmo relógio (o motivo pode ter mudado: o sorteio foi refeito, e saiu o mesmo)
function plano_mudancas($antes, $depois)
{
    $curto = [1 => t("seg"), 2 => t("ter"), 3 => t("qua"), 4 => t("qui"), 5 => t("sex"), 6 => t("sáb"), 7 => t("dom")];
    $estado = function ($x) { return $x === null ? "vazio" : ($x[0] === null ? "livre" : "relogio"); };
    $nome = function ($x) { return $x === null ? t("sem plano") : ($x[0] === null ? t("livre") : $x[1]); };
    $datas = array_unique(array_merge(array_keys($antes), array_keys($depois)));
    sort($datas);
    $dias = [];
    $iguais = 0;
    foreach ($datas as $data) {
        $a = $antes[$data] ?? null;
        $d = $depois[$data] ?? null;
        if ($a !== null && $d !== null && $a[0] === $d[0]) {
            $iguais++;
            continue;
        }
        $n = (int)date("N", strtotime($data));
        $dias[] = ["data" => $data, "dia_semana_curto" => $curto[$n],
            "antes" => $estado($a), "antes_relogio_id" => $a === null ? null : $a[0], "antes_relogio" => $a === null || $a[0] === null ? null : $a[1],
            "depois" => $estado($d), "depois_relogio_id" => $d === null ? null : $d[0], "depois_relogio" => $d === null || $d[0] === null ? null : $d[1],
            "antes_texto" => $nome($a), "depois_texto" => $nome($d), "motivo" => $d === null || $d[0] === null ? null : $d[2],
            "texto" => t("{1} {2}: {3} → {4}", [1 => $curto[$n], 2 => date("d/m", strtotime($data)), 3 => $nome($a), 4 => $nome($d)])];
    }
    $texto = count($dias) === 0 ? t("Nenhum dia mudou.") : (count($dias) === 1 ? t("1 dia mudou") : t("{1} dias mudaram", [1 => count($dias)]))
        . (count($dias) === 0 ? "" : ($iguais === 0 ? "." : ($iguais === 1 ? t("; 1 ficou com o mesmo relógio.") : t("; {1} ficaram com o mesmo relógio.", [1 => $iguais]))));
    return ["mudaram" => count($dias), "iguais" => $iguais, "texto" => $texto, "dias" => $dias];
}

// O bloco do modo que cobre um dia da semana (o primeiro), ou null
function bloco_do_dia($blocos, $dia)
{
    foreach ($blocos as $b) {
        if (in_array($dia->format("N"), explode(",", (string)$b["dias"]), true)) {
            return $b;
        }
    }
    return null;
}

// Monta o plano de um modo, de $de até $ate, pelo roteiro dele, dia a dia (o laço começa em $hoje para os dias que já
// estão no plano virarem uso simulado antes dos que faltam). Cada dia sem plano (e não livre) é escolhido entre os
// relógios disponíveis (escolha_do_dia: o candidato, o de ontem fora, o ciclo, a garantia, o peso); o escolhido fica os
// dias seguidos que o roteiro deu, enquanto os dias seguintes estiverem vagos, não livres e ele continuar candidato neles.
// Cada dia do plano vira uma sessão simulada no pulso no horário de uso: fora da escala, só o uso (a carga não é gasta,
// porque não se sabe se o relógio vai ser carregado); na escala ($escala), a sessão inteira, e os avisos do relógio
// escolhido (os de "uso" e "sempre") são conferidos no começo do bloco de dias dele e no fim de cada dia, os de "sempre"
// também nos outros relógios no começo de cada dia; o que vence vira o "o que fazer" do dia (plano.acao), e o lançamento
// que resolve o aviso é simulado terminando no começo do dia. Devolve quantos dias gravou
function plano_montar($modo, $hoje, $de, $ate, $escala)
{
    $roteiro = modo_roteiro($modo);
    $blocos = modo_blocos((int)$modo["id"]);
    $rels = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY id") as $r) {
        $rels[(int)$r["id"]] = $r;
    }
    $agora = time();
    $pulso = lancamento_tipos()["pulso"] ?? null;
    $gravados = 0;
    $simulados = [];
    $acoes = [];
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
    $sessao = function ($rid, $d) use ($pulso, $agora, $escala, &$simulados) {
        $data = $d->format("Y-m-d");
        $fim_u = instante($data, cfg("uso_fim"));
        if ($pulso && !isset($simulados[$data]) && $fim_u > $agora && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ?", [(int)$pulso["id"], $data]) === null) {
            simular_lancamento($rid, "pulso", max($agora, instante($data, cfg("uso_inicio"))), $fim_u, null, !$escala);
            $simulados[$data] = true;
        }
    };
    for ($d = $hoje; $d <= $ate; $d = $d->modify("+1 day")) {
        $data = $d->format("Y-m-d");
        $m_ini = max($agora, instante($data, cfg("uso_inicio")));
        $p = plano_do_dia($data);
        if ($p === null && $d >= $de && !dia_livre($data) && count($rels) > 0) {
            $bloco = bloco_do_dia($blocos, $d);
            [$id, $motivo, $dias] = escolha_do_dia($modo, $d, $m_ini, $rels, $roteiro, [], $bloco ? $bloco["nome"] : "");
            if ($id !== null) {
                // os dias seguidos: enquanto o dia seguinte estiver vago, não livre e ele continuar candidato (fora da escala, mesmo
                // depois do horizonte: um relógio para a semana inteira fica a semana inteira, montado o plano em qualquer dia)
                $fim_b = $d;
                for ($n = 1; $n < $dias; $n++) {
                    $prox = $fim_b->modify("+1 day");
                    if (($escala && $prox > $ate) || plano_do_dia($prox->format("Y-m-d")) !== null || dia_livre($prox->format("Y-m-d"))) {
                        break;
                    }
                    $c = roteiro_calcular(["candidato" => $roteiro["candidato"]], $rels[$id], $prox, max($agora, instante($prox->format("Y-m-d"), cfg("uso_inicio"))));
                    if (($c["candidato"] ?? null) === null || (float)$c["candidato"] == 0) {
                        break;
                    }
                    $fim_b = $prox;
                }
                $total = (int)$d->diff($fim_b)->days + 1;
                for ($k = $d, $i = 1; $k <= $fim_b; $k = $k->modify("+1 day"), $i++) {
                    $bk = bloco_do_dia($blocos, $k);
                    $m = $i === 1 ? $motivo . ($total > 1 ? t(" Fica {1} dias seguidos.", [1 => $total]) : "") : t("Segue no pulso: dia {1} de {2} seguidos.", [1 => $i, 2 => $total]);
                    plano_gravar($k->format("Y-m-d"), $id, $bk ? (int)$bk["id"] : null, "sorteio", $m !== "" ? corta_texto($m, 300) : null);
                    $gravados++;
                    if ($escala) {
                        $sessao($id, $k);
                    }
                }
                if ($escala) {
                    // os avisos do escolhido, conferidos no começo do bloco e no fim de cada dia dele: o que vence vira ação do dia
                    $pontos = [$m_ini];
                    for ($k = $d; $k <= $fim_b; $k = $k->modify("+1 day")) {
                        $pontos[] = instante($k->format("Y-m-d"), cfg("uso_fim"));
                    }
                    $vencidos = [];
                    foreach ($pontos as $ponto) {
                        foreach (avisos_do_relogio($rels[$id], $ponto) as $a) {
                            if ($a["escala"] !== "nao" && $a["falta_dias"] <= 0 && !isset($vencidos[$a["identificador"]])) {
                                $vencidos[$a["identificador"]] = $a;
                            }
                        }
                    }
                    foreach ($vencidos as $a) {
                        $acoes[$data][] = [$id, $a];
                    }
                }
            }
            $p = plano_do_dia($data);
        }
        if ($p) {
            $sessao((int)$p["relogio_id"], $d);
        }
        if ($escala) {
            // os avisos de "sempre" nos outros relógios, no começo do dia; o que resolve cada aviso vencido é simulado
            // terminando no começo do dia, e o texto vai para o dia
            foreach ($rels as $cid => $r) {
                if (!$p || $cid !== (int)$p["relogio_id"]) {
                    foreach (avisos_do_relogio($r, $m_ini) as $a) {
                        if ($a["escala"] === "sempre" && $a["falta_dias"] <= 0) {
                            $acoes[$data][] = [$cid, $a];
                        }
                    }
                }
            }
            $textos = [];
            foreach ($acoes[$data] ?? [] as $x) {
                $a = $x[1];
                $t = $a["resolve"] !== null ? (lancamento_tipos()[$a["resolve"]] ?? null) : null;
                $horas = $t && $t["formato"] === "sessao" ? (float)($a["simula_horas"] ?? 0) : 0;
                if ($t && ($t["formato"] === "instantaneo" || $horas > 0 || ($t["formato"] === "valor" && $a["simula_valor"] !== null))) {
                    simular_lancamento($x[0], $a["resolve"], max($agora, (int)round($m_ini - $horas * 3600)), $m_ini, $a["simula_valor"]);
                }
                $textos[] = $a["nome"] . ": " . $rels[$x[0]]["nome"] . ($horas > 0 ? " (" . decimal_txt($horas, 6) . " h)" : "");
            }
            if ($p && $p["origem"] !== "manual" && $d >= $de) {
                sql("UPDATE plano SET acao = ? WHERE data = ?", [count($textos) > 0 ? corta_texto(implode("; ", $textos), 500) : null, $data]);
            }
        }
    }
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"]++;
    return $gravados;
}

// Monta o plano que falta, de hoje até $ate (padrão: o horizonte do modo: o último dia da última semana dele; no último
// dia da semana com uma semana só, também o primeiro da seguinte, para o aviso da véspera), pelo roteiro do modo ativo
// (plano_montar). Na escala inteligente (modo com escala_dias), a escala gera o próprio período de uma vez (gerar_escala): quando ainda não há
// escala, quando ela venceu, ou quando falta hoje ou amanhã e ela ainda não foi refeita hoje.
// $manter_pulso: hoje sem plano e com um relógio já no pulso pelo rodízio, o plano de hoje é ele; false só no sortear de
// novo inclusive hoje, que quer outro sorteio para hoje.
function garantir_plano($hoje, $ate = null, $manter_pulso = true)
{
    $modo = linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]);
    if ($modo) {
        // hoje sem plano, mas com um relógio já no pulso pelo rodízio: o plano de hoje é ele (não se sorteia outro)
        $no_pulso = $manter_pulso ? valor("SELECT l.relogio_id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE t.identificador = 'pulso' AND l.origem = 'rodizio' AND DATE(l.inicio) = ? ORDER BY l.inicio DESC, l.id DESC LIMIT 1", [$hoje->format("Y-m-d")]) : null;
        if (plano_do_dia($hoje->format("Y-m-d")) === null && $no_pulso !== null && !dia_livre($hoje->format("Y-m-d"))) {
            plano_gravar($hoje->format("Y-m-d"), (int)$no_pulso, null, "manual", t("Já estava no pulso pelo rodízio quando o plano de hoje foi montado."));
        }
        if ($modo["escala_dias"] !== null) {
            if (cfg("escala_fim") === "" || cfg("escala_fim") < $hoje->format("Y-m-d") || ((plano_do_dia($hoje->format("Y-m-d")) === null
                || plano_do_dia($hoje->modify("+1 day")->format("Y-m-d")) === null) && cfg("escala_gerada") !== hoje_da_colecao()->format("Y-m-d"))) {
                gerar_escala($hoje);
            }
            return;
        }
        plano_montar($modo, $hoje, $hoje, $ate === null ? horizonte_do_modo($modo, $hoje) : $ate, false);
    }
}

// Testa um roteiro num dia (a página Modos de rodízio, "Testar em todos os relógios"; recurso=roteiro): cada relógio
// disponível com as linhas do roteiro calculadas e as cinco saídas, e quem sairia nesse dia pela mesma escolha do
// rodízio (o de ontem fora; sem o ciclo), com os dias anteriores do plano simulados no pulso como no rodízio. $texto:
// o roteiro; $dia: o dia (DateTimeImmutable, de hoje em diante)
function roteiro_testar($texto, $dia)
{
    $lido = roteiro_ler($texto);
    $arv = roteiro_completo($lido["arvores"]);
    $hoje = hoje_da_colecao();
    $agora = time();
    $rels = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY id") as $r) {
        $rels[(int)$r["id"]] = $r;
    }
    $pulso = lancamento_tipos()["pulso"] ?? null;
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
    for ($d = $hoje; $d < $dia; $d = $d->modify("+1 day")) {
        $p = plano_do_dia($d->format("Y-m-d"));
        $fim_u = instante($d->format("Y-m-d"), cfg("uso_fim"));
        if ($p && $pulso && $fim_u > $agora && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ?",
            [(int)$pulso["id"], $d->format("Y-m-d")]) === null) {
            simular_lancamento((int)$p["relogio_id"], "pulso", max($agora, instante($d->format("Y-m-d"), cfg("uso_inicio"))), $fim_u, null, true);
        }
    }
    $m_ini = max($agora, instante($dia->format("Y-m-d"), cfg("uso_inicio")));
    $relogios = [];
    foreach ($rels as $cid => $r) {
        $c = roteiro_calcular($arv, $r, $dia, $m_ini);
        $valores = [];
        foreach (array_keys($arv) as $nome) {
            $valores[$nome] = isset($c[$nome]) && is_numeric($c[$nome]) ? round((float)$c[$nome], 4) : ($c[$nome] ?? null);
        }
        $relogios[] = ["id" => $cid, "nome" => $r["nome"], "candidato" => ($c["candidato"] ?? null) !== null && (float)$c["candidato"] != 0,
            "nota" => is_numeric($c["nota"] ?? null) ? round((float)$c["nota"], 2) : null, "peso" => is_numeric($c["peso"] ?? null) ? round((float)$c["peso"], 2) : null,
            "dias" => is_numeric($c["dias"] ?? null) ? max(1, min(366, (int)floor((float)$c["dias"]))) : 1, "garantia" => ($c["garantia"] ?? null) !== null && (float)$c["garantia"] != 0,
            "dias_sem_uso" => round($c["dias_sem_uso"], 1), "valores" => $valores];
    }
    [$id, $motivo, $dias] = escolha_do_dia(null, $dia, $m_ini, $rels, $arv, [], "", true, "roteiro");
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"]++;
    return ["dia" => $dia->format("Y-m-d"), "dia_semana" => (int)$dia->format("N"), "linhas" => array_map(function ($l) { return ["nome" => $l[0], "expressao" => $l[1]]; }, $lido["linhas"]),
        "erros" => $lido["erros"], "relogios" => $relogios,
        "escolhido" => $id === null ? null : ["relogio_id" => $id, "relogio" => $rels[$id]["nome"], "motivo" => $motivo, "dias" => $dias]];
}

// Sorteia um dia só, agora, por uma forma de escolha ($selecao: a chave de formas_de_escolha), entre os relógios disponíveis
// do bloco do modo ativo que cobre o dia (do grupo dele; o relógio fixo não vale: é um sorteio) ou, sem modo ou sem bloco no
// dia, entre todos. O relógio que o dia já tem fica de fora, se houver outro (quem sorteia o dia quer trocar). Os dias
// anteriores do plano, de hoje até a véspera, são simulados no pulso como no garantir_plano, para a nota e os dias sem uso
// contarem com eles. Devolve [o id ou null, o motivo]; quem chama grava o dia
function sortear_dia($dia, $selecao)
{
    $hoje = hoje_da_colecao();
    $agora = time();
    $modo = linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]) ?: null;
    $bloco = null;
    foreach ($modo ? modo_blocos((int)$modo["id"]) : [] as $b) {
        if ($bloco === null && in_array($dia->format("N"), explode(",", $b["dias"]), true)) {
            $bloco = $b;
        }
    }
    $cands = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY id") as $r) {
        if ($bloco === null || $bloco["no_id"] === null || in_array((int)$bloco["no_id"], no_cadeia($r["no_id"] ?? 0), true)) {
            $cands[(int)$r["id"]] = $r;
        }
    }
    $fora = [];
    $atual = plano_do_dia($dia->format("Y-m-d"));
    if ($atual && isset($cands[(int)$atual["relogio_id"]]) && count($cands) > 1) {
        $fora[] = t("o que o dia tinha ({1}) ficou de fora", [1 => $cands[(int)$atual["relogio_id"]]["nome"]]);
        unset($cands[(int)$atual["relogio_id"]]);
    }
    $pulso = lancamento_tipos()["pulso"] ?? null;
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
    for ($d = $hoje; $d < $dia; $d = $d->modify("+1 day")) {
        $p = plano_do_dia($d->format("Y-m-d"));
        $fim_u = instante($d->format("Y-m-d"), cfg("uso_fim"));
        if ($p && $pulso && $fim_u > $agora && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ?",
            [(int)$pulso["id"], $d->format("Y-m-d")]) === null) {
            simular_lancamento((int)$p["relogio_id"], "pulso", max($agora, instante($d->format("Y-m-d"), cfg("uso_inicio"))), $fim_u, null, true);
        }
    }
    $m_ini = max($agora, instante($dia->format("Y-m-d"), cfg("uso_inicio")));
    [$id, $motivo] = escolha_do_dia($modo, $dia, $m_ini, $cands, $selecao, $fora, $bloco ? $bloco["nome"] : "");
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"]++;
    return [$id, $motivo];
}


// ---------------------------------------------------------------------------------------------------------------------
// O rodízio manual (o "Rodízio manual" do quadro Modo de rodízio): você escolhe o relógio de cada dia de um período. Cada
// dia tem um seletor com os relógios disponíveis, "Decida por mim" e "Sem relógio (dia livre)". O modo ativo continua
// valendo fora do período.
// ---------------------------------------------------------------------------------------------------------------------

const RODIZIO_MANUAL_POR_PAGINA = 7;

// o critério do "Decida por mim" do rodízio manual, como a API o explica
function rodizio_manual_criterio()
{
    return t("Decida por mim: o sistema escolhe pela nota dos critérios (os que você cadastra em Critérios: dias sem uso, equilíbrio, preferência, carga...), recalculada no começo de cada dia, com os dias anteriores do período (os que você escolheu e os que ele decidiu) já contados como uso; a garantia de rodízio vem na frente (quem passou do limite de dias sem uso, o mais tempo parado primeiro); e o relógio de ontem e o de amanhã ficam de fora, se houver outro. Empate: o cadastrado primeiro. A decisão é feita ao gravar e fica gravada no plano, com o motivo; hoje, com \"Decida por mim\", continua o relógio que já está no plano. Sortear este dia por: o dia é sorteado ao gravar pela forma escolhida (a maior nota, sorteio pela nota, aleatório ou fila), com a garantia de rodízio na frente (menos no aleatório) e sem o de ontem e o de amanhã.");
}

// O período do rodízio manual: de e ate (AAAA-MM-DD), de hoje em diante, até 2 anos. Devolve [de, ate, erro]
function rodizio_manual_periodo($de, $ate)
{
    $hoje = hoje_da_colecao();
    $de_p = DateTimeImmutable::createFromFormat("!Y-m-d", (string)$de);
    $ate_p = DateTimeImmutable::createFromFormat("!Y-m-d", (string)$ate);
    $erro = null;
    if (!$de_p || $de_p->format("Y-m-d") !== (string)$de || !$ate_p || $ate_p->format("Y-m-d") !== (string)$ate) {
        $erro = t("De e até: datas AAAA-MM-DD.");
    } elseif ($de_p < $hoje) {
        $erro = t("O período começa num dia que já passou: o que foi usado se corrige nas marcações.");
    } elseif ($ate_p < $de_p) {
        $erro = t("O fim do período vem antes do começo.");
    } elseif ($de_p->diff($ate_p)->days > 730) {
        $erro = t("Um período de até 2 anos.");
    }
    return [$de_p, $ate_p, $erro];
}

// O que o seletor de um dia mostra de partida: o relógio escolhido à mão (o id), "livre" (dia livre) ou "auto" (o
// "Decida por mim": o dia sorteado ou ainda sem relógio)
function rodizio_manual_escolha($p, $livre)
{
    if ($p && $p["origem"] === "manual") {
        return (int)$p["relogio_id"];
    }
    return $livre ? "livre" : "auto";
}

// Uma página do período (7 dias): cada dia com o dia da semana, o que o plano tem agora (o relógio, a origem, o motivo,
// se é dia livre) e a escolha de partida do seletor; e as páginas, os relógios disponíveis e o critério do "Decida por mim"
function rodizio_manual_pagina($de, $ate, $pagina)
{
    $total = $de->diff($ate)->days + 1;
    $paginas = (int)ceil($total / RODIZIO_MANUAL_POR_PAGINA);
    $pagina = max(1, min($paginas, (int)$pagina));
    $hoje = hoje_da_colecao()->format("Y-m-d");
    $nomes = [1 => t("segunda"), 2 => t("terça"), 3 => t("quarta"), 4 => t("quinta"), 5 => t("sexta"), 6 => t("sábado"), 7 => t("domingo")];
    $dias = [];
    $ini = $de->modify("+" . (($pagina - 1) * RODIZIO_MANUAL_POR_PAGINA) . " days");
    for ($i = 0, $dia = $ini; $i < RODIZIO_MANUAL_POR_PAGINA && $dia <= $ate; $i++, $dia = $dia->modify("+1 day")) {
        $data = $dia->format("Y-m-d");
        $p = plano_do_dia($data);
        $livre = dia_livre($data);
        $dias[] = ["data" => $data, "dia_semana" => (int)$dia->format("N"), "dia_semana_nome" => $nomes[(int)$dia->format("N")], "hoje" => $data === $hoje,
            "relogio_id" => $p ? (int)$p["relogio_id"] : null, "relogio" => $p ? $p["nome"] : null, "origem" => $p ? $p["origem"] : null,
            "motivo" => $p ? $p["motivo"] : null, "livre" => $livre, "escolha" => rodizio_manual_escolha($p, $livre)];
    }
    return ["de" => $de->format("Y-m-d"), "ate" => $ate->format("Y-m-d"), "total_dias" => $total, "por_pagina" => RODIZIO_MANUAL_POR_PAGINA,
        "pagina" => $pagina, "paginas" => $paginas, "dias" => $dias,
        "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"]]; }, linhas("SELECT id, nome FROM relogio WHERE disponivel = 1 ORDER BY nome")),
        "decida_por_mim" => rodizio_manual_criterio(),
        "formas" => array_map(function ($k, $n) { return ["chave" => $k, "nome" => $n]; }, array_keys(formas_de_escolha()), formas_de_escolha())];
}

// O "Decida por mim": o relógio de cada dia "auto" do período, em ordem, pelo critério de rodizio_manual_criterio(). De hoje
// até o fim do período, cada dia vira uma sessão simulada no pulso (os do plano antes do período, os escolhidos e os
// decididos), para os dias seguintes verem esse uso. $escolhas: [AAAA-MM-DD => id | "livre" | "auto"], todos os dias do
// período. Devolve [AAAA-MM-DD => [relogio_id, motivo]] dos dias decididos
function rodizio_manual_decidir($de, $ate, $escolhas)
{
    $hoje = hoje_da_colecao();
    $agora = time();
    $pulso = lancamento_tipos()["pulso"] ?? null;
    $rels = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY id") as $r) {
        $rels[(int)$r["id"]] = $r;
    }
    $max = (int)cfg("max_sem_uso");
    $res = [];
    $anterior = null;
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
    for ($dia = $hoje; $dia <= $ate; $dia = $dia->modify("+1 day")) {
        $data = $dia->format("Y-m-d");
        $ini_u = instante($data, cfg("uso_inicio"));
        $fim_u = instante($data, cfg("uso_fim"));
        $m_ini = max($agora, $ini_u);
        $e = $dia < $de ? null : ($escolhas[$data] ?? "auto");
        $escolhido = null;
        if ($e === null || ($e === "auto" && $dia == $hoje && plano_do_dia($data) !== null)) {
            // antes do período (e hoje, no "Decida por mim"): o que o plano já tem
            $p = plano_do_dia($data);
            $escolhido = $p ? (int)$p["relogio_id"] : null;
        } elseif (is_int($e)) {
            $escolhido = $e;
        } elseif (($e === "auto" || (is_string($e) && strpos($e, "sortear:") === 0)) && count($rels) > 0) {
            $cands = $rels;
            $fora = [];
            $amanha = $escolhas[$dia->modify("+1 day")->format("Y-m-d")] ?? null;
            foreach ([[$anterior, t("o de ontem")], [is_int($amanha) ? $amanha : null, t("o de amanhã")]] as [$x, $qual]) {
                if ($x !== null && isset($cands[$x]) && count($cands) > 1) {
                    $fora[] = $qual . " (" . $cands[$x]["nome"] . ")";
                    unset($cands[$x]);
                }
            }
            if ($e !== "auto") {
                // sortear:<forma>: o dia sorteado por essa forma de escolha (sem o de ontem e o de amanhã, como o Decida por mim)
                $forma = substr($e, 8);
                [$escolhido, $motivo] = escolha_do_dia(null, $dia, $m_ini, $cands, $forma, array_map(function ($x) { return t("{1} ficou de fora", [1 => $x]); }, $fora), "", false);
                if ($escolhido !== null) {
                    $res[$data] = [$escolhido, corta_texto(t("Sorteado à mão ({1}): {2}", [1 => formas_de_escolha()[$forma], 2 => $motivo]), 300)];
                }
            } else {
                $info = [];
                foreach ($cands as $cid => $r) {
                    $ctx = ["r" => $r, "momento" => $m_ini, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($cid)];
                    $dsu = variavel_valor("dias_sem_uso", $ctx);
                    $info[$cid] = ["dsu" => is_numeric($dsu) ? (float)$dsu : 9999.0, "nota" => nota_do_relogio($r, $m_ini)["nota"], "nunca" => nunca_no_pulso($ctx)];
                }
                $entre = count($cands) === 1 ? t("o único candidato") : t("entre {1} candidatos", [1 => count($cands)]);
                $parados = array_filter($info, function ($x) use ($max) { return $x["dsu"] > $max; });
                if ($max > 0 && count($parados) > 0) {
                    uasort($parados, function ($x, $y) { return $y["dsu"] <=> $x["dsu"]; });
                    $escolhido = (int)array_key_first($parados);
                    $motivo = t("Decida por mim, pela garantia de rodízio: {1}, além do limite de {2} dias sem uso, {3}.", [1 => motivo_parado($parados[$escolhido]["dsu"], $parados[$escolhido]["nunca"]), 2 => $max, 3 => $entre]);
                } else {
                    uasort($info, function ($x, $y) { return $y["nota"] <=> $x["nota"]; });
                    $escolhido = (int)array_key_first($info);
                    $motivo = t("Decida por mim: a maior nota ({1}), {2}.", [1 => motivo_nota($info[$escolhido]["nota"]), 2 => $entre]);
                }
                if (count($fora) > 0) {
                    $motivo .= t(" {1}{2} de fora.", [1 => ucfirst(implode(" e ", $fora)), 2 => count($fora) > 1 ? " ficaram" : " ficou"]);
                }
                $res[$data] = [$escolhido, corta_texto($motivo, 300)];
            }
        }
        // o dia vira uma sessão simulada no pulso (se ainda não tem a do rodízio): só o uso, como no sorteio
        if ($escolhido !== null && $pulso && $fim_u > $agora && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ?",
            [(int)$pulso["id"], $data]) === null) {
            simular_lancamento($escolhido, "pulso", $m_ini, $fim_u, null, true);
        }
        $anterior = $escolhido;
    }
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"]++;
    return $res;
}

// Um tamanho do php.ini ("8M", "2G", "512K", "1048576") em bytes; 0: sem limite ou não informado
function ini_bytes($v)
{
    $v = trim((string)$v);
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case "g":
            $n *= 1024;
            // segue
        case "m":
            $n *= 1024;
            // segue
        case "k":
            $n *= 1024;
    }
    return max(0, (int)$n);
}

// O modo de rodízio ativo (o id; 0: nenhum): a marca "ativo" da tabela modo (um só tem a marca)
function modo_ativo($recarregar = false)
{
    // guardado por coleção (o cron passa por todas)
    static $ids = [];
    $k = (int)colecao_atual();
    if (!isset($ids[$k]) || $recarregar) {
        $ids[$k] = (int)(valor("SELECT id FROM modo WHERE ativo = 1 ORDER BY id LIMIT 1") ?? 0);
    }
    return $ids[$k];
}

// Os blocos de um modo, na ordem, cada um com os dias da semana dele (a tabela modo_bloco_dia) em "dias": "1,2,3,4,5"
function modo_blocos($modo_id)
{
    $dias = [];
    foreach (linhas("SELECT d.bloco_id, d.dia FROM modo_bloco_dia d JOIN modo_bloco b ON b.id = d.bloco_id WHERE b.modo_id = ? ORDER BY d.bloco_id, d.dia", [(int)$modo_id]) as $x) {
        $dias[(int)$x["bloco_id"]][] = (int)$x["dia"];
    }
    return array_map(function ($b) use ($dias) {
        $b["dias"] = implode(",", $dias[(int)$b["id"]] ?? []);
        return $b;
    }, linhas("SELECT * FROM modo_bloco WHERE modo_id = ? ORDER BY ordem, id", [(int)$modo_id]));
}

// Ativa um modo, e só ele
function modo_ativar($id)
{
    // a marca é 1 no modo em uso e vazia nos outros (a chave única não deixa haver dois 1): primeiro sai, depois entra
    sql("UPDATE modo SET ativo = NULL WHERE ativo IS NOT NULL AND id <> ?", [(int)$id]);
    sql("UPDATE modo SET ativo = 1 WHERE id = ?", [(int)$id]);
    modo_ativo(true);
}

// ---------------------------------------------------------------------------------------------------------------------
// Os documentos de cada relógio: o arquivo numa pasta do servidor (DOCUMENTOS_PASTA, no config.php, fora da pasta
// publicada) e os dados dele na tabela documento, numa categoria (documento_categoria, cadastro)
// ---------------------------------------------------------------------------------------------------------------------

// A pasta dos documentos, sem a barra do fim; null se o config.php não tem DOCUMENTOS_PASTA ou se ela não existe e não dá
// para criar, ou não aceita gravar (documentos_pasta_erro() diz o motivo)
function documentos_pasta()
{
    return documentos_pasta_erro() === "" ? rtrim((string)DOCUMENTOS_PASTA, "/\\") : null;
}

// O motivo de a pasta dos documentos não servir ("" se serve)
function documentos_pasta_erro()
{
    static $erro = null;
    if ($erro === null) {
        if (!defined("DOCUMENTOS_PASTA") || trim((string)DOCUMENTOS_PASTA) === "") {
            $erro = t("falta a pasta dos documentos: o DOCUMENTOS_PASTA do config.php (uma pasta do servidor fora da pasta publicada)");
        } else {
            $pasta = rtrim((string)DOCUMENTOS_PASTA, "/\\");
            if (!is_dir($pasta)) {
                @mkdir($pasta, 0750, true);
            }
            $erro = !is_dir($pasta) ? t("a pasta dos documentos ({1}) não existe e não consegui criá-la", [1 => $pasta])
                : (!is_writable($pasta) ? t("a pasta dos documentos ({1}) não aceita gravar: dê permissão de escrita ao usuário do PHP", [1 => $pasta]) : "");
        }
    }
    return $erro;
}

// O maior documento aceito, em bytes: o DOCUMENTOS_LIMITE do config.php (sem ele, o MANUAL_LIMITE, o nome antigo; sem os
// dois, 100 MB; 0 ou negativo, como o -1: sem limite do sistema), ou menos se o PHP do servidor aceitar menos num envio
// (upload_max_filesize e post_max_size do php.ini; o envio leva também o resto do formulário, daí a folga de 64 KB).
// null: nenhum limite, nem do sistema nem do PHP
function documentos_limite()
{
    $limite = defined("DOCUMENTOS_LIMITE") ? (int)DOCUMENTOS_LIMITE : (defined("MANUAL_LIMITE") ? (int)MANUAL_LIMITE : 100 * 1024 * 1024);
    $limite = $limite > 0 ? $limite : PHP_INT_MAX;
    $arquivo = ini_bytes(ini_get("upload_max_filesize"));
    $envio = ini_bytes(ini_get("post_max_size"));
    if ($arquivo > 0) {
        $limite = min($limite, $arquivo);
    }
    if ($envio > 0) {
        $limite = min($limite, max(0, $envio - 64 * 1024));
    }
    return $limite === PHP_INT_MAX ? null : $limite;
}

// Um tamanho de arquivo para as mensagens: "850 KB", "3,2 MB"
function tamanho_texto($bytes)
{
    return $bytes < 1048576 ? t("{1} KB", [1 => max(1, (int)round($bytes / 1024))]) : t("{1} MB", [1 => decimal_txt($bytes / 1048576, 1)]);
}

// O tipo (MIME) de um arquivo: pelo conteúdo (a extensão fileinfo do PHP) e, quando ele não diz, pela extensão do nome
function documento_tipo($caminho, $nome)
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    $por_ext = ["jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png", "gif" => "image/gif", "webp" => "image/webp", "avif" => "image/avif",
        "heic" => "image/heic", "bmp" => "image/bmp", "svg" => "image/svg+xml", "mp4" => "video/mp4", "m4v" => "video/mp4", "mov" => "video/quicktime",
        "webm" => "video/webm", "mkv" => "video/x-matroska", "avi" => "video/x-msvideo", "3gp" => "video/3gpp", "mp3" => "audio/mpeg", "m4a" => "audio/mp4",
        "ogg" => "audio/ogg", "wav" => "audio/wav", "pdf" => "application/pdf", "xml" => "application/xml", "txt" => "text/plain", "csv" => "text/csv",
        "zip" => "application/zip", "doc" => "application/msword", "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "xls" => "application/vnd.ms-excel", "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"];
    $tipo = "";
    if (function_exists("finfo_open")) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $tipo = (string)@finfo_file($f, $caminho);
        finfo_close($f);
    }
    // o conteúdo diz pouco (texto, binário genérico, um zip que é docx): vale a extensão, se ela for conhecida
    if (($tipo === "" || in_array($tipo, ["application/octet-stream", "text/plain", "application/zip", "text/xml"], true)) && isset($por_ext[$ext])) {
        $tipo = $tipo === "text/xml" && $ext !== "xml" ? $tipo : $por_ext[$ext];
    }
    return $tipo !== "" ? substr($tipo, 0, 100) : "application/octet-stream";
}

// A família do arquivo, que decide como ele abre: imagem (a galeria), video (o player em sequência), audio, pdf (o
// visualizador), xml (o resumo da nota e o download) ou outro (só o download)
function documento_familia($tipo, $nome = "")
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    if ($tipo === "image/svg+xml") {
        return "outro";
    }
    foreach (["image/" => "imagem", "video/" => "video", "audio/" => "audio"] as $pre => $fam) {
        if (strpos($tipo, $pre) === 0) {
            return $fam;
        }
    }
    if ($tipo === "application/pdf") {
        return "pdf";
    }
    return in_array($tipo, ["application/xml", "text/xml"], true) || $ext === "xml" ? "xml" : "outro";
}

// As famílias que uma categoria aceita (vazio: qualquer arquivo): o texto "pdf,imagem" (o formato do pedido) em lista
function documento_familias_aceitas($aceita)
{
    return array_values(array_filter(array_map("trim", explode(",", (string)$aceita)), function ($x) { return $x !== ""; }));
}

// Uma categoria de documentos (null se não existe), com as famílias que ela aceita (a tabela documento_categoria_aceita) em
// "aceita": "imagem,pdf" (vazio: qualquer arquivo)
function documento_categoria_linha($id)
{
    $c = linha("SELECT * FROM documento_categoria WHERE id = ?", [(int)$id]);
    if ($c) {
        $c["aceita"] = implode(",", array_column(linhas("SELECT familia FROM documento_categoria_aceita WHERE categoria_id = ? ORDER BY familia", [(int)$id]), "familia"));
    }
    return $c;
}

// Grava as famílias que uma categoria aceita
function documento_categoria_gravar_aceita($id, $familias)
{
    sql("DELETE FROM documento_categoria_aceita WHERE categoria_id = ?", [(int)$id]);
    foreach ($familias as $f) {
        sql("INSERT INTO documento_categoria_aceita (categoria_id, familia) VALUES (?, ?)", [(int)$id, $f]);
    }
}

// Um nome novo de arquivo dentro da pasta (r<relógio>/<aleatório>), com a subpasta criada
function documento_novo_arquivo($pasta, $sub, $nome)
{
    $sub = preg_match("/^[rc]\\d+$/", (string)$sub) === 1 ? (string)$sub : "r" . (int)$sub;
    if (!is_dir($pasta . "/" . $sub)) {
        @mkdir($pasta . "/" . $sub, 0750, true);
    }
    $ext = strtolower(preg_replace("/[^A-Za-z0-9]/", "", pathinfo($nome, PATHINFO_EXTENSION)));
    return $sub . "/" . bin2hex(random_bytes(12)) . ($ext !== "" ? "." . substr($ext, 0, 10) : "");
}

// O arquivo de um documento está inteiro na pasta: existe e tem o tamanho gravado no envio. O cortado (uma cópia ou uma
// sincronização da pasta que parou no meio) não conta: volta da cópia no banco como o que sumiu
function documento_inteiro($d, $caminho)
{
    clearstatcache(true, $caminho);
    return is_file($caminho) && filesize($caminho) === (int)$d["tamanho"];
}

// Os dados de um documento para a API: a linha da tabela, com a família, os endereços e, no XML de uma NF-e, o resumo dela
function documento_info($d, $com_nfe = true)
{
    $familia = documento_familia($d["tipo"], $d["nome"]);
    $res = ["id" => (int)$d["id"], "relogio_id" => $d["relogio_id"] === null ? null : (int)$d["relogio_id"], "calibre_id" => ($d["calibre_id"] ?? null) === null ? null : (int)$d["calibre_id"],
        "categoria_id" => (int)$d["categoria_id"], "titulo" => $d["titulo"],
        "data" => $d["data"] === null ? null : substr((string)$d["data"], 0, 10), "descricao" => $d["descricao"], "nome" => $d["nome"], "tipo" => $d["tipo"],
        "familia" => $familia, "tamanho" => (int)$d["tamanho"], "miniatura" => $d["miniatura"] !== null && $d["miniatura"] !== "", "criado" => $d["criado"],
        "url" => "api.php?recurso=documento&id=" . (int)$d["id"], "no_disco" => documentos_pasta() !== null && documento_inteiro($d, documentos_pasta() . "/" . $d["arquivo"]),
        "no_banco" => (int)($d["no_banco"] ?? 0) === 1, "copia_banco" => (int)($d["copia_banco"] ?? 0) === 1, "copia_por" => documento_copia_por($d), "nfe" => null];
    if ($com_nfe && $familia === "xml" && documentos_pasta() !== null) {
        $res["nfe"] = nfe_resumo(documentos_pasta() . "/" . $d["arquivo"]);
    }
    return $res;
}

// Os documentos de um relógio (null: de todos), da categoria e da data
function documentos_do_relogio($rid = null)
{
    return linhas("SELECT d.* FROM documento d JOIN documento_categoria c ON c.id = d.categoria_id" . ($rid !== null ? " WHERE d.relogio_id = ?" : " WHERE d.relogio_id IS NOT NULL")
        . " ORDER BY d.relogio_id, c.ordem, c.id, COALESCE(d.data, '9999-12-31'), d.criado, d.id", $rid !== null ? [(int)$rid] : []);
}

// Os documentos de um calibre (o manual técnico, a ficha de serviço...), na ordem das categorias e da data
function documentos_do_calibre($cid)
{
    return linhas("SELECT d.* FROM documento d JOIN documento_categoria c ON c.id = d.categoria_id WHERE d.calibre_id = ?
        ORDER BY c.ordem, c.id, COALESCE(d.data, '9999-12-31'), d.criado, d.id", [(int)$cid]);
}

// O resumo de uma NF-e (o XML da nota fiscal eletrônica): o emitente, o número, a série, a data, o valor, a chave e os
// produtos; null se o arquivo não é uma NF-e. Lido sem baixar nada de fora (LIBXML_NONET) e sem entidades
function nfe_resumo($caminho)
{
    if (!is_file($caminho) || filesize($caminho) > 5 * 1048576 || !class_exists("DOMDocument")) {
        return null;
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML((string)file_get_contents($caminho), LIBXML_NONET)) {
        return null;
    }
    $inf = $dom->getElementsByTagName("infNFe")->item(0);
    if ($inf === null) {
        return null;
    }
    $um = function ($pai, $tag) {
        $n = $pai !== null ? $pai->getElementsByTagName($tag)->item(0) : null;
        return $n !== null ? trim($n->textContent) : null;
    };
    $emit = $inf->getElementsByTagName("emit")->item(0);
    $ide = $inf->getElementsByTagName("ide")->item(0);
    $tot = $inf->getElementsByTagName("ICMSTot")->item(0);
    $produtos = [];
    foreach ($inf->getElementsByTagName("prod") as $p) {
        $produtos[] = ["descricao" => $um($p, "xProd"), "quantidade" => is_numeric($um($p, "qCom")) ? (float)$um($p, "qCom") : null,
            "valor" => is_numeric($um($p, "vProd")) ? (float)$um($p, "vProd") : null];
    }
    $data = $um($ide, "dhEmi") ?? $um($ide, "dEmi");
    return ["emitente" => $um($emit, "xNome"), "cnpj" => $um($emit, "CNPJ") ?? $um($emit, "CPF"), "numero" => $um($ide, "nNF"), "serie" => $um($ide, "serie"),
        "data" => $data !== null ? substr($data, 0, 10) : null, "valor" => is_numeric($um($tot, "vNF")) ? (float)$um($tot, "vNF") : null,
        "chave" => preg_replace("/^NFe/", "", (string)$inf->getAttribute("Id")), "produtos" => $produtos];
}

// Manda um documento para o navegador: inline só os tipos que o navegador mostra sem rodar nada (imagens, vídeo, áudio,
// PDF, texto); o resto (inclusive HTML, SVG e XML, que rodariam no endereço do sistema) vai como download. Atende o
// pedido de um pedaço (Range), que o vídeo usa para avançar
function documento_enviar($d, $caminho, $baixar, $miniatura = false)
{
    $tipo = $miniatura ? "image/jpeg" : $d["tipo"];
    $mostra = !$baixar && ($miniatura || in_array(documento_familia($d["tipo"], $d["nome"]), ["imagem", "video", "audio", "pdf"], true) || $d["tipo"] === "text/plain");
    $tamanho = filesize($caminho);
    $ini = 0;
    $fim = $tamanho - 1;
    if (!$miniatura && preg_match("/^bytes=(\\d*)-(\\d*)$/", trim((string)($_SERVER["HTTP_RANGE"] ?? "")), $m) === 1 && ($m[1] !== "" || $m[2] !== "")) {
        if ($m[1] === "") {
            $ini = max(0, $tamanho - (int)$m[2]);
        } else {
            $ini = (int)$m[1];
            $fim = $m[2] !== "" ? min((int)$m[2], $tamanho - 1) : $tamanho - 1;
        }
        if ($ini > $fim || $ini >= $tamanho) {
            http_response_code(416);
            header("Content-Range: bytes */" . $tamanho);
            return;
        }
        http_response_code(206);
        header("Content-Range: bytes " . $ini . "-" . $fim . "/" . $tamanho);
    }
    $ascii = preg_replace("/[^A-Za-z0-9._ -]/", "_", $d["nome"]);
    header("Content-Type: " . ($mostra ? $tipo : "application/octet-stream"));
    header("Content-Length: " . ($fim - $ini + 1));
    header("Accept-Ranges: bytes");
    header("Content-Disposition: " . ($mostra ? "inline" : "attachment") . "; filename=\"" . $ascii . "\"; filename*=UTF-8''" . rawurlencode($d["nome"]));
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: private, max-age=86400");
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @set_time_limit(0);
    $f = fopen($caminho, "rb");
    fseek($f, $ini);
    $falta = $fim - $ini + 1;
    while ($falta > 0 && !feof($f)) {
        $pedaco = fread($f, (int)min(1048576, $falta));
        if ($pedaco === false || $pedaco === "") {
            break;
        }
        echo $pedaco;
        $falta -= strlen($pedaco);
        flush();
    }
    fclose($f);
}

// As categorias dos documentos, na ordem, com quantos documentos cada uma tem (de um relógio; null: de todos)
function documento_categorias_lista($rid = null, $cid = null)
{
    $aceita = [];
    foreach (linhas("SELECT categoria_id, familia FROM documento_categoria_aceita ORDER BY categoria_id, familia") as $x) {
        $aceita[(int)$x["categoria_id"]][] = $x["familia"];
    }
    return array_map(function ($c) use ($aceita) {
        return ["id" => (int)$c["id"], "identificador" => $c["identificador"], "nome" => $c["nome"], "aceita" => $aceita[(int)$c["id"]] ?? [],
            "ordem" => (int)$c["ordem"], "documentos" => (int)$c["documentos"]];
    }, linhas("SELECT c.*, (SELECT COUNT(*) FROM documento d WHERE d.categoria_id = c.id" . ($cid !== null ? " AND d.calibre_id = ?" : ($rid !== null ? " AND d.relogio_id = ?" : " AND d.relogio_id IS NOT NULL"))
        . ") AS documentos FROM documento_categoria c ORDER BY c.ordem, c.id", $cid !== null ? [(int)$cid] : ($rid !== null ? [(int)$rid] : [])));
}

// Apaga os arquivos de um documento (o arquivo e a miniatura) da pasta
function documento_apagar_arquivos($d)
{
    $pasta = documentos_pasta();
    if ($pasta !== null) {
        foreach ([$d["arquivo"], $d["miniatura"]] as $a) {
            if ($a !== null && $a !== "" && strpos($a, "..") === false && is_file($pasta . "/" . $a)) {
                @unlink($pasta . "/" . $a);
            }
        }
    }
}

// Como o relógio abre na página Hoje (a Configuração): "lado", no painel à direita da lista (o padrão), ou "flutuante",
// numa janela grande por cima da página. No celular, os dois cobrem a tela inteira
// Quantos dias cada página mostra (a Configuração, "Mostrar até"): os Próximos dias da página Hoje (padrão 7) e o dia a dia
// da página Plano (padrão 31). De 1 a 366
// De quantos em quantos segundos as páginas leem tudo de novo sozinhas (a Configuração, atualizar_segundos): 1 a 86400;
// 0, não atualizam sozinhas; vazio, 60
function atualizar_segundos()
{
    $v = trim((string)cfg("atualizar_segundos"));
    return $v === "" || !ctype_digit($v) || (int)$v > 86400 ? 60 : (int)$v;
}

// O reloginho da barra e do favicon (relojinho.js, 24 modelos): o modo da Configuração (relojinho_modo): hora (o padrão:
// cada hora do dia tem o seu modelo, da 0 à 23), aleatorio (cada hora sorteia um dos 24, podendo repetir) ou sem_repeticao
// (os 24 embaralhados: cada um aparece uma vez por dia)
const RELOJINHO_MODELOS = 24;
function relojinho_modo()
{
    $m = (string)cfg("relojinho_modo");
    return in_array($m, ["aleatorio", "sem_repeticao"], true) ? $m : "hora";
}

// O modelo do reloginho em cada hora de um dia (24 números, de 0 a RELOJINHO_MODELOS - 1, a hora 0 primeiro). O sorteio sai
// de um resumo (sha1) da coleção, do dia e da hora: o mesmo o dia inteiro, em toda página e aparelho, e outro no dia seguinte
function relojinho_ordem($dia, $modo = null)
{
    $modo = $modo ?? relojinho_modo();
    $ordem = range(0, 23);
    $sorteio = function ($x) use ($dia) {
        return sha1("relojinho|" . (int)colecao_atual() . "|" . $dia . "|" . $x);
    };
    if ($modo === "aleatorio") {
        foreach ($ordem as $h => $_) {
            $ordem[$h] = (int)(hexdec(substr($sorteio("h" . $h), 0, 7)) % RELOJINHO_MODELOS);
        }
    } elseif ($modo === "sem_repeticao") {
        $chave = [];
        foreach ($ordem as $i) {
            $chave[$i] = $sorteio("m" . $i);
        }
        usort($ordem, function ($a, $b) use ($chave) { return strcmp($chave[$a], $chave[$b]); });
    } else {
        $ordem = array_map(function ($h) { return $h % RELOJINHO_MODELOS; }, $ordem);
    }
    return $ordem;
}

function proximos_por_pagina()
{
    $n = (int)cfg("proximos_por_pagina");
    return $n >= 1 && $n <= 366 ? $n : 7;
}

function plano_por_pagina()
{
    $n = (int)cfg("plano_por_pagina");
    return $n >= 1 && $n <= 366 ? $n : 31;
}

// Uma página dos dias do plano, de $de a $ate (vazio: sem limite), com os dias livres no meio (sem o filtro de relógio),
// na ordem das datas: {"por_pagina", "pagina", "paginas", "total", "relogio" (o filtro, ou null), "dias": [{"data",
// "dia_semana" (1 segunda ... 7 domingo), "dia_semana_curto" (seg... dom), "hoje", "relogio_id", "relogio", "origem"
// (sorteio, manual ou livre), "acao", "motivo"}]}. Os Próximos dias da página Hoje e o dia a dia da página Plano
function plano_pagina($de, $ate, $relogio_id, $pagina, $por)
{
    $curto = [1 => t("seg"), 2 => t("ter"), 3 => t("qua"), 4 => t("qui"), 5 => t("sex"), 6 => t("sáb"), 7 => t("dom")];
    $hoje = hoje_da_colecao()->format("Y-m-d");
    $faixa = [$de !== "" ? $de : "0001-01-01", $ate !== "" ? $ate : "9999-12-31"];
    $dias = [];
    foreach (linhas("SELECT p.data, p.relogio_id, r.nome, p.origem, p.acao, p.motivo FROM plano p JOIN relogio r ON r.id = p.relogio_id
        WHERE p.data >= ? AND p.data <= ?" . ($relogio_id !== null ? " AND p.relogio_id = " . (int)$relogio_id : "") . " ORDER BY p.data", $faixa) as $p) {
        $dias[substr((string)$p["data"], 0, 10)] = ["relogio_id" => (int)$p["relogio_id"], "relogio" => $p["nome"], "origem" => $p["origem"], "acao" => $p["acao"], "motivo" => $p["motivo"]];
    }
    if ($relogio_id === null) {
        foreach (dias_livres($de, $ate) as $l) {
            $dias[$l] = ["relogio_id" => null, "relogio" => null, "origem" => "livre", "acao" => null, "motivo" => null];
        }
    }
    ksort($dias);
    $total = count($dias);
    $por = max(1, min(366, (int)$por));
    $paginas = max(1, (int)ceil($total / $por));
    $pagina = max(1, min($paginas, (int)$pagina));
    $lista = [];
    foreach (array_slice($dias, ($pagina - 1) * $por, $por, true) as $data => $x) {
        $n = (int)date("N", strtotime($data));
        $lista[] = array_merge(["data" => $data, "dia_semana" => $n, "dia_semana_curto" => $curto[$n], "hoje" => $data === $hoje], $x);
    }
    return ["por_pagina" => $por, "pagina" => $pagina, "paginas" => $paginas, "total" => $total, "relogio" => $relogio_id, "dias" => $lista];
}

function painel_modo()
{
    return cfg("painel_modo") === "flutuante" ? "flutuante" : "lado";
}

// A situação de cada relógio na coleção (a posse): o identificador e o nome. Só "colecao" entra no rodízio (fora dela o relógio
// fica indisponível: o banco garante, ck_relogio_posse_disponivel); "colecao" e "emprestado" são da coleção (contam no valor e
// nas listas da página Hoje); "desejado" é a lista de desejos; "vendido" e "arquivado" saem das listas e guardam a ficha
const POSSES = ["colecao" => "Na coleção", "desejado" => "Desejado", "emprestado" => "Emprestado", "vendido" => "Vendido", "arquivado" => "Arquivado"];
// os nomes, para os dicionários dos idiomas: saem traduzidos no uso, t(POSSES[...])
tk("Na coleção"); tk("Desejado"); tk("Emprestado"); tk("Vendido"); tk("Arquivado");

function posse_de($r)
{
    $p = (string)($r["posse"] ?? "colecao");
    return isset(POSSES[$p]) ? $p : "colecao";
}

// O que a lista de desejos mostra de um relógio desejado, dos valores dele: a marca, a referência, o preço-alvo, a prioridade
// (e a ordem dela: 1 é a mais alta; sem prioridade, depois de todas) e o link
function desejo_do_relogio($valores)
{
    $prioridades = [];
    $c = campos_todos()["prioridade_desejo"] ?? null;
    foreach (array_values(array_filter(array_map("trim", explode("\n", (string)($c["opcoes"] ?? ""))))) as $i => $op) {
        $prioridades[$op] = $i + 1;
    }
    $prio = ($valores["prioridade_desejo"] ?? "") !== "" ? (string)$valores["prioridade_desejo"] : null;
    return ["marca" => ($valores["marca"] ?? "") !== "" ? (string)$valores["marca"] : null,
        "referencia" => ($valores["referencia"] ?? "") !== "" ? (string)$valores["referencia"] : null,
        "preco_alvo" => isset($valores["preco_alvo"]) && $valores["preco_alvo"] !== "" ? (float)$valores["preco_alvo"] : null,
        "prioridade" => $prio, "prioridade_ordem" => $prio !== null && isset($prioridades[$prio]) ? $prioridades[$prio] : count($prioridades) + 1,
        "link" => ($valores["link"] ?? "") !== "" ? (string)$valores["link"] : null];
}

// é da coleção agora (na coleção ou emprestado)
function da_colecao($r)
{
    return in_array(posse_de($r), ["colecao", "emprestado"], true);
}

// Os tipos de acessório (ck_acessorio_tipo), na ordem, com o nome
const ACESSORIO_TIPOS = ["pulseira" => "Pulseira", "caixa" => "Caixa", "estojo" => "Estojo", "ferramenta" => "Ferramenta", "carregador" => "Carregador",
    "peca" => "Peça", "outro" => "Outro"];
// os nomes, para os dicionários dos idiomas: saem traduzidos no uso, t(ACESSORIO_TIPOS[...])
tk("Pulseira"); tk("Caixa"); tk("Estojo"); tk("Ferramenta"); tk("Carregador"); tk("Peça"); tk("Outro");

// Os acessórios (de um relógio, ou todos: null), pelo tipo e pelo nome, prontos para a API
function acessorios_lista($relogio_id = null)
{
    return array_map(function ($a) {
        return ["id" => (int)$a["id"], "nome" => $a["nome"], "tipo" => $a["tipo"], "tipo_texto" => isset(ACESSORIO_TIPOS[$a["tipo"]]) ? t(ACESSORIO_TIPOS[$a["tipo"]]) : $a["tipo"],
            "relogio_id" => $a["relogio_id"] === null ? null : (int)$a["relogio_id"], "relogio" => $a["relogio"],
            "observacao" => $a["observacao"] !== null && $a["observacao"] !== "" ? $a["observacao"] : null, "criado" => $a["criado"]];
    }, linhas("SELECT a.*, r.nome AS relogio FROM acessorio a LEFT JOIN relogio r ON r.id = a.relogio_id"
        . ($relogio_id !== null ? " WHERE a.relogio_id = ?" : "") . " ORDER BY a.tipo, a.nome, a.id", $relogio_id !== null ? [(int)$relogio_id] : []));
}

// Os tipos de serviço (ck_servico_tipo), na ordem, com o nome. A revisão e a troca de pilha viram também a marcação do tipo
// de lançamento de mesmo identificador (revisao, pilha), quando ele vale para o relógio
const SERVICO_TIPOS = ["revisao" => "Revisão", "pilha" => "Troca de pilha", "estanqueidade" => "Teste de estanqueidade", "regulagem" => "Regulagem",
    "polimento" => "Polimento", "reparo" => "Reparo", "diagnostico" => "Diagnóstico", "outro" => "Outro"];
tk("Revisão"); tk("Troca de pilha"); tk("Teste de estanqueidade"); tk("Regulagem"); tk("Polimento"); tk("Reparo"); tk("Diagnóstico");
const SERVICO_MARCA = ["revisao", "pilha"];
// quantos dias antes e depois do serviço a marcha medida pelo sistema (as leituras do desvio da hora) entra no "antes" e no
// "depois"
const SERVICO_JANELA_MARCHA = 60;

// Os serviços (de um relógio, ou todos: null), do mais novo para o mais velho, prontos para a API: as peças, os documentos,
// o custo (a mão de obra mais as peças), a estanqueidade e a marcha antes e depois (a informada pelo profissional e a medida
// pelo sistema nos SERVICO_JANELA_MARCHA dias de cada lado)
function servicos_lista($relogio_id = null)
{
    $servicos = linhas("SELECT s.*, r.nome AS relogio FROM servico s JOIN relogio r ON r.id = s.relogio_id" . ($relogio_id !== null ? " WHERE s.relogio_id = ?" : "")
        . " ORDER BY s.data DESC, s.id DESC", $relogio_id !== null ? [(int)$relogio_id] : []);
    if (count($servicos) === 0) {
        return [];
    }
    $ids = implode(", ", array_map(function ($s) { return (int)$s["id"]; }, $servicos));
    $pecas = [];
    foreach (linhas("SELECT * FROM servico_peca WHERE servico_id IN (" . $ids . ") ORDER BY servico_id, ordem, id") as $p) {
        $pecas[(int)$p["servico_id"]][] = ["nome" => $p["nome"], "custo" => $p["custo"] === null ? null : round((float)$p["custo"], 2)];
    }
    $docs = [];
    foreach (linhas("SELECT sd.servico_id, d.* FROM servico_documento sd JOIN documento d ON d.id = sd.documento_id WHERE sd.servico_id IN (" . $ids . ") ORDER BY d.titulo, d.id") as $d) {
        $docs[(int)$d["servico_id"]][] = ["id" => (int)$d["id"], "titulo" => $d["titulo"], "url" => "api.php?recurso=documento&id=" . (int)$d["id"]];
    }
    $pontos = [];
    $hoje = hoje_da_colecao();
    return array_map(function ($s) use ($pecas, $docs, &$pontos, $hoje) {
        $id = (int)$s["id"];
        $rid = (int)$s["relogio_id"];
        $ps = $pecas[$id] ?? [];
        $mao = $s["mao_de_obra"] === null ? null : round((float)$s["mao_de_obra"], 2);
        $soma_pecas = array_sum(array_map(function ($p) { return (float)($p["custo"] ?? 0); }, $ps));
        $tem_custo = $mao !== null || count(array_filter($ps, function ($p) { return $p["custo"] !== null; })) > 0;
        // a marcha medida pelo sistema: os intervalos do desvio que terminam nos dias antes do serviço, e os que começam depois
        if (!isset($pontos[$rid])) {
            $pontos[$rid] = precisao_intervalos(precisao_pontos($rid));
        }
        $dia = strtotime(substr((string)$s["data"], 0, 10) . " 00:00:00");
        $janela = SERVICO_JANELA_MARCHA * 86400;
        $antes = precisao_marcha(array_values(array_filter($pontos[$rid], function ($x) use ($dia, $janela) { return $x["ate"] <= $dia && $x["ate"] > $dia - $janela; })), 0, "", $dia);
        $depois = precisao_marcha(array_values(array_filter($pontos[$rid], function ($x) use ($dia, $janela) { return $x["de"] >= $dia + 86400 && $x["de"] < $dia + 86400 + $janela; })), 0, "", time());
        $data = substr((string)$s["data"], 0, 10);
        $garantia = $s["garantia_ate"] === null ? null : substr((string)$s["garantia_ate"], 0, 10);
        return ["id" => $id, "relogio_id" => $rid, "relogio" => $s["relogio"], "data" => $data,
            "ha_dias" => (int)(new DateTimeImmutable($data, $hoje->getTimezone()))->diff($hoje)->format("%r%a"),
            "tipo" => $s["tipo"], "tipo_texto" => isset(SERVICO_TIPOS[$s["tipo"]]) ? t(SERVICO_TIPOS[$s["tipo"]]) : $s["tipo"],
            "responsavel" => $s["responsavel"] !== null && $s["responsavel"] !== "" ? $s["responsavel"] : null,
            "descricao" => $s["descricao"] !== null && $s["descricao"] !== "" ? $s["descricao"] : null,
            "mao_de_obra" => $mao, "pecas" => $ps, "custo_pecas" => round($soma_pecas, 2), "custo" => $tem_custo ? round((float)$mao + $soma_pecas, 2) : null,
            "estanqueidade" => $s["estanqueidade"], "estanqueidade_texto" => $s["estanqueidade"] === "aprovado" ? t("aprovada") : ($s["estanqueidade"] === "reprovado" ? t("reprovada") : null),
            "estanqueidade_bar" => $s["estanqueidade_bar"] === null ? null : round((float)$s["estanqueidade_bar"], 2),
            "marcha_antes" => $s["marcha_antes"] === null ? null : round((float)$s["marcha_antes"], 2), "marcha_depois" => $s["marcha_depois"] === null ? null : round((float)$s["marcha_depois"], 2),
            "marcha_antes_texto" => $s["marcha_antes"] === null ? null : marcha_txt((float)$s["marcha_antes"]), "marcha_depois_texto" => $s["marcha_depois"] === null ? null : marcha_txt((float)$s["marcha_depois"]),
            "medida_antes" => $antes["marcha"] === null ? null : ["marcha" => round($antes["marcha"], 2), "marcha_texto" => marcha_txt($antes["marcha"]), "intervalos" => $antes["n"]],
            "medida_depois" => $depois["marcha"] === null ? null : ["marcha" => round($depois["marcha"], 2), "marcha_texto" => marcha_txt($depois["marcha"]), "intervalos" => $depois["n"]],
            "garantia_ate" => $garantia, "garantia_vale" => $garantia !== null && $garantia >= $hoje->format("Y-m-d"),
            "observacao" => $s["observacao"] !== null && $s["observacao"] !== "" ? $s["observacao"] : null,
            "documentos" => $docs[$id] ?? [], "marcacao" => $s["lancamento_id"] !== null, "criado" => $s["criado"]];
    }, $servicos);
}

// O resumo dos serviços de um relógio, para o painel e a ficha: quantos, o custo somado, o último, a resistência à água
// declarada (o campo do cadastro), a estanqueidade comprovada (o último serviço com o teste, aprovado ou não) e quantos
// serviços tiveram o teste
function servicos_resumo($rid, $lista = null)
{
    $lista = $lista ?? servicos_lista($rid);
    $teste = null;
    $testes = count(array_filter($lista, function ($s) { return $s["estanqueidade"] !== null; }));
    foreach ($lista as $s) {
        if ($s["estanqueidade"] !== null) {
            $teste = ["data" => $s["data"], "ha_dias" => $s["ha_dias"], "resultado" => $s["estanqueidade"], "resultado_texto" => $s["estanqueidade_texto"], "bar" => $s["estanqueidade_bar"], "servico_id" => $s["id"]];
            break;
        }
    }
    $declarada = valores_do_relogio((int)$rid)["resistencia_agua_declarada"] ?? null;
    $custos = array_values(array_filter(array_column($lista, "custo"), function ($c) { return $c !== null; }));
    return ["quantos" => count($lista), "custo" => count($custos) > 0 ? round(array_sum($custos), 2) : null,
        "ultimo" => count($lista) > 0 ? ["data" => $lista[0]["data"], "tipo" => $lista[0]["tipo"], "tipo_texto" => $lista[0]["tipo_texto"], "ha_dias" => $lista[0]["ha_dias"]] : null,
        "resistencia_declarada" => $declarada !== null && $declarada !== "" && is_numeric($declarada) ? (int)$declarada : null, "estanqueidade" => $teste,
        "testes_estanqueidade" => $testes];
}

// Os serviços de cada relógio para as fórmulas e os totais (lidos uma vez por requisição, de novo depois de uma gravação):
// [relógio => [[data em segundos, tipo, custo, estanqueidade]]], do mais velho para o mais novo
function servicos_das_contas()
{
    static $cache = [];
    $chave = (int)colecao_atual() . "/" . ($GLOBALS["FORMULAS_VERSAO"] ?? 0);
    if (!isset($cache[$chave])) {
        $res = [];
        $pecas = [];
        foreach (linhas("SELECT servico_id, SUM(custo) AS soma, COUNT(custo) AS n FROM servico_peca GROUP BY servico_id") as $p) {
            $pecas[(int)$p["servico_id"]] = (int)$p["n"] > 0 ? (float)$p["soma"] : null;
        }
        foreach (linhas("SELECT id, relogio_id, data, tipo, mao_de_obra, estanqueidade FROM servico ORDER BY data, id") as $s) {
            $p = $pecas[(int)$s["id"]] ?? null;
            $res[(int)$s["relogio_id"]][] = ["quando" => strtotime(substr((string)$s["data"], 0, 10) . " 00:00:00"), "tipo" => $s["tipo"],
                "custo" => $s["mao_de_obra"] === null && $p === null ? null : (float)$s["mao_de_obra"] + (float)$p, "estanqueidade" => $s["estanqueidade"]];
        }
        $cache = [$chave => $res];
    }
    return $cache[$chave];
}

// A regra única de confiança das medidas (a marcha, a reserva medida, a estanqueidade), sem porcentagem inventada: quantas
// medições, a idade da última e quanto elas variam. Alta: pelo menos o mínimo de medições da regra, a última dentro da
// validade e a variação até a tolerância; média: a última até três vezes a validade e a variação até o dobro da tolerância;
// baixa: o resto. Cada regra: o mínimo de medições e a validade (dias)
const CONFIANCA_REGRAS = ["marcha" => ["minimo" => 3, "validade" => 30], "reserva" => ["minimo" => 2, "validade" => 365], "estanqueidade" => ["minimo" => 1, "validade" => 365]];

// A confiança de uma medida: $n medições, a última em $ultima (segundos), a variação $dp (um desvio padrão; null com uma só
// ou quando não se aplica) e a tolerância $tol, na unidade de $fmt (a função que escreve um valor dela); $testes: as medições
// são testes ("1 teste", "2 testes"). null sem medição nenhuma
function confianca_medida($regra, $n, $ultima, $agora, $dp = null, $tol = null, $fmt = null, $testes = false)
{
    if ($n <= 0 || $ultima === null) {
        return null;
    }
    $min = CONFIANCA_REGRAS[$regra]["minimo"];
    $val = CONFIANCA_REGRAS[$regra]["validade"];
    $fmt = $fmt ?? function ($x) { return decimal_txt($x, 1); };
    $idade = max(0, (int)floor(($agora - $ultima) / 86400));
    $motivos = [];
    if ($n < $min) {
        $motivos[] = $testes ? t("menos de {1} testes", [1 => $min]) : t("menos de {1} medições", [1 => $min]);
    }
    if ($idade > $val) {
        $motivos[] = $testes ? t("o último tem mais de {1} dias", [1 => $val]) : t("a última tem mais de {1} dias", [1 => $val]);
    }
    if ($dp !== null && $tol !== null && $dp > $tol) {
        $motivos[] = t("variam mais que ±{1}", [1 => $fmt($tol)]);
    }
    $nivel = count($motivos) === 0 ? "alta" : ($idade <= 3 * $val && ($dp === null || $tol === null || $dp <= 2 * $tol) ? "media" : "baixa");
    $nivel_texto = ["alta" => t("alta"), "media" => t("média"), "baixa" => t("baixa")][$nivel];
    $quantos = $testes ? ($n === 1 ? t("1 teste") : t("{1} testes", [1 => $n])) : ($n === 1 ? t("1 medição") : t("{1} medições", [1 => $n]));
    $quando = $idade === 0 ? ($testes ? t("o último há menos de 1 dia") : t("a última há menos de 1 dia")) : ($testes ? ($idade === 1 ? t("o último há 1 dia") : t("o último há {1} dias", [1 => $idade]))
        : ($idade === 1 ? t("a última há 1 dia") : t("a última há {1} dias", [1 => $idade])));
    $texto = $quantos . ", " . $quando . ($dp !== null ? t(", variação de ±{1}", [1 => $fmt($dp)]) : "");
    return ["nivel" => $nivel, "nivel_texto" => $nivel_texto, "medicoes" => (int)$n, "idade_dias" => $idade, "variacao" => $dp === null ? null : round($dp, 2),
        "tolerancia" => $tol === null ? null : round($tol, 2), "texto" => $texto, "motivos" => $motivos,
        "resumo" => t("Confiança {1}: {2}.", [1 => $nivel_texto, 2 => $texto]) . (count($motivos) > 0 ? t(" Não é alta por: {1}.", [1 => implode("; ", $motivos)]) : "")];
}

// Os protocolos do teste da reserva (ck_reserva_teste_protocolo): como ele ficou cheio, e o que o número diz; e os tipos das
// observações da energia (ck_reserva_teste_tipo, menos o teste)
const RESERVA_PROTOCOLOS = ["corda" => ["Corda pela coroa", "voltas"], "winder" => ["No winder", "h"], "pulso" => ["No pulso", "h"], "outro" => ["Outro jeito", ""]];
const RESERVA_OBSERVACOES = ["parou" => "Parou", "carga_baixa" => "Indicador de carga baixa", "nota" => "Outra observação"];
tk("Corda pela coroa"); tk("No winder"); tk("No pulso"); tk("Outro jeito"); tk("voltas"); tk("Parou"); tk("Indicador de carga baixa"); tk("Outra observação");

// Como o relógio ficou cheio no teste, por extenso: "cheio com 40 voltas da coroa", "cheio depois de 8 h no winder"
function reserva_protocolo_txt($protocolo, $valor)
{
    $v = $valor === null ? null : decimal_txt((float)$valor, 2);
    if ($protocolo === "corda") {
        return $v !== null ? t("cheio com {1} voltas da coroa", [1 => $v]) : t("cheio pela corda da coroa");
    } elseif ($protocolo === "winder") {
        return $v !== null ? t("cheio depois de {1} h no winder", [1 => $v]) : t("cheio no winder");
    } elseif ($protocolo === "pulso") {
        return $v !== null ? t("cheio depois de {1} h no pulso", [1 => $v]) : t("cheio no pulso");
    }
    return t("cheio de outro jeito");
}

// Horas por extenso: "41,5 h"
function reserva_horas_txt($h)
{
    return $h === null ? null : t("{1} h", [1 => decimal_txt($h, 1)]);
}

// Os testes da reserva e as observações da energia (de um relógio, ou de todos: null), do mais novo para o mais velho, prontos
// para a API: o teste com o protocolo e as horas de cheio até parado (null em andamento, com as horas até agora)
function reserva_lista($relogio_id = null, $agora = null)
{
    $agora = $agora ?? time();
    return array_map(function ($x) use ($agora) {
        $ini = strtotime($x["inicio"]);
        $fim = $x["fim"] === null ? null : strtotime($x["fim"]);
        $teste = $x["tipo"] === "teste";
        $horas = $teste && $fim !== null ? ($fim - $ini) / 3600 : null;
        return ["id" => (int)$x["id"], "relogio_id" => (int)$x["relogio_id"], "relogio" => $x["relogio"], "tipo" => $x["tipo"],
            "tipo_texto" => $teste ? t("Teste da reserva") : t(RESERVA_OBSERVACOES[$x["tipo"]] ?? $x["tipo"]),
            "inicio" => date("Y-m-d H:i:s", $ini), "fim" => $fim === null ? null : date("Y-m-d H:i:s", $fim),
            "em_andamento" => $teste && $fim === null, "horas" => $horas === null ? null : round($horas, 2), "horas_texto" => reserva_horas_txt($horas),
            "ate_agora" => $teste && $fim === null ? round(max(0, $agora - $ini) / 3600, 2) : null,
            "protocolo" => $teste ? $x["protocolo"] : null, "protocolo_valor" => $x["protocolo_valor"] === null ? null : round((float)$x["protocolo_valor"], 2),
            "protocolo_texto" => $teste ? reserva_protocolo_txt($x["protocolo"], $x["protocolo_valor"]) : null,
            "observacao" => $x["observacao"] !== null && $x["observacao"] !== "" ? $x["observacao"] : null, "criado" => $x["criado"]];
    }, linhas("SELECT x.*, r.nome AS relogio FROM reserva_teste x JOIN relogio r ON r.id = x.relogio_id" . ($relogio_id !== null ? " WHERE x.relogio_id = ?" : "")
        . " ORDER BY x.inicio DESC, x.id DESC", $relogio_id !== null ? [(int)$relogio_id] : []));
}

// Os testes terminados de cada relógio, para as contas (lidos uma vez por requisição, de novo depois de uma gravação):
// [relógio => [[fim em segundos, horas]]], do mais velho para o mais novo
function reserva_das_contas()
{
    static $cache = [];
    $chave = (int)colecao_atual() . "/" . ($GLOBALS["FORMULAS_VERSAO"] ?? 0);
    if (!isset($cache[$chave])) {
        $res = [];
        foreach (linhas("SELECT relogio_id, inicio, fim FROM reserva_teste WHERE tipo = 'teste' AND fim IS NOT NULL ORDER BY fim, id") as $x) {
            $res[(int)$x["relogio_id"]][] = [strtotime($x["fim"]), (strtotime($x["fim"]) - strtotime($x["inicio"])) / 3600];
        }
        $cache = [$chave => $res];
    }
    return $cache[$chave];
}

// A reserva medida de um relógio até $momento: quantos testes terminados, a mediana das horas (resiste a um teste errado),
// a variação (um desvio padrão; null com um só), o último (quando terminou e quantas horas deu)
function reserva_medida($rid, $momento)
{
    $ts = array_values(array_filter(reserva_das_contas()[(int)$rid] ?? [], function ($x) use ($momento) { return $x[0] <= $momento; }));
    $n = count($ts);
    if ($n === 0) {
        return ["n" => 0, "mediana" => null, "dp" => null, "ultima" => null, "ultima_horas" => null];
    }
    $h = array_column($ts, 1);
    sort($h);
    $med = $n % 2 === 1 ? $h[intdiv($n, 2)] : ($h[$n / 2 - 1] + $h[$n / 2]) / 2;
    $media = array_sum($h) / $n;
    $dp = $n < 2 ? null : sqrt(array_sum(array_map(function ($x) use ($media) { return ($x - $media) ** 2; }, $h)) / $n);
    return ["n" => $n, "mediana" => $med, "dp" => $dp, "ultima" => $ts[$n - 1][0], "ultima_horas" => $ts[$n - 1][1]];
}

// O valor nominal de um campo num relógio, sem o do próprio relógio: o do calibre dele, senão o padrão do campo (o do grupo);
// [valor, origem (calibre, padrao ou vazio)]
function valor_nominal($r, $ident)
{
    $cal = valores_do_calibre_do_relogio((int)$r["id"])[$ident] ?? "";
    if ($cal !== "" && $cal !== null) {
        return [$cal, "calibre"];
    }
    $c = campos_do_relogio($r)[$ident] ?? null;
    return $c !== null && $c["padrao"] !== null && $c["padrao"] !== "" ? [$c["padrao"], "padrao"] : [null, "vazio"];
}

// A reserva de um relógio (o quadro Reserva do painel e recurso=reserva) até $agora: se ele faz o teste (o campo
// reserva_horas vale para ele: o mecânico) e se registra as observações (o mecânico e o solar, com o campo reserva_dias);
// o teste em andamento, os testes e as observações, a medida, o valor do cadastro do próprio relógio e se a medida pode ir
// para ele ("Usar a medida"); os protocolos e os tipos de observação para os formulários
function reserva_do_relogio($r, $agora)
{
    $campos = campos_do_relogio($r);
    $testar = isset($campos["reserva_horas"]);
    $observar = $testar || isset($campos["reserva_dias"]);
    if (!$observar) {
        return null;
    }
    $lista = reserva_lista((int)$r["id"], $agora);
    $testes = array_values(array_filter($lista, function ($x) { return $x["tipo"] === "teste"; }));
    $aberto = null;
    foreach ($testes as $x) {
        if ($x["em_andamento"]) {
            $aberto = $x;
            break;
        }
    }
    $m = reserva_medida((int)$r["id"], $agora);
    $proprio = valores_proprios((int)$r["id"])["reserva_horas"] ?? null;
    $proprio = $proprio !== null && $proprio !== "" && is_numeric($proprio) ? (float)$proprio : null;
    $usar = $testar && $m["mediana"] !== null ? (int)round($m["mediana"]) : null;
    return ["testar" => $testar, "observar" => $observar, "aberto" => $aberto, "testes" => $testes,
        "observacoes" => array_values(array_filter($lista, function ($x) { return $x["tipo"] !== "teste"; })),
        "medida" => $m["n"] === 0 ? null : ["testes" => $m["n"], "horas" => round($m["mediana"], 2), "horas_texto" => reserva_horas_txt($m["mediana"]),
            "variacao" => $m["dp"] === null ? null : round($m["dp"], 2), "ultimo" => date("Y-m-d H:i:s", $m["ultima"]), "ultimo_horas" => round($m["ultima_horas"], 2)],
        "cadastro_relogio" => $testar ? $proprio : null, "usar_horas" => $usar !== null && ($proprio === null || abs($proprio - $usar) >= 0.5) ? $usar : null,
        "protocolos" => array_map(function ($k) { return ["valor" => $k, "nome" => t(RESERVA_PROTOCOLOS[$k][0]), "unidade" => RESERVA_PROTOCOLOS[$k][1] === "voltas" ? t("voltas") : RESERVA_PROTOCOLOS[$k][1]]; },
            array_keys(RESERVA_PROTOCOLOS)),
        "tipos_observacao" => array_map(function ($k) { return ["valor" => $k, "nome" => t(RESERVA_OBSERVACOES[$k])]; }, array_keys(RESERVA_OBSERVACOES))];
}

// Declarado, medido e estimado: cada informação técnica do relógio numa tabelinha de três linhas (o que o fabricante ou o
// cadastro diz, o último teste e a estimativa de agora) com a confiança da regra única. A marcha (com a precisão: $pr), a
// reserva de marcha (o mecânico) e a de energia (o solar: só observações, sem calibrar) ($rs: reserva_do_relogio) e a
// estanqueidade ($sr: servicos_resumo). Cada linha: {valor, texto, detalhe, origem, origem_texto, data}; sem valor, o
// texto é null e o detalhe diz por quê
function tecnico_do_relogio($r, $agora, $pr, $rs, $sr)
{
    $linha = function ($valor = null, $texto = null, $detalhe = null, $origem = null, $data = null) {
        return ["valor" => $valor, "texto" => $texto, "detalhe" => $detalhe, "origem" => $origem, "origem_texto" => $origem === null ? null : origem_texto($origem), "data" => $data];
    };
    $campos = campos_do_relogio($r);
    $res = [];
    if ($pr !== null) {
        $ints = $pr["intervalos"];
        $ult = count($ints) > 0 ? $ints[0] : null;
        $faixa = $pr["esperada"]["de"] !== null && $pr["esperada"]["ate"] !== null ? ($pr["esperada"]["ate"] - $pr["esperada"]["de"]) / 2 : null;
        $tol = $faixa !== null && $faixa > 0 ? $faixa : 5.0;
        $ultimo_ponto = count($pr["pontos"]) > 0 ? strtotime($pr["pontos"][count($pr["pontos"]) - 1]["inicio"]) : null;
        // de onde vem a marcha esperada: a de (marcha_min), senão a até (marcha_max)
        $o = $pr["esperada"]["de"] !== null ? origem_valor((int)$r["id"], "marcha_min", $pr["esperada"]["de"])
            : ($pr["esperada"]["ate"] !== null ? origem_valor((int)$r["id"], "marcha_max", $pr["esperada"]["ate"]) : null);
        $fmt = function ($x) { return t("{1} s/dia", [1 => decimal_txt($x, 1)]); };
        $res[] = ["chave" => "marcha", "nome" => t("Marcha"), "unidade" => "s/dia",
            "declarado" => $linha(null, $pr["esperada"]["texto"], $pr["esperada"]["texto"] === null ? t("não cadastrada (a marcha esperada, no relógio ou no calibre)") : null, $o),
            "medido" => $ult === null ? $linha(null, null, count($pr["pontos"]) > 0 ? t("uma leitura só: leia de novo daqui a pelo menos um dia") : t("nenhuma leitura do desvio ainda"))
                : $linha($ult["marcha"], $ult["marcha_texto"], t("em {1} dias, {2}", [1 => decimal_txt($ult["dias"], 1), 2 => $ult["posicao_texto"]])
                    . ($pr["dentro"] === false ? t("; fora da marcha esperada") : ""), null, $ult["ate"]),
            "estimado" => $pr["marcha"] === null ? $linha(null, null, t("sem medição"))
                : $linha($pr["marcha"], $pr["marcha_texto"] . ($pr["marcha_dp_texto"] !== null ? " " . $pr["marcha_dp_texto"] : ""),
                    $pr["medicoes"] === 1 ? t("a única medição") : t("a média de {1} medições, pesada pelo tempo", [1 => $pr["medicoes"]])),
            "confianca" => confianca_medida("marcha", $pr["medicoes"], $ultimo_ponto, $agora, $pr["marcha_dp"], $tol, $fmt), "fora" => $pr["dentro"] === false];
    }
    if ($rs !== null && $rs["testar"]) {
        [$nom, $o] = valor_nominal($r, "reserva_horas");
        $m = $rs["medida"];
        $ult = null;
        foreach ($rs["testes"] as $x) {
            if ($x["horas"] !== null) {
                $ult = $x;
                break;
            }
        }
        $em_uso = valores_do_relogio((int)$r["id"])["reserva_horas"] ?? $nom;
        $res[] = ["chave" => "reserva", "nome" => t("Reserva de marcha"), "unidade" => "h",
            "declarado" => $linha(is_numeric($nom) ? (float)$nom : null, is_numeric($nom) ? reserva_horas_txt((float)$nom) : null,
                $rs["cadastro_relogio"] !== null ? t("no cadastro deste relógio: {1} (é o que vale na conta)", [1 => reserva_horas_txt($rs["cadastro_relogio"])])
                    : (is_numeric($nom) ? null : t("não cadastrada")), $o),
            "medido" => $ult === null ? $linha(null, null, $rs["aberto"] !== null ? t("teste em andamento") : t("nenhum teste ainda: carregue até o fim e marque quando parar"))
                : $linha($ult["horas"], $ult["horas_texto"], $ult["protocolo_texto"], null, $ult["fim"]),
            "estimado" => $m === null ? $linha(is_numeric($em_uso) ? (float)$em_uso : null, is_numeric($em_uso) ? reserva_horas_txt((float)$em_uso) : null, t("sem teste: vale o cadastro"))
                : $linha($m["horas"], $m["horas_texto"] . ($m["variacao"] !== null ? " ±" . reserva_horas_txt($m["variacao"]) : ""),
                    $m["testes"] === 1 ? t("o único teste") : t("a mediana de {1} testes", [1 => $m["testes"]])),
            "confianca" => $m === null ? null : confianca_medida("reserva", $m["testes"], strtotime($m["ultimo"]), $agora, $m["variacao"], max(1.0, $m["horas"] * 0.1), "reserva_horas_txt", true),
            "fora" => false];
    } elseif ($rs !== null && isset($campos["reserva_dias"])) {
        [$nom, $o] = valor_nominal($r, "reserva_dias");
        $obs = count($rs["observacoes"]) > 0 ? $rs["observacoes"][0] : null;
        $res[] = ["chave" => "reserva_energia", "nome" => t("Reserva de energia"), "unidade" => "dias",
            "declarado" => $linha(is_numeric($nom) ? (float)$nom : null, is_numeric($nom) ? t("{1} dias", [1 => decimal_txt((float)$nom, 1)]) : null, is_numeric($nom) ? null : t("não cadastrada"), $o),
            "medido" => $obs === null ? $linha(null, null, t("só observações (parou, o indicador de carga baixa): nenhuma ainda"))
                : $linha(null, $obs["tipo_texto"], $obs["observacao"] ?? t("a última observação"), null, $obs["inicio"]),
            "estimado" => $linha(null, null, t("não calculada: a recarga depende da luz, que o sistema não mede; um número aqui seria falsa precisão")),
            "confianca" => null, "fora" => false];
    }
    if (isset($campos["resistencia_agua_declarada"]) || ($sr !== null && $sr["estanqueidade"] !== null)) {
        $nom = valores_do_relogio((int)$r["id"])["resistencia_agua_declarada"] ?? null;
        $nom = $nom !== null && $nom !== "" && is_numeric($nom) ? $nom : null;
        $o = $nom !== null ? origem_valor((int)$r["id"], "resistencia_agua_declarada", $nom) : null;
        $e = $sr !== null ? $sr["estanqueidade"] : null;
        $n_e = $sr !== null ? $sr["testes_estanqueidade"] : 0;
        $res[] = ["chave" => "estanqueidade", "nome" => t("Resistência à água"), "unidade" => "m",
            "declarado" => $linha($nom !== null ? (float)$nom : null, $nom !== null ? t("{1} m", [1 => decimal_txt((float)$nom, 0)]) : null, $nom !== null ? t("do fabricante") : t("não cadastrada"), $o),
            "medido" => $e === null ? $linha(null, null, t("nenhum teste de estanqueidade nos serviços"))
                : $linha($e["bar"], $e["bar"] !== null ? t("{1} a {2} bar", [1 => $e["resultado_texto"], 2 => decimal_txt($e["bar"], 1)]) : $e["resultado_texto"], null, null, $e["data"]),
            "estimado" => $e === null ? $linha(null, null, t("sem teste, a resistência declarada não comprova a vedação de agora"))
                : $linha(null, $e["resultado"] === "aprovado" ? t("vedação comprovada no último teste") : t("vedação reprovada no último teste"),
                    t("o teste de {1}", [1 => data_txt(strtotime($e["data"] . " 00:00:00"))])),
            "confianca" => $e === null ? null : confianca_medida("estanqueidade", $n_e, strtotime($e["data"] . " 12:00:00"), $agora, null, null, null, true),
            "fora" => $e !== null && $e["resultado"] === "reprovado"];
    }
    return $res;
}

// O destaque no alto da página Hoje (a Configuração): o relógio do dia (dia, o padrão) ou o que está no pulso agora (pulso)
function destaque_hoje()
{
    return cfg("destaque_hoje") === "pulso" ? "pulso" : "dia";
}

// Qual relógio vai no destaque da página Hoje, e por quê. $rels: os relógios com id e em_uso (o pulso aberto agora, como
// a tabela mostra); $dia: o plano de hoje (ou null). No "pulso", o relógio no pulso agora (havendo mais de um, o que foi ao
// pulso por último); ninguém no pulso, fica o relógio do dia, e o texto diz isso.
// Devolve {mostra, relogio_id, e (dia ou pulso: o que o relógio do destaque é), do_dia, no_pulso, texto}
function destaque_da_pagina_hoje($rels, $dia)
{
    $mostra = destaque_hoje();
    $dia_id = $dia !== null ? (int)$dia["relogio_id"] : null;
    $no_pulso = [];
    foreach ($rels as $r) {
        if ($r["em_uso"]) {
            $no_pulso[] = (int)$r["id"];
        }
    }
    $pulso_id = null;
    if (count($no_pulso) > 0) {
        // o último que foi ao pulso (a sessão aberta mais recente); sem sessão aberta (o pulso posto sozinho), o do dia
        $ultimo = linha("SELECT l.relogio_id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE t.identificador = 'pulso' AND l.fim IS NULL
            AND l.relogio_id IN (" . implode(",", $no_pulso) . ") ORDER BY l.inicio DESC, l.id DESC LIMIT 1");
        $pulso_id = $ultimo ? (int)$ultimo["relogio_id"] : (in_array($dia_id, $no_pulso, true) ? $dia_id : $no_pulso[0]);
    }
    if ($mostra === "pulso" && $pulso_id !== null) {
        $id = $pulso_id;
        $e = $pulso_id === $dia_id ? "dia" : "pulso";
        $texto = $pulso_id === $dia_id ? t("O relógio do dia, no pulso agora.") : t("No pulso agora{1}", [1 => $dia_id !== null ? t("; o relógio do dia é outro.") : t("; hoje não tem relógio do dia.")]);
    } else {
        $id = $dia_id;
        $e = "dia";
        $texto = $mostra === "pulso" ? t("Nenhum relógio no pulso agora: aqui, o relógio do dia.")
            : ($dia_id !== null && $dia_id === $pulso_id ? t("O relógio do dia, no pulso agora.") : t("O relógio do dia."));
    }
    return ["mostra" => $mostra, "relogio_id" => $id, "e" => $e, "do_dia" => $id !== null && $id === $dia_id, "no_pulso" => $id !== null && $id === $pulso_id,
        "texto" => $id === null ? t("Nenhum relógio para hoje e nenhum no pulso.") : $texto];
}

// A cópia de segurança dos documentos no banco tem três níveis, e o de cima vale sobre os de baixo:
// 1. a coleção: a Configuração "Cópia dos documentos no banco" (documentos_copia): todos, todo arquivo vai também para o
//    banco; nenhum, nenhum vai (nem o que o relógio ou o arquivo pedem); vazio (null), quem decide é o relógio e o arquivo.
//    (O DOCUMENTOS_COPIA_BANCO do config.php, que valia para todas as coleções do servidor, não vale mais: cada dono
//    escolhe na Configuração da coleção dele; definido, a Configuração avisa.)
// 2. o relógio: a marca copia_banco dele. Marcada, todo arquivo dele vai para o banco, inclusive os que já estavam;
// 3. o arquivo: a marca copia_banco do documento, escolhida no envio ou depois, em editar.
function documentos_copia_sistema()
{
    $c = (string)cfg("documentos_copia");
    return $c === "todos" ? true : ($c === "nenhum" ? false : null);
}

// A marca copia_banco de um relógio (lidas todas uma vez por requisição; $recarregar depois de mudar uma)
function relogio_copia_banco($rid, $recarregar = false)
{
    // guardado por coleção (o cron passa por todas)
    static $todos_ = [];
    $k_ = (int)colecao_atual();
    $marcas = $todos_[$k_] ?? null;
    if ($marcas === null || $recarregar) {
        $marcas = [];
        foreach (linhas("SELECT id, copia_banco FROM relogio") as $r) {
            $marcas[(int)$r["id"]] = (int)$r["copia_banco"] === 1;
        }
    }
    $todos_[$k_] = $marcas;
    return $marcas[(int)$rid] ?? false;
}

// Quem pede a cópia de um documento no banco: "sistema", "relogio" ou "arquivo" (o primeiro nível que pede); null: ninguém
// pede (o arquivo fica só na pasta), ou o sistema não deixa
function documento_copia_por($d)
{
    $sistema = documentos_copia_sistema();
    if ($sistema !== null) {
        return $sistema ? "sistema" : null;
    }
    if (($d["relogio_id"] ?? null) !== null && relogio_copia_banco((int)$d["relogio_id"])) {
        return "relogio";
    }
    return (int)($d["copia_banco"] ?? 0) === 1 ? "arquivo" : null;
}

// Tira do banco a cópia de um documento que ninguém mais pede, mas só se o arquivo da pasta está lá e é o mesmo da cópia (o
// SHA-256 confere): o documento nunca fica sem as duas. Devolve "" se tirou, ou o motivo de não ter tirado
function documento_tirar_do_banco($d)
{
    $pasta = documentos_pasta();
    $caminho = $pasta !== null ? $pasta . "/" . $d["arquivo"] : null;
    if ($caminho === null || !is_file($caminho)) {
        return t("o arquivo não está na pasta (a cópia no banco é a única)");
    }
    if ($d["hash"] === null || $d["hash"] === "" || hash_file("sha256", $caminho) !== $d["hash"]) {
        return t("o arquivo da pasta não confere com a cópia no banco");
    }
    sql("UPDATE documento SET no_banco = 0, hash = NULL WHERE id = ?", [(int)$d["id"]]);
    sql("DELETE FROM documento_parte WHERE documento_id = ?", [(int)$d["id"]]);
    return "";
}

// O tamanho de cada pedaço da cópia no banco: 4 MB cabem em qualquer max_allowed_packet do MySQL (o menor padrão é 16 MB)
// e na memória do PHP, um de cada vez
const DOCUMENTO_PEDACO = 4 * 1024 * 1024;

// Copia o arquivo de um documento (e a miniatura, na parte -1) para o banco, em pedaços, lendo da pasta um pedaço por vez;
// no fim, marca no_banco = 1 com o SHA-256 do arquivo. Devolve "" se deu certo, ou o motivo (a cópia parcial sai)
function documento_copiar_para_banco($d)
{
    $pasta = documentos_pasta();
    $caminho = $pasta !== null ? $pasta . "/" . $d["arquivo"] : null;
    if ($caminho === null || !is_file($caminho)) {
        return t("o arquivo não está na pasta");
    }
    try {
        sql("DELETE FROM documento_parte WHERE documento_id = ?", [(int)$d["id"]]);
        sql("UPDATE documento SET no_banco = 0 WHERE id = ?", [(int)$d["id"]]);
        $h = hash_init("sha256");
        $f = fopen($caminho, "rb");
        $parte = 0;
        while (!feof($f)) {
            $pedaco = fread($f, DOCUMENTO_PEDACO);
            if ($pedaco === false || $pedaco === "") {
                break;
            }
            // o fread de um arquivo comum devolve o pedaço inteiro; o resto do pedaço, se faltar, vem nas voltas seguintes
            while (strlen($pedaco) < DOCUMENTO_PEDACO && !feof($f)) {
                $mais = fread($f, DOCUMENTO_PEDACO - strlen($pedaco));
                if ($mais === false || $mais === "") {
                    break;
                }
                $pedaco .= $mais;
            }
            hash_update($h, $pedaco);
            sql("INSERT INTO documento_parte (documento_id, parte, dados) VALUES (?, ?, ?)", [(int)$d["id"], $parte, documento_pedaco_para_banco($pedaco)]);
            $parte++;
        }
        fclose($f);
        if ($d["miniatura"] !== null && $d["miniatura"] !== "" && is_file($pasta . "/" . $d["miniatura"])) {
            sql("INSERT INTO documento_parte (documento_id, parte, dados) VALUES (?, -1, ?)", [(int)$d["id"], (string)file_get_contents($pasta . "/" . $d["miniatura"])]);
        }
        sql("UPDATE documento SET no_banco = 1, hash = ? WHERE id = ?", [hash_final($h), (int)$d["id"]]);
        return "";
    } catch (Throwable $t) {
        try {
            sql("DELETE FROM documento_parte WHERE documento_id = ?", [(int)$d["id"]]);
        } catch (Throwable $t2) {
            // o banco não respondeu nem para limpar: a parte que ficou é refeita na próxima cópia
        }
        return $t->getMessage();
    }
}

// Um pedaço de arquivo pronto para a coluna binária: no PostgreSQL, um pedaço que parece texto (um CSV, um XML) iria como
// texto para o bytea, e as barras invertidas dele virariam escapes; vai então já no formato binário dele (\x e o hexadecimal)
function documento_pedaco_para_banco($pedaco)
{
    return banco_tipo() === "pgsql" && !banco_binario($pedaco) ? "\\x" . bin2hex($pedaco) : $pedaco;
}

// Recria na pasta o arquivo de um documento (e a miniatura) a partir da cópia no banco, um pedaço por vez, conferindo o
// SHA-256; o arquivo só aparece na pasta quando está inteiro e certo. Devolve verdadeiro se o arquivo está na pasta no fim
function documento_restaurar($d)
{
    $pasta = documentos_pasta();
    if ($pasta === null || strpos((string)$d["arquivo"], "..") !== false) {
        return false;
    }
    $caminho = $pasta . "/" . $d["arquivo"];
    $mini = $d["miniatura"] !== null && $d["miniatura"] !== "" ? $pasta . "/" . $d["miniatura"] : null;
    if ($mini !== null && !is_file($mini) && (int)$d["no_banco"] === 1) {
        $m = valor("SELECT dados FROM documento_parte WHERE documento_id = ? AND parte = -1", [(int)$d["id"]]);
        if ($m !== null) {
            if (!is_dir(dirname($mini))) {
                @mkdir(dirname($mini), 0750, true);
            }
            @file_put_contents($mini, $m);
        }
    }
    if (documento_inteiro($d, $caminho)) {
        return true;
    }
    if ((int)$d["no_banco"] !== 1) {
        return false;
    }
    if (!is_dir(dirname($caminho))) {
        @mkdir(dirname($caminho), 0750, true);
    }
    $tmp = $caminho . ".restaurando";
    $f = @fopen($tmp, "wb");
    if ($f === false) {
        return false;
    }
    $h = hash_init("sha256");
    foreach (linhas("SELECT parte FROM documento_parte WHERE documento_id = ? AND parte >= 0 ORDER BY parte", [(int)$d["id"]]) as $p) {
        $pedaco = (string)valor("SELECT dados FROM documento_parte WHERE documento_id = ? AND parte = ?", [(int)$d["id"], (int)$p["parte"]]);
        hash_update($h, $pedaco);
        fwrite($f, $pedaco);
    }
    fclose($f);
    if ($d["hash"] !== null && hash_final($h) !== $d["hash"]) {
        @unlink($tmp);
        return false;
    }
    // a cópia confere: ela fica no lugar do cortado
    if (is_file($caminho)) {
        @unlink($caminho);
    }
    return @rename($tmp, $caminho);
}

// Apaga uma pasta e tudo o que há dentro
function apagar_pasta($pasta)
{
    $n = 0;
    foreach (scandir($pasta) ?: [] as $x) {
        if ($x === "." || $x === "..") {
            continue;
        }
        if (is_dir($pasta . "/" . $x) && !is_link($pasta . "/" . $x)) {
            $n += apagar_pasta($pasta . "/" . $x);
        } elseif (@unlink($pasta . "/" . $x)) {
            $n++;
        }
    }
    @rmdir($pasta);
    return $n;
}

// uma linha de erro do registro do cron: conta o erro (o texto sai no idioma da instalação, então o erro não se acha pela
// palavra) e devolve o texto
function registro_erro($texto)
{
    $GLOBALS["REGISTRO_ERROS"] = ($GLOBALS["REGISTRO_ERROS"] ?? 0) + 1;
    return $texto;
}

// A manutenção dos documentos, que o cron roda a cada minuto. Devolve as linhas do registro (vazio: nada a fazer):
// 1. o documento cujo arquivo sumiu da pasta, ou ficou cortado (documento_inteiro), volta do banco (a cópia de segurança);
// 2. o documento que tem a cópia pedida (documento_copia_por: o sistema, o relógio ou o próprio arquivo) e ainda não a tem
//    no banco ganha (até $orcamento bytes por rodada, para não pesar); o que tem a cópia e ninguém mais pede perde (só se o
//    arquivo da pasta confere com ela: documento_tirar_do_banco);
// 3. a pasta de um relógio que não existe mais sai inteira (r<id>), e o arquivo que não é de nenhum documento sai (só os
//    de mais de 10 minutos: um envio pode estar no meio); o documento de um relógio que não existe mais sai do banco.
// Com $diario, conta também os documentos perdidos (sem o arquivo inteiro na pasta e sem a cópia no banco).
function documentos_manutencao($orcamento, $diario = false)
{
    $log = [];
    $pasta = documentos_pasta();
    if ($pasta === null) {
        return $log;
    }
    $orfaos = (int)valor("SELECT COUNT(*) FROM documento WHERE relogio_id IS NOT NULL AND relogio_id NOT IN (SELECT id FROM relogio)");
    if ($orfaos > 0) {
        sql("DELETE FROM documento WHERE relogio_id IS NOT NULL AND relogio_id NOT IN (SELECT id FROM relogio)");
        $log[] = t("documentos: {1}{2}", [1 => $orfaos, 2 => $orfaos === 1 ? t(" documento de relógio que não existe mais apagado") : t(" documentos de relógios que não existem mais apagados")]);
    }
    $restaurados = [];
    $perdidos = 0;
    $copiados = 0;
    $falhas = [];
    $usados = [];
    $tirados = 0;
    foreach (linhas("SELECT id, relogio_id, titulo, arquivo, miniatura, no_banco, copia_banco, hash, tamanho FROM documento ORDER BY id") as $d) {
        $usados[$d["arquivo"]] = true;
        if ($d["miniatura"] !== null && $d["miniatura"] !== "") {
            $usados[$d["miniatura"]] = true;
        }
        $tem = documento_inteiro($d, $pasta . "/" . $d["arquivo"]);
        $mini_falta = $d["miniatura"] !== null && $d["miniatura"] !== "" && !is_file($pasta . "/" . $d["miniatura"]);
        if (!$tem || $mini_falta) {
            if (documento_restaurar($d) && !$tem) {
                $restaurados[] = $d["titulo"];
            } elseif (!$tem) {
                $perdidos++;
            }
        } elseif ((int)$d["no_banco"] !== 1 && documento_copia_por($d) !== null && $orcamento > 0) {
            $erro = documento_copiar_para_banco(linha("SELECT * FROM documento WHERE id = ?", [(int)$d["id"]]));
            if ($erro === "") {
                $copiados++;
                $orcamento -= (int)$d["tamanho"];
            } else {
                $falhas[] = $d["titulo"] . " (" . $erro . ")";
            }
        } elseif ((int)$d["no_banco"] === 1 && documento_copia_por($d) === null && $orcamento > 0) {
            // conferir o arquivo (o SHA-256) também pesa: conta no orçamento da rodada
            $orcamento -= (int)$d["tamanho"];
            if (documento_tirar_do_banco($d) === "") {
                $tirados++;
            }
        }
    }
    if (count($restaurados) > 0) {
        $log[] = t("documentos: {1}{2} a partir do banco: {3}", [1 => count($restaurados), 2 => count($restaurados) === 1 ? t(" arquivo sumido ou cortado na pasta, recriado") : t(" arquivos sumidos ou cortados na pasta, recriados"), 3 => implode(", ", array_slice($restaurados, 0, 10))]);
    }
    if ($copiados > 0) {
        $log[] = t("documentos: {1}{2} para o banco", [1 => $copiados, 2 => $copiados === 1 ? t(" documento copiado") : t(" documentos copiados")]);
    }
    if ($tirados > 0) {
        $log[] = t("documentos: {1}{2} do banco (ninguém mais pede; o arquivo continua na pasta)", [1 => $tirados, 2 => $tirados === 1 ? t(" cópia tirada") : t(" cópias tiradas")]);
    }
    if (count($falhas) > 0) {
        $log[] = registro_erro(t("erro: a cópia no banco falhou: {1}", [1 => implode("; ", array_slice($falhas, 0, 5))]));
    }
    if ($diario && $perdidos > 0) {
        $log[] = t("aviso: {1}{2} na pasta e sem a cópia no banco (perdidos: exclua-os na página Documentos)", [1 => $perdidos, 2 => $perdidos === 1 ? t(" documento sem o arquivo inteiro") : t(" documentos sem o arquivo inteiro")]);
    }
    // a pasta: as dos relógios (r<id>) e dos calibres (c<id>) que não existem mais, e os arquivos de nenhum documento
    $relogios = [];
    foreach (linhas("SELECT id FROM relogio") as $r) {
        $relogios["r" . (int)$r["id"]] = true;
    }
    foreach (linhas("SELECT id FROM calibre") as $r) {
        $relogios["c" . (int)$r["id"]] = true;
    }
    $pastas_apagadas = [];
    $soltos = 0;
    foreach (scandir($pasta) ?: [] as $x) {
        if (preg_match("/^[rc]\\d+$/", $x) !== 1 || !is_dir($pasta . "/" . $x)) {
            continue;
        }
        if (!isset($relogios[$x])) {
            $n = apagar_pasta($pasta . "/" . $x);
            $pastas_apagadas[] = $x . " (" . $n . ($n === 1 ? " arquivo)" : " arquivos)");
            continue;
        }
        foreach (scandir($pasta . "/" . $x) ?: [] as $a) {
            $rel = $x . "/" . $a;
            if ($a !== "." && $a !== ".." && is_file($pasta . "/" . $rel) && !isset($usados[$rel]) && filemtime($pasta . "/" . $rel) < time() - 600) {
                if (@unlink($pasta . "/" . $rel)) {
                    $soltos++;
                }
            }
        }
    }
    if (count($pastas_apagadas) > 0) {
        $log[] = t("documentos: pastas de relógios que não existem mais apagadas: {1}", [1 => implode(", ", $pastas_apagadas)]);
    }
    if ($soltos > 0) {
        $log[] = t("documentos: {1}{2} da pasta", [1 => $soltos, 2 => $soltos === 1 ? t(" arquivo que não era de nenhum documento apagado") : t(" arquivos que não eram de nenhum documento apagados")]);
    }
    return $log;
}

// O relógio do dia entra no pulso sozinho no início do horário de uso (a Configuração, ao lado do horário; é o padrão).
// Desligado, ele só entra pelo Pôs.
function pulso_poe_sozinho()
{
    return cfg("pulso_auto_inicio") !== "0";
}

// O relógio sai do pulso sozinho no fim do horário de uso (a Configuração, ao lado do horário; é o padrão): o "fecha às" do
// tipo No pulso segue o fim do horário. Desligado, ele só sai pelo Tirou.
function pulso_tira_sozinho()
{
    return cfg("pulso_auto_fim") !== "0";
}

// O solar sai do sol sozinho na hora da Configuração (sol_fim; é o padrão): o "fecha às" do tipo No sol segue essa hora.
// Desligado (sol_auto_fim = 0), a sessão no sol só fecha pelo Tirar do sol.
function sol_tira_sozinho()
{
    return cfg("sol_auto_fim") !== "0";
}

// A hora em que a sessão no sol fecha sozinha (HH:MM): a da Configuração, senão a que o tipo No sol já tem, senão 18:00. Fica
// guardada mesmo com o tirar sozinho desligado, para quando ele for ligado de novo
function sol_fim_hora()
{
    if (preg_match("/^([01][0-9]|2[0-3]):[0-5][0-9]\$/", cfg("sol_fim")) === 1) {
        return cfg("sol_fim");
    }
    $t = lancamento_tipos()["sol"] ?? null;
    return $t !== null && $t["fecha_as"] !== null ? substr((string)$t["fecha_as"], 0, 5) : "18:00";
}

// As horas no pulso de um relógio entre dois instantes (as sessões juntas, sem contar duas vezes a mesma hora; a aberta, até
// o fim do intervalo)
function horas_no_pulso($rid, $de, $ate)
{
    $h = 0.0;
    $ate_aqui = $de;
    foreach (linhas("SELECT l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo tp ON tp.id = l.tipo_id WHERE l.relogio_id = ? AND tp.identificador = 'pulso'
        AND l.inicio < ? AND (l.fim IS NULL OR l.fim > ?) ORDER BY l.inicio, l.id", [(int)$rid, date("Y-m-d H:i:s", $ate), date("Y-m-d H:i:s", $de)]) as $sp) {
        $a = max($ate_aqui, strtotime($sp["inicio"]));
        $b = min($ate, $sp["fim"] === null ? $ate : strtotime($sp["fim"]));
        if ($b > $a) {
            $h += ($b - $a) / 3600;
            $ate_aqui = $b;
        }
    }
    return $h;
}

// Os trechos no pulso de um relógio entre $de e $ate (instantes), cortados nas bordas: [[início, fim], ...]
function periodos_no_pulso($rid, $de, $ate)
{
    $res = [];
    foreach (linhas("SELECT l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo tp ON tp.id = l.tipo_id WHERE l.relogio_id = ? AND tp.identificador = 'pulso'
        AND l.inicio < ? AND (l.fim IS NULL OR l.fim > ?) ORDER BY l.inicio, l.id", [(int)$rid, date("Y-m-d H:i:s", $ate), date("Y-m-d H:i:s", $de)]) as $sp) {
        $a = max($de, strtotime($sp["inicio"]));
        $b = min($ate, $sp["fim"] === null ? $ate : strtotime($sp["fim"]));
        if ($b > $a) {
            $res[] = [$a, $b];
        }
    }
    return $res;
}

// A marcação de uma leitura (uso ou repouso) contra o pulso registrado no instante dela (e não no intervalo desde a
// leitura anterior: a leitura é uma foto daquele instante, e o que veio antes dela é a conta da medição, não a marcação).
// Bate: null. Não bate: a pergunta para a pessoa confirmar. A marcação manda: confirmada, ela vale como foi marcada.
function estado_leitura_confere($rid, $q, $estado)
{
    $agora_no_pulso = count(periodos_no_pulso($rid, $q, $q + 1)) > 0;
    if (($estado === "uso") === $agora_no_pulso) {
        return null;
    }
    return t("Para mim, nesse instante ({1}) o relógio estava {2}. Esta leitura {3} parece incorreta. Deseja continuar mesmo assim?",
        [1 => data_txt($q, true, true), 2 => $agora_no_pulso ? t("no pulso") : t("fora do pulso"), 3 => $estado === "uso" ? t("em uso") : t("em repouso")]);
}

// A marcação das leituras de carga de um relógio (id do lançamento => uso ou repouso): a gravada, ou, na leitura sem
// marcação (de antes dela, ou de um script que não diz), a do pulso registrado naquele instante
function leituras_marcadas($rid)
{
    $pulsos = linhas("SELECT l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo tp ON tp.id = l.tipo_id WHERE l.relogio_id = ? AND tp.identificador = 'pulso'", [(int)$rid]);
    $res = [];
    foreach (linhas("SELECT l.id, l.inicio, l.estado FROM lancamento l JOIN lancamento_tipo tp ON tp.id = l.tipo_id WHERE l.relogio_id = ? AND tp.formato = 'valor' AND tp.marcha IS NULL", [(int)$rid]) as $l) {
        $e = $l["estado"];
        if ($e === null || $e === "") {
            $t = strtotime($l["inicio"]);
            $e = "repouso";
            foreach ($pulsos as $p) {
                if (strtotime($p["inicio"]) <= $t && ($p["fim"] === null || strtotime($p["fim"]) > $t)) {
                    $e = "uso";
                    break;
                }
            }
        }
        $res[(int)$l["id"]] = ["estado" => $e, "marcada" => $l["estado"] !== null && $l["estado"] !== "", "inicio" => $l["inicio"]];
    }
    return $res;
}

// As medições do gasto de um relógio para a conta (as usadas, em ordem de fim). Pela marcação das leituras ($por_marcacao;
// null: conforme o motor do relógio, os motores marcacao e formulas): as duas leituras de uma medição marcadas em uso dão uma medição só de uso (o intervalo inteiro em
// uso, por dia de uso); as duas em repouso, só de repouso (o intervalo inteiro guardado); marcações diferentes, nenhuma
// das duas (fica fora da conta). Sem a marcação (false), as horas no pulso e fora que o pulso registrado deu.
// Resposta: [as linhas para o gasto_medido, id da medição => [as duas marcações, se entra]].
function medicoes_da_conta($rid, $por_marcacao = null)
{
    if ($por_marcacao === null) {
        $r_ = linha("SELECT * FROM relogio WHERE id = ?", [(int)$rid]);
        $por_marcacao = $r_ ? in_array(gasto_motor_de($r_)[0], ["marcacao", "formulas"], true) : false;
    }
    $linhas = linhas("SELECT m.id, m.lancamento_id, m.medida, m.taxa, m.peso_horas, m.inicio, m.fim, m.horas_pulso, m.horas_guardado, m.de_valor, m.ate_valor
        FROM medicao m JOIN lancamento l ON l.id = m.lancamento_id WHERE l.relogio_id = ? AND m.usada = 1 ORDER BY m.fim, m.id", [(int)$rid]);
    // as marcações das duas leituras de cada medição saem sempre (a tabela Medições do gasto mostra em qualquer motor); só
    // o motor pela marcação (e o das fórmulas) escolhe as medições por elas
    $marc = [];
    $leit = leituras_marcadas($rid);
    $por_inicio = [];
    foreach ($leit as $lid => $x) {
        $por_inicio[date("Y-m-d H:i:s", strtotime($x["inicio"]))] = $x["estado"];
    }
    $res = [];
    foreach ($linhas as $m) {
        $fim_e = $leit[(int)$m["lancamento_id"]]["estado"] ?? null;
        $ini_e = $por_inicio[date("Y-m-d H:i:s", strtotime($m["inicio"]))] ?? null;
        $entra = $fim_e !== null && $fim_e === $ini_e;
        $marc[(int)$m["id"]] = ["de" => $ini_e, "ate" => $fim_e, "entra" => $por_marcacao ? $entra : null];
        if (!$por_marcacao) {
            $res[] = $m;
        } elseif ($entra) {
            $h = (strtotime($m["fim"]) - strtotime($m["inicio"])) / 3600;
            $m["medida"] = $fim_e;
            $m["horas_pulso"] = $fim_e === "uso" ? $h : 0.0;
            $m["horas_guardado"] = $fim_e === "uso" ? 0.0 : $h;
            $res[] = $m;
        }
    }
    return [$res, $marc];
}

// Depois de corrigir o pulso de um relógio entre $de e $ate (uma sessão alterada, excluída ou lançada no passado), as
// medições do gasto que cobrem esse trecho refazem as horas no pulso e fora dele (a queda entre as leituras não muda)
function recalcular_medicoes($rid, $de, $ate)
{
    foreach (linhas("SELECT m.id, m.medida, m.inicio, m.fim FROM medicao m JOIN lancamento l ON l.id = m.lancamento_id WHERE l.relogio_id = ? AND m.inicio < ? AND m.fim > ?", [(int)$rid, date("Y-m-d H:i:s", $ate), date("Y-m-d H:i:s", $de)]) as $m) {
        $a = strtotime($m["inicio"]);
        $b = strtotime($m["fim"]);
        $hp = horas_no_pulso($rid, $a, $b);
        $hg = ($b - $a) / 3600 - $hp;
        // o peso escolhido aqui (e não num CASE com parâmetros, que o PostgreSQL não sabe de que tipo são)
        sql("UPDATE medicao SET horas_pulso = ?, horas_guardado = ?, peso_horas = ? WHERE id = ?",
            [round($hp, 2), round($hg, 2), round($m["medida"] === "uso" ? $hp : $hg, 2), (int)$m["id"]]);
    }
}

// As sessões esquecidas abertas (sem fim) de um tipo que fecha sozinho ("fecha às"): passaram da hora, ganham o fim nela, no
// dia em que começaram (a conta já as tratava assim; gravado, o Pôs do dia seguinte não esbarra nela, e o Tirou não a
// estica até agora). $relogio: só as desse relógio (null: todas).
function fechar_esquecidas($agora, $relogio = null)
{
    foreach (linhas("SELECT l.id, l.inicio, t.fecha_as FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.fim IS NULL AND t.formato = 'sessao' AND t.fecha_as IS NOT NULL" . ($relogio !== null ? " AND l.relogio_id = ?" : ""),
        $relogio !== null ? [(int)$relogio] : []) as $l) {
        $fecha = max(strtotime($l["inicio"]), instante(dia_da_colecao(strtotime($l["inicio"])), $l["fecha_as"]));
        if ($fecha <= $agora) {
            sql("UPDATE lancamento SET fim = ? WHERE id = ?", [date("Y-m-d H:i:s", $fecha), (int)$l["id"]]);
        }
    }
}

// O dia de hoje já começou no pulso: pondo sozinho, passou o início do horário de uso; senão, o relógio do dia já foi posto
// no pulso hoje (a sessão do rodízio de hoje já começou)
function dia_comecou($agora)
{
    $hoje = date("Y-m-d", $agora);
    if (pulso_poe_sozinho()) {
        return $agora >= instante($hoje, cfg("uso_inicio"));
    }
    return valor("SELECT l.id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE t.identificador = 'pulso' AND l.origem = 'rodizio'
        AND l.inicio >= ? AND l.inicio <= ? LIMIT 1", [$hoje . " 00:00:00", date("Y-m-d H:i:s", $agora)]) !== null;
}

// A sessão no pulso do relógio do dia: no horário de uso, o relógio do plano de hoje ganha uma sessão "pulso" de origem
// rodízio, do início do horário de uso até o fim dele (ou, sem tirar sozinho, até o Tirou). Uma por dia. Sem pôr sozinho,
// nada: a sessão é a do Pôs. Com o relógio noutra sessão exclusiva (no carregador, no sol, no winder), espera ele sair de lá,
// e a sessão começa nessa hora.
function sessao_do_dia($agora)
{
    if (!pulso_poe_sozinho()) {
        return;
    }
    $hoje = date("Y-m-d", $agora);
    $ini = instante($hoje, cfg("uso_inicio"));
    $fim = instante($hoje, cfg("uso_fim"));
    $p = plano_do_dia($hoje);
    // o relógio do dia que saiu da coleção hoje (vendido, emprestado...) não vai mais ao pulso sozinho
    if ($p && posse_de(linha("SELECT posse FROM relogio WHERE id = ?", [(int)$p["relogio_id"]]) ?: []) !== "colecao") {
        return;
    }
    $tipo = lancamento_tipos()["pulso"] ?? null;
    // uma só por dia, de qualquer relógio: a troca do relógio do dia (usando hoje) fecha a de um e abre a do outro
    if ($p && $tipo && $agora >= $ini && $agora < $fim && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ?",
        [(int)$tipo["id"], $hoje]) === null) {
        // o relógio num lugar só: noutra sessão exclusiva agora (no carregador, no sol, no winder), ele não vai ao pulso sozinho
        // (espera ele sair de lá); se saiu de uma depois do começo do horário, o pulso começa quando ele saiu
        $outra = linha("SELECT MAX(CASE WHEN l.fim IS NULL OR l.fim > ? THEN 1 ELSE 0 END) AS aberta, MAX(l.fim) AS fim FROM lancamento l
            JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.exclusiva = 1 AND t.id <> ? AND l.inicio < ? AND (l.fim IS NULL OR l.fim > ?)",
            [date("Y-m-d H:i:s", $agora), (int)$p["relogio_id"], (int)$tipo["id"], date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $ini)]);
        if ($outra && (int)$outra["aberta"] === 1) {
            return;
        }
        $comeca = $outra && $outra["fim"] !== null ? max($ini, (int)strtotime($outra["fim"])) : $ini;
        sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, 'rodizio', NOW())",
            [(int)$p["relogio_id"], (int)$tipo["id"], date("Y-m-d H:i:s", $comeca), pulso_tira_sozinho() ? date("Y-m-d H:i:s", $fim) : null]);
    }
}

// Um dia livre do plano (Deixar o período livre): sem relógio e sem sorteio, para escolher à mão
function dia_livre($data)
{
    return valor("SELECT data FROM plano_livre WHERE data = ?", [$data]) !== null;
}

// Os dias livres de um período (AAAA-MM-DD), em ordem
function dias_livres($de, $ate)
{
    return array_map(function ($x) { return substr((string)$x["data"], 0, 10); }, linhas("SELECT data FROM plano_livre WHERE data >= ? AND data <= ? ORDER BY data",
        [$de !== "" ? $de : "0001-01-01", $ate !== "" ? $ate : "9999-12-31"]));
}

// "só hoje" / "só amanhã" (conforme $quando), ou "até sexta, 02/10": até o último dia seguido do mesmo relógio no plano
// de onde veio um número da conta (o "origem" das respostas, um código), no idioma: o "origem_texto" que as telas mostram
function origem_texto($origem)
{
    $textos = ["informado" => t("informado"), "calibre" => t("calibre"), "padrao" => t("padrão"), "vazio" => t("vazio"), "medido" => t("medido"), "cadastro" => t("cadastro")];
    return $textos[$origem] ?? $origem;
}

// o último dia seguido do plano com o mesmo relógio de $data (o próprio $data quando o dia seguinte é de outro)
function plano_ultimo_dia_seguido($data)
{
    $p = plano_do_dia($data);
    $fim = $data;
    $prox = date("Y-m-d", strtotime($data . " +1 day"));
    $q = $p ? plano_do_dia($prox) : null;
    // o limite de voltas só protege de um plano muito longo
    for ($n = 0; $q && (int)$q["relogio_id"] === (int)$p["relogio_id"] && $n < 60; $n++) {
        $fim = $prox;
        $prox = date("Y-m-d", strtotime($prox . " +1 day"));
        $q = plano_do_dia($prox);
    }
    return $fim;
}

function texto_ate($data, $quando)
{
    $dias = ["", t("segunda"), t("terça"), t("quarta"), t("quinta"), t("sexta"), t("sábado"), t("domingo")];
    $fim = plano_ultimo_dia_seguido($data);
    return $fim === $data ? t("só {1}", [1 => $quando]) : t("até {1}, {2}", [1 => $dias[(int)date("N", strtotime($fim))], 2 => data_txt(strtotime($fim), false, true)]);
}

// Âncoras dos modelos de mensagem: {nome} vira o valor na hora de enviar
$ANCORAS = [
    "relogio" => tk("Nome do relógio"),
    "codigo" => tk("Código do relógio"),
    "tipo" => tk("Tipo: o grupo do relógio (Smartwatch, Automático, Solar...)"),
    "estado" => tk("O estado agora: em uso, em repouso ou a sessão aberta (no sol, no winder, no carregador...)"),
    "carga" => tk("Carga em %: bateria, reserva, luz ou pilha"),
    "acao" => tk("O que fazer: Dar corda, Carregar..."),
    "motivo" => tk("Por quê, com os números: a reserva acaba em 3h..."),
    "aviso" => tk("Nome do tipo de aviso"),
    "ate" => tk("Até quando fica no pulso: só hoje, até sexta..."),
    "ultimo_uso" => tk("Última vez que foi usado"),
    "data" => tk("Data do aviso ou do evento"),
    "hora" => tk("Hora do aviso ou do evento"),
    "dia_semana" => tk("Dia da semana"),
    "relogio_do_dia" => tk("O relógio de hoje"),
    "relogio_de_amanha" => tk("O relógio de amanhã"),
    "quantidade" => tk("Quantos avisos a mensagem tem"),
    "modo" => tk("Modo de rodízio"),
    "link" => tk("Endereço do sistema (no aviso de um relógio, a ficha dele)"),
];

// Repetições dos eventos personalizados
$REPETICOES = ["uma" => tk("Uma vez"), "diaria" => tk("Todo dia"), "semanal" => tk("Em dias da semana"), "mensal" => tk("Todo mês"), "intervalo" => tk("A cada N dias")];
$DIAS_SEMANA = [1 => tk("Segunda"), 2 => tk("Terça"), 3 => tk("Quarta"), 4 => tk("Quinta"), 5 => tk("Sexta"), 6 => tk("Sábado"), 7 => tk("Domingo")];
$CURTO = [1 => tk("seg"), 2 => tk("ter"), 3 => tk("qua"), 4 => tk("qui"), 5 => tk("sex"), 6 => tk("sáb"), 7 => tk("dom")];
// as listas acima saem no idioma de cada pedido (o lingua_definir() as refaz; textos/funcoes.php)
textos_globais(["TIPOS_CAMPO", "FORMATOS_LANCAMENTO", "MIGRACOES", "FUNCOES", "ANCORAS", "REPETICOES", "DIAS_SEMANA", "CURTO"]);

// Tipos de aviso: o relógio do dia, a véspera, os avisos cadastrados (pelo identificador) e os eventos criados na
// Configuração ("ev<id>"). Cada um: [nome, quando sai]
function tipos_de_aviso()
{
    $lista = ["dia" => [t("Relógio do dia"), t("manhã")], "vespera" => [t("Véspera: preparar o relógio de amanhã"), "noite"]];
    // os avisos na ordem em que foram cadastrados
    foreach (linhas("SELECT identificador, MIN(id) AS primeiro FROM aviso GROUP BY identificador ORDER BY primeiro") as $a) {
        $lista[$a["identificador"]] = [avisos_todos()[$a["identificador"]][0]["nome"], t("manhã")];
    }
    foreach (eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome")) as $ev) {
        $lista["ev" . $ev["id"]] = [$ev["nome"], descricao_repeticao($ev)];
    }
    return $lista;
}

// O que um relógio mostra na tela e nas mensagens: a energia (e de onde ela vem), se está em uso e desde quando, a última
// vez no pulso, a situação (as sessões abertas e a autonomia restante), a próxima manutenção (o aviso mais perto, sem o
// Encostado: ele tem coluna própria) e o próprio Encostado
function visao_relogio($r, $agora)
{
    $id = (int)$r["id"];
    $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($id)];
    $energia = isset(formulas_todas()["energia"]) ? variavel_valor("energia", $ctx) : null;
    $f_en = isset(formulas_todas()["energia"]) ? mais_perto(formulas_todas()["energia"], $r) : null;
    // de onde vem a energia: o nome da versão da fórmula, depois dos dois pontos ("Energia agora: bateria do smartwatch")
    // (sem as explicações entre parênteses no fim, como o "(0 a 100)")
    $de = $f_en === null ? t("sem medida de carga") : (is_numeric($energia) ? trim((string)preg_replace(["/^[^:]*:/u", "/(\\s*\\([^()]*\\))+\$/u"], "", $f_en["nome"])) : t("sem dados para calcular"));
    if ((int)$r["disponivel"] !== 1) {
        $de = t("indisponível");
    }
    // as sessões abertas agora; a esquecida aberta conta só até o "fecha às" do tipo, no dia em que começou
    $abertas = [];
    $ult_pulso = 0;
    foreach (linhas("SELECT l.inicio, l.fim, l.origem, t.identificador, t.nome, t.fecha_as FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.relogio_id = ? AND t.formato = 'sessao' AND l.inicio <= ? ORDER BY l.inicio, l.id", [$id, date("Y-m-d H:i:s", $agora)]) as $l) {
        $fim = $l["fim"] !== null ? strtotime($l["fim"]) : ($l["fecha_as"] !== null ? max(strtotime($l["inicio"]), instante(dia_da_colecao(strtotime($l["inicio"])), $l["fecha_as"])) : PHP_INT_MAX);
        if ($fim > $agora) {
            $abertas[] = ["tipo" => $l["identificador"], "nome" => $l["nome"], "inicio" => strtotime($l["inicio"]), "origem" => $l["origem"]];
        }
        if ($l["identificador"] === "pulso") {
            $ult_pulso = max($ult_pulso, min($fim, $agora));
        }
    }
    $antigo = valor("SELECT MAX(l.inicio) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso_antigo'", [$id]);
    $ult_pulso = max($ult_pulso, $antigo ? (int)strtotime($antigo) : 0);
    $pulso = null;
    $sessoes = [];
    $situacao = [];
    foreach ($abertas as $a) {
        if ($a["tipo"] === "pulso") {
            $pulso = $a;
        } else {
            $sessoes[] = $a;
        }
    }
    // na situação, as sessões abertas que o estado não mostra (ele mostra o pulso, ou a sessão mais recente)
    foreach ($sessoes as $i => $a) {
        if ($pulso !== null || $i < count($sessoes) - 1) {
            $min = (int)floor(($agora - $a["inicio"]) / 60);
            $situacao[] = t("{1} desde {2} ({3} h {4} min)", [1 => $a["nome"], 2 => date("H:i", $a["inicio"]), 3 => intdiv($min, 60), 4 => $min % 60]);
        }
    }
    // o estado: em uso (no pulso), numa sessão (no sol, no winder, no carregador: o nome do tipo) ou em repouso. Em uso desde o
    // começo da sessão no pulso; numa sessão, desde o começo da mais recente; em repouso, desde o fim da última no pulso
    $estado = $pulso !== null ? "uso" : (count($sessoes) > 0 ? "sessao" : "repouso");
    $estado_texto = $estado === "uso" ? t("Em uso") : ($estado === "sessao" ? implode(" · ", array_column($sessoes, "nome")) : t("Em repouso"));
    $ultima = count($sessoes) > 0 ? $sessoes[count($sessoes) - 1] : null;
    $desde = $pulso !== null ? $pulso["inicio"] : ($ultima !== null ? $ultima["inicio"] : ($ult_pulso > 0 ? $ult_pulso : null));
    $texto = $pulso !== null ? t("Em uso") : ($ultima !== null ? $ultima["nome"] : t("Em repouso"));
    if ($desde !== null) {
        $texto .= t(" desde {1}", [1 => date("Y-m-d", $desde) === date("Y-m-d", $agora) ? date("H:i", $desde)
            : (date("Y-m-d", $desde) === date("Y-m-d", $agora - 86400) ? t("{1} de ontem", [1 => date("H:i", $desde)]) : data_txt($desde, true, true))]);
    }
    $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $ctx["valores"]];
    $autonomia = isset(formulas_todas()["autonomia_restante"]) ? variavel_valor("autonomia_restante", $ctx) : null;
    if (is_numeric($autonomia)) {
        $situacao[] = t("Autonomia restante {1}", [1 => duracao_texto((float)$autonomia / 86400) !== "" ? duracao_texto((float)$autonomia / 86400) : t("esgotada")]);
    }
    $avisos = avisos_do_relogio($r, $agora);
    // o Encostado não é manutenção: não entra na próxima manutenção nem no "o que fazer"; a tabela tem a coluna dele
    $encostado = null;
    $manut = [];
    foreach ($avisos as $a) {
        if ($a["identificador"] === "encostado") {
            $encostado = $a;
        } else {
            $manut[] = $a;
        }
    }
    $prox = count($manut) > 0 ? $manut[0] : null;
    $falta_de = function ($a) {
        return ($a["falta_dias"] < 0 ? t("atrasado ") : "") . (duracao_texto($a["falta_dias"]) !== "" ? duracao_texto($a["falta_dias"]) : t("agora"));
    };
    return ["energia" => is_numeric($energia) ? (int)round((float)$energia) : null, "de" => $de, "em_uso" => $pulso !== null, "pulso" => $pulso,
        "estado" => $estado, "estado_texto" => $estado_texto,
        "texto" => $texto, "ultimo" => $pulso !== null ? $agora : $ult_pulso, "situacao" => $situacao, "abertas" => $abertas, "avisos" => $manut,
        "prox" => $prox, "prox_momento" => $prox ? (int)strtotime($prox["data"]) : 0,
        "prox_falta" => $prox ? $falta_de($prox) : "—",
        "encostado" => $encostado, "encostado_momento" => $encostado ? (int)strtotime($encostado["data"]) : 0,
        "encostado_falta" => $encostado ? $falta_de($encostado) : "—"];
}

// Os avisos de hoje ("Hoje é dia de"): os atrasados ou em breve dos relógios disponíveis, do mais urgente ao mais distante.
// Cada um: [relógio, aviso]
function avisos_de_hoje()
{
    $lista = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY nome") as $r) {
        foreach (avisos_do_relogio($r, time()) as $a) {
            if ($a["estado"] !== "ok") {
                $lista[] = [$r, $a];
            }
        }
    }
    // o desempate (dois relógios vencendo no mesmo instante) é fixo, para a lista sair igual em qualquer banco
    usort($lista, function ($x, $y) { return [aviso_ordem($x[1]["falta_dias"]), (int)$x[0]["id"], $x[1]["identificador"]] <=> [aviso_ordem($y[1]["falta_dias"]), (int)$y[0]["id"], $y[1]["identificador"]]; });
    return $lista;
}

// Valores das âncoras. Com $id, os do relógio; sempre, os gerais. $extra sobrepõe.
function contexto_mensagem($id, $extra, $hoje)
{
    $dias = ["", t("segunda"), t("terça"), t("quarta"), t("quinta"), t("sexta"), t("sábado"), t("domingo")];
    $url = rtrim(sis("url_sistema"), "/");
    $dia = plano_do_dia($hoje->format("Y-m-d"));
    $amanha = plano_do_dia($hoje->modify("+1 day")->format("Y-m-d"));
    $ctx = [
        "relogio" => "", "codigo" => "", "tipo" => "", "estado" => "", "carga" => "", "acao" => "", "motivo" => "", "aviso" => "",
        "ate" => "", "ultimo_uso" => "", "quantidade" => "",
        "data" => $hoje->format("d/m/Y"), "hora" => date("H:i"), "dia_semana" => $dias[(int)$hoje->format("N")],
        "relogio_do_dia" => $dia ? $dia["nome"] : "nenhum", "relogio_de_amanha" => $amanha ? $amanha["nome"] : "nenhum",
        "modo" => (string)valor("SELECT nome FROM modo WHERE id = ?", [modo_ativo()]), "link" => $url !== "" ? $url . "/" : "",
    ];
    $r = $id > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$id]) : null;
    if ($r) {
        $v = visao_relogio($r, time());
        $n = nos_todos();
        $ctx["relogio"] = $r["nome"];
        $ctx["codigo"] = (string)$r["id"];
        $ctx["tipo"] = isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "";
        $ctx["estado"] = function_exists("mb_strtolower") ? mb_strtolower($v["estado_texto"], "UTF-8") : strtolower($v["estado_texto"]);
        $ctx["carga"] = $v["energia"] !== null ? $v["energia"] . "%" : $v["de"];
        $ctx["ultimo_uso"] = $v["ultimo"] > 0 ? data_txt($v["ultimo"], true, true) : t("nunca");
        if ($url !== "") {
            $ctx["link"] = $url . "/index.php?r=" . $id;
        }
    }
    if (isset($extra["tipo_aviso"])) {
        $ctx["aviso"] = tipos_de_aviso()[$extra["tipo_aviso"]][0] ?? $extra["tipo_aviso"];
        unset($extra["tipo_aviso"]);
    }
    return array_merge($ctx, $extra);
}

// Troca as âncoras {nome} pelos valores e limpa o que sobra de âncora vazia no fim da linha (" — ", "()")
function aplicar_modelo($modelo, $ctx)
{
    $trocas = [];
    foreach ($ctx as $k => $v) {
        $trocas["{" . $k . "}"] = (string)$v;
    }
    $linhas = [];
    foreach (explode("\n", strtr(str_replace("\r\n", "\n", (string)$modelo), $trocas)) as $l) {
        $linhas[] = preg_replace("/[\\s—·:,\\-]+\$/u", "", str_replace(" ()", "", $l));
    }
    return trim(implode("\n", $linhas));
}

// Os eventos personalizados com os dias da semana (a tabela evento_dia) em "dias_semana": "1,4" (null: nenhum dia)
function eventos_com_dias($eventos)
{
    $dias = [];
    foreach (linhas("SELECT evento_id, dia FROM evento_dia ORDER BY evento_id, dia") as $x) {
        $dias[(int)$x["evento_id"]][] = (int)$x["dia"];
    }
    return array_map(function ($ev) use ($dias) {
        $ev["dias_semana"] = isset($dias[(int)$ev["id"]]) ? implode(",", $dias[(int)$ev["id"]]) : null;
        return $ev;
    }, $eventos);
}

// Os avisos com o identificador do tipo de lançamento que resolve cada um em "resolve" (a chave estrangeira é resolve_tipo_id)
const AVISO_SELECT = "SELECT a.*, t.identificador AS resolve FROM aviso a LEFT JOIN lancamento_tipo t ON t.id = a.resolve_tipo_id";

// A chave do lugar de um parâmetro: "" (todos), "g:<id>" ou "r:<id>"

function escopo_chave($p)
{
    return $p["escopo_relogio_id"] !== null ? "r:" . (int)$p["escopo_relogio_id"] : ($p["escopo_no_id"] !== null ? "g:" . (int)$p["escopo_no_id"] : "");
}

// O conjunto que um lugar herda quando não tem o seu: o do ponto de cima mais perto que tenha, ou o de todos

function conjunto_herdado($chave)
{
    $cfg = criterios_config();
    $res = isset($cfg[""]) ? "" : null;
    $cadeia = [];
    if (strpos($chave, "g:") === 0) {
        $cadeia = no_cadeia((int)substr($chave, 2));
        array_pop($cadeia);
    } elseif (strpos($chave, "r:") === 0) {
        $cadeia = no_cadeia((int)valor("SELECT no_id FROM relogio WHERE id = ?", [(int)substr($chave, 2)]));
    }
    foreach ($cadeia as $g) {
        if (isset($cfg["g:" . $g])) {
            $res = "g:" . $g;
        }
    }
    return $chave === "" ? null : $res;
}

// As variáveis que um critério pode medir: os campos (menos os de texto livre) e as fórmulas. [identificador => ["nome",
// "tipo" => numero ou categoria, "valores" (categoria: as opções da lista), "max" => null (sem limite)]]
function variaveis_disponiveis()
{
    // o limite da medida (a última faixa vai até ele, ou sem limite), como no sistema antigo: o que o nome do campo ou da
    // fórmula diz em "(0 a N)" ("Sua nota para o relógio (0 a 100)"); sem isso, a medida não tem limite
    $maximo = function ($nome) {
        $res = null;
        if (preg_match("/\\(0 a ([0-9]+)\\)/u", (string)$nome, $m) === 1) {
            $res = (float)$m[1];
        }
        return $res;
    };
    $res = [];
    foreach (campos_todos() as $ident => $c) {
        if ($c["tipo"] !== "texto") {
            $res[$ident] = ["nome" => $c["nome"] . ($c["unidade"] !== "" ? " (" . $c["unidade"] . ")" : ""), "tipo" => $c["tipo"] === "lista" ? "categoria" : "numero",
                "valores" => $c["tipo"] === "lista" ? array_values(array_filter(array_map("trim", explode("\n", (string)$c["opcoes"])))) : [],
                "max" => $c["tipo"] === "lista" ? null : $maximo($c["nome"])];
        }
    }
    foreach (formulas_todas() as $ident => $versoes) {
        $res[$ident] = ["nome" => $versoes[0]["nome"] . ($versoes[0]["unidade"] !== "" ? " (" . $versoes[0]["unidade"] . ")" : ""), "tipo" => "numero", "valores" => [],
            "max" => $maximo($versoes[0]["nome"])];
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// A escala inteligente, a previsão da energia, a linha do tempo, os eventos personalizados e o Google Agenda
// ---------------------------------------------------------------------------------------------------------------------

// O que fazer antes de um dia de uso para o relógio estar pronto: os avisos dele calculados no instante em que o uso
// daquele dia começa, os atrasados ou em breve naquele momento ("Carregar: chega a 20% em 3h"). Vazio: é só usar. Usado
// pela mensagem da noite e pela véspera na agenda.
function preparo_do_dia($r, $data)
{
    $res = [];
    foreach (avisos_do_relogio($r, instante($data, cfg("uso_inicio"))) as $x) {
        if ($x["estado"] !== "ok") {
            $res[] = $x["nome"] . ": " . $x["texto"];
        }
    }
    return $res;
}

// Corta um texto em até $max caracteres sem partir acento (não depende da extensão mbstring, que pode não estar instalada)
function corta_texto($texto, $max)
{
    $res = (string)$texto;
    if (preg_match("/^.{0," . (int)$max . "}/us", $res, $m) === 1) {
        $res = $m[0];
    }
    return $res;
}

// Um lançamento que não aconteceu, para o motor calcular o futuro (a escala e a previsão): o relógio, o identificador do
// tipo, o começo e o fim (sessão), e o valor (tipo com valor), em segundos. Fica em SIMULACAO até quem simulou limpar.
// Cada relógio e tipo recebe os simulados em ordem, todos depois de agora.
function simular_lancamento($rid, $tipo, $ini, $fim, $valor, $so_uso = false)
{
    $t = lancamento_tipos()[$tipo] ?? null;
    if ($t) {
        $GLOBALS["SIMULACAO"][(int)$rid][$tipo][] = ["ini" => (int)$ini, "fim" => $t["formato"] === "sessao" ? (int)$fim : null,
            "valor" => $t["formato"] === "valor" ? (float)$valor : null, "formato" => $t["formato"], "so_uso" => $so_uso];
        $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
    }
}

// A escala inteligente (o modo ativo com escala_dias): monta o plano de $ini até o fim da escala (escala_fim; vencido ou
// vazio, um horizonte novo de escala_dias) pelo roteiro do modo, simulando dia a dia a partir do estado real (plano_montar,
// com a simulação inteira da carga e os avisos: o que vence vira o "o que fazer" do dia). Os dias sorteados de $ini em
// diante saem antes; os escolhidos à mão e os livres ficam. O dia de hoje que já começou no pulso não muda. Devolve
// quantos dias gravou.
function gerar_escala($ini)
{
    $gravados = 0;
    $modo = linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]);
    $hoje = hoje_da_colecao();
    if ($ini < $hoje) {
        $ini = $hoje;
    }
    if ($ini == $hoje && time() >= instante($hoje->format("Y-m-d"), cfg("uso_inicio")) && plano_do_dia($hoje->format("Y-m-d")) !== null) {
        $ini = $hoje->modify("+1 day");
    }
    if ($modo && $modo["escala_dias"] !== null) {
        $fim = cfg("escala_fim") !== "" ? new DateTimeImmutable(cfg("escala_fim")) : null;
        if ($fim === null || $fim < $ini) {
            $fim = $ini->modify("+" . (max(1, (int)$modo["escala_dias"]) - 1) . " days");
            cfg_set("escala_fim", $fim->format("Y-m-d"));
        }
        sql("DELETE FROM plano WHERE data >= ? AND origem <> 'manual'", [$ini->format("Y-m-d")]);
        $gravados = plano_montar($modo, $hoje, $ini, $fim, true);
        cfg_set("escala_gerada", hoje_da_colecao()->format("Y-m-d"));
    }
    return $gravados;
}

// A previsão da energia de um relógio com leitura (um tipo de lançamento com valor, como a leitura de carga do
// smartwatch), pela fórmula energia (em %): quanto dura se usar a partir de hoje (dias_de_carga), quando chega ao limite da
// Configuração (previsao_limite, 20%) parado, com quanto entra no próximo rodízio e quanto precisa para os dias seguidos
// dele (simulado com carga cheia na entrada), e a confiança da conta (a idade da leitura e os campos da conta).
// Devolve as frases (as da página) e os mesmos números. "aplica": o relógio tem leitura com valor (senão, nada).
function previsao_energia($r, $agora)
{
    $hoje = new DateTimeImmutable(date("Y-m-d", $agora));
    $res = ["aplica" => false, "linhas" => [], "energia" => null, "dura_dias" => null, "dura_ate" => null, "limite" => (float)(cfg("previsao_limite") !== "" ? cfg("previsao_limite") : 20),
        "chega_limite_em" => null, "proxima_entrada" => null, "carga_na_entrada" => null, "precisa" => null, "carregar_antes" => null,
        "leitura" => null, "leitura_em" => null, "confianca" => null, "conta" => [], "gasto" => null];
    $tipos_valor = [];
    foreach (lancamento_tipos_do_relogio($r) as $ident => $t) {
        if ($t["formato"] === "valor" && ($t["marcha"] ?? null) === null) {
            $tipos_valor[] = $ident;
        }
    }
    $energia_f = isset(formulas_todas()["energia"]) ? mais_perto(formulas_todas()["energia"], $r) : null;
    if (count($tipos_valor) > 0 && $energia_f !== null) {
        $res["aplica"] = true;
        $leitura = linha("SELECT l.inicio, l.valor, t.identificador, t.unidade FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE l.relogio_id = ? AND t.formato = 'valor' AND t.marcha IS NULL AND l.inicio <= ? ORDER BY l.inicio DESC, l.id DESC LIMIT 1", [(int)$r["id"], date("Y-m-d H:i:s", $agora)]);
        $valores = valores_do_relogio((int)$r["id"]);
        $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
        $energia = variavel_valor("energia", $ctx);
        if ($leitura && is_numeric($energia)) {
            $energia = (float)$energia;
            $res["energia"] = round($energia, 1);
            $res["leitura"] = (float)$leitura["valor"];
            $res["leitura_em"] = $leitura["inicio"];
            // os campos que a conta da energia usou, informados no relógio, no padrão do cadastro ou vazios
            $campos = campos_do_relogio($r);
            $vazio = false;
            foreach ($ctx["rastro"] as $nome => $v) {
                if (isset($campos[$nome])) {
                    $origem = origem_valor((int)$r["id"], $nome, $v);
                    $vazio = $vazio || $origem === "vazio";
                    $res["conta"][] = ["campo" => $nome, "nome" => $campos[$nome]["nome"], "detalhe" => null, "valor" => $v, "unidade" => $campos[$nome]["unidade"],
                        "origem" => $origem, "origem_texto" => origem_texto($origem)];
                }
            }
            // o gasto medido pelas leituras (gasto_medido, na janela da Configuração): com o de uso medido, o campo vazio do
            // cadastro não pesa na confiança (a medição vale no lugar dele)
            $janela = max(1, (int)cfg("medicao_janela_dias"));
            [$motor_g, $motor_de] = gasto_motor_de($r);
            $medicoes = medicoes_da_conta((int)$r["id"])[0];
            $prior_g = gasto_prior($r, $agora);
            $motor_c = $motor_g === "formulas" ? "marcacao" : $motor_g;
            $g = gasto_medido($medicoes, $agora, $prior_g, $motor_c);
            // as medições da conta (as da janela; nenhuma nela: todas as de antes, como no gasto_medido), quantas de cada tipo,
            // e o que só as leituras dão (a mesma conta sem o cadastro como ponto de partida: com poucas medições, o cadastro
            // puxa o medido para perto dele)
            $janela_g = medicoes_janela($medicoes, $agora, 0);
            $ms_g = array_values(array_filter(count($janela_g) > 0 ? $janela_g : $medicoes, function ($m) use ($agora) { return strtotime($m["fim"]) <= $agora; }));
            $n_med = ["uso" => 0, "repouso" => 0];
            foreach ($ms_g as $m) {
                $n_med[$m["medida"] === "uso" ? "uso" : "repouso"]++;
            }
            $so = $motor_c === "cadastro" ? null : ($motor_c === "horas" ? $g : gasto_medido_desde($ms_g, $agora, null, null));
            $como = $g["n"] === 0 ? t("sem medição na janela ({1})", [1 => medicoes_janela_texto()])
                : ($motor_c === "marcacao" ? t("pela marcação, {1}: {2} em uso e {3} em repouso", [1 => medicoes_janela_texto(), 2 => $n_med["uso"], 3 => $n_med["repouso"]])
                : t("{1}{2}{3} {4}", [1 => $g["conjunta"] ? t("conta dos dois gastos juntos, com ") : t("conta de "), 2 => $g["n"], 3 => $g["n"] === 1 ? t(" medição") : t(" medições"), 4 => medicoes_janela_texto()]))
                . (count($g["fora"]) > 0 ? t("; {1} com uma leitura que não bate com as outras, pesando pouco", [1 => count($g["fora"])]) : "");
            // no motor "só o cadastro", o medido não entra na conta: não aparece em "de onde vem a conta"
            foreach (["uso" => t("Gasto em uso medido"), "repouso" => t("Gasto fora do pulso medido")] as $medida => $nome_m) {
                if ($g[$medida] !== null && $motor_g !== "cadastro") {
                    $res["conta"][] = ["campo" => "MEDIDO(\"" . $medida . "\")", "nome" => $nome_m, "detalhe" => $como, "valor" => round($g[$medida], 3),
                        "unidade" => $medida === "uso" ? t("% por dia de uso") : t("% por dia fora do pulso"), "origem" => "medido", "origem_texto" => origem_texto("medido")];
                    if ($medida === "uso") {
                        $vazio = false;
                    }
                }
            }
            // os dois gastos lado a lado (as fórmulas taxa_uso e taxa_repouso): o do cadastro (a conta sem as medições), o medido
            // nesta janela e na anterior (para ver o relógio envelhecer) e o que vale na conta
            $conta_j = function ($ms) use ($agora, $prior_g, $motor_c) {
                return $motor_c === "cadastro" ? gasto_medido([], $agora, $prior_g, "cadastro") : ($motor_c === "horas" ? gasto_medido_horas($ms, $agora) : gasto_medido_desde($ms, $agora, null, $prior_g));
            };
            $agora_j = $conta_j(medicoes_janela($medicoes, $agora, 0));
            $antes_j = $conta_j(medicoes_janela($medicoes, $agora, 1));
            $gasto = ["janela_dias" => $janela, "motor" => $motor_g, "motor_nome" => gasto_motor_nome($motor_g), "motor_de" => $motor_de,
                "base" => cfg("medicao_base") === "leituras" ? "leituras" : "dias",
                "janela_leituras" => (int)cfg("medicao_janela_leituras") >= 2 ? (int)cfg("medicao_janela_leituras") : 20, "janela_texto" => medicoes_janela_texto(), "medicoes" => $agora_j["n"], "fora" => count($agora_j["fora"]), "conjunta" => $agora_j["conjunta"], "medicoes_antes" => $antes_j["n"]];
            $tem = false;
            foreach (["uso" => "taxa_uso", "repouso" => "taxa_repouso"] as $medida => $ident) {
                $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
                $vale = variavel_valor($ident, $ctx);
                $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores, "sem_medido" => true];
                $cadastro = variavel_valor($ident, $ctx);
                $tem = $tem || is_numeric($vale);
                // os campos do cadastro de onde sai o gasto do cadastro (o decaimento, a autonomia...): o painel mostra o medido
                // ao lado deles
                $de_campos = [];
                foreach (array_keys($ctx["rastro"]) as $nome) {
                    if (isset($campos[$nome])) {
                        $de_campos[] = ["campo" => $nome, "nome" => $campos[$nome]["nome"], "valor" => is_numeric($ctx["rastro"][$nome]) ? (float)$ctx["rastro"][$nome] : null,
                            "unidade" => $campos[$nome]["unidade"], "origem" => $o_c = origem_valor((int)$r["id"], $nome, $ctx["rastro"][$nome]), "origem_texto" => origem_texto($o_c)];
                    }
                }
                $so_v = $so !== null && $so[$medida] !== null ? round((float)$so[$medida], 3) : null;
                $gasto[$medida] = ["cadastro" => is_numeric($cadastro) ? round((float)$cadastro, 3) : null, "medido" => $g[$medida], "medido_dp" => $g[$medida . "_dp"],
                    "antes" => $antes_j[$medida], "vale" => is_numeric($vale) ? round((float)$vale, 3) : null, "formula" => $ident, "campos" => $de_campos,
                    "medicoes" => $n_med[$medida], "so_leituras" => $so_v, "puxado" => $so_v !== null && $g[$medida] !== null && abs($so_v - (float)$g[$medida]) >= 0.01];
            }
            $res["gasto"] = $tem ? $gasto : null;
            // na conta, primeiro o gasto que vale no pulso e fora dele (o medido ou o do cadastro); os campos do cadastro de
            // um gasto em que vale o medido ficam na lista, marcados como fora da conta (usado: false)
            $vale_medido = [];
            $fora_da_conta = [];
            $na_conta = [];
            foreach (["uso", "repouso"] as $medida) {
                $x = $gasto[$medida];
                $vale_medido[$medida] = $motor_g !== "cadastro" && $x["medido"] !== null && $x["vale"] !== null && abs($x["vale"] - round($x["medido"], 3)) < 0.002;
                foreach ($x["campos"] as $c) {
                    if ($vale_medido[$medida]) {
                        $fora_da_conta[$c["campo"]] = true;
                    } else {
                        $na_conta[$c["campo"]] = true;
                    }
                }
            }
            $primeiras = [];
            foreach (["uso" => [t("No pulso, o gasto que vale"), t("% por dia de uso"), "taxa_uso"], "repouso" => [t("Fora do pulso, o gasto que vale"), t("% por dia fora do pulso"), "taxa_repouso"]] as $medida => $d) {
                if ($gasto[$medida]["vale"] !== null) {
                    $x = $gasto[$medida];
                    $detalhe = !$vale_medido[$medida] ? t("o do cadastro (sem medição que valha)")
                        : ($x["puxado"] ? t("o medido pelas leituras; só elas dariam {1}% ({2}{3}: o cadastro ainda puxa a conta)",
                            [1 => decimal_txt($x["so_leituras"], 2), 2 => $x["medicoes"], 3 => $x["medicoes"] === 1 ? t(" medição") : t(" medições")]) : t("o medido pelas leituras"));
                    $primeiras[] = ["campo" => $d[2], "nome" => $d[0], "detalhe" => $detalhe,
                        "valor" => $gasto[$medida]["vale"], "unidade" => $d[1], "origem" => $vale_medido[$medida] ? "medido" : "cadastro", "origem_texto" => origem_texto($vale_medido[$medida] ? "medido" : "cadastro"), "usado" => true, "vale" => true];
                }
            }
            foreach ($res["conta"] as $i => $c) {
                $fora = isset($fora_da_conta[$c["campo"]]) && !isset($na_conta[$c["campo"]]);
                $res["conta"][$i]["usado"] = !$fora;
                $res["conta"][$i]["vale"] = false;
                if ($fora) {
                    $res["conta"][$i]["detalhe"] = t("fora da conta: vale o gasto medido");
                }
            }
            $res["conta"] = array_merge($primeiras, $res["conta"]);
            $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
            $dura = variavel_valor("dias_de_carga", $ctx);
            if (is_numeric($dura)) {
                $res["dura_dias"] = round((float)$dura, 1);
                $res["dura_ate"] = $hoje->modify("+" . max(0, (int)floor((float)$dura)) . " days")->format("Y-m-d");
                $res["linhas"][] = t("Se usar a partir de hoje: dura ~{1}{2}, até {3}.", [1 => decimal_txt($res["dura_dias"], 6), 2 => $res["dura_dias"] == 1 ? " dia" : " dias", 3 => data_txt(strtotime($res["dura_ate"]), false, true)]);
            }
            // parado: o primeiro dia em que a energia fica no limite ou abaixo (a energia guardada só cai; procura por metades);
            // numa sessão agora (no pulso, no carregador, no sol...), a conta segue com a sessão aberta, e a frase diz isso
            $sessao_agora = valor("SELECT t.nome FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.formato = 'sessao'
                AND l.inicio <= ? AND (l.fim IS NULL OR l.fim > ?) ORDER BY l.inicio DESC, l.id DESC LIMIT 1", [(int)$r["id"], date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora)]);
            $continuando = $sessao_agora === null ? null : (function_exists("mb_strtolower") ? mb_strtolower((string)$sessao_agora, "UTF-8") : strtolower((string)$sessao_agora));
            if ($energia <= $res["limite"]) {
                $res["linhas"][] = t("Já está abaixo de {1}%. Guardado assim, a bateria se desgasta.", [1 => decimal_txt($res["limite"], 6)]);
            } else {
                $baixo = 0;
                $alto = 366;
                $ctx = ["r" => $r, "momento" => $agora + $alto * 86400, "rastro" => [], "pilha" => [], "valores" => $valores];
                $e_alto = variavel_valor("energia", $ctx);
                if (is_numeric($e_alto) && (float)$e_alto <= $res["limite"]) {
                    while ($alto - $baixo > 1) {
                        $meio = intdiv($baixo + $alto, 2);
                        $ctx = ["r" => $r, "momento" => $agora + $meio * 86400, "rastro" => [], "pilha" => [], "valores" => $valores];
                        $e = variavel_valor("energia", $ctx);
                        if (is_numeric($e) && (float)$e <= $res["limite"]) {
                            $alto = $meio;
                        } else {
                            $baixo = $meio;
                        }
                    }
                    $chega = $hoje->modify("+" . $alto . " days");
                    $res["chega_limite_em"] = $chega->format("Y-m-d");
                    $quando = [1 => decimal_txt($res["limite"], 6), 2 => data_txt($chega->getTimestamp(), false, $chega->format("Y") === $hoje->format("Y"))];
                    $res["linhas"][] = $continuando !== null ? t("Continuando {3}, chega a {1}% em {2}.", $quando + [3 => $continuando]) : t("Parado, chega a {1}% em {2}.", $quando);
                } else {
                    $res["linhas"][] = $continuando !== null ? t("Continuando {2}, fica acima de {1}% por mais de um ano.", [1 => decimal_txt($res["limite"], 6), 2 => $continuando])
                        : t("Parado, fica acima de {1}% por mais de um ano.", [1 => decimal_txt($res["limite"], 6)]);
                }
            }
            // a próxima entrada no rodízio (o primeiro dia dele no plano a partir de hoje, se não for hoje) e os dias seguidos dele
            $proxima = valor("SELECT data FROM plano WHERE relogio_id = ? AND data >= ? ORDER BY data LIMIT 1", [(int)$r["id"], $hoje->format("Y-m-d")]);
            if ($proxima !== null && $proxima !== $hoje->format("Y-m-d")) {
                $entrada = instante($proxima, cfg("uso_inicio"));
                $ctx = ["r" => $r, "momento" => $entrada, "rastro" => [], "pilha" => [], "valores" => $valores];
                $na_entrada = variavel_valor("energia", $ctx);
                // quanto os dias seguidos gastam: simulados com a carga cheia na entrada; esgotou antes do fim: 100%
                $ultimo_dia = $proxima;
                $prox = date("Y-m-d", strtotime($proxima . " +1 day"));
                for ($n = 0; (int)valor("SELECT relogio_id FROM plano WHERE data = ?", [$prox]) === (int)$r["id"] && $n < 366; $n++) {
                    $ultimo_dia = $prox;
                    $prox = date("Y-m-d", strtotime($prox . " +1 day"));
                }
                $GLOBALS["SIMULACAO"] = [];
                simular_lancamento((int)$r["id"], $leitura["identificador"], $entrada, null, 100);
                for ($k = $proxima; $k <= $ultimo_dia; $k = date("Y-m-d", strtotime($k . " +1 day"))) {
                    simular_lancamento((int)$r["id"], "pulso", instante($k, cfg("uso_inicio")), instante($k, cfg("uso_fim")), null);
                }
                $ctx = ["r" => $r, "momento" => instante($ultimo_dia, cfg("uso_fim")), "rastro" => [], "pilha" => [], "valores" => $valores];
                $no_fim = variavel_valor("energia", $ctx);
                $GLOBALS["SIMULACAO"] = [];
                $GLOBALS["SIMULACAO_VERSAO"]++;
                if (is_numeric($na_entrada) && is_numeric($no_fim)) {
                    $res["proxima_entrada"] = $proxima;
                    $res["carga_na_entrada"] = (int)round((float)$na_entrada);
                    $res["precisa"] = (float)$no_fim <= 0 ? 100 : (int)ceil(100 - (float)$no_fim);
                    $res["carregar_antes"] = (float)$na_entrada < $res["precisa"];
                    $res["linhas"][] = t("Entra no rodízio em {1} com ~{2}%; precisa de {3}%{4}", [1 => data_txt(strtotime($proxima), false, true), 2 => $res["carga_na_entrada"], 3 => $res["precisa"], 4 => $res["carregar_antes"] ? t(". Carregar antes.") : t(". Dá conta.")]);
                }
            }
            // a confiança: a leitura envelhece; alta só com leitura de até 2 dias e nenhum campo da conta vazio
            $idade = ($agora - strtotime($leitura["inicio"])) / 86400;
            $res["confianca"] = $idade <= 2 && !$vazio ? "alta" : ($idade <= 7 ? "media" : "baixa");
            // hoje, ontem, ou há quantos dias de calendário
            $dias_leitura = (int)(new DateTimeImmutable(substr($leitura["inicio"], 0, 10)))->diff($hoje)->days;
            $quando = $dias_leitura === 0 ? t("hoje") : ($dias_leitura === 1 ? t("ontem") : t("há {1} dias", [1 => $dias_leitura]));
            // (de onde vem cada número da conta vai à parte, em "conta": a tela mostra numa tabela)
            $res["linhas"][] = t("Confiança {1}: leitura de {2}{3} feita {4}.", [1 => ["alta" => t("alta"), "media" => t("média"), "baixa" => t("baixa")][$res["confianca"]], 2 => decimal_txt($res["leitura"], 6), 3 => $leitura["unidade"], 4 => $quando]);
        }
    }
    return $res;
}

// Quando a energia de um relógio (a fórmula energia, em %) chega a zero, entre $agora e $ate: com os lançamentos gravados e
// mais as sessões simuladas ($sessoes: [[tipo, início, fim], ...], em ordem, todas depois de agora). Entre um começo e um fim
// de sessão a energia só sobe ou só desce, então a busca é por trechos: acha o primeiro trecho em que ela chega a zero e
// procura o instante por metades, até 1 minuto. Devolve o instante (em segundos) ou null: sem energia calculável, ou ela não
// chega a zero até $ate. Usado pelas autonomias (parado, no pulso direto, seguindo o plano).
function quando_acaba($r, $agora, $sessoes, $ate)
{
    $id = (int)$r["id"];
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
    // os limites dos trechos: agora, o começo e o fim de cada sessão simulada e de cada gravada que ainda não acabou, e $ate
    $marcos = [$agora, $ate];
    foreach ($sessoes as $x) {
        simular_lancamento($id, $x[0], $x[1], $x[2], null);
        $marcos[] = (int)$x[1];
        $marcos[] = (int)$x[2];
    }
    foreach (linhas("SELECT l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.formato = 'sessao' AND l.fim > ?",
        [$id, date("Y-m-d H:i:s", $agora)]) as $l) {
        $marcos[] = (int)strtotime($l["inicio"]);
        $marcos[] = (int)strtotime($l["fim"]);
    }
    $marcos = array_values(array_unique(array_filter($marcos, function ($x) use ($agora, $ate) { return $x >= $agora && $x <= $ate; })));
    sort($marcos);
    $valores = valores_do_relogio($id);
    $energia_em = function ($t) use ($r, $valores) {
        $c = ["r" => $r, "momento" => (int)$t, "rastro" => [], "pilha" => [], "valores" => $valores];
        $e = variavel_valor("energia", $c);
        return is_numeric($e) ? (float)$e : null;
    };
    $res = null;
    $e0 = isset(formulas_todas()["energia"]) ? $energia_em($agora) : null;
    if ($e0 !== null && $e0 <= 0.0001) {
        $res = $agora;
    } elseif ($e0 !== null) {
        for ($i = 0; $res === null && $i < count($marcos) - 1; $i++) {
            $a = $marcos[$i];
            $b = $marcos[$i + 1];
            $eb = $energia_em($b);
            if ($eb !== null && $eb <= 0.0001) {
                while ($b - $a > 60) {
                    $meio = intdiv($a + $b, 2);
                    $em = $energia_em($meio);
                    if ($em !== null && $em <= 0.0001) {
                        $b = $meio;
                    } else {
                        $a = $meio;
                    }
                }
                $res = $b;
            }
        }
    }
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"]++;
    return $res;
}

// As autonomias de um relógio, em segundos (inteiros), para a API: a prevista (carga cheia, pelo cadastro: a fórmula
// autonomia_prevista), a atual (carga cheia, pela conta do sistema, com o gasto medido quando há: autonomia_atual), quanto
// ainda dura a partir de agora no pulso direto, guardado, e seguindo o plano (a estimada), e quando acaba pela estimada. Os
// que não dá para calcular saem null, com o motivo. Horizonte: 10 anos (a pilha dura anos). Usado pela API (a resposta
// completa, a ficha e o recurso=autonomia).
function autonomia_do_relogio($r, $agora)
{
    $id = (int)$r["id"];
    $ate = $agora + 3650 * 86400;
    $c = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($id)];
    $formula = function ($ident) use ($r, $agora, $c) {
        $x = $c;
        $v = isset(formulas_todas()[$ident]) && mais_perto(formulas_todas()[$ident], $r) !== null ? variavel_valor($ident, $x) : null;
        return is_numeric($v) ? (int)round((float)$v) : null;
    };
    $energia = $formula("energia");
    $em_uso = valor("SELECT l.id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso'
        AND l.inicio <= ? AND (l.fim IS NULL OR l.fim > ?) LIMIT 1", [$id, date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora)]) !== null;
    // as sessões no pulso já lançadas que ainda não acabaram (a do rodízio de hoje vai até o fim do horário de uso): o uso
    // simulado começa depois delas, para não contar a mesma hora duas vezes
    $fim_pulso = max($agora, (int)strtotime((string)valor("SELECT MAX(l.fim) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.relogio_id = ? AND t.identificador = 'pulso' AND l.fim > ?", [$id, date("Y-m-d H:i:s", $agora)])));
    $plano = [];
    foreach (linhas("SELECT data FROM plano WHERE relogio_id = ? AND data >= ? ORDER BY data", [$id, date("Y-m-d", $agora)]) as $p) {
        $ini = max($fim_pulso, instante($p["data"], cfg("uso_inicio")));
        $fim = instante($p["data"], cfg("uso_fim"));
        if ($fim > $ini) {
            $plano[] = ["pulso", $ini, $fim];
        }
    }
    $acaba_guardado = $energia === null ? null : quando_acaba($r, $agora, [], $ate);
    $acaba_em_uso = $energia === null ? null : quando_acaba($r, $agora, $fim_pulso < $ate ? [["pulso", $fim_pulso, $ate]] : [], $ate);
    $acaba = $energia === null ? null : quando_acaba($r, $agora, $plano, $ate);
    $res = ["calculado_em_unixtimestamp" => $agora, "em_uso" => $em_uso, "energia" => $energia,
        "autonomia_prevista" => $formula("autonomia_prevista"), "autonomia_atual" => $formula("autonomia_atual"),
        "autonomia_estimada" => $acaba === null ? null : $acaba - $agora,
        "restante_em_uso" => $acaba_em_uso === null ? null : $acaba_em_uso - $agora, "restante_guardado" => $acaba_guardado === null ? null : $acaba_guardado - $agora,
        "acaba_em_unixtimestamp" => $acaba, "acaba_em_segundos" => $acaba === null ? null : $acaba - $agora, "acaba_em_datacomtz" => $acaba === null ? null : date("c", $acaba),
        "motivos" => []];
    // por que cada um que ficou vazio ficou vazio
    foreach (["autonomia_prevista", "autonomia_atual"] as $k) {
        if ($res[$k] === null) {
            $res["motivos"][$k] = isset(formulas_todas()[$k]) && mais_perto(formulas_todas()[$k], $r) !== null
                ? t("falta no cadastro do relógio um dado que a fórmula {1} usa", [1 => $k]) : t("não há fórmula {1} para o grupo deste relógio", [1 => $k]);
        }
    }
    foreach (["restante_em_uso" => t("no pulso direto"), "restante_guardado" => t("guardado"), "autonomia_estimada" => t("seguindo o plano")] as $k => $como) {
        if ($res[$k] === null) {
            $res["motivos"][$k] = $energia === null ? t("sem dados para calcular a energia agora (ex.: smartwatch sem nenhuma leitura, solar sem nenhum sol registrado)")
                : t("a energia não chega a zero em até 10 anos {1}{2}", [1 => $como, 2 => $k === "restante_em_uso" ? t(" (no pulso, o relógio pode se recarregar)") : ""]);
        }
    }
    foreach (["acaba_em_unixtimestamp", "acaba_em_segundos", "acaba_em_datacomtz"] as $k) {
        if ($res[$k] === null) {
            $res["motivos"][$k] = $res["motivos"]["autonomia_estimada"];
        }
    }
    // os motivos sempre como objeto na resposta (vazio: {}), para quem lê saber que são chaves
    $res["motivos"] = (object)$res["motivos"];
    return $res;
}

// Os estados da linha do tempo: em uso pelo rodízio, em uso fora do plano (no pulso sem ser o relógio do dia), cada tipo de sessão (winder, sol...), em
// repouso (o resto) e as marcações instantâneas. [estado => texto]
function estados_da_linha()
{
    $res = ["rodizio" => t("em uso"), "pulso" => t("em uso (fora do plano)")];
    foreach (lancamento_tipos() as $ident => $t) {
        if ($t["formato"] === "sessao" && $ident !== "pulso") {
            // minúsculas sem depender do mbstring ("No winder" → "no winder")
            $res[$ident] = function_exists("mb_strtolower") ? mb_strtolower((string)$t["nome"], "UTF-8") : strtolower((string)$t["nome"]);
        }
    }
    return array_merge($res, ["repouso" => t("em repouso"), "marca" => t("marcação")]);
}

// A linha do tempo de um relógio, do primeiro lançamento até agora: trechos contínuos, cada um com o seu estado (em uso
// pelo rodízio, em uso fora do plano, em cada tipo de sessão, ou em repouso), e no meio as marcações instantâneas
// (corda, leitura de carga, troca de pilha, marcações antigas...). Onde duas sessões se sobrepõem, vale a de tipo que vem
// antes na ordem dos tipos de lançamento (o pulso antes do winder, antes do sol); sessão esquecida aberta vale até o
// "fecha às" do tipo. Filtrada: $de e $ate em segundos (0 e PHP_INT_MAX: sem limite), entra a linha que cruza o período;
// $estados: os estados pedidos (vazio: todos). Devolve as linhas (mais recente primeiro: inicio, fim, estado, texto,
// tipo, em_andamento), o tempo em cada estado dentro do período (o trecho que atravessa uma borda conta só a parte de
// dentro; o resumo ignora o filtro de estado) e a contagem das marcações por tipo. A página do histórico e a API usam esta.
function linha_do_tempo($id, $de, $ate, $estados)
{
    $agora = time();
    $textos = estados_da_linha();
    $res = ["linhas" => [], "tempo" => [], "marcas" => []];
    foreach ($textos as $k => $t) {
        if ($k !== "marca") {
            $res["tempo"][$k] = 0;
        }
    }
    $sessoes = [];
    $marcas = [];
    $t0 = 0;
    foreach (linhas("SELECT l.*, t.identificador, t.nome, t.formato, t.fecha_as, t.unidade, t.ordem FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.relogio_id = ? AND l.inicio <= ? ORDER BY l.inicio, l.id", [(int)$id, date("Y-m-d H:i:s", $agora)]) as $l) {
        $ini = strtotime($l["inicio"]);
        $t0 = $t0 === 0 ? $ini : min($t0, $ini);
        if ($l["formato"] === "sessao") {
            $fim = $l["fim"] !== null ? strtotime($l["fim"]) : ($l["fecha_as"] !== null ? max($ini, instante(dia_da_colecao(strtotime($l["inicio"])), $l["fecha_as"])) : $agora);
            $fim = min($fim, $agora);
            if ($fim > $ini) {
                $estado = $l["identificador"] === "pulso" ? ($l["origem"] === "rodizio" ? "rodizio" : "pulso") : $l["identificador"];
                // a prioridade: a ordem do tipo; no pulso, o rodízio antes do fora do plano
                $sessoes[] = [$ini, $fim, $estado, (int)$l["ordem"] * 2 + ($estado === "pulso" ? 1 : 0)];
            }
        } else {
            $marcas[] = ["inicio" => $ini, "fim" => null, "estado" => "marca", "tipo" => $l["identificador"], "em_andamento" => false,
                "texto" => $l["nome"] . ($l["valor"] !== null ? " " . decimal_txt((float)$l["valor"], 6) . $l["unidade"] : ""), "nome" => $l["nome"]];
        }
    }
    $trechos = [];
    if ($t0 > 0) {
        $marcos = [$t0, $agora];
        foreach ($sessoes as $x) {
            foreach ([$x[0], $x[1]] as $t) {
                if ($t > $t0 && $t < $agora) {
                    $marcos[] = $t;
                }
            }
        }
        $marcos = array_values(array_unique($marcos));
        sort($marcos);
        usort($sessoes, function ($x, $y) { return $x[0] <=> $y[0]; });
        // percorrido uma vez só: as sessões entram nas ativas quando começam e saem quando acabam
        $proxima = 0;
        $ativas = [];
        for ($i = 0; $i < count($marcos) - 1; $i++) {
            $a = $marcos[$i];
            $b = $marcos[$i + 1];
            $meio = $a + ($b - $a) / 2;
            while ($proxima < count($sessoes) && $sessoes[$proxima][0] <= $meio) {
                $ativas[] = $sessoes[$proxima];
                $proxima++;
            }
            $estado = "repouso";
            $melhor = PHP_INT_MAX;
            $ficam = [];
            foreach ($ativas as $x) {
                if ($meio < $x[1]) {
                    $ficam[] = $x;
                    if ($x[3] < $melhor) {
                        $melhor = $x[3];
                        $estado = $x[2];
                    }
                }
            }
            $ativas = $ficam;
            $n = count($trechos);
            if ($n > 0 && $trechos[$n - 1]["estado"] === $estado) {
                $trechos[$n - 1]["fim"] = $b;
            } else {
                $trechos[] = ["inicio" => $a, "fim" => $b, "estado" => $estado];
            }
        }
    }
    $linhas = [];
    foreach ($trechos as $i => $x) {
        $linhas[] = ["inicio" => $x["inicio"], "fim" => $x["fim"], "estado" => $x["estado"], "tipo" => $x["estado"] === "rodizio" ? "pulso" : ($x["estado"] === "repouso" ? null : $x["estado"]),
            "texto" => $textos[$x["estado"]] ?? $x["estado"], "em_andamento" => $i === count($trechos) - 1];
    }
    foreach ($marcas as $m) {
        $linhas[] = $m;
    }
    usort($linhas, function ($x, $y) { return $y["inicio"] <=> $x["inicio"]; });
    foreach ($linhas as $l) {
        $fim_l = $l["fim"] === null ? $l["inicio"] : $l["fim"];
        if ($fim_l >= $de && $l["inicio"] < $ate) {
            if ($l["estado"] === "marca") {
                $res["marcas"][$l["nome"]] = ($res["marcas"][$l["nome"]] ?? 0) + 1;
            } else {
                $res["tempo"][$l["estado"]] = ($res["tempo"][$l["estado"]] ?? 0) + max(0, min($fim_l, $ate) - max($l["inicio"], $de));
            }
            if (count($estados) === 0 || in_array($l["estado"], $estados, true)) {
                unset($l["nome"]);
                $res["linhas"][] = $l;
            }
        }
    }
    return $res;
}

// "todo dia 20:00", "seg, qua 20:00", "dia 1 do mês, 09:00", "a cada 90 dias, 09:00, desde 01/09/2026", "25/09/2026 14:00"
function descricao_repeticao($ev)
{
    $dias = ["", t("seg"), t("ter"), t("qua"), t("qui"), t("sex"), t("sáb"), t("dom")];
    $hora = substr((string)$ev["hora"], 0, 5);
    $txt = $hora;
    if ($ev["repeticao"] === "uma") {
        $txt = data_txt(strtotime((string)$ev["data_inicio"])) . " " . $hora;
    } elseif ($ev["repeticao"] === "diaria") {
        $txt = t("todo dia {1}", [1 => $hora]);
    } elseif ($ev["repeticao"] === "semanal") {
        $nomes = [];
        foreach (explode(",", (string)$ev["dias_semana"]) as $n) {
            if ((int)$n >= 1 && (int)$n <= 7) {
                $nomes[] = $dias[(int)$n];
            }
        }
        $txt = implode(", ", $nomes) . " " . $hora;
    } elseif ($ev["repeticao"] === "mensal") {
        $txt = t("dia {1} do mês, {2}", [1 => (int)$ev["dia_mes"], 2 => $hora]);
    } elseif ($ev["repeticao"] === "intervalo") {
        $txt = t("a cada {1} dias, {2}{3}", [1 => (int)$ev["intervalo_dias"], 2 => $hora, 3 => $ev["data_inicio"] ? t(", desde {1}", [1 => data_txt(strtotime($ev["data_inicio"]))]) : ""]);
    }
    if ((int)$ev["ativo"] !== 1) {
        $txt .= " · pausado";
    }
    return $txt;
}

// As ocorrências de um evento personalizado entre dois instantes (segundos), em ordem
function ocorrencias($ev, $de, $ate)
{
    $lista = [];
    $hora = substr((string)$ev["hora"], 0, 5);
    $inicio = $ev["data_inicio"] ? $ev["data_inicio"] : "0000-00-00";
    $fim = date("Y-m-d", (int)$ate);
    for ($d = new DateTimeImmutable(date("Y-m-d", (int)$de)); $d->format("Y-m-d") <= $fim; $d = $d->modify("+1 day")) {
        $dia = $d->format("Y-m-d");
        $vale = false;
        if ($dia >= $inicio) {
            if ($ev["repeticao"] === "uma") {
                $vale = $dia === $ev["data_inicio"];
            } elseif ($ev["repeticao"] === "diaria") {
                $vale = true;
            } elseif ($ev["repeticao"] === "semanal") {
                $vale = in_array($d->format("N"), explode(",", (string)$ev["dias_semana"]), true);
            } elseif ($ev["repeticao"] === "mensal") {
                // mês mais curto que o dia pedido: vale o último dia do mês
                $vale = (int)$d->format("j") === min((int)$ev["dia_mes"], (int)$d->format("t"));
            } elseif ($ev["repeticao"] === "intervalo" && $ev["data_inicio"]) {
                $vale = (int)(new DateTimeImmutable($ev["data_inicio"], fuso_colecao_tz()))->diff($d)->days % max(1, (int)$ev["intervalo_dias"]) === 0;
            }
        }
        if ($vale) {
            $ts = strtotime($dia . " " . $hora);
            if ($ts >= $de && $ts <= $ate) {
                $lista[] = $ts;
            }
        }
    }
    return $lista;
}

// O manual: os trechos do README.md que a página Ajuda mostra (as seções para quem usa: sem a instalação, a API e o código),
// na ordem dele, cada um da seção do primeiro título até antes da do segundo. A página Ajuda (ajuda.php) converte para HTML;
// a API (recurso=manual, ou recurso=ajuda&parte=manual) devolve o mesmo texto, em markdown e seção por seção
const MANUAL_TRECHOS = [["A ideia central", "Experimente em 1 minuto"], ["As telas", "A API"], ["Perguntas frequentes", "Como é por dentro"]];

// A âncora de um título, como a do GitHub (os links do README para as próprias seções funcionam na Ajuda também):
// minúsculas, sem pontuação, espaços viram hífens; as letras acentuadas ficam
function manual_ancora($titulo)
{
    $t = function_exists("mb_strtolower") ? mb_strtolower($titulo, "UTF-8") : strtolower($titulo);
    $t = preg_replace("/[^\\p{L}\\p{N} _-]/u", "", $t);
    return str_replace(" ", "-", trim((string)$t));
}

// Um título do manual: [o texto, a âncora]. A tradução do manual escreve a âncora do original no fim do título,
// "## The central idea {#a-ideia-central}": assim os links de uma seção para outra (#a-ideia-central) valem em todo idioma
function manual_titulo($t)
{
    $t = trim($t);
    if (preg_match('/^(.*?)\s*\{#([^}]+)\}$/u', $t, $m) === 1) {
        return [$m[1], $m[2]];
    }
    return [$t, manual_ancora($t)];
}

// O manual traduzido de um idioma (textos/manual/<idioma>.md): [a origem, o markdown], ou null. A primeira linha diz de
// que versão do original ele é a tradução (<!-- origem: o sha1 do manual em português -->)
function manual_traduzido($lingua)
{
    $arq = __DIR__ . "/textos/manual/" . $lingua . ".md";
    if ($lingua === "pt-BR" || !is_file($arq)) {
        return null;
    }
    $s = str_replace("\r\n", "\n", (string)file_get_contents($arq));
    $origem = preg_match('/^<!-- origem: ([0-9a-f]+) -->\n/', $s, $m) === 1 ? $m[1] : "";
    return [$origem, $origem !== "" ? substr($s, strlen($m[0])) : $s];
}

// O markdown do manual no idioma do pedido: a tradução em textos/manual/<idioma>.md; sem ela, o original em português (as
// seções do README.md; vazio: o README.md não está na pasta do sistema). Com TEXTOS_ESTRITO, a tradução que falta ou ficou
// para trás (o README mudou depois dela) para com erro, como no t()
function manual_markdown($lingua = null)
{
    $lingua = $lingua ?? lingua_atual();
    $md = manual_original();
    if ($lingua !== "pt-BR") {
        $tr = manual_traduzido($lingua);
        if ($tr !== null && ($tr[0] === sha1($md) || !textos_estrito())) {
            return $tr[1];
        }
        if (textos_estrito()) {
            throw new Error("Falta a tradução em " . $lingua . " (textos/manual/" . $lingua . ".md): " . ($tr === null ? "o manual" : "o manual mudou"));
        }
    }
    return $md;
}

// O manual em português: as seções do README.md para quem usa (MANUAL_TRECHOS)
function manual_original()
{
    $readme = str_replace("\r\n", "\n", (string)@file_get_contents(__DIR__ . "/README.md"));
    $md = "";
    foreach (MANUAL_TRECHOS as $t) {
        $ini = strpos($readme, "\n## " . $t[0]);
        $fim = $ini === false ? false : strpos($readme, "\n## " . $t[1], $ini + 1);
        if ($ini !== false) {
            $md .= substr($readme, $ini, $fim === false ? null : $fim - $ini) . "\n";
        }
    }
    return $md;
}

// O manual seção por seção: cada título (## a ####) com o nível, a âncora e o texto até o próximo título, em markdown (os
// títulos dentro de um bloco de código não contam)
function manual_secoes($md)
{
    $secoes = [];
    $atual = null;
    $no_codigo = false;
    foreach (explode("\n", $md) as $l) {
        if (strpos($l, "```") === 0) {
            $no_codigo = !$no_codigo;
        }
        if (!$no_codigo && preg_match("/^(#{2,4})\\s+(.*)$/", $l, $m) === 1) {
            if ($atual !== null) {
                $secoes[] = $atual;
            }
            [$tit, $anc] = manual_titulo($m[2]);
            $atual = ["nivel" => strlen($m[1]), "titulo" => $tit, "ancora" => $anc, "texto" => ""];
        } elseif ($atual !== null) {
            $atual["texto"] .= $l . "\n";
        }
    }
    if ($atual !== null) {
        $secoes[] = $atual;
    }
    foreach ($secoes as $i => $x) {
        $secoes[$i]["texto"] = trim(preg_replace("/\\n-{3,}\\s*$/", "", rtrim($x["texto"])));
    }
    return $secoes;
}

// O manual em HTML (a página Ajuda e o recurso=manual): o que vai dentro de uma linha: escapa o HTML e troca `código`, **negrito**, *itálico*, ![imagem](...) e [link](...)
function manual_linha($s)
{
    $codigos = [];
    $s = preg_replace_callback("/`([^`]+)`/", function ($m) use (&$codigos) {
        $codigos[] = "<code>" . htmlspecialchars($m[1], ENT_QUOTES, "UTF-8") . "</code>";
        return "\x01" . (count($codigos) - 1) . "\x02";
    }, $s);
    $s = htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
    $s = preg_replace("/!\\[([^\\]]*)\\]\\(([^)\\s]+)\\)/", "<img src=\"$2\" alt=\"$1\" loading=\"lazy\">", $s);
    $s = preg_replace_callback("/\\[([^\\]]+)\\]\\(([^)\\s]+)\\)/", function ($m) {
        // link para outra seção: âncora na página; para um arquivo do código, só o texto (o navegador não abre)
        if ($m[2][0] === "#" || preg_match("#^https?://#", $m[2]) === 1) {
            return "<a href=\"" . $m[2] . "\"" . ($m[2][0] === "#" ? "" : " rel=\"noopener\" target=\"_blank\"") . ">" . $m[1] . "</a>";
        }
        return $m[1];
    }, $s);
    $s = preg_replace("/\\*\\*(.+?)\\*\\*/", "<strong>$1</strong>", $s);
    $s = preg_replace("/(?<![\\w*])\\*(?!\\s)(.+?)(?<!\\s)\\*(?![\\w*])/u", "<em>$1</em>", $s);
    return preg_replace_callback("/\x01(\\d+)\x02/", function ($m) use ($codigos) { return $codigos[(int)$m[1]]; }, $s);
}

// Um trecho de markdown em HTML (o manual): títulos (com âncora), parágrafos, listas, tabelas, blocos de código, linhas e o <sub> das
// legendas. O diagrama (mermaid) fica de fora: sem a biblioteca ele seria só texto. Devolve [html, sumário]
function manual_html($md)
{
    $linhas = explode("\n", str_replace("\r\n", "\n", $md));
    $html = "";
    $sumario = [];
    $par = [];
    $fecha_par = function () use (&$par, &$html) {
        if (count($par) > 0) {
            $html .= "<p>" . manual_linha(implode(" ", $par)) . "</p>\n";
            $par = [];
        }
    };
    $n = count($linhas);
    for ($i = 0; $i < $n; $i++) {
        $l = $linhas[$i];
        if (preg_match("/^```(\\w*)/", $l, $m) === 1) {
            $fecha_par();
            $bloco = [];
            for ($i++; $i < $n && strpos($linhas[$i], "```") !== 0; $i++) {
                $bloco[] = $linhas[$i];
            }
            if ($m[1] !== "mermaid") {
                $html .= "<pre>" . htmlspecialchars(implode("\n", $bloco), ENT_QUOTES, "UTF-8") . "</pre>\n";
            }
        } elseif (preg_match("/^(#{2,4})\\s+(.*)$/", $l, $m) === 1) {
            $fecha_par();
            $nivel = strlen($m[1]);
            [$m[2], $ancora] = manual_titulo($m[2]);
            $html .= "<h" . $nivel . " id=\"" . htmlspecialchars($ancora, ENT_QUOTES, "UTF-8") . "\">" . manual_linha($m[2]) . "</h" . $nivel . ">\n";
            if ($nivel <= 3) {
                $sumario[] = [$nivel, $m[2], $ancora];
            }
        } elseif (trim($l) === "---") {
            $fecha_par();
        } elseif (strpos(ltrim($l), "|") === 0) {
            $fecha_par();
            $tabela = [];
            for (; $i < $n && strpos(ltrim($linhas[$i]), "|") === 0; $i++) {
                $tabela[] = $linhas[$i];
            }
            $i--;
            $celulas = function ($linha) {
                return array_map("trim", explode("|", trim(trim($linha), "|")));
            };
            $html .= "<div class=\"ajuda-tabela\"><table>\n<thead><tr>";
            foreach ($celulas($tabela[0]) as $c) {
                $html .= "<th>" . manual_linha($c) . "</th>";
            }
            $html .= "</tr></thead>\n<tbody>\n";
            foreach (array_slice($tabela, 2) as $t) {
                $html .= "<tr>";
                foreach ($celulas($t) as $c) {
                    $html .= "<td>" . manual_linha($c) . "</td>";
                }
                $html .= "</tr>\n";
            }
            $html .= "</tbody></table></div>\n";
        } elseif (preg_match("/^(-|\\d+\\.)\\s+/", $l, $m) === 1) {
            $fecha_par();
            $tag = $m[1] === "-" ? "ul" : "ol";
            $html .= "<" . $tag . ">\n";
            for (; $i < $n && preg_match("/^(-|\\d+\\.)\\s+(.*)$/", $linhas[$i], $mi) === 1; $i++) {
                $item = [$mi[2]];
                // as linhas seguintes com recuo continuam o mesmo item
                while ($i + 1 < $n && preg_match("/^\\s{2,}\\S/", $linhas[$i + 1]) === 1) {
                    $item[] = trim($linhas[++$i]);
                }
                $html .= "<li>" . manual_linha(implode(" ", $item)) . "</li>\n";
            }
            $i--;
            $html .= "</" . $tag . ">\n";
        } elseif (strpos(ltrim($l), "<sub>") === 0) {
            // a legenda de uma imagem: até o </sub>
            $fecha_par();
            $leg = [];
            for (; $i < $n; $i++) {
                $leg[] = $linhas[$i];
                if (strpos($linhas[$i], "</sub>") !== false) {
                    break;
                }
            }
            $html .= "<p class=\"nota\">" . manual_linha(str_replace(["<sub>", "</sub>"], "", implode(" ", $leg))) . "</p>\n";
        } elseif (trim($l) === "") {
            $fecha_par();
        } else {
            $par[] = trim($l);
        }
    }
    $fecha_par();
    return [$html, $sumario];
}

// ---------------------------------------------------------------------------------------------------------------------
// A cópia de segurança do banco: um arquivo por cópia na BACKUP_PASTA do config.php (uma pasta do servidor fora da
// pasta publicada), relojoeiro-AAAA-MM-DD-HHMMSS.jsonl.gz: JSON Lines comprimido, o mesmo nos quatro bancos (uma cópia do
// MySQL volta num PostgreSQL ou num SQLite da mesma versão). A primeira linha é o cabeçalho ({"relojoeiro_copia": 1,
// "criada", "banco", "versao" (a última migração), "documentos" (se tem o conteúdo dos documentos), "tabelas"}); depois,
// para cada tabela, uma linha {"tabela", "colunas", "binarias"} e uma linha por registro, a lista dos valores na ordem das
// colunas (as colunas binárias em base64). As tabelas vêm na ordem em que voltam (a tabela citada antes da que cita).
// O cron faz uma por dia, na rodada da noite, e guarda as BACKUP_MANTER mais novas (padrão 7). O conteúdo dos documentos
// (documento_parte, a cópia deles no banco, que pode ser grande) só entra com BACKUP_DOCUMENTOS = true: os arquivos já
// estão na pasta dos documentos.
// ---------------------------------------------------------------------------------------------------------------------
// A cópia de segurança pode ser desligada no config.php (BACKUP_ATIVO = false): sem cópia automática, sem a ação fazer e
// sem o quadro da Configuração (nem o aviso de que falta a pasta). Sem a linha: ligada
function backup_ativo()
{
    return !defined("BACKUP_ATIVO") || BACKUP_ATIVO !== false;
}

function backup_pasta_erro()
{
    if (!backup_ativo()) {
        return t("a cópia de segurança está desligada no config.php (BACKUP_ATIVO = false)");
    }
    if (!defined("BACKUP_PASTA") || trim((string)BACKUP_PASTA) === "") {
        return t("sem BACKUP_PASTA no config.php: a cópia de segurança automática está desligada");
    }
    $pasta = rtrim((string)BACKUP_PASTA, "/\\");
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0750, true);
    }
    if (!is_dir($pasta)) {
        return t("a pasta das cópias ({1}) não existe e não consegui criá-la", [1 => $pasta]);
    }
    if (!is_writable($pasta)) {
        return t("a pasta das cópias ({1}) não aceita gravar: dê permissão de escrita ao usuário do PHP", [1 => $pasta]);
    }
    $real = realpath($pasta);
    $sistema = realpath(__DIR__);
    if ($real !== false && $sistema !== false && ($real === $sistema || strpos($real . DIRECTORY_SEPARATOR, $sistema . DIRECTORY_SEPARATOR) === 0)) {
        return t("a pasta das cópias está dentro da pasta publicada do sistema: qualquer um baixaria o banco pelo navegador. Use uma pasta fora dela");
    }
    return "";
}

function backup_pasta()
{
    return backup_pasta_erro() === "" ? rtrim((string)BACKUP_PASTA, "/\\") : null;
}

function backup_manter()
{
    return defined("BACKUP_MANTER") && (int)BACKUP_MANTER > 0 ? (int)BACKUP_MANTER : 7;
}

function backup_com_documentos()
{
    return defined("BACKUP_DOCUMENTOS") && BACKUP_DOCUMENTOS === true;
}

// As tabelas na ordem em que voltam: a citada por uma chave estrangeira antes da que cita (a referência à própria tabela,
// como a árvore, fica de fora: os registros dela voltam em rodadas). Cada uma: [tabela, colunas, binárias, chave primária,
// colunas que citam a própria tabela]
function backup_tabelas()
{
    $cat = [];
    foreach (banco_catalogo() as $t) {
        $cat[$t["tabela"]] = $t;
    }
    $ordem = [];
    $visitando = [];
    $visitar = function ($nome) use (&$visitar, &$ordem, &$visitando, $cat) {
        if (isset($ordem[$nome]) || isset($visitando[$nome]) || !isset($cat[$nome])) {
            return;
        }
        $visitando[$nome] = true;
        foreach ($cat[$nome]["chaves_estrangeiras"] as $f) {
            $outra = explode(".", $f["referencia"])[0];
            if ($outra !== $nome) {
                $visitar($outra);
            }
        }
        $ordem[$nome] = true;
    };
    foreach (array_keys($cat) as $nome) {
        $visitar($nome);
    }
    $res = [];
    foreach (array_keys($ordem) as $nome) {
        $t = $cat[$nome];
        $colunas = array_column($t["colunas"], "nome");
        $binarias = array_values(array_map(function ($c) { return $c["nome"]; }, array_filter($t["colunas"], function ($c) {
            // (no Firebird, o texto longo é um BLOB SUB_TYPE TEXT: texto, não binário)
            return preg_match("/blob|bytea/i", (string)$c["tipo"]) === 1 && stripos((string)$c["tipo"], "SUB_TYPE TEXT") === false;
        })));
        $proprias = [];
        foreach ($t["chaves_estrangeiras"] as $f) {
            if (explode(".", $f["referencia"])[0] === $nome) {
                $proprias[$f["coluna"]] = explode(".", $f["referencia"])[1];
            }
        }
        $res[] = [$nome, $colunas, $binarias, count($t["chave_primaria"]) > 0 ? array_values($t["chave_primaria"]) : [$colunas[0]], $proprias];
    }
    return $res;
}

// As cópias que estão na pasta, da mais nova para a mais velha: [{arquivo, bytes, tamanho, criada, documentos, versao}]
function backup_lista()
{
    $pasta = backup_pasta();
    if ($pasta === null) {
        return [];
    }
    $res = [];
    foreach (glob($pasta . "/relojoeiro-*.jsonl.gz") ?: [] as $c) {
        $gz = @gzopen($c, "rb");
        $cab = $gz ? json_decode((string)gzgets($gz, 1 << 20), true) : null;
        if ($gz) {
            gzclose($gz);
        }
        $bytes = (int)filesize($c);
        $res[] = ["arquivo" => basename($c), "bytes" => $bytes, "tamanho" => $bytes < 1048576 ? t("{1} KB", [1 => max(1, (int)round($bytes / 1024))]) : t("{1} MB", [1 => decimal_txt($bytes / 1048576, 1)]),
            "criada" => is_array($cab) ? ($cab["criada"] ?? null) : null,
            "documentos" => is_array($cab) && !empty($cab["documentos"]), "versao" => is_array($cab) ? ($cab["versao"] ?? null) : null];
    }
    usort($res, function ($a, $b) { return strcmp($b["arquivo"], $a["arquivo"]); });
    return $res;
}

// O caminho de uma cópia pelo nome (só os nomes que o sistema dá, sem pasta), ou null
function backup_caminho($arquivo)
{
    $pasta = backup_pasta();
    $arquivo = (string)$arquivo;
    return $pasta !== null && preg_match("/^relojoeiro-\\d{4}-\\d{2}-\\d{2}-\\d{6}\\.jsonl\\.gz\$/", $arquivo) === 1 && is_file($pasta . "/" . $arquivo) ? $pasta . "/" . $arquivo : null;
}

// Faz uma cópia agora e apaga as mais velhas que passam do BACKUP_MANTER. Devolve {ok, mensagem, erros, arquivo}
function backup_fazer()
{
    global $MIGRACOES;
    $pasta = backup_pasta();
    if ($pasta === null) {
        return ["ok" => false, "mensagem" => "", "erros" => [t("Não fiz a cópia: {1}.", [1 => backup_pasta_erro()])], "arquivo" => null];
    }
    $nome = "relojoeiro-" . date("Y-m-d-His") . ".jsonl.gz";
    // segundos iguais (duas no mesmo segundo): a próxima espera o nome ficar livre
    while (is_file($pasta . "/" . $nome)) {
        sleep(1);
        $nome = "relojoeiro-" . date("Y-m-d-His") . ".jsonl.gz";
    }
    $parcial = $pasta . "/." . $nome . ".parcial";
    $docs = backup_com_documentos();
    try {
        $linhas = banco_despejar($parcial, $docs);
    } catch (Throwable $e) {
        @unlink($parcial);
        return ["ok" => false, "mensagem" => "", "erros" => [t("Não consegui criar o arquivo da cópia na pasta das cópias.")], "arquivo" => null];
    }
    rename($parcial, $pasta . "/" . $nome);
    sis_set("backup_ultima", date("Y-m-d H:i:s"));
    // as mais velhas que passam do limite saem
    $apagadas = 0;
    foreach (array_slice(backup_lista(), backup_manter()) as $velha) {
        if (@unlink($pasta . "/" . $velha["arquivo"])) {
            $apagadas++;
        }
    }
    return ["ok" => true, "mensagem" => t("Cópia de segurança feita: {1} ({2}{3} registros{4}).{5}", [1 => $nome, 2 => filesize($pasta . "/" . $nome) < 1048576 ? max(1, (int)round(filesize($pasta . "/" . $nome) / 1024)) . " KB, "
        : decimal_txt(filesize($pasta . "/" . $nome) / 1048576, 1) . " MB, ", 3 => $linhas, 4 => $docs ? t(", com o conteúdo dos documentos") : "", 5 => $apagadas > 0 ? " " . $apagadas . ($apagadas === 1 ? t(" cópia velha apagada.") : t(" cópias velhas apagadas.")) : ""]),
        "erros" => [], "arquivo" => $nome];
}

// Grava o banco inteiro num arquivo (o formato da cópia de segurança: JSON Lines com gzip; a primeira linha diz o que é, e
// cada tabela vem com as colunas e depois um registro por linha, o binário em base64). As tabelas na ordem em que voltam
// (a citada antes da que cita); $docs: com o conteúdo dos documentos (documento_parte). Sem escopo: o banco inteiro.
// Devolve quantos registros foram
function banco_despejar($arquivo, $docs)
{
    global $MIGRACOES;
    return sem_escopo(function () use ($arquivo, $docs, $MIGRACOES) {
        $gz = gzopen($arquivo, "wb6");
        if (!$gz) {
            throw new RuntimeException(t("não consegui criar {1}", [1 => $arquivo]));
        }
        $tabelas = array_values(array_filter(backup_tabelas(), function ($t) use ($docs) { return $docs || $t[0] !== "documento_parte"; }));
        // a versão em que o banco está: a última migração, ou a de antes da primeira que falta
        $versoes = array_keys($MIGRACOES);
        $falta = array_key_first(migracoes_pendentes());
        $versao = $falta === null ? end($versoes) : ($versoes[array_search($falta, $versoes, true) - 1] ?? "v1");
        gzwrite($gz, json_encode(["relojoeiro_copia" => 1, "criada" => date("Y-m-d H:i:s"), "banco" => banco_tipo(), "versao" => $versao,
            "documentos" => $docs, "tabelas" => array_column($tabelas, 0)], JSON_UNESCAPED_UNICODE) . "\n");
        $linhas = 0;
        foreach ($tabelas as [$nome_t, $colunas, $binarias, $chave]) {
            gzwrite($gz, json_encode(["tabela" => $nome_t, "colunas" => $colunas, "binarias" => $binarias], JSON_UNESCAPED_UNICODE) . "\n");
            // de pouco em pouco (o conteúdo dos documentos vem em pedaços de 4 MB)
            $lote = in_array($nome_t, ["documento_parte", "foto"], true) ? 2 : 500;
            for ($de = 0; ; $de += $lote) {
                $regs = linhas("SELECT * FROM " . $nome_t . " ORDER BY " . implode(", ", $chave) . " LIMIT " . $lote . " OFFSET " . $de);
                foreach ($regs as $reg) {
                    $vals = [];
                    foreach ($colunas as $c) {
                        $v = $reg[$c] ?? null;
                        $vals[] = $v !== null && in_array($c, $binarias, true) ? base64_encode((string)$v) : $v;
                    }
                    gzwrite($gz, json_encode($vals, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $linhas++;
                }
                if (count($regs) < $lote) {
                    break;
                }
            }
        }
        gzclose($gz);
        return $linhas;
    });
}

// Volta o banco para uma cópia: antes, faz uma cópia do que está agora; depois, num passo só (se algo falha, nada muda),
// apaga todos os registros e grava os da cópia. Só uma cópia da mesma versão do sistema (a mesma última migração). Sem o
// conteúdo dos documentos na cópia, os documentos voltam sem a cópia no banco, e o cron refaz a que for pedida.
function backup_restaurar($arquivo)
{
    // o banco inteiro, de todas as coleções: sem escopo
    return sem_escopo(function () use ($arquivo) {
        return backup_restaurar_tudo($arquivo);
    });
}

function backup_restaurar_tudo($arquivo)
{
    global $MIGRACOES;
    $caminho = backup_caminho($arquivo);
    if ($caminho === null) {
        return ["ok" => false, "mensagem" => "", "erros" => [backup_pasta() === null ? t("Sem a pasta das cópias: {1}.", [1 => backup_pasta_erro()]) : t("Cópia não encontrada: {1}.", [1 => $arquivo])]];
    }
    $gz = gzopen($caminho, "rb");
    $cab = json_decode((string)gzgets($gz, 1 << 20), true);
    if (!is_array($cab) || ($cab["relojoeiro_copia"] ?? null) !== 1) {
        gzclose($gz);
        return ["ok" => false, "mensagem" => "", "erros" => [t("O arquivo {1} não é uma cópia do Relojoeiro.", [1 => $arquivo])]];
    }
    if (($cab["versao"] ?? "") !== array_key_last($MIGRACOES)) {
        gzclose($gz);
        return ["ok" => false, "mensagem" => "", "erros" => [t("A cópia é da versão {1} do banco e este está na {2}: volte a cópia num sistema da mesma versão.", [1 => $cab["versao"] ?? "?", 2 => array_key_last($MIGRACOES)])]];
    }
    $antes = backup_fazer();
    if (!$antes["ok"]) {
        gzclose($gz);
        return ["ok" => false, "mensagem" => "", "erros" => array_merge([t("Não restaurei: a cópia do que está agora falhou.")], $antes["erros"])];
    }
    $todas = backup_tabelas();
    $info = [];
    foreach ($todas as $t) {
        $info[$t[0]] = $t;
    }
    $n = 0;
    banco_inicio();
    try {
        foreach (array_reverse(array_column($todas, 0)) as $t) {
            sql("DELETE FROM " . $t);
        }
        $tabela = null;
        $colunas = [];
        $binarias = [];
        $esperando = [];
        $grava = function ($vals) use (&$tabela, &$colunas, &$binarias, &$n) {
            $params = [];
            foreach ($colunas as $i => $c) {
                $v = $vals[$i] ?? null;
                if ($v !== null && in_array($c, $binarias, true)) {
                    $v = base64_decode((string)$v);
                    // no PostgreSQL o binário vai em hexadecimal, também o que parece texto
                    $v = banco_tipo() === "pgsql" ? "\\x" . bin2hex($v) : $v;
                }
                $params[] = $v;
            }
            sql("INSERT INTO " . $tabela . " (" . implode(", ", $colunas) . ") VALUES (" . implode(", ", array_fill(0, count($colunas), "?")) . ")", $params);
            $n++;
        };
        // os registros que citam a própria tabela (a árvore) esperam o citado entrar
        $soltar = function () use (&$esperando, &$tabela, &$colunas, &$info, $grava) {
            $proprias = $tabela !== null ? $info[$tabela][4] : [];
            $feitos = [];
            while (count($esperando) > 0) {
                $resto = [];
                foreach ($esperando as $vals) {
                    $pronto = true;
                    foreach ($proprias as $col => $ref) {
                        $v = $vals[array_search($col, $colunas, true)];
                        if ($v !== null && !isset($feitos[(string)$v])) {
                            $pronto = false;
                        }
                    }
                    if ($pronto) {
                        $grava($vals);
                        $feitos[(string)$vals[array_search(reset($proprias), $colunas, true)]] = true;
                    } else {
                        $resto[] = $vals;
                    }
                }
                if (count($resto) === count($esperando)) {
                    throw new RuntimeException(t("a tabela {1} cita registros que não estão na cópia", [1 => $tabela]));
                }
                $esperando = $resto;
            }
        };
        while (($l = gzgets($gz, 64 << 20)) !== false) {
            $x = json_decode($l, true);
            if (is_array($x) && isset($x["tabela"])) {
                $soltar();
                if (!isset($info[$x["tabela"]])) {
                    throw new RuntimeException(t("a tabela {1} da cópia não existe neste banco", [1 => $x["tabela"]]));
                }
                $tabela = $x["tabela"];
                $colunas = $x["colunas"];
                $binarias = $x["binarias"];
            } elseif (is_array($x) && $tabela !== null) {
                if (count($info[$tabela][4]) > 0) {
                    $esperando[] = $x;
                } else {
                    $grava($x);
                }
            }
        }
        $soltar();
        if (empty($cab["documentos"]) && isset($info["documento"])) {
            sql("UPDATE documento SET no_banco = 0");
        }
        banco_confirma();
    } catch (Throwable $e) {
        banco_desfaz();
        gzclose($gz);
        return ["ok" => false, "mensagem" => "", "erros" => [t("Não restaurei (o banco ficou como estava): {1}.", [1 => $e->getMessage()])]];
    }
    gzclose($gz);
    banco_ajustar_ids();
    sis_set("backup_ultima", (string)preg_replace("/^relojoeiro-(\\d{4}-\\d{2}-\\d{2})-(\\d{2})(\\d{2})(\\d{2}).*\$/", "\$1 \$2:\$3:\$4", $antes["arquivo"]));
    return ["ok" => true, "mensagem" => t("Banco restaurado da cópia {1} ({2} registros). O que estava antes foi guardado em {3}.", [1 => $arquivo, 2 => $n, 3 => $antes["arquivo"]]), "erros" => []];
}

// as coleções (o escopo do pedido, o modelo.json, os segredos, a configuração do sistema, a migração v25)
require_once __DIR__ . "/colecoes.php";
// os alertas (os destinos das mensagens, as agendas, o e-mail, o Web Push)
require_once __DIR__ . "/alertas.php";
// as contas (o cadastro, a conta, as coleções, os convites, os pedidos e o painel do master)
require_once __DIR__ . "/contas.php";
// o back-end de escrita (as operações que as páginas e a API chamam)
require_once __DIR__ . "/operacoes.php";
