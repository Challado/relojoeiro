// As abas por situação (em cima da tabela dos relógios da página Hoje e da Galeria) e o que as abas que não são a tabela
// mostram: a lista de desejos (recurso=desejos) e os relógios que saíram da coleção (recurso=saidas). As abas vêm da API (uma
// por situação do cadastro, Cadastros › Listas; a da situação sem relógio vem desabilitada, com o motivo, ou não vem, conforme
// a opção abas_vazias da Configuração); a faixa rola de lado, com as setas, quando não cabe.
// A página Desejos (desejos.js) usa a mesma lista de desejos.

// a aba pedida no endereço (?aba=): pela chave (a situação) ou pelo que a situação faz (desejado: a primeira aba dos
// desejados); sem pedido, com um que não existe mais ou com uma aba desabilitada (sem relógio), a primeira
function abaEscolhida(abas, pedida) {
  var achada = null;
  abas.forEach(function (a) {
    if (achada === null && a.chave === pedida && a.disponivel) {
      achada = a;
    }
  });
  abas.forEach(function (a) {
    if (achada === null && a.comportamento === pedida && a.disponivel) {
      achada = a;
    }
  });
  return achada !== null ? achada : (abas.length > 0 ? abas[0] : null);
}

// a faixa: a seta de cada lado e as abas (cada uma com quantos relógios tem); endereco(chave) dá o link de cada aba. A aba
// desabilitada (a situação sem relógio) não tem link: fica apagada, com o motivo que a API manda
function abasFaixaHtml(abas, escolhida, endereco) {
  return el("button", {"type": "button", "class": "aba-seta leve", "data-aba-seta": "-1", "aria-label": t("Abas anteriores"), "title": t("Abas anteriores"), "hidden": true}, "‹")
    + el("nav", {"class": "abas abas-rolam", "aria-label": t("Situação na coleção")}, abas.map(function (a) {
      if (!a.disponivel) {
        return el("a", {"class": "desabilitada", "role": "link", "aria-disabled": "true", "title": a.motivo, "aria-label": a.nome + ": " + a.motivo},
          h(a.nome) + " " + el("span", {"class": "aba-conta"}, String(a.quantos)));
      }
      return el("a", {"href": endereco(a.chave), "class": a.chave === escolhida ? "ativa" : "", "data-aba": a.chave, "aria-current": a.chave === escolhida ? "true" : null},
        h(a.nome) + " " + el("span", {"class": "aba-conta"}, String(a.quantos)));
    }).join(""))
    + el("button", {"type": "button", "class": "aba-seta leve", "data-aba-seta": "1", "aria-label": t("Próximas abas"), "title": t("Próximas abas"), "hidden": true}, "›");
}

// as setas: aparecem quando as abas não cabem; cada uma rola a faixa para o lado dela e fica apagada no fim do caminho
function abasFaixaLigar(faixa) {
  var nav = faixa.querySelector(".abas-rolam");
  var setas = faixa.querySelectorAll("[data-aba-seta]");
  if (!nav || setas.length !== 2) {
    return;
  }
  var atualizar = function () {
    var sobra = nav.scrollWidth - nav.clientWidth;
    setas.forEach(function (s) {
      var lado = Number(s.getAttribute("data-aba-seta"));
      s.hidden = sobra <= 1;
      s.disabled = lado < 0 ? nav.scrollLeft <= 1 : nav.scrollLeft >= sobra - 1;
    });
  };
  setas.forEach(function (s) {
    s.addEventListener("click", function () {
      nav.scrollBy({left: Number(s.getAttribute("data-aba-seta")) * Math.max(120, nav.clientWidth * 0.6), behavior: "smooth"});
    });
  });
  nav.addEventListener("scroll", atualizar);
  window.addEventListener("resize", atualizar);
  // primeiro as setas (elas tomam lugar da faixa), depois a aba escolhida à vista, no meio (só a faixa rola: a página fica onde está)
  atualizar();
  var ativa = nav.querySelector("a.ativa");
  if (ativa) {
    nav.scrollLeft = Math.max(0, ativa.offsetLeft - nav.offsetLeft - (nav.clientWidth - ativa.offsetWidth) / 2);
  }
  atualizar();
}

