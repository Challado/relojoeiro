// Hora certa: a hora da API (recurso=hora_certa). O relógio da página acerta pela API (agora_ms, descontando metade do tempo
// do pedido) e anda sozinho, segundo a segundo; a cada minuto lê de novo (a data, a lua, o fuso). A diferença para o relógio
// deste aparelho aparece embaixo.
var dadosHora = null;
var diferenca = 0;   // a hora certa menos a deste aparelho, em ms

function dois(n) {
  return String(n).padStart(2, "0");
}

// o mostrador: 12 marcas, os ponteiros das horas, dos minutos e dos segundos (os ângulos saem da hora mostrada)
function mostrador() {
  var marcas = "";
  for (var i = 0; i < 60; i++) {
    var grande = i % 5 === 0;
    marcas += "<line x1=\"100\" y1=\"" + (grande ? 14 : 12) + "\" x2=\"100\" y2=\"" + (grande ? 28 : 18) + "\" class=\"" + (grande ? "marca-h" : "marca-m") + "\" transform=\"rotate(" + (i * 6) + " 100 100)\"></line>";
  }
  return "<svg class=\"mostrador\" viewBox=\"0 0 200 200\" role=\"img\" aria-label=\"Mostrador com a hora certa\"><circle cx=\"100\" cy=\"100\" r=\"94\" class=\"caixa\"></circle>" + marcas
    + "<line id=\"p-hora\" x1=\"100\" y1=\"112\" x2=\"100\" y2=\"52\" class=\"p-hora\"></line><line id=\"p-min\" x1=\"100\" y1=\"116\" x2=\"100\" y2=\"30\" class=\"p-min\"></line>"
    + "<line id=\"p-seg\" x1=\"100\" y1=\"122\" x2=\"100\" y2=\"24\" class=\"p-seg\"></line><circle cx=\"100\" cy=\"100\" r=\"4\" class=\"eixo\"></circle></svg>";
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
  var digital = document.getElementById("digital");
  if (digital) {
    digital.textContent = dois(c.h) + ":" + dois(c.m) + ":" + dois(c.s);
    document.getElementById("digital-utc").textContent = dois(c.utc.getUTCHours()) + ":" + dois(c.utc.getUTCMinutes()) + ":" + dois(c.utc.getUTCSeconds()) + " UTC";
    document.getElementById("p-hora").setAttribute("transform", "rotate(" + ((c.h % 12) * 30 + c.m * 0.5) + " 100 100)");
    document.getElementById("p-min").setAttribute("transform", "rotate(" + (c.m * 6 + c.s * 0.1) + " 100 100)");
    document.getElementById("p-seg").setAttribute("transform", "rotate(" + (c.s * 6) + " 100 100)");
  }
}

function ler() {
  var antes = Date.now();
  return api({recurso: "hora_certa"}).then(function (d) {
    var depois = Date.now();
    dadosHora = d;
    diferenca = d.agora_ms - Math.round((antes + depois) / 2);
    var seg = Math.round(-diferenca / 100) / 10;
    var aparelho = Math.abs(seg) < 0.5 ? "O relógio deste aparelho está certo (menos de meio segundo de diferença)."
      : "O relógio deste aparelho está " + num(Math.abs(seg), 1) + " s " + (seg > 0 ? "adiantado" : "atrasado") + ".";
    var res = el("div", {"class": "titulo-secao"}, el("h1", {}, "Hora certa") + el("span", {"class": "nota"}, h(d.fuso.nome) + " · UTC" + h(d.fuso.deslocamento)));
    res += el("section", {"class": "cartao-config relogio-certo"}, mostrador()
      + el("div", {"class": "digital-bloco"}, el("p", {"class": "digital", "id": "digital", "aria-live": "off"}, h(d.local.hora))
        + el("p", {"class": "data-longa"}, h(d.local.texto))
        + el("p", {"class": "nota", "id": "digital-utc"}, h(d.utc.hora) + " UTC")
        + el("p", {"class": "nota"}, aparelho + " A hora vem do servidor do sistema, descontando o tempo do pedido.")));
    res += el("div", {"class": "config-grade grade-hora"},
      el("section", {"class": "cartao-config"}, el("h2", {}, "Fuso e UTC") + pares([
        ["Fuso", h(d.fuso.nome) + " (" + h(d.fuso.abreviacao) + ")"],
        ["Deslocamento", "UTC" + h(d.fuso.deslocamento) + (d.fuso.horario_de_verao ? " · horário de verão" : "")],
        ["UTC (Zulu)", h(d.utc.data.split("-").reverse().join("/")) + " " + h(d.utc.hora)],
        ["Letra militar", d.militar.letra !== null ? el("strong", {}, h(d.militar.letra)) + " (" + h(d.militar.nome) + ")" : "— (o fuso não é de hora cheia)"],
        ["Grupo data-hora", el("code", {}, h(d.militar.grupo_data_hora_local || "—")) + " · " + el("code", {}, h(d.militar.grupo_data_hora_zulu))],
        ["ISO 8601", el("code", {"class": "quebra"}, h(d.local.iso8601))]]))
      + el("section", {"class": "cartao-config"}, el("h2", {}, "Calendário") + pares([
        ["Hoje", h(d.local.texto)],
        ["Dia do ano", d.calendario.dia_do_ano + " de " + d.calendario.dias_no_ano + " · faltam " + d.calendario.faltam_no_ano],
        ["Semana (ISO)", d.calendario.semana_iso + " de " + d.calendario.ano_iso],
        ["Trimestre", d.calendario.trimestre + "º" + (d.calendario.bissexto ? " · ano bissexto" : "")],
        ["Dia juliano", num(d.calendario.dia_juliano, 5)],
        ["Unix", el("code", {}, String(d.calendario.unix))]]))
      + el("section", {"class": "cartao-config"}, el("h2", {}, "Lua") + pares([
        ["Fase", el("span", {"class": "lua-simbolo", "aria-hidden": "true"}, d.lua.simbolo) + " " + h(d.lua.nome)],
        ["Idade", num(d.lua.idade_dias, 1) + " dias desde a lua nova"],
        ["Iluminada", num(d.lua.iluminacao, 1) + "%"],
        ["Próxima cheia", dataBr(d.lua.proxima_cheia)],
        ["Próxima nova", dataBr(d.lua.proxima_nova)]]) + el("p", {"class": "nota"}, "Pelo ciclo médio da lua (29,53 dias): pode errar por um dia, e serve para o mostrador de fases.")));
    res += el("section", {"class": "cartao-config"}, el("h2", {}, "Como acertar") + el("p", {}, h(d.como_acertar)));
    document.getElementById("hora").innerHTML = res;
    andar();
  }).catch(function (e) {
    document.getElementById("hora").innerHTML = el("p", {"class": "acao"}, "Não consegui ler a hora certa: " + h(e.message));
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
