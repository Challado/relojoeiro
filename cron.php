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

// nada de erro na saída: avisos do PHP entram no registro
ini_set("display_errors", "0");
ini_set("log_errors", "0");
set_error_handler(function ($nivel, $texto, $arquivo, $linha) use (&$log) {
    $log[] = "aviso do PHP: " . $texto . " (" . basename($arquivo) . ":" . $linha . ")";
    return true;
});

// erro fatal ou exceção: grava no banco; se nem o banco responder, num arquivo na pasta temporária
register_shutdown_function(function () use (&$log, $falar, $inicio) {
    $e = error_get_last();
    if ($e && in_array($e["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log[] = "erro fatal: " . $e["message"] . " (" . basename($e["file"]) . ":" . $e["line"] . ")";
    }
    $texto = date("Y-m-d H:i") . "\n" . implode("\n", $log);
    $teve_erro = count(preg_grep("/^(erro|aviso do PHP)/", $log)) > 0;
    if (count($log) > 0) {
        $gravou = false;
        try {
            if (function_exists("cfg_set")) {
                cfg_set("cron_registro", $texto);
                // o erro fica guardado à parte, para a Configuração mostrar, e é limpo na primeira execução sem erro
                if ($teve_erro || cfg("cron_erro") !== "") {
                    cfg_set("cron_erro", $teve_erro ? $texto : "");
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
    // toda execução fica na tabela, com ou sem atividade (página "Execuções do cron")
    try {
        if (function_exists("sql")) {
            $dados = [date("Y-m-d H:i:s", (int)$inicio), date("Y-m-d H:i:s"), $teve_erro ? 1 : 0, implode("\n", $log)];
            try {
                sql("INSERT INTO cron_execucao (inicio, fim, teve_erro, registro, duracao_ms, teve_atividade) VALUES (?, ?, ?, ?, ?, ?)",
                    array_merge($dados, [(int)round((microtime(true) - $inicio) * 1000), count($log) > 0 ? 1 : 0]));
            } catch (Throwable $t) {
                // banco desatualizado (sem as colunas da v5): registra só o que houve, para o erro aparecer
                if (count($log) > 0) {
                    sql("INSERT INTO cron_execucao (inicio, fim, teve_erro, registro) VALUES (?, ?, ?, ?)", $dados);
                }
            }
        }
    } catch (Throwable $t) {
        // sem a tabela (banco desatualizado) ou sem banco: o registro acima já foi para a configuração ou para o arquivo
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
        $log[] = "erro: " . $ERRO_TOKEN;
    } elseif (count($pendentes) > 0) {
        $log[] = "erro: o banco está desatualizado, falta aplicar " . implode(", ", array_keys($pendentes)) . ". Aplique pela tela de Configuração.";
    } else {
        cfg_set("cron_ultima_execucao", date("Y-m-d H:i:s"));
        // execuções antigas: sem atividade ficam 7 dias; com atividade ou erro, 1 ano
        sql("DELETE FROM cron_execucao WHERE teve_atividade = 0 AND inicio < ?", [date("Y-m-d H:i:s", time() - 7 * 86400)]);
        sql("DELETE FROM cron_execucao WHERE inicio < ?", [date("Y-m-d H:i:s", time() - 365 * 86400)]);
        $houve_erro = cfg("cron_erro") !== "";

        // o plano, a cada minuto: se um dia que já deveria existir ainda não existe (à 0h da segunda, a semana nova), ele é
        // montado agora, pelo modo atual. Nos outros minutos só confere, e não registra nada.
        $antes = (int)valor("SELECT COUNT(*) FROM plano WHERE data >= ?", [$hoje->format("Y-m-d")]);
        garantir_plano($hoje);
        $novos = (int)valor("SELECT COUNT(*) FROM plano WHERE data >= ?", [$hoje->format("Y-m-d")]) - $antes;
        if ($novos > 0) {
            $log[] = "plano: " . $novos . ($novos === 1 ? " dia montado" : " dias montados") . ", pelo modo " . valor("SELECT nome FROM modo WHERE id = ?", [modo_ativo()]);
        }
        // as sessões esquecidas abertas ganham o fim no "fecha às" do tipo (o No pulso, tirando sozinho, no fim do horário de uso)
        fechar_esquecidas(time());
        // o período no pulso do relógio do dia, no horário de uso
        $sessoes = (int)valor("SELECT COUNT(*) FROM lancamento WHERE origem = 'rodizio'");
        sessao_do_dia(time());
        if ((int)valor("SELECT COUNT(*) FROM lancamento WHERE origem = 'rodizio'") > $sessoes) {
            $p = plano_do_dia($hoje->format("Y-m-d"));
            $log[] = "sessão do dia no pulso aberta: " . ($p["nome"] ?? "?");
        }
        $titulo = defined("MSG_TITULO") ? (string)constant("MSG_TITULO") : "Relógios";

        // os documentos: o arquivo que sumiu da pasta volta da cópia no banco; o que ainda não tem cópia ganha (até 256 MB por
        // minuto); a pasta de um relógio que não existe mais e o arquivo que não é de nenhum documento saem. Os perdidos (sem
        // o arquivo e sem a cópia) entram no registro uma vez por dia, na rodada da noite
        $noite_agora = $forcar || ($agora >= cfg("horario_noite") && cfg("ultima_noite") !== $hoje->format("Y-m-d"));
        foreach (documentos_manutencao(256 * 1024 * 1024, $noite_agora) as $l) {
            $log[] = $l;
        }

        // ---------- manhã ----------
        if ($forcar || ($agora >= cfg("horario_manha") && cfg("ultima_manha") !== $hoje->format("Y-m-d"))) {
            $log[] = "rodada da manhã";
            // a escala se replaneja a partir do estado real; os outros modos só sorteiam na virada do período.
            // O período no pulso de cada dia é criado pela sessao_do_dia, a partir do plano.
            if (valor("SELECT escala_dias FROM modo WHERE id = ?", [modo_ativo()]) !== null) {
                $n = gerar_escala($hoje);
                $log[] = "escala refeita a partir do estado real: " . $n . ($n === 1 ? " dia" : " dias") . ", até " . date("d/m/Y", strtotime(cfg("escala_fim")));
            }
            garantir_plano($hoje);
            sessao_do_dia(time());
            $texto = montar_mensagem($hoje);
            if (cfg("mensagens_ativas") === "1" && $texto !== "") {
                $log[] = enviar_em_partes($texto, $titulo) === 0 ? "alerta da manhã enviado" : "erro: a API de alerta não aceitou o alerta da manhã";
            } else {
                $log[] = $texto === "" ? "manhã: nada a avisar" : "manhã: alerta desligado";
            }
            if (cfg("agenda_ativa") === "1") {
                $r = sincronizar_agenda($hoje);
                $log[] = ($r["mensagem"] !== "" || $r["erros"] > 0 ? "erro na agenda: " : "agenda: ") . $r["criados"] . " criados, " . $r["atualizados"] . " atualizados, "
                    . $r["removidos"] . " removidos, " . $r["erros"] . " recusados " . $r["mensagem"];
            }
            cfg_set("ultima_manha", $hoje->format("Y-m-d"));
        }

        // ---------- noite ----------
        if ($forcar || ($agora >= cfg("horario_noite") && cfg("ultima_noite") !== $hoje->format("Y-m-d"))) {
            $log[] = "rodada da noite";
            garantir_plano($hoje);
            $texto = montar_mensagem_noite($hoje);
            if (cfg("mensagens_ativas") === "1" && $texto !== "") {
                $log[] = enviar_em_partes($texto, "Relógio de amanhã") === 0 ? "alerta da noite enviado" : "erro: a API de alerta não aceitou o alerta da noite";
            } else {
                $log[] = $texto === "" ? "noite: amanhã continua o mesmo relógio, nada a preparar" : "noite: alerta desligado";
            }
            if (cfg("agenda_ativa") === "1") {
                $r = sincronizar_agenda($hoje);
                $log[] = ($r["mensagem"] !== "" || $r["erros"] > 0 ? "erro na agenda: " : "agenda: ") . $r["criados"] . " criados, " . $r["atualizados"] . " atualizados, "
                    . $r["removidos"] . " removidos, " . $r["erros"] . " recusados " . $r["mensagem"];
            }
            cfg_set("ultima_noite", $hoje->format("Y-m-d"));
        }

        // ---------- eventos personalizados ----------
        // dispara a ocorrência que venceu e ainda não saiu; depois de uma parada (até 7 dias), sai uma vez só
        $itens_ev = [];
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
                if (canal_aviso("tg", "ev" . $ev["id"])["envia"]) {
                    $itens_ev[] = ["tipo" => "ev" . $ev["id"], "id" => (int)$ev["relogio_id"], "fazer" => $ev["nome"], "motivo" => "", "ate" => ""];
                }
                $log[] = "evento \"" . $ev["nome"] . "\" disparou" . (count($vencidas) > 1 ? " (" . count($vencidas) . " ocorrências atrasadas, enviado uma vez)" : "");
            }
        }
        if (count($itens_ev) > 0) {
            if (cfg("mensagens_ativas") === "1") {
                $log[] = enviar_em_partes(mensagem_telegram($itens_ev, $hoje), $titulo) === 0 ? "eventos enviados ao Telegram" : "erro: a API de alerta não aceitou os eventos";
            } else {
                $log[] = "eventos: alerta desligado";
            }
        }

        // saiu de um erro (guardado, ou no último registro): registra que voltou ao normal; o erro guardado é limpo no encerramento
        if ($houve_erro && count(preg_grep("/^(erro|aviso do PHP)/", $log)) === 0) {
            $log[] = "voltou a rodar sem erro";
        }
    }
} catch (Throwable $t) {
    $log[] = "erro: " . $t->getMessage() . " (" . basename($t->getFile()) . ":" . $t->getLine() . ")";
}
