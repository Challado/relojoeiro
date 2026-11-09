<?php
// As fontes de dados das colunas cadastradas (Cadastros › Colunas): o dicionário dos campos do banco que podem virar uma coluna da
// tabela dos relógios. Cada tabela ligada ao relógio (relogio_id) tem os campos que valem, com o tipo e a descrição de cada um, e
// a coluna escolhe um campo e a função que junta os registros do relógio: a soma, a média, o menor, o maior ou a contagem (como
// o SUM, o AVG, o MIN, o MAX e o COUNT de um SELECT ... GROUP BY relogio_id).
//
// O que sai daqui é uma variável de fórmula: fonte_<tabela>_<campo>_<função> (fonte_servico_mao_de_obra_soma). Ela vale em todo
// lugar onde uma variável vale (a coluna cadastrada, os totais, os relatórios, as fórmulas). O SQL nunca vem de quem escreve:
// a tabela, o campo e a função saem deste dicionário, e a variável desconhecida é recusada antes de chegar ao banco.
//
// Tipos: inteiro, decimal, data, datahora, sim_nao, texto e registro (o registro inteiro, só para contar). Datas e horas viram
// o dia (o número de dias, como as outras datas das fórmulas), para a coluna mostrar a data. Um campo novo numa dessas tabelas
// precisa de uma linha aqui (testes/fontes.php confere).

// as funções que juntam os registros de um relógio: chave => [o nome, o SQL, a descrição]
$FONTES_FUNCOES = [
    "soma" => [tk("Soma"), "SUM", tk("a soma dos valores de todos os registros do relógio")],
    "media" => [tk("Média"), "AVG", tk("a média dos valores dos registros do relógio")],
    "menor" => [tk("Menor (ou mais antigo)"), "MIN", tk("o menor valor, ou a data mais antiga, entre os registros do relógio")],
    "maior" => [tk("Maior (ou mais recente)"), "MAX", tk("o maior valor, ou a data mais recente, entre os registros do relógio")],
    "conta" => [tk("Contagem"), "COUNT", tk("quantos registros do relógio têm este campo preenchido")],
];
// as funções que cada tipo aceita
const FONTES_FUNCOES_DO_TIPO = [
    "inteiro" => ["soma", "media", "menor", "maior", "conta"],
    "decimal" => ["soma", "media", "menor", "maior", "conta"],
    "data" => ["menor", "maior", "conta"],
    "datahora" => ["menor", "maior", "conta"],
    "sim_nao" => ["soma", "conta"],
    "texto" => ["conta"],
    "registro" => ["conta"],
];
// o formato que combina com cada tipo quando a pessoa não escolhe (os de TOTAL_FORMATOS); a contagem é sempre um inteiro
const FONTES_FORMATO_DO_TIPO = ["inteiro" => "inteiro", "decimal" => "decimal", "data" => "data", "datahora" => "data", "sim_nao" => "inteiro", "texto" => "texto", "registro" => "inteiro"];

