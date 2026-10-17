// Hora certa: a hora da API (recurso=hora_certa). O relógio da página acerta pela API (agora_ms, descontando metade do tempo
// do pedido) e anda sozinho, segundo a segundo; a cada minuto lê de novo (a data, a lua, o fuso). A diferença para o relógio
// deste aparelho aparece embaixo.
var dadosHora = null;
var diferenca = 0;   // a hora certa menos a deste aparelho, em ms

function dois(n) {
  return String(n).padStart(2, "0");
}

// O mostrador analógico, em SVG: a caixa de aço, o aro escuro, o mostrador claro com 60 marcas e os índices das horas, os
// numerais 12, 6 e 9, a janela da data no 3, o nome do sistema; ponteiros das horas e dos minutos em forma de lança (com a
// faixa luminosa), o dos segundos vermelho fino com o contrapeso. Com anadigi, um visor de cristal líquido no meio de baixo
// do mostrador (a hora e a data). Os ângulos saem da hora mostrada (andar)
function mostrador(anadigi) {
  var marcas = "";
  for (var i = 0; i < 60; i++) {
    var grande = i % 5 === 0;
    marcas += grande
      ? "<rect x=\"-1.6\" y=\"-98\" width=\"3.2\" height=\"13\" rx=\"1.2\" class=\"marca-h\" transform=\"rotate(" + (i * 6) + ")\"></rect>"
      : "<line x1=\"0\" y1=\"-98\" x2=\"0\" y2=\"-93\" class=\"marca-m\" transform=\"rotate(" + (i * 6) + ")\"></line>";
  }
  var visor = !anadigi ? "" : "<g class=\"anadigi\"><rect x=\"-46\" y=\"24\" width=\"92\" height=\"40\" rx=\"4\" class=\"visor\"></rect>"
    + "<text id=\"anadigi-data\" x=\"0\" y=\"38\" class=\"visor-data\" text-anchor=\"middle\"></text>"
    + "<text id=\"anadigi-hora\" x=\"0\" y=\"58\" class=\"visor-hora\" text-anchor=\"middle\"></text></g>";
  return "<svg class=\"mostrador\" viewBox=\"0 0 240 240\" role=\"img\" aria-label=\"" + h(t("Mostrador com a hora certa")) + "\">"
    + "<defs><radialGradient id=\"g-dial\" cx=\"50%\" cy=\"42%\" r=\"60%\"><stop offset=\"0\" stop-color=\"#ffffff\"/><stop offset=\"1\" stop-color=\"#e3e7ec\"/></radialGradient>"
    + "<linearGradient id=\"g-caixa\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"#f4f6f8\"/><stop offset=\".5\" stop-color=\"#9aa5b1\"/><stop offset=\"1\" stop-color=\"#e6e9ed\"/></linearGradient>"
    + "<filter id=\"f-sombra\" x=\"-20%\" y=\"-20%\" width=\"140%\" height=\"140%\"><feDropShadow dx=\"0\" dy=\"1.5\" stdDeviation=\"1.2\" flood-color=\"#000\" flood-opacity=\".35\"/></filter></defs>"
    + "<g transform=\"translate(120 120)\" aria-hidden=\"true\">"
    + "<circle r=\"118\" class=\"caixa\"></circle><circle r=\"109\" class=\"aro\"></circle><circle r=\"101\" class=\"dial\"></circle>" + marcas
    + "<text x=\"0\" y=\"-62\" class=\"numeral\" text-anchor=\"middle\">12</text><text x=\"0\" y=\"84\" class=\"numeral\" text-anchor=\"middle\">6</text>"
    + "<text x=\"-72\" y=\"7\" class=\"numeral\" text-anchor=\"middle\">9</text>"
    + "<rect x=\"62\" y=\"-9\" width=\"26\" height=\"18\" rx=\"2\" class=\"janela\"></rect><text id=\"data-dia\" x=\"75\" y=\"5\" class=\"janela-dia\" text-anchor=\"middle\"></text>"
    + "<text x=\"0\" y=\"-34\" class=\"marca-nome\" text-anchor=\"middle\">RELOJOEIRO</text>" + (anadigi ? "" : "<text x=\"0\" y=\"48\" class=\"marca-sub\" text-anchor=\"middle\">" + h(t("HORA CERTA")) + "</text>") + visor
    + "<g filter=\"url(#f-sombra)\"><g id=\"p-hora\"><path d=\"M-5 14 L-3.2 -50 L0 -60 L3.2 -50 L5 14 Z\" class=\"p-hora\"></path><path d=\"M-1.4 -46 L0 -54 L1.4 -46 L1.4 -14 L-1.4 -14 Z\" class=\"lume\"></path></g>"
    + "<g id=\"p-min\"><path d=\"M-4 16 L-2.4 -80 L0 -92 L2.4 -80 L4 16 Z\" class=\"p-min\"></path><path d=\"M-1.2 -76 L0 -86 L1.2 -76 L1.2 -16 L-1.2 -16 Z\" class=\"lume\"></path></g>"
    + "<g id=\"p-seg\"><line x1=\"0\" y1=\"24\" x2=\"0\" y2=\"-94\" class=\"p-seg\"></line><circle cy=\"18\" r=\"4.5\" class=\"contrapeso\"></circle></g></g>"
    + "<circle r=\"5.5\" class=\"eixo\"></circle><circle r=\"2.2\" class=\"eixo-centro\"></circle></g></svg>";
}

