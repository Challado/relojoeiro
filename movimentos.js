// Movimentos: a lista da API (recurso=programas, a parte movimentos). Uma tabela (o código, o nome, o tipo, a medida e os
// programas que usam cada um) e o formulário (recurso=movimentos): o nome, o tipo e a medida (o giro em voltas ou em tempo;
// a parada, só o tempo). O código é o que a sequência do programa usa. As explicações ficam na gaveta de ajuda.
var q = new URLSearchParams(window.location.search);
var editar = parseInt(q.get("editar") || "0", 10);

aoGravar = function (form, res) {
  if (!res.ok) {
    alert(textoResposta(res));
    form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
    return;
  }
  if (window.location.search !== "") {
    window.location.href = "movimentos.php";
    return;
  }
  return salvarSemMover(form, res, carregarMovimentos);
};

// o formulário mostra a medida que vale para o tipo: a parada só tem o tempo; o giro, voltas ou tempo (um dos dois)
function ajustarMedida(form) {
  var parado = form.querySelector("[name=tipo]").value === "parado";
  var emVoltas = !parado && form.querySelector("[name=medida]:checked").value === "voltas";
  form.querySelector("[data-medida-escolha]").hidden = parado;
  form.querySelector("[data-so-voltas]").hidden = !emVoltas;
  form.querySelector("[data-so-tempo]").hidden = emVoltas;
  // o que não vale vai vazio (a API não aceita voltas e tempo juntos)
  form.querySelectorAll(emVoltas ? "[data-so-tempo] input" : "[data-so-voltas] input").forEach(function (x) { x.value = ""; });
}

