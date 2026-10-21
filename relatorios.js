// Relatórios: a porta única. O catálogo vem da API (recurso=relatorios): o menu dos relatórios, por grupo, os parâmetros
// do escolhido (o relógio, o período, as opções) e a descrição dele. Gerar pede o relatório pronto (recurso=relatorios,
// tipo=...) e monta com o relatorioHtml do api.js, o mesmo das outras telas. A escolha fica no endereço
// (relatorios.php?tipo=servicos&relogio=3): o link abre o relatório já gerado, e as outras telas apontam para ele.
var catalogo = null;
var pedidoRelatorio = new URLSearchParams(window.location.search);

function relatorioEscolhido(tipo) {
  return (catalogo.relatorios.filter(function (x) { return x.tipo === tipo; })[0]) || null;
}

// um parâmetro: o rótulo em cima do campo (os parâmetros ficam lado a lado, numa linha que quebra onde não cabe)
function parametro(rotulo, controle) {
  return el("label", {"class": "parametro"}, h(rotulo) + controle);
}

// os campos de um parâmetro, com o valor do endereço (ou o padrão da API)
function campoParametro(p) {
  var v = pedidoRelatorio.get(p.nome);
  if (v === null) {
    v = p.padrao || "";
  }
  if (p.tipo === "relogio") {
    return parametro(p.rotulo, el("select", {"name": p.nome, "required": p.obrigatorio},
      el("option", {"value": ""}, p.obrigatorio ? t("Escolha o relógio") : h(p.vazio || ""))
      + catalogo.relogios.map(function (r) { return el("option", {"value": r.id, "selected": String(r.id) === v}, h(r.nome)); }).join("")));
  }
  if (p.tipo === "opcoes") {
    return parametro(p.rotulo, el("select", {"name": p.nome}, p.opcoes.map(function (o) {
      return el("option", {"value": o.valor, "selected": o.valor === v}, h(o.nome));
    }).join("")));
  }
  return parametro(p.rotulo, el("input", {"type": "date", "name": p.nome, "value": v}));
}

function formRelatorio() {
  var tipo = pedidoRelatorio.get("tipo") || "";
  var grupos = [];
  catalogo.relatorios.forEach(function (x) {
    if (grupos.indexOf(x.grupo) < 0) {
      grupos.push(x.grupo);
    }
  });
  var opcoes = el("option", {"value": ""}, t("Escolha um relatório")) + grupos.map(function (g) {
    return el("optgroup", {"label": g}, catalogo.relatorios.filter(function (x) { return x.grupo === g; }).map(function (x) {
      return el("option", {"value": x.tipo, "selected": x.tipo === tipo}, h(x.nome));
    }).join(""));
  }).join("");
  var esc = relatorioEscolhido(tipo);
  // os parâmetros numa linha só; as datas seguidas (o período: de e até) juntas, sem quebrar entre elas; o botão no fim
  var partes = [];
  (esc ? esc.parametros : []).forEach(function (p) {
    var anterior = partes[partes.length - 1];
    if (p.tipo === "data" && anterior && anterior.data) {
      anterior.html.push(campoParametro(p));
    } else {
      partes.push({data: p.tipo === "data", html: [campoParametro(p)]});
    }
  });
  return el("form", {"class": "form-relatorio barra-impressao cartao-config", "id": "form-relatorio", "onsubmit": "return gerarRelatorio(this)"},
    parametro(t("Relatório"), el("select", {"name": "tipo", "required": true, "onchange": "trocarRelatorio(this.value)"}, opcoes))
    + (esc ? el("p", {"class": "nota"}, h(esc.descricao)) : "")
    + el("div", {"class": "linha-parametros"}, partes.map(function (x) {
      return x.data && x.html.length > 1 ? el("span", {"class": "periodo"}, x.html.join("")) : x.html.join("");
    }).join("") + el("div", {"class": "botoes"}, el("button", {"disabled": !esc}, t("Gerar o relatório")))));
}

// outro relatório no menu: os parâmetros dele (os do anterior saem do endereço)
function trocarRelatorio(tipo) {
  pedidoRelatorio = new URLSearchParams(tipo ? {tipo: tipo} : {});
  document.getElementById("relatorio-form").innerHTML = formRelatorio();
  document.getElementById("relatorio").innerHTML = "";
}

function gerarRelatorio(form) {
  var p = {tipo: form.tipo.value};
  Array.prototype.forEach.call(form.elements, function (c) {
    if (c.name && c.name !== "tipo" && c.value !== "") {
      p[c.name] = c.value;
    }
  });
  pedidoRelatorio = new URLSearchParams(p);
  history.replaceState(null, "", "relatorios.php?" + pedidoRelatorio.toString());
  mostrarRelatorio();
  return false;
}

function mostrarRelatorio() {
  var alvo = document.getElementById("relatorio");
  var p = {recurso: "relatorios"};
  pedidoRelatorio.forEach(function (v, k) { p[k] = v; });
  alvo.innerHTML = el("p", {"class": "nota"}, t("Gerando…"));
  return api(p).then(function (r) {
    alvo.innerHTML = relatorioHtml(r);
    document.title = r.titulo;
  }).catch(function (e) {
    alvo.innerHTML = el("p", {"class": "alerta-cron"}, t("Não consegui gerar o relatório: {1}", {1: h(e.message)}));
  });
}

api({recurso: "relatorios"}).then(function (d) {
  catalogo = d;
  document.getElementById("relatorio-form").innerHTML = formRelatorio();
  if (pedidoRelatorio.get("tipo") && relatorioEscolhido(pedidoRelatorio.get("tipo"))) {
    mostrarRelatorio();
  }
}).catch(function (e) {
  document.getElementById("relatorio-form").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler {1}: {2}", {1: t("os relatórios"), 2: h(e.message)}));
});
