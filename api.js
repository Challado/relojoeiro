// A base das páginas: todo dado vem do api.php e toda gravação vai para ele. As páginas só montam o HTML.
// O login do site (HTTP Basic) vai junto em cada pedido, pelo próprio navegador.

// Os idiomas: todo texto das telas passa pela função t (o texto em português e os parâmetros entre chaves); o dicionário vem da API
// (recurso=textos) antes de qualquer tela ser montada (o api() espera por ele). O que falta no dicionário sai em português.
// O idioma é o do pedido: o seletor do menu (o cookie lingua), senão o padrão da instalação, senão o do navegador.
// O dicionário chega antes deste script (a página carrega api.php?recurso=textos&formato=js, que põe window.TEXTOS_API); sem
// ele (uma página que não carregou), pela API, e o api() espera
var LINGUA = document.documentElement.lang || "pt-BR";
var LOCALE = "pt-BR";
var FORMATO = {data: "d/m/Y", data_curta: "d/m"};
var TEXTOS = {};
var ESTRITO = false;
function usarTextos(d) {
  if (d && d.lingua) {
    TEXTOS = d.textos || {};
    LOCALE = d.locale || "pt-BR";
    FORMATO = d.formato || FORMATO;
    ESTRITO = d.estrito === true;
    LINGUA = d.lingua;
    // sem idioma escolhido no menu (o cookie), vale o padrão da instalação: a página abre de novo nele, uma vez
    // o fuso do aparelho vai num cookie: vale para quem escolheu "O do navegador" em Minha conta (fuso_do_pedido, lib.php)
    try {
      var fusoNav = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (fusoNav) {
        document.cookie = "fuso_navegador=" + fusoNav + "; path=/; max-age=31536000; SameSite=Lax";
      }
    } catch (e) {
      // sem Intl: o fuso fica o da coleção
    }
    // o idioma padrão (d.padrao) não se usa daqui: este script fica em cache (um dia, e no service worker); o que decide o
    // idioma de quem não escolheu é o idioma_padrao do menu (seguirIdiomaPadrao, abaixo), que vem a cada página
  }
}
var textosProntos = window.TEXTOS_API ? Promise.resolve(usarTextos(window.TEXTOS_API))
  : fetch("api.php?recurso=textos&lingua=" + encodeURIComponent(LINGUA), { credentials: "same-origin" }).then(function (r) { return r.json(); }).then(usarTextos).catch(function () { return null; });

// o texto no idioma, com os parâmetros trocados ({nome} pelo valor). O que falta sai em português; no modo estrito
// (TEXTOS_ESTRITO no config.php), entre ⟦ ⟧ na tela e com o erro no console, dizendo o arquivo
function t(s, p) {
  var tem = Object.prototype.hasOwnProperty.call(TEXTOS, s) && TEXTOS[s] !== "";
  var res = tem ? TEXTOS[s] : s;
  if (!tem && ESTRITO && LINGUA !== "pt-BR" && s !== "") {
    console.error("Falta a tradução em " + LINGUA + " (textos/" + LINGUA + ".php): " + JSON.stringify(s));
    res = "⟦" + s + "⟧";
  }
  if (p) {
    Object.keys(p).forEach(function (k) {
      res = res.split("{" + k + "}").join(String(p[k]));
    });
  }
  return res;
}

// marca um texto do dicionário que é traduzido depois, num t(variável) (a conferência dos dicionários acha a chave aqui)
function tk(s) {
  return s;
}

// um texto que não se traduz de propósito (a marca, "24H", um exemplo de endereço ou de código): sai como está. Fora do t(),
// todo texto na tela tem de estar aqui (a regra 11 de testes/arquitetura.php confere)
function fixo(s) {
  return s;
}

// Os campos de credencial de um serviço de fora (o usuário e a senha do SMTP, do CalDAV): o navegador não os preenche
// sozinho com o login salvo deste site (num par usuário e senha ele ignora o autocomplete="off" e põe o login de quem usa o
// navegador, que acabaria gravado na conta de outra pessoa). Somente leitura até o clique, o que o preenchimento respeita
function semPreencher(attrs) {
  attrs.autocomplete = attrs.type === "password" ? "new-password" : "off";
  attrs.readonly = true;
  attrs.onfocus = "this.removeAttribute('readonly')";
  attrs["data-lpignore"] = "true";
  attrs["data-1p-ignore"] = "true";
  return attrs;
}

// Um relatório da API (recurso=relatorios, tipo=...) pronto para a tela e para o papel: a barra (Imprimir e a planilha),
// a cabeça (a foto, o grupo, o título e o subtítulo), o resumo, as seções (uma tabela, com o total embaixo, ou pares) e o
// rodapé. Tudo vem em texto da API; aqui só se monta. A página Relatórios usa este, e as outras telas apontam para ela
// (linkRelatorio)
function relatorioHtml(r) {
  var celula = function (v, c) { return el("td", {"class": c && c.numero ? "num" : ""}, c && c.registro && v !== "—" ? el("pre", {}, h(v)) : h(v)); };
  var res = el("div", {"class": "barra-impressao"}, el("button", {"type": "button", "onclick": "window.print()"}, t("Imprimir")) + " "
    + el("a", {"class": "botao leve", "href": r.csv, "download": ""}, t("Baixar a planilha (CSV)")) + " "
    + el("span", {"class": "nota"}, t("No papel saem só os dados (o \"Salvar como PDF\" do navegador guarda o arquivo).")));
  res += el("header", {"class": "cabeca-impressa"}, (r.foto ? el("img", {"src": r.foto, "alt": h(r.subtitulo || r.titulo)}) : "")
    + el("div", {}, el("p", {"class": "data"}, h(r.grupo)) + el("h1", {}, h(r.titulo)) + (r.subtitulo ? el("p", {}, h(r.subtitulo)) : "")));
  if (r.resumo.length > 0) {
    res += el("section", {"class": "relatorio-resumo"}, el("dl", {"class": "pares"}, r.resumo.map(function (x) { return el("dt", {}, h(x.nome)) + el("dd", {}, h(x.valor)); }).join("")));
  }
  r.secoes.forEach(function (s) {
    var corpo;
    if (s.tipo === "tabela") {
      corpo = s.linhas.length === 0 ? el("p", {"class": "nota"}, h(s.vazio || ""))
        : el("div", {"class": "rolagem"}, el("table", {"class": "tabela-dados compacta relatorio"},
          el("thead", {}, el("tr", {}, s.colunas.map(function (c) { return el("th", {"class": c.numero ? "num" : ""}, h(c.nome)); }).join("")))
          + el("tbody", {}, s.linhas.map(function (l) { return el("tr", {}, l.map(function (v, i) { return celula(v, s.colunas[i]); }).join("")); }).join(""))
          + (s.total ? el("tfoot", {}, el("tr", {}, s.total.map(function (v, i) { return celula(v, s.colunas[i]); }).join(""))) : "")));
    } else {
      corpo = s.pares.length === 0 ? el("p", {"class": "nota"}, h(s.vazio || ""))
        : el("dl", {"class": "pares"}, s.pares.map(function (x) { return el("dt", {}, h(x.nome)) + el("dd", {}, h(x.valor)); }).join(""));
    }
    res += el("section", {}, el("h2", {}, h(s.titulo)) + corpo + (s.nota ? el("p", {"class": "nota"}, h(s.nota)) : ""));
  });
  return res + el("p", {"class": "nota rodape-impresso"}, h(r.rodape));
}

// As subtelas recolhíveis (<details data-recolhe="chave">): a que o usuário fechou fica fechada neste aparelho (localStorage,
// só a comodidade de cada um; sem ele, tudo abre aberto)
function recolhidos() {
  try {
    return JSON.parse(localStorage.getItem("recolhidos") || "[]");
  } catch (e) {
    return [];
  }
}
function recolhivel(chave, titulo, corpo, classe) {
  return el("details", {"class": "recolhivel" + (classe ? " " + classe : ""), "data-recolhe": chave, "open": recolhidos().indexOf(chave) < 0}, el("summary", {}, titulo) + corpo);
}
document.addEventListener("toggle", function (ev) {
  var d = ev.target;
  if (!d.matches || !d.matches("details[data-recolhe]")) {
    return;
  }
  var chave = d.getAttribute("data-recolhe");
  var lista = recolhidos().filter(function (x) { return x !== chave; });
  if (!d.open) {
    lista.push(chave);
  }
  try {
    localStorage.setItem("recolhidos", JSON.stringify(lista));
  } catch (e) { /* armazenamento bloqueado: só não lembra */ }
}, true);

