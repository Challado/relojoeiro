<?php
// O simulador de rodízio (a página Simulador; a API recurso=simulador): compara dois ou mais cenários antes de escolher um.
// Cada cenário é um modo cadastrado com o que se quer mudar nele (a forma de escolha, o ciclo, as semanas à frente, os dias da
// escala, a garantia de rodízio) e os relógios que entram no sorteio; todos no mesmo período (de, ate).
//
// Como simula: cada cenário roda os mesmos caminhos do sistema de verdade (op_relogio para os relógios do sorteio, op_modos e
// op_config para o modo e a garantia, modo_ativar, e o plano sorteado de novo do primeiro dia do período em diante, como o
// Sortear de novo: os dias escolhidos à mão e os livres ficam) dentro de uma transação que é desfeita no fim. Nada fica
// gravado, e as regras são as mesmas do rodízio (nada é reescrito para o simulador). Os sorteios usam uma semente (o gerador
// do PHP, sem o RANDOM.ORG): a mesma para todos os cenários de uma simulação, e repetível.
//
// Como aplica: o cenário escolhido roda de novo, com a mesma semente, numa transação que se confirma; antes de confirmar, o
// plano que saiu é conferido com o simulado (a impressão digital dos dias). Se alguma coisa mudou desde a simulação (um uso,
// um dia escolhido à mão), o plano sairia diferente: nada é gravado, e a resposta pede para simular de novo. Assim o que se
// grava é exatamente o que se viu.

// no máximo tantos cenários numa simulação, e tantos dias no período
const SIMULADOR_MAX_CENARIOS = 8;
const SIMULADOR_MAX_DIAS = 120;
// os parâmetros do modo que um cenário pode mudar (e a variação pode percorrer), na ordem da tela
const SIMULADOR_PARAMETROS = ["forma", "ciclo", "semanas", "escala_dias", "max_sem_uso"];

final class SimuladorParou extends RuntimeException
{
    public $erros;

    public function __construct($erros)
    {
        parent::__construct(implode(" ", $erros));
        $this->erros = $erros;
    }
}

// O título de cada parâmetro (a tela e as mensagens)
function simulador_titulos()
{
    return ["forma" => t("Forma de escolha"), "ciclo" => t("Ciclo"), "semanas" => t("Semanas à frente"),
        "escala_dias" => t("Dias da escala"), "max_sem_uso" => t("Garantia de rodízio (dias sem uso, no máximo; 0 desliga)")];
}

// O que um modo deixa mudar: cada parâmetro com se vale nele (aplica; não valendo, o motivo), o valor de agora e os limites
// (a forma: as opções, as formas que o tipo do modo aceita)
function simulador_parametros($m)
{
    $tipo = (string)$m["tipo"];
    $escala = $m["escala_dias"] !== null;
    $formas = [];
    foreach (modo_tipos_lista() as $x) {
        if ($x["chave"] === $tipo) {
            $formas = $x["formas"];
        }
    }
    return [
        "forma" => ["aplica" => count($formas) > 0, "motivo" => count($formas) > 0 ? "" : ($tipo === "aleatorio" ? t("no aleatório, todos têm a mesma chance") : t("no personalizado, o roteiro do modo decide")),
            "valor" => (string)$m["selecao"], "opcoes" => $formas, "min" => null, "max" => null],
        "ciclo" => ["aplica" => $tipo !== "aleatorio", "motivo" => $tipo === "aleatorio" ? t("o aleatório não tem ciclo") : "",
            "valor" => (int)$m["ciclo"], "opcoes" => [0, 1], "min" => null, "max" => null],
        "semanas" => ["aplica" => !$escala, "motivo" => $escala ? t("a escala segue o próprio período (os dias da escala)") : "",
            "valor" => (int)($m["semanas"] ?? 1), "opcoes" => [], "min" => 1, "max" => 8],
        "escala_dias" => ["aplica" => $escala, "motivo" => $escala ? "" : t("só na escala inteligente"),
            "valor" => $escala ? (int)$m["escala_dias"] : null, "opcoes" => [], "min" => 7, "max" => 730],
        "max_sem_uso" => ["aplica" => $tipo !== "aleatorio", "motivo" => $tipo === "aleatorio" ? t("o aleatório não olha a garantia de rodízio") : "",
            "valor" => (int)cfg("max_sem_uso"), "opcoes" => [], "min" => 0, "max" => 365],
    ];
}

// Os relógios que podem entrar no sorteio de um cenário: os ativos (Relógio ativo, que só vale na coleção), pelo nome
function simulador_relogios_ativos()
{
    $res = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY nome, id") as $r) {
        $res[(int)$r["id"]] = $r;
    }
    return $res;
}

