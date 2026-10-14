<?php
// recurso=ficha: tudo o que o painel de um relógio mostra (ou o cadastro de um novo).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// tudo o que o painel de um relógio mostra (relogio=<id>); sem relogio, só o que o cadastro de um relógio novo precisa
$hoje = new DateTimeImmutable("today");
$agora = time();
$n = nos_todos();
$r = linha("SELECT * FROM relogio WHERE id = ?", [(int)($_REQUEST["relogio"] ?? 0)]);
$valores = $r ? valores_do_relogio((int)$r["id"]) : [];
$campos = [];
foreach (campos_todos() as $ident => $c) {
    $campos[] = ["identificador" => $ident, "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "opcoes" => array_values(array_filter(array_map("trim", explode("\n", (string)$c["opcoes"])))),
        "padrao" => $c["padrao"], "no_id" => $c["no_id"] === null ? 0 : (int)$c["no_id"], "valor" => $valores[$ident] ?? null];
}
$saida = ["hoje" => $hoje->format("Y-m-d"), "relogio" => null, "copia_sistema" => documentos_copia_sistema(), "painel_modo" => painel_modo(), "campos" => $campos,
    "grupos" => array_map(function ($o) use ($n) { return ["id" => $o[0], "caminho" => no_caminho($o[0]), "cadeia" => no_cadeia($o[0])]; }, nos_em_ordem())];
