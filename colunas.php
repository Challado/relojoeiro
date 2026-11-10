<?php
// O motor de colunas: quais colunas uma tabela mostra e em que ordem. Hoje serve a tabela dos relógios da página Hoje. TODA coluna
// mora na tabela coluna (da árvore, Cadastros › Colunas): as de sempre (código, relógio, marca, tipo, estado, carga, última vez
// usado, tempo de uso, próxima manutenção, em, o que fazer, encostado, comprado em e valor) nascem lá como colunas do sistema
// (sistema = 1, do modelo.json: uma árvore nova já nasce com elas, e a migração v62 criou nas que já existiam), e as que a pessoa monta
// (guiadas ou expert) vêm depois. Nada nasce fora do cadastro: o código não tem lista de colunas.
//
// A coluna do sistema tem o desenho dela pronto na tela (o estado, a barra de carga, a contagem do Em), então só muda de lugar na
// ordem e de aparecer de início ou não; a que a pessoa monta é uma conta por relógio (a expressão) com o formato dela e entra no
// catálogo com a chave c_<identificador>. A chave da coluna do sistema é o identificador dela (codigo, nome, marca...).
//
// O que a pessoa escolhe é uma lista de chaves (as que ficam à mostra, na ordem em que aparecem), guardada como uma opção em três
// níveis (opcoes.php: o padrão do sistema, o do usuário e a escolha da coleção; vazio herda); sem escolha em nenhum nível aparecem as
// marcadas "aparece sem ninguém escolher", na ordem do cadastro. A lista é só o que aparece: o que a coluna calcula (a ordenação, o filtro, os totais do
// rodapé) não muda. Quem desenha a tabela (o api.js, colunasAplicar) recebe da API as colunas na ordem em que valem; escondida,
// a coluna não ocupa lugar e o filtro dela se desliga.
// COLUNAS_PAGINAS: página => a opção que guarda a escolha
const COLUNAS_PAGINAS = [
    "hoje" => "colunas_hoje",
];

// O prefixo da chave de uma coluna montada pela pessoa na lista de colunas (c_custo_servico)
const COLUNA_PREFIXO = "c_";

// A tabela das colunas já existe? (antes da migração v61 não). Só o "sim" é guardado
function colunas_tabela_existe()
{
    static $existe = false;
    if (!$existe) {
        $existe = sem_escopo(function () { return in_array("coluna", banco_tabelas(), true); });
    }
    return $existe;
}

// A tabela das colunas já sabe o que é uma coluna do sistema (a migração v62)? Só o "sim" é guardado
function colunas_modelo_pronto()
{
    static $pronto = false;
    if (!$pronto) {
        $pronto = colunas_tabela_existe() && in_array("sistema", sem_escopo(function () { return sql_colunas_da_tabela("coluna"); }), true);
    }
    return $pronto;
}

// Uma linha da tabela coluna como a conta e a tela usam: a chave da lista (a do sistema é o identificador, a montada leva c_)
function colunas_linha($x)
{
    $sistema = (int)($x["sistema"] ?? 0) === 1;
    return ["id" => (int)$x["id"], "chave" => ($sistema ? "" : COLUNA_PREFIXO) . $x["identificador"], "identificador" => $x["identificador"], "nome" => $x["nome"],
        "modo" => $x["modo"], "tabela" => $x["tabela"], "campo" => $x["campo"], "funcao" => $x["funcao"], "expressao" => $x["expressao"],
        "formato" => $x["formato"], "casas" => $x["casas"] === null ? null : (int)$x["casas"], "simbolo" => $x["simbolo"], "padrao" => (int)$x["padrao"] === 1,
        "ordem" => (int)$x["ordem"], "sistema" => $sistema, "fixa" => (int)($x["fixa"] ?? 0) === 1];
}

