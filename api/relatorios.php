<?php
// recurso=relatorios: a porta única dos relatórios. Sem tipo, o catálogo: cada relatório com o grupo, o nome, a descrição e
// os parâmetros que aceita (o menu da página Relatórios sai daqui), e os relógios, para o parâmetro relogio. Com
// tipo=<um do catálogo> e os parâmetros dele, o relatório pronto, num formato só para todos: o título, o subtítulo (o que
// foi pedido, em texto), a foto (no do relógio), o resumo (pares nome e valor), as seções (tabela: as colunas, as linhas e
// o total; ou pares) e o rodapé, tudo já em texto, no idioma de quem pede. csv=1: o mesmo relatório numa planilha (CSV em
// UTF-8, separado por ponto e vírgula). Cada relatório é montado em cima do que a própria API responde (api_ler: o mesmo
// recurso que a tela usa, com as mesmas regras de acesso), então nunca diz outra coisa que a tela.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// ---------- os pedaços de um relatório ----------
// uma célula em texto: o vazio vira "—"
$cel = function ($v) {
    return $v === null || $v === "" ? "—" : (string)$v;
};
// uma seção em tabela: as colunas (o nome, ou [o nome, true] na que é número: alinhada à direita; [o nome, false, true] na
// que é um registro gravado como saiu, no idioma de quando foi gravado: o do cron), as linhas (cada uma, a lista das
// células), o texto de quando não há linha, o total (a linha de baixo) e uma nota
$tabela = function ($titulo, $colunas, $linhas, $vazio, $total = null, $nota = null) use ($cel) {
    return ["titulo" => $titulo, "tipo" => "tabela", "nota" => $nota,
        "colunas" => array_map(function ($c) { return is_array($c) ? ["nome" => $c[0], "numero" => (bool)$c[1], "registro" => (bool)($c[2] ?? false)] : ["nome" => $c, "numero" => false, "registro" => false]; }, $colunas),
        "linhas" => array_map(function ($l) use ($cel) { return array_map($cel, array_values($l)); }, array_values($linhas)),
        "total" => $total === null ? null : array_map($cel, array_values($total)), "vazio" => $vazio];
};
// uma seção em pares (nome e valor; o par sem valor fica de fora)
$pares = function ($titulo, $lista, $vazio = null, $nota = null) {
    $p = [];
    foreach ($lista as $x) {
        if ($x[1] !== null && $x[1] !== "") {
            $p[] = ["nome" => (string)$x[0], "valor" => (string)$x[1]];
        }
    }
    return ["titulo" => $titulo, "tipo" => "pares", "nota" => $nota, "pares" => $p, "vazio" => $vazio];
};
$data = function ($v) {
    return $v === null || $v === "" ? null : data_txt(substr((string)$v, 0, 10));
};
$data_hora = function ($v) {
    return $v === null || $v === "" ? null : data_txt((string)$v, true);
};
$reais = function ($v) {
    return $v === null || $v === "" ? null : dinheiro_txt((float)$v);
};
// um recurso da API por dentro: a resposta, ou o erro dele (que vira o erro do relatório)
$ler = function ($recurso_r, $parametros = []) {
    [$c, $s] = api_ler($recurso_r, $parametros);
    if ($c !== 200) {
        throw new RelatorioErro((string)($s["erro"] ?? t("não consegui ler {1}", [1 => $recurso_r])), $c);
    }
    return $s;
};
// o relógio do parâmetro relogio (obrigatório ou não); o que não existe (ou não é da coleção de quem pede) é 404
$relogio = function ($p, $obrigatorio) {
    $id = (int)($p["relogio"] ?? 0);
    if ($id <= 0) {
        if ($obrigatorio) {
            throw new RelatorioErro(t("escolha o relógio (relogio=<id>)"), 400);
        }
        return null;
    }
    $r = linha("SELECT * FROM relogio WHERE id = ?", [$id]);
    if (!$r) {
        throw new RelatorioErro(t("relógio não encontrado"), 404);
    }
    return $r;
};
// o período (de e ate, AAAA-MM-DD; o que não vier, o padrão em dias a partir de hoje: null deixa aberto)
$periodo = function ($p, $de_dias, $ate_dias) {
    $hoje = hoje_da_colecao()->format("Y-m-d");
    $res = [];
    foreach (["de" => $de_dias, "ate" => $ate_dias] as $k => $padrao) {
        $v = trim((string)($p[$k] ?? ""));
        if ($v === "") {
            $res[$k] = $padrao === null ? null : date("Y-m-d", strtotime($hoje . " " . ($padrao >= 0 ? "+" : "") . $padrao . " days"));
        } elseif (preg_match("/^\\d{4}-\\d{2}-\\d{2}\$/", $v) === 1 && date("Y-m-d", (int)strtotime($v)) === $v) {
            $res[$k] = $v;
        } else {
            throw new RelatorioErro(t("{1}: uma data AAAA-MM-DD", [1 => $k]), 400);
        }
    }
    if ($res["de"] !== null && $res["ate"] !== null && $res["de"] > $res["ate"]) {
        throw new RelatorioErro(t("o começo do período (de) é depois do fim (ate)"), 400);
    }
    return $res;
};
// o período em texto, para o subtítulo
$periodo_txt = function ($per) use ($data) {
    if ($per["de"] !== null && $per["ate"] !== null) {
        return t("de {1} a {2}", [1 => $data($per["de"]), 2 => $data($per["ate"])]);
    }
    if ($per["de"] !== null) {
        return t("desde {1}", [1 => $data($per["de"])]);
    }
    return $per["ate"] !== null ? t("até {1}", [1 => $data($per["ate"])]) : t("o tempo todo");
};
$dentro = function ($dia, $per) {
    $dia = substr((string)$dia, 0, 10);
    return ($per["de"] === null || $dia >= $per["de"]) && ($per["ate"] === null || $dia <= $per["ate"]);
};

// ---------- os parâmetros que os relatórios aceitam ----------
$p_relogio = function ($obrigatorio) {
    return ["nome" => "relogio", "rotulo" => t("Relógio"), "tipo" => "relogio", "obrigatorio" => $obrigatorio, "padrao" => null, "opcoes" => null,
        "vazio" => $obrigatorio ? null : t("todos")];
};
$p_data = function ($nome, $rotulo, $padrao_dias) {
    return ["nome" => $nome, "rotulo" => $rotulo, "tipo" => "data", "obrigatorio" => false,
        "padrao" => $padrao_dias === null ? null : date("Y-m-d", strtotime(date("Y-m-d") . " " . ($padrao_dias >= 0 ? "+" : "") . $padrao_dias . " days")), "opcoes" => null, "vazio" => null];
};
$p_opcoes = function ($nome, $rotulo, $opcoes, $padrao) {
    return ["nome" => $nome, "rotulo" => $rotulo, "tipo" => "opcoes", "obrigatorio" => true, "padrao" => $padrao,
        "opcoes" => array_map(function ($v, $n) { return ["valor" => $v, "nome" => $n]; }, array_keys($opcoes), array_values($opcoes)), "vazio" => null];
};