if ((int)($_REQUEST["relogio"] ?? 0) > 0 && !$r) {
    $codigo = 404;
    $saida = ["erro" => "relógio não encontrado"];
} elseif ($r) {
    $id = (int)$r["id"];
    $v = visao_relogio($r, $agora);
    $proxima = valor("SELECT data FROM plano WHERE relogio_id = ? AND data >= ? ORDER BY data LIMIT 1", [$id, $hoje->format("Y-m-d")]);
    $abertas = [];
    foreach ($v["abertas"] as $a) {
        $abertas[$a["tipo"]] = $a;
    }
    $tipos = [];
    foreach (lancamento_tipos_do_relogio($r) as $ident => $t) {
        if (substr($ident, -7) !== "_antigo") {
            $tipos[] = ["identificador" => $ident, "nome" => $t["nome"], "formato" => $t["formato"], "unidade" => $t["unidade"], "mede_gasto" => (int)$t["mede_gasto"] === 1,
                "aberta" => isset($abertas[$ident]) ? ["inicio" => date("Y-m-d H:i:s", $abertas[$ident]["inicio"]), "rodizio" => $abertas[$ident]["origem"] === "rodizio",
                    "texto" => ($abertas[$ident]["origem"] === "rodizio" ? "no rodízio " : "") . "desde " . date("H:i", $abertas[$ident]["inicio"]) . " ("
                        . intdiv((int)floor(($agora - $abertas[$ident]["inicio"]) / 60), 60) . " h " . ((int)floor(($agora - $abertas[$ident]["inicio"]) / 60) % 60) . " min)"] : null];
        }
    }
    $leituras = [];
    foreach (array_reverse(linhas("SELECT l.inicio, l.valor, t.unidade FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE l.relogio_id = ? AND t.formato = 'valor' ORDER BY l.inicio DESC, l.id DESC LIMIT 40", [$id])) as $l) {
        $leituras[] = ["inicio" => $l["inicio"], "valor" => (float)$l["valor"], "unidade" => $l["unidade"],
            "em_uso" => valor("SELECT l.id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso'
                    AND l.inicio <= ? AND COALESCE(l.fim, ?) >= ? LIMIT 1", [$id, $l["inicio"], $l["inicio"], $l["inicio"]]) !== null];
    }
    $lt = linha_do_tempo($id, 0, PHP_INT_MAX, []);
    $foto = valor("SELECT atualizado FROM foto WHERE relogio_id = ?", [$id]);
    $nota = (int)$r["disponivel"] === 1 ? nota_do_relogio($r, $agora) : null;
    $saida["relogio"] = ["id" => $id, "nome" => $r["nome"], "no_id" => $r["no_id"] === null ? 0 : (int)$r["no_id"], "disponivel" => (int)$r["disponivel"] === 1,
        "copia_banco" => (int)$r["copia_banco"] === 1,
        "tipo" => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "sem grupo", "caminho" => no_caminho($r["no_id"]), "observacao" => $valores["observacao"] ?? null,
        "foto" => $foto !== null ? strtotime($foto) : null, "documentos" => (function () use ($id) {
            $cats = array_values(array_filter(documento_categorias_lista($id), function ($c) { return $c["documentos"] > 0; }));
            return ["total" => array_sum(array_column($cats, "documentos")), "categorias" => array_map(function ($c) { return ["id" => $c["id"], "nome" => $c["nome"], "documentos" => $c["documentos"]]; }, $cats),
                "pasta_ok" => documentos_pasta() !== null, "copia_sistema" => documentos_copia_sistema()];
        })(), "em_uso" => $v["em_uso"], "estado" => $v["estado"], "estado_texto" => $v["estado_texto"], "agora" => $v["texto"], "carga" => $v["energia"], "carga_de" => $v["de"], "situacao" => $v["situacao"],
        "nota" => $nota ? ["nota" => $nota["nota"], "conjunto_texto" => $nota["conjunto_texto"]] : null,
        "proxima" => $proxima, "proxima_ate" => $proxima !== null ? texto_ate($proxima, "neste dia") : null,
        "escala_fim" => valor("SELECT escala_dias FROM modo WHERE id = ?", [modo_ativo()]) !== null ? cfg("escala_fim") : null,
        "compra" => ["data" => $valores["data_compra"] ?? null, "valor" => isset($valores["valor_compra"]) ? (float)$valores["valor_compra"] : null, "loja" => $valores["loja"] ?? null,
            "garantia_ate" => $valores["garantia_ate"] ?? null,
            "ha_dias" => !empty($valores["data_compra"]) ? (int)floor(($hoje->getTimestamp() - strtotime(substr((string)$valores["data_compra"], 0, 10))) / 86400) : null,
            // as fórmulas do cadastro (Dias de uso e Custo por uso), quando existem
            "dias_de_uso" => (function () use ($r, $agora, $valores) {
                $c = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
                $x = isset(formulas_todas()["dias_de_uso"]) ? variavel_valor("dias_de_uso", $c) : null;
                return is_numeric($x) ? (int)round((float)$x) : null;
            })(),
            "custo_por_uso" => (function () use ($r, $agora, $valores) {
                $c = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
                $x = isset(formulas_todas()["custo_por_uso"]) ? variavel_valor("custo_por_uso", $c) : null;
                return is_numeric($x) ? round((float)$x, 2) : null;
            })()],
        "manutencoes" => array_map(function ($a) use ($hoje) { return ["data" => max($hoje->format("Y-m-d"), substr($a["data"], 0, 10)), "nome" => $a["nome"]]; }, array_slice($v["avisos"], 0, 5)),
        "previsao" => previsao_energia($r, $agora), "tipos" => $tipos, "leituras" => $leituras,
        "medicoes" => medicoes_legivel($id), "medicao_janela_dias" => max(1, (int)cfg("medicao_janela_dias")),
        // os lançamentos dos últimos 14 dias (e a sessão ainda aberta), para corrigir: o quadro "Corrigir marcações"
        "lancamentos_recentes" => array_map(function ($l) {
            return ["id" => (int)$l["id"], "tipo" => $l["identificador"], "nome" => $l["nome"], "formato" => $l["formato"], "unidade" => $l["unidade"],
                "inicio" => $l["inicio"], "fim" => $l["fim"], "valor" => $l["valor"] === null ? null : (float)$l["valor"], "origem" => $l["origem"]];
        }, linhas("SELECT l.id, t.identificador, t.nome, t.formato, t.unidade, l.inicio, l.fim, l.valor, l.origem FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
                WHERE l.relogio_id = ? AND l.inicio <= ? AND (l.inicio >= ? OR l.fim IS NULL OR l.fim >= ?) ORDER BY l.inicio DESC, l.id DESC LIMIT 40",
            [$id, date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora - 14 * 86400), date("Y-m-d H:i:s", $agora - 14 * 86400)])),
        "linha_do_tempo" => array_map("linha_legivel", array_slice($lt["linhas"], 0, 5)), "registros" => count($lt["linhas"]),
        // a dica da foto no painel (o texto que aparece com o mouse parado em cima dela): um resumo do relógio e o duplo clique
        "dica_foto" => (function () use ($r, $id, $n, $v, $nota, $proxima, $valores) {
            $l = [$r["nome"] . " · " . (isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "sem grupo") . " · código " . $id];
            $l[] = $v["estado_texto"] . " · " . ($v["energia"] !== null ? "carga " . (int)round((float)$v["energia"]) . "%" : (string)$v["de"]);
            if ($nota !== null) {
                $l[] = "Nota " . motivo_nota($nota["nota"]) . ($proxima !== null ? " · próximo dia no rodízio: " . date("d/m/Y", strtotime($proxima)) : "");
            } elseif ((int)$r["disponivel"] !== 1) {
                $l[] = "Indisponível: fora do rodízio";
            }
            if (!empty($valores["data_compra"]) || isset($valores["valor_compra"])) {
                $l[] = "Comprado" . (!empty($valores["data_compra"]) ? " em " . date("d/m/Y", strtotime(substr((string)$valores["data_compra"], 0, 10))) : "")
                    . (isset($valores["valor_compra"]) && $valores["valor_compra"] !== "" ? " por R\$ " . number_format((float)$valores["valor_compra"], 2, ",", ".") : "");
            }
            $l[] = "";
            $l[] = "Duplo clique (ou \"Abrir foto\", embaixo): a foto em tamanho grande.";
            return implode("\n", $l);
        })(),
        "desde" => count($lt["linhas"]) > 0 ? date("Y-m-d H:i:s", $lt["linhas"][count($lt["linhas"]) - 1]["inicio"]) : null];
    // os dados do relógio: os campos do cadastro que valem para ele (o informado, senão o padrão) e o resultado de cada fórmula
    $dados = [];
    foreach (campos_do_relogio($r) as $ident => $c) {
        $dados[] = ["identificador" => $ident, "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "valor" => $valores[$ident] ?? null,
            "valor_usado" => ($valores[$ident] ?? "") !== "" ? $valores[$ident] : $c["padrao"],
            "origem" => ($valores[$ident] ?? "") !== "" ? "informado" : ($c["padrao"] !== null && $c["padrao"] !== "" ? "padrão" : "vazio")];
    }
    $calculos = [];
    foreach (formulas_todas() as $ident => $versoes) {
        $f = mais_perto($versoes, $r);
        if ($f !== null) {
            $cf = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
            $calculos[] = ["identificador" => $ident, "nome" => $f["nome"], "unidade" => $f["unidade"], "valor" => variavel_valor($ident, $cf), "versao" => no_caminho($f["no_id"])];
        }
    }
    $saida["relogio"] = array_merge($saida["relogio"], ["autonomia" => autonomia_do_relogio($r, $agora), "dados" => $dados, "calculos" => $calculos]);
}
