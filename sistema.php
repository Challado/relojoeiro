<?php
// Sistema: o que é do sistema inteiro (só o master): o endereço do sistema, as rodadas do cron, a força da senha, os parâmetros
// de operação, a situação do cron, os sorteios, o servidor de e-mail, a cópia de segurança e o SQL. Esta página só confere o
// login e traz o esqueleto: os dados vêm do api.php (recurso=sistema, backup e sql), montados pelo sistema.js, e as gravações
// vão para o api.php (recurso=config, backup, sql e conta).
require_once __DIR__ . "/pagina.php";
topo("Sistema", "sistema.php");
?>

<main class="config" id="sistema"></main>
<?php rodape(["api.js", "sistema.js"]); ?>
