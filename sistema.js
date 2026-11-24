// Página Sistema: o que é do sistema inteiro (só o master): o endereço do sistema e as rodadas do cron, a força da senha, os
// parâmetros de operação (o que estava só no config.php), a situação do cron, de onde vêm os números dos sorteios, o servidor
// de e-mail (o que o sistema leu do config.php, e o teste), a cópia de segurança e o SQL. Os dados vêm do api.php
// (recurso=sistema, recurso=backup e recurso=sql); as gravações, da Configuração (recurso=config, acao=salvar: url_sistema,
// cron_espera_rodadas, senha_<regra>, parametros[<chave>]), da cópia (recurso=backup), do SQL (recurso=sql) e do teste do
// e-mail (recurso=conta, acao=email_testar). O padrão de cada opção da coleção para o sistema inteiro fica na página da opção
// (a aba Sistema da Configuração, de Rodízio › Ajustes do rodízio e de Avisos).
function cartao(titulo, corpo, pe, explica) {
  return el("section", {"class": "cartao-config"}, el("h2", {}, h(titulo) + (explica || "")) + el("div", {"class": "cartao-corpo"}, corpo) + (pe ? el("div", {"class": "cartao-pe"}, pe) : ""));
}

// O SQL pela API (só o master; recurso=sql): o comando, o limite de linhas e Executar (a escrita recurso=sql, acao=executar);
// o resultado embaixo (a tabela do SELECT, ou as linhas mudadas) e o registro das últimas execuções
function tabelaSql(r) {
  if (!r) {
    return "";
  }
  if (r.tipo === "select" && r.colunas) {
    return el("p", {"class": "nota"}, t("{1} linha(s) lidas em {2} ms", {1: r.total, 2: r.duracao_ms}) + (r.cortado ? " · " + t("mostrando as {1} primeiras", {1: r.linhas.length}) : ""))
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, r.colunas.map(function (c) { return el("th", {}, h(c)); }).join("")))
        + el("tbody", {}, r.linhas.map(function (l) { return el("tr", {}, l.map(function (v) { return el("td", {}, v === null ? el("span", {"class": "nota"}, fixo("NULL")) : h(String(v))); }).join("")); }).join(""))));
  }
  return el("p", {"class": r.ok ? "nota" : "acao"}, r.ok ? (r.afetadas === null ? t("Executado em {1} ms.", {1: r.duracao_ms}) : t("{1} linha(s) mudadas em {2} ms.", {1: r.afetadas, 2: r.duracao_ms})) : h(r.erro || ""));
}
function quadroSql(s, resultado) {
  var execucoes = s.execucoes || [];
  return el("h2", {}, t("Banco de dados (SQL)"))
    + el("p", {"class": "nota"}, t("Um comando por vez, no SQL do MySQL (o sistema traduz para o banco {1}). Valem SELECT, INSERT e ALTER TABLE … ADD COLUMN (um campo novo numa tabela); UPDATE e DELETE só com a aprovação ligada nos Parâmetros de operação. Tudo fica no registro abaixo.", {1: el("code", {}, h(s.banco))}))
    + el("form", {"data-recurso": "sql", "id": "form-sql", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": "executar"})
      + el("label", {"style": "grid-column: 1 / -1"}, t("Comando {1}", {1: el("textarea", {"name": "comando", "class": "formula", "rows": "4", "required": true, "placeholder": fixo("SELECT id, nome FROM relogio ORDER BY nome")}, "")}))
      + el("label", {}, t("Linhas na resposta {1}", {1: el("input", {"type": "number", "name": "limite", "min": "1", "max": String(s.limite_maximo || 5000), "value": String(s.limite_padrao || 200)})}))
      + el("div", {"class": "botoes"}, el("button", {}, t("Executar"))))
    + el("div", {"id": "sql-resultado", "aria-live": "polite"}, tabelaSql(resultado))
    + el("details", {}, el("summary", {}, t("As últimas execuções")) + (execucoes.length === 0 ? el("p", {"class": "nota"}, t("Nenhuma ainda."))
      : el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Quando"), t("Quem"), t("Comando"), t("Resultado")].map(function (x) { return el("th", {}, x); }).join("")))
        + el("tbody", {}, execucoes.map(function (x) {
          return el("tr", {}, el("td", {}, dataBr(x.quando) + " " + horaBr(x.quando)) + el("td", {}, h(x.usuario || "—")) + el("td", {}, el("code", {}, h(x.comando)))
            // o erro fica como foi gravado, no idioma de quem rodou (um registro, como o do cron)
            + el("td", {"class": x.ok ? "nota" : "acao"}, x.ok ? (x.linhas === null ? t("ok") : t("{1} linha(s)", {1: x.linhas})) : (x.erro ? el("samp", {}, h(x.erro)) : t("erro"))));
        }).join(""))))));
}
function montarSql(resultado) {
  var alvo = document.getElementById("sql");
  if (!alvo) {
    return Promise.resolve();
  }
  return api({recurso: "sql", limite: 20}).then(function (s) {
    alvo.innerHTML = quadroSql(s, resultado);
  }).catch(function (e) {
    alvo.innerHTML = el("p", {"class": "acao"}, t("Não consegui ler o SQL: {1}", {1: h(e.message)}));
  });
}

