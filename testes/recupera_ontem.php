<?php
// Passar o relógio de ontem para hoje: a herança sistema → usuário → coleção e a regra (o relógio do plano de ontem que não foi
// ao pulso nem um pouquinho passa para hoje, e os outros dias do plano andam um dia). Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-recupera-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_recupera($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

// o plano dos dias (data => relogio_id), de hoje em diante
function plano_de_hoje($h)
{
    $res = [];
    foreach (linhas("SELECT data, relogio_id FROM plano WHERE data >= ? ORDER BY data", [$h]) as $x) {
        $res[substr((string)$x["data"], 0, 10)] = (int)$x["relogio_id"];
    }
    return $res;
}

// um plano de teste: ontem A, hoje B, depois C, A, B (e o dia seguinte livre); cada relógio com o do dia que se quer
function montar_plano($ids, $hoje)
{
    sql("DELETE FROM plano");
    sql("DELETE FROM plano_livre");
    foreach ([-1 => 0, 0 => 1, 1 => 2, 2 => 0, 3 => 1] as $dia => $i) {
        plano_gravar($hoje->modify(($dia >= 0 ? "+" : "") . $dia . " day")->format("Y-m-d"), $ids[$i], null, "sorteio", "teste", null, true);
    }
    plano_livre_marcar($hoje->modify("+5 day")->format("Y-m-d"));
    cfg_set("recupera_ontem_visto", "");
    cfg_set("recupera_ontem_em", "");
}

