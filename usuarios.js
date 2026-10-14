// Usuários: a lista da API (recurso=usuarios), no HTML da página do sistema antigo; excluir e salvar gravam pela API.
var erroUsuarios = "";


function recarregarUsuarios() {
  return api({recurso: "usuarios"}).then(function (d) {
    var res = erroUsuarios !== "" ? el("p", {"class": "acao"}, h(erroUsuarios)) : "";
    erroUsuarios = "";
    res += el("section", {"class": "plano"}, el("h2", {}, "Quem acessa") + el("table", {}, el("thead", {}, el("tr", {}, el("th", {}, "Login") + el("th", {}, "Criado em") + el("th", {}, "")))
      + el("tbody", {}, d.usuarios.map(function (u) {
        return el("tr", {}, el("td", {}, h(u.login) + (u.login === d.voce ? " " + el("small", {}, "você") : "")) + el("td", {}, dataBr(u.criado))
          + el("td", {"class": "acoes"}, u.login !== d.voce ? el("form", {"data-recurso": "usuarios", "onsubmit": "return confirm(" + JSON.stringify("Excluir o usuário " + u.login + "?") + ")"},
            el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "login", "value": u.login}) + el("button", {"class": "leve"}, "Excluir")) : ""));
      }).join(""))));
    res += el("section", {"class": "modo"}, el("h2", {}, "Criar usuário ou trocar senha") + el("form", {"data-recurso": "usuarios", "class": "cadastro"},
      el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
      + el("label", {}, "Login " + el("input", {"name": "login", "required": true, "autocomplete": "username"}))
      + el("label", {}, "Senha " + el("input", {"type": "password", "name": "senha", "required": true, "minlength": "6", "autocomplete": "new-password"}))
      + el("p", {"class": "nota"}, "Se o login já existir, a senha dele é trocada.") + el("div", {"class": "botoes"}, el("button", {}, "Salvar usuário"))));
    document.getElementById("usuarios").innerHTML = res;
  });
}

// como no antigo: gravou, a lista volta limpa; recusado, o erro aparece no topo
aoGravar = function (form, res) {
  erroUsuarios = res.ok ? "" : res.erros.join(" ");
  recarregarUsuarios();
};

recarregarUsuarios().catch(function (e) {
  document.getElementById("usuarios").innerHTML = el("p", {"class": "acao"}, "Não consegui ler os usuários: " + h(e.message));
});
