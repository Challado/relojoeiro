// Página Simulador: compara dois ou mais cenários de rodízio antes de escolher um. O que dá para escolher (os modos, o que cada
// um deixa mudar, as formas, os relógios ativos, o período padrão e os limites) vem do api.php (recurso=simulador); a simulação
// também (o mesmo recurso, com cenarios): a tela só monta o pedido e mostra a resposta (a comparação, com os melhores de cada
// medida, os dias lado a lado e cada relógio). O Aplicar grava o cenário escolhido (recurso=simulador, acao=aplicar) igual ao
// simulado. O pedido fica no endereço (de, ate, semente, cenarios, variar; simular=1 simula ao abrir): dá para voltar a ele.
var OP = null;
var estado = {de: "", ate: "", semente: "", variar: {parametro: "", de: "", ate: "", passo: "1"}, cenarios: []};
var resultado = null;
var simulando = false;
var recado = "";

function modoDe(id) {
  return OP.modos.filter(function (m) { return m.id === Number(id); })[0] || OP.modos[0];
}

function modoAtivo() {
  return OP.modos.filter(function (m) { return m.ativo; })[0] || OP.modos[0];
}

function tituloForma(chave) {
  var f = OP.formas.filter(function (x) { return x.chave === chave; })[0];
  return f ? f.titulo : chave;
}

function tituloParametro(chave) {
  var p = OP.parametros.filter(function (x) { return x.chave === chave; })[0];
  return p ? p.titulo : chave;
}

// um parâmetro do cenário: a lista (a forma, o ciclo) ou o número; o que não vale no modo fica desabilitado, com o motivo
function campoParametro(i, c, chave) {
  var m = modoDe(c.modo);
  var p = m.parametros[chave];
  var valor = c[chave] === undefined || c[chave] === null ? "" : String(c[chave]);
  var nome = "c" + i + "-" + chave;
  var controle;
  if (chave === "forma") {
    controle = el("select", {"id": nome, "data-cenario": i, "data-campo": chave, "disabled": !p.aplica, "title": p.motivo},
      el("option", {"value": ""}, h(t("como está ({1})", {1: p.valor !== null && p.valor !== "" ? tituloForma(p.valor) : t("nenhuma")})))
      + p.opcoes.map(function (f) { return el("option", {"value": f, "selected": valor === f}, h(tituloForma(f))); }).join(""));
  } else if (chave === "ciclo") {
    controle = el("select", {"id": nome, "data-cenario": i, "data-campo": chave, "disabled": !p.aplica, "title": p.motivo},
      el("option", {"value": ""}, h(t("como está ({1})", {1: p.valor === 1 ? t("ligado") : t("desligado")})))
      + el("option", {"value": "1", "selected": valor === "1"}, t("ligado")) + el("option", {"value": "0", "selected": valor === "0"}, t("desligado")));
  } else {
    controle = el("input", {"id": nome, "type": "number", "inputmode": "numeric", "min": p.min, "max": p.max, "value": valor, "data-cenario": i, "data-campo": chave,
      "disabled": !p.aplica, "title": p.motivo, "placeholder": p.valor === null ? "" : t("como está: {1}", {1: p.valor})});
  }
  return el("label", {"class": p.aplica ? "" : "desabilitado"}, h(tituloParametro(chave)) + " " + controle + (p.aplica ? "" : " " + el("small", {"class": "nota"}, h(p.motivo))));
}

// os relógios do sorteio do cenário: os ativos, marcados os que estão no sorteio (ou os que o cenário escolheu)
function campoRelogios(i, c) {
  var marcados = c.relogios || OP.relogios.filter(function (r) { return r.no_sorteio; }).map(function (r) { return r.id; });
  return el("details", {"class": "sim-relogios"}, el("summary", {}, t("Relógios no sorteio ({1} de {2})", {1: marcados.length, 2: OP.relogios.length}))
    + el("p", {"class": "nota"}, t("Desmarcar tira o relógio do sorteio só neste cenário; aplicado, ele sai do sorteio de verdade (continua ativo, e à mão continua valendo)."))
    + el("div", {"class": "sim-lista"}, OP.relogios.map(function (r) {
      return el("label", {"class": "check"}, el("input", {"type": "checkbox", "data-cenario": i, "data-relogio": r.id, "checked": marcados.indexOf(r.id) >= 0}) + " " + h(r.nome)
        + (r.no_sorteio ? "" : " " + el("small", {"class": "nota"}, t("(fora do sorteio agora)"))));
    }).join("")));
}

