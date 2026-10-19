// Página Configuração: os dados da API (recurso=config), no HTML da Configuração do sistema antigo. Com o banco
// desatualizado (recurso=migracoes com pendentes), só o aviso e o botão de aplicar.
// O idioma que veio do banco (não o idioma da interface): só uma escolha deliberada aqui muda este navegador.
var idiomaConfig = "";
var escolheuIdiomaConfig = false;
var chaveRetornoConfig = "relojoeiro:config:" + window.location.pathname;
var retornoConfig = null;
try {
  retornoConfig = JSON.parse(sessionStorage.getItem(chaveRetornoConfig) || "null");
} catch (e) {
  // armazenamento bloqueado: a mesma entrada do histórico também guarda o retorno
}
retornoConfig = retornoConfig || (history.state && history.state.retornoConfig);
if (!retornoConfig || retornoConfig.pagina !== window.location.pathname || Date.now() - retornoConfig.quando > 300000) {
  retornoConfig = null;
}
// No recarregamento por troca de idioma, o navegador não deve restaurar outra posição
// depois de a Configuração colocar o campo antigo no mesmo lugar.
if (retornoConfig && "scrollRestoration" in history) {
  history.scrollRestoration = "manual";
}

// A âncora é um campo ou um quadro, não só um número de pixels: a tradução pode mudar a altura dos textos.
function posicaoConfig() {
  var raiz = document.getElementById("configuracao");
  var p = {x: window.scrollX, y: window.scrollY, abertos: []};
  var distancia = Infinity;
  raiz.querySelectorAll(".par-campo, section.cartao-config").forEach(function (e) {
    var r = e.getBoundingClientRect();
    if (r.height > 0 && r.bottom > 0 && r.top < window.innerHeight && Math.abs(r.top) < distancia) {
      var campo = e.classList.contains("par-campo") ? e.querySelector("[name]") : null;
      p.campo = campo ? campo.name : null;
      p.quadro = campo ? null : Array.from(raiz.querySelectorAll("section.cartao-config")).indexOf(e);
      p.topo = r.top;
      distancia = Math.abs(r.top);
    }
  });
  raiz.querySelectorAll("details").forEach(function (e, i) { if (e.open) { p.abertos.push(i); } });
  return p;
}

function restaurarPosicaoConfig(p) {
  if (!p) { return Promise.resolve(); }
  var raiz = document.getElementById("configuracao");
  raiz.querySelectorAll("details").forEach(function (e, i) { e.open = (p.abertos || []).indexOf(i) >= 0; });
  return new Promise(function (resolve) {
    requestAnimationFrame(function () {
      var alvo = p.campo ? Array.from(raiz.querySelectorAll(".par-campo")).find(function (e) {
        var c = e.querySelector("[name]");
        return c && c.name === p.campo;
      }) : raiz.querySelectorAll("section.cartao-config")[p.quadro];
      var y = alvo && Number.isFinite(p.topo) ? window.scrollY + alvo.getBoundingClientRect().top - p.topo : p.y;
      window.scrollTo({left: p.x || 0, top: Math.max(0, y || 0), behavior: "instant"});
      resolve();
    });
  });
}

// Aviso sobre a página: não entra no fluxo, não toma o foco e não exige fechar para continuar usando o formulário.
function avisoConfig(mensagem, erro) {
  var antigo = document.getElementById("aviso-config");
  if (antigo) { antigo.remover(); }
  var caixa = document.createElement("div");
  caixa.id = "aviso-config";
  caixa.className = "cartao-config";
  caixa.style.cssText = "position:fixed;z-index:2000;left:1rem;right:1rem;bottom:calc(5rem + env(safe-area-inset-bottom, 0px));"
    + "max-width:32rem;max-height:40vh;overflow:auto;overflow-wrap:anywhere;margin:0 auto;padding:1rem;"
    + "display:flex;align-items:center;gap:1rem;background:var(--papel, white);color:var(--tinta, #202b36);"
    + "border:2px solid " + (erro ? "#9b2c22" : "var(--safira, #254a6d)") + ";box-shadow:0 4px 24px #0003;";
  var texto = document.createElement("span");
  texto.style.flex = "1";
  texto.setAttribute("role", erro ? "alert" : "status");
  texto.setAttribute("aria-live", erro ? "assertive" : "polite");
  texto.setAttribute("aria-atomic", "true");
  var fechar = document.createElement("button");
  fechar.type = "button";
  fechar.style.cssText = "min-width:44px;min-height:44px;flex-shrink:0";
  fechar.className = "leve";
  fechar.textContent = "×";
  fechar.setAttribute("aria-label", t("Fechar"));
  var timer;
  caixa.remover = function () { clearTimeout(timer); caixa.remove(); };
  fechar.addEventListener("click", caixa.remover);
  caixa.append(texto, fechar);
  document.body.appendChild(caixa);
  texto.textContent = mensagem;
  var agendar = function () { clearTimeout(timer); if (!erro) { timer = setTimeout(caixa.remover, 6000); } };
  caixa.addEventListener("mouseenter", function () { clearTimeout(timer); });
  caixa.addEventListener("mouseleave", agendar);
  caixa.addEventListener("focusin", function () { clearTimeout(timer); });
  caixa.addEventListener("focusout", agendar);
  agendar();
}

