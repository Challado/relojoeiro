<?php
// recurso=config: a Configuração inteira.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// tudo o que a página Configuração mostra: os valores da coleção, os eventos personalizados, a situação do cron, o sorteio e
// os resumos dos destinos e das agendas (que têm as páginas deles)
$hoje = new DateTimeImmutable("today");
garantir_plano($hoje);
$valores_c = [];
foreach (linhas("SELECT chave, valor FROM config ORDER BY chave") as $c) {
    $valores_c[$c["chave"]] = $c["valor"];
}
$saida = ["config" => (object)$valores_c,
    // os idiomas (o menu Idioma padrão)
    "linguas" => array_map(function ($k) { return ["codigo" => $k, "nome" => LINGUAS[$k]["nome"]]; }, array_keys(LINGUAS)),
    "mostrar_ate" => ["proximos" => proximos_por_pagina(), "plano" => plano_por_pagina()],
    "aleatorio" => ["fontes" => ["RANDOM.ORG", "ANU QRNG", t("gerador seguro do PHP (random_int)")], "ultimo_lote" => sis("aleatorio_ultima") !== "" ? sis("aleatorio_ultima") : null,
        "fora_desde" => sis("aleatorio_fora") !== "" ? date("Y-m-d H:i:s", (int)sis("aleatorio_fora")) : null], "sol_fim" => isset(lancamento_tipos()["sol"]) && lancamento_tipos()["sol"]["fecha_as"] !== null ? substr(lancamento_tipos()["sol"]["fecha_as"], 0, 5) : "",
    // o que é do sistema inteiro (só o master muda): o endereço do sistema
    "sistema" => ["url_sistema" => sis("url_sistema"), "pode_mudar" => usuario_master()],
    // o DOCUMENTOS_COPIA_BANCO do config.php não vale mais (a cópia é escolhida aqui, por coleção): definido, a página avisa
    "config_php_copia" => defined("DOCUMENTOS_COPIA_BANCO") ? (bool)DOCUMENTOS_COPIA_BANCO : null,
    "repeticoes" => $REPETICOES,
    "eventos" => array_map(function ($ev) {
        return array_merge(evento_legivel($ev), ["proximas_60" => array_map(function ($t) { return date("Y-m-d H:i:s", $t); },
            array_slice(ocorrencias($ev, time(), time() + 60 * 86400), 0, 5))]);
    }, eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome"))),
    "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1]; },
        linhas("SELECT id, nome, disponivel FROM relogio ORDER BY nome")),
    "destinos" => array_values(array_map(function ($x) { return ["id" => $x["id"], "nome" => $x["nome"], "tipo" => $x["tipo"], "ativo" => $x["ativo"]]; }, destinos())),
    "agendas" => array_values(array_map(function ($x) { return ["id" => $x["id"], "nome" => $x["nome"], "tipo" => $x["tipo"], "ativa" => $x["ativa"]]; }, agendas())),
    "cron_estado" => cron_estado()];
