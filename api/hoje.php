<?php
// recurso=hoje: tudo o que a página Hoje mostra.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// tudo o que a página Hoje mostra: o dia, os avisos de hoje, os próximos dias, o modo de rodízio e a tabela dos relógios
$hoje = new DateTimeImmutable("today");
garantir_plano($hoje);
sessao_do_dia(time());
$agora = time();
$dia = plano_do_dia($hoje->format("Y-m-d"));
$n = nos_todos();
$modo = linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]);
$pendentes_h = [];
$avisos_h = [];
foreach (avisos_de_hoje() as $x) {
    $pendentes_h[(int)$x[0]["id"]] = true;
    $t = $x[1]["resolve"] !== null ? (lancamento_tipos()[$x[1]["resolve"]] ?? null) : null;
    // o botão: a sessão que resolve, aberta, se fecha (o Tirar do carregador); fechada, se abre; a leitura se informa
    $aberta_h = $t && $t["formato"] === "sessao" && (int)valor("SELECT COUNT(*) FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND fim IS NULL", [(int)$x[0]["id"], (int)$t["id"]]) > 0;
    $minus = $t ? (function_exists("mb_strtolower") ? mb_strtolower($t["nome"], "UTF-8") : strtolower($t["nome"])) : "";
    $avisos_h[] = ["relogio_id" => (int)$x[0]["id"], "relogio" => $x[0]["nome"], "identificador" => $x[1]["identificador"], "nome" => $x[1]["nome"], "texto" => $x[1]["texto"],
        "estado" => $x[1]["estado"], "resolve" => $t ? ["identificador" => $t["identificador"], "nome" => $t["nome"], "formato" => $t["formato"], "unidade" => $t["unidade"],
            "acao" => $t["formato"] === "sessao" ? ($aberta_h ? "encerrar" : "iniciar") : "lancar",
            "botao" => $t["formato"] === "sessao" ? ($aberta_h ? (preg_match("/^n(o|a|os|as)\\s/u", $minus) === 1 ? "Tirou d" . substr($minus, 1) : "Terminou: " . $minus) : "Pôs " . $minus)
                : ($t["formato"] === "valor" ? "Informar " . $minus : $t["nome"])] : null];
}
$fotos = [];
foreach (linhas("SELECT relogio_id, atualizado FROM foto") as $f) {
    $fotos[(int)$f["relogio_id"]] = strtotime($f["atualizado"]);
}
$rels = [];
// os que não são da coleção (desejados, vendidos, arquivados): fora da tabela, numa lista à parte
$fora_h = [];
foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
    if (!da_colecao($r)) {
        $fora_h[] = ["id" => (int)$r["id"], "nome" => $r["nome"], "posse" => posse_de($r), "posse_texto" => POSSES[posse_de($r)],
            "tipo" => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "—", "foto" => $fotos[(int)$r["id"]] ?? null];
        continue;
    }
    $v = visao_relogio($r, $agora);
    // o estado na tabela: o indisponível parado aparece como indisponível (o emprestado, como emprestado); no pulso ou numa
    // sessão, o que ele está fazendo
    $estado_h = $v["estado"] === "repouso" && (int)$r["disponivel"] !== 1 ? "indisponivel" : $v["estado"];
    $val = valores_do_relogio((int)$r["id"]);
    $leitura = linha("SELECT l.inicio, l.valor, t.unidade FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.formato = 'valor'
            ORDER BY l.inicio DESC, l.id DESC LIMIT 1", [(int)$r["id"]]);
    $rels[] = ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1, "tipo" => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "—",
        "posse" => posse_de($r), "posse_texto" => POSSES[posse_de($r)],
        "em_uso" => $v["em_uso"], "estado" => $estado_h, "estado_texto" => $estado_h === "indisponivel" ? (posse_de($r) === "emprestado" ? "Emprestado" : "Indisponível") : $v["estado_texto"],
        "estado_ordem" => ["uso" => 0, "sessao" => 1, "repouso" => 2, "indisponivel" => 3][$estado_h],
        "agora" => $v["texto"], "carga" => (int)$r["disponivel"] === 1 ? $v["energia"] : null, "carga_de" => $v["de"], "ultimo" => $v["ultimo"],
        "ultimo_txt" => $v["em_uso"] ? "agora · " . date("H:i", $agora) : ($v["ultimo"] > 0 ? date(date("Y", $v["ultimo"]) === date("Y", $agora) ? "d/m H:i" : "d/m/Y H:i", $v["ultimo"]) : "nunca"),
        "situacao" => $v["situacao"], "manutencao" => $v["prox"] ? ["nome" => $v["prox"]["nome"], "data" => max($hoje->format("Y-m-d"), substr($v["prox"]["data"], 0, 10)),
            "momento" => $v["prox_momento"], "falta" => $v["prox_falta"]] : null,
        "compra" => ["data" => $val["data_compra"] ?? null, "valor" => isset($val["valor_compra"]) ? (float)$val["valor_compra"] : null, "loja" => $val["loja"] ?? null,
            "garantia_ate" => $val["garantia_ate"] ?? null],
        "leitura" => $leitura ? ["inicio" => $leitura["inicio"], "valor" => (float)$leitura["valor"], "unidade" => $leitura["unidade"]] : null,
        "de_hoje" => $dia !== null && (int)$dia["relogio_id"] === (int)$r["id"], "com_aviso" => isset($pendentes_h[(int)$r["id"]]), "foto" => $fotos[(int)$r["id"]] ?? null];
}
// o destaque no alto da página (a Configuração): o relógio do dia ou o que está no pulso agora; cada relógio diz se é ele
$destaque_h = destaque_da_pagina_hoje($rels, $dia);
foreach ($rels as $i => $r) {
    $rels[$i]["destaque"] = $destaque_h["relogio_id"] === $r["id"];
}
$modos_h = [];
foreach (linhas("SELECT * FROM modo ORDER BY ordem, id") as $m) {
    $modos_h[] = ["id" => (int)$m["id"], "nome" => $m["nome"], "selecao" => $m["selecao"], "escala_dias" => $m["escala_dias"] === null ? null : (int)$m["escala_dias"], "ciclo" => (int)$m["ciclo"] === 1,
        "blocos" => array_map(function ($b) {
            return ["id" => (int)$b["id"], "nome" => $b["nome"], "dias" => $b["dias"], "no_id" => $b["no_id"] === null ? 0 : (int)$b["no_id"], "um_por" => $b["um_por"],
                "relogio_id" => $b["relogio_id"] === null ? null : (int)$b["relogio_id"]];
        }, modo_blocos((int)$m["id"]))];
}
$seg = $hoje->modify("next monday");
$avisos_nomes = [];
foreach (avisos_todos() as $versoes) {
    $avisos_nomes[] = $versoes[0]["nome"];
}
$saida = ["data" => $hoje->format("Y-m-d"), "agora" => $agora, "uso_inicio" => cfg("uso_inicio"), "comecou" => $dia !== null && dia_comecou($agora),
    "pulso_poe_sozinho" => pulso_poe_sozinho(), "pulso_tira_sozinho" => pulso_tira_sozinho(),
    "modo" => $modo ? ["id" => (int)$modo["id"], "nome" => $modo["nome"], "selecao" => $modo["selecao"], "escala_dias" => $modo["escala_dias"] === null ? null : (int)$modo["escala_dias"]] : null,
    "escala_fim" => cfg("escala_fim") !== "" ? cfg("escala_fim") : null, "max_sem_uso" => (int)cfg("max_sem_uso"),
    "dia" => $dia ? ["data" => $dia["data"], "relogio_id" => (int)$dia["relogio_id"], "relogio" => $dia["nome"], "ate" => texto_ate($dia["data"], "hoje"), "acao" => $dia["acao"], "motivo" => $dia["motivo"]] : null,
    "destaque" => $destaque_h,
    "avisos" => $avisos_h,
    // os estados da tabela, na ordem (o filtro Estado): em uso, cada sessão que não é o pulso, em repouso e indisponível
    "estados" => array_merge(["Em uso"], array_column(linhas("SELECT nome FROM lancamento_tipo WHERE formato = 'sessao' AND identificador <> 'pulso' ORDER BY ordem, id"), "nome"),
        ["Em repouso", "Indisponível", "Emprestado"]),
    "plano" => array_map(function ($p) { return ["data" => $p["data"], "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["nome"], "acao" => $p["acao"], "motivo" => $p["motivo"], "origem" => $p["origem"]]; },
        linhas("SELECT p.*, r.nome FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data >= ? ORDER BY p.data LIMIT 62", [$hoje->format("Y-m-d")])),
    // os dias livres dos próximos 62 dias (sem relógio e sem sorteio: para escolher à mão)
    "livres" => dias_livres($hoje->format("Y-m-d"), $hoje->modify("+61 days")->format("Y-m-d")),
    // os Próximos dias da página, uma página por vez (o "Mostrar até" da Configuração), com os dias livres no meio
    "proximos" => plano_pagina($hoje->format("Y-m-d"), "", null, (int)($_REQUEST["proximos_pagina"] ?? 1),
        ctype_digit((string)($_REQUEST["por_pagina"] ?? "")) ? (int)$_REQUEST["por_pagina"] : proximos_por_pagina()),
    "proxima_semana" => ["segunda" => $seg->format("Y-m-d"), "domingo" => $seg->modify("+6 days")->format("Y-m-d"),
        "ja_montada" => (int)valor("SELECT COUNT(*) FROM plano WHERE data BETWEEN ? AND ?", [$seg->format("Y-m-d"), $seg->modify("+6 days")->format("Y-m-d")]) > 1],
    "modos" => $modos_h,
    "grupos" => array_map(function ($o) use ($n) { return ["id" => $o[0], "nome" => $n[$o[0]]["nome"], "profundidade" => $o[1], "caminho" => no_caminho($o[0])]; }, nos_em_ordem()),
    "avisos_nomes" => array_values(array_unique($avisos_nomes)),
    "relogios" => $rels,
    "fora_da_colecao" => $fora_h,
    // os totais da coleção inteira (a tabela da página soma só o que os filtros dela deixam à mostra)
    "totais" => ["relogios" => count($rels), "disponiveis" => count(array_filter($rels, function ($r) { return $r["disponivel"]; })),
        "em_uso" => count(array_filter($rels, function ($r) { return $r["em_uso"]; })),
        "valor" => round(array_sum(array_map(function ($r) { return $r["compra"]["valor"] ?? 0; }, $rels)), 2),
        "desejados" => count(array_filter($fora_h, function ($r) { return $r["posse"] === "desejado"; })),
        "fora" => count($fora_h)]];
