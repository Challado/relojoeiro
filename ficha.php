<?php
// Ficha de um relógio: o mesmo painel da página Hoje numa página própria, com a escolha do relógio. Esta página só confere
// o login e traz o esqueleto: os dados vêm do api.php (recurso=ficha, montado pelo painel.js) e as gravações vão para ele.
require_once __DIR__ . "/pagina.php";
topo("Ficha do relógio", "");
?>

<main class="ficha">
  <form method="get" class="seletor">
    <label for="troca">Relógio</label>
    <select id="troca" name="id" onchange="this.form.submit()"></select>
    <noscript><button>Abrir</button></noscript>
  </form>
  <p id="sem-relogios" hidden>Nenhum relógio cadastrado. <a href="index.php?novo=1">Cadastre o primeiro</a>.</p>
  <div id="detalhe"></div>
</main>
<script>
// a lista dos relógios (os disponíveis primeiro) e o relógio pedido (ficha.php?id=3; sem id, o primeiro), depois que os
// scripts do fim da página (api.js, painel.js) carregarem
document.addEventListener("DOMContentLoaded", function () { api({recurso: "hoje"}).then(function (d) {
  var lista = d.relogios.slice().sort(function (a, b) { return (b.disponivel ? 1 : 0) - (a.disponivel ? 1 : 0) || a.nome.localeCompare(b.nome); });
  var id = parseInt(new URLSearchParams(window.location.search).get("id") || "0", 10) || (lista.length > 0 ? lista[0].id : 0);
  document.getElementById("troca").innerHTML = lista.map(function (x) {
    return el("option", {"value": x.id, "selected": x.id === id}, h(x.nome) + (x.disponivel ? "" : " (indisponível)"));
  }).join("");
  document.getElementById("sem-relogios").hidden = lista.length > 0;
  if (id > 0) {
    abrirNoPainel(id);
  }
}); });
</script>
<?php rodape(["api.js", "foto.js", "painel.js"]); ?>
