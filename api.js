// A base das páginas: todo dado vem do api.php e toda gravação vai para ele. As páginas só montam o HTML.
// O login do site (HTTP Basic) vai junto em cada pedido, pelo próprio navegador.

// Os idiomas: todo texto das telas passa pela função t (o texto em português e os parâmetros entre chaves); o dicionário vem da API
// (recurso=textos) antes de qualquer tela ser montada (o api() espera por ele). O que falta no dicionário sai em português.
// O idioma é o do pedido: o seletor do menu (o cookie lingua), senão o padrão da instalação, senão o do navegador.
// O dicionário chega antes deste script (a página carrega api.php?recurso=textos&formato=js, que põe window.TEXTOS_API); sem
// ele (uma página que não carregou), pela API, e o api() espera
var LINGUA = document.documentElement.lang || "pt-BR";
var LOCALE = "pt-BR";
var FORMATO = {data: "d/m/Y", data_curta: "d/m"};
var TEXTOS = {};
var ESTRITO = false;
function usarTextos(d) {
  if (d && d.lingua) {
    TEXTOS = d.textos || {};
    LOCALE = d.locale || "pt-BR";
    FORMATO = d.formato || FORMATO;
    ESTRITO = d.estrito === true;
    LINGUA = d.lingua;
    // sem idioma escolhido no menu (o cookie), vale o padrão da instalação: a página abre de novo nele, uma vez
    // o fuso do aparelho vai num cookie: vale para quem escolheu "O do navegador" em Minha conta (fuso_do_pedido, lib.php)
    try {
      var fusoNav = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (fusoNav) {
        document.cookie = "fuso_navegador=" + fusoNav + "; path=/; max-age=31536000; SameSite=Lax";
      }
    } catch (e) {
      // sem Intl: o fuso fica o da coleção
    }
    // sem idioma escolhido no menu, vale o padrão (o de Minha conta, senão o da coleção, senão o da instalação): o cookie
    // lingua é gravado aqui sozinho, marcado como automático (lingua_auto), e acompanha o padrão quando ele muda; só a
    // escolha no seletor do menu apaga a marca e prende o idioma
    var cookieLingua = (document.cookie.match(/(?:^|;\s*)lingua=([^;]*)/) || [])[1] || "";
    var cookieAuto = (document.cookie.match(/(?:^|;\s*)lingua_auto=([^;]*)/) || [])[1] || "";
    if (d.padrao && d.padrao !== LINGUA && (cookieLingua === "" || cookieLingua === cookieAuto)) {
      document.cookie = "lingua=" + d.padrao + "; path=/; max-age=31536000; SameSite=Lax";
      document.cookie = "lingua_auto=" + d.padrao + "; path=/; max-age=31536000; SameSite=Lax";
      window.location.reload();
    }
  }
}
var textosProntos = window.TEXTOS_API ? Promise.resolve(usarTextos(window.TEXTOS_API))
  : fetch("api.php?recurso=textos&lingua=" + encodeURIComponent(LINGUA), { credentials: "same-origin" }).then(function (r) { return r.json(); }).then(usarTextos).catch(function () { return null; });

// o texto no idioma, com os parâmetros trocados ({nome} pelo valor). O que falta sai em português; no modo estrito
// (TEXTOS_ESTRITO no config.php), entre ⟦ ⟧ na tela e com o erro no console, dizendo o arquivo
function t(s, p) {
  var tem = Object.prototype.hasOwnProperty.call(TEXTOS, s) && TEXTOS[s] !== "";
  var res = tem ? TEXTOS[s] : s;
  if (!tem && ESTRITO && LINGUA !== "pt-BR" && s !== "") {
    console.error("Falta a tradução em " + LINGUA + " (textos/" + LINGUA + ".php): " + JSON.stringify(s));
    res = "⟦" + s + "⟧";
  }
  if (p) {
    Object.keys(p).forEach(function (k) {
      res = res.split("{" + k + "}").join(String(p[k]));
    });
  }
  return res;
}

// marca um texto do dicionário que é traduzido depois, num t(variável) (a conferência dos dicionários acha a chave aqui)
function tk(s) {
  return s;
}

