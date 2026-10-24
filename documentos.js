// Documentos de um relógio (documentos.php?relogio=3&cat=4), de um calibre (documentos.php?calibre=2: o manual técnico,
// que vale para todos os relógios com ele) ou de um winder (documentos.php?winder=1: o manual, a nota, as fotos): os dados
// vêm do api.php (recurso=documentos). Uma aba por
// categoria; dentro dela, cada arquivo abre pelo tipo: as fotos numa galeria (o visor em tela cheia, com setas, teclado,
// deslize e apresentação), os vídeos em sequência (marcados, na ordem escolhida), o PDF no visualizador do navegador, o
// XML da nota com o resumo dela, o áudio no player, e o resto para baixar. O envio é um arquivo por vez (com a miniatura de
// cada foto, feita aqui no navegador), com o andamento; abrir, baixar, editar e excluir ficam no menu ⋮ de cada um (e do
// visor), e gravam pelos formulários comuns (recurso=documentos).
var q = new URLSearchParams(window.location.search);
var rid = parseInt(q.get("relogio") || "0", 10);
var cid = parseInt(q.get("calibre") || "0", 10);
var wid = parseInt(q.get("winder") || "0", 10);
var aba = q.get("cat") || "";
var dados = null;
var recado = "";
var fotos = [];
var atual = 0;
var apresentacao = null;
var ordemVideos = [];
var marcados = {};
var fila = [];
var tocando = 0;
var NOME_FAMILIA = {imagem: t("imagens"), video: t("vídeos"), audio: t("áudios"), pdf: t("PDF"), xml: t("XML")};
var ACEITA = {imagem: "image/*", video: "video/*", audio: "audio/*", pdf: "application/pdf,.pdf", xml: ".xml,application/xml,text/xml"};

