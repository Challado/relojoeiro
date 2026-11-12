<?php
// recurso=galeria: a coleção para ver e imprimir, por grupo: cada relógio com a foto, a marca e a referência, o ano e o valor
// da compra e os dias de uso. Sem nada, os da coleção (na coleção e emprestados); aba=<situação>: só os dessa situação (as
// abas da tela: uma por situação do cadastro que tem relógio); aba=principal: a primeira aba; aba=todos (ou posse=todos,
// como antes): todos, também os desejados e os que saíram.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$aba_g = trim((string)($_REQUEST["aba"] ?? ""));
$todos_g = ($_REQUEST["posse"] ?? "") === "todos" || $aba_g === "todos";
$relogios_g = linhas("SELECT * FROM relogio ORDER BY nome");
$abas_g = abas_situacao($relogios_g);
if ($aba_g === "principal") {
    $aba_g = (string)($abas_g[0]["chave"] ?? "");
}
// a aba pedida tem de existir (uma das abas: a opção abas_vazias diz se a situação sem relógio é uma); a que não existe é um erro, não a coleção inteira
if ($aba_g !== "" && !$todos_g && !in_array($aba_g, array_column($abas_g, "chave"), true)) {
    $codigo = 400;
    $saida = ["erro" => t("aba: {1}, principal (a primeira) ou todos.", [1 => implode(", ", array_column($abas_g, "chave"))])];
    return;
}
$agora_g = time();
$n_g = nos_todos();
$fotos_g = [];
foreach (linhas("SELECT relogio_id, atualizado FROM foto") as $f) {
    $fotos_g[(int)$f["relogio_id"]] = strtotime($f["atualizado"]);
}
$grupos_g = [];
$total_g = 0;
$valor_g = 0.0;
$com_foto_g = 0;
foreach ($relogios_g as $r) {
    if (!$todos_g && ($aba_g !== "" ? aba_do_relogio($r) !== $aba_g : !da_colecao($r))) {
        continue;
    }
    $v = valores_do_relogio((int)$r["id"]);
    $c = ["r" => $r, "momento" => $agora_g, "rastro" => [], "pilha" => [], "valores" => $v];
    $du = isset(formulas_todas()["dias_de_uso"]) ? variavel_valor("dias_de_uso", $c) : null;
    $caminho = $r["no_id"] === null ? t("(na raiz)") : no_caminho($r["no_id"]);
    if (!isset($grupos_g[$caminho])) {
        $grupos_g[$caminho] = ["caminho" => $caminho, "relogios" => []];
    }
    $valor = ($v["valor_compra"] ?? "") !== "" ? (float)$v["valor_compra"] : null;
    $grupos_g[$caminho]["relogios"][] = ["id" => (int)$r["id"], "nome" => $r["nome"],
        "tipo" => isset($n_g[(int)$r["no_id"]]) ? $n_g[(int)$r["no_id"]]["nome"] : t("sem grupo"),
        "posse" => (string)$r["posse"], "posse_texto" => posse_texto($r), "posse_comportamento" => posse_de($r), "aba" => aba_do_relogio($r), "foto" => $fotos_g[(int)$r["id"]] ?? null,
        "marca" => ($v["marca"] ?? "") !== "" ? $v["marca"] : null, "referencia" => ($v["referencia"] ?? "") !== "" ? $v["referencia"] : null,
        "ano_compra" => ($v["data_compra"] ?? "") !== "" ? (int)substr((string)$v["data_compra"], 0, 4) : null, "valor_compra" => $valor,
        "dias_de_uso" => is_numeric($du) ? (int)round((float)$du) : null];
    $total_g++;
    $com_foto_g += isset($fotos_g[(int)$r["id"]]) ? 1 : 0;
    $valor_g += $valor ?? 0;
}
ksort($grupos_g);
$saida = ["gerado_em" => date("Y-m-d H:i:s", $agora_g), "todos" => $todos_g, "total" => $total_g, "valor_total" => round($valor_g, 2),
    "com_foto" => $com_foto_g,
    // as abas da tela (as de abas_situacao, e Todos no fim) e a que esta resposta mostra (vazio: os da coleção)
    "abas" => array_merge($abas_g, [["chave" => "todos", "nome" => t("Todos"), "comportamento" => null, "tipo" => "todos", "quantos" => count($relogios_g), "disponivel" => true, "motivo" => null]]),
    "aba" => $todos_g ? "todos" : $aba_g,
    "grupos" => array_values($grupos_g)];
