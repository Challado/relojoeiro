// Página Hoje: o relógio do dia, os avisos de hoje, os próximos dias, o modo de rodízio e a tabela dos relógios, com os
// dados da API (recurso=hoje), no HTML do sistema antigo. Remontada depois de cada gravação, sem recarregar a página.
var DIAS_SEMANA = ["", "Segunda", "Terça", "Quarta", "Quinta", "Sexta", "Sábado", "Domingo"];
var SELECOES = {
  inteligente: ["Inteligente", "Vai o relógio com a maior nota nos critérios. Previsível: com os mesmos dados, a mesma escolha."],
  ponderado: ["Inteligente com sorteio", "Sorteio em que a nota de cada relógio é a chance dele: quem tem nota maior sai mais vezes, mas todos têm chance. Variedade sem perder o critério."],
  aleatorio: ["Totalmente aleatório", "Todos os relógios do tipo têm a mesma chance, sem olhar os critérios (gerador do sistema, random_int)."],
  fifo: ["Fila (FIFO)", "Vai o que está há mais tempo sem uso: todos passam pelo pulso, em fila. Não olha os critérios, e já garante o rodízio por natureza."]
};

// o dia da semana (1 a 7) de uma data AAAA-MM-DD
function diaDaSemana(data) {
  return new Date(instante(data)).getDay() || 7;
}

// o recado de uma gravação que não é de um relógio: no topo do meio da página
function mostrarRecado(texto) {
  var p = document.getElementById("recado-hoje");
  p.textContent = texto;
  p.hidden = texto.trim() === "";
}

