<?php
// Compara duas fotografias do testes/cenario.php (a de referência e a de outro banco) e diz onde não saíram iguais.
//   php testes/comparar.php referencia.json outro.json [--leves]
// O cenário roda em momentos diferentes (alguns segundos entre um banco e outro), e o sistema calcula tudo a partir de
// agora: por isso os horários e as contas que andam com o tempo têm uma folga. O resto tem de ser idêntico, inclusive o
// tipo (5 não é "5"). As diferenças são de dois tipos:
//   graves: estrutura, tipo, texto, quantidade, ordem — o que está errado;
//   leves:  só os números dentro de um texto ("em 3h 12min"), provavelmente o tempo que passou; com --leves, mostra.
if (PHP_SAPI !== "cli" || count($argv) < 3) {
    fwrite(STDERR, "Uso: php testes/comparar.php <referência.json> <outro.json> [--leves]\n");
    exit(2);
}
$a = json_decode((string)file_get_contents($argv[1]), true);
$b = json_decode((string)file_get_contents($argv[2]), true);
if (!is_array($a) || !is_array($b)) {
    fwrite(STDERR, "Uma das fotografias não é um JSON válido.\n");
    exit(2);
}
$mostrar_leves = in_array("--leves", $argv, true);
$graves = [];
$leves = [];

// uma data e hora ("2026-09-27 14:05:09", "2026-09-27 14:05", com T ou fuso): o instante; senão, null
function instante($v)
{
    if (!is_string($v) || preg_match("/^\\d{4}-\\d{2}-\\d{2}[ T]\\d{2}:\\d{2}/", $v) !== 1) {
        return null;
    }
    $t = strtotime($v);
    return $t === false ? null : $t;
}

function numeros_iguais($x, $y, $chave)
{
    if ($x == $y) {
        return true;
    }
    // o que é medido em segundos, ou é um instante: a folga do tempo entre as duas rodadas
    if (preg_match("/segundos|_seg\$|timestamp|restante|autonomia|duracao|_ms\$|ultimo\$/", (string)$chave) === 1 && abs($x - $y) <= 120) {
        return true;
    }
    // o resto (energia, reserva, notas...) anda devagar com o tempo (uma sessão aberta cresce enquanto o cenário roda): 0,5% de folga
    if ($chave === "porcentagem" && abs($x - $y) <= 0.5) {
        return true;
    }
    // (e os valores bem pequenos, como os dias sem uso de quem acabou de sair do pulso: 3 ou 4 segundos em dias)
    return abs($x - $y) <= max(1e-3, 5e-3 * max(abs($x), abs($y)));
}

function comparar($x, $y, $caminho, $chave)
{
    global $graves, $leves;
    // o id das faixas dos critérios: o MySQL (InnoDB) reserva ids em blocos num INSERT ... SELECT de várias linhas e deixa
    // buracos na sequência; os outros bancos, não. O id não quer dizer nada além de identificar a faixa
    if (preg_match("/\.faixas\[\d+\]\.id$/", $caminho) === 1) {
        return;
    }
    // a mensagem de erro que o próprio banco escreveu (cada um tem a sua redação), e quanto tempo o cron levou (desempenho)
    if ($chave === "detalhe" && preg_match("/\\.resposta\\.detalhe\$/", $caminho) === 1) {
        return;
    }
    if (in_array($chave, ["duracao_ms", "mais_lenta_ms"], true)) {
        return;
    }
    // o hash da senha: cada cálculo usa um sal novo, então ele nunca sai igual; vale ser um hash do mesmo algoritmo
    if ($chave === "senha_hash" && is_string($x) && is_string($y)) {
        if (substr($x, 0, 4) !== substr($y, 0, 4)) {
            $graves[] = [$caminho, "algoritmo diferente", $x, $y];
        }
        return;
    }
    if (is_array($x) && is_array($y)) {
        $lista_x = array_is_list($x);
        if ($lista_x !== array_is_list($y) && count($x) > 0 && count($y) > 0) {
            $graves[] = [$caminho, "lista num, objeto no outro", "", ""];
            return;
        }
        if ($lista_x && count($x) !== count($y)) {
            $graves[] = [$caminho, "quantidade diferente", count($x), count($y)];
        }
        foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $k) {
            if (!array_key_exists($k, $x) || !array_key_exists($k, $y)) {
                if (!$lista_x) {
                    $graves[] = [$caminho . "." . $k, "só existe num", array_key_exists($k, $x) ? "presente" : "ausente", array_key_exists($k, $y) ? "presente" : "ausente"];
                }
                continue;
            }
            comparar($x[$k], $y[$k], $caminho . ($lista_x ? "[" . $k . "]" : "." . $k), $lista_x ? $chave : $k);
        }
        return;
    }
    // o hash da senha dentro de um texto (a resposta em XML): também só o algoritmo conta
    if (is_string($x) && is_string($y) && strpos($x, "<senha_hash>") !== false) {
        $sem_sal = function ($t) { return preg_replace("#<senha_hash>(\\$[0-9a-z]+\\$)[^<]*</senha_hash>#", "<senha_hash>\\1</senha_hash>", $t); };
        $x = $sem_sal($x);
        $y = $sem_sal($y);
    }
    if ($x === $y) {
        return;
    }
    if (gettype($x) !== gettype($y) && !(is_numeric($x) && is_numeric($y) && !is_string($x) && !is_string($y))) {
        $graves[] = [$caminho, "tipo diferente", json_encode($x, JSON_UNESCAPED_UNICODE), json_encode($y, JSON_UNESCAPED_UNICODE)];
        return;
    }
    if ((is_int($x) || is_float($x)) && numeros_iguais($x, $y, $chave)) {
        return;
    }
    // um inteiro arredondado bem na fronteira (23,499 e 23,501, calculados com segundos de diferença): 23 e 24
    if (is_int($x) && is_int($y) && abs($x - $y) === 1) {
        $leves[] = [$caminho, "arredondamento", $x, $y];
        return;
    }
    $ix = instante($x);
    $iy = instante($y);
    if ($ix !== null && $iy !== null && abs($ix - $iy) <= 120) {
        return;
    }
    if (is_string($x) && is_string($y) && preg_replace("/\\d+/", "#", $x) === preg_replace("/\\d+/", "#", $y)) {
        $leves[] = [$caminho, "números no texto", $x, $y];
        return;
    }
    $graves[] = [$caminho, "valor diferente", json_encode($x, JSON_UNESCAPED_UNICODE), json_encode($y, JSON_UNESCAPED_UNICODE)];
}

// a pasta onde o sistema está instalado muda de uma instalação para outra
foreach (["config", "2 config"] as $l) {
    unset($a["leituras"][$l]["resposta"]["pasta"], $b["leituras"][$l]["resposta"]["pasta"]);
}
comparar($a, $b, "", "");
$corta = function ($s) {
    $s = (string)$s;
    return strlen($s) > 160 ? substr($s, 0, 157) . "..." : $s;
};
foreach ($graves as $g) {
    echo "GRAVE  " . $g[0] . "  (" . $g[1] . ")\n         ref: " . $corta($g[2]) . "\n       outro: " . $corta($g[3]) . "\n";
}
if ($mostrar_leves) {
    foreach ($leves as $g) {
        echo "leve   " . $g[0] . "\n         ref: " . $corta($g[2]) . "\n       outro: " . $corta($g[3]) . "\n";
    }
}
echo count($graves) . " diferenças graves, " . count($leves) . " leves" . ($mostrar_leves || count($leves) === 0 ? "" : " (--leves mostra)") . "\n";
exit(count($graves) > 0 ? 1 : 0);
