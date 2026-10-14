<?php
// Minha conta: os meus dados (e-mail, idioma, senha), as coleções que vejo (abrir, criar, renomear, excluir, sair), as minhas
// árvores de grupos (copiar, renomear, excluir), pedir acesso, os meus pedidos, os tokens de API, as notificações deste
// aparelho (Web Push) e excluir a conta. Esta página só traz o esqueleto: os dados vêm do api.php (recurso=conta), montados
// pelo conta.js, e as gravações vão para o api.php (recurso=conta, colecoes, pedidos, convites e acessos)
require_once __DIR__ . "/pagina.php";
topo("Minha conta", "conta.php");
?>

<main class="config pagina-conta" id="conta"></main>
<?php rodape(["api.js", "conta.js"]); ?>