// Texto seguro para pôr no HTML
function h(t) {
  return String(t === null || t === undefined ? "" : t).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

// Uma marca HTML: el("p", {"class": "nota"}, "texto já seguro"). Atributo null ou false não sai; true sai sem valor.
function el(nome, atributos, conteudo) {
  var res = "<" + nome;
  Object.keys(atributos || {}).forEach(function (k) {
    var v = atributos[k];
    if (v === true) {
      res += " " + k;
    } else if (v !== null && v !== false && v !== undefined) {
      res += " " + k + "=\"" + h(v) + "\"";
    }
  });
  return res + ">" + (conteudo === null ? "" : (conteudo || "")) + (["input", "img", "br"].indexOf(nome) >= 0 ? "" : "</" + nome + ">");
}

// Número no formato do idioma (a vírgula ou o ponto), sem zeros sobrando
function num(v, casas) {
  var f = Math.pow(10, casas === undefined ? 1 : casas);
  return v === null || v === undefined || v === "" ? "—" : (Math.round(Number(v) * f) / f).toLocaleString(LOCALE, { maximumFractionDigits: casas === undefined ? 1 : casas, useGrouping: false });
}

// Dinheiro, no formato do idioma: 1.234,56 (1,234.56 em inglês)
function reais(v) {
  return Number(v).toLocaleString(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// "AAAA-MM-DD..." para a data do idioma (o formato vem da API, com d, m e Y): 13/10/2026, 10/13/2026, 2026-10-13; curto: sem o ano
function dataBr(t, curto) {
  var p = String(t).substr(0, 10).split("-");
  return (curto ? FORMATO.data_curta : FORMATO.data).replace(/[dmY]/g, function (c) { return c === "d" ? p[2] : (c === "m" ? p[1] : p[0]); });
}

// "AAAA-MM-DD HH:MM:SS" para "HH:MM"
function horaBr(t) {
  return String(t).substr(11, 5);
}

// Um instante "AAAA-MM-DD HH:MM:SS" em milissegundos, no fuso do navegador (o mesmo do servidor)
function instante(t) {
  var p = String(t).split(/[- :T]/);
  return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]), Number(p[3] || 0), Number(p[4] || 0), Number(p[5] || 0)).getTime();
}

// Trocar o relógio de um dia do plano (Hoje e Plano): a lista dos relógios disponíveis e o botão; no dia escolhido à mão
// ou livre (de amanhã em diante), também "voltar a sortear". p: o dia do plano (data, relogio_id, origem); hoje: AAAA-MM-DD
function formTrocarDia(p, relogios, hoje) {
  var opcoes = el("option", {"value": "", "selected": true, "disabled": true}, p.origem === "livre" ? t("escolher…") : t("trocar por…"))
    + relogios.filter(function (r) { return r.disponivel && r.id !== p.relogio_id; }).map(function (r) {
      return el("option", {"value": r.id}, h(r.nome));
    }).join("")
    + ((p.origem === "manual" || p.origem === "livre") && p.data > hoje ? el("option", {"value": "0"}, t("↺ voltar a sortear")) : "");
  return el("form", {"data-recurso": "rodizio", "class": "trocar-dia"}, el("input", {"type": "hidden", "name": "acao", "value": "trocar_dia"})
    + el("input", {"type": "hidden", "name": "data", "value": p.data})
    + el("select", {"name": "relogio_id", "required": true, "aria-label": t("Trocar o relógio de {1}", {1: dataBr(p.data)}), "onchange": "this.form.querySelector('button').hidden = false"}, opcoes)
    + el("button", {"class": "leve", "hidden": true}, t("Trocar")));
}

// O navegador de páginas (Próximos dias, Plano, Rodízio manual): "<" (Anterior), os números (a primeira, a última e até 2 de
// cada lado da atual, com "…" nos saltos; a atual marcada) e ">" (Próximo). Cada botão leva o número da página no atributo
// data-<atributo>; quem usa escuta o clique. Uma página só: nada
function navPaginas(pagina, paginas, atributo) {
  if (paginas <= 1) {
    return "";
  }
  var botao = function (n, texto, dica) {
    var a = {"type": "button", "class": "leve", "disabled": n < 1 || n > paginas, "title": dica, "aria-label": dica};
    a["data-" + atributo] = n;
    return el("button", a, texto);
  };
  var res = botao(pagina - 1, "&lt;", t("Anterior"));
  var ultimo = 0;
  for (var n = 1; n <= paginas; n++) {
    if (n === 1 || n === paginas || Math.abs(n - pagina) <= 2) {
      if (ultimo > 0 && n > ultimo + 1) {
        res += el("span", {"class": "reticencias"}, "…");
      }
      res += n === pagina ? el("span", {"class": "atual", "aria-current": "page", "title": t("Página {1}", {1: n})}, String(n)) : botao(n, String(n), t("Página {1}", {1: n}));
      ultimo = n;
    }
  }
  res += botao(pagina + 1, "&gt;", t("Próximo"));
  return el("nav", {"class": "paginas", "aria-label": t("Páginas")}, res);
}

