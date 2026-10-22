// Winders: a lista da API (recurso=winders). Em cada winder, o desenho (um berço por posição, com a foto do relógio que está
// nela), os dados e a tabela das posições, cada linha um formulário (recurso=winder_posicoes): o programa (os campos da
// posição), o relógio (— nenhum — tira; outro põe ou troca) e o Salvar (a página se remonta no lugar); embaixo, os formulários do winder
// (winders.php?editar=id) e da posição (?posicao=id: o nome, a ordem, ligada; nova: ?nova_posicao=winder).
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
  // gravou: com um formulário aberto pelo endereço (?editar=, ?posicao=, ?nova_posicao=), a página de novo sem ele; sem
  // nenhum, a página se remonta com a resposta, no mesmo lugar, e o recado da API aparece na caixinha flutuante (só trocar o
  // #w- do endereço não recarrega nada: a tabela ficava com o relógio antigo)
  if (window.location.search !== "") {
    window.location.href = "winders.php" + (form.hasAttribute("data-fica") ? "#w-" + form.getAttribute("data-fica") : "");
    return;
  }
  return salvarSemMover(form, res, carregarWinders);
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

// os dados de um winder em pares rótulo | valor (a tabela de duas colunas das fichas), com a etiqueta do padrão onde não foi
// informado, e a observação no fim
function tabelaDados(dados, observacao) {
  var pares = dados.map(function (x) {
    return el("dt", {}, h(x.nome)) + el("dd", {}, el("strong", {}, h(x.texto)) + (x.origem !== "informado" ? " " + el("span", {"class": "origem origem-" + x.origem}, h(x.origem_texto)) : ""));
  }).join("") + (observacao !== null ? el("dt", {}, t("Observação")) + el("dd", {}, h(observacao)) : "");
  return pares !== "" ? el("dl", {"class": "pares dados-winder"}, pares) : "";
}

// a entrada de um campo da posição na linha da tabela (o programa), ligada ao formulário da linha pelo atributo form: o
// menu dos valores possíveis (ou sim e não), ou o número; vazio volta ao padrão do campo, que o menu e a dica mostram
function entradaLinha(c, p, formId) {
  var v = valorGravado(p.dados, c.identificador);
  var nome = "valores[" + c.identificador + "]";
  var rotulo = c.nome + (c.unidade !== "" ? " (" + c.unidade + ")" : "");
  // o padrão sem a unidade, que já está no cabeçalho da coluna (no sim/não, o "sim" ou "não" do padrao_texto)
  var padrao = c.padrao_texto === null ? null : (c.tipo === "sim_nao" || c.padrao === null ? c.padrao_texto : String(c.padrao).replace(".", ","));
  if (c.multiplo) {
    var x = (p.dados || []).filter(function (y) { return y.identificador === c.identificador; })[0];
    return x ? h(x.texto) : "—";
  }
  if (c.tipo === "sim_nao" || c.tipo === "lista" || c.opcoes.length > 0) {
    var ops = c.tipo === "sim_nao" ? [["1", t("sim")], ["0", t("não")]] : c.opcoes.map(function (o) { return [o, o]; });
    return el("select", {"name": nome, "form": formId, "aria-label": rotulo}, el("option", {"value": ""}, padrao !== null ? h(t("padrão: {1}", {1: padrao})) : "—")
      + ops.map(function (o) { return el("option", {"value": o[0], "selected": v !== null && String(v) === o[0]}, h(o[1])); }).join(""));
  }
  return el("input", {"name": nome, "form": formId, "aria-label": rotulo, "class": "curto", "value": v === null ? "" : (c.tipo === "texto" ? v : String(v).replace(".", ",")),
    "inputmode": c.tipo === "texto" ? null : "decimal", "placeholder": padrao});
}

// o relógio da posição: "— nenhum —" tira quem está nela; escolher outro põe (e, se ele está noutra posição ou no winder
// sem posição, tira de lá). O que está nela fica no menu mesmo quando não está mais disponível para o rodízio
function escolhaRelogio(p, relogios, formId) {
  var atual = p.relogio !== null ? p.relogio.relogio_id : 0;
  var lista = relogios.slice();
  if (atual > 0 && lista.filter(function (r) { return r.id === atual; }).length === 0) {
    lista.unshift({id: atual, nome: p.relogio.relogio, posicao_id: p.id, posicao: null, no_winder: true});
  }
  return el("select", {"name": "relogio_id", "form": formId, "aria-label": t("Relógio")}, el("option", {"value": "0", "selected": atual === 0}, t("— nenhum —"))
    + lista.map(function (r) {
      var onde = r.posicao_id !== null && r.posicao_id !== p.id ? t(" (em {1})", {1: r.posicao}) : (r.no_winder && r.posicao_id === null ? t(" (no winder, sem posição)") : "");
      return el("option", {"value": r.id, "selected": r.id === atual}, h(r.nome + onde));
    }).join(""));
}

