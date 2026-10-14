// Desejos: a lista de desejos e os relógios fora da coleção, da API (recurso=desejos). "Comprei" põe o desejado na coleção
// (recurso=relogio, acao=salvar, posse=colecao: ele entra disponível para o rodízio) e abre a ficha dele para preencher a
// compra; "Voltar para a coleção" faz o mesmo com um emprestado, vendido ou arquivado.

// a miniatura e o nome (com o link da ficha)
function nomeComFoto(x, embaixo) {
  return el("div", {"class": "nome-rel"}, (x.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + x.id + "&v=" + x.foto, "alt": ""}) : el("span", {"class": "sem-mini"}, ""))
    + el("span", {}, el("a", {"href": "ficha.php?id=" + x.id}, h(x.nome)) + (embaixo ? el("small", {"class": "nota bloco"}, embaixo) : "")));
}

// o botão que põe o relógio na coleção
function botaoColecao(x, texto, pergunta) {
  return el("form", {"data-recurso": "relogio", "class": "inline", "data-abre": x.id, "onsubmit": "return confirm(" + JSON.stringify(pergunta) + ")"},
    el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
    + el("input", {"type": "hidden", "name": "posse", "value": "colecao"}) + el("button", {"class": "leve"}, texto));
}

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
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Lista de desejos"))
    + el("div", {"class": "botoes"}, el("a", {"class": "botao", "href": "index.php?novo=1&posse=desejado"}, t("Novo desejo"))));
  res += el("p", {"class": "nota"}, d.desejados.length === 0 ? t("Nenhum relógio na lista de desejos. Em \"Novo desejo\" (ou no cadastro de qualquer relógio, na \"Situação na coleção\"), um relógio desejado entra aqui: com a marca, a referência, o preço-alvo, a prioridade e o link. Ele não entra no rodízio nem nos avisos.")
    : t("{1}{2}, a prioridade mais alta primeiro. Somando os preços-alvo: R$ {3}{4}. Comprou? \"Comprei\" põe o relógio na coleção (disponível para o rodízio) e abre a ficha dele, para preencher a compra.", {1: d.desejados.length, 2: d.desejados.length === 1 ? t(" relógio desejado") : t(" relógios desejados"), 3: reais(d.total_preco_alvo), 4: d.sem_preco_alvo > 0 ? t(" ({1} sem preço-alvo)", {1: d.sem_preco_alvo}) : ""}));
  if (d.desejados.length > 0) {
    res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-desejos"}, el("thead", {}, el("tr", {}, el("th", {}, t("Relógio")) + el("th", {}, t("Prioridade"))
        + el("th", {}, t("Preço-alvo")) + el("th", {}, t("Grupo")) + el("th", {}, t("Link")) + el("th", {}, "")))
      + el("tbody", {}, d.desejados.map(function (x) {
        var modelo = [x.marca, x.referencia].filter(function (t) { return t !== null; }).join(" · ");
        return el("tr", {}, el("td", {}, nomeComFoto(x, h(modelo)))
          + el("td", {}, x.prioridade !== null ? h(x.prioridade) : "—")
          + el("td", {"class": "valor"}, x.preco_alvo !== null ? t("R$ ") + reais(x.preco_alvo) : "—")
          + el("td", {}, x.tipo === "sem grupo" ? "—" : h(x.caminho))
          + el("td", {}, x.link !== null && /^https?:\/\//i.test(x.link) ? el("a", {"href": x.link, "target": "_blank", "rel": "noopener noreferrer", "class": "quebra"}, t("abrir a página")) : (x.link !== null ? h(x.link) : "—"))
          + el("td", {"class": "acoes-desejo"}, botaoColecao(x, t("Comprei"), t("Comprou o {1}? Ele entra na coleção, disponível para o rodízio, e a ficha dele abre para você preencher a compra.", {1: x.nome}))));
      }).join(""))));
  }
  res += el("h2", {}, t("Fora da coleção"));
  res += el("p", {"class": "nota"}, d.fora.length === 0 ? t("Nenhum relógio emprestado, vendido ou arquivado. No cadastro de um relógio, a \"Situação na coleção\" muda isso: fora dela, ele sai do rodízio, do plano e dos avisos; a ficha, os documentos e o histórico ficam.")
    : t("Emprestados, vendidos e arquivados: fora do rodízio, do plano e dos avisos (o emprestado continua na tabela da página Hoje e no valor da coleção). A ficha, os documentos e o histórico ficam."));
  if (d.fora.length > 0) {
    res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-fora"}, el("thead", {}, el("tr", {}, el("th", {}, t("Relógio")) + el("th", {}, t("Situação"))
        + el("th", {}, t("Grupo")) + el("th", {}, "")))
      + el("tbody", {}, d.fora.map(function (x) {
        return el("tr", {}, el("td", {}, nomeComFoto(x, ""))
          + el("td", {}, el("span", {"class": "estado estado-posse"}, h(x.posse_texto.toLowerCase())))
          + el("td", {}, x.tipo === "sem grupo" ? "—" : h(x.caminho))
          + el("td", {"class": "acoes-desejo"}, botaoColecao(x, t("Voltar para a coleção"), t("Voltar o {1} para a coleção? Ele fica disponível para o rodízio de novo.", {1: x.nome}))));
      }).join(""))));
  }
  document.getElementById("desejos").innerHTML = res;
}).catch(function (e) {
  document.getElementById("desejos").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler a lista de desejos: {1}", {1: h(e.message)}));
});
