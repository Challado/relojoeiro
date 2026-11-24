<?php
// A trava do cron quando a primeira consulta ao banco trava: a rodada que pega a trava grava nela o PID, o início e a regra de
// quantas rodadas esperar antes de qualquer consulta. Com o banco preso por outro processo (a primeira consulta da rodada
// fica esperando), a rodada seguinte acha na trava o processo e a regra configurada (cron_espera_rodadas = 1, lida pela
// rodada anterior), mata a travada e roda no lugar dela, com o erro no registro. Na v67, a trava ficava vazia enquanto a
// primeira consulta esperava, e as rodadas seguintes saíam sem rodar para sempre (processo 0, nunca mata). Base SQLite nova e
// descartável, com a trava numa pasta própria.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
$pasta = sys_get_temp_dir() . "/relojoeiro-cron-trava-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
// o config de teste com a trava nesta pasta (CRON_TRAVA)
$cfg = $pasta . "/config.php";
file_put_contents($cfg, "<?php\ndefine('CRON_TRAVA', " . var_export($pasta . "/cron.trava", true) . ");\nrequire " . var_export(__DIR__ . "/config.teste.php", true) . ";\n");
putenv("RELOGIOS_CONFIG=" . $cfg);
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
// este processo não abre o banco (a conexão dele impediria o outro de prender o banco): a instalação roda num processo à parte,
// e a conferência do fim lê o arquivo do SQLite direto
$banco_arquivo = $pasta . "/sqlite.sqlite";

function exigir_trava($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

$processos = [];
$rodar = function ($args, $saida_arquivo) use ($raiz, $cfg, $pasta, &$processos) {
    $env = ["RELOGIOS_CONFIG" => $cfg, "RELOGIOS_TESTE_PASTA" => $pasta, "RELOGIOS_DB" => "sqlite", "PATH" => getenv("PATH")];
    $p = proc_open(array_merge([PHP_BINARY], $args), [0 => ["pipe", "r"], 1 => ["file", $saida_arquivo, "a"], 2 => ["file", $saida_arquivo, "a"]], $t, $raiz, $env);
    $processos[] = $p;
    return $p;
};
try {
    $instala = $pasta . "/instala.php";
    file_put_contents($instala, '<?php require ' . var_export($raiz . "/lib.php", true) . '; if (!instalar_banco()["ok"]) { exit(1); }'
        . ' if (!op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null)["ok"]) { exit(2); } sis_set("cron_espera_rodadas", "1");'
        . ' echo DB_ARQUIVO;');
    $pi = $rodar([$instala], $pasta . "/instala.log");
    exigir_trava(proc_close($pi) === 0, "Não instalou a base descartável: " . @file_get_contents($pasta . "/instala.log"));
    $banco_arquivo = trim((string)file_get_contents($pasta . "/instala.log"));
    // ---- uma rodada normal: deixa na trava a regra lida do banco (1)
    $p0 = $rodar([$raiz . "/cron.php"], $pasta . "/r0.log");
    proc_close($p0);
    $trava0 = json_decode((string)file_get_contents($pasta . "/cron.trava"), true);
    exigir_trava(is_array($trava0) && ($trava0["limite"] ?? null) === 1, "A rodada normal não deixou a regra lida do banco na trava: " . json_encode($trava0));

    // ---- o banco preso por outro processo: a primeira consulta da rodada seguinte fica esperando
    $prende = $pasta . "/prende.php";
    file_put_contents($prende, '<?php $c = new SQLite3(' . var_export($banco_arquivo, true) . '); $c->busyTimeout(5000); $c->exec("PRAGMA locking_mode = EXCLUSIVE"); $c->exec("BEGIN EXCLUSIVE");'
        . ' $c->exec("CREATE TABLE IF NOT EXISTS teste_prende (x INTEGER)"); $c->exec("INSERT INTO teste_prende (x) VALUES (1)"); file_put_contents(' . var_export($pasta . "/preso", true) . ', "1"); sleep(8); $c->exec("COMMIT");');
    $pp = $rodar([$prende], $pasta . "/prende.log");
    for ($i = 0; $i < 50 && !is_file($pasta . "/preso"); $i++) {
        usleep(100000);
    }
    exigir_trava(is_file($pasta . "/preso"), "Não consegui prender o banco.");
    // a rodada que vai travar na primeira consulta
    $p1 = $rodar([$raiz . "/cron.php"], $pasta . "/r1.log");
    $pid1 = proc_get_status($p1)["pid"];
    // ela grava o PID antes de consultar o banco
    $trava1 = null;
    for ($i = 0; $i < 50; $i++) {
        $trava1 = json_decode((string)@file_get_contents($pasta . "/cron.trava"), true);
        if (is_array($trava1) && (int)($trava1["pid"] ?? 0) !== (int)($trava0["pid"] ?? 0)) {
            break;
        }
        usleep(100000);
    }
    exigir_trava(is_array($trava1) && (int)$trava1["pid"] === (int)$pid1 && ($trava1["limite"] ?? null) === 1 && ($trava1["inicio"] ?? "") !== "",
        "Com a primeira consulta presa, a trava não tem o PID, o início e a regra: " . json_encode($trava1) . " (pid " . $pid1 . ")");
    usleep(500000);
    exigir_trava(proc_get_status($p1)["running"], "A rodada devia estar presa na primeira consulta.");
    // ---- a rodada seguinte: acha o processo, conta o pulo (1, o limite) e mata a travada
    $p2 = $rodar([$raiz . "/cron.php"], $pasta . "/r2.log");
    $fim = time() + 40;
    while (time() < $fim && proc_get_status($p1)["running"]) {
        usleep(200000);
    }
    exigir_trava(!proc_get_status($p1)["running"], "A rodada seguinte não matou a que travou na primeira consulta.");
    // a seguinte roda no lugar dela (quando o banco solta) e registra o erro
    while (time() < $fim && proc_get_status($p2)["running"]) {
        usleep(200000);
    }
    exigir_trava(!proc_get_status($p2)["running"], "A rodada que matou a travada não terminou.");
    proc_close($pp);
    $db = new SQLite3($banco_arquivo);
    $db->busyTimeout(5000);
    $reg = (string)$db->querySingle("SELECT registro FROM cron_execucao ORDER BY id DESC LIMIT 1");
    $db->close();
    exigir_trava(strpos($reg, (string)$pid1) !== false, "O registro da rodada não diz que matou a travada (processo " . $pid1 . "): " . $reg);
    echo "A trava do cron: o PID, o início e a regra gravados antes da primeira consulta; travada nela, a rodada seguinte a acha e mata.\n";
    $saida = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
    foreach (glob($pasta . "/*.log") as $l) {
        fwrite(STDERR, basename($l) . ": " . file_get_contents($l) . "\n");
    }
    $saida = 1;
} finally {
    foreach ($processos as $p) {
        if (is_resource($p) && proc_get_status($p)["running"]) {
            proc_terminate($p, 9);
        }
    }
    exec("rm -rf " . escapeshellarg($pasta));
}
exit($saida);
