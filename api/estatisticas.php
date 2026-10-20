<?php
// recurso=estatisticas: o uso da coleção num período (de, ate; padrão: os últimos 90 dias): o resumo, cada relógio (dias de
// uso, horas no pulso, a parte de cada um, o custo por uso, dias sem uso e a tendência), o uso por dia da semana e por mês,
// e a distribuição da coleção (por grupo, marca, ano da compra, situação e cada campo de lista). Tudo pronto para a tela: as
// porcentagens e as alturas das barras (0 a 100, comparadas com a maior) vêm daqui.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$agora_e = time();
$hoje_e = hoje_da_colecao();
$data_ok = function ($t) {
    $x = DateTimeImmutable::createFromFormat("!Y-m-d", (string)$t, fuso_colecao_tz());
    return $x && $x->format("Y-m-d") === (string)$t ? $x : null;
};
$de_e = ($_REQUEST["de"] ?? "") !== "" ? $data_ok($_REQUEST["de"]) : $hoje_e->modify("-89 days");
$ate_e = ($_REQUEST["ate"] ?? "") !== "" ? $data_ok($_REQUEST["ate"]) : $hoje_e;
if (($_REQUEST["de"] ?? "") === "tudo") {
    // desde o primeiro lançamento no pulso
    $primeiro = valor("SELECT MIN(l.inicio) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE t.identificador IN ('pulso', 'pulso_antigo')");
    $de_e = $primeiro !== null ? new DateTimeImmutable(substr((string)$primeiro, 0, 10), fuso_colecao_tz()) : $hoje_e;
}
if ($de_e === null || $ate_e === null) {
    $codigo = 400;
    $saida = ["erro" => t("de e ate: datas AAAA-MM-DD (de também aceita tudo: desde o primeiro uso)")];
    return;
}
if ($ate_e > $hoje_e) {
    $ate_e = $hoje_e;
}
if ($de_e > $ate_e) {
    $codigo = 400;
    $saida = ["erro" => t("de depois de ate (o período vai até hoje)")];
    return;
}
if ((int)$de_e->diff($ate_e)->days > 3660) {
    $de_e = $ate_e->modify("-3660 days");
}
$dias_e = (int)$de_e->diff($ate_e)->days + 1;
$ini_e = $de_e->getTimestamp();
$fim_e = min($agora_e, $ate_e->modify("+1 day")->getTimestamp());

// o tempo no pulso de cada relógio em cada dia do período (as sessões partidas na meia-noite); as marcações antigas no pulso
// (sem duração) contam o dia como dia de uso, sem horas
$seg = [];
$dias_uso = [];
foreach (linhas("SELECT l.relogio_id, l.inicio, l.fim, t.identificador FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
    WHERE t.identificador IN ('pulso', 'pulso_antigo') AND l.inicio < ? AND (l.fim IS NULL OR l.fim > ? OR t.identificador = 'pulso_antigo') AND l.inicio >= ?",
    [date("Y-m-d H:i:s", $fim_e), date("Y-m-d H:i:s", $ini_e), date("Y-m-d H:i:s", $ini_e - 400 * 86400)]) as $l) {
    $rid = (int)$l["relogio_id"];
    $a = strtotime($l["inicio"]);
    if ($l["identificador"] === "pulso_antigo") {
        if ($a >= $ini_e && $a < $fim_e) {
            $dias_uso[$rid][dia_da_colecao($a)] = true;
        }
        continue;
    }
    $a = max($a, $ini_e);
    $b = min($l["fim"] === null ? $agora_e : strtotime($l["fim"]), $fim_e);
    while ($b > $a) {
        $meia_noite = meia_noite_seguinte($a);
        $ate_aqui = min($b, $meia_noite);
        $dia = dia_da_colecao($a);
        $seg[$rid][$dia] = ($seg[$rid][$dia] ?? 0) + ($ate_aqui - $a);
        $dias_uso[$rid][$dia] = true;
        $a = $ate_aqui;
    }
}
$meio_e = $de_e->modify("+" . intdiv($dias_e, 2) . " days")->format("Y-m-d");

