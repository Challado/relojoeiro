<?php
// Documentos de um relógio (documentos.php?relogio=3): o manual, a nota fiscal em PDF e em XML, as fotos de recordação, os
// vídeos e o que mais for, por categoria. As fotos abrem numa galeria em tela cheia, os vídeos tocam em sequência, o PDF
// abre no visualizador do navegador, o XML da nota mostra o resumo, e o resto é para baixar. Esta página só confere o
// login e traz o esqueleto: os dados vêm do api.php (recurso=documentos) e o envio vai para ele (recurso=documentos).
require_once __DIR__ . "/pagina.php";
topo("Documentos", "documentos.php");
?>

<main id="documentos" class="documentos"></main>
<div id="visor" class="visor" hidden></div>
<?php rodape(["api.js", "documentos.js"]); ?>
