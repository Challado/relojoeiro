<?php
// recurso=cadastros: os campos, os tipos de lançamento, as fórmulas, os avisos, os modos e as categorias.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// tudo o que a página Cadastros mostra e edita: os campos, os tipos de lançamento (com quantos lançamentos cada um tem),
// as fórmulas e os avisos (todas as versões), os modos com os blocos, os pontos da árvore, os relógios e as funções do motor
$n = nos_todos();
$com_id = function ($linhas) {
    return array_map(function ($x) {
        foreach (["id", "no_id", "ordem", "ativo", "exclusiva", "mede_gasto", "modo_id", "relogio_id", "escala_dias", "ciclo", "resolve_tipo_id", "na_hora", "so_desejado"] as $k) {
            // a marca do modo em uso é 1 ou vazia (NULL) no banco: aqui, 1 ou 0
            if (array_key_exists($k, $x) && ($x[$k] !== null || ($k === "ativo" && array_key_exists("selecao", $x)))) {
                $x[$k] = (int)$x[$k];
            }
        }
        return $x;
    }, $linhas);
};
$saida = ["campos" => $com_id(campos_com_opcoes(linhas("SELECT * FROM campo ORDER BY ordem, id"))),
    "lancamento_tipos" => $com_id(linhas("SELECT t.*, (SELECT COUNT(*) FROM lancamento l WHERE l.tipo_id = t.id) AS lancamentos FROM lancamento_tipo t ORDER BY t.ordem, t.id")),
    "formulas" => $com_id(linhas("SELECT * FROM formula ORDER BY identificador, id")),
    "avisos" => $com_id(linhas(AVISO_SELECT . " ORDER BY a.identificador, a.id")),
    "modos" => array_map(function ($m) use ($com_id) {
        $m = $com_id([$m])[0];
        $m["blocos"] = $com_id(modo_blocos($m["id"]));
        return $m;
    }, linhas("SELECT * FROM modo ORDER BY ordem, id")),
    "modo_ativo" => modo_ativo(), "max_sem_uso" => (int)cfg("max_sem_uso"),
    "grupos" => array_map(function ($o) { return ["id" => $o[0], "caminho" => no_caminho($o[0])]; }, nos_em_ordem()),
    "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"]]; }, linhas("SELECT id, nome FROM relogio ORDER BY nome")),
    "documento_categorias" => documento_categorias_lista(),
    "funcoes" => array_map(function ($f) { return $f[2]; }, $FUNCOES), "tipos_de_campo" => $TIPOS_CAMPO, "formatos_de_lancamento" => $FORMATOS_LANCAMENTO];
