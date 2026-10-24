// Manutenção: as quatro abas (manutencao.php?aba=abertas, todas, oficinas, listas), com os dados da API (recurso=manutencao
// e listas) e as gravações nela (recurso=manutencao, oficinas e listas, acao=salvar, situacao ou excluir). Sem manutenção
// cadastrada, o relógio não vai a lugar nenhum: o "Cadastrar uma manutenção" o põe na oficina (fora do rodízio até voltar).
var q = new URLSearchParams(window.location.search);
var editar = parseInt(q.get("editar") || "0", 10);
var editarOficina = parseInt(q.get("oficina") || "0", 10);
var relogioNovo = parseInt(q.get("relogio") || "0", 10);
var aba = q.get("aba") || (editarOficina > 0 ? "oficinas" : "abertas");
var ABAS = {abertas: t("Em manutenção"), todas: t("Manutenções"), oficinas: t("Oficinas"), listas: t("Listas")};
if (!ABAS[aba]) {
  aba = "abertas";
}

aoGravar = function (form, res) {
  if (!res.ok) {
    alert(textoResposta(res));
    form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
    return;
  }
  // gravou: com um formulário aberto pelo endereço (?editar=, ?oficina=, ?relogio=), a aba de novo sem ele; sem nenhum, a
  // página se remonta no mesmo lugar, com o recado da API na caixinha flutuante
  if (editar > 0 || editarOficina > 0 || relogioNovo > 0) {
    window.location.href = "manutencao.php?aba=" + aba;
    return;
  }
  salvarSemMover(form, res, carregar);
};

// a etiqueta da situação, na cor dela
function etiquetaSituacao(m) {
  return el("span", {"class": "estado estado-manutencao", "style": m.situacao_cor ? "border-color:" + m.situacao_cor + ";color:" + m.situacao_cor : null}, h(m.situacao_texto));
}
function traco(v) {
  return v === null || v === undefined || v === "" ? el("span", {"class": "nota"}, "—") : v;
}
function dinheiro(v) {
  return v === null || v === undefined ? traco(null) : t("R$ ") + reais(v);
}
function diasTexto(n) {
  return n === 1 ? t("1 dia") : t("{1} dias", {1: n});
}

// o menu "mudar a situação" de uma manutenção (numa linha da tabela): a situação, quando e a observação
function formSituacao(d, m) {
  return el("form", {"data-recurso": "manutencao", "class": "inline situacao-manutencao"}, el("input", {"type": "hidden", "name": "acao", "value": "situacao"})
    + el("input", {"type": "hidden", "name": "id", "value": m.id})
    + el("select", {"name": "situacao", "aria-label": t("Nova situação")}, d.situacoes.map(function (s) {
      return el("option", {"value": s.valor, "selected": s.valor === m.situacao}, h(s.nome));
    }).join(""))
    + " " + el("button", {"class": "leve"}, t("Mudar")));
}