// Apagar um período do plano (página Plano e quadro Modo de rodízio): os dias saem da tabela, de amanhã em diante, e o modo de
// rodízio monta esses dias de novo (rodizio periodo_sortear). Um dos lados pode ficar vazio: sem o começo, de amanhã até o
// fim; sem o fim, do começo até o último dia do plano. hoje: AAAA-MM-DD
function formApagarPeriodo(hoje) {
  return el("form", {"data-recurso": "rodizio", "class": "apagar-periodo", "onsubmit": "return confirm(this.getAttribute('data-confirma'))",
      "data-confirma": t("Apagar os dias deste período do plano (escolhidos à mão, sorteados e livres)? O modo de rodízio monta esses dias de novo.")},
    el("input", {"type": "hidden", "name": "acao", "value": "periodo_sortear"})
    + el("span", {"class": "campo-principal"}, t("Apagar um período"))
    + el("div", {"class": "linha-apagar"}, el("label", {}, t("De {1}", {1: el("input", {"type": "date", "name": "de", "min": hoje})}))
      + el("label", {}, t("Até {1}", {1: el("input", {"type": "date", "name": "ate", "min": hoje})}))
      + el("button", {"class": "leve"}, t("Apagar")))
    + el("p", {"class": "nota"}, t("Os dias do período saem do plano, de amanhã em diante (os escolhidos à mão, os sorteados e os livres), e o modo de rodízio monta esses dias de novo. Pode deixar um lado vazio: só o começo apaga dele até o fim do plano; só o fim apaga de amanhã até ele.")));
}

// Apagar tudo (página Plano e quadro Modo de rodízio): toda a programação de amanhã em diante (com "Excluindo hoje", de hoje
// em diante) sai: os dias escolhidos à mão, os sorteados e os livres; o modo de rodízio monta o plano de novo (rodizio apagar_tudo)
function formApagarTudo() {
  return el("form", {"data-recurso": "rodizio", "class": "apagar-tudo", "onsubmit": "return confirm(this.getAttribute(this.hoje.checked ? 'data-confirma-hoje' : 'data-confirma'))",
      "data-confirma-hoje": t("Apagar toda a programação, de hoje em diante (escolhidos à mão, sorteados e dias livres)? O modo de rodízio monta o plano de novo, e hoje pode sair outro relógio, que passa a ser o do pulso a partir de agora."),
      "data-confirma": t("Apagar toda a programação, de amanhã em diante (escolhidos à mão, sorteados e dias livres)? O modo de rodízio monta o plano de novo; hoje continua como está.")},
    el("input", {"type": "hidden", "name": "acao", "value": "apagar_tudo"})
    + el("span", {"class": "campo-principal"}, t("Apagar tudo"))
    + el("div", {"class": "linha-apagar"}, el("label", {"class": "check"}, t("{1} Excluindo hoje (apaga também o dia de hoje)", {1: el("input", {"type": "checkbox", "name": "hoje", "value": "1"})}))
      + el("button", {"class": "leve"}, t("Apagar tudo")))
    + el("p", {"class": "nota"}, t("Limpa toda a programação, de amanhã em diante: os dias escolhidos à mão, os sorteados e os livres. Marcando \"Excluindo hoje\", apaga de hoje em diante (se sair outro relógio para hoje, ele passa a ser o do pulso a partir de agora). Depois, o modo de rodízio monta o plano de novo, do zero.")));
}

// Os dias do plano com os dias livres no meio, na ordem das datas (um dia livre: sem relógio, para escolher à mão)
function comLivres(plano, livres) {
  return plano.concat((livres || []).map(function (dt) {
    return {data: dt, relogio_id: null, relogio: null, origem: "livre", acao: null, motivo: null};
  })).sort(function (a, b) { return a.data < b.data ? -1 : (a.data > b.data ? 1 : 0); });
}

// A célula do relógio de um dia do plano: o nome (com "à mão"), ou "livre", e o motivo embaixo
function celulaDoDia(p) {
  return p.origem === "livre" ? el("span", {"class": "nota"}, t("livre: escolha à mão")) + " " + el("small", {"class": "a-mao"}, "livre")
    : h(p.relogio) + (p.origem === "manual" ? " " + el("small", {"class": "a-mao"}, t("à mão")) : "") + (p.motivo ? el("small", {"class": "motivo"}, h(p.motivo)) : "");
}

// Minúsculas, para os botões ("No sol" → "Pôs no sol")
function minusculo(t) {
  return String(t).toLowerCase();
}