try {
    exigir_recupera(instalar_banco()["ok"], "Não instalou a base descartável.");
    $master = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_recupera($master["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($master) { return linha("SELECT * FROM usuario WHERE id = ?", [$master["usuario_id"]]); }));
    banco_escopo($master["colecao_id"]);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $ids = [];
    foreach (["A", "B", "C"] as $nome) {
        $r = op_relogio("salvar", ["nome" => $nome, "no_id" => $grupo, "disponivel" => 1]);
        exigir_recupera($r["ok"], "Não criou o relógio " . $nome . ".");
        $ids[] = $r["id"];
    }
    $hoje = hoje_da_colecao();
    $h = $hoje->format("Y-m-d");
    $ontem = $hoje->modify("-1 day")->format("Y-m-d");
    $dia = function ($n) use ($hoje) { return $hoje->modify(($n >= 0 ? "+" : "") . $n . " day")->format("Y-m-d"); };

    // ---- a herança: padrão desligado; sistema, usuário e coleção
    exigir_recupera(recupera_ontem_configuracao() === ["ativo" => false, "colecao" => null, "usuario" => null, "sistema" => false, "origem" => "sistema"], "O padrão deixou de ser desligado e herdado.");
    foreach ([["recupera_ontem" => "talvez"], ["recupera_ontem" => []]] as $ruim) {
        exigir_recupera(!op_config("salvar", $ruim)["ok"], "A coleção aceitou uma escolha inválida.");
        exigir_recupera(!op_conta("alterar", $ruim)["ok"], "A conta aceitou uma escolha inválida.");
    }
    exigir_recupera(op_config("salvar", ["parametros" => ["recupera_ontem" => "1"]])["ok"] && recupera_ontem_ativo() && recupera_ontem_configuracao()["origem"] === "sistema", "A coleção não herdou o sistema ligado.");
    exigir_recupera(op_conta("alterar", ["recupera_ontem" => "0"])["ok"] && !recupera_ontem_ativo() && recupera_ontem_configuracao()["origem"] === "usuario", "O usuário desligado não valeu sobre o sistema.");
    exigir_recupera(op_config("salvar", ["recupera_ontem" => "1"])["ok"] && recupera_ontem_ativo() && recupera_ontem_configuracao()["origem"] === "colecao", "A coleção ligada não valeu sobre o usuário.");
    exigir_recupera(op_config("salvar", ["recupera_ontem" => ""])["ok"] && !recupera_ontem_ativo(), "A coleção não voltou a herdar o usuário.");
    exigir_recupera(op_conta("alterar", ["recupera_ontem" => ""])["ok"] && recupera_ontem_ativo(), "O usuário não voltou a herdar o sistema.");
    exigir_recupera(op_config("salvar", ["parametros" => ["recupera_ontem" => "0"]])["ok"] && !recupera_ontem_ativo(), "O sistema desligado não valeu.");

    // ---- desligado: nada muda
    montar_plano($ids, $hoje);
    $antes = plano_de_hoje($ontem);
    exigir_recupera(plano_recuperar_ontem($hoje) === false && plano_de_hoje($ontem) === $antes, "Mexeu no plano com a opção desligada.");

    // ---- ligado e A sem uso ontem: A passa para hoje, e o resto anda um dia (o dia livre também)
    exigir_recupera(op_config("salvar", ["recupera_ontem" => "1"])["ok"], "Não ligou na coleção.");
    montar_plano($ids, $hoje);
    exigir_recupera(plano_recuperar_ontem($hoje) === true, "Não passou o relógio de ontem sem uso.");
    exigir_recupera(plano_de_hoje($ontem) === [$ontem => $ids[0], $dia(0) => $ids[0], $dia(1) => $ids[1], $dia(2) => $ids[2], $dia(3) => $ids[0], $dia(4) => $ids[1]],
        "O plano depois de passar saiu errado: " . json_encode(plano_de_hoje($ontem)));
    exigir_recupera(plano_do_dia($dia(0))["origem"] === "manual" && strpos((string)plano_do_dia($dia(0))["motivo"], "Passou de ontem") === 0, "O dia de hoje não diz que passou de ontem.");
    exigir_recupera(dia_livre($dia(6)) && !dia_livre($dia(5)), "O dia livre não andou um dia.");
    exigir_recupera(cfg("recupera_ontem_em") === $h, "Não anotou o dia em que passou.");
    $depois = plano_de_hoje($ontem);
    exigir_recupera(plano_recuperar_ontem($hoje) === false && plano_de_hoje($ontem) === $depois, "Passou duas vezes no mesmo dia.");

    // ---- o relógio de ontem foi usado, nem que seja um pouquinho: nada passa
    montar_plano($ids, $hoje);
    $uso = op_lancamento("periodo", ["relogio_id" => $ids[0], "tipo" => "pulso", "inicio" => $ontem . " 23:50", "fim" => $h . " 00:05"]);
    exigir_recupera($uso["ok"], "Não registrou o uso de ontem: " . json_encode($uso["erros"] ?? []));
    $antes = plano_de_hoje($ontem);
    exigir_recupera(plano_recuperar_ontem($hoje) === false && plano_de_hoje($ontem) === $antes, "Passou o relógio que foi ao pulso ontem (cinco minutos antes da meia-noite).");
    sql("DELETE FROM lancamento WHERE relogio_id = ?", [$ids[0]]);

    // ---- não passa: ontem já foi trazido de antes; o relógio indisponível; hoje livre; o de hoje já no pulso
    montar_plano($ids, $hoje);
    cfg_set("recupera_ontem_em", $ontem);
    $antes = plano_de_hoje($ontem);
    exigir_recupera(plano_recuperar_ontem($hoje) === false && plano_de_hoje($ontem) === $antes, "Arrastou o mesmo relógio por dois dias seguidos.");
    montar_plano($ids, $hoje);
    sql("UPDATE relogio SET disponivel = 0 WHERE id = ?", [$ids[0]]);
    exigir_recupera(plano_recuperar_ontem($hoje) === false && plano_de_hoje($ontem) === $antes, "Passou um relógio indisponível.");
    sql("UPDATE relogio SET disponivel = 1 WHERE id = ?", [$ids[0]]);
    montar_plano($ids, $hoje);
    plano_livre_marcar($h);
    sql("DELETE FROM plano WHERE data = ?", [$h]);
    exigir_recupera(plano_recuperar_ontem($hoje) === false && plano_do_dia($h) === null, "Passou num dia livre.");
    sql("DELETE FROM plano_livre WHERE data = ?", [$h]);
    montar_plano($ids, $hoje);
    $iniciar = op_lancamento("iniciar", ["relogio_id" => $ids[1], "tipo" => "pulso"]);
    exigir_recupera($iniciar["ok"], "Não pôs o relógio de hoje no pulso.");
    exigir_recupera(plano_recuperar_ontem($hoje) === false && plano_de_hoje($ontem) === $antes, "Trocou o relógio de um dia que já começou no pulso.");
    sql("DELETE FROM lancamento WHERE relogio_id = ?", [$ids[1]]);

    // ---- o bloco do modo anda junto com o dia
    montar_plano($ids, $hoje);
    $modo = (int)valor("SELECT id FROM modo ORDER BY id LIMIT 1");
    $bloco = $modo > 0 ? (int)(valor("SELECT id FROM modo_bloco WHERE modo_id = ? ORDER BY id LIMIT 1", [$modo]) ?? 0) : 0;
    if ($bloco > 0) {
        sql("INSERT INTO plano_bloco (colecao_id, data, bloco_id) VALUES (?, ?, ?)", [colecao_atual(), $dia(1), $bloco]);
        exigir_recupera(plano_recuperar_ontem($hoje) === true && valor("SELECT bloco_id FROM plano_bloco WHERE data = ?", [$dia(2)]) !== null
            && valor("SELECT bloco_id FROM plano_bloco WHERE data = ?", [$dia(1)]) === null, "O bloco do modo não andou com o dia.");
    }
    echo "Passar o relógio de ontem: a herança, a regra, os casos em que não passa e o dia livre conferem.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    @array_map("unlink", glob($pasta . "/*") ?: []);
    @rmdir($pasta);
}