// o formulário da manutenção: nova (o relógio, a oficina, o tipo, o problema...) ou a que se edita (com a situação e a história)
function formManutencao(d, m, docs) {
  var v = function (k) { return m && m[k] !== null && m[k] !== undefined ? String(m[k]) : ""; };
  var relogios = d.relogios.filter(function (r) { return m ? r.id === m.relogio_id : r.manutencao_id === null; });
  var acessJunto = m ? m.acessorios.map(function (a) { return a.id; }) : [];
  var acess = d.acessorios.filter(function (a) { return a.relogio_id === null || a.relogio_id === (m ? m.relogio_id : relogioNovo) || acessJunto.indexOf(a.id) >= 0; });
  var docsJunto = m ? m.documentos.map(function (x) { return x.id; }) : [];
  return el("form", {"data-recurso": "manutencao", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
    + (m ? el("input", {"type": "hidden", "name": "id", "value": m.id}) + el("p", {"class": "largo"}, el("strong", {}, h(m.relogio)))
      : el("label", {}, t("Relógio {1}", {1: el("select", {"name": "relogio_id", "required": true}, el("option", {"value": ""}, t("— escolha —"))
        + relogios.map(function (r) { return el("option", {"value": r.id, "selected": r.id === relogioNovo}, h(r.nome)); }).join(""))})))
    + el("label", {}, t("Oficina {1}", {1: el("select", {"name": "oficina_id", "required": true}, d.oficinas.map(function (o) {
      return el("option", {"value": o.id, "selected": m ? o.id === m.oficina_id : !o.propria && d.oficinas.length > 1}, h(o.nome));
    }).join(""))}))
    + el("label", {}, t("Situação {1}", {1: el("select", {"name": "situacao"}, d.situacoes.map(function (s, i) {
      return el("option", {"value": s.valor, "selected": m ? s.valor === m.situacao : i === 0}, h(s.nome));
    }).join(""))}))
    + el("label", {}, t("Tipo de serviço {1}", {1: el("select", {"name": "tipo"}, d.tipos.map(function (x, i) {
      return el("option", {"value": x.valor, "selected": m ? x.valor === m.tipo : i === 0}, h(x.nome));
    }).join(""))}))
    + el("label", {}, t("Enviado {1}", {1: el("input", {"type": "date", "name": "enviado", "value": m ? m.enviado : d.hoje, "max": d.hoje, "required": true})}))
    + el("label", {}, t("Previsão de volta {1}", {1: el("input", {"type": "date", "name": "previsao", "value": v("previsao")})}))
    + el("label", {}, t("Ordem de serviço {1}", {1: el("input", {"name": "os", "value": v("os"), "maxlength": 60})}))
    + el("label", {}, t("Orçamento (R$) {1}", {1: el("input", {"name": "orcamento", "inputmode": "decimal", "value": v("orcamento").replace(".", ",")})}))
    + el("label", {}, t("Data do orçamento {1}", {1: el("input", {"type": "date", "name": "orcamento_data", "value": v("orcamento_data")})}))
    + el("label", {"class": "largo"}, t("Problema {1}", {1: el("textarea", {"name": "problema", "rows": 2, "maxlength": 2000, "placeholder": t("Atrasando 30 s por dia; a coroa não rosqueia")}, h(v("problema")))}))
    + el("label", {"class": "largo"}, t("Observação {1}", {1: el("textarea", {"name": "observacao", "rows": 2, "maxlength": 2000}, h(v("observacao")))}))
    + el("fieldset", {"class": "largo"}, el("legend", {}, t("Foi junto")) + el("input", {"type": "hidden", "name": "acessorios[]", "value": ""})
      + (acess.length === 0 ? el("p", {"class": "nota"}, t("Nenhum acessório deste relógio (ou solto) cadastrado."))
        : acess.map(function (a) { return el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "acessorios[]", "value": a.id, "checked": acessJunto.indexOf(a.id) >= 0}) + " " + h(a.nome) + " " + el("small", {}, h(a.tipo_texto))); }).join("")))
    + (docs ? el("fieldset", {"class": "largo"}, el("legend", {}, t("Documentos do envio")) + el("input", {"type": "hidden", "name": "documentos[]", "value": ""})
      + (docs.length === 0 ? el("p", {"class": "nota"}, t("Nenhum documento no relógio ainda (a OS, o orçamento, as fotos da entrega vão em Documentos)."))
        : docs.map(function (x) { return el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "documentos[]", "value": x.id, "checked": docsJunto.indexOf(x.id) >= 0}) + " " + h(x.titulo)); }).join(""))) : "")
    + (m ? "" : el("p", {"class": "nota largo"}, d.trocar_hoje ? t("Ao cadastrar, o relógio fica em manutenção: sai do rodízio, das sessões e dos avisos até voltar; se ele é o relógio de hoje, hoje fica livre para você escolher outro.")
      : t("Ao cadastrar, o relógio fica em manutenção: sai do rodízio, das sessões e dos avisos até voltar; se ele é o relógio de hoje, hoje fica sem relógio (a Configuração não deixa trocar o de hoje no próprio dia).")))
    + el("div", {"class": "botoes largo"}, el("button", {}, m ? t("Salvar a manutenção") : t("Cadastrar a manutenção"))
      + (m ? " " + el("a", {"href": "manutencao.php?aba=" + aba}, t("cancelar")) : "")));
}

