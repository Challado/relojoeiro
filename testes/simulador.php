<?php
// O simulador de rodízio (simulador.php; a página Simulador e o recurso=simulador): as opções (os modos, o que cada um deixa
// mudar, os relógios ativos), a simulação de vários cenários sem gravar nada (o banco igual antes e depois: o plano, os dias
// livres, os modos, a configuração, os relógios e os lançamentos; e a memória do pedido também: a garantia e o modo ativo), a
// semente repetível, os relógios fora do sorteio num cenário, o dia escolhido à mão que fica, a variação de um parâmetro, a
// comparação (os melhores de cada medida), as recusas (o período, a forma, o parâmetro que não vale no modo, cenários demais),
// e o Aplicar: grava exatamente o simulado (o plano, o modo ativo, a forma, a garantia, os relógios do sorteio) e recusa, sem
// gravar nada, quando o plano não sairia mais igual. Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-simulador-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_simulador($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

// o banco inteiro do que o simulador poderia mexer: igual antes e depois de simular
function retrato_simulador()
{
    $r = [];
    foreach (["plano" => "data", "plano_livre" => "data", "modo" => "id", "config" => "chave", "relogio" => "id", "lancamento" => "id", "modo_bloco" => "id"] as $tab => $ordem) {
        $r[$tab] = linhas("SELECT * FROM " . $tab . " ORDER BY " . $ordem);
    }
    return json_encode($r);
}

