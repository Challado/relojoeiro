// Modos de rodízio (o modos.php, no grupo Rodízio do menu): os modos, cada um com a tela do seu tipo (um por semana, semana e
// fim de semana, por dia da semana, aleatório todo dia, escala inteligente, personalizado), as semanas à frente, o critério de
// escolha e, no personalizado, o roteiro escrito à mão com o "Testar em todos os relógios". Lê a API (recurso=cadastros: os
// modos com o roteiro efetivo, os tipos, os grupos e os relógios) e grava nela (recurso=modos: salvar, ativar e excluir; o
// teste do roteiro lê recurso=roteiro). modos.php?editar=<id>#form abre um modo para alterar; ?tipo=<tipo> começa um novo
var SEL = {inteligente: t("Inteligente: a maior nota"), ponderado: t("Inteligente com sorteio: a nota é a chance"), aleatorio: t("Totalmente aleatório"), fifo: t("Fila: o que está há mais tempo sem uso")};
var DIAS = ["", t("seg"), t("ter"), t("qua"), t("qui"), t("sex"), t("sáb"), t("dom")];
var DIAS_LONGOS = ["", t("Segunda"), t("Terça"), t("Quarta"), t("Quinta"), t("Sexta"), t("Sábado"), t("Domingo")];
var ESCALAS = [[7, t("Uma semana")], [30, t("Um mês")], [60, t("Dois meses")], [180, t("Meio ano")], [365, t("Um ano")], [730, t("Dois anos")]];
var EXPLICA_TIPO = {
  semana: t("Um relógio para a semana inteira, do primeiro ao último dia dela (a semana começa no dia da Configuração). Escolha de onde ele sai e o critério; o Sortear de novo sorteia um para cada semana."),
  semana_fds: t("Dois trechos na semana: de segunda a sexta e no fim de semana, cada um com o seu grupo e com um relógio para o trecho inteiro ou um por dia."),
  dia_semana: t("Um relógio por dia, e cada dia da semana diz de onde ele sai: um grupo, um relógio fixo ou nenhum (dia livre)."),
  aleatorio: t("Um relógio por dia, todos com a mesma chance, sem olhar os critérios nem a garantia de rodízio. O de ontem fica de fora, se houver outro."),
  escala: t("Planeja o período inteiro de uma vez, simulando dia a dia a carga de cada relógio: a maior nota do dia simulado, o smartwatch pelos dias que a bateria dá (a fórmula dias_seguidos), os lembretes de carregar e dar corda anotados no dia. É refeita toda manhã a partir do estado real."),
  personalizado: t("Você escreve o roteiro: uma linha por nome, e o motor lê candidato, nota, peso, dias e garantia (o que faltar usa o padrão). Vale tudo o que vale numa fórmula do cadastro.")
};
var recado = "";
var dados = null;

