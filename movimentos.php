<?php
// Movimentos: os passos dos programas dos winders (um giro horário ou anti-horário, medido em voltas ou em tempo, ou uma
// parada), da coleção, com os programas que usam cada um. Esta página só traz o esqueleto: os dados vêm do api.php
// (recurso=programas), montados pelo movimentos.js, e as gravações vão para ele (recurso=movimentos, acao=salvar ou excluir).
// movimentos.php?editar=<id>
require_once __DIR__ . "/pagina.php";
topo("Movimentos", "movimentos.php");
?>

<main class="config pagina-movimentos" id="movimentos"></main>
<?php rodape(["api.js", "movimentos.js"]); ?>
