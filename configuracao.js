// Página Configuração: os dados da API (recurso=config), no HTML da Configuração do sistema antigo. Com o banco
// desatualizado (recurso=migracoes com pendentes), só o aviso e o botão de aplicar.
var recadoConfig = "";

// "Inserir âncora": a lista das âncoras, que põe a escolhida onde está o cursor do campo alvo
function listaAncoras(d, alvo, rotulo) {
  return el("select", {"class": "ancora", "data-alvo": alvo, "aria-label": rotulo}, el("option", {"value": ""}, t("Inserir âncora")) + Object.keys(d.ancoras).map(function (k) {
    return el("option", {"value": "{" + k + "}"}, h("{" + k + "} " + d.ancoras[k]));
  }).join(""));
}

// Um quadro da Configuração: o título, o conteúdo e, se houver, o pé (a explicação e os botões, sempre embaixo: os quadros
// da mesma linha têm a mesma altura)
function cartao(titulo, corpo, pe) {
  return el("section", {"class": "cartao-config"}, el("h2", {}, h(titulo)) + el("div", {"class": "cartao-corpo"}, corpo) + (pe ? el("div", {"class": "cartao-pe"}, pe) : ""));
}

// Uma opção numa linha: o rótulo à esquerda e o campo à direita, na mesma coluna em todos os quadros. "check": a caixa no
// lugar do campo; "largo": o rótulo em cima e o campo na largura toda (textos longos, como um endereço)
function parCampo(rotulo, controle, tipo) {
  return el("label", {"class": "par-campo" + (tipo ? " " + tipo : "")}, el("span", {"class": "rotulo"}, h(rotulo)) + el("span", {"class": "controle"}, controle));
}

// Evento personalizado: mostra só os campos da repetição escolhida
function mostrarRepeticao() {
  var repeticao = document.getElementById("ev-repeticao");
  if (repeticao) {
    document.querySelectorAll(".form-evento [data-rep]").forEach(function (e) {
      e.hidden = e.dataset.rep.split(" ").indexOf(repeticao.value) < 0;
    });
  }
}

