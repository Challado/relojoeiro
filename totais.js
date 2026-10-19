// Totais: os quadros da página de totais (recurso=totais, parte=pagina), nos relógios do endereço (totais.php?relogios=1,2,3:
// os que o filtro da página Hoje deixou; sem, todos). Cada total vira um quadro: a tabelinha com a linha de total (AGRUPADO,
// FAIXAS), o relógio com o valor (QUEM_MAIOR, QUEM_MENOR) ou o número; tudo vem pronto da API, a tela só desenha.
var q = new URLSearchParams(window.location.search);
var pedido = {recurso: "totais", parte: "pagina"};
if ((q.get("relogios") || "") !== "") {
  pedido.relogios = q.get("relogios");
}

// um quadro: o nome, a coluna (quando tem) e o resultado
function quadroTotal(x) {
  var r = x.resultado;
  var corpo = "";
  if (r.tipo === "tabela") {
    corpo = el("table", {"class": "quadro-tabela"}, el("tbody", {}, r.linhas.map(function (l) {
      return el("tr", {"class": l.sem ? "sem" : ""}, el("td", {}, h(l.rotulo)) + el("td", {"class": "num"}, h(l.texto)));
    }).join("")) + el("tfoot", {}, el("tr", {}, el("td", {}, t("Total")) + el("td", {"class": "num"}, h(r.total_texto)))));
  } else if (r.tipo === "relogio") {
    corpo = r.relogio_id === null ? el("p", {"class": "quadro-numero"}, "—")
      : el("p", {"class": "quadro-numero"}, h(r.valor_texto)) + el("p", {"class": "quadro-relogio"}, el("a", {"href": "index.php?r=" + r.relogio_id}, h(r.relogio)));
  } else if (r.tipo === "erro") {
    corpo = el("p", {"class": "acao"}, h(r.erro));
  } else {
    corpo = el("p", {"class": "quadro-numero"}, h(r.texto));
  }
  return el("section", {"class": "quadro-total" + (x.largura === "largo" ? " largo" : "")},
    el("h2", {}, h(x.nome)) + (x.coluna_nome ? el("p", {"class": "nota"}, t("coluna {1}", {1: h(x.coluna_nome)})) : "") + corpo);
}

// lê os totais e monta a página; a cada minuto, sozinha (api.js)
function carregarTotais() {
  return api(pedido).then(function (d) {
    var conta = d.relogios === d.todos ? t("Contando os {1} relógios da coleção.", {1: d.todos})
      : t("Contando {1} de {2} relógios (o filtro da página Hoje). {3}", {1: d.relogios, 2: d.todos, 3: el("a", {"href": "totais.php"}, t("Contar todos"))});
    porHtml(document.getElementById("totais"), el("h1", {}, t("Totais"))
      + el("p", {"class": "nota"}, conta + " " + el("a", {"href": "cadastros.php?aba=totais"}, t("Montar os totais")))
      + (d.totais.length === 0 ? el("p", {}, t("Nenhum total na página: crie os seus na aba Totais dos Cadastros, com \"Exibir na página de totais\" marcado."))
        : el("div", {"class": "grade-totais"}, d.totais.map(quadroTotal).join(""))));
  }).catch(function (e) {
    document.getElementById("totais").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os totais: {1}", {1: h(e.message)}));
  });
}
carregarTotais();
atualizarSozinho(carregarTotais);
