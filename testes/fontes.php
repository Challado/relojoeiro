<?php
// As fontes de dados das colunas (fontes.php): o dicionário confere com o banco (toda tabela e todo campo existem, toda coluna do
// banco está no dicionário ou na lista dos que ficam de fora), cada função junta os registros do relógio como o SELECT ... GROUP BY
// relogio_id pede (soma, média, menor, maior, contagem; datas viram o dia), a variável fonte_* vale nas fórmulas e nos totais e a
// desconhecida é recusada. Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-fontes-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_fontes($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

try {
    exigir_fontes(instalar_banco()["ok"], "Não instalou a base descartável.");
    // 1) o dicionário confere com o banco
    foreach ($GLOBALS["FONTES_TABELAS"] as $tabela => [$desc_t, $campos]) {
        exigir_fontes(trim(t($desc_t)) !== "", "A tabela $tabela não tem descrição.");
        $no_banco = sql_colunas_da_tabela($tabela);
        exigir_fontes(count($no_banco) > 0, "A tabela $tabela não existe no banco.");
        exigir_fontes(in_array("relogio_id", $no_banco, true), "A tabela $tabela não tem relogio_id.");
        foreach ($campos as $campo => [$tipo, $desc, $un]) {
            exigir_fontes(isset(FONTES_FUNCOES_DO_TIPO[$tipo]), "$tabela.$campo: tipo $tipo desconhecido.");
            exigir_fontes(trim(t($desc)) !== "", "$tabela.$campo não tem descrição.");
            exigir_fontes($tipo === "registro" || in_array($campo, $no_banco, true), "$tabela.$campo não existe no banco.");
        }
        $conhecidos = array_merge(array_keys($campos), FONTES_FORA[$tabela] ?? []);
        foreach ($no_banco as $c) {
            exigir_fontes(in_array($c, $conhecidos, true), "A coluna $tabela.$c não está no dicionário (fontes.php) nem em FONTES_FORA: decida se ela pode ser uma coluna da tabela dos relógios.");
        }
        foreach (FONTES_FORA[$tabela] ?? [] as $c) {
            exigir_fontes(in_array($c, $no_banco, true), "FONTES_FORA lista $tabela.$c, que não existe no banco.");
            exigir_fontes(!isset($campos[$c]), "$tabela.$c está no dicionário e em FONTES_FORA.");
        }
    }
    // as tabelas do banco com relogio_id estão no dicionário ou numa lista de fora (as de ligação, as de configuração...)
    $sem_fonte = ["campo_valor", "campo_valor_item", "relogio_marca", "relogio_calibre", "winder_lugar", "winder_limite_ok", "acessorio_relogio", "modo_bloco_relogio", "evento_relogio"];
    foreach (banco_tabelas() as $tabela) {
        if (in_array($tabela, ["sql_execucao"], true) || isset($GLOBALS["FONTES_TABELAS"][$tabela]) || in_array($tabela, $sem_fonte, true)) {
            continue;
        }
        $cols = sql_colunas_da_tabela($tabela);
        exigir_fontes(!in_array("relogio_id", $cols, true), "A tabela $tabela tem relogio_id e não está no dicionário (fontes.php): inclua, ou liste aqui como de fora.");
    }

    // 2) as variáveis: tudo que o dicionário promete existe, com função que o tipo aceita
    $vars = fontes_variaveis();
    exigir_fontes(count($vars) > 50, "Poucas variáveis de fonte: " . count($vars));
    exigir_fontes(isset($vars["fonte_servico_mao_de_obra_soma"]) && isset($vars["fonte_lancamento_id_conta"]), "Faltam variáveis básicas.");
    exigir_fontes(!isset($vars["fonte_servico_descricao_soma"]) && !isset($vars["fonte_servico_data_soma"]), "Uma função que o tipo não aceita virou variável.");
    foreach (fontes_dicionario() as $t) {
        foreach ($t["campos"] as $c) {
            foreach ($c["funcoes"] as $f) {
                exigir_fontes(isset($vars[$f["variavel"]]), "A variável " . $f["variavel"] . " do dicionário não existe.");
                exigir_fontes(trim(fonte_variavel_descricao($t["tabela"], $c["chave"], $f["chave"])) !== "", "Sem descrição: " . $f["variavel"]);
            }
        }
    }

    // 3) os valores: três medições de timegrapher e três lançamentos de um relógio, outro relógio sem nada
    $master = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_fontes($master["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($master) { return linha("SELECT * FROM usuario WHERE id = ?", [$master["usuario_id"]]); }));
    banco_escopo($master["colecao_id"]);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $ra = op_relogio("salvar", ["nome" => "Um", "no_id" => $grupo, "disponivel" => 1]);
    $rb = op_relogio("salvar", ["nome" => "Dois", "no_id" => $grupo, "disponivel" => 1]);
    exigir_fontes($ra["ok"] && $rb["ok"], "Não criou os relógios.");
    $a = (int)$ra["id"];
    $b = (int)$rb["id"];
    foreach ([["2026-02-10 10:00:00", "4.5", 280], ["2026-03-05 09:00:00", "-2.5", null], ["2026-01-20 08:00:00", null, 300]] as [$q, $marcha, $amp]) {
        sql("INSERT INTO timegrapher (relogio_id, quando, marcha, amplitude, origem, criado) VALUES (?, ?, ?, ?, 'manual', NOW())", [$a, $q, $marcha, $amp]);
    }
    $perto = function ($x, $y) { return $x !== null && abs($x - $y) < 0.0001; };
    exigir_fontes($perto(fonte_valor("fonte_timegrapher_marcha_soma", $a), 2.0), "A soma da marcha deveria ser 2: " . var_export(fonte_valor("fonte_timegrapher_marcha_soma", $a), true));
    exigir_fontes($perto(fonte_valor("fonte_timegrapher_marcha_media", $a), 1.0), "A média da marcha deveria ser 1 (o vazio não conta).");
    exigir_fontes($perto(fonte_valor("fonte_timegrapher_marcha_menor", $a), -2.5) && $perto(fonte_valor("fonte_timegrapher_marcha_maior", $a), 4.5), "O menor e o maior da marcha.");
    exigir_fontes(fonte_valor("fonte_timegrapher_id_conta", $a) === 3, "Eram 3 medições: " . var_export(fonte_valor("fonte_timegrapher_id_conta", $a), true));
    exigir_fontes(fonte_valor("fonte_timegrapher_marcha_conta", $a) === 2, "Só 2 medições têm marcha.");
    exigir_fontes(fonte_valor("fonte_timegrapher_id_conta", $b) === null && fonte_valor("fonte_timegrapher_marcha_soma", $b) === null, "O relógio sem medição devia dar vazio.");
    $dia = function ($d) { return dias_de(strtotime($d . " 00:00:00")); };
    exigir_fontes($perto(fonte_valor("fonte_timegrapher_quando_menor", $a), $dia("2026-01-20")) && $perto(fonte_valor("fonte_timegrapher_quando_maior", $a), $dia("2026-03-05")), "A data da primeira e da última medição (em dias).");
    exigir_fontes(fonte_valor("fonte_inexistente_x_soma", $a) === null, "A variável desconhecida devia dar vazio.");

    // 4) vale nas fórmulas e nos totais; a desconhecida é recusada antes do banco
    $conj = totais_relogios(null, "todos");
    $r = total_calcular("SOMA(fonte_timegrapher_marcha_soma)", "decimal", $conj);
    exigir_fontes($r["tipo"] === "valor" && $perto($r["valor"], 2.0), "O total sobre a fonte: " . json_encode($r));
    $r = total_calcular("MAIOR(fonte_timegrapher_id_conta)", "inteiro", $conj);
    exigir_fontes($r["tipo"] === "valor" && (int)$r["valor"] === 3, "O maior número de serviços: " . json_encode($r));
    $erros = total_validar("SOMA(fonte_servico_descricao_soma)");
    exigir_fontes(count($erros) > 0, "A fonte que não existe devia ser recusada.");
    exigir_fontes(count(relatorio_expressao_validar("fonte_timegrapher_marcha_soma / fonte_timegrapher_id_conta")) === 0, "A conta entre fontes vale num relatório.");
    echo "As fontes de dados conferem: o dicionário com o banco, as funções (soma, média, menor, maior, contagem), as datas, o vazio e as fórmulas e totais.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    @array_map("unlink", glob($pasta . "/*") ?: []);
    @rmdir($pasta);
}
