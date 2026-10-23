// Agendas: cada agenda da coleção (Google, CalDAV, Outlook, link ICS) num quadro, com o modelo do evento, o que recebe, a
// prévia (o que tem de estar nela agora) e os botões (sincronizar, testar); e o quadro da agenda nova. Lê e grava pela API
// (recurso=agendas); conectar ao Outlook leva à página da Microsoft, que volta para api.php?recurso=outlook_retorno.
var recadoAgendas = new URLSearchParams(window.location.search).get("recado") || "";

aoGravar = function (form, res, dados) {
  if (dados.get("acao") === "outlook_conectar" && res.ok && res.url) {
    window.location.href = res.url;
    return;
  }
  if (!res.ok) { return salvarSemMover(form, res, function () {}); }
  recadoAgendas = "";
  return salvarSemMover(form, res, carregarAgendas);
};

function botaoAgenda(id, acao, rotulo, confirma, extra) {
  return el("form", {"data-recurso": "agendas", "class": "inline", "onsubmit": confirma ? "return confirm(" + JSON.stringify(confirma) + ")" : null},
    el("input", {"type": "hidden", "name": "acao", "value": acao}) + el("input", {"type": "hidden", "name": "id", "value": id}) + el("button", {"class": "leve" + (extra ? " " + extra : "")}, rotulo));
}

