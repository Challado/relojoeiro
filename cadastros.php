<?php
// Os cadastros do projeto: campos, tipos de lançamento, fórmulas (com o testar), avisos e modos de rodízio. Tudo que o
// sistema usa e que não é código fica aqui. Esta página só confere o login e traz o esqueleto: os dados vêm do api.php
// (recurso=cadastros e recurso=calcular), montados pelo cadastros.js, e as gravações vão para o api.php.
require_once __DIR__ . "/pagina.php";
topo("Cadastros", "cadastros.php");
?>

<main class="config" id="cadastros"></main>
<?php rodape(["api.js", "cadastros.js"]); ?>
