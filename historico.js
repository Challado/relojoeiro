// Histórico de um relógio: a linha do tempo da API (recurso=historico), no HTML da página do sistema antigo. Os filtros
// ficam no endereço (historico.php?id=3&de=...&ate=...&estado=...&por=...&pagina=...), como antes.
var q = new URLSearchParams(window.location.search);
var id = parseInt(q.get("id") || "0", 10);
var de = /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(q.get("de") || "") ? q.get("de") : "";
var ate = /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(q.get("ate") || "") ? q.get("ate") : "";
var ESTADOS = {"": t("Todos"), rodizio: t("Em uso"), pulso: t("Fora do rodízio"), repouso: t("Em repouso"), marca: t("Marcações")};
var filtroEstado = q.get("estado") || "";
var por = ["25", "50", "100", "todos"].indexOf(q.get("por")) >= 0 ? q.get("por") : "50";
var pagina = Math.max(1, parseInt(q.get("pagina") || "1", 10));

// um dia, N dias antes de hoje, no fuso do navegador
function diaAntes(n) {
  var d = new Date(Date.now() - n * 86400000);
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, "0"), String(d.getDate()).padStart(2, "0")].join("-");
}

// endereço desta página com os filtros atuais, trocando o que for preciso
function link(troca) {
  var p = {id: id, de: de, ate: ate, estado: filtroEstado, por: por};
  Object.keys(troca).forEach(function (k) {
    p[k] = troca[k];
  });
  return "historico.php?" + new URLSearchParams(p).toString();
}

