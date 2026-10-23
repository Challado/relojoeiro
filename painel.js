// Painel da direita: abre o relógio clicado na tabela sem recarregar a página, com os dados da API (recurso=ficha),
// e no cadastro mostra só os campos que valem para o grupo escolhido (os do grupo e os de cada grupo acima dele).
// Também serve a ficha (ficha.php), que mostra o mesmo painel numa página própria.
var recados = {};
var CURTO = ["", t("seg"), t("ter"), t("qua"), t("qui"), t("sex"), t("sáb"), t("dom")];

function mostrarCamposDoTipo(form) {
  var sel = form.querySelector(".campo-subtipo");
  // a situação na coleção: os campos da lista de desejos (data-desejo) só no desejado; o disponível só na coleção
  var posse = form.querySelector(".campo-posse");
  var desejado = posse !== null && posse.value === "desejado";
  if (sel) {
    var opcao = sel.options[sel.selectedIndex];
    var cadeia = (opcao ? opcao.getAttribute("data-cadeia") || "" : "").split(" ");
    form.querySelectorAll("[data-sub]").forEach(function (el) {
      el.hidden = (el.getAttribute("data-sub") !== "" && cadeia.indexOf(el.getAttribute("data-sub")) < 0) || (el.hasAttribute("data-desejo") && !desejado);
    });
  }
  form.querySelectorAll("[data-so-colecao]").forEach(function (el) {
    el.hidden = posse !== null && posse.value !== "colecao";
  });
  // a divisória sem nenhum campo à vista (todos de outro grupo, ou só dos desejados) some inteira
  form.querySelectorAll("fieldset.secao-cadastro").forEach(function (fs) {
    fs.hidden = fs.querySelector("[data-sub]:not([hidden])") === null;
  });
}

// Num formulário com menus que escolhem partes (select[data-mostra] e as partes com data-grupo e data-valor): fica à vista,
// e vai no envio, só a parte da opção escolhida em cada menu; uma parte dentro de outra escondida também se esconde. Os
// campos das partes escondidas ficam desativados, para não irem no envio nem barrarem o envio com um "obrigatório"
function aplicarEscolhas(form) {
  var escolha = {};
  form.querySelectorAll("select[data-mostra]").forEach(function (s) {
    escolha[s.getAttribute("data-mostra")] = s.value;
  });
  form.querySelectorAll("[data-grupo]").forEach(function (parte) {
    var dentro = parte.parentElement.closest("[data-grupo]");
    parte.hidden = escolha[parte.getAttribute("data-grupo")] !== parte.getAttribute("data-valor") || (dentro !== null && dentro.hidden);
  });
  form.querySelectorAll("input, select, textarea, button").forEach(function (c) {
    c.disabled = c.closest("[data-grupo][hidden]") !== null;
  });
}

// O que fazer numa sessão, pelo nome dela: em português, "No pulso" vira "Pôr no pulso" e "Tirar do pulso"; "Na caixa", "Pôr na
// caixa" e "Tirar da caixa"; um nome de outro jeito (ou em outro idioma), "Começar: <nome>" e "Terminar: <nome>"
function acaoSessao(nome, tirar) {
  var m = LINGUA === "pt-BR" ? /^(no|na|nos|nas)\s+(.+)$/i.exec(nome) : null;
  if (!m) {
    return tirar ? t("Terminar: {1}", {1: nome}) : t("Começar: {1}", {1: nome});
  }
  var artigo = m[1].toLowerCase();
  return tirar ? t("Tirar {1} {2}", {1: {"no": "do", "na": "da", "nos": "dos", "nas": "das"}[artigo], 2: m[2]}) : t("Pôr {1} {2}", {1: artigo, 2: m[2]});
}

// Segundos por extenso, com as duas maiores partes: "1a 5m", "5d 3h", "12h 22min", "40min"
function duracao(seg) {
  var partes = [[31557600, tk(t("{n}a"))], [2629800, tk(t("{n}m"))], [86400, tk(t("{n}d"))], [3600, tk(t("{n}h"))], [60, tk(t("{n}min"))]];
  var res = [];
  var resto = Math.max(0, Math.round(seg));
  partes.forEach(function (x) {
    var n = Math.floor(resto / x[0]);
    if ((n > 0 || res.length > 0) && res.length < 2) {
      res.push(n === 0 ? "" : t(x[1], {n: n}));
      resto -= n * x[0];
    }
  });
  return res.length === 0 ? t("menos de 1min") : res.filter(function (x) { return x !== ""; }).join(" ");
}

// um campo do cadastro, conforme o tipo dele; aparece só nos grupos em que vale (data-sub: o grupo do campo)
function campoCadastro(c) {
  var v = c.valor === null ? "" : String(c.valor);
  var rotulo = h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "");
  var nome = "valores[" + c.identificador + "]";
  var sub = c.no_id > 0 ? String(c.no_id) : "";
  var res = "";
  var desejo = c.so_desejado ? " data-desejo" : "";
  if (c.tipo === "sim_nao") {
    res = el("label", {"data-sub": sub, "class": "check"}, el("input", {"type": "hidden", "name": nome, "value": "0"}) + el("input", {"type": "checkbox", "name": nome, "value": "1",
      "checked": (v !== "" ? v : String(c.do_calibre !== null && c.do_calibre !== undefined ? c.do_calibre : (c.padrao || ""))) === "1"}) + " " + rotulo);
  } else if (c.multiplo) {
    // vários dos valores possíveis de uma vez (as funções): uma caixa para cada; o vazio escondido deixa desmarcar todos
    var marcados = v === "" ? [] : v.split("\n");
    res = el("fieldset", {"data-sub": sub, "class": "multiplo"}, el("legend", {}, rotulo) + el("input", {"type": "hidden", "name": nome + "[]", "value": ""}) + c.opcoes.map(function (o) {
      return el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": nome + "[]", "value": o, "checked": marcados.indexOf(o) >= 0}) + " " + h(o));
    }).join(""));
  } else if (c.tipo === "lista" || c.opcoes.length > 0) {
    // a lista, e o texto ou o número com valores possíveis: um deles
    res = el("label", {"data-sub": sub}, rotulo + " " + el("select", {"name": nome}, el("option", {"value": ""}, "—") + c.opcoes.map(function (o) {
      return el("option", {"value": o, "selected": o === v}, h(c.tipo === "decimal" ? o.replace(".", ",") : o));
    }).join("")));
  } else if (c.tipo === "data") {
    res = el("label", {"data-sub": sub}, rotulo + " " + el("input", {"type": "date", "name": nome, "value": v}));
  } else if (c.tipo === "texto") {
    res = el("label", {"data-sub": sub}, rotulo + " " + el("input", {"name": nome, "value": v}));
  } else {
    var mostra = v === "" ? "" : (c.unidade === "R$" ? reais(v) : v.replace(".", ","));
    res = el("label", {"data-sub": sub}, rotulo + " " + el("input", {"type": "text", "inputmode": "decimal", "name": nome, "value": mostra,
      "placeholder": c.do_calibre !== null && c.do_calibre !== undefined ? t("vazio: {1} (do calibre)", {1: String(c.do_calibre).replace(".", ",")})
        : (c.padrao !== null && c.padrao !== "" ? t("vazio: {1}", {1: String(c.padrao).replace(".", ",")}) : null)})
      // o mínimo e o máximo (já em texto pela API)
      + (c.regra ? el("small", {}, h(c.regra)) : ""));
  }
  return desejo !== "" ? res.replace("<label ", "<label" + desejo + " ") : res;
}

// Um quadro do painel: o título, o conteúdo e, se houver, o rodapé (os botões, sempre no pé do quadro: os quadros da mesma
// linha da grade têm a mesma altura, e os botões ficam alinhados)
function quadro(titulo, corpo, rodape, classe) {
  return el("section", {"class": "quadro" + (classe ? " " + classe : "")}, el("h2", {}, h(titulo)) + el("div", {"class": "quadro-corpo"}, corpo)
    + (rodape ? el("div", {"class": "quadro-rodape"}, rodape) : ""));
}

// Pares rótulo e valor alinhados em duas colunas (o rótulo vazio continua a linha de cima). O valor já vem em HTML; um
// <small> dentro dele vira a explicação embaixo do valor
function pares(lista, classe) {
  return el("dl", {"class": "pares" + (classe ? " " + classe : "")}, lista.map(function (x) {
    return el("dt", {}, x[0] === "" ? "" : x[0]) + el("dd", {}, x[1]);
  }).join(""));
}

// De onde vem um valor, numa etiqueta ao lado dele, só quando não é o do relógio (o padrão, o calibre, o medido, o cadastro):
// o informado é o normal, e o vazio já é o "—"
function etiquetaOrigem(origem, texto) {
  return origem && origem !== "informado" && origem !== "vazio" ? " " + el("span", {"class": "origem origem-" + origem}, h(texto)) : "";
}

// mostra ou esconde o painel; escondido, o meio da página ocupa a largura toda
function mostrarPainel(mostrar) {
  var painel = document.getElementById("detalhe");
  var area = document.querySelector(".painel");
  if (painel && area) {
    painel.hidden = !mostrar;
    area.classList.toggle("sem-selecao", !mostrar);
    if (!mostrar) {
      painel.innerHTML = "";
      document.querySelectorAll("tr[data-id]").forEach(function (tr) {
        tr.classList.remove("selecionado");
      });
      history.replaceState(null, "", "index.php");
    }
  }
}

// No celular o painel cobre a tela inteira (estilo.css), e abri-lo empilha um passo no histórico do navegador: o "voltar"
// do aparelho fecha o painel, em vez de sair da página
var painelNoHistorico = false;
function fecharPainel() {
  var voltar = painelNoHistorico;
  painelNoHistorico = false;
  mostrarPainel(false);
  if (voltar) {
    history.back();
  }
}
window.addEventListener("popstate", function () {
  if (painelNoHistorico) {
    painelNoHistorico = false;
    mostrarPainel(false);
  }
});

