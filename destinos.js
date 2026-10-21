// Destinos: cada destino da coleção (webhook, e-mail, Web Push) num quadro, com a mensagem padrão, o que recebe e a prévia
// de hoje; e o quadro do destino novo. Lê e grava pela API (recurso=destinos).
var recadoDestinos = "";

aoGravar = function (form, res, dados) {
  if (!res.ok) { return salvarSemMover(form, res, function () {}); }
  recadoDestinos = "";
  return salvarSemMover(form, res, carregarDestinos);
};

function camposWebhook(w, id) {
  var linhaCampo = function (n, v) {
    return el("tr", {}, el("td", {}, el("input", {"name": "campo_nome[]", "value": n, "placeholder": t("nome"), "aria-label": t("Nome do campo")}))
      + el("td", {}, el("input", {"name": "campo_valor[]", "value": v, "placeholder": fixo("{mensagem}"), "aria-label": t("Valor do campo")})));
  };
  var linhaCab = function (c) {
    return el("tr", {}, el("td", {}, el("input", {"name": "cabecalho_nome[]", "value": c ? c.nome : "", "placeholder": fixo("Authorization"), "aria-label": t("Nome do cabeçalho")}))
      + el("td", {}, el("input", {"type": "password", "name": "cabecalho_valor[]", "value": "", "autocomplete": "off", "aria-label": t("Valor do cabeçalho"),
        "placeholder": c && c.preenchido ? t("(guardado; vazio mantém)") : fixo("Bearer ...")})));
  };
  return parCampo(t("Método"), el("select", {"name": "metodo"}, ["GET", "POST", "PUT"].map(function (m) { return el("option", {"value": m, "selected": w.metodo === m}, m); }).join("")))
    + parCampo(t("Endereço"), el("input", {"type": "url", "name": "url", "value": w.url, "required": true, "placeholder": fixo("https://api.telegram.org/bot.../sendMessage")}), "largo")
    + parCampo(t("A mensagem vai"), el("select", {"name": "formato"}, [["query", t("na URL (query)")], ["form", t("como formulário")], ["json", t("como JSON")]].map(function (f) {
      return el("option", {"value": f[0], "selected": w.formato === f[0]}, f[1]);
    }).join("")))
    + el("div", {"class": "sub-bloco"}, el("h4", {}, t("Campos")) + el("p", {"class": "nota"}, t("Cada valor pode usar {titulo} e {mensagem} (a mensagem já montada pelo modelo).")) + el("table", {"class": "compacta"}, el("tbody", {},
      w.campos.map(function (c) { return linhaCampo(c.nome, c.valor); }).join("") + linhaCampo("", "") + linhaCampo("", ""))))
    + el("div", {"class": "sub-bloco"}, el("label", {"for": "cj-" + id}, el("h4", {}, t("Corpo JSON (opcional, só no formato JSON)")))
      + el("textarea", {"id": "cj-" + id, "name": "corpo_json", "rows": "3", "placeholder": fixo("{\"chat_id\": \"123\", \"text\": \"{mensagem}\"}")}, h(w.corpo_json || ""))
      + el("p", {"class": "nota"}, t("Vazio: os campos acima viram o objeto JSON. Os marcadores {titulo} e {mensagem} vão dentro das aspas.")))
    + el("div", {"class": "sub-bloco"}, el("h4", {}, t("Cabeçalhos")) + el("p", {"class": "nota"}, t("Guardados cifrados (podem ter um token); nunca voltam para a tela.")) + el("table", {"class": "compacta"}, el("tbody", {},
      w.cabecalhos.map(linhaCab).join("") + linhaCab(null))));
}