// ---------- o catálogo: tipo => [grupo, nome, descrição, parâmetros, montar(parâmetros): o relatório] ----------
$g_colecao = t("Coleção");
$g_uso = t("Uso e rodízio");
$g_manut = t("Manutenção e precisão");
$g_sistema = t("Sistema");
$RELATORIOS = [];

// o inventário: a galeria (os grupos e os relógios), numa tabela só, com o valor somado
$RELATORIOS["colecao"] = [$g_colecao, t("Inventário da coleção"), t("Os relógios da coleção (ou também os de fora: desejados, vendidos, arquivados), com o grupo, a marca, a referência, a situação, o ano e o valor da compra e os dias de uso; o valor somado."),
    [$p_opcoes("posse", t("Quais relógios"), ["colecao" => t("os da coleção"), "todos" => t("todos, também os de fora")], "colecao")],
    function ($p) use ($ler, $tabela, $reais) {
        $todos = ($p["posse"] ?? "") === "todos";
        $g = $ler("galeria", $todos ? ["posse" => "todos"] : []);
        $linhas = [];
        foreach ($g["grupos"] as $gr) {
            foreach ($gr["relogios"] as $r) {
                $linhas[] = [$r["nome"], $gr["caminho"], $r["marca"], $r["referencia"], $r["posse_texto"], $r["ano_compra"], $reais($r["valor_compra"]), $r["dias_de_uso"]];
            }
        }
        return ["subtitulo" => $todos ? t("Todos os relógios, também os de fora da coleção") : t("Os relógios da coleção"),
            "resumo" => [[t("Relógios"), (string)$g["total"]], [t("Valor somado"), $reais($g["valor_total"])], [t("Com foto"), (string)$g["com_foto"]]],
            "secoes" => [$tabela(t("Relógios"), [t("Relógio"), t("Grupo"), t("Marca"), t("Referência"), t("Situação"), [t("Ano da compra"), true], [t("Valor pago"), true], [t("Dias de uso"), true]],
                $linhas, t("Nenhum relógio."), count($linhas) > 0 ? [t("Total"), "", "", "", "", "", $reais($g["valor_total"]), ""] : null)]];
    }];

// o passaporte: a ficha inteira de um relógio (o recurso=passaporte, seção por seção)
$RELATORIOS["passaporte"] = [$g_colecao, t("Passaporte do relógio"), t("A ficha inteira de um relógio: quem ele é, a compra, o uso, o cadastro, as manutenções, os serviços e diagnósticos, o declarado, medido e estimado, os testes da reserva, o timegrapher, os acessórios, os documentos e os próximos avisos."),
    [$p_relogio(true)],
    function ($p) use ($ler, $relogio, $tabela, $pares, $data, $data_hora, $reais) {
        $r = $relogio($p, true);
        $d = $ler("passaporte", ["relogio" => (int)$r["id"]]);
        $x = $d["relogio"];
        $c = $d["compra"];
        $u = $d["uso"];
        $sec = [];
        $sec[] = $pares(t("Quem ele é"), [[t("Código"), (string)$x["id"]], [t("Nome"), $x["nome"]], [t("Marca"), $x["marca"]], [t("Referência"), $x["referencia"]],
            [t("Grupo"), $x["caminho"]], [t("Situação"), $x["posse_texto"] . ($x["posse"] === "colecao" ? ($x["disponivel"] ? t(" · no rodízio") : t(" · fora do rodízio")) : "")],
            [t("No sistema desde"), $data($x["criado"])], [t("Observação"), $x["observacao"]]]);
        $sec[] = $pares(t("Compra"), [[t("Data"), $data($c["data"])], [t("Valor pago"), $reais($c["valor"])], [t("Loja"), $c["loja"]], [t("Garantia até"), $data($c["garantia_ate"])]],
            t("Sem dados da compra."));
        $sec[] = $pares(t("Uso"), [[t("Primeira vez no pulso"), $data($u["primeira_vez"])], [t("Última vez"), $u["no_pulso_agora"] ? t("no pulso agora") : $data($u["ultima_vez"])],
            [t("Horas no pulso"), $u["primeira_vez"] === null ? null : numero_txt($u["horas_no_pulso"], 1) . t(" h")],
            [t("Dias de uso"), $u["dias_de_uso"] === null ? null : (string)$u["dias_de_uso"]], [t("Custo por uso"), $reais($u["custo_por_uso"])]], t("Ainda não foi ao pulso."));
        // o cadastro, uma seção por divisória (o que não tem divisória sai como "Cadastro")
        if (count($d["cadastro_secoes"]) === 0) {
            $sec[] = $pares(t("Cadastro"), [], t("Nada preenchido."));
        }
        foreach ($d["cadastro_secoes"] as $g) {
            $sec[] = $pares($g["secao"] ?? t("Cadastro"), array_map(function ($v) { return [$v["nome"], $v["valor"]]; }, $g["campos"]));
        }
        $sec[] = $tabela(t("Manutenções"), [t("Marcação"), [t("Vezes"), true], t("A última")], array_map(function ($m) use ($data) {
            return [$m["nome"], (string)$m["vezes"], $data($m["ultima"])];
        }, $d["marcacoes"]), t("Nenhuma marcação."));
        $sec[] = $tabela(t("Serviços e diagnósticos"), [t("Data"), t("Tipo"), t("Oficina"), [t("Custo"), true], t("Estanqueidade"), t("Marcha antes"), t("Marcha depois"), t("Peças"), t("Descrição")],
            array_map(function ($s) use ($data, $reais) {
                return [$data($s["data"]), $s["tipo_texto"], $s["oficina"], $reais($s["custo"]),
                    $s["estanqueidade_texto"] === null ? null : $s["estanqueidade_texto"] . ($s["estanqueidade_bar"] !== null ? t(" a {1} bar", [1 => decimal_txt($s["estanqueidade_bar"], 2)]) : ""),
                    $s["marcha_antes_texto"], $s["marcha_depois_texto"], implode(", ", array_column($s["pecas"], "nome")), $s["descricao"]];
            }, $d["servicos_diagnosticos"]), t("Nenhum serviço."));
        $sec[] = $tabela(t("Declarado, medido e estimado"), [t("O quê"), t("Declarado"), t("Último teste"), t("Estimativa"), t("Confiança")], array_map(function ($t_) {
            return [$t_["nome"], $t_["declarado"]["texto"], $t_["medido"]["texto"], $t_["estimado"]["texto"], $t_["confianca"]["resumo"] ?? null];
        }, $d["tecnico"]), t("Nada a comparar."));
        $sec[] = $tabela(t("Testes da reserva"), [t("Começo"), t("Fim"), [t("Horas"), true], t("Como foi carregado"), t("Observação")], array_map(function ($x) use ($data_hora) {
            return [$data_hora($x["inicio"]), $x["em_andamento"] ? t("em andamento") : $data_hora($x["fim"]), $x["horas_texto"], $x["protocolo_texto"], $x["observacao"]];
        }, $d["reserva_testes"]), t("Nenhum teste da reserva."));
        $sec[] = $tabela(t("Timegrapher"), [t("Quando"), t("Posição"), [t("Marcha"), true], [t("Amplitude"), true], [t("Erro de batida"), true], [t("Batidas por hora"), true], [t("Ângulo de levantamento"), true]],
            array_map(function ($m) use ($data_hora) {
                return [$data_hora($m["quando"]), $m["posicao_texto"], $m["marcha_texto"], $m["amplitude_texto"], $m["erro_batida_texto"], $m["bph_texto"], $m["angulo_texto"]];
            }, $d["timegrapher"]), t("Nenhuma medição do timegrapher."));
        $sec[] = $tabela(t("Acessórios"), [t("Tipo"), t("Nome"), t("Dados"), t("Observação")], array_map(function ($a) {
            return [$a["tipo_texto"], $a["nome"], implode("; ", array_map(function ($x) { return $x["nome"] . ": " . $x["texto"]; }, $a["valores"])), $a["observacao"]];
        }, $d["acessorios"]), t("Nenhum."));
        $sec[] = $tabela(t("Documentos"), [t("Categoria"), t("Título"), t("Data"), t("Arquivo")], array_map(function ($x) use ($data) {
            return [$x["categoria"], $x["titulo"], $data($x["data"]), $x["nome"]];
        }, $d["documentos"]), t("Nenhum."));
        $sec[] = $tabela(t("Próximos avisos"), [t("Aviso"), t("Quando")], array_map(function ($a) {
            return [$a["nome"], $a["texto"]];
        }, $d["proximos_avisos"]), t("Nenhum aviso."));
        return ["subtitulo" => t("{1} · código {2}", [1 => $x["nome"], 2 => $x["id"]]),
            "foto" => $x["foto"] !== null ? "api.php?recurso=foto&relogio=" . (int)$x["id"] . "&v=" . (int)$x["foto"] : null,
            "resumo" => [], "secoes" => $sec];
    }];

