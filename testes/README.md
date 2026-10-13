# Teste de paridade entre os bancos

O sistema tem de se comportar igual no MySQL/MariaDB, no PostgreSQL, no SQLite e no Firebird. Este teste confere isso de ponta a ponta,
pela API, do jeito que uma pessoa usa o sistema:

- [`cenario.php`](cenario.php) faz ~100 escritas pela API: cadastra relógios de cada tipo; lança cordas, cargas, sol, winder
  e pilha; monta a escala inteligente; mexe nos critérios (e restaura); exclui grupos e relógios (as cascatas); troca de
  modo de rodízio; roda o cron; entra pelo login do site. Depois tira a "fotografia" de tudo o que a API devolve (~65
  leituras) num JSON.
- [`comparar.php`](comparar.php) compara duas fotografias e aponta cada diferença. Como o sistema calcula tudo a partir de
  agora, e as rodadas acontecem com alguns segundos de diferença, os horários e as contas que andam com o tempo têm uma
  pequena folga; e o hash das senhas, que tem um sal sorteado a cada cálculo, conta só pelo algoritmo. O resto tem de sair idêntico, inclusive o tipo de cada valor (`5` não é `"5"`).

## Como rodar

Com os bancos no ar (MySQL/MariaDB, PostgreSQL e Firebird; o SQLite não precisa de servidor), um comando faz tudo:

```sh
testes/paridade.sh                      # os quatro, ao mesmo tempo; o SQLite é a referência
testes/paridade.sh sqlite mysql         # só esses (o primeiro é a referência)
testes/paridade.sh --layout sqlite      # e, no fim, a conferência do layout (veja abaixo) no primeiro
testes/paridade.sh --manter sqlite      # deixa o servidor no ar no fim, para olhar as telas (Ctrl+C derruba)
```

Para cada banco ele apaga e recria o banco de teste, roda `instalar.php` e `criar_usuario.php teste senha123`, sobe um
servidor `php -S` com a semente dos sorteios fixa (`RELOGIOS_SEMENTE=42`; sem ela, a escala desempata ao acaso e o plano muda
de uma rodada para outra) e roda o `cenario.php`; depois compara a fotografia de cada banco com a do primeiro. O resultado
esperado é `Tudo igual.` (e o comando termina com 0). Tudo fica numa pasta temporária nova (a fotografia, o log e a comparação
de cada banco), que ele diz no começo.

O `config.php` de verdade não é lido nem tocado: o sistema roda com o [`config.teste.php`](config.teste.php), apontado pela
variável `RELOGIOS_CONFIG` (o `lib.php` usa o arquivo que ela diz; sem ela, o `config.php` da pasta, como sempre). O acesso aos
bancos vem do ambiente, com o padrão de uma máquina de desenvolvimento:

| Variável | Padrão | |
|---|---|---|
| `TESTE_BANCO` | `relogios_teste` | o banco no MySQL e no PostgreSQL. **Ele é apagado e recriado**: o nome tem de ter "teste" |
| `TESTE_MYSQL_HOST`, `_PORTA`, `_USUARIO`, `_SENHA` | `127.0.0.1`, `3306`, `teste`, `teste` | o MySQL/MariaDB |
| `TESTE_PG_HOST`, `_PORTA`, `_USUARIO`, `_SENHA` | `127.0.0.1`, `5432`, `postgres`, `teste` | o PostgreSQL |
| `TESTE_FB_ARQUIVO`, `TESTE_FB_USUARIO`, `TESTE_FB_SENHA` | `/var/lib/firebird/3.0/data/relogios_teste.fdb`, `SYSDBA`, `masterkey` | o Firebird (o arquivo também é apagado e recriado; tem de ter "teste" no nome) |
| `TESTE_PORTA` | `8101` | a porta do primeiro servidor (uma por banco, em sequência) |
| `RELOGIOS_TESTE_PASTA` | uma pasta temporária nova | onde fica tudo da rodada |

Ele usa o `psql`, o `mysql` e o `isql-fb` para recriar os bancos, e o `curl` para esperar o servidor subir.

