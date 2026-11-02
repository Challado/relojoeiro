<?php
// recurso=sql (só o master): sem nada, o que o SQL pela API aceita agora (o banco, se UPDATE e DELETE estão aprovados, o
// limite de linhas) e o registro das últimas execuções (limite: quantas, padrão 50, até 500); com tabela=<nome>, as
// colunas dela. Rodar um comando é a escrita (POST recurso=sql, acao=executar, comando, limite).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

if (!usuario_master()) {
    $codigo = 403;
    $saida = ["erro" => t("o SQL pela API é só do master")];
    return;
}
$quantas_s = max(1, min(500, (int)($_REQUEST["limite"] ?? 50)));
$saida = ["banco" => banco_tipo(), "aprovado_alterar" => sql_alteracoes_aprovadas(), "aceitos" => sql_alteracoes_aprovadas() ? SQL_TIPOS : ["select", "insert", "alter"],
    "limite_padrao" => SQL_LIMITE_PADRAO, "limite_maximo" => SQL_LIMITE_MAXIMO,
    "tabelas" => sem_escopo(function () { $t = banco_tabelas(); sort($t); return $t; }),
    "execucoes" => sem_escopo(function () use ($quantas_s) {
        return array_map(function ($x) {
            return ["id" => (int)$x["id"], "quando" => $x["quando"], "usuario" => $x["login"], "comando" => $x["comando"], "tipo" => $x["tipo"], "ok" => (int)$x["ok"] === 1,
                "linhas" => $x["linhas"] === null ? null : (int)$x["linhas"], "duracao_ms" => (int)$x["duracao_ms"], "erro" => $x["erro"]];
        }, linhas("SELECT e.*, u.login FROM sql_execucao e LEFT JOIN usuario u ON u.id = e.usuario_id ORDER BY e.id DESC LIMIT " . $quantas_s));
    })];
$tabela_s = trim((string)($_REQUEST["tabela"] ?? ""));
if ($tabela_s !== "") {
    if (!in_array($tabela_s, $saida["tabelas"], true)) {
        $codigo = 404;
        $saida = ["erro" => t("A tabela {1} não existe.", [1 => $tabela_s])];
        return;
    }
    $saida["colunas"] = sem_escopo(function () use ($tabela_s) { return sql_colunas_da_tabela($tabela_s); });
}
