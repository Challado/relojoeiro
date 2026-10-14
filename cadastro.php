<?php
// O cadastro aberto (sem login): criar uma conta (com uma coleção com o conjunto inicial, vazia, ou pedindo acesso à coleção
// de alguém ou a tudo de um dono), aceitar um convite (cadastro.php?convite=<token>), esqueci a senha e a senha nova pelo
// link do e-mail (cadastro.php?senha=<token>). Esta página só traz o esqueleto: os dados vêm do api.php (recurso=cadastro, que
// responde sem login), montados pelo cadastro.js, e as gravações vão para ele
require_once __DIR__ . "/pagina.php";
topo("Criar conta", "cadastro.php", true);
?>

<main class="config cadastro-pagina" id="cadastro"></main>
<?php rodape(["api.js", "cadastro.js"]); ?>
