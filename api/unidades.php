<?php
// recurso=unidades: as unidades da árvore (Cadastros › Unidades), na ordem das grandezas (cada base, seguida das que se
// convertem nela, da menor para a maior), cada uma com a conversão em texto e quantos campos, tipos de lançamento e
// fórmulas a usam; e as grandezas (cada base com as unidades dela: o que o "mostrar também em" pode escolher).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$todas_u = unidades_todas(true);
$usos_u = [];
foreach (["campo" => "campos", "lancamento_tipo" => "tipos", "formula" => "formulas"] as $tabela_u => $chave_u) {
    foreach (linhas("SELECT unidade, COUNT(*) AS n FROM " . $tabela_u . " WHERE unidade <> '' GROUP BY unidade") as $x) {
        $usos_u[$x["unidade"]][$chave_u] = (int)$x["n"];
    }
}
$lista_u = [];
$grandezas_u = [];
foreach ($todas_u as $b) {
    if ($b["base"] !== null) {
        continue;
    }
    $familia = array_values(array_filter($todas_u, function ($u) use ($b) { return $u["base"] === $b["simbolo"]; }));
    usort($familia, function ($x, $y) { return [$x["fator"], $x["simbolo"]] <=> [$y["fator"], $y["simbolo"]]; });
    $familia = array_merge([$b], $familia);
    $grandezas_u[] = ["base" => $b["simbolo"], "nome" => $b["nome"], "unidades" => array_column($familia, "simbolo")];
    foreach ($familia as $u) {
        $uso = $usos_u[$u["simbolo"]] ?? [];
        $lista_u[] = array_merge($u, ["conversao" => unidade_conversao_texto($u),
            "usos" => ["campos" => $uso["campos"] ?? 0, "tipos" => $uso["tipos"] ?? 0, "formulas" => $uso["formulas"] ?? 0]]);
    }
}
$saida = ["unidades" => $lista_u, "grandezas" => $grandezas_u];
