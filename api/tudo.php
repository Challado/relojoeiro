<?php
// api.php sem recurso: tudo o que está guardado.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$com_foto = ($_REQUEST["foto"] ?? "") !== "nao";
$arvore = [];
foreach (nos_em_ordem() as $o) {
    $x = nos_todos()[$o[0]];
    $arvore[] = ["id" => $o[0], "pai_id" => $x["pai_id"] === null ? null : (int)$x["pai_id"], "nome" => $x["nome"], "ordem" => (int)$x["ordem"],
        "caminho" => no_caminho($o[0]), "profundidade" => $o[1]];
}
$campos = [];
foreach (campos_todos() as $c) {
    $campos[] = ["id" => (int)$c["id"], "identificador" => $c["identificador"], "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"],
        "opcoes" => $c["opcoes"], "padrao" => $c["padrao"], "no_id" => $c["no_id"] === null ? null : (int)$c["no_id"], "lugar" => no_caminho($c["no_id"]), "ordem" => (int)$c["ordem"]];
}
$tipos_l = [];
foreach (lancamento_tipos() as $t) {
    $tipos_l[] = ["id" => (int)$t["id"], "identificador" => $t["identificador"], "nome" => $t["nome"], "formato" => $t["formato"], "unidade" => $t["unidade"],
        "fecha_as" => $t["fecha_as"] === null ? null : substr($t["fecha_as"], 0, 5), "exclusiva" => (int)$t["exclusiva"] === 1, "mede_gasto" => (int)$t["mede_gasto"] === 1, "condicao" => $t["condicao"],
        "no_id" => $t["no_id"] === null ? null : (int)$t["no_id"], "lugar" => no_caminho($t["no_id"]), "ordem" => (int)$t["ordem"]];
}
$formulas = [];
foreach (formulas_todas() as $versoes) {
    foreach ($versoes as $f) {
        try {
            $usa = formula_referencias(formula_ler($f["expressao"]));
        } catch (ErroFormula $e) {
            $usa = ["erro" => $e->getMessage()];
        }
        $formulas[] = ["id" => (int)$f["id"], "identificador" => $f["identificador"], "nome" => $f["nome"], "expressao" => $f["expressao"], "unidade" => $f["unidade"],
            "no_id" => $f["no_id"] === null ? null : (int)$f["no_id"], "lugar" => no_caminho($f["no_id"]), "usa" => $usa];
    }
}
$relogios = [];
foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
    $valores = valores_do_relogio((int)$r["id"]);
    $cs = [];
    foreach (campos_do_relogio($r) as $ident => $c) {
        $cs[] = ["identificador" => $ident, "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "valor" => $valores[$ident] ?? null,
            "valor_usado" => ($valores[$ident] ?? "") !== "" ? $valores[$ident] : $c["padrao"]];
    }
    $fs = [];
    foreach (formulas_todas() as $ident => $versoes) {
        $f = mais_perto($versoes, $r);
        if ($f !== null) {
            $ctx = ["r" => $r, "momento" => time(), "rastro" => [], "pilha" => [], "valores" => $valores];
            $fs[] = ["identificador" => $ident, "nome" => $f["nome"], "unidade" => $f["unidade"], "valor" => variavel_valor($ident, $ctx), "versao" => no_caminho($f["no_id"])];
        }
    }
    $foto = linha("SELECT tipo, atualizado" . ($com_foto ? ", dados" : "") . " FROM foto WHERE relogio_id = ?", [(int)$r["id"]]);
    $relogios[] = ["id" => (int)$r["id"], "nome" => $r["nome"], "no_id" => $r["no_id"] === null ? null : (int)$r["no_id"], "lugar" => no_caminho($r["no_id"]),
        "disponivel" => (int)$r["disponivel"] === 1, "copia_banco" => (int)$r["copia_banco"] === 1, "posse" => posse_de($r), "criado" => $r["criado"],
        "foto" => $foto ? ["tipo" => $foto["tipo"], "atualizada_em" => $foto["atualizado"], "base64" => $com_foto ? base64_encode($foto["dados"]) : null] : null,
        "documentos" => array_map(function ($x) { return documento_info($x, false); }, documentos_do_relogio((int)$r["id"])),
        "campos" => $cs, "formulas" => $fs, "avisos" => avisos_do_relogio($r, time()),
        "lancamento_tipos" => array_keys(lancamento_tipos_do_relogio($r)),
        "lancamentos" => array_map(function ($l) {
            return ["id" => (int)$l["id"], "tipo" => $l["tipo"], "inicio" => $l["inicio"], "fim" => $l["fim"], "valor" => $l["valor"] === null ? null : (float)$l["valor"],
                "origem" => $l["origem"], "criado" => $l["criado"]];
        }, linhas("SELECT l.*, t.identificador AS tipo FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? ORDER BY l.inicio, l.id", [(int)$r["id"]])),
        "previsao" => previsao_energia($r, time())];
    $lt = linha_do_tempo((int)$r["id"], 0, PHP_INT_MAX, []);
    $relogios[count($relogios) - 1]["linha_do_tempo"] = array_map("linha_legivel", $lt["linhas"]);
    $relogios[count($relogios) - 1]["resumo_do_tempo"] = resumo_do_tempo($lt);
    $relogios[count($relogios) - 1]["medicoes"] = medicoes_legivel((int)$r["id"]);
    $relogios[count($relogios) - 1] = array_merge($relogios[count($relogios) - 1], autonomia_do_relogio($r, time()));
}
$modos = [];
foreach (linhas("SELECT * FROM modo ORDER BY ordem, id") as $m) {
    $modos[] = ["id" => (int)$m["id"], "nome" => $m["nome"], "selecao" => $m["selecao"], "escala_dias" => $m["escala_dias"] === null ? null : (int)$m["escala_dias"], "ciclo" => (int)$m["ciclo"] === 1,
        "ordem" => (int)$m["ordem"], "ativo" => (int)$m["id"] === modo_ativo(),
        "blocos" => array_map(function ($b) {
            return ["id" => (int)$b["id"], "nome" => $b["nome"], "dias" => array_map("intval", explode(",", $b["dias"])), "no_id" => $b["no_id"] === null ? null : (int)$b["no_id"],
                "lugar" => no_caminho($b["no_id"]), "um_por" => $b["um_por"], "relogio_id" => $b["relogio_id"] === null ? null : (int)$b["relogio_id"]];
        }, modo_blocos((int)$m["id"]))];
}
$config = [];
foreach (linhas("SELECT chave, valor FROM config ORDER BY chave") as $c) {
    $config[$c["chave"]] = $c["valor"];
}
$avisos = [];
foreach (avisos_todos() as $versoes) {
    foreach ($versoes as $a) {
        $avisos[] = ["id" => (int)$a["id"], "identificador" => $a["identificador"], "nome" => $a["nome"], "expressao" => $a["expressao"], "condicao" => $a["condicao"],
            "antecedencia_dias" => (float)$a["antecedencia_dias"], "texto" => $a["texto"], "resolve" => $a["resolve"], "ativo" => (int)$a["ativo"] === 1,
            "no_id" => $a["no_id"] === null ? null : (int)$a["no_id"], "lugar" => no_caminho($a["no_id"]), "escala" => $a["escala"],
            "simula_valor" => $a["simula_valor"] === null ? null : (float)$a["simula_valor"], "simula_horas" => $a["simula_horas"] === null ? null : (float)$a["simula_horas"],
            "agenda" => $a["agenda"]];
    }
}
$saida = ["arvore" => $arvore, "campos" => $campos, "lancamento_tipos" => $tipos_l, "formulas" => $formulas, "avisos" => $avisos, "relogios" => $relogios,
    "documento_categorias" => documento_categorias_lista(), "modos" => $modos, "plano" => plano_legivel("", ""), "plano_livre" => dias_livres("", ""), "config" => (object)$config,
    "eventos" => array_map("evento_legivel", eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome"))),
    "agenda" => ["criados" => linhas("SELECT chave, google_id, data, titulo, assinatura, criado FROM agenda_evento ORDER BY data, chave"), "desejados" => agenda_legivel()],
    "criterios" => montar_criterios(),
    "cron" => ["ultima" => cfg("cron_ultima_execucao"), "erro" => cfg("cron_erro"), "execucoes" => array_map(function ($x) {
        return ["id" => (int)$x["id"], "inicio" => $x["inicio"], "fim" => $x["fim"], "duracao_ms" => (int)$x["duracao_ms"], "teve_atividade" => (int)$x["teve_atividade"] === 1,
            "teve_erro" => (int)$x["teve_erro"] === 1, "registro" => $x["registro"]];
    }, linhas("SELECT * FROM cron_execucao ORDER BY inicio DESC, id DESC"))],
    "usuarios" => usuarios_catalogo(),
    "migracoes" => ["pendentes" => []],
    "motor" => ["funcoes" => array_map(function ($f) { return $f[2]; }, $FUNCOES), "tipos_de_campo" => $TIPOS_CAMPO, "formatos_de_lancamento" => $FORMATOS_LANCAMENTO]];
