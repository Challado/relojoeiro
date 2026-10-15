<?php
// Modos de rodízio: as regras do plano (os blocos de dias da semana, de onde sortear, um por dia ou um para o bloco, o
// relógio fixo, a forma de escolha, a escala inteligente e o ciclo). Fica no grupo Rodízio do menu, com o Plano e os
// Critérios. Esta página só traz o esqueleto: os dados vêm do api.php (recurso=cadastros), montados pelo modos.js, e as
// gravações vão para ele (recurso=modos)
require_once __DIR__ . "/pagina.php";
topo("Modos de rodízio", "modos.php");
?>

<main class="config pagina-modos" id="modos"></main>
<?php rodape(["api.js", "modos.js"]); ?>
