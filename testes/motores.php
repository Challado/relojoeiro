<?php
// Regressões dos pesos extremos e das unidades/precisão da previsão. Banco SQLite novo, sem dados pessoais.
// php testes/motores.php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-motores-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700, true);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_DB=sqlite");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_SEMENTE=42");
require dirname(__DIR__) . "/lib.php";

function exigir_motor($vale, $mensagem)
{
    if (!$vale) throw new RuntimeException($mensagem);
}

try {
    exigir_motor(instalar_banco()["ok"], "Não instalou o banco descartável.");
    $u = op_usuarios("salvar", ["login"=>"teste", "senha"=>"Senha@123", "senha_confirmacao"=>"Senha@123"], null);
    exigir_motor($u["ok"], "Não criou a coleção de teste.");
    usuario_definir(sem_escopo(function () use ($u) { return linha("SELECT * FROM usuario WHERE id = ?", [$u["usuario_id"]]); }));
    banco_escopo($u["colecao_id"]);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    foreach ([1, 2] as $id) {
        sql("INSERT INTO relogio (id, colecao_id, no_id, nome, criado) VALUES (?, ?, ?, ?, NOW())", [$id, colecao_atual(), $grupo, "Teste " . $id]);
    }
    $rels = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY id") as $r) $rels[(int)$r["id"]] = $r;
    $roteiro = roteiro_completo(roteiro_ler("candidato = 1\nnota = 1\npeso = preferencia\ngarantia = 0\ndias = 1")["arvores"]);
    $campo = (int)valor("SELECT id FROM campo WHERE identificador = 'preferencia'");
    $dia = new DateTimeImmutable("2026-10-27");
    $pesos = function ($a, $b) use ($campo) {
        sql("DELETE FROM campo_valor WHERE campo_id = ?", [$campo]);
        sql("INSERT INTO campo_valor (relogio_id, campo_id, valor) VALUES (1, ?, ?)", [$campo, $a]);
        sql("INSERT INTO campo_valor (relogio_id, campo_id, valor) VALUES (2, ?, ?)", [$campo, $b]);
    };
    $contar = function ($vezes) use ($rels, $roteiro, $dia) {
        $n = [1=>0, 2=>0];
        for ($i = 0; $i < $vezes; $i++) {
            [$id] = escolha_do_dia(null, $dia, $dia->getTimestamp(), $rels, $roteiro, [], "", false);
            exigir_motor(isset($n[$id]), "Sorteio sem candidato válido.");
            $n[$id]++;
        }
        return $n;
    };
    cfg_set("sorteio_casas", "8");
    foreach ([["1e-300", "2e-300"], ["1e300", "2e300"]] as [$a, $b]) {
        $pesos($a, $b);
        $n = $contar(240);
        exigir_motor($n[2] >= 136 && $n[2] <= 188, "Os pesos 1:2 perderam a proporção: " . json_encode($n));
    }
    cfg_set("sorteio_casas", "0");
    $pesos("0.4", "0.6");
    exigir_motor($contar(20)[2] === 20, "Peso arredondado a zero participou do sorteio.");
    cfg_set("sorteio_casas", "2");
    $pesos("0", "100");
    exigir_motor($contar(20)[2] === 20, "Peso zero participou do sorteio.");

    cfg_set("uso_inicio", "08:00");
    cfg_set("uso_fim", "16:00");
    $momento = strtotime("2026-10-27 12:00:00");
    $ctx = ["r"=>$rels[1], "momento"=>$momento, "rastro"=>[], "pilha"=>[], "valores"=>[], "locais"=>[]];
    foreach (["energia"=>80, "limite_carga"=>20, "taxa_uso"=>12, "taxa_repouso"=>1] as $nome=>$v) $ctx["locais"][$nome] = formula_ler((string)$v);
    $agora = formula_calcular(formula_ler("AGORA()"), $ctx);
    // A atualização só troca a expressão padrão conhecida, e pode rodar mais de uma vez.
    $migracao = file_get_contents(dirname(__DIR__) . "/migracao_v49.sql");
    exigir_motor(preg_match("/AND expressao = '([^']+)'/", $migracao, $antiga) === 1, "Falta a proteção da expressão antiga na migração.");
    exigir_motor(preg_match("/UPDATE aviso SET expressao = '([^']+)'/", $migracao, $nova_v49) === 1, "Falta a expressão corrigida na v49.");
    $padrao = $nova_v49[1];
    sql("UPDATE aviso SET expressao = ? WHERE identificador = 'carregar'", [$antiga[1]]);
    sql("INSERT INTO aviso (identificador, nome, expressao, texto, no_id) VALUES ('carregar', 'Personalizado', 'AGORA() + 2', 'Teste', ?)", [no_raiz()]);
    banco_script($migracao);
    banco_script($migracao);
    exigir_motor(valor("SELECT expressao FROM aviso WHERE identificador = 'carregar' AND no_id = ?", [$grupo]) === $padrao, "A migração não corrigiu o aviso padrão.");
    exigir_motor(valor("SELECT expressao FROM aviso WHERE identificador = 'carregar' AND nome = 'Personalizado'") === "AGORA() + 2", "A migração alterou o aviso personalizado.");
    exigir_motor(sis("migracao_v49") === "1", "Falta a marca global da migração.");
    $aviso = formula_ler((string)valor("SELECT expressao FROM aviso WHERE identificador = 'carregar' ORDER BY id LIMIT 1"));
    $pulso = (int)valor("SELECT id FROM lancamento_tipo WHERE identificador = 'pulso'");
    sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, criado) VALUES (1, ?, '2026-10-27 08:00:00', NOW())", [$pulso]);
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    $limite = formula_calcular($aviso, $ctx);
    exigir_motor(abs(($limite - $agora) * 86400 - 40 * 3600) < 0.1, "A taxa por dia de uso não foi convertida em dias civis: " . json_encode([$agora, $limite, formula_calcular(formula_ler("HORAS_USO()"), $ctx), formula_calcular(formula_ler('EM_SESSAO("pulso")'), $ctx)]));
    sql("DELETE FROM lancamento");
    $GLOBALS["FORMULAS_VERSAO"]++;
    $ctx["locais"]["taxa_repouso"] = formula_ler("0.1");
    exigir_motor(formula_calcular($aviso, $ctx) === null, "O aviso passou do horizonte de um ano.");
    $ctx["locais"]["taxa_repouso"] = formula_ler("0");
    exigir_motor(formula_calcular($aviso, $ctx) === null, "O aviso dividiu por gasto zero.");

    // Energia controlada de 80%, caindo dez pontos por dia: o limite de 20% chega em seis dias, ao meio-dia.
    $base = number_format($agora, 12, ".", "");
    sql("UPDATE formula SET expressao = ? WHERE identificador = 'energia' AND no_id = ?", ["80 - (AGORA() - " . $base . ") * 10", $grupo]);
    formulas_todas(true);
    $carga = (int)valor("SELECT id FROM lancamento_tipo WHERE identificador = 'carga'");
    sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, valor, criado) VALUES (1, ?, '2026-10-27 12:00:00', 80, NOW())", [$carga]);
    $GLOBALS["FORMULAS_VERSAO"]++;
    $previsao = previsao_energia($rels[1], $momento);
    exigir_motor($previsao["chega_limite_em"] !== null && abs(strtotime($previsao["chega_limite_em"]) - ($momento + 6 * 86400)) <= 60, "A previsão arredondou o instante do limite para dias inteiros.");
    echo "Pesos extremos, arredondamento, unidades, horizonte e minuto da previsão conferem.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
