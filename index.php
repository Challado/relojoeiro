<?php
// Hoje, como no sistema antigo: no meio, o relógio do dia, os avisos de hoje, os próximos dias, o modo de rodízio e a
// tabela dos relógios; à direita, o painel com tudo sobre o relógio escolhido. Esta página só confere o login e traz o
// esqueleto: os dados vêm do api.php (recurso=hoje e recurso=ficha), montados pelo hoje.js e pelo painel.js, e as
// gravações vão para o api.php.
require_once __DIR__ . "/pagina.php";
topo("Relógio de hoje", "index.php");
?>

<div class="painel sem-selecao">

  <!-- meio: rodízio atual e a tabela dos relógios -->
  <main class="col-hoje">
    <p class="acao" id="recado-hoje" hidden></p>
    <section class="hoje" id="bloco-hoje"></section>

    <div id="bloco-avisos"></div>

    <section id="bloco-proximos"></section>

    <!-- modo de rodízio: fechado mostra o modo atual; aberto, edita -->
    <section class="modo-rodizio" id="bloco-modo"></section>

    <section>
      <div class="titulo-secao">
        <div class="titulo-e-conta"><h2>Relógios</h2> <span id="conta-relogios"></span></div>
        <div class="botoes">
          <button type="button" class="leve" id="limpar-filtros">Limpar filtros</button>
          <a href="index.php?novo=1" class="botao" data-novo>Novo relógio</a>
        </div>
      </div>
      <div class="rolagem" id="rolagem-relogios">
      <table class="relogios" id="tabela-relogios">
        <thead>
          <tr>
            <th class="col-codigo"><button type="button" data-ordena="codigo" data-tipo-ordem="num">Código <span></span></button></th>
            <th><button type="button" data-ordena="nome">Relógio <span></span></button></th>
            <th><button type="button" data-ordena="tipo">Tipo <span></span></button></th>
            <th><button type="button" data-ordena="estado" data-tipo-ordem="num">Estado <span></span></button></th>
            <th><button type="button" data-ordena="carga" data-tipo-ordem="num">Carga <span></span></button></th>
            <th><button type="button" data-ordena="ultimo" data-tipo-ordem="num">Última vez<br> usado <span></span></button></th>
            <th><button type="button" data-ordena="manut">Próxima<br> manutenção <span></span></button></th>
            <th class="col-em"><button type="button" data-ordena="em" data-tipo-ordem="num">Em <span></span></button></th>
            <th><button type="button" data-ordena="fazer">O que<br> fazer <span></span></button></th>
            <th><button type="button" data-ordena="compra">Comprado<br> em <span></span></button></th>
            <th class="col-valor"><button type="button" data-ordena="valor" data-tipo-ordem="num">Valor <span></span></button></th>
          </tr>
          <tr class="filtros">
            <td data-rotulo="Código"><input data-filtro="codigo" aria-label="Filtrar por código" placeholder="nº" class="curto"></td>
            <td data-rotulo="Relógio"><input data-filtro="nome" aria-label="Filtrar por nome" placeholder="buscar"></td>
            <td data-rotulo="Tipo"><select data-filtro="tipo" aria-label="Filtrar por tipo"><option value="">Todos</option></select></td>
            <td data-rotulo="Estado"><select data-filtro="estado" aria-label="Filtrar por estado"><option value="">Todos</option><option>Em uso</option><option>Em repouso</option><option>Indisponível</option></select></td>
            <td data-rotulo="Carga"><select data-filtro="carga" aria-label="Filtrar por carga"><option value="">Todas</option><option value="20">≤ 20%</option><option value="50">≤ 50%</option></select></td>
            <td data-rotulo="Última vez usado"><select data-filtro="ultimo" aria-label="Filtrar pela última vez usado"><option value="">Todos</option><option value="7">7 dias</option><option value="mais7">+7 dias</option><option value="nunca">nunca</option></select></td>
            <td data-rotulo="Próxima manutenção"><select data-filtro="manut" aria-label="Filtrar pela próxima manutenção"><option value="">Todas</option><option value="0">até hoje</option><option value="7">7 dias</option><option value="30">30 dias</option></select></td>
            <td class="col-em"></td>
            <td data-rotulo="O que fazer"><select data-filtro="fazer" aria-label="Filtrar pelo que fazer"><option value="">Todos</option></select></td>
            <td data-rotulo="Comprado em"><select data-filtro="compra" aria-label="Filtrar pela data da compra"><option value="">Todas</option><option value="30">30 dias</option><option value="90">90 dias</option><option value="ano">este ano</option><option value="sem">sem data</option></select></td>
            <td data-rotulo="Valor"><select data-filtro="valor" aria-label="Filtrar pelo valor"><option value="">Todos</option><option value="ate500">≤ 500</option><option value="500a1500">500–1.500</option><option value="acima1500">&gt; 1.500</option><option value="sem">sem valor</option></select></td>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr><td colspan="11"><span class="rotulo-total">Total dos relógios mostrados</span> <span id="total-valor"></span></td></tr>
        </tfoot>
      </table>
      </div>
      <p class="nota" id="tabela-vazia" hidden>Nenhum relógio com esses filtros.</p>
    </section>


  </main>

  <!-- direita: tudo sobre o relógio selecionado, editável -->
  <aside class="col-detalhe" id="detalhe" hidden></aside>