// Posição anterior ao foco do botão Salvar: importante quando a barra está presa ao
// rodapé, especialmente no celular com o teclado virtual aberto.
var posicaoAntesDoIdioma = null;
document.addEventListener("pointerdown", function (ev) {
  var botao = ev.target.closest && ev.target.closest("#form-config button[type=submit], #form-config button:not([type])");
  if (botao) {
    posicaoAntesDoIdioma = posicaoConfig();
  }
}, true);

// O envio já leva o idioma escolhido: o servidor devolve inclusive a confirmação traduzida. Só após o sucesso se muda
// o cookie, como no seletor do menu. Uma recusa não troca o idioma nem perde os campos digitados.
document.addEventListener("change", function (ev) {
  if (ev.target.matches("#form-config select[name=idioma]")) { escolheuIdiomaConfig = true; }
});
document.addEventListener("submit", function (ev) {
  var form = ev.target;
  if (ev.defaultPrevented || form.id !== "form-config") { return; }
  if (!posicaoAntesDoIdioma) { posicaoAntesDoIdioma = posicaoConfig(); }
  var c = form.querySelector("select[name=idioma]");
  var idioma = form.querySelector("input[data-idioma-config]");
  if (idioma) { idioma.remove(); }
  if (c && (escolheuIdiomaConfig || c.value !== idiomaConfig)) {
    idioma = document.createElement("input");
    idioma.type = "hidden";
    idioma.name = "lingua";
    idioma.value = c.value;
    idioma.setAttribute("data-idioma-config", "");
    form.appendChild(idioma);
  }
}, true);

function recarregarIdiomaConfig(lingua, mensagem, posicaoAnterior) {
  var estado = {pagina: window.location.pathname, quando: Date.now(), posicao: posicaoAnterior || posicaoConfig(), mensagem: mensagem};
  try { sessionStorage.setItem(chaveRetornoConfig, JSON.stringify(estado)); } catch (e) { /* usa o histórico */ }
  // Um lingua= antigo na URL tem prioridade sobre o cookie. Retira só esse parâmetro, preservando o resto e a âncora.
  var url = new URL(window.location.href);
  url.searchParams.delete("lingua");
  history.replaceState(Object.assign({}, history.state, {retornoConfig: estado}), "", url.href);
  document.cookie = "lingua=" + encodeURIComponent(lingua) + "; path=/; max-age=31536000; SameSite=Lax";
  document.cookie = "lingua_auto=; path=/; max-age=0; SameSite=Lax";
  // Preservar o lugar até a nova página terminar de carregar e montar os quadros.
  if ("scrollRestoration" in history) { history.scrollRestoration = "manual"; }
  window.location.reload();
}

// Um quadro da Configuração: o título, o conteúdo e, se houver, o pé (a explicação e os botões, sempre embaixo: os quadros
// da mesma linha têm a mesma altura)
function cartao(titulo, corpo, pe) {
  return el("section", {"class": "cartao-config"}, el("h2", {}, h(titulo)) + el("div", {"class": "cartao-corpo"}, corpo) + (pe ? el("div", {"class": "cartao-pe"}, pe) : ""));
}


