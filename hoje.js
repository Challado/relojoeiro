// Página Hoje: o relógio do dia, os avisos de hoje, os próximos dias, o modo de rodízio e a tabela dos relógios, com os
// dados da API (recurso=hoje), no HTML do sistema antigo. Remontada depois de cada gravação, sem recarregar a página.
var DIAS_SEMANA = ["", t("Segunda"), t("Terça"), t("Quarta"), t("Quinta"), t("Sexta"), t("Sábado"), t("Domingo")];
var SELECOES = {
  inteligente: [t("Inteligente"), t("Vai o relógio com a maior nota nos critérios. Previsível: com os mesmos dados, a mesma escolha.")],
  ponderado: [t("Inteligente com sorteio"), t("Sorteio em que a nota de cada relógio é a chance dele: quem tem nota maior sai mais vezes, mas todos têm chance. Variedade sem perder o critério.")],
  aleatorio: [t("Totalmente aleatório"), t("Todos os relógios do tipo têm a mesma chance, sem olhar os critérios. Os números vêm do RANDOM.ORG (ruído atmosférico); fora do ar, do ANU QRNG (quântico); sem os dois, do gerador seguro do PHP.")],
  fifo: [t("Fila (FIFO)"), t("Vai o que está há mais tempo sem uso: todos passam pelo pulso, em fila. Não olha os critérios, e já garante o rodízio por natureza.")],
  menos_usado: [t("Menos usado"), t("Vai o que tem menos dias de uso no total (empate: o mais tempo parado): todos passam pelo pulso, dos menos aos mais usados. Não olha os critérios.")]
};

// como a escala escolhe o relógio de cada dia (a forma do modo; vazia ou a maior nota: a maior nota)
function formaDaEscala(forma) {
  return forma === "ponderado" ? t("pelo sorteio pela nota") : (forma === "menos_usado" ? t("pelo menos usado") : (forma === "fifo" ? t("pela fila") : t("pela maior nota")));
}

// o dia da semana (1 a 7) de uma data AAAA-MM-DD
function diaDaSemana(data) {
  return new Date(instante(data)).getDay() || 7;
}

// o recado de uma gravação que não é de um relógio: no topo do meio da página
function mostrarRecado(texto) {
  var p = document.getElementById("recado-hoje");
  p.textContent = texto;
  p.hidden = texto.trim() === "";
}

// a página dos Próximos dias que está à mostra (fica a mesma quando a página Hoje se remonta depois de uma gravação)
var paginaProximos = 1;
var dadosHoje = null;

// Os Próximos dias de uma página (px: o "proximos" da API, já com os dias livres no meio), com o navegador embaixo
function proximosHtml(px) {
  return el("table", {"class": "relogios"}, el("thead", {}, el("tr", {}, el("th", {}, t("Dia")) + el("th", {}, t("Relógio")) + el("th", {}, t("Lembrete")) + el("th", {}, "")))
    + el("tbody", {}, px.dias.map(function (p) {
      return el("tr", {"class": p.dia_semana >= 6 ? "fds" : ""}, el("td", {}, dataBr(p.data, true) + " " + el("small", {}, p.dia_semana_curto))
        + el("td", {}, celulaDoDia(p)) + el("td", {}, h(p.acao || ""))
        + el("td", {"class": "trocar"}, formTrocarDia(p, dadosHoje.relogios, dadosHoje.data, dadosHoje.trocar_hoje)));
    }).join("") + (px.total === 0 ? el("tr", {}, el("td", {"colspan": "4"}, t("Neste modo o sorteio é feito dia a dia."))) : "")))
    + navPaginas(px.pagina, px.paginas, "proximos-pagina")
    + (px.paginas > 1 ? el("p", {"class": "nota"}, t("Página {1} de {2} · {3} dias no plano, {4} por página (o \"Mostrar até\" da {5}).", {1: px.pagina, 2: px.paginas, 3: px.total, 4: px.por_pagina, 5: el("a", {"href": "configuracao.php"}, t("Configuração"))})) : "");
}

// a troca de página dos Próximos dias lê só eles (recurso=proximos)
document.addEventListener("click", function (ev) {
  var b = ev.target.closest("[data-proximos-pagina]");
  if (b && !b.disabled) {
    paginaProximos = parseInt(b.getAttribute("data-proximos-pagina"), 10);
    api({recurso: "proximos", pagina: paginaProximos}).then(function (px) {
      paginaProximos = px.pagina;
      porHtml(document.getElementById("proximos-lista"), proximosHtml(px));
    }).catch(function (e) {
      mostrarRecado(t("Não consegui ler os próximos dias: {1}", {1: e.message}));
    });
  }
});

// as opções de um filtro da tabela ("Todos" e as que existem), com o que estava escolhido continuando escolhido (a página se
// remonta depois de cada gravação e sozinha, a cada minuto). todos: o texto do "Todos" (padrão "Todos"; a marca, "Todas")
function opcoesDoFiltro(nome, itens, todos) {
  var sel = document.querySelector("[data-filtro=" + nome + "]");
  var antes = sel.value;
  sel.innerHTML = el("option", {"value": ""}, todos || t("Todos")) + itens.map(function (item) { return el("option", {}, h(item)); }).join("");
  if (antes !== "" && itens.indexOf(antes) >= 0) {
    sel.value = antes;
  }
}