// os custos: o que cada relógio custou (a compra e os serviços) e o custo de cada uso
$RELATORIOS["custos"] = [$g_colecao, t("Custos da coleção"), t("Quanto cada relógio custou: o valor pago, os serviços (quantos e quanto), o total, os dias de uso e o custo de cada uso (o total dividido pelos dias de uso); as somas da coleção."),
    [],
    function ($p) use ($ler, $tabela, $reais) {
        $e = $ler("estatisticas", ["de" => "tudo"]);
        $serv = [];
        foreach (servicos_lista(null) as $s) {
            $k = (int)$s["relogio_id"];
            $serv[$k] = [($serv[$k][0] ?? 0) + 1, ($serv[$k][1] ?? 0) + (float)($s["custo"] ?? 0)];
        }
        $linhas = [];
        $soma = [0.0, 0, 0.0, 0.0, 0];
        foreach ($e["relogios"] as $r) {
            $n_s = $serv[(int)$r["id"]][0] ?? 0;
            $c_s = round($serv[(int)$r["id"]][1] ?? 0, 2);
            $total = round((float)($r["valor_compra"] ?? 0) + $c_s, 2);
            $dias = (int)($r["dias_de_uso"] ?? 0);
            $linhas[] = [$r["nome"], $reais($r["valor_compra"]), (string)$n_s, $n_s > 0 ? $reais($c_s) : null, $reais($total), (string)$dias, $dias > 0 ? $reais(round($total / $dias, 2)) : null];
            $soma = [$soma[0] + (float)($r["valor_compra"] ?? 0), $soma[1] + $n_s, $soma[2] + $c_s, $soma[3] + $total, $soma[4] + $dias];
        }
        return ["subtitulo" => t("Desde a primeira compra"),
            "resumo" => [[t("Valor pago"), $reais(round($soma[0], 2))], [t("Serviços"), $reais(round($soma[2], 2))], [t("Total"), $reais(round($soma[3], 2))],
                [t("Custo por uso"), $soma[4] > 0 ? $reais(round($soma[3] / $soma[4], 2)) : null]],
            "secoes" => [$tabela(t("Por relógio"), [t("Relógio"), [t("Valor pago"), true], [t("Serviços"), true], [t("Custo dos serviços"), true], [t("Total"), true], [t("Dias de uso"), true], [t("Custo por uso"), true]],
                $linhas, t("Nenhum relógio."), count($linhas) > 0 ? [t("Total"), $reais(round($soma[0], 2)), (string)$soma[1], $reais(round($soma[2], 2)), $reais(round($soma[3], 2)), (string)$soma[4],
                    $soma[4] > 0 ? $reais(round($soma[3] / $soma[4], 2)) : null] : null)]];
    }];

// a lista de desejos e os relógios fora da coleção
$RELATORIOS["desejos"] = [$g_colecao, t("Lista de desejos"), t("Os relógios desejados, da prioridade mais alta para a mais baixa, com a marca, a referência, o preço-alvo e o link; a soma dos preços; e os que saíram da coleção (vendidos, arquivados)."),
    [],
    function ($p) use ($ler, $tabela, $reais) {
        $d = $ler("desejos");
        return ["subtitulo" => null,
            "resumo" => [[t("Desejados"), (string)count($d["desejados"])], [t("Soma dos preços-alvo"), $reais($d["total_preco_alvo"])], [t("Sem preço-alvo"), (string)$d["sem_preco_alvo"]]],
            "secoes" => [$tabela(t("Desejados"), [t("Relógio"), t("Grupo"), t("Marca"), t("Referência"), t("Prioridade"), [t("Preço-alvo"), true], t("Link")], array_map(function ($x) use ($reais) {
                return [$x["nome"], $x["caminho"], $x["marca"], $x["referencia"], $x["prioridade"], $reais($x["preco_alvo"]), $x["link"]];
            }, $d["desejados"]), t("Nenhum relógio desejado."), count($d["desejados"]) > 0 ? [t("Total"), "", "", "", "", $reais($d["total_preco_alvo"]), ""] : null),
                $tabela(t("Fora da coleção"), [t("Relógio"), t("Grupo"), t("Situação")], array_map(function ($x) {
                    return [$x["nome"], $x["caminho"], $x["posse_texto"]];
                }, $d["fora"]), t("Nenhum."))]];
    }];

// os acessórios: de cada relógio e os soltos
$RELATORIOS["acessorios"] = [$g_colecao, t("Acessórios"), t("Os acessórios (pulseiras, caixas, estojos, ferramentas...) de cada relógio e os soltos, e quantos de cada tipo."),
    [$p_relogio(false)],
    function ($p) use ($relogio, $tabela) {
        $r = $relogio($p, false);
        $lista = acessorios_lista($r ? (int)$r["id"] : null);
        $tipos = [];
        foreach ($lista as $a) {
            $tipos[$a["tipo_texto"]] = ($tipos[$a["tipo_texto"]] ?? 0) + 1;
        }
        return ["subtitulo" => $r ? $r["nome"] : t("Todos os relógios"),
            "resumo" => [[t("Acessórios"), (string)count($lista)], [t("Soltos"), $r ? null : (string)count(array_filter($lista, function ($a) { return $a["relogio_id"] === null; }))]],
            "secoes" => [$tabela(t("Acessórios"), [t("Tipo"), t("Nome"), t("Relógio"), t("Dados"), t("Serve em"), t("Observação")], array_map(function ($a) {
                return [$a["tipo_texto"], $a["nome"], $a["relogio_id"] === null ? t("solto") : $a["relogio"],
                    implode("; ", array_map(function ($x) { return $x["nome"] . ": " . $x["texto"]; }, $a["valores"])),
                    $a["serve_em"] === null ? null : (count($a["serve_em"]) > 0 ? implode(", ", array_column($a["serve_em"], "relogio")) : t("nenhum relógio")), $a["observacao"]];
            }, $lista), t("Nenhum acessório.")),
                $tabela(t("Por tipo"), [t("Tipo"), [t("Quantos"), true]], array_map(function ($k, $n) { return [$k, (string)$n]; }, array_keys($tipos), array_values($tipos)), t("Nenhum."))]];
    }];

