// Painel da direita: abre o relógio clicado na tabela sem recarregar a página, com os dados da API (recurso=ficha),
// e no cadastro mostra só os campos que valem para o grupo escolhido (os do grupo e os de cada grupo acima dele).
// Também serve a ficha (ficha.php), que mostra o mesmo painel numa página própria.
var recados = {};
var CURTO = ["", "seg", "ter", "qua", "qui", "sex", "sáb", "dom"];

function mostrarCamposDoTipo(form) {
  var sel = form.querySelector(".campo-subtipo");
  if (sel) {
    var opcao = sel.options[sel.selectedIndex];
    var cadeia = (opcao ? opcao.getAttribute("data-cadeia") || "" : "").split(" ");
    form.querySelectorAll("[data-sub]").forEach(function (el) {
      el.hidden = el.getAttribute("data-sub") !== "" && cadeia.indexOf(el.getAttribute("data-sub")) < 0;
    });
  }
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

// O que fazer numa sessão, pelo nome dela: "No pulso" vira "Pôr no pulso" e "Tirar do pulso"; "Na caixa", "Pôr na caixa" e
// "Tirar da caixa"; um nome de outro jeito, "Começar: <nome>" e "Terminar: <nome>"
function acaoSessao(nome, tirar) {
  var m = /^(no|na|nos|nas)\s+(.+)$/i.exec(nome);
  if (!m) {
    return (tirar ? "Terminar: " : "Começar: ") + nome;
  }
  var artigo = m[1].toLowerCase();
  return tirar ? "Tirar " + {"no": "do", "na": "da", "nos": "dos", "nas": "das"}[artigo] + " " + m[2] : "Pôr " + artigo + " " + m[2];
}

// Segundos por extenso, com as duas maiores partes: "1a 5m", "5d 3h", "12h 22min", "40min"
function duracao(seg) {
  var partes = [[31557600, "a"], [2629800, "m"], [86400, "d"], [3600, "h"], [60, "min"]];
  var res = [];
  var resto = Math.max(0, Math.round(seg));
  partes.forEach(function (x) {
    var n = Math.floor(resto / x[0]);
    if ((n > 0 || res.length > 0) && res.length < 2) {
      res.push(n + x[1]);
      resto -= n * x[0];
    }
  });
  return res.length === 0 ? "menos de 1min" : res.filter(function (x) { return x.charAt(0) !== "0"; }).join(" ");
}

// Um instante no formato do campo de data e hora (AAAA-MM-DDTHH:MM), no fuso do navegador
function momentoLocal(d) {
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, "0"), String(d.getDate()).padStart(2, "0")].join("-") + "T"
    + String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
}