Na mão, sem o `paridade.sh`: um `config.php` de teste (o `config.teste.php` serve, com `RELOGIOS_CONFIG`), para cada banco o
banco vazio, `php instalar.php`, `php criar_usuario.php teste senha123` e um servidor com a semente
(`RELOGIOS_SEMENTE=42 RELOGIOS_DB=sqlite php -S 127.0.0.1:8083 -t .`), o cenário com o mesmo ambiente (ele roda o cron como
processo filho) e a comparação:

```sh
RELOGIOS_SEMENTE=42 RELOGIOS_DB=sqlite php testes/cenario.php http://127.0.0.1:8083/api.php sqlite.json
php testes/comparar.php mysql.json sqlite.json
```

O cenário monta as datas e horas no mesmo fuso do sistema: o `FUSO` do config do sistema (o de `RELOGIOS_CONFIG`; sem ela, o
`config.php` da pasta; ou a variável `RELOGIOS_FUSO`, se o sistema estiver noutro lugar). Assim ele roda em qualquer hora do
dia, inclusive à noite no Brasil (quando em UTC já é o dia seguinte) e logo depois da meia-noite. O token que ele manda é o do
`config.teste.php` (`token-de-teste-123`); com outro `API_TOKEN`, passe-o na variável `RELOGIOS_TOKEN`.

Os bancos rodam **ao mesmo tempo** (o `paridade.sh` faz assim), cada um no seu servidor: com minutos de diferença entre as rodadas, as contas que andam
com o tempo passam da folga. O resultado esperado é `0 diferenças graves`; as leves (`--leves` mostra) são os números
dentro dos textos, como "há 3 min", os arredondamentos bem na fronteira, como 23,499 e 23,501, e o tamanho do arquivo da
cópia de segurança (comprimido, com o nome do banco e a hora no cabeçalho: até 2% de diferença).

O roteiro não deixa o resultado depender do segundo em que cada passo cai, nem da hora do dia em que ele roda:

- as sessões "há N minutos" (o sol, o winder, o carregador) contam todas do minuto em que o roteiro começa; o carregador é
  fechado e aberto de novo (`alternar`) num mesmo instante, há 90 minutos, longe da virada de uma hora cheia;
- antes de cada escrita do rodízio e dos modos (as que podem trocar o relógio de hoje), ele espera o segundo virar: a sessão
  do rodízio de cada relógio que passa por hoje dura ao menos 1 s (a que abre e fecha no mesmo segundo sumiria num banco e
  ficaria noutro);
- o que ele lança no passado fica longe das bordas das janelas das fórmulas (os últimos 30 dias), que andam com a hora do
  dia, e a reserva do automático não chega perto de zero hoje;
- a menos de 3 minutos de uma das horas em que o sistema muda de comportamento (a meia-noite, 06:30, 07:00, 07:30, 18:00,
  20:00 e 22:00: as rodadas do cron, o horário de uso e o sol que fecha sozinho), ele espera a hora passar antes de começar.

E o que cresce enquanto o roteiro roda (a sessão no pulso aberta desde o começo do horário de uso) tem folga de 2 minutos na
comparação: os valores em segundos e as contas em horas das fórmulas (`HORAS("pulso"; 30)`).

