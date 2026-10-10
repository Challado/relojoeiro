// Documentos de um relógio (documentos.php?relogio=3&cat=4): os dados vêm do api.php (recurso=documentos). Uma aba por
// categoria; dentro dela, cada arquivo abre pelo tipo: as fotos numa galeria (o visor em tela cheia, com setas, teclado,
// deslize e apresentação), os vídeos em sequência (marcados, na ordem escolhida), o PDF no visualizador do navegador, o
// XML da nota com o resumo dela, o áudio no player, e o resto para baixar. O envio é um arquivo por vez (com a miniatura de
// cada foto, feita aqui no navegador), com o andamento; editar e excluir vão pelos formulários comuns (recurso=documentos).
var q = new URLSearchParams(window.location.search);
var rid = parseInt(q.get("relogio") || "0", 10);
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
var NOME_FAMILIA = {imagem: "imagens", video: "vídeos", audio: "áudios", pdf: "PDF", xml: "XML"};
var ACEITA = {imagem: "image/*", video: "video/*", audio: "audio/*", pdf: "application/pdf,.pdf", xml: ".xml,application/xml,text/xml"};

// "850 KB", "3,2 MB"
function tamanho(b) {
  return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + " KB" : num(b / 1048576, 1) + " MB";
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
  return "documentos.php?" + (rid > 0 ? "relogio=" + rid + "&" : "") + (cat !== "" ? "cat=" + cat : "");
}
// título, data e descrição de um documento
function sobre(x, comCategoria) {
  return el("strong", {}, h(x.titulo)) + (x.data ? " " + el("span", {"class": "nota"}, dataBr(x.data)) : "")
    + (comCategoria && aba === "" && categoria(x.categoria_id) ? " " + el("span", {"class": "etiqueta"}, h(categoria(x.categoria_id).nome)) : "")
    + (rid === 0 ? " " + el("span", {"class": "nota"}, "· " + h(nomeRelogio(x.relogio_id))) : "")
    + (x.descricao ? el("p", {"class": "doc-descricao"}, h(x.descricao)) : "");
}
function nomeRelogio(id) {
  var res = "";
  dados.relogios.forEach(function (r) { if (r.id === id) { res = r.nome; } });
  return res;
}
// editar (título, data, categoria, descrição) e excluir um documento
function formEditar(x) {
  return el("details", {"class": "doc-editar"}, el("summary", {}, "editar")
    + el("form", {"data-recurso": "documentos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "alterar"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
      + el("label", {}, "Título " + el("input", {"name": "titulo", "value": x.titulo, "maxlength": "200", "required": true}))
      + el("label", {}, "Data " + el("input", {"type": "date", "name": "data", "value": x.data || ""}))
      + el("label", {}, "Categoria " + el("select", {"name": "categoria_id"}, dados.categorias.map(function (c) {
        return el("option", {"value": c.id, "selected": c.id === x.categoria_id}, h(c.nome));
      }).join("")))
      + el("label", {"style": "grid-column: 1 / -1"}, "Descrição " + el("textarea", {"name": "descricao", "rows": "2"}, h(x.descricao || "")))
      // a escolha do próprio arquivo só aparece quando é ele quem decide: nem o config.php nem o relógio decidem por cima
      + (dados.copia_sistema === null && x.copia_por !== "relogio" ? el("label", {"class": "check", "style": "grid-column: 1 / -1"},
        el("input", {"type": "hidden", "name": "copia_banco", "value": "0"})
        + el("input", {"type": "checkbox", "name": "copia_banco", "value": "1", "checked": x.copia_banco}) + " Guardar a cópia deste arquivo no banco") : "")
      + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, "Salvar")))
    + el("form", {"data-recurso": "documentos", "onsubmit": "return confirm(" + JSON.stringify("Excluir " + x.titulo + "? O arquivo sai do servidor.") + ")"},
      el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
      + el("button", {"class": "leve discreto"}, "Excluir")));
}
// quem pede a cópia no banco: o sistema (config.php), o relógio ou o próprio arquivo
var COPIA_POR = {sistema: "o config.php guarda todos", relogio: "o relógio guarda todos os dele", arquivo: "pedida neste arquivo"};
// o nome do arquivo, o tamanho e onde ele está guardado: na pasta, no banco, e quem pediu a cópia no banco
function guardado(x) {
  var onde = !x.no_disco ? el("strong", {}, x.no_banco ? "fora da pasta (volta do banco ao abrir)" : "o arquivo sumiu da pasta e não tem cópia no banco")
    : x.no_banco ? (x.copia_por ? "na pasta e no banco (" + COPIA_POR[x.copia_por] + ")" : "na pasta e no banco (ninguém mais pede a cópia: o cron a tira)")
      : x.copia_por ? "na pasta; a cópia no banco ainda vai (" + COPIA_POR[x.copia_por] + "; o cron faz)" : "só na pasta";
  return el("p", {"class": "nota"}, h(x.nome) + " · " + tamanho(x.tamanho) + " · " + onde);
}
function linkBaixar(x, rotulo) {
  return el("a", {"href": x.url + "&baixar=1", "class": "botao-link"}, rotulo || "Baixar");
}

