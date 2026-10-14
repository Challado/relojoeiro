<?php
// Todo campo que a API devolve está explicado no dicionário da ajuda (recurso=ajuda, "campos")? Lê cada recurso de um sistema
// no ar (o do cenário: o testes/paridade.sh roda isto depois do roteiro), junta os caminhos de todos os campos das respostas
// (relogios[].nome, config.painel_modo...) e procura cada um na seção do recurso no dicionário.
//   php testes/campos.php http://127.0.0.1:8101/api.php
// Termina com 0 se não falta nenhum, 1 se falta (e lista os que faltam). Mostra também, à parte, os explicados que não
// apareceram (normal: um campo que só vem num caso que o banco do cenário não tem).
// O token: RELOGIOS_TOKEN (padrão: o do testes/config.teste.php).
if (PHP_SAPI !== "cli" || count($argv) < 2) {
    fwrite(STDERR, "Uso: php testes/campos.php <endereço do api.php>\n");
    exit(2);
}
$API = $argv[1];
$TOKEN = getenv("RELOGIOS_TOKEN") ?: "token-de-teste-123";
$FUSO = getenv("RELOGIOS_FUSO") ?: "America/Sao_Paulo";
date_default_timezone_set($FUSO);

function ler($consulta)
{
    global $API, $TOKEN;
    $ctx = stream_context_create(["http" => ["header" => "X-Api-Token: " . $TOKEN . "\r\n", "ignore_errors" => true, "timeout" => 120]]);
    // LINGUA=en: as respostas nesse idioma (com o servidor em modo estrito, TESTE_ESTRITO=1, a tradução que falta aparece aqui)
    $lingua = (string)getenv("LINGUA");
    $consulta .= $lingua !== "" ? ($consulta !== "" ? "&" : "") . "lingua=" . rawurlencode($lingua) : "";
    $corpo = @file_get_contents($API . ($consulta !== "" ? "?" . $consulta : ""), false, $ctx);
    if (preg_match('/Falta a tradução[^"]*/u', (string)$corpo, $m) === 1) {
        fwrite(STDERR, $consulta . ": " . stripslashes($m[0]) . "\n");
    }
    // objetos como objetos: um {} vazio não pode virar uma lista vazia
    return json_decode((string)$corpo, false);
}

// os caminhos de todos os campos de uma resposta; um objeto cujas chaves são números, datas ou ids (g:3), ou textos, vira <*>
function caminhos($v, $pre, &$saida)
{
    if (is_object($v)) {
        $v = get_object_vars($v);
        $variaveis = count($v) > 0;
        foreach (array_keys($v) as $k) {
            if (preg_match('/^(-?\d+|\d{4}-\d{2}-\d{2}.*|[a-z]:\d+|)$/', (string)$k) !== 1) {
                $variaveis = false;
                break;
            }
        }
        // ou um dicionário: chaves que não são nomes de campo (o texto em português, nos textos dos idiomas)
        foreach (array_keys($v) as $k) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$k) !== 1) {
                $variaveis = true;
                break;
            }
        }
        foreach ($v as $k => $x) {
            $p = ($pre !== "" ? $pre . "." : "") . ($variaveis ? "<*>" : $k);
            $saida[$p] = true;
            caminhos($x, $p, $saida);
        }
    } elseif (is_array($v)) {
        $p = $pre . "[]";
        $saida[$p] = true;
        foreach ($v as $x) {
            caminhos($x, $p, $saida);
        }
    }
}

// uma chave do dicionário vira uma expressão regular: <qualquer coisa> casa com um pedaço do caminho
function regex($chave)
{
    $k = preg_replace('/<[^>]*>/', "<*>", $chave);
    return "/^" . str_replace(preg_quote("<*>", "/"), "[^.\\[\\]]+", preg_quote($k, "/")) . "$/u";
}