// lê a página inteira da API e monta os blocos
// As colunas da tabela dos relógios que a API entregou na última leitura (o painel Colunas monta a lista delas ao abrir)
var colunasHoje = null;
// O painel Colunas: a lista das colunas, com a caixa de marcar e os botões ▲ ▼, e o Salvar (grava a escolha desta coleção: a opção
// colunas_hoje, pela configuração) e o Herdar (esvazia a escolha: valem as do usuário ou do sistema)
function montarPainelColunas() {
  var painel = document.getElementById("painel-colunas");
  if (!painel || !colunasHoje) {
    return;
  }
  var visiveis = colunasHoje.lista.filter(function (c) { return c.visivel; }).map(function (c) { return c.chave; });
  porHtml(painel, el("form", {"data-recurso": "config"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
    + el("p", {"class": "nota"}, t("Marque as colunas da tabela e ponha na ordem com ▲ e ▼. A escolha vale para esta coleção."))
    + listaOrdenavel({nome: "colunas_hoje", itens: colunasHoje.lista.map(function (c) { return {valor: c.chave, texto: c.titulo}; }),
      fixos: colunasHoje.lista.filter(function (c) { return c.fixa; }).map(function (c) { return c.chave; }),
      escolha: visiveis.join(","), herdado: colunasHoje.herdado, origem: colunasHoje.escolha === "" ? colunasHoje.origem : ""})
    + el("div", {"class": "acoes"}, el("button", {}, t("Salvar colunas")) + el("button", {"type": "button", "class": "leve", "data-colunas-fechar": true}, t("Fechar")))
    + el("p", {"class": "nota"}, el("a", {"href": "cadastros.php?aba=colunas", "data-criar-colunas": true}, t("Criar ou editar colunas (campos do banco, funções, formato)")))));
}
document.addEventListener("click", function (ev) {
  var painel = document.getElementById("painel-colunas");
  var abrir = document.getElementById("colunas-abrir");
  if (!painel || !abrir || !ev.target.closest) {
    return;
  }
  if (ev.target.closest("#colunas-abrir")) {
    var abre = painel.hidden;
    if (abre) {
      montarPainelColunas();
    }
    painel.hidden = !abre;
    abrir.setAttribute("aria-expanded", abre ? "true" : "false");
  } else if (ev.target.closest("[data-colunas-fechar]")) {
    painel.hidden = true;
    abrir.setAttribute("aria-expanded", "false");
  }
});
// gravou: o painel fecha (a página remonta a tabela com as colunas novas)
document.addEventListener("submit", function (ev) {
  var painel = document.getElementById("painel-colunas");
  if (painel && ev.target.closest && ev.target.closest("#painel-colunas")) {
    painel.hidden = true;
    var abrir = document.getElementById("colunas-abrir");
    if (abrir) {
      abrir.setAttribute("aria-expanded", "false");
    }
  }
});

function recarregarHoje() {
  return api({recurso: "hoje", proximos_pagina: paginaProximos}).then(function (d) {
    ajudaGrupo("pagina", t("Hoje"));
    dadosHoje = d;
    var rodizioAtivo = !d.rodizio || d.rodizio.ativo;
    paginaProximos = d.proximos.pagina;
    // o quadro "Hoje": o relógio do dia, até quando, o tipo, o lembrete, e ao lado as informações dele e a foto
    // o relógio do destaque (a Configuração: o do dia ou o que está no pulso agora): a API diz qual é
    var item = null;
    d.relogios.forEach(function (x) {
      if (x.destaque) {
        item = x;
      }
    });
    var dz = d.destaque;
    var texto = el("p", {"class": "data"}, DIAS_SEMANA[diaDaSemana(d.data)] + ", " + dataBr(d.data) + " · " + h(rodizioAtivo ? (d.modo ? d.modo.nome : t("sem modo")) : t("Rodízio desligado")));
    if (item && dz.e === "pulso") {
      // no pulso agora, e não é o do dia: o nome dele, "no pulso agora", e embaixo o relógio do dia
      texto += el("h1", {}, el("a", {"href": "index.php?r=" + item.id, "data-abrir": item.id}, h(item.nome)))
        + el("p", {"class": "periodo"}, el("span", {"class": "janela"}, t("no pulso agora")) + " " + h(item.tipo))
        + el("p", {"class": "acao"}, d.dia ? t("Relógio do dia: {1} ({2})", {1: el("a", {"href": "index.php?r=" + d.dia.relogio_id, "data-abrir": d.dia.relogio_id}, h(d.dia.relogio)), 2: h(d.dia.ate)})
          : t("Hoje não tem relógio do dia."));
    } else if (d.dia) {
      texto += el("h1", {}, el("a", {"href": "index.php?r=" + d.dia.relogio_id, "data-abrir": d.dia.relogio_id}, h(d.dia.relogio)))
        + el("p", {"class": "periodo"}, el("span", {"class": "janela"}, h(d.dia.ate)) + " " + h(item && item.tipo !== "—" ? item.tipo : ""))
        + (d.dia.acao ? el("p", {"class": "acao"}, h(d.dia.acao)) : "")
        + (d.dia.motivo ? el("p", {"class": "motivo"}, el("strong", {}, t("Por que ele: ")) + h(d.dia.motivo)) : "")
        // sem relógio hoje: o dia fica livre e a sessão do rodízio termina agora (só com a troca no próprio dia ligada)
        + (!d.trocar_hoje ? "" : el("form", {"data-recurso": "rodizio", "class": "sem-relogio", "onsubmit": "return confirm(" + JSON.stringify(t("Hoje sem relógio? O dia fica livre: o {1} sai do rodízio de hoje (se já está no pulso pelo rodízio, sai agora; o tempo que ficou conta). Os outros dias não mudam.", {1: d.dia.relogio})) + ")"},
          el("input", {"type": "hidden", "name": "acao", "value": "sem_relogio"}) + el("button", {"class": "leve"}, t("Sem relógio hoje"))));
    } else if (!rodizioAtivo) {
      texto += el("h1", {}, t("Rodízio desligado")) + el("p", {}, t("O plano está pausado. As marcações manuais continuam disponíveis."))
        + el("a", {"href": "configuracao.php"}, t("Configuração"));
    } else if (d.hoje_livre) {
      // o dia livre: escolher um relógio para hoje (o mesmo "escolher…" dos dias livres), ou deixar assim
      texto += el("h1", {}, t("Hoje sem relógio"))
        + el("p", {"class": "periodo"}, el("span", {"class": "janela"}, t("dia livre")))
        + el("p", {}, d.trocar_hoje ? t("Nenhum relógio vai ao pulso pelo rodízio hoje. Quer usar um? Escolha abaixo, ou sorteie de novo (inclusive hoje) no quadro do modo de rodízio.")
          : t("Nenhum relógio vai ao pulso pelo rodízio hoje. O relógio de hoje não se troca no próprio dia (Configuração): escolha o de amanhã nos Próximos dias."))
        + formTrocarDia({data: d.data, relogio_id: null, origem: "livre"}, d.relogios, d.data, d.trocar_hoje);
    } else {
      texto += el("h1", {}, t("Nenhum relógio para hoje"))
        + el("p", {}, t("Nenhum relógio disponível no grupo deste dia. Mude o grupo no modo de rodízio ou marque um relógio como disponível."));
    }
    var res = el("div", {"class": "hoje-texto"}, texto);
    if (item) {
      // o Pôs e o Tirou do relógio do dia (as marcações mandam, com ou sem o pulso sozinho da Configuração)
      var marcar = " " + el("form", {"data-recurso": "lancamento", "class": "marcar-pulso"},
        el("input", {"type": "hidden", "name": "acao", "value": item.em_uso ? "encerrar" : "iniciar"}) + el("input", {"type": "hidden", "name": "tipo", "value": "pulso"})
        + el("input", {"type": "hidden", "name": "relogio_id", "value": item.id}) + el("input", {"type": "hidden", "name": "r", "value": item.id})
        + el("button", {"class": "leve"}, item.em_uso ? t("Tirou do pulso") : t("Pôs no pulso")));
      var info = el("dt", {}, t("Agora")) + el("dd", {}, el("span", {"class": "estado estado-" + (item.estado)}, h(item.agora)) + marcar)
        + el("dt", {}, t("Carga")) + el("dd", {}, item.carga !== null
          ? el("span", {"class": "carga-linha" + (item.carga <= 20 ? " baixa" : "")}, el("span", {"class": "barra"}, el("span", {"style": "width: " + (item.carga) + "%"}, "")) + " " + el("strong", {}, item.carga + "%"))
            + " " + el("small", {}, h(item.carga_de))
          : h(item.carga_de));
      if (item.leitura) {
        info += el("dt", {}, t("Última leitura")) + el("dd", {}, num(item.leitura.valor, 1) + h(item.leitura.unidade) + t(" em ") + dataBr(item.leitura.inicio, true) + " " + horaBr(item.leitura.inicio));
      }
      if (item.situacao.length > 0) {
        info += el("dt", {}, t("Situação")) + el("dd", {}, item.situacao.map(function (l) { return el("span", {"class": "linha"}, h(l)); }).join(""));
      }
      info += el("dt", {}, t("Próxima manutenção")) + el("dd", {}, item.manutencao
        ? h(item.manutencao.nome) + " · " + (item.manutencao.data === d.data ? "hoje" : dataBr(item.manutencao.data)) + " "
          + el("span", {"class": "em" + (item.manutencao.momento > 0 && item.manutencao.momento * 1000 <= Date.now() ? " atrasado" : "")}, "(" + h(item.manutencao.falta) + ")")
        : t("nenhuma prevista"));
      if (item.encostado && item.encostado.estado !== "ok") {
        info += el("dt", {}, t("Encostado")) + el("dd", {}, el("span", {"class": "em atrasado"}, h(item.encostado.falta)));
      }
      if (item.compra.valor !== null || item.compra.data !== null) {
        info += el("dt", {}, t("Compra")) + el("dd", {}, (item.compra.valor !== null ? t("R$ ") + reais(item.compra.valor) : "") + (item.compra.loja !== null ? " · " + h(item.compra.loja) : "")
          + (item.compra.data !== null ? " · " + dataBr(item.compra.data) : ""));
      }
      if (item.compra.garantia_ate !== null) {
        info += el("dt", {}, t("Garantia")) + el("dd", {}, t("até {1}{2}", {1: dataBr(item.compra.garantia_ate), 2: item.compra.garantia_ate < d.data ? t(" (vencida)") : ""}));
      }
      info += el("dt", {}, t("Código")) + el("dd", {}, String(item.id));
      res += el("dl", {"class": "hoje-info"}, info)
        + el("a", {"class": "hoje-foto", "href": "index.php?r=" + item.id, "data-abrir": item.id, "title": t("Abrir o {1} no painel", {1: item.nome})},
          (item.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + item.id + "&v=" + item.foto, "alt": t("Foto do {1}", {1: item.nome})}) : el("span", {"class": "sem-foto"}, t("Sem foto")))
          // embaixo da foto, a tabelinha do destaque: Do dia | No pulso, acesa a casa que vale (as duas, se for o relógio do
          // dia no pulso), e parando o mouse a frase do porquê
          + el("table", {"class": "destaque-tab", "title": dz.texto}, el("tbody", {}, el("tr", {},
            el("td", {"class": dz.do_dia ? "sim dia" : ""}, (dz.do_dia ? el("span", {"class": "marca", "aria-hidden": "true"}, "✓") : "") + h(t("Do dia")))
            + el("td", {"class": dz.no_pulso ? "sim pulso" : ""}, (dz.no_pulso ? el("span", {"class": "marca", "aria-hidden": "true"}, "✓") : "") + h(t("No pulso")))))));
    }
    var quadro = document.getElementById("bloco-hoje");
    quadro.classList.toggle("com-relogio", item !== null);
    porHtml(quadro, res);

    // "Hoje é dia de": uma tabela, como a dos próximos dias; cada aviso com o relógio (abre o painel), o motivo e o botão do
    // lançamento que o resolve, como a API diz (leitura: com o valor; sessão: "Pôs ...", ou "Tirou ..." se ela já está aberta;
    // o winder, o link para a página Winders)
    var res = "";
    if (d.avisos.length > 0) {
      res = el("section", {"class": "avisos"}, el("h2", {}, t("Hoje é dia de")) + el("table", {"class": "relogios"}, el("thead", {}, el("tr", {}, el("th", {}, t("O que fazer"))
        + el("th", {}, t("Relógio")) + el("th", {}, t("Por quê")) + el("th", {}, ""))) + el("tbody", {}, d.avisos.map(function (a) {
        var botao = "";
        if (a.resolve && a.resolve.acao === "winders") {
          // pôr no winder: com winder cadastrado, só pela página Winders (o link, não um botão que grava)
          botao = el("a", {"class": "botao leve", "href": "winders.php"}, h(a.resolve.botao));
        } else if (a.resolve) {
          botao = el("form", {"data-recurso": "lancamento"}, el("input", {"type": "hidden", "name": "acao", "value": a.resolve.acao}) + el("input", {"type": "hidden", "name": "tipo", "value": a.resolve.identificador})
            + el("input", {"type": "hidden", "name": "relogio_id", "value": a.relogio_id}) + el("input", {"type": "hidden", "name": "r", "value": a.relogio_id})
            + (a.resolve.formato === "valor" ? el("input", {"type": "text", "inputmode": "decimal", "name": "valor", "placeholder": a.resolve.unidade, "required": true, "aria-label": a.resolve.nome}) : "")
            // a leitura que mede o gasto, num relógio que vai ao pulso: dois botões, como ele estava na hora da leitura
            + (a.resolve.estados ? el("button", {"class": "leve", "name": "estado", "value": "uso"}, t("Em uso")) + " " + el("button", {"class": "leve", "name": "estado", "value": "repouso"}, t("Em repouso"))
              : el("button", {"class": "leve"}, h(a.resolve.botao))));
        }
        return el("tr", {"class": "aviso-" + a.identificador + (a.estado === "atrasado" ? " aviso-atrasado" : "")}, el("td", {}, el("span", {}, h(a.nome)))
          + el("td", {}, el("a", {"href": "index.php?r=" + a.relogio_id, "data-abrir": a.relogio_id}, h(a.relogio))) + el("td", {}, el("span", {}, h(a.texto)))
          + el("td", {"class": "botao-aviso"}, botao));
      }).join(""))));
    }
    porHtml(document.getElementById("bloco-avisos"), res);

    // "Próximos dias": o plano, com o lembrete de cada dia e, embaixo do relógio, o motivo da escolha
    // cada dia com o "trocar por…" (o relógio escolhido à mão fica marcado), e o link para o plano inteiro
    // de "Mostrar até" em "Mostrar até" dias (a Configuração), com as páginas embaixo
    porHtml(document.getElementById("bloco-proximos"), el("h2", {}, t("Próximos dias {1}", {1: el("a", {"class": "ver-plano", "href": "plano.php"}, t("ver o plano inteiro →"))}))
      + el("div", {"id": "proximos-lista"}, proximosHtml(d.proximos)));

    // O quadro "Modo de rodízio": fechado mostra o modo atual; aberto, escolhe o modo (Aplicar só marca), o critério de
    // escolha e a garantia de rodízio, e sorteia de novo (a partir de amanhã, inclusive hoje ou a
    // próxima semana: todas ficam na lista, e a que não vale agora fica desabilitada, com o motivo). Os blocos, as semanas e o roteiro se editam nos Modos de rodízio
    var modo = d.modo;
    var escala = modo !== null && modo.escala_dias !== null;
    // o relógio de hoje, para os textos do Sortear de novo; sem plano para hoje, o texto diz só que hoje fica como está
    var nomeHoje = d.dia ? d.dia.relogio : "";
    var selModos = {};
    var tiposModo = {};
    d.modos.forEach(function (m) {
      selModos[m.id] = m.selecao;
      tiposModo[m.id] = m.tipo;
    });
    // a semana do rodízio começa no dia da Configuração (semana_inicio): a segunda ou o domingo
    var semIni = d.semana_inicio === "domingo" ? t("domingo") : t("segunda");
    var semFim = d.semana_inicio === "domingo" ? t("sábado") : t("domingo");
    var EXPLICA_TIPO = {
      semana: t("Um relógio para a semana inteira, de {1} a {2}, escolhido pelo critério.", {1: semIni, 2: semFim}),
      semana_fds: t("Dois trechos na semana: segunda a sexta e o fim de semana, cada um com o seu grupo, um relógio para o trecho ou um por dia."),
      dia_semana: t("Um relógio por dia, e cada dia da semana diz de onde ele sai."),
      aleatorio: t("Um relógio por dia, todos com a mesma chance, sem critério nem garantia de rodízio."),
      escala: t("Monta o plano do período inteiro de uma vez, simulando a carga de cada relógio dia a dia, com os lembretes de carregar, dar corda e pôr no sol. É refeita toda manhã a partir do estado real."),
      personalizado: t("O roteiro escrito à mão decide quem é candidato em cada dia, a nota, o peso, os dias seguidos e a garantia.")
    };
    // o "Rodízio manual" no fim da lista: não é um modo; escolhe o relógio de cada dia de um período (o modo de sempre continua)
    var ladoModo = el("label", {"class": "campo-principal"}, t("Modo de rodízio {1}", {1: el("select", {"name": "modo"}, d.modos.map(function (m) {
      return el("option", {"value": m.id, "selected": modo !== null && m.id === modo.id}, h(m.nome));
    }).join("") + el("option", {"value": "manual"}, t("Rodízio manual")))}));
    ladoModo += el("p", {"class": "nota explicacao", "data-explica-modo": "manual"}, t("Você escolhe o relógio de cada dia de um período. Ponha o começo e o fim e clique em OK: os dias aparecem de 7 em 7, cada um com a lista dos relógios, \"Decida por mim\" (o sistema escolhe), \"Sortear este dia por\" (uma forma de escolha) e \"Sem relógio (dia livre)\". Fora do período, continua valendo o modo de rodízio de sempre ({1}).", {1: h(modo ? modo.nome : t("nenhum"))}));
    d.modos.forEach(function (m) {
      var horizonte = m.escala_dias !== null ? t("período de {1} dias", {1: m.escala_dias}) : (m.semanas === 1 ? t("esta semana") : t("{1} semanas à frente", {1: m.semanas}));
      ladoModo += el("p", {"class": "nota explicacao", "data-explica-modo": m.id}, el("strong", {}, h(m.tipo_texto)) + " · " + h(horizonte) + (m.ciclo ? t(" · com ciclo") : "") + ". " + h(EXPLICA_TIPO[m.tipo] || "")
        + " " + el("a", {"href": "modos.php?editar=" + m.id + "#form"}, t("editar o modo")));
    });
    var ladoSelecao = el("label", {"class": "campo-principal", "data-com-selecao": true}, t("Como escolher o relógio {1}", {1: el("select", {"name": "selecao"}, Object.keys(SELECOES).map(function (k) {
      return el("option", {"value": k, "selected": modo !== null && modo.selecao === k}, h(SELECOES[k][0]));
    }).join(""))}))
      + el("p", {"class": "campo-principal", "data-sem-selecao": true}, t("Como escolher o relógio: {1}", {1: el("strong", {"data-sem-selecao-texto": true}, formaDaEscala(modo !== null ? modo.selecao : ""))}))
      + Object.keys(SELECOES).map(function (k) { return el("p", {"class": "nota explicacao", "data-explica-selecao": k}, h(SELECOES[k][1])); }).join("")
      + el("p", {"class": "nota explicacao", "data-sem-selecao": true, "data-explica-tipo": "escala"}, t("A escala escolhe pela forma do modo (a maior nota, o sorteio pela nota, o menos usado ou a fila), com as medidas de cada dia simulado; a forma e os critérios que contam na nota se escolhem nos Modos de rodízio."))
      + el("p", {"class": "nota explicacao", "data-sem-selecao": true, "data-explica-tipo": "aleatorio"}, h(SELECOES.aleatorio[1]))
      + el("p", {"class": "nota explicacao", "data-sem-selecao": true, "data-explica-tipo": "personalizado"}, t("O roteiro do modo diz a nota e o peso de cada relógio; mude-o nos Modos de rodízio."))
      + el("div", {"data-garantia": true}, el("label", {"class": "campo-principal"}, t("Garantia de rodízio: dias sem uso, no máximo {1}", {1: el("input", {"type": "number", "name": "max_sem_uso", "min": "0", "max": "365", "value": d.max_sem_uso, "class": "curto"})}))
        + ajudaNota("garantia", t("A garantia de rodízio"), t("Quem passa do limite tem prioridade, o mais tempo parado primeiro. 0 desliga. O mesmo valor vale para todos os modos que usam a nota."))
        + el("p", {"class": "nota"}, t("A nota vem dos {1}, que você cadastra.", {1: el("a", {"href": "criterios.php"}, t("critérios"))})));
    var formModo = el("form", {"data-recurso": "rodizio"},
      el("input", {"type": "hidden", "name": "acao", "value": "modo"})
      + el("div", {"class": "modo-grade", "data-selecoes": JSON.stringify(selModos), "data-tipos": JSON.stringify(tiposModo)}, el("div", {"class": "modo-lado"}, ladoModo) + el("div", {"class": "modo-lado", "data-nao-manual": true}, ladoSelecao))
      + el("button", {"data-nao-manual": true}, t("Aplicar modo"))
      + el("p", {"class": "nota", "data-nao-manual": true}, t("Aplicar só marca o modo (e grava o critério e a garantia): o plano gravado continua como está, e o modo vale para os próximos sorteios. Para refazer o plano com ele, use \"Sortear de novo\", embaixo. Os modos, as semanas e o roteiro se cadastram em {1}.", {1: el("a", {"href": "modos.php"}, t("Modos de rodízio"))})));
    // sortear de novo: as ações possíveis agora, com a explicação e a confirmação de cada uma
    var ateQuando = escala ? t("o fim da escala") : (d.proxima_semana && d.proxima_semana.ja_montada ? t("o fim da próxima semana") : semFim);
    // Todas as opções ficam na lista; a que não vale agora fica desabilitada, com o motivo no próprio texto (e no title): [valor, texto,
    // explicação, confirmação, motivo ("" quando vale agora)]
    var semModo = t("sem modo de rodízio ativo");
    var desligado = t("desligado na Configuração (Trocar o relógio de hoje no próprio dia)");
    var sorteios = [];
    var hojeJa = d.comecou;
    sorteios.push(["resortear", t("a partir de hoje"), t("Refaz o plano de hoje até {1} pelo modo atual (o dia ainda não começou no pulso).", {1: ateQuando}), t("Sortear de novo a partir de hoje?"),
      hojeJa ? t("o dia de hoje já começou no pulso") : (!d.trocar_hoje ? desligado : "")]);
    sorteios.push(["resortear", t("a partir de amanhã"), nomeHoje !== "" ? t("Refaz o plano de amanhã até {1} pelo modo atual; hoje continua o {2}.", {1: ateQuando, 2: nomeHoje}) : t("Refaz o plano de amanhã até {1} pelo modo atual; hoje fica como está.", {1: ateQuando}),
      nomeHoje !== "" ? t("Sortear de novo a partir de amanhã? Hoje continua o {1}.", {1: nomeHoje}) : t("Sortear de novo a partir de amanhã? Hoje fica como está."),
      hojeJa || !d.trocar_hoje ? "" : t("o dia de hoje ainda não começou: \"a partir de hoje\" já refaz também amanhã")]);
    sorteios.push(["resortear_hoje", t("inclusive hoje"), nomeHoje !== "" ? t("Refaz também hoje: se sair outro relógio, ele passa a ser o do pulso a partir de agora, e o {1} sai.", {1: nomeHoje}) : t("Refaz também hoje: o relógio que sair passa a ser o do pulso a partir de agora."),
      nomeHoje !== "" ? t("Sortear de novo inclusive hoje? Se sair outro relógio para hoje, ele passa a ser o do pulso a partir de agora, e o {1} sai.", {1: nomeHoje}) : t("Sortear de novo inclusive hoje? O relógio que sair para hoje passa a ser o do pulso a partir de agora."),
      !d.trocar_hoje ? desligado : (hojeJa ? "" : t("o dia de hoje ainda não começou: use \"a partir de hoje\""))]);
    var ps = d.proxima_semana;
    // a próxima semana vale em qualquer modo fora da escala (que já planeja o próprio período)
    var temSemana = !escala && modo !== null;
    sorteios.push(["proxima_semana", t("a próxima semana ({1} a {2})", {1: dataBr(ps.inicio, true), 2: dataBr(ps.fim, true)}),
      t("{1} a semana seguinte inteira, de {2} a {3}, pelo modo atual, com os dias que ainda faltam desta semana já contados como uso. Os dias escolhidos à mão ficam.", {1: ps.ja_montada ? t("Refaz") : t("Monta"), 2: semIni, 3: semFim}),
      t("{1} a semana de {2} a {3}?{4}", {1: ps.ja_montada ? t("Sortear de novo") : t("Montar"), 2: dataBr(ps.inicio, true), 3: dataBr(ps.fim, true), 4: ps.ja_montada ? t(" O que já estava montado para ela é refeito.") : ""}),
      temSemana ? "" : (modo === null ? semModo : t("a escala já planeja o próprio período"))]);
    var primeira = sorteios.filter(function (x) { return x[4] === ""; })[0];
    // "Sobrescrever tudo": sai tudo o que o plano tem do começo do sorteio em diante (à mão, livre ou sorteado); a confirmação avisa
    var avisoSobrescrever = t(" Com Sobrescrever tudo marcado, sai tudo o que o plano tem desse dia em diante, inclusive os dias escolhidos à mão e os dias livres, e o modo sorteia de novo.");
    var formSorteio = el("form", {"data-recurso": "rodizio", "class": "sortear-de-novo", "data-nao-manual": true, "data-aviso-sobrescrever": avisoSobrescrever,
      "onsubmit": "var o = this.acao.options[this.acao.selectedIndex]; return confirm(o.getAttribute('data-confirma') + (this.sobrescrever.checked ? this.getAttribute('data-aviso-sobrescrever') : ''))"},
      el("label", {"class": "campo-principal", "for": "acao-sorteio"}, t("Sortear de novo"))
      + el("div", {"class": "linha-sorteio"}, el("select", {"name": "acao", "id": "acao-sorteio"}, sorteios.map(function (s) {
        return s[4] === "" ? el("option", {"value": s[0], "data-explica": s[2], "data-confirma": s[3]}, h(s[1]))
          : el("option", {"value": s[0], "disabled": true, "title": s[4], "data-explica": s[4], "data-confirma": ""}, h(t("{1} — indisponível: {2}", {1: s[1], 2: s[4]})));
      }).join("")) + el("button", {"class": "leve"}, t("Sortear")))
      + el("p", {"class": "nota", "data-explica-sorteio": true}, h(primeira[2]))
      + el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "sobrescrever", "value": "1"}) + " " + t("Sobrescrever tudo"))
      + ajudaNota("apagar-sorteio", t("Apagar ao sortear de novo"), t("Marcada, sai tudo o que o plano tem do começo do sorteio em diante: os dias escolhidos à mão, os dias livres e os sorteados; depois o modo monta tudo de novo. Desmarcada, os dias à mão e os livres ficam como estão.")));
    // remontado, o quadro volta fechado, como no sistema antigo depois de aplicar
    // o rodízio manual: o período e, depois do OK, os dias dele de 7 em 7 (manualCarregar)
    var formManual = el("form", {"class": "rodizio-manual", "id": "form-manual", "data-so-manual": true, "hidden": true},
      el("div", {"class": "manual-periodo"}, el("label", {}, t("De {1}", {1: el("input", {"type": "date", "name": "de", "min": d.data, "value": d.data, "required": true})}))
        + el("label", {}, t("Até {1}", {1: el("input", {"type": "date", "name": "ate", "min": d.data, "required": true})}))
        + el("button", {"type": "button", "id": "manual-ok"}, t("OK")))
      + el("p", {"class": "acao", "id": "manual-recado", "hidden": true}, "")
      + el("div", {"id": "manual-dias"}, ""));
    porHtml(document.getElementById("bloco-modo"), el("details", {}, el("summary", {}, el("span", {"class": "rotulo"}, t("Modo de rodízio")) + " " + el("strong", {}, h(modo ? modo.nome : t("nenhum"))) + " "
      + el("span", {"class": "nota"}, "· " + (modo ? h(modo.tipo_texto) + " · " : "") + (escala ? formaDaEscala(modo.selecao) : (modo && modo.tipo === "personalizado" ? t("pelo roteiro") : h(modo ? SELECOES[modo.selecao][0] : "")))) + " " + el("span", {"class": "editar"}, t("editar"))) + formModo + formManual + formSorteio + formApagarPeriodo(d.data) + formApagarTudo(d.trocar_hoje)));
    mostrarModo();
    document.getElementById("bloco-modo").hidden = !rodizioAtivo;
    document.getElementById("bloco-proximos").hidden = !rodizioAtivo;

    // A tabela dos relógios: disponíveis primeiro (o de hoje, depois os com aviso), indisponíveis no fim; na tela, a ordem e os
    // filtros escolhidos no cabeçalho valem por cima desta (tabela.js)
    var sel = parseInt(new URLSearchParams(window.location.search).get("r") || "0", 10);
    var ordem = function (x) {
      return (x.disponivel ? 10 : 0) + (x.de_hoje ? 2 : 0) + (x.com_aviso ? 1 : 0);
    };
    var lista = d.relogios.slice().sort(function (a, b) { return ordem(b) - ordem(a); });
    // os filtros de tipo e do que fazer, com as opções que existem
    var tipos = [];
    lista.forEach(function (x) {
      if (tipos.indexOf(x.tipo) < 0) {
        tipos.push(x.tipo);
      }
    });
    tipos.sort();
    opcoesDoFiltro("tipo", tipos);
    // o filtro da marca: as marcas que os relógios têm (e "sem marca", quando algum não tem)
    var marcas = [];
    lista.forEach(function (x) {
      if (x.marca !== null && marcas.indexOf(x.marca) < 0) {
        marcas.push(x.marca);
      }
    });
    marcas.sort(function (a, b) { return a.localeCompare(b); });
    opcoesDoFiltro("marca", marcas.concat(lista.some(function (x) { return x.marca === null; }) ? [t("(sem marca)")] : []), t("Todas"));
    opcoesDoFiltro("estado", d.estados);
    // o Encostado desligado na Configuração: a coluna e o filtro somem (estilo.css, .sem-encostado; tabela.js ignora o filtro)
    document.getElementById("tabela-relogios").classList.toggle("sem-encostado", !d.encostado_ativo);
    opcoesDoFiltro("fazer", d.avisos_nomes);
    var ano = String(new Date().getFullYear());
    // as colunas cadastradas que aparecem, para as células das linhas (a ordem delas fica com colunasAplicar)
    var colunasCadastradas = (d.colunas || []).filter(function (c) { return c.cadastrada && c.visivel; });
    var linhas = lista.map(function (x) {
      var m = x.manutencao;
      var e = x.encostado;
      var atrasado = m && m.momento > 0 && m.momento * 1000 <= Date.now();
      var encAtrasado = e && e.estado !== "ok";
      // as colunas cadastradas (Cadastros › Colunas) que aparecem: o valor cru no tr (a ordenação) e a célula com o texto já formatado
      var attrsCad = {};
      var celulasCad = "";
      colunasCadastradas.forEach(function (c) {
        var ex = (x.colunas_extras || {})[c.chave];
        attrsCad["data-" + c.chave] = ex && ex.valor !== null && ex.valor !== undefined ? ex.valor : "";
        var texto = ex ? h(ex.texto) : "—";
        celulasCad += el("td", {"data-col": c.chave, "data-rotulo": c.titulo, "class": c.numero ? "col-num" : ""}, c.formato === "duracao" || c.formato === "moeda" ? texto.replace(/ /g, "&nbsp;") : texto);
      });
      var attrsLinha = {"data-id": x.id, "data-aba": x.aba, "tabindex": "0", "data-codigo": x.id, "data-nome": x.nome, "data-marca": x.marca === null ? "" : x.marca, "data-tipo": x.tipo, "data-estado": x.estado_ordem, "data-estado-txt": x.estado_texto,
        "data-carga": x.carga === null ? "" : x.carga, "data-ultimo": x.ultimo, "data-uso": x.uso, "data-manut": m ? m.data : "", "data-em": m && m.momento > 0 ? m.momento : "", "data-fazer": m ? m.nome : "",
        "data-encostado": e && e.momento > 0 ? e.momento : "", "data-encostado-estado": !e ? "" : (e.estado === "ok" ? "prazo" : "sim"),
        "data-compra": x.compra.data || "", "data-valor": x.compra.valor === null ? "" : x.compra.valor,
        "class": ((x.de_hoje ? "de-hoje " : "") + (x.com_aviso ? "pendente " : "") + (x.disponivel ? "" : "indisponivel ") + (x.id === sel ? "selecionado" : "")).trim()};
      Object.keys(attrsCad).forEach(function (k) { attrsLinha[k] = attrsCad[k]; });
      return el("tr", attrsLinha,
        el("td", {"data-col": "codigo", "class": "col-codigo", "data-rotulo": t("Código")}, String(x.id))
        + el("td", {"data-col": "nome", "class": "col-nome"}, el("div", {"class": "nome-rel"}, (x.foto !== null ? el("img", {"src": "api.php?recurso=foto&relogio=" + x.id + "&v=" + x.foto, "alt": ""}) : el("span", {"class": "sem-mini"}, ""))
          + el("a", {"href": "index.php?r=" + x.id, "title": x.nome}, h(x.nome))))
        + el("td", {"data-col": "marca", "class": "col-marca", "data-rotulo": t("Marca")}, x.marca !== null ? h(x.marca) : "—")
        + el("td", {"data-col": "tipo", "data-rotulo": t("Tipo")}, h(x.tipo))
        // (no emprestado, com quem está e desde quando: a saída de agora, da API)
        + el("td", {"data-col": "estado", "data-rotulo": t("Estado")}, el("span", {"class": "estado estado-" + (x.estado), "title": x.saida ? saidaResumo(x.saida) : null}, h(x.estado_texto)))
        + el("td", {"data-col": "carga", "data-rotulo": t("Carga")}, x.carga !== null
          ? el("div", {"class": "carga-cel" + (x.carga <= 20 ? " baixa" : ""), "title": x.carga_de}, el("div", {"class": "carga-linha"}, el("span", {"class": "barra"}, el("span", {"style": "width: " + (x.carga) + "%"}, ""))
            + " " + el("strong", {}, x.carga + "%")))
          : el("small", {"class": "sem-carga"}, h(x.carga_de)))
        + el("td", {"data-col": "ultimo", "data-rotulo": t("Última vez usado")}, h(x.ultimo_txt))
        // o tempo de uso total (a API manda o texto pronto: "1d 4h 30m"; sem uso, "—"); as partes não quebram a linha
        + el("td", {"data-col": "uso", "class": "col-uso", "data-rotulo": t("Tempo de uso")}, x.uso > 0 ? h(x.uso_texto).replace(/ /g, "&nbsp;") : "—")
        + el("td", {"data-col": "manut", "data-rotulo": t("Próxima manutenção")}, m ? (m.data === d.data ? "hoje" : dataBr(m.data)) : "—")
        + el("td", {"data-col": "em", "class": "col-em" + (atrasado ? " atrasado" : ""), "data-rotulo": t("Em"), "data-momento": m && m.momento > 0 ? m.momento : null}, m ? h(m.falta).replace(/(\d[a-z]*) (?=\d)/g, "$1&nbsp;") : "—")
        + el("td", {"data-col": "fazer", "class": m ? "fazer" : "", "data-rotulo": t("O que fazer")}, m ? h(m.nome) : "—")
        + el("td", {"data-col": "encostado", "class": "col-encostado" + (encAtrasado ? " atrasado" : ""), "data-rotulo": t("Encostado"), "data-momento": e && e.momento > 0 ? e.momento : null}, e ? h(e.falta).replace(/(\d[a-z]*) (?=\d)/g, "$1&nbsp;") : "—")
        + el("td", {"data-col": "compra", "data-rotulo": t("Comprado em")}, x.compra.data ? dataBr(x.compra.data) : "—")
        + el("td", {"data-col": "valor", "class": "col-valor", "data-rotulo": t("Valor")}, x.compra.valor !== null ? t("R$ ") + reais(x.compra.valor) : "—")
        + celulasCad);
    }).join("");
    // as colunas que valem (o motor de colunas: a opção colunas_hoje; api.js colunasAplicar, pelo tabela.js) e o que o painel Colunas mostra
    colunasHoje = {lista: d.colunas, escolha: d.colunas_escolha, herdado: d.colunas_herdado, origem: d.colunas_origem_herdado};
    window.trocarLinhasDaTabela(linhas + (lista.length === 0 ? el("tr", {}, el("td", {"colspan": "14", "data-colspan-colunas": true}, t("Nenhum relógio cadastrado. Use o botão Novo relógio."))) : ""), d.colunas);
    // as abas por situação (em cima da tabela): a faixa e o que a aba escolhida mostra
    return mostrarAbas(d.abas || []);
  });
}