function cartaoCenario(c, i) {
  return el("fieldset", {"class": "cartao-config sim-cenario"}, el("legend", {}, t("Cenário {1}", {1: i + 1}))
    + el("div", {"class": "form-grade"},
      el("label", {}, t("Modo {1}", {1: el("select", {"data-cenario": i, "data-campo": "modo"}, OP.modos.map(function (m) {
        return el("option", {"value": m.id, "selected": Number(c.modo) === m.id}, h(m.nome + (m.ativo ? t(" (o ativo)") : "") + " · " + m.tipo_texto));
      }).join(""))}))
      + OP.parametros.map(function (p) { return campoParametro(i, c, p.chave); }).join(""))
    + campoRelogios(i, c)
    + el("div", {"class": "botoes"}, estado.cenarios.length > 2 ? el("button", {"type": "button", "class": "leve discreto", "data-remover": i}, t("Remover este cenário")) : ""));
}

function formVariar() {
  var v = estado.variar;
  var p = OP.parametros.filter(function (x) { return x.chave === v.parametro; })[0];
  var numerico = p ? p.numerico : false;
  return el("fieldset", {"class": "cartao-config sim-variar"}, el("legend", {}, t("Variar um parâmetro (opcional)"))
    + el("div", {"class": "form-grade"},
      el("label", {}, t("Parâmetro {1}", {1: el("select", {"id": "v-parametro"}, el("option", {"value": ""}, t("nenhum"))
        + OP.parametros.map(function (x) { return el("option", {"value": x.chave, "selected": v.parametro === x.chave}, h(x.titulo)); }).join(""))}))
      + ["de", "ate", "passo"].map(function (k) {
        var titulo = {de: t("De"), ate: t("Até"), passo: t("Passo")}[k];
        return el("label", {"class": numerico ? "" : "desabilitado"}, titulo + " " + el("input", {"id": "v-" + k, "type": "number", "inputmode": "numeric", "value": v[k], "disabled": !numerico,
          "title": numerico ? "" : t("só nos parâmetros numéricos: a forma e o ciclo percorrem todos os valores")}));
      }).join(""))
    + el("p", {"class": "nota"}, t("Cada cenário vira um para cada valor (a forma: cada forma que o modo aceita; o ciclo: desligado e ligado). No máximo {1} cenários.", {1: OP.max_cenarios})));
}

// a comparação: uma coluna por cenário, as medidas nas linhas (os melhores de cada uma em destaque) e o Aplicar de cada um
function tabelaComparacao(s) {
  var cab = el("tr", {}, el("th", {}, t("Medida")) + s.cenarios.map(function (c) {
    // a escala planeja só os dias dela: o cenário mostra até o fim dela
    var ate = c.resultado && c.resultado.ate_mostrado < s.ate ? " " + el("small", {"class": "nota"}, t("(até {1}: o fim da escala; aumente os dias da escala para ver mais)", {1: dataBr(c.resultado.ate_mostrado)})) : "";
    return el("th", {"class": "sim-col"}, el("span", {"class": "sim-numero"}, t("Cenário {1}", {1: c.indice + 1})) + " " + h(c.rotulo) + ate);
  }).join(""));
  var linhas = s.comparacao.map(function (m) {
    return el("tr", {}, el("th", {"scope": "row"}, h(m.titulo) + (m.melhor_e === "" ? " " + el("small", {"class": "nota"}, t("(gosto)")) : ""))
      + m.valores.map(function (v, i) {
        return el("td", {"class": "num" + (m.melhores.indexOf(i) >= 0 ? " sim-melhor" : "")}, v === null ? "—" : h(String(v)) + (m.melhores.indexOf(i) >= 0 ? " ★" : ""));
      }).join(""));
  }).join("");
  var aplicar = el("tr", {"class": "sim-aplicar"}, el("th", {}, "") + s.cenarios.map(function (c) {
    return el("td", {}, c.resultado === null ? el("small", {"class": "erro"}, h(c.erros.join(" ")))
      : el("button", {"type": "button", "data-aplicar": c.indice}, t("Aplicar este")));
  }).join(""));
  return el("div", {"class": "rolagem"}, el("table", {"class": "relogios sim-comparacao"}, el("thead", {}, cab) + el("tbody", {}, linhas + aplicar)));
}

