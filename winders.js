// Winders: a lista da API (recurso=winders). Em cada winder, o desenho (um berço por posição, com a foto do relógio que está
// nela; o lugar de um relógio que saiu fica com a foto dele apagada e o aro vermelho), os dados (o link do manual, os
// documentos) e a tabela das posições, cada linha um formulário (recurso=winder_posicoes): o programa (da página Programas),
// os campos livres da posição, o relógio (— nenhum — tira; outro põe ou troca; o lugar fora: Pôr de volta ou Liberar o lugar)
// e o Salvar (a página se remonta no lugar); embaixo, os formulários do winder (winders.php?editar=id) e da posição
// (?posicao=id: o nome, a ordem, ligada, o programa; nova: ?nova_posicao=winder). As explicações ficam na gaveta de ajuda.
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
    // um texto que é um endereço (o link do manual) vira link, numa aba nova
    var valor = /^https?:\/\/\S+$/.test(String(x.valor)) ? el("a", {"href": x.valor, "target": "_blank", "rel": "noopener"}, h(x.texto)) : el("strong", {}, h(x.texto));
    return el("dt", {}, h(x.nome)) + el("dd", {}, valor + (x.origem !== "informado" ? " " + el("span", {"class": "origem origem-" + x.origem}, h(x.origem_texto)) : ""));
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

