<?php
// O config/salvar e o conta/alterar nos três níveis (sistema → dono → coleção): o pedido é uma transação só, conferida no fim
// com o valor final de cada coleção afetada. Um pedido recusado (o horário de uso que ficaria invertido em alguma coleção) não
// deixa nada gravado em nenhum nível, nem o rodízio desligado nem a retomada; um pedido que juntos os níveis deixariam com o
// fim antes do início é recusado. E nenhum pedido responde sucesso tendo deixado algo de lado: o formato de antes com o mesmo
// sentido vale (rodizio_ativo e recupera_ontem soltos no conta/alterar; parametros[rodizio_ativo|recupera_ontem] no
// config/salvar); o resto (opcoes[...] no config/salvar, uma opção solta no conta/alterar, um campo desconhecido) recusa tudo,
// com o formato certo. Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-config-niveis-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_niveis($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

// o que está gravado, lido do banco (sem a memória do pedido)
function gravado_sistema($chave)
{
    sis(null);
    return opcao_do_sistema($chave);
}
function gravado_dono($uid, $chave)
{
    $GLOBALS["OPCOES_VERSAO"] = ($GLOBALS["OPCOES_VERSAO"] ?? 0) + 1;
    return opcoes_do_usuario($uid)[$chave] ?? "";
}
function gravado_colecao($cid, $chave)
{
    return (string)sem_escopo(function () use ($cid, $chave) { return valor("SELECT valor FROM config WHERE colecao_id = ? AND chave = ?", [$cid, $chave]); });
}

