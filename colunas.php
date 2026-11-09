<?php
// O motor de colunas: quais colunas uma tabela mostra e em que ordem. Hoje serve a tabela dos relógios da página Hoje; o catálogo
// de cada tabela (COLUNAS_PAGINAS) diz as colunas que existem, na ordem de sempre, e o que a pessoa escolhe é uma lista de chaves
// (as que ficam à mostra, na ordem em que aparecem), guardada como uma opção em três níveis (opcoes.php: o padrão do sistema, o
// do usuário e a escolha da coleção; vazio herda; sem escolha em nenhum nível, todas, na ordem de sempre). A lista é só o que
// aparece: o que a coluna calcula (a ordenação, o filtro, os totais do rodapé) não muda. Quem desenha a tabela (o api.js,
// colunasAplicar) recebe da API as colunas na ordem em que valem; escondida, a coluna não ocupa lugar e o filtro dela se desliga.
//
// Além das colunas de sempre (fixas, do código), a tabela da página Hoje tem as colunas cadastradas (Cadastros › Colunas, a tabela
// coluna, da árvore): cada uma é uma conta por relógio (a expressão), com o formato dela, e entra no catálogo com a chave c_<identificador>.
// Sem escolha em nenhum nível, aparecem as de sempre e as cadastradas com "aparece sem ninguém escolher".
//
// O catálogo da tabela dos relógios é o das colunas onde um total pode ir (TOTAL_COLUNAS, lib.php): a chave é a do cabeçalho.
// COLUNAS_PAGINAS: página => [a opção que guarda a escolha, as chaves, na ordem de sempre, as que não se escondem]
const COLUNAS_PAGINAS = [
    "hoje" => ["colunas_hoje", ["codigo", "nome", "marca", "tipo", "estado", "carga", "ultimo", "uso", "manut", "em", "fazer", "encostado", "compra", "valor"], ["nome"]],
];
// as chaves da tabela dos relógios, para a opção (opcoes.php) saber os valores que aceita
const COLUNAS_HOJE = COLUNAS_PAGINAS["hoje"][1];

// O prefixo da chave de uma coluna cadastrada na lista de colunas (c_custo_servico)
const COLUNA_PREFIXO = "c_";

// A tabela das colunas cadastradas já existe? (antes da migração v61 não: o catálogo fica só com as de sempre). Só o "sim" é guardado
function colunas_tabela_existe()
{
    static $existe = false;
    if (!$existe) {
        $existe = sem_escopo(function () { return in_array("coluna", banco_tabelas(), true); });
    }
    return $existe;
}

// As colunas cadastradas da árvore, na ordem do cadastro: cada uma com a chave da lista (c_<identificador>). Guardado por requisição e
// por coleção; COLUNAS_VERSAO muda quando alguém grava
function colunas_cadastradas()
{
    static $cache = [];
    $k = (int)colecao_atual() . "|" . ($GLOBALS["COLUNAS_VERSAO"] ?? 0);
    if (!isset($cache[$k])) {
        $cache = [];
        $cache[$k] = [];
        if (colunas_tabela_existe()) {
            foreach (linhas("SELECT * FROM coluna ORDER BY ordem, id") as $x) {
                $cache[$k][] = ["id" => (int)$x["id"], "chave" => COLUNA_PREFIXO . $x["identificador"], "identificador" => $x["identificador"], "nome" => $x["nome"],
                    "modo" => $x["modo"], "tabela" => $x["tabela"], "campo" => $x["campo"], "funcao" => $x["funcao"], "expressao" => $x["expressao"],
                    "formato" => $x["formato"], "casas" => $x["casas"] === null ? null : (int)$x["casas"], "simbolo" => $x["simbolo"], "padrao" => (int)$x["padrao"] === 1,
                    "ordem" => (int)$x["ordem"]];
            }
        }
    }
    return $cache[$k];
}

// O catálogo de uma página: as chaves das colunas de sempre e, depois, as das cadastradas (só a página Hoje tem cadastradas)
function colunas_catalogo($pagina)
{
    $lista = COLUNAS_PAGINAS[$pagina][1];
    if ($pagina === "hoje") {
        foreach (colunas_cadastradas() as $c) {
            $lista[] = $c["chave"];
        }
    }
    return $lista;
}

// As colunas que aparecem quando ninguém escolheu: as de sempre e as cadastradas que aparecem sem ninguém escolher
function colunas_padrao($pagina)
{
    $lista = COLUNAS_PAGINAS[$pagina][1];
    if ($pagina === "hoje") {
        foreach (colunas_cadastradas() as $c) {
            if ($c["padrao"]) {
                $lista[] = $c["chave"];
            }
        }
    }
    return $lista;
}

// Os títulos das colunas de uma página, chave => texto, no idioma do pedido (as cadastradas têm o nome que a pessoa deu)
function colunas_titulos($pagina)
{
    if ($pagina !== "hoje") {
        return [];
    }
    $t = total_colunas_nomes();
    foreach (colunas_cadastradas() as $c) {
        $t[$c["chave"]] = $c["nome"];
    }
    return $t;
}

// A página de uma opção de colunas (colunas_hoje: hoje); null quando a opção não é de colunas
function colunas_pagina_da_opcao($chave)
{
    foreach (COLUNAS_PAGINAS as $pagina => [$opcao]) {
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
    [$opcao, , $fixas] = COLUNAS_PAGINAS[$pagina];
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
// (uma versão de antes, uma coluna cadastrada que saiu) perde essa coluna, e as fixas voltam
function colunas_efetivas($pagina)
{
    [$opcao, , $fixas] = COLUNAS_PAGINAS[$pagina];
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
// catálogo; cada uma com a chave, o título, se está à mostra, a posição (1, 2...; 0: escondida), se é fixa e se é cadastrada (com o
// formato e se o valor é número, para a tela alinhar e ordenar)
function colunas_da_pagina($pagina)
{
    [, , $fixas] = COLUNAS_PAGINAS[$pagina];
    $todas = colunas_catalogo($pagina);
    $titulos = colunas_titulos($pagina);
    $efetivas = colunas_efetivas($pagina);
    $cad = [];
    foreach ($pagina === "hoje" ? colunas_cadastradas() : [] as $c) {
        $cad[$c["chave"]] = $c;
    }
    $lista = [];
    foreach (array_merge($efetivas, array_values(array_diff($todas, $efetivas))) as $k) {
        $pos = array_search($k, $efetivas, true);
        $x = ["chave" => $k, "titulo" => $titulos[$k] ?? $k, "visivel" => $pos !== false, "posicao" => $pos === false ? 0 : $pos + 1, "fixa" => in_array($k, $fixas, true),
            "cadastrada" => isset($cad[$k])];
        if (isset($cad[$k])) {
            $x["formato"] = $cad[$k]["formato"];
            $x["numero"] = !in_array($cad[$k]["formato"], ["texto"], true);
        }
        $lista[] = $x;
    }
    return $lista;
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
