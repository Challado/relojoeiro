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
        <div class="titulo-e-conta"><h2><?= t("Relógios") ?></h2> <span id="conta-relogios"></span></div>
        <div class="botoes">
          <button type="button" class="leve" id="colunas-abrir" aria-expanded="false" aria-controls="painel-colunas"><?= t("Colunas") ?></button>
          <button type="button" class="leve" id="limpar-filtros"><?= t("Limpar filtros") ?></button>
          <a href="index.php?novo=1" class="botao" data-novo><?= t("Novo relógio") ?></a>
        </div>
      </div>
      <!-- as abas por situação (da API: uma por situação do cadastro que tem relógio; hoje.js e abas.js): a tabela mostra os da aba
           escolhida; a dos desejados e as dos que saíram da coleção aparecem em #aba-conteudo, no lugar da tabela -->
      <div class="abas-faixa" id="abas-relogios" hidden></div>
      <div id="aba-conteudo" class="aba-conteudo" hidden></div>
      <!-- as colunas da tabela (o motor de colunas: hoje.js monta a lista com as colunas que a API entrega e grava a escolha da coleção) -->
      <div class="painel-colunas" id="painel-colunas" hidden></div>
      <div class="rolagem" id="rolagem-relogios">
      <table class="relogios" id="tabela-relogios">
        <thead>
          <tr>
            <th class="col-codigo" data-col="codigo"><button type="button" data-ordena="codigo" data-tipo-ordem="num"><?= t("Código") ?> <span></span></button></th>
            <th data-col="nome"><button type="button" data-ordena="nome"><?= t("Relógio") ?> <span></span></button></th>
            <th class="col-marca" data-col="marca"><button type="button" data-ordena="marca"><?= t("Marca") ?> <span></span></button></th>
            <th data-col="tipo"><button type="button" data-ordena="tipo"><?= t("Tipo") ?> <span></span></button></th>
            <th data-col="estado"><button type="button" data-ordena="estado" data-tipo-ordem="num"><?= t("Estado") ?> <span></span></button></th>
            <th data-col="carga"><button type="button" data-ordena="carga" data-tipo-ordem="num"><?= t("Carga") ?> <span></span></button></th>
            <th data-col="ultimo"><button type="button" data-ordena="ultimo" data-tipo-ordem="num"><?= t("Última vez<br> usado") ?> <span></span></button></th>
            <th data-col="uso"><button type="button" data-ordena="uso" data-tipo-ordem="num"><?= t("Tempo<br> de uso") ?> <span></span></button></th>
            <th data-col="manut"><button type="button" data-ordena="manut"><?= t("Próxima<br> manutenção") ?> <span></span></button></th>
            <th class="col-em" data-col="em"><button type="button" data-ordena="em" data-tipo-ordem="num"><?= t("Em") ?> <span></span></button></th>
            <th data-col="fazer"><button type="button" data-ordena="fazer"><?= t("O que<br> fazer") ?> <span></span></button></th>
            <th class="col-encostado" data-col="encostado"><button type="button" data-ordena="encostado" data-tipo-ordem="num"><?= t("Encostado") ?> <span></span></button></th>
            <th data-col="compra"><button type="button" data-ordena="compra"><?= t("Comprado<br> em") ?> <span></span></button></th>
            <th class="col-valor" data-col="valor"><button type="button" data-ordena="valor" data-tipo-ordem="num"><?= t("Valor") ?> <span></span></button></th>
          </tr>
          <tr class="filtros">
            <td data-col="codigo" data-rotulo="<?= te("Código") ?>"><input data-filtro="codigo" aria-label="<?= te("Filtrar por código") ?>" placeholder="<?= te("nº") ?>" class="curto"></td>
            <td data-col="nome" data-rotulo="<?= te("Relógio") ?>"><input data-filtro="nome" aria-label="<?= te("Filtrar por nome") ?>" placeholder="<?= te("buscar") ?>"></td>
            <td data-col="marca" class="col-marca" data-rotulo="<?= te("Marca") ?>"><select data-filtro="marca" aria-label="<?= te("Filtrar por marca") ?>"><option value=""><?= t("Todas") ?></option></select></td>
            <td data-col="tipo" data-rotulo="<?= te("Tipo") ?>"><select data-filtro="tipo" aria-label="<?= te("Filtrar por tipo") ?>"><option value=""><?= t("Todos") ?></option></select></td>
            <td data-col="estado" data-rotulo="<?= te("Estado") ?>"><select data-filtro="estado" aria-label="<?= te("Filtrar por estado") ?>"><option value=""><?= t("Todos") ?></option></select></td>
            <td data-col="carga" data-rotulo="<?= te("Carga") ?>"><select data-filtro="carga" aria-label="<?= te("Filtrar por carga") ?>"><option value=""><?= t("Todas") ?></option><option value="20">≤ 20%</option><option value="50">≤ 50%</option></select></td>
            <td data-col="ultimo" data-rotulo="<?= te("Última vez usado") ?>"><select data-filtro="ultimo" aria-label="<?= te("Filtrar pela última vez usado") ?>"><option value=""><?= t("Todos") ?></option><option value="7"><?= t("7 dias") ?></option><option value="mais7"><?= t("+7 dias") ?></option><option value="nunca"><?= t("nunca") ?></option></select></td>
            <td data-col="uso" data-rotulo="<?= te("Tempo de uso") ?>"><select data-filtro="uso" aria-label="<?= te("Filtrar pelo tempo de uso") ?>"><option value=""><?= t("Todos") ?></option><option value="nunca"><?= t("Nunca usado") ?></option><option value="ate1h"><?= t("até 1 hora") ?></option><option value="1ha1d"><?= t("de 1 hora a 1 dia") ?></option><option value="1da7d"><?= t("de 1 a 7 dias") ?></option><option value="mais7d"><?= t("mais de 7 dias") ?></option></select></td>
            <td data-col="manut" data-rotulo="<?= te("Próxima manutenção") ?>"><select data-filtro="manut" aria-label="<?= te("Filtrar pela próxima manutenção") ?>"><option value=""><?= t("Todas") ?></option><option value="0"><?= t("até hoje") ?></option><option value="7"><?= t("7 dias") ?></option><option value="30"><?= t("30 dias") ?></option></select></td>
            <td data-col="em" class="col-em"></td>
            <td data-col="fazer" data-rotulo="<?= te("O que fazer") ?>"><select data-filtro="fazer" aria-label="<?= te("Filtrar pelo que fazer") ?>"><option value=""><?= t("Todos") ?></option></select></td>
            <td data-col="encostado" class="col-encostado" data-rotulo="<?= te("Encostado") ?>"><select data-filtro="encostado" aria-label="<?= te("Filtrar por encostado") ?>"><option value=""><?= t("Todos") ?></option><option value="sim"><?= t("Encostados") ?></option><option value="prazo"><?= t("Dentro do prazo") ?></option><option value="sem"><?= t("sem data") ?></option></select></td>
            <td data-col="compra" data-rotulo="<?= te("Comprado em") ?>"><select data-filtro="compra" aria-label="<?= te("Filtrar pela data da compra") ?>"><option value=""><?= t("Todas") ?></option><option value="30"><?= t("30 dias") ?></option><option value="90"><?= t("90 dias") ?></option><option value="ano"><?= t("este ano") ?></option><option value="sem"><?= t("sem data") ?></option></select></td>
            <td data-col="valor" data-rotulo="<?= te("Valor") ?>"><select data-filtro="valor" aria-label="<?= te("Filtrar pelo valor") ?>"><option value=""><?= t("Todos") ?></option><option value="ate500">≤ 500</option><option value="500a1500">500–1.500</option><option value="acima1500"><?= t("&gt; 1.500") ?></option><option value="sem"><?= t("sem valor") ?></option></select></td>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <!-- os totais do rodapé (recurso=totais, parte=rodape): embaixo de cada coluna, os totais dela, calculados nos relógios
               que o filtro deixa à mostra (o tabela.js pede de novo a cada filtro) -->
          <tr class="totais-rodape" id="totais-rodape" hidden>
            <td class="col-codigo" data-col="codigo" data-coluna="codigo" data-rotulo="<?= te("Código") ?>"></td>
            <td data-col="nome" data-coluna="nome" data-rotulo="<?= te("Relógio") ?>"></td>
            <td class="col-marca" data-col="marca" data-coluna="marca" data-rotulo="<?= te("Marca") ?>"></td>
            <td data-col="tipo" data-coluna="tipo" data-rotulo="<?= te("Tipo") ?>"></td>
            <td data-col="estado" data-coluna="estado" data-rotulo="<?= te("Estado") ?>"></td>
            <td data-col="carga" data-coluna="carga" data-rotulo="<?= te("Carga") ?>"></td>
            <td data-col="ultimo" data-coluna="ultimo" data-rotulo="<?= te("Última vez usado") ?>"></td>
            <td data-col="uso" data-coluna="uso" data-rotulo="<?= te("Tempo de uso") ?>"></td>
            <td data-col="manut" data-coluna="manut" data-rotulo="<?= te("Próxima manutenção") ?>"></td>
            <td class="col-em" data-col="em" data-coluna="em" data-rotulo="<?= te("Em") ?>"></td>
            <td data-col="fazer" data-coluna="fazer" data-rotulo="<?= te("O que fazer") ?>"></td>
            <td class="col-encostado" data-col="encostado" data-coluna="encostado" data-rotulo="<?= te("Encostado") ?>"></td>
            <td data-col="compra" data-coluna="compra" data-rotulo="<?= te("Comprado em") ?>"></td>
            <td class="col-valor" data-col="valor" data-coluna="valor" data-rotulo="<?= te("Valor") ?>"></td>
          </tr>
          <tr class="totais-mais"><td colspan="14" data-colspan-colunas><a href="totais.php" class="botao leve" id="ver-totais"><?= t("Ver todos os totais") ?></a> <a href="cadastros.php?aba=totais" class="nota"><?= t("montar os totais") ?></a> <span class="nota" id="totais-recado"></span></td></tr>
        </tfoot>
      </table>
      </div>
      <p class="nota" id="tabela-vazia" hidden><?= t("Nenhum relógio com esses filtros.") ?></p>
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
    // o tipo do modo escolhido: o critério se escolhe nos tipos por semana, semana e fim de semana e por dia da semana; na
    // escala, no aleatório e no personalizado ele é fixo (a maior nota, a mesma chance, o roteiro)
    var tipo = (JSON.parse(grade.getAttribute("data-tipos") || "{}"))[modo.value] || "";
    var fixo = tipo === "escala" || tipo === "aleatorio" || tipo === "personalizado";
    var forma = (JSON.parse(grade.getAttribute("data-selecoes") || "{}"))[modo.value];
    var textos = {escala: forma === "ponderado" ? t("pelo sorteio pela nota") : (forma === "menos_usado" ? t("pelo menos usado") : (forma === "fifo" ? t("pela fila") : t("pela maior nota"))),
      aleatorio: t("todos com a mesma chance"), personalizado: t("pelo roteiro do modo")};
    document.querySelectorAll("[data-com-selecao]").forEach(function (e) { e.hidden = fixo; });
    document.querySelectorAll("[data-sem-selecao]").forEach(function (e) {
      e.hidden = !fixo || (e.hasAttribute("data-explica-tipo") && e.getAttribute("data-explica-tipo") !== tipo);
    });
    var texto = document.querySelector("[data-sem-selecao-texto]");
    if (texto && fixo) {
      texto.textContent = textos[tipo];
    }
    document.querySelectorAll("[data-explica-selecao]").forEach(function (e) {
      e.hidden = fixo || e.getAttribute("data-explica-selecao") !== sel.value;
    });
    var garantia = document.querySelector("[data-garantia]");
    if (garantia) {
      garantia.hidden = tipo === "aleatorio" || (!fixo && sel.value === "aleatorio");
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
    // o "Rodízio manual" (não é um modo): no lugar da forma de escolha, do Aplicar e do Sortear de novo, o período dia a dia
    var manual = modo.value === "manual";
    document.querySelectorAll("[data-nao-manual]").forEach(function (e) {
      e.hidden = manual;
    });
    document.querySelectorAll("[data-so-manual]").forEach(function (e) {
      e.hidden = !manual;
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
<?php rodape(["api.js", "foto.js", "painel.js", "tabela.js", "abas.js", "hoje.js"]); ?>
