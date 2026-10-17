<?php
// recurso=hora_certa: a hora do servidor para acertar os relógios: a local (com o fuso), a UTC (Zulu), a letra militar do
// fuso e o grupo data-hora, o calendário (dia do ano, semana ISO, trimestre, dia juliano) e a fase da lua (pelo ciclo médio,
// aproximada). agora_ms é o instante em milissegundos: a página acerta o relógio dela por ele (descontando metade do tempo
// do pedido) e anda sozinha, segundo a segundo. Não usa o banco.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$micro_h = microtime(true);
$agora_h = (int)floor($micro_h);
$local_h = (new DateTimeImmutable("@" . $agora_h))->setTimezone(new DateTimeZone(date_default_timezone_get()));
$utc_h = (new DateTimeImmutable("@" . $agora_h))->setTimezone(new DateTimeZone("UTC"));
$desl_h = $local_h->getOffset();
$semana_h = ["", t("segunda-feira"), t("terça-feira"), t("quarta-feira"), t("quinta-feira"), t("sexta-feira"), t("sábado"), t("domingo")];
$meses_h = [t("janeiro"), t("fevereiro"), t("março"), t("abril"), t("maio"), t("junho"), t("julho"), t("agosto"), t("setembro"), t("outubro"), t("novembro"), t("dezembro")];
$meses_mil = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"];

// a letra militar do fuso (o alfabeto da OTAN): A a M (sem J) a leste, N a Y a oeste, Z no UTC; fuso fora da hora cheia: sem letra
$letras_h = [0 => ["Z", "Zulu"], 1 => ["A", "Alfa"], 2 => ["B", "Bravo"], 3 => ["C", "Charlie"], 4 => ["D", "Delta"], 5 => ["E", "Echo"], 6 => ["F", "Foxtrot"],
    7 => ["G", "Golf"], 8 => ["H", "Hotel"], 9 => ["I", "India"], 10 => ["K", "Kilo"], 11 => ["L", "Lima"], 12 => ["M", "Mike"], -1 => ["N", "November"],
    -2 => ["O", "Oscar"], -3 => ["P", "Papa"], -4 => ["Q", "Quebec"], -5 => ["R", "Romeo"], -6 => ["S", "Sierra"], -7 => ["T", "Tango"], -8 => ["U", "Uniform"],
    -9 => ["V", "Victor"], -10 => ["W", "Whiskey"], -11 => ["X", "X-ray"], -12 => ["Y", "Yankee"]];
$letra_h = $desl_h % 3600 === 0 && isset($letras_h[intdiv($desl_h, 3600)]) ? $letras_h[intdiv($desl_h, 3600)] : null;
// o grupo data-hora (DTG): DDHHMM, a letra do fuso, o mês em inglês e o ano com dois dígitos ("131714P OCT 26")
$dtg = function ($t, $letra) use ($meses_mil) {
    return $t->format("dHi") . $letra . " " . $meses_mil[(int)$t->format("n") - 1] . " " . $t->format("y");
};

// a lua: a idade (dias desde a última lua nova, pelo ciclo sinódico médio a partir da lua nova de 06/01/2000 18:14 UTC), a parte
// iluminada e a fase; as próximas cheia e nova pela mesma conta (erro de até um dia, como toda conta pelo ciclo médio)
$ciclo = 29.530588853;
$jd_h = $micro_h / 86400 + 2440587.5;
$idade = fmod($jd_h - 2451550.1, $ciclo);
if ($idade < 0) {
    $idade += $ciclo;
}
$fases = [[1.84566, "nova", t("Lua nova"), "🌑"], [5.53699, "crescente", t("Crescente"), "🌒"], [9.22831, "quarto_crescente", t("Quarto crescente"), "🌓"],
    [12.91963, "crescente_gibosa", t("Crescente gibosa"), "🌔"], [16.61096, "cheia", t("Lua cheia"), "🌕"], [20.30228, "minguante_gibosa", t("Minguante gibosa"), "🌖"],
    [23.99361, "quarto_minguante", t("Quarto minguante"), "🌗"], [27.68493, "minguante", t("Minguante"), "🌘"], [99, "nova", t("Lua nova"), "🌑"]];
foreach ($fases as $f) {
    if ($idade < $f[0]) {
        $fase = $f;
        break;
    }
}
$proxima = function ($alvo) use ($idade, $ciclo, $micro_h) {
    $falta = $alvo - $idade;
    if ($falta <= 0.5) {
        $falta += $ciclo;
    }
    return date("Y-m-d", (int)($micro_h + $falta * 86400));
};

$ano_h = (int)$local_h->format("Y");
$saida = ["agora" => $agora_h, "agora_ms" => (int)round($micro_h * 1000),
    "local" => ["data" => $local_h->format("Y-m-d"), "hora" => $local_h->format("H:i:s"), "iso8601" => $local_h->format("c"),
        "dia_semana" => $semana_h[(int)$local_h->format("N")],
        "texto" => t("{1}, {2} de {3} de {4}", [1 => $semana_h[(int)$local_h->format("N")], 2 => (int)$local_h->format("j"), 3 => $meses_h[(int)$local_h->format("n") - 1], 4 => $ano_h])],
    "fuso" => ["nome" => date_default_timezone_get(), "origem" => fuso_origem(), "deslocamento" => $local_h->format("P"), "segundos" => $desl_h, "abreviacao" => $local_h->format("T"),
        "horario_de_verao" => $local_h->format("I") === "1"],
    // o relógio da página (a Configuração, "Relógio da Hora certa"): analogico, digital, anadigi ou ambos (o padrão)
    "relogio" => in_array(cfg("hora_relogio"), ["analogico", "digital", "anadigi", "ambos"], true) ? cfg("hora_relogio") : "ambos",
    "utc" => ["data" => $utc_h->format("Y-m-d"), "hora" => $utc_h->format("H:i:s"), "iso8601" => $utc_h->format("Y-m-d\\TH:i:s\\Z")],
    "militar" => ["letra" => $letra_h[0] ?? null, "nome" => $letra_h[1] ?? null,
        "grupo_data_hora_local" => $letra_h !== null ? $dtg($local_h, $letra_h[0]) : null, "grupo_data_hora_zulu" => $dtg($utc_h, "Z")],
    "calendario" => ["dia_do_ano" => (int)$local_h->format("z") + 1, "dias_no_ano" => $local_h->format("L") === "1" ? 366 : 365,
        "faltam_no_ano" => ($local_h->format("L") === "1" ? 366 : 365) - ((int)$local_h->format("z") + 1),
        "semana_iso" => (int)$local_h->format("W"), "ano_iso" => (int)$local_h->format("o"), "trimestre" => intdiv((int)$local_h->format("n") - 1, 3) + 1,
        "bissexto" => $local_h->format("L") === "1", "dia_juliano" => round($jd_h, 5), "unix" => $agora_h],
    "lua" => ["fase" => $fase[1], "nome" => $fase[2], "simbolo" => $fase[3], "idade_dias" => round($idade, 1),
        "iluminacao" => round((1 - cos(2 * M_PI * $idade / $ciclo)) / 2 * 100, 1),
        "proxima_cheia" => $proxima($ciclo / 2), "proxima_nova" => $proxima($ciclo)],
    "como_acertar" => t("Puxe a coroa com o ponteiro dos segundos no 12 (ele para nos relógios com parada de segundos, o hack), ponha os ponteiros um ou dois minutos à frente e empurre a coroa quando o segundo chegar ao :00 da hora certa. Num relógio com data, troque a data longe da meia-noite (fora das 21h às 3h), para não forçar o mecanismo.")];
