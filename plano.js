// Página Plano: o plano gravado de hoje em diante, com filtro de período e de relógio, o resumo de quantos dias cada relógio
// tem no período (o período e o resumo vêm da API: recurso=plano, dias=N), em cada dia o "trocar por…" (recurso=rodizio,
// acao=trocar_dia). Os relógios de um período, dia a dia, se escolhem no Rodízio manual (quadro Modo de rodízio, na
// página Hoje). Remontada depois de cada troca.
var MESES = [t("janeiro"), t("fevereiro"), t("março"), t("abril"), t("maio"), t("junho"), t("julho"), t("agosto"), t("setembro"), t("outubro"), t("novembro"), t("dezembro")];
var SEMANA = [t("dom"), t("seg"), t("ter"), t("qua"), t("qui"), t("sex"), t("sáb")];
var filtro = {dias: "90", relogio: "", pagina: 1};
var dados = null;
var recado = "";

function montarPlano() {
  var d = dados;
  // o dia a dia vem da API uma página por vez (o "Mostrar até" da Configuração), já com os dias livres e o filtro de relógio
  var dd = d.dia_a_dia;
  var mostrados = dd.dias;
  var ultimo = d.plano_fim;

  var res = el("h1", {}, t("Plano")) + el("p", {"class": "nota"}, t("Modo: {1}{2}{3}. Troque o relógio de qualquer dia em \"trocar por…\": o dia fica escolhido à mão{4}.", {1: h(d.modo || t("nenhum")), 2: d.escala_fim ? t(" · a escala vai até {1}", {1: dataBr(d.escala_fim)}) : "", 3: ultimo ? t(" · o plano gravado vai até {1}", {1: dataBr(ultimo)}) : "", 4: d.escala_fim ? t(" e a escala é refeita a partir dele") : ""}));
  if (recado !== "") {
    res += el("p", {"class": "acao"}, h(recado));
  }
  // os filtros
  var periodos = [["30", t("Próximos 30 dias")], ["90", t("Próximos 90 dias")], ["365", t("Próximo ano")], ["730", t("Próximos 2 anos")], ["", t("Tudo")]];
  res += el("form", {"class": "filtros-plano", "onsubmit": "return false"},
    el("label", {}, t("Período {1}", {1: el("select", {"id": "f-dias"}, periodos.map(function (o) { return el("option", {"value": o[0], "selected": filtro.dias === o[0]}, o[1]); }).join(""))}))
    + el("label", {}, t("Relógio {1}", {1: el("select", {"id": "f-relogio"}, el("option", {"value": ""}, t("Todos")) + d.relogios.map(function (r) {
      return el("option", {"value": String(r.id), "selected": filtro.relogio === String(r.id)}, h(r.nome));
    }).join(""))})));

  // escolher os relógios de um período, dia a dia, é no Rodízio manual (o apagar fica no rodapé da página)
  res += el("p", {"class": "nota"}, t("Para escolher os relógios de um período, dia a dia: na página {1}, no quadro Modo de rodízio, escolha \"Rodízio manual\". Para apagar a programação, veja o fim desta página.", {1: el("a", {"href": "index.php#manual"}, t("Hoje"))}));

  // o resumo, da API: quantos dias cada relógio tem no período, a porcentagem, o próximo dia e quantos foram à mão
  var rs = d.resumo;
  res += el("section", {}, el("h2", {}, t("Resumo do período ({1}{2})", {1: rs.dias, 2: rs.dias === 1 ? t(" dia") : t(" dias")}))
    + (rs.relogios.length === 0 ? el("p", {}, t("Nenhum dia no plano neste período."))
      : el("table", {"class": "relogios resumo-plano"}, el("thead", {}, el("tr", {}, el("th", {}, t("Relógio")) + el("th", {"class": "num"}, t("Dias")) + el("th", {"class": "num"}, "%")
        + el("th", {}, t("Próximo dia")) + el("th", {"class": "num"}, t("À mão"))))
        + el("tbody", {}, rs.relogios.map(function (c) {
          return el("tr", {}, el("td", {}, h(c.relogio)) + el("td", {"class": "num"}, String(c.dias)) + el("td", {"class": "num"}, num(c.porcentagem, 1))
            + el("td", {}, dataBr(c.proximo) + " " + el("small", {}, SEMANA[new Date(instante(c.proximo)).getDay()])) + el("td", {"class": "num"}, c.a_mao > 0 ? String(c.a_mao) : ""));
        }).join(""))))
    + (rs.sem_dias.length > 0 ? el("p", {"class": "nota"}, t("Sem nenhum dia no período: {1}.", {1: rs.sem_dias.map(function (r) { return h(r.nome); }).join(", ")})) : ""));

  // os dias, mês a mês
  var meses = "";
  var mes = "";
  var linhas = "";
  var fecha = function () {
    if (mes !== "") {
      var p = mes.split("-");
      var nome = MESES[Number(p[1]) - 1];
      meses += el("h3", {}, nome.charAt(0).toUpperCase() + nome.slice(1) + t(" de ") + p[0]) + el("table", {"class": "relogios dias-plano"}, el("tbody", {}, linhas));
    }
  };
  mostrados.forEach(function (p) {
    if (p.data.substr(0, 7) !== mes) {
      fecha();
      mes = p.data.substr(0, 7);
      linhas = "";
    }
    linhas += el("tr", {"class": (p.dia_semana >= 6 ? "fds" : "") + (p.hoje ? " de-hoje" : "")},
      el("td", {"class": "dia"}, dataBr(p.data, true) + " " + el("small", {}, p.dia_semana_curto) + (p.hoje ? " " + el("small", {}, "hoje") : ""))
      + el("td", {}, celulaDoDia(p)) + el("td", {}, h(p.acao || ""))
      + el("td", {"class": "trocar"}, formTrocarDia(p, d.relogios, d.hoje)));
  });
  fecha();
  res += el("section", {}, el("h2", {}, t("Dia a dia{1}", {1: filtro.relogio !== "" ? t(" (só os dias do relógio escolhido)") : ""}))
    + (mostrados.length === 0 ? el("p", {}, !d.plano_fim ? t("Neste modo o sorteio é feito semana a semana: o plano tem só a semana atual.") : t("Nenhum dia.")) : meses)
    + navPaginas(dd.pagina, dd.paginas, "plano-pagina")
    + (dd.paginas > 1 ? el("p", {"class": "nota"}, t("Página {1} de {2} · {3} dias, {4} por página (o \"Mostrar até\" da {5}).", {1: dd.pagina, 2: dd.paginas, 3: dd.total, 4: dd.por_pagina, 5: el("a", {"href": "configuracao.php"}, t("Configuração"))})) : ""));

  // o rodapé: apagar os dias de um período (o modo monta de novo) e apagar tudo, depois de tudo o mais
  res += el("footer", {"class": "cartao-config apagar-plano", "id": "apagar"}, el("h2", {}, t("Apagar a programação")) + formApagarPeriodo(d.hoje) + formApagarTudo());

  var main = document.getElementById("plano");
  porHtml(main, res);
  // onchange (não addEventListener): a página se remonta sem trocar os elementos (porHtml), e o ouvinte não se repete
  document.getElementById("f-dias").onchange = function () { filtro.dias = this.value; filtro.pagina = 1; recarregarPlano(); };
  document.getElementById("f-relogio").onchange = function () { filtro.relogio = this.value; filtro.pagina = 1; recarregarPlano(); };
}

function recarregarPlano() {
  return api({recurso: "plano", dias: filtro.dias === "" ? "todos" : filtro.dias, relogio: filtro.relogio, pagina: filtro.pagina}).then(function (d) {
    dados = d;
    filtro.pagina = d.dia_a_dia.pagina;
    montarPlano();
  });
}

aoGravar = function (form, res) {
  recado = textoResposta(res);
  recarregarPlano();
};

// as páginas do dia a dia: cada uma relê a API (o resumo continua o do período inteiro)
document.addEventListener("click", function (ev) {
  var b = ev.target.closest("[data-plano-pagina]");
  if (b && !b.disabled) {
    filtro.pagina = parseInt(b.getAttribute("data-plano-pagina"), 10);
    recarregarPlano().then(function () {
      var dia = document.querySelector("#plano .dias-plano");
      if (dia) {
        dia.closest("section").scrollIntoView({block: "start"});
      }
    });
  }
});

recarregarPlano();
// a cada minuto, sozinha (api.js)
atualizarSozinho(recarregarPlano);