// os documentos de todos os relógios e calibres
$RELATORIOS["documentos"] = [$g_colecao, t("Documentos"), t("Os documentos (manuais, notas fiscais, fotos, vídeos...) de cada relógio e de cada calibre, com a categoria, a data, o arquivo e o tamanho."),
    [$p_relogio(false)],
    function ($p) use ($ler, $relogio, $tabela, $data) {
        $r = $relogio($p, false);
        $d = $ler("documentos", $r ? ["relogio" => (int)$r["id"]] : []);
        $cats = array_column($d["categorias"], "nome", "id");
        $rels = array_column($d["relogios"], "nome", "id");
        $cals = array_column($d["calibres"], "nome", "id");
        $total = 0;
        $linhas = array_map(function ($x) use ($cats, $rels, $cals, $data, &$total) {
            $total += (int)$x["tamanho"];
            return [$x["calibre_id"] !== null ? t("calibre {1}", [1 => $cals[$x["calibre_id"]] ?? $x["calibre_id"]]) : ($rels[$x["relogio_id"]] ?? (string)$x["relogio_id"]),
                $cats[$x["categoria_id"]] ?? "", $x["titulo"], $data($x["data"]), $x["nome"], tamanho_texto((int)$x["tamanho"])];
        }, $d["documentos"]);
        return ["subtitulo" => $r ? $r["nome"] : t("Todos os relógios e calibres"),
            "resumo" => [[t("Documentos"), (string)count($linhas)], [t("Tamanho somado"), tamanho_texto($total)]],
            "secoes" => [$tabela(t("Documentos"), [t("De"), t("Categoria"), t("Título"), t("Data"), t("Arquivo"), [t("Tamanho"), true]], $linhas, t("Nenhum documento."))]];
    }];

// a linha do tempo (o recurso=historico): cada trecho e o tempo de cada relógio em cada estado
$RELATORIOS["historico"] = [$g_uso, t("Linha do tempo"), t("O que cada relógio fez no período (no pulso, no rodízio, no winder, no sol, no carregador, em repouso e as marcações), trecho a trecho, e quanto tempo cada um passou em cada estado."),
    [$p_relogio(false), $p_data("de", t("De"), -30), $p_data("ate", t("Até"), 0)],
    function ($p) use ($ler, $relogio, $periodo, $periodo_txt, $tabela, $data_hora) {
        $r = $relogio($p, false);
        $per = $periodo($p, -30, 0);
        $d = $ler("historico", array_merge(["de" => $per["de"], "ate" => $per["ate"], "limite" => 1000, "ordem" => "asc"], $r ? ["relogio" => (int)$r["id"]] : []));
        // os estados que a API conhece (os tipos de sessão da coleção), menos as marcações, que não têm tempo
        $estados = array_values(array_diff(array_keys($d["estados"]), ["marca"]));
        $resumo_t = [];
        foreach ($d["resumo"] as $x) {
            $l = [$x["relogio"]];
            foreach ($estados as $e) {
                $te = $x["tempo"][$e] ?? null;
                $l[] = $te === null || (int)$te["segundos"] === 0 ? null : t("{1} ({2}%)", [1 => $te["texto"], 2 => numero_txt($te["porcentagem"], 1)]);
            }
            $resumo_t[] = $l;
        }
        return ["subtitulo" => ($r ? $r["nome"] : t("Todos os relógios")) . " · " . $periodo_txt($per),
            "resumo" => [[t("Trechos"), (string)$d["total"]]],
            "secoes" => [$tabela(t("Tempo em cada estado"), array_merge([t("Relógio")], array_map(function ($e) use ($d) { return $d["estados"][$e]; }, $estados)), $resumo_t, t("Nenhum relógio.")),
                $tabela(t("Trecho a trecho"), [t("Começo"), t("Fim"), [t("Duração"), true], t("Relógio"), t("O quê")], array_map(function ($l) use ($data_hora) {
                    return [$data_hora($l["inicio"]), $l["em_andamento"] ? t("em andamento") : $data_hora($l["fim"]), $l["duracao"], $l["relogio"], $l["texto"]];
                }, $d["linhas"]), t("Nada no período."), null, $d["total"] > count($d["linhas"]) ? t("Os {1} primeiros de {2} trechos.", [1 => count($d["linhas"]), 2 => $d["total"]]) : null)]];
    }];

// o uso no período (o recurso=estatisticas)
$RELATORIOS["estatisticas"] = [$g_uso, t("Uso no período"), t("As estatísticas do período: os dias com relógio, as horas, o mais e o menos usado, o valor da coleção; cada relógio (usos, horas, a parte de cada um, a tendência, o custo por uso); e o uso por dia da semana e por mês."),
    [$p_data("de", t("De"), -89), $p_data("ate", t("Até"), 0)],
    function ($p) use ($ler, $periodo, $periodo_txt, $tabela, $data, $reais) {
        // de=tudo: desde o primeiro uso (como na tela)
        $tudo = trim((string)($p["de"] ?? "")) === "tudo";
        $per = $periodo($tudo ? array_merge($p, ["de" => ""]) : $p, -89, 0);
        $e = $ler("estatisticas", ["de" => $tudo ? "tudo" : $per["de"], "ate" => $per["ate"]]);
        $s = $e["resumo"];
        return ["subtitulo" => $periodo_txt(["de" => $e["de"], "ate" => $e["ate"]]),
            "resumo" => [[t("Dias"), (string)$s["dias"]], [t("Dias com relógio"), (string)$s["dias_com_relogio"]], [t("Horas no pulso"), numero_txt($s["horas"], 1)],
                [t("Horas por dia com relógio"), numero_txt($s["horas_por_dia"], 1)], [t("Relógios usados"), t("{1} de {2}", [1 => $s["relogios_usados"], 2 => $s["relogios_na_colecao"]])],
                [t("O mais usado"), $s["mais_usado"] ? $s["mais_usado"]["nome"] : null], [t("O menos usado"), $s["menos_usado"] ? $s["menos_usado"]["nome"] : null],
                [t("Valor da coleção"), $reais($s["valor_colecao"])], [t("Custo por uso médio"), $reais($s["custo_por_uso_medio"])]],
            "secoes" => [$tabela(t("Por relógio"), [t("Relógio"), [t("Usos"), true], [t("Horas"), true], [t("Parte"), true], t("Último uso"), [t("Dias sem uso"), true], [t("Custo por uso"), true], t("Tendência")],
                array_map(function ($r) use ($data, $reais) {
                    return [$r["nome"], (string)$r["usos"], numero_txt($r["horas"], 1), numero_txt($r["porcentagem"], 1) . "%", $data($r["ultimo_uso"]),
                        $r["dias_sem_uso"] === null ? null : (string)$r["dias_sem_uso"], $reais($r["custo_por_uso"]), $r["tendencia_texto"]];
                }, $e["relogios"]), t("Nenhum relógio.")),
                $tabela(t("Por dia da semana"), [t("Dia"), [t("Dias com uso"), true], [t("Horas"), true], [t("Média por dia"), true], [t("Parte"), true]], array_map(function ($x) {
                    return [$x["nome"], t("{1} de {2}", [1 => $x["dias_com_uso"], 2 => $x["dias"]]), numero_txt($x["horas"], 1), numero_txt($x["media_horas"], 1), numero_txt($x["porcentagem"], 1) . "%"];
                }, $e["por_dia_semana"]), t("Nada.")),
                $tabela(t("Por mês"), [t("Mês"), [t("Dias com uso"), true], [t("Relógios"), true], [t("Horas"), true], [t("Média por dia"), true], [t("Parte"), true]], array_map(function ($x) {
                    return [$x["nome"], t("{1} de {2}", [1 => $x["dias_com_uso"], 2 => $x["dias"]]), (string)$x["relogios"], numero_txt($x["horas"], 1), numero_txt($x["media_horas"], 1), numero_txt($x["porcentagem"], 1) . "%"];
                }, $e["por_mes"]), t("Nada."))]];
    }];

