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

// Distribui cartões e opções do sistema pela altura real, sem mudar a ordem dos elementos e do foco.
var observadorQuadrosConfig = null;
var quadroAnimacaoConfig = null;
var ajustarQuadrosConfig = null;

function agendarQuadrosConfig() {
  if (quadroAnimacaoConfig === null) {
    quadroAnimacaoConfig = requestAnimationFrame(function () {
      quadroAnimacaoConfig = null;
      if (ajustarQuadrosConfig) { ajustarQuadrosConfig(); }
    });
  }
}

function organizarQuadrosConfig() {
  if (observadorQuadrosConfig) { observadorQuadrosConfig.disconnect(); observadorQuadrosConfig = null; }
  if (quadroAnimacaoConfig !== null) { cancelAnimationFrame(quadroAnimacaoConfig); quadroAnimacaoConfig = null; }
  var grades = Array.from(document.querySelectorAll("#form-config .config-grade, #form-config .parametros-sistema"));
  ajustarQuadrosConfig = null;
  if (!grades.length) { return; }
  var quadros = grades.reduce(function (todos, grade) { return todos.concat(Array.from(grade.children)); }, []);
  ajustarQuadrosConfig = function () {
    grades.forEach(function (grade) {
      var filhos = Array.from(grade.children);
      var alturas = filhos.map(function (q) { return q.getBoundingClientRect().height; });
      grade.classList.add("config-colunas");
      var estilo = getComputedStyle(grade);
      var linha = parseFloat(estilo.gridAutoRows);
      var intervalo = parseFloat(estilo.columnGap) || 0;
      if (!linha) { grade.classList.remove("config-colunas"); return; }
      filhos.forEach(function (q, i) {
        var span = "span " + Math.ceil((alturas[i] + intervalo) / linha);
        if (q.style.gridRowEnd !== span) { q.style.gridRowEnd = span; }
      });
    });
  };
  ajustarQuadrosConfig();
  if (typeof ResizeObserver === "function") {
    // O observador roda antes da pintura: adiar mais um quadro deixa o conteúdo aberto sobrepor o vizinho.
    observadorQuadrosConfig = new ResizeObserver(function () {
      if (ajustarQuadrosConfig) { ajustarQuadrosConfig(); }
    });
    quadros.forEach(function (q) { observadorQuadrosConfig.observe(q); });
  }
}

addEventListener("resize", agendarQuadrosConfig);
document.addEventListener("toggle", function (ev) {
  if (ajustarQuadrosConfig && ev.target instanceof Element && ev.target.closest("#form-config")) { ajustarQuadrosConfig(); }
}, true);
if (document.fonts) { document.fonts.ready.then(agendarQuadrosConfig); }

