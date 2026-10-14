<?php
// recurso=agendas: as agendas da coleção (Google, CalDAV, Outlook, link ICS), o que cada uma recebe e o que tem de estar
// nela agora. recurso=outlook_retorno: a volta da página da Microsoft depois de conectar a conta (code e state).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$hoje = new DateTimeImmutable("today");
garantir_plano($hoje);
$desejadas = [];
foreach (agendas_desejadas() as $x) {
    $desejadas[$x["agenda_id"]] = $x["eventos"];
}
$saida = ["agendas" => array_values(array_map(function ($ag) use ($desejadas) {
    return array_merge(agenda_legivel($ag), ["previa" => array_slice($desejadas[$ag["id"]] ?? [], 0, 12), "previstos" => count($desejadas[$ag["id"]] ?? [])]);
}, agendas())),
    "tipos" => array_map(function ($k) { return ["tipo" => $k, "nome" => t(AGENDA_TIPOS[$k])]; }, array_keys(AGENDA_TIPOS)),
    "avisos" => array_map(function ($k, $t) { return ["tipo" => $k, "nome" => $t[0], "quando" => $t[1]]; }, array_keys(tipos_de_aviso()), array_values(tipos_de_aviso())),
    "ancoras" => $ANCORAS, "linguas" => array_map(function ($k) { return ["codigo" => $k, "nome" => LINGUAS[$k]["nome"]]; }, array_keys(LINGUAS)),
    "padrao" => ["modelo" => modelo_tradutor(lingua_valida(cfg("idioma")) ?? "pt-BR")(modelo_ler()["agenda"]["modelo"]), "janela_dias" => (int)modelo_ler()["agenda"]["janela_dias"]],
    "outlook" => ["configurado" => trim((string)cfg_php("OUTLOOK_CLIENTE_ID", "")) !== "", "retorno" => outlook_retorno_url()],
    // a pasta do sistema (a dica do caminho da chave do Google): só para o master
    "pasta" => usuario_master() ? dirname(__DIR__) : null];
