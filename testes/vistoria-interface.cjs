// A vistoria periódica no navegador, sobre o servidor do cenário (que termina com a vistoria ligada, 30 dias): o menu Coleção
// tem Vistorias; a página lista o que a API manda (os atrasados e os de hoje marcados, a ordem, o resumo), o Todos e o
// Vencendo até vão para o endereço; o Registrar sem nenhum marcado avisa, e com os marcados grava e relê a lista; a Hoje mostra o
// aviso Vistoria em "Hoje é dia de"; a caixa "contar também como vistoria" aparece no registro de um serviço (o painel da ficha) e
// só quando a situação escolhida encerra (a manutenção); desligada, a página diz por quê e a caixa fica desabilitada com o motivo.
// No fim, as vistorias registradas aqui saem e a opção volta como estava.
//   BASE=http://127.0.0.1:8101/ node testes/vistoria-interface.cjs    (RELOGIOS_USUARIO, RELOGIOS_SENHA, RELOGIOS_TOKEN: os do config de teste)
const { chromium } = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const BASE = process.argv[2] || process.env.BASE;
const TOK = process.env.RELOGIOS_TOKEN || 'token-de-teste-123';
async function api(p, post) {
  const body = new URLSearchParams(p).toString();
  const r = await fetch(BASE + 'api.php' + (post ? '' : '?' + body), { method: post ? 'POST' : 'GET', headers: { 'X-Api-Token': TOK, ...(post ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: post ? body : undefined });
  return r.json();
}
// os ids dos lançamentos Vistoria de um relógio
async function vistoriasDo(id) {
  return ((await api({ recurso: 'historico', relogio: id, por_pagina: 500 })).lancamentos || []).filter((l) => l.tipo === 'vistoria').map((l) => l.id);
}
(async () => {
  const b = await chromium.launch();
  const c = await b.newContext({ viewport: { width: 1280, height: 900 }, httpCredentials: { username: process.env.RELOGIOS_USUARIO || 'teste', password: process.env.RELOGIOS_SENHA || 'Senha@123' }, timezoneId: 'America/Sao_Paulo' });
  const falhas = [];
  const conferir = (ok, msg) => { if (!ok) { falhas.push(msg); } console.log((ok ? 'ok  ' : 'FALHA ') + msg); };
  const p = await c.newPage();
  const erros = [];
  p.on('pageerror', (e) => erros.push(e.message));
  const dias = (await api({ recurso: 'config' })).config?.vistoria_dias;
  const lista = await api({ recurso: 'vistorias', todos: '1' });
  const antes = {};
  for (const r of lista.relogios || []) {
    antes[r.relogio_id] = await vistoriasDo(r.relogio_id);
  }
  try {
    conferir(lista.ativa === true && (lista.relogios || []).length > 0, 'o cenário termina com a vistoria ligada e relógios na lista');
    await p.goto(BASE + 'vistorias.php', { waitUntil: 'networkidle' });
    conferir(await p.locator('nav a[href="vistorias.php"]').count() === 1, 'o menu Coleção tem Vistorias');
    const padrao = await api({ recurso: 'vistorias' });
    const linhas = p.locator('#form-vistorias tbody tr');
    conferir(await linhas.count() === padrao.relogios.length, 'a tabela tem os relógios que a API manda (' + padrao.relogios.length + ')');
    const marcadas = await p.locator("#form-vistorias tbody input[type=checkbox]").evaluateAll((xs) => xs.map((x) => x.checked));
    conferir(JSON.stringify(marcadas) === JSON.stringify(padrao.relogios.map((r) => r.estado !== 'proxima')), 'os atrasados e os de hoje vêm marcados, os outros não');
    const nomes = await linhas.evaluateAll((xs) => xs.map((x) => x.querySelector('td:nth-child(2)').textContent));
    conferir(JSON.stringify(nomes) === JSON.stringify(padrao.relogios.map((r) => r.relogio)), 'a ordem é a da API (do vencimento mais antigo ao mais novo)');
    // Todos e o Vencendo até: o filtro vai para o endereço
    await p.click('#f-todos');
    await p.waitForFunction(() => location.search.indexOf('todos=1') >= 0);
    conferir(await p.locator('#form-vistorias tbody tr').count() === lista.relogios.length, 'Todos mostra todos os relógios com a vistoria e vai para o endereço');
    const ate = lista.relogios[lista.relogios.length - 1].vence_dia;
    await p.fill('#f-ate', ate);
    await p.click('#f-ok');
    await p.waitForFunction((a) => location.search.indexOf('ate=' + a) >= 0, ate);
    conferir(await p.locator('#form-vistorias tbody tr').count() === lista.relogios.length, 'Vencendo até o último vencimento mostra todos, e o dia vai para o endereço');
    // o Registrar: sem nenhum marcado, avisa; com dois marcados, grava os dois e relê
    await p.locator('#marcar-todos').check();
    await p.locator('#marcar-todos').uncheck();
    await p.click('#registrar');
    await p.waitForTimeout(400);
    conferir(await p.locator('#registrar').isEnabled() && (await vistoriasDo(lista.relogios[0].relogio_id)).length === antes[lista.relogios[0].relogio_id].length, 'sem nenhum marcado, nada é gravado');
    const dois = lista.relogios.slice(0, 2).map((r) => r.relogio_id);
    for (const id of dois) {
      await p.locator('#form-vistorias tbody input[value="' + id + '"]').check();
    }
    await p.click('#registrar');
    await p.waitForFunction(() => !document.getElementById('registrar') || !document.getElementById('registrar').disabled);
    await p.waitForTimeout(600);
    let novos = 0;
    for (const id of dois) {
      novos += (await vistoriasDo(id)).length - antes[id].length;
    }
    conferir(novos === 2, 'o Registrar gravou a vistoria dos dois marcados');
    const depois = await api({ recurso: 'vistorias', todos: '1' });
    conferir(dois.every((id) => depois.relogios.find((r) => r.relogio_id === id).ultima !== null), 'a lista relida traz a última vistoria deles');
    // a Hoje: o aviso Vistoria em "Hoje é dia de" (um relógio com a vistoria atrasada, se houver)
    const atrasado = depois.relogios.find((r) => r.estado === 'atrasado');
    if (atrasado) {
      await p.goto(BASE + 'index.php', { waitUntil: 'networkidle' });
      conferir((await p.locator('body').innerText()).indexOf(atrasado.relogio) >= 0 && await p.locator('text=Vistoria').count() > 0, 'a Hoje mostra o aviso Vistoria do atrasado (' + atrasado.relogio + ')');
    }
    // a caixa no registro de um serviço (a ficha) e na manutenção (só quando a situação escolhida encerra)
    await p.goto(BASE + 'ficha.php?id=' + lista.relogios[0].relogio_id, { waitUntil: 'networkidle' });
    const caixaServ = p.locator('.servico-form .caixa-vistoria input[name=vistoria]');
    conferir(await caixaServ.count() === 1 && await caixaServ.isEnabled() && !(await caixaServ.isChecked()), 'o registro de um serviço tem a caixa "contar também como vistoria", habilitada e desmarcada');
    await p.goto(BASE + 'manutencao.php', { waitUntil: 'networkidle' });
    const encerra = await p.evaluate(() => (window.DADOS_MANUTENCAO.situacoes.find((s) => s.comportamento === 'encerra') || {}).valor);
    const aberta = await p.evaluate(() => (window.DADOS_MANUTENCAO.situacoes.find((s) => s.comportamento === 'aberta') || {}).valor);
    const sel = p.locator('form:has(.vistoria-encerra) select[name=situacao]').first();
    await sel.selectOption(aberta);
    conferir(await p.locator('.vistoria-encerra').first().isHidden(), 'na manutenção, com uma situação aberta, a caixa fica escondida');
    await sel.selectOption(encerra);
    conferir(await p.locator('.vistoria-encerra').first().isVisible(), 'escolhendo uma situação que encerra, a caixa aparece');
    // desligada: a página diz por quê, e a caixa do serviço fica desabilitada com o motivo
    await api({ recurso: 'config', acao: 'salvar', vistoria_dias: '0' }, true);
    await p.goto(BASE + 'vistorias.php', { waitUntil: 'networkidle' });
    conferir(await p.locator('#vistorias .aviso').count() === 1 && await p.locator('#vistorias a[href="configuracao.php"]').count() === 1, 'desligada, a página diz por quê e leva à Configuração');
    await p.goto(BASE + 'ficha.php?id=' + lista.relogios[0].relogio_id, { waitUntil: 'networkidle' });
    conferir(await caixaServ.isDisabled() && (await p.locator('.servico-form .caixa-vistoria small').first().textContent()).length > 0, 'desligada, a caixa do serviço fica desabilitada, com o motivo');
    conferir(erros.length === 0, 'nenhum erro de página: ' + JSON.stringify(erros));
  } catch (e) {
    conferir(false, 'parou: ' + e.message.split('\n')[0]);
  } finally {
    // as vistorias registradas aqui saem, e a opção volta como estava
    for (const id of Object.keys(antes)) {
      for (const l of (await vistoriasDo(id)).filter((x) => antes[id].indexOf(x) < 0)) {
        await api({ recurso: 'lancamento', acao: 'excluir', id: l, confirmar: '1' }, true);
      }
    }
    await api({ recurso: 'config', acao: 'salvar', vistoria_dias: dias === undefined ? '' : String(dias) }, true);
    await b.close();
  }
  console.log(falhas.length === 0 ? 'A vistoria na tela confere.' : falhas.length + ' falha(s).');
  process.exit(falhas.length === 0 ? 0 : 1);
})();
