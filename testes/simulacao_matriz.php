<?php
// A matriz do rodízio (parte da simulação: o testes/simulacao.php a carrega e roda depois dos modos): todos os tipos de modo,
// cada um trocado por cada outro, todas as ações do rodízio em cada modo, as mudanças de parâmetro (as semanas, os dias da
// escala) e os dias passando, com a semana começando na segunda (rodada numa quarta) e no domingo (rodada num sábado, o
// último dia da semana). Depois de cada passo, o plano:
//   - não tem buraco de amanhã até o fim esperado (o fim das semanas do modo, ou da próxima semana montada; na escala,
//     escala_dias contados de hoje, ou de amanhã com o dia de hoje já no pulso);
//   - não tem nada sorteado depois desse fim, fora os dias seguidos do relógio que começou até ele (o smartwatch dos dias da
//     bateria): nem o que outro modo sorteou antes do Aplicar, nem a corrida quebrada por um dia mexido;
//   - tem os dias escolhidos à mão e os livres onde estavam (menos onde a ação os tira: o período apagado, o Apagar tudo);
//   - fora do período mexido (sortear de novo um período, deixar livre, escolher os relógios, trocar um dia), cada dia fica
//     com o relógio que tinha.
// E as ações que mexem no dia de hoje são recusadas com o "trocar o relógio de hoje no próprio dia" desligado (o padrão) e
// valem com ele ligado.

// os dias sorteados depois do fim que não são a continuação de um relógio que começou até o fim (os dias seguidos do
// smartwatch, ou o mesmo relógio do trecho do bloco, seguem depois do horizonte: é o jeito do sistema, fora da escala)
function matriz_alem($plano, $fim)
{
    $alem = [];
    $seguindo = null;
    ksort($plano);
    $ontem = null;
    foreach ($plano as $d => $x) {
        $continua = $ontem !== null && $ontem[0] === data_mais($d, -1) && (int)$ontem[1] === (int)$x["relogio_id"] && ($ontem[0] <= $fim || $seguindo === true);
        if ($d > $fim) {
            $seguindo = $continua;
            if (!$continua && $x["origem"] === "sorteio") {
                $alem[] = $d;
            }
        }
        $ontem = [$d, $x["relogio_id"]];
    }
    return $alem;
}

// os modos da matriz: o nome, o tipo e os parâmetros, como a página Modos de rodízio grava (todos com todos os dias)
function matriz_modos()
{
    $todos = [["nome" => "Todos", "dias" => [1, 2, 3, 4, 5, 6, 7], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0]];
    $bloco = [["nome" => "Todos", "dias" => [1, 2, 3, 4, 5, 6, 7], "no_id" => 0, "um_por" => "bloco", "relogio_id" => 0]];
    $fds = [["nome" => "Semana", "dias" => [1, 2, 3, 4, 5], "no_id" => 0, "um_por" => "bloco", "relogio_id" => 0],
        ["nome" => "Fim de semana", "dias" => [6, 7], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0]];
    $roteiro = "util = DIA_SEMANA() <= 5\ncandidato = SE(util; NO_GRUPO(\"Tradicional\"); 1)\npeso = SE(dias_sem_uso > 3; 100; 1)\ndias = SE(NO_GRUPO(\"Smartwatch\"); dias_seguidos; 1)";
    return [
        "um por dia, 1 semana" => ["tipo" => "dia_semana", "selecao" => "inteligente", "semanas" => 1, "blocos" => $todos],
        "um por dia, 2 semanas" => ["tipo" => "dia_semana", "selecao" => "fifo", "semanas" => 2, "blocos" => $todos],
        "um por dia, 3 semanas" => ["tipo" => "dia_semana", "selecao" => "ponderado", "semanas" => 3, "blocos" => $todos],
        "um por semana" => ["tipo" => "semana", "selecao" => "inteligente", "semanas" => 1, "blocos" => $bloco],
        "semana e fim de semana, 2 semanas" => ["tipo" => "semana_fds", "selecao" => "inteligente", "semanas" => 2, "blocos" => $fds],
        "aleatório" => ["tipo" => "aleatorio", "selecao" => "aleatorio", "semanas" => 1, "blocos" => $todos],
        "personalizado" => ["tipo" => "personalizado", "semanas" => 1, "roteiro" => $roteiro],
        "escala de 7" => ["tipo" => "escala", "selecao" => "inteligente", "escala_dias" => 7, "blocos" => $todos],
        "escala de 14" => ["tipo" => "escala", "selecao" => "inteligente", "escala_dias" => 14, "blocos" => $todos],
        "escala de 30" => ["tipo" => "escala", "selecao" => "inteligente", "escala_dias" => 30, "blocos" => $todos],
    ];
}

