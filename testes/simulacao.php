<?php
// A simulação dos modos de rodízio (rodada pelo testes/simulacao.sh, com o relógio falso): cada modo vivido dia a dia, do
// jeito que o sistema roda de verdade, e conferido contra as regras do README (Os modos de rodízio, O roteiro do modo, O
// rodízio manual, A escala inteligente).
//   php testes/simulacao.php <endereço do api.php> <pasta da rodada> [filtro...]
// Cada modo começa num banco igual (o modelo: a instalação, a árvore e os relógios, guardado uma vez) e numa segunda-feira.
// Cada dia: às 07:10 o cron (a rodada da manhã: o plano completado, a escala replanejada, o relógio do dia no pulso), às
// 12:00 a leitura da página Hoje (o relógio do dia, quem está no pulso, o plano à frente) e o que o modo faz nesse dia (o
// rodízio manual, uma troca recusada...), às 22:30 o cron de novo (a noite). No fim de cada modo, as regras:
//   - todo dia tem relógio (menos os livres), disponível, e ele está no pulso ao meio-dia (a sessão do rodízio);
//   - o relógio de cada dia é o que o plano dizia na véspera (fora da escala, que se replaneja toda manhã);
//   - o plano chega ao fim da última das semanas do modo (com uma semana só, no último dia, também ao primeiro da seguinte);
//     na escala, até o fim dela;
//   - cada dia sai do bloco do dia da semana (o grupo, com os subgrupos, ou o relógio fixo); dia sem bloco é livre;
//   - bloco "um para o trecho": o mesmo relógio nos dias seguidos do trecho, dentro da semana (a semana começa na segunda
//     ou no domingo, a Configuração);
//   - o de ontem fica de fora, se houver outro candidato (no "um por dia");
//   - o ciclo: na semana, um relógio só volta depois que todos os candidatos do bloco passaram;
//   - a fila (fifo): sai quem espera há mais tempo (nunca usado primeiro; empate: o cadastrado primeiro), sem o de ontem;
//   - a garantia de rodízio: ninguém fica mais de max_sem_uso + o número de candidatos dias sem ir ao pulso (fora do
//     aleatório, que não tem garantia);
//   - o aleatório: sai mais de um relógio;
//   - a escala: o smartwatch no máximo 7 dias seguidos; os dias escolhidos à mão ficam depois do replanejamento da manhã;
//   - o personalizado (o exemplo do README): na semana só os tradicionais; o smartwatch fica os dias do roteiro;
//   - o rodízio manual: o dia escolhido à mão sai como foi escolhido, o livre fica sem relógio e sem ninguém no pulso, e os
//     dois continuam depois do Sortear de novo e dos crons; o Decida por mim não repete o de ontem nem o de amanhã;
//   - trocar o relógio de hoje no próprio dia (desligado, o padrão): recusado.
// Depois dos modos, as trocas de modo (simular_trocas): num banco só, o semanal de 1 e 2 semanas, a escala de 7 e 30 dias, ida e
// volta, com o Sortear de novo, os Apagar e a escala vencendo; depois de cada passo, o plano sem buraco até o fim esperado,
// nada sorteado depois dele e os escolhidos à mão no lugar. O filtro "trocas de modo" roda só elas.
// Sai com 1 se alguma regra falhar, e diz o modo, o dia e o que aconteceu.
if (PHP_SAPI !== "cli" || count($argv) < 3) {
    fwrite(STDERR, "Uso: php testes/simulacao.php <endereço do api.php> <pasta da rodada> [filtro...]\n");
    exit(1);
}
$API = $argv[1];
$PASTA = rtrim($argv[2], "/");
$FILTROS = array_slice($argv, 3);
$TOKEN = getenv("RELOGIOS_TOKEN") ?: "token-de-teste-123";
$DIAS = max(7, (int)(getenv("SIMULACAO_DIAS") ?: 28));
$RELOGIO = getenv("FAKETIME_TIMESTAMP_FILE") ?: $PASTA . "/relogio.txt";
date_default_timezone_set("America/Sao_Paulo");
$INICIO = "2027-01-04";   // uma segunda-feira
$falhas = [];

// o relógio falso: todos (o servidor, o cron e este roteiro) passam a ver este instante. Escrito ao lado e trocado de uma
// vez (rename): o libfaketime relê o arquivo a cada leitura do relógio, e o servidor lê o relógio o tempo todo; pego vazio
// no meio de uma escrita (o file_put_contents esvazia antes de escrever), ele derruba o processo
function relogio($instante)
{
    global $RELOGIO;
    file_put_contents($RELOGIO . ".novo", $instante . "\n");
    rename($RELOGIO . ".novo", $RELOGIO);
    clearstatcache();
}

function chamar($metodo, $params = [])
{
    global $API, $TOKEN;
    $ch = curl_init($metodo === "GET" ? $API . "?" . http_build_query($params) : $API);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["X-Api-Token: " . $TOKEN], CURLOPT_TIMEOUT => 120]);
    if ($metodo === "POST") {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $corpo = (string)curl_exec($ch);
    $json = json_decode($corpo, true);
    return is_array($json) ? $json : ["ok" => false, "erros" => ["resposta que não é JSON: " . substr($corpo, 0, 300)]];
}

// uma escrita que tem de dar certo (ou, com $espera_ok = false, ser recusada)
function escrever($params, $espera_ok = true, $onde = "")
{
    global $falhas;
    $r = chamar("POST", $params);
    if (($r["ok"] ?? false) !== $espera_ok) {
        $falhas[] = ($onde !== "" ? $onde . ": " : "") . $params["recurso"] . "." . $params["acao"] . ($espera_ok ? " recusado: " : " devia ser recusado: ")
            . json_encode($r["erros"] ?? $r["mensagem"] ?? $r, JSON_UNESCAPED_UNICODE);
    }
    return $r;
}

function cron($onde)
{
    global $falhas;
    static $rodada = 0;
    $saida = [];
    // a semente de cada rodada do cron é outra (a mesma semente em todo processo daria o mesmo sorteio todo dia: o aleatório
    // não seria aleatório), e continua repetível de uma simulação para a outra. Com tempo máximo: com o relógio parado, uma
    // espera "até o segundo virar" nunca acaba (e o teste não pode ficar preso)
    $rodada++;
    exec("RELOGIOS_SEMENTE=" . (1000 + $rodada) . " timeout 300 " . escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__DIR__ . "/../cron.php") . " -v 2>&1", $saida, $codigo);
    $texto = implode("\n", $saida);
    if ($codigo !== 0 || preg_match("/PHP (Warning|Notice|Fatal|Deprecated)|\\bERRO\\b/u", $texto) === 1) {
        $falhas[] = $onde . ": o cron falhou (" . ($codigo === 124 ? "parou depois de 300 s" : $codigo) . "): " . substr($texto, 0, 400);
    }
}

function data_mais($data, $dias)
{
    return date("Y-m-d", strtotime($data . " " . ($dias >= 0 ? "+" : "") . $dias . " days"));
}