// um campo do cadastro, conforme o tipo dele; aparece só nos grupos em que vale (data-sub: o grupo do campo)
function campoCadastro(c) {
  var v = c.valor === null ? "" : String(c.valor);
  var rotulo = h(c.nome) + (c.unidade !== "" ? " (" + h(c.unidade) + ")" : "");
  var nome = "valores[" + c.identificador + "]";
  var sub = c.no_id > 0 ? String(c.no_id) : "";
  var res = "";
  if (c.tipo === "sim_nao") {
    res = el("label", {"data-sub": sub, "class": "check"}, el("input", {"type": "hidden", "name": nome, "value": "0"}) + el("input", {"type": "checkbox", "name": nome, "value": "1",
      "checked": (v !== "" ? v : String(c.padrao || "")) === "1"}) + " " + rotulo);
  } else if (c.tipo === "lista") {
    res = el("label", {"data-sub": sub}, rotulo + " " + el("select", {"name": nome}, el("option", {"value": ""}, "—") + c.opcoes.map(function (o) {
      return el("option", {"selected": o === v}, h(o));
    }).join("")));
  } else if (c.tipo === "data") {
    res = el("label", {"data-sub": sub}, rotulo + " " + el("input", {"type": "date", "name": nome, "value": v}));
  } else if (c.tipo === "texto") {
    res = el("label", {"data-sub": sub}, rotulo + " " + el("input", {"name": nome, "value": v}));
  } else {
    var mostra = v === "" ? "" : (c.unidade === "R$" ? reais(v) : v.replace(".", ","));
    res = el("label", {"data-sub": sub}, rotulo + " " + el("input", {"type": "text", "inputmode": "decimal", "name": nome, "value": mostra,
      "placeholder": c.padrao !== null && c.padrao !== "" ? "vazio: " + String(c.padrao).replace(".", ",") : null}));
  }
  return res;
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
      res += el("h1", {}, "Novo relógio");
    } else {
      // a foto, com os botões dela embaixo: escolher (o campo de arquivo fica escondido atrás do botão), salvar e remover
      var foto = r.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + id + "&v=" + r.foto, "alt": "Foto do " + r.nome}) : el("div", {"class": "sem-foto"}, "Sem foto");
      foto += el("form", {"data-recurso": "relogio", "class": "foto-acoes"}, el("input", {"type": "hidden", "name": "acao", "value": "foto"}) + el("input", {"type": "hidden", "name": "id", "value": id})
        + el("input", {"type": "hidden", "name": "foto_base64", "id": "foto_base64_" + id})
        + el("label", {"class": "botao-arquivo"}, el("input", {"type": "file", "accept": "image/*", "data-foto": "foto_base64_" + id, "data-previa": "previa_" + id,
          "data-salvar": "salvar_foto_" + id, "aria-label": "Escolher foto"}) + (r.foto !== null ? "Trocar foto" : "Escolher foto"))
        + el("img", {"id": "previa_" + id, "class": "previa", "hidden": true, "alt": ""})
        + el("button", {"id": "salvar_foto_" + id, "hidden": true}, "Salvar foto"));
      if (r.foto !== null) {
        foto += el("form", {"data-recurso": "relogio", "class": "foto-remover", "onsubmit": "return confirm('Remover a foto?')"}, el("input", {"type": "hidden", "name": "acao", "value": "remover_foto"})
          + el("input", {"type": "hidden", "name": "id", "value": id}) + el("button", {"class": "leve discreto"}, "Remover foto"));
      }
      // no rodízio: quando entra (e até quando fica), ou por que não entra
      var proxima = "Ainda não sorteado. Neste modo o sorteio é feito na virada de cada período.";
      if (!r.disponivel) {
        proxima = "Indisponível para o rodízio. Marque como disponível no cadastro.";
      } else if (r.proxima !== null) {
        var fica = r.proxima_ate === "só neste dia" ? "" : r.proxima_ate;
        proxima = r.proxima === hoje ? "É o relógio de hoje" + (fica !== "" ? ", " + fica : "") + "."
          : "Entra em " + dataBr(r.proxima, true) + " (" + CURTO[new Date(instante(r.proxima)).getDay() || 7] + ")" + (fica !== "" ? " e fica " + fica : "") + ".";
      } else if (r.escala_fim) {
        proxima = "Não entra na escala atual, que vai até " + dataBr(r.escala_fim) + ".";
      }
      var ultima = r.leituras.length > 0 ? r.leituras[r.leituras.length - 1] : null;
      var nada = function (motivo) { return el("span", {"class": "nota"}, h(motivo || "sem dados")); };
      // Agora: o estado, a carga (com a barra) e o que o sistema sabe da situação
      var carga = r.carga !== null
        ? el("span", {"class": "carga-linha" + (r.carga <= 20 ? " baixa" : "")}, el("span", {"class": "barra"}, el("span", {"style": "width:" + Math.max(0, Math.min(100, r.carga)) + "%"}, ""))
          + el("strong", {}, "~" + r.carga + "%")) + el("small", {}, h(r.carga_de))
        : nada(r.carga_de);
      var blocos = quadro("Agora", pares([["Estado", el("span", {"class": "estado " + (r.em_uso ? "estado-uso" : "estado-repouso")}, h(r.agora))], ["Carga", carga]]
        .concat(r.situacao.map(function (l, i) { return [i === 0 ? "Situação" : "", h(l)]; }))
        .concat(ultima ? [["Última leitura", num(ultima.valor, 1) + h(ultima.unidade) + " " + el("small", {}, dataBr(ultima.inicio, true) + " " + horaBr(ultima.inicio))]] : [])));
      if (r.previsao.aplica) {
        blocos += quadro("Previsão", r.previsao.linhas.length === 0 ? el("p", {"class": "nota"}, "Informe a carga atual para o sistema começar a prever.")
          : el("ul", {"class": "linhas"}, r.previsao.linhas.map(function (l) { return el("li", {}, h(l)); }).join("")));
      }
      // os dois gastos: o que vale na conta, e de onde ele vem (o medido pelas leituras, o do cadastro)
      var gs = r.previsao.gasto;
      if (gs) {
        var pct = function (v) { return num(v, 2) + "%"; };
        var gasto = function (por, x) {
          return x.vale !== null ? el("strong", {}, pct(x.vale)) + " " + por + el("small", {}, x.medido !== null ? "medido; o cadastro diz " + (x.cadastro !== null ? pct(x.cadastro) : "nada") : "do cadastro, sem medição")
            : nada();
        };
        var linhasGasto = [["No pulso", gasto("por dia de uso", gs.uso)], ["Fora do pulso", gasto("por dia", gs.repouso)]];
        if (gs.uso.antes !== null || gs.repouso.antes !== null) {
          linhasGasto.push(["Janela anterior", (gs.uso.antes !== null ? pct(gs.uso.antes) + " no pulso" : "") + (gs.uso.antes !== null && gs.repouso.antes !== null ? " · " : "")
            + (gs.repouso.antes !== null ? pct(gs.repouso.antes) + " fora" : "") + el("small", {}, gs.medicoes_antes + (gs.medicoes_antes === 1 ? " medição" : " medições") + ", nos " + gs.janela_dias + " dias antes")]);
        }
        linhasGasto.push(["Medições", gs.medicoes > 0 ? gs.medicoes + " nos últimos " + gs.janela_dias + " dias"
          + el("small", {}, gs.conjunta ? "os dois gastos saem juntos da conta" : "ainda não separam bem os dois: vale a média de cada um")
          : "nenhuma nos últimos " + gs.janela_dias + " dias" + (gs.uso.medido !== null || gs.repouso.medido !== null ? el("small", {}, "vale a conta com as de antes") : "")]);
        blocos += quadro("Gasto da bateria", pares(linhasGasto), el("p", {"class": "nota"}, "Medições mais velhas que a janela saem da conta: se a bateria envelhecer e gastar mais, o medido sobe sozinho."));
      }
      // a autonomia: cheio pelo cadastro e pela conta (com o gasto medido), quanto ainda dura e quando acaba
      var au = r.autonomia;
      var dura = function (seg, motivo) { return seg !== null ? el("strong", {}, duracao(seg)) : nada(motivo); };
      blocos += quadro("Autonomia", pares([
        ["Cheio, pelo cadastro", dura(au.autonomia_prevista, au.motivos.autonomia_prevista)],
        ["Cheio, pela conta", dura(au.autonomia_atual, au.motivos.autonomia_atual)],
        ["Acaba, seguindo o plano", au.acaba_em_unixtimestamp !== null ? el("strong", {}, dataBr(au.acaba_em_datacomtz.substr(0, 10), au.acaba_em_datacomtz.substr(0, 4) === hoje.substr(0, 4))
          + " " + au.acaba_em_datacomtz.substr(11, 5)) + el("small", {}, "em " + duracao(au.acaba_em_segundos)) : nada(au.motivos.autonomia_estimada)],
        ["No pulso sem tirar", dura(au.restante_em_uso, au.restante_em_uso === null && au.energia !== null ? "não acaba (no pulso ele se recarrega)" : au.motivos.restante_em_uso)],
        ["Guardado", dura(au.restante_guardado, au.motivos.restante_guardado)]]));
      blocos += quadro("Rodízio", pares([
        ["Nota", r.nota !== null ? el("strong", {}, num(r.nota.nota, 1)) + " " + el("a", {"href": "criterios.php?relogio=" + id + "#r" + id, "target": "_blank", "rel": "noopener"}, "ver a conta") : nada("fora do rodízio")],
        ["Critérios de", r.nota !== null ? h(r.nota.conjunto_texto) : "—"],
        ["Próxima vez", h(proxima)]]),
        r.disponivel && r.proxima !== hoje ? el("form", {"data-recurso": "rodizio", "onsubmit": "return confirm(" + JSON.stringify("Usar o " + r.nome
          + " hoje? Ele passa a ser o relógio do rodízio no período atual e o plano é refeito.") + ")"}, el("input", {"type": "hidden", "name": "acao", "value": "usando"})
          + el("input", {"type": "hidden", "name": "relogio_id", "value": id}) + el("button", {"class": "leve"}, "Usando hoje")) : "");
      var c = r.compra;
      blocos += quadro("Compra", c.data === null && c.valor === null && c.loja === null && c.garantia_ate === null
        ? el("p", {"class": "nota"}, "Sem dados da compra. Preencha em \"Editar cadastro\".")
        : pares([["Valor pago", c.valor !== null ? el("strong", {}, "R$ " + reais(c.valor)) : "—"], ["Loja", c.loja !== null ? h(c.loja) : "—"],
          ["Comprado em", c.data !== null ? dataBr(c.data) + el("small", {}, "há " + Math.round((instante(hoje) - instante(c.data)) / 86400000) + " dias") : "—"],
          ["Garantia", c.garantia_ate !== null ? (c.garantia_ate >= hoje ? "até " : "vencida em ") + dataBr(c.garantia_ate) : "—"]]));
      // os documentos: quantos em cada categoria, cada uma abrindo a página Documentos (numa aba nova) já nela
      var dc = r.documentos;
      blocos += quadro("Documentos", (dc.total === 0 ? el("p", {"class": "nota"}, "Nenhum ainda: o manual, a nota fiscal, fotos, vídeos...")
          : pares(dc.categorias.map(function (cat) {
            return [h(cat.nome), el("a", {"href": "documentos.php?relogio=" + id + "&cat=" + cat.id, "target": "_blank", "rel": "noopener"}, cat.documentos + (cat.documentos === 1 ? " arquivo" : " arquivos"))];
          })))
        + (dc.pasta_ok ? "" : el("p", {"class": "nota"}, "Para guardar documentos, falta a pasta deles no config.php (DOCUMENTOS_PASTA).")),
        el("a", {"href": "documentos.php?relogio=" + id, "target": "_blank", "rel": "noopener", "class": "botao leve"}, dc.total === 0 ? "Enviar documentos" : "Abrir os documentos"), "documentos-ficha");
      blocos += quadro("Próximas manutenções", r.manutencoes.length === 0 ? el("p", {"class": "nota"}, "Nada previsto.")
        : pares(r.manutencoes.map(function (m) { return [dataBr(m.data), h(m.nome)]; }), "datas"));
      // marcar: um quadro só. O menu diz o que aconteceu, e embaixo aparecem só os campos daquilo: pôr ou tirar (cada sessão,
      // só o que cabe agora), uma marcação de um momento (corda, pilha...), a leitura (carga), um período que já passou, ou
      // corrigir uma marcação dos últimos 14 dias (um segundo menu escolhe qual)
      var lancar = "";
      if (r.tipos.length > 0) {
        var sessoes = r.tipos.filter(function (t) { return t.formato === "sessao"; });
        var opcoes = {agora: "", passou: "", corrigir: ""};
        var partes = "";
        var parte = function (grupo, valor, conteudo) {
          return el("div", {"class": "marcar-campos", "data-grupo": grupo, "data-valor": valor, "hidden": true}, conteudo);
        };
        var quando = function (rotulo) {
          return el("label", {}, rotulo + " " + el("input", {"type": "datetime-local", "name": "quando", "max": momentoLocal(agora)})
            + el("small", {}, "Vazio: agora."));
        };
        var momento = function (nome, valor, obrigatorio) {
          return el("input", {"type": "datetime-local", "name": nome, "value": valor, "max": momentoLocal(agora), "required": obrigatorio});
        };
        r.tipos.forEach(function (t) {
          var chave = "t-" + t.identificador;
          var base = el("input", {"type": "hidden", "name": "tipo", "value": t.identificador});
          if (t.formato === "sessao") {
            var tirar = t.aberta !== null;
            var verbo = acaoSessao(t.nome, tirar);
            opcoes.agora += el("option", {"value": chave}, h(verbo));
            partes += parte("marcar", chave, base + el("input", {"type": "hidden", "name": "acao", "value": tirar ? "encerrar" : "iniciar"})
              + (tirar ? el("p", {"class": "marcar-estado"}, h(t.aberta.texto)) : "")
              + quando(tirar ? "Tirou às" : "Pôs às") + el("button", {}, h(verbo)));
          } else if (t.formato === "valor") {
            opcoes.agora += el("option", {"value": chave}, h(t.nome));
            partes += parte("marcar", chave, base + el("input", {"type": "hidden", "name": "acao", "value": "lancar"})
              + el("p", {"class": "marcar-estado"}, "Agora " + el("span", {"class": "estado " + (r.em_uso ? "estado-uso" : "estado-repouso")}, r.em_uso ? "em uso" : "em repouso")
                + (r.carga !== null && t.unidade === "%" ? " " + el("small", {}, "estimada ~" + r.carga + "%") : ""))
              + el("label", {}, "Leitura " + el("span", {"class": "com-unidade"}, el("input", {"type": "text", "inputmode": "decimal", "name": "valor", "required": true}) + " " + h(t.unidade)))
              + quando("Lida às")
              + (t.mede_gasto ? el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "medir", "value": "0"})
                + el("input", {"type": "checkbox", "name": "medir", "value": "1", "checked": true}) + " Atualizar o gasto com esta medição") : "")
              + el("button", {}, "Informar " + h(minusculo(t.nome))));
          } else {
            opcoes.agora += el("option", {"value": chave}, h(t.nome));
            partes += parte("marcar", chave, base + el("input", {"type": "hidden", "name": "acao", "value": "lancar"})
              + quando("Quando") + el("button", {}, "Marcar " + h(minusculo(t.nome))));
          }
        });
        // um período inteiro que já passou: onde (se houver mais de uma sessão), de e até
        if (sessoes.length > 0) {
          opcoes.passou += el("option", {"value": "periodo"}, "Um período que já passou (" + h(sessoes.map(function (t) { return minusculo(t.nome); }).join(", ")) + ")");
          partes += parte("marcar", "periodo", el("input", {"type": "hidden", "name": "acao", "value": "periodo"})
            + (sessoes.length > 1
              ? el("label", {}, "Onde " + el("select", {"name": "tipo"}, sessoes.map(function (t) { return el("option", {"value": t.identificador}, h(t.nome)); }).join("")))
              : el("input", {"type": "hidden", "name": "tipo", "value": sessoes[0].identificador}))
            + el("label", {}, "De " + momento("inicio", momentoLocal(new Date(agora.getTime() - 3600000)), true))
            + el("label", {}, "Até " + momento("fim", momentoLocal(agora), true))
            + el("button", {}, "Registrar o período"));
        }
        // corrigir: o segundo menu escolhe a marcação, e embaixo aparecem os campos dela, com Salvar e Excluir
        if (r.lancamentos_recentes.length > 0) {
          opcoes.corrigir += el("option", {"value": "corrigir"}, "Corrigir ou excluir uma marcação (últimos 14 dias)");
          var qual = "";
          var campos = "";
          r.lancamentos_recentes.forEach(function (l) {
            var programado = l.fim !== null && instante(l.fim) > agora.getTime();
            var desc = dataBr(l.inicio, true) + " " + horaBr(l.inicio) + " · " + l.nome
              + (l.formato === "sessao" ? (l.fim === null ? " (aberta)" : " até " + horaBr(l.fim)) : "")
              + (l.formato === "valor" ? ": " + num(l.valor, 1) + " " + l.unidade : "") + (l.origem === "rodizio" ? " (rodízio)" : "");
            qual += el("option", {"value": String(l.id)}, h(desc));
            var desta = "";
            if (l.formato === "sessao") {
              desta = el("label", {}, "De " + momento("inicio", l.inicio.substring(0, 16).replace(" ", "T"), true))
                + (programado
                  ? el("p", {"class": "nota"}, "Até " + horaBr(l.fim) + " (o fim do horário de uso).")
                  : el("label", {}, "Até " + momento("fim", l.fim === null ? "" : l.fim.substring(0, 16).replace(" ", "T"), false)
                    + el("small", {}, "Vazio: a sessão continua aberta.")));
            } else {
              desta = el("label", {}, "Quando " + momento("inicio", l.inicio.substring(0, 16).replace(" ", "T"), true))
                + (l.formato === "valor" ? el("label", {}, "Valor " + el("span", {"class": "com-unidade"}, el("input", {"type": "text", "inputmode": "decimal", "name": "valor", "value": num(l.valor, 1), "required": true}) + " " + h(l.unidade))) : "");
            }
            campos += parte("corrigir", String(l.id), el("input", {"type": "hidden", "name": "id", "value": l.id}) + desta
              + el("span", {"class": "marcar-botoes"}, el("button", {"name": "acao", "value": "alterar"}, "Salvar")
                + el("button", {"name": "acao", "value": "excluir", "class": "leve discreto", "formnovalidate": true,
                  "onclick": "return confirm(" + JSON.stringify("Excluir " + l.nome + " de " + dataBr(l.inicio, true) + " " + horaBr(l.inicio) + "?") + ")"}, "Excluir")));
          });
          partes += parte("marcar", "corrigir", el("p", {"class": "nota"}, "Esqueceu o Tirou à noite? Escolha a sessão e ponha a hora certa no fim.")
            + el("label", {}, "Qual marcação " + el("select", {"data-mostra": "corrigir"}, el("option", {"value": ""}, "— escolha —") + qual)) + campos);
        }
        var menu = el("option", {"value": ""}, "— escolha —")
          + (opcoes.agora !== "" ? el("optgroup", {"label": "Agora, ou numa hora que você disser"}, opcoes.agora) : "")
          + (opcoes.passou !== "" ? el("optgroup", {"label": "Algo que já passou"}, opcoes.passou) : "")
          + (opcoes.corrigir !== "" ? el("optgroup", {"label": "Corrigir"}, opcoes.corrigir) : "");
        lancar = quadro("Marcar", el("form", {"data-recurso": "lancamento", "class": "marcar"}, el("input", {"type": "hidden", "name": "relogio_id", "value": id})
          + el("label", {"class": "marcar-o-que"}, "O que você quer marcar? " + el("select", {"data-mostra": "marcar"}, menu)) + partes), "", "quadro-marcar");
      }
      // a cabeça: a foto e quem ele é (o grupo, o nome e as marcas de agora); embaixo, os quadros, todos alinhados numa grade
      res += el("div", {"class": "ficha-cabeca"}, el("div", {"class": "ficha-foto"}, foto)
        + el("div", {"class": "ficha-identidade"}, el("p", {"class": "data"}, h(r.tipo) + " · " + h(r.caminho))
          + el("h1", {}, h(r.nome)) + (r.observacao ? el("p", {"class": "observacao"}, h(r.observacao)) : "")
          + el("p", {"class": "marcas"}, el("span", {"class": "estado " + (r.em_uso ? "estado-uso" : "estado-repouso")}, h(r.em_uso ? "em uso" : "em repouso"))
            + (r.disponivel ? "" : " " + el("span", {"class": "estado estado-indisponivel"}, "indisponível"))
            + (r.proxima === hoje ? " " + el("span", {"class": "estado estado-atividade"}, "relógio de hoje") : ""))))
        + el("div", {"class": "quadros"}, blocos + lancar);
    }
    // o cadastro: nome, grupo, os campos (cada um no grupo em que vale), a compra agrupada, a observação, a foto (novo) e o disponível
    var compraIds = ["data_compra", "valor_compra", "loja", "garantia_ate"];
    var noForm = novo ? Number(new URLSearchParams(window.location.search).get("no_id") || 0) : r.no_id;
    var cad = el("input", {"type": "hidden", "name": "acao", "value": "salvar"}) + (novo ? "" : el("input", {"type": "hidden", "name": "id", "value": id}))
      + el("label", {}, "Nome " + el("input", {"name": "nome", "value": novo ? "" : r.nome, "required": true}))
      + el("label", {}, "Grupo " + el("select", {"name": "no_id", "class": "campo-subtipo"}, el("option", {"value": "0", "data-cadeia": "", "selected": noForm === 0}, "(na raiz)")
        + d.grupos.map(function (g) { return el("option", {"value": g.id, "data-cadeia": g.cadeia.join(" "), "selected": noForm === g.id}, h(g.caminho)); }).join(""))
        + " " + el("small", {}, "os campos, os lançamentos e os critérios de um grupo valem para tudo abaixo dele (páginas Grupos e Cadastros)"));
    d.campos.forEach(function (c) {
      if (compraIds.indexOf(c.identificador) < 0 && c.identificador !== "observacao") {
        cad += campoCadastro(c);
      }
    });
    var deCompra = d.campos.filter(function (c) { return compraIds.indexOf(c.identificador) >= 0; });
    if (deCompra.length > 0) {
      cad += el("fieldset", {"class": "compra"}, el("legend", {}, "Compra") + deCompra.map(campoCadastro).join(""));
    }
    d.campos.forEach(function (c) {
      if (c.identificador === "observacao") {
        cad += campoCadastro(c);
      }
    });
    if (novo) {
      cad += el("label", {}, "Foto " + el("input", {"type": "file", "accept": "image/*", "data-foto": "foto_base64_" + fid, "data-previa": "previa_" + fid, "aria-label": "Escolher foto"}))
        + el("input", {"type": "hidden", "name": "foto_base64", "id": "foto_base64_" + fid}) + el("img", {"id": "previa_" + fid, "class": "previa", "hidden": true, "alt": ""});
    }
    cad += el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "disponivel", "value": "0"}) + el("input", {"type": "checkbox", "name": "disponivel", "value": "1",
      "checked": novo || r.disponivel}) + " Disponível para o rodízio")
      // a escolha do relógio só aparece quando o config.php não decide por todos (DOCUMENTOS_COPIA_BANCO ausente)
      + (d.copia_sistema === null ? el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "copia_banco", "value": "0"})
        + el("input", {"type": "checkbox", "name": "copia_banco", "value": "1", "checked": !novo && r.copia_banco}) + " Guardar no banco a cópia de todos os documentos dele") : "")
      + el("div", {"class": "botoes"}, el("button", {}, novo ? "Cadastrar relógio" : "Salvar alterações"));
    var cadastro = el("form", {"data-recurso": "relogio", "class": "cadastro"}, cad);
    if (novo) {
      res += cadastro;
    } else {
      res += el("details", {"class": "cadastro-painel"}, el("summary", {}, "Editar cadastro") + cadastro
        + el("form", {"data-recurso": "relogio", "class": "excluir", "onsubmit": "return confirm(" + JSON.stringify("Excluir " + r.nome + " com todo o histórico e a foto?") + ")"},
          el("input", {"type": "hidden", "name": "acao", "value": "excluir"}) + el("input", {"type": "hidden", "name": "id", "value": id}) + el("button", {"class": "leve discreto"}, "Excluir este relógio")));
      // o gráfico das leituras de carga
      if (r.leituras.length > 0 || r.previsao.aplica) {
        var grafico = el("p", {"class": "nota"}, "O gráfico aparece a partir de duas leituras de carga.");
        if (r.leituras.length >= 2) {
          var t0 = instante(r.leituras[0].inicio);
          var t1 = Math.max(t0 + 1000, instante(r.leituras[r.leituras.length - 1].inicio));
          var pontos = r.leituras.map(function (l) {
            return (Math.round((instante(l.inicio) - t0) / (t1 - t0) * 6000) / 10) + "," + (Math.round((110 - Math.max(0, Math.min(100, l.valor))) * 10) / 10);
          });
          var de = dataBr(r.leituras[0].inicio, true);
          var ate = dataBr(r.leituras[r.leituras.length - 1].inicio, true);
          grafico = "<svg class=\"grafico\" viewBox=\"-10 0 620 120\" role=\"img\" aria-label=\"Leituras de carga de " + de + " a " + ate + "\">"
            + "<line x1=\"0\" y1=\"10\" x2=\"600\" y2=\"10\" class=\"guia\"/><line x1=\"0\" y1=\"60\" x2=\"600\" y2=\"60\" class=\"guia\"/><line x1=\"0\" y1=\"110\" x2=\"600\" y2=\"110\" class=\"guia\"/>"
            + "<polyline points=\"" + pontos.join(" ") + "\" class=\"linha-carga\"/>"
            + pontos.map(function (p, i) {
              var xy = p.split(",");
              return "<circle cx=\"" + xy[0] + "\" cy=\"" + xy[1] + "\" r=\"4\" class=\"" + (r.leituras[i].em_uso ? "ponto-uso" : "ponto-repouso") + "\"/>";
            }).join("")
            + "</svg>" + el("p", {"class": "nota"}, el("span", {"class": "bolinha ponto-uso"}, "") + " leitura em uso " + el("span", {"class": "bolinha ponto-repouso"}, "") + " em repouso. "
              + de + " a " + ate + ", de 0 a 100%." + (r.previsao.conta.length > 0 ? " Conta: " + h(r.previsao.conta.map(function (cc) {
                return cc.nome + ": " + (cc.valor === null ? "vazio" : num(cc.valor, 2) + (cc.unidade !== "" ? " " + cc.unidade : "") + " (" + cc.origem + ")");
              }).join("; ")) + "." : ""));
        }
        // as medições do gasto pelas leituras (as 10 mais recentes) e se cada uma entra na média
        var med = "";
        if (r.medicoes.length > 0) {
          med = el("h3", {}, "Medições do gasto") + el("p", {"class": "nota"}, "Cada leitura comparada com a anterior. Os dois gastos (em uso e fora do pulso) saem juntos das medições dos últimos "
              + r.medicao_janela_dias + " dias, pesadas pelas horas de cada uma (a janela fica na Configuração); o gasto de cada linha é só o dela.")
            + el("table", {"class": "tabela-dados medicoes"}, el("thead", {}, el("tr", {}, el("th", {}, "Quando") + el("th", {}, "Leituras") + el("th", {}, "Horas")
              + el("th", {}, "Gasto medido") + el("th", {}, "Na conta")))
              + el("tbody", {}, r.medicoes.slice(0, 10).map(function (m) {
                return el("tr", {}, el("td", {}, dataBr(m.fim, true) + " " + horaBr(m.fim)) + el("td", {}, num(m.de_valor, 1) + " → " + num(m.ate_valor, 1))
                  + el("td", {}, num(m.horas_pulso, 1) + " h no pulso, " + num(m.horas_guardado, 1) + " h fora")
                  + el("td", {}, num(m.taxa, 2) + "% por dia " + (m.medida === "uso" ? "de uso" : "fora do pulso"))
                  + el("td", {}, m.na_media ? "sim" : (m.usada ? "não (fora da janela)" : "não (só histórico)")));
              }).join("")));
        }
        res += quadro("Carga ao longo do tempo", grafico + med, "", "largo");
      }
      // os dados do relógio: o cadastro que vale para ele (o informado, senão o padrão do campo) e o resultado de cada fórmula agora
      var valorCampo = function (c) {
        var v = c.valor_usado;
        var res2 = "—";
        if (v !== null && v !== "") {
          if (c.tipo === "sim_nao") {
            res2 = String(v) === "1" ? "sim" : "não";
          } else if (c.tipo === "data") {
            res2 = dataBr(v);
          } else if (c.tipo === "inteiro" || c.tipo === "decimal") {
            res2 = c.unidade === "R$" ? "R$ " + reais(v) : num(v, 2) + (c.unidade !== "" ? " " + h(c.unidade) : "");
          } else {
            res2 = h(v);
          }
        }
        return res2;
      };
      var valorCalculo = function (x) {
        var res2 = "vazio";
        if (typeof x.valor === "number") {
          res2 = x.unidade === "s" ? duracao(x.valor) + " " + el("small", {}, "(" + Math.round(x.valor) + " s)") : num(x.valor, 2) + (x.unidade !== "" ? " " + h(x.unidade) : "");
        } else if (x.valor !== null) {
          res2 = h(x.valor);
        }
        return res2;
      };
      // duas tabelas lado a lado: o cadastro (o valor e de onde ele vem) e o resultado de cada fórmula agora (com a versão)
      res += quadro("Dados do relógio", el("div", {"class": "dados-grade"},
        el("div", {}, el("h3", {}, "Cadastro") + el("table", {"class": "tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, "Campo")
            + el("th", {}, "Valor") + el("th", {}, "Origem"))) + el("tbody", {}, r.dados.map(function (c) {
              return el("tr", {"class": c.origem === "vazio" ? "vazio" : ""}, el("td", {}, h(c.nome)) + el("td", {"class": "valor"}, valorCampo(c))
                + el("td", {}, el("span", {"class": "origem origem-" + (c.origem === "padrão" ? "padrao" : c.origem)}, h(c.origem))));
            }).join(""))))
          + el("div", {}, el("h3", {}, "Calculado agora") + el("table", {"class": "tabela-dados"}, el("thead", {}, el("tr", {}, el("th", {}, "Fórmula")
            + el("th", {}, "Valor") + el("th", {}, "Versão"))) + el("tbody", {}, r.calculos.map(function (x) {
              return el("tr", {}, el("td", {}, h(x.nome) + el("code", {"class": "ident"}, h(x.identificador))) + el("td", {"class": "valor"}, valorCalculo(x))
                + el("td", {"class": "versao"}, h(x.versao)));
            }).join(""))))), "", "largo");
      // o histórico: no painel só o resumo; a linha do tempo inteira fica em historico.php, que abre numa aba nova
      var hist = el("p", {"class": "nota"}, "Nada lançado ainda.");
      if (r.linha_do_tempo.length > 0) {
        var ultimo = null;
        r.linha_do_tempo.forEach(function (l) {
          if (l.estado !== "marca" && ultimo === null) {
            ultimo = l;
          }
        });
        hist = el("p", {"class": "nota"}, r.registros + " registros desde " + dataBr(r.desde) + " " + horaBr(r.desde) + "."
            + (ultimo ? " Agora " + h(ultimo.texto) + " há " + h(ultimo.duracao || "menos de 1 minuto") + "." : ""))
          + el("table", {"class": "tabela-dados historico-curto"}, el("thead", {}, el("tr", {}, el("th", {}, "Quando") + el("th", {}, "Duração") + el("th", {}, "Estado")))
            + el("tbody", {}, r.linha_do_tempo.map(function (l) {
              var classe = l.estado === "rodizio" || l.estado === "pulso" ? "uso" : (l.estado === "repouso" ? "repouso" : "carga");
              return el("tr", {"class": l.estado === "marca" ? "marca" : ""}, el("td", {}, dataBr(l.inicio, true) + " " + horaBr(l.inicio)
                  + (l.fim !== null ? el("small", {}, "até " + (l.fim.substr(0, 10) === l.inicio.substr(0, 10) ? horaBr(l.fim) : dataBr(l.fim, true) + " " + horaBr(l.fim))) : ""))
                + el("td", {}, l.fim === null ? "—" : h(l.duracao))
                + el("td", {}, l.estado === "marca" ? h(l.texto) : el("span", {"class": "estado estado-" + classe}, h(l.texto)) + (l.em_andamento ? el("small", {}, "em andamento") : "")));
            }).join("")));
      }
      res += quadro("Histórico", hist, r.linha_do_tempo.length > 0 ? el("a", {"class": "botao leve", "href": "historico.php?id=" + id, "target": "_blank", "rel": "noopener"}, "Abrir o histórico completo") : "",
        "largo historico");
    }
    painel.innerHTML = el("div", {"class": "detalhe", "data-id": id}, res);

    document.querySelectorAll("form.cadastro").forEach(mostrarCamposDoTipo);
    document.querySelectorAll("form.marcar").forEach(aplicarEscolhas);
    // o painel aberto sempre tem o "×" para fechar (só na página Hoje; a ficha não fecha)
    if (!painel.hidden && document.querySelector(".painel") && !painel.querySelector("[data-fechar-painel]")) {
      var fechar = document.createElement("button");
      fechar.type = "button";
      fechar.className = "fechar-painel";
      fechar.setAttribute("data-fechar-painel", "");
      fechar.setAttribute("aria-label", "Fechar o painel");
      fechar.textContent = "×";
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
  if (ev.target.matches(".campo-subtipo")) {
    mostrarCamposDoTipo(ev.target.form);
  }
  if (ev.target.matches("select[data-mostra]")) {
    aplicarEscolhas(ev.target.form);
  }
});

