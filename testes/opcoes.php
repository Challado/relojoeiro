<?php
// As opções em três níveis (sistema → usuário → coleção), todas as da tabela OPCOES: o valor que vale em cada nível, o que cada um
// herda, o conferidor único, quem pode mudar o quê, a cascata (o padrão do usuário vale nas coleções dele e só nelas; o do
// sistema em todas), o que se refaz quando o valor que vale muda e a recusa que desfaz tudo. Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-opcoes-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_opcoes($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

function entrar_opcoes($u)
{
    usuario_definir(sem_escopo(function () use ($u) { return linha("SELECT * FROM usuario WHERE id = ?", [$u["usuario_id"]]); }));
    banco_escopo($u["colecao_id"]);
}

// dois valores válidos e diferentes de uma opção: o padrão de fábrica e um outro
function outro_valor($chave)
{
    [, $tipo, $padrao, $min, $max, $valores] = OPCOES[$chave];
    // a lista (as colunas): a primeira, a segunda e a terceira chave, em ordens diferentes (e a Relógio, que é fixa, sempre junto)
    $candidatos = $tipo === "hora" ? ["08:15", "09:45", "10:30"] : ($tipo === "sim_nao" ? ["0", "1"] : ($tipo === "enum" ? $valores
        : ($tipo === "lista" ? ["nome,uso,marca", "nome,valor", "nome,carga,codigo"]
        : array_map("strval", array_unique([$min, $min + 1, $min + 2, $max - 1, $max])))));
    foreach ($candidatos as $c) {
        if ((string)$c !== (string)$padrao && opcao_conferir($chave, $c)[0] === null) {
            return (string)$c;
        }
    }
    throw new RuntimeException("Sem outro valor para " . $chave);
}

// o que o cfg() devolve para o valor guardado (o "individual" é o vazio de sempre)
function vale($v, $chave)
{
    return OPCOES_SAIDA[$chave][$v] ?? $v;
}