// lê a página inteira da API e monta os blocos
function recarregarHoje() {
  return api({recurso: "hoje"}).then(function (d) {
    // o quadro "Hoje": o relógio do dia, até quando, o tipo, o lembrete, e ao lado as informações dele e a foto
    var t = null;
    d.relogios.forEach(function (x) {
      if (x.de_hoje) {
        t = x;
      }
    });
    var texto = el("p", {"class": "data"}, DIAS_SEMANA[diaDaSemana(d.data)] + ", " + dataBr(d.data) + " · " + h(d.modo ? d.modo.nome : "sem modo"));
    if (d.dia) {
      texto += el("h1", {}, el("a", {"href": "index.php?r=" + d.dia.relogio_id, "data-abrir": d.dia.relogio_id}, h(d.dia.relogio)))
        + el("p", {"class": "periodo"}, el("span", {"class": "janela"}, h(d.dia.ate)) + " " + h(t ? t.tipo : ""))
        + (d.dia.acao ? el("p", {"class": "acao"}, h(d.dia.acao)) : "")
        + (d.dia.motivo ? el("p", {"class": "motivo"}, el("strong", {}, "Por que ele: ") + h(d.dia.motivo)) : "");
    } else {
      texto += el("h1", {}, "Nenhum relógio para hoje")
        + el("p", {}, "Nenhum relógio disponível no grupo deste dia. Mude o grupo no modo de rodízio ou marque um relógio como disponível.");
    }
    var res = el("div", {"class": "hoje-texto"}, texto);
    if (t) {
      // o Pôs e o Tirou do relógio do dia (as marcações mandam, com ou sem o pulso sozinho da Configuração)
      var marcar = " " + el("form", {"data-recurso": "lancamento", "class": "marcar-pulso"},
        el("input", {"type": "hidden", "name": "acao", "value": t.em_uso ? "encerrar" : "iniciar"}) + el("input", {"type": "hidden", "name": "tipo", "value": "pulso"})
        + el("input", {"type": "hidden", "name": "relogio_id", "value": t.id}) + el("input", {"type": "hidden", "name": "r", "value": t.id})
        + el("button", {"class": "leve"}, t.em_uso ? "Tirou do pulso" : "Pôs no pulso"));
      var info = el("dt", {}, "Agora") + el("dd", {}, el("span", {"class": "estado " + (t.em_uso ? "estado-uso" : "estado-repouso")}, h(t.agora)) + marcar)
        + el("dt", {}, "Carga") + el("dd", {}, t.carga !== null
          ? el("span", {"class": "carga-linha" + (t.carga <= 20 ? " baixa" : "")}, el("span", {"class": "barra"}, el("span", {"style": "width: " + t.carga + "%"}, "")) + " " + el("strong", {}, t.carga + "%"))
            + " " + el("small", {}, h(t.carga_de))
          : h(t.carga_de));
      if (t.leitura) {
        info += el("dt", {}, "Última leitura") + el("dd", {}, num(t.leitura.valor, 1) + h(t.leitura.unidade) + " em " + dataBr(t.leitura.inicio, true) + " " + horaBr(t.leitura.inicio));
      }
      if (t.situacao.length > 0) {
        info += el("dt", {}, "Situação") + el("dd", {}, t.situacao.map(function (l) { return el("span", {"class": "linha"}, h(l)); }).join(""));
      }
      info += el("dt", {}, "Próxima manutenção") + el("dd", {}, t.manutencao
        ? h(t.manutencao.nome) + " · " + (t.manutencao.data === d.data ? "hoje" : dataBr(t.manutencao.data)) + " "
          + el("span", {"class": "em" + (t.manutencao.momento > 0 && t.manutencao.momento * 1000 <= Date.now() ? " atrasado" : "")}, "(" + h(t.manutencao.falta) + ")")
        : "nenhuma prevista");
      if (t.compra.valor !== null || t.compra.data !== null) {
        info += el("dt", {}, "Compra") + el("dd", {}, (t.compra.valor !== null ? "R$ " + reais(t.compra.valor) : "") + (t.compra.loja !== null ? " · " + h(t.compra.loja) : "")
          + (t.compra.data !== null ? " · " + dataBr(t.compra.data) : ""));
      }
      if (t.compra.garantia_ate !== null) {
        info += el("dt", {}, "Garantia") + el("dd", {}, "até " + dataBr(t.compra.garantia_ate) + (t.compra.garantia_ate < d.data ? " (vencida)" : ""));
      }
      info += el("dt", {}, "Código") + el("dd", {}, String(t.id));
      res += el("dl", {"class": "hoje-info"}, info)
        + el("a", {"class": "hoje-foto", "href": "index.php?r=" + t.id, "data-abrir": t.id, "title": "Abrir o " + t.nome + " no painel"},
          t.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + t.id + "&v=" + t.foto, "alt": "Foto do " + t.nome}) : el("span", {"class": "sem-foto"}, "Sem foto"));
    }
    var quadro = document.getElementById("bloco-hoje");
    quadro.classList.toggle("com-relogio", t !== null);
    quadro.innerHTML = res;

    // "Hoje é dia de": uma tabela, como a dos próximos dias; cada aviso com o relógio (abre o painel), o motivo e o botão do
    // lançamento que o resolve (leitura: com o valor; sessão: "Pôs ...")
    var res = "";
    if (d.avisos.length > 0) {
      res = el("section", {"class": "avisos"}, el("h2", {}, "Hoje é dia de") + el("table", {"class": "relogios"}, el("thead", {}, el("tr", {}, el("th", {}, "O que fazer")
        + el("th", {}, "Relógio") + el("th", {}, "Por quê") + el("th", {}, ""))) + el("tbody", {}, d.avisos.map(function (a) {
        var botao = "";
        if (a.resolve) {
          var acao = a.resolve.formato === "sessao" ? "iniciar" : "lancar";
          var rotulo = a.resolve.formato === "sessao" ? "Pôs " + minusculo(a.resolve.nome) : (a.resolve.formato === "valor" ? "Informar " + minusculo(a.resolve.nome) : a.resolve.nome);
          botao = el("form", {"data-recurso": "lancamento"}, el("input", {"type": "hidden", "name": "acao", "value": acao}) + el("input", {"type": "hidden", "name": "tipo", "value": a.resolve.identificador})
            + el("input", {"type": "hidden", "name": "relogio_id", "value": a.relogio_id}) + el("input", {"type": "hidden", "name": "r", "value": a.relogio_id})
            + (a.resolve.formato === "valor" ? el("input", {"type": "text", "inputmode": "decimal", "name": "valor", "placeholder": a.resolve.unidade, "required": true, "aria-label": a.resolve.nome}) : "")
            + el("button", {"class": "leve"}, h(rotulo)));
        }
        return el("tr", {"class": "aviso-" + a.identificador + (a.estado === "atrasado" ? " aviso-atrasado" : "")}, el("td", {}, el("span", {}, h(a.nome)))
          + el("td", {}, el("a", {"href": "index.php?r=" + a.relogio_id, "data-abrir": a.relogio_id}, h(a.relogio))) + el("td", {}, el("span", {}, h(a.texto)))
          + el("td", {"class": "botao-aviso"}, botao));
      }).join(""))));
    }
    document.getElementById("bloco-avisos").innerHTML = res;

    // "Próximos dias": o plano, com o lembrete de cada dia e, embaixo do relógio, o motivo da escolha
    // cada dia com o "trocar por…" (o relógio escolhido à mão fica marcado), e o link para o plano inteiro
    document.getElementById("bloco-proximos").innerHTML = el("h2", {}, "Próximos dias " + el("a", {"class": "ver-plano", "href": "plano.php"}, "ver o plano inteiro →"))
      + el("table", {"class": "relogios"}, el("thead", {}, el("tr", {}, el("th", {}, "Dia") + el("th", {}, "Relógio") + el("th", {}, "Lembrete") + el("th", {}, "")))
      + el("tbody", {}, d.plano.map(function (p, i) {
        var dia = diaDaSemana(p.data);
        return el("tr", {"class": ((dia >= 6 ? "fds" : "") + (i >= 7 ? " dia-alem" : "")).trim()}, el("td", {}, dataBr(p.data, true) + " " + el("small", {}, CURTO[dia]))
          + el("td", {}, h(p.relogio) + (p.origem === "manual" ? " " + el("small", {"class": "a-mao"}, "à mão") : "")
            + (p.motivo ? el("small", {"class": "motivo"}, h(p.motivo)) : "")) + el("td", {}, h(p.acao || ""))
          + el("td", {"class": "trocar"}, formTrocarDia(p, d.relogios, d.data)));
      }).join("") + (d.plano.length === 0 ? el("tr", {}, el("td", {"colspan": "4"}, "Neste modo o sorteio é feito dia a dia.")) : "")))
      // no celular a lista começa com a primeira semana (estilo.css), e este botão mostra os outros dias
      + (d.plano.length > 7 ? el("button", {"type": "button", "class": "leve ver-mais-dias", "data-ver-dias": true}, "Mostrar os " + d.plano.length + " dias") : "");

    // O quadro "Modo de rodízio": fechado mostra o modo atual; aberto, edita (os blocos de cada modo, a forma de escolha, a
    // garantia de rodízio) e sorteia de novo
    var modo = d.modo;
    var escala = modo !== null && modo.escala_dias !== null;
    var nomeHoje = d.comecou && d.dia ? d.dia.relogio : "";
    var selModos = {};
    var escalas = [];
    d.modos.forEach(function (m) {
      selModos[m.id] = m.selecao;
      if (m.escala_dias !== null) {
        escalas.push(m.id);
      }
    });
    // as opções de cada bloco: sortear entre os de um grupo (ou todos), ou um relógio fixo
    var opcoesBloco = function (b) {
      var atual = b.relogio_id !== null ? "r:" + b.relogio_id : String(b.no_id);
      return el("optgroup", {"label": "Sortear entre"}, el("option", {"value": "0", "selected": atual === "0"}, "Todos") + d.grupos.map(function (g) {
        return el("option", {"value": g.id, "selected": atual === String(g.id)}, "\u00a0\u00a0".repeat(g.profundidade) + h(g.nome));
      }).join("")) + el("optgroup", {"label": "Relógio fixo"}, d.relogios.filter(function (r) { return r.disponivel || atual === "r:" + r.id; }).map(function (r) {
        return el("option", {"value": "r:" + r.id, "selected": atual === "r:" + r.id}, h(r.nome) + (r.disponivel ? "" : " (indisponível: o dia sorteia outro do mesmo grupo)"));
      }).join(""));
    };
    var ladoModo = el("label", {"class": "campo-principal"}, "Modo de rodízio " + el("select", {"name": "modo"}, d.modos.map(function (m) {
      return el("option", {"value": m.id, "selected": modo !== null && m.id === modo.id}, h(m.nome));
    }).join("")));
    d.modos.forEach(function (m) {
      ladoModo += el("p", {"class": "nota explicacao", "data-explica-modo": m.id}, h(m.escala_dias !== null
        ? "Monta o plano do período inteiro de uma vez, simulando a carga de cada relógio dia a dia, com os lembretes de carregar, dar corda e pôr no sol. É refeita toda manhã a partir do estado real."
        : "Cada bloco de dias sorteia entre os relógios do grupo escolhido, ou usa o relógio fixo; marcando \"sortear um por dia\", cada dia do bloco tem o seu sorteio. O plano vem montado até domingo; a semana seguinte é sorteada quando começa."));
    });
    d.modos.forEach(function (m) {
      var campos = "";
      if (m.escala_dias !== null) {
        campos += el("label", {}, "Período " + el("select", {"name": "escala_dias"}, [[7, "Uma semana"], [30, "Um mês"], [60, "Dois meses"], [180, "Meio ano"], [365, "Um ano"], [730, "Dois anos"]].map(function (o) {
          return el("option", {"value": o[0], "selected": m.escala_dias === o[0]}, o[1]);
        }).join("")));
      }
      m.blocos.forEach(function (b) {
        campos += el("div", {"class": "bloco-5x2"}, el("label", {}, h(b.nome) + " " + el("select", {"name": "bloco_alvo[" + b.id + "]"}, opcoesBloco(b)))
          + (m.escala_dias === null ? el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "bloco_cada_dia[" + b.id + "]", "checked": b.um_por === "dia"}) + " sortear um por dia") : ""));
      });
      ladoModo += el("fieldset", {"data-modo": m.id}, campos);
    });
    var ladoSelecao = el("label", {"class": "campo-principal", "data-com-selecao": true}, "Como escolher o relógio " + el("select", {"name": "selecao"}, Object.keys(SELECOES).map(function (k) {
      return el("option", {"value": k, "selected": modo !== null && modo.selecao === k}, h(SELECOES[k][0]));
    }).join("")))
      + el("p", {"class": "campo-principal", "data-sem-selecao": true}, "Como escolher o relógio: " + el("strong", {}, "pela maior nota"))
      + Object.keys(SELECOES).map(function (k) { return el("p", {"class": "nota explicacao", "data-explica-selecao": k}, h(SELECOES[k][1])); }).join("")
      + el("p", {"class": "nota explicacao", "data-sem-selecao": true}, "A escala escolhe sempre pela maior nota nos critérios, com as medidas de cada dia simulado.")
      + el("div", {"data-garantia": true}, el("label", {"class": "campo-principal"}, "Garantia de rodízio: dias sem uso, no máximo "
        + el("input", {"type": "number", "name": "max_sem_uso", "min": "0", "max": "365", "value": d.max_sem_uso, "class": "curto"}))
        + el("p", {"class": "nota"}, "Quem passa do limite tem prioridade, o mais tempo parado primeiro. 0 desliga. O mesmo valor vale para todos os modos que usam a nota.")
        + el("p", {"class": "nota"}, "A nota vem dos " + el("a", {"href": "criterios.php"}, "critérios") + ", que você cadastra."));
    var formModo = el("form", {"data-recurso": "rodizio", "onsubmit": "return confirm(" + JSON.stringify(d.comecou ? "Trocar o modo refaz o plano a partir de amanhã; hoje continua o "
        + nomeHoje + ". Continuar?" : "Trocar o modo apaga o plano atual. Continuar?") + ")"},
      el("input", {"type": "hidden", "name": "acao", "value": "modo"})
      + el("div", {"class": "modo-grade", "data-selecoes": JSON.stringify(selModos), "data-escalas": JSON.stringify(escalas)}, el("div", {"class": "modo-lado"}, ladoModo) + el("div", {"class": "modo-lado"}, ladoSelecao))
      + el("button", {}, "Aplicar modo")
      + el("p", {"class": "nota"}, "Trocar o modo apaga o plano atual e gera outro" + (d.comecou ? " a partir de amanhã: hoje continua o " + h(nomeHoje)
        + ", que já está no pulso (para trocar hoje, use \"Usando hoje\")" : " a partir de hoje") + ". O histórico de uso, corda, sol e carga continua. Os modos e os blocos de dias se cadastram em "
        + el("a", {"href": "cadastros.php?aba=modos"}, "Cadastros") + "."));
    // sortear de novo: as ações possíveis agora, com a explicação e a confirmação de cada uma
    var ateQuando = escala ? "o fim da escala" : "domingo";
    var sorteios = [];
    if (d.comecou) {
      sorteios.push(["resortear", "a partir de amanhã", "Refaz o plano de amanhã até " + ateQuando + " pelo modo atual; hoje continua o " + nomeHoje + ".",
        "Sortear de novo a partir de amanhã? Hoje continua o " + nomeHoje + ".", true]);
      sorteios.push(["resortear_hoje", "inclusive hoje", "Refaz também hoje: se sair outro relógio, ele passa a ser o do pulso a partir de agora, e o " + nomeHoje + " sai.",
        "Sortear de novo inclusive hoje? Se sair outro relógio para hoje, ele passa a ser o do pulso a partir de agora, e o " + nomeHoje + " sai.", true]);
    } else {
      sorteios.push(["resortear", "a partir de hoje", "Refaz o plano de hoje até " + ateQuando + " pelo modo atual (o dia ainda não começou no pulso).", "Sortear de novo a partir de hoje?", true]);
    }
    if (!escala) {
      var ps = d.proxima_semana;
      var domingo = diaDaSemana(d.data) === 7;
      sorteios.push(["proxima_semana", "a próxima semana (" + dataBr(ps.segunda, true) + " a " + dataBr(ps.domingo, true) + ")" + (domingo ? "" : " — disponível no domingo"),
        (ps.ja_montada ? "Refaz" : "Monta") + " a semana seguinte inteira, de segunda a domingo, pelo modo atual. Só no domingo.",
        (ps.ja_montada ? "Sortear de novo" : "Montar") + " a semana de " + dataBr(ps.segunda, true) + " a " + dataBr(ps.domingo, true) + "?" + (ps.ja_montada ? " O que já estava montado para ela é refeito." : ""), domingo]);
    }
    var formSorteio = el("form", {"data-recurso": "rodizio", "class": "sortear-de-novo", "onsubmit": "var o = this.acao.options[this.acao.selectedIndex]; return confirm(o.getAttribute('data-confirma'))"},
      el("label", {"class": "campo-principal", "for": "acao-sorteio"}, "Sortear de novo")
      + el("div", {"class": "linha-sorteio"}, el("select", {"name": "acao", "id": "acao-sorteio"}, sorteios.map(function (s) {
        return el("option", {"value": s[0], "disabled": !s[4], "data-explica": s[2], "data-confirma": s[3]}, h(s[1]));
      }).join("")) + el("button", {"class": "leve"}, "Sortear"))
      + el("p", {"class": "nota", "data-explica-sorteio": true}, h(sorteios[0][2])));
    // remontado, o quadro volta fechado, como no sistema antigo depois de aplicar
    document.getElementById("bloco-modo").innerHTML = el("details", {}, el("summary", {}, el("span", {"class": "rotulo"}, "Modo de rodízio") + " " + el("strong", {}, h(modo ? modo.nome : "nenhum")) + " "
      + el("span", {"class": "nota"}, "· " + (escala ? "pela maior nota" : h(modo ? SELECOES[modo.selecao][0] : ""))) + " " + el("span", {"class": "editar"}, "editar")) + formModo + formSorteio);
    mostrarModo();

    // A tabela dos relógios: disponíveis primeiro (o de hoje, depois os com aviso), indisponíveis no fim; na tela, a ordem e os
    // filtros escolhidos no cabeçalho valem por cima desta (tabela.js)
    var sel = parseInt(new URLSearchParams(window.location.search).get("r") || "0", 10);
    var ordem = function (x) {
      return (x.disponivel ? 10 : 0) + (x.de_hoje ? 2 : 0) + (x.com_aviso ? 1 : 0);
    };
    var lista = d.relogios.slice().sort(function (a, b) { return ordem(b) - ordem(a); });
    // os filtros de tipo e do que fazer, com as opções que existem
    var tipos = [];
    lista.forEach(function (x) {
      if (tipos.indexOf(x.tipo) < 0) {
        tipos.push(x.tipo);
      }
    });
    tipos.sort();
    document.querySelector("[data-filtro=tipo]").innerHTML = el("option", {"value": ""}, "Todos") + tipos.map(function (t) { return el("option", {}, h(t)); }).join("");
    document.querySelector("[data-filtro=fazer]").innerHTML = el("option", {"value": ""}, "Todos") + d.avisos_nomes.map(function (t) { return el("option", {}, h(t)); }).join("");
    var ano = String(new Date().getFullYear());
    var linhas = lista.map(function (x) {
      var estadoOrdem = !x.disponivel ? 2 : (x.em_uso ? 0 : 1);
      var estadoTxt = !x.disponivel ? "Indisponível" : (x.em_uso ? "Em uso" : "Em repouso");
      var m = x.manutencao;
      var atrasado = m && m.momento > 0 && m.momento * 1000 <= Date.now();
      return el("tr", {"data-id": x.id, "tabindex": "0", "data-codigo": x.id, "data-nome": x.nome, "data-tipo": x.tipo, "data-estado": estadoOrdem, "data-estado-txt": estadoTxt,
        "data-carga": x.carga === null ? "" : x.carga, "data-ultimo": x.ultimo, "data-manut": m ? m.data : "", "data-em": m && m.momento > 0 ? m.momento : "", "data-fazer": m ? m.nome : "",
        "data-compra": x.compra.data || "", "data-valor": x.compra.valor === null ? "" : x.compra.valor,
        "class": ((x.de_hoje ? "de-hoje " : "") + (x.com_aviso ? "pendente " : "") + (x.disponivel ? "" : "indisponivel ") + (x.id === sel ? "selecionado" : "")).trim()},
        el("td", {"class": "col-codigo"}, String(x.id))
        + el("td", {}, el("div", {"class": "nome-rel"}, (x.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + x.id + "&v=" + x.foto, "alt": ""}) : el("span", {"class": "sem-mini"}, ""))
          + el("a", {"href": "index.php?r=" + x.id, "title": x.nome}, h(x.nome))))
        + el("td", {}, h(x.tipo))
        + el("td", {}, el("span", {"class": "estado " + (estadoOrdem === 0 ? "estado-uso" : (estadoOrdem === 1 ? "estado-repouso" : "estado-indisponivel"))}, estadoTxt))
        + el("td", {}, x.carga !== null
          ? el("div", {"class": "carga-cel" + (x.carga <= 20 ? " baixa" : ""), "title": x.carga_de}, el("div", {"class": "carga-linha"}, el("span", {"class": "barra"}, el("span", {"style": "width: " + x.carga + "%"}, ""))
            + " " + el("strong", {}, x.carga + "%")))
          : el("small", {"class": "sem-carga"}, h(x.carga_de)))
        + el("td", {}, h(x.ultimo_txt))
        + el("td", {}, m ? (m.data === d.data ? "hoje" : dataBr(m.data)) : "—")
        + el("td", {"class": "col-em" + (atrasado ? " atrasado" : ""), "data-momento": m && m.momento > 0 ? m.momento : null}, m ? h(m.falta) : "—")
        + el("td", {"class": m ? "fazer" : ""}, m ? h(m.nome) : "—")
        + el("td", {}, x.compra.data ? dataBr(x.compra.data) : "—")
        + el("td", {"class": "col-valor"}, x.compra.valor !== null ? "R$ " + reais(x.compra.valor) : "—"));
    }).join("");
    window.trocarLinhasDaTabela(linhas + (lista.length === 0 ? el("tr", {}, el("td", {"colspan": "10"}, "Nenhum relógio cadastrado. Use o botão Novo relógio.")) : ""));
  });
}

// "Mostrar os N dias" dos próximos dias (só aparece no celular): fica aberto até a página ser recarregada
document.addEventListener("click", function (ev) {
  if (ev.target.closest("[data-ver-dias]")) {
    document.getElementById("bloco-proximos").classList.add("todos-os-dias");
  }
});

// ao abrir: a página, e o relógio pedido (index.php?r=3) ou o cadastro de um novo (index.php?novo=1) no painel
recarregarHoje().then(function () {
  var parametros = new URLSearchParams(window.location.search);
  if (parametros.get("r")) {
    abrirNoPainel(parseInt(parametros.get("r"), 10)).catch(function (e) {
      mostrarRecado("Não abri o relógio " + parametros.get("r") + ": " + e.message + ".");
      history.replaceState(null, "", "index.php");
    });
  } else if (parametros.has("novo")) {
    abrirNoPainel(0);
  }
}).catch(function (e) {
  mostrarRecado("Não consegui ler os dados: " + e.message);
});