**Em todas as horas do dia.** Com o [`faketime`](https://github.com/wolfcw/libfaketime) (`apt install faketime`), o roteiro roda
num horário simulado: `faketime -f "+3600s" ...` adianta o relógio do servidor `php -S`, do roteiro e do cron em uma hora. Só
o SQLite e o Firebird servem para isso (neles, o "agora" vem todo do PHP); o MySQL e o PostgreSQL usam o relógio do próprio
servidor do banco no `NOW()`. Rodando os dois juntos em cada horário (de hora em hora e logo depois das viradas: 00:02, 07:32,
18:02, 22:02), o resultado esperado é o mesmo `0 diferenças graves` em todos.

# Conferência do layout

O `testes/layout.cjs` abre todas as telas num navegador de verdade (o Chromium, pelo
[Playwright](https://playwright.dev/)), em 15 tamanhos: computador (1100, 1152, 1280, 1366, 1440, 1536 e 1920px), tablet de pé
(768 e 820px) e deitado (1024 e 1180px) e celular (320, 360, 390 e 412px). Em cada uma, mede o que quebra:

- a página que rola de lado;
- o elemento que sai da caixa (o quadro, o cartão, a etiqueta) em que está;
- a etiqueta com fundo ou borda partida em duas linhas, e o botão com o texto partido ("Abrir" numa linha, "foto" na outra);
- o texto cortado, e o conteúdo (um texto que não quebra, uma etiqueta) que passa da caixa do próprio elemento;
- irmãos que ficam um por cima do outro.

As telas: a Hoje (com o Rodízio manual aberto, e com o painel de cada relógio ao lado e na janela flutuante), a Ficha de cada
relógio e a de um relógio novo, o Histórico, o Plano, os Critérios, os Grupos, os Cadastros, a Configuração, os Documentos, as
Execuções, os Usuários e a Ajuda. Abre os quadros recolhidos (`<details>`) e passa por cada opção dos menus que mostram partes
(o quadro Marcar), para conferir também o que só aparece depois de um clique.

Ele usa o banco do cenário. O jeito simples é pelo `paridade.sh`, que roda o cenário e depois a conferência:

```sh
npm install -g playwright        # uma vez (e o Chromium: npx playwright install chromium)
testes/paridade.sh --layout sqlite
```

Ou, com um servidor do cenário no ar (o `--manter` deixa): `BASE=http://127.0.0.1:8101/ node testes/layout.cjs`.

O resultado esperado é `{} 0 problemas`. Em `testes/layout/` (fora do git) ficam uma captura de cada tela em cada tamanho, o
`relatorio.tsv` (um problema por linha) e o `agrupado.txt` (o mesmo problema com os tamanhos em que aparece). Para conferir só
uma parte, os dois argumentos filtram o nome da tela e o do tamanho: `node testes/layout.cjs "ficha" "cel-"`. Os dados do cenário
são curtos; para pegar o que só quebra com o nome comprido de verdade, vale rodar também depois de dar nomes longos a alguns
relógios e grupos e pôr foto em alguns (a cabeça do painel muda com a foto: os botões Abrir, Trocar e Remover foto).

# A documentação da API

O dicionário dos campos (o que é cada campo de cada resposta) tem **uma fonte só**: o `"campos"` da ajuda, no `api.php` (o
que o `recurso=ajuda` devolve). O bloco "DICIONÁRIO DOS CAMPOS" do cabeçalho do `api.php` é gerado dele:

```sh
php testes/dicionario.php            # confere: o cabeçalho é o que a ajuda gera? (0: sim; 1: não, e diz a primeira linha diferente)
php testes/dicionario.php --gravar   # regrava o bloco do cabeçalho a partir da ajuda
```

Para documentar um campo novo: escreva a entrada no `"campos"` da ajuda (na seção do recurso, com o caminho do campo como
chave: `relogios[].compra.valor`, `config.painel_modo`, `destaque.relogio_id`) e rode com `--gravar`. No cabeçalho, cada
campo fica com o caminho numa coluna de 31 caracteres (o comprido, sozinho na linha) e o texto em linhas de até 140.

E todo campo que a API devolve tem de estar explicado. O [`campos.php`](campos.php) lê cada recurso de um sistema no ar (o do
cenário), junta o caminho de cada campo das respostas e procura no dicionário da seção do recurso:

```sh
php testes/campos.php http://127.0.0.1:8101/api.php            # 0: todos explicados; 1: lista os que faltam
php testes/campos.php http://127.0.0.1:8101/api.php --sobras   # e os explicados que não apareceram neste banco
```

O `paridade.sh` roda os dois depois da comparação (o `campos.php` no servidor do primeiro banco).

# No GitHub, a cada push

O [`.github/workflows/testes.yml`](../.github/workflows/testes.yml) roda os testes no GitHub Actions a cada push no `main` (e
quando pedido na aba **Actions**, em "Run workflow"), em três trabalhos ao mesmo tempo:

- **Paridade (SQLite, PostgreSQL, MariaDB):** os bancos como serviços do GitHub e o `testes/paridade.sh` (com as conferências
  da documentação);
- **Paridade (SQLite e Firebird):** o mesmo com o Firebird 3;
- **Layout das telas:** o Playwright com o Chromium e o `testes/paridade.sh --layout sqlite`; quando falha, as capturas das
  telas ficam para baixar na página da rodada (Artifacts, "layout").

O resultado aparece na aba **Actions** do repositório e no ✓ / ✗ ao lado de cada commit; uma falha também chega por e-mail.
