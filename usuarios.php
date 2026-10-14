<?php
// Usuários: o painel do master (todas as contas: criar, alterar, trocar a senha, oficializar a demo, prorrogar, voltar a
// demo, bloquear, transferir uma coleção, dar acesso, ver o sistema como o usuário e excluir). Esta página só traz o
// esqueleto: os dados vêm do api.php (recurso=usuarios), montados pelo usuarios.js, e as gravações vão para o api.php
require_once __DIR__ . "/pagina.php";
topo("Usuários", "usuarios.php");
?>

<main class="config pagina-usuarios" id="usuarios"></main>
<?php rodape(["api.js", "usuarios.js"]); ?>