// "850 KB", "3,2 MB"
function tamanho(b) {
  return b < 1048576 ? t("{1} KB", {1: Math.max(1, Math.round(b / 1024))}) : t("{1} MB", {1: num(b / 1048576, 1)});
}
function categoria(id) {
  var res = null;
  dados.categorias.forEach(function (c) { if (c.id === id) { res = c; } });
  return res;
}
function docsDaAba() {
  return dados.documentos.filter(function (x) { return aba === "" || String(x.categoria_id) === aba; });
}
function endereco(cat) {
  return "documentos.php?" + (wid > 0 ? "winder=" + wid + "&" : (cid > 0 ? "calibre=" + cid + "&" : (rid > 0 ? "relogio=" + rid + "&" : ""))) + (cat !== "" ? "cat=" + cat : "");
}
// título, data e descrição de um documento
function sobre(x, comCategoria) {
  return el("strong", {}, h(x.titulo)) + (x.data ? " " + el("span", {"class": "nota"}, dataBr(x.data)) : "")
    + (comCategoria && aba === "" && categoria(x.categoria_id) ? " " + el("span", {"class": "etiqueta"}, h(categoria(x.categoria_id).nome)) : "")
    + (rid === 0 && cid === 0 && wid === 0 ? " " + el("span", {"class": "nota"}, "· " + h(nomeRelogio(x.relogio_id))) : "")
    + (x.descricao ? el("p", {"class": "doc-descricao"}, h(x.descricao)) : "");
}
function nomeRelogio(id) {
  var res = "";
  dados.relogios.forEach(function (r) { if (r.id === id) { res = r.nome; } });
  return res;
}
// editar um documento (título, data, categoria, descrição e, quando é ele quem decide, a cópia no banco): o formulário que
// abre na janela do "Editar" do menu ⋮
function formEditar(x) {
  return el("form", {"data-recurso": "documentos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "alterar"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
    + el("label", {}, t("Título {1}", {1: el("input", {"name": "titulo", "value": x.titulo, "maxlength": "200", "required": true})}))
    + el("label", {}, t("Data {1}", {1: el("input", {"type": "date", "name": "data", "value": x.data || ""})}))
    + el("label", {}, t("Categoria {1}", {1: el("select", {"name": "categoria_id"}, dados.categorias.map(function (c) {
      return el("option", {"value": c.id, "selected": c.id === x.categoria_id}, h(c.nome));
    }).join(""))}))
    + el("label", {"style": "grid-column: 1 / -1"}, t("Descrição {1}", {1: el("textarea", {"name": "descricao", "rows": "3"}, h(x.descricao || ""))}))
    // a escolha do próprio arquivo só aparece quando é ele quem decide: nem o config.php nem o relógio decidem por cima
    + (dados.copia_sistema === null && x.copia_por !== "relogio" ? el("label", {"class": "check", "style": "grid-column: 1 / -1"},
      el("input", {"type": "hidden", "name": "copia_banco", "value": "0"})
      + el("input", {"type": "checkbox", "name": "copia_banco", "value": "1", "checked": x.copia_banco}) + t(" Guardar a cópia deste arquivo no banco")) : "")
    + el("div", {"class": "botoes"}, el("button", {}, t("Salvar")) + el("button", {"type": "button", "class": "leve", "data-doc-fechar": "1"}, t("Cancelar"))));
}

// O menu ⋮ de um documento (na foto da galeria, no vídeo, no cartão do arquivo e no visor das fotos): Abrir (a foto em tela
// cheia, o vídeo no player, o PDF e o resto noutra aba), Baixar, Editar (a janela com o formulário) e Excluir (a API mostra
// o que sai junto e pede a confirmação)
function menuDoc(x, comAbrir) {
  return el("div", {"class": "doc-menu"}, el("button", {"type": "button", "class": "doc-menu-botao", "aria-haspopup": "true", "aria-expanded": "false",
      "aria-label": t("Ações de {1}", {1: x.titulo}), "title": t("Abrir, baixar, editar ou excluir")}, "⋮")
    + el("div", {"class": "doc-menu-lista", "role": "menu", "hidden": true},
      (comAbrir ? el("button", {"type": "button", "role": "menuitem", "data-doc-abrir": x.id}, t("Abrir")) : "")
      + el("a", {"href": x.url + "&baixar=1", "role": "menuitem"}, t("Baixar"))
      + el("button", {"type": "button", "role": "menuitem", "data-doc-editar": x.id}, t("Editar"))
      + el("form", {"data-recurso": "documentos"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
        + el("button", {"role": "menuitem", "class": "perigo"}, t("Excluir")))));
}
function docPorId(id) {
  return dados.documentos.filter(function (y) { return y.id === id; })[0];
}
function fecharMenus(exceto) {
  document.querySelectorAll(".doc-menu-lista").forEach(function (l) {
    if (l !== exceto && !l.hidden) {
      l.hidden = true;
      l.parentNode.querySelector(".doc-menu-botao").setAttribute("aria-expanded", "false");
    }
  });
}
// a janela do Editar
function abrirEditar(x) {
  var dlg = document.getElementById("doc-dialogo");
  if (!dlg) {
    dlg = document.createElement("dialog");
    dlg.id = "doc-dialogo";
    dlg.className = "doc-dialogo";
    document.body.appendChild(dlg);
  }
  dlg.innerHTML = el("h2", {}, t("Editar {1}", {1: h(x.titulo)})) + el("p", {"class": "nota"}, h(x.nome)) + formEditar(x);
  dlg.showModal();
  dlg.querySelector("input[name=titulo]").focus();
}
document.addEventListener("click", function (ev) {
  var b = ev.target.closest(".doc-menu-botao");
  if (b) {
    var lista = b.parentNode.querySelector(".doc-menu-lista");
    fecharMenus(lista);
    lista.hidden = !lista.hidden;
    b.setAttribute("aria-expanded", lista.hidden ? "false" : "true");
    if (!lista.hidden) {
      lista.querySelector("[role=menuitem]").focus();
    }
    return;
  }
  var item = ev.target.closest("[data-doc-abrir], [data-doc-editar], [data-doc-fechar]");
  if (!ev.target.closest(".doc-menu-lista button[role=menuitem]")) {
    fecharMenus(null);
  }
  if (item === null) {
    return;
  }
  if (item.hasAttribute("data-doc-fechar")) {
    document.getElementById("doc-dialogo").close();
    return;
  }
  var x = docPorId(parseInt(item.getAttribute(item.hasAttribute("data-doc-abrir") ? "data-doc-abrir" : "data-doc-editar"), 10));
  fecharMenus(null);
  if (!x) {
    return;
  }
  if (item.hasAttribute("data-doc-editar")) {
    abrirEditar(x);
  } else if (x.familia === "imagem") {
    abrirVisor(fotos.indexOf(x));
  } else if (x.familia === "video") {
    fila = [x.id];
    tocar(0);
  } else {
    window.open(x.url, "_blank", "noopener");
  }
});
// Esc fecha o menu aberto antes de qualquer outra coisa (o visor, por baixo, continua aberto)
document.addEventListener("keydown", function (ev) {
  if (ev.key === "Escape" && document.querySelector(".doc-menu-lista:not([hidden])")) {
    ev.stopImmediatePropagation();
    fecharMenus(null);
  }
}, true);
// quem pede a cópia no banco: o sistema (config.php), o relógio ou o próprio arquivo
var COPIA_POR = {sistema: t("o config.php guarda todos"), relogio: t("o relógio guarda todos os dele"), arquivo: t("pedida neste arquivo")};
// o nome do arquivo, o tamanho e onde ele está guardado: na pasta, no banco, e quem pediu a cópia no banco
function guardado(x) {
  var onde = !x.no_disco ? el("strong", {}, x.no_banco && x.copia_inteira ? t("fora da pasta (volta do banco ao abrir)")
      : x.no_banco ? t("cortado na pasta e também no banco (a cópia foi feita do arquivo já cortado): envie o arquivo de novo") : t("o arquivo sumiu da pasta e não tem cópia no banco"))
    : x.no_banco ? (x.copia_por ? t("na pasta e no banco ({1})", {1: COPIA_POR[x.copia_por]}) : t("na pasta e no banco (ninguém mais pede a cópia: o cron a tira)"))
      : x.copia_por ? t("na pasta; a cópia no banco ainda vai ({1}; o cron faz)", {1: COPIA_POR[x.copia_por]}) : t("só na pasta");
  return el("p", {"class": "nota"}, h(x.nome) + " · " + tamanho(x.tamanho) + " · " + onde);
}
function linkBaixar(x, rotulo) {
  return el("a", {"href": x.url + "&baixar=1", "class": "botao-link"}, rotulo || t("Baixar"));
}

function montar() {
  var d = dados;
  var res = el("h1", {}, d.winder ? t("Documentos do winder {1}", {1: h(d.winder.nome)}) : (d.calibre ? t("Documentos do calibre {1}", {1: h(d.calibre.nome)}) : (d.relogio ? t("Documentos do {1}", {1: h(d.relogio.nome)}) : t("Documentos de todos os relógios"))))
    + el("form", {"class": "filtros-plano", "onsubmit": "return false"}, el("label", {}, t("Relógio {1}", {1: el("select", {"id": "f-relogio"},
      el("option", {"value": "0"}, t("Todos")) + d.relogios.map(function (r) {
        return el("option", {"value": r.id, "selected": cid === 0 && wid === 0 && r.id === rid}, h(r.nome) + (r.documentos > 0 ? " (" + r.documentos + ")" : ""));
      }).join("")
      // os calibres: os documentos do movimento, que valem para todos os relógios com ele
      + (d.calibres.length > 0 ? el("optgroup", {"label": t("Calibres")}, d.calibres.map(function (c) {
        return el("option", {"value": "c" + c.id, "selected": c.id === cid}, h(c.nome) + (c.documentos > 0 ? " (" + c.documentos + ")" : ""));
      }).join("")) : "")
      // os winders: o manual, a nota e as fotos de cada um
      + (d.winders.length > 0 ? el("optgroup", {"label": t("Winders")}, d.winders.map(function (w) {
        return el("option", {"value": "w" + w.id, "selected": w.id === wid}, h(w.nome) + (w.documentos > 0 ? " (" + w.documentos + ")" : ""));
      }).join("")) : ""))}))
      + (d.relogio ? " " + el("a", {"href": "ficha.php?id=" + rid, "class": "botao leve"}, t("Abrir a ficha do relógio")) : "")
      + (d.calibre ? " " + el("a", {"href": "cadastros.php?aba=calibres&editar=" + cid + "#form", "class": "botao leve"}, t("Abrir o calibre")) : "")
      + (d.winder ? " " + el("a", {"href": "winders.php#w-" + wid, "class": "botao leve"}, t("Abrir o winder")) : ""));
  if (d.calibre) {
    res += el("p", {"class": "nota"}, d.calibre.relogios.length > 0 ? t("Valem para os relógios com este calibre: {1}.", {1: d.calibre.relogios.map(function (r) {
      return el("a", {"href": "documentos.php?relogio=" + r.id}, h(r.nome));
    }).join(", ")}) : t("Nenhum relógio tem este calibre ainda."));
  }
  // no relógio, os documentos do calibre dele (o manual técnico do movimento...), com o link para a página deles
  if (d.do_calibre) {
    res += el("p", {"class": "nota doc-do-calibre"}, t("Do calibre {1}: {2}", {1: el("a", {"href": "documentos.php?calibre=" + d.do_calibre.id}, h(d.do_calibre.nome)),
      2: d.do_calibre.documentos.length === 0 ? t("nenhum documento.") : d.do_calibre.documentos.map(function (x) {
        return el("a", {"href": x.url, "target": "_blank", "rel": "noopener"}, h(x.titulo));
      }).join(" · ")}));
  }
  if (recado !== "") {
    res += el("p", {"class": "acao"}, h(recado));
  }
  // a cópia no banco: a Configuração da coleção vale por todos (e então não há escolha nenhuma na página); sem ela, o
  // relógio (aqui) e, quando o relógio não guarda todos, cada arquivo (no envio e em editar)
  if (d.pasta_ok && d.copia_sistema === true) {
    res += el("p", {"class": "nota"}, t("Cópia no banco: a Configuração da coleção (Cópia dos documentos no banco: todos) manda guardar todo arquivo também no banco."));
  } else if (d.pasta_ok && d.copia_sistema === false) {
    res += el("p", {"class": "nota"}, t("Cópia no banco: a Configuração da coleção (Cópia dos documentos no banco: nenhum) não deixa; os arquivos ficam só na pasta."));
  } else if (d.pasta_ok && d.relogio) {
    res += el("form", {"data-recurso": "relogio", "class": "linha"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
      + el("input", {"type": "hidden", "name": "id", "value": d.relogio.id}) + el("input", {"type": "hidden", "name": "copia_banco", "value": d.relogio.copia_banco ? "0" : "1"})
      + el("span", {"class": "nota"}, d.relogio.copia_banco ? t("Cópia no banco: todos os arquivos do {1} vão também para o banco. ", {1: h(d.relogio.nome)})
        : t("Cópia no banco: só os arquivos marcados vão também para o banco. "))
      + el("button", {"class": "leve"}, d.relogio.copia_banco ? t("Só os marcados") : t("Guardar todos os dele no banco")));
  }
  if (!d.pasta_ok) {
    res += el("p", {"class": "acao"}, t("Os documentos ainda não podem ser guardados: {1}.", {1: h(d.pasta_erro)}));
  }
  // o envio: só com um relógio, um calibre ou um winder escolhido
  if ((d.relogio || d.calibre || d.winder) && d.pasta_ok) {
    var catPadrao = aba !== "" ? parseInt(aba, 10) : (d.categorias.length > 0 ? d.categorias[0].id : 0);
    res += el("details", {"class": "envio-docs", "open": d.documentos.length === 0}, el("summary", {}, t("Enviar arquivos"))
      + el("form", {"id": "form-envio", "class": "form-grade"},
        el("label", {}, t("Categoria {1}", {1: el("select", {"name": "categoria_id", "id": "envio-categoria"}, d.categorias.map(function (c) {
          return el("option", {"value": c.id, "selected": c.id === catPadrao}, h(c.nome) + (c.aceita.length > 0 ? " (" + c.aceita.map(function (f) { return NOME_FAMILIA[f] || f; }).join(", ") + ")" : ""));
        }).join(""))}))
        + el("label", {}, t("Título {1}", {1: el("input", {"name": "titulo", "maxlength": "200", "placeholder": t("vazio: o nome do arquivo")})}))
        + el("label", {}, t("Data {1}", {1: el("input", {"type": "date", "name": "data"})}))
        + el("label", {"style": "grid-column: 1 / -1"}, t("Descrição {1}", {1: el("textarea", {"name": "descricao", "rows": "2", "placeholder": t("a ocasião, o que é, onde foi...")})}))
        + el("label", {"style": "grid-column: 1 / -1"}, t("Arquivos (pode escolher vários) {1}", {1: el("input", {"type": "file", "name": "arquivos", "id": "envio-arquivos", "multiple": true, "required": true})}))
        + (d.copia_sistema === null && !(d.relogio && d.relogio.copia_banco) ? el("label", {"class": "check", "style": "grid-column: 1 / -1"},
          t("{1} Guardar a cópia destes arquivos também no banco", {1: el("input", {"type": "checkbox", "name": "copia_banco", "value": "1"})})) : "")
        + el("p", {"class": "nota", "style": "grid-column: 1 / -1"}, t("Qualquer arquivo{1}. O título, a data e a descrição valem para todos os escolhidos; depois cada um se edita sozinho.", {1: d.limite !== null ? t(", até {1} cada", {1: tamanho(d.limite)}) : t(", de qualquer tamanho")}))
        + el("div", {"class": "botoes"}, el("button", {}, t("Enviar")) + " " + el("span", {"id": "envio-andamento", "class": "nota"}, ""))));
  }
  // as abas: todas e cada categoria, com quantos documentos
  res += el("nav", {"class": "abas"}, el("a", {"href": endereco(""), "class": aba === "" ? "ativa" : ""}, t("Todos ({1})", {1: d.documentos.length}))
    + d.categorias.map(function (c) {
      return el("a", {"href": endereco(String(c.id)), "class": String(c.id) === aba ? "ativa" : ""}, h(c.nome) + " (" + c.documentos + ")");
    }).join(""));
  var docs = docsDaAba();
  if (docs.length === 0) {
    res += el("p", {}, t("Nenhum documento aqui ainda."));
  }
  // as fotos: a galeria
  fotos = docs.filter(function (x) { return x.familia === "imagem"; });
  if (fotos.length > 0) {
    res += el("section", {"class": "doc-secao"}, el("h2", {}, t("Fotos ({1})", {1: fotos.length}))
      + el("p", {}, el("button", {"type": "button", "class": "leve", "data-apresentacao": "1"}, t("▶ Apresentação")) + " " + el("span", {"class": "nota"}, t("Toque numa foto para ver em tela cheia.")))
      + el("div", {"class": "galeria"}, fotos.map(function (x, i) {
        return el("div", {"class": "galeria-item"}, el("button", {"type": "button", "class": "galeria-foto", "data-foto": i, "title": x.titulo},
          el("img", {"src": x.url + (x.miniatura ? "&mini=1" : ""), "alt": h(x.titulo), "loading": "lazy"})
          + el("span", {}, h(x.titulo) + (x.data ? " · " + dataBr(x.data, true) : ""))) + menuDoc(x, true));
      }).join("")));
  }
  // os vídeos: a lista para marcar e ordenar, e o player
  var videos = docs.filter(function (x) { return x.familia === "video"; });
  ordemVideos = ordemVideos.filter(function (vid) { return videos.some(function (x) { return x.id === vid; }); });
  videos.forEach(function (x) {
    if (ordemVideos.indexOf(x.id) < 0) {
      ordemVideos.push(x.id);
      marcados[x.id] = true;
    }
  });
  if (videos.length > 0) {
    res += el("section", {"class": "doc-secao"}, el("h2", {}, t("Vídeos ({1})", {1: videos.length}))
      + el("div", {"id": "player", "class": "player"}, "")
      + el("p", {"class": "nota"}, t("Marque os vídeos, ponha na ordem com ↑ e ↓ e aperte Assistir: eles tocam um depois do outro."))
      + el("ol", {"id": "lista-videos", "class": "lista-videos"}, "")
      + el("p", {}, el("button", {"type": "button", "data-assistir": "1"}, t("▶ Assistir os marcados"))));
  }
  // o resto: PDF, XML, áudio e outros
  var outros = docs.filter(function (x) { return x.familia !== "imagem" && x.familia !== "video"; });
  if (outros.length > 0) {
    res += el("section", {"class": "doc-secao"}, el("h2", {}, t("Arquivos ({1})", {1: outros.length})) + outros.map(cartao).join(""));
  }
  document.getElementById("documentos").innerHTML = res;
  desenharVideos();
  var sel = document.getElementById("f-relogio");
  sel.addEventListener("change", function () {
    var v = this.value;
    window.location = "documentos.php" + (v === "0" ? "" : (v.charAt(0) === "c" ? "?calibre=" + v.substr(1) : (v.charAt(0) === "w" ? "?winder=" + v.substr(1) : "?relogio=" + v)));
  });
  var cat = document.getElementById("envio-categoria");
  if (cat) {
    var acerta = function () {
      var c = categoria(parseInt(cat.value, 10));
      var aceita = c ? c.aceita.map(function (f) { return ACEITA[f]; }).filter(Boolean).join(",") : "";
      var arq = document.getElementById("envio-arquivos");
      if (aceita !== "") {
        arq.setAttribute("accept", aceita);
      } else {
        arq.removeAttribute("accept");
      }
    };
    cat.addEventListener("change", acerta);
    acerta();
  }
}

// um PDF, um XML, um áudio ou outro arquivo
function cartao(x) {
  var corpo = "";
  if (x.familia === "pdf") {
    corpo = el("p", {}, el("button", {"type": "button", "class": "leve", "data-pdf": x.id}, t("Ver aqui")) + " " + el("a", {"href": x.url, "target": "_blank", "rel": "noopener", "class": "botao-link"}, t("Abrir noutra aba"))
      + " " + linkBaixar(x)) + el("div", {"id": "pdf-" + x.id, "class": "pdf-visor", "hidden": true}, "");
  } else if (x.familia === "xml") {
    var n = x.nfe;
    corpo = (n ? el("table", {"class": "nfe"}, el("tbody", {},
      el("tr", {}, el("th", {}, t("Emitente")) + el("td", {}, h(n.emitente || "") + (n.cnpj ? " " + el("span", {"class": "nota"}, h(n.cnpj)) : "")))
      + el("tr", {}, el("th", {}, t("Nota")) + el("td", {}, t("nº ") + h(n.numero || "") + (n.serie ? t(", série {1}", {1: h(n.serie)}) : "") + (n.data ? ", " + dataBr(n.data) : "")))
      + el("tr", {}, el("th", {}, t("Valor")) + el("td", {}, n.valor !== null ? t("R$ ") + reais(n.valor) : ""))
      + n.produtos.map(function (p) {
        return el("tr", {}, el("th", {}, t("Produto")) + el("td", {}, h(p.descricao || "") + (p.quantidade !== null && p.quantidade !== 1 ? t(" × ") + num(p.quantidade, 2) : "") + (p.valor !== null ? t(" · R$ ") + reais(p.valor) : "")));
      }).join("")
      + (n.chave ? el("tr", {}, el("th", {}, t("Chave")) + el("td", {"class": "formula"}, h(n.chave))) : "")))
      : el("p", {"class": "nota"}, t("Não é o XML de uma NF-e: só para baixar.")))
      + el("p", {}, linkBaixar(x, t("Baixar o XML")));
  } else if (x.familia === "audio") {
    corpo = el("audio", {"controls": true, "preload": "none", "src": x.url}, "") + el("p", {}, linkBaixar(x));
  } else {
    corpo = el("p", {}, linkBaixar(x));
  }
  return el("div", {"class": "doc-cartao com-menu"}, menuDoc(x, true) + sobre(x, true) + guardado(x) + corpo);
}

// a lista dos vídeos, na ordem escolhida, com a marca de cada um
function desenharVideos() {
  var lista = document.getElementById("lista-videos");
  if (!lista) {
    return;
  }
  lista.innerHTML = ordemVideos.map(function (vid, i) {
    var x = dados.documentos.filter(function (y) { return y.id === vid; })[0];
    return el("li", {}, el("label", {"class": "check"}, el("input", {"type": "checkbox", "data-marca": vid, "checked": marcados[vid] === true}) + " " + sobre(x, true))
      + el("span", {"class": "nota"}, tamanho(x.tamanho)) + " "
      + el("button", {"type": "button", "class": "leve discreto", "data-sobe": i, "disabled": i === 0, "aria-label": t("Subir")}, "↑")
      + el("button", {"type": "button", "class": "leve discreto", "data-desce": i, "disabled": i === ordemVideos.length - 1, "aria-label": t("Descer")}, "↓")
      + menuDoc(x, true) + guardado(x));
  }).join("");
}

// toca o vídeo da posição i da fila; no fim dele, o próximo
function tocar(i) {
  var p = document.getElementById("player");
  if (i < 0 || i >= fila.length) {
    return;
  }
  tocando = i;
  var x = dados.documentos.filter(function (y) { return y.id === fila[i]; })[0];
  p.innerHTML = el("div", {"class": "player-topo"}, el("span", {}, t("{1} de {2}", {1: i + 1, 2: fila.length}) + ": " + el("strong", {}, h(x.titulo)) + (x.data ? " · " + dataBr(x.data) : ""))
    + el("span", {}, el("button", {"type": "button", "class": "leve", "data-video-ant": "1", "disabled": i === 0}, t("‹ Anterior"))
      + el("button", {"type": "button", "class": "leve", "data-video-prox": "1", "disabled": i === fila.length - 1}, t("Próximo ›"))
      + el("button", {"type": "button", "class": "leve discreto", "data-video-fechar": "1"}, t("Fechar"))))
    + el("video", {"src": x.url, "controls": true, "autoplay": true, "playsinline": true, "preload": "metadata"}, "")
    + (x.descricao ? el("p", {"class": "doc-descricao"}, h(x.descricao)) : "");
  p.querySelector("video").addEventListener("ended", function () {
    if (tocando + 1 < fila.length) {
      tocar(tocando + 1);
    }
  });
  p.scrollIntoView({behavior: "smooth", block: "start"});
}

// o visor das fotos, em tela cheia
var visor = document.getElementById("visor");
function abrirVisor(i) {
  atual = i;
  visor.hidden = false;
  document.body.classList.add("com-visor");
  desenharVisor();
}
function fecharVisor() {
  pararApresentacao();
  visor.hidden = true;
  visor.innerHTML = "";
  document.body.classList.remove("com-visor");
}
function desenharVisor() {
  var x = fotos[atual];
  visor.innerHTML = el("div", {"class": "visor-topo"}, el("span", {}, (atual + 1) + t(" de ") + fotos.length)
    + el("span", {}, el("button", {"type": "button", "class": "visor-apres"}, apresentacao ? t("❚❚ Parar") : t("▶ Apresentação"))
      + menuDoc(x, false) + el("button", {"type": "button", "class": "visor-fechar", "aria-label": t("Fechar")}, t("×"))))
    + el("div", {"class": "visor-foto"}, el("button", {"type": "button", "class": "visor-ant", "aria-label": t("Anterior")}, "‹")
      + el("img", {"src": x.url, "alt": h(x.titulo)}) + el("button", {"type": "button", "class": "visor-prox", "aria-label": t("Próxima")}, "›"))
    + el("div", {"class": "visor-legenda"}, el("strong", {}, h(x.titulo)) + (x.data ? " · " + dataBr(x.data) : "") + (x.descricao ? el("p", {}, h(x.descricao)) : ""));
  // a próxima já vem carregando
  if (fotos.length > 1) {
    new Image().src = fotos[(atual + 1) % fotos.length].url;
  }
}
function andar(passo) {
  atual = (atual + passo + fotos.length) % fotos.length;
  desenharVisor();
}
function pararApresentacao() {
  if (apresentacao) {
    clearInterval(apresentacao);
    apresentacao = null;
  }
}
function alternarApresentacao() {
  if (apresentacao) {
    pararApresentacao();
  } else {
    apresentacao = setInterval(function () { andar(1); }, 4000);
  }
  desenharVisor();
}
visor.addEventListener("click", function (ev) {
  var item = ev.target.closest("button");
  if (item === null) {
    if (ev.target === visor || ev.target.classList.contains("visor-foto")) {
      fecharVisor();
    }
    return;
  }
  if (item.classList.contains("visor-ant")) {
    pararApresentacao();
    andar(-1);
  } else if (item.classList.contains("visor-prox")) {
    pararApresentacao();
    andar(1);
  } else if (item.classList.contains("visor-fechar")) {
    fecharVisor();
  } else if (item.classList.contains("visor-apres")) {
    alternarApresentacao();
  }
});
document.addEventListener("keydown", function (ev) {
  // com a janela do Editar aberta, o teclado é dela
  var dlg = document.getElementById("doc-dialogo");
  if (visor.hidden || (dlg && dlg.open)) {
    return;
  }
  if (ev.key === "ArrowLeft") {
    pararApresentacao();
    andar(-1);
  } else if (ev.key === "ArrowRight") {
    pararApresentacao();
    andar(1);
  } else if (ev.key === "Escape") {
    fecharVisor();
  } else if (ev.key === " ") {
    ev.preventDefault();
    alternarApresentacao();
  }
});
// deslizar o dedo no celular
var toqueX = null;
visor.addEventListener("touchstart", function (ev) { toqueX = ev.touches[0].clientX; }, {passive: true});
visor.addEventListener("touchend", function (ev) {
  if (toqueX !== null) {
    var dx = ev.changedTouches[0].clientX - toqueX;
    if (Math.abs(dx) > 50) {
      pararApresentacao();
      andar(dx < 0 ? 1 : -1);
    }
  }
  toqueX = null;
});

// os botões da página: abrir uma foto, a apresentação, os vídeos, o PDF
document.addEventListener("click", function (ev) {
  var item = ev.target.closest("[data-foto], [data-apresentacao], [data-assistir], [data-sobe], [data-desce], [data-video-ant], [data-video-prox], [data-video-fechar], [data-pdf]");
  if (item === null || !document.getElementById("documentos").contains(item)) {
    return;
  }
  if (item.hasAttribute("data-foto")) {
    abrirVisor(parseInt(item.getAttribute("data-foto"), 10));
  } else if (item.hasAttribute("data-apresentacao")) {
    abrirVisor(0);
    alternarApresentacao();
  } else if (item.hasAttribute("data-assistir")) {
    fila = ordemVideos.filter(function (vid) { return marcados[vid] === true; });
    if (fila.length === 0) {
      alert(t("Marque pelo menos um vídeo."));
    } else {
      tocar(0);
    }
  } else if (item.hasAttribute("data-sobe") || item.hasAttribute("data-desce")) {
    var i = parseInt(item.getAttribute(item.hasAttribute("data-sobe") ? "data-sobe" : "data-desce"), 10);
    var j = item.hasAttribute("data-sobe") ? i - 1 : i + 1;
    var x = ordemVideos[i];
    ordemVideos[i] = ordemVideos[j];
    ordemVideos[j] = x;
    desenharVideos();
  } else if (item.hasAttribute("data-video-ant")) {
    tocar(tocando - 1);
  } else if (item.hasAttribute("data-video-prox")) {
    tocar(tocando + 1);
  } else if (item.hasAttribute("data-video-fechar")) {
    document.getElementById("player").innerHTML = "";
  } else if (item.hasAttribute("data-pdf")) {
    var caixa = document.getElementById("pdf-" + item.getAttribute("data-pdf"));
    if (caixa.hidden) {
      caixa.innerHTML = el("iframe", {"src": "api.php?recurso=documento&id=" + item.getAttribute("data-pdf"), "title": t("PDF")}, "");
      caixa.hidden = false;
      item.textContent = t("Fechar");
    } else {
      caixa.hidden = true;
      caixa.innerHTML = "";
      item.textContent = t("Ver aqui");
    }
  }
});
document.addEventListener("change", function (ev) {
  if (ev.target.hasAttribute("data-marca")) {
    marcados[parseInt(ev.target.getAttribute("data-marca"), 10)] = ev.target.checked;
  }
});

// a miniatura de uma foto, feita aqui (no máximo 480 px, JPEG): a galeria abre rápido mesmo com fotos grandes
function miniatura(arquivo) {
  return new Promise(function (pronto) {
    if (!/^image\/(jpeg|png|webp|gif)$/.test(arquivo.type)) {
      pronto(null);
      return;
    }
    var img = new Image();
    var url = URL.createObjectURL(arquivo);
    img.onload = function () {
      var escala = Math.min(1, 480 / Math.max(img.naturalWidth, img.naturalHeight));
      var c = document.createElement("canvas");
      c.width = Math.max(1, Math.round(img.naturalWidth * escala));
      c.height = Math.max(1, Math.round(img.naturalHeight * escala));
      c.getContext("2d").drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      c.toBlob(function (b) { pronto(b); }, "image/jpeg", 0.8);
    };
    img.onerror = function () {
      URL.revokeObjectURL(url);
      pronto(null);
    };
    img.src = url;
  });
}

// manda um arquivo (com a miniatura), mostrando o andamento; devolve a resposta da API
function enviarUm(form, arquivo, mini, rotulo) {
  return new Promise(function (pronto) {
    var fd = new FormData();
    fd.append("recurso", "documentos");
    fd.append("acao", "enviar");
    fd.append(wid > 0 ? "winder_id" : (cid > 0 ? "calibre_id" : "relogio_id"), wid > 0 ? wid : (cid > 0 ? cid : rid));
    ["categoria_id", "titulo", "data", "descricao"].forEach(function (k) { fd.append(k, form.elements[k].value); });
    fd.append("copia_banco", form.elements.copia_banco && form.elements.copia_banco.checked ? "1" : "0");
    fd.append("arquivos[]", arquivo, arquivo.name);
    if (mini) {
      fd.append("miniatura", mini, "miniatura.jpg");
    }
    var x = new XMLHttpRequest();
    var andamento = document.getElementById("envio-andamento");
    x.upload.onprogress = function (ev) {
      if (ev.lengthComputable) {
        andamento.textContent = rotulo + " — " + Math.round(ev.loaded * 100 / ev.total) + "%";
      }
    };
    x.onload = function () {
      var r = null;
      try {
        r = JSON.parse(x.responseText);
      } catch (e) {
        r = {ok: false, erros: [t("{1}: o servidor recusou (HTTP {2}{3}).", {1: arquivo.name, 2: x.status, 3: x.status === 413 ? t(": o arquivo passou do limite de envio do servidor") : ""})]};
      }
      pronto(r);
    };
    x.onerror = function () {
      pronto({ok: false, erros: [t("{1}: a conexão caiu no meio do envio.", {1: arquivo.name})]});
    };
    x.open("POST", "api.php");
    x.send(fd);
  });
}
document.addEventListener("submit", function (ev) {
  if (ev.target.id !== "form-envio") {
    return;
  }
  ev.preventDefault();
  var form = ev.target;
  var arquivos = Array.prototype.slice.call(document.getElementById("envio-arquivos").files);
  if (arquivos.length === 0) {
    return;
  }
  form.querySelector("button").disabled = true;
  var guardados = 0;
  var erros = [];
  var passo = Promise.resolve();
  arquivos.forEach(function (a, i) {
    passo = passo.then(function () {
      return miniatura(a);
    }).then(function (mini) {
      return enviarUm(form, a, mini, t("Enviando {1} de {2}: {3}", {1: i + 1, 2: arquivos.length, 3: a.name}));
    }).then(function (r) {
      if (r.ok) {
        guardados += (r.ids || []).length;
      }
      erros = erros.concat(r.erros || []);
    });
  });
  passo.then(function () {
    recado = (guardados > 0 ? (guardados === 1 ? t("1 documento guardado.") : t("{1} documentos guardados.", {1: guardados})) : t("Nada foi guardado.")) + (erros.length > 0 ? " " + erros.join(" ") : "");
    return recarregar();
  });
});

function recarregar() {
  return api(wid > 0 ? {recurso: "documentos", winder: wid} : (cid > 0 ? {recurso: "documentos", calibre: cid} : (rid > 0 ? {recurso: "documentos", relogio: rid} : {recurso: "documentos"}))).then(function (d) {
    dados = d;
    montar();
  }).catch(function (e) {
    document.getElementById("documentos").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os documentos: {1}", {1: h(e.message)}));
  });
}

// editar e excluir (o menu ⋮): fecha a janela do Editar e o visor (a foto pode ter saído), e o recado e a página de novo
aoGravar = function (form, res) {
  recado = textoResposta(res);
  var dlg = document.getElementById("doc-dialogo");
  if (dlg && dlg.open && res.ok) {
    dlg.close();
  }
  if (!visor.hidden && res.ok) {
    fecharVisor();
  }
  recarregar().then(function () {
    window.scrollTo(0, 0);
  });
};

recarregar();