// No celular as tabelas dos cadastros viram fichas (estilo.css), e cada célula mostra ao lado o título da sua coluna: ele
// vai no data-rotulo. Vale para toda tabela com cabeçalho, também as montadas depois (as linhas com colspan ficam sem).
function rotularTabelas() {
  document.querySelectorAll("table").forEach(function (t) {
    var titulos = t.tHead && t.tHead.rows.length > 0 ? Array.prototype.map.call(t.tHead.rows[0].cells, function (c) { return c.textContent.trim(); }) : [];
    Array.prototype.forEach.call(titulos.length > 0 ? t.tBodies : [], function (corpo) {
      Array.prototype.forEach.call(corpo.rows, function (tr) {
        if (tr.cells.length === titulos.length) {
          Array.prototype.forEach.call(tr.cells, function (td, i) {
            if (titulos[i] !== "" && td.getAttribute("data-rotulo") !== titulos[i]) {
              td.setAttribute("data-rotulo", titulos[i]);
            }
          });
        }
      });
    });
  });
}
// As ações de uma linha (Editar, ↑ ↓, Excluir): o Excluir em vermelho, em todas as tabelas, também nas montadas depois
function marcarAcoes() {
  document.querySelectorAll(".acoes-linha button, .acoes-ev button").forEach(function (b) {
    if (b.textContent.trim().toLowerCase().indexOf(t("Excluir").toLowerCase()) === 0 && !b.classList.contains("perigo")) {
      b.classList.add("perigo");
    }
  });
}
var rotulosPedidos = false;
new MutationObserver(function () {
  if (!rotulosPedidos) {
    rotulosPedidos = true;
    setTimeout(function () {
      rotulosPedidos = false;
      rotularTabelas();
      marcarAcoes();
    }, 0);
  }
}).observe(document.body, {childList: true, subtree: true});
rotularTabelas();

// O que a API respondeu com erro: banco desatualizado vai para a Configuração (que aplica); o resto vira uma exceção
function tratarResposta(resp) {
  return resp.json().catch(function () { return {}; }).then(function (dados) {
    if (resp.status === 503 && dados.pendentes && window.location.pathname.indexOf("configuracao.php") < 0) {
      window.location = "configuracao.php";
    }
    // a conta sem coleção (a que pediu acesso e espera; a que saiu de todas): Minha conta, que cria uma ou pede acesso
    if (resp.status === 409 && dados.sem_colecao && window.location.pathname.indexOf("conta.php") < 0) {
      window.location = "conta.php";
    }
    // sem login: o login é da API (recurso=entrar), que pede o usuário e a senha e volta para esta página
    if (resp.status === 401) {
      window.location = "api.php?recurso=entrar&volta=" + encodeURIComponent((window.location.pathname.split("/").pop() || "index.php") + window.location.search + window.location.hash);
    }
    // a resposta vai junto no erro (dados.erros: o que a API explicou)
    if (!resp.ok && dados.ok === undefined) {
      var erro = new Error(dados.erro || (t("a API respondeu {1}", {1: resp.status})));
      erro.dados = dados;
      throw erro;
    }
    return dados;
  });
}

// Lê um recurso da API: api({recurso: "hoje"}) (depois do dicionário do idioma: a tela que vem em seguida já sai traduzida)
function api(parametros) {
  return textosProntos.then(function () {
    return fetch("api.php?" + new URLSearchParams(parametros || {}).toString(), { credentials: "same-origin" });
  }).then(tratarResposta);
}

// Grava pela API: dados é um FormData ou um objeto; devolve {ok, mensagem, erros, ...}
function enviar(dados) {
  var corpo = dados;
  if (!(dados instanceof FormData)) {
    corpo = new FormData();
    Object.keys(dados).forEach(function (k) {
      corpo.append(k, dados[k]);
    });
  }
  return fetch("api.php", { method: "POST", body: corpo, credentials: "same-origin" }).then(tratarResposta);
}

// O texto de uma resposta de gravação: a mensagem, ou os erros
function textoResposta(res) {
  return (res.mensagem || "") + (res.erros && res.erros.length > 0 ? " " + res.erros.join(" ") : "");
}

// Um instante no formato do campo de data e hora (AAAA-MM-DDTHH:MM), no fuso do navegador
function momentoNavegador(d) {
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, "0"), String(d.getDate()).padStart(2, "0")].join("-") + "T"
    + String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
}

