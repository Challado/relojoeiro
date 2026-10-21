// Minha conta: lê recurso=conta e monta os quadros. O pedido de acesso é pelo código que o dono passou (nenhuma lista de contas).
// Com conta.php?convite=<token> (o link do convite, para quem já tem conta), aceita o convite ao abrir.
var recadoConta = "";
var tokenNovo = null;

aoGravar = function (form, res, dados) {
  if (!res.ok) { return salvarSemMover(form, res, function () {}); }
  recadoConta = "";
  tokenNovo = res.token ? {nome: dados.get("nome"), token: res.token} : null;
  if (dados.get("recurso") === "conta" && dados.get("acao") === "excluir") {
    window.location = "cadastro.php";
    return;
  }
  if (dados.get("recurso") === "colecoes" && dados.get("acao") === "criar" && res.id) {
    document.cookie = "colecao=" + res.id + "; path=/; max-age=31536000; SameSite=Lax";
  }
  // outro idioma na conta: a página abre de novo nele (reabrirNoIdioma, api.js), com o recado
  if (dados.get("recurso") === "conta" && dados.get("acao") === "alterar" && dados.has("idioma")
    && (dados.get("idioma") || "") !== ((CONTA && CONTA.usuario.idioma) || "")) {
    reabrirNoIdioma(textoResposta(res), dados.get("idioma") || "");
    return;
  }
  return salvarSemMover(form, res, carregarConta);
};

function formAcao(recurso, acao, campos, botao, classe) {
  return el("form", {"data-recurso": recurso, "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": acao})
    + Object.keys(campos).map(function (k) { return el("input", {"type": "hidden", "name": k, "value": campos[k]}); }).join("")
    + el("button", {"class": classe || "leve"}, botao));
}

function quadroColecoes(d) {
  var linhas = d.colecoes.map(function (c) {
    var acoes = c.id !== d.colecao ? el("button", {"type": "button", "class": "leve", "onclick": "escolherColecao(" + c.id + "); return false"}, t("Abrir")) : el("span", {"class": "estado estado-atividade"}, t("aberta"));
    if (c.papel === "dono" || (c.papel === "master" && d.usuario.papel === "master")) {
      acoes += el("details", {"class": "acoes-usuario"}, el("summary", {}, t("Renomear"))
        + el("form", {"data-recurso": "colecoes", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": "renomear"}) + el("input", {"type": "hidden", "name": "id", "value": c.id})
          + el("input", {"name": "nome", "value": c.nome, "required": true, "maxlength": "120", "aria-label": t("Nome da coleção")}) + el("button", {"class": "leve"}, t("Salvar"))))
        + formAcao("colecoes", "excluir", {id: c.id}, t("Excluir"), "leve perigo");
    } else {
      acoes += formAcao("acessos", "sair", {colecao_id: c.id}, t("Sair"), "leve perigo");
    }
    var papel = {dono: t("sua"), membro: t("membro"), master: t("master")}[c.papel] || c.papel;
    return el("tr", {}, el("td", {}, h(c.nome)) + el("td", {"class": "num"}, c.id) + el("td", {}, h(c.dono)) + el("td", {}, papel) + el("td", {"class": "acoes-linha"}, acoes));
  }).join("");
  var arvores = d.arvores.map(function (a) { return el("option", {"value": a.id}, h(a.nome)); }).join("");
  return el("section", {"class": "cartao-config", "id": "colecoes"}, el("h2", {}, t("Coleções"))
    + (d.colecoes.length === 0 ? el("p", {"class": "alerta-cron"}, t("Você ainda não vê nenhuma coleção: crie uma aqui embaixo, ou peça acesso à de alguém (e espere a aprovação)."))
      : el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Coleção")) + el("th", {}, t("Código")) + el("th", {}, t("Dono")) + el("th", {}, t("Você é")) + el("th", {}, "")))
        + el("tbody", {}, linhas))) + el("p", {"class": "nota"}, t("Para alguém pedir acesso a uma coleção sua, passe o código dela; para todas as suas, o seu código (em Seus dados). O pedido não mostra a ninguém o seu login nem os nomes das coleções: você aprova ou recusa em Aprovações.")))
    + el("h3", {}, t("Nova coleção")) + el("form", {"data-recurso": "colecoes", "class": "campos"}, el("input", {"type": "hidden", "name": "acao", "value": "criar"})
      + parCampo(t("Nome"), el("input", {"name": "nome", "required": true, "maxlength": "120"}))
      + parCampo(t("Começa"), el("select", {"name": "opcao", "onchange": "this.form.querySelector('[data-sub]').hidden = this.value !== 'arvore'"},
        el("option", {"value": "modelo"}, t("com o conjunto inicial (grupos, campos, fórmulas, avisos e critérios)")) + el("option", {"value": "vazia"}, t("vazia (só a raiz da árvore)"))
        + (arvores !== "" ? el("option", {"value": "arvore"}, t("com uma árvore minha que já existe (o cadastro fica em comum)")) : "")))
      + el("div", {"data-sub": true, "hidden": true}, parCampo(t("Árvore"), el("select", {"name": "arvore_id"}, arvores)))
      + el("div", {"class": "botoes"}, el("button", {}, t("Criar coleção")))));
}