$n_e = nos_todos();
$rels_e = [];
$tot_seg = 0;
foreach ($seg as $rid => $por_dia) {
    $tot_seg += array_sum($por_dia);
}
$dias_com = [];
foreach ($dias_uso as $rid => $ds) {
    foreach ($ds as $dia => $x) {
        $dias_com[$dia] = true;
    }
}
$todos_r = linhas("SELECT * FROM relogio ORDER BY nome");
$valores_e = [];
foreach ($todos_r as $r) {
    $valores_e[(int)$r["id"]] = valores_do_relogio((int)$r["id"]);
}
foreach ($todos_r as $r) {
    $id = (int)$r["id"];
    $s = array_sum($seg[$id] ?? []);
    // os da coleção sempre; os de fora dela (vendidos, arquivados, desejados), só se usaram no período
    if (!da_colecao($r) && $s === 0 && count($dias_uso[$id] ?? []) === 0) {
        continue;
    }
    $v = $valores_e[$id];
    $s1 = 0;
    $s2 = 0;
    foreach ($seg[$id] ?? [] as $dia => $x) {
        if ($dia < $meio_e) {
            $s1 += $x;
        } else {
            $s2 += $x;
        }
    }
    // a tendência: as horas da segunda metade do período comparadas com as da primeira (uma diferença de até 20%, ou de menos de
    // uma hora, é estável)
    if ($s1 + $s2 < 3600 || $dias_e < 14) {
        $tend = ["sem_dados", t("poucos dados")];
    } elseif (abs($s2 - $s1) < 3600 || abs($s2 - $s1) <= 0.2 * max($s1, $s2)) {
        $tend = ["estavel", t("estável")];
    } else {
        $tend = $s2 > $s1 ? ["sobe", t("usando mais")] : ["desce", t("usando menos")];
    }
    // a última vez no pulso: no pulso agora, hoje; senão, o fim da última sessão (ou a última marcação antiga)
    $no_pulso = (int)valor("SELECT COUNT(*) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso' AND l.fim IS NULL", [$id]) > 0;
    $ultimo = $no_pulso ? date("Y-m-d H:i:s", $agora_e) : valor("SELECT MAX(l.fim) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.relogio_id = ? AND t.identificador = 'pulso'", [$id]);
    $antigo = valor("SELECT MAX(l.inicio) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso_antigo'", [$id]);
    $ultimo = max((string)$ultimo, (string)$antigo);
    $c = ["r" => $r, "momento" => $agora_e, "rastro" => [], "pilha" => [], "valores" => $v];
    $du = isset(formulas_todas()["dias_de_uso"]) ? variavel_valor("dias_de_uso", $c) : null;
    $c = ["r" => $r, "momento" => $agora_e, "rastro" => [], "pilha" => [], "valores" => $v];
    $cpu = isset(formulas_todas()["custo_por_uso"]) ? variavel_valor("custo_por_uso", $c) : null;
    $rels_e[] = ["id" => $id, "nome" => $r["nome"], "tipo" => isset($n_e[(int)$r["no_id"]]) ? $n_e[(int)$r["no_id"]]["nome"] : t("sem grupo"),
        "posse" => posse_de($r), "posse_texto" => t(POSSES[posse_de($r)]),
        "usos" => count($dias_uso[$id] ?? []), "horas" => round($s / 3600, 1),
        "porcentagem" => $tot_seg > 0 ? round($s * 100 / $tot_seg, 1) : 0.0,
        "ultimo_uso" => $ultimo !== "" ? substr($ultimo, 0, 10) : null,
        "dias_sem_uso" => $ultimo !== "" ? max(0, (int)floor(($hoje_e->getTimestamp() - strtotime(substr($ultimo, 0, 10))) / 86400)) : null,
        "valor_compra" => isset($v["valor_compra"]) && $v["valor_compra"] !== "" ? (float)$v["valor_compra"] : null,
        "dias_de_uso" => is_numeric($du) ? (int)round((float)$du) : null,
        "custo_por_uso" => is_numeric($cpu) ? round((float)$cpu, 2) : null,
        "tendencia" => $tend[0], "tendencia_texto" => $tend[1],
        "horas_primeira_metade" => round($s1 / 3600, 1), "horas_segunda_metade" => round($s2 / 3600, 1)];
}
// do mais usado (horas, depois dias) ao menos usado; a barra de cada um é comparada com o mais usado
usort($rels_e, function ($a, $b) {
    return [$b["horas"], $b["usos"], $a["nome"]] <=> [$a["horas"], $a["usos"], $b["nome"]];
});
$max_h = count($rels_e) > 0 ? max(array_column($rels_e, "horas")) : 0;
foreach ($rels_e as &$x) {
    $x["barra"] = $max_h > 0 ? round($x["horas"] * 100 / $max_h, 1) : 0.0;
}
unset($x);

