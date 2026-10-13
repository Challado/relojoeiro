<?php
// recurso=galeria: a coleção para ver e imprimir, por grupo: cada relógio com a foto, a marca e a referência, o ano e o valor
// da compra e os dias de uso. Sem nada, os da coleção (na coleção e emprestados); posse=todos: também os desejados, vendidos e
// arquivados.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$todos_g = ($_REQUEST["posse"] ?? "") === "todos";
$agora_g = time();
$n_g = nos_todos();
$fotos_g = [];
foreach (linhas("SELECT relogio_id, atualizado FROM foto") as $f) {
    $fotos_g[(int)$f["relogio_id"]] = strtotime($f["atualizado"]);
}
$grupos_g = [];
$total_g = 0;
$valor_g = 0.0;
$com_foto_g = 0;
foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
    if (!$todos_g && !da_colecao($r)) {
        continue;
    }
    $v = valores_do_relogio((int)$r["id"]);
    $c = ["r" => $r, "momento" => $agora_g, "rastro" => [], "pilha" => [], "valores" => $v];
    $du = isset(formulas_todas()["dias_de_uso"]) ? variavel_valor("dias_de_uso", $c) : null;
    $caminho = $r["no_id"] === null ? t("(na raiz)") : no_caminho($r["no_id"]);
    if (!isset($grupos_g[$caminho])) {
        $grupos_g[$caminho] = ["caminho" => $caminho, "relogios" => []];
    }
    $valor = ($v["valor_compra"] ?? "") !== "" ? (float)$v["valor_compra"] : null;
    $grupos_g[$caminho]["relogios"][] = ["id" => (int)$r["id"], "nome" => $r["nome"],
        "tipo" => isset($n_g[(int)$r["no_id"]]) ? $n_g[(int)$r["no_id"]]["nome"] : t("sem grupo"),
        "posse" => posse_de($r), "posse_texto" => t(POSSES[posse_de($r)]), "foto" => $fotos_g[(int)$r["id"]] ?? null,
        "marca" => ($v["marca"] ?? "") !== "" ? $v["marca"] : null, "referencia" => ($v["referencia"] ?? "") !== "" ? $v["referencia"] : null,
        "ano_compra" => ($v["data_compra"] ?? "") !== "" ? (int)substr((string)$v["data_compra"], 0, 4) : null, "valor_compra" => $valor,
        "dias_de_uso" => is_numeric($du) ? (int)round((float)$du) : null];
    $total_g++;
    $com_foto_g += isset($fotos_g[(int)$r["id"]]) ? 1 : 0;
    $valor_g += $valor ?? 0;
}
ksort($grupos_g);
$saida = ["gerado_em" => date("Y-m-d H:i:s", $agora_g), "todos" => $todos_g, "total" => $total_g, "valor_total" => round($valor_g, 2),
    "com_foto" => $com_foto_g,
    "grupos" => array_values($grupos_g)];
