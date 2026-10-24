<?php
// recurso=listas: as listas que são cadastro, na árvore da coleção (Manutenção › Listas e Cadastros › Listas; pagina=
// manutencao ou cadastros: só as de uma página; lista=<identificador>: só uma). Cada lista com o nome, a página, o que quer
// dizer o texto do item (null: sem texto), se tem cor, os identificadores reservados, o que cada item pode fazer (os
// comportamentos) e os itens, na ordem, com quantas vezes cada um está em uso (o que está em uso não sai).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$pagina_l = (string)($_REQUEST["pagina"] ?? "");
$so_l = (string)($_REQUEST["lista"] ?? "");
$raiz_l = no_raiz();
$saida = ["listas" => array_values(array_map(function ($k) use ($raiz_l) {
    $def = LISTAS[$k];
    return ["lista" => $k, "nome" => t($def["nome"]), "pagina" => $def["pagina"], "texto" => $def["texto"] === null ? null : t($def["texto"]), "cor" => $def["cor"],
        "reservados" => $def["reservados"], "essenciais" => LISTAS_ESSENCIAIS[$k] ?? [],
        "comportamentos" => array_map(function ($c, $txt) { return ["valor" => $c, "nome" => t($txt)]; }, array_keys($def["comportamentos"]), array_values($def["comportamentos"])),
        "itens" => array_map(function ($x) use ($k, $raiz_l) {
            return array_merge($x, ["identificador" => $x["valor"], "em_uso" => lista_item_em_uso(["id" => $x["id"], "identificador" => $x["valor"], "lista" => $k], $raiz_l)]);
        }, lista_opcoes($k))];
}, array_values(array_filter(array_keys(LISTAS), function ($k) use ($pagina_l, $so_l) {
    return ($pagina_l === "" || LISTAS[$k]["pagina"] === $pagina_l) && ($so_l === "" || $k === $so_l);
}))))];