// o plano do rodízio (o recurso=plano): cada dia e quantos dias cada relógio tem
$RELATORIOS["plano"] = [$g_uso, t("Plano do rodízio"), t("O plano dia a dia no período (o relógio de cada dia, como ele foi escolhido e o motivo) e quantos dias cada relógio tem."),
    [$p_data("de", t("De"), 0), $p_data("ate", t("Até"), 29)],
    function ($p) use ($ler, $periodo, $periodo_txt, $tabela, $data) {
        $per = $periodo($p, 0, 29);
        $d = $ler("plano", ["de" => $per["de"], "ate" => $per["ate"], "por_pagina" => 400]);
        $dias = $d["dia_a_dia"]["dias"];
        return ["subtitulo" => $d["modo"] . " · " . $periodo_txt($per),
            "resumo" => [[t("Modo"), $d["modo"]], [t("Dias"), (string)$d["resumo"]["dias"]], [t("Relógios"), (string)count($d["resumo"]["relogios"])]],
            "secoes" => [$tabela(t("Dia a dia"), [t("Data"), t("Dia"), t("Relógio"), t("Motivo")], array_map(function ($x) use ($data) {
                return [$data($x["data"]), $x["dia_semana_curto"], $x["relogio"] ?? t("livre"), $x["motivo"]];
            }, $dias), t("Nada planejado no período.")),
                $tabela(t("Dias de cada relógio"), [t("Relógio"), [t("Dias"), true], [t("Parte"), true], t("O próximo")], array_map(function ($x) use ($data) {
                    return [$x["relogio"], (string)$x["dias"], numero_txt($x["porcentagem"], 1) . "%", $data($x["proximo"])];
                }, $d["resumo"]["relogios"]), t("Nenhum relógio."))]];
    }];

// os avisos de agora, do mais urgente ao mais distante
$RELATORIOS["avisos"] = [$g_uso, t("Avisos"), t("Os avisos de todos os relógios agora, do atrasado ao mais distante (o que é, de qual relógio, quando vence e a situação), e quantos de cada situação."),
    [$p_relogio(false)],
    function ($p) use ($relogio, $tabela) {
        $r = $relogio($p, false);
        $nomes = ["atrasado" => t("atrasado"), "em_breve" => t("em breve"), "ok" => t("em dia")];
        $lista = array_values(array_filter(avisos_de_agora(), function ($a) use ($r) { return $r === null || $a["relogio_id"] === (int)$r["id"]; }));
        $conta = ["atrasado" => 0, "em_breve" => 0, "ok" => 0];
        foreach ($lista as $a) {
            $conta[$a["estado"]] = ($conta[$a["estado"]] ?? 0) + 1;
        }
        return ["subtitulo" => $r ? $r["nome"] : t("Todos os relógios"),
            "resumo" => [[t("Atrasados"), (string)$conta["atrasado"]], [t("Em breve"), (string)$conta["em_breve"]], [t("Em dia"), (string)$conta["ok"]]],
            "secoes" => [$tabela(t("Avisos"), [t("Relógio"), t("Aviso"), t("Quando"), t("Situação")], array_map(function ($a) use ($nomes) {
                return [$a["relogio"], $a["nome"], $a["texto"], $nomes[$a["estado"]] ?? $a["estado"]];
            }, $lista), t("Nenhum aviso."))]];
    }];

// as notas dos critérios: a ordem dos relógios e a conta de cada um
$RELATORIOS["criterios"] = [$g_uso, t("Notas dos critérios"), t("A nota de cada relógio pelos critérios de escolha, da maior para a menor, e a conta de cada um: cada parâmetro com o valor, a faixa, a nota, o peso e os pontos."),
    [],
    function ($p) use ($tabela) {
        $c = montar_criterios();
        $notas = $c["notas"];
        usort($notas, function ($a, $b) { return [$b["nota"], $a["relogio"]] <=> [$a["nota"], $b["relogio"]]; });
        $conta = [];
        foreach ($notas as $n) {
            foreach ($n["conta"] as $x) {
                $conta[] = [$n["relogio"], $x["parametro"], $x["subparametro"], is_numeric($x["valor"]) ? decimal_txt($x["valor"], 2) : $x["valor"], $x["faixa"],
                    numero_txt($x["nota"], 1), numero_txt($x["peso_efetivo"], 1), numero_txt($x["pontos"], 2)];
            }
        }
        $pos = 0;
        return ["subtitulo" => t("{1} relógios", [1 => count($notas)]),
            "resumo" => [],
            "secoes" => [$tabela(t("A ordem"), [[t("Posição"), true], t("Relógio"), [t("Nota"), true], t("Critérios"), t("No rodízio")], array_map(function ($n) use (&$pos) {
                $pos++;
                return [(string)$pos, $n["relogio"], numero_txt($n["nota"], 2), $n["conjunto_texto"], $n["disponivel"] ? t("sim") : t("não")];
            }, $notas), t("Nenhum relógio.")),
                $tabela(t("A conta de cada relógio"), [t("Relógio"), t("Parâmetro"), t("Subparâmetro"), [t("Valor"), true], t("Faixa"), [t("Nota"), true], [t("Peso"), true], [t("Pontos"), true]],
                    $conta, t("Nada."))]];
    }];