// os dias lado a lado: uma linha por dia, uma coluna por cenário (o dia à mão e o que muda em relação ao plano de agora marcados)
function tabelaDias(s) {
  var base = s.cenarios.filter(function (c) { return c.resultado !== null; })[0];
  if (!base) {
    return "";
  }
  var cab = el("tr", {}, el("th", {}, t("Dia")) + s.cenarios.map(function (c) { return el("th", {}, t("Cenário {1}", {1: c.indice + 1})); }).join(""));
  var linhas = base.resultado.dias.map(function (d, n) {
    return el("tr", {"class": d.dia_semana >= 6 ? "fds" : ""}, el("td", {"class": "dia"}, dataBr(d.data, true) + " " + el("small", {}, h(d.dia_semana_curto)))
      + s.cenarios.map(function (c) {
        var x = c.resultado === null ? null : c.resultado.dias[n];
        if (!x) {
          return el("td", {}, "—");
        }
        var nome = x.origem === "livre" ? t("livre") : (x.origem === "vazio" ? t("sem relógio") : h(x.relogio));
        return el("td", {"class": (x.muda ? "sim-muda" : "") + (x.origem === "manual" ? " sim-mao" : ""), "title": x.motivo}, nome + (x.origem === "manual" ? " " + el("small", {"class": "nota"}, t("à mão")) : ""));
      }).join(""));
  }).join("");
  return el("section", {}, el("h2", {}, t("Dia a dia"))
    + el("p", {"class": "nota"}, t("Em destaque, os dias diferentes do plano de agora; o dia escolhido à mão fica em todos. Parar o mouse num dia mostra por que aquele relógio."))
    + el("div", {"class": "rolagem"}, el("table", {"class": "relogios sim-dias"}, el("thead", {}, cab) + el("tbody", {}, linhas))));
}

// cada relógio: os dias e a parte do período em cada cenário, e o maior tempo parado
function tabelaRelogios(s) {
  var nomes = {};
  var ordem = [];
  s.cenarios.forEach(function (c) {
    (c.resultado ? c.resultado.por_relogio : []).forEach(function (r) {
      if (nomes[r.relogio_id] === undefined) {
        nomes[r.relogio_id] = r.relogio;
        ordem.push(r.relogio_id);
      }
    });
  });
  var cab = el("tr", {}, el("th", {}, t("Relógio")) + s.cenarios.map(function (c) { return el("th", {}, t("Cenário {1}", {1: c.indice + 1})); }).join(""));
  var linhas = ordem.map(function (id) {
    return el("tr", {}, el("td", {}, h(nomes[id])) + s.cenarios.map(function (c) {
      var r = c.resultado ? c.resultado.por_relogio.filter(function (x) { return x.relogio_id === id; })[0] : null;
      if (!r) {
        return el("td", {}, "—");
      }
      return el("td", {"class": r.dias === 0 ? "sim-zero" : ""}, t("{1} d ({2}%)", {1: r.dias, 2: num(r.porcentagem, 1)})
        + (r.maior_intervalo !== null ? " " + el("small", {"class": "nota"}, t("parado até {1} d", {1: r.maior_intervalo})) : "")
        + (r.no_sorteio ? "" : " " + el("small", {"class": "nota"}, t("fora do sorteio"))));
    }).join(""));
  }).join("");
  return el("section", {}, el("h2", {}, t("Cada relógio"))
    + el("p", {"class": "nota"}, t("Os dias no período, a parte dele e o maior tempo parado (entre dois dias, contando da última vez antes do período, ou até o fim dele)."))
    + el("div", {"class": "rolagem"}, el("table", {"class": "relogios sim-por-relogio"}, el("thead", {}, cab) + el("tbody", {}, linhas))));
}

