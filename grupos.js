// Grupos de relógios: a árvore da API (recurso=arvore), no HTML da página Grupos do sistema antigo.
var recadoGrupos = "";
var errosGrupos = [];


function recarregarGrupos() {
  return api({recurso: "arvore"}).then(function (d) {
    var g = {};
    d.grupos.forEach(function (x) {
      g[x.id] = x;
    });
    var opcoes = [[0, "(na raiz)"]].concat(d.grupos.map(function (x) { return [x.id, x.caminho]; }));
    var res = el("h1", {}, "Grupos de relógios");
    if (recadoGrupos !== "") {
      res += el("p", {"class": "acao"}, h(recadoGrupos));
    }
    if (errosGrupos.length > 0) {
      res += el("div", {"class": "erros-form"}, el("strong", {}, "Não foi salvo:") + errosGrupos.map(function (e) { return el("p", {}, h(e)); }).join(""));
    }
    res += el("p", {"class": "nota"}, "Uma árvore sem limite de níveis. Cada relógio fica num grupo só. Cada grupo pode ter os seus próprios " + el("a", {"href": "criterios.php"}, "critérios")
      + "; o relógio usa os do lugar mais perto dele (os dele, os do grupo dele, os do grupo de cima... ou os de todos). O mesmo nome pode se repetir em ramos diferentes. "
      + "Os campos, os tipos de lançamento, as fórmulas e os avisos de cada grupo ficam em " + el("a", {"href": "cadastros.php"}, "Cadastros") + ".");
    var arvore = d.grupos.map(function (x) {
      var rel = x.relogios.length;
      var cima = x.pai_id !== null ? g[x.pai_id].caminho : "a raiz";
      var aviso = "Excluir o grupo " + x.caminho + "?\n\nVão para " + cima + ": " + x.subgrupos + " subgrupo(s) e " + rel + " relógio(s), com os campos, os tipos de lançamento e as fórmulas dele."
        + (x.criterios > 0 ? "\nOs critérios próprios do grupo (" + x.criterios + " parâmetros) serão excluídos; os relógios passam a usar os de cima." : "");
      return el("li", {"style": "--nivel: " + x.profundidade, "id": "g" + x.id}, el("div", {"class": "no-arvore"},
          el("form", {"data-recurso": "arvore", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "renomear"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
            + el("input", {"name": "nome", "value": x.nome, "maxlength": "80", "aria-label": "Nome do grupo"}) + el("button", {"class": "leve discreto"}, "Renomear"))
          + " " + el("span", {"class": "nota"}, rel + " " + (rel === 1 ? "relógio" : "relógios") + (x.criterios > 0 ? " · " + el("a", {"href": "criterios.php?escopo=g:" + x.id}, "critérios próprios") : ""))
          + " " + el("span", {"class": "acoes-linha"}, el("button", {"form": "ord-" + x.id, "name": "direcao", "value": "sobe", "class": "leve discreto", "title": "Subir"}, "↑")
            + el("button", {"form": "ord-" + x.id, "name": "direcao", "value": "desce", "class": "leve discreto", "title": "Descer"}, "↓") + " " + el("button", {"form": "exc-" + x.id, "class": "leve discreto"}, "Excluir"))
          + el("form", {"data-recurso": "arvore", "id": "ord-" + x.id}, el("input", {"type": "hidden", "name": "acao", "value": "ordem"}) + el("input", {"type": "hidden", "name": "id", "value": x.id}))
          + el("form", {"data-recurso": "arvore", "id": "exc-" + x.id, "onsubmit": "return confirm(" + JSON.stringify(aviso) + ")"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"})
            + el("input", {"type": "hidden", "name": "id", "value": x.id})))
        + (rel > 0 ? el("div", {"class": "nota relogios-do-grupo"}, h(x.relogios.join(", "))) : "")
        + el("details", {"class": "mais-grupo"}, el("summary", {}, "subgrupo e mover")
          + el("form", {"data-recurso": "arvore", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "novo"}) + el("input", {"type": "hidden", "name": "pai_id", "value": x.id})
            + el("label", {}, "Novo subgrupo " + el("input", {"name": "nome", "maxlength": "80", "required": true})) + el("button", {"class": "leve"}, "Criar"))
          + el("form", {"data-recurso": "arvore", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "mover"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
            + el("label", {}, "Mover para dentro de " + el("select", {"name": "pai_id"}, opcoes.filter(function (op) { return op[0] === 0 || g[op[0]].cadeia.indexOf(x.id) < 0; }).map(function (op) {
              return el("option", {"value": op[0], "selected": (x.pai_id || 0) === op[0]}, h(op[1]));
            }).join(""))) + el("button", {"class": "leve"}, "Mover"))));
    }).join("");
    res += el("section", {"class": "cartao-config"}, el("h2", {}, "Árvore") + (d.grupos.length === 0 ? el("p", {}, "Nenhum grupo ainda.") : "") + el("ul", {"class": "arvore"}, arvore)
      + el("form", {"data-recurso": "arvore", "class": "linha-inclusao"}, el("input", {"type": "hidden", "name": "acao", "value": "novo"})
        + el("label", {}, "Novo grupo " + el("input", {"name": "nome", "maxlength": "80", "required": true}))
        + el("label", {}, "dentro de " + el("select", {"name": "pai_id"}, opcoes.map(function (op) { return el("option", {"value": op[0]}, h(op[1])); }).join(""))) + el("button", {}, "Criar")));
    res += el("section", {"class": "cartao-config", "id": "relogios"}, el("h2", {}, "O grupo de cada relógio") + el("form", {"data-recurso": "arvore"}, el("input", {"type": "hidden", "name": "acao", "value": "relogios"})
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"}, el("thead", {}, el("tr", {}, el("th", {}, "Relógio") + el("th", {}, "Grupo")))
        + el("tbody", {}, d.relogios.map(function (r) {
          return el("tr", {}, el("td", {}, h(r.nome) + (r.disponivel ? "" : " " + el("span", {"class": "nota"}, "(indisponível)")))
            + el("td", {}, el("select", {"name": "grupo[" + r.id + "]"}, opcoes.map(function (op) { return el("option", {"value": op[0], "selected": r.no_id === op[0]}, h(op[1])); }).join(""))));
        }).join(""))))
      + el("div", {"class": "botoes"}, el("button", {}, "Salvar grupos"))));
    document.getElementById("grupos").innerHTML = res;
  });
}

// como no antigo: gravou, a página volta com o recado; recusado, os erros no topo
aoGravar = function (form, res) {
  recadoGrupos = res.ok ? res.mensagem : "";
  errosGrupos = res.ok ? [] : res.erros;
  recarregarGrupos().then(function () {
    window.scrollTo(0, 0);
  });
};

recarregarGrupos().catch(function (e) {
  document.getElementById("grupos").innerHTML = el("p", {"class": "acao"}, "Não consegui ler os grupos: " + h(e.message));
});