// O servidor de e-mail (só o master): a configuração que o sistema leu do config.php (as senhas só como definida ou não), os
// problemas achados nela, o teste das conexões (SMTP, IMAP e POP, sem mandar nada) e o e-mail de teste; depois do teste, o
// resultado de cada parte e a conversa com os servidores (TESTE_EMAIL, guardado entre uma montagem e outra da página)
var TESTE_EMAIL = null;
function quadroServidor(d) {
  if (!d.email) {
    return "";
  }
  var e = d.email;
  var seg = function (s) { return nomeNaLista("email_seguranca", s); };
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
  var res = el("h2", {}, t("Servidor de e-mail"))
    + ajudaNota("email-sistema", t("O e-mail do sistema"), t("O sistema manda e-mail pelo que está no config.php (EMAIL_METODO, EMAIL_REMETENTE, SMTP_*, IMAP_*, POP_*): os convites, o Esqueci a senha, os avisos da conta e os destinos do tipo e-mail. Aqui está o que ele leu de lá, e o teste."))
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
        + el("input", {"type": "email", "name": "para", "required": true, "value": d.email_para || "", "placeholder": t("para quem"), "aria-label": t("Para")})
        + el("button", {}, t("Mandar um e-mail de teste")))
      + ajudaNota("teste-email", t("O teste do e-mail"), t("O teste conecta e entra no SMTP (sem mandar nada), no IMAP (e confere a pasta Enviados) e no POP; o e-mail de teste passa por tudo isso e sai de verdade. A conversa com os servidores aparece embaixo, sem as senhas."));
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

