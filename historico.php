<?php
// Histórico de um relógio em página própria (abre numa aba nova a partir do painel): a linha do tempo inteira, com filtro
// de período e de estado, resumo do tempo em cada estado e navegação de páginas. historico.php?id=3
// Esta página só confere o login e traz o esqueleto: os dados vêm do api.php (recurso=historico), montados pelo historico.js.
require_once __DIR__ . "/pagina.php";
topo("Histórico", "");
?>

<main class="config pagina-historico" id="historico"></main>
<?php rodape(["api.js", "historico.js"]); ?>
