// A Sugestão para agora (o cartão da página Hoje, entre os avisos de hoje e os Próximos dias; a opção sugestao_agora da
// Configuração o mostra): a pessoa escolhe a forma de escolha e os tipos de relógio que quer, e o recurso=sugestao devolve um
// relógio para usar agora, fora da escala, com o motivo. "Pôr no pulso" grava pelo lancamento.iniciar de sempre (o plano não
// muda); "Outra sugestão" pede de novo pulando os que já apareceram. A conta é toda da API: aqui só se desenha e se lembra,
// no navegador, a forma e os tipos escolhidos da última vez. index.php?sugerir=1 abre a página já pedindo uma sugestão (um
// atalho no celular), com a escolha lembrada.

// o que o cartão tem agora: a forma e os tipos escolhidos (null: todos), os relógios já sugeridos nesta rodada e a última resposta
var sugestaoEstado = {forma: null, tipos: null, pular: [], resposta: null, pedindo: false};
// o pedido do endereço (?sugerir=1): vale uma vez, na primeira vez que o cartão aparece
var sugestaoDoEndereco = new URLSearchParams(window.location.search).get("sugerir") === "1";

// a escolha da última vez, guardada no navegador (uma comodidade de quem olha: sem ela, a primeira forma e todos os tipos)
function sugestaoLembrada() {
  try {
    var x = JSON.parse(window.localStorage.getItem("sugestao-agora") || "null");
    return x && typeof x === "object" ? x : null;
  } catch (e) {
    return null;
  }
}

function sugestaoLembrar() {
  try {
    window.localStorage.setItem("sugestao-agora", JSON.stringify({forma: sugestaoEstado.forma, tipos: sugestaoEstado.tipos}));
  } catch (e) {
    // sem o armazenamento do navegador (janela anônima, bloqueado), o cartão só não lembra a escolha
  }
}

// os tipos marcados, entre os que têm relógio disponível (null no estado: todos)
function sugestaoTiposMarcados(tipos) {
  return tipos.filter(function (x) {
    return x.disponiveis > 0 && (sugestaoEstado.tipos === null || sugestaoEstado.tipos.indexOf(x.id) !== -1);
  }).map(function (x) { return x.id; });
}

// o resultado: o relógio sugerido, com o motivo e os botões; ou o aviso de que não há sugestão; embaixo, quem ficou de fora
function sugestaoResultadoHtml(r) {
  if (r === null) {
    return "";
  }
  var res = "";
  var s = r.sugestao;
  if (s !== null) {
    var detalhes = [h(s.tipo), h(s.estado_texto)];
    if (s.carga !== null) {
      detalhes.push(t("carga {1}%", {1: s.carga}));
    }
    detalhes.push(t("última vez no pulso: {1}", {1: h(s.ultimo_txt)}));
    res += el("div", {"class": "sugestao-relogio"},
      (s.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + s.relogio_id + "&v=" + s.foto, "alt": ""}) : el("span", {"class": "sem-foto-sugestao", "aria-hidden": "true"}, ""))
      + el("div", {"class": "sugestao-texto"},
        el("a", {"class": "sugestao-nome", "href": "index.php?r=" + s.relogio_id, "data-abrir": s.relogio_id}, h(s.relogio))
        + el("small", {"class": "nota bloco"}, detalhes.join(" · "))
        + el("p", {"class": "motivo"}, h(s.motivo))
        + el("div", {"class": "botoes"},
          el("form", {"data-recurso": "lancamento", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "iniciar"})
            + el("input", {"type": "hidden", "name": "tipo", "value": "pulso"}) + el("input", {"type": "hidden", "name": "relogio_id", "value": s.relogio_id})
            + el("input", {"type": "hidden", "name": "r", "value": s.relogio_id}) + el("button", {}, t("Pôr no pulso")))
          + " " + el("button", {"type": "button", "class": "leve", "data-sugestao-outra": s.relogio_id}, t("Outra sugestão")))));
  } else {
    res += el("p", {"class": "acao"}, h(r.aviso))
      + (r.pulados > 0 ? el("button", {"type": "button", "class": "leve", "data-sugestao-de-novo": "1"}, t("Começar de novo")) : "");
  }
  if (r.fora.length > 0) {
    res += el("p", {"class": "nota sugestao-fora"}, t("Ficaram de fora: {1}.", {1: r.fora.map(function (x) { return h(x.relogio) + " (" + h(x.texto) + ")"; }).join("; ")}));
  }
  return res;
}

