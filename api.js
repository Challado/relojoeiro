// A base das páginas: todo dado vem do api.php e toda gravação vai para ele. As páginas só montam o HTML.
// O login do site (HTTP Basic) vai junto em cada pedido, pelo próprio navegador.

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

// Número com vírgula, sem zeros sobrando
function num(v, casas) {
  var f = Math.pow(10, casas === undefined ? 1 : casas);
  return v === null || v === undefined || v === "" ? "—" : String(Math.round(Number(v) * f) / f).replace(".", ",");
}

// Dinheiro: 1.234,56
function reais(v) {
  return Number(v).toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// "AAAA-MM-DD..." para "DD/MM/AAAA" (curto: "DD/MM")
function dataBr(t, curto) {
  var p = String(t).substr(0, 10).split("-");
  return curto ? p[2] + "/" + p[1] : p[2] + "/" + p[1] + "/" + p[0];
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
  var opcoes = el("option", {"value": "", "selected": true, "disabled": true}, p.origem === "livre" ? "escolher…" : "trocar por…")
    + relogios.filter(function (r) { return r.disponivel && r.id !== p.relogio_id; }).map(function (r) {
      return el("option", {"value": r.id}, h(r.nome));
    }).join("")
    + ((p.origem === "manual" || p.origem === "livre") && p.data > hoje ? el("option", {"value": "0"}, "↺ voltar a sortear") : "");
  return el("form", {"data-recurso": "rodizio", "class": "trocar-dia"}, el("input", {"type": "hidden", "name": "acao", "value": "trocar_dia"})
    + el("input", {"type": "hidden", "name": "data", "value": p.data})
    + el("select", {"name": "relogio_id", "required": true, "aria-label": "Trocar o relógio de " + dataBr(p.data), "onchange": "this.form.querySelector('button').hidden = false"}, opcoes)
    + el("button", {"class": "leve", "hidden": true}, "Trocar"));
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
  var res = botao(pagina - 1, "&lt;", "Anterior");
  var ultimo = 0;
  for (var n = 1; n <= paginas; n++) {
    if (n === 1 || n === paginas || Math.abs(n - pagina) <= 2) {
      if (ultimo > 0 && n > ultimo + 1) {
        res += el("span", {"class": "reticencias"}, "…");
      }
      res += n === pagina ? el("span", {"class": "atual", "aria-current": "page", "title": "Página " + n}, String(n)) : botao(n, String(n), "Página " + n);
      ultimo = n;
    }
  }
  res += botao(pagina + 1, "&gt;", "Próximo");
  return el("nav", {"class": "paginas", "aria-label": "Páginas"}, res);
}

// Apagar o que um período do plano tem (página Plano e quadro Modo de rodízio): de, até e o que fazer depois: sortear de
// novo pelo modo (rodizio periodo_sortear) ou deixar os dias livres, sem relógio (periodo_livre). Vale de amanhã em diante.
// hoje: AAAA-MM-DD
function formApagarPeriodo(hoje) {
  var acoes = [["periodo_sortear", "e sortear de novo", "Tudo o que o período tem, de amanhã em diante (escolhido à mão, sorteado ou livre), sai, e o modo de rodízio sorteia os dias outra vez.",
    "Apagar o que o período tem e sortear de novo? Os dias escolhidos à mão nele também saem."],
    ["periodo_livre", "e deixar livre", "Tudo o que o período tem, de amanhã em diante, sai, e os dias ficam livres: sem relógio e sem sorteio, para você escolher cada um (no \"escolher…\" do dia ou no Rodízio manual).",
    "Apagar o que o período tem e deixar os dias livres, sem relógio? Os dias escolhidos à mão nele também saem."]];
  return el("form", {"data-recurso": "rodizio", "class": "apagar-periodo", "onsubmit": "var o = this.acao.options[this.acao.selectedIndex]; return confirm(o.getAttribute('data-confirma'))"},
    el("span", {"class": "campo-principal"}, "Apagar um período")
    + el("div", {"class": "linha-apagar"}, el("label", {}, "De " + el("input", {"type": "date", "name": "de", "min": hoje, "required": true}))
      + el("label", {}, "Até " + el("input", {"type": "date", "name": "ate", "min": hoje, "required": true}))
      + el("label", {}, "Depois " + el("select", {"name": "acao"}, acoes.map(function (a) {
        return el("option", {"value": a[0], "data-explica": a[2], "data-confirma": a[3]}, h(a[1]));
      }).join("")))
      + el("button", {"class": "leve"}, "Apagar"))
    + el("p", {"class": "nota", "data-explica-apagar": true}, h(acoes[0][2])));
}

// Apagar tudo (página Plano e quadro Modo de rodízio): toda a programação de amanhã em diante (com "Excluindo hoje", de hoje
// em diante) sai: os dias escolhidos à mão, os sorteados e os livres; o modo de rodízio monta o plano de novo (rodizio apagar_tudo)
function formApagarTudo() {
  return el("form", {"data-recurso": "rodizio", "class": "apagar-tudo", "onsubmit": "return confirm(this.hoje.checked "
      + "? 'Apagar toda a programação, de hoje em diante (escolhidos à mão, sorteados e dias livres)? O modo de rodízio monta o plano de novo, e hoje pode sair outro relógio, que passa a ser o do pulso a partir de agora.' "
      + ": 'Apagar toda a programação, de amanhã em diante (escolhidos à mão, sorteados e dias livres)? O modo de rodízio monta o plano de novo; hoje continua como está.')"},
    el("input", {"type": "hidden", "name": "acao", "value": "apagar_tudo"})
    + el("span", {"class": "campo-principal"}, "Apagar tudo")
    + el("div", {"class": "linha-apagar"}, el("label", {"class": "check"}, el("input", {"type": "checkbox", "name": "hoje", "value": "1"}) + " Excluindo hoje (apaga também o dia de hoje)")
      + el("button", {"class": "leve"}, "Apagar tudo"))
    + el("p", {"class": "nota"}, "Limpa toda a programação, de amanhã em diante: os dias escolhidos à mão, os sorteados e os livres. Marcando \"Excluindo hoje\", apaga de hoje em diante "
      + "(se sair outro relógio para hoje, ele passa a ser o do pulso a partir de agora). Depois, o modo de rodízio monta o plano de novo, do zero."));
}

document.addEventListener("change", function (ev) {
  if (ev.target.matches(".apagar-periodo select[name=acao]")) {
    ev.target.closest("form").querySelector("[data-explica-apagar]").textContent = ev.target.options[ev.target.selectedIndex].getAttribute("data-explica");
  }
});

// Os dias do plano com os dias livres no meio, na ordem das datas (um dia livre: sem relógio, para escolher à mão)
function comLivres(plano, livres) {
  return plano.concat((livres || []).map(function (dt) {
    return {data: dt, relogio_id: null, relogio: null, origem: "livre", acao: null, motivo: null};
  })).sort(function (a, b) { return a.data < b.data ? -1 : (a.data > b.data ? 1 : 0); });
}

// A célula do relógio de um dia do plano: o nome (com "à mão"), ou "livre", e o motivo embaixo
function celulaDoDia(p) {
  return p.origem === "livre" ? el("span", {"class": "nota"}, "livre: escolha à mão") + " " + el("small", {"class": "a-mao"}, "livre")
    : h(p.relogio) + (p.origem === "manual" ? " " + el("small", {"class": "a-mao"}, "à mão") : "") + (p.motivo ? el("small", {"class": "motivo"}, h(p.motivo)) : "");
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
    if (/^\s*excluir/i.test(b.textContent) && !b.classList.contains("perigo")) {
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
    // sem login: o login é da API (recurso=entrar), que pede o usuário e a senha e volta para esta página
    if (resp.status === 401) {
      window.location = "api.php?recurso=entrar&volta=" + encodeURIComponent((window.location.pathname.split("/").pop() || "index.php") + window.location.search + window.location.hash);
    }
    // a resposta vai junto no erro (dados.erros: o que a API explicou)
    if (!resp.ok && dados.ok === undefined) {
      var erro = new Error(dados.erro || ("a API respondeu " + resp.status));
      erro.dados = dados;
      throw erro;
    }
    return dados;
  });
}

