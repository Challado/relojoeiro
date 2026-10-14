<?php
// Execuções do cron: filtro de datas, situação e texto, resumo do período e navegação de páginas.
// Esta página só confere o login e traz o esqueleto: os dados vêm do api.php (recurso=cron), montados pelo execucoes.js.
require_once __DIR__ . "/pagina.php";
topo("Execuções do cron", "execucoes.php");
?>

<main class="config execucoes" id="execucoes"></main>
<?php rodape(["api.js", "execucoes.js"]); ?>