// As abas por situação. A escolhida fica guardada na sessão do navegador, para a página voltar nela; a aba que é a tabela
// (os relógios que são seus) filtra a tabela de sempre; a dos desejados e as dos que saíram trocam a tabela pela lista delas
// (recurso=desejos e recurso=saidas, pelo abas.js)
var abaAtual = (function () {
  // a do endereço (index.php?aba=vendido: os links de outras páginas) vale e sai dele (o painel do relógio cuida do endereço);
  // sem ela, a última escolhida nesta sessão do navegador
  var p = new URLSearchParams(window.location.search);
  var pedida = p.get("aba");
  if (pedida !== null) {
    p.delete("aba");
    history.replaceState(null, "", "index.php" + (p.toString() !== "" ? "?" + p.toString() : "") + window.location.hash);
    guardarAba(pedida);
    return pedida;
  }
  try {
    return sessionStorage.getItem("relogios-aba") || "";
  } catch (e) {
    // sem sessão disponível: a primeira aba
    return "";
  }
})();
function guardarAba(chave) {
  try {
    sessionStorage.setItem("relogios-aba", chave);
  } catch (e) {
    // sem sessão disponível: só não guarda
  }
}
function mostrarAbas(abas) {
  var faixa = document.getElementById("abas-relogios");
  var outra = document.getElementById("aba-conteudo");
  var tabela = document.getElementById("tabela-relogios");
  var aba = abaEscolhida(abas, abaAtual);
  // uma aba só (a coleção inteira numa situação, com a opção de esconder as vazias; ou a lista com uma situação só): sem faixa, a tabela de sempre
  faixa.hidden = abas.length < 2;
  porHtml(faixa, abas.length < 2 ? "" : abasFaixaHtml(abas, aba.chave, function (chave) { return "index.php?aba=" + encodeURIComponent(chave); }));
  if (!faixa.hidden) {
    abasFaixaLigar(faixa);
  }
  var eTabela = aba === null || aba.tipo === "relogios";
  // o que é só da tabela: ela, as colunas, os filtros e o aviso de tabela vazia
  document.getElementById("rolagem-relogios").hidden = !eTabela;
  ["colunas-abrir", "limpar-filtros"].forEach(function (id) { document.getElementById(id).hidden = !eTabela; });
  if (!eTabela) {
    document.getElementById("painel-colunas").hidden = true;
    document.getElementById("colunas-abrir").setAttribute("aria-expanded", "false");
    tabela.setAttribute("data-fora-da-aba", "1");
  } else {
    tabela.removeAttribute("data-fora-da-aba");
  }
  outra.hidden = eTabela;
  window.filtrarTabelaPorAba(aba !== null && abas.length > 1 ? aba.chave : null);
  if (eTabela) {
    porHtml(outra, "");
    return null;
  }
  document.getElementById("conta-relogios").textContent = aba.quantos === 1 ? t("1 relógio") : t("{1} relógios", {1: aba.quantos});
  var pedido = aba.tipo === "desejos" ? api({recurso: "desejos"}).then(desejosListaHtml)
    : api({recurso: "saidas", situacao: aba.chave, abertas: 1}).then(function (d) { return saidasListaHtml(d, aba); });
  return pedido.then(function (html) {
    // (a aba pode ter mudado enquanto a resposta vinha: só a escolhida escreve)
    if (abaEscolhida(abas, abaAtual) === aba) {
      porHtml(outra, (aba.tipo === "desejos" ? el("p", {"class": "botoes"}, el("a", {"class": "botao", "href": "index.php?novo=1&posse=" + encodeURIComponent(aba.chave)}, t("Novo desejo"))
        + " " + linkRelatorio("desejos", {}, t("Relatório dos desejos"))) : "") + html);
    }
  }).catch(function (e) {
    porHtml(outra, el("p", {"class": "acao"}, t("Não consegui ler os relógios desta aba: {1}", {1: h(e.message)})));
  });
}
// trocar de aba sem recarregar a página: a tabela é remontada com o que já veio
document.addEventListener("click", function (ev) {
  var link = ev.target.closest("#abas-relogios a[data-aba]");
  if (link && dadosHoje) {
    ev.preventDefault();
    abaAtual = link.getAttribute("data-aba");
    guardarAba(abaAtual);
    mostrarAbas(dadosHoje.abas || []);
  }
});