// Lê um recurso da API: api({recurso: "hoje"})
function api(parametros) {
  return fetch("api.php?" + new URLSearchParams(parametros || {}).toString(), { credentials: "same-origin" }).then(tratarResposta);
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
        aoGravar(form, res, dados);
      })
      .catch(function (e) {
        botoes.forEach(function (b) {
          b.disabled = false;
        });
        alert("Não consegui gravar: " + e.message + ". Confira a conexão e tente de novo.");
      });
  }
});

// A foto do relógio ampliada: duplo clique em qualquer foto dele (o painel, o "Hoje é dia de", a miniatura da lista) abre
// a imagem numa janela flutuante, no tamanho dela. A que não cabe é mostrada em escala, sem deformar, no maior tamanho
// que cabe no monitor em que a página está (screen.availWidth/availHeight) e na janela do navegador (a página não desenha
// fora dela); com o navegador em tela cheia, é o monitor. Muda de tamanho junto com a janela (e ao passar para outro
// monitor). Fecha com o ×, com um clique fora da imagem ou com Esc.
function fotoAmpliada(src, nome) {
  fecharFotoAmpliada();
  var caixa = document.createElement("div");
  caixa.className = "foto-ampliada";
  caixa.setAttribute("role", "dialog");
  caixa.setAttribute("aria-modal", "true");
  caixa.setAttribute("aria-label", nome || "Foto do relógio");
  caixa.innerHTML = el("figure", {}, el("button", {"type": "button", "class": "fechar-foto", "aria-label": "Fechar", "title": "Fechar (Esc)"}, "×")
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
    caixa.querySelector("figcaption").textContent = (nome ? nome + " · " : "") + img.naturalWidth + " × " + img.naturalHeight + " px"
      + (escala < 1 ? " (mostrada a " + Math.round(escala * 100) + "%)" : "");
    caixa.classList.add("pronta");
  };
  img.addEventListener("load", ajustar);
  img.addEventListener("error", function () {
    caixa.querySelector("figcaption").textContent = "Não consegui abrir a foto.";
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
  var img = ev.target.closest("img");
  if (img && /[?&]recurso=foto&/.test(img.getAttribute("src") || "")) {
    ev.preventDefault();
    var nome = (img.getAttribute("alt") || "").replace(/^Foto do /, "") || img.getAttribute("data-nome") || "";
    fotoAmpliada(img.getAttribute("src"), nome);
  }
});

// Esc fecha a foto ampliada antes de qualquer outra coisa (o painel do relógio, por baixo, continua aberto)
document.addEventListener("keydown", function (ev) {
  if (ev.key === "Escape" && document.querySelector(".foto-ampliada")) {
    ev.stopImmediatePropagation();
    fecharFotoAmpliada();
  }
}, true);