function quadroArvores(d) {
  if (d.arvores.length === 0) {
    return "";
  }
  return el("section", {"class": "cartao-config", "id": "arvores"}, el("h2", {}, t("Árvores de grupos"))
    + el("p", {"class": "nota"}, t("A árvore guarda os grupos e o cadastro deles (campos, tipos de lançamento, fórmulas, avisos, categorias e critérios). Uma árvore pode servir a várias coleções suas: o que muda nela vale para todas."))
    + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Árvore")) + el("th", {}, t("Grupos")) + el("th", {}, t("Usada por")) + el("th", {}, "")))
      + el("tbody", {}, d.arvores.map(function (a) {
        return el("tr", {}, el("td", {}, h(a.nome)) + el("td", {"class": "num"}, a.grupos) + el("td", {}, a.colecoes.map(function (c) { return h(c.nome); }).join(", ") || el("span", {"class": "nota"}, t("nenhuma coleção")))
          + el("td", {"class": "acoes-linha"}, el("details", {"class": "acoes-usuario"}, el("summary", {}, t("Renomear ou copiar"))
            + el("form", {"data-recurso": "colecoes", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": "arvore_renomear"}) + el("input", {"type": "hidden", "name": "arvore_id", "value": a.id})
              + el("input", {"name": "nome", "value": a.nome, "required": true, "maxlength": "80", "aria-label": t("Nome da árvore")}) + el("button", {"class": "leve"}, t("Renomear")))
            + el("form", {"data-recurso": "colecoes", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": "arvore_copiar"}) + el("input", {"type": "hidden", "name": "arvore_id", "value": a.id})
              + el("input", {"name": "nome", "value": t("Cópia de {1}", {1: a.nome}), "required": true, "maxlength": "80", "aria-label": t("Nome da cópia")}) + el("button", {"class": "leve"}, t("Copiar"))))
            + formAcao("colecoes", "arvore_excluir", {arvore_id: a.id}, t("Excluir"), "leve perigo")));
      }).join("")))));
}

function quadroPedir(d) {
  var pedidos = d.pedidos.map(function (p) {
    return el("tr", {}, el("td", {}, h(p.alvo)) + el("td", {}, dataBr(p.criado)) + el("td", {}, dataBr(p.expira)) + el("td", {"class": "acoes-linha"}, formAcao("pedidos", "cancelar", {id: p.id}, t("Cancelar"), "leve perigo")));
  }).join("");
  return el("section", {"class": "cartao-config", "id": "pedir"}, el("h2", {}, t("Pedir acesso"))
    + (pedidos !== "" ? el("h3", {}, t("Esperando resposta")) + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("O quê")) + el("th", {}, t("Pedido em")) + el("th", {}, t("Vence em")) + el("th", {}, "")))
      + el("tbody", {}, pedidos))) : "")
    + (d.convites.length > 0 ? el("p", {"class": "nota"}, t("Há {1} convite(s) para o seu e-mail: aceite pelo link que chegou nele.", {1: d.convites.length})) : "")
    + el("form", {"data-recurso": "pedidos", "class": "campos", "onsubmit": "acessoPorCodigo(this)"},
      el("input", {"type": "hidden", "name": "acao", "value": "pedir"}) + camposCodigoAcesso(true, false)
      + parCampo(t("Recado para o dono (opcional)"), el("input", {"name": "mensagem", "maxlength": "500"}), "largo")
      + el("div", {"class": "botoes"}, el("button", {}, t("Pedir acesso"))))
    + el("p", {"class": "nota"}, t("O dono (ou o administrador) aprova. Pedidos sem resposta saem sozinhos depois de alguns dias.")));
}