// Todas as colunas da árvore, na ordem do cadastro: as do sistema e as montadas. Guardado por requisição e por coleção; COLUNAS_VERSAO
// muda quando alguém grava. Enquanto a migração v62 não vem, as do sistema saem do modelo.json (as mesmas que ela vai criar)
function colunas_todas()
{
    static $cache = [];
    $k = (int)colecao_atual() . "|" . ($GLOBALS["COLUNAS_VERSAO"] ?? 0);
    if (!isset($cache[$k])) {
        $cache = [];
        $linhas = colunas_tabela_existe() ? linhas("SELECT * FROM coluna ORDER BY ordem, id") : [];
        $lista = array_map("colunas_linha", $linhas);
        if (!colunas_modelo_pronto() || count(array_filter($lista, function ($c) { return $c["sistema"]; })) === 0) {
            $do_modelo = [];
            foreach (array_values(modelo_ler()["colunas"] ?? []) as $i => $x) {
                $do_modelo[] = colunas_linha(["id" => 0, "identificador" => $x["identificador"], "nome" => $x["nome"], "modo" => "guiado", "tabela" => $x["tabela"],
                    "campo" => $x["campo"], "funcao" => null, "expressao" => $x["expressao"], "formato" => $x["formato"], "casas" => null, "simbolo" => null,
                    "padrao" => (int)$x["padrao"], "ordem" => ($i + 1) * 10, "sistema" => 1, "fixa" => (int)$x["fixa"]]);
            }
            $lista = array_merge($do_modelo, $lista);
        }
        $cache[$k] = $lista;
    }
    return $cache[$k];
}

// As colunas que a pessoa montou (as do sistema não entram): são as que têm conta por relógio, os totais e a edição inteira
function colunas_cadastradas()
{
    return array_values(array_filter(colunas_todas(), function ($c) { return !$c["sistema"]; }));
}

// O catálogo de uma página: as chaves de todas as colunas, na ordem do cadastro
function colunas_catalogo($pagina)
{
    return $pagina === "hoje" ? array_column(colunas_todas(), "chave") : [];
}

// As colunas que aparecem quando ninguém escolheu: as marcadas "aparece sem ninguém escolher", na ordem do cadastro
function colunas_padrao($pagina)
{
    return $pagina === "hoje" ? array_column(array_filter(colunas_todas(), function ($c) { return $c["padrao"]; }), "chave") : [];
}

// As colunas que não se escondem (a Relógio)
function colunas_fixas($pagina)
{
    return $pagina === "hoje" ? array_values(array_column(array_filter(colunas_todas(), function ($c) { return $c["fixa"]; }), "chave")) : [];
}

// Os títulos das colunas de uma página, chave => texto, no idioma do pedido (a do sistema, pelo dicionário; a montada tem o nome que
// a pessoa deu)
function colunas_titulos($pagina)
{
    $t = [];
    foreach ($pagina === "hoje" ? colunas_todas() : [] as $c) {
        $t[$c["chave"]] = $c["sistema"] ? t($c["nome"]) : $c["nome"];
    }
    return $t;
}

// A página de uma opção de colunas (colunas_hoje: hoje); null quando a opção não é de colunas
function colunas_pagina_da_opcao($chave)
{
    foreach (COLUNAS_PAGINAS as $pagina => $opcao) {
        if ($opcao === $chave) {
            return $pagina;
        }
    }
    return null;
}

// Confere a lista de colunas de uma página, como a pessoa escreve ("nome,marca,uso" ou uma lista): [o erro ou null, a lista como se
// guarda ("nome,marca,uso"; vazio: sem escolha, herda)]. Cada coluna tem de existir, sem repetir, e as fixas (que não se escondem)
// têm de estar
function colunas_conferir($pagina, $valor)
{
    $opcao = COLUNAS_PAGINAS[$pagina];
    $fixas = colunas_fixas($pagina);
    $todas = colunas_catalogo($pagina);
    $itens = is_array($valor) ? $valor : explode(",", (string)$valor);
    $itens = array_values(array_filter(array_map(function ($x) { return trim((string)$x); }, $itens), function ($x) { return $x !== ""; }));
    if (count($itens) === 0) {
        return [null, ""];
    }
    $titulos = colunas_titulos($pagina);
    foreach ($itens as $i => $c) {
        if (!in_array($c, $todas, true)) {
            return [t("{1}: a coluna {2} não existe (as colunas: {3}).", [1 => $opcao, 2 => $c, 3 => implode(", ", $todas)]), null];
        }
        if (in_array($c, array_slice($itens, 0, $i), true)) {
            return [t("{1}: a coluna {2} aparece mais de uma vez.", [1 => $opcao, 2 => $c]), null];
        }
    }
    foreach ($fixas as $f) {
        if (!in_array($f, $itens, true)) {
            return [t("{1}: a coluna {2} não se esconde: ela tem de estar na lista.", [1 => $opcao, 2 => $titulos[$f] ?? $f]), null];
        }
    }
    return [null, implode(",", $itens)];
}

