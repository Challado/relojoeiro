<?php
// recurso=servicos: os serviços e diagnósticos (de um relógio, com relogio=<id>; sem ele, de todos), do mais novo para o mais
// velho, com as peças, os documentos, o custo, a estanqueidade e a marcha antes e depois; os tipos; e, com o relógio, o
// resumo (quantos, o custo somado, o último, a resistência à água declarada e a estanqueidade comprovada) e os documentos
// dele que um serviço pode citar.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$rid_s = (int)($_REQUEST["relogio"] ?? 0);
$r_s = $rid_s > 0 ? linha("SELECT id, nome FROM relogio WHERE id = ?", [$rid_s]) : null;
if ($rid_s > 0 && !$r_s) {
    $codigo = 404;
    $saida = ["erro" => t("relógio não encontrado")];
} else {
    $lista_s = servicos_lista($r_s ? $rid_s : null);
    $saida = ["relogio" => $r_s ? ["id" => (int)$r_s["id"], "nome" => $r_s["nome"]] : null,
        "tipos" => servico_tipos_opcoes(),
        "servicos" => $lista_s, "resumo" => $r_s ? servicos_resumo($rid_s, $lista_s) : null,
        "documentos" => $r_s ? array_map(function ($x) { return ["id" => (int)$x["id"], "titulo" => $x["titulo"]]; }, documentos_do_relogio($rid_s)) : null];
}
