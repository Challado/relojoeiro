<?php
// A reconstrução: o roteiro para reescrever a aplicação inteira do zero, em qualquer linguagem, banco ou framework, e
// chegar ao mesmo sistema. Sai pela API (recurso=ajuda&parte=reconstrucao, ou recurso=reconstrucao). O texto descreve o
// que o sistema faz e as regras de cada parte; o modelo de dados e as listas (funções das fórmulas, tipos, âncoras,
// repetições, canais, migrações) são lidos na hora do próprio banco e do código, então nunca ficam atrás da versão em uso.
// As telas (RECONSTRUCAO_TELAS) são a especificação visual: o visual com os valores exatos, o menu e cada página com as tabelas, os
// campos, os botões e as mensagens; quem muda uma tela muda também a especificação dela aqui.
// A API (cada consulta, cada escrita e cada campo das respostas) está no recurso=ajuda; o cadastro inicial (campos, tipos,
// fórmulas, avisos, critérios e modos que vêm prontos) está no schema.sql e no recurso=cadastros e recurso=criterios.

// O que cada tabela guarda (o resto, as colunas e as regras, vem do banco)
const RECONSTRUCAO_TABELAS = [
    "agenda_evento" => "os eventos que o sistema criou no Google Agenda: a chave (o que o evento é: o tipo, o relógio e o dia), o id no Google, a data, o título e a assinatura (o resumo do conteúdo, para saber se mudou)",
    "aviso" => "os avisos do cadastro: o identificador, o nome, a fórmula da data prevista (expressao), a condição (vale quando), a antecedência em dias, o texto, se está ativo, o grupo da versão (no_id; vazio: todos), o tipo de lançamento que resolve, como a escala simula a solução (escala, simula_valor, simula_horas) e se entra na agenda só dentro da janela ou sempre",
    "campo" => "os campos do cadastro dos relógios: o identificador (o nome nas fórmulas), o nome, o tipo, a unidade, o valor padrão, o grupo em que vale (vazio: todos) e a ordem",
    "campo_opcao" => "as opções de um campo do tipo lista, em ordem (sem repetir, sem diferenciar maiúsculas e acentos)",
    "campo_valor" => "o valor de cada campo em cada relógio (texto normalizado pelo tipo do campo: número com ponto, data AAAA-MM-DD, sim/não 1 ou 0)",
    "canal_aviso" => "para cada canal (tg: Telegram, ag: Google Agenda) e cada tipo de aviso (dia, vespera, o identificador de um aviso) ou evento personalizado: se ele vai pelo canal, se tem mensagem própria e o texto dela",
    "config" => "os valores soltos da Configuração (horários, limites, mensagens padrão, marcas do sistema), chave e valor",
    "criterio_faixa" => "as faixas de um subparâmetro: de um número até outro (ou sem limite) ou uma categoria, e a nota (0 a 100) que o valor ganha nela",
    "criterio_parametro" => "os parâmetros de um conjunto de critérios: o lugar (todos, um grupo ou um relógio), o nome, o peso (%) e a ordem",
    "criterio_sub" => "os subparâmetros de um parâmetro: o nome, a variável que mede (um campo ou uma fórmula), o peso dentro do parâmetro (%) e a ordem",
    "cron_execucao" => "cada execução do cron que registrou algo: início, fim, duração, o registro em texto, se teve erro e se teve atividade",
    "documento" => "os documentos de cada relógio: a categoria, o título, a data, a descrição, o nome e o tipo do arquivo, o tamanho, o caminho na pasta (arquivo e miniatura), se a cópia no banco está completa (no_banco), se o próprio arquivo pede a cópia (copia_banco) e o SHA-256",
    "documento_categoria" => "as categorias dos documentos (Manual, Nota fiscal, Fotos...): identificador, nome e ordem",
    "documento_categoria_aceita" => "as famílias de arquivo que uma categoria aceita (imagem, video, audio, pdf, xml); nenhuma: qualquer arquivo",
    "documento_parte" => "a cópia de um documento dentro do banco, em pedaços de 4 MB (parte 0, 1, 2...; a parte -1 é a miniatura)",
    "evento_dia" => "os dias da semana de um evento personalizado semanal (1 segunda a 7 domingo)",
    "evento_disparo" => "cada ocorrência de um evento personalizado que já disparou (para não disparar duas vezes)",
    "evento_personalizado" => "os lembretes do usuário: nome, se está ativo, a repetição (uma vez, todo dia, em dias da semana, todo mês, a cada N dias), a data de início, a hora, o dia do mês, o intervalo e o relógio (opcional)",
    "formula" => "as fórmulas do cadastro: o identificador (o nome nas outras fórmulas, nos avisos e nos critérios), o nome, a expressão, a unidade e o grupo da versão (no_id; vazio: todos). A mesma fórmula pode ter uma versão por grupo: vale a do grupo mais perto do relógio",
    "foto" => "a foto de cada relógio (a imagem inteira, o tipo e quando foi trocada)",
    "lancamento" => "o histórico de cada relógio: o tipo, o início (o instante, num instantâneo), o fim (numa sessão; vazio: aberta), o valor (numa leitura) e a origem (manual, rodizio, importado)",
    "lancamento_tipo" => "os tipos de lançamento: identificador, nome, formato (instantâneo, com valor ou sessão), unidade, a hora em que a sessão esquecida fecha sozinha (fecha_as), o grupo, a ordem, se a sessão é exclusiva (o relógio fica num lugar só), se a leitura mede o gasto e a condição (vale quando)",
    "medicao" => "cada medição do gasto: o lançamento (a leitura) que a fechou, se mede o uso ou o repouso, o gasto achado (% por dia), as duas leituras, o intervalo, as horas no pulso e fora, o peso (horas) e se entra na média",
    "modo" => "os modos de rodízio: nome, a forma de escolha, a ordem, o horizonte da escala inteligente (escala_dias; vazio: o modo sorteia pelos blocos), o ciclo e a marca do modo em uso (1 no ativo, vazio nos outros: no máximo um)",
    "modo_bloco" => "os blocos de um modo: nome, de onde sortear (um grupo; vazio: todos), um relógio por dia ou um para o bloco inteiro, o relógio fixo (opcional) e a ordem",
    "modo_bloco_dia" => "os dias da semana de um bloco (1 segunda a 7 domingo; um dia em um bloco só)",
    "no" => "a árvore dos grupos: o nome, o grupo de cima (vazio: a raiz) e a ordem entre irmãos",
    "plano" => "o relógio de cada dia: o relógio, o bloco do modo que o escolheu, a origem (sorteio ou manual), a ação do dia (o que fazer antes de usar), o motivo da escolha e quando foi gravado",
    "relogio" => "os relógios: nome, grupo, se está disponível para o rodízio, se pede a cópia no banco de todos os documentos e quando foi cadastrado",
    "plano_livre" => "os dias livres do plano: sem relógio e sem sorteio (nem a escala), para escolher à mão",
    "usuario" => "quem entra no site: o login e o hash da senha (password_hash)",
];

// As constantes do config.php
const RECONSTRUCAO_CONFIG_PHP = [
    "DB_TIPO" => "mysql (MySQL ou MariaDB, o padrão), pgsql (PostgreSQL), sqlite ou firebird (Firebird 3+)",
    "DB_HOST, DB_PORTA, DB_NOME, DB_USUARIO, DB_SENHA" => "o servidor do banco (MySQL, PostgreSQL e Firebird; no Firebird, DB_NOME é o caminho do arquivo .fdb no servidor ou um apelido, e DB_HOST vazio abre o arquivo direto)",
    "DB_ARQUIVO" => "o arquivo do banco (SQLite), fora da pasta publicada",
    "API_TOKEN" => "obrigatório, com pelo menos 10 caracteres: sem ele o sistema inteiro para (as páginas, a API e o cron dizem o motivo)",
    "FUSO" => "o fuso horário do sistema e das datas gravadas (vazio: o do PHP)",
    "MSG_ENDPOINT, MSG_DESTINATARIO, MSG_TITULO" => "a API de mensagem (Telegram): um GET com destinatario, titulo e mensagem; resposta 2xx é sucesso; sem o endereço, nada é enviado",
    "DOCUMENTOS_PASTA" => "a pasta dos arquivos dos documentos, fora da pasta publicada, com escrita para o PHP",
    "DOCUMENTOS_LIMITE (e o nome antigo MANUAL_LIMITE)" => "o maior documento aceito, em bytes (sem ele: 100 MB; 0 ou -1: sem limite do sistema)",
    "DOCUMENTOS_COPIA_BANCO" => "a cópia dos documentos no banco para o sistema inteiro: true, todos; false, nenhum; ausente, o relógio e o arquivo decidem",
    "BACKUP_PASTA, BACKUP_MANTER, BACKUP_DOCUMENTOS" => "a cópia de segurança do banco: a pasta (fora da publicada; sem ela, não há cópia automática), quantas "
        . "ficam (padrão 7) e se leva o conteúdo dos documentos (padrão: não)",
    "ANTIGO_HOST, ANTIGO_PORTA, ANTIGO_USUARIO, ANTIGO_SENHA" => "só para importar o sistema anterior (importar.php)",
    "ALEATORIO_RANDOMORG_URL, ALEATORIO_ANU_URL" => "só para testes: as fontes dos números aleatórios (RANDOM.ORG e ANU QRNG); trocadas, os sorteios pedem os números a um servidor de teste",
    "GOOGLE_TOKEN_URL, GOOGLE_API_URL" => "só para testes: os endereços do Google que a agenda usa (o do token e o da API do Google Agenda); trocados, a sincronização conversa com um servidor de teste",
];