// As colunas que valem numa página, na ordem em que aparecem (as chaves): o que a coleção escolheu, senão o dono, senão o
// sistema (o cfg() já herda), senão as que aparecem de início (colunas_padrao). Uma lista guardada com uma coluna que não existe mais
// (uma versão de antes, uma coluna montada que saiu) perde essa coluna, e as fixas voltam
function colunas_efetivas($pagina)
{
    $opcao = COLUNAS_PAGINAS[$pagina];
    $fixas = colunas_fixas($pagina);
    $todas = colunas_catalogo($pagina);
    $guardada = array_values(array_filter(explode(",", (string)cfg($opcao)), function ($c) use ($todas) { return in_array($c, $todas, true); }));
    if (count($guardada) === 0) {
        return colunas_padrao($pagina);
    }
    foreach ($fixas as $f) {
        if (!in_array($f, $guardada, true)) {
            array_unshift($guardada, $f);
        }
    }
    return array_values(array_unique($guardada));
}

// As colunas de uma página para a API: todas as do catálogo, as que valem primeiro, na ordem, e depois as escondidas, na ordem do
// catálogo; cada uma com a chave, o título, se está à mostra, a posição (1, 2...; 0: escondida), se é fixa, se é montada pela pessoa
// (cadastrada: a tela cria a coluna sozinha) e, nas montadas, o formato e se o valor é número, para a tela alinhar e ordenar
function colunas_da_pagina($pagina)
{
    $todas = colunas_catalogo($pagina);
    $fixas = colunas_fixas($pagina);
    $titulos = colunas_titulos($pagina);
    $efetivas = colunas_efetivas($pagina);
    $por_chave = [];
    foreach ($pagina === "hoje" ? colunas_todas() : [] as $c) {
        $por_chave[$c["chave"]] = $c;
    }
    $lista = [];
    foreach (array_merge($efetivas, array_values(array_diff($todas, $efetivas))) as $k) {
        $pos = array_search($k, $efetivas, true);
        $c = $por_chave[$k] ?? null;
        $x = ["chave" => $k, "titulo" => $titulos[$k] ?? $k, "visivel" => $pos !== false, "posicao" => $pos === false ? 0 : $pos + 1, "fixa" => in_array($k, $fixas, true),
            "cadastrada" => $c !== null && !$c["sistema"]];
        if ($x["cadastrada"]) {
            $x["formato"] = $c["formato"];
            $x["numero"] = $c["formato"] !== "texto";
        }
        $lista[] = $x;
    }
    return $lista;
}

// ---------------------------------------------------------------------------------------------------------------------
// Os totais do rodapé: um total vai embaixo de uma coluna do cadastro (as de sempre e as montadas, a chave dela em total.coluna) e tem de
// combinar com ela. A contagem (inteiro, número) combina com qualquer coluna (quantos relógios, quantos de cada marca, em cada faixa); o
// resto, só com o que a coluna mostra: embaixo do dinheiro, dinheiro; embaixo de uma data, a data (ou os dias); embaixo do tempo, o tempo.
// A coluna de texto só aceita contagem. O formato da coluna é o do cadastro dela
// ---------------------------------------------------------------------------------------------------------------------

// O tamanho de total.coluna no banco (VARCHAR(20)): a chave de uma coluna montada é c_ e o identificador, por isso o identificador tem até 18
const TOTAL_COLUNA_TAMANHO = 20;

