<?php
// recurso=opcoes: as opções em três níveis de um assunto (grupo=rotina, rodizio, limites, avisos ou telas; vários, separados por
// vírgula), cada nível com o que escolheu, o que herda e de onde: a coleção do pedido, o dono dela (Minhas coleções) e o sistema
// (só o master). É o que as páginas Rodízio › Ajustes do rodízio e Avisos mostram; a gravação é a da Configuração
// (recurso=config, acao=salvar: <chave>, dono[<chave>] e padroes[<chave>]).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$todos_g = array_keys(opcoes_textos()["grupos"]);
$pedidos_g = array_values(array_filter(array_map("trim", explode(",", (string)($_GET["grupo"] ?? "")))));
$errados_g = array_values(array_diff($pedidos_g, $todos_g));
if (count($pedidos_g) === 0 || count($errados_g) > 0) {
    $codigo = 400;
    $saida = ["erro" => t("grupo: um ou mais destes, separados por vírgula: {1}.", [1 => implode(", ", $todos_g)])];
} else {
    $saida = opcoes_niveis($pedidos_g);
}
