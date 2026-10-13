// Conferência do layout: todas as telas, em 15 tamanhos (computador de 1100 a 1920px, tablet de pé e deitado, celular de 320 a
// 412px), procurando o que quebra: a página que rola de lado, o elemento que sai da caixa (o quadro, o cartão) em que está, a
// etiqueta (com fundo ou borda) partida em duas linhas, o texto cortado, o botão com o texto partido ("Abrir" numa linha, "foto"
// na outra) e irmãos sobrepostos. Abre os <details> e passa por cada opção dos menus que mostram partes (o quadro Marcar).
// Roda sobre o banco do cenário (testes/cenario.php: os relógios 1, 3, 4, 5, 7 e 8) e precisa do Playwright com o Chromium:
//   BASE=http://127.0.0.1:8101/ RELOGIOS_USUARIO=teste RELOGIOS_SENHA=senha123 node testes/layout.cjs [página] [tela]
// (página e tela: um filtro, expressão regular, no nome; ex.: node testes/layout.cjs "ficha" "cel-"). Grava em testes/layout/
// uma captura de cada tela, o relatorio.tsv (uma linha por problema) e o agrupado.txt (o mesmo problema com as telas em que
// aparece). Termina com 0 se não achou nada, 1 se achou.
const { chromium, devices } = require(process.env.PLAYWRIGHT || require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8101/';
const USUARIO = process.env.RELOGIOS_USUARIO || 'teste';
const SENHA = process.env.RELOGIOS_SENHA || 'senha123';
const SAIDA = process.env.SAIDA || __dirname + '/layout';
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
const PAGINAS = [
  { nome: 'hoje', url: 'index.php' },
  { nome: 'hoje-manual', url: 'index.php#manual' },
  ...[1, 3, 4, 5, 7, 8].map((id) => ({ nome: 'hoje-painel-' + id, url: 'index.php?r=' + id })),
  ...[1, 3, 4, 5, 7, 8].map((id) => ({ nome: 'hoje-flutuante-' + id, url: 'index.php?r=' + id, flutuante: true })),
  { nome: 'ficha-1', url: 'ficha.php?id=1' },
  { nome: 'ficha-3', url: 'ficha.php?id=3' },
  { nome: 'ficha-4', url: 'ficha.php?id=4' },
  { nome: 'ficha-5', url: 'ficha.php?id=5' },
  { nome: 'ficha-7', url: 'ficha.php?id=7' },
  { nome: 'ficha-8', url: 'ficha.php?id=8' },
  { nome: 'ficha-novo', url: 'index.php?novo=1' },
  { nome: 'historico', url: 'historico.php?id=1' },
  { nome: 'historico-4', url: 'historico.php?id=4' },
  { nome: 'plano', url: 'plano.php' },
  { nome: 'criterios', url: 'criterios.php' },
  { nome: 'grupos', url: 'grupos.php' },
  { nome: 'cadastros', url: 'cadastros.php' },
  { nome: 'configuracao', url: 'configuracao.php' },
  { nome: 'documentos', url: 'documentos.php?relogio=1' },
  { nome: 'execucoes', url: 'execucoes.php' },
  { nome: 'usuarios', url: 'usuarios.php' },
  { nome: 'ajuda', url: 'ajuda.php' },
];

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
  const rolaveis = (e) => {
    for (let a = e.parentElement; a && a !== document.body; a = a.parentElement) {
      const cs = getComputedStyle(a);
      if (/(auto|scroll)/.test(cs.overflowX)) return true;
    }
    return false;
  };
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
        if (sai > 1.5 && ca.overflowX === 'visible' && !paiSai) {
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
      const rg = document.createRange();
      rg.selectNodeContents(e);
      const tops = [];
      for (const q of rg.getClientRects()) {
        if (q.width < 1) continue;
        if (!tops.some((t) => Math.abs(t - q.top) < 4)) tops.push(q.top);
      }
      if (tops.length > 1) {
        probs.push({ tipo: 'texto-partido', det: tops.length + ' linhas', quem: nomear(e) });
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
  // 6. linhas de texto que se sobrepõem a outros elementos de texto (por exemplo, valor por cima da tabela do lado): folhas
  return probs;
}

async function auditarTela(b, tela, fp, relatorio, total) {
  const { nome: _n, ...opts } = tela;
  const c = await b.newContext({ ...opts, httpCredentials: { username: USUARIO, password: SENHA }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
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
    await p.goto(BASE + pg.url, { waitUntil: 'networkidle' });
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
    const vistos = new Set();
    probs = probs.filter((x) => { const k = x.tipo + x.quem; if (vistos.has(k)) return false; vistos.add(k); return true; });
    erros.forEach((e) => probs.push({ tipo: 'erro', det: e, quem: '' }));
    await p.screenshot({ path: SAIDA + '/' + pg.nome + '__' + tela.nome + '.png', fullPage: true });
    for (const x of probs) {
      relatorio.push([tela.nome, pg.nome, x.tipo, x.det, x.quem].join('\t'));
      total[x.tipo] = (total[x.tipo] || 0) + 1;
    }
    await p.close();
  }
  await c.close();
}

(async () => {
  const fp = process.argv[2] ? new RegExp(process.argv[2]) : null;
  const ft = process.argv[3] ? new RegExp(process.argv[3]) : null;
  const b = await chromium.launch();
  const total = {};
  const relatorio = [];
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
