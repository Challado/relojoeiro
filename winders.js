// Winders: a lista da API (recurso=winders), com as posições de cada winder (o programa, o relógio que está nela, a carga, o
// que cada hora ali dá e quando enche), o Pôr e o Tirar de cada posição (recurso=lancamento, o tipo marcado como winder, com
// a posição) e os formulários do winder (winders.php?editar=id) e da posição (?posicao=id; nova: ?nova_posicao=winder).
var q = new URLSearchParams(window.location.search);
var editar = parseInt(q.get("editar") || "0", 10);
var editarPos = parseInt(q.get("posicao") || "0", 10);
var novaPos = parseInt(q.get("nova_posicao") || "0", 10);

aoGravar = function (form, res) {
  if (!res.ok) {
    alert(textoResposta(res));
    form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
    return;
  }
  // gravou: a página de novo, sem formulário aberto (o Pôr e o Tirar ficam onde estavam)
  window.location.href = "winders.php" + (form.hasAttribute("data-fica") ? "#w-" + form.getAttribute("data-fica") : "");
};

// a entrada de um campo do winder ou da posição (Cadastros › Campos, para o winder ou para a posição do winder), pelo tipo
// dele; o vazio apaga o valor (e vale o padrão do campo, que aparece como dica)
function entradaWinder(c, v) {
  var nome = "valores[" + c.identificador + "]";
  var rotulo = h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "");
  if (c.multiplo) {
    var marcados = v === null ? [] : String(v).split("\n");
    return el("fieldset", {"class": "multiplo"}, el("legend", {}, rotulo) + el("input", {"type": "hidden", "name": nome + "[]", "value": ""}) + c.opcoes.map(function (o) {
      return el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": nome + "[]", "value": o, "checked": marcados.indexOf(o) >= 0}) + " " + h(o));
    }).join(""));
  }
  var entrada;
  if (c.tipo === "sim_nao" || c.tipo === "lista" || c.opcoes.length > 0) {
    var ops = c.tipo === "sim_nao" ? [["1", t("sim")], ["0", t("não")]] : c.opcoes.map(function (o) { return [o, o]; });
    entrada = el("select", {"name": nome}, el("option", {"value": ""}, c.padrao_texto !== null ? h(t("— padrão: {1}", {1: c.padrao_texto})) : "—")
      + ops.map(function (o) { return el("option", {"value": o[0], "selected": v !== null && String(v) === o[0]}, h(o[1])); }).join(""));
  } else if (c.tipo === "data") {
    entrada = el("input", {"type": "date", "name": nome, "value": v === null ? "" : v});
  } else {
    entrada = el("input", {"name": nome, "value": v === null ? "" : (c.tipo === "texto" ? v : String(v).replace(".", ",")), "inputmode": c.tipo === "texto" ? null : "decimal",
      "placeholder": c.padrao_texto !== null ? t("padrão: {1}", {1: c.padrao_texto}) : null});
  }
  return el("label", {}, rotulo + " " + entrada + (c.regra ? el("small", {}, h(c.regra)) : ""));
}

// o valor gravado de um campo (só o informado; o padrão não vai para o formulário)
function valorGravado(dados, ident) {
  var v = null;
  (dados || []).forEach(function (x) { if (x.identificador === ident && x.origem === "informado") { v = x.valor; } });
  return v;
}

// os dados de um winder ou de uma posição numa linha: "Marca: Wolf · Modelo: 1.8", com a etiqueta do padrão onde não foi informado
function dadosTexto(dados) {
  return dados.map(function (x) {
    return h(x.nome) + ": " + el("strong", {}, h(x.texto)) + (x.origem !== "informado" ? " " + el("span", {"class": "origem origem-" + x.origem}, h(x.origem_texto)) : "");
  }).join(" · ");
}