// Abre (ou remonta) o relógio no painel: id > 0, um relógio; 0, o cadastro de um novo. Remontando o mesmo relógio, os
// quadros abertos e a rolagem ficam como estavam.
function abrirNoPainel(id) {
  var painel = document.getElementById("detalhe");
  var fechado = painel.hidden;
  var abertos = [];
  painel.querySelectorAll("details[open]").forEach(function (d) {
    if (d.className) {
      abertos.push(d.className);
    }
  });
  var atual = painel.querySelector(".detalhe");
  var mesmo = atual !== null && atual.getAttribute("data-id") === String(id);
  var rolagem = painel.scrollTop;
  return api(id > 0 ? {recurso: "ficha", relogio: id} : {recurso: "ficha"}).then(function (d) {
    // as explicações do painel: um grupo dele na gaveta (o da página continua)
    ajudaGrupo("painel", d.relogio ? d.relogio.nome : t("O relógio"));
    var noIndex = document.querySelector(".painel") !== null;
    if (noIndex) {
      // como ele abre (a Configuração): ao lado da lista, ou numa janela flutuante grande, com o fundo escurecido (um clique
      // no fundo fecha, como o × e o Esc)
      var area = document.querySelector(".painel");
      area.classList.toggle("modo-flutuante", d.painel_modo === "flutuante");
      if (!area.querySelector(".fundo-painel")) {
        var fundo = document.createElement("div");
        fundo.className = "fundo-painel";
        fundo.setAttribute("data-fechar-painel", "");
        area.insertBefore(fundo, painel);
      }
      mostrarPainel(true);
    }
    // O painel inteiro de um relógio (ou o cadastro de um novo), no HTML do painel do sistema antigo
    var r = d.relogio;
    var novo = r === null;
    var id = novo ? 0 : r.id;
    var fid = novo ? "novo" : String(id);
    var agora = new Date();
    var hoje = d.hoje;
    var res = "";
    if (!novo && recados[id]) {
      res += el("p", {"class": "conta-carga"}, h(recados[id]));
      delete recados[id];
    }
    if (novo) {
      res += el("h1", {}, t("Novo relógio"));
    } else {
      // a foto, com os botões dela embaixo: escolher (o campo de arquivo fica escondido atrás do botão), salvar e remover
      // com o mouse em cima, a dica: o resumo do relógio (da API) e o duplo clique, que abre a foto grande (como o "Abrir foto")
      var foto = r.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + id + "&v=" + r.foto, "alt": t("Foto do {1}", {1: r.nome}), "title": r.dica_foto, "data-foto-grande": r.nome})
        : el("div", {"class": "sem-foto"}, t("Sem foto"));
      foto += el("form", {"data-recurso": "relogio", "class": "foto-acoes"}, (r.foto !== null ? el("button", {"type": "button", "class": "leve", "data-abrir-foto": true,
          "title": t("A foto em tamanho grande (também com duplo clique na foto)")}, t("Abrir foto")) : "")
        + el("input", {"type": "hidden", "name": "acao", "value": "foto"}) + el("input", {"type": "hidden", "name": "id", "value": id})
        + el("input", {"type": "hidden", "name": "foto_base64", "id": "foto_base64_" + id})
        + el("label", {"class": "botao-arquivo"}, el("input", {"type": "file", "accept": "image/*", "data-foto": "foto_base64_" + id, "data-previa": "previa_" + id,
          "data-salvar": "salvar_foto_" + id, "aria-label": t("Escolher foto")}) + (r.foto !== null ? t("Trocar foto") : t("Escolher foto")))
        + el("img", {"id": "previa_" + id, "class": "previa", "hidden": true, "alt": ""})
        + el("button", {"id": "salvar_foto_" + id, "hidden": true}, t("Salvar foto")));
      if (r.foto !== null) {
        foto += el("form", {"data-recurso": "relogio", "class": "foto-remover", "onsubmit": "return confirm(" + JSON.stringify(t("Remover a foto?")) + ")"}, el("input", {"type": "hidden", "name": "acao", "value": "remover_foto"})
          + el("input", {"type": "hidden", "name": "id", "value": id}) + el("button", {"class": "leve discreto"}, t("Remover foto")));
      }
      // no rodízio: quando entra (e até quando fica), ou por que não entra
      var proxima = t("Ainda não sorteado. Neste modo o sorteio é feito na virada de cada período.");
      if (!r.disponivel) {
        proxima = t("Indisponível para o rodízio. Marque como disponível no cadastro.");
      } else if (r.proxima !== null) {
        var fica = r.proxima_um_dia ? "" : r.proxima_ate;
        proxima = r.proxima === hoje ? t("É o relógio de hoje{1}.", {1: fica !== "" ? ", " + fica : ""})
          : t("Entra em {1} ({2}){3}.", {1: dataBr(r.proxima, true), 2: CURTO[new Date(instante(r.proxima)).getDay() || 7], 3: fica !== "" ? t(" e fica {1}", {1: fica}) : ""});
      } else if (r.escala_fim) {
        proxima = t("Não entra na escala atual, que vai até {1}.", {1: dataBr(r.escala_fim)});
      }
      var ultima = r.leituras.length > 0 ? r.leituras[r.leituras.length - 1] : null;
      var nada = function (motivo) { return el("span", {"class": "nota"}, h(motivo || t("sem dados"))); };
      // declarado, medido e estimado: uma informação técnica (r.tecnico) numa tabelinha de três linhas (o que o fabricante ou o
      // cadastro diz, o último teste e a estimativa de agora), cada uma com o detalhe e a data, e a origem ao lado do valor quando
      // não é o informado; embaixo, a confiança
      var tabelaTecnica = function (chave) {
        var it = (r.tecnico || []).filter(function (x) { return x.chave === chave; })[0];
        if (!it) {
          return "";
        }
        var linha = function (rotulo, x, classe) {
          return el("tr", {"class": (x.texto === null ? "vazio " : "") + (classe || "")}, el("th", {"scope": "row"}, rotulo)
            + el("td", {}, (x.texto !== null ? el("strong", {}, h(x.texto)) : "—") + etiquetaOrigem(x.origem, x.origem_texto)
              + (x.detalhe !== null || x.data !== null ? el("small", {}, (x.detalhe !== null ? h(x.detalhe) : "") + (x.detalhe !== null && x.data !== null ? " · " : "")
                + (x.data !== null ? dataBr(x.data.substr(0, 10)) + (x.data.length > 10 ? " " + horaBr(x.data) : "") : "")) : "")));
        };
        return el("table", {"class": "tabela-dados tecnico", "data-tecnico": it.chave}, el("tbody", {}, linha(t("Declarado"), it.declarado)
            + linha(t("Último teste"), it.medido, it.fora ? "fora" : "") + linha(t("Estimativa agora"), it.estimado)))
          + (it.confianca ? el("p", {"class": "nota confianca confianca-" + it.confianca.nivel}, h(it.confianca.resumo)) : "");
      };
      // Agora: o estado, a carga (com a barra) e o que o sistema sabe da situação
      var carga = r.carga !== null
        ? el("span", {"class": "carga-linha" + (r.carga <= 20 ? " baixa" : "")}, el("span", {"class": "barra"}, el("span", {"style": "width:" + Math.max(0, Math.min(100, r.carga)) + "%"}, ""))
          + el("strong", {}, "~" + r.carga + "%")) + el("small", {}, h(r.carga_de))
        : nada(r.carga_de);
      // os quadros, cada um na seção do assunto dele (montadas no fim, depois dos quadros largos): o uso de agora e o rodízio,
      // a carga e a autonomia, a precisão, o cadastro com a compra e os documentos, e a linha do tempo
      var secoes = {uso: "", carga: "", precisao: "", cadastro: "", historico: ""};
      secoes.uso += quadro(t("Agora"), pares([[t("Estado"), el("span", {"class": "estado estado-" + (r.estado)}, h(r.agora))], [t("Carga"), carga]]
        .concat(r.encostado ? [[t("Encostado"), el("span", {"class": r.encostado.estado !== "ok" ? "em atrasado" : ""}, h(r.encostado.falta))]] : [])
        .concat(r.situacao.map(function (l, i) { return [i === 0 ? t("Situação") : "", h(l)]; }))
        .concat(ultima ? [[t("Última leitura"), num(ultima.valor, 1) + h(ultima.unidade) + " " + el("small", {}, dataBr(ultima.inicio, true) + " " + horaBr(ultima.inicio))]] : [])));
      if (r.previsao.aplica) {
        // as frases da previsão e, embaixo, de onde vem cada número da conta: o que é (e como foi medido, e a origem quando não é
        // o informado) e o valor
        var contaPrev = r.previsao.conta.length === 0 ? "" : el("h3", {"class": "conta-titulo"}, t("De onde vem a conta"))
          + el("table", {"class": "tabela-dados conta-previsao"}, el("tbody", {}, r.previsao.conta.map(function (cc) {
            // o gasto que vale (em cima, em negrito) e os campos que ficaram fora da conta (o medido vale no lugar deles), apagados
            return el("tr", {"class": (cc.valor === null ? "vazio " : "") + (cc.vale ? "vale " : "") + (cc.usado === false ? "fora-da-conta" : "")}, el("td", {}, h(cc.nome) + etiquetaOrigem(cc.origem, cc.origem_texto) + (cc.detalhe ? el("small", {}, h(cc.detalhe)) : ""))
              + el("td", {"class": "valor"}, cc.valor === null ? "—" : num(cc.valor, 2) + (cc.unidade !== "" ? el("small", {}, h(cc.unidade)) : "")));
          }).join("")));
        secoes.carga += quadro(t("Previsão"), r.previsao.linhas.length === 0 ? el("p", {"class": "nota"}, t("Informe a carga atual para o sistema começar a prever."))
          : el("ul", {"class": "linhas"}, r.previsao.linhas.map(function (l) { return el("li", {}, h(l)); }).join("")) + (contaPrev ? el("div", {"data-avancado": "previsao.conta"}, contaPrev) : ""));
      }
      // os dois gastos: o que vale na conta, e de onde ele vem (o medido pelas leituras, o do cadastro)
      var gs = r.previsao.gasto;
      if (gs) {
        var pct = function (v) { return num(v, 2) + "%"; };
        // o que vale na conta e, embaixo, de onde vem: o medido pelas leituras e o cadastro (com os campos informados dele);
        // no motor "só o cadastro", vale o cadastro e o medido aparece só para comparar
        var gsD = gs;
        var motorDe = {colecao: t("a escolha da coleção, na Configuração"), relogio: t("a escolha deste relógio, no cadastro"), grupo: t("a escolha do grupo, no cadastro"), padrao: t("o padrão: nada escolhido")};
        var gasto = function (por, x) {
          var campos = (x.campos || []).map(function (c) {
            // a origem entre parênteses só quando não é o informado (o vazio já é o "—")
            var v = c.valor !== null ? num(c.valor, 2) + (c.unidade !== "" ? " " + h(c.unidade) : "") : "—";
            return c.origem === "informado" || c.origem === "vazio" ? t("{1}: {2}", {1: h(c.nome), 2: v}) : t("{1}: {2} ({3})", {1: h(c.nome), 2: v, 3: h(c.origem_texto)});
          }).join(" · ");
          return x.vale !== null ? el("strong", {}, pct(x.vale)) + (x.medido !== null && x.medido_dp !== null ? " " + el("span", {"class": "nota"}, t("± {1}", {1: num(x.medido_dp, 2)})) : "") + " " + por
            + el("small", {}, gsD.motor === "cadastro" ? (x.medido !== null ? t("do cadastro (o motor escolhido); o medido daria {1}", {1: pct(x.medido)}) : t("do cadastro (o motor escolhido)"))
              : (x.medido !== null ? t("medido; o cadastro diz {1}", {1: x.cadastro !== null ? pct(x.cadastro) : t("nada")}) : t("do cadastro, sem medição")))
            + (gsD.motor !== "cadastro" && x.puxado ? el("small", {}, t("só as leituras dariam {1} ({2}{3}): com poucas medições, o cadastro ainda puxa a conta",
              {1: pct(x.so_leituras), 2: x.medicoes, 3: x.medicoes === 1 ? t(" medição") : t(" medições")})) : "")
            + (campos !== "" ? el("small", {}, t("no cadastro: {1}", {1: campos})) : "")
            : nada();
        };
        // qual motor calcula, e de onde veio a escolha (coleção › relógio › grupo › padrão)
        var linhasGasto = [[t("Calculado por"), h(gs.motor_nome) + el("small", {}, motorDe[gs.motor_de] || h(gs.motor_de))],
          [t("No pulso"), gasto(t("por dia de uso"), gs.uso)], [t("Fora do pulso"), gasto(t("por dia"), gs.repouso)]];
        if (gs.uso.antes !== null || gs.repouso.antes !== null) {
          linhasGasto.push([t("Janela anterior"), (gs.uso.antes !== null ? t("{1} no pulso", {1: pct(gs.uso.antes)}) : "") + (gs.uso.antes !== null && gs.repouso.antes !== null ? " · " : "")
            + (gs.repouso.antes !== null ? t("{1} fora", {1: pct(gs.repouso.antes)}) : "") + el("small", {}, gs.base === "leituras" ? t("{1}{2}, nas {3} leituras de cada tipo antes dessas", {1: gs.medicoes_antes, 2: gs.medicoes_antes === 1 ? t(" medição") : t(" medições"), 3: gs.janela_leituras}) : t("{1}{2}, nos {3} dias antes", {1: gs.medicoes_antes, 2: gs.medicoes_antes === 1 ? t(" medição") : t(" medições"), 3: gs.janela_dias}))]);
        }
        linhasGasto.push([t("Medições"), gs.medicoes > 0 ? t("{1} na conta, {2}{3}", {1: gs.medicoes, 2: h(gs.janela_texto), 3: el("small", {}, gs.motor === "marcacao" || gs.motor === "formulas" ? t("pela marcação: {1} em uso e {2} em repouso", {1: gs.uso.medicoes, 2: gs.repouso.medicoes})
            : (gs.conjunta ? t("os dois gastos saem juntos da conta") : t("ainda não separam bem os dois: vale a média de cada um")))
            + (gs.fora > 0 ? el("small", {"class": "em atrasado"}, t("{1} com uma leitura que não bate com as outras e pesa pouco na conta (veja Medições do gasto)", {1: gs.fora})) : "")})
          : t("nenhuma na janela ({1}){2}", {1: h(gs.janela_texto), 2: gs.uso.medido !== null || gs.repouso.medido !== null ? el("small", {}, t("vale a conta com as de antes")) : ""})]);
        secoes.carga += quadro(t("Gasto da bateria"), pares(linhasGasto), ajudaNota("janela-gasto", t("A janela do gasto medido"), t("Medições mais velhas que a janela saem da conta: se a bateria envelhecer e gastar mais, o medido sobe sozinho.")));
      }
      // a autonomia: cheio pelo cadastro e pela conta (com o gasto medido), quanto ainda dura e quando acaba
      var au = r.autonomia;
      var dura = function (seg, motivo) { return seg !== null ? el("strong", {}, duracao(seg)) : nada(motivo); };
      secoes.carga += quadro(t("Autonomia"), pares([
        [t("Cheio, pelo cadastro"), dura(au.autonomia_prevista, au.motivos.autonomia_prevista)],
        [t("Cheio, pela conta"), dura(au.autonomia_atual, au.motivos.autonomia_atual)],
        [t("Acaba, seguindo o plano"), au.acaba_em_unixtimestamp !== null ? el("strong", {}, dataBr(au.acaba_em_datacomtz.substr(0, 10), au.acaba_em_datacomtz.substr(0, 4) === hoje.substr(0, 4))
          + " " + au.acaba_em_datacomtz.substr(11, 5)) + el("small", {}, t("em {1}", {1: duracao(au.acaba_em_segundos)})) : nada(au.motivos.autonomia_estimada)],
        [t("No pulso sem tirar"), dura(au.restante_em_uso, au.restante_em_uso === null && au.energia !== null ? t("não acaba (no pulso ele se recarrega)") : au.motivos.restante_em_uso)],
        [t("Guardado"), dura(au.restante_guardado, au.motivos.restante_guardado)]]));
      // a reserva medida: no mecânico, o teste da reserva (cheio, por qual protocolo, até parar) e a mediana dos testes; no
      // solar, só as observações (parou, o indicador de carga baixa), sem calibrar
      var rs = r.reserva;
      if (rs) {
        var momentoCampo = function (nome, valor, obrigatorio) {
          return el("input", {"type": "datetime-local", "name": nome, "value": valor ? valor.substr(0, 16).replace(" ", "T") : momentoNavegador(new Date()),
            "max": momentoNavegador(new Date()), "data-agora": valor ? false : "vazio", "data-max-agora": true, "required": !!obrigatorio});
        };
        var formTeste = function (x) {
          return el("form", {"data-recurso": "reserva", "class": "reserva-form"}, el("input", {"type": "hidden", "name": "acao", "value": x ? "salvar" : "iniciar"})
            + (x ? el("input", {"type": "hidden", "name": "id", "value": x.id}) : el("input", {"type": "hidden", "name": "relogio_id", "value": id}))
            + el("label", {}, t("Cheio em {1}", {1: momentoCampo("inicio", x ? x.inicio : null)}))
            + (x ? el("label", {}, t("Parou em {1}", {1: el("input", {"type": "datetime-local", "name": "fim", "value": x.fim ? x.fim.substr(0, 16).replace(" ", "T") : "",
              "max": momentoNavegador(new Date()), "data-max-agora": true})})) : "")
            + el("label", {}, t("Como encheu {1}", {1: el("select", {"name": "protocolo"}, rs.protocolos.map(function (p) {
              return el("option", {"value": p.valor, "selected": (x ? x.protocolo : "corda") === p.valor}, h(p.nome) + (p.unidade !== "" ? " (" + h(p.unidade) + ")" : ""));
            }).join(""))}))
            + el("label", {}, t("Voltas ou horas {1}", {1: el("input", {"name": "protocolo_valor", "inputmode": "decimal", "value": x && x.protocolo_valor !== null ? String(x.protocolo_valor) : ""})}))
            + el("label", {"class": "largo"}, t("Observação {1}", {1: el("input", {"name": "observacao", "maxlength": 500, "value": x && x.observacao !== null ? x.observacao : ""})}))
            + el("div", {"class": "botoes largo"}, el("button", {}, x ? t("Salvar o teste") : t("Começar o teste"))));
        };
        var formObs = function (x) {
          return el("form", {"data-recurso": "reserva", "class": "reserva-form"}, el("input", {"type": "hidden", "name": "acao", "value": x ? "salvar" : "observar"})
            + (x ? el("input", {"type": "hidden", "name": "id", "value": x.id}) : el("input", {"type": "hidden", "name": "relogio_id", "value": id}))
            + el("label", {}, t("O que foi {1}", {1: el("select", {"name": "tipo"}, rs.tipos_observacao.map(function (o) {
              return el("option", {"value": o.valor, "selected": (x ? x.tipo : "parou") === o.valor}, h(o.nome));
            }).join(""))}))
            + el("label", {}, t("Quando {1}", {1: momentoCampo("quando", x ? x.inicio : null)}))
            + el("label", {"class": "largo"}, t("Observação {1}", {1: el("input", {"name": "observacao", "maxlength": 500, "value": x && x.observacao !== null ? x.observacao : ""})}))
            + el("div", {"class": "botoes largo"}, el("button", {}, x ? t("Salvar a observação") : t("Registrar a observação"))));
        };
        var excluirReg = function (x, rotulo) {
          return el("form", {"data-recurso": "reserva", "class": "excluir"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
            + el("button", {"class": "leve discreto"}, rotulo));
        };
        var quando = function (x) { return dataBr(x.substr(0, 10)) + " " + horaBr(x); };
        var corpoRs = tabelaTecnica(rs.testar ? "reserva" : "reserva_energia");
        if (rs.aberto) {
          corpoRs += el("p", {"class": "reserva-aberto"}, t("Em teste desde {1}, {2}: {3} até agora. Deixe ele parado, sem corda nem pulso.", {1: quando(rs.aberto.inicio), 2: h(rs.aberto.protocolo_texto),
              3: t("{1} h", {1: num(rs.aberto.ate_agora, 1)})}))
            + el("form", {"data-recurso": "reserva", "class": "reserva-parou"}, el("input", {"type": "hidden", "name": "acao", "value": "parou"}) + el("input", {"type": "hidden", "name": "id", "value": rs.aberto.id})
              + el("label", {}, t("Parou em {1}", {1: momentoCampo("fim", null)})) + el("button", {}, t("Parou")));
        }
        if (rs.usar_horas !== null) {
          corpoRs += el("form", {"data-recurso": "reserva", "class": "reserva-usar", "onsubmit": "return confirm(" + JSON.stringify(t("Gravar {1} h como a reserva de marcha do cadastro deste relógio? O calibre e o grupo não mudam.", {1: rs.usar_horas})) + ")"},
            el("input", {"type": "hidden", "name": "acao", "value": "usar"}) + el("input", {"type": "hidden", "name": "relogio_id", "value": id})
            + el("button", {"class": "leve"}, t("Usar {1} h no cadastro deste relógio", {1: rs.usar_horas})));
        }
        corpoRs += rs.testes.map(function (x) {
          return el("details", {"class": "reserva-registro"}, el("summary", {}, el("strong", {}, dataBr(x.inicio.substr(0, 10))) + " · "
              + (x.em_andamento ? t("em andamento") : h(x.horas_texto)) + " · " + h(x.protocolo_texto))
            + (x.observacao !== null ? el("p", {"class": "nota"}, h(x.observacao)) : "") + formTeste(x) + excluirReg(x, t("Excluir este teste")));
        }).join("") + rs.observacoes.map(function (x) {
          return el("details", {"class": "reserva-registro"}, el("summary", {}, el("strong", {}, dataBr(x.inicio.substr(0, 10))) + " · " + h(x.tipo_texto)
              + (x.observacao !== null ? " · " + h(x.observacao) : ""))
            + formObs(x) + excluirReg(x, t("Excluir esta observação")));
        }).join("");
        if (rs.testar && !rs.aberto) {
          corpoRs += el("details", {"class": "reserva-novo"}, el("summary", {}, t("Começar um teste da reserva"))
            + ajudaNota("testar-reserva", t("Como testar a reserva"), t("Encha até o fim (a corda pela coroa até travar, ou um tempo no winder ou no pulso), marque quando ficou cheio e deixe ele parado; quando ele parar, marque aqui. O tempo de cheio até parado é a reserva medida."))
            + formTeste(null));
        }
        corpoRs += el("details", {"class": "reserva-novo"}, el("summary", {}, t("Registrar uma observação")) + formObs(null));
        secoes.carga += quadro(rs.testar ? t("Reserva medida") : t("Reserva de energia"), corpoRs, "", "largo reserva-ficha");
      }
      secoes.uso += quadro(t("Rodízio"), pares([
        [t("Nota"), r.nota !== null ? el("strong", {}, num(r.nota.nota, 1)) + " " + avancado("rodizio.conta", el("a", {"href": "criterios.php?relogio=" + id + "#r" + id, "target": "_blank", "rel": "noopener"}, t("ver a conta"))) : nada(t("fora do rodízio"))],
        [t("Critérios de"), r.nota !== null ? h(r.nota.conjunto_texto) : "—"],
        [t("Próxima vez"), h(proxima)]]),
        r.disponivel && r.proxima !== hoje && d.trocar_hoje ? el("form", {"data-recurso": "rodizio", "onsubmit": "return confirm(" + JSON.stringify(t("Usar o {1} hoje? Ele passa a ser o relógio do rodízio no período atual e o plano é refeito.", {1: r.nome})) + ")"}, el("input", {"type": "hidden", "name": "acao", "value": "usando"})
          + el("input", {"type": "hidden", "name": "relogio_id", "value": id}) + el("button", {"class": "leve"}, t("Usando hoje"))) : "");
      // a precisão (com o tipo de lançamento que mede a marcha): o resumo da API, a marcha, a última, o desvio estimado agora e a
      // esperada; a última fora da esperada em âmbar
      var pr = r.precisao;
      if (pr) {
        secoes.precisao += quadro(t("Precisão"), el("p", {"class": "nota"}, h(pr.resumo)) + tabelaTecnica("marcha")
          + (pr.pontos.length > 0 ? pares([[t("Desvio estimado agora"), h(pr.desvio_agora_texto)]]) : ""), linkRelatorio("precisao", {relogio: id}, t("Relatório da precisão"), true));
      }
      var c = r.compra;
      secoes.cadastro += quadro(t("Compra"), c.data === null && c.valor === null && c.loja === null && c.garantia_ate === null
        ? el("p", {"class": "nota"}, t("Sem dados da compra. Preencha em \"Editar cadastro\"."))
        : pares([[t("Valor pago"), c.valor !== null ? el("strong", {}, t("R$ ") + reais(c.valor)) : "—"], [t("Loja"), c.loja !== null ? h(c.loja) : "—"],
          [t("Comprado em"), c.data !== null ? dataBr(c.data) + el("small", {}, t("há {1}{2}", {1: c.ha_dias, 2: c.ha_dias === 1 ? t(" dia") : t(" dias")})) : "—"],
          [t("Garantia"), c.garantia_ate !== null ? (c.garantia_ate >= hoje ? t("até {1}", {1: dataBr(c.garantia_ate)}) : t("vencida em {1}", {1: dataBr(c.garantia_ate)})) : "—"],
          [t("Custo por uso"), c.custo_por_uso !== null ? el("strong", {}, t("R$ ") + reais(c.custo_por_uso)) + el("small", {}, c.dias_de_uso + (c.dias_de_uso === 1 ? t(" dia de uso") : t(" dias de uso")))
            : nada(c.dias_de_uso === 0 ? t("ainda não foi ao pulso") : t("sem o valor pago"))]]));
      // os documentos: quantos em cada categoria, cada uma abrindo a página Documentos (numa aba nova) já nela
      var dc = r.documentos;
      var quadroDocs = quadro(t("Documentos"), (dc.total === 0 ? el("p", {"class": "nota"}, t("Nenhum ainda: o manual, a nota fiscal, fotos, vídeos..."))
          : pares(dc.categorias.map(function (cat) {
            return [h(cat.nome), el("a", {"href": "documentos.php?relogio=" + id + "&cat=" + cat.id, "target": "_blank", "rel": "noopener"}, cat.documentos + (cat.documentos === 1 ? t(" arquivo") : t(" arquivos")))];
          })))
        + (dc.pasta_ok ? "" : el("p", {"class": "nota"}, t("Para guardar documentos, falta a pasta deles no config.php (DOCUMENTOS_PASTA)."))),
        el("a", {"href": "documentos.php?relogio=" + id, "target": "_blank", "rel": "noopener", "class": "botao leve"}, dc.total === 0 ? t("Enviar documentos") : t("Abrir os documentos"))
        // o passaporte: a ficha dele para imprimir (ou guardar em PDF)
        + " " + el("a", {"href": "passaporte.php?id=" + id, "target": "_blank", "rel": "noopener", "class": "botao leve"}, t("Passaporte")), "documentos-ficha");
      secoes.cadastro += quadroDocs;
      // os acessórios dele (a pulseira, a caixa, a ferramenta...): o tipo e o nome; cadastrar e mudar, na página Acessórios
      // os dados de cada um (a largura, o material...) embaixo do nome; e os de outros relógios (ou soltos) que servem nele
      secoes.cadastro += quadro(t("Acessórios"), (r.acessorios.length === 0 ? el("p", {"class": "nota"}, t("Nenhum: a pulseira extra, a caixa, o estojo, a ferramenta..."))
        : pares(r.acessorios.map(function (a) {
          return [h(a.tipo_texto), h(a.nome) + (a.valores.length > 0 ? el("small", {}, a.valores.map(function (x) { return h(x.nome) + ": " + h(x.texto); }).join(" · ")) : "")
            + (a.observacao !== null ? el("small", {}, h(a.observacao)) : "")];
        })))
        + (r.acessorios_servem.length > 0 ? el("h3", {}, t("Servem neste relógio")) + pares(r.acessorios_servem.map(function (a) {
          return [h(a.tipo_texto), h(a.nome) + el("small", {}, (a.relogio !== null ? t("do {1}", {1: h(a.relogio)}) : t("solto")) + " · " + h(a.serve_por.join(" · ")))];
        })) : ""),
        el("a", {"href": "acessorios.php?relogio=" + id, "target": "_blank", "rel": "noopener", "class": "botao leve"}, r.acessorios.length === 0 ? t("Cadastrar acessórios") : t("Ver os acessórios")), "acessorios-ficha");
      // os serviços e diagnósticos: em cima, a resistência à água declarada e a estanqueidade comprovada (uma não vale pela outra),
      // o custo somado e o último; cada serviço numa caixa que abre (os detalhes e o formulário para mudar ou excluir); embaixo,
      // "Registrar um serviço" (o mesmo formulário, vazio)
      var sr = r.servicos_resumo;
      var formServico = function (s) {
        var v = function (k) { return s && s[k] !== null && s[k] !== undefined ? String(s[k]) : ""; };
        var docs = r.servico_documentos.length === 0 ? el("p", {"class": "nota"}, t("Nenhum documento no relógio ainda (a nota, o orçamento, as fotos do serviço vão em Documentos)."))
          : el("input", {"type": "hidden", "name": "documentos[]", "value": ""}) + r.servico_documentos.map(function (x) {
            return el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "documentos[]", "value": x.id, "checked": !!s && s.documentos.some(function (y) { return y.id === x.id; })}) + " " + h(x.titulo));
          }).join("");
        return el("form", {"data-recurso": "servicos", "class": "servico-form"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
          + (s ? el("input", {"type": "hidden", "name": "id", "value": s.id}) : el("input", {"type": "hidden", "name": "relogio_id", "value": id}))
          + el("label", {}, t("Data {1}", {1: el("input", {"type": "date", "name": "data", "value": s ? s.data : hoje, "max": hoje, "required": true})}))
          + el("label", {}, t("Tipo {1}", {1: el("select", {"name": "tipo"}, r.servico_tipos.map(function (x) {
            return el("option", {"value": x.valor, "selected": (s ? s.tipo : "revisao") === x.valor}, h(x.nome));
          }).join(""))}))
          + el("label", {}, t("Quem fez {1}", {1: el("input", {"name": "responsavel", "value": v("responsavel"), "maxlength": 120})}))
          + el("label", {}, t("Mão de obra (R$) {1}", {1: el("input", {"name": "mao_de_obra", "inputmode": "decimal", "value": v("mao_de_obra")})}))
          + el("label", {}, t("Estanqueidade {1}", {1: el("select", {"name": "estanqueidade"}, el("option", {"value": ""}, t("não testada"))
            + el("option", {"value": "aprovado", "selected": v("estanqueidade") === "aprovado"}, t("aprovada")) + el("option", {"value": "reprovado", "selected": v("estanqueidade") === "reprovado"}, t("reprovada")))}))
          + el("label", {}, t("Pressão do teste (bar) {1}", {1: el("input", {"name": "estanqueidade_bar", "inputmode": "decimal", "value": v("estanqueidade_bar")})}))
          + el("label", {}, t("Marcha antes (s/dia) {1}", {1: el("input", {"name": "marcha_antes", "inputmode": "decimal", "value": v("marcha_antes")})}))
          + el("label", {}, t("Marcha depois (s/dia) {1}", {1: el("input", {"name": "marcha_depois", "inputmode": "decimal", "value": v("marcha_depois")})}))
          + el("label", {}, t("Garantia até {1}", {1: el("input", {"type": "date", "name": "garantia_ate", "value": v("garantia_ate")})}))
          + el("label", {"class": "largo"}, t("O que foi feito {1}", {1: el("textarea", {"name": "descricao", "rows": 2, "maxlength": 4000}, h(v("descricao")))}))
          + el("label", {"class": "largo"}, t("Peças trocadas {1} {2}", {1: el("textarea", {"name": "pecas", "rows": 2, "placeholder": t("Coroa; 120,00")}, s ? h(s.pecas.map(function (p) {
            return p.nome + (p.custo !== null ? "; " + String(p.custo) : "");
          }).join("\n")) : ""), 2: el("small", {}, t("uma por linha; o custo, se houver, depois do ponto e vírgula"))}))
          + el("label", {"class": "largo"}, t("Observação {1}", {1: el("textarea", {"name": "observacao", "rows": 3}, h(v("observacao")))}))
          + el("fieldset", {"class": "largo servico-docs"}, el("legend", {}, t("Documentos do serviço")) + docs)
          + el("div", {"class": "botoes largo"}, el("button", {}, s ? t("Salvar o serviço") : t("Registrar o serviço"))));
      };
      var linhaServico = function (s) {
        var medida = function (m) { return m !== null ? t("{1} ({2} medidas)", {1: h(m.marcha_texto), 2: m.intervalos}) : nada(t("sem leituras do desvio")); };
        return el("details", {"class": "servico"}, el("summary", {}, el("strong", {}, dataBr(s.data)) + " · " + h(s.tipo_texto)
            + (s.responsavel !== null ? " · " + h(s.responsavel) : "") + (s.custo !== null ? " · " + t("R$ ") + reais(s.custo) : ""))
          + pares([[t("O que foi feito"), s.descricao !== null ? h(s.descricao) : nada("—")],
            [t("Peças"), s.pecas.length === 0 ? nada("—") : s.pecas.map(function (p) { return h(p.nome) + (p.custo !== null ? " " + el("small", {}, t("R$ ") + reais(p.custo)) : ""); }).join("<br>")],
            [t("Custo"), s.custo !== null ? el("strong", {}, t("R$ ") + reais(s.custo)) + el("small", {}, t("mão de obra {1} · peças {2}", {1: s.mao_de_obra !== null ? t("R$ ") + reais(s.mao_de_obra) : "—", 2: t("R$ ") + reais(s.custo_pecas)})) : nada("—")],
            [t("Estanqueidade"), s.estanqueidade !== null ? el("span", {"class": s.estanqueidade === "reprovado" ? "em atrasado" : ""}, h(s.estanqueidade_texto))
              + (s.estanqueidade_bar !== null ? " " + t("a {1} bar", {1: num(s.estanqueidade_bar, 1)}) : "") : nada(t("não testada"))],
            [t("Marcha antes"), (s.marcha_antes_texto !== null ? h(s.marcha_antes_texto) + el("small", {}, t("informada")) : "") + el("small", {}, t("medida pelo sistema: {1}", {1: medida(s.medida_antes)}))],
            [t("Marcha depois"), (s.marcha_depois_texto !== null ? h(s.marcha_depois_texto) + el("small", {}, t("informada")) : "") + el("small", {}, t("medida pelo sistema: {1}", {1: medida(s.medida_depois)}))],
            [t("Garantia"), s.garantia_ate !== null ? (s.garantia_vale ? t("até {1}", {1: dataBr(s.garantia_ate)}) : t("vencida em {1}", {1: dataBr(s.garantia_ate)})) : nada("—")],
            [t("Documentos"), s.documentos.length === 0 ? nada("—") : s.documentos.map(function (x) { return el("a", {"href": x.url, "target": "_blank", "rel": "noopener"}, h(x.titulo)); }).join(" · ")],
            [t("Observação"), s.observacao !== null ? h(s.observacao) : nada("—")]]
            .concat(s.marcacao ? [[t("Marcação"), t("também marcado como {1} na data do serviço", {1: h(s.tipo_texto.toLowerCase())})]] : []))
          + el("details", {"class": "servico-editar"}, el("summary", {}, t("Mudar este serviço")) + formServico(s)
            + el("form", {"data-recurso": "servicos", "class": "excluir"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": s.id})
              + el("button", {"class": "leve discreto"}, t("Excluir este serviço")))));
      };
      secoes.cadastro += quadro(t("Serviços e diagnósticos"), tabelaTecnica("estanqueidade") + pares([
        [t("Serviços"), sr.quantos === 0 ? nada(t("nenhum registrado")) : sr.quantos + (sr.quantos === 1 ? t(" serviço") : t(" serviços"))
          + (sr.ultimo !== null ? el("small", {}, t("o último: {1} em {2}", {1: h(sr.ultimo.tipo_texto.toLowerCase()), 2: dataBr(sr.ultimo.data)})) : "")],
        [t("Custo somado"), sr.custo !== null ? el("strong", {}, t("R$ ") + reais(sr.custo)) : nada("—")]])
        + r.servicos.map(linhaServico).join("")
        + el("details", {"class": "servico-novo"}, el("summary", {}, t("Registrar um serviço")) + formServico(null)),
        linkRelatorio("servicos", {relogio: id}, t("Relatório dos serviços"), true), "largo servicos-ficha");
      secoes.uso += quadro(t("Próximas manutenções"),r.manutencoes.length === 0 ? el("p", {"class": "nota"}, t("Nada previsto."))
        : pares(r.manutencoes.map(function (m) { return [dataBr(m.data), h(m.nome)]; }), "datas"));
      // marcar: um quadro só. O menu diz o que aconteceu, e embaixo aparecem só os campos daquilo: pôr ou tirar (cada sessão,
      // só o que cabe agora), uma marcação de um momento (corda, pilha...), a leitura (carga), um período que já passou, ou
      // corrigir uma marcação dos últimos 14 dias (um segundo menu escolhe qual)
      if (r.tipos.length > 0) {
        var sessoes = r.tipos.filter(function (item) { return item.formato === "sessao"; });
        var opcoes = {agora: "", passou: "", corrigir: ""};
        var partes = "";
        var parte = function (grupo, valor, conteudo) {
          return el("div", {"class": "marcar-campos", "data-grupo": grupo, "data-valor": valor, "hidden": true}, conteudo);
        };
        var quando = function (rotulo) {
          return el("label", {}, rotulo + " " + el("input", {"type": "datetime-local", "name": "quando", "value": momentoNavegador(new Date()),
            "max": momentoNavegador(new Date()), "data-agora": "vazio", "data-max-agora": true})
            + el("small", {}, t("Anda com o relógio até você mudar (vazio: agora).")));
        };
        var momento = function (nome, valor, obrigatorio, anda) {
          return el("input", {"type": "datetime-local", "name": nome, "value": valor, "max": momentoNavegador(new Date()), "data-max-agora": true,
            "data-agora": anda === true, "required": obrigatorio});
        };
        var pulsoTipo = r.tipos.filter(function (item) { return item.identificador === "pulso" && item.formato === "sessao"; })[0] || null;
        var tipoMede = {};
        r.tipos.forEach(function (item) { tipoMede[item.identificador] = item.mede_gasto; });
        r.tipos.forEach(function (item) {
          var chave = "t-" + item.identificador;
          var base = el("input", {"type": "hidden", "name": "tipo", "value": item.identificador});
          if (item.formato === "sessao" && item.winder && item.aberta === null && r.winder_lugar && r.winder_lugar.livre) {
            // o lugar fixo: o relógio que tem o lugar dele no winder, livre, volta para lá daqui (a API escolhe a posição)
            opcoes.agora += el("option", {"value": chave}, h(acaoSessao(item.nome, false)));
            partes += parte("marcar", chave, base + el("input", {"type": "hidden", "name": "acao", "value": "iniciar"})
              + el("p", {"class": "marcar-estado"}, t("Volta para o lugar dele: {1}", {1: h(r.winder_lugar.texto)}))
              + quando(t("Pôs às")) + el("button", {}, h(acaoSessao(item.nome, false))));
          } else if (item.formato === "sessao" && item.winder && item.aberta === null) {
            // o winder é obrigatório: sem o lugar dele livre, pôr no winder não se faz aqui. Com winder cadastrado, pela página
            // Winders, numa posição (aqui, o link); sem winder, não há pôr nem tirar (o tirar de uma sessão que já estava
            // aberta, sim: o ramo de baixo)
            opcoes.agora += el("option", {"value": chave}, h(acaoSessao(item.nome, false)));
            partes += parte("marcar", chave, el("p", {"class": "nota"}, (r.winder_cadastrado
              ? (r.winder_lugar ? t("Não dá para voltar para o lugar dele agora: {1}. Ponha numa posição pela página Winders.", {1: h(r.winder_lugar.motivo)})
                : t("Com winder cadastrado, o relógio vai para o winder pela página Winders, numa posição; depois, daqui mesmo ele volta para o lugar dele."))
              : t("Sem winder cadastrado não há pôr nem tirar do winder. Para usar, cadastre o seu na página Winders."))
              + " " + el("a", {"href": "winders.php"}, t("Abrir a página Winders ›"))));
          } else if (item.formato === "sessao") {
            var tirar = item.aberta !== null;
            var verbo = acaoSessao(item.nome, tirar);
            opcoes.agora += el("option", {"value": chave}, h(verbo));
            partes += parte("marcar", chave, base + el("input", {"type": "hidden", "name": "acao", "value": tirar ? "encerrar" : "iniciar"})
              + (tirar ? el("p", {"class": "marcar-estado"}, h(item.aberta.texto)) : "")
              + quando(tirar ? t("Tirou às") : t("Pôs às")) + el("button", {}, h(verbo)));
          } else if (item.marcha === "desvio" && r.precisao) {
            // a leitura do desvio da hora (o Testar a precisão): a hora certa andando na tela; a pessoa põe a hora que o relógio vai
            // mostrar e aperta o botão quando o ponteiro chegar nela (o instante vai pela hora certa: instante_ms); ou digita o desvio
            opcoes.agora += el("option", {"value": chave}, t("Testar a precisão (o desvio da hora)"));
            partes += parte("marcar", chave, base + el("input", {"type": "hidden", "name": "acao", "value": "lancar"})
              + el("input", {"type": "hidden", "name": "instante_ms", "value": "", "data-instante-certo": true})
              + el("p", {"class": "hora-certa-viva", "data-hora-certa": true}, "")
              + el("p", {"class": "nota"}, t("Ponha no campo a hora que o relógio vai mostrar daqui a pouco, com os segundos, e aperte o botão quando o ponteiro dos segundos chegar nela."))
              + el("label", {}, t("O relógio marca {1}", {1: el("input", {"type": "time", "step": "1", "name": "marca"})}))
              + el("label", {}, t("Onde ele ficou desde a leitura anterior {1}", {1: el("select", {"name": "posicao"}, r.precisao.posicoes.map(function (p) {
                return el("option", {"value": p.valor}, h(p.texto));
              }).join(""))}))
              + el("details", {"class": "ou-digite"}, el("summary", {}, t("ou digite o desvio"))
                + el("label", {}, t("Desvio {1}", {1: el("span", {"class": "com-unidade"}, el("input", {"type": "text", "inputmode": "decimal", "name": "valor"}) + " " + h(item.unidade))}))
                + el("p", {"class": "nota"}, t("Em segundos: + adiantado, − atrasado. Vale quando o campo da hora fica vazio.")) + quando(t("Lido às")))
              + el("button", {}, t("Marcar agora")));
          } else if (item.formato === "valor") {
            // a leitura que mede o gasto, num relógio que vai ao pulso: duas opções, em uso e em repouso. A marcação manda: o
            // gasto em uso sai só das leituras em uso, e o em repouso só das em repouso; se ela não bate com o pulso
            // registrado, a API pergunta antes de gravar
            var leitura = function (chaveL, estadoL, rotulo, botao) {
              opcoes.agora += el("option", {"value": chaveL}, h(rotulo));
              partes += parte("marcar", chaveL, base + el("input", {"type": "hidden", "name": "acao", "value": "lancar"})
                + (estadoL !== null ? el("input", {"type": "hidden", "name": "estado", "value": estadoL}) : "")
                + el("p", {"class": "marcar-estado"}, t("Agora {1}{2}", {1: el("span", {"class": "estado estado-" + (r.estado)}, h(minusculo(r.estado_texto))), 2: r.carga !== null && item.unidade === "%" ? " " + el("small", {}, t("estimada ~{1}%", {1: r.carga})) : ""}))
                + el("label", {}, t("Leitura {1}", {1: el("span", {"class": "com-unidade"}, el("input", {"type": "text", "inputmode": "decimal", "name": "valor", "required": true}) + " " + h(item.unidade))}))
                + quando(t("Lida às"))
                + (item.mede_gasto ? el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "medir", "value": "0"})
                  + el("input", {"type": "checkbox", "name": "medir", "value": "1", "checked": true}) + t(" Atualizar o gasto com esta medição")) : "")
                + el("button", {}, h(botao)));
            };
            if (item.mede_gasto && pulsoTipo) {
              // o botão curto (no celular, o nome inteiro não cabe numa linha); o nome inteiro fica na lista
              leitura(chave + "-uso", "uso", t("Informar {1} em uso", {1: minusculo(item.nome)}), t("Informar em uso"));
              leitura(chave + "-repouso", "repouso", t("Informar {1} em repouso", {1: minusculo(item.nome)}), t("Informar em repouso"));
            } else {
              leitura(chave, null, t("Informar {1}", {1: minusculo(item.nome)}), t("Informar {1}", {1: minusculo(item.nome)}));
            }
          } else {
            opcoes.agora += el("option", {"value": chave}, h(item.nome));
            partes += parte("marcar", chave, base + el("input", {"type": "hidden", "name": "acao", "value": "lancar"})
              + quando(t("Quando")) + el("button", {}, t("Marcar {1}", {1: h(minusculo(item.nome))})));
          }
        });
        // um período inteiro que já passou: onde (se houver mais de uma sessão), de e até. No winder (obrigatório), só com winder
        // cadastrado e com a posição onde ele esteve: o menu da posição aparece (e vai no pedido) só com o winder escolhido
        var sessoesP = sessoes.filter(function (item) { return !item.winder || (r.winder_cadastrado && (r.winder_posicoes || []).length > 0); });
        var posP = r.winder_posicoes || [];
        var winderPrimeiro = sessoesP.length > 0 && !!sessoesP[0].winder;
        if (sessoesP.length > 0) {
          opcoes.passou += el("option", {"value": "periodo"}, t("Um período que já passou ({1})", {1: h(sessoesP.map(function (item) { return minusculo(item.nome); }).join(", "))}));
          partes += parte("marcar", "periodo", el("input", {"type": "hidden", "name": "acao", "value": "periodo"})
            + (sessoesP.length > 1
              ? el("label", {}, t("Onde {1}", {1: el("select", {"name": "tipo", "onchange": "var w = this.selectedOptions[0].hasAttribute('data-winder'); this.form.querySelectorAll('[data-so-winder]').forEach(function (x) { x.hidden = !w; x.querySelectorAll('select').forEach(function (s) { s.disabled = !w; }); });"},
                sessoesP.map(function (item) { return el("option", {"value": item.identificador, "data-winder": item.winder ? true : null}, h(item.nome)); }).join(""))}))
              : el("input", {"type": "hidden", "name": "tipo", "value": sessoesP[0].identificador}))
            + (sessoesP.some(function (item) { return item.winder; }) ? el("label", {"data-so-winder": true, "hidden": !winderPrimeiro}, t("Posição do winder {1}", {1: el("select", {"name": "posicao_id", "disabled": !winderPrimeiro}, posP.map(function (p) {
              return el("option", {"value": p.id, "title": p.programa}, h(p.texto));
            }).join(""))}) + el("small", {}, t("onde ele esteve: o programa da posição entra na conta da carga"))) : "")
            + el("label", {}, t("De {1}", {1: momento("inicio", momentoNavegador(new Date(agora.getTime() - 3600000)), true)}))
            + el("label", {}, t("Até {1}", {1: momento("fim", momentoNavegador(new Date()), true, true)}))
            + el("button", {}, t("Registrar o período")));
        }
        // corrigir: o segundo menu escolhe a marcação, e embaixo aparecem os campos dela, com Salvar e Excluir
        if (r.lancamentos_recentes.length > 0) {
          opcoes.corrigir += el("option", {"value": "corrigir"}, t("Corrigir ou excluir uma marcação (últimos 14 dias)"));
          var qual = "";
          var campos = "";
          r.lancamentos_recentes.forEach(function (l) {
            var programado = l.fim !== null && instante(l.fim) > agora.getTime();
            var desc = dataBr(l.inicio, true) + " " + horaBr(l.inicio) + " · " + l.nome
              + (l.formato === "sessao" ? (l.fim === null ? t(" (aberta)") : t(" até {1}", {1: horaBr(l.fim)})) : "")
              + (l.formato === "valor" ? ": " + num(l.valor, 1) + " " + l.unidade : "") + (l.origem === "rodizio" ? t(" (rodízio)") : "");
            qual += el("option", {"value": String(l.id)}, h(desc));
            var desta = "";
            if (l.formato === "sessao") {
              desta = el("label", {}, t("De {1}", {1: momento("inicio", l.inicio.substring(0, 16).replace(" ", "T"), true)}))
                + (programado
                  ? el("p", {"class": "nota"}, t("Até {1} (o fim do horário de uso).", {1: horaBr(l.fim)}))
                  : el("label", {}, t("Até {1}{2}", {1: momento("fim", l.fim === null ? "" : l.fim.substring(0, 16).replace(" ", "T"), false), 2: el("small", {}, t("Vazio: a sessão continua aberta."))})));
            } else {
              desta = el("label", {}, t("Quando {1}", {1: momento("inicio", l.inicio.substring(0, 16).replace(" ", "T"), true)}))
                + (l.formato === "valor" ? el("label", {}, t("Valor {1}", {1: el("span", {"class": "com-unidade"}, el("input", {"type": "text", "inputmode": "decimal", "name": "valor", "value": num(l.valor, 1), "required": true}) + " " + h(l.unidade))})) : "")
                // a marcação da leitura (em uso ou em repouso), que diz para qual dos dois gastos ela conta
                + (l.formato === "valor" && pulsoTipo && tipoMede[l.tipo] ? el("label", {}, t("Marcação {1}", {1: el("select", {"name": "estado"},
                  el("option", {"value": "", "selected": l.estado === null}, t("sem marcação (vale o pulso registrado)")) + el("option", {"value": "uso", "selected": l.estado === "uso"}, t("em uso"))
                  + el("option", {"value": "repouso", "selected": l.estado === "repouso"}, t("em repouso")))})) : "")
                // a posição da leitura do desvio da hora (onde ele ficou desde a anterior)
                + (l.marcha === "desvio" && r.precisao ? el("label", {}, t("Onde ele ficou {1}", {1: el("select", {"name": "posicao"},
                  el("option", {"value": "", "selected": l.posicao === null}, t("não informada")) + r.precisao.posicoes.map(function (p) {
                    return el("option", {"value": p.valor, "selected": l.posicao === p.valor}, h(p.texto));
                  }).join(""))})) : "");
            }
            campos += parte("corrigir", String(l.id), el("input", {"type": "hidden", "name": "id", "value": l.id}) + desta
              + el("span", {"class": "marcar-botoes"}, el("button", {"name": "acao", "value": "alterar"}, t("Salvar"))
                + el("button", {"name": "acao", "value": "excluir", "class": "leve discreto", "formnovalidate": true,
                  "onclick": "return confirm(" + JSON.stringify(t("Excluir {1} de {2} {3}?", {1: l.nome, 2: dataBr(l.inicio, true), 3: horaBr(l.inicio)})) + ")"}, t("Excluir"))));
          });
          partes += parte("marcar", "corrigir", el("p", {"class": "nota"}, t("Esqueceu o Tirou à noite? Escolha a sessão e ponha a hora certa no fim."))
            + el("label", {}, t("Qual marcação {1}", {1: el("select", {"data-mostra": "corrigir"}, el("option", {"value": ""}, t("— escolha —")) + qual)})) + campos);
        }
        var menu = el("option", {"value": ""}, t("— escolha —"))
          + (opcoes.agora !== "" ? el("optgroup", {"label": t("Agora, ou numa hora que você disser")}, opcoes.agora) : "")
          + (opcoes.passou !== "" ? el("optgroup", {"label": t("Algo que já passou")}, opcoes.passou) : "")
          + (opcoes.corrigir !== "" ? el("optgroup", {"label": t("Corrigir")}, opcoes.corrigir) : "");
        secoes.uso += quadro(t("Marcar"), el("form", {"data-recurso": "lancamento", "class": "marcar"}, el("input", {"type": "hidden", "name": "relogio_id", "value": id})
          + el("label", {"class": "marcar-o-que"}, t("O que você quer marcar? {1}", {1: el("select", {"data-mostra": "marcar"}, menu)})) + partes), "", "quadro-marcar");
      }
      // o desejado (a lista de desejos): ainda não é seu, então nada de agora, previsão, rodízio ou marcar; na seção do
      // cadastro, o quadro Desejo (a marca, a referência, o preço-alvo, a prioridade e o link) e os documentos (fotos, anúncios
      // guardados)
      if (r.posse === "desejado" && r.desejo) {
        var dj = r.desejo;
        secoes = {uso: "", carga: "", precisao: "", historico: ""};
        secoes.cadastro = quadro(t("Desejo"), pares([[t("Marca"), dj.marca !== null ? h(dj.marca) : nada("—")], [t("Referência"), dj.referencia !== null ? h(dj.referencia) : nada("—")],
          [t("Preço-alvo"), dj.preco_alvo !== null ? el("strong", {}, t("R$ ") + reais(dj.preco_alvo)) : nada(t("sem preço-alvo"))],
          [t("Prioridade"), dj.prioridade !== null ? h(dj.prioridade) : nada(t("sem prioridade"))],
          [t("Link"), dj.link !== null ? el("a", {"href": dj.link, "target": "_blank", "rel": "noopener noreferrer"}, t("abrir a página")) : nada("—")]]),
          el("p", {"class": "nota"}, t("Comprou? Em \"Editar cadastro\", mude a situação para \"Na coleção\" e preencha a compra.")), "quadro-desejo") + quadroDocs;
      }
      // o atalho do pulso, na cabeça: um botão só, "Pôr" quando ele não está no pulso e "Tirar" quando está (agora; a hora
      // certa e o resto ficam no quadro Marcar)
      var pulso = r.posse === "desejado" ? null : r.tipos.filter(function (item) { return item.identificador === "pulso" && item.formato === "sessao"; })[0] || null;
      var atalho = "";
      if (pulso) {
        var noPulso = pulso.aberta !== null;
        atalho = el("form", {"data-recurso": "lancamento", "class": "atalho-pulso"}, el("input", {"type": "hidden", "name": "acao", "value": noPulso ? "encerrar" : "iniciar"})
          + el("input", {"type": "hidden", "name": "relogio_id", "value": id}) + el("input", {"type": "hidden", "name": "tipo", "value": "pulso"})
          + el("span", {"class": "atalho-titulo"}, t("Pulso"))
          + el("button", {"class": noPulso ? "leve" : "", "title": acaoSessao(pulso.nome, noPulso)}, noPulso ? t("Tirar") : t("Pôr"))
          + (noPulso ? el("small", {}, h(pulso.aberta.texto)) : ""));
      }
      // a cabeça: a foto e quem ele é (o grupo, o nome e as marcas de agora); logo embaixo, o Editar cadastro, e então as
      // seções (os dois entram no lugar das marcas, depois de montados)
      res += el("div", {"class": "ficha-cabeca"}, el("div", {"class": "ficha-foto"}, foto)
        + el("div", {"class": "ficha-identidade"}, el("p", {"class": "data"}, h(r.tipo) + " · " + h(r.caminho))
          + el("h1", {}, h(r.nome)) + (r.observacao ? el("p", {"class": "observacao"}, textoRecolhivel(r.observacao, "obs-" + r.id)) : "")
          + el("p", {"class": "marcas"}, (r.posse === "desejado" ? "" : el("span", {"class": "estado estado-" + (r.estado)}, h(minusculo(r.estado_texto))))
            + (r.posse !== "colecao" ? " " + el("span", {"class": "estado estado-posse"}, h(minusculo(r.posse_texto)))
              : (r.disponivel ? "" : " " + el("span", {"class": "estado estado-indisponivel"}, t("indisponível"))))
            + (r.proxima === hoje ? " " + el("span", {"class": "estado estado-atividade"}, t("relógio de hoje")) : "")) + atalho
          // no painel da página Hoje, o caminho para a ficha (o mesmo relógio numa página só dele, numa aba nova)
          + (document.querySelector(".painel") ? el("p", {"class": "ficha-link"}, el("a", {"href": "ficha.php?id=" + id, "target": "_blank", "rel": "noopener", "class": "botao leve"}, t("Abrir a ficha ↗"))) : "")))
        + "<!--editar-cadastro-->" + "<!--secoes-->";
    }
    // o cadastro: nome, grupo, os campos (cada um no grupo em que vale) por divisória (a API manda na ordem delas, cada campo
    // com a divisória dele; os sem divisória, como a observação, vêm no fim, soltos), a foto (novo) e o disponível
    var noForm = novo ? Number(new URLSearchParams(window.location.search).get("no_id") || 0) : r.no_id;
    var cad = el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (novo ? "" : el("input", {"type": "hidden", "name": "id", "value": id}))
      + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": novo ? "" : r.nome, "required": true})}))
      + el("label", {}, t("Grupo {1} {2}", {1: el("select", {"name": "no_id", "class": "campo-subtipo"}, el("option", {"value": "0", "data-cadeia": "", "selected": noForm === 0}, t("(na raiz)"))
        + d.grupos.map(function (g) { return el("option", {"value": g.id, "data-cadeia": g.cadeia.join(" "), "selected": noForm === g.id}, h(g.caminho)); }).join("")), 2: el("small", {}, t("os campos, os lançamentos e os critérios de um grupo valem para tudo abaixo dele (páginas Grupos e Cadastros)"))}));
    // a situação na coleção (no relógio novo, a do endereço: index.php?novo=1&posse=desejado, o "Novo desejo" da página Desejos)
    var posseForm = novo ? (new URLSearchParams(window.location.search).get("posse") || "colecao") : r.posse;
    cad += el("label", {}, t("Situação na coleção {1} {2}", {1: el("select", {"name": "posse", "class": "campo-posse"}, d.posses.map(function (p) {
      return el("option", {"value": p.valor, "selected": p.valor === posseForm}, h(p.nome));
    }).join("")), 2: el("small", {}, t("fora da coleção, ele sai do rodízio e dos avisos; a ficha e o histórico ficam"))}));
    // o calibre (o movimento): o relógio herda dele os valores que não tem (a aba Calibres dos Cadastros)
    var calForm = novo || !r.calibre ? 0 : r.calibre.id;
    cad += el("label", {}, t("Calibre {1} {2}", {1: el("select", {"name": "calibre_id"}, el("option", {"value": "0"}, t("— sem calibre")) + d.calibres.map(function (c) {
      return el("option", {"value": c.id, "selected": c.id === calForm}, h(c.nome) + (c.fabricante ? " · " + h(c.fabricante) : ""));
    }).join("")), 2: el("small", {}, t("o movimento: o relógio usa os valores dele nos campos vazios ({1})", {1: el("a", {"href": "cadastros.php?aba=calibres"}, t("cadastrar calibres"))}))}));
    var divisoria = null;
    var dentro = "";
    var fechar = function () {
      cad += divisoria !== null ? el("fieldset", {"class": "secao-cadastro"}, el("legend", {}, h(divisoria)) + dentro) : dentro;
      dentro = "";
    };
    d.campos.forEach(function (c, i) {
      if (i > 0 && c.secao !== divisoria) {
        fechar();
      }
      divisoria = c.secao;
      dentro += campoCadastro(c);
    });
    fechar();
    if (novo) {
      cad += el("label", {}, t("Foto {1}", {1: el("input", {"type": "file", "accept": "image/*", "data-foto": "foto_base64_" + fid, "data-previa": "previa_" + fid, "aria-label": t("Escolher foto")})}))
        + el("input", {"type": "hidden", "name": "foto_base64", "id": "foto_base64_" + fid}) + el("img", {"id": "previa_" + fid, "class": "previa", "hidden": true, "alt": ""});
    }
    cad += el("label", {"class": "check", "data-so-colecao": true}, el("input", {"type": "hidden", "name": "disponivel", "value": "0"}) + el("input", {"type": "checkbox", "name": "disponivel", "value": "1",
      "checked": novo || r.disponivel}) + t(" Disponível para o rodízio"))
      // a escolha do relógio só aparece quando a Configuração da coleção não decide por todos (Cópia dos documentos vazia)
      + (d.copia_sistema === null ? el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "copia_banco", "value": "0"})
        + el("input", {"type": "checkbox", "name": "copia_banco", "value": "1", "checked": !novo && r.copia_banco}) + t(" Guardar no banco a cópia de todos os documentos dele")) : "")
      + el("div", {"class": "botoes"}, el("button", {}, novo ? t("Cadastrar relógio") : t("Salvar alterações")));
    var cadastro = el("form", {"data-recurso": "relogio", "class": "cadastro"}, cad);
    if (novo) {
      res += cadastro;
    } else {
      // (com função no replace: um "$" nos dados, como em "R$", não vira padrão de troca)
      var editarCadastro = el("details", {"class": "cadastro-painel"}, el("summary", {}, t("Editar cadastro")) + ondeMuda("cadastro-relogio", [
          [t("Os valores deste relógio"), t("aqui, e Salvar alterações")],
          [t("Quais campos aparecem, o nome, o tipo, os valores possíveis e o padrão de cada um"), lugar("cadastros.php?aba=campos", t("Cadastros › Campos"))],
          [t("As divisórias (Construção, Compra...)"), lugar("cadastros.php?aba=secoes", t("Cadastros › Divisórias"))],
          [t("Os grupos (o que vale para cada um)"), lugar("grupos.php", t("Coleção › Grupos"))],
          [t("Os calibres e os valores deles (reserva, marcha)"), lugar("cadastros.php?aba=calibres", t("Cadastros › Calibres"))],
          [t("O que é calculado (autonomia, carga, custo por uso)"), lugar("cadastros.php?aba=formulas", t("Cadastros › Fórmulas"))]]) + cadastro
        + el("form", {"data-recurso": "relogio", "class": "excluir"},
          el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": id}) + el("button", {"class": "leve discreto"}, t("Excluir este relógio"))));
      res = res.replace("<!--editar-cadastro-->", function () { return editarCadastro; });
      // o gráfico das leituras de carga
      if (r.leituras.length > 0 || r.previsao.aplica) {
        var grafico = el("p", {"class": "nota"}, t("O gráfico aparece a partir de duas leituras de carga."));
        if (r.leituras.length >= 2) {
          var t0 = instante(r.leituras[0].inicio);
          var t1 = Math.max(t0 + 1000, instante(r.leituras[r.leituras.length - 1].inicio));
          var pontos = r.leituras.map(function (l) {
            return (Math.round((instante(l.inicio) - t0) / (t1 - t0) * 6000) / 10) + "," + (Math.round((110 - Math.max(0, Math.min(100, l.valor))) * 10) / 10);
          });
          var de = dataBr(r.leituras[0].inicio, true);
          var ate = dataBr(r.leituras[r.leituras.length - 1].inicio, true);
          grafico = "<svg class=\"grafico\" viewBox=\"-10 0 620 120\" role=\"img\" aria-label=\"" + h(t("Leituras de carga de {1} a {2}", {1: de, 2: ate})) + "\">"
            + "<line x1=\"0\" y1=\"10\" x2=\"600\" y2=\"10\" class=\"guia\"/><line x1=\"0\" y1=\"60\" x2=\"600\" y2=\"60\" class=\"guia\"/><line x1=\"0\" y1=\"110\" x2=\"600\" y2=\"110\" class=\"guia\"/>"
            + "<polyline points=\"" + pontos.join(" ") + "\" class=\"linha-carga\"/>"
            + pontos.map(function (p, i) {
              var xy = p.split(",");
              return "<circle cx=\"" + xy[0] + "\" cy=\"" + xy[1] + "\" r=\"4\" class=\"" + (r.leituras[i].em_uso ? "ponto-uso" : "ponto-repouso") + "\"/>";
            }).join("")
            + "</svg>" + pares([
              [t("Pontos"), el("span", {"class": "legenda-ponto"}, t("{1} leitura em uso", {1: el("span", {"class": "bolinha ponto-uso"}, "")}))
                + " " + el("span", {"class": "legenda-ponto"}, t("{1} leitura em repouso", {1: el("span", {"class": "bolinha ponto-repouso"}, "")}))],
              [t("Período"), t("{1} a {2}", {1: de, 2: ate})],
              [t("Escala"), t("de 0 a 100%")],
              [t("A conta"), t("de onde vem cada número: no quadro Previsão")]], "legenda-grafico");
        }
        // as medições do gasto pelas leituras (as 10 mais recentes) e se cada uma entra na média
        var med = "";
        if (r.medicoes.length > 0) {
          var motorG = r.previsao && r.previsao.gasto ? r.previsao.gasto.motor : "horas";
          // a nota diz como o motor escolhido usa as medições
          var notaMed = motorG === "horas" ? t("Cada leitura comparada com a anterior. Os dois gastos (em uso e fora do pulso) saem juntos de todas as medições {1}, cada uma dividida pelas marcações de pulso minuto a minuto e pesando as horas que cobre (o motor original). O botão de cada linha tira ou põe a medição na conta.", {1: h(r.medicao_janela_texto)})
            : (motorG === "robusto" ? t("Cada leitura comparada com a anterior. Os dois gastos (em uso e fora do pulso) saem juntos das leituras {1}, todas com o mesmo peso e partindo do cadastro; o gasto de cada linha é só o dela. A leitura que não bate bem com as outras pesa menos na conta, e o botão de cada linha tira ou põe a medição na conta.", {1: h(r.medicao_janela_texto)})
            : (motorG === "cadastro" ? t("Cada leitura comparada com a anterior. Neste relógio vale só o cadastro (o motor escolhido): as medições ficam aqui, no histórico, e não entram em conta nenhuma.")
            : t("Cada leitura comparada com a anterior. O gasto em uso sai só das medições com as duas leituras marcadas em uso, e o gasto em repouso só das marcadas em repouso, {1} (o \"contar por\" da Configuração), todas com o mesmo peso e partindo do cadastro; o gasto de cada linha é só o dela. A leitura que não bate bem com as outras pesa menos na conta, e o botão de cada linha tira ou põe a medição na conta.", {1: h(r.medicao_janela_texto)})));
          med = el("h3", {}, t("Medições do gasto")) + el("p", {"class": "nota"}, notaMed)
            + el("table", {"class": "tabela-dados medicoes"}, el("thead", {}, el("tr", {}, el("th", {}, t("Quando")) + el("th", {}, t("Leituras")) + el("th", {}, t("Horas"))
              + el("th", {}, t("Gasto medido")) + el("th", {}, t("Na conta")) + el("th", {}, "")))
              + el("tbody", {}, r.medicoes.slice(0, 10).map(function (m) {
                // o conteúdo de cada célula num div.v: no quadro estreito a linha vira uma tabelinha (o nome da coluna numa casa, o
                // valor na outra), e o valor precisa ser um bloco só
                var td = function (c) { return el("td", {}, el("div", {"class": "v"}, c)); };
                // um par de valores numa tabelinha: o título de cada um em cima, o valor embaixo
                var par = function (titulos, valores) {
                  return el("table", {"class": "par"}, el("thead", {}, el("tr", {}, titulos.map(function (x) { return el("th", {}, x); }).join("")))
                    + el("tbody", {}, el("tr", {}, valores.map(function (x) { return el("td", {}, x); }).join(""))));
                };
                var estado = function (e, falta) { return e === "uso" ? t("Em uso") : (e === "repouso" ? t("Em repouso") : falta); };
                return el("tr", {}, td(dataBr(m.fim, true) + " " + horaBr(m.fim))
                  // as leituras com a marcação de cada uma no título: em repouso → em uso
                  + td(par([estado(m.marcacao_de, t("Antes")), estado(m.marcacao_ate, t("Depois"))], [num(m.de_valor, 1), num(m.ate_valor, 1)]))
                  + td(par([t("No pulso"), t("Fora do pulso")], [t("{1} h", {1: num(m.horas_pulso, 1)}), t("{1} h", {1: num(m.horas_guardado, 1)})]))
                  + td(par([m.medida === "uso" ? t("Em uso") : t("Fora do pulso")], [t("{1}% por dia", {1: num(m.taxa, 2)})]))
                  // na conta: sim; em parte (a leitura que não bate com as outras pesa menos, Huber); não (fora da janela, só histórico)
                  + td((!m.usada ? t("não (só histórico)") : (m.marcacoes_iguais === false ? t("não (leituras com marcações diferentes)") : motorG === "cadastro" ? t("não (só o cadastro)") : !m.na_media ? t("não (fora da janela)")
                      : (m.peso !== null && m.peso < 1 ? el("span", {"class": m.fora ? "em atrasado" : ""}, t("em parte: pesa {1}%", {1: Math.round(100 * m.peso)})) : t("sim"))))
                    + (m.na_media && m.peso !== null && m.peso < 1 ? el("small", {}, t("a leitura de {1}% não bate bem com as outras (a conta erra {2} pontos nela)", {1: num(m.leitura_fraca, 0), 2: num(Math.abs(m.erro), 1)})) : ""))
                  // pôr ou tirar da conta, sem apagar a medição
                  + td(el("form", {"data-recurso": "lancamento"}, el("input", {"type": "hidden", "name": "acao", "value": "medicao"}) + el("input", {"type": "hidden", "name": "id", "value": m.id})
                    + el("input", {"type": "hidden", "name": "usada", "value": m.usada ? "0" : "1"}) + el("button", {"class": "leve"}, m.usada ? t("Tirar da conta") : t("Pôr na conta")))));
              }).join("")));
        }
        secoes.carga += quadro(t("Carga ao longo do tempo"), grafico + (med ? el("div", {"data-avancado": "gasto.medicoes"}, med) : ""), "", "largo");
      }
      // o desvio ao longo do tempo (as leituras e os acertos: cada acerto recomeça a linha em 0 s) e as medições da marcha (os
      // intervalos, os 10 mais recentes), com a marcha em cada posição quando há mais de uma
      if (r.precisao && r.precisao.pontos.length > 0) {
        var pr2 = r.precisao;
        var graficoP = el("p", {"class": "nota"}, t("O gráfico aparece a partir de duas leituras do desvio."));
        if (pr2.pontos.length >= 2) {
          var p0 = instante(pr2.pontos[0].inicio);
          var p1 = Math.max(p0 + 1000, instante(pr2.pontos[pr2.pontos.length - 1].inicio));
          var maior = Math.max(1, Math.max.apply(null, pr2.pontos.map(function (p) { return Math.abs(p.desvio); })));
          var xy = function (p) {
            return [Math.round((instante(p.inicio) - p0) / (p1 - p0) * 6000) / 10, Math.round((60 - p.desvio / maior * 50) * 10) / 10];
          };
          var trechos = [];
          var atual = [];
          pr2.pontos.forEach(function (p) {
            if (p.tipo === "acerto" && atual.length > 0) {
              trechos.push(atual);
              atual = [];
            }
            atual.push(xy(p).join(","));
          });
          trechos.push(atual);
          var deP = dataBr(pr2.pontos[0].inicio, true);
          var ateP = dataBr(pr2.pontos[pr2.pontos.length - 1].inicio, true);
          graficoP = "<svg class=\"grafico\" viewBox=\"-10 0 620 120\" role=\"img\" aria-label=\"" + h(t("Desvio da hora de {1} a {2}", {1: deP, 2: ateP})) + "\">"
            + "<line x1=\"0\" y1=\"10\" x2=\"600\" y2=\"10\" class=\"guia\"/><line x1=\"0\" y1=\"60\" x2=\"600\" y2=\"60\" class=\"guia zero\"/><line x1=\"0\" y1=\"110\" x2=\"600\" y2=\"110\" class=\"guia\"/>"
            + trechos.map(function (tr) { return "<polyline points=\"" + tr.join(" ") + "\" class=\"linha-desvio\"/>"; }).join("")
            + pr2.pontos.map(function (p) {
              var c2 = xy(p);
              return "<circle cx=\"" + c2[0] + "\" cy=\"" + c2[1] + "\" r=\"4\" class=\"" + (p.tipo === "acerto" ? "ponto-acerto" : "ponto-desvio") + "\"/>";
            }).join("")
            + "</svg>" + pares([
              [t("Pontos"), el("span", {"class": "legenda-ponto"}, t("{1} leitura do desvio", {1: el("span", {"class": "bolinha ponto-desvio"}, "")}))
                + " " + el("span", {"class": "legenda-ponto"}, t("{1} acerto da hora", {1: el("span", {"class": "bolinha ponto-acerto"}, "")}))],
              [t("Período"), t("{1} a {2}", {1: deP, 2: ateP})],
              [t("Escala"), t("de −{1} s (atrasado) a +{1} s (adiantado); o meio é a hora certa", {1: num(maior, 0)})]], "legenda-grafico");
        }
        var medP = "";
        if (pr2.intervalos.length > 0) {
          medP = el("h3", {}, t("Medições da marcha")) + ajudaNota("marcha", t("A marcha"), t("Cada leitura comparada com a anterior (ou com o acerto da hora). A marcha do relógio é a média delas pesada pelo tempo: um intervalo de 10 dias pesa dez vezes o de um dia."))
            + el("table", {"class": "tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Quando")) + el("th", {}, t("Dias")) + el("th", {}, t("Onde ficou")) + el("th", {}, t("Desvio")) + el("th", {}, t("Marcha"))))
              + el("tbody", {}, pr2.intervalos.slice(0, 10).map(function (x) {
                return el("tr", {}, el("td", {}, dataBr(x.ate, true) + " " + horaBr(x.ate)) + el("td", {}, num(x.dias, 2)) + el("td", {}, h(x.posicao_texto))
                  + el("td", {}, t("{1} → {2} s", {1: num(x.desvio_de, 1), 2: num(x.desvio_ate, 1)})) + el("td", {}, h(x.marcha_texto)));
              }).join("")));
          if (pr2.por_posicao.length > 1) {
            medP += el("h3", {}, t("Marcha em cada posição")) + el("table", {"class": "tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Onde ficou")) + el("th", {}, t("Medições")) + el("th", {}, t("Dias")) + el("th", {}, t("Marcha"))))
              + el("tbody", {}, pr2.por_posicao.map(function (x) {
                return el("tr", {}, el("td", {}, h(x.posicao_texto)) + el("td", {}, String(x.medicoes)) + el("td", {}, num(x.dias, 1)) + el("td", {}, h(x.marcha_texto)));
              }).join("")));
          }
        }
        secoes.precisao += quadro(t("Desvio ao longo do tempo"), graficoP + (medP ? el("div", {"data-avancado": "marcha.medicoes"}, medP) : ""), "", "largo");
      }
      // o timegrapher: a última sessão (uma medição por posição, a média e a diferença entre as posições), cada medição (para
      // mudar ou excluir), a medição digitada e a importação do CSV do aparelho (o navegador só lê o arquivo; quem entende é a API)
      var tg = r.timegrapher;
      if (tg) {
        var momentoTg = function (valor) {
          return el("input", {"type": "datetime-local", "name": "quando", "value": valor ? valor.substr(0, 16).replace(" ", "T") : momentoNavegador(new Date()),
            "max": momentoNavegador(new Date()), "data-agora": valor ? false : "vazio", "data-max-agora": true});
        };
        var formTg = function (x) {
          return el("form", {"data-recurso": "timegrapher", "class": "reserva-form"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
            + el("input", {"type": "hidden", "name": x ? "id" : "relogio_id", "value": x ? x.id : id})
            + el("label", {}, t("Quando {1}", {1: momentoTg(x ? x.quando : null)}))
            + el("label", {}, t("Posição {1}", {1: el("select", {"name": "posicao"}, el("option", {"value": ""}, t("não informada")) + tg.posicoes.map(function (p) {
              return el("option", {"value": p.valor, "selected": x !== null && x.posicao === p.valor}, h(p.texto));
            }).join(""))}))
            + tg.grandezas.map(function (g) {
              return el("label", {}, h(g.nome) + " (" + h(g.unidade) + ") " + el("input", {"name": g.campo, "inputmode": "decimal", "value": x && x[g.campo] !== null ? String(x[g.campo]) : ""}));
            }).join("")
            + el("label", {"class": "largo"}, t("Observação {1}", {1: el("input", {"name": "observacao", "maxlength": 500, "value": x && x.observacao !== null ? x.observacao : ""})}))
            + el("div", {"class": "botoes largo"}, el("button", {}, x ? t("Salvar a medição") : t("Registrar a medição"))));
        };
        var linhaTg = function (x) {
          return [h(x.posicao_texto), x.marcha_texto ? h(x.marcha_texto) : "—", x.amplitude_texto ? h(x.amplitude_texto) : "—", x.erro_batida_texto ? h(x.erro_batida_texto) : "—",
            x.bph_texto ? h(x.bph_texto) : "—", x.angulo_texto ? h(x.angulo_texto) : "—"];
        };
        var corpoTg = el("p", {"class": "nota"}, h(tg.resumo));
        if (tg.sessao) {
          var cabTg = [t("Posição"), t("Marcha"), t("Amplitude"), t("Erro de batida"), t("Batidas por hora"), t("Ângulo de levantamento")];
          corpoTg += el("h3", {"class": "conta-titulo"}, t("Última sessão ({1})", {1: dataBr(tg.sessao.dia)}))
            + el("div", {"class": "rolagem"}, el("table", {"class": "tabela-dados compacta timegrapher-sessao"}, el("thead", {}, el("tr", {}, cabTg.map(function (c) { return el("th", {}, c); }).join("")))
              + el("tbody", {}, tg.sessao.itens.map(function (x) { return el("tr", {}, linhaTg(x).map(function (c) { return el("td", {}, c); }).join("")); }).join("")
                + el("tr", {"class": "media"}, linhaTg({posicao_texto: t("Média"), marcha_texto: tg.sessao.marcha_texto, amplitude_texto: tg.sessao.amplitude_texto,
                  erro_batida_texto: tg.sessao.erro_batida_texto, bph_texto: tg.sessao.bph_texto, angulo_texto: tg.sessao.angulo_texto}).map(function (c) { return el("td", {}, el("strong", {}, c)); }).join("")))))
            + (tg.sessao.delta_texto ? el("p", {"class": "nota"}, t("Diferença de marcha entre as {1} posições: {2}.", {1: tg.sessao.posicoes, 2: el("strong", {}, h(tg.sessao.delta_texto))})) : "");
        }
        corpoTg += tg.medicoes.slice(0, 30).map(function (x) {
          return el("details", {"class": "reserva-registro"}, el("summary", {}, el("strong", {}, dataBr(x.quando.substr(0, 10)) + " " + horaBr(x.quando)) + " · " + h(x.posicao_texto)
              + [x.marcha_texto, x.amplitude_texto, x.erro_batida_texto].filter(function (v) { return v; }).map(function (v) { return " · " + h(v); }).join("")
              + " · " + h(x.origem_texto))
            + (x.observacao !== null ? el("p", {"class": "nota"}, h(x.observacao)) : "") + formTg(x)
            + el("form", {"data-recurso": "timegrapher", "class": "excluir"}, el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": x.id})
              + el("button", {"class": "leve discreto"}, t("Excluir esta medição"))));
        }).join("") + (tg.medicoes.length > 30 ? el("p", {"class": "nota"}, t("As 30 mais recentes de {1} medições (todas em recurso=timegrapher).", {1: tg.medicoes.length})) : "");
        corpoTg += el("details", {"class": "reserva-novo"}, el("summary", {}, t("Registrar uma medição"))
          + ajudaNota("timegrapher", t("O timegrapher"), t("O que o timegrapher mostra em cada posição: a marcha, a amplitude, o erro de batida, as batidas por hora e o ângulo de levantamento (o que o aparelho não mostra fica vazio). As medições do mesmo dia são uma sessão."))
          + formTg(null));
        corpoTg += el("details", {"class": "reserva-novo"}, el("summary", {}, t("Importar o CSV do aparelho"))
          + ajudaNota("timegrapher-csv", t("Importar o CSV do timegrapher"), t("O arquivo que o timegrapher (ou o aplicativo dele) exporta, com o cabeçalho: as colunas são reconhecidas pelo nome, em português ou em inglês (rate, amplitude, beat error, bph, lift angle, position, date, time). As linhas sem data ficam no instante escolhido aqui; a repetida não entra de novo. Conferir mostra o que entraria, sem gravar."))
          + el("form", {"data-recurso": "timegrapher", "class": "reserva-form"}, el("input", {"type": "hidden", "name": "acao", "value": "importar"}) + el("input", {"type": "hidden", "name": "relogio_id", "value": id})
            + el("label", {"class": "largo"}, t("Arquivo {1}", {1: el("input", {"type": "file", "accept": ".csv,.txt,text/csv,text/plain",
              "onchange": "var f = this.files[0], ta = this.form.querySelector('textarea'); if (f) { var rd = new FileReader(); rd.onload = function () { ta.value = rd.result; }; rd.readAsText(f); }"})}))
            + el("label", {"class": "largo"}, t("O conteúdo {1}", {1: el("textarea", {"name": "csv", "rows": 5, "required": true, "placeholder": fixo("date;position;rate;amplitude;beat error") + "\n" + fixo("2026-10-20 10:00;DU;+4;285;0.3")}, "")}))
            + el("label", {}, t("Linhas sem data em {1}", {1: momentoTg(null)}))
            + el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "conferir", "value": "1"}) + " " + t("Só conferir (sem gravar)"))
            + el("div", {"class": "botoes largo"}, el("button", {}, t("Importar")))));
        secoes.precisao += quadro(t("Timegrapher"), corpoTg, linkRelatorio("timegrapher", {relogio: id}, t("Relatório do timegrapher"), true), "largo reserva-ficha timegrapher-ficha");
      }
      // os dados do relógio: o cadastro que vale para ele (o informado, senão o padrão do campo) e o resultado de cada fórmula agora
      var valorCampo = function (c) {
        var v = c.valor_usado;
        var res2 = "—";
        if (v !== null && v !== "" && c.multiplo) {
          // o múltiplo: um valor por linha, cada um numa etiqueta
          res2 = String(v).split("\n").map(function (x) { return el("span", {"class": "item-multiplo"}, h(c.tipo === "decimal" ? x.replace(".", ",") : x) + (c.unidade !== "" ? " " + h(c.unidade) : "")); }).join(" ");
        } else if (v !== null && v !== "") {
          if (c.tipo === "sim_nao") {
            res2 = String(v) === "1" ? t("sim") : t("não");
          } else if (c.tipo === "data") {
            res2 = dataBr(v);
          } else if (c.tipo === "inteiro" || c.tipo === "decimal") {
            res2 = c.unidade === "R$" ? t("R$ ") + reais(v) : num(v, 2) + (c.unidade !== "" ? " " + h(c.unidade) : "");
            // o valor também na outra unidade (Cadastros › Unidades, "mostrar também em"), já em texto pela API
            res2 += c.tambem ? " " + el("small", {"class": "tambem"}, "(" + h(c.tambem) + ")") : "";
          } else {
            // o texto livre comprido (a observação) recolhido, com o + para ver o resto
            res2 = c.tipo === "texto" ? textoRecolhivel(v, "campo-" + r.id + "-" + c.identificador) : h(v);
          }
        }
        return res2;
      };
      var valorCalculo = function (x) {
        var res2 = t("vazio");
        if (typeof x.valor === "number") {
          res2 = x.unidade === "s" ? duracao(x.valor) + " " + el("small", {}, t("({1} s)", {1: Math.round(x.valor)})) : num(x.valor, 2) + (x.unidade !== "" ? " " + h(x.unidade) : "");
        } else if (x.valor !== null) {
          res2 = h(x.valor);
        }
        return res2;
      };
      // o calculado ao lado do informado: num campo de onde sai um gasto (o decaimento, a autonomia), o gasto medido pelas
      // leituras e o que vale na conta; na fórmula do gasto, o medido e o do cadastro
      var gsD = r.previsao.gasto;  // o mesmo objeto (pode ser null sem leitura)
      var gastoTexto = function (medida, v) {
        return v === null ? "—" : num(v, 2) + "% " + (medida === "uso" ? t("por dia de uso") : t("por dia fora do pulso"));
      };
      var calculadoDoCampo = function (ident) {
        if (!gsD) {
          return "";
        }
        return ["uso", "repouso"].filter(function (m) {
          return (gsD[m].campos || []).some(function (c) { return c.campo === ident; });
        }).map(function (m) {
          return el("small", {}, (gsD[m].medido !== null ? t("calculado pelas leituras: {1}", {1: gastoTexto(m, gsD[m].medido)}) : t("calculado pelas leituras: ainda sem medição ({1})", {1: m === "uso" ? t("em uso") : t("fora do pulso")}))
            + " · " + t("na conta: {1}", {1: gastoTexto(m, gsD[m].vale)}));
        }).join("");
      };
      var calculadoDaFormula = function (ident) {
        if (!gsD) {
          return "";
        }
        return ["uso", "repouso"].filter(function (m) { return gsD[m].formula === ident; }).map(function (m) {
          return el("small", {}, t("medido: {1} · do cadastro: {2}", {1: gsD[m].medido !== null ? gastoTexto(m, gsD[m].medido) : t("sem medição"), 2: gastoTexto(m, gsD[m].cadastro)}));
        }).join("");
      };
      // duas tabelas lado a lado: o cadastro (o valor e de onde ele vem) e o resultado de cada fórmula agora (com a versão)
      // o calibre (o movimento), em cima: o nome, o fabricante, o link do manual técnico e as recomendações
      var calLinha = r.calibre ? el("p", {"class": "calibre-linha"}, t("Calibre: {1}", {1: el("strong", {}, h(r.calibre.nome))}) + (r.calibre.fabricante ? " · " + h(r.calibre.fabricante) : "")
        + (r.calibre.link ? " · " + el("a", {"href": r.calibre.link, "target": "_blank", "rel": "noopener"}, t("manual técnico ↗")) : "")
        + (r.calibre.observacao ? el("small", {}, h(r.calibre.observacao)) : "")
        // os documentos do calibre (o manual técnico em PDF...): cada um abre numa aba nova; e a página deles
        + el("span", {"class": "calibre-docs"}, r.calibre.documentos.map(function (x) {
          return el("a", {"href": x.url, "target": "_blank", "rel": "noopener"}, h(x.titulo)) + " " + el("span", {"class": "nota"}, "(" + h(x.categoria) + ")");
        }).join(" · ") + (r.calibre.documentos.length > 0 ? " · " : "") + el("a", {"href": "documentos.php?calibre=" + r.calibre.id}, t("documentos do calibre")))) : "";
      secoes.cadastro += quadro(t("Dados do relógio"), calLinha + el("div", {"class": "dados-grade"},
        el("div", {}, el("h3", {}, t("Cadastro")) + el("table", {"class": "tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Campo"))
            + el("th", {}, t("Valor")))) + el("tbody", {}, r.dados.map(function (c, i) {
              // o texto livre (a observação, uma lista) quebra a linha; número, data e sim/não ficam numa linha só
              var texto = c.valor !== null && c.valor !== "" && ["sim_nao", "data", "inteiro", "decimal"].indexOf(c.tipo) < 0;
              // a API manda os dados na ordem das divisórias: uma linha de título onde muda a divisória (os sem divisória, no
              // fim, ficam em "Outros")
              var titulo = (i === 0 ? c.secao !== null : c.secao !== r.dados[i - 1].secao)
                ? el("tr", {"class": "secao-dados"}, el("th", {"colspan": "2"}, c.secao !== null ? h(c.secao) : t("Outros"))) : "";
              // de onde vem o valor só quando não é o do relógio: o padrão do campo ou o do calibre (etiquetaOrigem)
              return titulo + el("tr", {"class": c.origem === "vazio" ? "vazio" : ""}, el("td", {}, h(c.nome)) + el("td", {"class": "valor" + (texto ? " texto" : "")}, valorCampo(c) + etiquetaOrigem(c.origem, c.origem_texto) + calculadoDoCampo(c.identificador)));
            }).join(""))))
          + el("div", {"data-avancado": "dados.calculado"}, el("h3", {}, t("Calculado agora")) + el("table", {"class": "tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, t("Fórmula"))
            + el("th", {}, t("Valor")) + el("th", {}, t("Versão")))) + el("tbody", {}, r.calculos.map(function (x) {
              return el("tr", {}, el("td", {}, h(x.nome) + el("code", {"class": "ident"}, h(x.identificador))) + el("td", {"class": "valor" + (typeof x.valor === "string" ? " texto" : "")}, valorCalculo(x) + calculadoDaFormula(x.identificador))
                + el("td", {"class": "versao"}, h(x.versao)));
            }).join(""))))), "", "largo");
      // o histórico: no painel só o resumo; a linha do tempo inteira fica em historico.php, que abre numa aba nova
      var hist = el("p", {"class": "nota"}, t("Nada lançado ainda."));
      if (r.linha_do_tempo.length > 0) {
        var ultimo = null;
        r.linha_do_tempo.forEach(function (l) {
          if (l.estado !== "marca" && ultimo === null) {
            ultimo = l;
          }
        });
        hist = el("p", {"class": "nota"}, t("{1} registros desde {2} {3}.{4}", {1: r.registros, 2: dataBr(r.desde), 3: horaBr(r.desde), 4: ultimo ? t(" Agora {1} há {2}.", {1: h(ultimo.texto), 2: h(ultimo.duracao || t("menos de 1 minuto"))}) : ""}))
          + el("table", {"class": "tabela-dados historico-curto"}, el("thead", {}, el("tr", {}, el("th", {}, t("Quando")) + el("th", {}, t("Duração")) + el("th", {}, t("Estado"))))
            + el("tbody", {}, r.linha_do_tempo.map(function (l) {
              var classe = l.estado === "rodizio" || l.estado === "pulso" ? "uso" : (l.estado === "repouso" ? "repouso" : "carga");
              return el("tr", {"class": l.estado === "marca" ? "marca" : ""}, el("td", {}, dataBr(l.inicio, true) + " " + horaBr(l.inicio)
                  + (l.fim !== null ? el("small", {}, t("até {1}", {1: l.fim.substr(0, 10) === l.inicio.substr(0, 10) ? horaBr(l.fim) : dataBr(l.fim, true) + " " + horaBr(l.fim)})) : ""))
                + el("td", {}, l.fim === null ? "—" : h(l.duracao))
                + el("td", {}, l.estado === "marca" ? h(l.texto) : el("span", {"class": "estado estado-" + (classe)}, h(l.texto)) + (l.em_andamento ? el("small", {}, t("em andamento")) : "")));
            }).join("")));
      }
      secoes.historico += quadro(t("Histórico"), hist, r.linha_do_tempo.length > 0 ? el("a", {"class": "botao leve", "href": "historico.php?id=" + id, "target": "_blank", "rel": "noopener"}, t("Abrir o histórico completo"))
        + " " + linkRelatorio("historico", {relogio: id}, t("Relatório"), true) : "",
        "largo historico");
      // as seções (só as que têm quadros): o título e os quadros dela numa grade; em cima de todas, um botão para cada uma, que
      // rola até ela
      var nomes = [["uso", t("Uso e rodízio")], ["carga", t("Carga e autonomia")], ["precisao", t("Precisão e marcha")],
        ["cadastro", t("Cadastro e documentos")], ["historico", t("Linha do tempo")]].filter(function (x) { return secoes[x[0]] !== ""; });
      var corpo = (nomes.length > 1 ? el("nav", {"class": "secoes-atalhos", "aria-label": t("Seções do relógio")}, nomes.map(function (x) {
        return el("button", {"type": "button", "class": "leve", "data-ir-secao": x[0]}, x[1]);
      }).join("")) : "") + nomes.map(function (x) {
        return el("section", {"class": "secao-painel", "data-secao": x[0]}, el("h2", {"class": "secao-titulo"}, x[1]) + el("div", {"class": "quadros"}, secoes[x[0]]));
      }).join("");
      res = res.replace("<!--secoes-->", function () { return corpo; });
    }
    porHtml(painel, el("div", {"class": "detalhe", "data-id": id}, res));

    document.querySelectorAll("form.cadastro").forEach(mostrarCamposDoTipo);
    document.querySelectorAll("form.marcar").forEach(aplicarEscolhas);
    // o painel aberto sempre tem o "×" para fechar (só na página Hoje; a ficha não fecha)
    if (!painel.hidden && document.querySelector(".painel") && !painel.querySelector("[data-fechar-painel]")) {
      var fechar = document.createElement("button");
      fechar.type = "button";
      fechar.className = "fechar-painel";
      fechar.setAttribute("data-fechar-painel", "");
      fechar.setAttribute("aria-label", t("Fechar o painel"));
      fechar.textContent = t("×");
      painel.insertBefore(fechar, painel.firstChild);
    }
    if (mesmo) {
      abertos.forEach(function (classe) {
        var d2 = painel.querySelector("details." + classe.trim().split(/\s+/).join("."));
        if (d2) {
          d2.open = true;
        }
      });
      painel.scrollTop = rolagem;
    } else {
      painel.scrollTop = 0;
    }
    if (noIndex) {
      document.querySelectorAll("tr[data-id]").forEach(function (tr) {
        tr.classList.toggle("selecionado", tr.getAttribute("data-id") === String(id));
      });
      var endereco = id > 0 ? "index.php?r=" + id : "index.php?novo=1";
      if (fechado && !painelNoHistorico && window.innerWidth <= 900 && window.location.search === "") {
        history.pushState(null, "", endereco);
        painelNoHistorico = true;
      } else {
        history.replaceState(null, "", endereco);
      }
    }
  });
}

