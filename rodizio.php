<?php
// Rodízio › Ajustes do rodízio: as opções do rodízio (ligado, o relógio de ontem sem uso passa para hoje, os dias sem relógio,
// a troca no próprio dia, a semana, a escala inteligente, o sorteio e a garantia) nos três níveis (esta coleção, as coleções
// do dono e o sistema). Esta página só confere o login e traz o esqueleto: os dados vêm do api.php (recurso=opcoes,
// grupo=rodizio), montados pelo rodizio.js, e a gravação vai para o api.php (recurso=config, acao=salvar).
require_once __DIR__ . "/pagina.php";
topo("Ajustes do rodízio", "rodizio.php");
?>

<main class="config" id="rodizio"></main>
<?php rodape(["api.js", "rodizio.js"]); ?>