// o dia da semana (1 segunda a 7 domingo) e o primeiro dia da semana de uma data, pela semana_inicio
function dia_semana($data)
{
    return (int)date("N", strtotime($data));
}
function semana_de($data, $inicio)
{
    $ds = dia_semana($data);
    $volta = $inicio === "domingo" ? $ds % 7 : $ds - 1;
    return data_mais($data, -$volta);
}

// ---------- o modelo: a árvore e os relógios ----------
relogio($INICIO . " 05:00:00");
$GRUPOS = [];
$PAI = [];
foreach ((chamar("GET", ["recurso" => "arvore"])["grupos"] ?? []) as $g) {
    $GRUPOS[$g["caminho"]] = (int)$g["id"];
    $PAI[(int)$g["id"]] = $g["pai_id"] === null ? 0 : (int)$g["pai_id"];
}
function G($caminho)
{
    global $GRUPOS;
    if (!isset($GRUPOS[$caminho])) {
        fwrite(STDERR, "O grupo " . $caminho . " não está na árvore (" . implode(", ", array_keys($GRUPOS)) . ")\n");
        exit(1);
    }
    return $GRUPOS[$caminho];
}
// os relógios: sete disponíveis, de cinco grupos, e um indisponível (que nunca pode sair)
$REL = [];
$GRUPO_DE = [];
foreach ([
    ["Smart", "Smartwatch", ["preferencia" => 70, "autonomia_dias" => 14]],
    ["Auto A", "Tradicional › Mecânico › Automático", ["preferencia" => 90, "reserva_horas" => 41]],
    ["Auto B", "Tradicional › Mecânico › Automático", ["preferencia" => 60, "reserva_horas" => 40]],
    ["Auto C", "Tradicional › Mecânico › Automático", ["preferencia" => 50, "reserva_horas" => 38]],
    ["Corda", "Tradicional › Mecânico › Corda manual", ["preferencia" => 55, "reserva_horas" => 36]],
    ["Pilha", "Tradicional › Quartzo › Pilha", ["preferencia" => 40, "data_pilha" => "2026-06-01", "vida_pilha_meses" => 24]],
    ["Solar", "Tradicional › Quartzo › Solar", ["preferencia" => 80, "reserva_dias" => 180, "carga_sol_horas" => 10]],
    ["Fora", "Tradicional › Quartzo", ["preferencia" => 99], 0],
] as $x) {
    $r = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => $x[0], "no_id" => G($x[1]), "disponivel" => $x[3] ?? 1,
        "valores" => $x[2] + ["data_compra" => "2026-01-10"]], true, "modelo");
    $REL[$x[0]] = (int)($r["id"] ?? 0);
    $GRUPO_DE[(int)($r["id"] ?? 0)] = G($x[1]);
}
$NOME = array_flip($REL);
$DISPONIVEIS = array_values(array_diff_key($REL, ["Fora" => 1]));
// a semana antes do começo: cada relógio foi ao pulso num dia (o primeiro cadastrado ontem, o último há 7 dias). Assim
// ninguém começa parado desde a compra (a garantia não decide tudo nas primeiras semanas), a nota e o sorteio pesam desde o
// primeiro dia, e a fila começa por quem está esperando há mais tempo, que não é o primeiro cadastrado
$HISTORICO = [];
foreach ($DISPONIVEIS as $k => $rid) {
    $d_h = data_mais($INICIO, -1 - $k);
    escrever(["recurso" => "lancamento", "acao" => "periodo", "relogio_id" => $rid, "tipo" => "pulso", "inicio" => $d_h . " 08:00", "fim" => $d_h . " 21:00"], true, "modelo");
    $HISTORICO[$rid] = $d_h;
}
if ($falhas) {
    echo "O modelo não ficou pronto:\n  " . implode("\n  ", $falhas) . "\n";
    exit(1);
}
// os relógios de um ponto da árvore (o grupo e os subgrupos), ou só o fixo; 0: todos os disponíveis
function candidatos_do_bloco($b)
{
    global $DISPONIVEIS, $GRUPO_DE, $PAI;
    if ((int)($b["relogio_id"] ?? 0) > 0) {
        return [(int)$b["relogio_id"]];
    }
    $no = (int)($b["no_id"] ?? 0);
    if ($no === 0) {
        return $DISPONIVEIS;
    }
    return array_values(array_filter($DISPONIVEIS, function ($rid) use ($no, $GRUPO_DE, $PAI) {
        for ($g = $GRUPO_DE[$rid]; $g > 0; $g = $PAI[$g] ?? 0) {
            if ($g === $no) {
                return true;
            }
        }
        return false;
    }));
}

// o modelo guardado: cada modo começa dele (o servidor lê o arquivo do SQLite a cada pedido)
$BANCO = $PASTA . "/sqlite.sqlite";
$MODELO = $PASTA . "/modelo.sqlite";
function guardar_modelo()
{
    global $BANCO, $MODELO;
    $db = new SQLite3($BANCO);
    $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
    $db->close();
    copy($BANCO, $MODELO);
}
function voltar_ao_modelo()
{
    global $BANCO, $MODELO, $PASTA;
    foreach (["", "-wal", "-shm", "-journal"] as $s) {
        @unlink($BANCO . $s);
    }
    copy($MODELO, $BANCO);
    // as cópias de segurança do modo de antes (a da noite tem a data e a hora no nome: com as mesmas datas de novo e o
    // relógio parado, ela esperaria o nome ficar livre para sempre)
    foreach (glob($PASTA . "/copias_sqlite/*") ?: [] as $f) {
        @unlink($f);
    }
}
guardar_modelo();

// ---------- os modos ----------
$todos_os_dias = [1, 2, 3, 4, 5, 6, 7];
$bloco_todos = function ($um_por, $no = 0, $dias = null, $nome = "Todos") use ($todos_os_dias) {
    return ["nome" => $nome, "dias" => $dias ?? $todos_os_dias, "no_id" => $no, "um_por" => $um_por, "relogio_id" => 0];
};
$semana_fds = function () {
    return [["nome" => "Semana", "dias" => [1, 2, 3, 4, 5], "no_id" => G("Smartwatch"), "um_por" => "bloco", "relogio_id" => 0],
        ["nome" => "Fim de semana", "dias" => [6, 7], "no_id" => G("Tradicional"), "um_por" => "dia", "relogio_id" => 0]];
};
$por_dia = function () {
    global $REL;
    return [["nome" => "Segunda", "dias" => [1], "no_id" => G("Tradicional › Mecânico"), "um_por" => "dia", "relogio_id" => 0],
        ["nome" => "Terça", "dias" => [2], "no_id" => G("Tradicional"), "um_por" => "dia", "relogio_id" => 0],
        ["nome" => "Quarta", "dias" => [3], "no_id" => 0, "um_por" => "dia", "relogio_id" => $REL["Pilha"]],
        ["nome" => "Quinta", "dias" => [4], "no_id" => G("Tradicional › Mecânico › Automático"), "um_por" => "dia", "relogio_id" => 0],
        ["nome" => "Sexta", "dias" => [5], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0],
        ["nome" => "Domingo", "dias" => [7], "no_id" => G("Tradicional › Quartzo"), "um_por" => "dia", "relogio_id" => 0]];
};
$roteiro_readme = "util = DIA_SEMANA() <= 5\ncandidato = SE(util; NO_GRUPO(\"Tradicional\"); 1)\npeso = SE(dias_sem_uso > 3; 100; 1)\ndias = SE(NO_GRUPO(\"Smartwatch\"); dias_seguidos; 1)";

