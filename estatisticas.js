// Estatísticas: os dados da API (recurso=estatisticas), no período do endereço (estatisticas.php?de=AAAA-MM-DD&ate=...;
// de=tudo: desde o primeiro uso). Os gráficos são SVG desenhados com as alturas que a API manda (a barra: 0 a 100).
var q = new URLSearchParams(window.location.search);
var pedido = {recurso: "estatisticas"};
["de", "ate"].forEach(function (k) {
  if ((q.get(k) || "") !== "") {
    pedido[k] = q.get(k);
  }
});

// um dia, N dias antes de hoje, no fuso do navegador
function diaAntes(n) {
  var d = new Date(Date.now() - n * 86400000);
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, "0"), String(d.getDate()).padStart(2, "0")].join("-");
}

// horas com uma casa: "12,5 h"
function horas(v) {
  return num(v, 1) + " h";
}

// um gráfico de barras em SVG: itens [{rotulo, barra (0 a 100), valor (o texto em cima)}]; muitos itens: só alguns rótulos
function barrasSvg(itens, titulo) {
  var n = Math.max(1, itens.length);
  var largura = 600;
  var passo = largura / n;
  var cada = Math.max(1, Math.ceil(n / 7));
  var res = "<svg class=\"grafico-barras\" viewBox=\"0 0 " + largura + " 190\" role=\"img\" aria-label=\"" + h(titulo) + "\">"
    + "<line x1=\"0\" y1=\"150\" x2=\"" + largura + "\" y2=\"150\" class=\"eixo\"></line>";
  itens.forEach(function (x, i) {
    var alto = Math.round(x.barra * 1.2 * 10) / 10;
    var xx = Math.round((i * passo + passo * 0.15) * 10) / 10;
    var w = Math.round(passo * 0.7 * 10) / 10;
    res += "<rect x=\"" + xx + "\" y=\"" + (150 - alto) + "\" width=\"" + w + "\" height=\"" + alto + "\" rx=\"3\"><title>" + h(x.rotulo + ": " + x.valor) + "</title></rect>";
    if (n <= 8 && x.barra > 0) {
      res += "<text x=\"" + (xx + w / 2) + "\" y=\"" + (143 - alto) + "\" class=\"valor\">" + h(x.valor) + "</text>";
    }
    if (i % cada === 0) {
      res += "<text x=\"" + (xx + w / 2) + "\" y=\"178\" class=\"rotulo\">" + h(x.rotulo) + "</text>";
    }
  });
  return res + "</svg>";
}

// uma barra horizontal de porcentagem (a largura vem da API)
function barraH(p) {
  return el("span", {"class": "barra-h"}, el("span", {"style": "width: " + Math.max(0, Math.min(100, p)) + "%"}, ""));
}

var SETAS = {sobe: "↑", desce: "↓", estavel: "→", sem_dados: "·"};

