<?php
// recurso=documento: um arquivo de um documento (o original, a miniatura, a página de um PDF).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// o arquivo de um documento (não é JSON): baixar=1 força o download; mini=1, a miniatura da foto (sem ela, a foto)
$doc = linha("SELECT * FROM documento WHERE id = ?", [(int)($_REQUEST["id"] ?? 0)]);
$pasta = documentos_pasta();
$mini = ($_REQUEST["mini"] ?? "") === "1" && $doc && $doc["miniatura"] !== null && $doc["miniatura"] !== "";
$caminho = $doc && $pasta !== null ? $pasta . "/" . ($mini ? $doc["miniatura"] : $doc["arquivo"]) : null;
// o arquivo sumiu da pasta: volta da cópia no banco antes de sair
if ($caminho !== null && strpos($caminho, "..") === false && !is_file($caminho)) {
    documento_restaurar($doc);
}
if ($caminho !== null && strpos($caminho, "..") === false && is_file($caminho)) {
    documento_enviar($doc, $caminho, ($_REQUEST["baixar"] ?? "") === "1", $mini);
    exit;
}
$codigo = 404;
$saida = ["erro" => $doc ? "o arquivo do documento não está na pasta dos documentos" . ($pasta === null ? " (" . documentos_pasta_erro() . ")" : ((int)$doc["no_banco"] === 1 ? " e a cópia no banco não pôde ser recriada" : " e não tem cópia no banco")) : "documento não encontrado"];
