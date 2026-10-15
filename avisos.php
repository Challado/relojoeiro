<?php
// Avisos: tudo o que o sistema lembra, num lugar só. Os avisos dos relógios (calculados pelas fórmulas: dar corda,
// carregar, trocar a pilha...), os lembretes (os eventos personalizados, com hora e repetição) e para onde vão (os destinos
// das mensagens e as agendas). Esta página só traz o esqueleto: os dados vêm do api.php (recurso=cadastros e
// recurso=config), montados pelo avisos.js, e as gravações vão para ele (recurso=avisos e recurso=config)
require_once __DIR__ . "/pagina.php";
topo("Avisos", "avisos.php");
?>

<main class="config pagina-avisos" id="avisos"></main>
<?php rodape(["api.js", "avisos.js"]); ?>