// lê e monta a página: primeiro confere as migrações (com alguma pendente, só o aviso)
function recarregarConfig(aplicadas) {
  return api({recurso: "migracoes"}).then(function (m) {
    if (m.pendentes.length > 0 || aplicadas.length > 0) {
      // o aviso do banco desatualizado, com o botão de aplicar (e o resultado, depois de aplicar)
      var res = "";
      if (aplicadas.length > 0) {
        res += el("h2", {}, t("Atualização do banco")) + aplicadas.map(function (a) { return el("p", {}, h(a)); }).join("");
      }
      if (m.pendentes.length > 0) {
        res += el("h2", {}, t("O banco está desatualizado"))
          + el("p", {}, t("Esta versão do sistema precisa de migrações que ainda não foram aplicadas no banco. Até lá, as páginas, a API e o cron ficam parados, para não gravar nada pela metade."))
          + el("table", {"class": "compacta"}, el("tbody", {}, m.pendentes.map(function (p) {
            return el("tr", {}, el("td", {}, el("strong", {}, h(p.versao))) + el("td", {}, h(p.traz)) + el("td", {}, el("code", {}, h(p.arquivo))));
          }).join("")))
          + el("form", {"data-recurso": "migracoes"}, el("input", {"type": "hidden", "name": "acao", "value": "aplicar"}) + el("div", {"class": "botoes"}, el("button", {}, t("Aplicar agora"))))
          + el("p", {"class": "nota"}, t("Aplica na ordem e para na primeira que falhar. Se o usuário do banco do sistema não tiver permissão de alterar tabelas, rode na mão, na ordem: {1}", {1: el("code", {}, "mysql -u root -p &lt;banco&gt; &lt; arquivo.sql")}));
      } else {
        res += el("p", {}, el("a", {"href": "configuracao.php", "class": "botao"}, t("Continuar para a Configuração")));
      }
      document.getElementById("configuracao").innerHTML = el("section", {"class": "cartao-config aviso-banco"}, res);
    } else {
      return Promise.all([api({recurso: "config"}), api({recurso: "backup"})]).then(function (rs) {
        var d = rs[0];
        var bk = rs[1];
        var c = d.config;
        var cfg = function (k) { return c[k] === undefined ? "" : c[k]; };
        var res = "";
        if (recadoConfig !== "") {
          res += el("p", {"class": "acao"}, h(recadoConfig));
          recadoConfig = "";
        }
        // ---------- Geral ----------
        // a situação do cron vem da API (cron_estado): nunca rodou, parado (mais de 10 minutos) ou rodando
        var ce = d.cron_estado;
        var cron = ce.situacao === "rodando" ? el("p", {"class": "nota"}, h(ce.texto))
          : el("p", {"class": "alerta-cron"}, h(ce.texto.replace(ce.crontab, "")) + el("code", {}, h(ce.crontab)));
        if (cfg("cron_erro") !== "") {
          cron += el("div", {"class": "erro-cron"}, el("strong", {}, t("O cron está com erro")) + el("pre", {}, h(cfg("cron_erro"))) + el("span", {"class": "nota"}, t("Some sozinho na primeira execução sem erro.")));
        }
        cron += el("p", {"class": "nota"}, t("{1}, com filtro de datas e busca.", {1: el("a", {"href": "execucoes.php", "target": "_blank", "rel": "noopener"}, t("Ver todas as execuções do cron"))}));
        if (cfg("cron_registro") !== "") {
          cron += el("details", {"class": "registro-cron"}, el("summary", {}, t("Registro da última rodada")) + el("pre", {}, h(cfg("cron_registro"))));
        }
        // ---------- os quadros do alto: cada opção numa linha, o rótulo à esquerda e o campo à direita, todos alinhados ----------
        var hora = function (nome, valor) { return el("input", {"type": "time", "name": nome, "value": valor}); };
        var numero = function (nome, valor, min, max) { return el("input", {"type": "number", "min": min, "max": max, "name": nome, "value": valor}); };
        var marca = function (nome, ligado) {
          return el("input", {"type": "hidden", "name": nome, "value": "0"}) + el("input", {"type": "checkbox", "name": nome, "value": "1", "checked": ligado});
        };
        var rotina = cartao(t("Rotina do dia"), el("div", {"class": "campos"},
          parCampo(t("Manhã: relógio do dia e avisos"), hora("horario_manha", cfg("horario_manha")))
          + parCampo(t("Noite: preparar o de amanhã"), hora("horario_noite", cfg("horario_noite")))
          + parCampo(t("No pulso a partir de"), hora("uso_inicio", cfg("uso_inicio")))
          + parCampo(t("Pôr no pulso sozinho"), marca("pulso_auto_inicio", cfg("pulso_auto_inicio") !== "0"), "check")
          + parCampo(t("No pulso até"), hora("uso_fim", cfg("uso_fim")))
          + parCampo(t("Tirar do pulso sozinho"), marca("pulso_auto_fim", cfg("pulso_auto_fim") !== "0"), "check")
          + parCampo(t("Sessão no sol esquecida fecha às"), hora("sol_fim", d.sol_fim))),
          el("p", {"class": "nota"}, t("Sem <strong>pôr sozinho</strong>, o relógio do dia só entra no pulso pelo <strong>Pôs no pulso</strong>; sem <strong>tirar sozinho</strong>, só sai pelo ")
            + t("<strong>Tirou do pulso</strong> (esquecido, continua contando como no pulso). O horário de uso vale sempre para o plano e as previsões. Fora do pulso, o relógio conta como guardado.")));
        var limites = cartao(t("Limites e contas"), el("div", {"class": "campos"},
          parCampo(t("Carregar quando a carga chegar a (%)"), numero("carga_limiar", cfg("carga_limiar") !== "" ? cfg("carga_limiar") : "20", "1", "99"))
          + parCampo(t("Solar: pôr no sol quando chegar a (%)"), numero("sol_limiar", cfg("sol_limiar") !== "" ? cfg("sol_limiar") : "70", "1", "99"))
          + parCampo(t("Gasto medido: média dos últimos (dias)"), numero("medicao_janela_dias", cfg("medicao_janela_dias") !== "" ? cfg("medicao_janela_dias") : "90", "1", "3650"))
          + parCampo(t("Aviso Encostado: dias sem ir ao pulso"), numero("encostado_dias", cfg("encostado_dias") !== "" ? cfg("encostado_dias") : "30", "1", "3650"))),
          el("p", {"class": "nota"}, t("O limite de carga de um relógio (no cadastro dele) vale no lugar do geral. Medições do gasto mais velhas que a janela saem da média. ")
            + t("Um relógio da coleção que passa do limite de dias sem ir ao pulso ganha o aviso <strong>Encostado</strong> (desliga em Cadastros › Avisos).")));
        var tela = cartao(t("Telas"), el("div", {"class": "campos"},
          parCampo(t("Idioma padrão (quem não escolheu outro no menu)"), el("select", {"name": "idioma"}, d.linguas.map(function (l) {
            return el("option", {"value": l.codigo, "selected": (cfg("idioma") || "pt-BR") === l.codigo}, h(l.nome));
          }).join("")))
          + parCampo(t("Abrir o relógio na página Hoje"), el("select", {"name": "painel_modo"}, el("option", {"value": "lado", "selected": cfg("painel_modo") !== "flutuante"}, t("Ao lado da lista"))
            + el("option", {"value": "flutuante", "selected": cfg("painel_modo") === "flutuante"}, t("Numa janela flutuante, grande"))))
          + parCampo(t("Destaque no alto da página Hoje"), el("select", {"name": "destaque_hoje"}, el("option", {"value": "dia", "selected": cfg("destaque_hoje") !== "pulso"}, t("O relógio do dia"))
            + el("option", {"value": "pulso", "selected": cfg("destaque_hoje") === "pulso"}, t("O relógio no pulso agora"))))
          + parCampo(t("Próximos dias (página Hoje): mostrar até (dias por página)"), numero("proximos_por_pagina", String(d.mostrar_ate.proximos), "1", "366"))
          + parCampo(t("Plano: mostrar até (dias por página)"), numero("plano_por_pagina", String(d.mostrar_ate.plano), "1", "366"))
          + parCampo(t("Endereço do sistema (a âncora {link})"), el("input", {"name": "url_sistema", "value": cfg("url_sistema"), "placeholder": "http://servidor/relogios"}), "largo")),
          el("p", {"class": "nota"}, t("<strong>Ao lado da lista:</strong> o relógio clicado abre num painel à direita, e a lista continua à vista. <strong>Numa janela flutuante:</strong> abre grande, ")
            + t("no meio da tela, com os quadros lado a lado; fecha no ×, no Esc ou num clique fora. No celular, os dois cobrem a tela inteira."))
          + el("p", {"class": "nota"}, t("<strong>Destaque:</strong> o quadro grande no alto da página Hoje mostra o relógio do dia (o do plano) ou o que está no pulso agora, ")
            + t("mesmo que seja outro (o do dia aparece embaixo do nome). Sem nenhum relógio no pulso, fica o do dia."))
          + el("p", {"class": "nota"}, t("<strong>Mostrar até:</strong> quantos dias os Próximos dias (página Hoje, padrão 7) e o dia a dia da página Plano (padrão 31) mostram de cada vez. ")
            + t("Passando disso, embaixo aparecem as páginas: &lt; (anterior), os números e &gt; (próxima).")));
        var quadroCron = cartao(t("Cron"), cron);
        // de onde vêm os números dos sorteios (a API diz o último lote e se os dois serviços estão fora)
        var al = d.aleatorio;
        var sorteios = cartao(t("Sorteios"), el("div", {"class": "campos"},
          parCampo(t("Os números vêm de"), el("span", {}, t("1. RANDOM.ORG (ruído atmosférico) · 2. fora do ar, ANU QRNG (quântico) · 3. sem os dois, o gerador seguro do PHP")))
          + parCampo(t("Último lote"), el("span", {}, al.ultimo_lote ? h(al.ultimo_lote.replace(/ em (\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*/, " em $3/$2/$1 $4")) : t("nenhum ainda")))
          + (al.fora_desde ? parCampo(t("Serviços fora"), el("span", {"class": "acao"}, t("os dois sem responder desde {1} {2}; o sistema tenta de novo depois de 10 minutos", {1: dataBr(al.fora_desde), 2: horaBr(al.fora_desde)}))) : "")),
          el("p", {"class": "nota"}, t("Todo sorteio do sistema (o rodízio totalmente aleatório, o sorteio pela nota e o desempate de notas iguais na escala) usa estes números. Eles chegam num lote, e cada sorteio usa só o que precisa, sem viés.")));
        var telegram = cartao(t("Telegram (API de alerta)"), el("div", {"class": "campos"}, parCampo(t("Enviar alertas"), marca("alerta_ativo", cfg("mensagens_ativas") === "1"), "check")),
          el("p", {"class": "nota"}, t("O endereço e o destinatário ficam no config.php. O texto de cada aviso vem da mensagem padrão do Telegram ou da personalizada dele, em \"O que vai para onde\".")));
        var agenda = cartao(t("Google Agenda"), el("div", {"class": "campos"},
          parCampo(t("Criar eventos na agenda"), marca("agenda_ativa", cfg("agenda_ativa") === "1"), "check")
          + parCampo(t("Antecedência dos eventos (dias)"), numero("agenda_antecedencia", cfg("agenda_antecedencia"), "1", "365"))
          + parCampo(t("ID da agenda"), el("input", {"name": "agenda_id", "value": cfg("agenda_id"), "placeholder": t("xxxx@group.calendar.google.com")}), "largo")
          + parCampo(t("Caminho da chave JSON no servidor"), el("input", {"name": "agenda_chave", "value": cfg("agenda_chave"), "placeholder": d.pasta + "/google-conta-servico.json"}), "largo"))
          + (d.chave_agenda ? el("p", {"class": "nota"}, t("Chave encontrada. Compartilhe a agenda com {1}, com permissão para fazer alterações nos eventos.", {1: el("strong", {"class": "quebra"}, h(d.chave_agenda.client_email))}))
            : el("p", {"class": "nota"}, t("Nenhuma chave válida nesse caminho. Confira o nome do arquivo e se o usuário do PHP-FPM (www-data) tem permissão de leitura."))),
          el("p", {"class": "nota"}, t("O teste cria um evento daqui a 10 minutos, no formato dos modelos. Salve antes de testar."))
          + el("button", {"type": "submit", "form": "f-teste-criar", "class": "leve"}, t("Criar evento de teste"))
          + el("button", {"type": "submit", "form": "f-teste-remover", "class": "leve", "disabled": cfg("agenda_teste_id") === ""}, t("Remover evento de teste")));
        // ---------- Mensagem padrão ----------
        var modelos = Object.keys(d.canais).map(function (canal) {
          var cn = d.canais[canal];
          return el("div", {"class": "cartao-config"}, el("div", {"class": "campo-modelo"}, el("div", {"class": "rotulo-modelo"}, el("label", {"for": canal + "_padrao"}, el("h3", {}, h(cn.nome)))
            + listaAncoras(d, canal + "_padrao", t("Inserir âncora na mensagem padrão do {1}", {1: cn.nome})))
            + el("textarea", {"id": canal + "_padrao", "name": canal + "_padrao", "rows": "7"}, h(cfg(canal + "_padrao"))) + el("p", {"class": "nota"}, h(cn.ajuda))));
        }).join("");
        var padrao = el("section", {}, el("h2", {}, t("Mensagem padrão"))
          + el("p", {"class": "nota"}, t("Todo aviso usa a mensagem padrão do canal, a não ser que tenha a sua personalizada, marcada em \"O que vai para onde\". As âncoras entre chaves, como {relogio}, viram os valores na hora de enviar; \"Inserir âncora\" põe uma onde está o cursor."))
          + el("div", {"class": "modelos"}, modelos)
          + el("details", {"class": "lista-ancoras"}, el("summary", {}, t("O que cada âncora vira")) + el("table", {"class": "compacta"}, el("tbody", {}, Object.keys(d.ancoras).map(function (k) {
            return el("tr", {}, el("td", {}, el("code", {}, "{" + k + "}")) + el("td", {}, h(d.ancoras[k])));
          }).join("")))));
        // ---------- O que vai para onde ----------
        var canais = Object.keys(d.canais);
        var linhas = d.tipos.map(function (item) {
          var nome = h(item.nome);
          if (item.evento !== null) {
            nome += " " + el("span", {"class": "acoes-ev"}, el("a", {"href": "configuracao.php?evento=" + item.evento + "#personalizados"}, "editar") + " "
              + el("button", {"type": "submit", "form": "f-evento-excluir", "name": "evento_id", "value": item.evento, "class": "discreto",
                "onclick": "return confirm(" + JSON.stringify(t("Excluir o evento {1}?", {1: item.nome})) + ")"}, "excluir"));
          }
          var linha = el("td", {}, nome) + el("td", {}, h(item.quando)) + canais.map(function (canal) {
            return el("td", {"class": "centro"}, el("input", {"type": "checkbox", "name": d.canais[canal].tipos + "[]", "value": item.tipo, "checked": item.canais[canal].marcado,
              "aria-label": t("{1} no {2}", {1: item.nome, 2: d.canais[canal].nome})}));
          }).join("") + el("td", {"class": "proprios"}, canais.map(function (canal) {
            return el("label", {"class": "check"}, el("input", {"type": "hidden", "name": canal + "_proprio_" + item.tipo, "value": "0"}) + el("input", {"type": "checkbox", "name": canal + "_proprio_" + item.tipo,
              "value": "1", "data-mostra": "bloco_" + canal + "_" + item.tipo, "checked": item.canais[canal].proprio}) + " " + h(d.canais[canal].nome));
          }).join(""));
          var corpo = el("td", {"colspan": 3 + canais.length}, canais.map(function (canal) {
            return el("div", {"class": "campo-modelo", "id": "bloco_" + canal + "_" + item.tipo, "hidden": !item.canais[canal].proprio}, el("div", {"class": "rotulo-modelo"},
              el("label", {"for": canal + "_corpo_" + item.tipo}, t("{1}, personalizado no {2}", {1: h(item.nome), 2: h(d.canais[canal].nome)})) + listaAncoras(d, canal + "_corpo_" + item.tipo, t("Inserir âncora")))
              + el("textarea", {"id": canal + "_corpo_" + item.tipo, "name": canal + "_corpo_" + item.tipo, "rows": "3", "placeholder": t("Vazio usa a mensagem padrão do {1}", {1: d.canais[canal].nome})}, h(item.canais[canal].corpo)));
          }).join(""));
          return el("tr", {"class": item.evento !== null ? "personalizado" : null}, linha) + el("tr", {"class": "linha-corpo"}, corpo);
        }).join("");
        var paraOnde = el("section", {}, el("h2", {}, t("O que vai para onde"))
          + el("p", {"class": "nota"}, t("Marque \"Personalizar\" num canal para aquele aviso ter uma mensagem só dele, no lugar da mensagem padrão do canal. Cada canal tem a sua."))
          + canais.map(function (canal) { return el("input", {"type": "hidden", "name": d.canais[canal].tipos + "[]", "value": ""}); }).join("")
          + el("div", {"class": "rolagem"}, el("table", {"class": "relogios canais"}, el("thead", {}, el("tr", {}, el("th", {}, t("Aviso")) + el("th", {}, t("Quando"))
            + canais.map(function (canal) { return el("th", {"class": "centro"}, h(d.canais[canal].nome)); }).join("") + el("th", {}, t("Personalizar")))) + el("tbody", {}, linhas))));
        res += el("form", {"data-recurso": "config", "id": "form-config"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
          + el("div", {"class": "config-grade"}, rotina + limites + tela + quadroCron + sorteios + telegram + agenda) + padrao + paraOnde
          // salvar: uma barra presa no pé da tela enquanto a página rola, com o botão sempre no mesmo lugar
          + el("div", {"class": "barra-salvar"}, el("span", {"class": "nota"}, t("As mudanças desta página valem depois de salvar.")) + el("button", {}, t("Salvar configuração"))));
        res += el("form", {"data-recurso": "config", "id": "f-evento-excluir"}, el("input", {"type": "hidden", "name": "acao", "value": "evento_excluir"}));
        // ---------- o cadastro de um evento ----------
        var q = new URLSearchParams(window.location.search);
        var edit = null;
        d.eventos.forEach(function (ev) {
          if (String(ev.id) === q.get("evento")) {
            edit = ev;
          }
        });
        var fe = edit || {id: 0, nome: "", ativo: true, repeticao: "semanal", data_inicio: "", hora: "20:00", dias_semana: "7", dia_mes: 1, intervalo_dias: 30, relogio_id: null};
        var diasFe = String(fe.dias_semana || "").split(",");
        var formEvento = el("input", {"type": "hidden", "name": "acao", "value": "evento_salvar"}) + el("input", {"type": "hidden", "name": "evento_id", "value": fe.id})
          + el("label", {}, t("Nome {1}", {1: el("input", {"name": "nome", "value": fe.nome, "required": true, "placeholder": t("Conferir a carga dos smartwatches")})}))
          + el("label", {}, t("Relógio (opcional: com relógio, as âncoras dele funcionam) {1}", {1: el("select", {"name": "relogio_id"}, el("option", {"value": "0"}, t("Nenhum, é um evento geral"))
            + d.relogios.filter(function (r) { return r.disponivel || fe.relogio_id === r.id; }).map(function (r) {
              return el("option", {"value": r.id, "selected": fe.relogio_id === r.id}, h(r.nome) + (r.disponivel ? "" : t(" (indisponível)")));
            }).join(""))}))
          + el("label", {}, t("Quando dispara {1}", {1: el("select", {"name": "repeticao", "id": "ev-repeticao"}, Object.keys(d.repeticoes).map(function (k) {
            return el("option", {"value": k, "selected": fe.repeticao === k}, h(d.repeticoes[k]));
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
          + el("p", {"class": "nota"}, t("Depois de salvar, o evento aparece na tabela \"O que vai para onde\", onde se escolhem os canais e o texto de cada um. Evento novo já vem marcado para o Telegram."))
          + el("div", {"class": "botoes"}, el("button", {}, edit ? t("Salvar alterações") : t("Criar evento")) + (edit ? " " + el("a", {"href": "configuracao.php#personalizados"}, "cancelar") : ""));
        res += el("section", {"id": "personalizados"}, el("h2", {}, t("Eventos personalizados"))
          + el("p", {"class": "nota"}, t("Um aviso seu, com horário e repetição próprios: todo dia, em dias da semana, todo mês, a cada N dias ou uma vez só. Os eventos já criados estão na tabela acima (editar e excluir ficam no nome deles)."))
          + el("details", {"class": "cartao-config novo-evento", "open": edit !== null || q.has("novo_evento")},
          el("summary", {}, edit ? t("Editar o evento {1}", {1: h(edit.nome)}) : t("Novo evento")) + el("form", {"data-recurso": "config", "class": "form-evento"}, formEvento)));
        res += el("form", {"data-recurso": "config", "id": "f-teste-criar"}, el("input", {"type": "hidden", "name": "acao", "value": "teste_agenda_criar"}))
          + el("form", {"data-recurso": "config", "id": "f-teste-remover"}, el("input", {"type": "hidden", "name": "acao", "value": "teste_agenda_remover"}));
        // ---------- Como sai hoje ----------
        var p = d.previa;
        res += el("section", {}, el("h2", {}, t("Como sai hoje")) + el("div", {"class": "config-grade"},
          el("div", {"class": "previa-msg"}, el("h3", {}, t("Telegram de manhã")) + el("pre", {}, p.manha !== "" ? h(p.manha) : t("(nada a enviar)"))
            + el("form", {"data-recurso": "config"}, el("input", {"type": "hidden", "name": "acao", "value": "testar_manha"}) + el("button", {"class": "leve"}, t("Enviar agora"))))
          + el("div", {"class": "previa-msg"}, el("h3", {}, t("Telegram à noite")) + el("pre", {}, p.noite !== "" ? h(p.noite) : t("(nada a enviar: amanhã continua o mesmo relógio)"))
            + el("form", {"data-recurso": "config"}, el("input", {"type": "hidden", "name": "acao", "value": "testar_noite"}) + el("button", {"class": "leve"}, t("Enviar agora"))))
          + el("div", {"class": "previa-msg"}, el("h3", {}, t("Agenda")) + p.agenda.map(function (a) {
            return el("div", {"class": "previa-evento"}, el("div", {"class": "quando"}, dataBr(a.data, true) + "/" + a.data.substr(2, 2) + " " + h(a.hora)) + el("strong", {}, h(a.titulo))
              + (a.descricao !== "" ? el("pre", {}, h(a.descricao)) : ""));
          }).join("") + (p.agenda.length === 0 ? el("p", {"class": "nota"}, t("Nada previsto no período.")) : "")
            + el("p", {"class": "nota"}, t("{1} eventos criados pelo sistema estão na agenda. Mudou um modelo? Sincronize: os eventos já criados são atualizados.", {1: p.sincronizados}))
            + el("form", {"data-recurso": "config"}, el("input", {"type": "hidden", "name": "acao", "value": "sincronizar"}) + el("button", {"class": "leve"}, t("Sincronizar agora"))))));
        // ---------- Cópia de segurança (desligada no config.php, BACKUP_ATIVO = false: o quadro nem aparece) ----------
        res += bk.ativo === false ? "" : el("section", {"id": "copia"}, el("h2", {}, t("Cópia de segurança"))
          + (bk.pasta_ok
            ? el("p", {"class": "nota"}, t("Uma cópia do banco inteiro por dia, na rodada da noite, guardando as {1} mais novas, {2} o conteúdo dos documentos (os arquivos já ficam na pasta deles). A cópia volta em qualquer um dos três bancos, na mesma versão do sistema. {3}", {1: bk.manter, 2: bk.documentos ? "com" : "sem", 3: bk.ultima ? t("Última: {1} {2}.", {1: dataBr(bk.ultima), 2: horaBr(bk.ultima)}) : t("Nenhuma feita ainda.")}))
            : el("p", {"class": "alerta-cron"}, t("A cópia automática está desligada: {1}. Ponha no config.php a BACKUP_PASTA, uma pasta do servidor fora da pasta publicada.", {1: h(bk.pasta_erro)})))
          + (bk.copias.length > 0 ? el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Cópia"), t("Feita em"), t("Tamanho"), t("Documentos"), ""].map(function (item) { return el("th", {}, item); }).join("")))
            + el("tbody", {}, bk.copias.map(function (x) {
              return el("tr", {}, el("td", {}, el("code", {}, h(x.arquivo))) + el("td", {}, x.criada ? dataBr(x.criada) + " " + horaBr(x.criada) : "—") + el("td", {}, h(x.tamanho))
                + el("td", {}, x.documentos ? "com" : "sem") + el("td", {"class": "acoes-linha"}, el("a", {"href": "api.php?recurso=backup&arquivo=" + encodeURIComponent(x.arquivo), "class": "botao leve"}, t("Baixar")) + " "
                + el("form", {"data-recurso": "backup", "onsubmit": "return confirm(" + JSON.stringify(t("Voltar o banco para a cópia de {1}? Tudo o que foi feito depois some. Antes, o sistema guarda uma cópia do que está agora.", {1: x.criada ? dataBr(x.criada) + " " + horaBr(x.criada) : x.arquivo})) + ")"}, el("input", {"type": "hidden", "name": "acao", "value": "restaurar"})
                  + el("input", {"type": "hidden", "name": "arquivo", "value": x.arquivo}) + el("button", {"class": "discreto"}, t("Restaurar")))));
            }).join("")))) : "")
          + (bk.pasta_ok ? el("form", {"data-recurso": "backup"}, el("input", {"type": "hidden", "name": "acao", "value": "fazer"}) + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, t("Fazer uma cópia agora")))) : ""));
        document.getElementById("configuracao").innerHTML = res;
        mostrarRepeticao();
        if (window.location.hash) {
          var alvo = document.querySelector(window.location.hash);
          if (alvo) {
            alvo.scrollIntoView();
          }
        }
      });
    }
  });
}

document.addEventListener("change", function (ev) {
  // "Inserir âncora": põe a âncora escolhida onde está o cursor do campo
  if (ev.target.matches("select.ancora")) {
    var sel = ev.target;
    var alvo = document.getElementById(sel.dataset.alvo);
    if (alvo && sel.value) {
      var i = alvo.selectionStart;
      var f = alvo.selectionEnd;
      alvo.value = alvo.value.slice(0, i) + sel.value + alvo.value.slice(f);
      alvo.focus();
      alvo.selectionStart = alvo.selectionEnd = i + sel.value.length;
    }
    sel.value = "";
  }
  if (ev.target.matches("#ev-repeticao")) {
    mostrarRepeticao();
  }
  // "Corpo próprio": mostra ou esconde o campo do texto daquele aviso
  if (ev.target.matches("input[data-mostra]")) {
    var bloco = document.getElementById(ev.target.dataset.mostra);
    if (bloco) {
      bloco.hidden = !ev.target.checked;
    }
  }
});

// depois de gravar: o recado no topo e a página remontada (o evento salvo sai da edição)
aoGravar = function (form, res, dados) {
  recadoConfig = textoResposta(res);
  var acao = dados.get("acao");
  if ((acao === "evento_salvar" || acao === "evento_excluir") && res.ok) {
    history.replaceState(null, "", "configuracao.php#personalizados");
  } else if (acao === "salvar" && res.ok) {
    history.replaceState(null, "", "configuracao.php");
  }
  recarregarConfig(dados.get("recurso") === "migracoes" ? (res.aplicadas || [textoResposta(res)]) : []).then(function () {
    window.scrollTo(0, acao === "evento_salvar" || acao === "evento_excluir" ? document.getElementById("personalizados").offsetTop : 0);
  });
};

recarregarConfig([]).catch(function (e) {
  document.getElementById("configuracao").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler a configuração: {1}", {1: h(e.message)}));
});
