// A lista do "Sortear de novo" (página Hoje), no navegador, sobre o servidor do cenário: todas as opções ficam na lista, e a que não vale
// agora fica desabilitada, com o motivo no próprio texto e no title (a partir de hoje, a partir de amanhã, inclusive hoje e a
// próxima semana). Confere quatro situações (trocar_hoje desligado e ligado, modo de 2 semanas, escala inteligente),
// que a explicação acompanha a escolhida e que o clique em Sortear roda a próxima semana. Confere também a lista "O que fazer"
// do Apagar um período (Hoje e Plano): as duas escolhas, e a de deixar os dias livres desabilitada, com o motivo, quando os
// dias sem relógio estão desligados na Configuração; e que o grupo "Sortear este dia por" do trocar por… (Hoje e Plano) e do
// Rodízio manual tem todas as formas que a API manda. Restaura a Configuração e o modo.
//   BASE=http://127.0.0.1:8101/ node testes/sorteio-interface.cjs    (RELOGIOS_USUARIO, RELOGIOS_SENHA, RELOGIOS_TOKEN: os do config de teste)
const { chromium } = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const BASE = process.argv[2] || process.env.BASE;
const TOK = process.env.RELOGIOS_TOKEN || 'token-de-teste-123';
async function api(p, post) {
  const body = new URLSearchParams(p).toString();
  const r = await fetch(BASE + 'api.php' + (post ? '' : '?' + body), { method: post ? 'POST' : 'GET', headers: { 'X-Api-Token': TOK, ...(post ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: post ? body : undefined });
  return r.json();
}
(async () => {
  const b = await chromium.launch();
  const c = await b.newContext({ viewport: { width: 1280, height: 900 }, httpCredentials: { username: process.env.RELOGIOS_USUARIO || 'teste', password: process.env.RELOGIOS_SENHA || 'Senha@123' }, timezoneId: 'America/Sao_Paulo' });
  const falhas = [];
  const modoAntes = (await api({ recurso: 'hoje', incluir: 'modo' })).modo;
  const modos = (await api({ recurso: 'cadastros', incluir: 'modos' })).modos;
  const id = (n) => modos.find((m) => m.nome === n).id;
  async function opcoes(rotulo) {
    const p = await c.newPage();
    await p.goto(BASE, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
    const o = await p.$$eval('#acao-sorteio option', (os) => os.map((x) => ({ texto: x.textContent.trim(), off: x.disabled, titulo: x.title })));
    const sel = await p.$eval('#acao-sorteio', (s) => s.options[s.selectedIndex].textContent.trim());
    const expl = await p.$eval('[data-explica-sorteio]', (e) => e.textContent.trim());
    console.log('\n' + rotulo + ' (escolhida: ' + sel + ')');
    o.forEach((x) => console.log('  ' + (x.off ? '[desabilitada] ' : '[ok]           ') + x.texto));
    console.log('  explicação: ' + expl.slice(0, 110));
    await p.close();
    return { o, sel, expl };
  }
  const conf = (x) => api({ recurso: 'config', acao: 'salvar', ...x }, true);
  const esperar = (cond, msg, r) => { if (!cond) falhas.push(msg + ' :: ' + JSON.stringify(r.o.map((x) => [x.off, x.texto]))); };
  const hj = await api({ recurso: 'hoje', incluir: 'comecou,trocar_hoje' });
  console.log('comecou:', hj.comecou, ' trocar_hoje:', hj.trocar_hoje);
  // 1. padrão: modo de uma semana, trocar hoje desligado
  await conf({ trocar_hoje: '0' }); await api({ recurso: 'modos', acao: 'ativar', id: id('Semana e fim de semana') }, true);
  let r = await opcoes('trocar_hoje desligado, modo de 1 semana');
  esperar(r.o.length === 4, '4 opções sempre', r);
  const on = (r) => r.o.filter((x) => !x.off).map((x) => x.texto.split(' (')[0]);
  esperar(JSON.stringify(on(r)) === JSON.stringify(['a partir de amanhã', 'a próxima semana']), 'só amanhã e a próxima semana valem (a próxima semana vale também com 1 semana)', r);
  esperar(r.o.filter((x) => x.off).every((x) => /indisponível: /.test(x.texto) && x.titulo.length > 3), 'as desabilitadas trazem o motivo e o title', r);
  esperar(r.sel === 'a partir de amanhã', 'a escolhida é a primeira que vale', r);
  // 2. trocar hoje ligado
  await conf({ trocar_hoje: '1' });
  r = await opcoes('trocar_hoje ligado');
  esperar(r.o.some((x) => !x.off && /inclusive hoje|a partir de hoje/.test(x.texto)), 'com trocar_hoje, hoje vale', r);
  // 3. modo de 2 semanas
  await api({ recurso: 'modos', acao: 'ativar', id: id('Semana dupla') }, true);
  r = await opcoes('modo de 2 semanas');
  esperar(r.o.some((x) => !x.off && /próxima semana/.test(x.texto)), 'a próxima semana vale com 2 semanas', r);
  // 4. escala
  await api({ recurso: 'modos', acao: 'ativar', id: id('Escala inteligente') }, true);
  r = await opcoes('escala inteligente');
  esperar(r.o.some((x) => x.off && /próxima semana/.test(x.texto) && /escala já planeja/.test(x.texto)), 'na escala a próxima semana fica desabilitada com o motivo', r);
  // 5. submeter com a próxima semana (a ação roda sem erro)
  await conf({ trocar_hoje: '0' }); await api({ recurso: 'modos', acao: 'ativar', id: id('Semana dupla') }, true);
  const p = await c.newPage();
  p.on('dialog', (d) => d.accept());
  await p.goto(BASE, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  await p.selectOption('#acao-sorteio', { label: (await p.$$eval('#acao-sorteio option', (os) => os.map((x) => x.textContent.trim()))).find((t) => /^a próxima semana/.test(t)) });
  const expl = await p.$eval('[data-explica-sorteio]', (e) => e.textContent.trim());
  if (!/semana seguinte inteira/.test(expl)) falhas.push('a explicação não mudou ao escolher a próxima semana: ' + expl);
  await p.click('form.sortear-de-novo button');
  await p.waitForTimeout(1500);
  const msg = (await p.innerText('body')).split('\n').filter((l) => /Semana de .* montada|recusad|erro/i.test(l)).join(' | ');
  console.log('\nresposta depois de clicar Sortear:', msg.slice(0, 160));
  if (!/Semana de .* montada/.test(msg)) falhas.push('o clique em Sortear não mostrou a mensagem da próxima semana: ' + msg.slice(0, 120));
  // desabilitada não se escolhe
  const off = await p.$$eval('#acao-sorteio option:disabled', (os) => os.length);
  console.log('opções desabilitadas na lista:', off);
  await conf({ trocar_hoje: '0' });
  if (modoAntes) await api({ recurso: 'modos', acao: 'ativar', id: modoAntes.id }, true);
  // a lista "O que fazer" do Apagar um período (Hoje e Plano): as duas escolhas ficam na lista; com os dias sem relógio
  // desligados na Configuração, a de deixar os dias livres fica desabilitada, com o motivo no texto e no title
  async function apagar(url) {
    const pa = await c.newPage();
    await pa.goto(BASE + url, { waitUntil: 'networkidle' });
    const o = await pa.$$eval('form.apagar-periodo select[name="acao"] option', (os) => os.map((x) => ({ valor: x.value, texto: x.textContent.trim(), off: x.disabled, titulo: x.title, sel: x.selected })));
    await pa.close();
    return o;
  }
  for (const url of ['', 'plano.php']) {
    await conf({ dia_sem_relogio: '' });
    let a = await apagar(url);
    console.log('\nApagar um período em ' + (url || 'index.php') + ':');
    a.forEach((x) => console.log('  ' + (x.off ? '[desabilitada] ' : '[ok]           ') + x.texto));
    if (!(a.length === 2 && a[0].valor === 'periodo_sortear' && a[0].sel && !a[0].off && a[1].valor === 'periodo_livre' && !a[1].off)) falhas.push('Apagar um período (' + (url || 'index.php') + '): a lista devia ter as duas escolhas habilitadas, com a de remover os registros escolhida :: ' + JSON.stringify(a));
    await conf({ dia_sem_relogio: '0' });
    a = await apagar(url);
    a.forEach((x) => console.log('  sem os dias sem relógio: ' + (x.off ? '[desabilitada] ' : '[ok]           ') + x.texto));
    if (!(a.length === 2 && !a[0].off && a[1].valor === 'periodo_livre' && a[1].off && /indisponível: .+/.test(a[1].texto) && a[1].titulo !== '')) falhas.push('Apagar um período (' + (url || 'index.php') + '): com os dias sem relógio desligados, a de deixar os dias livres devia ficar desabilitada, com o motivo :: ' + JSON.stringify(a));
  }
  await conf({ dia_sem_relogio: '' });
  // o grupo "Sortear este dia por" de cada seletor de dia (o trocar por… da Hoje e do Plano e o Rodízio manual) tem todas as
  // formas que a API aceita em sortear:<forma>, na ordem dela: a tela não tem uma lista própria (uma forma nova, como o
  // menos usado, tem de aparecer em todas sem mexer na tela)
  const formasApi = (await api({ recurso: 'hoje', incluir: 'formas' })).formas || [];
  const esperadas = formasApi.map((f) => 'sortear:' + f.chave + '=' + f.nome);
  if (!formasApi.some((f) => f.chave === 'menos_usado')) falhas.push('a API não mandou o menos usado nas formas da Hoje :: ' + JSON.stringify(formasApi));
  const sortearDe = (sel) => sel.$$eval('optgroup', (gs) => gs.filter((g) => g.label === 'Sortear este dia por').map((g) => [...g.querySelectorAll('option')].map((o) => o.value + '=' + o.textContent.trim())));
  const conferirSortear = async (onde, selects) => {
    let vistos = 0;
    for (const s of selects) {
      const grupos = await sortearDe(s);
      if (grupos.length !== 1 || JSON.stringify(grupos[0]) !== JSON.stringify(esperadas)) {
        falhas.push(onde + ': o "Sortear este dia por" devia ter as formas da API :: tem ' + JSON.stringify(grupos) + ' :: a API ' + JSON.stringify(esperadas));
        return;
      }
      vistos++;
    }
    console.log('\n' + onde + ': ' + vistos + ' seletores com as ' + esperadas.length + ' formas (' + formasApi.map((f) => f.chave).join(', ') + ')');
    if (vistos === 0) falhas.push(onde + ': nenhum seletor de dia na tela');
  };
  for (const url of ['', 'plano.php']) {
    const ps = await c.newPage();
    await ps.goto(BASE + url, { waitUntil: 'networkidle' });
    await conferirSortear('trocar por… em ' + (url || 'index.php'), await ps.$$('td.trocar select'));
    await ps.close();
  }
  // e o sorteio de um dia por cada forma, pela tela (o Plano, um dia que vem): escolhe, clica Trocar, o dia fica à mão com o
  // motivo "Sorteado à mão (<a forma>)"; depois o dia volta a ser sorteado pelo modo
  for (const f of formasApi) {
    const pt = await c.newPage();
    pt.on('dialog', (d) => d.accept());
    await pt.goto(BASE + 'plano.php', { waitUntil: 'networkidle' });
    const hojeP = (await api({ recurso: 'plano', incluir: 'hoje' })).hoje;
    const forms = await pt.$$('td.trocar form.trocar-dia');
    let dia = null;
    for (const fm of forms) {
      const dt = await fm.$eval('input[name="data"]', (e) => e.value);
      if (dt > hojeP) { dia = dt; await fm.$eval('select', (s, v) => { s.value = v; s.dispatchEvent(new Event('change')); }, 'sortear:' + f.chave); await fm.$eval('button', (b) => b.click()); break; }
    }
    await pt.waitForTimeout(1500);
    const pl = dia ? ((await api({ recurso: 'plano', de: dia, ate: dia })).plano || []).find((x) => x.data === dia) : null;
    console.log('sortear ' + f.chave + ' em ' + dia + ': ' + (pl ? pl.origem + ' · ' + String(pl.motivo || '').slice(0, 90) : 'nada'));
    if (!pl || pl.origem !== 'manual' || String(pl.motivo || '').indexOf('Sorteado à mão (' + f.nome + ')') !== 0) falhas.push('Sortear este dia por ' + f.chave + ' pela tela: o dia ' + dia + ' devia ficar à mão com o motivo do sorteio :: ' + JSON.stringify(pl));
    if (dia) await api({ recurso: 'rodizio', acao: 'trocar_dia', data: dia, relogio_id: '0' }, true);
    await pt.close();
  }
  const pm = await c.newPage();
  await pm.goto(BASE, { waitUntil: 'networkidle' });
  await pm.evaluate(() => document.querySelectorAll('#bloco-modo details').forEach((d) => { d.open = true; }));
  await pm.selectOption('#bloco-modo select[name="modo"]', 'manual');
  const ateManual = await pm.$eval('#form-manual input[name="de"]', (e) => { const d = new Date(e.value + 'T12:00:00'); d.setDate(d.getDate() + 9); return d.toISOString().slice(0, 10); });
  await pm.fill('#form-manual input[name="ate"]', ateManual);
  await pm.click('#manual-ok');
  await pm.waitForSelector('#manual-dias select', { timeout: 15000 });
  await conferirSortear('Rodízio manual', await pm.$$('#manual-dias select'));
  await pm.close();
  // as outras listas das formas de escolha saem da mesma lista da API: "Como escolher o relógio" (o quadro Modo de rodízio da
  // Hoje), o critério no formulário de cada tipo de modo (Modos: só as formas que o tipo aceita, com a do tipo escolhida) e
  // "Como a nota vira escolha" (Critérios); e cada modo diz como escolhe com o texto que a API manda (escolha.texto)
  const igual = (onde, tem, devia) => { if (JSON.stringify(tem) !== JSON.stringify(devia)) falhas.push(onde + ' :: tem ' + JSON.stringify(tem) + ' :: a API ' + JSON.stringify(devia)); else console.log(onde + ': ' + devia.length + ' itens, como na API'); };
  const cad = await api({ recurso: 'cadastros', incluir: 'modos,tipos_de_modo,formas' });
  const ph = await c.newPage();
  await ph.goto(BASE, { waitUntil: 'networkidle' });
  igual('Hoje, Como escolher o relógio', await ph.$$eval('#bloco-modo select[name="selecao"] option', (os) => os.map((o) => o.value + '=' + o.textContent.trim())), cad.formas.map((f) => f.chave + '=' + f.titulo));
  igual('Hoje, a explicação de cada forma', await ph.$$eval('#bloco-modo [data-explica-selecao]', (es) => es.map((e) => e.getAttribute('data-explica-selecao') + '=' + e.textContent.trim())), cad.formas.map((f) => f.chave + '=' + f.explicacao));
  await ph.evaluate(() => document.querySelectorAll('#bloco-modo details').forEach((d) => { d.open = true; }));
  for (const m of cad.modos) {
    await ph.selectOption('#bloco-modo select[name="modo"]', String(m.id));
    const v = await ph.evaluate(() => { const vis = (e) => !!e && !e.hidden && e.offsetParent !== null; return { lista: vis(document.querySelector('#bloco-modo select[name="selecao"]')), valor: document.querySelector('#bloco-modo select[name="selecao"]').value, texto: vis(document.querySelector('[data-sem-selecao-texto]')) ? document.querySelector('[data-sem-selecao-texto]').textContent : null, garantia: vis(document.querySelector('[data-garantia]')) }; });
    const ok = m.escolha.escolhe_forma ? (v.lista && v.valor === m.escolha.forma && v.texto === null) : (!v.lista && v.texto === m.escolha.texto);
    if (!ok || v.garantia !== m.escolha.garantia) falhas.push('Hoje, o modo ' + m.nome + ': a tela não mostra a escolha que a API manda :: ' + JSON.stringify(v) + ' :: ' + JSON.stringify(m.escolha));
  }
  console.log('Hoje, a escolha de cada modo: ' + cad.modos.length + ' modos conferidos');
  await ph.goto(BASE + 'modos.php', { waitUntil: 'networkidle' });
  igual('Modos, o critério de cada modo na lista', await ph.$$eval('table.compacta tbody tr', (trs) => trs.map((t) => t.cells[2].textContent.trim().split(' · ')[0])), cad.modos.map((m) => m.escolha.texto));
  for (const tp of cad.tipos_de_modo) {
    await ph.goto(BASE + 'modos.php?tipo=' + tp.chave + '#form', { waitUntil: 'networkidle' });
    igual('Modos, as formas do tipo ' + tp.chave, await ph.$$eval('form select[name="selecao"] option', (os) => os.map((o) => o.value + (o.selected ? '*' : ''))), tp.formas.map((k) => k + (k === tp.forma_padrao ? '*' : '')));
    const nota = await ph.$eval('form p.nota', (e) => e.textContent.trim());
    if (nota !== tp.explicacao) falhas.push('Modos, a explicação do tipo ' + tp.chave + ' não é a da API :: ' + nota);
  }
  const crit = await api({ recurso: 'criterios' });
  await ph.goto(BASE + 'criterios.php', { waitUntil: 'networkidle' });
  igual('Critérios, Como a nota vira escolha', await ph.$$eval('#selecao .opcao-selecao strong', (es) => es.map((e) => e.textContent.trim())), crit.formas.map((f) => f.titulo));
  igual('Critérios, a forma de cada modo', await ph.$$eval('#selecao table tbody tr', (trs) => trs.map((t) => t.cells[1].textContent.trim().split(',')[0])), crit.modos.map((m) => m.escolha.texto.split(',')[0]));
  await ph.close();
  await b.close();
  console.log('\nfalhas:', falhas.length); falhas.forEach((f) => console.log(' -', f));
  process.exit(falhas.length ? 1 : 0);
})();
