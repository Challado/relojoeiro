<?php
// Simulador: compara dois ou mais cenários de rodízio (um modo com o que se quer mudar nele e os relógios do sorteio) no mesmo
// período, sem gravar nada, e aplica o que mais agradar. Esta página só confere o login e traz o esqueleto: os dados vêm do
// api.php (recurso=simulador; o motor em simulador_motor.php), montados pelo simulador.js, e o Aplicar vai para o api.php
// (recurso=simulador, acao=aplicar).
require_once __DIR__ . "/pagina.php";
topo("Simulador", "simulador.php");
?>

<main id="simulador" class="simulador"></main>
<?php rodape(["api.js", "simulador.js"]); ?>