api(pedido).then(function (d) {
  var r = d.resumo;
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, "Estatísticas") + el("span", {"class": "nota"}, "de " + dataBr(d.de) + " a " + dataBr(d.ate) + " · " + r.dias + (r.dias === 1 ? " dia" : " dias")));
  res += el("form", {"method": "get", "action": "estatisticas.php", "class": "filtros-execucoes cartao-config"},
    el("label", {}, "De " + el("input", {"type": "date", "name": "de", "value": d.de, "max": d.hoje}))
    + el("label", {}, "Até " + el("input", {"type": "date", "name": "ate", "value": d.ate, "max": d.hoje}))
    + el("div", {"class": "botoes"}, el("button", {}, "Ver"))
    + el("div", {"class": "atalhos"}, [[29, "30 dias"], [89, "90 dias"], [364, "1 ano"]].map(function (a) {
      return el("a", {"href": "estatisticas.php?de=" + diaAntes(a[0]) + "&ate=" + diaAntes(0)}, a[1]);
    }).join(" ") + " " + el("a", {"href": "estatisticas.php?de=tudo"}, "desde o começo")));
  // o resumo, em caixas
  var caixa = function (valor, texto) { return el("div", {}, el("strong", {}, valor) + el("span", {}, texto)); };
  res += el("div", {"class": "resumo-execucoes resumo-estatisticas"},
    caixa(String(r.dias_com_relogio), "dias com relógio no pulso (de " + r.dias + ")")
    + caixa(horas(r.horas), "no pulso · " + horas(r.horas_por_dia) + " por dia com relógio")
    + caixa(r.relogios_usados + " de " + r.relogios_na_colecao, "relógios usados" + (r.nao_usados > 0 ? " · " + r.nao_usados + " sem uso" : ""))
    + caixa(r.custo_por_uso_medio !== null ? "R$ " + reais(r.custo_por_uso_medio) : "—", "custo por uso, em média · coleção: R$ " + reais(r.valor_colecao)));
  if (r.mais_usado) {
    res += el("p", {"class": "nota"}, "Mais usado: " + el("a", {"href": "ficha.php?id=" + r.mais_usado.id}, h(r.mais_usado.nome)) + " (" + horas(r.mais_usado.horas) + ", " + r.mais_usado.usos + (r.mais_usado.usos === 1 ? " dia" : " dias") + ")"
      + (r.menos_usado ? ". Menos usado (dos que foram ao pulso): " + el("a", {"href": "ficha.php?id=" + r.menos_usado.id}, h(r.menos_usado.nome)) + " (" + horas(r.menos_usado.horas) + ")" : "") + ".");
  }
  // cada relógio
  res += el("section", {"class": "cartao-config"}, el("h2", {}, "Cada relógio")
    + el("p", {"class": "nota"}, "Do mais usado ao menos usado no período. Dias de uso: os dias em que ele foi ao pulso. A parte: das horas no pulso de todos. "
      + "Custo por uso: o valor pago dividido por todos os dias de uso (desde sempre). Tendência: as horas da segunda metade do período comparadas com as da primeira.")
    + el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-estatisticas"}, el("thead", {}, el("tr", {}, el("th", {}, "Relógio") + el("th", {}, "Dias de uso")
      + el("th", {}, "Horas") + el("th", {}, "A parte") + el("th", {}, "Custo por uso") + el("th", {}, "Sem uso há") + el("th", {}, "Tendência")))
      + el("tbody", {}, d.relogios.map(function (x) {
        return el("tr", {}, el("td", {}, el("a", {"href": "ficha.php?id=" + x.id}, h(x.nome)) + " " + el("small", {"class": "nota"}, h(x.tipo) + (x.posse !== "colecao" ? " · " + h(x.posse_texto.toLowerCase()) : "")))
          + el("td", {"class": "num"}, String(x.usos))
          + el("td", {"class": "num"}, horas(x.horas))
          + el("td", {}, el("span", {"class": "parte"}, barraH(x.barra) + " " + num(x.porcentagem, 1) + "%"))
          + el("td", {"class": "num"}, x.custo_por_uso !== null ? "R$ " + reais(x.custo_por_uso) : "—")
          + el("td", {"class": "num"}, x.dias_sem_uso === null ? "nunca usado" : (x.dias_sem_uso === 0 ? "usado hoje" : x.dias_sem_uso + (x.dias_sem_uso === 1 ? " dia" : " dias")))
          + el("td", {"class": "tendencia tendencia-" + x.tendencia, "title": "1ª metade: " + horas(x.horas_primeira_metade) + " · 2ª metade: " + horas(x.horas_segunda_metade)},
            SETAS[x.tendencia] + " " + h(x.tendencia_texto)));
      }).join("") + (d.relogios.length === 0 ? el("tr", {}, el("td", {"colspan": "7"}, "Nenhum relógio.")) : "")))));
  // os gráficos
  res += el("div", {"class": "graficos-estatisticas"},
    el("section", {"class": "cartao-config"}, el("h2", {}, "Por dia da semana")
      + barrasSvg(d.por_dia_semana.map(function (x) { return {rotulo: x.nome.substr(0, 3), barra: x.barra, valor: horas(x.horas)}; }), "Horas no pulso por dia da semana")
      + el("p", {"class": "nota"}, "As horas no pulso de todos os relógios em cada dia da semana (a média por dia: " + d.por_dia_semana.map(function (x) { return x.nome.substr(0, 3) + " " + num(x.media_horas, 1) + " h"; }).join(", ") + ")."))
    + el("section", {"class": "cartao-config"}, el("h2", {}, "Por mês")
      + barrasSvg(d.por_mes.map(function (x) { return {rotulo: x.nome_curto, barra: x.barra, valor: horas(x.horas)}; }), "Horas no pulso por mês")
      + el("p", {"class": "nota"}, d.por_mes.map(function (x) { return h(x.nome) + ": " + horas(x.horas) + ", " + x.relogios + (x.relogios === 1 ? " relógio" : " relógios"); }).join(" · "))));
  // a distribuição da coleção
  res += el("section", {"class": "cartao-config"}, el("h2", {}, "A coleção")
    + el("p", {"class": "nota"}, "Os relógios da coleção agora (na coleção e emprestados), divididos de cada jeito, com as horas no pulso de cada parte no período.")
    + el("div", {"class": "distribuicao"}, d.distribuicao.map(function (g) {
      return el("div", {"class": "parte-distribuicao"}, el("h3", {}, h(g.titulo)) + el("table", {"class": "relogios tabela-distribuicao"}, el("tbody", {}, g.itens.map(function (i) {
        return el("tr", {}, el("td", {}, h(i.nome)) + el("td", {"class": "num"}, i.relogios + (i.relogios === 1 ? " relógio" : " relógios"))
          + el("td", {}, el("span", {"class": "parte"}, barraH(i.porcentagem) + " " + num(i.porcentagem, 1) + "%")) + el("td", {"class": "num"}, horas(i.horas)));
      }).join(""))));
    }).join("")));
  document.getElementById("estatisticas").innerHTML = res;
}).catch(function (e) {
  document.getElementById("estatisticas").innerHTML = el("p", {"class": "acao"}, "Não consegui ler as estatísticas: " + h(e.message));
});