// os serviços e diagnósticos (o recurso=servicos), no período
$RELATORIOS["manutencao_oficinas"] = [$g_manut, t("Em manutenção, por oficina"), t("Os relógios na oficina agora, agrupados por oficina: a situação, desde quando, a previsão (e se está atrasada), o orçamento e os contatos da oficina."),
    [],
    function ($p) use ($ler, $tabela, $data, $reais) {
        $d = $ler("manutencao");
        $secoes = [];
        foreach ($d["oficinas"] as $o) {
            $abertas = array_values(array_filter($d["abertas"], function ($m) use ($o) { return $m["oficina_id"] === $o["id"]; }));
            if (count($abertas) === 0) {
                continue;
            }
            $contatos = implode(" · ", array_map(function ($c) { return $c["tipo_texto"] . ": " . $c["valor"] . ($c["pessoa"] !== null ? " (" . $c["pessoa"] . ")" : ""); }, $o["contatos"]));
            $secoes[] = $tabela($o["nome"], [t("Relógio"), t("Situação"), t("Enviado"), [t("Dias"), true], t("Previsão"), [t("Orçamento"), true], t("OS")],
                array_map(function ($m) use ($data, $reais) {
                    return [$m["relogio"], $m["situacao_texto"], $data($m["enviado"]), (string)$m["dias"], $m["previsao"] === null ? null : $data($m["previsao"]) . ($m["atrasada"] ? " " . t("(atrasada)") : ""),
                        $reais($m["orcamento"]), $m["os"]];
                }, $abertas), t("Nenhum."), null, trim(($o["endereco_texto"] ?? "") . ($contatos !== "" ? " · " . $contatos : "")) ?: null);
        }
        return ["subtitulo" => t("Agora"), "resumo" => [[t("Em manutenção"), (string)$d["resumo"]["abertas"]], [t("Atrasadas"), (string)$d["resumo"]["atrasadas"]]],
            "secoes" => count($secoes) > 0 ? $secoes : [$tabela(t("Em manutenção"), [t("Relógio")], [], t("Nenhum relógio na oficina agora."))]];
    }];

$RELATORIOS["manutencoes"] = [$g_manut, t("Manutenções no período"), t("Cada manutenção aberta em algum dia do período: o relógio, a oficina, o tipo, a situação, o envio, a volta, quantos dias ficou e quanto custou (os serviços dela); quantas e o custo somado."),
    [$p_relogio(false), $p_data("de", t("De"), -365), $p_data("ate", t("Até"), null)],
    function ($p) use ($ler, $relogio, $periodo, $periodo_txt, $tabela, $data, $reais) {
        $r = $relogio($p, false);
        $per = $periodo($p, -365, null);
        $d = $ler("manutencao", array_filter(["relogio" => $r ? (int)$r["id"] : null, "de" => $per["de"], "ate" => $per["ate"]], function ($v) { return $v !== null; }));
        $lista = $d["manutencoes"];
        $soma = array_sum(array_map(function ($m) { return (float)($m["custo"] ?? 0); }, $lista));
        return ["subtitulo" => ($r ? $r["nome"] : t("Todos os relógios")) . " · " . $periodo_txt($per),
            "resumo" => [[t("Manutenções"), (string)count($lista)], [t("Custo somado"), $reais(round($soma, 2))]],
            "secoes" => [$tabela(t("Manutenções"), [t("Relógio"), t("Oficina"), t("Tipo"), t("Situação"), t("Enviado"), t("Voltou"), [t("Dias"), true], [t("Custo"), true]],
                array_map(function ($m) use ($data, $reais) {
                    return [$m["relogio"], $m["oficina"], $m["tipo_texto"], $m["situacao_texto"], $data($m["enviado"]), $data($m["encerrado"]), (string)$m["dias"], $reais($m["custo"])];
                }, $lista), t("Nenhuma manutenção no período."), count($lista) > 0 ? [t("Total"), "", "", "", "", "", "", $reais(round($soma, 2))] : null)]];
    }];

$RELATORIOS["gasto_oficinas"] = [$g_manut, t("Gasto por oficina"), t("Quanto cada oficina custou no período (os serviços das manutenções dela), quantas manutenções, quantos dias os relógios ficaram lá e a média de dias; o total."),
    [$p_data("de", t("De"), -365), $p_data("ate", t("Até"), null)],
    function ($p) use ($ler, $periodo, $periodo_txt, $tabela, $reais) {
        $per = $periodo($p, -365, null);
        $d = $ler("manutencao", array_filter(["de" => $per["de"], "ate" => $per["ate"]], function ($v) { return $v !== null; }));
        $por = [];
        foreach ($d["manutencoes"] as $m) {
            $x = $por[$m["oficina"]] ?? [0, 0, 0.0];
            $por[$m["oficina"]] = [$x[0] + 1, $x[1] + $m["dias"], $x[2] + (float)($m["custo"] ?? 0)];
        }
        uasort($por, function ($a, $b) { return $b[2] <=> $a[2]; });
        $soma = array_sum(array_column($por, 2));
        return ["subtitulo" => $periodo_txt($per), "resumo" => [[t("Oficinas"), (string)count($por)], [t("Gasto somado"), $reais(round($soma, 2))]],
            "secoes" => [$tabela(t("Por oficina"), [t("Oficina"), [t("Manutenções"), true], [t("Dias na oficina"), true], [t("Média de dias"), true], [t("Gasto"), true]],
                array_map(function ($nome, $x) use ($reais) {
                    return [$nome, (string)$x[0], (string)$x[1], decimal_txt($x[1] / max(1, $x[0]), 1), $reais(round($x[2], 2))];
                }, array_keys($por), array_values($por)), t("Nenhuma manutenção no período."), count($por) > 0 ? [t("Total"), (string)array_sum(array_column($por, 0)), (string)array_sum(array_column($por, 1)), "", $reais(round($soma, 2))] : null)]];
    }];

$RELATORIOS["servicos"] = [$g_manut, t("Serviços e diagnósticos"), t("Cada serviço no período (revisão, pilha, estanqueidade, regulagem, polimento, reparo, diagnóstico): o relógio, a oficina, as peças, o custo, a estanqueidade e a marcha antes e depois; o custo somado e por tipo."),
    [$p_relogio(false), $p_data("de", t("De"), null), $p_data("ate", t("Até"), null)],
    function ($p) use ($ler, $relogio, $periodo, $periodo_txt, $dentro, $tabela, $data, $reais) {
        $r = $relogio($p, false);
        $per = $periodo($p, null, null);
        $d = $ler("servicos", $r ? ["relogio" => (int)$r["id"]] : []);
        $lista = array_values(array_filter($d["servicos"], function ($s) use ($dentro, $per) { return $dentro($s["data"], $per); }));
        $soma = 0.0;
        $tipos = [];
        foreach ($lista as $s) {
            $soma += (float)($s["custo"] ?? 0);
            $tipos[$s["tipo_texto"]] = [($tipos[$s["tipo_texto"]][0] ?? 0) + 1, ($tipos[$s["tipo_texto"]][1] ?? 0) + (float)($s["custo"] ?? 0)];
        }
        return ["subtitulo" => ($r ? $r["nome"] : t("Todos os relógios")) . " · " . $periodo_txt($per),
            "resumo" => [[t("Serviços"), (string)count($lista)], [t("Custo somado"), $reais(round($soma, 2))]],
            "secoes" => [$tabela(t("Serviços"), [t("Data"), t("Relógio"), t("Tipo"), t("Oficina"), [t("Mão de obra"), true], t("Peças"), [t("Custo"), true], t("Estanqueidade"), t("Marcha antes"), t("Marcha depois"), t("Descrição")],
                array_map(function ($s) use ($data, $reais) {
                    return [$data($s["data"]), $s["relogio"], $s["tipo_texto"], $s["oficina"], $reais($s["mao_de_obra"]),
                        implode(", ", array_map(function ($x) use ($reais) { return $x["nome"] . ($x["custo"] !== null ? " (" . $reais($x["custo"]) . ")" : ""); }, $s["pecas"])),
                        $reais($s["custo"]), $s["estanqueidade_texto"] === null ? null : $s["estanqueidade_texto"] . ($s["estanqueidade_bar"] !== null ? t(" a {1} bar", [1 => decimal_txt($s["estanqueidade_bar"], 2)]) : ""),
                        $s["marcha_antes_texto"], $s["marcha_depois_texto"], $s["descricao"]];
                }, $lista), t("Nenhum serviço no período."), count($lista) > 0 ? [t("Total"), "", "", "", "", "", $reais(round($soma, 2)), "", "", "", ""] : null),
                $tabela(t("Por tipo"), [t("Tipo"), [t("Quantos"), true], [t("Custo"), true]], array_map(function ($k, $v) use ($reais) {
                    return [$k, (string)$v[0], $reais(round($v[1], 2))];
                }, array_keys($tipos), array_values($tipos)), t("Nenhum."))]];
    }];

