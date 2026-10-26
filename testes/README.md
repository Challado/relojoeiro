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
testes/paridade.sh --rolagem sqlite     # e a conferência da rolagem (veja abaixo) no primeiro
testes/paridade.sh --manter sqlite      # deixa o servidor no ar no fim, para olhar as telas (Ctrl+C derruba)
```

Para cada banco ele apaga e recria o banco de teste, roda `instalar.php` e `criar_usuario.php teste Senha@123`, sobe um
servidor `php -S` com a semente dos sorteios fixa (`RELOGIOS_SEMENTE=42`; sem ela, a escala desempata ao acaso e o plano muda
de uma rodada para outra) e roda o `cenario.php`; depois compara a fotografia de cada banco com a do primeiro. O resultado
esperado é `Tudo igual.` (e o comando termina com 0). Antes do resultado, ele confere que o PHP não deu nenhum aviso
(Warning, Notice, Deprecated) no servidor nem no roteiro, roda a conferência da arquitetura (`arquitetura.php`, que também
roda sozinha, sem banco: `php testes/arquitetura.php`; as regras estão no começo do arquivo), a dos idiomas (`textos.php`) e
a da documentação (`dicionario.php` e `campos.php`). Tudo fica numa pasta temporária nova (a fotografia, o log e a comparação
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
banco vazio, `php instalar.php`, `php criar_usuario.php teste Senha@123` e um servidor com a semente
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
comparação: os valores em segundos, as contas em horas das fórmulas (`HORAS("pulso"; 30)`) e as contas em dias (a mesma folga,
em dias: uma fórmula com os dias desde um lançamento), mais o atraso de um banco em relação ao outro naquela leitura; e a
idade da Lua da Hora certa (uma casa) tem folga de um passo do arredondamento. Dois vizinhos de uma lista com a mesma hora
dentro dessa folga (os avisos em ordem de data, ou as linhas do relatório de avisos, previstos com segundos de diferença) podem
aparecer trocados de lugar: é uma diferença leve; qualquer outra mudança de ordem é grave.

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
- irmãos que ficam um por cima do outro;
- o painel que rola na vertical (o painel do relógio ao lado da lista) e, por dentro, ficou mais largo que ele. Dentro dele, o
  conteúdo é conferido como o resto: só uma área que rola **só de lado** (uma tabela larga numa `.rolagem`) pode passar da
  largura de propósito.

Antes de conferir, ele deixa os dados do cenário "feios" de propósito, pela API: nomes longos de relógio e de grupo, uma
observação comprida e fotos (a cabeça do painel muda com elas). O cenário tem nomes e números curtos, e o que quebra no uso de
verdade é o comprido. `ESTRESSE=0` confere com os dados como estão.

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

# Conferência da rolagem

O `testes/rolagem.cjs` confere que nada fica travado na rolagem, em 8 tamanhos de tela: computador (1440 e 1280px), tablet
deitado (1024px) e de pé (768px) e celular (412, 390, 360 e 320px; os três primeiros com o tamanho e o toque de um Pixel 7,
um iPhone 13 e um Galaxy S9+, o último com a tela de 568px de altura de um iPhone SE). Ele rola como a pessoa rola, pelo
gesto de rolagem do Chromium a partir de um ponto da tela (o navegador rola a caixa que está embaixo do ponto e, no fim dela,
a de fora), e confere:

| O quê | Onde | Passa quando |
|---|---|---|
| a página rola até o fim | todas as páginas (as de `testes/paginas.cjs`) | o fim do que rola embaixo do dedo chega ao pé da tela: a página, ou o painel do relógio aberto no celular e no tablet (que cobre a tela e rola por dentro, com a página de trás parada) |
| o menu aberto rola até o último item | até 900px (o menu recolhido), na Hoje e na Configuração | o último item (o idioma) chega à tela, e a página de trás não andou: o menu rola por dentro |
| a página volta a rolar com o menu fechado | as mesmas | depois de abrir e fechar o menu, o fim da página chega ao pé da tela |
| a gaveta Explicações rola até a última explicação | Configuração, Winders, Programas e Cadastros | a última explicação chega ao pé da gaveta |
| a página volta a rolar com a gaveta fechada (Esc) | as mesmas | o fim da página chega ao pé da tela |

Pelo `paridade.sh`, sobre o banco do cenário: `testes/paridade.sh --rolagem sqlite`. Ou, com um servidor do cenário no ar:
`BASE=http://127.0.0.1:8101/ node testes/rolagem.cjs`; os dois argumentos filtram a página (ou `menu`, ou `gaveta`) e o
tamanho: `node testes/rolagem.cjs "menu" "cel-"`. O resultado esperado é `A rolagem confere: ...`; quando trava, diz o tamanho,
a página e o que não deu para alcançar.

# A documentação da API