document.addEventListener("change", function (ev) {
  if (ev.target.matches(".campo-subtipo, .campo-posse")) {
    mostrarCamposDoTipo(ev.target.form);
  }
  if (ev.target.matches("select[data-mostra]")) {
    aplicarEscolhas(ev.target.form);
  }
});

document.addEventListener("click", function (ev) {
  // os atalhos das seções do relógio: rola até a seção (no painel, na janela flutuante e na ficha)
  var irSecao = ev.target.closest("[data-ir-secao]");
  if (irSecao) {
    var secao = irSecao.closest(".detalhe").querySelector("[data-secao=\"" + irSecao.getAttribute("data-ir-secao") + "\"]");
    if (secao) {
      secao.scrollIntoView({behavior: "smooth", block: "start"});
    }
    return;
  }
  var novo = ev.target.closest("[data-novo]");
  var linha = ev.target.closest("tr[data-id]");
  var abrir = ev.target.closest("[data-abrir]");
  var fechar = ev.target.closest("[data-fechar-painel]");
  if (document.querySelector(".painel")) {
    if (fechar) {
      fecharPainel();
    } else if (abrir) {
      // o relógio do dia, pelo quadro "Hoje": abre no painel
      ev.preventDefault();
      abrirNoPainel(parseInt(abrir.getAttribute("data-abrir"), 10));
    } else if (novo) {
      ev.preventDefault();
      abrirNoPainel(0);
    } else if (linha) {
      ev.preventDefault();
      abrirNoPainel(parseInt(linha.getAttribute("data-id"), 10));
    }
  }
});

