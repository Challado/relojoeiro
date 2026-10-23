// Programas: a lista da API (recurso=programas). Uma tabela (o código, o nome, o tipo de cadastro, como funciona, a
// velocidade do berço, a sequência, as voltas por dia e as posições que usam cada um), o formulário (recurso=programas,
// acao=salvar) com a conta na hora (previa=1: cada mudança pede a conta à API, sem gravar) e o ajudante "montar uma
// sequência para N voltas por dia" (acao=montar). Nada do que é calculado se digita. As explicações ficam na gaveta de ajuda.
var q = new URLSearchParams(window.location.search);
var editar = parseInt(q.get("editar") || "0", 10);
var MOVIMENTOS = [];

aoGravar = function (form, res) {
  if (!res.ok) {
    alert(textoResposta(res));
    form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
    return;
  }
  if (window.location.search !== "" || form.hasAttribute("data-vai-editar")) {
    window.location.href = form.hasAttribute("data-vai-editar") && res.id ? "programas.php?editar=" + res.id + "#form" : "programas.php";
    return;
  }
  return salvarSemMover(form, res, carregarProgramas);
};

// a conta de um programa: os passos (as duas medidas; a calculada em itálico) e o resumo (o ciclo, as vezes por dia, o
// tempo girando, as voltas por dia, a velocidade usada), com os erros e os avisos
function tabelaConta(c) {
  var erros = c.erros.map(function (x) { return el("p", {"class": "acao"}, h(x)); }).join("") + c.avisos.map(function (x) { return el("p", {"class": "aviso-conta"}, h(x)); }).join("");
  if (c.passos.length === 0) {
    return erros;
  }
  var medida = function (texto, calculada) {
    return texto === null ? el("span", {"class": "nota"}, t("falta a velocidade")) : (calculada ? el("em", {"title": t("calculado")}, h(texto)) : h(texto));
  };
  var resumo = [[t("Uma volta da sequência"), c.ciclo_texto], [t("Vezes por dia"), c.vezes_dia !== null ? num(c.vezes_dia, 1) : null],
    [t("Girando por dia"), c.girando_texto], [t("Velocidade do berço"), c.velocidade_texto],
    [t("Voltas por dia"), c.voltas_texto !== null ? el("strong", {}, h(c.voltas_texto)) : null]];
  return erros + el("div", {"class": "rolagem"}, el("table", {"class": "compacta conta-programa"}, el("thead", {}, el("tr", {}, el("th", {}, "#") + el("th", {}, t("Movimento"))
      + el("th", {}, t("Tipo")) + el("th", {}, t("Voltas")) + el("th", {}, t("Tempo"))))
    + el("tbody", {}, c.passos.map(function (p) {
      return el("tr", {"class": "tipo-" + p.tipo}, el("td", {"class": "num"}, p.ordem) + el("td", {}, el("strong", {}, p.movimento_id) + " " + h(p.nome)) + el("td", {}, h(p.tipo_texto))
        + el("td", {}, medida(p.voltas_texto, p.voltas_calculadas)) + el("td", {}, medida(p.tempo_texto, p.tempo_calculado)));
    }).join(""))))
    + el("dl", {"class": "pares resumo-programa"}, resumo.map(function (x) { return el("dt", {}, x[0]) + el("dd", {}, x[1] === null ? el("span", {"class": "nota"}, "—") : x[1]); }).join(""));
}

// a conta na hora: cada mudança no formulário pede a conta à API (previa=1), sem gravar
var contaEspera = null;
function pedirConta(form) {
  clearTimeout(contaEspera);
  contaEspera = setTimeout(function () {
    var fd = new FormData(form);
    var p = {recurso: "programas", previa: "1"};
    ["id", "medida", "velocidade", "tpd_manual", "funciona", "liga_horas", "para_horas", "sequencia"].forEach(function (k) {
      if (fd.has(k)) {
        p[k] = fd.get(k);
      }
    });
    api(p).then(function (r) {
      document.getElementById("conta-programa").innerHTML = tabelaConta(r.calculo);
    }).catch(function () { /* a conta volta na próxima mudança */ });
  }, 250);
}

