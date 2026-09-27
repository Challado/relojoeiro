// Página Configuração: os dados da API (recurso=config), no HTML da Configuração do sistema antigo. Com o banco
// desatualizado (recurso=migracoes com pendentes), só o aviso e o botão de aplicar.
var recadoConfig = "";

// "Inserir âncora": a lista das âncoras, que põe a escolhida onde está o cursor do campo alvo
function listaAncoras(d, alvo, rotulo) {
  return el("select", {"class": "ancora", "data-alvo": alvo, "aria-label": rotulo}, el("option", {"value": ""}, "Inserir âncora") + Object.keys(d.ancoras).map(function (k) {
    return el("option", {"value": "{" + k + "}"}, h("{" + k + "} " + d.ancoras[k]));
  }).join(""));
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
        res += el("h2", {}, "Atualização do banco") + aplicadas.map(function (a) { return el("p", {}, h(a)); }).join("");
      }
      if (m.pendentes.length > 0) {
        res += el("h2", {}, "O banco está desatualizado")
          + el("p", {}, "Esta versão do sistema precisa de migrações que ainda não foram aplicadas no banco. Até lá, as páginas, a API e o cron ficam parados, para não gravar nada pela metade.")
          + el("table", {"class": "compacta"}, el("tbody", {}, m.pendentes.map(function (p) {
            return el("tr", {}, el("td", {}, el("strong", {}, h(p.versao))) + el("td", {}, h(p.traz)) + el("td", {}, el("code", {}, h(p.arquivo))));
          }).join("")))
          + el("form", {"data-recurso": "migracoes"}, el("input", {"type": "hidden", "name": "acao", "value": "aplicar"}) + el("div", {"class": "botoes"}, el("button", {}, "Aplicar agora")))
          + el("p", {"class": "nota"}, "Aplica na ordem e para na primeira que falhar. Se o usuário do banco do sistema não tiver permissão de alterar tabelas, rode na mão, na ordem: "
            + el("code", {}, "mysql -u root -p &lt;banco&gt; &lt; arquivo.sql"));
      } else {
        res += el("p", {}, el("a", {"href": "configuracao.php", "class": "botao"}, "Continuar para a Configuração"));
      }
      document.getElementById("configuracao").innerHTML = el("section", {"class": "cartao-config aviso-banco"}, res);
    } else {
      return api({recurso: "config"}).then(function (d) {
        var c = d.config;
        var cfg = function (k) { return c[k] === undefined ? "" : c[k]; };
        var res = "";
        if (recadoConfig !== "") {
          res += el("p", {"class": "acao"}, h(recadoConfig));
          recadoConfig = "";
        }
        // ---------- Geral ----------
        var ultCron = cfg("cron_ultima_execucao");
        var linhaCron = el("code", {}, "* * * * * php " + h(d.pasta) + "/cron.php");
        var cron = "";
        if (ultCron === "") {
          cron = el("p", {"class": "alerta-cron"}, "O cron ainda não rodou. Linha do crontab: " + linhaCron);
        } else if (Date.now() - instante(ultCron) > 10 * 60000) {
          cron = el("p", {"class": "alerta-cron"}, "O cron não roda desde " + dataBr(ultCron, true) + " " + horaBr(ultCron) + ". Confira o crontab: " + linhaCron);
        } else {
          cron = el("p", {"class": "nota"}, "Cron rodando: última execução às " + horaBr(ultCron) + ".");
        }
        if (cfg("cron_erro") !== "") {
          cron += el("div", {"class": "erro-cron"}, el("strong", {}, "O cron está com erro") + el("pre", {}, h(cfg("cron_erro"))) + el("span", {"class": "nota"}, "Some sozinho na primeira execução sem erro."));
        }
        cron += el("p", {"class": "nota"}, el("a", {"href": "execucoes.php", "target": "_blank", "rel": "noopener"}, "Ver todas as execuções do cron") + ", com filtro de datas e busca.");
        if (cfg("cron_registro") !== "") {
          cron += el("details", {"class": "registro-cron"}, el("summary", {}, "Registro da última rodada") + el("pre", {}, h(cfg("cron_registro"))));
        }
        var geral = el("section", {"class": "cartao-config"}, el("h2", {}, "Geral") + el("div", {"class": "cadastro"},
          el("label", {}, "Manhã: relógio do dia e avisos " + el("input", {"type": "time", "name": "horario_manha", "value": cfg("horario_manha")}))
          + el("label", {}, "Noite: preparar o relógio de amanhã " + el("input", {"type": "time", "name": "horario_noite", "value": cfg("horario_noite")}))
          + el("label", {}, "Relógio no pulso a partir de " + el("input", {"type": "time", "name": "uso_inicio", "value": cfg("uso_inicio")}))
          + el("label", {}, "Até " + el("input", {"type": "time", "name": "uso_fim", "value": cfg("uso_fim")}))
          + el("label", {}, "Sessão no sol esquecida aberta fecha às " + el("input", {"type": "time", "name": "sol_fim", "value": d.sol_fim}))
          + el("label", {}, "Solar: pôr no sol quando a carga estimada chegar a (%) " + el("input", {"type": "number", "min": "1", "max": "99", "name": "sol_limiar", "value": cfg("sol_limiar") !== "" ? cfg("sol_limiar") : "70"}))
          + el("label", {}, "Gasto medido pelas leituras: média das medições dos últimos (dias) " + el("input", {"type": "number", "min": "1", "max": "3650", "name": "medicao_janela_dias",
            "value": cfg("medicao_janela_dias") !== "" ? cfg("medicao_janela_dias") : "90"}))
          + el("label", {}, "Endereço do sistema, para a âncora {link} " + el("input", {"name": "url_sistema", "value": cfg("url_sistema"), "placeholder": "http://servidor/relogios"}))
          + el("p", {"class": "nota"}, "O cron roda a cada minuto e faz cada rodada uma vez por dia, a partir dos horários da manhã e da noite. Fora do horário de uso, todos os relógios ficam desligados.")
          + cron));
        var telegram = el("section", {"class": "cartao-config"}, el("h2", {}, "Telegram (API de alerta)") + el("div", {"class": "cadastro"},
          el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "alerta_ativo", "value": "0"}) + el("input", {"type": "checkbox", "name": "alerta_ativo", "value": "1",
            "checked": cfg("mensagens_ativas") === "1"}) + " Enviar alertas")
          + el("p", {"class": "nota"}, "O endereço e o destinatário ficam no config.php. O texto das mensagens é montado pelos modelos abaixo, em \"Formato das mensagens\".")));
        var agenda = el("section", {"class": "cartao-config"}, el("h2", {}, "Google Agenda") + el("div", {"class": "cadastro"},
          el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "agenda_ativa", "value": "0"}) + el("input", {"type": "checkbox", "name": "agenda_ativa", "value": "1",
            "checked": cfg("agenda_ativa") === "1"}) + " Criar eventos na agenda")
          + el("label", {}, "ID da agenda " + el("input", {"name": "agenda_id", "value": cfg("agenda_id"), "placeholder": "xxxx@group.calendar.google.com"}))
          + el("label", {}, "Caminho da chave JSON no servidor " + el("input", {"name": "agenda_chave", "value": cfg("agenda_chave"), "placeholder": d.pasta + "/google-conta-servico.json"}))
          + (d.chave_agenda ? el("p", {"class": "nota"}, "Chave encontrada. Compartilhe a agenda com " + el("strong", {"class": "quebra"}, h(d.chave_agenda.client_email)) + ", com permissão para fazer alterações nos eventos.")
            : el("p", {"class": "nota"}, "Nenhuma chave válida nesse caminho. Confira o nome do arquivo e se o usuário do PHP-FPM (www-data) tem permissão de leitura."))
          + el("label", {}, "Criar os eventos com quantos dias de antecedência " + el("input", {"type": "number", "min": "1", "max": "365", "name": "agenda_antecedencia", "value": cfg("agenda_antecedencia")}))
          + el("div", {"class": "botoes-teste"}, el("button", {"type": "submit", "form": "f-teste-criar", "class": "leve"}, "Criar evento de teste")
            + el("button", {"type": "submit", "form": "f-teste-remover", "class": "leve", "disabled": cfg("agenda_teste_id") === ""}, "Remover evento de teste"))
          + el("p", {"class": "nota"}, "O teste cria um evento daqui a 10 minutos, já no formato dos modelos. Salve a configuração antes de testar.")));
        // ---------- Mensagem padrão ----------
        var modelos = Object.keys(d.canais).map(function (canal) {
          var cn = d.canais[canal];
          return el("div", {"class": "cartao-config"}, el("div", {"class": "campo-modelo"}, el("div", {"class": "rotulo-modelo"}, el("label", {"for": canal + "_padrao"}, el("h3", {}, h(cn.nome)))
            + listaAncoras(d, canal + "_padrao", "Inserir âncora na mensagem padrão do " + cn.nome))
            + el("textarea", {"id": canal + "_padrao", "name": canal + "_padrao", "rows": "7"}, h(cfg(canal + "_padrao"))) + el("p", {"class": "nota"}, h(cn.ajuda))));
        }).join("");
        var padrao = el("section", {}, el("h2", {}, "Mensagem padrão")
          + el("p", {"class": "nota"}, "Todo aviso usa a mensagem padrão do canal, a não ser que tenha a sua personalizada, marcada em \"O que vai para onde\". As âncoras entre chaves, como {relogio}, viram os valores na hora de enviar; \"Inserir âncora\" põe uma onde está o cursor.")
          + el("div", {"class": "modelos"}, modelos)
          + el("details", {"class": "lista-ancoras"}, el("summary", {}, "O que cada âncora vira") + el("table", {"class": "compacta"}, el("tbody", {}, Object.keys(d.ancoras).map(function (k) {
            return el("tr", {}, el("td", {}, el("code", {}, "{" + k + "}")) + el("td", {}, h(d.ancoras[k])));
          }).join("")))));
        // ---------- O que vai para onde ----------
        var canais = Object.keys(d.canais);
        var linhas = d.tipos.map(function (t) {
          var nome = h(t.nome);
          if (t.evento !== null) {
            nome += " " + el("span", {"class": "acoes-ev"}, el("a", {"href": "configuracao.php?evento=" + t.evento + "#personalizados"}, "editar") + " "
              + el("button", {"type": "submit", "form": "f-evento-excluir", "name": "evento_id", "value": t.evento, "class": "discreto",
                "onclick": "return confirm(" + JSON.stringify("Excluir o evento " + t.nome + "?") + ")"}, "excluir"));
          }
          var linha = el("td", {}, nome) + el("td", {}, h(t.quando)) + canais.map(function (canal) {
            return el("td", {"class": "centro"}, el("input", {"type": "checkbox", "name": d.canais[canal].tipos + "[]", "value": t.tipo, "checked": t.canais[canal].marcado,
              "aria-label": t.nome + " no " + d.canais[canal].nome}));
          }).join("") + el("td", {"class": "proprios"}, canais.map(function (canal) {
            return el("label", {"class": "check"}, el("input", {"type": "hidden", "name": canal + "_proprio_" + t.tipo, "value": "0"}) + el("input", {"type": "checkbox", "name": canal + "_proprio_" + t.tipo,
              "value": "1", "data-mostra": "bloco_" + canal + "_" + t.tipo, "checked": t.canais[canal].proprio}) + " " + h(d.canais[canal].nome));
          }).join(""));
          var corpo = el("td", {"colspan": 3 + canais.length}, canais.map(function (canal) {
            return el("div", {"class": "campo-modelo", "id": "bloco_" + canal + "_" + t.tipo, "hidden": !t.canais[canal].proprio}, el("div", {"class": "rotulo-modelo"},
              el("label", {"for": canal + "_corpo_" + t.tipo}, h(t.nome) + ", personalizado no " + h(d.canais[canal].nome)) + listaAncoras(d, canal + "_corpo_" + t.tipo, "Inserir âncora"))
              + el("textarea", {"id": canal + "_corpo_" + t.tipo, "name": canal + "_corpo_" + t.tipo, "rows": "3", "placeholder": "Vazio usa a mensagem padrão do " + d.canais[canal].nome}, h(t.canais[canal].corpo)));
          }).join(""));
          return el("tr", {"class": t.evento !== null ? "personalizado" : null}, linha) + el("tr", {"class": "linha-corpo"}, corpo);
        }).join("");
        var paraOnde = el("section", {}, el("h2", {}, "O que vai para onde")
          + el("p", {"class": "nota"}, "Marque \"Personalizar\" num canal para aquele aviso ter uma mensagem só dele, no lugar da mensagem padrão do canal. Cada canal tem a sua.")
          + canais.map(function (canal) { return el("input", {"type": "hidden", "name": d.canais[canal].tipos + "[]", "value": ""}); }).join("")
          + el("div", {"class": "rolagem"}, el("table", {"class": "relogios canais"}, el("thead", {}, el("tr", {}, el("th", {}, "Aviso") + el("th", {}, "Quando")
            + canais.map(function (canal) { return el("th", {"class": "centro"}, h(d.canais[canal].nome)); }).join("") + el("th", {}, "Personalizar"))) + el("tbody", {}, linhas))));
        res += el("form", {"data-recurso": "config", "id": "form-config"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
          + el("div", {"class": "config-grade"}, geral + telegram + agenda) + padrao + paraOnde
          + el("p", {"class": "novo-evento-link"}, el("a", {"href": "configuracao.php?novo_evento=1#personalizados", "class": "botao"}, "Novo evento") + " "
            + el("span", {"class": "nota"}, "Um aviso seu, com horário e repetição próprios: todo dia, em dias da semana, todo mês, a cada N dias ou uma vez só."))
          + el("div", {"class": "botoes salvar"}, el("button", {}, "Salvar configuração")));
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
          + el("label", {}, "Nome " + el("input", {"name": "nome", "value": fe.nome, "required": true, "placeholder": "Conferir a carga dos smartwatches"}))
          + el("label", {}, "Relógio (opcional: com relógio, as âncoras dele funcionam) " + el("select", {"name": "relogio_id"}, el("option", {"value": "0"}, "Nenhum, é um evento geral")
            + d.relogios.filter(function (r) { return r.disponivel || fe.relogio_id === r.id; }).map(function (r) {
              return el("option", {"value": r.id, "selected": fe.relogio_id === r.id}, h(r.nome) + (r.disponivel ? "" : " (indisponível)"));
            }).join("")))
          + el("label", {}, "Quando dispara " + el("select", {"name": "repeticao", "id": "ev-repeticao"}, Object.keys(d.repeticoes).map(function (k) {
            return el("option", {"value": k, "selected": fe.repeticao === k}, h(d.repeticoes[k]));
          }).join("")))
          + el("div", {"class": "linha-campos"}, el("label", {"data-rep": "uma intervalo"}, el("span", {"data-rep": "uma"}, "Data") + el("span", {"data-rep": "intervalo"}, "A partir de") + " "
              + el("input", {"type": "date", "name": "data_inicio", "value": fe.data_inicio || ""}))
            + el("label", {}, "Hora " + el("input", {"type": "time", "name": "hora", "value": fe.hora, "required": true}))
            + el("label", {"data-rep": "mensal"}, "Dia do mês " + el("input", {"type": "number", "min": "1", "max": "31", "name": "dia_mes", "value": fe.dia_mes || 1}))
            + el("label", {"data-rep": "intervalo"}, "A cada quantos dias " + el("input", {"type": "number", "min": "1", "name": "intervalo_dias", "value": fe.intervalo_dias || 30})))
          + el("fieldset", {"data-rep": "semanal", "class": "dias-semana"}, el("legend", {}, "Dias da semana") + ["", "seg", "ter", "qua", "qui", "sex", "sáb", "dom"].map(function (dn, n) {
            return n === 0 ? "" : el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "dias_semana[]", "value": n, "checked": diasFe.indexOf(String(n)) >= 0}) + " " + dn);
          }).join(""))
          + el("label", {"class": "check"}, el("input", {"type": "hidden", "name": "ativo", "value": "0"}) + el("input", {"type": "checkbox", "name": "ativo", "value": "1", "checked": fe.ativo}) + " Ativo")
          + (edit ? el("p", {"class": "nota"}, "Próximas vezes: " + (edit.proximas_60.length > 0 ? h(edit.proximas_60.map(function (t) { return dataBr(t, true) + " " + horaBr(t); }).join(", "))
            : "nenhuma nos próximos 60 dias") + ".") : "")
          + el("p", {"class": "nota"}, "Depois de salvar, o evento aparece na tabela \"O que vai para onde\", onde se escolhem os canais e o texto de cada um. Evento novo já vem marcado para o Telegram.")
          + el("div", {"class": "botoes"}, el("button", {}, edit ? "Salvar alterações" : "Criar evento") + (edit ? " " + el("a", {"href": "configuracao.php#personalizados"}, "cancelar") : ""));
        res += el("section", {"id": "personalizados"}, el("details", {"class": "cartao-config novo-evento", "open": edit !== null || q.has("novo_evento")},
          el("summary", {}, edit ? "Editar o evento " + h(edit.nome) : "Novo evento") + el("form", {"data-recurso": "config", "class": "cadastro form-evento"}, formEvento)));
        res += el("form", {"data-recurso": "config", "id": "f-teste-criar"}, el("input", {"type": "hidden", "name": "acao", "value": "teste_agenda_criar"}))
          + el("form", {"data-recurso": "config", "id": "f-teste-remover"}, el("input", {"type": "hidden", "name": "acao", "value": "teste_agenda_remover"}));
        // ---------- Como sai hoje ----------
        var p = d.previa;
        res += el("section", {}, el("h2", {}, "Como sai hoje") + el("div", {"class": "config-grade"},
          el("div", {"class": "previa-msg"}, el("h3", {}, "Telegram de manhã") + el("pre", {}, p.manha !== "" ? h(p.manha) : "(nada a enviar)")
            + el("form", {"data-recurso": "config"}, el("input", {"type": "hidden", "name": "acao", "value": "testar_manha"}) + el("button", {"class": "leve"}, "Enviar agora")))
          + el("div", {"class": "previa-msg"}, el("h3", {}, "Telegram à noite") + el("pre", {}, p.noite !== "" ? h(p.noite) : "(nada a enviar: amanhã continua o mesmo relógio)")
            + el("form", {"data-recurso": "config"}, el("input", {"type": "hidden", "name": "acao", "value": "testar_noite"}) + el("button", {"class": "leve"}, "Enviar agora")))
          + el("div", {"class": "previa-msg"}, el("h3", {}, "Agenda") + p.agenda.map(function (a) {
            return el("div", {"class": "previa-evento"}, el("div", {"class": "quando"}, dataBr(a.data, true) + "/" + a.data.substr(2, 2) + " " + h(a.hora)) + el("strong", {}, h(a.titulo))
              + (a.descricao !== "" ? el("pre", {}, h(a.descricao)) : ""));
          }).join("") + (p.agenda.length === 0 ? el("p", {"class": "nota"}, "Nada previsto no período.") : "")
            + el("p", {"class": "nota"}, p.sincronizados + " eventos criados pelo sistema estão na agenda. Mudou um modelo? Sincronize: os eventos já criados são atualizados.")
            + el("form", {"data-recurso": "config"}, el("input", {"type": "hidden", "name": "acao", "value": "sincronizar"}) + el("button", {"class": "leve"}, "Sincronizar agora")))));
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
  document.getElementById("configuracao").innerHTML = el("p", {"class": "acao"}, "Não consegui ler a configuração: " + h(e.message));
});
