<?php
// Operações: o back-end de escrita. Cada função recebe a ação e os campos, valida, grava e devolve o resultado:
// ok, mensagem, erros (e id quando cria). A API chama estas funções; as páginas vão chamar a API.
// Incluído pelo lib.php; não é uma página (o nginx bloqueia, e a trava abaixo também).
if (realpath($_SERVER["SCRIPT_FILENAME"] ?? "") === __FILE__) {
    http_response_code(403);
    exit;
}

// Número digitado com vírgula ou ponto, em duas casas; null se não é número
// Porcentagem para mostrar: "12,5%"
function pct_br($v)
{
    return str_replace('.', ',', (string)(0 + round((float)$v, 2))) . '%';
}
function numero_br($v)
{
    $v = str_replace(',', '.', trim((string)$v));
    return is_numeric($v) ? round((float)$v, 2) : null;
}

// O resultado padrão de uma operação
function resultado($erros, $mensagem, $extra = [])
{
    if (count($erros) === 0 && $mensagem === "") {
        $erros[] = "Ação desconhecida ou item não encontrado.";
    }
    return array_merge(["ok" => count($erros) === 0, "mensagem" => count($erros) === 0 ? $mensagem : "", "erros" => $erros], $extra);
}

// Identificador de campo, fórmula ou tipo de lançamento: minúsculas, números e _, começando por letra, até 40
function identificador_valido($v)
{
    return preg_match("/^[a-z][a-z0-9_]{0,39}\$/", (string)$v) === 1;
}

// Um ponto da árvore vindo de um pedido: 0 ou vazio = na raiz (null); false se não existe
function no_do_pedido($v)
{
    $res = false;
    if ($v === null || $v === "" || (string)$v === "0") {
        $res = null;
    } elseif (ctype_digit((string)$v) && isset(nos_todos()[(int)$v])) {
        $res = (int)$v;
    }
    return $res;
}

// As contas que usam uma variável ou um tipo de lançamento: as fórmulas, a data e o "vale quando" dos avisos e o "vale quando"
// dos tipos de lançamento (quem usa impede de apagar ou renomear o que é usado)
function formulas_que_usam($nome, $tipo)
{
    // as contas que podem usar: as fórmulas, a data e o "vale quando" de cada aviso, e o "vale quando" de cada tipo de lançamento
    $contas = [];
    foreach (formulas_todas() as $ident => $versoes) {
        foreach ($versoes as $f) {
            $contas[] = [$ident . " (" . no_caminho($f["no_id"]) . ")", $f["expressao"]];
        }
    }
    foreach (avisos_todos() as $ident => $versoes) {
        foreach ($versoes as $a) {
            $contas[] = ["a data do aviso " . $ident . " (" . no_caminho($a["no_id"]) . ")", $a["expressao"]];
            $contas[] = ["o vale quando do aviso " . $ident . " (" . no_caminho($a["no_id"]) . ")", $a["condicao"] ?? ""];
        }
    }
    foreach (lancamento_tipos() as $ident => $t) {
        $contas[] = ["o vale quando do tipo " . $ident, $t["condicao"] ?? ""];
    }
    $res = [];
    foreach ($contas as $c) {
        if (trim((string)$c[1]) !== "") {
            try {
                $refs = formula_referencias(formula_ler($c[1]));
                if (in_array($nome, $refs[$tipo], true)) {
                    $res[] = $c[0];
                }
            } catch (ErroFormula $e) {
                $res[] = $c[0] . ", com erro de escrita";
            }
        }
    }
    return array_values(array_unique($res));
}

