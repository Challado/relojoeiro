<?php
// as funções que só a API usa (o XML, os textos legíveis do tempo, do plano, dos eventos e da agenda, os filtros das listas e a resposta) e o tratamento dos erros.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// XML: cada chave vira um elemento; listas repetem o nome no singular ("item" quando não há um)
function xml_de($v, $nivel)
{
    $res = "";
    $recuo = str_repeat("  ", $nivel);
    foreach ((array)$v as $k => $x) {
        $tag = is_int($k) ? "item" : preg_replace("/[^A-Za-z0-9_]/", "_", (string)$k);
        if (preg_match("/^[A-Za-z_]/", $tag) !== 1) {
            $tag = "c_" . $tag;
        }
        if (is_array($x) || is_object($x)) {
            $res .= $recuo . "<" . $tag . ">\n" . xml_de($x, $nivel + 1) . $recuo . "</" . $tag . ">\n";
        } elseif ($x === null) {
            $res .= $recuo . "<" . $tag . "/>\n";
        } else {
            $res .= $recuo . "<" . $tag . ">" . htmlspecialchars(is_bool($x) ? ($x ? "true" : "false") : (string)$x, ENT_XML1) . "</" . $tag . ">\n";
        }
    }
    return $res;
}

// O resumo do tempo para a resposta: cada estado com segundos, texto ("13d 3h") e porcentagem, e as marcações por tipo
function resumo_do_tempo($f)
{
    $soma = array_sum($f["tempo"]);
    $tempo = [];
    foreach ($f["tempo"] as $k => $seg) {
        $tempo[$k] = ["segundos" => $seg, "texto" => duracao_texto($seg / 86400) !== "" ? duracao_texto($seg / 86400) : "0min",
            "porcentagem" => $soma > 0 ? round($seg * 100 / $soma, 1) : 0];
    }
    return ["tempo" => $tempo, "marcacoes" => (object)$f["marcas"]];
}

// Uma linha da linha do tempo para a resposta
function linha_legivel($l)
{
    return ["inicio" => date("Y-m-d H:i:s", $l["inicio"]), "fim" => $l["fim"] === null ? null : date("Y-m-d H:i:s", $l["fim"]), "em_andamento" => $l["em_andamento"],
        "duracao_seg" => $l["fim"] === null ? null : $l["fim"] - $l["inicio"],
        "duracao" => $l["fim"] === null ? null : (duracao_texto(($l["fim"] - $l["inicio"]) / 86400) !== "" ? duracao_texto(($l["fim"] - $l["inicio"]) / 86400) : "0min"),
        "estado" => $l["estado"], "tipo" => $l["tipo"], "texto" => $l["texto"]];
}

// As medições do gasto de um relógio para a resposta (todas, da mais recente para a mais antiga), dizendo se cada uma entra
// na média agora (usada e dentro da janela da Configuração)
function medicoes_legivel($id)
{
    $limite = time() - max(1, (int)cfg("medicao_janela_dias")) * 86400;
    return array_map(function ($m) use ($limite) {
        return ["id" => (int)$m["id"], "lancamento_id" => (int)$m["lancamento_id"], "medida" => $m["medida"], "taxa" => (float)$m["taxa"], "de_valor" => (float)$m["de_valor"],
            "ate_valor" => (float)$m["ate_valor"], "inicio" => $m["inicio"], "fim" => $m["fim"], "horas_pulso" => (float)$m["horas_pulso"], "horas_guardado" => (float)$m["horas_guardado"],
            "peso_horas" => (float)$m["peso_horas"], "usada" => (int)$m["usada"] === 1, "na_media" => (int)$m["usada"] === 1 && strtotime($m["fim"]) > $limite, "criado" => $m["criado"]];
    }, linhas("SELECT m.* FROM medicao m JOIN lancamento l ON l.id = m.lancamento_id WHERE l.relogio_id = ? ORDER BY m.fim DESC, m.id DESC", [(int)$id]));
}

