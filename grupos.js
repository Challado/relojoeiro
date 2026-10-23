// Grupos de relógios: a árvore da API (recurso=arvore), no HTML da página Grupos do sistema antigo.
var recadoGrupos = "";
var errosGrupos = [];


function recarregarGrupos() {
  return api({recurso: "arvore"}).then(function (d) {
    var g = {};
    d.grupos.forEach(function (x) {
      g[x.id] = x;
    });
    var opcoes = [[0, t("(na raiz)")]].concat(d.grupos.map(function (x) { return [x.id, x.caminho]; }));
    var res = el("h1", {}, t("Grupos de relógios")) + ondeMuda("grupos", [[t("O nome e o lugar de um grupo na árvore"), t("aqui")],
      [t("Em qual grupo cada relógio está"), t("{1} ou {2}: o relógio › Editar cadastro › Grupo", {1: lugar("index.php", t("Hoje")), 2: lugar("ficha.php", t("Ficha"))})],
      [t("Os campos de um grupo (valem para ele e para tudo abaixo)"), t("{1}: a subtela do vale para do grupo", {1: lugar("cadastros.php?aba=campos", t("Cadastros › Campos"))})],
      [t("Os tipos de lançamento e as fórmulas de um grupo"), t("{1} e {2}", {1: lugar("cadastros.php?aba=lancamentos", t("Cadastros › Tipos de lançamento")), 2: lugar("cadastros.php?aba=formulas", t("Cadastros › Fórmulas"))})],
      [t("Os critérios de escolha de um grupo"), lugar("criterios.php", t("Rodízio › Critérios de escolha"))]]);
    if (recadoGrupos !== "") {
      res += el("p", {"class": "acao"}, h(recadoGrupos));
    }
    if (errosGrupos.length > 0) {
      res += el("div", {"class": "erros-form"}, el("strong", {}, t("Não foi salvo:")) + errosGrupos.map(function (e) { return el("p", {}, h(e)); }).join(""));
    }
    res += ajudaNota("grupos", t("Os grupos"), t("Uma árvore sem limite de níveis. Cada relógio fica num grupo só. Cada grupo pode ter os seus próprios {1}; o relógio usa os do lugar mais perto dele (os dele, os do grupo dele, os do grupo de cima... ou os de todos). O mesmo nome pode se repetir em ramos diferentes. Os campos, os tipos de lançamento, as fórmulas e os avisos de cada grupo ficam em {2}.", {1: el("a", {"href": "criterios.php"}, t("critérios")), 2: el("a", {"href": "cadastros.php"}, t("Cadastros"))}));
    var arvore = d.grupos.map(function (x) {
      var rel = x.relogios.length;
      var cima = x.pai_id !== null ? g[x.pai_id].caminho : t("a raiz");
      var aviso = t("Excluir o grupo {1}?\n\nVão para {2}: {3} subgrupo(s) e {4} relógio(s), com os campos, os tipos de lançamento e as fórmulas dele.{5}", {1: x.caminho, 2: cima, 3: x.subgrupos, 4: rel, 5: x.criterios > 0 ? t("\nOs critérios próprios do grupo ({1} parâmetros) serão excluídos; os relógios passam a usar os de cima.", {1: x.criterios}) : ""});
      return el("li", {"style": "--nivel: " + (x.profundidade), "id": "g" + x.id}, el("div", {"class": "no-arvore"},
          el("form", {"data-recurso": "arvore", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "renomear"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
            + el("input", {"name": "nome", "value": x.nome, "maxlength": "80", "aria-label": t("Nome do grupo")}) + el("button", {"class": "leve discreto"}, t("Renomear")))
          + " " + el("span", {"class": "nota"}, rel + " " + (rel === 1 ? t("relógio") : t("relógios")) + (x.criterios > 0 ? " · " + el("a", {"href": "criterios.php?escopo=g:" + x.id}, t("critérios próprios")) : ""))
          + " " + el("span", {"class": "acoes-linha"}, el("button", {"form": "ord-" + x.id, "name": "direcao", "value": "sobe", "class": "leve discreto", "title": t("Subir")}, "↑")
            + el("button", {"form": "ord-" + x.id, "name": "direcao", "value": "desce", "class": "leve discreto", "title": t("Descer")}, "↓") + " " + el("button", {"form": "exc-" + x.id, "class": "leve discreto"}, t("Excluir")))
          + el("form", {"data-recurso": "arvore", "id": "ord-" + x.id}, el("input", {"type": "hidden", "name": "acao", "value": "ordem"}) + el("input", {"type": "hidden", "name": "id", "value": x.id}))
          + el("form", {"data-recurso": "arvore", "id": "exc-" + x.id, "onsubmit": "return confirm(" + JSON.stringify(aviso) + ")"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"})
            + el("input", {"type": "hidden", "name": "id", "value": x.id})))
        + (rel > 0 ? el("div", {"class": "nota relogios-do-grupo"}, h(x.relogios.join(", "))) : "")
        + el("details", {"class": "mais-grupo"}, el("summary", {}, t("subgrupo e mover"))
          + el("form", {"data-recurso": "arvore", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "novo"}) + el("input", {"type": "hidden", "name": "pai_id", "value": x.id})
            + el("label", {}, t("Novo subgrupo {1}", {1: el("input", {"name": "nome", "maxlength": "80", "required": true})})) + el("button", {"class": "leve"}, t("Criar")))
          + el("form", {"data-recurso": "arvore", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "mover"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
            + el("label", {}, t("Mover para dentro de {1}", {1: el("select", {"name": "pai_id"}, opcoes.filter(function (op) { return op[0] === 0 || g[op[0]].cadeia.indexOf(x.id) < 0; }).map(function (op) {
              return el("option", {"value": op[0], "selected": (x.pai_id || 0) === op[0]}, h(op[1]));
            }).join(""))})) + el("button", {"class": "leve"}, t("Mover")))));
    }).join("");
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Árvore")) + (d.grupos.length === 0 ? el("p", {}, t("Nenhum grupo ainda.")) : "") + el("ul", {"class": "arvore"}, arvore)
      + el("form", {"data-recurso": "arvore", "class": "linha-inclusao"}, el("input", {"type": "hidden", "name": "acao", "value": "novo"})
        + el("label", {}, t("Novo grupo {1}", {1: el("input", {"name": "nome", "maxlength": "80", "required": true})}))
        + el("label", {}, t("dentro de {1}", {1: el("select", {"name": "pai_id"}, opcoes.map(function (op) { return el("option", {"value": op[0]}, h(op[1])); }).join(""))})) + el("button", {}, t("Criar"))));
    res += el("section", {"class": "cartao-config", "id": "relogios"}, el("h2", {}, t("O grupo de cada relógio")) + el("form", {"data-recurso": "arvore"}, el("input", {"type": "hidden", "name": "acao", "value": "relogios"})
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"}, el("thead", {}, el("tr", {}, el("th", {}, t("Relógio")) + el("th", {}, t("Grupo"))))
        + el("tbody", {}, d.relogios.map(function (r) {
          return el("tr", {}, el("td", {}, h(r.nome) + (r.disponivel ? "" : " " + el("span", {"class": "nota"}, t("(indisponível)"))))
            + el("td", {}, el("select", {"name": "grupo[" + r.id + "]"}, opcoes.map(function (op) { return el("option", {"value": op[0], "selected": r.no_id === op[0]}, h(op[1])); }).join(""))));
        }).join(""))))
      + el("div", {"class": "botoes"}, el("button", {}, t("Salvar grupos")))));
    document.getElementById("grupos").innerHTML = res;
  });
}

// como no antigo: gravou, a página volta com o recado; recusado, os erros no topo
aoGravar = function (form, res) {
  if (!res.ok) { return salvarSemMover(form, res, function () {}); }
  recadoGrupos = "";
  errosGrupos = [];
  return salvarSemMover(form, res, recarregarGrupos);
};

recarregarGrupos().catch(function (e) {
  document.getElementById("grupos").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os grupos: {1}", {1: h(e.message)}));
});
