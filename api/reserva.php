<?php
// recurso=reserva: a reserva medida (de um relógio, com relogio=<id>; sem ele, os testes e as observações de todos, do mais
// novo para o mais velho). Com o relógio: se ele faz o teste da reserva (o mecânico) e se registra as observações da energia
// (o mecânico e o solar), o teste em andamento, os testes, as observações, a medida (a mediana dos testes), o valor do
// cadastro do próprio relógio e o que o "Usar a medida" gravaria, a tabela declarado, medido e estimado com a confiança, os
// protocolos e os tipos de observação.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$rid_rs = (int)($_REQUEST["relogio"] ?? 0);
$r_rs = $rid_rs > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$rid_rs]) : null;
if ($rid_rs > 0 && !$r_rs) {
    $codigo = 404;
    $saida = ["erro" => t("relógio não encontrado")];
} elseif ($r_rs) {
    $agora_rs = time();
    $rs = reserva_do_relogio($r_rs, $agora_rs);
    $saida = ["relogio" => ["id" => (int)$r_rs["id"], "nome" => $r_rs["nome"]], "reserva" => $rs,
        "tecnico" => tecnico_do_relogio($r_rs, $agora_rs, precisao_do_relogio($r_rs, $agora_rs), $rs, servicos_resumo($rid_rs))];
} else {
    $saida = ["relogio" => null, "reserva" => null, "tecnico" => null, "registros" => reserva_lista()];
}
