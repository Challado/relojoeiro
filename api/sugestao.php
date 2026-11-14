<?php
// recurso=sugestao: a Sugestão para agora (o cartão da página Hoje; a opção sugestao_agora da Configuração o mostra): um
// relógio para usar agora, fora da escala, sem mexer no plano e sem gravar nada. Parâmetros: forma= (uma das formas de
// escolha: inteligente, ponderado, aleatorio, fifo, menos_usado; vazio: inteligente), tipos= (os ids dos tipos, os grupos
// da árvore, separados por vírgula; 0: os que estão na raiz, sem grupo; vazio: todos) e pular= (os ids dos relógios já sugeridos, separados por vírgula: a
// Outra sugestão). Ficam de fora o que está no pulso agora e o que está com a carga no limite ou abaixo dele.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// uma lista de ids do pedido (1,2,3): [os ids, se a lista está bem escrita]. $zero: o 0 vale (o tipo dos que estão na raiz)
$ids_sg = function ($bruto, $zero = false) {
    $bruto = trim((string)$bruto);
    if ($bruto === "") {
        return [[], true];
    }
    $partes = array_map("trim", explode(",", $bruto));
    foreach ($partes as $p_sg) {
        if (!ctype_digit($p_sg) || ((int)$p_sg <= 0 && !$zero)) {
            return [[], false];
        }
    }
    return [array_values(array_unique(array_map("intval", $partes))), true];
};
$forma_sg = trim((string)($_REQUEST["forma"] ?? ""));
$forma_sg = $forma_sg === "" ? "inteligente" : $forma_sg;
[$tipos_sg, $tipos_ok_sg] = $ids_sg($_REQUEST["tipos"] ?? "", true);
[$pular_sg, $pular_ok_sg] = $ids_sg($_REQUEST["pular"] ?? "");
$tipos_todos_sg = sugestao_tipos();
if (!isset(formas_de_escolha()[$forma_sg])) {
    $codigo = 400;
    $saida = ["erro" => t("forma: uma de {1}.", [1 => implode(", ", array_keys(formas_de_escolha()))])];
} elseif (!$tipos_ok_sg || count(array_diff($tipos_sg, array_column($tipos_todos_sg, "id"))) > 0) {
    $codigo = 400;
    $saida = ["erro" => t("tipos: os ids dos tipos (os de tipos[] desta resposta), separados por vírgula.")];
} elseif (!$pular_ok_sg) {
    $codigo = 400;
    $saida = ["erro" => t("pular: os ids dos relógios já sugeridos, separados por vírgula.")];
} else {
    $saida = ["ativa" => cfg("sugestao_agora") === "1",
        "formas" => array_map(function ($k, $n) { return ["chave" => $k, "nome" => $n]; }, array_keys(formas_de_escolha()), formas_de_escolha()),
        "tipos" => $tipos_todos_sg] + sugestao_agora($forma_sg, $tipos_sg, $pular_sg);
}