// o cartão inteiro: a forma, os tipos (o tipo sem relógio disponível fica desabilitado, com o motivo), o Sugerir e o resultado
function sugestaoCartaoHtml(sg) {
  var marcados = sugestaoTiposMarcados(sg.tipos);
  return el("h2", {}, t("Sugestão para agora"))
    + el("p", {"class": "nota"}, t("Um relógio para usar agora, fora da escala: uma ocasião, uma vontade. Escolha como sugerir e de que tipo; o plano não muda, e nada é gravado até você pôr o relógio no pulso."))
    + el("form", {"id": "sugestao-form", "class": "sugestao-form"},
      el("label", {"class": "sugestao-forma"}, el("span", {}, t("Escolher por")) + el("select", {"name": "forma"}, sg.formas.map(function (f) {
        return el("option", {"value": f.chave, "selected": f.chave === sugestaoEstado.forma}, h(f.nome));
      }).join("")))
      + el("fieldset", {"class": "sugestao-tipos"}, el("legend", {}, t("Tipo")) + sg.tipos.map(function (x) {
        var sem = x.disponiveis === 0;
        return el("label", {"class": "check" + (sem ? " desabilitado" : ""), "title": sem ? t("Nenhum relógio disponível deste tipo agora.") : null},
          el("input", {"type": "checkbox", "name": "tipos", "value": x.id, "checked": !sem && marcados.indexOf(x.id) !== -1, "disabled": sem})
          + " " + h(x.nome) + " " + el("span", {"class": "aba-conta"}, String(x.disponiveis)));
      }).join(""))
      + el("button", {"disabled": marcados.length === 0, "title": marcados.length === 0 ? t("Marque ao menos um tipo.") : null}, t("Sugerir")))
    + el("div", {"id": "sugestao-resultado", "class": "sugestao-resultado", "aria-live": "polite"}, sugestaoResultadoHtml(sugestaoEstado.resposta));
}

// o cartão na página (hoje.js chama a cada leitura do recurso=hoje): com a opção desligada, some; o relógio sugerido que já
// foi para o pulso sai do cartão (a rodada recomeça)
function sugestaoMostrar(d) {
  var bloco = document.getElementById("bloco-sugestao");
  if (!bloco) {
    return;
  }
  var sg = d.sugestao_agora;
  bloco.hidden = sg === null;
  if (sg === null) {
    porHtml(bloco, "");
    return;
  }
  if (sugestaoEstado.forma === null) {
    var lembrada = sugestaoLembrada();
    var formas = sg.formas.map(function (f) { return f.chave; });
    sugestaoEstado.forma = lembrada && formas.indexOf(lembrada.forma) !== -1 ? lembrada.forma : formas[0];
    sugestaoEstado.tipos = lembrada && Array.isArray(lembrada.tipos) ? lembrada.tipos.map(Number) : null;
  }
  var s = sugestaoEstado.resposta ? sugestaoEstado.resposta.sugestao : null;
  if (s !== null && d.relogios.some(function (x) { return x.id === s.relogio_id && x.em_uso; })) {
    sugestaoEstado.resposta = null;
    sugestaoEstado.pular = [];
  }
  bloco.sugestaoDados = sg;
  porHtml(bloco, sugestaoCartaoHtml(sg));
  if (sugestaoDoEndereco) {
    sugestaoDoEndereco = false;
    sugestaoPedir();
  }
}

// pede a sugestão à API, com a forma, os tipos (todos os disponíveis marcados: sem o filtro) e os já sugeridos
function sugestaoPedir() {
  var bloco = document.getElementById("bloco-sugestao");
  var sg = bloco ? bloco.sugestaoDados : null;
  if (!sg || sugestaoEstado.pedindo) {
    return;
  }
  var marcados = sugestaoTiposMarcados(sg.tipos);
  var todos = sg.tipos.filter(function (x) { return x.disponiveis > 0; }).length === marcados.length;
  sugestaoEstado.pedindo = true;
  api({recurso: "sugestao", forma: sugestaoEstado.forma, tipos: todos ? "" : marcados.join(","), pular: sugestaoEstado.pular.join(",")}).then(function (r) {
    sugestaoEstado.resposta = r;
    porHtml(document.getElementById("sugestao-resultado"), sugestaoResultadoHtml(r));
  }).finally(function () {
    sugestaoEstado.pedindo = false;
  });
}

// a escolha muda: o estado acompanha o formulário, a rodada recomeça e o navegador lembra
document.addEventListener("change", function (ev) {
  var form = ev.target.closest ? ev.target.closest("#sugestao-form") : null;
  if (!form) {
    return;
  }
  sugestaoEstado.forma = form.elements.forma.value;
  sugestaoEstado.tipos = Array.prototype.filter.call(form.querySelectorAll('input[name="tipos"]'), function (c) { return c.checked; }).map(function (c) { return Number(c.value); });
  sugestaoEstado.pular = [];
  sugestaoEstado.resposta = null;
  sugestaoLembrar();
  var bloco = document.getElementById("bloco-sugestao");
  porHtml(bloco, sugestaoCartaoHtml(bloco.sugestaoDados));
});

document.addEventListener("submit", function (ev) {
  if (ev.target.id === "sugestao-form") {
    ev.preventDefault();
    sugestaoEstado.pular = [];
    sugestaoPedir();
  }
});

document.addEventListener("click", function (ev) {
  var outra = ev.target.closest ? ev.target.closest("[data-sugestao-outra]") : null;
  if (outra) {
    sugestaoEstado.pular.push(Number(outra.getAttribute("data-sugestao-outra")));
    sugestaoPedir();
    return;
  }
  if (ev.target.closest && ev.target.closest("[data-sugestao-de-novo]")) {
    sugestaoEstado.pular = [];
    sugestaoPedir();
  }
});
