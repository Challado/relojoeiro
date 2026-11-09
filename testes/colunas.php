<?php
// O motor de colunas e o tempo de uso: o formato "1d 4h 30m", a soma das sessões no pulso (sem contar duas vezes a mesma hora, sem
// contar o que ainda nem começou), o tempo de uso na linha da tabela e nos totais (a coluna, a variável e o formato Tempo) e a
// escolha das colunas da página Hoje (o conferidor, a ordem, as escondidas, a Relógio que não se esconde, uma coluna que sumiu de
// uma versão de antes e a herança sistema → usuário → coleção). Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-colunas-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_colunas($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

try {
    exigir_colunas(instalar_banco()["ok"], "Não instalou a base descartável.");
    $master = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_colunas($master["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($master) { return linha("SELECT * FROM usuario WHERE id = ?", [$master["usuario_id"]]); }));
    banco_escopo($master["colecao_id"]);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");

    // ---- o formato: só as partes que não são zero, 24 horas viram um dia, sem tempo nenhum, "—"
    foreach ([[0, "—"], [5, "5s"], [60, "1m"], [65, "1m 5s"], [1800, "30m"], [48600, "13h 30m"], [86400, "1d"], [86400 + 4 * 3600 + 1800, "1d 4h 30m"],
        [3 * 86400 + 3600 + 61, "3d 1h 1m 1s"], [24 * 3600 + 24 * 60, "1d 24m"]] as [$seg, $texto]) {
        exigir_colunas(duracao_uso_txt($seg) === $texto, "duracao_uso_txt(" . $seg . ") devia ser " . $texto . " e foi " . duracao_uso_txt($seg) . ".");
    }
    exigir_colunas(total_texto(48600, "duracao") === "13h 30m" && total_texto(null, "duracao") === "—", "O formato de total Tempo.");

    // ---- o tempo de uso: as sessões no pulso somadas
    $a = op_relogio("salvar", ["nome" => "A", "no_id" => $grupo, "disponivel" => 1]);
    $b = op_relogio("salvar", ["nome" => "B", "no_id" => $grupo, "disponivel" => 1]);
    $c = op_relogio("salvar", ["nome" => "C", "no_id" => $grupo, "disponivel" => 1]);
    exigir_colunas($a["ok"] && $b["ok"] && $c["ok"], "Não criou os relógios.");
    $tipo = (int)lancamento_tipos()["pulso"]["id"];
    $agora = time();
    $sessao = function ($rid, $de, $ate) use ($tipo, $agora) {
        sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, 'manual', NOW())",
            [$rid, $tipo, date("Y-m-d H:i:s", $agora + $de), date("Y-m-d H:i:s", $agora + $ate)]);
    };
    // A: 10h às 12h30 e 11h às 13h, três dias atrás (as duas juntas são 3 horas, não 4h30); e meia hora ontem
    $d3 = -3 * 86400;
    $sessao($a["id"], $d3, $d3 + 9000);
    $sessao($a["id"], $d3 + 3600, $d3 + 10800);
    $sessao($a["id"], -86400, -86400 + 1800);
    // B: uma sessão que ainda nem começou (amanhã) não conta; e uma de 1h30
    $sessao($b["id"], 86400, 86400 + 3600);
    $sessao($b["id"], -7200, -1800);
    $mapa = uso_total_mapa($agora);
    exigir_colunas(($mapa[(int)$a["id"]] ?? 0) === 10800 + 1800, "O tempo de uso de A devia ser 3h30 (e foi " . ($mapa[(int)$a["id"]] ?? 0) . " s).");
    exigir_colunas(($mapa[(int)$b["id"]] ?? 0) === 5400, "O tempo de uso de B devia ser 1h30 (a sessão de amanhã não conta; foi " . ($mapa[(int)$b["id"]] ?? 0) . " s).");
    exigir_colunas(!isset($mapa[(int)$c["id"]]), "C nunca foi ao pulso e não devia ter tempo.");
    // uma sessão que termina depois de agora conta só até agora
    $sessao($c["id"], -600, 3600);
    exigir_colunas((uso_total_mapa($agora)[(int)$c["id"]] ?? 0) === 600, "A sessão que passa de agora devia contar só até agora.");

    // ---- a linha da tabela e as variáveis dos totais
    $linhas = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY id") as $r) {
        $linhas[(int)$r["id"]] = [$r, tabela_linha($r, $agora, ["uso" => $mapa])];
    }
    $la = $linhas[(int)$a["id"]][1];
    exigir_colunas($la["uso"] === 12600 && $la["uso_texto"] === "3h 30m", "A linha de A: uso " . $la["uso"] . ", texto " . $la["uso_texto"] . ".");
    $lc = $linhas[(int)$c["id"]][1];
    exigir_colunas($lc["uso"] === 0 && $lc["uso_texto"] === "—", "A linha de C (sem uso) devia sair 0 e —.");
    exigir_colunas(tabela_variaveis($la, $linhas[(int)$a["id"]][0], $agora)["tempo_de_uso"] === 12600, "A variável tempo_de_uso.");
    exigir_colunas(isset($GLOBALS["VARIAVEIS_TABELA"]["tempo_de_uso"]) && isset(TOTAL_COLUNAS["uso"]) && isset(total_colunas_nomes()["uso"]) && isset(TOTAL_FORMATOS["duracao"]), "Faltam o tempo de uso no catálogo dos totais.");
    exigir_colunas(array_keys(TOTAL_COLUNAS) === colunas_catalogo("hoje"), "O catálogo das colunas da Hoje (as do sistema, que o modelo cria no cadastro) devia ser o das colunas dos totais, na mesma ordem.");

    // ---- um total no rodapé, embaixo do Tempo de uso, no formato Tempo
    $t = op_totais("salvar", ["nome" => "Tempo de uso somado", "coluna" => "uso", "expressao" => "SOMA(tempo_de_uso)", "formato" => "duracao", "rodape" => "1", "pagina" => "1"]);
    exigir_colunas($t["ok"], "Não criou o total na coluna Tempo de uso: " . implode("; ", $t["erros"] ?? []));
    $res = total_calcular("SOMA(tempo_de_uso)", "duracao", totais_relogios(null));
    exigir_colunas($res["tipo"] === "valor" && $res["texto"] === duracao_uso_txt(12600 + 5400 + 600), "O total SOMA(tempo_de_uso): " . json_encode($res, JSON_UNESCAPED_UNICODE));

    // ---- as colunas: sem escolha, todas, na ordem de sempre
    $todas = array_keys(TOTAL_COLUNAS);
    $da = colunas_da_pagina("hoje");
    exigir_colunas(array_column($da, "chave") === $todas && count(array_filter($da, function ($x) { return $x["visivel"]; })) === count($todas), "Sem escolha, todas as colunas à mostra, na ordem de sempre.");
    exigir_colunas(array_column($da, "posicao") === range(1, count($todas)) && $da[array_search("nome", $todas, true)]["fixa"] === true && $da[array_search("uso", $todas, true)]["titulo"] === "Tempo de uso",
        "As posições, a coluna fixa e o título do Tempo de uso.");
    exigir_colunas(array_search("uso", $todas, true) === array_search("ultimo", $todas, true) + 1, "O Tempo de uso vem logo depois da Última vez usado.");

    // ---- escolher: a ordem é a da lista, as escondidas vêm depois na ordem de sempre
    $r = op_config("salvar", ["colunas_hoje" => "nome,uso,marca"]);
    exigir_colunas($r["ok"] && cfg_cru("colunas_hoje") === "nome,uso,marca", "Não guardou a escolha das colunas: " . implode("; ", $r["erros"] ?? []));
    exigir_colunas(colunas_efetivas("hoje") === ["nome", "uso", "marca"], "As colunas que valem: nome, uso, marca.");
    $da = colunas_da_pagina("hoje");
    exigir_colunas(array_slice(array_column($da, "chave"), 0, 3) === ["nome", "uso", "marca"] && array_column(array_slice($da, 0, 3), "posicao") === [1, 2, 3]
        && count(array_filter($da, function ($x) { return $x["visivel"]; })) === 3, "As três escolhidas primeiro, na ordem, e as outras escondidas.");
    $escondidas = array_column(array_filter($da, function ($x) { return !$x["visivel"]; }), "chave");
    exigir_colunas($escondidas === array_values(array_diff($todas, ["nome", "uso", "marca"])) && array_unique(array_column(array_filter($da, function ($x) { return !$x["visivel"]; }), "posicao")) === [0],
        "As escondidas, na ordem de sempre, com a posição 0.");
    // aceita uma lista (a API manda colunas_hoje[]=...), com espaços
    exigir_colunas(op_config("salvar", ["colunas_hoje" => [" nome ", "valor", "codigo"]])["ok"] && cfg_cru("colunas_hoje") === "nome,valor,codigo", "Uma lista com espaços devia ser aceita e guardada limpa.");

    // ---- recusas: uma coluna que não existe, repetida, sem a Relógio; e nada muda
    foreach ([["nome,nao_existe", "não existe"], ["nome,uso,uso", "mais de uma vez"], ["uso,marca", "não se esconde"]] as [$ruim, $parte]) {
        $r = op_config("salvar", ["colunas_hoje" => $ruim]);
        exigir_colunas(!$r["ok"] && strpos(implode(" ", $r["erros"]), $parte) !== false && cfg_cru("colunas_hoje") === "nome,valor,codigo", "A lista \"" . $ruim . "\" devia ser recusada (" . $parte . "): " . json_encode($r["erros"], JSON_UNESCAPED_UNICODE));
    }
    // uma coluna que sumiu de uma versão de antes perde só ela, e a Relógio volta se faltar
    cfg_set("colunas_hoje", "velha,uso,marca");
    exigir_colunas(colunas_efetivas("hoje") === ["nome", "uso", "marca"], "Uma coluna que não existe mais sai, e a Relógio volta: " . json_encode(colunas_efetivas("hoje")));

    // ---- a herança: coleção → usuário → sistema → todas
    cfg_set("colunas_hoje", "");
    exigir_colunas(colunas_efetivas("hoje") === $todas, "Vazio na coleção sem nada acima: todas.");
    exigir_colunas(op_config("salvar", ["padroes" => ["colunas_hoje" => "nome,carga"]])["ok"] && colunas_efetivas("hoje") === ["nome", "carga"], "O padrão do sistema vale na coleção que não escolheu.");
    exigir_colunas(op_conta("alterar", ["opcoes" => ["colunas_hoje" => "nome,uso,estado"]])["ok"] && colunas_efetivas("hoje") === ["nome", "uso", "estado"], "A preferência do usuário prevalece sobre o sistema.");
    exigir_colunas(op_config("salvar", ["colunas_hoje" => "nome,valor"])["ok"] && colunas_efetivas("hoje") === ["nome", "valor"], "A escolha da coleção prevalece sobre as duas.");
    $lista = array_values(array_filter(opcoes_lista("colecao")["opcoes"], function ($o) { return $o["chave"] === "colunas_hoje"; }))[0];
    exigir_colunas($lista["tipo"] === "lista" && $lista["escolha"] === "nome,valor" && $lista["herdado"] === "nome,uso,estado" && $lista["origem_herdado"] === "usuario" && count($lista["valores"]) === count($todas)
        && $lista["valores"][array_search("uso", $todas, true)]["texto"] === "Tempo de uso", "A lista da opção na API: tipo, escolha, herdado e os valores com o título.");
    exigir_colunas(op_config("salvar", ["colunas_hoje" => ""])["ok"] && op_conta("alterar", ["opcoes" => ["colunas_hoje" => ""]])["ok"] && op_config("salvar", ["padroes" => ["colunas_hoje" => ""]])["ok"]
        && colunas_efetivas("hoje") === $todas, "Esvaziando os três níveis, voltam todas.");
    $vazia = array_values(array_filter(opcoes_lista("colecao")["opcoes"], function ($o) { return $o["chave"] === "colunas_hoje"; }))[0];
    exigir_colunas($vazia["herdado"] === implode(",", $todas) && $vazia["origem"] === "padrao", "Sem escolha em nenhum nível, a tela vê todas herdadas do padrão de fábrica.");

    echo "Colunas: o formato do tempo, a soma das sessões, a linha, os totais, as colunas escolhidas na ordem, as recusas e a herança em três níveis conferem.\n";
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
