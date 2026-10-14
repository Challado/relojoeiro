<?php
// recurso=documentos: os documentos de um relógio (ou de todos), as categorias e o limite.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// os documentos de um relógio (relogio=<id>; sem ele, de todos), com as categorias, os relógios, a pasta e o limite
$rid = (int)($_REQUEST["relogio"] ?? 0);
$r = $rid > 0 ? linha("SELECT id, nome, copia_banco FROM relogio WHERE id = ?", [$rid]) : null;
if ($rid > 0 && !$r) {
    $codigo = 404;
    $saida = ["erro" => t("relógio não encontrado")];
} else {
    $saida = ["relogio" => $r ? ["id" => (int)$r["id"], "nome" => $r["nome"], "copia_banco" => (int)$r["copia_banco"] === 1] : null, "pasta_ok" => documentos_pasta() !== null,
        "copia_sistema" => documentos_copia_sistema(),
        "pasta_erro" => documentos_pasta_erro() !== "" ? documentos_pasta_erro() : null, "limite" => documentos_limite(),
        "categorias" => documento_categorias_lista($r ? $rid : null),
        "relogios" => array_map(function ($x) { return ["id" => (int)$x["id"], "nome" => $x["nome"], "documentos" => (int)$x["documentos"]]; },
            linhas("SELECT r.id, r.nome, (SELECT COUNT(*) FROM documento d WHERE d.relogio_id = r.id) AS documentos FROM relogio r ORDER BY r.nome")),
        "documentos" => array_map("documento_info", documentos_do_relogio($r ? $rid : null))];
}
