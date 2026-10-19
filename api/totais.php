<?php
// recurso=totais: os totais (os do rodapé da tabela dos relógios e os da página de totais) calculados nos relógios mostrados.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// os relógios: relogios=1,2,3 (os que a tabela mostra pelo filtro dela); sem, todos os da tabela (na coleção e emprestados)
$rq_t = trim((string)($_REQUEST["relogios"] ?? ""));
$ids_t = $rq_t === "" ? null : array_values(array_unique(array_filter(array_map("intval", explode(",", $rq_t)))));
$conj_t = totais_relogios($ids_t);
// parte=rodape (só os do rodapé), pagina (só os da página de totais) ou teste (nenhum: só o teste da expressao); sem, todos
// (o cadastro)
$parte_t = (string)($_REQUEST["parte"] ?? "");
$lista_t = [];
foreach (totais_todos() as $x) {
    if (($parte_t === "rodape" && !$x["rodape"]) || ($parte_t === "pagina" && !$x["pagina"]) || $parte_t === "teste") {
        continue;
    }
    $x["resultado"] = total_calcular($x["expressao"], $x["formato"], $conj_t);
    $lista_t[] = $x;
}
$saida = ["relogios" => count($conj_t), "relogio_ids" => array_map(function ($c) { return (int)$c["r"]["id"]; }, $conj_t),
    "todos" => count(totais_relogios(null)), "totais" => $lista_t];
// expressao=: testa uma expressão sem gravar (o Testar do cadastro), no formato pedido (formato=; padrão numero)
if (isset($_REQUEST["expressao"])) {
    $f_t = isset(TOTAL_FORMATOS[(string)($_REQUEST["formato"] ?? "")]) ? (string)$_REQUEST["formato"] : "numero";
    $saida["teste"] = trim((string)$_REQUEST["expressao"]) === "" ? ["tipo" => "erro", "erro" => t("Escreva a expressão.")] : total_calcular((string)$_REQUEST["expressao"], $f_t, $conj_t);
}
if ($parte_t !== "rodape" && $parte_t !== "teste") {
    // o que o cadastro mostra: as colunas, os formatos, as funções de total e as variáveis da tabela
    $saida["colunas"] = array_map(function ($k, $n) { return ["chave" => $k, "nome" => $n]; }, array_keys(total_colunas_nomes()), total_colunas_nomes());
    $saida["formatos"] = array_map(function ($k, $n) { return ["chave" => $k, "nome" => $n]; }, array_keys(total_formatos_nomes()), total_formatos_nomes());
    $saida["funcoes"] = array_map(function ($k) { return ["nome" => $k, "descricao" => t($GLOBALS["FUNCOES_TOTAIS"][$k][2])]; }, array_keys($GLOBALS["FUNCOES_TOTAIS"]));
    $saida["variaveis"] = array_map(function ($k) { return ["nome" => $k, "descricao" => t($GLOBALS["VARIAVEIS_TABELA"][$k][0])]; }, array_keys($GLOBALS["VARIAVEIS_TABELA"]));
}