// o Pôr e o Tirar de uma posição: tirar fecha a sessão do relógio que está nela; pôr abre a sessão do tipo winder do relógio
// escolhido, já na posição (só os que não estão num winder agora)
function acaoPosicao(w, p, relogios) {
  if (p.relogio !== null) {
    return el("form", {"data-recurso": "lancamento", "class": "inline", "data-fica": w.id}, el("input", {"type": "hidden", "name": "acao", "value": "encerrar"})
      + el("input", {"type": "hidden", "name": "relogio_id", "value": p.relogio.relogio_id}) + el("input", {"type": "hidden", "name": "tipo", "value": p.relogio.tipo})
      + el("button", {"class": "leve"}, t("Tirar")));
  }
  var livres = relogios.filter(function (r) { return r.posicao_id === null; });
  if (!p.ligada || livres.length === 0) {
    return "";
  }
  return el("form", {"data-recurso": "lancamento", "class": "inline por-no-winder", "data-fica": w.id}, el("input", {"type": "hidden", "name": "acao", "value": "iniciar"})
    + el("input", {"type": "hidden", "name": "posicao_id", "value": p.id}) + el("input", {"type": "hidden", "name": "tipo", "value": livres[0].tipo})
    + el("select", {"name": "relogio_id", "aria-label": t("Relógio"), "onchange": "this.form.tipo.value = this.selectedOptions[0].getAttribute('data-tipo')"},
      livres.map(function (r) { return el("option", {"value": r.id, "data-tipo": r.tipo}, h(r.nome)); }).join(""))
    + " " + el("button", {"class": "leve"}, t("Pôr")));
}

