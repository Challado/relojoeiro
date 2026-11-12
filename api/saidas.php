<?php
// recurso=saidas: as saídas da coleção (os relógios emprestados e os que saíram: vendidos, presenteados, arquivados e os
// motivos do cadastro), cada uma com o relógio, a situação, as datas, o valor recebido, para quem foi e as contas (a compra,
// os serviços, o resultado, o tempo na coleção e os dias de uso), e as somas das de agora por situação. Filtros: relogio=
// (o histórico de um), situacao= (o identificador de uma) e abertas=1 (só as de agora).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$f_s = [];
if (ctype_digit((string)($_REQUEST["relogio"] ?? ""))) {
    $f_s["relogio"] = (int)$_REQUEST["relogio"];
}
if (trim((string)($_REQUEST["situacao"] ?? "")) !== "") {
    $f_s["situacao"] = trim((string)$_REQUEST["situacao"]);
}
if ((string)($_REQUEST["abertas"] ?? "") === "1") {
    $f_s["abertas"] = true;
}
$lista_s = saidas_lista($f_s);
$saida = ["disponivel" => saidas_tem(), "saidas" => $lista_s, "somas" => saidas_somas($lista_s),
    "abertas" => count(array_filter($lista_s, function ($x) { return $x["aberta"]; })),
    "historico" => count(array_filter($lista_s, function ($x) { return !$x["aberta"]; })),
    // a situação que põe o relógio de volta na coleção (o botão Voltar para a coleção grava com ela, pelo recurso=relogio)
    "posse_colecao" => lista_com_comportamento("relogio_situacao", "colecao")["identificador"] ?? "colecao"];
