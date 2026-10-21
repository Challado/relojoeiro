// Cadastros: os dados da API (recurso=cadastros; a aba Totais, recurso=totais; a aba Calibres, recurso=calibres; a aba
// Unidades, recurso=unidades), uma aba por vez (cadastros.php?aba=campos&editar=3), com as gravações pela API (recurso=campos,
// secoes, lancamento_tipos, formulas, totais, calibres, unidades e documento_categorias), o testar das fórmulas (recurso=calcular) e o dos totais (recurso=totais, expressao=). Os avisos ficam na página Avisos (avisos.js) e os modos de rodízio, na Modos de rodízio (modos.js).
var ABAS = {campos: t("Campos"), secoes: t("Divisórias"), unidades: t("Unidades"), lancamentos: t("Tipos de lançamento"), formulas: t("Fórmulas"), totais: t("Totais"), calibres: t("Calibres"), documentos: t("Categorias de documentos")};
var RECURSO = {campos: "campos", secoes: "secoes", unidades: "unidades", lancamentos: "lancamento_tipos", formulas: "formulas", totais: "totais", calibres: "calibres", documentos: "documento_categorias"};
var FAMILIAS = {imagem: t("imagens"), video: t("vídeos"), audio: t("áudios"), pdf: t("PDF"), xml: t("XML")};
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
// o menu da unidade (as do cadastro da árvore: a aba Unidades), com a que o item já tem
function campoUnidade(d, rotulo, sel) {
  return el("label", {}, rotulo + " " + el("select", {"name": "unidade"}, el("option", {"value": ""}, t("— nenhuma")) + d.unidades.map(function (u) {
    return el("option", {"value": u.simbolo, "selected": u.simbolo === sel}, h(u.simbolo) + " · " + h(u.nome));
  }).join("")) + el("small", {}, el("a", {"href": "cadastros.php?aba=unidades"}, t("cadastrar unidades"))));
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
  if (aba === "totais") {
    return recarregarTotais();
  }
  if (aba === "calibres") {
    return recarregarCalibres();
  }
  if (aba === "unidades") {
    return recarregarUnidades();
  }
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
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Campo"), t("Identificador"), t("Tipo"), t("Divisória"), t("Vale para"), t("Padrão"), ""].map(function (item) { return el("th", {}, item); }).join("")))
          + el("tbody", {}, d.campos.map(function (c) {
            var sec = d.secoes.filter(function (x) { return x.id === c.secao_id; })[0];
            return el("tr", {}, el("td", {}, h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "")) + el("td", {"class": "formula ident"}, h(c.identificador)) + el("td", {}, h(d.tipos_de_campo[c.tipo]) + (c.multiplo === 1 ? t(" · vários valores") : "")
                + (c.regra ? el("small", {}, h(c.regra)) : ""))
              + el("td", {}, sec ? h(sec.nome) : el("span", {"class": "nota"}, t("nenhuma")))
              + el("td", {"class": "nota"}, h(caminho(d, c.no_id)) + (c.so_desejado === 1 ? t(" · só nos desejados") : "") + (c.calibre === 1 ? t(" · do movimento") : "")) + el("td", {}, h(vazio(c.padrao)))
              + el("td", {"class": "acoes-linha"}, linkEditar(c.id) + " " + botaoLinha("ordem", c.id, "", null, el("button", {"class": "leve discreto", "name": "direcao", "value": "sobe"}, "↑")
                + el("button", {"class": "leve discreto", "name": "direcao", "value": "desce"}, "↓")) + botaoLinha("excluir", c.id, t("Excluir"), t("Excluir o campo {1} e os valores dele?", {1: c.nome}))));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? t("Alterar o campo {1}", {1: h(e.nome)}) : t("Novo campo"))
        + el("form", {"data-recurso": "campos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "novo"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true, "maxlength": "120"})}))
          + el("label", {}, t("Identificador {1}", {1: el("input", {"name": "identificador", "value": vazio(f.identificador), "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})}))
          + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo"}, Object.keys(d.tipos_de_campo).map(function (k) { return el("option", {"value": k, "selected": f.tipo === k}, h(d.tipos_de_campo[k])); }).join(""))}))
          + campoUnidade(d, t("Unidade"), vazio(f.unidade))
          + el("label", {}, t("Divisória {1}", {1: el("select", {"name": "secao_id"}, el("option", {"value": "0"}, t("— nenhuma (no fim da ficha)")) + d.secoes.map(function (x) {
            return el("option", {"value": x.id, "selected": f.secao_id === x.id}, h(x.nome));
          }).join(""))}) + el("small", {}, el("a", {"href": "cadastros.php?aba=secoes"}, t("as divisórias"))))
          + el("label", {}, t("Valor padrão {1}", {1: el("input", {"name": "padrao", "value": vazio(f.padrao)})}))
          + el("label", {}, t("Vale para {1}", {1: el("select", {"name": "no_id"}, opcoesNo(d, f.no_id))}))
          + el("label", {}, t("Valores possíveis (um por linha; vazio: qualquer um) {1}", {1: el("textarea", {"name": "opcoes"}, h(vazio(f.opcoes)))})
            + el("small", {}, t("Na lista, obrigatórios; no texto e no número, o valor tem de ser um deles. No sim/não e na data, não valem.")))
          + el("label", {}, t("Mínimo {1}", {1: el("input", {"name": "minimo", "inputmode": "decimal", "value": f.minimo === null || f.minimo === undefined ? "" : String(f.minimo).replace(".", ",")})}) + el("small", {}, t("só no número; vazio: sem limite")))
          + el("label", {}, t("Máximo {1}", {1: el("input", {"name": "maximo", "inputmode": "decimal", "value": f.maximo === null || f.maximo === undefined ? "" : String(f.maximo).replace(".", ",")})}) + el("small", {}, t("só no número; vazio: sem limite")))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "multiplo", "value": "0"}) + el("input", {"type": "checkbox", "name": "multiplo", "value": "1", "checked": f.multiplo === 1})
            + t(" vários valores (marca quantos dos valores possíveis quiser: as funções do relógio)"))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "so_desejado", "value": "0"}) + el("input", {"type": "checkbox", "name": "so_desejado", "value": "1", "checked": f.so_desejado === 1})
            + t(" só nos desejados (a lista de desejos: o preço-alvo, a prioridade)"))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "calibre", "value": "0"}) + el("input", {"type": "checkbox", "name": "calibre", "value": "1", "checked": f.calibre === 1})
            + t(" do movimento (aparece no formulário do calibre: a reserva, a marcha esperada...)"))
          + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")) + (e ? " " + el("a", {"href": "cadastros.php?aba=campos"}, t("cancelar")) : ""))));
    } else if (aba === "secoes") {
      var e = null;
      d.secoes.forEach(function (x) { if (x.id === editar) { e = x; } });
      res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Divisórias da ficha"))
        + el("p", {"class": "nota"}, t("As divisórias agrupam os campos no cadastro do relógio, no painel, no passaporte e nos relatórios, nesta ordem. Cada campo fica numa delas (na aba Campos, o menu Divisória); os sem divisória vêm no fim. Excluir uma divisória não apaga os campos: eles ficam sem divisória."))
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Divisória")) + el("th", {"class": "num"}, t("Campos")) + el("th", {}, "")))
          + el("tbody", {}, d.secoes.map(function (x) {
            return el("tr", {}, el("td", {}, h(x.nome)) + el("td", {"class": "num"}, String(x.campos))
              + el("td", {"class": "acoes-linha"}, linkEditar(x.id) + " " + botaoLinha("ordem", x.id, "", null, el("button", {"class": "leve discreto", "name": "direcao", "value": "sobe"}, "↑")
                + el("button", {"class": "leve discreto", "name": "direcao", "value": "desce"}, "↓")) + botaoLinha("excluir", x.id, t("Excluir"))));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? t("Alterar a divisória {1}", {1: h(e.nome)}) : t("Nova divisória"))
        + el("form", {"data-recurso": "secoes", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": e ? e.id : 0})
          + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": e ? e.nome : "", "required": true, "maxlength": "80", "placeholder": t("ex.: Construção")})}))
          + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")) + (e ? " " + el("a", {"href": "cadastros.php?aba=secoes"}, t("cancelar")) : ""))));
    } else if (aba === "lancamentos") {
      var e = null;
      d.lancamento_tipos.forEach(function (item) { if (item.id === editar) { e = item; } });
      var f = e || {};
      res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Tipos de lançamento"))
        + el("p", {"class": "nota"}, t("O que se registra num relógio: instantâneo (corda), com valor (leitura de carga) ou sessão com início e fim (pulso, winder, sol). O identificador é o nome nas funções das fórmulas: HORAS(\"pulso\"; 30). Sessão exclusiva: o relógio fica num lugar só. Mede o gasto: cada leitura desse tipo mede quanto caiu por dia de uso ou guardado (a função MEDIDO das fórmulas). Vale quando: uma fórmula que diz para quais relógios o tipo vale (ex.: corda_manual, só para quem aceita corda); vazia, vale para todos do grupo. Mede a marcha: a leitura do desvio da hora (quanto o relógio está adiantado ou atrasado, em segundos) e o acerto da hora, de onde sai a marcha em segundos por dia (o quadro Precisão e as funções MARCHA e DESVIO_AGORA)."))
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Tipo")) + el("th", {}, t("Identificador")) + el("th", {}, t("Formato")) + el("th", {}, t("Fecha sozinho às"))
            + el("th", {}, t("Exclusiva")) + el("th", {}, t("Mede o gasto")) + el("th", {}, t("Mede a marcha")) + el("th", {}, t("Vale quando")) + el("th", {}, t("Vale para")) + el("th", {"class": "num"}, t("Lançamentos")) + el("th", {}, "")))
          + el("tbody", {}, d.lancamento_tipos.map(function (item) {
            return el("tr", {}, el("td", {}, h(item.nome)) + el("td", {"class": "formula ident"}, h(item.identificador)) + el("td", {}, h(d.formatos_de_lancamento[item.formato]) + (item.unidade !== "" ? " (" + h(item.unidade) + ")" : ""))
              + el("td", {}, h(String(vazio(item.fecha_as)).substr(0, 5))) + el("td", {}, item.exclusiva === 1 ? t("sim") : "") + el("td", {}, item.mede_gasto === 1 ? t("sim") : "") + el("td", {}, item.marcha ? h(d.marchas_de_lancamento[item.marcha]) : "") + el("td", {"class": "formula"}, h(vazio(item.condicao))) + el("td", {"class": "nota"}, h(caminho(d, item.no_id))) + el("td", {"class": "num"}, String(item.lancamentos))
              + el("td", {"class": "acoes-linha"}, linkEditar(item.id) + " " + botaoLinha("excluir", item.id, t("Excluir"), t("Excluir o tipo {1}?", {1: item.nome}))));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? t("Alterar {1}", {1: h(e.nome)}) : t("Novo tipo de lançamento"))
        + el("form", {"data-recurso": "lancamento_tipos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "novo"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true})}))
          + el("label", {}, t("Identificador {1}", {1: el("input", {"name": "identificador", "value": vazio(f.identificador), "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})}))
          + el("label", {}, t("Formato {1}", {1: el("select", {"name": "formato"}, Object.keys(d.formatos_de_lancamento).map(function (k) { return el("option", {"value": k, "selected": f.formato === k}, h(d.formatos_de_lancamento[k])); }).join(""))}))
          + campoUnidade(d, t("Unidade (com valor)"), vazio(f.unidade))
          + el("label", {}, t("Fecha sozinho às (sessão; vazio: não fecha) {1}", {1: el("input", {"type": "time", "name": "fecha_as", "value": String(vazio(f.fecha_as)).substr(0, 5)})}))
          + el("label", {}, t("Vale para {1}", {1: el("select", {"name": "no_id"}, opcoesNo(d, f.no_id))}))
          + el("label", {}, t("Vale quando (fórmula: 1 vale, 0 não; vazio: sempre) {1}", {1: el("input", {"name": "condicao", "class": "formula", "value": vazio(f.condicao), "placeholder": t("ex.: corda_manual")})}))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "exclusiva", "value": "0"}) + el("input", {"type": "checkbox", "name": "exclusiva", "value": "1", "checked": f.exclusiva === 1})
            + t(" sessão exclusiva (o relógio num lugar só)"))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "mede_gasto", "value": "0"}) + el("input", {"type": "checkbox", "name": "mede_gasto", "value": "1", "checked": f.mede_gasto === 1})
            + t(" mede o gasto (com valor: cada leitura mede o gasto em uso ou guardado, comparando com a anterior)"))
          + el("label", {}, t("Mede a marcha {1}", {1: el("select", {"name": "marcha"}, el("option", {"value": ""}, t("não")) + Object.keys(d.marchas_de_lancamento).map(function (k) {
            return el("option", {"value": k, "selected": f.marcha === k}, h(d.marchas_de_lancamento[k]));
          }).join(""))}))
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
          + campoUnidade(d, t("Unidade"), vazio(f.unidade))
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
              return el("tr", {}, el("td", {}, h(item.relogio)) + el("td", {"class": "num"}, item.valor === null ? t("vazio") : h(typeof item.valor === "number" ? num(item.valor, 4) : item.valor))
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
          + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")) + (e ? " " + el("a", {"href": "cadastros.php?aba=documentos"}, t("cancelar")) : ""))));
    }
    document.getElementById("cadastros").innerHTML = res;
  });
}

// um resultado de total, como na página de totais: a tabelinha com o total, o relógio com o valor, ou o número
function resultadoTotal(r) {
  if (r.tipo === "tabela") {
    return el("table", {"class": "quadro-tabela"}, el("tbody", {}, r.linhas.map(function (l) {
      return el("tr", {"class": l.sem ? "sem" : ""}, el("td", {}, h(l.rotulo)) + el("td", {"class": "num"}, h(l.texto)));
    }).join("")) + el("tfoot", {}, el("tr", {}, el("td", {}, t("Total")) + el("td", {"class": "num"}, h(r.total_texto)))));
  }
  if (r.tipo === "erro") {
    return el("span", {"class": "acao"}, h(r.erro));
  }
  return h(r.texto);
}

// a aba Totais: a lista (na ordem, com o que cada um dá agora em todos os relógios), o formulário de um total (a coluna, o
// nome, a expressão, onde aparece, a largura e o formato) com o Testar, e as funções de total e as variáveis da tabela
function recarregarTotais() {
  return api({recurso: "totais"}).then(function (d) {
    var e = null;
    d.totais.forEach(function (x) { if (x.id === editar) { e = x; } });
    var f = e || {coluna: null, rodape: false, pagina: true, largura: "estreito", formato: "numero"};
    var res = el("h1", {}, t("Cadastros")) + el("nav", {"class": "abas"}, Object.keys(ABAS).map(function (k) { return el("a", {"href": "cadastros.php?aba=" + k, "class": k === aba ? "ativa" : ""}, h(ABAS[k])); }).join(""));
    if (recado !== "") {
      res += recado;
      recado = "";
    }
    var marca = function (nome, ligado, desligada) {
      return el("input", {"type": "hidden", "name": nome, "value": "0"}) + el("input", {"type": "checkbox", "name": nome, "value": "1", "checked": ligado, "disabled": desligada});
    };
    var sec = el("h2", {}, t("Totais"))
      + el("p", {"class": "nota"}, t("Cada total é uma expressão do motor que olha para todos os relógios mostrados de uma vez (os que o filtro da tabela da página Hoje deixa). Ele aparece no rodapé da tabela, embaixo da coluna dele, e/ou como um quadro na {1}. Vários totais na mesma coluna ficam um embaixo do outro, na ordem desta lista.", {1: el("a", {"href": "totais.php"}, t("página de totais"))}))
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Coluna")) + el("th", {}, t("Nome")) + el("th", {}, t("Expressão"))
        + el("th", {}, t("Rodapé")) + el("th", {}, t("Página")) + el("th", {}, t("Dá agora")) + el("th", {}, "")))
        + el("tbody", {}, d.totais.map(function (x, i) {
          return el("tr", {}, el("td", {}, x.coluna_nome ? h(x.coluna_nome) : el("span", {"class": "nota"}, t("nenhuma"))) + el("td", {}, h(x.nome))
            + el("td", {"class": "formula"}, h(x.expressao)) + el("td", {}, x.rodape ? "✓" : "") + el("td", {}, x.pagina ? "✓" + (x.largura === "largo" ? " " + el("small", {}, t("largo")) : "") : "")
            + el("td", {"class": "nota"}, resultadoTotal(x.resultado))
            + el("td", {"class": "acoes-linha"}, (i > 0 ? botaoLinha("subir", x.id, "", null, el("button", {"class": "leve discreto", "title": t("Subir")}, "↑")) : "")
              + (i < d.totais.length - 1 ? botaoLinha("descer", x.id, "", null, el("button", {"class": "leve discreto", "title": t("Descer")}, "↓")) : "")
              + " " + linkEditar(x.id) + " " + botaoLinha("excluir", x.id, t("Excluir"))));
        }).join(""))))
      + el("h3", {"id": "form"}, e ? t("Alterar o total {1}", {1: h(e.nome)}) : t("Novo total"))
      + el("form", {"data-recurso": "totais", "class": "form-grade", "id": "form-total"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": e ? e.id : 0})
        + el("label", {}, t("Coluna {1}", {1: el("select", {"name": "coluna", "onchange": "this.form.querySelector('input[type=checkbox][name=rodape]').disabled = this.value === ''"},
          el("option", {"value": ""}, t("Nenhuma (só na página de totais)")) + d.colunas.map(function (c) { return el("option", {"value": c.chave, "selected": f.coluna === c.chave}, h(c.nome)); }).join(""))}))
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true, "maxlength": "120"})}))
        + el("label", {"style": "grid-column: 1 / -1"}, t("Expressão {1}", {1: el("textarea", {"name": "expressao", "class": "formula", "rows": "3", "required": true}, h(vazio(f.expressao)))}))
        + el("label", {"class": "check"}, marca("rodape", f.rodape, f.coluna === null) + " " + t("Exibir no rodapé do totalizador (embaixo da coluna)"))
        + el("label", {"class": "check"}, marca("pagina", f.pagina, false) + " " + t("Exibir na página de totais"))
        + el("label", {}, t("Largura na página {1}", {1: el("select", {"name": "largura"}, [["estreito", t("Estreito (três por linha no computador)")], ["largo", t("Largo (a linha inteira)")]].map(function (o) {
          return el("option", {"value": o[0], "selected": f.largura === o[0]}, o[1]); }).join(""))}))
        + el("label", {}, t("Formato dos números {1}", {1: el("select", {"name": "formato"}, d.formatos.map(function (o) { return el("option", {"value": o.chave, "selected": f.formato === o.chave}, h(o.nome)); }).join(""))}))
        + el("p", {"class": "nota", "style": "grid-column: 1 / -1"}, t("Só os totais de uma coluna vão para o rodapé; os outros, quantos você quiser, ficam só na página de totais."))
        + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")) + " " + el("button", {"type": "button", "class": "leve", "id": "testar-total"}, t("Testar em todos os relógios"))
          + (e ? " " + el("a", {"href": "cadastros.php?aba=totais"}, t("cancelar")) : "")))
      + el("div", {"id": "teste-total", "aria-live": "polite"}, "")
      + el("details", {}, el("summary", {}, t("As funções de total")) + el("ul", {"class": "nota"}, d.funcoes.map(function (x) { return el("li", {}, el("strong", {}, h(x.nome)) + ": " + h(x.descricao)); }).join(""))
        + el("p", {"class": "nota"}, t("Dentro delas vale tudo o que vale numa fórmula (os campos, as fórmulas, SE, DIAS_DESDE, MES_ANO...), calculado em cada relógio; fora delas, só números, textos e as funções que não precisam de um relógio.")))
      + el("details", {}, el("summary", {}, t("As variáveis da tabela")) + el("ul", {"class": "nota"}, d.variaveis.map(function (x) { return el("li", {}, el("strong", {}, h(x.nome)) + ": " + h(x.descricao)); }).join("")));
    res += el("section", {"class": "cartao-config"}, sec);
    document.getElementById("cadastros").innerHTML = res;
  });
}

