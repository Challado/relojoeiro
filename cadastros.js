// Cadastros: os dados da API (recurso=cadastros), uma aba por vez (cadastros.php?aba=campos&editar=3), com as gravações
// pela API (recurso=campos, lancamento_tipos, formulas e documento_categorias) e o testar das fórmulas
// (recurso=calcular). Os avisos ficam na página Avisos (avisos.js) e os modos de rodízio, na Modos de rodízio (modos.js).
var ABAS = {campos: t("Campos"), lancamentos: t("Tipos de lançamento"), formulas: t("Fórmulas"), documentos: t("Categorias de documentos")};
var RECURSO = {campos: "campos", lancamentos: "lancamento_tipos", formulas: "formulas", documentos: "documento_categorias"};
var FAMILIAS = {imagem: "imagens", video: t("vídeos"), audio: t("áudios"), pdf: t("PDF"), xml: t("XML")};
var q = new URLSearchParams(window.location.search);
// os avisos dos relógios saíram daqui: moram na página Avisos, junto com os lembretes (o endereço antigo leva para lá)
if (q.get("aba") === "avisos") {
  window.location.replace("avisos.php" + (q.get("editar") ? "?editar=" + encodeURIComponent(q.get("editar")) + "#form" : "#avisos-relogios"));
}
// e os modos de rodízio também: moram na página Modos de rodízio, no grupo Rodízio do menu
if (q.get("aba") === "modos") {
  window.location.replace("modos.php" + (q.get("editar") ? "?editar=" + encodeURIComponent(q.get("editar")) + "#form" : ""));
}
var aba = ABAS[q.get("aba")] ? q.get("aba") : "campos";
var editar = parseInt(q.get("editar") || "0", 10);
var recado = "";
var teste = null;

// o lugar na árvore por extenso
function caminho(d, id) {
  var res = t("todos os relógios");
  d.grupos.forEach(function (g) {
    if (g.id === id) {
      res = g.caminho;
    }
  });
  return res;
}
function opcoesNo(d, sel) {
  return el("option", {"value": "0"}, t("todos os relógios")) + d.grupos.map(function (g) { return el("option", {"value": g.id, "selected": sel === g.id}, h(g.caminho)); }).join("");
}
// botões de uma linha da tabela: um formulário pequeno que grava pela API (o Excluir não pergunta aqui: a API diz o que sai
// junto e a pergunta vem com isso, no api.js)
function botaoLinha(acao, id, rotulo, confirma, extra) {
  return el("form", {"data-recurso": RECURSO[aba], "style": "display:inline", "onsubmit": confirma && acao !== "excluir" ? "return confirm(" + JSON.stringify(confirma) + ")" : null},
    el("input", {"type": "hidden", "name": "acao", "value": acao}) + el("input", {"type": "hidden", "name": "id", "value": id}) + (extra || el("button", {"class": "leve discreto"}, rotulo)));
}
function linkEditar(id) {
  return el("a", {"href": "cadastros.php?aba=" + aba + "&editar=" + id + "#form"}, t("Editar"));
}
function vazio(v) {
  return v === null || v === undefined ? "" : v;
}