// ---------------------------------------------------------------------------------------------------------------------
// A gaveta das explicações (a ajuda de cada página). As explicações não ficam no meio da página (empurrando os campos): ficam numa gaveta que desliza da
// direita (no celular, a tela inteira). Cada parte da tela registra as dela ao se montar:
//   ajudaGrupo(grupo, titulo)      começa (ou recomeça, ao remontar) um grupo: "pagina", "painel"... com o título dele
//   ajuda(chave, titulo, html)     um item do grupo atual; devolve o ⓘ que abre a gaveta já nele (vai ao lado do rótulo)
//   ajudaNota(chave, titulo, html) a explicação que era um parágrafo: a linha curta "ⓘ título" no lugar dele
//   ajudaBotao()                   o botão "Explicações" do título da página: a gaveta com todos os itens
//   ondeMuda(chave, linhas)        "onde se muda cada coisa" vira o primeiro item do grupo (a tabela O quê | Onde)
// Na gaveta: a busca no alto filtra os itens; fecha no ×, no Esc ou num clique fora.
// ---------------------------------------------------------------------------------------------------------------------
var AJUDA = {grupos: [], atual: null};
function ajudaGrupo(grupo, titulo) {
  var g = null;
  AJUDA.grupos.forEach(function (x) { if (x.grupo === grupo) { g = x; } });
  if (g === null) {
    g = {grupo: grupo, titulo: titulo, itens: []};
    AJUDA.grupos.push(g);
  }
  g.titulo = titulo;
  g.itens = [];
  AJUDA.atual = g;
  return g;
}
function ajudaItem(chave, titulo, html, primeiro) {
  var g = AJUDA.atual || ajudaGrupo("pagina", document.title);
  ajudaPorBotao();
  g.itens = g.itens.filter(function (x) { return x.chave !== chave; });
  var item = {chave: g.grupo + "-" + chave, titulo: titulo, html: html};
  if (primeiro) {
    g.itens.unshift(item);
  } else {
    g.itens.push(item);
  }
  return item.chave;
}
function ajuda(chave, titulo, html) {
  var k = ajudaItem(chave, titulo, html);
  return el("button", {"type": "button", "class": "ajuda-i", "data-ajuda": k, "aria-label": t("Explicação: {1}", {1: titulo}), "title": t("O que é isto?")}, t("ⓘ"));
}
// a explicação que era um parágrafo no meio da página: uma linha curta "ⓘ título" no mesmo lugar, que abre a gaveta nela
function ajudaNota(chave, titulo, html) {
  var k = ajudaItem(chave, titulo, el("p", {}, html));
  return el("button", {"type": "button", "class": "ajuda-linha", "data-ajuda": k}, el("span", {"class": "ajuda-linha-i", "aria-hidden": "true"}, t("ⓘ")) + " " + h(titulo));
}
// o botão Explicações entra sozinho no título da página (o primeiro h1 da área principal) que tem explicações e ainda não tem
// o botão; fora o destaque da página Hoje (o nome do relógio) e o painel do relógio, que mostram as dela pelas linhas ⓘ
var ajudaBotaoEspera = null;
function ajudaPorBotao() {
  clearTimeout(ajudaBotaoEspera);
  ajudaBotaoEspera = setTimeout(function () {
    if (document.querySelector(".ajuda-botao")) {
      return;
    }
    var h1 = Array.prototype.filter.call(document.querySelectorAll("main h1"), function (x) { return !x.closest(".col-hoje, .painel, .detalhe"); })[0];
    if (h1) {
      h1.insertAdjacentHTML("beforeend", " " + ajudaBotao());
    }
  }, 0);
}
// o ⓘ de um item que já está no grupo atual (o mesmo texto vale para dois campos)
function ajudaRef(chave) {
  var k = (AJUDA.atual ? AJUDA.atual.grupo : "pagina") + "-" + chave;
  return el("button", {"type": "button", "class": "ajuda-i", "data-ajuda": k, "aria-label": t("Explicação"), "title": t("O que é isto?")}, t("ⓘ"));
}
function ajudaBotao() {
  return el("button", {"type": "button", "class": "botao leve ajuda-botao", "data-ajuda": "", "title": t("As explicações desta página")}, t("Explicações"));
}
// "Onde se muda cada coisa" (o quê | onde, com o link para o lugar): um item da gaveta; linhas: [[o quê, onde], ...], o
// onde já em HTML (lugar() faz o link). Não devolve nada para a página: o botão Explicações do título abre
function ondeMuda(chave, linhas) {
  ajudaItem("onde-" + chave, t("Onde se muda cada coisa"), el("div", {"class": "rolagem"}, el("table", {"class": "compacta onde-muda"},
    el("thead", {}, el("tr", {}, el("th", {}, t("O quê")) + el("th", {}, t("Onde"))))
    + el("tbody", {}, linhas.map(function (l) { return el("tr", {}, el("td", {}, l[0]) + el("td", {}, l[1])); }).join("")))), true);
  return "";
}
// sem acento e em minúsculas, para a busca da gaveta
function semAcento(s) {
  return String(s).toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
}
function ajudaAbrir(chave) {
  ajudaFechar();
  var corpo = AJUDA.grupos.filter(function (g) { return g.itens.length > 0; }).map(function (g) {
    return el("section", {"class": "ajuda-grupo"}, (AJUDA.grupos.length > 1 ? el("h3", {}, h(g.titulo)) : "")
      + g.itens.map(function (x) { return el("article", {"class": "ajuda-item", "id": "ajuda-" + x.chave}, el("h4", {}, h(x.titulo)) + x.html); }).join(""));
  }).join("");
  var fundo = document.createElement("div");
  fundo.className = "ajuda-fundo";
  var gaveta = document.createElement("aside");
  gaveta.className = "ajuda-gaveta";
  gaveta.setAttribute("role", "dialog");
  gaveta.setAttribute("aria-modal", "true");
  gaveta.setAttribute("aria-label", t("Explicações"));
  gaveta.innerHTML = el("div", {"class": "ajuda-topo"}, el("h2", {}, t("Explicações"))
      + el("input", {"type": "search", "class": "ajuda-busca", "placeholder": t("Procurar nas explicações"), "aria-label": t("Procurar nas explicações")})
      + el("button", {"type": "button", "class": "ajuda-fechar", "aria-label": t("Fechar"), "title": t("Fechar (Esc)")}, t("×")))
    + el("div", {"class": "ajuda-corpo"}, corpo !== "" ? corpo + el("p", {"class": "nota ajuda-nada", "hidden": true}, t("Nada com isso nas explicações desta página."))
      : el("p", {"class": "nota"}, t("Esta página não tem explicações.")));
  document.body.appendChild(fundo);
  document.body.appendChild(gaveta);
  document.body.classList.add("com-ajuda");
  fundo.addEventListener("click", ajudaFechar);
  gaveta.querySelector(".ajuda-fechar").addEventListener("click", ajudaFechar);
  gaveta.querySelector(".ajuda-busca").addEventListener("input", function () {
    var q = semAcento(this.value.trim());
    var algum = false;
    gaveta.querySelectorAll(".ajuda-item").forEach(function (a) {
      var ve = q === "" || semAcento(a.textContent).indexOf(q) >= 0;
      a.hidden = !ve;
      algum = algum || ve;
    });
    gaveta.querySelectorAll(".ajuda-grupo").forEach(function (g) { g.hidden = g.querySelectorAll(".ajuda-item:not([hidden])").length === 0; });
    var nada = gaveta.querySelector(".ajuda-nada");
    if (nada) {
      nada.hidden = algum;
    }
  });
  var alvo = chave ? document.getElementById("ajuda-" + chave) : null;
  if (alvo) {
    alvo.classList.add("ajuda-destaque");
    alvo.scrollIntoView({block: "start"});
    gaveta.querySelector(".ajuda-fechar").focus();
  } else {
    gaveta.querySelector(".ajuda-busca").focus();
  }
}
function ajudaFechar() {
  document.querySelectorAll(".ajuda-gaveta, .ajuda-fundo").forEach(function (x) { x.remove(); });
  document.body.classList.remove("com-ajuda");
}
document.addEventListener("click", function (ev) {
  var b = ev.target.closest ? ev.target.closest("[data-ajuda]") : null;
  if (b) {
    ev.preventDefault();
    ajudaAbrir(b.getAttribute("data-ajuda"));
  }
});
// Esc fecha a gaveta antes de qualquer outra coisa (o painel do relógio, por baixo, continua aberto)
document.addEventListener("keydown", function (ev) {
  if (ev.key === "Escape" && document.querySelector(".ajuda-gaveta")) {
    ev.stopImmediatePropagation();
    ajudaFechar();
  }
}, true);
function lugar(href, texto) {
  return el("a", {"href": href}, texto);
}

// O link de uma tela para um relatório já escolhido (a página Relatórios abre e gera): o tipo e os parâmetros dele (os
// vazios ficam de fora); novaAba: abre noutra aba (o painel do relógio, ao lado da lista)
function linkRelatorio(tipo, parametros, texto, novaAba) {
  var p = new URLSearchParams({tipo: tipo});
  Object.keys(parametros || {}).forEach(function (k) {
    if (parametros[k] !== null && parametros[k] !== undefined && parametros[k] !== "") {
      p.set(k, parametros[k]);
    }
  });
  return el("a", {"class": "botao leve link-relatorio", "href": "relatorios.php?" + p.toString(), "target": novaAba ? "_blank" : null, "rel": novaAba ? "noopener" : null},
    texto || t("Relatório"));
}

// Texto seguro para pôr no HTML
function h(t) {
  return String(t === null || t === undefined ? "" : t).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

// Uma marca HTML: el("p", {"class": "nota"}, "texto já seguro"). Atributo null ou false não sai; true sai sem valor.
function el(nome, atributos, conteudo) {
  var res = "<" + nome;
  Object.keys(atributos || {}).forEach(function (k) {
    var v = atributos[k];
    if (v === true) {
      res += " " + k;
    } else if (v !== null && v !== false && v !== undefined) {
      res += " " + k + "=\"" + h(v) + "\"";
    }
  });
  return res + ">" + (conteudo === null ? "" : (conteudo || "")) + (["input", "img", "br"].indexOf(nome) >= 0 ? "" : "</" + nome + ">");
}

// Número no formato do idioma (a vírgula ou o ponto), sem zeros sobrando
function num(v, casas) {
  var f = Math.pow(10, casas === undefined ? 1 : casas);
  return v === null || v === undefined || v === "" ? "—" : (Math.round(Number(v) * f) / f).toLocaleString(LOCALE, { maximumFractionDigits: casas === undefined ? 1 : casas, useGrouping: false });
}

// Dinheiro, no formato do idioma: 1.234,56 (1,234.56 em inglês)
function reais(v) {
  return Number(v).toLocaleString(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// "AAAA-MM-DD..." para a data do idioma (o formato vem da API, com d, m e Y): 13/10/2026, 10/13/2026, 2026-10-13; curto: sem o ano
function dataBr(t, curto) {
  var p = String(t).substr(0, 10).split("-");
  return (curto ? FORMATO.data_curta : FORMATO.data).replace(/[dmY]/g, function (c) { return c === "d" ? p[2] : (c === "m" ? p[1] : p[0]); });
}

// "AAAA-MM-DD HH:MM:SS" para "HH:MM"
function horaBr(t) {
  return String(t).substr(11, 5);
}

// Um instante "AAAA-MM-DD HH:MM:SS" em milissegundos, no fuso do navegador (o mesmo do servidor)
function instante(t) {
  var p = String(t).split(/[- :T]/);
  return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]), Number(p[3] || 0), Number(p[4] || 0), Number(p[5] || 0)).getTime();
}

