<?php
// Desejos: a lista de desejos (os relógios desejados, a prioridade mais alta primeiro, com o preço-alvo e o link). Esta
// página só traz o esqueleto: os dados vêm do api.php (recurso=desejos), montados pelo desejos.js com o abas.js (a mesma
// lista da aba dos desejados na página Hoje); o "Comprei" grava pelo recurso=relogio. Os que saíram da coleção ficam nas
// abas da página Hoje.
require_once __DIR__ . "/pagina.php";
topo("Desejos", "desejos.php");
?>

<main class="config pagina-desejos" id="desejos"></main>
<?php rodape(["api.js", "abas.js", "desejos.js"]); ?>
