<?php
// Rodízio ligado e desligado: herança, permissões, pausa e retomada. Base nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-rodizio-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_rodizio($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

function entrar_rodizio($u)
{
    usuario_definir(sem_escopo(function () use ($u) { return linha("SELECT * FROM usuario WHERE id = ?", [$u["usuario_id"]]); }));
    banco_escopo($u["colecao_id"]);
}

try {
    exigir_rodizio(instalar_banco()["ok"], "Não instalou a base descartável.");
    $master = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_rodizio($master["ok"], "Não criou o master.");
    entrar_rodizio($master);
    $outra = op_usuarios("salvar", ["login" => "outra", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], "dono");
    exigir_rodizio($outra["ok"], "Não criou a outra coleção.");
    entrar_rodizio($master);
    exigir_rodizio(rodizio_configuracao() === ["ativo" => true, "colecao" => null, "usuario" => null, "sistema" => true, "origem" => "padrao"], "O padrão deixou de ser ligado e herdado.");
    exigir_rodizio(!op_config("salvar", ["rodizio_ativo" => "talvez"])["ok"], "Aceitou escolha inválida.");
    exigir_rodizio(!op_config("salvar", ["rodizio_ativo" => []])["ok"], "Aceitou uma lista no lugar da escolha.");
    $segunda = op_colecoes("criar", ["nome" => "Segunda", "opcao" => "vazia"]);
    exigir_rodizio($segunda["ok"], "Não criou a segunda coleção do mesmo dono.");
    exigir_rodizio(!op_conta("alterar", ["rodizio_ativo" => "talvez"])["ok"], "A conta aceitou uma escolha inválida.");
    exigir_rodizio(!op_conta("alterar", ["rodizio_ativo" => []])["ok"], "A conta aceitou uma lista.");
    exigir_rodizio(op_conta("alterar", ["rodizio_ativo" => "0"])["ok"] && !rodizio_ativo(), "A coleção não herdou o usuário.");
    exigir_rodizio(rodizio_configuracao()["origem"] === "usuario", "Perdeu a origem do usuário.");
    banco_escopo($segunda["id"]);
    exigir_rodizio(!rodizio_ativo(), "O usuário não valeu na segunda coleção.");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "1"])["ok"] && rodizio_ativo(), "A coleção não substituiu o usuário.");
    entrar_rodizio($outra);
    exigir_rodizio(rodizio_ativo(), "O padrão do primeiro dono vazou para outro dono.");
    banco_escopo($master["colecao_id"]);
    exigir_rodizio(!rodizio_ativo(), "O visitante substituiu o dono no cálculo do rodízio.");
    usuario_definir(null);
    exigir_rodizio(!rodizio_ativo(), "O cron ignorou o dono da coleção.");
    entrar_rodizio($master);
    exigir_rodizio(op_conta("alterar", ["rodizio_ativo" => "1"])["ok"] && rodizio_ativo(), "Não religou o padrão do dono.");
    exigir_rodizio(op_config("salvar", ["parametros" => ["rodizio_ativo" => "0"]])["ok"] && rodizio_ativo(), "O usuário ligado não substituiu o sistema desligado.");
    exigir_rodizio(op_config("salvar", ["parametros" => ["rodizio_ativo" => "1"]])["ok"], "Não restaurou o padrão geral.");
    banco_escopo($segunda["id"]);
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "0"])["ok"] && !rodizio_ativo(), "A coleção não desligou sobre o usuário ligado.");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => ""])["ok"] && rodizio_ativo(), "A coleção não voltou a herdar o usuário.");
    entrar_rodizio($master);
    exigir_rodizio(op_conta("alterar", ["rodizio_ativo" => ""])["ok"] && rodizio_ativo(), "O usuário não voltou a herdar o sistema.");
    exigir_rodizio(op_config("salvar", ["parametros" => ["rodizio_ativo" => "0"]])["ok"], "O master não desligou o padrão geral.");
    exigir_rodizio(!rodizio_ativo() && rodizio_configuracao()["origem"] === "sistema", "A coleção não herdou a pausa.");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "1"])["ok"] && rodizio_ativo(), "A escolha explícita não substituiu o geral.");
    entrar_rodizio($outra);
    exigir_rodizio(!rodizio_ativo(), "A escolha da primeira coleção vazou para a segunda.");
    exigir_rodizio(!op_config("salvar", ["parametros" => ["rodizio_ativo" => "1"]])["ok"], "Um dono que não é master mudou o padrão geral.");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "0"])["ok"], "O dono não desligou a própria coleção.");
    entrar_rodizio($master);
    exigir_rodizio(op_config("salvar", ["parametros" => ["rodizio_ativo" => "1"]])["ok"], "Não religou o padrão geral.");
    entrar_rodizio($outra);
    exigir_rodizio(!rodizio_ativo(), "O padrão geral ignorou a pausa explícita da coleção.");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => ""])["ok"] && rodizio_ativo(), "Não voltou a herdar o padrão geral.");

    entrar_rodizio($master);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $novo = op_relogio("salvar", ["nome" => "Teste", "no_id" => $grupo, "valores" => ["autonomia_dias" => "4", "decaimento_uso" => "25", "decaimento_repouso" => "1"]]);
    exigir_rodizio($novo["ok"], "Não criou o relógio.");
    $rid = $novo["id"];
    $agora = time();
    $hoje = hoje_da_colecao();
    cfg_set("uso_inicio", "00:00");
    cfg_set("uso_fim", "23:59");
    cfg_set("pulso_auto_inicio", "1");
    cfg_set("pulso_auto_fim", "0");
    plano_gravar($hoje->format("Y-m-d"), $rid, null, "manual", "Preservar", null, true);
    plano_gravar($hoje->modify("+1 day")->format("Y-m-d"), $rid, null, "manual", "Preservar amanhã", null, true);
    plano_gravar($hoje->modify("-1 day")->format("Y-m-d"), $rid, null, "manual", "Histórico", null, true);
    exigir_rodizio(op_lancamento("lancar", ["relogio_id" => $rid, "tipo" => "carga", "valor" => "80", "quando" => date("Y-m-d H:i", $agora - 3600), "estado" => "repouso"])["ok"], "Não registrou a leitura.");
    $plano = linhas("SELECT * FROM plano ORDER BY data");
    $lancamentos = linhas("SELECT * FROM lancamento ORDER BY id");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "0"])["ok"], "Não pausou.");
    garantir_plano($hoje);
    gerar_escala($hoje);
    sessao_do_dia(time());
    exigir_rodizio(linhas("SELECT * FROM plano ORDER BY data") === $plano, "A pausa apagou ou mudou a programação.");
    exigir_rodizio(linhas("SELECT * FROM lancamento ORDER BY id") === $lancamentos, "Criou uso automático ou mudou leituras durante a pausa.");
    exigir_rodizio(plano_do_dia($hoje->format("Y-m-d")) === null, "O plano de hoje continuou em execução.");
    exigir_rodizio(plano_do_dia($hoje->modify("-1 day")->format("Y-m-d")) !== null, "O histórico do plano sumiu.");
    exigir_rodizio(!op_rodizio("resortear", [])["ok"], "A API sorteou com o rodízio desligado.");
    exigir_rodizio(!op_rodizio("usando", ["relogio_id" => $rid])["ok"], "A API alterou o plano durante a pausa.");
    exigir_rodizio(itens_noite($hoje) === [], "Continuou preparando o relógio de amanhã.");
    exigir_rodizio(count(array_filter(itens_manha($hoje), function ($x) { return $x["tipo"] === "dia"; })) === 0, "Continuou avisando o relógio do dia.");
    $r = linha("SELECT * FROM relogio WHERE id = ?", [$rid]);
    $autonomia = autonomia_do_relogio($r, time());
    exigir_rodizio($autonomia["energia"] !== null, "A energia ficou desconhecida.");
    exigir_rodizio($autonomia["autonomia_estimada"] === $autonomia["restante_guardado"], "A autonomia ainda simulou o plano pausado.");
    exigir_rodizio(previsao_energia($r, time())["proxima_entrada"] === null, "A previsão ainda preparou uma entrada no rodízio.");
    exigir_rodizio(op_lancamento("iniciar", ["relogio_id" => $rid, "tipo" => "pulso"])["ok"], "Bloqueou a marcação manual.");
    exigir_rodizio(visao_relogio($r, time())["em_uso"], "O pulso manual deixou de valer.");
    sql("DELETE FROM lancamento WHERE relogio_id = ? AND tipo_id IN (SELECT id FROM lancamento_tipo WHERE identificador = 'pulso')", [$rid]);
    $antes = time();
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "1"])["ok"], "Não retomou a coleção.");
    $momento_retomada = time();
    if ($momento_retomada >= instante($hoje->format("Y-m-d"), "23:59")) {
        sessao_do_dia($momento_retomada);
        exigir_rodizio(valor("SELECT id FROM lancamento WHERE origem = 'rodizio'") === null, "Abriu uso depois do fim do horário.");
        $momento_retomada = instante($hoje->modify("+1 day")->format("Y-m-d"), "00:01");
    }
    sessao_do_dia($momento_retomada);
    $sessao = linha("SELECT * FROM lancamento WHERE origem = 'rodizio'");
    exigir_rodizio($sessao !== null && strtotime($sessao["inicio"]) >= $antes, "A retomada preencheu uso retroativo.");
    exigir_rodizio(linhas("SELECT * FROM plano ORDER BY data") === $plano, "A retomada apagou escolhas guardadas.");

    cfg_set("rodizio_retomado_em", (string)($antes - 100));
    exigir_rodizio(op_config("salvar", ["parametros" => ["rodizio_ativo" => "0"]])["ok"] && rodizio_ativo(), "O geral desligou uma coleção explicitamente ligada.");
    exigir_rodizio(op_config("salvar", ["parametros" => ["rodizio_ativo" => "1"]])["ok"], "Não retomou o padrão geral.");
    exigir_rodizio(rodizio_retomado_em() === $antes - 100, "A retomada geral afetou a coleção com escolha explícita.");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "", "parametros" => ["rodizio_ativo" => "0"]])["ok"] && !rodizio_ativo(), "Não herdou o geral na mesma escrita.");
    exigir_rodizio(op_config("salvar", ["parametros" => ["rodizio_ativo" => "1"]])["ok"] && rodizio_retomado_em() >= $antes, "A retomada herdada perdeu o instante.");
    $ancora = rodizio_retomado_em();
    cfg_set("rodizio_retomado_em", "0");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "1"])["ok"] && rodizio_retomado_em() >= $ancora, "A escolha explícita perdeu a retomada herdada.");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => ""])["ok"], "Não voltou à herança para testar a conta.");
    exigir_rodizio(op_conta("alterar", ["rodizio_ativo" => "0"])["ok"] && !rodizio_ativo(), "A pausa do usuário não se propagou.");
    $retomada_usuario = time();
    exigir_rodizio(op_conta("alterar", ["rodizio_ativo" => "1"])["ok"] && rodizio_retomado_em() >= $retomada_usuario, "A retomada do usuário preencheu horas pausadas.");
    banco_escopo($segunda["id"]);
    exigir_rodizio(rodizio_retomado_em() >= $retomada_usuario, "A retomada do usuário não valeu na outra coleção.");
    exigir_rodizio(op_config("salvar", ["rodizio_ativo" => "1"])["ok"], "Não fixou a coleção ligada.");
    $local = rodizio_retomado_em();
    exigir_rodizio(op_conta("alterar", ["rodizio_ativo" => "0"])["ok"] && rodizio_ativo(), "A pausa do usuário ignorou a escolha da coleção.");
    exigir_rodizio(rodizio_retomado_em() === $local, "A pausa do usuário mudou a retomada da coleção explícita.");
    echo "Rodízio: níveis, permissões, pausa, marcações, previsão e retomada conferem.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\nBase: " . $pasta . "\n");
    exit(1);
}
