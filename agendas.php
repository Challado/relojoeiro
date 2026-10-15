<?php
// Agendas: onde os avisos, o relógio do dia e os eventos viram eventos de agenda: o Google Agenda, um calendário CalDAV
// (Nextcloud, iCloud, Fastmail...), o Outlook (Microsoft 365) ou um link ICS para assinar em qualquer app. Esta página só
// traz o esqueleto: os dados vêm do api.php (recurso=agendas), montados pelo agendas.js, e as gravações vão para ele
// (recurso=agendas, acao=salvar, excluir, sincronizar, teste_criar, teste_remover, novo_link ou outlook_conectar)
require_once __DIR__ . "/pagina.php";
topo("Agendas", "avisos.php");
?>

<main class="config pagina-agendas" id="agendas"></main>
<?php rodape(["api.js", "agendas.js"]); ?>