// a cada minuto, sozinha (api.js): a página e o relógio aberto no painel; não com o quadro do modo de rodízio aberto (os
// formulários dele se remontariam fechados)
atualizarSozinho(function () {
  return recarregarHoje().then(function () {
    var aberto = document.querySelector("#detalhe:not([hidden]) .detalhe[data-id]");
    var id = aberto ? parseInt(aberto.getAttribute("data-id"), 10) : 0;
    return id > 0 ? abrirNoPainel(id) : null;
  });
}, function () {
  return document.querySelector("#bloco-modo details[open]") !== null;
});

// ao abrir: a página, e o relógio pedido (index.php?r=3) ou o cadastro de um novo (index.php?novo=1) no painel
recarregarHoje().then(function () {
  var parametros = new URLSearchParams(window.location.search);
  // index.php#manual (o link da página Plano): o quadro Modo de rodízio aberto, no Rodízio manual
  if (window.location.hash === "#manual") {
    var quadro = document.querySelector("#bloco-modo details");
    var sel = document.querySelector("select[name=modo]");
    if (quadro && sel) {
      quadro.open = true;
      sel.value = "manual";
      mostrarModo();
      quadro.scrollIntoView({block: "start"});
    }
  }
  if (parametros.get("r")) {
    abrirNoPainel(parseInt(parametros.get("r"), 10)).catch(function (e) {
      mostrarRecado(t("Não abri o relógio {1}: {2}.", {1: parametros.get("r"), 2: e.message}));
      history.replaceState(null, "", "index.php");
    });
  } else if (parametros.has("novo")) {
    abrirNoPainel(0);
  }
}).catch(function (e) {
  mostrarRecado(t("Não consegui ler os dados: {1}", {1: e.message}));
});

