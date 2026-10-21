// Galeria: a coleção da API (recurso=galeria), em fotos, por grupo. Na tela, o Imprimir e o "também os de fora da coleção";
// no papel, só as fotos e os dados.
var todos = new URLSearchParams(window.location.search).get("posse") === "todos";

api(todos ? {recurso: "galeria", posse: "todos"} : {recurso: "galeria"}).then(function (d) {
  var res = el("div", {"class": "barra-impressao"}, el("button", {"type": "button", "onclick": "window.print()"}, t("Imprimir")) + " "
    + el("a", {"href": todos ? "galeria.php" : "galeria.php?posse=todos", "class": "botao leve"}, todos ? t("Só a coleção") : t("Também os de fora da coleção"))
    + " " + linkRelatorio("colecao", {posse: todos ? "todos" : ""}, t("Relatório do inventário")));
  res += el("div", {"class": "titulo-secao"}, el("h1", {}, t("Galeria")) + el("span", {"class": "nota"}, t("{1}{2}{3} · {4} com foto", {1: d.total, 2: d.total === 1 ? t(" relógio") : t(" relógios"), 3: d.valor_total > 0 ? t(" · R$ ") + reais(d.valor_total) : "", 4: d.com_foto})));
  if (d.total === 0) {
    res += el("p", {}, t("Nenhum relógio na coleção."));
  }
  res += d.grupos.map(function (g) {
    return el("section", {"class": "grupo-galeria"}, el("h2", {}, h(g.caminho)) + el("div", {"class": "galeria-relogios"}, g.relogios.map(function (x) {
      var linha2 = [x.marca, x.referencia].filter(function (t) { return t !== null; }).map(h).join(" · ");
      var linha3 = [x.ano_compra !== null ? String(x.ano_compra) : null, x.valor_compra !== null ? t("R$ ") + reais(x.valor_compra) : null,
        x.dias_de_uso !== null ? x.dias_de_uso + (x.dias_de_uso === 1 ? t(" dia de uso") : t(" dias de uso")) : null].filter(function (t) { return t !== null; }).join(" · ");
      return el("figure", {}, el("a", {"href": "ficha.php?id=" + x.id}, x.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + x.id + "&v=" + x.foto, "alt": t("Foto do {1}", {1: x.nome}), "loading": "lazy"})
          : el("span", {"class": "sem-foto"}, t("Sem foto")))
        + el("figcaption", {}, el("strong", {}, h(x.nome)) + (linha2 !== "" ? el("span", {}, linha2) : "") + (linha3 !== "" ? el("small", {}, linha3) : "")
          + (x.posse !== "colecao" ? el("small", {"class": "estado estado-posse"}, h(x.posse_texto.toLowerCase())) : "")));
    }).join("")));
  }).join("");
  res += el("p", {"class": "nota rodape-impresso"}, t("Gerado em {1} {2} pelo Relojoeiro.", {1: dataBr(d.gerado_em), 2: horaBr(d.gerado_em)}));
  document.getElementById("galeria").innerHTML = res;
}).catch(function (e) {
  document.getElementById("galeria").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler a galeria: {1}", {1: h(e.message)}));
});