function quadroDados(d) {
  var u = d.usuario;
  return el("section", {"class": "cartao-config", "id": "dados"}, el("h2", {}, t("Seus dados"))
    + el("p", {}, t("Login: {1}", {1: el("strong", {}, h(u.login))}) + (u.papel === "master" ? el("span", {"class": "selo master"}, t("master")) : "")
      + (u.demo ? el("span", {"class": "selo demo"}, t("demonstração")) : "") + " · " + t("desde {1}", {1: dataBr(u.criado)})
      + " · " + t("seu código: {1}", {1: el("strong", {}, u.id)}))
    + el("p", {"class": "nota"}, t("Alguém quer ver as suas coleções? Passe o seu código ({1}) para todas elas, também as que você criar depois, ou o código de uma coleção só (na coluna Código da tabela Coleções). A pessoa pede acesso com ele, na Criar conta ou em Minha conta; você recebe um e-mail e aprova ou recusa em Aprovações. Ninguém vê o seu login antes de você aprovar.", {1: u.id}))
    + (u.demo ? el("p", {"class": "alerta-cron"}, t("Conta de demonstração: vence em {1} dias ({2}). Depois disso, ela e as suas coleções são apagadas. Para continuar, peça ao administrador para torná-la oficial.", {1: u.dias_restantes, 2: dataBr(u.expira_em)})) : "")
    + el("form", {"data-recurso": "conta", "class": "campos"}, el("input", {"type": "hidden", "name": "acao", "value": "alterar"})
      + parCampo(t("E-mail"), el("input", {"type": "email", "name": "email", "value": u.email || "", "maxlength": "200", "autocomplete": "email"}), "largo")
      + parCampo(t("Idioma"), el("select", {"name": "idioma"}, el("option", {"value": "", "selected": !u.idioma}, t("O da coleção")) + d.linguas.map(function (l) {
        return el("option", {"value": l.codigo, "selected": u.idioma === l.codigo}, h(l.nome));
      }).join("")))
      // o fuso horário da pessoa: as datas e horas que ela vê e digita (no banco fica tudo no fuso do sistema)
      + parCampo(t("Fuso horário"), el("select", {"name": "fuso"}, opcoesFuso(d.fusos, u.fuso, d.fuso.colecao ? t("O da coleção ({1})", {1: d.fuso.colecao.replace(/_/g, " ")}) : t("O do sistema ({1})", {1: d.fuso.sistema.replace(/_/g, " ")}),
        t("O do navegador (neste aparelho: {1})", {1: (fusoNavegador() || "?").replace(/_/g, " ")}))), "largo")
      + parCampo(t("Senha atual (só para trocar a senha)"), el("input", {"type": "password", "name": "senha_atual", "autocomplete": "current-password"}))
      + camposSenha(t("Senha nova"), d.senha_politica, false)
      + el("p", {"class": "nota"}, t("O e-mail serve para recuperar a senha, receber os convites e os avisos da conta. Trocou a senha: o navegador pede o login de novo.") + " "
        + t("O fuso horário vale para tudo o que você vê e digita (a Hora certa, os lançamentos, os avisos); quem divide a coleção com você vê cada hora no fuso dele. O dia do rodízio (o plano, o relógio do dia, as rodadas) é sempre o da coleção. Agora: {1}.", {1: el("strong", {}, h(d.fuso.nome.replace(/_/g, " ")))}))
      + el("div", {"class": "botoes"}, el("button", {}, t("Salvar")))));
}

