// Página Plano: o plano gravado de hoje em diante, com filtro de período e de relógio, o resumo de quantos dias cada relógio
// tem no período e, em cada dia, o "trocar por…" (recurso=rodizio, acao=trocar_dia). Remontada depois de cada troca.
var MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
var SEMANA = ["dom", "seg", "ter", "qua", "qui", "sex", "sáb"];
var filtro = {dias: "90", relogio: ""};
var dados = null;
var recado = "";

// a data AAAA-MM-DD somada de n dias
function somaDias(data, n) {
  var d = new Date(instante(data));
  d.setDate(d.getDate() + n);
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, "0"), String(d.getDate()).padStart(2, "0")].join("-");
}

function montarPlano() {
  var d = dados;
  var ate = filtro.dias === "" ? "9999-12-31" : somaDias(d.hoje, Number(filtro.dias) - 1);
  var dias = d.plano.filter(function (p) { return p.data >= d.hoje && p.data <= ate; });
  var mostrados = dias.filter(function (p) { return filtro.relogio === "" || String(p.relogio_id) === filtro.relogio; });
  var ultimo = d.plano.length > 0 ? d.plano[d.plano.length - 1].data : null;

  var res = el("h1", {}, "Plano") + el("p", {"class": "nota"}, "Modo: " + h(d.modo || "nenhum")
    + (d.escala_fim ? " · a escala vai até " + dataBr(d.escala_fim) : "") + (ultimo ? " · o plano gravado vai até " + dataBr(ultimo) : "")
    + ". Troque o relógio de qualquer dia em \"trocar por…\": o dia fica escolhido à mão" + (d.escala_fim ? " e a escala é refeita a partir dele" : "") + ".");
  if (recado !== "") {
    res += el("p", {"class": "acao"}, h(recado));
  }
  // os filtros
  var periodos = [["30", "Próximos 30 dias"], ["90", "Próximos 90 dias"], ["365", "Próximo ano"], ["730", "Próximos 2 anos"], ["", "Tudo"]];
  res += el("form", {"class": "filtros-plano", "onsubmit": "return false"},
    el("label", {}, "Período " + el("select", {"id": "f-dias"}, periodos.map(function (o) { return el("option", {"value": o[0], "selected": filtro.dias === o[0]}, o[1]); }).join("")))
    + el("label", {}, "Relógio " + el("select", {"id": "f-relogio"}, el("option", {"value": ""}, "Todos") + d.relogios.map(function (r) {
      return el("option", {"value": String(r.id), "selected": filtro.relogio === String(r.id)}, h(r.nome));
    }).join(""))));

  // o resumo: quantos dias cada relógio tem no período, e quando é o próximo
  var conta = {};
  dias.forEach(function (p) {
    var c = conta[p.relogio_id] || (conta[p.relogio_id] = {nome: p.relogio, dias: 0, proximo: p.data, manuais: 0});
    c.dias++;
    c.manuais += p.origem === "manual" ? 1 : 0;
  });
  var ids = Object.keys(conta).sort(function (a, b) { return conta[b].dias - conta[a].dias || conta[a].nome.localeCompare(conta[b].nome); });
  var semDia = d.relogios.filter(function (r) { return r.disponivel && !conta[r.id]; });
  res += el("section", {}, el("h2", {}, "Resumo do período (" + dias.length + (dias.length === 1 ? " dia)" : " dias)"))
    + (ids.length === 0 ? el("p", {}, "Nenhum dia no plano neste período.")
      : el("table", {"class": "relogios resumo-plano"}, el("thead", {}, el("tr", {}, el("th", {}, "Relógio") + el("th", {"class": "num"}, "Dias") + el("th", {"class": "num"}, "%")
        + el("th", {}, "Próximo dia") + el("th", {"class": "num"}, "À mão")))
        + el("tbody", {}, ids.map(function (id) {
          var c = conta[id];
          return el("tr", {}, el("td", {}, h(c.nome)) + el("td", {"class": "num"}, String(c.dias)) + el("td", {"class": "num"}, num(c.dias * 100 / dias.length, 1))
            + el("td", {}, dataBr(c.proximo) + " " + el("small", {}, SEMANA[new Date(instante(c.proximo)).getDay()])) + el("td", {"class": "num"}, c.manuais > 0 ? String(c.manuais) : ""));
        }).join(""))))
    + (semDia.length > 0 ? el("p", {"class": "nota"}, "Sem nenhum dia no período: " + semDia.map(function (r) { return h(r.nome); }).join(", ") + ".") : ""));

  // os dias, mês a mês
  var meses = "";
  var mes = "";
  var linhas = "";
  var fecha = function () {
    if (mes !== "") {
      var p = mes.split("-");
      var nome = MESES[Number(p[1]) - 1];
      meses += el("h3", {}, nome.charAt(0).toUpperCase() + nome.slice(1) + " de " + p[0]) + el("table", {"class": "relogios dias-plano"}, el("tbody", {}, linhas));
    }
  };
  mostrados.forEach(function (p) {
    if (p.data.substr(0, 7) !== mes) {
      fecha();
      mes = p.data.substr(0, 7);
      linhas = "";
    }
    var dia = new Date(instante(p.data)).getDay();
    linhas += el("tr", {"class": (dia === 0 || dia === 6 ? "fds" : "") + (p.data === d.hoje ? " de-hoje" : "")},
      el("td", {"class": "dia"}, dataBr(p.data, true) + " " + el("small", {}, SEMANA[dia]) + (p.data === d.hoje ? " " + el("small", {}, "hoje") : ""))
      + el("td", {}, h(p.relogio) + (p.origem === "manual" ? " " + el("small", {"class": "a-mao"}, "à mão") : "")) + el("td", {}, h(p.acao || ""))
      + el("td", {"class": "trocar"}, formTrocarDia(p, d.relogios, d.hoje)));
  });
  fecha();
  res += el("section", {}, el("h2", {}, "Dia a dia" + (filtro.relogio !== "" ? " (só os dias do relógio escolhido)" : ""))
    + (mostrados.length === 0 ? el("p", {}, d.plano.length === 0 ? "Neste modo o sorteio é feito semana a semana: o plano tem só a semana atual." : "Nenhum dia.") : meses));

  var main = document.getElementById("plano");
  main.innerHTML = res;
  document.getElementById("f-dias").addEventListener("change", function () { filtro.dias = this.value; montarPlano(); });
  document.getElementById("f-relogio").addEventListener("change", function () { filtro.relogio = this.value; montarPlano(); });
}

function recarregarPlano() {
  return api({recurso: "plano"}).then(function (d) {
    dados = d;
    montarPlano();
  });
}

aoGravar = function (form, res) {
  recado = textoResposta(res);
  recarregarPlano();
};

recarregarPlano();