// Trocar o relógio de um dia do plano (Hoje e Plano): a lista dos relógios disponíveis e o botão; no dia escolhido à mão
// ou livre (de amanhã em diante), também "voltar a sortear". p: o dia do plano (data, relogio_id, origem); hoje: AAAA-MM-DD;
// trocaHoje: o trocar_hoje da API (falso: o dia de hoje não se troca, e a célula diz que a troca vale de amanhã)
// As formas de escolha do "Sortear este dia por" (as mesmas chaves da API: rodizio trocar_dia e manual, sortear:<chave>)
var FORMAS_DE_ESCOLHA = [["inteligente", t("a maior nota")], ["ponderado", t("sorteio pela nota")], ["aleatorio", t("aleatório, todos com a mesma chance")], ["fifo", t("fila: o mais tempo sem uso")]];

// Um texto comprido (a observação do relógio, um campo de texto): até TEXTO_CURTO caracteres, inteiro; mais que isso, o
// começo (cortado numa palavra) com "…" e o botão redondo "+" que mostra o resto; aberto, o "−" recolhe (o sinal vem do CSS,
// pelo aria-expanded). chave: quem é o texto, para ele continuar aberto quando a página se atualiza sozinha
var TEXTO_CURTO = 110;
var TEXTOS_ABERTOS = {};
function textoRecolhivel(texto, chave) {
  texto = String(texto);
  if (texto.length <= TEXTO_CURTO) {
    return h(texto);
  }
  var corte = texto.lastIndexOf(" ", TEXTO_CURTO);
  if (corte < TEXTO_CURTO * 0.6) {
    corte = TEXTO_CURTO;
  }
  var aberto = TEXTOS_ABERTOS[chave] === true;
  return el("span", {"class": "texto-recolhe", "data-texto": chave},
    el("span", {"class": "texto-curto", "hidden": aberto}, h(texto.slice(0, corte).replace(/[\s,.;:]+$/, "")) + "…")
    + el("span", {"class": "texto-inteiro", "hidden": !aberto}, h(texto)) + "&nbsp;"
    + el("button", {"type": "button", "class": "mais-menos", "aria-expanded": aberto ? "true" : "false", "title": aberto ? t("Mostrar menos") : t("Mostrar o texto inteiro"),
      "aria-label": aberto ? t("Mostrar menos") : t("Mostrar o texto inteiro")}, ""));
}
document.addEventListener("click", function (ev) {
  var b = ev.target.closest(".texto-recolhe > .mais-menos");
  if (!b) {
    return;
  }
  var caixa = b.parentNode;
  var abrir = b.getAttribute("aria-expanded") !== "true";
  caixa.querySelector(".texto-curto").hidden = abrir;
  caixa.querySelector(".texto-inteiro").hidden = !abrir;
  b.setAttribute("aria-expanded", abrir ? "true" : "false");
  b.title = abrir ? t("Mostrar menos") : t("Mostrar o texto inteiro");
  b.setAttribute("aria-label", b.title);
  TEXTOS_ABERTOS[caixa.getAttribute("data-texto")] = abrir;
});

// O grupo "Sortear este dia por" de um seletor de dia (o trocar por…, o escolher… e o Rodízio manual): o dia é sorteado na
// hora por essa forma, entre os relógios do bloco do modo, e fica escolhido à mão com o motivo do sorteio
function opcoesSortearDia(atual) {
  return el("optgroup", {"label": t("Sortear este dia por")}, FORMAS_DE_ESCOLHA.map(function (f) {
    return el("option", {"value": "sortear:" + f[0], "selected": atual === "sortear:" + f[0]}, h(f[1]));
  }).join(""));
}

function formTrocarDia(p, relogios, hoje, trocaHoje) {
  if (p.data === hoje && !trocaHoje) {
    return el("span", {"class": "nota", "title": t("Configuração: Trocar o relógio de hoje no próprio dia")}, t("hoje fica (troca a partir de amanhã)"));
  }
  var opcoes = el("option", {"value": "", "selected": true, "disabled": true}, p.origem === "livre" ? t("escolher…") : t("trocar por…"))
    + el("optgroup", {"label": t("Um relógio")}, relogios.filter(function (r) { return r.disponivel && r.id !== p.relogio_id; }).map(function (r) {
      return el("option", {"value": r.id}, h(r.nome));
    }).join(""))
    + opcoesSortearDia("")
    + ((p.origem === "manual" || p.origem === "livre") && p.data > hoje ? el("option", {"value": "0"}, t("↺ voltar a sortear")) : "");
  return el("form", {"data-recurso": "rodizio", "class": "trocar-dia"}, el("input", {"type": "hidden", "name": "acao", "value": "trocar_dia"})
    + el("input", {"type": "hidden", "name": "data", "value": p.data})
    + el("select", {"name": "relogio_id", "required": true, "aria-label": t("Trocar o relógio de {1}", {1: dataBr(p.data)}), "onchange": "this.form.querySelector('button').hidden = false"}, opcoes)
    + el("button", {"class": "leve", "hidden": true}, t("Trocar")));
}

// O navegador de páginas (Próximos dias, Plano, Rodízio manual): "<" (Anterior), os números (a primeira, a última e até 2 de
// cada lado da atual, com "…" nos saltos; a atual marcada) e ">" (Próximo). Cada botão leva o número da página no atributo
// data-<atributo>; quem usa escuta o clique. Uma página só: nada
function navPaginas(pagina, paginas, atributo) {
  if (paginas <= 1) {
    return "";
  }
  var botao = function (n, texto, dica) {
    var a = {"type": "button", "class": "leve", "disabled": n < 1 || n > paginas, "title": dica, "aria-label": dica};
    a["data-" + atributo] = n;
    return el("button", a, texto);
  };
  var res = botao(pagina - 1, "&lt;", t("Anterior"));
  var ultimo = 0;
  for (var n = 1; n <= paginas; n++) {
    if (n === 1 || n === paginas || Math.abs(n - pagina) <= 2) {
      if (ultimo > 0 && n > ultimo + 1) {
        res += el("span", {"class": "reticencias"}, "…");
      }
      res += n === pagina ? el("span", {"class": "atual", "aria-current": "page", "title": t("Página {1}", {1: n})}, String(n)) : botao(n, String(n), t("Página {1}", {1: n}));
      ultimo = n;
    }
  }
  res += botao(pagina + 1, "&gt;", t("Próximo"));
  return el("nav", {"class": "paginas", "aria-label": t("Páginas")}, res);
}

// Apagar um período do plano (página Plano e quadro Modo de rodízio): os dias saem da tabela, de amanhã em diante, e o modo de
// rodízio monta esses dias de novo (rodizio periodo_sortear). Um dos lados pode ficar vazio: sem o começo, de amanhã até o
// fim; sem o fim, do começo até o último dia do plano. hoje: AAAA-MM-DD
function formApagarPeriodo(hoje) {
  return el("form", {"data-recurso": "rodizio", "class": "apagar-periodo", "onsubmit": "return confirm(this.getAttribute('data-confirma'))",
      "data-confirma": t("Apagar os dias deste período do plano (escolhidos à mão, sorteados e livres)? O modo de rodízio monta esses dias de novo.")},
    el("input", {"type": "hidden", "name": "acao", "value": "periodo_sortear"})
    + el("span", {"class": "campo-principal"}, t("Apagar um período"))
    + el("div", {"class": "linha-apagar"}, el("label", {}, t("De {1}", {1: el("input", {"type": "date", "name": "de", "min": hoje})}))
      + el("label", {}, t("Até {1}", {1: el("input", {"type": "date", "name": "ate", "min": hoje})}))
      + el("button", {"class": "leve"}, t("Apagar")))
    + ajudaNota("apagar-periodo", t("Apagar um período"), t("Os dias do período saem do plano, de amanhã em diante (os escolhidos à mão, os sorteados e os livres), e o modo de rodízio monta esses dias de novo. Pode deixar um lado vazio: só o começo apaga dele até o fim do plano; só o fim apaga de amanhã até ele.")));
}