// O valor de um parâmetro por extenso (o rótulo do cenário e as mudanças)
function simulador_valor_txt($k, $v)
{
    if ($k === "forma") {
        return formas_de_escolha_definicao()[$v]["titulo"] ?? (string)$v;
    }
    if ($k === "ciclo") {
        return (int)$v === 1 ? t("ligado") : t("desligado");
    }
    if ($k === "semanas") {
        return (int)$v === 1 ? t("1 semana") : t("{1} semanas", [1 => (int)$v]);
    }
    if ($k === "max_sem_uso") {
        return (int)$v === 0 ? t("desligada") : t("{1} dias", [1 => (int)$v]);
    }
    return t("{1} dias", [1 => (int)$v]);
}

// Um cenário do pedido, conferido: [o cenário (modo; os parâmetros que vieram; relogios, se veio), os erros]. $n: o número
// dele nas mensagens. Parâmetro vazio ou ausente: como está no modo; relogios ausente: os que estão no sorteio agora
function simulador_cenario_ler($c, $n)
{
    $pre = t("Cenário {1}: ", [1 => $n]);
    if (!is_array($c)) {
        return [null, [$pre . t("um cenário tem modo e, se quiser, forma, ciclo, semanas, escala_dias, max_sem_uso e relogios.")]];
    }
    $id = (int)($c["modo"] ?? 0);
    $m = $id > 0 ? linha("SELECT * FROM modo WHERE id = ?", [$id]) : null;
    if (!$m) {
        return [null, [$pre . t("modo: o id de um dos modos (modos[] desta resposta).")]];
    }
    $erros = [];
    $p = simulador_parametros($m);
    $tit = simulador_titulos();
    $cen = ["modo" => $id];
    foreach (SIMULADOR_PARAMETROS as $k) {
        $v = trim((string)($c[$k] ?? ""));
        if ($v === "") {
            continue;
        }
        if (!$p[$k]["aplica"]) {
            $erros[] = $pre . t("{1} não vale neste modo: {2}.", [1 => $tit[$k], 2 => $p[$k]["motivo"]]);
        } elseif ($k === "forma") {
            if (!in_array($v, $p[$k]["opcoes"], true)) {
                $erros[] = $pre . t("forma: uma de {1}.", [1 => implode(", ", $p[$k]["opcoes"])]);
            } else {
                $cen[$k] = $v;
            }
        } elseif ($k === "ciclo") {
            if (!in_array($v, ["0", "1"], true)) {
                $erros[] = $pre . t("ciclo: 1 (ligado) ou 0 (desligado).");
            } else {
                $cen[$k] = (int)$v;
            }
        } elseif (!ctype_digit($v) || (int)$v < $p[$k]["min"] || (int)$v > $p[$k]["max"]) {
            $erros[] = $pre . t("{1}: de {2} a {3}.", [1 => $tit[$k], 2 => $p[$k]["min"], 3 => $p[$k]["max"]]);
        } else {
            $cen[$k] = (int)$v;
        }
    }
    if (array_key_exists("relogios", $c)) {
        $lista = is_array($c["relogios"]) ? $c["relogios"] : explode(",", (string)$c["relogios"]);
        $ids = [];
        foreach ($lista as $x) {
            $x = trim((string)$x);
            if ($x === "") {
                continue;
            }
            if (!ctype_digit($x)) {
                $erros[] = $pre . t("relogios: os ids dos relógios que entram no sorteio, separados por vírgula.");
                $ids = [];
                break;
            }
            $ids[] = (int)$x;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $ativos = simulador_relogios_ativos();
        foreach ($ids as $rid) {
            if (!isset($ativos[$rid])) {
                $erros[] = $pre . t("o relógio {1} não pode entrar no sorteio: só os ativos (relogios[] desta resposta, com ativo verdadeiro).", [1 => $rid]);
            }
        }
        if (count($ids) === 0 && count($erros) === 0) {
            $erros[] = $pre . t("relogios: pelo menos um relógio no sorteio.");
        }
        $cen["relogios"] = $ids;
    }
    return [$cen, $erros];
}

// A variação de um parâmetro: cada cenário vira um para cada valor (a forma: cada forma que o modo aceita; o ciclo: desligado
// e ligado; os números: de, ate, passo). No cenário em que o parâmetro não vale, ele fica como está. [os cenários, os erros]
function simulador_variar($cens, $v)
{
    $par = trim((string)($v["parametro"] ?? ""));
    if ($par === "") {
        return [$cens, []];
    }
    if (!in_array($par, SIMULADOR_PARAMETROS, true)) {
        return [$cens, [t("variar[parametro]: um de {1}.", [1 => implode(", ", SIMULADOR_PARAMETROS)])]];
    }
    $tit = simulador_titulos();
    $res = [];
    $erros = [];
    foreach ($cens as $cen) {
        $p = simulador_parametros(linha("SELECT * FROM modo WHERE id = ?", [$cen["modo"]]))[$par];
        if (!$p["aplica"]) {
            $res[] = $cen;
            continue;
        }
        $valores = $p["opcoes"];
        if ($p["min"] !== null) {
            $de = trim((string)($v["de"] ?? ""));
            $ate = trim((string)($v["ate"] ?? ""));
            $passo = trim((string)($v["passo"] ?? "1"));
            if (!ctype_digit($de) || !ctype_digit($ate) || !ctype_digit($passo) || (int)$passo < 1 || (int)$de < $p["min"] || (int)$ate > $p["max"] || (int)$de > (int)$ate) {
                $erros[] = t("Variar {1}: de e até de {2} a {3} (de antes de até) e o passo de 1 em diante.", [1 => $tit[$par], 2 => $p["min"], 3 => $p["max"]]);
                return [$cens, $erros];
            }
            $valores = range((int)$de, (int)$ate, (int)$passo);
        }
        foreach ($valores as $x) {
            $res[] = array_merge($cen, [$par => $x]);
        }
    }
    return [$res, $erros];
}

// O rótulo de um cenário: o modo e o que muda nele (o que fica como está não aparece)
function simulador_rotulo($cen)
{
    $m = linha("SELECT * FROM modo WHERE id = ?", [$cen["modo"]]);
    $p = simulador_parametros($m);
    $partes = [$m["nome"]];
    $tit = ["forma" => "", "ciclo" => t("ciclo "), "semanas" => "", "escala_dias" => t("escala de "), "max_sem_uso" => t("garantia ")];
    foreach (SIMULADOR_PARAMETROS as $k) {
        if (isset($cen[$k]) && $cen[$k] !== $p[$k]["valor"]) {
            $partes[] = $tit[$k] . simulador_valor_txt($k, $cen[$k]);
        }
    }
    if (isset($cen["relogios"])) {
        [$saem, $entram] = simulador_relogios_mudam($cen["relogios"]);
        if (count($saem) > 0) {
            $partes[] = count($saem) <= 3 ? t("sem {1}", [1 => implode(", ", $saem)]) : t("sem {1} relógios", [1 => count($saem)]);
        }
        if (count($entram) > 0) {
            $partes[] = count($entram) <= 3 ? t("com {1}", [1 => implode(", ", $entram)]) : t("com mais {1} relógios", [1 => count($entram)]);
        }
    }
    return implode(" · ", $partes);
}

// Os relógios que saem do sorteio e os que entram, pela lista do cenário: [os nomes dos que saem, os dos que entram]
function simulador_relogios_mudam($ids)
{
    $saem = [];
    $entram = [];
    foreach (simulador_relogios_ativos() as $rid => $r) {
        $agora = relogio_no_sorteio($r);
        $quer = in_array($rid, $ids, true);
        if ($agora && !$quer) {
            $saem[] = $r["nome"];
        } elseif (!$agora && $quer) {
            $entram[] = $r["nome"];
        }
    }
    return [$saem, $entram];
}

// O que o Aplicar muda, por extenso, na ordem em que muda (a resposta da simulação e a confirmação da tela)
function simulador_mudancas($cen, $de)
{
    $m = linha("SELECT * FROM modo WHERE id = ?", [$cen["modo"]]);
    $p = simulador_parametros($m);
    $tit = simulador_titulos();
    $res = [];
    if (isset($cen["relogios"])) {
        [$saem, $entram] = simulador_relogios_mudam($cen["relogios"]);
        if (count($saem) > 0) {
            $res[] = t("Saem do sorteio (continuam ativos, e à mão continuam valendo): {1}.", [1 => implode(", ", $saem)]);
        }
        if (count($entram) > 0) {
            $res[] = t("Entram no sorteio: {1}.", [1 => implode(", ", $entram)]);
        }
    }
    foreach (SIMULADOR_PARAMETROS as $k) {
        if (isset($cen[$k]) && $cen[$k] !== $p[$k]["valor"]) {
            $res[] = $k === "max_sem_uso"
                ? t("{1}: {2} → {3} (vale para todos os modos).", [1 => $tit[$k], 2 => simulador_valor_txt($k, $p[$k]["valor"]), 3 => simulador_valor_txt($k, $cen[$k])])
                : t("{1} do modo {2}: {3} → {4}.", [1 => $tit[$k], 2 => $m["nome"], 3 => simulador_valor_txt($k, $p[$k]["valor"]), 4 => simulador_valor_txt($k, $cen[$k])]);
        }
    }
    if (modo_ativo() !== (int)$m["id"]) {
        $atual = linha("SELECT nome FROM modo WHERE id = ?", [modo_ativo()]);
        $res[] = t("O modo {1} passa a ser o ativo (hoje: {2}).", [1 => $m["nome"], 2 => $atual ? $atual["nome"] : t("nenhum")]);
    }
    $res[] = t("O plano: os dias sorteados de {1} em diante são trocados pelos simulados; os escolhidos à mão e os livres ficam.", [1 => data_txt(strtotime($de))]);
    return $res;
}

// Os passos de um cenário (dentro da transação de quem chama): os relógios do sorteio, o modo, a garantia, o modo ativo e o
// plano sorteado de novo do primeiro dia do período em diante. Devolve [os erros, o último dia que o plano tem para mostrar]
function simulador_passos($cen, $de, $ate)
{
    $erros = [];
    $hoje = hoje_da_colecao();
    if (isset($cen["relogios"])) {
        foreach (simulador_relogios_ativos() as $rid => $r) {
            $quer = in_array($rid, $cen["relogios"], true);
            if (relogio_no_sorteio($r) !== $quer) {
                $res = op_relogio("salvar", ["id" => $rid, "sorteio" => $quer ? "1" : "0"]);
                $erros = array_merge($erros, $res["ok"] ? [] : $res["erros"]);
            }
        }
    }
    $m = linha("SELECT * FROM modo WHERE id = ?", [$cen["modo"]]);
    $p = simulador_parametros($m);
    $dados = ["id" => (int)$m["id"]];
    foreach (["forma" => "selecao", "ciclo" => "ciclo", "semanas" => "semanas", "escala_dias" => "escala_dias"] as $k => $campo) {
        if (isset($cen[$k]) && $cen[$k] !== $p[$k]["valor"]) {
            $dados[$campo] = (string)$cen[$k];
        }
    }
    if (count($erros) === 0 && count($dados) > 1) {
        $res = op_modos("salvar", $dados);
        $erros = array_merge($erros, $res["ok"] ? [] : $res["erros"]);
    }
    if (count($erros) === 0 && isset($cen["max_sem_uso"]) && $cen["max_sem_uso"] !== $p["max_sem_uso"]["valor"]) {
        $res = op_config("salvar", ["max_sem_uso" => (string)$cen["max_sem_uso"]]);
        $erros = array_merge($erros, $res["ok"] ? [] : $res["erros"]);
    }
    if (count($erros) > 0) {
        return [$erros, $ate];
    }
    if (modo_ativo() !== (int)$m["id"]) {
        modo_ativar((int)$m["id"]);
    }
    // o Sortear de novo do primeiro dia do período em diante: os escolhidos à mão e os livres ficam
    $m = linha("SELECT * FROM modo WHERE id = ?", [$cen["modo"]]);
    sql("DELETE FROM plano WHERE data >= ? AND origem <> 'manual'", [$de]);
    if ($m["escala_dias"] !== null) {
        gerar_escala(new DateTimeImmutable($de), null, true);
        garantir_plano($hoje);
        $fim = (string)cfg("escala_fim");
        return [[], $fim !== "" && substr($fim, 0, 10) < $ate ? substr($fim, 0, 10) : $ate];
    }
    $horizonte = horizonte_do_modo($m, $hoje)->format("Y-m-d");
    garantir_plano($hoje, new DateTimeImmutable($horizonte > $ate ? $horizonte : $ate));
    return [[], $ate];
}

// O plano de um período como está agora: data => o id do relógio, "livre" ou "" (sem nada)
function simulador_plano_mapa($de, $ate)
{
    $mapa = [];
    foreach (linhas("SELECT data, relogio_id FROM plano WHERE data >= ? AND data <= ?", [$de, $ate]) as $x) {
        $mapa[substr((string)$x["data"], 0, 10)] = (int)$x["relogio_id"];
    }
    foreach (linhas("SELECT data FROM plano_livre WHERE data >= ? AND data <= ?", [$de, $ate]) as $x) {
        $mapa[substr((string)$x["data"], 0, 10)] = "livre";
    }
    return $mapa;
}

// O resultado de um cenário, lido do plano (dentro da transação, depois dos passos): os dias do período, o resumo, cada relógio
// e a impressão digital. $antes: o plano de agora (simulador_plano_mapa), para dizer o que muda; $fim: o último dia mostrado
function simulador_resultado($de, $ate, $fim, $antes)
{
    $hoje = hoje_da_colecao()->format("Y-m-d");
    $ativos = simulador_relogios_ativos();
    $sorteio = [];
    foreach ($ativos as $rid => $r) {
        if (relogio_no_sorteio($r)) {
            $sorteio[$rid] = $r["nome"];
        }
    }
    $plano = [];
    foreach (linhas("SELECT p.data, p.relogio_id, p.origem, p.motivo, r.nome FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data >= ? AND p.data <= ?", [$hoje, $fim]) as $x) {
        $plano[substr((string)$x["data"], 0, 10)] = $x;
    }
    $livres = [];
    foreach (linhas("SELECT data FROM plano_livre WHERE data >= ? AND data <= ?", [$de, $fim]) as $x) {
        $livres[substr((string)$x["data"], 0, 10)] = true;
    }
    // a última vez de cada relógio no pulso, até agora (o uso de verdade); depois, os dias do plano de hoje até o período
    $ultimo = [];
    $pulso = lancamento_tipos()["pulso"] ?? null;
    if ($pulso) {
        foreach (linhas("SELECT relogio_id, MAX(inicio) AS ult FROM lancamento WHERE tipo_id = ? AND inicio <= ? GROUP BY relogio_id", [(int)$pulso["id"], date("Y-m-d H:i:s")]) as $x) {
            $ultimo[(int)$x["relogio_id"]] = substr((string)$x["ult"], 0, 10);
        }
    }
    foreach ($plano as $data => $x) {
        if ($data < $de) {
            $ultimo[(int)$x["relogio_id"]] = $data;
        }
    }
    $dias = [];
    $conta = [];
    $primeiro = [];
    $maior_intervalo = [];
    $seq = ["relogio_id" => null, "relogio" => "", "dias" => 0];
    $seq_atual = [null, 0];
    $trocas = 0;
    $mudam = 0;
    $anterior = isset($plano[date("Y-m-d", strtotime($de . " -1 day"))]) ? (int)$plano[date("Y-m-d", strtotime($de . " -1 day"))]["relogio_id"] : null;
    $impressao = [];
    $n_dias = 0;
    $com_relogio = 0;
    $n_livres = 0;
    $vazios = 0;
    $manuais = 0;
    $curto = [1 => t("seg"), 2 => t("ter"), 3 => t("qua"), 4 => t("qui"), 5 => t("sex"), 6 => t("sáb"), 7 => t("dom")];
    for ($t = strtotime($de); date("Y-m-d", $t) <= $fim; $t = strtotime(date("Y-m-d", $t) . " +1 day")) {
        $data = date("Y-m-d", $t);
        $semana = ["dia_semana" => (int)date("N", $t), "dia_semana_curto" => $curto[(int)date("N", $t)]];
        $n_dias++;
        $x = $plano[$data] ?? null;
        if ($x !== null) {
            $rid = (int)$x["relogio_id"];
            $item = ["data" => $data, "relogio_id" => $rid, "relogio" => $x["nome"], "origem" => $x["origem"] === "manual" ? "manual" : "sorteio", "motivo" => (string)($x["motivo"] ?? "")];
            $com_relogio++;
            $manuais += $item["origem"] === "manual" ? 1 : 0;
            $conta[$rid] = ($conta[$rid] ?? 0) + 1;
            $primeiro[$rid] = $primeiro[$rid] ?? $data;
            if (isset($ultimo[$rid])) {
                $gap = (int)round((strtotime($data) - strtotime($ultimo[$rid])) / 86400) - 1;
                $maior_intervalo[$rid] = max($maior_intervalo[$rid] ?? 0, $gap);
            }
            $ultimo[$rid] = $data;
            $trocas += $anterior !== null && $anterior !== $rid ? 1 : 0;
            $anterior = $rid;
            $seq_atual = $seq_atual[0] === $rid ? [$rid, $seq_atual[1] + 1] : [$rid, 1];
            if ($seq_atual[1] > $seq["dias"]) {
                $seq = ["relogio_id" => $rid, "relogio" => $x["nome"], "dias" => $seq_atual[1]];
            }
            $marca = $rid;
        } else {
            $livre = isset($livres[$data]);
            $item = ["data" => $data, "relogio_id" => null, "relogio" => "", "origem" => $livre ? "livre" : "vazio", "motivo" => ""];
            $n_livres += $livre ? 1 : 0;
            $vazios += $livre ? 0 : 1;
            $seq_atual = [null, 0];
            $marca = $livre ? "livre" : "";
        }
        $item = array_merge(["data" => $data], $semana, $item);
        $item["muda"] = ($antes[$data] ?? "") !== $marca;
        $mudam += $item["muda"] ? 1 : 0;
        $impressao[] = $data . ":" . $marca;
        $dias[] = $item;
    }
    // cada relógio do sorteio (e os que aparecem no período por outro caminho: à mão): os dias, a parte do período, o primeiro
    // dia, o maior tempo parado (entre dois dias, contando da última vez antes do período) e quanto está parado no fim
    $por_relogio = [];
    $ids = array_values(array_unique(array_merge(array_keys($sorteio), array_keys($conta))));
    $fim_t = strtotime($fim);
    foreach ($ids as $rid) {
        $nome = $sorteio[$rid] ?? ($ativos[$rid]["nome"] ?? (string)valor("SELECT nome FROM relogio WHERE id = ?", [$rid]));
        $parado_fim = isset($ultimo[$rid]) ? (int)round(($fim_t - strtotime($ultimo[$rid])) / 86400) : null;
        $maior = max($maior_intervalo[$rid] ?? 0, $parado_fim ?? 0);
        $por_relogio[] = ["relogio_id" => $rid, "relogio" => $nome, "no_sorteio" => isset($sorteio[$rid]), "dias" => $conta[$rid] ?? 0,
            "porcentagem" => $n_dias > 0 ? round(100 * ($conta[$rid] ?? 0) / $n_dias, 1) : 0, "primeiro" => $primeiro[$rid] ?? null,
            "maior_intervalo" => $parado_fim === null && !isset($conta[$rid]) ? null : $maior, "parado_no_fim" => $parado_fim, "nunca_usado" => !isset($ultimo[$rid])];
    }
    usort($por_relogio, function ($a, $b) { return [$b["dias"], $a["relogio"]] <=> [$a["dias"], $b["relogio"]]; });
    $dias_sorteio = array_map(function ($rid) use ($conta) { return $conta[$rid] ?? 0; }, array_keys($sorteio));
    $sem_dias = [];
    foreach ($sorteio as $rid => $nome) {
        if (!isset($conta[$rid])) {
            $sem_dias[] = ["relogio_id" => $rid, "relogio" => $nome];
        }
    }
    // o mais tempo parado, entre os do sorteio que têm a conta (o que nunca foi ao pulso e não tem dia está em sem_dias)
    $pior = null;
    foreach ($por_relogio as $x) {
        if ($x["no_sorteio"] && $x["maior_intervalo"] !== null && ($pior === null || $x["maior_intervalo"] > $pior["maior_intervalo"])) {
            $pior = $x;
        }
    }
    $resumo = ["dias" => $n_dias, "com_relogio" => $com_relogio, "livres" => $n_livres, "vazios" => $vazios, "manuais" => $manuais,
        "relogios_no_sorteio" => count($sorteio), "relogios_usados" => count($conta), "sem_dias" => $sem_dias,
        "menos_dias" => count($dias_sorteio) > 0 ? min($dias_sorteio) : 0, "mais_dias" => count($dias_sorteio) > 0 ? max($dias_sorteio) : 0,
        "maior_intervalo" => $pior === null ? null : ["relogio_id" => $pior["relogio_id"], "relogio" => $pior["relogio"], "dias" => $pior["maior_intervalo"], "nunca_usado" => $pior["nunca_usado"]],
        "maior_sequencia" => $seq, "trocas" => $trocas, "dias_que_mudam" => $mudam];
    return ["dias" => $dias, "resumo" => $resumo, "por_relogio" => $por_relogio, "impressao" => sha1(implode("|", $impressao))];
}

// Depois de desfazer: o que ficou guardado na memória do pedido (a configuração, o modo ativo, as contas) volta a ler o banco
function simulador_limpar()
{
    cfg_cru(null);
    modo_ativo(true);
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
}

// Um cenário: simula (sem $impressao: desfeito no fim) ou aplica (com $impressao: confirma só se o plano que sair for o
// simulado). [os erros, o resultado (null com erro)]
function simulador_rodar($cen, $de, $ate, $semente, $antes, $impressao = null)
{
    $GLOBALS["SIMULADOR_SEMENTE"] = (int)$semente;
    mt_srand((int)$semente);
    $passos = function () use ($cen, $de, $ate, $antes, $impressao) {
        [$erros, $fim] = simulador_passos($cen, $de, $ate);
        if (count($erros) > 0) {
            throw new SimuladorParou($erros);
        }
        $res = simulador_resultado($de, $ate, $fim, $antes);
        $res["ate_mostrado"] = $fim;
        if ($impressao !== null && $res["impressao"] !== $impressao) {
            throw new SimuladorParou([t("O plano não sai mais igual ao simulado (alguma coisa mudou desde a simulação: um uso, um dia escolhido à mão, um relógio): nada foi gravado. Simule de novo e aplique.")]);
        }
        return $res;
    };
    try {
        if ($impressao !== null) {
            return [[], banco_atomico($passos)];
        }
        banco_inicio();
        try {
            return [[], $passos()];
        } finally {
            banco_desfaz();
            simulador_limpar();
        }
    } catch (SimuladorParou $e) {
        return [$e->erros, null];
    } catch (Throwable $e) {
        return [[t("O cenário parou com um erro: {1}", [1 => $e->getMessage()])], null];
    } finally {
        unset($GLOBALS["SIMULADOR_SEMENTE"]);
        if ($impressao !== null) {
            simulador_limpar();
        }
    }
}

// O período do pedido, conferido: [de, ate, os erros]. Padrão: de amanhã, 4 semanas
function simulador_periodo($de, $ate)
{
    $amanha = hoje_da_colecao()->modify("+1 day");
    $de = trim((string)$de) === "" ? $amanha->format("Y-m-d") : trim((string)$de);
    $ate = trim((string)$ate) === "" ? (new DateTimeImmutable($de))->modify("+27 days")->format("Y-m-d") : trim((string)$ate);
    $erros = [];
    $de_d = DateTimeImmutable::createFromFormat("!Y-m-d", $de);
    $ate_d = DateTimeImmutable::createFromFormat("!Y-m-d", $ate);
    if (!$de_d || $de_d->format("Y-m-d") !== $de || !$ate_d || $ate_d->format("Y-m-d") !== $ate) {
        $erros[] = t("de e ate: datas AAAA-MM-DD.");
    } elseif ($de < $amanha->format("Y-m-d")) {
        $erros[] = t("de: a partir de amanhã ({1}); hoje fica como está.", [1 => $amanha->format("Y-m-d")]);
    } elseif ($ate < $de) {
        $erros[] = t("ate: no mesmo dia de de ou depois.");
    } elseif ((int)$de_d->diff($ate_d)->days + 1 > SIMULADOR_MAX_DIAS) {
        $erros[] = t("O período tem no máximo {1} dias.", [1 => SIMULADOR_MAX_DIAS]);
    }
    return [$de, $ate, $erros];
}

// A simulação: os cenários (a lista do pedido, já com a variação), o período e a semente (vazia: uma nova). Devolve os
// erros do pedido, ou o período, a semente, cada cenário (o rótulo, o que entrou, as mudanças do Aplicar e o resultado, ou os
// erros dele) e a comparação (cada medida, o valor de cada cenário e quais são os melhores nela)
function simulador_simular($brutos, $de, $ate, $semente, $variar)
{
    if (!rodizio_ativo()) {
        return ["erros" => [t("O rodízio está desligado (Rodízio › Ajustes do rodízio): não há sorteio para simular.")]];
    }
    [$de, $ate, $erros] = simulador_periodo($de, $ate);
    $semente = trim((string)$semente);
    if ($semente !== "" && (!ctype_digit($semente) || (int)$semente < 1 || (int)$semente > 2147483647)) {
        $erros[] = t("semente: um número de 1 a 2147483647 (vazio: uma nova).");
    }
    if (!is_array($brutos) || count($brutos) === 0) {
        $erros[] = t("cenarios: pelo menos um cenário (cada um com o modo).");
    }
    $cens = [];
    foreach (is_array($brutos) ? array_values($brutos) : [] as $i => $c) {
        [$cen, $e] = simulador_cenario_ler($c, $i + 1);
        $erros = array_merge($erros, $e);
        $cens[] = $cen;
    }
    if (count($erros) === 0) {
        [$cens, $e] = simulador_variar($cens, is_array($variar) ? $variar : []);
        $erros = array_merge($erros, $e);
        if (count($cens) > SIMULADOR_MAX_CENARIOS) {
            $erros[] = t("São {1} cenários: no máximo {2} numa simulação (menos cenários, ou uma variação mais curta).", [1 => count($cens), 2 => SIMULADOR_MAX_CENARIOS]);
        }
    }
    if (count($erros) > 0) {
        return ["erros" => $erros];
    }
    @set_time_limit(0);
    $semente = $semente === "" ? random_int(1, 2147483647) : (int)$semente;
    $antes = simulador_plano_mapa($de, $ate);
    $saida = [];
    foreach ($cens as $i => $cen) {
        $item = ["indice" => $i, "rotulo" => simulador_rotulo($cen), "cenario" => $cen, "mudancas" => simulador_mudancas($cen, $de)];
        [$e, $res] = simulador_rodar($cen, $de, $ate, $semente, $antes);
        $saida[] = array_merge($item, ["erros" => $e, "resultado" => $res]);
    }
    return ["erros" => [], "de" => $de, "ate" => $ate, "semente" => $semente, "cenarios" => $saida, "comparacao" => simulador_comparacao($saida)];
}

// A comparação: as medidas, na ordem da tela, cada uma com o valor de cada cenário (null no cenário com erro) e os melhores
// (os índices; vazio quando a medida é questão de gosto, ou todos empatam)
function simulador_comparacao($cens)
{
    $medidas = [
        ["relogios_usados", t("Relógios que vão ao pulso"), "maior", function ($r) { return $r["resumo"]["relogios_usados"]; }],
        ["sem_dias", t("Relógios do sorteio sem nenhum dia"), "menor", function ($r) { return count($r["resumo"]["sem_dias"]); }],
        ["maior_intervalo", t("Maior tempo parado de um relógio do sorteio (dias)"), "menor", function ($r) { return $r["resumo"]["maior_intervalo"]["dias"] ?? null; }],
        ["diferenca", t("Diferença entre o mais e o menos usado (dias)"), "menor", function ($r) { return $r["resumo"]["mais_dias"] - $r["resumo"]["menos_dias"]; }],
        ["vazios", t("Dias sem relógio (sem candidato)"), "menor", function ($r) { return $r["resumo"]["vazios"]; }],
        ["maior_sequencia", t("Mais dias seguidos do mesmo relógio"), "", function ($r) { return $r["resumo"]["maior_sequencia"]["dias"]; }],
        ["trocas", t("Trocas de relógio no período"), "", function ($r) { return $r["resumo"]["trocas"]; }],
        ["dias_que_mudam", t("Dias que mudam em relação ao plano de agora"), "", function ($r) { return $r["resumo"]["dias_que_mudam"]; }],
    ];
    $res = [];
    foreach ($medidas as [$chave, $titulo, $sentido, $f]) {
        $valores = array_map(function ($c) use ($f) { return $c["resultado"] === null ? null : $f($c["resultado"]); }, $cens);
        $validos = array_filter($valores, function ($v) { return $v !== null; });
        $melhores = [];
        if ($sentido !== "" && count(array_unique($validos)) > 1) {
            $alvo = $sentido === "maior" ? max($validos) : min($validos);
            $melhores = array_keys(array_filter($valores, function ($v) use ($alvo) { return $v !== null && $v === $alvo; }));
        }
        $res[] = ["chave" => $chave, "titulo" => $titulo, "melhor_e" => $sentido, "valores" => $valores, "melhores" => array_values($melhores)];
    }
    return $res;
}

// O que a página Simulador oferece para escolher: os modos (com o que cada um deixa mudar), as formas, os relógios ativos (os
// que estão no sorteio agora marcados), o período padrão e os limites, e os parâmetros que a variação percorre
function simulador_opcoes()
{
    $modos = [];
    $ativo = modo_ativo();
    foreach (linhas("SELECT * FROM modo ORDER BY ordem, id") as $m) {
        $modos[] = ["id" => (int)$m["id"], "nome" => $m["nome"], "tipo" => $m["tipo"], "tipo_texto" => t(MODO_TIPOS[$m["tipo"]] ?? $m["tipo"]),
            "ativo" => (int)$m["id"] === $ativo, "parametros" => simulador_parametros($m)];
    }
    $formas = [];
    foreach (formas_de_escolha_definicao() as $k => $f) {
        $formas[] = ["chave" => $k, "titulo" => $f["titulo"], "explicacao" => $f["explicacao"]];
    }
    $relogios = [];
    foreach (simulador_relogios_ativos() as $rid => $r) {
        $relogios[] = ["id" => $rid, "nome" => $r["nome"], "no_sorteio" => relogio_no_sorteio($r)];
    }
    $amanha = hoje_da_colecao()->modify("+1 day");
    $titulos = simulador_titulos();
    $parametros = [];
    foreach (SIMULADOR_PARAMETROS as $k) {
        $parametros[] = ["chave" => $k, "titulo" => $titulos[$k], "numerico" => !in_array($k, ["forma", "ciclo"], true)];
    }
    return ["rodizio_ativo" => rodizio_ativo(), "modos" => $modos, "formas" => $formas, "relogios" => $relogios, "parametros" => $parametros,
        "periodo" => ["de" => $amanha->format("Y-m-d"), "ate" => $amanha->modify("+27 days")->format("Y-m-d"), "min" => $amanha->format("Y-m-d"), "max_dias" => SIMULADOR_MAX_DIAS],
        "max_cenarios" => SIMULADOR_MAX_CENARIOS];
}

// O Aplicar: o cenário como foi simulado (modo, os parâmetros, relogios), o período, a semente e a impressão digital da
// simulação. Roda de novo e grava só se sair igual. Devolve a resposta das escritas (ok, mensagem, erros) e as mudanças
function simulador_aplicar($d)
{
    if (!rodizio_ativo()) {
        return resultado([t("O rodízio está desligado (Rodízio › Ajustes do rodízio): não há sorteio para aplicar.")], "");
    }
    [$de, $ate, $erros] = simulador_periodo($d["de"] ?? "", $d["ate"] ?? "");
    [$cen, $e] = simulador_cenario_ler($d, 1);
    $erros = array_merge($erros, $e);
    $semente = trim((string)($d["semente"] ?? ""));
    $impressao = trim((string)($d["impressao"] ?? ""));
    if (!ctype_digit($semente) || (int)$semente < 1 || (int)$semente > 2147483647) {
        $erros[] = t("semente: a da simulação (um número de 1 a 2147483647).");
    }
    if (preg_match("/^[0-9a-f]{40}$/", $impressao) !== 1) {
        $erros[] = t("impressao: a impressão digital do cenário na simulação (resultado.impressao).");
    }
    if (count($erros) > 0) {
        return resultado($erros, "");
    }
    $mudancas = simulador_mudancas($cen, $de);
    $rotulo = simulador_rotulo($cen);
    [$e, $res] = simulador_rodar($cen, $de, $ate, (int)$semente, simulador_plano_mapa($de, $ate), $impressao);
    if (count($e) > 0) {
        return resultado($e, "");
    }
    return resultado([], t("Simulação aplicada: {1}. {2}", [1 => $rotulo, 2 => implode(" ", $mudancas)]), ["mudancas" => $mudancas, "resumo" => $res["resumo"]]);
}
