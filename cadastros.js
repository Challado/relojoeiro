// Cadastros: os dados da API (recurso=cadastros), uma aba por vez (cadastros.php?aba=campos&editar=3), com as gravações
// pela API (recurso=campos, lancamento_tipos, formulas, avisos, modos e documento_categorias) e o testar das fórmulas
// (recurso=calcular).
var ABAS = {campos: "Campos", lancamentos: "Tipos de lançamento", formulas: "Fórmulas", avisos: "Avisos", modos: "Modos de rodízio", documentos: "Categorias de documentos"};
var RECURSO = {campos: "campos", lancamentos: "lancamento_tipos", formulas: "formulas", avisos: "avisos", modos: "modos", documentos: "documento_categorias"};
var FAMILIAS = {imagem: "imagens", video: "vídeos", audio: "áudios", pdf: "PDF", xml: "XML"};
var SEL = {inteligente: "Inteligente: a maior nota", ponderado: "Inteligente com sorteio: a nota é a chance", aleatorio: "Totalmente aleatório", fifo: "Fila: o que está há mais tempo sem uso"};
var DIAS = ["", "seg", "ter", "qua", "qui", "sex", "sáb", "dom"];
var ESCALAS = [[7, "7 dias"], [30, "30 dias"], [60, "60 dias"], [180, "6 meses"], [365, "1 ano"], [730, "2 anos"]];
var q = new URLSearchParams(window.location.search);
var aba = ABAS[q.get("aba")] ? q.get("aba") : "campos";
var editar = parseInt(q.get("editar") || "0", 10);
var recado = "";
var teste = null;

// o lugar na árvore por extenso
function caminho(d, id) {
  var res = "todos os relógios";
  d.grupos.forEach(function (g) {
    if (g.id === id) {
      res = g.caminho;
    }
  });
  return res;
}
function opcoesNo(d, sel) {
  return el("option", {"value": "0"}, "todos os relógios") + d.grupos.map(function (g) { return el("option", {"value": g.id, "selected": sel === g.id}, h(g.caminho)); }).join("");
}
// botões de uma linha da tabela: um formulário pequeno que grava pela API
function botaoLinha(acao, id, rotulo, confirma, extra) {
  return el("form", {"data-recurso": RECURSO[aba], "style": "display:inline", "onsubmit": confirma ? "return confirm(" + JSON.stringify(confirma) + ")" : null},
    el("input", {"type": "hidden", "name": "acao", "value": acao}) + el("input", {"type": "hidden", "name": "id", "value": id}) + (extra || el("button", {"class": "leve discreto"}, rotulo)));
}
function linkEditar(id) {
  return el("a", {"href": "cadastros.php?aba=" + aba + "&editar=" + id + "#form"}, "Editar");
}
function vazio(v) {
  return v === null || v === undefined ? "" : v;
}

