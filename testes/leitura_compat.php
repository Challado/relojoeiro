<?php
// A leitura de config, conta e usuarios continua entregando o que a v66 entregava (os clientes de antes leem dali), com as
// permissões de sempre: o rodízio e o relógio de ontem (o que vale e a escolha de cada nível), a situação do cron (o usuário
// comum vê a da própria coleção, sem o registro das outras), os sorteios, a força da senha, o endereço e as rodadas do cron, os
// parâmetros (só o master, com o rodízio e o relógio de ontem como eram), a preferência do dono, o servidor de e-mail (só o
// master). E o recurso=opcoes entrega o sim/não com o texto e o booleano: o "0" é desligado. Pela API de verdade (um php -S
// com a base SQLite nova e descartável).
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
$pasta = sys_get_temp_dir() . "/relojoeiro-leitura-compat-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require $raiz . "/lib.php";

function exigir_compat($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

// o valor num caminho (a.b.c; [] é o primeiro da lista); [existe, valor]
function caminho_compat($x, $caminho)
{
    foreach (explode(".", str_replace("[]", ".0", $caminho)) as $k) {
        if (!is_array($x) || !array_key_exists(ctype_digit($k) ? (int)$k : $k, $x)) {
            return [false, null];
        }
        $x = $x[ctype_digit($k) ? (int)$k : $k];
    }
    return [true, $x];
}

$servidor = null;
try {
    exigir_compat(instalar_banco()["ok"], "Não instalou a base descartável.");
    exigir_compat(op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123", "email" => "dono@exemplo.com"], null)["ok"], "Não criou o master.");
    exigir_compat(op_usuarios("salvar", ["login" => "comum", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123", "email" => "comum@exemplo.com"], "dono")["ok"], "Não criou o usuário comum.");
    $porta = 18000 + random_int(0, 999);
    $env = ["RELOGIOS_CONFIG" => __DIR__ . "/config.teste.php", "RELOGIOS_TESTE_PASTA" => $pasta, "RELOGIOS_DB" => "sqlite", "PATH" => getenv("PATH")];
    $servidor = proc_open([PHP_BINARY, "-S", "127.0.0.1:" . $porta, "-t", $raiz], [0 => ["pipe", "r"], 1 => ["file", $pasta . "/servidor.log", "a"], 2 => ["file", $pasta . "/servidor.log", "a"]], $tubos, $raiz, $env);
    for ($i = 0; $i < 100 && @fsockopen("127.0.0.1", $porta) === false; $i++) {
        usleep(100000);
    }
    $api = function ($login, $campos, $post = false) use ($porta) {
        $c = curl_init("http://127.0.0.1:" . $porta . "/api.php?formato=json" . ($post ? "" : "&" . http_build_query($campos)));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $login . ":Senha@123", CURLOPT_TIMEOUT => 60]
            + ($post ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($campos)] : []));
        $r = curl_exec($c);
        curl_close($c);
        return json_decode((string)$r, true) ?? ["erro" => "resposta inválida: " . substr((string)$r, 0, 300)];
    };

    // ---- o que a v66 entregava, nos dois usuários
    $comuns = ["recurso=config" => ["rodizio.ativo", "rodizio.colecao", "rodizio.usuario", "rodizio.sistema", "rodizio.origem", "recupera_ontem.ativo", "recupera_ontem.colecao",
        "recupera_ontem.usuario", "recupera_ontem.sistema", "recupera_ontem.origem", "cron_estado.situacao", "cron_estado.texto", "cron_estado.crontab", "cron_estado.ultima",
        "cron_estado.minutos", "cron_estado.erro", "cron_estado.registro", "aleatorio.fontes", "aleatorio.ultimo_lote", "aleatorio.fora_desde", "senha_politica.texto",
        "senha_politica.regras.tamanho", "sistema.url_sistema", "sistema.cron_espera_rodadas", "sistema.parametros", "dono.rodizio_ativo", "dono.recupera_ontem",
        "dono.rodizio_sistema", "dono.recupera_ontem_sistema"],
        "recurso=conta" => ["rodizio_sistema", "recupera_ontem_sistema", "email", "usuario.rodizio_ativo", "usuario.recupera_ontem"]];
    foreach (["dono", "comum"] as $quem) {
        foreach ($comuns as $q => $caminhos) {
            parse_str($q, $campos);
            $r = $api($quem, $campos);
            foreach ($caminhos as $c) {
                exigir_compat(caminho_compat($r, $c)[0], $quem . ", " . $q . ": falta " . $c . " (a v66 entregava).");
            }
        }
    }
    // ---- as permissões de antes: os parâmetros e o servidor de e-mail só para o master
    $cfg_d = $api("dono", ["recurso" => "config"]);
    $cfg_c = $api("comum", ["recurso" => "config"]);
    exigir_compat(is_array($cfg_d["sistema"]["parametros"]) && $cfg_c["sistema"]["parametros"] === null, "Os parâmetros do sistema deviam vir só para o master.");
    $chaves_p = array_column($cfg_d["sistema"]["parametros"], "chave");
    exigir_compat(in_array("rodizio_ativo", $chaves_p, true) && in_array("recupera_ontem", $chaves_p, true), "Os parâmetros não trazem o rodízio e o relógio de ontem como na v66.");
    exigir_compat(is_array($api("dono", ["recurso" => "conta"])["email"]) && $api("comum", ["recurso" => "conta"])["email"] === null, "O servidor de e-mail do sistema devia vir só para o master.");
    exigir_compat(isset($api("dono", ["recurso" => "usuarios"])["usuarios"][0]) && array_key_exists("rodizio_ativo", $api("dono", ["recurso" => "usuarios"])["usuarios"][0])
        && array_key_exists("recupera_ontem", $api("dono", ["recurso" => "usuarios"])["usuarios"][0]), "O painel das contas não traz o rodízio e o relógio de ontem de cada um.");
    // ---- o resumo do cron do usuário comum: a da própria coleção (sem o registro do sistema inteiro)
    sis_set("cron_ultima_execucao", date("Y-m-d H:i:s"));
    sis_set("cron_registro", "registro do sistema inteiro, com outras coleções");
    $cfg_c = $api("comum", ["recurso" => "config"]);
    exigir_compat($cfg_c["cron_estado"]["situacao"] === "rodando" && strpos((string)$cfg_c["cron_estado"]["registro"], "outras coleções") === false,
        "O usuário comum não vê a situação do cron, ou vê o registro do sistema inteiro: " . json_encode($cfg_c["cron_estado"]));
    exigir_compat(strpos((string)$api("dono", ["recurso" => "config"])["cron_estado"]["registro"], "outras coleções") !== false, "O master não vê o registro do sistema inteiro.");

    // ---- a preferência do dono, lida como na v66, depois de gravada pelo formato de antes e pelo de agora
    exigir_compat(!empty($api("dono", ["recurso" => "conta", "acao" => "alterar", "rodizio_ativo" => "0"], true)["ok"]), "O conta/alterar rodizio_ativo=0 falhou.");
    $conta = $api("dono", ["recurso" => "conta"]);
    exigir_compat($conta["usuario"]["rodizio_ativo"] === false && $conta["rodizio_sistema"] === true, "A conta não diz o rodízio do dono desligado: " . json_encode([$conta["usuario"]["rodizio_ativo"], $conta["rodizio_sistema"]]));
    $cfg_d = $api("dono", ["recurso" => "config"]);
    exigir_compat($cfg_d["dono"]["rodizio_ativo"] === false && $cfg_d["rodizio"]["ativo"] === false && $cfg_d["rodizio"]["origem"] === "usuario", "A config não diz o rodízio herdado do dono, desligado: " . json_encode($cfg_d["rodizio"]));

    // ---- o "0" do recurso=opcoes é desligado: o texto e o booleano
    $op = $api("dono", ["recurso" => "opcoes", "grupo" => "rodizio"]);
    $item = function ($lista) { foreach ($lista as $o) { if ($o["chave"] === "rodizio_ativo") { return $o; } } return null; };
    $o_c = $item($op["colecao"]["opcoes"]);
    $o_d = $item($op["dono"]["opcoes"]["opcoes"] ?? $op["dono"]["opcoes"]);
    exigir_compat($o_c["efetivo"] === "0" && $o_c["ligado"] === false && $o_c["efetivo_texto"] === "Desligado" && $o_c["herdado_texto"] === "Desligado", "O rodízio desligado (\"0\") não vem como desligado na coleção: " . json_encode($o_c));
    exigir_compat($o_d["escolha"] === "0" && $o_d["ligado"] === false && $o_d["efetivo_texto"] === "Desligado", "O rodízio desligado (\"0\") não vem como desligado no dono: " . json_encode($o_d));
    exigir_compat(array_column($o_c["valores"], "texto", "valor") === ["1" => "Ligado", "0" => "Desligado"], "O sim/não não traz os dois valores com o texto: " . json_encode($o_c["valores"]));
    $o_s = $item($op["sistema"]["opcoes"]["opcoes"] ?? $op["sistema"]["opcoes"]);
    exigir_compat($o_s["ligado"] === true && $o_s["efetivo_texto"] === "Ligado", "O sistema (padrão de fábrica, ligado) não vem ligado: " . json_encode($o_s));

    $log = (string)@file_get_contents($pasta . "/servidor.log");
    exigir_compat(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), "O servidor registrou erro de PHP:\n" . $log);
    echo "A leitura de config, conta e usuarios entrega o que a v66 entregava, com as permissões; o \"0\" das opções é desligado.\n";
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