// Apagar tudo (página Plano e quadro Modo de rodízio): toda a programação de amanhã em diante (com "Excluindo hoje", de hoje
// em diante) sai: os dias escolhidos à mão, os sorteados e os livres; o modo de rodízio monta o plano de novo (rodizio apagar_tudo).
// trocaHoje: o trocar_hoje da API (falso: sem o "Excluindo hoje", o dia de hoje fica)
function formApagarTudo(trocaHoje) {
  return el("form", {"data-recurso": "rodizio", "class": "apagar-tudo", "onsubmit": "return confirm(this.getAttribute(this.hoje && this.hoje.checked ? 'data-confirma-hoje' : 'data-confirma'))",
      "data-confirma-hoje": t("Apagar toda a programação, de hoje em diante (escolhidos à mão, sorteados e dias livres)? O modo de rodízio monta o plano de novo, e hoje pode sair outro relógio, que passa a ser o do pulso a partir de agora."),
      "data-confirma": t("Apagar toda a programação, de amanhã em diante (escolhidos à mão, sorteados e dias livres)? O modo de rodízio monta o plano de novo; hoje continua como está.")},
    el("input", {"type": "hidden", "name": "acao", "value": "apagar_tudo"})
    + el("span", {"class": "campo-principal"}, t("Apagar tudo"))
    + el("div", {"class": "linha-apagar"}, (trocaHoje ? el("label", {"class": "check"}, t("{1} Excluindo hoje (apaga também o dia de hoje)", {1: el("input", {"type": "checkbox", "name": "hoje", "value": "1"})})) : "")
      + el("button", {"class": "leve"}, t("Apagar tudo")))
    + ajudaNota("apagar-tudo", t("Apagar tudo"), trocaHoje ? t("Limpa toda a programação, de amanhã em diante: os dias escolhidos à mão, os sorteados e os livres. Marcando \"Excluindo hoje\", apaga de hoje em diante (se sair outro relógio para hoje, ele passa a ser o do pulso a partir de agora). Depois, o modo de rodízio monta o plano de novo, do zero.")
      : t("Limpa toda a programação, de amanhã em diante: os dias escolhidos à mão, os sorteados e os livres. Depois, o modo de rodízio monta o plano de novo, do zero. O dia de hoje fica: na Configuração, o relógio de hoje não se troca no próprio dia.")));
}

// Os dias do plano com os dias livres no meio, na ordem das datas (um dia livre: sem relógio, para escolher à mão)
function comLivres(plano, livres) {
  return plano.concat((livres || []).map(function (dt) {
    return {data: dt, relogio_id: null, relogio: null, origem: "livre", acao: null, motivo: null};
  })).sort(function (a, b) { return a.data < b.data ? -1 : (a.data > b.data ? 1 : 0); });
}

// A célula do relógio de um dia do plano: o nome (com "à mão"), ou "livre", e o motivo embaixo
function celulaDoDia(p) {
  return p.origem === "livre" ? el("span", {"class": "nota"}, t("livre: escolha à mão")) + " " + el("small", {"class": "a-mao"}, t("livre"))
    : h(p.relogio) + (p.origem === "manual" ? " " + el("small", {"class": "a-mao"}, t("à mão")) : "") + (p.motivo ? el("small", {"class": "motivo"}, h(p.motivo)) : "");
}

// Minúsculas, para os botões ("No sol" → "Pôs no sol")
function minusculo(t) {
  return String(t).toLowerCase();
}

// No celular as tabelas dos cadastros viram fichas (estilo.css), e cada célula mostra ao lado o título da sua coluna: ele
// vai no data-rotulo. Vale para toda tabela com cabeçalho, também as montadas depois (as linhas com colspan ficam sem).
function rotularTabelas() {
  document.querySelectorAll("table").forEach(function (t) {
    var titulos = t.tHead && t.tHead.rows.length > 0 ? Array.prototype.map.call(t.tHead.rows[0].cells, function (c) {
      // o rótulo é o título sem o ⓘ das explicações (o botão fica só no cabeçalho)
      var copia = c.cloneNode(true);
      copia.querySelectorAll(".ajuda-i").forEach(function (b) { b.remove(); });
      return copia.textContent.trim();
    }) : [];
    Array.prototype.forEach.call(titulos.length > 0 ? t.tBodies : [], function (corpo) {
      Array.prototype.forEach.call(corpo.rows, function (tr) {
        if (tr.cells.length === titulos.length) {
          Array.prototype.forEach.call(tr.cells, function (td, i) {
            if (titulos[i] !== "" && td.getAttribute("data-rotulo") !== titulos[i]) {
              td.setAttribute("data-rotulo", titulos[i]);
            }
          });
        }
      });
    });
  });
}
// As ações de uma linha (Editar, ↑ ↓, Excluir): o Excluir em vermelho, em todas as tabelas, também nas montadas depois
function marcarAcoes() {
  document.querySelectorAll(".acoes-linha button, .acoes-ev button").forEach(function (b) {
    if (b.textContent.trim().toLowerCase().indexOf(t("Excluir").toLowerCase()) === 0 && !b.classList.contains("perigo")) {
      b.classList.add("perigo");
    }
  });
}
var rotulosPedidos = false;
new MutationObserver(function () {
  if (!rotulosPedidos) {
    rotulosPedidos = true;
    setTimeout(function () {
      rotulosPedidos = false;
      rotularTabelas();
      marcarAcoes();
    }, 0);
  }
}).observe(document.body, {childList: true, subtree: true});
rotularTabelas();

// O que a API respondeu com erro: banco desatualizado vai para a Configuração (que aplica); o resto vira uma exceção
function tratarResposta(resp) {
  return resp.json().catch(function () { return {}; }).then(function (dados) {
    if (resp.status === 503 && dados.pendentes && window.location.pathname.indexOf("configuracao.php") < 0) {
      window.location = "configuracao.php";
    }
    // a conta sem coleção (a que pediu acesso e espera; a que saiu de todas): Minha conta, que cria uma ou pede acesso
    if (resp.status === 409 && dados.sem_colecao && window.location.pathname.indexOf("conta.php") < 0) {
      window.location = "conta.php";
    }
    // sem login: o login é da API (recurso=entrar), que pede o usuário e a senha e volta para esta página
    if (resp.status === 401) {
      window.location = "api.php?recurso=entrar&volta=" + encodeURIComponent((window.location.pathname.split("/").pop() || "index.php") + window.location.search + window.location.hash);
    }
    // a resposta vai junto no erro (dados.erros: o que a API explicou)
    if (!resp.ok && dados.ok === undefined) {
      var erro = new Error(dados.erro || (t("a API respondeu {1}", {1: resp.status})));
      erro.dados = dados;
      throw erro;
    }
    return dados;
  });
}

// Lê um recurso da API: api({recurso: "hoje"}) (depois do dicionário do idioma: a tela que vem em seguida já sai traduzida)
function api(parametros) {
  return textosProntos.then(function () {
    return fetch("api.php?" + new URLSearchParams(parametros || {}).toString(), { credentials: "same-origin" });
  }).then(tratarResposta);
}

// Grava pela API: dados é um FormData ou um objeto; devolve {ok, mensagem, erros, ...}
var GRAVANDO = 0;
function enviar(dados) {
  var corpo = dados;
  if (!(dados instanceof FormData)) {
    corpo = new FormData();
    Object.keys(dados).forEach(function (k) {
      corpo.append(k, dados[k]);
    });
  }
  // as gravações em andamento (o atualizarSozinho espera elas acabarem)
  GRAVANDO++;
  var fim = function (x) {
    GRAVANDO--;
    return x;
  };
  return fetch("api.php", { method: "POST", body: corpo, credentials: "same-origin" }).then(tratarResposta).then(fim, function (e) {
    fim();
    throw e;
  });
}

// O texto de uma resposta de gravação: a mensagem, ou os erros
function textoResposta(res) {
  return (res.mensagem || "") + (res.erros && res.erros.length > 0 ? " " + res.erros.join(" ") : "");
}

// O recado que atravessa um reload: fica no sessionStorage e a página seguinte o mostra na janelinha flutuante
// (avisoFlutuanteGravacao, abaixo) ao abrir
function avisoAoAbrir(texto, erro) {
  try {
    sessionStorage.setItem("aviso_flutuante", JSON.stringify({texto: texto, erro: erro === true}));
  } catch (e) {
    // sem sessionStorage (navegação privada antiga): o recado se perde, a gravação não
  }
}
(function () {
  var guardado = null;
  try {
    guardado = sessionStorage.getItem("aviso_flutuante");
    if (guardado) {
      sessionStorage.removeItem("aviso_flutuante");
    }
  } catch (e) {
    guardado = null;
  }
  if (guardado) {
    var a = JSON.parse(guardado);
    var mostrar = function () { avisoFlutuanteGravacao(a.texto, a.erro); };
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", mostrar);
    } else {
      mostrar();
    }
  }
}());

// Sem idioma escolhido no menu, vale o padrão (o de Minha conta, senão o da coleção, senão o da instalação; o idioma_padrao
// de recurso=conta&parte=menu, que vem a cada página): o cookie lingua é gravado aqui sozinho, marcado como automático
// (lingua_auto), e acompanha o padrão quando ele muda; só a escolha no seletor do menu apaga a marca e prende o idioma
function seguirIdiomaPadrao(padrao) {
  var cookieLingua = (document.cookie.match(/(?:^|;\s*)lingua=([^;]*)/) || [])[1] || "";
  var cookieAuto = (document.cookie.match(/(?:^|;\s*)lingua_auto=([^;]*)/) || [])[1] || "";
  if (padrao && padrao !== LINGUA && (cookieLingua === "" || cookieLingua === cookieAuto)) {
    document.cookie = "lingua=" + padrao + "; path=/; max-age=31536000; SameSite=Lax";
    document.cookie = "lingua_auto=" + padrao + "; path=/; max-age=31536000; SameSite=Lax";
    window.location.reload();
    return true;
  }
  return false;
}

// Gravou outro idioma na conta (Minha conta): vale como escolher no seletor do menu (o cookie lingua com ele, sem a marca
// lingua_auto) e a página abre de novo nele; sem idioma ("O da coleção"), sem cookie nenhum (o menu da página seguinte
// põe o que vale agora, seguirIdiomaPadrao). O recado vai na janelinha da página seguinte. A Configuração faz o mesmo por
// conta própria (configuracao.js, recarregarIdiomaConfig), voltando ao mesmo campo
function reabrirNoIdioma(recado, idioma) {
  avisoAoAbrir(recado, false);
  document.cookie = "lingua=" + (idioma ? idioma + "; path=/; max-age=31536000; SameSite=Lax" : "; path=/; max-age=0; SameSite=Lax");
  document.cookie = "lingua_auto=; path=/; max-age=0; SameSite=Lax";
  window.location.reload();
}

