// Aprovações: lê recurso=aprovacoes e monta os quadros; grava em recurso=pedidos, convites e acessos.
var recadoAprov = "";
var linkConvite = null;

aoGravar = function (form, res, dados) {
  recadoAprov = textoResposta(res);
  linkConvite = res.link || null;
  carregarAprovacoes().then(function () {
    window.scrollTo(0, 0);
  });
};

function botaoAcao(recurso, acao, campos, texto, classe) {
  return el("form", {"data-recurso": recurso, "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": acao})
    + Object.keys(campos).map(function (k) { return el("input", {"type": "hidden", "name": k, "value": campos[k]}); }).join("")
    + el("button", {"class": classe || "leve"}, texto));
}

function tabela(titulos, linhas) {
  return el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, titulos.map(function (x) { return el("th", {}, x); }).join("")))
    + el("tbody", {}, linhas)));
}

function carregarAprovacoes() {
  return api({recurso: "aprovacoes"}).then(function (d) {
    var res = el("h1", {}, t("Aprovações e convites")) + (recadoAprov !== "" ? el("p", {"class": "acao"}, h(recadoAprov)) : "")
      + (linkConvite ? el("p", {"class": "token-novo"}, h(linkConvite)) : "");
    recadoAprov = "";
    linkConvite = null;
    // os pedidos de acesso
    res += el("section", {"class": "cartao-config", "id": "pedidos"}, el("h2", {}, t("Pedidos de acesso"))
      + (d.pedidos.length === 0 ? el("p", {"class": "nota"}, t("Nenhum pedido esperando."))
        : tabela([t("Quem"), t("Pede"), t("Recado"), t("Vence em"), ""], d.pedidos.map(function (p) {
          return el("tr", {}, el("td", {}, h(p.login) + (p.email ? el("br") + el("small", {"class": "nota"}, h(p.email)) : "")) + el("td", {}, h(p.alvo)) + el("td", {}, h(p.mensagem || "")) + el("td", {}, dataBr(p.expira))
            + el("td", {"class": "acoes-linha"}, botaoAcao("pedidos", "aprovar", {id: p.id}, t("Aprovar"), "") + botaoAcao("pedidos", "recusar", {id: p.id}, t("Recusar"), "leve perigo")));
        }).join(""))));
    // os convites de membros, esperando o dono
    if (d.convites_a_aprovar.length > 0) {
      res += el("section", {"class": "cartao-config", "id": "convites-aprovar"}, el("h2", {}, t("Convites esperando a sua aprovação"))
        + tabela([t("E-mail"), t("Quem convidou"), t("Para"), t("Vence em"), ""], d.convites_a_aprovar.map(function (v) {
          return el("tr", {}, el("td", {}, h(v.email)) + el("td", {}, h(v.por)) + el("td", {}, h(v.alvo)) + el("td", {}, dataBr(v.expira))
            + el("td", {"class": "acoes-linha"}, botaoAcao("convites", "aprovar", {id: v.id}, t("Aprovar e enviar"), "") + botaoAcao("convites", "recusar", {id: v.id}, t("Recusar"), "leve perigo")));
        }).join("")));
    }
    // convidar
    var alvos = d.pode_convidar.map(function (c) { return el("option", {"value": "c:" + c.id}, h(c.papel === "dono" ? c.nome : t("{1} (de {2})", {1: c.nome, 2: c.dono}))); }).join("")
      + (d.minhas_colecoes.length > 0 ? el("option", {"value": "d:eu"}, t("Tudo meu (todas as minhas coleções, também as que eu criar)")) : "");
    res += el("section", {"class": "cartao-config", "id": "convidar"}, el("h2", {}, t("Convidar"))
      + ajudaNota("convite", t("Como funciona o convite"), t("O convite vai por e-mail com um link (vale {1} dias). Quem não tem conta cria uma pelo link; quem tem, entra e aceita. O convite de um membro espera o dono aprovar.", {1: d.convite_dias}))
      + (d.email_configurado ? "" : el("p", {"class": "alerta-cron"}, t("O servidor não envia e-mail: o link aparece aqui, para você mandar.")))
      + el("form", {"data-recurso": "convites", "class": "campos", "data-dono": CONTA_LOGIN, "onsubmit": "var v = this.alvo.value.split(':'); this.colecao_id.value = v[0] === 'c' ? v[1] : ''; this.dono.value = v[0] === 'd' ? this.getAttribute('data-dono') : ''"},
        el("input", {"type": "hidden", "name": "acao", "value": "convidar"}) + el("input", {"type": "hidden", "name": "colecao_id", "value": ""}) + el("input", {"type": "hidden", "name": "dono", "value": ""})
        + parCampo(t("E-mail"), el("input", {"type": "email", "name": "email", "required": true, "maxlength": "200"}), "largo")
        + parCampo(t("Para ver"), el("select", {"name": "alvo", "required": true}, alvos), "largo")
        + el("div", {"class": "botoes"}, el("button", {}, t("Convidar")))));
    if (d.convites_feitos.length > 0) {
      res += el("section", {"class": "cartao-config", "id": "convites-feitos"}, el("h2", {}, t("Convites que você fez"))
        + tabela([t("E-mail"), t("Para"), t("Situação"), t("Vence em"), ""], d.convites_feitos.map(function (v) {
          return el("tr", {}, el("td", {}, h(v.email)) + el("td", {}, h(v.alvo)) + el("td", {}, v.estado === "aprovado" ? t("enviado") : t("esperando o dono")) + el("td", {}, dataBr(v.expira))
            + el("td", {"class": "acoes-linha"}, (v.estado === "aprovado" ? botaoAcao("convites", "reenviar", {id: v.id}, t("Reenviar")) : "") + botaoAcao("convites", "cancelar", {id: v.id}, t("Cancelar"), "leve perigo")));
        }).join("")));
    }
    // quem vê as minhas coleções
    var linhas = [];
    d.acessos.colecoes.forEach(function (c) {
      c.membros.forEach(function (m) {
        linhas.push(el("tr", {}, el("td", {}, h(m.login)) + el("td", {}, h(c.nome)) + el("td", {}, dataBr(m.desde))
          + el("td", {"class": "acoes-linha"}, botaoAcao("acessos", "remover", {usuario_id: m.usuario_id, colecao_id: c.id}, t("Tirar o acesso"), "leve perigo"))));
      });
    });
    d.acessos.tudo.forEach(function (m) {
      linhas.push(el("tr", {}, el("td", {}, h(m.login)) + el("td", {}, t("todas as suas coleções")) + el("td", {}, dataBr(m.desde))
        + el("td", {"class": "acoes-linha"}, botaoAcao("acessos", "remover", {usuario_id: m.usuario_id, dono: CONTA_LOGIN}, t("Tirar o acesso"), "leve perigo"))));
    });
    res += el("section", {"class": "cartao-config", "id": "acessos"}, el("h2", {}, t("Quem vê as suas coleções"))
      + (linhas.length === 0 ? el("p", {"class": "nota"}, t("Só você.")) : tabela([t("Quem"), t("Vê"), t("Desde"), ""], linhas.join(""))));
    document.getElementById("aprovacoes").innerHTML = res;
  });
}

// o login de quem entrou (o "tudo meu" cita o dono pelo login): vem do menu
var CONTA_LOGIN = "";
contaPronta.then(function (m) {
  CONTA_LOGIN = m && m.usuario ? m.usuario.login : "";
  return carregarAprovacoes();
}).catch(function (e) {
  document.getElementById("aprovacoes").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler as aprovações: {1}", {1: h(e.message)}));
});
