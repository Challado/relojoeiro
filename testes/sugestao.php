<?php
// A Sugestão para agora (sugestao_agora e sugestao_tipos, o recurso=sugestao): os tipos com relógio na coleção; ficam de
// fora o que está no pulso, o que está com a carga no limite ou abaixo dele, o indisponível, o que não está na coleção
// (emprestado, vendido, desejado) e os que a pessoa pulou; o filtro por tipo; cada forma de escolha devolve um candidato com
// o motivo; a Outra sugestão passa por todos e avisa quando acabam; e nada é gravado (o plano e os lançamentos ficam como
// estavam). Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-sugestao-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_sugestao($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

try {
    exigir_sugestao(instalar_banco()["ok"], "Não instalou a base descartável.");
    $master = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_sugestao($master["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($master) { return linha("SELECT * FROM usuario WHERE id = ?", [$master["usuario_id"]]); }));
    banco_escopo($master["colecao_id"]);
    $g_smart = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $g_auto = (int)valor("SELECT id FROM no WHERE nome = 'Automático'");
    $g_pilha = (int)valor("SELECT id FROM no WHERE nome = 'Pilha'");
    // (sem tirar do pulso sozinho: o teste não depende da hora em que roda)
    exigir_sugestao(op_config("salvar", ["pulso_auto_fim" => "0", "pulso_auto_inicio" => "0"])["ok"], "Não desligou o pôr e o tirar sozinho.");

    // ---- a opção nasce desligada, e a coleção vazia não tem o que sugerir
    exigir_sugestao(cfg("sugestao_agora") === "0", "A sugestao_agora não nasce desligada.");
    $s = sugestao_agora("inteligente");
    exigir_sugestao(sugestao_tipos() === [] && $s["sugestao"] === null && $s["candidatos"] === 0 && strpos($s["aviso"], "Nenhum relógio disponível na coleção") !== false, "A coleção vazia: " . json_encode($s));

    // ---- a coleção: dois smartwatches (um com a carga boa, um abaixo do limite), dois automáticos, um de pilha, um
    // indisponível, um emprestado, um vendido e um desejado
    $novo = function ($nome, $grupo, $mais = []) {
        $x = op_relogio("salvar", $mais + ["nome" => $nome, "no_id" => $grupo, "disponivel" => 1]);
        exigir_sugestao($x["ok"], "Não criou o " . $nome . ": " . json_encode($x["erros"]));
        return (int)$x["id"];
    };
    $id = ["Smart cheio" => $novo("Smart cheio", $g_smart), "Smart fraco" => $novo("Smart fraco", $g_smart), "Auto um" => $novo("Auto um", $g_auto),
        "Auto dois" => $novo("Auto dois", $g_auto), "Pilha um" => $novo("Pilha um", $g_pilha),
        "Parado" => $novo("Parado", $g_auto, ["disponivel" => 0]), "Emprestado" => $novo("Emprestado", $g_pilha, ["posse" => "emprestado"]),
        "Vendido" => $novo("Vendido", $g_pilha, ["posse" => "vendido"]), "Desejado" => $novo("Desejado", $g_pilha, ["posse" => "desejado"])];
    foreach ([["Smart cheio", 90], ["Smart fraco", 12]] as [$nome, $carga]) {
        $x = op_lancamento("lancar", ["relogio_id" => $id[$nome], "tipo" => "carga", "valor" => $carga]);
        exigir_sugestao($x["ok"], "Não lançou a carga do " . $nome . ": " . json_encode($x["erros"]));
    }
    $t = [];
    foreach (sugestao_tipos() as $x) {
        $t[$x["nome"]] = $x;
    }
    exigir_sugestao(array_keys($t) === ["Automático", "Pilha", "Smartwatch"] && $t["Automático"]["quantos"] === 3 && $t["Automático"]["disponiveis"] === 2
        && $t["Pilha"]["quantos"] === 1 && $t["Smartwatch"]["disponiveis"] === 2 && $t["Smartwatch"]["id"] === $g_smart, "Os tipos (só os que estão na coleção): " . json_encode(sugestao_tipos()));

    // ---- o que nunca muda de um pedido para outro: nada é gravado
    $retrato = function () {
        return json_encode([linhas("SELECT * FROM plano ORDER BY data"), linhas("SELECT * FROM lancamento ORDER BY id"), linhas("SELECT id, posse, disponivel FROM relogio ORDER BY id")]);
    };
    $antes = $retrato();

    // ---- todos os tipos: quatro candidatos; o da carga fraca fica de fora, com o motivo
    $candidatos = ["Smart cheio", "Auto um", "Auto dois", "Pilha um"];
    foreach (array_keys(formas_de_escolha()) as $forma) {
        $s = sugestao_agora($forma);
        exigir_sugestao($s["candidatos"] === 4 && $s["sugestao"] !== null && in_array($s["sugestao"]["relogio"], $candidatos, true) && trim((string)$s["sugestao"]["motivo"]) !== ""
            && $s["forma"] === $forma && $s["aviso"] === null, "A forma " . $forma . ": " . json_encode($s));
        exigir_sugestao(count($s["fora"]) === 1 && $s["fora"][0]["relogio"] === "Smart fraco" && $s["fora"][0]["motivo"] === "carga" && strpos($s["fora"][0]["texto"], "12%") !== false
            && strpos($s["fora"][0]["texto"], "20%") !== false, "O da carga fraca devia ficar de fora, com o motivo: " . json_encode($s["fora"]));
    }
    $s = sugestao_agora("inteligente");
    exigir_sugestao(array_keys($s["sugestao"]) === ["relogio_id", "relogio", "marca", "tipo", "foto", "estado", "estado_texto", "carga", "carga_de", "ultimo_txt", "motivo"]
        && $s["sugestao"]["relogio_id"] === $id[$s["sugestao"]["relogio"]] && $s["sugestao"]["ultimo_txt"] === "nunca", "Os campos da sugestão: " . json_encode($s["sugestao"]));

    // ---- o limite é o do cadastro: com o geral da Configuração em 10%, o de 12% volta a ser candidato; e de volta
    exigir_sugestao(op_config("salvar", ["carga_limiar" => "10"])["ok"], "Não gravou o limite de carga.");
    $s = sugestao_agora("inteligente", [$g_smart]);
    exigir_sugestao($s["candidatos"] === 2 && $s["fora"] === [], "Com o limite em 10%, os dois smartwatches são candidatos: " . json_encode($s));
    exigir_sugestao(op_config("salvar", ["carga_limiar" => ""])["ok"], "Não voltou o limite de carga.");

    // ---- o filtro por tipo: só os automáticos (o indisponível nem aparece); só o smartwatch, sobra um
    $s = sugestao_agora("fifo", [$g_auto]);
    exigir_sugestao($s["candidatos"] === 2 && in_array($s["sugestao"]["relogio"], ["Auto um", "Auto dois"], true) && $s["sugestao"]["tipo"] === "Automático" && $s["fora"] === []
        && $s["tipos_escolhidos"] === [$g_auto], "Só os automáticos: " . json_encode($s));
    $s = sugestao_agora("aleatorio", [$g_smart]);
    exigir_sugestao($s["candidatos"] === 1 && $s["sugestao"]["relogio"] === "Smart cheio" && $s["sugestao"]["carga"] === 90, "Só os smartwatches: " . json_encode($s));
    $s = sugestao_agora("aleatorio", [$g_smart, $g_pilha]);
    exigir_sugestao($s["candidatos"] === 2 && in_array($s["sugestao"]["relogio"], ["Smart cheio", "Pilha um"], true), "Dois tipos de uma vez: " . json_encode($s));

    // ---- a Outra sugestão: pulando os já vistos, passa pelos quatro, sem repetir, e avisa quando acabam
    $vistos = [];
    for ($i = 0; $i < 4; $i++) {
        $s = sugestao_agora("menos_usado", [], $vistos);
        exigir_sugestao($s["sugestao"] !== null && !in_array($s["sugestao"]["relogio_id"], $vistos, true) && $s["candidatos"] === 4 - $i && $s["pulados"] === $i, "A sugestão " . ($i + 1) . ": " . json_encode($s));
        $vistos[] = $s["sugestao"]["relogio_id"];
    }
    sort($vistos);
    $esperados = array_map(function ($n) use ($id) { return $id[$n]; }, $candidatos);
    sort($esperados);
    $s = sugestao_agora("menos_usado", [], $vistos);
    exigir_sugestao($vistos === $esperados && $s["sugestao"] === null && $s["candidatos"] === 0 && strpos($s["aviso"], "já passou por todos") !== false, "Depois de todos: " . json_encode($s));
    exigir_sugestao($retrato() === $antes, "A sugestão gravou alguma coisa.");

    // ---- o que está no pulso agora fica de fora, com o motivo; tirado, volta a ser candidato
    exigir_sugestao(op_lancamento("iniciar", ["relogio_id" => $id["Auto um"], "tipo" => "pulso", "quando" => date("Y-m-d H:i", time() - 600)])["ok"], "Não pôs o Auto um no pulso.");
    $s = sugestao_agora("inteligente", [$g_auto]);
    exigir_sugestao($s["candidatos"] === 1 && $s["sugestao"]["relogio"] === "Auto dois" && count($s["fora"]) === 1 && $s["fora"][0]["relogio"] === "Auto um" && $s["fora"][0]["motivo"] === "pulso",
        "O que está no pulso devia ficar de fora: " . json_encode($s));
    // só ele no tipo, e ele no pulso: nenhum candidato, e o aviso diz por quê
    $s = sugestao_agora("inteligente", [$g_auto], [$id["Auto dois"]]);
    exigir_sugestao($s["sugestao"] === null && strpos($s["aviso"], "já passou por todos") !== false, "Sem candidato, pulando o único: " . json_encode($s));
    exigir_sugestao(op_lancamento("encerrar", ["relogio_id" => $id["Auto um"], "tipo" => "pulso"])["ok"], "Não tirou o Auto um do pulso.");
    exigir_sugestao(sugestao_agora("inteligente", [$g_auto])["candidatos"] === 2, "Tirado do pulso, o Auto um devia voltar a ser candidato.");

    // ---- o relógio na raiz da árvore (sem grupo): o tipo 0, que também se pede; e o único candidato não é sorteado
    $id["Solto"] = $novo("Solto", 0);
    $t0 = array_values(array_filter(sugestao_tipos(), function ($x) { return $x["id"] === 0; }));
    exigir_sugestao(count($t0) === 1 && $t0[0]["nome"] === "sem grupo" && $t0[0]["quantos"] === 1 && $t0[0]["disponiveis"] === 1, "O tipo dos que estão na raiz: " . json_encode(sugestao_tipos()));
    $s = sugestao_agora("aleatorio", [0]);
    exigir_sugestao($s["candidatos"] === 1 && $s["sugestao"]["relogio"] === "Solto" && $s["sugestao"]["tipo"] === "sem grupo" && $s["sugestao"]["motivo"] === "Sem sorteio: único candidato.",
        "Só os da raiz: " . json_encode($s));

    // ---- o tipo sem ninguém disponível: o aviso próprio
    exigir_sugestao(op_relogio("salvar", ["id" => $id["Pilha um"], "disponivel" => 0])["ok"], "Não deixou o Pilha um indisponível.");
    $s = sugestao_agora("inteligente", [$g_pilha]);
    exigir_sugestao($s["sugestao"] === null && $s["candidatos"] === 0 && strpos($s["aviso"], "desses tipos") !== false, "O tipo sem disponível: " . json_encode($s));
    // todos os candidatos de fora (só o da carga fraca no tipo): o aviso diz que ficaram de fora
    exigir_sugestao(op_relogio("salvar", ["id" => $id["Smart cheio"], "disponivel" => 0])["ok"], "Não deixou o Smart cheio indisponível.");
    $s = sugestao_agora("inteligente", [$g_smart]);
    exigir_sugestao($s["sugestao"] === null && count($s["fora"]) === 1 && strpos($s["aviso"], "ficaram de fora") !== false, "Só o da carga fraca no tipo: " . json_encode($s));

    echo "Sugestão para agora: os tipos, as formas de escolha, o filtro por tipo, os que ficam de fora (o pulso, a carga, o indisponível, o que não está na coleção), a Outra sugestão e nada gravado conferem.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    // a pasta descartável
    foreach (glob($pasta . "/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($pasta);
}