// a história de uma manutenção: cada mudança de situação
function historia(m) {
  return el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Quando")) + el("th", {}, t("Situação")) + el("th", {}, t("Observação"))))
    + el("tbody", {}, m.eventos.slice().reverse().map(function (e) {
      return el("tr", {}, el("td", {}, dataBr(e.quando)) + el("td", {}, h(e.situacao_texto)) + el("td", {"class": "nota"}, e.observacao !== null ? h(e.observacao) : "—"));
    }).join(""))));
}

// os contatos de uma oficina, cada um com o link que abre (o telefone liga, o WhatsApp abre a conversa, o e-mail escreve)
function contatosTexto(o) {
  return o.contatos.length === 0 ? traco(null) : o.contatos.map(function (c) {
    var valor = c.link ? el("a", {"href": c.link, "target": c.comportamento === "link" || c.comportamento === "whatsapp" ? "_blank" : null, "rel": "noopener"}, h(c.valor)) : h(c.valor);
    return el("span", {"class": "contato" + (c.principal ? " principal" : "")}, h(c.tipo_texto) + ": " + valor + (c.pessoa !== null ? " " + el("small", {}, h(c.pessoa)) : "")
      + (c.observacao !== null ? " " + el("small", {}, h(c.observacao)) : ""));
  }).join(el("br", {}));
}

// o formulário da oficina, com os contatos (quantos quiser: o "+ contato" acrescenta uma linha)
function linhaContato(d, c, i) {
  var n = function (k) { return "contatos[" + i + "][" + k + "]"; };
  return el("tr", {"class": "linha-contato"}, el("td", {}, el("select", {"name": n("tipo"), "aria-label": t("Tipo")}, d.tipos_contato.map(function (x) {
      return el("option", {"value": x.valor, "selected": c ? c.tipo === x.valor : false}, h(x.nome));
    }).join("")))
    + el("td", {}, el("input", {"name": n("valor"), "value": c ? c.valor : "", "maxlength": 200, "aria-label": t("Número, e-mail ou endereço")}))
    + el("td", {}, el("input", {"name": n("pessoa"), "value": c && c.pessoa !== null ? c.pessoa : "", "maxlength": 120, "aria-label": t("Pessoa")}))
    + el("td", {}, el("input", {"name": n("observacao"), "value": c && c.observacao !== null ? c.observacao : "", "maxlength": 200, "aria-label": t("Observação")}))
    + el("td", {}, el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": n("principal"), "value": "1", "checked": !!(c && c.principal)}) + " " + t("principal"))));
}
function maisContato(botao) {
  var corpo = botao.closest("form").querySelector(".contatos tbody");
  var i = corpo.querySelectorAll("tr").length;
  corpo.insertAdjacentHTML("beforeend", linhaContato(window.DADOS_MANUTENCAO, null, i));
}
function formOficina(d, o) {
  var v = function (k) { return o && o[k] !== null && o[k] !== undefined ? String(o[k]) : ""; };
  var campo = function (rotulo, k, max, extra) { return el("label", {}, t("{1} {2}", {1: rotulo, 2: el("input", Object.assign({"name": k, "value": v(k), "maxlength": max}, extra || {}))})); };
  return el("form", {"data-recurso": "oficinas", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (o ? el("input", {"type": "hidden", "name": "id", "value": o.id}) : "")
    + campo(t("Nome"), "nome", 120, {"required": true, "placeholder": t("Relojoaria Central")})
    + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo"}, d.tipos_oficina.map(function (x) { return el("option", {"value": x.valor, "selected": o ? o.tipo === x.valor : x.valor === "relojoeiro"}, h(x.nome)); }).join(""))}))
    + campo(t("Endereço"), "endereco", 200) + campo(t("Número"), "numero", 20) + campo(t("Complemento"), "complemento", 80) + campo(t("Bairro"), "bairro", 80)
    + campo(t("Cidade"), "cidade", 80) + campo(t("Estado"), "uf", 40) + campo(t("CEP"), "cep", 20) + campo(t("Horário"), "horario", 200, {"placeholder": t("seg a sex, 9h às 18h")})
    + el("label", {"class": "largo"}, t("Observação {1}", {1: el("textarea", {"name": "observacao", "rows": 2, "maxlength": 2000}, h(v("observacao")))}))
    + el("fieldset", {"class": "largo"}, el("legend", {}, t("Contatos")) + el("input", {"type": "hidden", "name": "contatos", "value": ""})
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta contatos"}, el("thead", {}, el("tr", {}, el("th", {}, t("Tipo")) + el("th", {}, t("Número, e-mail ou endereço")) + el("th", {}, t("Pessoa"))
        + el("th", {}, t("Observação")) + el("th", {}, "")))
        + el("tbody", {}, (o ? o.contatos : []).concat([null]).map(function (c, i) { return linhaContato(d, c, i); }).join(""))))
      + el("button", {"type": "button", "class": "leve", "onclick": "maisContato(this)"}, t("+ contato"))
      + el("p", {"class": "nota"}, t("Os tipos de contato (Telefone, WhatsApp, E-mail, Site...) mudam na aba Listas. A linha vazia não grava.")))
    + el("div", {"class": "botoes largo"}, el("button", {}, o ? t("Salvar a oficina") : t("Cadastrar a oficina")) + (o ? " " + el("a", {"href": "manutencao.php?aba=oficinas"}, t("cancelar")) : "")));
}