// o dicionário: tabela => [a descrição da tabela, [campo => [tipo, a descrição, a unidade]]]. Todas têm relogio_id; "id" é o
// próprio registro (só conta). Os textos são traduzidos (tk)
$FONTES_TABELAS = [
    "lancamento" => [tk("Lançamentos do relógio (o pulso, a carga, a corda, o sol...)"), [
        "id" => ["registro", tk("cada lançamento"), ""],
        "valor" => ["decimal", tk("o valor do lançamento (a leitura de carga, a quantidade...)"), ""],
        "inicio" => ["datahora", tk("quando o lançamento começou (ou aconteceu)"), ""],
        "fim" => ["datahora", tk("quando a sessão terminou (vazio: marcação instantânea ou sessão aberta)"), ""],
    ]],
    "servico" => [tk("Serviços e diagnósticos do relógio"), [
        "id" => ["registro", tk("cada serviço"), ""],
        "data" => ["data", tk("a data do serviço"), ""],
        "mao_de_obra" => ["decimal", tk("a mão de obra cobrada no serviço"), "R$"],
        "estanqueidade_bar" => ["decimal", tk("a pressão do teste de estanqueidade"), "bar"],
        "marcha_antes" => ["decimal", tk("a marcha medida antes do serviço"), "s/dia"],
        "marcha_depois" => ["decimal", tk("a marcha medida depois do serviço"), "s/dia"],
        "garantia_ate" => ["data", tk("até quando vale a garantia do serviço"), ""],
    ]],
    "manutencao" => [tk("Manutenções do relógio (na oficina)"), [
        "id" => ["registro", tk("cada manutenção"), ""],
        "enviado" => ["data", tk("quando o relógio foi para a oficina"), ""],
        "previsao" => ["data", tk("a previsão de volta da oficina"), ""],
        "orcamento" => ["decimal", tk("o orçamento da oficina"), "R$"],
        "orcamento_data" => ["data", tk("a data do orçamento"), ""],
        "encerrado" => ["data", tk("quando a manutenção foi encerrada"), ""],
    ]],
    "timegrapher" => [tk("Medições do timegrapher"), [
        "id" => ["registro", tk("cada medição"), ""],
        "quando" => ["datahora", tk("quando a medição foi feita"), ""],
        "marcha" => ["decimal", tk("a marcha medida (segundos por dia)"), "s/dia"],
        "amplitude" => ["decimal", tk("a amplitude medida"), "°"],
        "erro_batida" => ["decimal", tk("o erro de batida medido"), "ms"],
        "bph" => ["inteiro", tk("as batidas por hora medidas"), ""],
        "angulo" => ["decimal", tk("o ângulo da posição medida"), "°"],
    ]],
    "reserva_teste" => [tk("Testes de reserva de marcha"), [
        "id" => ["registro", tk("cada teste"), ""],
        "inicio" => ["datahora", tk("quando o teste começou"), ""],
        "fim" => ["datahora", tk("quando o teste terminou (vazio: em andamento)"), ""],
        "protocolo_valor" => ["decimal", tk("o valor do protocolo do teste"), ""],
    ]],
    "documento" => [tk("Documentos do relógio"), [
        "id" => ["registro", tk("cada documento"), ""],
        "data" => ["data", tk("a data do documento"), ""],
        "tamanho" => ["inteiro", tk("o tamanho do arquivo, em bytes"), "B"],
    ]],
    "plano" => [tk("Dias em que o relógio foi sorteado para o pulso"), [
        "id" => ["registro", tk("cada dia sorteado"), ""],
        "data" => ["data", tk("o dia do sorteio"), ""],
    ]],
    "foto" => [tk("Fotos do relógio"), [
        "id" => ["registro", tk("cada foto"), ""],
        "atualizado" => ["datahora", tk("quando a foto foi trocada pela última vez"), ""],
    ]],
];

// As colunas de cada tabela que NÃO viram fonte, de propósito (chaves de outras tabelas, textos livres, arquivos, controle interno).
// Toda coluna do banco está no dicionário ou aqui: uma coluna nova sem lugar em nenhum dos dois falha no testes/fontes.php, para
// ninguém esquecer de decidir se ela pode ser uma coluna da tabela dos relógios
const FONTES_FORA = [
    "lancamento" => ["relogio_id", "tipo_id", "estado", "posicao", "origem", "criado"],
    "servico" => ["relogio_id", "manutencao_id", "tipo", "descricao", "estanqueidade", "observacao", "lancamento_id", "criado"],
    "manutencao" => ["relogio_id", "oficina_id", "situacao_id", "tipo_id", "problema", "os", "disponivel_antes", "observacao", "criado"],
    "timegrapher" => ["relogio_id", "posicao", "origem", "observacao", "criado"],
    "reserva_teste" => ["relogio_id", "tipo", "protocolo", "observacao", "criado"],
    "documento" => ["relogio_id", "calibre_id", "winder_id", "categoria_id", "titulo", "descricao", "nome", "tipo", "arquivo", "miniatura", "criado", "no_banco", "hash", "copia_banco"],
    "plano" => ["colecao_id", "relogio_id", "origem", "criado", "acao", "motivo"],
    "foto" => ["relogio_id", "tipo", "dados"],
];

// O nome da variável de uma tabela, campo e função
function fonte_variavel_nome($tabela, $campo, $funcao)
{
    return "fonte_" . $tabela . "_" . $campo . "_" . $funcao;
}

// Todas as variáveis das fontes: nome => [tabela, campo, função], na ordem do dicionário. Montado uma vez
function fontes_variaveis()
{
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        foreach ($GLOBALS["FONTES_TABELAS"] as $tabela => [, $campos]) {
            foreach ($campos as $campo => [$tipo]) {
                foreach (FONTES_FUNCOES_DO_TIPO[$tipo] as $funcao) {
                    $mapa[fonte_variavel_nome($tabela, $campo, $funcao)] = [$tabela, $campo, $funcao];
                }
            }
        }
    }
    return $mapa;
}

// O tipo do resultado de uma função sobre um campo: a contagem é inteira; a soma, a média, o menor e o maior de uma data são uma
// data; os outros seguem o campo
function fonte_tipo_do_resultado($tipo, $funcao)
{
    if ($funcao === "conta") {
        return "inteiro";
    }
    if ($tipo === "registro") {
        return "inteiro";
    }
    return $tipo;
}

