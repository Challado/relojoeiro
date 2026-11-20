// Página Rodízio › Ajustes do rodízio: as opções do rodízio (o rodízio ligado, o relógio de ontem sem uso passa para hoje, os
// dias sem relógio, a troca no próprio dia, a semana, a escala inteligente, as casas dos pesos do sorteio e a garantia de dias
// sem uso) nos três níveis: as abas Esta coleção, Minhas coleções (só o dono da coleção) e Sistema (só o master), pelo
// painelNiveis do api.js. Os dados vêm do api.php (recurso=opcoes, grupo=rodizio): cada opção com o que o nível escolheu, o
// que herda e de onde; a gravação é a da Configuração (recurso=config, acao=salvar: <chave>, dono[<chave>], padroes[<chave>]).
function quadroAjustes(titulo, corpo) {
  return el("section", {"class": "cartao-config"}, el("h2", {}, h(titulo)) + el("div", {"class": "cartao-corpo campos"}, corpo));
}

function montarAjustesRodizio(d) {
  var oc = {};
  d.colecao.opcoes.forEach(function (o) { oc[o.chave] = o; });
  // as explicações de cada opção ficam na gaveta (o ⓘ ao lado de cada uma e o botão Explicações do título)
  ajudaGrupo("pagina", t("Ajustes do rodízio"));
  var ex = function (chave, titulo, html) { return ajuda(chave, titulo, el("p", {}, html)); };
  var colecao = quadroAjustes(t("O rodízio"),
      campoOpcao(oc.rodizio_ativo, "rodizio_ativo", null, ex("rodizio-ativo", t("Rodízio da coleção"), t("Desligado, o rodízio pausa os sorteios, o uso automático e os lembretes do plano. O plano e o histórico ficam guardados; cadastro, avisos e marcações manuais continuam disponíveis.")))
      + campoOpcao(oc.recupera_ontem, "recupera_ontem", null, ex("recupera-ontem", t("Relógio de ontem sem uso passa para hoje"), t("Ligado, se o relógio do plano de ontem não foi ao pulso nem um pouquinho (você usou outro, ou nenhum), ele passa para hoje e todos os outros dias do plano andam um dia. Se ele foi usado, mesmo que pouco, nada muda. Não passa o mesmo relógio dois dias seguidos, nem um indisponível, na manutenção ou fora da coleção, nem quando hoje é um dia livre ou o relógio de hoje já foi ao pulso. A conta é feita uma vez por dia, quando o plano de hoje é montado.")))
      + campoOpcao(oc.dia_sem_relogio, "dia_sem_relogio", null, ex("dia-sem-relogio", t("Dias sem relógio (folga)"), t("Ligado (o padrão), dá para deixar um dia <strong>sem relógio</strong>, a folga: no <strong>trocar por…</strong> de cada dia (Próximos dias e Plano) e no Rodízio manual aparece <strong>Sem relógio (dia livre)</strong>, ")
          + t("e no dia de hoje, <strong>Sem relógio hoje</strong> (com a troca no próprio dia ligada). O dia livre fica sem relógio e sem sorteio até você escolher um ou voltar a sortear. Desligado, essas opções somem; os dias que já estão livres continuam livres.")))
      + campoOpcao(oc.trocar_hoje, "trocar_hoje", null, ex("trocar-hoje", t("Trocar o relógio de hoje no próprio dia"), t("Desligado (o padrão), o relógio de hoje fica: o <strong>trocar por…</strong>, o <strong>Usando hoje</strong>, o <strong>Sortear de novo</strong>, o <strong>Sem relógio hoje</strong>, o período e o Rodízio manual valem a partir de amanhã, e o relógio de amanhã se escolhe até hoje. ")
          + t("Ligado, o relógio de hoje também se troca no próprio dia, e passa a ser o do pulso a partir de agora. As marcações do pulso (Pôs e Tirou) continuam livres nos dois casos."))))
    + quadroAjustes(t("A semana e a escala inteligente"),
      // a semana do rodízio: o horizonte do plano, o ciclo, a próxima semana e os blocos contam dela
      campoOpcao(oc.semana_inicio, "semana_inicio", null, ex("semana", t("A semana começa"), t("A semana do rodízio começa no dia escolhido: o plano vem montado até o último dia da semana, e o ciclo, a próxima semana e o relógio para a semana inteira contam dela.")))
      // o primeiro dia da escala inteligente: amanhã quando o de hoje já começou no pulso (o padrão) ou sempre hoje
      + campoOpcao(oc.escala_inicio, "escala_inicio", null, ex("escala-inicio", t("A escala inteligente começa"), t("De onde a escala conta os dias do modo (o período dela). <strong>Amanhã, se o dia de hoje já começou no pulso</strong> (o padrão): o dia de hoje que já começou fica de fora da conta, e a escala de 7 dias vai de amanhã até daqui a 7 dias (o mesmo dia da semana aparece duas vezes, hoje e no fim). ")
          + t("<strong>Sempre hoje</strong>: hoje é o 1º dia, e a escala de 7 dias vai até daqui a 6 (uma semana certa). O relógio de hoje que já começou no pulso não muda nos dois casos. Mudou: a escala é refeita na próxima vez que o plano é montado (a página Hoje, o cron).")))
      // onde a escala fecha: nos dias corridos do período (o padrão), no fim da semana ou no fim do mês
      + campoOpcao(oc.escala_periodo, "escala_periodo", null, ex("escala-periodo", t("A escala inteligente fecha"), t("Onde termina o período da escala (o do modo: uma semana, um mês...). <strong>Nos dias corridos do período</strong> (o padrão): os dias contados do primeiro dia dela; a de uma semana, numa terça, vai até a terça seguinte. ")
          + t("<strong>No fim da semana</strong>: semanas inteiras, pela \"A semana começa\": a de uma semana vai do primeiro dia dela até o último dia da semana (numa terça, com a semana começando na segunda, até o domingo); no último dia da semana, a semana seguinte inteira. ")
          + t("<strong>No fim do mês</strong>: meses inteiros (a de um mês vai até o último dia do mês; a de meio ano, até o fim do 6º mês contando o de agora; no último dia do mês, até o fim do mês seguinte); com menos de 4 semanas, como no fim da semana. Quando o período acaba, o seguinte começa no dia depois do fim, do mesmo jeito. Mudou: a escala é refeita na próxima vez que o plano é montado.")))
      // no último dia da semana, os modos por semana montam só o dia seguinte (o padrão) ou a semana seguinte inteira
      + campoOpcao(oc.semana_ultimo_dia, "semana_ultimo_dia", null, ex("semana-ultimo-dia", t("No último dia da semana"), t("Nos modos que planejam por semana (fora da escala). <strong>Só o dia seguinte</strong> (o padrão): no último dia da semana o plano traz o dia de amanhã, e a semana seguinte entra inteira no dia em que ela começa. ")
          + t("<strong>A semana seguinte inteira</strong>: no último dia da semana, as semanas do modo já contam da semana que vem, e ela vem montada inteira (com a semana começando na segunda, no domingo o plano traz de segunda a domingo)."))))
    + quadroAjustes(t("O sorteio e a garantia"),
      campoOpcao(oc.sorteio_casas, "sorteio_casas", null)
      + campoOpcao(oc.max_sem_uso, "max_sem_uso", null));
  porHtml(document.getElementById("rodizio"), el("div", {"class": "titulo-secao"}, el("h1", {}, t("Ajustes do rodízio")) + ajudaBotao())
    + painelNiveis(d, colecao, nivelDoEndereco()));
}

function carregarAjustesRodizio() {
  return api({recurso: "opcoes", grupo: "rodizio"}).then(montarAjustesRodizio).catch(function (e) {
    porHtml(document.getElementById("rodizio"), el("p", {"class": "acao"}, t("Não consegui ler os ajustes do rodízio: {1}", {1: h(e.message)})));
  });
}

// salvar: a página volta montada com o que vale agora (os textos do Herdar mudam), no mesmo lugar e na mesma aba
aoGravar = function (form, res) {
  return salvarSemMover(form, res, res.ok ? carregarAjustesRodizio : null);
};

carregarAjustesRodizio();