// lê e monta a aba escolhida
function recarregarCadastros() {
  return api({recurso: "cadastros"}).then(function (d) {
    var res = el("h1", {}, "Cadastros") + el("nav", {"class": "abas"}, Object.keys(ABAS).map(function (k) { return el("a", {"href": "cadastros.php?aba=" + k, "class": k === aba ? "ativa" : ""}, h(ABAS[k])); }).join(""));
    if (recado !== "") {
      res += recado;
      recado = "";
    }
    if (aba === "campos") {
      var e = null;
      d.campos.forEach(function (c) { if (c.id === editar) { e = c; } });
      var f = e || {};
      res += el("section", {"class": "cartao-config"}, el("h2", {}, "Campos do cadastro dos relógios")
        + el("p", {"class": "nota"}, "Cada campo vale para o ponto da árvore escolhido e tudo abaixo dele (os de cada nível somam) e aparece no cadastro desses relógios. O identificador é o nome do campo nas fórmulas.")
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, ["Campo", "Identificador", "Tipo", "Vale para", "Padrão", ""].map(function (t) { return el("th", {}, t); }).join("")))
          + el("tbody", {}, d.campos.map(function (c) {
            return el("tr", {}, el("td", {}, h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "")) + el("td", {"class": "formula ident"}, h(c.identificador)) + el("td", {}, h(d.tipos_de_campo[c.tipo]))
              + el("td", {"class": "nota"}, h(caminho(d, c.no_id))) + el("td", {}, h(vazio(c.padrao)))
              + el("td", {"class": "acoes-linha"}, linkEditar(c.id) + " " + botaoLinha("ordem", c.id, "", null, el("button", {"class": "leve discreto", "name": "direcao", "value": "sobe"}, "↑")
                + el("button", {"class": "leve discreto", "name": "direcao", "value": "desce"}, "↓")) + botaoLinha("excluir", c.id, "Excluir", "Excluir o campo " + c.nome + " e os valores dele?")));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? "Alterar o campo " + h(e.nome) : "Novo campo")
        + el("form", {"data-recurso": "campos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "novo"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("label", {}, "Nome " + el("input", {"name": "nome", "value": vazio(f.nome), "required": true, "maxlength": "120"}))
          + el("label", {}, "Identificador " + el("input", {"name": "identificador", "value": vazio(f.identificador), "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"}))
          + el("label", {}, "Tipo " + el("select", {"name": "tipo"}, Object.keys(d.tipos_de_campo).map(function (k) { return el("option", {"value": k, "selected": f.tipo === k}, h(d.tipos_de_campo[k])); }).join("")))
          + el("label", {}, "Unidade " + el("input", {"name": "unidade", "value": vazio(f.unidade), "maxlength": "20"}))
          + el("label", {}, "Valor padrão " + el("input", {"name": "padrao", "value": vazio(f.padrao)}))
          + el("label", {}, "Vale para " + el("select", {"name": "no_id"}, opcoesNo(d, f.no_id)))
          + el("label", {}, "Opções (lista: uma por linha) " + el("textarea", {"name": "opcoes"}, h(vazio(f.opcoes))))
          + el("div", {"class": "botoes"}, el("button", {}, e ? "Salvar" : "Criar") + (e ? " " + el("a", {"href": "cadastros.php?aba=campos"}, "cancelar") : ""))));
    } else if (aba === "lancamentos") {
      var e = null;
      d.lancamento_tipos.forEach(function (t) { if (t.id === editar) { e = t; } });
      var f = e || {};
      res += el("section", {"class": "cartao-config"}, el("h2", {}, "Tipos de lançamento")
        + el("p", {"class": "nota"}, "O que se registra num relógio: instantâneo (corda), com valor (leitura de carga) ou sessão com início e fim (pulso, winder, sol). O identificador é o nome nas funções das fórmulas: HORAS(\"pulso\"; 30). Sessão exclusiva: o relógio fica num lugar só. Mede o gasto: cada leitura desse tipo mede quanto caiu por dia de uso ou guardado (a função MEDIDO das fórmulas). Vale quando: uma fórmula que diz para quais relógios o tipo vale (ex.: corda_manual, só para quem aceita corda); vazia, vale para todos do grupo.")
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, "Tipo") + el("th", {}, "Identificador") + el("th", {}, "Formato") + el("th", {}, "Fecha sozinho às")
            + el("th", {}, "Exclusiva") + el("th", {}, "Mede o gasto") + el("th", {}, "Vale quando") + el("th", {}, "Vale para") + el("th", {"class": "num"}, "Lançamentos") + el("th", {}, "")))
          + el("tbody", {}, d.lancamento_tipos.map(function (t) {
            return el("tr", {}, el("td", {}, h(t.nome)) + el("td", {"class": "formula ident"}, h(t.identificador)) + el("td", {}, h(d.formatos_de_lancamento[t.formato]) + (t.unidade !== "" ? " (" + h(t.unidade) + ")" : ""))
              + el("td", {}, h(String(vazio(t.fecha_as)).substr(0, 5))) + el("td", {}, t.exclusiva === 1 ? "sim" : "") + el("td", {}, t.mede_gasto === 1 ? "sim" : "") + el("td", {"class": "formula"}, h(vazio(t.condicao))) + el("td", {"class": "nota"}, h(caminho(d, t.no_id))) + el("td", {"class": "num"}, String(t.lancamentos))
              + el("td", {"class": "acoes-linha"}, linkEditar(t.id) + " " + botaoLinha("excluir", t.id, "Excluir", "Excluir o tipo " + t.nome + "?")));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? "Alterar " + h(e.nome) : "Novo tipo de lançamento")
        + el("form", {"data-recurso": "lancamento_tipos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "novo"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("label", {}, "Nome " + el("input", {"name": "nome", "value": vazio(f.nome), "required": true}))
          + el("label", {}, "Identificador " + el("input", {"name": "identificador", "value": vazio(f.identificador), "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"}))
          + el("label", {}, "Formato " + el("select", {"name": "formato"}, Object.keys(d.formatos_de_lancamento).map(function (k) { return el("option", {"value": k, "selected": f.formato === k}, h(d.formatos_de_lancamento[k])); }).join("")))
          + el("label", {}, "Unidade (com valor) " + el("input", {"name": "unidade", "value": vazio(f.unidade)}))
          + el("label", {}, "Fecha sozinho às (sessão; vazio: não fecha) " + el("input", {"type": "time", "name": "fecha_as", "value": String(vazio(f.fecha_as)).substr(0, 5)}))
          + el("label", {}, "Vale para " + el("select", {"name": "no_id"}, opcoesNo(d, f.no_id)))
          + el("label", {}, "Vale quando (fórmula: 1 vale, 0 não; vazio: sempre) " + el("input", {"name": "condicao", "class": "formula", "value": vazio(f.condicao), "placeholder": "ex.: corda_manual"}))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "exclusiva", "value": "0"}) + el("input", {"type": "checkbox", "name": "exclusiva", "value": "1", "checked": f.exclusiva === 1})
            + " sessão exclusiva (o relógio num lugar só)")
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "mede_gasto", "value": "0"}) + el("input", {"type": "checkbox", "name": "mede_gasto", "value": "1", "checked": f.mede_gasto === 1})
            + " mede o gasto (com valor: cada leitura mede o gasto em uso ou guardado, comparando com a anterior)")
          + el("div", {"class": "botoes"}, el("button", {}, e ? "Salvar" : "Criar"))));
    } else if (aba === "formulas") {
      var e = null;
      d.formulas.forEach(function (x) { if (x.id === editar) { e = x; } });
      var f = e || {};
      var sec = el("h2", {}, "Fórmulas")
        + el("p", {"class": "nota"}, "Uma fórmula pode ter uma versão em cada ponto da árvore: vale para o relógio a do ponto mais perto dele. Números com vírgula ou ponto, argumentos separados por ponto e vírgula, textos entre aspas. Variáveis: os identificadores dos campos e das fórmulas.")
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, "Fórmula") + el("th", {}, "Versão para") + el("th", {}, "Cálculo") + el("th", {}, "")))
          + el("tbody", {}, d.formulas.map(function (x) {
            return el("tr", {}, el("td", {}, h(x.nome) + " " + el("span", {"class": "nota formula"}, h(x.identificador) + (x.unidade !== "" ? " (" + h(x.unidade) + ")" : ""))) + el("td", {"class": "nota"}, h(caminho(d, x.no_id)))
              + el("td", {"class": "formula"}, h(x.expressao)) + el("td", {"class": "acoes-linha"}, linkEditar(x.id) + " " + botaoLinha("excluir", x.id, "Excluir", "Excluir esta versão de " + x.identificador + "?")));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? "Alterar " + h(e.identificador) + " (" + h(caminho(d, e.no_id)) + ")" : "Nova fórmula (ou nova versão de uma que existe)")
        + el("form", {"data-recurso": "formulas", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "nova"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + (e ? "" : el("label", {}, "Identificador " + el("input", {"name": "identificador", "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})))
          + el("label", {}, "Nome " + el("input", {"name": "nome", "value": vazio(f.nome), "required": true}))
          + el("label", {}, "Unidade " + el("input", {"name": "unidade", "value": vazio(f.unidade)}))
          + el("label", {}, "Versão para " + el("select", {"name": "no_id"}, opcoesNo(d, f.no_id)))
          + el("label", {"style": "grid-column: 1 / -1"}, "Cálculo " + el("textarea", {"name": "expressao", "class": "formula", "rows": "3", "required": true}, h(vazio(f.expressao))))
          + el("div", {"class": "botoes"}, el("button", {}, e ? "Salvar" : "Criar")))
        + el("h3", {"id": "testar"}, "Testar uma fórmula (sem gravar)")
        + el("form", {"id": "form-testar"}, el("textarea", {"name": "expressao", "class": "formula", "rows": "2", "style": "width:100%"}, h(teste ? teste.expressao : vazio(f.expressao)))
          + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, "Testar em todos os relógios")));
      if (teste) {
        sec += teste.erros && teste.erros.length > 0 ? el("div", {"class": "erros-form"}, teste.erros.map(function (x) { return el("p", {}, h(x)); }).join(""))
          : el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, "Relógio") + el("th", {"class": "num"}, "Resultado") + el("th", {}, "As partes da conta")))
            + el("tbody", {}, teste.resultados.map(function (t) {
              return el("tr", {}, el("td", {}, h(t.relogio)) + el("td", {"class": "num"}, t.valor === null ? "vazio" : h(typeof t.valor === "number" ? num(t.valor, 4) : t.valor))
                + el("td", {"class": "nota formula"}, h(Object.keys(t.partes || {}).map(function (k) {
                  var v = t.partes[k];
                  return k + " = " + (v === null ? "vazio" : (typeof v === "number" ? num(v, 3) : v));
                }).join("; "))));
            }).join(""))));
      }
      sec += el("details", {}, el("summary", {}, "As funções do motor") + el("ul", {"class": "nota"}, Object.keys(d.funcoes).map(function (k) { return el("li", {}, el("strong", {}, h(k)) + ": " + h(d.funcoes[k])); }).join("")));
      res += el("section", {"class": "cartao-config"}, sec);
    } else if (aba === "avisos") {
      var ESCALA_A = {nao: "não", uso: "no relógio do dia", sempre: "sempre"};
      var AGENDA_A = {janela: "só dentro da antecedência da agenda", sempre: "qualquer data"};
      var e = null;
      d.avisos.forEach(function (x) { if (x.id === editar) { e = x; } });
      var f = e || {};
      res += el("section", {"class": "cartao-config"}, el("h2", {}, "Avisos")
        + el("p", {"class": "nota"}, "Cada aviso tem uma fórmula da data prevista; entra \"em breve\" dentro da antecedência e \"atrasado\" quando a data passa. O \"vale quando\" (uma fórmula) diz para quais relógios o aviso vale (ex.: corda_manual = 0, só para o automático sem corda); vazio, vale para todos do grupo. O texto é o motivo nas mensagens ({motivo}): "
          + "\"Dar corda: San Martin (a reserva acaba em 3h, 28/09/2026 10:53)\"; nele: {relogio}, {data}, {quando} e {limite} (o limite de carga do relógio). O tipo que resolve vira o botão na tela Hoje. Como as fórmulas, pode ter uma versão por ponto da árvore. "
          + "Na escala inteligente: \"no relógio do dia\" é conferido no escolhido, do começo ao fim do bloco dele; \"sempre\", também nos guardados, todo dia. O que vence vira o lembrete do dia, e o lançamento "
          + "que resolve é simulado com o valor (leitura) ou as horas (sessão) do aviso. Se o aviso vai pelo Telegram e pela agenda é a Configuração, em \"O que vai para onde\"; aqui fica a data que ele usa na agenda.")
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, ["Aviso", "Versão para", "Data prevista", "Vale quando", "Antecedência", "Resolve", "Na escala", "Na agenda", "Ativo", ""].map(function (t, i) {
            return el("th", {"class": i === 4 ? "num" : null}, t);
          }).join(""))) + el("tbody", {}, d.avisos.map(function (a) {
            return el("tr", {}, el("td", {}, h(a.nome) + " " + el("span", {"class": "nota formula"}, h(a.identificador))) + el("td", {"class": "nota"}, h(caminho(d, a.no_id))) + el("td", {"class": "formula"}, h(a.expressao))
              + el("td", {"class": "formula"}, h(vazio(a.condicao))) + el("td", {"class": "num"}, num(a.antecedencia_dias, 2) + " d") + el("td", {}, h(vazio(a.resolve)))
              + el("td", {}, h(ESCALA_A[a.escala]) + (a.simula_valor !== null ? " " + el("span", {"class": "nota"}, "(" + num(a.simula_valor, 2) + ")") : "")
                + (a.simula_horas !== null ? " " + el("span", {"class": "nota"}, "(" + num(a.simula_horas, 2) + " h)") : ""))
              + el("td", {}, h(AGENDA_A[a.agenda])) + el("td", {}, a.ativo === 1 ? "sim" : "não")
              + el("td", {"class": "acoes-linha"}, linkEditar(a.id) + " " + botaoLinha("excluir", a.id, "Excluir", "Excluir esta versão do aviso?")));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? "Alterar " + h(e.nome) : "Novo aviso (ou nova versão)")
        + el("form", {"data-recurso": "avisos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "novo"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + (e ? "" : el("label", {}, "Identificador " + el("input", {"name": "identificador", "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})))
          + el("label", {}, "Nome " + el("input", {"name": "nome", "value": vazio(f.nome), "required": true}))
          + el("label", {}, "Antecedência (dias) " + el("input", {"name": "antecedencia_dias", "value": num(f.antecedencia_dias || 0, 2), "inputmode": "decimal"}))
          + el("label", {}, "Resolve " + el("select", {"name": "resolve"}, el("option", {"value": ""}, "—") + d.lancamento_tipos.map(function (t) {
            return el("option", {"value": t.identificador, "selected": f.resolve === t.identificador}, h(t.nome));
          }).join("")))
          + el("label", {}, "Versão para " + el("select", {"name": "no_id"}, opcoesNo(d, f.no_id)))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "ativo", "value": "0"}) + el("input", {"type": "checkbox", "name": "ativo", "value": "1", "checked": e ? e.ativo === 1 : true}) + " ativo")
          + el("label", {}, "Na escala inteligente " + el("select", {"name": "escala"}, Object.keys(ESCALA_A).map(function (k) { return el("option", {"value": k, "selected": (f.escala || "nao") === k}, h(ESCALA_A[k])); }).join("")))
          + el("label", {}, "Escala: valor do lançamento simulado (leitura) " + el("input", {"name": "simula_valor", "inputmode": "decimal", "value": f.simula_valor ? num(f.simula_valor, 2) : "", "placeholder": "ex.: 100"}))
          + el("label", {}, "Escala: horas da sessão simulada " + el("input", {"name": "simula_horas", "inputmode": "decimal", "value": f.simula_horas ? num(f.simula_horas, 2) : "", "placeholder": "ex.: 6"}))
          + el("label", {}, "Data na agenda " + el("select", {"name": "agenda"}, Object.keys(AGENDA_A).map(function (k) { return el("option", {"value": k, "selected": (f.agenda || "janela") === k}, h(AGENDA_A[k])); }).join("")))
          + el("label", {"style": "grid-column: 1 / -1"}, "Data prevista (fórmula) " + el("textarea", {"name": "expressao", "class": "formula", "rows": "2", "required": true}, h(vazio(f.expressao))))
          + el("label", {"style": "grid-column: 1 / -1"}, "Vale quando (fórmula: 1 vale, 0 não; vazio: vale sempre) " + el("input", {"name": "condicao", "class": "formula", "value": vazio(f.condicao),
            "placeholder": "ex.: corda_manual = 0"}))
          + el("label", {"style": "grid-column: 1 / -1"}, "Texto (o motivo) " + el("input", {"name": "texto", "value": vazio(f.texto), "maxlength": "300", "required": true}))
          + el("div", {"class": "botoes"}, el("button", {}, e ? "Salvar" : "Criar"))));
    } else if (aba === "modos") {
      var e = null;
      d.modos.forEach(function (x) { if (x.id === editar) { e = x; } });
      var f = e || {selecao: "ponderado", escala_dias: null, blocos: []};
      var linhas = f.blocos.concat([null, null]).map(function (b, k) {
        var ds = b ? b.dias.split(",") : [];
        return el("tr", {"data-bloco": true}, el("td", {}, el("input", {"name": "blocos[" + k + "][nome]", "value": b ? b.nome : "", "placeholder": b ? null : "(novo bloco)"}))
          + el("td", {"class": "dias-semana"}, DIAS.map(function (dn, n) {
            return n === 0 ? "" : el("label", {}, el("input", {"type": "checkbox", "name": "blocos[" + k + "][dias][]", "value": n, "checked": ds.indexOf(String(n)) >= 0}) + dn);
          }).join(""))
          + el("td", {}, el("select", {"name": "blocos[" + k + "][no_id]"}, opcoesNo(d, b ? b.no_id : 0)))
          + el("td", {}, el("select", {"name": "blocos[" + k + "][um_por]"}, el("option", {"value": "dia", "selected": !b || b.um_por === "dia"}, "um por dia") + el("option", {"value": "bloco", "selected": b !== null && b.um_por === "bloco"}, "um por bloco (na semana)")))
          + el("td", {}, el("select", {"name": "blocos[" + k + "][relogio_id]"}, el("option", {"value": "0"}, "— sorteia —") + d.relogios.map(function (r) {
            return el("option", {"value": r.id, "selected": b !== null && b.relogio_id === r.id}, h(r.nome));
          }).join(""))));
      }).join("");
      res += el("section", {"class": "cartao-config"}, el("h2", {}, "Modos de rodízio")
        + el("p", {"class": "nota"}, "Cada modo tem blocos de dias da semana. O sorteio segue os critérios, recalculados a cada dia com o uso dos dias anteriores do plano (a carga não é simulada: não se sabe se você vai carregar). Com o ciclo (opcional), um relógio só volta depois que todos os disponíveis do bloco passaram; dentro do ciclo, a forma de escolha decide a ordem. Um bloco sorteia entre os relógios disponíveis de um ponto da árvore — um relógio para o bloco inteiro na semana, ou um por dia — ou usa um relógio fixo. "
          + "A forma de escolha vale para o modo; a garantia de rodízio (" + d.max_sem_uso + " dias), para todos. Na escala inteligente, o modo monta o período inteiro de uma vez (de 7 dias a 2 anos), simulando as fórmulas dia a dia: "
          + "escolhe sempre pela maior nota no dia simulado, e o relógio escolhido fica os dias da fórmula dias_seguidos (vazio: 1); os blocos dizem de onde escolher em cada dia da semana (ou o relógio fixo), e o \"um por\" não conta. "
          + "A escala é refeita toda manhã a partir do estado real.")
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, "Modo") + el("th", {}, "Forma de escolha") + el("th", {}, "Blocos") + el("th", {}, "")))
          + el("tbody", {}, d.modos.map(function (m) {
            var esc = "";
            ESCALAS.forEach(function (x) { if (x[0] === m.escala_dias) { esc = x[1]; } });
            return el("tr", {}, el("td", {}, h(m.nome) + (d.modo_ativo === m.id ? " " + el("strong", {}, "(ativo)") : ""))
              + el("td", {}, (m.escala_dias !== null ? "Escala inteligente de " + h(esc || m.escala_dias + " dias") + ": a maior nota" : h(SEL[m.selecao])) + (m.ciclo === 1 ? " · com ciclo" : ""))
              + el("td", {"class": "nota"}, h(m.blocos.map(function (b) { return b.nome + " (" + b.dias.split(",").map(function (x) { return DIAS[parseInt(x, 10)]; }).join(", ") + ")"; }).join("; ")))
              + el("td", {"class": "acoes-linha"}, linkEditar(m.id) + (d.modo_ativo !== m.id ? " " + botaoLinha("ativar", m.id, "Ativar", null) + botaoLinha("excluir", m.id, "Excluir", "Excluir o modo " + m.nome + "?") : "")));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? "Alterar o modo " + h(e.nome) : "Novo modo")
        + el("form", {"data-recurso": "modos", "onsubmit": "this.querySelectorAll('tr[data-bloco]').forEach(function (tr) { var algum = tr.querySelector('input[type=checkbox]:checked') !== null; "
      + "tr.querySelectorAll('input, select').forEach(function (c) { c.disabled = !algum; }); }); return true;"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("div", {"class": "form-grade"}, el("label", {}, "Nome " + el("input", {"name": "nome", "value": e ? e.nome : "", "required": true}))
            + el("label", {}, "Planejamento " + el("select", {"name": "escala_dias"}, el("option", {"value": ""}, "sorteio pelos blocos") + ESCALAS.map(function (x) {
              return el("option", {"value": x[0], "selected": f.escala_dias === x[0]}, "escala inteligente de " + x[1]);
            }).join("") + (f.escala_dias !== null && ESCALAS.filter(function (x) { return x[0] === f.escala_dias; }).length === 0 ? el("option", {"value": f.escala_dias, "selected": true}, "escala inteligente de " + f.escala_dias + " dias") : "")))
            + el("label", {}, "Forma de escolha (no sorteio) " + el("select", {"name": "selecao"}, Object.keys(SEL).map(function (k) { return el("option", {"value": k, "selected": f.selecao === k}, h(SEL[k])); }).join(""))))
              + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "ciclo", "value": "0"}) + el("input", {"type": "checkbox", "name": "ciclo", "value": "1", "checked": e ? e.ciclo === 1 : false})
                + " ciclo: um relógio só volta depois que todos os do bloco passaram")
          + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, ["Bloco", "Dias", "Sortear de", "Um por", "Relógio fixo"].map(function (t) { return el("th", {}, t); }).join("")))
            + el("tbody", {}, linhas)))
          + el("p", {"class": "nota"}, "Bloco sem nenhum dia marcado sai. Cada dia da semana pode estar num bloco só.")
          + el("div", {"class": "botoes"}, el("button", {}, e ? "Salvar o modo" : "Criar o modo"))));
    } else if (aba === "documentos") {
      var e = null;
      d.documento_categorias.forEach(function (x) { if (x.id === editar) { e = x; } });
      var f = e || {aceita: []};
      res += el("section", {"class": "cartao-config"}, el("h2", {}, "Categorias de documentos")
        + el("p", {"class": "nota"}, "Os documentos de cada relógio (a página Documentos, aberta pela ficha) ficam numa categoria. \"Aceita\" limita os arquivos da categoria; "
          + "nada marcado, ela aceita qualquer arquivo. O jeito de abrir cada arquivo vem do tipo dele, não da categoria: as fotos na galeria, os vídeos no player, o PDF no visualizador, o XML da nota com o resumo, o resto para baixar. "
          + "Uma categoria com documentos não se exclui: passe os documentos para outra antes.")
        + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, ["Categoria", "Identificador", "Aceita", "Ordem", "Documentos", ""].map(function (t, i) {
            return el("th", {"class": i === 3 || i === 4 ? "num" : null}, t);
          }).join(""))) + el("tbody", {}, d.documento_categorias.map(function (c) {
            return el("tr", {}, el("td", {}, h(c.nome)) + el("td", {"class": "formula ident"}, h(c.identificador))
              + el("td", {}, c.aceita.length === 0 ? "qualquer arquivo" : h(c.aceita.map(function (x) { return FAMILIAS[x] || x; }).join(", ")))
              + el("td", {"class": "num"}, String(c.ordem)) + el("td", {"class": "num"}, String(c.documentos))
              + el("td", {"class": "acoes-linha"}, linkEditar(c.id) + (c.documentos === 0 ? " " + botaoLinha("excluir", c.id, "Excluir", "Excluir a categoria " + c.nome + "?") : "")));
          }).join(""))))
        + el("h3", {"id": "form"}, e ? "Alterar a categoria " + h(e.nome) : "Nova categoria")
        + el("form", {"data-recurso": "documento_categorias", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "nova"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
          + el("label", {}, "Nome " + el("input", {"name": "nome", "value": vazio(f.nome), "required": true, "maxlength": "120"}))
          + (e ? "" : el("label", {}, "Identificador " + el("input", {"name": "identificador", "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})))
          + el("label", {}, "Ordem " + el("input", {"type": "number", "name": "ordem", "value": e ? e.ordem : "", "placeholder": "vazio: no fim"}))
          + el("fieldset", {"style": "grid-column: 1 / -1"}, el("legend", {}, "Aceita (nada marcado: qualquer arquivo)") + el("input", {"type": "hidden", "name": "aceita", "value": ""})
            + Object.keys(FAMILIAS).map(function (k) {
              return el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "aceita[]", "value": k, "checked": f.aceita.indexOf(k) >= 0}) + " " + h(FAMILIAS[k]));
            }).join(" "))
          + el("div", {"class": "botoes"}, el("button", {}, e ? "Salvar" : "Criar") + (e ? " " + el("a", {"href": "cadastros.php?aba=documentos"}, "cancelar") : ""))));
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
  if (res.ok) {
    recado = el("p", {"class": "acao"}, h(res.mensagem));
    editar = 0;
    history.replaceState(null, "", "cadastros.php?aba=" + aba);
    recarregarCadastros().then(function () {
      window.scrollTo(0, 0);
    });
  } else {
    form.querySelectorAll("button").forEach(function (b) {
      b.disabled = false;
    });
    form.querySelectorAll("[disabled]").forEach(function (c) {
      c.disabled = false;
    });
    var velho = document.querySelector("#cadastros .erros-form.da-gravacao");
    if (velho) {
      velho.remove();
    }
    document.querySelector("#cadastros nav.abas").insertAdjacentHTML("afterend", el("div", {"class": "erros-form da-gravacao"}, res.erros.map(function (e) { return el("p", {}, h(e)); }).join("")));
    window.scrollTo(0, 0);
  }
};

recarregarCadastros().then(function () {
  if (window.location.hash) {
    var alvo = document.querySelector(window.location.hash);
    if (alvo) {
      alvo.scrollIntoView();
    }
  }
}).catch(function (e) {
  document.getElementById("cadastros").innerHTML = el("p", {"class": "acao"}, "Não consegui ler os cadastros: " + h(e.message));
});
