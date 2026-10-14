<?php
// recurso=desejos: a lista de desejos (os relógios desejados) e os que estão fora da coleção (emprestados, vendidos, arquivados).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$n = nos_todos();
$fotos_d = [];
foreach (linhas("SELECT relogio_id, atualizado FROM foto") as $f) {
    $fotos_d[(int)$f["relogio_id"]] = strtotime($f["atualizado"]);
}
$desejados = [];
$fora_d = [];
foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
    $base = ["id" => (int)$r["id"], "nome" => $r["nome"], "tipo" => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : t("sem grupo"),
        "caminho" => no_caminho($r["no_id"]), "foto" => $fotos_d[(int)$r["id"]] ?? null, "criado" => $r["criado"]];
    if (posse_de($r) === "desejado") {
        $desejados[] = array_merge($base, desejo_do_relogio(valores_do_relogio((int)$r["id"])));
    } elseif (posse_de($r) !== "colecao") {
        $fora_d[] = array_merge($base, ["posse" => posse_de($r), "posse_texto" => t(POSSES[posse_de($r)])]);
    }
}
// a lista: a prioridade mais alta primeiro, depois pelo nome
usort($desejados, function ($a, $b) {
    return $a["prioridade_ordem"] <=> $b["prioridade_ordem"] ?: strcmp($a["nome"], $b["nome"]);
});
$c_prio = campos_todos()["prioridade_desejo"] ?? null;
$saida = ["desejados" => $desejados, "total_preco_alvo" => round(array_sum(array_map(function ($x) { return $x["preco_alvo"] ?? 0; }, $desejados)), 2),
    "sem_preco_alvo" => count(array_filter($desejados, function ($x) { return $x["preco_alvo"] === null; })),
    "prioridades" => array_values(array_filter(array_map("trim", explode("\n", (string)($c_prio["opcoes"] ?? ""))))),
    "fora" => $fora_d,
    "grupos" => array_map(function ($o) { return ["id" => $o[0], "caminho" => no_caminho($o[0])]; }, nos_em_ordem())];