function montarSimulador() {
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Simulador")));
  res += ajudaNota("simulador", t("Como o simulador funciona"), t("Cada cenário é um modo com o que você quer mudar nele (a forma de escolha, o ciclo, as semanas, os dias da escala, a garantia de rodízio) e os relógios que entram no sorteio. Todos rodam no mesmo período, com as regras de verdade do rodízio, e nada é gravado. Compare e, no que mais agradar, clique em Aplicar este: o modo, as mudanças e o plano simulado são gravados igual ao que você viu (os dias escolhidos à mão e os livres ficam)."));
  if (!OP.rodizio_ativo) {
    res += el("p", {"class": "aviso"}, t("O rodízio está desligado: não há sorteio para simular.")) + el("a", {"href": "rodizio.php"}, t("Ajustes do rodízio"));
    porHtml(document.getElementById("simulador"), res);
    return;
  }
  if (recado !== "") {
    res += el("p", {"class": "acao"}, h(recado));
  }
  res += el("form", {"id": "form-simulador", "onsubmit": "return false"},
    el("fieldset", {"class": "cartao-config sim-periodo"}, el("legend", {}, t("Período"))
      + el("div", {"class": "form-grade"}, el("label", {}, t("De {1}", {1: el("input", {"type": "date", "id": "p-de", "min": OP.periodo.min, "value": estado.de})}))
        + el("label", {}, t("Até {1}", {1: el("input", {"type": "date", "id": "p-ate", "min": OP.periodo.min, "value": estado.ate})}))
        + el("label", {}, t("Semente {1}", {1: el("input", {"type": "number", "id": "p-semente", "inputmode": "numeric", "min": 1, "value": estado.semente, "placeholder": t("vazio: uma nova")})})))
      + el("p", {"class": "nota"}, t("De amanhã em diante (hoje fica como está), no máximo {1} dias. A mesma semente dá o mesmo sorteio; vazia, uma nova a cada simulação.", {1: OP.periodo.max_dias})))
    + el("div", {"class": "sim-cenarios"}, estado.cenarios.map(cartaoCenario).join(""))
    + el("div", {"class": "botoes"}, el("button", {"type": "button", "class": "leve", "id": "mais-cenario", "disabled": estado.cenarios.length >= OP.max_cenarios,
      "title": estado.cenarios.length >= OP.max_cenarios ? t("no máximo {1} cenários", {1: OP.max_cenarios}) : ""}, t("+ Cenário")))
    + formVariar()
    + el("div", {"class": "botoes"}, el("button", {"type": "button", "id": "simular", "disabled": simulando}, simulando ? t("Simulando…") : t("Simular"))));
  if (resultado !== null) {
    res += el("section", {"id": "resultado"}, el("h2", {}, t("Comparação ({1} a {2})", {1: dataBr(resultado.de), 2: dataBr(resultado.ate)}))
      + el("p", {"class": "nota"}, t("★ o melhor de cada medida; \"gosto\": não há melhor, depende do que você prefere. Semente {1}.", {1: resultado.semente}))
      + tabelaComparacao(resultado) + tabelaDias(resultado) + tabelaRelogios(resultado));
  }
  porHtml(document.getElementById("simulador"), res);
}

// o estado da tela a partir do formulário (antes de remontar)
function lerFormulario() {
  var de = document.getElementById("p-de");
  if (!de) {
    return;
  }
  estado.de = de.value;
  estado.ate = document.getElementById("p-ate").value;
  estado.semente = document.getElementById("p-semente").value;
  document.querySelectorAll("[data-cenario][data-campo]").forEach(function (x) {
    var c = estado.cenarios[Number(x.getAttribute("data-cenario"))];
    var campo = x.getAttribute("data-campo");
    c[campo] = campo === "modo" ? Number(x.value) : (x.disabled ? "" : x.value);
  });
  estado.cenarios.forEach(function (c, i) {
    var caixas = document.querySelectorAll("[data-cenario='" + i + "'][data-relogio]");
    if (caixas.length > 0) {
      var marcados = [];
      caixas.forEach(function (x) {
        if (x.checked) {
          marcados.push(Number(x.getAttribute("data-relogio")));
        }
      });
      var agora = OP.relogios.filter(function (r) { return r.no_sorteio; }).map(function (r) { return r.id; });
      c.relogios = marcados.join(",") === agora.join(",") ? null : marcados;
    }
  });
  var vp = document.getElementById("v-parametro");
  estado.variar = {parametro: vp.value, de: document.getElementById("v-de").value, ate: document.getElementById("v-ate").value, passo: document.getElementById("v-passo").value};
}

// o cenário como a API o recebe (o que fica como está não vai)
function cenarioPedido(c) {
  var r = {modo: c.modo};
  OP.parametros.forEach(function (p) {
    if (c[p.chave] !== undefined && c[p.chave] !== null && c[p.chave] !== "") {
      r[p.chave] = c[p.chave];
    }
  });
  if (c.relogios) {
    r.relogios = c.relogios.join(",");
  }
  return r;
}

function pedidoSimulacao() {
  var p = {recurso: "simulador", de: estado.de, ate: estado.ate, semente: estado.semente, cenarios: JSON.stringify(estado.cenarios.map(cenarioPedido))};
  if (estado.variar.parametro !== "") {
    p.variar = JSON.stringify(estado.variar);
  }
  return p;
}