function quadroDestino(x, d) {
  var id = x.id;
  var tipoCampos = "";
  if (x.tipo === "webhook") {
    tipoCampos = camposWebhook(x.webhook, id);
  } else if (x.tipo === "email") {
    tipoCampos = parCampo(t("Para (um ou mais, separados por vírgula)"), el("input", {"name": "para", "value": x.email.para, "required": true}), "largo")
      + (d.email.configurado ? el("p", {"class": "nota"}, d.email.proprio ? t("Os e-mails desta coleção saem pelo servidor de e-mail próprio do dono dela (Minha conta), de {1}.", {1: h(d.email.remetente)})
        : t("Os e-mails desta coleção saem pelo servidor do sistema, de {1}. O dono dela pode usar o servidor dele (o Gmail, o Outlook) em Minha conta.", {1: h(d.email.remetente)}))
        : el("p", {"class": "alerta-cron"}, t("O envio de e-mail não está configurado no servidor (EMAIL_METODO no config.php) nem o dono desta coleção tem um servidor próprio (Minha conta): nada sai por aqui até lá.")));
  } else {
    tipoCampos = parCampo(t("Para os aparelhos de"), el("select", {"name": "usuario_id"}, d.usuarios.map(function (u) {
      return el("option", {"value": u.id, "selected": x.webpush.usuario_id === u.id}, h(u.login));
    }).join("")))
      + el("p", {"class": "nota"}, t("{1} aparelho(s) inscrito(s). Para inscrever este navegador, entre como esse usuário e ligue as notificações em {2}.", {1: x.webpush.aparelhos, 2: el("a", {"href": "conta.php#push"}, t("Minha conta"))}))
      + (d.push.configurado ? "" : el("p", {"class": "alerta-cron"}, t("O Web Push não está configurado no servidor (VAPID_PUBLICA e VAPID_PRIVADA no config.php).")));
  }
  var idm = "modelo-" + id;
  var form = el("form", {"data-recurso": "destinos", "class": "form-destino"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": id})
    + el("div", {"class": "campos"}, parCampo(t("Nome"), el("input", {"name": "nome", "value": x.nome, "required": true, "maxlength": "80"}))
      + parCampo(t("Ligado"), el("input", {"type": "hidden", "name": "ativo", "value": "0"}) + el("input", {"type": "checkbox", "name": "ativo", "value": "1", "checked": x.ativo}), "check")
      + parCampo(t("Idioma"), el("select", {"name": "idioma"}, el("option", {"value": "", "selected": !x.idioma}, t("O da coleção")) + d.linguas.map(function (l) {
        return el("option", {"value": l.codigo, "selected": x.idioma === l.codigo}, h(l.nome));
      }).join("")))
      + parCampo(t("Fuso horário (das horas no texto)"), el("select", {"name": "fuso"}, opcoesFuso(d.fusos, x.fuso, t("O da coleção"))))
      + tipoCampos)
    + el("div", {"class": "campo-modelo"}, el("div", {"class": "rotulo-modelo"}, el("label", {"for": idm}, el("h3", {}, t("Mensagem padrão"))) + listaAncoras(d.ancoras, idm, t("Inserir âncora na mensagem padrão")))
      + el("textarea", {"id": idm, "name": "modelo", "rows": "4", "required": true}, h(x.modelo))
      + el("p", {"class": "nota"}, t("Cada item vira uma mensagem com este texto (ou com o personalizado dele, abaixo); os do mesmo horário vão juntos, separados por uma linha em branco.")))
    + el("h3", {}, t("O que recebe")) + tabelaRecebe("d" + id, x.recebe, d.ancoras, true)
    + el("div", {"class": "botoes"}, el("button", {}, t("Salvar"))));
  var acoes = el("div", {"class": "botoes"}, ["texto", "manha", "noite"].map(function (q) {
    return el("form", {"data-recurso": "destinos", "class": "inline"}, el("input", {"type": "hidden", "name": "acao", "value": "testar"}) + el("input", {"type": "hidden", "name": "id", "value": id})
      + el("input", {"type": "hidden", "name": "qual", "value": q}) + el("button", {"class": "leve"}, {"texto": t("Mandar um teste"), "manha": t("Mandar a da manhã agora"), "noite": t("Mandar a da noite agora")}[q]));
  }).join(" ") + " " + el("form", {"data-recurso": "destinos", "class": "inline"},
    el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": id}) + el("button", {"class": "leve discreto"}, t("Excluir"))));
  var previa = el("div", {"class": "config-grade"}, el("div", {"class": "previa-msg"}, el("h4", {}, t("De manhã, hoje")) + el("pre", {}, x.previa.manha !== "" ? h(x.previa.manha) : t("(nada a enviar)")))
    + el("div", {"class": "previa-msg"}, el("h4", {}, t("À noite, hoje")) + el("pre", {}, x.previa.noite !== "" ? h(x.previa.noite) : t("(nada a enviar)"))));
  return el("section", {"class": "cartao-config" + (x.ativo ? "" : " inativo"), "id": "destino-" + id}, el("h2", {}, h(x.nome) + " " + el("span", {"class": "nota"}, h(x.tipo_nome)))
    + form + el("h3", {}, t("Como sai hoje")) + previa + acoes);
}

function carregarDestinos() {
  return api({recurso: "destinos"}).then(function (d) {
    var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Destinos das mensagens")) + el("span", {"class": "nota"}, el("a", {"href": "avisos.php#para-onde"}, t("← Avisos")) + " · " + el("a", {"href": "agendas.php"}, t("Agendas"))));
    if (recadoDestinos !== "") {
      res += el("p", {"class": "acao"}, h(recadoDestinos));
      recadoDestinos = "";
    }
    res += el("p", {"class": "nota"}, t("As mensagens da manhã (o relógio do dia e os avisos) e da noite (preparar o de amanhã), os avisos na hora e os eventos saem por cada destino ligado, com o que ele recebe. As âncoras entre chaves, como {relogio}, viram os valores na hora de enviar."));
    res += d.destinos.map(function (x) { return quadroDestino(x, d); }).join("");
    if (d.destinos.length === 0) {
      res += el("p", {"class": "alerta-cron"}, t("Nenhum destino ainda: as mensagens não saem para lugar nenhum."));
    }
    // o destino novo: o tipo e o essencial; depois de criado, o quadro dele tem o resto
    res += el("section", {"class": "cartao-config", "id": "novo"}, el("h2", {}, t("Novo destino"))
      + el("form", {"data-recurso": "destinos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": "0"})
        + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo", "id": "novo-tipo"}, d.tipos.map(function (x) { return el("option", {"value": x.tipo}, h(x.nome)); }).join(""))}))
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "required": true, "maxlength": "80", "placeholder": fixo("Telegram")})}))
        + el("label", {"data-sub": "webhook"}, t("Endereço do webhook {1}", {1: el("input", {"type": "url", "name": "url", "placeholder": fixo("https://...")})}))
        + el("label", {"data-sub": "email", "hidden": true}, t("Para {1}", {1: el("input", {"name": "para", "placeholder": t("voce@exemplo.com")})}))
        + el("div", {"class": "botoes"}, el("button", {}, t("Criar")))));
    document.getElementById("destinos").innerHTML = res;
  });
}

document.addEventListener("change", function (ev) {
  if (ev.target.id === "novo-tipo") {
    document.querySelectorAll("#novo [data-sub]").forEach(function (e) { e.hidden = e.dataset.sub !== ev.target.value; });
  }
});

carregarDestinos().catch(function (e) {
  document.getElementById("destinos").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os destinos: {1}", {1: h(e.message)}));
});
