<?php
// recurso=simulador: o simulador de rodízio (a página Simulador; o motor em simulador_motor.php). Sem cenarios, o que dá para escolher
// (opcoes: os modos e o que cada um deixa mudar, as formas, os relógios ativos, o período padrão e os limites). Com
// cenarios, também a simulação: cada cenário (modo; forma, ciclo, semanas, escala_dias, max_sem_uso: vazio, como está;
// relogios: os ids dos que entram no sorteio, separados por vírgula; ausente, os de agora) no período de, ate (padrão: de
// amanhã, 4 semanas), com a semente (vazia: uma nova) e a variação de um parâmetro (variar[parametro], variar[de],
// variar[ate], variar[passo]). Nada é gravado: cada cenário roda numa transação desfeita. cenarios e variar vêm como listas
// do formulário (cenarios[0][modo]=2) ou em JSON. Aplicar um cenário: POST recurso=simulador, acao=aplicar.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$lista_sim = function ($bruto) {
    if (is_string($bruto) && trim($bruto) !== "") {
        $j = json_decode($bruto, true);
        return is_array($j) ? $j : null;
    }
    return is_array($bruto) ? $bruto : null;
};
$saida = ["opcoes" => simulador_opcoes()];
if (array_key_exists("cenarios", $_REQUEST)) {
    $cens_sim = $lista_sim($_REQUEST["cenarios"]);
    $variar_sim = array_key_exists("variar", $_REQUEST) ? $lista_sim($_REQUEST["variar"]) : [];
    $sim = simulador_simular($cens_sim === null ? [] : array_values($cens_sim), $_REQUEST["de"] ?? "", $_REQUEST["ate"] ?? "", $_REQUEST["semente"] ?? "", $variar_sim ?? []);
    if (count($sim["erros"]) > 0) {
        $codigo = 400;
        $saida = ["erro" => implode(" ", $sim["erros"]), "erros" => $sim["erros"], "opcoes" => $saida["opcoes"]];
    } else {
        unset($sim["erros"]);
        $saida["simulacao"] = $sim;
    }
}
