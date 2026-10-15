<?php
// Aprovações: os pedidos de acesso às minhas coleções (aprovar, recusar), os convites que membros fizeram e esperam por mim,
// convidar alguém (por e-mail), os convites que fiz (reenviar, cancelar) e quem vê as minhas coleções (tirar o acesso). Esta
// página só traz o esqueleto: os dados vêm do api.php (recurso=aprovacoes), montados pelo aprovacoes.js, e as gravações vão
// para o api.php (recurso=pedidos, convites e acessos)
require_once __DIR__ . "/pagina.php";
topo("Aprovações", "aprovacoes.php");
?>

<main class="config pagina-aprovacoes" id="aprovacoes"></main>
<?php rodape(["api.js", "aprovacoes.js"]); ?>
