<?php
// recurso=plano: o plano dia a dia, o resumo por relógio e as páginas.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$de_p = preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}\$/", (string)($_REQUEST["de"] ?? "")) === 1 ? (string)$_REQUEST["de"] : "";
$ate_p = preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}\$/", (string)($_REQUEST["ate"] ?? "")) === 1 ? (string)$_REQUEST["ate"] : "";
// dias=N: os próximos N dias, de hoje em diante (no lugar de de e ate); dias=todos: de hoje até o fim do plano
$dias_p = strtolower(trim((string)($_REQUEST["dias"] ?? "")));
if ($dias_p === "todos") {
    $de_p = hoje_da_colecao()->format("Y-m-d");
    $ate_p = "";
} elseif (ctype_digit($dias_p) && (int)$dias_p > 0) {
    $de_p = hoje_da_colecao()->format("Y-m-d");
    $ate_p = date("Y-m-d", strtotime("+" . ((int)$dias_p - 1) . " days"));
}
$plano_p = plano_legivel($de_p, $ate_p);
$rel_p = ctype_digit((string)($_REQUEST["relogio"] ?? "")) && (int)$_REQUEST["relogio"] > 0 ? (int)$_REQUEST["relogio"] : null;
$saida = ["modo" => valor("SELECT nome FROM modo WHERE id = ?", [modo_ativo()]),
    // o dia a dia da página, uma página por vez (o "Mostrar até" da Configuração), só de um relógio com relogio=<id>
    "dia_a_dia" => plano_pagina($de_p, $ate_p, $rel_p, (int)($_REQUEST["pagina"] ?? 1),
        ctype_digit((string)($_REQUEST["por_pagina"] ?? "")) ? (int)$_REQUEST["por_pagina"] : plano_por_pagina()), "escala_fim" => cfg("escala_fim") !== "" ? cfg("escala_fim") : null,
    "plano_fim" => valor("SELECT MAX(data) FROM plano"), "hoje" => hoje_da_colecao()->format("Y-m-d"), "plano" => $plano_p, "livres" => dias_livres($de_p, $ate_p),
    "resumo" => plano_resumo($plano_p, hoje_da_colecao()->format("Y-m-d")),
    "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1]; },
        linhas("SELECT id, nome, disponivel FROM relogio ORDER BY nome"))];
