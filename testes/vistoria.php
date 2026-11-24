<?php
// A vistoria periódica (a Configuração vistoria_dias; o tipo de lançamento e o aviso Vistoria, a v66): desligada (0, de
// fábrica) não avisa e a lista diz por quê; ligada, cada relógio ativo vence esses dias depois da última vistoria (sem nenhuma,
// da data da compra; sem ela, do cadastro: DATA_CADASTRO()), e o relógio na manutenção ou inativo não vence; a lista (até um
// dia, os atrasados sempre, ou todos; o estado pelo dia do calendário; o resumo); o registrar de várias (tudo ou nada, uma por
// dia, o dia passado ao meio-dia, as recusas); o serviço e o fim da manutenção só contam como vistoria com vistoria=1 (e o
// valor inválido é recusado); o aviso desligado na página Avisos também desliga; e o passo da v66 põe o tipo e o aviso numa
// árvore que não tinha, no idioma da coleção, sem duplicar. A contagem do registrar: só as novas (a primeira do dia conta 1, a
// repetida 0, o lote só as novas). A vistoria tem a coluna dela na tabela da Hoje (Próxima vistoria) e não toma o lugar da
// manutenção; o passo da v68 põe a coluna numa árvore que não tinha. Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-vistoria-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_vistoria($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