// lê e monta a aba escolhida
function recarregarCadastros() {
  return api({recurso: "cadastros"}).then(function (d) {
    var res = el("h1", {}, t("Cadastros")) + el("nav", {"class": "abas"}, Object.keys(ABAS).map(function (k) { return el("a", {"href": "cadastros.php?aba=" + k, "class": k === aba ? "ativa" : ""}, h(ABAS[k])); }).join(""));
    if (recado !== "") {
      res += recado;
      recado = "";
    }
    if (aba === "campos") {
      var e = null;
      d.campos.forEach(function (c) { if (c.id === editar) { e = c; } });
      var f = e || {};
      res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Campos do cadastro dos relógios"))
        + el("p", {"class": "nota"}, t("Cada campo vale para o ponto da árvore escolhido e tudo abaixo dele (os de cada nível somam) e aparece no cadastro desses relógios. O identificador é o nome do campo nas fórmulas. Marcado \"só nos desejados\", o campo aparece só nos relógios da lista de desejos."))
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Campo"), t("Identificador"), t("Tipo"), t("Vale para"), t("Padrão"), ""].map(function (item) { return el("th", {}, item); }).join("")))
          + el("tbody", {}, d.campos.map(function (c) {
            return el("tr", {}, el("td", {}, h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "")) + el("td", {"class": "formula ident"}, h(c.identificador)) + el("td", {}, h(d.tipos_de_campo[c.tipo]))
              + el("td", {"class": "nota"}, h(caminho(d, c.no_id)) + (c.so_desejado === 1 ? t(" · só nos desejados") : "")) + el("td", {}, h(vazio(c.padrao)))
              + el("td", {"class": "acoes-linha"}, linkEditar(c.id) + " " + botaoLinha("ordem", c.id, "", null, el("button", {"class": "leve discreto", "name": "direcao", "value": "sobe"}, "↑")
                + el("button", {"class": "leve discreto", "name": "direcao", "value": "desce"}, "↓")) + botaoLinha("excluir", c.id, t("Excluir"), t("Excluir o campo {1} e os valores dele?", {1: c.nome}))));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? t("Alterar o campo {1}", {1: h(e.nome)}) : t("Novo campo"))
        + el("form", {"data-recurso": "campos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "novo"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true, "maxlength": "120"})}))
          + el("label", {}, t("Identificador {1}", {1: el("input", {"name": "identificador", "value": vazio(f.identificador), "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})}))
          + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo"}, Object.keys(d.tipos_de_campo).map(function (k) { return el("option", {"value": k, "selected": f.tipo === k}, h(d.tipos_de_campo[k])); }).join(""))}))
          + el("label", {}, t("Unidade {1}", {1: el("input", {"name": "unidade", "value": vazio(f.unidade), "maxlength": "20"})}))
          + el("label", {}, t("Valor padrão {1}", {1: el("input", {"name": "padrao", "value": vazio(f.padrao)})}))
          + el("label", {}, t("Vale para {1}", {1: el("select", {"name": "no_id"}, opcoesNo(d, f.no_id))}))
          + el("label", {}, t("Opções (lista: uma por linha) {1}", {1: el("textarea", {"name": "opcoes"}, h(vazio(f.opcoes)))}))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "so_desejado", "value": "0"}) + el("input", {"type": "checkbox", "name": "so_desejado", "value": "1", "checked": f.so_desejado === 1})
            + t(" só nos desejados (a lista de desejos: o preço-alvo, a prioridade)"))
          + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")) + (e ? " " + el("a", {"href": "cadastros.php?aba=campos"}, "cancelar") : ""))));
    } else if (aba === "lancamentos") {
      var e = null;
      d.lancamento_tipos.forEach(function (item) { if (item.id === editar) { e = item; } });
      var f = e || {};
      res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Tipos de lançamento"))
        + el("p", {"class": "nota"}, t("O que se registra num relógio: instantâneo (corda), com valor (leitura de carga) ou sessão com início e fim (pulso, winder, sol). O identificador é o nome nas funções das fórmulas: HORAS(\"pulso\"; 30). Sessão exclusiva: o relógio fica num lugar só. Mede o gasto: cada leitura desse tipo mede quanto caiu por dia de uso ou guardado (a função MEDIDO das fórmulas). Vale quando: uma fórmula que diz para quais relógios o tipo vale (ex.: corda_manual, só para quem aceita corda); vazia, vale para todos do grupo."))
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Tipo")) + el("th", {}, t("Identificador")) + el("th", {}, t("Formato")) + el("th", {}, t("Fecha sozinho às"))
            + el("th", {}, t("Exclusiva")) + el("th", {}, t("Mede o gasto")) + el("th", {}, t("Vale quando")) + el("th", {}, t("Vale para")) + el("th", {"class": "num"}, t("Lançamentos")) + el("th", {}, "")))
          + el("tbody", {}, d.lancamento_tipos.map(function (item) {
            return el("tr", {}, el("td", {}, h(item.nome)) + el("td", {"class": "formula ident"}, h(item.identificador)) + el("td", {}, h(d.formatos_de_lancamento[item.formato]) + (item.unidade !== "" ? " (" + h(item.unidade) + ")" : ""))
              + el("td", {}, h(String(vazio(item.fecha_as)).substr(0, 5))) + el("td", {}, item.exclusiva === 1 ? t("sim") : "") + el("td", {}, item.mede_gasto === 1 ? t("sim") : "") + el("td", {"class": "formula"}, h(vazio(item.condicao))) + el("td", {"class": "nota"}, h(caminho(d, item.no_id))) + el("td", {"class": "num"}, String(item.lancamentos))
              + el("td", {"class": "acoes-linha"}, linkEditar(item.id) + " " + botaoLinha("excluir", item.id, t("Excluir"), t("Excluir o tipo {1}?", {1: item.nome}))));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? t("Alterar {1}", {1: h(e.nome)}) : t("Novo tipo de lançamento"))
        + el("form", {"data-recurso": "lancamento_tipos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "novo"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true})}))
          + el("label", {}, t("Identificador {1}", {1: el("input", {"name": "identificador", "value": vazio(f.identificador), "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})}))
          + el("label", {}, t("Formato {1}", {1: el("select", {"name": "formato"}, Object.keys(d.formatos_de_lancamento).map(function (k) { return el("option", {"value": k, "selected": f.formato === k}, h(d.formatos_de_lancamento[k])); }).join(""))}))
          + el("label", {}, t("Unidade (com valor) {1}", {1: el("input", {"name": "unidade", "value": vazio(f.unidade)})}))
          + el("label", {}, t("Fecha sozinho às (sessão; vazio: não fecha) {1}", {1: el("input", {"type": "time", "name": "fecha_as", "value": String(vazio(f.fecha_as)).substr(0, 5)})}))
          + el("label", {}, t("Vale para {1}", {1: el("select", {"name": "no_id"}, opcoesNo(d, f.no_id))}))
          + el("label", {}, t("Vale quando (fórmula: 1 vale, 0 não; vazio: sempre) {1}", {1: el("input", {"name": "condicao", "class": "formula", "value": vazio(f.condicao), "placeholder": t("ex.: corda_manual")})}))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "exclusiva", "value": "0"}) + el("input", {"type": "checkbox", "name": "exclusiva", "value": "1", "checked": f.exclusiva === 1})
            + t(" sessão exclusiva (o relógio num lugar só)"))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "mede_gasto", "value": "0"}) + el("input", {"type": "checkbox", "name": "mede_gasto", "value": "1", "checked": f.mede_gasto === 1})
            + t(" mede o gasto (com valor: cada leitura mede o gasto em uso ou guardado, comparando com a anterior)"))
          + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")))));
    } else if (aba === "formulas") {
      var e = null;
      d.formulas.forEach(function (x) { if (x.id === editar) { e = x; } });
      var f = e || {};
      var sec = el("h2", {}, t("Fórmulas"))
        + el("p", {"class": "nota"}, t("Uma fórmula pode ter uma versão em cada ponto da árvore: vale para o relógio a do ponto mais perto dele. Números com vírgula ou ponto, argumentos separados por ponto e vírgula, textos entre aspas. Variáveis: os identificadores dos campos e das fórmulas."))
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Fórmula")) + el("th", {}, t("Versão para")) + el("th", {}, t("Cálculo")) + el("th", {}, "")))
          + el("tbody", {}, d.formulas.map(function (x) {
            return el("tr", {}, el("td", {}, h(x.nome) + " " + el("span", {"class": "nota formula"}, h(x.identificador) + (x.unidade !== "" ? " (" + h(x.unidade) + ")" : ""))) + el("td", {"class": "nota"}, h(caminho(d, x.no_id)))
              + el("td", {"class": "formula"}, h(x.expressao)) + el("td", {"class": "acoes-linha"}, linkEditar(x.id) + " " + botaoLinha("excluir", x.id, t("Excluir"), t("Excluir esta versão de {1}?", {1: x.identificador}))));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? t("Alterar {1} ({2})", {1: h(e.identificador), 2: h(caminho(d, e.no_id))}) : t("Nova fórmula (ou nova versão de uma que existe)"))
        + el("form", {"data-recurso": "formulas", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "nova"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + (e ? "" : el("label", {}, t("Identificador {1}", {1: el("input", {"name": "identificador", "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})})))
          + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true})}))
          + el("label", {}, t("Unidade {1}", {1: el("input", {"name": "unidade", "value": vazio(f.unidade)})}))
          + el("label", {}, t("Versão para {1}", {1: el("select", {"name": "no_id"}, opcoesNo(d, f.no_id))}))
          + el("label", {"style": "grid-column: 1 / -1"}, t("Cálculo {1}", {1: el("textarea", {"name": "expressao", "class": "formula", "rows": "3", "required": true}, h(vazio(f.expressao)))}))
          + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar"))))
        + el("h3", {"id": "testar"}, t("Testar uma fórmula (sem gravar)"))
        + el("form", {"id": "form-testar"}, el("textarea", {"name": "expressao", "class": "formula", "rows": "2", "style": "width:100%"}, h(teste ? teste.expressao : vazio(f.expressao)))
          + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, t("Testar em todos os relógios"))));
      if (teste) {
        sec += teste.erros && teste.erros.length > 0 ? el("div", {"class": "erros-form"}, teste.erros.map(function (x) { return el("p", {}, h(x)); }).join(""))
          : el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Relógio")) + el("th", {"class": "num"}, t("Resultado")) + el("th", {}, t("As partes da conta"))))
            + el("tbody", {}, teste.resultados.map(function (item) {
              return el("tr", {}, el("td", {}, h(item.relogio)) + el("td", {"class": "num"}, item.valor === null ? "vazio" : h(typeof item.valor === "number" ? num(item.valor, 4) : item.valor))
                + el("td", {"class": "nota formula"}, h(Object.keys(item.partes || {}).map(function (k) {
                  var v = item.partes[k];
                  return k + " = " + (v === null ? "vazio" : (typeof v === "number" ? num(v, 3) : v));
                }).join("; "))));
            }).join(""))));
      }
      sec += el("details", {}, el("summary", {}, t("As funções do motor")) + el("ul", {"class": "nota"}, Object.keys(d.funcoes).map(function (k) { return el("li", {}, el("strong", {}, h(k)) + ": " + h(d.funcoes[k])); }).join("")));
      res += el("section", {"class": "cartao-config"}, sec);
    } else if (aba === "documentos") {
      var e = null;
      d.documento_categorias.forEach(function (x) { if (x.id === editar) { e = x; } });
      var f = e || {aceita: []};
      res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Categorias de documentos"))
        + el("p", {"class": "nota"}, t("Os documentos de cada relógio (a página Documentos, aberta pela ficha) ficam numa categoria. \"Aceita\" limita os arquivos da categoria; nada marcado, ela aceita qualquer arquivo. O jeito de abrir cada arquivo vem do tipo dele, não da categoria: as fotos na galeria, os vídeos no player, o PDF no visualizador, o XML da nota com o resumo, o resto para baixar. Uma categoria com documentos não se exclui: passe os documentos para outra antes."))
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Categoria"), t("Identificador"), t("Aceita"), t("Ordem"), t("Documentos"), ""].map(function (item, i) {
            return el("th", {"class": i === 3 || i === 4 ? "num" : null}, item);
          }).join(""))) + el("tbody", {}, d.documento_categorias.map(function (c) {
            return el("tr", {}, el("td", {}, h(c.nome)) + el("td", {"class": "formula ident"}, h(c.identificador))
              + el("td", {}, c.aceita.length === 0 ? t("qualquer arquivo") : h(c.aceita.map(function (x) { return FAMILIAS[x] || x; }).join(", ")))
              + el("td", {"class": "num"}, String(c.ordem)) + el("td", {"class": "num"}, String(c.documentos))
              + el("td", {"class": "acoes-linha"}, linkEditar(c.id) + (c.documentos === 0 ? " " + botaoLinha("excluir", c.id, t("Excluir"), t("Excluir a categoria {1}?", {1: c.nome})) : "")));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? t("Alterar a categoria {1}", {1: h(e.nome)}) : t("Nova categoria"))
        + el("form", {"data-recurso": "documento_categorias", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "nova"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true, "maxlength": "120"})}))
          + (e ? "" : el("label", {}, t("Identificador {1}", {1: el("input", {"name": "identificador", "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})})))
          + el("label", {}, t("Ordem {1}", {1: el("input", {"type": "number", "name": "ordem", "value": e ? e.ordem : "", "placeholder": t("vazio: no fim")})}))
          + el("fieldset", {"style": "grid-column: 1 / -1"}, el("legend", {}, t("Aceita (nada marcado: qualquer arquivo)")) + el("input", {"type": "hidden", "name": "aceita", "value": ""})
            + Object.keys(FAMILIAS).map(function (k) {
              return el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "aceita[]", "value": k, "checked": f.aceita.indexOf(k) >= 0}) + " " + h(FAMILIAS[k]));
            }).join(" "))
          + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")) + (e ? " " + el("a", {"href": "cadastros.php?aba=documentos"}, "cancelar") : ""))));
    }
    document.getElementById("cadastros").innerHTML = res;
  });
}

// o testar das fórmulas: calcula em cada relógio pela API, sem gravar
document.addEventListener("submit", function (ev) {
  if (ev.target.id === "form-testar") {
    ev.preventDefault();
    var expressao = ev.target.expressao.value;
    api({recurso: "calcular", expressao: expressao}).then(function (r) {
      teste = {expressao: expressao, erros: r.erros || [], resultados: r.resultados || []};
    }).catch(function (e) {
      teste = {expressao: expressao, erros: e.dados && e.dados.erros ? e.dados.erros : [e.message], resultados: []};
    }).then(function () {
      return recarregarCadastros();
    }).then(function () {
      document.getElementById("testar").scrollIntoView();
    });
  }
});

// gravou: a tabela volta com o recado, e a edição acaba; recusado: o que foi digitado fica, e os erros aparecem no topo
aoGravar = function (form, res) {
  if (!res.ok) { return salvarSemMover(form, res, function () {}); }
  recado = "";
  editar = 0;
  history.replaceState(null, "", "cadastros.php?aba=" + aba);
  return salvarSemMover(form, res, recarregarCadastros);
};

recarregarCadastros().then(function () {
  if (window.location.hash) {
    var alvo = document.querySelector(window.location.hash);
    if (alvo) {
      alvo.scrollIntoView();
    }
  }
}).catch(function (e) {
  document.getElementById("cadastros").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os cadastros: {1}", {1: h(e.message)}));
});