function quadroTokens(d) {
  return el("section", {"class": "cartao-config", "id": "tokens"}, el("h2", {}, t("Tokens de API"))
    + el("p", {"class": "nota"}, t("Para scripts e automações (cabeçalho X-Api-Token): o token vale como o seu login, com as suas coleções. Ele só aparece quando é criado."))
    + (tokenNovo ? el("p", {}, t("Token {1}:", {1: h(tokenNovo.nome)})) + el("p", {"class": "token-novo"}, h(tokenNovo.token)) : "")
    + (d.tokens.length > 0 ? el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Nome")) + el("th", {}, t("Criado em")) + el("th", {}, t("Último uso")) + el("th", {}, "")))
      + el("tbody", {}, d.tokens.map(function (k) {
        return el("tr", {}, el("td", {}, h(k.nome)) + el("td", {}, dataBr(k.criado)) + el("td", {}, k.ultimo_uso ? dataBr(k.ultimo_uso) + " " + horaBr(k.ultimo_uso) : "—")
          + el("td", {"class": "acoes-linha"}, formAcao("conta", "token_revogar", {id: k.id}, t("Revogar"), "leve perigo")));
      }).join("")))) : "")
    + el("form", {"data-recurso": "conta", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": "token_criar"})
      + el("input", {"name": "nome", "required": true, "maxlength": "80", "placeholder": t("onde vai usar (ex.: atalho do celular)"), "aria-label": t("Nome do token")}) + el("button", {}, t("Criar token"))));
}

// O aplicativo no celular (PWA): lembrar este aparelho (a chave no cookie: entra sem a senha), instalar (o pedido do
// navegador, guardado pelo api.js; no iPhone, o caminho do Safari) e ligar as notificações (o quadro de baixo)
function nomeAparelho() {
  var ua = navigator.userAgent;
  var sistema = /iPhone/.test(ua) ? "iPhone" : (/iPad/.test(ua) ? "iPad" : (/Android/.test(ua) ? "Android" : (/Windows/.test(ua) ? "Windows" : (/Mac OS/.test(ua) ? "Mac" : (/Linux/.test(ua) ? "Linux" : "")))));
  var nav = /Edg\//.test(ua) ? "Edge" : (/OPR\//.test(ua) ? "Opera" : (/Firefox\//.test(ua) ? "Firefox" : (/Chrome\//.test(ua) ? "Chrome" : (/Safari\//.test(ua) ? "Safari" : ""))));
  return (sistema + " " + nav).trim() || ua.substr(0, 60);
}
var noApp = (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches) || window.navigator.standalone === true;
function quadroApp(d) {
  var este = d.aparelhos.filter(function (a) { return a.este; })[0] || null;
  var iphone = /iPhone|iPad|iPod/.test(navigator.userAgent);
  var passo1 = este ? t("Este aparelho está lembrado desde {1}: o aplicativo (e este navegador) entra sem pedir a senha.", {1: dataBr(este.criado)})
      + " " + formAcao("conta", "aparelho_esquecer", {id: este.id}, t("Esquecer este aparelho"), "leve")
    : t("O sistema passa a entrar sem pedir a senha neste aparelho (só nele; a chave fica guardada no navegador, e você pode esquecer quando quiser).")
      + " " + formAcao("conta", "aparelho_lembrar", {nome: nomeAparelho()}, t("Lembrar este aparelho"));
  var passo2 = noApp ? t("Você já está no aplicativo.")
    : (INSTALAR_APP ? el("button", {"type": "button", "onclick": "instalarApp()"}, t("Instalar o aplicativo"))
    : (iphone ? t("No iPhone e no iPad: abra o sistema no Safari, toque em Compartilhar e em Adicionar à Tela de Início.")
    : t("No menu do navegador: Instalar aplicativo (ou Adicionar à tela inicial). Se o botão de instalar aparecer aqui, é só tocar nele.")));
  var passo3 = t("As notificações (o relógio do dia, a véspera e os avisos) chegam ao aparelho pelo quadro de baixo, {1}; no iPhone, só de dentro do aplicativo instalado. O que chega se escolhe na página Avisos, em Destinos.", {1: el("a", {"href": "#push"}, t("Notificações neste aparelho"))});
  var lista = "";
  if (d.aparelhos.length > 0) {
    lista = el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Aparelho lembrado")) + el("th", {}, t("Desde")) + el("th", {}, t("Último uso")) + el("th", {}, "")))
      + el("tbody", {}, d.aparelhos.map(function (a) {
        return el("tr", {}, el("td", {}, h(a.nome || t("(sem nome)")) + (a.este ? " " + el("small", {}, t("(este)")) : "")) + el("td", {}, dataBr(a.criado))
          + el("td", {}, a.ultimo_uso ? dataBr(a.ultimo_uso) + " " + horaBr(a.ultimo_uso) : "—")
          + el("td", {"class": "acoes-linha"}, formAcao("conta", "aparelho_esquecer", {id: a.id}, t("Esquecer"), "leve perigo")));
      }).join(""))));
  }
  return el("section", {"class": "cartao-config", "id": "app"}, el("h2", {}, t("Aplicativo no celular"))
    + el("p", {"class": "nota"}, t("O sistema vira um aplicativo no seu celular ou tablet: um ícone na tela inicial, abrindo em tela cheia, entrando sem pedir a senha e recebendo as notificações. Três passos, feitos no aparelho em que você quer o aplicativo:"))
    + el("ol", {"class": "passos"}, el("li", {}, el("strong", {}, t("Lembrar este aparelho.")) + " " + passo1) + el("li", {}, el("strong", {}, t("Instalar.")) + " " + passo2) + el("li", {}, el("strong", {}, t("Ligar as notificações.")) + " " + passo3))
    + (lista !== "" ? el("p", {"class": "nota"}, t("Os aparelhos lembrados (trocar a senha esquece todos):")) + lista : ""));
}
function instalarApp() {
  if (!INSTALAR_APP) {
    return;
  }
  var pedido = INSTALAR_APP;
  INSTALAR_APP = null;
  pedido.prompt();
  pedido.userChoice.then(function (r) {
    recadoConta = r && r.outcome === "accepted" ? t("Aplicativo instalado: abra pelo ícone na tela inicial.") : t("A instalação foi cancelada.");
    carregarConta();
  });
}
document.addEventListener("app-instalavel", function () {
  if (CONTA) {
    carregarConta();
  }
});

