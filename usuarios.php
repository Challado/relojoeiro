<?php
// Usuários: quem acessa, e criar um usuário ou trocar a senha. Esta página só confere o login e traz o esqueleto: os dados
// vêm do api.php (recurso=usuarios), montados pelo usuarios.js, e as gravações vão para o api.php.
require_once __DIR__ . "/pagina.php";
topo("Usuários", "usuarios.php");
?>

<main id="usuarios"></main>
<?php rodape(["api.js", "usuarios.js"]); ?>
