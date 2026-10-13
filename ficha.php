<?php
// Ficha de um relógio: o mesmo painel da página Hoje numa página própria, com a escolha do relógio. Esta página só confere
// o login e traz o esqueleto: os dados vêm do api.php (recurso=ficha, montado pelo painel.js) e as gravações vão para ele.
require_once __DIR__ . "/pagina.php";
topo("Ficha do relógio", "");
?>

<main class="ficha">
  <form method="get" class="seletor">
    <label for="troca"><?= t("Relógio") ?></label>
    <select id="troca" name="id" onchange="this.form.submit()"></select>
    <noscript><button><?= t("Abrir") ?></button></noscript>
  </form>
  <p id="sem-relogios" hidden><?= t("Nenhum relógio cadastrado. {1}Cadastre o primeiro{2}.", ["1" => "<a href=\"index.php?novo=1\">", "2" => "</a>"]) ?></p>
  <div id="detalhe"></div>
</main>
<script>
// a lista dos relógios (os da coleção, os disponíveis primeiro; depois os de fora dela: desejados, emprestados, vendidos,
// arquivados) e o relógio pedido (ficha.php?id=3; sem id, o primeiro), depois que os scripts do fim da página (api.js,
// painel.js) carregarem
document.addEventListener("DOMContentLoaded", function () { api({recurso: "hoje"}).then(function (d) {
  var lista = d.relogios.slice().sort(function (a, b) { return (b.disponivel ? 1 : 0) - (a.disponivel ? 1 : 0) || a.nome.localeCompare(b.nome); });
  var fora = d.fora_da_colecao.slice().sort(function (a, b) { return a.posse_texto.localeCompare(b.posse_texto) || a.nome.localeCompare(b.nome); });
  var todos = lista.concat(fora);
  var id = parseInt(new URLSearchParams(window.location.search).get("id") || "0", 10) || (todos.length > 0 ? todos[0].id : 0);
  var opcao = function (x, extra) { return el("option", {"value": x.id, "selected": x.id === id}, h(x.nome) + extra); };
  var html = lista.map(function (x) { return opcao(x, x.disponivel ? "" : " (" + h(x.estado_texto.toLowerCase()) + ")"); }).join("");
  if (fora.length > 0) {
    html = el("optgroup", {"label": t("Na coleção")}, html) + el("optgroup", {"label": t("Fora da coleção")}, fora.map(function (x) { return opcao(x, " (" + h(x.posse_texto.toLowerCase()) + ")"); }).join(""));
  }
  document.getElementById("troca").innerHTML = html;
  document.getElementById("sem-relogios").hidden = todos.length > 0;
  if (id > 0) {
    abrirNoPainel(id);
  }
}); });
</script>
<?php rodape(["api.js", "foto.js", "painel.js"]); ?>
