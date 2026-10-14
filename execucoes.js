// Execuções do cron: os dados da API (recurso=cron), no HTML da página do sistema antigo. Os filtros ficam no endereço.
var q = new URLSearchParams(window.location.search);
function diaAntes(n) {
  var d = new Date(Date.now() - n * 86400000);
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, "0"), String(d.getDate()).padStart(2, "0")].join("-");
}
var de = /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(q.get("de") || "") ? q.get("de") : diaAntes(6);
var ate = /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(q.get("ate") || "") ? q.get("ate") : diaAntes(0);
var SITUACOES = {atividade: "Com atividade", erro: "Com erro", nada: "Sem atividade", todas: "Todas"};
var situacao = SITUACOES[q.get("situacao")] ? q.get("situacao") : "atividade";
var busca = (q.get("busca") || "").trim();
var por = [25, 50, 100, 200].indexOf(parseInt(q.get("por") || "50", 10)) >= 0 ? parseInt(q.get("por"), 10) : 50;
var pagina = Math.max(1, parseInt(q.get("pagina") || "1", 10));

// endereço desta página com os filtros atuais, trocando o que for preciso
function link(troca) {
  var p = {de: de, ate: ate, situacao: situacao, busca: busca, por: por};
  Object.keys(troca).forEach(function (k) {
    p[k] = troca[k];
  });
  return "execucoes.php?" + new URLSearchParams(p).toString();
}

// segundos com uma casa e vírgula: "1,5 s"
function segundos(ms) {
  return (Math.round(ms / 100) / 10).toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + " s";
}

api({recurso: "cron", de: de, ate: ate, situacao: situacao, busca: busca, limite: por, pagina: pagina}).then(function (d) {
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, "Execuções do cron") + el("span", {"class": "nota"}, "Última execução: "
    + (d.ultima !== "" ? dataBr(d.ultima) + " " + d.ultima.substr(11, 8) : "nunca")));
  res += el("form", {"method": "get", "action": "execucoes.php", "class": "filtros-execucoes cartao-config"},
    el("label", {}, "De " + el("input", {"type": "date", "name": "de", "value": de}))
    + el("label", {}, "Até " + el("input", {"type": "date", "name": "ate", "value": ate}))
    + el("label", {}, "Situação " + el("select", {"name": "situacao"}, Object.keys(SITUACOES).map(function (k) { return el("option", {"value": k, "selected": situacao === k}, SITUACOES[k]); }).join("")))
    + el("label", {}, "Texto no registro " + el("input", {"name": "busca", "value": busca, "placeholder": "agenda, FALHA, evento..."}))
    + el("label", {}, "Por página " + el("select", {"name": "por"}, [25, 50, 100, 200].map(function (n) { return el("option", {"selected": por === n}, String(n)); }).join("")))
    + el("div", {"class": "botoes"}, el("button", {}, "Filtrar"))
    + el("div", {"class": "atalhos"}, el("a", {"href": link({de: diaAntes(0), ate: diaAntes(0), pagina: 1})}, "hoje") + " "
      + el("a", {"href": link({de: diaAntes(6), ate: diaAntes(0), pagina: 1})}, "7 dias") + " "
      + el("a", {"href": link({de: diaAntes(29), ate: diaAntes(0), pagina: 1})}, "30 dias") + " "
      + el("a", {"href": link({situacao: "erro", de: diaAntes(364), ate: diaAntes(0), pagina: 1})}, "erros do último ano")));
  res += el("div", {"class": "resumo-execucoes"}, el("div", {}, el("strong", {}, String(d.resumo.total)) + el("span", {}, "execuções no período"))
    + el("div", {}, el("strong", {}, String(d.resumo.atividade)) + el("span", {}, "com atividade"))
    + el("div", {"class": d.resumo.erros > 0 ? "com-erro" : ""}, el("strong", {}, String(d.resumo.erros)) + el("span", {}, "com erro"))
    + el("div", {}, el("strong", {}, segundos(d.resumo.mais_lenta_ms)) + el("span", {}, "a mais lenta")));
  res += el("p", {"class": "nota"}, d.total + " " + (d.total === 1 ? "execução" : "execuções") + " com esses filtros" + (d.total > por ? ", página " + d.pagina + " de " + d.paginas : "")
    + ". Sem atividade, as execuções ficam guardadas 7 dias; com atividade ou erro, 1 ano.");
  res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios lista-execucoes"}, el("thead", {}, el("tr", {}, el("th", {}, "Início") + el("th", {}, "Duração") + el("th", {}, "Situação") + el("th", {}, "Registro")))
    + el("tbody", {}, d.execucoes.map(function (x) {
      return el("tr", {"class": x.teve_erro ? "linha-erro" : ""}, el("td", {"class": "quando"}, dataBr(x.inicio) + "<br>" + el("strong", {}, x.inicio.substr(11, 8)))
        + el("td", {"class": "duracao"}, x.duracao_ms < 1000 ? x.duracao_ms + " ms" : segundos(x.duracao_ms))
        + el("td", {}, x.teve_erro ? el("span", {"class": "estado estado-repouso"}, "erro") : (x.teve_atividade ? el("span", {"class": "estado estado-atividade"}, "atividade")
          : el("span", {"class": "estado estado-indisponivel"}, "sem atividade")))
        + el("td", {}, x.registro !== "" ? el("pre", {}, h(x.registro)) : el("span", {"class": "nota"}, "nada a fazer neste minuto")));
    }).join("") + (d.execucoes.length === 0 ? el("tr", {}, el("td", {"colspan": "4"}, "Nenhuma execução com esses filtros.")) : ""))));
  if (d.paginas > 1) {
    var nav = "";
    if (d.pagina > 1) {
      nav += el("a", {"href": link({pagina: 1})}, "« primeira") + el("a", {"href": link({pagina: d.pagina - 1})}, "‹ anterior");
    }
    for (var n = Math.max(1, d.pagina - 3); n <= Math.min(d.paginas, d.pagina + 3); n++) {
      nav += n === d.pagina ? el("span", {"class": "atual"}, String(n)) : el("a", {"href": link({pagina: n})}, String(n));
    }
    if (d.pagina < d.paginas) {
      nav += el("a", {"href": link({pagina: d.pagina + 1})}, "próxima ›") + el("a", {"href": link({pagina: d.paginas})}, "última »");
    }
    res += el("nav", {"class": "paginas", "aria-label": "Páginas"}, nav);
  }
  document.getElementById("execucoes").innerHTML = res;
}).catch(function (e) {
  document.getElementById("execucoes").innerHTML = el("p", {"class": "acao"}, "Não consegui ler as execuções: " + h(e.message));
});
