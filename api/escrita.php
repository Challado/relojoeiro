<?php
// as escritas (POST): cada recurso e ação vai para a função dele no operacoes.php, que valida, grava e responde.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$ops = ["arvore" => "op_arvore", "campos" => "op_campos", "lancamento_tipos" => "op_lancamento_tipos", "formulas" => "op_formulas", "totais" => "op_totais", "colunas" => "op_colunas", "calibres" => "op_calibres", "marcas" => "op_marcas", "saidas" => "op_saidas", "relatorios" => "op_relatorios", "sql" => "op_sql", "unidades" => "op_unidades", "secoes" => "op_secoes", "relogio" => "op_relogio",
    "lancamento" => "op_lancamento", "avisos" => "op_avisos", "criterios" => "op_criterios",
    "rodizio" => "op_rodizio", "modos" => "op_modos", "config" => "op_config", "documentos" => "op_documentos", "documento_categorias" => "op_documento_categorias",
    "acessorios" => "op_acessorios", "winders" => "op_winders", "winder_posicoes" => "op_winder_posicoes", "movimentos" => "op_movimentos", "programas" => "op_programas", "servicos" => "op_servicos", "manutencao" => "op_manutencao", "oficinas" => "op_oficinas", "listas" => "op_listas", "reserva" => "op_reserva", "timegrapher" => "op_timegrapher", "destinos" => "op_destinos", "agendas" => "op_agendas", "conta" => "op_conta", "colecoes" => "op_colecoes", "acessos" => "op_acessos",
    "convites" => "op_convites", "pedidos" => "op_pedidos"];
// toda exclusão em dois passos: sem confirmar=1, a API diz o que sai junto (impacto_exclusao, no banco inteiro: uma árvore
// serve a várias coleções) e não apaga nada; com confirmar=1, apaga. O item tem de ser da coleção do pedido (lido pela visão
// dela); o que não é, segue para a operação, que responde "não encontrado"
$exclusoes = ["relogio" => ["excluir", "relogio"], "campos" => ["excluir", "campo"], "lancamento_tipos" => ["excluir", "lancamento_tipo"], "formulas" => ["excluir", "formula"], "totais" => ["excluir", "total"], "colunas" => ["excluir", "coluna"], "calibres" => ["excluir", "calibre"], "marcas" => ["excluir", "marca"], "saidas" => ["excluir", "relogio_saida"], "relatorios" => ["excluir", "relatorio"], "unidades" => ["excluir", "unidade"], "secoes" => ["excluir", "campo_secao"],
    "avisos" => ["excluir", "aviso"], "modos" => ["excluir", "modo"], "documentos" => ["excluir", "documento"], "documento_categorias" => ["excluir", "documento_categoria"],
    "acessorios" => ["excluir", "acessorio"], "winders" => ["excluir", "winder"], "winder_posicoes" => ["excluir", "winder_posicao"], "movimentos" => ["excluir", "winder_movimento"], "programas" => ["excluir", "winder_programa"], "servicos" => ["excluir", "servico"], "manutencao" => ["excluir", "manutencao"], "oficinas" => ["excluir", "oficina"], "listas" => ["excluir", "lista_item"], "reserva" => ["excluir", "reserva_teste"], "timegrapher" => ["excluir", "timegrapher"], "lancamento" => ["excluir", "lancamento"], "destinos" => ["excluir", "destino"], "agendas" => ["excluir", "agenda"],
    "config" => ["evento_excluir", "evento_personalizado"], "colecoes" => ["excluir", "colecao"]];