// Um instante no formato do campo de data e hora (AAAA-MM-DDTHH:MM), no fuso do navegador
function momentoNavegador(d) {
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, "0"), String(d.getDate()).padStart(2, "0")].join("-") + "T"
    + String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
}

// Os campos de data e hora que andam com o relógio do navegador: data-agora vem com a hora de agora e acompanha o relógio
// (a cada 15 s e ao clicar nele) até a pessoa mexer, e daí fica o que ela pôs; data-max-agora tem o máximo em agora, que
// também anda (senão, com a página aberta há horas, o navegador recusaria uma hora depois de quando ela abriu)
function acertarRelogios() {
  var agora = momentoNavegador(new Date());
  document.querySelectorAll("input[data-max-agora]").forEach(function (c) {
    c.max = agora;
  });
  document.querySelectorAll("input[data-agora]").forEach(function (c) {
    if (c !== document.activeElement) {
      c.value = agora;
    }
  });
}
setInterval(acertarRelogios, 15000);
document.addEventListener("focusin", function (ev) {
  var c = ev.target;
  if (c.matches && c.matches("input[data-agora], input[data-max-agora]")) {
    var agora = momentoNavegador(new Date());
    if (c.hasAttribute("data-max-agora")) {
      c.max = agora;
    }
    if (c.hasAttribute("data-agora")) {
      c.value = agora;
    }
  }
});
document.addEventListener("input", function (ev) {
  if (ev.target.hasAttribute && ev.target.hasAttribute("data-agora")) {
    ev.target.removeAttribute("data-agora");
  }
});

// Formulários que gravam pela API (data-recurso): o envio vai para o api.php, e depois a página chama o que ela
// registrou (aoGravar), com a resposta. O confirm() de alguns formulários já cancela o envio.
// Campos dentro de um bloco escondido ([data-sub] escondido) não vão.

// Guarda a posição antes do foco no botão (teclado, mouse, toque em celular/tablet).
var posicoesAntesDaGravacao = new WeakMap();
function posicaoAtualDaPagina() {
  var y = Math.min(window.innerHeight - 25, Math.max(90, Math.round(window.innerHeight * .32)));
  var el = document.elementFromPoint(Math.round(window.innerWidth / 2), y);
  var main = document.querySelector("main"), ancora = null;
  while (el && el !== main && el !== document.body) {
    if (el.id && main && main.contains(el)) {
      ancora = {id: el.id, topo: el.getBoundingClientRect().top};
      break;
    }
    el = el.parentElement;
  }
  return {x: window.scrollX, y: window.scrollY, ancora: ancora};
}
document.addEventListener("pointerdown", function (ev) {
  var b = ev.target.closest && ev.target.closest("button, input[type=submit]");
  if (b && b.form && b.form.hasAttribute("data-recurso")) {
    posicoesAntesDaGravacao.set(b.form, {quando: Date.now(), posicao: posicaoAtualDaPagina()});
  }
}, true);
function posicaoDaGravacao(form) {
  return (form && form.__posicaoGravacao) || posicaoAtualDaPagina();
}
function restaurarPosicaoDaGravacao(p) {
  if (!p) { return Promise.resolve(); }
  return new Promise(function (resolve) {
    requestAnimationFrame(function () {
      var e = p.ancora && document.getElementById(p.ancora.id);
      var y = e ? window.scrollY + e.getBoundingClientRect().top - p.ancora.topo : p.y;
      window.scrollTo({left: p.x || 0, top: Math.max(0, y), behavior: "instant"});
      requestAnimationFrame(resolve);
    });
  });
}
// A hora certa (a do servidor, recurso=hora_certa) para quem mede a precisão: a diferença para o relógio deste aparelho, em
// ms (descontando metade do tempo do pedido), e o deslocamento do fuso do pedido (as horas que a API usa), medidos uma vez,
// quando a tela mostra algum [data-hora-certa]; esses elementos mostram a hora certa andando, com os segundos
var horaCerta = {diferenca: null, fuso: 0, pedindo: false};
function horaCertaMedir() {
  var antes = Date.now();
  horaCerta.pedindo = true;
  return api({recurso: "hora_certa"}).then(function (d) {
    horaCerta.diferenca = d.agora_ms - Math.round((antes + Date.now()) / 2);
    horaCerta.fuso = d.fuso.segundos;
  }).catch(function () {
    return null;
  }).then(function () {
    horaCerta.pedindo = false;
  });
}
setInterval(function () {
  var mostra = document.querySelectorAll("[data-hora-certa]");
  if (mostra.length === 0) {
    return;
  }
  if (horaCerta.diferenca === null) {
    if (!horaCerta.pedindo) {
      horaCertaMedir();
    }
    return;
  }
  var a = new Date(Date.now() + horaCerta.diferenca + horaCerta.fuso * 1000);
  var dois = function (n) { return (n < 10 ? "0" : "") + n; };
  var texto = t("Hora certa agora: {1}", {1: dois(a.getUTCHours()) + ":" + dois(a.getUTCMinutes()) + ":" + dois(a.getUTCSeconds())});
  mostra.forEach(function (e) {
    if (e.textContent !== texto) {
      e.textContent = texto;
    }
  });
}, 200);

// O que uma gravação do rodízio mudou no plano (a resposta traz mudancas): o resumo e, quando algum dia mudou, a
// tabelinha dia a dia (antes → depois, com o motivo do novo), como a API mandou
function tabelaMudancas(m) {
  if (!m) { return ""; }
  var linhas = m.dias.map(function (x) {
    return el("tr", {}, el("td", {}, h(x.dia_semana_curto + " " + dataBr(x.data, true))) + el("td", {}, h(x.antes_texto)) + el("td", {}, h(x.depois_texto))
      + el("td", {}, h(x.motivo || "")));
  }).join("");
  return el("div", {"class": "mudancas-plano"}, el("p", {"class": "nota"}, h(m.texto))
    + (m.mudaram > 0 ? el("table", {"class": "tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Dia")) + el("th", {}, t("Antes")) + el("th", {}, t("Depois")) + el("th", {}, t("Motivo"))))
      + el("tbody", {}, linhas)) : ""));
}
function avisoFlutuanteGravacao(mensagem, erro, mudancas) {
  var antigo = document.getElementById("gravacao-flutuante");
  if (antigo) { antigo.remove(); }
  if (!mensagem) { return; }
  var caixa = document.createElement("div");
  caixa.id = "gravacao-flutuante";
  caixa.setAttribute("role", erro ? "alert" : "status");
  caixa.setAttribute("aria-live", erro ? "assertive" : "polite");
  caixa.style.cssText = "position:fixed;z-index:3000;left:1rem;right:1rem;bottom:calc(4.75rem + env(safe-area-inset-bottom, 0px));"
    + "margin:0 auto;max-width:32rem;max-height:40vh;overflow:auto;overflow-wrap:anywhere;"
    + "padding:.85rem 1rem;display:flex;align-items:center;gap:1rem;border-radius:12px;"
    + "background:var(--papel,white);color:var(--tinta,#202b36);box-shadow:0 8px 28px #0003;"
    + "border:2px solid " + (erro ? "#9b2c22" : "var(--safira,#254a6d)") + ";";
  var msg = document.createElement("div");
  msg.style.flex = "1";
  msg.style.minWidth = "0";
  var frase = document.createElement("span");
  frase.textContent = mensagem;
  msg.appendChild(frase);
  // com o que mudou no plano, a caixa fica até ser fechada (dá tempo de ler a tabelinha)
  var comTabela = !erro && mudancas && mudancas.mudaram > 0;
  if (!erro && mudancas) {
    var bloco = document.createElement("div");
    bloco.innerHTML = tabelaMudancas(mudancas);
    msg.appendChild(bloco);
  }
  var fechar = document.createElement("button");
  fechar.type = "button";
  fechar.className = "leve";
  fechar.textContent = "×";
  fechar.setAttribute("aria-label", t("Fechar"));
  fechar.style.cssText = "min-width:44px;min-height:44px;flex-shrink:0";
  var timer = null;
  var remover = function () { clearTimeout(timer); caixa.remove(); };
  fechar.addEventListener("click", remover);
  caixa.append(msg, fechar);
  document.body.appendChild(caixa);
  if (!erro && !comTabela) { timer = setTimeout(remover, 6000); }
  caixa.addEventListener("pointerenter", function () { clearTimeout(timer); });
  caixa.addEventListener("pointerleave", function () {
    if (!erro && !comTabela) { clearTimeout(timer); timer = setTimeout(remover, 6000); }
  });
}
function salvarSemMover(form, res, atualizar) {
  var posicao = posicaoDaGravacao(form);
  return Promise.resolve().then(atualizar).then(function () {
    if (form && form.isConnected && !res.ok) {
      form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
    }
    return restaurarPosicaoDaGravacao(posicao);
  }).then(function () { avisoFlutuanteGravacao(textoResposta(res), !res.ok, res.mudancas); });
}

