// Usuários: o painel do master (recurso=usuarios). Cada conta numa linha, com o que ela tem; o botão "Ações" abre as
// ações numa linha embaixo dela, da largura da tabela; embaixo, criar uma conta. Quem não é o master vê só a si mesmo e o caminho para Minha conta.
var recadoUsuarios = "";

aoGravar = function (form, res) {
  recadoUsuarios = textoResposta(res);
  recarregarUsuarios().then(function () {
    window.scrollTo(0, 0);
  });
};

function acaoUsuario(u, acao, campos, botao, classe) {
  return el("form", {"data-recurso": "usuarios", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": acao}) + el("input", {"type": "hidden", "name": "usuario_id", "value": u.id})
    + (campos || "") + el("button", {"class": classe || "leve"}, botao));
}

function acoesDoUsuario(u, d) {
  var idiomas = el("option", {"value": "", "selected": !u.idioma}, t("O da coleção")) + (window.TEXTOS_API ? TEXTOS_API.linguas : []).map(function (l) {
    return el("option", {"value": l.codigo, "selected": u.idioma === l.codigo}, h(l.nome));
  }).join("");
  var outras = d.usuarios.filter(function (x) { return x.id !== u.id; });
  var res = "";
  if (u.login !== d.voce) {
    res += el("button", {"type": "button", "class": "leve", "onclick": "verComo(" + JSON.stringify(u.login) + ")"}, t("Ver como {1}", {1: h(u.login)}));
  }
  if (u.papel !== "master") {
    res += u.demo ? acaoUsuario(u, "oficializar", "", t("Tornar oficial")) : acaoUsuario(u, "voltar_demo", el("input", {"type": "number", "name": "dias", "min": "1", "max": "3650", "value": d.demo_dias || 30, "aria-label": t("Dias")}), t("Voltar a demo (dias)"));
    res += acaoUsuario(u, "prorrogar", el("input", {"type": "number", "name": "dias", "min": "1", "max": "3650", "value": "30", "aria-label": t("Dias")}), t("Prorrogar (dias)"));
  }
  if (u.login !== d.voce) {
    res += acaoUsuario(u, "bloquear", el("input", {"type": "hidden", "name": "bloqueado", "value": u.bloqueado ? "0" : "1"}), u.bloqueado ? t("Desbloquear") : t("Bloquear"));
  }
  res += el("h4", {}, t("Alterar"))
    + el("form", {"data-recurso": "usuarios", "class": "campos"}, el("input", {"type": "hidden", "name": "acao", "value": "alterar"}) + el("input", {"type": "hidden", "name": "usuario_id", "value": u.id})
      + parCampo(t("Login"), el("input", {"name": "novo_login", "value": u.login, "maxlength": "60"}))
      + parCampo(t("E-mail"), el("input", {"type": "email", "name": "email", "value": u.email || "", "maxlength": "200"}))
      + parCampo(t("Idioma"), el("select", {"name": "idioma"}, idiomas))
      + parCampo(t("Fuso horário"), el("select", {"name": "fuso"}, opcoesFuso(d.fusos, u.fuso, t("O da coleção"), t("O do navegador dele"))))
      + parCampo(t("Papel"), el("select", {"name": "papel"}, el("option", {"value": "comum", "selected": u.papel === "comum"}, t("comum")) + el("option", {"value": "master", "selected": u.papel === "master"}, t("master"))))
      + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, t("Salvar"))));
  res += el("form", {"data-recurso": "usuarios", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + el("input", {"type": "hidden", "name": "login", "value": u.login})
    + el("input", {"type": "password", "name": "senha", "minlength": "6", "required": true, "autocomplete": "new-password", "placeholder": t("senha nova"), "aria-label": t("Senha nova")})
    + el("button", {"class": "leve"}, t("Trocar a senha")));
  if (u.colecoes.length > 0 && outras.length > 0) {
    res += el("form", {"data-recurso": "usuarios", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": "transferir"}) + el("input", {"type": "hidden", "name": "usuario_id", "value": u.id})
      + el("select", {"name": "colecao_id", "aria-label": t("Coleção")}, u.colecoes.map(function (c) { return el("option", {"value": c.id}, h(c.nome)); }).join(""))
      + el("select", {"name": "para", "aria-label": t("Novo dono")}, outras.map(function (x) { return el("option", {"value": x.login}, h(x.login)); }).join(""))
      + el("button", {"class": "leve"}, t("Transferir a coleção")));
  }
  var alvos = d.colecoes.filter(function (c) { return c.dono_id !== u.id; }).map(function (c) { return el("option", {"value": "c:" + c.id}, h(t("{1} (de {2})", {1: c.nome, 2: c.dono}))); }).join("")
    + outras.filter(function (x) { return x.colecoes.length > 0; }).map(function (x) { return el("option", {"value": "d:" + x.id}, h(t("Tudo de {1}", {1: x.login}))); }).join("");
  if (alvos !== "") {
    res += el("form", {"data-recurso": "usuarios", "class": "form-linha", "onsubmit": "var v = this.alvo.value.split(':'); this.colecao_id.value = v[0] === 'c' ? v[1] : ''; this.dono_id.value = v[0] === 'd' ? v[1] : ''"},
      el("input", {"type": "hidden", "name": "acao", "value": "dar_acesso"}) + el("input", {"type": "hidden", "name": "usuario_id", "value": u.id})
      + el("input", {"type": "hidden", "name": "colecao_id", "value": ""}) + el("input", {"type": "hidden", "name": "dono_id", "value": ""})
      + el("select", {"name": "alvo", "aria-label": t("Acesso a")}, alvos) + el("button", {"class": "leve"}, t("Dar acesso")));
  }
  if (u.login !== d.voce) {
    res += acaoUsuario(u, "excluir", "", t("Excluir"), "leve perigo");
  }
  return el("div", {"class": "acoes-usuario"}, res);
}

// abre e fecha a linha das ações de uma conta (a linha logo abaixo da do botão)
function abrirAcoesUsuario(botao) {
  var linha = botao.closest("tr").nextElementSibling;
  linha.hidden = !linha.hidden;
  botao.setAttribute("aria-expanded", linha.hidden ? "false" : "true");
}

function recarregarUsuarios() {
  return api({recurso: "usuarios"}).then(function (d) {
    var res = el("h1", {}, t("Usuários")) + (recadoUsuarios !== "" ? el("p", {"class": "acao"}, h(recadoUsuarios)) : "");
    recadoUsuarios = "";
    if (d.usuarios.length === 0 || d.usuarios[0].id === undefined) {
      document.getElementById("usuarios").innerHTML = res + el("p", {}, t("Só o administrador (master) cuida das contas. As suas coisas ficam em {1}.", {1: el("a", {"href": "conta.php"}, t("Minha conta"))}));
      return;
    }
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Contas ({1})", {1: d.usuarios.length}))
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Login")) + el("th", {}, t("E-mail")) + el("th", {}, t("Criada em"))
        + el("th", {}, t("Último acesso")) + el("th", {}, t("Vence")) + el("th", {}, t("Coleções")) + el("th", {}, t("Vê também")) + el("th", {}, "")))
        + el("tbody", {}, d.usuarios.map(function (u) {
          var selos = (u.papel === "master" ? el("span", {"class": "selo master"}, t("master")) : "") + (u.demo ? el("span", {"class": "selo demo"}, t("demo")) : "")
            + (u.bloqueado ? el("span", {"class": "selo bloqueado"}, t("bloqueada")) : "") + (u.login === d.voce ? " " + el("span", {"class": "estado estado-atividade"}, t("você")) : "");
          var ve = u.membro_de.map(function (c) { return h(t("{1} (de {2})", {1: c.nome, 2: c.dono})); }).concat(u.tudo_de.map(function (l) { return h(t("tudo de {1}", {1: l})); }));
          return el("tr", {}, el("td", {}, h(u.login) + selos) + el("td", {}, h(u.email || "—")) + el("td", {}, dataBr(u.criado))
            + el("td", {}, u.ultimo_acesso ? dataBr(u.ultimo_acesso) + " " + horaBr(u.ultimo_acesso) : "—")
            + el("td", {}, u.expira_em ? dataBr(u.expira_em) + " " + el("small", {"class": "nota"}, t("({1} dias)", {1: u.dias_restantes})) : t("não vence"))
            + el("td", {}, u.colecoes.map(function (c) { return h(c.nome) + " " + el("small", {"class": "nota"}, t("({1} relógios)", {1: c.relogios})); }).join(el("br")) || "—")
            + el("td", {}, ve.join(el("br")) || "—")
            + el("td", {"class": "acoes-linha"}, el("button", {"type": "button", "class": "leve", "aria-expanded": "false", "onclick": "abrirAcoesUsuario(this)"}, t("Ações"))))
            + el("tr", {"class": "linha-acoes-usuario", "hidden": true}, el("td", {"colspan": "8"}, acoesDoUsuario(u, d)));
        }).join("")))));
    res += el("section", {"class": "cartao-config"}, el("h2", {}, t("Criar conta")) + el("form", {"data-recurso": "usuarios", "class": "campos"},
      el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
      + parCampo(t("Login"), el("input", {"name": "login", "required": true, "maxlength": "60", "autocomplete": "off"}))
      + parCampo(t("Senha (pelo menos 6 caracteres)"), el("input", {"type": "password", "name": "senha", "required": true, "minlength": "6", "autocomplete": "new-password"}))
      + parCampo(t("E-mail"), el("input", {"type": "email", "name": "email", "maxlength": "200"}))
      + parCampo(t("Coleção"), el("select", {"name": "colecao_nova"}, el("option", {"value": "modelo"}, t("uma coleção com o conjunto inicial")) + el("option", {"value": "vazia"}, t("uma coleção vazia"))
        + el("option", {"value": "nenhuma"}, t("nenhuma (vai receber acesso a outra)"))))
      + el("p", {"class": "nota"}, t("A conta criada aqui é oficial (não vence). Se o login já existir, só a senha dele é trocada."))
      + el("div", {"class": "botoes"}, el("button", {}, t("Criar conta")))));
    document.getElementById("usuarios").innerHTML = res;
  });
}

recarregarUsuarios().catch(function (e) {
  document.getElementById("usuarios").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os usuários: {1}", {1: h(e.message)}));
});