// As telas: o visual, o menu e cada página, como a reescrita tem de reproduzir (os textos entre aspas são os da tela)
const RECONSTRUCAO_TELAS = [
    "como_ler" => "A interface que a reescrita tem de reproduzir, tela por tela: o visual (cores, letras, controles e componentes, com os valores "
        . "exatos), o menu, e cada página com o endereço, o que ela lê e grava na API, e as partes em ordem, com cada tabela (as "
        . "colunas), cada formulário (os campos com o rótulo, o tipo, o padrão e os limites), cada botão (o texto e o que ele manda para "
        . "a API) e cada mensagem. Os textos entre aspas são os da tela, palavra por palavra. As capturas em docs/telas/ mostram o "
        . "resultado; o estilo.css é a referência de cada medida. O código de cada página, como está agora, sai pelo recurso=codigo (os "
        . "arquivos dela em codigo; o HTML que ela entrega ao navegador em recurso=codigo&arquivo=<página>&html=1&cru=1).",
    "visual" => [
        ["id" => "cores", "titulo" => "Cores", "itens" => [
            "Fundo da página: aço #e4e8ec. Bordas: aço escuro #c3cad2. Texto: tinta #1c2530 (também o fundo da barra do menu). "
                . "Rótulos e notas: cinza #6b7684. Botões, links, aba ativa, barras de carga: safira #1f4f8f. O que fazer, recados e "
                . "carga baixa: âmbar #a8661a. Fundo de cartões, quadros e tabelas: papel #fbfcfd.",
            "Etiquetas de estado (pílulas): em uso: fundo #e3f3e6, texto #1e6b2e, borda #a9d8b3. Em repouso e erro: fundo #fbe8e6, "
                . "texto #9b2c22, borda #efb5ae. Indisponível e sem atividade: fundo #eef1f4, texto cinza, borda aço escuro. Atividade, "
                . "\"você\" e \"relógio de hoje\": fundo #e6eef8, texto #1f4f8f, borda #b8cbe6. Numa sessão que não é o pulso (no sol, no "
                . "winder, no carregador), outras sessões e carga: fundo #fff4e0, texto #8a5a00, borda #efd29a.",
            "Origem de um valor do cadastro: \"informado\" em azul (#e6eef8 / #1f4f8f / #b8cbe6), \"padrão\" em bege (#fbf1e3 / #8a5a00 "
                . "/ #ecd2a8), \"vazio\" sem etiqueta e com a linha em cinza.",
            "Atrasado (um prazo vencido, \"faltam\" e \"passa\" das somas): texto #9b2c22 em negrito. Caixa de erros de gravação: fundo "
                . "#fdf0ee, borda #e3b4ae, texto #7a2419, cantos 10px. Linha de execução com erro: fundo #fdf2f1.",
        ]],
        ["id" => "letras", "titulo" => "Letras e números", "itens" => [
            "Fonte: \"Segoe UI\", \"Helvetica Neue\", Arial, sans-serif, 16px, entrelinha 1,5.",
            "Título da página (h1): grande, de 2rem a 3,4rem conforme a largura (clamp(2rem, 6vw, 3.4rem)), negrito 700, "
                . "espaçamento -0,02em, entrelinha 1,05. Título de seção (h2): 1,1rem. Notas e explicações: 0,92rem, cinza. Título dos "
                . "quadros do painel do relógio: 0,78rem, maiúsculas, negrito, espaçado 0,06em, cinza, com uma linha embaixo. Números em "
                . "tabelas e valores com algarismos de largura igual (tabular).",
            "Formatos: data DD/MM/AAAA (curta: DD/MM); hora HH:MM; números com vírgula e sem zeros sobrando (1 casa por padrão); "
                . "dinheiro \"R\$ 1.234,56\"; porcentagem \"12,5%\"; duração pelas duas maiores partes: \"1a 5m\", \"5d 3h\", \"12h 22min\", "
                . "\"40min\", \"menos de 1min\"; dia da semana curto: seg, ter, qua, qui, sex, sáb, dom.",
        ]],
        ["id" => "controles", "titulo" => "Campos, listas e botões", "itens" => [
            "Uma altura só para campo, lista e botão: 2,4rem, para ficarem alinhados lado a lado. Cantos 8px; campo e lista com "
                . "borda 1px aço escuro e fundo branco; textarea com a mesma borda. Foco visível: contorno 2px safira afastado 2px. Campo "
                . "numérico curto: 5,5rem. Números digitados aceitam vírgula ou ponto (teclado decimal no celular). No celular, os "
                . "campos têm letra de 16px (o iPhone não aproxima a tela).",
            "Botão principal: fundo safira, texto branco, borda safira, preenchimento 0,3rem 1rem. Botão \"leve\": fundo "
                . "transparente, texto e borda safira (as ações secundárias). Botão \"discreto\": sem borda, 0,85rem, preenchimento lateral "
                . "0,45rem (↑, ↓ e Excluir das linhas). O Excluir das ações de linha é vermelho: texto #9b2c22, borda #efb5ae, fundo "
                . "#fbe8e6 ao passar o mouse.",
            "Caixa de marcar (checkbox) sempre à esquerda do texto, na mesma linha. Rótulo de campo: acima do campo nas grades de "
                . "formulário; à esquerda na Configuração (veja os componentes). Botões desativados durante o envio de um formulário.",
        ]],
        ["id" => "componentes", "titulo" => "Componentes", "itens" => [
            "Barra do menu: fundo tinta, links #b9c3ce sem sublinhado; o da página atual em branco com uma linha branca de 2px "
                . "embaixo.",
            "Cartão (as seções das páginas de cadastro): fundo papel, borda 1px aço escuro, cantos 12px, preenchimento 1rem, título "
                . "h2 em cima.",
            "Quadro (o painel do relógio): igual ao cartão (cantos 12px, preenchimento 0,85rem 1rem), com o título em maiúsculas "
                . "pequenas, o corpo, e um rodapé opcional (linha em cima, botões à direita, alinhados no pé: os quadros da mesma linha "
                . "da grade têm a mesma altura). Quadro \"largo\" ocupa a linha inteira da grade.",
            "Pares (rótulo e valor): duas colunas, o rótulo em cinza 0,85rem à esquerda (até 55% da largura), o valor à direita; "
                . "uma linha fina aço entre os pares (a última sem); um texto menor (0,78rem, cinza) embaixo do valor é a explicação "
                . "dele; rótulo vazio continua a linha de cima.",
            "Tabela: fundo papel, cantos 10px, cabeçalho em cinza 0,9rem negrito, células com preenchimento 0,55rem 0,75rem e uma "
                . "linha aço embaixo. Linha clicável: o fundo muda para #f1f4f8 ao passar e fica #e3eaf5 quando selecionada; fim de "
                . "semana em #f4f6f8; o relógio de hoje com uma faixa safira de 3px na borda esquerda, e um com aviso pendente com a "
                . "faixa âmbar. Tabela compacta (dos cadastros): 0,88rem, sem fundo próprio.",
            "Etiqueta de estado: pílula com cantos arredondados por inteiro, 0,8rem negrito, preenchimento 0,12rem 0,55rem, cores "
                . "em \"cores\". Numa linha só; nos pares rótulo/valor (o quadro Agora do painel estreito) e nas marcas da cabeça, a "
                . "comprida (\"Em repouso desde 25/09 20:40\") quebra a linha nos espaços (nunca no meio da palavra), com cantos de 0,8rem, "
                . "e fica dentro do quadro.",
            "Barra de carga: trilho aço com 7px de altura (44px na tabela, 56 a 70px nos quadros), preenchido em safira até a "
                . "porcentagem (âmbar com carga até 20%), seguido do número em negrito (\"68%\"; estimado: \"~68%\") e, menor, de onde a "
                . "carga vem.",
            "Janela de data (o \"só hoje\" / \"até sexta, 02/10\" do relógio do dia): caixa branca, borda 2px tinta, cantos 3px, "
                . "negrito, sombra interna fina, como a janela de data de um mostrador.",
            "Recado (o resultado de uma gravação): um parágrafo âmbar em negrito no topo da área (ou do painel do relógio). Erros "
                . "de gravação: a caixa vermelha clara com \"Não foi salvo:\" e um parágrafo por erro, no topo da página.",
            "Abas (Cadastros e Documentos): links lado a lado com borda aço e cantos 0,4rem, preenchimento 0,35rem 0,8rem, texto "
                . "safira; a ativa com fundo safira e texto branco.",
            "Grade de formulário (form-grade): colunas de no mínimo 14rem lado a lado, o rótulo acima de cada campo; textareas, "
                . "grupos de caixas e botões na linha inteira. Linha de inclusão: campos com o rótulo em cima, lado a lado, separados do "
                . "resto por uma linha tracejada acima.",
            "Barra de salvar (Configuração): presa no pé da tela enquanto a página rola (sticky), fundo papel quase opaco, borda, "
                . "cantos 12px, sombra para cima; à esquerda \"As mudanças desta página valem depois de salvar.\", à direita o botão.",
            "Sem foto: quadrado tracejado (2px aço escuro, cantos 14px) com \"Sem foto\" em cinza no meio. Foto: quadrada "
                . "(recortada), cantos 14px, borda aço escuro. Miniatura na tabela: 28px (36px no celular), cantos 5px.",
            "Ações de uma linha (Editar, ↑, ↓, Excluir): numa linha só, à direita da linha da tabela.",
            "Resumo em números (Execuções e Histórico): uma grade de caixinhas (4 colunas; 2 no celular), cada uma com o número "
                . "grande (1,4rem) e o que ele é embaixo em cinza; a de erros com fundo vermelho claro.",
        ]],
        ["id" => "layout", "titulo" => "Larguras e tamanhos de tela", "itens" => [
            "Conteúdo centrado com margens de 1,25rem: largura máxima 56rem nas páginas comuns (Plano, Usuários), 72rem na Ficha, "
                . "76rem na Configuração, Cadastros, Critérios, Grupos, Execuções e Histórico, 60rem na Ajuda. A página Hoje usa a "
                . "largura toda.",
            "Página Hoje: duas colunas, o meio e o painel do relógio com 22rem à direita, preso ao rolar (sticky) e com rolagem "
                . "própria; sem relógio aberto, o meio ocupa tudo. Com a Configuração \"Numa janela flutuante, grande\": o relógio abre no "
                . "meio da tela, até 78rem ou 94% da largura, de 3% do alto a 3% de baixo, cantos 16px, sombra forte, com o fundo "
                . "escurecido (rgba(16, 22, 29, .55)) e os quadros lado a lado; fecha no ×, no Esc ou num clique no fundo.",
            "Até 900px (celular e tablet de pé): o menu recolhido; as tabelas viram fichas (cada célula com o título da coluna ao "
                . "lado) ou cartões; o painel do relógio cobre a tela inteira, com o botão \"× Fechar\" preso no alto, e o voltar do "
                . "aparelho fecha o painel; as grades viram uma coluna. De 901 a 1199px (tablet deitado): o painel \"ao lado\" desliza por "
                . "cima, pela direita, com até 30rem, cantos 16px à esquerda e o fundo escurecido. De 1200px em diante: o painel ao lado.",
            "Nenhuma página rola de lado, em nenhum tamanho. A mesma informação em todos os tamanhos: nada é escondido por falta de "
                . "espaço (a tabela dos relógios vira cartões com todos os campos quando as 11 colunas não cabem).",
            "As tabelas de um quadro se arrumam pela largura do quadro (container queries), não pela da tela: o mesmo quadro é "
                . "estreito no painel ao lado e largo na ficha. Até 40rem, as Medições do gasto viram cartões; até 26rem, as tabelas dos "
                . "Dados do relógio ficam mais justas e o valor quebra a linha.",
            "Nada sai da caixa em que está, de 320px (celular pequeno) a 1920px: as grades de colunas automáticas encolhem a coluna "
                . "mínima até a largura da tela; um grupo de campos (fieldset) e os menus dentro dele encolhem com a tela (um menu de "
                . "nome comprido não vaza); o menu de escolher o relógio da Ficha fica na largura da tela; o código comprido da Ajuda "
                . "(uma URL de exemplo) quebra a linha. Os botões nunca partem o texto em duas linhas: os da foto (Abrir, Trocar, Remover "
                . "foto) ficam numa linha, na largura da foto (6,5rem no painel ao lado e no celular); o botão de marcar do quadro "
                . "Marcar fica numa linha própria, embaixo dos campos. Até 420px, as tabelas do painel e o resumo do Plano ficam mais "
                . "justas (0,82rem, 0,3rem entre as colunas) e quebram dentro da célula. No Histórico do celular, as colunas são 25%, "
                . "25%, 18% e 32% (o Estado, a mais larga).",
        ]],
        ["id" => "comportamento", "titulo" => "Comportamento comum", "itens" => [
            "Cada página entrega só o esqueleto; o JavaScript dela lê a API e monta a tela. Gravar é um formulário que manda um "
                . "POST para a API (recurso e acao nos campos); depois a página se remonta com os dados novos, sem recarregar, e mostra a "
                . "mensagem da API no topo (ou no painel do relógio, se a gravação era dele).",
            "Antes de toda exclusão e de toda ação que refaz o plano, uma confirmação do navegador com o texto do que vai acontecer "
                . "(os textos estão em cada página). Durante o envio os botões do formulário ficam desativados; se a conexão falhar: "
                . "\"Não consegui gravar: <motivo>. Confira a conexão e tente de novo.\"",
            "Sem login: a página manda para api.php?recurso=entrar&volta=<a página>, o navegador pede o usuário e a senha e volta. "
                . "Banco desatualizado (503 com pendentes): a página vai para a Configuração, que mostra só o aviso e o \"Aplicar agora\". "
                . "Erro ao ler: \"Não consegui ler <o quê>: <motivo>\" em âmbar, no lugar da página.",
            "Fotos reduzidas no navegador antes de enviar: no máximo 1200px no lado maior, JPEG 85%, com a prévia na tela; imagem "
                . "que não abre: alerta \"Não consegui abrir essa imagem. Use JPEG ou PNG.\".",
            "A foto do relógio ampliada, só no detalhe do relógio (o painel, ou a ficha): duplo clique na foto (o cursor é a lupa "
                . "de ampliar) ou o botão \"Abrir foto\" embaixo dela abre por cima de tudo uma janela flutuante (nas outras fotos, como a "
                . "do \"Hoje é dia de\" e as miniaturas da lista, o clique abre o relógio e o duplo clique não faz nada a mais): fundo "
                . "rgba(10,14,20,.82) na tela inteira, a foto no meio, no tamanho dela (os pixels da imagem em px da página), cantos 6px, "
                . "fundo branco e sombra; maior que a área que se vê, em escala, sem deformar: a escala é o menor entre 1, (largura − "
                . "32px) ÷ a largura da foto e (altura − 72px) ÷ a altura da foto, com a largura e a altura sendo o menor entre o monitor "
                . "(screen.availWidth/availHeight) e a janela do navegador; refeita quando a janela muda de tamanho ou de monitor. "
                . "Embaixo, em branco, \"<nome> · <largura> × <altura> px\" e, em escala, \"(mostrada a N%)\". Um botão redondo \"×\" (branco, "
                . "2,4rem) no canto de cima, à direita. Fecha com o ×, com um clique fora da foto ou com Esc (o Esc fecha só a foto: o "
                . "painel por baixo continua aberto); enquanto aberta, a página não rola.",
            "O título da aba do navegador é o título da página (\"Relógio de hoje\", \"Plano\", \"Configuração\", \"Critérios de escolha\", "
                . "\"Grupos de relógios\", \"Execuções do cron\", \"Usuários\", \"Cadastros\", \"Ajuda\", \"Ficha do relógio\", \"Documentos\", \"Desejos\", \"Estatísticas\"; no "
                . "histórico, \"Histórico — <relógio>\").",
            "O navegador de páginas (Próximos dias, Dia a dia do Plano, Rodízio manual): botões leves pequenos (padding .3rem "
                . ".65rem, letra .9rem) numa linha que quebra: \"<\" (dica \"Anterior\"; desativado na primeira), os números (a 1, a última e "
                . "até 2 de cada lado da atual, com \"…\" sem borda nos saltos; dica \"Página N\"; a atual em safira com letra branca, sem "
                . "ser botão) e \">\" (dica \"Próximo\"; desativado na última). Uma página só: sem navegador. Cada botão relê a API com a "
                . "página.",
        ]],
    ],
    "menu" => [
        "como" => "Na barra escura do topo, nessa ordem, em todas as páginas; o da página atual em branco e sublinhado. A Ficha, o Histórico "
            . "e os Documentos não estão no menu: abrem do painel do relógio (o histórico e os documentos numa aba nova). No celular (até "
            . "900px): a barra, presa no alto ao rolar, mostra o nome da página atual à esquerda e o botão \"☰ Menu\" à direita; aberto, "
            . "ele vira \"✕ Menu\" e os itens aparecem em duas colunas, cada um num botão escuro (#27323f), o atual em safira.",
        "itens" => [
            ["pagina" => "index.php", "rotulo" => "Hoje"],
            ["pagina" => "plano.php", "rotulo" => "Plano"],
            ["pagina" => "desejos.php", "rotulo" => "Desejos"],
            ["pagina" => "estatisticas.php", "rotulo" => "Estatísticas"],
            ["pagina" => "configuracao.php", "rotulo" => "Configuração"],
            ["pagina" => "criterios.php", "rotulo" => "Critérios"],
            ["pagina" => "grupos.php", "rotulo" => "Grupos"],
            ["pagina" => "execucoes.php", "rotulo" => "Execuções do cron"],
            ["pagina" => "usuarios.php", "rotulo" => "Usuários"],
            ["pagina" => "cadastros.php", "rotulo" => "Cadastros"],
            ["pagina" => "ajuda.php", "rotulo" => "Ajuda"],
        ],
    ],
    "paginas" => [
        [
            "id" => "hoje",
            "pagina" => "index.php",
            "titulo" => "Relógio de hoje",
            "endereco" => "index.php; index.php?r=<id> abre o relógio no painel; index.php?novo=1 abre o cadastro de um novo; index.php#manual "
                . "abre o quadro Modo de rodízio no Rodízio manual",
            "le" => [
                "recurso=hoje (com proximos_pagina)",
                "recurso=proximos (a troca de página dos Próximos dias)",
                "recurso=ficha (o painel)",
                "recurso=rodizio_manual (o Rodízio manual, de 7 em 7 dias)",
            ],
            "grava" => [
                "lancamento: iniciar, encerrar, lancar, periodo, alterar, excluir",
                "rodizio: trocar_dia, modo, resortear, resortear_hoje, proxima_semana, usando, sem_relogio, manual, periodo_sortear, "
                    . "periodo_livre, apagar_tudo",
                "relogio: salvar, foto, remover_foto, excluir",
            ],
            "partes" => [
                ["titulo" => "O relógio do dia (um cartão grande, no alto do meio)", "itens" => [
                    "O relógio do cartão é o de destaque.relogio_id da API (o que tem relogios[].destaque): o relógio do dia, ou, "
                        . "com a Configuração \"Destaque no alto da página Hoje\" em \"O relógio no pulso agora\" (destaque_hoje = pulso), o "
                        . "que está no pulso agora. Quando ele não é o do dia (destaque.e = pulso): o nome dele em h1 (link que abre o "
                        . "painel), a janela de data com \"no pulso agora\" e o tipo dele, e em âmbar \"Relógio do dia: <nome, link que abre "
                        . "o painel> (<até>)\" (ou \"Hoje não tem relógio do dia.\"), sem o motivo. Com o destaque no pulso e ninguém no "
                        . "pulso, o cartão do dia como sempre e, embaixo do motivo, o destaque.texto em cinza (\"Nenhum relógio no pulso "
                        . "agora: aqui, o relógio do dia.\"). Os pares, o botão Pôs/Tirou do pulso e a foto são do relógio do cartão.",
                    "À esquerda: \"<Dia da semana>, DD/MM/AAAA · <nome do modo>\" em cinza; o nome do relógio do dia em h1 (link que "
                        . "abre o painel); a janela de data com o \"até\" (\"só hoje\", \"até sexta, 02/10\") e o tipo dele; o lembrete do dia "
                        . "em âmbar (\"Pôr no winder: <relógio> (8 h)\"); \"Por que ele: <motivo>\" (o motivo em cinza); embaixo, o botão leve discreto "
                        . "\"Sem relógio hoje\" (rodizio sem_relogio; confirma \"Hoje sem relógio? O dia fica livre: ...\").",
                    "No meio, em pares: Agora (a etiqueta \"Em uso desde 07:00\", \"No sol desde 09:12\" ou \"Em repouso desde ...\", na "
                        . "cor do estado, e o botão leve \"Pôs no pulso\" / \"Tirou do pulso\", que manda lancamento iniciar/encerrar do tipo "
                        . "pulso); Carga (a barra, o % e de onde vem, ou só o texto); Última leitura (\"89% em 06/10 21:30\", se houver); "
                        . "Situação (as linhas da API); Próxima manutenção (\"<nome> · <data ou hoje> (<falta>)\", a falta em vermelho se "
                        . "atrasada, ou \"nenhuma prevista\"); Compra (\"R\$ valor · loja · data\"); Garantia (\"até DD/MM/AAAA\", \"(vencida)\" "
                        . "se passou); Código.",
                    "À direita: a foto do relógio (ou o quadrado \"Sem foto\"), que abre o painel.",
                    "Hoje livre (hoje_livre da API: o Sem relógio hoje, ou um período livre): h1 \"Hoje sem relógio\", a janela \"dia livre\", "
                        . "\"Nenhum relógio vai ao pulso pelo rodízio hoje. Quer usar um? ...\" e o \"escolher…\" dos dias livres (trocar_dia de "
                        . "hoje: o escolhido vai para o pulso a partir de agora); sem os pares nem a foto (a não ser com o destaque no pulso).",
                    "Sem relógio para hoje: h1 \"Nenhum relógio para hoje\" e \"Nenhum relógio disponível no grupo deste dia. Mude o "
                        . "grupo no modo de rodízio ou marque um relógio como disponível.\"",
                ]],
                ["titulo" => "Hoje é dia de (só quando há aviso)", "itens" => [
                    "Tabela com as colunas \"O que fazer\" (o nome do aviso; atrasado em vermelho), \"Relógio\" (link que abre o "
                        . "painel), \"Por quê\" (o texto do aviso) e, à direita, o botão do lançamento que resolve, com o texto e a ação "
                        . "que a API dá (resolve.botao e resolve.acao): sessão fechada: \"Pôs <nome em minúsculas>\" (lancamento iniciar); "
                        . "sessão aberta: \"Tirou do carregador\" (lancamento encerrar); com valor: um campo curto com a unidade de dica "
                        . "(\"%\") e \"Informar <nome>\" (lancamento lancar com valor); instantâneo: o nome do tipo (lancamento lancar).",
                ]],
                ["titulo" => "Próximos dias", "itens" => [
                    "Título \"Próximos dias\" com o link \"ver o plano inteiro →\" (plano.php). Tabela: Dia (\"DD/MM\" e o dia da semana "
                        . "curto, menor), Relógio (o nome; \"à mão\" pequeno quando escolhido à mão; o motivo embaixo, menor), Lembrete, e "
                        . "o \"trocar por…\". Fim de semana com fundo #f4f6f8. Sem plano: \"Neste modo o sorteio é feito dia a dia.\" Os dias "
                        . "livres entram no meio, como na página Plano (\"livre: escolha à mão\", \"escolher…\"). Os dias vêm da API "
                        . "(hoje.proximos), de \"Mostrar até\" em \"Mostrar até\" (a Configuração, padrão 7); passando disso, embaixo o "
                        . "navegador de páginas (recurso=proximos com pagina; só a lista se remonta) e \"Página N de M · <total> dias no "
                        . "plano, <n> por página (o \"Mostrar até\" da Configuração).\" (link para configuracao.php). A página à mostra fica "
                        . "a mesma quando a página Hoje se remonta depois de uma gravação.",
                    "\"trocar por…\": uma lista com os relógios disponíveis (menos o do dia) e, num dia de amanhã em diante escolhido "
                        . "à mão, \"↺ voltar a sortear\" (valor 0); escolhido um, aparece o botão leve \"Trocar\" (rodizio trocar_dia com "
                        . "data e relogio_id).",
                ]],
                ["titulo" => "Modo de rodízio (um quadro que abre e fecha)", "itens" => [
                    "Fechado: \"Modo de rodízio\" (cinza) e o nome do modo em negrito, \"· <forma de escolha>\" (na escala: \"pela maior "
                        . "nota\") e, à direita, o link \"editar\".",
                    "Aberto, em duas colunas: à esquerda a lista \"Modo de rodízio\" (os modos), a explicação do modo escolhido "
                        . "(escala: \"Monta o plano do período inteiro de uma vez...\"; sorteio: \"Cada bloco de dias sorteia entre os "
                        . "relógios do grupo escolhido...\") e os campos do modo escolhido: na escala, \"Período\" (Uma semana, Um mês, Dois "
                        . "meses, Meio ano, Um ano, Dois anos = 7, 30, 60, 180, 365, 730); para cada bloco, uma lista com o nome do "
                        . "bloco (grupos \"Sortear entre\": Todos e cada grupo com recuo pela profundidade; \"Relógio fixo\": cada relógio "
                        . "disponível) e, fora da escala, a caixa \"sortear um por dia\".",
                    "À direita: \"Como escolher o relógio\" (Inteligente; Inteligente com sorteio; Totalmente aleatório; Fila (FIFO)) "
                        . "com a explicação de cada uma (na escala, \"Como escolher o relógio: pela maior nota\"); \"Garantia de rodízio: "
                        . "dias sem uso, no máximo\" (número de 0 a 365; só nas formas inteligente, com sorteio e na escala) com \"Quem "
                        . "passa do limite tem prioridade, o mais tempo parado primeiro. 0 desliga. O mesmo valor vale para todos os "
                        . "modos que usam a nota.\" e \"A nota vem dos critérios, que você cadastra.\"",
                    "Botão \"Aplicar modo\" (rodizio modo), com a confirmação \"Trocar o modo apaga o plano atual. Continuar?\" (com o "
                        . "dia já começado: \"Trocar o modo refaz o plano a partir de amanhã; hoje continua o <relógio>. Continuar?\") e a "
                        . "nota sobre o histórico que continua e o link para Cadastros (os modos).",
                    "\"Sortear de novo\": uma lista com as ações possíveis agora e o botão leve \"Sortear\": \"a partir de amanhã\" "
                        . "(resortear) e \"inclusive hoje\" (resortear_hoje) com o dia já começado no pulso, ou \"a partir de hoje\" "
                        . "(resortear); fora da escala, \"a próxima semana (DD/MM a DD/MM)\" (proxima_semana; só no domingo: nos outros "
                        . "dias a opção vem desativada com \"— disponível no domingo\"). Embaixo, a explicação da opção escolhida; cada uma "
                        . "tem a sua confirmação.",
                    "Rodízio manual: a última opção da lista \"Modo de rodízio\" (não é um modo: o modo de sempre continua valendo "
                        . "fora do período). Escolhida, a explicação diz \"Você escolhe o relógio de cada dia de um período. Ponha o "
                        . "começo e o fim e clique em OK: os dias aparecem de 7 em 7, cada um com a lista dos relógios, \"Decida por mim\" "
                        . "(o sistema escolhe) e \"Sem relógio (dia livre)\". Fora do período, continua valendo o modo de rodízio de sempre "
                        . "(<modo>).\"; somem a coluna da direita, o \"Aplicar modo\" com a nota e o \"Sortear de novo\"; aparece, abaixo de "
                        . "uma linha, o formulário do período: \"De\" (data, de hoje em diante, já com hoje), \"Até\" (data) e o botão \"OK\". "
                        . "O endereço index.php#manual abre o quadro já no Rodízio manual (o link da página Plano).",
                    "OK lê recurso=rodizio_manual (de, ate, pagina) e mostra a tabela compacta (até 46rem): Dia (\"DD/MM\" e o dia da "
                        . "semana por extenso, menor, com \" · hoje\"; o número em safira no fim de semana), No plano agora (o relógio com "
                        . "\"à mão\" ou \"sorteado\" pequeno em âmbar, \"dia livre\" ou \"—\" em cinza) e Relógio do dia (uma lista: \"Decida por "
                        . "mim\", o grupo \"Relógios\" com os disponíveis por nome e, menos hoje, \"Sem relógio (dia livre)\"; de partida, a "
                        . "escolha que a API manda). Mais de 7 dias: embaixo, os botões leves \"‹ Anterior\", os números das páginas (a 1, "
                        . "a última e até 2 de cada lado da atual, com \"…\" nos saltos; a atual em safira) e \"Próxima ›\"; cada um relê a "
                        . "API. O que se escolhe fica guardado na tela ao trocar de página. Embaixo, \"Página N de M · <n> dias, de "
                        . "DD/MM/AAAA a DD/MM/AAAA. O que você escolhe fica guardado enquanto você anda pelas páginas; nada muda antes de "
                        . "gravar.\", os botões \"Gravar o rodízio manual\" (rodizio manual com de, ate e dias[data] dos que você mudou; "
                        . "confirma \"Gravar o rodízio manual de DD/MM/AAAA a DD/MM/AAAA? Os dias ficam com os relógios escolhidos; os de "
                        . "\"Decida por mim\" são decididos agora pelo sistema.\"); e as notas com o critério do Decida por mim (o texto da "
                        . "API) e \"Para apagar o que um período tem (e sortear de novo, ou deixar os dias livres): \"Apagar um período\", "
                        . "embaixo deste quadro, ou na página Plano.\" (link). Recusado: o motivo em âmbar acima da tabela, e as escolhas "
                        . "ficam; gravado: o recado no alto da página e a página remontada.",
                    "Abaixo do Sortear de novo, cada um abaixo de uma linha, os mesmos dois formulários da página Plano: \"Apagar um "
                        . "período\" e \"Apagar tudo\" (veja a página Plano).",
                ]],
                ["titulo" => "Relógios (a tabela da coleção)", "itens" => [
                    "Título \"Relógios\" com \"<mostrados> de <total> relógios · <n> em uso\" em cinza e, à direita, os botões \"Limpar "
                        . "filtros\" (leve) e \"Novo relógio\" (abre o cadastro no painel).",
                    "11 colunas, todas ordenáveis clicando no título (▲ ou ▼ ao lado; clicar de novo inverte; vazios sempre por "
                        . "último): Código, Relógio (miniatura e nome em negrito), Tipo, Estado (o estado_texto da API em etiqueta, na "
                        . "cor do estado: Em uso verde, a sessão aberta (No sol, No winder, No carregador) laranja, Em repouso vermelho, "
                        . "Indisponível cinza), Carga (barra e %, ou o texto de por que não tem), Última vez usado, Próxima manutenção "
                        . "(data ou \"hoje\"), Em (quanto falta; atrasado em vermelho negrito; atualizado a cada minuto), O que fazer (em "
                        . "âmbar negrito), Comprado em, Valor (à direita, \"R\$ 1.234,56\").",
                    "Uma linha de filtros embaixo dos títulos: Código (\"nº\"), Relógio (\"buscar\" no nome), Tipo (os tipos que "
                        . "existem), Estado (Todos e os estados da API, na ordem: Em uso, cada sessão, Em repouso, Indisponível), Carga "
                        . "(Todas, ≤ 20%, ≤ 50%), Última vez usado (Todos, 7 dias, +7 dias, nunca), Próxima manutenção (Todas, até hoje, "
                        . "7 dias, 30 dias), O que fazer (os avisos que existem), Comprado em (Todas, 30 dias, 90 dias, este ano, sem "
                        . "data), Valor (Todos, ≤ 500, 500–1.500, > 1.500, sem valor). A ordem e os filtros ficam guardados na sessão do "
                        . "navegador.",
                    "Ordem inicial: os disponíveis primeiro (o de hoje, depois os com aviso), os indisponíveis no fim e em cinza. A "
                        . "linha do relógio de hoje tem a faixa safira à esquerda; a de um com aviso, a âmbar. Clicar numa linha (ou "
                        . "Enter nela) abre o relógio no painel. Rodapé: \"Total dos relógios mostrados\" e a soma dos valores, à direita. "
                        . "Sem relógios: \"Nenhum relógio cadastrado. Use o botão Novo relógio.\"; com filtros que não deixam nenhum: "
                        . "\"Nenhum relógio com esses filtros.\"",
                    "Quando as 11 colunas não cabem na largura que a tabela tem: cada relógio vira um cartão, com a foto e o nome "
                        . "na linha de cima e embaixo os mesmos campos com o nome da coluna em cima, em colunas iguais (5; 2 no celular); "
                        . "os títulos viram botões \"Ordenar por\" e os filtros ficam todos, com o nome em cima.",
                ]],
                ["titulo" => "O painel do relógio (à direita, flutuante ou em tela cheia)", "itens" => [
                    "Um botão redondo \"×\" no canto (no celular \"× Fechar\") fecha o painel; Esc também. O endereço vira "
                        . "index.php?r=<id>.",
                    "Cabeça: a foto (ou \"Sem foto\"; com o mouse parado em cima, a dica relogio.dica_foto da API, em linhas: o nome, "
                        . "o grupo e o código; o estado e a carga; a nota e o próximo dia no rodízio; a compra; e \"Duplo clique (ou "
                        . "\"Abrir foto\", embaixo): a foto em tamanho grande.\") com os botões leves \"Abrir foto\" (só com foto: abre a foto "
                        . "ampliada), \"Escolher foto\" / \"Trocar foto\" (abre o seletor de arquivo; escolhida, aparece a prévia e o botão "
                        . "\"Salvar foto\": relogio foto) e, com foto, \"Remover foto\" (discreto; confirma \"Remover a foto?\"); ao lado "
                        . "\"<tipo> · <caminho no grupo>\" em cinza, o nome em h1, a observação, e as etiquetas: em uso/em repouso, "
                        . "\"indisponível\", \"relógio de hoje\" e, fora da coleção, a situação (etiqueta lilás: \"desejado\", "
                        . "\"emprestado\", \"vendido\", \"arquivado\"; no desejado, sem a etiqueta do estado).",
                    "Num relógio desejado, no lugar dos quadros abaixo (Agora, Previsão, Gasto, Autonomia, Rodízio, Compra, "
                        . "Manutenções, Marcar), só o quadro Desejo (pares: Marca, Referência, Preço-alvo \"R\$ ...\", Prioridade, Link \"abrir "
                        . "a página\" numa aba nova; vazio: \"—\"; no rodapé \"Comprou? Em \"Editar cadastro\", mude a situação para \"Na "
                        . "coleção\" e preencha a compra.\") e o de Documentos; os quadros largos (Dados, Histórico) e o Editar cadastro "
                        . "continuam.",
                    "Os quadros, numa grade (lado a lado onde cabem): Agora (pares: Estado (\"No carregador desde 06:30\", na cor do "
                        . "estado); Carga com a barra e \"~68%\" e de onde vem; Situação, uma linha por item; Última leitura); Previsão (só "
                        . "nos que têm leitura: as frases da API, ou \"Informe a carga atual para o sistema começar a prever.\"; embaixo, "
                        . "\"De onde vem a conta\" (título pequeno em maiúsculas cinza) e a tabela da previsao.conta, uma linha por número: "
                        . "o nome (e, menor, embaixo, o detalhe de como o gasto foi medido), o valor (com a unidade menor embaixo; \"—\" "
                        . "se vazio, a linha em cinza) e a origem em etiqueta: informado azul, padrão âmbar, medido verde, vazio cinza); "
                        . "Gasto da bateria (No pulso, Fora do pulso: o que vale \"% por dia\" e embaixo se é medido ou do cadastro; Janela "
                        . "anterior; Medições; no rodapé \"Medições mais velhas que a janela saem da conta...\"); Autonomia (Cheio, pelo "
                        . "cadastro; Cheio, pela conta; Acaba, seguindo o plano (data e hora, e \"em <duração>\"); No pulso sem tirar; "
                        . "Guardado; sem dado, o motivo em cinza); Rodízio (Nota com o link \"ver a conta\" (Critérios, numa aba nova); "
                        . "Critérios de; Próxima vez; no rodapé o botão leve \"Usando hoje\" (rodizio usando, com confirmação), se ele está "
                        . "disponível e não é o de hoje); Compra (Valor pago, Loja, Comprado em com \"há N dias\", Garantia \"até\" ou "
                        . "\"vencida em\"; sem nada: \"Sem dados da compra. Preencha em \"Editar cadastro\".\"); Compra também com \"Custo por "
                        . "uso\" (\"R\$ 100,00\" em negrito e \"3 dias de uso\" embaixo; sem: \"sem o valor pago\" ou \"ainda não foi ao pulso\" em "
                        . "cinza) e o \"há N dias\" da compra vindo da API (compra.ha_dias). Documentos (cada categoria com \"N arquivos\", "
                        . "link para a página Documentos numa aba nova; o botão \"Enviar documentos\" ou \"Abrir os documentos\"); Próximas "
                        . "manutenções (data em negrito e o nome; ou \"Nada previsto.\").",
                    "Marcar (um quadro na largura toda): \"O que você quer marcar?\" com uma lista em grupos: \"Agora, ou numa hora "
                        . "que você disser\" (cada sessão como verbo: \"Pôr no sol\" / \"Tirar do sol\" conforme está aberta; cada tipo com "
                        . "valor pelo nome; cada instantâneo pelo nome), \"Algo que já passou\" (\"Um período que já passou (<sessões>)\") e "
                        . "\"Corrigir\" (\"Corrigir ou excluir uma marcação (últimos 14 dias)\"). Escolhido um, aparecem só os campos dele: "
                        . "sessão: o estado aberto e \"Pôs às\" / \"Tirou às\" (data e hora, \"Anda com o relógio até você mudar (vazio: "
                        . "agora).\") e o botão com o verbo; com valor: \"Agora em uso/em repouso\" e a estimativa, \"Leitura\" (com a "
                        . "unidade), \"Lida às\", a caixa \"Atualizar o gasto com esta medição\" (marcada; só nos tipos que medem o gasto) e "
                        . "\"Informar <nome>\"; instantâneo: \"Quando\" e \"Marcar <nome>\"; período: \"Onde\" (se houver mais de uma sessão), "
                        . "\"De\" (uma hora atrás) e \"Até\" (agora) e \"Registrar o período\"; corrigir: \"Qual marcação\" (\"DD/MM HH:MM · nome "
                        . "até HH:MM\", \"(aberta)\", \": 68 %\", \"(rodízio)\") e os campos dela com \"Salvar\" e \"Excluir\" (confirma). Nada pode "
                        . "ser no futuro. Os campos de data e hora andam com o relógio do navegador: \"Pôs às\", \"Tirou às\", \"Lida às\", "
                        . "\"Quando\" e o \"Até\" do período vêm com a hora de agora e acompanham o relógio (a cada 15 s e ao clicar no "
                        . "campo) até a pessoa mexer, e daí ficam no que ela pôs; sem mexer, \"Pôs às\", \"Tirou às\", \"Lida às\" e \"Quando\" "
                        . "vão vazios (a API usa o agora dela, com os segundos) e o \"Até\" vai com o minuto de agora; o máximo de todos "
                        . "(nada no futuro) também anda.",
                    "Editar cadastro (um quadro que abre e fecha; no relógio novo, já aberto com o título \"Novo relógio\"): Nome "
                        . "(obrigatório); Grupo (\"(na raiz)\" e cada grupo pelo caminho, com a nota \"os campos, os lançamentos e os "
                        . "critérios de um grupo valem para tudo abaixo dele\"); depois os campos do cadastro, só os que valem para o "
                        . "grupo escolhido (mudar o grupo mostra e esconde na hora): sim/não como caixa; lista como menu (com \"—\"); data "
                        . "como campo de data; texto como campo; número como campo decimal, com o padrão de dica (\"vazio: 5\"); a Compra "
                        . "num grupo com título (Data da compra, Valor pago, Onde comprou, Garantia até); a Observação; no novo, a Foto; "
                        . "\"Situação na coleção\" (menu: Na coleção, Desejado, Emprestado, Vendido, Arquivado; no novo, a do endereço, "
                        . "index.php?novo=1&posse=desejado, senão Na coleção; os campos só nos desejados, como o Preço-alvo e a Prioridade, "
                        . "aparecem só com Desejado, e a caixa do disponível só com Na coleção, na hora); "
                        . "\"Disponível para o rodízio\" (marcada no novo); \"Guardar no banco a cópia de todos os documentos dele\" (só "
                        . "quando o config.php não decide); botão \"Cadastrar relógio\" ou \"Salvar alterações\" (relogio salvar). Embaixo, "
                        . "\"Excluir este relógio\" (discreto; confirma \"Excluir <nome> com todo o histórico e a foto?\").",
                    "Carga ao longo do tempo (largo; nos que têm leitura): um gráfico de linha das leituras (eixo de 0 a 100%, "
                        . "guias em 0, 50 e 100%, pontos verdes em uso e vermelhos em repouso, a linha em safira), com a legenda em pares "
                        . "rótulo/valor, menores (Pontos: as bolinhas \"leitura em uso\" e \"leitura em repouso\"; Período: \"DD/MM a DD/MM\"; "
                        . "Escala: \"de 0 a 100%\"; A conta: \"de onde vem cada número: no quadro Previsão\"); com menos de duas leituras, "
                        . "\"O gráfico aparece a partir de duas leituras de carga.\" Embaixo, \"Medições do gasto\" (no quadro estreito, até "
                        . "40rem, como no painel ao lado: cada medição vira um cartão, a data em negrito e embaixo cada valor com o nome "
                        . "da coluna em maiúsculas cinza na frente): tabela Quando, Leituras (\"89 → 75\"), Horas (\"14 h no pulso, 2 h "
                        . "fora\"), Gasto medido (\"% por dia de uso\" ou \"fora do pulso\"), Na conta (sim; não (fora da janela); não (só "
                        . "histórico)), as 10 mais recentes.",
                    "Dados do relógio (largo): duas tabelas lado a lado (o valor numérico, a data e o sim/não numa linha só; o "
                        . "valor de texto, como a observação ou uma fórmula que dá texto, quebra a linha dentro da coluna, sem invadir a "
                        . "tabela do lado): Cadastro (Campo, Valor, Origem com a etiqueta informado/padrão/vazio) e Calculado agora "
                        . "(Fórmula com o identificador em código, Valor com a unidade (segundos por extenso), Versão).",
                    "Histórico (largo): \"<n> registros desde DD/MM/AAAA HH:MM. Agora <estado> há <duração>.\" e a tabela das 5 "
                        . "linhas mais recentes (Quando com \"até ...\" embaixo, Duração, Estado em etiqueta ou o texto da marcação) e o "
                        . "botão leve \"Abrir o histórico completo\" (historico.php numa aba nova).",
                    "Depois de gravar no painel, o recado aparece no topo do painel e a página e o painel se remontam sem perder os "
                        . "quadros abertos nem a rolagem; excluir o relógio recarrega a página.",
                ]],
            ],
        ],
        [
            "id" => "ficha",
            "pagina" => "ficha.php",
            "titulo" => "Ficha do relógio",
            "endereco" => "ficha.php?id=<id> (sem id: o primeiro)",
            "le" => [
                "recurso=hoje (a lista)",
                "recurso=ficha",
            ],
            "grava" => [
                "as mesmas do painel do relógio",
            ],
            "partes" => [
                ["titulo" => "Escolha e painel", "itens" => [
                    "No alto, \"Relógio\" e uma lista com todos (os disponíveis primeiro, o estado entre parênteses nos outros: "
                        . "\"(indisponível)\", \"(emprestado)\"); havendo relógios fora da coleção, a lista vem em dois grupos, \"Na coleção\" e "
                        . "\"Fora da coleção\" (cada um com a situação: \"(desejado)\", \"(vendido)\", \"(arquivado)\"); trocar abre o "
                        . "escolhido. Embaixo, o mesmo painel do relógio da página Hoje, numa página própria de até 72rem, com os "
                        . "quadros lado a lado e sem o ×. Sem relógios: \"Nenhum relógio cadastrado. Cadastre o primeiro.\" (link para "
                        . "index.php?novo=1).",
                ]],
            ],
        ],
        [
            "id" => "desejos",
            "pagina" => "desejos.php",
            "titulo" => "Desejos",
            "endereco" => "desejos.php",
            "le" => [
                "recurso=desejos",
            ],
            "grava" => [
                "relogio: salvar (id e posse=colecao: o Comprei e o Voltar para a coleção)",
            ],
            "partes" => [
                ["titulo" => "Lista de desejos", "itens" => [
                    "h1 \"Lista de desejos\" com o botão \"Novo desejo\" à direita (index.php?novo=1&posse=desejado: o cadastro de um "
                        . "relógio novo já como Desejado). A nota: sem desejados, como um relógio entra na lista (\"Novo desejo\" ou a "
                        . "\"Situação na coleção\" do cadastro) e que ele não entra no rodízio nem nos avisos; com desejados, \"<n> relógios "
                        . "desejados, a prioridade mais alta primeiro. Somando os preços-alvo: R\$ <total> (<n> sem preço-alvo). Comprou? ...\".",
                    "Tabela (vira fichas até 900px) Relógio (a miniatura, o nome com o link da ficha e, embaixo, menor, \"marca · "
                        . "referência\"), Prioridade, Preço-alvo (\"R\$ 1.234,56\" ou —), Grupo (o caminho; — na raiz), Link (\"abrir a "
                        . "página\" numa aba nova, só para http:// e https://; outro texto, como está; —) e o botão leve \"Comprei\" "
                        . "(confirma \"Comprou o <nome>? Ele entra na coleção, disponível para o rodízio, e a ficha dele abre para você "
                        . "preencher a compra.\"; gravado, abre ficha.php?id=<id>).",
                ]],
                ["titulo" => "Fora da coleção", "itens" => [
                    "h2 \"Fora da coleção\", a nota (fora do rodízio, do plano e dos avisos; o emprestado continua na tabela da página "
                        . "Hoje e no valor da coleção; a ficha, os documentos e o histórico ficam) e a tabela Relógio (miniatura e nome), "
                        . "Situação (etiqueta lilás: emprestado, vendido, arquivado), Grupo e o botão leve \"Voltar para a coleção\" "
                        . "(confirma; gravado, abre a ficha).",
                    "Na página Hoje, ao lado do \"Novo relógio\", o botão leve \"Desejos (<n>) · Fora da coleção (<n>)\" (só as partes "
                        . "que têm algum; sem nenhum, o botão some) leva a esta página.",
                ]],
            ],
        ],
        [
            "id" => "estatisticas",
            "pagina" => "estatisticas.php",
            "titulo" => "Estatísticas",
            "endereco" => "estatisticas.php?de=AAAA-MM-DD&ate=AAAA-MM-DD (de=tudo: desde o primeiro uso; sem nada: os últimos 90 dias)",
            "le" => [
                "recurso=estatisticas",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => "Período e resumo", "itens" => [
                    "h1 \"Estatísticas\" com \"de DD/MM/AAAA a DD/MM/AAAA · N dias\" em cinza à direita. Filtro num cartão: De, Até (máximo "
                        . "hoje), \"Ver\" e os atalhos 30 dias, 90 dias, 1 ano, desde o começo.",
                    "Quatro caixas (duas por linha até 900px): \"<n>\" dias com relógio no pulso (de <dias>); \"<h> h\" no pulso · <h> h por dia "
                        . "com relógio; \"<usados> de <coleção>\" relógios usados · <n> sem uso; \"R\$ <custo médio>\" custo por uso, em média · "
                        . "coleção: R\$ <valor>. Embaixo, \"Mais usado: <link> (<h> h, <n> dias). Menos usado (dos que foram ao pulso): <link> (<h> h).\"",
                ]],
                ["titulo" => "Cada relógio", "itens" => [
                    "Cartão com a nota do que cada coluna é e a tabela (fichas até 900px) Relógio (o link da ficha e, embaixo, menor, o grupo e a "
                        . "situação fora da coleção), Dias de uso, Horas (\"12,5 h\"), A parte (a barra horizontal safira com a largura relogios[].barra "
                        . "e \"56,8%\"), Custo por uso (\"R\$ 83,30\" ou —), Sem uso há (\"3 dias\", \"usado hoje\", \"nunca usado\"), Tendência (\"↑ "
                        . "usando mais\" em verde, \"↓ usando menos\" em vermelho, \"→ estável\", \"· poucos dados\" em cinza; a dica com as horas de "
                        . "cada metade).",
                ]],
                ["titulo" => "Gráficos", "itens" => [
                    "Dois cartões lado a lado (um embaixo do outro até 900px): Por dia da semana e Por mês, cada um um gráfico de barras "
                        . "verticais em SVG (largura 100%, as barras safira com a altura da barra da API, o valor \"14 h\" em cima de cada uma "
                        . "quando são até 8, os rótulos embaixo: seg...dom, set/26..., no máximo uns 7 rótulos) e a nota com os números (a média "
                        . "por dia de cada dia da semana; as horas e quantos relógios em cada mês).",
                ]],
                ["titulo" => "A coleção", "itens" => [
                    "Cartão com a nota e as divisões em grade (lado a lado onde cabem, cada uma com pelo menos 22rem): h3 com o título e a "
                        . "tabela nome, \"<n> relógios\", a barra com a porcentagem e as horas; numa divisão estreita (até 26rem), cada linha em "
                        . "duas: o nome em negrito em cima e os números embaixo.",
                ]],
            ],
        ],
        [
            "id" => "plano",
            "pagina" => "plano.php",
            "titulo" => "Plano",
            "endereco" => "plano.php",
            "le" => [
                "recurso=plano (com dias=30, 90, 365, 730 ou todos; pagina e relogio)",
            ],
            "grava" => [
                "rodizio: trocar_dia, periodo_sortear, periodo_livre, apagar_tudo",
            ],
            "partes" => [
                ["titulo" => "Cabeça e filtros", "itens" => [
                    "h1 \"Plano\" e a nota \"Modo: <modo> · a escala vai até DD/MM/AAAA · o plano gravado vai até DD/MM/AAAA. Troque o "
                        . "relógio de qualquer dia em \"trocar por…\": o dia fica escolhido à mão (e a escala é refeita a partir dele).\" "
                        . "Filtros lado a lado: Período (Próximos 30 dias, Próximos 90 dias (o padrão), Próximo ano, Próximos 2 anos, "
                        . "Tudo; trocar relê a API) e Relógio (Todos e cada um; filtra o dia a dia na tela).",
                ]],
                ["titulo" => "O período à mão", "itens" => [
                    "Uma nota: \"Para escolher os relógios de um período, dia a dia: na página Hoje, no quadro Modo de rodízio, "
                        . "escolha \"Rodízio manual\". Para apagar a programação, veja o fim desta página.\" (o link Hoje vai para "
                        . "index.php#manual).",
                ]],
                ["titulo" => "Resumo do período (<n> dias)", "itens" => [
                    "Tabela Relógio, Dias, %, Próximo dia (\"DD/MM/AAAA seg\"), À mão; do que tem mais dias ao que tem menos. "
                        . "Embaixo, \"Sem nenhum dia no período: <relógios>.\" Sem dias: \"Nenhum dia no plano neste período.\"",
                ]],
                ["titulo" => "Dia a dia", "itens" => [
                    "Os dias livres (livres, da API) entram entre os do plano, na ordem das datas: no lugar do relógio, \"livre: "
                        . "escolha à mão\" em cinza com a marca \"livre\", e a lista do dia começa em \"escolher…\" (com \"↺ voltar a "
                        . "sortear\"). Um título por mês (\"Outubro de 2026\") e uma tabela de dias: o dia (\"DD/MM\" e o dia da semana, "
                        . "\"hoje\" no de hoje, com a faixa safira), o relógio (com \"à mão\" e o motivo embaixo), o lembrete e o \"trocar "
                        . "por…\" (como na página Hoje). Fim de semana com fundo cinza claro. Sem dias: \"Neste modo o sorteio é feito "
                        . "semana a semana: o plano tem só a semana atual.\" ou \"Nenhum dia.\" Os dias vêm da API (plano.dia_a_dia, com "
                        . "pagina e relogio: o filtro de relógio relê a API), de \"Mostrar até\" em \"Mostrar até\" (a Configuração, padrão "
                        . "31); passando disso, o navegador de páginas embaixo (cada página relê a API e rola até o dia a dia) e \"Página "
                        . "N de M · <total> dias, <n> por página (o \"Mostrar até\" da Configuração).\"",
                ]],
                ["titulo" => "Rodapé: Apagar a programação (um cartão no fim da página, depois do dia a dia, separado por uma margem grande e "
                    . "uma borda de 3px aço escuro em cima)", "itens" => [
                    "Título h2 \"Apagar a programação\". \"Apagar um período\" (o rótulo em cinza): numa linha que quebra, \"De\" e \"Até\" "
                        . "(datas, de hoje em diante, obrigatórias), \"Depois\" (lista: \"e sortear de novo\" = rodizio periodo_sortear; \"e "
                        . "deixar livre\" = periodo_livre) e o botão leve \"Apagar\". Embaixo, a explicação da opção escolhida (\"Tudo o que "
                        . "o período tem, de amanhã em diante (escolhido à mão, sorteado ou livre), sai, e o modo de rodízio sorteia os "
                        . "dias outra vez.\" / \"... sai, e os dias ficam livres: sem relógio e sem sorteio, para você escolher cada um "
                        . "...\"). Confirma \"Apagar o que o período tem e sortear de novo? Os dias escolhidos à mão nele também saem.\" (ou "
                        . "\"... e deixar os dias livres, sem relógio? ...\").",
                    "Abaixo de uma linha, \"Apagar tudo\": a caixa \"Excluindo hoje (apaga também o dia de hoje)\" e o botão leve "
                        . "\"Apagar tudo\" (rodizio apagar_tudo, com hoje=1 marcada), e a nota \"Limpa toda a programação, de amanhã em "
                        . "diante: os dias escolhidos à mão, os sorteados e os livres. Marcando \"Excluindo hoje\", apaga de hoje em diante "
                        . "(se sair outro relógio para hoje, ele passa a ser o do pulso a partir de agora). Depois, o modo de rodízio "
                        . "monta o plano de novo, do zero.\" Confirma (com hoje: \"Apagar toda a programação, de hoje em diante (escolhidos "
                        . "à mão, sorteados e dias livres)? O modo de rodízio monta o plano de novo, e hoje pode sair outro relógio, que "
                        . "passa a ser o do pulso a partir de agora.\"; sem: \"... de amanhã em diante ...; hoje continua como está.\").",
                ]],
            ],
        ],
        [
            "id" => "configuracao",
            "pagina" => "configuracao.php",
            "titulo" => "Configuração",
            "endereco" => "configuracao.php; ?evento=<id> abre um evento para editar; #personalizados",
            "le" => [
                "recurso=migracoes (antes de tudo)",
                "recurso=config",
                "recurso=backup",
            ],
            "grava" => [
                "config: salvar, evento_salvar, evento_excluir, testar_manha, testar_noite, teste_agenda_criar, "
                    . "teste_agenda_remover, sincronizar",
                "migracoes: aplicar",
                "backup: fazer, restaurar",
            ],
            "partes" => [
                ["titulo" => "Banco desatualizado (só quando há migração pendente)", "itens" => [
                    "Um cartão só: \"O banco está desatualizado\", a explicação (\"Esta versão do sistema precisa de migrações que "
                        . "ainda não foram aplicadas... as páginas, a API e o cron ficam parados...\"), a tabela das pendentes (versão em "
                        . "negrito, o que traz, o arquivo em código), o botão \"Aplicar agora\" (migracoes aplicar) e a nota de como rodar "
                        . "na mão. Depois de aplicar: \"Atualização do banco\", o resultado e \"Continuar para a Configuração\".",
                ]],
                ["titulo" => "Os quadros do alto (uma grade de 3 colunas; um formulário só, salvo pela barra do pé)", "itens" => [
                    "Cada opção numa linha: o rótulo à esquerda e o campo à direita, alinhados na mesma coluna em todos os quadros; "
                        . "caixas de marcar no lugar do campo; textos longos com o rótulo em cima e o campo na largura toda. Cada quadro "
                        . "com a explicação embaixo.",
                    "Rotina do dia: Manhã: relógio do dia e avisos (hora); Noite: preparar o de amanhã (hora); No pulso a partir de "
                        . "(hora); Pôr no pulso sozinho (caixa); No pulso até (hora); Tirar do pulso sozinho (caixa); Sessão no sol "
                        . "esquecida fecha às (hora).",
                    "Limites e contas: Carregar quando a carga chegar a (%) (1 a 99, padrão 20); Solar: pôr no sol quando chegar a "
                        . "(%) (1 a 99, padrão 70); Gasto medido: média dos últimos (dias) (1 a 3650, padrão 90); Aviso Encostado: dias sem ir ao "
                        . "pulso (1 a 3650, padrão 30; encostado_dias).",
                    "Telas: Abrir o relógio na página Hoje (Ao lado da lista; Numa janela flutuante, grande); Destaque no alto da "
                        . "página Hoje (O relógio do dia; O relógio no pulso agora: destaque_hoje dia ou pulso, com a nota \"Destaque: "
                        . "...\"); \"Próximos dias (página Hoje): mostrar até (dias por página)\" e \"Plano: mostrar até (dias por página)\" "
                        . "(números de 1 a 366, com o valor de mostrar_ate da API: padrão 7 e 31); Endereço do sistema (a âncora {link}); "
                        . "e a nota sobre o Mostrar até (passando disso, as páginas embaixo: <, os números, >).",
                    "Cron: \"Cron rodando: última execução às HH:MM.\" (nota) ou, em âmbar, \"O cron ainda não rodou\" / \"O cron não "
                        . "roda desde ...\" com a linha do crontab em código; o erro guardado numa caixa (\"O cron está com erro\"); o link "
                        . "\"Ver todas as execuções do cron\" e o \"Registro da última rodada\" (abre e fecha).",
                    "Sorteios (só leitura): \"Os números vêm de\": \"1. RANDOM.ORG (ruído atmosférico) · 2. fora do ar, ANU QRNG "
                        . "(quântico) · 3. sem os dois, o gerador seguro do PHP\"; \"Último lote\": \"<fonte> em DD/MM/AAAA HH:MM\" "
                        . "(aleatorio.ultimo_lote da API) ou \"nenhum ainda\"; com os dois fora, \"Serviços fora\" em âmbar: \"os dois sem "
                        . "responder desde DD/MM/AAAA HH:MM; o sistema tenta de novo depois de 10 minutos\". A nota: \"Todo sorteio do "
                        . "sistema (o rodízio totalmente aleatório, o sorteio pela nota e o desempate de notas iguais na escala) usa "
                        . "estes números. Eles chegam num lote, e cada sorteio usa só o que precisa, sem viés.\"",
                    "Telegram (API de alerta): Enviar alertas (caixa). Google Agenda: Criar eventos na agenda (caixa), Antecedência "
                        . "dos eventos (dias, 1 a 365), ID da agenda, Caminho da chave JSON no servidor; a chave encontrada "
                        . "(\"Compartilhe a agenda com <e-mail>\") ou não; os botões leves \"Criar evento de teste\" e \"Remover evento de "
                        . "teste\".",
                ]],
                ["titulo" => "Mensagem padrão", "itens" => [
                    "Um cartão por canal (Telegram, Google Agenda), lado a lado: o nome do canal, a lista \"Inserir âncora\" (põe "
                        . "{ancora} onde está o cursor), o texto (7 linhas) e a ajuda do canal. Embaixo, \"O que cada âncora vira\" (abre e "
                        . "fecha: a tabela {ancora} → o que é).",
                ]],
                ["titulo" => "O que vai para onde", "itens" => [
                    "Tabela: Aviso (o nome; nos eventos personalizados, em negrito, com \"editar\" e \"excluir\"), Quando, uma coluna "
                        . "por canal com uma caixa centrada (vai ou não), e Personalizar (uma caixa por canal). Marcar Personalizar abre, "
                        . "na linha de baixo, o texto daquele aviso naquele canal, com \"Inserir âncora\" e a dica \"Vazio usa a mensagem "
                        . "padrão do <canal>\".",
                    "A barra de salvar no pé: \"As mudanças desta página valem depois de salvar.\" e o botão \"Salvar configuração\" "
                        . "(config salvar).",
                ]],
                ["titulo" => "Eventos personalizados", "itens" => [
                    "A explicação e um quadro que abre e fecha (\"Novo evento\" ou \"Editar o evento <nome>\"): Nome (obrigatório), "
                        . "Relógio (opcional: \"Nenhum, é um evento geral\" e os relógios), Quando dispara (uma vez, todo dia, toda semana, "
                        . "todo mês, a cada N dias), e só os campos da repetição escolhida: Data / A partir de, Hora (obrigatória), Dia "
                        . "do mês (1 a 31), A cada quantos dias, Dias da semana (caixas seg a dom); Ativo; as próximas vezes (na edição); "
                        . "botão \"Criar evento\" / \"Salvar alterações\" e \"cancelar\".",
                ]],
                ["titulo" => "Cópia de segurança (no fim da página, depois de Como sai hoje)", "itens" => [
                    "Título \"Cópia de segurança\". Com a pasta: a nota \"Uma cópia do banco inteiro por dia, na rodada da noite, "
                        . "guardando as 7 mais novas, sem (ou com) o conteúdo dos documentos... Última: DD/MM/AAAA HH:MM.\" (ou \"Nenhuma "
                        . "feita ainda.\"); sem a pasta, em âmbar: \"A cópia automática está desligada: <motivo>. Ponha no config.php a "
                        . "BACKUP_PASTA, uma pasta do servidor fora da pasta publicada.\"",
                    "A tabela compacta das cópias (da mais nova para a mais velha): Cópia (o nome do arquivo em fonte fixa), Feita "
                        . "em, Tamanho (\"7 KB\"), Documentos (com/sem) e, à direita, \"Baixar\" (link leve para "
                        . "api.php?recurso=backup&arquivo=<nome>) e \"Restaurar\" (discreto; confirma \"Voltar o banco para a cópia de "
                        . "DD/MM/AAAA HH:MM? Tudo o que foi feito depois some. Antes, o sistema guarda uma cópia do que está agora.\"; "
                        . "backup restaurar). Embaixo, o botão leve \"Fazer uma cópia agora\" (backup fazer).",
                ]],
                ["titulo" => "Como sai hoje", "itens" => [
                    "Três caixas lado a lado: Telegram de manhã e Telegram à noite (o texto como sai, em fonte fixa, ou \"(nada a "
                        . "enviar)\", e o botão leve \"Enviar agora\"); Agenda (os próximos eventos com a data, a hora, o título em negrito "
                        . "e a descrição, quantos o sistema já criou, e \"Sincronizar agora\").",
                ]],
            ],
        ],
        [
            "id" => "criterios",
            "pagina" => "criterios.php",
            "titulo" => "Critérios de escolha",
            "endereco" => "criterios.php?escopo=<\"\", g:<grupo> ou r:<relógio>>; ?relogio=<id> abre a conta dele",
            "le" => [
                "recurso=criterios",
            ],
            "grava" => [
                "criterios: todas as ações",
            ],
            "partes" => [
                ["titulo" => "Explicações", "itens" => [
                    "h1 \"Critérios de escolha do relógio\". Cartão \"Como a nota funciona\" (lugar, conjunto, parâmetro, subparâmetro, "
                        . "faixas, peso efetivo e nota final, em negrito os termos). Cartão \"Como a nota vira escolha\": as quatro formas "
                        . "com a explicação, a tabela Modo de rodízio / Forma de escolha (\"(o modo atual)\") e a garantia de rodízio.",
                ]],
                ["titulo" => "Onde há critérios", "itens" => [
                    "Tabela Lugar, Parâmetros (quantos), Usado por (\"N relógios\" e os nomes em cinza) e o link \"editar\". Em "
                        . "vermelho: \"Há relógios sem conjunto nenhum (nota neutra, 50): ...\"",
                ]],
                ["titulo" => "O conjunto do lugar escolhido", "itens" => [
                    "\"Editar os critérios de\" com a lista de todos os lugares (\"Todos os relógios\", \"Grupo: <caminho>\", \"Relógio: "
                        . "<nome>\"; \"— com critérios próprios\" nos que têm) e \"Abrir\". Sem critérios próprios: \"<Lugar> não tem critérios "
                        . "próprios: usa os de <lugar de cima>.\" e os botões \"Criar critérios próprios, copiando os de <lugar>\" e \"Criar "
                        . "vazio\" (leve).",
                    "Com critérios: \"Critérios de <lugar>\", \"Usado por N relógios: ...\" e a tabela Parâmetro (o nome editável), "
                        . "Peso no conjunto (campo curto e \"%\"), Subparâmetros (link para a seção; \"(fora da conta)\" em vermelho se "
                        . "nenhum), e ↑ ↓ Excluir; no rodapé \"Soma\" com a soma ao vivo e \"fecha 100%\" ou, em vermelho, \"faltam N%\" / "
                        . "\"passa N%\"; botão \"Salvar parâmetros\". Excluir confirma mostrando como o peso se divide entre os que ficam. "
                        . "Linha de inclusão: Novo parâmetro, Peso (%), \"Incluir\". Embaixo, \"Excluir os critérios próprios de <lugar>\" "
                        . "(discreto, confirma).",
                ]],
                ["titulo" => "Cada parâmetro", "itens" => [
                    "Um cartão por parâmetro: \"<nome> <peso> do conjunto de <lugar>\"; a tabela Subparâmetro, Mede, Peso no "
                        . "parâmetro, Peso efetivo no conjunto (atualizado ao digitar), ↑ ↓ Excluir, com a soma no rodapé e \"Salvar "
                        . "subparâmetros\".",
                    "Para cada subparâmetro, \"Faixas de <nome>\" (abre e fecha, com quantas faixas e o que mede): numa medida "
                        . "numérica, a tabela De, Até, Nota, Apagar; numa de categoria, Categoria (lista), Nota, Apagar; sempre uma linha "
                        . "em branco para incluir, o botão \"+ faixa\" (mais uma linha) e \"Salvar faixas\"; a regra das faixas em nota. "
                        . "Embaixo, \"Trocar a medida para\" (confirma: as faixas recomeçam) e \"Mover para o parâmetro\".",
                    "Linha de inclusão: Novo subparâmetro, Mede (lista das medidas), Peso (%), \"Incluir\". Sem subparâmetros, em "
                        . "vermelho: \"Sem subparâmetros: este parâmetro não entra na conta até ganhar um.\"",
                ]],
                ["titulo" => "A nota de cada relógio agora", "itens" => [
                    "Um quadro que abre e fecha por relógio disponível, da maior nota para a menor: o nome em negrito, \"<grupo> · "
                        . "critérios de <lugar>\" e a nota à direita; aberto, a tabela Parâmetro, Subparâmetro, Valor medido, Faixa, Nota, "
                        . "Peso efetivo, Pontos, com a nota final no rodapé, e os links \"editar os critérios de <lugar>\" e \"critérios só "
                        . "deste relógio\". Por fim, o cartão \"Restaurar os critérios iniciais\" com o botão leve \"Restaurar\" (confirma).",
                ]],
            ],
        ],
        [
            "id" => "grupos",
            "pagina" => "grupos.php",
            "titulo" => "Grupos de relógios",
            "endereco" => "grupos.php",
            "le" => [
                "recurso=arvore",
            ],
            "grava" => [
                "arvore: novo, renomear, mover, ordem, excluir, relogios",
            ],
            "partes" => [
                ["titulo" => "Árvore", "itens" => [
                    "A explicação; a árvore como lista com recuo por nível. Cada grupo: o nome num campo com \"Renomear\" (discreto), "
                        . "\"N relógios\" (\"· critérios próprios\" com link), ↑ ↓ e Excluir (confirma dizendo para onde vão os subgrupos, "
                        . "os relógios e o que acontece com os critérios); os relógios dele em cinza embaixo; e \"subgrupo e mover\" (abre "
                        . "e fecha): Novo subgrupo + \"Criar\", Mover para dentro de (só onde não forma ciclo) + \"Mover\". No fim: linha de "
                        . "inclusão \"Novo grupo\", \"dentro de\" e \"Criar\". Sem grupos: \"Nenhum grupo ainda.\"",
                ]],
                ["titulo" => "O grupo de cada relógio", "itens" => [
                    "Tabela Relógio (\"(indisponível)\" em cinza) e Grupo (lista com \"(na raiz)\" e os caminhos), e o botão \"Salvar "
                        . "grupos\" (arvore relogios, todos de uma vez).",
                ]],
            ],
        ],
        [
            "id" => "execucoes",
            "pagina" => "execucoes.php",
            "titulo" => "Execuções do cron",
            "endereco" => "execucoes.php?de=&ate=&situacao=&busca=&por=&pagina= (os filtros no endereço)",
            "le" => [
                "recurso=cron",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => "Tudo", "itens" => [
                    "Título com \"Última execução: DD/MM/AAAA HH:MM:SS\". Filtros num cartão: De, Até (padrão: os últimos 7 dias), "
                        . "Situação (Com atividade (padrão), Com erro, Sem atividade, Todas), Texto no registro, Por página (25, 50, 100, "
                        . "200), \"Filtrar\" e os atalhos hoje, 7 dias, 30 dias, erros do último ano.",
                    "Resumo em 4 caixas: execuções no período, com atividade, com erro (vermelho se houver), a mais lenta. A nota "
                        . "com quantas e a retenção. Tabela Início (data e a hora em negrito embaixo), Duração (\"850 ms\" ou \"1,5 s\"), "
                        . "Situação (etiqueta erro / atividade / sem atividade), Registro (o texto em fonte fixa, ou \"nada a fazer neste "
                        . "minuto\"); linha com erro em vermelho claro. Paginação: « primeira, ‹ anterior, até 3 números de cada lado, "
                        . "próxima ›, última ».",
                ]],
            ],
        ],
        [
            "id" => "historico",
            "pagina" => "historico.php",
            "titulo" => "Histórico — <relógio>",
            "endereco" => "historico.php?id=<id>&de=&ate=&estado=&por=&pagina=",
            "le" => [
                "recurso=historico",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => "Tudo", "itens" => [
                    "Cabeça com a foto, \"Histórico · <tipo> · código N\", o nome em h1 e \"<n> registros desde ...\". Filtros num "
                        . "cartão: De, Até, Estado (Todos, Em uso, Fora do rodízio, cada tipo de sessão, Em repouso, Marcações), Por "
                        . "página (25, 50, 100, Todos), \"Filtrar\" e os atalhos 7 dias, 30 dias, desde o começo.",
                    "Resumo em caixas: o tempo em cada estado (\"5d 7h\" e \"em uso · 13%\") e a contagem de cada marcação. A nota de "
                        . "quantos e de como ler os trechos. Tabela Início, Fim, Duração, Estado (etiqueta verde para uso, vermelha para "
                        . "repouso, laranja para as outras sessões; marcação em cinza, só o texto; \"em andamento: o fim é o momento desta "
                        . "consulta\"). Paginação: « mais recentes, ‹ anterior, números, próxima ›, mais antigos » e \"ver tudo\".",
                ]],
            ],
        ],
        [
            "id" => "documentos",
            "pagina" => "documentos.php",
            "titulo" => "Documentos",
            "endereco" => "documentos.php?relogio=<id>&cat=<categoria> (sem relógio: de todos)",
            "le" => [
                "recurso=documentos",
                "recurso=documento (cada arquivo)",
            ],
            "grava" => [
                "documentos: enviar, alterar, excluir",
                "relogio: salvar (a cópia no banco do relógio)",
            ],
            "partes" => [
                ["titulo" => "Cabeça", "itens" => [
                    "h1 \"Documentos do <relógio>\" (ou \"de todos os relógios\"); a lista \"Relógio\" (Todos e cada um, com \"(N)\") e "
                        . "\"Abrir a ficha do relógio\". A situação da cópia no banco (quem decide: o config.php, o relógio, com o botão "
                        . "\"Guardar todos os dele no banco\" / \"Só os marcados\"). Sem a pasta: o motivo em âmbar.",
                ]],
                ["titulo" => "Enviar arquivos (abre e fecha; aberto quando não há nenhum; só com um relógio escolhido)", "itens" => [
                    "Categoria (com o que ela aceita), Título (\"vazio: o nome do arquivo\"), Data, Descrição, Arquivos (vários; o "
                        . "seletor só deixa os tipos da categoria), \"Guardar a cópia destes arquivos também no banco\" (só quando cabe ao "
                        . "arquivo decidir), o limite de tamanho, \"Enviar\" e o andamento (\"Enviando 2 de 5: foto.jpg — 40%\"). Um arquivo "
                        . "por vez, cada foto com a miniatura feita no navegador (480px, JPEG 80%). No fim: \"N documentos guardados.\" e "
                        . "os erros.",
                ]],
                ["titulo" => "Abas e conteúdo", "itens" => [
                    "Abas \"Todos (N)\" e cada categoria \"(N)\". Fotos: a galeria (quadrados de 140px, 100px no celular, com o título "
                        . "e a data) e o botão \"▶ Apresentação\"; tocar numa foto abre o visor em tela cheia (fundo #080b0f): \"N de M\", \"▶ "
                        . "Apresentação\" / \"❚❚ Parar\" (troca a cada 4 s), \"Baixar\", \"×\"; ‹ e › nos lados, setas do teclado, deslizar o "
                        . "dedo, Esc fecha, espaço liga a apresentação; a legenda embaixo.",
                    "Vídeos: o player e a lista para marcar e ordenar (↑ ↓), \"▶ Assistir os marcados\" (tocam em sequência; \"‹ "
                        . "Anterior\", \"Próximo ›\", \"Fechar\"). Arquivos: um cartão por arquivo com o título, a data, a categoria em "
                        . "etiqueta, a descrição, o nome, o tamanho e onde está guardado; PDF: \"Ver aqui\" (abre embutido), \"Abrir noutra "
                        . "aba\", \"Baixar\"; XML de NF-e: a tabela Emitente, Nota, Valor, Produto(s), Chave e \"Baixar o XML\"; áudio: o "
                        . "player; o resto: \"Baixar\".",
                    "Em cada arquivo, \"editar\" (abre e fecha): Título, Data, Categoria, Descrição, a cópia no banco (quando cabe), "
                        . "\"Salvar\" e \"Excluir\" (confirma \"Excluir <título>? O arquivo sai do servidor.\"). Fotos e vídeos se editam numa "
                        . "lista à parte, \"Editar ou excluir fotos e vídeos\".",
                ]],
            ],
        ],
        [
            "id" => "cadastros",
            "pagina" => "cadastros.php",
            "titulo" => "Cadastros",
            "endereco" => "cadastros.php?aba=<campos|lancamentos|formulas|avisos|modos|documentos>&editar=<id>#form",
            "le" => [
                "recurso=cadastros",
                "recurso=calcular (o testar)",
            ],
            "grava" => [
                "campos, lancamento_tipos, formulas, avisos, modos, documento_categorias",
            ],
            "partes" => [
                ["titulo" => "Em todas as abas", "itens" => [
                    "h1 \"Cadastros\" e as abas Campos, Tipos de lançamento, Fórmulas, Avisos, Modos de rodízio, Categorias de "
                        . "documentos. Cada aba é um cartão: o título, a explicação, a tabela com \"Editar\" (link que abre o formulário "
                        . "embaixo com os dados, no #form) e Excluir em cada linha, e o formulário (\"Novo ...\" ou \"Alterar ...\", botão "
                        . "\"Criar\" / \"Salvar\" e \"cancelar\"). Gravou: o recado no alto e a edição acaba; recusado: o que foi digitado fica "
                        . "e os erros aparecem no topo.",
                ]],
                ["titulo" => "Campos", "itens" => [
                    "Tabela Campo (\"(unidade)\"), Identificador (fonte fixa), Tipo, Vale para, Padrão, ações (Editar, ↑, ↓, Excluir "
                        . "com confirmação \"Excluir o campo <nome> e os valores dele?\"). Formulário: Nome (até 120), Identificador (a-z, "
                        . "0-9 e _; começa com letra; até 40), Tipo (Número inteiro, Número decimal, Sim ou não, Data, Lista de opções, "
                        . "Texto), Unidade (até 20), Valor padrão, Vale para (\"todos os relógios\" e os grupos), Opções (lista: uma por "
                        . "linha).",
                ]],
                ["titulo" => "Tipos de lançamento", "itens" => [
                    "Tabela Tipo, Identificador, Formato (com a unidade), Fecha sozinho às, Exclusiva, Mede o gasto, Vale quando "
                        . "(fonte fixa), Vale para, Lançamentos (quantos), ações. Formulário: Nome, Identificador, Formato (Instantâneo; "
                        . "Instantâneo com valor; Sessão, com início e fim), Unidade (com valor), Fecha sozinho às (hora; vazio: não "
                        . "fecha), Vale para, Vale quando (fórmula), as caixas \"sessão exclusiva (o relógio num lugar só)\" e \"mede o "
                        . "gasto\".",
                ]],
                ["titulo" => "Fórmulas", "itens" => [
                    "Tabela Fórmula (o nome e o identificador com a unidade), Versão para, Cálculo (fonte fixa), ações. Formulário: "
                        . "Identificador (só na nova), Nome, Unidade, Versão para, Cálculo (textarea, fonte fixa). \"Testar uma fórmula "
                        . "(sem gravar)\": o texto da fórmula e \"Testar em todos os relógios\" (recurso=calcular): a tabela Relógio, "
                        . "Resultado, As partes da conta, ou os erros. \"As funções do motor\" (abre e fecha): cada função em negrito e a "
                        . "explicação.",
                ]],
                ["titulo" => "Avisos", "itens" => [
                    "Tabela Aviso, Versão para, Data prevista, Vale quando, Antecedência (\"N d\"), Resolve, Na escala (com o valor "
                        . "ou as horas simuladas), Na agenda, Na hora (sim/não), Ativo, ações. Formulário: Identificador (na novo), Nome, "
                        . "Antecedência (dias), Resolve (os tipos), Versão para, ativo, \"na hora (Telegram no minuto em que vence)\" "
                        . "(caixa), Na escala inteligente (não; no relógio do dia; sempre), Escala: valor do lançamento simulado, Escala: "
                        . "horas da sessão simulada, Data na agenda (só dentro da antecedência; qualquer data), Data prevista (fórmula), "
                        . "Vale quando (fórmula), Texto (até 300).",
                ]],
                ["titulo" => "Modos de rodízio", "itens" => [
                    "Tabela Modo (\"(ativo)\"), Forma de escolha (\"· com ciclo\"), Blocos (nome e dias), ações (Editar; nos outros: "
                        . "Ativar e Excluir). Formulário: Nome, Planejamento (sorteio pelos blocos; escala inteligente de 7 dias, 30 "
                        . "dias, 60 dias, 6 meses, 1 ano, 2 anos), Forma de escolha, a caixa \"ciclo\", e a tabela dos blocos (os que "
                        . "existem e duas linhas vazias): Bloco (nome), Dias (caixas seg a dom), Sortear de, Um por (um por dia; um por "
                        . "bloco (na semana)), Relógio fixo (\"— sorteia —\" e os relógios). Bloco sem dia marcado sai.",
                ]],
                ["titulo" => "Categorias de documentos", "itens" => [
                    "Tabela Categoria, Identificador, Aceita (\"qualquer arquivo\" ou as famílias), Ordem, Documentos, ações (Excluir "
                        . "só sem documentos). Formulário: Nome, Identificador (na nova), Ordem (\"vazio: no fim\"), Aceita (caixas "
                        . "imagens, vídeos, áudios, PDF, XML).",
                ]],
            ],
        ],
        [
            "id" => "usuarios",
            "pagina" => "usuarios.php",
            "titulo" => "Usuários",
            "endereco" => "usuarios.php",
            "le" => [
                "recurso=usuarios",
            ],
            "grava" => [
                "usuarios: salvar, excluir",
            ],
            "partes" => [
                ["titulo" => "Tudo", "itens" => [
                    "h1 \"Usuários\". Cartão \"Quem acessa\": tabela Login (\"você\" em etiqueta azul no próprio), Criado em, e Excluir "
                        . "(menos no próprio; confirma). Cartão \"Criar usuário ou trocar senha\": Login, Senha (pelo menos 6 caracteres, "
                        . "campo de senha), \"Se o login já existir, a senha dele é trocada.\" e \"Salvar usuário\". Recusado: o erro no "
                        . "topo.",
                ]],
            ],
        ],
        [
            "id" => "ajuda",
            "pagina" => "ajuda.php",
            "titulo" => "Ajuda",
            "endereco" => "ajuda.php#<âncora>",
            "le" => [
                "recurso=manual (o HTML e o sumário)",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => "Tudo", "itens" => [
                    "h1 \"Ajuda\"; \"Nesta página\": o sumário com os títulos de nível 2 e 3 (recuados), cada um link para a sua "
                        . "âncora; depois o manual em HTML, com as tabelas que rolam de lado no celular, as imagens com borda e cantos, o "
                        . "código em fonte fixa. Abrindo com uma âncora no endereço, a página vai direto para ela.",
                ]],
            ],
        ],
    ],
    "capturas" => [
        ["arquivo" => "docs/telas/hoje.png", "mostra" => "a página Hoje no computador, com o painel ao lado"],
        ["arquivo" => "docs/telas/colecao.png", "mostra" => "a tabela dos relógios com as 11 colunas e os filtros"],
        ["arquivo" => "docs/telas/historico.png", "mostra" => "o histórico de um relógio"],
        ["arquivo" => "docs/telas/cadastros.png", "mostra" => "a aba Campos dos Cadastros"],
        ["arquivo" => "docs/telas/celular.png", "mostra" => "no celular: a Hoje, os cartões dos relógios e o painel em tela cheia"],
    ],
];

