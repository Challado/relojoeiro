<?php
// recurso=codigo: o código do sistema como está agora, arquivo por arquivo.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// o código do sistema como está agora, lido do disco (a lista e o que cada arquivo é ficam no reconstrucao.php): a lista
// (conteudo=1: com o conteúdo de todos), um arquivo (arquivo=), o arquivo puro (cru=1) e o HTML de uma página (html=1)
require_once dirname(__DIR__) . "/reconstrucao.php";
$arq = (string)($_REQUEST["arquivo"] ?? "");
$html = ($_REQUEST["html"] ?? "") === "1";
$fora = array_values(array_filter(RECONSTRUCAO_NAO_MOSTRADOS, function ($x) use ($arq) { return $x["arquivo"] === $arq; }));
if ($arq === "") {
    $saida = codigo_lista(($_REQUEST["conteudo"] ?? "") === "1");
} elseif (!isset(codigo_arquivos()[$arq])) {
    $codigo = 404;
    $saida = count($fora) > 0 ? ["erro" => t("{1} não sai pela API: {2}", [1 => $arq, 2 => $fora[0]["por_que"]]), "o_que_ver" => $fora[0]["o_que_ver"]]
        : ["erro" => t("esse arquivo não é do sistema (os que saem estão em arquivos)"), "arquivos" => array_keys(codigo_arquivos())];
} elseif ($html && !in_array($arq, codigo_paginas(), true)) {
    $codigo = 400;
    $saida = ["erro" => t("html=1 só vale para uma página: {1}", [1 => implode(", ", codigo_paginas())])];
} elseif (($_REQUEST["cru"] ?? "") === "1") {
    // puro, sem JSON: o texto como text/plain (o navegador mostra, não roda), a imagem como imagem
    header("Content-Type: " . ($html ? "text/plain; charset=utf-8" : codigo_tipo($arq)[2]));
    header("X-Content-Type-Options: nosniff");
    echo $html ? codigo_html($arq) : file_get_contents(dirname(__DIR__) . "/" . $arq);
    exit;
} else {
    $saida = codigo_arquivo($arq, true);
}
