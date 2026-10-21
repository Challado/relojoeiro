<?php
// recurso=timegrapher: as medições do timegrapher (de um relógio, com relogio=<id>: a última sessão com a média de cada
// grandeza e a diferença entre as posições, o resumo, as medições, as grandezas e as posições dos formulários; sem ele, as
// medições de todos os relógios, da mais nova para a mais velha), e as colunas que a importação do CSV entende.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$rid_tg = (int)($_REQUEST["relogio"] ?? 0);
$r_tg = $rid_tg > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$rid_tg]) : null;
// as colunas e as posições que a importação entende (o mesmo para todos os relógios)
$csv_tg = ["colunas" => array_map(function ($c, $ns) { return ["campo" => $c, "nomes" => $ns]; }, array_keys(TIMEGRAPHER_COLUNAS), array_values(TIMEGRAPHER_COLUNAS)),
    "posicoes" => array_map(function ($c, $ns) { return ["posicao" => $c, "nomes" => $ns]; }, array_keys(TIMEGRAPHER_POSICAO_NOMES), array_values(TIMEGRAPHER_POSICAO_NOMES))];
if ($rid_tg > 0 && !$r_tg) {
    $codigo = 404;
    $saida = ["erro" => t("relógio não encontrado")];
} elseif ($r_tg) {
    $saida = ["relogio" => ["id" => (int)$r_tg["id"], "nome" => $r_tg["nome"]], "timegrapher" => timegrapher_do_relogio($r_tg, time()), "csv" => $csv_tg];
} else {
    $saida = ["relogio" => null, "timegrapher" => null, "medicoes" => timegrapher_lista(), "csv" => $csv_tg];
}