// O código do sistema como está agora, para a reescrita ler o original (recurso=codigo): cada arquivo com a camada e o que
// ele é. O conteúdo é lido do disco na hora do pedido, então nunca fica atrás da versão em uso. Só saem os arquivos desta
// lista, as migrações (as de $MIGRACOES) e as capturas das telas (as de RECONSTRUCAO_TELAS); o resto da pasta nunca sai, e
// o que é de propósito fica de fora está em RECONSTRUCAO_NAO_MOSTRADOS, com o que ver no lugar. Arquivo novo no sistema?
// Ponha aqui também.
const RECONSTRUCAO_CODIGO = [
    "pagina.php" => ["tela", "a base de todas as páginas: topo() (o head, o menu e o título) e rodape() (os scripts, com a data do arquivo na URL)"],
    "estilo.css" => ["tela", "o estilo de todas as páginas: as cores, as letras, os controles, os componentes e os tamanhos de tela"],
    "api.js" => ["tela", "a base do JavaScript das páginas: ler e gravar pela API, os formatos (número, dinheiro, data, hora), o login, os formulários que gravam, as tabelas que viram fichas no celular"],
    "index.php" => ["tela", "o esqueleto da página Hoje (o meio, a tabela dos relógios com os títulos e os filtros, e o painel)"],
    "hoje.js" => ["tela", "a página Hoje: o relógio do dia, os avisos de hoje, os próximos dias, o modo de rodízio e as linhas da tabela dos relógios"],
    "tabela.js" => ["tela", "a tabela dos relógios: ordenar, filtrar e virar cartões quando as 11 colunas não cabem"],
    "painel.js" => ["tela", "o painel do relógio (na página Hoje e na Ficha): os quadros, o Marcar, o Editar cadastro, o gráfico da carga, os dados e o histórico"],
    "foto.js" => ["tela", "a foto reduzida no navegador antes de enviar (1200 px, JPEG 85%), com a prévia"],
    "ficha.php" => ["tela", "a página Ficha do relógio: o painel do relógio numa página própria"],
    "plano.php" => ["tela", "o esqueleto da página Plano"],
    "plano.js" => ["tela", "a página Plano: o resumo do período e o dia a dia, mês a mês"],
    "configuracao.php" => ["tela", "o esqueleto da página Configuração"],
    "configuracao.js" => ["tela", "a página Configuração: o banco desatualizado, os quadros das opções, as mensagens, o que vai para onde, os eventos e como sai hoje"],
    "criterios.php" => ["tela", "o esqueleto da página Critérios"],
    "criterios.js" => ["tela", "a página Critérios: as explicações, os lugares, os parâmetros, os subparâmetros, as faixas e a nota de cada relógio"],
    "grupos.php" => ["tela", "o esqueleto da página Grupos"],
    "grupos.js" => ["tela", "a página Grupos: a árvore e o grupo de cada relógio"],
    "execucoes.php" => ["tela", "o esqueleto da página Execuções do cron"],
    "execucoes.js" => ["tela", "a página Execuções do cron: os filtros, o resumo, a tabela e a paginação"],
    "desejos.php" => ["tela", "o esqueleto da página Desejos"],
    "desejos.js" => ["tela", "a página Desejos: a lista de desejos (com o Comprei) e os relógios fora da coleção"],
    "estatisticas.php" => ["tela", "o esqueleto da página Estatísticas"],
    "estatisticas.js" => ["tela", "a página Estatísticas: o período, o resumo, cada relógio, os gráficos em SVG e a coleção"],
    "historico.php" => ["tela", "o esqueleto da página Histórico"],
    "historico.js" => ["tela", "a página Histórico de um relógio: os filtros, o resumo do tempo e a linha do tempo"],
    "documentos.php" => ["tela", "o esqueleto da página Documentos"],
    "documentos.js" => ["tela", "a página Documentos: o envio, as abas, a galeria com o visor, os vídeos, os PDFs, as NF-e e a edição"],
    "cadastros.php" => ["tela", "o esqueleto da página Cadastros"],
    "cadastros.js" => ["tela", "a página Cadastros: as abas Campos, Tipos de lançamento, Fórmulas, Avisos, Modos de rodízio e Categorias de documentos"],
    "usuarios.php" => ["tela", "o esqueleto da página Usuários"],
    "usuarios.js" => ["tela", "a página Usuários: quem acessa e criar usuário ou trocar a senha"],
    "ajuda.php" => ["tela", "o esqueleto da página Ajuda"],
    "ajuda.js" => ["tela", "a página Ajuda: o sumário e o manual em HTML (recurso=manual)"],
    "api.php" => ["api", "a API, a porta única: a autenticação, o formato, a ordem dos recursos (cada um no seu arquivo da pasta api/, ou ali mesmo, os pequenos), os filtros, o JSON e o XML, e no cabeçalho a documentação inteira"],
    "api/funcoes.php" => ["api", "as funções que só a API usa (o XML, os textos legíveis do tempo, do plano, dos eventos e da agenda, os filtros das listas e a resposta) e o tratamento dos erros"],
    "api/tudo.php" => ["api", "api.php sem recurso: tudo o que está guardado"],
    "api/hoje.php" => ["api", "recurso=hoje: tudo o que a página Hoje mostra"],
    "api/ficha.php" => ["api", "recurso=ficha: tudo o que o painel de um relógio mostra (ou o cadastro de um novo)"],
    "api/configuracao.php" => ["api", "recurso=config: a Configuração inteira"],
    "api/desejos.php" => ["api", "recurso=desejos: a lista de desejos e os relógios fora da coleção"],
    "api/estatisticas.php" => ["api", "recurso=estatisticas: o uso da coleção num período, por relógio, dia da semana e mês, e a distribuição da coleção"],
    "api/historico.php" => ["api", "recurso=historico: a linha do tempo de um relógio, com os filtros e o resumo"],
    "api/plano.php" => ["api", "recurso=plano: o plano dia a dia, o resumo por relógio e as páginas"],
    "api/cron.php" => ["api", "recurso=cron: as execuções do cron, com os filtros, o resumo e as páginas"],
    "api/arvore.php" => ["api", "recurso=arvore: a árvore dos grupos e o grupo de cada relógio"],
    "api/cadastros.php" => ["api", "recurso=cadastros: os campos, os tipos de lançamento, as fórmulas, os avisos, os modos e as categorias"],
    "api/calcular.php" => ["api", "recurso=calcular: o resultado de uma fórmula para um relógio (ou para todos)"],
    "api/documentos.php" => ["api", "recurso=documentos: os documentos de um relógio (ou de todos), as categorias e o limite"],
    "api/documento.php" => ["api", "recurso=documento: um arquivo de um documento (o original, a miniatura, a página de um PDF)"],
    "api/backup.php" => ["api", "recurso=backup: as cópias de segurança da pasta, e uma cópia para baixar (arquivo=)"],
    "api/migracoes.php" => ["api", "recurso=migracoes: as migrações pendentes do banco (a leitura) e aplicar (a escrita)"],
    "api/codigo.php" => ["api", "recurso=codigo: o código do sistema como está agora, arquivo por arquivo"],
    "api/ajuda.php" => ["api", "recurso=ajuda: a documentação da API (cada consulta, cada escrita e o dicionário dos campos, a fonte única dele)"],
    "api/escrita.php" => ["api", "as escritas (POST): cada recurso e ação vai para a função dele no operacoes.php, que valida, grava e responde"],
    "lib.php" => ["api", "o núcleo: o token, o login, a árvore, os campos, os lançamentos, o motor das fórmulas, os avisos, os critérios, o rodízio, a escala e as listas do código"],
    "operacoes.php" => ["api", "as escritas: cada ação valida, grava e devolve ok, mensagem e erros (a API chama estas funções)"],
    "banco.php" => ["api", "o banco: MySQL/MariaDB, PostgreSQL, SQLite ou Firebird, o SQL escrito uma vez só e traduzido para cada um, e o catálogo do modelo"],
    "reconstrucao.php" => ["api", "a reconstrução (recurso=reconstrucao): as regras de cada parte, a especificação das telas e esta lista do código"],
    "cron.php" => ["linha_de_comando", "o cron, a cada minuto: as mensagens, o pulso sozinho, a agenda, a manutenção dos documentos, sempre mudo e registrado no banco"],
    "instalar.php" => ["linha_de_comando", "a instalação pelo servidor (a mesma do POST recurso=instalacao)"],
    "importar.php" => ["linha_de_comando", "a importação do sistema antigo pelo servidor (a mesma do POST recurso=importacao)"],
    "criar_usuario.php" => ["linha_de_comando", "criar um usuário ou trocar a senha pelo servidor (o mesmo do POST recurso=usuarios, acao=salvar)"],
    "schema.sql" => ["banco", "o banco inicial (no dialeto do MySQL, traduzido para os outros) e o cadastro que vem pronto, com os critérios iniciais"],
    "config.exemplo.php" => ["servidor", "o modelo do config.php, com cada constante explicada (o config.php de verdade não sai: veja nao_mostrados)"],
    ".htaccess" => ["servidor", "o Apache: o que abre pelo navegador e o que fica bloqueado"],
    "nginx-relogios.conf" => ["servidor", "o nginx: o mesmo bloqueio do .htaccess"],
    "testes/cenario.php" => ["testes", "o roteiro de aceitação: faz pela API o que uma pessoa faz e tira a fotografia de tudo o que a API devolve"],
    "testes/comparar.php" => ["testes", "compara duas fotografias do cenário (os quatro bancos, ou a reescrita com a versão atual)"],
    "testes/paridade.sh" => ["testes", "o teste de paridade com um comando: os quatro bancos ao mesmo tempo, numa pasta temporária, e a comparação"],
    "testes/config.teste.php" => ["testes", "o config dos testes (o acesso aos bancos pelo ambiente), apontado por RELOGIOS_CONFIG: o config.php de verdade fica intocado"],
    "testes/dicionario.php" => ["testes", "gera o dicionário dos campos do cabeçalho do api.php a partir do da ajuda (a fonte única), e confere que batem"],
    "testes/campos.php" => ["testes", "confere que todo campo das respostas da API está explicado no dicionário da ajuda"],
    "testes/layout.cjs" => ["testes", "a conferência do layout: todas as telas num navegador, em 15 tamanhos, com dados de estresse, procurando o que quebra"],
    "testes/README.md" => ["testes", "como rodar o teste de paridade e a conferência do layout"],
    "README.md" => ["documentacao", "o manual inteiro: para quem usa, a instalação, a API e como é por dentro"],
    "PORTE.md" => ["documentacao", "o que o sistema antigo fazia e onde está no novo"],
    "LICENSE" => ["documentacao", "a licença"],
    ".gitignore" => ["repositorio", "o que nunca entra no repositório (a configuração, as chaves, os bancos)"],
    ".gitattributes" => ["repositorio", "as quebras de linha (LF em tudo) e os binários"],
    ".github/workflows/testes.yml" => ["repositorio", "os testes no GitHub a cada push no main: a paridade nos bancos (com a documentação da API) e o layout das telas"],
];

