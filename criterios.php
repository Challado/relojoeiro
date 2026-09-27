<?php
// Critérios de escolha do relógio, por lugar da árvore. Cada lugar — todos os relógios, um grupo em qualquer nível ou um
// relógio — pode ter o seu conjunto: parâmetros (peso no conjunto, somam 100), subparâmetros (peso no parâmetro, somam 100,
// e o que medem) e faixas (o valor medido vira nota de 0 a 100). O relógio usa o conjunto mais perto dele, inteiro.
// Esta página só confere o login e traz o esqueleto: os dados vêm do api.php (recurso=criterios), montados pelo
// criterios.js, e as gravações vão para o api.php (recurso=criterios, as ações de op_criterios).
require_once __DIR__ . "/pagina.php";
topo("Critérios de escolha", "criterios.php");
?>

<main class="config pagina-criterios" id="criterios"></main>
<?php rodape(["api.js", "criterios.js"]); ?>