var pedido = {recurso: "historico", relogio: id, limite: por === "todos" ? 1000 : por, pagina: por === "todos" ? 1 : pagina};
if (de !== "") {
  pedido.de = de;
}
if (ate !== "") {
  pedido.ate = ate;
}
if (filtroEstado !== "") {
  pedido.estado = filtroEstado;
}
// lê o histórico e monta a página; a cada minuto, sozinha (api.js): o trecho em andamento vai até agora
function carregarHistorico() {
  return api(pedido).then(function (d) {
    var main = document.getElementById("historico");
    var r = d.resumo[String(id)];
    if (!r) {
      main.innerHTML = el("p", {}, t("Relógio não encontrado."));
    } else {
      // os estados que existem (os tipos de sessão cadastrados entram entre o fora do plano e o repouso)
      Object.keys(d.estados).forEach(function (k) {
        if (ESTADOS[k] === undefined) {
          ESTADOS[k] = d.estados[k].charAt(0).toUpperCase() + d.estados[k].slice(1);
        }
      });
      var ordem = ["", "rodizio", "pulso"].concat(Object.keys(d.estados).filter(function (k) { return ["rodizio", "pulso", "repouso", "marca"].indexOf(k) < 0; }), ["repouso", "marca"]);
      var soma = 0;
      Object.keys(r.tempo).forEach(function (k) {
        soma += r.tempo[k].segundos;
      });
      var res = el("div", {"class": "cabeca-historico"}, (r.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + id + "&v=" + r.foto, "alt": t("Foto do {1}", {1: r.relogio})}) : "")
        + el("div", {}, el("p", {"class": "data"}, t("Histórico · {1} · código {2}", {1: h(r.tipo), 2: id})) + el("h1", {}, h(r.relogio))
          + el("p", {"class": "nota"}, r.registros > 0 ? t("{1} registros desde {2} {3}", {1: r.registros, 2: dataBr(r.desde), 3: horaBr(r.desde)}) : t("Nada lançado ainda."))));
      res += el("form", {"method": "get", "action": "historico.php", "class": "filtros-execucoes cartao-config"}, el("input", {"type": "hidden", "name": "id", "value": id})
        + el("label", {}, t("De {1}", {1: el("input", {"type": "date", "name": "de", "value": de})}))
        + el("label", {}, t("Até {1}", {1: el("input", {"type": "date", "name": "ate", "value": ate})}))
        + el("label", {}, t("Estado {1}", {1: el("select", {"name": "estado"}, ordem.map(function (k) { return el("option", {"value": k, "selected": filtroEstado === k}, h(ESTADOS[k])); }).join(""))}))
        + el("label", {}, t("Por página {1}", {1: el("select", {"name": "por"}, [["25", "25"], ["50", "50"], ["100", "100"], ["todos", t("Todos")]].map(function (o) {
          return el("option", {"value": o[0], "selected": por === o[0]}, o[1]);
        }).join(""))}))
        + el("div", {"class": "botoes"}, el("button", {}, t("Filtrar")))
        + el("div", {"class": "atalhos"}, el("a", {"href": link({de: diaAntes(6), ate: diaAntes(0), pagina: 1})}, t("7 dias")) + " "
          + el("a", {"href": link({de: diaAntes(29), ate: diaAntes(0), pagina: 1})}, t("30 dias")) + " " + el("a", {"href": link({de: "", ate: "", pagina: 1})}, t("desde o começo"))));
      var marcas = Object.keys(r.marcacoes);
      if (soma > 0 || marcas.length > 0) {
        res += el("div", {"class": "resumo-execucoes resumo-historico"}, Object.keys(r.tempo).map(function (k) {
          return r.tempo[k].segundos > 0 ? el("div", {}, el("strong", {}, h(r.tempo[k].texto)) + el("span", {}, h(d.estados[k]) + " · " + Math.round(r.tempo[k].porcentagem) + "%")) : "";
        }).join("") + marcas.map(function (n) { return el("div", {}, el("strong", {}, String(r.marcacoes[n])) + el("span", {}, h(n))); }).join(""));
      }
      res += el("p", {"class": "nota"}, t("{1} {2} com esses filtros{3}. Os trechos são contínuos: em uso (pelo rodízio, ou fora do plano: no pulso sem ser o relógio do dia), em cada tipo de sessão (no winder, no sol...) e em repouso; as marcações (corda, carga, pilha, revisão) não têm fim nem duração.", {1: d.total, 2: d.total === 1 ? "registro" : "registros", 3: d.paginas > 1 ? t(", página {1} de {2}", {1: d.pagina, 2: d.paginas}) : ""}));
      res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios lista-historico"}, el("thead", {}, el("tr", {}, el("th", {}, t("Início")) + el("th", {}, t("Fim")) + el("th", {}, t("Duração")) + el("th", {}, t("Estado"))))
        + el("tbody", {}, d.linhas.map(function (l) {
          var classe = l.estado === "rodizio" || l.estado === "pulso" ? "uso" : (l.estado === "repouso" ? "repouso" : "carga");
          return el("tr", {"class": l.estado === "marca" ? "marca" : ""}, el("td", {}, dataBr(l.inicio) + " " + horaBr(l.inicio)) + el("td", {}, l.fim === null ? "—" : dataBr(l.fim) + " " + horaBr(l.fim))
            + el("td", {}, l.fim === null ? "—" : h(l.duracao))
            + el("td", {}, l.estado === "marca" ? h(l.texto) : el("span", {"class": "estado estado-" + (classe)}, h(l.texto)) + (l.em_andamento ? " " + el("small", {}, t("em andamento: o fim é o momento desta consulta")) : "")));
        }).join("") + (d.linhas.length === 0 ? el("tr", {}, el("td", {"colspan": "4"}, t("Nenhum registro com esses filtros."))) : ""))));
      if (d.paginas > 1) {
        var nav = "";
        if (d.pagina > 1) {
          nav += el("a", {"href": link({pagina: 1})}, t("« mais recentes")) + el("a", {"href": link({pagina: d.pagina - 1})}, t("‹ anterior"));
        }
        for (var n = Math.max(1, d.pagina - 3); n <= Math.min(d.paginas, d.pagina + 3); n++) {
          nav += n === d.pagina ? el("span", {"class": "atual"}, String(n)) : el("a", {"href": link({pagina: n})}, String(n));
        }
        if (d.pagina < d.paginas) {
          nav += el("a", {"href": link({pagina: d.pagina + 1})}, t("próxima ›")) + el("a", {"href": link({pagina: d.paginas})}, t("mais antigos »"));
        }
        nav += el("a", {"href": link({por: "todos", pagina: 1})}, t("ver tudo"));
        res += el("nav", {"class": "paginas", "aria-label": t("Páginas")}, nav);
      }
      main.innerHTML = res;
      document.title = t("Histórico — {1}", {1: r.relogio});
    }
  }).catch(function (e) {
    document.getElementById("historico").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler o histórico: {1}", {1: h(e.message)}));
  });
}
carregarHistorico();
atualizarSozinho(carregarHistorico);