// O que fica de fora de propósito, e o que ver no lugar
const RECONSTRUCAO_NAO_MOSTRADOS = [
    ["arquivo" => "config.php", "por_que" => "tem as senhas do banco e o API_TOKEN",
        "o_que_ver" => "o config.exemplo.php (o modelo, com cada constante explicada) e listas.config_php no recurso=reconstrucao"],
    ["arquivo" => "a chave JSON do Google (google-conta-servico.json ou o caminho da Configuração)", "por_que" => "é uma credencial",
        "o_que_ver" => "a seção mensagens do recurso=reconstrucao e o README (Google Agenda): como criar a conta de serviço e a chave"],
    ["arquivo" => "o banco de dados (o servidor MySQL/PostgreSQL/Firebird, ou o arquivo do SQLite)", "por_que" => "são os dados, não o código",
        "o_que_ver" => "o modelo em modelo_de_dados (recurso=reconstrucao) e os dados pela própria API (api.php sem recurso: tudo o que está guardado)"],
    ["arquivo" => "a pasta dos documentos (DOCUMENTOS_PASTA)", "por_que" => "são os arquivos de cada relógio, não o código",
        "o_que_ver" => "recurso=documentos (a lista) e recurso=documento (cada arquivo)"],
    ["arquivo" => "as senhas dos usuários", "por_que" => "não existem no sistema: só o hash",
        "o_que_ver" => "recurso=usuarios (o login e o hash) e senhas na ajuda (como conferir e migrar)"],
];