// um valor de campo como texto (a lista e o formulário dos calibres): sim/não, data, número com vírgula
function valorDoCampo(c, v) {
  if (c.multiplo) {
    return String(v).split("\n").map(function (x) { return valorDoCampo({tipo: c.tipo, unidade: c.unidade}, x); }).join(", ");
  }
  if (c.tipo === "sim_nao") {
    return String(v) === "1" ? t("sim") : t("não");
  }
  if (c.tipo === "data") {
    return dataBr(v);
  }
  return c.tipo === "inteiro" || c.tipo === "decimal" ? String(v).replace(".", ",") + (c.unidade !== "" ? " " + c.unidade : "") : String(v);
}

// a entrada de um campo no formulário do calibre: vazio é "o calibre não define" (o sim/não também, por isso é uma lista)
function entradaDoCalibre(c, v) {
  var nome = "valores[" + c.identificador + "]";
  var rotulo = h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "");
  var entrada;
  var possiveis = String(c.opcoes || "").split("\n").filter(function (o) { return o.trim() !== ""; });
  if (c.multiplo) {
    // vários dos valores possíveis; nenhum marcado: o calibre não define
    var marcados = v === null || v === undefined ? [] : String(v).split("\n");
    return el("fieldset", {"class": "multiplo"}, el("legend", {}, rotulo) + el("input", {"type": "hidden", "name": nome + "[]", "value": ""}) + possiveis.map(function (o) {
      return el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": nome + "[]", "value": o, "checked": marcados.indexOf(o) >= 0}) + " " + h(o));
    }).join("") + (c.grupo ? el("small", {}, t("vale em {1}", {1: h(c.grupo)})) : ""));
  }
  if (c.tipo === "sim_nao" || c.tipo === "lista" || possiveis.length > 0) {
    var ops = c.tipo === "sim_nao" ? [["1", t("sim")], ["0", t("não")]] : possiveis.map(function (o) { return [o, o]; });
    entrada = el("select", {"name": nome}, el("option", {"value": ""}, t("— não define")) + ops.map(function (o) { return el("option", {"value": o[0], "selected": v !== null && String(v) === o[0]}, h(o[1])); }).join(""));
  } else if (c.tipo === "data") {
    entrada = el("input", {"type": "date", "name": nome, "value": vazio(v)});
  } else {
    entrada = el("input", {"name": nome, "value": v === null || v === undefined ? "" : (c.tipo === "texto" ? v : String(v).replace(".", ",")), "inputmode": c.tipo === "texto" ? null : "decimal",
      "placeholder": t("não define")}) + (c.regra ? el("small", {}, h(c.regra)) : "");
  }
  return el("label", {}, rotulo + " " + entrada + (c.grupo ? el("small", {}, t("vale em {1}", {1: h(c.grupo)})) : ""));
}

