<?php
// Relógios 2: o núcleo. O código só tem mecanismos genéricos; o conteúdo (a árvore, os campos, os lançamentos, as
// fórmulas, os critérios, os modos) é tudo cadastro. Aqui: o banco, o token obrigatório, o login, a árvore, os campos com
// os seus valores, os tipos de lançamento e o motor de cálculo (as fórmulas).
require_once __DIR__ . "/config.php";

// O fuso: o FUSO do config.php; sem ele (ou vazio), o do PHP (date.timezone no php.ini; sem nada lá, UTC). Um nome que o
// PHP não conhece para o sistema, como o token abaixo: rodar com a hora errada é pior que não rodar.
/** @var mixed $fuso_cfg */
$fuso_cfg = defined("FUSO") ? trim((string)constant("FUSO")) : "";
$ERRO_FUSO = "";
if ($fuso_cfg !== "") {
    if (in_array($fuso_cfg, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        date_default_timezone_set($fuso_cfg);
    } else {
        $ERRO_FUSO = "Sistema parado: FUSO no config.php (\"" . $fuso_cfg . "\") não é um fuso que o PHP conheça. Use um nome como "
            . "America/Sao_Paulo, ou deixe vazio para usar o do PHP (date.timezone no php.ini, agora " . date_default_timezone_get() . ").";
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// O token da API é obrigatório: API_TOKEN no config.php, texto com pelo menos 10 caracteres sem contar os espaços das
// pontas. Sem isso o sistema inteiro para (a página e a API respondem o erro; a linha de comando mostra e sai com 1).
// ---------------------------------------------------------------------------------------------------------------------
$ERRO_TOKEN = "";
// o valor pode ser qualquer coisa que alguém escreveu no config.php; o @var diz isso às ferramentas de análise
/** @var mixed $token_cfg */
$token_cfg = defined("API_TOKEN") ? constant("API_TOKEN") : null;
if (!defined("API_TOKEN")) {
    $ERRO_TOKEN = "o config.php não define API_TOKEN";
} elseif ($token_cfg === null) {
    $ERRO_TOKEN = "API_TOKEN está nulo no config.php";
} elseif (!is_string($token_cfg)) {
    $ERRO_TOKEN = "API_TOKEN no config.php tem de ser um texto, entre aspas";
} elseif (trim($token_cfg) === "") {
    $ERRO_TOKEN = "API_TOKEN está em branco no config.php";
} elseif (strlen(trim($token_cfg)) < 10) {
    $ERRO_TOKEN = "API_TOKEN no config.php tem " . strlen(trim($token_cfg)) . " caracteres; o mínimo é 10";
}
if ($ERRO_TOKEN !== "") {
    $ERRO_TOKEN = "Sistema parado: " . $ERRO_TOKEN . ". Defina no config.php, por exemplo: define('API_TOKEN', 'uma-chave-longa-e-secreta'); "
        . "com pelo menos 10 caracteres (sem contar espaços nas pontas).";
}
// $ERRO_TOKEN é o motivo de o sistema estar parado (o cron e a API conferem ele): o token ou o fuso
if ($ERRO_TOKEN === "" && $ERRO_FUSO !== "") {
    $ERRO_TOKEN = $ERRO_FUSO;
}
if ($ERRO_TOKEN !== "") {
    if (PHP_SAPI === "cli" && basename((string)($_SERVER["SCRIPT_FILENAME"] ?? "")) === "cron.php") {
        // o cron fica mudo: registra o motivo nas execuções e não faz mais nada (cron.php confere $ERRO_TOKEN)
    } elseif (PHP_SAPI === "cli") {
        fwrite(STDERR, $ERRO_TOKEN . "\n");
        exit(1);
    } else {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["erro" => $ERRO_TOKEN], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Banco: MySQL/MariaDB, PostgreSQL ou SQLite (DB_TIPO no config.php). db(), sql(), linhas(), linha(), valor(), ultimo_id()
// e a tradução do SQL estão no banco.php
// ---------------------------------------------------------------------------------------------------------------------
require_once __DIR__ . "/banco.php";

// O sorteio: aleatório de verdade (random_int). Com a variável de ambiente RELOGIOS_SEMENTE (os testes de paridade entre
// os bancos), repetível: a mesma semente dá os mesmos sorteios em cada pedido e em cada rodada do cron
function sorteio($min, $max)
{
    static $semente = null;
    if ($semente === null) {
        $s = getenv("RELOGIOS_SEMENTE");
        $semente = $s === false || $s === "" ? false : (int)$s;
        if ($semente !== false) {
            mt_srand($semente);
        }
    }
    return $semente === false ? random_int($min, $max) : mt_rand($min, $max);
}

// Dois nomes iguais, sem diferenciar maiúsculas de minúsculas, inclusive acentuadas (É = é), sem depender do mbstring
function mesmo_nome($a, $b)
{
    return preg_match("/^" . preg_quote(trim((string)$a), "/") . "\$/iu", trim((string)$b)) === 1;
}

// ---------------------------------------------------------------------------------------------------------------------
// A árvore de classificação: cada relógio fica num ponto dela; cada ponto pode ter campos, lançamentos e fórmulas
// ---------------------------------------------------------------------------------------------------------------------

// Os pontos da árvore, por id (lidos uma vez por requisição; $recarregar depois de mudar a árvore)
function nos_todos($recarregar = false)
{
    static $n = null;
    if ($n === null || $recarregar) {
        $n = [];
        foreach (linhas("SELECT * FROM no ORDER BY ordem, nome") as $x) {
            $n[(int)$x["id"]] = $x;
        }
    }
    return $n;
}

// Os pontos da raiz até $id: [id, id, ...] (vazio: na raiz, ou ponto que não existe)
function no_cadeia($id)
{
    $n = nos_todos();
    $cadeia = [];
    $atual = (int)$id;
    // o limite de voltas só protege de uma árvore corrompida (um ciclo); as operações não deixam criar um
    while ($atual > 0 && isset($n[$atual]) && count($cadeia) < 1000) {
        array_unshift($cadeia, $atual);
        $atual = (int)$n[$atual]["pai_id"];
    }
    return $cadeia;
}

// Os pontos em ordem de árvore (cada um seguido dos de dentro dele): [[id, profundidade], ...], profundidade 0 = na raiz
function nos_em_ordem()
{
    $n = nos_todos();
    $filhos = [];
    foreach ($n as $id => $x) {
        $filhos[(int)$x["pai_id"]][] = $id;
    }
    $lista = [];
    $pilha = [];
    foreach (array_reverse($filhos[0] ?? []) as $id) {
        $pilha[] = [$id, 0];
    }
    // o limite de voltas só protege de uma árvore corrompida (um ciclo)
    while (count($pilha) > 0 && count($lista) <= count($n)) {
        $atual = array_pop($pilha);
        $lista[] = $atual;
        foreach (array_reverse($filhos[$atual[0]] ?? []) as $id) {
            $pilha[] = [$id, $atual[1] + 1];
        }
    }
    return $lista;
}

// O caminho por extenso: "Smartwatch › Xiaomi › HyperOS"; na raiz: "todos os relógios"
function no_caminho($id)
{
    $n = nos_todos();
    $cadeia = no_cadeia($id);
    return count($cadeia) > 0 ? implode(" › ", array_map(function ($x) use ($n) { return $n[$x]["nome"]; }, $cadeia)) : "todos os relógios";
}

// ---------------------------------------------------------------------------------------------------------------------
// Campos: definidos por você, em algum ponto da árvore (ou para todos); valem para os relógios daquele ponto e de
// tudo abaixo dele (os de cada nível somam). O valor de cada relógio fica em campo_valor.
// ---------------------------------------------------------------------------------------------------------------------
$TIPOS_CAMPO = [
    "inteiro" => "Número inteiro",
    "decimal" => "Número decimal",
    "sim_nao" => "Sim ou não (nas contas: 1 ou 0)",
    "data" => "Data (nas contas: por DIAS_DESDE, DIAS_ATE e SOMA_MESES)",
    "lista" => "Lista de opções (compara e dá faixa por categoria)",
    "texto" => "Texto (compara e dá faixa por categoria)",
];

// Todos os campos, por identificador (uma vez por requisição)
function campos_todos($recarregar = false)
{
    static $c = null;
    if ($c === null || $recarregar) {
        $c = [];
        foreach (linhas("SELECT * FROM campo ORDER BY ordem, nome") as $x) {
            $c[$x["identificador"]] = $x;
        }
    }
    return $c;
}

// Os campos que valem para um relógio: os de todos e os de cada ponto da cadeia dele
function campos_do_relogio($r)
{
    $cadeia = no_cadeia($r["no_id"] ?? 0);
    $res = [];
    foreach (campos_todos() as $ident => $c) {
        if ($c["no_id"] === null || in_array((int)$c["no_id"], $cadeia, true)) {
            $res[$ident] = $c;
        }
    }
    return $res;
}

// Os valores gravados de um relógio: [identificador => texto]
function valores_do_relogio($id)
{
    $res = [];
    foreach (linhas("SELECT c.identificador, v.valor FROM campo_valor v JOIN campo c ON c.id = v.campo_id WHERE v.relogio_id = ?", [$id]) as $x) {
        $res[$x["identificador"]] = $x["valor"];
    }
    return $res;
}

// Confere e normaliza um valor digitado para o tipo do campo. Devolve [valor normalizado (texto) ou null se vazio, erro]
function valor_para_campo($c, $v)
{
    $v = trim((string)$v);
    $res = [null, ""];
    if ($v !== "") {
        if ($c["tipo"] === "inteiro") {
            $res = preg_match("/^-?[0-9]+\$/", $v) === 1 ? [(string)(int)$v, ""] : [null, "tem de ser um número inteiro"];
        } elseif ($c["tipo"] === "decimal") {
            // aceita "1.079,99", "1079,99" ou "1079.99"
            $n = strpos($v, ',') !== false ? str_replace(',', '.', str_replace('.', "", $v)) : $v;
            $res = is_numeric($n) ? [(string)(0 + (float)$n), ""] : [null, "tem de ser um número"];
        } elseif ($c["tipo"] === "sim_nao") {
            $s = strtolower($v);
            $res = in_array($s, ["1", "sim", "s", "true"], true) ? ["1", ""] : (in_array($s, ["0", "não", "nao", "n", "false"], true) ? ["0", ""] : [null, "tem de ser sim ou não"]);
        } elseif ($c["tipo"] === "data") {
            $d = DateTimeImmutable::createFromFormat("!Y-m-d", $v);
            $res = $d && $d->format("Y-m-d") === $v ? [$v, ""] : [null, "tem de ser uma data AAAA-MM-DD"];
        } elseif ($c["tipo"] === "lista") {
            $opcoes = array_values(array_filter(array_map("trim", explode("\n", (string)$c["opcoes"]))));
            $res = in_array($v, $opcoes, true) ? [$v, ""] : [null, "tem de ser uma das opções: " . implode(", ", $opcoes)];
        } else {
            $res = strlen($v) <= 1000 ? [$v, ""] : [null, "passa de 1000 caracteres"];
        }
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// Tipos de lançamento: o que se registra num relógio. Formato: instantaneo (corda), valor (leitura de carga em %) ou
// sessao (no pulso, no winder, no sol: com início e fim). Os lançamentos ficam em "lancamento".
// ---------------------------------------------------------------------------------------------------------------------
$FORMATOS_LANCAMENTO = ["instantaneo" => "Instantâneo", "valor" => "Instantâneo com valor", "sessao" => "Sessão, com início e fim"];

function lancamento_tipos($recarregar = false)
{
    static $t = null;
    if ($t === null || $recarregar) {
        $t = [];
        foreach (linhas("SELECT * FROM lancamento_tipo ORDER BY ordem, nome") as $x) {
            $t[$x["identificador"]] = $x;
        }
    }
    return $t;
}

// Os tipos de lançamento que valem para um relógio: os de todos e os de cada ponto da cadeia dele
function lancamento_tipos_do_relogio($r)
{
    $cadeia = no_cadeia($r["no_id"] ?? 0);
    $res = [];
    foreach (lancamento_tipos() as $ident => $t) {
        if (($t["no_id"] === null || in_array((int)$t["no_id"], $cadeia, true)) && condicao_vale($t["condicao"] ?? null, $r, time())) {
            $res[$ident] = $t;
        }
    }
    return $res;
}

// A condição "vale quando" de um tipo de lançamento ou de um aviso, para um relógio num instante: uma fórmula que dá 1 (vale)
// ou 0; vazia, vale sempre; com erro ou sem valor (um campo vazio sem padrão), não vale
function condicao_vale($expressao, $r, $momento)
{
    $res = true;
    if ($expressao !== null && trim((string)$expressao) !== "") {
        $ctx = ["r" => $r, "momento" => $momento, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
        try {
            $v = formula_calcular(formula_ler((string)$expressao), $ctx);
        } catch (ErroFormula $e) {
            $v = null;
        }
        $res = is_numeric($v) && (float)$v != 0;
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// Migrações: as mudanças de estrutura do banco depois da instalação, em arquivos migracao_vN.sql, aplicadas pela API
// (recurso=migracoes, acao=aplicar). Cada uma: [arquivo, o que traz, a marca de que já foi aplicada: uma tabela ou
// tabela.coluna]. Com alguma pendente, a API só responde as migrações (a conferência fica no api.php). O arquivo é um
// só para os três bancos: o DDL no dialeto do MySQL (o banco.php traduz) e os dados em SQL comum aos três.
// ---------------------------------------------------------------------------------------------------------------------
$MIGRACOES = [
    "v2" => ["migracao_v2.sql", "lançamentos pela API, com sessões exclusivas (o relógio num lugar só), avisos cadastráveis e a autonomia restante", "aviso"],
    "v3" => ["migracao_v3.sql", "modos de rodízio cadastráveis, o plano, os critérios por lugar e o cron", "modo"],
    "v4" => ["migracao_v4.sql", "a escala inteligente, os eventos personalizados, o Google Agenda, a previsão do smartwatch e a linha do tempo", "evento_personalizado"],
    "v5" => ["migracao_v5.sql", "as telas e as mensagens do sistema antigo: canais com mensagem padrão e personalizada, avisos de carga baixa e de leitura, "
        . "limite do solar, e o registro completo do cron", "cron_execucao.teve_atividade"],
    "v6" => ["migracao_v6.sql", "o gasto medido pelas leituras (a média dos últimos dias) e as autonomias em segundos", "medicao"],
    "v7" => ["migracao_v7.sql", "o ciclo no rodízio (opção de cada modo, desligada por padrão): um relógio só volta depois que todos do bloco passaram", "modo.ciclo"],
    "v8" => ["migracao_v8.sql", "a condição \"vale quando\" nos tipos de lançamento e nos avisos; o automático sem corda recebe \"Pôr no winder\" em vez de \"Dar corda\"", "aviso.condicao"],
    "v9" => ["migracao_v9.sql", "o limite de carga, geral e por relógio: carregar, pôr no sol e dar corda só quando a carga chega a ele", "campo.identificador=carga_minima"],
    "v10" => ["migracao_v10.sql", "os avisos de carga pelo estado de agora: no winder ou no pulso (o automático carrega no pulso), sem aviso de winder ou de corda; no sol, sem aviso de sol; a data do \"Carregar\" pelo gasto de agora (no pulso, o de uso; guardado, o de guardado)", "config.chave=migracao_v10"],
    "v11" => ["migracao_v11.sql", "o motivo de cada escolha do plano: por que aquele relógio saiu naquele dia (a garantia de rodízio, a nota, o sorteio, a escolha à mão)", "plano.motivo"],
    "v12" => ["migracao_v12.sql", "os dias sem uso de um relógio nunca usado contam desde a compra (antes valiam 9999 para todos: empatavam na garantia de rodízio)", "config.chave=migracao_v12"],
    "v13" => ["migracao_v13.sql", "o manual de cada relógio: um arquivo (PDF ou imagem) guardado no banco, que se envia e se abre pela ficha", "manual"],
    "v14" => ["migracao_v14.sql", "os documentos de cada relógio: qualquer arquivo (manual, nota fiscal em PDF e em XML, fotos, vídeos, diversos), numa categoria, com a página Documentos (galeria, vídeos em sequência, visualizador de PDF, resumo da nota); os manuais da v13 passam para lá", "documento"],
    "v15" => ["migracao_v15.sql", "a cópia de segurança de cada documento dentro do banco (em pedaços de 4 MB): o arquivo que sumir da pasta volta sozinho do banco; o cron copia os que já existem e limpa a pasta (relógio excluído, arquivo sem documento)", "documento_parte"],
];

// As migrações que faltam aplicar neste banco: as da lista cuja marca ainda não existe. A marca: uma tabela, uma coluna
// (tabela.coluna) ou uma linha (tabela.coluna=valor, para a migração que só acrescenta cadastro)
function migracoes_pendentes()
{
    global $MIGRACOES;
    return array_filter($MIGRACOES, function ($m) {
        $linha = explode("=", $m[2], 2);
        $marca = explode(".", $linha[0]);
        if (count($linha) === 2) {
            return (int)valor("SELECT COUNT(*) FROM " . $marca[0] . " WHERE " . $marca[1] . " = ?", [$linha[1]]) === 0;
        }
        return count($marca) === 2 ? !banco_tem_coluna($marca[0], $marca[1]) : !banco_tem_tabela($marca[0]);
    });
}


// ---------------------------------------------------------------------------------------------------------------------
// O motor de cálculo. Uma fórmula é lida por um interpretador próprio (nunca vira código PHP) e vira uma árvore de
// operações, que é calculada para um relógio num instante (agora, ou uma data da simulação).
//   Números: 12  12,5  12.5      Texto: "carga"      Operações: + - * / ^ ( )      Comparações: = <> < <= > >=
//   Argumentos separados por ponto e vírgula: LIMITA(x; 0; 100)
//   Variáveis: os identificadores dos campos e das fórmulas.
//   Vazio se propaga: se falta um dado, o resultado é vazio ("não se aplica"). Divisão por zero: vazio.
//   Datas viram números de dias (desde 1970-01-01), para entrar em contas.
// ---------------------------------------------------------------------------------------------------------------------

// As funções: nome => [mínimo de argumentos, máximo (null: sem limite), descrição, histórico (o 1º argumento é um tipo
// de lançamento, entre aspas)]
$FUNCOES = [
    "MIN" => [1, null, "o menor dos valores (ignora os vazios)", false],
    "MAX" => [1, null, "o maior dos valores (ignora os vazios)", false],
    "SE" => [3, 3, "SE(condição; se verdadeira; se falsa)", false],
    "E" => [1, null, "1 se todas as condições são verdadeiras", false],
    "OU" => [1, null, "1 se alguma condição é verdadeira", false],
    "NAO" => [1, 1, "inverte a condição", false],
    "LIMITA" => [3, 3, "LIMITA(x; mínimo; máximo)", false],
    "ARREDONDA" => [1, 2, "ARREDONDA(x; casas)", false],
    "ABS" => [1, 1, "o valor sem sinal", false],
    "PADRAO" => [2, 2, "PADRAO(x; valor se x estiver vazio)", false],
    "VAZIO" => [1, 1, "1 se o valor está vazio", false],
    "NADA" => [0, 0, "o vazio, de propósito (ex.: SE(condição; NADA(); conta))", false],
    "HOJE" => [0, 0, "a data de hoje (em dias)", false],
    "AGORA" => [0, 0, "o instante de agora (em dias, com a fração do dia)", false],
    "HORAS_USO" => [0, 0, "as horas de um dia de uso, pelo horário de uso da Configuração (das 7h às 22h: 15)", false],
    "MEDIDO" => [1, 1, "MEDIDO(\"uso\") ou MEDIDO(\"repouso\"): o gasto medido pelas leituras, em % por dia (de uso ou fora do pulso): os dois saem juntos das medições dos últimos dias da Configuração (cada intervalo entre duas leituras: a queda = dias de uso × gasto em uso + dias fora × gasto fora), pesadas pelas horas; quando elas não separam os dois, a média das medições de cada um; sem nenhuma nesses dias, a última; sem nenhuma, vazio", false],
    "CONFIG" => [1, 1, "CONFIG(\"chave\"): um número da Configuração (ex.: CONFIG(\"sol_limiar\"), o limite do solar); vazio se não for número", false],
    "DIAS_DESDE" => [1, 1, "dias desde uma data", false],
    "DIAS_ATE" => [1, 1, "dias até uma data (negativo: já passou)", false],
    "SOMA_MESES" => [2, 2, "SOMA_MESES(data; meses): a data somada de meses, pelo calendário", false],
    "ULTIMO_VALOR" => [1, 1, "ULTIMO_VALOR(\"tipo\"): o valor do último lançamento daquele tipo", true],
    "ULTIMA_DATA" => [1, 1, "ULTIMA_DATA(\"tipo\"): quando foi o último (numa sessão, o fim; aberta: agora)", true],
    "DIAS_DESDE_ULTIMO" => [1, 1, "DIAS_DESDE_ULTIMO(\"tipo\"): dias desde o último (sessão aberta: 0)", true],
    "HORAS" => [2, 2, "HORAS(\"sessão\"; dias): horas naquela sessão nos últimos N dias", true],
    "HORAS_APOS" => [2, 2, "HORAS_APOS(\"sessão\"; \"tipo\"): horas naquela sessão desde o último lançamento do outro tipo", true],
    "CONTAR" => [2, 2, "CONTAR(\"tipo\"; dias): quantos lançamentos (ou dias com sessão) nos últimos N dias", true],
    "EM_SESSAO" => [1, 1, "EM_SESSAO(\"sessão\"): 1 se a sessão está aberta agora", true],
    "ACUMULA" => [3, null, "ACUMULA(início; máximo; perda por hora; \"tipo\"; efeito; ...): um saldo que percorre o histórico: "
        . "numa sessão da lista, ganha o efeito por hora; num lançamento instantâneo da lista, passa a valer o efeito; no resto, perde; "
        . "sempre entre 0 e o máximo", true],
    "MEDIA_COLECAO" => [1, 1, "MEDIA_COLECAO(\"variável\"): a média daquela variável nos relógios disponíveis", false],
];

class ErroFormula extends Exception
{
}

// Um instante (segundos) em dias, contados no horário local: a meia-noite local de qualquer data é um número inteiro,
// e a fração é a hora do dia. Assim uma data e o agora entram na mesma conta sem o fuso aparecer.
function dias_de($ts)
{
    return ((int)$ts + (int)date("Z", (int)$ts)) / 86400;
}

// Lê a fórmula e devolve a árvore de operações. Nós: ["n", número], ["t", texto], ["v", variável],
// ["f", FUNÇÃO, [argumentos]], ["op", operador, a, b], ["neg", a]. Erro de escrita: ErroFormula, com a posição.
function formula_ler($f)
{
    // os pedaços: [tipo, valor, posição]; tipos: num, txt, id, op, (, ), ;
    $p = [];
    $i = 0;
    $n = strlen($f);
    while ($i < $n) {
        $c = $f[$i];
        if (ctype_space($c)) {
            $i++;
        } elseif (ctype_digit($c)) {
            preg_match("/^[0-9]+([.,][0-9]+)?/", substr($f, $i), $m);
            $p[] = ["num", (float)str_replace(',', '.', $m[0]), $i];
            $i += strlen($m[0]);
        } elseif ($c === '"') {
            $fim = strpos($f, '"', $i + 1);
            if ($fim === false) {
                throw new ErroFormula("texto aberto na posição " . ($i + 1) . ": falta fechar as aspas");
            }
            $p[] = ["txt", substr($f, $i + 1, $fim - $i - 1), $i];
            $i = $fim + 1;
        } elseif (ctype_alpha($c) || $c === '_') {
            preg_match("/^[A-Za-z_][A-Za-z0-9_]*/", substr($f, $i), $m);
            $p[] = ["id", $m[0], $i];
            $i += strlen($m[0]);
        } elseif (in_array(substr($f, $i, 2), ["<=", ">=", "<>"], true)) {
            $p[] = ["op", substr($f, $i, 2), $i];
            $i += 2;
        } elseif (strpos("+-*/^=<>", $c) !== false) {
            $p[] = ["op", $c, $i];
            $i++;
        } elseif (strpos("();", $c) !== false) {
            $p[] = [$c, $c, $i];
            $i++;
        } else {
            throw new ErroFormula("caractere inesperado \"" . $c . "\" na posição " . ($i + 1));
        }
    }
    $st = ["p" => $p, "i" => 0];
    if (count($p) === 0) {
        throw new ErroFormula("a fórmula está vazia");
    }
    $arvore = formula_ler_nivel($st, 0);
    if ($st["i"] < count($p)) {
        throw new ErroFormula("\"" . $p[$st["i"]][1] . "\" sobrando na posição " . ($p[$st["i"]][2] + 1));
    }
    return $arvore;
}

// Lê um nível da fórmula, da precedência mais fraca para a mais forte: 0 comparação, 1 soma e subtração, 2 produto e
// divisão, 3 potência (à direita: 2 ^ 3 ^ 2 = 2 ^ 9), 4 sinal e o resto (número, texto, parênteses, variável, função).
// $st: os pedaços e a posição da leitura.
function formula_ler_nivel(&$st, $nivel)
{
    global $FUNCOES;
    $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
    $onde = $t[2] >= 0 ? "na posição " . ($t[2] + 1) : "no fim";
    if ($nivel === 0) {
        $res = formula_ler_nivel($st, 1);
        $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
        if ($t[0] === "op" && in_array($t[1], ["=", "<>", "<", "<=", ">", ">="], true)) {
            $st["i"]++;
            $res = ["op", $t[1], $res, formula_ler_nivel($st, 1)];
        }
    } elseif ($nivel === 1 || $nivel === 2) {
        $ops = $nivel === 1 ? ["+", "-"] : ["*", "/"];
        $res = formula_ler_nivel($st, $nivel + 1);
        $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
        while ($t[0] === "op" && in_array($t[1], $ops, true)) {
            $st["i"]++;
            $res = ["op", $t[1], $res, formula_ler_nivel($st, $nivel + 1)];
            $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
        }
    } elseif ($nivel === 3) {
        $res = formula_ler_nivel($st, 4);
        $t = $st["p"][$st["i"]] ?? ["fim", "", -1];
        if ($t[0] === "op" && $t[1] === "^") {
            $st["i"]++;
            $res = ["op", "^", $res, formula_ler_nivel($st, 3)];
        }
    } else {
        $st["i"]++;
        if ($t[0] === "op" && $t[1] === "-") {
            $res = ["neg", formula_ler_nivel($st, 4)];
        } elseif ($t[0] === "num" || $t[0] === "txt") {
            $res = [$t[0] === "num" ? "n" : "t", $t[1]];
        } elseif ($t[0] === "(") {
            $res = formula_ler_nivel($st, 0);
            if (($st["p"][$st["i"]][0] ?? "") !== ")") {
                throw new ErroFormula("falta fechar o parêntese " . (isset($st["p"][$st["i"]]) ? "na posição " . ($st["p"][$st["i"]][2] + 1) : "no fim"));
            }
            $st["i"]++;
        } elseif ($t[0] === "id" && ($st["p"][$st["i"]][0] ?? "") === "(") {
            $nome = strtoupper($t[1]);
            if (!isset($FUNCOES[$nome])) {
                throw new ErroFormula("função desconhecida " . $t[1] . " " . $onde);
            }
            $st["i"]++;
            $args = [];
            if (($st["p"][$st["i"]][0] ?? "") !== ")") {
                $args[] = formula_ler_nivel($st, 0);
                while (($st["p"][$st["i"]][0] ?? "") === ";") {
                    $st["i"]++;
                    $args[] = formula_ler_nivel($st, 0);
                }
            }
            if (($st["p"][$st["i"]][0] ?? "") !== ")") {
                throw new ErroFormula("falta fechar os argumentos de " . $nome . " " . (isset($st["p"][$st["i"]]) ? "na posição " . ($st["p"][$st["i"]][2] + 1) : "no fim")
                    . " (os argumentos se separam com ponto e vírgula)");
            }
            $st["i"]++;
            $d = $FUNCOES[$nome];
            if (count($args) < $d[0] || ($d[1] !== null && count($args) > $d[1])) {
                throw new ErroFormula($nome . " recebe " . ($d[1] === null ? "pelo menos " . $d[0] : ($d[0] === $d[1] ? $d[0] : "de " . $d[0] . " a " . $d[1]))
                    . " argumento(s), e recebeu " . count($args) . " " . $onde);
            }
            $res = ["f", $nome, $args];
        } elseif ($t[0] === "id") {
            $res = ["v", $t[1]];
        } else {
            throw new ErroFormula($t[0] === "fim" ? "a fórmula acabou antes do esperado" : "\"" . $t[1] . "\" fora do lugar " . $onde);
        }
    }
    return $res;
}

// As variáveis e os tipos de lançamento que uma fórmula usa: ["variaveis" => [...], "lancamentos" => [...]]
function formula_referencias($arvore)
{
    global $FUNCOES;
    $res = ["variaveis" => [], "lancamentos" => []];
    $pilha = [$arvore];
    while (count($pilha) > 0) {
        $no = array_pop($pilha);
        if ($no[0] === "v") {
            $res["variaveis"][$no[1]] = true;
        } elseif ($no[0] === "f") {
            foreach ($no[2] as $k => $a) {
                // nas funções do histórico, o 1º argumento (e, no HORAS_APOS e no ACUMULA, os outros textos) é um tipo de lançamento;
                // no MEDIA_COLECAO, o argumento é uma variável
                if ($a[0] === "t" && $no[1] === "MEDIA_COLECAO") {
                    $res["variaveis"][$a[1]] = true;
                } elseif ($a[0] === "t" && $FUNCOES[$no[1]][3]) {
                    $res["lancamentos"][$a[1]] = true;
                }
                $pilha[] = $a;
            }
        } elseif ($no[0] === "op") {
            $pilha[] = $no[2];
            $pilha[] = $no[3];
        } elseif ($no[0] === "neg") {
            $pilha[] = $no[1];
        }
    }
    return ["variaveis" => array_keys($res["variaveis"]), "lancamentos" => array_keys($res["lancamentos"])];
}

// Todas as fórmulas: [identificador => [linhas, uma por ponto da árvore em que foi definida]]
function formulas_todas($recarregar = false)
{
    static $f = null;
    if ($f === null || $recarregar) {
        $f = [];
        foreach (linhas("SELECT * FROM formula ORDER BY identificador, id") as $x) {
            $f[$x["identificador"]][] = $x;
        }
    }
    return $f;
}

// De várias versões de uma mesma coisa (fórmula ou aviso), cada uma num ponto da árvore (no_id; vazio: todos), a que vale
// para um relógio: a do ponto mais perto dele (a do ponto dele; senão a do de cima; ...; senão a de todos). null se
// nenhuma vale para ele.
function mais_perto($versoes, $r)
{
    $cadeia = no_cadeia($r["no_id"] ?? 0);
    $res = null;
    $melhor = -1;
    foreach ($versoes as $v) {
        $nivel = $v["no_id"] === null ? 0 : array_search((int)$v["no_id"], $cadeia, true);
        if ($nivel !== false) {
            $nivel = $v["no_id"] === null ? 0 : $nivel + 1;
            if ($nivel > $melhor) {
                $melhor = $nivel;
                $res = $v;
            }
        }
    }
    return $res;
}

// O gasto medido pelas leituras de carga, num instante: ["uso" => % por dia de uso, "repouso" => % por dia fora do pulso
// (guardado, desligado), "n" => quantas medições entraram, "conjunta" => se os dois saíram juntos]. $medicoes: as medições
// usadas do relógio, em ordem de fim (medida, taxa, peso_horas, fim, horas_pulso, horas_guardado, de_valor, ate_valor).
// Cada medição é um intervalo entre duas leituras: a queda = dias de uso × gasto em uso + dias fora do pulso × gasto fora.
// Com as medições da janela da Configuração (nenhuma nela: todas as de antes), os dois gastos saem juntos, pelos mínimos
// quadrados (cada intervalo pesa as suas horas: o curto, que o arredondamento da leitura atrapalha mais, pesa pouco),
// nunca negativos. Quando as medições não separam os dois (só intervalos fora do pulso, só no pulso, ou todos na mesma
// proporção), vale a média das medições de cada gasto, pesada pelas horas.
function gasto_medido($medicoes, $momento)
{
    $janela = max(1, (int)cfg("medicao_janela_dias")) * 86400;
    $res = gasto_medido_desde($medicoes, $momento, $momento - $janela);
    return $res["n"] > 0 ? $res : gasto_medido_desde($medicoes, $momento, null);
}

// A conta do gasto_medido com as medições que terminam depois de $desde (null: todas) e até $momento
function gasto_medido_desde($medicoes, $momento, $desde)
{
    $h_dia = (strtotime("2000-01-01 " . cfg("uso_fim")) - strtotime("2000-01-01 " . cfg("uso_inicio"))) / 3600;
    $h_dia = $h_dia > 0 ? $h_dia : 24;
    $n = 0;
    $saa = $sbb = $sab = $saq = $sbq = 0.0;
    $media = ["uso" => [0.0, 0.0], "repouso" => [0.0, 0.0]];
    foreach ($medicoes as $m) {
        $fim = strtotime($m["fim"]);
        if ($fim > $momento) {
            break;
        }
        if ($desde !== null && $fim <= $desde) {
            continue;
        }
        $n++;
        $hp = (float)$m["horas_pulso"];
        $hg = (float)$m["horas_guardado"];
        $w = $hp + $hg;
        $a = $hp / $h_dia;
        $b = $hg / 24;
        $q = (float)$m["de_valor"] - (float)$m["ate_valor"];
        $saa += $w * $a * $a;
        $sbb += $w * $b * $b;
        $sab += $w * $a * $b;
        $saq += $w * $a * $q;
        $sbq += $w * $b * $q;
        $media[$m["medida"]][0] += (float)$m["taxa"] * (float)$m["peso_horas"];
        $media[$m["medida"]][1] += (float)$m["peso_horas"];
    }
    $res = ["uso" => null, "repouso" => null, "n" => $n, "conjunta" => false];
    if ($n === 0) {
        return $res;
    }
    $det = $saa * $sbb - $sab * $sab;
    // separa bem quando os intervalos têm proporções diferentes de pulso e de fora (1 - a correlação² entre elas)
    if ($saa > 0 && $sbb > 0 && $det / ($saa * $sbb) >= 0.02) {
        $u = ($saq * $sbb - $sbq * $sab) / $det;
        $r = ($sbq * $saa - $saq * $sab) / $det;
        if ($r < 0) {
            $r = 0.0;
            $u = $saq / $saa;
        } elseif ($u < 0) {
            $u = 0.0;
            $r = $sbq / $sbb;
        }
        return ["uso" => round(max(0, $u), 3), "repouso" => round(max(0, $r), 3), "n" => $n, "conjunta" => true];
    }
    foreach (["uso", "repouso"] as $medida) {
        if ($media[$medida][1] > 0) {
            $res[$medida] = round($media[$medida][0] / $media[$medida][1], 3);
        }
    }
    return $res;
}

// Calcula uma árvore de operações para um relógio. $ctx: ["r" => relógio, "momento" => segundos, "rastro" => lista das
// partes da conta (o nome e o valor de cada variável e de cada função do histórico), "pilha" => fórmulas em cálculo
// (para não entrar em círculo)]. Devolve número, texto ou null (vazio).
function formula_calcular($no, &$ctx)
{
    global $FUNCOES;
    // os lançamentos lidos de cada relógio e as médias da coleção de cada instante, guardados para a requisição inteira;
    // quando algo é gravado no meio da requisição (a escala refeita depois de um lançamento), FORMULAS_VERSAO muda e o
    // guardado sai. As médias também dependem da simulação da escala (SIMULACAO_VERSAO, na chave).
    static $cache_l = [];
    static $cache_m = [];
    static $cache_med = [];
    static $versao = 0;
    if ($versao !== ($GLOBALS["FORMULAS_VERSAO"] ?? 0)) {
        $cache_l = [];
        $cache_m = [];
        $cache_med = [];
        $versao = $GLOBALS["FORMULAS_VERSAO"] ?? 0;
    }
    $res = null;
    if ($no[0] === "n" || $no[0] === "t") {
        $res = $no[1];
    } elseif ($no[0] === "neg") {
        $a = formula_calcular($no[1], $ctx);
        $res = is_numeric($a) ? -$a : null;
    } elseif ($no[0] === "v") {
        $res = variavel_valor($no[1], $ctx);
        $ctx["rastro"][$no[1]] = $res;
    } elseif ($no[0] === "op") {
        $a = formula_calcular($no[2], $ctx);
        $b = formula_calcular($no[3], $ctx);
        if ($a !== null && $b !== null) {
            if (in_array($no[1], ["=", "<>"], true)) {
                $igual = is_numeric($a) && is_numeric($b) ? abs((float)$a - (float)$b) < 1e-9 : (string)$a === (string)$b;
                $res = ($no[1] === "=") === $igual ? 1 : 0;
            } elseif (is_numeric($a) && is_numeric($b)) {
                $a = (float)$a;
                $b = (float)$b;
                if ($no[1] === "+") {
                    $res = $a + $b;
                } elseif ($no[1] === "-") {
                    $res = $a - $b;
                } elseif ($no[1] === "*") {
                    $res = $a * $b;
                } elseif ($no[1] === "/") {
                    $res = abs($b) < 1e-12 ? null : $a / $b;
                } elseif ($no[1] === "^") {
                    $res = ($a < 0 && floor($b) != $b) ? null : pow($a, $b);
                } elseif ($no[1] === "<") {
                    $res = $a < $b ? 1 : 0;
                } elseif ($no[1] === "<=") {
                    $res = $a <= $b ? 1 : 0;
                } elseif ($no[1] === ">") {
                    $res = $a > $b ? 1 : 0;
                } elseif ($no[1] === ">=") {
                    $res = $a >= $b ? 1 : 0;
                }
            }
        }
    } elseif ($no[0] === "f") {
        $nome = $no[1];
        $args = $no[2];
        // SE calcula só o lado escolhido; as outras calculam todos os argumentos
        if ($nome === "SE") {
            $c = formula_calcular($args[0], $ctx);
            $res = $c === null ? null : formula_calcular(((float)$c != 0) ? $args[1] : $args[2], $ctx);
        } elseif ($FUNCOES[$nome][3]) {
            // as funções do histórico: os lançamentos do relógio até o instante, por tipo (lidos uma vez por relógio e
            // requisição). Sessão aberta vale até o instante; se o tipo tem "fecha sozinho às", no máximo até essa hora do
            // dia em que começou (a sessão esquecida aberta não vira dias de uso).
            $ate = (int)$ctx["momento"];
            $id = (int)$ctx["r"]["id"];
            if (!isset($cache_l[$id])) {
                $cache_l[$id] = [];
                foreach (linhas("SELECT l.*, t.identificador AS tipo, t.formato, t.fecha_as FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? ORDER BY l.inicio, l.id", [$id]) as $l) {
                    $ini = strtotime($l["inicio"]);
                    $limite = null;
                    if ($l["fim"] === null && $l["fecha_as"] !== null) {
                        $limite = max($ini, (int)strtotime(substr($l["inicio"], 0, 10) . " " . $l["fecha_as"]));
                    }
                    $cache_l[$id][$l["tipo"]][] = ["ini" => $ini, "fim" => $l["fim"] === null ? $limite : strtotime($l["fim"]),
                        "valor" => $l["valor"] === null ? null : (float)$l["valor"], "formato" => $l["formato"]];
                }
            }
            // os gravados, e depois deles os simulados pela escala e pela previsão (SIMULACAO: [relógio => [tipo => lançamentos]],
            // no mesmo formato e em ordem, todos depois de agora). O simulado "só uso" (o do sorteio: não se sabe se o relógio vai
            // ser carregado) conta para as funções de uso, mas não entra nas contas de saldo da carga (HORAS_APOS e ACUMULA)
            $saldo = $nome === "HORAS_APOS" || $nome === "ACUMULA";
            $ls = [];
            foreach ([$cache_l[$id], $GLOBALS["SIMULACAO"][$id] ?? []] as $fonte) {
                foreach ($fonte as $tipo => $do_cache) {
                    foreach ($do_cache as $l) {
                        if ($l["ini"] <= $ate && !($saldo && !empty($l["so_uso"]))) {
                            $l["fim_ate"] = $l["formato"] === "sessao" ? min($l["fim"] === null ? $ate : $l["fim"], $ate) : $l["ini"];
                            $ls[$tipo][] = $l;
                        }
                    }
                }
            }
            $tipo = $args[0][0] === "t" ? $args[0][1] : "";
            $do_tipo = $ls[$tipo] ?? [];
            $ultimo = count($do_tipo) > 0 ? $do_tipo[count($do_tipo) - 1] : null;
            if ($nome === "ULTIMO_VALOR") {
                $res = $ultimo ? $ultimo["valor"] : null;
            } elseif ($nome === "ULTIMA_DATA") {
                $res = $ultimo ? dias_de($ultimo["fim_ate"]) : null;
            } elseif ($nome === "DIAS_DESDE_ULTIMO") {
                $res = $ultimo ? ($ate - $ultimo["fim_ate"]) / 86400 : null;
            } elseif ($nome === "EM_SESSAO") {
                $res = $ultimo && $ultimo["formato"] === "sessao" && ($ultimo["fim"] === null || $ultimo["fim"] > $ate) ? 1 : 0;
            } elseif ($nome === "HORAS" || $nome === "CONTAR") {
                $dias = formula_calcular($args[1], $ctx);
                if (is_numeric($dias)) {
                    $desde = $ate - (float)$dias * 86400;
                    $horas = 0.0;
                    $conta = 0;
                    $dias_com = [];
                    foreach ($do_tipo as $l) {
                        if ($l["fim_ate"] >= $desde) {
                            $conta++;
                            if ($l["formato"] === "sessao") {
                                $horas += max(0, $l["fim_ate"] - max($l["ini"], $desde)) / 3600;
                                for ($t = max($l["ini"], (int)$desde); $t <= $l["fim_ate"]; $t += 86400) {
                                    $dias_com[date("Y-m-d", $t)] = true;
                                }
                                $dias_com[date("Y-m-d", $l["fim_ate"])] = true;
                            }
                        }
                    }
                    $formato = $ultimo ? $ultimo["formato"] : (lancamento_tipos()[$tipo]["formato"] ?? "instantaneo");
                    $res = $nome === "HORAS" ? $horas : ($formato === "sessao" ? count($dias_com) : $conta);
                }
            } elseif ($nome === "HORAS_APOS") {
                $outro = $args[1][0] === "t" ? $args[1][1] : "";
                $ref = $ls[$outro] ?? [];
                if (count($ref) > 0) {
                    $desde = $ref[count($ref) - 1]["ini"];
                    $horas = 0.0;
                    foreach ($do_tipo as $l) {
                        $horas += max(0, $l["fim_ate"] - max($l["ini"], $desde)) / 3600;
                    }
                    $res = $horas;
                }
            } elseif ($nome === "ACUMULA") {
                // um saldo que percorre o histórico dos tipos da lista, do primeiro lançamento deles até o instante. Numa sessão da
                // lista, ganha o efeito dela por hora (duas ao mesmo tempo: vale a de maior ganho); num lançamento instantâneo da
                // lista, o saldo passa a valer o efeito; fora de sessão, perde a perda por hora. Sempre entre 0 e o máximo. Sem
                // nenhum lançamento dos tipos da lista: vazio.
                $inicio = formula_calcular($args[0], $ctx);
                $maximo = formula_calcular($args[1], $ctx);
                $perda = formula_calcular($args[2], $ctx);
                if (is_numeric($inicio) && is_numeric($maximo) && is_numeric($perda) && count($args) >= 5 && count($args) % 2 === 1) {
                    $sessoes = [];
                    $marcas = [];
                    for ($k = 3; $k + 1 < count($args); $k += 2) {
                        $tipo = $args[$k][0] === "t" ? $args[$k][1] : "";
                        $efeito = formula_calcular($args[$k + 1], $ctx);
                        if (is_numeric($efeito)) {
                            foreach ($ls[$tipo] ?? [] as $l) {
                                if ($l["formato"] === "sessao") {
                                    $sessoes[] = [$l["ini"], $l["fim_ate"], (float)$efeito];
                                } else {
                                    $marcas[] = [$l["ini"], (float)$efeito];
                                }
                            }
                        }
                    }
                    if (count($sessoes) + count($marcas) > 0) {
                        // os instantes em que algo muda: início e fim de cada sessão, cada marca, e o instante do cálculo
                        $pontos = [$ate];
                        foreach ($sessoes as $s) {
                            $pontos[] = $s[0];
                            $pontos[] = $s[1];
                        }
                        foreach ($marcas as $m) {
                            $pontos[] = $m[0];
                        }
                        $pontos = array_values(array_unique(array_filter($pontos, function ($t) use ($ate) { return $t <= $ate; })));
                        sort($pontos);
                        // percorrido uma vez só: as marcas por instante (duas no mesmo instante: vale a última da lista) e as
                        // sessões por início, entrando nas ativas quando começam e saindo quando acabam
                        $marca_em = [];
                        foreach ($marcas as $m) {
                            $marca_em[$m[0]] = $m[1];
                        }
                        usort($sessoes, function ($x, $y) { return $x[0] <=> $y[0]; });
                        $proxima = 0;
                        $ativas = [];
                        $saldo = max(0, min((float)$maximo, (float)$inicio));
                        for ($i = 0; $i < count($pontos); $i++) {
                            if (isset($marca_em[$pontos[$i]])) {
                                $saldo = max(0, min((float)$maximo, $marca_em[$pontos[$i]]));
                            }
                            if ($i + 1 < count($pontos)) {
                                $a = $pontos[$i];
                                $b = $pontos[$i + 1];
                                $meio = ($a + $b) / 2;
                                while ($proxima < count($sessoes) && $sessoes[$proxima][0] <= $meio) {
                                    $ativas[] = $sessoes[$proxima];
                                    $proxima++;
                                }
                                $ganho = null;
                                $ficam = [];
                                foreach ($ativas as $s) {
                                    if ($meio < $s[1]) {
                                        $ficam[] = $s;
                                        $ganho = $ganho === null ? $s[2] : max($ganho, $s[2]);
                                    }
                                }
                                $ativas = $ficam;
                                $horas = ($b - $a) / 3600;
                                $saldo = $ganho === null ? $saldo - (float)$perda * $horas : $saldo + $ganho * $horas;
                                $saldo = max(0, min((float)$maximo, $saldo));
                            }
                        }
                        $res = $saldo;
                    }
                }
            }
            $ctx["rastro"][$nome . "(" . implode("; ", array_map(function ($a) { return $a[0] === "t" ? "\"" . $a[1] . "\"" : "…"; }, $args)) . ")"] = $res;
        } elseif ($nome === "MEDIA_COLECAO") {
            // a média da variável nos relógios disponíveis (os vazios não entram), calculada uma vez por instante; dentro
            // dela, outra MEDIA_COLECAO vale vazio (não há média de média)
            $ident = $args[0][0] === "t" ? $args[0][1] : "";
            $chave = $ident . "@" . (int)$ctx["momento"] . "@" . ($GLOBALS["SIMULACAO_VERSAO"] ?? 0);
            if (!isset($cache_m[$chave])) {
                $cache_m[$chave] = null;
                if (empty($ctx["na_media"])) {
                    $soma = 0.0;
                    $n = 0;
                    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1") as $r) {
                        $c = ["r" => $r, "momento" => $ctx["momento"], "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"]), "na_media" => true];
                        $v = variavel_valor($ident, $c);
                        if (is_numeric($v)) {
                            $soma += (float)$v;
                            $n++;
                        }
                    }
                    $cache_m[$chave] = $n > 0 ? $soma / $n : null;
                }
            }
            $res = $cache_m[$chave];
            $ctx["rastro"]["MEDIA_COLECAO(\"" . ($args[0][1] ?? "") . "\")"] = $res;
        } else {
            $v = array_map(function ($a) use (&$ctx) {
                return formula_calcular($a, $ctx);
            }, $args);
            $numeros = array_values(array_filter($v, function ($x) { return is_numeric($x); }));
            $agora_d = dias_de($ctx["momento"]);
            if ($nome === "MIN") {
                $res = count($numeros) > 0 ? min(array_map("floatval", $numeros)) : null;
            } elseif ($nome === "MAX") {
                $res = count($numeros) > 0 ? max(array_map("floatval", $numeros)) : null;
            } elseif ($nome === "E") {
                $res = in_array(null, $v, true) ? null : (count(array_filter($v, function ($x) { return (float)$x == 0; })) === 0 ? 1 : 0);
            } elseif ($nome === "OU") {
                $res = in_array(null, $v, true) ? null : (count(array_filter($v, function ($x) { return (float)$x != 0; })) > 0 ? 1 : 0);
            } elseif ($nome === "NAO") {
                $res = $v[0] === null ? null : ((float)$v[0] == 0 ? 1 : 0);
            } elseif ($nome === "LIMITA") {
                $res = count($numeros) === 3 ? max((float)$v[1], min((float)$v[2], (float)$v[0])) : null;
            } elseif ($nome === "ARREDONDA") {
                $res = is_numeric($v[0]) ? round((float)$v[0], isset($v[1]) && is_numeric($v[1]) ? (int)$v[1] : 0) : null;
            } elseif ($nome === "ABS") {
                $res = is_numeric($v[0]) ? abs((float)$v[0]) : null;
            } elseif ($nome === "PADRAO") {
                $res = $v[0] ?? $v[1];
            } elseif ($nome === "VAZIO") {
                $res = $v[0] === null ? 1 : 0;
            } elseif ($nome === "NADA") {
                $res = null;
            } elseif ($nome === "HOJE") {
                $res = floor($agora_d);
            } elseif ($nome === "AGORA") {
                $res = $agora_d;
            } elseif ($nome === "MEDIDO" && !empty($ctx["sem_medido"])) {
                // a conta só com o cadastro (a tabela dos gastos da previsão mostra o que valeria sem as medições)
                $res = null;
            } elseif ($nome === "MEDIDO") {
                // as medições usadas do relógio, lidas uma vez por requisição; os gastos são os da janela que termina no
                // instante da conta (gasto_medido), guardados pelo trecho de medições que entrou (a escala pede o mesmo muitas vezes)
                $id = (int)$ctx["r"]["id"];
                if (!isset($cache_med[$id])) {
                    $cache_med[$id] = ["linhas" => linhas("SELECT medida, taxa, peso_horas, fim, horas_pulso, horas_guardado, de_valor, ate_valor
                        FROM medicao WHERE relogio_id = ? AND usada = 1 ORDER BY fim, id", [$id]), "contas" => []];
                }
                $janela = max(1, (int)cfg("medicao_janela_dias")) * 86400;
                $ini = 0;
                $fim = 0;
                foreach ($cache_med[$id]["linhas"] as $k => $m) {
                    $t = strtotime($m["fim"]);
                    if ($t <= $ctx["momento"] - $janela) {
                        $ini = $k + 1;
                    }
                    if ($t <= $ctx["momento"]) {
                        $fim = $k + 1;
                    }
                }
                $chave = $ini . "-" . $fim;
                if (!isset($cache_med[$id]["contas"][$chave])) {
                    $cache_med[$id]["contas"][$chave] = gasto_medido($cache_med[$id]["linhas"], $ctx["momento"]);
                }
                $res = in_array($v[0], ["uso", "repouso"], true) ? $cache_med[$id]["contas"][$chave][$v[0]] : null;
            } elseif ($nome === "CONFIG") {
                $res = is_string($v[0]) && is_numeric(cfg($v[0])) ? (float)cfg($v[0]) : null;
            } elseif ($nome === "HORAS_USO") {
                $res = (strtotime("2000-01-01 " . cfg("uso_fim")) - strtotime("2000-01-01 " . cfg("uso_inicio"))) / 3600;
                $res = $res > 0 ? $res : null;
            } elseif ($nome === "DIAS_DESDE") {
                $res = is_numeric($v[0]) ? $agora_d - (float)$v[0] : null;
            } elseif ($nome === "DIAS_ATE") {
                $res = is_numeric($v[0]) ? (float)$v[0] - $agora_d : null;
            } elseif ($nome === "SOMA_MESES") {
                if (is_numeric($v[0]) && is_numeric($v[1])) {
                    // a data (o dia inteiro) somada de meses pelo calendário: o dia fica limitado ao último dia do mês de
                    // destino (31/01 + 1 mês = 28/02 ou 29/02, não 03/03); a hora do dia, se houver, é mantida
                    $dia = floor((float)$v[0]);
                    $partes = explode("-", gmdate("Y-n-j", (int)($dia * 86400)));
                    $total = (int)$partes[0] * 12 + (int)$partes[1] - 1 + (int)$v[1];
                    $ano = intdiv($total, 12);
                    $mes = $total % 12 + 1;
                    $res = gmmktime(0, 0, 0, $mes, min((int)$partes[2], (int)gmdate("t", gmmktime(0, 0, 0, $mes, 1, $ano))), $ano) / 86400 + ((float)$v[0] - $dia);
                }
            }
        }
    }
    return $res;
}

// O valor de uma variável para o relógio: o campo (convertido para o tipo), ou a fórmula que vale para ele
function variavel_valor($ident, &$ctx)
{
    static $arvores = [];
    $res = null;
    $campos = campos_do_relogio($ctx["r"]);
    if (isset($campos[$ident])) {
        $bruto = $ctx["valores"][$ident] ?? null;
        if ($bruto === null || $bruto === "") {
            $bruto = $campos[$ident]["padrao"];
        }
        if ($bruto !== null && $bruto !== "") {
            $tipo = $campos[$ident]["tipo"];
            if ($tipo === "inteiro" || $tipo === "decimal" || $tipo === "sim_nao") {
                $res = (float)$bruto;
            } elseif ($tipo === "data") {
                $res = dias_de(strtotime($bruto . " 00:00:00"));
            } else {
                $res = (string)$bruto;
            }
        }
    } elseif (isset(formulas_todas()[$ident])) {
        $f = mais_perto(formulas_todas()[$ident], $ctx["r"]);
        if ($f !== null) {
            if (isset($ctx["pilha"][$ident])) {
                // círculo: uma fórmula que depende dela mesma (a validação não deixa gravar; isto só protege)
                $res = null;
            } else {
                $ctx["pilha"][$ident] = true;
                // a árvore de cada expressão é lida uma vez por requisição (a escala calcula a mesma fórmula milhares de vezes)
                if (!array_key_exists($f["expressao"], $arvores)) {
                    try {
                        $arvores[$f["expressao"]] = formula_ler($f["expressao"]);
                    } catch (ErroFormula $e) {
                        $arvores[$f["expressao"]] = null;
                    }
                }
                $res = $arvores[$f["expressao"]] === null ? null : formula_calcular($arvores[$f["expressao"]], $ctx);
                unset($ctx["pilha"][$ident]);
            }
        }
    }
    return $res;
}

// Confere uma fórmula antes de gravar: a escrita, as variáveis (campos ou fórmulas que existem), os tipos de lançamento,
// e se ela não depende dela mesma (A usa B, que usa A). $ident: o identificador da fórmula que se grava. Devolve a
// lista de erros (vazia: pode gravar).
function formula_validar($ident, $expressao)
{
    $erros = [];
    try {
        $refs = formula_referencias(formula_ler($expressao));
        $conhecidas = array_merge(array_keys(campos_todos()), array_keys(formulas_todas()), [$ident]);
        foreach ($refs["variaveis"] as $v) {
            if (!in_array($v, $conhecidas, true)) {
                $erros[] = "variável desconhecida: " . $v . " (não é campo nem fórmula)";
            }
        }
        foreach ($refs["lancamentos"] as $t) {
            if (!isset(lancamento_tipos()[$t])) {
                $erros[] = "tipo de lançamento desconhecido: \"" . $t . "\"";
            }
        }
        // o círculo: a partir das variáveis desta fórmula, seguindo as fórmulas (todas as versões de cada uma), chega-se a ela?
        if (count($erros) === 0) {
            // cada item a visitar leva o caminho até ele, para a mensagem mostrar o círculo inteiro
            $visitar = [];
            foreach ($refs["variaveis"] as $v) {
                $visitar[] = [$v, [$ident, $v]];
            }
            $vistas = [];
            while (count($visitar) > 0) {
                $item = array_pop($visitar);
                $v = $item[0];
                if ($v === $ident) {
                    $erros[] = "a fórmula depende dela mesma: " . implode(" → ", $item[1]);
                    $visitar = [];
                } elseif (!isset($vistas[$v]) && isset(formulas_todas()[$v])) {
                    $vistas[$v] = true;
                    foreach (formulas_todas()[$v] as $f) {
                        try {
                            foreach (formula_referencias(formula_ler($f["expressao"]))["variaveis"] as $prox) {
                                $visitar[] = [$prox, array_merge($item[1], [$prox])];
                            }
                        } catch (ErroFormula $e) {
                            $erros[] = "a fórmula " . $v . " usada aqui está com erro de escrita";
                        }
                    }
                }
            }
        }
    } catch (ErroFormula $e) {
        $erros[] = "erro de escrita: " . $e->getMessage();
    }
    return $erros;
}

// ---------------------------------------------------------------------------------------------------------------------
// Avisos: cada um com a fórmula da data prevista (em dias, como as datas das fórmulas), a antecedência em dias, o texto
// e o tipo de lançamento que o resolve. Como as fórmulas, a mesma chave pode ter uma versão por ponto da árvore, e vale
// a do mais perto do relógio.
// ---------------------------------------------------------------------------------------------------------------------

// Um tempo em dias por extenso, pelas duas maiores partes: "2a 3m", "1m 10d", "5d 3h", "2h 40min", "30min" (o sinal não
// importa); menos de um minuto: "" (quem chama decide o que mostrar)
function duracao_texto($dias)
{
    $min = (int)round(abs((float)$dias) * 1440);
    $partes = [];
    foreach ([["a", 525960], ["m", 43830], ["d", 1440], ["h", 60], ["min", 1]] as $u) {
        if (count($partes) < 2 && ($min >= $u[1] || count($partes) > 0)) {
            $n = intdiv($min, $u[1]);
            $min -= $n * $u[1];
            if ($n > 0) {
                $partes[] = $n . $u[0];
            }
        }
    }
    return implode(" ", $partes);
}

// Todos os avisos: [identificador => [versões]]
function avisos_todos($recarregar = false)
{
    static $a = null;
    if ($a === null || $recarregar) {
        $a = [];
        foreach (linhas("SELECT * FROM aviso ORDER BY identificador, id") as $x) {
            $a[$x["identificador"]][] = $x;
        }
    }
    return $a;
}

// Os avisos de um relógio num instante: cada aviso que vale para ele e tem data prevista (sem data: não se aplica agora).
// estado: atrasado (a data passou), em_breve (dentro da antecedência) ou ok. texto: o do cadastro, com {relogio},
// {data} (dd/mm/aaaa hh:mm), {quando} ("em 2d 3h", "há 5h", "agora") e {limite} (o limite de carga do relógio) trocados.
function avisos_do_relogio($r, $momento)
{
    $res = [];
    $agora_d = dias_de($momento);
    foreach (avisos_todos() as $ident => $versoes) {
        $a = mais_perto($versoes, $r);
        if ($a !== null && (int)$a["ativo"] === 1 && condicao_vale($a["condicao"] ?? null, $r, $momento)) {
            $ctx = ["r" => $r, "momento" => $momento, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
            try {
                $data = formula_calcular(formula_ler($a["expressao"]), $ctx);
            } catch (ErroFormula $e) {
                $data = null;
            }
            if (is_numeric($data)) {
                // {limite}: o limite de carga que vale para o relógio (o dele, senão o geral), trocado já no modelo
                $modelo = $a["texto"];
                if (strpos($modelo, "{limite}") !== false) {
                    $lim = isset(formulas_todas()["limite_carga"]) ? variavel_valor("limite_carga", $ctx) : null;
                    $modelo = is_numeric($lim) ? str_replace("{limite}", str_replace(".", ",", (string)round((float)$lim, 1)), $modelo)
                        : str_replace(["{limite}%", "{limite}"], "o limite", $modelo);
                }
                $falta = (float)$data - $agora_d;
                // o quanto falta (ou passou), por extenso (as duas maiores partes)
                $quando = duracao_texto($falta) === "" ? "agora" : ($falta >= 0 ? "em " : "há ") . duracao_texto($falta);
                $ts = (int)round((float)$data * 86400);
                $data_txt = gmdate("d/m/Y H:i", $ts);
                // escala, simula_*, agenda e modelo: para a escala inteligente e a agenda (o modelo é o texto sem as trocas, só com o {limite})
                $res[] = ["identificador" => $ident, "nome" => $a["nome"], "data" => gmdate("Y-m-d H:i:s", $ts), "falta_dias" => round($falta, 4),
                    "estado" => $falta < 0 ? "atrasado" : ($falta <= (float)$a["antecedencia_dias"] ? "em_breve" : "ok"),
                    "texto" => str_replace(["{relogio}", "{data}", "{quando}"], [$r["nome"], $data_txt, $quando], $modelo),
                    "resolve" => $a["resolve"], "versao" => no_caminho($a["no_id"]), "escala" => $a["escala"],
                    "simula_valor" => $a["simula_valor"] === null ? null : (float)$a["simula_valor"], "simula_horas" => $a["simula_horas"] === null ? null : (float)$a["simula_horas"],
                    "agenda" => $a["agenda"], "modelo" => $modelo];
            }
        }
    }
    usort($res, function ($x, $y) {
        return $x["falta_dias"] <=> $y["falta_dias"];
    });
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// Configuração (chave e valor), o login do site, e o envio pela API de mensagem
// ---------------------------------------------------------------------------------------------------------------------
function cfg($chave, $novo = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (linhas("SELECT chave, valor FROM config") as $c) {
            $cache[$c["chave"]] = $c["valor"];
        }
    }
    if ($novo !== null) {
        $cache[$chave] = $novo;
    }
    return $cache[$chave] ?? "";
}

function cfg_set($chave, $valor)
{
    sql("REPLACE INTO config (chave, valor) VALUES (?, ?)", [$chave, (string)$valor]);
    cfg($chave, (string)$valor);
}

// O usuário do site que veio no pedido (HTTP Basic, com a senha conferida), ou "" (as páginas e a API)
function usuario_autenticado()
{
    $login = $_SERVER["PHP_AUTH_USER"] ?? "";
    $senha = $_SERVER["PHP_AUTH_PW"] ?? "";
    if ($login === "") {
        $cab = $_SERVER["HTTP_AUTHORIZATION"] ?? ($_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "");
        if (stripos($cab, "Basic ") === 0) {
            $par = explode(':', (string)base64_decode(substr($cab, 6)), 2);
            if (count($par) === 2) {
                $login = $par[0];
                $senha = $par[1];
            }
        }
    }
    $res = "";
    if ($login !== "") {
        $hash = valor("SELECT senha_hash FROM usuario WHERE login = ?", [$login]);
        if ($hash && password_verify($senha, $hash)) {
            $res = $login;
        }
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// Critérios de escolha: um conjunto por lugar da árvore ("" todos, "g:<no>" um ponto, "r:<relógio>" um relógio). O
// relógio usa o conjunto mais perto dele, inteiro. Cada subparâmetro mede uma variável (campo ou fórmula); as faixas
// transformam o valor numa nota de 0 a 100.
// ---------------------------------------------------------------------------------------------------------------------
function criterios_config($recarregar = false)
{
    static $cfg = null;
    if ($cfg === null || $recarregar) {
        $cfg = [];
        foreach (linhas("SELECT * FROM criterio_parametro ORDER BY ordem, id") as $p) {
            $p["subs"] = [];
            foreach (linhas("SELECT * FROM criterio_sub WHERE parametro_id = ? ORDER BY ordem, id", [$p["id"]]) as $sb) {
                $sb["faixas"] = linhas("SELECT * FROM criterio_faixa WHERE sub_id = ? ORDER BY categoria, de", [$sb["id"]]);
                $p["subs"][] = $sb;
            }
            $chave = $p["escopo_relogio_id"] !== null ? "r:" . (int)$p["escopo_relogio_id"] : ($p["escopo_no_id"] !== null ? "g:" . (int)$p["escopo_no_id"] : "");
            $cfg[$chave][] = $p;
        }
    }
    return $cfg;
}

// O lugar por extenso: "todos os relógios", o caminho do ponto, ou "relógio: nome"
function escopo_texto($chave)
{
    $res = "todos os relógios";
    if (strpos((string)$chave, "r:") === 0) {
        $res = "relógio: " . (valor("SELECT nome FROM relogio WHERE id = ?", [(int)substr($chave, 2)]) ?? "excluído");
    } elseif (strpos((string)$chave, "g:") === 0) {
        $res = no_caminho((int)substr($chave, 2));
    }
    return $res;
}

// A nota de 0 a 100 de um relógio, pelo conjunto mais perto dele, com a conta. Subparâmetro sem valor sai e os pesos dos
// que ficam se reescalam. Sem conjunto nenhum: 50.
function nota_do_relogio($r, $momento)
{
    $cfg = criterios_config();
    $chave = isset($cfg[""]) ? "" : null;
    foreach (no_cadeia($r["no_id"] ?? 0) as $g) {
        if (isset($cfg["g:" . $g])) {
            $chave = "g:" . $g;
        }
    }
    if (isset($cfg["r:" . (int)$r["id"]])) {
        $chave = "r:" . (int)$r["id"];
    }
    $ctx = ["r" => $r, "momento" => $momento, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
    $params = [];
    foreach ($chave !== null ? $cfg[$chave] : [] as $p) {
        $subs = [];
        foreach ($p["subs"] as $sb) {
            $v = variavel_valor($sb["variavel"], $ctx);
            $nota = null;
            $faixa = "";
            foreach ($sb["faixas"] as $i => $f) {
                if ($nota === null && $v !== null) {
                    if ($f["categoria"] !== null) {
                        if ((string)$v === $f["categoria"]) {
                            $nota = (float)$f["nota"];
                            $faixa = $f["categoria"];
                        }
                    } elseif (is_numeric($v) && (float)$v >= (float)$f["de"] && ($f["ate"] === null || (float)$v < (float)$f["ate"] || ($i === count($sb["faixas"]) - 1 && (float)$v <= (float)$f["ate"]))) {
                        $nota = (float)$f["nota"];
                        $faixa = (0 + (float)$f["de"]) . " a " . ($f["ate"] === null ? "mais" : (0 + (float)$f["ate"]));
                    }
                }
            }
            if ($nota !== null) {
                $subs[] = ["sb" => $sb, "valor" => $v, "faixa" => $faixa, "nota" => $nota];
            }
        }
        if (count($subs) > 0) {
            $params[] = ["p" => $p, "subs" => $subs, "soma" => array_sum(array_map(function ($x) { return (float)$x["sb"]["peso"]; }, $subs))];
        }
    }
    $total = 50.0;
    $conta = [];
    if (count($params) > 0) {
        $soma_p = array_sum(array_map(function ($x) { return (float)$x["p"]["peso"]; }, $params));
        $total = 0.0;
        foreach ($params as $x) {
            $fp = $soma_p > 0 ? (float)$x["p"]["peso"] / $soma_p : 1 / count($params);
            foreach ($x["subs"] as $s) {
                $fs = $x["soma"] > 0 ? (float)$s["sb"]["peso"] / $x["soma"] : 1 / count($x["subs"]);
                $total += $s["nota"] * $fp * $fs;
                $conta[] = ["parametro" => $x["p"]["nome"], "subparametro" => $s["sb"]["nome"], "variavel" => $s["sb"]["variavel"], "valor" => $s["valor"],
                    "faixa" => $s["faixa"], "nota" => $s["nota"], "peso_efetivo" => round($fp * $fs * 100, 2), "pontos" => round($s["nota"] * $fp * $fs, 2)];
            }
        }
    }
    return ["nota" => round($total, 2), "conjunto" => $chave, "conjunto_texto" => $chave !== null ? escopo_texto($chave) : "nenhum", "conta" => $conta];
}

// ---------------------------------------------------------------------------------------------------------------------
// O rodízio: o plano (o relógio de cada dia), pelo modo ativo, e a sessão no pulso do relógio do dia
// ---------------------------------------------------------------------------------------------------------------------

// O plano de um dia (Y-m-d), com o nome do relógio; null se não há
// O ciclo do rodízio: dos candidatos de um bloco ($ids), os que já passaram no ciclo atual, pela sequência dos relógios que
// entraram no rodízio que está sendo montado (do mais antigo para o mais recente: o plano desde a segunda-feira da semana, e
// o que a escala acabou de escolher; o uso de antes pesa pela nota e pelos dias sem uso). A sequência é lida em ordem:
// cada candidato que aparece entra no ciclo; quando todos entraram, o ciclo fecha e começa outro. Os que estão no ciclo
// aberto não voltam enquanto houver candidato que ainda não passou. Os relógios que não são candidatos do bloco não contam.
function ciclo_atual($sequencia, $ids)
{
    $ciclo = [];
    foreach ($sequencia as $x) {
        if (in_array((int)$x, $ids, true)) {
            $ciclo[(int)$x] = true;
            if (count($ciclo) >= count($ids)) {
                $ciclo = [];
            }
        }
    }
    return array_keys($ciclo);
}

function plano_do_dia($data)
{
    return linha("SELECT p.*, r.nome FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data = ?", [$data]);
}

// Para o motivo de uma escolha do plano (a frase que diz por que aquele relógio saiu): os dias sem uso por extenso
// ("nunca usado", "parado há 23 dias") e a nota com vírgula ("91,2")
function motivo_parado($dias_sem_uso, $nunca_usado = false)
{
    $dias = str_replace(".", ",", (string)round($dias_sem_uso, $dias_sem_uso < 10 ? 1 : 0)) . " dias";
    if ($dias_sem_uso >= 9999) {
        return "nunca usado";
    }
    return $nunca_usado ? "nunca usado, na coleção há " . $dias : "parado há " . $dias;
}

// O relógio nunca foi ao pulso (nem nos dias do plano já simulados)? Pela mesma conta das fórmulas. Os dias sem uso de um
// relógio assim vêm da fórmula dias_sem_uso (desde a compra, ou 9999), e o motivo diz que ele nunca foi usado
function nunca_no_pulso(&$ctx)
{
    static $arvore = null;
    if ($arvore === null) {
        $arvore = formula_ler("DIAS_DESDE_ULTIMO(\"pulso\")");
    }
    return formula_calcular($arvore, $ctx) === null;
}

function motivo_nota($nota)
{
    return str_replace(".", ",", (string)round((float)$nota, 1));
}

// Monta o plano que falta, de hoje até $ate (padrão: o domingo desta semana; no domingo, também a segunda, para o aviso
// da véspera). Cada dia sai do bloco do modo ativo que cobre aquele dia da semana: o relógio fixo do bloco; ou, num bloco
// de um por bloco, o mesmo relógio já sorteado para o bloco nesta semana; ou um sorteio entre os disponíveis do ponto do
// bloco (nunca o de ontem, se houver outro). O sorteio: primeiro a garantia de rodízio (quem está há mais de max_sem_uso
// dias sem uso, o mais tempo parado primeiro; não vale no aleatório); depois a forma de escolha do modo, pela nota. A nota
// e os dias sem uso são os do começo do dia sorteado, com os dias anteriores do plano simulados no pulso (como na escala):
// o relógio sorteado num dia chega aos seguintes como usado (o uso conta; a carga não é gasta, porque não se sabe se ele vai
// ser carregado). Com o ciclo do modo (opção, desligada por padrão), quem já passou na semana não volta enquanto houver
// candidato do bloco que ainda não passou (ciclo_atual).
// Na escala inteligente (modo com escala_dias), a escala gera o próprio período de uma vez (gerar_escala): quando ainda
// não há escala, quando ela venceu, ou quando falta hoje ou amanhã e ela ainda não foi refeita hoje.
// $manter_pulso: hoje sem plano e com um relógio já no pulso pelo rodízio, o plano de hoje é ele; false só no sortear de
// novo inclusive hoje, que quer outro sorteio para hoje.
function garantir_plano($hoje, $ate = null, $manter_pulso = true)
{
    $modo = linha("SELECT * FROM modo WHERE id = ?", [(int)cfg("modo_ativo")]);
    if ($modo) {
        // hoje sem plano, mas com um relógio já no pulso pelo rodízio: o plano de hoje é ele (não se sorteia outro)
        $no_pulso = $manter_pulso ? valor("SELECT l.relogio_id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE t.identificador = 'pulso' AND l.origem = 'rodizio' AND DATE(l.inicio) = ? ORDER BY l.inicio DESC, l.id DESC LIMIT 1", [$hoje->format("Y-m-d")]) : null;
        if (plano_do_dia($hoje->format("Y-m-d")) === null && $no_pulso !== null) {
            sql("INSERT INTO plano (data, relogio_id, bloco_id, origem, motivo, criado) VALUES (?, ?, NULL, 'manual', ?, NOW())",
                [$hoje->format("Y-m-d"), (int)$no_pulso, "Já estava no pulso pelo rodízio quando o plano de hoje foi montado."]);
        }
        $blocos = linhas("SELECT * FROM modo_bloco WHERE modo_id = ? ORDER BY ordem, id", [(int)$modo["id"]]);
        if ($ate === null) {
            $ate = $hoje->modify((int)$hoje->format("N") === 7 ? "+1 day" : "sunday this week");
        }
        if ($modo["escala_dias"] !== null) {
            if (cfg("escala_fim") === "" || cfg("escala_fim") < $hoje->format("Y-m-d") || ((plano_do_dia($hoje->format("Y-m-d")) === null
                || plano_do_dia($hoje->modify("+1 day")->format("Y-m-d")) === null) && cfg("escala_gerada") !== date("Y-m-d"))) {
                gerar_escala($hoje);
            }
            // a escala já montou o período dela: o laço abaixo não sorteia nada
            $ate = $hoje->modify("-1 day");
        }
        // cada dia do plano (o que já estava e o que acabou de ser sorteado) vira uma sessão simulada no pulso, só de uso, e
        // cada dia é sorteado pelos critérios recalculados no começo daquele dia; assim o relógio sorteado num dia chega aos
        // seguintes como usado (o último uso, as horas de uso), sem mexer na carga (não se sabe se ele vai ser carregado)
        $agora = time();
        $pulso = lancamento_tipos()["pulso"] ?? null;
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $GLOBALS["SIMULACAO"] = [];
        $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
        for ($dia = $hoje; $dia <= $ate; $dia = $dia->modify("+1 day")) {
            $ini_u = strtotime($dia->format("Y-m-d") . " " . cfg("uso_inicio"));
            $fim_u = strtotime($dia->format("Y-m-d") . " " . cfg("uso_fim"));
            $m_ini = max($agora, $ini_u);
            if (plano_do_dia($dia->format("Y-m-d")) === null) {
                $bloco = null;
                foreach ($blocos as $b) {
                    if ($bloco === null && in_array($dia->format("N"), explode(",", $b["dias"]), true)) {
                        $bloco = $b;
                    }
                }
                if ($bloco !== null) {
                    $escolhido = null;
                    // o motivo: a frase que fica no plano dizendo por que este relógio saiu (a escolha em si não muda por ela)
                    $motivo = "";
                    if ($bloco["relogio_id"] !== null && (int)valor("SELECT disponivel FROM relogio WHERE id = ?", [(int)$bloco["relogio_id"]]) === 1) {
                        $escolhido = (int)$bloco["relogio_id"];
                        $motivo = "Relógio fixo do bloco " . $bloco["nome"] . ".";
                    } elseif ($bloco["um_por"] === "bloco") {
                        $seg = $dia->modify("monday this week");
                        $v = valor("SELECT relogio_id FROM plano WHERE bloco_id = ? AND data BETWEEN ? AND ? ORDER BY data LIMIT 1",
                            [(int)$bloco["id"], $seg->format("Y-m-d"), $seg->modify("+6 days")->format("Y-m-d")]);
                        $escolhido = $v !== null ? (int)$v : null;
                        $motivo = "O mesmo relógio da semana no bloco " . $bloco["nome"] . " (um relógio para o bloco inteiro).";
                    }
                    if ($escolhido === null) {
                        $fora = [];
                        $cands = [];
                        foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY id") as $r) {
                            if ($bloco["no_id"] === null || in_array((int)$bloco["no_id"], no_cadeia($r["no_id"] ?? 0), true)) {
                                $cands[(int)$r["id"]] = $r;
                            }
                        }
                        $ontem = valor("SELECT relogio_id FROM plano WHERE data = ?", [$dia->modify("-1 day")->format("Y-m-d")]);
                        if ($ontem !== null && count($cands) > 1) {
                            if (isset($cands[(int)$ontem])) {
                                $fora[] = "o de ontem (" . $cands[(int)$ontem]["nome"] . ") ficou de fora";
                            }
                            unset($cands[(int)$ontem]);
                        }
                        // o ciclo (se o modo usa): os que já passaram no ciclo atual ficam de fora, pelo plano desde a segunda-feira da semana
                        if ((int)($modo["ciclo"] ?? 0) === 1 && count($cands) > 1) {
                            $seq = array_map(function ($x) { return (int)$x["relogio_id"]; }, linhas("SELECT relogio_id FROM plano WHERE data BETWEEN ? AND ? ORDER BY data",
                                [$dia->modify("monday this week")->format("Y-m-d"), $dia->modify("-1 day")->format("Y-m-d")]));
                            $restam = array_diff_key($cands, array_flip(ciclo_atual($seq, array_keys($cands))));
                            if (count($restam) > 0) {
                                if (count($restam) < count($cands)) {
                                    $fora[] = "pelo ciclo, " . (count($cands) - count($restam)) . " que já passaram na semana ficaram de fora";
                                }
                                $cands = $restam;
                            }
                        }
                        if (count($cands) > 0) {
                            $info = [];
                            foreach ($cands as $cid => $r) {
                                $ctx = ["r" => $r, "momento" => $m_ini, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($cid)];
                                $dsu = variavel_valor("dias_sem_uso", $ctx);
                                $info[$cid] = ["dsu" => is_numeric($dsu) ? (float)$dsu : 9999.0, "nota" => nota_do_relogio($r, $m_ini)["nota"], "nunca" => nunca_no_pulso($ctx)];
                            }
                            $entre = count($cands) === 1 ? "único candidato do bloco " . $bloco["nome"] : "entre " . count($cands) . " candidatos";
                            $max = (int)cfg("max_sem_uso");
                            $parados = array_filter($info, function ($x) use ($max) { return $x["dsu"] > $max; });
                            if ($max > 0 && count($parados) > 0 && $modo["selecao"] !== "aleatorio") {
                                uasort($parados, function ($x, $y) { return $y["dsu"] <=> $x["dsu"]; });
                                $escolhido = (int)array_key_first($parados);
                                // os empatados com ele no tempo parado (os nunca usados empatam entre si): sai o cadastrado primeiro
                                $empatados = [];
                                foreach ($parados as $cid => $x) {
                                    if ($cid !== $escolhido && $x["dsu"] == $parados[$escolhido]["dsu"]) {
                                        $empatados[] = $cands[$cid]["nome"];
                                    }
                                }
                                $motivo = "Garantia de rodízio: " . motivo_parado($parados[$escolhido]["dsu"], $parados[$escolhido]["nunca"]) . ", além do limite de " . $max . " dias sem uso"
                                    . (count($parados) > 1 ? " (" . count($parados) . " além do limite; sai o mais tempo parado)" : "") . "."
                                    . (count($empatados) > 0 ? " Empatado com " . implode(", ", $empatados) . ": saiu o cadastrado primeiro." : "");
                            } elseif ($modo["selecao"] === "aleatorio") {
                                $ids = array_keys($cands);
                                $escolhido = $ids[sorteio(0, count($ids) - 1)];
                                $motivo = "Sorteio simples, " . $entre . ", todos com a mesma chance.";
                            } elseif ($modo["selecao"] === "fifo") {
                                uasort($info, function ($x, $y) { return $y["dsu"] <=> $x["dsu"]; });
                                $escolhido = (int)array_key_first($info);
                                $motivo = "Fila: o mais tempo sem uso (" . motivo_parado($info[$escolhido]["dsu"], $info[$escolhido]["nunca"]) . "), " . $entre . ".";
                            } elseif ($modo["selecao"] === "inteligente") {
                                uasort($info, function ($x, $y) { return $y["nota"] <=> $x["nota"]; });
                                $escolhido = (int)array_key_first($info);
                                $motivo = "A maior nota (" . motivo_nota($info[$escolhido]["nota"]) . "), " . $entre . ".";
                            } else {
                                // com sorteio: a nota de cada um é a chance dele (no mínimo 1)
                                $total = 0;
                                foreach ($info as $x) {
                                    $total += (int)round(max(1, $x["nota"]) * 100);
                                }
                                $alvo = sorteio(1, max(1, $total));
                                foreach ($info as $cid => $x) {
                                    $alvo -= (int)round(max(1, $x["nota"]) * 100);
                                    if ($escolhido === null && $alvo <= 0) {
                                        $escolhido = $cid;
                                    }
                                }
                                if ($escolhido !== null) {
                                    $motivo = "Sorteio pela nota, " . $entre . ": nota " . motivo_nota($info[$escolhido]["nota"]) . ", "
                                        . motivo_nota(round(max(1, $info[$escolhido]["nota"]) * 100) * 100 / max(1, $total)) . "% de chance.";
                                }
                            }
                            if (count($fora) > 0 && $motivo !== "") {
                                $motivo .= " " . ucfirst(implode("; ", $fora)) . ".";
                            }
                            if ($bloco["um_por"] === "bloco" && $escolhido !== null) {
                                $motivo .= " Fica o bloco " . $bloco["nome"] . " inteiro.";
                            }
                        }
                    }
                    if ($escolhido !== null) {
                        sql("INSERT INTO plano (data, relogio_id, bloco_id, origem, motivo, criado) VALUES (?, ?, ?, 'sorteio', ?, NOW())",
                            [$dia->format("Y-m-d"), $escolhido, (int)$bloco["id"], $motivo !== "" ? corta_texto($motivo, 300) : null]);
                    }
                }
            }
            // o dia no plano vira uma sessão simulada no pulso (se ainda não tem a do rodízio), para os dias seguintes verem esse
            // uso; só o uso: a carga não é gasta na simulação, porque não se sabe se o relógio vai ser carregado
            $p = plano_do_dia($dia->format("Y-m-d"));
            if ($p && $pulso && $fim_u > $agora && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ?",
                [(int)$pulso["id"], $dia->format("Y-m-d")]) === null) {
                simular_lancamento((int)$p["relogio_id"], "pulso", $m_ini, $fim_u, null, true);
            }
        }
        $GLOBALS["SIMULACAO"] = [];
        $GLOBALS["SIMULACAO_VERSAO"]++;
    }
}

// Um tamanho do php.ini ("8M", "2G", "512K", "1048576") em bytes; 0: sem limite ou não informado
function ini_bytes($v)
{
    $v = trim((string)$v);
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case "g":
            $n *= 1024;
            // segue
        case "m":
            $n *= 1024;
            // segue
        case "k":
            $n *= 1024;
    }
    return max(0, (int)$n);
}

// ---------------------------------------------------------------------------------------------------------------------
// Os documentos de cada relógio: o arquivo numa pasta do servidor (DOCUMENTOS_PASTA, no config.php, fora da pasta
// publicada) e os dados dele na tabela documento, numa categoria (documento_categoria, cadastro)
// ---------------------------------------------------------------------------------------------------------------------

// A pasta dos documentos, sem a barra do fim; null se o config.php não tem DOCUMENTOS_PASTA ou se ela não existe e não dá
// para criar, ou não aceita gravar (documentos_pasta_erro() diz o motivo)
function documentos_pasta()
{
    return documentos_pasta_erro() === "" ? rtrim((string)DOCUMENTOS_PASTA, "/\\") : null;
}

// O motivo de a pasta dos documentos não servir ("" se serve)
function documentos_pasta_erro()
{
    static $erro = null;
    if ($erro === null) {
        if (!defined("DOCUMENTOS_PASTA") || trim((string)DOCUMENTOS_PASTA) === "") {
            $erro = "falta a pasta dos documentos: o DOCUMENTOS_PASTA do config.php (uma pasta do servidor fora da pasta publicada)";
        } else {
            $pasta = rtrim((string)DOCUMENTOS_PASTA, "/\\");
            if (!is_dir($pasta)) {
                @mkdir($pasta, 0750, true);
            }
            $erro = !is_dir($pasta) ? "a pasta dos documentos (" . $pasta . ") não existe e não consegui criá-la"
                : (!is_writable($pasta) ? "a pasta dos documentos (" . $pasta . ") não aceita gravar: dê permissão de escrita ao usuário do PHP" : "");
        }
    }
    return $erro;
}

// O maior documento aceito, em bytes: o DOCUMENTOS_LIMITE do config.php (sem ele, o MANUAL_LIMITE, o nome antigo; sem os
// dois, 100 MB; 0 ou negativo, como o -1: sem limite do sistema), ou menos se o PHP do servidor aceitar menos num envio
// (upload_max_filesize e post_max_size do php.ini; o envio leva também o resto do formulário, daí a folga de 64 KB).
// null: nenhum limite, nem do sistema nem do PHP
function documentos_limite()
{
    $limite = defined("DOCUMENTOS_LIMITE") ? (int)DOCUMENTOS_LIMITE : (defined("MANUAL_LIMITE") ? (int)MANUAL_LIMITE : 100 * 1024 * 1024);
    $limite = $limite > 0 ? $limite : PHP_INT_MAX;
    $arquivo = ini_bytes(ini_get("upload_max_filesize"));
    $envio = ini_bytes(ini_get("post_max_size"));
    if ($arquivo > 0) {
        $limite = min($limite, $arquivo);
    }
    if ($envio > 0) {
        $limite = min($limite, max(0, $envio - 64 * 1024));
    }
    return $limite === PHP_INT_MAX ? null : $limite;
}

// Um tamanho de arquivo para as mensagens: "850 KB", "3,2 MB"
function tamanho_texto($bytes)
{
    return $bytes < 1048576 ? max(1, (int)round($bytes / 1024)) . " KB" : str_replace(".", ",", (string)round($bytes / 1048576, 1)) . " MB";
}

// O tipo (MIME) de um arquivo: pelo conteúdo (a extensão fileinfo do PHP) e, quando ele não diz, pela extensão do nome
function documento_tipo($caminho, $nome)
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    $por_ext = ["jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png", "gif" => "image/gif", "webp" => "image/webp", "avif" => "image/avif",
        "heic" => "image/heic", "bmp" => "image/bmp", "svg" => "image/svg+xml", "mp4" => "video/mp4", "m4v" => "video/mp4", "mov" => "video/quicktime",
        "webm" => "video/webm", "mkv" => "video/x-matroska", "avi" => "video/x-msvideo", "3gp" => "video/3gpp", "mp3" => "audio/mpeg", "m4a" => "audio/mp4",
        "ogg" => "audio/ogg", "wav" => "audio/wav", "pdf" => "application/pdf", "xml" => "application/xml", "txt" => "text/plain", "csv" => "text/csv",
        "zip" => "application/zip", "doc" => "application/msword", "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "xls" => "application/vnd.ms-excel", "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"];
    $tipo = "";
    if (function_exists("finfo_open")) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $tipo = (string)@finfo_file($f, $caminho);
        finfo_close($f);
    }
    // o conteúdo diz pouco (texto, binário genérico, um zip que é docx): vale a extensão, se ela for conhecida
    if (($tipo === "" || in_array($tipo, ["application/octet-stream", "text/plain", "application/zip", "text/xml"], true)) && isset($por_ext[$ext])) {
        $tipo = $tipo === "text/xml" && $ext !== "xml" ? $tipo : $por_ext[$ext];
    }
    return $tipo !== "" ? substr($tipo, 0, 100) : "application/octet-stream";
}

// A família do arquivo, que decide como ele abre: imagem (a galeria), video (o player em sequência), audio, pdf (o
// visualizador), xml (o resumo da nota e o download) ou outro (só o download)
function documento_familia($tipo, $nome = "")
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    if ($tipo === "image/svg+xml") {
        return "outro";
    }
    foreach (["image/" => "imagem", "video/" => "video", "audio/" => "audio"] as $pre => $fam) {
        if (strpos($tipo, $pre) === 0) {
            return $fam;
        }
    }
    if ($tipo === "application/pdf") {
        return "pdf";
    }
    return in_array($tipo, ["application/xml", "text/xml"], true) || $ext === "xml" ? "xml" : "outro";
}

// As famílias que uma categoria aceita (vazio: qualquer arquivo)
function documento_familias_aceitas($aceita)
{
    return array_values(array_filter(array_map("trim", explode(",", (string)$aceita)), function ($x) { return $x !== ""; }));
}

// Um nome novo de arquivo dentro da pasta (r<relógio>/<aleatório>), com a subpasta criada
function documento_novo_arquivo($pasta, $rid, $nome)
{
    $sub = "r" . (int)$rid;
    if (!is_dir($pasta . "/" . $sub)) {
        @mkdir($pasta . "/" . $sub, 0750, true);
    }
    $ext = strtolower(preg_replace("/[^A-Za-z0-9]/", "", pathinfo($nome, PATHINFO_EXTENSION)));
    return $sub . "/" . bin2hex(random_bytes(12)) . ($ext !== "" ? "." . substr($ext, 0, 10) : "");
}

// Os dados de um documento para a API: a linha da tabela, com a família, os endereços e, no XML de uma NF-e, o resumo dela
function documento_info($d, $com_nfe = true)
{
    $familia = documento_familia($d["tipo"], $d["nome"]);
    $res = ["id" => (int)$d["id"], "relogio_id" => (int)$d["relogio_id"], "categoria_id" => (int)$d["categoria_id"], "titulo" => $d["titulo"],
        "data" => $d["data"] === null ? null : substr((string)$d["data"], 0, 10), "descricao" => $d["descricao"], "nome" => $d["nome"], "tipo" => $d["tipo"],
        "familia" => $familia, "tamanho" => (int)$d["tamanho"], "miniatura" => $d["miniatura"] !== null && $d["miniatura"] !== "", "criado" => $d["criado"],
        "url" => "api.php?recurso=documento&id=" . (int)$d["id"], "no_disco" => documentos_pasta() !== null && is_file(documentos_pasta() . "/" . $d["arquivo"]),
        "no_banco" => (int)($d["no_banco"] ?? 0) === 1, "nfe" => null];
    if ($com_nfe && $familia === "xml" && documentos_pasta() !== null) {
        $res["nfe"] = nfe_resumo(documentos_pasta() . "/" . $d["arquivo"]);
    }
    return $res;
}

// Os documentos de um relógio (null: de todos), da categoria e da data
function documentos_do_relogio($rid = null)
{
    return linhas("SELECT d.* FROM documento d JOIN documento_categoria c ON c.id = d.categoria_id" . ($rid !== null ? " WHERE d.relogio_id = ?" : "")
        . " ORDER BY d.relogio_id, c.ordem, c.id, COALESCE(d.data, '9999-12-31'), d.criado, d.id", $rid !== null ? [(int)$rid] : []);
}

// O resumo de uma NF-e (o XML da nota fiscal eletrônica): o emitente, o número, a série, a data, o valor, a chave e os
// produtos; null se o arquivo não é uma NF-e. Lido sem baixar nada de fora (LIBXML_NONET) e sem entidades
function nfe_resumo($caminho)
{
    if (!is_file($caminho) || filesize($caminho) > 5 * 1048576 || !class_exists("DOMDocument")) {
        return null;
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML((string)file_get_contents($caminho), LIBXML_NONET)) {
        return null;
    }
    $inf = $dom->getElementsByTagName("infNFe")->item(0);
    if ($inf === null) {
        return null;
    }
    $um = function ($pai, $tag) {
        $n = $pai !== null ? $pai->getElementsByTagName($tag)->item(0) : null;
        return $n !== null ? trim($n->textContent) : null;
    };
    $emit = $inf->getElementsByTagName("emit")->item(0);
    $ide = $inf->getElementsByTagName("ide")->item(0);
    $tot = $inf->getElementsByTagName("ICMSTot")->item(0);
    $produtos = [];
    foreach ($inf->getElementsByTagName("prod") as $p) {
        $produtos[] = ["descricao" => $um($p, "xProd"), "quantidade" => is_numeric($um($p, "qCom")) ? (float)$um($p, "qCom") : null,
            "valor" => is_numeric($um($p, "vProd")) ? (float)$um($p, "vProd") : null];
    }
    $data = $um($ide, "dhEmi") ?? $um($ide, "dEmi");
    return ["emitente" => $um($emit, "xNome"), "cnpj" => $um($emit, "CNPJ") ?? $um($emit, "CPF"), "numero" => $um($ide, "nNF"), "serie" => $um($ide, "serie"),
        "data" => $data !== null ? substr($data, 0, 10) : null, "valor" => is_numeric($um($tot, "vNF")) ? (float)$um($tot, "vNF") : null,
        "chave" => preg_replace("/^NFe/", "", (string)$inf->getAttribute("Id")), "produtos" => $produtos];
}

// Manda um documento para o navegador: inline só os tipos que o navegador mostra sem rodar nada (imagens, vídeo, áudio,
// PDF, texto); o resto (inclusive HTML, SVG e XML, que rodariam no endereço do sistema) vai como download. Atende o
// pedido de um pedaço (Range), que o vídeo usa para avançar
function documento_enviar($d, $caminho, $baixar, $miniatura = false)
{
    $tipo = $miniatura ? "image/jpeg" : $d["tipo"];
    $mostra = !$baixar && ($miniatura || in_array(documento_familia($d["tipo"], $d["nome"]), ["imagem", "video", "audio", "pdf"], true) || $d["tipo"] === "text/plain");
    $tamanho = filesize($caminho);
    $ini = 0;
    $fim = $tamanho - 1;
    if (!$miniatura && preg_match("/^bytes=(\\d*)-(\\d*)$/", trim((string)($_SERVER["HTTP_RANGE"] ?? "")), $m) === 1 && ($m[1] !== "" || $m[2] !== "")) {
        if ($m[1] === "") {
            $ini = max(0, $tamanho - (int)$m[2]);
        } else {
            $ini = (int)$m[1];
            $fim = $m[2] !== "" ? min((int)$m[2], $tamanho - 1) : $tamanho - 1;
        }
        if ($ini > $fim || $ini >= $tamanho) {
            http_response_code(416);
            header("Content-Range: bytes */" . $tamanho);
            return;
        }
        http_response_code(206);
        header("Content-Range: bytes " . $ini . "-" . $fim . "/" . $tamanho);
    }
    $ascii = preg_replace("/[^A-Za-z0-9._ -]/", "_", $d["nome"]);
    header("Content-Type: " . ($mostra ? $tipo : "application/octet-stream"));
    header("Content-Length: " . ($fim - $ini + 1));
    header("Accept-Ranges: bytes");
    header("Content-Disposition: " . ($mostra ? "inline" : "attachment") . "; filename=\"" . $ascii . "\"; filename*=UTF-8''" . rawurlencode($d["nome"]));
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: private, max-age=86400");
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @set_time_limit(0);
    $f = fopen($caminho, "rb");
    fseek($f, $ini);
    $falta = $fim - $ini + 1;
    while ($falta > 0 && !feof($f)) {
        $pedaco = fread($f, (int)min(1048576, $falta));
        if ($pedaco === false || $pedaco === "") {
            break;
        }
        echo $pedaco;
        $falta -= strlen($pedaco);
        flush();
    }
    fclose($f);
}

// As categorias dos documentos, na ordem, com quantos documentos cada uma tem (de um relógio; null: de todos)
function documento_categorias_lista($rid = null)
{
    return array_map(function ($c) {
        return ["id" => (int)$c["id"], "identificador" => $c["identificador"], "nome" => $c["nome"], "aceita" => documento_familias_aceitas($c["aceita"]),
            "ordem" => (int)$c["ordem"], "documentos" => (int)$c["documentos"]];
    }, linhas("SELECT c.*, (SELECT COUNT(*) FROM documento d WHERE d.categoria_id = c.id" . ($rid !== null ? " AND d.relogio_id = ?" : "") . ") AS documentos
        FROM documento_categoria c ORDER BY c.ordem, c.id", $rid !== null ? [(int)$rid] : []));
}

// Apaga os arquivos de um documento (o arquivo e a miniatura) da pasta
function documento_apagar_arquivos($d)
{
    $pasta = documentos_pasta();
    if ($pasta !== null) {
        foreach ([$d["arquivo"], $d["miniatura"]] as $a) {
            if ($a !== null && $a !== "" && strpos($a, "..") === false && is_file($pasta . "/" . $a)) {
                @unlink($pasta . "/" . $a);
            }
        }
    }
}

// A cópia de segurança dos documentos no banco está ligada: o DOCUMENTOS_COPIA_BANCO do config.php (sem ele, ligada)
function documentos_copia_banco()
{
    return !defined("DOCUMENTOS_COPIA_BANCO") || (bool)DOCUMENTOS_COPIA_BANCO;
}

// O tamanho de cada pedaço da cópia no banco: 4 MB cabem em qualquer max_allowed_packet do MySQL (o menor padrão é 16 MB)
// e na memória do PHP, um de cada vez
const DOCUMENTO_PEDACO = 4 * 1024 * 1024;

// Copia o arquivo de um documento (e a miniatura, na parte -1) para o banco, em pedaços, lendo da pasta um pedaço por vez;
// no fim, marca no_banco = 1 com o SHA-256 do arquivo. Devolve "" se deu certo, ou o motivo (a cópia parcial sai)
function documento_copiar_para_banco($d)
{
    $pasta = documentos_pasta();
    $caminho = $pasta !== null ? $pasta . "/" . $d["arquivo"] : null;
    if ($caminho === null || !is_file($caminho)) {
        return "o arquivo não está na pasta";
    }
    try {
        sql("DELETE FROM documento_parte WHERE documento_id = ?", [(int)$d["id"]]);
        sql("UPDATE documento SET no_banco = 0 WHERE id = ?", [(int)$d["id"]]);
        $h = hash_init("sha256");
        $f = fopen($caminho, "rb");
        $parte = 0;
        while (!feof($f)) {
            $pedaco = fread($f, DOCUMENTO_PEDACO);
            if ($pedaco === false || $pedaco === "") {
                break;
            }
            // o fread de um arquivo comum devolve o pedaço inteiro; o resto do pedaço, se faltar, vem nas voltas seguintes
            while (strlen($pedaco) < DOCUMENTO_PEDACO && !feof($f)) {
                $mais = fread($f, DOCUMENTO_PEDACO - strlen($pedaco));
                if ($mais === false || $mais === "") {
                    break;
                }
                $pedaco .= $mais;
            }
            hash_update($h, $pedaco);
            sql("INSERT INTO documento_parte (documento_id, parte, dados) VALUES (?, ?, ?)", [(int)$d["id"], $parte, documento_pedaco_para_banco($pedaco)]);
            $parte++;
        }
        fclose($f);
        if ($d["miniatura"] !== null && $d["miniatura"] !== "" && is_file($pasta . "/" . $d["miniatura"])) {
            sql("INSERT INTO documento_parte (documento_id, parte, dados) VALUES (?, -1, ?)", [(int)$d["id"], (string)file_get_contents($pasta . "/" . $d["miniatura"])]);
        }
        sql("UPDATE documento SET no_banco = 1, hash = ? WHERE id = ?", [hash_final($h), (int)$d["id"]]);
        return "";
    } catch (Throwable $t) {
        try {
            sql("DELETE FROM documento_parte WHERE documento_id = ?", [(int)$d["id"]]);
        } catch (Throwable $t2) {
            // o banco não respondeu nem para limpar: a parte que ficou é refeita na próxima cópia
        }
        return $t->getMessage();
    }
}

// Um pedaço de arquivo pronto para a coluna binária: no PostgreSQL, um pedaço que parece texto (um CSV, um XML) iria como
// texto para o bytea, e as barras invertidas dele virariam escapes; vai então já no formato binário dele (\x e o hexadecimal)
function documento_pedaco_para_banco($pedaco)
{
    return banco_tipo() === "pgsql" && !banco_binario($pedaco) ? "\\x" . bin2hex($pedaco) : $pedaco;
}

// Recria na pasta o arquivo de um documento (e a miniatura) a partir da cópia no banco, um pedaço por vez, conferindo o
// SHA-256; o arquivo só aparece na pasta quando está inteiro e certo. Devolve verdadeiro se o arquivo está na pasta no fim
function documento_restaurar($d)
{
    $pasta = documentos_pasta();
    if ($pasta === null || strpos((string)$d["arquivo"], "..") !== false) {
        return false;
    }
    $caminho = $pasta . "/" . $d["arquivo"];
    $mini = $d["miniatura"] !== null && $d["miniatura"] !== "" ? $pasta . "/" . $d["miniatura"] : null;
    if ($mini !== null && !is_file($mini) && (int)$d["no_banco"] === 1) {
        $m = valor("SELECT dados FROM documento_parte WHERE documento_id = ? AND parte = -1", [(int)$d["id"]]);
        if ($m !== null) {
            if (!is_dir(dirname($mini))) {
                @mkdir(dirname($mini), 0750, true);
            }
            @file_put_contents($mini, $m);
        }
    }
    if (is_file($caminho)) {
        return true;
    }
    if ((int)$d["no_banco"] !== 1) {
        return false;
    }
    if (!is_dir(dirname($caminho))) {
        @mkdir(dirname($caminho), 0750, true);
    }
    $tmp = $caminho . ".restaurando";
    $f = @fopen($tmp, "wb");
    if ($f === false) {
        return false;
    }
    $h = hash_init("sha256");
    foreach (linhas("SELECT parte FROM documento_parte WHERE documento_id = ? AND parte >= 0 ORDER BY parte", [(int)$d["id"]]) as $p) {
        $pedaco = (string)valor("SELECT dados FROM documento_parte WHERE documento_id = ? AND parte = ?", [(int)$d["id"], (int)$p["parte"]]);
        hash_update($h, $pedaco);
        fwrite($f, $pedaco);
    }
    fclose($f);
    if ($d["hash"] !== null && hash_final($h) !== $d["hash"]) {
        @unlink($tmp);
        return false;
    }
    return @rename($tmp, $caminho);
}

// Apaga uma pasta e tudo o que há dentro
function apagar_pasta($pasta)
{
    $n = 0;
    foreach (scandir($pasta) ?: [] as $x) {
        if ($x === "." || $x === "..") {
            continue;
        }
        if (is_dir($pasta . "/" . $x) && !is_link($pasta . "/" . $x)) {
            $n += apagar_pasta($pasta . "/" . $x);
        } elseif (@unlink($pasta . "/" . $x)) {
            $n++;
        }
    }
    @rmdir($pasta);
    return $n;
}

// A manutenção dos documentos, que o cron roda a cada minuto. Devolve as linhas do registro (vazio: nada a fazer):
// 1. o documento cujo arquivo sumiu da pasta volta do banco (a cópia de segurança);
// 2. o documento que ainda não tem cópia no banco ganha (até $orcamento bytes por rodada, para não pesar);
// 3. a pasta de um relógio que não existe mais sai inteira (r<id>), e o arquivo que não é de nenhum documento sai (só os
//    de mais de 10 minutos: um envio pode estar no meio); o documento de um relógio que não existe mais sai do banco.
// Com $diario, conta também os documentos perdidos (sem o arquivo na pasta e sem a cópia no banco).
function documentos_manutencao($orcamento, $diario = false)
{
    $log = [];
    $pasta = documentos_pasta();
    if ($pasta === null) {
        return $log;
    }
    $orfaos = (int)valor("SELECT COUNT(*) FROM documento WHERE relogio_id NOT IN (SELECT id FROM relogio)");
    if ($orfaos > 0) {
        sql("DELETE FROM documento WHERE relogio_id NOT IN (SELECT id FROM relogio)");
        $log[] = "documentos: " . $orfaos . ($orfaos === 1 ? " documento de relógio que não existe mais apagado" : " documentos de relógios que não existem mais apagados");
    }
    $restaurados = [];
    $perdidos = 0;
    $copiados = 0;
    $falhas = [];
    $usados = [];
    foreach (linhas("SELECT id, relogio_id, titulo, arquivo, miniatura, no_banco, hash, tamanho FROM documento ORDER BY id") as $d) {
        $usados[$d["arquivo"]] = true;
        if ($d["miniatura"] !== null && $d["miniatura"] !== "") {
            $usados[$d["miniatura"]] = true;
        }
        $tem = is_file($pasta . "/" . $d["arquivo"]);
        $mini_falta = $d["miniatura"] !== null && $d["miniatura"] !== "" && !is_file($pasta . "/" . $d["miniatura"]);
        if (!$tem || $mini_falta) {
            if (documento_restaurar($d) && !$tem) {
                $restaurados[] = $d["titulo"];
            } elseif (!$tem) {
                $perdidos++;
            }
        } elseif ((int)$d["no_banco"] !== 1 && documentos_copia_banco() && $orcamento > 0) {
            $erro = documento_copiar_para_banco(linha("SELECT * FROM documento WHERE id = ?", [(int)$d["id"]]));
            if ($erro === "") {
                $copiados++;
                $orcamento -= (int)$d["tamanho"];
            } else {
                $falhas[] = $d["titulo"] . " (" . $erro . ")";
            }
        }
    }
    if (count($restaurados) > 0) {
        $log[] = "documentos: " . count($restaurados) . (count($restaurados) === 1 ? " arquivo sumido da pasta, recriado" : " arquivos sumidos da pasta, recriados") . " a partir do banco: " . implode(", ", array_slice($restaurados, 0, 10));
    }
    if ($copiados > 0) {
        $log[] = "documentos: " . $copiados . ($copiados === 1 ? " documento copiado" : " documentos copiados") . " para o banco";
    }
    if (count($falhas) > 0) {
        $log[] = "erro: a cópia no banco falhou: " . implode("; ", array_slice($falhas, 0, 5));
    }
    if ($diario && $perdidos > 0) {
        $log[] = "aviso: " . $perdidos . ($perdidos === 1 ? " documento sem o arquivo" : " documentos sem o arquivo") . " na pasta e sem a cópia no banco (perdidos: exclua-os na página Documentos)";
    }
    // a pasta: as dos relógios que não existem mais, e os arquivos de nenhum documento
    $relogios = [];
    foreach (linhas("SELECT id FROM relogio") as $r) {
        $relogios[(int)$r["id"]] = true;
    }
    $pastas_apagadas = [];
    $soltos = 0;
    foreach (scandir($pasta) ?: [] as $x) {
        if (preg_match("/^r(\\d+)$/", $x, $m) !== 1 || !is_dir($pasta . "/" . $x)) {
            continue;
        }
        if (!isset($relogios[(int)$m[1]])) {
            $n = apagar_pasta($pasta . "/" . $x);
            $pastas_apagadas[] = $x . " (" . $n . ($n === 1 ? " arquivo)" : " arquivos)");
            continue;
        }
        foreach (scandir($pasta . "/" . $x) ?: [] as $a) {
            $rel = $x . "/" . $a;
            if ($a !== "." && $a !== ".." && is_file($pasta . "/" . $rel) && !isset($usados[$rel]) && filemtime($pasta . "/" . $rel) < time() - 600) {
                if (@unlink($pasta . "/" . $rel)) {
                    $soltos++;
                }
            }
        }
    }
    if (count($pastas_apagadas) > 0) {
        $log[] = "documentos: pastas de relógios que não existem mais apagadas: " . implode(", ", $pastas_apagadas);
    }
    if ($soltos > 0) {
        $log[] = "documentos: " . $soltos . ($soltos === 1 ? " arquivo que não era de nenhum documento apagado" : " arquivos que não eram de nenhum documento apagados") . " da pasta";
    }
    return $log;
}

// Os manuais da v13 (guardados no banco, na tabela manual) passam para os documentos, na categoria Manual (sem ela, na
// primeira categoria), assim que a pasta dos documentos existe. Um por vez, para não pesar na memória; a tabela fica vazia
function mover_manuais()
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $feito = true;
    $pasta = documentos_pasta();
    if ($pasta === null || (int)valor("SELECT COUNT(*) FROM manual") === 0) {
        return;
    }
    $cat = valor("SELECT id FROM documento_categoria WHERE identificador = 'manual'") ?? valor("SELECT id FROM documento_categoria ORDER BY ordem, id LIMIT 1");
    if ($cat === null) {
        return;
    }
    foreach (linhas("SELECT relogio_id FROM manual") as $x) {
        $m = linha("SELECT * FROM manual WHERE relogio_id = ?", [(int)$x["relogio_id"]]);
        $arquivo = documento_novo_arquivo($pasta, (int)$m["relogio_id"], $m["nome"]);
        if (@file_put_contents($pasta . "/" . $arquivo, $m["dados"]) === strlen($m["dados"])) {
            sql("INSERT INTO documento (relogio_id, categoria_id, titulo, data, descricao, nome, tipo, tamanho, arquivo, miniatura, criado) VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, NULL, ?)",
                [(int)$m["relogio_id"], (int)$cat, "Manual", $m["nome"], $m["tipo"], strlen($m["dados"]), $arquivo, $m["atualizado"]]);
            if (documentos_copia_banco()) {
                documento_copiar_para_banco(linha("SELECT * FROM documento WHERE id = ?", [ultimo_id()]));
            }
            sql("DELETE FROM manual WHERE relogio_id = ?", [(int)$m["relogio_id"]]);
        } else {
            @unlink($pasta . "/" . $arquivo);
        }
    }
}

// O relógio do dia entra no pulso sozinho no início do horário de uso (a Configuração, ao lado do horário; é o padrão).
// Desligado, ele só entra pelo Pôs.
function pulso_poe_sozinho()
{
    return cfg("pulso_auto_inicio") !== "0";
}

// O relógio sai do pulso sozinho no fim do horário de uso (a Configuração, ao lado do horário; é o padrão): o "fecha às" do
// tipo No pulso segue o fim do horário. Desligado, ele só sai pelo Tirou.
function pulso_tira_sozinho()
{
    return cfg("pulso_auto_fim") !== "0";
}

// As horas no pulso de um relógio entre dois instantes (as sessões juntas, sem contar duas vezes a mesma hora; a aberta, até
// o fim do intervalo)
function horas_no_pulso($rid, $de, $ate)
{
    $h = 0.0;
    $ate_aqui = $de;
    foreach (linhas("SELECT l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo tp ON tp.id = l.tipo_id WHERE l.relogio_id = ? AND tp.identificador = 'pulso'
        AND l.inicio < ? AND (l.fim IS NULL OR l.fim > ?) ORDER BY l.inicio, l.id", [(int)$rid, date("Y-m-d H:i:s", $ate), date("Y-m-d H:i:s", $de)]) as $sp) {
        $a = max($ate_aqui, strtotime($sp["inicio"]));
        $b = min($ate, $sp["fim"] === null ? $ate : strtotime($sp["fim"]));
        if ($b > $a) {
            $h += ($b - $a) / 3600;
            $ate_aqui = $b;
        }
    }
    return $h;
}

// Depois de corrigir o pulso de um relógio entre $de e $ate (uma sessão alterada, excluída ou lançada no passado), as
// medições do gasto que cobrem esse trecho refazem as horas no pulso e fora dele (a queda entre as leituras não muda)
function recalcular_medicoes($rid, $de, $ate)
{
    foreach (linhas("SELECT id, inicio, fim FROM medicao WHERE relogio_id = ? AND inicio < ? AND fim > ?", [(int)$rid, date("Y-m-d H:i:s", $ate), date("Y-m-d H:i:s", $de)]) as $m) {
        $a = strtotime($m["inicio"]);
        $b = strtotime($m["fim"]);
        $hp = horas_no_pulso($rid, $a, $b);
        $hg = ($b - $a) / 3600 - $hp;
        sql("UPDATE medicao SET horas_pulso = ?, horas_guardado = ?, peso_horas = CASE WHEN medida = 'uso' THEN ? ELSE ? END WHERE id = ?",
            [round($hp, 2), round($hg, 2), round($hp, 2), round($hg, 2), (int)$m["id"]]);
    }
}

// As sessões esquecidas abertas (sem fim) de um tipo que fecha sozinho ("fecha às"): passaram da hora, ganham o fim nela, no
// dia em que começaram (a conta já as tratava assim; gravado, o Pôs do dia seguinte não esbarra nela, e o Tirou não a
// estica até agora). $relogio: só as desse relógio (null: todas).
function fechar_esquecidas($agora, $relogio = null)
{
    foreach (linhas("SELECT l.id, l.inicio, t.fecha_as FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.fim IS NULL AND t.formato = 'sessao' AND t.fecha_as IS NOT NULL" . ($relogio !== null ? " AND l.relogio_id = ?" : ""),
        $relogio !== null ? [(int)$relogio] : []) as $l) {
        $fecha = max(strtotime($l["inicio"]), (int)strtotime(substr($l["inicio"], 0, 10) . " " . $l["fecha_as"]));
        if ($fecha <= $agora) {
            sql("UPDATE lancamento SET fim = ? WHERE id = ?", [date("Y-m-d H:i:s", $fecha), (int)$l["id"]]);
        }
    }
}

// O dia de hoje já começou no pulso: pondo sozinho, passou o início do horário de uso; senão, o relógio do dia já foi posto
// no pulso hoje (a sessão do rodízio de hoje já começou)
function dia_comecou($agora)
{
    $hoje = date("Y-m-d", $agora);
    if (pulso_poe_sozinho()) {
        return $agora >= strtotime($hoje . " " . cfg("uso_inicio"));
    }
    return valor("SELECT l.id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE t.identificador = 'pulso' AND l.origem = 'rodizio'
        AND l.inicio >= ? AND l.inicio <= ? LIMIT 1", [$hoje . " 00:00:00", date("Y-m-d H:i:s", $agora)]) !== null;
}

// A sessão no pulso do relógio do dia: no horário de uso, o relógio do plano de hoje ganha uma sessão "pulso" de origem
// rodízio, do início do horário de uso até o fim dele (ou, sem tirar sozinho, até o Tirou). Uma por dia. Sem pôr sozinho,
// nada: a sessão é a do Pôs.
function sessao_do_dia($agora)
{
    if (!pulso_poe_sozinho()) {
        return;
    }
    $hoje = date("Y-m-d", $agora);
    $ini = strtotime($hoje . " " . cfg("uso_inicio"));
    $fim = strtotime($hoje . " " . cfg("uso_fim"));
    $p = plano_do_dia($hoje);
    $tipo = lancamento_tipos()["pulso"] ?? null;
    // uma só por dia, de qualquer relógio: a troca do relógio do dia (usando hoje) fecha a de um e abre a do outro
    if ($p && $tipo && $agora >= $ini && $agora < $fim && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ?",
        [(int)$tipo["id"], $hoje]) === null) {
        sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, 'rodizio', NOW())",
            [(int)$p["relogio_id"], (int)$tipo["id"], date("Y-m-d H:i:s", $ini), pulso_tira_sozinho() ? date("Y-m-d H:i:s", $fim) : null]);
    }
}

// "só hoje" / "só amanhã" (conforme $quando), ou "até sexta, 02/10": até o último dia seguido do mesmo relógio no plano
function texto_ate($data, $quando)
{
    $dias = ["", "segunda", "terça", "quarta", "quinta", "sexta", "sábado", "domingo"];
    $p = plano_do_dia($data);
    $fim = $data;
    $prox = date("Y-m-d", strtotime($data . " +1 day"));
    $q = $p ? plano_do_dia($prox) : null;
    // o limite de voltas só protege de um plano muito longo
    for ($n = 0; $q && (int)$q["relogio_id"] === (int)$p["relogio_id"] && $n < 60; $n++) {
        $fim = $prox;
        $prox = date("Y-m-d", strtotime($prox . " +1 day"));
        $q = plano_do_dia($prox);
    }
    return $fim === $data ? "só " . $quando : "até " . $dias[(int)date("N", strtotime($fim))] . ", " . date("d/m", strtotime($fim));
}

// ---------------------------------------------------------------------------------------------------------------------
// As mensagens, como no sistema antigo: dois canais (Telegram e Google Agenda), cada um com a sua mensagem padrão e os
// avisos que vão por ele; cada aviso pode ter a sua mensagem personalizada em cada canal. As âncoras entre chaves viram
// os valores na hora de enviar.
// ---------------------------------------------------------------------------------------------------------------------
$CANAIS = [
    "tg" => ["nome" => "Telegram", "tipos" => "alerta_tipos",
        "ajuda" => "Cada aviso vira uma mensagem com este texto. As do mesmo horário vão juntas, separadas por uma linha em branco."],
    "ag" => ["nome" => "Google Agenda", "tipos" => "agenda_tipos",
        "ajuda" => "Cada aviso vira um evento. A primeira linha é o título do evento; o resto, a descrição."],
];

// Âncoras dos modelos de mensagem: {nome} vira o valor na hora de enviar
$ANCORAS = [
    "relogio" => "Nome do relógio",
    "codigo" => "Código do relógio",
    "tipo" => "Tipo: o grupo do relógio (Smartwatch, Automático, Solar...)",
    "estado" => "Em uso ou em repouso",
    "carga" => "Carga em %: bateria, reserva, luz ou pilha",
    "acao" => "O que fazer: Dar corda, Carregar...",
    "motivo" => "Por quê, com os números: a reserva acaba em 3h...",
    "aviso" => "Nome do tipo de aviso",
    "ate" => "Até quando fica no pulso: só hoje, até sexta...",
    "ultimo_uso" => "Última vez que foi usado",
    "data" => "Data do aviso ou do evento",
    "hora" => "Hora do aviso ou do evento",
    "dia_semana" => "Dia da semana",
    "relogio_do_dia" => "O relógio de hoje",
    "relogio_de_amanha" => "O relógio de amanhã",
    "quantidade" => "Quantos avisos a mensagem tem",
    "modo" => "Modo de rodízio",
    "link" => "Endereço do sistema (no aviso de um relógio, a ficha dele)",
];

// Repetições dos eventos personalizados
$REPETICOES = ["uma" => "Uma vez", "diaria" => "Todo dia", "semanal" => "Em dias da semana", "mensal" => "Todo mês", "intervalo" => "A cada N dias"];
$DIAS_SEMANA = [1 => "Segunda", 2 => "Terça", 3 => "Quarta", 4 => "Quinta", 5 => "Sexta", 6 => "Sábado", 7 => "Domingo"];
$CURTO = [1 => "seg", 2 => "ter", 3 => "qua", 4 => "qui", 5 => "sex", 6 => "sáb", 7 => "dom"];

// Tipos de aviso: o relógio do dia, a véspera, os avisos cadastrados (pelo identificador) e os eventos criados na
// Configuração ("ev<id>"). Cada um: [nome, quando sai]
function tipos_de_aviso()
{
    $lista = ["dia" => ["Relógio do dia", "manhã"], "vespera" => ["Véspera: preparar o relógio de amanhã", "noite"]];
    // os avisos na ordem em que foram cadastrados
    foreach (linhas("SELECT identificador, MIN(id) AS primeiro FROM aviso GROUP BY identificador ORDER BY primeiro") as $a) {
        $lista[$a["identificador"]] = [avisos_todos()[$a["identificador"]][0]["nome"], "manhã"];
    }
    foreach (linhas("SELECT * FROM evento_personalizado ORDER BY nome") as $ev) {
        $lista["ev" . $ev["id"]] = [$ev["nome"], descricao_repeticao($ev)];
    }
    return $lista;
}

// O que um relógio mostra na tela e nas mensagens: a energia (e de onde ela vem), se está em uso e desde quando, a última
// vez no pulso, a situação (as sessões abertas e a autonomia restante) e a próxima manutenção (o aviso mais perto)
function visao_relogio($r, $agora)
{
    $id = (int)$r["id"];
    $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($id)];
    $energia = isset(formulas_todas()["energia"]) ? variavel_valor("energia", $ctx) : null;
    $f_en = isset(formulas_todas()["energia"]) ? mais_perto(formulas_todas()["energia"], $r) : null;
    // de onde vem a energia: o nome da versão da fórmula, depois dos dois pontos ("Energia agora: bateria do smartwatch")
    // (sem as explicações entre parênteses no fim, como o "(0 a 100)")
    $de = $f_en === null ? "sem medida de carga" : (is_numeric($energia) ? trim((string)preg_replace(["/^[^:]*:/u", "/(\\s*\\([^()]*\\))+\$/u"], "", $f_en["nome"])) : "sem dados para calcular");
    if ((int)$r["disponivel"] !== 1) {
        $de = "indisponível";
    }
    // as sessões abertas agora; a esquecida aberta conta só até o "fecha às" do tipo, no dia em que começou
    $abertas = [];
    $ult_pulso = 0;
    foreach (linhas("SELECT l.inicio, l.fim, l.origem, t.identificador, t.nome, t.fecha_as FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.relogio_id = ? AND t.formato = 'sessao' AND l.inicio <= ? ORDER BY l.inicio, l.id", [$id, date("Y-m-d H:i:s", $agora)]) as $l) {
        $fim = $l["fim"] !== null ? strtotime($l["fim"]) : ($l["fecha_as"] !== null ? max(strtotime($l["inicio"]), (int)strtotime(substr($l["inicio"], 0, 10) . " " . $l["fecha_as"])) : PHP_INT_MAX);
        if ($fim > $agora) {
            $abertas[] = ["tipo" => $l["identificador"], "nome" => $l["nome"], "inicio" => strtotime($l["inicio"]), "origem" => $l["origem"]];
        }
        if ($l["identificador"] === "pulso") {
            $ult_pulso = max($ult_pulso, min($fim, $agora));
        }
    }
    $antigo = valor("SELECT MAX(l.inicio) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso_antigo'", [$id]);
    $ult_pulso = max($ult_pulso, $antigo ? (int)strtotime($antigo) : 0);
    $pulso = null;
    $situacao = [];
    foreach ($abertas as $a) {
        if ($a["tipo"] === "pulso") {
            $pulso = $a;
        } else {
            $min = (int)floor(($agora - $a["inicio"]) / 60);
            $situacao[] = $a["nome"] . " desde " . date("H:i", $a["inicio"]) . " (" . intdiv($min, 60) . " h " . ($min % 60) . " min)";
        }
    }
    // em uso desde o começo da sessão no pulso; em repouso desde o fim da última
    $desde = $pulso !== null ? $pulso["inicio"] : ($ult_pulso > 0 ? $ult_pulso : null);
    $texto = $pulso !== null ? "Em uso" : "Em repouso";
    if ($desde !== null) {
        $texto .= " desde " . (date("Y-m-d", $desde) === date("Y-m-d", $agora) ? date("H:i", $desde)
            : (date("Y-m-d", $desde) === date("Y-m-d", $agora - 86400) ? date("H:i", $desde) . " de ontem" : date("d/m H:i", $desde)));
    }
    $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $ctx["valores"]];
    $autonomia = isset(formulas_todas()["autonomia_restante"]) ? variavel_valor("autonomia_restante", $ctx) : null;
    if (is_numeric($autonomia)) {
        $situacao[] = "Autonomia restante " . (duracao_texto((float)$autonomia / 86400) !== "" ? duracao_texto((float)$autonomia / 86400) : "esgotada");
    }
    $avisos = avisos_do_relogio($r, $agora);
    $prox = count($avisos) > 0 ? $avisos[0] : null;
    return ["energia" => is_numeric($energia) ? (int)round((float)$energia) : null, "de" => $de, "em_uso" => $pulso !== null, "pulso" => $pulso,
        "texto" => $texto, "ultimo" => $pulso !== null ? $agora : $ult_pulso, "situacao" => $situacao, "abertas" => $abertas, "avisos" => $avisos,
        "prox" => $prox, "prox_momento" => $prox ? (int)strtotime($prox["data"]) : 0,
        "prox_falta" => $prox ? ($prox["falta_dias"] < 0 ? "atrasado " : "") . (duracao_texto($prox["falta_dias"]) !== "" ? duracao_texto($prox["falta_dias"]) : "agora") : "—"];
}

// Os avisos de hoje ("Hoje é dia de"): os atrasados ou em breve dos relógios disponíveis, do mais urgente ao mais distante.
// Cada um: [relógio, aviso]
function avisos_de_hoje()
{
    $lista = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY nome") as $r) {
        foreach (avisos_do_relogio($r, time()) as $a) {
            if ($a["estado"] !== "ok") {
                $lista[] = [$r, $a];
            }
        }
    }
    usort($lista, function ($x, $y) { return $x[1]["falta_dias"] <=> $y[1]["falta_dias"]; });
    return $lista;
}

// Valores das âncoras. Com $id, os do relógio; sempre, os gerais. $extra sobrepõe.
function contexto_mensagem($id, $extra, $hoje)
{
    $dias = ["", "segunda", "terça", "quarta", "quinta", "sexta", "sábado", "domingo"];
    $url = rtrim(cfg("url_sistema"), "/");
    $dia = plano_do_dia($hoje->format("Y-m-d"));
    $amanha = plano_do_dia($hoje->modify("+1 day")->format("Y-m-d"));
    $ctx = [
        "relogio" => "", "codigo" => "", "tipo" => "", "estado" => "", "carga" => "", "acao" => "", "motivo" => "", "aviso" => "",
        "ate" => "", "ultimo_uso" => "", "quantidade" => "",
        "data" => $hoje->format("d/m/Y"), "hora" => date("H:i"), "dia_semana" => $dias[(int)$hoje->format("N")],
        "relogio_do_dia" => $dia ? $dia["nome"] : "nenhum", "relogio_de_amanha" => $amanha ? $amanha["nome"] : "nenhum",
        "modo" => (string)valor("SELECT nome FROM modo WHERE id = ?", [(int)cfg("modo_ativo")]), "link" => $url !== "" ? $url . "/" : "",
    ];
    $r = $id > 0 ? linha("SELECT * FROM relogio WHERE id = ?", [$id]) : null;
    if ($r) {
        $v = visao_relogio($r, time());
        $n = nos_todos();
        $ctx["relogio"] = $r["nome"];
        $ctx["codigo"] = (string)$r["id"];
        $ctx["tipo"] = isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "";
        $ctx["estado"] = $v["em_uso"] ? "em uso" : "em repouso";
        $ctx["carga"] = $v["energia"] !== null ? $v["energia"] . "%" : $v["de"];
        $ctx["ultimo_uso"] = $v["ultimo"] > 0 ? date("d/m H:i", $v["ultimo"]) : "nunca";
        if ($url !== "") {
            $ctx["link"] = $url . "/index.php?r=" . $id;
        }
    }
    if (isset($extra["tipo_aviso"])) {
        $ctx["aviso"] = tipos_de_aviso()[$extra["tipo_aviso"]][0] ?? $extra["tipo_aviso"];
        unset($extra["tipo_aviso"]);
    }
    return array_merge($ctx, $extra);
}

// Troca as âncoras {nome} pelos valores e limpa o que sobra de âncora vazia no fim da linha (" — ", "()")
function aplicar_modelo($modelo, $ctx)
{
    $trocas = [];
    foreach ($ctx as $k => $v) {
        $trocas["{" . $k . "}"] = (string)$v;
    }
    $linhas = [];
    foreach (explode("\n", strtr(str_replace("\r\n", "\n", (string)$modelo), $trocas)) as $l) {
        $linhas[] = preg_replace("/[\\s—·:,\\-]+\$/u", "", str_replace(" ()", "", $l));
    }
    return trim(implode("\n", $linhas));
}

// O modelo de um aviso num canal: o personalizado do tipo, se marcado e preenchido; senão a mensagem padrão do canal
function modelo_do_aviso($canal, $tipo)
{
    $modelo = cfg($canal . "_padrao");
    if (cfg($canal . "_proprio_" . $tipo) === "1" && trim(cfg($canal . "_corpo_" . $tipo)) !== "") {
        $modelo = cfg($canal . "_corpo_" . $tipo);
    }
    return $modelo;
}

// Telegram: cada aviso é uma mensagem pelo seu modelo; as do mesmo horário vão agrupadas, separadas por linha em branco.
// Sem avisos, não há mensagem.
function mensagem_telegram($itens, $hoje)
{
    $blocos = [];
    foreach ($itens as $it) {
        $blocos[] = aplicar_modelo(modelo_do_aviso("tg", $it["tipo"]), contexto_mensagem($it["id"], ["acao" => $it["fazer"], "motivo" => $it["motivo"],
            "ate" => $it["ate"], "tipo_aviso" => $it["tipo"], "quantidade" => (string)count($itens)], $hoje));
    }
    return implode("\n\n", array_filter($blocos, function ($b) { return $b !== ""; }));
}

// A mensagem da manhã: o relógio do dia e os avisos atrasados ou em breve, só os marcados para o Telegram
function montar_mensagem($hoje)
{
    $tipos = explode(",", cfg("alerta_tipos"));
    $itens = [];
    $dia = plano_do_dia($hoje->format("Y-m-d"));
    if (in_array("dia", $tipos, true) && $dia) {
        $ate = texto_ate($dia["data"], "hoje");
        $itens[] = ["tipo" => "dia", "id" => (int)$dia["relogio_id"], "fazer" => "Usar hoje", "motivo" => $ate, "ate" => $ate];
    }
    foreach (avisos_de_hoje() as $x) {
        if (in_array($x[1]["identificador"], $tipos, true)) {
            $itens[] = ["tipo" => $x[1]["identificador"], "id" => (int)$x[0]["id"], "fazer" => $x[1]["nome"], "motivo" => $x[1]["texto"], "ate" => ""];
        }
    }
    return mensagem_telegram($itens, $hoje);
}

// Aviso da noite: se amanhã entra outro relógio, o que fazer hoje para ele estar pronto (os avisos dele no instante em que
// o uso de amanhã começa; nenhum: "É só usar.")
function montar_mensagem_noite($hoje)
{
    $itens = [];
    $d = plano_do_dia($hoje->modify("+1 day")->format("Y-m-d"));
    $h = plano_do_dia($hoje->format("Y-m-d"));
    if ($d && in_array("vespera", explode(",", cfg("alerta_tipos")), true) && (!$h || (int)$h["relogio_id"] !== (int)$d["relogio_id"])) {
        $preparo = preparo_do_dia(linha("SELECT * FROM relogio WHERE id = ?", [(int)$d["relogio_id"]]), $d["data"]);
        $itens[] = ["tipo" => "vespera", "id" => (int)$d["relogio_id"], "fazer" => "Preparar para amanhã",
            "motivo" => count($preparo) > 0 ? implode("\n", $preparo) : "É só usar.", "ate" => texto_ate($d["data"], "amanhã")];
    }
    return mensagem_telegram($itens, $hoje);
}

// Envia uma mensagem longa em partes: o Telegram aceita uns 4.000 caracteres, e a API de mensagem recebe o texto na URL;
// passando de 3.000, quebra entre um bloco e outro (linha em branco) e as partes vão uma atrás da outra. Devolve quantas
// partes falharam.
function enviar_em_partes($texto, $titulo)
{
    $partes = [];
    $atual = "";
    foreach (explode("\n\n", $texto) as $bloco) {
        if ($atual !== "" && strlen($atual) + 2 + strlen($bloco) > 3000) {
            $partes[] = $atual;
            $atual = "";
        }
        $atual = $atual === "" ? $bloco : $atual . "\n\n" . $bloco;
    }
    if ($atual !== "") {
        $partes[] = $atual;
    }
    // cada parte pela API de mensagem do config.php (MSG_ENDPOINT, MSG_DESTINATARIO): sem endereço, ou resposta fora de 2xx, é falha
    $falhas = 0;
    // o endereço pode ser qualquer coisa escrita no config.php (ou não estar lá); o @var diz isso às ferramentas de análise
    /** @var mixed $endpoint */
    $endpoint = defined("MSG_ENDPOINT") ? constant("MSG_ENDPOINT") : "";
    foreach ($partes as $parte) {
        $codigo = 0;
        if (is_string($endpoint) && $endpoint !== "") {
            $ch = curl_init($endpoint . "?" . http_build_query(["destinatario" => defined("MSG_DESTINATARIO") ? constant("MSG_DESTINATARIO") : "",
                "titulo" => $titulo, "mensagem" => $parte]));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => "Relogios2/1.0"]);
            curl_exec($ch);
            $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        if ($codigo < 200 || $codigo >= 300) {
            $falhas++;
        }
    }
    return $falhas;
}

// A chave do lugar de um parâmetro: "" (todos), "g:<id>" ou "r:<id>"

function escopo_chave($p)
{
    return $p["escopo_relogio_id"] !== null ? "r:" . (int)$p["escopo_relogio_id"] : ($p["escopo_no_id"] !== null ? "g:" . (int)$p["escopo_no_id"] : "");
}

// O conjunto que um lugar herda quando não tem o seu: o do ponto de cima mais perto que tenha, ou o de todos

function conjunto_herdado($chave)
{
    $cfg = criterios_config();
    $res = isset($cfg[""]) ? "" : null;
    $cadeia = [];
    if (strpos($chave, "g:") === 0) {
        $cadeia = no_cadeia((int)substr($chave, 2));
        array_pop($cadeia);
    } elseif (strpos($chave, "r:") === 0) {
        $cadeia = no_cadeia((int)valor("SELECT no_id FROM relogio WHERE id = ?", [(int)substr($chave, 2)]));
    }
    foreach ($cadeia as $g) {
        if (isset($cfg["g:" . $g])) {
            $res = "g:" . $g;
        }
    }
    return $chave === "" ? null : $res;
}

// As variáveis que um critério pode medir: os campos (menos os de texto livre) e as fórmulas. [identificador => ["nome",
// "tipo" => numero ou categoria, "valores" (categoria: as opções da lista), "max" => null (sem limite)]]
function variaveis_disponiveis()
{
    // o limite da medida (a última faixa vai até ele, ou sem limite), como no sistema antigo: o que o nome do campo ou da
    // fórmula diz em "(0 a N)" ("Sua nota para o relógio (0 a 100)"); sem isso, a medida não tem limite
    $maximo = function ($nome) {
        $res = null;
        if (preg_match("/\\(0 a ([0-9]+)\\)/u", (string)$nome, $m) === 1) {
            $res = (float)$m[1];
        }
        return $res;
    };
    $res = [];
    foreach (campos_todos() as $ident => $c) {
        if ($c["tipo"] !== "texto") {
            $res[$ident] = ["nome" => $c["nome"] . ($c["unidade"] !== "" ? " (" . $c["unidade"] . ")" : ""), "tipo" => $c["tipo"] === "lista" ? "categoria" : "numero",
                "valores" => $c["tipo"] === "lista" ? array_values(array_filter(array_map("trim", explode("\n", (string)$c["opcoes"])))) : [],
                "max" => $c["tipo"] === "lista" ? null : $maximo($c["nome"])];
        }
    }
    foreach (formulas_todas() as $ident => $versoes) {
        $res[$ident] = ["nome" => $versoes[0]["nome"] . ($versoes[0]["unidade"] !== "" ? " (" . $versoes[0]["unidade"] . ")" : ""), "tipo" => "numero", "valores" => [],
            "max" => $maximo($versoes[0]["nome"])];
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// A escala inteligente, a previsão da energia, a linha do tempo, os eventos personalizados e o Google Agenda
// ---------------------------------------------------------------------------------------------------------------------

// O que fazer antes de um dia de uso para o relógio estar pronto: os avisos dele calculados no instante em que o uso
// daquele dia começa, os atrasados ou em breve naquele momento ("Carregar: chega a 20% em 3h"). Vazio: é só usar. Usado
// pela mensagem da noite e pela véspera na agenda.
function preparo_do_dia($r, $data)
{
    $res = [];
    foreach (avisos_do_relogio($r, (int)strtotime($data . " " . cfg("uso_inicio"))) as $x) {
        if ($x["estado"] !== "ok") {
            $res[] = $x["nome"] . ": " . $x["texto"];
        }
    }
    return $res;
}

// Corta um texto em até $max caracteres sem partir acento (não depende da extensão mbstring, que pode não estar instalada)
function corta_texto($texto, $max)
{
    $res = (string)$texto;
    if (preg_match("/^.{0," . (int)$max . "}/us", $res, $m) === 1) {
        $res = $m[0];
    }
    return $res;
}

// Um lançamento que não aconteceu, para o motor calcular o futuro (a escala e a previsão): o relógio, o identificador do
// tipo, o começo e o fim (sessão), e o valor (tipo com valor), em segundos. Fica em SIMULACAO até quem simulou limpar.
// Cada relógio e tipo recebe os simulados em ordem, todos depois de agora.
function simular_lancamento($rid, $tipo, $ini, $fim, $valor, $so_uso = false)
{
    $t = lancamento_tipos()[$tipo] ?? null;
    if ($t) {
        $GLOBALS["SIMULACAO"][(int)$rid][$tipo][] = ["ini" => (int)$ini, "fim" => $t["formato"] === "sessao" ? (int)$fim : null,
            "valor" => $t["formato"] === "valor" ? (float)$valor : null, "formato" => $t["formato"], "so_uso" => $so_uso];
        $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
    }
}

// A escala inteligente (o modo ativo com escala_dias): monta o plano de $ini até o fim da escala (escala_fim; vencido ou
// vazio, um horizonte novo de escala_dias), simulando dia a dia a partir do estado real. Cada dia sai do bloco do modo que
// cobre aquele dia da semana: o relógio fixo do bloco, ou o de maior nota nos critérios entre os disponíveis do ponto da
// árvore do bloco, calculada no começo do uso daquele dia simulado (o último escolhido fica de fora, se houver outro; a
// garantia de rodízio vem na frente; sem bloco, o dia fica sem relógio). O escolhido fica os dias da fórmula dias_seguidos
// (vazio: 1), enquanto os dias seguintes aceitarem ele. Os dias escolhidos à mão (origem manual) ficam como estão.
// A simulação: cada dia do plano vira uma sessão no pulso no horário de uso. Os avisos da escala: os de "uso" e "sempre"
// do relógio escolhido, conferidos no começo do bloco e no fim de cada dia dele; os de "sempre", também nos guardados, no
// começo de cada dia. O que vence (a data prevista chega) vira o "o que fazer" do dia, e o lançamento que resolve o aviso
// é simulado terminando no começo do dia (com o valor ou as horas do aviso). O dia de hoje que já começou no pulso não
// muda. Devolve quantos dias gravou.
function gerar_escala($ini)
{
    $gravados = 0;
    $modo = linha("SELECT * FROM modo WHERE id = ?", [(int)cfg("modo_ativo")]);
    $hoje = new DateTimeImmutable("today");
    $agora = time();
    if ($ini < $hoje) {
        $ini = $hoje;
    }
    if ($ini == $hoje && $agora >= strtotime($hoje->format("Y-m-d") . " " . cfg("uso_inicio")) && plano_do_dia($hoje->format("Y-m-d")) !== null) {
        $ini = $hoje->modify("+1 day");
    }
    if ($modo && $modo["escala_dias"] !== null) {
        $fim = cfg("escala_fim") !== "" ? new DateTimeImmutable(cfg("escala_fim")) : null;
        if ($fim === null || $fim < $ini) {
            $fim = $ini->modify("+" . (max(1, (int)$modo["escala_dias"]) - 1) . " days");
            cfg_set("escala_fim", $fim->format("Y-m-d"));
        }
        $blocos = linhas("SELECT * FROM modo_bloco WHERE modo_id = ? ORDER BY ordem, id", [(int)$modo["id"]]);
        sql("DELETE FROM plano WHERE data >= ? AND origem <> 'manual'", [$ini->format("Y-m-d")]);
        $manuais = [];
        foreach (linhas("SELECT data, relogio_id FROM plano WHERE data >= ?", [$ini->format("Y-m-d")]) as $m) {
            $manuais[$m["data"]] = (int)$m["relogio_id"];
        }
        $rels = [];
        foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY id") as $r) {
            $rels[(int)$r["id"]] = $r;
        }
        // o bloco do modo que cobre um dia, e se ele aceita um relógio (o relógio no ponto da árvore do bloco, ou abaixo)
        $bloco_de = function ($d) use ($blocos) {
            $res = null;
            foreach ($blocos as $b) {
                if ($res === null && in_array($d->format("N"), explode(",", $b["dias"]), true)) {
                    $res = $b;
                }
            }
            return $res;
        };
        $aceita = function ($b, $r) {
            return $b !== null && ($b["no_id"] === null || in_array((int)$b["no_id"], no_cadeia($r["no_id"] ?? 0), true));
        };
        // começa do zero: o que foi gravado nesta requisição entra no cálculo, e nenhuma simulação de antes
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
        $GLOBALS["SIMULACAO"] = [];
        $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
        // os dias de hoje até antes de $ini que já estão no plano e ainda não têm a sessão do rodízio: a sessão é simulada
        $pulso = lancamento_tipos()["pulso"] ?? null;
        for ($d = $hoje; $d < $ini; $d = $d->modify("+1 day")) {
            $p = plano_do_dia($d->format("Y-m-d"));
            $fim_u = strtotime($d->format("Y-m-d") . " " . cfg("uso_fim"));
            if ($p && $pulso && $fim_u > $agora && valor("SELECT id FROM lancamento WHERE tipo_id = ? AND origem = 'rodizio' AND DATE(inicio) = ?",
                [(int)$pulso["id"], $d->format("Y-m-d")]) === null) {
                simular_lancamento((int)$p["relogio_id"], "pulso", max($agora, strtotime($d->format("Y-m-d") . " " . cfg("uso_inicio"))), $fim_u, null);
            }
        }
        // o último escolhido: o de ontem no plano, senão o último no pulso pelo rodízio
        $ultimo = valor("SELECT relogio_id FROM plano WHERE data = ?", [$ini->modify("-1 day")->format("Y-m-d")]);
        $ultimo = $ultimo !== null ? (int)$ultimo : (int)valor("SELECT l.relogio_id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE t.identificador = 'pulso' AND l.origem = 'rodizio' ORDER BY l.inicio DESC, l.id DESC LIMIT 1");
        $no_bloco = 0;
        $bloco_fim = $ini->modify("-1 day");
        $bloco_ini = $ini;
        $motivo_bloco = "";
        $acoes = [];
        // o ciclo: a sequência dos relógios que entraram (o plano desde a segunda-feira da semana em que a escala começa, e o
        // que a escala for escolhendo; o ciclo fecha quando todos passaram, e começa outro, pelo período inteiro da escala)
        $sequencia = array_map(function ($x) { return (int)$x["relogio_id"]; }, linhas("SELECT relogio_id FROM plano WHERE data BETWEEN ? AND ? ORDER BY data",
            [$ini->modify("monday this week")->format("Y-m-d"), $ini->modify("-1 day")->format("Y-m-d")]));
        for ($d = $ini; $d <= $fim; $d = $d->modify("+1 day")) {
            $data = $d->format("Y-m-d");
            $m_ini = strtotime($data . " " . cfg("uso_inicio"));
            $bloco = $bloco_de($d);
            $novo = 0;
            $ate = $d;
            if (isset($manuais[$data]) && isset($rels[$manuais[$data]]) && ($no_bloco !== $manuais[$data] || $d > $bloco_fim)) {
                // escolhido à mão: o bloco são os dias seguidos escolhidos à mão para ele
                $novo = $manuais[$data];
                while (($manuais[$ate->modify("+1 day")->format("Y-m-d")] ?? 0) === $novo && $ate < $fim) {
                    $ate = $ate->modify("+1 day");
                }
                $motivo_bloco = "Escolhido à mão.";
            } elseif (!isset($manuais[$data]) && ($no_bloco === 0 || $d > $bloco_fim) && $bloco !== null) {
                // o motivo: a frase que fica no plano dizendo por que este relógio saiu (a escolha em si não muda por ela)
                $motivo_bloco = "";
                if ($bloco["relogio_id"] !== null && isset($rels[(int)$bloco["relogio_id"]])) {
                    $novo = (int)$bloco["relogio_id"];
                    $motivo_bloco = "Relógio fixo do bloco " . $bloco["nome"] . ".";
                } else {
                    $fora = [];
                    $cands = [];
                    $nunca = [];
                    foreach ($rels as $cid => $r) {
                        if ($aceita($bloco, $r)) {
                            $ctx = ["r" => $r, "momento" => $m_ini, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($cid)];
                            $dsu = variavel_valor("dias_sem_uso", $ctx);
                            $cands[$cid] = is_numeric($dsu) ? (float)$dsu : 9999.0;
                            $nunca[$cid] = nunca_no_pulso($ctx);
                        }
                    }
                    if (count($cands) > 1 && isset($cands[$ultimo])) {
                        $fora[] = "o de antes (" . $rels[$ultimo]["nome"] . ") ficou de fora";
                        unset($cands[$ultimo]);
                    }
                    // o ciclo (se o modo usa): os que já passaram no ciclo atual ficam de fora
                    if ((int)($modo["ciclo"] ?? 0) === 1 && count($cands) > 1) {
                        $restam = array_diff_key($cands, array_flip(ciclo_atual($sequencia, array_keys($cands))));
                        if (count($restam) > 0) {
                            if (count($restam) < count($cands)) {
                                $fora[] = "pelo ciclo, " . (count($cands) - count($restam)) . " que já passaram ficaram de fora";
                            }
                            $cands = $restam;
                        }
                    }
                    $todos = count($cands);
                    // garantia de rodízio: quem chegou ao limite de dias sem uso passa na frente, o mais tempo parado primeiro
                    $max = (int)cfg("max_sem_uso");
                    $garantia = "";
                    if ($max > 0 && count($cands) > 0 && max($cands) >= $max) {
                        $mais = max($cands);
                        $cands = array_filter($cands, function ($x) use ($mais) { return $x == $mais; });
                        $garantia = "no limite de " . $max . " dias sem uso";
                    }
                    // a maior nota no dia simulado; um valor minúsculo só desempata notas iguais
                    $melhor = -1.0;
                    $nota_dele = 0.0;
                    foreach (array_keys($cands) as $cid) {
                        $nota_sem = nota_do_relogio($rels[$cid], $m_ini)["nota"];
                        $nota = $nota_sem + sorteio(0, 1000) / 100000;
                        if ($nota > $melhor) {
                            $melhor = $nota;
                            $novo = $cid;
                            $nota_dele = $nota_sem;
                        }
                    }
                    if ($novo > 0) {
                        $motivo_bloco = ($garantia !== ""
                            ? "Garantia de rodízio: " . motivo_parado($cands[$novo], $nunca[$novo]) . ", " . $garantia . (count($cands) > 1 ? "; entre os " . count($cands) . " empatados no tempo parado, a maior nota (" . motivo_nota($nota_dele) . ")" : "") . "."
                            : "A maior nota do dia (" . motivo_nota($nota_dele) . "), " . ($todos === 1 ? "único candidato" : "entre " . $todos . " candidatos") . ".")
                            . (count($fora) > 0 ? " " . ucfirst(implode("; ", $fora)) . "." : "");
                    }
                }
                if ($novo > 0) {
                    // os dias seguidos: dias_seguidos (vazio: 1), enquanto o dia seguinte for do mesmo jeito (sem escolha à mão, com
                    // bloco que aceita o relógio e sem relógio fixo de outro)
                    $ctx = ["r" => $rels[$novo], "momento" => $m_ini, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($novo)];
                    $tam = variavel_valor("dias_seguidos", $ctx);
                    $tam = is_numeric($tam) ? max(1, min(366, (int)floor((float)$tam))) : 1;
                    $segue = true;
                    for ($n = 1; $segue && $n < $tam; $n++) {
                        $prox = $ate->modify("+1 day");
                        $b = $bloco_de($prox);
                        $segue = $prox <= $fim && !isset($manuais[$prox->format("Y-m-d")]) && $aceita($b, $rels[$novo])
                            && ($b["relogio_id"] === null || (int)$b["relogio_id"] === $novo);
                        if ($segue) {
                            $ate = $prox;
                        }
                    }
                }
            }
            if ($novo > 0) {
                // um bloco novo: as sessões no pulso de todos os dias dele, e os avisos conferidos no começo e no fim de cada dia
                $no_bloco = $novo;
                $bloco_fim = $ate;
                $bloco_ini = $d;
                $ultimo = $novo;
                $sequencia[] = $novo;
                $pontos = [$m_ini];
                for ($k = $d; $k <= $ate; $k = $k->modify("+1 day")) {
                    simular_lancamento($novo, "pulso", strtotime($k->format("Y-m-d") . " " . cfg("uso_inicio")), strtotime($k->format("Y-m-d") . " " . cfg("uso_fim")), null);
                    $pontos[] = strtotime($k->format("Y-m-d") . " " . cfg("uso_fim"));
                }
                $vencidos = [];
                foreach ($pontos as $ponto) {
                    foreach (avisos_do_relogio($rels[$novo], $ponto) as $a) {
                        if ($a["escala"] !== "nao" && $a["falta_dias"] <= 0 && !isset($vencidos[$a["identificador"]])) {
                            $vencidos[$a["identificador"]] = $a;
                        }
                    }
                }
                foreach ($vencidos as $a) {
                    $acoes[$data][] = [$novo, $a];
                }
            }
            // os avisos de "sempre" nos outros relógios (o do bloco já foi conferido no bloco inteiro)
            foreach ($rels as $cid => $r) {
                if ($cid !== $no_bloco || $d > $bloco_fim) {
                    foreach (avisos_do_relogio($r, $m_ini) as $a) {
                        if ($a["escala"] === "sempre" && $a["falta_dias"] <= 0) {
                            $acoes[$data][] = [$cid, $a];
                        }
                    }
                }
            }
            // o que resolve cada aviso vencido é simulado terminando no começo do dia; o texto vai para o dia
            $textos = [];
            foreach ($acoes[$data] ?? [] as $x) {
                $a = $x[1];
                // sem as horas (sessão) ou sem o valor (leitura) no aviso, a ação é anotada mas não simulada
                $t = $a["resolve"] !== null ? (lancamento_tipos()[$a["resolve"]] ?? null) : null;
                $horas = $t && $t["formato"] === "sessao" ? (float)($a["simula_horas"] ?? 0) : 0;
                if ($t && ($t["formato"] === "instantaneo" || $horas > 0 || ($t["formato"] === "valor" && $a["simula_valor"] !== null))) {
                    simular_lancamento($x[0], $a["resolve"], max($agora, (int)round($m_ini - $horas * 3600)), $m_ini, $a["simula_valor"]);
                }
                $textos[] = $a["nome"] . ": " . $rels[$x[0]]["nome"] . ($horas > 0 ? " (" . str_replace(".", ",", (string)(0 + $horas)) . " h)" : "");
            }
            if ($no_bloco > 0 && $d <= $bloco_fim) {
                // o motivo do dia: no primeiro dia do bloco, o da escolha (e quantos dias ele fica); nos seguintes, que ele segue
                $dias_bloco = (int)$bloco_ini->diff($bloco_fim)->days + 1;
                $dia_bloco = (int)$bloco_ini->diff($d)->days + 1;
                $motivo = isset($manuais[$data]) ? "Escolhido à mão." : ($dia_bloco === 1
                    ? $motivo_bloco . ($dias_bloco > 1 ? " Fica " . $dias_bloco . " dias seguidos." : "")
                    : "Segue no pulso: dia " . $dia_bloco . " de " . $dias_bloco . " seguidos.");
                sql("REPLACE INTO plano (data, relogio_id, bloco_id, origem, acao, motivo, criado) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$data, $no_bloco, isset($manuais[$data]) || $bloco === null ? null : (int)$bloco["id"], isset($manuais[$data]) ? "manual" : "sorteio",
                    count($textos) > 0 ? corta_texto(implode("; ", $textos), 500) : null, $motivo !== "" ? corta_texto($motivo, 300) : null]);
                $gravados++;
            }
        }
        $GLOBALS["SIMULACAO"] = [];
        $GLOBALS["SIMULACAO_VERSAO"]++;
        cfg_set("escala_gerada", date("Y-m-d"));
    }
    return $gravados;
}

// A previsão da energia de um relógio com leitura (um tipo de lançamento com valor, como a leitura de carga do
// smartwatch), pela fórmula energia (em %): quanto dura se usar a partir de hoje (dias_de_carga), quando chega ao limite da
// Configuração (previsao_limite, 20%) parado, com quanto entra no próximo rodízio e quanto precisa para os dias seguidos
// dele (simulado com carga cheia na entrada), e a confiança da conta (a idade da leitura e os campos da conta).
// Devolve as frases (as da página) e os mesmos números. "aplica": o relógio tem leitura com valor (senão, nada).
function previsao_energia($r, $agora)
{
    $hoje = new DateTimeImmutable(date("Y-m-d", $agora));
    $res = ["aplica" => false, "linhas" => [], "energia" => null, "dura_dias" => null, "dura_ate" => null, "limite" => (float)(cfg("previsao_limite") !== "" ? cfg("previsao_limite") : 20),
        "chega_limite_em" => null, "proxima_entrada" => null, "carga_na_entrada" => null, "precisa" => null, "carregar_antes" => null,
        "leitura" => null, "leitura_em" => null, "confianca" => null, "conta" => [], "gasto" => null];
    $tipos_valor = [];
    foreach (lancamento_tipos_do_relogio($r) as $ident => $t) {
        if ($t["formato"] === "valor") {
            $tipos_valor[] = $ident;
        }
    }
    $energia_f = isset(formulas_todas()["energia"]) ? mais_perto(formulas_todas()["energia"], $r) : null;
    if (count($tipos_valor) > 0 && $energia_f !== null) {
        $res["aplica"] = true;
        $leitura = linha("SELECT l.inicio, l.valor, t.identificador, t.unidade FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE l.relogio_id = ? AND t.formato = 'valor' AND l.inicio <= ? ORDER BY l.inicio DESC, l.id DESC LIMIT 1", [(int)$r["id"], date("Y-m-d H:i:s", $agora)]);
        $valores = valores_do_relogio((int)$r["id"]);
        $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
        $energia = variavel_valor("energia", $ctx);
        if ($leitura && is_numeric($energia)) {
            $energia = (float)$energia;
            $res["energia"] = round($energia, 1);
            $res["leitura"] = (float)$leitura["valor"];
            $res["leitura_em"] = $leitura["inicio"];
            // os campos que a conta da energia usou, informados no relógio, no padrão do cadastro ou vazios
            $campos = campos_do_relogio($r);
            $vazio = false;
            foreach ($ctx["rastro"] as $nome => $v) {
                if (isset($campos[$nome])) {
                    $origem = ($valores[$nome] ?? "") !== "" ? "informado" : ($v === null ? "vazio" : "padrão");
                    $vazio = $vazio || $origem === "vazio";
                    $res["conta"][] = ["campo" => $nome, "nome" => $campos[$nome]["nome"], "valor" => $v, "unidade" => $campos[$nome]["unidade"], "origem" => $origem];
                }
            }
            // o gasto medido pelas leituras (gasto_medido, na janela da Configuração): com o de uso medido, o campo vazio do
            // cadastro não pesa na confiança (a medição vale no lugar dele)
            $janela = max(1, (int)cfg("medicao_janela_dias"));
            $medicoes = linhas("SELECT medida, taxa, peso_horas, fim, horas_pulso, horas_guardado, de_valor, ate_valor FROM medicao
                WHERE relogio_id = ? AND usada = 1 ORDER BY fim, id", [(int)$r["id"]]);
            $g = gasto_medido($medicoes, $agora);
            $como = $g["n"] === 0 ? " (a última medição; nenhuma nos últimos " . $janela . " dias)"
                : " (" . ($g["conjunta"] ? "conta dos dois gastos juntos, com " : "média de ") . $g["n"] . ($g["n"] === 1 ? " medição" : " medições")
                    . " dos últimos " . $janela . " dias)";
            foreach (["uso" => "Gasto em uso medido", "repouso" => "Gasto fora do pulso medido"] as $medida => $nome_m) {
                if ($g[$medida] !== null) {
                    $res["conta"][] = ["campo" => "MEDIDO(\"" . $medida . "\")", "nome" => $nome_m . $como, "valor" => round($g[$medida], 3),
                        "unidade" => $medida === "uso" ? "% por dia de uso" : "% por dia fora do pulso", "origem" => "medido"];
                    if ($medida === "uso") {
                        $vazio = false;
                    }
                }
            }
            // os dois gastos lado a lado (as fórmulas taxa_uso e taxa_repouso): o do cadastro (a conta sem as medições), o medido
            // nesta janela e na anterior (para ver o relógio envelhecer) e o que vale na conta
            $agora_j = gasto_medido_desde($medicoes, $agora, $agora - $janela * 86400);
            $antes_j = gasto_medido_desde($medicoes, $agora - $janela * 86400, $agora - 2 * $janela * 86400);
            $gasto = ["janela_dias" => $janela, "medicoes" => $agora_j["n"], "conjunta" => $agora_j["conjunta"], "medicoes_antes" => $antes_j["n"]];
            $tem = false;
            foreach (["uso" => "taxa_uso", "repouso" => "taxa_repouso"] as $medida => $ident) {
                $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
                $vale = variavel_valor($ident, $ctx);
                $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores, "sem_medido" => true];
                $cadastro = variavel_valor($ident, $ctx);
                $tem = $tem || is_numeric($vale);
                $gasto[$medida] = ["cadastro" => is_numeric($cadastro) ? round((float)$cadastro, 3) : null, "medido" => $g[$medida],
                    "antes" => $antes_j[$medida], "vale" => is_numeric($vale) ? round((float)$vale, 3) : null];
            }
            $res["gasto"] = $tem ? $gasto : null;
            $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
            $dura = variavel_valor("dias_de_carga", $ctx);
            if (is_numeric($dura)) {
                $res["dura_dias"] = round((float)$dura, 1);
                $res["dura_ate"] = $hoje->modify("+" . max(0, (int)floor((float)$dura)) . " days")->format("Y-m-d");
                $res["linhas"][] = "Se usar a partir de hoje: dura ~" . str_replace(".", ",", (string)$res["dura_dias"]) . ($res["dura_dias"] == 1 ? " dia" : " dias")
                    . ", até " . date("d/m", strtotime($res["dura_ate"])) . ".";
            }
            // parado: o primeiro dia em que a energia fica no limite ou abaixo (a energia guardada só cai; procura por metades)
            if ($energia <= $res["limite"]) {
                $res["linhas"][] = "Já está abaixo de " . str_replace(".", ",", (string)(0 + $res["limite"])) . "%. Guardado assim, a bateria se desgasta.";
            } else {
                $baixo = 0;
                $alto = 366;
                $ctx = ["r" => $r, "momento" => $agora + $alto * 86400, "rastro" => [], "pilha" => [], "valores" => $valores];
                $e_alto = variavel_valor("energia", $ctx);
                if (is_numeric($e_alto) && (float)$e_alto <= $res["limite"]) {
                    while ($alto - $baixo > 1) {
                        $meio = intdiv($baixo + $alto, 2);
                        $ctx = ["r" => $r, "momento" => $agora + $meio * 86400, "rastro" => [], "pilha" => [], "valores" => $valores];
                        $e = variavel_valor("energia", $ctx);
                        if (is_numeric($e) && (float)$e <= $res["limite"]) {
                            $alto = $meio;
                        } else {
                            $baixo = $meio;
                        }
                    }
                    $chega = $hoje->modify("+" . $alto . " days");
                    $res["chega_limite_em"] = $chega->format("Y-m-d");
                    $res["linhas"][] = "Parado, chega a " . str_replace(".", ",", (string)(0 + $res["limite"])) . "% em " . $chega->format($chega->format("Y") === $hoje->format("Y") ? "d/m" : "d/m/Y") . ".";
                } else {
                    $res["linhas"][] = "Parado, fica acima de " . str_replace(".", ",", (string)(0 + $res["limite"])) . "% por mais de um ano.";
                }
            }
            // a próxima entrada no rodízio (o primeiro dia dele no plano a partir de hoje, se não for hoje) e os dias seguidos dele
            $proxima = valor("SELECT data FROM plano WHERE relogio_id = ? AND data >= ? ORDER BY data LIMIT 1", [(int)$r["id"], $hoje->format("Y-m-d")]);
            if ($proxima !== null && $proxima !== $hoje->format("Y-m-d")) {
                $entrada = strtotime($proxima . " " . cfg("uso_inicio"));
                $ctx = ["r" => $r, "momento" => $entrada, "rastro" => [], "pilha" => [], "valores" => $valores];
                $na_entrada = variavel_valor("energia", $ctx);
                // quanto os dias seguidos gastam: simulados com a carga cheia na entrada; esgotou antes do fim: 100%
                $ultimo_dia = $proxima;
                $prox = date("Y-m-d", strtotime($proxima . " +1 day"));
                for ($n = 0; (int)valor("SELECT relogio_id FROM plano WHERE data = ?", [$prox]) === (int)$r["id"] && $n < 366; $n++) {
                    $ultimo_dia = $prox;
                    $prox = date("Y-m-d", strtotime($prox . " +1 day"));
                }
                $GLOBALS["SIMULACAO"] = [];
                simular_lancamento((int)$r["id"], $leitura["identificador"], $entrada, null, 100);
                for ($k = $proxima; $k <= $ultimo_dia; $k = date("Y-m-d", strtotime($k . " +1 day"))) {
                    simular_lancamento((int)$r["id"], "pulso", strtotime($k . " " . cfg("uso_inicio")), strtotime($k . " " . cfg("uso_fim")), null);
                }
                $ctx = ["r" => $r, "momento" => strtotime($ultimo_dia . " " . cfg("uso_fim")), "rastro" => [], "pilha" => [], "valores" => $valores];
                $no_fim = variavel_valor("energia", $ctx);
                $GLOBALS["SIMULACAO"] = [];
                $GLOBALS["SIMULACAO_VERSAO"]++;
                if (is_numeric($na_entrada) && is_numeric($no_fim)) {
                    $res["proxima_entrada"] = $proxima;
                    $res["carga_na_entrada"] = (int)round((float)$na_entrada);
                    $res["precisa"] = (float)$no_fim <= 0 ? 100 : (int)ceil(100 - (float)$no_fim);
                    $res["carregar_antes"] = (float)$na_entrada < $res["precisa"];
                    $res["linhas"][] = "Entra no rodízio em " . date("d/m", strtotime($proxima)) . " com ~" . $res["carga_na_entrada"] . "%; precisa de "
                        . $res["precisa"] . "%" . ($res["carregar_antes"] ? ". Carregar antes." : ". Dá conta.");
                }
            }
            // a confiança: a leitura envelhece; alta só com leitura de até 2 dias e nenhum campo da conta vazio
            $idade = ($agora - strtotime($leitura["inicio"])) / 86400;
            $res["confianca"] = $idade <= 2 && !$vazio ? "alta" : ($idade <= 7 ? "média" : "baixa");
            // hoje, ontem, ou há quantos dias de calendário
            $dias_leitura = (int)(new DateTimeImmutable(substr($leitura["inicio"], 0, 10)))->diff($hoje)->days;
            $quando = $dias_leitura === 0 ? "hoje" : ($dias_leitura === 1 ? "ontem" : "há " . $dias_leitura . " dias");
            $res["linhas"][] = "Confiança " . $res["confianca"] . ": leitura de " . str_replace(".", ",", (string)(0 + $res["leitura"])) . $leitura["unidade"] . " feita " . $quando
                . (count($res["conta"]) > 0 ? ". Conta: " . implode("; ", array_map(function ($c) {
                    return $c["nome"] . ": " . ($c["valor"] === null ? "vazio" : str_replace(".", ",", (string)(0 + round((float)$c["valor"], 2))) . ($c["unidade"] !== "" ? " " . $c["unidade"] : "")
                        . " (" . $c["origem"] . ")");
                }, $res["conta"])) : "") . ".";
        }
    }
    return $res;
}

// Quando a energia de um relógio (a fórmula energia, em %) chega a zero, entre $agora e $ate: com os lançamentos gravados e
// mais as sessões simuladas ($sessoes: [[tipo, início, fim], ...], em ordem, todas depois de agora). Entre um começo e um fim
// de sessão a energia só sobe ou só desce, então a busca é por trechos: acha o primeiro trecho em que ela chega a zero e
// procura o instante por metades, até 1 minuto. Devolve o instante (em segundos) ou null: sem energia calculável, ou ela não
// chega a zero até $ate. Usado pelas autonomias (parado, no pulso direto, seguindo o plano).
function quando_acaba($r, $agora, $sessoes, $ate)
{
    $id = (int)$r["id"];
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"] = ($GLOBALS["SIMULACAO_VERSAO"] ?? 0) + 1;
    // os limites dos trechos: agora, o começo e o fim de cada sessão simulada e de cada gravada que ainda não acabou, e $ate
    $marcos = [$agora, $ate];
    foreach ($sessoes as $x) {
        simular_lancamento($id, $x[0], $x[1], $x[2], null);
        $marcos[] = (int)$x[1];
        $marcos[] = (int)$x[2];
    }
    foreach (linhas("SELECT l.inicio, l.fim FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.formato = 'sessao' AND l.fim > ?",
        [$id, date("Y-m-d H:i:s", $agora)]) as $l) {
        $marcos[] = (int)strtotime($l["inicio"]);
        $marcos[] = (int)strtotime($l["fim"]);
    }
    $marcos = array_values(array_unique(array_filter($marcos, function ($x) use ($agora, $ate) { return $x >= $agora && $x <= $ate; })));
    sort($marcos);
    $valores = valores_do_relogio($id);
    $energia_em = function ($t) use ($r, $valores) {
        $c = ["r" => $r, "momento" => (int)$t, "rastro" => [], "pilha" => [], "valores" => $valores];
        $e = variavel_valor("energia", $c);
        return is_numeric($e) ? (float)$e : null;
    };
    $res = null;
    $e0 = isset(formulas_todas()["energia"]) ? $energia_em($agora) : null;
    if ($e0 !== null && $e0 <= 0.0001) {
        $res = $agora;
    } elseif ($e0 !== null) {
        for ($i = 0; $res === null && $i < count($marcos) - 1; $i++) {
            $a = $marcos[$i];
            $b = $marcos[$i + 1];
            $eb = $energia_em($b);
            if ($eb !== null && $eb <= 0.0001) {
                while ($b - $a > 60) {
                    $meio = intdiv($a + $b, 2);
                    $em = $energia_em($meio);
                    if ($em !== null && $em <= 0.0001) {
                        $b = $meio;
                    } else {
                        $a = $meio;
                    }
                }
                $res = $b;
            }
        }
    }
    $GLOBALS["SIMULACAO"] = [];
    $GLOBALS["SIMULACAO_VERSAO"]++;
    return $res;
}

// As autonomias de um relógio, em segundos (inteiros), para a API: a prevista (carga cheia, pelo cadastro: a fórmula
// autonomia_prevista), a atual (carga cheia, pela conta do sistema, com o gasto medido quando há: autonomia_atual), quanto
// ainda dura a partir de agora no pulso direto, guardado, e seguindo o plano (a estimada), e quando acaba pela estimada. Os
// que não dá para calcular saem null, com o motivo. Horizonte: 10 anos (a pilha dura anos). Usado pela API (a resposta
// completa, a ficha e o recurso=autonomia).
function autonomia_do_relogio($r, $agora)
{
    $id = (int)$r["id"];
    $ate = $agora + 3650 * 86400;
    $c = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => valores_do_relogio($id)];
    $formula = function ($ident) use ($r, $agora, $c) {
        $x = $c;
        $v = isset(formulas_todas()[$ident]) && mais_perto(formulas_todas()[$ident], $r) !== null ? variavel_valor($ident, $x) : null;
        return is_numeric($v) ? (int)round((float)$v) : null;
    };
    $energia = $formula("energia");
    $em_uso = valor("SELECT l.id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso'
        AND l.inicio <= ? AND (l.fim IS NULL OR l.fim > ?) LIMIT 1", [$id, date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora)]) !== null;
    // as sessões no pulso já lançadas que ainda não acabaram (a do rodízio de hoje vai até o fim do horário de uso): o uso
    // simulado começa depois delas, para não contar a mesma hora duas vezes
    $fim_pulso = max($agora, (int)strtotime((string)valor("SELECT MAX(l.fim) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.relogio_id = ? AND t.identificador = 'pulso' AND l.fim > ?", [$id, date("Y-m-d H:i:s", $agora)])));
    $plano = [];
    foreach (linhas("SELECT data FROM plano WHERE relogio_id = ? AND data >= ? ORDER BY data", [$id, date("Y-m-d", $agora)]) as $p) {
        $ini = max($fim_pulso, (int)strtotime($p["data"] . " " . cfg("uso_inicio")));
        $fim = (int)strtotime($p["data"] . " " . cfg("uso_fim"));
        if ($fim > $ini) {
            $plano[] = ["pulso", $ini, $fim];
        }
    }
    $acaba_guardado = $energia === null ? null : quando_acaba($r, $agora, [], $ate);
    $acaba_em_uso = $energia === null ? null : quando_acaba($r, $agora, $fim_pulso < $ate ? [["pulso", $fim_pulso, $ate]] : [], $ate);
    $acaba = $energia === null ? null : quando_acaba($r, $agora, $plano, $ate);
    $res = ["calculado_em_unixtimestamp" => $agora, "em_uso" => $em_uso, "energia" => $energia,
        "autonomia_prevista" => $formula("autonomia_prevista"), "autonomia_atual" => $formula("autonomia_atual"),
        "autonomia_estimada" => $acaba === null ? null : $acaba - $agora,
        "restante_em_uso" => $acaba_em_uso === null ? null : $acaba_em_uso - $agora, "restante_guardado" => $acaba_guardado === null ? null : $acaba_guardado - $agora,
        "acaba_em_unixtimestamp" => $acaba, "acaba_em_segundos" => $acaba === null ? null : $acaba - $agora, "acaba_em_datacomtz" => $acaba === null ? null : date("c", $acaba),
        "motivos" => []];
    // por que cada um que ficou vazio ficou vazio
    foreach (["autonomia_prevista", "autonomia_atual"] as $k) {
        if ($res[$k] === null) {
            $res["motivos"][$k] = isset(formulas_todas()[$k]) && mais_perto(formulas_todas()[$k], $r) !== null
                ? "falta no cadastro do relógio um dado que a fórmula " . $k . " usa" : "não há fórmula " . $k . " para o grupo deste relógio";
        }
    }
    foreach (["restante_em_uso" => "no pulso direto", "restante_guardado" => "guardado", "autonomia_estimada" => "seguindo o plano"] as $k => $como) {
        if ($res[$k] === null) {
            $res["motivos"][$k] = $energia === null ? "sem dados para calcular a energia agora (ex.: smartwatch sem nenhuma leitura, solar sem nenhum sol registrado)"
                : "a energia não chega a zero em até 10 anos " . $como . ($k === "restante_em_uso" ? " (no pulso, o relógio pode se recarregar)" : "");
        }
    }
    foreach (["acaba_em_unixtimestamp", "acaba_em_segundos", "acaba_em_datacomtz"] as $k) {
        if ($res[$k] === null) {
            $res["motivos"][$k] = $res["motivos"]["autonomia_estimada"];
        }
    }
    // os motivos sempre como objeto na resposta (vazio: {}), para quem lê saber que são chaves
    $res["motivos"] = (object)$res["motivos"];
    return $res;
}

// Os estados da linha do tempo: em uso pelo rodízio, no pulso fora do rodízio, cada tipo de sessão (winder, sol...), em
// repouso (o resto) e as marcações instantâneas. [estado => texto]
function estados_da_linha()
{
    $res = ["rodizio" => "em uso", "pulso" => "fora do rodízio"];
    foreach (lancamento_tipos() as $ident => $t) {
        if ($t["formato"] === "sessao" && $ident !== "pulso") {
            // minúsculas sem depender do mbstring ("No winder" → "no winder")
            $res[$ident] = function_exists("mb_strtolower") ? mb_strtolower((string)$t["nome"], "UTF-8") : strtolower((string)$t["nome"]);
        }
    }
    return array_merge($res, ["repouso" => "em repouso", "marca" => "marcação"]);
}

// A linha do tempo de um relógio, do primeiro lançamento até agora: trechos contínuos, cada um com o seu estado (em uso
// pelo rodízio, no pulso fora do rodízio, em cada tipo de sessão, ou em repouso), e no meio as marcações instantâneas
// (corda, leitura de carga, troca de pilha, marcações antigas...). Onde duas sessões se sobrepõem, vale a de tipo que vem
// antes na ordem dos tipos de lançamento (o pulso antes do winder, antes do sol); sessão esquecida aberta vale até o
// "fecha às" do tipo. Filtrada: $de e $ate em segundos (0 e PHP_INT_MAX: sem limite), entra a linha que cruza o período;
// $estados: os estados pedidos (vazio: todos). Devolve as linhas (mais recente primeiro: inicio, fim, estado, texto,
// tipo, em_andamento), o tempo em cada estado dentro do período (o trecho que atravessa uma borda conta só a parte de
// dentro; o resumo ignora o filtro de estado) e a contagem das marcações por tipo. A página do histórico e a API usam esta.
function linha_do_tempo($id, $de, $ate, $estados)
{
    $agora = time();
    $textos = estados_da_linha();
    $res = ["linhas" => [], "tempo" => [], "marcas" => []];
    foreach ($textos as $k => $t) {
        if ($k !== "marca") {
            $res["tempo"][$k] = 0;
        }
    }
    $sessoes = [];
    $marcas = [];
    $t0 = 0;
    foreach (linhas("SELECT l.*, t.identificador, t.nome, t.formato, t.fecha_as, t.unidade, t.ordem FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
        WHERE l.relogio_id = ? AND l.inicio <= ? ORDER BY l.inicio, l.id", [(int)$id, date("Y-m-d H:i:s", $agora)]) as $l) {
        $ini = strtotime($l["inicio"]);
        $t0 = $t0 === 0 ? $ini : min($t0, $ini);
        if ($l["formato"] === "sessao") {
            $fim = $l["fim"] !== null ? strtotime($l["fim"]) : ($l["fecha_as"] !== null ? max($ini, (int)strtotime(substr($l["inicio"], 0, 10) . " " . $l["fecha_as"])) : $agora);
            $fim = min($fim, $agora);
            if ($fim > $ini) {
                $estado = $l["identificador"] === "pulso" ? ($l["origem"] === "rodizio" ? "rodizio" : "pulso") : $l["identificador"];
                // a prioridade: a ordem do tipo; no pulso, o rodízio antes do fora do rodízio
                $sessoes[] = [$ini, $fim, $estado, (int)$l["ordem"] * 2 + ($estado === "pulso" ? 1 : 0)];
            }
        } else {
            $marcas[] = ["inicio" => $ini, "fim" => null, "estado" => "marca", "tipo" => $l["identificador"], "em_andamento" => false,
                "texto" => $l["nome"] . ($l["valor"] !== null ? " " . str_replace(".", ",", (string)(0 + (float)$l["valor"])) . $l["unidade"] : ""), "nome" => $l["nome"]];
        }
    }
    $trechos = [];
    if ($t0 > 0) {
        $marcos = [$t0, $agora];
        foreach ($sessoes as $x) {
            foreach ([$x[0], $x[1]] as $t) {
                if ($t > $t0 && $t < $agora) {
                    $marcos[] = $t;
                }
            }
        }
        $marcos = array_values(array_unique($marcos));
        sort($marcos);
        usort($sessoes, function ($x, $y) { return $x[0] <=> $y[0]; });
        // percorrido uma vez só: as sessões entram nas ativas quando começam e saem quando acabam
        $proxima = 0;
        $ativas = [];
        for ($i = 0; $i < count($marcos) - 1; $i++) {
            $a = $marcos[$i];
            $b = $marcos[$i + 1];
            $meio = $a + ($b - $a) / 2;
            while ($proxima < count($sessoes) && $sessoes[$proxima][0] <= $meio) {
                $ativas[] = $sessoes[$proxima];
                $proxima++;
            }
            $estado = "repouso";
            $melhor = PHP_INT_MAX;
            $ficam = [];
            foreach ($ativas as $x) {
                if ($meio < $x[1]) {
                    $ficam[] = $x;
                    if ($x[3] < $melhor) {
                        $melhor = $x[3];
                        $estado = $x[2];
                    }
                }
            }
            $ativas = $ficam;
            $n = count($trechos);
            if ($n > 0 && $trechos[$n - 1]["estado"] === $estado) {
                $trechos[$n - 1]["fim"] = $b;
            } else {
                $trechos[] = ["inicio" => $a, "fim" => $b, "estado" => $estado];
            }
        }
    }
    $linhas = [];
    foreach ($trechos as $i => $x) {
        $linhas[] = ["inicio" => $x["inicio"], "fim" => $x["fim"], "estado" => $x["estado"], "tipo" => $x["estado"] === "rodizio" ? "pulso" : ($x["estado"] === "repouso" ? null : $x["estado"]),
            "texto" => $textos[$x["estado"]] ?? $x["estado"], "em_andamento" => $i === count($trechos) - 1];
    }
    foreach ($marcas as $m) {
        $linhas[] = $m;
    }
    usort($linhas, function ($x, $y) { return $y["inicio"] <=> $x["inicio"]; });
    foreach ($linhas as $l) {
        $fim_l = $l["fim"] === null ? $l["inicio"] : $l["fim"];
        if ($fim_l >= $de && $l["inicio"] < $ate) {
            if ($l["estado"] === "marca") {
                $res["marcas"][$l["nome"]] = ($res["marcas"][$l["nome"]] ?? 0) + 1;
            } else {
                $res["tempo"][$l["estado"]] = ($res["tempo"][$l["estado"]] ?? 0) + max(0, min($fim_l, $ate) - max($l["inicio"], $de));
            }
            if (count($estados) === 0 || in_array($l["estado"], $estados, true)) {
                unset($l["nome"]);
                $res["linhas"][] = $l;
            }
        }
    }
    return $res;
}

// "todo dia 20:00", "seg, qua 20:00", "dia 1 do mês, 09:00", "a cada 90 dias, 09:00, desde 01/09/2026", "25/09/2026 14:00"
function descricao_repeticao($ev)
{
    $dias = ["", "seg", "ter", "qua", "qui", "sex", "sáb", "dom"];
    $hora = substr((string)$ev["hora"], 0, 5);
    $txt = $hora;
    if ($ev["repeticao"] === "uma") {
        $txt = date("d/m/Y", strtotime((string)$ev["data_inicio"])) . " " . $hora;
    } elseif ($ev["repeticao"] === "diaria") {
        $txt = "todo dia " . $hora;
    } elseif ($ev["repeticao"] === "semanal") {
        $nomes = [];
        foreach (explode(",", (string)$ev["dias_semana"]) as $n) {
            if ((int)$n >= 1 && (int)$n <= 7) {
                $nomes[] = $dias[(int)$n];
            }
        }
        $txt = implode(", ", $nomes) . " " . $hora;
    } elseif ($ev["repeticao"] === "mensal") {
        $txt = "dia " . (int)$ev["dia_mes"] . " do mês, " . $hora;
    } elseif ($ev["repeticao"] === "intervalo") {
        $txt = "a cada " . (int)$ev["intervalo_dias"] . " dias, " . $hora . ($ev["data_inicio"] ? ", desde " . date("d/m/Y", strtotime($ev["data_inicio"])) : "");
    }
    if ((int)$ev["ativo"] !== 1) {
        $txt .= " · pausado";
    }
    return $txt;
}

// As ocorrências de um evento personalizado entre dois instantes (segundos), em ordem
function ocorrencias($ev, $de, $ate)
{
    $lista = [];
    $hora = substr((string)$ev["hora"], 0, 5);
    $inicio = $ev["data_inicio"] ? $ev["data_inicio"] : "0000-00-00";
    $fim = date("Y-m-d", (int)$ate);
    for ($d = new DateTimeImmutable(date("Y-m-d", (int)$de)); $d->format("Y-m-d") <= $fim; $d = $d->modify("+1 day")) {
        $dia = $d->format("Y-m-d");
        $vale = false;
        if ($dia >= $inicio) {
            if ($ev["repeticao"] === "uma") {
                $vale = $dia === $ev["data_inicio"];
            } elseif ($ev["repeticao"] === "diaria") {
                $vale = true;
            } elseif ($ev["repeticao"] === "semanal") {
                $vale = in_array($d->format("N"), explode(",", (string)$ev["dias_semana"]), true);
            } elseif ($ev["repeticao"] === "mensal") {
                // mês mais curto que o dia pedido: vale o último dia do mês
                $vale = (int)$d->format("j") === min((int)$ev["dia_mes"], (int)$d->format("t"));
            } elseif ($ev["repeticao"] === "intervalo" && $ev["data_inicio"]) {
                $vale = (int)(new DateTimeImmutable($ev["data_inicio"]))->diff($d)->days % max(1, (int)$ev["intervalo_dias"]) === 0;
            }
        }
        if ($vale) {
            $ts = strtotime($dia . " " . $hora);
            if ($ts >= $de && $ts <= $ate) {
                $lista[] = $ts;
            }
        }
    }
    return $lista;
}

// O que tem de estar na agenda, de hoje até o fim da janela (agenda_antecedencia dias), só os tipos marcados para a agenda
// (agenda_tipos): os avisos dos relógios disponíveis que não estão com uma sessão aberta à mão (carregando: a data andaria a
// cada conta; volta quando a sessão fechar), no horário da manhã do dia previsto (vencido: hoje) — a rotina (agenda "janela"
// no aviso) só dentro da janela, a manutenção ("sempre") em qualquer data; o relógio do dia e a véspera, a partir do plano;
// e as ocorrências dos eventos personalizados. Cada item: chave (a mesma enquanto nada mudar), data, hora, momento (o
// instante previsto), tipo, relogio_id, relogio, fazer e motivo.
function agenda_desejada($hoje)
{
    $lista = [];
    $tipos = explode(",", cfg("agenda_tipos"));
    $janela = max(0, (int)cfg("agenda_antecedencia"));
    $limite = $hoje->modify("+" . $janela . " days")->format("Y-m-d");
    $manha = cfg("horario_manha") !== "" ? cfg("horario_manha") : "06:30";
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY nome") as $r) {
        if (valor("SELECT l.id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.formato = 'sessao' AND l.fim IS NULL LIMIT 1", [(int)$r["id"]]) === null) {
            foreach (avisos_do_relogio($r, time()) as $a) {
                $data = max($hoje->format("Y-m-d"), substr($a["data"], 0, 10));
                if (in_array($a["identificador"], $tipos, true) && ($a["agenda"] === "sempre" || $data <= $limite)) {
                    // o motivo: o texto do aviso, com o "quando" contado do começo do evento (não muda a cada sincronização)
                    $falta = (strtotime($a["data"]) - strtotime($data . " " . $manha)) / 86400;
                    $quando = duracao_texto($falta) === "" ? "agora" : ($falta >= 0 ? "em " : "há ") . duracao_texto($falta);
                    $lista[] = ["chave" => $a["identificador"] . ":" . $r["id"] . ":" . $data, "data" => $data, "hora" => $manha, "momento" => $a["data"],
                        "tipo" => $a["identificador"], "relogio_id" => (int)$r["id"], "relogio" => $r["nome"], "fazer" => $a["nome"],
                        "motivo" => str_replace(["{relogio}", "{data}", "{quando}"], [$r["nome"], date("d/m/Y H:i", strtotime($a["data"])), $quando], $a["modelo"])];
                }
            }
        }
    }
    // o relógio do dia (o primeiro dia de cada vez que ele entra) e a véspera, a partir do plano
    $anterior = 0;
    foreach (linhas("SELECT p.data, p.relogio_id, r.* FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data BETWEEN ? AND ? ORDER BY p.data",
        [$hoje->modify("-1 day")->format("Y-m-d"), $limite]) as $p) {
        if ($anterior !== (int)$p["relogio_id"] && $p["data"] >= $hoje->format("Y-m-d")) {
            if (in_array("dia", $tipos, true)) {
                $lista[] = ["chave" => "dia:" . $p["relogio_id"] . ":" . $p["data"], "data" => $p["data"], "hora" => $manha, "momento" => $p["data"] . " " . $manha . ":00",
                    "tipo" => "dia", "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["nome"], "fazer" => "Usar hoje",
                    "motivo" => "Usar " . texto_ate($p["data"], "neste dia") . "."];
            }
            $vespera = date("Y-m-d", strtotime($p["data"] . " -1 day"));
            if (in_array("vespera", $tipos, true) && $vespera >= $hoje->format("Y-m-d")) {
                $r_v = $p;
                $r_v["id"] = $p["relogio_id"];
                $preparo = preparo_do_dia($r_v, $p["data"]);
                $lista[] = ["chave" => "vespera:" . $p["relogio_id"] . ":" . $vespera, "data" => $vespera, "hora" => cfg("horario_noite"),
                    "momento" => $vespera . " " . cfg("horario_noite") . ":00", "tipo" => "vespera", "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["nome"],
                    "fazer" => "Preparar para amanhã", "motivo" => count($preparo) > 0 ? implode("\n", $preparo) : "É só usar."];
            }
        }
        $anterior = (int)$p["relogio_id"];
    }
    $fim = strtotime($limite . " 23:59:59");
    foreach (linhas("SELECT * FROM evento_personalizado WHERE ativo = 1") as $ev) {
        if (in_array("ev" . $ev["id"], $tipos, true)) {
            foreach (ocorrencias($ev, max(time(), strtotime($ev["criado"])), $fim) as $ts) {
                $lista[] = ["chave" => "ev" . $ev["id"] . ":" . (int)$ev["relogio_id"] . ":" . date("Y-m-d-H-i", $ts), "data" => date("Y-m-d", $ts), "hora" => date("H:i", $ts),
                    "momento" => date("Y-m-d H:i:s", $ts), "tipo" => "ev" . $ev["id"], "relogio_id" => (int)$ev["relogio_id"],
                    "relogio" => $ev["relogio_id"] !== null ? (string)valor("SELECT nome FROM relogio WHERE id = ?", [(int)$ev["relogio_id"]]) : "",
                    "fazer" => $ev["nome"], "motivo" => ""];
            }
        }
    }
    usort($lista, function ($a, $b) { return strcmp($a["data"] . $a["hora"], $b["data"] . $b["hora"]); });
    return $lista;
}

// Evento da agenda pelo seu modelo (o personalizado do tipo, ou a mensagem padrão da agenda): a primeira linha é o título,
// o resto é a descrição
function evento_formatado($m)
{
    $dias = ["", "segunda", "terça", "quarta", "quinta", "sexta", "sábado", "domingo"];
    $ctx = contexto_mensagem((int)$m["relogio_id"], ["acao" => $m["fazer"], "motivo" => $m["motivo"], "ate" => $m["tipo"] === "dia" ? $m["motivo"] : "",
        "tipo_aviso" => $m["tipo"], "data" => date("d/m/Y", strtotime($m["data"])), "hora" => $m["hora"], "dia_semana" => $dias[(int)date("N", strtotime($m["data"]))]],
        new DateTimeImmutable("today"));
    $partes = explode("\n", aplicar_modelo(modelo_do_aviso("ag", $m["tipo"]), $ctx), 2);
    return ["titulo" => trim($partes[0]) !== "" ? trim($partes[0]) : $m["fazer"], "descricao" => isset($partes[1]) ? trim($partes[1]) : ""];
}

// Os endereços do Google (o config.php pode trocar, para testes)
if (!defined("GOOGLE_TOKEN_URL")) {
    define("GOOGLE_TOKEN_URL", "https://oauth2.googleapis.com/token");
}
if (!defined("GOOGLE_API_URL")) {
    define("GOOGLE_API_URL", "https://www.googleapis.com/calendar/v3");
}

// O token de acesso do Google pela conta de serviço (a chave JSON em agenda_chave; vazio: google-conta-servico.json na
// pasta do sistema), uma vez por requisição; false se não houver chave ou o Google recusar
function google_token()
{
    static $token = null;
    if ($token === null) {
        $token = false;
        // caminho vazio ou arquivo ilegível: sem chave (no PHP 8, ler caminho vazio é erro fatal)
        $caminho = cfg("agenda_chave") !== "" ? cfg("agenda_chave") : __DIR__ . "/google-conta-servico.json";
        $json = is_file($caminho) && is_readable($caminho) ? file_get_contents($caminho) : false;
        $chave = $json ? json_decode($json, true) : null;
        if (is_array($chave) && !empty($chave["private_key"]) && !empty($chave["client_email"])) {
            $b64 = function ($x) {
                return rtrim(strtr(base64_encode($x), "+/", "-_"), "=");
            };
            $agora = time();
            $cabeca = $b64(json_encode(["alg" => "RS256", "typ" => "JWT"]));
            $corpo = $b64(json_encode(["iss" => $chave["client_email"], "scope" => "https://www.googleapis.com/auth/calendar", "aud" => GOOGLE_TOKEN_URL,
                "iat" => $agora, "exp" => $agora + 3600]));
            $assinatura = "";
            if (@openssl_sign($cabeca . "." . $corpo, $assinatura, $chave["private_key"], "sha256WithRSAEncryption")) {
                $ch = curl_init(GOOGLE_TOKEN_URL);
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                    CURLOPT_POSTFIELDS => http_build_query(["grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer", "assertion" => $cabeca . "." . $corpo . "." . $b64($assinatura)])]);
                $resp = json_decode((string)curl_exec($ch), true);
                if (!empty($resp["access_token"])) {
                    $token = $resp["access_token"];
                }
            }
        }
    }
    return $token;
}

// Um pedido à API do Google Agenda: [código HTTP, resposta decodificada]
function google_api($metodo, $caminho, $corpo)
{
    $ch = curl_init(GOOGLE_API_URL . $caminho);
    $cab = ["Authorization: Bearer " . google_token()];
    $opcoes = [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
    if ($corpo !== null) {
        $cab[] = "Content-Type: application/json";
        $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo);
    }
    $opcoes[CURLOPT_HTTPHEADER] = $cab;
    curl_setopt_array($ch, $opcoes);
    $resp = curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$resp, true)];
}

