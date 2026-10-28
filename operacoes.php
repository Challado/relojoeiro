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
    return decimal_txt(round((float)$v, 2), 6) . '%';
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
        $erros[] = t("Ação desconhecida ou item não encontrado.");
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
            $contas[] = [t("a data do aviso {1} ({2})", [1 => $ident, 2 => no_caminho($a["no_id"])]), $a["expressao"]];
            $contas[] = [t("o vale quando do aviso {1} ({2})", [1 => $ident, 2 => no_caminho($a["no_id"])]), $a["condicao"] ?? ""];
        }
    }
    foreach (lancamento_tipos() as $ident => $t) {
        $contas[] = [t("o vale quando do tipo {1}", [1 => $ident]), $t["condicao"] ?? ""];
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
                $res[] = t("{1}, com erro de escrita", [1 => $c[0]]);
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
                $erros[] = t("Relógio {1} não encontrado.", [1 => (int)$rid]);
            } elseif ($no === false) {
                $erros[] = t("Grupo {1} não encontrado.", [1 => $gid]);
            } else {
                $novos[(int)$rid] = $no;
            }
        }
        if (count($erros) === 0) {
            $mudou = 0;
            foreach ($novos as $rid => $no) {
                if ((int)valor("SELECT COALESCE(no_id, 0) FROM relogio WHERE id = ?", [$rid]) !== (int)$no) {
                    sql("UPDATE relogio SET no_id = ? WHERE id = ?", [no_gravar($no), $rid]);
                    $mudou++;
                }
            }
            $msg = $mudou === 0 ? t("Nada mudou.") : t("Grupo alterado em {1}{2}", [1 => $mudou, 2 => $mudou === 1 ? t(" relógio.") : t(" relógios.")]);
        }
    } elseif ($acao === "novo" || $acao === "renomear") {
        $pai_do_nome = $acao === "novo" ? $pai : ($n[$id]["pai_id"] ?? null);
        if ($acao === "renomear" && !isset($n[$id])) {
            $erros[] = t("Ponto da árvore não encontrado.");
        }
        if ($acao === "novo" && $pai === false) {
            $erros[] = t("O ponto de cima não existe.");
        }
        if ($nome === "" || strlen($nome) > 80) {
            $erros[] = t("Dê um nome (até 80 caracteres).");
        }
        foreach ($n as $nid => $x) {
            if ((string)$x["pai_id"] === (string)$pai_do_nome && $nid !== $id && mesmo_nome($x["nome"], $nome)) {
                $erros[] = t("Já existe {1} neste mesmo lugar da árvore.", [1 => $x["nome"]]);
            }
        }
        if (count($erros) === 0 && $acao === "novo") {
            sql("INSERT INTO no (pai_id, nome, ordem) VALUES (?, ?, ?)", [no_gravar($pai), $nome, (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM no WHERE pai_id <=> ?", [$pai])]);
            $id = ultimo_id();
            nos_todos(true);
            $msg = t("{1} criado.", [1 => no_caminho($id)]);
        } elseif (count($erros) === 0) {
            sql("UPDATE no SET nome = ? WHERE id = ?", [$nome, $id]);
            nos_todos(true);
            $msg = t("Renomeado: {1}.", [1 => no_caminho($id)]);
        }
    } elseif ($acao === "mover") {
        if (!isset($n[$id]) || $pai === false) {
            $erros[] = t("Ponto da árvore não encontrado.");
        } elseif ($pai !== null && in_array($id, no_cadeia($pai), true)) {
            $erros[] = t("Um ponto não pode ir para dentro dele mesmo, nem de um ponto de dentro dele.");
        } else {
            foreach ($n as $nid => $x) {
                if ((string)$x["pai_id"] === (string)$pai && $nid !== $id && mesmo_nome($x["nome"], $n[$id]["nome"])) {
                    $erros[] = t("Já existe {1} no destino.", [1 => $x["nome"]]);
                }
            }
        }
        if (count($erros) === 0) {
            sql("UPDATE no SET pai_id = ?, ordem = ? WHERE id = ?", [no_gravar($pai), (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM no WHERE pai_id <=> ?", [$pai]), $id]);
            nos_todos(true);
            $msg = t("Movido: agora é {1}.", [1 => no_caminho($id)]);
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
        $msg = t("Ordem alterada.");
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
            sql("UPDATE " . $tabela . " SET " . $coluna . " = ? WHERE " . $coluna . " = ?", [no_gravar($cima), $id]);
        }
        sql("DELETE FROM no WHERE id = ?", [$id]);
        nos_todos(true);
        $msg = t("{1} excluído; o que era dele foi para {2}.{3}{4}{5}", [1 => $n[$id]["nome"], 2 => $destino, 3 => count($saem["formula"]) > 0 ? t(" Saíram as versões dele das fórmulas que {1} já tinha: {2}.", [1 => $destino, 2 => implode(", ", $saem["formula"])]) : "", 4 => count($saem["aviso"]) > 0 ? t(" Saíram as versões dele dos avisos que {1} já tinha: {2}.", [1 => $destino, 2 => implode(", ", $saem["aviso"])]) : "", 5 => $criterios > 0 ? t(" Os critérios próprios dele saíram: os relógios dele usam os do lugar mais perto, acima.") : ""]);
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Campos. Ações: novo e alterar (id; identificador, nome, tipo, unidade, opcoes (lista: uma por linha), padrao,
// no_id, so_desejado, calibre: 1 se o campo é do movimento, o formulário do calibre o mostra), excluir (id), ordem (id,
// direcao). Não se exclui nem se renomeia um campo que uma fórmula usa; não se muda o
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
        $erros[] = t("Campo não encontrado.");
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
        // só na lista de desejos: o campo aparece só nos relógios desejados (o preço-alvo, a prioridade)
        $so_desejado = array_key_exists("so_desejado", $d) ? ((string)$d["so_desejado"] === "1" ? 1 : 0) : (int)($atual["so_desejado"] ?? 0);
        // do movimento: o campo aparece no formulário do calibre (com a Configuração calibre_campos em só os do movimento)
        $do_calibre = array_key_exists("calibre", $d) ? ((string)$d["calibre"] === "1" ? 1 : 0) : (int)($atual["calibre"] ?? 0);
        // a divisória da ficha (0 ou vazio: nenhuma, no fim)
        $secao = array_key_exists("secao_id", $d) ? ((int)$d["secao_id"] > 0 ? (int)$d["secao_id"] : null) : (($atual["secao_id"] ?? null) !== null ? (int)$atual["secao_id"] : null);
        if ($secao !== null && !isset(secoes_todas()[$secao])) {
            $erros[] = t("Divisória: a {1} não existe (Cadastros › Divisórias).", [1 => $secao]);
        }
        // as regras do valor: o mínimo e o máximo (só no número; vazio: sem limite) e o múltiplo (vários dos valores possíveis)
        $numero = $c["tipo"] === "inteiro" || $c["tipo"] === "decimal";
        foreach (["minimo" => t("Mínimo"), "maximo" => t("Máximo")] as $k => $rot) {
            $bruto = array_key_exists($k, $d) ? trim((string)$d[$k]) : (string)numero_do_campo($atual[$k] ?? null);
            $c[$k] = null;
            if ($numero && $bruto !== "") {
                $p = valor_para_campo(["tipo" => "decimal"], $bruto);
                if ($p[1] !== "") {
                    $erros[] = $rot . ": " . $p[1] . ".";
                } else {
                    $c[$k] = $p[0];
                }
            }
        }
        if ($c["minimo"] !== null && $c["maximo"] !== null && (float)$c["minimo"] > (float)$c["maximo"]) {
            $erros[] = t("O mínimo passa do máximo.");
        }
        $c["multiplo"] = array_key_exists("multiplo", $d) ? ((string)$d["multiplo"] === "1" ? 1 : 0) : (int)($atual["multiplo"] ?? 0);
        // de quem é o campo: do relógio (o padrão), do acessório, do winder ou da posição do winder; no acessório, o tipo (vazio:
        // todos) e o campo do relógio com que ele tem de bater para servir nele (a largura da pulseira com o entre alças)
        $alvo = array_key_exists("alvo", $d) ? (string)$d["alvo"] : (string)($atual["alvo"] ?? "relogio");
        $ac_tipo = array_key_exists("acessorio_tipo", $d) ? trim((string)$d["acessorio_tipo"]) : (string)($atual["acessorio_tipo"] ?? "");
        $serve = array_key_exists("serve_campo_id", $d) ? (int)$d["serve_campo_id"] : (int)($atual["serve_campo_id"] ?? 0);
        if (!in_array($alvo, ["relogio", "acessorio", "winder", "posicao"], true)) {
            $erros[] = t("Para: relogio, acessorio, winder ou posicao.");
        } elseif ($atual && $alvo !== (string)($atual["alvo"] ?? "relogio") && count(valores_do_campo($atual)) > 0) {
            $erros[] = t("O campo {1} já tem valores: não dá para mudar de quem ele é (do relógio, do acessório, do winder ou da posição).", [1 => $atual["nome"]]);
        }
        if ($alvo === "acessorio") {
            if ($ac_tipo !== "" && !isset(lista_itens("acessorio_tipo")[$ac_tipo])) {
                $erros[] = t("Tipo de acessório: {1} (vazio: todos).", [1 => implode(", ", array_keys(lista_itens("acessorio_tipo")))]);
            }
            $alvo_serve = $serve > 0 ? (array_values(array_filter(campos_todos(), function ($x) use ($serve) { return (int)$x["id"] === $serve; }))[0] ?? null) : null;
            if ($serve > 0 && !$alvo_serve) {
                $erros[] = t("Serve quando bate com: o campo {1} não é um campo dos relógios.", [1 => $serve]);
            }
            // o do acessório não tem grupo, divisória, desejado nem movimento (vale em todos os acessórios do tipo)
            $no = null;
            $so_desejado = 0;
            $do_calibre = 0;
            $secao = null;
        } elseif ($alvo === "winder" || $alvo === "posicao") {
            // o do winder e o da posição também não (valem em todos os winders e em todas as posições)
            $no = null;
            $so_desejado = 0;
            $do_calibre = 0;
            $secao = null;
            $ac_tipo = "";
            $serve = 0;
        } else {
            $ac_tipo = "";
            $serve = 0;
        }
        if (!identificador_valido($c["identificador"])) {
            $erros[] = t("Identificador: minúsculas, números e _, começando por letra, até 40 (é o nome nas fórmulas).");
        } elseif ((int)valor("SELECT COUNT(*) FROM campo WHERE identificador = ? AND id <> ?", [$c["identificador"], $id]) > 0 || isset(formulas_todas()[$c["identificador"]])) {
            $erros[] = t("Já existe um campo ou uma fórmula com o identificador {1}.", [1 => $c["identificador"]]);
        }
        if ($atual && $c["identificador"] !== $atual["identificador"] && count(formulas_que_usam($atual["identificador"], "variaveis")) > 0) {
            $erros[] = t("Não dá para renomear {1}: estas fórmulas usam: {2}.", [1 => $atual["identificador"], 2 => implode(", ", formulas_que_usam($atual["identificador"], "variaveis"))]);
        }
        if ($c["nome"] === "" || strlen($c["nome"]) > 120) {
            $erros[] = t("Dê um nome ao campo (até 120 caracteres).");
        }
        if (!isset($TIPOS_CAMPO[$c["tipo"]])) {
            $erros[] = t("Tipo: {1}.", [1 => implode(", ", array_keys($TIPOS_CAMPO))]);
        }
        if (($erro_u = unidade_conferir($c["unidade"])) !== "") {
            $erros[] = $erro_u;
        }
        if ($c["tipo"] === "lista" && count(array_filter(explode("\n", $c["opcoes"]))) === 0) {
            $erros[] = t("Uma lista precisa de pelo menos uma opção (uma por linha).");
        }
        // os valores possíveis: no sim/não e na data, nenhum; no número, cada um conferido (e escrito como o número)
        if ($c["tipo"] === "sim_nao" || $c["tipo"] === "data") {
            $c["opcoes"] = "";
        } elseif ($numero && $c["opcoes"] !== "" && count($erros) === 0) {
            $ops = [];
            foreach (campo_opcoes_lista($c) as $o) {
                $p = valor_para_campo(array_merge($c, ["opcoes" => "", "multiplo" => 0]), $o);
                if ($p[1] !== "") {
                    $erros[] = t("Valores possíveis: {1} {2}.", [1 => $o, 2 => $p[1]]);
                } else {
                    $ops[] = $p[0];
                }
            }
            $c["opcoes"] = implode("\n", $ops);
        }
        if ($c["multiplo"] === 1 && count(campo_opcoes_lista($c)) === 0) {
            $erros[] = t("Vários valores: o campo precisa dos valores possíveis (um por linha).");
        }
        if ($no === false) {
            $erros[] = t("O ponto da árvore não existe.");
        }
        if (count($erros) === 0 && $c["padrao"] !== "") {
            $p = valor_para_campo($c, $c["padrao"]);
            if ($p[1] !== "") {
                $erros[] = t("Valor padrão: {1}.", [1 => $p[1]]);
            }
            $c["padrao"] = $p[0] ?? "";
        }
        // tipo ou regras novas: os valores já gravados têm de servir nelas (e são gravados de novo, já no jeito novo: o
        // múltiplo um por linha, o número dos valores possíveis)
        $regravar = [];
        if (count($erros) === 0 && $atual && ($c["tipo"] !== $atual["tipo"] || $c["opcoes"] !== (string)$atual["opcoes"] || $c["multiplo"] !== (int)($atual["multiplo"] ?? 0)
            || $c["minimo"] !== numero_do_campo($atual["minimo"] ?? null) || $c["maximo"] !== numero_do_campo($atual["maximo"] ?? null))) {
            $nao_servem = [];
            foreach (valores_do_campo($atual) as $v) {
                $p = valor_para_campo($c, $v["valor"]);
                if ($p[1] !== "") {
                    $nao_servem[] = $v["nome"] . " (" . str_replace("\n", ", ", $v["valor"]) . ")";
                } else {
                    $regravar[] = [$v["dono"], $v["id"], $p[0]];
                }
            }
            if (count($nao_servem) > 0) {
                $erros[] = t("Estes valores não servem no tipo ou nas opções novas: {1}. Acerte-os antes.", [1 => implode(", ", $nao_servem)]);
            }
        }
        if (count($erros) === 0) {
            $dados = [$c["identificador"], $c["nome"], $c["tipo"], $c["unidade"], $c["padrao"] !== "" ? $c["padrao"] : null, no_gravar($no), $so_desejado, $do_calibre, $secao,
                $c["minimo"], $c["maximo"], $c["multiplo"], $alvo, $ac_tipo !== "" ? $ac_tipo : null, $serve > 0 ? $serve : null];
            if ($acao === "novo") {
                sql("INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, so_desejado, calibre, secao_id, minimo, maximo, multiplo, alvo, acessorio_tipo, serve_campo_id, ordem)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    array_merge($dados, [(int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM campo")]));
                $id = ultimo_id();
                $msg = $alvo === "acessorio" ? t("Campo {1} criado, para os acessórios{2}.", [1 => $c["nome"], 2 => $ac_tipo !== "" ? " (" . mb_strtolower(lista_nome("acessorio_tipo", $ac_tipo), "UTF-8") . ")" : ""])
                    : ($alvo === "winder" ? t("Campo {1} criado, para os winders. Nas fórmulas: WINDER(\"{2}\").", [1 => $c["nome"], 2 => $c["identificador"]])
                    : ($alvo === "posicao" ? t("Campo {1} criado, para as posições dos winders. Nas fórmulas: WINDER_POSICAO(\"{2}\").", [1 => $c["nome"], 2 => $c["identificador"]])
                    : t("Campo {1} criado, para {2}. Nas fórmulas: {3}.", [1 => $c["nome"], 2 => no_caminho($no), 3 => $c["identificador"]])));
            } else {
                sql("UPDATE campo SET identificador = ?, nome = ?, tipo = ?, unidade = ?, padrao = ?, no_id = ?, so_desejado = ?, calibre = ?, secao_id = ?, minimo = ?, maximo = ?, multiplo = ?,
                    alvo = ?, acessorio_tipo = ?, serve_campo_id = ? WHERE id = ?", array_merge($dados, [$id]));
                $msg = t("Campo {1} salvo.", [1 => $c["nome"]]);
            }
            // os valores possíveis (no sim/não e na data, nenhum) e os valores já gravados, no jeito novo
            campo_gravar_opcoes($id, $c["opcoes"]);
            foreach ($regravar as [$dono, $did, $val]) {
                valor_gravar($dono, $did, ["id" => $id, "multiplo" => $c["multiplo"]], $val);
            }
            campos_todos(true);
        }
    } elseif ($acao === "excluir") {
        $usam = formulas_que_usam($atual["identificador"], "variaveis");
        if (count($usam) > 0) {
            $erros[] = t("Não dá para excluir {1}: estas fórmulas usam: {2}.", [1 => $atual["identificador"], 2 => implode(", ", $usam)]);
        } else {
            $n = (int)valor("SELECT COUNT(*) FROM relogio WHERE id IN (SELECT relogio_id FROM campo_valor WHERE campo_id = ?) OR id IN (SELECT relogio_id FROM campo_valor_item WHERE campo_id = ?)", [$id, $id]);
            sql("DELETE FROM campo WHERE id = ?", [$id]);
            campos_todos(true);
            $msg = t("Campo {1} excluído{2}", [1 => $atual["nome"], 2 => $n > 0 ? t(", com os valores dele em {1}{2}", [1 => $n, 2 => $n === 1 ? t(" relógio.") : t(" relógios.")]) : "."]);
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
        $msg = t("Ordem alterada.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Divisórias da ficha (as seções dos campos: Cadastros › Divisórias). Ações: salvar (id 0 cria, no fim; nome, até 80, sem
// repetir na árvore), ordem (id, direcao: sobe ou desce), excluir (id; os campos dela ficam sem divisória, no fim da ficha)
// ---------------------------------------------------------------------------------------------------------------------
function op_secoes($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? (secoes_todas(true)[$id] ?? null) : null;
    if ((($acao === "salvar" && $id > 0) || $acao === "ordem" || $acao === "excluir") && !$atual) {
        $erros[] = t("Divisória não encontrada.");
    } elseif ($acao === "salvar") {
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        if ($nome === "" || mb_strlen($nome) > 80) {
            $erros[] = t("Dê um nome à divisória (até 80 caracteres).");
        } elseif ((int)valor("SELECT COUNT(*) FROM campo_secao WHERE nome = ? AND id <> ?", [$nome, $id]) > 0) {
            $erros[] = t("Já existe a divisória {1}.", [1 => $nome]);
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE campo_secao SET nome = ? WHERE id = ?", [$nome, $id]);
                $msg = t("Divisória {1} salva.", [1 => $nome]);
            } else {
                sql("INSERT INTO campo_secao (no_id, nome, ordem) VALUES (?, ?, ?)", [no_raiz(), $nome, (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM campo_secao")]);
                $id = ultimo_id();
                $msg = t("Divisória {1} criada.", [1 => $nome]);
            }
        }
    } elseif ($acao === "ordem") {
        $todas = array_keys(secoes_todas());
        $pos = array_search($id, $todas, true);
        $viz = ($d["direcao"] ?? "") === "sobe" ? $pos - 1 : $pos + 1;
        if ($viz >= 0 && $viz < count($todas)) {
            $todas[$pos] = $todas[$viz];
            $todas[$viz] = $id;
        }
        foreach ($todas as $k => $iid) {
            sql("UPDATE campo_secao SET ordem = ? WHERE id = ?", [$k + 1, $iid]);
        }
        $msg = t("Ordem alterada.");
    } elseif ($acao === "excluir") {
        $n = (int)valor("SELECT COUNT(*) FROM campo WHERE secao_id = ?", [$id]);
        sql("DELETE FROM campo_secao WHERE id = ?", [$id]);
        $msg = t("Divisória {1} excluída{2}", [1 => $atual["nome"], 2 => $n === 0 ? "." : ($n === 1 ? t(": 1 campo ficou sem divisória, no fim da ficha.")
            : t(": {1} campos ficaram sem divisória, no fim da ficha.", [1 => $n]))]);
    } else {
        $erros[] = t("Ação desconhecida: {1}.", [1 => $acao]);
    }
    secoes_todas(true);
    campos_todos(true);
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Unidades (o cadastro da árvore: Cadastros › Unidades). Ações: salvar (id 0 cria; simbolo (até 20, sem repetir na
// árvore: o que vai depois do número), nome (até 80), base (o símbolo da unidade base, a da mesma grandeza em que esta se
// converte; vazio: esta é uma base), fator (com base: 1 desta = fator da base), zero (com base: o valor desta que é o zero
// da base, como os 32 do °F; vazio: 0), mostrar_em (o símbolo de uma unidade da mesma grandeza em que o valor também
// aparece; vazio: nenhuma); só muda o que vier. O símbolo novo vai junto para os campos, os tipos de lançamento e as
// fórmulas que usavam o antigo), excluir (id; não se exclui a unidade em uso, nem a base de uma unidade em uso)
// ---------------------------------------------------------------------------------------------------------------------
function op_unidades($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM unidade WHERE id = ?", [$id]) : null;
    $todas = unidades_todas(true);
    $por_id = [];
    foreach ($todas as $u) {
        $por_id[$u["id"]] = $u;
    }
    // quem usa estes símbolos: os campos, os tipos de lançamento e as fórmulas
    $usos = function ($simbolos) {
        $res = [];
        foreach ([["campo", t("campo {1}")], ["lancamento_tipo", t("tipo de lançamento {1}")], ["formula", t("fórmula {1}")]] as [$tabela, $texto]) {
            foreach ($simbolos as $simbolo) {
                foreach (linhas("SELECT DISTINCT nome FROM " . $tabela . " WHERE unidade = ? ORDER BY nome", [$simbolo]) as $x) {
                    $res[] = str_replace("{1}", $x["nome"] . " (" . $simbolo . ")", $texto);
                }
            }
        }
        return $res;
    };
    // um número digitado ("25,4", "1.000,5" ou "25.4"), ou null
    $numero = function ($t) {
        $n = strpos($t, ",") !== false ? str_replace(",", ".", str_replace(".", "", $t)) : $t;
        return is_numeric($n) ? (float)$n : null;
    };
    if ((($acao === "salvar" && $id > 0) || $acao === "excluir") && !$atual) {
        $erros[] = t("Unidade não encontrada.");
    } elseif ($acao === "salvar") {
        $antes = $atual ? $por_id[$id] : null;
        $simbolo = trim((string)($d["simbolo"] ?? ($atual["simbolo"] ?? "")));
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $base = trim((string)(array_key_exists("base", $d) ? $d["base"] : ($antes["base"] ?? "")));
        $fator_t = trim((string)(array_key_exists("fator", $d) ? $d["fator"] : ($antes["fator"] ?? "")));
        $zero_t = trim((string)(array_key_exists("zero", $d) ? $d["zero"] : ($antes["zero"] ?? "")));
        $mostrar = trim((string)(array_key_exists("mostrar_em", $d) ? $d["mostrar_em"] : ($antes["mostrar_em"] ?? "")));
        if ($simbolo === "" || mb_strlen($simbolo) > 20) {
            $erros[] = t("Símbolo: de 1 a 20 caracteres (o que vai depois do número: mm, h, R$).");
        } elseif ((int)valor("SELECT COUNT(*) FROM unidade WHERE simbolo = ? AND id <> ?", [$simbolo, $id]) > 0) {
            $erros[] = t("Já existe a unidade {1}.", [1 => $simbolo]);
        }
        if ($nome === "" || mb_strlen($nome) > 80) {
            $erros[] = t("Dê um nome à unidade (até 80 caracteres).");
        }
        $base_id = null;
        $fator = null;
        $zero = 0.0;
        if ($base !== "") {
            $b = $todas[$base] ?? null;
            $derivadas = $atual ? array_values(array_filter($todas, function ($u) use ($atual) { return $u["base"] === $atual["simbolo"]; })) : [];
            if ($b === null) {
                $erros[] = t("Base: a unidade {1} não existe.", [1 => $base]);
            } elseif ($b["id"] === $id) {
                $erros[] = t("A unidade não pode ser a base dela mesma.");
            } elseif ($b["base"] !== null) {
                $erros[] = t("{1} já se converte em {2}: a base tem de ser uma unidade sem base.", [1 => $b["simbolo"], 2 => $b["base"]]);
            } elseif (count($derivadas) > 0) {
                $erros[] = t("{1} é a base de {2}: uma base não tem base.", [1 => $atual["simbolo"], 2 => implode(", ", array_column($derivadas, "simbolo"))]);
            } else {
                $base_id = $b["id"];
            }
            $fator = $numero($fator_t);
            if ($fator === null || $fator == 0 || abs($fator) >= 1e9) {
                $erros[] = t("Fator: quanto vale 1 {1} na base, um número diferente de zero.", [1 => $simbolo]);
            }
            $zero = $zero_t === "" ? 0.0 : $numero($zero_t);
            if ($zero === null || abs($zero) >= 1e9) {
                $erros[] = t("Zero: um número (o valor de {1} que é o zero da base; quase sempre 0).", [1 => $simbolo]);
            }
        }
        $mostrar_id = null;
        if ($mostrar !== "") {
            $w = $todas[$mostrar] ?? null;
            // as duas têm de ter a mesma base (a desta é a base escolhida, ou ela mesma)
            $minha = $base_id ?? $id;
            $dela = $w === null ? null : ($w["base"] !== null ? $todas[$w["base"]]["id"] : $w["id"]);
            if ($w === null) {
                $erros[] = t("Mostrar também em: a unidade {1} não existe.", [1 => $mostrar]);
            } elseif ($w["id"] === $id) {
                $erros[] = t("Mostrar também em: escolha outra unidade, não ela mesma.");
            } elseif ($minha === 0 || $dela !== $minha) {
                $erros[] = t("{1} não se converte em {2}: escolha uma unidade da mesma grandeza (com a mesma base).", [1 => $simbolo, 2 => $mostrar]);
            } else {
                $mostrar_id = $w["id"];
            }
        }
        if (count($erros) === 0) {
            $dados = [$simbolo, $nome, $base_id, $fator, $base_id === null ? 0 : $zero, $mostrar_id];
            if ($atual) {
                sql("UPDATE unidade SET simbolo = ?, nome = ?, base_id = ?, fator = ?, zero = ?, mostrar_em_id = ? WHERE id = ?", array_merge($dados, [$id]));
                $msg = t("Unidade {1} salva.", [1 => $simbolo]);
                // o símbolo novo: os campos, os tipos de lançamento e as fórmulas que usavam o antigo passam a usar o novo
                if ($simbolo !== $atual["simbolo"]) {
                    $quem = $usos([$atual["simbolo"]]);
                    foreach (["campo", "lancamento_tipo", "formula"] as $tabela) {
                        sql("UPDATE " . $tabela . " SET unidade = ? WHERE unidade = ?", [$simbolo, $atual["simbolo"]]);
                    }
                    $msg .= count($quem) > 0 ? t(" Passaram de {1} para {2}: {3}.", [1 => $atual["simbolo"], 2 => $simbolo, 3 => implode(", ", $quem)]) : "";
                }
            } else {
                sql("INSERT INTO unidade (no_id, simbolo, nome, base_id, fator, zero, mostrar_em_id) VALUES (?, ?, ?, ?, ?, ?, ?)", array_merge([no_raiz()], $dados));
                $id = ultimo_id();
                $msg = t("Unidade {1} criada.", [1 => $simbolo]);
            }
            // a unidade em que outra também aparecia e deixou de ser da mesma grandeza (a base mudou) sai dela
            foreach (unidades_todas(true) as $u) {
                if ($u["mostrar_em"] !== null && unidade_base($u["mostrar_em"]) !== unidade_base($u["simbolo"])) {
                    sql("UPDATE unidade SET mostrar_em_id = NULL WHERE id = ?", [$u["id"]]);
                }
            }
            unidades_todas(true);
            campos_todos(true);
            lancamento_tipos(true);
        }
    } elseif ($acao === "excluir") {
        // a unidade e as que se convertem nela (saem junto)
        $derivadas = array_values(array_filter($todas, function ($u) use ($atual) { return $u["base"] === $atual["simbolo"]; }));
        $em_uso = $usos(array_merge([$atual["simbolo"]], array_column($derivadas, "simbolo")));
        if (count($em_uso) > 0) {
            $erros[] = t("Não dá para excluir {1}, que está em uso: {2}.", [1 => $atual["simbolo"], 2 => implode(", ", $em_uso)]);
        } else {
            sql("DELETE FROM unidade WHERE id = ?", [$id]);
            unidades_todas(true);
            $msg = t("Unidade {1} excluída{2}", [1 => $atual["simbolo"], 2 => count($derivadas) > 0 ? t(", com as que se convertiam nela: {1}.", [1 => implode(", ", array_column($derivadas, "simbolo"))]) : "."]);
        }
    } else {
        $erros[] = t("Ação desconhecida: {1}.", [1 => $acao]);
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Tipos de lançamento. Ações: novo e alterar (id; identificador, nome, formato, unidade, fecha_as (HH:MM; só sessão:
// a sessão esquecida aberta fecha sozinha a essa hora do dia em que começou; vazio: não fecha), exclusiva (1 ou 0; só sessão),
// mede_gasto (1 ou 0; só com valor: cada leitura mede o gasto, comparando com a anterior), marcha (vazio, desvio (só com valor:
// a leitura do desvio da hora, em segundos) ou acerto (só instantâneo: o acerto da hora)), winder (1 ou 0; só sessão: a sessão
// é numa posição de um winder, e o Pôr pede a posição), condicao (vale quando: uma fórmula,
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
        $erros[] = t("Tipo de lançamento não encontrado.");
    } elseif ($acao === "novo" || $acao === "alterar") {
        $ident = trim((string)($d["identificador"] ?? ($atual["identificador"] ?? "")));
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $formato = (string)($d["formato"] ?? ($atual["formato"] ?? ""));
        $unidade = trim((string)($d["unidade"] ?? ($atual["unidade"] ?? "")));
        if (($erro_u = unidade_conferir($unidade)) !== "") {
            $erros[] = $erro_u;
        }
        $fecha = trim((string)($d["fecha_as"] ?? ($atual ? substr((string)$atual["fecha_as"], 0, 5) : "")));
        $exclusiva = array_key_exists("exclusiva", $d) ? ((string)$d["exclusiva"] === "1" ? 1 : 0) : ($atual ? (int)$atual["exclusiva"] : 0);
        $mede_gasto = array_key_exists("mede_gasto", $d) ? ((string)$d["mede_gasto"] === "1" ? 1 : 0) : ($atual ? (int)$atual["mede_gasto"] : 0);
        $marcha = trim((string)(array_key_exists("marcha", $d) ? $d["marcha"] : ($atual["marcha"] ?? "")));
        $winder = array_key_exists("winder", $d) ? ((string)$d["winder"] === "1" ? 1 : 0) : ($atual ? (int)($atual["winder"] ?? 0) : 0);
        if ($winder === 1 && $formato !== "sessao") {
            $erros[] = t("Numa posição de winder só vale para sessão (com início e fim).");
        }
        if ($marcha !== "" && !(($marcha === "desvio" && $formato === "valor") || ($marcha === "acerto" && $formato === "instantaneo"))) {
            $erros[] = t("Mede a marcha: desvio (só com valor, em segundos), acerto (só instantâneo) ou vazio.");
        }
        $condicao = trim((string)($d["condicao"] ?? ($atual["condicao"] ?? "")));
        if ($condicao !== "") {
            foreach (formula_validar("", $condicao) as $e) {
                $erros[] = t("Vale quando: {1}.", [1 => $e]);
            }
        }
        $no = array_key_exists("no_id", $d) ? no_do_pedido($d["no_id"]) : ($atual ? ($atual["no_id"] === null ? null : (int)$atual["no_id"]) : null);
        if ($fecha !== "" && preg_match("/^([01][0-9]|2[0-3]):[0-5][0-9]\$/", $fecha) !== 1) {
            $erros[] = t("Fecha sozinho às: uma hora HH:MM, ou vazio.");
        } elseif ($fecha !== "" && $formato !== "sessao") {
            $erros[] = t("Fecha sozinho às só vale para sessão (com início e fim).");
        }
        if (!identificador_valido($ident)) {
            $erros[] = t("Identificador: minúsculas, números e _, começando por letra, até 40 (é o nome nas fórmulas: HORAS(\"{1}\"; 30)).", [1 => $ident !== "" ? $ident : "pulso"]);
        } elseif ((int)valor("SELECT COUNT(*) FROM lancamento_tipo WHERE identificador = ? AND id <> ?", [$ident, $id]) > 0) {
            $erros[] = t("Já existe um tipo de lançamento {1}.", [1 => $ident]);
        }
        if ($atual && $ident !== $atual["identificador"] && count(formulas_que_usam($atual["identificador"], "lancamentos")) > 0) {
            $erros[] = t("Não dá para renomear {1}: estas fórmulas usam: {2}.", [1 => $atual["identificador"], 2 => implode(", ", formulas_que_usam($atual["identificador"], "lancamentos"))]);
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = t("Dê um nome (até 120 caracteres).");
        }
        if (!isset($FORMATOS_LANCAMENTO[$formato])) {
            $erros[] = t("Formato: {1}.", [1 => implode(", ", array_keys($FORMATOS_LANCAMENTO))]);
        } elseif ($atual && $formato !== $atual["formato"] && $com_lancamentos > 0) {
            $erros[] = t("Não dá para mudar o formato: já há {1} lançamentos deste tipo.", [1 => $com_lancamentos]);
        }
        if ($no === false) {
            $erros[] = t("O ponto da árvore não existe.");
        }
        if (count($erros) === 0) {
            if ($acao === "novo") {
                sql("INSERT INTO lancamento_tipo (identificador, nome, formato, unidade, fecha_as, exclusiva, mede_gasto, marcha, winder, condicao, no_id, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$ident, $nome, $formato, $unidade, $fecha !== "" ? $fecha : null, $formato === "sessao" ? $exclusiva : 0, $formato === "valor" ? $mede_gasto : 0, $marcha !== "" ? $marcha : null,
                    $winder, $condicao !== "" ? $condicao : null, no_gravar($no),
                    (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM lancamento_tipo")]);
                $id = ultimo_id();
                $msg = t("Tipo de lançamento {1} criado, para {2}.", [1 => $nome, 2 => no_caminho($no)]);
            } else {
                sql("UPDATE lancamento_tipo SET identificador = ?, nome = ?, formato = ?, unidade = ?, fecha_as = ?, exclusiva = ?, mede_gasto = ?, marcha = ?, winder = ?, condicao = ?, no_id = ? WHERE id = ?",
                    [$ident, $nome, $formato, $unidade, $fecha !== "" ? $fecha : null, $formato === "sessao" ? $exclusiva : 0, $formato === "valor" ? $mede_gasto : 0, $marcha !== "" ? $marcha : null,
                    $winder, $condicao !== "" ? $condicao : null, no_gravar($no), $id]);
                $msg = t("Tipo de lançamento {1} salvo.", [1 => $nome]);
            }
            lancamento_tipos(true);
        }
    } elseif ($acao === "excluir") {
        $usam = formulas_que_usam($atual["identificador"], "lancamentos");
        if ($com_lancamentos > 0) {
            $erros[] = t("Não dá para excluir {1}: há {2} lançamentos deste tipo.", [1 => $atual["nome"], 2 => $com_lancamentos]);
        } elseif (count($usam) > 0) {
            $erros[] = t("Não dá para excluir {1}: estas fórmulas usam: {2}.", [1 => $atual["identificador"], 2 => implode(", ", $usam)]);
        } else {
            sql("DELETE FROM lancamento_tipo WHERE id = ?", [$id]);
            lancamento_tipos(true);
            $msg = t("Tipo de lançamento {1} excluído.", [1 => $atual["nome"]]);
        }
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Os totais (a aba Totais dos Cadastros; da árvore, presos à raiz dela). Ações: salvar (id: 0 cria; coluna: a chave da
// coluna da tabela dos relógios, ou vazio: nenhuma; nome; expressao: a expressão de total, conferida por total_validar;
// rodape e pagina: 1 ou 0, onde ele aparece (o rodapé só com coluna; pelo menos um dos dois); largura: estreito ou largo,
// o quadro na página de totais; formato: numero, inteiro, decimal, moeda, porcentagem, data ou dias), excluir (id), subir
// e descer (id: troca com o vizinho na ordem, que é a ordem embaixo de cada coluna e dos quadros da página)
// ---------------------------------------------------------------------------------------------------------------------
function op_totais($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM total WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Total não encontrado.");
    } elseif (in_array($acao, ["excluir", "subir", "descer"], true) && !$atual) {
        $erros[] = t("Total não encontrado.");
    } elseif ($acao === "salvar") {
        $coluna = array_key_exists("coluna", $d) ? trim((string)$d["coluna"]) : (string)($atual["coluna"] ?? "");
        $coluna = $coluna === "" ? null : $coluna;
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $expr = trim((string)($d["expressao"] ?? ($atual["expressao"] ?? "")));
        $rodape = array_key_exists("rodape", $d) ? ((string)$d["rodape"] === "1" ? 1 : 0) : (int)($atual["rodape"] ?? 0);
        $pagina = array_key_exists("pagina", $d) ? ((string)$d["pagina"] === "1" ? 1 : 0) : (int)($atual["pagina"] ?? 1);
        $largura = (string)($d["largura"] ?? ($atual["largura"] ?? "estreito"));
        $formato = (string)($d["formato"] ?? ($atual["formato"] ?? "numero"));
        if ($coluna !== null && !isset(TOTAL_COLUNAS[$coluna])) {
            $erros[] = t("Coluna: {1}, ou vazio (nenhuma: só na página de totais).", [1 => implode(", ", array_keys(TOTAL_COLUNAS))]);
        }
        if ($nome === "" || mb_strlen($nome, "UTF-8") > 120) {
            $erros[] = t("Dê um nome ao total (até 120 caracteres).");
        }
        if ($expr === "") {
            $erros[] = t("Escreva a expressão do total.");
        } else {
            foreach (total_validar($expr) as $e) {
                $erros[] = t("Expressão: {1}.", [1 => $e]);
            }
        }
        if ($rodape === 1 && $coluna === null) {
            $erros[] = t("Só os totais de uma coluna vão para o rodapé: escolha a coluna, ou deixe só na página de totais.");
        }
        if ($rodape === 0 && $pagina === 0) {
            $erros[] = t("Marque onde o total aparece: no rodapé da tabela, na página de totais ou nos dois.");
        }
        if (!in_array($largura, ["estreito", "largo"], true)) {
            $erros[] = t("Largura: estreito ou largo.");
        }
        if (!isset(TOTAL_FORMATOS[$formato])) {
            $erros[] = t("Formato: {1}.", [1 => implode(", ", array_keys(TOTAL_FORMATOS))]);
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE total SET coluna = ?, nome = ?, expressao = ?, rodape = ?, pagina = ?, largura = ?, formato = ? WHERE id = ?",
                    [$coluna, $nome, $expr, $rodape, $pagina, $largura, $formato, $id]);
                $msg = t("Total {1} salvo.", [1 => $nome]);
            } else {
                $ordem = (int)(valor("SELECT MAX(ordem) FROM total") ?? 0) + 10;
                sql("INSERT INTO total (no_id, coluna, nome, expressao, rodape, pagina, largura, formato, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [no_raiz(), $coluna, $nome, $expr, $rodape, $pagina, $largura, $formato, $ordem]);
                $id = ultimo_id();
                $msg = t("Total {1} criado.", [1 => $nome]);
            }
        }
    } elseif ($acao === "excluir") {
        sql("DELETE FROM total WHERE id = ?", [$id]);
        $msg = t("Total {1} excluído.", [1 => $atual["nome"]]);
    } elseif ($acao === "subir" || $acao === "descer") {
        // a ordem refeita de 10 em 10, e o total troca de lugar com o vizinho
        $ids = array_map("intval", array_column(linhas("SELECT id FROM total ORDER BY ordem, id"), "id"));
        $pos = array_search($id, $ids, true);
        $outro = $acao === "subir" ? $pos - 1 : $pos + 1;
        if ($outro >= 0 && $outro < count($ids)) {
            [$ids[$pos], $ids[$outro]] = [$ids[$outro], $ids[$pos]];
        }
        foreach ($ids as $i => $x) {
            sql("UPDATE total SET ordem = ? WHERE id = ?", [($i + 1) * 10, $x]);
        }
        $msg = t("Ordem dos totais salva.");
    } else {
        $erros[] = t("Ação desconhecida: {1}.", [1 => $acao]);
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Os calibres (a aba Calibres dos Cadastros; da árvore, presos à raiz dela). Ações: salvar (id: 0 cria; nome, fabricante,
// link: o endereço do manual técnico, http:// ou https://; observacao: as recomendações; valores: {identificador: valor}
// dos campos do cadastro que o calibre define, vazio tira; só muda o que vier), excluir (id: os relógios que apontam para
// ele ficam sem calibre; os documentos dele saem, com os arquivos)
// ---------------------------------------------------------------------------------------------------------------------
function op_calibres($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM calibre WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Calibre não encontrado.");
    } elseif ($acao === "excluir" && !$atual) {
        $erros[] = t("Calibre não encontrado.");
    } elseif ($acao === "salvar") {
        $texto = function ($k, $max) use ($d, $atual) {
            $v = array_key_exists($k, $d) ? trim((string)$d[$k]) : (string)($atual[$k] ?? "");
            return [$v === "" ? null : $v, mb_strlen($v, "UTF-8") <= $max];
        };
        [$nome, $nome_ok] = $texto("nome", 80);
        [$fabricante, $fab_ok] = $texto("fabricante", 80);
        [$link, $link_ok] = $texto("link", 500);
        [$obs, $obs_ok] = $texto("observacao", 4000);
        if ($nome === null || !$nome_ok) {
            $erros[] = t("Dê um nome ao calibre (até 80 caracteres).");
        } elseif (valor("SELECT id FROM calibre WHERE nome = ? AND id <> ?", [$nome, $id]) !== null) {
            $erros[] = t("Já existe o calibre {1}.", [1 => $nome]);
        }
        if (!$fab_ok) {
            $erros[] = t("Fabricante: até 80 caracteres.");
        }
        if (!$link_ok || ($link !== null && preg_match("~^https?://\\S+$~i", $link) !== 1)) {
            $erros[] = t("Link do manual técnico: um endereço http:// ou https:// (até 500 caracteres), ou vazio.");
        }
        if (!$obs_ok) {
            $erros[] = t("Observação: até 4000 caracteres.");
        }
        // os valores: só dos campos do cadastro (os da lista de desejos não são do movimento)
        $campos = array_filter(campos_todos(), function ($c) { return (int)($c["so_desejado"] ?? 0) !== 1; });
        $gravar = [];
        foreach ((array)($d["valores"] ?? []) as $ident => $v) {
            if (!isset($campos[$ident])) {
                $erros[] = t("Campo desconhecido: {1}.", [1 => $ident]);
                continue;
            }
            $conf = valor_para_campo($campos[$ident], $v);
            if ($conf[1] !== "") {
                $erros[] = $campos[$ident]["nome"] . ": " . $conf[1];
            } else {
                $gravar[(int)$campos[$ident]["id"]] = [$campos[$ident], $conf[0]];
            }
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE calibre SET nome = ?, fabricante = ?, link = ?, observacao = ? WHERE id = ?", [$nome, $fabricante, $link, $obs, $id]);
                $msg = t("Calibre {1} salvo.", [1 => $nome]);
            } else {
                sql("INSERT INTO calibre (no_id, nome, fabricante, link, observacao) VALUES (?, ?, ?, ?, ?)", [no_raiz(), $nome, $fabricante, $link, $obs]);
                $id = ultimo_id();
                $msg = t("Calibre {1} criado.", [1 => $nome]);
            }
            foreach ($gravar as $cid => [$campo_g, $v]) {
                valor_gravar("calibre", $id, $campo_g, $v);
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        }
    } elseif ($acao === "excluir") {
        $n = (int)valor("SELECT COUNT(*) FROM relogio_calibre WHERE calibre_id = ?", [$id]);
        sql("DELETE FROM calibre WHERE id = ?", [$id]);
        calibres_apagar_arquivos([$id]);
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $msg = t("Calibre {1} excluído.", [1 => $atual["nome"]]) . ($n > 0 ? " " . ($n === 1 ? t("O relógio que tinha esse calibre ficou sem.") : t("Os {1} relógios que tinham esse calibre ficaram sem.", [1 => $n])) : "");
        $id = 0;
    } else {
        $erros[] = t("Ação desconhecida: use salvar ou excluir.");
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
        $erros[] = t("Fórmula não encontrada.");
    } elseif ($acao === "nova" || $acao === "alterar") {
        $ident = $atual ? $atual["identificador"] : trim((string)($d["identificador"] ?? ""));
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $expr = trim((string)($d["expressao"] ?? ($atual["expressao"] ?? "")));
        $unidade = trim((string)($d["unidade"] ?? ($atual["unidade"] ?? "")));
        if (($erro_u = unidade_conferir($unidade)) !== "") {
            $erros[] = $erro_u;
        }
        $no = array_key_exists("no_id", $d) ? no_do_pedido($d["no_id"]) : ($atual ? ($atual["no_id"] === null ? null : (int)$atual["no_id"]) : null);
        if (!identificador_valido($ident)) {
            $erros[] = t("Identificador: minúsculas, números e _, começando por letra, até 40 (é o nome da fórmula nas outras fórmulas).");
        } elseif (isset(campos_todos()[$ident])) {
            $erros[] = t("Já existe um campo com o identificador {1}.", [1 => $ident]);
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = t("Dê um nome à fórmula (até 120 caracteres).");
        }
        if ($no === false) {
            $erros[] = t("O ponto da árvore não existe.");
        } elseif ((int)valor("SELECT COUNT(*) FROM formula WHERE identificador = ? AND no_id <=> ? AND id <> ?", [$ident, $no, $id]) > 0) {
            $erros[] = t("A fórmula {1} já tem uma versão em {2}: altere aquela.", [1 => $ident, 2 => no_caminho($no)]);
        }
        if ($expr === "") {
            $erros[] = t("Escreva o cálculo.");
        } else {
            foreach (formula_validar($ident, $expr) as $e) {
                $erros[] = t("Cálculo: {1}.", [1 => $e]);
            }
        }
        if (count($erros) === 0) {
            if ($acao === "nova") {
                sql("INSERT INTO formula (identificador, nome, expressao, unidade, no_id) VALUES (?, ?, ?, ?, ?)", [$ident, $nome, $expr, $unidade, no_gravar($no)]);
                $id = ultimo_id();
                $msg = t("Fórmula {1} gravada para {2}.", [1 => $ident, 2 => no_caminho($no)]);
            } else {
                sql("UPDATE formula SET nome = ?, expressao = ?, unidade = ?, no_id = ? WHERE id = ?", [$nome, $expr, $unidade, no_gravar($no), $id]);
                $msg = t("Fórmula {1} ({2}) salva.", [1 => $ident, 2 => no_caminho($no)]);
            }
            formulas_todas(true);
        }
    } elseif ($acao === "excluir") {
        $versoes = count(formulas_todas()[$atual["identificador"]] ?? []);
        $usam = array_filter(formulas_que_usam($atual["identificador"], "variaveis"), function ($x) use ($atual) {
            return strpos($x, $atual["identificador"] . " (") !== 0;
        });
        if ($versoes <= 1 && count($usam) > 0) {
            $erros[] = t("Não dá para excluir {1}: estas fórmulas usam: {2}.", [1 => $atual["identificador"], 2 => implode(", ", $usam)]);
        } else {
            sql("DELETE FROM formula WHERE id = ?", [$id]);
            formulas_todas(true);
            $msg = t("A versão de {1} para {2} foi excluída.{3}", [1 => $atual["identificador"], 2 => no_caminho($atual["no_id"]), 3 => $versoes > 1 ? t(" Os relógios dali passam a usar a versão de cima.") : ""]);
        }
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Relógios. Ações: salvar (id: 0 ou ausente cria; nome, no_id, disponivel (1 ou 0), copia_banco (1 ou 0: a cópia no banco
// de todos os documentos dele), calibre_id (o calibre dele; 0 ou vazio tira), valores[identificador]: só muda o que vier no
// pedido; valor vazio apaga; valor inválido fica o que estava, e a mensagem diz quais), excluir (id), foto
// (id, foto_base64: JPEG, PNG ou WebP, até 4 MB), remover_foto (id). Os documentos do relógio: op_documentos.
// ---------------------------------------------------------------------------------------------------------------------
function op_relogio($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Relógio não encontrado.");
    } elseif ($acao === "salvar") {
        $nome = array_key_exists("nome", $d) ? trim((string)$d["nome"]) : ($atual["nome"] ?? "");
        $no = array_key_exists("no_id", $d) ? no_do_pedido($d["no_id"]) : ($atual ? ($atual["no_id"] === null ? null : (int)$atual["no_id"]) : null);
        $disp = array_key_exists("disponivel", $d) ? ((string)$d["disponivel"] === "1" ? 1 : 0) : ($atual ? (int)$atual["disponivel"] : 1);
        $copia = array_key_exists("copia_banco", $d) ? ((string)$d["copia_banco"] === "1" ? 1 : 0) : ($atual ? (int)$atual["copia_banco"] : 0);
        // a situação na coleção (um item da lista relogio_situacao, pelo identificador; o que ela faz é o comportamento dele):
        // fora da coleção o relógio fica indisponível; voltando para ela sem dizer o disponível, ele volta disponível
        $posse_antes = $atual ? posse_de($atual) : null;
        $posse = array_key_exists("posse", $d) ? trim((string)$d["posse"]) : ($atual ? (string)$atual["posse"] : (lista_com_comportamento("relogio_situacao", "colecao")["identificador"] ?? "colecao"));
        $item_posse = lista_itens("relogio_situacao")[$posse] ?? null;
        $comp = $item_posse["comportamento"] ?? null;
        $nota_posse = "";
        if ($item_posse === null) {
            $erros[] = t("Situação na coleção: {1}.", [1 => implode(", ", array_keys(lista_itens("relogio_situacao")))]);
        } elseif ($comp !== "colecao") {
            if ($disp === 1 && array_key_exists("disponivel", $d) && $posse_antes !== null) {
                $nota_posse = t(" Fora da coleção, ele fica indisponível para o rodízio.");
            }
            $disp = 0;
        } elseif ($posse_antes !== null && $posse_antes !== "colecao") {
            if (!array_key_exists("disponivel", $d)) {
                $disp = 1;
            }
            $nota_posse = t(" Entrou na coleção{1}", [1 => $disp === 1 ? t(": disponível para o rodízio.") : t(" (indisponível para o rodízio, como pedido).")]);
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = t("Dê um nome ao relógio (até 120 caracteres).");
        }
        if ($no === false) {
            $erros[] = t("O ponto da árvore não existe.");
        }
        // o calibre (o movimento): calibre_id, um da árvore; 0 ou vazio tira
        $calibre = array_key_exists("calibre_id", $d) ? (int)$d["calibre_id"] : null;
        if ($calibre !== null && $calibre > 0 && valor("SELECT id FROM calibre WHERE id = ?", [$calibre]) === null) {
            $erros[] = t("O calibre não existe.");
        }
        // em manutenção, ele fica indisponível até ela encerrar: a disponibilidade pedida é a da volta
        $em_manut = $atual ? manutencao_aberta($id) : null;
        if ($em_manut !== null && count($erros) === 0) {
            if ((int)$em_manut["disponivel_antes"] !== $disp) {
                sql("UPDATE manutencao SET disponivel_antes = ? WHERE id = ?", [$disp, (int)$em_manut["id"]]);
            }
            if ($disp === 1 || (int)$em_manut["disponivel_antes"] !== $disp) {
                $nota_posse .= $disp === 1 ? t(" Em manutenção: ele volta disponível para o rodízio quando ela encerrar.") : t(" Em manutenção: ele volta indisponível para o rodízio quando ela encerrar.");
            }
            $disp = 0;
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE relogio SET nome = ?, no_id = ?, disponivel = ?, copia_banco = ?, posse = ? WHERE id = ?", [$nome, no_gravar($no), $disp, $copia, $posse, $id]);
            } else {
                sql("INSERT INTO relogio (colecao_id, nome, no_id, disponivel, copia_banco, posse, criado) VALUES (?, ?, ?, ?, ?, ?, NOW())", [colecao_atual(), $nome, no_gravar($no), $disp, $copia, $posse]);
                $id = ultimo_id();
            }
            relogio_copia_banco($id, true);
            // saiu da coleção: os dias dele no plano, de amanhã em diante, saem e são sorteados de novo (hoje fica: troque pelo
            // "trocar por…" ou pelo "Sortear de novo")
            if ($posse_antes === "colecao" && $comp !== "colecao" && $em_manut === null) {
                $nota_posse .= relogio_sai_do_rodizio($id, false);
            }
            // os valores: só dos campos que valem para o relógio (no ponto e na situação em que ficou)
            $campos = campos_do_relogio(["no_id" => $no, "posse" => $posse]);
            $ignorados = [];
            foreach ((array)($d["valores"] ?? []) as $ident => $v) {
                if (!isset($campos[$ident])) {
                    $ignorados[] = $ident . (isset(campos_do_relogio(["no_id" => $no, "posse" => "desejado"])[$ident]) ? t(" (só nos desejados)") : t(" (não é campo de {1})", [1 => no_caminho($no)]));
                } else {
                    $conf = valor_para_campo($campos[$ident], $v);
                    if ($conf[1] !== "") {
                        $ignorados[] = $campos[$ident]["nome"] . " (" . $conf[1] . ")";
                    } else {
                        valor_gravar("relogio", $id, $campos[$ident], $conf[0]);
                    }
                }
            }
            if ($calibre !== null) {
                sql("DELETE FROM relogio_calibre WHERE relogio_id = ?", [$id]);
                if ($calibre > 0) {
                    sql("INSERT INTO relogio_calibre (relogio_id, calibre_id) VALUES (?, ?)", [$id, $calibre]);
                }
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            // a marca da cópia no banco mudou: o cron copia (ou tira) os documentos dele aos poucos
            $aviso_copia = "";
            if ($atual && $copia !== (int)$atual["copia_banco"] && (int)valor("SELECT COUNT(*) FROM documento WHERE relogio_id = ?", [$id]) > 0) {
                $sistema = documentos_copia_sistema();
                $aviso_copia = $sistema !== null ? t(" (A cópia no banco dos documentos não muda: a Configuração da coleção decide por todos os relógios, {1}.)", [1 => $sistema ? t("todos os arquivos") : t("nenhum arquivo")])
                    : ($copia === 1 ? t(" Os documentos dele vão para o banco aos poucos, pelo cron.") : t(" As cópias no banco dos documentos dele que não pedem a própria cópia saem aos poucos, pelo cron (os arquivos continuam na pasta)."));
            }
            $msg = ($atual ? t("Relógio {1} salvo.", [1 => $nome]) : t("Relógio {1} criado {2}{3}.", [1 => $nome, 2 => $no === null ? t("na raiz da árvore") : "em " . no_caminho($no), 3 => $comp !== "colecao" ? " (" . mb_strtolower($item_posse["nome"], "UTF-8") . ")" : ""])) . $nota_posse . $aviso_copia
                . (count($ignorados) > 0 ? t(" Não gravei: {1}. Ficou o que já estava.", [1 => implode("; ", $ignorados)]) : "");
        }
    } elseif ($acao === "excluir" && $atual) {
        // os arquivos dos documentos saem da pasta, e a pasta do relógio (r<id>) inteira (as linhas saem com o relógio, pelo banco)
        foreach (linhas("SELECT arquivo, miniatura FROM documento WHERE relogio_id = ?", [$id]) as $doc) {
            documento_apagar_arquivos($doc);
        }
        $n_aces = (int)valor("SELECT COUNT(*) FROM acessorio WHERE relogio_id = ?", [$id]);
        sql("DELETE FROM relogio WHERE id = ?", [$id]);
        relogios_apagar_arquivos([$id]);
        $msg = t("Relógio {1} excluído, com os valores, a foto, os documentos e os lançamentos dele.{2}", [1 => $atual["nome"], 2 => $n_aces > 0 ? " " . ($n_aces === 1 ? t("O acessório dele ficou solto.") : t("Os {1} acessórios dele ficaram soltos.", [1 => $n_aces])) : ""]);
        $id = 0;
    } elseif ($acao === "foto" && $atual) {
        $b64 = (string)($d["foto_base64"] ?? "");
        if (strpos($b64, "base64,") !== false) {
            $b64 = substr($b64, strpos($b64, "base64,") + 7);
        }
        $bin = base64_decode($b64, true);
        $info = $bin !== false && strlen($bin) > 0 && strlen($bin) <= 4 * 1024 * 1024 ? @getimagesizefromstring($bin) : false;
        if (!$info || !in_array($info["mime"], ["image/jpeg", "image/png", "image/webp"], true)) {
            $erros[] = t("A foto não foi aceita: tem de ser JPEG, PNG ou WebP, de até 4 MB.");
        } else {
            sql("REPLACE INTO foto (relogio_id, tipo, dados, atualizado) VALUES (?, ?, ?, NOW())", [$id, $info["mime"], $bin]);
            $msg = t("Foto de {1} salva.", [1 => $atual["nome"]]);
        }
    } elseif ($acao === "remover_foto" && $atual) {
        sql("DELETE FROM foto WHERE relogio_id = ?", [$id]);
        $msg = t("Foto de {1} removida.", [1 => $atual["nome"]]);
    } elseif ($id === 0) {
        $erros[] = t("Informe o relógio (id).");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Documentos de um relógio, de um calibre ou de um winder. Ações: enviar (relogio_id, ou calibre_id: o manual técnico, a
// ficha de serviço, as fotos do movimento, que aparecem em todos os relógios com ele, ou winder_id: o manual, a nota, as
// fotos do winder; categoria_id, titulo, data AAAA-MM-DD, descricao e os arquivos: no
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
        $erros[] = t("Não dá para guardar documentos: {1}.", [1 => documentos_pasta_erro()]);
        return resultado($erros, $msg, ["id" => $id, "ids" => []]);
    }
    // a categoria do pedido (ou a de antes) e as famílias que ela aceita
    $cat_id = array_key_exists("categoria_id", $d) ? (int)$d["categoria_id"] : ($atual ? (int)$atual["categoria_id"] : 0);
    $cat = documento_categoria_linha($cat_id);
    $data = array_key_exists("data", $d) ? trim((string)$d["data"]) : ($atual ? (string)$atual["data"] : "");
    $data = $data === "" ? null : substr($data, 0, 10);
    if ($data !== null && (preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $data) !== 1 || !checkdate((int)substr($data, 5, 2), (int)substr($data, 8, 2), (int)substr($data, 0, 4)))) {
        $erros[] = t("A data tem de ser AAAA-MM-DD (ou vazia).");
    }
    $titulo = trim((string)($d["titulo"] ?? ($atual["titulo"] ?? "")));
    // a cópia no banco pedida pelo próprio arquivo (o sistema e o relógio, se pedem, valem por cima dela)
    $copia = array_key_exists("copia_banco", $d) ? ((string)$d["copia_banco"] === "1" ? 1 : 0) : ($atual ? (int)$atual["copia_banco"] : 0);
    $descricao = array_key_exists("descricao", $d) ? trim((string)$d["descricao"]) : ($atual ? (string)$atual["descricao"] : "");
    if (mb_strlen($titulo) > 200) {
        $erros[] = t("O título vai até 200 caracteres.");
    }
    $ids = [];
    if ($acao === "enviar") {
        // o dono: o relógio, o calibre ou o winder (um dos três); a pasta dele: r<id>, c<id> ou w<id>
        $donos_d = ((int)($d["relogio_id"] ?? 0) > 0 ? 1 : 0) + ((int)($d["calibre_id"] ?? 0) > 0 ? 1 : 0) + ((int)($d["winder_id"] ?? 0) > 0 ? 1 : 0);
        if ($donos_d > 1) {
            $r = null;
            $sub = "";
            $erros[] = t("O documento é de um relógio, de um calibre ou de um winder: mande só um dos três.");
        } elseif ((int)($d["winder_id"] ?? 0) > 0) {
            $r = linha("SELECT id, nome FROM winder WHERE id = ?", [(int)$d["winder_id"]]);
            $sub = $r ? "w" . (int)$r["id"] : "";
            if (!$r) {
                $erros[] = t("Winder não encontrado.");
            }
        } elseif ((int)($d["calibre_id"] ?? 0) > 0) {
            $r = linha("SELECT id, nome FROM calibre WHERE id = ?", [(int)$d["calibre_id"]]);
            $sub = $r ? "c" . (int)$r["id"] : "";
            if (!$r) {
                $erros[] = t("Calibre não encontrado.");
            }
        } else {
            $r = linha("SELECT id, nome FROM relogio WHERE id = ?", [(int)($d["relogio_id"] ?? 0)]);
            $sub = $r ? "r" . (int)$r["id"] : "";
            if (!$r) {
                $erros[] = t("Relógio não encontrado.");
            }
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
                $erros[] = t("O arquivo_base64 não é base64.");
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
        $nomes_fam = ["imagem" => t("imagens"), "video" => t("vídeos"), "audio" => t("áudios"), "pdf" => "PDF", "xml" => "XML"];
        $avisos = [];
        $copiados_banco = 0;
        if (count($erros) === 0) {
            foreach ($arquivos as $k => $a) {
                list($nome, $tmp, $bin, $erro) = $a;
                $nome = trim(preg_replace("/[\\x00-\\x1f]/", "", basename(str_replace("\\", "/", $nome))));
                $nome = $nome !== "" ? mb_substr($nome, 0, 255) : "arquivo";
                if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
                    $erros[] = t("{1}: passou do tamanho que o servidor aceita (o upload_max_filesize do php.ini{2}).", [1 => $nome, 2 => $limite !== null ? ", " . tamanho_texto($limite) : ""]);
                    continue;
                }
                if ($erro !== UPLOAD_ERR_OK || ($tmp !== null && !is_uploaded_file($tmp))) {
                    $erros[] = t("{1}: não chegou inteiro (erro {2} no envio). Tente de novo.", [1 => $nome, 2 => $erro]);
                    continue;
                }
                $tamanho = $tmp !== null ? (int)filesize($tmp) : strlen($bin);
                if ($tamanho === 0) {
                    $erros[] = t("{1}: o arquivo está vazio.", [1 => $nome]);
                    continue;
                }
                if ($limite !== null && $tamanho > $limite) {
                    $erros[] = t("{1}: tem {2}; o servidor aceita até {3}.", [1 => $nome, 2 => tamanho_texto($tamanho), 3 => tamanho_texto($limite)]);
                    continue;
                }
                $arquivo = documento_novo_arquivo($pasta, $sub, $nome);
                $ok = $tmp !== null ? @move_uploaded_file($tmp, $pasta . "/" . $arquivo) : @file_put_contents($pasta . "/" . $arquivo, $bin) === $tamanho;
                if (!$ok) {
                    $erros[] = t("{1}: não consegui gravar na pasta dos documentos.", [1 => $nome]);
                    continue;
                }
                $tipo = documento_tipo($pasta . "/" . $arquivo, $nome);
                $familia = documento_familia($tipo, $nome);
                if (count($aceita) > 0 && !in_array($familia, $aceita, true)) {
                    @unlink($pasta . "/" . $arquivo);
                    $erros[] = t("{1}: a categoria {2} aceita só {3}.", [1 => $nome, 2 => $cat["nome"], 3 => implode(", ", array_map(function ($f) use ($nomes_fam) { return $nomes_fam[$f] ?? $f; }, $aceita))]);
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
                    sql("INSERT INTO documento (relogio_id, calibre_id, winder_id, categoria_id, titulo, data, descricao, nome, tipo, tamanho, arquivo, miniatura, copia_banco, criado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [$sub[0] === "r" ? (int)$r["id"] : null, $sub[0] === "c" ? (int)$r["id"] : null, $sub[0] === "w" ? (int)$r["id"] : null, (int)$cat["id"], $t !== "" ? $t : $nome, $data, $descricao !== "" ? $descricao : null, $nome, $tipo, $tamanho, $arquivo, $arq_mini, $copia]);
                    $ids[] = ultimo_id();
                    // a cópia de segurança no banco, em pedaços, se alguém a pede; se falhar, o documento fica (o cron tenta de novo)
                    $novo_doc = linha("SELECT * FROM documento WHERE id = ?", [end($ids)]);
                    if (documento_copia_por($novo_doc) !== null) {
                        $copiados_banco++;
                        $erro_copia = documento_copiar_para_banco($novo_doc);
                        if ($erro_copia !== "") {
                            $avisos[] = t("{1}: a cópia no banco falhou ({2}); o cron tenta de novo", [1 => $nome, 2 => $erro_copia]);
                        }
                    }
                } catch (BancoErro $e) {
                    documento_apagar_arquivos(["arquivo" => $arquivo, "miniatura" => $arq_mini]);
                    $erros[] = t("{1}: o banco recusou ({2}).", [1 => $nome, 2 => $e->getMessage()]);
                }
            }
            if (count($ids) > 0) {
                $msg = t("{1} em {2} do {3}{4}{5}", [1 => count($ids) === 1 ? t("1 documento guardado") : t("{1} documentos guardados", [1 => count($ids)]), 2 => $cat["nome"], 3 => $r["nome"], 4 => $copiados_banco > 0 ? t(", na pasta e no banco.") : t(", só na pasta (sem cópia no banco)."), 5 => count($avisos) > 0 ? t(" Atenção: {1}.", [1 => implode("; ", $avisos)]) : ""]);
                $id = $ids[0];
            }
        }
    } elseif ($acao === "alterar" || $acao === "excluir") {
        if (!$atual) {
            $erros[] = t("Documento não encontrado.");
        } elseif ($acao === "excluir") {
            documento_apagar_arquivos($atual);
            sql("DELETE FROM documento WHERE id = ?", [$id]);
            $msg = t("Documento {1} excluído.", [1 => $atual["titulo"]]);
        } else {
            if (!$cat) {
                $erros[] = t("A categoria não existe.");
            } elseif ((int)$cat["id"] !== (int)$atual["categoria_id"] && count(documento_familias_aceitas($cat["aceita"])) > 0
                && !in_array(documento_familia($atual["tipo"], $atual["nome"]), documento_familias_aceitas($cat["aceita"]), true)) {
                $erros[] = t("A categoria {1} não aceita este tipo de arquivo ({2}).", [1 => $cat["nome"], 2 => $atual["tipo"]]);
            }
            if ($titulo === "") {
                $erros[] = t("Dê um título ao documento.");
            }
            if (count($erros) === 0) {
                sql("UPDATE documento SET categoria_id = ?, titulo = ?, data = ?, descricao = ?, copia_banco = ? WHERE id = ?",
                    [(int)$cat["id"], $titulo, $data, $descricao !== "" ? $descricao : null, $copia, $id]);
                $msg = t("Documento {1} salvo.", [1 => $titulo]);
                // a cópia no banco segue o pedido na hora: entra se agora alguém pede, sai se ninguém mais pede
                $doc = linha("SELECT * FROM documento WHERE id = ?", [$id]);
                $por = documento_copia_por($doc);
                if ($por !== null && (int)$doc["no_banco"] !== 1) {
                    $erro_copia = documento_copiar_para_banco($doc);
                    $msg .= $erro_copia === "" ? t(" A cópia foi para o banco.") : t(" A cópia no banco falhou ({1}); o cron tenta de novo.", [1 => $erro_copia]);
                } elseif ($por === null && (int)$doc["no_banco"] === 1) {
                    $erro_copia = documento_tirar_do_banco($doc);
                    $msg .= $erro_copia === "" ? t(" A cópia saiu do banco (o arquivo continua na pasta).") : t(" A cópia continua no banco: {1}.", [1 => $erro_copia]);
                } elseif ($copia === 1 && $por === null) {
                    $msg .= t(" A cópia não vai para o banco: a Configuração da coleção (Cópia dos documentos no banco: nenhum) não deixa.");
                }
            }
        }
    } else {
        $erros[] = t("Ação desconhecida: use enviar, alterar ou excluir.");
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
        $erros[] = t("Categoria não encontrada.");
    } elseif ($acao === "nova" || $acao === "alterar") {
        $ident = $atual ? $atual["identificador"] : trim((string)($d["identificador"] ?? ""));
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $aceita = array_key_exists("aceita", $d) ? (is_array($d["aceita"]) ? $d["aceita"] : documento_familias_aceitas($d["aceita"])) : documento_familias_aceitas($atual["aceita"] ?? "");
        $aceita = array_values(array_unique(array_filter(array_map("trim", $aceita), function ($x) { return $x !== ""; })));
        $ordem = array_key_exists("ordem", $d) && trim((string)$d["ordem"]) !== "" ? (int)$d["ordem"] : ($atual ? (int)$atual["ordem"] : (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM documento_categoria"));
        if (!identificador_valido($ident)) {
            $erros[] = t("Identificador: minúsculas, números e _, começando por letra, até 40.");
        } elseif (!$atual && valor("SELECT id FROM documento_categoria WHERE identificador = ?", [$ident]) !== null) {
            $erros[] = t("Já existe a categoria {1}.", [1 => $ident]);
        }
        if ($nome === "" || mb_strlen($nome) > 120) {
            $erros[] = t("Dê um nome à categoria (até 120 caracteres).");
        }
        foreach ($aceita as $f) {
            if (!in_array($f, ["imagem", "video", "audio", "pdf", "xml"], true)) {
                $erros[] = t("Tipo aceito desconhecido: {1} (use imagem, video, audio, pdf ou xml; nenhum: qualquer arquivo).", [1 => $f]);
            }
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE documento_categoria SET nome = ?, ordem = ? WHERE id = ?", [$nome, $ordem, $id]);
                documento_categoria_gravar_aceita($id, $aceita);
                $msg = t("Categoria {1} salva.", [1 => $nome]);
            } else {
                sql("INSERT INTO documento_categoria (identificador, nome, ordem, no_id) VALUES (?, ?, ?, ?)", [$ident, $nome, $ordem, no_raiz()]);
                $id = ultimo_id();
                documento_categoria_gravar_aceita($id, $aceita);
                $msg = t("Categoria {1} criada.", [1 => $nome]);
            }
        }
    } elseif ($acao === "excluir") {
        $n = (int)valor("SELECT COUNT(*) FROM documento WHERE categoria_id = ?", [$id]);
        if ($n > 0) {
            $erros[] = t("A categoria {1} tem {2}{3}: passe para outra categoria ou exclua antes.", [1 => $atual["nome"], 2 => $n, 3 => $n === 1 ? " documento" : " documentos"]);
        } else {
            sql("DELETE FROM documento_categoria WHERE id = ?", [$id]);
            $msg = t("Categoria {1} excluída.", [1 => $atual["nome"]]);
        }
    } else {
        $erros[] = t("Ação desconhecida: use nova, alterar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Acessórios. Ações: salvar (id: 0 ou ausente cria; nome, tipo (pulseira, caixa, estojo, ferramenta, carregador, peca, outro),
// relogio_id (0: solto), observacao; só muda o que vier), excluir (id).
// ---------------------------------------------------------------------------------------------------------------------
function op_acessorios($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM acessorio WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Acessório não encontrado.");
    } elseif ($acao === "salvar") {
        $nome = array_key_exists("nome", $d) ? trim((string)$d["nome"]) : (string)($atual["nome"] ?? "");
        $tipo = array_key_exists("tipo", $d) ? trim((string)$d["tipo"]) : (string)($atual["tipo"] ?? lista_padrao("acessorio_tipo", "pulseira"));
        $rel = array_key_exists("relogio_id", $d) ? (int)$d["relogio_id"] : ($atual && $atual["relogio_id"] !== null ? (int)$atual["relogio_id"] : 0);
        $obs = array_key_exists("observacao", $d) ? trim((string)$d["observacao"]) : (string)($atual["observacao"] ?? "");
        if ($nome === "" || mb_strlen($nome) > 120) {
            $erros[] = t("Dê um nome ao acessório (até 120 caracteres).");
        }
        if (!isset(lista_itens("acessorio_tipo")[$tipo])) {
            $erros[] = t("Tipo: {1}.", [1 => implode(", ", array_keys(lista_itens("acessorio_tipo")))]);
        }
        $r = $rel > 0 ? linha("SELECT id, nome FROM relogio WHERE id = ?", [$rel]) : null;
        if ($rel > 0 && !$r) {
            $erros[] = t("Relógio não encontrado (0: o acessório fica solto).");
        }
        if (mb_strlen($obs) > 1000) {
            $erros[] = t("A observação vai até 1000 caracteres.");
        }
        // os valores dos campos do acessório (os do tipo dele: Cadastros › Campos, para acessório); só muda o que vier
        $gravar_a = [];
        $campos_a = campos_de_acessorio(isset(lista_itens("acessorio_tipo")[$tipo]) ? $tipo : null);
        foreach ((array)($d["valores"] ?? []) as $ident => $v) {
            if (!isset($campos_a[$ident])) {
                $erros[] = t("Campo desconhecido: {1}.", [1 => $ident]);
                continue;
            }
            $conf = valor_para_campo($campos_a[$ident], $v);
            if ($conf[1] !== "") {
                $erros[] = $campos_a[$ident]["nome"] . ": " . $conf[1];
            } else {
                $gravar_a[] = [$campos_a[$ident], $conf[0]];
            }
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE acessorio SET nome = ?, tipo = ?, observacao = ? WHERE id = ?", [$nome, $tipo, $obs !== "" ? $obs : null, $id]);
            } else {
                sql("INSERT INTO acessorio (colecao_id, nome, tipo, observacao, criado) VALUES (?, ?, ?, ?, NOW())", [colecao_atual(), $nome, $tipo, $obs !== "" ? $obs : null]);
                $id = ultimo_id();
            }
            // o relógio do acessório (a ligação; 0: solto)
            ligacao_relogio("acessorio_relogio", "acessorio_id", $id, $rel > 0 ? $rel : null);
            foreach ($gravar_a as [$campo_a, $v]) {
                valor_gravar("acessorio", $id, $campo_a, $v);
            }
            // trocou de tipo: os valores dos campos que não são do tipo novo saem
            if ($atual && $atual["tipo"] !== $tipo) {
                foreach (campos_de_acessorio() as $c_a) {
                    if (!isset($campos_a[$c_a["identificador"]])) {
                        valor_gravar("acessorio", $id, $c_a, null);
                    }
                }
            }
            $msg = t("Acessório {1} ({2}){3}{4}.", [1 => $nome, 2 => mb_strtolower(lista_nome("acessorio_tipo", $tipo), "UTF-8"), 3 => $atual ? t(" salvo") : t(" cadastrado"), 4 => $r ? t(", do {1}", [1 => $r["nome"]]) : t(", solto (de nenhum relógio)")]);
        }
    } elseif ($acao === "excluir" && $atual) {
        sql("DELETE FROM acessorio WHERE id = ?", [$id]);
        $msg = t("Acessório {1} excluído.", [1 => $atual["nome"]]);
        $id = 0;
    } elseif ($acao === "excluir") {
        $erros[] = t("Informe o acessório (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Winders. Ações: salvar (id: 0 ou ausente cria; nome, observacao, valores[<identificador>]: os campos do winder, Cadastros ›
// Campos, para o winder: a marca, o modelo, o link do manual, os limites da almofada; e, só ao criar, posicoes: quantas
// posições ele já ganha, "Pos. 1", "Pos. 2"..., de 0 a 50; só muda o que vier), limite_ok (id, relogio_id: o aviso de limite
// da almofada daquele relógio neste winder não volta: "Já sei, deixa assim"), excluir (id: com as posições, os lugares e
// os documentos dele, também os arquivos; as sessões que ficaram nelas continuam, sem a posição).
// ---------------------------------------------------------------------------------------------------------------------
function op_winders($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM winder WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Winder não encontrado.");
    } elseif ($acao === "salvar") {
        $nome = array_key_exists("nome", $d) ? trim((string)$d["nome"]) : (string)($atual["nome"] ?? "");
        $obs = array_key_exists("observacao", $d) ? trim((string)$d["observacao"]) : (string)($atual["observacao"] ?? "");
        $n_pos = $atual ? 0 : trim((string)($d["posicoes"] ?? "0"));
        if ($nome === "" || mb_strlen($nome) > 120) {
            $erros[] = t("Dê um nome ao winder (até 120 caracteres).");
        }
        if (mb_strlen($obs) > 1000) {
            $erros[] = t("A observação vai até 1000 caracteres.");
        }
        if (!$atual && (!ctype_digit((string)$n_pos) || (int)$n_pos > 50)) {
            $erros[] = t("Posições: de 0 a 50 (as que o winder já ganha ao ser criado).");
        }
        // os valores dos campos do winder; só muda o que vier
        $gravar_w = [];
        $campos_w = campos_de_winder();
        foreach ((array)($d["valores"] ?? []) as $ident => $v) {
            if (!isset($campos_w[$ident])) {
                $erros[] = t("Campo desconhecido: {1}.", [1 => $ident]);
                continue;
            }
            $conf = valor_para_campo($campos_w[$ident], $v);
            if ($conf[1] !== "") {
                $erros[] = $campos_w[$ident]["nome"] . ": " . $conf[1];
            } else {
                $gravar_w[] = [$campos_w[$ident], $conf[0]];
            }
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE winder SET nome = ?, observacao = ? WHERE id = ?", [$nome, $obs !== "" ? $obs : null, $id]);
            } else {
                sql("INSERT INTO winder (colecao_id, nome, observacao, criado) VALUES (?, ?, ?, NOW())", [colecao_atual(), $nome, $obs !== "" ? $obs : null]);
                $id = ultimo_id();
                for ($i = 1; $i <= (int)$n_pos; $i++) {
                    sql("INSERT INTO winder_posicao (winder_id, nome, ordem, ligada) VALUES (?, ?, ?, 1)", [$id, t("Pos. {1}", [1 => $i]), $i]);
                }
            }
            foreach ($gravar_w as [$campo_w, $v]) {
                valor_gravar("winder", $id, $campo_w, $v);
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $msg = $atual ? t("Winder {1} salvo.", [1 => $nome])
                : t("Winder {1} cadastrado{2}.", [1 => $nome, 2 => (int)$n_pos > 0 ? t(", com {1} {2}", [1 => (int)$n_pos, 2 => (int)$n_pos === 1 ? t("posição") : t("posições")]) : ""]);
        }
    } elseif ($acao === "limite_ok" && $atual) {
        $r_l = linha("SELECT id, nome FROM relogio WHERE id = ?", [(int)($d["relogio_id"] ?? 0)]);
        if (!$r_l) {
            $erros[] = t("Relógio não encontrado (relogio_id).");
        } else {
            sql("DELETE FROM winder_limite_ok WHERE winder_id = ? AND relogio_id = ?", [$id, (int)$r_l["id"]]);
            sql("INSERT INTO winder_limite_ok (winder_id, relogio_id) VALUES (?, ?)", [$id, (int)$r_l["id"]]);
            $msg = t("Combinado: o aviso de limite do {1} no {2} não aparece mais.", [1 => $r_l["nome"], 2 => $atual["nome"]]);
        }
    } elseif ($acao === "excluir" && $atual) {
        // os arquivos dos documentos saem da pasta, e a pasta do winder (w<id>) inteira (as linhas saem com ele, pelo banco)
        foreach (linhas("SELECT arquivo, miniatura FROM documento WHERE winder_id = ?", [$id]) as $doc) {
            documento_apagar_arquivos($doc);
        }
        sql("DELETE FROM winder WHERE id = ?", [$id]);
        winders_apagar_arquivos([$id]);
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $msg = t("Winder {1} excluído.", [1 => $atual["nome"]]);
        $id = 0;
    } elseif ($acao === "excluir" || $acao === "limite_ok") {
        $erros[] = t("Informe o winder (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar, limite_ok ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Os movimentos dos programas dos winders (a página Movimentos). Ações: salvar (id: 0 ou ausente cria; nome, até 60; tipo:
// horario, anti_horario ou parado; a medida: voltas, ou o tempo em minutos e segundos; a parada só tem tempo, e o giro tem
// uma das duas, nunca as duas; só muda o que vier), excluir (id: o que está num programa não sai; tire-o da sequência antes).
// ---------------------------------------------------------------------------------------------------------------------
function op_movimentos($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM winder_movimento WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Movimento não encontrado.");
    } elseif ($acao === "salvar") {
        $nome = array_key_exists("nome", $d) ? trim((string)$d["nome"]) : (string)($atual["nome"] ?? "");
        $tipo = array_key_exists("tipo", $d) ? trim((string)$d["tipo"]) : (string)($atual["tipo"] ?? "");
        // a medida: as voltas, ou o tempo (minutos e segundos); o que vier troca a de antes
        $mede = array_key_exists("voltas", $d) || array_key_exists("minutos", $d) || array_key_exists("segundos", $d);
        $voltas = $mede ? numero_digitado((string)($d["voltas"] ?? "")) : ($atual && $atual["voltas"] !== null ? (float)$atual["voltas"] : null);
        $min = $mede ? numero_digitado((string)($d["minutos"] ?? "")) : null;
        $sg = $mede ? numero_digitado((string)($d["segundos"] ?? "")) : null;
        $segundos = $mede ? ($min === null && $sg === null ? null : (int)round((float)($min ?: 0) * 60 + (float)($sg ?: 0))) : ($atual && $atual["segundos"] !== null ? (int)$atual["segundos"] : null);
        if ($nome === "" || mb_strlen($nome) > 60) {
            $erros[] = t("Dê um nome ao movimento (até 60 caracteres).");
        }
        if (!isset(WINDER_MOVIMENTO_TIPOS[$tipo])) {
            $erros[] = t("Tipo: horario, anti_horario ou parado.");
        }
        if ($voltas === false || $min === false || $sg === false) {
            $erros[] = t("As voltas, os minutos e os segundos são números.");
        } elseif ($tipo === "parado") {
            if ($voltas !== null) {
                $erros[] = t("A parada não gira: diga só o tempo dela.");
            } elseif ($segundos === null || $segundos <= 0) {
                $erros[] = t("Diga quanto tempo a parada dura (minutos e segundos).");
            }
        } elseif (isset(WINDER_MOVIMENTO_TIPOS[$tipo])) {
            if ($voltas !== null && $segundos !== null) {
                $erros[] = t("O giro é em voltas ou em tempo, não nos dois: o outro o programa calcula pela velocidade do berço.");
            } elseif ($voltas === null && ($segundos === null || $segundos <= 0)) {
                $erros[] = t("Diga quantas voltas o giro dá, ou quanto tempo ele dura.");
            } elseif ($voltas !== null && $voltas <= 0) {
                $erros[] = t("As voltas do giro são mais que zero.");
            }
        }
        // nos programas que o usam, a conta tem de continuar fechando (a medida do giro é a do tipo de cadastro de cada um;
        // a sequência continua com um giro)
        if (count($erros) === 0 && $atual) {
            $novo = ["id" => $id, "nome" => $nome, "tipo" => $tipo, "voltas" => $tipo === "parado" ? null : $voltas, "segundos" => $tipo !== "parado" && $voltas !== null ? null : $segundos];
            foreach (linhas("SELECT DISTINCT g.* FROM winder_programa_passo s JOIN winder_programa g ON g.id = s.programa_id WHERE s.movimento_id = ? ORDER BY g.nome", [$id]) as $g) {
                $movs_g = array_map(function ($m) use ($novo, $id) { return (int)$m["id"] === $id ? $novo : $m; }, winder_programa_movimentos((int)$g["id"]));
                $c_g = winder_programa_calculo($g, $movs_g);
                if (count($c_g["erros"]) > 0) {
                    $erros[] = t("No programa {1}, que usa este movimento: {2}", [1 => $g["nome"], 2 => $c_g["erros"][0]]);
                }
            }
        }
        if (count($erros) === 0) {
            $v_g = $tipo === "parado" ? null : $voltas;
            $s_g = $v_g !== null ? null : $segundos;
            if ($atual) {
                sql("UPDATE winder_movimento SET nome = ?, tipo = ?, voltas = ?, segundos = ? WHERE id = ?", [$nome, $tipo, $v_g, $s_g, $id]);
            } else {
                sql("INSERT INTO winder_movimento (colecao_id, nome, tipo, voltas, segundos) VALUES (?, ?, ?, ?, ?)", [colecao_atual(), $nome, $tipo, $v_g, $s_g]);
                $id = (int)ultimo_id();
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $usado = (int)valor("SELECT COUNT(DISTINCT programa_id) FROM winder_programa_passo WHERE movimento_id = ?", [$id]);
            $msg = t("Movimento {1} {2}: {3}.", [1 => $nome, 2 => $atual ? t("salvo") : t("cadastrado"), 3 => winder_movimento_info(["id" => $id, "nome" => $nome, "tipo" => $tipo, "voltas" => $v_g, "segundos" => $s_g])["texto"]])
                . ($atual && $usado > 0 ? " " . ($usado === 1 ? t("Mudou também no programa que o usa.") : t("Mudou também nos {1} programas que o usam.", [1 => $usado])) : "");
        }
    } elseif ($acao === "excluir" && $atual) {
        $em = array_column(linhas("SELECT DISTINCT g.nome FROM winder_programa_passo s JOIN winder_programa g ON g.id = s.programa_id WHERE s.movimento_id = ? ORDER BY g.nome", [$id]), "nome");
        if (count($em) > 0) {
            $erros[] = t("O movimento {1} está na sequência de {2}: tire-o de lá antes de excluir.", [1 => $atual["nome"], 2 => implode(", ", $em)]);
        } else {
            sql("DELETE FROM winder_movimento WHERE id = ?", [$id]);
            $msg = t("Movimento {1} excluído.", [1 => $atual["nome"]]);
            $id = 0;
        }
    } elseif ($acao === "excluir") {
        $erros[] = t("Informe o movimento (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Os programas dos winders (a página Programas). Ações: salvar (id: 0 ou ausente cria; nome, até 80; medida: voltas ou tempo,
// o tipo de cadastro; velocidade: as voltas por minuto do berço, contadas, ou tpd_manual: as voltas por dia que o manual
// diz, de onde a velocidade sai; funciona: dia_todo, ou liga_horas e para_horas; observacao; sequencia: os códigos dos
// movimentos, na ordem, como "1, 2, 3, 2"; só muda o que vier; o programa só grava se a conta fecha: a sequência tem um
// giro, os giros estão na medida do programa), excluir (id: o que está numa posição não sai; troque o programa dela antes),
// montar (o ajudante: nome, opcional; tpd: as voltas por dia; velocidade; sentido: os_dois, horario ou anti_horario;
// medida: voltas ou tempo; cria os movimentos que faltam e o programa, para conferir e mudar).
// ---------------------------------------------------------------------------------------------------------------------
function op_programas($acao, $d)
{
    $erros = [];
    $msg = "";
    $extra = [];
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM winder_programa WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Programa não encontrado.");
    } elseif ($acao === "salvar") {
        [$p, $seq, $movs, $erros] = winder_programa_do_pedido($d, $atual);
        $calc = winder_programa_calculo($p, $movs);
        if (count($erros) === 0) {
            $erros = $calc["erros"];
        }
        if (count($erros) === 0) {
            $obs = $p["observacao"] !== "" ? $p["observacao"] : null;
            if ($atual) {
                sql("UPDATE winder_programa SET nome = ?, medida = ?, velocidade = ?, tpd_manual = ?, liga_horas = ?, para_horas = ?, observacao = ? WHERE id = ?",
                    [$p["nome"], $p["medida"], $p["velocidade"], $p["tpd_manual"], $p["liga_horas"], $p["para_horas"], $obs, $id]);
                sql("DELETE FROM winder_programa_passo WHERE programa_id = ?", [$id]);
            } else {
                sql("INSERT INTO winder_programa (colecao_id, nome, medida, velocidade, tpd_manual, liga_horas, para_horas, observacao, criado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                    [colecao_atual(), $p["nome"], $p["medida"], $p["velocidade"], $p["tpd_manual"], $p["liga_horas"], $p["para_horas"], $obs]);
                $id = (int)ultimo_id();
            }
            foreach ($seq as $k => $mid) {
                sql("INSERT INTO winder_programa_passo (programa_id, ordem, movimento_id) VALUES (?, ?, ?)", [$id, $k + 1, $mid]);
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $msg = t("Programa {1} {2}: {3}.", [1 => $p["nome"], 2 => $atual ? t("salvo") : t("cadastrado"), 3 => $calc["texto"]])
                . (count($calc["avisos"]) > 0 ? " " . implode(" ", $calc["avisos"]) : "");
            $extra["calculo"] = $calc;
        }
    } elseif ($acao === "montar") {
        $tpd = numero_digitado((string)($d["tpd"] ?? ""));
        $vel = numero_digitado((string)($d["velocidade"] ?? ""));
        $sentido = (string)($d["sentido"] ?? "os_dois");
        $medida = (string)($d["medida"] ?? "voltas");
        if (!is_float($tpd) || $tpd <= 0) {
            $erros[] = t("Diga as voltas por dia (tpd) que a sequência tem de dar.");
        }
        if (!is_float($vel) || $vel <= 0) {
            $erros[] = t("Diga a velocidade do berço (voltas por minuto): sem ela não dá para montar os giros.");
        }
        if (!in_array($sentido, ["os_dois", "horario", "anti_horario"], true)) {
            $erros[] = t("Sentido: os_dois, horario ou anti_horario.");
        }
        if (!isset(WINDER_PROGRAMA_MEDIDAS[$medida])) {
            $erros[] = t("Tipo de cadastro (medida): voltas ou tempo.");
        }
        $seq_m = null;
        if (count($erros) === 0) {
            [$seq_m, $erro_m] = winder_sequencia_para($tpd, $vel, $sentido, $medida);
            if ($erro_m !== null) {
                $erros[] = $erro_m;
            }
        }
        $nome = trim((string)($d["nome"] ?? ""));
        $nome = $nome !== "" ? $nome : t("{1} voltas por dia, {2}", [1 => numero_txt($tpd, 0), 2 => ["os_dois" => t("os dois sentidos"), "horario" => t("sentido horário"), "anti_horario" => t("sentido anti-horário")][$sentido] ?? ""]);
        if (mb_strlen($nome) > 80) {
            $erros[] = t("Dê um nome ao programa (até 80 caracteres).");
        }
        if (count($erros) === 0) {
            sql("INSERT INTO winder_programa (colecao_id, nome, medida, velocidade, tpd_manual, liga_horas, para_horas, observacao, criado) VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, NOW())",
                [colecao_atual(), $nome, $medida, $vel]);
            $id = (int)ultimo_id();
            foreach ($seq_m as $k => [$tipo, $voltas, $seg]) {
                sql("INSERT INTO winder_programa_passo (programa_id, ordem, movimento_id) VALUES (?, ?, ?)", [$id, $k + 1, winder_movimento_achar_ou_criar(colecao_atual(), $tipo, $voltas, $seg)]);
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $calc = winder_programa_calculado($id);
            $msg = t("Programa {1} montado: {2}. Confira a sequência e mude o que quiser.", [1 => $nome, 2 => $calc["texto"]]);
            $extra["calculo"] = $calc;
        }
    } elseif ($acao === "excluir" && $atual) {
        $em = array_map(function ($x) { return t("{1}, {2}", [1 => $x["winder"], 2 => $x["nome"]]); },
            linhas("SELECT p.nome, w.nome AS winder FROM winder_posicao_programa pp JOIN winder_posicao p ON p.id = pp.posicao_id JOIN winder w ON w.id = p.winder_id WHERE pp.programa_id = ? ORDER BY w.nome, p.ordem", [$id]));
        if (count($em) > 0) {
            $erros[] = t("O programa {1} está em {2}: troque o programa dessa posição antes de excluir.", [1 => $atual["nome"], 2 => implode("; ", $em)]);
        } else {
            sql("DELETE FROM winder_programa WHERE id = ?", [$id]);
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $msg = t("Programa {1} excluído (os movimentos dele continuam na página Movimentos).", [1 => $atual["nome"]]);
            $id = 0;
        }
    } elseif ($acao === "excluir") {
        $erros[] = t("Informe o programa (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar, montar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id] + $extra);
}

// ---------------------------------------------------------------------------------------------------------------------
// As posições dos winders. Ações: salvar (id: 0 ou ausente cria, com winder_id; nome (até 60, sem repetir no winder), ordem,
// ligada (1 ou 0: desligada não recebe relógio; com um relógio dentro, tire-o antes), programa_id (o programa da página
// Programas; 0 tira), relogio_id (o relógio que fica nela: 0 tira quem está; outro põe ou troca, e a posição passa a ser
// o lugar dele), valores[<identificador>]: os campos livres da posição, Cadastros › Campos, para a posição do winder; só
// muda o que vier), liberar (id: a posição deixa de ser o lugar de quem era; só vazia), excluir (id: as sessões que
// ficaram nela continuam, sem a posição; a conta delas volta à de sempre).
// ---------------------------------------------------------------------------------------------------------------------
function op_winder_posicoes($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT p.*, w.nome AS winder FROM winder_posicao p JOIN winder w ON w.id = p.winder_id WHERE p.id = ?", [$id]) : null;
    $agora_t = date("Y-m-d H:i:s");
    if ($id > 0 && !$atual) {
        $erros[] = t("Posição de winder não encontrada.");
    } elseif ($acao === "salvar") {
        $wid = $atual ? (int)$atual["winder_id"] : (int)($d["winder_id"] ?? 0);
        $w = linha("SELECT * FROM winder WHERE id = ?", [$wid]);
        $nome = array_key_exists("nome", $d) ? trim((string)$d["nome"]) : (string)($atual["nome"] ?? "");
        $ligada = array_key_exists("ligada", $d) ? ((string)$d["ligada"] === "0" ? 0 : 1) : (int)($atual["ligada"] ?? 1);
        $ordem = array_key_exists("ordem", $d) && trim((string)$d["ordem"]) !== "" ? (int)$d["ordem"] : ($atual ? (int)$atual["ordem"]
            : (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM winder_posicao WHERE winder_id = ?", [$wid]));
        if (!$w) {
            $erros[] = t("Winder não encontrado (winder_id).");
        }
        if ($nome === "" || mb_strlen($nome) > 60) {
            $erros[] = t("Dê um nome à posição (até 60 caracteres).");
        } elseif ($w && (int)valor("SELECT COUNT(*) FROM winder_posicao WHERE winder_id = ? AND nome = ? AND id <> ?", [$wid, $nome, $id]) > 0) {
            $erros[] = t("O {1} já tem uma posição {2}.", [1 => $w["nome"], 2 => $nome]);
        }
        $dentro = $atual ? valor("SELECT r.nome FROM lancamento_winder lw JOIN lancamento l ON l.id = lw.lancamento_id JOIN relogio r ON r.id = l.relogio_id
            WHERE lw.posicao_id = ? AND l.inicio <= ? AND (l.fim IS NULL OR l.fim > ?)", [$id, $agora_t, $agora_t]) : null;
        // relogio_id: o relógio que fica na posição (0: nenhum). Pôr, tirar e trocar numa gravação só: quem estava nela sai
        // agora; o escolhido, se está noutra posição (ou no winder sem posição), sai de lá e entra nesta
        $troca_r = array_key_exists("relogio_id", $d) ? (int)$d["relogio_id"] : null;
        $novo_r = null;
        $tipo_novo = null;
        if ($troca_r !== null && $troca_r > 0) {
            $novo_r = linha("SELECT * FROM relogio WHERE id = ?", [$troca_r]);
            $tipo_novo = $novo_r ? tipo_winder_do_relogio($novo_r) : null;
            if (!$novo_r) {
                $erros[] = t("Relógio não encontrado (relogio_id).");
            } elseif ($tipo_novo === null) {
                $erros[] = t("O {1} não vai para um winder: nenhum tipo de lançamento dele está marcado como winder (Cadastros › Tipos de lançamento).", [1 => $novo_r["nome"]]);
            } elseif ($ligada === 0) {
                $erros[] = t("A posição {1} está desligada.", [1 => $nome]);
            }
        }
        if ($ligada === 0 && $dentro !== null && $troca_r !== 0) {
            $erros[] = t("A posição {1} está com o {2}: tire o relógio antes de desligar.", [1 => $nome, 2 => $dentro]);
        }
        // o programa: um da página Programas (0 tira)
        $prog_n = array_key_exists("programa_id", $d) ? (int)$d["programa_id"] : null;
        $prog_l = $prog_n !== null && $prog_n > 0 ? linha("SELECT * FROM winder_programa WHERE id = ?", [$prog_n]) : null;
        if ($prog_n !== null && $prog_n > 0 && !$prog_l) {
            $erros[] = t("Programa não encontrado (programa_id: o código, na página Programas).");
        }
        $gravar_p = [];
        $campos_p = campos_de_posicao();
        foreach ((array)($d["valores"] ?? []) as $ident => $v) {
            if (!isset($campos_p[$ident])) {
                $erros[] = t("Campo desconhecido: {1}.", [1 => $ident]);
                continue;
            }
            $conf = valor_para_campo($campos_p[$ident], $v);
            if ($conf[1] !== "") {
                $erros[] = $campos_p[$ident]["nome"] . ": " . $conf[1];
            } else {
                $gravar_p[] = [$campos_p[$ident], $conf[0]];
            }
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE winder_posicao SET nome = ?, ordem = ?, ligada = ? WHERE id = ?", [$nome, $ordem, $ligada, $id]);
            } else {
                sql("INSERT INTO winder_posicao (winder_id, nome, ordem, ligada) VALUES (?, ?, ?, ?)", [$wid, $nome, $ordem, $ligada]);
                $id = ultimo_id();
            }
            foreach ($gravar_p as [$campo_p, $v]) {
                valor_gravar("posicao", $id, $campo_p, $v);
            }
            if ($prog_n !== null) {
                sql("DELETE FROM winder_posicao_programa WHERE posicao_id = ?", [$id]);
                if ($prog_l) {
                    sql("INSERT INTO winder_posicao_programa (posicao_id, programa_id) VALUES (?, ?)", [$id, (int)$prog_l["id"]]);
                }
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $msg = t("Posição {1} do {2} {3}{4}.", [1 => $nome, 2 => $w["nome"], 3 => $atual ? t("salva") : t("criada"), 4 => $ligada === 0 ? t(" (desligada)") : ""])
                . ($prog_n !== null ? " " . ($prog_l ? t("Programa: {1} ({2}).", [1 => $prog_l["nome"], 2 => winder_programa_calculado((int)$prog_l["id"])["texto"]]) : t("Sem programa.")) : "");
            if ($troca_r !== null) {
                $agora_p = date("Y-m-d H:i:s");
                $ocupante = linha("SELECT l.relogio_id, t.identificador FROM lancamento_winder lw JOIN lancamento l ON l.id = lw.lancamento_id
                    JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE lw.posicao_id = ? AND l.inicio <= ? AND l.fim IS NULL", [$id, $agora_p]);
                $partes = [];
                if ($ocupante && (int)$ocupante["relogio_id"] !== $troca_r) {
                    $r_sai = op_lancamento("encerrar", ["relogio_id" => (int)$ocupante["relogio_id"], "tipo" => $ocupante["identificador"]]);
                    if ($r_sai["ok"]) {
                        $partes[] = $r_sai["mensagem"];
                    } else {
                        $erros = array_merge($erros, $r_sai["erros"]);
                    }
                }
                if ($novo_r && count($erros) === 0 && (!$ocupante || (int)$ocupante["relogio_id"] !== $troca_r)) {
                    // o escolhido já está no winder (noutra posição ou sem posição): sai de lá antes de entrar nesta
                    $aberta_n = valor("SELECT COUNT(*) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = ? AND l.inicio <= ? AND l.fim IS NULL",
                        [$troca_r, $tipo_novo["identificador"], $agora_p]);
                    if ((int)$aberta_n > 0) {
                        $r_mv = op_lancamento("encerrar", ["relogio_id" => $troca_r, "tipo" => $tipo_novo["identificador"]]);
                        if ($r_mv["ok"]) {
                            $partes[] = $r_mv["mensagem"];
                        } else {
                            $erros = array_merge($erros, $r_mv["erros"]);
                        }
                    }
                    if (count($erros) === 0) {
                        $r_entra = op_lancamento("iniciar", ["relogio_id" => $troca_r, "tipo" => $tipo_novo["identificador"], "posicao_id" => $id, "pela_pagina_winders" => true]);
                        if ($r_entra["ok"]) {
                            $partes[] = $r_entra["mensagem"];
                        } else {
                            $erros = array_merge($erros, $r_entra["erros"]);
                        }
                    }
                }
                // o lugar fixo: a posição passa a ser do relógio posto nela (ele sai do lugar que tinha); tirar não muda o lugar
                if ($novo_r && count($erros) === 0) {
                    $antes_l = valor("SELECT r.nome FROM winder_lugar l JOIN relogio r ON r.id = l.relogio_id WHERE l.posicao_id = ? AND l.relogio_id <> ?", [$id, $troca_r]);
                    winder_lugar_gravar($id, $troca_r);
                    $partes[] = $antes_l !== null ? t("A posição passa a ser o lugar do {1} (era do {2}).", [1 => $novo_r["nome"], 2 => $antes_l]) : t("A posição é o lugar do {1}: tirado, ele volta para cá.", [1 => $novo_r["nome"]]);
                    foreach (winder_limites_avisos($wid, $troca_r) as $av) {
                        $partes[] = t("Atenção: {1}", [1 => $av]);
                    }
                }
                $msg .= count($partes) > 0 ? " " . implode(" ", $partes) : "";
            }
        }
    } elseif ($acao === "liberar" && $atual) {
        // a posição deixa de ser o lugar de alguém (o relógio continua onde está; pôr no winder de novo, só pela página)
        $dono = linha("SELECT r.id, r.nome FROM winder_lugar l JOIN relogio r ON r.id = l.relogio_id WHERE l.posicao_id = ?", [$id]);
        $dentro_l = winder_posicao_ocupante($id);
        if (!$dono) {
            $erros[] = t("A posição {1} não é o lugar de nenhum relógio.", [1 => $atual["nome"]]);
        } elseif ($dentro_l) {
            $erros[] = t("A posição {1} está com o {2}: tire o relógio antes de liberar o lugar.", [1 => $atual["nome"], 2 => $dentro_l["nome"]]);
        } else {
            sql("DELETE FROM winder_lugar WHERE posicao_id = ?", [$id]);
            $msg = t("A posição {1} do {2} deixou de ser o lugar do {3}.", [1 => $atual["nome"], 2 => $atual["winder"], 3 => $dono["nome"]]);
        }
    } elseif ($acao === "excluir" && $atual) {
        sql("DELETE FROM winder_posicao WHERE id = ?", [$id]);
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $msg = t("Posição {1} do {2} excluída.", [1 => $atual["nome"], 2 => $atual["winder"]]);
        $id = 0;
    } elseif ($acao === "excluir" || $acao === "liberar") {
        $erros[] = t("Informe a posição (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar, liberar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Os serviços e diagnósticos de um relógio: cada um é o encerramento de uma manutenção. Ações:
//   salvar (id: 0 ou sem, um novo; relogio_id, só no novo; manutencao_id, só no novo: a manutenção do relógio de que ele é
//           o encerramento; sem ela, uma manutenção já encerrada, na oficina oficina_id (sem ela, a sua, "Eu mesmo (em
//           casa)"), no dia do serviço; data AAAA-MM-DD, até hoje; tipo: o identificador de um tipo de serviço (Manutenção ›
//           Listas; sem ele, o da manutenção, senão revisao); descricao; mao_de_obra (R$); pecas: uma por
//           linha, "nome; custo" (o custo é opcional); estanqueidade: o identificador de um resultado (aprovado, reprovado)
//           ou vazio (não testada; o tipo estanqueidade pede o resultado); estanqueidade_bar (a pressão do teste, só com o resultado); marcha_antes e
//           marcha_depois (s/dia, a que o profissional mediu; + adianta); garantia_ate (AAAA-MM-DD, a partir da data);
//           observacao (texto livre, sem limite); documentos: os ids dos documentos do relógio que são do serviço, separados por vírgula). Só o que
//           vem no pedido muda. O tipo de serviço com a marca (o texto do item: o tipo de lançamento, revisao, pilha) vira
//           também essa marcação, na data do serviço, quando o tipo de lançamento vale para o relógio: os avisos e as fórmulas de sempre contam
//           o serviço (ULTIMA_DATA("revisao")); mudar a data muda a marcação, e mudar o tipo a desfaz.
//   excluir (id): sai o serviço, as peças e a ligação com os documentos (os documentos ficam no relógio), e a marcação
//           que ele criou.
// ---------------------------------------------------------------------------------------------------------------------
function op_servicos($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM servico WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Serviço não encontrado.");
    } elseif ($acao === "salvar") {
        $tem = function ($k) use ($d) { return array_key_exists($k, $d); };
        $texto = function ($k) use ($d, $atual) { return array_key_exists($k, $d) ? trim((string)$d[$k]) : trim((string)($atual[$k] ?? "")); };
        $data_ok = function ($v) { return preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $v) === 1 && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)); };
        $rid = $atual ? (int)$atual["relogio_id"] : (int)($d["relogio_id"] ?? 0);
        $r = $rid > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$rid]) : null;
        if (!$r) {
            $erros[] = t("relógio não encontrado");
        }
        $data = $texto("data");
        $data = $data !== "" ? substr($data, 0, 10) : "";
        if (!$data_ok($data)) {
            $erros[] = t("Data do serviço: AAAA-MM-DD.");
        } elseif ($data > hoje_da_colecao()->format("Y-m-d")) {
            $erros[] = t("A data do serviço não pode ser no futuro.");
        }
        // a manutenção de que ele é o encerramento (só no novo): manutencao_id, uma do relógio; sem ela, o serviço ganha uma
        // manutenção já encerrada (Retirado), na oficina oficina_id (sem ela, a sua: "Eu mesmo (em casa)"), no dia dele
        $manut = null;
        $oficina = null;
        if (!$atual && (int)($d["manutencao_id"] ?? 0) > 0) {
            $manut = linha("SELECT * FROM manutencao WHERE id = ? AND relogio_id = ?", [(int)$d["manutencao_id"], $rid]);
            if (!$manut) {
                $erros[] = t("A manutenção não é deste relógio.");
            }
        } elseif (!$atual) {
            $oficina = (int)($d["oficina_id"] ?? 0) > 0 ? linha("SELECT id, nome FROM oficina WHERE id = ?", [(int)$d["oficina_id"]]) : oficina_propria();
            if (!$oficina) {
                $erros[] = t("A oficina não existe (sem ela, o serviço vai para a sua: Manutenção › Oficinas).");
            }
        }
        $tipo = $texto("tipo") !== "" ? $texto("tipo") : ($manut ? (string)(lista_item_por_id("servico_tipo", (int)$manut["tipo_id"])["identificador"] ?? "") : lista_padrao("servico_tipo", "revisao"));
        if (!isset(lista_itens("servico_tipo")[$tipo])) {
            $erros[] = t("Tipo: {1}.", [1 => implode(", ", array_keys(lista_itens("servico_tipo")))]);
        }
        $desc = $texto("descricao");
        $obs = $texto("observacao");
        if (mb_strlen($desc) > 4000) {
            $erros[] = t("A descrição vai até 4000 caracteres.");
        }
        // os números: vazio apaga; com vírgula ou ponto
        $num = function ($k, $atual_v, $rotulo) use ($d, &$erros) {
            if (!array_key_exists($k, $d)) {
                return $atual_v === null ? null : (float)$atual_v;
            }
            $v = trim((string)$d[$k]);
            if ($v === "") {
                return null;
            }
            $n = numero_br($v);
            if ($n === null) {
                $erros[] = t("{1}: um número.", [1 => $rotulo]);
            }
            return $n;
        };
        $mao = $num("mao_de_obra", $atual["mao_de_obra"] ?? null, t("Mão de obra"));
        if ($mao !== null && $mao < 0) {
            $erros[] = t("A mão de obra não pode ser negativa.");
        }
        $est = $texto("estanqueidade");
        $resultados = implode(", ", array_keys(lista_itens("estanqueidade")));
        if ($est !== "" && !isset(lista_itens("estanqueidade")[$est])) {
            $erros[] = t("Estanqueidade: {1} ou vazio (não testada).", [1 => $resultados]);
        }
        if ($tipo === "estanqueidade" && $est === "") {
            $erros[] = t("O teste de estanqueidade pede o resultado: {1}.", [1 => $resultados]);
        }
        $bar = $num("estanqueidade_bar", $atual["estanqueidade_bar"] ?? null, t("Pressão do teste"));
        if ($bar !== null && ($bar <= 0 || $bar > 1000)) {
            $erros[] = t("A pressão do teste vai de 0 a 1000 bar.");
        } elseif ($bar !== null && $est === "") {
            $erros[] = t("A pressão do teste só vale com o resultado da estanqueidade.");
        }
        $m_antes = $num("marcha_antes", $atual["marcha_antes"] ?? null, t("Marcha antes"));
        $m_depois = $num("marcha_depois", $atual["marcha_depois"] ?? null, t("Marcha depois"));
        foreach ([$m_antes, $m_depois] as $mm) {
            if ($mm !== null && abs($mm) > 3600) {
                $erros[] = t("A marcha vai de −3600 a +3600 s/dia.");
                break;
            }
        }
        $garantia = $texto("garantia_ate");
        $garantia = $garantia !== "" ? substr($garantia, 0, 10) : "";
        if ($garantia !== "" && !$data_ok($garantia)) {
            $erros[] = t("Garantia até: AAAA-MM-DD.");
        } elseif ($garantia !== "" && $data_ok($data) && $garantia < $data) {
            $erros[] = t("A garantia não pode acabar antes do serviço.");
        }
        // as peças: uma por linha, "nome; custo"
        $pecas = null;
        if ($tem("pecas")) {
            $pecas = [];
            foreach (preg_split("/\\r\\n|\\r|\\n/", (string)$d["pecas"]) as $linha_p) {
                $linha_p = trim($linha_p);
                if ($linha_p === "") {
                    continue;
                }
                $partes = array_map("trim", explode(";", $linha_p, 2));
                $custo = isset($partes[1]) && $partes[1] !== "" ? numero_br(str_replace(["R$", " "], "", $partes[1])) : null;
                if ($partes[0] === "" || mb_strlen($partes[0]) > 120) {
                    $erros[] = t("Peça: o nome, até 120 caracteres ({1}).", [1 => $linha_p]);
                } elseif (isset($partes[1]) && $partes[1] !== "" && ($custo === null || $custo < 0)) {
                    $erros[] = t("Peça {1}: o custo depois do ponto e vírgula, um número (ex.: Coroa; 120,00).", [1 => $partes[0]]);
                }
                $pecas[] = [$partes[0], $custo];
            }
            if (count($pecas) > 50) {
                $erros[] = t("No máximo 50 peças por serviço.");
            }
        }
        // os documentos: os do próprio relógio
        $docs = null;
        if ($tem("documentos") && $r) {
            $docs = [];
            foreach (is_array($d["documentos"]) ? $d["documentos"] : preg_split("/[,\\s]+/", (string)$d["documentos"]) as $x) {
                if (trim((string)$x) === "") {
                    continue;
                }
                if ((int)valor("SELECT COUNT(*) FROM documento WHERE id = ? AND relogio_id = ?", [(int)$x, $rid]) === 0) {
                    $erros[] = t("Documento {1} não é deste relógio.", [1 => (int)$x]);
                } else {
                    $docs[(int)$x] = true;
                }
            }
            $docs = array_keys($docs);
        }
        if (count($erros) === 0) {
            $campos = [$data, $tipo, $desc !== "" ? $desc : null, $mao, $est !== "" ? $est : null, $bar, $m_antes, $m_depois,
                $garantia !== "" ? $garantia : null, $obs !== "" ? $obs : null];
            if ($atual) {
                sql("UPDATE servico SET data = ?, tipo = ?, descricao = ?, mao_de_obra = ?, estanqueidade = ?, estanqueidade_bar = ?, marcha_antes = ?, marcha_depois = ?,
                    garantia_ate = ?, observacao = ? WHERE id = ?", array_merge($campos, [$id]));
            } else {
                $mid = $manut ? (int)$manut["id"] : manutencao_encerrada($rid, (int)$oficina["id"], $tipo, $data);
                sql("INSERT INTO servico (relogio_id, manutencao_id, data, tipo, descricao, mao_de_obra, estanqueidade, estanqueidade_bar, marcha_antes, marcha_depois, garantia_ate,
                    observacao, criado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", array_merge([$rid, $mid], $campos));
                $id = ultimo_id();
            }
            if ($pecas !== null) {
                sql("DELETE FROM servico_peca WHERE servico_id = ?", [$id]);
                foreach ($pecas as $i => [$nome_p, $custo_p]) {
                    sql("INSERT INTO servico_peca (servico_id, nome, custo, ordem) VALUES (?, ?, ?, ?)", [$id, $nome_p, $custo_p, $i + 1]);
                }
            }
            if ($docs !== null) {
                sql("DELETE FROM servico_documento WHERE servico_id = ?", [$id]);
                foreach ($docs as $doc) {
                    sql("INSERT INTO servico_documento (servico_id, documento_id) VALUES (?, ?)", [$id, $doc]);
                }
            }
            // a marcação da revisão e da troca de pilha: na data do serviço (ao meio-dia), do tipo de mesmo nome, se ele vale
            // para o relógio; noutro tipo (ou sem o tipo de lançamento), a marcação que o serviço tinha sai
            $lan = $atual && $atual["lancamento_id"] !== null ? linha("SELECT * FROM lancamento WHERE id = ?", [(int)$atual["lancamento_id"]]) : null;
            $marca = lista_itens("servico_tipo")[$tipo]["texto"] ?? null;
            $tl = $marca !== null ? (lancamento_tipos_do_relogio($r)[$marca] ?? null) : null;
            $quando = $data . " 12:00:00";
            $marcou = "";
            if ($tl !== null && $tl["formato"] === "instantaneo") {
                if ($lan !== null && (int)$lan["tipo_id"] === (int)$tl["id"]) {
                    sql("UPDATE lancamento SET inicio = ? WHERE id = ?", [$quando, (int)$lan["id"]]);
                } else {
                    if ($lan !== null) {
                        sql("DELETE FROM lancamento WHERE id = ?", [(int)$lan["id"]]);
                    }
                    sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, NULL, NULL, 'manual', NOW())", [$rid, (int)$tl["id"], $quando]);
                    sql("UPDATE servico SET lancamento_id = ? WHERE id = ?", [ultimo_id(), $id]);
                }
                $marcou = t(" Marcado também: {1} em {2}.", [1 => $tl["nome"], 2 => data_txt(strtotime($quando))]);
            } elseif ($lan !== null) {
                sql("DELETE FROM lancamento WHERE id = ?", [(int)$lan["id"]]);
                sql("UPDATE servico SET lancamento_id = NULL WHERE id = ?", [$id]);
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $msg = t("Serviço {1} de {2}{3}, do {4}.", [1 => mb_strtolower(lista_nome("servico_tipo", $tipo), "UTF-8"), 2 => data_txt(strtotime($data . " 00:00:00")),
                3 => $atual ? t(" salvo") : t(" registrado"), 4 => $r["nome"]]) . $marcou;
        }
    } elseif ($acao === "excluir" && $atual) {
        if ($atual["lancamento_id"] !== null) {
            sql("DELETE FROM lancamento WHERE id = ?", [(int)$atual["lancamento_id"]]);
        }
        sql("DELETE FROM servico WHERE id = ?", [$id]);
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $msg = t("Serviço de {1} excluído.", [1 => data_txt(strtotime(substr((string)$atual["data"], 0, 10) . " 00:00:00"))]);
        $id = 0;
    } elseif ($acao === "excluir") {
        $erros[] = t("Informe o serviço (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// A manutenção: o relógio numa oficina, da ida à volta. Sem manutenção cadastrada, o relógio não vai a lugar nenhum: é
// ela que o põe em manutenção. Ações:
//   salvar (id: 0 ou sem, uma nova; relogio_id, só na nova: um relógio da coleção, na coleção ou emprestado, sem outra
//           manutenção aberta; oficina_id: uma oficina da coleção (Manutenção › Oficinas); situacao: o identificador de
//           uma situação (Manutenção › Listas; sem ela, na nova, a primeira das abertas; mudar por aqui é o mesmo que a
//           ação situacao); tipo: o identificador do tipo de serviço pedido (sem ele, na nova, revisao); problema (até
//           2000); os (o número da ordem de serviço, até 60); enviado (AAAA-MM-DD, até hoje; sem ele, hoje); previsao
//           (AAAA-MM-DD, a partir do envio; passou e ela ainda está aberta: o aviso Cobrar a oficina); orcamento (R$, não
//           negativo); orcamento_data (AAAA-MM-DD, a partir do envio); encerrado (AAAA-MM-DD: só numa situação que
//           encerra ou cancela; sem ele, o dia da mudança); observacao (até 2000); acessorios (os ids dos acessórios da
//           coleção que foram junto, separados por vírgula); documentos (os ids dos documentos do relógio: a OS, o
//           orçamento, as fotos da entrega). Só o que vem no pedido muda. Aberta, o relógio fica em manutenção:
//           indisponível (a disponibilidade de antes volta quando ela fecha), as sessões abertas dele fecham (o winder
//           deixa a posição livre), sai do plano de amanhã em diante (sorteado de novo) e, se era o de hoje, hoje fica
//           livre (sem a Configuração deixar trocar o relógio de hoje, sem relógio); os avisos dele param, menos os da
//           manutenção.
//   situacao (id; situacao; quando: AAAA-MM-DD HH:MM, até agora, a partir do envio; sem ele, agora; observacao, até 500;
//           encerrado): a mudança fica na história. Numa que encerra (Retirado), o relógio volta e a manutenção vira um
//           serviço (o tipo, o orçamento como mão de obra e os documentos dela; complete no serviço); numa que cancela,
//           volta sem serviço; reabrir (voltar a uma aberta) põe o relógio de novo em manutenção, se ela não tem serviço.
//   excluir (id): sai a manutenção, a história, os serviços dela (com a marcação de cada um) e as ligações; aberta, o
//           relógio volta antes.
// ---------------------------------------------------------------------------------------------------------------------
function op_manutencao($acao, $d)
{
    $erros = [];
    $msg = "";
    $extra = [];
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM manutencao WHERE id = ?", [$id]) : null;
    $data_ok = function ($v) { return preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $v) === 1 && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)); };
    $hoje = hoje_da_colecao()->format("Y-m-d");
    if ($id > 0 && !$atual) {
        $erros[] = t("Manutenção não encontrada.");
    } elseif ($acao === "salvar") {
        $texto = function ($k) use ($d, $atual) { return array_key_exists($k, $d) ? trim((string)$d[$k]) : trim((string)($atual[$k] ?? "")); };
        $rid = $atual ? (int)$atual["relogio_id"] : (int)($d["relogio_id"] ?? 0);
        $r = $rid > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$rid]) : null;
        if (!$r) {
            $erros[] = t("relógio não encontrado");
        } elseif (!$atual && !da_colecao($r)) {
            $erros[] = t("Só um relógio da coleção (na coleção ou emprestado) vai para a manutenção; o {1} está {2}.", [1 => $r["nome"], 2 => mb_strtolower(posse_texto($r), "UTF-8")]);
        }
        $of_id = array_key_exists("oficina_id", $d) ? (int)$d["oficina_id"] : ($atual ? (int)$atual["oficina_id"] : 0);
        $of = $of_id > 0 ? linha("SELECT id, nome FROM oficina WHERE id = ?", [$of_id]) : null;
        if (!$of) {
            $erros[] = t("Escolha a oficina (Manutenção › Oficinas).");
        }
        $sit_id = $texto("situacao") !== "" && array_key_exists("situacao", $d) ? (lista_itens("manutencao_situacao")[$texto("situacao")]["id"] ?? 0)
            : ($atual ? (int)$atual["situacao_id"] : (lista_com_comportamento("manutencao_situacao", "aberta")["id"] ?? 0));
        $sit = lista_item_por_id("manutencao_situacao", (int)$sit_id);
        if ($sit === null) {
            $erros[] = t("Situação: {1}.", [1 => implode(", ", array_keys(lista_itens("manutencao_situacao")))]);
        }
        $tipo_ident = array_key_exists("tipo", $d) && trim((string)$d["tipo"]) !== "" ? trim((string)$d["tipo"])
            : ($atual ? (string)(lista_item_por_id("servico_tipo", (int)$atual["tipo_id"])["identificador"] ?? "") : lista_padrao("servico_tipo", "revisao"));
        $tipo = lista_itens("servico_tipo")[$tipo_ident] ?? null;
        if ($tipo === null) {
            $erros[] = t("Tipo: {1}.", [1 => implode(", ", array_keys(lista_itens("servico_tipo")))]);
        }
        $problema = $texto("problema");
        $os = $texto("os");
        $obs = $texto("observacao");
        if (mb_strlen($problema) > 2000 || mb_strlen($obs) > 2000) {
            $erros[] = t("O problema e a observação vão até 2000 caracteres.");
        }
        if (mb_strlen($os) > 60) {
            $erros[] = t("A ordem de serviço vai até 60 caracteres.");
        }
        $enviado = $texto("enviado") !== "" ? substr($texto("enviado"), 0, 10) : $hoje;
        if (!$data_ok($enviado)) {
            $erros[] = t("Enviado: AAAA-MM-DD.");
        } elseif ($enviado > $hoje) {
            $erros[] = t("O envio não pode ser no futuro.");
        }
        $datas = [];
        foreach (["previsao" => t("Previsão"), "orcamento_data" => t("Data do orçamento")] as $k => $rot) {
            $v = $texto($k) !== "" ? substr($texto($k), 0, 10) : "";
            if ($v !== "" && !$data_ok($v)) {
                $erros[] = t("{1}: AAAA-MM-DD.", [1 => $rot]);
            } elseif ($v !== "" && $v < $enviado) {
                $erros[] = t("{1}: a partir do envio.", [1 => $rot]);
            }
            $datas[$k] = $v !== "" ? $v : null;
        }
        $orc = null;
        if ($texto("orcamento") !== "") {
            $orc = numero_br($texto("orcamento"));
            if ($orc === null || $orc < 0) {
                $erros[] = t("O orçamento é um número, não negativo.");
            }
        }
        // o que foi junto (os acessórios da coleção) e os documentos (os do relógio)
        $ids = function ($k) use ($d) {
            return array_values(array_unique(array_filter(array_map("intval", is_array($d[$k]) ? $d[$k] : preg_split("/[,\\s]+/", (string)$d[$k])), function ($x) { return $x > 0; })));
        };
        $acess = array_key_exists("acessorios", $d) ? $ids("acessorios") : null;
        foreach ($acess ?? [] as $a) {
            if (valor("SELECT id FROM acessorio WHERE id = ?", [$a]) === null) {
                $erros[] = t("O acessório {1} não é da coleção.", [1 => $a]);
            }
        }
        $docs = array_key_exists("documentos", $d) ? $ids("documentos") : null;
        foreach ($docs ?? [] as $x) {
            if ((int)valor("SELECT COUNT(*) FROM documento WHERE id = ? AND relogio_id = ?", [$x, $rid]) === 0) {
                $erros[] = t("Documento {1} não é deste relógio.", [1 => $x]);
            }
        }
        $aberta_outra = $r ? manutencao_aberta($rid) : null;
        if (!$atual && $aberta_outra !== null && ($sit["comportamento"] ?? "") === "aberta") {
            $erros[] = t("O {1} já está em manutenção: {2}, desde {3}.", [1 => $r["nome"], 2 => $aberta_outra["oficina"], 3 => data_txt(strtotime($aberta_outra["enviado"] . " 00:00:00"))]);
        }
        if (count($erros) === 0) {
            $campos = [(int)$of["id"], $tipo["id"], $problema !== "" ? $problema : null, $os !== "" ? $os : null, $enviado, $datas["previsao"], $orc, $datas["orcamento_data"], $obs !== "" ? $obs : null];
            $nota = "";
            if ($atual) {
                sql("UPDATE manutencao SET oficina_id = ?, tipo_id = ?, problema = ?, os = ?, enviado = ?, previsao = ?, orcamento = ?, orcamento_data = ?, observacao = ? WHERE id = ?",
                    array_merge($campos, [$id]));
            } else {
                // nova: entra na primeira situação aberta e, se a pedida é outra, muda para ela logo depois (a história fica)
                $abre = ($sit["comportamento"] ?? "") === "aberta" ? $sit : lista_com_comportamento("manutencao_situacao", "aberta");
                sql("INSERT INTO manutencao (relogio_id, oficina_id, tipo_id, problema, os, enviado, previsao, orcamento, orcamento_data, observacao, situacao_id, disponivel_antes, criado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", array_merge([$rid], $campos, [$abre["id"], (int)$r["disponivel"]]));
                $id = ultimo_id();
                sql("INSERT INTO manutencao_evento (manutencao_id, situacao_id, quando, observacao) VALUES (?, ?, ?, NULL)",
                    [$id, $abre["id"], $enviado === $hoje ? date("Y-m-d H:i:s") : $enviado . " 12:00:00"]);
                // a que já nasce fechada (o registro de uma manutenção passada) não mexe no relógio
                $registro = ($sit["comportamento"] ?? "") !== "aberta";
                if (!$registro) {
                    sql("UPDATE relogio SET disponivel = 0 WHERE id = ?", [$rid]);
                    $nota .= relogio_sai_do_rodizio($rid, true);
                }
                $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
                $atual = linha("SELECT * FROM manutencao WHERE id = ?", [$id]);
            }
            if ($acess !== null) {
                sql("DELETE FROM manutencao_acessorio WHERE manutencao_id = ?", [$id]);
                foreach ($acess as $a) {
                    sql("INSERT INTO manutencao_acessorio (manutencao_id, acessorio_id) VALUES (?, ?)", [$id, $a]);
                }
            }
            if ($docs !== null) {
                sql("DELETE FROM manutencao_documento WHERE manutencao_id = ?", [$id]);
                foreach ($docs as $x) {
                    sql("INSERT INTO manutencao_documento (manutencao_id, documento_id) VALUES (?, ?)", [$id, $x]);
                }
            }
            if ((int)$atual["situacao_id"] !== (int)$sit["id"]) {
                $mud = manutencao_situacao_mudar($atual, $sit, time(), null, $texto("encerrado") !== "" ? substr($texto("encerrado"), 0, 10) : (!empty($registro) ? $enviado : null),
                    empty($registro));
                $erros = $mud["erros"];
                $nota .= $mud["nota"];
                $extra["servico_id"] = $mud["servico_id"];
            }
            $msg = t("Manutenção do {1} {2}: {3}, {4}.", [1 => $r["nome"], 2 => $id === (int)($d["id"] ?? 0) ? t("salva") : t("cadastrada"), 3 => $of["nome"],
                4 => mb_strtolower(lista_item_por_id("manutencao_situacao", (int)valor("SELECT situacao_id FROM manutencao WHERE id = ?", [$id]))["nome"] ?? "", "UTF-8")]) . $nota;
        }
    } elseif ($acao === "situacao" && $atual) {
        $sit = lista_itens("manutencao_situacao")[trim((string)($d["situacao"] ?? ""))] ?? null;
        $quando = trim((string)($d["quando"] ?? "")) === "" ? time() : strtotime(str_replace("T", " ", (string)$d["quando"]));
        $obs = trim((string)($d["observacao"] ?? ""));
        if ($sit === null) {
            $erros[] = t("Situação: {1}.", [1 => implode(", ", array_keys(lista_itens("manutencao_situacao")))]);
        }
        if ($quando === false || $quando > time() + 60) {
            $erros[] = t("Quando: AAAA-MM-DD HH:MM, até agora.");
        } elseif (date("Y-m-d", $quando) < substr((string)$atual["enviado"], 0, 10)) {
            $erros[] = t("A mudança não pode ser antes do envio.");
        }
        if (mb_strlen($obs) > 500) {
            $erros[] = t("A observação vai até 500 caracteres.");
        }
        if (count($erros) === 0) {
            $enc = trim((string)($d["encerrado"] ?? ""));
            $mud = manutencao_situacao_mudar($atual, $sit, (int)$quando, $obs !== "" ? $obs : null, $enc !== "" ? substr($enc, 0, 10) : null);
            $erros = $mud["erros"];
            $extra["servico_id"] = $mud["servico_id"];
            $msg = t("Manutenção do {1}: {2}.", [1 => (string)valor("SELECT nome FROM relogio WHERE id = ?", [(int)$atual["relogio_id"]]), 2 => mb_strtolower($sit["nome"], "UTF-8")]) . $mud["nota"];
        }
    } elseif ($acao === "excluir" && $atual) {
        $nome = (string)valor("SELECT nome FROM relogio WHERE id = ?", [(int)$atual["relogio_id"]]);
        $nota = (lista_item_por_id("manutencao_situacao", (int)$atual["situacao_id"])["comportamento"] ?? "") === "aberta" ? manutencao_volta($atual) : "";
        foreach (linhas("SELECT id FROM servico WHERE manutencao_id = ?", [$id]) as $s) {
            op_servicos("excluir", ["id" => (int)$s["id"]]);
        }
        sql("DELETE FROM manutencao WHERE id = ?", [$id]);
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $msg = t("Manutenção do {1} excluída.", [1 => $nome]) . $nota;
        $id = 0;
    } elseif (in_array($acao, ["situacao", "excluir"], true)) {
        $erros[] = t("Informe a manutenção (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar, situacao ou excluir.");
    }
    return resultado($erros, $msg, array_merge(["id" => $id], $extra));
}

// Muda a situação de uma manutenção ($m, a linha; $sit, o item; $quando, em segundos; $obs; $encerrado, AAAA-MM-DD ou null:
// o dia da mudança): a história ganha a mudança e, conforme o que a situação faz, o relógio volta (encerra, cancela) ou vai
// de novo (reabrir), e a que encerra vira um serviço. $mexe_no_relogio false: o registro de uma manutenção passada (o relógio
// não foi a lugar nenhum agora). Devolve {erros, nota, servico_id}
function manutencao_situacao_mudar($m, $sit, $quando, $obs, $encerrado, $mexe_no_relogio = true)
{
    $de = lista_item_por_id("manutencao_situacao", (int)$m["situacao_id"])["comportamento"] ?? "aberta";
    $para = $sit["comportamento"];
    $id = (int)$m["id"];
    $rid = (int)$m["relogio_id"];
    $servicos = (int)valor("SELECT COUNT(*) FROM servico WHERE manutencao_id = ?", [$id]);
    $enc = $encerrado ?? date("Y-m-d", $quando);
    $nota = "";
    $servico_id = null;
    if ($para !== "encerra" && $de === "encerra" && $servicos > 0) {
        return ["erros" => [t("A manutenção já virou serviço: para mudar a situação, exclua antes o serviço dela.")], "nota" => "", "servico_id" => null];
    }
    if ($para === "aberta" && $de !== "aberta") {
        $outra = manutencao_aberta($rid);
        if ($outra !== null) {
            return ["erros" => [t("O relógio já está noutra manutenção aberta: {1}.", [1 => $outra["oficina"]])], "nota" => "", "servico_id" => null];
        }
    }
    if ($para !== "aberta" && ($enc < substr((string)$m["enviado"], 0, 10) || $enc > hoje_da_colecao()->format("Y-m-d"))) {
        return ["erros" => [t("O encerramento vai do envio até hoje.")], "nota" => "", "servico_id" => null];
    }
    sql("UPDATE manutencao SET situacao_id = ? WHERE id = ?", [$sit["id"], $id]);
    sql("INSERT INTO manutencao_evento (manutencao_id, situacao_id, quando, observacao) VALUES (?, ?, ?, ?)", [$id, $sit["id"], date("Y-m-d H:i:s", $quando), $obs]);
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    if ($de === "aberta" && $para !== "aberta") {
        sql("UPDATE manutencao SET encerrado = ? WHERE id = ?", [$enc, $id]);
        $nota .= $mexe_no_relogio ? manutencao_volta($m) : "";
    } elseif ($de !== "aberta" && $para === "aberta") {
        $r = linha("SELECT disponivel FROM relogio WHERE id = ?", [$rid]);
        sql("UPDATE manutencao SET encerrado = NULL, disponivel_antes = ? WHERE id = ?", [(int)$r["disponivel"], $id]);
        sql("UPDATE relogio SET disponivel = 0 WHERE id = ?", [$rid]);
        $nota .= t(" Voltou para a manutenção.") . relogio_sai_do_rodizio($rid, true);
    }
    if ($para === "encerra" && $servicos === 0) {
        $tipo = lista_item_por_id("servico_tipo", (int)$m["tipo_id"]);
        $docs = array_column(linhas("SELECT documento_id FROM manutencao_documento WHERE manutencao_id = ?", [$id]), "documento_id");
        $fim = (string)(valor("SELECT encerrado FROM manutencao WHERE id = ?", [$id]) ?? $enc);
        $sv = op_servicos("salvar", ["relogio_id" => $rid, "manutencao_id" => $id, "data" => substr($fim, 0, 10), "tipo" => $tipo["identificador"] ?? "",
            "mao_de_obra" => $m["orcamento"] === null ? "" : (string)$m["orcamento"], "estanqueidade" => "", "documentos" => implode(",", $docs)]);
        if ($sv["ok"]) {
            $servico_id = (int)$sv["id"];
            $nota .= t(" Virou o serviço de {1}: complete o que foi feito, as peças e o custo no serviço.", [1 => data_txt(strtotime(substr($fim, 0, 10) . " 00:00:00"))]);
        } else {
            $nota .= t(" O serviço não foi criado ({1}): registre-o no quadro Serviços do relógio.", [1 => implode(" ", $sv["erros"])]);
        }
    }
    return ["erros" => [], "nota" => $nota, "servico_id" => $servico_id];
}

// ---------------------------------------------------------------------------------------------------------------------
// As oficinas da coleção (Manutenção › Oficinas): quem cuida dos relógios, com quantos contatos quiser. Ações:
//   salvar (id: 0 ou sem, uma nova; nome, até 120, sem repetir na coleção; tipo: o identificador de um tipo de oficina
//           (Manutenção › Listas; sem ele, na nova, relojoeiro); endereco (até 200), numero (20), complemento (80), bairro
//           (80), cidade (80), uf (40), cep (20), horario (até 200), observacao (até 2000); contatos: a lista inteira (os
//           de antes saem), cada um com tipo (o identificador de um tipo de contato), valor (até 200: o número, o e-mail, o
//           endereço), pessoa (até 120), observacao (até 200) e principal (1: o primeiro da lista; só um), em
//           contatos[0][tipo]=... ou num JSON; até 30). Só o que vem no pedido muda.
//   excluir (id): só a oficina sem manutenções (com elas, ela fica: é a história delas); a sua ("Eu mesmo (em casa)", a
//           única do tipo que faz "você mesmo") também fica.
// ---------------------------------------------------------------------------------------------------------------------
function op_oficinas($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM oficina WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Oficina não encontrada.");
    } elseif ($acao === "salvar") {
        $texto = function ($k) use ($d, $atual) { return array_key_exists($k, $d) ? trim((string)$d[$k]) : trim((string)($atual[$k] ?? "")); };
        $nome = $texto("nome");
        if ($nome === "" || mb_strlen($nome) > 120) {
            $erros[] = t("Dê um nome à oficina (até 120 caracteres).");
        } elseif (valor("SELECT id FROM oficina WHERE nome = ? AND id <> ?", [$nome, $id]) !== null) {
            $erros[] = t("Já há a oficina {1}.", [1 => $nome]);
        }
        $tipo_ident = array_key_exists("tipo", $d) && trim((string)$d["tipo"]) !== "" ? trim((string)$d["tipo"])
            : ($atual ? (string)(lista_item_por_id("oficina_tipo", (int)$atual["tipo_id"])["identificador"] ?? "") : lista_padrao("oficina_tipo", "relojoeiro"));
        $tipo = lista_itens("oficina_tipo")[$tipo_ident] ?? null;
        if ($tipo === null) {
            $erros[] = t("Tipo: {1}.", [1 => implode(", ", array_keys(lista_itens("oficina_tipo")))]);
        }
        $lim = ["endereco" => 200, "numero" => 20, "complemento" => 80, "bairro" => 80, "cidade" => 80, "uf" => 40, "cep" => 20, "horario" => 200, "observacao" => 2000];
        $vals = [];
        foreach ($lim as $k => $n) {
            $vals[$k] = $texto($k);
            if (mb_strlen($vals[$k]) > $n) {
                $erros[] = t("{1}: até {2} caracteres.", [1 => $k, 2 => $n]);
            }
        }
        $contatos = null;
        if (array_key_exists("contatos", $d)) {
            $lista_c = is_array($d["contatos"]) ? $d["contatos"] : (trim((string)$d["contatos"]) === "" ? [] : json_decode((string)$d["contatos"], true));
            if (!is_array($lista_c)) {
                $erros[] = t("Contatos: uma lista (contatos[0][tipo]=...) ou um JSON.");
                $lista_c = [];
            }
            if (count($lista_c) > 30) {
                $erros[] = t("No máximo 30 contatos por oficina.");
            }
            $contatos = [];
            $tem_principal = false;
            foreach (array_values($lista_c) as $i => $c) {
                $c = is_array($c) ? $c : [];
                $tc = lista_itens("contato_tipo")[trim((string)($c["tipo"] ?? ""))] ?? null;
                $valor_c = trim((string)($c["valor"] ?? ""));
                $pessoa = trim((string)($c["pessoa"] ?? ""));
                $obs_c = trim((string)($c["observacao"] ?? ""));
                if ($valor_c === "" && $pessoa === "" && $obs_c === "") {
                    continue;
                }
                if ($tc === null) {
                    $erros[] = t("Contato {1}: o tipo ({2}).", [1 => $i + 1, 2 => implode(", ", array_keys(lista_itens("contato_tipo")))]);
                }
                if ($valor_c === "" || mb_strlen($valor_c) > 200 || mb_strlen($pessoa) > 120 || mb_strlen($obs_c) > 200) {
                    $erros[] = t("Contato {1}: o valor (até 200), a pessoa (até 120) e a observação (até 200).", [1 => $i + 1]);
                }
                $principal = !$tem_principal && in_array((string)($c["principal"] ?? "0"), ["1", "true", "on"], true);
                $tem_principal = $tem_principal || $principal;
                $contatos[] = [$tc["id"] ?? 0, $valor_c, $pessoa !== "" ? $pessoa : null, $obs_c !== "" ? $obs_c : null, $principal ? 1 : 0, count($contatos) + 1];
            }
        }
        if (count($erros) === 0) {
            $campos = array_merge([$nome, $tipo["id"]], array_map(function ($v) { return $v !== "" ? $v : null; }, array_values($vals)));
            if ($atual) {
                sql("UPDATE oficina SET nome = ?, tipo_id = ?, endereco = ?, numero = ?, complemento = ?, bairro = ?, cidade = ?, uf = ?, cep = ?, horario = ?, observacao = ? WHERE id = ?",
                    array_merge($campos, [$id]));
            } else {
                sql("INSERT INTO oficina (colecao_id, nome, tipo_id, endereco, numero, complemento, bairro, cidade, uf, cep, horario, observacao, criado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", array_merge([colecao_atual()], $campos));
                $id = ultimo_id();
            }
            if ($contatos !== null) {
                sql("DELETE FROM oficina_contato WHERE oficina_id = ?", [$id]);
                foreach ($contatos as $c) {
                    sql("INSERT INTO oficina_contato (oficina_id, tipo_id, valor, pessoa, observacao, principal, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)", array_merge([$id], $c));
                }
            }
            $msg = t("Oficina {1} {2}.", [1 => $nome, 2 => $atual ? t("salva") : t("cadastrada")]);
        }
    } elseif ($acao === "excluir" && $atual) {
        $n = (int)valor("SELECT COUNT(*) FROM manutencao WHERE oficina_id = ?", [$id]);
        $propria = (lista_item_por_id("oficina_tipo", (int)$atual["tipo_id"])["comportamento"] ?? "") === "propria";
        if ($propria && (int)valor("SELECT COUNT(*) FROM oficina o JOIN lista_item i ON i.id = o.tipo_id WHERE i.comportamento = 'propria'") === 1) {
            $erros[] = t("A {1} fica: é a sua (o serviço registrado sem oficina vai para ela).", [1 => $atual["nome"]]);
        } elseif ($n > 0) {
            $erros[] = t("A oficina {1} tem {2}: ela fica (é a história delas).", [1 => $atual["nome"], 2 => $n === 1 ? t("1 manutenção") : t("{1} manutenções", [1 => $n])]);
        } else {
            sql("DELETE FROM oficina WHERE id = ?", [$id]);
            $msg = t("Oficina {1} excluída.", [1 => $atual["nome"]]);
            $id = 0;
        }
    } elseif ($acao === "excluir") {
        $erros[] = t("Informe a oficina (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// As listas que são cadastro (Manutenção › Listas e Cadastros › Listas), na árvore da coleção: a situação do relógio na
// coleção, os tipos de acessório, os jeitos de carregar e as observações da reserva, as situações da manutenção, os tipos
// de serviço, os resultados da estanqueidade, os tipos de oficina e os tipos de contato. Ações:
//   salvar (id: 0 ou sem, um item novo; lista, só no novo; identificador, só no novo: minúsculas, números e _, começando
//           por letra, até 20, sem repetir na lista e fora dos reservados; nome, até 80; ordem; comportamento: o que ele
//           faz, nas listas que têm (obrigatório nelas; não muda com o item em uso); texto, nas listas que têm (até 200;
//           no tipo de serviço, o identificador de um tipo de lançamento instantâneo); cor, nas listas que têm (#rrggbb ou
//           vazio)). Só o que vem no pedido muda. O identificador não muda.
//   excluir (id): só o item fora de uso. Uma lista nunca fica sem um item do que o sistema precisa (a situação na
//           coleção: um na coleção; a manutenção: uma aberta e uma que encerra; a oficina: a sua).
// ---------------------------------------------------------------------------------------------------------------------
function op_listas($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $raiz = no_raiz();
    $atual = $id > 0 ? linha("SELECT * FROM lista_item WHERE id = ? AND no_id = ?", [$id, $raiz]) : null;
    // o último item com o que o sistema precisa não sai nem muda de comportamento
    $ultimo = function ($it) use ($raiz) {
        return in_array($it["comportamento"], LISTAS_ESSENCIAIS[$it["lista"]] ?? [], true)
            && (int)valor("SELECT COUNT(*) FROM lista_item WHERE no_id = ? AND lista = ? AND comportamento = ? AND id <> ?", [$raiz, $it["lista"], $it["comportamento"], (int)$it["id"]]) === 0;
    };
    if ($id > 0 && !$atual) {
        $erros[] = t("Item não encontrado.");
    } elseif ($acao === "salvar") {
        $lista = $atual ? $atual["lista"] : trim((string)($d["lista"] ?? ""));
        $def = LISTAS[$lista] ?? null;
        if ($def === null) {
            $erros[] = t("Lista: {1}.", [1 => implode(", ", array_keys(LISTAS))]);
        }
        $ident = $atual ? $atual["identificador"] : trim((string)($d["identificador"] ?? ""));
        if (!$atual && $def !== null) {
            if (preg_match("/^[a-z][a-z0-9_]{0,19}$/", $ident) !== 1) {
                $erros[] = t("Identificador: minúsculas, números e _, começando por letra, até 20.");
            } elseif (in_array($ident, $def["reservados"], true)) {
                $erros[] = t("O identificador {1} é do sistema nesta lista.", [1 => $ident]);
            } elseif (valor("SELECT id FROM lista_item WHERE no_id = ? AND lista = ? AND identificador = ?", [$raiz, $lista, $ident]) !== null) {
                $erros[] = t("A lista já tem o identificador {1}.", [1 => $ident]);
            }
        }
        $nome = array_key_exists("nome", $d) ? trim((string)$d["nome"]) : (string)($atual["nome"] ?? "");
        if ($nome === "" || mb_strlen($nome) > 80) {
            $erros[] = t("Dê um nome ao item (até 80 caracteres).");
        }
        $ordem = array_key_exists("ordem", $d) && trim((string)$d["ordem"]) !== "" ? (int)$d["ordem"]
            : ($atual ? (int)$atual["ordem"] : 1 + (int)valor("SELECT COALESCE(MAX(ordem), 0) FROM lista_item WHERE no_id = ? AND lista = ?", [$raiz, $lista]));
        $comp = array_key_exists("comportamento", $d) ? trim((string)$d["comportamento"]) : (string)($atual["comportamento"] ?? "");
        $texto = array_key_exists("texto", $d) ? trim((string)$d["texto"]) : (string)($atual["texto"] ?? "");
        $cor = array_key_exists("cor", $d) ? strtolower(trim((string)$d["cor"])) : (string)($atual["cor"] ?? "");
        if ($def !== null) {
            if (count($def["comportamentos"]) > 0 && !isset($def["comportamentos"][$comp])) {
                $erros[] = t("O que ele faz (comportamento): {1}.", [1 => implode(", ", array_keys($def["comportamentos"]))]);
            } elseif (count($def["comportamentos"]) === 0) {
                $comp = "";
            }
            if ($atual && $comp !== (string)$atual["comportamento"]) {
                $uso = lista_item_em_uso($atual, $raiz);
                if ($uso > 0) {
                    $erros[] = t("O item {1} está em uso ({2}): o que ele faz não muda (crie outro item).", [1 => $atual["nome"], 2 => $uso]);
                } elseif ($ultimo($atual)) {
                    $erros[] = t("O {1} é o último item que faz isso: a lista precisa dele.", [1 => $atual["nome"]]);
                }
            }
            if ($def["texto"] === null) {
                $texto = "";
            } elseif (mb_strlen($texto) > 200) {
                $erros[] = t("O texto vai até 200 caracteres.");
            } elseif ($lista === "servico_tipo" && $texto !== ""
                && (int)valor("SELECT COUNT(*) FROM lancamento_tipo WHERE identificador = ? AND formato = 'instantaneo' AND no_id IN (" . implode(", ", arvore_nos($raiz)) . ")", [$texto]) === 0) {
                $erros[] = t("A marca: o identificador de um tipo de lançamento instantâneo (Cadastros › Tipos de lançamento: revisao, pilha) ou vazio.");
            }
            if (!$def["cor"]) {
                $cor = "";
            } elseif ($cor !== "" && preg_match("/^#[0-9a-f]{6}$/", $cor) !== 1) {
                $erros[] = t("A cor: #rrggbb ou vazio.");
            }
        }
        if (count($erros) === 0) {
            $vals = [$nome, $ordem, $comp !== "" ? $comp : null, $texto !== "" ? $texto : null, $cor !== "" ? $cor : null];
            if ($atual) {
                sql("UPDATE lista_item SET nome = ?, ordem = ?, comportamento = ?, texto = ?, cor = ? WHERE id = ?", array_merge($vals, [$id]));
            } else {
                sql("INSERT INTO lista_item (no_id, lista, identificador, nome, ordem, comportamento, texto, cor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", array_merge([$raiz, $lista, $ident], $vals));
                $id = ultimo_id();
            }
            $GLOBALS["LISTAS_VERSAO"] = ($GLOBALS["LISTAS_VERSAO"] ?? 0) + 1;
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $msg = t("{1}: {2} {3}.", [1 => t($def["nome"]), 2 => $nome, 3 => $atual ? t("salvo") : t("criado")]);
        }
    } elseif ($acao === "excluir" && $atual) {
        $uso = lista_item_em_uso($atual, $raiz);
        if ($uso > 0) {
            $erros[] = t("O item {1} está em uso ({2}): ele fica.", [1 => $atual["nome"], 2 => $uso]);
        } elseif ($ultimo($atual)) {
            $erros[] = t("O {1} é o último item que faz isso: a lista precisa dele.", [1 => $atual["nome"]]);
        } else {
            sql("DELETE FROM lista_item WHERE id = ?", [$id]);
            $GLOBALS["LISTAS_VERSAO"] = ($GLOBALS["LISTAS_VERSAO"] ?? 0) + 1;
            $msg = t("{1}: {2} excluído.", [1 => t(LISTAS[$atual["lista"]]["nome"]), 2 => $atual["nome"]]);
            $id = 0;
        }
    } elseif ($acao === "excluir") {
        $erros[] = t("Informe o item (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// A reserva medida de um relógio: os testes da reserva de marcha (o mecânico: o campo reserva_horas vale para ele) e as
// observações da energia (o mecânico e o solar, com o campo reserva_dias). Os instantes: AAAA-MM-DD HH:MM, na hora local;
// vazio: agora; nunca no futuro. Ações:
//   iniciar (relogio_id; inicio: quando ele ficou cheio; protocolo: corda, winder, pulso ou outro; protocolo_valor: as
//           voltas da coroa, as horas no winder ou no pulso; observacao): começa um teste. Um teste em andamento por
//           relógio.
//   parou (id, ou relogio_id: o teste em andamento dele; fim: quando ele parou, depois do inicio): termina o teste; as horas
//           de cheio até parado são a reserva medida.
//   observar (relogio_id; tipo: parou, carga_baixa ou nota; quando; observacao): uma observação da energia.
//   salvar (id; inicio, fim (só no teste; vazio: em andamento), protocolo, protocolo_valor, tipo (só na observação),
//           observacao): muda um teste ou uma observação; só o que vem no pedido muda.
//   usar (relogio_id): a mediana dos testes vira a reserva de marcha do cadastro do próprio relógio (o campo reserva_horas
//           dele, em horas inteiras): é a que vale na conta; o valor do calibre e o padrão do grupo não mudam.
//   excluir (id): sai o teste ou a observação.
// ---------------------------------------------------------------------------------------------------------------------
function op_reserva($acao, $d)
{
    $erros = [];
    $msg = "";
    $agora = time();
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM reserva_teste WHERE id = ?", [$id]) : null;
    $instante = function ($v, $rotulo) use ($agora, &$erros) {
        $v = str_replace("T", " ", trim((string)$v));
        if ($v === "") {
            return $agora;
        }
        $dt = DateTimeImmutable::createFromFormat("!Y-m-d H:i", substr($v, 0, 16));
        if (!$dt || $dt->format("Y-m-d H:i") !== substr($v, 0, 16)) {
            $erros[] = t("{1}: AAAA-MM-DD HH:MM.", [1 => $rotulo]);
            return null;
        }
        if ($dt->getTimestamp() > $agora + 60) {
            $erros[] = t("{1}: não pode ser no futuro.", [1 => $rotulo]);
            return null;
        }
        return $dt->getTimestamp();
    };
    $texto = function ($k) use ($d, $atual) { return array_key_exists($k, $d) ? trim((string)$d[$k]) : trim((string)($atual[$k] ?? "")); };
    $rid = $atual ? (int)$atual["relogio_id"] : (int)($d["relogio_id"] ?? 0);
    $r = $rid > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$rid]) : null;
    $campos = $r ? campos_do_relogio($r) : [];
    $testar = isset($campos["reserva_horas"]);
    $observar = $testar || isset($campos["reserva_dias"]);
    if ($acao === "parou" && !$atual && $r) {
        $atual = linha("SELECT * FROM reserva_teste WHERE relogio_id = ? AND tipo = 'teste' AND fim IS NULL ORDER BY inicio DESC, id DESC", [$rid]);
        $id = $atual ? (int)$atual["id"] : 0;
    }
    $obs = $texto("observacao");
    if (mb_strlen($obs) > 500) {
        $erros[] = t("A observação vai até 500 caracteres.");
    }
    if ($id > 0 && !$atual) {
        $erros[] = t("Teste ou observação não encontrado.");
    } elseif (!$r && in_array($acao, ["iniciar", "parou", "observar", "usar", "salvar"], true)) {
        $erros[] = t("relógio não encontrado");
    } elseif ($acao === "iniciar" || ($acao === "salvar" && $atual && $atual["tipo"] === "teste")) {
        if (!$testar) {
            $erros[] = t("O teste da reserva é do relógio com a reserva de marcha no cadastro (o mecânico); no solar, registre as observações.");
        }
        $ini = array_key_exists("inicio", $d) || !$atual ? $instante($d["inicio"] ?? "", t("Cheio em")) : strtotime($atual["inicio"]);
        $fim = $atual ? (array_key_exists("fim", $d) ? (trim((string)$d["fim"]) === "" ? null : $instante($d["fim"], t("Parou em"))) : ($atual["fim"] === null ? null : strtotime($atual["fim"]))) : null;
        $prot = $texto("protocolo") !== "" ? $texto("protocolo") : lista_padrao("reserva_protocolo", "outro");
        if (!isset(lista_itens("reserva_protocolo")[$prot])) {
            $erros[] = t("Protocolo: {1}.", [1 => implode(", ", array_keys(lista_itens("reserva_protocolo")))]);
        }
        $pv = $texto("protocolo_valor");
        $pv_n = $pv === "" ? null : numero_br($pv);
        if ($pv !== "" && ($pv_n === null || $pv_n <= 0 || $pv_n > 999)) {
            $erros[] = t("As voltas ou as horas para encher: um número de 0 a 999.");
        }
        if ($ini !== null && $fim !== null && $fim <= $ini) {
            $erros[] = t("Ele não pode parar antes de ficar cheio.");
        }
        $outro = $r ? linha("SELECT id, inicio FROM reserva_teste WHERE relogio_id = ? AND tipo = 'teste' AND fim IS NULL AND id <> ?", [$rid, $id]) : null;
        if ($outro && $fim === null) {
            $erros[] = t("Já há um teste em andamento neste relógio, desde {1}: marque quando ele parou, ou exclua.", [1 => data_txt(strtotime($outro["inicio"]), true)]);
        }
        if (count($erros) === 0) {
            $vals = [date("Y-m-d H:i:s", $ini), $fim === null ? null : date("Y-m-d H:i:s", $fim), $prot, $pv_n, $obs !== "" ? $obs : null];
            if ($atual) {
                sql("UPDATE reserva_teste SET inicio = ?, fim = ?, protocolo = ?, protocolo_valor = ?, observacao = ? WHERE id = ?", array_merge($vals, [$id]));
                $msg = t("Teste da reserva do {1} salvo.", [1 => $r["nome"]]);
            } else {
                sql("INSERT INTO reserva_teste (relogio_id, tipo, inicio, fim, protocolo, protocolo_valor, observacao, criado) VALUES (?, 'teste', ?, ?, ?, ?, ?, NOW())", array_merge([$rid], $vals));
                $id = ultimo_id();
                $msg = t("Teste da reserva do {1} começado: {2}, em {3}. Deixe ele parado, sem corda nem pulso, e marque quando ele parar.",
                    [1 => $r["nome"], 2 => reserva_protocolo_txt($prot, $pv_n), 3 => data_txt($ini, true)]);
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        }
    } elseif ($acao === "parou") {
        if (!$atual || $atual["tipo"] !== "teste") {
            $erros[] = t("Nenhum teste da reserva em andamento neste relógio.");
        } else {
            $fim = $instante($d["fim"] ?? "", t("Parou em"));
            if ($fim !== null && $fim <= strtotime($atual["inicio"])) {
                $erros[] = t("Ele não pode parar antes de ficar cheio.");
            }
            if (count($erros) === 0) {
                sql("UPDATE reserva_teste SET fim = ?" . (array_key_exists("observacao", $d) ? ", observacao = ?" : "") . " WHERE id = ?",
                    array_merge([date("Y-m-d H:i:s", $fim)], array_key_exists("observacao", $d) ? [$obs !== "" ? $obs : null] : [], [$id]));
                $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
                $m = reserva_medida($rid, $agora);
                $msg = t("O {1} parou: a reserva medida foi de {2} (de cheio, em {3}, até parado).", [1 => $r["nome"], 2 => reserva_horas_txt(($fim - strtotime($atual["inicio"])) / 3600),
                    3 => data_txt(strtotime($atual["inicio"]), true)])
                    . ($m["n"] > 1 ? t(" Com {1} testes, a mediana é {2}.", [1 => $m["n"], 2 => reserva_horas_txt($m["mediana"])]) : "");
            }
        }
    } elseif ($acao === "observar" || ($acao === "salvar" && $atual)) {
        if (!$observar) {
            $erros[] = t("As observações da energia são do relógio com a reserva no cadastro (o mecânico e o solar).");
        }
        $tipos_obs = array_diff(array_keys(lista_itens("reserva_observacao")), ["teste"]);
        $tipo = $texto("tipo") !== "" ? $texto("tipo") : (in_array("nota", $tipos_obs, true) ? "nota" : (string)(array_values($tipos_obs)[0] ?? "nota"));
        if (!in_array($tipo, $tipos_obs, true)) {
            $erros[] = t("Tipo da observação: {1}.", [1 => implode(", ", $tipos_obs)]);
        }
        $quando = array_key_exists("quando", $d) || !$atual ? $instante($d["quando"] ?? "", t("Quando")) : strtotime($atual["inicio"]);
        if ($tipo === "nota" && $obs === "") {
            $erros[] = t("A outra observação pede o texto.");
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE reserva_teste SET tipo = ?, inicio = ?, observacao = ? WHERE id = ?", [$tipo, date("Y-m-d H:i:s", $quando), $obs !== "" ? $obs : null, $id]);
            } else {
                sql("INSERT INTO reserva_teste (relogio_id, tipo, inicio, observacao, criado) VALUES (?, ?, ?, ?, NOW())", [$rid, $tipo, date("Y-m-d H:i:s", $quando), $obs !== "" ? $obs : null]);
                $id = ultimo_id();
            }
            $msg = t("Observação do {1} {2}: {3}, em {4}.", [1 => $r["nome"], 2 => $atual ? t("salva") : t("registrada"), 3 => mb_strtolower(lista_nome("reserva_observacao", $tipo), "UTF-8"), 4 => data_txt($quando, true)]);
        }
    } elseif ($acao === "usar") {
        $m = reserva_medida($rid, $agora);
        if (!$testar) {
            $erros[] = t("O teste da reserva é do relógio com a reserva de marcha no cadastro (o mecânico); no solar, registre as observações.");
        } elseif ($m["n"] === 0) {
            $erros[] = t("Nenhum teste da reserva terminado ainda: não há medida para usar.");
        } else {
            $h = (int)round($m["mediana"]);
            $conf = valor_para_campo($campos["reserva_horas"], (string)$h);
            if ($conf[1] !== "") {
                $erros[] = $conf[1];
            } else {
                valor_gravar("relogio", $rid, $campos["reserva_horas"], $conf[0]);
                $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
                [$nom, $o_n] = valor_nominal($r, "reserva_horas");
                $msg = t("A reserva de marcha do cadastro do {1} passou a {2} (a medida, em {3}).", [1 => $r["nome"], 2 => reserva_horas_txt($h),
                    3 => $m["n"] === 1 ? t("1 teste") : t("{1} testes", [1 => $m["n"]])])
                    . ($nom !== null ? t(" O {1} continua com {2}: apagando o valor do relógio no cadastro, volta a valer.", [1 => $o_n === "calibre" ? t("calibre") : t("padrão do grupo"), 2 => reserva_horas_txt((float)$nom)]) : "");
            }
        }
    } elseif ($acao === "excluir" && $atual) {
        sql("DELETE FROM reserva_teste WHERE id = ?", [$id]);
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $msg = $atual["tipo"] === "teste" ? t("Teste da reserva de {1} excluído.", [1 => data_txt(strtotime($atual["inicio"]), true)])
            : t("Observação de {1} excluída.", [1 => data_txt(strtotime($atual["inicio"]), true)]);
        $id = 0;
    } elseif ($acao === "excluir" || $acao === "salvar") {
        $erros[] = t("Informe o teste ou a observação (id).");
    } else {
        $erros[] = t("Ação desconhecida: use iniciar, parou, observar, salvar, usar ou excluir.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// O timegrapher de um relógio: as medições de um timegrapher de bancada, uma por posição. Os instantes: AAAA-MM-DD HH:MM,
// na hora local; vazio: agora; nunca no futuro. Os números com ponto ou vírgula. Ações:
//   salvar (id: 0 cria; relogio_id (só na nova); quando; posicao: mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo,
//           coroa_esquerda, coroa_direita ou vazio; marcha (s/dia), amplitude (graus), erro_batida (ms), bph, angulo (o
//           ângulo de levantamento, graus): pelo menos a marcha, a amplitude ou o erro de batida; observacao): uma medição
//           digitada; na que existe, só o que vem no pedido muda.
//   importar (relogio_id; csv: o texto do arquivo, ou arquivo_base64; quando: o instante das linhas sem data; conferir=1: só
//           mostra o que entraria, sem gravar): as medições do CSV do aparelho. O separador (; , ou tabulação) e as colunas
//           vêm do cabeçalho (os nomes em português ou em inglês: rate, amplitude, beat error, bph, lift angle, position,
//           date, time...); a linha que não se entende fica de fora, com o motivo; a repetida (o mesmo instante, a mesma
//           posição e os mesmos números) também.
//   excluir (id): sai a medição.
// ---------------------------------------------------------------------------------------------------------------------
function op_timegrapher($acao, $d)
{
    $erros = [];
    $msg = "";
    $extra = [];
    $agora = time();
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM timegrapher WHERE id = ?", [$id]) : null;
    $rid = $atual ? (int)$atual["relogio_id"] : (int)($d["relogio_id"] ?? 0);
    $r = $rid > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$rid]) : null;
    // a medição gravada: [quando, posicao, marcha, amplitude, erro_batida, bph, angulo, observacao]
    $gravar = function ($m, $origem) use ($rid) {
        sql("INSERT INTO timegrapher (relogio_id, quando, posicao, marcha, amplitude, erro_batida, bph, angulo, origem, observacao, criado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$rid, date("Y-m-d H:i:s", $m["quando"]), $m["posicao"], $m["marcha"], $m["amplitude"], $m["erro_batida"], $m["bph"] === null ? null : (int)round($m["bph"]), $m["angulo"], $origem, $m["observacao"]]);
        return ultimo_id();
    };
    if ($id > 0 && !$atual) {
        $erros[] = t("Medição do timegrapher não encontrada.");
    } elseif (!$r && in_array($acao, ["salvar", "importar"], true)) {
        $erros[] = t("relógio não encontrado");
    } elseif ($acao === "salvar") {
        $m = [];
        $q = array_key_exists("quando", $d) || !$atual ? str_replace("T", " ", trim((string)($d["quando"] ?? ""))) : null;
        if ($q === null) {
            $m["quando"] = strtotime((string)$atual["quando"]);
        } elseif ($q === "") {
            $m["quando"] = $agora;
        } else {
            $dt = DateTimeImmutable::createFromFormat("!Y-m-d H:i", substr($q, 0, 16));
            $m["quando"] = $dt && $dt->format("Y-m-d H:i") === substr($q, 0, 16) ? $dt->getTimestamp() : null;
            if ($m["quando"] === null) {
                $erros[] = t("{1}: AAAA-MM-DD HH:MM.", [1 => t("Quando")]);
            }
        }
        $p = array_key_exists("posicao", $d) ? trim((string)$d["posicao"]) : (string)($atual["posicao"] ?? "");
        if ($p !== "" && !in_array($p, TIMEGRAPHER_POSICOES, true)) {
            $erros[] = t("Posição: {1}, ou vazio.", [1 => implode(", ", TIMEGRAPHER_POSICOES)]);
        }
        $m["posicao"] = $p !== "" ? $p : null;
        foreach (array_keys(TIMEGRAPHER_GRANDEZAS) as $g) {
            $v = array_key_exists($g, $d) ? trim((string)$d[$g]) : (($atual[$g] ?? null) === null ? "" : (string)$atual[$g]);
            $m[$g] = $v === "" ? null : numero_br($v);
            if ($v !== "" && $m[$g] === null) {
                $erros[] = t("{1}: um número.", [1 => t(TIMEGRAPHER_GRANDEZAS[$g])]);
            }
        }
        $obs = array_key_exists("observacao", $d) ? trim((string)$d["observacao"]) : (string)($atual["observacao"] ?? "");
        if (mb_strlen($obs, "UTF-8") > 500) {
            $erros[] = t("A observação vai até 500 caracteres.");
        }
        $m["observacao"] = $obs !== "" ? $obs : null;
        if (count($erros) === 0 && ($e_m = timegrapher_conferir($m)) !== "") {
            $erros[] = mb_strtoupper(mb_substr($e_m, 0, 1, "UTF-8"), "UTF-8") . mb_substr($e_m, 1, null, "UTF-8") . ".";
        }
        if (count($erros) === 0) {
            if ($atual) {
                sql("UPDATE timegrapher SET quando = ?, posicao = ?, marcha = ?, amplitude = ?, erro_batida = ?, bph = ?, angulo = ?, observacao = ? WHERE id = ?",
                    [date("Y-m-d H:i:s", $m["quando"]), $m["posicao"], $m["marcha"], $m["amplitude"], $m["erro_batida"], $m["bph"] === null ? null : (int)round($m["bph"]), $m["angulo"], $m["observacao"], $id]);
                $msg = t("Medição do timegrapher do {1} salva.", [1 => $r["nome"]]);
            } else {
                $id = $gravar($m, "manual");
                $msg = t("Medição do timegrapher do {1} registrada ({2}, {3}).", [1 => $r["nome"], 2 => data_txt($m["quando"], true),
                    3 => $m["posicao"] === null ? t("sem posição") : posicoes_marcha()[$m["posicao"]]]);
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        }
    } elseif ($acao === "importar") {
        $txt = (string)($d["csv"] ?? "");
        if ($txt === "" && (string)($d["arquivo_base64"] ?? "") !== "") {
            $txt = (string)base64_decode((string)$d["arquivo_base64"], true);
        }
        $q = str_replace("T", " ", trim((string)($d["quando"] ?? "")));
        $dt = $q === "" ? null : DateTimeImmutable::createFromFormat("!Y-m-d H:i", substr($q, 0, 16));
        if (trim($txt) === "") {
            $erros[] = t("Mande o CSV do timegrapher (csv: o texto do arquivo, ou arquivo_base64).");
        } elseif (strlen($txt) > 2000000) {
            $erros[] = t("O CSV vai até 2 MB.");
        } elseif ($q !== "" && (!$dt || $dt->format("Y-m-d H:i") !== substr($q, 0, 16))) {
            $erros[] = t("{1}: AAAA-MM-DD HH:MM.", [1 => t("Quando")]);
        } else {
            $lido = timegrapher_ler_csv($txt, $dt ? $dt->getTimestamp() : $agora);
            if (isset($lido["erro"])) {
                $erros[] = $lido["erro"];
            } else {
                $conferir = (string)($d["conferir"] ?? "") === "1";
                $ja = [];
                foreach (linhas("SELECT quando, posicao, marcha, amplitude, erro_batida FROM timegrapher WHERE relogio_id = ?", [$rid]) as $x) {
                    $ja[implode("|", [date("Y-m-d H:i", strtotime((string)$x["quando"])), (string)$x["posicao"], $x["marcha"] === null ? "" : (float)$x["marcha"],
                        $x["amplitude"] === null ? "" : (float)$x["amplitude"], $x["erro_batida"] === null ? "" : (float)$x["erro_batida"]])] = true;
                }
                $entram = 0;
                $repetidas = 0;
                $linhas_r = [];
                foreach ($lido["linhas"] as $l) {
                    $m = $l["medicao"];
                    $estado = "recusada";
                    if ($m !== null) {
                        $chave = implode("|", [date("Y-m-d H:i", $m["quando"]), (string)$m["posicao"], $m["marcha"] === null ? "" : round($m["marcha"], 1),
                            $m["amplitude"] === null ? "" : round($m["amplitude"], 1), $m["erro_batida"] === null ? "" : round($m["erro_batida"], 2)]);
                        if (isset($ja[$chave])) {
                            $estado = "repetida";
                            $repetidas++;
                        } else {
                            $ja[$chave] = true;
                            $estado = "entra";
                            $entram++;
                            if (!$conferir) {
                                $gravar($m, "csv");
                            }
                        }
                    }
                    $linhas_r[] = ["linha" => $l["linha"], "estado" => $estado, "quando" => $m !== null ? date("Y-m-d H:i:s", $m["quando"]) : null, "posicao" => $m["posicao"] ?? null,
                        "marcha" => $m["marcha"] ?? null, "amplitude" => $m["amplitude"] ?? null, "erro_batida" => $m["erro_batida"] ?? null, "bph" => $m["bph"] ?? null,
                        "angulo" => $m["angulo"] ?? null, "erro" => $l["erro"]];
                }
                $recusadas = count($lido["linhas"]) - $entram - $repetidas;
                $extra = ["importacao" => ["conferir" => $conferir, "colunas" => (object)$lido["colunas"], "lidas" => count($lido["linhas"]), "entram" => $entram,
                    "repetidas" => $repetidas, "recusadas" => $recusadas, "linhas" => $linhas_r]];
                $partes = t("{1} de {2} linhas", [1 => $entram, 2 => count($lido["linhas"])]) . ($repetidas > 0 ? t("; {1} repetidas", [1 => $repetidas]) : "")
                    . ($recusadas > 0 ? t("; {1} recusadas", [1 => $recusadas]) : "");
                // o motivo das primeiras recusadas, no recado (todas estão em importacao.linhas)
                $motivos = array_slice(array_values(array_filter($linhas_r, function ($x) { return $x["estado"] === "recusada"; })), 0, 5);
                $motivos_txt = count($motivos) === 0 ? "" : " " . implode(" ", array_map(function ($x) { return t("Linha {1}: {2}.", [1 => $x["linha"], 2 => $x["erro"]]); }, $motivos))
                    . ($recusadas > 5 ? " " . t("E mais {1}.", [1 => $recusadas - 5]) : "");
                if ($conferir) {
                    $msg = t("Conferência do CSV do {1}: entrariam {2}. Nada foi gravado.", [1 => $r["nome"], 2 => $partes]) . $motivos_txt;
                } elseif ($entram === 0) {
                    $erros[] = t("Nenhuma medição do CSV entrou: {1}.", [1 => $partes]) . $motivos_txt;
                } else {
                    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
                    $msg = t("Medições do timegrapher do {1} importadas: {2}.", [1 => $r["nome"], 2 => $partes]) . $motivos_txt;
                }
            }
        }
    } elseif ($acao === "excluir" && $atual) {
        sql("DELETE FROM timegrapher WHERE id = ?", [$id]);
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $msg = t("Medição do timegrapher de {1} excluída.", [1 => data_txt(strtotime((string)$atual["quando"]), true)]);
        $id = 0;
    } elseif ($acao === "excluir") {
        $erros[] = t("Informe a medição (id).");
    } else {
        $erros[] = t("Ação desconhecida: use salvar, importar ou excluir.");
    }
    return resultado($erros, $msg, array_merge(["id" => $id], $extra));
}

// ---------------------------------------------------------------------------------------------------------------------
// Lançamentos. Ações:
//   lancar (relogio_id, tipo, valor (formato "valor"), quando (AAAA-MM-DD HH:MM; vazio: agora), medir (1 ou 0; padrão 1)):
//           instantâneo ou com valor. Num tipo que mede o gasto (a leitura de carga), a leitura é comparada com a anterior,
//           subiu: é recarga (novo ponto de partida, sem medir). Acima da estimativa, mas sem subir, mede normalmente.
//           Instantes diferentes: o intervalo é separado em horas no pulso e guardado, inclusive abaixo de uma hora
//           e mede o gasto em uso (com meio dia de uso ou mais) ou guardado. Com medir=1, a medição entra na média (a
//           caixa "Atualizar o gasto com esta medição"); com 0, fica só no histórico. Leitura com data anterior à última
//           reconstrói os intervalos vizinhos. estado (uso ou repouso; vazio: não diz): a marcação da leitura, que fica gravada nela e manda: o gasto
//           em uso sai só das leituras marcadas em uso, e o gasto em repouso só das marcadas em repouso. Se o pulso
//           registrado desde a leitura anterior não bate com ela, a API pergunta antes (confirmar: true, com a pergunta);
//           com confirmar=1, grava como a pessoa marcou.
//   medicao_criar (lancamento_id): recupera a medição ausente de uma leitura; preserva as existentes, inclusive usada=0.
//   iniciar (relogio_id, tipo, quando, posicao_id): abre uma sessão; se ela é exclusiva, fecha no mesmo instante a exclusiva
//                                       aberta em outro lugar (o relógio fica num lugar só). O winder é obrigatório: num tipo
//                                       marcado como winder, sem winder cadastrado, recusa; com winder, só pela página Winders
//                                       (o winder_posicoes salvar com relogio_id, que passa a posição e pela_pagina_winders)
//   encerrar (relogio_id, tipo, quando): fecha a sessão aberta daquele tipo
//   periodo (relogio_id, tipo, inicio, fim, posicao_id): uma sessão que já passou; num tipo marcado como winder, só com
//           winder cadastrado e com a posição onde ele esteve (sem outro relógio nela nesse tempo)
//   alterar (id; quando, inicio, fim, valor, estado, posicao_id (0: sem posição): só o que vier), excluir (id)
//   medicao (id da medição, usada 1 ou 0): a medição do gasto entra na conta ou fica só no histórico
// Nada no futuro. O tipo tem de valer para o relógio (o ponto dele na árvore). Sessões exclusivas não se sobrepõem.
// ---------------------------------------------------------------------------------------------------------------------
function op_lancamento($acao, $d)
{
    return banco_atomico(function () use ($acao, $d) {
        $erros = [];
        $msg = "";
        $id = (int)($d["id"] ?? 0);
        $agora = time();
        // Recupera uma medição ausente sem alterar as leituras nem as medições existentes (inclusive as tiradas da conta).
        if ($acao === "medicao_criar") {
            $lid = (int)($d["lancamento_id"] ?? 0);
            $l = linha("SELECT l.id, l.relogio_id, l.tipo_id, l.inicio, l.valor, t.formato, t.mede_gasto FROM lancamento l
                JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.id = ?", [$lid]);
            if (!$l || $l["formato"] !== "valor" || (int)$l["mede_gasto"] !== 1) {
                return resultado([t("Leitura de carga não encontrada (lancamento_id).")], "");
            }
            $existente_m = valor("SELECT id FROM medicao WHERE lancamento_id = ?", [$lid]);
            sincronizar_medicoes((int)$l["relogio_id"], [$lid], [], strtotime($l["inicio"]));
            $medicao_m = linha("SELECT id, valida FROM medicao WHERE lancamento_id = ? ORDER BY id LIMIT 1", [$lid]);
            if (!$medicao_m || (int)$medicao_m["valida"] !== 1) {
                return resultado([t("O intervalo não mede gasto: precisa de leitura anterior, instantes diferentes, carga sem aumento e nenhuma outra sessão além do pulso.")], "");
            }
            return resultado([], $existente_m !== null ? t("A medição já existe e foi preservada.") : t("Medição criada a partir das leituras registradas."), ["id" => (int)$medicao_m["id"]]);
        }
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
        // uma medição do gasto entra ou sai da conta (usada 1 ou 0), sem apagar nada: ela fica no histórico
        if ($acao === "medicao") {
            $m = linha("SELECT m.id, m.usada, l.relogio_id FROM medicao m JOIN lancamento l ON l.id = m.lancamento_id WHERE m.id = ?", [$id]);
            $usar = (string)($d["usada"] ?? "");
            if (!$m) {
                return resultado([t("Medição não encontrada (id).")], "");
            }
            if ($usar !== "0" && $usar !== "1") {
                return resultado([t("Usada: 1 (entra na conta) ou 0 (só no histórico).")], "");
            }
            sql("UPDATE medicao SET usada = ? WHERE id = ?", [(int)$usar, $id]);
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            return resultado([], $usar === "1" ? t("A medição voltou para a conta do gasto.") : t("A medição saiu da conta do gasto (fica no histórico)."), ["id" => $id]);
        }
        $existente = $id > 0 ? linha("SELECT l.*, t.identificador AS tipo, t.formato, t.exclusiva, t.nome AS tipo_nome FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.id = ?", [$id]) : null;
        // relogio_id=hoje: o relógio do dia, pelo plano (para um atalho no celular que não sabe o id)
        $hoje_pedido = !$existente && strtolower(trim((string)($d["relogio_id"] ?? ""))) === "hoje";
        $p_hoje = $hoje_pedido ? plano_do_dia(date("Y-m-d", $agora)) : null;
        $rid = $existente ? (int)$existente["relogio_id"] : ($hoje_pedido ? ($p_hoje ? (int)$p_hoje["relogio_id"] : 0) : (int)($d["relogio_id"] ?? 0));
        $r = linha("SELECT * FROM relogio WHERE id = ?", [$rid]);
        $t = $existente ? lancamento_tipos()[$existente["tipo"]] : ($r ? (lancamento_tipos_do_relogio($r)[(string)($d["tipo"] ?? "")] ?? null) : null);
        // as sessões esquecidas abertas do relógio fecham antes (o Pôs de hoje não esbarra na de ontem, nem o Tirou a estica)
        if ($r) {
            fechar_esquecidas($agora, $rid);
        }
        // alternar: a sessão aberta se fecha, a fechada se abre (um botão só no celular: "pulso", "carregador")
        if ($acao === "alternar" && $t && $t["formato"] === "sessao") {
            $acao = valor("SELECT id FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND inicio <= ? AND (fim IS NULL OR fim > ?) LIMIT 1",
                [$rid, (int)$t["id"], date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora)]) !== null ? "encerrar" : "iniciar";
        }
        // o lugar fixo: com winder cadastrado, o relógio que tem o lugar dele (winder_lugar) volta para lá ao pôr no winder de
        // qualquer lugar (o painel, o atalho, o aviso, a API), se a posição está ligada e livre; o período que já passou, sem a
        // posição, fica no lugar dele. Sem lugar (ou com ele ocupado, ou desligado), pôr no winder só pela página Winders
        $lugar_auto = null;
        $lugar_motivo = null;
        if ($r && $t && (int)($t["winder"] ?? 0) === 1 && in_array($acao, ["iniciar", "periodo"], true) && ($d["pela_pagina_winders"] ?? false) !== true && winder_cadastrado()) {
            $lg = winder_lugar_do_relogio($rid);
            if ($lg && ((int)($d["posicao_id"] ?? 0) <= 0 || (int)$d["posicao_id"] === (int)$lg["id"])) {
                $oc_lg = $acao === "iniciar" ? winder_posicao_ocupante((int)$lg["id"], $agora) : null;
                if ($acao === "iniciar" && (int)$lg["ligada"] !== 1) {
                    $lugar_motivo = t("o lugar dele ({1}, {2}) está desligado", [1 => $lg["winder"], 2 => $lg["nome"]]);
                } elseif ($oc_lg && (int)$oc_lg["relogio_id"] !== $rid) {
                    $lugar_motivo = t("o lugar dele ({1}, {2}) está com o {3}", [1 => $lg["winder"], 2 => $lg["nome"], 3 => $oc_lg["nome"]]);
                } else {
                    $d["posicao_id"] = (int)$lg["id"];
                    $d["pela_pagina_winders"] = $acao === "iniciar" ? true : ($d["pela_pagina_winders"] ?? false);
                    $lugar_auto = t("{1}, {2}", [1 => $lg["winder"], 2 => $lg["nome"]]);
                }
            }
        }
        // a posição de winder do pedido (posicao_id; vazio ou 0: nenhuma) para o trecho [a, b) (b null: aberta): só num tipo marcado
        // como winder; tem de existir, estar ligada (ao abrir uma sessão agora) e sem outro relógio nesse trecho. [posição, erro]
        $posicao_conferir = function ($a, $b, $abrir) use ($d, $t, $rid) {
            $pid = (int)($d["posicao_id"] ?? 0);
            if ($pid <= 0 || !$t) {
                return [null, null];
            }
            if ((int)($t["winder"] ?? 0) !== 1) {
                return [null, t("{1} não é numa posição de winder: a posição vale só nos tipos marcados como winder (Cadastros › Tipos de lançamento).", [1 => $t["nome"]])];
            }
            $pos = linha("SELECT p.*, w.nome AS winder FROM winder_posicao p JOIN winder w ON w.id = p.winder_id WHERE p.id = ?", [$pid]);
            if (!$pos) {
                return [null, t("Posição de winder não encontrada (posicao_id).")];
            }
            $texto = t("{1}, {2}", [1 => $pos["winder"], 2 => $pos["nome"]]);
            if ($abrir && (int)$pos["ligada"] !== 1) {
                return [null, t("A posição {1} está desligada.", [1 => $texto])];
            }
            $outro = linha("SELECT r.nome FROM lancamento_winder lw JOIN lancamento l ON l.id = lw.lancamento_id JOIN relogio r ON r.id = l.relogio_id
                WHERE lw.posicao_id = ? AND l.relogio_id <> ? AND l.inicio < ? AND (l.fim IS NULL OR l.fim > ?) LIMIT 1", [$pid, $rid, $b === null ? "9999-12-31 00:00:00" : date("Y-m-d H:i:s", $b), date("Y-m-d H:i:s", $a)]);
            if ($outro) {
                return [null, t("A posição {1} já está com o {2} nesse tempo.", [1 => $texto, 2 => $outro["nome"]])];
            }
            return [$pos + ["texto" => $texto], null];
        };
        // grava a posição de winder de um lançamento (a do pedido; 0: tira a que havia)
        $posicao_gravar = function ($lid) use ($d) {
            sql("DELETE FROM lancamento_winder WHERE lancamento_id = ?", [(int)$lid]);
            if ((int)($d["posicao_id"] ?? 0) > 0) {
                sql("INSERT INTO lancamento_winder (lancamento_id, posicao_id) VALUES (?, ?)", [(int)$lid, (int)$d["posicao_id"]]);
            }
        };
        // sessões exclusivas do relógio que se sobrepõem a [a, b) (fora a $ignorar)
        $sobrepostas = function ($a, $b, $ignorar) use ($rid) {
            return linhas("SELECT l.id, l.tipo_id, t.nome, l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
                WHERE l.relogio_id = ? AND t.exclusiva = 1 AND l.id <> ? AND l.inicio < ? AND (l.fim IS NULL OR l.fim > ?)",
                [$rid, $ignorar, date("Y-m-d H:i:s", $b), date("Y-m-d H:i:s", $a)]);
        };
        if (($acao === "alterar" || $acao === "excluir") && !$existente) {
            $erros[] = t("Lançamento não encontrado.");
        } elseif ($hoje_pedido && !$p_hoje) {
            $erros[] = t("Nenhum relógio para hoje no plano (relogio_id=hoje).");
        } elseif (!$r) {
            $erros[] = t("Relógio não encontrado (relogio_id).");
        } elseif (!$t) {
            $erros[] = t("Tipo de lançamento desconhecido para {1}: use um destes: {2}.", [1 => $r["nome"], 2 => implode(", ", array_keys(lancamento_tipos_do_relogio($r)))]);
        } elseif ((int)($t["winder"] ?? 0) === 1 && in_array($acao, ["iniciar", "periodo"], true) && !winder_cadastrado()) {
            // o winder é obrigatório: sem winder cadastrado, não há pôr no winder (nem pelo alternar, que aqui já virou iniciar);
            // o tirar de uma sessão que já estava aberta continua
            $erros[] = t("{1}: sem winder cadastrado, não há pôr no winder. Cadastre o seu na página Winders (recurso=winders).", [1 => $r["nome"]]);
        } elseif ((int)($t["winder"] ?? 0) === 1 && $acao === "iniciar" && ($d["pela_pagina_winders"] ?? false) !== true) {
            // com winder cadastrado, o relógio vai para o winder só pela página Winders, numa posição: o winder_posicoes salvar com
            // relogio_id chama este iniciar com pela_pagina_winders (só por dentro: o que vem do pedido é texto, nunca true)
            $erros[] = $lugar_motivo !== null
                ? t("{1}: {2}; ponha pela página Winders, numa posição (recurso=winder_posicoes, acao=salvar, com o relogio_id).", [1 => $r["nome"], 2 => $lugar_motivo])
                : t("{1}: com winder cadastrado, o relógio vai para o winder pela página Winders, numa posição (recurso=winder_posicoes, acao=salvar, com o relogio_id); depois, de qualquer lugar, ele volta para o lugar dele.", [1 => $r["nome"]]);
        } elseif ((int)($t["winder"] ?? 0) === 1 && $acao === "periodo" && (int)($d["posicao_id"] ?? 0) <= 0) {
            $erros[] = t("{1}: com winder cadastrado, o período no winder precisa da posição onde ele esteve (posicao_id).", [1 => $r["nome"]]);
        } elseif ($acao === "alternar") {
            $erros[] = t("{1} não é uma sessão: alternar vale só para as sessões (abre a fechada, fecha a aberta).", [1 => $t["nome"]]);
        } elseif ($acao === "lancar") {
            $q = $momento($d["quando"] ?? "");
            $v = numero_br($d["valor"] ?? "");
            $estado = strtolower(trim((string)($d["estado"] ?? "")));
            $pulso_t = lancamento_tipos_do_relogio($r)["pulso"] ?? null;
            // a leitura do desvio da hora: o desvio direto (valor, em segundos) ou a hora que o relógio mostrava (marca) no instante
            // da leitura (instante_ms, o da hora certa na tela; sem ele, agora); a posição em que ele ficou desde a anterior
            $marcha_t = $t["marcha"] ?? null;
            $posicao = strtolower(trim((string)($d["posicao"] ?? "")));
            $marca_ok = true;
            if ($marcha_t === "desvio" && trim((string)($d["marca"] ?? "")) !== "") {
                [$v_m, $q_m] = desvio_da_marca((string)$d["marca"], (string)($d["instante_ms"] ?? ""), $agora);
                $marca_ok = $v_m !== null;
                if ($marca_ok) {
                    $v = $v_m;
                    $q = $q_m;
                }
            }
            if ($t["formato"] === "sessao") {
                $erros[] = t("{1} é uma sessão: use iniciar e encerrar, ou periodo.", [1 => $t["nome"]]);
            } elseif ($q === false) {
                $erros[] = t("Quando: AAAA-MM-DD HH:MM, até agora (vazio: agora).");
            } elseif (!$marca_ok) {
                $erros[] = t("Marca: a hora que o relógio mostra, HH:MM:SS (o instante da leitura vai em instante_ms, em milissegundos, de até 10 minutos atrás; sem ele, agora).");
            } elseif ($t["formato"] === "valor" && $v === null) {
                $erros[] = t("{1} precisa de um valor (número{2}).", [1 => $t["nome"], 2 => $t["unidade"] !== "" ? ", em " . $t["unidade"] : ""]);
            } elseif ($marcha_t === "desvio" && abs($v) > 21600) {
                $erros[] = t("O desvio vai até 6 horas para mais ou para menos (em segundos; + adiantado, − atrasado).");
            } elseif ($posicao !== "" && ($marcha_t !== "desvio" || !isset(posicoes_marcha()[$posicao]))) {
                $erros[] = t("Posição ({1}) vale só para a leitura do desvio da hora.", [1 => implode(", ", array_keys(posicoes_marcha()))]);
            } elseif ($estado !== "" && !in_array($estado, ["uso", "repouso"], true)) {
                $erros[] = t("Estado: uso ou repouso (vazio: não diz).");
            } elseif ($estado !== "" && ($t["formato"] !== "valor" || $pulso_t === null)) {
                $erros[] = t("Estado (uso ou repouso) vale só para uma leitura, num relógio que vai ao pulso.");
            } elseif ($estado !== "" && (string)($d["confirmar"] ?? "") !== "1" && ($pergunta = estado_leitura_confere($rid, $q, $estado)) !== null) {
                // a marcação não bate com o pulso registrado: a pessoa confirma (confirmar=1) e vale o que ela marcou
                return resultado([$pergunta], "", ["confirmar" => true, "pergunta" => $pergunta]);
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
                sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, estado, posicao, origem, criado) VALUES (?, ?, ?, NULL, ?, ?, ?, 'manual', NOW())",
                    [$rid, (int)$t["id"], date("Y-m-d H:i:s", $q), $t["formato"] === "valor" ? $v : null, $estado !== "" ? $estado : null, $posicao !== "" ? $posicao : null]);
                $id = ultimo_id();
                $medicao_nova = null;
                if ((int)($t["mede_gasto"] ?? 0) === 1) {
                    sincronizar_medicoes($rid, true, [$id => (string)($d["medir"] ?? "1") === "1" ? 1 : 0], $q);
                    $medicoes_refeitas = true;
                    $medicao_nova = linha("SELECT * FROM medicao WHERE lancamento_id = ? AND valida = 1 ORDER BY id LIMIT 1", [$id]);
                }
                $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
                $msg = t("Lançado no {1}: {2}{3}{4}.", [1 => $r["nome"], 2 => $t["nome"], 3 => $t["formato"] === "valor" ? " " . decimal_txt($v, 6) . ($t["unidade"] !== "" ? " " . $t["unidade"] : "") : "", 4 => $q < $agora - 60 ? " em " . data_txt($q, true) : ""]);
                if ($marcha_t !== null) {
                    $msg .= precisao_recado($rid, $id, $marcha_t);
                }
                if ($mede && !$antes) {
                    $msg .= t(" Primeira leitura: é o ponto de partida.");
                } elseif ($antes) {
                    $t1 = strtotime($antes["inicio"]);
                    $horas = ($q - $t1) / 3600;
                    $anterior = (float)$antes["valor"];
                    $queda = $anterior - $v;
                    $estimada = is_numeric($conta["energia"]) ? (float)$conta["energia"] : null;
                    $un = $t["unidade"];
                    $de = t("De {1}{2} em {3} a {4}{5}{6}", [1 => decimal_txt($anterior, 6), 2 => $un, 3 => data_txt($t1, true, true), 4 => decimal_txt($v, 6), 5 => $un, 6 => $q < $agora - 60 ? t(" em {1}", [1 => data_txt($q, true, true)]) : t(" agora")]);
                    $pela_estimativa = $estimada === null ? "" : t(" A estimativa era ~{1}{2}.", [1 => (int)round($estimada), 2 => $un]);
                    if ($queda < 0) {
                        $msg .= t(" Carregado: a leitura anterior era {1}{2}{3}. Novo ponto de partida.", [1 => decimal_txt($anterior, 6), 2 => $un, 3 => $estimada === null ? "" : " e a estimativa ~" . (int)round($estimada) . $un]);
                    } elseif ($medicao_nova === null && $horas > 0) {
                        $msg .= t(" {1}: outra sessão no intervalo impede medir o gasto.{2}", [1 => $de, 2 => $pela_estimativa]);
                    } elseif ($horas <= 0) {
                        $msg .= t(" {1}: a leitura anterior está no mesmo instante, sem intervalo para medir o gasto.{2}", [1 => $de, 2 => $pela_estimativa]);
                    } else {
                        // as horas no pulso entre as duas leituras
                        $medicao = $medicao_nova;
                        $h_pulso = $medicao["horas_pulso"];
                        $h_guardado = $medicao["horas_guardado"];
                        $tempo = t("{1}{2} h fora do pulso", [1 => $h_pulso >= 1 ? t("{1} h no pulso e ", [1 => (int)round($h_pulso)]) : "", 2 => (int)round($h_guardado)]);
                        // todo intervalo entra na conta dos dois gastos (gasto_medido), até o sem queda nenhuma: ele diz que o gasto é
                        // pequeno. O gasto do intervalo sozinho fica no histórico: com pelo menos meio dia de uso, ou mais dias de uso que
                        // fora do pulso, o de uso (descontando o fora do pulso que valia); senão, o de fora (descontando o pouco de uso)
                        $medida = $medicao["medida"];
                        $taxa = $medicao["taxa"];
                        $usada = !array_key_exists("medir", $d) || (string)$d["medir"] === "1";
                        $id_med = (int)$medicao["id"];
                        $msg .= " " . $de . ": " . $tempo . "." . $pela_estimativa;
                        if ($usada) {
                            // os dois gastos que passam a valer, com esta medição junto (a mesma conta da função MEDIDO)
                            $motor_l = gasto_motor_de($r)[0];
                            $g = gasto_medido(medicoes_da_conta($rid)[0], $q, gasto_prior($r, $q), $motor_l === "formulas" ? "marcacao" : $motor_l);
                            $num = function ($x) { return decimal_txt(round((float)$x, 2), 6); };
                            $partes = [];
                            if ($g["uso"] !== null) {
                                $partes[] = t("em uso {1}% (± {2}) por dia de uso", [1 => $num($g["uso"]), 2 => $num($g["uso_dp"])]);
                            }
                            if ($g["repouso"] !== null) {
                                $partes[] = t("fora do pulso {1}% (± {2}) por dia", [1 => $num($g["repouso"]), 2 => $num($g["repouso_dp"])]);
                            }
                            $msg .= t(" Gasto medido agora{1} ({2}{3}{4} {5}).", [1 => count($partes) > 0 ? ": " . implode(", ", $partes) : t(": ainda sem como separar"), 2 => $g["conjunta"] ? t("os dois juntos, de ") : "", 3 => $g["n"], 4 => $g["n"] === 1 ? t(" medição") : t(" medições"), 5 => medicoes_janela_texto()]);
                            // uma leitura desta medição não bate com as outras: ela pesa pouco na conta (a tabela Medições do gasto diz)
                            if (in_array($id_med, $g["fora"], true)) {
                                $msg .= t(" A leitura de {1}% não bate com as outras (a conta erra {2} pontos nela): pesa só {3}% na conta. Confira as marcações do intervalo (o pulso, o carregador) e a leitura.",
                                    [1 => decimal_txt((float)$g["leitura"][$id_med], 6), 2 => decimal_txt(abs((float)$g["erro"][$id_med]), 6), 3 => (int)round(100 * (float)$g["peso"][$id_med])]);
                            }
                        } else {
                            $msg .= t(" Entrou só no histórico.");
                        }
                    }
                }
            }
        } elseif ($acao === "iniciar") {
            $q = $momento($d["quando"] ?? "");
            if ($t["formato"] !== "sessao") {
                $erros[] = t("{1} não é uma sessão: use lancar.", [1 => $t["nome"]]);
            } elseif ($q === false) {
                $erros[] = t("Quando: AAAA-MM-DD HH:MM, até agora (vazio: agora).");
            } elseif (valor("SELECT id FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND inicio <= ? AND (fim IS NULL OR fim > ?)", [$rid, (int)$t["id"], date("Y-m-d H:i:s", $q), date("Y-m-d H:i:s", $q)]) !== null) {
                $erros[] = t("{1} já está com {2} aberto.", [1 => $r["nome"], 2 => $t["nome"]]);
            } else {
                $fechadas = [];
                [$pos_w, $erro_w] = $posicao_conferir($q, null, true);
                if ($erro_w !== null) {
                    $erros[] = $erro_w;
                }
                if (count($erros) === 0 && (int)$t["exclusiva"] === 1) {
                    // primeiro a conferência, sem mexer em nada: nesse instante, o relógio não pode estar numa sessão exclusiva
                    // já fechada (um período lançado antes) nem numa aberta que começou depois dele
                    $conflito = linha("SELECT t.nome, l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
                        WHERE l.relogio_id = ? AND t.exclusiva = 1 AND l.inicio > ? AND l.inicio <= ? LIMIT 1",
                        [$rid, date("Y-m-d H:i:s", $q), date("Y-m-d H:i:s", $agora)]);
                    if ($conflito) {
                        $erros[] = t("Nesse momento {1} já estava em {2} (de {3} a {4}): o relógio fica num lugar só.", [1 => $r["nome"], 2 => $conflito["nome"], 3 => data_txt(strtotime($conflito["inicio"]), true, true), 4 => $conflito["fim"] === null ? "agora" : data_txt(strtotime($conflito["fim"]), true, true)]);
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
                    // o Pôs no relógio do dia é o uso do rodízio (não "fora do plano"): sem pôr sozinho, é ele que abre o dia; pondo
                    // sozinho, um Pôs antes do início do horário já conta como a sessão do dia (o cron não abre outra). Aberta até o
                    // Tirou, ou até o "fecha às" do tipo (o fim do horário de uso, tirando sozinho)
                    $p_dia = plano_do_dia(date("Y-m-d", $q));
                    $origem = $t["identificador"] === "pulso" && $p_dia && (int)$p_dia["relogio_id"] === $rid ? "rodizio" : "manual";
                    sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, NULL, NULL, ?, NOW())", [$rid, (int)$t["id"], date("Y-m-d H:i:s", $q), $origem]);
                    $id = ultimo_id();
                    if ($pos_w !== null) {
                        $posicao_gravar($id);
                    }
                    $msg = t("{1}: {2} desde {3}.{4}", [1 => $r["nome"], 2 => $t["nome"], 3 => data_txt($q, true, true), 4 => count($fechadas) > 0 ? t(" Fechado no mesmo instante: {1}.", [1 => implode(", ", $fechadas)]) : ""])
                        . ($pos_w !== null ? t(" Na posição {1}.", [1 => $pos_w["texto"]]) : "");
                }
            }
        } elseif ($acao === "encerrar") {
            $q = $momento($d["quando"] ?? "");
            // a sessão aberta agora: sem fim, ou com o fim ainda no futuro (a de rodízio já nasce com o fim do horário de uso)
            $aberta = linha("SELECT * FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND inicio <= ? AND (fim IS NULL OR fim > ?) ORDER BY inicio DESC, id DESC LIMIT 1",
                [$rid, (int)$t["id"], date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora)]);
            if ($q === false) {
                $erros[] = t("Quando: AAAA-MM-DD HH:MM, até agora (vazio: agora).");
            } elseif (!$aberta) {
                $erros[] = t("{1} não está com {2} aberto.", [1 => $r["nome"], 2 => $t["nome"]]);
            } elseif ($q < strtotime($aberta["inicio"])) {
                $erros[] = t("Quando: AAAA-MM-DD HH:MM, não antes do início ({1}) e até agora (vazio: agora).", [1 => data_txt(strtotime($aberta["inicio"]), true, true)]);
            } else {
                sql("UPDATE lancamento SET fim = ? WHERE id = ?", [date("Y-m-d H:i:s", $q), (int)$aberta["id"]]);
                $id = (int)$aberta["id"];
                $min = (int)round(($q - strtotime($aberta["inicio"])) / 60);
                $msg = t("{1}: encerrada a sessão {2} ({3} h {4} min).", [1 => $r["nome"], 2 => $t["nome"], 3 => intdiv($min, 60), 4 => $min % 60]);
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
                $erros[] = t("{1} não é uma sessão: use lancar.", [1 => $t["nome"]]);
            } elseif ($ini === false || $fim === false) {
                $erros[] = t("Início e fim: AAAA-MM-DD HH:MM, até agora.");
            } elseif ($fim !== null && $fim <= $ini) {
                $erros[] = t("O fim tem de ser depois do início.");
            } elseif ($formato === "valor" && $v === null) {
                $erros[] = t("{1} precisa de um valor.", [1 => $t["nome"]]);
            } elseif ((int)$t["exclusiva"] === 1 && count($outras = array_values(array_filter($sobrepostas($ini, $fim ?? $agora, $id), function ($x) use ($acao, $t) {
                // no período, as do mesmo tipo não atrapalham: elas se juntam a ele (logo abaixo)
                return $acao !== "periodo" || (int)$x["tipo_id"] !== (int)$t["id"];
            }))) > 0) {
                $s = $outras[0];
                $erros[] = t("{1} já estava em {2} de {3} a {4}: o relógio fica num lugar só.", [1 => $r["nome"], 2 => $s["nome"], 3 => data_txt(strtotime($s["inicio"]), true, true), 4 => $s["fim"] === null ? "agora" : data_txt(strtotime($s["fim"]), true, true)]);
            } elseif ($acao === "alterar" && array_key_exists("estado", $d) && !in_array((string)$d["estado"], ["", "uso", "repouso"], true)) {
                $erros[] = t("Estado: uso ou repouso (vazio: não diz).");
            } elseif ($acao === "alterar" && array_key_exists("posicao", $d) && (string)$d["posicao"] !== "" && (($t["marcha"] ?? null) !== "desvio" || !isset(posicoes_marcha()[(string)$d["posicao"]]))) {
                $erros[] = t("Posição ({1}) vale só para a leitura do desvio da hora.", [1 => implode(", ", array_keys(posicoes_marcha()))]);
            } elseif (array_key_exists("posicao_id", $d) && ($erro_w = $posicao_conferir($ini, $fim, false)[1]) !== null) {
                $erros[] = $erro_w;
            } elseif ($acao === "periodo") {
                // no pulso, o relógio do dia está em uso pelo rodízio (como no Pôs), não "fora do plano"
                $p_dia = plano_do_dia(date("Y-m-d", $ini));
                $origem = $t["identificador"] === "pulso" && $p_dia && (int)$p_dia["relogio_id"] === $rid ? "rodizio" : "manual";
                // os trechos do mesmo tipo que o período cobre ou em que ele encosta (o Pôs aberto que começou depois, o período
                // que acaba quando ele começa) viram um só: do primeiro início ao último fim (aberto, se um deles está aberto)
                $juntar = linhas("SELECT id, inicio, fim, origem FROM lancamento WHERE relogio_id = ? AND tipo_id = ? AND inicio <= ? AND (fim IS NULL OR fim >= ?) ORDER BY inicio, id",
                    [$rid, (int)$t["id"], date("Y-m-d H:i:s", $fim), date("Y-m-d H:i:s", $ini)]);
                if (count($juntar) === 0) {
                    sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, ?, NOW())",
                        [$rid, (int)$t["id"], date("Y-m-d H:i:s", $ini), date("Y-m-d H:i:s", $fim), $origem]);
                    $id = ultimo_id();
                    if (array_key_exists("posicao_id", $d)) {
                        $posicao_gravar($id);
                    }
                    $msg = t("Lançado no {1}: {2} de {3} a {4}.", [1 => $r["nome"], 2 => $t["nome"], 3 => data_txt($ini, true, true), 4 => data_txt($fim, true, true)]);
                } else {
                    $aberto = false;
                    foreach ($juntar as $x) {
                        $ini = min($ini, strtotime($x["inicio"]));
                        $aberto = $aberto || $x["fim"] === null;
                        $fim = $x["fim"] === null ? $fim : max($fim, strtotime($x["fim"]));
                        $origem = $x["origem"] === "rodizio" ? "rodizio" : $origem;
                    }
                    $id = (int)$juntar[0]["id"];
                    sql("UPDATE lancamento SET inicio = ?, fim = ?, origem = ? WHERE id = ?", [date("Y-m-d H:i:s", $ini), $aberto ? null : date("Y-m-d H:i:s", $fim), $origem, $id]);
                    foreach (array_slice($juntar, 1) as $x) {
                        sql("DELETE FROM lancamento WHERE id = ?", [(int)$x["id"]]);
                    }
                    if (array_key_exists("posicao_id", $d)) {
                        $posicao_gravar($id);
                    }
                    $msg = $aberto
                        ? t("Juntado no {1}: {2} desde {3} (com o que já estava marcado).", [1 => $r["nome"], 2 => $t["nome"], 3 => data_txt($ini, true, true)])
                        : t("Juntado no {1}: {2} de {3} a {4} (com o que já estava marcado).", [1 => $r["nome"], 2 => $t["nome"], 3 => data_txt($ini, true, true), 4 => data_txt($fim, true, true)]);
                }
            } else {
                // a marcação da leitura (uso ou repouso; vazio: nenhuma) muda só se vier
                $est = $formato === "valor" && array_key_exists("estado", $d) ? ((string)$d["estado"] === "" ? null : (string)$d["estado"]) : ($existente["estado"] ?? null);
                // a posição da leitura do desvio (vazio: não informada) também muda só se vier
                $pos_l = ($t["marcha"] ?? null) === "desvio" && array_key_exists("posicao", $d) ? ((string)$d["posicao"] === "" ? null : (string)$d["posicao"]) : ($existente["posicao"] ?? null);
                sql("UPDATE lancamento SET inicio = ?, fim = ?, valor = ?, estado = ?, posicao = ? WHERE id = ?", [date("Y-m-d H:i:s", $ini), $fim === null ? null : date("Y-m-d H:i:s", $fim), $v, $est, $pos_l, $id]);
                // a posição de winder da sessão muda só se vier (0: sem posição)
                if (array_key_exists("posicao_id", $d)) {
                    $posicao_gravar($id);
                }
                $msg = t("Corrigido no {1}: {2}.", [1 => $r["nome"], 2 => $t["nome"]]);
            }
        } elseif ($acao === "excluir") {
            sql("DELETE FROM lancamento WHERE id = ?", [$id]);
            $msg = t("Excluído do {1}: {2} de {3}.", [1 => $r["nome"], 2 => $existente["tipo_nome"], 3 => data_txt(strtotime($existente["inicio"]), true)]);
            $id = 0;
        }
        // Leituras e sessões mudam os intervalos vizinhos: fontes corrigidas não podem deixar quedas ou horas antigas.
        if (count($erros) === 0 && empty($medicoes_refeitas) && $t && ((int)($t["mede_gasto"] ?? 0) === 1 || $t["formato"] === "sessao")
            && in_array($acao, ["lancar", "iniciar", "encerrar", "periodo", "alterar", "excluir"], true)) {
            $escolhas_m = $acao === "lancar" && $id > 0 && (string)($d["medir"] ?? "1") === "0" ? [$id => 0] : [];
            $desde_m = [$agora];
            foreach ([$q ?? null, $ini ?? null, $existente ? strtotime($existente["inicio"]) : null] as $instante_m) {
                if (is_int($instante_m)) $desde_m[] = $instante_m;
            }
            sincronizar_medicoes($rid, true, $escolhas_m, min($desde_m));
        }
        // o relógio voltou para o lugar dele no winder (sem a página Winders): o recado diz onde
        if (count($erros) === 0 && $lugar_auto !== null && $msg !== "") {
            $msg .= " " . t("No lugar dele: {1}.", [1 => $lugar_auto]);
        }
        return resultado($erros, $msg, ["id" => $id]);
    });
}


// ---------------------------------------------------------------------------------------------------------------------
// Avisos. Ações: novo (identificador, nome, expressao (a data prevista), condicao (vale quando: uma fórmula, 1 vale e 0 não;
// vazia, vale sempre; ex.: corda_manual), antecedencia_dias, texto ({relogio}, {data},
// {quando}, {limite}), resolve (o tipo de lançamento que resolve; vazio: nenhum), ativo (1 ou 0), no_id: um aviso novo, ou mais
// uma versão de um que existe, em outro ponto da árvore; escala: nao, uso ou sempre; simula_valor e simula_horas (o
// lançamento que a escala simula; vazio: nenhum); agenda: janela (na agenda, só dentro da antecedência) ou sempre (qualquer
// data; se vai ou não para cada destino e cada agenda se escolhe neles, na página Avisos, em Para onde vão); na_hora (1 ou 0: o cron manda pelo Telegram
// no minuto em que ele vence, uma vez, além do alerta da manhã)), alterar (id: o mesmo, menos o identificador), excluir (id).
// ---------------------------------------------------------------------------------------------------------------------
function op_avisos($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha(AVISO_SELECT . " WHERE a.id = ?", [$id]) : null;
    if (($acao === "alterar" || $acao === "excluir") && !$atual) {
        $erros[] = t("Aviso não encontrado.");
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
        $na_hora = array_key_exists("na_hora", $d) ? ((string)$d["na_hora"] === "1" ? 1 : 0) : ($atual ? (int)$atual["na_hora"] : 0);
        $simula = [];
        foreach (["simula_valor", "simula_horas"] as $k) {
            $bruto = array_key_exists($k, $d) ? trim((string)$d[$k]) : ($atual[$k] ?? "");
            $simula[$k] = $bruto === null || $bruto === "" ? null : numero_br($bruto);
            if ($bruto !== null && $bruto !== "" && ($simula[$k] === null || $simula[$k] < 0 || ($k === "simula_horas" && $simula[$k] > 48))) {
                $erros[] = $k === "simula_valor" ? t("Valor simulado na escala: um número (vazio: nenhum).") : t("Horas simuladas na escala: de 0 a 48 (vazio: nenhuma).");
            }
        }
        if (!in_array($escala, ["nao", "uso", "sempre"], true)) {
            $erros[] = t("Na escala: nao, uso ou sempre.");
        }
        if (!in_array($agenda, ["janela", "sempre"], true)) {
            $erros[] = t("Na agenda: janela (só dentro da antecedência da agenda) ou sempre (qualquer data).");
        }
        if (!identificador_valido($ident)) {
            $erros[] = t("Identificador: minúsculas, números e _, começando por letra, até 40.");
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = t("Dê um nome ao aviso (até 120 caracteres).");
        }
        if ($texto === "" || strlen($texto) > 300) {
            $erros[] = t("Escreva o texto do aviso (até 300 caracteres; pode usar {relogio}, {data}, {quando} e {limite}).");
        }
        if ($ante === null || $ante < 0 || $ante > 3650) {
            $erros[] = t("Antecedência: de 0 a 3650 dias.");
        }
        if ($resolve !== "" && !isset(lancamento_tipos()[$resolve])) {
            $erros[] = t("Resolve: tipo de lançamento desconhecido ({1}).", [1 => $resolve]);
        }
        if ($no === false) {
            $erros[] = t("O ponto da árvore não existe.");
        } elseif ((int)valor("SELECT COUNT(*) FROM aviso WHERE identificador = ? AND no_id <=> ? AND id <> ?", [$ident, $no, $id]) > 0) {
            $erros[] = t("O aviso {1} já tem uma versão em {2}: altere aquela.", [1 => $ident, 2 => no_caminho($no)]);
        }
        if ($expr === "") {
            $erros[] = t("Escreva a fórmula da data prevista.");
        } else {
            foreach (formula_validar("", $expr) as $e) {
                $erros[] = t("Data prevista: {1}.", [1 => $e]);
            }
        }
        if ($condicao !== "") {
            foreach (formula_validar("", $condicao) as $e) {
                $erros[] = t("Vale quando: {1}.", [1 => $e]);
            }
        }
        if (count($erros) === 0) {
            $dados = [$nome, $expr, $condicao !== "" ? $condicao : null, $ante, $texto, $resolve !== "" ? (int)lancamento_tipos()[$resolve]["id"] : null, $ativo, no_gravar($no), $escala,
                $simula["simula_valor"], $simula["simula_horas"], $agenda, $na_hora];
            if ($acao === "novo") {
                sql("INSERT INTO aviso (identificador, nome, expressao, condicao, antecedencia_dias, texto, resolve_tipo_id, ativo, no_id, escala, simula_valor, simula_horas, agenda, na_hora)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", array_merge([$ident], $dados));
                $id = ultimo_id();
                $msg = t("Aviso {1} gravado para {2}.", [1 => $nome, 2 => no_caminho($no)]);
            } else {
                sql("UPDATE aviso SET nome = ?, expressao = ?, condicao = ?, antecedencia_dias = ?, texto = ?, resolve_tipo_id = ?, ativo = ?, no_id = ?, escala = ?,
                    simula_valor = ?, simula_horas = ?, agenda = ?, na_hora = ? WHERE id = ?", array_merge($dados, [$id]));
                $msg = t("Aviso {1} ({2}) salvo.", [1 => $nome, 2 => no_caminho($no)]);
            }
            avisos_todos(true);
        }
    } elseif ($acao === "excluir") {
        sql("DELETE FROM aviso WHERE id = ?", [$id]);
        // a última versão do aviso: o que ia por cada destino e agenda para ele sai (cita o aviso pelo identificador, que é
        // o nome dele nas mensagens e nas fórmulas, como uma fórmula cita outra)
        if ((int)valor("SELECT COUNT(*) FROM aviso WHERE identificador = ?", [$atual["identificador"]]) === 0) {
            sql("DELETE FROM destino_aviso WHERE tipo_aviso = ?", [$atual["identificador"]]);
            sql("DELETE FROM agenda_aviso WHERE tipo_aviso = ?", [$atual["identificador"]]);
            destinos(true);
            agendas(true);
        }
        avisos_todos(true);
        $msg = t("A versão do aviso {1} para {2} foi excluída.", [1 => $atual["nome"], 2 => no_caminho($atual["no_id"])]);
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
        $erros[] = t("Lugar desconhecido: use \"\" (todos), g:<id> de um ponto da árvore ou r:<id> de um relógio.");
        $escopo = "";
    }
    $col = $colunas($escopo);
    // para gravar: o lugar "todos" é a raiz da árvore (na leitura, a raiz aparece vazia)
    $col_g = [$col[0] ?? ($col[1] === null ? no_raiz() : null), $col[1]];

    // ---------- o conjunto de um lugar ----------
    if ($acao === "conjunto_criar" && count($erros) === 0) {
        $ja_tem = (int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE " . $do_lugar, $col);
        $origem = conjunto_herdado($escopo);
        if ($ja_tem > 0) {
            $erros[] = t("{1} já tem critérios próprios.", [1 => escopo_texto($escopo)]);
        } elseif (($d["origem"] ?? "herdado") === "herdado" && $origem !== null) {
            // copia o conjunto herdado inteiro: parâmetros, subparâmetros e faixas
            foreach (criterios_config()[$origem] as $p) {
                sql("INSERT INTO criterio_parametro (escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (?, ?, ?, ?, ?)", [$col_g[0], $col_g[1], $p["nome"], $p["peso"], $p["ordem"]]);
                $np = ultimo_id();
                foreach ($p["subs"] as $sb) {
                    sql("INSERT INTO criterio_sub (parametro_id, nome, variavel, peso, ordem) VALUES (?, ?, ?, ?, ?)", [$np, $sb["nome"], $sb["variavel"], $sb["peso"], $sb["ordem"]]);
                    $ns = ultimo_id();
                    foreach ($sb["faixas"] as $f) {
                        sql("INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) VALUES (?, ?, ?, ?, ?)", [$ns, $f["de"], $f["ate"], $f["categoria"], $f["nota"]]);
                    }
                }
            }
            $ok = t("{1} agora tem critérios próprios, copiados dos de {2}. Ajuste à vontade: os de {3} não mudam.", [1 => escopo_texto($escopo), 2 => escopo_texto($origem), 3 => escopo_texto($origem)]);
        } else {
            sql("INSERT INTO criterio_parametro (escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (?, ?, ?, 100, 1)", [$col_g[0], $col_g[1], t("Novo parâmetro")]);
            $ok = t("{1} agora tem critérios próprios, começando com um parâmetro de 100%: renomeie e inclua os subparâmetros dele.", [1 => escopo_texto($escopo)]);
        }
        $ancora = "conjunto";
    }
    if ($acao === "conjunto_excluir" && count($erros) === 0) {
        $n = (int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE " . $do_lugar, $col);
        if ($n === 0) {
            $erros[] = t("{1} não tem critérios próprios.", [1 => escopo_texto($escopo)]);
        } else {
            foreach (array_column(linhas("SELECT id FROM criterio_parametro WHERE " . $do_lugar, $col), "id") as $pid) {
                sql("DELETE FROM criterio_parametro WHERE id = ?", [(int)$pid]);
            }
            $herda = conjunto_herdado($escopo);
            $ok = t("Os critérios próprios de {1} foram excluídos ({2}{3}{4}", [1 => escopo_texto($escopo), 2 => $n, 3 => $n === 1 ? t(" parâmetro).") : t(" parâmetros)."), 4 => $escopo === "" ? t(" Relógio sem conjunto mais perto fica com nota neutra, 50.") : t(" Agora usa os de {1}.", [1 => $herda !== null ? escopo_texto($herda) : t("nenhum lugar (nota neutra, 50)")])]);
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
            $erros[] = t("Dê um nome ao parâmetro (até 80 caracteres).");
        }
        if ($peso === null || $peso <= 0 || $peso > 100 || (count($outros) > 0 && $peso >= 100)) {
            $erros[] = count($outros) > 0 ? t("O peso do parâmetro novo vai de 0,01% a 99,99%: os outros do conjunto precisam de algum espaço.") : t("O peso vai de 0,01% a 100%.");
        }
        if (count($erros) === 0) {
            $conta = count($outros) > 0 ? $regrava("criterio_parametro", $outros, round(100 - $peso, 2)) : [];
            sql("INSERT INTO criterio_parametro (escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (?, ?, ?, ?, ?)",
                [$col_g[0], $col_g[1], $nome, count($outros) > 0 ? $peso : 100, (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM criterio_parametro WHERE " . $do_lugar, $col)]);
            // o id logo depois do INSERT (a consulta do nome do lugar, abaixo, zeraria o insert_id)
            $ancora = "p" . ultimo_id();
            $ok = t("Parâmetro {1} incluído em {2} com {3}.{4} Inclua os subparâmetros dele: sem nenhum, ele não entra na conta.", [1 => $nome, 2 => escopo_texto($escopo), 3 => pct_br(count($outros) > 0 ? $peso : 100), 4 => count($conta) > 0 ? t(" Os outros abriram espaço na proporção de cada um: {1}.", [1 => implode("; ", $conta)]) : ""]);
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
                $erros[] = t("O parâmetro {1} não é de {2}.", [1 => (int)$id, 2 => escopo_texto($escopo)]);
            }
            if ($p === null || $p < 0 || $p > 100) {
                $erros[] = t("Peso inválido em {1}: use um número de 0 a 100.", [1 => $nome !== "" ? $nome : t("um parâmetro")]);
            }
            if ($nome === "" || strlen($nome) > 80) {
                $erros[] = t("Todo parâmetro precisa de nome (até 80 caracteres).");
            }
            $soma += (float)$p;
            $validos[(int)$id] = [$nome, $p];
        }
        if (count($erros) === 0 && count($validos) !== (int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE " . $do_lugar, $col)) {
            $erros[] = t("Mande o peso de todos os parâmetros de {1} de uma vez: a soma é do conjunto inteiro.", [1 => escopo_texto($escopo)]);
        }
        if (count($erros) === 0 && abs($soma - 100) > 0.005) {
            $erros[] = t("A soma dos parâmetros de {1} dá {2}{3}. Ela precisa dar exatamente 100%.", [1 => escopo_texto($escopo), 2 => pct_br($soma), 3 => $soma > 100 ? ": passa " . pct_br($soma - 100) : ": faltam " . pct_br(100 - $soma)]);
        }
        if (count($erros) === 0) {
            foreach ($validos as $id => $v) {
                sql("UPDATE criterio_parametro SET nome = ?, peso = ? WHERE id = ?", [$v[0], $v[1], $id]);
            }
            $ok = t("Parâmetros de {1} salvos. Soma: 100%.", [1 => escopo_texto($escopo)]);
            $ancora = "conjunto";
        } else {
            $recusado = "param_pesos";
        }
    }
    if ($acao === "param_excluir") {
        $p = linha("SELECT * FROM criterio_parametro WHERE id = ?", [(int)($d["id"] ?? 0)]);
        if (!$p) {
            $erros[] = t("Parâmetro não encontrado.");
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
            $ok = t("Parâmetro {1} excluído de {2}.{3}", [1 => $p["nome"], 2 => escopo_texto($escopo), 3 => count($conta) > 0 ? t(" O peso dele foi dividido na proporção de cada um: {1}.", [1 => implode("; ", $conta)]) : t(" O lugar ficou sem parâmetros e volta a herdar os critérios de cima.")]);
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
            $erros[] = t("Parâmetro não encontrado.");
        }
        if ($nome === "" || strlen($nome) > 80) {
            $erros[] = t("Dê um nome ao subparâmetro (até 80 caracteres).");
        }
        if (!isset($METRICAS[$metrica])) {
            $erros[] = t("Escolha o que o subparâmetro mede.");
        }
        if ($peso === null || $peso <= 0 || $peso > 100 || (count($outros) > 0 && $peso >= 100)) {
            $erros[] = count($outros) > 0 ? t("O peso do subparâmetro novo vai de 0,01% a 99,99%: os outros do parâmetro precisam de algum espaço.") : t("O peso vai de 0,01% a 100%.");
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
            $ok = t("Subparâmetro {1} incluído em {2} com {3}.{4} Ele começou com nota 50 em tudo: ajuste as faixas.", [1 => $nome, 2 => $p["nome"], 3 => pct_br(count($outros) > 0 ? $peso : 100), 4 => count($conta) > 0 ? t(" Os outros abriram espaço na proporção de cada um: {1}.", [1 => implode("; ", $conta)]) : ""]);
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
                $erros[] = t("O subparâmetro {1} não é deste parâmetro.", [1 => (int)$id]);
            }
            if ($pv === null || $pv < 0 || $pv > 100) {
                $erros[] = t("Peso inválido em {1}: use um número de 0 a 100.", [1 => $nome !== "" ? $nome : t("um subparâmetro")]);
            }
            if ($nome === "" || strlen($nome) > 80) {
                $erros[] = t("Todo subparâmetro precisa de nome (até 80 caracteres).");
            }
            $soma += (float)$pv;
            $validos[(int)$id] = [$nome, $pv];
        }
        if (!$p) {
            $erros[] = t("Parâmetro não encontrado.");
        } elseif (count($erros) === 0 && count($validos) !== (int)valor("SELECT COUNT(*) FROM criterio_sub WHERE parametro_id = ?", [$pid])) {
            $erros[] = t("Mande o peso de todos os subparâmetros de {1} de uma vez: a soma é do parâmetro inteiro.", [1 => $p["nome"]]);
        }
        if ($p && count($erros) === 0 && abs($soma - 100) > 0.005) {
            $erros[] = t("A soma dos subparâmetros de {1} dá {2}{3}. Ela precisa dar exatamente 100%.", [1 => $p["nome"], 2 => pct_br($soma), 3 => $soma > 100 ? ": passa " . pct_br($soma - 100) : ": faltam " . pct_br(100 - $soma)]);
        }
        if (count($erros) === 0) {
            foreach ($validos as $id => $v) {
                sql("UPDATE criterio_sub SET nome = ?, peso = ? WHERE id = ?", [$v[0], $v[1], $id]);
            }
            $escopo = escopo_chave($p);
            $ok = t("Subparâmetros de {1} salvos. Soma: 100%.", [1 => $p["nome"]]);
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
            $erros[] = t("Subparâmetro não encontrado.");
        } else {
            $outros = [];
            foreach (linhas("SELECT id, peso FROM criterio_sub WHERE parametro_id = ? AND id <> ?", [$sub["parametro_id"], $sub["id"]]) as $q) {
                $outros[(int)$q["id"]] = (float)$q["peso"];
            }
            $conta = $regrava("criterio_sub", $outros, 100);
            sql("DELETE FROM criterio_sub WHERE id = ?", [$sub["id"]]);
            $escopo = escopo_chave($sub);
            $ok = t("Subparâmetro {1} excluído.{2}", [1 => $sub["nome"], 2 => count($conta) > 0 ? t(" O peso dele foi dividido na proporção de cada um: {1}.", [1 => implode("; ", $conta)]) : t(" O parâmetro ficou sem subparâmetros e não entra na conta até ganhar um.")]);
            $ancora = "p" . $sub["parametro_id"];
        }
    }
    // trocar o que um subparâmetro mede: as faixas antigas não servem para a medida nova e recomeçam com nota 50
    if ($acao === "sub_medida") {
        $sub = linha("SELECT s.*, p.escopo_no_id, p.escopo_relogio_id FROM criterio_sub s JOIN criterio_parametro p ON p.id = s.parametro_id WHERE s.id = ?", [(int)($d["id"] ?? 0)]);
        $metrica = (string)($d["variavel"] ?? ($d["metrica"] ?? ""));
        $erros = [];
        if (!$sub || !isset($METRICAS[$metrica])) {
            $erros[] = t("Escolha o subparâmetro e a medida nova.");
        } elseif ($metrica === $sub["variavel"]) {
            $erros[] = t("{1} já mede isso: nada mudou.", [1 => $sub["nome"]]);
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
            $ok = t("{1} agora mede: {2}. As faixas recomeçaram com nota 50 em tudo: ajuste-as.", [1 => $sub["nome"], 2 => $METRICAS[$metrica]["nome"]]);
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
            $erros[] = t("Escolha o subparâmetro e o parâmetro de destino.");
        } elseif ((int)$destino["id"] === (int)$sub["parametro_id"]) {
            $erros[] = t("{1} já está em {2}: nada mudou.", [1 => $sub["nome"], 2 => $destino["nome"]]);
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
            $ok = t("{1} foi para {2} ({3}) com {4}.{5}", [1 => $sub["nome"], 2 => $destino["nome"], 3 => escopo_texto($escopo), 4 => pct_br($peso), 5 => count($conta) > 0 ? t(" Os dois parâmetros se reequilibraram na proporção: {1}.", [1 => implode("; ", $conta)]) : ""]);
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
            $erros[] = t("Não encontrado.");
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
            $ok = t("Ordem alterada.");
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
                $erros[] = t("Precisa de pelo menos uma faixa.");
            }
            foreach ($lista as $f) {
                $n = numero_br($f["nota"]);
                if ($n === null || $n < 0 || $n > 100) {
                    $erros[] = t("Faixa {1}: a nota vai de 0 a 100.", [1 => $f["linha"]]);
                }
            }
            if ($m["tipo"] === "categoria") {
                $vistas = [];
                foreach ($lista as $f) {
                    if (!in_array($f["categoria"], $m["valores"], true)) {
                        $erros[] = t("Faixa {1}: escolha uma categoria da lista.", [1 => $f["linha"]]);
                    } elseif (isset($vistas[$f["categoria"]])) {
                        $erros[] = t("A categoria {1} aparece mais de uma vez.", [1 => $f["categoria"]]);
                    }
                    $vistas[$f["categoria"]] = true;
                }
            } else {
                // por intervalo: começa em 0, cada faixa começa onde a anterior terminou, só a última pode ficar sem limite,
                // e a última vai até o maior valor possível (ou sem limite): todo valor cai em exatamente uma faixa
                foreach ($lista as $f) {
                    if (numero_br($f["de"]) === null || numero_br($f["de"]) < 0) {
                        $erros[] = t("Faixa {1}: o \"de\" precisa ser um número a partir de 0.", [1 => $f["linha"]]);
                    }
                    if ($f["ate"] !== "" && (numero_br($f["ate"]) === null || (numero_br($f["de"]) !== null && numero_br($f["ate"]) <= numero_br($f["de"])))) {
                        $erros[] = t("Faixa {1}: o \"até\" precisa ser maior que o \"de\" (ou vazio, sem limite).", [1 => $f["linha"]]);
                    }
                }
                if (count($erros) === 0) {
                    usort($lista, function ($a, $b) {
                        return numero_br($a["de"]) <=> numero_br($b["de"]);
                    });
                    if (numero_br($lista[0]["de"]) != 0) {
                        $erros[] = t("A primeira faixa precisa começar em 0 (hoje começa em {1}): senão os valores abaixo ficam sem nota.", [1 => $lista[0]["de"]]);
                    }
                    for ($i = 1; $i < count($lista); $i++) {
                        $anterior = $lista[$i - 1]["ate"];
                        if ($anterior === "") {
                            $erros[] = t("A faixa que começa em {1} está sem limite, mas há outra depois dela: só a última pode ficar sem limite.", [1 => $lista[$i - 1]["de"]]);
                        } elseif (numero_br($anterior) < numero_br($lista[$i]["de"])) {
                            $erros[] = t("Buraco entre {1} e {2}: os valores nesse intervalo ficam sem nota.", [1 => $anterior, 2 => $lista[$i]["de"]]);
                        } elseif (numero_br($anterior) > numero_br($lista[$i]["de"])) {
                            $erros[] = t("Sobreposição: uma faixa vai até {1} e a seguinte começa em {2}.", [1 => $anterior, 2 => $lista[$i]["de"]]);
                        }
                    }
                    $ultima = $lista[count($lista) - 1]["ate"];
                    if ($ultima !== "" && ($m["max"] === null || numero_br($ultima) < $m["max"])) {
                        $erros[] = t("A última faixa vai até {1}{2}", [1 => $ultima, 2 => $m["max"] === null ? t(", e esta medida não tem limite: deixe o \"até\" dela vazio.") : t(", mas a medida vai até {1}.", [1 => $m["max"]])]);
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
                $ok = t("Faixas de {1} salvas.", [1 => $sub["nome"]]);
                $escopo = escopo_chave(linha("SELECT * FROM criterio_parametro WHERE id = ?", [$sub["parametro_id"]]));
                $ancora = "s" . $sid;
            } else {
                $recusado = "faixas:" . $sid;
                $escopo = escopo_chave(linha("SELECT * FROM criterio_parametro WHERE id = ?", [$sub["parametro_id"]]));
            }
        }
    }

    // restaurar: apaga todos os conjuntos (os da árvore e os dos relógios da coleção) e volta aos do modelo.json, no idioma da
    // coleção; cada grupo do modelo vai para o grupo da árvore com o mesmo caminho (sem ele, para a raiz)
    if ($acao === "restaurar") {
        $erros = [];
        foreach (array_column(linhas("SELECT id FROM criterio_parametro"), "id") as $pid) {
            sql("DELETE FROM criterio_parametro WHERE id = ?", [(int)$pid]);
        }
        criterios_restaurar(lingua_valida(cfg("idioma")) ?? "pt-BR");
        $ok = t("Critérios iniciais restaurados.");
        $escopo = "";
        $ancora = "lugares";
    }

    if (count($erros) === 0 && $ok === "") {
        $erros[] = t("Ação desconhecida ou item não encontrado.");
    }
    return ["ok" => count($erros) === 0, "mensagem" => $ok, "erros" => $erros, "escopo" => $escopo, "ancora" => $ancora, "recusado" => $recusado];
}

// ---------------------------------------------------------------------------------------------------------------------
// O rodízio. Ações: modo (o quadro Modo de rodízio da página Hoje: modo (o id do modo), bloco_alvo[<bloco>] (o grupo de
// onde sortear, 0: todos; ou r:<id>, um relógio fixo), bloco_cada_dia[<bloco>] (presente: um relógio por dia; ausente:
// um para o bloco; só nos blocos que vierem e fora da escala), selecao, escala_dias, max_sem_uso: grava o modo e ativa;
// se o dia ainda não começou no pulso, o plano novo vale já de hoje), resortear (refaz o plano de amanhã até o fim da semana, ou até o fim da próxima semana, se ela já foi montada; se o dia ainda não começou no pulso, também hoje),
// resortear_hoje (inclusive hoje: o sorteado passa a ser o do pulso a partir de agora), usando (relogio_id: este passa a
// ser o relógio de hoje, a partir de agora; motivo, opcional: a frase que fica no plano), trocar_dia (data, relogio_id: o relógio daquele dia, à mão; hoje, como o
// usando; 0: o dia volta a ser sorteado; sortear:<forma>: o dia é sorteado agora por essa forma de escolha, entre os do
// bloco do modo, e fica à mão com o motivo do sorteio), proxima_semana (em qualquer dia: monta ou refaz a semana seguinte inteira, com os dias que faltam desta já contados como uso),
// periodo (de, ate, relogio_ids em ordem, dias_cada: os dias do período escolhidos à mão, em rodízio entre eles),
// periodo_sortear (de, ate: tudo o que o período tem, de amanhã em diante, sai e ele é sorteado de novo), periodo_livre (de,
// ate: tudo sai e os dias ficam livres: sem relógio e sem sorteio, para escolher à mão), manual (o rodízio manual: de, ate,
// dias[AAAA-MM-DD] = o id do relógio, auto (Decida por mim: o sistema decide ao gravar, rodizio_manual_decidir),
// sortear:<forma> (o dia sorteado ao gravar por essa forma de escolha: inteligente, ponderado, aleatorio ou fifo) ou livre;
// o dia que não vem fica com a escolha de partida; tudo grava como escolhido à mão), apagar_tudo (toda a programação de
// amanhã em diante, à mão, sorteada e livre, sai; hoje=1: de hoje em diante; o modo ativo monta o plano de novo; hoje, se
// mudar, o novo vai para o pulso a partir de agora). Os dias escolhidos à mão e os
// livres, de amanhã em diante, ficam quando se sorteia de novo ou se monta a próxima semana, e a mensagem diz quantos
// ficaram; com sobrescrever=1 (a caixa "Sobrescrever tudo" do Sortear de novo), sai tudo o que o plano tem do começo do
// sorteio em diante (resortear e resortear_hoje: de amanhã, ou de hoje; proxima_semana: do primeiro dia dela), à mão,
// livre ou sorteado, e o modo monta de novo.
// Na escala inteligente: sortear de novo e apagar tudo refazem a escala inteira (de amanhã, ou inclusive hoje); o que
// tem um dia ou um período (trocar_dia, periodo, periodo_sortear, periodo_livre, manual) muda só ele: fora dele, cada dia
// fica com o relógio que tem, e só o "o que fazer" é refeito; usando segura o escolhido nos dias que faltavam do bloco
// de hoje, e os outros dias ficam; a próxima semana não existe (a escala segue o próprio período).
// ---------------------------------------------------------------------------------------------------------------------
function op_rodizio($acao, $d)
{
    $erros = [];
    $msg = "";
    $agora = time();
    $hoje = hoje_da_colecao();
    $ini_uso = instante($hoje->format("Y-m-d"), cfg("uso_inicio"));
    $fim_uso = instante($hoje->format("Y-m-d"), cfg("uso_fim"));
    $comecou = dia_comecou($agora);
    $sobrescrever = (string)($d["sobrescrever"] ?? "") === "1";
    // trocar o relógio de hoje no próprio dia (a Configuração, trocar_hoje): desligado, o padrão, o de hoje fica e tudo o que
    // o trocaria é recusado (ou, no Sortear de novo, vale a partir de amanhã)
    $trava_hoje = !trocar_hoje_permitido();
    $recusa_hoje = t("O relógio de hoje não se troca no próprio dia (Configuração: Trocar o relógio de hoje no próprio dia): a troca vale a partir de amanhã. As marcações do pulso continuam livres.");
    // troca o relógio de hoje a partir de agora: a sessão de rodízio do anterior termina agora (ou sai, se nem começou)
    // e a do novo começa agora (se ainda está no horário de uso)
    $troca_hoje = function ($novo_id, $motivo) use ($hoje, $agora, $ini_uso, $fim_uso) {
        $tipo = lancamento_tipos()["pulso"] ?? null;
        $dia = $hoje->format("Y-m-d");
        sql("DELETE FROM plano_livre WHERE data = ?", [$dia]);
        plano_gravar($dia, $novo_id, null, "manual", $motivo, null, true);
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
            // (uma sessão dele de hoje já fechada, como a do "Sem relógio hoje", não conta: ele volta ao pulso agora)
            if (pulso_poe_sozinho() && $agora < $fim_uso && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ? AND relogio_id = ?
                AND (fim IS NULL OR fim > ?)", [(int)$tipo["id"], $dia, $novo_id, date("Y-m-d H:i:s", $agora)]) === null) {
                sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, 'rodizio', NOW())",
                    [$novo_id, (int)$tipo["id"], date("Y-m-d H:i:s", max($agora, $ini_uso)), pulso_tira_sozinho() ? date("Y-m-d H:i:s", $fim_uso) : null]);
            }
        }
    };
    $escala = valor("SELECT escala_dias FROM modo WHERE id = ?", [modo_ativo()]) !== null;
    // o plano antes da gravação: a resposta diz o que mudou (mudancas), dia a dia; marcar o modo não mexe no plano
    $retrato = $acao === "modo" ? null : plano_retrato($hoje);
    if ($acao === "modo") {
        $id_modo = (int)($d["modo"] ?? 0);
        $m = linha("SELECT * FROM modo WHERE id = ?", [$id_modo]);
        if (!$m) {
            $erros[] = t("Modo não encontrado.");
        } else {
            // o quadro grava só o critério de escolha (a forma do sorteio; não na escala nem no aleatório) e a garantia, e
            // marca o modo; os blocos, as semanas e o roteiro se editam nos Modos de rodízio
            $res = ["ok" => true, "erros" => [], "mensagem" => ""];
            if (isset($d["selecao"]) && $m["escala_dias"] === null && !in_array($m["tipo"] ?? "", ["aleatorio", "personalizado"], true)) {
                $res = op_modos("salvar", ["id" => $id_modo, "selecao" => $d["selecao"]]);
            }
            if ($res["ok"] && isset($d["max_sem_uso"])) {
                $res = op_config("salvar", ["max_sem_uso" => $d["max_sem_uso"]]);
            }
            if ($res["ok"]) {
                // só grava e marca o modo: quem quiser o plano refeito usa Sortear de novo
                $res = op_modos("ativar", ["id" => $id_modo]);
            }
            $erros = $res["erros"];
            $msg = $res["ok"] ? t("Modo aplicado: {1}. {2}", [1 => $m["nome"], 2 => $res["mensagem"]]) : "";
        }
    } elseif ($acao === "resortear_hoje" && $trava_hoje) {
        $erros[] = $recusa_hoje;
    } elseif ($acao === "resortear" || $acao === "resortear_hoje") {
        $antes = plano_do_dia($hoje->format("Y-m-d"));
        // com o dia ainda por começar, o Sortear de novo pega hoje também (se o relógio de hoje se troca no próprio dia)
        $desde = ($acao === "resortear_hoje" || (!$comecou && !$trava_hoje)) ? $hoje : $hoje->modify("+1 day");
        // a próxima semana, se foi montada (proxima_semana_fim): é refeita até lá, e não só até o fim da semana. Só ela: os dias
        // sorteados depois do horizonte por outro modo (a escala de 30 dias antes de voltar ao semanal) saem e não voltam
        $fim_s = !$escala && cfg("proxima_semana_fim") >= $desde->format("Y-m-d") ? cfg("proxima_semana_fim") : null;
        $modo_r = linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]);
        $ate_s = $modo_r ? horizonte_do_modo($modo_r, $hoje) : $hoje;
        if ($fim_s !== null && (string)$fim_s > $ate_s->format("Y-m-d")) {
            $ate_s = new DateTimeImmutable(substr((string)$fim_s, 0, 10));
        }
        // os dias escolhidos à mão e os livres de amanhã em diante ficam (um período que você montou não some no sorteio),
        // menos com sobrescrever=1: sai tudo do começo do sorteio em diante; hoje, sorteia sempre
        $guardados = dias_guardados($desde);
        if ($sobrescrever) {
            sql("DELETE FROM plano WHERE data >= ?", [$desde->format("Y-m-d")]);
            sql("DELETE FROM plano_livre WHERE data >= ?", [$desde->format("Y-m-d")]);
        }
        sql("DELETE FROM plano WHERE data >= ? AND (origem <> 'manual' OR data = ?)", [$desde->format("Y-m-d"), $hoje->format("Y-m-d")]);
        sql("DELETE FROM plano_livre WHERE data = ? AND data >= ?", [$hoje->format("Y-m-d"), $desde->format("Y-m-d")]);
        if ($escala) {
            gerar_escala($desde, null, true);
        }
        garantir_plano($hoje, $escala ? null : $ate_s, $acao !== "resortear_hoje");
        $depois = plano_do_dia($hoje->format("Y-m-d"));
        if ($depois && (!$antes || (int)$antes["relogio_id"] !== (int)$depois["relogio_id"]) && ($acao === "resortear_hoje" || !$comecou) && (!$trava_hoje || !$antes)) {
            // o de hoje trocado pelo sorteio: o motivo é o do sorteio
            $troca_hoje((int)$depois["relogio_id"], $depois["motivo"] ?? null);
        }
        $msg = t("Sorteado de novo{1}.{2}", [1 => $desde > $hoje ? ($antes ? t(" a partir de amanhã; hoje continua o {1}", [1 => $antes["nome"]]) : t(" a partir de amanhã")) : "", 2 => $depois ? " " . t("Hoje: {1}.", [1 => $depois["nome"]]) : ""])
            . dias_guardados_texto($guardados, $sobrescrever);
    } elseif (($acao === "sem_relogio" || $acao === "usando") && $trava_hoje) {
        $erros[] = $recusa_hoje;
    } elseif ($acao === "sem_relogio") {
        // hoje sem relógio: o dia fica livre (sem relógio e sem sorteio, como os dias livres do plano) e a sessão do rodízio de
        // hoje termina agora (o tempo que ele já ficou no pulso conta; a que ainda nem começou sai). Os outros dias não mudam.
        // Desfaz: o "escolher…" de hoje (trocar_dia ou usando) ou o "Sortear de novo" inclusive hoje
        $dia = $hoje->format("Y-m-d");
        $antes = plano_do_dia($dia);
        $tipo = lancamento_tipos()["pulso"] ?? null;
        $tirou = "";
        if ($tipo) {
            foreach (linhas("SELECT l.id, l.inicio, l.fim, r.nome FROM lancamento l JOIN relogio r ON r.id = l.relogio_id WHERE l.tipo_id = ? AND l.origem = 'rodizio' AND DATE(l.inicio) = ?",
                [(int)$tipo["id"], $dia]) as $l) {
                if (strtotime($l["inicio"]) >= $agora) {
                    sql("DELETE FROM lancamento WHERE id = ?", [(int)$l["id"]]);
                } elseif ($l["fim"] === null || strtotime($l["fim"]) > $agora) {
                    sql("UPDATE lancamento SET fim = ? WHERE id = ?", [date("Y-m-d H:i:s", $agora), (int)$l["id"]]);
                    $tirou = $l["nome"];
                }
            }
        }
        sql("DELETE FROM plano WHERE data = ?", [$dia]);
        plano_livre_marcar($dia);
        $msg = t("Hoje sem relógio: o dia ficou livre{1}.{2} Mudou de ideia? Escolha um relógio para hoje (o \"escolher…\") ou sorteie de novo inclusive hoje.", [1 => $antes ? " (era o " . $antes["nome"] . ")" : "", 2 => $tirou !== "" ? t(" O {1} saiu do pulso agora; o tempo que ele já ficou conta.", [1 => $tirou]) : t(" Nenhum relógio vai ao pulso pelo rodízio hoje.")]);
    } elseif ($acao === "usando") {
        $r = linha("SELECT * FROM relogio WHERE id = ?", [(int)($d["relogio_id"] ?? 0)]);
        if (!$r) {
            $erros[] = t("Relógio não encontrado.");
        } else {
            $antes = plano_do_dia($hoje->format("Y-m-d"));
            $troca_hoje((int)$r["id"], trim((string)($d["motivo"] ?? "")) !== "" ? corta_texto(trim((string)$d["motivo"]), 300) : t("Escolhido à mão: você marcou que está usando este hoje."));
            $msg = t("Hoje: {1}, a partir de agora.", [1 => $r["nome"]]);
            if ($escala) {
                // os dias seguidos que o relógio de antes ainda teria ficam com o escolhido
                $dia = $hoje->modify("+1 day");
                $p = plano_do_dia($dia->format("Y-m-d"));
                for ($n = 0; $antes && $p && (int)$p["relogio_id"] === (int)$antes["relogio_id"] && $n < 366; $n++) {
                    sql("UPDATE plano SET relogio_id = ?, origem = 'manual', acao = NULL, motivo = ? WHERE data = ?",
                        [(int)$r["id"], t("Escolhido à mão: segue o relógio que você pôs no pulso."), $dia->format("Y-m-d")]);
                    $dia = $dia->modify("+1 day");
                    $p = plano_do_dia($dia->format("Y-m-d"));
                }
                // os outros dias ficam com o relógio que têm; só o "o que fazer" deles é refeito
                gerar_escala($dia, $hoje);
                $msg .= t(" Fica até {1}; os outros dias continuam como estavam.", [1 => $dia->modify("-1 day")->format("d/m")]);
            }
        }
    } elseif ($acao === "trocar_dia") {
        // o relógio de um dia do plano, à mão: hoje, como o "usando"; um dia que vem, só aquele dia (na escala, refeita a partir
        // dele, com o dia fixo); relogio_id 0 desfaz a escolha à mão e o dia volta a ser sorteado
        $data = (string)($d["data"] ?? "");
        $dt = DateTimeImmutable::createFromFormat("!Y-m-d", $data);
        // relogio_id: o id; 0 (volta a sortear); ou sortear:<forma> (o dia é sorteado agora por essa forma de escolha)
        $forma = preg_match("/^sortear:([a-z]+)$/", trim((string)($d["relogio_id"] ?? "")), $m_f) === 1 ? $m_f[1] : null;
        $novo = $forma === null ? (int)($d["relogio_id"] ?? 0) : 0;
        $r = $novo > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$novo]) : null;
        if (!$dt || $dt->format("Y-m-d") !== $data) {
            $erros[] = t("Data: AAAA-MM-DD.");
        } elseif ($dt < $hoje) {
            $erros[] = t("O dia {1} já passou: o que foi usado nele se corrige nas marcações (o quadro \"Corrigir marcações\" no painel do relógio).", [1 => $dt->format("d/m")]);
        } elseif ($dt == $hoje && $trava_hoje) {
            $erros[] = $recusa_hoje;
        } elseif ($forma !== null && !isset(formas_de_escolha()[$forma])) {
            $erros[] = t("Forma de escolha: {1}.", [1 => implode(", ", array_keys(formas_de_escolha()))]);
        } elseif ($forma !== null) {
            // o dia sorteado agora por essa forma: fica escolhido à mão, com o motivo do sorteio; hoje, como o usando
            [$novo, $motivo_s] = sortear_dia($dt, $forma);
            if ($novo === null) {
                $erros[] = t("Nenhum relógio disponível para sortear em {1}.", [1 => $dt->format("d/m")]);
            } else {
                $motivo_s = t("Sorteado à mão ({1}): {2}", [1 => formas_de_escolha()[$forma], 2 => $motivo_s]);
                if ($dt == $hoje) {
                    return op_rodizio("usando", ["relogio_id" => $novo, "motivo" => $motivo_s]);
                }
                sql("DELETE FROM plano_livre WHERE data = ?", [$data]);
                plano_gravar($data, $novo, null, "manual", corta_texto($motivo_s, 300), null, true);
                if ($escala) {
                    // só esse dia muda; os outros ficam com o relógio que têm (o "o que fazer" deles é refeito)
                    gerar_escala($dt, $dt);
                }
                $msg = t("{1}: {2}, sorteado por {3}.", [1 => $dt->format("d/m"), 2 => valor("SELECT nome FROM relogio WHERE id = ?", [$novo]), 3 => formas_de_escolha()[$forma]]);
            }
        } elseif ($novo > 0 && !$r) {
            $erros[] = t("Relógio não encontrado.");
        } elseif ($r && (int)$r["disponivel"] !== 1) {
            $erros[] = t("{1} está indisponível: marque como disponível no cadastro dele antes.", [1 => $r["nome"]]);
        } elseif ($novo === 0 && $dt == $hoje) {
            $erros[] = t("Para sortear hoje de novo, use \"Sortear de novo\" no quadro do modo de rodízio.");
        } elseif ($dt == $hoje) {
            return op_rodizio("usando", ["relogio_id" => $novo]);
        } elseif ($novo === 0) {
            sql("DELETE FROM plano_livre WHERE data = ?", [$data]);
            if ($escala) {
                // só esse dia é sorteado de novo
                sql("UPDATE plano SET origem = 'sorteio' WHERE data = ?", [$data]);
                gerar_escala($dt, $dt);
            } else {
                // só esse dia, com os dias de hoje até ele contados como uso (a simulação começa hoje)
                sql("DELETE FROM plano WHERE data = ?", [$data]);
                $modo_d = linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]);
                if ($modo_d) {
                    plano_montar($modo_d, $hoje, $dt, $dt, false);
                }
            }
            $p = plano_do_dia($data);
            $msg = t("{1}: volta a ser sorteado{2}.", [1 => $dt->format("d/m"), 2 => $p ? " (" . $p["nome"] . ")" : ""]);
        } else {
            sql("DELETE FROM plano_livre WHERE data = ?", [$data]);
            plano_gravar($data, $novo, null, "manual", t("Escolhido à mão."), null, true);
            if ($escala) {
                // só esse dia muda; os outros ficam com o relógio que têm (o "o que fazer" deles é refeito)
                gerar_escala($dt, $dt);
            }
            $msg = t("{1}: {2}, escolhido à mão.", [1 => $dt->format("d/m"), 2 => $r["nome"]]);
        }
    } elseif ($acao === "periodo" || $acao === "periodo_sortear" || $acao === "periodo_livre") {
        // um período escolhido à mão: de, ate (AAAA-MM-DD, de hoje em diante, até 2 anos), relogio_ids (um ou vários, na ordem:
        // um por dia, e depois do último volta ao primeiro; dias_cada: quantos dias seguidos cada um fica). periodo_sortear:
        // tudo o que o período tem (de amanhã em diante: à mão, sorteado ou livre) sai da tabela e o modo de rodízio monta os
        // dias de novo. periodo_livre: tudo sai e os dias ficam livres (sem relógio e sem sorteio, para escolher à mão). Nos
        // dois, um dos lados pode faltar: sem o de, de amanhã; sem o ate, até o último dia do plano
        $apagar_p = $acao !== "periodo";
        $de_txt = trim((string)($d["de"] ?? ""));
        $ate_txt = trim((string)($d["ate"] ?? ""));
        if ($apagar_p && $de_txt === "" && $ate_txt !== "") {
            $de_txt = $hoje->modify("+1 day")->format("Y-m-d");
        }
        if ($apagar_p && $ate_txt === "" && $de_txt !== "") {
            $ultimo_p = max((string)valor("SELECT MAX(data) FROM plano"), (string)valor("SELECT MAX(data) FROM plano_livre"));
            $ate_txt = max(substr($ultimo_p, 0, 10), $de_txt);
            // na escala, sem o fim: até onde os dias do modo deixam (a escala de 7 que passou a 30 vai até o 30º dia)
            $modo_p = $escala ? linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]) : null;
            if ($modo_p) {
                $ate_txt = max($ate_txt, escala_fim_maximo($modo_p, $hoje)->format("Y-m-d"));
            }
        }
        $de_p = DateTimeImmutable::createFromFormat("!Y-m-d", $de_txt);
        $ate_p = DateTimeImmutable::createFromFormat("!Y-m-d", $ate_txt);
        $ids_p = $d["relogio_ids"] ?? [];
        $ids_p = array_values(array_filter(array_map("intval", is_array($ids_p) ? $ids_p : explode(",", (string)$ids_p))));
        $cada = trim((string)($d["dias_cada"] ?? "1"));
        $rels_p = [];
        foreach ($ids_p as $x) {
            $rels_p[] = linha("SELECT * FROM relogio WHERE id = ?", [$x]);
        }
        if ($apagar_p && $de_txt === "" && $ate_txt === "") {
            $erros[] = t("Dê o começo, o fim ou os dois (para apagar tudo, use o Apagar tudo).");
        } elseif (!$de_p || $de_p->format("Y-m-d") !== $de_txt || !$ate_p || $ate_p->format("Y-m-d") !== $ate_txt) {
            $erros[] = t("De e até: datas AAAA-MM-DD.");
        } elseif ($de_p < $hoje) {
            $erros[] = t("O período começa num dia que já passou: o que foi usado se corrige nas marcações.");
        } elseif ($acao === "periodo" && $de_p == $hoje && $trava_hoje) {
            $erros[] = t("O período começa hoje, mas o relógio de hoje não se troca no próprio dia (Configuração: Trocar o relógio de hoje no próprio dia): comece o período amanhã.");
        } elseif ($ate_p < $de_p) {
            $erros[] = t("O fim do período vem antes do começo.");
        } elseif (!$apagar_p && $de_p->diff($ate_p)->days > 730) {
            $erros[] = t("Um período de até 2 anos.");
        } elseif ($acao === "periodo_sortear" || $acao === "periodo_livre") {
            // de amanhã em diante (hoje já tem o relógio do pulso: troque pelo "Sortear de novo" ou pelo "trocar por…")
            $desde_p = max($de_p, $hoje->modify("+1 day"));
            $faixa = [$desde_p->format("Y-m-d"), $ate_p->format("Y-m-d")];
            if ($desde_p > $ate_p) {
                $erros[] = t("O período tem só hoje: troque o relógio de hoje pelo \"Sortear de novo\" ou pelo \"trocar por…\".");
            } else {
                $n_p = (int)valor("SELECT COUNT(*) FROM plano WHERE data BETWEEN ? AND ?", $faixa) + (int)valor("SELECT COUNT(*) FROM plano_livre WHERE data BETWEEN ? AND ?", $faixa);
                sql("DELETE FROM plano WHERE data BETWEEN ? AND ?", $faixa);
                sql("DELETE FROM plano_livre WHERE data BETWEEN ? AND ?", $faixa);
                $livres = 0;
                if ($acao === "periodo_livre") {
                    for ($dia = $desde_p; $dia <= $ate_p; $dia = $dia->modify("+1 day"), $livres++) {
                        plano_livre_marcar($dia->format("Y-m-d"));
                    }
                }
                $alem_p = "";
                if ($escala) {
                    // só o período é sorteado de novo (os livres ficam sem relógio); fora dele, cada dia fica com o relógio que tem.
                    // O que passa do fim da escala (escala_dias do modo) saiu e não volta: a escala seguinte monta esses dias
                    gerar_escala($desde_p, $ate_p);
                    $fim_e = new DateTimeImmutable(cfg("escala_fim"));
                    if ($acao === "periodo_sortear" && $ate_p > $fim_e) {
                        $ate_p = max($fim_e, $desde_p);
                        $alem_p = t(" Depois de {1}, os dias ficam sem plano até a próxima escala: a escala do modo tem {2} dias.",
                            [1 => $fim_e->format("d/m"), 2 => (int)valor("SELECT escala_dias FROM modo WHERE id = ?", [modo_ativo()])]);
                    }
                } else {
                    garantir_plano($hoje);
                }
                // a data com o ano quando o período passa deste ano
                $fmt_p = $ate_p->format("Y") !== $hoje->format("Y") ? "d/m/Y" : "d/m";
                $faixa_txt = $desde_p->format($fmt_p) . " a " . $ate_p->format($fmt_p);
                $msg = $acao === "periodo_livre"
                    ? t("De {1}: {2}{3}, sem relógio e sem sorteio{4}. Escolha o relógio de cada um em \"escolher…\", ou no Rodízio manual.", [1 => $faixa_txt, 2 => $livres, 3 => $livres === 1 ? t(" dia livre") : t(" dias livres"), 4 => $n_p > 0 ? t(" (o que estava gravado saiu)") : ""])
                    : t("De {1}: {2}o período foi sorteado de novo{3}.", [1 => $faixa_txt, 2 => $n_p > 0 ? ($n_p === 1 ? t("1 dia saiu do plano e ") : t("{1} dias saíram do plano e ", [1 => $n_p])) : "", 3 => $escala ? t("; fora dele, nada mudou")
                            : ($ate_p > horizonte_do_modo(linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]) ?: [], $hoje) ? t("; os dias depois do horizonte do modo são sorteados quando a semana deles chegar") : "")]) . $alem_p;
            }
        } elseif (count($ids_p) === 0) {
            $erros[] = t("Escolha pelo menos um relógio para o período.");
        } elseif (in_array(null, $rels_p, true) || in_array(false, $rels_p, true)) {
            $erros[] = t("Relógio não encontrado.");
        } elseif (!ctype_digit($cada) || (int)$cada < 1 || (int)$cada > 365) {
            $erros[] = t("Dias seguidos de cada: de 1 a 365.");
        } else {
            $fora = array_values(array_filter($rels_p, function ($r) { return (int)$r["disponivel"] !== 1; }));
            if (count($fora) > 0) {
                $erros[] = t("{1}{2}: marque como disponível no cadastro antes.", [1 => implode(", ", array_column($fora, "nome")), 2 => count($fora) === 1 ? t(" está indisponível") : t(" estão indisponíveis")]);
            } else {
                $motivo_p = t("Escolhido à mão para o período de {1} a {2}.", [1 => $de_p->format("d/m"), 2 => $ate_p->format("d/m")]);
                sql("DELETE FROM plano_livre WHERE data BETWEEN ? AND ?", [$de_p->format("Y-m-d"), $ate_p->format("Y-m-d")]);
                $i = 0;
                for ($dia = $de_p; $dia <= $ate_p; $dia = $dia->modify("+1 day"), $i++) {
                    $r = $rels_p[intdiv($i, (int)$cada) % count($rels_p)];
                    if ($dia == $hoje) {
                        // hoje: ele passa a ser o do pulso a partir de agora (como o usando)
                        $troca_hoje((int)$r["id"], $motivo_p);
                    } else {
                        plano_gravar($dia->format("Y-m-d"), (int)$r["id"], null, "manual", $motivo_p, null, true);
                    }
                }
                if ($escala) {
                    // os dias do período ficam à mão; fora dele, cada dia fica com o relógio que tem
                    gerar_escala($de_p, $ate_p);
                }
                $msg = t("De {1} a {2}: {3}{4} à mão ({5}{6}).", [1 => $de_p->format("d/m"), 2 => $ate_p->format("d/m"), 3 => $i, 4 => $i === 1 ? t(" dia escolhido") : t(" dias escolhidos"), 5 => implode(", ", array_column($rels_p, "nome")), 6 => count($rels_p) > 1 ? t(", nessa ordem, {1}", [1 => (int)$cada === 1 ? t("um por dia") : t("{1} dias seguidos cada", [1 => $cada])]) : ""]);
            }
        }
    } elseif ($acao === "apagar_tudo") {
        // apaga toda a programação de amanhã em diante (com hoje=1, de hoje em diante): os dias escolhidos à mão, os sorteados
        // e os livres; depois o modo ativo monta o plano de novo (o sorteio até o fim da semana, ou a escala inteira). Hoje, se mudar, o
        // novo passa a ser o do pulso a partir de agora
        $com_hoje = in_array((string)($d["hoje"] ?? "0"), ["1", "on", "sim", "true"], true);
        if ($com_hoje && $trava_hoje) {
            return resultado([$recusa_hoje], "");
        }
        $desde_t = $com_hoje ? $hoje : $hoje->modify("+1 day");
        $antes = plano_do_dia($hoje->format("Y-m-d"));
        $n_t = (int)valor("SELECT COUNT(*) FROM plano WHERE data >= ?", [$desde_t->format("Y-m-d")]) + (int)valor("SELECT COUNT(*) FROM plano_livre WHERE data >= ?", [$desde_t->format("Y-m-d")]);
        sql("DELETE FROM plano WHERE data >= ?", [$desde_t->format("Y-m-d")]);
        sql("DELETE FROM plano_livre WHERE data >= ?", [$desde_t->format("Y-m-d")]);
        // a próxima semana montada também sai: o Sortear de novo de depois vai só até o fim das semanas do modo
        cfg_set("proxima_semana_fim", "");
        if ($escala) {
            gerar_escala($desde_t, null, true);
        }
        garantir_plano($hoje, null, !$com_hoje);
        $depois = plano_do_dia($hoje->format("Y-m-d"));
        if ($com_hoje && $depois && (!$antes || (int)$antes["relogio_id"] !== (int)$depois["relogio_id"])) {
            $troca_hoje((int)$depois["relogio_id"], $depois["motivo"] ?? null);
        }
        $modo_t = valor("SELECT nome FROM modo WHERE id = ?", [modo_ativo()]);
        $msg = t("Programação apagada {1}: {2}{3} (escolhidos à mão, sorteados e livres). O plano foi montado de novo pelo modo {4}{5}.{6}", [1 => $com_hoje ? t("de hoje em diante") : t("de amanhã em diante"), 2 => $n_t, 3 => $n_t === 1 ? " dia" : " dias", 4 => $modo_t ?? "ativo", 5 => $escala ? t(" (a escala inteira)") : t(" (até o fim da semana; as semanas seguintes, quando chegarem)"), 6 => $com_hoje && $depois ? " Hoje: " . $depois["nome"] . "." : ""]);
    } elseif ($acao === "manual") {
        // o rodízio manual: de, ate, dias[AAAA-MM-DD] = o id do relógio, "auto" (Decida por mim) ou "livre" (dia livre).
        // O dia que não vem fica com a escolha de partida (a escolha à mão que ele já tem; senão, Decida por mim)
        [$de_m, $ate_m, $erro_m] = rodizio_manual_periodo($d["de"] ?? "", $d["ate"] ?? "");
        $vindos = is_array($d["dias"] ?? null) ? $d["dias"] : [];
        $disp = [];
        foreach (linhas("SELECT id, nome FROM relogio WHERE disponivel = 1") as $r) {
            $disp[(int)$r["id"]] = $r["nome"];
        }
        $escolhas = [];
        if ($erro_m !== null) {
            $erros[] = $erro_m;
        } else {
            foreach (array_keys($vindos) as $k) {
                if (!is_string($k) || $k < $de_m->format("Y-m-d") || $k > $ate_m->format("Y-m-d") || DateTimeImmutable::createFromFormat("!Y-m-d", $k) === false) {
                    $erros[] = t("O dia {1} está fora do período.", [1 => $k]);
                }
            }
            for ($dia = $de_m; $dia <= $ate_m; $dia = $dia->modify("+1 day")) {
                $data = $dia->format("Y-m-d");
                $v = array_key_exists($data, $vindos) ? trim((string)$vindos[$data]) : null;
                if ($v === null) {
                    $escolhas[$data] = rodizio_manual_escolha(plano_do_dia($data), dia_livre($data));
                } elseif ($v === "auto" || $v === "livre" || (strpos($v, "sortear:") === 0 && isset(formas_de_escolha()[substr($v, 8)]))) {
                    $escolhas[$data] = $v;
                } elseif (ctype_digit($v) && isset($disp[(int)$v])) {
                    $escolhas[$data] = (int)$v;
                } else {
                    $erros[] = t("{1}: escolha um relógio disponível, \"auto\" (Decida por mim), \"livre\" ou \"sortear:<forma>\" ({2}).", [1 => $dia->format("d/m"), 2 => implode(", ", array_keys(formas_de_escolha()))]);
                }
                if (($escolhas[$data] ?? null) === "livre" && $dia == $hoje) {
                    $erros[] = t("Hoje não pode ficar livre: hoje já tem o relógio do pulso (troque por outro, ou Decida por mim).");
                } elseif ($dia == $hoje && $trava_hoje && $v !== null && $v !== "auto" && $v !== "livre"
                    && (!ctype_digit($v) || (int)$v !== (int)(plano_do_dia($data)["relogio_id"] ?? 0))) {
                    // hoje, sem trocar no próprio dia: só o que ele já tem (ou o Decida por mim, que mantém o de hoje)
                    $erros[] = t("{1}: {2}", [1 => $dia->format("d/m"), 2 => $recusa_hoje]);
                }
            }
        }
        if (count($erros) === 0) {
            $decididos = rodizio_manual_decidir($de_m, $ate_m, $escolhas);
            $motivo_m = t("Escolhido à mão no rodízio manual de {1} a {2}.", [1 => $de_m->format("d/m"), 2 => $ate_m->format("d/m")]);
            $n = ["mao" => 0, "decididos" => 0, "livres" => 0, "mantidos" => 0];
            foreach ($escolhas as $data => $e) {
                $eh_hoje = $data === $hoje->format("Y-m-d");
                // o dia que não veio e já estava escolhido à mão (ou livre) fica como estava
                $mantido = !array_key_exists($data, $vindos) && $e !== "auto";
                if ($e === "livre") {
                    sql("DELETE FROM plano WHERE data = ?", [$data]);
                    plano_livre_marcar($data);
                    $n[$mantido ? "mantidos" : "livres"]++;
                    continue;
                }
                sql("DELETE FROM plano_livre WHERE data = ?", [$data]);
                [$rid, $motivo] = is_int($e) ? [$e, $motivo_m] : ($decididos[$data] ?? [null, null]);
                if ($rid === null) {
                    // hoje, no Decida por mim: continua o que está no plano
                    continue;
                }
                $n[$mantido ? "mantidos" : (is_int($e) ? "mao" : "decididos")]++;
                $antes = plano_do_dia($data);
                if ($eh_hoje) {
                    if (!$antes || (int)$antes["relogio_id"] !== $rid) {
                        $troca_hoje($rid, $motivo);
                    }
                } elseif (!$antes || (int)$antes["relogio_id"] !== $rid || $antes["origem"] !== "manual" || !is_int($e)) {
                    plano_gravar($data, $rid, null, "manual", $motivo, null, true);
                }
            }
            if ($escala) {
                // os dias do período ficam como você gravou; fora dele, cada dia fica com o relógio que tem
                gerar_escala($de_m, $ate_m);
            }
            $partes = [];
            foreach ([["mao", t("escolhido por você"), t("escolhidos por você")], ["decididos", t("decidido pelo sistema"), t("decididos pelo sistema")], ["livres", t("livre"), t("livres")],
                ["mantidos", t("como já estava"), t("como já estavam")]] as [$k, $um, $varios]) {
                if ($n[$k] > 0) {
                    $partes[] = $n[$k] . " " . ($n[$k] === 1 ? t("dia {1}", [1 => $um]) : t("dias {1}", [1 => $varios]));
                }
            }
            $msg = t("Rodízio manual de {1} a {2} gravado{3}. Fora do período, vale o modo de rodízio de sempre.", [1 => $de_m->format("d/m"), 2 => $ate_m->format("d/m"), 3 => count($partes) > 0 ? ": " . implode(", ", $partes) : ""]);
        }
    } elseif ($acao === "proxima_semana") {
        if ($escala) {
            $erros[] = t("Na escala inteligente não há próxima semana para montar: a escala segue o próprio período (até {1}). Sortear de novo refaz a escala.", [1 => data_txt(strtotime(cfg("escala_fim")))]);
        } else {
            // a semana que vem (no último dia da semana, a de amanhã; a semana começa no dia da Configuração, semana_inicio), em
            // qualquer dia: o plano roda desde hoje, para que os dias que ainda faltam desta semana cheguem a ela como usados (a
            // simulação no pulso), e só os dias dela são sorteados de novo
            $ini = semana_fim($hoje)->modify("+1 day");
            // com sobrescrever=1, sai tudo do primeiro dia dela em diante (à mão, livre ou sorteado); sem, só os sorteados dela
            $guardados = dias_guardados($ini, $ini->modify("+6 days"));
            if ($sobrescrever) {
                $guardados = dias_guardados($ini);
                sql("DELETE FROM plano WHERE data >= ?", [$ini->format("Y-m-d")]);
                sql("DELETE FROM plano_livre WHERE data >= ?", [$ini->format("Y-m-d")]);
            }
            sql("DELETE FROM plano WHERE data BETWEEN ? AND ? AND origem <> 'manual'", [$ini->format("Y-m-d"), $ini->modify("+6 days")->format("Y-m-d")]);
            garantir_plano($hoje, $ini->modify("+6 days"));
            // até onde a próxima semana foi montada: o Sortear de novo refaz até lá (e não até onde o plano de outro modo ia)
            cfg_set("proxima_semana_fim", $ini->modify("+6 days")->format("Y-m-d"));
            $msg = t("Semana de {1} a {2} montada.", [1 => $ini->format("d/m"), 2 => $ini->modify("+6 days")->format("d/m")]) . dias_guardados_texto($guardados, $sobrescrever);
        }
    }
    return resultado($erros, $msg, $retrato !== null && count($erros) === 0 && $msg !== "" ? ["mudancas" => plano_mudancas($retrato, plano_retrato($hoje))] : []);
}

// ---------------------------------------------------------------------------------------------------------------------
// Modos de rodízio. Ações: salvar (id: 0 cria; nome; tipo: semana, semana_fds, dia_semana, aleatorio, escala ou
// personalizado (sem o tipo, inferido dos blocos: modo_tipo_inferir); selecao: inteligente, ponderado, aleatorio ou fifo;
// escala_dias: de 7 a 730, só na escala; semanas: 1 a 8, quantas semanas à frente o plano vem montado (não na escala);
// blocos: lista de {nome, dias: [1..7], no_id (0: todos), um_por: bloco ou dia, relogio_id (fixo; 0: sorteia)}: substitui
// os blocos (a forma deles é conferida pelo tipo: um por semana, um bloco com os sete dias; semana e fim de semana, segunda
// a sexta e sábado e domingo; aleatório, um bloco com os sete dias); roteiro: as linhas "nome = expressão" do
// personalizado (conferidas: roteiro_ler); ciclo: 1 ou 0 (padrão): com o ciclo, um relógio só volta depois que todos os
// candidatos passaram na semana), ativar (id: só marca o modo), excluir (id: não o ativo). O roteiro dos modos que não
// são personalizados é gerado dos blocos (modo_roteiro_texto); na escala, a forma de escolha não conta (a maior nota) e o
// relógio fica os dias da fórmula dias_seguidos.
// ---------------------------------------------------------------------------------------------------------------------
function op_modos($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $atual = $id > 0 ? linha("SELECT * FROM modo WHERE id = ?", [$id]) : null;
    if ($id > 0 && !$atual) {
        $erros[] = t("Modo não encontrado.");
    } elseif ($acao === "salvar") {
        $nome = trim((string)($d["nome"] ?? ($atual["nome"] ?? "")));
        $sel = (string)($d["selecao"] ?? ($atual["selecao"] ?? "ponderado"));
        $ciclo = array_key_exists("ciclo", $d) ? ((string)$d["ciclo"] === "1" ? 1 : 0) : ($atual ? (int)$atual["ciclo"] : 0);
        $escala_dias = array_key_exists("escala_dias", $d) ? trim((string)$d["escala_dias"]) : ($atual["escala_dias"] ?? "");
        $escala_dias = $escala_dias === null || $escala_dias === "" || (string)$escala_dias === "0" ? null : $escala_dias;
        if ($escala_dias !== null && (!ctype_digit((string)$escala_dias) || (int)$escala_dias < 7 || (int)$escala_dias > 730)) {
            $erros[] = t("Escala inteligente: de 7 a 730 dias (vazio: o modo sorteia pelos blocos).");
        }
        $blocos = $d["blocos"] ?? null;
        if (is_string($blocos)) {
            $blocos = json_decode($blocos, true);
        }
        if ($nome === "" || strlen($nome) > 80) {
            $erros[] = t("Dê um nome ao modo (até 80 caracteres).");
        }
        if (!in_array($sel, ["inteligente", "ponderado", "aleatorio", "fifo"], true)) {
            $erros[] = t("Forma de escolha: inteligente, ponderado, aleatorio ou fifo.");
        }
        // o tipo: o que veio, senão o de antes (com blocos novos, inferido deles), senão inferido dos blocos
        $tipo = trim((string)($d["tipo"] ?? ""));
        $blocos_t = is_array($blocos) ? array_map(function ($b) { return [array_map("intval", (array)($b["dias"] ?? [])), ($b["um_por"] ?? "dia") === "bloco" ? "bloco" : "dia"]; }, $blocos) : [];
        if ($tipo === "") {
            $tipo = $atual && $blocos === null ? (string)$atual["tipo"] : modo_tipo_inferir($escala_dias, $sel, $blocos_t);
        }
        if (!isset(MODO_TIPOS[$tipo])) {
            $erros[] = t("Tipo do modo: {1}.", [1 => implode(", ", array_keys(MODO_TIPOS))]);
        } elseif ($tipo === "escala" && $escala_dias === null) {
            $erros[] = t("A escala inteligente precisa do período (escala_dias: de 7 a 730 dias).");
        } elseif ($tipo !== "escala") {
            $escala_dias = null;
        }
        if ($tipo === "aleatorio") {
            $sel = "aleatorio";
        }
        $semanas = array_key_exists("semanas", $d) ? trim((string)$d["semanas"]) : (string)($atual["semanas"] ?? 1);
        if (!ctype_digit($semanas) || (int)$semanas < 1 || (int)$semanas > 8) {
            $erros[] = t("Semanas à frente: de 1 a 8.");
        }
        $roteiro = array_key_exists("roteiro", $d) ? trim(str_replace("\r", "", (string)$d["roteiro"])) : ($atual["roteiro"] ?? "");
        if ($tipo === "personalizado") {
            if ($roteiro === "" && $blocos === null && !($atual && count(modo_blocos($id)) > 0)) {
                $erros[] = t("O modo personalizado precisa do roteiro (ou de blocos de dias, que geram um).");
            } elseif ($roteiro !== "") {
                foreach (roteiro_ler($roteiro)["erros"] as $e) {
                    $erros[] = t("Roteiro: {1}", [1 => $e]);
                }
            }
        } else {
            $roteiro = "";
        }
        $limpos = [];
        $dias_usados = [];
        if ($blocos !== null) {
            if (!is_array($blocos) || count($blocos) === 0) {
                $erros[] = t("Um modo precisa de pelo menos um bloco de dias.");
            } else {
                foreach ($blocos as $k => $b) {
                    $dias = array_values(array_unique(array_map("intval", (array)($b["dias"] ?? []))));
                    sort($dias);
                    $no = no_do_pedido($b["no_id"] ?? "");
                    $fixo = (int)($b["relogio_id"] ?? 0);
                    if (count($dias) === 0 || min($dias) < 1 || max($dias) > 7) {
                        $erros[] = t("Bloco {1}: escolha os dias (1 a 7).", [1 => $k + 1]);
                    }
                    foreach ($dias as $x) {
                        if (isset($dias_usados[$x])) {
                            $erros[] = t("O dia {1} está em mais de um bloco.", [1 => $x]);
                        }
                        $dias_usados[$x] = true;
                    }
                    if ($no === false) {
                        $erros[] = t("Bloco {1}: o ponto da árvore não existe.", [1 => $k + 1]);
                    }
                    if ($fixo > 0 && valor("SELECT id FROM relogio WHERE id = ?", [$fixo]) === null) {
                        $erros[] = t("Bloco {1}: o relógio fixo não existe.", [1 => $k + 1]);
                    }
                    $limpos[] = [trim((string)($b["nome"] ?? "")) !== "" ? trim((string)$b["nome"]) : t("Bloco {1}", [1 => $k + 1]), implode(",", $dias), $no, ($b["um_por"] ?? "dia") === "bloco" || ($tipo === "semana") ? "bloco" : "dia", $fixo > 0 ? $fixo : null];
                }
                // a forma dos blocos pelo tipo
                $forma_b = array_map(function ($b) { return $b[1]; }, $limpos);
                if (in_array($tipo, ["semana", "aleatorio"], true) && $forma_b !== ["1,2,3,4,5,6,7"]) {
                    $erros[] = t("{1}: um bloco só, com os sete dias.", [1 => t(MODO_TIPOS[$tipo])]);
                } elseif ($tipo === "semana_fds" && $forma_b !== ["1,2,3,4,5", "6,7"]) {
                    $erros[] = t("Semana e fim de semana: dois blocos, segunda a sexta e sábado e domingo.");
                }
            }
        } elseif (!$atual && $tipo !== "personalizado") {
            $erros[] = t("Um modo novo precisa dos blocos de dias.");
        }
        if (count($erros) === 0) {
            $escala_dias = $escala_dias === null ? null : (int)$escala_dias;
            if ($atual) {
                sql("UPDATE modo SET nome = ?, tipo = ?, selecao = ?, escala_dias = ?, semanas = ?, ciclo = ?, roteiro = ? WHERE id = ?", [$nome, $tipo, $sel, $escala_dias, (int)$semanas, $ciclo, $roteiro !== "" ? $roteiro : null, $id]);
                // a escala ativa com outro número de dias: o fim gravado sai, e a próxima montagem (a página Hoje, o cron, o Sortear
                // de novo) faz a escala com os dias novos (de 7 para 30: até o 30º dia; de 30 para 7: só os 7)
                if ((int)$id === modo_ativo() && ($atual["escala_dias"] === null ? null : (int)$atual["escala_dias"]) !== $escala_dias) {
                    cfg_set("escala_fim", "");
                }
            } else {
                sql("INSERT INTO modo (colecao_id, nome, tipo, selecao, escala_dias, semanas, ciclo, roteiro, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [colecao_atual(), $nome, $tipo, $sel, $escala_dias, (int)$semanas, $ciclo, $roteiro !== "" ? $roteiro : null,
                    (int)valor("SELECT COALESCE(MAX(ordem), 0) + 1 FROM modo")]);
                $id = ultimo_id();
            }
            if ($blocos !== null) {
                sql("DELETE FROM modo_bloco WHERE modo_id = ?", [$id]);
                foreach ($limpos as $k => $b) {
                    sql("INSERT INTO modo_bloco (modo_id, nome, no_id, um_por, ordem) VALUES (?, ?, ?, ?, ?)", [$id, $b[0], no_gravar($b[2]), $b[3], $k + 1]);
                    $bloco = ultimo_id();
                    ligacao_relogio("modo_bloco_relogio", "bloco_id", $bloco, $b[4]);
                    foreach (explode(",", $b[1]) as $dia) {
                        sql("INSERT INTO modo_bloco_dia (bloco_id, dia) VALUES (?, ?)", [$bloco, (int)$dia]);
                    }
                }
            }
            // o modo salvo (mesmo o ativo) vale para os próximos sorteios: o plano gravado continua (Sortear de novo o refaz)
            $msg = t("Modo {1} salvo{2}.", [1 => $nome, 2 => count($dias_usados) > 0 && count($dias_usados) < 7 ? t(" (os dias sem bloco ficam sem relógio sorteado)") : ""]);
        }
    } elseif ($acao === "ativar" && $atual) {
        // só marca o modo: o plano gravado continua como está, e o modo novo vale para os próximos sorteios (os dias que o
        // plano ainda não tem, ou Sortear de novo)
        modo_ativar($id);
        $msg = t("Modo ativo: {1}. O plano gravado continua como está; para refazê-lo com este modo, use Sortear de novo.", [1 => $atual["nome"]]);
    } elseif ($acao === "excluir" && $atual) {
        if (modo_ativo() === $id) {
            $erros[] = t("Não dá para excluir o modo ativo: ative outro antes.");
        } else {
            sql("DELETE FROM modo WHERE id = ?", [$id]);
            $msg = t("Modo {1} excluído.", [1 => $atual["nome"]]);
        }
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Configuração (página Configuração e API recurso=config). Ações:
//   salvar: horario_manha, horario_noite, uso_inicio e uso_fim (HH:MM; o horário de uso só grava com os dois válidos e o fim
//           depois do início); sol_fim (a sessão no sol esquecida aberta fecha a essa hora: o "fecha às" do tipo sol);
//           sol_auto_fim (1 ou 0: o solar sai do sol sozinho no sol_fim; 0: só pelo Tirar do sol, e a hora fica guardada);
//           sol_limiar (1 a 99: o solar vai para o sol quando a carga estimada chega a essa %); url_sistema (para a âncora
//           {link}); pulso_auto_inicio (1 ou 0: o relógio do dia entra no pulso sozinho no início do horário de uso; 0: só pelo
//           Pôs); pulso_auto_fim (1 ou 0: sai sozinho no fim do horário de uso, o "fecha às" do tipo No pulso; 0: só pelo
//           Tirou); encostado_ativo (1 ou 0: liga ou desliga o aviso Encostado do cadastro); max_sem_uso (0 a 365); previsao_limite (0 a 100); medicao_base (dias ou leituras: o gasto medido conta as medições dos últimos dias ou as últimas leituras de cada tipo); gasto_motor (vazio: conforme o relógio e o grupo; horas, robusto, marcacao, cadastro ou formulas: como calcular o gasto da bateria, para toda a coleção); documentos_copia (vazio: conforme o relógio e o arquivo; todos ou nenhum: a cópia dos documentos no banco, para toda a coleção); medicao_janela_leituras (2 a 1000); medicao_janela_dias (1 a 3650: a média do gasto medido
//           usa as medições inteiras destes últimos dias); medicao_meia_vida_dias (0 a 3650, padrão 0: sem peso por idade); url_sistema só o master (é do sistema inteiro). Só o que vier no pedido muda.
//           As mensagens e as agendas saem pelos destinos e pelas agendas (op_destinos, op_agendas).
//   evento_salvar (evento_id (ou id): 0 cria; nome, ativo, repeticao (uma, diaria, semanal, mensal, intervalo), hora (HH:MM),
//           data_inicio (uma vez: a data; a cada N dias: desde quando, vazio: hoje), dias_semana (1 a 7, lista ou separados
//           por vírgula), dia_mes (1 a 31), intervalo_dias, relogio_id (0: nenhum)); o evento novo já vai por todos os destinos
//           da coleção (e por nenhuma agenda): cada destino e cada agenda escolhe o que recebe. evento_excluir (evento_id ou id)
// ---------------------------------------------------------------------------------------------------------------------
function op_config($acao, $d)
{
    global $REPETICOES;
    $erros = [];
    $msg = "";
    $extra = [];
    $hoje = hoje_da_colecao();
    $hora_ok = "/^([01][0-9]|2[0-3]):[0-5][0-9]\$/";
    if ($acao === "salvar") {
        foreach (["horario_manha", "horario_noite", "sol_fim"] as $k) {
            if (array_key_exists($k, $d) && preg_match($hora_ok, (string)$d[$k]) !== 1) {
                $erros[] = t("{1}: uma hora HH:MM.", [1 => $k]);
            }
        }
        if ((array_key_exists("uso_inicio", $d) || array_key_exists("uso_fim", $d)) && (preg_match($hora_ok, (string)($d["uso_inicio"] ?? cfg("uso_inicio"))) !== 1
            || preg_match($hora_ok, (string)($d["uso_fim"] ?? cfg("uso_fim"))) !== 1 || (string)($d["uso_fim"] ?? cfg("uso_fim")) <= (string)($d["uso_inicio"] ?? cfg("uso_inicio")))) {
            $erros[] = t("Relógio no pulso: duas horas HH:MM, o fim depois do início.");
        }
        if (array_key_exists("carga_limiar", $d) && (!ctype_digit(trim((string)$d["carga_limiar"])) || (int)$d["carga_limiar"] < 1 || (int)$d["carga_limiar"] > 99)) {
            $erros[] = t("Carregar: o limite é uma carga de 1 a 99%.");
        }
        if (array_key_exists("sol_limiar", $d) && (!ctype_digit(trim((string)$d["sol_limiar"])) || (int)$d["sol_limiar"] < 1 || (int)$d["sol_limiar"] > 99)) {
            $erros[] = t("Solar: o limite é uma carga de 1 a 99%.");
        }
        if (array_key_exists("max_sem_uso", $d) && (!ctype_digit(trim((string)$d["max_sem_uso"])) || (int)$d["max_sem_uso"] > 365)) {
            $erros[] = t("Garantia de rodízio: de 0 a 365 dias.");
        }
        if (array_key_exists("sorteio_casas", $d) && (!ctype_digit(trim((string)$d["sorteio_casas"])) || (int)$d["sorteio_casas"] > 8)) {
            $erros[] = t("{1}: um número inteiro de {2} a {3}.", [1 => t("Casas decimais dos pesos"), 2 => 0, 3 => 8]);
        }
        if (array_key_exists("medicao_janela_dias", $d) && (!ctype_digit(trim((string)$d["medicao_janela_dias"])) || (int)$d["medicao_janela_dias"] < 1 || (int)$d["medicao_janela_dias"] > 3650)) {
            $erros[] = t("Gasto medido: a média usa de 1 a 3650 dias de medições.");
        }
        if (array_key_exists("medicao_meia_vida_dias", $d) && (!ctype_digit(trim((string)$d["medicao_meia_vida_dias"])) || (int)$d["medicao_meia_vida_dias"] > 3650)) {
            $erros[] = t("{1}: um número inteiro de {2} a {3}.", [1 => t("Gasto medido: meia-vida dos dados (dias; 0 desliga)"), 2 => 0, 3 => 3650]);
        }
        if (array_key_exists("medicao_base", $d) && !in_array((string)$d["medicao_base"], ["dias", "leituras"], true)) {
            $erros[] = t("Gasto medido: contar por dias ou por leituras.");
        }
        if (array_key_exists("gasto_motor", $d) && (string)$d["gasto_motor"] !== "" && !in_array((string)$d["gasto_motor"], GASTO_MOTORES, true)) {
            $erros[] = t("Gasto da bateria: como calcular: vazio (conforme o relógio e o grupo), horas, robusto, marcacao, cadastro ou formulas.");
        }
        if (array_key_exists("documentos_copia", $d) && !in_array((string)$d["documentos_copia"], ["", "todos", "nenhum"], true)) {
            $erros[] = t("Cópia dos documentos no banco: vazio (conforme o relógio e o arquivo), todos ou nenhum.");
        }
        if (array_key_exists("medicao_janela_leituras", $d) && (!ctype_digit(trim((string)$d["medicao_janela_leituras"])) || (int)$d["medicao_janela_leituras"] < 2 || (int)$d["medicao_janela_leituras"] > 1000)) {
            $erros[] = t("Gasto medido: de 2 a 1000 leituras de cada tipo.");
        }
        if (array_key_exists("encostado_dias", $d) && (!ctype_digit(trim((string)$d["encostado_dias"])) || (int)$d["encostado_dias"] < 1 || (int)$d["encostado_dias"] > 3650)) {
            $erros[] = t("Aviso Encostado: de 1 a 3650 dias sem ir ao pulso.");
        }
        if (array_key_exists("previsao_limite", $d) && (numero_br($d["previsao_limite"]) === null || numero_br($d["previsao_limite"]) < 0 || numero_br($d["previsao_limite"]) > 100)) {
            $erros[] = t("Previsão: o limite é uma carga de 0 a 100%.");
        }
        if (array_key_exists("url_sistema", $d) && trim((string)$d["url_sistema"]) !== "" && preg_match("#^https?://#i", trim((string)$d["url_sistema"])) !== 1) {
            $erros[] = t("Endereço do sistema: começando por http:// ou https:// (vazio: sem link).");
        } elseif (array_key_exists("url_sistema", $d) && !usuario_master() && trim((string)$d["url_sistema"]) !== sis("url_sistema")) {
            $erros[] = t("O endereço do sistema é do sistema inteiro: só o master muda.");
        }
        foreach (["proximos_por_pagina" => t("Próximos dias"), "plano_por_pagina" => t("Plano")] as $k => $onde) {
            if (array_key_exists($k, $d) && (!ctype_digit(trim((string)$d[$k])) || (int)$d[$k] < 1 || (int)$d[$k] > 366)) {
                $erros[] = t("{1}: mostrar até de 1 a 366 dias por página.", [1 => $onde]);
            }
        }
        if (array_key_exists("painel_modo", $d) && !in_array((string)$d["painel_modo"], ["lado", "flutuante"], true)) {
            $erros[] = t("Abrir o relógio: lado (ao lado da lista) ou flutuante (numa janela grande por cima da página).");
        }
        if (array_key_exists("idioma", $d) && !isset(LINGUAS[(string)$d["idioma"]])) {
            $erros[] = t("Idioma: {linguas}.", ["linguas" => implode(", ", array_keys(LINGUAS))]);
        }
        if (array_key_exists("destaque_hoje", $d) && !in_array((string)$d["destaque_hoje"], ["dia", "pulso"], true)) {
            $erros[] = t("Destaque da página Hoje: dia (o relógio do dia) ou pulso (o relógio no pulso agora).");
        }
        if (array_key_exists("fuso", $d) && trim((string)$d["fuso"]) !== "" && fuso_valido($d["fuso"]) === null) {
            $erros[] = t("Fuso horário desconhecido: use um nome como America/Sao_Paulo ou Europe/Lisbon (a lista da Configuração), ou vazio para o do sistema.");
        }
        if (array_key_exists("calibre_campos", $d) && !in_array((string)$d["calibre_campos"], ["", "movimento", "todos"], true)) {
            $erros[] = t("Campos no formulário do calibre: movimento (só os do movimento) ou todos.");
        }
        if (array_key_exists("relojinho_modo", $d) && !in_array((string)$d["relojinho_modo"], ["", "hora", "aleatorio", "sem_repeticao"], true)) {
            $erros[] = t("O reloginho da barra é hora, aleatorio ou sem_repeticao.");
        }
        if (array_key_exists("atualizar_segundos", $d) && trim((string)$d["atualizar_segundos"]) !== ""
            && (!ctype_digit(trim((string)$d["atualizar_segundos"])) || (int)$d["atualizar_segundos"] > 86400)) {
            $erros[] = t("Intervalo de atualização: de 1 a 86400 segundos, ou 0 (as páginas não se atualizam sozinhas); vazio: 60.");
        }
        if (array_key_exists("trocar_hoje", $d) && !in_array(trim((string)$d["trocar_hoje"]), ["", "0", "1"], true)) {
            $erros[] = t("Trocar o relógio de hoje no próprio dia: 1 (pode) ou 0 (só a partir de amanhã; vazio: 0).");
        }
        if (array_key_exists("semana_inicio", $d) && !in_array((string)$d["semana_inicio"], ["", "segunda", "domingo"], true)) {
            $erros[] = t("A semana começa na segunda ou no domingo (vazio: segunda).");
        }
        if (array_key_exists("escala_inicio", $d) && !in_array((string)$d["escala_inicio"], ["", "amanha", "hoje"], true)) {
            $erros[] = t("O primeiro dia da escala inteligente: amanha (hoje, ou amanhã quando o dia de hoje já começou no pulso) ou hoje (sempre hoje; vazio: amanha).");
        }
        if (array_key_exists("escala_periodo", $d) && !in_array((string)$d["escala_periodo"], ["", "corridos", "semana", "mes"], true)) {
            $erros[] = t("Onde a escala inteligente fecha: corridos (os dias do período contados), semana (no fim da semana, semanas inteiras) ou mes (no fim do mês, meses inteiros); vazio: corridos.");
        }
        if (array_key_exists("semana_ultimo_dia", $d) && !in_array((string)$d["semana_ultimo_dia"], ["", "dia_seguinte", "semana_seguinte"], true)) {
            $erros[] = t("No último dia da semana, os modos por semana montam: dia_seguinte (só o dia seguinte) ou semana_seguinte (a semana seguinte inteira); vazio: dia_seguinte.");
        }
        if (array_key_exists("hora_relogio", $d) && !in_array((string)$d["hora_relogio"], ["", "analogico", "digital", "anadigi", "ambos"], true)) {
            $erros[] = t("Relógio da Hora certa: analogico, digital, anadigi (os dois no mesmo relógio) ou ambos (um ao lado do outro).");
        }
        // a força da senha: o tamanho de 4 a 128, cada mínimo de 0 a 32, e os mínimos juntos cabem no tamanho
        $sp = senha_politica();
        $mexe_senha = false;
        foreach (array_keys(SENHA_REGRAS) as $k) {
            if (array_key_exists("senha_" . $k, $d)) {
                $mexe_senha = true;
                $v = trim((string)$d["senha_" . $k]);
                [$min, $max] = $k === "tamanho" ? [4, 128] : [0, 32];
                if (preg_match("/^\\d+\$/", $v) !== 1 || (int)$v < $min || (int)$v > $max) {
                    $erros[] = t("{1}: um número inteiro de {2} a {3}.", [1 => "senha_" . $k, 2 => $min, 3 => $max]);
                } else {
                    $sp[$k] = (int)$v;
                }
            }
        }
        if ($mexe_senha && !usuario_master()) {
            $erros[] = t("A força da senha é do sistema inteiro: só o master muda.");
        } elseif ($mexe_senha && $sp["maiusculas"] + $sp["minusculas"] + $sp["numeros"] + $sp["simbolos"] > $sp["tamanho"]) {
            $erros[] = t("Os mínimos de maiúsculas, minúsculas, números e símbolos, somados, passam do tamanho da senha.");
        }
        if (count($erros) === 0) {
            if (array_key_exists("url_sistema", $d) && usuario_master()) {
                sis_set("url_sistema", trim((string)$d["url_sistema"]));
            }
            // a força da senha é do sistema inteiro: só o master muda
            foreach (array_keys(SENHA_REGRAS) as $k) {
                if (array_key_exists("senha_" . $k, $d) && usuario_master()) {
                    sis_set("senha_" . $k, (string)(int)$d["senha_" . $k]);
                }
            }
            // o primeiro dia da escala mudou: o fim dela sai, e a próxima montagem (a página Hoje, o cron) refaz a escala contando do
            // primeiro dia novo (como mudar os dias do modo)
            if (array_key_exists("escala_inicio", $d) && (cfg("escala_inicio") === "hoje") !== (trim((string)$d["escala_inicio"]) === "hoje")) {
                cfg_set("escala_fim", "");
            }
            // onde a escala fecha mudou (ou a semana, com a escala fechando na semana ou no mês): o mesmo
            $periodo_n = array_key_exists("escala_periodo", $d) ? trim((string)$d["escala_periodo"]) : cfg("escala_periodo");
            if (($periodo_n === "" ? "corridos" : $periodo_n) !== (cfg("escala_periodo") === "" ? "corridos" : cfg("escala_periodo"))
                || (array_key_exists("semana_inicio", $d) && in_array($periodo_n, ["semana", "mes"], true)
                && ((string)$d["semana_inicio"] === "domingo") !== (cfg("semana_inicio") === "domingo"))) {
                cfg_set("escala_fim", "");
            }
            foreach (["horario_manha", "horario_noite", "uso_inicio", "uso_fim", "max_sem_uso", "sorteio_casas", "carga_limiar", "sol_limiar", "medicao_janela_dias", "medicao_meia_vida_dias", "medicao_base", "medicao_janela_leituras", "gasto_motor", "documentos_copia", "encostado_dias", "painel_modo", "destaque_hoje", "idioma", "proximos_por_pagina", "plano_por_pagina", "hora_relogio", "semana_inicio", "escala_inicio", "escala_periodo", "semana_ultimo_dia", "trocar_hoje", "atualizar_segundos", "relojinho_modo", "calibre_campos"] as $k) {
                if (array_key_exists($k, $d)) {
                    cfg_set($k, trim((string)$d[$k]));
                }
            }
            // o fuso da coleção, com o nome na caixa certa (vazio: o do sistema)
            if (array_key_exists("fuso", $d)) {
                cfg_set("fuso", (string)(fuso_valido($d["fuso"]) ?? ""));
            }
            // a hora do sol fica na Configuração; tirando do sol sozinho, o "fecha às" do tipo No sol é ela; sem tirar sozinho,
            // nenhum (só o Tirar do sol)
            if (array_key_exists("sol_fim", $d)) {
                cfg_set("sol_fim", (string)$d["sol_fim"]);
            }
            if (array_key_exists("sol_auto_fim", $d)) {
                cfg_set("sol_auto_fim", (string)$d["sol_auto_fim"] === "1" ? "1" : "0");
            }
            if ((array_key_exists("sol_fim", $d) || array_key_exists("sol_auto_fim", $d)) && isset(lancamento_tipos()["sol"])) {
                sql("UPDATE lancamento_tipo SET fecha_as = ? WHERE identificador = 'sol'", [sol_tira_sozinho() ? sol_fim_hora() . ":00" : null]);
            }
            if (array_key_exists("previsao_limite", $d)) {
                cfg_set("previsao_limite", (string)numero_br($d["previsao_limite"]));
            }
            // a caixa "Avisar quando um relógio fica encostado": liga ou desliga o aviso Encostado do cadastro (todas as versões
            // dele na árvore), o mesmo que a página Avisos faz; desligado, some o aviso, a coluna da Hoje e o filtro
            if (array_key_exists("encostado_ativo", $d)) {
                sql("UPDATE aviso SET ativo = ? WHERE identificador = 'encostado'", [(string)$d["encostado_ativo"] === "1" ? 1 : 0]);
            }
            foreach (["pulso_auto_inicio" => "pulso_auto_inicio", "pulso_auto_fim" => "pulso_auto_fim"] as $k => $chave) {
                if (array_key_exists($k, $d)) {
                    cfg_set($chave, (string)$d[$k] === "1" ? "1" : "0");
                }
            }
            // tirando do pulso sozinho, o "fecha às" do tipo No pulso é o fim do horário de uso; sem tirar sozinho, nenhum (só o Tirou)
            if ((array_key_exists("pulso_auto_fim", $d) || array_key_exists("uso_fim", $d)) && isset(lancamento_tipos()["pulso"])) {
                sql("UPDATE lancamento_tipo SET fecha_as = ? WHERE identificador = 'pulso'", [pulso_tira_sozinho() ? cfg("uso_fim") . ":00" : null]);
            }
            $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
            $msg = t("Configuração salva.");
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
            $erros[] = t("Evento não encontrado.");
        }
        if ($nome === "" || strlen($nome) > 120) {
            $erros[] = t("Dê um nome ao evento (até 120 caracteres).");
        }
        if (!isset($REPETICOES[$rep])) {
            $erros[] = t("Quando dispara: uma, diaria, semanal, mensal ou intervalo.");
        }
        if (preg_match($hora_ok, $hora) !== 1) {
            $erros[] = t("Hora: HH:MM.");
        }
        if (($rep === "uma" || $rep === "intervalo") && !($d_ok && $d_ok->format("Y-m-d") === $data_ini)) {
            $erros[] = t("Uma vez: informe a data (AAAA-MM-DD).");
        }
        if ($rep === "semanal" && count($dias) === 0) {
            $erros[] = t("Em dias da semana: marque pelo menos um dia.");
        }
        if ($rep === "mensal" && ($dia_mes < 1 || $dia_mes > 31)) {
            $erros[] = t("Dia do mês: de 1 a 31.");
        }
        if ($rep === "intervalo" && ($intervalo < 1 || $intervalo > 3650)) {
            $erros[] = t("A cada quantos dias: de 1 a 3650.");
        }
        // relógio do evento: um disponível, ou o que o evento já tinha (não troca sozinho); outro qualquer fica sem relógio
        if ($rid > 0 && (int)valor("SELECT COUNT(*) FROM relogio WHERE id = ? AND disponivel = 1", [$rid]) === 0 && (int)($atual["relogio_id"] ?? 0) !== $rid) {
            $rid = 0;
        }
        if (count($erros) === 0) {
            // só o que a repetição usa fica gravado
            $dados = [$nome, $ativo, $rep, $rep === "uma" || $rep === "intervalo" ? $data_ini : null, $hora . ":00",
                $rep === "mensal" ? $dia_mes : null, $rep === "intervalo" ? $intervalo : null];
            if ($atual) {
                sql("UPDATE evento_personalizado SET nome = ?, ativo = ?, repeticao = ?, data_inicio = ?, hora = ?, dia_mes = ?, intervalo_dias = ?
                    WHERE id = ?", array_merge($dados, [$id]));
            } else {
                sql("INSERT INTO evento_personalizado (colecao_id, nome, ativo, repeticao, data_inicio, hora, dia_mes, intervalo_dias, criado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", array_merge([colecao_atual()], $dados, [date("Y-m-d H:i:s")]));
                // evento novo já sai por todos os destinos da coleção; cada destino se ajusta na tela Destinos
                $id = ultimo_id();
                foreach (array_keys(destinos(true)) as $did) {
                    sql("INSERT INTO destino_evento (destino_id, evento_id, envia, modelo_proprio) VALUES (?, ?, 1, NULL)", [$did, $id]);
                }
                destinos(true);
            }
            // o relógio do evento (a ligação; 0: nenhum)
            ligacao_relogio("evento_relogio", "evento_id", $id, $rid > 0 ? $rid : null);
            // os dias da semana (semanal): uma linha cada, na tabela evento_dia
            sql("DELETE FROM evento_dia WHERE evento_id = ?", [$id]);
            if ($rep === "semanal") {
                foreach ($dias as $dia) {
                    sql("INSERT INTO evento_dia (evento_id, dia) VALUES (?, ?)", [$id, $dia]);
                }
            }
            $msg = t("Evento salvo.");
            $extra["id"] = $id;
        }
    } elseif ($acao === "evento_excluir") {
        $id = (int)($d["evento_id"] ?? ($d["id"] ?? 0));
        $ev = linha("SELECT * FROM evento_personalizado WHERE id = ?", [$id]);
        if (!$ev) {
            $erros[] = t("Evento não encontrado.");
        } else {
            // o que ia por cada destino e cada agenda, e os dias (evento_dia), saem junto, pelo banco (chave estrangeira em cascata)
            sql("DELETE FROM evento_personalizado WHERE id = ?", [$id]);
            destinos(true);
            agendas(true);
            $msg = t("Evento excluído.");
        }
    }
    return resultado($erros, $msg, $extra);
}

// ---------------------------------------------------------------------------------------------------------------------
// O que um destino ou uma agenda recebe (o formulário das telas Destinos e Agendas): envia[] (os tipos marcados: dia, vespera,
// o identificador de um aviso, ev<id> de um evento; com o envia[] vazio, nenhum) e modelo_<tipo> (o modelo próprio; vazio: a
// mensagem padrão). Só o que vier no pedido muda. $tabela: destino ou agenda
// ---------------------------------------------------------------------------------------------------------------------
function recebe_gravar($tabela, $id, $d)
{
    $tipos = tipos_de_aviso();
    $marcados = array_key_exists("envia", $d) ? array_values(array_filter((array)$d["envia"], function ($x) { return $x !== ""; })) : null;
    foreach (array_keys($tipos) as $k) {
        $ev = preg_match("/^ev(\d+)\$/", $k, $m) === 1 ? (int)$m[1] : null;
        $atual = $ev !== null ? linha("SELECT envia" . ($tabela === "destino" ? ", modelo_proprio" : "") . " FROM " . $tabela . "_evento WHERE " . $tabela . "_id = ? AND evento_id = ?", [$id, $ev])
            : linha("SELECT envia, modelo_proprio FROM " . $tabela . "_aviso WHERE " . $tabela . "_id = ? AND tipo_aviso = ?", [$id, $k]);
        $envia = $marcados !== null ? (in_array($k, $marcados, true) ? 1 : 0) : (int)($atual["envia"] ?? 0);
        $modelo = array_key_exists("modelo_" . $k, $d) ? (trim((string)$d["modelo_" . $k]) !== "" ? str_replace("\r\n", "\n", (string)$d["modelo_" . $k]) : null) : ($atual["modelo_proprio"] ?? null);
        if ($atual === null && $envia === 0 && $modelo === null) {
            continue;
        }
        if ($ev !== null) {
            sql("DELETE FROM " . $tabela . "_evento WHERE " . $tabela . "_id = ? AND evento_id = ?", [$id, $ev]);
            if ($tabela === "destino") {
                sql("INSERT INTO destino_evento (destino_id, evento_id, envia, modelo_proprio) VALUES (?, ?, ?, ?)", [$id, $ev, $envia, $modelo]);
            } else {
                sql("INSERT INTO agenda_evento (agenda_id, evento_id, envia) VALUES (?, ?, ?)", [$id, $ev, $envia]);
            }
        } else {
            sql("DELETE FROM " . $tabela . "_aviso WHERE " . $tabela . "_id = ? AND tipo_aviso = ?", [$id, $k]);
            sql("INSERT INTO " . $tabela . "_aviso (" . $tabela . "_id, tipo_aviso, envia, modelo_proprio) VALUES (?, ?, ?, ?)", [$id, $k, $envia, $modelo]);
        }
    }
}

// Os erros comuns de destino e agenda: nome, modelo, idioma, os tipos marcados e os modelos próprios
function recebe_erros($d, $tabela, $id)
{
    $erros = [];
    if (array_key_exists("nome", $d) && (trim((string)$d["nome"]) === "" || mb_strlen(trim((string)$d["nome"])) > 80)) {
        $erros[] = t("Dê um nome (até 80 caracteres).");
    } elseif (array_key_exists("nome", $d) && valor("SELECT id FROM " . $tabela . " WHERE nome = ? AND id <> ?", [trim((string)$d["nome"]), $id]) !== null) {
        $erros[] = t("Já existe {1} nesta coleção.", [1 => trim((string)$d["nome"])]);
    }
    if (array_key_exists("modelo", $d) && (trim((string)$d["modelo"]) === "" || strlen((string)$d["modelo"]) > 4000)) {
        $erros[] = t("A mensagem padrão não pode ficar vazia e vai até 4000 caracteres.");
    }
    if (array_key_exists("idioma", $d) && (string)$d["idioma"] !== "" && !isset(LINGUAS[(string)$d["idioma"]])) {
        $erros[] = t("Idioma: vazio (o da coleção) ou {1}.", [1 => implode(", ", array_keys(LINGUAS))]);
    }
    $tipos = tipos_de_aviso();
    foreach ((array)($d["envia"] ?? []) as $x) {
        if ($x !== "" && !isset($tipos[$x])) {
            $erros[] = t("Aviso desconhecido: {1}.", [1 => $x]);
        }
    }
    foreach ($d as $k => $v) {
        if (strpos((string)$k, "modelo_") === 0 && strlen((string)$v) > 4000) {
            $erros[] = t("Um modelo próprio vai até 4000 caracteres.");
        }
    }
    return $erros;
}

// O que um destino ou uma agenda nova recebe: o do modelo.json (o relógio do dia, a véspera e os avisos, com os modelos
// próprios do relógio do dia e da véspera) e, no destino, todos os eventos
function recebe_inicial($tabela, $id)
{
    $m = modelo_ler()[$tabela === "destino" ? "mensagens" : "agenda"];
    $tr = modelo_tradutor(lingua_valida(cfg("idioma")) ?? "pt-BR");
    foreach ($m["avisos"] as $a) {
        sql("INSERT INTO " . $tabela . "_aviso (" . $tabela . "_id, tipo_aviso, envia, modelo_proprio) VALUES (?, ?, ?, ?)", [$id, $a["tipo"], (int)$a["envia"], isset($a["modelo"]) ? $tr($a["modelo"]) : null]);
    }
    if ($tabela === "destino") {
        foreach (linhas("SELECT id FROM evento_personalizado") as $ev) {
            sql("INSERT INTO destino_evento (destino_id, evento_id, envia, modelo_proprio) VALUES (?, ?, 1, NULL)", [$id, (int)$ev["id"]]);
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Destinos (página Destinos, recurso=destinos). Ações:
//   salvar (id: 0 cria; nome, tipo (webhook, email, webpush; só ao criar), ativo (1 ou 0), idioma (vazio: o da coleção),
//     modelo (a mensagem padrão); o webhook: metodo (GET, POST, PUT), url, formato (query: os campos na URL; form; json),
//     corpo_json (só no json: o modelo do corpo; vazio: os campos viram o objeto), campo_nome[] e campo_valor[] (os campos, na
//     ordem: a lista inteira), cabecalho_nome[] e cabecalho_valor[] (os cabeçalhos; o valor vazio mantém o guardado); o
//     e-mail: para (um ou mais endereços, separados por vírgula); o Web Push: usuario_id (quem vê a coleção; sem ele, você);
//     e o que recebe: envia[] e modelo_<tipo>). Destino novo recebe o que o modelo.json diz.
//   excluir (id); testar (id; qual: manha (a mensagem desta manhã), noite (a da noite) ou texto (uma mensagem de teste))
// ---------------------------------------------------------------------------------------------------------------------
function op_destinos($acao, $d)
{
    $erros = [];
    $msg = "";
    $id = (int)($d["id"] ?? 0);
    $todos = destinos(true);
    $atual = $todos[$id] ?? null;
    if ($id > 0 && $atual === null) {
        $erros[] = t("Destino não encontrado.");
    } elseif ($acao === "salvar") {
        $tipo = $atual ? $atual["tipo"] : (string)($d["tipo"] ?? "");
        if (!isset(DESTINO_TIPOS[$tipo])) {
            $erros[] = t("Tipo: webhook, email ou webpush.");
        }
        if (!$atual && !array_key_exists("nome", $d)) {
            $d["nome"] = t(DESTINO_TIPOS[$tipo] ?? "Destino");
        }
        $erros = array_merge($erros, recebe_erros($d, "destino", $id));
        if ($tipo === "webhook") {
            $w = $atual["webhook"] ?? ["metodo" => "GET", "url" => "", "formato" => "query", "corpo_json" => null];
            $metodo = strtoupper((string)($d["metodo"] ?? $w["metodo"]));
            $url = trim((string)($d["url"] ?? $w["url"]));
            $formato = (string)($d["formato"] ?? $w["formato"]);
            if (!in_array($metodo, ["GET", "POST", "PUT"], true)) {
                $erros[] = t("Método: GET, POST ou PUT.");
            }
            if (preg_match("#^https?://\\S+\$#i", $url) !== 1 || strlen($url) > 1000) {
                $erros[] = t("O endereço do webhook começa por http:// ou https:// (até 1000 caracteres).");
            }
            if (!in_array($formato, ["query", "form", "json"], true)) {
                $erros[] = t("Formato: query (os campos na URL), form ou json.");
            } elseif ($metodo === "GET" && $formato !== "query") {
                $erros[] = t("No GET, os campos vão na URL (formato query).");
            }
            if (array_key_exists("corpo_json", $d) && trim((string)$d["corpo_json"]) !== ""
                && json_decode(strtr((string)$d["corpo_json"], ["{titulo}" => "t", "{mensagem}" => "m"]), true) === null) {
                $erros[] = t("O corpo JSON não é um JSON válido (os marcadores {titulo} e {mensagem} vão dentro das aspas).");
            }
        } elseif ($tipo === "email") {
            $para = trim((string)($d["para"] ?? ($atual["email"]["para"] ?? "")));
            $lista = array_filter(array_map("trim", explode(",", $para)));
            if (count($lista) === 0 || strlen($para) > 500 || count(array_filter($lista, function ($x) { return filter_var($x, FILTER_VALIDATE_EMAIL) === false; })) > 0) {
                $erros[] = t("Para: um ou mais e-mails, separados por vírgula.");
            }
        } elseif ($tipo === "webpush") {
            $uid = (int)($d["usuario_id"] ?? ($atual["webpush"]["usuario_id"] ?? (usuario_atual()["id"] ?? 0)));
            if (!in_array($uid, array_map("intval", array_column(membros_da_colecao(), "id")), true)) {
                $erros[] = t("O Web Push vai para alguém que vê esta coleção.");
            }
        }
        if (count($erros) === 0) {
            $nome = trim((string)($d["nome"] ?? $atual["nome"]));
            $ativo = array_key_exists("ativo", $d) ? ((string)$d["ativo"] === "1" ? 1 : 0) : ($atual ? ($atual["ativo"] ? 1 : 0) : 1);
            $idioma = array_key_exists("idioma", $d) ? ((string)$d["idioma"] !== "" ? (string)$d["idioma"] : null) : ($atual["idioma"] ?? null);
            // o fuso das horas no texto (vazio: o da coleção)
            if (array_key_exists("fuso", $d) && trim((string)$d["fuso"]) !== "" && fuso_valido($d["fuso"]) === null) {
                $erros[] = t("Fuso horário desconhecido: use um nome como America/Sao_Paulo ou Europe/Lisbon, ou vazio para o da coleção.");
            }
            $fuso_alvo = array_key_exists("fuso", $d) ? fuso_valido($d["fuso"]) : ($atual["fuso"] ?? null);
            $modelo = array_key_exists("modelo", $d) ? str_replace("\r\n", "\n", (string)$d["modelo"]) : ($atual ? $atual["modelo"] : modelo_tradutor(lingua_valida(cfg("idioma")) ?? "pt-BR")(modelo_ler()["mensagens"]["modelo"]));
            if ($atual) {
                sql("UPDATE destino SET nome = ?, ativo = ?, idioma = ?, fuso = ?, modelo = ? WHERE id = ?", [$nome, $ativo, $idioma, $fuso_alvo, $modelo, $id]);
            } else {
                sql("INSERT INTO destino (colecao_id, nome, tipo, ativo, modelo, idioma, fuso, criado) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())", [colecao_atual(), $nome, $tipo, $ativo, $modelo, $idioma, $fuso_alvo]);
                $id = ultimo_id();
                recebe_inicial("destino", $id);
            }
            if ($tipo === "webhook") {
                sql("DELETE FROM destino_webhook_campo WHERE destino_id = ?", [$id]);
                $cab_antes = [];
                foreach (($atual["webhook"]["cabecalhos"] ?? []) as $c) {
                    $cab_antes[$c["nome"]] = $c["valor_cifrado"];
                }
                if ($atual) {
                    sql("UPDATE destino_webhook SET metodo = ?, url = ?, formato = ?, corpo_json = ? WHERE destino_id = ?",
                        [$metodo, $url, $formato, $formato === "json" ? (array_key_exists("corpo_json", $d) ? (trim((string)$d["corpo_json"]) !== "" ? (string)$d["corpo_json"] : null) : $w["corpo_json"]) : null, $id]);
                } else {
                    sql("INSERT INTO destino_webhook (destino_id, metodo, url, formato, corpo_json) VALUES (?, ?, ?, ?, ?)",
                        [$id, $metodo, $url, $formato, $formato === "json" && trim((string)($d["corpo_json"] ?? "")) !== "" ? (string)$d["corpo_json"] : null]);
                }
                // os campos: a lista que veio (sem ela, os de antes; no destino novo, titulo e mensagem)
                $campos = array_key_exists("campo_nome", $d) ? array_map(null, (array)$d["campo_nome"], (array)($d["campo_valor"] ?? []))
                    : ($atual ? array_map(function ($c) { return [$c["nome"], $c["valor"]]; }, $atual["webhook"]["campos"]) : [["titulo", "{titulo}"], ["mensagem", "{mensagem}"]]);
                $ordem = 0;
                $vistos = [];
                foreach ($campos as [$cn, $cv]) {
                    $cn = trim((string)$cn);
                    if ($cn !== "" && !isset($vistos[$cn])) {
                        $vistos[$cn] = true;
                        sql("INSERT INTO destino_webhook_campo (destino_id, nome, valor, ordem) VALUES (?, ?, ?, ?)", [$id, corta_texto($cn, 80), (string)$cv, ++$ordem]);
                    }
                }
                if (array_key_exists("cabecalho_nome", $d)) {
                    sql("DELETE FROM destino_webhook_cabecalho WHERE destino_id = ?", [$id]);
                    $vistos = [];
                    foreach (array_map(null, (array)$d["cabecalho_nome"], (array)($d["cabecalho_valor"] ?? [])) as [$cn, $cv]) {
                        $cn = trim((string)$cn);
                        if ($cn !== "" && !isset($vistos[$cn]) && preg_match("/^[A-Za-z0-9-]+\$/", $cn) === 1) {
                            $vistos[$cn] = true;
                            $cifrado = (string)$cv !== "" ? segredo_cifrar((string)$cv) : ($cab_antes[$cn] ?? null);
                            if ($cifrado !== null) {
                                sql("INSERT INTO destino_webhook_cabecalho (destino_id, nome, valor_cifrado) VALUES (?, ?, ?)", [$id, $cn, $cifrado]);
                            }
                        }
                    }
                }
            } elseif ($tipo === "email") {
                sql("DELETE FROM destino_email WHERE destino_id = ?", [$id]);
                sql("INSERT INTO destino_email (destino_id, para) VALUES (?, ?)", [$id, $para]);
            } else {
                sql("DELETE FROM destino_webpush WHERE destino_id = ?", [$id]);
                sql("INSERT INTO destino_webpush (destino_id, usuario_id) VALUES (?, ?)", [$id, $uid]);
            }
            recebe_gravar("destino", $id, $d);
            destinos(true);
            $msg = $atual ? t("Destino {1} salvo.", [1 => $nome]) : t("Destino {1} criado.", [1 => $nome]);
        }
    } elseif ($acao === "excluir" && $atual) {
        sql("DELETE FROM destino WHERE id = ?", [$id]);
        destinos(true);
        $msg = t("Destino {1} excluído.", [1 => $atual["nome"]]);
    } elseif ($acao === "testar" && $atual) {
        $hoje = hoje_da_colecao();
        garantir_plano($hoje);
        $qual = (string)($d["qual"] ?? "texto");
        if ($qual === "manha" || $qual === "noite") {
            $texto = mensagem_do_destino($atual, function () use ($hoje, $qual) { return $qual === "manha" ? itens_manha($hoje) : itens_noite($hoje); }, $hoje);
        } else {
            $texto = t("Teste do Relojoeiro: se esta mensagem chegou, o destino {1} está funcionando.", [1 => $atual["nome"]]);
        }
        if ($texto === "") {
            $msg = t("Nada a enviar agora por {1}: nenhum item marcado para ele tem o que dizer.", [1 => $atual["nome"]]);
        } else {
            [$ok, $erro] = destino_enviar($atual, $texto, $qual === "noite" ? t("Relógio de amanhã") : (defined("MSG_TITULO") ? (string)constant("MSG_TITULO") : t("Relógios")));
            if ($ok) {
                $msg = t("Enviado para {1}.", [1 => $atual["nome"]]);
            } else {
                $erros[] = t("Não chegou a {1}: {2}", [1 => $atual["nome"], 2 => $erro]);
            }
        }
    } else {
        $erros[] = t("Ação desconhecida: use salvar, excluir ou testar.");
    }
    return resultado($erros, $msg, ["id" => $id]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Agendas (página Agendas, recurso=agendas). Ações:
//   salvar (id: 0 cria; nome, tipo (google, caldav, outlook, ics; só ao criar), ativa (1 ou 0), janela_dias (1 a 365: quantos dias à frente os eventos entram),
//     idioma (vazio: o da coleção), modelo (a primeira linha é o título do evento; o resto, a descrição); o Google:
//     calendario_id e chave_json (o conteúdo da chave da conta de serviço, ou o caminho dela no servidor; vazio mantém); o
//     CalDAV: url (o endereço do calendário), usuario e senha (vazia mantém; no iCloud, uma senha de app); o Outlook:
//     calendario_id (vazio: o principal); e o que recebe: envia[] e modelo_<tipo>). Agenda nova recebe o que o modelo.json diz
//   excluir (id), sincronizar (id), teste_criar (id: um evento daqui a 10 minutos), teste_remover (id), novo_link (id: o
//   link ICS muda; o de antes para de funcionar), outlook_conectar (id: devolve o endereço da página da Microsoft)
// ---------------------------------------------------------------------------------------------------------------------
function op_agendas($acao, $d)
{
    $erros = [];
    $msg = "";
    $extra = [];
    $id = (int)($d["id"] ?? 0);
    $todas = agendas(true);
    $atual = $todas[$id] ?? null;
    $hoje = hoje_da_colecao();
    if ($id > 0 && $atual === null) {
        $erros[] = t("Agenda não encontrada.");
    } elseif ($acao === "salvar") {
        $tipo = $atual ? $atual["tipo"] : (string)($d["tipo"] ?? "");
        if (!isset(AGENDA_TIPOS[$tipo])) {
            $erros[] = t("Tipo: google, caldav, outlook ou ics.");
        }
        if (!$atual && !array_key_exists("nome", $d)) {
            $d["nome"] = t(AGENDA_TIPOS[$tipo] ?? "Agenda");
        }
        $erros = array_merge($erros, recebe_erros($d, "agenda", $id));
        if (array_key_exists("janela_dias", $d) && (!ctype_digit(trim((string)$d["janela_dias"])) || (int)$d["janela_dias"] < 1 || (int)$d["janela_dias"] > 365)) {
            $erros[] = t("Agenda: de 1 a 365 dias de antecedência.");
        }
        if ($tipo === "google" && array_key_exists("calendario_id", $d) && (trim((string)$d["calendario_id"]) === "" || strlen(trim((string)$d["calendario_id"])) > 255)) {
            $erros[] = t("Informe o ID da agenda do Google (até 255 caracteres).");
        }
        // a chave do Google: o JSON colado; o caminho de um arquivo no servidor, só o master (o arquivo é do servidor)
        $chave_txt = trim((string)($d["chave_json"] ?? ""));
        if ($tipo === "google" && $chave_txt !== "" && $chave_txt[0] !== "{" && !usuario_master()) {
            $erros[] = t("Cole o conteúdo do arquivo JSON da chave (o caminho de um arquivo no servidor, só o master usa).");
        } elseif ($tipo === "google" && $chave_txt !== "" && $chave_txt[0] === "{" && !is_array(json_decode($chave_txt, true))) {
            $erros[] = t("A chave do Google não é um JSON válido: cole o arquivo inteiro, como o Google entregou.");
        }
        if ($tipo === "caldav") {
            $url = trim((string)($d["url"] ?? ($atual["caldav"]["url"] ?? "")));
            if (preg_match("#^https?://\\S+\$#i", $url) !== 1 || strlen($url) > 1000) {
                $erros[] = t("O endereço do calendário CalDAV começa por http:// ou https://.");
            }
            if (trim((string)($d["usuario"] ?? ($atual["caldav"]["usuario"] ?? ""))) === "") {
                $erros[] = t("Informe o usuário do CalDAV.");
            }
            if (!$atual && trim((string)($d["senha"] ?? "")) === "") {
                $erros[] = t("Informe a senha do CalDAV (no iCloud, uma senha de app).");
            }
        }
        if (count($erros) === 0) {
            $nome = trim((string)($d["nome"] ?? $atual["nome"]));
            $ativa = array_key_exists("ativa", $d) ? ((string)$d["ativa"] === "1" ? 1 : 0) : ($atual ? ($atual["ativa"] ? 1 : 0) : 1);
            $ant = array_key_exists("janela_dias", $d) ? (int)$d["janela_dias"] : ($atual ? $atual["janela_dias"] : (int)modelo_ler()["agenda"]["janela_dias"]);
            $idioma = array_key_exists("idioma", $d) ? ((string)$d["idioma"] !== "" ? (string)$d["idioma"] : null) : ($atual["idioma"] ?? null);
            // o fuso das horas no texto (vazio: o da coleção)
            if (array_key_exists("fuso", $d) && trim((string)$d["fuso"]) !== "" && fuso_valido($d["fuso"]) === null) {
                $erros[] = t("Fuso horário desconhecido: use um nome como America/Sao_Paulo ou Europe/Lisbon, ou vazio para o da coleção.");
            }
            $fuso_alvo = array_key_exists("fuso", $d) ? fuso_valido($d["fuso"]) : ($atual["fuso"] ?? null);
            $modelo = array_key_exists("modelo", $d) ? str_replace("\r\n", "\n", (string)$d["modelo"]) : ($atual ? $atual["modelo"] : modelo_tradutor(lingua_valida(cfg("idioma")) ?? "pt-BR")(modelo_ler()["agenda"]["modelo"]));
            if ($atual) {
                sql("UPDATE agenda SET nome = ?, ativa = ?, janela_dias = ?, idioma = ?, fuso = ?, modelo = ? WHERE id = ?", [$nome, $ativa, $ant, $idioma, $fuso_alvo, $modelo, $id]);
            } else {
                sql("INSERT INTO agenda (colecao_id, nome, tipo, ativa, janela_dias, modelo, idioma, fuso, criado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())", [colecao_atual(), $nome, $tipo, $ativa, $ant, $modelo, $idioma, $fuso_alvo]);
                $id = ultimo_id();
                recebe_inicial("agenda", $id);
            }
            if ($tipo === "google") {
                $cal = trim((string)($d["calendario_id"] ?? ($atual["google"]["calendario_id"] ?? "")));
                $chave = array_key_exists("chave_json", $d) && trim((string)$d["chave_json"]) !== "" ? segredo_cifrar(trim((string)$d["chave_json"])) : ($atual["google"]["chave_json_cifrada"] ?? segredo_cifrar(""));
                sql("DELETE FROM agenda_google WHERE agenda_id = ?", [$id]);
                sql("INSERT INTO agenda_google (agenda_id, calendario_id, chave_json_cifrada) VALUES (?, ?, ?)", [$id, $cal, $chave]);
            } elseif ($tipo === "caldav") {
                $senha = trim((string)($d["senha"] ?? "")) !== "" ? segredo_cifrar((string)$d["senha"]) : $atual["caldav"]["senha_cifrada"];
                sql("DELETE FROM agenda_caldav WHERE agenda_id = ?", [$id]);
                sql("INSERT INTO agenda_caldav (agenda_id, url, usuario, senha_cifrada) VALUES (?, ?, ?, ?)", [$id, $url, trim((string)($d["usuario"] ?? $atual["caldav"]["usuario"])), $senha]);
            } elseif ($tipo === "outlook") {
                if ($atual) {
                    sql("UPDATE agenda_outlook SET calendario_id = ? WHERE agenda_id = ?", [array_key_exists("calendario_id", $d) ? (trim((string)$d["calendario_id"]) !== "" ? trim((string)$d["calendario_id"]) : null) : ($atual["outlook"]["calendario_id"] ?? null), $id]);
                } else {
                    sql("INSERT INTO agenda_outlook (agenda_id, conta, calendario_id, refresh_token_cifrado) VALUES (?, NULL, ?, NULL)", [$id, trim((string)($d["calendario_id"] ?? "")) !== "" ? trim((string)$d["calendario_id"]) : null]);
                }
            } elseif (!$atual) {
                $token = b64url(random_bytes(24));
                sql("INSERT INTO agenda_ics (agenda_id, token_cifrado, token_hash) VALUES (?, ?, ?)", [$id, segredo_cifrar($token), hash("sha256", $token)]);
            }
            recebe_gravar("agenda", $id, $d);
            agendas(true);
            $msg = $atual ? t("Agenda {1} salva.", [1 => $nome]) : t("Agenda {1} criada.", [1 => $nome]);
        }
    } elseif ($acao === "excluir" && $atual) {
        // os eventos de hoje em diante que ela criou saem da agenda de fora (o que já passou fica lá, como histórico)
        $p = agenda_provedor($atual);
        if ($p[0] === null) {
            foreach (linhas("SELECT externo_id FROM agenda_sincronizado WHERE agenda_id = ? AND data >= ?", [$id, $hoje->format("Y-m-d")]) as $ev) {
                $p[3]($ev["externo_id"]);
            }
        }
        sql("DELETE FROM agenda WHERE id = ?", [$id]);
        agendas(true);
        $msg = t("Agenda {1} excluída.", [1 => $atual["nome"]]);
    } elseif ($acao === "sincronizar" && $atual) {
        $r = agenda_sincronizar($atual, $hoje);
        if ($r["mensagem"] !== "") {
            $erros[] = $r["mensagem"];
        } elseif ($r["erros"] > 0) {
            $erros[] = t("Agenda: {1} criados, {2} atualizados, {3} removidos e {4} recusados.", [1 => $r["criados"], 2 => $r["atualizados"], 3 => $r["removidos"], 4 => $r["erros"]]);
        } else {
            $msg = t("Agenda sincronizada: {1} criados, {2} atualizados, {3} removidos.", [1 => $r["criados"], 2 => $r["atualizados"], 3 => $r["removidos"]]);
        }
        $extra = ["criados" => $r["criados"], "atualizados" => $r["atualizados"], "removidos" => $r["removidos"], "recusados" => $r["erros"]];
    } elseif ($acao === "teste_criar" && $atual) {
        // um evento de teste daqui a 10 minutos, pelo modelo do relógio do dia; o id fica para poder removê-lo
        $p = agenda_provedor($atual);
        if ($p[0] !== null) {
            $erros[] = $p[0];
        } else {
            $dia = plano_do_dia($hoje->format("Y-m-d"));
            $inicio = new DateTimeImmutable("+10 minutes");
            $m = ["chave" => "teste:" . time(), "tipo" => "dia", "relogio_id" => $dia ? (int)$dia["relogio_id"] : 0, "data" => $inicio->format("Y-m-d"), "hora" => $inicio->format("H:i"),
                "fazer" => t("Usar hoje"), "motivo" => $dia ? texto_ate($dia["data"], "hoje") : t("evento de teste do sistema")];
            $f = evento_formatado($m, $atual);
            $f = ["titulo" => "[TESTE] " . $f["titulo"], "descricao" => t("{1}\n\nEvento de teste criado pela tela Agendas do Relojoeiro.", [1 => $f["descricao"]])];
            if ($atual["teste_id"] !== null) {
                $p[3]($atual["teste_id"]);
            }
            [$ok, $ext] = $p[1]($m, $f);
            if ($ok) {
                sql("UPDATE agenda SET teste_id = ? WHERE id = ?", [$ext, $id]);
                $msg = t("Evento de teste criado para hoje às {1}: \"{2}\". Confira na agenda.", [1 => $inicio->format("H:i"), 2 => $f["titulo"]]);
            } else {
                $erros[] = t("A agenda recusou o evento de teste. Confira o calendário e a permissão de escrita.");
            }
        }
    } elseif ($acao === "teste_remover" && $atual) {
        $p = agenda_provedor($atual);
        if ($atual["teste_id"] === null) {
            $erros[] = t("Não há evento de teste para remover.");
        } elseif ($p[0] !== null) {
            $erros[] = $p[0];
        } elseif ($p[3]($atual["teste_id"])) {
            sql("UPDATE agenda SET teste_id = NULL WHERE id = ?", [$id]);
            $msg = t("Evento de teste removido da agenda.");
        } else {
            $erros[] = t("A agenda não removeu o evento de teste.");
        }
    } elseif ($acao === "novo_link" && $atual && $atual["tipo"] === "ics") {
        $token = b64url(random_bytes(24));
        sql("UPDATE agenda_ics SET token_cifrado = ?, token_hash = ? WHERE agenda_id = ?", [segredo_cifrar($token), hash("sha256", $token), $id]);
        $msg = t("Link novo criado: o de antes parou de funcionar. Assine o novo no app de agenda.");
        $extra = ["link" => ics_link(agendas(true)[$id])];
    } elseif ($acao === "outlook_conectar" && $atual && $atual["tipo"] === "outlook") {
        if (trim((string)cfg_php("OUTLOOK_CLIENTE_ID", "")) === "") {
            $erros[] = t("O Outlook não está configurado no servidor (OUTLOOK_CLIENTE_ID e OUTLOOK_CLIENTE_SEGREDO no config.php): use o link ICS.");
        } else {
            $msg = t("Abra o endereço para entrar na conta Microsoft e autorizar.");
            $extra = ["url" => outlook_autorizar_url($id)];
        }
    } else {
        $erros[] = t("Ação desconhecida: use salvar, excluir, sincronizar, teste_criar, teste_remover, novo_link ou outlook_conectar.");
    }
    return resultado($erros, $msg, array_merge(["id" => $id], $extra));
}

// A importação do sistema anterior (recurso=importacao, acao=importar; e o importar.php): traz do banco antigo (MySQL/
// MariaDB) a árvore (os grupos, com os mesmos números), os relógios, as fotos, os usuários, os valores dos campos (cada
// coluna do cadastro antigo no campo equivalente, só nos relógios em que o campo vale) e o histórico, como lançamentos
// (sessões no pulso, no winder e no sol; leituras de carga; cordas; trocas de pilha; revisões; as marcações antigas sem
// duração). O plano, a configuração e os critérios vêm nas etapas deles. Com o banco antigo no mesmo servidor MySQL, o
// usuário do config.php; com este sistema no PostgreSQL ou no SQLite, ANTIGO_HOST, ANTIGO_PORTA, ANTIGO_USUARIO e
// ANTIGO_SENHA (o que faltar vem do DB_*). Campos: banco (o nome do banco antigo), substituir (1: apaga antes os relógios e
// o histórico deste banco; sem ele, só num banco sem relógios). Devolve {ok, mensagem, erros, contagem}
function importacao_servidor()
{
    $de = function ($nome, $padrao) {
        return defined("ANTIGO_" . $nome) ? constant("ANTIGO_" . $nome) : (banco_tipo() === "mysql" && defined("DB_" . $nome) ? constant("DB_" . $nome) : $padrao);
    };
    return ["host" => (string)$de("HOST", "127.0.0.1"), "porta" => (int)$de("PORTA", 0), "usuario" => (string)$de("USUARIO", "root"), "senha" => (string)$de("SENHA", "")];
}

// A situação da importação (recurso=importacao): o servidor do banco antigo (sem a senha), quantos relógios este banco já
// tem (com algum, a importação pede substituir=1) e se a extensão do MySQL está no PHP
function importacao_situacao()
{
    $sv = importacao_servidor();
    $n = (int)valor("SELECT COUNT(*) FROM relogio");
    return ["servidor_antigo" => ["host" => $sv["host"], "porta" => $sv["porta"] > 0 ? $sv["porta"] : null, "usuario" => $sv["usuario"],
        "de_onde" => defined("ANTIGO_HOST") || defined("ANTIGO_USUARIO") ? "ANTIGO_* do config.php" : "DB_* do config.php"],
        "mysqli" => class_exists("mysqli"), "relogios_neste_banco" => $n, "precisa_substituir" => $n > 0,
        "traz" => [t("a árvore (os grupos, com os mesmos números)"), t("os relógios"), t("as fotos"), t("os usuários"), t("os valores dos campos"), t("o histórico, como lançamentos")]];
}

// A importação (descrita acima, em importacao_servidor): confere o pedido, abre o banco antigo e traz tudo de uma vez
function op_importacao($acao, $d)
{
    if ($acao !== "importar") {
        return ["ok" => false, "mensagem" => "", "erros" => [t("Ação desconhecida: use importar.")]];
    }
    $banco = trim((string)($d["banco"] ?? ""));
    if (preg_match("/^[A-Za-z0-9_]+\$/", $banco) !== 1) {
        return ["ok" => false, "mensagem" => "", "erros" => [t("banco: o nome do banco antigo (letras, números e _).")]];
    }
    if (!class_exists("mysqli")) {
        return ["ok" => false, "mensagem" => "", "erros" => [t("O PHP não tem a extensão do MySQL (mysqli): o banco antigo é MySQL/MariaDB.")]];
    }
    $sv = importacao_servidor();
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $antigo = new mysqli($sv["host"], $sv["usuario"], $sv["senha"], $banco, $sv["porta"] > 0 ? $sv["porta"] : null);
        $antigo->set_charset("utf8mb4");
    } catch (mysqli_sql_exception $e) {
        return ["ok" => false, "mensagem" => "", "erros" => [t("Não consegui abrir o banco antigo {1} com o usuário {2}: {3}. O usuário precisa poder ler o banco antigo. No mysql, como root: GRANT SELECT ON {4}.* TO '{5}'@'localhost';{6}", [1 => $banco, 2 => $sv["usuario"], 3 => $e->getMessage(), 4 => $banco, 5 => $sv["usuario"], 6 => banco_tipo() === "mysql" ? "" : t(" Com este sistema no {1}, diga onde está o MySQL antigo no config.php: ANTIGO_HOST, ANTIGO_PORTA, ANTIGO_USUARIO, ANTIGO_SENHA.", [1 => banco_tipo()])])]];
    }
    $ler = function ($comando) use ($antigo) {
        return $antigo->query($comando)->fetch_all(MYSQLI_ASSOC);
    };
    if ((int)valor("SELECT COUNT(*) FROM relogio") > 0 && (string)($d["substituir"] ?? "") !== "1") {
        return ["ok" => false, "mensagem" => "", "erros" => [t("Este banco já tem relógios. Para apagar e importar de novo: substituir=1 (na linha de comando, --substituir).")]];
    }
    try {
        $conta = importacao_trazer($ler);
    } catch (Throwable $e) {
        // nada fica pela metade: o que já tinha sido gravado volta
        try {
            banco_desfaz();
        } catch (Throwable $x) {
            // a transação já tinha acabado
        }
        return ["ok" => false, "mensagem" => "", "erros" => [t("A importação parou e nada foi gravado: {1}", [1 => $e->getMessage()])]];
    }
    $partes = [];
    foreach ($conta as $o_que => $n) {
        $partes[] = $o_que . ": " . $n;
    }
    return ["ok" => true, "mensagem" => t("Importado de {1}: {2}.", [1 => $banco, 2 => implode("; ", $partes)]), "erros" => [], "contagem" => $conta];
}

// O que a importação traz, numa transação (op_importacao chama, com a leitura do banco antigo): devolve quantos de cada
function importacao_trazer($ler)
{
    $conta = [];
    banco_inicio();
    sql("DELETE FROM lancamento");
    sql("DELETE FROM campo_valor");
    sql("DELETE FROM campo_valor_item");
    sql("DELETE FROM foto");
    sql("DELETE FROM relogio");

    // a árvore: cada grupo antigo vai para o grupo da árvore da coleção com o mesmo caminho de nomes (o pai antes do filho);
    // o que não existe é criado. Os códigos novos são os do banco (outras coleções já usam números): $gmap e $rmap levam
    // os antigos aos novos
    $gmap = [];
    $antigos = [];
    foreach ($ler("SELECT * FROM grupo") as $g) {
        $antigos[(int)$g["id"]] = $g;
    }
    while (count($antigos) > 0) {
        $resto = [];
        foreach ($antigos as $gid => $g) {
            $pai_antigo = $g["pai_id"] === null ? null : (int)$g["pai_id"];
            if ($pai_antigo !== null && !isset($gmap[$pai_antigo])) {
                $resto[$gid] = $g;
                continue;
            }
            $pai = $pai_antigo === null ? null : $gmap[$pai_antigo];
            $ja = null;
            foreach (linhas("SELECT id, nome FROM no WHERE pai_id <=> ?", [$pai]) as $x) {
                if (mesmo_nome($x["nome"], $g["nome"])) {
                    $ja = (int)$x["id"];
                }
            }
            if ($ja === null) {
                sql("INSERT INTO no (pai_id, nome, ordem) VALUES (?, ?, ?)", [no_gravar($pai), $g["nome"], (int)$g["ordem"]]);
                $ja = ultimo_id();
            }
            $gmap[$gid] = $ja;
        }
        if (count($resto) === count($antigos)) {
            break;
        }
        $antigos = $resto;
    }
    nos_todos(true);
    $conta[t("pontos da árvore")] = count($ler("SELECT id FROM grupo"));

    // os relógios (com os códigos novos)
    $rmap = [];
    foreach ($ler("SELECT * FROM relogio ORDER BY id") as $r) {
        sql("INSERT INTO relogio (colecao_id, nome, no_id, disponivel, criado) VALUES (?, ?, ?, ?, NOW())",
            [colecao_atual(), $r["nome"], no_gravar($r["grupo_id"] === null ? null : ($gmap[(int)$r["grupo_id"]] ?? null)), (int)$r["disponivel"]]);
        $rmap[(int)$r["id"]] = ultimo_id();
    }
    $conta[t("relógios")] = count($ler("SELECT id FROM relogio"));

    foreach ($ler("SELECT * FROM foto") as $f) {
        if (isset($rmap[(int)$f["relogio_id"]])) {
            sql("INSERT INTO foto (relogio_id, tipo, dados, atualizado) VALUES (?, ?, ?, ?)", [$rmap[(int)$f["relogio_id"]], $f["tipo"], $f["dados"], $f["atualizado"]]);
        }
    }
    $conta[t("fotos")] = count($ler("SELECT relogio_id FROM foto"));

    // os usuários do sistema antigo: os que ainda não existem entram como membros desta coleção (contas oficiais); o login que
    // já é o login ou o e-mail de uma conta daqui fica de fora (nunca duas contas com o mesmo)
    foreach ($ler("SELECT * FROM usuario") as $u) {
        if (identificador_em_uso($u["login"]) === null) {
            sem_escopo(function () use ($u) {
                sql("INSERT INTO usuario (login, senha_hash, papel, criado) VALUES (?, ?, 'comum', ?)", [$u["login"], $u["senha_hash"], $u["criado"]]);
                sql("INSERT INTO colecao_usuario (colecao_id, usuario_id, desde) VALUES (?, ?, NOW())", [colecao_atual() ?? 0, ultimo_id()]);
            });
        }
    }
    $conta[t("usuários")] = count($ler("SELECT id FROM usuario"));

    // os valores: coluna antiga => campo (o identificador); só nos relógios em que o campo vale, e só valores que servem
    $colunas = ["obs" => "observacao", "preferencia" => "preferencia", "data_compra" => "data_compra", "valor_compra" => "valor_compra", "loja" => "loja",
        "garantia_ate" => "garantia_ate", "autonomia_dias" => "autonomia_dias", "decaimento_uso" => "decaimento_uso", "decaimento_repouso" => "decaimento_repouso",
        "data_revisao" => "data_revisao", "intervalo_revisao_meses" => "intervalo_revisao_meses", "reserva_horas" => "reserva_horas", "corda_manual" => "corda_manual",
        "carga_pulso_horas" => "carga_pulso_horas", "carga_pulso_reserva" => "carga_pulso_reserva", "carga_winder_horas" => "carga_winder_horas",
        "carga_winder_reserva" => "carga_winder_reserva", "reserva_dias" => "reserva_dias", "carga_sol_horas" => "carga_sol_horas", "data_pilha" => "data_pilha",
        "vida_pilha_meses" => "vida_pilha_meses"];
    $conta[t("valores")] = 0;
    $conta[t("valores de campo que não vale para o relógio (não trazidos)")] = 0;
    $conta[t("valores que não servem no campo (não trazidos)")] = 0;
    $existem = array_column($ler("SHOW COLUMNS FROM relogio"), "Field");
    foreach ($ler("SELECT * FROM relogio") as $r) {
        if (!isset($rmap[(int)$r["id"]])) {
            continue;
        }
        $campos = campos_do_relogio(["no_id" => $r["grupo_id"] === null ? null : ($gmap[(int)$r["grupo_id"]] ?? null)]);
        foreach ($colunas as $col => $ident) {
            if (in_array($col, $existem, true) && $r[$col] !== null && trim((string)$r[$col]) !== "") {
                if (!isset($campos[$ident])) {
                    $conta[t("valores de campo que não vale para o relógio (não trazidos)")]++;
                } else {
                    $v = valor_para_campo($campos[$ident], $r[$col]);
                    if ($v[1] !== "" || $v[0] === null) {
                        $conta[t("valores que não servem no campo (não trazidos)")]++;
                    } else {
                        valor_gravar("relogio", $rmap[(int)$r["id"]], $campos[$ident], $v[0]);
                        $conta[t("valores")]++;
                    }
                }
            }
        }
    }

    // o histórico: marcações (evento) e sessões (uso_periodo), como lançamentos
    $tipos = [];
    foreach (lancamento_tipos(true) as $ident => $t) {
        $tipos[$ident] = (int)$t["id"];
    }
    // os horários da configuração antiga: a sessão esquecida aberta no pulso fecha no fim do horário de uso; no sol, no fim da tarde
    $cfg_antiga = [];
    foreach ($ler("SELECT chave, valor FROM config") as $c) {
        $cfg_antiga[$c["chave"]] = $c["valor"];
    }
    foreach (["pulso" => "uso_fim", "sol" => "sol_fim"] as $ident => $chave) {
        if (preg_match("/^([01][0-9]|2[0-3]):[0-5][0-9]\$/", $cfg_antiga[$chave] ?? "") === 1) {
            sql("UPDATE lancamento_tipo SET fecha_as = ? WHERE identificador = ?", [$cfg_antiga[$chave], $ident]);
        }
    }
    // os dias com algum período no pulso (de qualquer relógio): neles, a marcação "relógio do dia" não conta (o uso vem dos períodos)
    $dias_controlados = [];
    foreach ($ler("SELECT DISTINCT DATE(inicio) AS dia FROM uso_periodo WHERE tipo IN ('rodizio', 'pulso')") as $x) {
        $dias_controlados[$x["dia"]] = true;
    }
    $de_evento = ["carga" => "carga", "corda" => "corda", "pilha" => "pilha", "revisao" => "revisao", "sol" => "sol_antigo", "winder" => "winder_antigo", "pulso" => "pulso_antigo"];
    $conta[t("lançamentos")] = 0;
    $conta[t("marcações \"relógio do dia\" em dia sem período (viraram uma sessão no pulso, no horário de uso)")] = 0;
    $conta[t("marcações \"relógio do dia\" em dia com período (não trazidas: o uso vem dos períodos)")] = 0;
    foreach ($ler("SELECT * FROM evento ORDER BY quando") as $e) {
        if (!isset($rmap[(int)$e["relogio_id"]])) {
            continue;
        }
        $e["relogio_id"] = $rmap[(int)$e["relogio_id"]];
        if (isset($de_evento[$e["tipo"]]) && isset($tipos[$de_evento[$e["tipo"]]])) {
            sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, NULL, ?, 'importado', NOW())",
                [(int)$e["relogio_id"], $tipos[$de_evento[$e["tipo"]]], $e["quando"], $e["valor"] === null ? null : (float)$e["valor"]]);
            $conta[t("lançamentos")]++;
        } elseif ($e["tipo"] === "uso" && !isset($dias_controlados[substr($e["quando"], 0, 10)])) {
            // como no sistema antigo: o dia inteiro no pulso, do início ao fim do horário de uso
            $dia = substr($e["quando"], 0, 10);
            sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, 'importado', NOW())",
                [(int)$e["relogio_id"], $tipos["pulso"], $dia . " " . ($cfg_antiga["uso_inicio"] ?? "07:00") . ":00", $dia . " " . ($cfg_antiga["uso_fim"] ?? "22:00") . ":00"]);
            $dias_controlados[$dia] = true;
            $conta[t("marcações \"relógio do dia\" em dia sem período (viraram uma sessão no pulso, no horário de uso)")]++;
            $conta[t("lançamentos")]++;
        } else {
            $conta[t("marcações \"relógio do dia\" em dia com período (não trazidas: o uso vem dos períodos)")]++;
        }
    }
    $de_periodo = ["rodizio" => ["pulso", "rodizio"], "pulso" => ["pulso", "manual"], "winder" => ["winder", "manual"], "sol" => ["sol", "manual"]];
    foreach ($ler("SELECT * FROM uso_periodo ORDER BY inicio") as $p) {
        if (!isset($rmap[(int)$p["relogio_id"]])) {
            continue;
        }
        $p["relogio_id"] = $rmap[(int)$p["relogio_id"]];
        $tipo = $de_periodo[$p["tipo"] ?? "rodizio"] ?? ["pulso", "manual"];
        sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, ?, NOW())",
            [(int)$p["relogio_id"], $tipos[$tipo[0]], $p["inicio"], $p["fim"], $tipo[1]]);
        $conta[t("lançamentos")]++;
    }
    banco_confirma();
    return $conta;
}
