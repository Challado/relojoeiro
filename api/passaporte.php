<?php
// recurso=passaporte: a ficha de um relógio para imprimir (ou guardar em PDF), tudo já em texto: quem ele é, o cadastro
// preenchido, a compra, o uso (desde quando, quantos dias, horas no pulso, custo por uso), as manutenções feitas (cada tipo
// de marcação, quantas vezes e a última; a lista das revisões e trocas de pilha), os acessórios, os documentos e os próximos
// avisos. relogio=<id>; que não existe: 404.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$r = linha("SELECT * FROM relogio WHERE id = ?", [(int)($_REQUEST["relogio"] ?? 0)]);
if (!$r) {
    $codigo = 404;
    $saida = ["erro" => t("relógio não encontrado (relogio=<id>)")];
    return;
}
$id = (int)$r["id"];
$agora_p = time();
$valores = valores_do_relogio($id);
$n_p = nos_todos();

// um valor do cadastro em texto, pelo tipo do campo: data DD/MM/AAAA, sim/não, número com vírgula e a unidade (R$ na frente)
$texto_valor = function ($c, $v) {
    if ($v === null || $v === "") {
        return null;
    }
    if ($c["tipo"] === "data") {
        return data_txt(strtotime(substr((string)$v, 0, 10)));
    }
    if ($c["tipo"] === "sim_nao") {
        return (string)$v === "1" ? t("sim") : t("não");
    }
    if ($c["tipo"] === "decimal" || $c["tipo"] === "inteiro") {
        $num = $c["tipo"] === "inteiro" ? numero_txt((float)$v, 0) : numero_txt((float)$v, 2, 0);
        if ($c["unidade"] === "R$") {
            return dinheiro_txt((float)$v);
        }
        return $num . ($c["unidade"] !== "" ? " " . $c["unidade"] : "");
    }
    return (string)$v;
};
$cadastro = [];
foreach (campos_do_relogio($r) as $ident => $c) {
    $t = $texto_valor($c, $valores[$ident] ?? null);
    if ($t !== null) {
        $cadastro[] = ["identificador" => $ident, "nome" => $c["nome"], "valor" => $t];
    }
}

