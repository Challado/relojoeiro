// Conferência dos textos fixos: nenhum texto que o sistema escreve pode ficar fora do dicionário (o t() das telas e da API).
// Abre todas as telas (testes/paginas.cjs) num idioma de outra escrita (zh-CN, o padrão: o texto do sistema sai em ideogramas)
// e procura, em todo texto da página (o que está à mostra e o escondido, as opções dos menus, title, placeholder, aria-label e
// alt), palavras em letras latinas. De cada texto saem antes, inteiros, os trechos que podem ter letras latinas:
//   - os trechos da própria tradução (cada valor de textos/zh-CN.php, partido nos parâmetros {1} e nas marcas HTML: uma sigla,
//     um código, um nome de produto no meio do chinês), o que o código marca como fixo("...") e os nomes dos idiomas no seletor;
//   - os dados de quem usa: os textos que o roteiro gravou (testes/cenario.php grava a lista em <banco>.dados.json, o DADOS
//     daqui), o cadastro inicial (modelo.json), as contas e coleções, e os fusos horários, que saem como foram escritos;
//   - na página da Ajuda, as palavras do manual do idioma (textos/manual/zh-CN.md).
// O que está em <code>, <pre>, <kbd>, <samp>, .ident e nos campos de texto (código e dado) fica de fora. A palavra latina que
// sobra é texto escrito direto no código, que não muda com o idioma: a conferência falha e mostra a palavra, a página e onde
// ela está. Roda sobre o servidor do cenário, como o layout:
//   BASE=http://127.0.0.1:8101/ DADOS=/pasta/sqlite.dados.json node testes/texto-fixo.cjs [página]
// (o testes/paridade.sh --textos faz isso no primeiro banco, depois do roteiro). LINGUA escolhe o idioma (zh-CN, hi).
const { chromium } = require(process.env.PLAYWRIGHT || require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const fs = require('fs');
const { execFileSync } = require('child_process');
const PAGINAS = require('./paginas.cjs');
const RAIZ = __dirname + '/..';
const BASE = process.env.BASE || 'http://127.0.0.1:8101/';
const USUARIO = process.env.RELOGIOS_USUARIO || 'teste';
const SENHA = process.env.RELOGIOS_SENHA || 'senha123';
const LINGUA = process.env.LINGUA || 'zh-CN';
const DADOS = process.env.DADOS || '';

const PALAVRA = /[A-Za-zÀ-ÖØ-öø-ÿ]{2,}/g;
const palavras = (s) => String(s).match(PALAVRA) || [];

const LATINA = 'A-Za-zÀ-ÖØ-öø-ÿ';
const minusc = (x) => x.toLocaleLowerCase('pt-BR');
// um trecho, inteiro: sem letra latina colada antes ou depois ("post" não come o começo de "PostgreSQL"; um ideograma colado pode)
const trechoRe = (x) => new RegExp('(?<![' + LATINA + '])' + x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![' + LATINA + '])', 'g');

// os trechos da tradução: cada valor do dicionário do idioma partido nos parâmetros e nas marcas HTML; o fixo("...") das telas;
// os nomes dos idiomas e os curtos (o seletor do menu mostra cada um no próprio idioma)
function trechosDaTraducao() {
  const res = new Set();
  const valores = JSON.parse(execFileSync('php', ['-r', '$v = []; foreach ((array)(include "' + RAIZ + '/textos/" . $argv[1] . ".php") as $p) { foreach ((array)$p as $x) { $v[] = $x; } } echo json_encode($v, JSON_UNESCAPED_UNICODE);', '--', LINGUA]).toString());
  // o fixo("...") das telas (.js) e da API (.php): o que não se traduz de propósito
  fs.readdirSync(RAIZ).filter((f) => f.endsWith('.js') || f.endsWith('.php')).map((f) => RAIZ + '/' + f)
    .concat(fs.readdirSync(RAIZ + '/api').map((f) => RAIZ + '/api/' + f)).forEach((f) => {
      const cod = fs.readFileSync(f, 'utf8');
      for (const m of cod.matchAll(/(?<![\w$.>])fixo\(("(?:[^"\\]|\\.)*")\)/g)) valores.push(JSON.parse(m[1]));
      // array_map("fixo", ["JAN", "FEB", ...])
      for (const m of cod.matchAll(/array_map\("fixo", \[([^\]]*)\]\)/g)) valores.push(...JSON.parse('[' + m[1] + ']'));
    });
  // o funcoes.php não roda sozinho (aberto direto, sai): vem depois de um arquivo de idioma
  valores.push(...JSON.parse(execFileSync('php', ['-r', 'include "' + RAIZ + '/textos/en.php"; require "' + RAIZ + '/textos/funcoes.php"; echo json_encode(array_merge(array_column(LINGUAS, "nome"), array_column(LINGUAS, "curto")), JSON_UNESCAPED_UNICODE);']).toString()));
  valores.forEach((v) => String(v).split(/\{[^}]*\}|<[^>]+>/).map((x) => x.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim())
    .filter((x) => new RegExp('[' + LATINA + ']').test(x)).forEach((x) => res.add(minusc(x))));
  return [...res].sort((a, b) => b.length - a.length);
}

// as palavras do manual do idioma (a página da Ajuda), sem os blocos de código e as âncoras
function palavrasDoManual() {
  const manual = RAIZ + '/textos/manual/' + LINGUA + '.md';
  if (!fs.existsSync(manual)) return new Set();
  const md = fs.readFileSync(manual, 'utf8').replace(/```[\s\S]*?```/g, ' ').replace(/`[^`]*`/g, ' ').replace(/\{#[^}]*\}/g, ' ')
    .replace(/\]\([^)]*\)/g, ']').replace(/<!--[\s\S]*?-->/g, ' ');
  return new Set(palavras(md).map(minusc));
}

// os dados de quem usa: o que o roteiro gravou e o cadastro inicial (modelo.json), do mais comprido para o mais curto
function textosDosDados() {
  const res = new Set();
  const juntar = (x) => {
    if (typeof x === 'string') { if (/\p{L}/u.test(x)) res.add(x.trim()); return; }
    if (x && typeof x === 'object') Object.values(x).forEach(juntar);
  };
  if (DADOS && fs.existsSync(DADOS)) juntar(JSON.parse(fs.readFileSync(DADOS, 'utf8')));
  juntar(JSON.parse(fs.readFileSync(RAIZ + '/modelo.json', 'utf8')));
  // os fusos horários (America/Sao_Paulo): o nome do fuso é o identificador do banco de fusos, igual em todo idioma
  juntar(JSON.parse(execFileSync('php', ['-r', 'echo json_encode(timezone_identifiers_list());']).toString()));
  // o fuso aparece com espaço no lugar do _ (America/Sao Paulo)
  [...res].forEach((x) => { if (x.includes('_')) res.add(x.replace(/_/g, ' ')); });
  // os pedaços: um texto do cadastro aparece em partes (o nome "Energia agora: vida da pilha (0 a 100)" mostra "vida da pilha";
  // o modelo de aviso "bateria cheia {quando}, {data}" mostra "bateria cheia"; as opções de um campo, uma por linha; os textos
  // entre aspas de uma fórmula: SE(disponivel; "disponíveis"; "indisponíveis"))
  [...res].forEach((x) => x.split(/\{[^}]*\}|[\n:;()\[\],"]/).map((p) => p.trim()).filter((p) => p.length >= 3).forEach((p) => res.add(p)));
  return [...res].filter((s) => s.length >= 2).sort((a, b) => b.length - a.length);
}

// as contas e as coleções (o login, o e-mail, o nome da coleção: dados de quem usa, criados pelo roteiro e na instalação)
async function textosDasContas() {
  const r = await fetch(BASE + 'api.php?recurso=usuarios', { headers: { Authorization: 'Basic ' + Buffer.from(USUARIO + ':' + SENHA).toString('base64') } });
  const j = await r.json().catch(() => ({}));
  const res = [];
  (j.usuarios || []).forEach((u) => { res.push(u.login, u.email); (u.colecoes || []).forEach((c) => res.push(c.nome)); });
  (j.colecoes || []).forEach((c) => res.push(c.nome, c.dono));
  return res.filter((x) => typeof x === 'string' && x.length >= 2);
}

// roda dentro da página: cada texto com o lugar dele
function coletar() {
  // o código (code, pre, kbd, samp, .ident: identificadores e fórmulas) e o que se digita (textarea) são iguais em todo idioma
  const pular = 'script, style, noscript, code, pre, kbd, samp, textarea, .ident, svg title';
  const nomear = (e) => {
    const partes = [];
    for (let a = e, i = 0; a && a !== document.body && i < 4; a = a.parentElement, i++) {
      partes.unshift(a.tagName.toLowerCase() + (a.id ? '#' + a.id : '') + (a.classList.length ? '.' + [...a.classList].slice(0, 2).join('.') : ''));
    }
    return partes.join(' > ');
  };
  const res = [];
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = w.nextNode(); n; n = w.nextNode()) {
    const e = n.parentElement;
    if (!e || e.closest(pular)) continue;
    const t = n.textContent.replace(/\s+/g, ' ').trim();
    if (t) res.push({ texto: t, onde: nomear(e), manual: !!e.closest('main#ajuda') });
  }
  document.querySelectorAll('[title], [placeholder], [aria-label], img[alt], input[type="submit"][value], input[type="button"][value]').forEach((e) => {
    ['title', 'placeholder', 'aria-label', 'alt'].concat(e.matches('input[type="submit"], input[type="button"]') ? ['value'] : []).forEach((a) => {
      const v = (e.getAttribute(a) || '').trim();
      if (v) res.push({ texto: v, onde: nomear(e) + ' [' + a + ']' });
    });
  });
  if (document.title) res.push({ texto: document.title, onde: 'title' });
  return res;
}

(async () => {
  const fp = process.argv[2] ? new RegExp(process.argv[2]) : null;
  // do mais comprido para o mais curto, sem diferença de maiúsculas ("Marcar revisão" mostra o tipo "Revisão" em minúsculas)
  const trechos = trechosDaTraducao().concat(textosDosDados().concat(await textosDasContas()).map(minusc))
    .filter((x, i, a) => x.length >= 2 && a.indexOf(x) === i).sort((a, b) => b.length - a.length);
  const trechosRe = trechos.map(trechoRe);
  const doManual = palavrasDoManual();
  const b = await chromium.launch();
  const c = await b.newContext({ viewport: { width: 1366, height: 900 }, httpCredentials: { username: USUARIO, password: SENHA }, locale: LINGUA, timezoneId: 'America/Sao_Paulo' });
  await c.addCookies([{ name: 'lingua', value: LINGUA, url: BASE }]);
  const achados = new Map();
  const erros = [];
  for (const pg of PAGINAS) {
    if (fp && !fp.test(pg.nome)) continue;
    const p = await c.newPage();
    p.on('pageerror', (e) => erros.push(pg.nome + ': ' + e.message));
    p.on('dialog', (d) => d.dismiss());
    if (pg.flutuante) {
      await p.route('**/api.php?*recurso=ficha*', async (r) => {
        const res = await r.fetch(); const j = await res.json(); j.painel_modo = 'flutuante'; r.fulfill({ response: res, json: j });
      });
    }
    await p.goto(BASE + pg.url, { waitUntil: 'networkidle', timeout: 90000 });
    await p.waitForTimeout(300);
    await p.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
    let textos = await p.evaluate(coletar);
    // cada opção dos menus que mostram partes (o quadro Marcar)
    const menus = await p.$$eval('select[data-mostra]', (ss) => ss.map((s, i) => ({ i, n: s.options.length })));
    for (const m of menus) {
      for (let k = 1; k < m.n; k++) {
        await p.evaluate(([i, k]) => {
          const s = document.querySelectorAll('select[data-mostra]')[i];
          if (s) { s.selectedIndex = k; s.dispatchEvent(new Event('change', { bubbles: true })); }
        }, [m.i, k]);
        await p.waitForTimeout(40);
        textos = textos.concat(await p.evaluate(coletar));
      }
    }
    for (const x of textos) {
      // as âncoras das mensagens ({motivo}, {relogio}): são código, iguais em todo idioma
      let resto = minusc(x.texto).replace(/\{[a-z_0-9]+\}/g, ' ');
      for (const [i, d] of trechos.entries()) {
        if (resto.includes(d)) resto = resto.replace(trechosRe[i], ' ');
      }
      for (const w of palavras(resto)) {
        if (x.manual && doManual.has(w)) continue;
        const k = w + '\t' + x.texto;
        if (!achados.has(k)) achados.set(k, { palavra: w, texto: x.texto, onde: x.onde, paginas: new Set() });
        achados.get(k).paginas.add(pg.nome);
      }
    }
    await p.close();
  }
  await b.close();
  erros.forEach((e) => console.log('erro de JS: ' + e));
  if (achados.size === 0) {
    console.log('Nenhum texto fixo: as telas em ' + LINGUA + ' só têm o que vem do dicionário e os dados.');
    process.exit(erros.length > 0 ? 1 : 0);
  }
  // agrupado pelo texto: a palavra, o texto inteiro, onde e em quais páginas
  const porTexto = new Map();
  for (const a of achados.values()) {
    const k = a.texto + '\t' + a.onde;
    if (!porTexto.has(k)) porTexto.set(k, { ...a, palavras: new Set() });
    porTexto.get(k).palavras.add(a.palavra);
    a.paginas.forEach((x) => porTexto.get(k).paginas.add(x));
  }
  console.log(porTexto.size + ' textos fixos (fora do dicionário) nas telas em ' + LINGUA + ':');
  for (const a of [...porTexto.values()].sort((x, y) => x.texto.localeCompare(y.texto))) {
    console.log('  «' + a.texto.slice(0, 140) + '» (' + [...a.palavras].join(', ') + ')\n      em ' + a.onde + ' · ' + [...a.paginas].slice(0, 4).join(', ') + (a.paginas.size > 4 ? '…' : ''));
  }
  process.exit(1);
})();
