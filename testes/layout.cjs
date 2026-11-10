// Conferência do layout: todas as telas, em 15 tamanhos (computador de 1100 a 1920px, tablet de pé e deitado, celular de 320 a
// 412px), procurando o que quebra: a página que rola de lado, o elemento que sai da caixa (o quadro, o cartão) em que está, a
// etiqueta (com fundo ou borda) partida em duas linhas, o texto cortado, o botão com o texto partido ("Abrir" numa linha, "foto"
// na outra), irmãos sobrepostos, palavra ou número partido no meio ("R$ 11.757,85" letra por letra: o overflow-wrap: anywhere deixa a
// coluna encolher abaixo do valor; só de 700px para cima, mais o rodapé de totais e o alto da Hoje em qualquer tamanho) e espaço vazio
// aproveitável entre os cartões da Configuração. Abre os <details> e passa
// por cada opção dos menus que mostram partes (o quadro Marcar).
// Antes, deixa os dados do cenário "feios" de propósito (nomes longos, observação comprida, fotos: veja estressar); ESTRESSE=0
// confere com os dados como estão.
// Roda sobre o banco do cenário (testes/cenario.php: os relógios 1, 3, 4, 5, 7 e 8) e precisa do Playwright com o Chromium:
//   BASE=http://127.0.0.1:8101/ RELOGIOS_USUARIO=teste RELOGIOS_SENHA=Senha@123 node testes/layout.cjs [página] [tela]
// (página e tela: um filtro, expressão regular, no nome; ex.: node testes/layout.cjs "ficha" "cel-"). Grava em testes/layout/
// uma captura de cada tela, o relatorio.tsv (uma linha por problema) e o agrupado.txt (o mesmo problema com as telas em que
// aparece). Termina com 0 se não achou nada, 1 se achou. LINGUA=en (zh-CN, hi, es, fr) confere as telas nesse idioma (o
// cookie do seletor do menu); sem ela, em português. SO=ajuda-solta,ajuda-sem-nome confere só esses tipos de problema (o CI "Botões de
// ajuda": o ⓘ colado ao rótulo ou ao campo que explica, e com nome e explicação).
const { chromium, devices } = require(process.env.PLAYWRIGHT || require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8101/';
const USUARIO = process.env.RELOGIOS_USUARIO || 'teste';
const SENHA = process.env.RELOGIOS_SENHA || 'Senha@123';
const SAIDA = process.env.SAIDA || __dirname + '/layout';
const LINGUA = process.env.LINGUA || '';
fs.mkdirSync(SAIDA, { recursive: true });

const TELAS = [
  { nome: 'pc-1920', viewport: { width: 1920, height: 1080 } },
  { nome: 'pc-1366', viewport: { width: 1366, height: 768 } },
  { nome: 'pc-1280', viewport: { width: 1280, height: 720 } },
  { nome: 'pc-1536', viewport: { width: 1536, height: 864 } },
  { nome: 'pc-1440', viewport: { width: 1440, height: 900 } },
  { nome: 'pc-1152', viewport: { width: 1152, height: 720 } },
  { nome: 'pc-1100', viewport: { width: 1100, height: 700 } },
  { nome: 'tablet-pe-768', ...devices['iPad Mini'] },
  { nome: 'tablet-pe-820', ...devices['iPad Air'] },
  { nome: 'tablet-deitado-1024', ...devices['iPad Mini landscape'] },
  { nome: 'tablet-deitado-1180', ...devices['iPad Air landscape'] },
  { nome: 'cel-390', ...devices['iPhone 13'] },
  { nome: 'cel-360', ...devices['Galaxy S9+'], viewport: { width: 360, height: 740 } },
  { nome: 'cel-412', ...devices['Pixel 7'] },
  { nome: 'cel-320', ...devices['iPhone SE'], viewport: { width: 320, height: 568 } },
];
// as páginas conferidas (testes/paginas.cjs: as mesmas da conferência dos textos fixos)
const PAGINAS = require('./paginas.cjs');

// roda dentro da página: devolve a lista de problemas
function examinar(seletorRaiz) {
  const raiz = (seletorRaiz && document.querySelector(seletorRaiz)) || document.body;
  const probs = [];
  const vw = document.documentElement.clientWidth;
  const visivel = (e) => {
    if (!(e instanceof Element)) return false;
    const r = e.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) return false;
    const cs = getComputedStyle(e);
    if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) === 0) return false;
    if (e.closest('[hidden], [aria-hidden="true"]')) return false;
    // fora da tela de propósito (os só-para-leitores-de-tela)
    if (cs.position === 'absolute' && (r.right < 0 || r.left > vw * 3)) return false;
    if (cs.clip !== 'auto' || cs.clipPath !== 'none') return false;
    return true;
  };
  const nomear = (e) => {
    let s = e.tagName.toLowerCase();
    if (e.getAttribute('id')) s += '#' + e.getAttribute('id');
    if (e.classList.length) s += '.' + [...e.classList].join('.');
    const p = e.parentElement;
    let anc = '';
    let a = p;
    for (let i = 0; a && i < 3; i++, a = a.parentElement) {
      anc = a.tagName.toLowerCase() + (a.getAttribute('id') ? '#' + a.getAttribute('id') : '') + (a.classList.length ? '.' + [...a.classList].slice(0, 2).join('.') : '') + (anc ? ' > ' + anc : '');
    }
    const t = (e.innerText || e.value || '').replace(/\s+/g, ' ').trim().slice(0, 50);
    return anc + ' > ' + s + (t ? ' «' + t + '»' : '');
  };
  // 1. a página rola de lado
  const sw = document.documentElement.scrollWidth;
  if (sw > vw + 1) {
    // quem passa da borda
    const culpados = [...document.body.querySelectorAll('*')].filter((e) => visivel(e) && e.getBoundingClientRect().right > vw + 1)
      .filter((e) => !e.parentElement || e.parentElement.getBoundingClientRect().right <= vw + 1).slice(0, 4).map(nomear);
    probs.push({ tipo: 'rolagem-lateral', det: 'scrollWidth ' + sw + ' > ' + vw, quem: culpados.join(' | ') });
  }
  // rola de lado de propósito (uma tabela larga dentro de .rolagem): o ancestral rola na horizontal e não na vertical. Um painel
  // que rola na vertical (o painel do relógio ao lado da lista) também fica com overflow-x auto, mas o que passa da largura
  // dele é defeito: o conteúdo dele é conferido como o resto
  const rolaDeLado = (a) => {
    const cs = getComputedStyle(a);
    return /(auto|scroll)/.test(cs.overflowX) && a.scrollHeight <= a.clientHeight + 1;
  };
  const rolaveis = (e) => {
    for (let a = e.parentElement; a && a !== document.body; a = a.parentElement) {
      if (rolaDeLado(a)) return true;
    }
    return false;
  };
  // um painel que rola na vertical e, por dentro, ficou mais largo que ele (o conteúdo vaza para o lado)
  for (const a of raiz.querySelectorAll('*')) {
    const cs = getComputedStyle(a);
    if (/(auto|scroll)/.test(cs.overflowY) && a.scrollHeight > a.clientHeight + 1 && a.scrollWidth > a.clientWidth + 1 && visivel(a)) {
      probs.push({ tipo: 'painel-rola-de-lado', det: a.scrollWidth + ' > ' + a.clientWidth, quem: nomear(a) });
    }
  }
  const todos = [...raiz.querySelectorAll('*')].filter(visivel);
  for (const e of todos) {
    const cs = getComputedStyle(e);
    const r = e.getBoundingClientRect();
    // 2. sai da caixa: o ancestral mais próximo com borda ou fundo (um quadro, um cartão, uma etiqueta)
    if (cs.position !== 'fixed' && cs.position !== 'absolute' && !rolaveis(e)) {
      for (let a = e.parentElement; a && a !== document.body; a = a.parentElement) {
        const ca = getComputedStyle(a);
        const temCaixa = parseFloat(ca.borderLeftWidth) > 0 || parseFloat(ca.borderRightWidth) > 0
          || (ca.backgroundColor !== 'rgba(0, 0, 0, 0)' && ca.backgroundColor !== 'transparent');
        if (!temCaixa) continue;
        if (a.tagName === 'TR' || a.tagName === 'TBODY' || a.tagName === 'THEAD' || a.tagName === 'TABLE') break;
        const ra = a.getBoundingClientRect();
        const dentroDir = ra.right - parseFloat(ca.borderRightWidth);
        const dentroEsq = ra.left + parseFloat(ca.borderLeftWidth);
        const sai = Math.max(r.right - dentroDir, dentroEsq - r.left);
        // só o culpado: o pai dele (se não for a própria caixa) não sai
        const pai = e.parentElement;
        const rp = pai.getBoundingClientRect();
        const paiSai = pai !== a && Math.max(rp.right - dentroDir, dentroEsq - rp.left) > 1.5;
        if (sai > 1.5 && (ca.overflowX === 'visible' || !rolaDeLado(a)) && !paiSai) {
          probs.push({ tipo: 'sai-da-caixa', det: Math.round(sai) + 'px para fora de ' + nomear(a).split(' > ').pop(), quem: nomear(e) });
        }
        break;
      }
    }
    // 3. etiqueta (fundo ou borda) partida em mais de uma linha
    if (cs.display === 'inline' && e.tagName !== 'CODE' && e.getClientRects().length > 1
      && (parseFloat(cs.borderTopWidth) > 0 || (cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent'))) {
      probs.push({ tipo: 'etiqueta-partida', det: e.getClientRects().length + ' pedaços', quem: nomear(e) });
    }
    // 4. texto cortado (overflow escondido, sem reticências) ou maior que o botão
    const corta = cs.overflowX === 'hidden' || cs.overflowX === 'clip';
    if (!['INPUT', 'SELECT', 'TEXTAREA'].includes(e.tagName) && (corta && cs.textOverflow !== 'ellipsis' || e.tagName === 'BUTTON' || (e.tagName === 'A' && e.classList.contains('botao')))
      && e.scrollWidth > e.clientWidth + 2 && e.clientWidth > 0 && !/(auto|scroll)/.test(cs.overflowX)) {
      probs.push({ tipo: 'texto-cortado-ou-vazando', det: e.scrollWidth + ' > ' + e.clientWidth, quem: nomear(e) });
    }
    // 4a. o conteúdo (um texto que não quebra, uma etiqueta) passa da caixa do próprio elemento, que não rola nem corta
    if (!corta && !/(auto|scroll)/.test(cs.overflowX) && e.scrollWidth > e.clientWidth + 2 && e.clientWidth > 0
      && !['INPUT', 'SELECT', 'TEXTAREA', 'TABLE', 'TBODY', 'THEAD', 'TR', 'PRE'].includes(e.tagName) && cs.display !== 'inline' && !rolaveis(e)) {
      probs.push({ tipo: 'conteudo-vaza', det: e.scrollWidth + ' > ' + e.clientWidth, quem: nomear(e) });
    }
    // 4b. botão (ou etiqueta) com o texto partido em duas linhas: "Abrir" numa, "foto" na outra (as linhas de verdade do texto)
    // (a etiqueta de estado que quebra de propósito, nos espaços, fica de fora: white-space normal)
    if ((e.tagName === 'BUTTON' && !e.matches('form.excluir-conjunto button') || e.classList.contains('botao') || (e.classList.contains('estado') && cs.whiteSpace === 'nowrap')) && (e.innerText || '').trim().length > 0
      && !/\n/.test(e.innerText.trim())) {
      const tops = [];
      // A imagem e a legenda de uma galeria ocupam alturas diferentes, sem partir uma linha de texto.
      const textos = document.createTreeWalker(e, NodeFilter.SHOW_TEXT);
      for (let no = textos.nextNode(); no; no = textos.nextNode()) {
        if (!no.textContent.trim() || !visivel(no.parentElement)) continue;
        const rg = document.createRange();
        rg.selectNodeContents(no);
        for (const q of rg.getClientRects()) {
          if (q.width < 1) continue;
          if (!tops.some((t) => Math.abs(t - q.top) < 4)) tops.push(q.top);
        }
      }
      if (tops.length > 1) {
        probs.push({ tipo: 'texto-partido', det: tops.length + ' linhas', quem: nomear(e) });
      }
    }
    // 4c. palavra ou número partido no meio, em duas linhas: "R$ 11.757,85" caindo letra por letra numa coluna que encolheu, "Esc/ala".
    // Quem faz isso é o que quebra em qualquer ponto (overflow-wrap: anywhere, word-break: break-all): ele pega a palavra que caberia
    // inteira na linha de baixo e zera a largura mínima das colunas. (O break-word só parte o que não cabe de jeito nenhum.) Só
    // palavras (letras) e números/datas/dinheiro: identificadores, fórmulas e endereços compridos partem de propósito
    // (no celular estreito, abaixo de 700px, as tabelas apertam de propósito e uma data pode partir: ali só o rodapé de totais e o
    // cabeçalho da Hoje contam; de 700px para cima, qualquer lugar)
    if ((cs.overflowWrap === 'anywhere' || cs.wordBreak === 'break-all') && !e.closest('pre, code') && (vw >= 700 || e.closest('.mini-total, .hoje'))) {
      for (const no of e.childNodes) {
        if (no.nodeType !== 3 || !no.textContent.trim() || no.textContent.length > 400) continue;
        const palavras = /\S{3,24}/g;
        let m;
        while ((m = palavras.exec(no.textContent))) {
          const w = m[0].replace(/^[(\["'«]+|[)\]"'»;:!?]+$/g, '');
          if (!(/^\p{L}+[.,]?$/u.test(w) || /^[\d.,:\/%$+-]+$/.test(w))) continue;
          const rg = document.createRange();
          rg.setStart(no, m.index); rg.setEnd(no, m.index + m[0].length);
          const tops = [];
          for (const q of rg.getClientRects()) {
            if (q.width < 1) continue;
            if (!tops.some((t) => Math.abs(t - q.top) < 4)) tops.push(q.top);
          }
          if (tops.length > 1) {
            probs.push({ tipo: 'palavra-partida', det: '«' + m[0] + '» em ' + tops.length + ' linhas', quem: nomear(e) });
            break;
          }
        }
      }
    }
  }
  // 5. irmãos que se sobrepõem
  const pais = new Set(todos.map((e) => e.parentElement));
  for (const p of pais) {
    if (!p) continue;
    const filhos = [...p.children].filter((c) => visivel(c) && !['absolute', 'fixed', 'sticky'].includes(getComputedStyle(c).position)
      && !['inline'].includes(getComputedStyle(c).display) && getComputedStyle(c).float === 'none');
    for (let i = 0; i < filhos.length; i++) {
      const a = filhos[i].getBoundingClientRect();
      for (let j = i + 1; j < filhos.length; j++) {
        const b = filhos[j].getBoundingClientRect();
        const x = Math.min(a.right, b.right) - Math.max(a.left, b.left);
        const y = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
        if (x > 2 && y > 2) {
          probs.push({ tipo: 'sobreposicao', det: Math.round(x) + 'x' + Math.round(y), quem: nomear(filhos[i]) + '  ⟷  ' + nomear(filhos[j]) });
        }
      }
    }
  }
  // 5a. o ⓘ de ajuda solto: tem de ficar colado ao que explica (o texto do rótulo ou o campo, na mesma linha e a no máximo 24px);
  // sozinho numa linha, ou longe do rótulo (embaixo do campo, na ponta da linha), não dá para saber de onde é
  for (const b of raiz.querySelectorAll('button.ajuda-i')) {
    if (!visivel(b) || !b.parentElement) continue;
    const rb = b.getBoundingClientRect();
    const vizinhos = [];
    for (const no of b.parentElement.childNodes) {
      if (no === b) continue;
      if (no.nodeType === 3 && no.textContent.trim()) {
        const rg = document.createRange();
        rg.selectNodeContents(no);
        vizinhos.push(...[...rg.getClientRects()].filter((q) => q.width >= 1));
      } else if (no.nodeType === 1 && visivel(no) && !no.matches('button.ajuda-i')) {
        vizinhos.push(...no.getClientRects());
      }
    }
    const colado = vizinhos.some((q) => Math.min(q.bottom, rb.bottom) - Math.max(q.top, rb.top) >= rb.height * .5
      && Math.max(rb.left - q.right, q.left - rb.right) <= 24);
    if (!b.getAttribute('aria-label') || !b.getAttribute('data-ajuda')) {
      probs.push({ tipo: 'ajuda-sem-nome', det: 'o ⓘ sem aria-label ou sem a explicação que abre (data-ajuda)', quem: nomear(b.parentElement) });
    }
    if (!colado) {
      probs.push({ tipo: 'ajuda-solta', det: 'o ⓘ não encosta no rótulo nem no campo que explica', quem: nomear(b.parentElement) });
    }
  }
  // Cartões independentes: uma linha da grade não pode deixar uma coluna vazia até o cartão vizinho terminar.
  // O fim mais baixo da coluna é natural; o defeito é um vão interno em que o próximo cartão poderia começar antes.
  for (const grade of raiz.querySelectorAll('#form-config .config-grade, #form-config .parametros-sistema')) {
    const rg = grade.getBoundingClientRect();
    const cs = getComputedStyle(grade);
    const topo = rg.top + parseFloat(cs.paddingTop || 0) + parseFloat(cs.borderTopWidth || 0);
    const limite = Math.max(6 * parseFloat(getComputedStyle(document.documentElement).fontSize), 3 * Math.max(parseFloat(cs.rowGap) || 0, parseFloat(cs.columnGap) || 0));
    const cartoes = [...grade.children].filter(e => e.matches('.cartao-config, .par-campo') && visivel(e)).map(e => ({e, r: e.getBoundingClientRect()}));
    for (const atual of cartoes) {
      const acima = cartoes.filter(outro => outro !== atual && outro.r.bottom <= atual.r.top + 1
        && Math.min(outro.r.right, atual.r.right) - Math.max(outro.r.left, atual.r.left) > Math.min(outro.r.width, atual.r.width) * .8);
      const fim = acima.length ? Math.max(...acima.map(outro => outro.r.bottom)) : topo;
      const vazio = atual.r.top - fim;
      if (vazio > limite) {
        probs.push({tipo: 'espaco-aproveitavel', det: Math.round(vazio) + 'px vazios antes do cartão (limite ' + Math.round(limite) + 'px)', quem: nomear(atual.e)});
      }
    }
    // A sobra no fim só é um defeito se um cartão mais abaixo, em outra coluna, couber nela e puder subir bastante.
    // Uma coluna curta ao lado de um único cartão alto não tem como preencher sua sobra e não deve reprovar.
    const colunas = cartoes.filter((c, i) => !cartoes.slice(0, i).some(outro => Math.abs(outro.r.left - c.r.left) < 2));
    const fundo = Math.max(topo, ...cartoes.map(c => c.r.bottom));
    const intervalo = parseFloat(cs.columnGap) || 0;
    for (const coluna of colunas) {
      const fim = Math.max(...cartoes.filter(c => Math.abs(c.r.left - coluna.r.left) < 2).map(c => c.r.bottom));
      const cabe = cartoes.find(c => Math.abs(c.r.left - coluna.r.left) >= 2 && c.r.width <= coluna.r.width + 2
        && c.r.top - fim > limite && c.r.height + intervalo <= fundo - fim);
      if (cabe) {
        probs.push({tipo: 'espaco-aproveitavel', det: Math.round(fundo - fim) + 'px livres em outra coluna; este cartão pode subir ' + Math.round(cabe.r.top - fim - intervalo) + 'px', quem: nomear(cabe.e)});
      }
    }
  }
  // 6. texto por cima de texto, ou por cima de um botão ou campo, que não são pai nem filho um do outro (o conteúdo que vaza da caixa
  // e cai sobre o que vem depois: o botão com flex-basis em porcentagem numa coluna, por exemplo). Só o que rola junto com a página:
  // fica de fora o que é fixo, sticky ou absoluto (menus, gaveta, rótulos sobrepostos de propósito) e o que está cortado pela caixa
  {
    // o retângulo recortado pelas caixas que cortam o que passa delas (overflow hidden, clip, auto, scroll: o próprio elemento, se for o
    // caso, e os de fora); null quando nada sobra à vista. O texto com reticências, por exemplo, só vale até a borda da célula
    const recorta = (e, r, doProprio) => {
      let c = { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
      for (let a = doProprio ? e : e.parentElement; a && a !== document.body; a = a.parentElement) {
        const ca = getComputedStyle(a);
        if (/(hidden|auto|scroll|clip)/.test(ca.overflowX + ca.overflowY)) {
          const ra = a.getBoundingClientRect();
          c = { left: Math.max(c.left, ra.left), right: Math.min(c.right, ra.right), top: Math.max(c.top, ra.top), bottom: Math.min(c.bottom, ra.bottom) };
          if (c.right - c.left < 3 || c.bottom - c.top < 3) return null;
        }
      }
      return c;
    };
    const flutua = (e) => {
      for (let a = e; a && a !== document.body; a = a.parentElement) {
        const ps = getComputedStyle(a).position;
        if (ps === 'fixed' || ps === 'sticky' || ps === 'absolute') return true;
      }
      return false;
    };
    const itens = [];
    const percorre = document.createTreeWalker(raiz, NodeFilter.SHOW_TEXT);
    for (let no = percorre.nextNode(); no; no = percorre.nextNode()) {
      const pai = no.parentElement;
      if (!no.textContent.trim() || !pai || !visivel(pai) || ['SCRIPT', 'STYLE', 'OPTION', 'SELECT', 'TEXTAREA'].includes(pai.tagName) || flutua(pai)) continue;
      const rg = document.createRange();
      rg.selectNodeContents(no);
      for (const q of rg.getClientRects()) {
        const rc = q.width < 3 || q.height < 3 ? null : recorta(pai, q, true);
        if (!rc) continue;
        itens.push({ e: pai, r: { left: rc.left, right: rc.right, top: rc.top + scrollY, bottom: rc.bottom + scrollY }, texto: true });
      }
    }
    // as caixas dos botões e campos (o texto de dentro deles é do próprio botão)
    for (const c of raiz.querySelectorAll('button, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea')) {
      if (!visivel(c) || flutua(c)) continue;
      const rc = recorta(c, c.getBoundingClientRect(), false);
      if (!rc) continue;
      itens.push({ e: c, r: { left: rc.left, right: rc.right, top: rc.top + scrollY, bottom: rc.bottom + scrollY }, texto: false });
    }
    itens.sort((a, b) => a.r.top - b.r.top);
    const parentesco = (a, b) => a === b || a.contains(b) || b.contains(a);
    const vistos6 = new Set();
    for (let i = 0; i < itens.length; i++) {
      const a = itens[i];
      for (let j = i + 1; j < itens.length && itens[j].r.top < a.r.bottom - 2; j++) {
        const b = itens[j];
        if (!a.texto && !b.texto) continue;
        if (parentesco(a.e, b.e)) continue;
        const x = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
        const y = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
        const menor = Math.min(a.r.bottom - a.r.top, b.r.bottom - b.r.top);
        if (x > 4 && y > Math.max(4, menor * .35)) {
          const chave = nomear(a.e) + '|' + nomear(b.e);
          if (vistos6.has(chave)) continue;
          vistos6.add(chave);
          probs.push({ tipo: 'texto-sobreposto', det: Math.round(x) + 'x' + Math.round(y) + 'px', quem: nomear(a.e) + '  ⟷  ' + nomear(b.e) });
        }
      }
    }
  }
  return probs;
}

async function auditarTela(b, tela, fp, relatorio, total) {
  const { nome: _n, ...opts } = tela;
  const c = await b.newContext({ ...opts, httpCredentials: { username: USUARIO, password: SENHA }, locale: LINGUA || 'pt-BR', timezoneId: 'America/Sao_Paulo' });
  if (LINGUA) await c.addCookies([{ name: 'lingua', value: LINGUA, url: BASE }]);
  for (const pg of PAGINAS) {
    if (fp && !fp.test(pg.nome)) continue;
    const p = await c.newPage();
    const erros = [];
    p.on('pageerror', (e) => erros.push('JS: ' + e.message));
    p.on('console', (m) => { if (m.type() === 'error') erros.push('console: ' + m.text()); });
    p.on('dialog', (d) => d.dismiss());
    if (pg.flutuante) {
      // o modo flutuante sem gravar na configuração (as telas rodam em paralelo): a resposta da ficha diz flutuante
      await p.route('**/api.php?*recurso=ficha*', async (r) => {
        const res = await r.fetch(); const j = await res.json(); j.painel_modo = 'flutuante'; r.fulfill({ response: res, json: j });
      });
    }
    // com muitas telas ao mesmo tempo o servidor de teste demora: prazo maior, e uma segunda tentativa
    try {
      await p.goto(BASE + pg.url, { waitUntil: 'networkidle', timeout: 90000 });
    } catch (e) {
      await p.goto(BASE + pg.url, { waitUntil: 'networkidle', timeout: 90000 });
    }
    await p.waitForTimeout(300);
    if (pg.abrir) {
      const lnk = await p.$('#tabela-relogios tbody tr');
      if (lnk) { await lnk.click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(400); }
    }
    await p.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
    await p.waitForTimeout(100);
    let probs = await p.evaluate(examinar, null);
    const menus = await p.$$eval('select[data-mostra]', (ss) => ss.map((s, i) => ({ i, n: s.options.length })));
    for (const m of menus) {
      for (let k = 1; k < m.n; k++) {
        const ok = await p.evaluate(([i, k]) => {
          const s = document.querySelectorAll('select[data-mostra]')[i];
          if (!s || s.offsetParent === null) return false;
          s.selectedIndex = k; s.dispatchEvent(new Event('change', { bubbles: true }));
          document.querySelectorAll('[data-auditar]').forEach((x) => x.removeAttribute('data-auditar'));
          (s.closest('form, .quadro, section') || document.body).setAttribute('data-auditar', '1');
          return true;
        }, [m.i, k]);
        if (!ok) continue;
        await p.waitForTimeout(40);
        const mais = await p.evaluate(examinar, '[data-auditar]');
        probs = probs.concat(mais.map((x) => ({ ...x, det: x.det + ' [menu ' + m.i + ' opção ' + k + ']' })));
      }
    }
    // SO=tipo1,tipo2: só esses tipos de problema contam (o CI dos botões de ajuda confere só o ⓘ: SO=ajuda-solta,ajuda-sem-nome)
    if (process.env.SO) {
      const so = process.env.SO.split(',');
      probs = probs.filter((x) => so.includes(x.tipo));
    }
    const vistos = new Set();
    probs = probs.filter((x) => { const k = x.tipo + x.quem; if (vistos.has(k)) return false; vistos.add(k); return true; });
    // o problema no menu do alto: como ele estava na hora (aberto pelo botão? o CSS do celular valia?), para saber a causa sem a captura
    if (probs.some((x) => /nav\.topo|menu-itens/.test(x.quem))) {
      const menu = await p.evaluate(() => {
        const topo = document.querySelector('nav.topo');
        const itens = document.getElementById('menu-itens');
        const botao = document.querySelector('.menu-botao');
        return 'menu: ' + JSON.stringify({ aberto: !!topo && topo.classList.contains('aberto'), botao: botao ? botao.getAttribute('aria-expanded') : null,
          celular: matchMedia('(max-width: 900px)').matches, largura: innerWidth, documento: document.documentElement.clientWidth,
          itens: itens ? getComputedStyle(itens).display : null, grupos: [...document.querySelectorAll('.menu-grupo.aberto')].length,
          foco: document.activeElement ? document.activeElement.tagName + '.' + document.activeElement.className : null, rolagem: scrollY });
      });
      probs.forEach((x) => { if (/nav\.topo|menu-itens/.test(x.quem)) x.det += ' [' + menu + ']'; });
    }
    erros.forEach((e) => probs.push({ tipo: 'erro', det: e, quem: '' }));
    // a captura só serve para olhar: com a máquina carregada (15 tamanhos ao mesmo tempo) ela pode passar do tempo esperando
    // as fontes; tenta de novo, e se falhar outra vez segue sem ela (os problemas já foram conferidos)
    for (let t = 0; t < 2; t++) {
      try {
        await p.screenshot({ path: SAIDA + '/' + pg.nome + '__' + tela.nome + '.png', fullPage: true, timeout: 60000 });
        break;
      } catch (e) {
        if (t === 1) console.log('sem a captura de ' + pg.nome + ' em ' + tela.nome + ': ' + e.message.split('\n')[0]);
      }
    }
    for (const x of probs) {
      relatorio.push([tela.nome, pg.nome, x.tipo, x.det, x.quem].join('\t'));
      total[x.tipo] = (total[x.tipo] || 0) + 1;
    }
    await p.close();
  }
  await c.close();
}

// O espelho: a ficha (ficha.php) e o painel da página Hoje mostram o mesmo relógio na mesma ordem. O tamanho e a posição
// mudam com a largura, mas a cabeça, o Editar cadastro, os botões das seções, as seções e os quadros de cada uma vêm na mesma
// sequência; só o "Abrir a ficha ↗" é do painel. Cada relógio do cenário, num computador de 1440px: o que falta num lado ou
// a ordem diferente vira um problema "espelho". Roda antes das 15 telas, num navegador só dele: no fim delas o navegador do CI
// pode já ter caído (a captura enorme da Ajuda no celular), e o espelho não pode depender disso.
async function conferirDetector(b) {
  const c = await b.newContext({viewport: {width: 320, height: 480}});
  try {
    const p = await c.newPage();
    await p.setContent('<style>button{font:16px sans-serif}#foto{display:flex;flex-direction:column}#foto img{width:80px;height:80px}#foto span{white-space:nowrap}#quebra{width:65px;white-space:normal}</style><button id="foto"><img alt="" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"><span>Foto do cadastro</span></button><button id="quebra">Texto comprido que quebra</button>');
    const problemas = (await p.evaluate(examinar)).filter(x => x.tipo === 'texto-partido');
    if (problemas.some(x => x.quem.includes('button#foto')) || !problemas.some(x => x.quem.includes('button#quebra'))) {
      throw new Error('O detector deve ignorar a imagem e continuar detectando o texto partido.');
    }
    await p.setViewportSize({width: 1100, height: 800});
    const cartoes = '<section class="cartao-config" style="height:160px">A</section><section class="cartao-config" style="height:720px">B</section><section class="cartao-config" style="height:140px">C</section><section class="cartao-config" style="height:180px">D</section>';
    const compactos = [160, 720, 140, 180].map(h => '<section class="cartao-config" style="height:' + h + 'px;grid-row-end:span ' + Math.ceil((h + 16) / 4) + '">Cartão</section>').join('');
    const colunaLonga = [500, 300, 500, 100, 750, 100, 100].map(h => '<section class="cartao-config" style="height:' + h + 'px">Cartão</section>').join('');
    await p.setContent('<style>.config-grade{width:536px;margin-bottom:20px;gap:16px}.cartao-config{break-inside:avoid;margin:0 0 16px}#linhas,#compacta{display:grid;grid-template-columns:1fr 1fr;align-items:start}#linhas section,#compacta section{margin:0}#compacta{grid-auto-rows:4px;row-gap:0;grid-auto-flow:dense}#colunas{width:836px;columns:3;column-gap:16px;column-fill:auto;height:1000px}#unica{display:grid;grid-template-columns:1fr}</style><form id="form-config"><div class="config-grade" id="linhas">' + cartoes + '</div><div class="config-grade" id="colunas">' + colunaLonga + '</div><div class="config-grade" id="compacta">' + compactos + '</div><div class="config-grade" id="unica">' + cartoes + '</div></form>');
    const espacos = (await p.evaluate(examinar)).filter(x => x.tipo === 'espaco-aproveitavel');
    if (!espacos.some(x => x.quem.includes('#linhas')) || !espacos.some(x => x.quem.includes('#colunas'))
      || espacos.some(x => /#compacta|#unica/.test(x.quem))) {
      throw new Error('O detector deve pegar vãos internos e sobras que comportam outro cartão, aceitando a distribuição compacta e a coluna única.');
    }
    // o ⓘ de ajuda: embaixo do campo e sozinho numa linha são soltos; colado ao texto do rótulo (o campo desce) não
    const bi = '<button type="button" class="ajuda-i" data-ajuda="x" aria-label="Explicação" style="width:20px;height:20px;padding:0">ⓘ</button>';
    await p.setContent('<label id="ruim" style="display:flex;flex-direction:column;width:300px">Escolher de<select style="width:100%"><option>a</option></select>' + bi + '</label>'
      + '<label id="bom" style="display:flex;flex-wrap:wrap;align-items:center;width:300px">Escolher de' + bi.replace('style="', 'style="order:1;')
      + '<select style="order:2;flex:1 1 100%"><option>a</option></select></label><p id="so" style="margin:8px 0">' + bi + '</p>'
      + '<button type="button" class="ajuda-i" style="width:20px;height:20px">ⓘ</button>');
    const soltas = await p.evaluate(examinar);
    const soltos = soltas.filter(x => x.tipo === 'ajuda-solta');
    if (!soltos.some(x => x.quem.includes('label#ruim')) || !soltos.some(x => x.quem.includes('p#so')) || soltos.some(x => x.quem.includes('label#bom'))
      || soltas.filter(x => x.tipo === 'ajuda-sem-nome').length !== 1) {
      throw new Error('O detector deve pegar o ⓘ embaixo do campo, o sozinho e o sem nome, e aceitar o colado ao rótulo.');
    }
    // texto por cima de texto ou de botão: o que pisa no que vem depois (pego); o texto cortado com reticências, por mais largo que seja,
    // não (aceito)
    await p.setContent('<p id="primeira" style="margin:0">A garantia de rodízio explicada aqui</p><p id="nota" style="margin:-10px 0 0">A nota vem dos critérios</p>'
      + '<button id="aplicar" style="margin-top:-12px">Aplicar modo</button>'
      + '<table id="cortada"><tr><td style="width:60px;max-width:60px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Cronógrafos automáticos muito compridos</td><td>Em uso</td></tr></table>');
    const sobrepostos = (await p.evaluate(examinar)).filter(x => x.tipo === 'texto-sobreposto');
    if (!sobrepostos.some(x => /nota/.test(x.quem)) || !sobrepostos.some(x => /Aplicar/.test(x.quem)) || sobrepostos.some(x => /cortada/.test(x.quem) || /Cronógrafos/.test(x.quem))) {
      throw new Error('O detector deve pegar o texto e o botão que pisam no que vem antes e aceitar o texto cortado com reticências: ' + JSON.stringify(sobrepostos.map(x => x.quem)));
    }
  } finally {
    await c.close();
  }
}

async function conferirEspelho(relatorio, total) {
  const b = await chromium.launch();
  try {
    await espelho(b, relatorio, total);
  } catch (e) {
    relatorio.push(['pc-1440', 'espelho', 'erro', 'a conferência parou: ' + e.message.split('\n')[0], ''].join('\t'));
    total.erro = (total.erro || 0) + 1;
  }
  await b.close();
}

async function espelho(b, relatorio, total) {
  const c = await b.newContext({ viewport: { width: 1440, height: 900 }, httpCredentials: { username: USUARIO, password: SENHA }, locale: LINGUA || 'pt-BR', timezoneId: 'America/Sao_Paulo' });
  if (LINGUA) await c.addCookies([{ name: 'lingua', value: LINGUA, url: BASE }]);
  const p = await c.newPage();
  // os números não contam: o tempo corre entre a ficha e o Hoje ("no rodízio desde 08:31 (0 h 0 min)" vira "0 h 1 min")
  const estrutura = (raiz) => {
    const d = document.querySelector(raiz + ' .detalhe');
    return d ? [...d.querySelectorAll('.ficha-cabeca, .atalho-pulso, details.cadastro-painel > summary, .secoes-atalhos button, .secao-titulo, .quadro > h2')]
      .map((e) => e.className + ': ' + e.textContent.trim().replace(/\d+/g, '#').slice(0, 40)) : [];
  };
  for (const id of [1, 3, 4, 5, 7, 8, 10]) {
    await p.goto(BASE + 'ficha.php?id=' + id, { waitUntil: 'networkidle', timeout: 90000 });
    const ficha = await p.evaluate(estrutura, '#detalhe');
    await p.goto(BASE + 'index.php?r=' + id, { waitUntil: 'networkidle', timeout: 90000 });
    const hoje = await p.evaluate(estrutura, '.col-detalhe');
    const det = ficha.length === 0 || hoje.length === 0 ? 'não abriu (ficha ' + ficha.length + ', Hoje ' + hoje.length + ')'
      : (JSON.stringify(ficha) !== JSON.stringify(hoje) ? 'ficha: ' + ficha.join(' › ') + '  ≠  Hoje: ' + hoje.join(' › ') : '');
    if (det !== '') {
      relatorio.push(['pc-1440', 'espelho-' + id, 'espelho', det, ''].join('\t'));
      total.espelho = (total.espelho || 0) + 1;
    }
  }
  await c.close();
}

// Os dados de estresse: o cenário tem nomes e números curtos, e o que quebra no uso de verdade é o comprido. Antes de conferir,
// pela própria API, o banco do cenário fica "feio" de propósito: nomes longos de relógio, de grupo e do winder, observações compridas e
// fotos (a cabeça do painel muda com elas). Só no banco do cenário (ele é apagado e recriado a cada rodada); ESTRESSE=0 deixa
// os dados como estão.
async function estressar() {
  const api = async (campos) => {
    const corpo = new URLSearchParams();
    for (const [k, v] of Object.entries(campos)) corpo.append(k, String(v));
    const r = await fetch(BASE + 'api.php', { method: 'POST', body: corpo,
      headers: { Authorization: 'Basic ' + Buffer.from(USUARIO + ':' + SENHA).toString('base64') } });
    const j = await r.json().catch(() => ({}));
    return j.ok === true;
  };
  const resultados = [];
  // um PNG de 1x1 (a foto)
  const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgYPgPAAEDAQAIicLsAAAAAElFTkSuQmCC';
  // Nomes sem espaços exercitam a quebra do cartão e a reticência da legenda, além dos títulos compridos.
  const dd = await fetch(BASE + 'api.php?recurso=documentos&relogio=1', {
    headers: { Authorization: 'Basic ' + Buffer.from(USUARIO + ':' + SENHA).toString('base64') }
  }).then(r => r.json());
  const categoria = dd.categorias.find(c => c.identificador === 'manual');
  if (!categoria) throw new Error('Falta a categoria Manual no cenário do layout.');
  const nomeLongo = 'Manual_do_movimento_com_referencia_comprida_e_especificacoes_tecnicas_para_uso_diario';
  for (const [ext, conteudo] of [['pdf', Buffer.from('%PDF-1.4 manual de teste').toString('base64')], ['png', png]]) {
    const cat = ext === 'png' ? dd.categorias.find(c => c.identificador === 'foto') || categoria : categoria;
    const ok = await api({recurso:'documentos', acao:'enviar', relogio_id:1, categoria_id:cat.id,
      titulo:nomeLongo, arquivo_nome:nomeLongo+'.'+ext, arquivo_base64:conteudo});
    if (!ok) throw new Error('Não criou o documento de nome comprido no cenário do layout.');
    resultados.push(ok);
  }
  const nomes = { 3: 'Orient Bambino Version 2 Open Heart Automático RA-AC0001S10B', 5: 'Casio F-91W-1YEF Edição Comemorativa de 35 anos' };
  for (const [id, nome] of Object.entries(nomes)) {
    resultados.push(await api({ recurso: 'relogio', acao: 'salvar', id, nome }));
  }
  resultados.push(await api({ recurso: 'relogio', acao: 'salvar', id: 1, 'valores[observacao]':
    'Comprado na viagem de 2024; a pulseira original foi trocada pela de silicone preta, e a de couro está guardada na caixa azul junto com o carregador reserva.' }));
  resultados.push(await api({ recurso: 'arvore', acao: 'renomear', id: 4, nome: 'Automático com reserva de marcha estendida de 80 horas' }));
  // o desejado (10): marca, referência e link compridos, preço-alvo alto, a foto
  resultados.push(await api({ recurso: 'relogio', acao: 'salvar', id: 10, 'valores[marca]': 'Casio G-Shock Master of G Edição Limitada',
    'valores[referencia]': 'GW-M5610U-1ER-ANIVERSARIO-40-ANOS', 'valores[link]': 'https://www.exemplo-de-loja-com-endereco-comprido.com.br/relogios/casio/g-shock/gw-m5610u-1er?origem=lista',
    'valores[preco_alvo]': '1234567,89' }));
  // o winder Quarto do cenário (achado pelo nome: os números dependem de quantos winders o cenário criou antes): nome, marca,
  // modelo, observação e link do manual compridos, os limites da almofada, e na Pos. 2 um programa de nome comprido, em blocos,
  // com um movimento de nome comprido
  const lw = await (await fetch(BASE + 'api.php?recurso=winders', {
    headers: { Authorization: 'Basic ' + Buffer.from(USUARIO + ':' + SENHA).toString('base64') } })).json().catch(() => ({}));
  const quarto = (lw.winders || []).find((w) => w.nome === 'Quarto') || { id: 0, posicoes: [] };
  const pos2 = (quarto.posicoes.find((p) => p.nome === 'Pos. 2') || { id: 0 }).id;
  resultados.push(await api({ recurso: 'winders', acao: 'salvar', id: quarto.id, nome: 'Winder de quatro posições do quarto de hóspedes, em cima da cômoda',
    observacao: 'Comprado junto com a caixa de viagem; a Pos. 3 faz barulho quando gira no sentido anti-horário, então fica só no horário.',
    'valores[winder_marca]': 'Wolf Designs London', 'valores[winder_modelo]': 'Viceroy Module 2.7 Quad Watch Winder Macassar' }));
  resultados.push(await api({ recurso: 'winders', acao: 'salvar', id: quarto.id,
    'valores[winder_manual]': 'https://www.exemplo-de-fabricante-com-endereco-comprido.com/manuais/winders/viceroy-module-2-7-quad.pdf',
    'valores[winder_caixa_max]': '42', 'valores[winder_pulso_min]': '16,5', 'valores[winder_pulso_max]': '18,5', 'valores[winder_peso_max]': '180' }));
  const mov = async (campos) => {
    const corpo = new URLSearchParams(Object.entries(Object.assign({ recurso: 'movimentos', acao: 'salvar' }, campos)));
    const r = await fetch(BASE + 'api.php', { method: 'POST', body: corpo,
      headers: { Authorization: 'Basic ' + Buffer.from(USUARIO + ':' + SENHA).toString('base64') } });
    const j = await r.json().catch(() => ({}));
    resultados.push(j.ok === true);
    return j.id || 0;
  };
  const giro = await mov({ nome: 'Giro horário lento de 12 min 30 s, o do modo silencioso', tipo: 'horario', minutos: 12, segundos: 30 });
  const parada = await mov({ nome: 'Parada comprida de quarenta e sete minutos e meio', tipo: 'parado', minutos: 47, segundos: 30 });
  const pr = await fetch(BASE + 'api.php', { method: 'POST', body: new URLSearchParams({ recurso: 'programas', acao: 'salvar',
    nome: 'Programa noturno silencioso só no sentido horário, liga três horas', medida: 'tempo', tpd_manual: '1250', funciona: 'blocos',
    liga_horas: '3', para_horas: '9', observacao: 'Para os automáticos que só dão corda num sentido; o resto do dia descansa.', sequencia: giro + ', ' + parada }),
    headers: { Authorization: 'Basic ' + Buffer.from(USUARIO + ':' + SENHA).toString('base64') } }).then((r) => r.json()).catch(() => ({}));
  resultados.push(pr.ok === true);
  resultados.push(await api({ recurso: 'winder_posicoes', acao: 'salvar', id: pos2, nome: 'Posição de baixo à direita', programa_id: pr.id || 0 }));
  for (const id of [1, 3, 4, 5, 10]) {
    resultados.push(await api({ recurso: 'relogio', acao: 'foto', id, foto_base64: png }));
  }
  return resultados.filter((x) => x).length + ' de ' + resultados.length;
}

(async () => {
  const fp = process.argv[2] ? new RegExp(process.argv[2]) : null;
  const ft = process.argv[3] ? new RegExp(process.argv[3]) : null;
  if (process.env.ESTRESSE !== '0') {
    console.log('dados de estresse: ' + (await estressar()) + ' gravações aceitas');
  }
  const total = {};
  const relatorio = [];
  if (!fp || fp.test('ficha')) {
    await conferirEspelho(relatorio, total);
  }
  const b = await chromium.launch();
  await conferirDetector(b);
  await Promise.all(TELAS.filter((t) => !ft || ft.test(t.nome)).map((t) => auditarTela(b, t, fp, relatorio, total)));
  await b.close();
  relatorio.sort();
  fs.writeFileSync(SAIDA + '/relatorio.tsv', relatorio.join('\n') + '\n');
  // agrupado: o mesmo problema na mesma página, com as telas em que aparece
  const grupos = {};
  for (const l of relatorio) {
    const [t, pg, tipo, det, quem] = l.split('\t');
    const k = pg + '\t' + tipo + '\t' + quem.replace(/«.*»/, '');
    (grupos[k] = grupos[k] || { telas: new Set(), ex: det + ' ' + quem }).telas.add(t);
  }
  fs.writeFileSync(SAIDA + '/agrupado.txt', Object.keys(grupos).sort().map((k) => k.split('\t').slice(0, 2).join(' | ') + ' | ' + [...grupos[k].telas].join(',') + '\n    ' + grupos[k].ex).join('\n') + '\n');
  console.log(JSON.stringify(total), relatorio.length, 'problemas');
  process.exit(relatorio.length > 0 ? 1 : 0);
})();