// o link de um relógio: na página Hoje, abre no painel ao lado; nas outras, a ficha dele
function linkDoRelogio(x) {
  return document.querySelector(".painel") ? el("a", {"href": "index.php?r=" + x.id, "data-abrir": x.id}, h(x.nome)) : el("a", {"href": "ficha.php?id=" + x.id}, h(x.nome));
}

// a miniatura e o nome (com o link) e, embaixo, uma linha a mais
function nomeComFoto(x, embaixo) {
  return el("div", {"class": "nome-rel"}, (x.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + x.id + "&v=" + x.foto, "alt": ""}) : el("span", {"class": "sem-mini"}, ""))
    + el("span", {}, linkDoRelogio(x) + (embaixo ? el("small", {"class": "nota bloco"}, embaixo) : "")));
}

// o botão que põe o relógio na coleção (a situação que faz "na coleção", da API: Cadastros › Listas), pelo recurso=relogio
function botaoColecao(x, posseColecao, texto, pergunta) {
  return el("form", {"data-recurso": "relogio", "class": "inline", "data-abre": x.id, "onsubmit": "return confirm(" + JSON.stringify(pergunta) + ")"},
    el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
    + el("input", {"type": "hidden", "name": "posse", "value": posseColecao}) + el("button", {"class": "leve"}, texto));
}

// a lista de desejos (d: a resposta do recurso=desejos): a nota com a soma e a tabela, a prioridade mais alta primeiro
function desejosListaHtml(d) {
  var res = el("p", {"class": "nota"}, d.desejados.length === 0 ? t("Nenhum relógio na lista de desejos. Em \"Novo desejo\" (ou no cadastro de qualquer relógio, na \"Situação na coleção\"), um relógio desejado entra aqui: com a marca, a referência, o preço-alvo, a prioridade e o link. Ele não entra no rodízio nem nos avisos.")
    : t("{1}{2}, a prioridade mais alta primeiro. Somando os preços-alvo: R$ {3}{4}. Comprou? \"Comprei\" põe o relógio na coleção (disponível para o rodízio) e abre a ficha dele, para preencher a compra.", {1: d.desejados.length, 2: d.desejados.length === 1 ? t(" relógio desejado") : t(" relógios desejados"), 3: reais(d.total_preco_alvo), 4: d.sem_preco_alvo > 0 ? t(" ({1} sem preço-alvo)", {1: d.sem_preco_alvo}) : ""}));
  if (d.desejados.length > 0) {
    res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-desejos"}, el("thead", {}, el("tr", {}, el("th", {}, t("Relógio")) + el("th", {}, t("Prioridade"))
        + el("th", {}, t("Preço-alvo")) + el("th", {}, t("Grupo")) + el("th", {}, t("Link")) + el("th", {}, "")))
      + el("tbody", {}, d.desejados.map(function (x) {
        var modelo = [x.marca, x.referencia].filter(function (t) { return t !== null; }).join(" · ");
        return el("tr", {}, el("td", {}, nomeComFoto(x, h(modelo)))
          + el("td", {"data-rotulo": t("Prioridade")}, x.prioridade !== null ? h(x.prioridade) : "—")
          + el("td", {"class": "valor", "data-rotulo": t("Preço-alvo")}, x.preco_alvo !== null ? t("R$ ") + reais(x.preco_alvo) : "—")
          + el("td", {"data-rotulo": t("Grupo")}, x.no_id === null ? "—" : h(x.caminho))
          + el("td", {"data-rotulo": t("Link")}, x.link !== null && /^https?:\/\//i.test(x.link) ? el("a", {"href": x.link, "target": "_blank", "rel": "noopener noreferrer", "class": "quebra"}, t("abrir a página")) : (x.link !== null ? h(x.link) : "—"))
          + el("td", {"class": "acoes-desejo"}, botaoColecao(x, d.posse_colecao, t("Comprei"), t("Comprou o {1}? Ele entra na coleção, disponível para o rodízio, e a ficha dele abre para você preencher a compra.", {1: x.nome}))));
      }).join(""))));
  }
  return res;
}

