// O cadastro aberto: lê recurso=cadastro (sem login) e monta o formulário. Três caminhos pela URL: ?convite=<token> (o
// convite: criar a conta e já ver o que foi convidado, ou entrar e aceitar), ?senha=<token> (a senha nova) e o normal
// (criar a conta, esqueci a senha).
var parametros = new URLSearchParams(window.location.search);
var convite = parametros.get("convite") || "";
var tokenSenha = parametros.get("senha") || "";
var recadoCadastro = null;

aoGravar = function (form, res, dados) {
  recadoCadastro = res;
  if (res.ok && (dados.get("acao") === "criar_conta" || dados.get("acao") === "nova_senha")) {
    // pronto: só falta entrar (o navegador pede o login e a senha)
    document.getElementById("cadastro").innerHTML = el("h1", {}, t("Pronto")) + el("p", {"class": "acao"}, h(res.mensagem))
      + el("div", {"class": "botoes"}, el("a", {"href": "index.php", "class": "botao"}, t("Entrar")));
    return;
  }
  montarCadastro();
};

function recado() {
  if (!recadoCadastro) {
    return "";
  }
  var r = el("p", {"class": recadoCadastro.ok ? "acao" : "alerta-cron"}, h(textoResposta(recadoCadastro)));
  recadoCadastro = null;
  return r;
}

function formEsqueci(d) {
  return el("section", {"class": "cartao-config", "id": "esqueci"}, el("h2", {}, t("Esqueci a senha"))
    + (d.email_configurado ? el("form", {"data-recurso": "cadastro", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": "esqueci_senha"})
      + el("input", {"name": "email", "required": true, "maxlength": "200", "placeholder": t("o e-mail ou o login"), "aria-label": t("E-mail ou login"), "autocomplete": "email"})
      + el("button", {"class": "leve"}, t("Mandar o link")))
      : el("p", {"class": "nota"}, t("O servidor não envia e-mail: peça ao administrador para trocar a sua senha."))));
}

function formConta(d) {
  var linguas = d.linguas.map(function (l) { return el("option", {"value": l.codigo, "selected": l.codigo === LINGUA}, h(l.nome)); }).join("");
  var opcoes = "";
  if (d.convite) {
    opcoes = el("input", {"type": "hidden", "name": "opcao", "value": "nenhuma"}) + el("input", {"type": "hidden", "name": "convite", "value": convite})
      + el("label", {"class": "check"}, el("input", {"type": "checkbox", "onchange": "this.form.opcao.value = this.checked ? 'modelo' : 'nenhuma'"}) + " " + t("Criar também uma coleção minha, com o conjunto inicial"));
  } else {
    opcoes = el("fieldset", {"class": "opcoes-conta"}, el("legend", {}, t("Para começar"))
      + el("label", {}, el("input", {"type": "radio", "name": "opcao", "value": "modelo", "checked": true}) + " " + t("Uma coleção minha, com o conjunto inicial (grupos, campos, fórmulas, avisos e critérios prontos)"))
      + el("label", {}, el("input", {"type": "radio", "name": "opcao", "value": "vazia"}) + " " + t("Uma coleção minha, vazia (eu monto tudo)"))
      + el("label", {}, el("input", {"type": "radio", "name": "opcao", "value": "pedir"}) + " " + t("Pedir acesso à coleção de alguém")))
      + el("div", {"data-pedir": true, "hidden": true}, camposCodigoAcesso(false) + parCampo(t("Recado para o dono (opcional)"), el("input", {"name": "mensagem", "maxlength": "500"}), "largo"));
  }
  return el("section", {"class": "cartao-config"}, el("form", {"data-recurso": "cadastro", "class": "campos", "id": "form-conta",
      "onsubmit": "acessoPorCodigo(this)"},
    el("input", {"type": "hidden", "name": "acao", "value": "criar_conta"})
    + parCampo(t("Login"), el("input", {"name": "login", "required": true, "maxlength": "60", "autocomplete": "username", "pattern": "[^:]+", "title": t("sem dois-pontos")}))
    + parCampo(t("Senha (pelo menos 6 caracteres)"), el("input", {"type": "password", "name": "senha", "required": true, "minlength": "6", "autocomplete": "new-password"}))
    + parCampo(t("E-mail"), el("input", {"type": "email", "name": "email", "required": true, "maxlength": "200", "value": d.convite ? d.convite.email : "", "autocomplete": "email"}), "largo")
    + parCampo(t("Idioma"), el("select", {"name": "idioma"}, linguas))
    + opcoes
    + (d.demo_dias > 0 ? el("p", {"class": "nota"}, t("A conta nova é de demonstração por {1} dias: depois disso ela é apagada, a menos que o administrador a torne oficial. Você recebe um aviso por e-mail antes.", {1: d.demo_dias})) : "")
    + el("div", {"class": "botoes"}, el("button", {}, t("Criar conta")))));
}

function montarCadastro() {
  return api({recurso: "cadastro", convite: convite}).then(function (d) {
    var res = "";
    if (tokenSenha !== "") {
      res = el("h1", {}, t("Senha nova")) + recado() + el("section", {"class": "cartao-config"}, el("form", {"data-recurso": "cadastro", "class": "campos"},
        el("input", {"type": "hidden", "name": "acao", "value": "nova_senha"}) + el("input", {"type": "hidden", "name": "token", "value": tokenSenha})
        + parCampo(t("Senha nova (pelo menos 6 caracteres)"), el("input", {"type": "password", "name": "senha", "required": true, "minlength": "6", "autocomplete": "new-password"}))
        + el("div", {"class": "botoes"}, el("button", {}, t("Trocar a senha")))));
    } else if (convite !== "") {
      res = el("h1", {}, t("Convite")) + recado();
      if (!d.convite) {
        res += el("p", {"class": "alerta-cron"}, t("Este convite não vale mais (vencido, já usado ou cancelado). Peça outro a quem convidou."));
      } else {
        res += el("p", {}, t("Você foi convidado para ver {1}.", {1: el("strong", {}, h(d.convite.alvo))}))
          + el("p", {}, t("Já tem conta? {1}", {1: el("a", {"href": "conta.php?convite=" + encodeURIComponent(convite)}, t("Entre e aceite o convite"))}))
          + (d.convite.tem_conta ? "" : el("h2", {}, t("Ou crie a sua conta")) + formConta(d));
      }
    } else {
      res = el("h1", {}, t("Criar conta")) + recado();
      res += d.aberto ? formConta(d) : el("p", {"class": "alerta-cron"}, t("O cadastro está fechado: só entra quem tem um convite. Fale com o administrador."));
      res += el("p", {}, t("Já tem conta? {1}", {1: el("a", {"href": "index.php"}, t("Entrar"))})) + formEsqueci(d);
    }
    document.getElementById("cadastro").innerHTML = res;
  }).catch(function (e) {
    document.getElementById("cadastro").innerHTML = el("p", {"class": "acao"}, t("Não consegui abrir o cadastro: {1}", {1: h(e.message)}));
  });
}

// "Pedir acesso": mostra o código (obrigatório só nessa opção)
document.addEventListener("change", function (ev) {
  if (ev.target.name === "opcao" && ev.target.type === "radio") {
    var bloco = document.querySelector("[data-pedir]");
    if (bloco) {
      bloco.hidden = ev.target.value !== "pedir";
      bloco.querySelector("[name=codigo]").required = ev.target.value === "pedir";
    }
  }
});

montarCadastro();