// cada modo: o nome, a Configuração, o modo (como a página Modos de rodízio grava) e o que acontece nele (dia => função)
$MODOS = [];
foreach (["inteligente", "ponderado", "aleatorio", "fifo"] as $f) {
    $MODOS[] = ["nome" => "um por semana, " . $f, "config" => [], "modo" => ["tipo" => "semana", "selecao" => $f, "semanas" => 1, "blocos" => [$bloco_todos("bloco")]]];
}
$MODOS[] = ["nome" => "um por semana, duas semanas, semana no domingo", "config" => ["semana_inicio" => "domingo"],
    "modo" => ["tipo" => "semana", "selecao" => "inteligente", "semanas" => 2, "blocos" => [$bloco_todos("bloco", G("Tradicional"))]]];
foreach (["ponderado", "fifo"] as $f) {
    $MODOS[] = ["nome" => "semana e fim de semana, " . $f, "config" => [], "modo" => ["tipo" => "semana_fds", "selecao" => $f, "semanas" => 1, "blocos" => $semana_fds()]];
}
$MODOS[] = ["nome" => "semana e fim de semana, semana no domingo", "config" => ["semana_inicio" => "domingo"],
    "modo" => ["tipo" => "semana_fds", "selecao" => "inteligente", "semanas" => 1, "blocos" => $semana_fds()]];
$MODOS[] = ["nome" => "por dia da semana, ponderado, com rodízio manual", "config" => ["max_sem_uso" => 6], "manual" => true,
    "modo" => ["tipo" => "dia_semana", "selecao" => "ponderado", "semanas" => 1, "blocos" => $por_dia()]];
$MODOS[] = ["nome" => "por dia da semana, fifo, três semanas", "config" => [], "modo" => ["tipo" => "dia_semana", "selecao" => "fifo", "semanas" => 3, "blocos" => $por_dia()]];
foreach (["inteligente", "ponderado", "fifo"] as $f) {
    $MODOS[] = ["nome" => "um por dia, " . $f . ", garantia de 5 dias", "config" => ["max_sem_uso" => 5],
        "modo" => ["tipo" => "dia_semana", "selecao" => $f, "semanas" => 1, "blocos" => [$bloco_todos("dia")]]];
}
$MODOS[] = ["nome" => "um por dia, ciclo, maior nota", "config" => ["max_sem_uso" => 0],
    "modo" => ["tipo" => "dia_semana", "selecao" => "inteligente", "ciclo" => 1, "semanas" => 1, "blocos" => [$bloco_todos("dia")]]];
$MODOS[] = ["nome" => "um por dia, ciclo, semana no domingo", "config" => ["max_sem_uso" => 0, "semana_inicio" => "domingo"],
    "modo" => ["tipo" => "dia_semana", "selecao" => "ponderado", "ciclo" => 1, "semanas" => 1, "blocos" => [$bloco_todos("dia")]]];
$MODOS[] = ["nome" => "aleatório todo dia", "config" => [], "modo" => ["tipo" => "aleatorio", "selecao" => "aleatorio", "semanas" => 1, "blocos" => [$bloco_todos("dia")]]];
$MODOS[] = ["nome" => "um por dia, com as trocas à mão (trocar no próprio dia ligado)", "config" => ["max_sem_uso" => 6, "trocar_hoje" => "1"], "trocas" => true,
    "modo" => ["tipo" => "dia_semana", "selecao" => "ponderado", "semanas" => 1, "blocos" => [$bloco_todos("dia")]]];
$MODOS[] = ["nome" => "escala inteligente, 30 dias, com rodízio manual", "config" => [], "manual" => true,
    "modo" => ["tipo" => "escala", "selecao" => "inteligente", "escala_dias" => 30, "blocos" => [$bloco_todos("dia")]]];
$MODOS[] = ["nome" => "personalizado (o roteiro do README)", "config" => [], "modo" => ["tipo" => "personalizado", "semanas" => 1, "roteiro" => $roteiro_readme]];

