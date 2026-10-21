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
        "padrao" => $c["padrao"], "opcoes" => $c["opcoes"], "multiplo" => (int)($c["multiplo"] ?? 0) === 1, "regra" => campo_regra_texto($c), "grupo" => $c["no_id"] === null ? null : no_caminho((int)$c["no_id"]), "movimento" => (int)($c["calibre"] ?? 0) === 1];
}, array_filter(campos_todos(), function ($c) { return (int)($c["so_desejado"] ?? 0) !== 1; })));
// o formulário: só os campos do movimento ou todos (a Configuração calibre_campos); os de um calibre novo em campos_no_formulario,
// os de cada calibre em calibres[].campos_no_formulario
$saida = ["calibres" => calibres_todos(), "campos" => $campos_cal, "campos_mostrar" => calibre_campos(), "campos_no_formulario" => calibre_campos_no_formulario()];
