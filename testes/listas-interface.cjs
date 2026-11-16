// As listas fixas do sistema nas telas, no navegador, sobre o servidor do cenário: cada seletor (ou grupo de caixas) que escolhe
// numa lista fixa (listas_fixas, no lib.php) mostra exatamente os itens que a API manda com o dicionário (recurso=textos:
// listas), na ordem, com o nome de cada um. Nenhuma tela tem a lista dela: um item novo no núcleo aparece em todas sozinho,
// e um que falte numa tela é pego aqui. Confere também que toda lista fixa está em alguma tela conferida (uma lista nova
// entra no núcleo e nesta tabela).
//   BASE=http://127.0.0.1:8101/ node testes/listas-interface.cjs    (RELOGIOS_USUARIO, RELOGIOS_SENHA: os do config de teste)
const { chromium } = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright');
const BASE = process.argv[2] || process.env.BASE;
// a página, o que procurar nela e a lista: "parte" quando a lista é um trecho do seletor (as fontes das colunas: as duas
// fixas e depois as do dicionário de campos); "sem" quando um item só aparece para quem pode usá-lo (a árvore que já existe);
// "abrir": o que clicar antes (um formulário que só aparece depois de uma escolha)
const TELAS = [
  { url: 'cadastros.php', seletor: 'select[name="alvo"]', lista: 'campo_alvo' },
  { url: 'cadastros.php?aba=totais', seletor: 'select[name="largura"]', lista: 'total_largura' },
  { url: 'cadastros.php?aba=colunas', seletor: 'select[name="modo"]', lista: 'coluna_modo' },
  { url: 'cadastros.php?aba=colunas', seletor: 'select[name="tabela"]', lista: 'coluna_tabela', parte: true },
  { url: 'cadastros.php?aba=documentos', seletor: 'input[name="aceita[]"]', lista: 'arquivo_familia' },
  { url: 'programas.php', seletor: 'select[name="sentido"]', lista: 'winder_sentido' },
  { url: 'avisos.php?editar=1&evento=1', seletor: 'select[name="escala"]', lista: 'aviso_escala' },
  { url: 'avisos.php?editar=1&evento=1', seletor: 'select[name="agenda"]', lista: 'aviso_agenda' },
  { url: 'destinos.php', seletor: 'select[name="metodo"]', lista: 'destino_metodo' },
  { url: 'destinos.php', seletor: 'select[name="formato"]', lista: 'destino_formato' },
  { url: 'conta.php', seletor: 'select[name="seguranca"]', lista: 'email_seguranca' },
  { url: 'conta.php', seletor: 'form[data-recurso="colecoes"] select[name="opcao"]', lista: 'colecao_comeca', sem: ['arvore'] },
  { url: 'usuarios.php', seletor: 'select[name="papel"]', lista: 'usuario_papel' },
  { url: 'usuarios.php', seletor: 'select[name="colecao_nova"]', lista: 'usuario_colecao' },
  { url: 'execucoes.php', seletor: 'select[name="situacao"]', lista: 'cron_situacao' },
  { url: 'cadastro.php', seletor: 'input[type="radio"][name="opcao"]', lista: 'cadastro_colecao', semLogin: true },
];
// as listas que não são um seletor: o nome de um item aparece num texto da tela (quem pede a cópia de um documento no banco)
const SO_NOME = ['copia_por'];
(async () => {
  const b = await chromium.launch();
  const credenciais = { username: process.env.RELOGIOS_USUARIO || 'teste', password: process.env.RELOGIOS_SENHA || 'Senha@123' };
  const c = await b.newContext({ viewport: { width: 1280, height: 900 }, httpCredentials: credenciais, timezoneId: 'America/Sao_Paulo' });
  const visitante = await b.newContext({ viewport: { width: 1280, height: 900 }, timezoneId: 'America/Sao_Paulo' });
  const falhas = [];
  const listas = (await (await fetch(BASE + 'api.php?recurso=textos')).json()).listas || {};
  console.log('listas fixas que a API manda: ' + Object.keys(listas).length + ' (' + Object.keys(listas).join(', ') + ')');
  if (Object.keys(listas).length === 0) falhas.push('a API não mandou as listas fixas (recurso=textos: listas)');
  const vistas = new Set(SO_NOME);
  const paginas = {};
  for (const tela of TELAS) {
    vistas.add(tela.lista);
    const devia = (listas[tela.lista] || []).map((x) => x.chave + '=' + x.nome);
    if (devia.length === 0) { falhas.push(tela.lista + ': a lista não veio da API'); continue; }
    const chave = (tela.semLogin ? 'v:' : '') + tela.url;
    if (!paginas[chave]) {
      paginas[chave] = await (tela.semLogin ? visitante : c).newPage();
      paginas[chave].erros = [];
      paginas[chave].on('pageerror', (e) => paginas[chave].erros.push(e.message));
      await paginas[chave].goto(BASE + tela.url, { waitUntil: 'networkidle' });
    }
    const p = paginas[chave];
    // o primeiro seletor (ou o primeiro grupo de caixas, do mesmo formulário) com esse nome na página
    const tem = await p.evaluate((sel) => {
      const es = [...document.querySelectorAll(sel)];
      if (es.length === 0) { return null; }
      if (es[0].tagName === 'SELECT') {
        return [...es[0].options].map((o) => o.value + '=' + o.textContent.trim());
      }
      const form = es[0].closest('form') || document;
      return es.filter((e) => (e.closest('form') || document) === form).map((e) => e.value + '=' + (e.closest('label') ? e.closest('label').textContent.trim() : ''));
    }, tela.seletor);
    const onde = tela.url + ' ' + tela.seletor + ' (' + tela.lista + ')';
    if (tem === null) { falhas.push(onde + ': não achei o campo na tela' + (p.erros.length ? ' (erro na página: ' + p.erros[0] + ')' : '')); continue; }
    const texto = JSON.stringify(tem);
    const inteira = JSON.stringify(devia);
    const semOpcionais = JSON.stringify(devia.filter((x) => !(tela.sem || []).includes(x.split('=')[0])));
    const ok = tela.parte ? texto.includes(inteira.slice(1, -1)) : (texto === inteira || (tela.sem && texto === semOpcionais));
    if (!ok) falhas.push(onde + ': a tela não mostra a lista da API :: tem ' + texto + ' :: a API ' + inteira);
    else console.log('  ' + onde + ': ' + devia.length + ' itens, como na API');
  }
  for (const k of Object.keys(paginas)) {
    if (paginas[k].erros.length) falhas.push(k + ': erro na página: ' + paginas[k].erros.join(' | '));
  }
  // toda lista fixa está em alguma tela conferida (ou na lista das que só dão o nome)
  for (const nome of Object.keys(listas)) {
    if (!vistas.has(nome)) falhas.push('a lista fixa ' + nome + ' não está na tabela das telas conferidas (testes/listas-interface.cjs)');
  }
  await b.close();
  console.log('\nfalhas:', falhas.length); falhas.forEach((f) => console.log(' -', f));
  process.exit(falhas.length ? 1 : 0);
})();
