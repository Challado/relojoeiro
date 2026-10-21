<?php
// as escritas (POST): cada recurso e ação vai para a função dele no operacoes.php, que valida, grava e responde.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$ops = ["arvore" => "op_arvore", "campos" => "op_campos", "lancamento_tipos" => "op_lancamento_tipos", "formulas" => "op_formulas", "totais" => "op_totais", "calibres" => "op_calibres", "unidades" => "op_unidades", "relogio" => "op_relogio",
    "lancamento" => "op_lancamento", "avisos" => "op_avisos", "criterios" => "op_criterios",
    "rodizio" => "op_rodizio", "modos" => "op_modos", "config" => "op_config", "documentos" => "op_documentos", "documento_categorias" => "op_documento_categorias",
    "acessorios" => "op_acessorios", "servicos" => "op_servicos", "reserva" => "op_reserva", "timegrapher" => "op_timegrapher", "destinos" => "op_destinos", "agendas" => "op_agendas", "conta" => "op_conta", "colecoes" => "op_colecoes", "acessos" => "op_acessos",
    "convites" => "op_convites", "pedidos" => "op_pedidos"];
// toda exclusão em dois passos: sem confirmar=1, a API diz o que sai junto (impacto_exclusao, no banco inteiro: uma árvore
// serve a várias coleções) e não apaga nada; com confirmar=1, apaga. O item tem de ser da coleção do pedido (lido pela visão
// dela); o que não é, segue para a operação, que responde "não encontrado"
$exclusoes = ["relogio" => ["excluir", "relogio"], "campos" => ["excluir", "campo"], "lancamento_tipos" => ["excluir", "lancamento_tipo"], "formulas" => ["excluir", "formula"], "totais" => ["excluir", "total"], "calibres" => ["excluir", "calibre"], "unidades" => ["excluir", "unidade"],
    "avisos" => ["excluir", "aviso"], "modos" => ["excluir", "modo"], "documentos" => ["excluir", "documento"], "documento_categorias" => ["excluir", "documento_categoria"],
    "acessorios" => ["excluir", "acessorio"], "servicos" => ["excluir", "servico"], "reserva" => ["excluir", "reserva_teste"], "timegrapher" => ["excluir", "timegrapher"], "lancamento" => ["excluir", "lancamento"], "destinos" => ["excluir", "destino"], "agendas" => ["excluir", "agenda"],
    "config" => ["evento_excluir", "evento_personalizado"], "colecoes" => ["excluir", "colecao"]];
$alvo_exc = null;
if (($recurso === "colecoes" && $acao === "arvore_excluir") || (isset($exclusoes[$recurso]) && $acao === $exclusoes[$recurso][0])) {
    $tab_exc = $acao === "arvore_excluir" ? "no" : $exclusoes[$recurso][1];
    $id_exc = (int)($d["id"] ?? ($d["evento_id"] ?? ($d["arvore_id"] ?? 0)));
    // as coleções e as árvores: sem escopo (a coleção é a do pedido, ou outra do mesmo dono); o resto, pela visão da coleção
    $existe_exc = in_array($tab_exc, ["colecao", "no"], true) ? sem_escopo(function () use ($tab_exc, $id_exc) { return valor("SELECT id FROM " . banco_tabela_sql($tab_exc) . " WHERE id = ?", [$id_exc]); })
        : valor("SELECT id FROM " . $tab_exc . " WHERE id = ?", [$id_exc]);
    // a coleção e a árvore: só as do dono (ou o master); as dos outros seguem para a operação, que responde "não encontrada"
    if ($existe_exc !== null && in_array($tab_exc, ["colecao", "no"], true) && !usuario_master()) {
        $dono_exc = sem_escopo(function () use ($tab_exc, $id_exc) { return valor("SELECT dono_id FROM " . banco_tabela_sql($tab_exc) . " WHERE id = ?", [$id_exc]); });
        $existe_exc = usuario_atual() !== null && (int)$dono_exc === (int)usuario_atual()["id"] ? $existe_exc : null;
    }
    if ($existe_exc !== null) {
        $alvo_exc = [$tab_exc, $id_exc];
    }
} elseif ($recurso === "usuarios" && $acao === "excluir") {
    $id_exc = sem_escopo(function () use ($d) { return valor("SELECT id FROM usuario WHERE login = ?", [trim((string)($d["login"] ?? ""))]); });
    $id_exc = $id_exc ?? (int)($d["id"] ?? 0);
    if ($id_exc && usuario_master()) {
        $alvo_exc = ["usuario", (int)$id_exc];
    }
} elseif ($recurso === "conta" && $acao === "excluir" && usuario_atual() !== null) {
    $alvo_exc = ["usuario", (int)usuario_atual()["id"]];
}
if ($recurso === "acessos" && in_array($acao, ["remover", "sair"], true) && (string)($d["confirmar"] ?? "") !== "1") {
    // tirar um acesso não apaga nada além dele: só confirma quem perde o quê
    [$col_a, $dono_a, $erro_a] = alvo_do_pedido($d);
    $quem_a = $acao === "sair" ? usuario_atual() : usuario_por_id((int)($d["usuario_id"] ?? 0));
    if ($erro_a === "" && $quem_a !== null) {
        $txt_a = t("{1} deixa de ver {2}. Nada é apagado.", [1 => $quem_a["login"], 2 => alvo_texto($col_a, $dono_a)]);
        $saida = ["ok" => false, "mensagem" => "", "confirmar" => true, "impacto" => ["itens" => [], "desfeitos" => [], "colecoes" => [], "relogios" => 0, "arquivos" => 0, "bytes" => 0, "texto" => $txt_a],
            "erros" => [t("Confirme a exclusão (confirmar=1). {1}", [1 => $txt_a])]];
        $codigo = 409;
        return;
    }
}
if ($alvo_exc !== null && (string)($d["confirmar"] ?? "") !== "1") {
    $imp = impacto_exclusao($alvo_exc[0], [$alvo_exc[1]]);
    $saida = ["ok" => false, "mensagem" => "", "confirmar" => true, "impacto" => $imp,
        "erros" => [t("Confirme a exclusão (confirmar=1). {1}", [1 => $imp["texto"]])], "id" => $alvo_exc[1]];
} elseif (isset($ops[$recurso])) {
    $saida = $ops[$recurso]($acao, $d);
} elseif ($recurso === "usuarios") {
    $saida = op_usuarios($acao, $d, $quem !== "" ? $quem : t("(token da API)"));
} elseif ($recurso === "importacao") {
    // a importação do sistema anterior pode demorar (o histórico inteiro): sem o limite de tempo do PHP
    @set_time_limit(0);
    $saida = op_importacao($acao, $d);
} elseif ($recurso === "backup") {
    // a cópia de segurança: fazer agora, ou restaurar uma (as duas podem demorar num banco grande)
    @set_time_limit(0);
    $saida = $acao === "fazer" ? backup_fazer() : ($acao === "restaurar" ? backup_restaurar((string)($d["arquivo"] ?? ""))
        : ["ok" => false, "mensagem" => "", "erros" => [t("Ação desconhecida: use fazer ou restaurar.")]]);
} else {
    $saida = ["ok" => false, "mensagem" => "", "erros" => [t("este recurso não aceita escrita; os que aceitam: {1}", [1 => implode(", ", array_merge(array_keys($ops), ["usuarios", "importacao", "backup", "instalacao", "migracoes", "cadastro"]))])]];
}
$codigo = $saida["ok"] ? 200 : (!empty($saida["confirmar"]) ? 409 : 400);
