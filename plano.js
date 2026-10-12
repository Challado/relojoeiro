// Página Plano: o plano gravado de hoje em diante, com filtro de período e de relógio, o resumo de quantos dias cada relógio
// tem no período (o período e o resumo vêm da API: recurso=plano, dias=N), em cada dia o "trocar por…" (recurso=rodizio,
// acao=trocar_dia). Os relógios de um período, dia a dia, se escolhem no Rodízio manual (quadro Modo de rodízio, na
// página Hoje). Remontada depois de cada troca.
var MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
var SEMANA = ["dom", "seg", "ter", "qua", "qui", "sex", "sáb"];
var filtro = {dias: "90", relogio: "", pagina: 1};
var dados = null;
var recado = "";

function montarPlano() {
  var d = dados;
  // o dia a dia vem da API uma página por vez (o "Mostrar até" da Configuração), já com os dias livres e o filtro de relógio
  var dd = d.dia_a_dia;
  var mostrados = dd.dias;
  var ultimo = d.plano_fim;

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

  // apagar o que um período tem (e sortear de novo, ou deixar livre); escolher os relógios dia a dia é no Rodízio manual
  res += el("section", {"class": "cartao-config apagar-plano"}, formApagarPeriodo(d.hoje) + formApagarTudo()
    + el("p", {"class": "nota"}, "Para escolher os relógios de um período, dia a dia: na página " + el("a", {"href": "index.php#manual"}, "Hoje")
      + ", no quadro Modo de rodízio, escolha \"Rodízio manual\"."));

  // o resumo, da API: quantos dias cada relógio tem no período, a porcentagem, o próximo dia e quantos foram à mão
  var rs = d.resumo;
  res += el("section", {}, el("h2", {}, "Resumo do período (" + rs.dias + (rs.dias === 1 ? " dia)" : " dias)"))
    + (rs.relogios.length === 0 ? el("p", {}, "Nenhum dia no plano neste período.")
      : el("table", {"class": "relogios resumo-plano"}, el("thead", {}, el("tr", {}, el("th", {}, "Relógio") + el("th", {"class": "num"}, "Dias") + el("th", {"class": "num"}, "%")
        + el("th", {}, "Próximo dia") + el("th", {"class": "num"}, "À mão")))
        + el("tbody", {}, rs.relogios.map(function (c) {
          return el("tr", {}, el("td", {}, h(c.relogio)) + el("td", {"class": "num"}, String(c.dias)) + el("td", {"class": "num"}, num(c.porcentagem, 1))
            + el("td", {}, dataBr(c.proximo) + " " + el("small", {}, SEMANA[new Date(instante(c.proximo)).getDay()])) + el("td", {"class": "num"}, c.a_mao > 0 ? String(c.a_mao) : ""));
        }).join(""))))
    + (rs.sem_dias.length > 0 ? el("p", {"class": "nota"}, "Sem nenhum dia no período: " + rs.sem_dias.map(function (r) { return h(r.nome); }).join(", ") + ".") : ""));

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
    linhas += el("tr", {"class": (p.dia_semana >= 6 ? "fds" : "") + (p.hoje ? " de-hoje" : "")},
      el("td", {"class": "dia"}, dataBr(p.data, true) + " " + el("small", {}, p.dia_semana_curto) + (p.hoje ? " " + el("small", {}, "hoje") : ""))
      + el("td", {}, celulaDoDia(p)) + el("td", {}, h(p.acao || ""))
      + el("td", {"class": "trocar"}, formTrocarDia(p, d.relogios, d.hoje)));
  });
  fecha();
  res += el("section", {}, el("h2", {}, "Dia a dia" + (filtro.relogio !== "" ? " (só os dias do relógio escolhido)" : ""))
    + (mostrados.length === 0 ? el("p", {}, !d.plano_fim ? "Neste modo o sorteio é feito semana a semana: o plano tem só a semana atual." : "Nenhum dia.") : meses)
    + navPaginas(dd.pagina, dd.paginas, "plano-pagina")
    + (dd.paginas > 1 ? el("p", {"class": "nota"}, "Página " + dd.pagina + " de " + dd.paginas + " · " + dd.total + " dias, " + dd.por_pagina + " por página (o \"Mostrar até\" da "
      + el("a", {"href": "configuracao.php"}, "Configuração") + ").") : ""));

  var main = document.getElementById("plano");
  main.innerHTML = res;
  document.getElementById("f-dias").addEventListener("change", function () { filtro.dias = this.value; filtro.pagina = 1; recarregarPlano(); });
  document.getElementById("f-relogio").addEventListener("change", function () { filtro.relogio = this.value; filtro.pagina = 1; recarregarPlano(); });
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
