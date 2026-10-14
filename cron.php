<?php
// Rodar a cada minuto. O script é MUDO: não escreve nada na saída, nem em caso de erro,
// então o cron não manda e-mail. Cada execução fica registrada no banco (tabela cron_execucao) e aparece
// na página "Execuções do cron"; a última rodada e o erro, se houver, também na Configuração.
//   * * * * *  php /caminho/relojoeiro/cron.php
// Opções:
//   --forcar   roda a manhã e a noite agora, mesmo que já tenham rodado hoje
//   -v         mostra o registro na tela (para rodar à mão)
// A rodada da manhã e a da noite acontecem nos horários da Configuração, uma vez por dia cada: na manhã, a escala
// inteligente se replaneja a partir do estado real; nas duas, a mensagem (se as mensagens estão ligadas) e a
// sincronização do Google Agenda (se ela está ligada). Os eventos personalizados disparam no minuto marcado.

if (php_sapi_name() !== "cli") {
    http_response_code(403);
    exit;
}

$inicio = microtime(true);
$falar = in_array("-v", $argv, true);
$forcar = in_array("--forcar", $argv, true);
$log = [];
// os erros do registro são contados à parte, pelo registro_erro() do lib.php (o texto sai no idioma da instalação: não dá
// para achar o erro pela palavra)
$GLOBALS["REGISTRO_ERROS"] = 0;

// nada de erro na saída: avisos do PHP entram no registro
ini_set("display_errors", "0");
ini_set("log_errors", "0");
set_error_handler(function ($nivel, $texto, $arquivo, $linha) use (&$log) {
    $log[] = registro_erro(t("aviso do PHP: {1} ({2}:{3})", [1 => $texto, 2 => basename($arquivo), 3 => $linha]));
    return true;
});

