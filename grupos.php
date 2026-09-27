<?php
// Grupos de relógios: a árvore (criar, renomear, mover, ordenar, excluir) e o grupo de cada relógio. Esta página só confere o
// login e traz o esqueleto: os dados vêm do api.php (recurso=arvore), montados pelo grupos.js, e as gravações vão para o
// api.php (recurso=arvore).
require_once __DIR__ . "/pagina.php";
topo("Grupos de relógios", "grupos.php");
?>

<main class="config pagina-criterios" id="grupos"></main>
<?php rodape(["api.js", "grupos.js"]); ?>