try {
    exigir_simulador(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_simulador($dono["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($dono) { return linha("SELECT * FROM usuario WHERE id = ?", [$dono["usuario_id"]]); }));
    banco_escopo($dono["colecao_id"]);
    $smart = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $hoje = hoje_da_colecao();
    $dia = function ($n) use ($hoje) { return $hoje->modify(($n >= 0 ? "+" : "") . $n . " day")->format("Y-m-d"); };

    // ---- seis relógios com uso diferente (o "Novo" nunca foi usado) e um inativo
    $ids = [];
    foreach (["Alfa", "Beta", "Gama", "Delta", "Épsilon", "Novo", "Parado"] as $nome) {
        $r = op_relogio("salvar", ["nome" => $nome, "no_id" => $smart, "disponivel" => $nome === "Parado" ? 0 : 1]);
        exigir_simulador($r["ok"], "Não criou o relógio " . $nome . ": " . implode(" ", $r["erros"] ?? []));
        $ids[$nome] = (int)$r["id"];
    }
    foreach (["Alfa" => [-20, -12, -6, -5], "Beta" => [-4, -3], "Gama" => [-2], "Delta" => [-15, -9, -1], "Épsilon" => [-30]] as $nome => $dias) {
        foreach ($dias as $n) {
            $u = op_lancamento("periodo", ["relogio_id" => $ids[$nome], "tipo" => "pulso", "inicio" => $dia($n) . " 08:00", "fim" => $dia($n) . " 18:00"]);
            exigir_simulador($u["ok"], "Não registrou o uso de " . $nome . ": " . implode(" ", $u["erros"] ?? []));
        }
    }
    $um = op_modos("salvar", ["nome" => "Um por dia", "tipo" => "dia_semana", "selecao" => "fifo", "semanas" => 1,
        "blocos" => [["nome" => "Todo dia", "no_id" => "", "dias" => [1, 2, 3, 4, 5, 6, 7], "um_por" => "dia"]]]);
    exigir_simulador($um["ok"], "Não criou o modo Um por dia: " . implode(" ", $um["erros"] ?? []));
    $aleat = op_modos("salvar", ["nome" => "Sorte", "tipo" => "aleatorio",
        "blocos" => [["nome" => "Todo dia", "no_id" => "", "dias" => [1, 2, 3, 4, 5, 6, 7], "um_por" => "dia"]]]);
    exigir_simulador($aleat["ok"], "Não criou o modo aleatório: " . implode(" ", $aleat["erros"] ?? []));
    modo_ativar($um["id"]);
    sql("DELETE FROM plano");
    garantir_plano($hoje);
    // um dia escolhido à mão no meio do período: fica em todo cenário
    $mao = $dia(4);
    exigir_simulador(op_rodizio("trocar_dia", ["data" => $mao, "relogio_id" => $ids["Gama"]])["ok"], "Não escolheu o Gama à mão.");

    // ---- as opções: os modos com o que cada um deixa mudar, os relógios ativos (o inativo não), o período padrão
    $op = simulador_opcoes();
    $m_um = array_values(array_filter($op["modos"], function ($m) use ($um) { return $m["id"] === (int)$um["id"]; }))[0];
    $m_al = array_values(array_filter($op["modos"], function ($m) use ($aleat) { return $m["id"] === (int)$aleat["id"]; }))[0];
    exigir_simulador($m_um["ativo"] && $m_um["parametros"]["forma"]["aplica"] && in_array("menos_usado", $m_um["parametros"]["forma"]["opcoes"], true)
        && $m_um["parametros"]["semanas"]["aplica"] && !$m_um["parametros"]["escala_dias"]["aplica"], "Os parâmetros do modo por dia saíram errados: " . json_encode($m_um["parametros"]));
    exigir_simulador(!$m_al["parametros"]["forma"]["aplica"] && $m_al["parametros"]["forma"]["motivo"] !== "" && !$m_al["parametros"]["ciclo"]["aplica"] && !$m_al["parametros"]["max_sem_uso"]["aplica"],
        "O aleatório devia ter a forma, o ciclo e a garantia desabilitados, com o motivo.");
    exigir_simulador(count($op["relogios"]) === 6 && !in_array($ids["Parado"], array_column($op["relogios"], "id"), true), "Os relógios do simulador devem ser só os ativos.");
    exigir_simulador($op["periodo"]["de"] === $dia(1) && $op["periodo"]["ate"] === $dia(28), "O período padrão devia ser de amanhã, 4 semanas.");

    // ---- dois cenários (a fila e o menos usado), 14 dias: nada muda no banco nem na memória do pedido
    $antes = retrato_simulador();
    $garantia_antes = cfg("max_sem_uso");
    $t0 = microtime(true);
    $s = simulador_simular([["modo" => $um["id"]], ["modo" => $um["id"], "forma" => "menos_usado", "max_sem_uso" => "3"]], $dia(1), $dia(14), "12345", []);
    $tempo = microtime(true) - $t0;
    exigir_simulador(count($s["erros"]) === 0 && count($s["cenarios"]) === 2, "A simulação recusou: " . json_encode($s["erros"]));
    foreach ($s["cenarios"] as $c) {
        exigir_simulador(count($c["erros"]) === 0 && $c["resultado"] !== null && count($c["resultado"]["dias"]) === 14, "O cenário " . $c["indice"] . " não simulou: " . json_encode($c["erros"]));
        exigir_simulador($c["resultado"]["dias"][3]["data"] === $mao && $c["resultado"]["dias"][3]["origem"] === "manual" && $c["resultado"]["dias"][3]["relogio_id"] === $ids["Gama"], "O dia escolhido à mão devia ficar em todo cenário.");
        foreach ($c["resultado"]["dias"] as $x) {
            exigir_simulador($x["relogio_id"] !== null && $x["relogio_id"] !== $ids["Parado"], "Dia sem relógio, ou com o inativo, no cenário " . $c["indice"] . ": " . json_encode($x));
        }
    }
    exigir_simulador(retrato_simulador() === $antes, "A simulação deixou alguma coisa gravada no banco.");
    exigir_simulador(cfg("max_sem_uso") === $garantia_antes && modo_ativo() === (int)$um["id"], "A simulação deixou a garantia ou o modo ativo trocados na memória do pedido.");
    $c1 = $s["cenarios"][1];
    exigir_simulador(strpos($c1["rotulo"], "Menos usado") !== false && strpos($c1["rotulo"], "garantia 3 dias") !== false, "O rótulo do cenário 2 devia dizer o que muda: " . $c1["rotulo"]);
    exigir_simulador(count(array_filter($c1["mudancas"], function ($t) { return strpos($t, "Garantia de rodízio") === 0; })) === 1
        && count(array_filter($c1["mudancas"], function ($t) { return strpos($t, "Forma de escolha do modo Um por dia") === 0; })) === 1, "As mudanças do cenário 2 deviam ter a forma e a garantia: " . json_encode($c1["mudancas"]));
    // com a garantia de 3 dias, o primeiro dia é do mais tempo parado (o Épsilon, 31 dias), pela garantia
    exigir_simulador($c1["resultado"]["dias"][0]["relogio_id"] === $ids["Épsilon"] && strpos($c1["resultado"]["dias"][0]["motivo"], "Garantia de rodízio") === 0,
        "Com a garantia de 3 dias, o primeiro dia devia ser do Épsilon, pela garantia: " . json_encode($c1["resultado"]["dias"][0], JSON_UNESCAPED_UNICODE));
    // a comparação: as medidas, um valor por cenário
    exigir_simulador(count($s["comparacao"]) >= 6 && count($s["comparacao"][0]["valores"]) === 2, "A comparação devia ter um valor por cenário em cada medida.");

    // ---- a mesma semente: o mesmo plano; outra semente, no aleatório: outro plano (quase sempre)
    $s2 = simulador_simular([["modo" => $um["id"]], ["modo" => $um["id"], "forma" => "menos_usado", "max_sem_uso" => "3"]], $dia(1), $dia(14), "12345", []);
    exigir_simulador($s2["cenarios"][0]["resultado"]["impressao"] === $s["cenarios"][0]["resultado"]["impressao"] && $s2["cenarios"][1]["resultado"]["impressao"] === $c1["resultado"]["impressao"], "A mesma semente devia dar o mesmo plano.");
    $a1 = simulador_simular([["modo" => $aleat["id"]]], $dia(1), $dia(14), "1", []);
    $a2 = simulador_simular([["modo" => $aleat["id"]]], $dia(1), $dia(14), "2", []);
    exigir_simulador(count($a1["erros"]) === 0 && $a1["cenarios"][0]["resultado"]["impressao"] !== $a2["cenarios"][0]["resultado"]["impressao"], "Sementes diferentes, no aleatório, deviam dar planos diferentes.");
    exigir_simulador(strpos($a1["cenarios"][0]["resultado"]["dias"][0]["motivo"], "gerador do simulador") !== false || strpos(json_encode($a1["cenarios"][0]["resultado"]["dias"]), "simulador") !== false, "O motivo do sorteio devia dizer que o número veio do gerador do simulador.");
    exigir_simulador(retrato_simulador() === $antes, "As simulações deixaram alguma coisa gravada.");

    // ---- um cenário sem dois relógios no sorteio: eles não aparecem (fora o dia à mão), e continuam no sorteio de verdade
    $sem = simulador_simular([["modo" => $um["id"], "relogios" => implode(",", [$ids["Alfa"], $ids["Beta"], $ids["Delta"], $ids["Novo"]])]], $dia(1), $dia(14), "7", []);
    exigir_simulador(count($sem["erros"]) === 0 && count($sem["cenarios"][0]["erros"]) === 0, "O cenário com relógios fora recusou: " . json_encode($sem));
    foreach ($sem["cenarios"][0]["resultado"]["dias"] as $x) {
        exigir_simulador($x["data"] === $mao || !in_array($x["relogio_id"], [$ids["Gama"], $ids["Épsilon"]], true), "O relógio fora do sorteio do cenário apareceu sorteado: " . json_encode($x));
    }
    exigir_simulador(strpos($sem["cenarios"][0]["rotulo"], "sem ") !== false && strpos(implode(" ", $sem["cenarios"][0]["mudancas"]), "Saem do sorteio") !== false, "O cenário devia dizer os relógios que saem.");
    exigir_simulador((int)valor("SELECT sorteio FROM relogio WHERE id = ?", [$ids["Gama"]]) === 1 && retrato_simulador() === $antes, "O relógio fora do cenário saiu do sorteio de verdade.");

    // ---- a variação: as semanas de 1 a 2 dão dois cenários; a forma, uma para cada forma que o tipo aceita
    $v = simulador_simular([["modo" => $um["id"]]], $dia(1), $dia(7), "3", ["parametro" => "semanas", "de" => "1", "ate" => "2", "passo" => "1"]);
    exigir_simulador(count($v["erros"]) === 0 && count($v["cenarios"]) === 2 && $v["cenarios"][1]["cenario"]["semanas"] === 2, "A variação das semanas devia dar dois cenários: " . json_encode($v["erros"] ?? []));
    $vf = simulador_simular([["modo" => $um["id"]]], $dia(1), $dia(7), "3", ["parametro" => "forma"]);
    exigir_simulador(count($vf["erros"]) === 0 && count($vf["cenarios"]) === count(formas_de_escolha()), "A variação da forma devia dar um cenário por forma: " . json_encode($vf["erros"] ?? []));

    // ---- a escala inteligente: simula pelo próprio caminho dela (gerar_escala), e os dias da escala mudam o período mostrado
    $esc = op_modos("salvar", ["nome" => "Escala", "tipo" => "escala", "escala_dias" => "14", "selecao" => "inteligente", "blocos" => [["nome" => "Todo dia", "no_id" => "", "dias" => [1, 2, 3, 4, 5, 6, 7], "um_por" => "dia"]]]);
    exigir_simulador($esc["ok"], "Não criou a escala: " . implode(" ", $esc["erros"] ?? []));
    $antes = retrato_simulador();
    $se = simulador_simular([["modo" => $esc["id"]], ["modo" => $esc["id"], "escala_dias" => "30", "forma" => "fifo"]], $dia(1), $dia(40), "8", []);
    exigir_simulador(count($se["erros"]) === 0 && count($se["cenarios"][0]["erros"]) === 0 && count($se["cenarios"][1]["erros"]) === 0, "A escala não simulou: " . json_encode([$se["erros"] ?? [], $se["cenarios"][0]["erros"] ?? [], $se["cenarios"][1]["erros"] ?? []], JSON_UNESCAPED_UNICODE));
    exigir_simulador(count($se["cenarios"][1]["resultado"]["dias"]) > count($se["cenarios"][0]["resultado"]["dias"]), "A escala de 30 dias devia mostrar mais dias que a de 14.");
    exigir_simulador(retrato_simulador() === $antes && modo_ativo() === (int)$um["id"], "A simulação da escala deixou alguma coisa gravada.");
    // a escala calcula a carga a partir de agora: aplicada uns segundos depois, ainda sai igual à simulada (e grava)
    sleep(2);
    $pe = array_merge($se["cenarios"][1]["cenario"], ["de" => $dia(1), "ate" => $dia(40), "semente" => "8", "impressao" => $se["cenarios"][1]["resultado"]["impressao"]]);
    $ae = op_simulador("aplicar", $pe);
    exigir_simulador($ae["ok"] && modo_ativo() === (int)$esc["id"] && (int)valor("SELECT escala_dias FROM modo WHERE id = ?", [$esc["id"]]) === 30, "A escala aplicada dois segundos depois devia sair igual: " . json_encode($ae, JSON_UNESCAPED_UNICODE));
    // de volta ao modo por dia, para o resto do teste
    modo_ativar($um["id"]);
    sql("DELETE FROM plano WHERE origem <> 'manual'");
    garantir_plano($hoje);
    exigir_simulador(op_modos("salvar", ["id" => $esc["id"], "escala_dias" => "14", "selecao" => "inteligente"])["ok"], "A escala não voltou a 14 dias.");
    $antes = retrato_simulador();

    // ---- o pior caso: 8 cenários de 120 dias, no tempo de um pedido
    $t1 = microtime(true);
    $grande = simulador_simular([["modo" => $um["id"]]], $dia(1), $dia(SIMULADOR_MAX_DIAS), "4", ["parametro" => "max_sem_uso", "de" => "0", "ate" => "7", "passo" => "1"]);
    $tempo_grande = microtime(true) - $t1;
    exigir_simulador(count($grande["erros"]) === 0 && count($grande["cenarios"]) === SIMULADOR_MAX_CENARIOS, "O pior caso não simulou: " . json_encode($grande["erros"]));
    exigir_simulador(retrato_simulador() === $antes, "O pior caso deixou alguma coisa gravada.");

    // ---- as recusas
    $recusa = function ($cens, $de, $ate, $variar, $trecho, $msg) {
        $r = simulador_simular($cens, $de, $ate, "", $variar);
        exigir_simulador(count($r["erros"]) > 0 && strpos(implode(" ", $r["erros"]), $trecho) !== false, $msg . ": " . json_encode($r["erros"]));
    };
    $recusa([["modo" => $um["id"]]], $hoje->format("Y-m-d"), $dia(5), [], "a partir de amanhã", "O período começando hoje devia ser recusado");
    $recusa([["modo" => $um["id"]]], $dia(1), $dia(1 + SIMULADOR_MAX_DIAS), [], "no máximo", "O período longo demais devia ser recusado");
    $recusa([["modo" => $um["id"], "forma" => "talvez"]], $dia(1), $dia(5), [], "forma", "A forma inválida devia ser recusada");
    $recusa([["modo" => $aleat["id"], "ciclo" => "1"]], $dia(1), $dia(5), [], "não vale neste modo", "O ciclo no aleatório devia ser recusado");
    $recusa([["modo" => $um["id"], "relogios" => (string)$ids["Parado"]]], $dia(1), $dia(5), [], "só os ativos", "O relógio inativo no sorteio devia ser recusado");
    $recusa([["modo" => 999999]], $dia(1), $dia(5), [], "modo", "O modo que não existe devia ser recusado");
    $recusa([["modo" => $um["id"]]], $dia(1), $dia(5), ["parametro" => "max_sem_uso", "de" => "0", "ate" => "20", "passo" => "1"], "no máximo", "Cenários demais deviam ser recusados");
    $recusa([], $dia(1), $dia(5), [], "cenarios", "Sem cenário devia ser recusado");

    // ---- o Aplicar: grava exatamente o simulado (o plano, a forma, a garantia, o modo ativo, os relógios do sorteio)
    $alvo = simulador_simular([["modo" => $um["id"], "forma" => "menos_usado", "max_sem_uso" => "3", "relogios" => implode(",", [$ids["Alfa"], $ids["Beta"], $ids["Gama"], $ids["Delta"], $ids["Novo"]])]], $dia(1), $dia(10), "99", []);
    $ca = $alvo["cenarios"][0];
    exigir_simulador(count($ca["erros"]) === 0, "O cenário a aplicar não simulou: " . json_encode($ca["erros"]));
    $pedido = array_merge($ca["cenario"], ["relogios" => implode(",", $ca["cenario"]["relogios"]), "de" => $dia(1), "ate" => $dia(10), "semente" => (string)$alvo["semente"]]);
    // a impressão errada: recusa e nada muda
    $errado = op_simulador("aplicar", array_merge($pedido, ["impressao" => str_repeat("0", 40)]));
    exigir_simulador(!$errado["ok"] && strpos(implode(" ", $errado["erros"]), "Simule de novo") !== false, "A impressão errada devia ser recusada: " . json_encode($errado));
    exigir_simulador(retrato_simulador() === $antes && cfg("max_sem_uso") === $garantia_antes, "O Aplicar recusado deixou alguma coisa gravada.");
    exigir_simulador(!op_simulador("simular", [])["ok"], "Uma ação desconhecida devia ser recusada.");
    $ok = op_simulador("aplicar", array_merge($pedido, ["impressao" => $ca["resultado"]["impressao"]]));
    exigir_simulador($ok["ok"] && strpos($ok["mensagem"], "Simulação aplicada") === 0, "O Aplicar recusou: " . json_encode($ok));
    foreach ($ca["resultado"]["dias"] as $x) {
        $p = plano_do_dia($x["data"]);
        exigir_simulador($p !== null && (int)$p["relogio_id"] === $x["relogio_id"], "O plano gravado não é o simulado em " . $x["data"] . ".");
    }
    exigir_simulador(valor("SELECT selecao FROM modo WHERE id = ?", [$um["id"]]) === "menos_usado" && (int)cfg("max_sem_uso") === 3, "O Aplicar devia gravar a forma e a garantia.");
    exigir_simulador((int)valor("SELECT sorteio FROM relogio WHERE id = ?", [$ids["Épsilon"]]) === 0 && (int)valor("SELECT sorteio FROM relogio WHERE id = ?", [$ids["Gama"]]) === 1, "O Aplicar devia tirar o Épsilon do sorteio e deixar o Gama.");
    exigir_simulador(plano_do_dia($mao)["origem"] === "manual", "O dia escolhido à mão devia ficar depois do Aplicar.");

    // ---- aplicar outro modo: ele vira o ativo; aplicar de novo o mesmo pedido depois de uma mudança: recusa
    $al = simulador_simular([["modo" => $aleat["id"]]], $dia(1), $dia(7), "5", []);
    $pa = ["modo" => $aleat["id"], "de" => $dia(1), "ate" => $dia(7), "semente" => (string)$al["semente"], "impressao" => $al["cenarios"][0]["resultado"]["impressao"]];
    exigir_simulador(strpos(implode(" ", $al["cenarios"][0]["mudancas"]), "passa a ser o ativo") !== false, "As mudanças deviam dizer que o modo vira o ativo.");
    exigir_simulador(op_rodizio("trocar_dia", ["data" => $dia(6), "relogio_id" => $ids["Alfa"]])["ok"], "Não escolheu um dia à mão.");
    $depois = op_simulador("aplicar", $pa);
    exigir_simulador(!$depois["ok"] || (int)plano_do_dia($dia(6))["relogio_id"] === $ids["Alfa"], "Depois de um dia escolhido à mão, o Aplicar devia recusar (ou o dia à mão ficar).");
    exigir_simulador(op_rodizio("trocar_dia", ["data" => $dia(6), "relogio_id" => 0])["ok"], "O dia 6 não voltou a ser sorteado.");
    $al = simulador_simular([["modo" => $aleat["id"]]], $dia(1), $dia(7), "5", []);
    $pa["impressao"] = $al["cenarios"][0]["resultado"]["impressao"];
    $foi = op_simulador("aplicar", $pa);
    exigir_simulador($foi["ok"] && modo_ativo() === (int)$aleat["id"], "O Aplicar devia ativar o modo aleatório: " . json_encode($foi));

    echo "Simulador: as opções, os cenários sem gravar nada, a semente repetível, os relógios fora do sorteio, o dia à mão, a variação, a comparação, as recusas e o Aplicar (igual ao simulado, ou nada) conferem ("
        . number_format($tempo, 1) . " s para 2 cenários de 14 dias; " . number_format($tempo_grande, 1) . " s para " . SIMULADOR_MAX_CENARIOS . " de " . SIMULADOR_MAX_DIAS . ").\n";
} catch (Throwable $e) {
    echo "FALHOU: " . $e->getMessage() . "\n" . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
} finally {
    if (function_exists("db")) {
        db(true);
    }
    foreach (glob($pasta . "/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($pasta);
}