var aoGravar = function (form, res) { window.location.reload(); };
document.addEventListener("submit", function (ev) {
  var form = ev.target;
  if (!ev.defaultPrevented && form.hasAttribute("data-recurso")) {
    ev.preventDefault();
    var ponto = posicoesAntesDaGravacao.get(form);
    form.__posicaoGravacao = ponto && Date.now() - ponto.quando < 10000 ? ponto.posicao : posicaoAtualDaPagina();
    var escondidos = [];
    form.querySelectorAll("[data-sub][hidden] input, [data-sub][hidden] select, [data-sub][hidden] textarea").forEach(function (c) {
      if (!c.disabled) {
        c.disabled = true;
        escondidos.push(c);
      }
    });
    var dados = new FormData(form);
    escondidos.forEach(function (c) {
      c.disabled = false;
    });
    // o campo de data e hora que ninguém mexeu (data-agora): "vazio" vai vazio (a API usa o agora dela, com os segundos);
    // os outros vão com o minuto de agora
    form.querySelectorAll("input[data-agora]").forEach(function (c) {
      if (c.name && !c.disabled) {
        dados.set(c.name, c.getAttribute("data-agora") === "vazio" ? "" : momentoNavegador(new Date()));
      }
    });
    // o instante exato do envio pela hora certa (data-instante-certo: o Testar a precisão), em milissegundos
    form.querySelectorAll("input[data-instante-certo]").forEach(function (c) {
      if (c.name && !c.disabled) {
        dados.set(c.name, String(Date.now() + (horaCerta.diferenca || 0)));
      }
    });
    dados.append("recurso", form.getAttribute("data-recurso"));
    if (ev.submitter && ev.submitter.name) {
      dados.append(ev.submitter.name, ev.submitter.value);
    }
    var botoes = form.querySelectorAll("button");
    botoes.forEach(function (b) {
      b.disabled = true;
    });
    enviar(dados)
      .then(function (res) {
        // a gravação que a API quer confirmada (a marcação da leitura que não bate com o pulso registrado): a pergunta dela;
        // com o sim, vai de novo com confirmar=1
        if (res.confirmar && res.pergunta) {
          if (!confirm(res.pergunta)) {
            botoes.forEach(function (b) {
              b.disabled = false;
            });
            return null;
          }
          dados.set("confirmar", "1");
          return enviar(dados).then(function (res2) {
            return aoGravar(form, res2, dados);
          });
        }
        // a exclusão em dois passos: a API diz o que sai junto; só com a confirmação ela apaga
        if (res.confirmar && res.impacto) {
          if (!confirm(res.impacto.texto + "\n\n" + t("Excluir mesmo assim?"))) {
            botoes.forEach(function (b) {
              b.disabled = false;
            });
            return null;
          }
          dados.set("confirmar", "1");
          return enviar(dados).then(function (res2) {
            return aoGravar(form, res2, dados);
          });
        }
        return aoGravar(form, res, dados);
      })
      .catch(function (e) {
        botoes.forEach(function (b) {
          b.disabled = false;
        });
        alert(t("Não consegui gravar: {1}. Confira a conexão e tente de novo.", {1: e.message}));
      });
  }
});

// A foto do relógio ampliada: duplo clique na foto do detalhe do relógio (o painel, ou a ficha), ou o botão "Abrir foto"
// embaixo dela, abre a imagem numa janela flutuante, no tamanho dela. Só ali: nas outras fotos (o "Hoje é dia de", as
// miniaturas da lista, o histórico) o clique já abre o relógio, e o duplo clique não faz nada a mais. A que não cabe é mostrada em escala, sem deformar, no maior tamanho
// que cabe no monitor em que a página está (screen.availWidth/availHeight) e na janela do navegador (a página não desenha
// fora dela); com o navegador em tela cheia, é o monitor. Muda de tamanho junto com a janela (e ao passar para outro
// monitor). Fecha com o ×, com um clique fora da imagem ou com Esc.
function fotoAmpliada(src, nome) {
  fecharFotoAmpliada();
  var caixa = document.createElement("div");
  caixa.className = "foto-ampliada";
  caixa.setAttribute("role", "dialog");
  caixa.setAttribute("aria-modal", "true");
  caixa.setAttribute("aria-label", nome || t("Foto do relógio"));
  caixa.innerHTML = el("figure", {}, el("button", {"type": "button", "class": "fechar-foto", "aria-label": t("Fechar"), "title": t("Fechar (Esc)")}, t("×"))
    + el("img", {"src": src, "alt": nome || ""}) + el("figcaption", {}, h(nome || "")));
  document.body.appendChild(caixa);
  document.body.classList.add("com-foto-ampliada");
  var img = caixa.querySelector("img");
  var ajustar = function () {
    if (!img.naturalWidth) {
      return;
    }
    // a área que se vê: o menor entre o monitor e a janela, menos a margem e a legenda
    var largura = Math.min(window.innerWidth, (window.screen && screen.availWidth) || window.innerWidth) - 32;
    var altura = Math.min(window.innerHeight, (window.screen && screen.availHeight) || window.innerHeight) - 72;
    var escala = Math.min(1, largura / img.naturalWidth, altura / img.naturalHeight);
    img.style.width = Math.floor(img.naturalWidth * escala) + "px";
    img.style.height = Math.floor(img.naturalHeight * escala) + "px";
    caixa.querySelector("figcaption").textContent = t("{1}{2} × {3} px{4}", {1: nome ? nome + " · " : "", 2: img.naturalWidth, 3: img.naturalHeight, 4: escala < 1 ? t(" (mostrada a {1}%)", {1: Math.round(escala * 100)}) : ""});
    caixa.classList.add("pronta");
  };
  img.addEventListener("load", ajustar);
  img.addEventListener("error", function () {
    caixa.querySelector("figcaption").textContent = t("Não consegui abrir a foto.");
    caixa.classList.add("pronta");
  });
  if (img.complete) {
    ajustar();
  }
  caixa.ajustar = ajustar;
  window.addEventListener("resize", ajustar);
  caixa.addEventListener("click", function (ev) {
    if (ev.target === caixa || ev.target.closest(".fechar-foto")) {
      fecharFotoAmpliada();
    }
  });
  caixa.querySelector(".fechar-foto").focus();
}

function fecharFotoAmpliada() {
  var caixa = document.querySelector(".foto-ampliada");
  if (caixa) {
    window.removeEventListener("resize", caixa.ajustar);
    caixa.remove();
  }
  document.body.classList.remove("com-foto-ampliada");
}

document.addEventListener("dblclick", function (ev) {
  var img = ev.target.closest(".ficha-foto img[data-foto-grande]");
  if (img) {
    ev.preventDefault();
    fotoAmpliada(img.getAttribute("src"), img.getAttribute("data-foto-grande"));
  }
});
document.addEventListener("click", function (ev) {
  var b = ev.target.closest("[data-abrir-foto]");
  if (b) {
    var img = b.closest(".ficha-foto").querySelector("img[data-foto-grande]");
    if (img) {
      fotoAmpliada(img.getAttribute("src"), img.getAttribute("data-foto-grande"));
    }
  }
});

// Esc fecha a foto ampliada antes de qualquer outra coisa (o painel do relógio, por baixo, continua aberto)
document.addEventListener("keydown", function (ev) {
  if (ev.key === "Escape" && document.querySelector(".foto-ampliada")) {
    ev.stopImmediatePropagation();
    fecharFotoAmpliada();
  }
}, true);

// "Inserir âncora" nos modelos de mensagem (Destinos e Agendas): a lista das âncoras ({relogio}, {acao}...), que põe a
// escolhida onde está o cursor do campo alvo (o id dele)
function listaAncoras(ancoras, alvo, rotulo) {
  return el("select", {"class": "ancora", "data-alvo": alvo, "aria-label": rotulo}, el("option", {"value": ""}, t("Inserir âncora")) + Object.keys(ancoras).map(function (k) {
    return el("option", {"value": "{" + k + "}"}, h("{" + k + "} " + ancoras[k]));
  }).join(""));
}
document.addEventListener("change", function (ev) {
  if (ev.target.matches("select.ancora")) {
    var sel = ev.target;
    var alvo = document.getElementById(sel.dataset.alvo);
    if (alvo && sel.value) {
      var i = alvo.selectionStart;
      var f = alvo.selectionEnd;
      alvo.value = alvo.value.slice(0, i) + sel.value + alvo.value.slice(f);
      alvo.focus();
      alvo.selectionStart = alvo.selectionEnd = i + sel.value.length;
    }
    sel.value = "";
  }
});

// "O que recebe" um destino ou uma agenda: cada tipo (o relógio do dia, a véspera, os avisos, os eventos) com a caixa
// (envia[]) e, se $proprio, o modelo próprio (modelo_<tipo>; vazio: a mensagem padrão), dentro de um "Personalizar"
function tabelaRecebe(prefixo, recebe, ancoras, proprio) {
  return el("input", {"type": "hidden", "name": "envia[]", "value": ""}) + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados recebe"},
    el("thead", {}, el("tr", {}, el("th", {}, t("Recebe")) + el("th", {}, t("Aviso")) + el("th", {}, t("Quando")) + (proprio ? el("th", {}, t("Texto")) : "")))
    + el("tbody", {}, recebe.map(function (r) {
      var id = prefixo + "-m-" + r.tipo;
      return el("tr", {}, el("td", {"class": "centro"}, el("input", {"type": "checkbox", "name": "envia[]", "value": r.tipo, "checked": r.envia, "aria-label": r.nome}))
        + el("td", {}, h(r.nome)) + el("td", {"class": "nota"}, h(r.quando))
        + (proprio ? el("td", {}, el("details", {"open": r.modelo !== null}, el("summary", {}, r.modelo !== null ? t("Personalizado") : t("Personalizar"))
          + el("div", {"class": "campo-modelo"}, el("div", {"class": "rotulo-modelo"}, el("label", {"for": id}, t("Texto de {1}", {1: h(r.nome)})) + listaAncoras(ancoras, id, t("Inserir âncora")))
            + el("textarea", {"id": id, "name": "modelo_" + r.tipo, "rows": "3", "placeholder": t("Vazio: a mensagem padrão")}, h(r.modelo || ""))))) : ""));
    }).join(""))));
}

// Uma opção numa linha: o rótulo à esquerda e o campo à direita, na mesma coluna em todos os quadros (a Configuração, os Destinos, as Agendas, a Conta). "check": a caixa no
// lugar do campo; "largo": o rótulo em cima e o campo na largura toda (textos longos, como um endereço)
// as opções de um seletor de fuso horário: a primeira é o "vazio" (o da coleção, o do sistema) e depois todos os fusos que o
// PHP conhece (recurso=conta, configuracao ou usuarios: fusos[]), cada um com o deslocamento de agora ("UTC−03:00")
// (com navegadorTexto, a opção "navegador": o fuso do aparelho de quem entra; fusoNavegador() diz qual é o deste aparelho)
function fusoNavegador() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "";
  } catch (e) {
    return "";
  }
}

