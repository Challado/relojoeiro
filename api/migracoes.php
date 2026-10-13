<?php
// recurso=migracoes: as migrações pendentes do banco (a leitura) e aplicar (a escrita).
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// as migrações: a lista das pendentes, e aplicar (a única escrita aceita com o banco desatualizado)
if ($escrita && $acao === "aplicar") {
    $aplicadas = [];
    foreach ($pendentes as $versao => $m) {
        // os comandos do arquivo, um a um, cada um traduzido para o banco em uso (os comentários saem)
        banco_script((string)file_get_contents(dirname(__DIR__) . "/" . $m[0]));
        // o passo em PHP da migração, se ela tem (o que o SQL comum aos quatro bancos não faz bem)
        if (isset($m[3])) {
            call_user_func($m[3]);
        }
        $aplicadas[] = $versao . " (" . $m[1] . ")";
    }
    $saida = ["ok" => true, "mensagem" => count($aplicadas) > 0 ? t("Aplicadas: {1}.", [1 => implode("; ", $aplicadas)]) : t("Nada a aplicar: o banco está em dia."), "erros" => [], "aplicadas" => $aplicadas];
} elseif ($escrita) {
    $codigo = 400;
    $saida = ["ok" => false, "mensagem" => "", "erros" => [t("Ação desconhecida: use aplicar.")]];
} else {
    $saida = ["pendentes" => array_map(function ($v, $m) {
        return ["versao" => $v, "arquivo" => $m[0], "traz" => $m[1]];
    }, array_keys($pendentes), array_values($pendentes))];
}
