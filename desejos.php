<?php
// Desejos: a lista de desejos (os relógios desejados, a prioridade mais alta primeiro, com o preço-alvo e o link) e os que
// estão fora da coleção (emprestados, vendidos, arquivados). Esta página só traz o esqueleto: os dados vêm do api.php
// (recurso=desejos), montados pelo desejos.js; o "Comprei" e o "Voltar para a coleção" gravam pelo recurso=relogio.
require_once __DIR__ . "/pagina.php";
topo("Desejos", "desejos.php");
?>

<main class="config pagina-desejos" id="desejos"></main>
<?php rodape(["api.js", "desejos.js"]); ?>
