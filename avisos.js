// Avisos: tudo o que o sistema lembra, num lugar só (o avisos.php). Lê a API (recurso=cadastros: os avisos dos relógios, os
// grupos e os tipos de lançamento; recurso=config: os lembretes, os relógios, os destinos e as agendas) e monta três partes:
// os avisos dos relógios (calculados pelas fórmulas, o cadastro da árvore: recurso=avisos), os lembretes (os eventos
// personalizados da coleção, com hora e repetição: recurso=config, acao=evento_salvar e evento_excluir) e para onde vão
// (o resumo dos destinos e das agendas, com o caminho para as páginas deles). avisos.php?editar=<id>#form abre um aviso para
// alterar; avisos.php?evento=<id>#lembretes, um lembrete
var recado = "";

// o lugar na árvore por extenso
function caminho(dc, id) {
  var res = t("todos os relógios");
  dc.grupos.forEach(function (g) {
    if (g.id === id) {
      res = g.caminho;
    }
  });
  return res;
}
function opcoesNo(dc, sel) {
  return el("option", {"value": "0"}, t("todos os relógios")) + dc.grupos.map(function (g) { return el("option", {"value": g.id, "selected": sel === g.id}, h(g.caminho)); }).join("");
}
// botões de uma linha da tabela dos avisos: um formulário pequeno que grava pela API (o Excluir não pergunta aqui: a API diz
// o que sai junto e a pergunta vem com isso, no api.js)
function botaoLinha(acao, id, rotulo, confirma, extra) {
  return el("form", {"data-recurso": "avisos", "style": "display:inline", "onsubmit": confirma && acao !== "excluir" ? "return confirm(" + JSON.stringify(confirma) + ")" : null},
    el("input", {"type": "hidden", "name": "acao", "value": acao}) + el("input", {"type": "hidden", "name": "id", "value": id}) + (extra || el("button", {"class": "leve discreto"}, rotulo)));
}
function linkEditar(id) {
  return el("a", {"href": "avisos.php?editar=" + id + "#form"}, t("Editar"));
}
function vazio(v) {
  return v === null || v === undefined ? "" : v;
}
// um quadro (como os da Configuração): o título, o conteúdo e, se houver, o pé
function cartao(titulo, corpo, pe) {
  return el("section", {"class": "cartao-config"}, el("h2", {}, h(titulo)) + el("div", {"class": "cartao-corpo"}, corpo) + (pe ? el("div", {"class": "cartao-pe"}, pe) : ""));
}
// o lembrete: mostra só os campos da repetição escolhida
function mostrarRepeticao() {
  var repeticao = document.getElementById("ev-repeticao");
  if (repeticao) {
    document.querySelectorAll(".form-evento [data-rep]").forEach(function (e) {
      e.hidden = e.dataset.rep.split(" ").indexOf(repeticao.value) < 0;
    });
  }
}

