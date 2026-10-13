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
  var res = el("div", {"class": "barra-impressao"}, el("button", {"type": "button", "onclick": "window.print()"}, "Imprimir") + " "
    + el("a", {"href": "ficha.php?id=" + r.id, "class": "botao leve"}, "Voltar para a ficha") + " " + el("span", {"class": "nota"}, "No papel saem só os dados (o \"Salvar como PDF\" do navegador guarda o arquivo)."));
  res += el("header", {"class": "cabeca-impressa"}, (r.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + r.id + "&v=" + r.foto, "alt": "Foto do " + r.nome}) : "")
    + el("div", {}, el("p", {"class": "data"}, "Passaporte do relógio · código " + r.id) + el("h1", {}, h(r.nome))
      + el("p", {}, [r.marca, r.referencia].filter(function (x) { return x !== null; }).map(h).join(" · ") + (r.marca || r.referencia ? " · " : "") + h(r.caminho))
      + el("p", {"class": "nota"}, h(r.posse_texto) + (r.posse === "colecao" ? (r.disponivel ? " · no rodízio" : " · fora do rodízio") : "") + " · no sistema desde " + dataBr(r.criado))
      + (r.observacao !== null ? el("p", {}, h(r.observacao)) : "")));
  res += el("div", {"class": "grade-impressa"},
    el("section", {}, el("h2", {}, "Compra") + (c.data === null && c.valor === null && c.loja === null && c.garantia_ate === null ? el("p", {"class": "nota"}, "Sem dados da compra.")
      : linhas([["Data", c.data !== null ? dataBr(c.data) : null], ["Valor pago", c.valor !== null ? "R$ " + reais(c.valor) : null], ["Loja", c.loja !== null ? h(c.loja) : null],
        ["Garantia até", c.garantia_ate !== null ? dataBr(c.garantia_ate) : null]])))
    + el("section", {}, el("h2", {}, "Uso") + (u.primeira_vez === null ? el("p", {"class": "nota"}, "Ainda não foi ao pulso.")
      : linhas([["Primeira vez no pulso", dataBr(u.primeira_vez)], ["Última vez", u.no_pulso_agora ? "no pulso agora" : dataBr(u.ultima_vez)],
        ["Horas no pulso", num(u.horas_no_pulso, 1) + " h"], ["Dias de uso", u.dias_de_uso !== null ? String(u.dias_de_uso) : null],
        ["Custo por uso", u.custo_por_uso !== null ? "R$ " + reais(u.custo_por_uso) : null]]))));
  res += el("section", {}, el("h2", {}, "Cadastro") + (d.cadastro.length === 0 ? el("p", {"class": "nota"}, "Nada preenchido.")
    : linhas(d.cadastro.map(function (x) { return [h(x.nome), h(x.valor)]; }))));
  res += el("section", {}, el("h2", {}, "Manutenções") + (d.marcacoes.length === 0 ? el("p", {"class": "nota"}, "Nenhuma marcação.")
    : linhas(d.marcacoes.map(function (m) { return [h(m.nome), m.vezes + (m.vezes === 1 ? " vez" : " vezes") + " · a última em " + dataBr(m.ultima)]; })))
    + (d.servicos.length > 0 ? el("p", {"class": "nota"}, "Revisões e trocas de pilha: " + d.servicos.map(function (s) { return dataBr(s.data) + " (" + h(s.nome.toLowerCase()) + ")"; }).join(", ") + ".") : ""));
  res += el("div", {"class": "grade-impressa"},
    el("section", {}, el("h2", {}, "Acessórios") + (d.acessorios.length === 0 ? el("p", {"class": "nota"}, "Nenhum.")
      : linhas(d.acessorios.map(function (a) { return [h(a.tipo_texto), h(a.nome) + (a.observacao !== null ? el("small", {}, h(a.observacao)) : "")]; }))))
    + el("section", {}, el("h2", {}, "Documentos") + (d.documentos.length === 0 ? el("p", {"class": "nota"}, "Nenhum.")
      : linhas(d.documentos.map(function (x) { return [h(x.categoria), h(x.titulo) + (x.data !== null ? " · " + dataBr(x.data) : "")]; })))));
  if (d.proximos_avisos.length > 0) {
    res += el("section", {}, el("h2", {}, "Próximos avisos") + linhas(d.proximos_avisos.map(function (a) { return [h(a.nome), h(a.texto)]; })));
  }
  res += el("p", {"class": "nota rodape-impresso"}, "Gerado em " + dataBr(d.gerado_em) + " " + horaBr(d.gerado_em) + " pelo Relojoeiro.");
  document.getElementById("passaporte").innerHTML = res;
  document.title = "Passaporte — " + r.nome;
}).catch(function (e) {
  document.getElementById("passaporte").innerHTML = el("p", {"class": "acao"}, "Não consegui ler o passaporte: " + h(e.message));
});