// Um evento personalizado para a resposta, com as próximas ocorrências e os disparos
function evento_legivel($ev)
{
    return ["id" => (int)$ev["id"], "nome" => $ev["nome"], "ativo" => (int)$ev["ativo"] === 1, "repeticao" => $ev["repeticao"],
        "descricao" => descricao_repeticao($ev), "hora" => substr($ev["hora"], 0, 5), "data_inicio" => $ev["data_inicio"], "dias_semana" => $ev["dias_semana"],
        "dia_mes" => $ev["dia_mes"] === null ? null : (int)$ev["dia_mes"], "intervalo_dias" => $ev["intervalo_dias"] === null ? null : (int)$ev["intervalo_dias"],
        "relogio_id" => $ev["relogio_id"] === null ? null : (int)$ev["relogio_id"],
        "relogio" => $ev["relogio_id"] === null ? null : valor("SELECT nome FROM relogio WHERE id = ?", [(int)$ev["relogio_id"]]),
        "telegram" => canal_aviso("tg", "ev" . $ev["id"])["envia"], "agenda" => canal_aviso("ag", "ev" . $ev["id"])["envia"],
        "criado" => $ev["criado"],
        "proximas" => (int)$ev["ativo"] === 1 ? array_map(function ($t) { return date("Y-m-d H:i:s", $t); }, array_slice(ocorrencias($ev, time() + 1, time() + 366 * 86400), 0, 10)) : [],
        "disparos" => linhas("SELECT ocorrencia, disparado FROM evento_disparo WHERE evento_id = ? ORDER BY ocorrencia DESC", [(int)$ev["id"]])];
}

// O plano gravado para a resposta, entre duas datas (vazias: sem limite)
function plano_legivel($de, $ate)
{
    return array_map(function ($p) {
        return ["data" => $p["data"], "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["relogio"], "bloco_id" => $p["bloco_id"] === null ? null : (int)$p["bloco_id"],
            "bloco" => $p["bloco"], "origem" => $p["origem"], "acao" => $p["acao"], "motivo" => $p["motivo"], "criado" => $p["criado"]];
    }, linhas("SELECT p.*, r.nome AS relogio, b.nome AS bloco FROM plano p JOIN relogio r ON r.id = p.relogio_id LEFT JOIN modo_bloco b ON b.id = p.bloco_id
        WHERE p.data >= ? AND p.data <= ? ORDER BY p.data", [$de !== "" ? $de : "0001-01-01", $ate !== "" ? $ate : "9999-12-31"]));
}

// O resumo de um período do plano (os dias de hoje em diante da lista): quantos dias cada relógio tem, a porcentagem, o
// próximo dia dele e quantos foram escolhidos à mão; e os relógios disponíveis sem nenhum dia (o resumo da página Plano)
function plano_resumo($plano, $hoje)
{
    $dias = array_values(array_filter($plano, function ($p) use ($hoje) { return $p["data"] >= $hoje; }));
    $conta = [];
    foreach ($dias as $p) {
        $k = $p["relogio_id"];
        if (!isset($conta[$k])) {
            $conta[$k] = ["relogio_id" => $k, "relogio" => $p["relogio"], "dias" => 0, "porcentagem" => 0.0, "proximo" => $p["data"], "a_mao" => 0];
        }
        $conta[$k]["dias"]++;
        $conta[$k]["a_mao"] += $p["origem"] === "manual" ? 1 : 0;
    }
    $lista = array_values($conta);
    foreach ($lista as $i => $c) {
        $lista[$i]["porcentagem"] = round($c["dias"] * 100 / count($dias), 1);
    }
    usort($lista, function ($a, $b) { return $b["dias"] <=> $a["dias"] ?: strcmp(texto_normal($a["relogio"]), texto_normal($b["relogio"])); });
    $sem = array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"]]; }, array_values(array_filter(linhas("SELECT id, nome FROM relogio WHERE disponivel = 1 ORDER BY nome"),
        function ($r) use ($conta) { return !isset($conta[(int)$r["id"]]); })));
    return ["de" => count($dias) > 0 ? $dias[0]["data"] : null, "ate" => count($dias) > 0 ? $dias[count($dias) - 1]["data"] : null, "dias" => count($dias),
        "relogios" => $lista, "sem_dias" => $sem];
}

