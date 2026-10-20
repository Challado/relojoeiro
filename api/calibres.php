<?php
// recurso=calibres: os calibres da árvore (o movimento de cada relógio), cada um com os valores dos campos que define e os
// relógios que apontam para ele, e os campos do cadastro que um calibre pode definir (o formulário da aba Calibres).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// os campos: os do cadastro (os da lista de desejos não são do movimento), com o grupo em que valem
$campos_cal = array_values(array_map(function ($c) {
    return ["id" => (int)$c["id"], "identificador" => $c["identificador"], "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"],
        "padrao" => $c["padrao"], "opcoes" => $c["opcoes"], "grupo" => $c["no_id"] === null ? null : no_caminho((int)$c["no_id"])];
}, array_filter(campos_todos(), function ($c) { return (int)($c["so_desejado"] ?? 0) !== 1; })));
$saida = ["calibres" => calibres_todos(), "campos" => $campos_cal];
