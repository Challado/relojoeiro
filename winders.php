<?php
// Winders: os winders da coleção, as posições de cada um (o programa, o relógio que está nela, a carga e quando enche) e o
// cadastro dos dois. Esta página só traz o esqueleto: os dados vêm do api.php (recurso=winders), montados pelo winders.js,
// e as gravações vão para ele (recurso=winders e winder_posicoes, acao=salvar ou excluir; o Pôr e o Tirar, recurso=lancamento).
// winders.php?editar=<winder>, ?posicao=<posição>, ?nova_posicao=<winder>
require_once __DIR__ . "/pagina.php";
topo("Winders", "winders.php");
?>

<main class="config pagina-winders" id="winders"></main>
<?php rodape(["api.js", "winders.js"]); ?>