function quadroPush(d) {
  var p = d.push;
  var res = el("h2", {}, t("Notificações neste aparelho (Web Push)"));
  if (!p.configurado) {
    res += el("p", {"class": "alerta-cron"}, t("O Web Push não está configurado no servidor (VAPID_PUBLICA e VAPID_PRIVADA no config.php)."));
  } else if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
    res += el("p", {"class": "nota"}, t("Este navegador não recebe notificações (no iPhone, primeiro instale o aplicativo: o quadro Aplicativo no celular, acima)."));
  } else {
    res += el("p", {"class": "nota"}, t("Ligue para receber neste aparelho as mensagens dos destinos do tipo Web Push que apontam para você."))
      + el("div", {"class": "botoes"}, el("button", {"type": "button", "onclick": "ligarPush()"}, t("Receber notificações neste aparelho")));
  }
  if (p.aparelhos.length > 0) {
    res += el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Aparelho")) + el("th", {}, t("Desde")) + el("th", {}, "")))
      + el("tbody", {}, p.aparelhos.map(function (a) {
        return el("tr", {}, el("td", {}, h(a.aparelho || t("(sem nome)"))) + el("td", {}, dataBr(a.criada)) + el("td", {"class": "acoes-linha"}, formAcao("conta", "push_cancelar", {id: a.id}, t("Desligar"), "leve perigo")));
      }).join(""))))
      + formAcao("conta", "push_testar", {}, t("Mandar uma notificação de teste"));
  }
  if (d.usuario.papel === "master") {
    res += el("details", {}, el("summary", {}, t("Chaves do Web Push (master)")) + el("p", {"class": "nota"}, t("Gera um par novo de chaves VAPID para pôr no config.php. Trocar as chaves desliga os aparelhos já inscritos."))
      + formAcao("conta", "vapid_gerar", {}, t("Gerar chaves")) + (CHAVES_VAPID ? el("pre", {"class": "token-novo"}, h(CHAVES_VAPID)) : ""));
  }
  return el("section", {"class": "cartao-config", "id": "push"}, res);
}
var CHAVES_VAPID = null;

