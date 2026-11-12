<?php
// Galeria: a coleção em fotos, por grupo, para ver e imprimir, com as abas por situação: galeria.php (a primeira aba),
// galeria.php?aba=vendido, galeria.php?aba=todos (ou galeria.php?posse=todos, como antes).
// Esta página só traz o esqueleto: os dados vêm do api.php (recurso=galeria), montados pelo galeria.js com o abas.js.
require_once __DIR__ . "/pagina.php";
topo("Galeria", "galeria.php");
?>

<main class="config pagina-impressa pagina-galeria" id="galeria"></main>
<?php rodape(["api.js", "abas.js", "galeria.js"]); ?>