function opcoesFuso(fusos, atual, vazioTexto, navegadorTexto) {
  return el("option", {"value": "", "selected": !atual}, vazioTexto) + (navegadorTexto ? el("option", {"value": "navegador", "selected": atual === "navegador"}, navegadorTexto) : "") + (fusos || []).map(function (f) {
    return el("option", {"value": f.nome, "selected": atual === f.nome}, h(f.nome.replace(/_/g, " ")) + " (UTC" + h(f.deslocamento.replace("-", "\u2212")) + ")");
  }).join("");
}

// um rótulo com o controle; explica: o ⓘ das explicações (ajuda()), ao lado do rótulo
function parCampo(rotulo, controle, tipo, explica) {
  return el("label", {"class": "par-campo" + (tipo ? " " + tipo : "")}, el("span", {"class": "rotulo"}, h(rotulo) + (explica || "")) + el("span", {"class": "controle"}, controle));
}

// Uma senha nova com a confirmação (Criar conta, a senha nova pelo link, Minha conta, Usuários): os dois campos e, embaixo,
// a força pedida (o texto da API) e, enquanto a pessoa digita, o que a API diz da senha: cada regra com ✓ ou ✗, a
// confirmação e a força. Quem confere é a API (recurso=cadastro, acao=conferir_senha, sem gravar); a tela só mostra
function camposSenha(rotulo, politica, obrigatoria) {
  return parCampo(rotulo, el("input", {"type": "password", "name": "senha", "required": !!obrigatoria, "autocomplete": "new-password", "data-senha-nova": true}))
    + parCampo(t("Confirmar a senha"), el("input", {"type": "password", "name": "senha_confirmacao", "required": !!obrigatoria, "autocomplete": "new-password", "data-senha-nova": true}))
    + caixaSenha(politica);
}

// A caixa da conferência da senha: começa com o texto da força pedida (o mesmo que ela mostra quando os campos ficam vazios)
function caixaSenha(politica) {
  return el("div", {"class": "senha-conferencia largo", "aria-live": "polite", "data-politica": politica.texto}, el("p", {"class": "nota"}, h(politica.texto)));
}

// Enquanto a pessoa digita a senha nova ou a confirmação: espera ela parar um instante e pergunta à API; a resposta mais
// nova é a que fica (uma antiga que chega depois não apaga)
var senhaConferida = {vez: 0, espera: null};
document.addEventListener("input", function (ev) {
  var campo = ev.target;
  if (!campo.hasAttribute || !campo.hasAttribute("data-senha-nova") || !campo.form) {
    return;
  }
  var form = campo.form;
  var caixa = form.querySelector(".senha-conferencia");
  if (!caixa) {
    return;
  }
  var senha = form.querySelector("[name=senha]").value;
  var conf = form.querySelector("[name=senha_confirmacao]");
  var vez = ++senhaConferida.vez;
  clearTimeout(senhaConferida.espera);
  if (senha === "" && (!conf || conf.value === "")) {
    caixa.innerHTML = el("p", {"class": "nota"}, h(caixa.getAttribute("data-politica")));
    return;
  }
  senhaConferida.espera = setTimeout(function () {
    enviar({recurso: "cadastro", acao: "conferir_senha", senha: senha, senha_confirmacao: conf ? conf.value : ""}).then(function (res) {
      var c = res.conferencia;
      if (vez !== senhaConferida.vez || !c) {
        return;
      }
      caixa.innerHTML = el("ul", {"class": "senha-regras"}, c.regras.map(function (r) {
        return el("li", {"class": r.ok ? "ok" : "falta"}, (r.ok ? "✓ " : "✗ ") + h(r.texto));
      }).join("")) + el("p", {"class": "senha-forca forca-" + c.forca}, t("Força: {1}", {1: el("strong", {}, h(c.forca_texto))})
        + el("span", {"class": "barra-forca"}, el("span", {"style": "width: " + (c.forca * 25) + "%"}, "")));
    }, function () {});
  }, 250);
});

// O pedido de acesso pelo código (a Criar conta e a Minha conta): o código que o dono passou, de uma coleção ou do dono (todas
// as coleções dele). Nenhuma lista de contas nem de coleções: quem pede não vê quem tem conta. O acessoPorCodigo, no envio do
// formulário, passa o código para o colecao_id ou o dono_id. Junto, o passo a passo: por que é pelo código, como pedir o código
// ao dono, onde o dono o encontra e o que acontece depois (aberto na Criar conta; fechado em Minha conta)
function explicacaoPedido(aberto) {
  return el("details", {"class": "como-pedir", "open": !!aberto}, el("summary", {}, t("Como pedir acesso (e por que pelo código)"))
    + el("ol", {}, el("li", {}, t("Por que o código: para proteger quem usa o sistema, nenhuma página mostra a lista de contas nem de coleções. O login é metade do que alguém precisa para tentar entrar numa conta alheia, e o nome de uma coleção costuma levar o login do dono. Por isso o pedido é feito pelo código que o próprio dono passa: quem não recebeu o código não tem como saber de quem ele é."))
      + el("li", {}, t("Peça o código ao dono (por mensagem, e-mail ou pessoalmente). Ele o encontra em Minha conta: o código dele, em Seus dados, dá acesso a todas as coleções dele, também às que ele criar depois; o código de uma coleção só fica na coluna Código da tabela Coleções."))
      + el("li", {}, t("Aqui, escolha se o código é de uma coleção ou do dono, digite o número e deixe um recado dizendo quem você é: o dono vê só o seu login."))
      + el("li", {}, t("O dono recebe um e-mail e aprova ou recusa em Aprovações. Até lá, o seu pedido mostra só o código. Aprovado, você recebe um e-mail e a coleção aparece no seletor do menu."))));
}

function camposCodigoAcesso(obrigatorio, aberto) {
  return el("input", {"type": "hidden", "name": "colecao_id", "value": ""}) + el("input", {"type": "hidden", "name": "dono_id", "value": ""})
    + parCampo(t("Acesso a"), el("select", {"name": "tipo_codigo"}, el("option", {"value": "c"}, t("uma coleção (o código da coleção)"))
      + el("option", {"value": "d"}, t("todas as coleções de um dono (o código do dono)"))), "largo")
    + parCampo(t("Código"), el("input", {"type": "number", "name": "codigo", "min": "1", "step": "1", "inputmode": "numeric", "required": !!obrigatorio}))
    + explicacaoPedido(aberto);
}

function acessoPorCodigo(form) {
  if (form.codigo) {
    form.colecao_id.value = form.tipo_codigo.value === "c" ? form.codigo.value : "";
    form.dono_id.value = form.tipo_codigo.value === "d" ? form.codigo.value : "";
  }
}

// O menu de quem entrou (recurso=conta, parte=menu; não na página pública do cadastro): o seletor da coleção (com mais de
// uma: grava o cookie colecao e abre a página de novo), o aviso da conta de demonstração (os dias que faltam), as aprovações
// que esperam (o link para a página Aprovações), o Usuários (só o master) e a faixa "Vendo como" (o master vendo o sistema
// como outro usuário: o botão volta a ele, apagando o cookie como)
var CONTA_MENU = null;
function escolherColecao(id) {
  document.cookie = "colecao=" + id + "; path=/; max-age=31536000; SameSite=Lax";
  window.location.reload();
}
function verComo(login) {
  document.cookie = "como=" + encodeURIComponent(login) + "; path=/; SameSite=Lax" + (login === "" ? "; max-age=0" : "");
  document.cookie = "colecao=; path=/; max-age=0; SameSite=Lax";
  window.location = "index.php";
}
// O modo da interface (Minha conta): no básico, a API manda o que fica de fora (ocultos), as páginas pelo arquivo e as
// partes pelo nome que a tela põe nelas (avancado(), abaixo). Some do menu a página (e o grupo que fica vazio), some da
// tela a parte marcada, e a página do avançado aberta direto ganha o aviso com o botão que passa para o avançado
function avancado(id, html) {
  return String(html).replace(/^(\s*<[a-zA-Z0-9]+)/, "$1 data-avancado=\"" + id + "\"");
}
function aplicarNivel(ocultos) {
  if (ocultos.length === 0) {
    return;
  }
  var estilo = document.createElement("style");
  estilo.textContent = ocultos.map(function (x) { return "[data-avancado=\"" + x + "\"]"; }).join(", ") + " { display: none !important; }";
  document.head.appendChild(estilo);
  document.querySelectorAll(".menu-itens a[href]").forEach(function (a) {
    if (ocultos.indexOf(a.getAttribute("href")) >= 0) {
      a.hidden = true;
    }
  });
  document.querySelectorAll(".menu-grupo").forEach(function (g) {
    g.hidden = g.querySelectorAll(".menu-sub a:not([hidden])").length === 0;
  });
  var pagina = (window.location.pathname.split("/").pop() || "index.php");
  if (ocultos.indexOf(pagina) >= 0) {
    var faixa = document.createElement("div");
    faixa.className = "faixa-vendo-como faixa-nivel";
    faixa.innerHTML = t("Esta tela é do modo avançado: no básico, ela fica fora do menu.") + " "
      + el("button", {"type": "button", "class": "leve", "onclick": "usarAvancado()"}, t("Usar o modo avançado"));
    document.body.insertBefore(faixa, document.body.firstChild);
  }
}
function usarAvancado() {
  enviar({recurso: "conta", acao: "alterar", nivel: "avancado"}).then(function () { window.location.reload(); });
}
var contaPronta = document.querySelector("[data-menu-conta]") ? api({recurso: "conta", parte: "menu"}).then(function (m) {
  CONTA_MENU = m;
  // o intervalo do atualizar sozinho (a Configuração da coleção)
  if (typeof m.atualizar_segundos === "number") {
    ATUALIZAR_SEGUNDOS = m.atualizar_segundos;
  }
  // o reloginho da barra: o modelo de cada hora do dia, no modo da Configuração (o relojinho.js desenha)
  if (m.relojinho && m.relojinho.ordem) {
    window.RELOJINHO_ORDEM = m.relojinho.ordem;
  }
  if (seguirIdiomaPadrao(m.idioma_padrao)) {
    return m;
  }
  var lugar = document.querySelector("[data-menu-conta]");
  var res = "";
  if (m.colecoes.length > 1) {
    res += el("label", {"class": "menu-colecao"}, el("span", {"class": "visualmente-oculto"}, t("Coleção")) + el("select", {"aria-label": t("Coleção"), "onchange": "escolherColecao(this.value)"},
      m.colecoes.map(function (c) {
        return el("option", {"value": c.id, "selected": c.id === m.colecao}, h(c.nome) + (c.papel !== "dono" ? " · " + h(c.dono) : ""));
      }).join("")));
  }
  if (m.aprovacoes > 0) {
    res += el("a", {"href": "aprovacoes.php", "class": "menu-aviso", "title": t("Pedidos e convites esperando a sua resposta")}, t("Aprovações ({1})", {1: m.aprovacoes}));
  }
  if (m.usuario && m.usuario.demo) {
    res += el("a", {"href": "conta.php", "class": "menu-aviso", "title": t("Conta de demonstração: depois disso, ela e as suas coleções são apagadas. Peça ao administrador para torná-la oficial.")},
      t("Demo: {1} dias", {1: m.usuario.dias_restantes}));
  }
  lugar.innerHTML = res;
  aplicarNivel(m.ocultos || []);
  if (m.master) {
    document.querySelectorAll("[data-so-master]").forEach(function (a) {
      a.hidden = false;
    });
  }
  if (m.vendo_como) {
    var faixa = document.createElement("div");
    faixa.className = "faixa-vendo-como";
    faixa.innerHTML = t("Vendo como {1}: o que aparece e o que se grava é da conta dele.", {1: el("strong", {}, h(m.vendo_como.login))}) + " "
      + el("button", {"type": "button", "class": "leve", "onclick": "verComo('')"}, t("Voltar a ser {1}", {1: h(m.vendo_como.master)}));
    document.body.insertBefore(faixa, document.body.firstChild);
  }
  return m;
}).catch(function () { return null; })
  // a página pública (o cadastro), sem menu: o padrão da instalação vem do recurso=textos em JSON (não fica em cache)
  : api({recurso: "textos"}).then(function (d) { seguirIdiomaPadrao(d.padrao); return null; }).catch(function () { return null; });