// o uso: a primeira e a última vez no pulso, as horas no pulso desde sempre, os dias de uso e o custo por uso (as fórmulas)
$pulso_p = linha("SELECT MIN(l.inicio) AS primeiro, MAX(l.fim) AS ultimo, COUNT(*) AS n, SUM(CASE WHEN l.fim IS NULL THEN 1 ELSE 0 END) AS abertas
    FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador IN ('pulso', 'pulso_antigo')", [$id]);
$horas_p = horas_no_pulso($id, 0, $agora_p);
$formula = function ($ident) use ($r, $agora_p, $valores) {
    $c = ["r" => $r, "momento" => $agora_p, "rastro" => [], "pilha" => [], "valores" => $valores];
    $x = isset(formulas_todas()[$ident]) ? variavel_valor($ident, $c) : null;
    return is_numeric($x) ? (float)$x : null;
};
$du = $formula("dias_de_uso");
$cpu = $formula("custo_por_uso");

// as manutenções: cada tipo de marcação (instantâneo) com quantas vezes e a última; a lista das revisões e trocas de pilha
$marcacoes = array_map(function ($m) {
    return ["tipo" => $m["identificador"], "nome" => $m["nome"], "vezes" => (int)$m["n"], "ultima" => substr((string)$m["ultima"], 0, 10)];
}, linhas("SELECT t.identificador, t.nome, COUNT(*) AS n, MAX(l.inicio) AS ultima FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
    WHERE l.relogio_id = ? AND t.formato = 'instantaneo' AND t.identificador <> 'pulso_antigo' GROUP BY t.identificador, t.nome ORDER BY MAX(l.inicio) DESC", [$id]));
$servicos = array_map(function ($m) {
    return ["data" => substr((string)$m["inicio"], 0, 10), "tipo" => $m["identificador"], "nome" => $m["nome"]];
}, linhas("SELECT l.inicio, t.identificador, t.nome FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
    WHERE l.relogio_id = ? AND t.identificador IN ('revisao', 'pilha') ORDER BY l.inicio DESC, l.id DESC", [$id]));

$cat_p = [];
foreach (documento_categorias_lista() as $c) {
    $cat_p[(int)$c["id"]] = $c["nome"];
}
$foto_p = valor("SELECT atualizado FROM foto WHERE relogio_id = ?", [$id]);
$rs_p = reserva_do_relogio($r, $agora_p);
$saida = ["gerado_em" => date("Y-m-d H:i:s", $agora_p),
    "relogio" => ["id" => $id, "nome" => $r["nome"], "caminho" => no_caminho($r["no_id"]),
        "tipo" => isset($n_p[(int)$r["no_id"]]) ? $n_p[(int)$r["no_id"]]["nome"] : t("sem grupo"), "posse" => posse_de($r), "posse_texto" => t(POSSES[posse_de($r)]),
        "disponivel" => (int)$r["disponivel"] === 1, "criado" => $r["criado"], "foto" => $foto_p !== null ? strtotime($foto_p) : null,
        "marca" => ($valores["marca"] ?? "") !== "" ? $valores["marca"] : null, "referencia" => ($valores["referencia"] ?? "") !== "" ? $valores["referencia"] : null,
        "observacao" => ($valores["observacao"] ?? "") !== "" ? $valores["observacao"] : null],
    "cadastro" => $cadastro,
    "compra" => ["data" => ($valores["data_compra"] ?? "") !== "" ? substr((string)$valores["data_compra"], 0, 10) : null,
        "valor" => ($valores["valor_compra"] ?? "") !== "" ? (float)$valores["valor_compra"] : null,
        "loja" => ($valores["loja"] ?? "") !== "" ? $valores["loja"] : null,
        "garantia_ate" => ($valores["garantia_ate"] ?? "") !== "" ? substr((string)$valores["garantia_ate"], 0, 10) : null],
    "uso" => ["primeira_vez" => $pulso_p && $pulso_p["primeiro"] !== null ? substr((string)$pulso_p["primeiro"], 0, 10) : null,
        "ultima_vez" => $pulso_p && (int)$pulso_p["abertas"] > 0 ? date("Y-m-d", $agora_p) : ($pulso_p && $pulso_p["ultimo"] !== null ? substr((string)$pulso_p["ultimo"], 0, 10) : null),
        "no_pulso_agora" => $pulso_p && (int)$pulso_p["abertas"] > 0,
        "horas_no_pulso" => round($horas_p, 1), "dias_de_uso" => $du !== null ? (int)round($du) : null, "custo_por_uso" => $cpu !== null ? round($cpu, 2) : null],
    "marcacoes" => $marcacoes, "servicos" => $servicos,
    "acessorios" => acessorios_lista($id),
    // os serviços e diagnósticos (recurso=servicos): quem fez, o custo, a estanqueidade, a marcha antes e depois
    "servicos_diagnosticos" => servicos_lista($id),
    // declarado, medido e estimado (a marcha, a reserva, a estanqueidade), com a confiança; e os testes da reserva
    "tecnico" => tecnico_do_relogio($r, $agora_p, precisao_do_relogio($r, $agora_p), $rs_p, servicos_resumo($id)),
    "reserva_testes" => $rs_p !== null ? $rs_p["testes"] : [],
    "documentos" => array_map(function ($x) use ($cat_p) {
        return ["id" => (int)$x["id"], "categoria" => $cat_p[(int)$x["categoria_id"]] ?? "", "titulo" => $x["titulo"], "data" => $x["data"] === null ? null : substr((string)$x["data"], 0, 10), "nome" => $x["nome"]];
    }, documentos_do_relogio($id)),
    "proximos_avisos" => array_map(function ($a) {
        return ["nome" => $a["nome"], "data" => substr((string)$a["data"], 0, 10), "texto" => $a["texto"]];
    }, array_slice((function () use ($r, $agora_p) {
        $a = avisos_do_relogio($r, $agora_p);
        usort($a, function ($x, $y) { return [$x["falta_dias"], $x["identificador"]] <=> [$y["falta_dias"], $y["identificador"]]; });
        return $a;
    })(), 0, 8))];
