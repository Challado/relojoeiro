// O construtor de colunas no navegador, sobre o servidor do cenário: as colunas cadastradas aparecem na tabela da página Hoje (cabeçalho,
// filtro, células com o texto no formato delas, ordenação por número, busca no texto), entram no painel Colunas e saem da tabela quando
// se desmarcam; e a aba Cadastros › Colunas: os menus de fonte, campo e função se refazem um do outro, o formato sugerido aparece, o modo
// expert troca o formulário, o Testar mostra o valor num relógio e o Criar grava. Computador e celular (os relógios viram cartões).
// Apaga no fim tudo o que criou e restaura a escolha das colunas.
//   BASE=http://127.0.0.1:8101/ node testes/construtor-interface.cjs    (RELOGIOS_USUARIO, RELOGIOS_SENHA, RELOGIOS_TOKEN: os do config de teste)
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
  const medicoes = [];
  const totaisCriados = [];
  try {
    await api({ recurso: 'config', acao: 'salvar', colunas_hoje: '' }, true);
    // os relógios e as medições do timegrapher que a conta de teste usa (três no primeiro relógio; os outros ficam sem)
    // colunas que já existiam (o teste conta só as dele): quantas, e quantas aparecem de início
    const todasAntes = (await api({ recurso: 'colunas' })).colunas;
    const previas = todasAntes.filter((x) => !x.sistema);
    const previasVisiveis = previas.filter((x) => x.padrao).length;
    const hoje = await api({ recurso: 'hoje' });
    const rid = hoje.relogios[0].id;
    for (const [q, m] of [['2026-10-10 10:00:00', '4.5'], ['2026-10-20 09:00:00', '-2.5'], ['2026-11-01 08:00:00', '1.0']]) {
      const r = await api({ recurso: 'timegrapher', acao: 'salvar', id: 0, relogio_id: rid, quando: q, marcha: m, amplitude: 280 }, true);
      exigir(r.ok, 'não gravou a medição de teste: ' + JSON.stringify(r.erros));
      if (r.id) medicoes.push(r.id);
    }
    // duas colunas pela API: uma guiada que aparece de início, uma expert na moeda
    const c1 = await api({ recurso: 'colunas', acao: 'salvar', id: 0, nome: 'Teste marcha média', modo: 'guiado', tabela: 'timegrapher', campo: 'marcha', funcao: 'media', casas: 1, padrao: 1 }, true);
    const c2 = await api({ recurso: 'colunas', acao: 'salvar', id: 0, nome: 'Teste custo', modo: 'expert', expressao: 'fonte_timegrapher_id_conta * 12.5', formato: 'moeda', simbolo: 'US$', casas: 2, padrao: 1 }, true);
    exigir(c1.ok && c2.ok, 'não criou as colunas pela API: ' + JSON.stringify([c1.erros, c2.erros]));
    // um total embaixo da coluna montada (dinheiro): o rodapé da Hoje o mostra ali; e um que não combina é recusado
    const tt = await api({ recurso: 'totais', acao: 'salvar', id: 0, coluna: 'c_teste_custo', nome: 'Teste total custo', expressao: 'SOMA(fonte_timegrapher_id_conta)', formato: 'moeda', rodape: 1, pagina: 1 }, true);
    exigir(tt.ok, 'não criou o total embaixo da coluna montada: ' + JSON.stringify(tt.erros));
    if (tt.id) totaisCriados.push(tt.id);
    const ruim = await api({ recurso: 'totais', acao: 'salvar', id: 0, coluna: 'uso', nome: 'Teste total ruim', expressao: 'SOMA(custo_servicos)', formato: 'moeda', rodape: 1, pagina: 1 }, true);
    exigir(!ruim.ok && /não combina com a coluna/.test((ruim.erros || []).join(' ')), 'o total em dinheiro embaixo do Tempo de uso devia ser recusado: ' + JSON.stringify(ruim));
    if (ruim.id) totaisCriados.push(ruim.id);
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
      // 1. as cadastradas que aparecem de início vêm depois das de sempre, com cabeçalho, filtro e célula
      let cols = await ths();
      exigir(cols.slice(-2).join() === 'c_teste_marcha_media,c_teste_custo', onde + 'as duas cadastradas no fim do cabeçalho: ' + JSON.stringify(cols));
      exigir(await p.$$eval('#tabela-relogios thead tr.filtros td[data-col^=c_] input[data-filtro]', (is) => is.length) === previasVisiveis + 2, onde + 'um campo de busca por cadastrada');
      const linha = await p.$eval('#tabela-relogios tbody tr[data-id="' + rid + '"]', (r) => ({
        media: r.querySelector('td[data-col=c_teste_marcha_media]').textContent.trim(), custo: r.querySelector('td[data-col=c_teste_custo]').textContent.replace(/\u00a0/g, ' ').trim(),
        rotulo: r.querySelector('td[data-col=c_teste_custo]').getAttribute('data-rotulo'), cru: r.dataset.c_teste_marcha_media }));
      exigir(linha.media === '1,0' && linha.custo === 'US$ 37,50' && linha.rotulo === 'Teste custo' && Number(linha.cru) === 1, onde + 'o texto de cada cadastrada na linha: ' + JSON.stringify(linha));
      const vazias = await p.$$eval('#tabela-relogios tbody tr[data-id]:not([data-id="' + rid + '"]) td[data-col=c_teste_custo]', (ts) => ts.map((t) => t.textContent.trim()));
      exigir(vazias.every((x) => x === '—' || x.startsWith('US$')), onde + 'o relógio sem medição mostra o traço: ' + JSON.stringify(vazias.slice(0, 4)));
      // o total do rodapé embaixo da coluna montada
      await p.waitForSelector('#tabela-relogios tfoot td[data-coluna=c_teste_custo] table.mini-total caption', { timeout: 15000 }).catch(() => {});
      exigir((await p.$$eval('#tabela-relogios tfoot td[data-coluna=c_teste_custo] table.mini-total caption', (cs) => cs.map((c) => c.textContent))).includes('Teste total custo'), onde + 'o rodapé mostra o total embaixo da coluna montada');
      // 2. a ordem por número (os vazios por último) e a busca no texto
      await p.click('#tabela-relogios thead [data-ordena=c_teste_custo]');
      let crus = await p.$$eval('#tabela-relogios tbody tr[data-id]', (rs) => rs.map((r) => r.dataset.c_teste_custo));
      let nums = crus.filter((x) => x !== '').map(Number);
      exigir(nums.every((x, i) => i === 0 || nums[i - 1] <= x) && crus.slice(nums.length).every((x) => x === ''), onde + 'a ordem crescente pela cadastrada (vazios por último): ' + JSON.stringify(crus.slice(0, 6)));
      await p.click('#tabela-relogios thead [data-ordena=c_teste_custo]');
      crus = await p.$$eval('#tabela-relogios tbody tr[data-id]', (rs) => rs.map((r) => r.dataset.c_teste_custo));
      nums = crus.filter((x) => x !== '').map(Number);
      exigir(nums.every((x, i) => i === 0 || nums[i - 1] >= x), onde + 'a ordem decrescente pela cadastrada: ' + JSON.stringify(crus.slice(0, 6)));
      await p.fill('#tabela-relogios [data-filtro=c_teste_custo]', 'US$ 37');
      await p.waitForTimeout(200);
      const achados = await p.$$eval('#tabela-relogios tbody tr[data-id]', (rs) => rs.filter((r) => !r.hidden).map((r) => r.dataset.id));
      const esperados = await p.$$eval('#tabela-relogios tbody tr[data-id]', (rs) => rs.filter((r) => r.querySelector('td[data-col=c_teste_custo]').textContent.replace(/\u00a0/g, ' ').includes('US$ 37')).map((r) => r.dataset.id));
      exigir(achados.includes(String(rid)) && JSON.stringify(achados.sort()) === JSON.stringify(esperados.sort()), onde + 'a busca "US$ 37" (com espaço comum, o texto tem espaço que não quebra) devia deixar os relógios ' + JSON.stringify(esperados) + ' e deixou ' + JSON.stringify(achados));
      await p.fill('#tabela-relogios [data-filtro=c_teste_custo]', '');
      // 3. o painel Colunas lista as cadastradas; desmarcar uma tira a coluna da tabela e o filtro dela
      await p.click('#colunas-abrir');
      await p.waitForSelector('#painel-colunas:not([hidden]) .lista-ordenavel li');
      const itens = await p.$$eval('#painel-colunas li', (ls) => ls.map((l) => ({ v: l.getAttribute('data-valor'), t: l.textContent, marcado: l.querySelector('input').checked })));
      const item = itens.find((i) => i.v === 'c_teste_custo');
      exigir(item && item.marcado && /Teste custo/.test(item.t) && itens.length === todasAntes.length + 2, onde + 'o painel lista as 14 de sempre e as 2 cadastradas, marcadas: ' + JSON.stringify(itens.map((i) => i.v)));
      exigir(await p.$eval('#painel-colunas a[data-criar-colunas]', (x) => x.getAttribute('href')) === 'cadastros.php?aba=colunas', onde + 'o painel Colunas leva a Cadastros › Colunas (criar e editar)');
      await p.click('#painel-colunas li[data-valor=c_teste_custo] input');
      await p.click('#painel-colunas button:not([type=button])');
      await p.waitForFunction(() => !document.querySelector('#tabela-relogios th[data-col=c_teste_custo]'));
      cols = await ths();
      exigir(cols.slice(-1)[0] === 'c_teste_marcha_media' && !cols.includes('c_teste_custo'), onde + 'a coluna desmarcada saiu da tabela: ' + JSON.stringify(cols.slice(-3)));
      exigir(await p.$$eval('#tabela-relogios td[data-col=c_teste_custo], #tabela-relogios [data-filtro=c_teste_custo]', (x) => x.length) === 0, onde + 'sem célula nem busca da coluna que saiu');
      exigir(await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), onde + 'a página não rola de lado com as cadastradas');
      await api({ recurso: 'config', acao: 'salvar', colunas_hoje: '' }, true);

      // 3b. Cadastros › Totais: o formato acompanha a coluna (os que não combinam ficam desabilitados, com o motivo)
      await p.goto(BASE + 'cadastros.php?aba=totais', { waitUntil: 'networkidle' });
      await p.waitForSelector('#form-total');
      const colunasTotal = await p.$$eval('#form-total [name=coluna] option', (os) => os.map((o) => o.value));
      exigir(colunasTotal.includes('c_teste_custo') && colunasTotal.includes('uso'), onde + 'o menu Coluna do total lista as de sempre e as montadas: ' + JSON.stringify(colunasTotal));
      await p.selectOption('#form-total [name=coluna]', 'uso');
      const fmt = await p.$$eval('#form-total [name=formato] option', (os) => os.map((o) => ({ v: o.value, d: o.disabled, t: o.textContent })));
      exigir(fmt.find((o) => o.v === 'moeda').d && /não combina com a coluna/.test(fmt.find((o) => o.v === 'moeda').t) && !fmt.find((o) => o.v === 'duracao').d && !fmt.find((o) => o.v === 'inteiro').d,
        onde + 'embaixo do Tempo de uso: dinheiro desabilitado com o motivo, tempo e contagem livres: ' + JSON.stringify(fmt.map((o) => o.v + (o.d ? '(x)' : ''))));
      exigir(fmt.length === 8, onde + 'nenhum formato some do menu (só ficam desabilitados): ' + fmt.length);
      await p.selectOption('#form-total [name=coluna]', '');
      exigir((await p.$$eval('#form-total [name=formato] option', (os) => os.every((o) => !o.disabled))), onde + 'sem coluna, todos os formatos valem');
      // 4. Cadastros › Colunas: a lista e o formulário
      await p.goto(BASE + 'cadastros.php?aba=colunas', { waitUntil: 'networkidle' });
      await p.waitForSelector('#form-coluna');
      const linhasTab = await p.$$eval('section table.compacta tbody tr', (rs) => rs.map((r) => r.children[0].textContent));
      exigir(linhasTab.some((x) => /Teste marcha média/.test(x)) && linhasTab.some((x) => /Teste custo/.test(x)), onde + 'a lista de colunas: ' + JSON.stringify(linhasTab));
      const visivel = (sel) => p.$eval('#form-coluna ' + sel, (e) => !e.hidden && e.offsetParent !== null);
      exigir(await visivel('[name=tabela]') && !(await visivel('[name=expressao]')) && !(await visivel('[name=funcao]')), onde + 'no guiado: a fonte aparece; a expressão e a função, não');
      await p.selectOption('#form-coluna [name=tabela]', 'servico');
      const campos = await p.$$eval('#form-coluna [name=campo] option', (os) => os.map((o) => o.value));
      exigir(campos.includes('mao_de_obra') && campos.includes('data') && !campos.includes('descricao'), onde + 'os campos da fonte servico: ' + JSON.stringify(campos));
      await p.selectOption('#form-coluna [name=campo]', 'mao_de_obra');
      exigir(await visivel('[name=funcao]'), onde + 'numa tabela do banco, a função aparece');
      const funcoes = await p.$$eval('#form-coluna [name=funcao] option', (os) => os.map((o) => o.value));
      exigir(funcoes.join() === 'soma,media,menor,maior,conta', onde + 'as funções de um decimal: ' + JSON.stringify(funcoes));
      await p.selectOption('#form-coluna [name=campo]', 'data');
      exigir((await p.$$eval('#form-coluna [name=funcao] option', (os) => os.map((o) => o.value))).join() === 'menor,maior,conta', onde + 'uma data só aceita menor, maior e contagem');
      const sugerido = await p.$eval('#form-coluna [data-formato-sugerido]', (e) => e.textContent);
      exigir(/Data/.test(sugerido), onde + 'o formato sugerido para uma data: ' + sugerido);
      await p.selectOption('#form-coluna [name=tabela]', 'relogio');
      exigir(!(await visivel('[name=funcao]')), onde + 'na tabela dos relógios não há função');
      exigir((await p.$$eval('#form-coluna [name=campo] option', (os) => os.map((o) => o.value))).includes('tempo_de_uso'), onde + 'a tabela dos relógios oferece tempo_de_uso');
      await p.selectOption('#form-coluna [name=formato]', 'moeda');
      exigir(await visivel('[name=simbolo]'), onde + 'o símbolo aparece no formato moeda');
      await p.selectOption('#form-coluna [name=formato]', 'inteiro');
      exigir(!(await visivel('[name=simbolo]')), onde + 'o símbolo some fora da moeda');
      await p.selectOption('#form-coluna [name=modo]', 'expert');
      exigir(await visivel('[name=expressao]') && !(await visivel('[name=tabela]')), onde + 'no expert: a expressão aparece e a fonte some');
      // o Testar no expert, com erro e sem
      await p.fill('#form-coluna [name=expressao]', 'variavel_que_nao_existe');
      await p.evaluate(() => { document.getElementById('teste-coluna').innerHTML = ''; });
      await p.click('#testar-coluna');
      await p.waitForSelector('#teste-coluna .acao');
      exigir(/variável desconhecida/.test(await p.textContent('#teste-coluna')), onde + 'o Testar mostra o erro da expressão');
      await p.fill('#form-coluna [name=expressao]', 'fonte_timegrapher_id_conta + 1');
      await p.selectOption('#form-coluna [name=formato]', 'inteiro');
      await p.evaluate(() => { document.getElementById('teste-coluna').innerHTML = ''; });
      await p.click('#testar-coluna');
      await p.waitForSelector('#teste-coluna .quadro-total');
      exigir(/\d/.test(await p.textContent('#teste-coluna .quadro-total strong')), onde + 'o Testar mostra o valor num relógio');
      // criar pelo formulário, no guiado
      await p.selectOption('#form-coluna [name=modo]', 'guiado');
      await p.fill('#form-coluna [name=nome]', 'Teste pelo formulário');
      await p.selectOption('#form-coluna [name=tabela]', 'timegrapher');
      await p.selectOption('#form-coluna [name=campo]', 'marcha');
      await p.selectOption('#form-coluna [name=funcao]', 'maior');
      await p.selectOption('#form-coluna [name=formato]', '');
      await p.evaluate(() => { document.getElementById('teste-coluna').innerHTML = ''; });
      await p.click('#testar-coluna');
      await p.waitForSelector('#teste-coluna .quadro-total');
      exigir(/4,50/.test(await p.textContent('#teste-coluna .quadro-total strong')) || /—/.test(await p.textContent('#teste-coluna .quadro-total strong')), onde + 'o Testar do guiado: ' + await p.textContent('#teste-coluna'));
      await p.click('#form-coluna .botoes button:not([type=button])');
      await p.waitForFunction(() => /Teste pelo formulário/.test(document.body.textContent) && document.querySelectorAll('section table.compacta tbody tr').length >= 3);
      const criada = (await api({ recurso: 'colunas' })).colunas.find((x) => x.nome === 'Teste pelo formulário');
      exigir(criada && criada.expressao === 'fonte_timegrapher_marcha_maior' && criada.formato === 'decimal' && criada.modo === 'guiado', onde + 'a coluna criada pelo formulário: ' + JSON.stringify(criada));
      if (criada) await api({ recurso: 'colunas', acao: 'excluir', id: criada.id, confirmar: 1 }, true);
      exigir(erros.length === 0, onde + 'erros de JavaScript: ' + JSON.stringify(erros));
      await c.close();
    }
  } catch (e) {
    falhas.push('exceção: ' + (e && e.message));
  } finally {
    for (const x of (await api({ recurso: 'colunas' })).colunas || []) {
      if (/^Teste/.test(x.nome)) await api({ recurso: 'colunas', acao: 'excluir', id: x.id, confirmar: 1 }, true);
    }
    for (const id of totaisCriados) await api({ recurso: 'totais', acao: 'excluir', id, confirmar: 1 }, true);
    for (const id of medicoes) await api({ recurso: 'timegrapher', acao: 'excluir', id, confirmar: 1 }, true);
    await api({ recurso: 'config', acao: 'salvar', colunas_hoje: '' }, true);
    await b.close();
  }
  console.log(falhas.length ? falhas.join('\n') : '');
  console.log('falhas: ' + falhas.length);
  process.exit(falhas.length ? 1 : 0);
})();
