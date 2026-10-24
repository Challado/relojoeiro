<?php
// recurso=ficha: tudo o que o painel de um relógio mostra (ou o cadastro de um novo).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// tudo o que o painel de um relógio mostra (relogio=<id>); sem relogio, só o que o cadastro de um relógio novo precisa
$hoje = hoje_da_colecao();
$agora = time();
$n = nos_todos();
$r = linha("SELECT * FROM relogio WHERE id = ?", [(int)($_REQUEST["relogio"] ?? 0)]);
$valores = $r ? valores_do_relogio((int)$r["id"]) : [];
// os valores do próprio relógio (o cadastro que se edita), sem os que vêm do calibre
$proprios = $r ? valores_proprios((int)$r["id"]) : [];
$do_calibre = $r ? valores_do_calibre_do_relogio((int)$r["id"]) : [];
// os campos na ordem da ficha: por divisória (na ordem delas; os sem divisória no fim), cada um com o nome da dele
$campos = [];
foreach (campos_na_ordem_da_ficha(campos_todos()) as $c) {
    $ident = $c["identificador"];
    $campos[] = ["identificador" => $ident, "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "opcoes" => array_values(array_filter(array_map("trim", explode("\n", (string)$c["opcoes"])))),
        "padrao" => $c["padrao"], "no_id" => $c["no_id"] === null ? 0 : (int)$c["no_id"], "so_desejado" => (int)($c["so_desejado"] ?? 0) === 1,
        "secao" => $c["secao"], "multiplo" => (int)($c["multiplo"] ?? 0) === 1, "regra" => campo_regra_texto($c), "valor" => $proprios[$ident] ?? null, "do_calibre" => $do_calibre[$ident] ?? null];
}
$saida = ["hoje" => $hoje->format("Y-m-d"), "trocar_hoje" => trocar_hoje_permitido(), "relogio" => null, "copia_sistema" => documentos_copia_sistema(), "painel_modo" => painel_modo(), "campos" => $campos,
    // as situações na coleção, na ordem, para o menu do cadastro
    "posses" => lista_opcoes("relogio_situacao"),
    "grupos" => array_map(function ($o) use ($n) { return ["id" => $o[0], "caminho" => no_caminho($o[0]), "cadeia" => no_cadeia($o[0])]; }, nos_em_ordem()),
    // os calibres da árvore, para o menu do cadastro
    "calibres" => array_map(function ($c) { return ["id" => (int)$c["id"], "nome" => $c["nome"], "fabricante" => $c["fabricante"]]; }, linhas("SELECT id, nome, fabricante FROM calibre ORDER BY nome, id"))];