// Os campos de data e hora que andam com o relógio do navegador: data-agora vem com a hora de agora e acompanha o relógio
// (a cada 15 s e ao clicar nele) até a pessoa mexer, e daí fica o que ela pôs; data-max-agora tem o máximo em agora, que
// também anda (senão, com a página aberta há horas, o navegador recusaria uma hora depois de quando ela abriu)
function acertarRelogios() {
  var agora = momentoNavegador(new Date());
  document.querySelectorAll("input[data-max-agora]").forEach(function (c) {
    c.max = agora;
  });
  document.querySelectorAll("input[data-agora]").forEach(function (c) {
    if (c !== document.activeElement) {
      c.value = agora;
    }
  });
}
setInterval(acertarRelogios, 15000);
document.addEventListener("focusin", function (ev) {
  var c = ev.target;
  if (c.matches && c.matches("input[data-agora], input[data-max-agora]")) {
    var agora = momentoNavegador(new Date());
    if (c.hasAttribute("data-max-agora")) {
      c.max = agora;
    }
    if (c.hasAttribute("data-agora")) {
      c.value = agora;
    }
  }
});
document.addEventListener("input", function (ev) {
  if (ev.target.hasAttribute && ev.target.hasAttribute("data-agora")) {
    ev.target.removeAttribute("data-agora");
  }
});

// Formulários que gravam pela API (data-recurso): o envio vai para o api.php, e depois a página chama o que ela
// registrou (aoGravar), com a resposta. O confirm() de alguns formulários já cancela o envio.
// Campos dentro de um bloco escondido ([data-sub] escondido) não vão.
var aoGravar = function (form, res) { window.location.reload(); };
document.addEventListener("submit", function (ev) {
  var form = ev.target;
  if (!ev.defaultPrevented && form.hasAttribute("data-recurso")) {
    ev.preventDefault();
    var escondidos = [];
    form.querySelectorAll("[data-sub][hidden] input, [data-sub][hidden] select, [data-sub][hidden] textarea").forEach(function (c) {
      if (!c.disabled) {
        c.disabled = true;
        escondidos.push(c);
      }
    });
    var dados = new FormData(form);
    escondidos.forEach(function (c) {
      c.disabled = false;
    });
    // o campo de data e hora que ninguém mexeu (data-agora): "vazio" vai vazio (a API usa o agora dela, com os segundos);
    // os outros vão com o minuto de agora
    form.querySelectorAll("input[data-agora]").forEach(function (c) {
      if (c.name && !c.disabled) {
        dados.set(c.name, c.getAttribute("data-agora") === "vazio" ? "" : momentoNavegador(new Date()));
      }
    });
    dados.append("recurso", form.getAttribute("data-recurso"));
    if (ev.submitter && ev.submitter.name) {
      dados.append(ev.submitter.name, ev.submitter.value);
    }
    var botoes = form.querySelectorAll("button");
    botoes.forEach(function (b) {
      b.disabled = true;
    });
    enviar(dados)
      .then(function (res) {
        // a gravação que a API quer confirmada (a marcação da leitura que não bate com o pulso registrado): a pergunta dela;
        // com o sim, vai de novo com confirmar=1
        if (res.confirmar && res.pergunta) {
          if (!confirm(res.pergunta)) {
            botoes.forEach(function (b) {
              b.disabled = false;
            });
            return null;
          }
          dados.set("confirmar", "1");
          return enviar(dados).then(function (res2) {
            aoGravar(form, res2, dados);
          });
        }
        // a exclusão em dois passos: a API diz o que sai junto; só com a confirmação ela apaga
        if (res.confirmar && res.impacto) {
          if (!confirm(res.impacto.texto + "\n\n" + t("Excluir mesmo assim?"))) {
            botoes.forEach(function (b) {
              b.disabled = false;
            });
            return null;
          }
          dados.set("confirmar", "1");
          return enviar(dados).then(function (res2) {
            aoGravar(form, res2, dados);
          });
        }
        aoGravar(form, res, dados);
      })
      .catch(function (e) {
        botoes.forEach(function (b) {
          b.disabled = false;
        });
        alert(t("Não consegui gravar: {1}. Confira a conexão e tente de novo.", {1: e.message}));
      });
  }
});