// Os arquivos que saem pelo recurso=codigo, os que existem na pasta: [arquivo => [camada, o que é]]
function codigo_arquivos()
{
    global $MIGRACOES;
    $lista = RECONSTRUCAO_CODIGO;
    foreach ($MIGRACOES as $versao => $m) {
        $lista[$m[0]] = ["banco", "a migração " . $versao . ": " . $m[1]];
    }
    foreach (RECONSTRUCAO_TELAS["capturas"] as $c) {
        $lista[$c["arquivo"]] = ["imagem", "a captura de tela: " . $c["mostra"]];
    }
    return array_filter($lista, function ($a) { return is_file(__DIR__ . "/" . $a); }, ARRAY_FILTER_USE_KEY);
}

// O tipo de um arquivo: [linguagem, binário?, o tipo para mostrar cru]
function codigo_tipo($arquivo)
{
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    $linguagens = ["php" => "php", "js" => "javascript", "css" => "css", "sql" => "sql", "md" => "markdown", "conf" => "nginx", "png" => "png", "yml" => "yaml", "sh" => "shell", "cjs" => "javascript"];
    $linguagem = $linguagens[$ext] ?? ($arquivo === ".htaccess" ? "apache" : "texto");
    return [$linguagem, $ext === "png", $ext === "png" ? "image/png" : "text/plain; charset=utf-8"];
}

