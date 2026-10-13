<?php
// as escritas (POST): cada recurso e ação vai para a função dele no operacoes.php, que valida, grava e responde.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$ops = ["arvore" => "op_arvore", "campos" => "op_campos", "lancamento_tipos" => "op_lancamento_tipos", "formulas" => "op_formulas", "relogio" => "op_relogio",
    "lancamento" => "op_lancamento", "avisos" => "op_avisos", "criterios" => "op_criterios",
    "rodizio" => "op_rodizio", "modos" => "op_modos", "config" => "op_config", "documentos" => "op_documentos", "documento_categorias" => "op_documento_categorias"];
if (isset($ops[$recurso])) {
    $saida = $ops[$recurso]($acao, $d);
} elseif ($recurso === "usuarios") {
    $saida = op_usuarios($acao, $d, $quem !== "" ? $quem : "(token da API)");
} elseif ($recurso === "importacao") {
    // a importação do sistema anterior pode demorar (o histórico inteiro): sem o limite de tempo do PHP
    @set_time_limit(0);
    $saida = op_importacao($acao, $d);
} elseif ($recurso === "backup") {
    // a cópia de segurança: fazer agora, ou restaurar uma (as duas podem demorar num banco grande)
    @set_time_limit(0);
    $saida = $acao === "fazer" ? backup_fazer() : ($acao === "restaurar" ? backup_restaurar((string)($d["arquivo"] ?? ""))
        : ["ok" => false, "mensagem" => "", "erros" => ["Ação desconhecida: use fazer ou restaurar."]]);
} else {
    $saida = ["ok" => false, "mensagem" => "", "erros" => ["este recurso não aceita escrita; os que aceitam: " . implode(", ", array_merge(array_keys($ops), ["usuarios", "importacao", "backup", "instalacao", "migracoes"]))]];
}
$codigo = $saida["ok"] ? 200 : 400;