// a precisão: a marcha de cada relógio, ou a de um, com cada intervalo medido
$RELATORIOS["precisao"] = [$g_manut, t("Precisão e marcha"), t("A marcha (s/dia) de cada relógio pelas leituras do desvio da hora; com um relógio, a conta inteira: a marcha, a variação, a última, o desvio agora, a esperada e cada intervalo medido, com a posição."),
    [$p_relogio(false)],
    function ($p) use ($ler, $relogio, $tabela, $pares, $data_hora) {
        $r = $relogio($p, false);
        if ($r === null) {
            $d = $ler("precisao");
            return ["subtitulo" => t("Todos os relógios que medem a marcha"), "resumo" => [],
                "secoes" => [$tabela(t("Por relógio"), [t("Relógio"), [t("Medições"), true], [t("Marcha"), true], [t("A última"), true], [t("Desvio agora"), true], t("Resumo")], array_map(function ($x) {
                    return [$x["relogio"], (string)$x["medicoes"], $x["marcha_texto"], $x["ultima_texto"], $x["desvio_agora_texto"], $x["resumo"]];
                }, $d["relogios"]), t("Nenhum relógio mede a marcha."))]];
        }
        $d = $ler("precisao", ["relogio" => (int)$r["id"]]);
        $x = $d["precisao"];
        if ($x === null) {
            return ["subtitulo" => $r["nome"], "resumo" => [],
                "secoes" => [$pares(t("A marcha"), [], t("Este relógio não mede a marcha (nenhum lançamento de desvio da hora no grupo dele)."))]];
        }
        return ["subtitulo" => $r["nome"], "resumo" => [[t("Resumo"), $x["resumo"]]],
            "secoes" => [$pares(t("A marcha"), [[t("Marcha"), $x["marcha_texto"]], [t("Variação"), $x["marcha_dp_texto"]], [t("A última"), $x["ultima_texto"]],
                [t("Desvio agora"), $x["desvio_agora_texto"]], [t("Esperada"), $x["esperada"]["texto"] ?? null], [t("Medições"), (string)$x["medicoes"]]]),
                $tabela(t("Os intervalos medidos"), [t("De"), t("Até"), [t("Dias"), true], [t("Marcha"), true], t("Posição")], array_map(function ($i) use ($data_hora) {
                    return [$data_hora($i["de"]), $data_hora($i["ate"]), numero_txt($i["dias"], 2), $i["marcha_texto"], $i["posicao_texto"]];
                }, $x["intervalos"]), t("Nenhum intervalo medido."))]];
    }];

// o timegrapher: as medições (de um relógio, com a última sessão; ou de todos)
$RELATORIOS["timegrapher"] = [$g_manut, t("Timegrapher"), t("As medições do timegrapher (a marcha, a amplitude, o erro de batida, as batidas por hora e o ângulo de levantamento, em cada posição); com um relógio, também a última sessão."),
    [$p_relogio(false)],
    function ($p) use ($ler, $relogio, $tabela, $pares, $data_hora) {
        $r = $relogio($p, false);
        $d = $ler("timegrapher", $r ? ["relogio" => (int)$r["id"]] : []);
        $lista = $r ? $d["timegrapher"]["medicoes"] : $d["medicoes"];
        $sec = [];
        if ($r && $d["timegrapher"]["sessao"] !== null) {
            $s = $d["timegrapher"]["sessao"];
            $sec[] = $pares(t("Última sessão"), [[t("Dia"), data_txt($s["dia"])], [t("Marcha"), $s["marcha_texto"]], [t("Amplitude"), $s["amplitude_texto"]], [t("Erro de batida"), $s["erro_batida_texto"]],
                [t("Batidas por hora"), $s["bph_texto"]], [t("Ângulo de levantamento"), $s["angulo_texto"]], [t("Diferença entre as posições"), $s["delta_texto"]], [t("Medições"), (string)$s["medicoes"]]]);
        }
        $sec[] = $tabela(t("Medições"), [t("Quando"), t("Relógio"), t("Posição"), [t("Marcha"), true], [t("Amplitude"), true], [t("Erro de batida"), true], [t("Batidas por hora"), true], [t("Ângulo de levantamento"), true], t("Origem"), t("Observação")],
            array_map(function ($m) use ($data_hora) {
                return [$data_hora($m["quando"]), $m["relogio"], $m["posicao_texto"], $m["marcha_texto"], $m["amplitude_texto"], $m["erro_batida_texto"], $m["bph_texto"], $m["angulo_texto"], $m["origem_texto"], $m["observacao"]];
            }, $lista), t("Nenhuma medição do timegrapher."));
        return ["subtitulo" => $r ? $r["nome"] : t("Todos os relógios"), "resumo" => $r ? [[t("Resumo"), $d["timegrapher"]["resumo"]]] : [[t("Medições"), (string)count($lista)]], "secoes" => $sec];
    }];

// a reserva medida: os testes e as observações
$RELATORIOS["reserva"] = [$g_manut, t("Reserva medida"), t("Os testes da reserva de marcha (cheio, por qual protocolo, até parar) e as observações da energia, de cada relógio."),
    [$p_relogio(false)],
    function ($p) use ($ler, $relogio, $tabela, $data_hora) {
        $r = $relogio($p, false);
        // os registros de todos (a resposta de um relógio traz a reserva dele, não a lista): os do relógio pedido
        $d = $ler("reserva");
        $lista = array_values(array_filter($d["registros"], function ($x) use ($r) { return $r === null || (int)$x["relogio_id"] === (int)$r["id"]; }));
        return ["subtitulo" => $r ? $r["nome"] : t("Todos os relógios"), "resumo" => [[t("Registros"), (string)count($lista)]],
            "secoes" => [$tabela(t("Registros"), [t("Relógio"), t("O quê"), t("Começo"), t("Fim"), [t("Horas"), true], t("Como foi carregado"), t("Observação")], array_map(function ($x) use ($data_hora) {
                return [$x["relogio"], $x["tipo_texto"], $data_hora($x["inicio"]), $x["em_andamento"] ? t("em andamento") : $data_hora($x["fim"]),
                    $x["horas_texto"], $x["protocolo_texto"], $x["observacao"]];
            }, $lista), t("Nenhum registro da reserva."))]];
    }];