// lê e monta a página
function recarregarAvisos() {
  return Promise.all([api({recurso: "cadastros"}), api({recurso: "config"})]).then(function (r) {
    var dc = r[0];
    var dg = r[1];
    var q = new URLSearchParams(window.location.search);
    var editar = parseInt(q.get("editar") || "0", 10);
    var res = el("h1", {}, t("Avisos")) + el("nav", {"class": "abas"}, el("a", {"href": "#avisos-relogios"}, t("Avisos dos relógios")) + el("a", {"href": "#lembretes"}, t("Lembretes"))
      + el("a", {"href": "#para-onde"}, t("Para onde vão")))
      + el("div", {"class": "botoes"}, linkRelatorio("avisos", {}, t("Relatório dos avisos")))
      + el("p", {"class": "nota"}, t("Tudo o que o sistema lembra: os avisos dos relógios, que ele calcula sozinho pelos dados de cada um (dar corda, carregar, trocar a pilha...); os lembretes, que você marca com hora e repetição; e para onde vão os dois (os destinos das mensagens e as agendas)."));
    if (recado !== "") {
      res += recado;
      recado = "";
    }
    // ---------- 1. os avisos dos relógios ----------
    var ESCALA_A = {nao: t("não"), uso: t("no relógio do dia"), sempre: t("sempre")};
    var AGENDA_A = {janela: t("só dentro da antecedência da agenda"), sempre: t("qualquer data")};
    var e = null;
    dc.avisos.forEach(function (x) { if (x.id === editar) { e = x; } });
    var f = e || {};
    res += el("section", {"class": "cartao-config", "id": "avisos-relogios"}, el("h2", {}, t("Avisos dos relógios"))
      + el("p", {"class": "nota"}, t("Cada aviso tem uma fórmula da data prevista; entra \"em breve\" dentro da antecedência e \"atrasado\" quando a data passa. O \"vale quando\" (uma fórmula) diz para quais relógios o aviso vale (ex.: corda_manual = 0, só para o automático sem corda); vazio, vale para todos do grupo. O texto é o motivo nas mensagens ({motivo}): \"Dar corda: San Martin (a reserva acaba em 3h, 28/09/2026 10:53)\"; nele: {relogio}, {data}, {quando} e {limite} (o limite de carga do relógio). O tipo que resolve vira o botão na tela Hoje. Como as fórmulas, pode ter uma versão por ponto da árvore. Na escala inteligente: \"no relógio do dia\" é conferido no escolhido, do começo ao fim do bloco dele; \"sempre\", também nos guardados, todo dia. O que vence vira o lembrete do dia, e o lançamento que resolve é simulado com o valor (leitura) ou as horas (sessão) do aviso. Se o aviso vai para cada destino e cada agenda (e com que texto) se escolhe neles, em Para onde vão, embaixo; aqui fica a data que ele usa na agenda. \"Na hora\": além do alerta da manhã, o Telegram recebe o aviso no minuto em que ele vence (uma vez, até ele ser resolvido), como o \"Tirar do carregador\" quando a bateria enche."))
      + el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Aviso"), t("Versão para"), t("Data prevista"), t("Vale quando"), t("Antecedência"), t("Resolve"), t("Na escala"), t("Na agenda"), t("Na hora"), t("Ativo"), ""].map(function (item, i) {
          return el("th", {"class": i === 4 ? "num" : null}, item);
        }).join(""))) + el("tbody", {}, dc.avisos.map(function (a) {
          return el("tr", {}, el("td", {}, h(a.nome) + " " + el("span", {"class": "nota formula"}, h(a.identificador))) + el("td", {"class": "nota"}, h(caminho(dc, a.no_id))) + el("td", {"class": "formula"}, h(a.expressao))
            + el("td", {"class": "formula"}, h(vazio(a.condicao))) + el("td", {"class": "num"}, num(a.antecedencia_dias, 2) + t(" d")) + el("td", {}, h(vazio(a.resolve)))
            + el("td", {}, h(ESCALA_A[a.escala]) + (a.simula_valor !== null ? " " + el("span", {"class": "nota"}, "(" + num(a.simula_valor, 2) + ")") : "")
              + (a.simula_horas !== null ? " " + el("span", {"class": "nota"}, "(" + num(a.simula_horas, 2) + t(" h)")) : ""))
            + el("td", {}, h(AGENDA_A[a.agenda])) + el("td", {}, a.na_hora === 1 ? t("sim") : t("não")) + el("td", {}, a.ativo === 1 ? t("sim") : t("não"))
            + el("td", {"class": "acoes-linha"}, linkEditar(a.id) + " " + botaoLinha("excluir", a.id, t("Excluir"), t("Excluir esta versão do aviso?"))));
        }).join(""))))
      + el("h3", {"id": "form"}, e ? t("Alterar {1}", {1: h(e.nome)}) : t("Novo aviso (ou nova versão)"))
      + el("form", {"data-recurso": "avisos", "class": "form-grade"}, el("input", {"type": "hidden", "name": "acao", "value": e ? "alterar" : "novo"}) + (e ? el("input", {"type": "hidden", "name": "id", "value": e.id}) : "")
        + (e ? "" : el("label", {}, t("Identificador {1}", {1: el("input", {"name": "identificador", "required": true, "pattern": "[a-z][a-z0-9_]{0,39}"})})))
        + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": vazio(f.nome), "required": true})}))
        + el("label", {}, t("Antecedência (dias) {1}", {1: el("input", {"name": "antecedencia_dias", "value": num(f.antecedencia_dias || 0, 2), "inputmode": "decimal"})}))
        + el("label", {}, t("Resolve {1}", {1: el("select", {"name": "resolve"}, el("option", {"value": ""}, "—") + dc.lancamento_tipos.map(function (item) {
          return el("option", {"value": item.identificador, "selected": f.resolve === item.identificador}, h(item.nome));
        }).join(""))}))
        + el("label", {}, t("Versão para {1}", {1: el("select", {"name": "no_id"}, opcoesNo(dc, f.no_id))}))
        + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "ativo", "value": "0"}) + el("input", {"type": "checkbox", "name": "ativo", "value": "1", "checked": e ? e.ativo === 1 : true}) + t(" ativo"))
        + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "na_hora", "value": "0"}) + el("input", {"type": "checkbox", "name": "na_hora", "value": "1", "checked": e ? e.na_hora === 1 : false}) + t(" na hora (Telegram no minuto em que vence)"))
        + el("label", {}, t("Na escala inteligente {1}", {1: el("select", {"name": "escala"}, Object.keys(ESCALA_A).map(function (k) { return el("option", {"value": k, "selected": (f.escala || "nao") === k}, h(ESCALA_A[k])); }).join(""))}))
        + el("label", {}, t("Escala: valor do lançamento simulado (leitura) {1}", {1: el("input", {"name": "simula_valor", "inputmode": "decimal", "value": f.simula_valor ? num(f.simula_valor, 2) : "", "placeholder": t("ex.: 100")})}))
        + el("label", {}, t("Escala: horas da sessão simulada {1}", {1: el("input", {"name": "simula_horas", "inputmode": "decimal", "value": f.simula_horas ? num(f.simula_horas, 2) : "", "placeholder": t("ex.: 6")})}))
        + el("label", {}, t("Data na agenda {1}", {1: el("select", {"name": "agenda"}, Object.keys(AGENDA_A).map(function (k) { return el("option", {"value": k, "selected": (f.agenda || "janela") === k}, h(AGENDA_A[k])); }).join(""))}))
        + el("label", {"style": "grid-column: 1 / -1"}, t("Data prevista (fórmula) {1}", {1: el("textarea", {"name": "expressao", "class": "formula", "rows": "2", "required": true}, h(vazio(f.expressao)))}))
        + el("label", {"style": "grid-column: 1 / -1"}, t("Vale quando (fórmula: 1 vale, 0 não; vazio: vale sempre) {1}", {1: el("input", {"name": "condicao", "class": "formula", "value": vazio(f.condicao),
          "placeholder": t("ex.: corda_manual = 0")})}))
        + el("label", {"style": "grid-column: 1 / -1"}, t("Texto (o motivo) {1}", {1: el("input", {"name": "texto", "value": vazio(f.texto), "maxlength": "300", "required": true})}))
        + el("div", {"class": "botoes"}, el("button", {}, e ? t("Salvar") : t("Criar")))));
    // ---------- 2. os lembretes (os eventos personalizados) ----------
    // os eventos personalizados: editar e excluir
    var eventosLista = dg.eventos.length === 0 ? el("p", {"class": "nota"}, t("Nenhum evento ainda."))
      : el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, [t("Evento"), t("Quando"), t("Relógio"), t("Vai para"), ""].map(function (x) { return el("th", {}, x); }).join("")))
        + el("tbody", {}, dg.eventos.map(function (ev) {
          return el("tr", {"class": ev.ativo ? null : "inativo"}, el("td", {}, h(ev.nome) + (ev.ativo ? "" : " " + el("span", {"class": "nota"}, t("(desligado)"))))
            + el("td", {}, h(ev.descricao)) + el("td", {}, ev.relogio ? h(ev.relogio) : "—")
            + el("td", {}, h(ev.destinos.concat(ev.agendas).join(", ")) || "—")
            + el("td", {"class": "acoes-linha"}, el("a", {"href": "avisos.php?evento=" + ev.id + "#lembretes"}, t("Editar")) + " "
              + el("button", {"type": "submit", "form": "f-evento-excluir", "name": "evento_id", "value": ev.id, "class": "discreto"}, t("Excluir"))));
        }).join(""))));
    res += el("form", {"data-recurso": "config", "id": "f-evento-excluir"}, el("input", {"type": "hidden", "name": "acao", "value": "evento_excluir"}));
    // ---------- o cadastro de um evento ----------
    var edit = null;
    dg.eventos.forEach(function (ev) {
      if (String(ev.id) === q.get("evento")) {
        edit = ev;
      }
    });
    var fe = edit || {id: 0, nome: "", ativo: true, repeticao: "semanal", data_inicio: "", hora: "20:00", dias_semana: "7", dia_mes: 1, intervalo_dias: 30, relogio_id: null};
    var diasFe = String(fe.dias_semana || "").split(",");
    var formEvento = el("input", {"type": "hidden", "name": "acao", "value": "evento_salvar"}) + el("input", {"type": "hidden", "name": "evento_id", "value": fe.id})
      + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": fe.nome, "required": true, "placeholder": t("Conferir a carga dos smartwatches")})}))
      + el("label", {}, t("Relógio (opcional: com relógio, as âncoras dele funcionam) {1}", {1: el("select", {"name": "relogio_id"}, el("option", {"value": "0"}, t("Nenhum, é um evento geral"))
        + dg.relogios.filter(function (r) { return r.disponivel || fe.relogio_id === r.id; }).map(function (r) {
          return el("option", {"value": r.id, "selected": fe.relogio_id === r.id}, h(r.nome) + (r.disponivel ? "" : t(" (indisponível)")));
        }).join(""))}))
      + el("label", {}, t("Quando dispara {1}", {1: el("select", {"name": "repeticao", "id": "ev-repeticao"}, Object.keys(dg.repeticoes).map(function (k) {
        return el("option", {"value": k, "selected": fe.repeticao === k}, h(dg.repeticoes[k]));
      }).join(""))}))
      + el("div", {"class": "linha-campos"}, el("label", {"data-rep": "uma intervalo"}, el("span", {"data-rep": "uma"}, t("Data")) + el("span", {"data-rep": "intervalo"}, t("A partir de")) + " "
          + el("input", {"type": "date", "name": "data_inicio", "value": fe.data_inicio || ""}))
        + el("label", {}, t("Hora {1}", {1: el("input", {"type": "time", "name": "hora", "value": fe.hora, "required": true})}))
        + el("label", {"data-rep": "mensal"}, t("Dia do mês {1}", {1: el("input", {"type": "number", "min": "1", "max": "31", "name": "dia_mes", "value": fe.dia_mes || 1})}))
        + el("label", {"data-rep": "intervalo"}, t("A cada quantos dias {1}", {1: el("input", {"type": "number", "min": "1", "name": "intervalo_dias", "value": fe.intervalo_dias || 30})})))
      + el("fieldset", {"data-rep": "semanal", "class": "dias-semana"}, el("legend", {}, t("Dias da semana")) + ["", t("seg"), t("ter"), t("qua"), t("qui"), t("sex"), t("sáb"), t("dom")].map(function (dn, n) {
        return n === 0 ? "" : el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "dias_semana[]", "value": n, "checked": diasFe.indexOf(String(n)) >= 0}) + " " + dn);
      }).join(""))
      + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "ativo", "value": "0"}) + el("input", {"type": "checkbox", "name": "ativo", "value": "1", "checked": fe.ativo}) + t(" Ativo"))
      + (edit ? el("p", {"class": "nota"}, t("Próximas vezes: {1}.", {1: edit.proximas_60.length > 0 ? h(edit.proximas_60.map(function (item) { return dataBr(item, true) + " " + horaBr(item); }).join(", "))
        : t("nenhuma nos próximos 60 dias")})) : "")
      + el("p", {"class": "nota"}, t("Evento novo já vai por todos os destinos da coleção. Em cada destino e cada agenda (páginas Destinos e Agendas), escolha se ele vai e com que texto."))
      + el("div", {"class": "botoes"}, el("button", {}, edit ? t("Salvar alterações") : t("Criar evento")) + (edit ? " " + el("a", {"href": "avisos.php#lembretes"}, t("cancelar")) : ""));
    res += el("section", {"class": "cartao-config", "id": "lembretes"}, el("h2", {}, t("Lembretes (eventos personalizados)"))
      + el("p", {"class": "nota"}, t("Um aviso seu, com horário e repetição próprios: todo dia, em dias da semana, todo mês, a cada N dias ou uma vez só."))
      + eventosLista
      + el("details", {"class": "cartao-config novo-evento", "open": edit !== null || q.has("novo_evento")},
      el("summary", {}, edit ? t("Editar o evento {1}", {1: h(edit.nome)}) : t("Novo evento")) + el("form", {"data-recurso": "config", "class": "form-evento"}, formEvento)));
    // ---------- 3. para onde vão ----------
    // os destinos das mensagens e as agendas têm as páginas deles: aqui, o resumo e o caminho
    var listaAlvos = function (lista, campo, vazio) {
      return lista.length > 0 ? el("ul", {"class": "lista-simples"}, lista.map(function (x) {
        return el("li", {}, h(x.nome) + " " + el("span", {"class": "nota"}, t("({1})", {1: h(x.tipo_nome) + (x[campo] ? "" : t(", desligado"))})));
      }).join("")) : el("p", {"class": "nota"}, vazio);
    };
    var alertas = cartao(t("Para onde vão: destinos e agendas"), el("div", {"class": "campos"},
      el("h3", {}, el("a", {"href": "destinos.php"}, t("Destinos das mensagens"))) + listaAlvos(dg.destinos, "ativo", t("Nenhum ainda: as mensagens da manhã, da noite e os avisos não saem para lugar nenhum."))
      + el("h3", {}, el("a", {"href": "agendas.php"}, t("Agendas"))) + listaAlvos(dg.agendas, "ativa", t("Nenhuma ainda."))),
      el("p", {"class": "nota"}, t("Um destino é um webhook (Telegram, n8n...), um e-mail ou o Web Push do navegador; uma agenda é o Google, um CalDAV (Nextcloud, iCloud, Fastmail), o Outlook ou um link ICS para assinar em qualquer app. Cada um escolhe o que recebe e tem a sua mensagem.")));
    res += el("div", {"id": "para-onde"}, alertas);
    document.getElementById("avisos").innerHTML = res;
    mostrarRepeticao();
  });
}

document.addEventListener("change", function (ev) {
  if (ev.target.matches("#ev-repeticao")) {
    mostrarRepeticao();
  }
});

// gravou: a parte volta com o recado, e a edição acaba; recusado: o que foi digitado fica, e os erros aparecem no topo
aoGravar = function (form, res, dados) {
  if (!res.ok) { return salvarSemMover(form, res, function () {}); }
  recado = "";
  var parte = dados.get("recurso") === "avisos" ? "avisos-relogios" : "lembretes";
  history.replaceState(null, "", "avisos.php#" + parte);
  return salvarSemMover(form, res, recarregarAvisos);
};

recarregarAvisos().then(function () {
  if (window.location.hash) {
    var alvo = document.querySelector(window.location.hash);
    if (alvo) {
      alvo.scrollIntoView();
    }
  }
}).catch(function (e) {
  document.getElementById("avisos").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler os avisos: {1}", {1: h(e.message)}));
});
