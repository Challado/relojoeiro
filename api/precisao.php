<?php
// recurso=precisao: a marcha dos relógios. relogio=<id>: a precisão dele inteira (as leituras do desvio da hora e os acertos,
// os intervalos com a marcha de cada um, o resumo, a marcha por posição e a esperada); sem relogio: um resumo de cada relógio
// da coleção que tem o tipo de lançamento que mede a marcha.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$agora_p = time();
if ((string)($_REQUEST["relogio"] ?? "") !== "") {
    $r_p = linha("SELECT * FROM relogio WHERE id = ?", [(int)$_REQUEST["relogio"]]);
    if (!$r_p) {
        $codigo = 404;
        $saida = ["erro" => t("relógio não encontrado")];
    } else {
        $saida = ["relogio_id" => (int)$r_p["id"], "relogio" => $r_p["nome"], "precisao" => precisao_do_relogio($r_p, $agora_p)];
    }
} else {
    $lista_p = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome, id") as $r_p) {
        $p_p = da_colecao($r_p) ? precisao_do_relogio($r_p, $agora_p) : null;
        if ($p_p !== null) {
            $lista_p[] = ["relogio_id" => (int)$r_p["id"], "relogio" => $r_p["nome"], "medicoes" => $p_p["medicoes"], "marcha" => $p_p["marcha"], "marcha_texto" => $p_p["marcha_texto"],
                "ultima" => $p_p["ultima"], "ultima_texto" => $p_p["ultima_texto"], "desvio_agora" => $p_p["desvio_agora"], "desvio_agora_texto" => $p_p["desvio_agora_texto"],
                "esperada" => $p_p["esperada"]["texto"], "dentro" => $p_p["dentro"], "resumo" => $p_p["resumo"]];
        }
    }
    $saida = ["relogios" => $lista_p];
}
