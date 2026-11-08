// A lista do "Sortear de novo" (página Hoje), no navegador, sobre o servidor do cenário: todas as opções ficam na lista, e a que não vale
// agora fica desabilitada, com o motivo no próprio texto e no title (a partir de hoje, a partir de amanhã, inclusive hoje, a partir do
// próximo período, a próxima semana). Confere quatro situações (trocar_hoje desligado e ligado, modo de 2 semanas, escala inteligente),
// que a explicação acompanha a escolhida e que o clique em Sortear roda o a partir do próximo período. Restaura a Configuração e o modo.
//   BASE=http://127.0.0.1:8101/ node testes/sorteio-interface.cjs    (RELOGIOS_USUARIO, RELOGIOS_SENHA, RELOGIOS_TOKEN: os do config de teste)
const { chromium } = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const BASE = process.argv[2];
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
  esperar(r.o.length === 5, '5 opções sempre', r);
  const on = (r) => r.o.filter((x) => !x.off).map((x) => x.texto.split(' (')[0]);
  esperar(JSON.stringify(on(r)) === JSON.stringify(['a partir de amanhã', 'a partir do próximo período']), 'só amanhã e próximo período valem', r);
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
  // 5. submeter com o select de próximo período (a ação roda sem erro)
  await conf({ trocar_hoje: '0' }); await api({ recurso: 'modos', acao: 'ativar', id: id('Semana dupla') }, true);
  const p = await c.newPage();
  p.on('dialog', (d) => d.accept());
  await p.goto(BASE, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  await p.selectOption('#acao-sorteio', { label: (await p.$$eval('#acao-sorteio option', (os) => os.map((x) => x.textContent.trim()))).find((t) => /^a partir do próximo período/.test(t)) });
  const expl = await p.$eval('[data-explica-sorteio]', (e) => e.textContent.trim());
  if (!/^Refaz o plano de /.test(expl)) falhas.push('a explicação não mudou ao escolher o próximo período: ' + expl);
  await p.click('form.sortear-de-novo button');
  await p.waitForTimeout(1500);
  const msg = (await p.innerText('body')).split('\n').filter((l) => /Sorteado de novo|recusad|erro/i.test(l)).join(' | ');
  console.log('\nresposta depois de clicar Sortear:', msg.slice(0, 160));
  if (!/próximo período/.test(msg)) falhas.push('o clique em Sortear não mostrou a mensagem do próximo período: ' + msg.slice(0, 120));
  // desabilitada não se escolhe
  const off = await p.$$eval('#acao-sorteio option:disabled', (os) => os.length);
  console.log('opções desabilitadas na lista:', off);
  await conf({ trocar_hoje: '0' });
  if (modoAntes) await api({ recurso: 'modos', acao: 'ativar', id: modoAntes.id }, true);
  await b.close();
  console.log('\nfalhas:', falhas.length); falhas.forEach((f) => console.log(' -', f));
  process.exit(falhas.length ? 1 : 0);
})();