// ---------------------------------------------------------------------------------------------------------------------
// A árvore. Ações: novo (nome, pai_id), renomear (id, nome), mover (id, pai_id), ordem (id, direcao: sobe ou desce),
// excluir (id: o que é dele sobe para o ponto de cima — os pontos de dentro, os relógios, os campos e os tipos de
// lançamento; as fórmulas também, menos as que o ponto de cima já tem com o mesmo identificador, que saem), relogios
// (grupo[<relógio>] = o ponto de cada relógio, 0 na raiz: a tabela "O grupo de cada relógio" da página Grupos).
// ---------------------------------------------------------------------------------------------------------------------
function op_arvore($acao, $d)
{
    $erros = [];
    $msg = "";
    $n = nos_todos();
    $id = (int)($d["id"] ?? 0);
    $nome = trim((string)($d["nome"] ?? ""));
    $pai = no_do_pedido($d["pai_id"] ?? "");
    if ($acao === "relogios") {
        // o grupo de cada relógio: confere tudo antes, e grava só se não houver erro
        $novos = [];
        foreach ((array)($d["grupo"] ?? []) as $rid => $gid) {
            $no = no_do_pedido($gid);
            if (valor("SELECT id FROM relogio WHERE id = ?", [(int)$rid]) === null) {
                $erros[] = "Relógio " . (int)$rid . " não encontrado.";
            } elseif ($no === false) {
                $erros[] = "Grupo " . $gid . " não encontrado.";
            } else {
                $novos[(int)$rid] = $no;
            }
        }
        if (count($erros) === 0) {
            $mudou = 0;
            foreach ($novos as $rid => $no) {
                if ((int)valor("SELECT COALESCE(no_id, 0) FROM relogio WHERE id = ?", [$rid]) !== (int)$no) {
                    sql("UPDATE relogio SET no_id = ? WHERE id = ?", [$no, $rid]);
                    $mudou++;
                }
            }
            $msg = $mudou === 0 ? "Nada mudou." : "Grupo alterado em " . $mudou . ($mudou === 1 ? " relógio." : " relógios.");
        }
    } elseif ($acao === "novo" || $acao === "renomear") {
        $pai_do_nome = $acao === "novo" ? $pai : ($n[$id]["pai_id"] ?? null);
        if ($acao === "renomear" && !isset($n[$id])) {
            $erros[] = "Ponto da árvore não encontrado.";
        }
        if ($acao === "novo" && $pai === false) {
            $erros[] = "O ponto de cima não existe.";
        }
        if ($nome === "" || strlen($nome) > 80) {
            $erros[] = "Dê um nome (até 80 caracteres).";
        }
        foreach ($n as $nid => $x) {
            if ((string)$x["pai_id"] === (string)$pai_do_nome && $nid !== $id && mesmo_nome($x["nome"], $nome)) {
                $erros[] = "Já existe " . $x["nome"] . " neste mesmo lugar da árvore.";
            }
        }
        if (count($erros) === 0 && $acao === "novo") {
            sql("INSERT INTO no (pai_id, nome, ordem) VALUES (?, ?, ?)", [$pai, $nome, (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM no WHERE pai_id <=> ?", [$pai])]);
            $id = ultimo_id();
            nos_todos(true);
            $msg = no_caminho($id) . " criado.";
        } elseif (count($erros) === 0) {
            sql("UPDATE no SET nome = ? WHERE id = ?", [$nome, $id]);
            nos_todos(true);
            $msg = "Renomeado: " . no_caminho($id) . ".";
        }
    } elseif ($acao === "mover") {
        if (!isset($n[$id]) || $pai === false) {
            $erros[] = "Ponto da árvore não encontrado.";
        } elseif ($pai !== null && in_array($id, no_cadeia($pai), true)) {
            $erros[] = "Um ponto não pode ir para dentro dele mesmo, nem de um ponto de dentro dele.";
        } else {
            foreach ($n as $nid => $x) {
                if ((string)$x["pai_id"] === (string)$pai && $nid !== $id && mesmo_nome($x["nome"], $n[$id]["nome"])) {
                    $erros[] = "Já existe " . $x["nome"] . " no destino.";
                }
            }
        }
        if (count($erros) === 0) {
            sql("UPDATE no SET pai_id = ?, ordem = ? WHERE id = ?", [$pai, (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM no WHERE pai_id <=> ?", [$pai]), $id]);
            nos_todos(true);
            $msg = "Movido: agora é " . no_caminho($id) . ".";
        }
    } elseif ($acao === "ordem" && isset($n[$id])) {
        $irmaos = array_map("intval", array_column(linhas("SELECT id FROM no WHERE pai_id <=> ? ORDER BY ordem, nome", [$n[$id]["pai_id"]]), "id"));
        $pos = array_search($id, $irmaos, true);
        $viz = ($d["direcao"] ?? "") === "sobe" ? $pos - 1 : $pos + 1;
        if ($viz >= 0 && $viz < count($irmaos)) {
            $irmaos[$pos] = $irmaos[$viz];
            $irmaos[$viz] = $id;
        }
        foreach ($irmaos as $k => $iid) {
            sql("UPDATE no SET ordem = ? WHERE id = ?", [$k + 1, $iid]);
        }
        $msg = "Ordem alterada.";
    } elseif ($acao === "excluir" && isset($n[$id])) {
        $cima = $n[$id]["pai_id"] !== null ? (int)$n[$id]["pai_id"] : null;
        $destino = no_caminho($cima);
        // fórmulas e avisos (uma versão por ponto): sobem, menos os que o ponto de cima já tem com o mesmo identificador
        $saem = ["formula" => [], "aviso" => []];
        foreach (array_keys($saem) as $tabela) {
            foreach (linhas("SELECT id, identificador FROM " . $tabela . " WHERE no_id = ?", [$id]) as $f) {
                if ((int)valor("SELECT COUNT(*) FROM " . $tabela . " WHERE identificador = ? AND no_id <=> ?", [$f["identificador"], $cima]) > 0) {
                    sql("DELETE FROM " . $tabela . " WHERE id = ?", [$f["id"]]);
                    $saem[$tabela][] = $f["identificador"];
                }
            }
        }
        // os critérios próprios dele saem (o banco apaga junto): os relógios passam a usar os do ponto de cima
        $criterios = (int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE escopo_no_id = ?", [$id]);
        // o resto sobe: os pontos de dentro, os relógios, os campos, os tipos de lançamento, as fórmulas, os avisos, e os
        // blocos dos modos que sorteavam dele (passam a sortear do ponto de cima)
        foreach (["no" => "pai_id", "relogio" => "no_id", "campo" => "no_id", "lancamento_tipo" => "no_id", "formula" => "no_id", "aviso" => "no_id",
            "modo_bloco" => "no_id"] as $tabela => $coluna) {
            sql("UPDATE " . $tabela . " SET " . $coluna . " = ? WHERE " . $coluna . " = ?", [$cima, $id]);
        }
        sql("DELETE FROM no WHERE id = ?", [$id]);
        nos_todos(true);
        $msg = $n[$id]["nome"] . " excluído; o que era dele foi para " . $destino . "."
            . (count($saem["formula"]) > 0 ? " Saíram as versões dele das fórmulas que " . $destino . " já tinha: " . implode(", ", $saem["formula"]) . "." : "")
            . (count($saem["aviso"]) > 0 ? " Saíram as versões dele dos avisos que " . $destino . " já tinha: " . implode(", ", $saem["aviso"]) . "." : "")
            . ($criterios > 0 ? " Os critérios próprios dele saíram: os relógios dele usam os do lugar mais perto, acima." : "");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Campos. Ações: novo e alterar (id; identificador, nome, tipo, unidade, opcoes (lista: uma por linha), padrao,
// no_id), excluir (id), ordem (id, direcao). Não se exclui nem se renomeia um campo que uma fórmula usa; não se muda o
// tipo se algum valor já gravado não servir no tipo novo.
// ---------------------------------------------------------------------------------------------------------------------
function op_campos($acao, $d)
{
    global $TIPOS_CAMPO;
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? (campos_com_opcoes(linhas("SELECT * FROM campo WHERE id = ?", [$id]))[0] ?? null) : null;
    if (($acao === "alterar" || $acao === "excluir" || $acao === "ordem") && !$atual) {
        $erros[] = "Campo não encontrado.";
    } elseif ($acao === "novo" || $acao === "alterar") {
        $c = [
            "identificador" => trim((string)($d["identificador"] ?? ($atual["identificador"] ?? ""))),
            "nome" => trim((string)($d["nome"] ?? ($atual["nome"] ?? ""))),
            "tipo" => (string)($d["tipo"] ?? ($atual["tipo"] ?? "")),
            "unidade" => trim((string)($d["unidade"] ?? ($atual["unidade"] ?? ""))),
            "opcoes" => trim(str_replace("\r", "", (string)($d["opcoes"] ?? ($atual["opcoes"] ?? "")))),
            "padrao" => trim((string)($d["padrao"] ?? ($atual["padrao"] ?? ""))),
        ];
        $no = array_key_exists("no_id", $d) ? no_do_pedido($d["no_id"]) : ($atual ? ($atual["no_id"] === null ? null : (int)$atual["no_id"]) : null);
        if (!identificador_valido($c["identificador"])) {
            $erros[] = "Identificador: minúsculas, números e _, começando por letra, até 40 (é o nome nas fórmulas).";
        } elseif ((int)valor("SELECT COUNT(*) FROM campo WHERE identificador = ? AND id <> ?", [$c["identificador"], $id]) > 0 || isset(formulas_todas()[$c["identificador"]])) {
            $erros[] = "Já existe um campo ou uma fórmula com o identificador " . $c["identificador"] . ".";
        }
        if ($atual && $c["identificador"] !== $atual["identificador"] && count(formulas_que_usam($atual["identificador"], "variaveis")) > 0) {
            $erros[] = "Não dá para renomear " . $atual["identificador"] . ": estas fórmulas usam: " . implode(", ", formulas_que_usam($atual["identificador"], "variaveis")) . ".";
        }
        if ($c["nome"] === "" || strlen($c["nome"]) > 120) {
            $erros[] = "Dê um nome ao campo (até 120 caracteres).";
        }
        if (!isset($TIPOS_CAMPO[$c["tipo"]])) {
            $erros[] = "Tipo: " . implode(", ", array_keys($TIPOS_CAMPO)) . ".";
        }
        if ($c["tipo"] === "lista" && count(array_filter(explode("\n", $c["opcoes"]))) === 0) {
            $erros[] = "Uma lista precisa de pelo menos uma opção (uma por linha).";
        }
        if ($no === false) {
            $erros[] = "O ponto da árvore não existe.";
        }
        if (count($erros) === 0 && $c["padrao"] !== "") {
            $p = valor_para_campo($c, $c["padrao"]);
            if ($p[1] !== "") {
                $erros[] = "Valor padrão: " . $p[1] . ".";
            }
            $c["padrao"] = $p[0] ?? "";
        }
        // tipo novo: os valores já gravados têm de servir nele
        if (count($erros) === 0 && $atual && ($c["tipo"] !== $atual["tipo"] || $c["opcoes"] !== (string)$atual["opcoes"])) {
            $nao_servem = [];
            foreach (linhas("SELECT r.nome, v.valor FROM campo_valor v JOIN relogio r ON r.id = v.relogio_id WHERE v.campo_id = ?", [$id]) as $v) {
                if (valor_para_campo($c, $v["valor"])[1] !== "") {
                    $nao_servem[] = $v["nome"] . " (" . $v["valor"] . ")";
                }
            }
            if (count($nao_servem) > 0) {
                $erros[] = "Estes valores não servem no tipo ou nas opções novas: " . implode(", ", $nao_servem) . ". Acerte-os antes.";
            }
        }
        if (count($erros) === 0) {
            $dados = [$c["identificador"], $c["nome"], $c["tipo"], $c["unidade"], $c["padrao"] !== "" ? $c["padrao"] : null, $no];
            if ($acao === "novo") {
                sql("INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    array_merge($dados, [(int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM campo")]));
                $id = ultimo_id();
                $msg = "Campo " . $c["nome"] . " criado, para " . no_caminho($no) . ". Nas fórmulas: " . $c["identificador"] . ".";
            } else {
                sql("UPDATE campo SET identificador = ?, nome = ?, tipo = ?, unidade = ?, padrao = ?, no_id = ? WHERE id = ?", array_merge($dados, [$id]));
                $msg = "Campo " . $c["nome"] . " salvo.";
            }
            // as opções da lista (só no tipo lista; nos outros, nenhuma)
            campo_gravar_opcoes($id, $c["tipo"] === "lista" ? $c["opcoes"] : "");
            campos_todos(true);
        }
    } elseif ($acao === "excluir") {
        $usam = formulas_que_usam($atual["identificador"], "variaveis");
        if (count($usam) > 0) {
            $erros[] = "Não dá para excluir " . $atual["identificador"] . ": estas fórmulas usam: " . implode(", ", $usam) . ".";
        } else {
            $n = (int)valor("SELECT COUNT(*) FROM campo_valor WHERE campo_id = ?", [$id]);
            sql("DELETE FROM campo WHERE id = ?", [$id]);
            campos_todos(true);
            $msg = "Campo " . $atual["nome"] . " excluído" . ($n > 0 ? ", com os valores dele em " . $n . ($n === 1 ? " relógio." : " relógios.") : ".");
        }
    } elseif ($acao === "ordem") {
        $todos = array_map("intval", array_column(linhas("SELECT id FROM campo ORDER BY ordem, nome"), "id"));
        $pos = array_search($id, $todos, true);
        $viz = ($d["direcao"] ?? "") === "sobe" ? $pos - 1 : $pos + 1;
        if ($viz >= 0 && $viz < count($todos)) {
            $todos[$pos] = $todos[$viz];
            $todos[$viz] = $id;
        }
        foreach ($todos as $k => $iid) {
            sql("UPDATE campo SET ordem = ? WHERE id = ?", [$k + 1, $iid]);
        }
        $msg = "Ordem alterada.";
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Tipos de lançamento. Ações: novo e alterar (id; identificador, nome, formato, unidade, fecha_as (HH:MM; só sessão:
// a sessão esquecida aberta fecha sozinha a essa hora do dia em que começou; vazio: não fecha), exclusiva (1 ou 0; só sessão),
// mede_gasto (1 ou 0; só com valor: cada leitura mede o gasto, comparando com a anterior), condicao (vale quando: uma fórmula,
// 1 vale e 0 não; vazia, vale sempre; ex.: corda_manual), no_id), excluir (id). Não se
// exclui um tipo com lançamentos ou usado numa fórmula; não se muda o formato de um tipo que já tem lançamentos.
// ---------------------------------------------------------------------------------------------------------------------
function op_lancamento_tipos($acao, $d)
{
    global $FORMATOS_LANCAMENTO;
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM lancamento_tipo WHERE id = ?", [$id]) : null;
    $com_lancamentos = $atual ? (int)valor("SELECT COUNT(*) FROM lancamento WHERE tipo_id = ?", [$id]) : 0;
    if (($acao === "alterar" || $acao === "excluir") && !$atual) {
        $erros[] = "Tipo de lançamento não encontrado.";
    } elseif ($acao === "novo" || $acao === "alterar") {
        $ident = trim((string)($d["identificador"] ?? ($atual["identificador"] ?? "")));
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $formato = (string)($d["formato"] ?? ($atual["formato"] ?? ""));
        $unidade = trim((string)($d["unidade"] ?? ($atual["unidade"] ?? "")));
        $fecha = trim((string)($d["fecha_as"] ?? ($atual ? substr((string)$atual["fecha_as"], 0, 5) : "")));
        $exclusiva = array_key_exists("exclusiva", $d) ? ((string)$d["exclusiva"] === "1" ? 1 : 0) : ($atual ? (int)$atual["exclusiva"] : 0);
        $mede_gasto = array_key_exists("mede_gasto", $d) ? ((string)$d["mede_gasto"] === "1" ? 1 : 0) : ($atual ? (int)$atual["mede_gasto"] : 0);
        $condicao = trim((string)($d["condicao"] ?? ($atual["condicao"] ?? "")));
        if ($condicao !== "") {
            foreach (formula_validar("", $condicao) as $e) {
                $erros[] = "Vale quando: " . $e . ".";
            }
        }
        $no = array_key_exists("no_id", $d) ? no_do_pedido($d["no_id"]) : ($atual ? ($atual["no_id"] === null ? null : (int)$atual["no_id"]) : null);
        if ($fecha !== "" && preg_match("/^([01][0-9]|2[0-3]):[0-5][0-9]\$/", $fecha) !== 1) {
            $erros[] = "Fecha sozinho às: uma hora HH:MM, ou vazio.";
        } elseif ($fecha !== "" && $formato !== "sessao") {
            $erros[] = "Fecha sozinho às só vale para sessão (com início e fim).";
        }
        if (!identificador_valido($ident)) {
            $erros[] = "Identificador: minúsculas, números e _, começando por letra, até 40 (é o nome nas fórmulas: HORAS(\"" . ($ident !== "" ? $ident : "pulso") . "\"; 30)).";
        } elseif ((int)valor("SELECT COUNT(*) FROM lancamento_tipo WHERE identificador = ? AND id <> ?", [$ident, $id]) > 0) {
            $erros[] = "Já existe um tipo de lançamento " . $ident . ".";
        }
        if ($atual && $ident !== $atual["identificador"] && count(formulas_que_usam($atual["identificador"], "lancamentos")) > 0) {
            $erros[] = "Não dá para renomear " . $atual["identificador"] . ": estas fórmulas usam: " . implode(", ", formulas_que_usam($atual["identificador"], "lancamentos")) . ".";
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = "Dê um nome (até 120 caracteres).";
        }
        if (!isset($FORMATOS_LANCAMENTO[$formato])) {
            $erros[] = "Formato: " . implode(", ", array_keys($FORMATOS_LANCAMENTO)) . ".";
        } elseif ($atual && $formato !== $atual["formato"] && $com_lancamentos > 0) {
            $erros[] = "Não dá para mudar o formato: já há " . $com_lancamentos . " lançamentos deste tipo.";
        }
        if ($no === false) {
            $erros[] = "O ponto da árvore não existe.";
        }
        if (count($erros) === 0) {
            if ($acao === "novo") {
                sql("INSERT INTO lancamento_tipo (identificador, nome, formato, unidade, fecha_as, exclusiva, mede_gasto, condicao, no_id, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$ident, $nome, $formato, $unidade, $fecha !== "" ? $fecha : null, $formato === "sessao" ? $exclusiva : 0, $formato === "valor" ? $mede_gasto : 0,
                    $condicao !== "" ? $condicao : null, $no,
                    (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM lancamento_tipo")]);
                $id = ultimo_id();
                $msg = "Tipo de lançamento " . $nome . " criado, para " . no_caminho($no) . ".";
            } else {
                sql("UPDATE lancamento_tipo SET identificador = ?, nome = ?, formato = ?, unidade = ?, fecha_as = ?, exclusiva = ?, mede_gasto = ?, condicao = ?, no_id = ? WHERE id = ?",
                    [$ident, $nome, $formato, $unidade, $fecha !== "" ? $fecha : null, $formato === "sessao" ? $exclusiva : 0, $formato === "valor" ? $mede_gasto : 0,
                    $condicao !== "" ? $condicao : null, $no, $id]);
                $msg = "Tipo de lançamento " . $nome . " salvo.";
            }
            lancamento_tipos(true);
        }
    } elseif ($acao === "excluir") {
        $usam = formulas_que_usam($atual["identificador"], "lancamentos");
        if ($com_lancamentos > 0) {
            $erros[] = "Não dá para excluir " . $atual["nome"] . ": há " . $com_lancamentos . " lançamentos deste tipo.";
        } elseif (count($usam) > 0) {
            $erros[] = "Não dá para excluir " . $atual["identificador"] . ": estas fórmulas usam: " . implode(", ", $usam) . ".";
        } else {
            sql("DELETE FROM lancamento_tipo WHERE id = ?", [$id]);
            lancamento_tipos(true);
            $msg = "Tipo de lançamento " . $atual["nome"] . " excluído.";
        }
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Fórmulas. Ações: nova (identificador, nome, expressao, unidade, no_id: uma fórmula nova, ou mais uma versão de uma que
// já existe, em outro ponto da árvore), alterar (id; nome, expressao, unidade, no_id), excluir (id: não se exclui a
// última versão de uma fórmula que outra usa). A expressão é conferida antes de gravar (formula_validar).
// ---------------------------------------------------------------------------------------------------------------------
function op_formulas($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM formula WHERE id = ?", [$id]) : null;
    if (($acao === "alterar" || $acao === "excluir") && !$atual) {
        $erros[] = "Fórmula não encontrada.";
    } elseif ($acao === "nova" || $acao === "alterar") {
        $ident = $atual ? $atual["identificador"] : trim((string)($d["identificador"] ?? ""));
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $expr = trim((string)($d["expressao"] ?? ($atual["expressao"] ?? "")));
        $unidade = trim((string)($d["unidade"] ?? ($atual["unidade"] ?? "")));
        $no = array_key_exists("no_id", $d) ? no_do_pedido($d["no_id"]) : ($atual ? ($atual["no_id"] === null ? null : (int)$atual["no_id"]) : null);
        if (!identificador_valido($ident)) {
            $erros[] = "Identificador: minúsculas, números e _, começando por letra, até 40 (é o nome da fórmula nas outras fórmulas).";
        } elseif (isset(campos_todos()[$ident])) {
            $erros[] = "Já existe um campo com o identificador " . $ident . ".";
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = "Dê um nome à fórmula (até 120 caracteres).";
        }
        if ($no === false) {
            $erros[] = "O ponto da árvore não existe.";
        } elseif ((int)valor("SELECT COUNT(*) FROM formula WHERE identificador = ? AND no_id <=> ? AND id <> ?", [$ident, $no, $id]) > 0) {
            $erros[] = "A fórmula " . $ident . " já tem uma versão em " . no_caminho($no) . ": altere aquela.";
        }
        if ($expr === "") {
            $erros[] = "Escreva o cálculo.";
        } else {
            foreach (formula_validar($ident, $expr) as $e) {
                $erros[] = "Cálculo: " . $e . ".";
            }
        }
        if (count($erros) === 0) {
            if ($acao === "nova") {
                sql("INSERT INTO formula (identificador, nome, expressao, unidade, no_id) VALUES (?, ?, ?, ?, ?)", [$ident, $nome, $expr, $unidade, $no]);
                $id = ultimo_id();
                $msg = "Fórmula " . $ident . " gravada para " . no_caminho($no) . ".";
            } else {
                sql("UPDATE formula SET nome = ?, expressao = ?, unidade = ?, no_id = ? WHERE id = ?", [$nome, $expr, $unidade, $no, $id]);
                $msg = "Fórmula " . $ident . " (" . no_caminho($no) . ") salva.";
            }
            formulas_todas(true);
        }
    } elseif ($acao === "excluir") {
        $versoes = count(formulas_todas()[$atual["identificador"]] ?? []);
        $usam = array_filter(formulas_que_usam($atual["identificador"], "variaveis"), function ($x) use ($atual) {
            return strpos($x, $atual["identificador"] . " (") !== 0;
        });
        if ($versoes <= 1 && count($usam) > 0) {
            $erros[] = "Não dá para excluir " . $atual["identificador"] . ": estas fórmulas usam: " . implode(", ", $usam) . ".";
        } else {
            sql("DELETE FROM formula WHERE id = ?", [$id]);
            formulas_todas(true);
            $msg = "A versão de " . $atual["identificador"] . " para " . no_caminho($atual["no_id"]) . " foi excluída."
                . ($versoes > 1 ? " Os relógios dali passam a usar a versão de cima." : "");
        }
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Relógios. Ações: salvar (id: 0 ou ausente cria; nome, no_id, disponivel (1 ou 0), copia_banco (1 ou 0: a cópia no banco
// de todos os documentos dele), valores[identificador]: só muda o que vier no pedido; valor vazio apaga; valor inválido
// fica o que estava, e a mensagem diz quais), excluir (id), foto
// (id, foto_base64: JPEG, PNG ou WebP, até 4 MB), remover_foto (id). Os documentos do relógio: op_documentos.
// ---------------------------------------------------------------------------------------------------------------------
function op_relogio($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = "Relógio não encontrado.";
    } elseif ($acao === "salvar") {
        $nome = array_key_exists("nome", $d) ? trim((string)$d["nome"]) : ($atual["nome"] ?? "");
        $no = array_key_exists("no_id", $d) ? no_do_pedido($d["no_id"]) : ($atual ? ($atual["no_id"] === null ? null : (int)$atual["no_id"]) : null);
        $disp = array_key_exists("disponivel", $d) ? ((string)$d["disponivel"] === "1" ? 1 : 0) : ($atual ? (int)$atual["disponivel"] : 1);
        $copia = array_key_exists("copia_banco", $d) ? ((string)$d["copia_banco"] === "1" ? 1 : 0) : ($atual ? (int)$atual["copia_banco"] : 0);
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = "Dê um nome ao relógio (até 120 caracteres).";
        }
        if ($no === false) {
            $erros[] = "O ponto da árvore não existe.";
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE relogio SET nome = ?, no_id = ?, disponivel = ?, copia_banco = ? WHERE id = ?", [$nome, $no, $disp, $copia, $id]);
            } else {
                sql("INSERT INTO relogio (nome, no_id, disponivel, copia_banco, criado) VALUES (?, ?, ?, ?, NOW())", [$nome, $no, $disp, $copia]);
                $id = ultimo_id();
            }
            relogio_copia_banco($id, true);
            // os valores: só dos campos que valem para o relógio (no ponto em que ficou)
            $campos = campos_do_relogio(["no_id" => $no]);
            $ignorados = [];
            foreach ((array)($d["valores"] ?? []) as $ident => $v) {
                if (!isset($campos[$ident])) {
                    $ignorados[] = $ident . " (não é campo de " . no_caminho($no) . ")";
                } else {
                    $conf = valor_para_campo($campos[$ident], $v);
                    if ($conf[1] !== "") {
                        $ignorados[] = $campos[$ident]["nome"] . " (" . $conf[1] . ")";
                    } elseif ($conf[0] === null) {
                        sql("DELETE FROM campo_valor WHERE relogio_id = ? AND campo_id = ?", [$id, (int)$campos[$ident]["id"]]);
                    } else {
                        sql("REPLACE INTO campo_valor (relogio_id, campo_id, valor) VALUES (?, ?, ?)", [$id, (int)$campos[$ident]["id"], $conf[0]]);
                    }
                }
            }
            // a marca da cópia no banco mudou: o cron copia (ou tira) os documentos dele aos poucos
            $aviso_copia = "";
            if ($atual && $copia !== (int)$atual["copia_banco"] && (int)valor("SELECT COUNT(*) FROM documento WHERE relogio_id = ?", [$id]) > 0) {
                $sistema = documentos_copia_sistema();
                $aviso_copia = $sistema !== null ? " (A cópia no banco dos documentos não muda: o config.php decide por todos, DOCUMENTOS_COPIA_BANCO = " . ($sistema ? "true" : "false") . ".)"
                    : ($copia === 1 ? " Os documentos dele vão para o banco aos poucos, pelo cron." : " As cópias no banco dos documentos dele que não pedem a própria cópia saem aos poucos, pelo cron (os arquivos continuam na pasta).");
            }
            $msg = ($atual ? "Relógio " . $nome . " salvo." : "Relógio " . $nome . " criado " . ($no === null ? "na raiz da árvore" : "em " . no_caminho($no)) . ".") . $aviso_copia
                . (count($ignorados) > 0 ? " Não gravei: " . implode("; ", $ignorados) . ". Ficou o que já estava." : "");
        }
    } elseif ($acao === "excluir" && $atual) {
        // os arquivos dos documentos saem da pasta (as linhas saem com o relógio, pelo banco)
        foreach (linhas("SELECT arquivo, miniatura FROM documento WHERE relogio_id = ?", [$id]) as $doc) {
            documento_apagar_arquivos($doc);
        }
        sql("DELETE FROM relogio WHERE id = ?", [$id]);
        $msg = "Relógio " . $atual["nome"] . " excluído, com os valores, a foto, os documentos e os lançamentos dele.";
        $id = 0;
    } elseif ($acao === "foto" && $atual) {
        $b64 = (string)($d["foto_base64"] ?? "");
        if (strpos($b64, "base64,") !== false) {
            $b64 = substr($b64, strpos($b64, "base64,") + 7);
        }
        $bin = base64_decode($b64, true);
        $info = $bin !== false && strlen($bin) > 0 && strlen($bin) <= 4 * 1024 * 1024 ? @getimagesizefromstring($bin) : false;
        if (!$info || !in_array($info["mime"], ["image/jpeg", "image/png", "image/webp"], true)) {
            $erros[] = "A foto não foi aceita: tem de ser JPEG, PNG ou WebP, de até 4 MB.";
        } else {
            sql("REPLACE INTO foto (relogio_id, tipo, dados, atualizado) VALUES (?, ?, ?, NOW())", [$id, $info["mime"], $bin]);
            $msg = "Foto de " . $atual["nome"] . " salva.";
        }
    } elseif ($acao === "remover_foto" && $atual) {
        sql("DELETE FROM foto WHERE relogio_id = ?", [$id]);
        $msg = "Foto de " . $atual["nome"] . " removida.";
    } elseif ($id === 0) {
        $erros[] = "Informe o relógio (id).";
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Documentos de um relógio. Ações: enviar (relogio_id, categoria_id, titulo, data AAAA-MM-DD, descricao e os arquivos: no
// campo arquivos[] (vários, como upload), ou arquivo (um), ou arquivo_base64 com o nome em arquivo_nome; a miniatura de
// uma foto, opcional, em miniatura (uma por envio: a do primeiro arquivo) ou miniatura_base64; copia_banco: 1 pede a cópia
// no banco destes arquivos), alterar (id: categoria_id, titulo, data, descricao, copia_banco; só muda o que vier), excluir
// (id: o documento, o arquivo dele e a cópia no banco). O arquivo vai para a pasta e, se a cópia é pedida (pelo sistema,
// pelo relógio ou pelo próprio arquivo: documento_copia_por), também para o banco, em pedaços.
// ---------------------------------------------------------------------------------------------------------------------
function op_documentos($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM documento WHERE id = ?", [$id]) : null;
    $pasta = documentos_pasta();
    if ($pasta === null && $acao !== "excluir" && $acao !== "alterar") {
        $erros[] = "Não dá para guardar documentos: " . documentos_pasta_erro() . ".";
        return resultado($erros, $msg, ["id" => $id, "ids" => []]);
    }
    // a categoria do pedido (ou a de antes) e as famílias que ela aceita
    $cat_id = array_key_exists("categoria_id", $d) ? (int)$d["categoria_id"] : ($atual ? (int)$atual["categoria_id"] : 0);
    $cat = documento_categoria_linha($cat_id);
    $data = array_key_exists("data", $d) ? trim((string)$d["data"]) : ($atual ? (string)$atual["data"] : "");
    $data = $data === "" ? null : substr($data, 0, 10);
    if ($data !== null && (preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $data) !== 1 || !checkdate((int)substr($data, 5, 2), (int)substr($data, 8, 2), (int)substr($data, 0, 4)))) {
        $erros[] = "A data tem de ser AAAA-MM-DD (ou vazia).";
    }
    $titulo = trim((string)($d["titulo"] ?? ($atual["titulo"] ?? "")));
    // a cópia no banco pedida pelo próprio arquivo (o sistema e o relógio, se pedem, valem por cima dela)
    $copia = array_key_exists("copia_banco", $d) ? ((string)$d["copia_banco"] === "1" ? 1 : 0) : ($atual ? (int)$atual["copia_banco"] : 0);
    $descricao = array_key_exists("descricao", $d) ? trim((string)$d["descricao"]) : ($atual ? (string)$atual["descricao"] : "");
    if (mb_strlen($titulo) > 200) {
        $erros[] = "O título vai até 200 caracteres.";
    }
    $ids = [];
    if ($acao === "enviar") {
        $r = linha("SELECT id, nome FROM relogio WHERE id = ?", [(int)($d["relogio_id"] ?? 0)]);
        if (!$r) {
            $erros[] = "Relógio não encontrado.";
        }
        if (!$cat) {
            $erros[] = "Escolha a categoria.";
        }
        // os arquivos do pedido: [nome, caminho temporário ou null, conteúdo (base64) ou null, erro do envio]
        $arquivos = [];
        foreach (["arquivos", "arquivo"] as $campo) {
            $f = $_FILES[$campo] ?? null;
            if (is_array($f)) {
                $nomes = (array)$f["name"];
                foreach ($nomes as $k => $nome) {
                    $erro = (int)((array)$f["error"])[$k];
                    if ($erro !== UPLOAD_ERR_NO_FILE) {
                        $arquivos[] = [(string)$nome, (string)((array)$f["tmp_name"])[$k], null, $erro];
                    }
                }
            }
        }
        if (trim((string)($d["arquivo_base64"] ?? "")) !== "") {
            $b64 = (string)$d["arquivo_base64"];
            $bin = base64_decode(strpos($b64, "base64,") !== false ? substr($b64, strpos($b64, "base64,") + 7) : $b64, true);
            if ($bin === false) {
                $erros[] = "O arquivo_base64 não é base64.";
            } else {
                $arquivos[] = [trim((string)($d["arquivo_nome"] ?? "")) !== "" ? (string)$d["arquivo_nome"] : "arquivo", null, $bin, UPLOAD_ERR_OK];
            }
        }
        if (count($arquivos) === 0 && count($erros) === 0) {
            $erros[] = "Escolha o arquivo.";
        }
        // a miniatura da foto (a do primeiro arquivo), feita pelo navegador: só imagem JPEG, PNG ou WebP, até 1 MB
        $mini = null;
        if (isset($_FILES["miniatura"]) && (int)$_FILES["miniatura"]["error"] === UPLOAD_ERR_OK && is_uploaded_file($_FILES["miniatura"]["tmp_name"])) {
            $mini = (string)file_get_contents($_FILES["miniatura"]["tmp_name"]);
        } elseif (trim((string)($d["miniatura_base64"] ?? "")) !== "") {
            $b64 = (string)$d["miniatura_base64"];
            $mini = base64_decode(strpos($b64, "base64,") !== false ? substr($b64, strpos($b64, "base64,") + 7) : $b64, true);
        }
        if (is_string($mini)) {
            $info = strlen($mini) <= 1048576 ? @getimagesizefromstring($mini) : false;
            $mini = $info && in_array($info["mime"], ["image/jpeg", "image/png", "image/webp"], true) ? $mini : null;
        }
        $limite = documentos_limite();
        $aceita = $cat ? documento_familias_aceitas($cat["aceita"]) : [];
        $nomes_fam = ["imagem" => "imagens", "video" => "vídeos", "audio" => "áudios", "pdf" => "PDF", "xml" => "XML"];
        $avisos = [];
        $copiados_banco = 0;
        if (count($erros) === 0) {
            foreach ($arquivos as $k => $a) {
                list($nome, $tmp, $bin, $erro) = $a;
                $nome = trim(preg_replace("/[\\x00-\\x1f]/", "", basename(str_replace("\\", "/", $nome))));
                $nome = $nome !== "" ? mb_substr($nome, 0, 255) : "arquivo";
                if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
                    $erros[] = $nome . ": passou do tamanho que o servidor aceita (o upload_max_filesize do php.ini" . ($limite !== null ? ", " . tamanho_texto($limite) : "") . ").";
                    continue;
                }
                if ($erro !== UPLOAD_ERR_OK || ($tmp !== null && !is_uploaded_file($tmp))) {
                    $erros[] = $nome . ": não chegou inteiro (erro " . $erro . " no envio). Tente de novo.";
                    continue;
                }
                $tamanho = $tmp !== null ? (int)filesize($tmp) : strlen($bin);
                if ($tamanho === 0) {
                    $erros[] = $nome . ": o arquivo está vazio.";
                    continue;
                }
                if ($limite !== null && $tamanho > $limite) {
                    $erros[] = $nome . ": tem " . tamanho_texto($tamanho) . "; o servidor aceita até " . tamanho_texto($limite) . ".";
                    continue;
                }
                $arquivo = documento_novo_arquivo($pasta, (int)$r["id"], $nome);
                $ok = $tmp !== null ? @move_uploaded_file($tmp, $pasta . "/" . $arquivo) : @file_put_contents($pasta . "/" . $arquivo, $bin) === $tamanho;
                if (!$ok) {
                    $erros[] = $nome . ": não consegui gravar na pasta dos documentos.";
                    continue;
                }
                $tipo = documento_tipo($pasta . "/" . $arquivo, $nome);
                $familia = documento_familia($tipo, $nome);
                if (count($aceita) > 0 && !in_array($familia, $aceita, true)) {
                    @unlink($pasta . "/" . $arquivo);
                    $erros[] = $nome . ": a categoria " . $cat["nome"] . " aceita só " . implode(", ", array_map(function ($f) use ($nomes_fam) { return $nomes_fam[$f] ?? $f; }, $aceita)) . ".";
                    continue;
                }
                $arq_mini = null;
                if ($k === 0 && $mini !== null && $familia === "imagem") {
                    $arq_mini = $arquivo . ".mini.jpg";
                    if (@file_put_contents($pasta . "/" . $arq_mini, $mini) !== strlen($mini)) {
                        $arq_mini = null;
                    }
                }
                $t = $titulo !== "" ? $titulo : mb_substr(pathinfo($nome, PATHINFO_FILENAME), 0, 200);
                try {
                    sql("INSERT INTO documento (relogio_id, categoria_id, titulo, data, descricao, nome, tipo, tamanho, arquivo, miniatura, copia_banco, criado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [(int)$r["id"], (int)$cat["id"], $t !== "" ? $t : $nome, $data, $descricao !== "" ? $descricao : null, $nome, $tipo, $tamanho, $arquivo, $arq_mini, $copia]);
                    $ids[] = ultimo_id();
                    // a cópia de segurança no banco, em pedaços, se alguém a pede; se falhar, o documento fica (o cron tenta de novo)
                    $novo_doc = linha("SELECT * FROM documento WHERE id = ?", [end($ids)]);
                    if (documento_copia_por($novo_doc) !== null) {
                        $copiados_banco++;
                        $erro_copia = documento_copiar_para_banco($novo_doc);
                        if ($erro_copia !== "") {
                            $avisos[] = $nome . ": a cópia no banco falhou (" . $erro_copia . "); o cron tenta de novo";
                        }
                    }
                } catch (BancoErro $e) {
                    documento_apagar_arquivos(["arquivo" => $arquivo, "miniatura" => $arq_mini]);
                    $erros[] = $nome . ": o banco recusou (" . $e->getMessage() . ").";
                }
            }
            if (count($ids) > 0) {
                $msg = (count($ids) === 1 ? "1 documento guardado" : count($ids) . " documentos guardados") . " em " . $cat["nome"] . " do " . $r["nome"]
                    . ($copiados_banco > 0 ? ", na pasta e no banco." : ", só na pasta (sem cópia no banco).")
                    . (count($avisos) > 0 ? " Atenção: " . implode("; ", $avisos) . "." : "");
                $id = $ids[0];
            }
        }
    } elseif ($acao === "alterar" || $acao === "excluir") {
        if (!$atual) {
            $erros[] = "Documento não encontrado.";
        } elseif ($acao === "excluir") {
            documento_apagar_arquivos($atual);
            sql("DELETE FROM documento WHERE id = ?", [$id]);
            $msg = "Documento " . $atual["titulo"] . " excluído.";
        } else {
            if (!$cat) {
                $erros[] = "A categoria não existe.";
            } elseif ((int)$cat["id"] !== (int)$atual["categoria_id"] && count(documento_familias_aceitas($cat["aceita"])) > 0
                && !in_array(documento_familia($atual["tipo"], $atual["nome"]), documento_familias_aceitas($cat["aceita"]), true)) {
                $erros[] = "A categoria " . $cat["nome"] . " não aceita este tipo de arquivo (" . $atual["tipo"] . ").";
            }
            if ($titulo === "") {
                $erros[] = "Dê um título ao documento.";
            }
            if (count($erros) === 0) {
                sql("UPDATE documento SET categoria_id = ?, titulo = ?, data = ?, descricao = ?, copia_banco = ? WHERE id = ?",
                    [(int)$cat["id"], $titulo, $data, $descricao !== "" ? $descricao : null, $copia, $id]);
                $msg = "Documento " . $titulo . " salvo.";
                // a cópia no banco segue o pedido na hora: entra se agora alguém pede, sai se ninguém mais pede
                $doc = linha("SELECT * FROM documento WHERE id = ?", [$id]);
                $por = documento_copia_por($doc);
                if ($por !== null && (int)$doc["no_banco"] !== 1) {
                    $erro_copia = documento_copiar_para_banco($doc);
                    $msg .= $erro_copia === "" ? " A cópia foi para o banco." : " A cópia no banco falhou (" . $erro_copia . "); o cron tenta de novo.";
                } elseif ($por === null && (int)$doc["no_banco"] === 1) {
                    $erro_copia = documento_tirar_do_banco($doc);
                    $msg .= $erro_copia === "" ? " A cópia saiu do banco (o arquivo continua na pasta)." : " A cópia continua no banco: " . $erro_copia . ".";
                } elseif ($copia === 1 && $por === null) {
                    $msg .= " A cópia não vai para o banco: o config.php (DOCUMENTOS_COPIA_BANCO = false) não deixa.";
                }
            }
        }
    } else {
        $erros[] = "Ação desconhecida: use enviar, alterar ou excluir.";
    }
    return resultado($erros, $msg, ["id" => $id, "ids" => $ids]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Categorias dos documentos (cadastro). Ações: nova (identificador, nome, aceita, ordem), alterar (id: nome, aceita, ordem;
// o identificador não muda), excluir (id; só sem documentos). "aceita": as famílias aceitas (aceita[] ou o texto separado
// por vírgula: imagem, video, audio, pdf, xml); vazio, qualquer arquivo.
// ---------------------------------------------------------------------------------------------------------------------
function op_documento_categorias($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? documento_categoria_linha($id) : null;
    if (($acao === "alterar" || $acao === "excluir") && !$atual) {
        $erros[] = "Categoria não encontrada.";
    } elseif ($acao === "nova" || $acao === "alterar") {
        $ident = $atual ? $atual["identificador"] : trim((string)($d["identificador"] ?? ""));
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $aceita = array_key_exists("aceita", $d) ? (is_array($d["aceita"]) ? $d["aceita"] : documento_familias_aceitas($d["aceita"])) : documento_familias_aceitas($atual["aceita"] ?? "");
        $aceita = array_values(array_unique(array_filter(array_map("trim", $aceita), function ($x) { return $x !== ""; })));
        $ordem = array_key_exists("ordem", $d) && trim((string)$d["ordem"]) !== "" ? (int)$d["ordem"] : ($atual ? (int)$atual["ordem"] : (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM documento_categoria"));
        if (!identificador_valido($ident)) {
            $erros[] = "Identificador: minúsculas, números e _, começando por letra, até 40.";
        } elseif (!$atual && valor("SELECT id FROM documento_categoria WHERE identificador = ?", [$ident]) !== null) {
            $erros[] = "Já existe a categoria " . $ident . ".";
        }
        if ($nome === "" || mb_strlen($nome) > 120) {
            $erros[] = "Dê um nome à categoria (até 120 caracteres).";
        }
        foreach ($aceita as $f) {
            if (!in_array($f, ["imagem", "video", "audio", "pdf", "xml"], true)) {
                $erros[] = "Tipo aceito desconhecido: " . $f . " (use imagem, video, audio, pdf ou xml; nenhum: qualquer arquivo).";
            }
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE documento_categoria SET nome = ?, ordem = ? WHERE id = ?", [$nome, $ordem, $id]);
                documento_categoria_gravar_aceita($id, $aceita);
                $msg = "Categoria " . $nome . " salva.";
            } else {
                sql("INSERT INTO documento_categoria (identificador, nome, ordem) VALUES (?, ?, ?)", [$ident, $nome, $ordem]);
                $id = ultimo_id();
                documento_categoria_gravar_aceita($id, $aceita);
                $msg = "Categoria " . $nome . " criada.";
            }
        }
    } elseif ($acao === "excluir") {
        $n = (int)valor("SELECT COUNT(*) FROM documento WHERE categoria_id = ?", [$id]);
        if ($n > 0) {
            $erros[] = "A categoria " . $atual["nome"] . " tem " . $n . ($n === 1 ? " documento" : " documentos") . ": passe para outra categoria ou exclua antes.";
        } else {
            sql("DELETE FROM documento_categoria WHERE id = ?", [$id]);
            $msg = "Categoria " . $atual["nome"] . " excluída.";
        }
    } else {
        $erros[] = "Ação desconhecida: use nova, alterar ou excluir.";
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Lançamentos. Ações:
//   lancar (relogio_id, tipo, valor (formato "valor"), quando (AAAA-MM-DD HH:MM; vazio: agora), medir (1 ou 0; padrão 1)):
//           instantâneo ou com valor. Num tipo que mede o gasto (a leitura de carga), a leitura é comparada com a anterior,
//           como no sistema antigo: subiu, ou ficou bem acima do que a estimativa previa, é recarga (novo ponto de partida,
//           sem medir); menos de 12 horas, pouco tempo para medir; senão o intervalo é separado em horas no pulso e guardado
//           e mede o gasto em uso (com meio dia de uso ou mais) ou guardado. Com medir=1, a medição entra na média (a
//           caixa "Atualizar o gasto com esta medição"); com 0, fica só no histórico. Leitura com data anterior à última
//           não mede.
//   iniciar (relogio_id, tipo, quando): abre uma sessão; se ela é exclusiva, fecha no mesmo instante a exclusiva aberta
//                                       em outro lugar (o relógio fica num lugar só)
//   encerrar (relogio_id, tipo, quando): fecha a sessão aberta daquele tipo
//   periodo (relogio_id, tipo, inicio, fim): uma sessão que já passou
//   alterar (id; quando, inicio, fim, valor: só o que vier), excluir (id)
// Nada no futuro. O tipo tem de valer para o relógio (o ponto dele na árvore). Sessões exclusivas não se sobrepõem.
// ---------------------------------------------------------------------------------------------------------------------
function op_lancamento($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $agora = time();
    // um momento vindo do pedido (AAAA-MM-DD HH:MM, com espaço ou T, como manda o campo de data e hora do navegador): vazio =
    // agora; false se não é data e hora válidas ou está no futuro
    $momento = function ($v) use ($agora) {
        $v = str_replace("T", " ", trim((string)$v));
        $res = false;
        if ($v === "") {
            $res = $agora;
        } else {
            $dt = DateTimeImmutable::createFromFormat("!Y-m-d H:i", substr($v, 0, 16));
            if ($dt && $dt->format("Y-m-d H:i") === substr($v, 0, 16) && $dt->getTimestamp() <= $agora + 60) {
                $res = $dt->getTimestamp();
            }
        }
        return $res;
    };
    $existente = $id > 0 ? linha("SELECT l.*, t.identificador AS tipo, t.formato, t.exclusiva, t.nome AS tipo_nome FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.id = ?", [$id]) : null;
    $rid = $existente ? (int)$existente["relogio_id"] : (int)($d["relogio_id"] ?? 0);
    $r = linha("SELECT * FROM relogio WHERE id = ?", [$rid]);
    $t = $existente ? lancamento_tipos()[$existente["tipo"]] : ($r ? (lancamento_tipos_do_relogio($r)[(string)($d["tipo"] ?? "")] ?? null) : null);
    // as sessões esquecidas abertas do relógio fecham antes (o Pôs de hoje não esbarra na de ontem, nem o Tirou a estica)
    if ($r) {
        fechar_esquecidas($agora, $rid);
    }
    // sessões exclusivas do relógio que se sobrepõem a [a, b) (fora a $ignorar)
    $sobrepostas = function ($a, $b, $ignorar) use ($rid) {
        return linhas("SELECT l.id, t.nome, l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE l.relogio_id = ? AND t.exclusiva = 1 AND l.id <> ? AND l.inicio < ? AND (l.fim IS NULL OR l.fim > ?)",
            [$rid, $ignorar, date("Y-m-d H:i:s", $b), date("Y-m-d H:i:s", $a)]);
    };
    if (($acao === "alterar" || $acao === "excluir") && !$existente) {
        $erros[] = "Lançamento não encontrado.";
    } elseif (!$r) {
        $erros[] = "Relógio não encontrado (relogio_id).";
    } elseif (!$t) {
        $erros[] = "Tipo de lançamento desconhecido para " . $r["nome"] . ": use um destes: " . implode(", ", array_keys(lancamento_tipos_do_relogio($r))) . ".";
    } elseif ($acao === "lancar") {
        $q = $momento($d["quando"] ?? "");
        $v = numero_br($d["valor"] ?? "");
        if ($t["formato"] === "sessao") {
            $erros[] = $t["nome"] . " é uma sessão: use iniciar e encerrar, ou periodo.";
        } elseif ($q === false) {
            $erros[] = "Quando: AAAA-MM-DD HH:MM, até agora (vazio: agora).";
        } elseif ($t["formato"] === "valor" && $v === null) {
            $erros[] = $t["nome"] . " precisa de um valor (número" . ($t["unidade"] !== "" ? ", em " . $t["unidade"] : "") . ").";
        } else {
            // o gasto medido: a leitura de antes (do mesmo tipo), a estimativa e os gastos que valiam, tudo antes de gravar esta
            $mede = $t["formato"] === "valor" && (int)($t["mede_gasto"] ?? 0) === 1
                && valor("SELECT id FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND inicio > ?", [$rid, (int)$t["id"], date("Y-m-d H:i:s", $q)]) === null;
            $antes = $mede ? linha("SELECT inicio, valor FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND inicio <= ? ORDER BY inicio DESC, id DESC LIMIT 1",
                [$rid, (int)$t["id"], date("Y-m-d H:i:s", $q)]) : null;
            $conta = [];
            if ($antes) {
                $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
                foreach (["energia", "taxa_uso", "taxa_repouso"] as $f) {
                    $c = ["r" => $r, "momento" => $q, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($rid)];
                    $conta[$f] = isset(formulas_todas()[$f]) ? variavel_valor($f, $c) : null;
                }
            }
            sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, NULL, ?, 'manual', NOW())",
                [$rid, (int)$t["id"], date("Y-m-d H:i:s", $q), $t["formato"] === "valor" ? $v : null]);
            $id = ultimo_id();
            $msg = "Lançado no " . $r["nome"] . ": " . $t["nome"] . ($t["formato"] === "valor" ? " " . str_replace('.', ',', (string)$v) . ($t["unidade"] !== "" ? " " . $t["unidade"] : "") : "")
                . ($q < $agora - 60 ? " em " . date("d/m/Y H:i", $q) : "") . ".";
            if ($mede && !$antes) {
                $msg .= " Primeira leitura: é o ponto de partida.";
            } elseif ($antes) {
                $t1 = strtotime($antes["inicio"]);
                $horas = ($q - $t1) / 3600;
                $anterior = (float)$antes["valor"];
                $queda = $anterior - $v;
                $estimada = is_numeric($conta["energia"]) ? (float)$conta["energia"] : null;
                // folga para não confundir recarga com estimativa um pouco errada: metade da queda esperada, no mínimo 10 pontos
                $folga = max(10, $estimada === null ? 0 : ($anterior - $estimada) * 0.5);
                $un = $t["unidade"];
                $de = "De " . str_replace(".", ",", (string)(0 + $anterior)) . $un . " em " . date("d/m H:i", $t1) . " a " . str_replace(".", ",", (string)(0 + $v)) . $un
                    . ($q < $agora - 60 ? " em " . date("d/m H:i", $q) : " agora");
                $pela_estimativa = $estimada === null ? "" : " A estimativa era ~" . (int)round($estimada) . $un . ".";
                if ($queda < 0) {
                    $msg .= " Carregado: a leitura anterior era " . str_replace(".", ",", (string)(0 + $anterior)) . $un . ($estimada === null ? "" : " e a estimativa ~" . (int)round($estimada) . $un)
                        . ". Novo ponto de partida.";
                } elseif ($estimada !== null && $v > $estimada + $folga) {
                    $msg .= " Carregado em parte: pela estimativa ele estava com ~" . (int)round($estimada) . $un . ". Novo ponto de partida, sem medir o gasto deste intervalo.";
                } elseif ($horas < 1) {
                    $msg .= " " . $de . ": menos de 1 hora desde a leitura anterior, pouco tempo para medir o gasto." . $pela_estimativa;
                } else {
                    // as horas no pulso entre as duas leituras
                    $h_pulso = horas_no_pulso($rid, $t1, $q);
                    $h_guardado = $horas - $h_pulso;
                    $h_dia = (strtotime("2000-01-01 " . cfg("uso_fim")) - strtotime("2000-01-01 " . cfg("uso_inicio"))) / 3600;
                    $dias_uso = $h_pulso / max(1, $h_dia);
                    $dias_guardado = $h_guardado / 24;
                    $tempo = ($h_pulso >= 1 ? (int)round($h_pulso) . " h no pulso e " : "") . (int)round($h_guardado) . " h fora do pulso";
                    // todo intervalo entra na conta dos dois gastos (gasto_medido), até o sem queda nenhuma: ele diz que o gasto é
                    // pequeno. O gasto do intervalo sozinho fica no histórico: com pelo menos meio dia de uso, ou mais dias de uso que
                    // fora do pulso, o de uso (descontando o fora do pulso que valia); senão, o de fora (descontando o pouco de uso)
                    $medida = $dias_uso >= 0.5 || $dias_uso >= $dias_guardado ? "uso" : "repouso";
                    $taxa = $medida === "uso" ? ($queda - $dias_guardado * (float)$conta["taxa_repouso"]) / $dias_uso
                        : ($queda - $dias_uso * (float)$conta["taxa_uso"]) / max(0.01, $dias_guardado);
                    $taxa = round(max(0, min(100, $taxa)), 3);
                    $usada = !array_key_exists("medir", $d) || (string)$d["medir"] === "1";
                    sql("INSERT INTO medicao (lancamento_id, medida, taxa, de_valor, ate_valor, inicio, fim, horas_pulso, horas_guardado, peso_horas, usada, criado)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", [$id, $medida, $taxa, $anterior, $v, date("Y-m-d H:i:s", $t1), date("Y-m-d H:i:s", $q),
                        round($h_pulso, 2), round($h_guardado, 2), round($medida === "uso" ? $h_pulso : $h_guardado, 2), $usada ? 1 : 0]);
                    $msg .= " " . $de . ": " . $tempo . "." . $pela_estimativa;
                    if ($usada) {
                        // os dois gastos que passam a valer, com esta medição junto (a mesma conta da função MEDIDO)
                        $g = gasto_medido(linhas("SELECT m.medida, m.taxa, m.peso_horas, m.fim, m.horas_pulso, m.horas_guardado, m.de_valor, m.ate_valor FROM medicao m
                            JOIN lancamento l ON l.id = m.lancamento_id WHERE l.relogio_id = ? AND m.usada = 1 ORDER BY m.fim, m.id", [$rid]), $q);
                        $num = function ($x) { return str_replace(".", ",", (string)(0 + round((float)$x, 2))); };
                        $partes = [];
                        if ($g["uso"] !== null) {
                            $partes[] = "em uso " . $num($g["uso"]) . "% por dia de uso";
                        }
                        if ($g["repouso"] !== null) {
                            $partes[] = "fora do pulso " . $num($g["repouso"]) . "% por dia";
                        }
                        $msg .= " Gasto medido agora" . (count($partes) > 0 ? ": " . implode(", ", $partes) : ": ainda sem como separar") . " ("
                            . ($g["conjunta"] ? "os dois juntos, de " : "") . $g["n"] . ($g["n"] === 1 ? " medição" : " medições") . " dos últimos "
                            . max(1, (int)cfg("medicao_janela_dias")) . " dias).";
                    } else {
                        $msg .= " Entrou só no histórico.";
                    }
                }
            }
        }
    } elseif ($acao === "iniciar") {
        $q = $momento($d["quando"] ?? "");
        if ($t["formato"] !== "sessao") {
            $erros[] = $t["nome"] . " não é uma sessão: use lancar.";
        } elseif ($q === false) {
            $erros[] = "Quando: AAAA-MM-DD HH:MM, até agora (vazio: agora).";
        } elseif (valor("SELECT id FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND inicio <= ? AND (fim IS NULL OR fim > ?)", [$rid, (int)$t["id"], date("Y-m-d H:i:s", $q), date("Y-m-d H:i:s", $q)]) !== null) {
            $erros[] = $r["nome"] . " já está com " . $t["nome"] . " aberto.";
        } else {
            $fechadas = [];
            if ((int)$t["exclusiva"] === 1) {
                // primeiro a conferência, sem mexer em nada: nesse instante, o relógio não pode estar numa sessão exclusiva
                // já fechada (um período lançado antes) nem numa aberta que começou depois dele
                $conflito = linha("SELECT t.nome, l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
                    WHERE l.relogio_id = ? AND t.exclusiva = 1 AND l.inicio > ? AND l.inicio <= ? LIMIT 1",
                    [$rid, date("Y-m-d H:i:s", $q), date("Y-m-d H:i:s", $agora)]);
                if ($conflito) {
                    $erros[] = "Nesse momento " . $r["nome"] . " já estava em " . $conflito["nome"] . " (de " . date("d/m H:i", strtotime($conflito["inicio"])) . " a "
                        . ($conflito["fim"] === null ? "agora" : date("d/m H:i", strtotime($conflito["fim"]))) . "): o relógio fica num lugar só.";
                } else {
                    // o relógio num lugar só: a exclusiva aberta em outro lugar fecha no mesmo instante
                    foreach (linhas("SELECT l.id, t.nome FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.exclusiva = 1 AND l.inicio <= ? AND (l.fim IS NULL OR l.fim > ?)",
                        [$rid, date("Y-m-d H:i:s", $q), date("Y-m-d H:i:s", $q)]) as $x) {
                        sql("UPDATE lancamento SET fim = ? WHERE id = ?", [date("Y-m-d H:i:s", $q), (int)$x["id"]]);
                        $fechadas[] = $x["nome"];
                    }
                }
            }
            if (count($erros) === 0) {
                // o Pôs no relógio do dia é o uso do rodízio (não "fora do rodízio"): sem pôr sozinho, é ele que abre o dia; pondo
                // sozinho, um Pôs antes do início do horário já conta como a sessão do dia (o cron não abre outra). Aberta até o
                // Tirou, ou até o "fecha às" do tipo (o fim do horário de uso, tirando sozinho)
                $p_dia = plano_do_dia(date("Y-m-d", $q));
                $origem = $t["identificador"] === "pulso" && $p_dia && (int)$p_dia["relogio_id"] === $rid ? "rodizio" : "manual";
                sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, NULL, NULL, ?, NOW())", [$rid, (int)$t["id"], date("Y-m-d H:i:s", $q), $origem]);
                $id = ultimo_id();
                $msg = $r["nome"] . ": " . $t["nome"] . " desde " . date("d/m H:i", $q) . "." . (count($fechadas) > 0 ? " Fechado no mesmo instante: " . implode(", ", $fechadas) . "." : "");
            }
        }
    } elseif ($acao === "encerrar") {
        $q = $momento($d["quando"] ?? "");
        // a sessão aberta agora: sem fim, ou com o fim ainda no futuro (a de rodízio já nasce com o fim do horário de uso)
        $aberta = linha("SELECT * FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND inicio <= ? AND (fim IS NULL OR fim > ?) ORDER BY inicio DESC, id DESC LIMIT 1",
            [$rid, (int)$t["id"], date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora)]);
        if ($q === false) {
            $erros[] = "Quando: AAAA-MM-DD HH:MM, até agora (vazio: agora).";
        } elseif (!$aberta) {
            $erros[] = $r["nome"] . " não está com " . $t["nome"] . " aberto.";
        } elseif ($q < strtotime($aberta["inicio"])) {
            $erros[] = "Quando: AAAA-MM-DD HH:MM, não antes do início (" . date("d/m H:i", strtotime($aberta["inicio"])) . ") e até agora (vazio: agora).";
        } else {
            sql("UPDATE lancamento SET fim = ? WHERE id = ?", [date("Y-m-d H:i:s", $q), (int)$aberta["id"]]);
            $id = (int)$aberta["id"];
            $min = (int)round(($q - strtotime($aberta["inicio"])) / 60);
            $msg = $r["nome"] . ": encerrada a sessão " . $t["nome"] . " (" . intdiv($min, 60) . " h " . ($min % 60) . " min).";
        }
    } elseif ($acao === "periodo" || $acao === "alterar") {
        // o período (ou a correção): início e fim no passado, fim depois do início, e sem sobrepor outra exclusiva
        $formato = $t["formato"];
        $ini = $acao === "periodo" || array_key_exists("inicio", $d) || array_key_exists("quando", $d)
            ? $momento($d["inicio"] ?? ($d["quando"] ?? "x")) : strtotime($existente["inicio"]);
        $fim = null;
        if ($formato === "sessao") {
            $fim = $acao === "periodo" || array_key_exists("fim", $d) ? (trim((string)($d["fim"] ?? "")) === "" && $acao === "alterar" ? null : $momento($d["fim"] ?? "x"))
                : ($existente["fim"] === null ? null : strtotime($existente["fim"]));
        }
        $v = $formato === "valor" ? (array_key_exists("valor", $d) ? numero_br($d["valor"]) : (float)$existente["valor"]) : null;
        if ($acao === "periodo" && $formato !== "sessao") {
            $erros[] = $t["nome"] . " não é uma sessão: use lancar.";
        } elseif ($ini === false || $fim === false) {
            $erros[] = "Início e fim: AAAA-MM-DD HH:MM, até agora.";
        } elseif ($fim !== null && $fim <= $ini) {
            $erros[] = "O fim tem de ser depois do início.";
        } elseif ($formato === "valor" && $v === null) {
            $erros[] = $t["nome"] . " precisa de um valor.";
        } elseif ((int)$t["exclusiva"] === 1 && count($sobrepostas($ini, $fim ?? $agora, $id)) > 0) {
            $s = $sobrepostas($ini, $fim ?? $agora, $id)[0];
            $erros[] = $r["nome"] . " já estava em " . $s["nome"] . " de " . date("d/m H:i", strtotime($s["inicio"])) . " a "
                . ($s["fim"] === null ? "agora" : date("d/m H:i", strtotime($s["fim"]))) . ": o relógio fica num lugar só.";
        } elseif ($acao === "periodo") {
            sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, 'manual', NOW())",
                [$rid, (int)$t["id"], date("Y-m-d H:i:s", $ini), date("Y-m-d H:i:s", $fim)]);
            $id = ultimo_id();
            $msg = "Lançado no " . $r["nome"] . ": " . $t["nome"] . " de " . date("d/m H:i", $ini) . " a " . date("d/m H:i", $fim) . ".";
        } else {
            sql("UPDATE lancamento SET inicio = ?, fim = ?, valor = ? WHERE id = ?", [date("Y-m-d H:i:s", $ini), $fim === null ? null : date("Y-m-d H:i:s", $fim), $v, $id]);
            $msg = "Corrigido no " . $r["nome"] . ": " . $t["nome"] . ".";
        }
    } elseif ($acao === "excluir") {
        sql("DELETE FROM lancamento WHERE id = ?", [$id]);
        $msg = "Excluído do " . $r["nome"] . ": " . $existente["tipo_nome"] . " de " . date("d/m/Y H:i", strtotime($existente["inicio"])) . ".";
        $id = 0;
    }
    // o pulso mudou no passado (Pôs ou Tirou com hora, período, correção, exclusão): as medições do gasto que cobrem o trecho
    // refazem as horas no pulso e fora
    if (count($erros) === 0 && $t && $t["identificador"] === "pulso" && in_array($acao, ["iniciar", "encerrar", "periodo", "alterar", "excluir"], true)) {
        $desde = [$agora];
        foreach ([$q ?? null, $ini ?? null, $existente ? strtotime($existente["inicio"]) : null] as $x) {
            if (is_int($x)) {
                $desde[] = $x;
            }
        }
        recalcular_medicoes($rid, min($desde), $agora + 1);
    }
    return resultado($erros, $msg, ["id" => $id]);
}


// ---------------------------------------------------------------------------------------------------------------------
// Avisos. Ações: novo (identificador, nome, expressao (a data prevista), condicao (vale quando: uma fórmula, 1 vale e 0 não;
// vazia, vale sempre; ex.: corda_manual), antecedencia_dias, texto ({relogio}, {data},
// {quando}, {limite}), resolve (o tipo de lançamento que resolve; vazio: nenhum), ativo (1 ou 0), no_id: um aviso novo, ou mais
// uma versão de um que existe, em outro ponto da árvore; escala: nao, uso ou sempre; simula_valor e simula_horas (o
// lançamento que a escala simula; vazio: nenhum); agenda: janela (na agenda, só dentro da antecedência) ou sempre (qualquer
// data; se vai ou não para a agenda é a Configuração, em "O que vai para onde")), alterar (id: o mesmo, menos o
// identificador), excluir (id).
// ---------------------------------------------------------------------------------------------------------------------
function op_avisos($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha(AVISO_SELECT . " WHERE a.id = ?", [$id]) : null;
    if (($acao === "alterar" || $acao === "excluir") && !$atual) {
        $erros[] = "Aviso não encontrado.";
    } elseif ($acao === "novo" || $acao === "alterar") {
        $ident = $atual ? $atual["identificador"] : trim((string)($d["identificador"] ?? ""));
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $expr = trim((string)($d["expressao"] ?? ($atual["expressao"] ?? "")));
        $condicao = trim((string)($d["condicao"] ?? ($atual["condicao"] ?? "")));
        $ante = numero_br($d["antecedencia_dias"] ?? ($atual["antecedencia_dias"] ?? "0"));
        $texto = trim((string)($d["texto"] ?? ($atual["texto"] ?? "")));
        $resolve = trim((string)($d["resolve"] ?? ($atual["resolve"] ?? "")));
        $ativo = array_key_exists("ativo", $d) ? ((string)$d["ativo"] === "1" ? 1 : 0) : ($atual ? (int)$atual["ativo"] : 1);
        $no = array_key_exists("no_id", $d) ? no_do_pedido($d["no_id"]) : ($atual ? ($atual["no_id"] === null ? null : (int)$atual["no_id"]) : null);
        $escala = (string)($d["escala"] ?? ($atual["escala"] ?? "nao"));
        $agenda = (string)($d["agenda"] ?? ($atual["agenda"] ?? "janela"));
        $simula = [];
        foreach (["simula_valor", "simula_horas"] as $k) {
            $bruto = array_key_exists($k, $d) ? trim((string)$d[$k]) : ($atual[$k] ?? "");
            $simula[$k] = $bruto === null || $bruto === "" ? null : numero_br($bruto);
            if ($bruto !== null && $bruto !== "" && ($simula[$k] === null || $simula[$k] < 0 || ($k === "simula_horas" && $simula[$k] > 48))) {
                $erros[] = $k === "simula_valor" ? "Valor simulado na escala: um número (vazio: nenhum)." : "Horas simuladas na escala: de 0 a 48 (vazio: nenhuma).";
            }
        }
        if (!in_array($escala, ["nao", "uso", "sempre"], true)) {
            $erros[] = "Na escala: nao, uso ou sempre.";
        }
        if (!in_array($agenda, ["janela", "sempre"], true)) {
            $erros[] = "Na agenda: janela (só dentro da antecedência da agenda) ou sempre (qualquer data).";
        }
        if (!identificador_valido($ident)) {
            $erros[] = "Identificador: minúsculas, números e _, começando por letra, até 40.";
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = "Dê um nome ao aviso (até 120 caracteres).";
        }
        if ($texto === "" || strlen($texto) > 300) {
            $erros[] = "Escreva o texto do aviso (até 300 caracteres; pode usar {relogio}, {data}, {quando} e {limite}).";
        }
        if ($ante === null || $ante < 0 || $ante > 3650) {
            $erros[] = "Antecedência: de 0 a 3650 dias.";
        }
        if ($resolve !== "" && !isset(lancamento_tipos()[$resolve])) {
            $erros[] = "Resolve: tipo de lançamento desconhecido (" . $resolve . ").";
        }
        if ($no === false) {
            $erros[] = "O ponto da árvore não existe.";
        } elseif ((int)valor("SELECT COUNT(*) FROM aviso WHERE identificador = ? AND no_id <=> ? AND id <> ?", [$ident, $no, $id]) > 0) {
            $erros[] = "O aviso " . $ident . " já tem uma versão em " . no_caminho($no) . ": altere aquela.";
        }
        if ($expr === "") {
            $erros[] = "Escreva a fórmula da data prevista.";
        } else {
            foreach (formula_validar("", $expr) as $e) {
                $erros[] = "Data prevista: " . $e . ".";
            }
        }
        if ($condicao !== "") {
            foreach (formula_validar("", $condicao) as $e) {
                $erros[] = "Vale quando: " . $e . ".";
            }
        }
        if (count($erros) === 0) {
            $dados = [$nome, $expr, $condicao !== "" ? $condicao : null, $ante, $texto, $resolve !== "" ? (int)lancamento_tipos()[$resolve]["id"] : null, $ativo, $no, $escala,
                $simula["simula_valor"], $simula["simula_horas"], $agenda];
            if ($acao === "novo") {
                sql("INSERT INTO aviso (identificador, nome, expressao, condicao, antecedencia_dias, texto, resolve_tipo_id, ativo, no_id, escala, simula_valor, simula_horas, agenda)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", array_merge([$ident], $dados));
                $id = ultimo_id();
                $msg = "Aviso " . $nome . " gravado para " . no_caminho($no) . ".";
            } else {
                sql("UPDATE aviso SET nome = ?, expressao = ?, condicao = ?, antecedencia_dias = ?, texto = ?, resolve_tipo_id = ?, ativo = ?, no_id = ?, escala = ?,
                    simula_valor = ?, simula_horas = ?, agenda = ? WHERE id = ?", array_merge($dados, [$id]));
                $msg = "Aviso " . $nome . " (" . no_caminho($no) . ") salvo.";
            }
            avisos_todos(true);
        }
    } elseif ($acao === "excluir") {
        sql("DELETE FROM aviso WHERE id = ?", [$id]);
        // a última versão do aviso: o que ia por cada canal para ele sai (canal_aviso cita o aviso pelo identificador, que é
        // o nome dele nas mensagens e nas fórmulas, como uma fórmula cita outra)
        if ((int)valor("SELECT COUNT(*) FROM aviso WHERE identificador = ?", [$atual["identificador"]]) === 0) {
            sql("DELETE FROM canal_aviso WHERE tipo = ?", [$atual["identificador"]]);
            canal_aviso("tg", null, true);
        }
        avisos_todos(true);
        $msg = "A versão do aviso " . $atual["nome"] . " para " . no_caminho($atual["no_id"]) . " foi excluída.";
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// Critérios de escolha (página Critérios e API recurso=criterios), por lugar da árvore. Cada lugar — todos os relógios (""),
// um ponto da árvore ("g:<id>") ou um relógio ("r:<id>") — pode ter o seu conjunto: parâmetros com peso no conjunto (somam 100),
// cada um com subparâmetros (peso no parâmetro, somam 100), cada um com faixas. Incluir abre espaço na proporção dos outros
// do mesmo conjunto (ou do mesmo parâmetro); excluir divide o peso na proporção dos que ficam; editar à mão só grava se
// fechar 100. Ações: conjunto_criar (escopo, origem: herdado ou vazio), conjunto_excluir (escopo), param_novo (escopo,
// nome, peso), param_pesos (escopo, nome[id], peso[id]), param_excluir (id), sub_novo (parametro_id, nome, metrica, peso),
// sub_pesos (parametro_id, nome[id], peso[id]), sub_excluir (id), sub_medida (id, metrica), sub_mover (id, parametro_id),
// ordem (tipo: parametro ou sub, id, direcao), faixas (sub_id, de[], ate[], categoria[], nota[], apagar[]), restaurar.
// Devolve: ok, mensagem, erros, escopo (o lugar a mostrar), ancora (a seção) e recusado (o formulário recusado).
function op_criterios($acao, $d)
{
    // as medidas: as variáveis cadastradas (campos e fórmulas)
    $METRICAS = variaveis_disponiveis();
    $erros = [];
    $recusado = "";
    $ok = "";
    $ancora = "";
    // o lugar vem como "", "g:<id>" ou "r:<id>"; false se o ponto da árvore ou o relógio não existe
    $escopo_de = function ($v) {
        $res = false;
        $v = (string)$v;
        if ($v === "") {
            $res = "";
        } elseif (preg_match("/^g:([0-9]+)\$/", $v, $m) === 1 && valor("SELECT id FROM no WHERE id = ?", [(int)$m[1]]) !== null) {
            $res = "g:" . (int)$m[1];
        } elseif (preg_match("/^r:([0-9]+)\$/", $v, $m) === 1 && valor("SELECT id FROM relogio WHERE id = ?", [(int)$m[1]]) !== null) {
            $res = "r:" . (int)$m[1];
        }
        return $res;
    };
    // as colunas do lugar, para gravar e para filtrar
    $colunas = function ($chave) {
        return [strpos($chave, "g:") === 0 ? (int)substr($chave, 2) : null, strpos($chave, "r:") === 0 ? (int)substr($chave, 2) : null];
    };
    $do_lugar = "escopo_no_id <=> ? AND escopo_relogio_id <=> ?";
    // redistribui e grava os pesos de uma tabela na proporção de cada um (o arredondamento que sobra vai para o maior);
    // devolve a conta ("nome 25% → 20%") para a mensagem
    $regrava = function ($tabela, $antes, $alvo) {
        $conta = [];
        $novos = [];
        $soma = array_sum($antes);
        foreach ($antes as $oid => $p) {
            $novos[$oid] = round($soma > 0 ? $p * $alvo / $soma : $alvo / max(1, count($antes)), 2);
        }
        if (count($novos) > 0) {
            $maior = array_search(max($novos), $novos);
            $novos[$maior] = round($novos[$maior] + ($alvo - array_sum($novos)), 2);
        }
        foreach ($novos as $oid => $np) {
            sql("UPDATE " . $tabela . " SET peso = ? WHERE id = ?", [$np, $oid]);
            $conta[] = valor("SELECT nome FROM " . $tabela . " WHERE id = ?", [$oid]) . " " . pct_br($antes[$oid]) . " → " . pct_br($np);
        }
        return $conta;
    };
    $escopo = $escopo_de($d["escopo"] ?? "");
    if ($escopo === false) {
        $erros[] = "Lugar desconhecido: use \"\" (todos), g:<id> de um ponto da árvore ou r:<id> de um relógio.";
        $escopo = "";
    }
    $col = $colunas($escopo);

    // ---------- o conjunto de um lugar ----------
    if ($acao === "conjunto_criar" && count($erros) === 0) {
        $ja_tem = (int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE " . $do_lugar, $col);
        $origem = conjunto_herdado($escopo);
        if ($ja_tem > 0) {
            $erros[] = escopo_texto($escopo) . " já tem critérios próprios.";
        } elseif (($d["origem"] ?? "herdado") === "herdado" && $origem !== null) {
            // copia o conjunto herdado inteiro: parâmetros, subparâmetros e faixas
            foreach (criterios_config()[$origem] as $p) {
                sql("INSERT INTO criterio_parametro (escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (?, ?, ?, ?, ?)", [$col[0], $col[1], $p["nome"], $p["peso"], $p["ordem"]]);
                $np = ultimo_id();
                foreach ($p["subs"] as $sb) {
                    sql("INSERT INTO criterio_sub (parametro_id, nome, variavel, peso, ordem) VALUES (?, ?, ?, ?, ?)", [$np, $sb["nome"], $sb["variavel"], $sb["peso"], $sb["ordem"]]);
                    $ns = ultimo_id();
                    foreach ($sb["faixas"] as $f) {
                        sql("INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) VALUES (?, ?, ?, ?, ?)", [$ns, $f["de"], $f["ate"], $f["categoria"], $f["nota"]]);
                    }
                }
            }
            $ok = escopo_texto($escopo) . " agora tem critérios próprios, copiados dos de " . escopo_texto($origem) . ". Ajuste à vontade: os de " . escopo_texto($origem) . " não mudam.";
        } else {
            sql("INSERT INTO criterio_parametro (escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (?, ?, 'Novo parâmetro', 100, 1)", $col);
            $ok = escopo_texto($escopo) . " agora tem critérios próprios, começando com um parâmetro de 100%: renomeie e inclua os subparâmetros dele.";
        }
        $ancora = "conjunto";
    }
    if ($acao === "conjunto_excluir" && count($erros) === 0) {
        $n = (int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE " . $do_lugar, $col);
        if ($n === 0) {
            $erros[] = escopo_texto($escopo) . " não tem critérios próprios.";
        } else {
            sql("DELETE FROM criterio_parametro WHERE " . $do_lugar, $col);
            $herda = conjunto_herdado($escopo);
            $ok = "Os critérios próprios de " . escopo_texto($escopo) . " foram excluídos (" . $n . ($n === 1 ? " parâmetro)." : " parâmetros).")
                . ($escopo === "" ? " Relógio sem conjunto mais perto fica com nota neutra, 50." : " Agora usa os de " . ($herda !== null ? escopo_texto($herda) : "nenhum lugar (nota neutra, 50)") . ".");
            $ancora = "conjunto";
        }
    }

    // ---------- parâmetros ----------
    if ($acao === "param_novo" && count($erros) === 0) {
        $nome = trim((string)($d["nome"] ?? ""));
        $peso = numero_br($d["peso"] ?? "");
        $outros = [];
        foreach (linhas("SELECT id, peso FROM criterio_parametro WHERE " . $do_lugar, $col) as $q) {
            $outros[(int)$q["id"]] = (float)$q["peso"];
        }
        if ($nome === "" || strlen($nome) > 80) {
            $erros[] = "Dê um nome ao parâmetro (até 80 caracteres).";
        }
        if ($peso === null || $peso <= 0 || $peso > 100 || (count($outros) > 0 && $peso >= 100)) {
            $erros[] = count($outros) > 0 ? "O peso do parâmetro novo vai de 0,01% a 99,99%: os outros do conjunto precisam de algum espaço." : "O peso vai de 0,01% a 100%.";
        }
        if (count($erros) === 0) {
            $conta = count($outros) > 0 ? $regrava("criterio_parametro", $outros, round(100 - $peso, 2)) : [];
            sql("INSERT INTO criterio_parametro (escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (?, ?, ?, ?, ?)",
                [$col[0], $col[1], $nome, count($outros) > 0 ? $peso : 100, (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM criterio_parametro WHERE " . $do_lugar, $col)]);
            // o id logo depois do INSERT (a consulta do nome do lugar, abaixo, zeraria o insert_id)
            $ancora = "p" . ultimo_id();
            $ok = "Parâmetro " . $nome . " incluído em " . escopo_texto($escopo) . " com " . pct_br(count($outros) > 0 ? $peso : 100) . "."
                . (count($conta) > 0 ? " Os outros abriram espaço na proporção de cada um: " . implode("; ", $conta) . "." : "")
                . " Inclua os subparâmetros dele: sem nenhum, ele não entra na conta.";
        }
    }
    if ($acao === "param_pesos" && count($erros) === 0) {
        $soma = 0;
        $validos = [];
        foreach ((array)($d["peso"] ?? []) as $id => $v) {
            $p = numero_br($v);
            $nome = trim((string)($d["nome"][$id] ?? ""));
            $do_conjunto = (int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE id = ? AND " . $do_lugar, [(int)$id, $col[0], $col[1]]) === 1;
            if (!$do_conjunto) {
                $erros[] = "O parâmetro " . (int)$id . " não é de " . escopo_texto($escopo) . ".";
            }
            if ($p === null || $p < 0 || $p > 100) {
                $erros[] = "Peso inválido em " . ($nome !== "" ? $nome : "um parâmetro") . ": use um número de 0 a 100.";
            }
            if ($nome === "" || strlen($nome) > 80) {
                $erros[] = "Todo parâmetro precisa de nome (até 80 caracteres).";
            }
            $soma += (float)$p;
            $validos[(int)$id] = [$nome, $p];
        }
        if (count($erros) === 0 && count($validos) !== (int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE " . $do_lugar, $col)) {
            $erros[] = "Mande o peso de todos os parâmetros de " . escopo_texto($escopo) . " de uma vez: a soma é do conjunto inteiro.";
        }
        if (count($erros) === 0 && abs($soma - 100) > 0.005) {
            $erros[] = "A soma dos parâmetros de " . escopo_texto($escopo) . " dá " . pct_br($soma) . ($soma > 100 ? ": passa " . pct_br($soma - 100) : ": faltam " . pct_br(100 - $soma)) . ". Ela precisa dar exatamente 100%.";
        }
        if (count($erros) === 0) {
            foreach ($validos as $id => $v) {
                sql("UPDATE criterio_parametro SET nome = ?, peso = ? WHERE id = ?", [$v[0], $v[1], $id]);
            }
            $ok = "Parâmetros de " . escopo_texto($escopo) . " salvos. Soma: 100%.";
            $ancora = "conjunto";
        } else {
            $recusado = "param_pesos";
        }
    }
    if ($acao === "param_excluir") {
        $p = linha("SELECT * FROM criterio_parametro WHERE id = ?", [(int)($d["id"] ?? 0)]);
        if (!$p) {
            $erros[] = "Parâmetro não encontrado.";
        } else {
            $escopo = escopo_chave($p);
            $col = $colunas($escopo);
            $outros = [];
            foreach (linhas("SELECT id, peso FROM criterio_parametro WHERE id <> ? AND " . $do_lugar, [$p["id"], $col[0], $col[1]]) as $q) {
                $outros[(int)$q["id"]] = (float)$q["peso"];
            }
            $conta = $regrava("criterio_parametro", $outros, 100);
            sql("DELETE FROM criterio_parametro WHERE id = ?", [$p["id"]]);
            $erros = [];
            $ok = "Parâmetro " . $p["nome"] . " excluído de " . escopo_texto($escopo) . "."
                . (count($conta) > 0 ? " O peso dele foi dividido na proporção de cada um: " . implode("; ", $conta) . "." : " O lugar ficou sem parâmetros e volta a herdar os critérios de cima.");
            $ancora = "conjunto";
        }
    }

    // ---------- subparâmetros ----------
    if ($acao === "sub_novo") {
        $pid = (int)($d["parametro_id"] ?? 0);
        $p = linha("SELECT * FROM criterio_parametro WHERE id = ?", [$pid]);
        $nome = trim((string)($d["nome"] ?? ""));
        $metrica = (string)($d["variavel"] ?? ($d["metrica"] ?? ""));
        $peso = numero_br($d["peso"] ?? "");
        $outros = [];
        foreach (linhas("SELECT id, peso FROM criterio_sub WHERE parametro_id = ?", [$pid]) as $q) {
            $outros[(int)$q["id"]] = (float)$q["peso"];
        }
        $erros = [];
        if (!$p) {
            $erros[] = "Parâmetro não encontrado.";
        }
        if ($nome === "" || strlen($nome) > 80) {
            $erros[] = "Dê um nome ao subparâmetro (até 80 caracteres).";
        }
        if (!isset($METRICAS[$metrica])) {
            $erros[] = "Escolha o que o subparâmetro mede.";
        }
        if ($peso === null || $peso <= 0 || $peso > 100 || (count($outros) > 0 && $peso >= 100)) {
            $erros[] = count($outros) > 0 ? "O peso do subparâmetro novo vai de 0,01% a 99,99%: os outros do parâmetro precisam de algum espaço." : "O peso vai de 0,01% a 100%.";
        }
        if (count($erros) === 0) {
            $conta = count($outros) > 0 ? $regrava("criterio_sub", $outros, round(100 - $peso, 2)) : [];
            sql("INSERT INTO criterio_sub (parametro_id, nome, variavel, peso, ordem) VALUES (?, ?, ?, ?, ?)",
                [$pid, $nome, $metrica, count($outros) > 0 ? $peso : 100, (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM criterio_sub WHERE parametro_id = ?", [$pid])]);
            $sid = ultimo_id();
            // faixas iniciais, válidas, para ajustar: uma faixa cobrindo tudo, ou uma por categoria, todas com nota 50
            if ($METRICAS[$metrica]["tipo"] === "categoria") {
                foreach ($METRICAS[$metrica]["valores"] as $cat) {
                    sql("INSERT INTO criterio_faixa (sub_id, categoria, nota) VALUES (?, ?, 50)", [$sid, $cat]);
                }
            } else {
                sql("INSERT INTO criterio_faixa (sub_id, de, ate, nota) VALUES (?, 0, NULL, 50)", [$sid]);
            }
            $escopo = escopo_chave($p);
            $ok = "Subparâmetro " . $nome . " incluído em " . $p["nome"] . " com " . pct_br(count($outros) > 0 ? $peso : 100) . "."
                . (count($conta) > 0 ? " Os outros abriram espaço na proporção de cada um: " . implode("; ", $conta) . "." : "")
                . " Ele começou com nota 50 em tudo: ajuste as faixas.";
            $ancora = "s" . $sid;
        }
    }
    if ($acao === "sub_pesos") {
        $pid = (int)($d["parametro_id"] ?? 0);
        $p = linha("SELECT * FROM criterio_parametro WHERE id = ?", [$pid]);
        $erros = [];
        $soma = 0;
        $validos = [];
        foreach ((array)($d["peso"] ?? []) as $id => $v) {
            $pv = numero_br($v);
            $nome = trim((string)($d["nome"][$id] ?? ""));
            if ((int)valor("SELECT COUNT(*) FROM criterio_sub WHERE id = ? AND parametro_id = ?", [(int)$id, $pid]) !== 1) {
                $erros[] = "O subparâmetro " . (int)$id . " não é deste parâmetro.";
            }
            if ($pv === null || $pv < 0 || $pv > 100) {
                $erros[] = "Peso inválido em " . ($nome !== "" ? $nome : "um subparâmetro") . ": use um número de 0 a 100.";
            }
            if ($nome === "" || strlen($nome) > 80) {
                $erros[] = "Todo subparâmetro precisa de nome (até 80 caracteres).";
            }
            $soma += (float)$pv;
            $validos[(int)$id] = [$nome, $pv];
        }
        if (!$p) {
            $erros[] = "Parâmetro não encontrado.";
        } elseif (count($erros) === 0 && count($validos) !== (int)valor("SELECT COUNT(*) FROM criterio_sub WHERE parametro_id = ?", [$pid])) {
            $erros[] = "Mande o peso de todos os subparâmetros de " . $p["nome"] . " de uma vez: a soma é do parâmetro inteiro.";
        }
        if ($p && count($erros) === 0 && abs($soma - 100) > 0.005) {
            $erros[] = "A soma dos subparâmetros de " . $p["nome"] . " dá " . pct_br($soma) . ($soma > 100 ? ": passa " . pct_br($soma - 100) : ": faltam " . pct_br(100 - $soma)) . ". Ela precisa dar exatamente 100%.";
        }
        if (count($erros) === 0) {
            foreach ($validos as $id => $v) {
                sql("UPDATE criterio_sub SET nome = ?, peso = ? WHERE id = ?", [$v[0], $v[1], $id]);
            }
            $escopo = escopo_chave($p);
            $ok = "Subparâmetros de " . $p["nome"] . " salvos. Soma: 100%.";
            $ancora = "p" . $pid;
        } else {
            $recusado = "sub_pesos:" . $pid;
            if ($p) {
                $escopo = escopo_chave($p);
            }
        }
    }
    if ($acao === "sub_excluir") {
        $sub = linha("SELECT s.*, p.escopo_no_id, p.escopo_relogio_id FROM criterio_sub s JOIN criterio_parametro p ON p.id = s.parametro_id WHERE s.id = ?", [(int)($d["id"] ?? 0)]);
        $erros = [];
        if (!$sub) {
            $erros[] = "Subparâmetro não encontrado.";
        } else {
            $outros = [];
            foreach (linhas("SELECT id, peso FROM criterio_sub WHERE parametro_id = ? AND id <> ?", [$sub["parametro_id"], $sub["id"]]) as $q) {
                $outros[(int)$q["id"]] = (float)$q["peso"];
            }
            $conta = $regrava("criterio_sub", $outros, 100);
            sql("DELETE FROM criterio_sub WHERE id = ?", [$sub["id"]]);
            $escopo = escopo_chave($sub);
            $ok = "Subparâmetro " . $sub["nome"] . " excluído." . (count($conta) > 0 ? " O peso dele foi dividido na proporção de cada um: " . implode("; ", $conta) . "." : " O parâmetro ficou sem subparâmetros e não entra na conta até ganhar um.");
            $ancora = "p" . $sub["parametro_id"];
        }
    }
    // trocar o que um subparâmetro mede: as faixas antigas não servem para a medida nova e recomeçam com nota 50
    if ($acao === "sub_medida") {
        $sub = linha("SELECT s.*, p.escopo_no_id, p.escopo_relogio_id FROM criterio_sub s JOIN criterio_parametro p ON p.id = s.parametro_id WHERE s.id = ?", [(int)($d["id"] ?? 0)]);
        $metrica = (string)($d["variavel"] ?? ($d["metrica"] ?? ""));
        $erros = [];
        if (!$sub || !isset($METRICAS[$metrica])) {
            $erros[] = "Escolha o subparâmetro e a medida nova.";
        } elseif ($metrica === $sub["variavel"]) {
            $erros[] = $sub["nome"] . " já mede isso: nada mudou.";
        } else {
            sql("UPDATE criterio_sub SET variavel = ? WHERE id = ?", [$metrica, $sub["id"]]);
            sql("DELETE FROM criterio_faixa WHERE sub_id = ?", [$sub["id"]]);
            if ($METRICAS[$metrica]["tipo"] === "categoria") {
                foreach ($METRICAS[$metrica]["valores"] as $cat) {
                    sql("INSERT INTO criterio_faixa (sub_id, categoria, nota) VALUES (?, ?, 50)", [$sub["id"], $cat]);
                }
            } else {
                sql("INSERT INTO criterio_faixa (sub_id, de, ate, nota) VALUES (?, 0, NULL, 50)", [$sub["id"]]);
            }
            $escopo = escopo_chave($sub);
            $ok = $sub["nome"] . " agora mede: " . $METRICAS[$metrica]["nome"] . ". As faixas recomeçaram com nota 50 em tudo: ajuste-as.";
            $ancora = "s" . $sub["id"];
        }
    }
    // mover um subparâmetro para outro parâmetro (de qualquer lugar): no de origem, o peso dele se divide entre os que
    // ficam; no de destino, ele entra com o mesmo peso e os outros abrem espaço. As faixas vão junto.
    if ($acao === "sub_mover") {
        $sub = linha("SELECT * FROM criterio_sub WHERE id = ?", [(int)($d["id"] ?? 0)]);
        $destino = linha("SELECT * FROM criterio_parametro WHERE id = ?", [(int)($d["parametro_id"] ?? 0)]);
        $erros = [];
        if (!$sub || !$destino) {
            $erros[] = "Escolha o subparâmetro e o parâmetro de destino.";
        } elseif ((int)$destino["id"] === (int)$sub["parametro_id"]) {
            $erros[] = $sub["nome"] . " já está em " . $destino["nome"] . ": nada mudou.";
        } else {
            $origem = [];
            foreach (linhas("SELECT id, peso FROM criterio_sub WHERE parametro_id = ? AND id <> ?", [$sub["parametro_id"], $sub["id"]]) as $q) {
                $origem[(int)$q["id"]] = (float)$q["peso"];
            }
            $conta = $regrava("criterio_sub", $origem, 100);
            $no_destino = [];
            foreach (linhas("SELECT id, peso FROM criterio_sub WHERE parametro_id = ?", [$destino["id"]]) as $q) {
                $no_destino[(int)$q["id"]] = (float)$q["peso"];
            }
            $peso = count($no_destino) > 0 ? min(99.99, (float)$sub["peso"]) : 100;
            $conta = array_merge($conta, count($no_destino) > 0 ? $regrava("criterio_sub", $no_destino, round(100 - $peso, 2)) : []);
            sql("UPDATE criterio_sub SET parametro_id = ?, peso = ?, ordem = ? WHERE id = ?",
                [$destino["id"], $peso, (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM criterio_sub WHERE parametro_id = ?", [$destino["id"]]), $sub["id"]]);
            $escopo = escopo_chave($destino);
            $ok = $sub["nome"] . " foi para " . $destino["nome"] . " (" . escopo_texto($escopo) . ") com " . pct_br($peso) . "."
                . (count($conta) > 0 ? " Os dois parâmetros se reequilibraram na proporção: " . implode("; ", $conta) . "." : "");
            $ancora = "p" . $destino["id"];
        }
    }
    // ordem: sobe ou desce um parâmetro dentro do seu conjunto, ou um subparâmetro dentro do seu parâmetro
    if ($acao === "ordem") {
        $tabela = ($d["tipo"] ?? "") === "sub" ? "criterio_sub" : "criterio_parametro";
        $id = (int)($d["id"] ?? 0);
        $item = linha("SELECT * FROM " . $tabela . " WHERE id = ?", [$id]);
        $erros = [];
        if (!$item) {
            $erros[] = "Não encontrado.";
        } else {
            if ($tabela === "criterio_sub") {
                $irmaos = array_column(linhas("SELECT id FROM criterio_sub WHERE parametro_id = ? ORDER BY ordem, id", [$item["parametro_id"]]), "id");
                $escopo = escopo_chave(linha("SELECT * FROM criterio_parametro WHERE id = ?", [$item["parametro_id"]]));
            } else {
                $escopo = escopo_chave($item);
                $irmaos = array_column(linhas("SELECT id FROM criterio_parametro WHERE " . $do_lugar . " ORDER BY ordem, id", $colunas($escopo)), "id");
            }
            $irmaos = array_map("intval", $irmaos);
            $pos = array_search($id, $irmaos, true);
            $vizinho = ($d["direcao"] ?? "") === "sobe" ? $pos - 1 : $pos + 1;
            if ($vizinho >= 0 && $vizinho < count($irmaos)) {
                $irmaos[$pos] = $irmaos[$vizinho];
                $irmaos[$vizinho] = $id;
            }
            foreach ($irmaos as $n => $iid) {
                sql("UPDATE " . $tabela . " SET ordem = ? WHERE id = ?", [$n + 1, $iid]);
            }
            $ok = "Ordem alterada.";
            $ancora = $tabela === "criterio_sub" ? "p" . $item["parametro_id"] : "conjunto";
        }
    }

    if ($acao === "faixas") {
        $erros = [];
        $sid = (int)($d["sub_id"] ?? 0);
        $sub = linha("SELECT * FROM criterio_sub WHERE id = ?", [$sid]);
        if ($sub && isset($METRICAS[$sub["variavel"]])) {
            $m = $METRICAS[$sub["variavel"]];
            $lista = [];
            $apagar = (array)($d["apagar"] ?? []);
            foreach ((array)($d["nota"] ?? []) as $i => $nota_txt) {
                $de_txt = trim($d["de"][$i] ?? "");
                $ate_txt = trim($d["ate"][$i] ?? "");
                $cat = trim($d["categoria"][$i] ?? "");
                $vazia = trim($nota_txt) === "" && $de_txt === "" && $ate_txt === "" && $cat === "";
                if (!$vazia && !isset($apagar[$i])) {
                    $lista[] = ["de" => $de_txt, "ate" => $ate_txt, "categoria" => $cat, "nota" => trim($nota_txt), "linha" => count($lista) + 1];
                }
            }
            if (count($lista) === 0) {
                $erros[] = "Precisa de pelo menos uma faixa.";
            }
            foreach ($lista as $f) {
                $n = numero_br($f["nota"]);
                if ($n === null || $n < 0 || $n > 100) {
                    $erros[] = "Faixa " . $f["linha"] . ": a nota vai de 0 a 100.";
                }
            }
            if ($m["tipo"] === "categoria") {
                $vistas = [];
                foreach ($lista as $f) {
                    if (!in_array($f["categoria"], $m["valores"], true)) {
                        $erros[] = "Faixa " . $f["linha"] . ": escolha uma categoria da lista.";
                    } elseif (isset($vistas[$f["categoria"]])) {
                        $erros[] = "A categoria " . $f["categoria"] . " aparece mais de uma vez.";
                    }
                    $vistas[$f["categoria"]] = true;
                }
            } else {
                // por intervalo: começa em 0, cada faixa começa onde a anterior terminou, só a última pode ficar sem limite,
                // e a última vai até o maior valor possível (ou sem limite): todo valor cai em exatamente uma faixa
                foreach ($lista as $f) {
                    if (numero_br($f["de"]) === null || numero_br($f["de"]) < 0) {
                        $erros[] = "Faixa " . $f["linha"] . ": o \"de\" precisa ser um número a partir de 0.";
                    }
                    if ($f["ate"] !== "" && (numero_br($f["ate"]) === null || (numero_br($f["de"]) !== null && numero_br($f["ate"]) <= numero_br($f["de"])))) {
                        $erros[] = "Faixa " . $f["linha"] . ": o \"até\" precisa ser maior que o \"de\" (ou vazio, sem limite).";
                    }
                }
                if (count($erros) === 0) {
                    usort($lista, function ($a, $b) {
                        return numero_br($a["de"]) <=> numero_br($b["de"]);
                    });
                    if (numero_br($lista[0]["de"]) != 0) {
                        $erros[] = "A primeira faixa precisa começar em 0 (hoje começa em " . $lista[0]["de"] . "): senão os valores abaixo ficam sem nota.";
                    }
                    for ($i = 1; $i < count($lista); $i++) {
                        $anterior = $lista[$i - 1]["ate"];
                        if ($anterior === "") {
                            $erros[] = "A faixa que começa em " . $lista[$i - 1]["de"] . " está sem limite, mas há outra depois dela: só a última pode ficar sem limite.";
                        } elseif (numero_br($anterior) < numero_br($lista[$i]["de"])) {
                            $erros[] = "Buraco entre " . $anterior . " e " . $lista[$i]["de"] . ": os valores nesse intervalo ficam sem nota.";
                        } elseif (numero_br($anterior) > numero_br($lista[$i]["de"])) {
                            $erros[] = "Sobreposição: uma faixa vai até " . $anterior . " e a seguinte começa em " . $lista[$i]["de"] . ".";
                        }
                    }
                    $ultima = $lista[count($lista) - 1]["ate"];
                    if ($ultima !== "" && ($m["max"] === null || numero_br($ultima) < $m["max"])) {
                        $erros[] = "A última faixa vai até " . $ultima . ($m["max"] === null ? ", e esta medida não tem limite: deixe o \"até\" dela vazio." : ", mas a medida vai até " . $m["max"] . ".");
                    }
                }
            }
            if (count($erros) === 0) {
                sql("DELETE FROM criterio_faixa WHERE sub_id = ?", [$sid]);
                foreach ($lista as $f) {
                    if ($m["tipo"] === "categoria") {
                        sql("INSERT INTO criterio_faixa (sub_id, categoria, nota) VALUES (?, ?, ?)", [$sid, $f["categoria"], numero_br($f["nota"])]);
                    } else {
                        sql("INSERT INTO criterio_faixa (sub_id, de, ate, nota) VALUES (?, ?, ?, ?)", [$sid, numero_br($f["de"]), $f["ate"] === "" ? null : numero_br($f["ate"]), numero_br($f["nota"])]);
                    }
                }
                $ok = "Faixas de " . $sub["nome"] . " salvas.";
                $escopo = escopo_chave(linha("SELECT * FROM criterio_parametro WHERE id = ?", [$sub["parametro_id"]]));
                $ancora = "s" . $sid;
            } else {
                $recusado = "faixas:" . $sid;
                $escopo = escopo_chave(linha("SELECT * FROM criterio_parametro WHERE id = ?", [$sub["parametro_id"]]));
            }
        }
    }

    // restaurar: apaga todos os conjuntos e volta aos iniciais (o final do schema.sql)
    if ($acao === "restaurar") {
        $erros = [];
        $semente = file_get_contents(__DIR__ . "/schema.sql");
        $ini = strpos((string)$semente, "-- critérios iniciais");
        if ($ini === false) {
            $erros[] = "Não encontrei os critérios iniciais no arquivo schema.sql.";
        } else {
            sql("DELETE FROM criterio_parametro");
            // os comandos até o fim do arquivo, traduzidos para o banco em uso (e os ids andam depois dos gravados)
            banco_script(substr((string)$semente, $ini));
            $ok = "Critérios iniciais restaurados.";
            $escopo = "";
            $ancora = "lugares";
        }
    }

    if (count($erros) === 0 && $ok === "") {
        $erros[] = "Ação desconhecida ou item não encontrado.";
    }
    return ["ok" => count($erros) === 0, "mensagem" => $ok, "erros" => $erros, "escopo" => $escopo, "ancora" => $ancora, "recusado" => $recusado];
}

// ---------------------------------------------------------------------------------------------------------------------
// O rodízio. Ações: modo (o quadro Modo de rodízio da página Hoje: modo (o id do modo), bloco_alvo[<bloco>] (o grupo de
// onde sortear, 0: todos; ou r:<id>, um relógio fixo), bloco_cada_dia[<bloco>] (presente: um relógio por dia; ausente:
// um para o bloco; só nos blocos que vierem e fora da escala), selecao, escala_dias, max_sem_uso: grava o modo e ativa;
// se o dia ainda não começou no pulso, o plano novo vale já de hoje), resortear (refaz o plano de amanhã até domingo; se o dia ainda não começou no pulso, também hoje),
// resortear_hoje (inclusive hoje: o sorteado passa a ser o do pulso a partir de agora), usando (relogio_id: este passa a
// ser o relógio de hoje, a partir de agora), trocar_dia (data, relogio_id: o relógio daquele dia, à mão; hoje, como o
// usando; 0: o dia volta a ser sorteado), proxima_semana (só no domingo: monta ou refaz a semana seguinte inteira).
// Na escala inteligente: sortear de novo refaz a escala (do mesmo jeito: de amanhã, ou inclusive hoje); usando segura o
// escolhido nos dias que faltavam do bloco de hoje e refaz a escala depois deles; a próxima semana não existe (a escala
// segue o próprio período).
// ---------------------------------------------------------------------------------------------------------------------
function op_rodizio($acao, $d)
{
    $erros = [];
    $msg = "";
    $agora = time();
    $hoje = new DateTimeImmutable("today");
    $ini_uso = strtotime($hoje->format("Y-m-d") . " " . cfg("uso_inicio"));
    $fim_uso = strtotime($hoje->format("Y-m-d") . " " . cfg("uso_fim"));
    $comecou = dia_comecou($agora);
    // troca o relógio de hoje a partir de agora: a sessão de rodízio do anterior termina agora (ou sai, se nem começou)
    // e a do novo começa agora (se ainda está no horário de uso)
    $troca_hoje = function ($novo_id, $motivo) use ($hoje, $agora, $ini_uso, $fim_uso) {
        $tipo = lancamento_tipos()["pulso"] ?? null;
        $dia = $hoje->format("Y-m-d");
        sql("REPLACE INTO plano (data, relogio_id, bloco_id, origem, motivo, criado) VALUES (?, ?, NULL, 'manual', ?, NOW())", [$dia, $novo_id, $motivo]);
        if ($tipo) {
            foreach (linhas("SELECT id, relogio_id, inicio FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ? AND relogio_id <> ?", [(int)$tipo["id"], $dia, $novo_id]) as $l) {
                if (strtotime($l["inicio"]) >= $agora) {
                    sql("DELETE FROM lancamento WHERE id = ?", [(int)$l["id"]]);
                } else {
                    // fecha agora a que ainda está aberta (sem fim, pelas marcações, ou com o fim ainda por vir)
                    sql("UPDATE lancamento SET fim = ? WHERE id = ? AND (fim IS NULL OR fim > ?)", [date("Y-m-d H:i:s", $agora), (int)$l["id"], date("Y-m-d H:i:s", $agora)]);
                }
            }
            // sem pôr no pulso sozinho, o novo só entra no pulso pelo Pôs
            if (pulso_poe_sozinho() && $agora < $fim_uso && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ? AND relogio_id = ?", [(int)$tipo["id"], $dia, $novo_id]) === null) {
                sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, 'rodizio', NOW())",
                    [$novo_id, (int)$tipo["id"], date("Y-m-d H:i:s", max($agora, $ini_uso)), pulso_tira_sozinho() ? date("Y-m-d H:i:s", $fim_uso) : null]);
            }
        }
    };
    $escala = valor("SELECT escala_dias FROM modo WHERE id = ?", [modo_ativo()]) !== null;
    if ($acao === "modo") {
        $id_modo = (int)($d["modo"] ?? 0);
        $m = linha("SELECT * FROM modo WHERE id = ?", [$id_modo]);
        if (!$m) {
            $erros[] = "Modo não encontrado.";
        } else {
            // os blocos do modo, com o que veio do quadro (grupo ou relógio fixo, e um por dia)
            $blocos = [];
            foreach (modo_blocos($id_modo) as $b) {
                $veio = isset($d["bloco_alvo"][$b["id"]]);
                $alvo = (string)($d["bloco_alvo"][$b["id"]] ?? ($b["relogio_id"] !== null ? "r:" . $b["relogio_id"] : (string)(int)$b["no_id"]));
                $blocos[] = ["nome" => $b["nome"], "dias" => explode(",", $b["dias"]), "no_id" => strpos($alvo, "r:") === 0 ? ($b["no_id"] ?? "0") : $alvo,
                    "relogio_id" => strpos($alvo, "r:") === 0 ? (int)substr($alvo, 2) : 0,
                    "um_por" => $m["escala_dias"] === null && $veio ? (isset($d["bloco_cada_dia"][$b["id"]]) ? "dia" : "bloco") : $b["um_por"]];
            }
            $dm = ["id" => $id_modo, "blocos" => $blocos];
            if (isset($d["selecao"]) && $m["escala_dias"] === null) {
                $dm["selecao"] = $d["selecao"];
            }
            if ($m["escala_dias"] !== null && isset($d["escala_dias"])) {
                $dm["escala_dias"] = is_array($d["escala_dias"]) ? ($d["escala_dias"][$id_modo] ?? $m["escala_dias"]) : $d["escala_dias"];
            }
            $res = op_modos("salvar", $dm);
            if ($res["ok"] && isset($d["max_sem_uso"])) {
                $res = op_config("salvar", ["max_sem_uso" => $d["max_sem_uso"]]);
            }
            if ($res["ok"]) {
                $res = op_modos("ativar", ["id" => $id_modo]);
                // o dia ainda não começou no pulso: o plano novo vale já de hoje
                if ($res["ok"] && !$comecou) {
                    $res = op_rodizio("resortear", []);
                }
            }
            $erros = $res["erros"];
            $msg = $res["ok"] ? "Modo aplicado: " . $m["nome"] . ". " . $res["mensagem"] : "";
        }
    } elseif ($acao === "resortear" || $acao === "resortear_hoje") {
        $antes = plano_do_dia($hoje->format("Y-m-d"));
        $desde = ($acao === "resortear_hoje" || !$comecou) ? $hoje : $hoje->modify("+1 day");
        sql("DELETE FROM plano WHERE data >= ?", [$desde->format("Y-m-d")]);
        if ($escala) {
            gerar_escala($desde);
        }
        garantir_plano($hoje, null, $acao !== "resortear_hoje");
        $depois = plano_do_dia($hoje->format("Y-m-d"));
        if ($depois && (!$antes || (int)$antes["relogio_id"] !== (int)$depois["relogio_id"]) && ($acao === "resortear_hoje" || !$comecou)) {
            // o de hoje trocado pelo sorteio: o motivo é o do sorteio
            $troca_hoje((int)$depois["relogio_id"], $depois["motivo"] ?? null);
        }
        $msg = "Sorteado de novo" . ($desde > $hoje ? " a partir de amanhã; hoje continua o " . ($antes["nome"] ?? "mesmo") : "") . "." . ($depois ? " Hoje: " . $depois["nome"] . "." : "");
    } elseif ($acao === "usando") {
        $r = linha("SELECT * FROM relogio WHERE id = ?", [(int)($d["relogio_id"] ?? 0)]);
        if (!$r) {
            $erros[] = "Relógio não encontrado.";
        } else {
            $antes = plano_do_dia($hoje->format("Y-m-d"));
            $troca_hoje((int)$r["id"], "Escolhido à mão: você marcou que está usando este hoje.");
            $msg = "Hoje: " . $r["nome"] . ", a partir de agora.";
            if ($escala) {
                // os dias seguidos que o relógio de antes ainda teria ficam com o escolhido; a escala é refeita depois deles
                $dia = $hoje->modify("+1 day");
                $p = plano_do_dia($dia->format("Y-m-d"));
                for ($n = 0; $antes && $p && (int)$p["relogio_id"] === (int)$antes["relogio_id"] && $n < 366; $n++) {
                    sql("UPDATE plano SET relogio_id = ?, origem = 'manual', acao = NULL, motivo = ? WHERE data = ?",
                        [(int)$r["id"], "Escolhido à mão: segue o relógio que você pôs no pulso.", $dia->format("Y-m-d")]);
                    $dia = $dia->modify("+1 day");
                    $p = plano_do_dia($dia->format("Y-m-d"));
                }
                gerar_escala($dia);
                $msg .= " Fica até " . $dia->modify("-1 day")->format("d/m") . "; a escala foi refeita a partir de " . $dia->format("d/m") . ".";
            }
        }
    } elseif ($acao === "trocar_dia") {
        // o relógio de um dia do plano, à mão: hoje, como o "usando"; um dia que vem, só aquele dia (na escala, refeita a partir
        // dele, com o dia fixo); relogio_id 0 desfaz a escolha à mão e o dia volta a ser sorteado
        $data = (string)($d["data"] ?? "");
        $dt = DateTimeImmutable::createFromFormat("!Y-m-d", $data);
        $novo = (int)($d["relogio_id"] ?? 0);
        $r = $novo > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$novo]) : null;
        if (!$dt || $dt->format("Y-m-d") !== $data) {
            $erros[] = "Data: AAAA-MM-DD.";
        } elseif ($dt < $hoje) {
            $erros[] = "O dia " . $dt->format("d/m") . " já passou: o que foi usado nele se corrige nas marcações (o quadro \"Corrigir marcações\" no painel do relógio).";
        } elseif ($novo > 0 && !$r) {
            $erros[] = "Relógio não encontrado.";
        } elseif ($r && (int)$r["disponivel"] !== 1) {
            $erros[] = $r["nome"] . " está indisponível: marque como disponível no cadastro dele antes.";
        } elseif ($novo === 0 && $dt == $hoje) {
            $erros[] = "Para sortear hoje de novo, use \"Sortear de novo\" no quadro do modo de rodízio.";
        } elseif ($dt == $hoje) {
            return op_rodizio("usando", ["relogio_id" => $novo]);
        } elseif ($novo === 0) {
            if ($escala) {
                sql("UPDATE plano SET origem = 'sorteio' WHERE data = ?", [$data]);
                gerar_escala($dt);
            } else {
                sql("DELETE FROM plano WHERE data = ?", [$data]);
                garantir_plano($dt, $dt);
            }
            $p = plano_do_dia($data);
            $msg = $dt->format("d/m") . ": volta a ser sorteado" . ($p ? " (" . $p["nome"] . ")" : "") . ".";
        } else {
            sql("REPLACE INTO plano (data, relogio_id, bloco_id, origem, motivo, criado) VALUES (?, ?, NULL, 'manual', ?, NOW())", [$data, $novo, "Escolhido à mão."]);
            if ($escala) {
                // a escala refeita a partir do dia trocado: ele fica, e os seguintes (e o que fazer antes de cada um) se ajustam
                gerar_escala($dt);
            }
            $msg = $dt->format("d/m") . ": " . $r["nome"] . ", escolhido à mão." . ($escala ? " A escala foi refeita a partir desse dia." : "");
        }
    } elseif ($acao === "proxima_semana") {
        if ($escala) {
            $erros[] = "Na escala inteligente não há próxima semana para montar: a escala segue o próprio período (até " . date("d/m/Y", strtotime(cfg("escala_fim"))) . "). Sortear de novo refaz a escala.";
        } elseif ((int)$hoje->format("N") !== 7) {
            $erros[] = "A próxima semana só se monta no domingo. Nos outros dias, sortear de novo refaz até o domingo desta semana.";
        } else {
            $seg = $hoje->modify("+1 day");
            sql("DELETE FROM plano WHERE data BETWEEN ? AND ?", [$seg->format("Y-m-d"), $seg->modify("+6 days")->format("Y-m-d")]);
            garantir_plano($seg, $seg->modify("+6 days"));
            $msg = "Semana de " . $seg->format("d/m") . " a " . $seg->modify("+6 days")->format("d/m") . " montada.";
        }
    }
    return resultado($erros, $msg);
}

// ---------------------------------------------------------------------------------------------------------------------
// Modos de rodízio. Ações: salvar (id: 0 cria; nome, selecao: inteligente, ponderado, aleatorio ou fifo; escala_dias:
// vazio ou 0 = sorteio pelos blocos, de 7 a 730 = escala inteligente com esse horizonte; blocos: lista de {nome, dias:
// [1..7], no_id (0: todos), um_por: bloco ou dia, relogio_id (fixo; 0: sorteia)}: substitui os blocos; ciclo: 1 ou 0
// (padrão): com o ciclo, um relógio só volta depois que todos os disponíveis do bloco passaram), ativar (id: passa
// a valer; o plano de amanhã em diante é refeito), excluir (id: não o ativo). Na escala, a forma de escolha e o "um por"
// não contam: ela escolhe sempre pela maior nota, e o relógio fica os dias da fórmula dias_seguidos.
// ---------------------------------------------------------------------------------------------------------------------
function op_modos($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM modo WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = "Modo não encontrado.";
    } elseif ($acao === "salvar") {
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $sel = (string)($d["selecao"] ?? ($atual["selecao"] ?? "ponderado"));
        $ciclo = array_key_exists("ciclo", $d) ? ((string)$d["ciclo"] === "1" ? 1 : 0) : ($atual ? (int)$atual["ciclo"] : 0);
        $escala_dias = array_key_exists("escala_dias", $d) ? trim((string)$d["escala_dias"]) : ($atual["escala_dias"] ?? "");
        $escala_dias = $escala_dias === null || $escala_dias === "" || (string)$escala_dias === "0" ? null : $escala_dias;
        if ($escala_dias !== null && (!ctype_digit((string)$escala_dias) || (int)$escala_dias < 7 || (int)$escala_dias > 730)) {
            $erros[] = "Escala inteligente: de 7 a 730 dias (vazio: o modo sorteia pelos blocos).";
        }
        $blocos = $d["blocos"] ?? null;
        if (is_string($blocos)) {
            $blocos = json_decode($blocos, true);
        }
        if ($nome === "" || strlen($nome) > 80) {
            $erros[] = "Dê um nome ao modo (até 80 caracteres).";
        }
        if (!in_array($sel, ["inteligente", "ponderado", "aleatorio", "fifo"], true)) {
            $erros[] = "Forma de escolha: inteligente, ponderado, aleatorio ou fifo.";
        }
        $limpos = [];
        $dias_usados = [];
        if ($blocos !== null) {
            if (!is_array($blocos) || count($blocos) === 0) {
                $erros[] = "Um modo precisa de pelo menos um bloco de dias.";
            } else {
                foreach ($blocos as $k => $b) {
                    $dias = array_values(array_unique(array_map("intval", (array)($b["dias"] ?? []))));
                    sort($dias);
                    $no = no_do_pedido($b["no_id"] ?? "");
                    $fixo = (int)($b["relogio_id"] ?? 0);
                    if (count($dias) === 0 || min($dias) < 1 || max($dias) > 7) {
                        $erros[] = "Bloco " . ($k + 1) . ": escolha os dias (1 a 7).";
                    }
                    foreach ($dias as $x) {
                        if (isset($dias_usados[$x])) {
                            $erros[] = "O dia " . $x . " está em mais de um bloco.";
                        }
                        $dias_usados[$x] = true;
                    }
                    if ($no === false) {
                        $erros[] = "Bloco " . ($k + 1) . ": o ponto da árvore não existe.";
                    }
                    if ($fixo > 0 && valor("SELECT id FROM relogio WHERE id = ?", [$fixo]) === null) {
                        $erros[] = "Bloco " . ($k + 1) . ": o relógio fixo não existe.";
                    }
                    $limpos[] = [trim((string)($b["nome"] ?? "")) !== "" ? trim((string)$b["nome"]) : "Bloco " . ($k + 1), implode(",", $dias), $no, ($b["um_por"] ?? "dia") === "bloco" ? "bloco" : "dia", $fixo > 0 ? $fixo : null];
                }
            }
        } elseif (!$atual) {
            $erros[] = "Um modo novo precisa dos blocos de dias.";
        }
        if (count($erros) === 0) {
            $escala_dias = $escala_dias === null ? null : (int)$escala_dias;
            if ($atual) {
                sql("UPDATE modo SET nome = ?, selecao = ?, escala_dias = ?, ciclo = ? WHERE id = ?", [$nome, $sel, $escala_dias, $ciclo, $id]);
            } else {
                sql("INSERT INTO modo (nome, selecao, escala_dias, ciclo, ordem) VALUES (?, ?, ?, ?, ?)", [$nome, $sel, $escala_dias, $ciclo,
                    (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM modo")]);
                $id = ultimo_id();
            }
            if ($blocos !== null) {
                sql("DELETE FROM modo_bloco WHERE modo_id = ?", [$id]);
                foreach ($limpos as $k => $b) {
                    sql("INSERT INTO modo_bloco (modo_id, nome, no_id, um_por, relogio_id, ordem) VALUES (?, ?, ?, ?, ?, ?)", [$id, $b[0], $b[2], $b[3], $b[4], $k + 1]);
                    $bloco = ultimo_id();
                    foreach (explode(",", $b[1]) as $dia) {
                        sql("INSERT INTO modo_bloco_dia (bloco_id, dia) VALUES (?, ?)", [$bloco, (int)$dia]);
                    }
                }
            }
            if (modo_ativo() === $id) {
                sql("DELETE FROM plano WHERE data > ?", [date("Y-m-d")]);
                cfg_set("escala_fim", "");
                garantir_plano(new DateTimeImmutable("today"));
            }
            $msg = "Modo " . $nome . " salvo" . (count($dias_usados) > 0 && count($dias_usados) < 7 ? " (os dias sem bloco ficam sem relógio sorteado)" : "") . ".";
        }
    } elseif ($acao === "ativar" && $atual) {
        modo_ativar($id);
        sql("DELETE FROM plano WHERE data > ?", [date("Y-m-d")]);
        // a escala de antes (se havia) acaba aqui: a do modo novo começa um período novo
        cfg_set("escala_fim", "");
        garantir_plano(new DateTimeImmutable("today"));
        $msg = "Modo ativo: " . $atual["nome"] . ". O plano de amanhã em diante foi refeito"
            . ($atual["escala_dias"] !== null ? " (escala até " . date("d/m/Y", strtotime(cfg("escala_fim"))) . ")" : "") . ".";
    } elseif ($acao === "excluir" && $atual) {
        if (modo_ativo() === $id) {
            $erros[] = "Não dá para excluir o modo ativo: ative outro antes.";
        } else {
            sql("DELETE FROM modo WHERE id = ?", [$id]);
            $msg = "Modo " . $atual["nome"] . " excluído.";
        }
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Configuração (página Configuração e API recurso=config). Ações:
//   salvar: horario_manha, horario_noite, uso_inicio e uso_fim (HH:MM; o horário de uso só grava com os dois válidos e o fim
//           depois do início); sol_fim (a sessão no sol esquecida aberta fecha a essa hora: o "fecha às" do tipo sol);
//           sol_limiar (1 a 99: o solar vai para o sol quando a carga estimada chega a essa %); url_sistema (para a âncora
//           {link}); pulso_auto_inicio (1 ou 0: o relógio do dia entra no pulso sozinho no início do horário de uso; 0: só pelo
//           Pôs); pulso_auto_fim (1 ou 0: sai sozinho no fim do horário de uso, o "fecha às" do tipo No pulso; 0: só pelo
//           Tirou); alerta_ativo (1 ou 0: mandar as mensagens pelo Telegram); agenda_ativa (1 ou 0); agenda_id; agenda_chave
//           (o caminho da chave JSON; vazio: a google-conta-servico.json na pasta do sistema); agenda_antecedencia (1 a 365);
//           max_sem_uso (0 a 365); previsao_limite (0 a 100); medicao_janela_dias (1 a 3650: a média do gasto medido usa as
//           medições destes últimos dias); os canais: alerta_tipos[] e agenda_tipos[] (os avisos que vão
//           por cada um: dia, vespera, os identificadores dos avisos e ev<id>), tg_padrao e ag_padrao (a mensagem padrão),
//           tg_proprio_<tipo> e ag_proprio_<tipo> (1 ou 0: o aviso tem a sua personalizada no canal), tg_corpo_<tipo> e
//           ag_corpo_<tipo> (o texto dela). Só o que vier no pedido muda. Os canais ficam na tabela canal_aviso (os nomes
//           acima são os campos do pedido).
//   testar_manha, testar_noite: mandam a mensagem agora
//   evento_salvar (evento_id (ou id): 0 cria; nome, ativo, repeticao (uma, diaria, semanal, mensal, intervalo), hora (HH:MM),
//           data_inicio (uma vez: a data; a cada N dias: desde quando, vazio: hoje), dias_semana (1 a 7, lista ou separados
//           por vírgula), dia_mes (1 a 31), intervalo_dias, relogio_id (0: nenhum)); o evento novo já vai pelo Telegram, e
//           os canais e o texto de cada um se escolhem em "O que vai para onde". evento_excluir (evento_id ou id)
//   teste_agenda_criar (um evento de teste daqui a 10 minutos), teste_agenda_remover, sincronizar (a agenda, agora)
// ---------------------------------------------------------------------------------------------------------------------
function op_config($acao, $d)
{
    global $CANAIS, $REPETICOES;
    $erros = [];
    $msg = "";
    $extra = [];
    $hoje = new DateTimeImmutable("today");
    $hora_ok = "/^([01][0-9]|2[0-3]):[0-5][0-9]\$/";
    if ($acao === "salvar") {
        foreach (["horario_manha", "horario_noite", "sol_fim"] as $k) {
            if (array_key_exists($k, $d) && preg_match($hora_ok, (string)$d[$k]) !== 1) {
                $erros[] = $k . ": uma hora HH:MM.";
            }
        }
        if ((array_key_exists("uso_inicio", $d) || array_key_exists("uso_fim", $d)) && (preg_match($hora_ok, (string)($d["uso_inicio"] ?? cfg("uso_inicio"))) !== 1
            || preg_match($hora_ok, (string)($d["uso_fim"] ?? cfg("uso_fim"))) !== 1 || (string)($d["uso_fim"] ?? cfg("uso_fim")) <= (string)($d["uso_inicio"] ?? cfg("uso_inicio")))) {
            $erros[] = "Relógio no pulso: duas horas HH:MM, o fim depois do início.";
        }
        if (array_key_exists("carga_limiar", $d) && (!ctype_digit(trim((string)$d["carga_limiar"])) || (int)$d["carga_limiar"] < 1 || (int)$d["carga_limiar"] > 99)) {
            $erros[] = "Carregar: o limite é uma carga de 1 a 99%.";
        }
        if (array_key_exists("sol_limiar", $d) && (!ctype_digit(trim((string)$d["sol_limiar"])) || (int)$d["sol_limiar"] < 1 || (int)$d["sol_limiar"] > 99)) {
            $erros[] = "Solar: o limite é uma carga de 1 a 99%.";
        }
        if (array_key_exists("max_sem_uso", $d) && (!ctype_digit(trim((string)$d["max_sem_uso"])) || (int)$d["max_sem_uso"] > 365)) {
            $erros[] = "Garantia de rodízio: de 0 a 365 dias.";
        }
        if (array_key_exists("agenda_antecedencia", $d) && (!ctype_digit(trim((string)$d["agenda_antecedencia"])) || (int)$d["agenda_antecedencia"] < 1 || (int)$d["agenda_antecedencia"] > 365)) {
            $erros[] = "Agenda: de 1 a 365 dias de antecedência.";
        }
        if (array_key_exists("medicao_janela_dias", $d) && (!ctype_digit(trim((string)$d["medicao_janela_dias"])) || (int)$d["medicao_janela_dias"] < 1 || (int)$d["medicao_janela_dias"] > 3650)) {
            $erros[] = "Gasto medido: a média usa de 1 a 3650 dias de medições.";
        }
        if (array_key_exists("previsao_limite", $d) && (numero_br($d["previsao_limite"]) === null || numero_br($d["previsao_limite"]) < 0 || numero_br($d["previsao_limite"]) > 100)) {
            $erros[] = "Previsão: o limite é uma carga de 0 a 100%.";
        }
        if (array_key_exists("url_sistema", $d) && trim((string)$d["url_sistema"]) !== "" && preg_match("#^https?://#i", trim((string)$d["url_sistema"])) !== 1) {
            $erros[] = "Endereço do sistema: começando por http:// ou https:// (vazio: sem link).";
        }
        foreach (["agenda_id" => 255, "agenda_chave" => 500] as $k => $max) {
            if (array_key_exists($k, $d) && strlen(trim((string)$d[$k])) > $max) {
                $erros[] = $k . ": até " . $max . " caracteres.";
            }
        }
        $tipos_todos = tipos_de_aviso();
        foreach ($CANAIS as $canal => $c) {
            if (array_key_exists($canal . "_padrao", $d) && strlen((string)$d[$canal . "_padrao"]) > 4000) {
                $erros[] = "A mensagem padrão do " . $c["nome"] . " vai até 4000 caracteres.";
            }
            foreach ((array)($d[$c["tipos"]] ?? []) as $t) {
                if ($t !== "" && !isset($tipos_todos[$t])) {
                    $erros[] = $c["tipos"] . ": aviso desconhecido (" . $t . ").";
                }
            }
        }
        if (count($erros) === 0) {
            foreach (["horario_manha", "horario_noite", "uso_inicio", "uso_fim", "max_sem_uso", "agenda_antecedencia", "agenda_id", "agenda_chave", "url_sistema", "carga_limiar",
                "sol_limiar", "medicao_janela_dias"] as $k) {
                if (array_key_exists($k, $d)) {
                    cfg_set($k, trim((string)$d[$k]));
                }
            }
            if (array_key_exists("sol_fim", $d) && isset(lancamento_tipos()["sol"])) {
                sql("UPDATE lancamento_tipo SET fecha_as = ? WHERE identificador = 'sol'", [$d["sol_fim"] . ":00"]);
            }
            if (array_key_exists("previsao_limite", $d)) {
                cfg_set("previsao_limite", (string)numero_br($d["previsao_limite"]));
            }
            // "Enviar alertas" é a chave mensagens_ativas (alerta_ativo, o nome antigo, também vale)
            foreach (["alerta_ativo" => "mensagens_ativas", "mensagens_ativas" => "mensagens_ativas", "agenda_ativa" => "agenda_ativa",
                "pulso_auto_inicio" => "pulso_auto_inicio", "pulso_auto_fim" => "pulso_auto_fim"] as $k => $chave) {
                if (array_key_exists($k, $d)) {
                    cfg_set($chave, (string)$d[$k] === "1" ? "1" : "0");
                }
            }
            // tirando do pulso sozinho, o "fecha às" do tipo No pulso é o fim do horário de uso; sem tirar sozinho, nenhum (só o Tirou)
            if ((array_key_exists("pulso_auto_fim", $d) || array_key_exists("uso_fim", $d)) && isset(lancamento_tipos()["pulso"])) {
                sql("UPDATE lancamento_tipo SET fecha_as = ? WHERE identificador = 'pulso'", [pulso_tira_sozinho() ? cfg("uso_fim") . ":00" : null]);
            }
            foreach ($CANAIS as $canal => $c) {
                // os tipos que vão pelo canal: os marcados; os outros, não (a tabela canal_aviso)
                if (array_key_exists($c["tipos"], $d)) {
                    $marcados = array_values(array_filter((array)$d[$c["tipos"]], function ($t) { return $t !== ""; }));
                    foreach ($tipos_todos as $k => $t) {
                        if (in_array($k, $marcados, true) !== canal_aviso($canal, $k)["envia"]) {
                            canal_gravar($canal, $k, ["envia" => in_array($k, $marcados, true)]);
                        }
                    }
                }
                if (array_key_exists($canal . "_padrao", $d)) {
                    cfg_set($canal . "_padrao", str_replace("\r\n", "\n", (string)$d[$canal . "_padrao"]));
                }
                // a personalizada de cada aviso (o texto fica guardado mesmo desmarcado)
                foreach ($tipos_todos as $k => $t) {
                    $muda = [];
                    if (array_key_exists($canal . "_proprio_" . $k, $d)) {
                        $muda["propria"] = (string)$d[$canal . "_proprio_" . $k] === "1";
                    }
                    if (array_key_exists($canal . "_corpo_" . $k, $d)) {
                        $muda["corpo"] = str_replace("\r\n", "\n", (string)$d[$canal . "_corpo_" . $k]);
                    }
                    if (count($muda) > 0) {
                        canal_gravar($canal, $k, $muda);
                    }
                }
            }
            $msg = "Configuração salva.";
        }
    } elseif ($acao === "testar_manha" || $acao === "testar_noite") {
        garantir_plano($hoje);
        $texto = $acao === "testar_manha" ? montar_mensagem($hoje) : montar_mensagem_noite($hoje);
        if ($texto === "") {
            $msg = $acao === "testar_manha" ? "A mensagem da manhã está vazia: nenhum aviso marcado para o Telegram tem o que dizer hoje."
                : "Nada a enviar hoje à noite: amanhã continua o mesmo relógio, ou a véspera está desmarcada para o Telegram.";
        } elseif (enviar_em_partes($texto, defined("MSG_TITULO") ? (string)constant("MSG_TITULO") : "Relógios") === 0) {
            $msg = $acao === "testar_manha" ? "Mensagem da manhã enviada." : "Mensagem da noite enviada.";
        } else {
            $erros[] = "A API de alerta não respondeu com sucesso. Confira o endereço no config.php.";
        }
    } elseif ($acao === "evento_salvar") {
        $id = (int)($d["evento_id"] ?? ($d["id"] ?? 0));
        $atual = $id > 0 ? (eventos_com_dias(linhas("SELECT * FROM evento_personalizado WHERE id = ?", [$id]))[0] ?? null) : null;
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $rep = (string)($d["repeticao"] ?? ($atual["repeticao"] ?? ""));
        $hora = substr(trim((string)($d["hora"] ?? ($atual["hora"] ?? ""))), 0, 5);
        $data_ini = trim((string)($d["data_inicio"] ?? ($atual["data_inicio"] ?? "")));
        $dias = $d["dias_semana"] ?? ($atual["dias_semana"] ?? "");
        $dias = array_values(array_unique(array_filter(array_map("intval", is_array($dias) ? $dias : explode(",", (string)$dias)), function ($x) { return $x >= 1 && $x <= 7; })));
        sort($dias);
        $dia_mes = (int)($d["dia_mes"] ?? ($atual["dia_mes"] ?? 0));
        $intervalo = (int)($d["intervalo_dias"] ?? ($atual["intervalo_dias"] ?? 0));
        $rid = (int)($d["relogio_id"] ?? ($atual["relogio_id"] ?? 0));
        $ativo = array_key_exists("ativo", $d) ? ((string)$d["ativo"] === "1" ? 1 : 0) : ($atual ? (int)$atual["ativo"] : 1);
        // a cada N dias sem data: começa hoje
        if ($rep === "intervalo" && $data_ini === "") {
            $data_ini = $hoje->format("Y-m-d");
        }
        $d_ok = DateTimeImmutable::createFromFormat("!Y-m-d", $data_ini);
        if ($id > 0 && !$atual) {
            $erros[] = "Evento não encontrado.";
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = "Dê um nome ao evento (até 120 caracteres).";
        }
        if (!isset($REPETICOES[$rep])) {
            $erros[] = "Quando dispara: uma, diaria, semanal, mensal ou intervalo.";
        }
        if (preg_match($hora_ok, $hora) !== 1) {
            $erros[] = "Hora: HH:MM.";
        }
        if (($rep === "uma" || $rep === "intervalo") && !($d_ok && $d_ok->format("Y-m-d") === $data_ini)) {
            $erros[] = "Uma vez: informe a data (AAAA-MM-DD).";
        }
        if ($rep === "semanal" && count($dias) === 0) {
            $erros[] = "Em dias da semana: marque pelo menos um dia.";
        }
        if ($rep === "mensal" && ($dia_mes < 1 || $dia_mes > 31)) {
            $erros[] = "Dia do mês: de 1 a 31.";
        }
        if ($rep === "intervalo" && ($intervalo < 1 || $intervalo > 3650)) {
            $erros[] = "A cada quantos dias: de 1 a 3650.";
        }
        // relógio do evento: um disponível, ou o que o evento já tinha (não troca sozinho); outro qualquer fica sem relógio
        if ($rid > 0 && (int)valor("SELECT COUNT(*) FROM relogio WHERE id = ? AND disponivel = 1", [$rid]) === 0 && (int)($atual["relogio_id"] ?? 0) !== $rid) {
            $rid = 0;
        }
        if (count($erros) === 0) {
            // só o que a repetição usa fica gravado
            $dados = [$nome, $ativo, $rep, $rep === "uma" || $rep === "intervalo" ? $data_ini : null, $hora . ":00",
                $rep === "mensal" ? $dia_mes : null, $rep === "intervalo" ? $intervalo : null, $rid > 0 ? $rid : null];
            if ($atual) {
                sql("UPDATE evento_personalizado SET nome = ?, ativo = ?, repeticao = ?, data_inicio = ?, hora = ?, dia_mes = ?, intervalo_dias = ?,
                    relogio_id = ? WHERE id = ?", array_merge($dados, [$id]));
            } else {
                sql("INSERT INTO evento_personalizado (nome, ativo, repeticao, data_inicio, hora, dia_mes, intervalo_dias, relogio_id, criado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", array_merge($dados, [date("Y-m-d H:i:s")]));
                // evento novo já sai pelo Telegram; os canais se ajustam na tabela
                $id = ultimo_id();
                canal_gravar("tg", "ev" . $id, ["envia" => true]);
            }
            // os dias da semana (semanal): uma linha cada, na tabela evento_dia
            sql("DELETE FROM evento_dia WHERE evento_id = ?", [$id]);
            if ($rep === "semanal") {
                foreach ($dias as $dia) {
                    sql("INSERT INTO evento_dia (evento_id, dia) VALUES (?, ?)", [$id, $dia]);
                }
            }
            $msg = "Evento salvo.";
            $extra["id"] = $id;
        }
    } elseif ($acao === "evento_excluir") {
        $id = (int)($d["evento_id"] ?? ($d["id"] ?? 0));
        $ev = linha("SELECT * FROM evento_personalizado WHERE id = ?", [$id]);
        if (!$ev) {
            $erros[] = "Evento não encontrado.";
        } else {
            // os canais e os textos dele (canal_aviso) e os dias (evento_dia) saem junto, pelo banco (chave estrangeira em cascata)
            sql("DELETE FROM evento_personalizado WHERE id = ?", [$id]);
            canal_aviso("tg", null, true);
            $msg = "Evento excluído.";
        }
    } elseif ($acao === "teste_agenda_criar") {
        // um evento de teste daqui a 10 minutos, pelo modelo do relógio do dia; o id fica para poder removê-lo
        if (cfg("agenda_id") === "") {
            $erros[] = "Informe o ID da agenda antes de testar.";
        } elseif (!google_token()) {
            $erros[] = "Não autenticou no Google: confira o caminho da chave JSON e se a Calendar API está ativa no projeto.";
        } else {
            $dia = plano_do_dia($hoje->format("Y-m-d"));
            $inicio = new DateTimeImmutable("+10 minutes");
            $f = evento_formatado(["tipo" => "dia", "relogio_id" => $dia ? (int)$dia["relogio_id"] : 0, "data" => $inicio->format("Y-m-d"), "hora" => $inicio->format("H:i"),
                "fazer" => "Usar hoje", "motivo" => $dia ? texto_ate($dia["data"], "hoje") : "evento de teste do sistema"]);
            $r = google_api("POST", "/calendars/" . rawurlencode(cfg("agenda_id")) . "/events", ["summary" => "[TESTE] " . $f["titulo"],
                "description" => $f["descricao"] . "\n\nEvento de teste criado pela tela de Configuração do sistema de relógios.",
                "start" => ["dateTime" => $inicio->format("Y-m-d\\TH:i:s"), "timeZone" => date_default_timezone_get()],
                "end" => ["dateTime" => $inicio->modify("+15 minutes")->format("Y-m-d\\TH:i:s"), "timeZone" => date_default_timezone_get()]]);
            if ($r[0] >= 200 && $r[0] < 300 && !empty($r[1]["id"])) {
                cfg_set("agenda_teste_id", $r[1]["id"]);
                $msg = "Evento de teste criado para hoje às " . $inicio->format("H:i") . ": \"[TESTE] " . $f["titulo"] . "\". Confira no Google Agenda.";
            } else {
                $erros[] = "O Google recusou o evento (código " . $r[0] . "). Confira o ID da agenda e se ela foi compartilhada com a conta de serviço, com permissão para fazer alterações nos eventos.";
            }
        }
    } elseif ($acao === "teste_agenda_remover") {
        if (cfg("agenda_teste_id") === "") {
            $erros[] = "Não há evento de teste para remover.";
        } else {
            $r = google_api("DELETE", "/calendars/" . rawurlencode(cfg("agenda_id")) . "/events/" . rawurlencode(cfg("agenda_teste_id")), null);
            if (in_array($r[0], [200, 204, 404, 410], true)) {
                cfg_set("agenda_teste_id", "");
                $msg = "Evento de teste removido da agenda.";
            } else {
                $erros[] = "O Google não removeu o evento de teste (código " . $r[0] . ").";
            }
        }
    } elseif ($acao === "sincronizar") {
        $r = sincronizar_agenda($hoje);
        if ($r["mensagem"] !== "") {
            $erros[] = $r["mensagem"];
        } elseif ($r["erros"] > 0) {
            $erros[] = "Agenda: " . $r["criados"] . " criados, " . $r["atualizados"] . " atualizados, " . $r["removidos"] . " removidos e " . $r["erros"] . " recusados pelo Google.";
        } else {
            $msg = "Agenda sincronizada: " . $r["criados"] . " criados, " . $r["atualizados"] . " atualizados, " . $r["removidos"] . " removidos.";
        }
        $extra = ["criados" => $r["criados"], "atualizados" => $r["atualizados"], "removidos" => $r["removidos"], "recusados" => $r["erros"]];
    }
    return resultado($erros, $msg, $extra);
}

// ---------------------------------------------------------------------------------------------------------------------
// Usuários. Ações: salvar (login, senha: cria, ou troca a senha; pelo menos 6 caracteres), excluir (login: não o
// conectado, e sobra pelo menos um). $atual: quem está fazendo.
// ---------------------------------------------------------------------------------------------------------------------
function op_usuarios($acao, $d, $atual)
{
    $erros = [];
    $msg = "";
    $alvo = trim((string)($d["login"] ?? ""));
    $nova = (string)($d["senha"] ?? "");
    if ($acao === "salvar") {
        if ($alvo === "" || strlen($alvo) > 60 || strlen($nova) < 6) {
            $erros[] = "Informe o login (até 60 caracteres) e uma senha de pelo menos 6 caracteres.";
        } else {
            $hash = password_hash($nova, PASSWORD_DEFAULT);
            if (valor("SELECT id FROM usuario WHERE login = ?", [$alvo])) {
                sql("UPDATE usuario SET senha_hash = ? WHERE login = ?", [$hash, $alvo]);
                $msg = "Senha de " . $alvo . " trocada.";
            } else {
                sql("INSERT INTO usuario (login, senha_hash, criado) VALUES (?, ?, NOW())", [$alvo, $hash]);
                $msg = "Usuário " . $alvo . " criado.";
            }
        }
    } elseif ($acao === "excluir") {
        if (!valor("SELECT id FROM usuario WHERE login = ?", [$alvo])) {
            $erros[] = "Usuário não encontrado.";
        } elseif ($alvo === $atual) {
            $erros[] = "Você não pode excluir o usuário com que está conectado.";
        } elseif ((int)valor("SELECT COUNT(*) FROM usuario") <= 1) {
            $erros[] = "Precisa sobrar pelo menos um usuário.";
        } else {
            sql("DELETE FROM usuario WHERE login = ?", [$alvo]);
            $msg = "Usuário " . $alvo . " excluído.";
        }
    }
    return resultado($erros, $msg);
}
