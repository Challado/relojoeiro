<?php
// recurso=marcas: as marcas da árvore (Cadastros › Marcas), cada uma com o país, o site, a observação e os relógios que
// apontam para ela. Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis
// ($recurso, $acao, $d, $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$saida = ["marcas" => marcas_todas()];