// O rodízio manual (quadro Modo de rodízio, opção "Rodízio manual"): o período carregado e o que você mudou em cada dia
// (data: id do relógio, "auto" ou "livre"), guardado enquanto você anda pelas páginas; o que não mudou vai como a API
// mostrou. Gravar manda tudo de uma vez (recurso=rodizio, acao=manual)
var manual = {de: "", ate: "", escolhas: {}};

function manualRecado(texto, erro) {
  var p = document.getElementById("manual-recado");
  if (p) {
    p.textContent = texto;
    p.hidden = texto === "";
    p.classList.toggle("erro", !!erro);
  }
}

function manualCarregar(pagina) {
  var caixa = document.getElementById("manual-dias");
  caixa.innerHTML = el("p", {"class": "nota"}, t("Carregando os dias…"));
  api({recurso: "rodizio_manual", de: manual.de, ate: manual.ate, pagina: pagina}).then(function (r) {
    caixa.innerHTML = manualHtml(r);
  }).catch(function (e) {
    caixa.innerHTML = "";
    manualRecado(e.message, true);
  });
}

function manualHtml(r) {
  var linhas = r.dias.map(function (dia) {
    var atual = manual.escolhas[dia.data] !== undefined ? manual.escolhas[dia.data] : String(dia.escolha);
    var opcoes = el("option", {"value": "auto", "selected": atual === "auto"}, t("Decida por mim"))
      + el("optgroup", {"label": t("Relógios")}, r.relogios.map(function (x) {
        return el("option", {"value": String(x.id), "selected": atual === String(x.id)}, h(x.nome));
      }).join(""))
      + opcoesSortearDia(atual)
      + (dia.hoje ? "" : el("option", {"value": "livre", "selected": atual === "livre"}, t("Sem relógio (dia livre)")));
    // hoje, sem trocar o relógio no próprio dia (a Configuração): o seletor fica parado no que hoje já tem
    var fixo = dia.hoje && !r.trocar_hoje;
    var agora = dia.livre ? el("span", {"class": "nota"}, t("dia livre")) : (dia.relogio !== null ? h(dia.relogio) + " " + el("small", {"class": "a-mao"}, dia.origem === "manual" ? t("à mão") : t("sorteado")) : el("span", {"class": "nota"}, "—"));
    return el("tr", {"class": dia.dia_semana >= 6 ? "fds" : ""}, el("td", {"data-rotulo": t("Dia")}, dataBr(dia.data, true) + " " + el("small", {}, dia.dia_semana_nome + (dia.hoje ? t(" · hoje") : "")))
      + el("td", {"data-rotulo": t("No plano agora")}, agora)
      + el("td", {"data-rotulo": t("Relógio do dia")}, el("select", {"data-dia": dia.data, "disabled": fixo, "title": fixo ? t("Hoje fica: o relógio de hoje não se troca no próprio dia (Configuração).") : null, "aria-label": t("Relógio de {1}", {1: dataBr(dia.data)})}, opcoes)));
  }).join("");
  var nav = navPaginas(r.pagina, r.paginas, "manual-pagina");
  return el("table", {"class": "compacta manual-tabela"}, el("thead", {}, el("tr", {}, el("th", {}, t("Dia")) + el("th", {}, t("No plano agora")) + el("th", {}, t("Relógio do dia")))) + el("tbody", {}, linhas))
    + nav
    + el("p", {"class": "nota"}, t("Página {1} de {2} · {3}{4}, de {5} a {6}. O que você escolhe fica guardado enquanto você anda pelas páginas; nada muda antes de gravar.", {1: r.pagina, 2: r.paginas, 3: r.total_dias, 4: r.total_dias === 1 ? t(" dia") : t(" dias"), 5: dataBr(r.de), 6: dataBr(r.ate)}))
    + el("div", {"class": "botoes"}, el("button", {"name": "acao", "value": "manual"}, t("Gravar o rodízio manual")))
    + el("p", {"class": "nota"}, h(r.decida_por_mim))
    + ajudaNota("apagar-dias", t("Apagar dias do plano"), t("Para apagar os dias de um período (o modo de rodízio monta esses dias de novo): \"Apagar um período\", embaixo deste quadro, ou na página {1}.", {1: el("a", {"href": "plano.php"}, t("Plano"))}));
}

