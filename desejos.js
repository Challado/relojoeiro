// Desejos: a lista de desejos, da API (recurso=desejos), montada pelo abas.js (a mesma lista da aba dos desejados na página
// Hoje). "Comprei" põe o desejado na coleção (recurso=relogio, acao=salvar, posse=a situação que faz "na coleção": ele entra
// disponível para o rodízio) e abre a ficha dele para preencher a compra. Os relógios que saíram da coleção (emprestados,
// vendidos, arquivados) ficam nas abas da página Hoje.

// gravou: com o relógio na coleção, a ficha dele (para a compra, a foto, os campos); senão, a mensagem e a página de novo
aoGravar = function (form, res) {
  if (res.ok && form.hasAttribute("data-abre")) {
    window.location.href = "ficha.php?id=" + form.getAttribute("data-abre");
  } else {
    alert(textoResposta(res));
    window.location.reload();
  }
};

api({recurso: "desejos"}).then(function (d) {
  document.getElementById("desejos").innerHTML = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Lista de desejos"))
    + el("div", {"class": "botoes"}, el("a", {"class": "botao", "href": "index.php?novo=1&posse=desejado"}, t("Novo desejo")) + " " + linkRelatorio("desejos", {}, t("Relatório dos desejos"))
      + " " + el("a", {"class": "botao leve", "href": "index.php?aba=fora"}, t("Os que saíram da coleção"))))
    + desejosListaHtml(d);
}).catch(function (e) {
  document.getElementById("desejos").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler a lista de desejos: {1}", {1: h(e.message)}));
});