api({recurso: "winders"}).then(function (d) {
  var ew = null;
  var ep = null;
  var wp = null;
  d.winders.forEach(function (w) {
    if (w.id === editar) { ew = w; }
    if (w.id === novaPos) { wp = w; }
    w.posicoes.forEach(function (p) { if (p.id === editarPos) { ep = p; wp = w; } });
  });
  var nPos = 0;
  var nOcup = 0;
  d.winders.forEach(function (w) { nPos += w.posicoes.length; nOcup += w.ocupadas; });
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Winders")) + el("span", {"class": "nota"}, d.winders.length + (d.winders.length === 1 ? t(" winder") : t(" winders"))
    + (nPos > 0 ? " · " + t("{1} posições, {2} com relógio", {1: nPos, 2: nOcup}) : "")));
  res += el("p", {"class": "nota"}, t("Cada winder tem as posições dele, e cada posição o programa dela (os campos da posição: as rotações por dia, o sentido, o ciclo). Quanto cada hora no winder carrega sai da fórmula winder_ganho, pelo programa da posição e pelo que o relógio pede (Cadastros › Fórmulas)."));
  // um cartão por winder: os dados e a tabela das posições
  d.winders.forEach(function (w) {
    var linhas = w.posicoes.map(function (p) {
      var rel = p.relogio;
      return el("tr", {"class": p.ligada ? "" : "desligada"}, el("td", {}, el("strong", {}, h(p.nome)) + (p.ligada ? "" : el("small", {}, t("desligada"))))
        + el("td", {"class": "programa"}, p.dados.length > 0 ? dadosTexto(p.dados) : "—")
        + el("td", {}, rel !== null ? el("a", {"href": "ficha.php?id=" + rel.relogio_id}, h(rel.relogio)) + el("small", {}, h(rel.desde_texto)) : el("span", {"class": "nota"}, p.ligada ? t("livre") : "—"))
        + el("td", {"class": "num"}, rel !== null && rel.carga !== null ? "~" + rel.carga + "%" : "—")
        + el("td", {}, rel !== null && rel.ganho_texto !== null ? h(rel.ganho_texto) : "—")
        + el("td", {}, rel !== null && rel.cheio_texto !== null ? h(rel.cheio_texto) : "—")
        + el("td", {"class": "acoes-linha"}, acaoPosicao(w, p, d.relogios) + " " + el("a", {"href": "winders.php?posicao=" + p.id + "#form-posicao"}, t("Editar"))));
    }).join("");
    res += el("section", {"class": "cartao-config winder", "id": "w-" + w.id}, el("h2", {}, h(w.nome))
      + (w.dados.length > 0 ? el("p", {"class": "dados-winder"}, dadosTexto(w.dados)) : "")
      + (w.observacao !== null ? el("p", {"class": "nota"}, h(w.observacao)) : "")
      + (w.posicoes.length > 0
        ? el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta posicoes-winder"}, el("thead", {}, el("tr", {}, el("th", {}, t("Posição")) + el("th", {}, t("Programa"))
          + el("th", {}, t("Relógio")) + el("th", {}, t("Carga")) + el("th", {}, t("Cada hora dá")) + el("th", {}, t("Enche")) + el("th", {}, ""))) + el("tbody", {}, linhas)))
        : el("p", {"class": "nota"}, t("Nenhuma posição. Crie a primeira abaixo.")))
      + el("div", {"class": "botoes"}, el("a", {"class": "botao leve", "href": "winders.php?nova_posicao=" + w.id + "#form-posicao"}, t("Nova posição"))
        + " " + el("a", {"class": "botao leve", "href": "winders.php?editar=" + w.id + "#form"}, t("Editar o winder"))
        + " " + el("form", {"data-recurso": "winders", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": w.id})
          + el("button", {"class": "leve discreto"}, t("Excluir o winder")))));
  });
  if (d.winders.length === 0) {
    res += el("p", {"class": "nota"}, t("Nenhum winder ainda. Cadastre o primeiro abaixo."));
  }
  if (d.relogios.length === 0) {
    res += el("p", {"class": "nota"}, t("Nenhum relógio pode ir para um winder: os que podem são os que têm um tipo de lançamento marcado como winder (o No winder dos automáticos)."));
  }
  // a posição: nova (no winder escolhido) ou a que se edita
  if (wp !== null) {
    res += el("section", {"class": "cartao-config", "id": "form-posicao"}, el("h2", {}, ep ? t("Alterar a posição {1} do {2}", {1: h(ep.nome), 2: h(wp.nome)}) : t("Nova posição no {1}", {1: h(wp.nome)}))
      + el("form", {"data-recurso": "winder_posicoes", "class": "form-grade", "data-fica": wp.id}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
        + (ep ? el("input", {"type": "hidden", "name": "id", "value": ep.id}) : el("input", {"type": "hidden", "name": "winder_id", "value": wp.id}))
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": ep ? ep.nome : t("Pos. {1}", {1: wp.posicoes.length + 1}), "required": true, "maxlength": "60"})}))
        + el("label", {}, t("Ordem {1}", {1: el("input", {"name": "ordem", "value": ep ? ep.ordem : wp.posicoes.length + 1, "inputmode": "numeric"})}))
        + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "ligada", "value": "0"}) + el("input", {"type": "checkbox", "name": "ligada", "value": "1", "checked": ep ? ep.ligada : true})
          + t(" ligada (desligada, não recebe relógio)"))
        + d.campos_posicao.map(function (c) { return entradaWinder(c, ep ? valorGravado(ep.dados, c.identificador) : null); }).join("")
        + el("div", {"class": "botoes"}, el("button", {}, ep ? t("Salvar") : t("Criar a posição")) + " " + el("a", {"href": "winders.php"}, t("cancelar"))))
      + (ep ? el("form", {"data-recurso": "winder_posicoes", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": ep.id})
        + el("button", {"class": "leve discreto"}, t("Excluir esta posição"))) : ""));
  }
  // o winder: novo, ou o que se edita
  res += el("section", {"class": "cartao-config", "id": "form"}, el("h2", {}, ew ? t("Alterar {1}", {1: h(ew.nome)}) : t("Novo winder"))
    + el("form", {"data-recurso": "winders", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (ew ? el("input", {"type": "hidden", "name": "id", "value": ew.id}) : "")
      + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": ew ? ew.nome : "", "required": true, "maxlength": "120", "placeholder": t("Winder do escritório")})}))
      + (ew ? "" : el("label", {}, t("Posições {1}", {1: el("input", {"name": "posicoes", "value": "4", "inputmode": "numeric"})}) + el("small", {}, t("as que ele já ganha (Pos. 1, Pos. 2...); dá para criar, renomear e excluir depois"))))
      + d.campos_winder.map(function (c) { return entradaWinder(c, ew ? valorGravado(ew.dados, c.identificador) : null); }).join("")
      + el("label", {}, t("Observação {1}", {1: el("textarea", {"name": "observacao", "maxlength": "1000"}, ew && ew.observacao !== null ? h(ew.observacao) : "")}))
      + el("div", {"class": "botoes"}, el("button", {}, ew ? t("Salvar") : t("Cadastrar")) + (ew ? " " + el("a", {"href": "winders.php"}, t("cancelar")) : ""))));
  document.getElementById("winders").innerHTML = res;
  var foco = document.getElementById((ep || (wp !== null && novaPos > 0)) ? "form-posicao" : (ew ? "form" : ""));
  if (foco) {
    foco.scrollIntoView();
  }
}).catch(function (er) {
  document.getElementById("winders").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os winders: {1}", {1: h(er.message)}));
});