document.addEventListener("keydown", function (ev) {
  if (ev.key === "Enter" && ev.target.matches("tr[data-id]")) {
    ev.target.click();
  }
  // Esc fecha o painel (menos quando se está digitando num campo)
  if (ev.key === "Escape" && !ev.target.matches("input, textarea, select") && document.querySelector(".painel")) {
    fecharPainel();
  }
});

// Depois de gravar: o recado vai para o painel do relógio (ou para o topo da página), e a página e o painel são remontados
// com os dados novos, sem recarregar. Excluir um relógio recarrega a página inteira. O relógio novo com foto: a foto vai
// depois do cadastro, e o painel abre o relógio criado.
aoGravar = function (form, res, dados) {
  var posicao = posicaoDaGravacao(form);
  var painel = document.getElementById("detalhe");
  var atual = painel ? painel.querySelector(".detalhe") : null;
  var aberto = atual !== null ? parseInt(atual.getAttribute("data-id"), 10) : 0;
  var rid = parseInt(dados.get("relogio_id") || dados.get("id") || "0", 10);
  var recurso = dados.get("recurso");
  var acao = dados.get("acao");
  var seguir = Promise.resolve(res);
  // A foto do novo relógio é gravada depois do cadastro, como antes.
  if (recurso === "relogio" && acao === "salvar" && res.ok && !dados.get("id") && dados.get("foto_base64")) {
    seguir = enviar({recurso: "relogio", acao: "foto", id: res.id, foto_base64: dados.get("foto_base64")}).then(function (res2) {
      return {ok: res.ok && res2.ok, mensagem: res.mensagem + (res2.ok ? " " + res2.mensagem : ""), erros: res2.erros, id: res.id};
    });
  }
  return seguir.then(function (resp) {
    var texto = textoResposta(resp);
    if (!resp.ok) {
      form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
      return restaurarPosicaoDaGravacao(posicao).then(function () { avisoFlutuanteGravacao(texto, true); });
    }
    if (recurso === "relogio" && acao === "excluir") {
      window.location = document.querySelector(".painel") ? "index.php" : "ficha.php";
      return;
    }
    if (recurso === "relogio" && resp.id) { rid = resp.id; }
    var abrir = aberto;
    if (recurso === "relogio" && acao === "salvar") {
      abrir = resp.id;
    } else if (dados.get("r")) {
      abrir = parseInt(dados.get("r"), 10);
    }
    // Atualiza a página e, depois dela, o painel. Não substitui o aviso por uma mensagem no topo.
    if (rid > 0 && rid === abrir) { recados[rid] = texto; }
    var atualizar = window.recarregarHoje ? window.recarregarHoje() : Promise.resolve();
    return Promise.resolve(atualizar).then(function () {
      return painel && abrir > 0 ? abrirNoPainel(abrir) : null;
    }).then(function () {
      return restaurarPosicaoDaGravacao(posicao);
    }).then(function () { avisoFlutuanteGravacao(texto, false, resp.mudancas); });
  });
};
