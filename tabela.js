// Tabela de relógios: ordena pelo cabeçalho (clicando de novo, inverte) e filtra pela linha de filtros.
// A escolha fica guardada na sessão do navegador e volta quando a página recarrega.
(function () {
  var tabela = document.getElementById("tabela-relogios");
  if (tabela) {
    var corpo = tabela.tBodies[0];
    var original = Array.prototype.slice.call(corpo.querySelectorAll("tr[data-id]"));
    var chave = "relogios-tabela";
    var st = { col: "", desc: false, f: {} };
    try {
      var salvo = JSON.parse(sessionStorage.getItem(chave) || "null");
      if (salvo && salvo.f) {
        st = salvo;
      }
    } catch (e) {
      // sem sessão disponível: começa do zero
    }

    var guardar = function () {
      try {
        sessionStorage.setItem(chave, JSON.stringify(st));
      } catch (e) {
        // sem sessão disponível: só não guarda
      }
    };

    var passa = function (tr) {
      var f = st.f;
      var ok = true;
      var agora = Date.now() / 1000;
      var hoje = new Date();
      hoje.setHours(0, 0, 0, 0);
      if (f.codigo && String(tr.dataset.codigo).indexOf(f.codigo) !== 0) {
        ok = false;
      }
      if (f.nome && tr.dataset.nome.toLowerCase().indexOf(f.nome.toLowerCase()) < 0) {
        ok = false;
      }
      if (f.tipo && tr.dataset.tipo !== f.tipo) {
        ok = false;
      }
      // duas sessões abertas juntas vêm como "No sol · No winder": vale cada uma
      if (f.estado && tr.dataset.estadoTxt.split(" · ").indexOf(f.estado) < 0) {
        ok = false;
      }
      if (f.carga && (tr.dataset.carga === "" || Number(tr.dataset.carga) > Number(f.carga))) {
        ok = false;
      }
      var u = Number(tr.dataset.ultimo);
      if (f.ultimo === "7" && !(u > 0 && u >= agora - 7 * 86400)) {
        ok = false;
      }
      if (f.ultimo === "mais7" && !(u > 0 && u < agora - 7 * 86400)) {
        ok = false;
      }
      if (f.ultimo === "nunca" && u !== 0) {
        ok = false;
      }
      if (f.manut) {
        if (!tr.dataset.manut) {
          ok = false;
        } else if (new Date(tr.dataset.manut + "T00:00:00").getTime() > hoje.getTime() + Number(f.manut) * 86400000) {
          ok = false;
        }
      }
      if (f.fazer && tr.dataset.fazer !== f.fazer) {
        ok = false;
      }
      // o filtro Encostado só vale com a coluna à mostra (o aviso ligado na Configuração)
      var comEncostado = !tabela.classList.contains("sem-encostado");
      if (comEncostado && f.encostado === "sim" && tr.dataset.encostadoEstado !== "sim") {
        ok = false;
      }
      if (comEncostado && f.encostado === "prazo" && tr.dataset.encostadoEstado !== "prazo") {
        ok = false;
      }
      if (comEncostado && f.encostado === "sem" && tr.dataset.encostadoEstado !== "") {
        ok = false;
      }
      var compra = tr.dataset.compra ? new Date(tr.dataset.compra + "T00:00:00").getTime() : 0;
      if (f.compra === "sem" && compra !== 0) {
        ok = false;
      }
      if ((f.compra === "30" || f.compra === "90") && !(compra > 0 && compra >= hoje.getTime() - Number(f.compra) * 86400000)) {
        ok = false;
      }
      if (f.compra === "ano" && !(compra > 0 && new Date(compra).getFullYear() === hoje.getFullYear())) {
        ok = false;
      }
      var v = tr.dataset.valor === "" ? null : Number(tr.dataset.valor);
      if (f.valor === "sem" && v !== null) {
        ok = false;
      }
      if (f.valor === "ate500" && !(v !== null && v <= 500)) {
        ok = false;
      }
      if (f.valor === "500a1500" && !(v !== null && v > 500 && v <= 1500)) {
        ok = false;
      }
      if (f.valor === "acima1500" && !(v !== null && v > 1500)) {
        ok = false;
      }
      return ok;
    };

    // valor usado na ordenação: vazios sempre por último
    var valor = function (tr, col) {
      var v = "";
      if (col === "codigo" || col === "ultimo") {
        v = Number(tr.dataset[col]);
      } else if (col === "estado") {
        v = Number(tr.dataset.estado) * 100000 + Number(tr.dataset.codigo);
      } else if (col === "carga" || col === "valor" || col === "em" || col === "encostado") {
        v = tr.dataset[col] === "" ? null : Number(tr.dataset[col]);
      } else if (col === "nome") {
        v = tr.dataset.nome.toLowerCase();
      } else {
        v = tr.dataset[col] === "" ? null : tr.dataset[col];
      }
      return v;
    };

    // os totais do rodapé: a API calcula nos relógios à mostra (recurso=totais, parte=rodape, relogios=os ids) e a tela só
    // desenha, embaixo de cada coluna, os totais dela, cada um numa tabelinha com o nome em cima e o total no pé; as
    // tabelinhas ganham linhas vazias em cima, sem borda, até a maior delas, para os totais ficarem todos na mesma altura
    var pedidoTotais = 0;
    var esperaTotais = null;
    var linhaTotais = document.getElementById("totais-rodape");
    var miniTotal = function (x, linhas) {
      var r = x.resultado;
      var corpoT = [];
      var pe = "";
      if (r.tipo === "tabela") {
        corpoT = r.linhas.map(function (l, i) {
          return el("tr", {"class": (l.sem ? "sem " : "") + (i === 0 ? "primeira" : "")}, el("td", {"title": l.rotulo}, h(l.rotulo)) + el("td", {"class": "num"}, h(l.texto)));
        });
        pe = el("tr", {}, el("td", {}, t("Total")) + el("td", {"class": "num"}, h(r.total_texto)));
      } else if (r.tipo === "relogio") {
        pe = el("tr", {}, el("td", {"title": r.relogio || ""}, h(r.relogio || "—")) + el("td", {"class": "num"}, h(r.valor_texto)));
      } else if (r.tipo === "erro") {
        pe = el("tr", {}, el("td", {"colspan": "2", "class": "erro", "title": r.erro}, h(r.erro)));
      } else {
        pe = el("tr", {}, el("td", {"colspan": "2", "class": "num"}, h(r.texto)));
      }
      // a última das vazias fecha a caixa por cima (a linha dela é a borda de cima da primeira linha com dado ou do total)
      var vazias = [];
      while (vazias.length + corpoT.length < linhas) {
        vazias.push(el("tr", {"class": "vazia" + (vazias.length === 0 ? " fim" : ""), "aria-hidden": "true"}, el("td", {"colspan": "2"}, "\u00a0")));
      }
      corpoT = vazias.reverse().concat(corpoT);
      return el("table", {"class": "mini-total"}, el("caption", {"title": x.nome}, h(x.nome)) + el("tbody", {}, corpoT.join("")) + el("tfoot", {}, pe));
    };
    var pedirTotais = function () {
      if (!linhaTotais || typeof api !== "function") {
        return;
      }
      clearTimeout(esperaTotais);
      esperaTotais = setTimeout(function () {
        var ids = Array.prototype.filter.call(corpo.querySelectorAll("tr[data-id]"), function (tr) { return !tr.hidden; }).map(function (tr) { return tr.dataset.id; });
        var meu = ++pedidoTotais;
        var todos = corpo.querySelectorAll("tr[data-id]").length;
        var ver = document.getElementById("ver-totais");
        if (ver) {
          ver.href = "totais.php" + (ids.length < todos ? "?relogios=" + (ids.length > 0 ? ids.join(",") : "0") : "");
        }
        api({recurso: "totais", parte: "rodape", relogios: ids.length > 0 ? ids.join(",") : "0"}).then(function (d) {
          if (meu !== pedidoTotais) {
            return;
          }
          var maior = 0;
          d.totais.forEach(function (x) {
            if (x.resultado.tipo === "tabela") {
              maior = Math.max(maior, x.resultado.linhas.length);
            }
          });
          linhaTotais.querySelectorAll("td[data-coluna]").forEach(function (td) {
            // só a primeira tabelinha de cada coluna se completa até a maior: os totais delas ficam na mesma linha
            porHtml(td, d.totais.filter(function (x) { return x.coluna === td.getAttribute("data-coluna"); }).map(function (x, i) { return miniTotal(x, i === 0 ? maior : 0); }).join(""));
          });
          linhaTotais.hidden = d.totais.length === 0;
          var recado = document.getElementById("totais-recado");
          if (recado) {
            recado.textContent = d.totais.length === 0 ? t("Nenhum total no rodapé: monte os seus na aba Totais dos Cadastros.") : "";
          }
        }).catch(function () {
          linhaTotais.hidden = true;
        });
      }, 250);
    };

    var aplicar = function () {
      var lista = original.slice();
      if (st.col) {
        lista.sort(function (a, b) {
          var va = valor(a, st.col);
          var vb = valor(b, st.col);
          var r = 0;
          if (va === null && vb !== null) {
            r = 1;
          } else if (vb === null && va !== null) {
            r = -1;
          } else if (va !== null) {
            r = va < vb ? -1 : (va > vb ? 1 : 0);
            if (st.desc) {
              r = -r;
            }
          }
          return r;
        });
      }
      var visiveis = 0;
      var emUso = 0;
      lista.forEach(function (tr) {
        corpo.appendChild(tr);
        tr.hidden = !passa(tr);
        if (!tr.hidden) {
          visiveis++;
        }
        if (tr.dataset.estado === "0") {
          emUso++;
        }
      });
      var conta = document.getElementById("conta-relogios");
      if (conta) {
        conta.textContent = t("{1} de {2} relógios · {3} em uso", {1: visiveis, 2: lista.length, 3: emUso});
      }
      pedirTotais();
      var vazia = document.getElementById("tabela-vazia");
      if (vazia) {
        vazia.hidden = visiveis > 0;
      }
      tabela.querySelectorAll("[data-ordena]").forEach(function (b) {
        var ativa = b.dataset.ordena === st.col;
        b.querySelector("span").textContent = ativa ? (st.desc ? "▼" : "▲") : "";
        b.closest("th").setAttribute("aria-sort", ativa ? (st.desc ? "descending" : "ascending") : "none");
      });
    };

    tabela.querySelectorAll("[data-ordena]").forEach(function (b) {
      b.addEventListener("click", function () {
        if (st.col === b.dataset.ordena) {
          st.desc = !st.desc;
        } else {
          st.col = b.dataset.ordena;
          st.desc = false;
        }
        guardar();
        aplicar();
      });
    });

    tabela.querySelectorAll("[data-filtro]").forEach(function (campo) {
      campo.value = st.f[campo.dataset.filtro] || "";
      campo.addEventListener(campo.tagName === "SELECT" ? "change" : "input", function () {
        st.f[campo.dataset.filtro] = campo.value;
        guardar();
        aplicar();
      });
    });

    var limpar = document.getElementById("limpar-filtros");
    if (limpar) {
      limpar.addEventListener("click", function () {
        st.f = {};
        tabela.querySelectorAll("[data-filtro]").forEach(function (campo) {
          campo.value = "";
        });
        guardar();
        aplicar();
      });
    }

    // coluna "Em": quando falta menos de um mês, o texto é recalculado aqui a cada minuto (dias, horas e minutos não dependem
    // do calendário); acima disso ele só muda de um dia para o outro, e vem pronto do servidor
    var faltaTexto = function (momento) {
      var dif = Math.round((momento * 1000 - Date.now()) / 60000);
      var min = Math.abs(dif);
      var d = Math.floor(min / 1440);
      var h = Math.floor((min % 1440) / 60);
      var m = min % 60;
      var partes = [[m, "m"]];
      if (h > 0) {
        partes = [[h, "h"], [m, "m"]];
      }
      if (d > 0) {
        partes = [[d, "d"], [h, "h"]];
      }
      var txt = partes.filter(function (p) { return p[0] > 0; }).map(function (p) { return p[0] + p[1]; }).join("\u00a0");   // "2d 10h" não quebra; o "atrasado" pode ir para cima
      if (txt === "") {
        txt = "agora";
      } else if (dif < 0) {
        txt = t("atrasado {1}", {1: txt});
      }
      return txt;
    };
    var atualizarFalta = function () {
      corpo.querySelectorAll("td[data-momento]").forEach(function (td) {
        var momento = Number(td.getAttribute("data-momento"));
        if (Math.abs(momento * 1000 - Date.now()) < 28 * 86400000) {
          td.textContent = faltaTexto(momento);
          td.classList.toggle("atrasado", momento * 1000 <= Date.now());
        }
      });
    };
    setInterval(atualizarFalta, 60000);

    // linhas montadas com os dados da API (ao abrir e depois de cada lançamento, sem recarregar a página): troca as
    // linhas e reaplica a ordem e os filtros que estavam escolhidos (as opções dos filtros podem ter chegado agora)
    // As 12 colunas sempre, em qualquer tela: quando a tabela inteira não cabe na largura que tem (celular, tablet, o
    // painel aberto ao lado), cada relógio vira um cartão com todos os campos (estilo.css, .em-cartoes). A conta é com
    // todos os relógios à mostra, para um filtro não trocar a forma da lista; tudo no mesmo quadro, sem piscar
    var rolagem = document.getElementById("rolagem-relogios");
    var ajustarForma = function () {
      if (!rolagem) {
        return;
      }
      var escondidos = Array.prototype.filter.call(corpo.rows, function (tr) { return tr.hidden; });
      escondidos.forEach(function (tr) { tr.hidden = false; });
      tabela.classList.remove("em-cartoes");
      var naoCabe = tabela.offsetWidth > rolagem.clientWidth;
      escondidos.forEach(function (tr) { tr.hidden = true; });
      tabela.classList.toggle("em-cartoes", naoCabe);
    };
    if (rolagem && window.ResizeObserver) {
      var largura = -1;
      new ResizeObserver(function () {
        if (rolagem.clientWidth !== largura) {
          largura = rolagem.clientWidth;
          ajustarForma();
        }
      }).observe(rolagem);
    }

    window.trocarLinhasDaTabela = function (html) {
      porHtml(corpo, html);
      original = Array.prototype.slice.call(corpo.querySelectorAll("tr[data-id]"));
      tabela.querySelectorAll("[data-filtro]").forEach(function (campo) {
        campo.value = st.f[campo.dataset.filtro] || "";
      });
      aplicar();
      ajustarForma();
    };

    aplicar();
    ajustarForma();
  }
})();