// Os níveis da página: as opções das três hierarquias desta página (a Rotina do dia, os Limites e contas e as Telas; as do
// rodízio ficam em Rodízio › Ajustes do rodízio, e as dos avisos, na página Avisos) existem na coleção, no dono (o padrão dele
// para as coleções de que é dono) e no sistema (os Padrões do sistema); lado a lado, cada opção apareceria três vezes. A página
// mostra um nível por vez, pelas abas do alto (as do api.js: abasNiveis): "colecao" (o que vale nesta coleção), "dono" (Minhas
// coleções: só o dono da coleção aberta tem) ou "sistema" (o padrão do sistema: só o master tem). Quem não é dono nem master
// não tem outro nível para mudar: vê tudo numa página só. O nível vai no endereço (nivel=colecao, dono ou sistema), para a
// página voltar no mesmo depois de recarregar (ao trocar o idioma). O que é do sistema inteiro (as senhas, o cron, os
// parâmetros, a cópia de segurança, o SQL) fica na página Sistema
var NIVEIS_CONFIG = NIVEIS_OPCOES;
var nivelConfig = (function () {
  var pedido = (/(?:^|[?&])nivel=(sistema|dono|colecao)(?:&|$)/.exec(window.location.search) || [])[1];
  return pedido || "colecao";
}());
function noNivel(html, nivel) {
  if (!html) { return html; }
  // o quadro marcado pelo próprio navegador (e não por uma troca de texto, que dependeria de como o el() escreve a marca)
  var molde = document.createElement("div");
  molde.innerHTML = html;
  if (molde.firstElementChild) { molde.firstElementChild.setAttribute("data-nivel", nivel); }
  return molde.innerHTML;
}
function verNivelConfig(nivel) {
  var raiz = document.getElementById("configuracao");
  if (!raiz || !raiz.querySelector(".abas-nivel")) { return; }
  // só um nível que a página tem (a aba dele existe); senão, o da coleção
  nivelConfig = NIVEIS_CONFIG.indexOf(nivel) >= 0 && raiz.querySelector('.abas-nivel a[data-nivel-ir="' + nivel + '"]') ? nivel : "colecao";
  raiz.setAttribute("data-nivel-visto", nivelConfig);
  raiz.querySelectorAll(".abas-nivel a").forEach(function (a) {
    var ativa = a.getAttribute("data-nivel-ir") === nivelConfig;
    a.classList.toggle("ativa", ativa);
    if (ativa) { a.setAttribute("aria-current", "page"); } else { a.removeAttribute("aria-current"); }
  });
  if (ajustarQuadrosConfig) { ajustarQuadrosConfig(); }
}
document.addEventListener("click", function (ev) {
  var a = ev.target.closest ? ev.target.closest(".abas-nivel a[data-nivel-ir]") : null;
  if (!a) { return; }
  // sem recarregar: o que já foi digitado nos dois níveis continua na página, e o Salvar grava tudo de uma vez
  ev.preventDefault();
  verNivelConfig(a.getAttribute("data-nivel-ir"));
  // o nível escolhido fica no endereço (o resto dele, a âncora e o que a página guarda no histórico continuam)
  var url = new URL(window.location.href);
  url.searchParams.set("nivel", nivelConfig);
  history.replaceState(history.state, "", url.href);
});

