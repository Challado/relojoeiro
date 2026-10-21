<?php
// Relatórios: a porta única de todo relatório do sistema (o menu com os relatórios, os campos do escolhido, a tela e o papel,
// a planilha). relatorios.php?tipo=servicos&relogio=3 abre o relatório já gerado. Esta página só traz o esqueleto: o
// catálogo e o relatório vêm do api.php (recurso=relatorios), montados pelo relatorios.js.
require_once __DIR__ . "/pagina.php";
topo("Relatórios", "relatorios.php");
?>

<main class="config pagina-impressa pagina-relatorios">
<h1 class="barra-impressao"><?= te("Relatórios") ?></h1>
<div id="relatorio-form"></div>
<div id="relatorio"></div>
</main>
<?php rodape(["api.js", "relatorios.js"]); ?>