// O relógio digital: a caixa escura com o visor de cristal líquido (a data e o dia da semana em cima, a hora grande no meio,
// com os "88:88:88" apagados atrás, como num visor de verdade, e o UTC embaixo)
function digitalLcd(d) {
  return el("div", {"class": "lcd-caixa", "role": "img", "aria-label": h(t("Relógio digital com a hora certa"))}, el("div", {"class": "lcd-marca"}, el("span", {}, "RELOJOEIRO") + el("span", {}, h(d.fuso.abreviacao)))
    + el("div", {"class": "lcd"}, el("div", {"class": "lcd-linha"}, el("span", {"id": "lcd-data"}, "") + el("span", {}, "24H"))
      + el("div", {"class": "lcd-digitos"}, el("span", {"class": "fantasma", "aria-hidden": "true"}, "88:88:88") + el("span", {"id": "digital", "aria-live": "off"}, h(d.local.hora)))
      + el("div", {"class": "lcd-linha"}, el("span", {"id": "lcd-utc"}, "") + el("span", {}, h(d.lua.simbolo)))));
}

// a data curta do visor: o dia da semana abreviado (três letras, maiúsculas) e DD/MM
function dataVisor(d) {
  return d.local.dia_semana.replace(/-.*$/, "").substring(0, 3).toUpperCase() + " " + d.local.data.substring(8, 10) + "/" + d.local.data.substring(5, 7);
}

// a hora certa agora, no fuso do sistema (o deslocamento da API), em partes
function horaCerta() {
  var t = new Date(Date.now() + diferenca + dadosHora.fuso.segundos * 1000);
  return {h: t.getUTCHours(), m: t.getUTCMinutes(), s: t.getUTCSeconds(), utc: new Date(Date.now() + diferenca)};
}

function andar() {
  if (!dadosHora) {
    return;
  }
  var c = horaCerta();
  var hora = dois(c.h) + ":" + dois(c.m) + ":" + dois(c.s);
  var utc = t("{1}:{2}:{3} UTC", {1: dois(c.utc.getUTCHours()), 2: dois(c.utc.getUTCMinutes()), 3: dois(c.utc.getUTCSeconds())});
  var pedacos = {"digital": hora, "anadigi-hora": hora, "lcd-utc": utc, "digital-utc": utc, "lcd-data": dataVisor(dadosHora), "anadigi-data": dataVisor(dadosHora), "data-dia": dadosHora.local.data.substring(8, 10)};
  Object.keys(pedacos).forEach(function (id) {
    var e = document.getElementById(id);
    if (e && e.textContent !== pedacos[id]) {
      e.textContent = pedacos[id];
    }
  });
  var ph = document.getElementById("p-hora");
  if (ph) {
    ph.setAttribute("transform", "rotate(" + ((c.h % 12) * 30 + c.m * 0.5) + ")");
    document.getElementById("p-min").setAttribute("transform", "rotate(" + (c.m * 6 + c.s * 0.1) + ")");
    document.getElementById("p-seg").setAttribute("transform", "rotate(" + (c.s * 6) + ")");
  }
}

