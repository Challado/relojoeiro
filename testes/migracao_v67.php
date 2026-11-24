<?php
// A migração v67 retomada depois de parar no meio: um banco como a v66 o deixava (as colunas rodizio_ativo,
// rodizio_retomado_em e recupera_ontem do usuário, com as regras; os parâmetros param_rodizio_ativo e param_recupera_ontem e a
// retomada do sistema) para em cada uma das etapas (migracao_v67_passos) e é aplicado de novo: a reaplicação guarda o padrão
// do sistema, a preferência de cada dono e a retomada de cada coleção que herda, tira as três colunas, e só então grava a marca.
// Enquanto parada, a v67 continua pendente, e a v57 (que dependia da coluna recupera_ontem) não volta a aparecer. Inclui o caso
// que a v67 de antes errava: parada depois de tirar só a primeira coluna, ela se dava por aplicada com as outras duas no banco.
// Base SQLite nova e descartável, uma para cada ponto de parada.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
$base = sys_get_temp_dir() . "/relojoeiro-migracao-v67-" . bin2hex(random_bytes(6));
mkdir($base, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_DB=sqlite");
$parada = $argv[1] ?? null;
if ($parada === null) {
    // o roteiro: uma rodada filha para cada ponto de parada (cada uma com a sua base e o seu processo: a memória do PHP do
    // banco e das marcas começa do zero, como na aplicação de verdade depois de uma queda)
    putenv("RELOGIOS_TESTE_PASTA=" . $base . "/n");
    mkdir($base . "/n");
    require $raiz . "/lib.php";
    $n = count(migracao_v67_passos());
    $falhas = [];
    for ($k = 0; $k <= $n; $k++) {
        $saida = [];
        exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__FILE__) . " " . $k . " 2>&1", $saida, $codigo);
        if ($codigo !== 0) {
            $falhas[] = "parada depois da etapa " . $k . ": " . implode(" ", $saida);
        }
    }
    apagar_pasta($base);
    if (count($falhas) > 0) {
        fwrite(STDERR, "FALHOU:\n" . implode("\n", $falhas) . "\n");
        exit(1);
    }
    echo "Migração v67: parada depois de cada uma das " . $n . " etapas (e sem parar), a reaplicação guarda os valores, tira as três colunas e só então marca.\n";
    exit(0);
}