// lê e monta a página: primeiro confere as migrações (com alguma pendente, só o aviso)
function recarregarConfig(aplicadas, manterPosicao) {
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
        idiomaConfig = c.idioma || "pt-BR";
        var cfg = function (k) { return c[k] === undefined ? "" : c[k]; };
        var res = "";
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
          + parCampo(t("Sessão no sol esquecida fecha às"), hora("sol_fim", d.sol_fim))
          // a semana do rodízio: o horizonte do plano, o ciclo, a próxima semana e os blocos contam dela
          + parCampo(t("A semana começa"), el("select", {"name": "semana_inicio"}, el("option", {"value": "segunda", "selected": cfg("semana_inicio") !== "domingo"}, t("Na segunda-feira"))
            + el("option", {"value": "domingo", "selected": cfg("semana_inicio") === "domingo"}, t("No domingo"))))),
          el("p", {"class": "nota"}, t("Sem <strong>pôr sozinho</strong>, o relógio do dia só entra no pulso pelo <strong>Pôs no pulso</strong>; sem <strong>tirar sozinho</strong>, só sai pelo ")
            + t("<strong>Tirou do pulso</strong> (esquecido, continua contando como no pulso). O horário de uso vale sempre para o plano e as previsões. Fora do pulso, o relógio conta como guardado.")
            + " " + t("A semana do rodízio começa no dia escolhido: o plano vem montado até o último dia da semana, e o ciclo, a próxima semana e o relógio para a semana inteira contam dela.")));
        var limites = cartao(t("Limites e contas"), el("div", {"class": "campos"},
          parCampo(t("Carregar quando a carga chegar a (%)"), numero("carga_limiar", cfg("carga_limiar") !== "" ? cfg("carga_limiar") : "20", "1", "99"))
          + parCampo(t("Solar: pôr no sol quando chegar a (%)"), numero("sol_limiar", cfg("sol_limiar") !== "" ? cfg("sol_limiar") : "70", "1", "99"))
          // como calcular o gasto da bateria: a escolha da coleção vale por todos os relógios; vazia, valem o relógio e o grupo
          // (o campo do cadastro "Como calcular o gasto da bateria")
          + parCampo(t("Gasto da bateria: como calcular (válido para todos os relógios)"), el("select", {"name": "gasto_motor"}, el("option", {"value": "", "selected": ["horas", "robusto", "marcacao", "cadastro", "formulas"].indexOf(cfg("gasto_motor")) < 0}, t("Individual (no relógio ou no grupo)"))
            + el("option", {"value": "horas", "selected": cfg("gasto_motor") === "horas"}, t("1. Pelas horas registradas (o motor original)"))
            + el("option", {"value": "robusto", "selected": cfg("gasto_motor") === "robusto"}, t("2. Pelas horas registradas, com leituras e cadastro (robusto)"))
            + el("option", {"value": "marcacao", "selected": cfg("gasto_motor") === "marcacao"}, t("3. Pela marcação: duas taxas separadas"))
            + el("option", {"value": "cadastro", "selected": cfg("gasto_motor") === "cadastro"}, t("4. Só o cadastro"))
            + el("option", {"value": "formulas", "selected": cfg("gasto_motor") === "formulas"}, t("5. Pelas fórmulas do cadastro"))))
          // o gasto medido conta as medições dos últimos dias ou as últimas leituras de cada tipo (em uso e em repouso)
          + parCampo(t("Gasto medido: contar por"), el("select", {"name": "medicao_base"}, el("option", {"value": "dias", "selected": cfg("medicao_base") !== "leituras"}, t("Dias (os últimos dias)"))
            + el("option", {"value": "leituras", "selected": cfg("medicao_base") === "leituras"}, t("Leituras (as últimas de cada tipo)"))))
          + parCampo(t("Gasto medido: média dos últimos (dias)"), numero("medicao_janela_dias", cfg("medicao_janela_dias") !== "" ? cfg("medicao_janela_dias") : "90", "1", "3650"))
          + parCampo(t("Gasto medido: últimas leituras (de cada tipo)"), numero("medicao_janela_leituras", cfg("medicao_janela_leituras") !== "" ? cfg("medicao_janela_leituras") : "20", "2", "1000"))
          // o Encostado: a caixa liga ou desliga o aviso inteiro (o aviso do cadastro, a coluna da Hoje, o filtro); os dias, o limite
          + parCampo(t("Avisar quando um relógio fica encostado"), marca("encostado_ativo", cfg("encostado_ativo") !== "0"), "check")
          + parCampo(t("Aviso Encostado: dias sem ir ao pulso"), numero("encostado_dias", cfg("encostado_dias") !== "" ? cfg("encostado_dias") : "30", "1", "3650"))
          // a cópia dos documentos no banco: a escolha da coleção vale sobre o relógio e o arquivo (antes era o config.php)
          + parCampo(t("Cópia dos documentos no banco (válido para todos os relógios)"), el("select", {"name": "documentos_copia"}, el("option", {"value": "", "selected": cfg("documentos_copia") !== "todos" && cfg("documentos_copia") !== "nenhum"}, t("Individual (no relógio ou no arquivo)"))
            + el("option", {"value": "todos", "selected": cfg("documentos_copia") === "todos"}, t("Todos os arquivos"))
            + el("option", {"value": "nenhum", "selected": cfg("documentos_copia") === "nenhum"}, t("Nenhum arquivo"))))),
          el("p", {"class": "nota"}, t("O limite de carga de um relógio (no cadastro dele) vale no lugar do geral. O gasto da bateria tem cinco motores (veja a Ajuda); a escolha aqui vale para todos os relógios da coleção, e vazia valem a do relógio e a do grupo (o campo \"Como calcular o gasto da bateria\" do cadastro). O gasto medido conta as medições dos últimos dias ou as últimas leituras de cada tipo (em uso e em repouso), conforme o \"contar por\"; as de fora saem da conta. A cópia dos documentos no banco escolhida aqui vale sobre o relógio e o arquivo. ")
            + (d.config_php_copia !== null ? el("strong", {"class": "em atrasado"}, t("O config.php ainda define DOCUMENTOS_COPIA_BANCO, que não vale mais: a cópia dos documentos é escolhida aqui, por coleção. ")) : "")
            + t("Um relógio da coleção que passa do limite de dias sem ir ao pulso ganha o aviso <strong>Encostado</strong>; a caixa desliga tudo (o aviso, a coluna da Hoje e o filtro: é o mesmo aviso Encostado da página Avisos).")));
        var tela = cartao(t("Telas"), el("div", {"class": "campos"},
          parCampo(t("Idioma padrão (quem não escolheu outro no menu)"), el("select", {"name": "idioma"}, d.linguas.map(function (l) {
            return el("option", {"value": l.codigo, "selected": (cfg("idioma") || "pt-BR") === l.codigo}, h(l.nome));
          }).join("")), "largo")
          // o fuso da coleção (quem não escolheu o seu em Minha conta; o cron, as mensagens e as agendas); vazio: o do sistema
          + parCampo(t("Fuso horário (quem não escolheu outro em Minha conta)"), el("select", {"name": "fuso"}, opcoesFuso(d.fusos, cfg("fuso"), t("O do sistema ({1})", {1: d.fuso_sistema.replace(/_/g, " ")}))))
          // o relógio da página Hora certa
          + parCampo(t("Relógio da Hora certa"), el("select", {"name": "hora_relogio"}, [["ambos", t("Analógico e digital (um ao lado do outro)")], ["analogico", t("Analógico")], ["digital", t("Digital")], ["anadigi", t("Anadigi (o visor digital dentro do analógico)")]].map(function (o) {
            return el("option", {"value": o[0], "selected": (cfg("hora_relogio") || "ambos") === o[0]}, o[1]);
          }).join("")), "largo")
          + parCampo(t("Abrir o relógio na página Hoje"), el("select", {"name": "painel_modo"}, el("option", {"value": "lado", "selected": cfg("painel_modo") !== "flutuante"}, t("Ao lado da lista"))
            + el("option", {"value": "flutuante", "selected": cfg("painel_modo") === "flutuante"}, t("Numa janela flutuante, grande"))))
          + parCampo(t("Destaque no alto da página Hoje"), el("select", {"name": "destaque_hoje"}, el("option", {"value": "dia", "selected": cfg("destaque_hoje") !== "pulso"}, t("O relógio do dia"))
            + el("option", {"value": "pulso", "selected": cfg("destaque_hoje") === "pulso"}, t("O relógio no pulso agora"))))
          + parCampo(t("Próximos dias (página Hoje): mostrar até (dias por página)"), numero("proximos_por_pagina", String(d.mostrar_ate.proximos), "1", "366"))
          + parCampo(t("Plano: mostrar até (dias por página)"), numero("plano_por_pagina", String(d.mostrar_ate.plano), "1", "366"))
          + parCampo(t("Intervalo de atualização das páginas (segundos; 0 desliga)"), numero("atualizar_segundos", String(d.atualizar_segundos), "0", "86400"))
          + parCampo(t("Endereço do sistema (a âncora {link})"), d.sistema.pode_mudar ? el("input", {"name": "url_sistema", "value": d.sistema.url_sistema, "placeholder": "http://servidor/relogios"})
            : el("span", {}, d.sistema.url_sistema !== "" ? h(d.sistema.url_sistema) : t("(o do pedido; só o master muda)")), "largo")),
          el("p", {"class": "nota"}, t("<strong>Ao lado da lista:</strong> o relógio clicado abre num painel à direita, e a lista continua à vista. <strong>Numa janela flutuante:</strong> abre grande, ")
            + t("no meio da tela, com os quadros lado a lado; fecha no ×, no Esc ou num clique fora. No celular, os dois cobrem a tela inteira."))
          + el("p", {"class": "nota"}, t("<strong>Destaque:</strong> o quadro grande no alto da página Hoje mostra o relógio do dia (o do plano) ou o que está no pulso agora, ")
            + t("mesmo que seja outro (o do dia aparece embaixo do nome). Sem nenhum relógio no pulso, fica o do dia."))
          + el("p", {"class": "nota"}, t("<strong>Intervalo de atualização:</strong> de quantos em quantos segundos a Hoje (com o painel), a Ficha, o Plano, os Totais e o Histórico leem tudo de novo sozinhos, sem recarregar a página (a carga, a autonomia, a última leitura, os avisos, os contadores). ")
            + t("De 1 a 86400; 0 desliga; padrão 60. Nunca por cima de quem está digitando ou tem algo mudado e não gravado."))
          + el("p", {"class": "nota"}, t("<strong>Mostrar até:</strong> quantos dias os Próximos dias (página Hoje, padrão 7) e o dia a dia da página Plano (padrão 31) mostram de cada vez. ")
            + t("Passando disso, embaixo aparecem as páginas: &lt; (anterior), os números e &gt; (próxima)."))
          + el("p", {"class": "nota"}, t("<strong>Fuso horário:</strong> o da coleção vale para quem não escolheu o seu em Minha conta, e para o cron, as mensagens e as agendas dela; vazio, o do sistema (o config.php). ")
            + t("Cada pessoa vê e digita as horas no fuso dela; no banco fica tudo no fuso do sistema. <strong>Relógio da Hora certa:</strong> o mostrador da página Hora certa: analógico, digital, anadigi (o visor digital dentro do mostrador) ou os dois, um ao lado do outro.")));
        var quadroCron = cartao(t("Cron"), cron);
        // de onde vêm os números dos sorteios (a API diz o último lote e se os dois serviços estão fora)
        var al = d.aleatorio;
        var sorteios = cartao(t("Sorteios"), el("div", {"class": "campos"},
          parCampo(t("Os números vêm de"), el("span", {}, t("1. RANDOM.ORG (ruído atmosférico) · 2. fora do ar, ANU QRNG (quântico) · 3. sem os dois, o gerador seguro do PHP")))
          + parCampo(t("Último lote"), el("span", {}, al.ultimo_lote ? h(al.ultimo_lote.replace(/ em (\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*/, " em $3/$2/$1 $4")) : t("nenhum ainda")))
          + (al.fora_desde ? parCampo(t("Serviços fora"), el("span", {"class": "acao"}, t("os dois sem responder desde {1} {2}; o sistema tenta de novo depois de 10 minutos", {1: dataBr(al.fora_desde), 2: horaBr(al.fora_desde)}))) : "")),
          el("p", {"class": "nota"}, t("Todo sorteio do sistema (o rodízio totalmente aleatório, o sorteio pela nota e o desempate de notas iguais na escala) usa estes números. Eles chegam num lote, e cada sorteio usa só o que precisa, sem viés.")));
        // os avisos, os lembretes e para onde vão (os destinos e das agendas) moram na página Avisos: aqui, o resumo e o caminho
        var alertas = cartao(t("Avisos, lembretes e mensagens"), el("div", {"class": "campos"},
          el("p", {}, t("{1} lembretes · {2} destinos · {3} agendas", {1: d.eventos.length, 2: d.destinos.length, 3: d.agendas.length}))),
          el("p", {"class": "nota"}, t("Os avisos dos relógios, os lembretes (os eventos personalizados) e para onde eles vão ficam juntos na página {1}.", {1: el("a", {"href": "avisos.php"}, t("Avisos"))})));
        res += el("form", {"data-recurso": "config", "id": "form-config"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
          + el("div", {"class": "config-grade"}, rotina + limites + tela + quadroCron + sorteios + alertas)
          // salvar: uma barra presa no pé da tela enquanto a página rola, com o botão sempre no mesmo lugar
          + el("div", {"class": "barra-salvar"}, el("span", {"class": "nota"}, t("As mudanças desta página valem depois de salvar.")) + el("button", {}, t("Salvar configuração"))));
        // ---------- Cópia de segurança (desligada no config.php, BACKUP_ATIVO = false: o quadro nem aparece) ----------
        res += bk === null || bk.ativo === false ? "" : el("section", {"id": "copia", "class": "cartao-config"}, el("h2", {}, t("Cópia de segurança"))
          + (bk.pasta_ok
            ? el("p", {"class": "nota"}, t("Uma cópia do banco inteiro por dia, na rodada da noite, guardando as {1} mais novas, {2} o conteúdo dos documentos (os arquivos já ficam na pasta deles). A cópia volta em qualquer um dos três bancos, na mesma versão do sistema. {3}", {1: bk.manter, 2: bk.documentos ? "com" : "sem", 3: bk.ultima ? t("Última: {1} {2}.", {1: dataBr(bk.ultima), 2: horaBr(bk.ultima)}) : t("Nenhuma feita ainda.")}))
            : el("p", {"class": "alerta-cron"}, t("A cópia automática está desligada: {1}. Ponha no config.php a BACKUP_PASTA, uma pasta do servidor fora da pasta publicada.", {1: h(bk.pasta_erro)})))
          + (bk.copias.length > 0 ? el("div", {"class": "rolagem"}, el("table", {"class": "compacta"}, el("thead", {}, el("tr", {}, [t("Cópia"), t("Feita em"), t("Tamanho"), t("Documentos"), ""].map(function (item) { return el("th", {}, item); }).join("")), "largo")
            + el("tbody", {}, bk.copias.map(function (x) {
              return el("tr", {}, el("td", {}, el("code", {}, h(x.arquivo))) + el("td", {}, x.criada ? dataBr(x.criada) + " " + horaBr(x.criada) : "—") + el("td", {}, h(x.tamanho))
                + el("td", {}, x.documentos ? "com" : "sem") + el("td", {"class": "acoes-linha"}, el("a", {"href": "api.php?recurso=backup&arquivo=" + encodeURIComponent(x.arquivo), "class": "botao leve"}, t("Baixar")) + " "
                + el("form", {"data-recurso": "backup", "onsubmit": "return confirm(" + JSON.stringify(t("Voltar o banco para a cópia de {1}? Tudo o que foi feito depois some. Antes, o sistema guarda uma cópia do que está agora.", {1: x.criada ? dataBr(x.criada) + " " + horaBr(x.criada) : x.arquivo})) + ")"}, el("input", {"type": "hidden", "name": "acao", "value": "restaurar"})
                  + el("input", {"type": "hidden", "name": "arquivo", "value": x.arquivo}) + el("button", {"class": "discreto"}, t("Restaurar")))));
            }).join("")), "largo")) : "")
          + (bk.pasta_ok ? el("form", {"data-recurso": "backup"}, el("input", {"type": "hidden", "name": "acao", "value": "fazer"}) + el("div", {"class": "botoes"}, el("button", {"class": "leve"}, t("Fazer uma cópia agora")))) : ""));
        document.getElementById("configuracao").innerHTML = res;
        if (!manterPosicao && !retornoConfig && window.location.hash) {
          var alvo = document.getElementById(window.location.hash.slice(1));
          if (alvo) {
            alvo.scrollIntoView();
          }
        }
      });
    }
  });
}

