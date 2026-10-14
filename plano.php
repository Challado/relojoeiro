<?php
// Plano: o plano inteiro (na escala inteligente, até o fim dela: um ano, dois), mês a mês, com quantos dias cada relógio
// tem no período e o "trocar por…" de cada dia. Esta página só confere o login e traz o esqueleto: os dados vêm do api.php
// (recurso=plano), montados pelo plano.js, e as trocas vão para o api.php (recurso=rodizio, acao=trocar_dia).
require_once __DIR__ . "/pagina.php";
topo("Plano", "plano.php");
?>

<main id="plano" class="plano"></main>
<?php rodape(["api.js", "plano.js"]); ?>
