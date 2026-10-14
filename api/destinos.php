<?php
// recurso=destinos: os destinos das mensagens da coleção (webhook, e-mail, Web Push), o que cada um recebe e a prévia de hoje.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$hoje = new DateTimeImmutable("today");
garantir_plano($hoje);
$saida = ["destinos" => array_values(array_map(function ($d) use ($hoje) {
    // a prévia: o que sairia hoje de manhã e à noite por ele (vazio: nada)
    return array_merge(destino_legivel($d), ["previa" => ["manha" => mensagem_do_destino($d, function () use ($hoje) { return itens_manha($hoje); }, $hoje),
        "noite" => mensagem_do_destino($d, function () use ($hoje) { return itens_noite($hoje); }, $hoje)]]);
}, destinos())),
    "tipos" => array_map(function ($k) { return ["tipo" => $k, "nome" => t(DESTINO_TIPOS[$k])]; }, array_keys(DESTINO_TIPOS)),
    "avisos" => array_map(function ($k, $t) { return ["tipo" => $k, "nome" => $t[0], "quando" => $t[1]]; }, array_keys(tipos_de_aviso()), array_values(tipos_de_aviso())),
    "ancoras" => $ANCORAS, "linguas" => array_map(function ($k) { return ["codigo" => $k, "nome" => LINGUAS[$k]["nome"]]; }, array_keys(LINGUAS)),
    "padrao" => ["modelo" => modelo_tradutor(lingua_valida(cfg("idioma")) ?? "pt-BR")(modelo_ler()["mensagens"]["modelo"])],
    "email" => ["configurado" => email_metodo() !== null, "metodo" => email_metodo()],
    "push" => ["configurado" => vapid_configurado(), "chave_publica" => vapid_configurado() ? (string)VAPID_PUBLICA : null],
    "usuarios" => array_map(function ($u) { return ["id" => (int)$u["id"], "login" => $u["login"]]; }, membros_da_colecao())];
