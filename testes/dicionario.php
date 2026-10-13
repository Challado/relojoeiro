<?php
// O dicionário dos campos tem uma fonte só: o "campos" da ajuda (o que o recurso=ajuda devolve, no api/ajuda.php). O bloco
// "DICIONÁRIO DOS CAMPOS" do cabeçalho do api.php é gerado dele por este script; ninguém escreve nele à mão.
//   php testes/dicionario.php            confere: termina com 0 se o cabeçalho é o que a ajuda gera, 1 se não (e diz onde)
//   php testes/dicionario.php --gravar   reescreve o bloco do cabeçalho a partir da ajuda
// Para documentar um campo novo: escreva a entrada no "campos" da ajuda (na seção do recurso, com o caminho como chave) e rode
// com --gravar. O formato do cabeçalho: a seção ("api.php (sem recurso)", "recurso=<nome>" ou "_filtros ..."), e cada campo
// com o caminho numa coluna de 31 caracteres (o comprido fica sozinho na linha) e o texto quebrado em linhas de até 140.
if (PHP_SAPI !== "cli") {
    exit;
}
$raiz = dirname(__DIR__);
$API = $raiz . "/api.php";
$LARGURA = 140;
$COL_CHAVE = 31;

// onde está o "campos" da ajuda: o arquivo (o api.php ou, se ele for dividido, um dos arquivos da pasta api/) com a linha
// '        "campos" => [' seguida do "como_ler"
function achar_fonte($raiz)
{
    $candidatos = array_merge([$raiz . "/api.php"], glob($raiz . "/api/*.php") ?: []);
    foreach ($candidatos as $arq) {
        $L = explode("\n", (string)file_get_contents($arq));
        foreach ($L as $i => $l) {
            if (preg_match('/^(\s*)"campos" => \[$/', $l, $m) && isset($L[$i + 1]) && strpos($L[$i + 1], '"como_ler" =>') !== false) {
                $ind = $m[1];
                for ($j = $i + 1; $j < count($L); $j++) {
                    if ($L[$j] === $ind . "],") {
                        return [$arq, implode("\n", array_slice($L, $i + 1, $j - $i - 1))];
                    }
                }
            }
        }
    }
    fwrite(STDERR, "Não achei o \"campos\" da ajuda (a linha '\"campos\" => [' seguida do \"como_ler\").\n");
    exit(2);
}

// o texto quebrado em linhas de até $larg caracteres, nos espaços (uma palavra maior que a linha fica inteira)
function quebrar($texto, $larg)
{
    $texto = trim(preg_replace('/\s+/u', " ", $texto));
    $linhas = [];
    $atual = "";
    foreach (explode(" ", $texto) as $p) {
        if ($atual === "") {
            $atual = $p;
        } elseif (mb_strlen($atual . " " . $p, "UTF-8") <= $larg) {
            $atual .= " " . $p;
        } else {
            $linhas[] = $atual;
            $atual = $p;
        }
    }
    if ($atual !== "") {
        $linhas[] = $atual;
    }
    return $linhas ?: [""];
}

function gerar($campos, $larg, $col)
{
    $saida = [];
    foreach (quebrar($campos["como_ler"], $larg - 5) as $l) {
        $saida[] = " *   " . $l;
    }
    $saida[] = " *";
    $recuo = " *" . str_repeat(" ", 5 + $col);
    $larg_texto = $larg - mb_strlen($recuo, "UTF-8");
    foreach ($campos as $secao => $itens) {
        if (!is_array($itens)) {
            continue;
        }
        $saida[] = " *   " . ($secao === "api.php (sem recurso)" || $secao[0] === "_" ? $secao : "recurso=" . $secao);
        foreach ($itens as $chave => $texto) {
            $partes = quebrar((string)$texto, $larg_texto);
            if (mb_strlen($chave, "UTF-8") < $col) {
                $saida[] = " *     " . $chave . str_repeat(" ", $col - mb_strlen($chave, "UTF-8")) . array_shift($partes);
            } else {
                $saida[] = " *     " . $chave;
            }
            foreach ($partes as $p) {
                $saida[] = $recuo . $p;
            }
        }
    }
    return $saida;
}

[$arq_fonte, $corpo] = achar_fonte($raiz);
$campos = eval("return [" . $corpo . "];");
if (!is_array($campos) || !isset($campos["como_ler"])) {
    fwrite(STDERR, "O \"campos\" da ajuda em " . basename($arq_fonte) . " não virou um array com o \"como_ler\".\n");
    exit(2);
}
$novo = gerar($campos, $LARGURA, $COL_CHAVE);

// o bloco no cabeçalho: da linha depois do título "DICIONÁRIO DOS CAMPOS" (com o aviso de que é gerado) e do traço até a
// linha antes do traço dos ERROS
$L = explode("\n", (string)file_get_contents($API));
$titulo = null;
foreach ($L as $i => $l) {
    if (strpos($l, " * DICIONÁRIO DOS CAMPOS") === 0) {
        $titulo = $i;
        break;
    }
}
$fim = null;
for ($i = (int)$titulo + 2; $titulo !== null && $i < count($L); $i++) {
    if (strpos($L[$i], " * ERROS") === 0) {
        $fim = $i - 1;
        break;
    }
}
if ($titulo !== null && strpos($L[$titulo + 1], " *   Gerado do") === 0) {
    $titulo++;
}
if ($titulo === null || $fim === null || strpos($L[$titulo + 1], " * ---") !== 0 || strpos($L[$fim], " * ---") !== 0) {
    fwrite(STDERR, "Não achei o bloco do dicionário no cabeçalho do api.php (\" * DICIONÁRIO DOS CAMPOS\" ... \" * ERROS\").\n");
    exit(2);
}
$atual = array_slice($L, $titulo + 2, $fim - $titulo - 2);

if (in_array("--gravar", $argv, true)) {
    array_splice($L, $titulo + 2, $fim - $titulo - 2, $novo);
    file_put_contents($API, implode("\n", $L));
    echo "Cabeçalho do api.php regravado: " . count($novo) . " linhas do dicionário (" . (count($novo) - count($atual)) . " a mais).\n";
    exit(0);
}
if ($atual === $novo) {
    echo "O dicionário do cabeçalho do api.php é o da ajuda (" . count($novo) . " linhas).\n";
    exit(0);
}
// onde começa a diferença
$n = max(count($atual), count($novo));
for ($i = 0; $i < $n; $i++) {
    if (($atual[$i] ?? null) !== ($novo[$i] ?? null)) {
        echo "O dicionário do cabeçalho do api.php não é o da ajuda (linha " . ($titulo + 3 + $i) . " do api.php):\n";
        echo "  no cabeçalho: " . ($atual[$i] ?? "(acabou)") . "\n";
        echo "  da ajuda:     " . ($novo[$i] ?? "(acabou)") . "\n";
        break;
    }
}
echo "Escreva no \"campos\" da ajuda (" . basename($arq_fonte) . ") e rode: php testes/dicionario.php --gravar\n";
exit(1);
