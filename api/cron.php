<?php
// recurso=cron: as execuções do cron, com os filtros, o resumo e as páginas.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// as execuções do cron, com filtro de período (cron_de e cron_ate, ou de e ate: data, ou data e hora), situação
// (atividade, erro, nada ou todas; padrão: atividade) e texto no registro (cron_busca ou busca), resumo do período e páginas
$per_c = [];
foreach (["de" => "00:00:00", "ate" => "23:59:59"] as $k => $hora) {
    $v = str_replace("T", " ", trim((string)($_REQUEST["cron_" . $k] ?? ($_REQUEST[$k] ?? ""))));
    $per_c[$k] = preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2}(:[0-9]{2})?)?\$/", $v) === 1
        ? $v . (strlen($v) === 10 ? " " . $hora : (strlen($v) === 16 ? ($k === "de" ? ":00" : ":59") : "")) : ($k === "de" ? "0001-01-01 00:00:00" : "9999-12-31 23:59:59");
}
$situacao = in_array($_REQUEST["cron_situacao"] ?? ($_REQUEST["situacao"] ?? ""), ["atividade", "erro", "nada", "todas"], true) ? ($_REQUEST["cron_situacao"] ?? $_REQUEST["situacao"]) : "atividade";
$busca = trim((string)($_REQUEST["cron_busca"] ?? ($_REQUEST["busca"] ?? "")));
$onde = "inicio BETWEEN ? AND ?";
$params = [$per_c["de"], $per_c["ate"]];
$resumo = linha("SELECT COUNT(*) AS total, COALESCE(SUM(teve_atividade), 0) AS atividade, COALESCE(SUM(teve_erro), 0) AS erros,
        COALESCE(MAX(duracao_ms), 0) AS mais_lenta FROM cron_execucao WHERE " . $onde, $params);
$onde .= ["atividade" => " AND teve_atividade = 1", "erro" => " AND teve_erro = 1", "nada" => " AND teve_atividade = 0", "todas" => ""][$situacao];
if ($busca !== "") {
    $onde .= " AND registro LIKE ?";
    $params[] = "%" . $busca . "%";
}
$total = (int)valor("SELECT COUNT(*) FROM cron_execucao WHERE " . $onde, $params);
$por = max(1, min(1000, (int)($_REQUEST["limite"] ?? 100)));
$paginas = max(1, (int)ceil($total / $por));
$pagina = min($paginas, max(1, (int)($_REQUEST["pagina"] ?? 1)));
$saida = ["filtro" => ["de" => $per_c["de"], "ate" => $per_c["ate"], "situacao" => $situacao, "busca" => $busca], "ultima" => cfg("cron_ultima_execucao"),
    "resumo" => ["total" => (int)$resumo["total"], "atividade" => (int)$resumo["atividade"], "erros" => (int)$resumo["erros"], "mais_lenta_ms" => (int)$resumo["mais_lenta"]],
    "total" => $total, "pagina" => $pagina, "por_pagina" => $por, "paginas" => $paginas,
    "execucoes" => array_map(function ($x) {
        return ["id" => (int)$x["id"], "inicio" => $x["inicio"], "fim" => $x["fim"], "duracao_ms" => (int)$x["duracao_ms"], "teve_atividade" => (int)$x["teve_atividade"] === 1,
            "teve_erro" => (int)$x["teve_erro"] === 1, "registro" => $x["registro"]];
    }, linhas("SELECT * FROM cron_execucao WHERE " . $onde . " ORDER BY inicio DESC, id DESC LIMIT " . $por . " OFFSET " . (($pagina - 1) * $por), $params))];
