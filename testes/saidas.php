<?php
// As saídas da coleção (relogio_saida, a v64) e as abas por situação: a saída abre quando o relógio é emprestado ou sai, fecha
// quando ele volta, vira outra quando troca de motivo noutro dia e é só corrigida no mesmo dia; os campos da saída só valem
// numa saída; a correção e a exclusão pelo recurso=saidas; as contas (o resultado, o tempo na coleção); as abas (uma por
// situação com relógio, a principal sempre) e o item da lista que não sai enquanto o histórico o cita. E o passo da migração:
// os que já estavam fora ganham a saída aberta, sem data. Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-saidas-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_saidas($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

try {
    exigir_saidas(instalar_banco()["ok"], "Não instalou a base descartável.");
    $master = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_saidas($master["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($master) { return linha("SELECT * FROM usuario WHERE id = ?", [$master["usuario_id"]]); }));
    banco_escopo($master["colecao_id"]);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $hoje = hoje_da_colecao()->format("Y-m-d");
    $ontem = hoje_da_colecao()->modify("-1 day")->format("Y-m-d");
    $mes = hoje_da_colecao()->modify("-30 days")->format("Y-m-d");
    $abertas = function ($rid) { return (int)valor("SELECT COUNT(*) FROM relogio_saida WHERE relogio_id = ? AND fim IS NULL", [$rid]); };
    $todas = function ($rid) { return (int)valor("SELECT COUNT(*) FROM relogio_saida WHERE relogio_id = ?", [$rid]); };
    $aba = function ($chave) { return array_values(array_filter(abas_situacao(), function ($a) use ($chave) { return $a["chave"] === $chave; }))[0] ?? null; };

    // ---- sem relógio nenhum: só a aba principal (a primeira situação que faz "na coleção"), vazia
    $ab = abas_situacao();
    exigir_saidas(count($ab) === 1 && $ab[0]["chave"] === "colecao" && $ab[0]["quantos"] === 0 && $ab[0]["tipo"] === "relogios", "Sem relógios, só a aba principal: " . json_encode($ab));

    $a = op_relogio("salvar", ["nome" => "A", "no_id" => $grupo, "valores" => ["valor_compra" => "1000", "data_compra" => $mes]]);
    $b = op_relogio("salvar", ["nome" => "B", "no_id" => $grupo]);
    $c = op_relogio("salvar", ["nome" => "C", "no_id" => $grupo]);
    exigir_saidas($a["ok"] && $b["ok"] && $c["ok"], "Não criou os relógios: " . json_encode([$a, $b, $c]));
    exigir_saidas($todas($a["id"]) === 0 && $aba("colecao")["quantos"] === 3, "Na coleção, nenhuma saída.");

    // ---- os campos da saída só valem numa saída; a volta só na volta; datas e valores conferidos
    foreach ([[["saida_valor" => "500"], "valor numa situação que não é saída"], [["saida_destino" => "Fulano"], "destino sem saída"],
        [["posse" => "vendido", "saida_data" => "2026-02-30"], "data que não existe"], [["posse" => "vendido", "saida_data" => "2999-01-01"], "data no futuro"],
        [["posse" => "vendido", "saida_valor" => "-1"], "valor negativo"], [["posse" => "vendido", "saida_valor" => "abc"], "valor que não é número"],
        [["posse" => "vendido", "volta_data" => $hoje], "volta numa saída"], [["posse" => "vendido", "saida_destino" => str_repeat("x", 121)], "destino comprido demais"]] as [$pedido, $caso]) {
        $r = op_relogio("salvar", array_merge(["id" => $a["id"]], $pedido));
        exigir_saidas(!$r["ok"], "Devia recusar: " . $caso . ".");
    }
    exigir_saidas($todas($a["id"]) === 0 && linha("SELECT posse FROM relogio WHERE id = ?", [$a["id"]])["posse"] === "colecao", "O pedido recusado não grava nada.");

    // ---- vender: a saída abre com a data, o valor e para quem; o relógio sai do rodízio
    $r = op_relogio("salvar", ["id" => $a["id"], "posse" => "vendido", "saida_data" => $ontem, "saida_valor" => "1.500,50", "saida_destino" => "Fulano"]);
    exigir_saidas(!$r["ok"], "1.500,50 não é um número que a API aceita (é 1500,50): devia recusar.");
    $r = op_relogio("salvar", ["id" => $a["id"], "posse" => "vendido", "saida_data" => $ontem, "saida_valor" => "1500,50", "saida_destino" => "Fulano", "saida_observacao" => "pelo fórum"]);
    exigir_saidas($r["ok"] && strpos($r["mensagem"], "Fulano") !== false, "Não vendeu: " . json_encode($r));
    $s = saida_aberta($a["id"]);
    exigir_saidas($s !== null && $s["situacao"] === "vendido" && $s["data"] === $ontem && (float)$s["valor"] === 1500.5 && $s["destino"] === "Fulano" && $s["observacao"] === "pelo fórum",
        "A saída aberta não é a da venda: " . json_encode($s));
    exigir_saidas((int)valor("SELECT disponivel FROM relogio WHERE id = ?", [$a["id"]]) === 0, "Vendido, sai do rodízio.");
    exigir_saidas($aba("colecao")["quantos"] === 2 && $aba("vendido")["quantos"] === 1 && $aba("vendido")["tipo"] === "saidas", "As abas depois da venda: " . json_encode(abas_situacao()));

    // ---- as contas: o resultado (recebido menos a compra e os serviços) e o tempo na coleção (da compra à saída)
    $l = saidas_lista(["relogio" => $a["id"]]);
    exigir_saidas(count($l) === 1 && $l[0]["aberta"] && $l[0]["compra_valor"] === 1000.0 && $l[0]["resultado"] === 500.5 && $l[0]["tempo_na_colecao"] === 29 && $l[0]["dias"] === 1,
        "As contas da saída: " . json_encode($l));
    $somas = saidas_somas(saidas_lista());
    exigir_saidas(count($somas) === 1 && $somas[0]["situacao"] === "vendido" && $somas[0]["quantas"] === 1 && $somas[0]["valor"] === 1500.5 && $somas[0]["resultado"] === 500.5, "As somas: " . json_encode($somas));

    // ---- salvar de novo sem mexer na situação não cria outra saída; só o que vier muda
    $r = op_relogio("salvar", ["id" => $a["id"], "nome" => "A vendido"]);
    exigir_saidas($r["ok"] && $todas($a["id"]) === 1 && saida_aberta($a["id"])["destino"] === "Fulano", "Renomear não mexe na saída.");
    $r = op_relogio("salvar", ["id" => $a["id"], "saida_destino" => "Beltrano", "saida_valor" => ""]);
    $s = saida_aberta($a["id"]);
    exigir_saidas($r["ok"] && $todas($a["id"]) === 1 && $s["destino"] === "Beltrano" && $s["valor"] === null && $s["data"] === $ontem, "Corrigir a saída pelo cadastro: " . json_encode($s));
    exigir_saidas(saidas_lista(["relogio" => $a["id"]])[0]["resultado"] === null, "Sem valor recebido, sem resultado.");

    // ---- trocar o motivo noutro dia: a de antes fecha no dia em que a nova começa
    $r = op_relogio("salvar", ["id" => $a["id"], "posse" => "presenteado"]);
    exigir_saidas($r["ok"] && $todas($a["id"]) === 2 && $abertas($a["id"]) === 1, "De vendido para presenteado: duas saídas, uma aberta. " . json_encode($r));
    $h = saidas_mapa()[$a["id"]];
    exigir_saidas($h[0]["situacao"] === "presenteado" && $h[0]["data"] === $hoje && $h[1]["situacao"] === "vendido" && $h[1]["fim"] === $hoje, "A ordem do histórico: " . json_encode($h));
    // ---- trocar o motivo no mesmo dia: é uma correção, a mesma saída
    $r = op_relogio("salvar", ["id" => $a["id"], "posse" => "arquivado"]);
    exigir_saidas($r["ok"] && $todas($a["id"]) === 2 && saida_aberta($a["id"])["situacao"] === "arquivado", "No mesmo dia, a saída só muda de motivo.");

    // ---- voltar para a coleção: a aberta fecha e fica no histórico; ele volta disponível
    $r = op_relogio("salvar", ["id" => $a["id"], "posse" => "colecao"]);
    exigir_saidas($r["ok"] && $abertas($a["id"]) === 0 && $todas($a["id"]) === 2 && (int)valor("SELECT disponivel FROM relogio WHERE id = ?", [$a["id"]]) === 1, "A volta: " . json_encode($r));
    exigir_saidas($aba("arquivado") === null && $aba("colecao")["quantos"] === 3, "A aba vazia some: " . json_encode(abas_situacao()));

    // ---- emprestar: é uma saída (com quem está), e ele continua da coleção; devolver com a data da volta
    $r = op_relogio("salvar", ["id" => $b["id"], "posse" => "emprestado", "saida_destino" => "Meu irmão", "saida_data" => $mes]);
    exigir_saidas($r["ok"] && saida_aberta($b["id"])["destino"] === "Meu irmão" && da_colecao(linha("SELECT * FROM relogio WHERE id = ?", [$b["id"]])), "O empréstimo: " . json_encode($r));
    exigir_saidas($aba("emprestado")["quantos"] === 1 && $aba("emprestado")["tipo"] === "relogios", "A aba do emprestado é a tabela de sempre.");
    $r = op_relogio("salvar", ["id" => $b["id"], "posse" => "colecao", "volta_data" => $ontem]);
    $h = saidas_mapa()[$b["id"]];
    exigir_saidas($r["ok"] && count($h) === 1 && $h[0]["fim"] === $ontem && saida_dados($h[0])["dias"] === 29, "A devolução com a data: " . json_encode($h));

    // ---- o relógio que já nasce fora: a saída abre sem data (ninguém sabe quando), ou com a que o pedido disser
    $d = op_relogio("salvar", ["nome" => "D", "no_id" => $grupo, "posse" => "vendido"]);
    exigir_saidas($d["ok"] && saida_aberta($d["id"])["data"] === null, "Nasce vendido, sem data.");
    $e = op_relogio("salvar", ["nome" => "E", "no_id" => $grupo, "posse" => "vendido", "saida_data" => $mes, "saida_valor" => "200"]);
    exigir_saidas($e["ok"] && saida_aberta($e["id"])["data"] === $mes, "Nasce vendido, com a data do pedido.");
    // ---- o desejado não é saída: não abre nada; comprado, entra na coleção sem saída
    $w = op_relogio("salvar", ["nome" => "W", "no_id" => $grupo, "posse" => "desejado"]);
    exigir_saidas($w["ok"] && $todas($w["id"]) === 0 && $aba("desejado")["tipo"] === "desejos", "O desejado não tem saída.");
    $r = op_relogio("salvar", ["id" => $w["id"], "posse" => "colecao"]);
    exigir_saidas($r["ok"] && $todas($w["id"]) === 0, "Comprado, sem saída.");

    // ---- o recurso=saidas: corrige e exclui só o histórico
    $fechada = (int)valor("SELECT id FROM relogio_saida WHERE relogio_id = ? AND fim IS NOT NULL ORDER BY id", [$a["id"]]);
    $aberta_d = (int)saida_aberta($d["id"])["id"];
    exigir_saidas(!op_saidas("salvar", ["id" => 999999, "valor" => "1"])["ok"], "Saída que não existe.");
    exigir_saidas(!op_saidas("salvar", ["id" => $aberta_d, "fim" => $hoje])["ok"], "A de agora não se fecha por aqui.");
    exigir_saidas(!op_saidas("salvar", ["id" => $fechada, "fim" => ""])["ok"], "A do histórico não perde a volta.");
    exigir_saidas(!op_saidas("salvar", ["id" => $fechada, "data" => $hoje, "fim" => $ontem])["ok"], "A volta antes da saída.");
    exigir_saidas(!op_saidas("excluir", ["id" => $aberta_d])["ok"], "A de agora não se exclui.");
    $r = op_saidas("salvar", ["id" => $aberta_d, "data" => $mes, "valor" => "350", "destino" => "Loja", "observacao" => ""]);
    $s = saida_aberta($d["id"]);
    exigir_saidas($r["ok"] && $s["data"] === $mes && (float)$s["valor"] === 350.0 && $s["destino"] === "Loja" && $s["observacao"] === null, "Corrigir a saída de agora: " . json_encode($s));
    exigir_saidas(op_saidas("excluir", ["id" => $fechada])["ok"] && $todas($a["id"]) === 1, "Excluir uma do histórico.");

    // ---- as listas com filtro: abertas, por situação, por relógio
    $todas_l = saidas_lista();
    $abertas_l = saidas_lista(["abertas" => true]);
    exigir_saidas(count($abertas_l) === 2 && count($todas_l) === 4 && $todas_l[0]["aberta"] && !$todas_l[3]["aberta"], "As de agora primeiro: " . json_encode(array_column($todas_l, "aberta")));
    exigir_saidas(count(saidas_lista(["situacao" => "emprestado"])) === 1 && count(saidas_lista(["situacao" => "vendido", "abertas" => true])) === 2, "O filtro por situação.");

    // ---- o item da lista em uso: o que o histórico cita não sai (o Presenteado ninguém usa; o Emprestado está no histórico do B)
    $raiz = (int)banco_escopo_atual()["raiz"];
    $itens = lista_itens("relogio_situacao");
    exigir_saidas(lista_item_em_uso(array_merge($itens["emprestado"], ["lista" => "relogio_situacao"]), $raiz) === 1, "O Emprestado está em uso pelo histórico.");
    exigir_saidas(lista_item_em_uso(array_merge($itens["vendido"], ["lista" => "relogio_situacao"]), $raiz) === 2, "O Vendido está em uso por dois relógios.");
    exigir_saidas(lista_item_em_uso(array_merge($itens["presenteado"], ["lista" => "relogio_situacao"]), $raiz) === 0, "O Presenteado não está em uso.");

    // ---- excluir o relógio leva as saídas dele
    op_relogio("excluir", ["id" => $e["id"]]);
    exigir_saidas($todas($e["id"]) === 0, "As saídas saem com o relógio.");

    // ---- o passo da migração: quem já estava fora (ou emprestado) sem saída ganha a aberta, sem data; rodar de novo não repete
    sql("DELETE FROM relogio_saida");
    sql("UPDATE relogio SET posse = 'emprestado' WHERE id = ?", [$c["id"]]);
    sem_escopo(function () { sql("DELETE FROM sistema WHERE chave = 'migracao_v64'"); });
    migracao_v64_dados(false);
    migracao_v64_dados(false);
    banco_escopo($master["colecao_id"]);
    exigir_saidas((int)valor("SELECT COUNT(*) FROM relogio_saida") === 2 && saida_aberta($c["id"])["situacao"] === "emprestado" && saida_aberta($c["id"])["data"] === null
        && saida_aberta($d["id"])["situacao"] === "vendido" && $todas($a["id"]) === 0, "O passo da migração: " . json_encode(linhas("SELECT relogio_id, situacao, data, fim FROM relogio_saida")));
    exigir_saidas(count(migracoes_pendentes()) === 0, "A v64 ficou pendente depois do passo.");

    echo "Saídas da coleção: abrir, corrigir, trocar de motivo, voltar, as contas, o histórico, as abas por situação e o passo da migração conferem.\n";
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