// o desenho do winder: a caixa com um berço redondo por posição, na grade dele; no berço ocupado, a foto do relógio (ou a
// inicial do nome) com o anel da carga em volta, girando devagar enquanto a posição está ligada, e o link da ficha; o livre
// tracejado; o desligado apagado
function desenhoWinder(w) {
  var n = w.posicoes.length;
  var colunas = n <= 2 ? Math.max(1, n) : (n <= 4 ? 2 : (n <= 6 ? 3 : 4));
  return el("div", {"class": "winder-desenho", "style": "--colunas: " + colunas, "role": "img", "aria-label": t("Desenho do {1}", {1: w.nome})}, w.posicoes.map(function (p) {
    var r = p.relogio;
    var miolo = r === null ? el("span", {"class": "berco-vazio"}, p.ligada ? t("livre") : t("desligada"))
      : (r.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + r.relogio_id + "&v=" + r.foto, "alt": ""}) : el("span", {"class": "berco-sem-foto"}, h(r.relogio.charAt(0))));
    var berco = el("span", {"class": "berco" + (r !== null ? " ocupado" : "") + (!p.ligada ? " desligado" : (r !== null ? " girando" : "")),
      "style": r !== null && r.carga !== null ? "--carga: " + Math.max(0, Math.min(100, r.carga)) : null}, el("span", {"class": "miolo"}, miolo));
    return el("figure", {}, (r !== null ? el("a", {"href": "ficha.php?id=" + r.relogio_id, "title": r.relogio}, berco) : berco)
      + el("figcaption", {}, el("strong", {}, h(p.nome)) + (r !== null ? el("small", {}, h(r.relogio) + (r.carga !== null ? " · ~" + r.carga + "%" : "")) : "")));
  }).join(""));
}

function carregarWinders() {
  return api({recurso: "winders"}).then(function (d) {
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
    res += el("p", {"class": "nota"}, t("Cada winder tem as posições dele, e cada posição o programa dela (os campos da posição: as rotações por dia, o sentido, o ciclo). Mude o programa e o relógio de uma posição direto na linha da tabela e clique em Salvar: no Relógio, — nenhum — tira quem está nela, e outro relógio entra no lugar (saindo de onde estava). Quanto cada hora no winder carrega sai da fórmula winder_ganho, pelo programa da posição e pelo que o relógio pede (Cadastros › Fórmulas); os valores possíveis de cada campo, como as rotações por dia, ficam em Cadastros › Campos."));
    res += ondeMuda("winders", [[t("O programa de uma posição (as rotações por dia, o sentido, o ciclo) e o relógio nela"), t("aqui: na linha da posição, e Salvar")],
      [t("O nome, a ordem e se a posição está ligada"), t("aqui: Editar, embaixo do nome da posição")], [t("O nome, a marca, o modelo e a observação do winder"), t("aqui: Editar o winder")],
      [t("Os valores possíveis das rotações por dia e os outros campos da posição"), t("{1}: a subtela \"as posições dos winders\"", {1: lugar("cadastros.php?aba=campos", t("Cadastros › Campos"))})],
      [t("Os campos do winder (marca, modelo...)"), t("{1}: a subtela \"os winders\"", {1: lugar("cadastros.php?aba=campos", t("Cadastros › Campos"))})],
      [t("Quanto cada hora no winder carrega"), t("{1}: a fórmula winder_ganho", {1: lugar("cadastros.php?aba=formulas", t("Cadastros › Fórmulas"))})],
      [t("Quais relógios vão para o winder"), t("{1}: o tipo marcado como winder e o vale para dele", {1: lugar("cadastros.php?aba=lancamentos", t("Cadastros › Tipos de lançamento"))})]]);
    // um cartão por winder: os dados e a tabela das posições
    d.winders.forEach(function (w) {
      var linhas = w.posicoes.map(function (p) {
        var rel = p.relogio;
        var fid = "fp-" + p.id;
        return el("tr", {"class": p.ligada ? "" : "desligada"}, el("td", {}, el("form", {"id": fid, "data-recurso": "winder_posicoes", "data-fica": w.id},
            el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": p.id}))
            + el("strong", {}, h(p.nome)) + (p.ligada ? "" : el("small", {}, t("desligada")))
            + el("small", {}, el("a", {"href": "winders.php?posicao=" + p.id + "#form-posicao", "title": t("O nome, a ordem e se a posição está ligada")}, t("Editar")))
            + el("button", {"form": fid, "class": "leve"}, t("Salvar")))
          + d.campos_posicao.map(function (c) { return el("td", {}, entradaLinha(c, p, fid)); }).join("")
          + el("td", {}, p.ligada ? escolhaRelogio(p, d.relogios, fid) + (rel !== null ? el("small", {}, h(rel.desde_texto)) : "") : el("span", {"class": "nota"}, "—"))
          + el("td", {"class": "num"}, rel !== null && rel.carga !== null ? "~" + rel.carga + "%" : "—")
          + el("td", {}, rel !== null && rel.ganho_texto !== null ? h(rel.ganho_texto) : "—")
          + el("td", {}, rel !== null && rel.cheio_texto !== null ? h(rel.cheio_texto) : "—"));
      }).join("");
      res += el("section", {"class": "cartao-config winder", "id": "w-" + w.id}, el("h2", {}, h(w.nome))
        + (w.posicoes.length > 0 ? desenhoWinder(w) : "")
        + tabelaDados(w.dados, w.observacao)
        + (w.posicoes.length > 0
          ? el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta posicoes-winder"}, el("thead", {}, el("tr", {}, el("th", {}, t("Posição"))
            + d.campos_posicao.map(function (c) { return el("th", {}, h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "")); }).join("")
            + el("th", {}, t("Relógio")) + el("th", {}, t("Carga")) + el("th", {}, t("Cada hora dá")) + el("th", {}, t("Enche")))) + el("tbody", {}, linhas)))
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
}
carregarWinders();
