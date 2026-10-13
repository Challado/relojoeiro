// Usuários: a lista da API (recurso=usuarios), no HTML da página do sistema antigo; excluir e salvar gravam pela API.
var erroUsuarios = "";


function recarregarUsuarios() {
  return api({recurso: "usuarios"}).then(function (d) {
    var res = erroUsuarios !== "" ? el("p", {"class": "acao"}, h(erroUsuarios)) : "";
    erroUsuarios = "";
    // dois quadros: quem acessa (a tabela, com o Excluir à direita) e o formulário de criar ou trocar a senha
    res += el("h1", {}, t("Usuários"));
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Quem acessa")) + el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, el("th", {}, t("Login")) + el("th", {}, t("Criado em")) + el("th", {}, "")))
      + el("tbody", {}, d.usuarios.map(function (u) {
        return el("tr", {}, el("td", {}, h(u.login) + (u.login === d.voce ? " " + el("span", {"class": "estado estado-atividade"}, t("você")) : "")) + el("td", {}, dataBr(u.criado))
          + el("td", {"class": "acoes-linha"}, u.login !== d.voce ? el("form", {"data-recurso": "usuarios", "onsubmit": "return confirm(" + JSON.stringify(t("Excluir o usuário {1}?", {1: u.login})) + ")"},
            el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "login", "value": u.login}) + el("button", {}, t("Excluir"))) : ""));
      }).join(""))));
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Criar usuário ou trocar senha")) + el("form", {"data-recurso": "usuarios", "class": "form-grade"},
      el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
      + el("label", {}, t("Login {1}", {1: el("input", {"name": "login", "required": true, "autocomplete": "username"})}))
      + el("label", {}, t("Senha (pelo menos 6 caracteres) {1}", {1: el("input", {"type": "password", "name": "senha", "required": true, "minlength": "6", "autocomplete": "new-password"})}))
      + el("p", {"class": "nota"}, t("Se o login já existir, a senha dele é trocada.")) + el("div", {"class": "botoes"}, el("button", {}, t("Salvar usuário")))));
    document.getElementById("usuarios").innerHTML = res;
  });
}

// como no antigo: gravou, a lista volta limpa; recusado, o erro aparece no topo
aoGravar = function (form, res) {
  erroUsuarios = res.ok ? "" : res.erros.join(" ");
  recarregarUsuarios();
};

recarregarUsuarios().catch(function (e) {
  document.getElementById("usuarios").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os usuários: {1}", {1: h(e.message)}));
});
