<?php
// Passaporte do relógio: a ficha dele para imprimir (ou guardar em PDF pelo "Imprimir" do navegador). passaporte.php?id=3
// Esta página só traz o esqueleto: os dados vêm do api.php (recurso=passaporte, já em texto), montados pelo passaporte.js.
require_once __DIR__ . "/pagina.php";
topo("Passaporte do relógio", "");
?>

<main class="config pagina-impressa" id="passaporte"></main>
<?php rodape(["api.js", "passaporte.js"]); ?>
