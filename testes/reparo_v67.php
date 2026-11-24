<?php
// O reparo da v67 (a migração v69), pelo caminho normal das migrações (recurso=migracoes: a lista e o aplicar, num php -S). A versão
// antiga da v67 se dava por aplicada antes de terminar: gravava a marca com colunas antigas do usuário no banco, e nenhuma
// migração voltava a aparecer, nem depois da v68. Quatro bases SQLite novas e descartáveis, cada uma com os dados já migrados
// e escolhas mais novas do usuário (a preferência e a retomada gravadas depois, diferentes das que ficaram nas colunas):
//   antiga   a marca da v67, rodizio_retomado_em e recupera_ontem sobrando (com a regra), sem a v68: pendentes a v68 e a v69;
//   com_v68  o mesmo, já com a v68 aplicada: pendente só a v69 (antes, nenhuma);
//   tres     a marca da v67 e as três colunas sobrando (a leva parou antes da primeira): pendente só a v69;
//   saudavel sem nada sobrando: nenhuma pendente, e o passo da v69 rodado à mão não muda nada.
// Aplicada, nenhuma pendente, nenhuma das três colunas, e as preferências, as retomadas e os padrões do sistema iguais aos de
// antes; aplicar de novo (pela API e o passo à mão) não muda nada.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
$cenario = $argv[1] ?? null;
if ($cenario === null) {
    // o roteiro: uma rodada filha para cada base (cada uma com a sua pasta e o seu processo)
    $falhas = [];
    foreach (["antiga", "com_v68", "tres", "saudavel"] as $c) {
        $saida = [];
        exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__FILE__) . " " . $c . " 2>&1", $saida, $codigo);
        if ($codigo !== 0) {
            $falhas[] = $c . ": " . implode(" ", $saida);
        }
    }
    if (count($falhas) > 0) {
        fwrite(STDERR, "FALHOU:\n" . implode("\n", $falhas) . "\n");
        exit(1);
    }
    echo "Reparo da v67 (v69): a base antiga marcada antes de terminar, a que já tinha a v68 e a das três colunas voltam a ter a migração, que tira as colunas sem mexer nas escolhas; a saudável não tem nada; aplicar de novo não muda nada.\n";
    exit(0);
}

$pasta = sys_get_temp_dir() . "/relojoeiro-reparo-v67-" . $cenario . "-" . bin2hex(random_bytes(5));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require $raiz . "/lib.php";

