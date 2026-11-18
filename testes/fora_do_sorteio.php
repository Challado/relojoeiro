<?php
// O relógio fora do sorteio do rodízio (a marca sorteio, a v65): o rodízio nunca o escolhe sozinho (o plano semanal, a escala,
// o Sortear este dia por, o Decida por mim e o "sortear:" do Rodízio manual, a Sugestão para agora e o teste do roteiro), mas
// ele continua na coleção: disponível, com a carga e os avisos, e pode ser escolhido à mão (trocar_dia, usando, o Rodízio
// manual); os dias sorteados dele, de amanhã em diante, são sorteados de novo quando ele sai, e os escolhidos à mão ficam; a
// marca volta e ele volta ao sorteio; o valor inválido é recusado; e o relógio fixo de um bloco também não vale se está fora.
// Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-fora-do-sorteio-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_sorteio($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

try {
    exigir_sorteio(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_sorteio($dono["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($dono) { return linha("SELECT * FROM usuario WHERE id = ?", [$dono["usuario_id"]]); }));
    banco_escopo($dono["colecao_id"]);
    exigir_sorteio(banco_tem_coluna("relogio", "sorteio"), "A instalação nova não tem a coluna relogio.sorteio.");
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $hoje = hoje_da_colecao();
    $dia = function ($n) use ($hoje) { return $hoje->modify(($n >= 0 ? "+" : "") . $n . " day")->format("Y-m-d"); };

    // ---- quatro relógios; o "Ocasião" é o que sai do sorteio. Ele nunca foi usado: por qualquer forma que olha o uso, seria
    // o primeiro (a fila e o menos usado), e por isso o teste vê de verdade quando ele fica de fora
    $ids = [];
    foreach (["Diário", "Esporte", "Social", "Ocasião"] as $nome) {
        $r = op_relogio("salvar", ["nome" => $nome, "no_id" => $grupo, "disponivel" => 1]);
        exigir_sorteio($r["ok"], "Não criou o relógio " . $nome . ": " . implode(" ", $r["erros"] ?? []));
        $ids[$nome] = (int)$r["id"];
    }
    foreach (["Diário" => [-6, -5], "Esporte" => [-4, -3], "Social" => [-2, -1]] as $nome => $dias) {
        foreach ($dias as $n) {
            $u = op_lancamento("periodo", ["relogio_id" => $ids[$nome], "tipo" => "pulso", "inicio" => $dia($n) . " 08:00", "fim" => $dia($n) . " 18:00"]);
            exigir_sorteio($u["ok"], "Não registrou o uso de " . $nome . ": " . implode(" ", $u["erros"] ?? []));
        }
    }
    $oc = $ids["Ocasião"];
    $linha = function ($id) { return linha("SELECT * FROM relogio WHERE id = ?", [$id]); };
    exigir_sorteio((int)$linha($oc)["sorteio"] === 1 && relogio_no_sorteio($linha($oc)), "O relógio novo devia entrar no sorteio.");

    // ---- antes: a fila e o menos usado pegam o Ocasião (nunca usado), no Sortear este dia por e na Sugestão para agora
    // um dia útil (o modo de fábrica sorteia no fim de semana só entre os tradicionais, e os relógios daqui são smartwatches)
    $util = 3;
    while ((int)(new DateTimeImmutable($dia($util)))->format("N") > 5) {
        $util++;
    }
    $s = op_rodizio("trocar_dia", ["data" => $dia($util), "relogio_id" => "sortear:fifo"]);
    exigir_sorteio($s["ok"] && (int)plano_do_dia($dia($util))["relogio_id"] === $oc, "Antes de sair do sorteio, a fila devia pegar o Ocasião: " . json_encode($s));
    exigir_sorteio(op_rodizio("trocar_dia", ["data" => $dia($util), "relogio_id" => 0])["ok"], "O dia útil não voltou a ser sorteado.");
    exigir_sorteio((int)(sugestao_agora("menos_usado")["sugestao"]["relogio_id"] ?? 0) === $oc, "Antes de sair do sorteio, a sugestão pelo menos usado devia ser o Ocasião.");

    // ---- o modo semanal por dia, uma semana: o plano sorteado inclui o Ocasião (pela fila); um dia dele escolhido à mão
    $modo = op_modos("salvar", ["nome" => "Um por dia", "tipo" => "dia_semana", "selecao" => "fifo", "semanas" => 2,
        "blocos" => [["nome" => "Todo dia", "no_id" => "", "dias" => [1, 2, 3, 4, 5, 6, 7], "um_por" => "dia"]]]);
    exigir_sorteio($modo["ok"], "Não criou o modo: " . implode(" ", $modo["erros"] ?? []));
    modo_ativar($modo["id"]);
    sql("DELETE FROM plano");
    garantir_plano($hoje);
    $do_oc = function ($origem) use ($oc, $dia) {
        return (int)valor("SELECT COUNT(*) FROM plano WHERE relogio_id = ? AND data >= ? AND origem = ?", [$oc, $dia(1), $origem]);
    };
    exigir_sorteio($do_oc("sorteio") > 0, "Antes de sair do sorteio, o plano devia ter dias sorteados do Ocasião.");
    $manual_d = $dia(9);
    exigir_sorteio(op_rodizio("trocar_dia", ["data" => $manual_d, "relogio_id" => $oc])["ok"], "Não escolheu o Ocasião à mão para o dia 9.");

    // ---- sai do sorteio pelo cadastro: o valor inválido é recusado; o certo grava, e os dias sorteados dele saem e são
    // sorteados de novo entre os outros; o escolhido à mão fica
    exigir_sorteio(!op_relogio("salvar", ["id" => $oc, "sorteio" => "talvez"])["ok"], "Aceitou sorteio=talvez.");
    $sai = op_relogio("salvar", ["id" => $oc, "sorteio" => "0"]);
    exigir_sorteio($sai["ok"], "Não tirou do sorteio: " . implode(" ", $sai["erros"] ?? []));
    exigir_sorteio(strpos($sai["mensagem"], "Saiu do sorteio") !== false, "A mensagem não disse o que aconteceu com o plano: " . $sai["mensagem"]);
    $r_oc = $linha($oc);
    exigir_sorteio((int)$r_oc["sorteio"] === 0 && (int)$r_oc["disponivel"] === 1 && !relogio_no_sorteio($r_oc), "Fora do sorteio, ele devia continuar disponível.");
    exigir_sorteio($do_oc("sorteio") === 0, "Os dias sorteados do Ocasião deviam sair do plano.");
    exigir_sorteio((int)(plano_do_dia($manual_d)["relogio_id"] ?? 0) === $oc && plano_do_dia($manual_d)["origem"] === "manual", "O dia escolhido à mão devia ficar.");
    exigir_sorteio((int)valor("SELECT COUNT(*) FROM plano WHERE data >= ? AND relogio_id IS NULL", [$dia(1)]) === 0
        && (int)valor("SELECT COUNT(*) FROM plano WHERE data BETWEEN ? AND ?", [$dia(1), $dia(7)]) >= 7, "Os dias que eram dele deviam ser sorteados de novo entre os outros.");

    // ---- fora do sorteio: nenhum caminho do sorteio o escolhe
    sql("DELETE FROM plano WHERE origem = 'sorteio'");
    garantir_plano($hoje);
    exigir_sorteio($do_oc("sorteio") === 0, "O plano semanal sorteou o relógio fora do sorteio.");
    foreach (array_keys(formas_de_escolha()) as $forma) {
        $s = op_rodizio("trocar_dia", ["data" => $dia(4), "relogio_id" => "sortear:" . $forma]);
        exigir_sorteio($s["ok"] && (int)plano_do_dia($dia(4))["relogio_id"] !== $oc, "O Sortear este dia por " . $forma . " escolheu o relógio fora do sorteio.");
    }
    $m = op_rodizio("manual", ["de" => $dia(5), "ate" => $dia(6), "dias" => [$dia(5) => "auto", $dia(6) => "sortear:menos_usado"]]);
    exigir_sorteio($m["ok"] && (int)plano_do_dia($dia(5))["relogio_id"] !== $oc && (int)plano_do_dia($dia(6))["relogio_id"] !== $oc,
        "O Decida por mim ou o sortear: do Rodízio manual escolheu o relógio fora do sorteio: " . json_encode($m["erros"] ?? []));
    $sg = sugestao_agora("menos_usado");
    $fora_sg = array_values(array_filter($sg["fora"], function ($x) use ($oc) { return (int)$x["relogio_id"] === $oc; }));
    exigir_sorteio((int)($sg["sugestao"]["relogio_id"] ?? 0) !== $oc && count($fora_sg) === 1 && $fora_sg[0]["motivo"] === "sorteio",
        "A Sugestão para agora devia deixar o Ocasião de fora, com o motivo sorteio: " . json_encode($sg["fora"]));
    $teste = roteiro_testar("candidato = 1\nnota = dias_sem_uso\npeso = 0", $hoje->modify("+2 days"));
    $linha_oc = array_values(array_filter($teste["relogios"], function ($x) use ($oc) { return (int)$x["id"] === $oc; }));
    exigir_sorteio(count($linha_oc) === 1 && $linha_oc[0]["no_sorteio"] === false && (int)($teste["escolhido"]["relogio_id"] ?? 0) !== $oc,
        "O teste do roteiro devia mostrar o Ocasião fora do sorteio e não escolhê-lo.");

    // ---- e a escala inteligente (pela fila: sem o Ocasião, ninguém nunca usado)
    $esc = op_modos("salvar", ["nome" => "Escala", "tipo" => "escala", "selecao" => "fifo", "escala_dias" => 14,
        "blocos" => [["nome" => "Todo dia", "no_id" => "", "dias" => [1, 2, 3, 4, 5, 6, 7], "um_por" => "dia"]]]);
    exigir_sorteio($esc["ok"], "Não criou a escala: " . implode(" ", $esc["erros"] ?? []));
    modo_ativar($esc["id"]);
    sql("DELETE FROM plano WHERE origem = 'sorteio'");
    cfg_set("escala_fim", "");
    garantir_plano($hoje);
    exigir_sorteio((int)valor("SELECT COUNT(*) FROM plano WHERE data >= ?", [$dia(0)]) >= 7 && $do_oc("sorteio") === 0, "A escala sorteou o relógio fora do sorteio.");

    // ---- o relógio fixo de um bloco também não vale: o bloco dele fica sem relógio sorteado
    $fixo = op_modos("salvar", ["nome" => "Fixo", "tipo" => "dia_semana", "selecao" => "inteligente", "semanas" => 1,
        "blocos" => [["nome" => "Todo dia", "relogio_id" => $oc, "dias" => [1, 2, 3, 4, 5, 6, 7], "um_por" => "dia"]]]);
    exigir_sorteio($fixo["ok"], "Não criou o modo com o relógio fixo: " . implode(" ", $fixo["erros"] ?? []));
    modo_ativar($fixo["id"]);
    sql("DELETE FROM plano WHERE origem = 'sorteio'");
    garantir_plano($hoje);
    exigir_sorteio($do_oc("sorteio") === 0, "O relógio fixo fora do sorteio foi sorteado.");
    modo_ativar($modo["id"]);

    // ---- mas ele continua na coleção: à mão, hoje e num dia que vem; o Usando hoje; a carga e os avisos; a linha da tabela
    $mao = op_rodizio("trocar_dia", ["data" => $dia(2), "relogio_id" => $oc]);
    exigir_sorteio($mao["ok"] && (int)plano_do_dia($dia(2))["relogio_id"] === $oc, "Fora do sorteio, ele devia poder ser escolhido à mão.");
    $m = op_rodizio("manual", ["de" => $dia(11), "ate" => $dia(11), "dias" => [$dia(11) => $oc]]);
    exigir_sorteio($m["ok"] && (int)plano_do_dia($dia(11))["relogio_id"] === $oc, "O Rodízio manual devia aceitar o relógio fora do sorteio escolhido à mão.");
    $tl = tabela_linha($linha($oc), time());
    exigir_sorteio($tl["disponivel"] === true && $tl["no_sorteio"] === false && $tl["estado"] !== "indisponivel", "Na tabela, ele devia aparecer disponível, só fora do sorteio: " . json_encode([$tl["disponivel"], $tl["no_sorteio"], $tl["estado"]]));
    exigir_sorteio(tabela_variaveis($tl, $linha($oc), time())["no_sorteio"] === 0, "A variável no_sorteio dos totais devia ser 0.");
    $pulso = op_lancamento("iniciar", ["relogio_id" => $oc, "tipo" => "pulso"]);
    exigir_sorteio($pulso["ok"], "Fora do sorteio, ele devia poder ir ao pulso: " . implode(" ", $pulso["erros"] ?? []));

    // ---- volta ao sorteio: a fila volta a pegá-lo (sem o uso de agora, que acabou de começar, ele volta a ser o nunca usado)
    sql("DELETE FROM lancamento WHERE relogio_id = ?", [$oc]);
    $volta = op_relogio("salvar", ["id" => $oc, "sorteio" => "1"]);
    exigir_sorteio($volta["ok"] && relogio_no_sorteio($linha($oc)), "Não voltou ao sorteio.");
    // (no dia 1, antes dos dias 2 e 11, em que ele foi escolhido à mão e que contam como uso nos dias seguintes)
    $s = op_rodizio("trocar_dia", ["data" => $dia(1), "relogio_id" => "sortear:fifo"]);
    exigir_sorteio($s["ok"] && (int)plano_do_dia($dia(1))["relogio_id"] === $oc, "De volta ao sorteio, a fila devia pegá-lo de novo: " . json_encode([$s["mensagem"] ?? "", $s["erros"] ?? []]));

    // ---- o indisponível continua fora de tudo, com a marca ligada ou não
    exigir_sorteio(op_relogio("salvar", ["id" => $oc, "disponivel" => "0"])["ok"] && !relogio_no_sorteio($linha($oc)), "O indisponível entrou no sorteio.");
    exigir_sorteio(!op_rodizio("trocar_dia", ["data" => $dia(13), "relogio_id" => $oc])["ok"], "O indisponível foi escolhido à mão.");

    echo "Fora do sorteio: o plano, a escala, o relógio fixo, o Sortear este dia por, o Decida por mim, a Sugestão para agora e o teste do roteiro o deixam de fora; à mão, no pulso e na tabela ele continua; a saída refaz os dias sorteados dele e guarda os escolhidos à mão; a volta e o valor inválido conferem.\n";
    $codigo = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n");
    $codigo = 1;
}
@exec("rm -rf " . escapeshellarg($pasta));
exit($codigo);
