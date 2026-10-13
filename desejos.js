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
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, "Lista de desejos")
    + el("div", {"class": "botoes"}, el("a", {"class": "botao", "href": "index.php?novo=1&posse=desejado"}, "Novo desejo")));
  res += el("p", {"class": "nota"}, d.desejados.length === 0 ? "Nenhum relógio na lista de desejos. Em \"Novo desejo\" (ou no cadastro de qualquer relógio, "
      + "na \"Situação na coleção\"), um relógio desejado entra aqui: com a marca, a referência, o preço-alvo, a prioridade e o link. Ele não entra no rodízio nem nos avisos."
    : d.desejados.length + (d.desejados.length === 1 ? " relógio desejado" : " relógios desejados") + ", a prioridade mais alta primeiro. Somando os preços-alvo: R$ " + reais(d.total_preco_alvo)
      + (d.sem_preco_alvo > 0 ? " (" + d.sem_preco_alvo + " sem preço-alvo)" : "") + ". Comprou? \"Comprei\" põe o relógio na coleção (disponível para o rodízio) e abre a ficha dele, para preencher a compra.");
  if (d.desejados.length > 0) {
    res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-desejos"}, el("thead", {}, el("tr", {}, el("th", {}, "Relógio") + el("th", {}, "Prioridade")
        + el("th", {}, "Preço-alvo") + el("th", {}, "Grupo") + el("th", {}, "Link") + el("th", {}, "")))
      + el("tbody", {}, d.desejados.map(function (x) {
        var modelo = [x.marca, x.referencia].filter(function (t) { return t !== null; }).join(" · ");
        return el("tr", {}, el("td", {}, nomeComFoto(x, h(modelo)))
          + el("td", {}, x.prioridade !== null ? h(x.prioridade) : "—")
          + el("td", {"class": "valor"}, x.preco_alvo !== null ? "R$ " + reais(x.preco_alvo) : "—")
          + el("td", {}, x.tipo === "sem grupo" ? "—" : h(x.caminho))
          + el("td", {}, x.link !== null && /^https?:\/\//i.test(x.link) ? el("a", {"href": x.link, "target": "_blank", "rel": "noopener noreferrer", "class": "quebra"}, "abrir a página") : (x.link !== null ? h(x.link) : "—"))
          + el("td", {"class": "acoes-desejo"}, botaoColecao(x, "Comprei", "Comprou o " + x.nome + "? Ele entra na coleção, disponível para o rodízio, e a ficha dele abre para você preencher a compra.")));
      }).join(""))));
  }
  res += el("h2", {}, "Fora da coleção");
  res += el("p", {"class": "nota"}, d.fora.length === 0 ? "Nenhum relógio emprestado, vendido ou arquivado. No cadastro de um relógio, a \"Situação na coleção\" muda isso: fora dela, ele sai do rodízio, do plano e dos avisos; a ficha, os documentos e o histórico ficam."
    : "Emprestados, vendidos e arquivados: fora do rodízio, do plano e dos avisos (o emprestado continua na tabela da página Hoje e no valor da coleção). A ficha, os documentos e o histórico ficam.");
  if (d.fora.length > 0) {
    res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-fora"}, el("thead", {}, el("tr", {}, el("th", {}, "Relógio") + el("th", {}, "Situação")
        + el("th", {}, "Grupo") + el("th", {}, "")))
      + el("tbody", {}, d.fora.map(function (x) {
        return el("tr", {}, el("td", {}, nomeComFoto(x, ""))
          + el("td", {}, el("span", {"class": "estado estado-posse"}, h(x.posse_texto.toLowerCase())))
          + el("td", {}, x.tipo === "sem grupo" ? "—" : h(x.caminho))
          + el("td", {"class": "acoes-desejo"}, botaoColecao(x, "Voltar para a coleção", "Voltar o " + x.nome + " para a coleção? Ele fica disponível para o rodízio de novo.")));
      }).join(""))));
  }
  document.getElementById("desejos").innerHTML = res;
}).catch(function (e) {
  document.getElementById("desejos").innerHTML = el("p", {"class": "acao"}, "Não consegui ler a lista de desejos: " + h(e.message));
});
