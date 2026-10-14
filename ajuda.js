// Página Ajuda: o texto do README para quem usa, que vem do api.php (recurso=manual) já em HTML, com o sumário no alto.
// Depois de montar, vai para a seção do endereço (ajuda.php#a-api...), como faria a página pronta.
api({recurso: "manual"}).then(function (d) {
  var main = document.getElementById("ajuda");
  main.innerHTML = el("h1", {}, t("Ajuda"))
    + el("nav", {"class": "ajuda-sumario", "aria-label": t("Nesta página")}, el("h2", {}, t("Nesta página")) + el("ul", {}, d.sumario.map(function (s) {
      return el("li", {"class": "nivel-" + s.nivel}, el("a", {"href": "#" + s.ancora}, h(s.titulo)));
    }).join("")))
    + d.html;
  var alvo = window.location.hash ? document.getElementById(decodeURIComponent(window.location.hash.slice(1))) : null;
  if (alvo) {
    alvo.scrollIntoView();
  }
}).catch(function (e) {
  document.getElementById("ajuda").innerHTML = el("h1", {}, t("Ajuda")) + el("p", {"class": "acao"}, t("Não consegui ler a ajuda: {1}.", {1: h(e.message)}));
});