// A situação do cron, como a Configuração mostra: nunca rodou, parado (mais de 10 minutos sem rodar; ele roda a cada
// minuto) ou rodando; com a última execução, há quantos minutos, a frase e a linha do crontab
function cron_estado()
{
    $ultima = cfg("cron_ultima_execucao");
    $minutos = $ultima !== "" ? max(0, (int)floor((time() - strtotime($ultima)) / 60)) : null;
    $situacao = $ultima === "" ? "nunca" : ($minutos > 10 ? "parado" : "rodando");
    $linha = "* * * * * php " . dirname(__DIR__) . "/cron.php";
    $texto = $situacao === "nunca" ? "O cron ainda não rodou. Linha do crontab: " . $linha
        : ($situacao === "parado" ? "O cron não roda desde " . date("d/m H:i", strtotime($ultima)) . ". Confira o crontab: " . $linha
            : "Cron rodando: última execução às " . date("H:i", strtotime($ultima)) . ".");
    return ["situacao" => $situacao, "ultima" => $ultima !== "" ? $ultima : null, "minutos" => $minutos, "texto" => $texto, "crontab" => $linha,
        "erro" => cfg("cron_erro") !== "" ? cfg("cron_erro") : null];
}

// O que tem de estar na agenda, com o título e a descrição pelo modelo
function agenda_legivel()
{
    return array_map(function ($m) {
        return array_merge($m, evento_formatado($m));
    }, agenda_desejada(new DateTimeImmutable("today")));
}
// ---------------------------------------------------------------------------------------------------------------------
// Filtros de qualquer consulta (GET): valem para toda lista da resposta, pelo caminho dela ("relogios", "plano",
// "relogios.lancamentos", "criterios.notas"...), e para todo campo de cada item (com ponto para entrar num objeto:
// "compra.valor"; numa lista de itens com identificador, o identificador escolhe o item: "formulas.energia.valor").
// ---------------------------------------------------------------------------------------------------------------------

// Um valor em texto para comparar: minúsculas e sem acento (lista ou objeto: o JSON dele; verdadeiro e falso: 1 e 0)
function texto_normal($v)
{
    $t = is_array($v) || is_object($v) ? (string)json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? "1" : "0") : (string)$v);
    $t = function_exists("mb_strtolower") ? mb_strtolower($t, "UTF-8") : strtolower($t);
    return strtr($t, ["á" => "a", "à" => "a", "â" => "a", "ã" => "a", "ä" => "a", "é" => "e", "è" => "e", "ê" => "e", "ë" => "e", "í" => "i", "ì" => "i", "î" => "i",
        "ï" => "i", "ó" => "o", "ò" => "o", "ô" => "o", "õ" => "o", "ö" => "o", "ú" => "u", "ù" => "u", "û" => "u", "ü" => "u", "ç" => "c", "ñ" => "n"]);
}

// O valor de um campo de um item, pelo caminho com pontos ("compra.valor", "formulas.energia.valor"); não existe: null
function valor_do_campo($item, $caminho)
{
    $v = $item;
    foreach (explode(".", $caminho) as $parte) {
        $achou = null;
        if (is_array($v) && array_key_exists($parte, $v)) {
            $achou = $v[$parte];
        } elseif (is_array($v) && array_is_list($v)) {
            // numa lista de itens com identificador, o identificador escolhe o item
            foreach ($v as $x) {
                if (is_array($x) && (string)($x["identificador"] ?? "") === $parte) {
                    $achou = $x;
                }
            }
        }
        $v = $achou;
    }
    return $v;
}

