// Regressão de componente: DOM real em Chromium, sem rede e sem banco de produção.
// API, cookies, histórico e recarga são simulados. Não substitui um teste integrado do PHP/SGBD.
// node testes/configuracao-interface.cjs (requer playwright; CHROMIUM_PATH é opcional).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const fonte = fs.readFileSync(process.env.CONFIG_SCRIPT || path.join(__dirname, '..', 'configuracao.js'), 'utf8');
const salvas = {'pt-BR':'Configuração salva.',en:'Configuration saved.','zh-CN':'配置已保存。',hi:'सेटिंग सहेजी गई。',es:'Configuración guardada.',fr:'Configuration enregistrée.'};
function dadosConfig() {
  return {config:{idioma:'pt-BR',horario_manha:'06:30',horario_noite:'20:00',uso_inicio:'07:00',uso_fim:'22:00',encostado_dias:'30'},
    linguas:Object.keys(salvas).map(codigo=>({codigo,nome:codigo})),fuso_sistema:'UTC',fusos:[{nome:'UTC',deslocamento:'+00:00'}],
    mostrar_ate:{proximos:7,plano:31},cron_estado:{situacao:'rodando',texto:'Cron OK',registro:'registro\n'.repeat(30)},
    sol_fim:'18:00',config_php_copia:null,sistema:{pode_mudar:true,url_sistema:'https://exemplo.invalid'},
    aleatorio:{ultimo_lote:null,fora_desde:null},eventos:[],destinos:[],agendas:[]};
}
const base = `
var LINGUA=document.documentElement.lang, TEXTOS={}, ESTRITO=false;
function t(s,p) { var r=LINGUA==='pt-BR'?s:({"Telas":"Interface "+LINGUA,"Configuração":"Settings "+LINGUA,"Fechar":"Close "+LINGUA}[s]||s); Object.keys(p||{}).forEach(k=>r=r.split('{'+k+'}').join(p[k])); return r; }
function h(s) { return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function el(n,a,c) { return '<'+n+Object.keys(a||{}).map(k=>a[k]===true?' '+k:(a[k]===false||a[k]==null?'':' '+k+'="'+h(a[k])+'"')).join('')+'>'+(c||'')+(['input','img','br'].includes(n)?'':'</'+n+'>'); }
function parCampo(r,c,tipo) {return el('label',{class:'par-campo '+(tipo||'')},el('span',{},h(r))+el('span',{class:'controle'},c));}
function opcoesFuso(f,a,v) {return el('option',{value:''},v)+f.map(x=>el('option',{value:x.nome,selected:a===x.nome},x.nome)).join('');}
function dataBr(x){return String(x).slice(0,10);} function horaBr(x){return String(x).slice(11,16);}
function textoResposta(r){return (r.mensagem||'')+(r.erros&&r.erros.length?' '+r.erros.join(' '):'');}
function api(p){return window.fixtureAPI(p);}
function enviar(d){return window.fixtureEnviar(Object.fromEntries(d));}
var aoGravar=function(){};
document.addEventListener('submit',function(ev){var f=ev.target;if(ev.defaultPrevented||!f.hasAttribute('data-recurso'))return;ev.preventDefault();var d=new FormData(f);d.append('recurso',f.dataset.recurso);f.querySelectorAll('button').forEach(b=>b.disabled=true);enviar(d).then(r=>aoGravar(f,r,d)).catch(e=>{window.erroTeste=e.message;});});

`;
const estilo = `*{box-sizing:border-box}body{margin:0;font:16px system-ui}nav{padding:20px;background:#ddd}main{max-width:1000px;margin:auto;padding:20px}.config-grade{display:grid;grid-template-columns:1fr;gap:20px}.cartao-config{border:1px solid #bbb;border-radius:8px;padding:20px}.par-campo{display:grid;grid-template-columns:1fr 52%;gap:10px;padding:12px 0}.par-campo.largo{grid-template-columns:1fr}.controle>*:not([type=checkbox]){max-width:100%;width:100%}select,input,button{padding:10px;min-height:40px}input[type=hidden]{display:none}.barra-salvar{position:fixed;bottom:0;left:0;right:0;background:white;border-top:1px solid #999;padding:10px;z-index:10;display:flex;justify-content:space-between}.nota{line-height:1.6}#copia{min-height:350px;margin:30px 0 100px}pre{white-space:pre-wrap}@media(min-width:1000px){.config-grade{grid-template-columns:1fr 1fr}}`;
async function montar(page, fixture) {
  // document.open (setContent) descarta os listeners da montagem anterior; cada recarga tem novo estado de JS.
  await page.setContent('<!doctype html><html lang="'+fixture.lingua+'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+estilo+'</style><body><nav id="menu">'+fixture.lingua+'</nav><main id="configuracao"></main></body></html>');
  await page.evaluate(({f,salvas})=>{
    window.fixture=f;
    window.fixtureAPI=p=>Promise.resolve(structuredClone(p.recurso==='config'?f.dados:p.recurso==='migracoes'?{pendentes:[]}:
      p.recurso==='backup'?{ativo:true,pasta_ok:true,manter:7,documentos:false,copias:[],ultima:null}:{}));
    window.fixtureEnviar=d=>{
      f.posts.push(d);
      if(f.recusar)return Promise.resolve({ok:false,erros:['Valor recusado pelo servidor.']});
      if(d.recurso==='config')Object.entries(d).forEach(([k,v])=>{if(!['recurso','acao','lingua'].includes(k))f.dados.config[k]=v;});
      return Promise.resolve({ok:true,mensagem:salvas[d.lingua||f.lingua]});
    };
    Object.defineProperty(document,'cookie',{configurable:true,get(){return Object.entries(f.cookies).map(([k,v])=>k+'='+v).join('; ');},set(s){
      const [kv]=s.split(';');const i=kv.indexOf('=');const k=kv.slice(0,i),v=kv.slice(i+1);
      if(/max-age=0(?:;|$)/i.test(s))delete f.cookies[k];else f.cookies[k]=v;
    }});
  },{f:fixture,salvas});
  await page.addScriptTag({content:base});
  await page.evaluate(fonte=>{
    const f=window.fixture;
    const loc=new URL(f.url);
    loc.reload=()=>{f.recarregou=true;};
    const janela={location:loc,get scrollY(){return window.scrollY;},get scrollX(){return window.scrollX;},get innerHeight(){return window.innerHeight;},scrollTo:window.scrollTo.bind(window)};
    const historico={state:f.historico,replaceState(s,t,u){this.state=s;f.historico=s;if(u){f.url=String(u);const novo=new URL(u);loc.search=novo.search;loc.hash=novo.hash;}}};
    const armazenar={getItem(k){if(f.semStorage)throw Error('bloqueado');return f.storage[k]||null;},setItem(k,v){if(f.semStorage)throw Error('bloqueado');f.storage[k]=v;},removeItem(k){if(f.semStorage)throw Error('bloqueado');delete f.storage[k];}};
    // A janela injetada é só o ambiente de teste. O corpo de configuracao.js é executado sem mudar sua implementação.
    const carregar=new Function('window','history','sessionStorage',fonte+'\nreturn {posicao:typeof posicaoConfig === "function" ? posicaoConfig : null};');
    window.appTeste=carregar(janela,historico,armazenar);
  },fonte);
  await page.locator('#form-config').waitFor();
}
function inicial(){return {lingua:'pt-BR',url:'http://relojoeiro.test/configuracao.php?outro=1',dados:dadosConfig(),cookies:{lingua:'pt-BR'},historico:{},storage:{},posts:[],recusar:false};}
async function salvar(page){await page.locator('#form-config .barra-salvar button').click();}
async function estado(page){return page.evaluate(()=>structuredClone(window.fixture));}
async function recarga(page){
  await page.waitForFunction(()=>window.fixture.recarregou);
  const f=await estado(page);
  f.lingua=f.cookies.lingua;f.recarregou=false;
  // A preferência explícita do navegador vence o padrão pessoal (como usarTextos no api.js).
  assert.notEqual(f.cookies.lingua,f.cookies.lingua_auto);
  await montar(page,f);
  await page.locator('#aviso-config').waitFor();
}
(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||undefined});
  let total=0;
  try{
    for(const viewport of [{width:390,height:844},{width:820,height:1180},{width:1440,height:900}]){
      const ctx=await browser.newContext({viewport});const page=await ctx.newPage();page.setDefaultTimeout(5000);const erros=[];page.on('pageerror',e=>erros.push(e.message));
      await montar(page,inicial());
      await page.evaluate(()=>{document.querySelector('details').open=true;window.formAnterior=document.getElementById('form-config');window.scrollTo(0,900);});
      const y=await page.evaluate(()=>scrollY);
      await salvar(page);await page.locator('#aviso-config [role=status]').waitFor();
      assert.equal(await page.evaluate(()=>scrollY),y);
      assert.equal(await page.evaluate(()=>document.getElementById('form-config')===window.formAnterior),true);
      assert.equal(await page.locator('details').getAttribute('open'),'');
      assert.equal(await page.locator('#configuracao > .acao').count(),0);
      assert.equal(await page.locator('#aviso-config [role=status]').textContent(),salvas['pt-BR']);total++;
      for(const lingua of ['en','zh-CN','es']){
        await page.selectOption('#form-config [name=idioma]',lingua);
        await page.evaluate(()=>{const e=document.querySelector('[name=idioma]').closest('.par-campo');window.scrollTo(0,window.scrollY+e.getBoundingClientRect().top-130);});
        const p=await page.evaluate(()=>appTeste.posicao());
        await salvar(page);await recarga(page);
        const f=await estado(page);
        assert.equal(f.dados.config.idioma,lingua);assert.equal(f.posts.at(-1).lingua,lingua);
        assert.equal(await page.getAttribute('html','lang'),lingua);
        assert.equal(await page.locator('#aviso-config [role=status]').textContent(),salvas[lingua]);
        const top=await page.evaluate(p=>{const a=p.campo?Array.from(document.querySelectorAll('.par-campo')).find(e=>e.querySelector('[name]')?.name===p.campo):document.querySelectorAll('section.cartao-config')[p.quadro];return a.getBoundingClientRect().top;},p);
        assert.ok(Math.abs(top-p.topo)<2,'perdeu a âncora ao mudar o idioma');
        assert.equal(new URL(f.url).searchParams.get('outro'),'1');assert.equal(!!f.historico.retornoConfig,false);total++;
      }
      await page.evaluate(()=>fixture.recusar=true);
      await page.selectOption('[name=idioma]','hi');const antes=await page.evaluate(()=>scrollY);
      await salvar(page);await page.locator('#aviso-config [role=alert]').waitFor();
      assert.equal(await page.getAttribute('html','lang'),'es');assert.equal((await estado(page)).dados.config.idioma,'es');
      assert.equal(await page.inputValue('[name=idioma]'),'hi');assert.equal(await page.evaluate(()=>scrollY),antes);
      assert.equal(await page.locator('#aviso-config').count(),1);assert.equal(await page.locator('#form-config .barra-salvar button').isEnabled(),true);
      assert.deepEqual(erros,[]);total++;
      if(process.env.SCREENSHOT_DIR){fs.mkdirSync(process.env.SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SCREENSHOT_DIR,'config-'+viewport.width+'.png')});}
      await ctx.close();console.log('OK '+viewport.width+'x'+viewport.height+': salvar, três idiomas, validação.');
    }
    // Cookie automático antigo, parâmetro lingua= na URL, âncora e armazenamento indisponível.
    const ctx=await browser.newContext({viewport:{width:390,height:844}});const page=await ctx.newPage();
    const f=inicial();f.url='http://relojoeiro.test/configuracao.php?lingua=pt-BR&outro=1#copia';f.semStorage=true;f.cookies.lingua_auto='pt-BR';
    await montar(page,f);await page.selectOption('[name=idioma]','fr');await page.evaluate(()=>window.scrollTo(0,800));
    const pos=await page.evaluate(()=>appTeste.posicao());await salvar(page);await recarga(page);
    const depois=await estado(page);assert.equal(new URL(depois.url).searchParams.has('lingua'),false);assert.equal(new URL(depois.url).hash,'#copia');
    assert.equal(depois.cookies.lingua,'fr');assert.equal(depois.cookies.lingua_auto,undefined);
    const topo=await page.evaluate(p=>Array.from(document.querySelectorAll('.par-campo')).find(e=>e.querySelector('[name]')?.name===p.campo)?.getBoundingClientRect().top,pos);
    if(pos.campo)assert.ok(Math.abs(topo-pos.topo)<2);total++;
    // Sem mexer no campo Idioma, salvar outro ajuste não substitui uma escolha pessoal do navegador.
    const pessoal=inicial();pessoal.lingua='en';pessoal.cookies.lingua='en';await montar(page,pessoal);
    await salvar(page);await page.locator('#aviso-config').waitFor();
    assert.equal(await page.getAttribute('html','lang'),'en');assert.equal((await estado(page)).posts.at(-1).lingua,undefined);total++;
    await ctx.close();console.log(total+' cenários de componente passaram (Chromium; sem rede/banco).');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