function montarSistema(d, bk) {
  ajudaGrupo("pagina", t("Sistema"));
  var ex = function (chave, titulo, html) { return ajuda(chave, titulo, el("p", {}, html)); };
  var numero = function (nome, valor, min, max) { return el("input", {"type": "number", "min": min, "max": max, "name": nome, "value": valor}); };
  // a situação do cron vem da API (cron_estado): o mesmo bloco da Configuração (api.js)
  var cron = conteudoCron(d.cron_estado);
  var enderecos = cartao(t("Endereço e cron"), el("div", {"class": "campos"},
    parCampo(t("Endereço do sistema (a âncora {link})"), el("input", {"name": "url_sistema", "value": d.url_sistema, "placeholder": t("http://servidor/relogios")}), "largo")
    + parCampo(t("Rodadas do cron antes de matar a travada (0 nunca mata)"), numero("cron_espera_rodadas", String(d.cron_espera_rodadas), "0", "1440"), null,
      ex("cron-espera", t("Rodadas do cron antes de matar a travada"), t("O cron roda a cada minuto, uma rodada por vez: cada uma grava o número do processo dela no arquivo de trava. Achando a anterior ainda rodando, a rodada sai sem rodar e conta o pulo; depois deste número de rodadas puladas seguidas, a próxima mata a travada e roda no lugar dela, com o erro no registro. ")
        + t("De 0 a 1440; 0 nunca mata (só espera); padrão 10. É do sistema inteiro: só o master muda.")))));
  // a força da senha (do sistema inteiro: só o master muda)
  var sp = d.senha_politica;
  var senhas = cartao(t("Senhas"), el("div", {"class": "campos"},
    [["tamanho", t("Tamanho mínimo (caracteres)"), "4", "128"], ["maiusculas", t("Letras maiúsculas, no mínimo"), "0", "32"], ["minusculas", t("Letras minúsculas, no mínimo"), "0", "32"],
      ["numeros", t("Números, no mínimo"), "0", "32"], ["simbolos", t("Símbolos, no mínimo (como ! @ # -)"), "0", "32"]].map(function (x) {
      return parCampo(x[1], numero("senha_" + x[0], String(sp.regras[x[0]]), x[2], x[3]));
    }).join("")),
    el("p", {"class": "nota"}, h(sp.texto)),
    ex("senhas", t("Senhas"), t("Vale para toda senha nova: a conta criada, a troca em Minha conta, a senha nova pelo link do Esqueci a senha e a do master em Usuários; as que já existem continuam valendo. É do sistema inteiro: só o master muda.")));
  // os parâmetros de operação do sistema inteiro (só o master): o que estava só no config.php; vazio volta ao config.php
  var parametros = cartao(t("Parâmetros de operação"), el("div", {"class": "campos parametros-sistema"}, d.parametros.map(function (p) {
    var de = p.origem === "sistema" ? "" : (p.config_valor !== null ? p.config_valor : p.padrao);
    var origem = p.origem === "sistema" ? t("gravado aqui") : (p.origem === "config" ? t("do config.php ({1})", {1: p.config_php}) : t("o padrão"));
    var campo = p.tipo === "sim_nao"
      ? el("select", {"name": "parametros[" + p.chave + "]"}, el("option", {"value": "", "selected": p.origem !== "sistema"}, p.config_php === null ? t("Padrão: {1}", {1: p.padrao === 1 ? t("sim") : t("não")}) : t("Como o config.php: {1}", {1: de === 1 ? t("sim") : t("não")}))
        + el("option", {"value": "1", "selected": p.origem === "sistema" && p.valor === 1}, t("Sim")) + el("option", {"value": "0", "selected": p.origem === "sistema" && p.valor === 0}, t("Não")))
      : el("input", {"type": "number", "name": "parametros[" + p.chave + "]", "min": String(p.minimo), "max": String(p.maximo), "value": p.origem === "sistema" ? String(p.valor) : "", "placeholder": String(de)});
    // o rótulo com a descrição em cima e o campo embaixo (largo): a descrição não cabe ao lado do campo no celular
    return parCampo(p.nome, campo, "largo", el("small", {"class": "nota", "style": "display:block; overflow-wrap: anywhere"}, h(p.descricao) + " · " + origem));
  }).join("")), null, ex("parametros", t("Parâmetros de operação"), t("Os parâmetros de operação do sistema inteiro, que antes só mudavam no config.php: o gravado aqui vale; vazio volta a valer a linha do config.php (ou o padrão). As credenciais, as chaves e as pastas continuam só no config.php. Só o master muda.")));
  var quadroCron = cartao(t("Cron"), cron);
  // de onde vêm os números dos sorteios (a API diz o último lote e se os dois serviços estão fora)
  var al = d.aleatorio;
  var sorteios = cartao(t("Sorteios"), el("div", {"class": "campos"},
    parCampo(t("Os números vêm de"), el("span", {}, t("1. RANDOM.ORG (ruído atmosférico) · 2. fora do ar, ANU QRNG (quântico) · 3. sem os dois, o gerador seguro do PHP")))
    + parCampo(t("Último lote"), el("span", {}, al.ultimo_lote ? h(al.ultimo_lote.replace(/ em (\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*/, " em $3/$2/$1 $4")) : t("nenhum ainda")))
    + (al.fora_desde ? parCampo(t("Serviços fora"), el("span", {"class": "acao"}, t("os dois sem responder desde {1} {2}; o sistema tenta de novo depois de 10 minutos", {1: dataBr(al.fora_desde), 2: horaBr(al.fora_desde)}))) : "")),
    "", ex("sorteios", t("Sorteios"), t("Todo sorteio do sistema (o rodízio totalmente aleatório, o sorteio pela nota e o desempate de notas iguais na escala) usa estes números. Eles chegam num lote, e cada sorteio usa só o que precisa, sem viés.")));
  // ---------- Cópia de segurança (desligada no config.php, BACKUP_ATIVO = false: o quadro nem aparece) ----------
  var copia = bk === null || bk.ativo === false ? "" : el("section", {"id": "copia", "class": "cartao-config"}, el("h2", {}, t("Cópia de segurança"))
    + (bk.pasta_ok
      ? el("p", {"class": "nota"}, t("Uma cópia do banco inteiro por dia, na rodada da noite, guardando as {1} mais novas, {2} o conteúdo dos documentos (os arquivos já ficam na pasta deles). A cópia volta em qualquer um dos três bancos, na mesma versão do sistema. {3}", {1: bk.manter, 2: bk.documentos ? t("com") : t("sem"), 3: bk.ultima ? t("Última: {1} {2}.", {1: dataBr(bk.ultima), 2: horaBr(bk.ultima)}) : t("Nenhuma feita ainda.")}))
      : el("p", {"class": "alerta-cron"}, t("A cópia automática está desligada: {1}. Ponha no config.php a BACKUP_PASTA, uma pasta do servidor fora da pasta publicada.", {1: h(bk.pasta_erro)})))
    + (bk.copias.length > 0 ? el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Cópia"), t("Feita em"), t("Tamanho"), t("Documentos"), ""].map(function (item) { return el("th", {}, item); }).join("")), "largo")
      + el("tbody", {}, bk.copias.map(function (x) {
        return el("tr", {}, el("td", {}, el("code", {}, h(x.arquivo))) + el("td", {}, x.criada ? dataBr(x.criada) + " " + horaBr(x.criada) : "—") + el("td", {}, h(x.tamanho))
          + el("td", {}, x.documentos ? t("com") : t("sem")) + el("td", {"class": "acoes-linha"}, el("a", {"href": "api.php?recurso=backup&arquivo=" + encodeURIComponent(x.arquivo), "class": "botao leve"}, t("Baixar")) + " "
          + el("form", {"data-recurso": "backup", "onsubmit": "return confirm(" + JSON.stringify(t("Voltar o banco para a cópia de {1}? Tudo o que foi feito depois some. Antes, o sistema guarda uma cópia do que está agora.", {1: x.criada ? dataBr(x.criada) + " " + horaBr(x.criada) : x.arquivo})) + ")"}, el("input", {"type": "hidden", "name": "acao", "value": "restaurar"})
            + el("input", {"type": "hidden", "name": "arquivo", "value": x.arquivo}) + el("button", {"class": "discreto"}, t("Restaurar")))));
      }).join("")), "largo")) : "")
    + (bk.pasta_ok ? el("form", {"data-recurso": "backup"}, el("input", {"type": "hidden", "name": "acao", "value": "fazer"}) + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, t("Fazer uma cópia agora")))) : ""));
  // data-quadros: os cartões e os parâmetros em colunas pela altura de cada um (organizarQuadrosConfig, do api.js)
  var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Sistema")) + ajudaBotao())
    + el("form", {"data-recurso": "config", "id": "form-sistema"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
      + el("div", {"class": "config-grade"}, enderecos + senhas)
      // os parâmetros são muitos: o quadro na largura toda, com os campos em colunas
      + parametros
      + el("div", {"class": "barra-salvar"}, el("span", {"class": "nota"}, t("As mudanças desta página valem depois de salvar.")) + el("button", {}, t("Salvar"))))
    + el("div", {"class": "config-grade"}, quadroCron + sorteios + quadroServidor(d))
    + copia
    + el("section", {"id": "sql", "class": "cartao-config"}, el("p", {"class": "nota"}, t("Lendo…")));
  porHtml(document.getElementById("sistema"), el("div", {"data-quadros": ""}, res));
  organizarQuadrosConfig();
  montarSql(null);
  if (window.location.hash) {
  var alvo = document.getElementById(window.location.hash.slice(1));
  if (alvo) {
    alvo.scrollIntoView();
  }
}
}

