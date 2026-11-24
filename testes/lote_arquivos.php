<?php
// Os arquivos da pasta dos documentos só saem depois que o banco confirma: num lote tudo ou nada (recurso=lote, atomico=1),
// excluir um relógio (ou um winder, um calibre, um documento) e depois falhar outra operação desfaz o banco E deixa os
// arquivos onde estavam. O documento do teste existe só no disco (sem a cópia no banco), o caso em que perder o arquivo
// é perder o documento. Confere também que, com o lote gravado, os arquivos saem; e que a exclusão sozinha (fora do lote)
// continua apagando. Pela API de verdade (um php -S com a base SQLite nova e descartável).
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
$pasta = sys_get_temp_dir() . "/relojoeiro-lote-arquivos-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require $raiz . "/lib.php";

function exigir_lote($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

$servidor = null;
try {
    exigir_lote(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_lote($dono["ok"], "Não criou o master.");
    // o servidor da API, nesta base
    $porta = 18000 + random_int(0, 999);
    $env = ["RELOGIOS_CONFIG" => __DIR__ . "/config.teste.php", "RELOGIOS_TESTE_PASTA" => $pasta, "RELOGIOS_DB" => "sqlite", "PATH" => getenv("PATH")];
    $servidor = proc_open([PHP_BINARY, "-S", "127.0.0.1:" . $porta, "-t", $raiz], [0 => ["pipe", "r"], 1 => ["file", $pasta . "/servidor.log", "a"], 2 => ["file", $pasta . "/servidor.log", "a"]], $tubos, $raiz, $env);
    $api = function ($campos) use ($porta) {
        clearstatcache();
        $c = curl_init("http://127.0.0.1:" . $porta . "/api.php?formato=json");
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($campos), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-Api-Token: token-de-teste-123"], CURLOPT_TIMEOUT => 60]);
        $r = curl_exec($c);
        curl_close($c);
        clearstatcache();
        return json_decode((string)$r, true) ?? ["ok" => false, "erros" => ["resposta inválida: " . substr((string)$r, 0, 300)]];
    };
    // espera o servidor subir (no máximo 10 s)
    for ($i = 0; $i < 100 && @fsockopen("127.0.0.1", $porta) === false; $i++) {
        usleep(100000);
    }
    $g = (int)sem_escopo(function () { return valor("SELECT id FROM no WHERE nome = 'Smartwatch'"); });
    $cat = (int)sem_escopo(function () { return valor("SELECT id FROM documento_categoria ORDER BY ordem, id LIMIT 1"); });
    $docs = DOCUMENTOS_PASTA;
    // um relógio com um documento só no disco (copia_banco=0)
    $novo_relogio = function ($nome) use ($api, $g, $cat) {
        $r = $api(["recurso" => "relogio", "acao" => "salvar", "nome" => $nome, "no_id" => $g, "disponivel" => 1]);
        exigir_lote(!empty($r["ok"]), "Não criou o relógio " . $nome . ": " . implode(" ", $r["erros"] ?? []));
        $doc = $api(["recurso" => "documentos", "acao" => "enviar", "relogio_id" => $r["id"], "categoria_id" => $cat, "copia_banco" => "0",
            "arquivo_base64" => base64_encode("o manual do " . $nome), "arquivo_nome" => "manual.txt"]);
        exigir_lote(!empty($doc["ok"]), "Não guardou o documento do " . $nome . ": " . implode(" ", $doc["erros"] ?? []));
        $linha = sem_escopo(function () use ($doc) { return linha("SELECT arquivo, copia_banco FROM documento WHERE id = ?", [(int)$doc["id"]]); });
        exigir_lote((int)$linha["copia_banco"] === 0 && (int)sem_escopo(function () use ($doc) { return valor("SELECT COUNT(*) FROM documento_parte WHERE documento_id = ?", [(int)$doc["id"]]); }) === 0,
            "O documento do teste devia existir só no disco.");
        return [(int)$r["id"], (int)$doc["id"], $linha["arquivo"]];
    };

    // ---- 1. o lote que exclui o relógio e depois falha: o relógio volta, e o arquivo nunca saiu do disco
    [$rid, $did, $arq] = $novo_relogio("Lote desfeito");
    exigir_lote(is_file($docs . "/" . $arq), "O arquivo não foi para a pasta.");
    $lote = $api(["recurso" => "lote", "atomico" => "1", "operacoes" => json_encode([
        ["recurso" => "relogio", "acao" => "excluir", "id" => $rid, "confirmar" => "1"],
        ["recurso" => "relogio", "acao" => "salvar", "id" => 999999, "nome" => ""],
    ])]);
    exigir_lote(empty($lote["ok"]) && !empty($lote["desfeito"]), "O lote devia falhar e ser desfeito: " . json_encode($lote));
    exigir_lote((int)sem_escopo(function () use ($rid) { return valor("SELECT COUNT(*) FROM relogio WHERE id = ?", [$rid]); }) === 1, "O lote desfeito não trouxe o relógio de volta.");
    exigir_lote((int)sem_escopo(function () use ($did) { return valor("SELECT COUNT(*) FROM documento WHERE id = ?", [$did]); }) === 1, "O lote desfeito não trouxe o documento de volta.");
    exigir_lote(is_file($docs . "/" . $arq), "O lote foi desfeito, mas o arquivo do documento sumiu da pasta (" . $arq . ").");
    exigir_lote(is_dir($docs . "/r" . $rid), "O lote foi desfeito, mas a pasta do relógio sumiu.");

    // ---- 2. o mesmo lote gravado: o relógio sai e os arquivos também
    $lote = $api(["recurso" => "lote", "atomico" => "1", "operacoes" => json_encode([
        ["recurso" => "relogio", "acao" => "excluir", "id" => $rid, "confirmar" => "1"],
    ])]);
    exigir_lote(!empty($lote["ok"]), "O lote da exclusão falhou: " . json_encode($lote));
    exigir_lote(!is_file($docs . "/" . $arq) && !is_dir($docs . "/r" . $rid), "O lote gravado não tirou os arquivos do relógio excluído.");

    // ---- 3. a exclusão sozinha (fora do lote) continua apagando na hora
    [$rid2, , $arq2] = $novo_relogio("Sozinho");
    $exc = $api(["recurso" => "relogio", "acao" => "excluir", "id" => $rid2, "confirmar" => "1"]);
    exigir_lote(!empty($exc["ok"]) && !is_file($docs . "/" . $arq2), "A exclusão fora do lote não tirou o arquivo: " . json_encode($exc));

    // ---- 4. as outras exclusões que tiram arquivos, num lote desfeito: o winder e o calibre (os documentos deles)
    $w = $api(["recurso" => "winders", "acao" => "salvar", "nome" => "Winder do teste"]);
    exigir_lote(!empty($w["ok"]), "Não criou o winder: " . implode(" ", $w["erros"] ?? []));
    $dw = $api(["recurso" => "documentos", "acao" => "enviar", "winder_id" => $w["id"], "categoria_id" => $cat, "copia_banco" => "0",
        "arquivo_base64" => base64_encode("o manual do winder"), "arquivo_nome" => "winder.txt"]);
    exigir_lote(!empty($dw["ok"]), "Não guardou o documento do winder: " . implode(" ", $dw["erros"] ?? []));
    $arq_w = sem_escopo(function () use ($dw) { return valor("SELECT arquivo FROM documento WHERE id = ?", [(int)$dw["id"]]); });
    $c = $api(["recurso" => "calibres", "acao" => "salvar", "nome" => "Calibre do teste"]);
    exigir_lote(!empty($c["ok"]), "Não criou o calibre: " . implode(" ", $c["erros"] ?? []));
    $dc = $api(["recurso" => "documentos", "acao" => "enviar", "calibre_id" => $c["id"], "categoria_id" => $cat, "copia_banco" => "0",
        "arquivo_base64" => base64_encode("o manual do calibre"), "arquivo_nome" => "calibre.txt"]);
    exigir_lote(!empty($dc["ok"]), "Não guardou o documento do calibre: " . implode(" ", $dc["erros"] ?? []));
    $arq_c = sem_escopo(function () use ($dc) { return valor("SELECT arquivo FROM documento WHERE id = ?", [(int)$dc["id"]]); });
    $lote = $api(["recurso" => "lote", "atomico" => "1", "operacoes" => json_encode([
        ["recurso" => "winders", "acao" => "excluir", "id" => $w["id"], "confirmar" => "1"],
        ["recurso" => "calibres", "acao" => "excluir", "id" => $c["id"], "confirmar" => "1"],
        ["recurso" => "relogio", "acao" => "salvar", "id" => 999999, "nome" => ""],
    ])]);
    exigir_lote(empty($lote["ok"]) && !empty($lote["desfeito"]), "O lote do winder e do calibre devia ser desfeito: " . json_encode($lote));
    exigir_lote(is_file($docs . "/" . $arq_w) && is_file($docs . "/" . $arq_c), "O lote foi desfeito, mas os arquivos do winder ou do calibre sumiram.");
    // gravado, saem
    $lote = $api(["recurso" => "lote", "atomico" => "1", "operacoes" => json_encode([
        ["recurso" => "winders", "acao" => "excluir", "id" => $w["id"], "confirmar" => "1"],
        ["recurso" => "calibres", "acao" => "excluir", "id" => $c["id"], "confirmar" => "1"],
    ])]);
    exigir_lote(!empty($lote["ok"]) && !is_file($docs . "/" . $arq_w) && !is_file($docs . "/" . $arq_c), "O lote gravado não tirou os arquivos do winder e do calibre: " . json_encode($lote));

    // ---- 5. a exclusão do documento sozinho: o arquivo só sai se a linha sair
    [$rid3, $did3, $arq3] = $novo_relogio("Documento sozinho");
    $ex = $api(["recurso" => "documentos", "acao" => "excluir", "id" => $did3, "confirmar" => "1"]);
    exigir_lote(!empty($ex["ok"]) && !is_file($docs . "/" . $arq3), "A exclusão do documento não tirou o arquivo: " . json_encode($ex));

    $log = (string)@file_get_contents($pasta . "/servidor.log");
    exigir_lote(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), "O servidor registrou erro de PHP:\n" . $log);
    echo "Os arquivos só saem da pasta depois que o banco confirma (lote desfeito, lote gravado, exclusão sozinha).\n";
    $saida = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
    $saida = 1;
} finally {
    if ($servidor !== null) {
        proc_terminate($servidor);
        proc_close($servidor);
    }
    if (function_exists("apagar_pasta")) {
        apagar_pasta($pasta);
    }
}
exit($saida);
