// Galeria: a coleção da API (recurso=galeria), em fotos, por grupo. Na tela, o Imprimir e o "também os de fora da coleção";
// no papel, só as fotos e os dados.
var todos = new URLSearchParams(window.location.search).get("posse") === "todos";

api(todos ? {recurso: "galeria", posse: "todos"} : {recurso: "galeria"}).then(function (d) {
  var res = el("div", {"class": "barra-impressao"}, el("button", {"type": "button", "onclick": "window.print()"}, "Imprimir") + " "
    + el("a", {"href": todos ? "galeria.php" : "galeria.php?posse=todos", "class": "botao leve"}, todos ? "Só a coleção" : "Também os de fora da coleção"));
  res += el("div", {"class": "titulo-secao"}, el("h1", {}, "Galeria") + el("span", {"class": "nota"}, d.total + (d.total === 1 ? " relógio" : " relógios")
    + (d.valor_total > 0 ? " · R$ " + reais(d.valor_total) : "") + " · " + d.com_foto + " com foto"));
  if (d.total === 0) {
    res += el("p", {}, "Nenhum relógio na coleção.");
  }
  res += d.grupos.map(function (g) {
    return el("section", {"class": "grupo-galeria"}, el("h2", {}, h(g.caminho)) + el("div", {"class": "galeria-relogios"}, g.relogios.map(function (x) {
      var linha2 = [x.marca, x.referencia].filter(function (t) { return t !== null; }).map(h).join(" · ");
      var linha3 = [x.ano_compra !== null ? String(x.ano_compra) : null, x.valor_compra !== null ? "R$ " + reais(x.valor_compra) : null,
        x.dias_de_uso !== null ? x.dias_de_uso + (x.dias_de_uso === 1 ? " dia de uso" : " dias de uso") : null].filter(function (t) { return t !== null; }).join(" · ");
      return el("figure", {}, el("a", {"href": "ficha.php?id=" + x.id}, x.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + x.id + "&v=" + x.foto, "alt": "Foto do " + x.nome, "loading": "lazy"})
          : el("span", {"class": "sem-foto"}, "Sem foto"))
        + el("figcaption", {}, el("strong", {}, h(x.nome)) + (linha2 !== "" ? el("span", {}, linha2) : "") + (linha3 !== "" ? el("small", {}, linha3) : "")
          + (x.posse !== "colecao" ? el("small", {"class": "estado estado-posse"}, h(x.posse_texto.toLowerCase())) : "")));
    }).join("")));
  }).join("");
  res += el("p", {"class": "nota rodape-impresso"}, "Gerado em " + dataBr(d.gerado_em) + " " + horaBr(d.gerado_em) + " pelo Relojoeiro.");
  document.getElementById("galeria").innerHTML = res;
}).catch(function (e) {
  document.getElementById("galeria").innerHTML = el("p", {"class": "acao"}, "Não consegui ler a galeria: " + h(e.message));
});
