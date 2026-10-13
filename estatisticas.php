<?php
// Estatísticas: o uso da coleção num período, com gráficos. Esta página só traz o esqueleto: os dados vêm do api.php
// (recurso=estatisticas, já com as porcentagens e as alturas das barras), montados pelo estatisticas.js.
require_once __DIR__ . "/pagina.php";
topo("Estatísticas", "estatisticas.php");
?>

<main class="config pagina-estatisticas" id="estatisticas"></main>
<?php rodape(["api.js", "estatisticas.js"]); ?>
