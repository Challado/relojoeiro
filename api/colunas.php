<?php
// recurso=colunas: as colunas cadastradas da tabela dos relógios (Cadastros › Colunas) e o que o construtor delas precisa: o
// dicionário de fontes (as tabelas do banco ligadas ao relógio, cada campo com o tipo e a descrição e as funções que aceita), as
// colunas da tabela dos relógios e os campos do cadastro que dão uma coluna sem função, as funções, os formatos e as variáveis do
// modo expert. Com expressao (modo expert) ou tabela, campo e funcao (modo guiado), mais formato, casas e simbolo, mostra o teste
// de uma coluna num relógio (relogio=<id>; sem ele, o primeiro da coleção), sem gravar. Parte do api.php: roda dentro dele (o
// require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d, $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$formatos_c = coluna_formatos();
$saida = [
    "colunas" => array_map(function ($c) use ($formatos_c) {
        return ["id" => $c["id"], "chave" => $c["chave"], "identificador" => $c["identificador"], "nome" => $c["nome"], "modo" => $c["modo"], "tabela" => $c["tabela"],
            "campo" => $c["campo"], "funcao" => $c["funcao"], "expressao" => $c["expressao"], "formato" => $c["formato"], "formato_nome" => $formatos_c[$c["formato"]] ?? $c["formato"],
            "casas" => $c["casas"], "simbolo" => $c["simbolo"], "padrao" => $c["padrao"], "ordem" => $c["ordem"]];
    }, colunas_cadastradas()),
    "tabelas" => fontes_dicionario(),
    // a tabela dos relógios: cada coluna dela (a variável, o que é, a unidade e o formato que combina)
    "relogio" => array_map(function ($k) { return ["campo" => $k, "descricao" => t($GLOBALS["VARIAVEIS_TABELA"][$k][0]), "unidade" => $GLOBALS["VARIAVEIS_TABELA"][$k][1], "formato" => coluna_formato_sugerido("relogio", $k, null)]; },
        array_keys($GLOBALS["VARIAVEIS_TABELA"])),
    // os campos do cadastro dos relógios (Cadastros › Campos): cada um vale como coluna, sem função
    "campos" => array_map(function ($c) { return ["campo" => $c["identificador"], "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "formato" => coluna_formato_sugerido("campos", $c["identificador"], null)]; },
        array_values(campos_todos())),
    "funcoes" => array_map(function ($k) { return ["chave" => $k, "nome" => t($GLOBALS["FONTES_FUNCOES"][$k][0]), "descricao" => t($GLOBALS["FONTES_FUNCOES"][$k][2])]; }, array_keys($GLOBALS["FONTES_FUNCOES"])),
    "formatos" => array_map(function ($k, $n) { return ["chave" => $k, "nome" => $n]; }, array_keys($formatos_c), $formatos_c),
    "funcoes_expressao" => array_map(function ($k) { return ["nome" => $k, "descricao" => t($GLOBALS["FUNCOES"][$k][2] ?? "")]; }, array_keys(array_filter($GLOBALS["FUNCOES"], function ($f, $k) { return !isset($GLOBALS["FUNCOES_TOTAIS"][$k]); }, ARRAY_FILTER_USE_BOTH))),
];

// o teste: a coluna montada do pedido, calculada num relógio, sem gravar
if (isset($_REQUEST["expressao"]) || isset($_REQUEST["tabela"])) {
    $modo_c = (string)($_REQUEST["modo"] ?? (isset($_REQUEST["tabela"]) && trim((string)$_REQUEST["tabela"]) !== "" ? "guiado" : "expert"));
    $erros_c = [];
    $expr_c = trim((string)($_REQUEST["expressao"] ?? ""));
    $tab_c = trim((string)($_REQUEST["tabela"] ?? ""));
    $cam_c = trim((string)($_REQUEST["campo"] ?? ""));
    $fun_c = trim((string)($_REQUEST["funcao"] ?? ""));
    if ($modo_c === "guiado") {
        [$erros_c, $expr_g] = coluna_expressao_guiada($tab_c, $cam_c, $fun_c === "" ? null : $fun_c);
        $expr_c = $expr_g ?? "";
    } elseif ($expr_c === "") {
        $erros_c[] = t("Escreva a expressão.");
    } else {
        $erros_c = relatorio_expressao_validar($expr_c);
    }
    $fmt_c = trim((string)($_REQUEST["formato"] ?? ""));
    if ($fmt_c === "") {
        $fmt_c = $modo_c === "guiado" && count($erros_c) === 0 ? coluna_formato_sugerido($tab_c, $cam_c, $fun_c === "" ? null : $fun_c) : "numero";
    }
    if (!isset($formatos_c[$fmt_c])) {
        $erros_c[] = t("Formato: {1}.", [1 => implode(", ", array_keys($formatos_c))]);
    }
    $rid_c = (int)($_REQUEST["relogio"] ?? 0);
    $conj_c = totais_relogios($rid_c > 0 ? [$rid_c] : null, "colecao");
    if (count($erros_c) > 0) {
        $saida["teste"] = ["tipo" => "erro", "erro" => implode("; ", $erros_c)];
    } elseif (count($conj_c) === 0) {
        $saida["teste"] = ["tipo" => "erro", "erro" => t("Não há relógio para testar.")];
    } else {
        $ctx_c = $conj_c[0];
        $col_c = ["expressao" => $expr_c, "formato" => $fmt_c, "casas" => ctype_digit((string)($_REQUEST["casas"] ?? "")) ? (int)$_REQUEST["casas"] : null,
            "simbolo" => trim((string)($_REQUEST["simbolo"] ?? "")) === "" ? null : trim((string)$_REQUEST["simbolo"])];
        [$v_c, $txt_c] = coluna_valor($col_c, $ctx_c);
        $saida["teste"] = ["tipo" => "valor", "expressao" => $expr_c, "formato" => $fmt_c, "relogio_id" => (int)$ctx_c["r"]["id"], "relogio" => $ctx_c["r"]["nome"], "valor" => $v_c, "texto" => $txt_c];
    }
}