function quadroAgenda(x, d) {
  var id = x.id;
  var campos = "";
  if (x.tipo === "google") {
    campos = parCampo(t("ID da agenda"), el("input", {"name": "calendario_id", "value": x.google.calendario_id, "required": true, "placeholder": t("xxxx@group.calendar.google.com")}), "largo")
      + el("label", {"class": "par-campo largo"}, el("span", {"class": "rotulo"}, t("Chave da conta de serviço (o JSON, ou o caminho dele no servidor)"))
        + el("span", {"class": "controle"}, el("textarea", {"name": "chave_json", "rows": "3", "placeholder": x.google.chave !== "nenhuma" ? t("(guardada; vazio mantém)") : (d.pasta ? d.pasta + "/google-conta-servico.json" : t("cole aqui o JSON da chave"))}, "")))
      + (x.google.conta_servico ? el("p", {"class": "nota"}, t("Compartilhe a agenda com {1}, com permissão para fazer alterações nos eventos.", {1: el("strong", {"class": "quebra"}, h(x.google.conta_servico))}))
        : x.google.caminho ? el("p", {"class": "nota"}, t("A chave é lida de {1} (o usuário do PHP precisa ler o arquivo).", {1: el("code", {}, h(x.google.caminho))})) : "");
  } else if (x.tipo === "caldav") {
    campos = parCampo(t("Endereço do calendário"), el("input", {"type": "url", "name": "url", "value": x.caldav.url, "required": true, "placeholder": t("https://nuvem.exemplo.com/remote.php/dav/calendars/voce/relogios/")}), "largo")
      + parCampo(t("Usuário"), el("input", semPreencher({"name": "usuario", "value": x.caldav.usuario, "required": true})))
      + parCampo(t("Senha"), el("input", semPreencher({"type": "password", "name": "senha", "placeholder": x.caldav.senha_preenchida ? t("(guardada; vazio mantém)") : ""})))
      + ajudaNota("caldav", t("Como achar o endereço CalDAV"), t("Nextcloud: Calendário › ⋯ › Copiar o link CalDAV. iCloud: o endereço do calendário em caldav.icloud.com e uma senha de app (appleid.apple.com › Senhas de app). Fastmail: caldav.fastmail.com, com uma senha de app."));
  } else if (x.tipo === "outlook") {
    campos = parCampo(t("Conta Microsoft"), el("span", {}, x.outlook.conectada ? h(x.outlook.conta || t("conectada")) : t("não conectada")))
      + parCampo(t("Calendário (o id; vazio: o principal)"), el("input", {"name": "calendario_id", "value": x.outlook.calendario_id || ""}), "largo")
      + (x.outlook.configurado ? el("p", {"class": "nota"}, t("No aplicativo registrado na Microsoft, o endereço de retorno é {1}.", {1: el("code", {"class": "quebra"}, h(x.outlook.retorno))}))
        : el("p", {"class": "alerta-cron"}, t("O Outlook não está configurado no servidor (OUTLOOK_CLIENTE_ID e OUTLOOK_CLIENTE_SEGREDO no config.php). Sem isso, use um link ICS: o Outlook assina o link e mostra os eventos.")));
  } else {
    campos = parCampo(t("Link para assinar"), el("input", {"value": x.ics.link || "", "readonly": true, "class": "copiar", "onclick": "this.select()"}), "largo")
      + ajudaNota("ics", t("Como assinar o link ICS"), t("Google Agenda: Outras agendas › + › Do URL. Outlook: Adicionar calendário › Assinar da Web. iPhone: Ajustes › Calendário › Contas › Adicionar › Outra › Calendário assinado. Quem tem o link vê os eventos: trocar o link desliga o de antes."));
  }
  var idm = "amodelo-" + id;
  var form = el("form", {"data-recurso": "agendas"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": id})
    + el("div", {"class": "campos"}, parCampo(t("Nome"), el("input", {"name": "nome", "value": x.nome, "required": true, "maxlength": "80"}))
      + parCampo(t("Ligada"), el("input", {"type": "hidden", "name": "ativa", "value": "0"}) + el("input", {"type": "checkbox", "name": "ativa", "value": "1", "checked": x.ativa}), "check")
      + parCampo(t("Eventos até quantos dias à frente"), el("input", {"type": "number", "name": "janela_dias", "min": "1", "max": "365", "value": x.janela_dias}))
      + parCampo(t("Idioma"), el("select", {"name": "idioma"}, el("option", {"value": "", "selected": !x.idioma}, t("O da coleção")) + d.linguas.map(function (l) {
        return el("option", {"value": l.codigo, "selected": x.idioma === l.codigo}, h(l.nome));
      }).join("")))
      + parCampo(t("Fuso horário (das horas no texto)"), el("select", {"name": "fuso"}, opcoesFuso(d.fusos, x.fuso, t("O da coleção"))))
      + campos)
    + el("div", {"class": "campo-modelo"}, el("div", {"class": "rotulo-modelo"}, el("label", {"for": idm}, el("h3", {}, t("Modelo do evento"))) + listaAncoras(d.ancoras, idm, t("Inserir âncora no modelo do evento")))
      + el("textarea", {"id": idm, "name": "modelo", "rows": "5", "required": true}, h(x.modelo))
      + el("p", {"class": "nota"}, t("A primeira linha é o título do evento; o resto, a descrição.")))
    + el("h3", {}, t("O que recebe")) + tabelaRecebe("a" + id, x.recebe, d.ancoras, true)
    + el("div", {"class": "botoes"}, el("button", {}, t("Salvar"))));
  var acoes = el("div", {"class": "botoes"}, (x.tipo !== "ics" ? botaoAgenda(id, "sincronizar", t("Sincronizar agora")) + " " + botaoAgenda(id, "teste_criar", t("Criar evento de teste"))
    + " " + (x.teste_id ? botaoAgenda(id, "teste_remover", t("Remover evento de teste")) : "") : botaoAgenda(id, "novo_link", t("Trocar o link"), t("O link de agora para de funcionar. Trocar?")))
    + (x.tipo === "outlook" && x.outlook.configurado ? " " + botaoAgenda(id, "outlook_conectar", x.outlook.conectada ? t("Conectar de novo") : t("Conectar ao Outlook")) : "")
    + " " + botaoAgenda(id, "excluir", t("Excluir"), null, "discreto"));
  var previa = el("div", {"class": "previa-msg"}, x.previa.map(function (a) {
    return el("div", {"class": "previa-evento"}, el("div", {"class": "quando"}, dataBr(a.data, true) + " " + h(a.hora)) + el("strong", {}, h(a.titulo)) + (a.descricao !== "" ? el("pre", {}, h(a.descricao)) : ""));
  }).join("") + (x.previa.length === 0 ? el("p", {"class": "nota"}, t("Nada previsto no período.")) : "")
    + el("p", {"class": "nota"}, x.tipo === "ics" ? t("{1} eventos no link agora.", {1: x.previstos}) : t("{1} eventos previstos; {2} já criados pelo sistema nesta agenda.", {1: x.previstos, 2: x.sincronizados})));
  return el("section", {"class": "cartao-config" + (x.ativa ? "" : " inativo"), "id": "agenda-" + id}, el("h2", {}, h(x.nome) + " " + el("span", {"class": "nota"}, h(x.tipo_nome)))
    + form + el("h3", {}, t("O que tem de estar nela agora")) + previa + acoes);
}

function carregarAgendas() {
  return api({recurso: "agendas"}).then(function (d) {
    var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Agendas")) + el("span", {"class": "nota"}, el("a", {"href": "avisos.php#para-onde"}, t("← Avisos")) + " · " + el("a", {"href": "destinos.php"}, t("Destinos"))));
    if (recadoAgendas !== "") {
      res += el("p", {"class": "acao"}, h(recadoAgendas));
      recadoAgendas = "";
      history.replaceState(null, "", "agendas.php" + window.location.hash);
    }
    res += ajudaNota("o-que-vai", t("O que vai para a agenda"), t("Os avisos, o relógio do dia, a véspera e os eventos viram eventos de agenda, no horário da manhã do dia previsto. O cron sincroniza de manhã e à noite; o link ICS o app de agenda lê quando quiser."));
    res += d.agendas.map(function (x) { return quadroAgenda(x, d); }).join("");
    res += el("section", {"class": "cartao-config", "id": "nova"}, el("h2", {}, t("Nova agenda"))
      + el("form", {"data-recurso": "agendas", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "id", "value": "0"})
        + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo", "id": "nova-tipo"}, d.tipos.map(function (x) { return el("option", {"value": x.tipo, "selected": x.tipo === "ics"}, h(x.nome)); }).join(""))}))
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "required": true, "maxlength": "80", "placeholder": t("Minha agenda")})}))
        + el("label", {"data-sub": "google", "hidden": true}, t("ID da agenda do Google {1}", {1: el("input", {"name": "calendario_id"})}))
        + el("label", {"data-sub": "caldav", "hidden": true}, t("Endereço do calendário CalDAV {1}", {1: el("input", {"type": "url", "name": "url"})}))
        + el("label", {"data-sub": "caldav", "hidden": true}, t("Usuário {1}", {1: el("input", semPreencher({"name": "usuario"}))}))
        + el("label", {"data-sub": "caldav", "hidden": true}, t("Senha {1}", {1: el("input", semPreencher({"type": "password", "name": "senha"}))}))
        + el("div", {"class": "botoes"}, el("button", {}, t("Criar")))));
    document.getElementById("agendas").innerHTML = res;
    if (window.location.hash) {
      var alvo = document.querySelector(window.location.hash);
      if (alvo) {
        alvo.scrollIntoView();
      }
    }
  });
}

document.addEventListener("change", function (ev) {
  if (ev.target.id === "nova-tipo") {
    document.querySelectorAll("#nova [data-sub]").forEach(function (e) { e.hidden = e.dataset.sub !== ev.target.value; });
  }
});

carregarAgendas().catch(function (e) {
  document.getElementById("agendas").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler as agendas: {1}", {1: h(e.message)}));
});
