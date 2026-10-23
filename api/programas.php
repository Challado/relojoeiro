<?php
// recurso=programas: os programas dos winders (a página Programas: o cadastro, a sequência, o cálculo e as posições que usam
// cada um), os movimentos (a página Movimentos: os programas que usam cada um), os tipos de movimento e os tipos de
// cadastro. Com previa=1, o cálculo de um programa que não foi gravado (os campos do salvar: medida, velocidade,
// tpd_manual, funciona, liga_horas, para_horas, sequencia; com id, o que não vier é o dele): o que a tela mostra enquanto a
// pessoa muda, sem gravar nada.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

if ((string)($_REQUEST["previa"] ?? "") === "1") {
    $id_pv = (int)($_REQUEST["id"] ?? 0);
    $atual_pv = $id_pv > 0 ? linha("SELECT * FROM winder_programa WHERE id = ?", [$id_pv]) : null;
    [$p_pv, $seq_pv, $movs_pv, $erros_pv] = winder_programa_do_pedido($_REQUEST, $atual_pv, true);
    $calc_pv = winder_programa_calculo($p_pv, $movs_pv);
    $calc_pv["erros"] = array_values(array_unique(array_merge($erros_pv, $calc_pv["erros"])));
    $saida = ["calculo" => $calc_pv, "sequencia" => $seq_pv];
} else {
    $saida = ["programas" => winder_programas_lista(), "movimentos" => winder_movimentos_lista(),
        "tipos" => array_map(function ($k) { return ["valor" => $k, "nome" => t(WINDER_MOVIMENTO_TIPOS[$k])]; }, array_keys(WINDER_MOVIMENTO_TIPOS)),
        "medidas" => array_map(function ($k) { return ["valor" => $k, "nome" => t(WINDER_PROGRAMA_MEDIDAS[$k])]; }, array_keys(WINDER_PROGRAMA_MEDIDAS))];
}
