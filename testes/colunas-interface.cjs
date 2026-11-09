// As colunas da tabela dos relógios da página Hoje (o motor de colunas e o Tempo de uso), no navegador, sobre o servidor do cenário:
// a coluna Tempo de uso (o texto "1d 4h 30m"), o painel Colunas (marcar, ▲ ▼, Salvar, Herdar) e que a tabela inteira (cabeçalho, filtros,
// linhas e rodapé) segue a lista que a API entrega; a coluna escondida perde a ordenação e o filtro; o filtro e a ordem do Tempo de uso;
// e a lista ordenável da Configuração. Restaura a escolha das colunas no fim. Computador e celular (os relógios viram cartões).
//   BASE=http://127.0.0.1:8101/ node testes/colunas-interface.cjs    (RELOGIOS_USUARIO, RELOGIOS_SENHA, RELOGIOS_TOKEN: os do config de teste)
const { chromium } = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const BASE = process.argv[2] || process.env.BASE;
const TOK = process.env.RELOGIOS_TOKEN || 'token-de-teste-123';
async function api(p, post) {
  const body = new URLSearchParams(p).toString();
  const r = await fetch(BASE + 'api.php' + (post ? '' : '?' + body), { method: post ? 'POST' : 'GET', headers: { 'X-Api-Token': TOK, ...(post ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: post ? body : undefined });
  return r.json();
}
(async () => {
  const falhas = [];
  const exigir = (ok, msg) => { if (!ok) falhas.push(msg); };
  const b = await chromium.launch();
  try {
    await api({ recurso: 'config', acao: 'salvar', colunas_hoje: '' }, true);
    for (const celular of [false, true]) {
      const c = await b.newContext({ viewport: celular ? { width: 390, height: 844 } : { width: 1366, height: 900 }, isMobile: celular, hasTouch: celular,
        httpCredentials: { username: process.env.RELOGIOS_USUARIO || 'teste', password: process.env.RELOGIOS_SENHA || 'Senha@123' }, timezoneId: 'America/Sao_Paulo' });
      const onde = celular ? 'celular: ' : 'computador: ';
      const p = await c.newPage();
      const erros = [];
      p.on('pageerror', (e) => erros.push(e.message));
      p.on('dialog', (d) => d.accept());
      await p.goto(BASE, { waitUntil: 'networkidle' });
      await p.waitForSelector('#tabela-relogios tbody tr[data-id]');
      const ths = () => p.$$eval('#tabela-relogios thead tr:first-child th[data-col]', (hs) => hs.filter((h) => !h.hidden).map((h) => h.getAttribute('data-col')));
      const celulas = (sel) => p.$$eval(sel, (ts) => ts.filter((t) => !t.hidden).map((t) => t.getAttribute('data-col')));
      const todas = ['codigo', 'nome', 'marca', 'tipo', 'estado', 'carga', 'ultimo', 'uso', 'manut', 'em', 'fazer', 'encostado', 'compra', 'valor'];
      // 1. sem escolha: todas, na ordem de sempre, com o Tempo de uso depois da Última vez usado
      exigir(JSON.stringify(await ths()) === JSON.stringify(todas), onde + 'sem escolha, todas as colunas na ordem de sempre: ' + JSON.stringify(await ths()));
      const textosUso = await p.$$eval('#tabela-relogios tbody tr[data-id] td[data-col=uso]', (ts) => ts.map((t) => t.textContent.replace(/ /g, ' ').trim()));
      exigir(textosUso.length > 0 && textosUso.every((x) => /^(—|\d+[dhms]( \d+[dhms])*)$/.test(x)), onde + 'o texto do Tempo de uso: ' + JSON.stringify(textosUso.slice(0, 8)));
      // 2. o filtro e a ordem do Tempo de uso
      const nunca = textosUso.filter((x) => x === '—').length;
      await p.selectOption('#tabela-relogios [data-filtro=uso]', 'nunca');
      await p.waitForTimeout(200);
      const vistos = await p.$$eval('#tabela-relogios tbody tr[data-id]', (rs) => rs.filter((r) => !r.hidden).length);
      exigir(vistos === nunca, onde + 'o filtro "Nunca usado" devia deixar ' + nunca + ' relógios e deixou ' + vistos);
      await p.selectOption('#tabela-relogios [data-filtro=uso]', '');
      if (!celular) {
        await p.click('#tabela-relogios thead [data-ordena=uso]');
        const usos = await p.$$eval('#tabela-relogios tbody tr[data-id]', (rs) => rs.map((r) => Number(r.dataset.uso)));
        const comUso = usos.filter((x) => x > 0);
        exigir(comUso.every((x, i) => i === 0 || comUso[i - 1] <= x) && usos.slice(comUso.length).every((x) => x === 0), onde + 'a ordem pelo Tempo de uso (os sem uso por último): ' + JSON.stringify(usos.slice(0, 10)));
        await p.click('#tabela-relogios thead [data-ordena=uso]');
        await p.click('#tabela-relogios thead [data-ordena=uso]');
      }
      // 3. o painel: a Relógio não se desmarca; tira a Marca e o Valor; sobe o Tempo de uso para logo depois da Relógio; salva
      await p.click('#colunas-abrir');
      await p.waitForSelector('#painel-colunas:not([hidden]) .lista-ordenavel li');
      const itens = await p.$$eval('#painel-colunas li', (ls) => ls.map((l) => ({ v: l.getAttribute('data-valor'), marcado: l.querySelector('input').checked, desabilitado: l.querySelector('input').disabled })));
      exigir(itens.length === todas.length && itens.find((i) => i.v === 'nome').desabilitado && itens.every((i) => i.marcado), onde + 'o painel lista as ' + todas.length + ' colunas marcadas, e a Relógio não se desmarca');
      for (const k of ['marca', 'valor']) await p.click('#painel-colunas li[data-valor=' + k + '] input');
      for (let i = 0; i < 12; i++) await p.click('#painel-colunas li[data-valor=uso] [data-lista-sobe]');
      // o Tempo de uso sobe até o alto da lista e a Relógio sobe depois: ficam a Relógio e o Tempo de uso, nessa ordem
      for (let i = 0; i < 14; i++) await p.click('#painel-colunas li[data-valor=uso] [data-lista-sobe]');
      for (let i = 0; i < 14; i++) await p.click('#painel-colunas li[data-valor=nome] [data-lista-sobe]');
      await Promise.all([p.waitForResponse((r) => /api\.php/.test(r.url()) && r.request().method() === 'POST'), p.click('#painel-colunas button:not([type=button])')]);
      await p.waitForTimeout(1500);
      const esperado = ['nome', 'uso'];
      const depois = await ths();
      exigir(depois.slice(0, 2).join(',') === esperado.join(',') && depois.indexOf('marca') < 0 && depois.indexOf('valor') < 0 && depois.length === todas.length - 2, onde + 'depois de salvar: ' + JSON.stringify(depois));
      exigir(await p.$eval('#painel-colunas', (e) => e.hidden), onde + 'o painel devia fechar ao salvar');
      // a API concorda, e cabeçalho, filtros, linhas e rodapé têm as mesmas colunas, na mesma ordem
      const hj = await api({ recurso: 'hoje', incluir: 'colunas,colunas_escolha' });
      const vis = hj.colunas.filter((x) => x.visivel).sort((x, y) => x.posicao - y.posicao).map((x) => x.chave);
      exigir(JSON.stringify(vis) === JSON.stringify(depois) && hj.colunas_escolha === vis.join(','), onde + 'a API e a tela devem ter as mesmas colunas: ' + JSON.stringify(vis));
      for (const sel of ['#tabela-relogios tr.filtros td[data-col]', '#tabela-relogios tbody tr[data-id]:first-child td[data-col]', '#tabela-relogios tr.totais-rodape td[data-col]']) {
        const ord = await celulas(sel);
        exigir(JSON.stringify(ord) === JSON.stringify(depois), onde + 'as células de ' + sel + ' fora da ordem: ' + JSON.stringify(ord));
      }
      // 4. uma coluna escondida perde o filtro que tinha: filtra pelo Estado (a Tipo já foi) e esconde o Estado pelo painel
      const opcoesEstado = await p.$$eval('#tabela-relogios [data-filtro=estado] option', (os) => os.map((o) => o.value).filter((v) => v !== ''));
      if (opcoesEstado.length > 0) {
        await p.click('#colunas-abrir');
        await p.waitForSelector('#painel-colunas:not([hidden]) li');
        // o Estado estava à mostra? marca-o primeiro (a lista do painel mostra só as colunas da última leitura)
        const estaMarcado = await p.$eval('#painel-colunas li[data-valor=estado] input', (i) => i.checked);
        if (!estaMarcado) await p.click('#painel-colunas li[data-valor=estado] input');
        await Promise.all([p.waitForResponse((r) => /api\.php/.test(r.url()) && r.request().method() === 'POST'), p.click('#painel-colunas button:not([type=button])')]);
        await p.waitForTimeout(1500);
        await p.selectOption('#tabela-relogios [data-filtro=estado]', opcoesEstado[0]);
        await p.click('#colunas-abrir');
        await p.click('#painel-colunas li[data-valor=estado] input');
        await Promise.all([p.waitForResponse((r) => /api\.php/.test(r.url()) && r.request().method() === 'POST'), p.click('#painel-colunas button:not([type=button])')]);
        await p.waitForTimeout(1500);
        const guardado = await p.evaluate(() => JSON.parse(sessionStorage.getItem('relogios-tabela') || '{"f":{}}'));
        const total = await p.$$eval('#tabela-relogios tbody tr[data-id]', (rs) => rs.length);
        const aVista = await p.$$eval('#tabela-relogios tbody tr[data-id]', (rs) => rs.filter((r) => !r.hidden).length);
        exigir(!guardado.f.estado && aVista === total, onde + 'a coluna escondida devia perder o filtro (ficou ' + JSON.stringify(guardado.f.estado) + ', ' + aVista + ' de ' + total + ' à vista)');
      }
      // 5. Herdar: esvazia a escolha da coleção, e voltam todas
      await p.click('#colunas-abrir');
      await p.click('#painel-colunas [data-lista-herdar]');
      const vazioCampo = await p.$eval('#painel-colunas input[name=colunas_hoje]', (i) => i.value);
      exigir(vazioCampo === '', onde + 'o Herdar devia esvaziar o campo');
      await Promise.all([p.waitForResponse((r) => /api\.php/.test(r.url()) && r.request().method() === 'POST'), p.click('#painel-colunas button:not([type=button])')]);
      await p.waitForTimeout(1500);
      exigir(JSON.stringify(await ths()) === JSON.stringify(todas) && (await api({ recurso: 'hoje', incluir: 'colunas_escolha' })).colunas_escolha === '', onde + 'depois do Herdar, todas as colunas e a coleção sem escolha');
      exigir(erros.length === 0, onde + 'erros na página: ' + erros.join(' | '));
      await c.close();
    }
    // 6. a lista ordenável na Configuração (o mesmo widget, no nível da coleção)
    const c2 = await b.newContext({ viewport: { width: 1366, height: 900 }, httpCredentials: { username: process.env.RELOGIOS_USUARIO || 'teste', password: process.env.RELOGIOS_SENHA || 'Senha@123' } });
    const p2 = await c2.newPage();
    await p2.goto(BASE + 'configuracao.php', { waitUntil: 'networkidle' });
    await p2.waitForSelector('.lista-ordenavel input[name=colunas_hoje]', { state: 'attached' });
    const antes = await p2.$$eval('.lista-ordenavel li', (ls) => ls.map((l) => l.getAttribute('data-valor')));
    await p2.click('.lista-ordenavel li[data-valor=carga] [data-lista-sobe]');
    const campo = await p2.$eval('.lista-ordenavel input[name=colunas_hoje]', (i) => i.value);
    const apos = campo.split(',');
    exigir(campo !== '' && apos.indexOf('carga') === antes.indexOf('carga') - 1, 'Configuração: o ▲ devia subir a Carga e pôr a lista no campo: ' + campo);
    await p2.click('.lista-ordenavel [data-lista-herdar]');
    exigir(await p2.$eval('.lista-ordenavel input[name=colunas_hoje]', (i) => i.value) === '', 'Configuração: o Herdar devia esvaziar o campo');
    await c2.close();
    // Minha conta e Sistema usam o mesmo campo pela lista da API (opcoes e padroes)
    const cfg = await api({ recurso: 'config', incluir: 'opcoes' });
    const op = (cfg.opcoes.opcoes || []).find((o) => o.chave === 'colunas_hoje');
    exigir(op && op.tipo === 'lista' && op.valores.length === 14 && JSON.stringify(op.fixos) === '["nome"]', 'A API da configuração devia trazer a opção colunas_hoje como lista com os 14 itens e a fixa Relógio');
  } finally {
    await api({ recurso: 'config', acao: 'salvar', colunas_hoje: '' }, true);
    await b.close();
  }
  console.log('falhas:', falhas.length);
  falhas.forEach((f) => console.log(' -', f));
  process.exit(falhas.length ? 1 : 0);
})();