// Salvar a configuração não remonta o formulário: mantém rolagem, foco, quadros abertos e edições ainda em andamento.
// Ao trocar o idioma, a página inteira (inclusive o menu PHP) é traduzida, voltando à mesma âncora depois de pronta.
aoGravar = function (form, res, dados) {
  var posicaoSalvar = typeof posicaoDaGravacao === "function" ? posicaoDaGravacao(form) : null;
  var posicaoIdioma = posicaoAntesDoIdioma || posicaoConfig();
  posicaoAntesDoIdioma = null;
  form.querySelectorAll("button").forEach(function (b) { b.disabled = false; });
  if (!res.ok) {
    avisoConfig(textoResposta(res), true);
    return posicaoSalvar ? restaurarPosicaoDaGravacao(posicaoSalvar) : null;
  }
  if (dados.get("recurso") === "config" && dados.get("acao") === "salvar") {
    idiomaConfig = dados.get("idioma") || idiomaConfig;
    escolheuIdiomaConfig = false;
    if (dados.get("lingua")) {
      document.cookie = "lingua=" + encodeURIComponent(dados.get("lingua")) + "; path=/; max-age=31536000; SameSite=Lax";
      document.cookie = "lingua_auto=; path=/; max-age=0; SameSite=Lax";
    }
    if (dados.get("lingua") && dados.get("lingua") !== LINGUA) {
      if (posicaoSalvar) {
        return restaurarPosicaoDaGravacao(posicaoSalvar).then(function () {
          recarregarIdiomaConfig(dados.get("lingua"), textoResposta(res), posicaoIdioma);
        });
      }
      recarregarIdiomaConfig(dados.get("lingua"), textoResposta(res), posicaoIdioma);
      return;
    }
    avisoConfig(textoResposta(res), false);
    return posicaoSalvar ? restaurarPosicaoDaGravacao(posicaoSalvar) : null;
  }
  // Cópias e migrações precisam atualizar suas listas, mas não mandar a pessoa para o começo da página.
  var posicao = posicaoConfig();
  return recarregarConfig(dados.get("recurso") === "migracoes" ? (res.aplicadas || [textoResposta(res)]) : [], true)
    .then(function () { return posicaoSalvar ? restaurarPosicaoDaGravacao(posicaoSalvar) : restaurarPosicaoConfig(posicao); })
    .then(function () { avisoConfig(textoResposta(res), false); })
    .catch(function (e) {
      avisoConfig(textoResposta(res) + " " + t("Não consegui ler a configuração: {1}", {1: e.message}), true);
    });
};
// os lembretes saíram daqui: moram na página Avisos (o endereço antigo de um lembrete leva para lá)
(function () {
  var q = new URLSearchParams(window.location.search);
  if (q.get("evento") || window.location.hash === "#personalizados") {
    window.location.replace("avisos.php" + (q.get("evento") ? "?evento=" + encodeURIComponent(q.get("evento")) : "") + "#lembretes");
  }
})();