// Os formatos de total que combinam com um formato de coluna, na ordem de TOTAL_FORMATOS
function total_formatos_do_formato($formato_coluna)
{
    $mais = ["texto" => [], "inteiro" => ["decimal"], "numero" => ["decimal"], "decimal" => ["decimal"], "moeda" => ["moeda"], "porcentagem" => ["porcentagem", "decimal"],
        "data" => ["data", "dias"], "dias" => ["dias", "decimal"], "duracao" => ["duracao"]][$formato_coluna] ?? [];
    $aceitos = array_merge(["numero", "inteiro"], $mais);
    return array_values(array_filter(array_keys(TOTAL_FORMATOS), function ($f) use ($aceitos) { return in_array($f, $aceitos, true); }));
}

// A coluna do cadastro de uma chave (a do sistema ou a montada); null: não existe
function coluna_da_chave($chave)
{
    foreach (colunas_todas() as $c) {
        if ($c["chave"] === (string)$chave) {
            return $c;
        }
    }
    return null;
}

// Confere o lugar de um total: a coluna existe no cadastro, cabe no banco e o formato do total combina com o que ela mostra. Devolve o
// erro (texto) ou null
function total_coluna_conferir($chave, $formato)
{
    $c = coluna_da_chave($chave);
    if ($c === null) {
        return t("Coluna: {1}, ou vazio (nenhuma: só na página de totais).", [1 => implode(", ", array_column(colunas_todas(), "chave"))]);
    }
    if (strlen($c["chave"]) > TOTAL_COLUNA_TAMANHO) {
        return t("A coluna {1} tem o identificador comprido demais para ter total (a chave dela passa de {2} letras): encurte o identificador ou deixe o total só na página de totais.", [1 => $c["sistema"] ? t($c["nome"]) : $c["nome"], 2 => TOTAL_COLUNA_TAMANHO]);
    }
    $aceitos = total_formatos_do_formato($c["formato"]);
    if (!in_array((string)$formato, $aceitos, true)) {
        $nomes = total_formatos_nomes();
        return t("O formato {1} não combina com a coluna {2} (ela mostra {3}): use {4}, ou deixe o total só na página de totais.",
            [1 => $nomes[$formato] ?? $formato, 2 => $c["sistema"] ? t($c["nome"]) : $c["nome"], 3 => coluna_formatos()[$c["formato"]] ?? $c["formato"],
            4 => implode(", ", array_map(function ($f) use ($nomes) { return $nomes[$f]; }, $aceitos))]);
    }
    return null;
}

// ---------------------------------------------------------------------------------------------------------------------
// As colunas cadastradas: a expressão do modo guiado, o formato e o valor de cada relógio
// ---------------------------------------------------------------------------------------------------------------------

// Os formatos que uma coluna aceita: os dos totais mais o texto (como está)
function coluna_formatos()
{
    return relatorio_formatos_nomes();
}

// O formato que combina com o que a coluna mostra, quando a pessoa não escolhe: o da variável da tabela (pela unidade), do campo de
// cadastro (pelo tipo) ou da função da fonte (pelo tipo do campo)
function coluna_formato_sugerido($tabela, $campo, $funcao)
{
    if ($tabela === "relogio") {
        $v = $GLOBALS["VARIAVEIS_TABELA"][$campo] ?? null;
        if (in_array($campo, ["ultimo_uso", "manutencao_data", "encostado_data"], true)) {
            return "data";
        }
        if (in_array($campo, ["codigo", "nome", "marca", "tipo", "estado", "manutencao_nome", "posse"], true)) {
            return "texto";
        }
        $un = $v[1] ?? "";
        return $un === "%" ? "porcentagem" : ($un === "R$" ? "moeda" : ($un === "s" ? "duracao" : "numero"));
    }
    if ($tabela === "campos") {
        $tipo = campos_todos()[$campo]["tipo"] ?? "texto";
        return ["inteiro" => "inteiro", "decimal" => "decimal", "sim_nao" => "inteiro", "data" => "data"][$tipo] ?? "texto";
    }
    $tipo = $GLOBALS["FONTES_TABELAS"][$tabela][1][$campo][0] ?? null;
    if ($tipo === null || $funcao === null) {
        return "numero";
    }
    return fonte_tipo_do_resultado($tipo, $funcao) === "inteiro" ? "inteiro" : (FONTES_FORMATO_DO_TIPO[$tipo] ?? "numero");
}