function ler() {
  var antes = Date.now();
  return api({recurso: "hora_certa"}).then(function (d) {
    var depois = Date.now();
    dadosHora = d;
    diferenca = d.agora_ms - Math.round((antes + depois) / 2);
    var seg = Math.round(-diferenca / 100) / 10;
    var aparelho = Math.abs(seg) < 0.5 ? t("O relógio deste aparelho está certo (menos de meio segundo de diferença).")
      : (seg > 0 ? t("O relógio deste aparelho está {1} s adiantado.", {1: num(Math.abs(seg), 1)}) : t("O relógio deste aparelho está {1} s atrasado.", {1: num(Math.abs(seg), 1)}));
    var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Hora certa")) + el("span", {"class": "nota"}, t("{1} · UTC{2}", {1: h(d.fuso.nome), 2: h(d.fuso.deslocamento)})));
    // o relógio escolhido na Configuração: analógico, digital, anadigi (o visor dentro do mostrador) ou os dois lado a lado
    var modo = d.relogio || "ambos";
    var origem = {"usuario": t("o seu, de Minha conta"), "navegador": t("o do seu navegador, por Minha conta"), "colecao": t("o da coleção, da Configuração"), "sistema": t("o do sistema, do config.php")}[d.fuso.origem] || "";
    res += el("section", {"class": "cartao-config relogio-certo modo-" + modo}, (modo === "analogico" || modo === "ambos" ? mostrador(false) : "") + (modo === "anadigi" ? mostrador(true) : "")
      + (modo === "digital" || modo === "ambos" ? digitalLcd(d) : "")
      + el("div", {"class": "digital-bloco"}, (modo === "analogico" || modo === "anadigi" ? el("p", {"class": "hora-texto", "id": "digital", "aria-live": "off"}, h(d.local.hora)) : "")
        + el("p", {"class": "data-longa"}, h(d.local.texto))
        + el("p", {"class": "nota", "id": "digital-utc"}, t("{1} UTC", {1: h(d.utc.hora)}))
        + el("p", {"class": "nota"}, t("{1} A hora vem do servidor do sistema, descontando o tempo do pedido.", {1: aparelho}) + " " + t("Fuso horário: {1} ({2}).", {1: h(d.fuso.nome), 2: origem}))));
    res += el("div", {"class": "config-grade grade-hora"},
      el("section", {"class": "cartao-config"}, el("h2", {}, t("Fuso e UTC")) + pares([
        [t("Fuso"), h(d.fuso.nome) + " (" + h(d.fuso.abreviacao) + ")"],
        [t("Deslocamento"), t("UTC{1}{2}", {1: h(d.fuso.deslocamento), 2: d.fuso.horario_de_verao ? t(" · horário de verão") : ""})],
        [t("UTC (Zulu)"), h(d.utc.data.split("-").reverse().join("/")) + " " + h(d.utc.hora)],
        [t("Letra militar"), d.militar.letra !== null ? el("strong", {}, h(d.militar.letra)) + " (" + h(d.militar.nome) + ")" : t("— (o fuso não é de hora cheia)")],
        [t("Grupo data-hora"), el("code", {}, h(d.militar.grupo_data_hora_local || "—")) + " · " + el("code", {}, h(d.militar.grupo_data_hora_zulu))],
        [t("ISO 8601"), el("code", {"class": "quebra"}, h(d.local.iso8601))]]))
      + el("section", {"class": "cartao-config"}, el("h2", {}, t("Calendário")) + pares([
        [t("Hoje"), h(d.local.texto)],
        [t("Dia do ano"), t("{1} de {2} · faltam {3}", {1: d.calendario.dia_do_ano, 2: d.calendario.dias_no_ano, 3: d.calendario.faltam_no_ano})],
        [t("Semana (ISO)"), t("{1} de {2}", {1: d.calendario.semana_iso, 2: d.calendario.ano_iso})],
        [t("Trimestre"), d.calendario.trimestre + "º" + (d.calendario.bissexto ? t(" · ano bissexto") : "")],
        [t("Dia juliano"), num(d.calendario.dia_juliano, 5)],
        [t("Unix"), el("code", {}, String(d.calendario.unix))]]))
      + el("section", {"class": "cartao-config"}, el("h2", {}, t("Lua")) + pares([
        [t("Fase"), el("span", {"class": "lua-simbolo", "aria-hidden": "true"}, d.lua.simbolo) + " " + h(d.lua.nome)],
        [t("Idade"), t("{1} dias desde a lua nova", {1: num(d.lua.idade_dias, 1)})],
        [t("Iluminada"), num(d.lua.iluminacao, 1) + "%"],
        [t("Próxima cheia"), dataBr(d.lua.proxima_cheia)],
        [t("Próxima nova"), dataBr(d.lua.proxima_nova)]]) + el("p", {"class": "nota"}, t("Pelo ciclo médio da lua (29,53 dias): pode errar por um dia, e serve para o mostrador de fases."))));
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Como acertar")) + el("p", {}, h(d.como_acertar)));
    document.getElementById("hora").innerHTML = res;
    andar();
  }).catch(function (e) {
    document.getElementById("hora").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler a hora certa: {1}", {1: h(e.message)}));
  });
}

// os pares rótulo/valor (como no painel do relógio)
function pares(lista) {
  return el("dl", {"class": "pares"}, lista.map(function (p) { return el("dt", {}, p[0]) + el("dd", {}, p[1]); }).join(""));
}

ler();
// o relógio anda a cada virada de segundo (acertado pelo relógio deste aparelho mais a diferença), e lê a API de novo a cada minuto
(function tique() {
  andar();
  setTimeout(tique, 1000 - ((Date.now() + diferenca) % 1000) + 5);
})();
setInterval(ler, 60000);