// as opções de "Escolher de": todos, cada grupo e cada relógio (fixo); com "livre", também "Sem relógio (dia livre)"
function opcoesDe(sel, comLivre) {
  return el("option", {"value": "0", "selected": sel === "0"}, t("Toda a coleção"))
    + el("optgroup", {"label": t("Um grupo")}, dados.grupos.map(function (g) { return el("option", {"value": String(g.id), "selected": sel === String(g.id)}, h(g.caminho)); }).join(""))
    + el("optgroup", {"label": t("Um relógio fixo")}, dados.relogios.map(function (r) { return el("option", {"value": "r:" + r.id, "selected": sel === "r:" + r.id}, h(r.nome)); }).join(""))
    + (comLivre ? el("option", {"value": "livre", "selected": sel === "livre"}, t("Sem relógio (dia livre)")) : "");
}
// o valor de "Escolher de" de um bloco gravado
function valorDe(b) {
  return b === null ? "0" : (b.relogio_id !== null ? "r:" + b.relogio_id : String(b.no_id || 0));
}
// o bloco de um dia da semana (o primeiro que o cobre), ou null
function blocoDoDia(m, dia) {
  var res = null;
  m.blocos.forEach(function (b) { if (res === null && b.dias.split(",").indexOf(String(dia)) >= 0) { res = b; } });
  return res;
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
// de onde o modo escolhe, em texto, para a tabela
function deOnde(m) {
  var partes = [];
  m.blocos.forEach(function (b) {
    var alvo = b.relogio_id !== null ? (dados.relogios.filter(function (r) { return r.id === b.relogio_id; })[0] || {nome: "#" + b.relogio_id}).nome
      : (b.no_id ? (dados.grupos.filter(function (g) { return g.id === b.no_id; })[0] || {caminho: t("todos os relógios")}).caminho : t("todos os relógios"));
    partes.push(b.dias.split(",").map(function (x) { return DIAS[parseInt(x, 10)]; }).join(", ") + ": " + alvo + (b.um_por === "bloco" && m.tipo !== "escala" ? t(" (um para o bloco)") : ""));
  });
  return m.tipo === "personalizado" ? t("pelo roteiro") : partes.join("; ");
}
// os dias da semana na ordem da semana do rodízio: começando na segunda ou no domingo (a Configuração, semana_inicio)
function diasEmOrdem() {
  return dados.semana_inicio === "domingo" ? [7, 1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5, 6, 7];
}
// as semanas à frente: a lista das semanas (número; do primeiro ao último dia, pela semana_inicio), a partir de hoje
function listaSemanas(n) {
  var hoje = new Date(instante(dados.hoje));
  var seg = new Date(hoje.getTime());
  seg.setDate(seg.getDate() - ((hoje.getDay() || 7) - diasEmOrdem()[0] + 7) % 7);
  var linhas = [];
  for (var i = 0; i < n; i++) {
    var ini = new Date(seg.getTime());
    ini.setDate(ini.getDate() + i * 7);
    var fim = new Date(ini.getTime());
    fim.setDate(fim.getDate() + 6);
    // o número é o da semana ISO da segunda-feira dela (na semana que começa no domingo, a segunda é o dia seguinte)
    var segunda = new Date(ini.getTime());
    segunda.setDate(segunda.getDate() + (ini.getDay() === 0 ? 1 : 0));
    linhas.push(el("li", {}, t("Semana {1}: {2} a {3}", {1: numeroDaSemana(segunda), 2: dataBr(isoData(ini), true), 3: dataBr(isoData(fim), true)}) + (i === 0 ? " " + el("small", {}, t("(esta)")) : "")));
  }
  return el("ul", {"class": "semanas-lista"}, linhas.join(""));
}
function isoData(d) {
  return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2) + "-" + ("0" + d.getDate()).slice(-2);
}
// o número da semana do ano (ISO), para a lista
function numeroDaSemana(d) {
  var x = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  var dia = x.getUTCDay() || 7;
  x.setUTCDate(x.getUTCDate() + 4 - dia);
  var ano = new Date(Date.UTC(x.getUTCFullYear(), 0, 1));
  return Math.ceil(((x - ano) / 86400000 + 1) / 7);
}

// o ⓘ do "Escolher de": de onde sai o relógio de cada dia (por dia da semana, também o dia livre)
function ajudaEscolherDe(porDia) {
  return ajuda("escolher-de", t("Escolher de"), el("p", {}, t("De onde sai o relógio: a coleção toda, um grupo da árvore (com os subgrupos dele) ou um relógio fixo. O relógio fixo sai sempre que estiver disponível; indisponível (na oficina, fora do rodízio), o dia é sorteado entre os outros candidatos.")
    + (porDia ? " " + t("Na tabela por dia da semana, \"Sem relógio\" deixa o dia livre: sem sorteio e sem ninguém no pulso.") : "")));
}

