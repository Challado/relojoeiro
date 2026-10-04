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
// (de amanhã em diante), também "voltar a sortear". p: o dia do plano (data, relogio_id, origem); hoje: AAAA-MM-DD
function formTrocarDia(p, relogios, hoje) {
  var opcoes = el("option", {"value": "", "selected": true, "disabled": true}, "trocar por…")
    + relogios.filter(function (r) { return r.disponivel && r.id !== p.relogio_id; }).map(function (r) {
      return el("option", {"value": r.id}, h(r.nome));
    }).join("")
    + (p.origem === "manual" && p.data > hoje ? el("option", {"value": "0"}, "↺ voltar a sortear") : "");
  return el("form", {"data-recurso": "rodizio", "class": "trocar-dia"}, el("input", {"type": "hidden", "name": "acao", "value": "trocar_dia"})
    + el("input", {"type": "hidden", "name": "data", "value": p.data})
    + el("select", {"name": "relogio_id", "required": true, "aria-label": "Trocar o relógio de " + dataBr(p.data), "onchange": "this.form.querySelector('button').hidden = false"}, opcoes)
    + el("button", {"class": "leve", "hidden": true}, "Trocar"));
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
var rotulosPedidos = false;
new MutationObserver(function () {
  if (!rotulosPedidos) {
    rotulosPedidos = true;
    setTimeout(function () {
      rotulosPedidos = false;
      rotularTabelas();
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
    if (resp.status === 401) {
      window.location.reload();
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