// Deixa a agenda igual ao que tem de estar nela (agenda_desejada): cria o que falta, atualiza o que mudou de título ou de
// descrição, e apaga o que deixou de valer. Os eventos que já passaram ficam na agenda, como histórico.
function sincronizar_agenda($hoje)
{
    $res = ["criados" => 0, "atualizados" => 0, "removidos" => 0, "erros" => 0, "mensagem" => ""];
    if (cfg("agenda_id") === "") {
        $res["mensagem"] = "Informe o ID da agenda na configuração.";
    } elseif (!google_token()) {
        $res["mensagem"] = "Não autenticou no Google: confira o caminho da chave JSON e se a Calendar API está ativa no projeto.";
    } else {
        $desejados = [];
        foreach (agenda_desejada($hoje) as $m) {
            $desejados[$m["chave"]] = $m;
        }
        $cal = rawurlencode(cfg("agenda_id"));
        $existentes = [];
        foreach (linhas("SELECT * FROM agenda_evento") as $ev) {
            $existentes[$ev["chave"]] = $ev;
            if (!isset($desejados[$ev["chave"]])) {
                if ($ev["data"] < $hoje->format("Y-m-d")) {
                    sql("DELETE FROM agenda_evento WHERE chave = ?", [$ev["chave"]]);
                } else {
                    $r = google_api("DELETE", "/calendars/" . $cal . "/events/" . rawurlencode($ev["google_id"]), null);
                    if (in_array($r[0], [200, 204, 404, 410], true)) {
                        sql("DELETE FROM agenda_evento WHERE chave = ?", [$ev["chave"]]);
                        $res["removidos"]++;
                    } else {
                        $res["erros"]++;
                    }
                }
            }
        }
        foreach ($desejados as $chave => $m) {
            $f = evento_formatado($m);
            $assinatura = md5($f["titulo"] . "\n" . $f["descricao"]);
            if (isset($existentes[$chave])) {
                if ($existentes[$chave]["assinatura"] !== $assinatura) {
                    $r = google_api("PATCH", "/calendars/" . $cal . "/events/" . rawurlencode($existentes[$chave]["google_id"]), ["summary" => $f["titulo"], "description" => $f["descricao"]]);
                    if ($r[0] >= 200 && $r[0] < 300) {
                        sql("UPDATE agenda_evento SET titulo = ?, assinatura = ? WHERE chave = ?", [corta_texto($f["titulo"], 255), $assinatura, $chave]);
                        $res["atualizados"]++;
                    } else {
                        $res["erros"]++;
                    }
                }
            } else {
                $inicio = new DateTimeImmutable($m["data"] . " " . $m["hora"]);
                $r = google_api("POST", "/calendars/" . $cal . "/events", ["summary" => $f["titulo"], "description" => $f["descricao"],
                    "start" => ["dateTime" => $inicio->format("Y-m-d\\TH:i:s"), "timeZone" => date_default_timezone_get()],
                    "end" => ["dateTime" => $inicio->modify("+15 minutes")->format("Y-m-d\\TH:i:s"), "timeZone" => date_default_timezone_get()],
                    "reminders" => ["useDefault" => false, "overrides" => [["method" => "popup", "minutes" => 0]]],
                    "extendedProperties" => ["private" => ["relogios_chave" => $chave]]]);
                if ($r[0] >= 200 && $r[0] < 300 && !empty($r[1]["id"])) {
                    sql("INSERT INTO agenda_evento (chave, google_id, data, titulo, assinatura, criado) VALUES (?, ?, ?, ?, ?, NOW())",
                        [$chave, $r[1]["id"], $m["data"], corta_texto($f["titulo"], 255), $assinatura]);
                    $res["criados"]++;
                } else {
                    $res["erros"]++;
                }
            }
        }
    }
    return $res;
}

// o back-end de escrita (as operações que as páginas e a API chamam)
require_once __DIR__ . "/operacoes.php";