function cabeca(d) {
  ondeMuda("manutencao-" + aba, [[t("Mandar um relógio para a manutenção"), t("aqui: Em manutenção › Cadastrar uma manutenção")],
    [t("Mudar a situação (orçamento, aprovado, pronto, retirado)"), t("aqui: Em manutenção, o Mudar na linha dela")],
    [t("O serviço que a volta registrou (o que foi feito, as peças, o custo)"), t("{1}: o relógio › Serviços e diagnósticos", {1: lugar("ficha.php", t("Ficha"))})],
    [t("As oficinas e os contatos"), t("aqui: Oficinas")],
    [t("As situações, os tipos de serviço, os resultados da estanqueidade e os tipos de oficina e de contato"), t("aqui: Listas")],
    [t("A situação do relógio na coleção, os tipos de acessório e os da reserva"), lugar("cadastros.php?aba=listas", t("Cadastros › Listas"))],
    [t("Os avisos da manutenção (Cobrar a oficina, o andamento)"), lugar("avisos.php", t("Avisos"))]]);
  return el("div", {"class": "titulo-secao"}, el("h1", {}, t("Manutenção")) + el("span", {"class": "nota"}, d ? (d.resumo.abertas === 1 ? t("1 relógio na oficina") : t("{1} relógios na oficina", {1: d.resumo.abertas}))
      + (d.resumo.atrasadas > 0 ? " · " + el("span", {"class": "em atrasado"}, d.resumo.atrasadas === 1 ? t("1 atrasada") : t("{1} atrasadas", {1: d.resumo.atrasadas})) : "") : "") + ajudaBotao())
    + el("nav", {"class": "abas"}, Object.keys(ABAS).map(function (k) { return el("a", {"href": "manutencao.php?aba=" + k, "class": k === aba ? "ativa" : ""}, h(ABAS[k])); }).join(""));
}

