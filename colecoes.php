<?php
// As coleções: o que é de cada uma no banco (o escopo do pedido, a raiz da árvore, o plano), o conjunto inicial de uma
// coleção nova (o modelo.json), os segredos guardados cifrados, a configuração do sistema inteiro e a migração v25, que
// passa o banco de uma pessoa só para o de várias pessoas. Parte do lib.php (o lib.php inclui este arquivo).
if (!function_exists("cfg")) {
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------------------------------------------------------
// A coleção do pedido
// ---------------------------------------------------------------------------------------------------------------------

// A coleção em que o pedido trabalha (o escopo do banco), ou null (sem escopo)
function colecao_atual()
{
    $e = banco_escopo_atual();
    return $e === null ? null : (int)$e["colecao"];
}

// A raiz da árvore da coleção do pedido (o grupo que o resto do código vê como vazio: "na raiz", "todos")
function no_raiz()
{
    $e = banco_escopo_atual();
    if ($e === null || (int)$e["raiz"] === 0) {
        throw new RuntimeException(t("sem coleção no pedido"));
    }
    return (int)$e["raiz"];
}

// Um grupo para gravar: o vazio (a raiz, "todos") vira a raiz da árvore da coleção
function no_gravar($no)
{
    return $no === null || $no === "" || (int)$no === 0 ? no_raiz() : (int)$no;
}

// Grava o relógio de um dia do plano da coleção, com o bloco do modo que o escolheu (ou nenhum). $substituir: o dia
// pode já existir (o REPLACE de antes)
function plano_gravar($data, $relogio_id, $bloco_id, $origem, $motivo, $acao = null, $substituir = false)
{
    $c = colecao_atual();
    if ($substituir) {
        sql("DELETE FROM plano WHERE data = ?", [$data]);
    }
    sql("INSERT INTO plano (colecao_id, data, relogio_id, origem, acao, motivo, criado) VALUES (?, ?, ?, ?, ?, ?, NOW())", [$c, $data, (int)$relogio_id, $origem, $acao, $motivo]);
    if ($bloco_id !== null) {
        sql("INSERT INTO plano_bloco (colecao_id, data, bloco_id) VALUES (?, ?, ?)", [$c, $data, (int)$bloco_id]);
    }
}

// Marca um dia como livre (sem relógio)
function plano_livre_marcar($data)
{
    sql("INSERT IGNORE INTO plano_livre (colecao_id, data, criado) VALUES (?, ?, NOW())", [colecao_atual(), $data]);
}

// Liga ou desfaz a ligação opcional de uma linha com um relógio (acessorio_relogio, evento_relogio, modo_bloco_relogio)
function ligacao_relogio($tabela, $coluna, $id, $relogio_id)
{
    sql("DELETE FROM " . $tabela . " WHERE " . $coluna . " = ?", [(int)$id]);
    if ($relogio_id !== null && (int)$relogio_id > 0) {
        sql("INSERT INTO " . $tabela . " (" . $coluna . ", relogio_id) VALUES (?, ?)", [(int)$id, (int)$relogio_id]);
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// A configuração do sistema inteiro (a tabela sistema): as marcas do cron, da cópia de segurança, do aleatório e das
// migrações. A de cada coleção é o cfg()
// ---------------------------------------------------------------------------------------------------------------------
const SISTEMA_CHAVES = ["cron_ultima_execucao", "cron_erro", "cron_registro", "backup_ultima", "aleatorio_fora", "aleatorio_ultima", "url_sistema"];

function sis($chave, $novo = null)
{
    static $cache = null;
    if ($chave === null) {
        $cache = null;
        return "";
    }
    if ($cache === null) {
        $cache = [];
        if (banco_tem_tabela("sistema")) {
            foreach (sem_escopo(function () { return linhas("SELECT chave, valor FROM sistema"); }) as $c) {
                $cache[$c["chave"]] = $c["valor"];
            }
        }
    }
    if ($novo !== null) {
        $cache[$chave] = $novo;
    }
    return $cache[$chave] ?? "";
}

function sis_set($chave, $valor)
{
    sem_escopo(function () use ($chave, $valor) {
        sql("REPLACE INTO sistema (chave, valor) VALUES (?, ?)", [$chave, (string)$valor]);
    });
    sis($chave, (string)$valor);
}

// ---------------------------------------------------------------------------------------------------------------------
// Os segredos (a senha do CalDAV, a chave do Google, o token do Outlook, um cabeçalho de webhook) ficam cifrados no banco
// (AES-256-GCM): quem copia o banco não leva as senhas. A chave: SEGREDO_CHAVE do config.php; sem ela, o API_TOKEN (trocar
// o API_TOKEN, então, perde os segredos guardados)
// ---------------------------------------------------------------------------------------------------------------------
function segredo_chave()
{
    $base = defined("SEGREDO_CHAVE") && trim((string)SEGREDO_CHAVE) !== "" ? (string)SEGREDO_CHAVE : (string)API_TOKEN;
    return hash("sha256", "relojoeiro|" . $base, true);
}

function segredo_cifrar($texto)
{
    $iv = random_bytes(12);
    $tag = "";
    $c = openssl_encrypt((string)$texto, "aes-256-gcm", segredo_chave(), OPENSSL_RAW_DATA, $iv, $tag);
    return "v1:" . base64_encode($iv . $tag . $c);
}

// O texto de um segredo, ou null (não abre: outra chave, ou não é um segredo)
function segredo_abrir($cifrado)
{
    $cifrado = (string)$cifrado;
    if (strpos($cifrado, "v1:") !== 0) {
        return null;
    }
    $b = base64_decode(substr($cifrado, 3), true);
    if ($b === false || strlen($b) < 28) {
        return null;
    }
    $t = openssl_decrypt(substr($b, 28), "aes-256-gcm", segredo_chave(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $t === false ? null : $t;
}

// ---------------------------------------------------------------------------------------------------------------------
// O conjunto inicial de uma coleção nova: o modelo.json. Sem códigos: cada grupo tem um apelido, e o resto cita o grupo
// pelo apelido ("raiz": a árvore inteira); o aviso cita o tipo que o resolve pelo identificador. Os nomes e os textos estão
// em português e entram no idioma de quem cria a coleção (a parte "cadastro" de textos/<idioma>.php)
// ---------------------------------------------------------------------------------------------------------------------
function modelo_ler()
{
    static $m = null;
    if ($m === null) {
        $m = json_decode((string)@file_get_contents(__DIR__ . "/modelo.json"), true);
        if (!is_array($m)) {
            throw new RuntimeException(t("o modelo.json não existe ou não é um JSON válido"));
        }
    }
    return $m;
}

// Os totais prontos do modelo (modelo.json, totais) numa árvore ($raiz: a raiz dela), na ordem, com o nome e os textos
// entre aspas da expressão no idioma ($tr, o tradutor do modelo). Sem escopo (quem chama já está fora dele)
function totais_do_modelo($raiz, $tr)
{
    foreach (array_values(modelo_ler()["totais"]) as $i => $x) {
        $expr = preg_replace_callback('/"([^"]*)"/', function ($m) use ($tr) { return "\"" . str_replace("\"", "'", $tr($m[1])) . "\""; }, $x["expressao"]);
        sql("INSERT INTO total (no_id, coluna, nome, expressao, rodape, pagina, largura, formato, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [(int)$raiz, $x["coluna"], $tr($x["nome"]), $expr, (int)$x["rodape"], (int)$x["pagina"], $x["largura"], $x["formato"], ($i + 1) * 10]);
    }
}

// As unidades do modelo (modelo.json, unidades) numa árvore ($raiz: a raiz dela), com o nome no idioma ($tr): as bases
// primeiro, depois as que se convertem nelas e, por fim, a unidade em que cada uma também aparece. A que a árvore já tem
// (o mesmo símbolo) fica como está. Sem escopo (quem chama já está fora dele)
function unidades_do_modelo($raiz, $tr)
{
    $u = modelo_ler()["unidades"];
    $id = function ($simbolo) use ($raiz) {
        $x = valor("SELECT id FROM unidade WHERE no_id = ? AND simbolo = ?", [(int)$raiz, $simbolo]);
        return $x === null ? null : (int)$x;
    };
    foreach ([false, true] as $com_base) {
        foreach ($u as $x) {
            if (($x["base"] !== null) === $com_base && $id($x["simbolo"]) === null) {
                sql("INSERT INTO unidade (no_id, simbolo, nome, base_id, fator, zero) VALUES (?, ?, ?, ?, ?, ?)",
                    [(int)$raiz, $x["simbolo"], $tr($x["nome"]), $com_base ? $id($x["base"]) : null, $x["fator"], $x["zero"] ?? 0]);
            }
        }
    }
    foreach ($u as $x) {
        if ($x["mostrar_em"] !== null) {
            sql("UPDATE unidade SET mostrar_em_id = ? WHERE id = ? AND mostrar_em_id IS NULL", [$id($x["mostrar_em"]), $id($x["simbolo"])]);
        }
    }
}

// As divisórias do modelo (modelo.json, secoes) numa árvore ($raiz), com o nome no idioma ($tr); a que a árvore já tem
// (o mesmo nome) fica. Devolve o nome do modelo (em português, como os campos do modelo citam) => id. Sem escopo
function secoes_do_modelo($raiz, $tr)
{
    $res = [];
    foreach (modelo_ler()["secoes"] as $x) {
        $id = valor("SELECT id FROM campo_secao WHERE no_id = ? AND nome = ?", [(int)$raiz, $tr($x["nome"])]);
        if ($id === null) {
            sql("INSERT INTO campo_secao (no_id, nome, ordem) VALUES (?, ?, ?)", [(int)$raiz, $tr($x["nome"]), (int)$x["ordem"]]);
            $id = ultimo_id();
        }
        $res[$x["nome"]] = (int)$id;
    }
    return $res;
}

// Um campo do modelo (modelo.json, campos) numa árvore, com o nome e os valores possíveis no idioma ($tr): o grupo ($no;
// null: a raiz), a divisória ($secao_id) e a ordem; as regras do valor (minimo, maximo, multiplo), de quem ele é (alvo:
// relogio, acessorio, winder ou posicao) e, no do acessório, o tipo de acessório (o serve, o campo do relógio com que ele
// bate, entra depois: arvore_criar). Devolve o id. Sem escopo
function campo_do_modelo($c, $no, $secao_id, $ordem, $tr)
{
    $unidade = (string)($c["unidade"] ?? "");
    sql("INSERT INTO campo (identificador, nome, tipo, unidade, padrao, no_id, ordem, so_desejado, calibre, secao_id, minimo, maximo, multiplo, alvo, acessorio_tipo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$c["identificador"], $tr($c["nome"]), $c["tipo"], $unidade, $c["padrao"] ?? null, $no, $ordem, (int)($c["so_desejado"] ?? 0), (int)($c["calibre"] ?? 0), $secao_id,
            $c["minimo"] ?? null, $c["maximo"] ?? null, (int)($c["multiplo"] ?? 0), $c["alvo"] ?? "relogio", $c["acessorio_tipo"] ?? null]);
    $cid = ultimo_id();
    foreach (array_values($c["opcoes"] ?? []) as $i => $o) {
        sql("INSERT INTO campo_opcao (campo_id, ordem, valor) VALUES (?, ?, ?)", [$cid, $i + 1, $tr($o)]);
    }
    return (int)$cid;
}

// O tradutor dos textos do modelo para um idioma
function modelo_tradutor($lingua)
{
    $dic = $lingua !== "pt-BR" ? textos_parte($lingua, "cadastro") : [];
    return function ($s) use ($dic) {
        return $s !== null && isset($dic[$s]) && $dic[$s] !== "" ? $dic[$s] : $s;
    };
}

// Os textos do modelo que entram no idioma de quem cria a coleção (os nomes dos grupos, das unidades, das divisórias, dos
// campos e das opções, dos tipos, das fórmulas, dos avisos e os textos deles, dos critérios e das faixas, das categorias,
// dos modos e dos blocos, dos totais e os textos entre aspas das expressões deles, e as mensagens padrão dos destinos e das
// agendas): a parte "cadastro" dos arquivos dos idiomas traduz cada um (o testes/textos.php confere)
function modelo_textos()
{
    $m = modelo_ler();
    $res = [];
    $junta = function ($v) use (&$res) {
        if (is_string($v) && $v !== "") {
            $res[$v] = true;
        }
    };
    foreach ($m["grupos"] as $g) {
        $junta($g["nome"]);
    }
    foreach ($m["unidades"] as $u) {
        $junta($u["nome"]);
    }
    foreach ($m["secoes"] as $x) {
        $junta($x["nome"]);
    }
    foreach ($m["campos"] as $c) {
        $junta($c["nome"]);
        foreach ($c["opcoes"] ?? [] as $o) {
            $junta($o);
        }
    }
    foreach ($m["totais"] as $x) {
        $junta($x["nome"]);
        preg_match_all('/"([^"]*)"/', $x["expressao"], $aspas);
        foreach ($aspas[1] as $a) {
            $junta($a);
        }
    }
    foreach (["tipos", "formulas", "categorias"] as $k) {
        foreach ($m[$k] as $x) {
            $junta($x["nome"]);
        }
    }
    foreach ($m["avisos"] as $a) {
        $junta($a["nome"]);
        $junta($a["texto"]);
    }
    foreach ($m["criterios"] as $p) {
        $junta($p["nome"]);
        foreach ($p["subparametros"] as $sb) {
            $junta($sb["nome"]);
            foreach ($sb["faixas"] as $f) {
                $junta($f["categoria"] ?? null);
            }
        }
    }
    foreach ($m["modos"] as $mo) {
        $junta($mo["nome"]);
        foreach ($mo["blocos"] as $b) {
            $junta($b["nome"]);
        }
    }
    // as listas que são cadastro (o texto do serviço é o identificador de um campo: esse não se traduz) e a oficina inicial
    foreach ($m["listas"] as $lista => $itens) {
        foreach ($itens as $x) {
            $junta($x["nome"]);
            if ($lista !== "servico_tipo") {
                $junta($x["texto"] ?? null);
            }
        }
    }
    foreach ($m["oficinas"] as $o) {
        $junta($o["nome"]);
        $junta($o["observacao"] ?? null);
    }
    foreach (["mensagens", "agenda"] as $k) {
        $junta($m[$k]["modelo"]);
        foreach ($m[$k]["avisos"] as $a) {
            $junta($a["modelo"] ?? null);
        }
    }
    return array_keys($res);
}

// Uma árvore nova de um dono: a raiz (com o nome) e, com o modelo, os grupos e o cadastro dele (unidades, campos, tipos,
// fórmulas, avisos, critérios dos grupos, categorias de documento). Devolve [raiz, apelido => id]. Sem escopo
function arvore_criar($dono_id, $nome, $lingua, $com_modelo)
{
    return sem_escopo(function () use ($dono_id, $nome, $lingua, $com_modelo) {
        sql("INSERT INTO no (pai_id, dono_id, nome, ordem) VALUES (NULL, ?, ?, 0)", [(int)$dono_id, corta_texto($nome, 80)]);
        $raiz = ultimo_id();
        $ids = ["raiz" => $raiz];
        // as listas (a situação na coleção, os tipos, as situações da manutenção...) entram sempre, com ou sem o modelo: o
        // sistema precisa delas para funcionar
        listas_do_modelo($raiz, modelo_tradutor($lingua));
        if (!$com_modelo) {
            return [$raiz, $ids];
        }
        $m = modelo_ler();
        $tr = modelo_tradutor($lingua);
        // os grupos: o pai antes do filho
        $faltam = $m["grupos"];
        while (count($faltam) > 0) {
            $resto = [];
            foreach ($faltam as $g) {
                if (!isset($ids[$g["pai"]])) {
                    $resto[] = $g;
                    continue;
                }
                sql("INSERT INTO no (pai_id, dono_id, nome, ordem) VALUES (?, NULL, ?, ?)", [$ids[$g["pai"]], $tr($g["nome"]), (int)$g["ordem"]]);
                $ids[$g["apelido"]] = ultimo_id();
            }
            if (count($resto) === count($faltam)) {
                throw new RuntimeException(t("modelo.json: grupo com um pai que não existe: {1}", [1 => $resto[0]["apelido"]]));
            }
            $faltam = $resto;
        }
        $grupo = function ($a) use ($ids) {
            if (!isset($ids[$a ?? "raiz"])) {
                throw new RuntimeException(t("modelo.json: o grupo {1} não existe", [1 => (string)$a]));
            }
            return $ids[$a ?? "raiz"];
        };
        unidades_do_modelo($raiz, $tr);
        $secoes = secoes_do_modelo($raiz, $tr);
        $campo_ids = [];
        foreach ($m["campos"] as $c) {
            $campo_ids[$c["identificador"]] = campo_do_modelo($c, $grupo($c["grupo"]), ($c["secao"] ?? null) !== null ? ($secoes[$c["secao"]] ?? null) : null, (int)$c["ordem"], $tr);
        }
        // o campo do acessório que tem de bater com um do relógio (a largura da pulseira com o entre alças), depois de todos
        foreach ($m["campos"] as $c) {
            if (($c["serve"] ?? null) !== null && isset($campo_ids[$c["serve"]])) {
                sql("UPDATE campo SET serve_campo_id = ? WHERE id = ?", [$campo_ids[$c["serve"]], $campo_ids[$c["identificador"]]]);
            }
        }
        $tipos = [];
        foreach ($m["tipos"] as $x) {
            sql("INSERT INTO lancamento_tipo (identificador, nome, formato, unidade, fecha_as, no_id, ordem, exclusiva, mede_gasto, marcha, winder, condicao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$x["identificador"], $tr($x["nome"]), $x["formato"], $x["unidade"], $x["fecha_as"], $grupo($x["grupo"]), (int)$x["ordem"], (int)$x["exclusiva"], (int)$x["mede_gasto"], $x["marcha"] ?? null,
                    (int)($x["winder"] ?? 0), $x["condicao"]]);
            $tipos[$x["identificador"]] = ultimo_id();
        }
        foreach ($m["formulas"] as $f) {
            sql("INSERT INTO formula (identificador, nome, expressao, unidade, no_id) VALUES (?, ?, ?, ?, ?)", [$f["identificador"], $tr($f["nome"]), $f["expressao"], $f["unidade"], $grupo($f["grupo"])]);
        }
        totais_do_modelo($raiz, $tr);
        foreach ($m["avisos"] as $a) {
            sql("INSERT INTO aviso (identificador, nome, expressao, antecedencia_dias, texto, ativo, no_id, escala, simula_valor, simula_horas, agenda, condicao, resolve_tipo_id, na_hora)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$a["identificador"], $tr($a["nome"]), $a["expressao"], $a["antecedencia_dias"], $tr($a["texto"]), (int)$a["ativo"],
                $grupo($a["grupo"]), $a["escala"], $a["simula_valor"], $a["simula_horas"], $a["agenda"], $a["condicao"], $a["resolve"] !== null ? ($tipos[$a["resolve"]] ?? null) : null, (int)$a["na_hora"]]);
        }
        modelo_criterios($grupo, $tr);
        foreach ($m["categorias"] as $c) {
            sql("INSERT INTO documento_categoria (identificador, nome, ordem, no_id) VALUES (?, ?, ?, ?)", [$c["identificador"], $tr($c["nome"]), (int)$c["ordem"], $grupo($c["grupo"])]);
            $cid = ultimo_id();
            foreach ($c["aceita"] as $fam) {
                sql("INSERT INTO documento_categoria_aceita (categoria_id, familia) VALUES (?, ?)", [$cid, $fam]);
            }
        }
        return [$raiz, $ids];
    });
}

// As listas que são cadastro (lista_item: a situação do relógio na coleção, os tipos de acessório e de serviço, a
// estanqueidade, a reserva, a manutenção, as oficinas e os contatos), do modelo.json, numa árvore: cada item só se a lista
// dela ainda não tem esse identificador (a migração v48 pode rodar de novo). O nome sai no idioma ($tr); o texto também,
// menos o do tipo de serviço (o identificador do tipo de lançamento que ele marca). Sem escopo
function listas_do_modelo($raiz, $tr)
{
    sem_escopo(function () use ($raiz, $tr) {
        foreach (modelo_ler()["listas"] as $lista => $itens) {
            foreach (array_values($itens) as $i => $x) {
                if (valor("SELECT id FROM lista_item WHERE no_id = ? AND lista = ? AND identificador = ?", [(int)$raiz, $lista, $x["identificador"]]) === null) {
                    $texto = $x["texto"] ?? null;
                    sql("INSERT INTO lista_item (no_id, lista, identificador, nome, ordem, comportamento, texto, cor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [(int)$raiz, $lista, $x["identificador"], $tr($x["nome"]), $i + 1, $x["comportamento"] ?? null,
                            $texto === null || $lista === "servico_tipo" ? $texto : $tr($texto), $x["cor"] ?? null]);
                }
            }
        }
    });
}

// As oficinas do modelo.json (a "Eu mesmo (em casa)") numa coleção: o tipo pelo identificador, na lista da árvore dela; só
// a que a coleção ainda não tem pelo nome. Sem escopo
function oficinas_do_modelo($colecao_id, $raiz, $tr)
{
    sem_escopo(function () use ($colecao_id, $raiz, $tr) {
        foreach (modelo_ler()["oficinas"] as $o) {
            $tipo = valor("SELECT id FROM lista_item WHERE no_id = ? AND lista = 'oficina_tipo' AND identificador = ?", [(int)$raiz, $o["tipo"]]);
            if ($tipo !== null && valor("SELECT id FROM oficina WHERE colecao_id = ? AND nome = ?", [(int)$colecao_id, $tr($o["nome"])]) === null) {
                sql("INSERT INTO oficina (colecao_id, nome, tipo_id, observacao, criado) VALUES (?, ?, ?, ?, NOW())",
                    [(int)$colecao_id, $tr($o["nome"]), (int)$tipo, isset($o["observacao"]) ? $tr($o["observacao"]) : null]);
            }
        }
    });
}

// Os critérios do modelo (os dos grupos), na árvore: $grupo dá o id de um apelido
function modelo_criterios($grupo, $tr)
{
    foreach (modelo_ler()["criterios"] as $p) {
        sql("INSERT INTO criterio_parametro (escopo_no_id, escopo_relogio_id, nome, peso, ordem) VALUES (?, NULL, ?, ?, ?)", [$grupo($p["grupo"]), $tr($p["nome"]), $p["peso"], (int)$p["ordem"]]);
        $pid = ultimo_id();
        foreach ($p["subparametros"] as $s) {
            sql("INSERT INTO criterio_sub (parametro_id, nome, variavel, peso, ordem) VALUES (?, ?, ?, ?, ?)", [$pid, $tr($s["nome"]), $s["variavel"], $s["peso"], (int)$s["ordem"]]);
            $sid = ultimo_id();
            foreach ($s["faixas"] as $f) {
                sql("INSERT INTO criterio_faixa (sub_id, de, ate, categoria, nota) VALUES (?, ?, ?, ?, ?)",
                    [$sid, $f["de"] ?? null, $f["ate"] ?? null, isset($f["categoria"]) ? $tr($f["categoria"]) : null, $f["nota"]]);
            }
        }
    }
}

// Uma coleção nova de um dono, numa árvore dele ($ids: os apelidos dos grupos do modelo, para os modos; vazio: sem os modos
// do modelo). A configuração inicial e o idioma. Devolve o id. Sem escopo
function colecao_criar($dono_id, $nome, $raiz, $ids, $lingua)
{
    return sem_escopo(function () use ($dono_id, $nome, $raiz, $ids, $lingua) {
        sql("INSERT INTO colecao (dono_id, no_id, nome, criada) VALUES (?, ?, ?, NOW())", [(int)$dono_id, (int)$raiz, corta_texto($nome, 120)]);
        $c = ultimo_id();
        $m = modelo_ler();
        $tr = modelo_tradutor($lingua);
        foreach ($m["config"] as $k => $v) {
            sql("INSERT INTO config (colecao_id, chave, valor) VALUES (?, ?, ?)", [$c, $k, (string)$v]);
        }
        sql("INSERT INTO config (colecao_id, chave, valor) VALUES (?, 'idioma', ?)", [$c, $lingua]);
        oficinas_do_modelo($c, $raiz, $tr);
        if (count($ids) > 1) {
            foreach ($m["modos"] as $mo) {
                sql("INSERT INTO modo (colecao_id, nome, tipo, selecao, ordem, escala_dias, semanas, ciclo, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$c, $tr($mo["nome"]), $mo["tipo"] ?? "personalizado", $mo["selecao"], (int)$mo["ordem"], $mo["escala_dias"], (int)($mo["semanas"] ?? 1), (int)$mo["ciclo"], $mo["ativo"] ? 1 : null]);
                $mid = ultimo_id();
                foreach ($mo["blocos"] as $b) {
                    sql("INSERT INTO modo_bloco (modo_id, nome, no_id, um_por, ordem) VALUES (?, ?, ?, ?, ?)", [$mid, $tr($b["nome"]), $ids[$b["grupo"]] ?? $raiz, $b["um_por"], (int)$b["ordem"]]);
                    $bid = ultimo_id();
                    foreach ($b["dias"] as $dia) {
                        sql("INSERT INTO modo_bloco_dia (bloco_id, dia) VALUES (?, ?)", [$bid, (int)$dia]);
                    }
                }
            }
        } else {
            // sem o modelo: um modo só, para o rodízio ter o que fazer (um relógio por dia, de todos)
            sql("INSERT INTO modo (colecao_id, nome, tipo, selecao, ordem, escala_dias, semanas, ciclo, ativo) VALUES (?, ?, 'dia_semana', 'ponderado', 1, NULL, 1, 0, 1)", [$c, t("Um por dia")]);
            $mid = ultimo_id();
            sql("INSERT INTO modo_bloco (modo_id, nome, no_id, um_por, ordem) VALUES (?, ?, ?, 'dia', 1)", [$mid, t("Todo dia"), (int)$raiz]);
            $bid = ultimo_id();
            for ($dia = 1; $dia <= 7; $dia++) {
                sql("INSERT INTO modo_bloco_dia (bloco_id, dia) VALUES (?, ?)", [$bid, $dia]);
            }
        }
        return $c;
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// A migração v25: de uma pessoa só para várias. O banco antigo é copiado inteiro para um arquivo (o formato da cópia de
// segurança, com o conteúdo dos documentos), as tabelas antigas saem, a estrutura nova entra (o schema.sql) e os registros
// voltam do arquivo, cada um na forma nova, com os mesmos códigos (os relógios, os documentos e os lançamentos continuam
// com o id de sempre). O usuário mais antigo vira o master, o dono da coleção e da árvore; os outros, membros da coleção.
// O Telegram (MSG_ENDPOINT) vira o primeiro destino webhook da coleção, e o Google Agenda, a primeira agenda.
// Se parar no meio, a próxima tentativa refaz a volta a partir do mesmo arquivo. Na instalação nova ($nova), só a marca.
// ---------------------------------------------------------------------------------------------------------------------
function migracao_v25_arquivo()
{
    foreach ([backup_pasta(), documentos_pasta(), sys_get_temp_dir()] as $p) {
        if ($p !== null && is_dir($p) && is_writable($p)) {
            return rtrim($p, "/\\") . "/relojoeiro-antes-da-v25.jsonl.gz";
        }
    }
    throw new RuntimeException(t("a migração v25 precisa de uma pasta com escrita para a cópia do banco antigo (BACKUP_PASTA, DOCUMENTOS_PASTA ou a pasta temporária do PHP)"));
}

function migracao_v25($nova = false)
{
    if (banco_tem_tabela("sistema") && (int)sem_escopo(function () { return valor("SELECT COUNT(*) FROM sistema WHERE chave = 'migracao_v25'"); }) > 0) {
        return;
    }
    banco_escopo(null);
    if ($nova) {
        sql("INSERT INTO sistema (chave, valor) VALUES ('migracao_v25', '1')");
        return;
    }
    $arquivo = migracao_v25_arquivo();
    if (!banco_tem_tabela("colecao")) {
        // o banco antigo: a cópia inteira, e as tabelas antigas saem (a citada depois da que cita)
        $copia = banco_despejar($arquivo . ".parcial", true);
        rename($arquivo . ".parcial", $arquivo);
        $drop = "";
        foreach (array_reverse(array_column(backup_tabelas(), 0)) as $t) {
            $drop .= "DROP TABLE " . $t . ";\n";
        }
        banco_script($drop);
        banco_script((string)file_get_contents(__DIR__ . "/schema.sql"));
    } elseif (!is_file($arquivo)) {
        throw new RuntimeException(t("a migração v25 parou no meio e a cópia do banco antigo ({1}) não está mais lá", [1 => $arquivo]));
    } else {
        // a tentativa anterior parou no meio: os registros que entraram saem, e a volta recomeça
        foreach (array_reverse(array_column(backup_tabelas(), 0)) as $t) {
            sql("DELETE FROM " . $t);
        }
    }
    $n = migracao_v25_voltar($arquivo);
    banco_ajustar_ids();
    sql("INSERT INTO sistema (chave, valor) VALUES ('migracao_v25', '1')");
    return $n;
}

// Lê a cópia de segurança (o formato do backup_fazer): chama $f(tabela, colunas, valores) para cada registro
function banco_ler_copia($arquivo, $f)
{
    $gz = gzopen($arquivo, "rb");
    if (!$gz) {
        throw new RuntimeException(t("não consegui abrir {1}", [1 => $arquivo]));
    }
    gzgets($gz, 1 << 20);
    $tabela = null;
    $colunas = [];
    $binarias = [];
    while (($l = gzgets($gz, 64 << 20)) !== false) {
        $x = json_decode($l, true);
        if (is_array($x) && isset($x["tabela"])) {
            $tabela = $x["tabela"];
            $colunas = $x["colunas"];
            $binarias = $x["binarias"];
        } elseif (is_array($x) && $tabela !== null) {
            $reg = [];
            foreach ($colunas as $i => $c) {
                $v = $x[$i] ?? null;
                $reg[$c] = $v !== null && in_array($c, $binarias, true) ? base64_decode((string)$v) : $v;
            }
            if ($f($tabela, $reg) === false) {
                break;
            }
        }
    }
    gzclose($gz);
}

// A volta dos registros na forma nova. Devolve quantos entraram
function migracao_v25_voltar($arquivo)
{
    // as colunas de cada tabela nova
    $cols = [];
    $binarias = [];
    foreach (backup_tabelas() as $t) {
        $cols[$t[0]] = $t[1];
        $binarias[$t[0]] = $t[2];
    }
    $n = 0;
    $grava = function ($tabela, $reg) use ($cols, $binarias, &$n) {
        $c = array_values(array_intersect(array_keys($reg), $cols[$tabela]));
        $vals = array_map(function ($k) use ($reg, $binarias, $tabela) {
            $v = $reg[$k];
            // no PostgreSQL o binário vai em hexadecimal, também o que parece texto
            return $v !== null && banco_tipo() === "pgsql" && in_array($k, $binarias[$tabela], true) ? "\\x" . bin2hex((string)$v) : $v;
        }, $c);
        sql("INSERT INTO " . $tabela . " (" . implode(", ", $c) . ") VALUES (" . implode(", ", array_fill(0, count($c), "?")) . ")", $vals);
        $n++;
    };
    // a primeira leitura: o que decide o resto (os usuários, a árvore, a configuração, se há relógios)
    $usuarios = [];
    $nos = [];
    $config = [];
    $tem_relogio = false;
    banco_ler_copia($arquivo, function ($t, $r) use (&$usuarios, &$nos, &$config, &$tem_relogio) {
        if ($t === "usuario") {
            $usuarios[] = $r;
        } elseif ($t === "no") {
            $nos[] = $r;
        } elseif ($t === "config") {
            $config[$r["chave"]] = (string)$r["valor"];
        } elseif ($t === "relogio") {
            $tem_relogio = true;
        }
    });
    usort($usuarios, function ($a, $b) { return (int)$a["id"] <=> (int)$b["id"]; });
    banco_inicio();
    try {
        foreach (SISTEMA_CHAVES as $k) {
            if (isset($config[$k])) {
                sql("INSERT INTO sistema (chave, valor) VALUES (?, ?)", [$k, $config[$k]]);
            }
        }
        if (count($usuarios) === 0 && !$tem_relogio) {
            // instalado e nunca usado: fica vazio; o primeiro usuário ganha a coleção com o modelo
            banco_confirma();
            return $n;
        }
        if (count($usuarios) === 0) {
            // relógios sem nenhum usuário (usado só pelo token): um master sem senha, para o token dar a senha depois
            $usuarios[] = ["id" => 1, "login" => "admin", "senha_hash" => "*", "criado" => date("Y-m-d H:i:s")];
        }
        foreach ($usuarios as $i => $u) {
            sql("INSERT INTO usuario (id, login, senha_hash, papel, criado) VALUES (?, ?, ?, ?, ?)", [(int)$u["id"], $u["login"], $u["senha_hash"], $i === 0 ? "master" : "comum", $u["criado"]]);
        }
        $dono = (int)$usuarios[0]["id"];
        $lingua = lingua_valida($config["idioma"] ?? "") ?? "pt-BR";
        $lingua_antes = lingua_atual();
        lingua_definir($lingua);
        // a raiz da árvore (um código depois do maior grupo) e os grupos de antes, o pai antes do filho
        $raiz = 1 + array_reduce($nos, function ($m, $x) { return max($m, (int)$x["id"]); }, 0);
        sql("INSERT INTO no (id, pai_id, dono_id, nome, ordem) VALUES (?, NULL, ?, ?, 0)", [$raiz, $dono, t("Grupos de {1}", [1 => $usuarios[0]["login"]])]);
        $feitos = [$raiz => true];
        while (count($nos) > 0) {
            $resto = [];
            foreach ($nos as $x) {
                $pai = $x["pai_id"] === null ? $raiz : (int)$x["pai_id"];
                if (!isset($feitos[$pai])) {
                    $resto[] = $x;
                    continue;
                }
                sql("INSERT INTO no (id, pai_id, dono_id, nome, ordem) VALUES (?, ?, NULL, ?, ?)", [(int)$x["id"], $pai, $x["nome"], (int)$x["ordem"]]);
                $feitos[(int)$x["id"]] = true;
            }
            if (count($resto) === count($nos)) {
                throw new RuntimeException(t("a árvore antiga tem grupos sem o pai"));
            }
            $nos = $resto;
        }
        sql("INSERT INTO colecao (id, dono_id, no_id, nome, criada) VALUES (1, ?, ?, ?, NOW())", [$dono, $raiz, t("Coleção de {1}", [1 => $usuarios[0]["login"]])]);
        foreach (array_slice($usuarios, 1) as $u) {
            sql("INSERT INTO colecao_usuario (colecao_id, usuario_id, desde) VALUES (1, ?, NOW())", [(int)$u["id"]]);
        }
        // a configuração da coleção: o que não é do sistema, nem do Telegram e da agenda (que viram o destino e a agenda),
        // nem marca de migração
        foreach ($config as $k => $v) {
            if (in_array($k, SISTEMA_CHAVES, true) || in_array($k, ["mensagens_ativas", "modo_ativo", "alerta_tipos", "agenda_tipos", "tg_padrao", "ag_padrao"], true)
                || preg_match("/^(migracao_v\\d+|agenda_.*|tg_.*)\$/", $k) === 1) {
                continue;
            }
            sql("INSERT INTO config (colecao_id, chave, valor) VALUES (1, ?, ?)", [$k, $v]);
        }
        // o Telegram: o webhook com o endereço do config.php, como ia antes (GET, destinatario, titulo e mensagem na URL)
        $destino = null;
        /** @var mixed $endpoint */
        $endpoint = defined("MSG_ENDPOINT") ? constant("MSG_ENDPOINT") : "";
        if (is_string($endpoint) && preg_match("#^https?://#i", $endpoint) === 1) {
            sql("INSERT INTO destino (colecao_id, nome, tipo, ativo, modelo, idioma, criado) VALUES (1, 'Telegram', 'webhook', ?, ?, NULL, NOW())",
                [($config["mensagens_ativas"] ?? "1") === "1" ? 1 : 0, $config["tg_padrao"] ?? "{acao}: {relogio}\n{motivo}"]);
            $destino = ultimo_id();
            sql("INSERT INTO destino_webhook (destino_id, metodo, url, formato, corpo_json) VALUES (?, 'GET', ?, 'query', NULL)", [$destino, $endpoint]);
            $campos = ["destinatario" => defined("MSG_DESTINATARIO") ? (string)constant("MSG_DESTINATARIO") : "", "titulo" => "{titulo}", "mensagem" => "{mensagem}"];
            $i = 0;
            foreach ($campos as $k => $v) {
                sql("INSERT INTO destino_webhook_campo (destino_id, nome, valor, ordem) VALUES (?, ?, ?, ?)", [$destino, $k, $v, ++$i]);
            }
        }
        // o Google Agenda
        $agenda = null;
        if (trim($config["agenda_id"] ?? "") !== "" || trim($config["agenda_chave"] ?? "") !== "") {
            $ant = max(1, min(365, (int)($config["agenda_antecedencia"] ?? 30)));
            sql("INSERT INTO agenda (colecao_id, nome, tipo, ativa, janela_dias, modelo, idioma, teste_id, criado) VALUES (1, 'Google', 'google', ?, ?, ?, NULL, ?, NOW())",
                [($config["agenda_ativa"] ?? "0") === "1" ? 1 : 0, $ant, $config["ag_padrao"] ?? "{acao}: {relogio}\n{motivo}", ($config["agenda_teste_id"] ?? "") !== "" ? $config["agenda_teste_id"] : null]);
            $agenda = ultimo_id();
            sql("INSERT INTO agenda_google (agenda_id, calendario_id, chave_json_cifrada) VALUES (?, ?, ?)", [$agenda, trim($config["agenda_id"] ?? ""), segredo_cifrar($config["agenda_chave"] ?? "")]);
        }
        // a segunda leitura: o resto, tabela por tabela, na ordem da cópia (a citada antes da que cita)
        $ja = ["usuario", "no", "config"];
        banco_ler_copia($arquivo, function ($t, $r) use ($ja, $grava, $raiz, $destino, $agenda, $cols) {
            if (in_array($t, $ja, true)) {
                return;
            }
            $na_raiz = function ($v) use ($raiz) { return $v === null ? $raiz : (int)$v; };
            switch ($t) {
                case "relogio":
                    $r["colecao_id"] = 1;
                    $r["no_id"] = $na_raiz($r["no_id"]);
                    break;
                case "campo":
                case "lancamento_tipo":
                case "formula":
                case "aviso":
                    $r["no_id"] = $na_raiz($r["no_id"]);
                    break;
                case "documento_categoria":
                    $r["no_id"] = $raiz;
                    break;
                case "criterio_parametro":
                    if ($r["escopo_no_id"] === null && $r["escopo_relogio_id"] === null) {
                        $r["escopo_no_id"] = $raiz;
                    }
                    break;
                case "modo":
                case "plano_livre":
                    $r["colecao_id"] = 1;
                    break;
                case "modo_bloco":
                    $r["no_id"] = $na_raiz($r["no_id"]);
                    $grava($t, $r);
                    if (($r["relogio_id"] ?? null) !== null) {
                        $grava("modo_bloco_relogio", ["bloco_id" => $r["id"], "relogio_id" => $r["relogio_id"]]);
                    }
                    return;
                case "plano":
                    $r["colecao_id"] = 1;
                    $grava($t, $r);
                    if (($r["bloco_id"] ?? null) !== null) {
                        $grava("plano_bloco", ["colecao_id" => 1, "data" => $r["data"], "bloco_id" => $r["bloco_id"]]);
                    }
                    return;
                case "evento_personalizado":
                case "acessorio":
                    $r["colecao_id"] = 1;
                    $grava($t, $r);
                    if (($r["relogio_id"] ?? null) !== null) {
                        $grava($t === "acessorio" ? "acessorio_relogio" : "evento_relogio", [$t === "acessorio" ? "acessorio_id" : "evento_id" => $r["id"], "relogio_id" => $r["relogio_id"]]);
                    }
                    return;
                case "canal_aviso":
                    // o que ia pelo Telegram e pela agenda: agora, pelo destino e pela agenda
                    $alvo = $r["canal"] === "tg" ? $destino : $agenda;
                    if ($alvo === null) {
                        return;
                    }
                    $pre = $r["canal"] === "tg" ? "destino" : "agenda";
                    $proprio = (int)$r["propria"] === 1 && trim((string)$r["corpo"]) !== "" ? $r["corpo"] : null;
                    if ($r["evento_id"] !== null) {
                        $grava($pre . "_evento", $pre === "destino" ? ["destino_id" => $alvo, "evento_id" => $r["evento_id"], "envia" => (int)$r["envia"], "modelo_proprio" => $proprio]
                            : ["agenda_id" => $alvo, "evento_id" => $r["evento_id"], "envia" => (int)$r["envia"]]);
                    } else {
                        $grava($pre . "_aviso", [$pre . "_id" => $alvo, "tipo_aviso" => $r["tipo"], "envia" => (int)$r["envia"], "modelo_proprio" => $proprio]);
                    }
                    return;
                case "agenda_evento":
                    // os eventos que a sincronização do Google já criou
                    if ($agenda !== null && isset($r["google_id"])) {
                        $grava("agenda_sincronizado", ["agenda_id" => $agenda, "chave" => $r["chave"], "externo_id" => $r["google_id"], "data" => $r["data"], "titulo" => $r["titulo"],
                            "assinatura" => $r["assinatura"], "criado" => $r["criado"]]);
                    }
                    return;
            }
            if (!isset($cols[$t])) {
                // uma tabela que não existe mais (a manual, da v13)
                return;
            }
            $grava($t, $r);
        });
        banco_confirma();
        lingua_definir($lingua_antes);
    } catch (Throwable $e) {
        banco_desfaz();
        throw $e;
    }
    return $n;
}

// ---------------------------------------------------------------------------------------------------------------------
// Quem pede e em que coleção
// ---------------------------------------------------------------------------------------------------------------------

// O usuário do pedido (a linha da tabela usuario), ou null: o do login (HTTP Basic), o do token pessoal, ou, com o
// API_TOKEN do config.php, o master mais antigo (o token do servidor vale como o master)
function usuario_atual()
{
    return $GLOBALS["USUARIO"] ?? null;
}

function usuario_definir($u)
{
    $GLOBALS["USUARIO"] = $u;
}

function usuario_master()
{
    $u = usuario_atual();
    return $u !== null && $u["papel"] === "master";
}

// As coleções que um usuário vê: as dele, as de que é membro e as dos donos que abriram tudo para ele (o master vê todas).
// Cada uma: {id, nome, dono_id, dono, papel (dono, membro ou master), no_id}. As dele primeiro, depois pelo nome
function colecoes_visiveis($u)
{
    if ($u === null) {
        return [];
    }
    return sem_escopo(function () use ($u) {
        $id = (int)$u["id"];
        $todas = linhas("SELECT c.id, c.nome, c.dono_id, c.no_id, u.login AS dono,
            CASE WHEN c.dono_id = ? THEN 'dono' WHEN EXISTS (SELECT 1 FROM colecao_usuario m WHERE m.colecao_id = c.id AND m.usuario_id = ?)
                OR EXISTS (SELECT 1 FROM acesso_dono a WHERE a.dono_id = c.dono_id AND a.usuario_id = ?) THEN 'membro' ELSE 'master' END AS papel
            FROM colecao c JOIN usuario u ON u.id = c.dono_id ORDER BY CASE WHEN c.dono_id = ? THEN 0 ELSE 1 END, c.nome, c.id", [$id, $id, $id, $id]);
        $res = [];
        foreach ($todas as $c) {
            // o texto de um CASE vem com espaços no fim no Firebird (o tipo é o do maior: CHAR(6))
            $c["papel"] = rtrim((string)$c["papel"]);
            if ($c["papel"] !== "master" || $u["papel"] === "master") {
                $res[] = ["id" => (int)$c["id"], "nome" => $c["nome"], "dono_id" => (int)$c["dono_id"], "dono" => $c["dono"], "papel" => $c["papel"], "no_id" => (int)$c["no_id"]];
            }
        }
        return $res;
    });
}

// A coleção do pedido: o parâmetro colecao=, senão o cookie colecao (o seletor do menu), senão a primeira que ele vê (a
// dele). Um parâmetro de uma coleção que ele não vê: false (a API responde 404, sem dizer se ela existe). Nenhuma: null
function colecao_escolher($u, $pedida, $cookie)
{
    $vis = colecoes_visiveis($u);
    $ids = array_column($vis, "id");
    if ($pedida !== null && $pedida !== "") {
        return in_array((int)$pedida, $ids, true) ? (int)$pedida : false;
    }
    if ($cookie !== null && $cookie !== "" && in_array((int)$cookie, $ids, true)) {
        return (int)$cookie;
    }
    // o master sem coleção própria nem escolhida: a primeira em que ele é dono ou membro, senão a primeira de todas
    foreach ($vis as $c) {
        if ($c["papel"] !== "master") {
            return $c["id"];
        }
    }
    return count($vis) > 0 ? $vis[0]["id"] : null;
}

// O papel do usuário do pedido na coleção do pedido: dono, membro ou master (null: sem coleção)
function papel_na_colecao()
{
    $c = colecao_atual();
    foreach (colecoes_visiveis(usuario_atual()) as $x) {
        if ($x["id"] === $c) {
            return $x["papel"];
        }
    }
    return null;
}

// Cria uma conta: o usuário e, se pedido, a coleção dele ("modelo": com o conjunto inicial do modelo.json; "vazia": só a raiz
// da árvore e um modo de um relógio por dia; "nenhuma": sem coleção, a conta que só pede acesso). O primeiro usuário do
// sistema é o master. Sem escopo. Devolve {usuario_id, colecao_id}
function conta_criar($login, $hash, $email, $expira_em, $lingua, $opcao)
{
    return sem_escopo(function () use ($login, $hash, $email, $expira_em, $lingua, $opcao) {
        $primeiro = (int)valor("SELECT COUNT(*) FROM usuario") === 0;
        sql("INSERT INTO usuario (login, email, senha_hash, papel, idioma, expira_em, bloqueado, criado) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())",
            [$login, $email !== null && $email !== "" ? $email : null, $hash, $primeiro ? "master" : "comum", $lingua, $primeiro ? null : $expira_em]);
        $uid = ultimo_id();
        $cid = null;
        if ($primeiro || $opcao === "modelo" || $opcao === "vazia") {
            $antes = lingua_atual();
            lingua_definir($lingua);
            [$raiz, $ids] = arvore_criar($uid, t("Grupos de {1}", [1 => $login]), $lingua, $opcao !== "vazia");
            $cid = colecao_criar($uid, t("Coleção de {1}", [1 => $login]), $raiz, $ids, $lingua);
            lingua_definir($antes);
        }
        return ["usuario_id" => $uid, "colecao_id" => $cid];
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// Excluir: o banco apaga em cascata; os arquivos dos documentos (a pasta de cada relógio, em DOCUMENTOS_PASTA) saem junto,
// na hora (o banco não apaga arquivo; a limpeza do cron fica de rede de segurança)
// ---------------------------------------------------------------------------------------------------------------------

// As pastas dos documentos de relógios que saíram
function relogios_apagar_arquivos($ids)
{
    $pasta = documentos_pasta();
    $n = 0;
    if ($pasta === null) {
        return 0;
    }
    foreach ($ids as $id) {
        if (is_dir($pasta . "/r" . (int)$id)) {
            $n += apagar_pasta($pasta . "/r" . (int)$id);
        }
    }
    return $n;
}

// As pastas dos documentos de calibres que saíram (c<id>)
function calibres_apagar_arquivos($ids)
{
    $pasta = documentos_pasta();
    $n = 0;
    if ($pasta === null) {
        return 0;
    }
    foreach ($ids as $id) {
        if (is_dir($pasta . "/c" . (int)$id)) {
            $n += apagar_pasta($pasta . "/c" . (int)$id);
        }
    }
    return $n;
}

// As pastas dos documentos de winders que saíram (w<id>)
function winders_apagar_arquivos($ids)
{
    $pasta = documentos_pasta();
    $n = 0;
    if ($pasta === null) {
        return 0;
    }
    foreach ($ids as $id) {
        if (is_dir($pasta . "/w" . (int)$id)) {
            $n += apagar_pasta($pasta . "/w" . (int)$id);
        }
    }
    return $n;
}

// Os winders de coleções (sem escopo)
function winders_das_colecoes($colecoes)
{
    if (count($colecoes) === 0) {
        return [];
    }
    return array_map("intval", array_column(sem_escopo(function () use ($colecoes) {
        return linhas("SELECT id FROM winder WHERE colecao_id IN (" . implode(", ", array_map("intval", $colecoes)) . ")");
    }), "id"));
}

// Os calibres das árvores (pelas raízes) de um dono, ou de uma árvore (sem escopo)
function calibres_das_arvores($raizes)
{
    if (count($raizes) === 0) {
        return [];
    }
    return array_map("intval", array_column(sem_escopo(function () use ($raizes) {
        return linhas("SELECT id FROM calibre WHERE no_id IN (" . implode(", ", array_map("intval", $raizes)) . ")");
    }), "id"));
}

// Os relógios de um conjunto de coleções (sem escopo)
function relogios_das_colecoes($colecoes)
{
    if (count($colecoes) === 0) {
        return [];
    }
    return array_map("intval", array_column(sem_escopo(function () use ($colecoes) {
        return linhas("SELECT id FROM relogio WHERE colecao_id IN (" . implode(", ", array_map("intval", $colecoes)) . ")");
    }), "id"));
}

// Exclui um usuário com tudo o que é dele: as coleções e as árvores (e, nelas, tudo), os acessos, os convites e os pedidos
function usuario_excluir($uid)
{
    $cols = array_map("intval", array_column(sem_escopo(function () use ($uid) { return linhas("SELECT id FROM colecao WHERE dono_id = ?", [(int)$uid]); }), "id"));
    $rel = relogios_das_colecoes($cols);
    $wnd = winders_das_colecoes($cols);
    $cal = calibres_das_arvores(array_column(sem_escopo(function () use ($uid) { return linhas("SELECT id FROM no WHERE pai_id IS NULL AND dono_id = ?", [(int)$uid]); }), "id"));
    sem_escopo(function () use ($uid) { sql("DELETE FROM usuario WHERE id = ?", [(int)$uid]); });
    return relogios_apagar_arquivos($rel) + calibres_apagar_arquivos($cal) + winders_apagar_arquivos($wnd);
}

// Exclui uma coleção com tudo o que é dela (a árvore fica: é do dono, e pode servir a outras coleções)
function colecao_excluir($cid)
{
    $rel = relogios_das_colecoes([(int)$cid]);
    $wnd = winders_das_colecoes([(int)$cid]);
    sem_escopo(function () use ($cid) { sql("DELETE FROM colecao WHERE id = ?", [(int)$cid]); });
    return relogios_apagar_arquivos($rel) + winders_apagar_arquivos($wnd);
}

// Os critérios do modelo.json na árvore da coleção do pedido (o "Restaurar os critérios iniciais"): cada grupo do modelo vai
// para o grupo da árvore com o mesmo caminho de nomes, no idioma $lingua (sem ele, para a raiz)
function criterios_restaurar($lingua)
{
    $m = modelo_ler();
    $tr = modelo_tradutor($lingua);
    $caminho = [];
    $por_apelido = [];
    foreach ($m["grupos"] as $g) {
        $por_apelido[$g["apelido"]] = $g;
    }
    $cam = function ($a) use (&$cam, $por_apelido, $tr) {
        $g = $por_apelido[$a] ?? null;
        return $g === null ? [] : array_merge($cam($g["pai"]), [mb_strtolower($tr($g["nome"]), "UTF-8")]);
    };
    // os caminhos dos grupos da árvore (a visão da coleção: a raiz não aparece, e os de cima têm o pai vazio)
    $nos = [];
    foreach (linhas("SELECT id, pai_id, nome FROM no") as $n) {
        $nos[(int)$n["id"]] = $n;
    }
    $da_arvore = function ($id) use (&$da_arvore, $nos) {
        $n = $nos[$id] ?? null;
        return $n === null ? [] : array_merge($n["pai_id"] === null ? [] : $da_arvore((int)$n["pai_id"]), [mb_strtolower($n["nome"], "UTF-8")]);
    };
    foreach (array_keys($nos) as $id) {
        $caminho[implode("/", $da_arvore($id))] = $id;
    }
    $raiz = no_raiz();
    $grupo = function ($a) use ($cam, $caminho, $raiz) {
        return $a === null || $a === "raiz" ? $raiz : ($caminho[implode("/", $cam($a))] ?? $raiz);
    };
    modelo_criterios($grupo, $tr);
}

// ---------------------------------------------------------------------------------------------------------------------
// A limpeza das contas (o cron, a cada minuto): a conta demo que vai vencer recebe o aviso por e-mail (7 dias e 1 dia antes,
// uma vez cada); a vencida sai com tudo (as coleções, as árvores, os arquivos); os convites, os pedidos e os links de senha
// vencidos saem; e a conta comum que ficou sem nada (sem coleção, sem acesso, sem pedido nem convite esperando) sai também.
// O master nunca sai sozinho. Devolve as linhas do registro do cron
// ---------------------------------------------------------------------------------------------------------------------
function contas_limpeza()
{
    $log = [];
    $agora = date("Y-m-d H:i:s");
    sem_escopo(function () use (&$log, $agora) {
        $avisados = json_decode(sis("demo_avisos"), true);
        $avisados = is_array($avisados) ? $avisados : [];
        foreach (linhas("SELECT id, login, email, idioma, expira_em FROM usuario WHERE papel = 'comum' AND expira_em IS NOT NULL ORDER BY id") as $u) {
            $falta = (strtotime((string)$u["expira_em"]) - time()) / 86400;
            $uid = (string)$u["id"];
            if ($falta <= 0) {
                $n = usuario_excluir((int)$u["id"]);
                unset($avisados[$uid]);
                $log[] = t("conta demo vencida: {1} saiu com tudo o que era dela{2}", [1 => $u["login"], 2 => $n > 0 ? t(" ({1} arquivos)", [1 => $n]) : ""]);
                continue;
            }
            foreach ([1, 7] as $dias) {
                if ($falta <= $dias && (int)($avisados[$uid] ?? 99) > $dias) {
                    $avisados[$uid] = $dias;
                    if ((string)$u["email"] !== "" && email_conta((int)$u["id"])["metodo"] !== null) {
                        $antes = lingua_atual();
                        lingua_definir(lingua_valida($u["idioma"]) ?? sis("idioma"));
                        [$ok, $erro] = email_enviar($u["email"], t("Relojoeiro: a sua conta de demonstração vence em {1}", [1 => duracao_texto($falta)]),
                            t("Olá, {1}. A sua conta de demonstração do Relojoeiro vence em {2} ({3}). Depois disso, ela e as suas coleções são apagadas. Para continuar, peça ao administrador para tornar a conta oficial.",
                                [1 => $u["login"], 2 => duracao_texto($falta), 3 => data_txt(strtotime((string)$u["expira_em"]), true)]), (int)$u["id"]);
                        lingua_definir($antes);
                        $log[] = $ok ? t("aviso de vencimento da demo para {1}", [1 => $u["login"]]) : registro_erro(t("erro no aviso de vencimento da demo para {1}: {2}", [1 => $u["login"], 2 => $erro]));
                    }
                    break;
                }
            }
        }
        sis_set("demo_avisos", json_encode($avisados));
        foreach (["convite", "pedido", "usuario_token"] as $t) {
            $n = (int)valor("SELECT COUNT(*) FROM " . $t . " WHERE expira < ?", [$agora]);
            if ($n > 0) {
                sql("DELETE FROM " . $t . " WHERE expira < ?", [$agora]);
                if ($t !== "usuario_token") {
                    $log[] = t("{1} vencidos apagados: {2}", [1 => $t === "convite" ? t("convites") : t("pedidos de acesso"), 2 => $n]);
                }
            }
        }
        // a conta que ficou sem nada (uma hora de folga: a conta acabou de ser criada)
        foreach (linhas("SELECT u.id, u.login FROM usuario u WHERE u.papel = 'comum' AND u.criado < ?
            AND NOT EXISTS (SELECT 1 FROM colecao c WHERE c.dono_id = u.id) AND NOT EXISTS (SELECT 1 FROM colecao_usuario m WHERE m.usuario_id = u.id)
            AND NOT EXISTS (SELECT 1 FROM acesso_dono a WHERE a.usuario_id = u.id) AND NOT EXISTS (SELECT 1 FROM pedido p WHERE p.usuario_id = u.id)
            AND NOT EXISTS (SELECT 1 FROM convite v WHERE v.email = u.email)", [date("Y-m-d H:i:s", time() - 3600)]) as $u) {
            usuario_excluir((int)$u["id"]);
            $log[] = t("conta sem coleção nem acesso apagada: {1}", [1 => $u["login"]]);
        }
    });
    return $log;
}

// Quem vê a coleção do pedido: o dono, os membros dela e quem tem acesso a tudo do dono. Cada um: {id, login, papel}
function membros_da_colecao()
{
    $c = colecao_atual();
    if ($c === null) {
        return [];
    }
    // o ORDER BY de um UNION pela posição (o login): o Firebird não aceita o nome da coluna
    return sem_escopo(function () use ($c) {
        // e o texto de cada parte com espaços no fim no Firebird (o tipo é o do maior)
        return array_map(function ($l) {
            $l["papel"] = rtrim((string)$l["papel"]);
            return $l;
        }, linhas("SELECT u.id, u.login, 'dono' AS papel FROM usuario u JOIN colecao c ON c.dono_id = u.id WHERE c.id = ?
            UNION SELECT u.id, u.login, 'membro' AS papel FROM usuario u JOIN colecao_usuario m ON m.usuario_id = u.id WHERE m.colecao_id = ?
            UNION SELECT u.id, u.login, 'tudo' AS papel FROM usuario u JOIN acesso_dono a ON a.usuario_id = u.id JOIN colecao c ON c.dono_id = a.dono_id WHERE c.id = ?
            ORDER BY 2", [$c, $c, $c]));
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// O impacto de uma exclusão: o que mais sai junto, seguindo as chaves estrangeiras em cascata (e o que só perde o vínculo,
// nas de ON DELETE SET NULL), no banco inteiro (uma árvore serve a várias coleções: excluir um campo dela tira os valores
// dele em todas). Toda exclusão pela API mostra isto primeiro e só apaga com confirmar=1
// ---------------------------------------------------------------------------------------------------------------------
const IMPACTO_NOMES = ["usuario" => "usuários", "colecao" => "coleções", "colecao_usuario" => "membros", "acesso_dono" => "acessos a tudo de um dono",
    "convite" => "convites", "pedido" => "pedidos de acesso", "api_token" => "tokens de API", "usuario_token" => "links de senha", "push_inscricao" => "aparelhos do Web Push", "usuario_smtp" => "servidores de e-mail próprios",
    "no" => "grupos", "campo" => "campos", "campo_opcao" => "opções de lista", "unidade" => "unidades", "campo_secao" => "divisórias de campos", "lancamento_tipo" => "tipos de lançamento", "formula" => "fórmulas", "aviso" => "avisos",
    "documento_categoria" => "categorias de documento", "documento_categoria_aceita" => "tipos aceitos de categorias", "relogio" => "relógios", "foto" => "fotos",
    "campo_valor" => "valores de campo", "calibre" => "calibres", "calibre_valor" => "valores de calibre", "campo_valor_item" => "valores de campo múltiplo", "calibre_valor_item" => "valores de campo múltiplo de calibre", "acessorio_valor" => "valores de acessório", "acessorio_valor_item" => "valores de campo múltiplo de acessório", "relogio_calibre" => "relógios com calibre", "lancamento" => "lançamentos", "medicao" => "medições do gasto", "documento" => "documentos", "documento_parte" => "pedaços de documentos no banco",
    "servico" => "serviços e diagnósticos", "servico_peca" => "peças de serviços", "servico_documento" => "documentos de serviços",
    "lista_item" => "itens de listas", "oficina" => "oficinas", "oficina_contato" => "contatos de oficinas", "manutencao" => "manutenções",
    "manutencao_evento" => "mudanças de situação de manutenções", "manutencao_acessorio" => "acessórios enviados para manutenção", "manutencao_documento" => "documentos de manutenções",
    "reserva_teste" => "testes da reserva e observações da energia", "timegrapher" => "medições do timegrapher",
    "acessorio" => "acessórios", "acessorio_relogio" => "vínculos de acessório", "winder" => "winders", "winder_valor" => "valores de winder",
    "winder_valor_item" => "valores de campo múltiplo de winder", "winder_posicao" => "posições de winder", "winder_posicao_valor" => "valores de posição de winder",
    "winder_posicao_valor_item" => "valores de campo múltiplo de posição de winder", "lancamento_winder" => "sessões em posição de winder", "winder_movimento" => "movimentos de winder",
    "winder_programa" => "programas de winder", "winder_programa_passo" => "passos de programas de winder", "winder_posicao_programa" => "programas de posições de winder",
    "winder_lugar" => "lugares fixos no winder", "winder_limite_ok" => "avisos de limite do winder dispensados", "criterio_parametro" => "parâmetros de critérios", "criterio_sub" => "subparâmetros de critérios",
    "criterio_faixa" => "faixas de critérios", "config" => "valores da configuração", "modo" => "modos de rodízio", "modo_bloco" => "blocos de modos", "modo_bloco_relogio" => "relógios fixos de blocos",
    "modo_bloco_dia" => "dias de blocos", "plano" => "dias do plano", "plano_bloco" => "blocos de dias do plano", "plano_livre" => "dias livres", "evento_personalizado" => "eventos",
    "evento_relogio" => "vínculos de evento", "evento_dia" => "dias de eventos", "evento_disparo" => "disparos de eventos", "destino" => "destinos", "destino_webhook" => "webhooks",
    "destino_webhook_campo" => "campos de webhook", "destino_webhook_cabecalho" => "cabeçalhos de webhook", "destino_email" => "destinos de e-mail", "destino_webpush" => "destinos de Web Push",
    "destino_aviso" => "escolhas de destino", "destino_evento" => "eventos de destino", "agenda" => "agendas", "agenda_google" => "agendas do Google", "agenda_caldav" => "agendas CalDAV",
    "agenda_outlook" => "agendas do Outlook", "agenda_ics" => "links ICS", "agenda_aviso" => "escolhas de agenda", "agenda_evento" => "eventos de agenda",
    "agenda_sincronizado" => "eventos criados em agendas", "cron_execucao_colecao" => "registros do cron"];
// os nomes, para os dicionários dos idiomas: saem traduzidos no uso, t(IMPACTO_NOMES[...])
tk("usuários"); tk("coleções"); tk("membros"); tk("acessos a tudo de um dono"); tk("convites");
tk("pedidos de acesso"); tk("tokens de API"); tk("links de senha"); tk("aparelhos do Web Push");
tk("servidores de e-mail próprios"); tk("grupos"); tk("campos"); tk("opções de lista"); tk("tipos de lançamento");
tk("fórmulas"); tk("avisos"); tk("categorias de documento"); tk("tipos aceitos de categorias"); tk("relógios");
tk("fotos"); tk("valores de campo"); tk("unidades"); tk("divisórias de campos"); tk("calibres"); tk("valores de calibre"); tk("valores de campo múltiplo"); tk("valores de campo múltiplo de calibre"); tk("valores de acessório"); tk("valores de campo múltiplo de acessório"); tk("relógios com calibre");
tk("lançamentos"); tk("medições do gasto"); tk("documentos"); tk("pedaços de documentos no banco");
tk("serviços e diagnósticos"); tk("peças de serviços"); tk("documentos de serviços");
tk("itens de listas"); tk("oficinas"); tk("contatos de oficinas"); tk("manutenções"); tk("mudanças de situação de manutenções"); tk("acessórios enviados para manutenção"); tk("documentos de manutenções");
tk("testes da reserva e observações da energia"); tk("medições do timegrapher"); tk("acessórios"); tk("vínculos de acessório");
tk("winders"); tk("valores de winder"); tk("valores de campo múltiplo de winder"); tk("posições de winder"); tk("valores de posição de winder");
tk("valores de campo múltiplo de posição de winder"); tk("sessões em posição de winder"); tk("movimentos de winder"); tk("programas de winder");
tk("passos de programas de winder"); tk("programas de posições de winder"); tk("lugares fixos no winder"); tk("avisos de limite do winder dispensados");
tk("parâmetros de critérios"); tk("subparâmetros de critérios"); tk("faixas de critérios");
tk("valores da configuração"); tk("modos de rodízio"); tk("blocos de modos"); tk("relógios fixos de blocos");
tk("dias de blocos"); tk("dias do plano"); tk("blocos de dias do plano"); tk("dias livres"); tk("eventos");
tk("vínculos de evento"); tk("dias de eventos"); tk("disparos de eventos"); tk("destinos"); tk("webhooks");
tk("campos de webhook"); tk("cabeçalhos de webhook"); tk("destinos de e-mail"); tk("destinos de Web Push");
tk("escolhas de destino"); tk("eventos de destino"); tk("agendas"); tk("agendas do Google"); tk("agendas CalDAV");
tk("agendas do Outlook"); tk("links ICS"); tk("escolhas de agenda"); tk("eventos de agenda");
tk("eventos criados em agendas"); tk("registros do cron");

// As chaves estrangeiras do banco ao contrário: [tabela citada => [[tabela que cita, coluna, ao apagar]]] (só as de uma coluna)
function impacto_grafo()
{
    static $g = null;
    if ($g === null) {
        $g = [];
        foreach (sem_escopo(function () { return banco_catalogo(); }) as $t) {
            foreach ($t["chaves_estrangeiras"] as $f) {
                if (strpos($f["coluna"], ",") === false) {
                    [$ref, $col_ref] = explode(".", $f["referencia"], 2);
                    $g[$ref][] = [$t["tabela"], $f["coluna"], $f["ao_apagar"], $col_ref, array_column($t["colunas"], "nome"), array_values($t["chave_primaria"])];
                }
            }
        }
    }
    return $g;
}

// O que sai junto com as linhas $ids da tabela $tabela: {itens: [{tabela, nome, quantos}], desfeitos: [...], colecoes: [nomes],
// relogios: [ids], arquivos, bytes, texto}. Sem escopo (o banco inteiro)
function impacto_exclusao($tabela, $ids)
{
    return sem_escopo(function () use ($tabela, $ids) {
        $g = impacto_grafo();
        $conta = [];
        $desfeitos = [];
        $vistos = [];
        $linhas_vistas = [];
        $fila = [[$tabela, array_map("intval", (array)$ids)]];
        $rel = $tabela === "relogio" ? array_map("intval", (array)$ids) : [];
        $cal = $tabela === "calibre" ? array_map("intval", (array)$ids) : [];
        $cols = $tabela === "colecao" ? array_map("intval", (array)$ids) : [];
        while (count($fila) > 0) {
            [$t, $lista] = array_shift($fila);
            $lista = array_values(array_diff($lista, $vistos[$t] ?? []));
            if (count($lista) === 0) {
                continue;
            }
            $vistos[$t] = array_merge($vistos[$t] ?? [], $lista);
            foreach ($g[$t] ?? [] as [$filha, $col, $ao, $col_ref, $colunas_f, $chave_f]) {
                if ($col_ref !== "id") {
                    continue;
                }
                $onde = $col . " IN (" . implode(", ", $lista) . ")";
                if ($ao === "SET NULL") {
                    $n = (int)valor("SELECT COUNT(*) FROM " . banco_tabela_sql($filha) . " WHERE " . $onde);
                    if ($n > 0) {
                        $desfeitos[$filha] = ($desfeitos[$filha] ?? 0) + $n;
                    }
                    continue;
                }
                if (in_array("id", $colunas_f, true)) {
                    $novos = array_map("intval", array_column(linhas("SELECT id FROM " . banco_tabela_sql($filha) . " WHERE " . $onde), "id"));
                    $novos = array_values(array_diff($novos, $vistos[$filha] ?? []));
                    if (count($novos) > 0) {
                        $conta[$filha] = ($conta[$filha] ?? 0) + count($novos);
                        $fila[] = [$filha, $novos];
                        if ($filha === "relogio") {
                            $rel = array_merge($rel, $novos);
                        } elseif ($filha === "calibre") {
                            $cal = array_merge($cal, $novos);
                        } elseif ($filha === "colecao") {
                            $cols = array_merge($cols, $novos);
                        }
                    }
                } else {
                    // sem id (uma ligação, de chave composta): a mesma linha pode chegar por dois caminhos (o vínculo do
                    // serviço com o documento sai pelo serviço e pelo documento); conta cada linha uma vez, pela chave primária
                    // dela (só a chave: os pedaços dos documentos no banco são binários grandes)
                    $novas = 0;
                    $cols_k = count($chave_f) > 0 ? $chave_f : [$col];
                    foreach (linhas("SELECT " . implode(", ", $cols_k) . " FROM " . banco_tabela_sql($filha) . " WHERE " . $onde) as $x) {
                        $k = json_encode(array_map("strval", array_values($x)));
                        if (!isset($linhas_vistas[$filha][$k])) {
                            $linhas_vistas[$filha][$k] = true;
                            $novas++;
                        }
                    }
                    if ($novas > 0) {
                        $conta[$filha] = ($conta[$filha] ?? 0) + $novas;
                    }
                }
            }
        }
        // as coleções atingidas: as que saem e as dos relógios que saem
        if (count($rel) > 0) {
            $cols = array_merge($cols, array_map("intval", array_column(linhas("SELECT DISTINCT colecao_id FROM relogio WHERE id IN (" . implode(", ", $rel) . ")"), "colecao_id")));
        }
        $nomes_c = count($cols) > 0 ? array_column(linhas("SELECT nome FROM colecao WHERE id IN (" . implode(", ", array_unique($cols)) . ") ORDER BY nome"), "nome") : [];
        // os arquivos que saem do disco: os dos relógios e dos calibres que saem, ou os documentos mesmos
        $donos_ = array_merge(count($rel) > 0 ? ["relogio_id IN (" . implode(", ", $rel) . ")"] : [], count($cal) > 0 ? ["calibre_id IN (" . implode(", ", $cal) . ")"] : []);
        $docs_ = $tabela === "documento" ? "id IN (" . implode(", ", array_map("intval", (array)$ids)) . ")" : (count($donos_) > 0 ? implode(" OR ", $donos_) : null);
        $arq = $docs_ !== null ? linha("SELECT COUNT(*) AS n, COALESCE(SUM(tamanho), 0) AS b FROM documento WHERE " . $docs_) : ["n" => 0, "b" => 0];
        $itens = [];
        foreach ($conta as $t => $n) {
            $itens[] = ["tabela" => $t, "nome" => t(IMPACTO_NOMES[$t] ?? $t), "quantos" => $n];
        }
        $partes = array_map(function ($x) { return $x["quantos"] . " " . $x["nome"]; }, $itens);
        if ((int)$arq["n"] > 0) {
            $partes[] = (int)$arq["n"] === 1 ? t("o arquivo no disco ({1})", [1 => tamanho_texto((int)$arq["b"])]) : t("{1} arquivos ({2})", [1 => (int)$arq["n"], 2 => tamanho_texto((int)$arq["b"])]);
        }
        $texto = count($partes) > 0 ? t("Sai junto: {1}.", [1 => implode(", ", $partes)]) : t("Nada mais sai junto.");
        if (count($nomes_c) > 0) {
            $texto .= " " . t("Coleções atingidas: {1}.", [1 => implode(", ", $nomes_c)]);
        }
        if (count($desfeitos) > 0) {
            $texto .= " " . t("Perdem o vínculo: {1}.", [1 => implode(", ", array_map(function ($t, $n) { return $n . " " . t(IMPACTO_NOMES[$t] ?? $t); }, array_keys($desfeitos), $desfeitos))]);
        }
        return ["itens" => $itens, "desfeitos" => array_map(function ($t, $n) { return ["tabela" => $t, "nome" => t(IMPACTO_NOMES[$t] ?? $t), "quantos" => $n]; }, array_keys($desfeitos), $desfeitos),
            "colecoes" => $nomes_c, "relogios" => count(array_unique($rel)), "arquivos" => (int)$arq["n"], "bytes" => (int)$arq["b"], "texto" => $texto];
    });
}
