<?php
// Destinos: para onde vão as mensagens da coleção (a da manhã, a da noite, os avisos na hora e os eventos): um webhook
// HTTP (Telegram, n8n...), um e-mail ou o Web Push do navegador, cada um com a sua mensagem e o que recebe. Esta página só
// traz o esqueleto: os dados vêm do api.php (recurso=destinos), montados pelo destinos.js, e as gravações vão para ele
// (recurso=destinos, acao=salvar, excluir ou testar)
require_once __DIR__ . "/pagina.php";
topo("Destinos", "configuracao.php");
?>

<main class="config pagina-destinos" id="destinos"></main>
<?php rodape(["api.js", "destinos.js"]); ?>