// O aplicativo no celular (PWA): toda página registra o service worker (pela API: o cache das telas e as notificações do
// Web Push) e guarda o pedido de instalação do navegador (o Chrome avisa que dá para instalar: o botão "Instalar o
// aplicativo" de Minha conta usa) — nada disso muda a página
var INSTALAR_APP = null;
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("api.php?recurso=sw").catch(function () {});
}
window.addEventListener("beforeinstallprompt", function (e) {
  e.preventDefault();
  INSTALAR_APP = e;
  document.dispatchEvent(new CustomEvent("app-instalavel"));
});

// Pôr o HTML novo num bloco sem piscar: em vez de trocar o conteúdo inteiro (innerHTML), compara o novo com o que está na
// tela e mexe só no que mudou: um texto, um atributo, uma linha que entrou ou saiu. O que não mudou fica como está (a foto
// não é baixada nem desenhada de novo, a linha não pisca). As linhas e os blocos com data-id (ou id) são achados pela
// chave, mesmo que a ordem tenha mudado. Os campos de formulário ficam com o valor do HTML novo (as páginas só se
// atualizam sozinhas sem nada mexido, paginaOcupada). Bloco vazio: o innerHTML de sempre.
function porHtml(alvo, html) {
  if (!alvo.firstChild) {
    alvo.innerHTML = html;
    return;
  }
  var molde = document.createElement("template");
  molde.innerHTML = html;
  morfarFilhos(alvo, molde.content);
}
function chaveDoNo(n) {
  if (n.nodeType !== 1) {
    return null;
  }
  var k = n.getAttribute("data-id");
  return k !== null ? n.tagName + "#" + k : (n.id ? n.tagName + "@" + n.id : null);
}
function morfarFilhos(velho, novo) {
  var porChave = {};
  Array.prototype.forEach.call(velho.childNodes, function (c) {
    var k = chaveDoNo(c);
    if (k !== null) {
      porChave[k] = c;
    }
  });
  var cursor = velho.firstChild;
  Array.prototype.slice.call(novo.childNodes).forEach(function (n) {
    var k = chaveDoNo(n);
    var achado = null;
    if (k !== null && porChave[k]) {
      achado = porChave[k];
      delete porChave[k];
    } else if (k === null && cursor && chaveDoNo(cursor) === null && cursor.nodeType === n.nodeType && (n.nodeType !== 1 || cursor.tagName === n.tagName)) {
      achado = cursor;
    }
    if (achado === null) {
      velho.insertBefore(n, cursor);
      return;
    }
    if (achado === cursor) {
      cursor = cursor.nextSibling;
    } else {
      velho.insertBefore(achado, cursor);
    }
    morfarNo(achado, n);
  });
  while (cursor) {
    var depois = cursor.nextSibling;
    velho.removeChild(cursor);
    cursor = depois;
  }
}
function morfarNo(a, b) {
  if (a.nodeType !== 1) {
    if (a.nodeValue !== b.nodeValue) {
      a.nodeValue = b.nodeValue;
    }
    return;
  }
  Array.prototype.slice.call(a.attributes).forEach(function (at) {
    if (!b.hasAttribute(at.name)) {
      a.removeAttribute(at.name);
    }
  });
  Array.prototype.forEach.call(b.attributes, function (at) {
    if (a.getAttribute(at.name) !== at.value) {
      a.setAttribute(at.name, at.value);
    }
  });
  // o valor de agora dos campos (a propriedade, não só o atributo)
  if (a.tagName === "INPUT" && a.type !== "file") {
    if (a.type === "checkbox" || a.type === "radio") {
      a.checked = b.hasAttribute("checked");
    } else if (a.value !== (b.getAttribute("value") || "")) {
      a.value = b.getAttribute("value") || "";
    }
  } else if (a.tagName === "OPTION") {
    a.selected = b.hasAttribute("selected");
  } else if (a.tagName === "TEXTAREA") {
    if (a.value !== b.textContent) {
      a.value = b.textContent;
    }
    return;
  }
  morfarFilhos(a, b);
}

// Atualizar sozinho: a página passa a função que lê tudo de novo da API (a carga, a autonomia, os avisos, os contadores e
// o relógio do dia são calculados na hora do pedido) e ela roda de ATUALIZAR_SEGUNDOS em ATUALIZAR_SEGUNDOS (a Configuração,
// que vem com o menu: de 1 a 86400; 0, não roda) com a aba à mostra, e na hora em que a aba volta a aparecer. Nunca por
// cima de quem está mexendo: com um campo de texto em foco, um formulário de gravação com algo mudado e não gravado, uma
// gravação em andamento, ou o que a página disser (ocupada); a vez fica para a próxima volta. A rolagem fica onde estava
// (menos se a pessoa rolou enquanto a página lia).
var ATUALIZAR_SEGUNDOS = 60;
var MEXEU_NA_ROLAGEM = 0;
document.addEventListener("input", marcarMexido, true);
document.addEventListener("change", marcarMexido, true);
["wheel", "touchmove", "keydown", "mousedown"].forEach(function (ev) {
  document.addEventListener(ev, function () { MEXEU_NA_ROLAGEM = Date.now(); }, {capture: true, passive: true});
});
function marcarMexido(ev) {
  var f = ev.target && ev.target.closest ? ev.target.closest("form[data-recurso]") : null;
  if (f) {
    f.setAttribute("data-mexido", "");
  }
}
function paginaOcupada() {
  var a = document.activeElement;
  var texto = a && a.matches && (a.matches("textarea, [contenteditable], input:not([type]), input[type=text], input[type=search], input[type=number], input[type=date], input[type=datetime-local], input[type=time], input[type=email], input[type=password], input[type=url]")
    || (a.matches("select") && a.closest("form[data-recurso]")));
  return Boolean(texto) || GRAVANDO > 0 || document.querySelector("form[data-mexido], dialog[open]") !== null;
}
function atualizarSozinho(ler, ocupada) {
  var ultima = Date.now();
  var lendo = false;
  var tentar = function () {
    var s = ATUALIZAR_SEGUNDOS;
    if (!(s > 0) || document.hidden || lendo || Date.now() - ultima < s * 1000 - 200 || paginaOcupada() || (ocupada && ocupada())) {
      return;
    }
    lendo = true;
    var comeco = Date.now();
    var posicao = posicaoAtualDaPagina();
    Promise.resolve().then(ler).then(function () {
      return MEXEU_NA_ROLAGEM > comeco ? null : restaurarPosicaoDaGravacao(posicao);
    }).catch(function () {}).then(function () {
      ultima = Date.now();
      lendo = false;
    });
  };
  // a volta: no ritmo do intervalo (até 5 s entre uma olhada e outra, para pegar a mudança do intervalo e a aba que volta)
  var laco = function () {
    tentar();
    var s = ATUALIZAR_SEGUNDOS;
    setTimeout(laco, s > 0 ? Math.min(s * 1000, 5000) : 5000);
  };
  setTimeout(laco, 1000);
  document.addEventListener("visibilitychange", tentar);
  window.addEventListener("focus", tentar);
}
