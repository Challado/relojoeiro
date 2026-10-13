<?php
// Hora certa: a hora do servidor para acertar os relógios, andando segundo a segundo, com a hora UTC (Zulu), o fuso militar, o
// calendário e a lua. Esta página só traz o esqueleto: os dados vêm do api.php (recurso=hora_certa), montados pelo hora.js.
require_once __DIR__ . "/pagina.php";
topo("Hora certa", "hora.php");
?>

<main class="config pagina-hora" id="hora"></main>
<?php rodape(["api.js", "hora.js"]); ?>
