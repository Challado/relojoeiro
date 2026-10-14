<?php
// recurso=arvore: a árvore dos grupos e o grupo de cada relógio.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// a árvore (os grupos) em ordem, com os relógios direto em cada grupo e quantos parâmetros de critérios ele tem, e o grupo de cada relógio
$n = nos_todos();
$rels_a = linhas("SELECT id, nome, no_id, disponivel FROM relogio ORDER BY nome");
$crit = [];
foreach (linhas("SELECT escopo_no_id, COUNT(*) AS q FROM criterio_parametro WHERE escopo_no_id IS NOT NULL GROUP BY escopo_no_id") as $x) {
    $crit[(int)$x["escopo_no_id"]] = (int)$x["q"];
}
$saida = ["grupos" => array_map(function ($o) use ($n, $rels_a, $crit) {
    return ["id" => $o[0], "pai_id" => $n[$o[0]]["pai_id"] === null ? null : (int)$n[$o[0]]["pai_id"], "nome" => $n[$o[0]]["nome"], "profundidade" => $o[1], "caminho" => no_caminho($o[0]),
        "cadeia" => no_cadeia($o[0]), "subgrupos" => count(array_filter($n, function ($x) use ($o) { return (int)$x["pai_id"] === $o[0]; })),
        "relogios" => array_values(array_map(function ($r) { return $r["nome"]; }, array_filter($rels_a, function ($r) use ($o) { return (int)$r["no_id"] === $o[0]; }))),
        "criterios" => $crit[$o[0]] ?? 0];
}, nos_em_ordem()), "relogios" => array_map(function ($r) {
    return ["id" => (int)$r["id"], "nome" => $r["nome"], "no_id" => $r["no_id"] === null ? 0 : (int)$r["no_id"], "disponivel" => (int)$r["disponivel"] === 1];
}, $rels_a)];
