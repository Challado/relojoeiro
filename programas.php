<?php
// Programas: os programas dos winders (a sequência dos movimentos, por voltas ou por tempo, o dia todo ou em blocos, com a
// velocidade do berço), da coleção, com a conta de cada um (as voltas por dia, o ciclo, o tempo girando) e as posições que o
// usam, o cadastro com a conta na hora e o ajudante "montar uma sequência para N voltas por dia". Esta página só traz o
// esqueleto: os dados vêm do api.php (recurso=programas; a conta enquanto se digita, com previa=1), montados pelo
// programas.js, e as gravações vão para ele (recurso=programas, acao=salvar, montar ou excluir). programas.php?editar=<id>
require_once __DIR__ . "/pagina.php";
topo("Programas", "programas.php");
?>

<main class="config pagina-programas" id="programas"></main>
<?php rodape(["api.js", "programas.js"]); ?>
