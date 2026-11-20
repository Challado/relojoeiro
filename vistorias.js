// Página Vistorias: os relógios com a vistoria periódica vencendo até um dia (os atrasados sempre) ou todos, do mais atrasado
// ao mais distante, para marcar e registrar várias de uma vez. A lista (o vencimento de cada um pela fórmula do aviso Vistoria,
// a última vistoria, o resumo) vem do api.php (recurso=vistorias); o registro vai para o mesmo recurso (acao=registrar), com
// os relógios marcados e o dia da vistoria (vazio: agora). Desligada (a Configuração em 0, ou o aviso desligado), a página diz
// por quê e leva à Configuração. O filtro fica no endereço (ate, todos=1).
var dados = null;
var filtro = {ate: "", todos: false};
var gravando = false;

function linhaVistoria(x) {
  var classe = x.estado === "atrasado" ? "em atrasado" : (x.estado === "hoje" ? "em hoje" : "em");
  return el("tr", {}, el("td", {}, el("input", {"type": "checkbox", "name": "relogio_ids[]", "value": x.relogio_id, "checked": x.estado !== "proxima",
      "aria-label": t("Vistoria do {1}", {1: x.relogio})}))
    + el("td", {}, el("a", {"href": "ficha.php?id=" + x.relogio_id}, h(x.relogio)))
    + el("td", {}, h(x.vence_texto) + " " + el("small", {"class": classe}, h(x.quando)))
    + el("td", {}, h(x.ultima_texto)));
}

function montar() {
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Vistorias")));
  res += ajudaNota("vistorias", t("Como a vistoria funciona"), t("Com a vistoria ligada em Configuração (a cada quantos dias conferir cada relógio), cada relógio ativo ganha o aviso Vistoria esses dias depois da última vistoria (sem nenhuma, da data da compra; sem ela, do cadastro). É para pegar o relógio e conferir (se funciona, a hora, a bateria ou a pilha, o vidro, a pulseira) antes que algo errado passe despercebido. Registre uma por uma pelo botão em Hoje é dia de, ou aqui, várias de uma vez: os atrasados e os de hoje já vêm marcados."));
  if (!dados.ativa) {
    res += el("p", {"class": "aviso"}, h(dados.motivo)) + el("p", {}, el("a", {"href": "configuracao.php", "class": "botao leve"}, t("Configuração")));
    porHtml(document.getElementById("vistorias"), res);
    return;
  }
  res += el("form", {"id": "filtro-vistorias", "class": "inline filtro", "onsubmit": "return false"},
    el("label", {}, t("Vencendo até {1}", {1: el("input", {"type": "date", "id": "f-ate", "value": filtro.todos ? "" : (dados.ate || ""), "min": dados.hoje})}))
    + " " + el("button", {"type": "button", "class": "leve", "id": "f-ok"}, t("Mostrar"))
    + " " + el("button", {"type": "button", "class": "leve", "id": "f-todos", "disabled": filtro.todos}, t("Todos")));
  var r = dados.resumo;
  res += el("p", {"class": "nota"}, t("Na lista: {1} de {2} relógios. Vistorias atrasadas: {3}. A vistoria vence a cada {4} dias.", {1: r.no_periodo, 2: r.com_vistoria, 3: r.atrasadas, 4: dados.dias}));
  if (dados.relogios.length === 0) {
    res += el("p", {}, filtro.todos ? t("Nenhum relógio ativo com a vistoria.") : t("Nenhuma vistoria vencendo até esse dia."));
    porHtml(document.getElementById("vistorias"), res);
    return;
  }
  res += el("form", {"id": "form-vistorias", "onsubmit": "return false"},
    el("div", {"class": "rolagem"}, el("table", {"class": "tabela vistorias-tabela"},
      el("thead", {}, el("tr", {}, el("th", {}, el("input", {"type": "checkbox", "id": "marcar-todos", "aria-label": t("Marcar todos")}))
        + el("th", {}, t("Relógio")) + el("th", {}, t("Vence")) + el("th", {}, t("Última vistoria"))))
      + el("tbody", {}, dados.relogios.map(linhaVistoria).join(""))))
    + el("div", {"class": "form-grade"}, el("label", {}, t("Dia da vistoria {1}", {1: el("input", {"type": "date", "id": "v-quando", "max": dados.hoje})}))
      + el("p", {"class": "nota"}, t("Vazio: agora. Um dia que passou fica ao meio-dia dele.")))
    + el("div", {"class": "botoes"}, el("button", {"type": "button", "id": "registrar", "disabled": gravando}, gravando ? t("Registrando…") : t("Registrar as vistorias"))));
  porHtml(document.getElementById("vistorias"), res);
}

function carregar() {
  var p = {recurso: "vistorias"};
  if (filtro.todos) {
    p.todos = "1";
  } else if (filtro.ate) {
    p.ate = filtro.ate;
  }
  return api(p).then(function (d) {
    dados = d;
    var q = new URLSearchParams();
    if (filtro.todos) {
      q.set("todos", "1");
    } else if (filtro.ate) {
      q.set("ate", filtro.ate);
    }
    history.replaceState(null, "", "vistorias.php" + (q.toString() ? "?" + q.toString() : ""));
    montar();
  }).catch(function (er) {
    porHtml(document.getElementById("vistorias"), el("p", {"class": "acao"}, t("Não consegui ler as vistorias: {1}", {1: h(er.message)})));
  });
}

function registrar() {
  var ids = Array.prototype.map.call(document.querySelectorAll("#form-vistorias input[name='relogio_ids[]']:checked"), function (x) { return x.value; });
  if (ids.length === 0) {
    avisoFlutuanteGravacao(t("Marque pelo menos um relógio."), true);
    return;
  }
  gravando = true;
  montarBotao();
  enviar({recurso: "vistorias", acao: "registrar", relogio_ids: ids.join(","), quando: document.getElementById("v-quando").value}).then(function (r) {
    gravando = false;
    avisoFlutuanteGravacao(textoResposta(r), !r.ok);
    return r.ok ? carregar() : montarBotao();
  });
}

function montarBotao() {
  var b = document.getElementById("registrar");
  if (b) {
    b.disabled = gravando;
    b.textContent = gravando ? t("Registrando…") : t("Registrar as vistorias");
  }
}

document.addEventListener("click", function (ev) {
  var id = ev.target.id;
  if (id === "f-ok") {
    filtro.ate = document.getElementById("f-ate").value;
    filtro.todos = false;
    carregar();
  } else if (id === "f-todos") {
    filtro.todos = true;
    carregar();
  } else if (id === "registrar") {
    registrar();
  }
});
document.addEventListener("change", function (ev) {
  if (ev.target.id === "marcar-todos") {
    document.querySelectorAll("#form-vistorias input[name='relogio_ids[]']").forEach(function (x) { x.checked = ev.target.checked; });
  }
});

(function () {
  var q = new URLSearchParams(window.location.search);
  filtro.todos = q.get("todos") === "1";
  filtro.ate = q.get("ate") || "";
  carregar();
})();
