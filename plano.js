// Página Plano: o plano gravado de hoje em diante, com filtro de período e de relógio, o resumo de quantos dias cada relógio
// tem no período (o período e o resumo vêm da API: recurso=plano, dias=N), em cada dia o "trocar por…" (recurso=rodizio,
// acao=trocar_dia) e a escolha dos relógios de um período (acao=periodo e periodo_sortear). Remontada depois de cada troca.
var MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
var SEMANA = ["dom", "seg", "ter", "qua", "qui", "sex", "sáb"];
var filtro = {dias: "90", relogio: ""};
var dados = null;
var recado = "";
var periodoAberto = false;

function montarPlano() {
  var d = dados;
  var mostrados = d.plano.filter(function (p) { return filtro.relogio === "" || String(p.relogio_id) === filtro.relogio; });
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

  // escolher os relógios de um período: de, até, os relógios na ordem da lista (um por dia, ou N dias seguidos cada, e depois
  // do último volta ao primeiro); e voltar a sortear os dias escolhidos à mão do período
  res += el("details", {"class": "cartao-config periodo-mao", "open": periodoAberto}, el("summary", {}, "Escolher os relógios de um período")
    + el("form", {"data-recurso": "rodizio", "class": "form-grade", "id": "form-periodo", "onsubmit": "return confirm(event.submitter && event.submitter.value === 'periodo_sortear' "
      + "? 'Os dias escolhidos à mão neste período voltam a ser sorteados. Continuar?' : 'Os dias do período ficam com estes relógios, escolhidos à mão. Continuar?')"},
      el("input", {"type": "hidden", "name": "acao", "value": "periodo"})
      + el("label", {}, "De " + el("input", {"type": "date", "name": "de", "min": d.hoje, "required": true}))
      + el("label", {}, "Até " + el("input", {"type": "date", "name": "ate", "min": d.hoje, "required": true}))
      + el("label", {}, "Dias seguidos de cada " + el("input", {"type": "number", "name": "dias_cada", "min": "1", "max": "365", "value": "1", "class": "curto"}))
      + el("label", {}, "Incluir relógio " + el("select", {"id": "periodo-incluir"}, el("option", {"value": ""}, "escolha…") + d.relogios.filter(function (r) { return r.disponivel; }).map(function (r) {
        return el("option", {"value": String(r.id)}, h(r.nome));
      }).join("")))
      + el("ol", {"id": "periodo-lista", "class": "periodo-lista", "style": "grid-column: 1 / -1"}, "")
      + el("p", {"class": "nota", "style": "grid-column: 1 / -1"}, "Os relógios entram na ordem da lista: um por dia (ou os dias seguidos de cada), e depois do último volta ao primeiro; com um só, ele fica o período inteiro. "
        + "Os dias ficam escolhidos à mão: sortear de novo não mexe neles. Para desfazer, \"Voltar a sortear o período\" (de amanhã em diante).")
      + el("div", {"class": "botoes"}, el("button", {}, "Aplicar ao período") + " " + el("button", {"class": "leve", "name": "acao", "value": "periodo_sortear", "formnovalidate": true}, "Voltar a sortear o período"))));

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
    var dia = new Date(instante(p.data)).getDay();
    linhas += el("tr", {"class": (dia === 0 || dia === 6 ? "fds" : "") + (p.data === d.hoje ? " de-hoje" : "")},
      el("td", {"class": "dia"}, dataBr(p.data, true) + " " + el("small", {}, SEMANA[dia]) + (p.data === d.hoje ? " " + el("small", {}, "hoje") : ""))
      + el("td", {}, h(p.relogio) + (p.origem === "manual" ? " " + el("small", {"class": "a-mao"}, "à mão") : "")
        + (p.motivo ? el("small", {"class": "motivo"}, h(p.motivo)) : "")) + el("td", {}, h(p.acao || ""))
      + el("td", {"class": "trocar"}, formTrocarDia(p, d.relogios, d.hoje)));
  });
  fecha();
  res += el("section", {}, el("h2", {}, "Dia a dia" + (filtro.relogio !== "" ? " (só os dias do relógio escolhido)" : ""))
    + (mostrados.length === 0 ? el("p", {}, !d.plano_fim ? "Neste modo o sorteio é feito semana a semana: o plano tem só a semana atual." : "Nenhum dia.") : meses));

  var main = document.getElementById("plano");
  main.innerHTML = res;
  document.getElementById("f-dias").addEventListener("change", function () { filtro.dias = this.value; recarregarPlano(); });
  document.getElementById("f-relogio").addEventListener("change", function () { filtro.relogio = this.value; montarPlano(); });
}

function recarregarPlano() {
  return api({recurso: "plano", dias: filtro.dias === "" ? "todos" : filtro.dias}).then(function (d) {
    dados = d;
    montarPlano();
  });
}

aoGravar = function (form, res) {
  recado = textoResposta(res);
  periodoAberto = form.id === "form-periodo" && !res.ok;
  recarregarPlano();
};

// o período: escolher um relógio na lista "Incluir relógio" põe ele no fim da ordem; o × tira
document.addEventListener("change", function (ev) {
  if (ev.target.id === "periodo-incluir" && ev.target.value !== "") {
    var sel = ev.target;
    var nome = sel.options[sel.selectedIndex].text;
    document.getElementById("periodo-lista").insertAdjacentHTML("beforeend", el("li", {}, el("input", {"type": "hidden", "name": "relogio_ids[]", "value": sel.value})
      + h(nome) + " " + el("button", {"type": "button", "class": "discreto periodo-tirar", "aria-label": "Tirar " + nome}, "×")));
    sel.value = "";
  }
});
document.addEventListener("click", function (ev) {
  if (ev.target.classList.contains("periodo-tirar")) {
    ev.target.parentNode.remove();
  }
});

recarregarPlano();