function simular() {
  simulando = true;
  recado = "";
  montarSimulador();
  var pedido = pedidoSimulacao();
  return api(pedido).then(function (d) {
    OP = d.opcoes;
    resultado = d.simulacao;
    // o pedido no endereço: recarregar a página (ou guardar o link) volta a esta simulação
    var url = new URLSearchParams(pedido);
    url.delete("recurso");
    // a semente usada (a sorteada, se veio vazia): recarregar mostra o mesmo sorteio
    url.set("semente", String(d.simulacao.semente));
    url.set("simular", "1");
    history.replaceState(null, "", "simulador.php?" + url.toString());
  }).catch(function (e) {
    resultado = null;
    recado = e.dados && e.dados.erros ? e.dados.erros.join(" ") : e.message;
  }).then(function () {
    simulando = false;
    montarSimulador();
    var r = document.getElementById("resultado");
    if (r) {
      r.scrollIntoView({block: "start"});
    }
  });
}

function aplicar(i) {
  var c = resultado.cenarios[i];
  var texto = t("Aplicar o cenário {1} ({2})?", {1: i + 1, 2: c.rotulo}) + "\n\n" + c.mudancas.map(function (m) { return "• " + m; }).join("\n");
  if (!confirm(texto)) {
    return;
  }
  var dados = Object.assign({recurso: "simulador", acao: "aplicar", de: resultado.de, ate: resultado.ate, semente: String(resultado.semente), impressao: c.resultado.impressao},
    cenarioPedido(Object.assign({}, c.cenario, {relogios: c.cenario.relogios || null})));
  enviar(dados).then(function (r) {
    avisoFlutuanteGravacao(textoResposta(r), !r.ok);
    if (r.ok) {
      recado = textoResposta(r);
      resultado = null;
      return carregar(false);
    }
    return null;
  });
}

// a primeira leitura: as opções; o pedido do endereço (se houver) e, com simular=1, a simulação
function carregar(doEndereco) {
  return api({recurso: "simulador"}).then(function (d) {
    OP = d.opcoes;
    var q = new URLSearchParams(window.location.search);
    estado.de = doEndereco && q.get("de") ? q.get("de") : OP.periodo.de;
    estado.ate = doEndereco && q.get("ate") ? q.get("ate") : OP.periodo.ate;
    estado.semente = doEndereco && q.get("semente") ? q.get("semente") : "";
    var cens = null;
    var variar = null;
    try {
      cens = doEndereco && q.get("cenarios") ? JSON.parse(q.get("cenarios")) : null;
      variar = doEndereco && q.get("variar") ? JSON.parse(q.get("variar")) : null;
    } catch (e) {
      cens = null;
    }
    var ativo = modoAtivo();
    // sem cenários no endereço: dois do modo ativo (um para comparar com o outro); com uma variação, um só (ela multiplica)
    var padrao = variar && variar.parametro ? [{modo: ativo.id}] : [{modo: ativo.id}, {modo: ativo.id}];
    estado.cenarios = (Array.isArray(cens) && cens.length > 0 ? cens : padrao).map(function (c) {
      return Object.assign({}, c, {relogios: c.relogios ? String(c.relogios).split(",").map(Number) : null});
    });
    estado.variar = Object.assign({parametro: "", de: "", ate: "", passo: "1"}, variar || {});
    if (doEndereco && q.get("simular") === "1") {
      return simular();
    }
    montarSimulador();
    return null;
  });
}

document.addEventListener("change", function (ev) {
  if (!ev.target.closest("#form-simulador")) {
    return;
  }
  lerFormulario();
  // trocar o modo de um cenário (ou o parâmetro da variação) muda o que vale nele: a tela se remonta
  if (ev.target.getAttribute("data-campo") === "modo" || ev.target.id === "v-parametro") {
    if (ev.target.getAttribute("data-campo") === "modo") {
      var c = estado.cenarios[Number(ev.target.getAttribute("data-cenario"))];
      OP.parametros.forEach(function (p) { c[p.chave] = ""; });
    }
    montarSimulador();
  }
});

document.addEventListener("click", function (ev) {
  var b = ev.target.closest("button");
  if (!b || !b.closest("#simulador")) {
    return;
  }
  if (b.id === "mais-cenario") {
    lerFormulario();
    estado.cenarios.push({modo: modoAtivo().id, relogios: null});
    montarSimulador();
  } else if (b.hasAttribute("data-remover")) {
    lerFormulario();
    estado.cenarios.splice(Number(b.getAttribute("data-remover")), 1);
    montarSimulador();
  } else if (b.id === "simular") {
    lerFormulario();
    simular();
  } else if (b.hasAttribute("data-aplicar")) {
    aplicar(Number(b.getAttribute("data-aplicar")));
  }
});

carregar(true);