// o formulário mostra só o que vale: a velocidade contada ou as voltas do manual, e as horas do bloco
function ajustarPrograma(form) {
  var vel = form.querySelector("[name=origem_velocidade]:checked").value;
  form.querySelector("[data-so-contada]").hidden = vel !== "contada";
  form.querySelector("[data-so-manual]").hidden = vel !== "manual";
  if (vel !== "contada") {
    form.querySelector("[name=velocidade]").value = "";
  }
  if (vel !== "manual") {
    form.querySelector("[name=tpd_manual]").value = "";
  }
  form.querySelector("[data-so-blocos]").hidden = form.querySelector("[name=funciona]:checked").value !== "blocos";
}

function carregarProgramas() {
  return api({recurso: "programas"}).then(function (d) {
    MOVIMENTOS = d.movimentos;
    var ep = null;
    d.programas.forEach(function (g) { if (g.id === editar) { ep = g; } });
    ajudaGrupo("pagina", t("Programas"));
    ondeMuda("programas", [[t("O programa: o nome, o tipo de cadastro, como funciona, a velocidade do berço e a sequência"), t("aqui")],
      [t("Os giros e as paradas (as voltas ou o tempo de cada um)"), lugar("movimentos.php", t("Movimentos"))],
      [t("Qual programa cada posição usa"), lugar("winders.php", t("Winders"))]]);
    var iProg = ajuda("programa", t("O que é um programa"), el("p", {}, t("O jeito como o berço do winder gira: uma sequência de movimentos (giros e paradas, da página Movimentos), repetida o dia todo ou em blocos. Um programa serve em quantas posições você quiser, em qualquer winder."))
      + el("p", {}, t("Nada do que é calculado se digita: as voltas por dia, o ciclo e o tempo girando saem sempre da sequência e da velocidade do berço, e a conta aparece embaixo enquanto você muda.")));
    var iTipo = ajuda("tipo", t("Por voltas ou por tempo"), el("p", {}, t("O tipo de cadastro diz como os giros da sequência são medidos: por voltas (o giro diz quantas voltas dá; o tempo é calculado) ou por tempo (o giro diz quanto tempo gira; as voltas são calculadas). Os dois nunca juntos: um giro na outra medida não entra na sequência.")));
    var iVel = ajuda("velocidade", t("A velocidade do berço"), el("p", {}, t("Quantas voltas o berço dá em 1 minuto, girando. É o que transforma tempo em voltas (e voltas em tempo): o mesmo programa num motor lento e num rápido dá voltas por dia bem diferentes."))
      + el("p", {}, t("Contada: cole um pedacinho de fita no berço e conte as voltas em 1 minuto. Pelo manual: se o manual diz quantas voltas por dia aquele modo dá, ponha esse número e a velocidade sai dele. Sem nenhuma das duas, a conta mostra o ciclo e o tempo girando, mas não as voltas por dia.")));
    var iFunc = ajuda("funciona", t("O dia todo ou em blocos"), el("p", {}, t("O dia todo: a sequência se repete sem parar. Em blocos: ela se repete durante as horas ligado e descansa as horas parado, e o bloco recomeça (como o \"gira 3 h, descansa 9 h\" de alguns winders).")));
    var iSeq = ajuda("sequencia", t("A sequência"), el("p", {}, t("Os códigos dos movimentos, na ordem, separados por vírgula: 4, 5, 6, 5. O mesmo código pode entrar mais de uma vez (a mesma parada entre os dois giros). O menu do lado põe um movimento no fim; os códigos estão na página Movimentos.")));
    var iMontar = ajuda("montar", t("O ajudante"), el("p", {}, t("Para um winder que só diz as voltas por dia (como a chave TPD do Versa: 650, 850...): diga as voltas, a velocidade do berço e o sentido, e o ajudante monta um programa com giros e paradas que dão essas voltas (cria os movimentos que faltam). Depois é um programa como os outros: confira e mude o que quiser.")));
    var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Programas")) + el("span", {"class": "nota"}, d.programas.length === 1 ? t("1 programa") : t("{1} programas", {1: d.programas.length})) + ajudaBotao());
    if (d.programas.length === 0) {
      res += el("p", {"class": "nota"}, t("Nenhum programa ainda. Cadastre o primeiro abaixo, ou use o ajudante."));
    } else {
      res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta programas"}, el("thead", {}, el("tr", {}, el("th", {}, t("Código")) + el("th", {}, t("Nome") + iProg)
        + el("th", {}, t("Tipo") + iTipo) + el("th", {}, t("Funciona") + iFunc) + el("th", {}, t("Velocidade do berço") + iVel) + el("th", {}, t("Sequência") + iSeq)
        + el("th", {}, t("Voltas por dia")) + el("th", {}, t("Usado em")) + el("th", {}, "")))
        + el("tbody", {}, d.programas.map(function (g) {
          var c = g.calculo;
          return el("tr", {}, el("td", {"class": "num"}, el("strong", {}, g.id)) + el("td", {}, h(g.nome) + (g.observacao ? el("small", {}, h(g.observacao)) : ""))
            + el("td", {}, h(g.medida_texto)) + el("td", {}, h(g.funciona_texto))
            + el("td", {}, c.velocidade_texto !== null ? h(c.velocidade_texto) : el("span", {"class": "nota"}, t("não informada")))
            + el("td", {}, g.sequencia.length > 0 ? h(g.sequencia.join(", ")) + el("small", {}, h(c.passos.map(function (p) { return p.nome; }).join(" → "))) : "—")
            + el("td", {}, c.erros.length > 0 ? el("span", {"class": "acao"}, h(c.erros[0])) : (c.voltas_texto !== null ? el("strong", {}, h(c.voltas_texto)) + (c.ciclo_texto !== null ? el("small", {}, t("ciclo de {1}", {1: h(c.ciclo_texto)})) : "") : el("small", {}, h(c.texto))))
            + el("td", {}, g.usado_em.length === 0 ? el("span", {"class": "nota"}, t("nenhuma posição")) : g.usado_em.map(function (u) { return h(u.texto); }).join("; "))
            + el("td", {"class": "acoes-linha"}, el("a", {"class": "botao leve", "href": "programas.php?editar=" + g.id + "#form"}, t("Editar"))
              + (g.usado_em.length === 0 ? " " + el("form", {"data-recurso": "programas", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"})
                + el("input", {"type": "hidden", "name": "id", "value": g.id}) + el("button", {"class": "leve discreto"}, t("Excluir"))) : "")));
        }).join(""))));
    }
    // o formulário: novo, ou o que se edita
    var origem = ep ? (ep.velocidade !== null ? "contada" : (ep.tpd_manual !== null ? "manual" : "nenhuma")) : "contada";
    var blocos = ep !== null && ep.liga_horas !== null;
    var dec = function (v) { return v === null || v === undefined ? "" : String(v).replace(".", ","); };
    res += el("section", {"class": "cartao-config", "id": "form"}, el("h2", {}, ep ? t("Alterar o programa {1}", {1: ep.id}) : t("Novo programa"))
      + el("form", {"data-recurso": "programas", "class": "form-grade", "id": "form-programa"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (ep ? el("input", {"type": "hidden", "name": "id", "value": ep.id}) : "")
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": ep ? ep.nome : "", "required": true, "maxlength": "80", "placeholder": t("Anyasun modo L")})}))
        + el("fieldset", {"class": "escolha"}, el("legend", {}, t("Tipo de cadastro") + iTipo) + d.medidas.map(function (m) {
          return el("label", {"class": "check"}, el("input", {"type": "radio", "name": "medida", "value": m.valor, "checked": (ep ? ep.medida : "tempo") === m.valor}) + " " + h(m.nome));
        }).join(""))
        + el("fieldset", {"class": "escolha"}, el("legend", {}, t("Funciona") + iFunc)
          + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "funciona", "value": "dia_todo", "checked": !blocos}) + " " + t("o dia todo"))
          + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "funciona", "value": "blocos", "checked": blocos}) + " " + t("em blocos"))
          + el("div", {"class": "par-tempo", "data-so-blocos": true}, el("label", {}, t("liga {1} h", {1: el("input", {"name": "liga_horas", "value": dec(ep ? ep.liga_horas : null), "inputmode": "decimal", "class": "curto"})}))
            + el("label", {}, t("e para {1} h", {1: el("input", {"name": "para_horas", "value": dec(ep ? ep.para_horas : null), "inputmode": "decimal", "class": "curto"})}))))
        + el("fieldset", {"class": "escolha"}, el("legend", {}, t("Velocidade do berço") + iVel)
          + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "origem_velocidade", "value": "contada", "checked": origem === "contada"}) + " " + t("contada"))
          + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "origem_velocidade", "value": "manual", "checked": origem === "manual"}) + " " + t("pelas voltas por dia do manual"))
          + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "origem_velocidade", "value": "nenhuma", "checked": origem === "nenhuma"}) + " " + t("ainda não sei"))
          + el("label", {"data-so-contada": true}, t("{1} voltas por minuto", {1: el("input", {"name": "velocidade", "value": dec(ep ? ep.velocidade : null), "inputmode": "decimal", "class": "curto"})}))
          + el("label", {"data-so-manual": true}, t("{1} voltas por dia (o manual)", {1: el("input", {"name": "tpd_manual", "value": ep && ep.tpd_manual !== null ? ep.tpd_manual : "", "inputmode": "numeric", "class": "curto"})})))
        + el("div", {"class": "campo-sequencia"}, el("label", {}, t("Sequência {1}", {1: el("input", {"name": "sequencia", "value": ep ? ep.sequencia.join(", ") : "", "placeholder": t("4, 5, 6, 5"), "autocomplete": "off"})}) + iSeq)
          + el("label", {}, t("pôr no fim {1}", {1: el("select", {"data-por-movimento": true}, el("option", {"value": ""}, t("— um movimento —"))
            + d.movimentos.map(function (m) { return el("option", {"value": m.id}, h(m.id + " · " + m.nome + " (" + m.medida_texto + ")")); }).join(""))})))
        + el("label", {}, t("Observação {1}", {1: el("textarea", {"name": "observacao", "maxlength": "1000"}, ep && ep.observacao !== null ? h(ep.observacao) : "")}))
        + el("div", {"id": "conta-programa", "class": "conta-programa-caixa"}, ep ? tabelaConta(ep.calculo) : "")
        + el("div", {"class": "botoes"}, el("button", {}, ep ? t("Salvar") : t("Cadastrar")) + (ep ? " " + el("a", {"href": "programas.php"}, t("cancelar")) : ""))));
    // o ajudante
    res += el("section", {"class": "cartao-config", "id": "montar"}, el("h2", {}, t("Montar uma sequência para N voltas por dia") + iMontar)
      + el("form", {"data-recurso": "programas", "class": "form-grade", "data-vai-editar": true}, el("input", {"type": "hidden", "name": "acao", "value": "montar"})
        + el("label", {}, t("Voltas por dia {1}", {1: el("input", {"name": "tpd", "value": "650", "inputmode": "numeric", "class": "curto", "required": true})}))
        + el("label", {}, t("Velocidade do berço {1} voltas por minuto", {1: el("input", {"name": "velocidade", "value": "8", "inputmode": "decimal", "class": "curto", "required": true})}))
        + el("label", {}, t("Sentido {1}", {1: el("select", {"name": "sentido"}, el("option", {"value": "os_dois"}, t("os dois (alternando)")) + el("option", {"value": "horario"}, t("só o horário"))
          + el("option", {"value": "anti_horario"}, t("só o anti-horário")))}))
        + el("label", {}, t("Giros em {1}", {1: el("select", {"name": "medida"}, d.medidas.map(function (m) { return el("option", {"value": m.valor}, h(m.nome)); }).join(""))}))
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "maxlength": "80", "placeholder": t("vazio: \"650 voltas por dia, os dois sentidos\"")})}))
        + el("div", {"class": "botoes"}, el("button", {}, t("Montar")))));
    document.getElementById("programas").innerHTML = res;
    var form = document.getElementById("form-programa");
    ajustarPrograma(form);
    form.addEventListener("change", function (ev) {
      if (ev.target.hasAttribute("data-por-movimento")) {
        if (ev.target.value !== "") {
          var seq = form.querySelector("[name=sequencia]");
          seq.value = (seq.value.trim() !== "" ? seq.value.trim().replace(/,\s*$/, "") + ", " : "") + ev.target.value;
          ev.target.value = "";
        }
      } else if (ev.target.name === "origem_velocidade" || ev.target.name === "funciona") {
        ajustarPrograma(form);
      }
      pedirConta(form);
    });
    form.addEventListener("input", function () { pedirConta(form); });
    if (ep) {
      document.getElementById("form").scrollIntoView();
    }
  }).catch(function (er) {
    document.getElementById("programas").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os programas: {1}", {1: h(er.message)}));
  });
}
carregarProgramas();