function abaAbertas(d) {
  var m = editar > 0 ? d.manutencoes.filter(function (x) { return x.id === editar; })[0] || null : null;
  var res = el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-manutencao"}, el("thead", {}, el("tr", {}, [t("Relógio"), t("Oficina"), t("Situação"), t("Enviado"), t("Na oficina"),
      t("Previsão"), t("Orçamento"), t("Aviso"), ""].map(function (c) { return el("th", {}, c); }).join("")))
    + el("tbody", {}, d.abertas.map(function (x) {
      return el("tr", {}, el("td", {}, el("a", {"href": "ficha.php?id=" + x.relogio_id}, h(x.relogio))) + el("td", {}, h(x.oficina)) + el("td", {}, etiquetaSituacao(x))
        + el("td", {}, dataBr(x.enviado)) + el("td", {"class": "num"}, diasTexto(x.dias))
        + el("td", {}, x.previsao !== null ? el("span", {"class": x.atrasada ? "em atrasado" : ""}, dataBr(x.previsao)) + (x.atrasada ? " " + el("small", {}, t("atrasada")) : "") : traco(null))
        + el("td", {"class": "num"}, dinheiro(x.orcamento)) + el("td", {}, x.aviso !== null ? h(x.aviso) : traco(null))
        + el("td", {"class": "acoes-linha"}, formSituacao(d, x) + " " + el("a", {"href": "manutencao.php?aba=abertas&editar=" + x.id + "#form"}, t("Editar"))));
    }).join("") + (d.abertas.length === 0 ? el("tr", {}, el("td", {"colspan": "9"}, t("Nenhum relógio na oficina agora."))) : ""))));
  res += el("div", {"class": "botoes"}, linkRelatorio("manutencao_oficinas", {}, t("Relatório: em manutenção, por oficina")));
  if (m) {
    res += el("section", {"class": "cartao-config", "id": "form"}, el("h2", {}, t("Manutenção do {1}", {1: h(m.relogio)})) + formManutencao(d, m, window.DOCS_MANUTENCAO || [])
      + el("h3", {}, t("A história")) + historia(m)
      + (m.servicos.length > 0 ? el("p", {"class": "nota"}, t("Virou o serviço de {1}: o que foi feito, as peças e o custo ficam no {2}.", {1: dataBr(m.servicos[0].data),
        2: el("a", {"href": "ficha.php?id=" + m.relogio_id}, t("quadro Serviços do relógio"))})) : "")
      + el("form", {"data-recurso": "manutencao", "class": "excluir"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": m.id})
        + el("button", {"class": "leve discreto"}, t("Excluir esta manutenção"))));
  } else {
    res += el("section", {"class": "cartao-config", "id": "form"}, el("h2", {}, t("Cadastrar uma manutenção")) + formManutencao(d, null, null));
  }
  return res;
}

function abaTodas(d) {
  var f = d.filtro;
  var res = el("form", {"method": "get", "action": "manutencao.php", "class": "filtros-execucoes cartao-config"}, el("input", {"type": "hidden", "name": "aba", "value": "todas"})
    + el("label", {}, t("Relógio {1}", {1: el("select", {"name": "relogio"}, el("option", {"value": ""}, t("Todos")) + d.relogios.map(function (r) { return el("option", {"value": r.id, "selected": r.id === f.relogio}, h(r.nome)); }).join(""))}))
    + el("label", {}, t("Oficina {1}", {1: el("select", {"name": "oficina"}, el("option", {"value": ""}, t("Todas")) + d.oficinas.map(function (o) { return el("option", {"value": o.id, "selected": o.id === f.oficina}, h(o.nome)); }).join(""))}))
    + el("label", {}, t("De {1}", {1: el("input", {"type": "date", "name": "de", "value": f.de})})) + el("label", {}, t("Até {1}", {1: el("input", {"type": "date", "name": "ate", "value": f.ate})}))
    + el("div", {"class": "botoes"}, el("button", {}, t("Filtrar")) + " " + linkRelatorio("manutencoes", {relogio: f.relogio || "", de: f.de, ate: f.ate}, t("Relatório do período"))
      + " " + linkRelatorio("gasto_oficinas", {de: f.de, ate: f.ate}, t("Gasto por oficina"))));
  res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-manutencao"}, el("thead", {}, el("tr", {}, [t("Relógio"), t("Oficina"), t("Tipo"), t("Situação"), t("Enviado"),
      t("Voltou"), t("Dias"), t("Custo"), ""].map(function (c) { return el("th", {}, c); }).join("")))
    + el("tbody", {}, d.manutencoes.map(function (x) {
      return el("tr", {}, el("td", {}, el("a", {"href": "ficha.php?id=" + x.relogio_id}, h(x.relogio))) + el("td", {}, h(x.oficina)) + el("td", {}, h(x.tipo_texto)) + el("td", {}, etiquetaSituacao(x))
        + el("td", {}, dataBr(x.enviado)) + el("td", {}, x.encerrado !== null ? dataBr(x.encerrado) : traco(null)) + el("td", {"class": "num"}, String(x.dias)) + el("td", {"class": "num"}, dinheiro(x.custo))
        + el("td", {"class": "acoes-linha"}, el("a", {"href": "manutencao.php?aba=abertas&editar=" + x.id + "#form"}, t("Abrir"))));
    }).join("") + (d.manutencoes.length === 0 ? el("tr", {}, el("td", {"colspan": "9"}, t("Nenhuma manutenção com esses filtros."))) : ""))
    + (d.manutencoes.length > 0 ? el("tfoot", {}, el("tr", {}, el("td", {"colspan": "7"}, t("Total")) + el("td", {"class": "num"}, dinheiro(d.resumo.gasto)) + el("td", {}, ""))) : "")));
  return res;
}