// o formulário de um modo: os campos do tipo (f: o modo, ou o modelo de um novo com o tipo escolhido)
function formModo(e, f) {
  var tipo = f.tipo;
  var campos = el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": f.nome || "", "required": true, "maxlength": "80"})}))
    + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo", "data-troca-tipo": true}, dados.tipos_de_modo.map(function (x) {
      return el("option", {"value": x.chave, "selected": x.chave === tipo}, h(x.nome));
    }).join(""))}));
  var corpo = el("p", {"class": "nota"}, h(EXPLICA_TIPO[tipo]));
  var b1 = f.blocos[0] || null;
  if (tipo === "semana" || tipo === "aleatorio" || tipo === "escala") {
    corpo += el("div", {"class": "form-grade"}, el("label", {}, t("Escolher de {1}", {1: el("select", {"name": "de[0]"}, opcoesDe(valorDe(b1), false))}) + " " + ajudaEscolherDe())
      + (tipo === "escala" ? el("label", {}, t("Período {1}", {1: el("select", {"name": "escala_dias"}, ESCALAS.map(function (x) {
        return el("option", {"value": x[0], "selected": (f.escala_dias || 30) === x[0]}, h(x[1]));
      }).join("") + (f.escala_dias && ESCALAS.filter(function (x) { return x[0] === f.escala_dias; }).length === 0 ? el("option", {"value": f.escala_dias, "selected": true}, t("{1} dias", {1: f.escala_dias})) : ""))})
        + " " + ajuda("periodo-escala", t("O período da escala"), el("p", {}, t("Quantos dias a escala planeja de uma vez, contados de hoje (ou de amanhã, se o dia de hoje já começou no pulso: em {1}, \"A escala inteligente começa\" escolhe). Ela segue esse período até ele acabar, e aí o cron da manhã monta o seguinte. Mudou o período: na próxima vez que o plano é montado (a página Hoje, o cron), a escala é refeita com os dias novos; o Sortear de novo e o Apagar tudo também começam uma escala nova com eles.", {1: el("a", {"href": "configuracao.php"}, t("Configuração"))})))) : ""));
  } else if (tipo === "semana_fds") {
    var b2 = f.blocos[1] || null;
    corpo += el("div", {"class": "form-grade trechos"}, [[t("Segunda a sexta"), b1, 0, t("um relógio para os cinco dias")], [t("Sábado e domingo"), b2, 1, t("um relógio para os dois dias")]].map(function (x) {
      return el("fieldset", {"class": "trecho"}, el("legend", {}, x[0])
        + el("label", {}, t("Escolher de {1}", {1: el("select", {"name": "de[" + x[2] + "]"}, opcoesDe(valorDe(x[1]), false))}) + " " + (x[2] === 0 ? ajudaEscolherDe() : ajudaRef("escolher-de")))
        + el("p", {"class": "trecho-ajuda"}, x[2] === 0 ? ajuda("um-por", t("Um relógio para o trecho ou um por dia"), el("p", {}, t("Um relógio para o trecho: o mesmo relógio em todos os dias do trecho, dentro da semana (escolhido no primeiro dia dele). Um relógio por dia: cada dia tem a sua escolha, e o de ontem fica de fora se houver outro candidato."))) : ajudaRef("um-por"))
        + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "um_por[" + x[2] + "]", "value": "bloco", "checked": x[1] === null ? x[2] === 0 : x[1].um_por === "bloco"}) + " " + x[3])
        + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "um_por[" + x[2] + "]", "value": "dia", "checked": x[1] === null ? x[2] === 1 : x[1].um_por === "dia"}) + " " + t("um relógio por dia")));
    }).join(""));
  } else if (tipo === "dia_semana") {
    corpo += el("div", {"class": "rolagem"}, el("table", {"class": "compacta dias-da-semana"}, el("thead", {}, el("tr", {}, el("th", {}, t("Dia")) + el("th", {}, t("Escolher de") + " " + ajudaEscolherDe(true))))
      + el("tbody", {}, diasEmOrdem().map(function (dia) {
        var b = e ? blocoDoDia(f, dia) : null;
        return el("tr", {"class": dia >= 6 ? "fds" : ""}, el("td", {}, DIAS_LONGOS[dia]) + el("td", {}, el("select", {"name": "de[" + dia + "]", "aria-label": DIAS_LONGOS[dia]}, opcoesDe(e ? (b === null ? "livre" : valorDe(b)) : "0", true))));
      }).join(""))));
  } else if (tipo === "personalizado") {
    var hoje = new Date(instante(dados.hoje));
    var diasTeste = [];
    for (var i = 0; i < 14; i++) {
      var d = new Date(hoje.getTime());
      d.setDate(d.getDate() + i);
      diasTeste.push(el("option", {"value": isoData(d)}, (i === 0 ? t("hoje, ") : "") + DIAS_LONGOS[d.getDay() || 7].toLowerCase() + " " + dataBr(isoData(d), true)));
    }
    corpo += el("label", {"class": "roteiro-campo"}, t("O roteiro {1}", {1: el("textarea", {"name": "roteiro", "rows": "10", "spellcheck": "false", "required": true}, h(f.roteiro || f.roteiro_efetivo || ""))}))
      + ajudaNota("roteiro-linhas", t("As linhas do roteiro"), t("Uma linha por nome (\"nome = expressão\"); linhas que começam com # são comentários. Os nomes que o motor lê: {1} (1 entra na escolha do dia), {2} (a nota), {3} (a chance no sorteio: todos 0, a maior nota ganha; todos iguais, aleatório), {4} (quantos dias seguidos fica) e {5} (1 passa na frente de todos, o mais parado primeiro). Os outros nomes são seus. O que faltar usa o padrão: {6}.", {1: el("code", {}, "candidato"), 2: el("code", {}, "nota"), 3: el("code", {}, "peso"), 4: el("code", {}, "dias"), 5: el("code", {}, "garantia"),
        6: Object.keys(dados.roteiro_padrao).map(function (k) { return el("code", {}, k + " = " + h(dados.roteiro_padrao[k])); }).join(", ")}))
      + ajudaNota("roteiro-funcoes", t("As funções do roteiro"), t("Além das funções das fórmulas: DIA_SEMANA() (1 segunda a 7 domingo), SEMANA_DO_ANO(), NO_GRUPO(\"grupo\"), RELOGIO(\"nome\"), ESCOLHIDO_HA(n) (1 se foi o do plano há n dias), VEZES_NO_PLANO(n), ESCOLHA(n; a; b; ...) (o n-ésimo valor), SORTEIO() (0 a 1) e a variável nota_criterios. A lista completa está em {1}.", {1: el("a", {"href": "cadastros.php?aba=formulas"}, t("Cadastros › Fórmulas"))}))
      + el("div", {"class": "botoes testar-roteiro"}, el("button", {"type": "button", "class": "leve", "data-testar-roteiro": true}, t("Testar em todos os relógios"))
        + el("label", {}, t("no dia {1}", {1: el("select", {"name": "dia_teste"}, diasTeste.join(""))})))
      + el("div", {"id": "roteiro-teste"}, "");
  }
  if (tipo !== "escala") {
    var semanas = f.semanas || 1;
    corpo += el("div", {"class": "semanas"}, el("label", {}, t("Planejar {1} semanas à frente", {1: el("input", {"type": "number", "name": "semanas", "min": "1", "max": "8", "value": semanas, "class": "curto", "data-semanas": true})}))
      + el("div", {"data-semanas-lista": true}, listaSemanas(semanas))
      + ajudaNota("semanas", t("As semanas do plano"), t("O plano vem montado até o último dia da última semana ({1}); a seguinte entra quando a sua vez chega, ou antes, em \"Sortear de novo: a próxima semana\" (só com mais de uma semana). A semana começa no dia escolhido em {2}.", {1: dados.semana_inicio === "domingo" ? t("o sábado") : t("o domingo"), 2: el("a", {"href": "configuracao.php"}, t("Configuração"))})));
  }
  if (tipo === "semana" || tipo === "semana_fds" || tipo === "dia_semana") {
    corpo += el("label", {}, t("Critério de escolha (o sorteio) {1}", {1: el("select", {"name": "selecao"}, Object.keys(SEL).map(function (k) { return el("option", {"value": k, "selected": (f.selecao || "ponderado") === k}, h(SEL[k])); }).join(""))}))
      + ajudaNota("garantia", t("A garantia de rodízio"), t("A garantia de rodízio ({1} dias sem uso, no máximo) vale antes do critério, menos no aleatório; ela se muda no quadro Modo de rodízio da página Hoje e vale para todos os modos. A nota vem dos {2}.", {1: dados.max_sem_uso, 2: el("a", {"href": "criterios.php"}, t("critérios"))}));
  } else if (tipo === "escala") {
    corpo += ajudaNota("criterio", t("O critério de escolha"), t("Critério de escolha: a maior nota do dia simulado (a garantia de rodízio na frente). A nota vem dos {1}.", {1: el("a", {"href": "criterios.php"}, t("critérios"))}));
  }
  if (tipo !== "aleatorio") {
    corpo += el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "ciclo", "value": "0"}) + el("input", {"type": "checkbox", "name": "ciclo", "value": "1", "checked": f.ciclo === 1 || f.ciclo === true})
      + " " + t("ciclo: um relógio só volta depois que todos os candidatos passaram na semana") + " "
      + ajuda("ciclo", t("O ciclo"), el("p", {}, t("Ligado, um relógio que já foi para o pulso só volta depois que todos os candidatos do mesmo bloco passaram; aí o ciclo fecha e começa outro. Conta o plano desde o primeiro dia da semana (a semana começa no dia escolhido em {1}); na escala, o período inteiro dela. Se nenhum candidato que ainda não passou estiver disponível naquele dia, o ciclo é deixado de lado só nesse dia. Desligado, a escolha pode repetir um relógio na mesma semana (menos o de ontem).", {1: el("a", {"href": "configuracao.php"}, t("Configuração"))}))));
  }
  if (tipo !== "personalizado" && e) {
    corpo += el("details", {"class": "roteiro-gerado"}, el("summary", {}, t("O roteiro que este modo gera (só leitura)")) + el("pre", {"class": "formula"}, h(e.roteiro_efetivo))
      + el("p", {"class": "nota"}, t("Para escrever o seu, crie um modo do tipo Personalizado a partir deste: {1}.", {1: el("a", {"href": "modos.php?tipo=personalizado&copiar=" + e.id + "#form"}, t("copiar este roteiro"))})));
  }
  return el("form", {"data-recurso": "modos", "id": "form-modo", "onsubmit": "return montarBlocos(this)"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
    + el("div", {"class": "form-grade"}, campos) + corpo + el("div", {"id": "blocos-escondidos"}, "")
    + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar o modo") : t("Criar o modo")) + " " + el("a", {"class": "leve", "href": "modos.php"}, t("cancelar"))));
}

// antes de enviar: os campos da tela viram os blocos da API (blocos[k][dias][], no_id, um_por, relogio_id)
function montarBlocos(form) {
  var tipo = form.querySelector("select[name=tipo]").value;
  var caixa = form.querySelector("#blocos-escondidos");
  var blocos = [];
  var de = function (k) { var s = form.querySelector("select[name='de[" + k + "]']"); return s ? s.value : "0"; };
  var alvo = function (v) { return v.indexOf("r:") === 0 ? {no_id: 0, relogio_id: v.slice(2)} : {no_id: v, relogio_id: 0}; };
  if (tipo === "semana" || tipo === "aleatorio" || tipo === "escala") {
    blocos.push(Object.assign({nome: tipo === "semana" ? t("A semana") : t("Todo dia"), dias: [1, 2, 3, 4, 5, 6, 7], um_por: tipo === "semana" ? "bloco" : "dia"}, alvo(de(0))));
  } else if (tipo === "semana_fds") {
    var umPor = function (k) { var r = form.querySelector("input[name='um_por[" + k + "]']:checked"); return r ? r.value : "dia"; };
    blocos.push(Object.assign({nome: t("Segunda a sexta"), dias: [1, 2, 3, 4, 5], um_por: umPor(0)}, alvo(de(0))));
    blocos.push(Object.assign({nome: t("Sábado e domingo"), dias: [6, 7], um_por: umPor(1)}, alvo(de(1))));
  } else if (tipo === "dia_semana") {
    diasEmOrdem().forEach(function (dia) {
      var v = de(dia);
      if (v !== "livre") {
        blocos.push(Object.assign({nome: DIAS_LONGOS[dia], dias: [dia], um_por: "dia"}, alvo(v)));
      }
    });
    if (blocos.length === 0) {
      alert(t("Por dia da semana: escolha de onde sai o relógio em pelo menos um dia."));
      return false;
    }
  }
  var html = "";
  blocos.forEach(function (b, k) {
    var p = "blocos[" + k + "]";
    html += el("input", {"type": "hidden", "name": p + "[nome]", "value": b.nome}) + b.dias.map(function (d) { return el("input", {"type": "hidden", "name": p + "[dias][]", "value": d}); }).join("")
      + el("input", {"type": "hidden", "name": p + "[no_id]", "value": b.no_id}) + el("input", {"type": "hidden", "name": p + "[um_por]", "value": b.um_por}) + el("input", {"type": "hidden", "name": p + "[relogio_id]", "value": b.relogio_id});
  });
  caixa.innerHTML = html;
  return true;
}

// lê e monta a página
function recarregarModos() {
  return api({recurso: "cadastros"}).then(function (d) {
    dados = d;
    var params = new URLSearchParams(window.location.search);
    var editar = parseInt(params.get("editar") || "0", 10);
    var res = el("h1", {}, t("Modos de rodízio")) + ondeMuda("modos", [[t("Os modos, os blocos e quem entra em cada um"), t("aqui")],
      [t("O plano dos próximos dias que o modo monta"), lugar("plano.php", t("Rodízio › Plano"))],
      [t("Como o relógio de cada dia é escolhido (a nota, o peso)"), lugar("criterios.php", t("Rodízio › Critérios de escolha"))],
      [t("Se um relógio entra no rodízio"), t("{1} ou {2}: o relógio › Editar cadastro › Disponível para o rodízio", {1: lugar("index.php", t("Hoje")), 2: lugar("ficha.php", t("Ficha"))})]]);
    if (recado !== "") {
      res += recado;
      recado = "";
    }
    var e = null;
    d.modos.forEach(function (x) { if (x.id === editar) { e = x; } });
    var tipoNovo = params.get("tipo") && EXPLICA_TIPO[params.get("tipo")] ? params.get("tipo") : "semana";
    var f = e || {tipo: tipoNovo, selecao: "ponderado", escala_dias: null, semanas: 1, ciclo: 0, blocos: [], roteiro: ""};
    if (!e && params.get("copiar")) {
      d.modos.forEach(function (x) { if (x.id === parseInt(params.get("copiar"), 10)) { f.roteiro = x.roteiro_efetivo; f.nome = x.nome; f.semanas = x.semanas; } });
    }
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Modos de rodízio"))
      + ajudaNota("tipos", t("Os tipos de modo"), t("Cada modo tem um tipo, e a tela do tipo pede só o que faz sentido nele. Por baixo, todos geram o mesmo roteiro (candidato, nota, peso, dias, garantia), que o motor calcula relógio a relógio no começo de cada dia do plano, com os dias anteriores já contados como uso. O de ontem fica de fora, se houver outro; a garantia de rodízio ({1} dias) vale antes do critério; e o ciclo, se ligado, segura quem já passou na semana. Escolher o modo ou o critério não mexe no plano gravado: só o \"Sortear de novo\" do quadro Modo de rodízio refaz.", {1: d.max_sem_uso}))
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Modo")) + el("th", {}, t("Tipo")) + el("th", {}, t("Critério")) + el("th", {}, t("Semanas")) + el("th", {}, t("Escolhe de")) + el("th", {}, "")))
        + el("tbody", {}, d.modos.map(function (m) {
          var esc = "";
          ESCALAS.forEach(function (x) { if (x[0] === m.escala_dias) { esc = x[1]; } });
          var criterio = m.tipo === "escala" ? t("a maior nota") : (m.tipo === "aleatorio" ? t("todos com a mesma chance") : (m.tipo === "personalizado" ? t("pelo roteiro") : h(SEL[m.selecao])));
          return el("tr", {}, el("td", {}, h(m.nome) + (d.modo_ativo === m.id ? " " + el("strong", {}, t("(ativo)")) : ""))
            + el("td", {}, h(m.tipo_texto))
            + el("td", {}, criterio + (m.ciclo === 1 ? t(" · com ciclo") : ""))
            + el("td", {}, m.tipo === "escala" ? h(esc || t("{1} dias", {1: m.escala_dias})) : String(m.semanas))
            + el("td", {"class": "nota"}, h(deOnde(m)))
            + el("td", {"class": "acoes-linha"}, linkEditar(m.id) + (d.modo_ativo !== m.id ? " " + botaoLinha("ativar", m.id, t("Ativar"), null) + botaoLinha("excluir", m.id, t("Excluir"), t("Excluir o modo {1}?", {1: m.nome})) : "")));
        }).join(""))))
      + el("h3", {"id": "form"}, e ? t("Alterar o modo {1}", {1: h(e.nome)}) : t("Novo modo"))
      + formModo(e, f));
    document.getElementById("modos").innerHTML = res;
  });
}

