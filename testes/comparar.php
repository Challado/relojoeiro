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
    // o que é medido em segundos, ou é um instante: a folga do tempo entre as duas rodadas (120 s), mais o atraso de um banco
    // em relação ao outro nesta leitura (FOLGA_ATRASO: a sessão aberta da linha do tempo cresce enquanto o cenário roda, e
    // no banco mais lento os passos até a leitura levam mais tempo)
    $folga = 120 + ($GLOBALS["FOLGA_ATRASO"] ?? 0);
    if (preg_match("/segundos|_seg\$|timestamp|restante|autonomia|duracao|_ms\$|ultimo\$/", (string)$chave) === 1 && abs($x - $y) <= $folga) {
        return true;
    }
    // os minutos desde a última rodada do cron (cron_estado.minutos): a mesma folga, em minutos inteiros
    if ($chave === "minutos" && abs($x - $y) <= ceil($folga / 60)) {
        return true;
    }
    // o que é medido em horas (HORAS("pulso"; 30), numa conta da fórmula): a sessão aberta cresce enquanto o cenário roda; a
    // mesma folga, em horas
    if (strpos((string)$chave, "HORAS(") === 0 && abs($x - $y) <= $folga / 3600) {
        return true;
    }
    // o resto (energia, reserva, notas...) anda devagar com o tempo (uma sessão aberta cresce enquanto o cenário roda): 0,5% de folga
    if (in_array($chave, ["porcentagem", "porcentagem_horas", "barra"], true) && abs($x - $y) <= 0.5) {
        return true;
    }
    // as horas no pulso das estatísticas (uma casa): a sessão aberta cresce enquanto o cenário roda, um passo do arredondamento
    if (preg_match("/horas/", (string)$chave) === 1 && abs($x - $y) <= 0.1 + 1e-9) {
        return true;
    }
    // a idade da Lua (uma casa, em dias): anda com o relógio de verdade, e segundos de diferença cruzam um passo do
    // arredondamento (12 e 12,1)
    if ($chave === "idade_dias" && abs($x - $y) <= 0.1 + 1e-9) {
        return true;
    }
    // (e os valores bem pequenos, como os dias sem uso de quem acabou de sair do pulso: 3 ou 4 segundos em dias; e o que é
    // medido em dias, como a conta de uma fórmula com os dias desde um lançamento: a mesma folga do tempo, em dias)
    return abs($x - $y) <= max(1e-3, $folga / 86400, 5e-3 * max(abs($x), abs($y)));
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
        // dois vizinhos com a mesma hora (os avisos em ordem de data, previstos com segundos de diferença entre um banco e o
        // outro): trocados de lugar dentro da folga do tempo, é a mesma lista; o mesmo relógio e o mesmo identificador
        // identificam cada um. Qualquer outra troca de ordem continua grave
        if ($lista_x && count($x) === count($y) && array_is_list($y)) {
            $folga_o = 120 + ($GLOBALS["FOLGA_ATRASO"] ?? 0);
            // quem é (o aviso: o relógio e o identificador; a linha de um relatório: as duas primeiras colunas) e a hora dele (a
            // data do aviso; na linha, a data e a hora dentro do texto, "24/10/2026 19:44")
            $quem = function ($e) {
                if (is_array($e) && !array_is_list($e)) {
                    return isset($e["data"]) ? json_encode([$e["relogio_id"] ?? null, $e["identificador"] ?? null]) : null;
                }
                return is_array($e) && count($e) >= 3 && is_string($e[0]) && is_string($e[1]) ? json_encode([$e[0], $e[1]]) : null;
            };
            $hora = function ($e) {
                if (is_array($e) && !array_is_list($e)) {
                    return instante($e["data"] ?? null);
                }
                foreach (is_array($e) ? $e : [] as $c) {
                    if (is_string($c) && preg_match("#(\\d{2})/(\\d{2})/(\\d{4}) (\\d{2}:\\d{2})#", $c, $m) === 1) {
                        return instante($m[3] . "-" . $m[2] . "-" . $m[1] . " " . $m[4]);
                    }
                }
                return null;
            };
            for ($i = 0; $i + 1 < count($x); $i++) {
                $a0 = $quem($x[$i]);
                $a1 = $quem($x[$i + 1]);
                $t0 = $hora($x[$i]);
                $t1 = $hora($x[$i + 1]);
                if ($a0 !== null && $a1 !== null && $a0 !== $a1 && $t0 !== null && $t1 !== null && abs($t0 - $t1) <= $folga_o
                    && $quem($y[$i]) === $a1 && $quem($y[$i + 1]) === $a0) {
                    [$x[$i], $x[$i + 1]] = [$x[$i + 1], $x[$i]];
                    $leves[] = [$caminho . "[" . $i . "]", "empate na hora, trocados de lugar", $a0, $a1];
                    $i++;
                }
            }
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
    // o tamanho do arquivo da cópia de segurança: comprimido, com o nome do banco e a hora no cabeçalho; uns bytes de diferença
    // (até 2%) são leves. Mais que isso, é outra coisa
    if ($chave === "bytes" && preg_match("/^\\.leituras\\.backup\\.resposta\\.copias\\[\\d+\\]\\.bytes\$/", $caminho) === 1 && is_int($x) && is_int($y)
        && abs($x - $y) <= 0.02 * max($x, $y)) {
        $leves[] = [$caminho, "tamanho da cópia", $x, $y];
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
    // um prazo relativo no texto ("vence em 5d" e "vence em 4d 23h", "em 12h 58min" e "em 13h"): calculado com segundos de
    // diferença, cruza o arredondamento; e, no mesmo texto, a data pode ter virado o minuto ("13:16" e "13:17"), por isso os
    // outros números também contam como iguais
    $prazo = function ($t) { return preg_replace("/\\d+/", "#", preg_replace("/\\d+(a|m|d|h|min)( \\d+(a|m|d|h|min))*\\b/u", "<prazo>", $t)); };
    if (is_string($x) && is_string($y) && $prazo($x) === $prazo($y)) {
        $leves[] = [$caminho, "prazo no texto", $x, $y];
        return;
    }
    $graves[] = [$caminho, "valor diferente", json_encode($x, JSON_UNESCAPED_UNICODE), json_encode($y, JSON_UNESCAPED_UNICODE)];
}

// a pasta onde o sistema está instalado muda de uma instalação para outra
foreach (["config", "2 config"] as $l) {
    unset($a["leituras"][$l]["resposta"]["pasta"], $b["leituras"][$l]["resposta"]["pasta"]);
}
// o atraso de cada leitura (t: há quantos segundos a rodada começou, anotado pelo cenário): sai da comparação e vira folga
// do tempo naquela leitura. Fotografias sem o t: sem atraso (só os 120 s)
$atrasos = [];
foreach (array_keys($a["leituras"] ?? []) as $l) {
    if (isset($a["leituras"][$l]["t"], $b["leituras"][$l]["t"])) {
        $atrasos[$l] = abs((float)$a["leituras"][$l]["t"] - (float)$b["leituras"][$l]["t"]);
    }
    unset($a["leituras"][$l]["t"]);
    if (isset($b["leituras"][$l])) {
        unset($b["leituras"][$l]["t"]);
    }
}
$leituras_a = $a["leituras"] ?? [];
$leituras_b = $b["leituras"] ?? [];
unset($a["leituras"], $b["leituras"]);
comparar($a, $b, "", "");
foreach (array_unique(array_merge(array_keys($leituras_a), array_keys($leituras_b))) as $l) {
    if (!array_key_exists($l, $leituras_a) || !array_key_exists($l, $leituras_b)) {
        $graves[] = [".leituras." . $l, "só existe num", array_key_exists($l, $leituras_a) ? "presente" : "ausente", array_key_exists($l, $leituras_b) ? "presente" : "ausente"];
        continue;
    }
    $FOLGA_ATRASO = $atrasos[$l] ?? 0;
    comparar($leituras_a[$l], $leituras_b[$l], ".leituras." . $l, $l);
}
$FOLGA_ATRASO = 0;
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
