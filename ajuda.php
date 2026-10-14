<?php
// Ajuda: como o sistema funciona, os campos do cadastro, os avisos e as telas. O texto é o do README.md (as seções para
// quem usa: sem a instalação, a API e o código), para a ajuda e o README nunca discordarem. Esta página só confere o login
// e traz o esqueleto, como as outras: o texto vem do api.php (recurso=manual, já em HTML, com o sumário), montado pelo
// ajuda.js. Os trechos e a conversão para HTML ficam no lib.php (manual_markdown e manual_html).
require_once __DIR__ . "/pagina.php";
topo("Ajuda", "ajuda.php");
?>

<main class="ajuda" id="ajuda">
  <h1>Ajuda</h1>
</main>
<?php rodape(["api.js", "ajuda.js"]); ?>