// ---- uma rodada filha: parar depois de $parada etapas e aplicar de novo
putenv("RELOGIOS_TESTE_PASTA=" . $base);
require $raiz . "/lib.php";
function exigir_v67($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}
try {
    exigir_v67(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    $outro = op_usuarios("salvar", ["login" => "outro", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], "dono");
    exigir_v67($dono["ok"] && $outro["ok"], "Não criou os usuários.");
    // ---- o banco como a v66 o deixava
    sem_escopo(function () use ($dono, $outro) {
        banco_script("ALTER TABLE usuario ADD COLUMN rodizio_ativo TINYINT NULL;\nALTER TABLE usuario ADD COLUMN rodizio_retomado_em DATETIME NULL;\n"
            . "ALTER TABLE usuario ADD COLUMN recupera_ontem TINYINT NULL;\n"
            . "ALTER TABLE usuario ADD CONSTRAINT ck_usuario_rodizio CHECK (rodizio_ativo IS NULL OR rodizio_ativo IN (0, 1));\n"
            . "ALTER TABLE usuario ADD CONSTRAINT ck_usuario_recupera_ontem CHECK (recupera_ontem IS NULL OR recupera_ontem IN (0, 1));");
        // o dono herda o rodízio do sistema (que estava desligado) e voltou a ligá-lo em 2026-01-02; o outro desligou o dele e
        // ligou o relógio de ontem
        sql("UPDATE usuario SET rodizio_ativo = NULL, rodizio_retomado_em = '2026-01-02 03:04:05', recupera_ontem = 1 WHERE id = ?", [(int)$dono["usuario_id"]]);
        sql("UPDATE usuario SET rodizio_ativo = 0, recupera_ontem = NULL WHERE id = ?", [(int)$outro["usuario_id"]]);
        sql("DELETE FROM usuario_opcao");
        sql("DELETE FROM sistema WHERE chave IN ('migracao_v67', 'padrao_rodizio_ativo', 'padrao_recupera_ontem')");
        foreach (["param_rodizio_ativo" => "0", "param_recupera_ontem" => "1", "rodizio_retomado_em" => (string)strtotime("2026-03-04 05:06:07")] as $k => $v) {
            sql("REPLACE INTO sistema (chave, valor) VALUES (?, ?)", [$k, $v]);
        }
    });
    sis(null);
    $GLOBALS["BANCO_ESTRUTURA"] = ($GLOBALS["BANCO_ESTRUTURA"] ?? 0) + 1;
    $pend = function () {
        $GLOBALS["BANCO_ESTRUTURA"] = ($GLOBALS["BANCO_ESTRUTURA"] ?? 0) + 1;
        return array_keys(migracoes_pendentes());
    };
    exigir_v67($pend() === ["v67"], "O banco da v66 devia ter só a v67 pendente: " . implode(", ", $pend()));
    // ---- para depois de $parada etapas
    $passos = migracao_v67_passos();
    foreach (array_slice($passos, 0, (int)$parada) as $passo) {
        $passo();
    }
    if ((int)$parada < count($passos)) {
        exigir_v67($pend() === ["v67"], "Parada depois da etapa " . $parada . ", devia continuar só a v67 pendente: " . implode(", ", $pend()));
    }
    // ---- aplica de novo, como o botão da Configuração (o SQL da v67 e o passo em PHP)
    banco_script((string)file_get_contents(dirname(__DIR__) . "/migracao_v67.sql"));
    migracao_v67_dados();
    exigir_v67($pend() === [], "Reaplicada, ainda há migração pendente: " . implode(", ", $pend()));
    foreach (["rodizio_ativo", "rodizio_retomado_em", "recupera_ontem"] as $col) {
        $GLOBALS["BANCO_ESTRUTURA"]++;
        exigir_v67(!banco_tem_coluna("usuario", $col), "Reaplicada, a coluna usuario." . $col . " ficou.");
    }
    sis(null);
    exigir_v67(sis("migracao_v67") === "1", "Reaplicada, a marca não foi gravada.");
    exigir_v67(opcao_do_sistema("rodizio_ativo") === "0" && opcao_do_sistema("recupera_ontem") === "1", "O padrão do sistema não veio dos parâmetros: " . opcao_do_sistema("rodizio_ativo") . "/" . opcao_do_sistema("recupera_ontem"));
    exigir_v67(sis("param_rodizio_ativo") === "" && sis("param_recupera_ontem") === "" && sis("rodizio_retomado_em") === "", "Os parâmetros de antes (ou a retomada do sistema) ficaram na tabela sistema.");
    $GLOBALS["OPCOES_VERSAO"] = ($GLOBALS["OPCOES_VERSAO"] ?? 0) + 1;
    $od = opcoes_do_usuario((int)$dono["usuario_id"]);
    $oo = opcoes_do_usuario((int)$outro["usuario_id"]);
    exigir_v67(($od["rodizio_ativo"] ?? "") === "" && ($od["recupera_ontem"] ?? "") === "1", "A preferência do dono não veio da coluna: " . json_encode($od));
    exigir_v67(($oo["rodizio_ativo"] ?? "") === "0" && ($oo["recupera_ontem"] ?? "") === "", "A preferência do outro não veio da coluna: " . json_encode($oo));
    // a coleção do dono herdava: a retomada é a maior entre a do dono e a do sistema (o dono herdava o do sistema)
    $ret = (int)sem_escopo(function () use ($dono) { return valor("SELECT valor FROM config WHERE colecao_id = ? AND chave = 'rodizio_retomado_em'", [(int)$dono["colecao_id"]]); });
    exigir_v67($ret === max(strtotime("2026-01-02 03:04:05"), strtotime("2026-03-04 05:06:07")), "A retomada da coleção do dono não ficou a maior das que valiam: " . $ret);
    // a coleção do outro herdava do outro, que desligou: a retomada do sistema não vale para ela
    $ret_o = (int)sem_escopo(function () use ($outro) { return valor("SELECT valor FROM config WHERE colecao_id = ? AND chave = 'rodizio_retomado_em'", [(int)$outro["colecao_id"]]); });
    exigir_v67($ret_o === 0, "A coleção do outro (o rodízio desligado pelo dono dela) ganhou a retomada do sistema: " . $ret_o);
    // aplicar mais uma vez não muda nada
    migracao_v67_dados();
    exigir_v67((int)sem_escopo(function () use ($dono) { return valor("SELECT valor FROM config WHERE colecao_id = ? AND chave = 'rodizio_retomado_em'", [(int)$dono["colecao_id"]]); }) === $ret, "Aplicar de novo mudou a retomada.");
    exit(0);
} catch (Throwable $e) {
    echo $e->getMessage();
    exit(1);
} finally {
    apagar_pasta($base);
}
