<?php
// Manutenção: o relógio numa oficina, da ida à volta. Quatro abas: Em manutenção (os que estão na oficina agora, mudar a
// situação e o "Cadastrar uma manutenção"), Manutenções (todas, com os filtros), Oficinas (o cadastro, com os contatos) e
// Listas (as situações, os tipos de serviço, os resultados da estanqueidade, os tipos de oficina e de contato). Esta
// página só traz o esqueleto: os dados vêm do api.php (recurso=manutencao e listas), montados pelo manutencao.js, e as
// gravações vão para ele (recurso=manutencao, oficinas e listas). manutencao.php?aba=abertas|todas|oficinas|listas,
// ?relogio=<id> (o novo já nesse relógio), ?editar=<manutenção>, ?oficina=<oficina a editar>
require_once __DIR__ . "/pagina.php";
topo("Manutenção", "manutencao.php");
?>

<main class="config pagina-manutencao" id="manutencao"></main>
<?php rodape(["api.js", "manutencao.js"]); ?>