// Aplica as regras de uma lista (filtros, busca, ordem, página, campos mostrados) em toda lista que estiver no caminho;
// $conta soma, de todas elas, quantos itens havia, quantos passaram nos filtros e quantos foram devolvidos
function filtrar_lista(&$no, $partes, $regras, &$conta)
{
    if (count($partes) === 0) {
        if (is_array($no) && array_is_list($no)) {
            $conta["listas"]++;
            $conta["antes"] += count($no);
            $lista = array_values(array_filter($no, function ($item) use ($regras) {
                $passa = true;
                foreach ($regras["f"] as $campo => $cond) {
                    $v = valor_do_campo($item, (string)$campo);
                    $vt = texto_normal($v);
                    // sem operador: igual a um dos valores (separados por vírgula); com operador: cada um tem de valer
                    $ops = is_array($cond) ? $cond : ["igual" => $cond];
                    foreach ($ops as $op => $alvo) {
                        $alvo = (string)$alvo;
                        if ($op === "igual" || $op === "diferente") {
                            $algum = false;
                            foreach (explode(",", $alvo) as $a) {
                                $a = trim($a);
                                $igual = is_numeric($a) && is_numeric($v) ? (float)$a == (float)$v : texto_normal($a) === $vt;
                                // numa lista (de textos ou de números), vale se algum item for igual
                                if (is_array($v) && array_is_list($v)) {
                                    $igual = count(array_filter($v, function ($x) use ($a) { return texto_normal($x) === texto_normal($a); })) > 0;
                                }
                                $algum = $algum || $igual;
                            }
                            $passa = $passa && ($op === "igual" ? $algum : !$algum);
                        } elseif ($op === "contem") {
                            $passa = $passa && $alvo !== "" && strpos($vt, texto_normal($alvo)) !== false;
                        } elseif ($op === "vazio") {
                            $e_vazio = $v === null || $v === "" || (is_array($v) && count($v) === 0);
                            $passa = $passa && ($alvo === "1" ? $e_vazio : !$e_vazio);
                        } elseif ($op === "de" || $op === "ate") {
                            // números como números; datas e textos como texto (uma data só no "até" vai até o fim daquele dia)
                            $cmp = 0;
                            $vs = is_bool($v) ? ($v ? "1" : "0") : (is_array($v) ? "" : (string)$v);
                            if (is_numeric($v) && is_numeric($alvo)) {
                                $cmp = (float)$v <=> (float)$alvo;
                            } else {
                                $cmp = strcmp($op === "ate" && strlen($alvo) === 10 && strlen($vs) > 10 ? substr($vs, 0, 10) : $vs, $alvo);
                            }
                            $passa = $passa && $v !== null && $vs !== "" && ($op === "de" ? $cmp >= 0 : $cmp <= 0);
                        } else {
                            $passa = false;
                        }
                    }
                }
                if ($regras["busca"] !== "") {
                    $passa = $passa && strpos(texto_normal($item), texto_normal($regras["busca"])) !== false;
                }
                return $passa;
            }));
            $conta["depois"] += count($lista);
            if (count($regras["ordem"]) > 0) {
                usort($lista, function ($a, $b) use ($regras) {
                    $res = 0;
                    foreach ($regras["ordem"] as $campo) {
                        $desc = substr($campo, 0, 1) === "-";
                        $campo = ltrim($campo, "-+");
                        $va = valor_do_campo($a, $campo);
                        $vb = valor_do_campo($b, $campo);
                        $c = is_numeric($va) && is_numeric($vb) ? (float)$va <=> (float)$vb : strcmp(texto_normal($va), texto_normal($vb));
                        if ($res === 0) {
                            $res = $desc ? -$c : $c;
                        }
                    }
                    return $res;
                });
            }
            if ($regras["limite"] > 0) {
                $lista = array_slice($lista, ($regras["pagina"] - 1) * $regras["limite"], $regras["limite"]);
            }
            if (count($regras["mostrar"]) > 0) {
                $lista = array_map(function ($item) use ($regras) {
                    $res = $item;
                    if (is_array($item) && !array_is_list($item)) {
                        $res = [];
                        foreach ($regras["mostrar"] as $campo) {
                            $res[$campo] = valor_do_campo($item, $campo);
                        }
                    }
                    return $res;
                }, $lista);
            }
            $conta["devolvidos"] += count($lista);
            $no = $lista;
        }
    } elseif (is_array($no)) {
        if (array_is_list($no)) {
            // uma lista no meio do caminho: as regras valem para a lista de dentro de cada item
            foreach ($no as $i => $item) {
                filtrar_lista($no[$i], $partes, $regras, $conta);
            }
        } elseif (array_key_exists($partes[0], $no)) {
            filtrar_lista($no[$partes[0]], array_slice($partes, 1), $regras, $conta);
        }
    }
}