// A foto do relógio ampliada: duplo clique na foto do detalhe do relógio (o painel, ou a ficha), ou o botão "Abrir foto"
// embaixo dela, abre a imagem numa janela flutuante, no tamanho dela. Só ali: nas outras fotos (o "Hoje é dia de", as
// miniaturas da lista, o histórico) o clique já abre o relógio, e o duplo clique não faz nada a mais. A que não cabe é mostrada em escala, sem deformar, no maior tamanho
// que cabe no monitor em que a página está (screen.availWidth/availHeight) e na janela do navegador (a página não desenha
// fora dela); com o navegador em tela cheia, é o monitor. Muda de tamanho junto com a janela (e ao passar para outro
// monitor). Fecha com o ×, com um clique fora da imagem ou com Esc.
function fotoAmpliada(src, nome) {
  fecharFotoAmpliada();
  var caixa = document.createElement("div");
  caixa.className = "foto-ampliada";
  caixa.setAttribute("role", "dialog");
  caixa.setAttribute("aria-modal", "true");
  caixa.setAttribute("aria-label", nome || t("Foto do relógio"));
  caixa.innerHTML = el("figure", {}, el("button", {"type": "button", "class": "fechar-foto", "aria-label": t("Fechar"), "title": t("Fechar (Esc)")}, t("×"))
    + el("img", {"src": src, "alt": nome || ""}) + el("figcaption", {}, h(nome || "")));
  document.body.appendChild(caixa);
  document.body.classList.add("com-foto-ampliada");
  var img = caixa.querySelector("img");
  var ajustar = function () {
    if (!img.naturalWidth) {
      return;
    }
    // a área que se vê: o menor entre o monitor e a janela, menos a margem e a legenda
    var largura = Math.min(window.innerWidth, (window.screen && screen.availWidth) || window.innerWidth) - 32;
    var altura = Math.min(window.innerHeight, (window.screen && screen.availHeight) || window.innerHeight) - 72;
    var escala = Math.min(1, largura / img.naturalWidth, altura / img.naturalHeight);
    img.style.width = Math.floor(img.naturalWidth * escala) + "px";
    img.style.height = Math.floor(img.naturalHeight * escala) + "px";
    caixa.querySelector("figcaption").textContent = t("{1}{2} × {3} px{4}", {1: nome ? nome + " · " : "", 2: img.naturalWidth, 3: img.naturalHeight, 4: escala < 1 ? t(" (mostrada a {1}%)", {1: Math.round(escala * 100)}) : ""});
    caixa.classList.add("pronta");
  };
  img.addEventListener("load", ajustar);
  img.addEventListener("error", function () {
    caixa.querySelector("figcaption").textContent = t("Não consegui abrir a foto.");
    caixa.classList.add("pronta");
  });
  if (img.complete) {
    ajustar();
  }
  caixa.ajustar = ajustar;
  window.addEventListener("resize", ajustar);
  caixa.addEventListener("click", function (ev) {
    if (ev.target === caixa || ev.target.closest(".fechar-foto")) {
      fecharFotoAmpliada();
    }
  });
  caixa.querySelector(".fechar-foto").focus();
}

function fecharFotoAmpliada() {
  var caixa = document.querySelector(".foto-ampliada");
  if (caixa) {
    window.removeEventListener("resize", caixa.ajustar);
    caixa.remove();
  }
  document.body.classList.remove("com-foto-ampliada");
}

document.addEventListener("dblclick", function (ev) {
  var img = ev.target.closest(".ficha-foto img[data-foto-grande]");
  if (img) {
    ev.preventDefault();
    fotoAmpliada(img.getAttribute("src"), img.getAttribute("data-foto-grande"));
  }
});
document.addEventListener("click", function (ev) {
  var b = ev.target.closest("[data-abrir-foto]");
  if (b) {
    var img = b.closest(".ficha-foto").querySelector("img[data-foto-grande]");
    if (img) {
      fotoAmpliada(img.getAttribute("src"), img.getAttribute("data-foto-grande"));
    }
  }
});

// Esc fecha a foto ampliada antes de qualquer outra coisa (o painel do relógio, por baixo, continua aberto)
document.addEventListener("keydown", function (ev) {
  if (ev.key === "Escape" && document.querySelector(".foto-ampliada")) {
    ev.stopImmediatePropagation();
    fecharFotoAmpliada();
  }
}, true);

// "Inserir âncora" nos modelos de mensagem (Destinos e Agendas): a lista das âncoras ({relogio}, {acao}...), que põe a
// escolhida onde está o cursor do campo alvo (o id dele)
function listaAncoras(ancoras, alvo, rotulo) {
  return el("select", {"class": "ancora", "data-alvo": alvo, "aria-label": rotulo}, el("option", {"value": ""}, t("Inserir âncora")) + Object.keys(ancoras).map(function (k) {
    return el("option", {"value": "{" + k + "}"}, h("{" + k + "} " + ancoras[k]));
  }).join(""));
}
document.addEventListener("change", function (ev) {
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
});