</div>

<script>
// campos do modo escolhido (a escuta é no documento: continua valendo depois que o bloco é remontado sem recarregar)
// a explicação e os parâmetros da forma de escolha: a garantia de rodízio só aparece onde vale (inteligente, com sorteio e escala)
function mostrarSelecao() {
  var modo = document.querySelector("select[name=modo]");
  var sel = document.querySelector("select[name=selecao]");
  var grade = document.querySelector(".modo-grade");
  if (modo && sel && grade) {
    var escala = JSON.parse(grade.getAttribute("data-escalas")).indexOf(parseInt(modo.value, 10)) >= 0;
    document.querySelectorAll("[data-com-selecao]").forEach(function (e) { e.hidden = escala; });
    document.querySelectorAll("[data-sem-selecao]").forEach(function (e) { e.hidden = !escala; });
    document.querySelectorAll("[data-explica-selecao]").forEach(function (e) {
      e.hidden = escala || e.getAttribute("data-explica-selecao") !== sel.value;
    });
    var garantia = document.querySelector("[data-garantia]");
    if (garantia) {
      garantia.hidden = !(escala || sel.value === "inteligente" || sel.value === "ponderado");
    }
  }
}
// campos e explicação do modo escolhido, e a forma de escolha salva dele
function mostrarModo() {
  var modo = document.querySelector("select[name=modo]");
  if (modo) {
    document.querySelectorAll("fieldset[data-modo]").forEach(function (f) {
      f.hidden = f.getAttribute("data-modo") !== modo.value;
      // só os campos do modo escolhido vão no envio
      f.disabled = f.hidden;
    });
    document.querySelectorAll("[data-explica-modo]").forEach(function (e) {
      e.hidden = e.getAttribute("data-explica-modo") !== modo.value;
    });
    // lidas do próprio quadro, que é trocado depois de cada envio (o script, não)
    var grade = document.querySelector(".modo-grade");
    var salvas = grade ? JSON.parse(grade.getAttribute("data-selecoes")) : {};
    var sel = document.querySelector("select[name=selecao]");
    if (sel && salvas[modo.value]) {
      sel.value = salvas[modo.value];
    }
    mostrarSelecao();
  }
}
document.addEventListener("change", function (ev) {
  if (ev.target.matches("select[name=modo]")) {
    mostrarModo();
  }
  if (ev.target.matches("select[name=selecao]")) {
    mostrarSelecao();
  }
  if (ev.target.matches(".sortear-de-novo select")) {
    ev.target.closest("form").querySelector("[data-explica-sorteio]").textContent = ev.target.options[ev.target.selectedIndex].getAttribute("data-explica");
  }
});
</script>
<?php rodape(["api.js", "foto.js", "painel.js", "tabela.js", "hoje.js"]); ?>
