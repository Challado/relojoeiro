<?php
// Acessórios: as pulseiras, caixas, estojos, ferramentas e o que mais for, de cada relógio ou soltos, com o cadastro. Esta
// página só traz o esqueleto: os dados vêm do api.php (recurso=acessorios), montados pelo acessorios.js, e as gravações vão
// para ele (recurso=acessorios, acao=salvar ou excluir). acessorios.php?relogio=3 (0: os soltos)&editar=5
require_once __DIR__ . "/pagina.php";
topo("Acessórios", "acessorios.php");
?>

<main class="config pagina-acessorios" id="acessorios"></main>
<?php rodape(["api.js", "acessorios.js"]); ?>