function abaOficinas(d) {
  var o = editarOficina > 0 ? d.oficinas.filter(function (x) { return x.id === editarOficina; })[0] || null : null;
  var res = el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-oficinas"}, el("thead", {}, el("tr", {}, [t("Oficina"), t("Tipo"), t("Endereço"), t("Contatos"), t("Horário"),
      t("Em manutenção"), t("Manutenções"), t("Gasto"), ""].map(function (c) { return el("th", {}, c); }).join("")))
    + el("tbody", {}, d.oficinas.map(function (x) {
      return el("tr", {}, el("td", {}, h(x.nome) + (x.observacao !== null ? el("small", {}, textoRecolhivel(x.observacao, "oficina-" + x.id)) : "")) + el("td", {}, h(x.tipo_texto))
        + el("td", {}, x.endereco_texto !== null ? h(x.endereco_texto) : traco(null)) + el("td", {}, contatosTexto(x)) + el("td", {}, x.horario !== null ? h(x.horario) : traco(null))
        + el("td", {"class": "num"}, String(x.abertas)) + el("td", {"class": "num"}, String(x.manutencoes)) + el("td", {"class": "num"}, dinheiro(x.gasto))
        + el("td", {"class": "acoes-linha"}, el("a", {"href": "manutencao.php?aba=oficinas&oficina=" + x.id + "#form"}, t("Editar"))
          + (x.manutencoes === 0 && !x.propria ? " " + el("form", {"data-recurso": "oficinas", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"})
            + el("input", {"type": "hidden", "name": "id", "value": x.id}) + el("button", {"class": "leve discreto"}, t("Excluir"))) : "")));
    }).join(""))));
  res += el("section", {"class": "cartao-config", "id": "form"}, el("h2", {}, o ? t("Alterar {1}", {1: h(o.nome)}) : t("Nova oficina")) + formOficina(d, o));
  return res;
}

function carregar() {
  if (aba === "listas") {
    return api({recurso: "listas", pagina: "manutencao"}).then(function (l) {
      document.getElementById("manutencao").innerHTML = cabeca(null) + el("p", {"class": "nota"}, t("Cada item tem o identificador (fixo: é o que a API e as fórmulas citam), o nome, a ordem e o que ele faz. O item em uso não sai, e o que ele faz não muda."))
        + editorListas(l.listas);
    });
  }
  var p = {recurso: "manutencao"};
  ["relogio", "oficina", "de", "ate"].forEach(function (k) { if (aba === "todas" && q.get(k)) { p[k] = q.get(k); } });
  return api(p).then(function (d) {
    window.DADOS_MANUTENCAO = d;
    var m = editar > 0 ? d.manutencoes.filter(function (x) { return x.id === editar; })[0] : null;
    // a que se edita: os documentos do relógio dela (o que pode ir junto no envio)
    return (m ? api({recurso: "documentos", relogio: m.relogio_id}).then(function (dd) { window.DOCS_MANUTENCAO = (dd.documentos || []).map(function (x) { return {id: x.id, titulo: x.titulo}; }); })
      : Promise.resolve()).then(function () {
      var corpo = aba === "todas" ? abaTodas(d) : (aba === "oficinas" ? abaOficinas(d) : abaAbertas(d));
      document.getElementById("manutencao").innerHTML = cabeca(d) + corpo;
      if ((editar > 0 || editarOficina > 0 || relogioNovo > 0) && document.getElementById("form")) {
        document.getElementById("form").scrollIntoView();
      }
    });
  }).catch(function (er) {
    document.getElementById("manutencao").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler a manutenção: {1}", {1: h(er.message)}));
  });
}
carregar();