try {
    exigir_opcoes(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_opcoes($dono["ok"], "Não criou o master.");
    entrar_opcoes($dono);
    $outra = op_usuarios("salvar", ["login" => "outra", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], "dono");
    exigir_opcoes($outra["ok"], "Não criou o outro dono.");
    entrar_opcoes($dono);
    $segunda = op_colecoes("criar", ["nome" => "Segunda", "opcao" => "vazia"]);
    exigir_opcoes($segunda["ok"], "Não criou a segunda coleção do mesmo dono.");
    $primeira = $dono["colecao_id"];

    // ---- a tabela: os textos, os padrões válidos, a coleção nova herda tudo
    $tx = opcoes_textos();
    $modelo = modelo_ler()["config"];
    foreach (OPCOES as $k => [$grupo, $tipo, $padrao, $min, $max, $valores]) {
        // a lista de colunas: os valores são as colunas do cadastro (colunas.php), não uma lista fixa
        $valores = $tipo === "lista" ? colunas_catalogo(colunas_pagina_da_opcao($k)) : $valores;
        exigir_opcoes(isset($tx["grupos"][$grupo]) && ($tx["titulos"][$k] ?? "") !== "", "Falta o texto de " . $k . ".");
        exigir_opcoes($padrao === "" || opcao_conferir($k, $padrao)[0] === null, "O padrão de fábrica de " . $k . " não passa no conferidor.");
        exigir_opcoes($tipo !== "enum" || (in_array($padrao, $valores, true) && count($valores) === count($tx["valores"][$k] ?? [])), "Faltam os textos dos valores de " . $k . ".");
        exigir_opcoes($tipo !== "lista" || (count($valores) > 0 && count($valores) === count($tx["valores"][$k] ?? []) && array_keys($tx["valores"][$k]) === $valores), "Faltam os títulos dos itens da lista " . $k . ".");
        exigir_opcoes(($modelo[$k] ?? "") === "", "O modelo.json dá valor a " . $k . ": a coleção nova não herdaria.");
        exigir_opcoes(cfg_cru($k) === "", "A coleção nova guardou " . $k . ".");
        exigir_opcoes(cfg($k) === vale($padrao, $k), "O padrão de fábrica de " . $k . " não vale na coleção nova.");
    }

    // ---- cada opção, nos três níveis: o sistema, o usuário que prevalece sobre ele, a coleção que prevalece sobre os dois;
    // e de volta, tirando um nível de cada vez
    foreach (OPCOES as $k => [$grupo, $tipo, $padrao]) {
        $s = outro_valor($k);
        $p = $padrao === "" ? outro_valor($k) : $padrao;
        $efetivo = function () use ($k) { return opcoes_lista("colecao")["opcoes"][array_search($k, array_keys(OPCOES), true)]; };
        exigir_opcoes(op_config("salvar", ["padroes" => [$k => $s]])["ok"] && cfg($k) === vale($s, $k), "O sistema não valeu em " . $k . ".");
        exigir_opcoes($efetivo()["origem"] === "sistema" && $efetivo()["escolha"] === "" && $efetivo()["efetivo"] === $s, "A origem do sistema em " . $k . ".");
        exigir_opcoes(op_conta("alterar", ["opcoes" => [$k => $p]])["ok"] && cfg($k) === vale($p, $k), "O usuário não prevaleceu sobre o sistema em " . $k . ".");
        exigir_opcoes($efetivo()["origem"] === "usuario" && $efetivo()["herdado"] === $p, "A origem do usuário em " . $k . ".");
        exigir_opcoes(op_config("salvar", [$k => $s])["ok"] && cfg($k) === vale($s, $k) && cfg_cru($k) === $s, "A coleção não prevaleceu em " . $k . ".");
        exigir_opcoes($efetivo()["origem"] === "colecao" && $efetivo()["escolha"] === $s, "A origem da coleção em " . $k . ".");
        exigir_opcoes(op_config("salvar", [$k => ""])["ok"] && cfg($k) === vale($p, $k), "A coleção não voltou a herdar o usuário em " . $k . ".");
        exigir_opcoes(op_conta("alterar", ["opcoes" => [$k => ""]])["ok"] && cfg($k) === vale($s, $k), "O usuário não voltou a herdar o sistema em " . $k . ".");
        exigir_opcoes(op_config("salvar", ["padroes" => [$k => ""]])["ok"] && cfg($k) === vale($padrao, $k) && $efetivo()["origem"] === "padrao", "O sistema não voltou ao padrão de fábrica em " . $k . ".");
        // o valor inválido é recusado em cada nível, e nada muda
        foreach ([["padroes", "op_config"], ["opcoes", "op_conta"]] as [$campo, $op]) {
            $r = $op($op === "op_config" ? "salvar" : "alterar", [$campo => [$k => "valor inválido"]]);
            exigir_opcoes(!$r["ok"] && cfg($k) === vale($padrao, $k), "Aceitou um valor inválido de " . $k . " em " . $campo . ".");
        }
        exigir_opcoes(!op_config("salvar", [$k => "valor inválido"])["ok"] && cfg_cru($k) === "", "A coleção aceitou um valor inválido de " . $k . ".");
    }
    exigir_opcoes(!op_config("salvar", ["padroes" => ["nao_existe" => "1"]])["ok"], "Aceitou uma opção que não existe.");
    exigir_opcoes(!op_conta("alterar", ["opcoes" => "texto"])["ok"] && !op_config("salvar", ["padroes" => "texto"])["ok"], "Aceitou opções fora de uma lista.");

    // ---- quem pode mudar: o padrão do sistema é do master; o do usuário, de cada um, só nas coleções dele
    entrar_opcoes($outra);
    exigir_opcoes(!op_config("salvar", ["padroes" => ["carga_limiar" => "30"]])["ok"], "Um dono que não é master mudou o padrão do sistema.");
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["carga_limiar" => "35"]])["ok"] && cfg("carga_limiar") === "35", "O outro dono não escolheu o dele.");
    entrar_opcoes($dono);
    exigir_opcoes(cfg("carga_limiar") === "20", "O padrão de um dono vazou para outro.");
    exigir_opcoes(op_config("salvar", ["padroes" => ["carga_limiar" => "25"]])["ok"], "O master não mudou o padrão do sistema.");
    exigir_opcoes(cfg("carga_limiar") === "25", "O padrão do sistema não valeu na coleção do master.");
    entrar_opcoes($outra);
    exigir_opcoes(cfg("carga_limiar") === "35", "O padrão do sistema passou por cima do usuário.");
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["carga_limiar" => ""]])["ok"] && cfg("carga_limiar") === "25", "O outro dono não voltou a herdar o sistema.");
    entrar_opcoes($dono);
    exigir_opcoes(op_config("salvar", ["padroes" => ["carga_limiar" => ""]])["ok"], "Não voltou ao padrão de fábrica.");

    // ---- a cascata: o padrão do dono vale nas coleções dele (e só nas que não escolheram a sua)
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["sol_limiar" => "60"]])["ok"], "Não gravou o padrão do dono.");
    exigir_opcoes(cfg("sol_limiar") === "60", "O padrão do dono não valeu na primeira coleção.");
    banco_escopo($segunda["id"]);
    exigir_opcoes(cfg("sol_limiar") === "60", "O padrão do dono não valeu na segunda coleção.");
    exigir_opcoes(op_config("salvar", ["sol_limiar" => "80"])["ok"] && cfg("sol_limiar") === "80", "A segunda coleção não escolheu a dela.");
    banco_escopo($primeira);
    exigir_opcoes(cfg("sol_limiar") === "60", "A escolha da segunda vazou para a primeira.");
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["sol_limiar" => "65"]])["ok"] && cfg("sol_limiar") === "65", "Mudar o padrão do dono não chegou na coleção que herda.");
    banco_escopo($segunda["id"]);
    exigir_opcoes(cfg("sol_limiar") === "80", "O padrão do dono passou por cima da escolha da coleção.");
    banco_escopo($primeira);
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["sol_limiar" => ""]])["ok"] && cfg("sol_limiar") === "70", "Não voltou ao padrão de fábrica.");

    // ---- o que se refaz quando o valor que vale muda
    // o fim do horário de uso: o "fecha às" do tipo No pulso segue (tirando do pulso sozinho)
    $fecha = function () { return substr((string)valor("SELECT fecha_as FROM lancamento_tipo WHERE identificador = 'pulso'"), 0, 5); };
    exigir_opcoes($fecha() === "22:00", "O fecha às do pulso não começou no fim do horário de uso.");
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["uso_fim" => "23:30"]])["ok"] && $fecha() === "23:30", "O padrão do dono não moveu o fecha às do pulso.");
    exigir_opcoes(op_config("salvar", ["padroes" => ["pulso_auto_fim" => "0"]])["ok"] && cfg("pulso_auto_fim") === "0" && valor("SELECT fecha_as FROM lancamento_tipo WHERE identificador = 'pulso'") === null, "Sem tirar do pulso sozinho, o fecha às não saiu.");
    exigir_opcoes(op_config("salvar", ["padroes" => ["pulso_auto_fim" => ""]])["ok"] && $fecha() === "23:30", "O fecha às não voltou.");
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["uso_fim" => ""]])["ok"] && $fecha() === "22:00", "O fecha às não voltou ao fim de fábrica.");
    // o primeiro dia da escala: o fim dela sai
    cfg_set("escala_fim", "2099-01-01");
    exigir_opcoes(op_config("salvar", ["padroes" => ["escala_inicio" => "hoje"]])["ok"] && cfg("escala_inicio") === "hoje" && cfg("escala_fim") === "", "Mudar o primeiro dia da escala no sistema não refez a escala.");
    cfg_set("escala_fim", "2099-01-01");
    exigir_opcoes(op_config("salvar", ["padroes" => ["escala_inicio" => "hoje"]])["ok"] && cfg("escala_fim") === "2099-01-01", "Regravar o mesmo valor refez a escala.");
    exigir_opcoes(op_config("salvar", ["padroes" => ["escala_inicio" => ""]])["ok"], "Não voltou ao padrão da escala.");
    cfg_set("escala_fim", "");
    // o que o outro dono vê não muda quando o primeiro dono muda o dele
    entrar_opcoes($outra);
    exigir_opcoes(cfg("uso_fim") === "22:00" && cfg("escala_inicio") === "amanha", "A mudança de um dono chegou no outro.");
    entrar_opcoes($dono);

    // ---- a recusa que desfaz tudo: a coleção escolheu o início, o usuário escolheria um fim antes dele
    banco_escopo($segunda["id"]);
    exigir_opcoes(op_config("salvar", ["uso_inicio" => "20:00"])["ok"], "A segunda coleção não escolheu o início.");
    banco_escopo($primeira);
    $r = op_conta("alterar", ["opcoes" => ["uso_fim" => "19:00"]]);
    exigir_opcoes(!$r["ok"] && strpos(implode(" ", $r["erros"]), "Segunda") !== false, "Aceitou um fim antes do início que a segunda coleção escolheu.");
    exigir_opcoes((opcoes_do_usuario((int)$dono["usuario_id"])["uso_fim"] ?? "") === "" && cfg("uso_fim") === "22:00", "A recusa deixou o fim gravado.");
    $r = op_config("salvar", ["padroes" => ["uso_fim" => "19:00"]]);
    exigir_opcoes(!$r["ok"] && opcao_do_sistema("uso_fim") === "" && cfg("uso_fim") === "22:00", "A recusa do sistema deixou o fim gravado.");
    exigir_opcoes(!op_config("salvar", ["uso_inicio" => "23:00"])["ok"] && !op_conta("alterar", ["opcoes" => ["uso_inicio" => "23:30", "uso_fim" => "22:00"]])["ok"], "Aceitou o horário invertido.");
    banco_escopo($segunda["id"]);
    exigir_opcoes(op_config("salvar", ["uso_inicio" => ""])["ok"], "A segunda coleção não voltou a herdar o início.");
    banco_escopo($primeira);

    // ---- o cron, sem usuário no pedido: o dono da coleção vale do mesmo jeito
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["painel_modo" => "flutuante"]])["ok"], "Não gravou o painel.");
    usuario_definir(null);
    exigir_opcoes(cfg("painel_modo") === "flutuante", "O cron ignorou o padrão do dono da coleção.");
    entrar_opcoes($dono);
    exigir_opcoes(op_conta("alterar", ["opcoes" => ["painel_modo" => ""]])["ok"], "Não limpou o painel.");

    // ---- a migração leva o valor igual ao padrão de fábrica para o vazio: todas as opções com padrão que existiam na v58
    // estão nela (as que nasceram depois, como a abas_vazias e a pulso_um_so, nunca tiveram o padrão gravado no config: nada a limpar)
    $sql = (string)file_get_contents(dirname(__DIR__) . "/migracao_v58.sql");
    $depois_da_v58 = ["abas_vazias", "pulso_um_so", "sugestao_agora", "dia_sem_relogio"];
    foreach (OPCOES as $k => [, , $padrao]) {
        if ($padrao !== "" && !in_array($k, array_merge(["gasto_motor", "documentos_copia"], $depois_da_v58), true)) {
            exigir_opcoes(strpos($sql, "chave = '" . $k . "' AND valor = '" . $padrao . "'") !== false, "A migração v58 não limpa " . $k . ".");
        }
    }

    // ---- um relógio só no pulso (pulso_um_so): desligada (de fábrica), dois relógios ficam no pulso; ligada, o Pôs de um tira
    // o outro no mesmo instante; o Pôs lançado para trás tira quem já estava e deixa quem foi posto depois; o período fechado
    // (o uso de ontem) não tira ninguém
    $grupo_p = (int)valor("SELECT id FROM no ORDER BY id LIMIT 1");
    $rp = [];
    foreach (["Pulso A", "Pulso B", "Pulso C"] as $nome_p) {
        $x = op_relogio("salvar", ["nome" => $nome_p, "no_id" => $grupo_p, "disponivel" => 1]);
        exigir_opcoes($x["ok"], "Não criou o " . $nome_p . ": " . json_encode($x["erros"]));
        $rp[$nome_p] = (int)$x["id"];
    }
    $no_pulso = function () {
        return array_column(linhas("SELECT r.nome FROM lancamento l JOIN relogio r ON r.id = l.relogio_id JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE t.identificador = 'pulso' AND (l.fim IS NULL OR l.fim > ?) ORDER BY r.nome", [date("Y-m-d H:i:s")]), "nome");
    };
    $ha = function ($min) { return date("Y-m-d H:i", time() - $min * 60); };
    exigir_opcoes(cfg("pulso_um_so") === "0", "A pulso_um_so não nasce desligada.");
    // (sem tirar sozinho no fim do horário: o teste não depende da hora em que roda; com ele, o roteiro confere)
    exigir_opcoes(op_config("salvar", ["pulso_auto_fim" => "0"])["ok"], "Não desligou o tirar sozinho.");
    exigir_opcoes(op_lancamento("iniciar", ["relogio_id" => $rp["Pulso A"], "tipo" => "pulso", "quando" => $ha(180)])["ok"]
        && op_lancamento("iniciar", ["relogio_id" => $rp["Pulso B"], "tipo" => "pulso", "quando" => $ha(120)])["ok"] && $no_pulso() === ["Pulso A", "Pulso B"], "Desligada, os dois deviam ficar no pulso: " . json_encode($no_pulso()));
    exigir_opcoes(op_config("salvar", ["pulso_um_so" => "1"])["ok"] && cfg("pulso_um_so") === "1", "A pulso_um_so não ligou.");
    // o período fechado, de ontem, não mexe em quem está no pulso
    $x = op_lancamento("periodo", ["relogio_id" => $rp["Pulso C"], "tipo" => "pulso", "inicio" => date("Y-m-d", time() - 86400 * 2) . " 08:00", "fim" => date("Y-m-d", time() - 86400 * 2) . " 18:00"]);
    exigir_opcoes($x["ok"] && $no_pulso() === ["Pulso A", "Pulso B"] && strpos($x["mensagem"], "Tirado do pulso") === false, "O período fechado tirou alguém: " . json_encode($x));
    // o Pôs para trás (há 150 min): o A (posto há 180) sai nesse instante; o B (posto há 120, depois) fica
    $x = op_lancamento("periodo", ["relogio_id" => $rp["Pulso C"], "tipo" => "pulso", "inicio" => $ha(150)]);
    exigir_opcoes($x["ok"] && $no_pulso() === ["Pulso B", "Pulso C"] && strpos($x["mensagem"], "Tirado do pulso no mesmo instante: Pulso A.") !== false
        && strpos($x["mensagem"], "Continua no pulso (foi posto depois desse instante): Pulso B.") !== false, "O Pôs para trás: " . json_encode([$x, $no_pulso()]));
    $fim_a = valor("SELECT l.fim FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso' ORDER BY l.id DESC LIMIT 1", [$rp["Pulso A"]]);
    exigir_opcoes(substr((string)$fim_a, 0, 16) === $ha(150), "O A não saiu no instante em que o C entrou: " . json_encode($fim_a));
    // o Pôs (de 5 minutos atrás): os dois que estão no pulso saem, e só o A fica
    $x = op_lancamento("iniciar", ["relogio_id" => $rp["Pulso A"], "tipo" => "pulso", "quando" => $ha(5)]);
    exigir_opcoes($x["ok"] && $no_pulso() === ["Pulso A"] && strpos($x["mensagem"], "Tirado do pulso no mesmo instante: Pulso C, Pulso B.") !== false, "O Pôs: " . json_encode([$x, $no_pulso()]));
    // o atalho (alternar), agora, num relógio fora do pulso é um Pôs: tira o outro; de novo, é o Tirou dele, e ninguém volta
    $x = op_lancamento("alternar", ["relogio_id" => $rp["Pulso B"], "tipo" => "pulso"]);
    exigir_opcoes($x["ok"] && $no_pulso() === ["Pulso B"], "O atalho não tirou o outro: " . json_encode([$x, $no_pulso()]));
    exigir_opcoes(op_lancamento("alternar", ["relogio_id" => $rp["Pulso B"], "tipo" => "pulso"])["ok"] && $no_pulso() === [], "O atalho não tirou o próprio: " . json_encode($no_pulso()));
    exigir_opcoes(op_config("salvar", ["pulso_um_so" => "", "pulso_auto_fim" => ""])["ok"] && cfg("pulso_um_so") === "0", "A pulso_um_so não voltou a herdar.");

    echo "Opções em três níveis: " . count(OPCOES) . " opções (sistema, usuário e coleção), conferidor, permissões, cascata, efeitos e recusa conferem.\n";
    $codigo = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n");
    $codigo = 1;
}
@exec("rm -rf " . escapeshellarg($pasta));
exit($codigo);
