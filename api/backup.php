<?php
// recurso=backup: as cópias de segurança da pasta, e uma cópia para baixar (arquivo=).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// a cópia de segurança do banco: a situação e as cópias da pasta; arquivo=<nome> baixa uma (fazer e restaurar: na escrita)
$arq_b = (string)($_REQUEST["arquivo"] ?? "");
if ($arq_b !== "") {
    $caminho_b = backup_caminho($arq_b);
    if ($caminho_b !== null) {
        header("Content-Type: application/gzip");
        header("Content-Disposition: attachment; filename=\"" . $arq_b . "\"");
        header("Content-Length: " . filesize($caminho_b));
        readfile($caminho_b);
        exit;
    }
    $codigo = 404;
    $saida = ["erro" => t("cópia não encontrada")];
} else {
    $saida = ["pasta_ok" => backup_pasta() !== null, "pasta_erro" => backup_pasta_erro() !== "" ? backup_pasta_erro() : null, "manter" => backup_manter(),
        "documentos" => backup_com_documentos(), "versao" => array_key_last($MIGRACOES), "ultima" => cfg("backup_ultima") !== "" ? cfg("backup_ultima") : null,
        "copias" => backup_lista()];
}
