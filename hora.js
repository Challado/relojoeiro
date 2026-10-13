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
  return "<svg class=\"mostrador\" viewBox=\"0 0 200 200\" role=\"img\" aria-label=\"" + h(t("Mostrador com a hora certa")) + "\"><circle cx=\"100\" cy=\"100\" r=\"94\" class=\"caixa\"></circle>" + marcas
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
    document.getElementById("digital-utc").textContent = t("{1}:{2}:{3} UTC", {1: dois(c.utc.getUTCHours()), 2: dois(c.utc.getUTCMinutes()), 3: dois(c.utc.getUTCSeconds())});
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
    var aparelho = Math.abs(seg) < 0.5 ? t("O relógio deste aparelho está certo (menos de meio segundo de diferença).")
      : (seg > 0 ? t("O relógio deste aparelho está {1} s adiantado.", {1: num(Math.abs(seg), 1)}) : t("O relógio deste aparelho está {1} s atrasado.", {1: num(Math.abs(seg), 1)}));
    var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Hora certa")) + el("span", {"class": "nota"}, t("{1} · UTC{2}", {1: h(d.fuso.nome), 2: h(d.fuso.deslocamento)})));
    res += el("section", {"class": "cartao-config relogio-certo"}, mostrador()
      + el("div", {"class": "digital-bloco"}, el("p", {"class": "digital", "id": "digital", "aria-live": "off"}, h(d.local.hora))
        + el("p", {"class": "data-longa"}, h(d.local.texto))
        + el("p", {"class": "nota", "id": "digital-utc"}, t("{1} UTC", {1: h(d.utc.hora)}))
        + el("p", {"class": "nota"}, t("{1} A hora vem do servidor do sistema, descontando o tempo do pedido.", {1: aparelho}))));
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