function cartao(titulo, corpo, pe, explica) {
  return el("section", {"class": "cartao-config"}, el("h2", {}, h(titulo) + (explica || "")) + el("div", {"class": "cartao-corpo"}, corpo) + (pe ? el("div", {"class": "cartao-pe"}, pe) : ""));
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
      organizarQuadrosConfig();
    } else {
      return api({recurso: "config"}).then(function (d) {
        var c = d.config;
        idiomaConfig = c.idioma || "pt-BR";
        var cfg = function (k) { return c[k] === undefined ? "" : c[k]; };
        // as opções das três hierarquias (sistema → usuário → coleção), por chave
        var oc = {};
        d.opcoes.opcoes.forEach(function (o) { oc[o.chave] = o; });
        // as explicações de cada opção ficam na gaveta (o ⓘ ao lado de cada uma e o botão Explicações do título)
        ajudaGrupo("pagina", t("Configuração"));
        var ex = function (chave, titulo, html) { return ajuda(chave, titulo, el("p", {}, html)); };
        var res = el("div", {"class": "titulo-secao"}, el("h1", {}, t("Configuração")) + ajudaBotao());
        // os níveis da página (o do dono, só o dono desta coleção tem; o do sistema, só o master): as abas, e a nota do que cada um guarda
        var temDono = !!(d.dono && d.dono.pode_mudar === true);
        var temSistema = d.sistema.pode_mudar === true;
        var comNiveis = temDono || temSistema;
        if ((nivelConfig === "dono" && !temDono) || (nivelConfig === "sistema" && !temSistema)) { nivelConfig = "colecao"; }
        if (comNiveis) {
          res += abasNiveis(nivelConfig, temDono, temSistema);
        }
        // ---------- os quadros do alto: cada opção numa linha, o rótulo à esquerda e o campo à direita, todos alinhados ----------
        var rotina = cartao(t("Rotina do dia"), el("div", {"class": "campos"},
          campoOpcao(oc.horario_manha, "horario_manha", null)
          + campoOpcao(oc.horario_noite, "horario_noite", null)
          + campoOpcao(oc.uso_inicio, "uso_inicio", null)
          + campoOpcao(oc.pulso_auto_inicio, "pulso_auto_inicio", null, ex("pulso-auto", t("Pôr e tirar do pulso sozinho"), t("Sem <strong>pôr sozinho</strong>, o relógio do dia só entra no pulso pelo <strong>Pôs no pulso</strong>; sem <strong>tirar sozinho</strong>, só sai pelo ")
              + t("<strong>Tirou do pulso</strong> (esquecido, continua contando como no pulso). O horário de uso vale sempre para o plano e as previsões. Fora do pulso, o relógio conta como guardado.")))
          + campoOpcao(oc.uso_fim, "uso_fim", null)
          + campoOpcao(oc.pulso_auto_fim, "pulso_auto_fim", null, ajudaRef("pulso-auto"))
          + campoOpcao(oc.pulso_um_so, "pulso_um_so", null, ex("pulso-um-so", t("Um relógio só no pulso"), t("Ligado, ao marcar <strong>Pôs no pulso</strong> num relógio (na tela, no atalho ou pela API), os outros que estão no pulso saem no mesmo instante: ")
              + t("não fica um esquecido contando horas. Desligado, cada relógio só sai pelo <strong>Tirou do pulso</strong> dele (dá para ter dois no pulso). O relógio do dia que o sistema põe sozinho não tira ninguém.")))
          + campoOpcao(oc.sol_fim, "sol_fim", null)
          + campoOpcao(oc.sol_auto_fim, "sol_auto_fim", null, ex("sol-auto", t("Tirar do sol sozinho"), t("Sem <strong>tirar do sol sozinho</strong>, o solar só sai do sol pelo <strong>Tirar do sol</strong> (esquecido, continua carregando na conta).")))));
        var limites = cartao(t("Limites e contas"), el("div", {"class": "campos"},
          campoOpcao(oc.carga_limiar, "carga_limiar", null, ex("limite", t("O limite de carga"), t("O limite de carga de um relógio (no cadastro dele) vale no lugar do geral.")))
          + campoOpcao(oc.sol_limiar, "sol_limiar", null)
          + campoOpcao(oc.previsao_limite, "previsao_limite", null)
          // como calcular o gasto da bateria: a escolha da coleção vale por todos os relógios; vazia, valem o relógio e o grupo
          // (o campo do cadastro "Como calcular o gasto da bateria")
          + avancado("config.gasto", campoOpcao(oc.gasto_motor, "gasto_motor", null, ex("motor", t("O gasto da bateria"), t("O gasto da bateria tem cinco motores (veja a Ajuda); a escolha aqui vale para todos os relógios da coleção, e vazia valem a do relógio e a do grupo (o campo \"Como calcular o gasto da bateria\" do cadastro)."))))
          // o gasto medido conta as medições dos últimos dias ou as últimas leituras de cada tipo (em uso e em repouso)
          + avancado("config.gasto", campoOpcao(oc.medicao_base, "medicao_base", null, ex("medido", t("O gasto medido"), t("O gasto medido conta as medições dos últimos dias ou as últimas leituras de cada tipo (em uso e em repouso), conforme o \"contar por\"; as de fora saem da conta."))))
          + avancado("config.gasto", campoOpcao(oc.medicao_janela_dias, "medicao_janela_dias", null))
          + avancado("config.gasto", campoOpcao(oc.medicao_janela_leituras, "medicao_janela_leituras", null))
          + avancado("config.gasto", campoOpcao(oc.medicao_meia_vida_dias, "medicao_meia_vida_dias", null, ex("idade", t("O gasto medido"), t("Com meia-vida de N dias, uma leitura de N dias atrás pesa metade de uma atual. A janela continua limitando a idade dos dados; 0 mantém pesos iguais por idade. Os cinco motores continuam disponíveis."))))
          // a cópia dos documentos no banco: a escolha da coleção vale sobre o relógio e o arquivo (antes era o config.php)
          + campoOpcao(oc.documentos_copia, "documentos_copia", null, ex("copia", t("A cópia dos documentos no banco"), t("A cópia dos documentos no banco escolhida aqui vale sobre o relógio e o arquivo.")))),
          // o aviso que precisa ficar à vista: o config.php com a opção que não vale mais
          d.config_php_copia !== null ? el("p", {"class": "nota"}, el("strong", {"class": "em atrasado"}, t("O config.php ainda define DOCUMENTOS_COPIA_BANCO, que não vale mais: a cópia dos documentos é escolhida aqui, por coleção. "))) : "");
        var tela = cartao(t("Telas"), el("div", {"class": "campos"},
          parCampo(t("Idioma padrão (quem não escolheu outro no menu)"), el("select", {"name": "idioma"}, d.linguas.map(function (l) {
            return el("option", {"value": l.codigo, "selected": (cfg("idioma") || "pt-BR") === l.codigo}, h(l.nome));
          }).join("")), "largo")
          // o fuso da coleção (quem não escolheu o seu em Minha conta; o cron, as mensagens e as agendas); vazio: o do sistema
          + parCampo(t("Fuso horário (quem não escolheu outro em Minha conta)"), el("select", {"name": "fuso"}, opcoesFuso(d.fusos, cfg("fuso"), t("O do sistema ({1})", {1: d.fuso_sistema.replace(/_/g, " ")}))), null,
            ex("fuso", t("Fuso horário"), t("O da coleção vale para quem não escolheu o seu em Minha conta, e para o cron, as mensagens e as agendas dela; vazio, o do sistema (o config.php). ")
              + t("Cada pessoa vê e digita as horas no fuso dela; no banco fica tudo no fuso do sistema.")))
          // o relógio da página Hora certa
          + campoOpcao(oc.hora_relogio, "hora_relogio", "largo", ex("hora", t("Relógio da Hora certa"), t("O mostrador da página Hora certa: analógico, digital, anadigi (o visor digital dentro do mostrador) ou os dois, um ao lado do outro.")))
          + campoOpcao(oc.painel_modo, "painel_modo", null, ex("painel", t("Abrir o relógio"), t("<strong>Ao lado da lista:</strong> o relógio clicado abre num painel à direita, e a lista continua à vista. <strong>Numa janela flutuante:</strong> abre grande, ")
              + t("no meio da tela, com os quadros lado a lado; fecha no ×, no Esc ou num clique fora. No celular, os dois cobrem a tela inteira.")))
          + campoOpcao(oc.destaque_hoje, "destaque_hoje", null, ex("destaque", t("Destaque"), t("O quadro grande no alto da página Hoje mostra o relógio do dia (o do plano) ou o que está no pulso agora, ")
              + t("mesmo que seja outro (o do dia aparece embaixo do nome). Sem nenhum relógio no pulso, fica o do dia.")))
          + campoOpcao(oc.abas_vazias, "abas_vazias", "largo", ex("abas-vazias", t("Abas vazias"), t("As abas em cima da tabela dos relógios (páginas Hoje e Galeria) são as situações do cadastro: Na coleção, Emprestado, Desejado, Vendido... ")
              + t("<strong>Mostrar sempre:</strong> todas aparecem, e a da situação sem relógio fica desabilitada (apagada, sem clique), com o motivo. <strong>Esconder:</strong> só aparecem as situações que têm relógio; com todos os relógios numa situação só, a faixa de abas some.")))
          + campoOpcao(oc.sugestao_agora, "sugestao_agora", null, ex("sugestao-agora", t("Sugestão para agora"), t("Ligada, a página Hoje ganha o cartão <strong>Sugestão para agora</strong>, entre os avisos de hoje e os Próximos dias: você escolhe a forma de escolha ")
              + t("(a maior nota, o sorteio, a fila, o menos usado) e os tipos de relógio, e o sistema sugere um para usar agora, fora da escala. Ficam de fora o que está no pulso e o que está com a carga no limite ou abaixo dele. O plano não muda: pôr o sugerido no pulso é um uso fora do plano.")))
          // o formulário do calibre (Cadastros › Calibres): só os campos do movimento (a marca do campo) ou todos
          + campoOpcao(oc.calibre_campos, "calibre_campos", "largo")
          // o reloginho da barra (e da aba): um modelo por hora do dia, na ordem, sorteado ou sorteado sem repetir
          + campoOpcao(oc.relojinho_modo, "relojinho_modo", "largo")
          + campoOpcao(oc.proximos_por_pagina, "proximos_por_pagina", null, ex("mostrar", t("Mostrar até"), t("Quantos dias os Próximos dias (página Hoje, padrão 7) e o dia a dia da página Plano (padrão 31) mostram de cada vez. ")
              + t("Passando disso, embaixo aparecem as páginas: &lt; (anterior), os números e &gt; (próxima).")))
          + campoOpcao(oc.plano_por_pagina, "plano_por_pagina", null, ajudaRef("mostrar"))
          + campoOpcao(oc.atualizar_segundos, "atualizar_segundos", null, ex("atualizar", t("Intervalo de atualização"), t("De quantos em quantos segundos a Hoje (com o painel), a Ficha, o Plano, os Totais e o Histórico leem tudo de novo sozinhos, sem recarregar a página (a carga, a autonomia, a última leitura, os avisos, os contadores). ")
              + t("De 1 a 86400; 0 desliga; padrão 60. Nunca por cima de quem está digitando ou tem algo mudado e não gravado.")))
          // as colunas da tabela dos relógios da página Hoje (o motor de colunas): quais e em que ordem
          + campoOpcao(oc.colunas_hoje, "colunas_hoje", "largo", ex("colunas", t("Colunas da tabela dos relógios"), t("Quais colunas a tabela da página Hoje mostra e em que ordem: marque as que quer ver e ponha na ordem com ▲ e ▼ (a Relógio sempre aparece). ")
              + t("Herdar volta a valer a lista do nível acima (a de Minha conta ou a do sistema; sem nenhuma, todas, na ordem de sempre). Na própria página Hoje, o botão Colunas faz a mesma escolha.")))));
        // o padrão do sistema de cada opção da coleção (só o master): cada usuário e cada coleção herda, e pode escolher a sua
        var padroes = d.sistema.pode_mudar && d.sistema.padroes ? cartao(t("Padrões do sistema"), el("div", {"class": "campos parametros-sistema"}, camposOpcoes(d.sistema.padroes, "padroes")), null,
          ex("padroes", t("Padrões do sistema"), t("O padrão de cada opção desta página: horários, limites, gasto da bateria e telas. Vale em toda coleção que não escolheu a sua e cujo dono não escolheu o dele (na aba Minhas coleções); vazio, vale o padrão de fábrica. O mais específico prevalece: a coleção, depois o dono, depois o sistema."))) : "";
        // os avisos, os lembretes e para onde vão (os destinos e das agendas) moram na página Avisos: aqui, o resumo e o caminho
        var alertas = cartao(t("Avisos, lembretes e mensagens"), el("div", {"class": "campos"},
          el("p", {}, t("{1} lembretes · {2} destinos · {3} agendas", {1: d.eventos.length, 2: d.destinos.length, 3: d.agendas.length}))),
          el("p", {"class": "nota"}, t("Os avisos dos relógios, os lembretes (os eventos personalizados) e para onde eles vão ficam juntos na página {1}.", {1: el("a", {"href": "avisos.php"}, t("Avisos"))})));
        // o padrão do dono para as coleções dele (só o dono desta coleção): cada opção desta página, gravada em dono[<chave>] pelo
        // config/salvar
        var meus = temDono ? cartao(t("Minhas coleções"), el("div", {"class": "campos parametros-sistema"}, camposOpcoes(d.dono.opcoes, "dono"))) : "";
        if (comNiveis) {
          rotina = noNivel(rotina, "colecao"); limites = noNivel(limites, "colecao"); tela = noNivel(tela, "colecao"); alertas = noNivel(alertas, "colecao");
          meus = noNivel(meus, "dono"); padroes = noNivel(padroes, "sistema");
        }
        res += el("form", {"data-recurso": "config", "id": "form-config"}, el("input", {"type": "hidden", "name": "acao", "value": "salvar"})
          + el("div", {"class": "config-grade"}, rotina + limites + tela + alertas)
          + meus + padroes
          // salvar: uma barra presa no pé da tela enquanto a página rola, com o botão sempre no mesmo lugar
          + el("div", {"class": "barra-salvar"}, el("span", {"class": "nota"}, t("As mudanças desta página valem depois de salvar.")) + el("button", {}, t("Salvar configuração"))));
        document.getElementById("configuracao").innerHTML = res;
        if (comNiveis) {
          document.getElementById("configuracao").setAttribute("data-nivel-visto", nivelConfig);
        } else {
          document.getElementById("configuracao").removeAttribute("data-nivel-visto");
        }
        organizarQuadrosConfig();
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
// os lembretes saíram daqui: moram na página Avisos (o endereço antigo de um lembrete leva para lá); a cópia de segurança e o
// SQL também: moram na página Sistema
(function () {
  if (/^#(copia|sql)$/.test(window.location.hash)) {
    window.location.replace("sistema.php" + window.location.hash);
    return;
  }
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
  organizarQuadrosConfig();
});
