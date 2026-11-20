<?php
// recurso=vistorias: a vistoria periódica (a página Vistorias; a Configuração, vistoria_dias). Os relógios ativos com o aviso
// Vistoria, do mais atrasado ao mais distante: até o dia ate (AAAA-MM-DD; padrão: daqui a 7 dias; os atrasados sempre), ou
// todos (todos=1). Cada um com o vencimento (pela fórmula do aviso no cadastro), o estado (atrasado, hoje, proxima) e a
// última vistoria. Desligada (vistoria_dias 0 ou o aviso desligado), ativa false e o motivo. Registrar várias de uma vez:
// POST recurso=vistorias, acao=registrar (op_vistorias).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$todos_v = (string)($_REQUEST["todos"] ?? "") === "1";
$ate_v = trim((string)($_REQUEST["ate"] ?? ""));
$ate_dt = $ate_v === "" ? hoje_da_colecao()->modify("+7 days") : DateTimeImmutable::createFromFormat("!Y-m-d", $ate_v);
if (!$todos_v && (!$ate_dt || ($ate_v !== "" && $ate_dt->format("Y-m-d") !== $ate_v))) {
    $codigo = 400;
    $saida = ["erro" => t("Até: AAAA-MM-DD (vazio: daqui a 7 dias), ou todos=1.")];
} else {
    $saida = vistorias_lista($todos_v ? null : $ate_dt->format("Y-m-d"));
}