// O lote (recurso=lote): várias escritas num POST só. operacoes: a lista, em JSON, de {recurso, acao, os campos dela}, feitas
// na ordem. atomico=1 (o padrão): tudo ou nada, numa transação só; a primeira que falha desfaz as de antes e o lote para.
// atomico=0: cada uma vale sozinha, e o lote segue depois de uma que falha. Fora do lote: lote_recursos_fora (o que a
// transação não desfaz). No máximo lote_maximo() operações. Resposta: ok (todas gravadas), mensagem, erros (os de cada uma
// que falhou, com o número dela), atomico, desfeito (tudo ou nada e algo falhou: nada ficou gravado) e resultados (de cada
// uma feita: indice, a partir de 0, recurso, acao e a resposta dela, a mesma de um POST sozinho)
final class LoteParou extends RuntimeException
{
}
function escrita_lote($d, $quem, $ops, $exclusoes)
{
    $lista = $d["operacoes"] ?? null;
    if (is_string($lista)) {
        $lista = json_decode($lista, true);
    }
    $atomico_txt = (string)($d["atomico"] ?? "1");
    $erro = null;
    if (!is_array($lista) || !array_is_list($lista) || count($lista) === 0) {
        $erro = t("operacoes: uma lista, em JSON, de operações, cada uma com recurso, acao e os campos dela.");
    } elseif (count($lista) > lote_maximo()) {
        $erro = t("operacoes: no máximo {1} num lote (LOTE_MAXIMO do config.php).", [1 => lote_maximo()]);
    } elseif (!in_array($atomico_txt, ["0", "1"], true)) {
        $erro = t("atomico: 1 (tudo ou nada, o padrão) ou 0 (cada uma vale sozinha).");
    }
    if ($erro !== null) {
        return ["ok" => false, "mensagem" => "", "erros" => [$erro]];
    }
    $atomico = $atomico_txt === "1";
    // uma operação: a resposta dela (um erro dela também vira resposta, para o lote dizer qual foi)
    $roda = function ($op) use ($quem, $ops, $exclusoes) {
        $rec = is_array($op) ? (string)($op["recurso"] ?? "") : "";
        $ac = is_array($op) ? (string)($op["acao"] ?? "") : "";
        if (!is_array($op) || array_is_list($op) || $rec === "" || $ac === "") {
            return ["ok" => false, "mensagem" => "", "erros" => [t("cada operação é um objeto com recurso, acao e os campos dela.")]];
        }
        if (!isset($ops[$rec]) || in_array($rec, lote_recursos_fora(), true)) {
            return ["ok" => false, "mensagem" => "", "erros" => [t("o recurso {1} não entra num lote (fora: {2}).", [1 => $rec, 2 => implode(", ", lote_recursos_fora())])]];
        }
        try {
            return escrita_uma($rec, $ac, $op, $quem, $ops, $exclusoes);
        } catch (Throwable $e) {
            return ["ok" => false, "mensagem" => "", "erros" => [t("a operação parou com um erro: {1}", [1 => $e->getMessage()])]];
        }
    };
    $resultados = [];
    $erros = [];
    $falhou = null;
    $uma = function ($i, $op) use ($roda, &$resultados, &$erros, &$falhou) {
        $s = $roda($op);
        $rec = is_array($op) ? (string)($op["recurso"] ?? "") : "";
        $ac = is_array($op) ? (string)($op["acao"] ?? "") : "";
        $resultados[] = ["indice" => $i, "recurso" => $rec, "acao" => $ac, "resposta" => $s];
        if (empty($s["ok"])) {
            $falhou = $falhou ?? $i;
            foreach (count($s["erros"] ?? []) > 0 ? $s["erros"] : [t("recusada")] as $e) {
                $erros[] = t("Operação {1} ({2}, {3}): {4}", [1 => $i, 2 => $rec, 3 => $ac, 4 => $e]);
            }
            return false;
        }
        return true;
    };
    if ($atomico) {
        // tudo ou nada: a primeira que falha desfaz a transação inteira (as de antes também) e o lote para
        try {
            banco_atomico(function () use ($lista, $uma) {
                foreach ($lista as $i => $op) {
                    if (!$uma($i, $op)) {
                        throw new LoteParou();
                    }
                }
            });
        } catch (LoteParou $e) {
            // desfeito pelo banco_atomico
        }
    } else {
        foreach ($lista as $i => $op) {
            $uma($i, $op);
        }
    }
    $gravadas = count(array_filter($resultados, function ($x) { return !empty($x["resposta"]["ok"]); }));
    $mensagem = $falhou === null ? t("Lote gravado: {1} de {1} operações.", [1 => count($lista)])
        : ($atomico ? t("Nada foi gravado: a operação {1} ({2}, {3}) falhou, e o lote é tudo ou nada.", [1 => $falhou, 2 => $resultados[count($resultados) - 1]["recurso"], 3 => $resultados[count($resultados) - 1]["acao"]])
            : t("{1} de {2} operações gravadas; as outras falharam.", [1 => $gravadas, 2 => count($lista)]));
    return ["ok" => $falhou === null, "mensagem" => $mensagem, "erros" => $erros, "atomico" => $atomico, "desfeito" => $atomico && $falhou !== null, "resultados" => $resultados];
}

// Uma escrita: o recurso e a ação vão para a função dele (com a pergunta antes de toda exclusão). Devolve a resposta dela;
// com "confirmar", a exclusão esperando confirmar=1 (409)
function escrita_uma($recurso, $acao, $d, $quem, $ops, $exclusoes)
{
    $saida = null;
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
        // o movimento numa sequência e o programa numa posição não saem: sem a pergunta, a operação diz onde estão
        if ($existe_exc !== null && !winder_exclusao_em_uso($tab_exc, $id_exc)) {
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
            return $saida;
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
        // a importação do sistema anterior pode demorar (o histórico inteiro): sem o limite de tempo do PHP (recursos_demorados)
        @set_time_limit(0);
        $saida = op_importacao($acao, $d);
    } elseif ($recurso === "backup") {
        // a cópia de segurança: fazer agora, ou restaurar uma (as duas podem demorar num banco grande)
        @set_time_limit(0);
        $saida = $acao === "fazer" ? backup_fazer() : ($acao === "restaurar" ? backup_restaurar((string)($d["arquivo"] ?? ""))
            : ["ok" => false, "mensagem" => "", "erros" => [t("Ação desconhecida: use fazer ou restaurar.")]]);
    } else {
        $saida = ["ok" => false, "mensagem" => "", "erros" => [t("este recurso não aceita escrita; os que aceitam: {1}", [1 => implode(", ", array_merge(array_keys($ops), ["usuarios", "importacao", "backup", "instalacao", "migracoes", "cadastro", "lote"]))])]];
    }
    return $saida;
}

if ($recurso === "lote") {
    $saida = escrita_lote($d, $quem, $ops, $exclusoes);
} else {
    $saida = escrita_uma($recurso, $acao, $d, $quem, $ops, $exclusoes);
}
$codigo = $saida["ok"] ? 200 : (!empty($saida["confirmar"]) ? 409 : 400);