try {
    exigir_vistoria(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_vistoria($dono["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($dono) { return linha("SELECT * FROM usuario WHERE id = ?", [$dono["usuario_id"]]); }));
    banco_escopo($dono["colecao_id"]);
    $hoje = hoje_da_colecao();
    $dia = function ($n) use ($hoje) { return $hoje->modify(($n >= 0 ? "+" : "") . $n . " day")->format("Y-m-d"); };

    // ---- a instalação nova já tem o tipo e o aviso, e a opção vem desligada
    exigir_vistoria((linha("SELECT formato FROM lancamento_tipo WHERE identificador = 'vistoria'")["formato"] ?? null) === "instantaneo", "A instalação nova não tem o tipo de lançamento Vistoria.");
    exigir_vistoria((int)(linha("SELECT ativo FROM aviso WHERE identificador = 'vistoria'")["ativo"] ?? 0) === 1, "A instalação nova não tem o aviso Vistoria ligado.");
    exigir_vistoria(cfg("vistoria_dias") === "0", "A vistoria devia vir desligada (0).");

    // ---- quatro relógios: comprado há 45 dias, há 10, sem data de compra (conta do cadastro) e um que vai para a manutenção
    $g = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $ids = [];
    foreach (["Antigo" => -45, "Recente" => -10, "Sem compra" => null, "Na oficina" => -100] as $nome => $n) {
        $r = op_relogio("salvar", ["nome" => $nome, "no_id" => $g, "disponivel" => 1] + ($n === null ? [] : ["valores" => ["data_compra" => $dia($n)]]));
        exigir_vistoria($r["ok"], "Não criou o relógio " . $nome . ": " . implode(" ", $r["erros"] ?? []));
        $ids[$nome] = (int)$r["id"];
    }
    $v = vistorias_lista(null);
    exigir_vistoria($v["ativa"] === false && count($v["relogios"]) === 0 && strpos((string)$v["motivo"], "0") !== false, "Desligada, a lista devia vir vazia, com o motivo: " . json_encode($v));
    $avisos_v = function ($id) {
        return avisos_do_relogio(linha("SELECT * FROM relogio WHERE id = ?", [$id]), time(), ["vistoria"]);
    };
    exigir_vistoria(count($avisos_v($ids["Antigo"])) === 0, "Desligada, a vistoria avisou.");
    exigir_vistoria(op_config("salvar", ["vistoria_dias" => "-1"])["ok"] === false && op_config("salvar", ["vistoria_dias" => "3651"])["ok"] === false, "Aceitou vistoria_dias fora de 0 a 3650.");

    // ---- ligada (30 dias): da compra, ou do cadastro sem ela; o relógio na manutenção não vence
    exigir_vistoria(op_config("salvar", ["vistoria_dias" => "30"])["ok"], "Não ligou a vistoria.");
    $of = (int)valor("SELECT id FROM oficina ORDER BY id LIMIT 1");
    $man = op_manutencao("salvar", ["relogio_id" => $ids["Na oficina"], "oficina_id" => $of, "tipo" => "revisao"]);
    exigir_vistoria($man["ok"], "Não abriu a manutenção: " . implode(" ", $man["erros"] ?? []));
    exigir_vistoria(count($avisos_v($ids["Na oficina"])) === 0, "O relógio na manutenção devia ficar sem a vistoria.");
    $vence = function ($nome) use ($avisos_v, $ids) { $a = $avisos_v($ids[$nome]); return count($a) === 1 ? substr($a[0]["data"], 0, 10) : null; };
    exigir_vistoria($vence("Antigo") === $dia(-15), "Comprado há 45 dias, a vistoria devia ter vencido há 15: " . $vence("Antigo"));
    exigir_vistoria($vence("Recente") === $dia(20), "Comprado há 10 dias, a vistoria devia vencer em 20: " . $vence("Recente"));
    exigir_vistoria($vence("Sem compra") === $dia(30), "Sem data de compra, a vistoria devia contar do cadastro (em 30 dias): " . $vence("Sem compra"));

    // ---- a lista: até um dia (os atrasados sempre), todos, o estado e o resumo, do vencimento mais antigo ao mais novo
    $nomes = function ($l) { return array_column($l["relogios"], "relogio"); };
    $ate_hoje = vistorias_lista($dia(0));
    exigir_vistoria($nomes($ate_hoje) === ["Antigo"] && $ate_hoje["relogios"][0]["estado"] === "atrasado" && $ate_hoje["relogios"][0]["quando"] === t("há {1}", [1 => t("{1} dias", [1 => 15])])
        && $ate_hoje["relogios"][0]["ultima"] === null, "Até hoje, só o atrasado, há 15 dias, sem vistoria: " . json_encode($ate_hoje["relogios"]));
    $todos = vistorias_lista(null);
    exigir_vistoria($nomes($todos) === ["Antigo", "Recente", "Sem compra"] && $todos["resumo"] === ["atrasadas" => 1, "no_periodo" => 3, "com_vistoria" => 3]
        && array_column($todos["relogios"], "estado") === ["atrasado", "proxima", "proxima"], "Todos: os três ativos, na ordem do vencimento: " . json_encode([$nomes($todos), $todos["resumo"]]));
    exigir_vistoria($nomes(vistorias_lista($dia(20))) === ["Antigo", "Recente"], "Até daqui a 20 dias: o atrasado e o que vence nesse dia.");

    // ---- a tabela da Hoje: a vistoria tem a coluna dela (Próxima vistoria) e não toma o lugar da manutenção (a Próxima
    // manutenção, o Em e o O que fazer); na v67, a vistoria atrasada do Antigo virava a "próxima manutenção" dele
    $linha_a = tabela_linha(linha("SELECT * FROM relogio WHERE id = ?", [$ids["Antigo"]]), time());
    $nome_v = (string)valor("SELECT nome FROM aviso WHERE identificador = 'vistoria'");
    exigir_vistoria(($linha_a["manutencao"]["nome"] ?? null) !== $nome_v, "A vistoria ainda aparece como a próxima manutenção (O que fazer): " . json_encode($linha_a["manutencao"]));
    exigir_vistoria(($linha_a["vistoria"]["estado"] ?? null) === "atrasado" && $linha_a["vistoria"]["data"] === $dia(0) && $linha_a["vistoria"]["momento"] > 0,
        "A linha não traz a próxima vistoria (atrasada, mostrada como hoje): " . json_encode($linha_a["vistoria"]));
    exigir_vistoria(tabela_variaveis($linha_a, linha("SELECT * FROM relogio WHERE id = ?", [$ids["Antigo"]]), time())["vistoria_data"] === dias_de(strtotime($dia(0) . " 00:00:00")),
        "A variável vistoria_data (das colunas montadas e dos totais) não acompanha a coluna.");
    $linha_o = tabela_linha(linha("SELECT * FROM relogio WHERE id = ?", [$ids["Na oficina"]]), time());
    exigir_vistoria($linha_o["vistoria"] === null, "Na manutenção, o relógio não devia ter a próxima vistoria.");
    // a coluna do sistema, logo depois da Encostado, e no catálogo da página
    $chaves = array_column(colunas_todas(), "chave");
    exigir_vistoria(array_search("vistoria", $chaves, true) === array_search("encostado", $chaves, true) + 1, "A coluna Próxima vistoria devia vir logo depois da Encostado: " . implode(",", $chaves));
    exigir_vistoria(in_array("vistoria", colunas_padrao("hoje"), true), "A coluna Próxima vistoria devia aparecer de início.");
    // o passo da v68: numa árvore sem a coluna, põe uma vez só, logo depois da Encostado
    sql("DELETE FROM coluna WHERE identificador = 'vistoria'");
    $GLOBALS["COLUNAS_VERSAO"] = ($GLOBALS["COLUNAS_VERSAO"] ?? 0) + 1;
    exigir_vistoria(!in_array("vistoria", array_column(colunas_todas(), "chave"), true), "A coluna devia sumir para o teste da v68.");
    migracao_v68_dados(false);
    migracao_v68_dados(false);
    $chaves = array_column(colunas_todas(), "chave");
    exigir_vistoria(count(array_keys($chaves, "vistoria", true)) === 1 && array_search("vistoria", $chaves, true) === array_search("encostado", $chaves, true) + 1
        && end($chaves) !== "vistoria", "O passo da v68 devia pôr a coluna uma vez só, logo depois da Encostado: " . implode(",", $chaves));

    // ---- o registrar: as recusas não gravam nada; tudo ou nada; uma por dia; o dia passado ao meio-dia
    $lancs = function () { return (int)valor("SELECT COUNT(*) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE t.identificador = 'vistoria'"); };
    foreach ([["relogio_ids" => ""], ["relogio_ids" => "x"], ["relogio_ids" => $ids["Antigo"] . ",999999"], ["relogio_ids" => $ids["Antigo"], "quando" => $dia(1)],
        ["relogio_ids" => $ids["Antigo"], "quando" => "ontem"]] as $pedido) {
        exigir_vistoria(op_vistorias("registrar", $pedido)["ok"] === false, "Aceitou o registrar " . json_encode($pedido));
    }
    exigir_vistoria(op_vistorias("apagar", [])["ok"] === false, "Aceitou uma ação desconhecida.");
    exigir_vistoria($lancs() === 0, "Uma recusa gravou vistoria.");
    $reg = op_vistorias("registrar", ["relogio_ids" => [$ids["Antigo"], $ids["Recente"]], "quando" => $dia(-2)]);
    exigir_vistoria($reg["ok"] && $reg["registradas"] === 2 && $lancs() === 2, "Não registrou as duas vistorias: " . json_encode($reg));
    exigir_vistoria(valor("SELECT MAX(inicio) FROM lancamento") === $dia(-2) . " 12:00:00", "O dia passado devia ficar ao meio-dia dele.");
    exigir_vistoria($vence("Antigo") === $dia(28) && $vence("Recente") === $dia(28), "Depois da vistoria de anteontem, devia vencer em 28 dias.");
    // repetir a mesma: não duplica e devolve registradas = 0 (só conta a nova); repetir o lote também
    $rep = op_vistorias("registrar", ["relogio_ids" => $ids["Antigo"], "quando" => $dia(-2)]);
    exigir_vistoria($rep["ok"] && $rep["registradas"] === 0 && $lancs() === 2, "A segunda vistoria do mesmo dia duplicou ou contou como registrada: " . json_encode($rep));
    $rep2 = op_vistorias("registrar", ["relogio_ids" => [$ids["Antigo"], $ids["Recente"]], "quando" => $dia(-2)]);
    exigir_vistoria($rep2["ok"] && $rep2["registradas"] === 0 && $lancs() === 2, "Repetir o lote devia devolver registradas = 0: " . json_encode($rep2));
    // a primeira do relógio no dia: registradas = 1
    $agora = op_vistorias("registrar", ["relogio_ids" => (string)$ids["Antigo"]]);
    exigir_vistoria($agora["ok"] && $agora["registradas"] === 1 && $lancs() === 3 && $vence("Antigo") === $dia(30), "Sem quando, a vistoria devia ser agora, com registradas = 1: " . json_encode($agora));
    // num lote com uma já feita hoje e uma nova: registradas conta só a nova
    $misto = op_vistorias("registrar", ["relogio_ids" => [$ids["Antigo"], $ids["Sem compra"]], "quando" => $dia(-5)]);
    exigir_vistoria($misto["ok"] && $misto["registradas"] === 2 && $lancs() === 5, "Lote em outro dia: as duas são novas: " . json_encode($misto));
    $misto2 = op_vistorias("registrar", ["relogio_ids" => [$ids["Antigo"], $ids["Sem compra"], $ids["Recente"]], "quando" => $dia(-5)]);
    exigir_vistoria($misto2["ok"] && $misto2["registradas"] === 1 && $lancs() === 6, "Lote com duas já feitas e uma nova: registradas devia ser 1: " . json_encode($misto2));
    sql("DELETE FROM lancamento WHERE inicio LIKE ?", [$dia(-5) . "%"]);
    avisos_todos(true);
    exigir_vistoria(vistorias_lista(null)["relogios"][2]["ultima"] !== null, "A lista devia trazer a última vistoria.");

    // ---- o serviço: só conta como vistoria com vistoria=1, só no registro; o valor inválido é recusado
    exigir_vistoria(op_servicos("salvar", ["relogio_id" => $ids["Sem compra"], "data" => $dia(-1), "tipo" => "revisao", "vistoria" => "talvez"])["ok"] === false, "O serviço aceitou vistoria=talvez.");
    $s0 = op_servicos("salvar", ["relogio_id" => $ids["Sem compra"], "data" => $dia(-3), "tipo" => "revisao"]);
    exigir_vistoria($s0["ok"] && $vence("Sem compra") === $dia(30), "O serviço sem vistoria=1 contou como vistoria.");
    exigir_vistoria(op_servicos("salvar", ["id" => $s0["id"], "data" => $dia(-3), "vistoria" => "1"])["ok"] && $vence("Sem compra") === $dia(30), "Editar o serviço com vistoria=1 contou como vistoria (só no registro).");
    $s1 = op_servicos("salvar", ["relogio_id" => $ids["Sem compra"], "data" => $dia(-1), "tipo" => "revisao", "vistoria" => "1"]);
    exigir_vistoria($s1["ok"] && $vence("Sem compra") === $dia(29) && strpos($s1["mensagem"], "vistoria") !== false, "O serviço com vistoria=1 devia contar, na data dele: " . json_encode([$s1["mensagem"] ?? "", $vence("Sem compra")]));

    // ---- a manutenção: encerrar com vistoria=1 conta (no dia do encerramento); o valor inválido é recusado
    $encerra = (string)valor("SELECT identificador FROM lista_item WHERE lista = 'manutencao_situacao' AND comportamento = 'encerra' ORDER BY ordem LIMIT 1");
    exigir_vistoria(op_manutencao("situacao", ["id" => $man["id"], "situacao" => $encerra, "vistoria" => "x"])["ok"] === false, "A manutenção aceitou vistoria=x.");
    $fim = op_manutencao("situacao", ["id" => $man["id"], "situacao" => $encerra, "vistoria" => "1"]);
    exigir_vistoria($fim["ok"], "Não encerrou a manutenção: " . implode(" ", $fim["erros"] ?? []));
    exigir_vistoria($vence("Na oficina") === $dia(30), "Encerrada com vistoria=1, a vistoria devia vencer em 30 dias: " . $vence("Na oficina"));
    $antes = $vence("Recente");
    $man2 = op_manutencao("salvar", ["relogio_id" => $ids["Recente"], "oficina_id" => $of, "tipo" => "revisao"]);
    op_manutencao("situacao", ["id" => $man2["id"], "situacao" => $encerra]);
    exigir_vistoria($vence("Recente") === $antes, "Encerrada sem vistoria=1, a manutenção contou como vistoria.");

    // ---- o aviso desligado na página Avisos também desliga (a lista diz por quê)
    sql("UPDATE aviso SET ativo = 0 WHERE identificador = 'vistoria'");
    avisos_todos(true);
    $off = vistorias_lista(null);
    exigir_vistoria($off["ativa"] === false && strpos((string)$off["motivo"], "Avisos") !== false && vistoria_opcao()["ativa"] === false, "Com o aviso desligado, a vistoria devia valer como desligada: " . json_encode($off["motivo"]));
    sql("UPDATE aviso SET ativo = 1 WHERE identificador = 'vistoria'");
    avisos_todos(true);

    // ---- o passo da v66: numa árvore sem o tipo e o aviso (a coleção em inglês), põe os dois, no idioma dela, uma vez só
    sql("DELETE FROM aviso WHERE identificador = 'vistoria'");
    sql("DELETE FROM lancamento_tipo WHERE identificador = 'vistoria'");
    sql("UPDATE config SET valor = 'en' WHERE chave = 'idioma'");
    if ((int)valor("SELECT COUNT(*) FROM config WHERE chave = 'idioma'") === 0) {
        sql("INSERT INTO config (colecao_id, chave, valor) VALUES (?, 'idioma', 'en')", [(int)$dono["colecao_id"]]);
    }
    migracao_v66_dados(false);
    migracao_v66_dados(false);
    $tr = modelo_tradutor("en");
    exigir_vistoria((int)valor("SELECT COUNT(*) FROM lancamento_tipo WHERE identificador = 'vistoria'") === 1 && (int)valor("SELECT COUNT(*) FROM aviso WHERE identificador = 'vistoria'") === 1,
        "O passo da v66 devia pôr o tipo e o aviso uma vez só.");
    $av = linha("SELECT a.nome, a.resolve_tipo_id, t.identificador FROM aviso a JOIN lancamento_tipo t ON t.id = a.resolve_tipo_id WHERE a.identificador = 'vistoria'");
    exigir_vistoria($av && $av["identificador"] === "vistoria" && $av["nome"] === $tr("Vistoria"), "O aviso da v66 devia resolver com o tipo Vistoria, no idioma da coleção: " . json_encode($av));

    echo "Vistoria: desligada não avisa; ligada vence da última vistoria (sem ela, da compra; sem compra, do cadastro), e na manutenção não; a lista, o registrar de várias e as recusas; o serviço e a manutenção só com vistoria=1; o aviso desligado desliga; o passo da v66 confere.\n";
    $codigo = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n");
    $codigo = 1;
}
@exec("rm -rf " . escapeshellarg($pasta));
exit($codigo);