// um caminho que passa por todas as trocas de um modo para outro, cada uma uma vez (o circuito de Euler do grafo completo
// orientado: todo vértice tem tantas setas entrando quanto saindo)
function matriz_circuito($n)
{
    $saidas = [];
    for ($i = 0; $i < $n; $i++) {
        $saidas[$i] = [];
        for ($j = $n - 1; $j >= 0; $j--) {
            if ($j !== $i) {
                $saidas[$i][] = $j;
            }
        }
    }
    $pilha = [0];
    $caminho = [];
    while ($pilha) {
        $v = end($pilha);
        if ($saidas[$v]) {
            $pilha[] = array_pop($saidas[$v]);
        } else {
            $caminho[] = array_pop($pilha);
        }
    }
    return array_reverse($caminho);
}

function simular_matriz($semana_inicio, $dia)
{
    global $falhas, $REL, $BANCO;
    $nome = "matriz do rodízio, semana " . ($semana_inicio === "domingo" ? "no domingo" : "na segunda") . " (" . $dia . ")";
    $antes_f = count($falhas);
    $passos = 0;
    voltar_ao_modelo();
    relogio($dia . " 05:00:00");
    escrever(["recurso" => "config", "acao" => "salvar", "semana_inicio" => $semana_inicio, "trocar_hoje" => "0"], true, $nome);
    $modos = matriz_modos();
    $ids = [];
    foreach ($modos as $rotulo => $m) {
        $ids[$rotulo] = (int)(escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => $rotulo] + $m, true, $nome)["id"] ?? 0);
    }
    $ativo = null;                 // o rótulo do modo ativo
    $parametro = [];               // rótulo => as semanas ou os dias da escala de agora (mudam na parte dos parâmetros)
    foreach ($modos as $rotulo => $m) {
        $parametro[$rotulo] = $m["tipo"] === "escala" ? (int)$m["escala_dias"] : (int)$m["semanas"];
    }
    $guardados = [];               // data => relógio escolhido à mão, ou "livre"
    $prox_fim = "";                // o fim da próxima semana montada ("" sem ela)
    $escala_inicio = "amanha";     // a Configuração escala_inicio de agora
    $estrito = false;              // o fim da escala exato (a parte F: hoje já começou no pulso e cada passo refaz a escala)
    $semana_fim = function ($d) use ($semana_inicio) {
        return data_mais(semana_de($d, $semana_inicio), 6);
    };

    $ler = function () {
        $p = chamar("GET", ["recurso" => "plano", "dias" => 70]);
        $plano = [];
        foreach ($p["plano"] ?? [] as $x) {
            $plano[$x["data"]] = $x;
        }
        return [$plano, array_flip($p["livres"] ?? []), (string)($p["escala_fim"] ?? "")];
    };
    // o fim esperado do modo ativo, hoje
    $fim_esperado = function () use (&$ativo, &$parametro, &$prox_fim, &$escala_inicio, &$estrito, $modos, $dia, $semana_inicio) {
        $m = $modos[$ativo];
        if ($m["tipo"] === "escala") {
            // os dias do modo contados de hoje ou de amanhã (com o dia de hoje já no pulso, a escala montada antes de ele
            // começar ou depois); na parte F, exato: de amanhã com a escala_inicio amanha, de hoje com a hoje
            if ($estrito) {
                $fim = data_mais($dia, $parametro[$ativo] - 1 + ($escala_inicio === "amanha" ? 1 : 0));
                return [$fim, $fim];
            }
            return [data_mais($dia, $parametro[$ativo] - 1), data_mais($dia, $parametro[$ativo])];
        }
        $fim = data_mais(semana_de($dia, $semana_inicio), 7 * $parametro[$ativo] - 1);
        if ($parametro[$ativo] === 1 && $dia === $fim) {
            $fim = data_mais($dia, 1);
        }
        if ($prox_fim !== "" && $prox_fim > $fim) {
            $fim = $prox_fim;
        }
        return [$fim, $fim];
    };
    // confere o estado; $fora: [de, até, plano de antes] para conferir que fora do período nada mudou
    $conferir = function ($passo, $fora = null) use (&$falhas, &$passos, &$ativo, &$guardados, $nome, $dia, $ler, $fim_esperado, $modos) {
        $passos++;
        $onde = $nome . " · " . $ativo . " · " . $passo;
        chamar("GET", ["recurso" => "hoje"]);
        [$plano, $livres, $escala_fim] = $ler();
        [$min, $max] = $fim_esperado();
        $fim = $max;
        if ($modos[$ativo]["tipo"] === "escala") {
            if ($escala_fim < $min || $escala_fim > $max) {
                $falhas[] = $onde . ": a escala vai até " . ($escala_fim ?: "nada") . " (devia ir até " . $min . ($max !== $min ? " ou " . $max : "") . ")";
            }
            $fim = $escala_fim !== "" && $escala_fim >= $min && $escala_fim <= $max ? $escala_fim : $min;
        }
        for ($d = data_mais($dia, 1); $d <= $fim; $d = data_mais($d, 1)) {
            if (!isset($plano[$d]) && !isset($livres[$d])) {
                $falhas[] = $onde . ": o plano tem um buraco em " . $d . " (devia ir até " . $fim . ")";
                break;
            }
        }
        $alem = matriz_alem($plano, $fim);
        if ($alem) {
            $falhas[] = $onde . ": sorteados depois do fim (" . $fim . "): " . implode(", ", array_slice($alem, 0, 4)) . (count($alem) > 4 ? "…" : "")
                . " (o primeiro: " . ($plano[$alem[0]]["motivo"] ?? "sem motivo") . ")";
        }
        foreach ($guardados as $d => $v) {
            if ($d <= $dia) {
                continue;
            }
            $tem = $v === "livre" ? isset($livres[$d]) : (isset($plano[$d]) && (int)$plano[$d]["relogio_id"] === $v && $plano[$d]["origem"] === "manual");
            if (!$tem) {
                $falhas[] = $onde . ": o dia " . $d . " escolhido à mão (" . ($v === "livre" ? "livre" : "relógio " . $v) . ") sumiu ou mudou";
            }
        }
        if ($fora !== null) {
            [$de_f, $ate_f, $antes] = $fora;
            $mudou = [];
            foreach ($antes as $d => $x) {
                if ($d > $dia && ($d < $de_f || $d > $ate_f) && $d <= $fim && isset($plano[$d]) && (int)$plano[$d]["relogio_id"] !== (int)$x["relogio_id"]) {
                    $mudou[] = $d;
                }
            }
            if ($mudou) {
                $falhas[] = $onde . ": fora do período (" . $de_f . " a " . $ate_f . ") mudou " . implode(", ", array_slice($mudou, 0, 4));
            }
        }
    };
    $rodizio = function ($campos, $passo, $espera_ok = true) use (&$ativo, $nome) {
        return escrever(["recurso" => "rodizio"] + $campos, $espera_ok, $nome . " · " . $ativo . " · " . $passo);
    };
    $aplicar = function ($rotulo) use (&$ativo, $ids, $nome) {
        escrever(["recurso" => "modos", "acao" => "ativar", "id" => $ids[$rotulo]], true, $nome . " · " . $rotulo . " · aplicar");
        $ativo = $rotulo;
    };
    $tirar_guardados = function ($de, $ate) use (&$guardados) {
        foreach (array_keys($guardados) as $d) {
            if ($d >= $de && $d <= $ate) {
                unset($guardados[$d]);
            }
        }
    };
    // o cron da manhã do dia (monta o plano do primeiro modo) e o meio-dia: o dia de hoje já começou no pulso
    $rotulos = array_keys($modos);
    $aplicar($rotulos[0]);
    relogio($dia . " 07:10:00");
    cron($nome . " · " . $dia . " 07:10");
    relogio($dia . " 12:00:00");
    $conferir("o cron da manhã");

    // ---------- A: cada modo trocado por cada outro (o Aplicar, a página Hoje e o Sortear de novo) ----------
    $circuito = matriz_circuito(count($rotulos));
    for ($k = 1; $k < count($circuito); $k++) {
        $de_r = $rotulos[$circuito[$k - 1]];
        $aplicar($rotulos[$circuito[$k]]);
        $conferir("aplicado (vindo de " . $de_r . ")");
        $rodizio(["acao" => "resortear"], "sortear de novo");
        $conferir("sortear de novo (vindo de " . $de_r . ")");
    }

    // ---------- B: todas as ações em cada modo ----------
    foreach ($rotulos as $rotulo) {
        $aplicar($rotulo);
        $rodizio(["acao" => "apagar_tudo"], "apagar tudo (o começo)");
        $guardados = [];
        $prox_fim = "";
        $conferir("apagar tudo (o começo)");
        $escala = $modos[$rotulo]["tipo"] === "escala";
        // sortear de novo um período, com o começo e o fim
        [$p_antes] = $ler();
        $rodizio(["acao" => "periodo_sortear", "de" => data_mais($dia, 2), "ate" => data_mais($dia, 4)], "sortear de novo um período");
        $conferir("sortear de novo um período", [data_mais($dia, 2), data_mais($dia, 4), $p_antes]);
        // um período livre
        [$p_antes] = $ler();
        $rodizio(["acao" => "periodo_livre", "de" => data_mais($dia, 5), "ate" => data_mais($dia, 6)], "período livre");
        $guardados[data_mais($dia, 5)] = "livre";
        $guardados[data_mais($dia, 6)] = "livre";
        $conferir("período livre", [data_mais($dia, 5), data_mais($dia, 6), $p_antes]);
        // os relógios de um período, na ordem
        [$p_antes] = $ler();
        $rodizio(["acao" => "periodo", "de" => data_mais($dia, 1), "ate" => data_mais($dia, 2), "relogio_ids" => [$REL["Corda"], $REL["Solar"]]], "os relógios de um período");
        $guardados[data_mais($dia, 1)] = $REL["Corda"];
        $guardados[data_mais($dia, 2)] = $REL["Solar"];
        $conferir("os relógios de um período", [data_mais($dia, 1), data_mais($dia, 2), $p_antes]);
        // trocar o relógio de um dia
        [$p_antes] = $ler();
        $rodizio(["acao" => "trocar_dia", "data" => data_mais($dia, 3), "relogio_id" => $REL["Pilha"]], "trocar o relógio de um dia");
        $guardados[data_mais($dia, 3)] = $REL["Pilha"];
        $conferir("trocar o relógio de um dia", [data_mais($dia, 3), data_mais($dia, 3), $p_antes]);
        // o rodízio manual de dois dias (um relógio e um livre)
        $rodizio(["acao" => "manual", "de" => data_mais($dia, 8), "ate" => data_mais($dia, 9), "dias" => [data_mais($dia, 8) => (string)$REL["Auto A"], data_mais($dia, 9) => "livre"]], "rodízio manual");
        $guardados[data_mais($dia, 8)] = $REL["Auto A"];
        $guardados[data_mais($dia, 9)] = "livre";
        $conferir("rodízio manual");
        // o Sortear de novo: os escolhidos à mão e os livres ficam
        $rodizio(["acao" => "resortear"], "sortear de novo");
        $conferir("sortear de novo");
        // sortear de novo um período só com o fim (de amanhã até ele): tudo do período sai, os à mão também
        $rodizio(["acao" => "periodo_sortear", "ate" => data_mais($dia, 3)], "sortear de novo um período só com o fim");
        $tirar_guardados(data_mais($dia, 1), data_mais($dia, 3));
        $conferir("sortear de novo um período só com o fim");
        // e só com o começo: dele até o fim do plano
        $rodizio(["acao" => "periodo_sortear", "de" => data_mais($dia, 6)], "sortear de novo um período só com o começo");
        $tirar_guardados(data_mais($dia, 6), "9999-12-31");
        $conferir("sortear de novo um período só com o começo");
        // a próxima semana (fora da escala) e o Sortear de novo depois dela
        $rodizio(["acao" => "proxima_semana"], "próxima semana", !$escala);
        if (!$escala) {
            $prox_fim = data_mais($semana_fim($dia), 7);
            $tirar_guardados(data_mais($semana_fim($dia), 1), $prox_fim);
        }
        $conferir("próxima semana" . ($escala ? " (recusada na escala)" : ""));
        $rodizio(["acao" => "resortear"], "sortear de novo depois da próxima semana");
        $conferir("sortear de novo depois da próxima semana");
        // o dia de hoje, com o trocar no próprio dia desligado: tudo recusado
        $outro = $REL["Auto B"];
        foreach ([["acao" => "resortear_hoje"], ["acao" => "usando", "relogio_id" => $outro], ["acao" => "sem_relogio"],
            ["acao" => "apagar_tudo", "hoje" => "1"], ["acao" => "trocar_dia", "data" => $dia, "relogio_id" => $outro]] as $campos) {
            $rodizio($campos, $campos["acao"] . " hoje (recusado)", false);
        }
        $conferir("as ações de hoje recusadas");
        // o Apagar tudo: sai tudo, os à mão e a próxima semana também
        $rodizio(["acao" => "apagar_tudo"], "apagar tudo");
        $guardados = [];
        $prox_fim = "";
        $conferir("apagar tudo");
    }

    // ---------- C: o trocar no próprio dia ligado: as ações de hoje valem ----------
    escrever(["recurso" => "config", "acao" => "salvar", "trocar_hoje" => "1"], true, $nome);
    foreach ($rotulos as $rotulo) {
        $aplicar($rotulo);
        $rodizio(["acao" => "resortear"], "sortear de novo");
        $outro = $REL["Auto C"];
        $rodizio(["acao" => "usando", "relogio_id" => $outro], "usando hoje");
        [$p] = $ler();
        if ((int)($p[$dia]["relogio_id"] ?? 0) !== $outro) {
            $falhas[] = $nome . " · " . $rotulo . " · usando hoje: o plano de hoje ficou com " . ($p[$dia]["relogio_id"] ?? "nada") . " (devia ser " . $outro . ")";
        }
        $conferir("usando hoje");
        $rodizio(["acao" => "resortear_hoje"], "sortear de novo inclusive hoje");
        $conferir("sortear de novo inclusive hoje");
        $rodizio(["acao" => "sem_relogio"], "sem relógio hoje");
        [, $l] = $ler();
        if (!isset($l[$dia])) {
            $falhas[] = $nome . " · " . $rotulo . " · sem relógio hoje: hoje não ficou livre";
        }
        $conferir("sem relógio hoje");
        $rodizio(["acao" => "trocar_dia", "data" => $dia, "relogio_id" => $REL["Corda"]], "trocar o relógio de hoje");
        $conferir("trocar o relógio de hoje");
        $rodizio(["acao" => "apagar_tudo", "hoje" => "1"], "apagar tudo inclusive hoje");
        [$p] = $ler();
        if (!isset($p[$dia])) {
            $falhas[] = $nome . " · " . $rotulo . " · apagar tudo inclusive hoje: hoje ficou sem relógio";
        }
        $conferir("apagar tudo inclusive hoje");
    }
    escrever(["recurso" => "config", "acao" => "salvar", "trocar_hoje" => "0"], true, $nome);

    // ---------- D: os parâmetros (as semanas e os dias da escala), salvando, direto no banco e com os Apagar ----------
    $salvar = function ($rotulo, $valor) use (&$parametro, $ids, $modos, $nome) {
        $m = $modos[$rotulo];
        $m[$m["tipo"] === "escala" ? "escala_dias" : "semanas"] = $valor;
        escrever(["recurso" => "modos", "acao" => "salvar", "id" => $ids[$rotulo], "nome" => $rotulo] + $m, true, $nome . " · " . $rotulo . " · salvar com " . $valor);
        $parametro[$rotulo] = $valor;
    };
    foreach (["um por dia, 1 semana", "semana e fim de semana, 2 semanas", "personalizado"] as $rotulo) {
        $aplicar($rotulo);
        $rodizio(["acao" => "apagar_tudo"], "apagar tudo");
        foreach ([2, 3, 1, 3] as $n) {
            $salvar($rotulo, $n);
            // mais semanas: a página Hoje já completa; menos: o que passou do fim sai
            $conferir("passado a " . $n . " semana(s), só salvando");
            $rodizio(["acao" => "resortear"], "sortear de novo");
            $conferir("passado a " . $n . " semana(s) e sortear de novo");
        }
        $salvar($rotulo, $modos[$rotulo]["semanas"]);
        $rodizio(["acao" => "apagar_tudo"], "apagar tudo");
        $conferir("de volta às semanas de antes e apagar tudo");
    }
    foreach (["escala de 7", "escala de 30"] as $rotulo) {
        $aplicar($rotulo);
        $rodizio(["acao" => "apagar_tudo"], "apagar tudo");
        foreach ([14, 30, 7, 30, 14] as $n) {
            $salvar($rotulo, $n);
            $conferir("passada a " . $n . " dias, só salvando");
            $rodizio(["acao" => "resortear"], "sortear de novo");
            $conferir("passada a " . $n . " dias e sortear de novo");
            $rodizio(["acao" => "periodo_sortear", "de" => data_mais($dia, 1)], "apagar um período só com o começo");
            $conferir("passada a " . $n . " dias e apagar um período só com o começo");
        }
        // os dias mudados direto no banco (sem o salvar: um banco gravado por uma versão antiga), maior e menor
        foreach ([30, 7] as $n) {
            $pdo = new PDO("sqlite:" . $BANCO);
            $pdo->exec("UPDATE modo SET escala_dias = " . $n . " WHERE id = " . $ids[$rotulo]);
            $pdo = null;
            $parametro[$rotulo] = $n;
            $conferir("passada a " . $n . " dias direto no banco");
        }
        $salvar($rotulo, $modos[$rotulo]["escala_dias"]);
        $rodizio(["acao" => "apagar_tudo"], "apagar tudo");
        $conferir("de volta aos dias de antes e apagar tudo");
    }

    // ---------- F: o primeiro dia da escala (a Configuração escala_inicio), com o dia de hoje já começado no pulso ----------
    // amanha (o padrão): os dias do modo contam de amanhã; hoje: o de hoje é o 1º dia. Mudar a Configuração refaz a escala na
    // próxima montagem, e o Sortear de novo, o Apagar um período e o Apagar tudo seguem a escolha; o relógio de hoje não muda
    $estrito = true;
    foreach (["escala de 7", "escala de 30"] as $rotulo) {
        $aplicar($rotulo);
        [$p0] = $ler();
        $de_hoje = isset($p0[$dia]) ? (int)$p0[$dia]["relogio_id"] : null;
        if ($de_hoje === null) {
            $falhas[] = $nome . " · " . $rotulo . " · o primeiro dia da escala: hoje está sem relógio ao meio-dia";
        }
        foreach (["hoje", "amanha", "hoje", "amanha"] as $ini) {
            $escala_inicio = $ini;
            $passo = "a escala começando " . ($ini === "hoje" ? "sempre hoje" : "amanhã");
            escrever(["recurso" => "config", "acao" => "salvar", "escala_inicio" => $ini], true, $nome . " · " . $rotulo . " · " . $passo);
            $conferir($passo . ", só salvando");
            escrever(["recurso" => "config", "acao" => "salvar", "escala_inicio" => $ini], true, $nome . " · " . $rotulo . " · " . $passo);
            $conferir($passo . ", salvando de novo igual");
            $rodizio(["acao" => "resortear"], "sortear de novo");
            $conferir($passo . " e sortear de novo");
            $rodizio(["acao" => "periodo_sortear", "de" => data_mais($dia, 1)], "apagar um período só com o começo");
            $conferir($passo . " e apagar um período só com o começo");
            $rodizio(["acao" => "apagar_tudo"], "apagar tudo");
            $conferir($passo . " e apagar tudo");
            $salvar($rotulo, 14);
            $conferir($passo . " e passada a 14 dias");
            $salvar($rotulo, $modos[$rotulo]["escala_dias"]);
            $conferir($passo . " e de volta aos dias de antes");
            [$p1] = $ler();
            if ($de_hoje !== null && (!isset($p1[$dia]) || (int)$p1[$dia]["relogio_id"] !== $de_hoje)) {
                $falhas[] = $nome . " · " . $rotulo . " · " . $passo . ": o relógio de hoje, já no pulso, mudou";
            }
        }
    }
    escrever(["recurso" => "config", "acao" => "salvar", "escala_inicio" => "amanha"], true, $nome);

    printf("%-55s %s · %d passos\n", $nome, count($falhas) === $antes_f ? "confere" : (count($falhas) - $antes_f) . " regra(s) falharam", $passos);
    return $passos;
}

