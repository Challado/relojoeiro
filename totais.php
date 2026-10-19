<?php
// Totais: a página de totais, montada por quem usa (a aba Totais dos Cadastros): cada total marcado para a página vira um
// quadro, estreito ou largo, na ordem do cadastro. Esta página só traz o esqueleto: os dados vêm do api.php (recurso=totais,
// parte=pagina, já calculados nos relógios do endereço: totais.php?relogios=1,2,3, o filtro da página Hoje; sem, todos),
// montados pelo totais.js.
require_once __DIR__ . "/pagina.php";
topo("Totais", "totais.php");
?>

<main class="config pagina-totais" id="totais"></main>
<?php rodape(["api.js", "totais.js"]); ?>
