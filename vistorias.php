<?php
// Vistorias: os relógios com a vistoria periódica vencendo num período (os atrasados sempre), para marcar e registrar várias
// de uma vez. Esta página só confere o login e traz o esqueleto: a lista vem do api.php (recurso=vistorias), montada pelo
// vistorias.js, e o registro vai para o api.php (recurso=vistorias, acao=registrar). A vistoria se liga em Configuração
// (Vistoria: a cada quantos dias conferir cada relógio).
require_once __DIR__ . "/pagina.php";
topo("Vistorias", "vistorias.php");
?>

<main id="vistorias" class="vistorias"></main>
<?php rodape(["api.js", "vistorias.js"]); ?>