// a aba Calibres: a lista (o calibre, o fabricante, o que ele define e os relógios dele) e o formulário (o nome, o
// fabricante, o link do manual técnico, as recomendações e os valores dos campos que ele define)
function recarregarCalibres() {
  return api({recurso: "calibres"}).then(function (d) {
    var e = null;
    d.calibres.forEach(function (x) { if (x.id === editar) { e = x; } });
    var f = e || {valores: {}};
    var noForm = e ? e.campos_no_formulario : d.campos_no_formulario;
    var porIdent = {};
    d.campos.forEach(function (c) { porIdent[c.identificador] = c; });
    var res = el("h1", {}, t("Cadastros")) + el("nav", {"class": "abas"}, Object.keys(ABAS).map(function (k) { return el("a", {"href": "cadastros.php?aba=" + k, "class": k === aba ? "ativa" : ""}, h(ABAS[k])); }).join(""));
    if (recado !== "") {
      res += recado;
      recado = "";
    }
    // quais campos o formulário mostra: a Configuração da coleção (calibre_campos), trocada aqui mesmo
    var escolha = el("form", {"data-recurso": "config", "class": "linha calibre-campos"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
      + el("label", {}, t("Campos no formulário {1}", {1: el("select", {"name": "calibre_campos", "onchange": "this.form.requestSubmit()"},
        el("option", {"value": "movimento", "selected": d.campos_mostrar !== "todos"}, t("Só os do movimento (marcados na aba Campos)"))
        + el("option", {"value": "todos", "selected": d.campos_mostrar === "todos"}, t("Todos os campos do cadastro")))}))
      + el("span", {"class": "nota"}, t("Com só os do movimento, os que o calibre já define aparecem também, para poder tirar.")));
    var sec = el("h2", {}, t("Calibres"))
      + el("p", {"class": "nota"}, t("O calibre é o movimento do relógio (NH35, ST2130, B620...). Ele guarda o que é do movimento, não do relógio: o fabricante, o manual técnico, as recomendações e os valores dos campos (a reserva de marcha, a marcha esperada, aceita corda pela coroa...). O relógio com esse calibre usa esses valores nos campos que ele mesmo deixou vazios: vale o valor do relógio, senão o do calibre, senão o padrão do campo. Escolha o calibre no cadastro do relógio."))
      + (d.calibres.length === 0 ? el("p", {"class": "nota"}, t("Nenhum calibre cadastrado."))
        : el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Calibre")) + el("th", {}, t("Fabricante")) + el("th", {}, t("Define"))
          + el("th", {}, t("Relógios")) + el("th", {}, "")))
          + el("tbody", {}, d.calibres.map(function (x) {
            var def = Object.keys(x.valores).map(function (k) { return porIdent[k] ? h(porIdent[k].nome) + ": " + h(valorDoCampo(porIdent[k], x.valores[k])) : h(k); });
            return el("tr", {}, el("td", {}, h(x.nome) + (x.link ? " " + el("a", {"href": x.link, "target": "_blank", "rel": "noopener", "title": t("manual técnico")}, "↗") : ""))
              + el("td", {}, h(vazio(x.fabricante))) + el("td", {"class": "nota"}, def.length === 0 ? t("nada") : def.join("; "))
              + el("td", {"class": "nota"}, x.relogios.length === 0 ? t("nenhum") : x.relogios.map(function (r) { return el("a", {"href": "index.php?r=" + r.id}, h(r.nome)); }).join(", "))
              + el("td", {"class": "acoes-linha"}, el("a", {"href": "documentos.php?calibre=" + x.id}, t("Documentos ({1})", {1: x.documentos})) + " " + linkEditar(x.id) + " " + botaoLinha("excluir", x.id, t("Excluir"))));
          }).join("")))))
      + el("h3", {"id": "form"}, e ? t("Alterar o calibre {1}", {1: h(e.nome)}) : t("Novo calibre"))
      + escolha
      + el("form", {"data-recurso": "calibres", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": e ? e.id : 0})
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true, "maxlength": "80", "placeholder": t("ex.: NH35")})}))
        + el("label", {}, t("Fabricante {1}", {1: el("input", {"name": "fabricante", "value": vazio(f.fabricante), "maxlength": "80", "placeholder": t("ex.: Seiko")})}))
        + el("label", {"style": "grid-column: 1 / -1"}, t("Link do manual técnico {1}", {1: el("input", {"type": "url", "name": "link", "value": vazio(f.link), "maxlength": "500", "placeholder": fixo("https://")})}))
        + el("label", {"style": "grid-column: 1 / -1"}, t("Observação (as recomendações do fabricante) {1}", {1: el("textarea", {"name": "observacao", "rows": "3", "maxlength": "4000"}, h(vazio(f.observacao)))}))
        + el("fieldset", {"class": "campos-calibre", "style": "grid-column: 1 / -1"}, el("legend", {}, t("Os valores que o calibre define (vazio: não define)"))
          + d.campos.filter(function (c) { return noForm.indexOf(c.identificador) >= 0; }).map(function (c) {
            return entradaDoCalibre(c, f.valores[c.identificador] === undefined ? null : f.valores[c.identificador]);
          }).join(""))
        + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")) + (e ? " " + el("a", {"href": "cadastros.php?aba=calibres"}, t("cancelar")) : "")));
    res += el("section", {"class": "cartao-config"}, sec);
    document.getElementById("cadastros").innerHTML = res;
  });
}