function exigir_reparo($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

$servidor = null;
try {
    exigir_reparo(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    $outro = op_usuarios("salvar", ["login" => "outro", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], "dono");
    exigir_reparo($dono["ok"] && $outro["ok"], "Não criou os usuários.");
    $novo = strtotime("2026-06-07 08:09:10");
    sem_escopo(function () use ($dono, $outro, $novo, $cenario) {
        // os dados já migrados, com as escolhas mais novas do usuário (gravadas depois da v67 antiga)
        sql("DELETE FROM usuario_opcao");
        sql("INSERT INTO usuario_opcao (usuario_id, chave, valor) VALUES (?, 'recupera_ontem', '0')", [(int)$dono["usuario_id"]]);
        sql("INSERT INTO usuario_opcao (usuario_id, chave, valor) VALUES (?, 'rodizio_ativo', '0')", [(int)$outro["usuario_id"]]);
        sql("REPLACE INTO config (colecao_id, chave, valor) VALUES (?, 'rodizio_retomado_em', ?)", [(int)$dono["colecao_id"], (string)$novo]);
        sql("REPLACE INTO sistema (chave, valor) VALUES ('padrao_rodizio_ativo', '1')");
        if ($cenario === "saudavel") {
            return;
        }
        // o que a v67 antiga deixou: a marca gravada (a instalação nova já a tem) e as colunas que sobraram, com os valores de antes
        $sobra = $cenario === "tres" ? ["rodizio_ativo", "rodizio_retomado_em", "recupera_ontem"] : ["rodizio_retomado_em", "recupera_ontem"];
        $ddl = ["rodizio_ativo" => "ALTER TABLE usuario ADD COLUMN rodizio_ativo TINYINT NULL;\nALTER TABLE usuario ADD CONSTRAINT ck_usuario_rodizio CHECK (rodizio_ativo IS NULL OR rodizio_ativo IN (0, 1));",
            "rodizio_retomado_em" => "ALTER TABLE usuario ADD COLUMN rodizio_retomado_em DATETIME NULL;",
            "recupera_ontem" => "ALTER TABLE usuario ADD COLUMN recupera_ontem TINYINT NULL;\nALTER TABLE usuario ADD CONSTRAINT ck_usuario_recupera_ontem CHECK (recupera_ontem IS NULL OR recupera_ontem IN (0, 1));"];
        foreach ($sobra as $col) {
            banco_script($ddl[$col]);
        }
        $GLOBALS["BANCO_ESTRUTURA"] = ($GLOBALS["BANCO_ESTRUTURA"] ?? 0) + 1;
        sql("UPDATE usuario SET rodizio_retomado_em = '2026-01-02 03:04:05', recupera_ontem = 1 WHERE id = ?", [(int)$dono["usuario_id"]]);
        if (in_array("rodizio_ativo", $sobra, true)) {
            sql("UPDATE usuario SET rodizio_ativo = 1 WHERE id = ?", [(int)$outro["usuario_id"]]);
        }
        if ($cenario === "antiga") {
            // a base de antes da v68: sem a coluna Próxima vistoria nem a marca dela
            sql("DELETE FROM coluna WHERE identificador = 'vistoria'");
            sql("DELETE FROM sistema WHERE chave = 'migracao_v68'");
        }
    });
    sis(null);
    exigir_reparo(sis("migracao_v67") === "1", "A base devia ter a marca da v67.");
    // os dados que o reparo não pode mudar
    $foto = function () {
        return sem_escopo(function () {
            return [linhas("SELECT usuario_id, chave, valor FROM usuario_opcao ORDER BY usuario_id, chave"),
                linhas("SELECT colecao_id, valor FROM config WHERE chave = 'rodizio_retomado_em' ORDER BY colecao_id"),
                linhas("SELECT chave, valor FROM sistema WHERE chave LIKE 'padrao_%' ORDER BY chave")];
        });
    };
    $colunas = function () {
        $GLOBALS["BANCO_ESTRUTURA"] = ($GLOBALS["BANCO_ESTRUTURA"] ?? 0) + 1;
        return array_values(array_filter(MIGRACAO_V67_COLUNAS, function ($c) { return banco_tem_coluna("usuario", $c); }));
    };
    $antes = $foto();

    // ---- pelo caminho normal: o php -S, a lista das pendentes e o aplicar
    $porta = 18000 + random_int(0, 999);
    $env = ["RELOGIOS_CONFIG" => __DIR__ . "/config.teste.php", "RELOGIOS_TESTE_PASTA" => $pasta, "RELOGIOS_DB" => "sqlite", "PATH" => getenv("PATH")];
    $servidor = proc_open([PHP_BINARY, "-S", "127.0.0.1:" . $porta, "-t", $raiz], [0 => ["pipe", "r"], 1 => ["file", $pasta . "/servidor.log", "a"], 2 => ["file", $pasta . "/servidor.log", "a"]], $tubos, $raiz, $env);
    for ($i = 0; $i < 100 && @fsockopen("127.0.0.1", $porta) === false; $i++) {
        usleep(100000);
    }
    $api = function ($campos, $post = false) use ($porta) {
        $c = curl_init("http://127.0.0.1:" . $porta . "/api.php?formato=json" . ($post ? "" : "&" . http_build_query($campos)));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "dono:Senha@123", CURLOPT_TIMEOUT => 120]
            + ($post ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($campos)] : []));
        $r = curl_exec($c);
        curl_close($c);
        return json_decode((string)$r, true) ?? ["erro" => "resposta inválida: " . substr((string)$r, 0, 300)];
    };
    $pendentes = function () use ($api) { return array_column($api(["recurso" => "migracoes"])["pendentes"] ?? [["versao" => "?"]], "versao"); };
    $esperadas = ["antiga" => ["v68", "v69"], "com_v68" => ["v69"], "tres" => ["v69"], "saudavel" => []][$cenario];
    exigir_reparo($pendentes() === $esperadas, "As pendentes deviam ser " . json_encode($esperadas) . ": " . json_encode($pendentes()));
    if ($esperadas !== []) {
        $apl = $api(["recurso" => "migracoes", "acao" => "aplicar"], true);
        exigir_reparo(!empty($apl["ok"]) && count($apl["aplicadas"] ?? []) === count($esperadas), "O aplicar falhou: " . json_encode($apl));
    }
    exigir_reparo($pendentes() === [], "Depois de aplicar, ainda há pendentes: " . json_encode($pendentes()));
    exigir_reparo($colunas() === [], "Depois de aplicar, ficaram colunas antigas: " . implode(", ", $colunas()));
    exigir_reparo($foto() === $antes, "O reparo mudou as preferências, as retomadas ou os padrões do sistema:\nantes " . json_encode($antes) . "\ndepois " . json_encode($foto()));
    if ($cenario === "antiga") {
        exigir_reparo((int)sem_escopo(function () { return valor("SELECT COUNT(*) FROM coluna WHERE identificador = 'vistoria'"); }) > 0, "A v68 aplicada junto devia pôr a coluna Próxima vistoria.");
    }
    // ---- de novo: o aplicar sem nada pendente e o passo da v69 à mão não mudam nada
    $apl2 = $api(["recurso" => "migracoes", "acao" => "aplicar"], true);
    exigir_reparo(!empty($apl2["ok"]) && ($apl2["aplicadas"] ?? null) === [], "O segundo aplicar devia não ter nada: " . json_encode($apl2));
    sis(null);
    migracao_v69_dados(false);
    exigir_reparo($colunas() === [] && $foto() === $antes && migracao_v69_feita(), "O passo da v69 rodado de novo mudou algo.");
    exigir_reparo($pendentes() === [], "Depois do passo de novo, apareceu pendente: " . json_encode($pendentes()));
    $log = (string)@file_get_contents($pasta . "/servidor.log");
    exigir_reparo(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), "O servidor registrou erro de PHP:\n" . $log);
    $saida = 0;
} catch (Throwable $e) {
    echo $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")";
    $saida = 1;
} finally {
    if ($servidor !== null) {
        proc_terminate($servidor);
        proc_close($servidor);
    }
    apagar_pasta($pasta);
}
exit($saida);
