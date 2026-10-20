// Acessórios: a lista da API (recurso=acessorios), filtrada pelo relógio do endereço (acessorios.php?relogio=3; 0: os soltos),
// e o formulário de um novo (ou do que se edita: editar=id), que grava pela API (recurso=acessorios, acao=salvar).
var q = new URLSearchParams(window.location.search);
var filtro = q.get("relogio") || "";
var editar = parseInt(q.get("editar") || "0", 10);

aoGravar = function (form, res) {
  if (!res.ok) {
    alert(textoResposta(res));
    form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
    return;
  }
  // gravou: a lista de novo, com o mesmo filtro e o formulário limpo
  window.location.href = "acessorios.php" + (filtro !== "" ? "?relogio=" + encodeURIComponent(filtro) : "");
};

api(filtro !== "" ? {recurso: "acessorios", relogio: filtro} : {recurso: "acessorios"}).then(function (d) {
  var e = null;
  d.acessorios.forEach(function (a) { if (a.id === editar) { e = a; } });
  var nomeFiltro = filtro === "0" ? "soltos" : "";
  d.relogios.forEach(function (r) { if (String(r.id) === filtro) { nomeFiltro = t("do {1}", {1: r.nome}); } });
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Acessórios")) + el("span", {"class": "nota"}, d.acessorios.length + (d.acessorios.length === 1 ? t(" acessório") : t(" acessórios"))
    + (nomeFiltro !== "" ? " " + h(nomeFiltro) : "") + (d.por_tipo.length > 0 ? " · " + d.por_tipo.map(function (t) { return t.quantos + " " + h(t.nome.toLowerCase()); }).join(", ") : "")));
  res += el("form", {"method": "get", "action": "acessorios.php", "class": "filtros-execucoes cartao-config"},
    el("label", {}, t("Relógio {1}", {1: el("select", {"name": "relogio", "onchange": "this.form.submit()"}, el("option", {"value": "", "selected": filtro === ""}, t("Todos"))
      + el("option", {"value": "0", "selected": filtro === "0"}, t("Soltos (de nenhum relógio)"))
      + d.relogios.map(function (r) { return el("option", {"value": r.id, "selected": String(r.id) === filtro}, h(r.nome)); }).join(""))}))
    + el("noscript", {}, el("button", {}, t("Filtrar"))));
  res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-acessorios"}, el("thead", {}, el("tr", {}, el("th", {}, t("Acessório")) + el("th", {}, t("Tipo"))
      + el("th", {}, t("Relógio")) + el("th", {}, t("Observação")) + el("th", {}, "")))
    + el("tbody", {}, d.acessorios.map(function (a) {
      return el("tr", {}, el("td", {}, h(a.nome)) + el("td", {}, h(a.tipo_texto))
        + el("td", {}, a.relogio_id !== null ? el("a", {"href": "ficha.php?id=" + a.relogio_id}, h(a.relogio)) : el("span", {"class": "nota"}, t("solto")))
        + el("td", {"class": "nota"}, a.observacao !== null ? h(a.observacao) : "—")
        + el("td", {"class": "acoes-linha"}, el("a", {"href": "acessorios.php?" + new URLSearchParams({relogio: filtro, editar: a.id}).toString() + "#form"}, t("Editar")) + " "
          + el("form", {"data-recurso": "acessorios", "class": "inline"},
            el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": a.id})
            + el("button", {"class": "leve discreto"}, t("Excluir")))));
    }).join("") + (d.acessorios.length === 0 ? el("tr", {}, el("td", {"colspan": "5"}, t("Nenhum acessório{1}. Cadastre abaixo.", {1: nomeFiltro !== "" ? " " + h(nomeFiltro) : ""}))) : ""))));
  // o formulário: novo (já no relógio do filtro) ou o que se edita
  var relForm = e ? (e.relogio_id === null ? "0" : String(e.relogio_id)) : (filtro !== "" ? filtro : "0");
  res += el("section", {"class": "cartao-config", "id": "form"}, el("h2", {}, e ? t("Alterar {1}", {1: h(e.nome)}) : t("Novo acessório"))
    + el("form", {"data-recurso": "acessorios", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
      + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": e ? e.nome : "", "required": true, "maxlength": "120", "placeholder": t("Pulseira de couro marrom")})}))
      + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo"}, d.tipos.map(function (t) { return el("option", {"value": t.valor, "selected": e ? e.tipo === t.valor : t.valor === "pulseira"}, h(t.nome)); }).join(""))}))
      + el("label", {}, t("Relógio {1}", {1: el("select", {"name": "relogio_id"}, el("option", {"value": "0", "selected": relForm === "0"}, t("— solto (serve a vários) —"))
        + d.relogios.map(function (r) { return el("option", {"value": r.id, "selected": String(r.id) === relForm}, h(r.nome)); }).join(""))}))
      + el("label", {}, t("Observação {1}", {1: el("textarea", {"name": "observacao", "maxlength": "1000"}, e && e.observacao !== null ? h(e.observacao) : "")}))
      + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Cadastrar")) + (e ? " " + el("a", {"href": "acessorios.php" + (filtro !== "" ? "?relogio=" + encodeURIComponent(filtro) : "")}, t("cancelar")) : ""))));
  document.getElementById("acessorios").innerHTML = res;
  if (e) {
    document.getElementById("form").scrollIntoView();
  }
}).catch(function (er) {
  document.getElementById("acessorios").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os acessórios: {1}", {1: h(er.message)}));
});
