<?php
// recurso=historico: a linha do tempo de um relógio, com os filtros e o resumo.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// os relógios pedidos (relogio=3 ou 1,2; relogio_id também vale); sem nenhum, todos
$ids_h = array_values(array_filter(array_map("intval", array_merge(explode(",", (string)($_REQUEST["relogio"] ?? "")), explode(",", (string)($_REQUEST["relogio_id"] ?? ""))))));
$rels_h = array_values(array_filter(linhas("SELECT id, nome FROM relogio ORDER BY nome"), function ($x) use ($ids_h) {
    return count($ids_h) === 0 || in_array((int)$x["id"], $ids_h, true);
}));
// o período: data (de às 00:00:00, ate às 23:59:59) ou data e hora; o que não entende vai para "ignorados"
$per = [0, PHP_INT_MAX];
$filtro = ["de" => null, "ate" => null];
$ignorados = [];
foreach (["de" => 0, "ate" => 1] as $campo => $pos) {
    $v = str_replace("T", " ", trim((string)($_REQUEST[$campo] ?? "")));
    if ($v !== "") {
        if (preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2}(:[0-9]{2})?)?\$/", $v) === 1 && strtotime($v) !== false) {
            $v .= strlen($v) === 10 ? ($campo === "de" ? " 00:00:00" : " 23:59:59") : (strlen($v) === 16 ? ($campo === "de" ? ":00" : ":59") : "");
            $per[$pos] = $campo === "de" ? strtotime($v) : strtotime($v) + 1;
            $filtro[$campo] = $v;
        } else {
            $ignorados[] = $campo . " = " . $v . " (formato: AAAA-MM-DD ou AAAA-MM-DD HH:MM)";
        }
    }
}
$estados_h = array_values(array_intersect(array_filter(array_map("trim", explode(",", (string)($_REQUEST["estado"] ?? "") . "," . (string)($_REQUEST["linha_estado"] ?? "")))),
    array_keys(estados_da_linha())));
$linhas_h = [];
$resumos = [];
foreach ($rels_h as $x) {
    $f_h = linha_do_tempo((int)$x["id"], $per[0], $per[1], $estados_h);
    foreach ($f_h["linhas"] as $l) {
        $linhas_h[] = array_merge(["relogio_id" => (int)$x["id"], "relogio" => $x["nome"], "ts" => $l["inicio"]], linha_legivel($l));
    }
    $todas = linha_do_tempo((int)$x["id"], 0, PHP_INT_MAX, [])["linhas"];
    $rx = linha("SELECT no_id FROM relogio WHERE id = ?", [(int)$x["id"]]);
    $foto_h = valor("SELECT atualizado FROM foto WHERE relogio_id = ?", [(int)$x["id"]]);
    $resumos[(string)$x["id"]] = array_merge(["relogio" => $x["nome"], "tipo" => isset(nos_todos()[(int)$rx["no_id"]]) ? nos_todos()[(int)$rx["no_id"]]["nome"] : "sem grupo",
        "foto" => $foto_h !== null ? strtotime($foto_h) : null, "registros" => count($todas),
        "desde" => count($todas) > 0 ? date("Y-m-d H:i:s", $todas[count($todas) - 1]["inicio"]) : null], resumo_do_tempo($f_h));
}
$asc = ($_REQUEST["ordem"] ?? "") === "asc";
usort($linhas_h, function ($a, $b) use ($asc) { return $asc ? $a["ts"] <=> $b["ts"] : $b["ts"] <=> $a["ts"]; });
$por_h = max(1, min(1000, (int)($_REQUEST["limite"] ?? 100)));
$pag_h = max(1, (int)($_REQUEST["pagina"] ?? 1));
$ids_rel = array_map("intval", array_column($rels_h, "id"));
$saida = ["filtro" => ["relogios" => $ids_rel, "de" => $filtro["de"], "ate" => $filtro["ate"], "estados" => $estados_h, "ordem" => $asc ? "asc" : "desc", "ignorados" => $ignorados],
    "estados" => estados_da_linha(), "total" => count($linhas_h), "pagina" => $pag_h, "por_pagina" => $por_h, "paginas" => max(1, (int)ceil(count($linhas_h) / $por_h)),
    "resumo" => (object)$resumos,
    "linhas" => array_map(function ($l) { unset($l["ts"]); return $l; }, array_slice($linhas_h, ($pag_h - 1) * $por_h, $por_h)),
    "lancamentos" => count($ids_rel) === 0 ? [] : linhas("SELECT l.id, l.relogio_id, r.nome AS relogio, t.identificador AS tipo, l.inicio, l.fim, l.valor, l.origem
            FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id JOIN relogio r ON r.id = l.relogio_id WHERE l.relogio_id IN (" . implode(",", $ids_rel) . ")
            AND l.inicio < ? AND COALESCE(l.fim, l.inicio) >= ? ORDER BY l.inicio " . ($asc ? "ASC, l.id ASC" : "DESC, l.id DESC") . " LIMIT " . $por_h,
        // o teto do período sem "ate": 9999-12-31 23:59:59 no fuso do sistema (o instante dele em UTC, num fuso a leste de UTC,
        // vira o ano 10000, que o MySQL compara como texto e não acha nada, e o Firebird recusa)
        [date("Y-m-d H:i:s", min($per[1], (int)strtotime("9999-12-31 23:59:59"))), date("Y-m-d H:i:s", $per[0])])];