// ---------- a simulação de um modo ----------
function simular($m)
{
    global $falhas, $INICIO, $DIAS, $REL, $NOME, $DISPONIVEIS, $HISTORICO;
    $erro = function ($dia, $texto) use ($m, &$falhas) {
        $falhas[] = $m["nome"] . ($dia !== null ? " · " . $dia . " (" . ["", "seg", "ter", "qua", "qui", "sex", "sáb", "dom"][dia_semana($dia)] . ")" : "") . ": " . $texto;
    };
    $antes = count($falhas);
    voltar_ao_modelo();
    relogio($INICIO . " 05:00:00");
    $onde = $m["nome"] . " (o começo)";
    if ($m["config"]) {
        escrever(["recurso" => "config", "acao" => "salvar"] + $m["config"], true, $onde);
    }
    $semana_inicio = $m["config"]["semana_inicio"] ?? "segunda";
    $max = (int)($m["config"]["max_sem_uso"] ?? 21);
    $r = escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Simulado"] + $m["modo"], true, $onde);
    $modo_id = (int)($r["id"] ?? 0);
    escrever(["recurso" => "modos", "acao" => "ativar", "id" => $modo_id], true, $onde);
    // o modelo não tem plano nenhum (nada o montou ainda): o cron da manhã monta tudo pelo modo novo
    $modo = chamar("GET", ["recurso" => "hoje"]);
    $blocos = [];
    foreach (($modo["modos"] ?? []) as $x) {
        if ((int)$x["id"] === $modo_id) {
            $blocos = $x["blocos"] ?? [];
        }
    }
    $escala = ($m["modo"]["tipo"] ?? "") === "escala";
    $pers = ($m["modo"]["tipo"] ?? "") === "personalizado";
    $semanas = (int)($m["modo"]["semanas"] ?? 1);
    $bloco_do_dia = function ($data) use ($blocos) {
        foreach ($blocos as $b) {
            $dias_b = is_array($b["dias"]) ? $b["dias"] : preg_split("/\\D+/", (string)$b["dias"], -1, PREG_SPLIT_NO_EMPTY);
            if (in_array(dia_semana($data), array_map("intval", $dias_b), true)) {
                return $b;
            }
        }
        return null;
    };

    $usado = [];         // data => relógio do dia (o que estava no plano ao meio-dia), null no dia livre
    $no_pulso = [];      // data => relógios no pulso ao meio-dia
    $previsto = [];      // data => o que o plano dizia na véspera
    $manuais = [];       // data => relógio escolhido à mão (ou "livre")
    $decida = [];        // datas do Decida por mim
    for ($i = 0; $i < $DIAS; $i++) {
        $dia = data_mais($INICIO, $i);
        relogio($dia . " 07:10:00");
        cron($m["nome"] . " · " . $dia . " 07:10");
        relogio($dia . " 12:00:00");
        $h = chamar("GET", ["recurso" => "hoje"]);
        if (!isset($h["data"]) || $h["data"] !== $dia) {
            $erro($dia, "a página Hoje não respondeu o dia de hoje: " . json_encode($h["data"] ?? $h, JSON_UNESCAPED_UNICODE));
            continue;
        }
        $usado[$dia] = $h["dia"] !== null ? (int)$h["dia"]["relogio_id"] : null;
        $no_pulso[$dia] = array_values(array_map(function ($x) { return (int)$x["id"]; }, array_filter($h["relogios"] ?? [], function ($x) { return !empty($x["em_uso"]); })));
        $plano = [];
        foreach ($h["plano"] ?? [] as $p) {
            $plano[$p["data"]] = $p;
        }
        $livres = array_flip($h["livres"] ?? []);
        $amanha = data_mais($dia, 1);
        if (!$escala) {
            $previsto[$amanha] = isset($plano[$amanha]) ? (int)$plano[$amanha]["relogio_id"] : (isset($livres[$amanha]) ? null : "nada");
        }
        // o horizonte: o fim da última das semanas do modo; com uma semana só, no último dia, também o primeiro da seguinte
        $fim_esperado = $escala ? (string)($h["escala_fim"] ?? "") : data_mais(semana_de($dia, $semana_inicio), 7 * $semanas - 1);
        if (!$escala && $semanas === 1 && $dia === $fim_esperado) {
            $fim_esperado = data_mais($dia, 1);
        }
        if ($escala && ($fim_esperado === "" || $fim_esperado < data_mais($dia, 1))) {
            $erro($dia, "a escala acaba em " . ($fim_esperado ?: "nada") . ", antes de amanhã");
        }
        for ($d = $dia; $d <= $fim_esperado; $d = data_mais($d, 1)) {
            $b = $pers || $escala ? true : $bloco_do_dia($d);
            if ($b !== null && !isset($plano[$d]) && !isset($livres[$d])) {
                $erro($dia, "o plano não chega a " . $d . " (devia ir até " . $fim_esperado . ")");
                break;
            }
        }

        // o que o modo faz neste dia
        if ($i === 1 && ($m["config"]["trocar_hoje"] ?? "0") !== "1") {
            // trocar o relógio de hoje no próprio dia (desligado, o padrão): recusado
            $outro = null;
            foreach ($DISPONIVEIS as $rid) {
                if ($rid !== $usado[$dia]) {
                    $outro = $rid;
                    break;
                }
            }
            if ($usado[$dia] !== null) {
                escrever(["recurso" => "rodizio", "acao" => "trocar_dia", "data" => $dia, "relogio_id" => $outro], false, $m["nome"] . " · " . $dia);
                escrever(["recurso" => "rodizio", "acao" => "usando", "relogio_id" => $outro], false, $m["nome"] . " · " . $dia);
            }
        }
        if (!empty($m["manual"]) && $i === 3) {
            // o rodízio manual, de daqui a 5 a 8 dias: um relógio à mão, um dia livre, um Decida por mim e outro à mão
            $d5 = data_mais($dia, 5);
            $d6 = data_mais($dia, 6);
            $d7 = data_mais($dia, 7);
            $d8 = data_mais($dia, 8);
            $manuais = [$d5 => $REL["Corda"], $d6 => "livre", $d8 => $REL["Auto C"]];
            $decida = [$d7];
            escrever(["recurso" => "rodizio", "acao" => "manual", "de" => $d5, "ate" => $d8,
                "dias" => [$d5 => (string)$REL["Corda"], $d6 => "livre", $d7 => "auto", $d8 => (string)$REL["Auto C"]]], true, $m["nome"] . " · " . $dia);
        }
        if (!empty($m["manual"]) && $i === 4) {
            // o Sortear de novo (de amanhã): os dias à mão e o livre ficam
            escrever(["recurso" => "rodizio", "acao" => "resortear"], true, $m["nome"] . " · " . $dia);
        }
        if ($escala && $i === 10) {
            // os dias do modo mudados sem passar pelo salvar do modo (como no servidor do Lucas: o modo salvo com 30 dias numa
            // versão que não apagava o fim): a escala lembra com quantos dias foi gerada e se refaz inteira na página Hoje
            foreach ([7, 30] as $dias_x) {
                $pdo = new PDO("sqlite:" . $GLOBALS["BANCO"]);
                $pdo->exec("UPDATE modo SET escala_dias = " . $dias_x . " WHERE id = " . (int)$modo_id);
                $pdo = null;
                $fim_x = (string)(chamar("GET", ["recurso" => "hoje"])["escala_fim"] ?? "");
                if ($fim_x < data_mais($dia, $dias_x - 1) || $fim_x > data_mais($dia, $dias_x)) {
                    $erro($dia, "o modo passou a " . $dias_x . " dias e a escala ficou até " . ($fim_x ?: "nada") . " (devia ir até " . data_mais($dia, $dias_x) . ")");
                }
            }
        }
        if ($escala && $i === 12) {
            // Apagar um período só com o começo (amanhã): monta até onde os 30 dias do modo deixam, a contar de hoje (e não só
            // até o fim que a escala tinha, de dois dias atrás)
            escrever(["recurso" => "rodizio", "acao" => "periodo_sortear", "de" => $amanha], true, $m["nome"] . " · " . $dia);
            $fim_x = (string)(chamar("GET", ["recurso" => "hoje"])["escala_fim"] ?? "");
            if ($fim_x < data_mais($dia, 29) || $fim_x > data_mais($dia, 30)) {
                $erro($dia, "apagar um período só com o começo deixou a escala até " . ($fim_x ?: "nada") . " (devia ir até " . data_mais($dia, 30) . ")");
            }
        }
        if (!empty($m["trocas"])) {
            $onde_t = $m["nome"] . " · " . $dia;
            $fora_de = function ($evitar) use ($DISPONIVEIS) {
                foreach ($DISPONIVEIS as $x) {
                    if (!in_array($x, $evitar, true)) {
                        return $x;
                    }
                }
                return $DISPONIVEIS[0];
            };
            $relido = function () use ($dia, &$usado, &$no_pulso) {
                $h2 = chamar("GET", ["recurso" => "hoje"]);
                $usado[$dia] = $h2["dia"] !== null ? (int)$h2["dia"]["relogio_id"] : null;
                $no_pulso[$dia] = array_values(array_map(function ($x) { return (int)$x["id"]; }, array_filter($h2["relogios"] ?? [], function ($x) { return !empty($x["em_uso"]); })));
                $p2 = [];
                foreach ($h2["plano"] ?? [] as $p) {
                    $p2[$p["data"]] = (int)$p["relogio_id"];
                }
                return [$h2, $p2];
            };
            if ($i === 2) {
                // Usando hoje: o relógio de hoje passa a ser outro, agora (o dia vira escolhido à mão)
                $x = $fora_de([$usado[$dia]]);
                escrever(["recurso" => "rodizio", "acao" => "usando", "relogio_id" => $x], true, $onde_t);
                $relido();
                $manuais[$dia] = $x;
            } elseif ($i === 3) {
                // o trocar por… de amanhã
                $y = $fora_de([$usado[$dia], (int)($plano[$amanha]["relogio_id"] ?? 0)]);
                escrever(["recurso" => "rodizio", "acao" => "trocar_dia", "data" => $amanha, "relogio_id" => $y], true, $onde_t);
                $manuais[$amanha] = $y;
            } elseif ($i === 5) {
                // os relógios de um período, na ordem, um por dia: de depois de amanhã a daqui a 4 dias
                [$pa, $pb] = [$REL["Corda"], $REL["Solar"]];
                escrever(["recurso" => "rodizio", "acao" => "periodo", "de" => data_mais($dia, 2), "ate" => data_mais($dia, 4), "relogio_ids" => [$pa, $pb]], true, $onde_t);
                $manuais[data_mais($dia, 2)] = $pa;
                $manuais[data_mais($dia, 3)] = $pb;
                $manuais[data_mais($dia, 4)] = $pa;
            } elseif ($i === 6) {
                // um período livre: daqui a 5 e 6 dias, sem relógio e sem sorteio
                escrever(["recurso" => "rodizio", "acao" => "periodo_livre", "de" => data_mais($dia, 5), "ate" => data_mais($dia, 6)], true, $onde_t);
                $manuais[data_mais($dia, 5)] = "livre";
                $manuais[data_mais($dia, 6)] = "livre";
            } elseif ($i === 13) {
                // sortear de novo um período (amanhã a daqui a 3 dias): fora dele, o plano não muda
                [, $p_antes] = $relido();
                escrever(["recurso" => "rodizio", "acao" => "periodo_sortear", "de" => $amanha, "ate" => data_mais($dia, 3)], true, $onde_t);
                [, $p_depois] = $relido();
                foreach ($p_antes as $d => $rid) {
                    if ($d > $dia && ($d < $amanha || $d > data_mais($dia, 3)) && ($p_depois[$d] ?? null) !== $rid) {
                        $erro($dia, "sortear de novo o período de " . $amanha . " a " . data_mais($dia, 3) . " mudou " . $d);
                    }
                }
                $previsto[$amanha] = $p_depois[$amanha] ?? "nada";
            } elseif ($i === 15) {
                // Sem relógio hoje: o dia fica livre e ninguém fica no pulso
                escrever(["recurso" => "rodizio", "acao" => "sem_relogio"], true, $onde_t);
                [$h2] = $relido();
                if (empty($h2["hoje_livre"])) {
                    $erro($dia, "o Sem relógio hoje não deixou o dia livre");
                }
                $manuais[$dia] = "livre";
            } elseif ($i === 17) {
                // a próxima semana (pela API, em qualquer modo sem escala): ela entra inteira no plano
                escrever(["recurso" => "rodizio", "acao" => "proxima_semana"], true, $onde_t);
                [, $p2] = $relido();
                $ini_p = data_mais(semana_de($dia, $semana_inicio), 7);
                for ($d = $ini_p; $d <= data_mais($ini_p, 6); $d = data_mais($d, 1)) {
                    if (!isset($p2[$d])) {
                        $erro($dia, "a próxima semana não montou " . $d);
                    }
                }
                $previsto[$amanha] = $p2[$amanha] ?? "nada";
            } elseif ($i === 20) {
                // Apagar tudo (de amanhã): o modo monta de novo, e amanhã tem relógio
                escrever(["recurso" => "rodizio", "acao" => "apagar_tudo"], true, $onde_t);
                [, $p2] = $relido();
                if (!isset($p2[$amanha])) {
                    $erro($dia, "depois do Apagar tudo, amanhã ficou sem relógio");
                }
                $previsto[$amanha] = $p2[$amanha] ?? "nada";
            }
        }

        relogio($dia . " 22:30:00");
        cron($m["nome"] . " · " . $dia . " 22:30");
    }

    // ---------- as regras ----------
    $dias = array_keys($usado);
    $ultimo_uso = $HISTORICO;
    $ontem = $REL["Smart"];   // o de ontem, na semana antes do começo
    $seguidos = 1;
    $semana_vistos = [];
    foreach ($dias as $k => $dia) {
        $rid = $usado[$dia];
        $b = $pers || $escala ? null : $bloco_do_dia($dia);
        $livre_esperado = ($manuais[$dia] ?? null) === "livre" || (!$pers && !$escala && $b === null);
        if ($rid === null) {
            if (!$livre_esperado) {
                $erro($dia, "sem relógio do dia");
            }
            if ($no_pulso[$dia]) {
                $erro($dia, "dia livre, mas no pulso: " . implode(", ", array_map(function ($x) use ($NOME) { return $NOME[$x] ?? $x; }, $no_pulso[$dia])));
            }
            $ontem = null;
            $seguidos = 0;
            continue;
        }
        if ($livre_esperado) {
            $erro($dia, "devia ser livre, saiu " . ($NOME[$rid] ?? $rid));
        }
        if (!in_array($rid, $DISPONIVEIS, true)) {
            $erro($dia, "saiu um relógio que não está disponível: " . ($NOME[$rid] ?? $rid));
        }
        if ($no_pulso[$dia] !== [$rid]) {
            $erro($dia, "ao meio-dia, no pulso: [" . implode(", ", array_map(function ($x) use ($NOME) { return $NOME[$x] ?? $x; }, $no_pulso[$dia])) . "], e o relógio do dia é " . ($NOME[$rid] ?? $rid));
        }
        if (array_key_exists($dia, $previsto) && $previsto[$dia] !== $rid && !isset($manuais[$dia]) && !in_array($dia, $decida, true)) {
            $erro($dia, "a véspera dizia " . ($previsto[$dia] === null ? "livre" : ($previsto[$dia] === "nada" ? "nada" : ($NOME[$previsto[$dia]] ?? $previsto[$dia]))) . ", saiu " . ($NOME[$rid] ?? $rid));
        }
        if (isset($manuais[$dia]) && $manuais[$dia] !== "livre" && $manuais[$dia] !== $rid) {
            $erro($dia, "escolhido à mão " . ($NOME[$manuais[$dia]] ?? $manuais[$dia]) . ", saiu " . ($NOME[$rid] ?? $rid));
        }
        if (in_array($dia, $decida, true)) {
            $vizinhos = [$usado[data_mais($dia, -1)] ?? null, $usado[data_mais($dia, 1)] ?? null];
            if (in_array($rid, $vizinhos, true)) {
                $erro($dia, "o Decida por mim repetiu o de ontem ou o de amanhã (" . ($NOME[$rid] ?? $rid) . ")");
            }
        }
        $manual_hoje = isset($manuais[$dia]) || in_array($dia, $decida, true);
        // o bloco do dia: o relógio sai dele
        if ($b !== null && !$manual_hoje) {
            $cands = candidatos_do_bloco($b);
            if (!in_array($rid, $cands, true)) {
                $erro($dia, ($NOME[$rid] ?? $rid) . " não é do bloco " . $b["nome"] . " (" . implode(", ", array_map(function ($x) use ($NOME) { return $NOME[$x]; }, $cands)) . ")");
            }
            $ant = $dias[$k - 1] ?? null;
            $b_ant = $ant !== null ? $bloco_do_dia($ant) : null;
            $mesma_semana = $ant !== null && semana_de($ant, $semana_inicio) === semana_de($dia, $semana_inicio);
            if ($b["um_por"] === "bloco" && $ant !== null && $b_ant !== null && $b_ant["nome"] === $b["nome"] && $mesma_semana
                && $usado[$ant] !== null && !isset($manuais[$ant]) && $usado[$ant] !== $rid) {
                $erro($dia, "o bloco " . $b["nome"] . " é um relógio para o trecho: ontem " . ($NOME[$usado[$ant]] ?? "") . ", hoje " . ($NOME[$rid] ?? $rid));
            }
            $novo_trecho = $b["um_por"] === "dia" || !$mesma_semana || $b_ant === null || $b_ant["nome"] !== $b["nome"];
            if ($novo_trecho && $ontem === $rid && count($cands) > 1) {
                $erro($dia, "repetiu o de ontem (" . ($NOME[$rid] ?? $rid) . "), com " . count($cands) . " candidatos");
            }
            // o ciclo: na semana, só volta depois que todos os candidatos do bloco passaram
            if ((int)($m["modo"]["ciclo"] ?? 0) === 1 && $b["um_por"] === "dia") {
                $chave = semana_de($dia, $semana_inicio) . "|" . $b["nome"];
                $vistos = $semana_vistos[$chave] ?? [];
                if (in_array($rid, $vistos, true) && count(array_diff($cands, $vistos)) > 0) {
                    $erro($dia, "o ciclo repetiu " . ($NOME[$rid] ?? $rid) . " antes de passarem " . implode(", ", array_map(function ($x) use ($NOME) { return $NOME[$x]; }, array_diff($cands, $vistos))));
                }
                $semana_vistos[$chave] = count(array_diff($cands, $vistos)) === 0 ? [$rid] : array_merge($vistos, [$rid]);
            }
            // a fila: quem espera há mais tempo (nunca usado primeiro; empate: o cadastrado primeiro), sem o de ontem
            if (($m["modo"]["selecao"] ?? "") === "fifo" && $novo_trecho) {
                $fila = array_values(array_filter($cands, function ($x) use ($ontem, $cands) { return $x !== $ontem || count($cands) === 1; }));
                usort($fila, function ($x, $y) use ($ultimo_uso) {
                    $ux = $ultimo_uso[$x] ?? "0000-00-00";
                    $uy = $ultimo_uso[$y] ?? "0000-00-00";
                    return $ux === $uy ? $x <=> $y : strcmp($ux, $uy);
                });
                if ($fila && $fila[0] !== $rid) {
                    $erro($dia, "a fila dava " . ($NOME[$fila[0]] ?? $fila[0]) . " (esperando desde " . ($ultimo_uso[$fila[0]] ?? "nunca") . "), saiu " . ($NOME[$rid] ?? $rid));
                }
            }
        }
        // o personalizado do README: na semana, só os tradicionais
        if ($pers && dia_semana($dia) <= 5 && $rid === $REL["Smart"]) {
            $erro($dia, "o roteiro só deixa os tradicionais na semana, saiu o smartwatch");
        }
        $seguidos = $ontem === $rid ? $seguidos + 1 : 1;
        if ($escala && $rid === $REL["Smart"] && $seguidos > 7) {
            $erro($dia, "o smartwatch passou de 7 dias seguidos na escala");
        }
        if ($escala && $rid !== $REL["Smart"] && $seguidos > 1 && !$manual_hoje) {
            $erro($dia, ($NOME[$rid] ?? $rid) . " repetiu na escala (só o smartwatch fica dias seguidos)");
        }
        $ultimo_uso[$rid] = $dia;
        $ontem = $rid;
    }
    // a garantia de rodízio (fora do aleatório): nos modos de um por dia com a coleção inteira, ninguém fica parado mais de
    // max_sem_uso + o número de candidatos
    $um_por_dia_todos = count($blocos) === 1 && $blocos[0]["um_por"] === "dia" && (int)$blocos[0]["no_id"] === 0 && (int)($blocos[0]["relogio_id"] ?? 0) === 0;
    // (sem as trocas à mão: o dia trocado muda só ele, e os dias seguintes, sorteados antes contando com o relógio que
    // ia nele, ficam como estão; o relógio que perdeu a vez espera mais que a garantia)
    if ($um_por_dia_todos && $max > 0 && ($m["modo"]["selecao"] ?? "") !== "aleatorio" && (int)($m["modo"]["ciclo"] ?? 0) === 0 && empty($m["trocas"])) {
        $limite = $max + count($DISPONIVEIS);
        foreach ($DISPONIVEIS as $rid) {
            // os dias parados que ele já trazia da semana antes do começo
            $parado = (int)round((strtotime($INICIO) - strtotime($HISTORICO[$rid])) / 86400) - 1;
            $pior = $parado;
            foreach ($dias as $dia) {
                if ($usado[$dia] === null) {
                    continue;   // no dia livre ninguém vai ao pulso
                }
                $parado = $usado[$dia] === $rid ? 0 : $parado + 1;
                $pior = max($pior, $parado);
            }
            if ($pior > $limite) {
                $erro(null, ($NOME[$rid] ?? $rid) . " ficou " . $pior . " dias sem ir ao pulso (a garantia é de " . $max . " dias, com " . count($DISPONIVEIS) . " candidatos: no máximo " . $limite . ")");
            }
        }
    }
    // o aleatório: mais de um relógio (o de ontem fica de fora); com um sorteio por dia, pelo menos 3 em todo o período (com
    // um por semana são só 4 sorteios em 28 dias, e dois relógios se revezando é um resultado possível)
    if (($m["modo"]["selecao"] ?? "") === "aleatorio") {
        $diferentes = count(array_unique(array_filter($usado, function ($x) { return $x !== null; })));
        $minimo = ($m["modo"]["tipo"] ?? "") === "semana" ? 2 : 3;
        if ($diferentes < $minimo) {
            $erro(null, "o aleatório tirou " . $diferentes . " relógio(s) diferente(s) em " . count($dias) . " dias (o mínimo aqui é " . $minimo . ")");
        }
    }
    // um relógio por semana: a semana inteira com o mesmo (a semana do modo, pela semana_inicio)
    if (($m["modo"]["tipo"] ?? "") === "semana") {
        $por_semana = [];
        foreach ($dias as $dia) {
            $por_semana[semana_de($dia, $semana_inicio)][$dia] = $usado[$dia];
        }
        foreach ($por_semana as $ini => $ds) {
            if (count(array_unique($ds)) > 1) {
                $erro(null, "na semana de " . $ini . " saíram " . implode(", ", array_map(function ($x) use ($NOME) { return $NOME[$x] ?? "livre"; }, array_unique($ds))));
            }
        }
    }
    $resumo = [];
    foreach (array_count_values(array_map(function ($x) { return $x === null ? "livre" : (string)$x; }, $usado)) as $rid => $n) {
        $resumo[] = ($rid === "livre" ? "livre" : ($NOME[(int)$rid] ?? $rid)) . " " . $n;
    }
    printf("%-55s %s · %s\n", $m["nome"], count($falhas) === $antes ? "confere" : (count($falhas) - $antes) . " regra(s) falharam", implode(", ", $resumo));
    // com regra falhando, o diário do modo: o relógio de cada dia (* escolhido à mão)
    if (count($falhas) > $antes) {
        $linha = [];
        foreach ($usado as $dia => $rid) {
            $linha[] = substr($dia, 5) . " " . ($rid === null ? "livre" : ($NOME[$rid] ?? $rid)) . (isset($manuais[$dia]) || in_array($dia, $decida, true) ? "*" : "");
        }
        echo "    " . implode(" | ", $linha) . "\n";
    }
}