recarregarConfig([]).then(function () {
  if (!retornoConfig || !document.getElementById("form-config")) { return; }
  var retorno = retornoConfig;
  retornoConfig = null;
  try { sessionStorage.removeItem(chaveRetornoConfig); } catch (e) { /* armazenamento bloqueado */ }
  var estado = Object.assign({}, history.state);
  delete estado.retornoConfig;
  history.replaceState(estado, "");
  return restaurarPosicaoConfig(retorno.posicao).then(function () {
    avisoConfig(retorno.mensagem, false);
    // A tradução pode alterar a altura dos quadros; fontes e rolagem nativa do
    // navegador podem terminar DEPOIS do primeiro requestAnimationFrame.
    // Reafirma a posição por um curto intervalo, salvo se a pessoa começar a rolar.
    var ativo = true;
    var prazos = [];
    var eventos = ["pointerdown", "wheel", "touchstart", "keydown"];
    var parar = function () {
      ativo = false;
      prazos.forEach(clearTimeout);
      eventos.forEach(function (nome) { removeEventListener(nome, parar); });
      if ("scrollRestoration" in history) { history.scrollRestoration = "auto"; }
    };
    eventos.forEach(function (nome) { addEventListener(nome, parar, {passive: true}); });
    var reaplicar = function () { if (ativo) { restaurarPosicaoConfig(retorno.posicao); } };
    [90, 280, 650].forEach(function (ms) { prazos.push(setTimeout(reaplicar, ms)); });
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(reaplicar); }
    prazos.push(setTimeout(parar, 950));
  });
}).catch(function (e) {
  document.getElementById("configuracao").innerHTML = el("p", {"class": "acao"}, t("Não consegui ler a configuração: {1}", {1: h(e.message)}));
});
