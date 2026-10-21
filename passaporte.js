// Passaporte do relógio: os dados da API (recurso=passaporte), numa página feita para imprimir: na tela, o botão Imprimir; no
// papel, só a ficha (o menu e os botões somem).
var id = parseInt(new URLSearchParams(window.location.search).get("id") || "0", 10);

function linhas(lista) {
  return el("dl", {"class": "pares"}, lista.filter(function (p) { return p[1] !== null && p[1] !== ""; }).map(function (p) { return el("dt", {}, p[0]) + el("dd", {}, p[1]); }).join(""));
}

api({recurso: "passaporte", relogio: id}).then(function (d) {
  var r = d.relogio;
  var c = d.compra;
  var u = d.uso;
  var res = el("div", {"class": "barra-impressao"}, el("button", {"type": "button", "onclick": "window.print()"}, t("Imprimir")) + " "
    + el("a", {"href": "ficha.php?id=" + r.id, "class": "botao leve"}, t("Voltar para a ficha")) + " " + el("span", {"class": "nota"}, t("No papel saem só os dados (o \"Salvar como PDF\" do navegador guarda o arquivo).")));
  res += el("header", {"class": "cabeca-impressa"}, (r.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + r.id + "&v=" + r.foto, "alt": t("Foto do {1}", {1: r.nome})}) : "")
    + el("div", {}, el("p", {"class": "data"}, t("Passaporte do relógio · código {1}", {1: r.id})) + el("h1", {}, h(r.nome))
      + el("p", {}, [r.marca, r.referencia].filter(function (x) { return x !== null; }).map(h).join(" · ") + (r.marca || r.referencia ? " · " : "") + h(r.caminho))
      + el("p", {"class": "nota"}, t("{1}{2} · no sistema desde {3}", {1: h(r.posse_texto), 2: r.posse === "colecao" ? (r.disponivel ? t(" · no rodízio") : t(" · fora do rodízio")) : "", 3: dataBr(r.criado)}))
      + (r.observacao !== null ? el("p", {}, h(r.observacao)) : "")));
  res += el("div", {"class": "grade-impressa"},
    el("section", {}, el("h2", {}, t("Compra")) + (c.data === null && c.valor === null && c.loja === null && c.garantia_ate === null ? el("p", {"class": "nota"}, t("Sem dados da compra."))
      : linhas([[t("Data"), c.data !== null ? dataBr(c.data) : null], [t("Valor pago"), c.valor !== null ? t("R$ ") + reais(c.valor) : null], [t("Loja"), c.loja !== null ? h(c.loja) : null],
        [t("Garantia até"), c.garantia_ate !== null ? dataBr(c.garantia_ate) : null]])))
    + el("section", {}, el("h2", {}, t("Uso")) + (u.primeira_vez === null ? el("p", {"class": "nota"}, t("Ainda não foi ao pulso."))
      : linhas([[t("Primeira vez no pulso"), dataBr(u.primeira_vez)], [t("Última vez"), u.no_pulso_agora ? t("no pulso agora") : dataBr(u.ultima_vez)],
        [t("Horas no pulso"), num(u.horas_no_pulso, 1) + t(" h")], [t("Dias de uso"), u.dias_de_uso !== null ? String(u.dias_de_uso) : null],
        [t("Custo por uso"), u.custo_por_uso !== null ? t("R$ ") + reais(u.custo_por_uso) : null]]))));
  res += el("section", {}, el("h2", {}, t("Cadastro")) + (d.cadastro.length === 0 ? el("p", {"class": "nota"}, t("Nada preenchido."))
    : linhas(d.cadastro.map(function (x) { return [h(x.nome), h(x.valor)]; }))));
  res += el("section", {}, el("h2", {}, t("Manutenções")) + (d.marcacoes.length === 0 ? el("p", {"class": "nota"}, t("Nenhuma marcação."))
    : linhas(d.marcacoes.map(function (m) { return [h(m.nome), t("{1}{2} · a última em {3}", {1: m.vezes, 2: m.vezes === 1 ? t(" vez") : t(" vezes"), 3: dataBr(m.ultima)})]; })))
    + (d.servicos.length > 0 ? el("p", {"class": "nota"}, t("Revisões e trocas de pilha: {1}.", {1: d.servicos.map(function (s) { return dataBr(s.data) + " (" + h(s.nome.toLowerCase()) + ")"; }).join(", ")})) : ""));
  // os serviços e diagnósticos: a data e o tipo; quem fez, o custo, a estanqueidade e a marcha antes e depois
  if (d.servicos_diagnosticos.length > 0) {
    res += el("section", {}, el("h2", {}, t("Serviços e diagnósticos")) + linhas(d.servicos_diagnosticos.map(function (s) {
      return [dataBr(s.data) + " · " + h(s.tipo_texto), ([s.responsavel !== null ? h(s.responsavel) : null, s.custo !== null ? t("R$ ") + reais(s.custo) : null,
        s.estanqueidade !== null ? t("estanqueidade {1}{2}", {1: h(s.estanqueidade_texto), 2: s.estanqueidade_bar !== null ? t(" a {1} bar", {1: num(s.estanqueidade_bar, 1)}) : ""}) : null,
        s.marcha_antes !== null || s.marcha_depois !== null ? t("marcha {1} → {2}", {1: s.marcha_antes_texto !== null ? h(s.marcha_antes_texto) : "—", 2: s.marcha_depois_texto !== null ? h(s.marcha_depois_texto) : "—"}) : null]
        .filter(function (x) { return x !== null; }).join(" · ") || "—") + (s.descricao !== null ? el("small", {}, h(s.descricao)) : "")
        + (s.pecas.length > 0 ? el("small", {}, t("Peças: {1}", {1: s.pecas.map(function (p) { return h(p.nome); }).join(", ")})) : "")];
    })));
  }
  // declarado, medido e estimado: cada informação técnica (a marcha, a reserva, a estanqueidade) numa linha, com a confiança
  if (d.tecnico.length > 0) {
    var ou = function (x) { return x.texto !== null ? h(x.texto) : "—"; };
    res += el("section", {}, el("h2", {}, t("Declarado, medido e estimado")) + linhas(d.tecnico.map(function (x) {
      return [h(x.nome), t("declarado: {1} · último teste: {2} · estimativa: {3}", {1: ou(x.declarado), 2: ou(x.medido), 3: ou(x.estimado)})
        + (x.confianca ? el("small", {}, h(x.confianca.resumo)) : "")];
    })));
  }
  res += el("div", {"class": "grade-impressa"},
    el("section", {}, el("h2", {}, t("Acessórios")) + (d.acessorios.length === 0 ? el("p", {"class": "nota"}, t("Nenhum."))
      : linhas(d.acessorios.map(function (a) { return [h(a.tipo_texto), h(a.nome) + (a.observacao !== null ? el("small", {}, h(a.observacao)) : "")]; }))))
    + el("section", {}, el("h2", {}, t("Documentos")) + (d.documentos.length === 0 ? el("p", {"class": "nota"}, t("Nenhum."))
      : linhas(d.documentos.map(function (x) { return [h(x.categoria), h(x.titulo) + (x.data !== null ? " · " + dataBr(x.data) : "")]; })))));
  if (d.proximos_avisos.length > 0) {
    res += el("section", {}, el("h2", {}, t("Próximos avisos")) + linhas(d.proximos_avisos.map(function (a) { return [h(a.nome), h(a.texto)]; })));
  }
  res += el("p", {"class": "nota rodape-impresso"}, t("Gerado em {1} {2} pelo Relojoeiro.", {1: dataBr(d.gerado_em), 2: horaBr(d.gerado_em)}));
  document.getElementById("passaporte").innerHTML = res;
  document.title = t("Passaporte — {1}", {1: r.nome});
}).catch(function (e) {
  document.getElementById("passaporte").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler o passaporte: {1}", {1: h(e.message)}));
});
