// Regressão de componente: as páginas de um assunto das opções em três níveis (Rodízio › Ajustes do rodízio, pelo painelNiveis
// do api.js) e a página Sistema, em DOM real no Chromium, sem rede e sem banco: a API é simulada. Não substitui o teste
// integrado do PHP (testes/opcoes.php, testes/rodizio.php) nem o layout (testes/layout.cjs).
// node testes/niveis-interface.cjs (requer playwright; CHROMIUM_PATH é opcional).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT || require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const raiz = path.join(__dirname, '..');
const apiJs = fs.readFileSync(path.join(raiz, 'api.js'), 'utf8');
// os ajudantes das opções em três níveis (campoOpcao, camposOpcoes, abasNiveis, painelNiveis, formAcao...), os mesmos do api.js
const ajudantes = apiJs.slice(apiJs.indexOf('// ---- os quadros das páginas de configuração'), apiJs.indexOf('// Uma senha nova com a confirmação'))
  + apiJs.slice(apiJs.indexOf('// um botão que grava uma ação pela API'), apiJs.indexOf('var aoGravar = function'));
// a regra dos níveis (o quadro dos outros níveis some) é a do estilo.css de verdade
const estiloTodo = fs.readFileSync(path.join(raiz, 'estilo.css'), 'utf8');
const estiloNiveis = estiloTodo.split('\n').filter((l) => l.includes('data-nivel-visto')).join('\n');
const base = `
var LINGUA="pt-BR", TEXTOS={}, ESTRITO=false;
function t(s,p) { var r=s; Object.keys(p||{}).forEach(k=>r=r.split('{'+k+'}').join(p[k])); return r; }
function fixo(s) { return s; }
function h(s) { return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function el(n,a,c) { return '<'+n+Object.keys(a||{}).map(k=>a[k]===true?' '+k:(a[k]===false||a[k]==null?'':' '+k+'="'+h(a[k])+'"')).join('')+'>'+(c||'')+(['input','img','br'].includes(n)?'':'</'+n+'>'); }
function parCampo(r,c,tipo,explica) {return el('label',{class:'par-campo '+(tipo||'')},el('span',{class:'rotulo'},h(r)+(explica||''))+el('span',{class:'controle'},c));}
function ajudaGrupo(){} function ajuda(c){return el('button',{type:'button',class:'ajuda-i','data-ajuda':c},'i');} function ajudaRef(c){return ajuda(c);}
function ajudaNota(c,ti){return el('button',{type:'button',class:'ajuda-linha','data-ajuda':c},h(ti));} function ajudaBotao(){return el('button',{type:'button',class:'ajuda-botao'},'?');}
function dataBr(x){return String(x).slice(0,10);} function horaBr(x){return String(x).slice(11,16);}
function nomeNaLista(n,c){return String(c);}
function textoResposta(r){return (r.mensagem||'')+(r.erros&&r.erros.length?' '+r.erros.join(' '):'');}
function porHtml(alvo,html){alvo.innerHTML=html;}
function avisoFlutuanteGravacao(m){window.ultimoAviso=m;}
function salvarSemMover(form,res,atualizar){return Promise.resolve().then(atualizar||function(){}).then(function(){window.ultimoAviso=textoResposta(res);});}
function api(p){return window.fixtureAPI(p);}
function enviar(d){return window.fixtureEnviar(Object.fromEntries(d));}
var aoGravar=function(){};
document.addEventListener('submit',function(ev){var f=ev.target;if(ev.defaultPrevented||!f.hasAttribute('data-recurso'))return;ev.preventDefault();var d=new FormData(f);d.append('recurso',f.dataset.recurso);enviar(d).then(r=>aoGravar(f,r,d));});
${ajudantes}
`;
// uma opção como a API manda (opcoes_lista): o que o nível escolheu, o que herda e de onde
function opcao(chave, grupo, tipo, escolha, herdado, origem, valores) {
  return {chave, grupo, tipo, titulo: 'Opção ' + chave, minimo: tipo === 'inteiro' ? 0 : null, maximo: tipo === 'inteiro' ? 365 : null,
    valores: valores ? valores.map((v) => ({valor: v, texto: 'Valor ' + v})) : null, padrao: '', escolha, herdado, origem_herdado: origem,
    efetivo: escolha !== '' ? escolha : herdado, origem: escolha !== '' ? 'colecao' : origem};
}
function listaRodizio(escolhas, origem) {
  const e = (k) => escolhas[k] || '';
  return {grupos: [{chave: 'rodizio', texto: 'Rodízio'}], opcoes: [
    opcao('rodizio_ativo', 'rodizio', 'sim_nao', e('rodizio_ativo'), '1', origem), opcao('recupera_ontem', 'rodizio', 'sim_nao', e('recupera_ontem'), '0', origem),
    opcao('semana_inicio', 'rodizio', 'enum', e('semana_inicio'), 'segunda', origem, ['segunda', 'domingo']),
    opcao('escala_inicio', 'rodizio', 'enum', e('escala_inicio'), 'amanha', origem, ['amanha', 'hoje']),
    opcao('escala_periodo', 'rodizio', 'enum', e('escala_periodo'), 'corridos', origem, ['corridos', 'semana', 'mes']),
    opcao('semana_ultimo_dia', 'rodizio', 'enum', e('semana_ultimo_dia'), 'dia_seguinte', origem, ['dia_seguinte', 'semana_seguinte']),
    opcao('trocar_hoje', 'rodizio', 'sim_nao', e('trocar_hoje'), '0', origem), opcao('dia_sem_relogio', 'rodizio', 'sim_nao', e('dia_sem_relogio'), '1', origem),
    opcao('max_sem_uso', 'rodizio', 'inteiro', e('max_sem_uso'), '21', origem), opcao('sorteio_casas', 'rodizio', 'inteiro', e('sorteio_casas'), '2', origem)]};
}
function niveis(dono, master) {
  return {colecao: listaRodizio({recupera_ontem: '1'}, 'usuario'),
    dono: {pode_mudar: dono, opcoes: dono ? listaRodizio({rodizio_ativo: '0'}, 'sistema') : null},
    sistema: {pode_mudar: master, opcoes: master ? listaRodizio({}, 'padrao') : null}};
}
function sistema() {
  return {url_sistema: 'https://exemplo.invalid', cron_espera_rodadas: 10,
    parametros: [{chave: 'cadastro_aberto', nome: 'Cadastro aberto', descricao: '1 sim; 0 não', tipo: 'sim_nao', valor: 1, origem: 'padrao', config_php: null, config_valor: null, padrao: 1, minimo: 0, maximo: 1},
      {chave: 'convite_dias', nome: 'Dias do convite', descricao: 'dias', tipo: 'inteiro', valor: 7, origem: 'config', config_php: 'CONVITE_DIAS', config_valor: 7, padrao: 7, minimo: 1, maximo: 365}],
    senha_politica: {regras: {tamanho: 8, maiusculas: 1, minusculas: 1, numeros: 1, simbolos: 1}, texto: 'A senha precisa de 8 caracteres.'},
    cron_estado: {situacao: 'rodando', texto: 'Cron OK', registro: 'linha\n'.repeat(5)},
    aleatorio: {fontes: [], ultimo_lote: null, fora_desde: null},
    email: {metodo: 'smtp', remetente: 'sistema@exemplo.invalid', smtp: {host: 'smtp.exemplo.invalid', porta: 587, seguranca: 'tls', usuario: 'u', senha: true}, imap: null, pop: null, problemas: []},
    email_para: 'master@exemplo.invalid'};
}
async function abrir(page, pagina, fixture) {
  await page.route('http://relojoeiro.test/**', (r) => r.fulfill({contentType: 'text/html', body: '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + (fixture.estiloTodo ? estiloTodo : estiloNiveis)
    + '.barra-salvar{position:sticky;bottom:0}</style><body><main id="' + pagina + '"></main></body></html>'}));
  await page.goto('http://relojoeiro.test/' + pagina + '.php' + (fixture.busca || ''));
  await page.evaluate((f) => {
    window.fixture = f;
    window.fixtureAPI = (p) => { f.lidos.push(p.recurso + (p.grupo ? ':' + p.grupo : '')); return Promise.resolve(structuredClone(p.recurso === 'opcoes' ? f.niveis : p.recurso === 'sistema' ? f.sistema
      : p.recurso === 'sql' ? {banco: 'sqlite', execucoes: [], limite_padrao: 200, limite_maximo: 5000} : p.recurso === 'backup' ? null : {})); };
    window.fixtureEnviar = (d) => { f.posts.push(d); return Promise.resolve({ok: true, mensagem: 'Configuração salva.'}); };
  }, fixture);
  await page.addScriptTag({content: base});
  await page.addScriptTag({content: fs.readFileSync(path.join(raiz, pagina + '.js'), 'utf8')});
}
const visiveis = (page, seletor) => page.locator(seletor).evaluateAll((es) => es.filter((e) => e.offsetParent !== null).map((e) => e.getAttribute('name')));
(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH || undefined});
  let total = 0;
  try {
    const ctx = await browser.newContext({viewport: {width: 390, height: 844}});
    const page = await ctx.newPage();
    page.setDefaultTimeout(5000);
    const erros = [];
    page.on('pageerror', (e) => erros.push(e.message));
    // ---- Ajustes do rodízio, para o master que é dono: as três abas, um nível por vez; o Herdar diz de onde vem o que vale
    let f = {niveis: niveis(true, true), lidos: [], posts: []};
    await abrir(page, 'rodizio', f);
    await page.locator('#rodizio [name=rodizio_ativo]').waitFor();
    assert.deepEqual((await page.evaluate(() => fixture.lidos)), ['opcoes:rodizio']);
    assert.deepEqual(await page.locator('.abas-nivel a').evaluateAll((a) => a.map((x) => x.getAttribute('data-nivel-ir'))), ['colecao', 'dono', 'sistema']);
    assert.equal(await page.getAttribute('[data-niveis]', 'data-nivel-visto'), 'colecao');
    assert.equal(await page.inputValue('[name=recupera_ontem]'), '1');
    assert.match(await page.locator('[name=rodizio_ativo] option').first().textContent(), /^Herdar \(Ligado, do usuário\)$/);
    const nomesColecao = await visiveis(page, '#rodizio [name]:not([type=hidden])');
    for (const k of ['rodizio_ativo', 'recupera_ontem', 'dia_sem_relogio', 'trocar_hoje', 'semana_inicio', 'escala_inicio', 'escala_periodo', 'semana_ultimo_dia', 'sorteio_casas', 'max_sem_uso']) {
      assert.ok(nomesColecao.includes(k), k + ' não aparece em Esta coleção');
    }
    assert.equal(nomesColecao.filter((n) => /^(dono|padroes)\[/.test(n)).length, 0);
    total++;
    // trocar de aba não recarrega (o que foi digitado fica) e o nível vai para o endereço
    await page.selectOption('[name=rodizio_ativo]', '0');
    await page.click('.abas-nivel a[data-nivel-ir=dono]');
    assert.equal(await page.getAttribute('[data-niveis]', 'data-nivel-visto'), 'dono');
    assert.equal(new URL(page.url()).searchParams.get('nivel'), 'dono');
    assert.equal(await page.inputValue('[name="dono[rodizio_ativo]"]'), '0');
    assert.equal((await visiveis(page, '#rodizio [name]:not([type=hidden])')).every((n) => /^dono\[/.test(n)), true);
    await page.selectOption('[name="dono[recupera_ontem]"]', '1');
    await page.click('.abas-nivel a[data-nivel-ir=sistema]');
    assert.equal((await visiveis(page, '#rodizio [name]:not([type=hidden])')).every((n) => /^padroes\[/.test(n)), true);
    await page.fill('[name="padroes[max_sem_uso]"]', '30');
    // o Salvar grava os três níveis de uma vez, pela Configuração; a página volta lida de novo, na mesma aba
    await page.click('.barra-salvar button');
    await page.waitForFunction(() => window.ultimoAviso === 'Configuração salva.');
    const post = (await page.evaluate(() => fixture.posts))[0];
    assert.equal(post.recurso, 'config'); assert.equal(post.acao, 'salvar');
    assert.equal(post.rodizio_ativo, '0'); assert.equal(post['dono[recupera_ontem]'], '1'); assert.equal(post['padroes[max_sem_uso]'], '30');
    assert.equal(post.recupera_ontem, '1'); assert.equal(post['dono[rodizio_ativo]'], '0');
    assert.deepEqual((await page.evaluate(() => fixture.lidos)), ['opcoes:rodizio', 'opcoes:rodizio']);
    assert.equal(await page.getAttribute('[data-niveis]', 'data-nivel-visto'), 'sistema');
    total++;
    // o endereço com o nível abre nele
    f = {niveis: niveis(true, true), lidos: [], posts: [], busca: '?nivel=dono'};
    await abrir(page, 'rodizio', f);
    await page.locator('#rodizio [name=rodizio_ativo]').waitFor({state: 'attached'});
    assert.equal(await page.getAttribute('[data-niveis]', 'data-nivel-visto'), 'dono');
    total++;
    // o dono que não é master: duas abas; quem não é dono nem master: só a coleção, sem abas
    f = {niveis: niveis(true, false), lidos: [], posts: []};
    await abrir(page, 'rodizio', f);
    await page.locator('#rodizio [name=rodizio_ativo]').waitFor();
    assert.deepEqual(await page.locator('.abas-nivel a').evaluateAll((a) => a.map((x) => x.getAttribute('data-nivel-ir'))), ['colecao', 'dono']);
    assert.equal(await page.locator('[name^="padroes["]').count(), 0);
    f = {niveis: niveis(false, false), lidos: [], posts: []};
    await abrir(page, 'rodizio', f);
    await page.locator('#rodizio [name=rodizio_ativo]').waitFor();
    assert.equal(await page.locator('.abas-nivel').count(), 0);
    assert.equal(await page.getAttribute('[data-niveis]', 'data-nivel-visto'), null);
    assert.equal(await page.locator('[name^="dono["], [name^="padroes["]').count(), 0);
    total++;
    // ---- Sistema (só o master): o endereço e as rodadas do cron, as senhas, os parâmetros de operação (vazio volta ao config.php
    // ou ao padrão), o cron, o servidor de e-mail (o teste) e o SQL; a cópia de segurança desligada não aparece
    f = {sistema: sistema(), lidos: [], posts: []};
    await abrir(page, 'sistema', f);
    await page.locator('#sistema [name=url_sistema]').waitFor();
    assert.equal(await page.inputValue('[name=url_sistema]'), 'https://exemplo.invalid');
    assert.equal(await page.inputValue('[name=cron_espera_rodadas]'), '10');
    assert.equal(await page.inputValue('[name=senha_tamanho]'), '8');
    assert.equal(await page.locator('[name="parametros[cadastro_aberto]"] option[value=""]').textContent(), 'Padrão: sim');
    assert.equal(await page.getAttribute('[name="parametros[convite_dias]"]', 'placeholder'), '7');
    assert.equal(await page.locator('.registro-cron').count(), 1);
    assert.equal(await page.locator('form[data-recurso=conta] input[name=acao][value=email_testar]').count(), 2);
    assert.equal(await page.inputValue('form[data-recurso=conta] input[name=para][type=email]'), 'master@exemplo.invalid');
    await page.locator('#form-sql').waitFor();
    assert.equal(await page.locator('#copia').count(), 0);
    assert.equal(await page.locator('[name=rodizio_ativo], [name^="padroes["], [name^="dono["]').count(), 0);
    await page.selectOption('[name="parametros[cadastro_aberto]"]', '0');
    await page.fill('[name=cron_espera_rodadas]', '5');
    await page.click('#form-sistema .barra-salvar button');
    await page.waitForFunction(() => window.ultimoAviso === 'Configuração salva.');
    const ps = (await page.evaluate(() => fixture.posts))[0];
    assert.equal(ps.recurso, 'config'); assert.equal(ps['parametros[cadastro_aberto]'], '0'); assert.equal(ps.cron_espera_rodadas, '5'); assert.equal(ps['parametros[convite_dias]'], '');
    total++;
    // ---- Sistema com o estilo.css de verdade: os cartões e os parâmetros sem vão grande nem um por cima do outro, com o registro
    // do cron aberto (comprido) e os parâmetros de descrição curta e longa, do computador largo ao celular. Sem o organizador dos
    // quadros na página Sistema, o cartão que vem depois do Cron aberto ficava embaixo dele, com um vão de centenas de pixels
    // embaixo do vizinho curto
    f = sistema();
    f.cron_estado.registro = Array.from({length: 70}, (_, i) => 'linha ' + (i + 1) + ' da rodada do cron').join('\n');
    f.parametros = f.parametros.concat(Array.from({length: 9}, (_, i) => ({chave: 'p' + i, nome: 'Parâmetro ' + i, descricao: i % 3 === 0 ? 'Uma descrição comprida '.repeat(14) : 'curta',
      tipo: 'inteiro', valor: 1, origem: 'padrao', config_php: null, config_valor: null, padrao: 1, minimo: 0, maximo: 9})));
    f = {sistema: f, lidos: [], posts: [], estiloTodo: true};
    await ctx.close();
    for (const largura of [1440, 1100, 820, 390]) {
      const c2 = await browser.newContext({viewport: {width: largura, height: 900}});
      const p2 = await c2.newPage();
      p2.setDefaultTimeout(5000);
      p2.on('pageerror', (e) => erros.push(e.message));
      await abrir(p2, 'sistema', f);
      await p2.locator('#sistema [name=url_sistema]').waitFor();
      await p2.evaluate(() => document.querySelectorAll('#sistema details').forEach((d) => { d.open = true; }));
      const semVao = () => p2.waitForFunction(() => [...document.querySelectorAll('#sistema .config-grade, #sistema .parametros-sistema')].every((g) => {
        const rs = [...g.children].filter((e) => e.offsetParent !== null).map((e) => e.getBoundingClientRect());
        return rs.every((r, i) => {
          if (rs.some((o, j) => j !== i && Math.min(r.right, o.right) - Math.max(r.left, o.left) > 2 && Math.min(r.bottom, o.bottom) - Math.max(r.top, o.top) > 2)) { return false; }
          const acima = rs.filter((o, j) => j !== i && o.bottom <= r.top + 1 && Math.min(r.right, o.right) - Math.max(r.left, o.left) > Math.min(r.width, o.width) * .8);
          return !acima.length || r.top - Math.max(...acima.map((o) => o.bottom)) <= 96;
        });
      }), null, {polling: 50, timeout: 5000}).catch(async () => {
        throw new Error('Sistema em ' + largura + 'px: um vão grande (ou um quadro por cima do outro) entre os cartões ou os parâmetros: '
          + JSON.stringify(await p2.evaluate(() => [...document.querySelectorAll('#sistema .config-grade, #sistema .parametros-sistema')].map((g) => [...g.children]
            .map((e) => { const r = e.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.bottom)]; })))));
      });
      await semVao();
      // fechar e abrir de novo o registro (o quadro muda de altura depois de arrumado) e o requestAnimationFrame adiado (aba em
      // segundo plano ou CI ocupado): a arrumação acompanha
      await p2.evaluate(() => { window.requestAnimationFrame = () => 0; document.querySelectorAll('#sistema details').forEach((d) => { d.open = false; }); });
      await semVao();
      await p2.evaluate(() => document.querySelectorAll('#sistema details').forEach((d) => { d.open = true; }));
      await semVao();
      await c2.close();
    }
    total++;
    assert.deepEqual(erros, []);
    console.log(total + ' cenários de componente passaram (Ajustes do rodízio e Sistema; Chromium, sem rede/banco).');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exitCode = 1; });
