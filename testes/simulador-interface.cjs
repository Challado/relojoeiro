// A página Simulador, no navegador, sobre o servidor do cenário: o formulário (os modos, os parâmetros de cada um, com o que não
// vale desabilitado e o motivo ao lado), um cenário mudado pela tela (a garantia e um relógio a menos no sorteio), o + Cenário e
// o Remover, o Simular (a comparação com uma coluna por cenário e os melhores marcados, os dias lado a lado e cada relógio, o
// pedido no endereço com a semente usada), o Aplicar (a confirmação lista as mudanças; a resposta diz que aplicou) e a
// comparação aberta pelo endereço (uma variação das formas: um cenário por forma). No fim, a garantia e o relógio voltam como
// estavam.
//   BASE=http://127.0.0.1:8101/ node testes/simulador-interface.cjs    (RELOGIOS_USUARIO, RELOGIOS_SENHA, RELOGIOS_TOKEN: os do config de teste)
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
  const conferir = (ok, msg) => { if (!ok) { falhas.push(msg); } console.log((ok ? 'ok  ' : 'FALHA ') + msg); };
  const p = await c.newPage();
  const erros = [];
  let confirmacao = '';
  p.on('pageerror', (e) => erros.push(e.message));
  p.on('dialog', (d) => { confirmacao = d.message(); d.accept(); });
  const op = (await api({ recurso: 'simulador' })).opcoes;
  const garantia = (await api({ recurso: 'config' })).config?.max_sem_uso;
  const primeiro = op.relogios.find((r) => r.no_sorteio);
  try {
    await p.goto(BASE + 'simulador.php', { waitUntil: 'networkidle' });
    conferir(await p.locator('nav a[href="simulador.php"]').count() === 1, 'o menu Rodízio tem o Simulador');
    conferir(await p.locator('fieldset.sim-cenario').count() === 2, 'a página abre com dois cenários');
    conferir(await p.locator('select[data-cenario="0"][data-campo="modo"] option').count() === op.modos.length, 'a lista dos modos tem os modos que a API manda');
    // o que não vale no modo: desabilitado, com o motivo ao lado
    const ativo = op.modos.find((m) => m.ativo);
    for (const par of op.parametros) {
      const pa = ativo.parametros[par.chave];
      const campo = p.locator('[data-cenario="0"][data-campo="' + par.chave + '"]');
      const desab = await campo.isDisabled();
      const motivo = await campo.evaluate((e) => (e.closest('label').querySelector('small') || {}).textContent || '');
      conferir(desab === !pa.aplica && (pa.aplica || motivo === pa.motivo), par.chave + ': ' + (pa.aplica ? 'habilitado' : 'desabilitado, com o motivo "' + pa.motivo + '"'));
    }
    // o cenário 2: a garantia (se vale) e um relógio a menos
    const g = p.locator('input[data-cenario="1"][data-campo="max_sem_uso"]');
    if (await g.isEnabled()) {
      await g.fill('5');
      await g.dispatchEvent('change');
    }
    await p.locator('fieldset.sim-cenario').nth(1).locator('details.sim-relogios summary').click();
    const caixa = p.locator('input[data-cenario="1"][data-relogio="' + primeiro.id + '"]');
    await caixa.uncheck();
    await caixa.dispatchEvent('change');
    await p.locator('#mais-cenario').click();
    conferir(await p.locator('fieldset.sim-cenario').count() === 3, 'o + Cenário põe um terceiro');
    await p.locator('button[data-remover="2"]').click();
    conferir(await p.locator('fieldset.sim-cenario').count() === 2, 'o Remover tira o terceiro');
    await p.locator('#simular').click();
    await p.waitForSelector('#resultado table.sim-comparacao', { timeout: 120000 });
    const rotulos = await p.evaluate(() => [...document.querySelectorAll('table.sim-comparacao th.sim-col')].map((e) => e.textContent));
    conferir(rotulos.length === 2 && rotulos[1].indexOf(primeiro.nome) >= 0, 'a comparação tem uma coluna por cenário, e o rótulo do 2 diz o relógio que sai: ' + JSON.stringify(rotulos));
    conferir(await p.locator('table.sim-comparacao tbody tr').count() >= 8, 'a comparação tem as medidas e a linha do Aplicar');
    conferir(await p.locator('table.sim-dias tbody tr').count() > 0 && await p.locator('table.sim-por-relogio tbody tr').count() > 0, 'os dias lado a lado e cada relógio');
    const url = new URL(p.url());
    conferir(/^\d+$/.test(url.searchParams.get('semente') || '') && url.searchParams.get('simular') === '1', 'o endereço guarda o pedido, com a semente usada');
    // o Aplicar do cenário 2: a confirmação lista as mudanças
    await p.locator('button[data-aplicar="1"]').click();
    await p.waitForFunction(() => { const x = document.querySelector('#simulador p.acao'); return x && x.textContent !== ''; }, null, { timeout: 120000 });
    conferir(confirmacao.indexOf(primeiro.nome) >= 0 && confirmacao.indexOf('•') >= 0, 'a confirmação do Aplicar lista as mudanças');
    const recado = await p.evaluate(() => document.querySelector('#simulador p.acao').textContent);
    conferir(recado.indexOf(primeiro.nome) >= 0 && (await api({ recurso: 'simulador' })).opcoes.relogios.find((r) => r.id === primeiro.id).no_sorteio === false, 'aplicado: o relógio saiu do sorteio de verdade: ' + recado.slice(0, 120));
    // a comparação aberta pelo endereço: a variação das formas do modo ativo
    await p.goto(BASE + 'simulador.php?simular=1&semente=7&variar=' + encodeURIComponent(JSON.stringify({ parametro: 'forma' })), { waitUntil: 'networkidle' });
    await p.waitForSelector('#resultado table.sim-comparacao', { timeout: 120000 });
    const formas = (await api({ recurso: 'simulador' })).opcoes.modos.find((m) => m.ativo).parametros.forma;
    conferir(await p.locator('table.sim-comparacao th.sim-col').count() === (formas.aplica ? formas.opcoes.length : 1), 'pelo endereço, um cenário por forma do modo ativo');
    conferir(erros.length === 0, 'nenhum erro de página: ' + JSON.stringify(erros));
  } finally {
    // de volta: a garantia e o relógio no sorteio
    await api({ recurso: 'relogio', acao: 'salvar', id: primeiro.id, sorteio: '1' }, true);
    if (garantia !== undefined && garantia !== null) {
      await api({ recurso: 'config', acao: 'salvar', max_sem_uso: String(garantia) }, true);
    }
    await b.close();
  }
  console.log(falhas.length === 0 ? 'O simulador na tela confere.' : falhas.length + ' falha(s).');
  process.exit(falhas.length === 0 ? 0 : 1);
})();
