<?php
// Configuração, como no sistema antigo: Geral, Telegram, Google Agenda, a mensagem padrão de cada canal, "O que vai para
// onde" (com os eventos personalizados), o cadastro de um evento e "Como sai hoje". Com o banco desatualizado, só o aviso
// e o botão de aplicar. Esta página só confere o login e traz o esqueleto: os dados vêm do api.php (recurso=config e
// recurso=migracoes), montados pelo configuracao.js, e as gravações vão para o api.php.
require_once __DIR__ . "/pagina.php";
topo("Configuração", "configuracao.php");
?>

<main class="config" id="configuracao"></main>
<?php rodape(["api.js", "configuracao.js"]); ?>
