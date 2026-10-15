// Modos de rodízio (o modos.php, no grupo Rodízio do menu): os modos, com os blocos de dias da semana, e o formulário de um
// modo. Lê a API (recurso=cadastros: os modos, o modo ativo, os grupos e os relógios) e grava nela (recurso=modos: salvar,
// ativar e excluir). modos.php?editar=<id>#form abre um modo para alterar
var SEL = {inteligente: t("Inteligente: a maior nota"), ponderado: t("Inteligente com sorteio: a nota é a chance"), aleatorio: t("Totalmente aleatório"), fifo: t("Fila: o que está há mais tempo sem uso")};
var DIAS = ["", t("seg"), t("ter"), t("qua"), t("qui"), t("sex"), t("sáb"), t("dom")];
var ESCALAS = [[7, t("7 dias")], [30, t("30 dias")], [60, t("60 dias")], [180, t("6 meses")], [365, t("1 ano")], [730, t("2 anos")]];
var recado = "";

function opcoesNo(d, sel) {
  return el("option", {"value": "0"}, t("todos os relógios")) + d.grupos.map(function (g) { return el("option", {"value": g.id, "selected": sel === g.id}, h(g.caminho)); }).join("");
}
// botões de uma linha da tabela: um formulário pequeno que grava pela API (o Excluir não pergunta aqui: a API diz o que sai
// junto e a pergunta vem com isso, no api.js)
function botaoLinha(acao, id, rotulo, confirma, extra) {
  return el("form", {"data-recurso": "modos", "style": "display:inline", "onsubmit": confirma && acao !== "excluir" ? "return confirm(" + JSON.stringify(confirma) + ")" : null},
    el("input", {"type": "hidden", "name": "acao", "value": acao}) + el("input", {"type": "hidden", "name": "id", "value": id}) + (extra || el("button", {"class": "leve discreto"}, rotulo)));
}
function linkEditar(id) {
  return el("a", {"href": "modos.php?editar=" + id + "#form"}, t("Editar"));
}