// o programa da posição (os da página Programas): o menu, com "— sem programa —"; o resumo do cálculo vai no title
function escolhaPrograma(p, programas, formId) {
  var atual = p.programa !== null ? p.programa.id : 0;
  return el("select", {"name": "programa_id", "form": formId, "aria-label": t("Programa")}, el("option", {"value": "0", "selected": atual === 0}, t("— sem programa —"))
    + programas.map(function (g) { return el("option", {"value": g.id, "selected": g.id === atual, "title": g.texto}, h(g.nome)); }).join(""));
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
    // o lugar de um relógio que saiu: a foto dele apagada, com o aro vermelho ("fora")
    var fora = r === null && p.lugar !== null && p.lugar.fora ? p.lugar : null;
    var foto = function (id, versao, nome) {
      return versao !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + id + "&v=" + versao, "alt": ""}) : el("span", {"class": "berco-sem-foto"}, h(nome.charAt(0)));
    };
    var miolo = r !== null ? foto(r.relogio_id, r.foto, r.relogio) : (fora !== null ? foto(fora.relogio_id, fora.foto, fora.relogio)
      : el("span", {"class": "berco-vazio"}, p.ligada ? t("livre") : t("desligada")));
    var berco = el("span", {"class": "berco" + (r !== null ? " ocupado" : (fora !== null ? " fora" : "")) + (!p.ligada ? " desligado" : (r !== null ? " girando" : "")),
      "style": r !== null && r.carga !== null ? "--carga: " + Math.max(0, Math.min(100, r.carga)) : null}, el("span", {"class": "miolo"}, miolo));
    var quem = r !== null ? r : fora;
    return el("figure", {}, (quem !== null ? el("a", {"href": "ficha.php?id=" + quem.relogio_id, "title": quem.relogio}, berco) : berco)
      + el("figcaption", {}, el("strong", {}, h(p.nome)) + (r !== null ? el("small", {}, h(r.relogio) + (r.carga !== null ? " · ~" + r.carga + "%" : ""))
        : (fora !== null ? el("small", {"class": "fora"}, h(fora.relogio) + " · " + h(fora.estado_texto)) : ""))));
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
    // as explicações: na gaveta de ajuda (o botão Ajuda do título e o ⓘ de cada coisa)
    ajudaGrupo("pagina", t("Winders"));
    ondeMuda("winders", [[t("O programa de uma posição e o relógio nela"), t("aqui: na linha da posição, e Salvar")],
      [t("Os programas (a sequência de giros e paradas) e a velocidade do berço"), lugar("programas.php", t("Programas"))],
      [t("Os giros e as paradas que os programas usam"), lugar("movimentos.php", t("Movimentos"))],
      [t("O nome, a ordem e se a posição está ligada"), t("aqui: Editar, embaixo do nome da posição")],
      [t("O nome, a marca, o modelo, o link do manual, os limites da almofada e a observação do winder"), t("aqui: Editar o winder")],
      [t("O manual, a nota e as fotos do winder"), t("aqui: Documentos, no cartão do winder")],
      [t("Outros campos do winder e da posição"), t("{1}: as subtelas \"os winders\" e \"as posições dos winders\"", {1: lugar("cadastros.php?aba=campos", t("Cadastros › Campos"))})],
      [t("Quanto cada hora no winder carrega"), t("{1}: a fórmula winder_ganho", {1: lugar("cadastros.php?aba=formulas", t("Cadastros › Fórmulas"))})],
      [t("Quais relógios vão para o winder"), t("{1}: o tipo marcado como winder e o vale para dele", {1: lugar("cadastros.php?aba=lancamentos", t("Cadastros › Tipos de lançamento"))})]]);
    var iPos = ajuda("posicao", t("A posição e o programa"), el("p", {}, t("Cada winder tem as posições dele, e cada posição o programa dela, escolhido entre os da página Programas (um programa serve em quantas posições você quiser). As voltas por dia saem sempre da conta do programa: a sequência de giros e paradas e a velocidade do berço."))
      + el("p", {}, t("Mude o programa e o relógio direto na linha da posição e clique em Salvar.")));
    var iRel = ajuda("relogio", t("Pôr, tirar e o lugar fixo"), el("p", {}, t("No Relógio da linha, — nenhum — tira quem está na posição, e outro relógio entra no lugar (saindo de onde estava)."))
      + el("p", {}, t("A posição em que você põe um relógio passa a ser o lugar dele. Tirado (para o pulso, guardado...), o berço continua com a foto dele, apagada e com o aro vermelho: \"fora\". Pôr no winder de novo, de qualquer lugar (o painel do relógio, o atalho do celular, o aviso da Hoje), o devolve para lá, se a posição estiver ligada e livre."))
      + el("p", {}, t("Pôr de volta faz isso daqui; Liberar o lugar desfaz o lugar (a posição fica livre para outro). Outro relógio posto na posição fica com o lugar.")));
    var iCarga = ajuda("carga", t("Carga, Cada hora dá e Enche"), el("p", {}, t("Quanto cada hora no winder carrega sai da fórmula winder_ganho: as voltas por dia do programa no sentido em que o relógio dá corda (os campos dele), comparadas com as que ele pede. A carga é a energia dele agora, e Enche é quando chega a 100%.")));
    var iLim = ajuda("limites", t("Os limites da almofada"), el("p", {}, t("No cadastro do winder: até que tamanho de caixa, de pulso e de peso a almofada aguenta. Um relógio que passa (pelo diâmetro e pelo peso do cadastro dele) ganha um aviso na posição; é só um aviso: ele vai para o winder do mesmo jeito. \"Já sei, deixa assim\" tira o aviso daquele relógio naquele winder.")));
    var iObr = ajuda("obrigatorio", t("O winder é obrigatório"), el("p", {}, t("Sem nenhum winder cadastrado, não há pôr nem tirar do winder em lugar nenhum. Com winder, o relógio vai para o winder por aqui, numa posição; depois, de qualquer lugar ele volta para o lugar dele, e tirar dá para fazer aqui, no painel do relógio ou no atalho do celular.")));
    var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Winders")) + el("span", {"class": "nota"}, d.winders.length + (d.winders.length === 1 ? t(" winder") : t(" winders"))
      + (nPos > 0 ? " · " + t("{1} posições, {2} com relógio", {1: nPos, 2: nOcup}) : "")) + ajudaBotao());
    // o winder é obrigatório: sem nenhum cadastrado, não há pôr nem tirar do winder em lugar nenhum
    if (d.winders.length === 0) {
      res += el("p", {"class": "acao"}, t("Nenhum winder cadastrado: sem ele, não há pôr nem tirar do winder. Cadastre o seu abaixo.") + " " + iObr);
    }
    if (d.programas.length === 0 && d.winders.length > 0) {
      res += el("p", {"class": "acao"}, t("Nenhum programa ainda: monte o do seu winder na página {1} e escolha-o em cada posição.", {1: lugar("programas.php", t("Programas"))}));
    }
    // um cartão por winder: os dados e a tabela das posições
    d.winders.forEach(function (w) {
      var linhas = w.posicoes.map(function (p) {
        var rel = p.relogio;
        var fid = "fp-" + p.id;
        var fora = rel === null && p.lugar !== null && p.lugar.fora ? p.lugar : null;
        // o relógio: o menu; o lugar fora: Pôr de volta e Liberar o lugar; os avisos de limite com o Já sei
        var celRel = !p.ligada ? el("span", {"class": "nota"}, "—")
          : escolhaRelogio(p, d.relogios, fid) + (rel !== null ? el("small", {}, h(rel.desde_texto)) : "")
            + (fora !== null ? el("small", {"class": "lugar-fora"}, t("lugar do {1} · {2}", {1: h(fora.relogio), 2: h(fora.estado_texto)}))
              + el("form", {"data-recurso": "winder_posicoes", "class": "inline acao-lugar", "data-fica": w.id}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
                + el("input", {"type": "hidden", "name": "id", "value": p.id}) + el("input", {"type": "hidden", "name": "relogio_id", "value": fora.relogio_id})
                + el("button", {"class": "leve"}, t("Pôr de volta")))
              + el("form", {"data-recurso": "winder_posicoes", "class": "inline acao-lugar", "data-fica": w.id}, el("input", {"type": "hidden", "name": "acao", "value": "liberar"})
                + el("input", {"type": "hidden", "name": "id", "value": p.id}) + el("button", {"class": "leve discreto"}, t("Liberar o lugar"))) : "")
            + p.avisos_limite.map(function (a) {
              return el("small", {"class": "aviso-limite"}, h(a.texto)) + el("form", {"data-recurso": "winders", "class": "inline acao-lugar", "data-fica": w.id},
                el("input", {"type": "hidden", "name": "acao", "value": "limite_ok"}) + el("input", {"type": "hidden", "name": "id", "value": w.id})
                + el("input", {"type": "hidden", "name": "relogio_id", "value": a.relogio_id}) + el("button", {"class": "leve discreto"}, t("Já sei, deixa assim")));
            }).join("");
        return el("tr", {"class": p.ligada ? "" : "desligada"}, el("td", {}, el("form", {"id": fid, "data-recurso": "winder_posicoes", "data-fica": w.id},
            el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": p.id}))
            + el("strong", {}, h(p.nome)) + (p.ligada ? "" : el("small", {}, t("desligada")))
            + el("small", {}, el("a", {"href": "winders.php?posicao=" + p.id + "#form-posicao", "title": t("O nome, a ordem e se a posição está ligada")}, t("Editar")))
            + el("button", {"form": fid, "class": "leve"}, t("Salvar")))
          + el("td", {}, escolhaPrograma(p, d.programas, fid) + (p.programa !== null ? el("small", {}, h(p.programa.texto)) : ""))
          + d.campos_posicao.map(function (c) { return el("td", {}, entradaLinha(c, p, fid)); }).join("")
          + el("td", {}, celRel)
          + el("td", {"class": "num"}, rel !== null && rel.carga !== null ? "~" + rel.carga + "%" : "—")
          + el("td", {}, rel !== null && rel.ganho_texto !== null ? h(rel.ganho_texto) : "—")
          + el("td", {}, rel !== null && rel.cheio_texto !== null ? h(rel.cheio_texto) : "—"));
      }).join("");
      res += el("section", {"class": "cartao-config winder", "id": "w-" + w.id}, el("h2", {}, h(w.nome))
        + (w.posicoes.length > 0 ? desenhoWinder(w) : "")
        + tabelaDados(w.dados, w.observacao)
        + (w.posicoes.length > 0
          ? el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta posicoes-winder"}, el("thead", {}, el("tr", {}, el("th", {}, t("Posição"))
            + el("th", {}, t("Programa") + iPos)
            + d.campos_posicao.map(function (c) { return el("th", {}, h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "")); }).join("")
            + el("th", {}, t("Relógio") + iRel) + el("th", {}, t("Carga") + iCarga) + el("th", {}, t("Cada hora dá")) + el("th", {}, t("Enche")))) + el("tbody", {}, linhas)))
          : el("p", {"class": "nota"}, t("Nenhuma posição. Crie a primeira abaixo.")))
        + el("div", {"class": "botoes"}, el("a", {"class": "botao leve", "href": "winders.php?nova_posicao=" + w.id + "#form-posicao"}, t("Nova posição"))
          + " " + el("a", {"class": "botao leve", "href": "winders.php?editar=" + w.id + "#form"}, t("Editar o winder"))
          + " " + el("a", {"class": "botao leve", "href": "documentos.php?winder=" + w.id}, w.documentos > 0 ? t("Documentos ({1})", {1: w.documentos}) : t("Documentos"))
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
          + el("label", {}, t("Programa {1}", {1: el("select", {"name": "programa_id"}, el("option", {"value": "0"}, t("— sem programa —"))
            + d.programas.map(function (g) { return el("option", {"value": g.id, "selected": ep && ep.programa !== null && ep.programa.id === g.id}, h(g.nome)); }).join(""))}) + iPos)
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
        + el("p", {"class": "nota"}, t("Os limites da almofada {1}", {1: iLim}))
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