// Um arquivo: o que ele é e, com $conteudo, o conteúdo (texto como está; imagem em base64)
function codigo_arquivo($arquivo, $conteudo)
{
    $caminho = __DIR__ . "/" . $arquivo;
    [$linguagem, $binario] = codigo_tipo($arquivo);
    $bytes = (string)file_get_contents($caminho);
    $info = codigo_arquivos()[$arquivo];
    $res = ["arquivo" => $arquivo, "camada" => $info[0], "o_que_e" => $info[1], "linguagem" => $linguagem, "bytes" => strlen($bytes),
        "linhas" => $binario ? null : substr_count($bytes, "\n") + (substr($bytes, -1) === "\n" || $bytes === "" ? 0 : 1),
        "sha256" => hash("sha256", $bytes), "alterado_em" => date("Y-m-d H:i:s", (int)filemtime($caminho)),
        "ver" => "api.php?recurso=codigo&arquivo=" . rawurlencode($arquivo), "cru" => "api.php?recurso=codigo&arquivo=" . rawurlencode($arquivo) . "&cru=1"];
    if ($conteudo) {
        $res[$binario ? "conteudo_base64" : "conteudo"] = $binario ? base64_encode($bytes) : $bytes;
    }
    // uma página: os arquivos dela e o HTML que o navegador recebe
    if (in_array($arquivo, codigo_paginas(), true)) {
        $res["usa"] = codigo_da_pagina($arquivo);
        $res["html_cru"] = "api.php?recurso=codigo&arquivo=" . rawurlencode($arquivo) . "&html=1&cru=1";
        if ($conteudo) {
            $res["html"] = codigo_html($arquivo);
        }
    }
    return $res;
}

// As páginas do sistema (as da especificação das telas)
function codigo_paginas()
{
    return array_column(RECONSTRUCAO_TELAS["paginas"], "pagina");
}

// O HTML que uma página entrega ao navegador, montado agora: as páginas não leem o banco nem conferem o login, só
// escrevem o esqueleto (o topo() e o rodape() do pagina.php em volta do HTML dela)
function codigo_html($pagina)
{
    ob_start();
    include __DIR__ . "/" . $pagina;
    return (string)ob_get_clean();
}

// A lista do código (recurso=codigo; $conteudo: com o conteúdo de cada arquivo)
function codigo_lista($conteudo)
{
    return [
        "como_usar" => "O código do sistema como está agora, lido do disco na hora: cada arquivo com a camada, o que ele é, o tamanho e o "
            . "SHA-256. Um arquivo: arquivo=<nome> (o conteúdo como está, em conteudo; a imagem em conteudo_base64); com cru=1, o arquivo "
            . "puro, sem JSON (texto como text/plain, para ler no navegador). Numa página, html traz o HTML que o navegador recebe, já montado "
            . "(html=1 com cru=1: só ele), e usa, os arquivos que ela carrega. Todos de uma vez: conteudo=1 (com os filtros: "
            . "f[arquivos][camada]=tela traz o HTML, o CSS e o JavaScript das telas). O que fica de fora de propósito está em nao_mostrados, com o que ver no lugar.",
        "camadas" => [
            ["camada" => "tela", "o_que_e" => "as páginas: o esqueleto em PHP, o estilo e o JavaScript que lê a API e monta a tela"],
            ["camada" => "api", "o_que_e" => "o back-end: a API e o que ela usa (o núcleo, as escritas, o banco, a reconstrução)"],
            ["camada" => "linha_de_comando", "o_que_e" => "o cron e os scripts do servidor"],
            ["camada" => "banco", "o_que_e" => "o banco inicial e as migrações"],
            ["camada" => "servidor", "o_que_e" => "a configuração do servidor web e o modelo do config.php"],
            ["camada" => "testes", "o_que_e" => "o roteiro de aceitação e a comparação"],
            ["camada" => "documentacao", "o_que_e" => "o README e os outros textos"],
            ["camada" => "repositorio", "o_que_e" => "os arquivos do git"],
            ["camada" => "imagem", "o_que_e" => "as capturas das telas"],
        ],
        "arquivos" => array_map(function ($a) use ($conteudo) { return codigo_arquivo($a, $conteudo); }, array_keys(codigo_arquivos())),
        "nao_mostrados" => RECONSTRUCAO_NAO_MOSTRADOS,
    ];
}

// Os arquivos de uma página: o pagina.php, o estilo, a própria página e os scripts que ela carrega (lidos do rodape([...]) dela)
function codigo_da_pagina($pagina)
{
    $fonte = (string)@file_get_contents(__DIR__ . "/" . $pagina);
    $scripts = preg_match('/rodape\(\[([^\]]*)\]\)/', $fonte, $m) === 1 && preg_match_all('/"([^"]+\.js)"/', $m[1], $s) > 0 ? $s[1] : [];
    return array_values(array_unique(array_merge(["pagina.php", "estilo.css", $pagina], $scripts)));
}

