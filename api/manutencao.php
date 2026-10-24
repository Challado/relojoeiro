<?php
// recurso=manutencao: a página Manutenção. As abertas (o relógio na oficina agora: a situação, a previsão, se está
// atrasada, o aviso da situação), as manutenções (as do período e dos filtros: relogio=<id>, oficina=<id>, de e ate
// AAAA-MM-DD; sem eles, todas), as oficinas com os contatos, o resumo (quantas abertas, atrasadas, por oficina, o gasto),
// as listas da manutenção (as situações, os tipos de serviço, os tipos de oficina e de contato), os relógios que podem ir
// (os da coleção, com a manutenção aberta de cada um) e os acessórios (o que pode ir junto).
// recurso=oficinas: só as oficinas, os tipos de oficina e os tipos de contato.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$oficinas_m = oficinas_lista();
if (($recurso ?? "manutencao") === "oficinas") {
    $saida = ["oficinas" => $oficinas_m, "tipos" => lista_opcoes("oficina_tipo"), "tipos_contato" => lista_opcoes("contato_tipo")];
    return;
}
$data_m = function ($k) {
    $v = substr(trim((string)($_REQUEST[$k] ?? "")), 0, 10);
    return preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $v) === 1 ? $v : "";
};
$filtro_m = ["relogio" => (int)($_REQUEST["relogio"] ?? 0), "oficina" => (int)($_REQUEST["oficina"] ?? 0), "de" => $data_m("de"), "ate" => $data_m("ate")];
$todas_m = manutencao_lista($filtro_m);
$abertas_m = array_values(array_filter(manutencao_lista(["abertas" => true]), function ($m) { return $m["aberta"]; }));
$por_oficina_m = [];
foreach ($abertas_m as $m) {
    $por_oficina_m[$m["oficina_id"]] = ($por_oficina_m[$m["oficina_id"]] ?? 0) + 1;
}
$abertas_rel = [];
foreach ($abertas_m as $m) {
    $abertas_rel[$m["relogio_id"]] = $m;
}
$gasto_m = array_sum(array_map(function ($m) { return (float)($m["custo"] ?? 0); }, $todas_m));
$saida = ["filtro" => $filtro_m, "hoje" => hoje_da_colecao()->format("Y-m-d"),
    "abertas" => $abertas_m, "manutencoes" => $todas_m, "oficinas" => $oficinas_m,
    "resumo" => ["abertas" => count($abertas_m), "atrasadas" => count(array_filter($abertas_m, function ($m) { return $m["atrasada"]; })),
        "manutencoes" => count($todas_m), "gasto" => round($gasto_m, 2),
        "por_oficina" => array_values(array_map(function ($o) use ($por_oficina_m) {
            return ["oficina_id" => $o["id"], "oficina" => $o["nome"], "abertas" => $por_oficina_m[$o["id"]] ?? 0, "manutencoes" => $o["manutencoes"], "gasto" => $o["gasto"]];
        }, array_filter($oficinas_m, function ($o) { return $o["manutencoes"] > 0; })))],
    "situacoes" => lista_opcoes("manutencao_situacao"), "tipos" => servico_tipos_opcoes(),
    "tipos_oficina" => lista_opcoes("oficina_tipo"), "tipos_contato" => lista_opcoes("contato_tipo"),
    "relogios" => array_values(array_map(function ($r) use ($abertas_rel) {
        $m = $abertas_rel[(int)$r["id"]] ?? null;
        return ["id" => (int)$r["id"], "nome" => $r["nome"], "posse" => (string)$r["posse"], "posse_texto" => posse_texto($r), "disponivel" => (int)$r["disponivel"] === 1,
            "manutencao_id" => $m["id"] ?? null, "em_manutencao" => $m !== null ? t("{1}, desde {2}", [1 => $m["oficina"], 2 => data_txt(strtotime($m["enviado"] . " 00:00:00"))]) : null];
    }, array_filter(linhas("SELECT * FROM relogio ORDER BY nome, id"), "da_colecao"))),
    "acessorios" => array_map(function ($a) {
        return ["id" => (int)$a["id"], "nome" => $a["nome"], "tipo_texto" => lista_nome("acessorio_tipo", $a["tipo"]), "relogio_id" => $a["relogio_id"] === null ? null : (int)$a["relogio_id"]];
    }, linhas("SELECT a.id, a.nome, a.tipo, ar.relogio_id FROM acessorio a LEFT JOIN acessorio_relogio ar ON ar.acessorio_id = a.id ORDER BY a.nome, a.id")),
    "trocar_hoje" => trocar_hoje_permitido()];