// Liga o Web Push neste navegador: registra o service worker (o sw.js, pela API), inscreve com a chave pública do servidor e
// manda a inscrição para a API
function ligarPush() {
  var chave = CONTA.push.chave_publica;
  var bruta = atob(chave.replace(/-/g, "+").replace(/_/g, "/") + "===".slice((chave.length + 3) % 4));
  var bytes = new Uint8Array(bruta.length);
  for (var i = 0; i < bruta.length; i++) {
    bytes[i] = bruta.charCodeAt(i);
  }
  Notification.requestPermission().then(function (perm) {
    if (perm !== "granted") {
      throw new Error(t("as notificações foram bloqueadas no navegador"));
    }
    return navigator.serviceWorker.register("api.php?recurso=sw");
  }).then(function () {
    return navigator.serviceWorker.ready;
  }).then(function (reg) {
    return reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: bytes});
  }).then(function (insc) {
    var j = insc.toJSON();
    return enviar({recurso: "conta", acao: "push_inscrever", endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth, aparelho: navigator.userAgent.substr(0, 200)});
  }).then(function (res) {
    recadoConta = textoResposta(res);
    return carregarConta();
  }).catch(function (e) {
    recadoConta = t("Não consegui ligar as notificações: {1}", {1: e.message});
    carregarConta();
  });
}

// o servidor (só o master): o teste do e-mail do config.php
// O servidor de e-mail (só o master): a configuração que o sistema leu do config.php (as senhas só como definida ou não), os
// problemas achados nela, o teste das conexões (SMTP, IMAP e POP, sem mandar nada) e o e-mail de teste; depois do teste, o
// resultado de cada parte e a conversa com os servidores (TESTE_EMAIL, guardado entre uma montagem e outra da página)
var TESTE_EMAIL = null;
function quadroServidor(d) {
  if (d.usuario.papel !== "master" || !d.email) {
    return "";
  }
  var e = d.email;
  var seg = function (s) { return {ssl: t("SSL (desde o começo)"), tls: t("TLS (STARTTLS)"), nada: t("sem criptografia")}[s] || s; };
  var conta = function (c) {
    return h(c.host + ":" + c.porta) + " · " + seg(c.seguranca) + " · " + (c.usuario !== "" ? t("usuário {1}", {1: h(c.usuario)}) : t("sem usuário"))
      + " · " + (c.senha ? t("senha definida") : el("strong", {}, t("sem senha")));
  };
  var linhas = [[t("Método"), e.metodo === "smtp" ? t("SMTP (o servidor de e-mail do config.php)") : (e.metodo === "mail" ? t("mail() do PHP (o sendmail do servidor)") : el("strong", {}, t("desligado")))],
    [t("Remetente"), e.remetente !== "" ? h(e.remetente) : el("strong", {}, t("falta"))]];
  if (e.metodo === "smtp") {
    linhas.push([t("SMTP (envio)"), conta(e.smtp)]);
  }
  linhas.push([t("IMAP (cópia em Enviados)"), e.imap ? conta(e.imap) + " · " + t("pasta {1}", {1: h(e.imap.pasta)}) : el("span", {"class": "nota"}, t("não configurado (opcional)"))]);
  linhas.push([t("POP (só o teste da conta)"), e.pop ? conta(e.pop) : el("span", {"class": "nota"}, t("não configurado (opcional)"))]);
  var res = el("h2", {}, t("Servidor de e-mail (master)"))
    + el("p", {"class": "nota"}, t("O sistema manda e-mail pelo que está no config.php (EMAIL_METODO, EMAIL_REMETENTE, SMTP_*, IMAP_*, POP_*): os convites, o Esqueci a senha, os avisos da conta e os destinos do tipo e-mail. Aqui está o que ele leu de lá, e o teste."))
    + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados config-email"}, el("thead", {}, el("tr", {}, el("th", {}, t("Parte")) + el("th", {}, t("O que o sistema leu do config.php"))))
      + el("tbody", {}, linhas.map(function (l) {
        return el("tr", {}, el("td", {}, l[0]) + el("td", {}, l[1]));
      }).join(""))));
  if (e.problemas.length > 0) {
    res += el("div", {"class": "alerta-cron"}, el("strong", {}, t("Para corrigir no config.php:")) + el("ul", {}, e.problemas.map(function (p) { return el("li", {}, h(p)); }).join("")));
  }
  if (e.metodo !== null) {
    res += el("div", {"class": "botoes"}, formAcao("conta", "email_testar", {para: ""}, t("Testar as conexões (sem mandar nada)")))
      + el("form", {"data-recurso": "conta", "class": "form-linha"}, el("input", {"type": "hidden", "name": "acao", "value": "email_testar"})
        + el("input", {"type": "email", "name": "para", "required": true, "value": d.usuario.email || "", "placeholder": t("para quem"), "aria-label": t("Para")})
        + el("button", {}, t("Mandar um e-mail de teste")))
      + el("p", {"class": "nota"}, t("O teste conecta e entra no SMTP (sem mandar nada), no IMAP (e confere a pasta Enviados) e no POP; o e-mail de teste passa por tudo isso e sai de verdade. A conversa com os servidores aparece embaixo, sem as senhas."));
  }
  if (TESTE_EMAIL) {
    var r = TESTE_EMAIL;
    var partes = [["smtp", t("SMTP")], ["imap", t("IMAP")], ["pop", t("POP")], ["envio", t("E-mail de teste")]].filter(function (p) { return r[p[0]]; });
    res += el("h3", {}, t("Resultado do teste"))
      + (partes.length > 0 ? el("ul", {"class": "teste-email"}, partes.map(function (p) {
        var x = r[p[0]];
        return el("li", {}, el("span", {"class": "estado " + (x.ok ? "estado-uso" : "estado-repouso")}, x.ok ? t("ok") : t("falhou")) + " " + el("strong", {}, p[1]) + (x.erro ? ": " + h(x.erro) : ""));
      }).join("")) : el("p", {"class": "nota"}, t("Nada para testar: o envio está desligado.")))
      + (r.registro && r.registro.length > 0 ? el("details", {"class": "registro-cron", "open": true}, el("summary", {}, t("Conversa com os servidores"))
        + el("pre", {}, h(r.registro.join("\n")))) : "");
  }
  return el("section", {"class": "cartao-config", "id": "servidor"}, res);
}