$ajuda = json_decode(json_encode(ler("recurso=ajuda")), true);
if (!is_array($ajuda) || !isset($ajuda["campos"])) {
    fwrite(STDERR, "Não consegui ler recurso=ajuda em " . $API . "\n");
    exit(2);
}
$dic = $ajuda["campos"];
$tudo = ler("foto=nao");
$ids = array_map(function ($r) { return (int)$r->id; }, $tudo->relogios ?? []);
$hoje = date("Y-m-d");
$daqui20 = date("Y-m-d", strtotime("+20 days"));
$para_cada = function ($modelo) use ($ids) {
    return array_map(function ($i) use ($modelo) { return sprintf($modelo, $i); }, $ids);
};
$consultas = [
    "api.php (sem recurso)" => ["foto=nao"],
    "autonomia" => ["recurso=autonomia"],
    "hoje" => ["recurso=hoje"],
    "ficha" => array_merge($para_cada("recurso=ficha&relogio=%d"), ["recurso=ficha"]),
    "config" => ["recurso=config"],
    "cron" => ["recurso=cron"],
    "arvore" => ["recurso=arvore"],
    "cadastros" => ["recurso=cadastros"],
    "calcular" => ["recurso=calcular&expressao=energia*2"],
    "avisos" => ["recurso=avisos"],
    "criterios" => ["recurso=criterios"],
    "historico" => $para_cada("recurso=historico&relogio=%d"),
    "previsao" => ["recurso=previsao"],
    "plano" => ["recurso=plano", "recurso=plano&dias=todos&pagina=2&por_pagina=3"],
    "proximos" => ["recurso=proximos", "recurso=proximos&pagina=2&por_pagina=3"],
    "desejos" => ["recurso=desejos"],
    "estatisticas" => ["recurso=estatisticas", "recurso=estatisticas&de=tudo"],
    "hora_certa" => ["recurso=hora_certa"],
    "textos" => ["recurso=textos", "recurso=textos&lingua=en"],
    "acessorios" => ["recurso=acessorios", "recurso=acessorios&relogio=0"],
    "passaporte" => $para_cada("recurso=passaporte&relogio=%d"),
    "galeria" => ["recurso=galeria", "recurso=galeria&posse=todos"],
    "rodizio_manual" => ["recurso=rodizio_manual&de=" . $hoje . "&ate=" . $daqui20 . "&pagina=1", "recurso=rodizio_manual&de=" . $hoje . "&ate=" . $daqui20 . "&pagina=3"],
    "eventos" => ["recurso=eventos"],
    "agenda" => ["recurso=agenda"],
    "usuarios" => ["recurso=usuarios"],
    "migracoes" => ["recurso=migracoes"],
    "instalacao" => ["recurso=instalacao"],
    "importacao" => ["recurso=importacao"],
    "entrar" => ["recurso=entrar"],
    "documentos" => array_merge(["recurso=documentos"], $para_cada("recurso=documentos&relogio=%d")),
    "manual" => ["recurso=manual", "recurso=ajuda&parte=manual"],
    "reconstrucao" => ["recurso=reconstrucao", "recurso=ajuda&parte=reconstrucao"],
    "backup" => ["recurso=backup"],
    "codigo" => ["recurso=codigo&conteudo=1", "recurso=ajuda&parte=codigo", "recurso=codigo&arquivo=index.php", "recurso=codigo&arquivo=docs/telas/hoje.png"],
];
$faltam = 0;
$sobram = [];
foreach ($consultas as $secao => $qs) {
    $vistos = [];
    foreach ($qs as $q) {
        caminhos(ler($q), "", $vistos);
    }
    if (!isset($dic[$secao]) || !is_array($dic[$secao])) {
        echo "## " . $secao . ": a seção não existe no dicionário\n";
        $faltam += count($vistos);
        continue;
    }
    $chaves = array_keys($dic[$secao]);
    // um campo explicado cobre os pais dele (relogios[].compra.valor cobre relogios[].compra e relogios[]), e "x[]" cobre "x"
    $doc = [];
    foreach ($chaves as $k) {
        $doc[$k] = true;
        $partes = explode(".", $k);
        for ($i = 1; $i < count($partes); $i++) {
            $doc[implode(".", array_slice($partes, 0, $i))] = true;
        }
        if (substr($k, -2) === "[]") {
            $doc[substr($k, 0, -2)] = true;
        }
    }
    $regs = array_map("regex", array_keys($doc));
    $falta = [];
    foreach (array_keys($vistos) as $p) {
        $ok = false;
        foreach ($regs as $r) {
            if (preg_match($r, $p) === 1 || preg_match($r, $p . "[]") === 1) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $falta[] = $p;
        }
    }
    foreach ($chaves as $k) {
        $r = regex($k);
        $achou = false;
        foreach (array_keys($vistos) as $p) {
            if (preg_match($r, $p) === 1) {
                $achou = true;
                break;
            }
        }
        if (!$achou) {
            $sobram[] = $secao . ": " . $k;
        }
    }
    sort($falta);
    if ($falta) {
        echo "## " . $secao . ": na resposta, sem explicação (" . count($falta) . ")\n    " . implode("\n    ", $falta) . "\n";
    }
    $faltam += count($falta);
}
if (in_array("--sobras", $argv, true) && $sobram) {
    echo "Explicados que não apareceram neste banco (" . count($sobram) . "):\n    " . implode("\n    ", $sobram) . "\n";
}
echo $faltam === 0 ? "Todos os campos das respostas estão explicados no dicionário.\n" : "Faltam explicar: " . $faltam . "\n";
exit($faltam === 0 ? 0 : 1);