// A expressão do modo guiado: tabela (relogio: as colunas da tabela dos relógios; campos: os campos do cadastro; ou uma tabela do
// dicionário de fontes), campo e função (só nas tabelas do dicionário). Devolve [a lista de erros, a expressão]
function coluna_expressao_guiada($tabela, $campo, $funcao)
{
    if ($tabela === "relogio") {
        if (!isset($GLOBALS["VARIAVEIS_TABELA"][(string)$campo])) {
            return [[t("Campo: {1} não é uma coluna da tabela dos relógios.", [1 => (string)$campo])], null];
        }
        return [[], (string)$campo];
    }
    if ($tabela === "campos") {
        if (!isset(campos_todos()[(string)$campo])) {
            return [[t("Campo: {1} não é um campo do cadastro dos relógios.", [1 => (string)$campo])], null];
        }
        return [[], (string)$campo];
    }
    if (!isset($GLOBALS["FONTES_TABELAS"][(string)$tabela])) {
        return [[t("Tabela: {1}, campos ou relogio.", [1 => implode(", ", array_keys($GLOBALS["FONTES_TABELAS"]))])], null];
    }
    $def = $GLOBALS["FONTES_TABELAS"][$tabela][1][(string)$campo] ?? null;
    if ($def === null) {
        return [[t("Campo: {1} não está na tabela {2}.", [1 => (string)$campo, 2 => $tabela])], null];
    }
    $aceitas = FONTES_FUNCOES_DO_TIPO[$def[0]];
    if (!in_array((string)$funcao, $aceitas, true)) {
        return [[t("Função: {1} para {2}.", [1 => implode(", ", $aceitas), 2 => $campo])], null];
    }
    return [[], fonte_variavel_nome($tabela, $campo, $funcao)];
}

// O texto de um valor no formato da coluna (as casas e o símbolo dela)
function coluna_texto($valor, $col)
{
    if ($col["formato"] === "texto") {
        return $valor === null || $valor === "" ? "—" : (string)$valor;
    }
    return total_texto($valor, $col["formato"], ["casas" => $col["casas"], "simbolo" => $col["simbolo"]]);
}

// A árvore da expressão de uma coluna (lida uma vez por expressão); null com erro de escrita
function coluna_arvore($expressao)
{
    static $arvores = [];
    if (!array_key_exists($expressao, $arvores)) {
        try {
            $arvores[$expressao] = formula_ler($expressao);
        } catch (ErroFormula $e) {
            $arvores[$expressao] = null;
        }
    }
    return $arvores[$expressao];
}

// O valor de uma coluna num relógio: [o valor, o texto]. $ctx: o contexto da conta do relógio (como o de totais_relogios: r, momento,
// rastro, pilha, valores e tabela). Expressão que não lê ou conta que falha: vazio
function coluna_valor($col, &$ctx)
{
    $arvore = coluna_arvore($col["expressao"]);
    $v = $arvore === null ? null : formula_calcular($arvore, $ctx);
    if (is_float($v) && !is_finite($v)) {
        $v = null;
    }
    return [$v, coluna_texto($v, $col)];
}

// Os valores das colunas cadastradas de uma linha da tabela (tabela_linha): chave => ["valor", "texto"], só das que aparecem (as
// escondidas não precisam de conta). $linha: a linha pronta; $r: o relógio; $val: os valores dos campos dele
function colunas_valores_da_linha($linha, $r, $agora, $val)
{
    $cad = colunas_cadastradas();
    if (count($cad) === 0) {
        return [];
    }
    $visiveis = colunas_efetivas("hoje");
    $ctx = null;
    $res = [];
    foreach ($cad as $c) {
        if (!in_array($c["chave"], $visiveis, true)) {
            continue;
        }
        if ($ctx === null) {
            $ctx = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $val, "tabela" => tabela_variaveis($linha, $r, $agora)];
        }
        [$v, $texto] = coluna_valor($c, $ctx);
        $res[$c["chave"]] = ["valor" => $v, "texto" => $texto];
    }
    return $res;
}