// "O que recebe" um destino ou uma agenda: cada tipo (o relógio do dia, a véspera, os avisos, os eventos) com a caixa
// (envia[]) e, se $proprio, o modelo próprio (modelo_<tipo>; vazio: a mensagem padrão), dentro de um "Personalizar"
function tabelaRecebe(prefixo, recebe, ancoras, proprio) {
  return el("input", {"type": "hidden", "name": "envia[]", "value": ""}) + el("div", {"class": "rolagem"}, el("table", {"class": "compacta tabela-dados recebe"},
    el("thead", {}, el("tr", {}, el("th", {}, t("Recebe")) + el("th", {}, t("Aviso")) + el("th", {}, t("Quando")) + (proprio ? el("th", {}, t("Texto")) : "")))
    + el("tbody", {}, recebe.map(function (r) {
      var id = prefixo + "-m-" + r.tipo;
      return el("tr", {}, el("td", {"class": "centro"}, el("input", {"type": "checkbox", "name": "envia[]", "value": r.tipo, "checked": r.envia, "aria-label": r.nome}))
        + el("td", {}, h(r.nome)) + el("td", {"class": "nota"}, h(r.quando))
        + (proprio ? el("td", {}, el("details", {"open": r.modelo !== null}, el("summary", {}, r.modelo !== null ? t("Personalizado") : t("Personalizar"))
          + el("div", {"class": "campo-modelo"}, el("div", {"class": "rotulo-modelo"}, el("label", {"for": id}, t("Texto de {1}", {1: h(r.nome)})) + listaAncoras(ancoras, id, t("Inserir âncora")))
            + el("textarea", {"id": id, "name": "modelo_" + r.tipo, "rows": "3", "placeholder": t("Vazio: a mensagem padrão")}, h(r.modelo || ""))))) : ""));
    }).join(""))));
}

// Uma opção numa linha: o rótulo à esquerda e o campo à direita, na mesma coluna em todos os quadros (a Configuração, os Destinos, as Agendas, a Conta). "check": a caixa no
// lugar do campo; "largo": o rótulo em cima e o campo na largura toda (textos longos, como um endereço)
// as opções de um seletor de fuso horário: a primeira é o "vazio" (o da coleção, o do sistema) e depois todos os fusos que o
// PHP conhece (recurso=conta, configuracao ou usuarios: fusos[]), cada um com o deslocamento de agora ("UTC−03:00")
// (com navegadorTexto, a opção "navegador": o fuso do aparelho de quem entra; fusoNavegador() diz qual é o deste aparelho)
function fusoNavegador() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "";
  } catch (e) {
    return "";
  }
}

function opcoesFuso(fusos, atual, vazioTexto, navegadorTexto) {
  return el("option", {"value": "", "selected": !atual}, vazioTexto) + (navegadorTexto ? el("option", {"value": "navegador", "selected": atual === "navegador"}, navegadorTexto) : "") + (fusos || []).map(function (f) {
    return el("option", {"value": f.nome, "selected": atual === f.nome}, h(f.nome.replace(/_/g, " ")) + " (UTC" + h(f.deslocamento.replace("-", "\u2212")) + ")");
  }).join("");
}

function parCampo(rotulo, controle, tipo) {
  return el("label", {"class": "par-campo" + (tipo ? " " + tipo : "")}, el("span", {"class": "rotulo"}, h(rotulo)) + el("span", {"class": "controle"}, controle));
}

// O pedido de acesso pelo código (a Criar conta e a Minha conta): o código que o dono passou, de uma coleção ou do dono (todas
// as coleções dele). Nenhuma lista de contas nem de coleções: quem pede não vê quem tem conta. O acessoPorCodigo, no envio do
// formulário, passa o código para o colecao_id ou o dono_id. Junto, o passo a passo: por que é pelo código, como pedir o código
// ao dono, onde o dono o encontra e o que acontece depois (aberto na Criar conta; fechado em Minha conta)
function explicacaoPedido(aberto) {
  return el("details", {"class": "como-pedir", "open": !!aberto}, el("summary", {}, t("Como pedir acesso (e por que pelo código)"))
    + el("ol", {}, el("li", {}, t("Por que o código: para proteger quem usa o sistema, nenhuma página mostra a lista de contas nem de coleções. O login é metade do que alguém precisa para tentar entrar numa conta alheia, e o nome de uma coleção costuma levar o login do dono. Por isso o pedido é feito pelo código que o próprio dono passa: quem não recebeu o código não tem como saber de quem ele é."))
      + el("li", {}, t("Peça o código ao dono (por mensagem, e-mail ou pessoalmente). Ele o encontra em Minha conta: o código dele, em Seus dados, dá acesso a todas as coleções dele, também às que ele criar depois; o código de uma coleção só fica na coluna Código da tabela Coleções."))
      + el("li", {}, t("Aqui, escolha se o código é de uma coleção ou do dono, digite o número e deixe um recado dizendo quem você é: o dono vê só o seu login."))
      + el("li", {}, t("O dono recebe um e-mail e aprova ou recusa em Aprovações. Até lá, o seu pedido mostra só o código. Aprovado, você recebe um e-mail e a coleção aparece no seletor do menu."))));
}