// o tipo trocado no formulário de um modo novo: a tela do tipo é remontada (o que já foi digitado no nome fica)
document.addEventListener("change", function (ev) {
  if (ev.target.matches("[data-troca-tipo]")) {
    var form = ev.target.closest("form");
    var id = form.querySelector("input[name=id]");
    var nome = form.querySelector("input[name=nome]").value;
    var e = null;
    dados.modos.forEach(function (x) { if (id && x.id === parseInt(id.value, 10)) { e = x; } });
    var f = Object.assign({}, e || {selecao: "ponderado", escala_dias: null, semanas: 1, ciclo: 0, blocos: [], roteiro: ""}, {tipo: ev.target.value, nome: nome});
    form.outerHTML = formModo(e, f);
  }
  if (ev.target.matches("[data-semanas]")) {
    var n = Math.max(1, Math.min(8, parseInt(ev.target.value, 10) || 1));
    ev.target.closest(".semanas").querySelector("[data-semanas-lista]").innerHTML = listaSemanas(n);
  }
});

// Testar em todos os relógios: o roteiro da caixa, no dia escolhido (recurso=roteiro), numa tabela com as linhas calculadas
document.addEventListener("click", function (ev) {
  var b = ev.target.closest("[data-testar-roteiro]");
  if (b) {
    var form = b.closest("form");
    var caixa = form.querySelector("#roteiro-teste");
    caixa.innerHTML = el("p", {"class": "nota"}, t("Calculando…"));
    api({recurso: "roteiro", roteiro: form.querySelector("textarea[name=roteiro]").value, dia: form.querySelector("select[name=dia_teste]").value}).then(function (r) {
      var nomes = r.linhas.map(function (l) { return l.nome; });
      ["candidato", "nota", "peso", "dias", "garantia"].forEach(function (k) { if (nomes.indexOf(k) < 0) { nomes.push(k); } });
      var html = "";
      if (r.erros.length > 0) {
        html += el("p", {"class": "acao"}, h(r.erros.join(" · ")));
      }
      html += el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Relógio")) + nomes.map(function (n) { return el("th", {}, h(n)); }).join("")))
        + el("tbody", {}, r.relogios.map(function (x) {
          return el("tr", {"class": x.candidato ? "" : "fora"}, el("td", {}, h(x.nome)) + nomes.map(function (n) {
            var v = x.valores[n];
            return el("td", {"class": "num"}, v === null || v === undefined ? "—" : (typeof v === "number" ? num(v, Number.isInteger(v) ? 0 : 2) : h(String(v))));
          }).join(""));
        }).join(""))));
      html += el("p", {"class": "nota"}, r.escolhido ? t("Sairia {1} em {2}: {3}{4}", {1: el("strong", {}, h(r.escolhido.relogio)), 2: dataBr(r.dia, true), 3: h(r.escolhido.motivo), 4: r.escolhido.dias > 1 ? " " + t("Fica {1} dias seguidos.", {1: r.escolhido.dias}) : ""})
        : t("Nenhum relógio seria escolhido em {1} (ninguém é candidato nesse dia).", {1: dataBr(r.dia, true)}));
      caixa.innerHTML = html;
    }).catch(function (e) {
      caixa.innerHTML = el("p", {"class": "acao"}, h(e.message));
    });
  }
});

// gravou: a tabela volta com o recado, e a edição acaba; recusado: o que foi digitado fica, e os erros aparecem no topo
aoGravar = function (form, res) {
  if (!res.ok) { return salvarSemMover(form, res, function () {}); }
  recado = "";
  history.replaceState(null, "", "modos.php");
  return salvarSemMover(form, res, recarregarModos);
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