try {
    exigir_niveis(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123", "email" => "dono@exemplo.com"], null);
    exigir_niveis($dono["ok"], "Não criou o master.");
    $uid = (int)$dono["usuario_id"];
    $entrar = function ($cid) use ($uid) {
        usuario_definir(sem_escopo(function () use ($uid) { return linha("SELECT * FROM usuario WHERE id = ?", [$uid]); }));
        banco_escopo($cid);
        sis(null);
        cfg_cru(null);
        $GLOBALS["OPCOES_VERSAO"] = ($GLOBALS["OPCOES_VERSAO"] ?? 0) + 1;
    };
    $primeira = (int)$dono["colecao_id"];
    $entrar($primeira);
    $segunda = op_colecoes("criar", ["nome" => "Segunda", "opcao" => "vazia"]);
    exigir_niveis($segunda["ok"], "Não criou a segunda coleção.");
    $segunda = (int)$segunda["id"];
    $entrar($primeira);

    // ===== 2. a hierarquia numa transação só =====

    // ---- (a) recusado: o padrão do sistema desliga o rodízio e põe o início às 19h, e o do dono põe o fim às 18h. Na v67, o
    // sistema gravava primeiro (o rodízio desligado em tudo) e só o dono era recusado
    $r = op_config("salvar", ["padroes" => ["rodizio_ativo" => "0", "uso_inicio" => "19:00"], "dono" => ["uso_fim" => "18:00"]]);
    exigir_niveis(!$r["ok"], "Aceitou o pedido que deixaria o uso das 19h às 18h: " . json_encode($r));
    exigir_niveis(gravado_sistema("rodizio_ativo") === "" && gravado_sistema("uso_inicio") === "", "O pedido recusado desligou o rodízio do sistema (ou gravou o início): " . gravado_sistema("rodizio_ativo") . " / " . gravado_sistema("uso_inicio"));
    exigir_niveis(gravado_dono($uid, "uso_fim") === "", "O pedido recusado gravou o fim do dono.");
    $entrar($primeira);
    exigir_niveis(rodizio_ativo() && cfg("uso_inicio") === "07:00" && cfg("uso_fim") === "22:00", "Depois da recusa, a coleção não ficou como estava.");

    // ---- (b) recusado: a coleção põe o início às 19h e o dono, o fim às 18h. Na v67, cada nível passava sozinho e a coleção
    // ficava das 19h às 18h
    $r = op_config("salvar", ["uso_inicio" => "19:00", "dono" => ["uso_fim" => "18:00"]]);
    exigir_niveis(!$r["ok"], "Aceitou a coleção das 19h às 18h (o início dela e o fim do dono): " . json_encode($r));
    exigir_niveis(gravado_colecao($primeira, "uso_inicio") === "" && gravado_dono($uid, "uso_fim") === "", "A recusa deixou o início da coleção ou o fim do dono gravado.");
    $entrar($primeira);
    exigir_niveis(cfg("uso_inicio") < cfg("uso_fim"), "A coleção ficou com o horário invertido: " . cfg("uso_inicio") . " a " . cfg("uso_fim"));

    // ---- (c) a outra coleção do dono escolheu o fim às 20h; o dono pôr o início às 21h a deixaria invertida: recusa, com o
    // nome dela, e nada muda nas duas
    $entrar($segunda);
    exigir_niveis(op_config("salvar", ["uso_fim" => "20:00"])["ok"], "A segunda coleção não escolheu o fim.");
    $entrar($primeira);
    $r = op_config("salvar", ["carga_limiar" => "33", "dono" => ["uso_inicio" => "21:00"]]);
    exigir_niveis(!$r["ok"] && strpos(implode(" ", $r["erros"]), "Segunda") !== false, "Não recusou (com o nome da coleção) o início do dono que inverte a outra coleção: " . json_encode($r));
    exigir_niveis(gravado_colecao($primeira, "carga_limiar") === "" && gravado_dono($uid, "uso_inicio") === "", "A recusa deixou a carga desta coleção ou o início do dono gravado.");
    // pelo conta/alterar, o mesmo
    $r = op_conta("alterar", ["email" => "novo@exemplo.com", "opcoes" => ["uso_inicio" => "21:00"]]);
    exigir_niveis(!$r["ok"] && (string)sem_escopo(function () use ($uid) { return valor("SELECT email FROM usuario WHERE id = ?", [$uid]); }) === "dono@exemplo.com",
        "O conta/alterar recusado gravou o e-mail (ou aceitou o início que inverte a outra coleção): " . json_encode($r));

    // ---- (d) aceito: os níveis juntos deixam tudo certo, mesmo que cada um sozinho, com os valores de antes, não deixasse
    // (o sistema põe o fim às 23h e o dono o início às 22h30)
    $entrar($segunda);
    exigir_niveis(op_config("salvar", ["uso_fim" => ""])["ok"], "A segunda coleção não voltou a herdar o fim.");
    $entrar($primeira);
    $r = op_config("salvar", ["padroes" => ["uso_fim" => "23:00"], "dono" => ["uso_inicio" => "22:30"]]);
    exigir_niveis($r["ok"], "Recusou o pedido que, junto, fica certo: " . json_encode($r));
    $entrar($primeira);
    exigir_niveis(cfg("uso_inicio") === "22:30" && cfg("uso_fim") === "23:00", "Não valeu o que o pedido aceito gravou.");
    exigir_niveis(op_config("salvar", ["padroes" => ["uso_fim" => ""], "dono" => ["uso_inicio" => ""]])["ok"], "Não voltou aos padrões.");

    // ---- (e) a retomada do rodízio: desligado no sistema, um pedido recusado que o religaria não marca a retomada
    $entrar($primeira);
    exigir_niveis(op_config("salvar", ["padroes" => ["rodizio_ativo" => "0"]])["ok"], "Não desligou o rodízio do sistema.");
    $retomada = [$primeira => gravado_colecao($primeira, "rodizio_retomado_em"), $segunda => gravado_colecao($segunda, "rodizio_retomado_em")];
    sleep(1);
    $r = op_config("salvar", ["padroes" => ["rodizio_ativo" => "1"], "uso_inicio" => "19:00", "dono" => ["uso_fim" => "18:00"]]);
    exigir_niveis(!$r["ok"], "Aceitou o pedido inválido que religaria o rodízio.");
    exigir_niveis(gravado_sistema("rodizio_ativo") === "0", "O pedido recusado religou o rodízio do sistema.");
    foreach ($retomada as $c => $antes) {
        exigir_niveis(gravado_colecao($c, "rodizio_retomado_em") === $antes, "O pedido recusado marcou a retomada do rodízio na coleção " . $c . ".");
    }
    // aceito, marca a retomada em cada coleção que herda
    exigir_niveis(op_config("salvar", ["padroes" => ["rodizio_ativo" => ""]])["ok"], "Não religou o rodízio do sistema.");
    foreach ([$primeira, $segunda] as $c) {
        exigir_niveis((int)gravado_colecao($c, "rodizio_retomado_em") > (int)$retomada[$c], "Religado, a coleção " . $c . " não marcou a retomada.");
    }
    // o pedido recusado não deixa a memória do pedido diferente do banco
    $entrar($primeira);
    $r = op_config("salvar", ["padroes" => ["rodizio_ativo" => "0", "uso_inicio" => "19:00"], "dono" => ["uso_fim" => "18:00"]]);
    exigir_niveis(!$r["ok"] && rodizio_ativo() && opcao_do_sistema("rodizio_ativo") === "", "Depois da recusa, a memória do pedido ficou com o rodízio desligado.");

    // ===== 3. nenhum sucesso para o que ficou de lado =====
    $entrar($primeira);
    // ---- o conta/alterar com o formato de antes (rodizio_ativo e recupera_ontem soltos: a preferência do dono)
    $r = op_conta("alterar", ["email" => "dono2@exemplo.com", "rodizio_ativo" => "0"]);
    exigir_niveis($r["ok"] && gravado_dono($uid, "rodizio_ativo") === "0" && (string)sem_escopo(function () use ($uid) { return valor("SELECT email FROM usuario WHERE id = ?", [$uid]); }) === "dono2@exemplo.com",
        "O conta/alterar com email e rodizio_ativo=0 não gravou os dois: " . json_encode($r));
    $r = op_conta("alterar", ["rodizio_ativo" => ""]);
    exigir_niveis($r["ok"] && gravado_dono($uid, "rodizio_ativo") === "", "O rodizio_ativo vazio sozinho não voltou a herdar.");
    $r = op_conta("alterar", ["recupera_ontem" => "1"]);
    exigir_niveis($r["ok"] && gravado_dono($uid, "recupera_ontem") === "1", "O recupera_ontem=1 sozinho não gravou.");
    $r = op_conta("alterar", ["recupera_ontem" => "0", "idioma" => "en"]);
    exigir_niveis($r["ok"] && gravado_dono($uid, "recupera_ontem") === "0", "O recupera_ontem=0 com o idioma não gravou.");
    op_conta("alterar", ["idioma" => "pt-BR", "recupera_ontem" => ""]);
    $r = op_conta("alterar", ["rodizio_ativo" => "0", "opcoes" => ["rodizio_ativo" => "1"]]);
    exigir_niveis(!$r["ok"] && gravado_dono($uid, "rodizio_ativo") === "", "Aceitou rodizio_ativo e opcoes[rodizio_ativo] diferentes.");
    $r = op_conta("alterar", ["recupera_ontem" => "2"]);
    exigir_niveis(!$r["ok"], "Aceitou recupera_ontem=2.");
    // ---- o conta/alterar com o que não é dele: recusa tudo, e o e-mail não muda
    foreach ([["uso_fim" => "20:00"], ["padroes" => ["carga_limiar" => "30"]], ["dono" => ["carga_limiar" => "30"]], ["rodizio" => "0"]] as $fora) {
        $r = op_conta("alterar", ["email" => "outro@exemplo.com"] + $fora);
        exigir_niveis(!$r["ok"] && (string)sem_escopo(function () use ($uid) { return valor("SELECT email FROM usuario WHERE id = ?", [$uid]); }) === "dono2@exemplo.com",
            "O conta/alterar aceitou (ou gravou o e-mail com) " . json_encode($fora) . ": " . json_encode($r));
    }
    $r = op_conta("alterar", ["opcoes" => ["nao_existe" => "1"]]);
    exigir_niveis(!$r["ok"], "O conta/alterar aceitou uma opção desconhecida.");
    // ---- o config/salvar com o formato de antes (parametros[rodizio_ativo|recupera_ontem]: o padrão do sistema)
    $r = op_config("salvar", ["parametros" => ["rodizio_ativo" => "0"]]);
    exigir_niveis($r["ok"] && gravado_sistema("rodizio_ativo") === "0", "O parametros[rodizio_ativo]=0 não gravou o padrão do sistema: " . json_encode($r));
    $r = op_config("salvar", ["parametros" => ["rodizio_ativo" => ""]]);
    exigir_niveis($r["ok"] && gravado_sistema("rodizio_ativo") === "", "O parametros[rodizio_ativo] vazio não voltou ao padrão de fábrica.");
    $r = op_config("salvar", ["parametros" => ["recupera_ontem" => "1"], "carga_limiar" => "31"]);
    exigir_niveis($r["ok"] && gravado_sistema("recupera_ontem") === "1" && gravado_colecao($primeira, "carga_limiar") === "31", "O parametros[recupera_ontem] com outro campo não gravou os dois: " . json_encode($r));
    $r = op_config("salvar", ["parametros" => ["recupera_ontem" => "0", "lote_maximo" => "50"]]);
    exigir_niveis($r["ok"] && gravado_sistema("recupera_ontem") === "0" && parametro_gravado("lote_maximo") === 50, "O parametros com o formato de antes e um parâmetro de verdade não gravou os dois: " . json_encode($r));
    $r = op_config("salvar", ["parametros" => ["rodizio_ativo" => "0"], "padroes" => ["rodizio_ativo" => "1"]]);
    exigir_niveis(!$r["ok"] && gravado_sistema("rodizio_ativo") === "", "Aceitou parametros[rodizio_ativo] e padroes[rodizio_ativo] diferentes.");
    // ---- o config/salvar com o que não é dele: recusa tudo
    foreach ([["opcoes" => ["uso_fim" => "20:00"]], ["campo_que_nao_existe" => "1"], ["rodizio" => "0"]] as $fora) {
        $r = op_config("salvar", ["carga_limiar" => "29"] + $fora);
        exigir_niveis(!$r["ok"] && gravado_colecao($primeira, "carga_limiar") === "31", "O config/salvar aceitou (ou gravou a carga com) " . json_encode($fora) . ": " . json_encode($r));
    }
    $r = op_config("salvar", ["opcoes" => ["uso_fim" => "20:00"]]);
    exigir_niveis(!$r["ok"] && strpos(implode(" ", $r["erros"]), "dono[") !== false, "A recusa do opcoes[...] no config/salvar não diz o formato certo: " . json_encode($r));
    // ---- o idioma da coleção (aceito e, desde 5c16b0d, não gravado) grava
    $r = op_config("salvar", ["idioma" => "es"]);
    exigir_niveis($r["ok"] && gravado_colecao($primeira, "idioma") === "es", "O idioma da coleção não gravou: " . json_encode($r));
    exigir_niveis(!op_config("salvar", ["idioma" => "xx"])["ok"], "Aceitou um idioma que não existe.");

    echo "Configuração em três níveis: uma transação só (recusa sem gravar nada, nem a retomada; o horário conferido com os níveis juntos) e nenhum sucesso para o que ficou de lado conferem.\n";
    $saida = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
    $saida = 1;
} finally {
    if (function_exists("apagar_pasta")) {
        apagar_pasta($pasta);
    }
}
exit($saida);