O dicionário dos campos (o que é cada campo de cada resposta) tem **uma fonte só**: o `"campos"` da ajuda, no [`api/ajuda.php`](../api/ajuda.php) (o
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

# Isolamento entre contas

O [`isolamento.php`](isolamento.php) confere que uma conta não vê nem muda o que é de outra pelo token pessoal. Num banco
recém-instalado (com o usuário `teste` e o token do `config.teste.php`), ele cria três contas: a `ana`, que cadastra um relógio,
foto, documento, acessório, evento, destino e agenda com a marca SEGREDOANA; a `bia`, com a sua coleção; e o `caio`, sem
coleção. A bia e o caio, cada um pelo seu token, pedem todos os recursos da API com os ids e a coleção da ana nos parâmetros
(e `como=ana`), e tentam alterar e apagar o que é dela. Termina com 0 se nada da ana apareceu e nada dela mudou:

```sh
php testes/isolamento.php http://127.0.0.1:8101/api.php
```

Ele confere também o pedido de acesso, que é pelo código (o id) que o dono passa: a bia e o caio pedem acesso à coleção e a
tudo da ana pelo código, e nem a resposta nem a lista dos pedidos deles mostram o nome da coleção ou o login dela; e a página
Criar conta, sem login, não lista contas nem coleções. O `paridade.sh` o roda em cada banco, no fim (leva uns 10 segundos).

# Atualização de um banco antigo

O [`migracao.sh`](migracao.sh) confere o caminho de quem já usa o sistema: o `git pull` e o **Aplicar** da Configuração. Para
cada banco, ele tira uma cópia da versão anterior do código (pelo `git worktree`, numa pasta temporária), instala com ela um
banco vazio, cria o usuário de teste e lança dados pela API dela (um relógio com valores, leituras de carga, uma sessão no
pulso, um documento e um acessório). Depois sobe o código de agora no mesmo banco, lista as migrações pendentes, aplica e
confere: nenhuma pendente depois, o relógio, o documento, o acessório e o histórico de antes lidos pela API de agora, a
ficha, a página Hoje e o passaporte respondendo, e nenhum aviso do PHP. Sem migração nova entre as duas versões, confere do
mesmo jeito (o banco já fica em dia). Termina com 0 se tudo passou (uns 10 segundos):

```sh
testes/migracao.sh                                    # os quatro bancos, a partir do commit anterior (HEAD~1)
testes/migracao.sh sqlite firebird                    # só esses
MIGRACAO_BASE=v1.0 testes/migracao.sh sqlite          # a partir de outra versão (um commit, uma tag, um ramo)
```

Os bancos e o acesso são os do teste de paridade, com outro nome de banco (`TESTE_BANCO_MIG`, padrão `relogios_mig_teste`; o
arquivo do Firebird ganha `_mig` no nome), **apagado e recriado**; os servidores sobem a partir da porta `TESTE_PORTA_MIG`
(padrão 8150, duas por banco).

# A simulação dos modos de rodízio

O [`simulacao.sh`](simulacao.sh) vive cada modo de rodízio dia a dia, do jeito que o sistema roda de verdade, e confere o
resultado contra as regras do README ([Os modos de rodízio](../README.md#os-modos-de-rodízio), [O roteiro do
modo](../README.md#o-roteiro-do-modo), [O rodízio manual](../README.md#o-rodízio-manual), [A escala
inteligente](../README.md#a-escala-inteligente)). O tempo passa de mentira: o servidor (`php -S`), o cron e o roteiro rodam com
o [libfaketime](https://github.com/wolfcw/libfaketime) (`apt install faketime`), e o roteiro escreve a data e a hora num arquivo
que todos leem (`FAKETIME_TIMESTAMP_FILE`), trocado sempre de uma vez (escrito ao lado e posto no lugar com `rename`: o
libfaketime 0.9.11 em diante, o do Ubuntu 26.04, derruba o processo que lê o arquivo vazio, e o servidor lê o relógio o tempo
todo). Antes de começar, o `simulacao.sh` confere que o relógio falso pegou. Nada do código do sistema muda para isso.

O [`simulacao.php`](simulacao.php) instala um SQLite novo e monta um modelo (sete relógios de cinco grupos, um indisponível e
uma semana de uso antes do começo, cada relógio num dia); cada modo começa desse modelo, numa segunda-feira, e vive 28 dias:
às 07:10 o cron (a rodada da manhã: o plano completado, a escala replanejada, o relógio do dia no pulso), às 12:00 a página
Hoje (o relógio do dia, quem está no pulso, o plano à frente) e o que o modo faz nesse dia, às 22:30 o cron de novo. Os
sorteios saem de uma semente diferente em cada rodada do cron (repetível, sem rede). Os 19 modos:

| Modo | O que exercita |
|---|---|
| Um por semana (maior nota, sorteio pela nota, aleatório, fila) | a semana inteira com o mesmo relógio, cada forma de escolha |
| Um por semana, duas semanas, semana no domingo | o horizonte de duas semanas, a semana começando no domingo, um grupo |
| Semana e fim de semana (sorteio pela nota, fila, semana no domingo) | o trecho de segunda a sexta com um relógio, o fim de semana um por dia |
| Por dia da semana (sorteio pela nota com o rodízio manual; fila com três semanas) | o grupo de cada dia, o relógio fixo, o dia sem bloco (livre), o rodízio manual |
| Um por dia (maior nota, sorteio pela nota, fila), garantia de 5 dias | a garantia de rodízio |
| Um por dia com o ciclo (segunda e domingo) | o ciclo da semana |
| Aleatório todo dia | o sorteio sem garantia |
| Um por dia com as trocas à mão | Usando hoje, trocar por… amanhã, os relógios de um período, o período livre, sortear de novo um período, Sem relógio hoje, a próxima semana, Apagar tudo |
| Escala inteligente, 30 dias, com o rodízio manual | o replanejamento da manhã, o smartwatch dias seguidos, os dias à mão |
| Personalizado (o roteiro de exemplo do README) | o candidato e os dias do roteiro |

E as regras, dia a dia: todo dia tem relógio (menos os livres), disponível, e ele está no pulso ao meio-dia; o relógio de
cada dia é o que o plano dizia na véspera (fora da escala); o plano chega ao fim da última das semanas do modo (na escala,
até o fim dela); cada dia sai do bloco do dia da semana (o grupo com os subgrupos, ou o relógio fixo); "um para o trecho"
mantém o relógio no trecho dentro da semana; o de ontem fica de fora quando há outro candidato; o ciclo só repete depois de
todos; a fila tira quem espera há mais tempo; a garantia de rodízio (ninguém parado mais que `max_sem_uso` mais o número de
candidatos, fora do aleatório e sem as trocas à mão: o dia trocado muda só ele, e os seguintes, sorteados antes, ficam); o
aleatório tira relógios diferentes; na escala, o smartwatch no máximo 7 dias seguidos; o personalizado só com os
tradicionais na semana; o rodízio manual (o dia à mão sai como foi escolhido, o livre fica sem relógio e sem ninguém no
pulso, os dois continuam depois do Sortear de novo e dos crons, e o Decida por mim não repete o de ontem nem o de amanhã);
trocar o relógio de hoje no próprio dia, desligado (o padrão), é recusado; e o PHP não dá aviso nenhum. Com uma regra
falhando, ele diz o modo, o dia e o que aconteceu, e mostra o diário do modo (o relógio de cada dia; `*` escolhido à mão).
Leva uns 4 minutos e termina com 0 se tudo passou:

```sh
testes/simulacao.sh                     # os 19 modos
testes/simulacao.sh fifo "por dia"      # só os modos cujo nome tem "fifo" ou "por dia"
SIMULACAO_DIAS=56 testes/simulacao.sh   # cada modo por 8 semanas
```

A porta do servidor é a `TESTE_PORTA` (padrão 8191); a pasta da rodada, a `RELOGIOS_TESTE_PASTA` (padrão: uma temporária nova).

# No GitHub, a cada push

O [`.github/workflows/testes.yml`](../.github/workflows/testes.yml) roda os testes no GitHub Actions a cada push no `main` (e
quando pedido na aba **Actions**, em "Run workflow"), em sete trabalhos ao mesmo tempo:

- **Arquitetura, idiomas e documentação (sem banco):** o `arquitetura.php`, o `textos.php` (os dicionários, com as listas
  traduzidas no uso, `t(LISTA[...])`, os manuais e os READMEs traduzidos) e o `dicionario.php`;
- **Paridade (SQLite, PostgreSQL, MariaDB):** os bancos como serviços do GitHub e o `testes/paridade.sh` (com as conferências
  da documentação); depois, o `testes/migracao.sh` nos três, a partir da versão de antes do push (`github.event.before`);
- **Paridade (SQLite e Firebird):** o mesmo com o Firebird 3, e o `testes/migracao.sh firebird`;
- **Simulação dos modos de rodízio:** o libfaketime e o `testes/simulacao.sh` (veja acima): os 19 modos, 28 dias cada;
- **Layout das telas:** o Playwright com o Chromium, o `configuracao-interface.cjs` e o `testes/paridade.sh --layout sqlite`; quando falha, as capturas das
  telas ficam para baixar na página da rodada (Artifacts, "layout");
- **Rolagem das telas:** o `testes/paridade.sh --rolagem sqlite`: no computador, no tablet de pé e deitado e no celular, cada
  página rola até o fim, o menu aberto até o último item e a gaveta Explicações até a última explicação;
- **Nenhum texto fixo:** o `LINGUA=zh-CN testes/paridade.sh --textos sqlite`, o sistema inteiro em chinês.

O resultado aparece na aba **Actions** do repositório e no ✓ / ✗ ao lado de cada commit; uma falha também chega por e-mail.