// os calibres da árvore, com os relógios de cada um
$RELATORIOS["calibres"] = [$g_manut, t("Calibres"), t("Os calibres (os movimentos) da árvore: o fabricante, os relógios com cada um, quantos documentos e a observação."),
    [],
    function ($p) use ($ler, $tabela) {
        $d = $ler("calibres");
        return ["subtitulo" => null, "resumo" => [[t("Calibres"), (string)count($d["calibres"])]],
            "secoes" => [$tabela(t("Calibres"), [t("Calibre"), t("Fabricante"), t("Relógios"), [t("Documentos"), true], t("Observação"), t("Link")], array_map(function ($c) {
                return [$c["nome"], $c["fabricante"], implode(", ", array_map(function ($x) { return is_array($x) ? ($x["nome"] ?? "") : (string)$x; }, $c["relogios"])), (string)$c["documentos"], $c["observacao"], $c["link"]];
            }, $d["calibres"]), t("Nenhum calibre."))]];
    }];

// os totais da página de totais
$RELATORIOS["totais"] = [$g_sistema, t("Totais"), t("Os totais da página de totais (as expressões do motor sobre os relógios da coleção), cada um com o resultado; os que são uma tabela saem em tabela."),
    [],
    function ($p) use ($ler, $tabela, $pares) {
        $d = $ler("totais", ["parte" => "pagina"]);
        $simples = [];
        $sec = [];
        foreach ($d["totais"] as $x) {
            $res = $x["resultado"];
            if (($res["tipo"] ?? "") === "tabela") {
                $sec[] = $tabela($x["nome"], [t("Grupo"), [t("Valor"), true]], array_map(function ($l) { return [$l["rotulo"], $l["texto"]]; }, $res["linhas"]), t("Nada."), [t("Total"), $res["total_texto"] ?? null]);
            } else {
                $simples[] = [$x["nome"], $res["texto"] ?? ($res["erro"] ?? null)];
            }
        }
        array_unshift($sec, $pares(t("Os totais"), $simples, t("Nenhum total.")));
        return ["subtitulo" => t("{1} relógios", [1 => $d["relogios"]]), "resumo" => [], "secoes" => $sec];
    }];

// as execuções do cron
$RELATORIOS["cron"] = [$g_sistema, t("Execuções do cron"), t("As últimas execuções do cron (as que fizeram alguma coisa, ou todas, ou só as com erro): quando, quanto tempo levou e o que fez."),
    [$p_opcoes("situacao", t("Quais"), ["atividade" => t("as que fizeram alguma coisa"), "todas" => t("todas"), "erros" => t("só as com erro")], "atividade")],
    function ($p) use ($ler, $tabela, $data_hora) {
        $sit = in_array($p["situacao"] ?? "", ["atividade", "todas", "erros"], true) ? $p["situacao"] : "atividade";
        $d = $ler("cron", ["situacao" => $sit, "limite" => 500]);
        return ["subtitulo" => ["atividade" => t("As que fizeram alguma coisa"), "todas" => t("Todas"), "erros" => t("Só as com erro")][$sit],
            "resumo" => [[t("Execuções"), (string)$d["resumo"]["total"]], [t("Com erro"), (string)$d["resumo"]["erros"]], [t("A última"), $data_hora($d["ultima"])]],
            "secoes" => [$tabela(t("Execuções"), [t("Começo"), [t("Duração (ms)"), true], t("Erro"), [t("O que fez"), false, true]], array_map(function ($x) use ($data_hora) {
                return [$data_hora($x["inicio"]), (string)$x["duracao_ms"], $x["teve_erro"] ? t("sim") : t("não"), $x["registro"]];
            }, $d["execucoes"]), t("Nenhuma execução."), null, $d["total"] > count($d["execucoes"]) ? t("As {1} mais recentes de {2}.", [1 => count($d["execucoes"]), 2 => $d["total"]]) : null)]];
    }];

// ---------- a resposta ----------
$tipo_r = trim((string)($_REQUEST["tipo"] ?? ""));
if ($tipo_r === "") {
    // o catálogo
    $saida = ["relatorios" => array_map(function ($k, $x) {
        return ["tipo" => $k, "grupo" => $x[0], "nome" => $x[1], "descricao" => $x[2], "parametros" => $x[3]];
    }, array_keys($RELATORIOS), array_values($RELATORIOS)),
        "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "posse" => (string)$r["posse"], "posse_comportamento" => posse_de($r)]; }, linhas("SELECT * FROM relogio ORDER BY nome"))];
    return;
}
if (!isset($RELATORIOS[$tipo_r])) {
    $codigo = 404;
    $saida = ["erro" => t("relatório desconhecido: {1}", [1 => $tipo_r]), "tipos" => array_keys($RELATORIOS)];
    return;
}
$def_r = $RELATORIOS[$tipo_r];
// os parâmetros do relatório que vieram (os outros do pedido não entram nem no link do CSV)
$usados_r = [];
foreach ($def_r[3] as $par) {
    $v = trim((string)($_REQUEST[$par["nome"]] ?? ""));
    if ($v !== "") {
        $usados_r[$par["nome"]] = $v;
    } elseif ($par["padrao"] !== null) {
        $usados_r[$par["nome"]] = (string)$par["padrao"];
    }
}
try {
    $m_r = ($def_r[4])($usados_r);
} catch (RelatorioErro $e) {
    $codigo = $e->getCode() > 0 ? $e->getCode() : 400;
    $saida = ["erro" => $e->getMessage()];
    return;
}
$agora_r = time();
$saida = ["tipo" => $tipo_r, "grupo" => $def_r[0], "titulo" => $def_r[1], "subtitulo" => $m_r["subtitulo"] ?? null, "parametros" => (object)$usados_r,
    "foto" => $m_r["foto"] ?? null,
    "resumo" => array_values(array_map(function ($x) { return ["nome" => (string)$x[0], "valor" => (string)$x[1]]; }, array_filter($m_r["resumo"], function ($x) { return $x[1] !== null && $x[1] !== ""; }))),
    "secoes" => $m_r["secoes"], "gerado_em" => date("Y-m-d H:i:s", $agora_r),
    "rodape" => t("Gerado em {1} pelo Relojoeiro.", [1 => data_txt($agora_r, true)]),
    "csv" => "api.php?" . http_build_query(array_merge(["recurso" => "relatorios", "tipo" => $tipo_r], $usados_r, ["csv" => 1]))];
if (($_REQUEST["csv"] ?? "") === "1") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . relatorio_arquivo($saida) . "\"");
    echo relatorio_csv($saida);
    exit;
}
