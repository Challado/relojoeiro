<?php
// Regressões da descarga: calibração fixa, sessões, recarga no bloco, dados ausentes e saída JSON.
// Banco novo e descartável; não lê a configuração da instalação. php testes/previsao.php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-previsao-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_previsao($ok, $mensagem)
{
    if (!$ok) throw new RuntimeException($mensagem);
}
function alterar_previsao($nome, $expressao)
{
    global $grupo;
    sql("UPDATE formula SET expressao = ? WHERE identificador = ? AND no_id = ?", [$expressao, $nome, $grupo]);
    formulas_todas(true);
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
}
function limpar_previsao()
{
    sql("DELETE FROM medicao");
    sql("DELETE FROM lancamento");
    sql("DELETE FROM plano");
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
}
function lancar_previsao($tipo, $inicio, $fim = null, $valor = null)
{
    sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, estado, criado) VALUES (1, ?, ?, ?, ?, ?, NOW())",
        [(int)valor("SELECT id FROM lancamento_tipo WHERE identificador = ?", [$tipo]), date("Y-m-d H:i:s", $inicio),
            $fim === null ? null : date("Y-m-d H:i:s", $fim), $valor, $tipo === "carga" ? "repouso" : null]);
    $id = ultimo_id();
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    return $id;
}
function contexto_previsao()
{
    global $r, $agora;
    return ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio(1)];
}
try {
    exigir_previsao(instalar_banco()["ok"], "Não instalou o cenário descartável.");
    exigir_previsao(sis("migracao_v50") === "1", "A instalação nova deixou a v50 pendente.");
    $u = op_usuarios("salvar", ["login" => "teste", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_previsao($u["ok"], "Não criou a coleção.");
    usuario_definir(sem_escopo(function () use ($u) { return linha("SELECT * FROM usuario WHERE id = ?", [$u["usuario_id"]]); }));
    banco_escopo($u["colecao_id"]);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    sql("INSERT INTO relogio (id, colecao_id, no_id, nome, criado) VALUES (1, ?, ?, 'Teste', NOW())", [colecao_atual(), $grupo]);
    $r = linha("SELECT * FROM relogio WHERE id = 1");
    $agora = strtotime("2020-06-15 12:00:00");
    cfg_set("uso_inicio", "08:00"); cfg_set("uso_fim", "16:00");
    cfg_set("medicao_janela_dias", "90"); cfg_set("medicao_base", "dias");
    $padroes = [];
    foreach (linhas("SELECT identificador, expressao FROM formula WHERE no_id = ?", [$grupo]) as $f) $padroes[$f["identificador"]] = $f["expressao"];
    // A correção não muda a precedência do motor: coleção, relógio, grupo e padrão.
    cfg_set("gasto_motor", ""); $herdado = gasto_motor_de($r);
    $campo_motor = (int)valor("SELECT id FROM campo WHERE identificador = 'gasto_motor' ORDER BY id LIMIT 1");
    sql("INSERT INTO campo_valor (relogio_id, campo_id, valor) VALUES (1, ?, 'marcacao')", [$campo_motor]);
    exigir_previsao(gasto_motor_de($r) === ["marcacao", "relogio"], "O motor do relógio não prevaleceu sobre o herdado.");
    cfg_set("gasto_motor", "robusto");
    exigir_previsao(gasto_motor_de($r) === ["robusto", "colecao"], "O motor da coleção não prevaleceu sobre o do relógio.");
    cfg_set("gasto_motor", ""); sql("DELETE FROM campo_valor WHERE campo_id = ?", [$campo_motor]);
    exigir_previsao(gasto_motor_de($r) === $herdado, "O motor herdado não voltou.");
    $ctx = contexto_previsao();
    foreach (["10 ^ 1000", "0 ^ (0 - 1)", "(10 ^ 308) * 10"] as $expr) {
        exigir_previsao(formula_calcular(formula_ler($expr), $ctx) === null, "O estouro não virou vazio: " . $expr);
    }
    exigir_previsao(formula_calcular(formula_ler("PADRAO(10 ^ 1000; 7)"), $ctx) === 7.0, "O fallback não tratou o estouro.");
    exigir_previsao(campo_para_conta(["tipo" => "decimal"], "1e309") === null, "Um campo não finito entrou na conta.");
    // Mesmo um resultado inválido que escape do motor recebe erro JSON, nunca corpo vazio com HTTP 200.
    $filho = 'define("RELOJOEIRO_API", true); $formato="json"; function t($s){return $s;} require '
        . var_export(dirname(__DIR__) . "/api/funcoes.php", true)
        . '; ob_start(); responde(200,["valor"=>INF],"json"); $s=ob_get_clean(); echo json_encode(["status"=>http_response_code(),"saida"=>json_decode($s,true,512,JSON_THROW_ON_ERROR)]);';
    $pr = proc_open([PHP_BINARY, "-r", $filho], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
    fclose($pipes[0]); $texto = stream_get_contents($pipes[1]); fclose($pipes[1]); $erro = stream_get_contents($pipes[2]); fclose($pipes[2]);
    exigir_previsao(proc_close($pr) === 0, "Falhou a resposta JSON: " . $erro);
    $json = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);
    exigir_previsao($json["status"] === 500 && isset($json["saida"]["erro"]), "O erro de serialização não respondeu JSON com HTTP 500.");
    define("RELOJOEIRO_API", true); $formato = "json"; require dirname(__DIR__) . "/api/funcoes.php";
    $avisos = [
        ["identificador" => "winder", "nome" => "Pôr no winder", "data" => "2020-06-16 12:00:01"],
        ["identificador" => "carregar", "nome" => "Carregar", "data" => "2020-06-16 12:00:40"],
        ["identificador" => "leitura", "nome" => "Informar a carga", "data" => "2020-06-14 08:00:00"],
    ];
    $ordem = manutencoes_da_ficha($avisos, "2020-06-15");
    $avisos[0]["data"] = "2020-06-16 12:00:59"; $avisos[1]["data"] = "2020-06-16 12:00:02";
    exigir_previsao(manutencoes_da_ficha(array_reverse($avisos), "2020-06-15") === $ordem, "Os segundos inverteram a lista de dias da ficha.");
    exigir_previsao($ordem[0]["data"] === "2020-06-15" && $ordem[1]["nome"] === "Carregar", "A lista diária não preservou a data exibida e o desempate.");
    // Dois avisos em instantes diferentes, mas no mesmo dia/horário da agenda: o empate usa a chave do evento.
    foreach (["a_agenda_teste" => "0.2", "z_agenda_teste" => "0.1"] as $ident => $fracao) {
        sql("INSERT INTO aviso (identificador, nome, expressao, texto, no_id, ativo, agenda) VALUES (?, ?, ?, 'Teste', ?, 1, 'janela')",
            [$ident, $ident, "HOJE() + 1 + " . $fracao, $grupo]);
    }
    avisos_todos(true);
    $agenda = ["janela_dias" => 3, "avisos" => ["a_agenda_teste" => ["envia" => true], "z_agenda_teste" => ["envia" => true]]];
    $eventos = agenda_desejada(hoje_da_colecao(), $agenda);
    exigir_previsao(array_column($eventos, "tipo") === ["a_agenda_teste", "z_agenda_teste"], "A agenda herdou a ordem dos segundos das previsões.");
    sql("UPDATE aviso SET expressao = 'HOJE() + 1 + 0.3' WHERE identificador = 'z_agenda_teste'"); avisos_todos(true);
    exigir_previsao(array_column(agenda_desejada(hoje_da_colecao(), $agenda), "chave") === array_column($eventos, "chave"), "A previsão inverteu eventos no mesmo horário da agenda.");
    // A lista do passaporte também deve manter o empate do minuto, mesmo se os segundos inverterem.
    $ordem_minuto = function () use ($r, $agora) {
        return array_column(array_values(array_filter(avisos_do_relogio($r, $agora), function ($a) {
            return in_array($a["identificador"], ["a_agenda_teste", "z_agenda_teste"], true);
        })), "identificador");
    };
    foreach ([[1, 40], [59, 2]] as $segundos) {
        foreach (["a_agenda_teste", "z_agenda_teste"] as $i => $ident) {
            sql("UPDATE aviso SET expressao = ? WHERE identificador = ?", ["HOJE() + 1 + (43200 + " . $segundos[$i] . ") / 86400", $ident]);
        }
        avisos_todos(true);
        exigir_previsao($ordem_minuto() === ["a_agenda_teste", "z_agenda_teste"], "Os segundos inverteram os próximos avisos no mesmo minuto.");
        [$codigo_p, $passaporte] = api_ler("passaporte", ["relogio" => 1]);
        $nomes_p = array_values(array_filter(array_column($passaporte["proximos_avisos"], "nome"), function ($nome) {
            return in_array($nome, ["a_agenda_teste", "z_agenda_teste"], true);
        }));
        exigir_previsao($codigo_p === 200 && $nomes_p === ["a_agenda_teste", "z_agenda_teste"], "O passaporte reordenou os avisos depois do motor.");
        [$codigo_r, $relatorio] = api_ler("relatorios", ["tipo" => "passaporte", "relogio" => 1]);
        $nomes_r = [];
        foreach ($relatorio["secoes"] as $secao) {
            if ($secao["titulo"] !== t("Próximos avisos")) continue;
            $nomes_r = array_values(array_intersect(array_column($secao["linhas"], 0), ["a_agenda_teste", "z_agenda_teste"]));
        }
        exigir_previsao($codigo_r === 200 && $nomes_r === $nomes_p, "O relatório mudou a ordem dos avisos do passaporte.");
    }
    sql("DELETE FROM aviso WHERE identificador IN ('a_agenda_teste', 'z_agenda_teste')"); avisos_todos(true);

    // Duas medições passadas: a antiga sai da janela em dez dias. Não há qualquer recarga futura.
    $campo = (int)valor("SELECT id FROM campo WHERE identificador = 'decaimento_repouso' ORDER BY id LIMIT 1");
    sql("INSERT INTO campo_valor (relogio_id, campo_id, valor) VALUES (1, ?, '0')", [$campo]);
    lancar_previsao("carga", $agora - 81 * 86400, null, 100);
    $antiga = lancar_previsao("carga", $agora - 80 * 86400, null, 0);
    lancar_previsao("carga", $agora - 3600, null, 21);
    $nova = lancar_previsao("carga", $agora, null, 21);
    foreach ([[$antiga, 100, 0, $agora - 81 * 86400, $agora - 80 * 86400, 24, 100], [$nova, 21, 21, $agora - 3600, $agora, 1, 0]] as [$lid, $de, $ate, $ini, $fim, $h, $taxa]) {
        sql("INSERT INTO medicao (lancamento_id, medida, taxa, de_valor, ate_valor, inicio, fim, horas_pulso, horas_guardado, peso_horas, usada, criado)
            VALUES (?, 'repouso', ?, ?, ?, ?, ?, 0, ?, ?, 1, NOW())", [$lid, $taxa, $de, $ate, date("Y-m-d H:i:s", $ini), date("Y-m-d H:i:s", $fim), $h, $h]);
    }
    foreach (["horas", "robusto", "marcacao", "formulas"] as $motor) {
        cfg_set("gasto_motor", $motor); $GLOBALS["FORMULAS_VERSAO"]++;
        $ctx = contexto_previsao(); $taxa = variavel_valor("taxa_repouso", $ctx);
        exigir_previsao($taxa > 0, "Falta a taxa do caso " . $motor);
        $p = previsao_energia($r, $agora);
        exigir_previsao(abs(strtotime($p["chega_limite_em"]) - ($agora + 86400 / $taxa)) <= 60, "Perdeu o primeiro limite no motor " . $motor);
        $t = quando_acaba($r, $agora, [], $agora + 3650 * 86400);
        exigir_previsao(abs($t - ($agora + 21 * 86400 / $taxa)) <= 60, "Perdeu o primeiro esgotamento no motor " . $motor);
        exigir_previsao(energia_projetada($r, $agora + 11 * 86400, $agora, valores_do_relogio(1)) === 0.0, "A energia subiu sem recarga no motor " . $motor);
        exigir_previsao($p["confianca"] !== "alta" && count($p["confianca_motivos"]) > 0, "Poucas medições ganharam confiança alta.");
    }
    // As funções de medição usadas pelas fórmulas personalizadas também mantêm a janela inicial.
    foreach (["LEITURAS", "QUEDA", "DIAS_MEDIDOS", "MEDIA_TAXA", "MEDIANA_TAXA", "ULTIMA_TAXA"] as $funcao) {
        $expr = formula_ler($funcao . ($funcao === "ULTIMA_TAXA" ? '("repouso")' : '("repouso"; 90)'));
        $ctx = contexto_previsao(); $antes = formula_calcular($expr, $ctx);
        $ctx["momento"] = $agora + 11 * 86400; $ctx["medicoes_em"] = $agora;
        exigir_previsao(formula_calcular($expr, $ctx) === $antes, "A função " . $funcao . " mudou a calibração durante a projeção.");
    }
    // Consultar o passado continua usando a janela histórica, não a calibração da projeção anterior.
    $ctx = contexto_previsao(); $ctx["momento"] = $agora - 82 * 86400;
    exigir_previsao(formula_calcular(formula_ler('MEDIDO("repouso")'), $ctx) === null, "A calibração vazou para uma consulta histórica.");

    limpar_previsao(); cfg_set("gasto_motor", "cadastro");
    alterar_previsao("taxa_uso", "12"); alterar_previsao("taxa_repouso", "1");
    lancar_previsao("carga", $agora, null, 80); lancar_previsao("pulso", $agora, $agora + 4 * 3600);
    $p = previsao_energia($r, $agora); $ctx = contexto_previsao();
    $aviso = (string)valor("SELECT expressao FROM aviso WHERE identificador = 'carregar' ORDER BY id LIMIT 1");
    $data_aviso = formula_calcular(formula_ler($aviso), $ctx);
    exigir_previsao(abs((strtotime($p["chega_limite_em"]) - $agora) - (54 * 86400 + 4 * 3600)) <= 60, "A projeção não respeitou o fim do pulso.");
    exigir_previsao(abs(($data_aviso - dias_de($agora)) * 86400 - (strtotime($p["chega_limite_em"]) - $agora)) <= 60, "Aviso e ficha usam premissas diferentes.");
    exigir_previsao(count(formula_validar("energia", "QUANDO_ENERGIA(20)")) > 0, "Não detectou o ciclo implícito da energia.");
    exigir_previsao(formula_calcular(formula_ler("QUANDO_ENERGIA(20; 0)"), $ctx) === null, "Aceitou horizonte inválido.");
    // Fim automático da sessão aberta também é um marco da busca.
    limpar_previsao(); lancar_previsao("carga", $agora, null, 80); lancar_previsao("pulso", $agora);
    sql("UPDATE lancamento_tipo SET fecha_as = '16:00' WHERE identificador = 'pulso'"); $GLOBALS["FORMULAS_VERSAO"]++;
    $p = previsao_energia($r, $agora);
    exigir_previsao(abs(strtotime($p["chega_limite_em"]) - ($agora + 54 * 86400 + 4 * 3600)) <= 60, "Ignorou o fechamento automático.");
    $GLOBALS["SIMULACAO"] = []; simular_lancamento(1, "pulso", $agora + 3600, $agora + 7200, null); $sim = $GLOBALS["SIMULACAO"];
    quando_acaba($r, $agora, [], $agora + 86400);
    exigir_previsao($GLOBALS["SIMULACAO"] === $sim, "A consulta apagou a simulação do chamador."); $GLOBALS["SIMULACAO"] = [];

    limpar_previsao(); alterar_previsao("taxa_uso", "24"); alterar_previsao("taxa_repouso", "0");
    lancar_previsao("carga", $agora, null, 100);
    for ($n = 1; $n <= 5; $n++) sql("INSERT INTO plano (colecao_id, data, relogio_id, origem, criado) VALUES (?, ?, 1, 'manual', NOW())", [colecao_atual(), date("Y-m-d", $agora + $n * 86400)]);
    $p = previsao_energia($r, $agora);
    exigir_previsao($p["recarga_no_bloco"] === true && $p["carga_na_entrada"] === 100, "Disse que uma carga cobre um bloco de 120%.");
    exigir_previsao(strpos(implode(" ", $p["linhas"]), "Dá conta.") === false, "O texto ainda diz que o bloco de 120% dá conta.");
    sql("DELETE FROM plano WHERE data = ?", [date("Y-m-d", $agora + 5 * 86400)]);
    $p = previsao_energia($r, $agora);
    exigir_previsao($p["recarga_no_bloco"] === false && $p["precisa"] === 96, "O bloco que cabe numa carga foi marcado errado.");
    limpar_previsao(); lancar_previsao("carga", $agora, null, 80);
    alterar_previsao("energia", "SE(AGORA() < " . number_format(dias_de($agora), 12, ".", "") . " + 1; 80; NADA())");
    $p = previsao_energia($r, $agora);
    exigir_previsao($p["chega_limite_em"] === null && strpos(implode(" ", $p["linhas"]), "mais de um ano") === false, "Dado ausente virou mais de um ano.");
    exigir_previsao($p["confianca"] === "baixa", "A projeção desconhecida ganhou confiança.");
    $autonomia = autonomia_do_relogio($r, $agora);
    exigir_previsao(strpos(json_encode($autonomia["motivos"]), "10 anos") === false, "A autonomia desconhecida virou mais de dez anos.");
    alterar_previsao("energia", $padroes["energia"]);

    // Taxa pequena não permite tratar 0,0001% como zero: isso adiantaria a descarga em mais de quatorze minutos.
    limpar_previsao(); alterar_previsao("taxa_repouso", "0.01");
    lancar_previsao("carga", $agora, null, 0.01);
    $fim_pequeno = quando_acaba($r, $agora, [], $agora + 2 * 86400);
    exigir_previsao($fim_pequeno !== null && abs($fim_pequeno - ($agora + 86400)) <= 1, "Descarga de taxa pequena fora do instante esperado: " . var_export($fim_pequeno === null ? null : $fim_pequeno - $agora, true));

    // Atualização idempotente: somente a expressão padrão conhecida muda.
    $migracao = file_get_contents(dirname(__DIR__) . "/migracao_v50.sql");
    exigir_previsao(preg_match("/AND expressao = '([^']+)'/", $migracao, $antiga) === 1, "Falta a proteção da expressão antiga.");
    $id_aviso = (int)valor("SELECT id FROM aviso WHERE identificador = 'carregar' ORDER BY id LIMIT 1");
    sql("UPDATE aviso SET expressao = ? WHERE id = ?", [$antiga[1], $id_aviso]);
    sql("INSERT INTO aviso (identificador, nome, expressao, texto, no_id) VALUES ('carregar', 'Personalizado', 'AGORA() + 2', 'Teste', ?)", [no_raiz()]);
    banco_script($migracao); banco_script($migracao);
    exigir_previsao(valor("SELECT expressao FROM aviso WHERE id = ?", [$id_aviso]) === $aviso, "A v50 não atualizou o padrão.");
    exigir_previsao(valor("SELECT expressao FROM aviso WHERE nome = 'Personalizado'") === "AGORA() + 2", "A v50 alterou a expressão personalizada.");
    echo "Descarga, calibração nos quatro motores medidos, sessões, aviso, recarga no bloco, confiança, JSON e migração conferem.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