document.addEventListener("click", function (ev) {
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
  var painel = document.getElementById("detalhe");
  var atual = painel ? painel.querySelector(".detalhe") : null;
  var aberto = atual !== null ? parseInt(atual.getAttribute("data-id"), 10) : 0;
  var rid = parseInt(dados.get("relogio_id") || dados.get("id") || "0", 10);
  var recurso = dados.get("recurso");
  var acao = dados.get("acao");
  var seguir = Promise.resolve(res);
  if (recurso === "relogio" && acao === "salvar" && res.ok && !dados.get("id") && dados.get("foto_base64")) {
    seguir = enviar({recurso: "relogio", acao: "foto", id: res.id, foto_base64: dados.get("foto_base64")}).then(function (res2) {
      return {ok: res.ok && res2.ok, mensagem: res.mensagem + (res2.ok ? " " + res2.mensagem : ""), erros: res2.erros, id: res.id};
    });
  }
  seguir.then(function (resp) {
    var texto = textoResposta(resp);
    if (recurso === "relogio" && acao === "excluir" && resp.ok) {
      window.location = document.querySelector(".painel") ? "index.php" : "ficha.php";
    } else {
      if (recurso === "relogio" && resp.id) {
        rid = resp.id;
      }
      // o relógio a mostrar no painel depois: o criado, o do aviso apertado, ou o que já estava aberto
      var abrir = aberto;
      if (recurso === "relogio" && acao === "salvar" && resp.ok) {
        abrir = resp.id;
      } else if (dados.get("r")) {
        abrir = parseInt(dados.get("r"), 10);
      }
      if (rid > 0 && rid === abrir) {
        recados[rid] = texto;
      } else if (texto.trim() !== "" && window.mostrarRecado) {
        window.mostrarRecado(texto);
      }
      if (window.recarregarHoje) {
        window.recarregarHoje();
      }
      // o cadastro de um relógio novo recusado fica aberto como está, com o recado no topo
      if (painel && abrir > 0) {
        abrirNoPainel(abrir);
      }
    }
  });
};