// a aba Unidades (recurso=unidades): a tabela, por grandeza (cada base seguida das que se convertem nela), com a conversão
// e quem usa cada uma; e o formulário: o símbolo, o nome, a base e o fator (1 desta = fator da base), o zero (só o °F tem)
// e a unidade em que o valor também aparece
function recarregarUnidades() {
  return api({recurso: "unidades"}).then(function (d) {
    var e = null;
    d.unidades.forEach(function (x) { if (x.id === editar) { e = x; } });
    var f = e || {};
    var res = el("h1", {}, t("Cadastros")) + el("nav", {"class": "abas"}, Object.keys(ABAS).map(function (k) { return el("a", {"href": "cadastros.php?aba=" + k, "class": k === aba ? "ativa" : ""}, h(ABAS[k])); }).join(""));
    if (recado !== "") {
      res += recado;
      recado = "";
    }
    var usos = function (u) {
      var l = [];
      if (u.usos.campos > 0) { l.push(u.usos.campos === 1 ? t("1 campo") : t("{1} campos", {1: u.usos.campos})); }
      if (u.usos.tipos > 0) { l.push(u.usos.tipos === 1 ? t("1 tipo de lançamento") : t("{1} tipos de lançamento", {1: u.usos.tipos})); }
      if (u.usos.formulas > 0) { l.push(u.usos.formulas === 1 ? t("1 fórmula") : t("{1} fórmulas", {1: u.usos.formulas})); }
      return l.length === 0 ? t("nada") : l.join(", ");
    };
    var bases = d.grandezas.map(function (g) { return el("option", {"value": g.base, "selected": f.base === g.base}, h(g.base) + " · " + h(g.nome)); }).join("");
    var sec = el("h2", {}, t("Unidades"))
      + el("p", {"class": "nota"}, t("As unidades que os campos, os tipos de lançamento e as fórmulas usam (o símbolo vai depois do número: 47 mm). A conversão: cada unidade se converte na base dela (a polegada em milímetros: 1 pol = 25,4 mm), e as que têm a mesma base convertem entre si (na função CONVERTER das fórmulas). Mostrar também em: o valor aparece também nessa unidade (47 mm (1,85 pol)). Mudar o símbolo muda também nos campos, nos tipos e nas fórmulas que o usam."))
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Símbolo")) + el("th", {}, t("Nome")) + el("th", {}, t("Conversão"))
          + el("th", {}, t("Mostrar também em")) + el("th", {}, t("Em uso")) + el("th", {}, "")))
        + el("tbody", {}, d.unidades.map(function (u) {
          return el("tr", {"class": u.base === null ? "base-unidade" : ""}, el("td", {}, u.base === null ? el("strong", {}, h(u.simbolo)) : h(u.simbolo)) + el("td", {}, h(u.nome))
            + el("td", {"class": "nota"}, u.conversao === null ? t("base") : h(u.conversao)) + el("td", {}, h(vazio(u.mostrar_em))) + el("td", {"class": "nota"}, usos(u))
            + el("td", {"class": "acoes-linha"}, linkEditar(u.id) + " " + botaoLinha("excluir", u.id, t("Excluir"))));
        }).join(""))))
      + el("h3", {"id": "form"}, e ? t("Alterar a unidade {1}", {1: h(e.simbolo)}) : t("Nova unidade"))
      + el("form", {"data-recurso": "unidades", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": e ? e.id : 0})
        + el("label", {}, t("Símbolo {1}", {1: el("input", {"name": "simbolo", "value": vazio(f.simbolo), "required": true, "maxlength": "20", "placeholder": t("ex.: mm")})}))
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true, "maxlength": "80", "placeholder": t("ex.: milímetro")})}))
        + el("label", {}, t("Converte em (a base) {1}", {1: el("select", {"name": "base"}, el("option", {"value": ""}, t("— nenhuma: esta é uma base")) + bases)}))
        + el("label", {}, t("Fator (1 desta = quanto da base) {1}", {1: el("input", {"name": "fator", "value": f.fator === null || f.fator === undefined ? "" : num(f.fator, 9), "inputmode": "decimal", "placeholder": t("ex.: 25,4")})}))
        + el("label", {}, t("Zero (o valor desta que é o zero da base; quase sempre 0) {1}", {1: el("input", {"name": "zero", "value": f.zero ? num(f.zero, 9) : "", "inputmode": "decimal", "placeholder": fixo("0")})}))
        + el("label", {}, t("Mostrar também em {1}", {1: el("select", {"name": "mostrar_em"}, el("option", {"value": ""}, t("— nenhuma")) + d.grandezas.map(function (g) {
          return el("optgroup", {"label": g.base}, g.unidades.map(function (s) { return el("option", {"value": s, "selected": f.mostrar_em === s}, h(s)); }).join(""));
        }).join(""))}))
        + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")) + (e ? " " + el("a", {"href": "cadastros.php?aba=unidades"}, t("cancelar")) : "")));
    res += el("section", {"class": "cartao-config"}, sec);
    document.getElementById("cadastros").innerHTML = res;
  });
}

// o Testar dos totais: a expressão do formulário calculada em todos os relógios pela API, sem gravar
document.addEventListener("click", function (ev) {
  if (ev.target.id === "testar-total") {
    var form = ev.target.form;
    var caixa = document.getElementById("teste-total");
    api({recurso: "totais", parte: "teste", expressao: form.expressao.value, formato: form.formato.value}).then(function (r) {
      caixa.innerHTML = el("div", {"class": "quadro-total"}, el("h2", {}, t("Dá agora, em {1} relógios", {1: r.relogios})) + resultadoTotal(r.teste));
    }).catch(function (e) {
      caixa.innerHTML = el("p", {"class": "acao"}, h(e.message));
    });
  }
});

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
  // a troca dos campos do formulário do calibre (a Configuração) não acaba a edição: o formulário volta com os outros campos
  if (form.getAttribute("data-recurso") !== "config") {
    editar = 0;
    history.replaceState(null, "", "cadastros.php?aba=" + aba);
  }
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
