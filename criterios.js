// Critérios de escolha: os dados da API (recurso=criterios), no HTML da página Critérios do sistema antigo. O lugar em
// edição fica no endereço (criterios.php?escopo=g:3), como antes; ?relogio=3 abre a conta da nota daquele relógio.
var SELECOES = {
  inteligente: [t("Inteligente"), t("vai o relógio com a maior nota nos critérios")],
  ponderado: [t("Inteligente com sorteio"), t("sorteio em que a nota de cada relógio é a chance dele: variedade sem perder o critério")],
  aleatorio: [t("Totalmente aleatório"), t("todos com a mesma chance, sem olhar os critérios (os números do RANDOM.ORG; fora do ar, do ANU QRNG; sem os dois, do gerador seguro do PHP)")],
  fifo: [t("Fila (FIFO)"), t("vai o que está há mais tempo sem uso: todos passam pelo pulso em rodízio")]
};
var q = new URLSearchParams(window.location.search);
var escopo = q.get("escopo") || "";
var recado = q.get("ok") || "";
var errosCrit = [];
var abrirFaixa = q.get("s") || "";

// porcentagem com vírgula: "12,5%"
function pct(v) {
  return num(v, 2) + "%";
}

// a conta da exclusão, para a confirmação: como o peso de quem sai se divide entre os que ficam
function contaExclusao(lista, id) {
  var outros = {};
  lista.forEach(function (x) {
    if (x.id !== id) {
      outros[x.id] = x.peso;
    }
  });
  // os 100% divididos entre os que ficam, na proporção de cada um (o arredondamento que sobra vai para o maior), como no back-end
  var novos = {};
  var ids = Object.keys(outros);
  var soma = ids.reduce(function (s, k) { return s + outros[k]; }, 0);
  ids.forEach(function (k) {
    novos[k] = Math.round((soma > 0 ? outros[k] * 100 / soma : 100 / Math.max(1, ids.length)) * 100) / 100;
  });
  if (ids.length > 0) {
    var maior = ids.reduce(function (a, b) { return novos[b] > novos[a] ? b : a; });
    novos[maior] = Math.round((novos[maior] + (100 - ids.reduce(function (s, k) { return s + novos[k]; }, 0))) * 100) / 100;
  }
  return lista.filter(function (x) { return x.id !== id; }).map(function (x) { return x.nome + ": " + pct(x.peso) + " → " + pct(novos[x.id]); });
}

// "+ faixa": uma linha nova, vazia, igual à última da tabela, com o próximo índice
document.addEventListener("click", function (ev) {
  var botao = ev.target.closest("[data-mais-faixa]");
  if (botao) {
    var corpo = botao.closest("form").querySelector("[data-faixas] tbody");
    var nova = corpo.lastElementChild.cloneNode(true);
    var indice = corpo.children.length;
    nova.querySelectorAll("input, select").forEach(function (campo) {
      campo.name = campo.name.replace(/\[\d+\]/, "[" + indice + "]");
      if (campo.type === "checkbox") {
        campo.checked = false;
      } else {
        campo.value = "";
      }
    });
    corpo.appendChild(nova);
  }
});