function quadroExcluir(d) {
  return el("section", {"class": "cartao-config", "id": "excluir"}, el("h2", {}, t("Excluir a conta"))
    + el("p", {"class": "nota"}, t("Sai a conta com tudo o que é dela: as suas coleções (com os relógios, o histórico e os arquivos), as suas árvores, os seus acessos e os seus tokens. Antes de apagar, a API mostra tudo o que sai junto."))
    + formAcao("conta", "excluir", {}, t("Excluir a minha conta"), "perigo"));
}

var CONTA = null;
function carregarConta() {
  return api({recurso: "conta"}).then(function (d) {
    CONTA = d;
    var res = el("h1", {}, t("Minha conta")) + (recadoConta !== "" ? el("p", {"class": "acao"}, h(recadoConta)) : "");
    recadoConta = "";
    if (d.aprovacoes > 0) {
      res += el("p", {"class": "alerta-cron"}, t("{1} pedido(s) ou convite(s) esperam a sua resposta em {2}.", {1: d.aprovacoes, 2: el("a", {"href": "aprovacoes.php"}, t("Aprovações"))}));
    }
    res += quadroColecoes(d) + quadroArvores(d) + quadroPedir(d)
      + el("p", {"class": "nota"}, t("Para convidar alguém para as suas coleções, ou ver quem as vê: {1}.", {1: el("a", {"href": "aprovacoes.php"}, t("Aprovações e convites"))}))
      + quadroDados(d) + quadroApp(d) + quadroTokens(d) + quadroPush(d) + quadroServidor(d) + quadroExcluir(d);
    document.getElementById("conta").innerHTML = res;
  });
}

// o "Gerar chaves" mostra as linhas para o config.php
var gravarConta = aoGravar;
aoGravar = function (form, res, dados) {
  CHAVES_VAPID = res.config || null;
  // o teste do e-mail fica na página até o próximo
  if (dados.get("recurso") === "conta" && dados.get("acao") === "email_testar") {
    TESTE_EMAIL = res.teste || null;
  }
  gravarConta(form, res, dados);
};

// o link do convite para quem já tem conta: aceita ao abrir
var conviteUrl = new URLSearchParams(window.location.search).get("convite");
(conviteUrl ? enviar({recurso: "convites", acao: "aceitar", token: conviteUrl}).then(function (res) {
  recadoConta = textoResposta(res);
  if (res.ok && res.colecao_id) {
    document.cookie = "colecao=" + res.colecao_id + "; path=/; max-age=31536000; SameSite=Lax";
  }
  history.replaceState(null, "", "conta.php");
}) : Promise.resolve()).then(carregarConta).catch(function (e) {
  document.getElementById("conta").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler a conta: {1}", {1: h(e.message)}));
});
