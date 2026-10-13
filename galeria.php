<?php
// Galeria: a coleção em fotos, por grupo, para ver e imprimir. galeria.php (os da coleção) ou galeria.php?posse=todos
// Esta página só traz o esqueleto: os dados vêm do api.php (recurso=galeria), montados pelo galeria.js.
require_once __DIR__ . "/pagina.php";
topo("Galeria", "galeria.php");
?>

<main class="config pagina-impressa pagina-galeria" id="galeria"></main>
<?php rodape(["api.js", "galeria.js"]); ?>