document.addEventListener("click", function (ev) {
  if (ev.target.id === "manual-ok") {
    var form = ev.target.form;
    if (!form.de.value || !form.ate.value) {
      manualRecado(t("Ponha o começo e o fim do período."), true);
      return;
    }
    if (form.de.value !== manual.de || form.ate.value !== manual.ate) {
      manual = {de: form.de.value, ate: form.ate.value, escolhas: {}};
    }
    manualRecado("", false);
    manualCarregar(1);
  }
  var pag = ev.target.closest("[data-manual-pagina]");
  if (pag && !pag.disabled) {
    manualCarregar(parseInt(pag.getAttribute("data-manual-pagina"), 10));
  }
});

document.addEventListener("change", function (ev) {
  if (ev.target.matches("#manual-dias select[data-dia]")) {
    manual.escolhas[ev.target.getAttribute("data-dia")] = ev.target.value;
  }
});

document.addEventListener("submit", function (ev) {
  if (ev.target.id !== "form-manual") {
    return;
  }
  ev.preventDefault();
  var acao = ev.submitter && ev.submitter.value ? ev.submitter.value : "manual";
  if (!manual.de) {
    return;
  }
  var pergunta = t("Gravar o rodízio manual de {1} a {2}? Os dias ficam com os relógios escolhidos; os de \"Decida por mim\" são decididos agora pelo sistema.", {1: dataBr(manual.de), 2: dataBr(manual.ate)});
  if (acao !== "manual" || !confirm(pergunta)) {
    return;
  }
  var dados = new FormData();
  dados.append("recurso", "rodizio");
  dados.append("acao", acao);
  dados.append("de", manual.de);
  dados.append("ate", manual.ate);
  Object.keys(manual.escolhas).forEach(function (dt) {
    dados.append("dias[" + dt + "]", manual.escolhas[dt]);
  });
  var posicao = posicaoAtualDaPagina();
  var botoes = ev.target.querySelectorAll("button");
  botoes.forEach(function (b) { b.disabled = true; });
  enviar(dados).then(function (res) {
    botoes.forEach(function (b) { b.disabled = false; });
    if (!res.ok) {
      manualRecado(textoResposta(res), true);
      avisoFlutuanteGravacao(textoResposta(res), true);
      return restaurarPosicaoDaGravacao(posicao);
    }
    manual = {de: "", ate: "", escolhas: {}};
    return Promise.resolve(window.recarregarHoje ? window.recarregarHoje() : null)
      .then(function () { return restaurarPosicaoDaGravacao(posicao); })
      .then(function () { avisoFlutuanteGravacao(textoResposta(res), false, res.mudancas); });
  }).catch(function (e) {
    botoes.forEach(function (b) { b.disabled = false; });
    var erro = t("Não consegui gravar: {1}. Confira a conexão e tente de novo.", {1: e.message});
    manualRecado(erro, true);
    avisoFlutuanteGravacao(erro, true);
  });
});