// por dia da semana e por mês (as horas de todos), com a altura da barra comparada com a maior
$nomes_dia = [1 => t("segunda"), 2 => t("terça"), 3 => t("quarta"), 4 => t("quinta"), 5 => t("sexta"), 6 => t("sábado"), 7 => t("domingo")];
$meses = [t("jan"), t("fev"), t("mar"), t("abr"), t("mai"), t("jun"), t("jul"), t("ago"), t("set"), t("out"), t("nov"), t("dez")];
$sem = [];
foreach ($nomes_dia as $k => $nome) {
    $sem[$k] = ["dia" => $k, "nome" => $nome, "dias" => 0, "segundos" => 0, "dias_com_uso" => 0];
}
$mes = [];
for ($d_e = $de_e; $d_e <= $ate_e; $d_e = $d_e->modify("+1 day")) {
    $sem[(int)$d_e->format("N")]["dias"]++;
    $m = $d_e->format("Y-m");
    if (!isset($mes[$m])) {
        $mes[$m] = ["mes" => $m, "nome" => $meses[(int)$d_e->format("n") - 1] . "/" . $d_e->format("Y"), "nome_curto" => $meses[(int)$d_e->format("n") - 1] . "/" . $d_e->format("y"), "dias" => 0, "segundos" => 0, "dias_com_uso" => 0, "relogios" => []];
    }
    $mes[$m]["dias"]++;
    if (isset($dias_com[$d_e->format("Y-m-d")])) {
        $sem[(int)$d_e->format("N")]["dias_com_uso"]++;
        $mes[$m]["dias_com_uso"]++;
    }
}
foreach ($seg as $rid => $por_dia) {
    foreach ($por_dia as $dia => $s) {
        $sem[(int)date("N", strtotime($dia))]["segundos"] += $s;
        $mes[substr($dia, 0, 7)]["segundos"] += $s;
        $mes[substr($dia, 0, 7)]["relogios"][$rid] = true;
    }
}
foreach ($dias_uso as $rid => $ds) {
    foreach ($ds as $dia => $x) {
        if (isset($mes[substr($dia, 0, 7)])) {
            $mes[substr($dia, 0, 7)]["relogios"][$rid] = true;
        }
    }
}
$barras = function ($lista) {
    $maior = max(array_merge([0], array_column($lista, "segundos")));
    $total = array_sum(array_column($lista, "segundos"));
    return array_map(function ($x) use ($maior, $total) {
        $res = ["horas" => round($x["segundos"] / 3600, 1), "media_horas" => $x["dias"] > 0 ? round($x["segundos"] / 3600 / $x["dias"], 1) : 0.0,
            "porcentagem" => $total > 0 ? round($x["segundos"] * 100 / $total, 1) : 0.0, "barra" => $maior > 0 ? round($x["segundos"] * 100 / $maior, 1) : 0.0];
        unset($x["segundos"]);
        if (isset($x["relogios"])) {
            $x["relogios"] = count($x["relogios"]);
        }
        return array_merge($x, $res);
    }, array_values($lista));
};

