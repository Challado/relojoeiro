// A conferência da rolagem: em cada tamanho de tela (computador, tablet de pé e deitado, celular), rola como a pessoa rola
// (o gesto de rolagem do Chromium, no meio do que está à
// mostra) e confere que nada fica travado. O gesto é o da roda do mouse em todos os tamanhos: o do dedo não roda no
// Chromium sem janela, e a roda segue o mesmo caminho (rola a caixa que está embaixo do ponto e, no fim dela, a de fora):
//   - cada página (testes/paginas.cjs) rola até o fim: o que rola embaixo do dedo chega ao fim (a página ou, com o painel do
//     relógio aberto no celular e no tablet, que cobre a tela e rola por dentro, o painel);
//   - o menu aberto (até 900 px, o menu recolhido) rola por dentro até o último item, sem a página de trás andar, e o fim
//     da página continua alcançável depois de fechá-lo;
//   - a gaveta Explicações rola até a última explicação (nas páginas que têm o botão Explicações).
// Roda sobre o banco do cenário (testes/cenario.php), como a conferência do layout; testes/paridade.sh --layout a chama no fim.
//   BASE=http://127.0.0.1:8101/ RELOGIOS_USUARIO=teste RELOGIOS_SENHA=Senha@123 node testes/rolagem.cjs [página] [tela]
// Sai com 1 se alguma tela travar (e diz qual, onde e o que não deu para alcançar).
const { chromium, devices } = require(process.env.PLAYWRIGHT || require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8101/';
const USUARIO = process.env.RELOGIOS_USUARIO || 'teste';
const SENHA = process.env.RELOGIOS_SENHA || 'Senha@123';
const LINGUA = process.env.LINGUA || '';

// os tamanhos: os de cada tipo de aparelho, do maior ao menor (o celular pequeno é o que mais aperta o menu)
const TELAS = [
  { nome: 'pc-1440', viewport: { width: 1440, height: 900 } },
  { nome: 'pc-1280', viewport: { width: 1280, height: 720 } },
  { nome: 'tablet-deitado-1024', ...devices['iPad Mini landscape'] },
  { nome: 'tablet-pe-768', ...devices['iPad Mini'] },
  { nome: 'cel-412', ...devices['Pixel 7'] },
  { nome: 'cel-390', ...devices['iPhone 13'] },
  { nome: 'cel-360', ...devices['Galaxy S9+'], viewport: { width: 360, height: 740 } },
  { nome: 'cel-320', ...devices['iPhone SE'], viewport: { width: 320, height: 568 } },
];
const PAGINAS = require('./paginas.cjs');
// onde conferir o menu e a gaveta (o menu é o mesmo em todas; a gaveta, a da Configuração tem mais explicações)
const PAGINAS_MENU = ['index.php', 'configuracao.php'];
const PAGINAS_GAVETA = ['configuracao.php', 'winders.php', 'programas.php', 'cadastros.php'];

// o gesto de rolagem a partir de um ponto (x, y), como a pessoa faz: o navegador rola o que está embaixo do ponto (e, no fim
// dele, o de fora, como de verdade)
async function rolar(p, x, y, dy) {
  const cdp = await p.context().newCDPSession(p);
  await cdp.send('Input.synthesizeScrollGesture', { x: Math.round(x), y: Math.round(y), yDistance: -dy, xDistance: 0, speed: 30000,
    gestureSourceType: 'mouse', repeatCount: 1 });
  await cdp.detach();
}
// rola para baixo até parar de andar (dois gestos seguidos sem andar: a página pode ainda estar crescendo; no máximo 40 gestos, cada um de 2000 px ou de 1/20 do que há para rolar, o que for
// maior: a Ajuda passa de 100 mil px no celular); devolve quantos gestos deu
async function rolarAteParar(p, x, y, marca) {
  const total = await p.evaluate(() => Math.max(document.documentElement.scrollHeight, ...[...document.querySelectorAll('*')].map((e) => e.scrollHeight)));
  const dy = Math.max(2000, Math.ceil(total / 20));
  let antes = null;
  let parado = 0;
  for (let i = 0; i < 40; i++) {
    await rolar(p, x, y, dy);
    await p.waitForTimeout(80);
    const agora = await p.evaluate(marca);
    parado = agora === antes ? parado + 1 : 0;
    if (parado >= 2) return i;
    antes = agora;
  }
  return 40;
}
// a posição de rolagem de tudo o que rola na página (a janela e cada caixa com rolagem), para saber se o gesto andou
const MARCA = () => {
  let s = String(Math.round(window.scrollY));
  document.querySelectorAll('*').forEach((e) => { if (e.scrollTop) s += ',' + Math.round(e.scrollTop); });
  return s;
};
// o que rola embaixo do ponto (x, y): a caixa com rolagem mais perto (o painel do relógio, que no celular cobre a tela e
// rola por dentro) ou, sem nenhuma, a página; e se chegou ao fim
const FIM = ([x, y]) => {
  let e = document.elementFromPoint(x, y);
  let fixo = false;
  while (e && e !== document.body && e !== document.documentElement) {
    if (getComputedStyle(e).position === 'fixed') fixo = true;
    const o = getComputedStyle(e).overflowY;
    if ((o === 'auto' || o === 'scroll') && e.scrollHeight > e.clientHeight + 4) break;
    e = e.parentElement;
  }
  const pagina = !e || e === document.body || e === document.documentElement;
  const c = pagina ? { top: window.scrollY, h: window.innerHeight, total: document.documentElement.scrollHeight } : { top: e.scrollTop, h: e.clientHeight, total: e.scrollHeight };
  // a página presa embaixo de uma camada que cobre a tela e cabe nela inteira (não tem o que rolar): confere
  const coberta = pagina && fixo && getComputedStyle(document.body).overflowY === 'hidden';
  return { pagina, fim: coberta || c.top + c.h >= c.total - 4, onde: Math.round(c.top + c.h), total: c.total,
    quem: pagina ? 'a página' : (e.className ? '.' + String(e.className).trim().split(/\s+/).join('.') : e.tagName.toLowerCase()),
    corpo: getComputedStyle(document.body).overflowY };
};
// com a página de trás presa (o body sem rolagem: o painel do relógio aberto por cima, no celular e no tablet), o meio da
// camada que rola por cima (a maior caixa com rolagem à mostra); senão, null
const CAMADA = () => {
  if (getComputedStyle(document.body).overflowY !== 'hidden') return null;
  let melhor = null;
  document.querySelectorAll('*').forEach((e) => {
    const o = getComputedStyle(e).overflowY;
    if ((o !== 'auto' && o !== 'scroll') || e.scrollHeight <= e.clientHeight + 4) return;
    const r = e.getBoundingClientRect();
    const area = Math.max(0, Math.min(r.right, innerWidth) - Math.max(r.left, 0)) * Math.max(0, Math.min(r.bottom, innerHeight) - Math.max(r.top, 0));
    if (area > 0 && (!melhor || area > melhor.area)) melhor = { area, x: (Math.max(r.left, 0) + Math.min(r.right, innerWidth)) / 2, y: (Math.max(r.top, 0) + Math.min(r.bottom, innerHeight)) / 2 };
  });
  return melhor ? [melhor.x, melhor.y] : null;
};
// rola a partir do meio da tela (ou do meio da camada que rola por cima da página presa) até parar e confere que chegou ao
// fim do que rola ali
async function conferirFim(p, vp, problemas, rotulo) {
  const ponto = (await p.evaluate(CAMADA)) || [vp.width / 2, vp.height * 0.6];
  await rolarAteParar(p, ponto[0], ponto[1], MARCA);
  const r = await p.evaluate(FIM, ponto);
  if (!r.fim) {
    problemas.push(rotulo + ' (' + r.quem + ' parou em ' + r.onde + ' de ' + r.total + ' px; overflow do body: ' + r.corpo + ')');
  }
}

async function conferirTela(b, tela, fp, problemas) {
  const { nome, ...opts } = tela;
  const c = await b.newContext({ ...opts, httpCredentials: { username: USUARIO, password: SENHA }, locale: LINGUA || 'pt-BR', timezoneId: 'America/Sao_Paulo' });
  if (LINGUA) await c.addCookies([{ name: 'lingua', value: LINGUA, url: BASE }]);
  const abrir = async (url) => {
    const p = await c.newPage();
    p.on('dialog', (d) => d.dismiss());
    try {
      await p.goto(BASE + url, { waitUntil: 'networkidle', timeout: 90000 });
    } catch (e) {
      await p.goto(BASE + url, { waitUntil: 'networkidle', timeout: 90000 });
    }
    await p.waitForTimeout(150);
    return p;
  };
  const vp = opts.viewport;
  // 1. cada página rola até o fim (a rolagem começa no meio da tela, onde fica o conteúdo)
  for (const pg of PAGINAS) {
    if (fp && !fp.test(pg.nome)) continue;
    if (pg.flutuante) continue;
    const p = await abrir(pg.url);
    await conferirFim(p, vp, problemas, nome + ' · ' + pg.nome + ': não rola até o fim');
    await p.close();
  }
  // 2. o menu aberto (só no menu recolhido, até 900 px) rola até o último item; fechado, a página volta a rolar até o fim
  if (vp.width <= 900) {
    for (const url of PAGINAS_MENU) {
      if (fp && !fp.test(url.replace('.php', '')) && !fp.test('menu')) continue;
      const p = await abrir(url);
      await p.click('.menu-botao');
      await p.waitForTimeout(150);
      // o ponto do gesto: no meio da lista do menu que está à mostra
      const ponto = await p.evaluate(() => {
        const r = document.querySelector('.menu-itens').getBoundingClientRect();
        return { x: r.left + r.width / 2, y: Math.max(r.top + 10, Math.min(r.bottom - 10, window.innerHeight * 0.6)) };
      });
      const yAntes = await p.evaluate(() => window.scrollY);
      await rolarAteParar(p, ponto.x, ponto.y, MARCA);
      const ult = await p.evaluate(() => {
        const itens = [...document.querySelectorAll('.menu-itens a, .menu-itens select')].filter((e) => e.offsetParent !== null);
        const e = itens[itens.length - 1];
        const r = e.getBoundingClientRect();
        return { texto: (e.textContent || e.name || '').trim().slice(0, 40), top: r.top, bottom: r.bottom, h: window.innerHeight, y: window.scrollY };
      });
      // o menu rola por dentro: a página de trás fica parada (senão o último item só aparece no fim da página inteira, que
      // numa página comprida é o mesmo que travado)
      if (Math.abs(ult.y - yAntes) > 1) {
        problemas.push(nome + ' · menu em ' + url + ': rolando o menu aberto, quem rola é a página de trás (andou ' + Math.round(ult.y - yAntes) + ' px), não o menu');
      }
      if (ult.bottom > ult.h + 1 || ult.top < -1) {
        problemas.push(nome + ' · menu em ' + url + ': o último item ("' + ult.texto + '") não chega à tela rolando o menu aberto (fica em ' + Math.round(ult.top) + '–' + Math.round(ult.bottom) + ' px, a tela tem ' + ult.h + ')');
      }
      await p.click('.menu-botao');
      await p.waitForTimeout(150);
      await conferirFim(p, vp, problemas, nome + ' · menu em ' + url + ': depois de abrir e fechar o menu, a página não rola até o fim');
      await p.close();
    }
  }
  // 3. a gaveta Explicações rola até a última explicação; fechada, a página volta a rolar
  for (const url of PAGINAS_GAVETA) {
    if (fp && !fp.test(url.replace('.php', '')) && !fp.test('gaveta')) continue;
    const p = await abrir(url);
    // a página monta depois de ler a API (com as telas todas ao mesmo tempo, pode demorar): espera o botão aparecer
    if (!(await p.waitForSelector('.ajuda-botao', { timeout: 30000 }).catch(() => null))) {
      problemas.push(nome + ' · gaveta em ' + url + ': a página não tem o botão Explicações');
      await p.close();
      continue;
    }
    await p.locator('.ajuda-botao').first().click();
    await p.waitForTimeout(200);
    const ponto = await p.evaluate(() => {
      const r = document.querySelector('.ajuda-corpo').getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    });
    await rolarAteParar(p, ponto.x, ponto.y, MARCA);
    const ult = await p.evaluate(() => {
      const itens = [...document.querySelectorAll('.ajuda-item')];
      const e = itens[itens.length - 1];
      const r = e.getBoundingClientRect();
      const g = document.querySelector('.ajuda-corpo').getBoundingClientRect();
      return { texto: e.querySelector('h4').textContent.trim().slice(0, 40), bottom: r.bottom, fim: Math.min(g.bottom, window.innerHeight) };
    });
    if (ult.bottom > ult.fim + 1) {
      problemas.push(nome + ' · gaveta em ' + url + ': a última explicação ("' + ult.texto + '") não chega à tela rolando a gaveta');
    }
    await p.keyboard.press('Escape');
    await p.waitForTimeout(150);
    await conferirFim(p, vp, problemas, nome + ' · gaveta em ' + url + ': depois de fechar a gaveta, a página não rola até o fim');
    await p.close();
  }
  await c.close();
}

(async () => {
  const fp = process.argv[2] ? new RegExp(process.argv[2]) : null;
  const ft = process.argv[3] ? new RegExp(process.argv[3]) : null;
  const b = await chromium.launch();
  const problemas = [];
  // as telas todas ao mesmo tempo (o servidor de teste atende vários pedidos de uma vez: PHP_CLI_SERVER_WORKERS)
  const telas = TELAS.filter((t) => !ft || ft.test(t.nome));
  await Promise.all(telas.map((t) => conferirTela(b, t, fp, problemas)));
  await b.close();
  if (problemas.length) {
    console.log('A rolagem trava (' + problemas.length + '):\n  ' + problemas.join('\n  '));
    process.exit(1);
  }
  console.log('A rolagem confere: ' + telas.length + ' tamanhos de tela, as páginas até o fim, o menu aberto e a gaveta Explicações.');
})().catch((e) => { console.error(e); process.exit(1); });