function camposCodigoAcesso(obrigatorio, aberto) {
  return el("input", {"type": "hidden", "name": "colecao_id", "value": ""}) + el("input", {"type": "hidden", "name": "dono_id", "value": ""})
    + parCampo(t("Acesso a"), el("select", {"name": "tipo_codigo"}, el("option", {"value": "c"}, t("uma coleção (o código da coleção)"))
      + el("option", {"value": "d"}, t("todas as coleções de um dono (o código do dono)"))), "largo")
    + parCampo(t("Código"), el("input", {"type": "number", "name": "codigo", "min": "1", "step": "1", "inputmode": "numeric", "required": !!obrigatorio}))
    + explicacaoPedido(aberto);
}

function acessoPorCodigo(form) {
  if (form.codigo) {
    form.colecao_id.value = form.tipo_codigo.value === "c" ? form.codigo.value : "";
    form.dono_id.value = form.tipo_codigo.value === "d" ? form.codigo.value : "";
  }
}

// O menu de quem entrou (recurso=conta, parte=menu; não na página pública do cadastro): o seletor da coleção (com mais de
// uma: grava o cookie colecao e abre a página de novo), o aviso da conta de demonstração (os dias que faltam), as aprovações
// que esperam (o link para a página Aprovações), o Usuários (só o master) e a faixa "Vendo como" (o master vendo o sistema
// como outro usuário: o botão volta a ele, apagando o cookie como)
var CONTA_MENU = null;
function escolherColecao(id) {
  document.cookie = "colecao=" + id + "; path=/; max-age=31536000; SameSite=Lax";
  window.location.reload();
}
function verComo(login) {
  document.cookie = "como=" + encodeURIComponent(login) + "; path=/; SameSite=Lax" + (login === "" ? "; max-age=0" : "");
  document.cookie = "colecao=; path=/; max-age=0; SameSite=Lax";
  window.location = "index.php";
}
var contaPronta = document.querySelector("[data-menu-conta]") ? api({recurso: "conta", parte: "menu"}).then(function (m) {
  CONTA_MENU = m;
  var lugar = document.querySelector("[data-menu-conta]");
  var res = "";
  if (m.colecoes.length > 1) {
    res += el("label", {"class": "menu-colecao"}, el("span", {"class": "visualmente-oculto"}, t("Coleção")) + el("select", {"aria-label": t("Coleção"), "onchange": "escolherColecao(this.value)"},
      m.colecoes.map(function (c) {
        return el("option", {"value": c.id, "selected": c.id === m.colecao}, h(c.nome) + (c.papel !== "dono" ? " · " + h(c.dono) : ""));
      }).join("")));
  }
  if (m.aprovacoes > 0) {
    res += el("a", {"href": "aprovacoes.php", "class": "menu-aviso", "title": t("Pedidos e convites esperando a sua resposta")}, t("Aprovações ({1})", {1: m.aprovacoes}));
  }
  if (m.usuario && m.usuario.demo) {
    res += el("a", {"href": "conta.php", "class": "menu-aviso", "title": t("Conta de demonstração: depois disso, ela e as suas coleções são apagadas. Peça ao administrador para torná-la oficial.")},
      t("Demo: {1} dias", {1: m.usuario.dias_restantes}));
  }
  lugar.innerHTML = res;
  if (m.master) {
    document.querySelectorAll("[data-so-master]").forEach(function (a) {
      a.hidden = false;
    });
  }
  if (m.vendo_como) {
    var faixa = document.createElement("div");
    faixa.className = "faixa-vendo-como";
    faixa.innerHTML = t("Vendo como {1}: o que aparece e o que se grava é da conta dele.", {1: el("strong", {}, h(m.vendo_como.login))}) + " "
      + el("button", {"type": "button", "class": "leve", "onclick": "verComo('')"}, t("Voltar a ser {1}", {1: h(m.vendo_como.master)}));
    document.body.insertBefore(faixa, document.body.firstChild);
  }
  return m;
}).catch(function () { return null; }) : Promise.resolve(null);

// O aplicativo no celular (PWA): toda página registra o service worker (pela API: o cache das telas e as notificações do
// Web Push) e guarda o pedido de instalação do navegador (o Chrome avisa que dá para instalar: o botão "Instalar o
// aplicativo" de Minha conta usa) — nada disso muda a página
var INSTALAR_APP = null;
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("api.php?recurso=sw").catch(function () {});
}
window.addEventListener("beforeinstallprompt", function (e) {
  e.preventDefault();
  INSTALAR_APP = e;
  document.dispatchEvent(new CustomEvent("app-instalavel"));
});