function carregarMovimentos() {
  return api({recurso: "programas"}).then(function (d) {
    var em = null;
    d.movimentos.forEach(function (m) { if (m.id === editar) { em = m; } });
    ajudaGrupo("pagina", t("Movimentos"));
    ondeMuda("movimentos", [[t("Os giros e as paradas (o nome, o tipo, as voltas ou o tempo)"), t("aqui")],
      [t("A sequência de um programa (os códigos, na ordem) e a velocidade do berço"), lugar("programas.php", t("Programas"))],
      [t("O programa de cada posição do winder"), lugar("winders.php", t("Winders"))]]);
    var iMov = ajuda("movimento", t("O que é um movimento"), el("p", {}, t("Um passo de um programa de winder: um giro no sentido horário, um giro no anti-horário ou uma parada. Um programa é a sequência deles, que se repete; cada um serve em quantos programas você quiser (o mesmo código pode entrar mais de uma vez na mesma sequência)."))
      + el("p", {}, t("O código (a primeira coluna) é o que você põe na sequência do programa, na página Programas.")));
    var iMed = ajuda("medida", t("Voltas ou tempo"), el("p", {}, t("O giro diz quantas voltas o berço dá, ou quanto tempo ele gira: um dos dois, nunca os dois. O outro o programa calcula pela velocidade do berço (voltas por minuto). A parada só tem o tempo."))
      + el("p", {}, t("Num programa por voltas, os giros são em voltas; num programa por tempo, em tempo. A parada entra nos dois.")));
    var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Movimentos")) + el("span", {"class": "nota"}, d.movimentos.length === 1 ? t("1 movimento") : t("{1} movimentos", {1: d.movimentos.length})) + ajudaBotao());
    if (d.movimentos.length === 0) {
      res += el("p", {"class": "nota"}, t("Nenhum movimento ainda. Cadastre o primeiro abaixo, ou monte um programa pronto na página {1} (o ajudante cria os movimentos).", {1: lugar("programas.php", t("Programas"))}));
    } else {
      res += el("div", {"class": "rolagem"}, el("table", {"class": "relogios compacta movimentos"}, el("thead", {}, el("tr", {}, el("th", {}, t("Código")) + el("th", {}, t("Nome") + iMov)
        + el("th", {}, t("Tipo")) + el("th", {}, t("Voltas ou tempo") + iMed) + el("th", {}, t("Usado em")) + el("th", {}, "")))
        + el("tbody", {}, d.movimentos.map(function (m) {
          return el("tr", {"class": "tipo-" + m.tipo}, el("td", {"class": "num"}, el("strong", {}, m.id)) + el("td", {}, h(m.nome)) + el("td", {}, h(m.tipo_texto))
            + el("td", {}, h(m.medida_texto))
            + el("td", {}, m.usado_em.length === 0 ? el("span", {"class": "nota"}, t("nenhum programa"))
              : m.usado_em.map(function (g) { return el("a", {"href": "programas.php?editar=" + g.programa_id + "#form"}, h(g.nome)); }).join(", "))
            + el("td", {"class": "acoes-linha"}, el("a", {"class": "botao leve", "href": "movimentos.php?editar=" + m.id + "#form"}, t("Editar"))
              + (m.usado_em.length === 0 ? " " + el("form", {"data-recurso": "movimentos", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"})
                + el("input", {"type": "hidden", "name": "id", "value": m.id}) + el("button", {"class": "leve discreto"}, t("Excluir"))) : "")));
        }).join(""))));
    }
    // o formulário: novo, ou o que se edita
    var tipo = em ? em.tipo : "horario";
    var emVoltas = em ? em.medida === "voltas" : false;
    var seg = em && em.segundos !== null ? em.segundos : null;
    res += el("section", {"class": "cartao-config", "id": "form"}, el("h2", {}, em ? t("Alterar o movimento {1}", {1: em.id}) : t("Novo movimento"))
      + el("form", {"data-recurso": "movimentos", "class": "form-grade", "id": "form-movimento"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (em ? el("input", {"type": "hidden", "name": "id", "value": em.id}) : "")
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": em ? em.nome : "", "required": true, "maxlength": "60", "placeholder": t("Giro horário 2 min")})}))
        + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo"}, d.tipos.map(function (x) { return el("option", {"value": x.valor, "selected": x.valor === tipo}, h(x.nome)); }).join(""))}))
        + el("fieldset", {"class": "escolha", "data-medida-escolha": true}, el("legend", {}, t("O giro é medido em") + iMed)
          + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "medida", "value": "voltas", "checked": emVoltas}) + " " + t("voltas"))
          + el("label", {"class": "check"}, el("input", {"type": "radio", "name": "medida", "value": "tempo", "checked": !emVoltas}) + " " + t("tempo")))
        + el("label", {"data-so-voltas": true}, t("Voltas {1}", {1: el("input", {"name": "voltas", "value": em && em.voltas !== null ? String(em.voltas).replace(".", ",") : "", "inputmode": "decimal", "class": "curto"})}))
        + el("div", {"class": "par-tempo", "data-so-tempo": true}, el("label", {}, t("Minutos {1}", {1: el("input", {"name": "minutos", "value": seg !== null ? Math.floor(seg / 60) : "", "inputmode": "numeric", "class": "curto"})}))
          + el("label", {}, t("Segundos {1}", {1: el("input", {"name": "segundos", "value": seg !== null ? seg % 60 : "", "inputmode": "numeric", "class": "curto"})})))
        + el("div", {"class": "botoes"}, el("button", {}, em ? t("Salvar") : t("Cadastrar")) + (em ? " " + el("a", {"href": "movimentos.php"}, t("cancelar")) : ""))));
    document.getElementById("movimentos").innerHTML = res;
    var form = document.getElementById("form-movimento");
    ajustarMedida(form);
    form.addEventListener("change", function (ev) {
      if (ev.target.name === "tipo" || ev.target.name === "medida") {
        ajustarMedida(form);
      }
    });
    if (em) {
      document.getElementById("form").scrollIntoView();
    }
  }).catch(function (er) {
    document.getElementById("movimentos").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os movimentos: {1}", {1: h(er.message)}));
  });
}
carregarMovimentos();
