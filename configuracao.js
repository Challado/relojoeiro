// Página Configuração: os dados da API (recurso=config), no HTML da Configuração do sistema antigo. Com o banco
// desatualizado (recurso=migracoes com pendentes), só o aviso e o botão de aplicar.
var recadoConfig = "";

// Um quadro da Configuração: o título, o conteúdo e, se houver, o pé (a explicação e os botões, sempre embaixo: os quadros
// da mesma linha têm a mesma altura)
function cartao(titulo, corpo, pe) {
  return el("section", {"class": "cartao-config"}, el("h2", {}, h(titulo)) + el("div", {"class": "cartao-corpo"}, corpo) + (pe ? el("div", {"class": "cartao-pe"}, pe) : ""));
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
      // a cópia de segurança é do sistema inteiro: só o master vê (os outros recebem 403, e o quadro não aparece)
      return Promise.all([api({recurso: "config"}), api({recurso: "backup"}).catch(function () { return null; })]).then(function (rs) {
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
        if (ce.erro) {
          cron += el("div", {"class": "erro-cron"}, el("strong", {}, t("O cron está com erro")) + el("pre", {}, h(ce.erro)) + el("span", {"class": "nota"}, t("Some sozinho na primeira execução sem erro.")));
        }
        cron += el("p", {"class": "nota"}, t("{1}, com filtro de datas e busca.", {1: el("a", {"href": "execucoes.php", "target": "_blank", "rel": "noopener"}, t("Ver todas as execuções do cron"))}));
        if (ce.registro) {
          cron += el("details", {"class": "registro-cron"}, el("summary", {}, t("Registro da última rodada")) + el("pre", {}, h(ce.registro)));
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
          + parCampo(t("Endereço do sistema (a âncora {link})"), d.sistema.pode_mudar ? el("input", {"name": "url_sistema", "value": d.sistema.url_sistema, "placeholder": "http://servidor/relogios"})
            : el("span", {}, d.sistema.url_sistema !== "" ? h(d.sistema.url_sistema) : t("(o do pedido; só o master muda)")), "largo")),
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
        // os destinos das mensagens e as agendas têm as páginas deles: aqui, o resumo e o caminho
        var listaAlvos = function (lista, campo, vazio) {
          return lista.length > 0 ? el("ul", {"class": "lista-simples"}, lista.map(function (x) {
            return el("li", {}, h(x.nome) + " " + el("span", {"class": "nota"}, "(" + h(x.tipo) + (x[campo] ? "" : ", " + t("desligado")) + ")"));
          }).join("")) : el("p", {"class": "nota"}, vazio);
        };
        var alertas = cartao(t("Mensagens e agendas"), el("div", {"class": "campos"},
          el("h3", {}, el("a", {"href": "destinos.php"}, t("Destinos das mensagens"))) + listaAlvos(d.destinos, "ativo", t("Nenhum ainda: as mensagens da manhã, da noite e os avisos não saem para lugar nenhum."))
          + el("h3", {}, el("a", {"href": "agendas.php"}, t("Agendas"))) + listaAlvos(d.agendas, "ativa", t("Nenhuma ainda."))),
          el("p", {"class": "nota"}, t("Um destino é um webhook (Telegram, n8n...), um e-mail ou o Web Push do navegador; uma agenda é o Google, um CalDAV (Nextcloud, iCloud, Fastmail), o Outlook ou um link ICS para assinar em qualquer app. Cada um escolhe o que recebe e tem a sua mensagem.")));
        // os eventos personalizados: editar e excluir
        var eventosLista = d.eventos.length === 0 ? el("p", {"class": "nota"}, t("Nenhum evento ainda."))
          : el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados"}, el("thead", {}, el("tr", {}, [t("Evento"), t("Quando"), t("Relógio"), t("Vai para"), ""].map(function (x) { return el("th", {}, x); }).join("")))
            + el("tbody", {}, d.eventos.map(function (ev) {
              return el("tr", {"class": ev.ativo ? null : "inativo"}, el("td", {}, h(ev.nome) + (ev.ativo ? "" : " " + el("span", {"class": "nota"}, t("(desligado)"))))
                + el("td", {}, h(ev.descricao)) + el("td", {}, ev.relogio ? h(ev.relogio) : "—")
                + el("td", {}, h(ev.destinos.concat(ev.agendas).join(", ")) || "—")
                + el("td", {"class": "acoes-linha"}, el("a", {"href": "configuracao.php?evento=" + ev.id + "#personalizados"}, t("Editar")) + " "
                  + el("button", {"type": "submit", "form": "f-evento-excluir", "name": "evento_id", "value": ev.id, "class": "discreto"}, t("Excluir"))));
            }).join(""))));
        res += el("form", {"data-recurso": "config", "id": "form-config"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
          + el("div", {"class": "config-grade"}, rotina + limites + tela + quadroCron + sorteios + alertas)
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
          + el("p", {"class": "nota"}, t("Evento novo já vai por todos os destinos da coleção. Em cada destino e cada agenda (páginas Destinos e Agendas), escolha se ele vai e com que texto."))
          + el("div", {"class": "botoes"}, el("button", {}, edit ? t("Salvar alterações") : t("Criar evento")) + (edit ? " " + el("a", {"href": "configuracao.php#personalizados"}, "cancelar") : ""));
        res += el("section", {"id": "personalizados"}, el("h2", {}, t("Eventos personalizados"))
          + el("p", {"class": "nota"}, t("Um aviso seu, com horário e repetição próprios: todo dia, em dias da semana, todo mês, a cada N dias ou uma vez só."))
          + eventosLista
          + el("details", {"class": "cartao-config novo-evento", "open": edit !== null || q.has("novo_evento")},
          el("summary", {}, edit ? t("Editar o evento {1}", {1: h(edit.nome)}) : t("Novo evento")) + el("form", {"data-recurso": "config", "class": "form-evento"}, formEvento)));
        // ---------- Cópia de segurança (desligada no config.php, BACKUP_ATIVO = false: o quadro nem aparece) ----------
        res += bk === null || bk.ativo === false ? "" : el("section", {"id": "copia"}, el("h2", {}, t("Cópia de segurança"))
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
  if (ev.target.matches("#ev-repeticao")) {
    mostrarRepeticao();
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