// Os critérios: os conjuntos por lugar (parâmetros, subparâmetros e faixas, com quem usa cada um), as variáveis que podem
// medir, a nota de cada relógio com a conta, os lugares (quem tem critérios próprios e de quem herda) e os modos. Usado pelo
// recurso=criterios e pela resposta sem parâmetros.
function montar_criterios()
{
    // os conjuntos de critérios, um por lugar, com quem usa cada um, e a nota de cada relógio com a conta
    $conjuntos = [];
    $notas = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
        $n = nota_do_relogio($r, time());
        $notas[] = array_merge(["relogio_id" => (int)$r["id"], "relogio" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1], $n);
        if ((int)$r["disponivel"] === 1) {
            $conjuntos[(string)$n["conjunto"]][] = (int)$r["id"];
        }
    }
    $lista = [];
    foreach (criterios_config() as $chave => $ps) {
        $lista[] = ["escopo" => (string)$chave, "lugar" => escopo_texto((string)$chave), "usado_por" => $conjuntos[(string)$chave] ?? [], "parametros" => array_map(function ($p) {
            return ["id" => (int)$p["id"], "ordem" => (int)$p["ordem"], "escopo_no_id" => $p["escopo_no_id"] === null ? null : (int)$p["escopo_no_id"],
                "escopo_relogio_id" => $p["escopo_relogio_id"] === null ? null : (int)$p["escopo_relogio_id"], "nome" => $p["nome"], "peso" => (float)$p["peso"],
                "subparametros" => array_map(function ($sb) use ($p) {
                    return ["id" => (int)$sb["id"], "ordem" => (int)$sb["ordem"], "nome" => $sb["nome"], "variavel" => $sb["variavel"], "peso" => (float)$sb["peso"],
                        "peso_efetivo_no_conjunto" => round((float)$sb["peso"] * (float)$p["peso"] / 100, 2),
                        "faixas" => array_map(function ($f) {
                            return ["id" => (int)$f["id"], "de" => $f["de"] === null ? null : (float)$f["de"], "ate" => $f["ate"] === null ? null : (float)$f["ate"],
                                "categoria" => $f["categoria"], "nota" => (float)$f["nota"]];
                        }, $sb["faixas"])];
                }, $p["subs"])];
        }, $ps)];
    }
    // os lugares que podem ter critérios (todos, cada grupo em ordem de árvore, cada relógio), de quem cada um herda, e os modos
    $lugares = [["escopo" => "", "texto" => "Todos os relógios"]];
    foreach (nos_em_ordem() as $o) {
        $lugares[] = ["escopo" => "g:" . $o[0], "texto" => "Grupo: " . no_caminho($o[0])];
    }
    foreach (linhas("SELECT id, nome FROM relogio ORDER BY nome") as $x) {
        $lugares[] = ["escopo" => "r:" . $x["id"], "texto" => "Relógio: " . $x["nome"]];
    }
    foreach ($lugares as $i => $l) {
        $lugares[$i]["lugar"] = escopo_texto($l["escopo"]);
        $lugares[$i]["proprio"] = isset(criterios_config()[$l["escopo"]]);
        $lugares[$i]["herda"] = conjunto_herdado($l["escopo"]);
        $lugares[$i]["herda_texto"] = $lugares[$i]["herda"] !== null ? escopo_texto($lugares[$i]["herda"]) : null;
    }
    foreach ($notas as $i => $x) {
        $notas[$i]["grupo"] = no_caminho(valor("SELECT no_id FROM relogio WHERE id = ?", [$x["relogio_id"]]));
    }
    return ["conjuntos" => $lista, "variaveis" => variaveis_disponiveis(), "notas" => $notas, "max_sem_uso" => (int)cfg("max_sem_uso"), "lugares" => $lugares,
        "modos" => array_map(function ($m) { return ["nome" => $m["nome"], "selecao" => $m["selecao"], "escala" => $m["escala_dias"] !== null, "ativo" => (int)$m["id"] === modo_ativo()]; },
            linhas("SELECT * FROM modo ORDER BY ordem, id"))];
}


function responde($codigo, $saida, $formato)
{
    http_response_code($codigo);
    if ($formato === "xml") {
        header("Content-Type: application/xml; charset=utf-8");
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<resposta>\n" . xml_de($saida, 1) . "</resposta>\n";
    } else {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

// erro que escapar: sai no formato pedido
set_exception_handler(function ($t) use ($formato) {
    if ($t instanceof BancoErro && $t->integridade) {
        // o banco recusou a gravação: um registro que outro ainda usa, ou um valor repetido onde não pode
        responde(409, ["erro" => "o banco recusou a gravação: ela quebraria uma regra dos dados (um registro que outro ainda usa, "
            . "um valor repetido onde não pode, ou um valor fora das regras de validação do banco)", "detalhe" => $t->getMessage()], $formato);
        return;
    }
    $banco = $t instanceof BancoErro || $t instanceof mysqli_sql_exception;
    responde($banco ? 503 : 500, ["erro" => $banco ? "o banco de dados não respondeu" : "erro interno", "detalhe" => $t->getMessage()], $formato);
});
