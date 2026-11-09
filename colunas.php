<?php
// O motor de colunas: quais colunas uma tabela mostra e em que ordem. Hoje serve a tabela dos relógios da página Hoje; o catálogo
// de cada tabela (COLUNAS_PAGINAS) diz as colunas que existem, na ordem de sempre, e o que a pessoa escolhe é uma lista de chaves
// (as que ficam à mostra, na ordem em que aparecem), guardada como uma opção em três níveis (opcoes.php: o padrão do sistema, o
// do usuário e a escolha da coleção; vazio herda; sem escolha em nenhum nível, todas, na ordem de sempre). A lista é só o que
// aparece: o que a coluna calcula (a ordenação, o filtro, os totais do rodapé) não muda. Quem desenha a tabela (o api.js,
// colunasAplicar) recebe da API as colunas na ordem em que valem; escondida, a coluna não ocupa lugar e o filtro dela se desliga.
//
// O catálogo da tabela dos relógios é o das colunas onde um total pode ir (TOTAL_COLUNAS, lib.php): a chave é a do cabeçalho.
// COLUNAS_PAGINAS: página => [a opção que guarda a escolha, as chaves, na ordem de sempre, as que não se escondem]
const COLUNAS_PAGINAS = [
    "hoje" => ["colunas_hoje", ["codigo", "nome", "marca", "tipo", "estado", "carga", "ultimo", "uso", "manut", "em", "fazer", "encostado", "compra", "valor"], ["nome"]],
];
// as chaves da tabela dos relógios, para a opção (opcoes.php) saber os valores que aceita
const COLUNAS_HOJE = COLUNAS_PAGINAS["hoje"][1];

// Os títulos das colunas de uma página, chave => texto, no idioma do pedido
function colunas_titulos($pagina)
{
    return $pagina === "hoje" ? total_colunas_nomes() : [];
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
    [$opcao, $todas, $fixas] = COLUNAS_PAGINAS[$pagina];
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
// sistema (o cfg() já herda), senão todas na ordem de sempre. Uma lista guardada com uma coluna que não existe mais (uma versão
// de antes) perde essa coluna, e as fixas voltam
function colunas_efetivas($pagina)
{
    [$opcao, $todas, $fixas] = COLUNAS_PAGINAS[$pagina];
    $guardada = array_values(array_filter(explode(",", (string)cfg($opcao)), function ($c) use ($todas) { return in_array($c, $todas, true); }));
    if (count($guardada) === 0) {
        return $todas;
    }
    foreach ($fixas as $f) {
        if (!in_array($f, $guardada, true)) {
            array_unshift($guardada, $f);
        }
    }
    return array_values(array_unique($guardada));
}

// As colunas de uma página para a API: todas as do catálogo, as que valem primeiro, na ordem, e depois as escondidas, na ordem de
// sempre; cada uma com a chave, o título, se está à mostra, a posição (1, 2...; 0: escondida) e se é fixa
function colunas_da_pagina($pagina)
{
    [, $todas, $fixas] = COLUNAS_PAGINAS[$pagina];
    $titulos = colunas_titulos($pagina);
    $efetivas = colunas_efetivas($pagina);
    $lista = [];
    foreach (array_merge($efetivas, array_values(array_diff($todas, $efetivas))) as $c) {
        $pos = array_search($c, $efetivas, true);
        $lista[] = ["chave" => $c, "titulo" => $titulos[$c] ?? $c, "visivel" => $pos !== false, "posicao" => $pos === false ? 0 : $pos + 1, "fixa" => in_array($c, $fixas, true)];
    }
    return $lista;
}