// lê e monta a página
function recarregarCriterios() {
  return api({recurso: "criterios"}).then(function (d) {
    var conjuntos = {};
    d.conjuntos.forEach(function (c) {
      conjuntos[c.escopo] = c;
    });
    var nomes = {};
    d.notas.forEach(function (n) {
      nomes[n.relogio_id] = n.relogio;
    });
    var lugarAtual = null;
    d.lugares.forEach(function (l) {
      if (l.escopo === escopo) {
        lugarAtual = l;
      }
    });
    if (lugarAtual === null) {
      escopo = "";
      lugarAtual = d.lugares[0];
    }
    var textoLugar = lugarAtual.lugar;
    var proprio = conjuntos[escopo] ? conjuntos[escopo].parametros : [];
    var usam = function (e) {
      return (conjuntos[e] ? conjuntos[e].usado_por : []).map(function (id) { return nomes[id]; });
    };
    var opcoesMedida = function (atual) {
      return Object.keys(d.variaveis).map(function (k) { return el("option", {"value": k, "selected": k === atual}, h(d.variaveis[k].nome)); }).join("");
    };
    var res = el("h1", {}, t("Critérios de escolha do relógio")) + ondeMuda("criterios", [[t("Os critérios e o peso de cada um"), t("aqui")],
      [t("A sua nota para cada relógio"), t("{1} ou {2}: o relógio › Editar cadastro", {1: lugar("index.php", t("Hoje")), 2: lugar("ficha.php", t("Ficha"))})],
      [t("Os modos que usam os critérios"), lugar("modos.php", t("Rodízio › Modos de rodízio"))], [t("As fórmulas que os critérios usam"), lugar("cadastros.php?aba=formulas", t("Cadastros › Fórmulas"))]]);
    if (recado !== "") {
      res += el("p", {"class": "acao"}, h(recado));
    }
    if (errosCrit.length > 0) {
      res += el("div", {"class": "erros-form"}, el("strong", {}, t("Não foi salvo:")) + errosCrit.filter(function (e, i) { return errosCrit.indexOf(e) === i; }).map(function (e) { return el("p", {}, h(e)); }).join(""));
    }
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Como a nota funciona"))
      + el("p", {}, t("Cada {1} — todos os relógios, um grupo em qualquer nível da árvore, ou um relógio — pode ter o seu {2}. O relógio usa o conjunto {3}, inteiro: o dele; senão o do grupo dele; senão o do grupo de cima; e assim até o de todos os relógios. Um lugar sem conjunto próprio herda o de cima.", {1: el("strong", {}, t("lugar")), 2: el("strong", {}, t("conjunto de critérios")), 3: el("strong", {}, t("mais perto dele"))}))
      + el("p", {}, t("Num conjunto, cada {1} tem um peso, e a soma fecha 100%. Cada parâmetro tem {2}, com peso dentro dele, que também fecha 100%. Cada subparâmetro mede uma coisa do relógio e usa {3} para transformar o valor medido numa nota de 0 a 100. {4} = peso do subparâmetro × peso do parâmetro; {5} = soma de nota × peso efetivo. Subparâmetro sem medida para o relógio (autonomia num relógio que não é smartwatch, por exemplo) sai da conta, e os pesos dos que ficam são reescalados.", {1: el("strong", {}, t("parâmetro")), 2: el("strong", {}, t("subparâmetros")), 3: el("strong", {}, t("faixas")), 4: el("strong", {}, t("Peso efetivo")), 5: el("strong", {}, t("nota final"))})));
    res += el("section", {"class": "cartao-config", "id": "selecao"}, el("h2", {}, t("Como a nota vira escolha"))
      + el("p", {}, t("Cada modo de rodízio tem a sua forma de escolher o relógio, escolhida no quadro {1}, na página Hoje:", {1: el("a", {"href": "index.php#bloco-modo"}, t("Modo de rodízio"))}))
      + Object.keys(SELECOES).map(function (k) { return el("p", {"class": "opcao-selecao"}, el("strong", {}, h(SELECOES[k][0])) + " " + el("span", {"class": "nota"}, h(SELECOES[k][1]))); }).join("")
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"}, el("thead", {}, el("tr", {}, el("th", {}, t("Modo de rodízio")) + el("th", {}, t("Forma de escolha"))))
        + el("tbody", {}, d.modos.map(function (m) {
          return el("tr", {}, el("td", {}, h(m.nome) + (m.ativo ? " " + el("span", {"class": "nota"}, t("(o modo atual)")) : ""))
            + el("td", {}, m.escala ? t("sempre pela maior nota, com as medidas de cada dia simulado") : h(SELECOES[m.selecao][0])));
        }).join(""))))
      + el("p", {"class": "nota"}, t("Garantia de rodízio: {1} (no mesmo quadro). Fora da nota também valem o grupo de cada bloco de dias, os relógios fixos e não repetir o de ontem.", {1: d.max_sem_uso > 0 ? t("nenhum relógio fica mais de {1} dias sem uso", {1: d.max_sem_uso}) : t("desligada")})));
    // onde há critérios
    var semConjunto = d.notas.filter(function (n) { return n.disponivel && n.conjunto === null; }).map(function (n) { return n.relogio; });
    res += el("section", {"class": "cartao-config", "id": "lugares"}, el("h2", {}, t("Onde há critérios")) + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"},
      el("thead", {}, el("tr", {}, el("th", {}, t("Lugar")) + el("th", {}, t("Parâmetros")) + el("th", {}, t("Usado por")) + el("th", {}, "")))
      + el("tbody", {}, d.conjuntos.map(function (c) {
        var u = usam(c.escopo);
        return el("tr", {}, el("td", {}, h(c.lugar)) + el("td", {}, String(c.parametros.length))
          + el("td", {}, el("span", {"title": u.join(", ")}, u.length + " " + (u.length === 1 ? t("relógio") : t("relógios"))) + (u.length > 0 ? " " + el("small", {"class": "nota"}, h(u.join(", "))) : ""))
          + el("td", {}, el("a", {"href": "criterios.php?escopo=" + encodeURIComponent(c.escopo) + "#conjunto"}, t("editar"))));
      }).join("") + (d.conjuntos.length === 0 ? el("tr", {}, el("td", {"colspan": "4"}, t("Nenhum lugar tem critérios: todos os relógios ficam com nota neutra, 50."))) : ""))))
      + (semConjunto.length > 0 ? el("p", {"class": "atrasado"}, t("Há relógios sem conjunto nenhum (nota neutra, 50): {1}.", {1: h(semConjunto.join(", "))})) : ""));
    // o conjunto do lugar em edição
    var conj = el("form", {"method": "get", "action": "criterios.php", "class": "escolhe-lugar"}, el("label", {"class": "campo-principal", "for": "escopo-lugar"}, t("Editar os critérios de"))
      + el("div", {"class": "linha-sorteio"}, el("select", {"name": "escopo", "id": "escopo-lugar", "onchange": "this.form.submit()"}, d.lugares.map(function (l) {
        return el("option", {"value": l.escopo, "selected": l.escopo === escopo}, h(l.texto) + (l.proprio ? t(" — com critérios próprios") : ""));
      }).join("")) + el("button", {"class": "leve"}, t("Abrir"))));
    if (proprio.length === 0) {
      conj += el("p", {}, t("{1} não tem critérios próprios{2}", {1: el("strong", {}, h(textoLugar.charAt(0).toUpperCase() + textoLugar.slice(1))), 2: lugarAtual.herda !== null ? t(": usa os de {1}.", {1: el("strong", {}, h(lugarAtual.herda_texto))}) : (escopo === "" ? "." : t(", e nenhum lugar acima tem: nota neutra, 50."))}))
        + el("div", {"class": "botoes"}, (lugarAtual.herda !== null ? el("form", {"data-recurso": "criterios"}, el("input", {"type": "hidden", "name": "acao", "value": "conjunto_criar"})
          + el("input", {"type": "hidden", "name": "escopo", "value": escopo}) + el("input", {"type": "hidden", "name": "origem", "value": "herdado"})
          + el("button", {}, t("Criar critérios próprios, copiando os de {1}", {1: h(lugarAtual.herda_texto)}))) : "")
          + el("form", {"data-recurso": "criterios"}, el("input", {"type": "hidden", "name": "acao", "value": "conjunto_criar"}) + el("input", {"type": "hidden", "name": "escopo", "value": escopo})
            + el("input", {"type": "hidden", "name": "origem", "value": "vazio"}) + el("button", {"class": "leve"}, t("Criar vazio"))));
    } else {
      var u = usam(escopo);
      var soma = proprio.reduce(function (s, p) { return s + p.peso; }, 0);
      conj += el("h2", {}, t("Critérios de {1}", {1: h(textoLugar)}))
        + el("p", {"class": "nota"}, t("Usado por {1} {2}{3}. Os lugares abaixo deste, sem critérios próprios, também usam estes.", {1: u.length, 2: u.length === 1 ? t("relógio") : t("relógios"), 3: u.length > 0 ? ": " + h(u.join(", ")) : ""}))
        + el("form", {"data-recurso": "criterios", "data-soma-form": true}, el("input", {"type": "hidden", "name": "acao", "value": "param_pesos"}) + el("input", {"type": "hidden", "name": "escopo", "value": escopo})
          + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"}, el("thead", {}, el("tr", {}, el("th", {}, t("Parâmetro")) + el("th", {}, t("Peso no conjunto")) + el("th", {}, t("Subparâmetros")) + el("th", {}, "")))
            + el("tbody", {}, proprio.map(function (p) {
              return el("tr", {}, el("td", {}, el("input", {"name": "nome[" + p.id + "]", "value": p.nome, "maxlength": "80"}))
                + el("td", {}, el("input", {"name": "peso[" + p.id + "]", "value": num(p.peso, 2), "class": "curto", "inputmode": "decimal", "data-soma": true}) + " %")
                + el("td", {}, el("a", {"href": "#p" + p.id}, p.subparametros.length + " " + (p.subparametros.length === 1 ? t("subparâmetro") : t("subparâmetros")))
                  + (p.subparametros.length === 0 ? " " + el("span", {"class": "atrasado"}, t("(fora da conta)")) : ""))
                + el("td", {"class": "acoes-linha"}, el("button", {"form": "ordem-p" + p.id, "name": "direcao", "value": "sobe", "class": "leve discreto", "type": "submit", "title": t("Subir")}, "↑")
                  + el("button", {"form": "ordem-p" + p.id, "name": "direcao", "value": "desce", "class": "leve discreto", "type": "submit", "title": t("Descer")}, "↓") + " "
                  + el("button", {"form": "excluir-p" + p.id, "class": "leve discreto", "type": "submit"}, t("Excluir"))));
            }).join("")) + el("tfoot", {}, el("tr", {}, el("td", {}, t("Soma")) + el("td", {"colspan": "3"}, el("span", {"data-soma-total": true}, pct(soma)) + " " + el("span", {"data-soma-aviso": true, "class": "nota"}, ""))))))
          + el("div", {"class": "botoes"}, el("button", {}, t("Salvar parâmetros"))));
      proprio.forEach(function (p) {
        var conta = contaExclusao(proprio, p.id);
        conj += el("form", {"data-recurso": "criterios", "id": "excluir-p" + p.id, "onsubmit": "return confirm(" + JSON.stringify(t("Excluir o parâmetro {1} ({2}) e os subparâmetros dele?\n\n{3}", {1: p.nome, 2: pct(p.peso), 3: conta.length > 0 ? t("O peso dele é dividido na proporção dos que ficam:\n{1}", {1: conta.join("\n")}) : t("Era o único: o lugar fica sem critérios próprios e volta a herdar os de cima.")})) + ")"},
          el("input", {"type": "hidden", "name": "acao", "value": "param_excluir"}) + el("input", {"type": "hidden", "name": "id", "value": p.id}))
          + el("form", {"data-recurso": "criterios", "id": "ordem-p" + p.id}, el("input", {"type": "hidden", "name": "acao", "value": "ordem"}) + el("input", {"type": "hidden", "name": "tipo", "value": "parametro"})
            + el("input", {"type": "hidden", "name": "id", "value": p.id}));
      });
      conj += el("form", {"data-recurso": "criterios", "class": "linha-inclusao"}, el("input", {"type": "hidden", "name": "acao", "value": "param_novo"}) + el("input", {"type": "hidden", "name": "escopo", "value": escopo})
        + el("label", {}, t("Novo parâmetro {1}", {1: el("input", {"name": "nome", "maxlength": "80", "required": true})})) + el("label", {}, t("Peso (%) {1}", {1: el("input", {"name": "peso", "class": "curto", "inputmode": "decimal", "required": true})}))
        + el("button", {}, t("Incluir")) + " " + el("span", {"class": "nota"}, t("Os outros deste conjunto abrem espaço na proporção de cada um.")))
        + el("form", {"data-recurso": "criterios", "class": "excluir-conjunto", "onsubmit": "return confirm(" + JSON.stringify(t("Excluir todos os critérios próprios de {1}?\n\n{2}", {1: textoLugar, 2: lugarAtual.herda !== null ? t("Passa a usar os de {1}.", {1: lugarAtual.herda_texto}) : t("Os relógios sem conjunto mais perto ficam com nota neutra, 50.")})) + ")"},
          el("input", {"type": "hidden", "name": "acao", "value": "conjunto_excluir"}) + el("input", {"type": "hidden", "name": "escopo", "value": escopo})
          + el("button", {"class": "leve discreto"}, t("Excluir os critérios próprios de {1}{2}", {1: h(textoLugar), 2: lugarAtual.herda !== null ? t(" (volta a usar os de {1})", {1: h(lugarAtual.herda_texto)}) : ""})));
    }
    res += el("section", {"class": "cartao-config", "id": "conjunto"}, conj);
    // cada parâmetro: os subparâmetros, as faixas de cada um, e incluir
    var todosParams = [];
    d.conjuntos.forEach(function (c) {
      c.parametros.forEach(function (p) {
        todosParams.push({id: p.id, nome: p.nome, lugar: c.lugar});
      });
    });
    proprio.forEach(function (p) {
      var sec = el("h2", {}, h(p.nome) + " " + el("span", {"class": "nota"}, t("{1} do conjunto de {2}", {1: pct(p.peso), 2: h(textoLugar)})));
      if (p.subparametros.length > 0) {
        var somaS = p.subparametros.reduce(function (s, x) { return s + x.peso; }, 0);
        sec += el("form", {"data-recurso": "criterios", "data-soma-form": true}, el("input", {"type": "hidden", "name": "acao", "value": "sub_pesos"}) + el("input", {"type": "hidden", "name": "parametro_id", "value": p.id})
          + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"}, el("thead", {}, el("tr", {}, el("th", {}, t("Subparâmetro")) + el("th", {}, t("Mede")) + el("th", {}, t("Peso no parâmetro"))
            + el("th", {}, t("Peso efetivo no conjunto")) + el("th", {}, ""))) + el("tbody", {}, p.subparametros.map(function (s) {
            return el("tr", {}, el("td", {}, el("input", {"name": "nome[" + s.id + "]", "value": s.nome, "maxlength": "80"}))
              + el("td", {"class": "nota"}, h(d.variaveis[s.variavel] ? d.variaveis[s.variavel].nome : s.variavel))
              + el("td", {}, el("input", {"name": "peso[" + s.id + "]", "value": num(s.peso, 2), "class": "curto", "inputmode": "decimal", "data-soma": true, "data-peso-pai": p.peso}) + " %")
              + el("td", {"data-efetivo": true}, pct(s.peso_efetivo_no_conjunto))
              + el("td", {"class": "acoes-linha"}, el("button", {"form": "ordem-s" + s.id, "name": "direcao", "value": "sobe", "class": "leve discreto", "type": "submit", "title": t("Subir")}, "↑")
                + el("button", {"form": "ordem-s" + s.id, "name": "direcao", "value": "desce", "class": "leve discreto", "type": "submit", "title": t("Descer")}, "↓") + " "
                + el("button", {"form": "excluir-s" + s.id, "class": "leve discreto", "type": "submit"}, t("Excluir"))));
          }).join("")) + el("tfoot", {}, el("tr", {}, el("td", {"colspan": "2"}, t("Soma dentro de {1}", {1: h(p.nome)})) + el("td", {"colspan": "3"}, el("span", {"data-soma-total": true}, pct(somaS)) + " "
            + el("span", {"data-soma-aviso": true, "class": "nota"}, ""))))))
          + el("div", {"class": "botoes"}, el("button", {}, t("Salvar subparâmetros"))));
        p.subparametros.forEach(function (s) {
          var conta = contaExclusao(p.subparametros, s.id);
          sec += el("form", {"data-recurso": "criterios", "id": "excluir-s" + s.id, "onsubmit": "return confirm(" + JSON.stringify(t("Excluir o subparâmetro {1} ({2} de {3})?\n\n{4}", {1: s.nome, 2: pct(s.peso), 3: p.nome, 4: conta.length > 0 ? t("O peso dele é dividido na proporção dos que ficam:\n{1}", {1: conta.join("\n")}) : t("O parâmetro fica sem subparâmetros e sai da conta até ganhar um.")})) + ")"},
            el("input", {"type": "hidden", "name": "acao", "value": "sub_excluir"}) + el("input", {"type": "hidden", "name": "id", "value": s.id}))
            + el("form", {"data-recurso": "criterios", "id": "ordem-s" + s.id}, el("input", {"type": "hidden", "name": "acao", "value": "ordem"}) + el("input", {"type": "hidden", "name": "tipo", "value": "sub"})
              + el("input", {"type": "hidden", "name": "id", "value": s.id}));
        });
        p.subparametros.forEach(function (s) {
          var m = d.variaveis[s.variavel] || {tipo: "numero", max: null, nome: s.variavel, valores: []};
          var linhas = s.faixas.map(function (f) {
            return {de: f.de === null ? "" : num(f.de, 4), ate: f.ate === null ? "" : num(f.ate, 4), categoria: f.categoria || "", nota: num(f.nota, 2)};
          });
          linhas.push({de: "", ate: "", categoria: "", nota: ""});
          var tabela = m.tipo === "categoria"
            ? el("thead", {}, el("tr", {}, el("th", {}, t("Categoria")) + el("th", {}, t("Nota")) + el("th", {}, t("Apagar")))) + el("tbody", {}, linhas.map(function (f, i) {
              return el("tr", {}, el("td", {}, el("select", {"name": "categoria[" + i + "]"}, el("option", {"value": ""}, "—") + m.valores.map(function (c) { return el("option", {"selected": f.categoria === c}, h(c)); }).join(""))
                  + el("input", {"type": "hidden", "name": "de[" + i + "]", "value": ""}) + el("input", {"type": "hidden", "name": "ate[" + i + "]", "value": ""}))
                + el("td", {}, el("input", {"name": "nota[" + i + "]", "value": f.nota, "class": "curto", "inputmode": "decimal"})) + el("td", {}, el("input", {"type": "checkbox", "name": "apagar[" + i + "]", "value": "1"})));
            }).join(""))
            : el("thead", {}, el("tr", {}, el("th", {}, t("De")) + el("th", {}, t("Até")) + el("th", {}, t("Nota")) + el("th", {}, t("Apagar")))) + el("tbody", {}, linhas.map(function (f, i) {
              return el("tr", {}, el("td", {}, el("input", {"name": "de[" + i + "]", "value": f.de, "class": "curto", "inputmode": "decimal"}) + el("input", {"type": "hidden", "name": "categoria[" + i + "]", "value": ""}))
                + el("td", {}, el("input", {"name": "ate[" + i + "]", "value": f.ate, "class": "curto", "inputmode": "decimal"})) + el("td", {}, el("input", {"name": "nota[" + i + "]", "value": f.nota, "class": "curto", "inputmode": "decimal"}))
                + el("td", {}, el("input", {"type": "checkbox", "name": "apagar[" + i + "]", "value": "1"})));
            }).join(""));
          sec += el("details", {"class": "faixas", "id": "s" + s.id, "open": abrirFaixa === String(s.id)}, el("summary", {}, t("Faixas de {1} {2}", {1: el("strong", {}, h(s.nome)), 2: el("span", {"class": "nota"}, s.faixas.length + " " + (s.faixas.length === 1 ? "faixa" : "faixas") + " · " + h(m.nome))}))
            + el("form", {"data-recurso": "criterios"}, el("input", {"type": "hidden", "name": "acao", "value": "faixas"}) + el("input", {"type": "hidden", "name": "sub_id", "value": s.id})
              + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-faixas", "data-faixas": true}, tabela))
              + el("p", {"class": "nota"}, t("{1} A linha em branco serve para incluir uma faixa; o \"+ faixa\" cria mais.", {1: m.tipo === "categoria" ? t("Uma nota por categoria; categoria sem faixa: o subparâmetro não se aplica ao relógio daquela categoria.")
                : t("Nota de 0 a 100. \"De\" entra na faixa, \"até\" não (a última inclui o seu \"até\"); \"até\" vazio = sem limite. A primeira começa em 0; cada uma começa onde a anterior terminou; a última vai até {1}.", {1: m.max === null ? t("\"sem limite\" (até vazio)") : t("{1} (ou sem limite)", {1: m.max})})}))
              + el("div", {"class": "botoes"}, el("button", {"type": "button", "class": "leve", "data-mais-faixa": true}, t("+ faixa")) + " " + el("button", {}, t("Salvar faixas"))))
            + el("div", {"class": "mais-opcoes"}, el("form", {"data-recurso": "criterios", "onsubmit": "return confirm(" + JSON.stringify(t("Trocar o que {1} mede? As faixas atuais serão apagadas, e ele recomeça com nota 50 em tudo.", {1: s.nome})) + ")"},
                el("input", {"type": "hidden", "name": "acao", "value": "sub_medida"}) + el("input", {"type": "hidden", "name": "id", "value": s.id})
                + el("label", {}, t("Trocar a medida para {1}", {1: el("select", {"name": "variavel"}, opcoesMedida(s.variavel))})) + " " + el("button", {"class": "leve"}, t("Trocar")))
              + el("form", {"data-recurso": "criterios"}, el("input", {"type": "hidden", "name": "acao", "value": "sub_mover"}) + el("input", {"type": "hidden", "name": "id", "value": s.id})
                + el("label", {}, t("Mover para o parâmetro {1}", {1: el("select", {"name": "parametro_id"}, todosParams.map(function (x) {
                  return el("option", {"value": x.id, "selected": x.id === p.id}, h(x.nome + " (" + x.lugar + ")"));
                }).join(""))})) + " " + el("button", {"class": "leve"}, t("Mover")))));
        });
      } else {
        sec += el("p", {"class": "atrasado"}, t("Sem subparâmetros: este parâmetro não entra na conta até ganhar um."));
      }
      sec += el("form", {"data-recurso": "criterios", "class": "linha-inclusao"}, el("input", {"type": "hidden", "name": "acao", "value": "sub_novo"}) + el("input", {"type": "hidden", "name": "parametro_id", "value": p.id})
        + el("label", {}, t("Novo subparâmetro {1}", {1: el("input", {"name": "nome", "maxlength": "80", "required": true})})) + el("label", {}, t("Mede {1}", {1: el("select", {"name": "variavel"}, opcoesMedida(""))}))
        + el("label", {}, t("Peso (%) {1}", {1: el("input", {"name": "peso", "class": "curto", "inputmode": "decimal", "required": true})})) + el("button", {}, t("Incluir")));
      res += el("section", {"class": "cartao-config", "id": "p" + p.id}, sec);
    });
    // a nota de cada relógio disponível agora, da maior para a menor
    var abrir = q.get("relogio") || "";
    res += el("section", {"class": "cartao-config", "id": "notas"}, el("h2", {}, t("A nota de cada relógio agora"))
      + el("p", {"class": "nota"}, t("Relógios disponíveis, da maior nota para a menor, cada um pelo conjunto de critérios que vale para ele. As regras que ficam fora da nota — o grupo de cada bloco de dias, os relógios fixos, não repetir o de ontem e a garantia de rodízio — valem na hora do sorteio."))
      + d.notas.filter(function (n) { return n.disponivel; }).sort(function (a, b) { return b.nota - a.nota; }).map(function (n) {
        var somaEf = n.conta.reduce(function (s, l) { return s + l.peso_efetivo; }, 0);
        return el("details", {"class": "conta-nota", "open": abrir === String(n.relogio_id), "id": "r" + n.relogio_id}, el("summary", {}, el("strong", {}, h(n.relogio)) + " "
            + el("span", {"class": "nota"}, t("{1} · critérios de {2}", {1: h(n.grupo), 2: h(n.conjunto_texto)})) + " " + el("span", {"class": "nota-final"}, num(n.nota, 1)))
          + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-conta"}, el("thead", {}, el("tr", {}, [t("Parâmetro"), t("Subparâmetro"), t("Valor medido"), t("Faixa"), t("Nota"), t("Puxa"), t("Peso efetivo"), t("Pontos")].map(function (t) {
            return el("th", {}, t);
          }).join(""))) + el("tbody", {}, n.conta.map(function (l) {
            // a seta: a nota acima de 50 (o neutro) puxa a nota final para cima, abaixo puxa para baixo; 50 em ponto, ou sem peso, é neutro
            // (a seta é um SVG cheio, igual em todo aparelho: para cima, para baixo ou para os dois lados)
            var setaSvg = function (d) { return '<svg viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><path d="' + d + '" fill="currentColor"/></svg>'; };
            var puxa = l.peso_efetivo > 0 && l.nota > 50 ? ["puxa-sobe", setaSvg("M8 1 L15 8.5 H11 V15 H5 V8.5 H1 Z"), t("puxa a nota para cima")]
              : (l.peso_efetivo > 0 && l.nota < 50 ? ["puxa-desce", setaSvg("M8 15 L1 7.5 H5 V1 H11 V7.5 H15 Z"), t("puxa a nota para baixo")]
                : ["puxa-neutro", setaSvg("M1 8 L5.5 3.5 V6 H10.5 V3.5 L15 8 L10.5 12.5 V10 H5.5 V12.5 Z"), t("neutro: não puxa para nenhum lado")]);
            return el("tr", {}, el("td", {}, h(l.parametro)) + el("td", {}, h(l.subparametro)) + el("td", {}, h(typeof l.valor === "number" ? num(l.valor, 1) : l.valor))
              + el("td", {}, h(l.faixa)) + el("td", {}, num(l.nota, 2)) + el("td", {"class": "puxa " + puxa[0], "title": puxa[2], "aria-label": puxa[2]}, puxa[1])
              + el("td", {}, pct(l.peso_efetivo)) + el("td", {}, Number(l.pontos).toFixed(2).replace(".", ",")));
          }).join("") + (n.conta.length === 0 ? el("tr", {}, el("td", {"colspan": "8"}, t("Nenhum critério com medida para este relógio: nota neutra, 50."))) : ""))
            + el("tfoot", {}, el("tr", {}, el("td", {"colspan": "6"}, t("Nota final (pesos efetivos somam {1})", {1: pct(somaEf)})) + el("td", {}, "") + el("td", {}, el("strong", {}, Number(n.nota).toFixed(2).replace(".", ",")))))))
          + el("p", {"class": "nota"}, el("a", {"href": "criterios.php?escopo=" + encodeURIComponent(n.conjunto || "") + "#conjunto"}, t("editar os critérios de {1}", {1: h(n.conjunto_texto)}))
            + " · " + el("a", {"href": "criterios.php?escopo=r:" + n.relogio_id + "#conjunto"}, t("critérios só deste relógio"))));
      }).join(""));
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Restaurar os critérios iniciais")) + el("form", {"data-recurso": "criterios", "onsubmit": "return confirm(" + JSON.stringify(t("Apagar todos os critérios de todos os lugares e voltar aos iniciais?")) + ")"},
      el("input", {"type": "hidden", "name": "acao", "value": "restaurar"})
      + el("p", {"class": "nota"}, t("Apaga os critérios de todos os lugares e volta aos que vieram com o sistema: um conjunto para todos os relógios e um para cada grupo principal. A forma de escolha e a garantia de rodízio não mudam."))
      + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, t("Restaurar")))));
    document.getElementById("criterios").innerHTML = res;
    // soma ao vivo dos pesos de cada formulário, com quanto falta ou quanto passa, e o peso efetivo de cada subparâmetro
    document.querySelectorAll("[data-soma-form]").forEach(function (form) {
      var atualiza = function () {
        var soma = 0;
        form.querySelectorAll("[data-soma]").forEach(function (campo) {
          var v = parseFloat(campo.value.replace(",", "."));
          soma += isNaN(v) ? 0 : v;
          var pai = campo.getAttribute("data-peso-pai");
          var efetivo = campo.closest("tr").querySelector("[data-efetivo]");
          if (pai && efetivo) {
            efetivo.textContent = (isNaN(v) ? 0 : Math.round(v * parseFloat(pai)) / 100).toString().replace(".", ",") + "%";
          }
        });
        soma = Math.round(soma * 100) / 100;
        form.querySelector("[data-soma-total]").textContent = soma.toString().replace(".", ",") + "%";
        var aviso = form.querySelector("[data-soma-aviso]");
        var dif = Math.round((soma - 100) * 100) / 100;
        aviso.textContent = dif === 0 ? t("fecha 100%") : (dif > 0 ? t("passa {1}%", {1: dif.toString().replace(".", ",")}) : t("faltam {1}%", {1: (-dif).toString().replace(".", ",")}));
        aviso.classList.toggle("atrasado", dif !== 0);
      };
      form.addEventListener("input", atualiza);
      atualiza();
    });
  });
}

// gravou: a página volta no lugar e na seção da resposta, com o recado; recusado: os valores digitados ficam, e os erros
// aparecem no topo
aoGravar = function (form, res, dados) {
  if (!res.ok) { return salvarSemMover(form, res, function () {}); }
  errosCrit = [];
  recado = "";
  escopo = res.escopo !== undefined ? res.escopo : escopo;
  abrirFaixa = dados.get("acao") === "faixas" ? String(dados.get("sub_id")) : "";
  history.replaceState(null, "", "criterios.php?escopo=" + encodeURIComponent(escopo) + (res.ancora ? "#" + res.ancora : ""));
  return salvarSemMover(form, res, recarregarCriterios);
};

recarregarCriterios().then(function () {
  if (window.location.hash) {
    var alvo = document.querySelector(window.location.hash);
    if (alvo) {
      alvo.scrollIntoView();
    }
  }
}).catch(function (e) {
  document.getElementById("criterios").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os critérios: {1}", {1: h(e.message)}));
});