// A descrição de uma variável de fonte, no idioma do pedido
function fonte_variavel_descricao($tabela, $campo, $funcao)
{
    [$desc_t, $campos] = $GLOBALS["FONTES_TABELAS"][$tabela];
    return t("{1}: {2}, {3} (a conta de cada relógio)", [1 => t($desc_t), 2 => t($GLOBALS["FONTES_FUNCOES"][$funcao][0]), 3 => t($campos[$campo][1])]);
}

// Os valores de uma fonte para todos os relógios: [relogio_id => valor]. Um SELECT com GROUP BY relogio_id; guardado por requisição
// (muda com FORMULAS_VERSAO, como o resto das contas). Datas viram o dia (número); o vazio, nada
function fonte_mapa($tabela, $campo, $funcao)
{
    static $cache = [];
    static $versao = 0;
    if ($versao !== ($GLOBALS["FORMULAS_VERSAO"] ?? 0)) {
        $cache = [];
        $versao = $GLOBALS["FORMULAS_VERSAO"] ?? 0;
    }
    $chave = $tabela . "|" . $campo . "|" . $funcao;
    if (isset($cache[$chave])) {
        return $cache[$chave];
    }
    if (!isset($GLOBALS["FONTES_TABELAS"][$tabela][1][$campo]) || !isset($GLOBALS["FONTES_FUNCOES"][$funcao])
        || !in_array($funcao, FONTES_FUNCOES_DO_TIPO[$GLOBALS["FONTES_TABELAS"][$tabela][1][$campo][0]], true)) {
        return $cache[$chave] = [];
    }
    $tipo = $GLOBALS["FONTES_TABELAS"][$tabela][1][$campo][0];
    $coluna = $tipo === "registro" ? "*" : $campo;
    $res = [];
    // a média sai da soma e da contagem, feita aqui: a média de um inteiro volta inteira em alguns bancos (Firebird), e assim os quatro dão o mesmo
    $sql_f = $funcao === "media" ? "SUM(" . $coluna . ") AS v, COUNT(" . $coluna . ") AS n" : $GLOBALS["FONTES_FUNCOES"][$funcao][1] . "(" . $coluna . ") AS v, 1 AS n";
    foreach (linhas("SELECT relogio_id AS rid, " . $sql_f . " FROM " . $tabela . " WHERE relogio_id IS NOT NULL GROUP BY relogio_id") as $l) {
        $v = $l["v"];
        if ($v === null || $v === "") {
            continue;
        }
        if ($funcao === "media") {
            $v = (int)$l["n"] > 0 ? (float)$v / (int)$l["n"] : null;
            if ($v === null) {
                continue;
            }
        }
        if ($funcao !== "conta" && ($tipo === "data" || $tipo === "datahora")) {
            $ts = strtotime(substr((string)$v, 0, 10) . " 00:00:00");
            $res[(int)$l["rid"]] = $ts === false ? null : dias_de($ts);
        } else {
            $res[(int)$l["rid"]] = $funcao === "conta" ? (int)$v : (float)$v;
        }
    }
    return $cache[$chave] = $res;
}

// O valor de uma variável de fonte para um relógio (nada: sem registros)
function fonte_valor($variavel, $relogio_id)
{
    $v = fontes_variaveis()[$variavel] ?? null;
    if ($v === null) {
        return null;
    }
    $mapa = fonte_mapa($v[0], $v[1], $v[2]);
    return $mapa[(int)$relogio_id] ?? null;
}

// O dicionário para a API e para a tela: cada tabela com a descrição, os campos (chave, tipo, descrição, unidade, as funções que
// aceita e a variável de cada função)
function fontes_dicionario()
{
    $lista = [];
    foreach ($GLOBALS["FONTES_TABELAS"] as $tabela => [$desc_t, $campos]) {
        $cs = [];
        foreach ($campos as $campo => [$tipo, $desc_c, $un]) {
            $fs = [];
            foreach (FONTES_FUNCOES_DO_TIPO[$tipo] as $f) {
                $fs[] = ["chave" => $f, "nome" => t($GLOBALS["FONTES_FUNCOES"][$f][0]), "variavel" => fonte_variavel_nome($tabela, $campo, $f),
                    "tipo_resultado" => fonte_tipo_do_resultado($tipo, $f), "formato" => fonte_tipo_do_resultado($tipo, $f) === "inteiro" ? "inteiro" : FONTES_FORMATO_DO_TIPO[$tipo]];
            }
            $cs[] = ["chave" => $campo, "tipo" => $tipo, "descricao" => t($desc_c), "unidade" => $un, "funcoes" => $fs];
        }
        $lista[] = ["tabela" => $tabela, "descricao" => t($desc_t), "campos" => $cs];
    }
    return $lista;
}
