// Critérios de escolha: os dados da API (recurso=criterios), no HTML da página Critérios do sistema antigo. O lugar em
// edição fica no endereço (criterios.php?escopo=g:3), como antes; ?relogio=3 abre a conta da nota daquele relógio.
var SELECOES = {
  inteligente: ["Inteligente", "vai o relógio com a maior nota nos critérios"],
  ponderado: ["Inteligente com sorteio", "sorteio em que a nota de cada relógio é a chance dele: variedade sem perder o critério"],
  aleatorio: ["Totalmente aleatório", "todos com a mesma chance, sem olhar os critérios (gerador do sistema, random_int)"],
  fifo: ["Fila (FIFO)", "vai o que está há mais tempo sem uso: todos passam pelo pulso em rodízio"]
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
    var lugar = null;
    d.lugares.forEach(function (l) {
      if (l.escopo === escopo) {
        lugar = l;
      }
    });
    if (lugar === null) {
      escopo = "";
      lugar = d.lugares[0];
    }
    var textoLugar = lugar.lugar;
    var proprio = conjuntos[escopo] ? conjuntos[escopo].parametros : [];
    var usam = function (e) {
      return (conjuntos[e] ? conjuntos[e].usado_por : []).map(function (id) { return nomes[id]; });
    };
    var opcoesMedida = function (atual) {
      return Object.keys(d.variaveis).map(function (k) { return el("option", {"value": k, "selected": k === atual}, h(d.variaveis[k].nome)); }).join("");
    };
    var res = el("h1", {}, "Critérios de escolha do relógio");
    if (recado !== "") {
      res += el("p", {"class": "acao"}, h(recado));
    }
    if (errosCrit.length > 0) {
      res += el("div", {"class": "erros-form"}, el("strong", {}, "Não foi salvo:") + errosCrit.filter(function (e, i) { return errosCrit.indexOf(e) === i; }).map(function (e) { return el("p", {}, h(e)); }).join(""));
    }
    res += el("section", {"class": "cartao-config"}, el("h2", {}, "Como a nota funciona")
      + el("p", {}, "Cada " + el("strong", {}, "lugar") + " — todos os relógios, um grupo em qualquer nível da árvore, ou um relógio — pode ter o seu " + el("strong", {}, "conjunto de critérios") + ". "
        + "O relógio usa o conjunto " + el("strong", {}, "mais perto dele") + ", inteiro: o dele; senão o do grupo dele; senão o do grupo de cima; e assim até o de todos os relógios. Um lugar sem conjunto próprio herda o de cima.")
      + el("p", {}, "Num conjunto, cada " + el("strong", {}, "parâmetro") + " tem um peso, e a soma fecha 100%. Cada parâmetro tem " + el("strong", {}, "subparâmetros") + ", com peso dentro dele, que também fecha 100%. "
        + "Cada subparâmetro mede uma coisa do relógio e usa " + el("strong", {}, "faixas") + " para transformar o valor medido numa nota de 0 a 100. "
        + el("strong", {}, "Peso efetivo") + " = peso do subparâmetro × peso do parâmetro; " + el("strong", {}, "nota final") + " = soma de nota × peso efetivo. "
        + "Subparâmetro sem medida para o relógio (autonomia num relógio que não é smartwatch, por exemplo) sai da conta, e os pesos dos que ficam são reescalados."));
    res += el("section", {"class": "cartao-config", "id": "selecao"}, el("h2", {}, "Como a nota vira escolha")
      + el("p", {}, "Cada modo de rodízio tem a sua forma de escolher o relógio, escolhida no quadro " + el("a", {"href": "index.php#bloco-modo"}, "Modo de rodízio") + ", na página Hoje:")
      + Object.keys(SELECOES).map(function (k) { return el("p", {"class": "opcao-selecao"}, el("strong", {}, h(SELECOES[k][0])) + " " + el("span", {"class": "nota"}, h(SELECOES[k][1]))); }).join("")
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"}, el("thead", {}, el("tr", {}, el("th", {}, "Modo de rodízio") + el("th", {}, "Forma de escolha")))
        + el("tbody", {}, d.modos.map(function (m) {
          return el("tr", {}, el("td", {}, h(m.nome) + (m.ativo ? " " + el("span", {"class": "nota"}, "(o modo atual)") : ""))
            + el("td", {}, m.escala ? "sempre pela maior nota, com as medidas de cada dia simulado" : h(SELECOES[m.selecao][0])));
        }).join(""))))
      + el("p", {"class": "nota"}, "Garantia de rodízio: " + (d.max_sem_uso > 0 ? "nenhum relógio fica mais de " + d.max_sem_uso + " dias sem uso" : "desligada") + " (no mesmo quadro). "
        + "Fora da nota também valem o grupo de cada bloco de dias, os relógios fixos e não repetir o de ontem."));
    // onde há critérios
    var semConjunto = d.notas.filter(function (n) { return n.disponivel && n.conjunto === null; }).map(function (n) { return n.relogio; });
    res += el("section", {"class": "cartao-config", "id": "lugares"}, el("h2", {}, "Onde há critérios") + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"},
      el("thead", {}, el("tr", {}, el("th", {}, "Lugar") + el("th", {}, "Parâmetros") + el("th", {}, "Usado por") + el("th", {}, "")))
      + el("tbody", {}, d.conjuntos.map(function (c) {
        var u = usam(c.escopo);
        return el("tr", {}, el("td", {}, h(c.lugar)) + el("td", {}, String(c.parametros.length))
          + el("td", {}, el("span", {"title": u.join(", ")}, u.length + " " + (u.length === 1 ? "relógio" : "relógios")) + (u.length > 0 ? " " + el("small", {"class": "nota"}, h(u.join(", "))) : ""))
          + el("td", {}, el("a", {"href": "criterios.php?escopo=" + encodeURIComponent(c.escopo) + "#conjunto"}, "editar")));
      }).join("") + (d.conjuntos.length === 0 ? el("tr", {}, el("td", {"colspan": "4"}, "Nenhum lugar tem critérios: todos os relógios ficam com nota neutra, 50.")) : ""))))
      + (semConjunto.length > 0 ? el("p", {"class": "atrasado"}, "Há relógios sem conjunto nenhum (nota neutra, 50): " + h(semConjunto.join(", ")) + ".") : ""));
    // o conjunto do lugar em edição
    var conj = el("form", {"method": "get", "action": "criterios.php", "class": "escolhe-lugar"}, el("label", {"class": "campo-principal", "for": "escopo-lugar"}, "Editar os critérios de")
      + el("div", {"class": "linha-sorteio"}, el("select", {"name": "escopo", "id": "escopo-lugar", "onchange": "this.form.submit()"}, d.lugares.map(function (l) {
        return el("option", {"value": l.escopo, "selected": l.escopo === escopo}, h(l.texto) + (l.proprio ? " — com critérios próprios" : ""));
      }).join("")) + el("button", {"class": "leve"}, "Abrir")));
    if (proprio.length === 0) {
      conj += el("p", {}, el("strong", {}, h(textoLugar.charAt(0).toUpperCase() + textoLugar.slice(1))) + " não tem critérios próprios"
        + (lugar.herda !== null ? ": usa os de " + el("strong", {}, h(lugar.herda_texto)) + "." : (escopo === "" ? "." : ", e nenhum lugar acima tem: nota neutra, 50.")))
        + el("div", {"class": "botoes"}, (lugar.herda !== null ? el("form", {"data-recurso": "criterios"}, el("input", {"type": "hidden", "name": "acao", "value": "conjunto_criar"})
          + el("input", {"type": "hidden", "name": "escopo", "value": escopo}) + el("input", {"type": "hidden", "name": "origem", "value": "herdado"})
          + el("button", {}, "Criar critérios próprios, copiando os de " + h(lugar.herda_texto))) : "")
          + el("form", {"data-recurso": "criterios"}, el("input", {"type": "hidden", "name": "acao", "value": "conjunto_criar"}) + el("input", {"type": "hidden", "name": "escopo", "value": escopo})
            + el("input", {"type": "hidden", "name": "origem", "value": "vazio"}) + el("button", {"class": "leve"}, "Criar vazio")));
    } else {
      var u = usam(escopo);
      var soma = proprio.reduce(function (s, p) { return s + p.peso; }, 0);
      conj += el("h2", {}, "Critérios de " + h(textoLugar))
        + el("p", {"class": "nota"}, "Usado por " + u.length + " " + (u.length === 1 ? "relógio" : "relógios") + (u.length > 0 ? ": " + h(u.join(", ")) : "") + ". Os lugares abaixo deste, sem critérios próprios, também usam estes.")
        + el("form", {"data-recurso": "criterios", "data-soma-form": true}, el("input", {"type": "hidden", "name": "acao", "value": "param_pesos"}) + el("input", {"type": "hidden", "name": "escopo", "value": escopo})
          + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"}, el("thead", {}, el("tr", {}, el("th", {}, "Parâmetro") + el("th", {}, "Peso no conjunto") + el("th", {}, "Subparâmetros") + el("th", {}, "")))
            + el("tbody", {}, proprio.map(function (p) {
              return el("tr", {}, el("td", {}, el("input", {"name": "nome[" + p.id + "]", "value": p.nome, "maxlength": "80"}))
                + el("td", {}, el("input", {"name": "peso[" + p.id + "]", "value": num(p.peso, 2), "class": "curto", "inputmode": "decimal", "data-soma": true}) + " %")
                + el("td", {}, el("a", {"href": "#p" + p.id}, p.subparametros.length + " " + (p.subparametros.length === 1 ? "subparâmetro" : "subparâmetros"))
                  + (p.subparametros.length === 0 ? " " + el("span", {"class": "atrasado"}, "(fora da conta)") : ""))
                + el("td", {"class": "acoes-linha"}, el("button", {"form": "ordem-p" + p.id, "name": "direcao", "value": "sobe", "class": "leve discreto", "type": "submit", "title": "Subir"}, "↑")
                  + el("button", {"form": "ordem-p" + p.id, "name": "direcao", "value": "desce", "class": "leve discreto", "type": "submit", "title": "Descer"}, "↓") + " "
                  + el("button", {"form": "excluir-p" + p.id, "class": "leve discreto", "type": "submit"}, "Excluir")));
            }).join("")) + el("tfoot", {}, el("tr", {}, el("td", {}, "Soma") + el("td", {"colspan": "3"}, el("span", {"data-soma-total": true}, pct(soma)) + " " + el("span", {"data-soma-aviso": true, "class": "nota"}, ""))))))
          + el("div", {"class": "botoes"}, el("button", {}, "Salvar parâmetros")));
      proprio.forEach(function (p) {
        var conta = contaExclusao(proprio, p.id);
        conj += el("form", {"data-recurso": "criterios", "id": "excluir-p" + p.id, "onsubmit": "return confirm(" + JSON.stringify("Excluir o parâmetro " + p.nome + " (" + pct(p.peso) + ") e os subparâmetros dele?\n\n"
            + (conta.length > 0 ? "O peso dele é dividido na proporção dos que ficam:\n" + conta.join("\n") : "Era o único: o lugar fica sem critérios próprios e volta a herdar os de cima.")) + ")"},
          el("input", {"type": "hidden", "name": "acao", "value": "param_excluir"}) + el("input", {"type": "hidden", "name": "id", "value": p.id}))
          + el("form", {"data-recurso": "criterios", "id": "ordem-p" + p.id}, el("input", {"type": "hidden", "name": "acao", "value": "ordem"}) + el("input", {"type": "hidden", "name": "tipo", "value": "parametro"})
            + el("input", {"type": "hidden", "name": "id", "value": p.id}));
      });
      conj += el("form", {"data-recurso": "criterios", "class": "linha-inclusao"}, el("input", {"type": "hidden", "name": "acao", "value": "param_novo"}) + el("input", {"type": "hidden", "name": "escopo", "value": escopo})
        + el("label", {}, "Novo parâmetro " + el("input", {"name": "nome", "maxlength": "80", "required": true})) + el("label", {}, "Peso (%) " + el("input", {"name": "peso", "class": "curto", "inputmode": "decimal", "required": true}))
        + el("button", {}, "Incluir") + " " + el("span", {"class": "nota"}, "Os outros deste conjunto abrem espaço na proporção de cada um."))
        + el("form", {"data-recurso": "criterios", "class": "excluir-conjunto", "onsubmit": "return confirm(" + JSON.stringify("Excluir todos os critérios próprios de " + textoLugar + "?\n\n"
            + (lugar.herda !== null ? "Passa a usar os de " + lugar.herda_texto + "." : "Os relógios sem conjunto mais perto ficam com nota neutra, 50.")) + ")"},
          el("input", {"type": "hidden", "name": "acao", "value": "conjunto_excluir"}) + el("input", {"type": "hidden", "name": "escopo", "value": escopo})
          + el("button", {"class": "leve discreto"}, "Excluir os critérios próprios de " + h(textoLugar) + (lugar.herda !== null ? " (volta a usar os de " + h(lugar.herda_texto) + ")" : "")));
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
      var sec = el("h2", {}, h(p.nome) + " " + el("span", {"class": "nota"}, pct(p.peso) + " do conjunto de " + h(textoLugar)));
      if (p.subparametros.length > 0) {
        var somaS = p.subparametros.reduce(function (s, x) { return s + x.peso; }, 0);
        sec += el("form", {"data-recurso": "criterios", "data-soma-form": true}, el("input", {"type": "hidden", "name": "acao", "value": "sub_pesos"}) + el("input", {"type": "hidden", "name": "parametro_id", "value": p.id})
          + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-criterios"}, el("thead", {}, el("tr", {}, el("th", {}, "Subparâmetro") + el("th", {}, "Mede") + el("th", {}, "Peso no parâmetro")
            + el("th", {}, "Peso efetivo no conjunto") + el("th", {}, ""))) + el("tbody", {}, p.subparametros.map(function (s) {
            return el("tr", {}, el("td", {}, el("input", {"name": "nome[" + s.id + "]", "value": s.nome, "maxlength": "80"}))
              + el("td", {"class": "nota"}, h(d.variaveis[s.variavel] ? d.variaveis[s.variavel].nome : s.variavel))
              + el("td", {}, el("input", {"name": "peso[" + s.id + "]", "value": num(s.peso, 2), "class": "curto", "inputmode": "decimal", "data-soma": true, "data-peso-pai": p.peso}) + " %")
              + el("td", {"data-efetivo": true}, pct(s.peso * p.peso / 100))
              + el("td", {"class": "acoes-linha"}, el("button", {"form": "ordem-s" + s.id, "name": "direcao", "value": "sobe", "class": "leve discreto", "type": "submit", "title": "Subir"}, "↑")
                + el("button", {"form": "ordem-s" + s.id, "name": "direcao", "value": "desce", "class": "leve discreto", "type": "submit", "title": "Descer"}, "↓") + " "
                + el("button", {"form": "excluir-s" + s.id, "class": "leve discreto", "type": "submit"}, "Excluir")));
          }).join("")) + el("tfoot", {}, el("tr", {}, el("td", {"colspan": "2"}, "Soma dentro de " + h(p.nome)) + el("td", {"colspan": "3"}, el("span", {"data-soma-total": true}, pct(somaS)) + " "
            + el("span", {"data-soma-aviso": true, "class": "nota"}, ""))))))
          + el("div", {"class": "botoes"}, el("button", {}, "Salvar subparâmetros")));
        p.subparametros.forEach(function (s) {
          var conta = contaExclusao(p.subparametros, s.id);
          sec += el("form", {"data-recurso": "criterios", "id": "excluir-s" + s.id, "onsubmit": "return confirm(" + JSON.stringify("Excluir o subparâmetro " + s.nome + " (" + pct(s.peso) + " de " + p.nome + ")?\n\n"
              + (conta.length > 0 ? "O peso dele é dividido na proporção dos que ficam:\n" + conta.join("\n") : "O parâmetro fica sem subparâmetros e sai da conta até ganhar um.")) + ")"},
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
            ? el("thead", {}, el("tr", {}, el("th", {}, "Categoria") + el("th", {}, "Nota") + el("th", {}, "Apagar"))) + el("tbody", {}, linhas.map(function (f, i) {
              return el("tr", {}, el("td", {}, el("select", {"name": "categoria[" + i + "]"}, el("option", {"value": ""}, "—") + m.valores.map(function (c) { return el("option", {"selected": f.categoria === c}, h(c)); }).join(""))
                  + el("input", {"type": "hidden", "name": "de[" + i + "]", "value": ""}) + el("input", {"type": "hidden", "name": "ate[" + i + "]", "value": ""}))
                + el("td", {}, el("input", {"name": "nota[" + i + "]", "value": f.nota, "class": "curto", "inputmode": "decimal"})) + el("td", {}, el("input", {"type": "checkbox", "name": "apagar[" + i + "]", "value": "1"})));
            }).join(""))
            : el("thead", {}, el("tr", {}, el("th", {}, "De") + el("th", {}, "Até") + el("th", {}, "Nota") + el("th", {}, "Apagar"))) + el("tbody", {}, linhas.map(function (f, i) {
              return el("tr", {}, el("td", {}, el("input", {"name": "de[" + i + "]", "value": f.de, "class": "curto", "inputmode": "decimal"}) + el("input", {"type": "hidden", "name": "categoria[" + i + "]", "value": ""}))
                + el("td", {}, el("input", {"name": "ate[" + i + "]", "value": f.ate, "class": "curto", "inputmode": "decimal"})) + el("td", {}, el("input", {"name": "nota[" + i + "]", "value": f.nota, "class": "curto", "inputmode": "decimal"}))
                + el("td", {}, el("input", {"type": "checkbox", "name": "apagar[" + i + "]", "value": "1"})));
            }).join(""));
          sec += el("details", {"class": "faixas", "id": "s" + s.id, "open": abrirFaixa === String(s.id)}, el("summary", {}, "Faixas de " + el("strong", {}, h(s.nome)) + " "
              + el("span", {"class": "nota"}, s.faixas.length + " " + (s.faixas.length === 1 ? "faixa" : "faixas") + " · " + h(m.nome)))
            + el("form", {"data-recurso": "criterios"}, el("input", {"type": "hidden", "name": "acao", "value": "faixas"}) + el("input", {"type": "hidden", "name": "sub_id", "value": s.id})
              + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-faixas", "data-faixas": true}, tabela))
              + el("p", {"class": "nota"}, (m.tipo === "categoria" ? "Uma nota por categoria; categoria sem faixa: o subparâmetro não se aplica ao relógio daquela categoria."
                : "Nota de 0 a 100. \"De\" entra na faixa, \"até\" não (a última inclui o seu \"até\"); \"até\" vazio = sem limite. A primeira começa em 0; cada uma começa onde a anterior terminou; a última vai até "
                  + (m.max === null ? "\"sem limite\" (até vazio)" : m.max + " (ou sem limite)") + ".") + " A linha em branco serve para incluir uma faixa; o \"+ faixa\" cria mais.")
              + el("div", {"class": "botoes"}, el("button", {"type": "button", "class": "leve", "data-mais-faixa": true}, "+ faixa") + " " + el("button", {}, "Salvar faixas")))
            + el("div", {"class": "mais-opcoes"}, el("form", {"data-recurso": "criterios", "onsubmit": "return confirm(" + JSON.stringify("Trocar o que " + s.nome + " mede? As faixas atuais serão apagadas, e ele recomeça com nota 50 em tudo.") + ")"},
                el("input", {"type": "hidden", "name": "acao", "value": "sub_medida"}) + el("input", {"type": "hidden", "name": "id", "value": s.id})
                + el("label", {}, "Trocar a medida para " + el("select", {"name": "variavel"}, opcoesMedida(s.variavel))) + " " + el("button", {"class": "leve"}, "Trocar"))
              + el("form", {"data-recurso": "criterios"}, el("input", {"type": "hidden", "name": "acao", "value": "sub_mover"}) + el("input", {"type": "hidden", "name": "id", "value": s.id})
                + el("label", {}, "Mover para o parâmetro " + el("select", {"name": "parametro_id"}, todosParams.map(function (x) {
                  return el("option", {"value": x.id, "selected": x.id === p.id}, h(x.nome + " (" + x.lugar + ")"));
                }).join(""))) + " " + el("button", {"class": "leve"}, "Mover"))));
        });
      } else {
        sec += el("p", {"class": "atrasado"}, "Sem subparâmetros: este parâmetro não entra na conta até ganhar um.");
      }
      sec += el("form", {"data-recurso": "criterios", "class": "linha-inclusao"}, el("input", {"type": "hidden", "name": "acao", "value": "sub_novo"}) + el("input", {"type": "hidden", "name": "parametro_id", "value": p.id})
        + el("label", {}, "Novo subparâmetro " + el("input", {"name": "nome", "maxlength": "80", "required": true})) + el("label", {}, "Mede " + el("select", {"name": "variavel"}, opcoesMedida("")))
        + el("label", {}, "Peso (%) " + el("input", {"name": "peso", "class": "curto", "inputmode": "decimal", "required": true})) + el("button", {}, "Incluir"));
      res += el("section", {"class": "cartao-config", "id": "p" + p.id}, sec);
    });
    // a nota de cada relógio disponível agora, da maior para a menor
    var abrir = q.get("relogio") || "";
    res += el("section", {"class": "cartao-config", "id": "notas"}, el("h2", {}, "A nota de cada relógio agora")
      + el("p", {"class": "nota"}, "Relógios disponíveis, da maior nota para a menor, cada um pelo conjunto de critérios que vale para ele. As regras que ficam fora da nota "
        + "— o grupo de cada bloco de dias, os relógios fixos, não repetir o de ontem e a garantia de rodízio — valem na hora do sorteio.")
      + d.notas.filter(function (n) { return n.disponivel; }).sort(function (a, b) { return b.nota - a.nota; }).map(function (n) {
        var somaEf = n.conta.reduce(function (s, l) { return s + l.peso_efetivo; }, 0);
        return el("details", {"class": "conta-nota", "open": abrir === String(n.relogio_id), "id": "r" + n.relogio_id}, el("summary", {}, el("strong", {}, h(n.relogio)) + " "
            + el("span", {"class": "nota"}, h(n.grupo) + " · critérios de " + h(n.conjunto_texto)) + " " + el("span", {"class": "nota-final"}, num(n.nota, 1)))
          + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-conta"}, el("thead", {}, el("tr", {}, ["Parâmetro", "Subparâmetro", "Valor medido", "Faixa", "Nota", "Peso efetivo", "Pontos"].map(function (t) {
            return el("th", {}, t);
          }).join(""))) + el("tbody", {}, n.conta.map(function (l) {
            return el("tr", {}, el("td", {}, h(l.parametro)) + el("td", {}, h(l.subparametro)) + el("td", {}, h(typeof l.valor === "number" ? num(l.valor, 1) : l.valor))
              + el("td", {}, h(l.faixa)) + el("td", {}, num(l.nota, 2)) + el("td", {}, pct(l.peso_efetivo)) + el("td", {}, Number(l.pontos).toFixed(2).replace(".", ",")));
          }).join("") + (n.conta.length === 0 ? el("tr", {}, el("td", {"colspan": "7"}, "Nenhum critério com medida para este relógio: nota neutra, 50.")) : ""))
            + el("tfoot", {}, el("tr", {}, el("td", {"colspan": "5"}, "Nota final (pesos efetivos somam " + pct(somaEf) + ")") + el("td", {}, "") + el("td", {}, el("strong", {}, Number(n.nota).toFixed(2).replace(".", ",")))))))
          + el("p", {"class": "nota"}, el("a", {"href": "criterios.php?escopo=" + encodeURIComponent(n.conjunto || "") + "#conjunto"}, "editar os critérios de " + h(n.conjunto_texto))
            + " · " + el("a", {"href": "criterios.php?escopo=r:" + n.relogio_id + "#conjunto"}, "critérios só deste relógio")));
      }).join(""));
    res += el("section", {"class": "cartao-config"}, el("h2", {}, "Restaurar os critérios iniciais") + el("form", {"data-recurso": "criterios", "onsubmit": "return confirm('Apagar todos os critérios de todos os lugares e voltar aos iniciais?')"},
      el("input", {"type": "hidden", "name": "acao", "value": "restaurar"})
      + el("p", {"class": "nota"}, "Apaga os critérios de todos os lugares e volta aos que vieram com o sistema: um conjunto para todos os relógios e um para cada grupo principal. A forma de escolha e a garantia de rodízio não mudam.")
      + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, "Restaurar"))));
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
        aviso.textContent = dif === 0 ? "fecha 100%" : (dif > 0 ? "passa " + dif.toString().replace(".", ",") + "%" : "faltam " + (-dif).toString().replace(".", ",") + "%");
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
  if (res.ok) {
    errosCrit = [];
    recado = res.mensagem;
    escopo = res.escopo !== undefined ? res.escopo : escopo;
    abrirFaixa = dados.get("acao") === "faixas" ? String(dados.get("sub_id")) : "";
    history.replaceState(null, "", "criterios.php?escopo=" + encodeURIComponent(escopo) + (res.ancora ? "#" + res.ancora : ""));
    recarregarCriterios().then(function () {
      var alvo = res.ancora ? document.getElementById(res.ancora) : null;
      if (alvo) {
        alvo.scrollIntoView();
      } else {
        window.scrollTo(0, 0);
      }
    });
  } else {
    errosCrit = res.erros;
    form.querySelectorAll("button").forEach(function (b) {
      b.disabled = false;
    });
    var topo = document.querySelector("#criterios .erros-form");
    var html = el("strong", {}, "Não foi salvo:") + res.erros.map(function (e) { return el("p", {}, h(e)); }).join("");
    if (topo) {
      topo.innerHTML = html;
    } else {
      document.querySelector("#criterios h1").insertAdjacentHTML("afterend", el("div", {"class": "erros-form"}, html));
    }
    window.scrollTo(0, 0);
  }
};

recarregarCriterios().then(function () {
  if (window.location.hash) {
    var alvo = document.querySelector(window.location.hash);
    if (alvo) {
      alvo.scrollIntoView();
    }
  }
}).catch(function (e) {
  document.getElementById("criterios").innerHTML = el("p", {"class": "acao"}, "Não consegui ler os critérios: " + h(e.message));
});
