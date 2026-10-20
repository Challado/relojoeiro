<?php
// recurso=documentos: os documentos de um relógio (ou de todos os relógios), ou de um calibre, as categorias e o limite.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// os documentos de um relógio (relogio=<id>; sem ele, de todos os relógios) ou de um calibre (calibre=<id>: o manual
// técnico, a ficha de serviço... que valem para todos os relógios com ele), com as categorias, os relógios, os calibres, a
// pasta e o limite
$rid = (int)($_REQUEST["relogio"] ?? 0);
$cid_d = (int)($_REQUEST["calibre"] ?? 0);
$r = $rid > 0 ? linha("SELECT id, nome, copia_banco FROM relogio WHERE id = ?", [$rid]) : null;
$cal_d = $cid_d > 0 ? linha("SELECT id, nome, fabricante FROM calibre WHERE id = ?", [$cid_d]) : null;
if ($rid > 0 && !$r) {
    $codigo = 404;
    $saida = ["erro" => t("relógio não encontrado")];
} elseif ($cid_d > 0 && !$cal_d) {
    $codigo = 404;
    $saida = ["erro" => t("calibre não encontrado")];
} else {
    // no relógio, os documentos do calibre dele vêm à parte (do_calibre): valem para ele, mas são do calibre
    $do_cal = $r ? calibre_do_relogio($rid) : null;
    $saida = ["relogio" => $r ? ["id" => (int)$r["id"], "nome" => $r["nome"], "copia_banco" => (int)$r["copia_banco"] === 1] : null,
        "calibre" => $cal_d ? ["id" => (int)$cal_d["id"], "nome" => $cal_d["nome"], "fabricante" => $cal_d["fabricante"],
            "relogios" => array_map(function ($x) { return ["id" => (int)$x["id"], "nome" => $x["nome"]]; },
                linhas("SELECT r.id, r.nome FROM relogio_calibre rc JOIN relogio r ON r.id = rc.relogio_id WHERE rc.calibre_id = ? ORDER BY r.nome, r.id", [$cid_d]))] : null,
        "pasta_ok" => documentos_pasta() !== null,
        "copia_sistema" => documentos_copia_sistema(),
        "pasta_erro" => documentos_pasta_erro() !== "" ? documentos_pasta_erro() : null, "limite" => documentos_limite(),
        "categorias" => documento_categorias_lista($r ? $rid : null, $cal_d ? $cid_d : null),
        "relogios" => array_map(function ($x) { return ["id" => (int)$x["id"], "nome" => $x["nome"], "documentos" => (int)$x["documentos"]]; },
            linhas("SELECT r.id, r.nome, (SELECT COUNT(*) FROM documento d WHERE d.relogio_id = r.id) AS documentos FROM relogio r ORDER BY r.nome")),
        "calibres" => array_map(function ($x) { return ["id" => (int)$x["id"], "nome" => $x["nome"], "documentos" => (int)$x["documentos"]]; },
            linhas("SELECT c.id, c.nome, (SELECT COUNT(*) FROM documento d WHERE d.calibre_id = c.id) AS documentos FROM calibre c ORDER BY c.nome, c.id")),
        "documentos" => array_map("documento_info", $cal_d ? documentos_do_calibre($cid_d) : documentos_do_relogio($r ? $rid : null)),
        "do_calibre" => $do_cal ? ["id" => (int)$do_cal["id"], "nome" => $do_cal["nome"], "documentos" => array_map("documento_info", documentos_do_calibre((int)$do_cal["id"]))] : null];
}