// a distribuição da coleção (os relógios da coleção agora: na coleção e emprestados), com as horas de cada parte no período
$colecao = array_values(array_filter($todos_r, function ($r) { return da_colecao($r); }));
$distribuir = function ($titulo, $ident, $de_quem) use ($colecao, $seg) {
    $partes = [];
    foreach ($colecao as $r) {
        $k = $de_quem($r);
        $k = $k === null || $k === "" ? t("(sem)") : (string)$k;
        if (!isset($partes[$k])) {
            $partes[$k] = ["nome" => $k, "relogios" => 0, "segundos" => 0];
        }
        $partes[$k]["relogios"]++;
        $partes[$k]["segundos"] += array_sum($seg[(int)$r["id"]] ?? []);
    }
    uasort($partes, function ($a, $b) {
        return [$b["relogios"], $b["segundos"], $a["nome"]] <=> [$a["relogios"], $a["segundos"], $b["nome"]];
    });
    $n = count($colecao);
    $tot = array_sum(array_column($partes, "segundos"));
    return ["titulo" => $titulo, "identificador" => $ident, "itens" => array_values(array_map(function ($p) use ($n, $tot) {
        return ["nome" => $p["nome"], "relogios" => $p["relogios"], "porcentagem" => $n > 0 ? round($p["relogios"] * 100 / $n, 1) : 0.0,
            "horas" => round($p["segundos"] / 3600, 1), "porcentagem_horas" => $tot > 0 ? round($p["segundos"] * 100 / $tot, 1) : 0.0];
    }, $partes))];
};
$distr = [
    $distribuir(t("Grupo"), "grupo", function ($r) { return $r["no_id"] === null ? t("(na raiz)") : no_caminho($r["no_id"]); }),
    $distribuir(t("Marca"), "marca", function ($r) use ($valores_e) { return $valores_e[(int)$r["id"]]["marca"] ?? null; }),
    $distribuir(t("Ano da compra"), "ano_compra", function ($r) use ($valores_e) { $d = (string)($valores_e[(int)$r["id"]]["data_compra"] ?? ""); return $d !== "" ? substr($d, 0, 4) : null; }),
    $distribuir(t("Situação"), "posse", function ($r) { return t(POSSES[posse_de($r)]); }),
];
foreach (campos_todos() as $ident => $c) {
    if ($c["tipo"] === "lista" && (int)($c["so_desejado"] ?? 0) === 0) {
        $distr[] = $distribuir($c["nome"], $ident, function ($r) use ($valores_e, $ident) { return $valores_e[(int)$r["id"]][$ident] ?? null; });
    }
}

$usados = array_values(array_filter($rels_e, function ($x) { return $x["usos"] > 0; }));
$com_custo = array_values(array_filter(array_column($rels_e, "custo_por_uso"), function ($x) { return $x !== null; }));
$saida = ["de" => $de_e->format("Y-m-d"), "ate" => $ate_e->format("Y-m-d"), "hoje" => $hoje_e->format("Y-m-d"),
    "resumo" => ["dias" => $dias_e, "dias_com_relogio" => count($dias_com), "dias_sem_relogio" => $dias_e - count($dias_com),
        "horas" => round($tot_seg / 3600, 1), "horas_por_dia" => count($dias_com) > 0 ? round($tot_seg / 3600 / count($dias_com), 1) : 0.0,
        "relogios_na_colecao" => count($colecao), "relogios_usados" => count($usados),
        "nao_usados" => count(array_filter($rels_e, function ($x) { return $x["usos"] === 0 && in_array($x["posse"], ["colecao", "emprestado"], true); })),
        "mais_usado" => count($usados) > 0 ? ["id" => $usados[0]["id"], "nome" => $usados[0]["nome"], "horas" => $usados[0]["horas"], "usos" => $usados[0]["usos"]] : null,
        "menos_usado" => count($usados) > 1 ? ["id" => $usados[count($usados) - 1]["id"], "nome" => $usados[count($usados) - 1]["nome"], "horas" => $usados[count($usados) - 1]["horas"], "usos" => $usados[count($usados) - 1]["usos"]] : null,
        "valor_colecao" => round(array_sum(array_map(function ($r) use ($valores_e) { return (float)($valores_e[(int)$r["id"]]["valor_compra"] ?? 0); }, $colecao)), 2),
        "custo_por_uso_medio" => count($com_custo) > 0 ? round(array_sum($com_custo) / count($com_custo), 2) : null],
    "relogios" => $rels_e,
    "por_dia_semana" => $barras($sem),
    "por_mes" => $barras($mes),
    "distribuicao" => $distr];