// ---------- E: os dias passando com o cron, nas semanas e na escala ----------
// um modo por vez, 20 dias seguidos, o cron de manhã: o plano sempre sem buraco até o fim esperado, nada sorteado depois
// dele, e a escala segue o próprio período (o fim não anda) até vencer, quando o cron monta a seguinte
function simular_matriz_dias($inicio)
{
    global $falhas;
    $nome = "matriz do rodízio, os dias passando";
    $antes_f = count($falhas);
    $passos = 0;
    $rodadas = [];
    foreach (matriz_modos() as $rotulo => $m) {
        // a escala, com as duas escolhas da Configuração escala_inicio (amanha, o padrão, e hoje)
        foreach ($m["tipo"] === "escala" ? ["amanha", "hoje"] : ["amanha"] as $ini) {
            $rodadas[] = [$m["tipo"] === "escala" ? $rotulo . ", começando " . ($ini === "hoje" ? "sempre hoje" : "amanhã") : $rotulo, $m, $ini];
        }
    }
    foreach ($rodadas as [$rotulo, $m, $ini]) {
        voltar_ao_modelo();
        relogio($inicio . " 05:00:00");
        escrever(["recurso" => "config", "acao" => "salvar", "escala_inicio" => $ini], true, $nome);
        $id = (int)(escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => $rotulo] + $m, true, $nome)["id"] ?? 0);
        escrever(["recurso" => "modos", "acao" => "ativar", "id" => $id], true, $nome);
        $fim_e = "";
        for ($i = 0; $i < 20; $i++) {
            $dia = data_mais($inicio, $i);
            relogio($dia . " 07:10:00");
            cron($nome . " · " . $rotulo . " · " . $dia);
            relogio($dia . " 12:00:00");
            chamar("GET", ["recurso" => "hoje"]);
            $p = chamar("GET", ["recurso" => "plano", "dias" => 70]);
            $passos++;
            $plano = [];
            foreach ($p["plano"] ?? [] as $x) {
                $plano[$x["data"]] = $x;
            }
            $livres = array_flip($p["livres"] ?? []);
            $onde = $nome . " · " . $rotulo . " · " . $dia;
            if ($m["tipo"] === "escala") {
                $fim = (string)($p["escala_fim"] ?? "");
                if ($fim === "" || $fim < data_mais($dia, 1) || $fim > data_mais($dia, $m["escala_dias"])) {
                    $falhas[] = $onde . ": a escala vai até " . ($fim ?: "nada") . " (devia ir de amanhã a " . data_mais($dia, $m["escala_dias"]) . ")";
                    continue;
                }
                // o fim só muda quando a escala vence
                if ($fim_e !== "" && $fim !== $fim_e && $fim_e >= data_mais($dia, 1)) {
                    $falhas[] = $onde . ": o fim da escala andou de " . $fim_e . " para " . $fim . " antes de ela vencer";
                }
                // a primeira escala (montada às 05:00, com o dia de hoje ainda não começado): os dias do modo contados de hoje; a
                // seguinte (o cron da manhã no último dia da que vence): começa no dia depois do fim dela, com os dias do modo
                // inteiros, nas duas escolhas da escala_inicio (de 7 em 7, sem perder nem repetir um dia)
                $certo_f = $fim_e === "" ? data_mais($dia, $m["escala_dias"] - 1) : data_mais($fim_e, $m["escala_dias"]);
                if ($fim !== $fim_e && $fim !== $certo_f) {
                    $falhas[] = $onde . ": a escala " . ($fim_e === "" ? "nova" : "seguinte (a anterior ia até " . $fim_e . ")") . " vai até " . $fim . " (devia ir até " . $certo_f . ")";
                }
                $fim_e = $fim;
            } else {
                $fim = data_mais(semana_de($dia, "segunda"), 7 * $m["semanas"] - 1);
                if ($m["semanas"] === 1 && $dia === $fim) {
                    $fim = data_mais($dia, 1);
                }
            }
            for ($d = data_mais($dia, 1); $d <= $fim; $d = data_mais($d, 1)) {
                if (!isset($plano[$d]) && !isset($livres[$d])) {
                    $falhas[] = $onde . ": o plano tem um buraco em " . $d . " (devia ir até " . $fim . ")";
                    break;
                }
            }
            $alem = matriz_alem($plano, $fim);
            if ($alem) {
                $falhas[] = $onde . ": sorteado depois do fim (" . $fim . "): " . $alem[0] . " (" . ($plano[$alem[0]]["motivo"] ?? "sem motivo") . ")";
            }
        }
    }
    printf("%-55s %s · %d passos\n", $nome, count($falhas) === $antes_f ? "confere" : (count($falhas) - $antes_f) . " regra(s) falharam", $passos);
    return $passos;
}