// os relógios de uma situação de saída (d: a resposta do recurso=saidas com situacao= e abertas=1; aba: a aba): quando saiu,
// para quem, o que foi recebido, o que custou, o resultado e o tempo que ficou na coleção, com as somas embaixo
function saidasListaHtml(d, aba) {
  if (!d.disponivel) {
    return el("p", {"class": "acao"}, t("As saídas da coleção chegam com a migração v64: aplique em Configuração."));
  }
  var dinheiro = function (v) { return v !== null ? (t("R$ ") + reais(v)).replace(/ /g, "&nbsp;") : "—"; };
  var soma = d.somas.length > 0 ? d.somas[0] : null;
  var res = el("p", {"class": "nota"}, t("{1}: fora do rodízio, do plano e dos avisos; a ficha, os documentos e o histórico ficam. A data, o valor e para quem foi mudam no cadastro do relógio (\"Editar cadastro\", na Saída da coleção).", {1: h(aba.nome)}));
  if (d.saidas.length === 0) {
    return res + el("p", {"class": "nota"}, t("Nenhum relógio nesta situação."));
  }
  res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta lista-saidas"}, el("thead", {}, el("tr", {}, el("th", {}, t("Relógio")) + el("th", {}, t("Saiu em"))
      + el("th", {}, t("Para quem")) + el("th", {"class": "valor"}, t("Recebido")) + el("th", {"class": "valor"}, t("Valor pago")) + el("th", {"class": "valor"}, t("Serviços"))
      + el("th", {"class": "valor"}, t("Resultado")) + el("th", {}, t("Tempo na coleção")) + el("th", {}, "")))
    + el("tbody", {}, d.saidas.map(function (x) {
      var rel = {id: x.relogio_id, nome: x.relogio, foto: x.foto};
      return el("tr", {}, el("td", {}, nomeComFoto(rel, h(x.tipo)))
        + el("td", {"data-rotulo": t("Saiu em")}, x.data !== null ? dataBr(x.data) + el("small", {"class": "nota bloco"}, h(x.dias_texto)) : el("span", {"class": "nota"}, t("sem a data")))
        + el("td", {"data-rotulo": t("Para quem")}, (x.destino !== null ? h(x.destino) : "—") + (x.observacao !== null ? el("small", {"class": "nota bloco"}, h(x.observacao)) : ""))
        + el("td", {"class": "valor", "data-rotulo": t("Recebido")}, dinheiro(x.valor))
        + el("td", {"class": "valor", "data-rotulo": t("Valor pago")}, dinheiro(x.compra_valor))
        + el("td", {"class": "valor", "data-rotulo": t("Serviços")}, x.custo_servicos > 0 ? dinheiro(x.custo_servicos) : "—")
        + el("td", {"class": "valor" + (x.resultado !== null && x.resultado < 0 ? " negativo" : ""), "data-rotulo": t("Resultado")}, dinheiro(x.resultado))
        + el("td", {"data-rotulo": t("Tempo na coleção")}, x.tempo_na_colecao_texto !== null ? h(x.tempo_na_colecao_texto).replace(/ /g, "&nbsp;") : "—")
        + el("td", {"class": "acoes-desejo"}, botaoColecao(rel, d.posse_colecao, t("Voltar para a coleção"), t("Voltar o {1} para a coleção? Ele fica disponível para o rodízio de novo, e esta saída vai para o histórico dele.", {1: x.relogio}))));
    }).join(""))
    ));
  // as somas da aba (da API): o que foi recebido, o que os relógios custaram e o resultado (só dos que têm valor recebido)
  if (soma !== null && soma.com_valor > 0) {
    res += el("p", {"class": "saidas-total"}, t("Total: recebido {1} · valor pago {2} · serviços {3} · resultado {4}", {1: el("strong", {}, dinheiro(soma.valor)), 2: dinheiro(soma.compra_valor > 0 ? soma.compra_valor : null),
        3: dinheiro(soma.custo_servicos > 0 ? soma.custo_servicos : null), 4: el("strong", {"class": soma.resultado < 0 ? "negativo" : ""}, dinheiro(soma.resultado))}))
      + el("p", {"class": "nota"}, t("O resultado é o valor recebido menos o valor pago e os serviços, e soma só os relógios com valor recebido ({1} de {2}); o valor pago e os serviços somam todos.", {1: soma.com_valor, 2: soma.quantas}));
  }
  return res + el("p", {"class": "botoes"}, linkRelatorio("saidas", {}, t("Relatório das saídas")));
}