// lê e monta a página
function recarregarModos() {
  return api({recurso: "cadastros"}).then(function (d) {
    var editar = parseInt(new URLSearchParams(window.location.search).get("editar") || "0", 10);
    var res = el("h1", {}, t("Modos de rodízio"));
    if (recado !== "") {
      res += recado;
      recado = "";
    }
    var e = null;
    d.modos.forEach(function (x) { if (x.id === editar) { e = x; } });
    var f = e || {selecao: "ponderado", escala_dias: null, blocos: []};
    var linhas = f.blocos.concat([null, null]).map(function (b, k) {
      var ds = b ? b.dias.split(",") : [];
      return el("tr", {"data-bloco": true}, el("td", {}, el("input", {"name": "blocos[" + k + "][nome]", "value": b ? b.nome : "", "placeholder": b ? null : t("(novo bloco)")}))
        + el("td", {"class": "dias-semana"}, DIAS.map(function (dn, n) {
          return n === 0 ? "" : el("label", {}, el("input", {"type": "checkbox", "name": "blocos[" + k + "][dias][]", "value": n, "checked": ds.indexOf(String(n)) >= 0}) + dn);
        }).join(""))
        + el("td", {}, el("select", {"name": "blocos[" + k + "][no_id]"}, opcoesNo(d, b ? b.no_id : 0)))
        + el("td", {}, el("select", {"name": "blocos[" + k + "][um_por]"}, el("option", {"value": "dia", "selected": !b || b.um_por === "dia"}, t("um por dia")) + el("option", {"value": "bloco", "selected": b !== null && b.um_por === "bloco"}, t("um por bloco (na semana)"))))
        + el("td", {}, el("select", {"name": "blocos[" + k + "][relogio_id]"}, el("option", {"value": "0"}, t("— sorteia —")) + d.relogios.map(function (r) {
          return el("option", {"value": r.id, "selected": b !== null && b.relogio_id === r.id}, h(r.nome));
        }).join(""))));
    }).join("");
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Modos de rodízio"))
      + el("p", {"class": "nota"}, t("Cada modo tem blocos de dias da semana. O sorteio segue os critérios, recalculados a cada dia com o uso dos dias anteriores do plano (a carga não é simulada: não se sabe se você vai carregar). Com o ciclo (opcional), um relógio só volta depois que todos os disponíveis do bloco passaram; dentro do ciclo, a forma de escolha decide a ordem. Um bloco sorteia entre os relógios disponíveis de um ponto da árvore — um relógio para o bloco inteiro na semana, ou um por dia — ou usa um relógio fixo. A forma de escolha vale para o modo; a garantia de rodízio ({1} dias), para todos. Na escala inteligente, o modo monta o período inteiro de uma vez (de 7 dias a 2 anos), simulando as fórmulas dia a dia: escolhe sempre pela maior nota no dia simulado, e o relógio escolhido fica os dias da fórmula dias_seguidos (vazio: 1); os blocos dizem de onde escolher em cada dia da semana (ou o relógio fixo), e o \"um por\" não conta. A escala é refeita toda manhã a partir do estado real.", {1: d.max_sem_uso}))
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Modo")) + el("th", {}, t("Forma de escolha")) + el("th", {}, t("Blocos")) + el("th", {}, "")))
        + el("tbody", {}, d.modos.map(function (m) {
          var esc = "";
          ESCALAS.forEach(function (x) { if (x[0] === m.escala_dias) { esc = x[1]; } });
          return el("tr", {}, el("td", {}, h(m.nome) + (d.modo_ativo === m.id ? " " + el("strong", {}, t("(ativo)")) : ""))
            + el("td", {}, (m.escala_dias !== null ? t("Escala inteligente de {1}: a maior nota", {1: h(esc || t("{1} dias", {1: m.escala_dias}))}) : h(SEL[m.selecao])) + (m.ciclo === 1 ? t(" · com ciclo") : ""))
            + el("td", {"class": "nota"}, h(m.blocos.map(function (b) { return b.nome + " (" + b.dias.split(",").map(function (x) { return DIAS[parseInt(x, 10)]; }).join(", ") + ")"; }).join("; ")))
            + el("td", {"class": "acoes-linha"}, linkEditar(m.id) + (d.modo_ativo !== m.id ? " " + botaoLinha("ativar", m.id, t("Ativar"), null) + botaoLinha("excluir", m.id, t("Excluir"), t("Excluir o modo {1}?", {1: m.nome})) : "")));
        }).join(""))))
      + el("h3", {"id": "form"}, e ? t("Alterar o modo {1}", {1: h(e.nome)}) : t("Novo modo"))
      + el("form", {"data-recurso": "modos", "onsubmit": "this.querySelectorAll('tr[data-bloco]').forEach(function (tr) { var algum = tr.querySelector('input[type=checkbox]:checked') !== null; "
    + "tr.querySelectorAll('input, select').forEach(function (c) { c.disabled = !algum; }); }); return true;"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
        + el("div", {"class": "form-grade"}, el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": e ? e.nome : "", "required": true})}))
          + el("label", {}, t("Planejamento {1}", {1: el("select", {"name": "escala_dias"}, el("option", {"value": ""}, t("sorteio pelos blocos")) + ESCALAS.map(function (x) {
            return el("option", {"value": x[0], "selected": f.escala_dias === x[0]}, t("escala inteligente de {1}", {1: x[1]}));
          }).join("") + (f.escala_dias !== null && ESCALAS.filter(function (x) { return x[0] === f.escala_dias; }).length === 0 ? el("option", {"value": f.escala_dias, "selected": true}, t("escala inteligente de {1} dias", {1: f.escala_dias})) : ""))}))
          + el("label", {}, t("Forma de escolha (no sorteio) {1}", {1: el("select", {"name": "selecao"}, Object.keys(SEL).map(function (k) { return el("option", {"value": k, "selected": f.selecao === k}, h(SEL[k])); }).join(""))})))
            + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "ciclo", "value": "0"}) + el("input", {"type": "checkbox", "name": "ciclo", "value": "1", "checked": e ? e.ciclo === 1 : false})
              + t(" ciclo: um relógio só volta depois que todos os do bloco passaram"))
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Bloco"), t("Dias"), t("Sortear de"), t("Um por"), t("Relógio fixo")].map(function (item) { return el("th", {}, item); }).join("")))
          + el("tbody", {}, linhas)))
        + el("p", {"class": "nota"}, t("Bloco sem nenhum dia marcado sai. Cada dia da semana pode estar num bloco só."))
        + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar o modo") : t("Criar o modo")))));
    document.getElementById("modos").innerHTML = res;
  });
}

// gravou: a tabela volta com o recado, e a edição acaba; recusado: o que foi digitado fica, e os erros aparecem no topo
aoGravar = function (form, res) {
  if (res.ok) {
    recado = el("p", {"class": "acao"}, h(res.mensagem));
    history.replaceState(null, "", "modos.php");
    recarregarModos().then(function () {
      window.scrollTo(0, 0);
    });
  } else {
    form.querySelectorAll("button").forEach(function (b) {
      b.disabled = false;
    });
    form.querySelectorAll("[disabled]").forEach(function (c) {
      c.disabled = false;
    });
    var velho = document.querySelector("#modos .erros-form.da-gravacao");
    if (velho) {
      velho.remove();
    }
    document.querySelector("#modos h1").insertAdjacentHTML("afterend", el("div", {"class": "erros-form da-gravacao"}, (res.erros || []).map(function (e) { return el("p", {}, h(e)); }).join("")));
    window.scrollTo(0, 0);
  }
};

recarregarModos().then(function () {
  if (window.location.hash) {
    var alvo = document.querySelector(window.location.hash);
    if (alvo) {
      alvo.scrollIntoView();
    }
  }
}).catch(function (e) {
  document.getElementById("modos").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os modos: {1}", {1: h(e.message)}));
});