function montar() {
  var d = dados;
  var res = el("h1", {}, d.relogio ? "Documentos do " + h(d.relogio.nome) : "Documentos de todos os relógios")
    + el("form", {"class": "filtros-plano", "onsubmit": "return false"}, el("label", {}, "Relógio " + el("select", {"id": "f-relogio"},
      el("option", {"value": "0"}, "Todos") + d.relogios.map(function (r) {
        return el("option", {"value": r.id, "selected": r.id === rid}, h(r.nome) + (r.documentos > 0 ? " (" + r.documentos + ")" : ""));
      }).join("")))
      + (d.relogio ? " " + el("a", {"href": "ficha.php?id=" + rid, "class": "botao leve"}, "Abrir a ficha do relógio") : ""));
  if (recado !== "") {
    res += el("p", {"class": "acao"}, h(recado));
  }
  // a cópia no banco: o config.php vale por todos (e então não há escolha nenhuma na página); sem ele, o relógio (aqui) e,
  // quando o relógio não guarda todos, cada arquivo (no envio e em editar)
  if (d.pasta_ok && d.copia_sistema === true) {
    res += el("p", {"class": "nota"}, "Cópia no banco: o config.php (DOCUMENTOS_COPIA_BANCO) manda guardar todo arquivo também no banco.");
  } else if (d.pasta_ok && d.copia_sistema === false) {
    res += el("p", {"class": "nota"}, "Cópia no banco: o config.php (DOCUMENTOS_COPIA_BANCO = false) não deixa; os arquivos ficam só na pasta.");
  } else if (d.pasta_ok && d.relogio) {
    res += el("form", {"data-recurso": "relogio", "class": "linha"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
      + el("input", {"type": "hidden", "name": "id", "value": d.relogio.id}) + el("input", {"type": "hidden", "name": "copia_banco", "value": d.relogio.copia_banco ? "0" : "1"})
      + el("span", {"class": "nota"}, d.relogio.copia_banco ? "Cópia no banco: todos os arquivos do " + h(d.relogio.nome) + " vão também para o banco. "
        : "Cópia no banco: só os arquivos marcados vão também para o banco. ")
      + el("button", {"class": "leve"}, d.relogio.copia_banco ? "Só os marcados" : "Guardar todos os dele no banco"));
  }
  if (!d.pasta_ok) {
    res += el("p", {"class": "acao"}, "Os documentos ainda não podem ser guardados: " + h(d.pasta_erro) + ".");
  }
  // o envio: só com um relógio escolhido
  if (d.relogio && d.pasta_ok) {
    var catPadrao = aba !== "" ? parseInt(aba, 10) : (d.categorias.length > 0 ? d.categorias[0].id : 0);
    res += el("details", {"class": "envio-docs", "open": d.documentos.length === 0}, el("summary", {}, "Enviar arquivos")
      + el("form", {"id": "form-envio", "class": "form-grade"},
        el("label", {}, "Categoria " + el("select", {"name": "categoria_id", "id": "envio-categoria"}, d.categorias.map(function (c) {
          return el("option", {"value": c.id, "selected": c.id === catPadrao}, h(c.nome) + (c.aceita.length > 0 ? " (" + c.aceita.map(function (f) { return NOME_FAMILIA[f] || f; }).join(", ") + ")" : ""));
        }).join("")))
        + el("label", {}, "Título " + el("input", {"name": "titulo", "maxlength": "200", "placeholder": "vazio: o nome do arquivo"}))
        + el("label", {}, "Data " + el("input", {"type": "date", "name": "data"}))
        + el("label", {"style": "grid-column: 1 / -1"}, "Descrição " + el("textarea", {"name": "descricao", "rows": "2", "placeholder": "a ocasião, o que é, onde foi..."}))
        + el("label", {"style": "grid-column: 1 / -1"}, "Arquivos (pode escolher vários) " + el("input", {"type": "file", "name": "arquivos", "id": "envio-arquivos", "multiple": true, "required": true}))
        + (d.copia_sistema === null && !d.relogio.copia_banco ? el("label", {"class": "check", "style": "grid-column: 1 / -1"},
          el("input", {"type": "checkbox", "name": "copia_banco", "value": "1"}) + " Guardar a cópia destes arquivos também no banco") : "")
        + el("p", {"class": "nota", "style": "grid-column: 1 / -1"}, "Qualquer arquivo" + (d.limite !== null ? ", até " + tamanho(d.limite) + " cada" : ", de qualquer tamanho")
          + ". O título, a data e a descrição valem para todos os escolhidos; depois cada um se edita sozinho.")
        + el("div", {"class": "botoes"}, el("button", {}, "Enviar") + " " + el("span", {"id": "envio-andamento", "class": "nota"}, ""))));
  }
  // as abas: todas e cada categoria, com quantos documentos
  res += el("nav", {"class": "abas"}, el("a", {"href": endereco(""), "class": aba === "" ? "ativa" : ""}, "Todos (" + d.documentos.length + ")")
    + d.categorias.map(function (c) {
      return el("a", {"href": endereco(String(c.id)), "class": String(c.id) === aba ? "ativa" : ""}, h(c.nome) + " (" + c.documentos + ")");
    }).join(""));
  var docs = docsDaAba();
  if (docs.length === 0) {
    res += el("p", {}, "Nenhum documento aqui ainda.");
  }
  // as fotos: a galeria
  fotos = docs.filter(function (x) { return x.familia === "imagem"; });
  if (fotos.length > 0) {
    res += el("section", {"class": "doc-secao"}, el("h2", {}, "Fotos (" + fotos.length + ")")
      + el("p", {}, el("button", {"type": "button", "class": "leve", "data-apresentacao": "1"}, "▶ Apresentação") + " " + el("span", {"class": "nota"}, "Toque numa foto para ver em tela cheia."))
      + el("div", {"class": "galeria"}, fotos.map(function (x, i) {
        return el("button", {"type": "button", "class": "galeria-foto", "data-foto": i, "title": x.titulo},
          el("img", {"src": x.url + (x.miniatura ? "&mini=1" : ""), "alt": h(x.titulo), "loading": "lazy"})
          + el("span", {}, h(x.titulo) + (x.data ? " · " + dataBr(x.data, true) : "")));
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
    res += el("section", {"class": "doc-secao"}, el("h2", {}, "Vídeos (" + videos.length + ")")
      + el("div", {"id": "player", "class": "player"}, "")
      + el("p", {"class": "nota"}, "Marque os vídeos, ponha na ordem com ↑ e ↓ e aperte Assistir: eles tocam um depois do outro.")
      + el("ol", {"id": "lista-videos", "class": "lista-videos"}, "")
      + el("p", {}, el("button", {"type": "button", "data-assistir": "1"}, "▶ Assistir os marcados")));
  }
  // o resto: PDF, XML, áudio e outros
  var outros = docs.filter(function (x) { return x.familia !== "imagem" && x.familia !== "video"; });
  if (outros.length > 0) {
    res += el("section", {"class": "doc-secao"}, el("h2", {}, "Arquivos (" + outros.length + ")") + outros.map(cartao).join(""));
  }
  // as fotos e os vídeos também se editam aqui embaixo, numa lista só
  var midia = fotos.concat(videos);
  if (midia.length > 0) {
    res += el("details", {"class": "doc-secao"}, el("summary", {}, "Editar ou excluir fotos e vídeos")
      + midia.map(function (x) { return el("div", {"class": "doc-cartao"}, sobre(x, true) + guardado(x) + formEditar(x)); }).join(""));
  }
  document.getElementById("documentos").innerHTML = res;
  desenharVideos();
  var sel = document.getElementById("f-relogio");
  sel.addEventListener("change", function () {
    window.location = "documentos.php" + (this.value !== "0" ? "?relogio=" + this.value : "");
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
    corpo = el("p", {}, el("button", {"type": "button", "class": "leve", "data-pdf": x.id}, "Ver aqui") + " " + el("a", {"href": x.url, "target": "_blank", "rel": "noopener", "class": "botao-link"}, "Abrir noutra aba")
      + " " + linkBaixar(x)) + el("div", {"id": "pdf-" + x.id, "class": "pdf-visor", "hidden": true}, "");
  } else if (x.familia === "xml") {
    var n = x.nfe;
    corpo = (n ? el("table", {"class": "nfe"}, el("tbody", {},
      el("tr", {}, el("th", {}, "Emitente") + el("td", {}, h(n.emitente || "") + (n.cnpj ? " " + el("span", {"class": "nota"}, h(n.cnpj)) : "")))
      + el("tr", {}, el("th", {}, "Nota") + el("td", {}, "nº " + h(n.numero || "") + (n.serie ? ", série " + h(n.serie) : "") + (n.data ? ", " + dataBr(n.data) : "")))
      + el("tr", {}, el("th", {}, "Valor") + el("td", {}, n.valor !== null ? "R$ " + reais(n.valor) : ""))
      + n.produtos.map(function (p) {
        return el("tr", {}, el("th", {}, "Produto") + el("td", {}, h(p.descricao || "") + (p.quantidade !== null && p.quantidade !== 1 ? " × " + num(p.quantidade, 2) : "") + (p.valor !== null ? " · R$ " + reais(p.valor) : "")));
      }).join("")
      + (n.chave ? el("tr", {}, el("th", {}, "Chave") + el("td", {"class": "formula"}, h(n.chave))) : "")))
      : el("p", {"class": "nota"}, "Não é o XML de uma NF-e: só para baixar."))
      + el("p", {}, linkBaixar(x, "Baixar o XML"));
  } else if (x.familia === "audio") {
    corpo = el("audio", {"controls": true, "preload": "none", "src": x.url}, "") + el("p", {}, linkBaixar(x));
  } else {
    corpo = el("p", {}, linkBaixar(x));
  }
  return el("div", {"class": "doc-cartao"}, sobre(x, true) + guardado(x) + corpo + formEditar(x));
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
      + el("button", {"type": "button", "class": "leve discreto", "data-sobe": i, "disabled": i === 0, "aria-label": "Subir"}, "↑")
      + el("button", {"type": "button", "class": "leve discreto", "data-desce": i, "disabled": i === ordemVideos.length - 1, "aria-label": "Descer"}, "↓"));
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
  p.innerHTML = el("div", {"class": "player-topo"}, el("span", {}, (i + 1) + " de " + fila.length + ": " + el("strong", {}, h(x.titulo)) + (x.data ? " · " + dataBr(x.data) : ""))
    + el("span", {}, el("button", {"type": "button", "class": "leve", "data-video-ant": "1", "disabled": i === 0}, "‹ Anterior")
      + el("button", {"type": "button", "class": "leve", "data-video-prox": "1", "disabled": i === fila.length - 1}, "Próximo ›")
      + el("button", {"type": "button", "class": "leve discreto", "data-video-fechar": "1"}, "Fechar")))
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
  visor.innerHTML = el("div", {"class": "visor-topo"}, el("span", {}, (atual + 1) + " de " + fotos.length)
    + el("span", {}, el("button", {"type": "button", "class": "visor-apres"}, apresentacao ? "❚❚ Parar" : "▶ Apresentação")
      + el("a", {"href": x.url + "&baixar=1", "class": "botao-link"}, "Baixar") + el("button", {"type": "button", "class": "visor-fechar", "aria-label": "Fechar"}, "×")))
    + el("div", {"class": "visor-foto"}, el("button", {"type": "button", "class": "visor-ant", "aria-label": "Anterior"}, "‹")
      + el("img", {"src": x.url, "alt": h(x.titulo)}) + el("button", {"type": "button", "class": "visor-prox", "aria-label": "Próxima"}, "›"))
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
  var t = ev.target.closest("button");
  if (t === null) {
    if (ev.target === visor || ev.target.classList.contains("visor-foto")) {
      fecharVisor();
    }
    return;
  }
  if (t.classList.contains("visor-ant")) {
    pararApresentacao();
    andar(-1);
  } else if (t.classList.contains("visor-prox")) {
    pararApresentacao();
    andar(1);
  } else if (t.classList.contains("visor-fechar")) {
    fecharVisor();
  } else if (t.classList.contains("visor-apres")) {
    alternarApresentacao();
  }
});
document.addEventListener("keydown", function (ev) {
  if (visor.hidden) {
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
  var t = ev.target.closest("[data-foto], [data-apresentacao], [data-assistir], [data-sobe], [data-desce], [data-video-ant], [data-video-prox], [data-video-fechar], [data-pdf]");
  if (t === null || !document.getElementById("documentos").contains(t)) {
    return;
  }
  if (t.hasAttribute("data-foto")) {
    abrirVisor(parseInt(t.getAttribute("data-foto"), 10));
  } else if (t.hasAttribute("data-apresentacao")) {
    abrirVisor(0);
    alternarApresentacao();
  } else if (t.hasAttribute("data-assistir")) {
    fila = ordemVideos.filter(function (vid) { return marcados[vid] === true; });
    if (fila.length === 0) {
      alert("Marque pelo menos um vídeo.");
    } else {
      tocar(0);
    }
  } else if (t.hasAttribute("data-sobe") || t.hasAttribute("data-desce")) {
    var i = parseInt(t.getAttribute(t.hasAttribute("data-sobe") ? "data-sobe" : "data-desce"), 10);
    var j = t.hasAttribute("data-sobe") ? i - 1 : i + 1;
    var x = ordemVideos[i];
    ordemVideos[i] = ordemVideos[j];
    ordemVideos[j] = x;
    desenharVideos();
  } else if (t.hasAttribute("data-video-ant")) {
    tocar(tocando - 1);
  } else if (t.hasAttribute("data-video-prox")) {
    tocar(tocando + 1);
  } else if (t.hasAttribute("data-video-fechar")) {
    document.getElementById("player").innerHTML = "";
  } else if (t.hasAttribute("data-pdf")) {
    var caixa = document.getElementById("pdf-" + t.getAttribute("data-pdf"));
    if (caixa.hidden) {
      caixa.innerHTML = el("iframe", {"src": "api.php?recurso=documento&id=" + t.getAttribute("data-pdf"), "title": "PDF"}, "");
      caixa.hidden = false;
      t.textContent = "Fechar";
    } else {
      caixa.hidden = true;
      caixa.innerHTML = "";
      t.textContent = "Ver aqui";
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
    fd.append("relogio_id", rid);
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
        r = {ok: false, erros: [arquivo.name + ": o servidor recusou (HTTP " + x.status + (x.status === 413 ? ": o arquivo passou do limite de envio do servidor" : "") + ")."]};
      }
      pronto(r);
    };
    x.onerror = function () {
      pronto({ok: false, erros: [arquivo.name + ": a conexão caiu no meio do envio."]});
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
      return enviarUm(form, a, mini, "Enviando " + (i + 1) + " de " + arquivos.length + ": " + a.name);
    }).then(function (r) {
      if (r.ok) {
        guardados += (r.ids || []).length;
      }
      erros = erros.concat(r.erros || []);
    });
  });
  passo.then(function () {
    recado = (guardados > 0 ? (guardados === 1 ? "1 documento guardado." : guardados + " documentos guardados.") : "Nada foi guardado.") + (erros.length > 0 ? " " + erros.join(" ") : "");
    return recarregar();
  });
});

function recarregar() {
  return api(rid > 0 ? {recurso: "documentos", relogio: rid} : {recurso: "documentos"}).then(function (d) {
    dados = d;
    montar();
  }).catch(function (e) {
    document.getElementById("documentos").innerHTML = el("p", {"class": "acao"}, "Não consegui ler os documentos: " + h(e.message));
  });
}

// editar e excluir (os formulários comuns): o recado e a página de novo
aoGravar = function (form, res) {
  recado = textoResposta(res);
  recarregar().then(function () {
    window.scrollTo(0, 0);
  });
};

recarregar();
