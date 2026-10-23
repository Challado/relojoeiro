<?php
// recurso=hoje: tudo o que a página Hoje mostra.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// tudo o que a página Hoje mostra: o dia, os avisos de hoje, os próximos dias, o modo de rodízio e a tabela dos relógios
$hoje = hoje_da_colecao();
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
    // o winder é obrigatório: o aviso que se resolve pondo no winder fica sem botão sem winder cadastrado; com winder, o botão
    // leva à página Winders (o pôr é lá), a não ser que o relógio tenha o lugar dele livre: aí o botão o põe de volta lá; com
    // a sessão aberta, o Tirou continua
    $pagina_w = $t && (int)($t["winder"] ?? 0) === 1 && !$aberta_h && !(($lg_h = winder_lugar_info((int)$x[0]["id"], $agora)) !== null && $lg_h["livre"]);
    if ($pagina_w && !winder_cadastrado()) {
        $t = null;
        $pagina_w = false;
    }
    $minus = $t ? (function_exists("mb_strtolower") ? mb_strtolower($t["nome"], "UTF-8") : strtolower($t["nome"])) : "";
    $avisos_h[] = ["relogio_id" => (int)$x[0]["id"], "relogio" => $x[0]["nome"], "identificador" => $x[1]["identificador"], "nome" => $x[1]["nome"], "texto" => $x[1]["texto"],
        "estado" => $x[1]["estado"], "resolve" => $t ? ["identificador" => $t["identificador"], "nome" => $t["nome"], "formato" => $t["formato"], "unidade" => $t["unidade"],
            "acao" => $pagina_w ? "winders" : ($t["formato"] === "sessao" ? ($aberta_h ? "encerrar" : "iniciar") : "lancar"),
            // o botão: "Pôs no carregador"; aberta, "Tirou do carregador" (o "no" do nome vira "do": só em português, a língua do nome
            // no cadastro de quem instalou em português; nos outros idiomas, "Terminou: <nome>")
            // a leitura que mede o gasto num relógio que vai ao pulso: a tela pergunta como ele estava (em uso ou em repouso)
            "estados" => $t["formato"] === "valor" && (int)$t["mede_gasto"] === 1 && isset(lancamento_tipos_do_relogio($x[0])["pulso"]),
            "botao" => $pagina_w ? t("Pôr {1} ›", [1 => $minus]) : ($t["formato"] === "sessao" ? ($aberta_h ? (lingua_atual() === "pt-BR" && preg_match("/^n(o|a|os|as)\\s/u", $minus) === 1 ? "Tirou d" . substr($minus, 1) : t("Terminou: {1}", [1 => $minus])) : t("Pôs {1}", [1 => $minus]))
                : ($t["formato"] === "valor" ? t("Informar {1}", [1 => $minus]) : $t["nome"]))] : null];
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
        $fora_h[] = ["id" => (int)$r["id"], "nome" => $r["nome"], "posse" => posse_de($r), "posse_texto" => t(POSSES[posse_de($r)]),
            "tipo" => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "—", "foto" => $fotos[(int)$r["id"]] ?? null];
        continue;
    }
    $rels[] = tabela_linha($r, $agora, ["nos" => $n, "hoje" => $hoje, "dia" => $dia, "pendentes" => $pendentes_h, "fotos" => $fotos]);
}
// o destaque no alto da página (a Configuração): o relógio do dia ou o que está no pulso agora; cada relógio diz se é ele
$destaque_h = destaque_da_pagina_hoje($rels, $dia);
foreach ($rels as $i => $r) {
    $rels[$i]["destaque"] = $destaque_h["relogio_id"] === $r["id"];
}
$modos_h = [];
foreach (linhas("SELECT * FROM modo ORDER BY ordem, id") as $m) {
    $modos_h[] = ["id" => (int)$m["id"], "nome" => $m["nome"], "tipo" => $m["tipo"], "tipo_texto" => t(MODO_TIPOS[$m["tipo"]] ?? "Personalizado"), "semanas" => (int)$m["semanas"],
        "selecao" => $m["selecao"], "escala_dias" => $m["escala_dias"] === null ? null : (int)$m["escala_dias"], "ciclo" => (int)$m["ciclo"] === 1,
        "blocos" => array_map(function ($b) {
            return ["id" => (int)$b["id"], "nome" => $b["nome"], "dias" => $b["dias"], "no_id" => $b["no_id"] === null ? 0 : (int)$b["no_id"], "um_por" => $b["um_por"],
                "relogio_id" => $b["relogio_id"] === null ? null : (int)$b["relogio_id"]];
        }, modo_blocos((int)$m["id"]))];
}
// a próxima semana: a que começa depois do último dia desta (a semana começa no dia da Configuração, semana_inicio)
$ini_s = semana_fim($hoje)->modify("+1 day");
$avisos_nomes = [];
foreach (avisos_todos() as $ident => $versoes) {
    // o Encostado tem a coluna dele; não entra no filtro "O que fazer" (a manutenção)
    if ($ident === "encostado") {
        continue;
    }
    $avisos_nomes[] = $versoes[0]["nome"];
}
$saida = ["data" => $hoje->format("Y-m-d"), "agora" => $agora, "uso_inicio" => cfg("uso_inicio"), "comecou" => $dia !== null && dia_comecou($agora),
    "pulso_poe_sozinho" => pulso_poe_sozinho(), "pulso_tira_sozinho" => pulso_tira_sozinho(),
    "modo" => $modo ? ["id" => (int)$modo["id"], "nome" => $modo["nome"], "tipo" => $modo["tipo"], "tipo_texto" => t(MODO_TIPOS[$modo["tipo"]] ?? "Personalizado"), "semanas" => (int)$modo["semanas"], "selecao" => $modo["selecao"], "escala_dias" => $modo["escala_dias"] === null ? null : (int)$modo["escala_dias"]] : null,
    "escala_fim" => cfg("escala_fim") !== "" ? cfg("escala_fim") : null, "max_sem_uso" => (int)cfg("max_sem_uso"),
    "dia" => $dia ? ["data" => $dia["data"], "relogio_id" => (int)$dia["relogio_id"], "relogio" => $dia["nome"], "ate" => texto_ate($dia["data"], "hoje"), "acao" => $dia["acao"], "motivo" => $dia["motivo"]] : null,
    "destaque" => $destaque_h,
    "avisos" => $avisos_h,
    // a caixa da Configuração: desligada, a página esconde a coluna Encostado e o filtro (e nenhum relógio vem com encostado)
    "encostado_ativo" => encostado_ativo(),
    // os estados da tabela, na ordem (o filtro Estado): em uso, cada sessão que não é o pulso, em repouso e indisponível
    "estados" => array_merge([t("Em uso")], array_column(linhas("SELECT nome FROM lancamento_tipo WHERE formato = 'sessao' AND identificador <> 'pulso' ORDER BY ordem, id"), "nome"),
        [t("Em repouso"), t("Indisponível"), t("Emprestado")]),
    "plano" => array_map(function ($p) { return ["data" => $p["data"], "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["nome"], "acao" => $p["acao"], "motivo" => $p["motivo"], "origem" => $p["origem"]]; },
        linhas("SELECT p.*, r.nome FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data >= ? ORDER BY p.data LIMIT 62", [$hoje->format("Y-m-d")])),
    // os dias livres dos próximos 62 dias (sem relógio e sem sorteio: para escolher à mão)
    // hoje é um dia livre (o "Sem relógio hoje", ou um período livre): sem relógio do dia, para escolher à mão
    "hoje_livre" => dia_livre($hoje->format("Y-m-d")),
    "livres" => dias_livres($hoje->format("Y-m-d"), $hoje->modify("+61 days")->format("Y-m-d")),
    // os Próximos dias da página, uma página por vez (o "Mostrar até" da Configuração), com os dias livres no meio
    "proximos" => plano_pagina($hoje->format("Y-m-d"), "", null, (int)($_REQUEST["proximos_pagina"] ?? 1),
        ctype_digit((string)($_REQUEST["por_pagina"] ?? "")) ? (int)$_REQUEST["por_pagina"] : proximos_por_pagina()),
    "semana_inicio" => semana_comeca() === 7 ? "domingo" : "segunda",
    // o relógio de hoje se troca no próprio dia? (a Configuração; não: o trocar por…, o Usando hoje, o inclusive hoje e o Sem
    // relógio hoje não aparecem, e a troca vale a partir de amanhã)
    "trocar_hoje" => trocar_hoje_permitido(),
    "proxima_semana" => ["inicio" => $ini_s->format("Y-m-d"), "fim" => $ini_s->modify("+6 days")->format("Y-m-d"),
        "ja_montada" => (int)valor("SELECT COUNT(*) FROM plano WHERE data BETWEEN ? AND ?", [$ini_s->format("Y-m-d"), $ini_s->modify("+6 days")->format("Y-m-d")]) > 1],
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