// ---------- as trocas de modo e de parâmetro ----------
// Um banco só, um passo depois do outro, como alguém mexendo nos modos: um por semana com 1 e 2 semanas, a escala de 7 e de
// 30 dias (salvando o modo, sem passar pelo salvar, com o Sortear de novo, o Apagar um período só com o começo e o Apagar
// tudo), a volta para o modo semanal e de novo para a escala, os dias passando com o cron e a escala vencendo. Depois de
// cada passo, o plano de amanhã até o fim esperado (o fim das semanas do modo, ou escala_dias contados de hoje; de amanhã,
// se o dia de hoje já começou no pulso) não tem buraco, nada sorteado passa desse fim, e os dias escolhidos à mão e os
// livres continuam onde estavam (menos no Apagar tudo, que tira tudo).
function simular_trocas()
{
    global $falhas, $INICIO, $REL, $BANCO;
    $nome = "trocas de modo (semanas 1 e 2, escala 7 e 30, ida e volta)";
    $antes = count($falhas);
    $passos = 0;
    voltar_ao_modelo();
    relogio($INICIO . " 05:00:00");
    $todos = [["nome" => "Todos", "dias" => [1, 2, 3, 4, 5, 6, 7], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0]];
    $semanal = function ($semanas) use ($todos) {
        return ["nome" => "Semanal", "tipo" => "dia_semana", "selecao" => "inteligente", "semanas" => $semanas, "blocos" => $todos];
    };
    $escala_m = function ($dias) use ($todos) {
        return ["nome" => "Escala", "tipo" => "escala", "selecao" => "inteligente", "escala_dias" => $dias, "blocos" => $todos];
    };
    $id_s = (int)(escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0] + $semanal(1), true, $nome)["id"] ?? 0);
    $id_e = (int)(escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0] + $escala_m(7), true, $nome)["id"] ?? 0);
    $guardados = [];   // data => relógio escolhido à mão, ou "livre"
    // confere o estado depois de um passo. $tipo: ["semanas", n] ou ["escala", n]
    $conferir = function ($passo, $dia, $tipo) use (&$falhas, $nome, &$guardados, &$passos) {
        $passos++;
        $onde = $nome . " · " . $dia . " · " . $passo;
        $h = chamar("GET", ["recurso" => "hoje"]);
        $p = chamar("GET", ["recurso" => "plano", "dias" => 60]);
        $plano = [];
        foreach ($p["plano"] ?? [] as $x) {
            $plano[$x["data"]] = $x;
        }
        $livres = array_flip($p["livres"] ?? []);
        if ($tipo[0] === "escala") {
            $fim = (string)($p["escala_fim"] ?? "");
            [$min, $max] = [data_mais($dia, $tipo[1] - 1), data_mais($dia, $tipo[1])];
            if ($fim < $min || $fim > $max) {
                $falhas[] = $onde . ": a escala de " . $tipo[1] . " dias vai até " . ($fim ?: "nada") . " (devia ir até " . $min . " ou " . $max . ")";
                return;
            }
        } elseif ($tipo[0] === "ate") {
            $fim = $tipo[1];
        } else {
            $fim = data_mais(semana_de($dia, "segunda"), 7 * $tipo[1] - 1);
            if ($tipo[1] === 1 && $dia === $fim) {
                $fim = data_mais($dia, 1);
            }
        }
        for ($d = data_mais($dia, 1); $d <= $fim; $d = data_mais($d, 1)) {
            if (!isset($plano[$d]) && !isset($livres[$d])) {
                $falhas[] = $onde . ": o plano tem um buraco em " . $d . " (devia ir até " . $fim . ")";
                break;
            }
        }
        $alem = [];
        foreach ($plano as $d => $x) {
            if ($d > $fim && $x["origem"] === "sorteio") {
                $alem[] = $d;
            }
        }
        if ($alem) {
            $falhas[] = $onde . ": sorteados depois do fim (" . $fim . "): " . implode(", ", array_slice($alem, 0, 5)) . (count($alem) > 5 ? "…" : "");
        }
        foreach ($guardados as $d => $v) {
            if ($d <= $dia) {
                continue;
            }
            $tem = $v === "livre" ? isset($livres[$d]) : (isset($plano[$d]) && (int)$plano[$d]["relogio_id"] === $v && $plano[$d]["origem"] === "manual");
            if (!$tem) {
                $falhas[] = $onde . ": o dia " . $d . " escolhido à mão (" . ($v === "livre" ? "livre" : $v) . ") sumiu ou mudou";
            }
        }
    };
    $salvar = function ($id, $modo, $onde) {
        escrever(["recurso" => "modos", "acao" => "salvar", "id" => $id] + $modo, true, $onde);
    };
    $rodizio = function ($campos, $onde) {
        escrever(["recurso" => "rodizio"] + $campos, true, $onde);
    };

    // segunda: o semanal de 1 semana, montado pelo cron da manhã
    $dia = $INICIO;
    escrever(["recurso" => "modos", "acao" => "ativar", "id" => $id_s], true, $nome);
    relogio($dia . " 07:10:00");
    cron($nome . " · " . $dia);
    relogio($dia . " 12:00:00");
    $conferir("semanal, 1 semana", $dia, ["semanas", 1]);
    $salvar($id_s, $semanal(2), $nome);
    $conferir("semanal passado a 2 semanas (só salvando)", $dia, ["semanas", 2]);
    $salvar($id_s, $semanal(1), $nome);
    $rodizio(["acao" => "apagar_tudo"], $nome);
    $conferir("de volta a 1 semana e Apagar tudo", $dia, ["semanas", 1]);

    // a escala de 7 ativada (o Aplicar só marca; a página Hoje monta a escala, que ainda não existia)
    escrever(["recurso" => "modos", "acao" => "ativar", "id" => $id_e], true, $nome);
    $conferir("escala de 7 ativada", $dia, ["escala", 7]);
    $salvar($id_e, $escala_m(30), $nome);
    $conferir("escala passada a 30 dias (só salvando)", $dia, ["escala", 30]);

    // terça e quarta, com o cron: a escala segue o mesmo período (o fim não anda)
    $fim_antes = (string)(chamar("GET", ["recurso" => "plano"])["escala_fim"] ?? "");
    foreach ([1, 2] as $k) {
        $dia = data_mais($INICIO, $k);
        relogio($dia . " 07:10:00");
        cron($nome . " · " . $dia);
    }
    relogio($dia . " 12:00:00");
    $fim_agora = (string)(chamar("GET", ["recurso" => "plano"])["escala_fim"] ?? "");
    if ($fim_agora !== $fim_antes) {
        $falhas[] = $nome . " · " . $dia . ": com os dias passando, o fim da escala andou de " . $fim_antes . " para " . $fim_agora . " (a escala segue o próprio período)";
    }
    $passos++;
    // Apagar um período só com o começo (amanhã): de novo os 30 dias, contados de hoje
    $rodizio(["acao" => "periodo_sortear", "de" => data_mais($dia, 1)], $nome);
    $conferir("apagar um período só com o começo, na escala de 30", $dia, ["escala", 30]);

    // um dia escolhido à mão e dois livres: ficam em todas as trocas até o Apagar tudo
    $rodizio(["acao" => "trocar_dia", "data" => data_mais($dia, 3), "relogio_id" => $REL["Corda"]], $nome);
    $rodizio(["acao" => "periodo_livre", "de" => data_mais($dia, 5), "ate" => data_mais($dia, 6)], $nome);
    $guardados = [data_mais($dia, 3) => $REL["Corda"], data_mais($dia, 5) => "livre", data_mais($dia, 6) => "livre"];
    $conferir("um dia à mão e dois livres", $dia, ["escala", 30]);
    $salvar($id_e, $escala_m(7), $nome);
    $conferir("escala de volta a 7 dias (só salvando)", $dia, ["escala", 7]);
    $rodizio(["acao" => "resortear"], $nome);
    $conferir("Sortear de novo na escala de 7", $dia, ["escala", 7]);

    // os dias do modo mudados sem passar pelo salvar (um banco salvo por uma versão antiga): 30 de novo, direto no banco
    $pdo = new PDO("sqlite:" . $BANCO);
    $pdo->exec("UPDATE modo SET escala_dias = 30 WHERE id = " . $id_e);
    $pdo = null;
    $conferir("escala passada a 30 dias direto no banco", $dia, ["escala", 30]);
    $salvar($id_e, $escala_m(7), $nome);
    $conferir("e de volta a 7", $dia, ["escala", 7]);
    $rodizio(["acao" => "apagar_tudo"], $nome);
    $guardados = [];
    $conferir("Apagar tudo na escala de 7", $dia, ["escala", 7]);

    // de volta ao semanal: o Aplicar só marca; o Sortear de novo monta a semana, e nada da escala fica depois dela
    escrever(["recurso" => "modos", "acao" => "ativar", "id" => $id_s], true, $nome);
    $rodizio(["acao" => "resortear"], $nome);
    $conferir("de volta ao semanal e Sortear de novo", $dia, ["semanas", 1]);
    // a próxima semana montada à mão: o Sortear de novo refaz até o fim dela (e não só até o fim desta)
    $rodizio(["acao" => "proxima_semana"], $nome);
    $fim_prox = data_mais(semana_de($dia, "segunda"), 13);
    $conferir("próxima semana montada", $dia, ["ate", $fim_prox]);
    $rodizio(["acao" => "resortear"], $nome);
    $conferir("Sortear de novo com a próxima semana montada", $dia, ["ate", $fim_prox]);
    $rodizio(["acao" => "apagar_tudo"], $nome);
    $conferir("Apagar tudo no semanal", $dia, ["semanas", 1]);

    // outro modo, semana e fim de semana de 2 semanas: o Aplicar só marca, o Sortear de novo monta as 2 semanas
    $fds = ["nome" => "Semana e fds", "tipo" => "semana_fds", "selecao" => "inteligente", "semanas" => 2, "blocos" => [
        ["nome" => "Semana", "dias" => [1, 2, 3, 4, 5], "no_id" => 0, "um_por" => "bloco", "relogio_id" => 0],
        ["nome" => "Fim de semana", "dias" => [6, 7], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0]]];
    $id_f = (int)(escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0] + $fds, true, $nome)["id"] ?? 0);
    escrever(["recurso" => "modos", "acao" => "ativar", "id" => $id_f], true, $nome);
    $rodizio(["acao" => "resortear"], $nome);
    $conferir("semana e fim de semana, 2 semanas", $dia, ["semanas", 2]);

    // e para a escala, agora de 30 (salva antes de ativar)
    $salvar($id_e, $escala_m(30), $nome);
    escrever(["recurso" => "modos", "acao" => "ativar", "id" => $id_e], true, $nome);
    $conferir("de novo a escala, agora de 30", $dia, ["escala", 30]);

    // o fim da escala passa: no dia seguinte a ele, o cron da manhã monta uma escala nova de 30
    $fim_e = (string)(chamar("GET", ["recurso" => "plano"])["escala_fim"] ?? "");
    $dia = data_mais($fim_e, 1);
    relogio($dia . " 07:10:00");
    cron($nome . " · " . $dia);
    relogio($dia . " 12:00:00");
    $conferir("a escala venceu e o cron fez a seguinte", $dia, ["escala", 30]);

    printf("%-55s %s · %d passos\n", $nome, count($falhas) === $antes ? "confere" : (count($falhas) - $antes) . " regra(s) falharam", $passos);
    return $passos;
}

$rodados = 0;
foreach ($MODOS as $m) {
    if ($FILTROS) {
        $casou = false;
        foreach ($FILTROS as $f) {
            if (mb_stripos($m["nome"], $f) !== false) {
                $casou = true;
            }
        }
        if (!$casou) {
            continue;
        }
    }
    simular($m);
    $rodados++;
}
$trocas = !$FILTROS;
foreach ($FILTROS as $f) {
    if (mb_stripos("trocas de modo", $f) !== false) {
        $trocas = true;
    }
}
$passos_trocas = $trocas ? simular_trocas() : 0;
if ($rodados === 0 && !$trocas) {
    echo "Nenhum modo com esse nome.\n";
    exit(1);
}
if ($falhas) {
    echo "\nRegras que falharam:\n  " . implode("\n  ", $falhas) . "\n";
    exit(1);
}
echo "\n" . ($rodados > 0 ? "Os " . $rodados . " modos conferem: " . $DIAS . " dias cada, com o cron de manhã e de noite" . ($trocas ? "; e" : ".") . "\n" : "")
    . ($trocas ? "as trocas de modo conferem: " . $passos_trocas . " passos.\n" : "");