// erro fatal ou exceção: grava no banco; se nem o banco responder, num arquivo na pasta temporária
register_shutdown_function(function () use (&$log, $falar, $inicio) {
    $e = error_get_last();
    if ($e && in_array($e["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log[] = registro_erro(t("erro fatal: {1} ({2}:{3})", [1 => $e["message"], 2 => basename($e["file"]), 3 => $e["line"]]));
    }
    $texto = date("Y-m-d H:i") . "\n" . implode("\n", $log);
    $teve_erro = $GLOBALS["REGISTRO_ERROS"] > 0;
    if (count($log) > 0) {
        $gravou = false;
        try {
            if (function_exists("sis_set") && banco_tem_tabela("sistema")) {
                sis_set("cron_registro", $texto);
                // o erro fica guardado à parte, para a Configuração mostrar, e é limpo na primeira execução sem erro
                if ($teve_erro || sis("cron_erro") !== "") {
                    sis_set("cron_erro", $teve_erro ? $texto : "");
                }
                $gravou = true;
            }
        } catch (Throwable $t) {
            $gravou = false;
        }
        if (!$gravou) {
            @file_put_contents(sys_get_temp_dir() . "/relogios-cron.log", $texto . "\n\n", FILE_APPEND);
        }
    }
    // toda execução fica na tabela, com ou sem atividade (página "Execuções do cron"), e o que ela fez em cada coleção
    try {
        if (function_exists("sem_escopo") && banco_tem_tabela("cron_execucao_colecao")) {
            sem_escopo(function () use ($inicio, $teve_erro, $log) {
                sql("INSERT INTO cron_execucao (inicio, fim, teve_erro, registro, duracao_ms, teve_atividade) VALUES (?, ?, ?, ?, ?, ?)",
                    [date("Y-m-d H:i:s", (int)$inicio), date("Y-m-d H:i:s"), $teve_erro ? 1 : 0, implode("\n", $log), (int)round((microtime(true) - $inicio) * 1000), count($log) > 0 ? 1 : 0]);
                $id = ultimo_id();
                foreach ($GLOBALS["CRON_COLECOES"] ?? [] as $c => $x) {
                    if (count($x["log"]) > 0) {
                        sql("INSERT INTO cron_execucao_colecao (execucao_id, colecao_id, registro, teve_erro, teve_atividade) VALUES (?, ?, ?, ?, 1)",
                            [$id, $c, implode("\n", $x["log"]), $x["erros"] > 0 ? 1 : 0]);
                    }
                }
            });
        }
    } catch (Throwable $t) {
        // sem a tabela (banco desatualizado) ou sem banco: o registro acima já foi para o sistema ou para o arquivo
    }
    if ($falar) {
        echo $texto . "\n";
    }
});

try {
    require_once __DIR__ . "/lib.php";

    $hoje = new DateTimeImmutable("today");
    $agora = date("H:i");
    // banco desatualizado: registra o que falta e não roda nada, em vez de quebrar no meio
    $pendentes = migracoes_pendentes();
    if ($ERRO_TOKEN !== "") {
        // token da API inválido: o sistema inteiro para, o cron também (registra o motivo, sem escrever na saída)
        $log[] = registro_erro(t("erro: {1}", [1 => $ERRO_TOKEN]));
    } elseif (count($pendentes) > 0) {
        $log[] = registro_erro(t("erro: o banco está desatualizado, falta aplicar {1}. Aplique pela tela de Configuração.", [1 => implode(", ", array_keys($pendentes))]));
    } else {
        // ---------- a parte do sistema ----------
        lingua_definir(sis("idioma"));
        sis_set("cron_ultima_execucao", date("Y-m-d H:i:s"));
        $houve_erro = sis("cron_erro") !== "";
        sem_escopo(function () {
            // execuções antigas: sem atividade ficam 7 dias; com atividade ou erro, 1 ano
            sql("DELETE FROM cron_execucao WHERE teve_atividade = 0 AND inicio < ?", [date("Y-m-d H:i:s", time() - 7 * 86400)]);
            sql("DELETE FROM cron_execucao WHERE inicio < ?", [date("Y-m-d H:i:s", time() - 365 * 86400)]);
        });
        // as contas: a demo que vence (o aviso 7 dias e 1 dia antes; vencida, a conta sai com tudo), os convites e os pedidos
        // vencidos, e quem ficou sem nada (menos o master)
        foreach (contas_limpeza() as $l) {
            $log[] = $l;
        }
        // os documentos: o arquivo que sumiu da pasta volta da cópia no banco; o que ainda não tem cópia ganha (até 256 MB por
        // minuto); a pasta de um relógio que não existe mais e o arquivo que não é de nenhum documento saem. Os perdidos (sem
        // o arquivo e sem a cópia) entram no registro uma vez por dia, depois das 20h. O banco inteiro (todas as coleções)
        foreach (sem_escopo(function () use ($forcar) { return documentos_manutencao(256 * 1024 * 1024, $forcar || (date("H:i") >= "20:00" && substr(sis("documentos_diario"), 0, 10) !== date("Y-m-d"))); }) as $l) {
            $log[] = $l;
        }
        if (date("H:i") >= "20:00" && substr(sis("documentos_diario"), 0, 10) !== date("Y-m-d")) {
            sis_set("documentos_diario", date("Y-m-d H:i:s"));
        }
        // a cópia de segurança do banco inteiro, uma por dia, depois das 20h (só com a BACKUP_PASTA no config.php, e sem
        // BACKUP_ATIVO = false)
        if (backup_ativo() && defined("BACKUP_PASTA") && trim((string)BACKUP_PASTA) !== "" && ($forcar || (date("H:i") >= "20:00" && substr(sis("backup_ultima"), 0, 10) !== date("Y-m-d")))) {
            $b = backup_fazer();
            $log[] = $b["ok"] ? t("cópia de segurança: {1}", [1 => $b["mensagem"]]) : registro_erro(t("erro na cópia de segurança: {1}", [1 => implode(" ", $b["erros"])]));
        }

        // ---------- cada coleção ----------
        $GLOBALS["CRON_COLECOES"] = [];
        foreach (sem_escopo(function () { return array_map("intval", array_column(linhas("SELECT id FROM colecao ORDER BY id"), "id")); }) as $c) {
            $erros_antes = $GLOBALS["REGISTRO_ERROS"];
            $clog = [];
            try {
                banco_escopo($c);
                // as mensagens, a agenda e o registro saem no idioma da coleção (os destinos podem ter o seu)
                lingua_definir(cfg("idioma"));
                $clog = cron_colecao($hoje, $agora, $forcar);
            } catch (Throwable $t) {
                $clog[] = registro_erro(t("erro: {1} ({2}:{3})", [1 => $t->getMessage(), 2 => basename($t->getFile()), 3 => $t->getLine()]));
            }
            banco_escopo(null);
            $GLOBALS["CRON_COLECOES"][$c] = ["log" => $clog, "erros" => $GLOBALS["REGISTRO_ERROS"] - $erros_antes];
            foreach ($clog as $l) {
                $log[] = "[" . $c . "] " . $l;
            }
        }
        lingua_definir(sis("idioma"));

        // saiu de um erro (guardado, ou no último registro): registra que voltou ao normal; o erro guardado é limpo no encerramento
        if ($houve_erro && $GLOBALS["REGISTRO_ERROS"] === 0) {
            $log[] = t("voltou a rodar sem erro");
        }
    }
} catch (Throwable $t) {
    $log[] = registro_erro(t("erro: {1} ({2}:{3})", [1 => $t->getMessage(), 2 => basename($t->getFile()), 3 => $t->getLine()]));
}

// A rodada de uma coleção (com o escopo dela): o plano, as sessões do dia, a manhã e a noite (as mensagens pelos destinos e
// as agendas), os avisos na hora e os eventos personalizados. Devolve as linhas do registro
function cron_colecao($hoje, $agora, $forcar)
{
    $log = [];
    $titulo = defined("MSG_TITULO") ? (string)constant("MSG_TITULO") : t("Relógios");
    // o plano, a cada minuto: se um dia que já deveria existir ainda não existe (à 0h da segunda, a semana nova), ele é
    // montado agora, pelo modo atual. Nos outros minutos só confere, e não registra nada.
    $antes = (int)valor("SELECT COUNT(*) FROM plano WHERE data >= ?", [$hoje->format("Y-m-d")]);
    garantir_plano($hoje);
    $novos = (int)valor("SELECT COUNT(*) FROM plano WHERE data >= ?", [$hoje->format("Y-m-d")]) - $antes;
    if ($novos > 0) {
        $log[] = t("plano: {1}{2}, pelo modo {3}", [1 => $novos, 2 => $novos === 1 ? t(" dia montado") : t(" dias montados"), 3 => valor("SELECT nome FROM modo WHERE id = ?", [modo_ativo()])]);
    }
    // as sessões esquecidas abertas ganham o fim no "fecha às" do tipo (o No pulso, tirando sozinho, no fim do horário de uso)
    fechar_esquecidas(time());
    // o período no pulso do relógio do dia, no horário de uso
    $sessoes = (int)valor("SELECT COUNT(*) FROM lancamento WHERE origem = 'rodizio'");
    sessao_do_dia(time());
    if ((int)valor("SELECT COUNT(*) FROM lancamento WHERE origem = 'rodizio'") > $sessoes) {
        $p = plano_do_dia($hoje->format("Y-m-d"));
        $log[] = t("sessão do dia no pulso aberta: {1}", [1 => $p["nome"] ?? "?"]);
    }

    // ---------- manhã ----------
    if ($forcar || ($agora >= cfg("horario_manha") && cfg("ultima_manha") !== $hoje->format("Y-m-d"))) {
        $log[] = t("rodada da manhã");
        // a escala se replaneja a partir do estado real; os outros modos só sorteiam na virada do período.
        // O período no pulso de cada dia é criado pela sessao_do_dia, a partir do plano.
        if (valor("SELECT escala_dias FROM modo WHERE id = ?", [modo_ativo()]) !== null) {
            $n = gerar_escala($hoje);
            $log[] = t("escala refeita a partir do estado real: {1}{2}, até {3}", [1 => $n, 2 => $n === 1 ? " dia" : " dias", 3 => data_txt(strtotime(cfg("escala_fim")))]);
        }
        garantir_plano($hoje);
        sessao_do_dia(time());
        $r = alertas_enviar(function () use ($hoje) { return itens_manha($hoje); }, $hoje, $titulo, t("alerta da manhã"));
        $log = array_merge($log, $r["linhas"]);
        if ($r["enviados"] + $r["falhas"] === 0) {
            $log[] = t("manhã: nada a avisar");
        }
        $log = array_merge($log, agendas_sincronizar($hoje));
        cfg_set("ultima_manha", $hoje->format("Y-m-d"));
    }

    // ---------- noite ----------
    if ($forcar || ($agora >= cfg("horario_noite") && cfg("ultima_noite") !== $hoje->format("Y-m-d"))) {
        $log[] = t("rodada da noite");
        garantir_plano($hoje);
        $r = alertas_enviar(function () use ($hoje) { return itens_noite($hoje); }, $hoje, t("Relógio de amanhã"), t("alerta da noite"));
        $log = array_merge($log, $r["linhas"]);
        if ($r["enviados"] + $r["falhas"] === 0) {
            $log[] = t("noite: nada a avisar");
        }
        $log = array_merge($log, agendas_sincronizar($hoje));
        cfg_set("ultima_noite", $hoje->format("Y-m-d"));
    }

    // ---------- avisos na hora ----------
    // o aviso marcado "na hora" (o Tirar do carregador) vai pelos destinos no minuto em que vence, uma vez: a lista dos que
    // já foram (relógio:aviso) fica na configuração, e um aviso sai dela quando deixa de vencer (foi resolvido)
    $ja = array_values(array_filter(explode(",", cfg("na_hora_enviados"))));
    $agora_vencidos = [];
    $novos_h = [];
    foreach (avisos_na_hora_vencidos(time()) as $x) {
        $chave = $x[0]["id"] . ":" . $x[1]["identificador"];
        $agora_vencidos[] = $chave;
        if (!in_array($chave, $ja, true)) {
            $log[] = t("aviso na hora: {1} ({2})", [1 => $x[1]["nome"], 2 => $x[0]["nome"]]);
            $novos_h[] = $chave;
        }
    }
    if (count($novos_h) > 0) {
        $r = alertas_enviar(function () use ($novos_h) {
            $itens = [];
            foreach (avisos_na_hora_vencidos(time()) as $x) {
                if (in_array($x[0]["id"] . ":" . $x[1]["identificador"], $novos_h, true)) {
                    $itens[] = ["tipo" => $x[1]["identificador"], "id" => (int)$x[0]["id"], "fazer" => $x[1]["nome"], "motivo" => $x[1]["texto"], "ate" => ""];
                }
            }
            return $itens;
        }, $hoje, $titulo, t("avisos na hora"));
        $log = array_merge($log, $r["linhas"]);
    }
    if (implode(",", $agora_vencidos) !== implode(",", $ja)) {
        cfg_set("na_hora_enviados", implode(",", $agora_vencidos));
    }

    // ---------- eventos personalizados ----------
    // dispara a ocorrência que venceu e ainda não saiu; depois de uma parada (até 7 dias), sai uma vez só
    $disparados = [];
    foreach (eventos_com_dias(linhas("SELECT * FROM evento_personalizado WHERE ativo = 1 ORDER BY id")) as $ev) {
        $vencidas = [];
        foreach (ocorrencias($ev, max(strtotime($ev["criado"]), time() - 7 * 86400), time()) as $ts) {
            if (valor("SELECT evento_id FROM evento_disparo WHERE evento_id = ? AND ocorrencia = ?", [(int)$ev["id"], date("Y-m-d H:i:s", $ts)]) === null) {
                $vencidas[] = $ts;
            }
        }
        if (count($vencidas) > 0) {
            foreach ($vencidas as $ts) {
                sql("INSERT INTO evento_disparo (evento_id, ocorrencia, disparado) VALUES (?, ?, ?)", [(int)$ev["id"], date("Y-m-d H:i:s", $ts), date("Y-m-d H:i:s")]);
            }
            $disparados[] = $ev;
            $log[] = t("evento \"{1}\" disparou", [1 => $ev["nome"]]) . (count($vencidas) > 1 ? t(" ({1} ocorrências atrasadas, enviado uma vez)", [1 => count($vencidas)]) : "");
        }
    }
    if (count($disparados) > 0) {
        $r = alertas_enviar(function () use ($disparados) {
            return array_map(function ($ev) {
                return ["tipo" => "ev" . $ev["id"], "id" => (int)$ev["relogio_id"], "fazer" => $ev["nome"], "motivo" => "", "ate" => ""];
            }, $disparados);
        }, $hoje, $titulo, t("eventos"));
        $log = array_merge($log, $r["linhas"]);
    }
    return $log;
}