if ((int)($_REQUEST["relogio"] ?? 0) > 0 && !$r) {
    $codigo = 404;
    $saida = ["erro" => t("relógio não encontrado")];
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
            // o tipo marcado como winder pede a posição ao abrir; aberta numa posição, o texto diz qual
            $pos_a = isset($abertas[$ident]) && ($abertas[$ident]["posicao_id"] ?? null) !== null ? winder_posicao_nome((int)$abertas[$ident]["posicao_id"]) : null;
            $tipos[] = ["identificador" => $ident, "nome" => $t["nome"], "formato" => $t["formato"], "unidade" => $t["unidade"], "mede_gasto" => (int)$t["mede_gasto"] === 1, "marcha" => $t["marcha"] ?? null,
                "winder" => (int)($t["winder"] ?? 0) === 1,
                "aberta" => isset($abertas[$ident]) ? ["inicio" => date("Y-m-d H:i:s", $abertas[$ident]["inicio"]), "rodizio" => $abertas[$ident]["origem"] === "rodizio",
                    "posicao_id" => $abertas[$ident]["posicao_id"] ?? null, "posicao" => $pos_a,
                    "texto" => t("{1}desde {2} ({3} h {4} min)", [1 => $abertas[$ident]["origem"] === "rodizio" ? t("no rodízio ") : "", 2 => date("H:i", $abertas[$ident]["inicio"]), 3 => intdiv((int)floor(($agora - $abertas[$ident]["inicio"]) / 60), 60), 4 => (int)floor(($agora - $abertas[$ident]["inicio"]) / 60) % 60])
                        . ($pos_a !== null ? " · " . $pos_a : "")] : null];
        }
    }
    // as leituras com a marcação delas (uso ou repouso: a gravada, ou a do pulso registrado naquele instante)
    $leituras = [];
    $marcadas = leituras_marcadas($id);
    foreach (array_reverse(linhas("SELECT l.id, l.inicio, l.valor, t.unidade FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE l.relogio_id = ? AND t.formato = 'valor' AND t.marcha IS NULL ORDER BY l.inicio DESC, l.id DESC LIMIT 40", [$id])) as $l) {
        $mk = $marcadas[(int)$l["id"]] ?? ["estado" => "repouso", "marcada" => false];
        $leituras[] = ["inicio" => $l["inicio"], "valor" => (float)$l["valor"], "unidade" => $l["unidade"], "em_uso" => $mk["estado"] === "uso",
            "estado" => $mk["estado"], "marcada" => $mk["marcada"]];
    }
    $lt = linha_do_tempo($id, 0, PHP_INT_MAX, []);
    $foto = valor("SELECT atualizado FROM foto WHERE relogio_id = ?", [$id]);
    $nota = (int)$r["disponivel"] === 1 ? nota_do_relogio($r, $agora) : null;
    $saida["relogio"] = ["id" => $id, "nome" => $r["nome"], "no_id" => $r["no_id"] === null ? 0 : (int)$r["no_id"], "disponivel" => (int)$r["disponivel"] === 1,
        "posse" => (string)$r["posse"], "posse_texto" => posse_texto($r), "posse_comportamento" => posse_de($r), "da_colecao" => da_colecao($r),
        "acessorios" => acessorios_lista($id),
        // o winder é obrigatório: sem winder cadastrado, o Marcar não tem pôr nem período no winder; com winder, o pôr é pela
        // página Winders, e o período que já passou escolhe a posição onde ele esteve (todas as posições, quando ele tem um tipo
        // marcado como winder); o relógio que tem o lugar dele (winder_lugar) volta para lá daqui também, se está livre
        "winder_cadastrado" => ($w_cad = winder_cadastrado()),
        "winder_lugar" => $w_cad ? winder_lugar_info($id, $agora) : null,
        "winder_posicoes" => $w_cad && count(array_filter($tipos, function ($x) { return $x["winder"]; })) > 0 ? winder_posicoes_livres($agora, false) : [],
        // os acessórios de outros relógios (ou soltos) que servem neste (a pulseira com a largura do entre alças dele)
        "acessorios_servem" => acessorios_que_servem($id),
        // os serviços e diagnósticos (o quadro Serviços e diagnósticos): a lista, o resumo, os tipos e os documentos dele que um
        // serviço pode citar (o formulário)
        "servicos" => ($srv_f = servicos_lista($id)), "servicos_resumo" => servicos_resumo($id, $srv_f),
        "servico_tipos" => servico_tipos_opcoes(), "estanqueidades" => lista_opcoes("estanqueidade"),
        // a manutenção (o quadro Manutenção): a aberta (o relógio na oficina agora; null sem) e todas, da mais nova para a mais
        // velha; cadastrar, mudar a situação e encerrar ficam na página Manutenção
        "manutencoes_oficina" => ($mn_f = manutencao_lista(["relogio" => $id])),
        "manutencao_aberta" => array_values(array_filter($mn_f, function ($m) { return $m["aberta"]; }))[0] ?? null,
        "oficinas" => array_map(function ($o) { return ["id" => (int)$o["id"], "nome" => $o["nome"]]; }, linhas("SELECT id, nome FROM oficina ORDER BY nome, id")),
        "servico_documentos" => array_map(function ($x) { return ["id" => (int)$x["id"], "titulo" => $x["titulo"]]; }, documentos_do_relogio($id)),
        // a reserva medida (o quadro Reserva): os testes do mecânico e as observações da energia; null sem reserva no cadastro
        "reserva" => ($rs_f = reserva_do_relogio($r, $agora)),
        "copia_banco" => (int)$r["copia_banco"] === 1,
        // o calibre (o movimento): o cadastro dele, com o link do manual técnico e as recomendações; null sem
        "calibre" => ($cal_f = calibre_do_relogio($id)) ? ["id" => (int)$cal_f["id"], "nome" => $cal_f["nome"], "fabricante" => $cal_f["fabricante"], "link" => $cal_f["link"],
            "observacao" => $cal_f["observacao"],
            // os documentos do calibre (o manual técnico...), para abrir dali: o título, a categoria, a família e o endereço
            "documentos" => array_map(function ($x) { return ["id" => (int)$x["id"], "titulo" => $x["titulo"], "categoria" => $x["categoria"],
                "familia" => documento_familia($x["tipo"], $x["nome"]), "url" => "api.php?recurso=documento&id=" . (int)$x["id"]]; },
                linhas("SELECT d.id, d.titulo, d.tipo, d.nome, c.nome AS categoria FROM documento d JOIN documento_categoria c ON c.id = d.categoria_id WHERE d.calibre_id = ?
                    ORDER BY c.ordem, c.id, d.id", [(int)$cal_f["id"]]))] : null,
        "tipo" => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : t("sem grupo"), "caminho" => no_caminho($r["no_id"]), "observacao" => $valores["observacao"] ?? null,
        "foto" => $foto !== null ? strtotime($foto) : null, "documentos" => (function () use ($id) {
            $cats = array_values(array_filter(documento_categorias_lista($id), function ($c) { return $c["documentos"] > 0; }));
            return ["total" => array_sum(array_column($cats, "documentos")), "categorias" => array_map(function ($c) { return ["id" => $c["id"], "nome" => $c["nome"], "documentos" => $c["documentos"]]; }, $cats),
                "pasta_ok" => documentos_pasta() !== null, "copia_sistema" => documentos_copia_sistema()];
        })(), "em_uso" => $v["em_uso"], "estado" => $v["estado"], "estado_texto" => $v["estado_texto"], "agora" => $v["texto"], "carga" => $v["energia"], "carga_de" => $v["de"], "situacao" => $v["situacao"],
        "nota" => $nota ? ["nota" => $nota["nota"], "conjunto_texto" => $nota["conjunto_texto"]] : null,
        "proxima" => $proxima, "proxima_ate" => $proxima !== null ? texto_ate($proxima, t("neste dia")) : null,
        "proxima_um_dia" => $proxima !== null && plano_ultimo_dia_seguido($proxima) === $proxima,
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
        "encostado" => $v["encostado"] ? ["data" => max($hoje->format("Y-m-d"), substr($v["encostado"]["data"], 0, 10)),
            "momento" => $v["encostado_momento"], "falta" => $v["encostado_falta"], "estado" => $v["encostado"]["estado"]] : null,
        "manutencoes" => array_map(function ($a) use ($hoje) { return ["data" => max($hoje->format("Y-m-d"), substr($a["data"], 0, 10)), "nome" => $a["nome"]]; }, array_slice($v["avisos"], 0, 5)),
        "previsao" => previsao_energia($r, $agora), "tipos" => $tipos, "leituras" => $leituras, "precisao" => ($pr_f = precisao_do_relogio($r, $agora)),
        // o timegrapher (o quadro Timegrapher, na seção Precisão e marcha): nos que medem a marcha, ou já têm medição; null nos outros
        "timegrapher" => $pr_f !== null || (int)valor("SELECT COUNT(*) FROM timegrapher WHERE relogio_id = ?", [$id]) > 0 ? timegrapher_do_relogio($r, $agora) : null,
        // declarado, medido e estimado: a marcha, a reserva e a estanqueidade, cada uma com a confiança
        "tecnico" => tecnico_do_relogio($r, $agora, $pr_f, $rs_f, servicos_resumo($id, $srv_f)),
        "medicoes" => medicoes_legivel($id), "medicao_janela_dias" => max(1, (int)cfg("medicao_janela_dias")), "medicao_janela_texto" => medicoes_janela_texto(),
        // os lançamentos dos últimos 14 dias (e a sessão ainda aberta), para corrigir: o quadro "Corrigir marcações"
        "lancamentos_recentes" => array_map(function ($l) {
            return ["id" => (int)$l["id"], "tipo" => $l["identificador"], "nome" => $l["nome"], "formato" => $l["formato"], "unidade" => $l["unidade"],
                "inicio" => $l["inicio"], "fim" => $l["fim"], "valor" => $l["valor"] === null ? null : (float)$l["valor"], "estado" => $l["estado"], "origem" => $l["origem"],
                "marcha" => $l["marcha"], "posicao" => $l["posicao"]];
        }, linhas("SELECT l.id, t.identificador, t.nome, t.formato, t.unidade, t.marcha, l.inicio, l.fim, l.valor, l.estado, l.posicao, l.origem FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
                WHERE l.relogio_id = ? AND l.inicio <= ? AND (l.inicio >= ? OR l.fim IS NULL OR l.fim >= ?) ORDER BY l.inicio DESC, l.id DESC LIMIT 40",
            [$id, date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora - 14 * 86400), date("Y-m-d H:i:s", $agora - 14 * 86400)])),
        "linha_do_tempo" => array_map("linha_legivel", array_slice($lt["linhas"], 0, 5)), "registros" => count($lt["linhas"]),
        // a dica da foto no painel (o texto que aparece com o mouse parado em cima dela): um resumo do relógio e o duplo clique
        "dica_foto" => (function () use ($r, $id, $n, $v, $nota, $proxima, $valores) {
            $l = [t("{1} · {2} · código {3}", [1 => $r["nome"], 2 => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : t("sem grupo"), 3 => $id])];
            $l[] = $v["estado_texto"] . " · " . ($v["energia"] !== null ? t("carga {1}%", [1 => (int)round((float)$v["energia"])]) : (string)$v["de"]);
            if ($nota !== null) {
                $l[] = t("Nota {1}{2}", [1 => motivo_nota($nota["nota"]), 2 => $proxima !== null ? t(" · próximo dia no rodízio: {1}", [1 => data_txt(strtotime($proxima))]) : ""]);
            } elseif (manutencao_aberta($id) !== null) {
                $l[] = t("Em manutenção ({1}): fora do rodízio", [1 => manutencao_aberta($id)["oficina"]]);
            } elseif (posse_de($r) !== "colecao") {
                $l[] = t("{1}: fora do rodízio", [1 => posse_texto($r)]);
            } elseif ((int)$r["disponivel"] !== 1) {
                $l[] = t("Indisponível: fora do rodízio");
            }
            if (!empty($valores["data_compra"]) || isset($valores["valor_compra"])) {
                $l[] = t("Comprado{1}{2}", [1 => !empty($valores["data_compra"]) ? t(" em {1}", [1 => data_txt(strtotime(substr((string)$valores["data_compra"], 0, 10)))]) : "",
                    2 => isset($valores["valor_compra"]) && $valores["valor_compra"] !== "" ? t(" por R\$ {1}", [1 => numero_txt((float)$valores["valor_compra"], 2)]) : ""]);
            }
            $l[] = "";
            $l[] = t("Duplo clique (ou \"Abrir foto\", embaixo): a foto em tamanho grande.");
            return implode("\n", $l);
        })(),
        "desde" => count($lt["linhas"]) > 0 ? date("Y-m-d H:i:s", $lt["linhas"][count($lt["linhas"]) - 1]["inicio"]) : null,
        // a lista de desejos: o que o quadro Desejo mostra (só no desejado; null nos outros)
        "desejo" => posse_de($r) === "desejado" ? desejo_do_relogio($valores) : null];
    // os dados do relógio: os campos do cadastro que valem para ele (o informado, senão o padrão) e o resultado de cada fórmula
    $dados = [];
    foreach (campos_na_ordem_da_ficha(campos_do_relogio($r)) as $c) {
        $ident = $c["identificador"];
        $dados[] = ["identificador" => $ident, "nome" => $c["nome"], "secao" => $c["secao"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "multiplo" => (int)($c["multiplo"] ?? 0) === 1, "valor" => $proprios[$ident] ?? null,
            "valor_usado" => ($valores[$ident] ?? "") !== "" ? $valores[$ident] : $c["padrao"],
            "origem" => $o_d = origem_valor($id, $ident, $c["padrao"]), "origem_texto" => origem_texto($o_d)];
        // o valor também na unidade em que a dele aparece (o milímetro também em polegadas): o texto, ou null
        $usado_d = $dados[count($dados) - 1]["valor_usado"];
        $dados[count($dados) - 1]["tambem"] = in_array($c["tipo"], ["inteiro", "decimal"], true) && is_numeric($usado_d) ? (unidade_tambem($usado_d, $c["unidade"])["texto"] ?? null) : null;
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