function reconstrucao()
{
    global $FUNCOES, $TIPOS_CAMPO, $FORMATOS_LANCAMENTO, $REPETICOES, $ANCORAS, $CANAIS, $MIGRACOES, $DIAS_SEMANA;
    $secao = function ($id, $titulo, $itens) {
        return ["id" => $id, "titulo" => $titulo, "itens" => $itens];
    };
    $tabelas = array_map(function ($t) {
        return array_merge(["tabela" => $t["tabela"], "descricao" => RECONSTRUCAO_TABELAS[$t["tabela"]] ?? null], array_diff_key($t, ["tabela" => 1]));
    }, banco_catalogo());
    // uma lista {chave: descrição} do código como lista de objetos: [{<nome da chave>: chave, descricao}]
    $lista = function ($mapa, $nome) {
        return array_map(function ($k, $v) use ($nome) { return [$nome => (string)$k, "descricao" => $v]; }, array_keys($mapa), $mapa);
    };
    $funcoes = [];
    foreach ($FUNCOES as $nome => $f) {
        $funcoes[] = ["nome" => $nome, "argumentos" => $f[1] === null ? $f[0] . " ou mais" : ($f[0] === $f[1] ? (string)$f[0] : $f[0] . " a " . $f[1]),
            "descricao" => $f[2], "le_historico" => (bool)$f[3]];
    }
    $migracoes = [];
    foreach ($MIGRACOES as $v => $m) {
        $migracoes[] = ["versao" => $v, "arquivo" => $m[0], "traz" => $m[1], "passo_em_php" => isset($m[3])];
    }
    return [
        "o_que_e" => "O Relojoeiro (Relógios 2) planeja o rodízio de uma coleção de relógios: escolhe o relógio de cada dia pela nota de cada um "
            . "(critérios com pesos) e pelas regras do modo de rodízio, acompanha a energia de cada um (bateria, reserva de marcha, carga de luz, vida "
            . "da pilha) pelas fórmulas do cadastro e pelo histórico de lançamentos, avisa o que fazer (carregar, dar corda, pôr no sol, trocar a pilha, "
            . "revisão) pelo Telegram e pelo Google Agenda, mede o gasto real da bateria pelas leituras e guarda os documentos de cada relógio. "
            . "Esta resposta é o roteiro para reescrever o sistema inteiro do zero e chegar ao mesmo comportamento.",
        "como_usar" => [
            "Leia as seções na ordem: os princípios e a arquitetura, a ordem de construção, e cada módulo com as suas regras.",
            "O modelo de dados (modelo_de_dados) é o do banco em uso agora, lido dele: tabelas, colunas, chaves, índices e regras de validação. Os tipos "
                . "vêm no dialeto desse banco; o dialeto de referência (MySQL) está no schema.sql.",
            "O contrato da API (cada consulta com parâmetros e exemplo, cada escrita com ações e campos, e o significado de cada campo das respostas) "
                . "está em api.php?recurso=ajuda: a reescrita deve responder igual, para as telas, os scripts e quem integra continuarem funcionando.",
            "O conhecimento sobre relógios não está no código: está no cadastro inicial (schema.sql, a partir de \"-- critérios iniciais\" para os "
                . "critérios; os campos, os tipos de lançamento, as fórmulas, os avisos e os modos nas migrações). Leia também por "
                . "recurso=cadastros e recurso=criterios. A reescrita traz esse cadastro como dado, não como código.",
            "As telas (telas) são a especificação visual: o visual com os valores exatos, o menu e cada página, parte por parte, com cada "
                . "tabela, campo, botão e mensagem, palavra por palavra. A reescrita reproduz a mesma interface, não uma parecida: o que está "
                . "lá existe, e o que não está não existe. As capturas em docs/telas/ mostram o resultado.",
            "O código de verdade, como está agora, sai pelo recurso=codigo (a lista em codigo, abaixo): o CSS, o JavaScript, cada página e o "
                . "HTML que ela entrega ao navegador, a API, o banco e o resto, lido do disco na hora (arquivo=<nome>; cru=1 para o arquivo puro). "
                . "Cada página em telas diz os arquivos dela (codigo). O que não sai (o config.php, as chaves, os dados) está em codigo.nao_mostrados, "
                . "com o que ver no lugar.",
            "O README.md explica cada tela e cada opção para quem usa (as seções para quem usa saem também pela API, em recurso=manual: o texto da "
                . "página Ajuda); o testes/cenario.php é o roteiro de aceitação (veja a seção testes).",
        ],
        "secoes" => [
            $secao("principios", "Os princípios (não negociáveis)", [
                "O sistema não sabe nada de relógios: o código tem só mecanismos (árvore, campos, tipos de lançamento, fórmulas, avisos, critérios, modos). "
                    . "Que um automático tem reserva de marcha, ou que um solar carrega no sol, está escrito em fórmulas cadastradas, que o usuário lê, "
                    . "edita e testa pela tela. Mudar uma regra de relógio é mudar cadastro (com uma migração), não código.",
                "Tudo pela API: as páginas só desenham; toda leitura e toda gravação passa pela API, a mesma que um script usa. Nenhuma regra de negócio no "
                    . "navegador além de mostrar e esconder campos.",
                "Falha segura: sem token válido, com o fuso inválido ou com o banco desatualizado (migração pendente), o sistema para e diz o motivo "
                    . "(a API responde 500 ou 503, o cron registra e não faz nada), em vez de rodar pela metade.",
                "O banco se defende: relacional e normalizado, toda ligação é chave estrangeira (obrigatória: some junto, ON DELETE CASCADE; opcional: "
                    . "a ligação se desfaz, SET NULL), nenhuma lista guardada como texto, e regras de validação (CHECK, NOT NULL, UNIQUE) iguais às da "
                    . "tela. Uma gravação errada é recusada pelo banco venha de onde vier (a API responde 409 com a regra quebrada).",
                "Os mesmos resultados em MySQL/MariaDB, PostgreSQL, SQLite e Firebird: o SQL do sistema é escrito uma vez só; o que muda de banco "
                    . "para banco fica numa camada de tradução. Maiúsculas e acentos não contam nos textos curtos (\"Relógio\" = \"RELOGIO\"), em todos; no "
                    . "MySQL/MariaDB, toda tabela em utf8mb4_unicode_ci (a utf8mb4_general_ci, padrão do MariaDB, ordena diferente); no Firebird, a "
                    . "collation UNICODE_CI_AI.",
                "Datas e horas no fuso do sistema (FUSO). Nas fórmulas, o tempo é contado em dias no horário local: a meia-noite local de qualquer "
                    . "data é um número inteiro e a fração é a hora do dia; assim uma data e o agora entram na mesma conta sem o fuso aparecer.",
                "O cron é mudo e rastreável: roda a cada minuto, não escreve na saída, e registra no banco o que fez.",
                "Português em tudo o que o usuário vê: mensagens de erro explicam o que fazer (\"Dê um nome ao relógio (até 120 caracteres).\").",
            ]),
            $secao("arquitetura", "A arquitetura", [
                "Um back-end único, a API (api.php): GET lê (recurso=...), POST grava (recurso=... e acao=...). Responde JSON (ou XML com formato=xml). "
                    . "O api.php faz a porta (a autenticação, o formato, a ordem dos recursos, os filtros e a resposta); cada recurso grande fica num "
                    . "arquivo da pasta api/ (api/hoje.php, api/ficha.php, api/ajuda.php...), que roda dentro dele, com as mesmas variáveis. "
                    . "Autenticação em todo pedido: o token (cabeçalho X-Api-Token ou parâmetro token, igual ao API_TOKEN) ou o login do site (HTTP Basic "
                    . "com usuário e senha da tabela usuario, senha com password_hash).",
                "Um núcleo (lib.php): o motor das fórmulas, as variáveis de cada relógio, os avisos, as notas, o plano, a escala, a previsão, o gasto "
                    . "medido, o pulso sozinho, a linha do tempo, as mensagens, a agenda e os documentos.",
                "As gravações (operacoes.php): uma função por recurso (op_relogio, op_lancamento, op_campos...), que valida tudo, grava e devolve "
                    . "{ok, mensagem, erros[], ...}. A tela e a API chamam as mesmas.",
                "O banco (banco.php): a conexão com as extensões do PHP (mysqli, pgsql, sqlite3, pdo_firebird), parâmetros sempre por ? (nunca texto "
                    . "colado no SQL), e a tradução do dialeto do MySQL para os outros: AUTO_INCREMENT, ENUM (vira CHECK ... IN), TINYINT, DATETIME, os INDEX "
                    . "dentro do CREATE TABLE, AFTER, MODIFY COLUMN (o SQLite refaz a tabela), INSERT IGNORE, REPLACE INTO, NOW(), <=>, LIKE sem diferenciar "
                    . "maiúsculas; no Firebird, também LIMIT/OFFSET (OFFSET ... ROWS FETCH NEXT ... ROWS ONLY), DATE(x) (CAST AS DATE), CONCAT (||), "
                    . "LENGTH/LEAST/GREATEST (OCTET_LENGTH/MINVALUE/MAXVALUE), o SELECT sem FROM (FROM RDB\$DATABASE), o INSERT de várias linhas (um por "
                    . "linha), a tabela no (palavra reservada: \"NO\"), nomes de índice e de regra com até 31 letras, o TEXT como VARCHAR(8191) e a chave "
                    . "única de várias colunas com coluna vazia como índice único de uma expressão; e três comandos próprios das migrações: ALTER FOREIGN "
                    . "KEY, DROP COLUMN e ADD CONSTRAINT ... CHECK (trocando a regra sem perder a anterior se os dados não cumprem a nova).",
                "As páginas (index.php, plano.php, desejos.php, estatisticas.php, ficha.php, documentos.php, historico.php, configuracao.php, criterios.php, grupos.php, "
                    . "cadastros.php, execucoes.php, usuarios.php, ajuda.php) só entregam o esqueleto, sem conferir nem o login; o JavaScript de cada uma "
                    . "(hoje.js, painel.js, configuracao.js, ajuda.js...) chama a API e monta a tela, sem fazer conta: resumos, porcentagens e situações "
                    . "(o resumo do plano, a porcentagem de cada estado no histórico, o peso efetivo dos critérios, a situação do cron, o manual "
                    . "em HTML) vêm prontos da API. Formulários com data-recurso gravam pela API e a página se remonta com a resposta.",
                "A API faz tudo, inclusive o que vem antes das telas: a instalação do banco vazio (recurso=instalacao, pelo token), o primeiro "
                    . "usuário (recurso=usuarios pelo token), a importação do sistema anterior (recurso=importacao) e o login das páginas "
                    . "(recurso=entrar: a página sem login recebe 401 da API, o api.js manda para api.php?recurso=entrar&volta=<a página>, o "
                    . "navegador pede o usuário e a senha e a API devolve para a página). Os scripts de linha de comando (instalar.php, "
                    . "criar_usuario.php, importar.php) só chamam as mesmas operações.",
                "Fora da API, só o cron (cron.php), a cada minuto pela linha de comando, com as mesmas funções que a API usa; o que ele fez sai "
                    . "em recurso=cron.",
                "Sem framework e sem dependências: PHP 8.1+ e JavaScript puro. A reescrita pode usar outra pilha, desde que mantenha o contrato da API, o "
                    . "modelo de dados e as regras.",
            ]),
            $secao("ordem", "A ordem de construção", [
                "1. O banco: as tabelas do modelo_de_dados, com as chaves e as regras; a instalação (o schema inteiro num banco vazio) e as migrações "
                    . "(uma lista ordenada; cada uma com o SQL, um passo opcional em programa, e uma marca de aplicada: uma tabela, uma coluna ou uma "
                    . "linha da config; com alguma pendente, o sistema para e a Configuração mostra o botão de aplicar).",
                "2. O cadastro inicial (a árvore Smartwatch / Tradicional › Mecânico › Automático e Corda manual / Tradicional › Quartzo › Pilha e "
                    . "Solar, os campos, os tipos de lançamento, as fórmulas, os avisos, os critérios e os modos) como dados.",
                "3. A autenticação (token e HTTP Basic) e o esqueleto da API: os formatos, os erros (400, 401, 404, 409, 413, 500, 503) e os filtros "
                    . "genéricos de qualquer consulta (incluir, excluir, f[], busca, ordem, limite, pagina, mostrar).",
                "4. A árvore, os campos (com a validação do valor pelo tipo), os relógios e as fotos.",
                "5. Os tipos de lançamento e os lançamentos (instantâneo, com valor, sessão; sessão exclusiva fecha as outras exclusivas no mesmo "
                    . "instante; sem sobreposição de sessões do mesmo tipo), e a linha do tempo.",
                "6. O motor das fórmulas (abaixo), as variáveis de um relógio e o botão Testar.",
                "7. Os avisos, a visão de cada relógio (agora, carga, situação, próxima manutenção) e a página Hoje.",
                "8. O gasto medido pelas leituras e a previsão da energia (autonomia).",
                "9. Os critérios e a nota; os modos de rodízio, o plano, a garantia de rodízio, trocar o dia, sortear de novo; a escala inteligente.",
                "10. O pulso sozinho e as sessões esquecidas; o cron.",
                "11. As mensagens (Telegram), a agenda (Google), os eventos personalizados e a tabela \"O que vai para onde\".",
                "12. Os documentos (pasta, cópia no banco em três níveis, galeria, vídeos, PDF, NF-e) e a manutenção deles no cron.",
                "13. As telas, os usuários, as execuções do cron, a ajuda (o README dentro do sistema: os mesmos trechos que recurso=manual devolve) "
                    . "e a importação do sistema anterior.",
                "14. A aceitação: o roteiro de testes nos quatro bancos dá as mesmas respostas (seção testes).",
            ]),
            $secao("arvore_campos", "A árvore, os campos e os relógios", [
                "A árvore de grupos tem quantos níveis o usuário quiser; um relógio fica num grupo só (ou na raiz). Tudo o que se cadastra num grupo "
                    . "(campos, tipos de lançamento, fórmulas, avisos, critérios, a origem de um bloco de modo) vale para ele e para tudo abaixo dele.",
                "O mesmo nome pode se repetir em ramos diferentes, mas não entre irmãos. Mover um grupo para dentro dele mesmo (ou de um de dentro) é "
                    . "recusado. Excluir um grupo pela tela passa antes o que era dele para o grupo de cima (os de dentro, os relógios, os campos, os "
                    . "tipos, as fórmulas, os avisos e os blocos que sorteavam dele); versões de fórmula e de aviso que o grupo de cima já tem saem.",
                "Campos: identificador (minúsculas, números e _, começando por letra, até 40; único entre campos e fórmulas, porque é o nome nas "
                    . "fórmulas; não muda se alguma fórmula usa), nome, tipo (listas.tipos_de_campo), unidade, valor padrão, o grupo, a ordem e a marca só nos desejados "
                    . "(so_desejado). Os campos de um relógio são os de todos e os de cada grupo da cadeia dele (os só nos desejados, só no desejado).",
                "O valor de um campo é conferido e normalizado pelo tipo: inteiro; decimal (aceita 1.079,99, 1079,99 e 1079.99, grava com ponto); "
                    . "sim/não (1, sim, s, true; 0, não, nao, n, false); data AAAA-MM-DD; lista (uma das opções); texto (até 1000). Vazio apaga. O valor "
                    . "usado nas contas é o informado, senão o padrão do campo.",
                "A situação na coleção de cada relógio (posse): colecao (o padrão), desejado (a lista de desejos), emprestado, vendido ou arquivado. "
                    . "Só o que está na coleção entra no rodízio: nas outras, o relógio fica indisponível (o banco garante, ck_relogio_posse_disponivel; "
                    . "a gravação força disponivel=0 e diz isso na mensagem), e por isso sai do sorteio, da escala e dos avisos sem regra a mais. Saindo "
                    . "da coleção, as sessões abertas dele fecham agora (com a nota \"Fechei agora: ...\"), os dias dele no plano de amanhã em diante saem e são sorteados de novo (com escala, gerar_escala de amanhã; sem, "
                    . "garantir_plano); hoje fica, mas a sessão do dia (o pulso sozinho) não abre para um relógio fora da coleção. Voltando para a coleção sem dizer o disponível, ele fica disponível. Da coleção (contam na tabela da "
                    . "página Hoje e no valor): na coleção e emprestado (o estado do emprestado parado é \"Emprestado\"); desejado, vendido e arquivado "
                    . "vão para fora_da_colecao (recurso=hoje) e para a página Desejos. Um campo com so_desejado=1 (o preço-alvo, a prioridade) vale só "
                    . "no desejado: campos_do_relogio o deixa de fora nos outros, e a gravação dos valores também. A lista de desejos (recurso=desejos) "
                    . "vem da prioridade mais alta para a mais baixa (a ordem das opções do campo prioridade_desejo; sem prioridade, no fim), depois "
                    . "pelo nome, com a soma dos preços-alvo; no painel do desejado, o quadro Desejo e os documentos tomam o lugar dos do dia a dia.",
                "Relógio: nome (até 120), grupo, situação na coleção, disponível (fora do rodízio e dos avisos quando não), a marca da cópia no banco dos documentos, a foto "
                    . "(JPEG, PNG ou WebP até 4 MB; o navegador reduz para 1200 px antes de enviar). Excluir apaga junto os valores, a foto, os "
                    . "documentos (e os arquivos), os lançamentos, o plano e os critérios próprios.",
            ]),
            $secao("lancamentos", "Os tipos de lançamento, os lançamentos e a linha do tempo", [
                "Formatos (listas.formatos_de_lancamento): instantâneo (corda, troca de pilha), com valor (a leitura de carga, com unidade) e sessão, "
                    . "com início e fim (no pulso, no winder, no sol). Só a sessão tem \"fecha às\" (a hora em que a esquecida aberta fecha sozinha) e "
                    . "pode ser exclusiva (abrir uma exclusiva fecha as outras exclusivas abertas no mesmo instante: o relógio fica num lugar só). Só "
                    . "o com valor pode medir o gasto. A condição (\"vale quando\") é uma fórmula: o tipo só vale para os relógios em que ela dá verdadeiro.",
                "Lançar: lancar (instantâneo ou com valor, num instante até agora), iniciar e encerrar (uma sessão; encerrar não antes do início), "
                    . "alternar (a sessão aberta se fecha, a fechada se abre: um botão só num atalho de celular), periodo (uma sessão inteira que já "
                    . "passou, sem sobrepor outra exclusiva), alterar e excluir (corrigir uma marcação). Em todas, relogio_id=hoje é o relógio do dia "
                    . "pelo plano. O formato de um tipo não muda depois de ter lançamentos; um tipo com lançamentos não é excluído.",
                "A origem: manual (pela tela ou pela API), rodizio (a sessão no pulso do relógio do dia, aberta pelo sistema ou pelo Pôs no relógio do "
                    . "dia) e importado.",
                "A linha do tempo de um relógio divide o histórico em trechos contínuos: em uso pelo rodízio, no pulso fora do rodízio, em cada tipo "
                    . "de sessão (no winder, no sol...) e em repouso (o que sobra); e as marcações (corda, carga, pilha, revisão) como pontos, sem "
                    . "duração. O resumo dá o tempo e a porcentagem em cada estado.",
                "O estado de agora de um relógio: em uso (uma sessão no pulso aberta), numa sessão (outra sessão aberta: o estado é o nome do tipo, "
                    . "\"No sol\", \"No winder\", \"No carregador\"; duas juntas, \"No sol · No winder\") ou em repouso; na tabela da página Hoje, o "
                    . "indisponível em repouso aparece como indisponível. Com o desde quando: o começo da sessão no pulso, o da sessão mais recente, ou "
                    . "o fim da última no pulso (\"Em repouso desde 21:40\"). A sessão esquecida aberta conta só até o \"fecha às\" dela. O pulso é o "
                    . "único tipo que o código conhece pelo identificador; os outros entram pelo nome.",
            ]),
            $secao("formulas", "O motor das fórmulas", [
                "A sintaxe é a de uma planilha: números com vírgula ou ponto, textos entre aspas, ; separando os argumentos, + - * / ^ (a potência "
                    . "associa à direita), as comparações = <> < <= > >= (dão 1 ou 0), o sinal, os parênteses, as variáveis e as funções "
                    . "(listas.funcoes_das_formulas). A precedência, da mais fraca para a mais forte: comparação, soma, produto, potência, sinal.",
                "Um valor vazio se propaga pela conta (vazio + 1 = vazio); a divisão por zero dá vazio. MIN e MAX ignoram os vazios. PADRAO(x; outro) "
                    . "troca o vazio. Um erro de escrita diz a posição (\"falta fechar as aspas\", \"\\\";\\\" sobrando na posição 12\").",
                "As variáveis de um relógio: os campos (o valor usado), as fórmulas (pelo identificador; uma fórmula pode usar outra, sem ciclo) e as "
                    . "que o sistema dá. Cada fórmula pode ter uma versão por grupo; vale a do grupo mais perto do relógio (a dele, senão a de cima, ... "
                    . "senão a de todos). Uma fórmula que não tem versão para o relógio fica vazia nele.",
                "As funções de histórico leem os lançamentos do relógio até o instante da conta (o primeiro argumento é o identificador do tipo, entre "
                    . "aspas). ACUMULA percorre o histórico como um saldo: começa no início, perde por hora, ganha por hora em cada sessão da lista, "
                    . "passa a valer o efeito num instantâneo da lista, sempre entre 0 e o máximo. É ela que faz a reserva de marcha e a carga de luz.",
                "Testar: a mesma fórmula calculada em todos os relógios (ou nos escolhidos), sem gravar, com o caminho da conta (rastro). Antes de "
                    . "gravar uma fórmula, o sistema a lê (erro de escrita) e confere as variáveis e os tipos que ela usa. Renomear ou excluir um campo, "
                    . "um tipo ou uma fórmula que outra fórmula usa é recusado, com a lista de quem usa.",
                "O tempo nas contas é em dias: HOJE() é a meia-noite local de hoje, AGORA() o instante, e as datas dos campos viram dias pelo mesmo "
                    . "relógio. As horas viram dias dividindo por 24.",
            ]),
            $secao("avisos", "Os avisos e a visão de cada relógio", [
                "Um aviso tem uma fórmula que dá a data prevista (expressao, em dias), uma condição (vale quando), a antecedência (0 a 3650 dias), o "
                    . "texto (até 300, com {relogio}, {data}, {quando} e {limite}), o tipo de lançamento que resolve (o botão na tela Hoje), se está ativo, "
                    . "uma versão por grupo (como as fórmulas), e como a escala simula a solução (escala: nao, uso ou sempre; o valor ou as horas "
                    . "simuladas) e se entra na agenda só dentro da janela de antecedência ou sempre.",
                "Um relógio fora da coleção (desejado, vendido, arquivado) não tem aviso nenhum (o emprestado tem). O aviso vale para um relógio disponível quando a condição dá verdadeiro e a data prevista existe; sai hoje quando a data menos a "
                    . "antecedência já chegou. Estado: atrasado (a data passou) ou em breve. O aviso de um relógio com uma sessão aberta à mão "
                    . "(carregando, no sol) não vai para a agenda enquanto ela estiver aberta: a data andaria a cada conta.",
                "Os avisos de carga usam o limite de carga: o do relógio (campo carga_minima), senão o geral da Configuração (carga_limiar); o solar "
                    . "usa sol_limiar. A data do Carregar é pelo gasto do estado de agora (no pulso, o de uso; guardado, o de guardado); no carregador, "
                    . "o Carregar some e vale o Tirar do carregador (carregado: vence quando a bateria enche no carregador).",
                "Um aviso pode ser \"na hora\" (na_hora): além do alerta da manhã, o cron manda pelo Telegram no minuto em que ele vence, uma vez, e "
                    . "guarda quais já foram (config.na_hora_enviados, relogio_id:identificador); um aviso sai da lista quando deixa de vencer. O botão "
                    . "do aviso na página Hoje vem da API (resolve.acao e resolve.botao): a sessão que resolve, aberta, se encerra (\"Tirou do "
                    . "carregador\"); fechada, se inicia (\"Pôs no sol\"); a leitura se informa.",
                "O aviso Encostado (encostado, de todos os relógios, resolve com o pulso) é só cadastro: a data prevista é PADRAO(ULTIMA_DATA(\"pulso\"); "
                    . "data_compra) + CONFIG(\"encostado_dias\") (a Configuração, padrão 30), antecedência 0; sem nunca ter ido ao pulso nem data da "
                    . "compra, não avisa.",
                "A visão de cada relógio (a tabela da página Hoje e o painel): em uso ou em repouso desde quando, a carga estimada agora e de onde vem "
                    . "(a fórmula energia e a origem), a situação (frases curtas: autonomia restante, reserva...), a próxima manutenção (o aviso mais "
                    . "perto) e o que fazer.",
            ]),
            $secao("gasto", "O gasto medido pelas leituras e a autonomia", [
                "Cada leitura de carga de um tipo que mede o gasto é comparada com a anterior do mesmo relógio: o intervalo vira uma medição, com a "
                    . "queda, as horas no pulso e as horas fora do pulso no intervalo (o horário de uso conta como pulso sozinho quando o pulso sozinho "
                    . "está ligado; as marcações Pôs e Tirou mandam). Uma subida é recarga: novo ponto de partida, sem medir. Só entram intervalos de 1 "
                    . "hora ou mais; o curto entra com peso pequeno. Um intervalo com outra sessão no meio (o carregador) não mede: a medição só separa pulso "
                    . "e guardado.",
                "Cada medição é uma conta com dois desconhecidos: queda = dias de uso × gasto em uso + dias fora × gasto fora. Com as medições da janela "
                    . "(medicao_janela_dias, padrão 90), os dois gastos saem juntos por mínimos quadrados, cada medição pesando as horas que cobriu. "
                    . "Quando elas não separam os dois (só leituras guardado, por exemplo), vale a média das medições de cada um; sem nenhuma na janela, "
                    . "as de antes; sem nenhuma, o do cadastro. O resultado também é dado na janela anterior, para comparar (a bateria envelhecendo).",
                "MEDIDO(\"uso\") e MEDIDO(\"repouso\") dão esses gastos às fórmulas; corrigir uma marcação de pulso recalcula as horas das medições "
                    . "que cobrem aquele trecho.",
                "A autonomia: cheio pelo cadastro e pela conta (com o gasto medido), quando acaba seguindo o plano (simulando os dias do plano), quanto "
                    . "dura no pulso sem tirar e guardado; e a previsão do smartwatch (a carga dia a dia, até o limite).",
            ]),
            $secao("criterios", "Os critérios e a nota", [
                "Um conjunto de critérios por lugar: todos os relógios, um grupo ou um relógio. O relógio usa o conjunto mais perto dele, inteiro (o "
                    . "dele, senão o do grupo, senão o de cima, ..., senão o de todos).",
                "O conjunto tem parâmetros com peso (somam 100%); cada parâmetro tem subparâmetros com peso (somam 100% dentro dele); cada subparâmetro "
                    . "lê uma variável (um campo ou uma fórmula) e transforma o valor em nota (0 a 100) pelas faixas: de um número até outro (a primeira "
                    . "começa em 0, sem buracos nem sobreposição, só a última sem limite) ou por categoria (campo de lista ou texto).",
                "A nota do parâmetro é a média das notas dos subparâmetros, pelo peso; a nota do relógio, a média dos parâmetros, pelo peso. Um "
                    . "subparâmetro sem valor (campo vazio, valor fora de toda faixa) sai da conta e os outros dividem o peso dele. A conta inteira de "
                    . "cada nota é mostrada (cada faixa, cada peso, cada ponto).",
                "Restaurar os critérios iniciais apaga os de todos os lugares e volta aos do cadastro inicial.",
            ]),
            $secao("rodizio", "Os modos de rodízio, o plano e a escala inteligente", [
                "Hoje sem relógio (rodizio sem_relogio): o plano de hoje sai e hoje vira um dia livre; a sessão do rodízio de hoje que já "
                    . "começou fecha agora (o tempo dela conta como uso), a que ainda não começou sai; a sessão do dia (o pulso sozinho) não abre "
                    . "num dia sem plano, e o garantir_plano não põe de volta como plano de hoje o relógio que esteve no pulso pelo rodízio "
                    . "num dia livre. Os outros dias não mudam. Desfaz: trocar_dia (ou usando) de hoje, ou resortear_hoje.",
                "Um modo é feito de blocos de dias da semana (cada dia em um bloco só). Cada bloco diz de onde escolher (um grupo, a coleção toda ou um "
                    . "relógio fixo) e se é um relógio por dia ou um para o bloco inteiro. A forma de escolha do modo: inteligente (a maior nota), "
                    . "ponderado (sorteio com a nota como chance), aleatório ou fifo (quem espera há mais tempo). Com o ciclo ligado, um relógio só volta "
                    . "depois que todos os disponíveis do bloco passaram. No máximo um modo em uso.",
                "A garantia de rodízio (max_sem_uso, padrão 21) vale antes da nota: candidato além desse limite de dias sem uso ganha direto, o mais "
                    . "parado primeiro. Os dias sem uso de um relógio nunca usado contam desde a compra (sem data de compra: 9999).",
                "O plano guarda o relógio de cada dia, o bloco, a origem (sorteio ou manual), a ação do dia e o motivo, numa frase (\"Garantia de "
                    . "rodízio: ...\", \"Sorteio pela nota, entre 5 candidatos: nota 70,2, 16,4% de chance\", \"Escolhido à mão\"). O cron monta o dia que "
                    . "falta (à 0h da segunda, a semana nova); trocar o relógio de um dia o marca como manual; sortear de novo refaz o período; usando "
                    . "hoje põe outro relógio no período atual e refaz o plano.",
                "Um período escolhido à mão (rodizio, acao=periodo): de, até (de hoje até 2 anos), os relógios na ordem e quantos dias seguidos cada "
                    . "(dias_cada): cada dia do período fica manual, com o relógio da vez (depois do último, volta ao primeiro; hoje, ele passa a ser o "
                    . "do pulso a partir de agora); na escala, ela é refeita a partir do começo do período. De amanhã em diante: periodo_sortear "
                    . "apaga o que o período tem (à mão, sorteado ou livre) e sorteia de novo; periodo_livre apaga e deixa os dias livres (a tabela "
                    . "plano_livre: sem relógio e sem sorteio; o sorteio e a escala pulam esses dias, e a escala não estica um bloco sobre eles). "
                    . "Escolher um relógio para um dia livre (trocar_dia, periodo, usando) o tira de plano_livre; trocar_dia 0 também (volta ao "
                    . "sorteio). Os dias manuais e os livres de amanhã em diante ficam quando se sorteia de novo ou se monta a próxima semana (hoje, "
                    . "o sortear de novo sorteia); trocar de modo refaz os sorteados. A API dá os dias livres em plano.livres e hoje.livres (as telas os "
                    . "põem entre os dias do plano, como \"livre: escolha à mão\").",
                "O Rodízio manual (a última opção da lista Modo de rodízio da página Hoje; não é um modo: fora do período vale o modo ativo): de e "
                    . "até (de hoje até 2 anos); recurso=rodizio_manual dá os dias de 7 em 7 (pagina), cada um com o que o plano tem e a escolha de "
                    . "partida do seletor: o relógio escolhido à mão, livre (dia livre) ou auto (Decida por mim: o dia sorteado ou sem relógio). "
                    . "rodizio acao=manual grava o período inteiro: dias[data] = o id, auto ou livre (hoje não); o dia que não vem fica com a escolha "
                    . "de partida. Os dias com relógio ficam manual (\"Escolhido à mão no rodízio manual de DD/MM a DD/MM.\"); os livres vão para "
                    . "plano_livre; hoje com outro relógio passa a ser o do pulso a partir de agora (hoje em auto continua o que está); na escala, ela "
                    . "é refeita a partir do começo. O Decida por mim decide ao gravar e grava manual, com o motivo (\"Decida por mim: a maior nota "
                    . "(62,9), entre 4 candidatos. O de ontem (X) ficou de fora.\" ou \"Decida por mim, pela garantia de rodízio: parado há 33 dias, "
                    . "além do limite de 15 dias sem uso, ...\"): de hoje até o fim do período, dia a dia, cada dia vira uma sessão simulada no pulso "
                    . "(os do plano antes do período, os escolhidos e os decididos), e no dia auto: os disponíveis, menos o de ontem e o escolhido "
                    . "para amanhã (se sobrar outro); a garantia de rodízio na frente (o mais tempo parado além de max_sem_uso); senão a maior nota no "
                    . "começo do uso do dia; empate: o menor id. A resposta diz quantos dias foram escolhidos, decididos, livres e quantos ficaram "
                    . "como já estavam.",
                "Apagar a programação (página Plano e quadro Modo de rodízio): apagar um período (periodo_sortear: sai tudo o que ele tem, de amanhã "
                    . "em diante, e o modo sorteia de novo; periodo_livre: sai tudo e os dias ficam livres) e apagar tudo (rodizio apagar_tudo: sai toda a "
                    . "programação de amanhã em diante, à mão, sorteada e livre; com hoje=1, \"Excluindo hoje\", de hoje em diante; depois o modo ativo "
                    . "monta o plano de novo, gerar_escala na escala e garantir_plano até domingo no sorteio; hoje, se mudar, o novo vai para o pulso a "
                    . "partir de agora, como no resortear_hoje).",
                "As listas longas de dias vêm da API uma página por vez (plano_pagina): os Próximos dias (hoje.proximos e recurso=proximos, de hoje "
                    . "até o fim do plano) e o dia a dia da página Plano (plano.dia_a_dia, com o filtro relogio), com os dias livres no meio (origem "
                    . "livre; sem eles no filtro de relógio), cada dia com o dia da semana (dia_semana 1 a 7, dia_semana_curto); o tamanho da página é o "
                    . "Mostrar até da Configuração (config proximos_por_pagina, padrão 7; plano_por_pagina, padrão 31; 1 a 366; por_pagina no pedido "
                    . "vale por cima), e o recurso=config devolve os dois já com o padrão (mostrar_ate).",
                "A escala inteligente (um modo com escala_dias de 7 a 730) planeja tudo de uma vez simulando dia a dia: a nota de cada relógio com o "
                    . "estado simulado daquele dia; a escolha (o smartwatch fica os dias que a bateria aguenta, até 7); a sessão no pulso no horário de uso "
                    . "e o efeito dela em todos; os avisos (se o escolhido vai precisar de carga ou corda, anota a ação no dia e simula o lançamento que "
                    . "resolve). Toda manhã o cron refaz a escala a partir do estado real. Notas iguais na maior (o empate exato) se desempatam por "
                    . "sorteio, e o motivo diz \"Empatado na nota com N outros: saiu no sorteio (<fonte>).\"",
                "O sorteio (sorteio(min, max): o modo aleatório, o sorteio pela nota e o desempate da escala) usa números aleatórios de fora, nesta "
                    . "ordem: RANDOM.ORG (www.random.org/integers/?num=64&min=0&max=65535&col=1&base=10&format=plain&rnd=new: ruído atmosférico); "
                    . "senão o ANU QRNG (qrng.anu.edu.au/API/jsonI.php?length=64&type=uint16, o endereço antigo, sem chave: {\"success\": true, "
                    . "\"data\": [...]}; quântico); senão o gerador seguro do PHP (random_int). Um lote de 64 números de 16 bits por pedido, na memória "
                    . "do pedido; cada sorteio junta quantos pedaços de 16 bits precisar e descarta o valor que passa do último múltiplo inteiro da faixa "
                    . "(sem viés). Cada serviço tem 4 segundos; a resposta tem de vir completa e dentro da faixa, senão vale como falha. Se os dois "
                    . "falham, a config aleatorio_fora guarda quando, e por 10 minutos o sistema nem tenta (usa o do PHP); a config aleatorio_ultima "
                    . "guarda a fonte do último lote e quando (recurso=config: aleatorio.ultimo_lote, aleatorio.fora_desde; a Configuração mostra no "
                    . "quadro Sorteios). O motivo de cada dia sorteado termina com a fonte entre parênteses (\"... todos com a mesma chance "
                    . "(RANDOM.ORG).\"). Com RELOGIOS_SEMENTE (os testes), mt_rand com a semente, sem rede (a fonte: \"semente de teste\"). O que é "
                    . "segredo (o sal das senhas, os nomes dos arquivos dos documentos) fica no gerador do PHP: número que viaja pela internet não "
                    . "serve para segredo.",
            ]),
            $secao("pulso", "O pulso sozinho e as sessões esquecidas", [
                "O horário de uso (uso_inicio e uso_fim) diz quando o relógio do dia está no pulso. Pôr sozinho (pulso_auto_inicio): no início do horário, "
                    . "o cron abre a sessão no pulso do relógio do dia (origem rodizio). Tirar sozinho (pulso_auto_fim): o \"fecha às\" do tipo No pulso "
                    . "segue o fim do horário. Desligados, só as marcações Pôs e Tirou contam; o que foi marcado sempre vale. Com o relógio do dia noutra "
                    . "sessão exclusiva aberta (no carregador, no sol, no winder), o pôr sozinho espera; se ele saiu de uma depois do início do horário, "
                    . "a sessão no pulso começa quando ele saiu.",
                "Toda sessão esquecida aberta fecha sozinha no \"fecha às\" do tipo dela (a do sol, em sol_fim).",
            ]),
            $secao("cron", "O cron", [
                "A cada minuto: confere o token, o fuso e as migrações (pendente: registra e para); apaga execuções antigas (sem atividade, 7 dias; com "
                    . "atividade ou erro, 1 ano); monta o dia do plano que falta; fecha as sessões esquecidas; abre a sessão no pulso do dia; dispara os "
                    . "eventos personalizados no minuto marcado (depois de uma parada de até 7 dias, sai uma vez só, sem repetir); a manutenção dos "
                    . "documentos.",
                "Na hora da manhã (horario_manha), uma vez por dia: refaz a escala a partir do estado real, manda o Telegram do dia (o relógio de hoje e "
                    . "os avisos) e sincroniza a agenda. Na hora da noite (horario_noite): o Telegram da véspera (o relógio de amanhã e o que preparar) e a "
                    . "sincronização; conta os documentos perdidos; e, com a BACKUP_PASTA no config.php, faz a cópia de segurança do banco.",
                "Os avisos na hora, a cada minuto: os vencidos agora (de todos os relógios) que ainda não foram vão pelo Telegram.",
                "A cópia de segurança: um arquivo relojoeiro-AAAA-MM-DD-HHMMSS.jsonl.gz na BACKUP_PASTA (fora da pasta publicada: dentro dela, "
                    . "recusada), JSON Lines comprimido, o mesmo nos quatro bancos: a primeira linha é o cabeçalho (relojoeiro_copia 1, criada, banco, "
                    . "versao: a última migração, documentos, tabelas); depois, para cada tabela, uma linha {tabela, colunas, binarias} e uma linha "
                    . "por registro com a lista dos valores (binários em base64). As tabelas vêm na ordem em que voltam (a citada antes da que cita; "
                    . "a que cita a si mesma, a árvore, volta em rodadas). Ficam as BACKUP_MANTER mais novas (padrão 7). O conteúdo dos documentos "
                    . "(documento_parte) só com BACKUP_DOCUMENTOS = true. Restaurar: só da mesma versão; antes, uma cópia do que está agora; depois, "
                    . "numa transação, apaga tudo (na ordem inversa) e grava a cópia; sem o conteúdo dos documentos, documento.no_banco volta a 0.",
                "Mudo: avisos do PHP e erros entram no registro, nunca na saída. Grava em cron_execucao o que fez (só quando fez algo, ou deu erro), o "
                    . "último erro em config.cron_erro (sai na primeira execução sem erro) e a última rodada com atividade em config.cron_registro. Erro "
                    . "fatal sem banco vai para um arquivo na pasta temporária. Opções: --forcar (manhã e noite agora) e -v (mostra o registro).",
            ]),
            $secao("mensagens", "As mensagens, a agenda e os eventos personalizados", [
                "Dois canais (listas.canais): Telegram (tg) e Google Agenda (ag). Cada tipo de aviso (dia, vespera, o identificador de cada aviso e ev<id> "
                    . "de cada evento personalizado) é marcado ou não para cada canal, e pode ter mensagem própria no canal; sem ela, vale a mensagem "
                    . "padrão do canal (tg_padrao, ag_padrao). As mensagens têm âncoras entre chaves (listas.ancoras), trocadas pelos valores na hora.",
                "O Telegram vai por uma API de mensagem (MSG_ENDPOINT): GET com destinatario, titulo e mensagem; partes de até 3000 caracteres (as "
                    . "mensagens do mesmo horário vão juntas, separadas por uma linha em branco).",
                "A agenda: uma conta de serviço do Google (a chave JSON no servidor; token por JWT RS256, escopo do Calendar). O sistema calcula o que "
                    . "tem de estar na agenda (de hoje até agenda_antecedencia dias: os avisos marcados para a agenda, no horário da manhã do dia "
                    . "previsto; o relógio do dia e a véspera, pelo plano; as ocorrências dos eventos), cada item com uma chave estável; cria o que falta, "
                    . "atualiza o que mudou (pela assinatura), apaga o que deixou de valer; o que já passou fica. Na agenda, a primeira linha do modelo é "
                    . "o título e o resto a descrição.",
                "Evento personalizado: nome, ativo, repetição (listas.repeticoes), data (uma vez; a cada N dias: a partir dela), hora, dias da semana, "
                    . "dia do mês (1 a 31; mês sem o dia, o último), intervalo (1 a 3650), relógio opcional (com ele, as âncoras do relógio funcionam). "
                    . "Só o que a repetição usa fica gravado. Novo, já vai pelo Telegram.",
            ]),
            $secao("documentos", "Os documentos de cada relógio", [
                "Qualquer arquivo, cada um numa categoria (as que aceitam só algumas famílias recusam as outras), com título, data e descrição. O tipo "
                    . "vem do conteúdo do arquivo; quando o conteúdo diz pouco (texto, binário genérico, zip), da extensão. A família decide como abre: imagem (galeria com visor em tela cheia e apresentação, com "
                    . "a miniatura feita no navegador), vídeo (player, em sequência na ordem escolhida), PDF (visualizador), XML (o resumo da NF-e: "
                    . "emitente, número, série, data, valor, chave e produtos; lido sem baixar nada de fora), o resto para baixar. Inline só o que o "
                    . "navegador mostra sem rodar nada (HTML, SVG e XML sempre como download); atende pedido de pedaço (Range).",
                "Os arquivos ficam numa pasta fora da publicada (DOCUMENTOS_PASTA), em r<id do relógio>/ com nome aleatório. Limite por arquivo: "
                    . "DOCUMENTOS_LIMITE (padrão 100 MB) e os do PHP e do servidor web.",
                "A cópia no banco (documento_parte, pedaços de 4 MB, com o SHA-256): três níveis, e o de cima vale sobre os de baixo. O sistema "
                    . "(DOCUMENTOS_COPIA_BANCO: true todos; false nenhum; ausente, decidem os de baixo), o relógio (copia_banco: todos os dele) e o "
                    . "arquivo (copia_banco: só ele). A tela só mostra a escolha de quem decide. A cópia entra na hora no envio e em editar; pelo relógio, o "
                    . "cron copia aos poucos; quando ninguém mais pede, sai, mas só se o arquivo da pasta confere com ela (o documento nunca fica sem as duas).",
                "A manutenção (o cron, a cada minuto, até 256 MB por rodada): o arquivo que sumiu da pasta volta da cópia (conferindo o SHA-256); a "
                    . "cópia pedida que falta é feita; a que ninguém pede sai; a pasta de um relógio que não existe mais sai inteira; o arquivo que não é de "
                    . "nenhum documento sai (só depois de 10 minutos: um envio pode estar no meio). Os perdidos (sem arquivo e sem cópia) são contados à noite.",
            ]),
            $secao("estatisticas", "As estatísticas", [
                "recurso=estatisticas conta o tempo no pulso de cada relógio em cada dia do período (as sessões do tipo pulso partidas na "
                    . "meia-noite, a aberta até agora; as marcações antigas no pulso contam o dia, sem horas). Dela saem: os dias de uso e as horas "
                    . "de cada relógio, a parte de cada um, a barra (comparada com o mais usado), a tendência (a segunda metade do período contra a "
                    . "primeira: até 20% ou menos de 1 h de diferença é estável; menos de 1 h no total ou período de menos de 14 dias, poucos "
                    . "dados), o uso por dia da semana e por mês (com a média por dia e a barra comparada com o maior) e o resumo. O custo por uso e "
                    . "os dias de uso desde sempre são as fórmulas custo_por_uso e dias_de_uso. A coleção (na coleção e emprestados) é dividida "
                    . "por grupo, marca, ano da compra, situação e cada campo de lista (menos os só dos desejados). Período padrão: os últimos 90 "
                    . "dias; até hoje; no máximo 3661 dias; de=tudo: desde o primeiro uso.",
            ]),
            $secao("telas", "As telas", [
                "A especificação completa, tela por tela, está em telas (abaixo): o visual com os valores exatos (cores, letras, controles, "
                    . "componentes, larguras), o menu, e cada página com o endereço, o que ela lê e grava na API, e as partes em ordem com cada "
                    . "tabela, campo, botão e mensagem. Aqui, o resumo.",
                "Hoje: no alto, o destaque: o relógio do dia ou, com destaque_hoje = pulso, o que está no pulso agora (a API escolhe: destaque.relogio_id) "
                    . "(com o Pôs e o Tirou), \"Hoje é dia de\" (os avisos com o botão que resolve), os próximos dias com o motivo e o "
                    . "trocar por…, o modo de rodízio (troca ali mesmo), e a tabela da coleção com filtros e ordenação; clicar num relógio abre o painel "
                    . "dele, ao lado da lista ou numa janela flutuante (painel_modo).",
                "O painel (e a ficha, a mesma coisa numa página): a cabeça (foto, grupo, nome, marcas), quadros alinhados numa grade (Agora, Previsão, "
                    . "Gasto da bateria, Autonomia, Rodízio, Compra, Documentos, Próximas manutenções), cada um com rótulo e valor em duas colunas; o "
                    . "quadro Marcar (um menu só, \"O que você quer marcar?\", e embaixo só os campos daquilo); Editar cadastro; o gráfico da carga com as "
                    . "medições; os dados do relógio (o cadastro com a origem de cada valor e o resultado de cada fórmula com a versão); o histórico recente.",
                "Plano, Histórico, Documentos, Configuração (quadros com uma opção por linha e a barra de salvar presa no pé), Critérios, Grupos, "
                    . "Cadastros, Execuções do cron, Usuários e Ajuda (as seções do README).",
                "Três tamanhos com o mesmo sistema visual: até 900 px (celular e tablet de pé) o menu fica recolhido, as tabelas viram cartões e o "
                    . "relógio abre em tela cheia (o voltar do aparelho fecha); de 901 a 1199 px o painel ao lado desliza por cima, pela direita. A "
                    . "mesma informação em todos os tamanhos: nenhuma coluna, filtro ou dia é escondido por falta de espaço. A tabela dos relógios tem "
                    . "sempre as 11 colunas; quando a tabela inteira não cabe na largura que tem (medida com os dados, com ou sem o painel aberto), "
                    . "cada relógio vira um cartão com os mesmos 11 campos na mesma ordem, cada um com o nome da coluna em cima (2 colunas no "
                    . "celular, 5 no tablet), os títulos viram botões de ordenar e os 10 filtros ficam todos. Campos com letra de 16 px no "
                    . "celular (o iPhone não aproxima a tela).",
            ]),
            $secao("seguranca", "Segurança", [
                "Todo pedido da API exige autenticação (o token ou o login do site); as páginas são só o esqueleto, sem dado nenhum, e quem "
                    . "chega sem login vai para o login da API. O cron e os scripts só pela linha de comando. Os arquivos internos (núcleo, banco, "
                    . "configuração, scripts, testes, dados, os que começam com ponto) ficam bloqueados no servidor web (.htaccess e nginx-relogios.conf).",
                "SQL sempre com parâmetros; textos escapados no HTML; XML da NF-e lido sem entidades nem rede; arquivos servidos inline só dos tipos "
                    . "seguros; senhas com password_hash; o token comparado em tempo constante.",
                "Senhas: só o hash, pelo password_hash do PHP (bcrypt, \$2y\$<custo>\$<sal de 22><resultado de 31>); o login confere com o "
                    . "password_verify (o bcrypt da senha digitada com o sal e o custo do hash). A API entrega o login e o hash de cada usuário "
                    . "(recurso=usuarios: senha_hash, senha_algoritmo, senha_custo) e aceita criar um usuário pelo hash de outro sistema (usuarios/salvar "
                    . "com senha_hash; bcrypt \$2y\$, \$2b\$ ou \$2a\$, ou argon2; o \$2b\$ guardado como \$2y\$, o mesmo cálculo). A reescrita "
                    . "tem de conferir pelo mesmo bcrypt, para os usuários migrarem com a mesma senha. Os arquivos dos documentos só pelo recurso=documento.",
            ]),
            $secao("testes", "Os testes e a aceitação", [
                "testes/cenario.php faz pela API o que uma pessoa faz (cadastra relógios de cada tipo, lança cordas, cargas, sol, winder e pilha, monta a "
                    . "escala, mexe nos critérios, nos eventos e nas fotos, roda o cron) e tira a fotografia de tudo o que a API devolve; "
                    . "testes/comparar.php compara duas fotografias. Com RELOGIOS_SEMENTE (o sorteio fica previsível), o roteiro rodado em MySQL, "
                    . "PostgreSQL, SQLite e Firebird, num banco recém-instalado, tem de dar as mesmas respostas; e a reescrita, as mesmas da versão atual. "
                    . "testes/paridade.sh faz tudo com um comando (os bancos recriados, a instalação, o usuário, os servidores, o roteiro e a comparação), "
                    . "com o config de teste apontado pela variável de ambiente RELOGIOS_CONFIG (o núcleo carrega o arquivo que ela diz; sem ela, o config.php).",
                "Além disso: nenhuma escrita do roteiro recusada; o dicionário da ajuda explica todos os campos das respostas; as telas sem erro de "
                    . "JavaScript e sem rolagem lateral no celular, no tablet e no computador.",
                "A documentação da API tem uma fonte só: o dicionário dos campos da ajuda (recurso=ajuda, campos); o do cabeçalho do api.php é "
                    . "gerado dele (testes/dicionario.php --gravar; sem o --gravar, confere). testes/campos.php confere que todo campo das respostas "
                    . "está explicado. Os dois rodam no testes/paridade.sh.",
                "testes/layout.cjs abre todas as telas (com os quadros recolhidos abertos e cada opção dos menus que mostram partes) num navegador, "
                    . "em 15 tamanhos de 320 a 1920px, e mede o que quebra: a página que rola de lado, o elemento que sai da caixa em que está, a "
                    . "etiqueta partida, o texto cortado, o botão com o texto em duas linhas e o que fica por cima de outro. O esperado: 0 problemas.",
            ]),
        ],
        // cada página com os arquivos dela (lidos da própria página: nunca ficam atrás)
        "telas" => array_merge(RECONSTRUCAO_TELAS, ["paginas" => array_map(function ($p) {
            return array_merge($p, ["codigo" => codigo_da_pagina($p["pagina"])]);
        }, RECONSTRUCAO_TELAS["paginas"])]),
        "codigo" => codigo_lista(false),
        "modelo_de_dados" => [
            "banco" => banco_tipo(),
            "como_ler" => "Cada tabela com as colunas (nome, tipo no dialeto do banco em uso, se aceita vazio, o padrão), a chave primária, as chaves "
                . "estrangeiras (a coluna, a tabela.coluna de referência e o que acontece ao apagar a linha de cima: CASCADE some junto, SET NULL "
                . "desfaz a ligação), as chaves únicas, os índices e as regras de validação (CHECK). As colunas de texto curto não diferenciam "
                . "maiúsculas nem acentos. Três referências ficam pelo nome, de propósito: as fórmulas e os critérios citam campos e fórmulas pelo "
                . "identificador, e canal_aviso.tipo cita o aviso pelo identificador (vale para todas as versões dele).",
            "tabelas" => $tabelas,
        ],
        "listas" => [
            "funcoes_das_formulas" => $funcoes,
            "tipos_de_campo" => $lista($TIPOS_CAMPO, "tipo"),
            "formatos_de_lancamento" => $lista($FORMATOS_LANCAMENTO, "formato"),
            "repeticoes" => $lista($REPETICOES, "repeticao"),
            "dias_da_semana" => array_map(function ($n, $nome) { return ["dia" => (int)$n, "nome" => $nome]; }, array_keys($DIAS_SEMANA), $DIAS_SEMANA),
            "ancoras" => $lista($ANCORAS, "ancora"),
            "canais" => array_map(function ($k, $c) { return ["canal" => $k, "nome" => $c["nome"], "ajuda" => $c["ajuda"]]; }, array_keys($CANAIS), $CANAIS),
            "migracoes" => $migracoes,
            "config_php" => array_map(function ($k, $v) { return ["constante" => $k, "descricao" => $v]; }, array_keys(RECONSTRUCAO_CONFIG_PHP), RECONSTRUCAO_CONFIG_PHP),
        ],
    ];
}