function carregarSistema() {
  // a cópia de segurança desligada no config.php (BACKUP_ATIVO = false): o quadro nem aparece
  return Promise.all([api({recurso: "sistema"}), api({recurso: "backup"}).catch(function () { return null; })]).then(function (rs) {
    montarSistema(rs[0], rs[1]);
  }).catch(function (e) {
    porHtml(document.getElementById("sistema"), el("p", {"class": "acao"}, t("Não consegui ler o sistema: {1}", {1: h(e.message)})));
  });
}

// salvar não tira a pessoa do lugar; o SQL mostra o resultado embaixo do comando (que continua escrito); o teste do e-mail fica
// na página até o próximo
aoGravar = function (form, res, dados) {
  if (dados.get("recurso") === "sql") {
    form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
    return montarSql(res.resultado || null).then(function () {
      var caixa = document.querySelector("#form-sql textarea[name=comando]");
      if (caixa) {
        caixa.value = dados.get("comando") || "";
      }
      avisoFlutuanteGravacao(textoResposta(res), !res.ok);
    });
  }
  if (dados.get("recurso") === "conta" && dados.get("acao") === "email_testar") {
    TESTE_EMAIL = res.teste || null;
  }
  return salvarSemMover(form, res, res.ok || TESTE_EMAIL ? carregarSistema : null);
};

carregarSistema();
