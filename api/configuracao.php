<?php
// recurso=config: a Configuração inteira.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// tudo o que a página Configuração mostra: os valores, os canais (os avisos de cada um, a mensagem padrão e as
// personalizadas), os eventos personalizados, a situação do cron e como as mensagens e a agenda saem hoje
$hoje = new DateTimeImmutable("today");
garantir_plano($hoje);
$valores_c = [];
foreach (linhas("SELECT chave, valor FROM config ORDER BY chave") as $c) {
    $valores_c[$c["chave"]] = $c["valor"];
}
$caminho = cfg("agenda_chave") !== "" ? cfg("agenda_chave") : dirname(__DIR__) . "/google-conta-servico.json";
$json_c = is_file($caminho) && is_readable($caminho) ? json_decode((string)file_get_contents($caminho), true) : null;
$tipos_c = [];
foreach (tipos_de_aviso() as $k => $t) {
    $canais_t = [];
    foreach ($CANAIS as $canal => $c) {
        $l = canal_aviso($canal, $k);
        $canais_t[$canal] = ["marcado" => $l["envia"], "proprio" => $l["propria"], "corpo" => $l["corpo"]];
    }
    $tipos_c[] = ["tipo" => $k, "nome" => $t[0], "quando" => $t[1], "evento" => strpos($k, "ev") === 0 && ctype_digit(substr($k, 2)) ? (int)substr($k, 2) : null, "canais" => $canais_t];
}
$previa = [];
foreach (agenda_desejada($hoje) as $m) {
    if (count($previa) < 8) {
        $previa[] = array_merge(["data" => $m["data"], "hora" => $m["hora"]], evento_formatado($m));
    }
}
$saida = ["config" => (object)$valores_c, "mostrar_ate" => ["proximos" => proximos_por_pagina(), "plano" => plano_por_pagina()],
    "aleatorio" => ["fontes" => ["RANDOM.ORG", "ANU QRNG", "gerador seguro do PHP (random_int)"], "ultimo_lote" => cfg("aleatorio_ultima") !== "" ? cfg("aleatorio_ultima") : null,
        "fora_desde" => cfg("aleatorio_fora") !== "" ? date("Y-m-d H:i:s", (int)cfg("aleatorio_fora")) : null], "sol_fim" => isset(lancamento_tipos()["sol"]) && lancamento_tipos()["sol"]["fecha_as"] !== null ? substr(lancamento_tipos()["sol"]["fecha_as"], 0, 5) : "",
    "chave_agenda" => is_array($json_c) && !empty($json_c["private_key"]) && !empty($json_c["client_email"]) ? ["client_email" => $json_c["client_email"]] : null,
    "pasta" => dirname(__DIR__), "canais" => $CANAIS, "ancoras" => $ANCORAS, "repeticoes" => $REPETICOES, "tipos" => $tipos_c,
    "eventos" => array_map(function ($ev) {
        return array_merge(evento_legivel($ev), ["proximas_60" => array_map(function ($t) { return date("Y-m-d H:i:s", $t); },
            array_slice(ocorrencias($ev, time(), time() + 60 * 86400), 0, 5))]);
    }, eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome"))),
    "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1]; },
        linhas("SELECT id, nome, disponivel FROM relogio ORDER BY nome")),
    "previa" => ["manha" => montar_mensagem($hoje), "noite" => montar_mensagem_noite($hoje), "agenda" => $previa,
        "sincronizados" => (int)valor("SELECT COUNT(*) FROM agenda_evento")], "cron_estado" => cron_estado()];
