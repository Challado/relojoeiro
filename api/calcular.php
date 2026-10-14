<?php
// recurso=calcular: o resultado de uma fórmula para um relógio (ou para todos).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$expr = (string)($_REQUEST["expressao"] ?? "");
// a mesma conferência das fórmulas gravadas: escrita, variáveis e tipos de lançamento
$erros = formula_validar("", $expr);
if (count($erros) > 0) {
    $codigo = 400;
    $saida = ["erro" => "a fórmula não pode ser calculada", "erros" => $erros];
} else {
    $ids = array_filter(array_map("intval", explode(',', (string)($_REQUEST["relogio"] ?? ""))));
    $res = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
        if (count($ids) === 0 || in_array((int)$r["id"], $ids, true)) {
            $ctx = ["r" => $r, "momento" => time(), "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
            $valor = formula_calcular(formula_ler($expr), $ctx);
            $res[] = ["relogio_id" => (int)$r["id"], "relogio" => $r["nome"], "valor" => $valor, "partes" => (object)$ctx["rastro"]];
        }
    }
    $saida = ["expressao" => $expr, "resultados" => $res];
}
