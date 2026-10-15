<?php
// A reconstrução: o roteiro para reescrever a aplicação inteira do zero, em qualquer linguagem, banco ou framework, e
// chegar ao mesmo sistema. Sai pela API (recurso=ajuda&parte=reconstrucao, ou recurso=reconstrucao). O texto descreve o
// que o sistema faz e as regras de cada parte; o modelo de dados e as listas (funções das fórmulas, tipos, âncoras,
// repetições, canais, migrações) são lidos na hora do próprio banco e do código, então nunca ficam atrás da versão em uso.
// As telas (reconstrucao_telas()) são a especificação visual: o visual com os valores exatos, o menu e cada página com as tabelas, os
// campos, os botões e as mensagens; quem muda uma tela muda também a especificação dela aqui.
// A API (cada consulta, cada escrita e cada campo das respostas) está no recurso=ajuda; o cadastro inicial (campos, tipos,
// fórmulas, avisos, critérios e modos que vêm prontos) está no modelo.json e no recurso=cadastros e recurso=criterios.

// O que cada tabela guarda (o resto, as colunas e as regras, vem do banco)
function reconstrucao_tabelas()
{
    return [
    "acesso_dono" => t('quem vê todas as coleções de um dono, também as que ele criar depois, e desde quando'),
    "acessorio" => t('os acessórios de uma coleção: o nome, o tipo e a observação'),
    "acessorio_relogio" => t('o relógio de que um acessório é (sem linha: solto)'),
    "agenda" => t('as agendas de uma coleção: o tipo (google, caldav, outlook, ics), o nome, se está ativa, a janela em dias, o modelo do evento, o idioma e o evento de teste'),
    "agenda_aviso" => t('o que uma agenda recebe: cada tipo de aviso, se envia'),
    "agenda_caldav" => t('o CalDAV de uma agenda: o endereço do calendário, o usuário e a senha (cifrada)'),
    "agenda_evento" => t('os eventos personalizados que uma agenda recebe'),
    "agenda_google" => t('o Google Agenda de uma agenda: o id da agenda e a chave da conta de serviço (cifrada)'),
    "agenda_ics" => t('o link ICS de uma agenda: o token secreto do link'),
    "agenda_outlook" => t('o Outlook de uma agenda: a conta, o calendário e o token de renovação (cifrado)'),
    "agenda_sincronizado" => t('os eventos que o sistema criou em cada agenda: a chave (o que o evento é: o tipo, o relógio e o dia), o id no serviço, a data, o título e a assinatura (o resumo do conteúdo, para saber se mudou)'),
    "api_token" => t('os tokens de API de cada conta: o nome e o hash (o token só aparece ao criar), quando foi criado e o último uso'),
    "aviso" => t('os avisos do cadastro: o identificador, o nome, a fórmula da data prevista (expressao), a condição (vale quando), a antecedência em dias, o texto, se está ativo, o grupo da versão, a escala, a simulação, se vai para a agenda, o tipo de lançamento que o resolve e se avisa na hora'),
    "campo" => t('os campos do cadastro dos relógios, por árvore: o identificador (o nome nas fórmulas), o nome, o tipo, a unidade, o valor padrão, o grupo em que vale (a raiz: todos), a ordem e se vale só nos desejados'),
    "campo_opcao" => t('as opções de um campo do tipo lista, em ordem (sem repetir, sem diferenciar maiúsculas e acentos)'),
    "campo_valor" => t('o valor de cada campo em cada relógio (texto normalizado pelo tipo do campo: número com ponto, data AAAA-MM-DD, sim/não 1 ou 0)'),
    "colecao" => t('as coleções: o dono, a raiz da árvore que ela usa (uma árvore do mesmo dono), o nome (sem repetir entre as do dono) e quando foi criada'),
    "colecao_usuario" => t('os membros de uma coleção (o dono não entra aqui) e desde quando'),
    "config" => t('os valores soltos da Configuração de cada coleção (horários, limites, o idioma), chave e valor'),
    "convite" => t('os convites para uma coleção ou para tudo de um dono (um ou outro): o e-mail, quem convidou, o estado (pendente: espera o dono; aprovado: enviado), o hash do token do link e até quando vale'),
    "criterio_faixa" => t('as faixas de um subparâmetro: de um número até outro (ou sem limite) ou uma categoria, e a nota (0 a 100) que o valor ganha nela'),
    "criterio_parametro" => t('os parâmetros de um conjunto de critérios: o lugar (um grupo da árvore, a raiz para todos, ou um relógio: um ou outro), o nome, o peso (%) e a ordem'),
    "criterio_sub" => t('os subparâmetros de um parâmetro: o nome, a variável que mede (um campo ou uma fórmula), o peso dentro do parâmetro (%) e a ordem'),
    "cron_execucao" => t('cada execução do cron que registrou algo: início, fim, duração, o registro do sistema em texto, se teve erro e se teve atividade'),
    "cron_execucao_colecao" => t('o registro de cada coleção numa execução do cron, se teve erro e se teve atividade'),
    "destino" => t('os destinos das mensagens de uma coleção: o tipo (webhook, email, webpush), o nome, se está ligado, a mensagem padrão e o idioma'),
    "destino_aviso" => t('o que um destino recebe: cada tipo de aviso (dia, vespera, o identificador de um aviso), se envia e o texto próprio'),
    "destino_email" => t('para quem vai o e-mail de um destino'),
    "destino_evento" => t('os eventos personalizados que um destino recebe, com o texto próprio'),
    "destino_webhook" => t('o webhook de um destino: o método, o endereço, o formato (query, form, json) e o corpo JSON opcional'),
    "destino_webhook_cabecalho" => t('os cabeçalhos do pedido de um webhook (o valor cifrado: pode ter um token)'),
    "destino_webhook_campo" => t('os campos do pedido de um webhook, em ordem, com {titulo} e {mensagem} no valor'),
    "destino_webpush" => t('o usuário cujos aparelhos recebem o Web Push de um destino'),
    "documento" => t('os documentos de cada relógio: a categoria, o título, a data, a descrição, o nome e o tipo do arquivo, o tamanho, o caminho na pasta (arquivo e miniatura), se a cópia no banco está completa e se o arquivo pede a cópia'),
    "documento_categoria" => t('as categorias dos documentos (Manual, Nota fiscal, Fotos...), por árvore: identificador, nome e ordem'),
    "documento_categoria_aceita" => t('as famílias de arquivo que uma categoria aceita (imagem, video, audio, pdf, xml); nenhuma: qualquer arquivo'),
    "documento_parte" => t('a cópia de um documento dentro do banco, em pedaços de 4 MB (parte 0, 1, 2...; a parte -1 é a miniatura)'),
    "evento_dia" => t('os dias da semana de um evento personalizado semanal (1 segunda a 7 domingo)'),
    "evento_disparo" => t('cada ocorrência de um evento personalizado que já disparou (para não disparar duas vezes)'),
    "evento_personalizado" => t('os lembretes de uma coleção: nome, se está ativo, a repetição (uma vez, todo dia, em dias da semana, todo mês, a cada N dias), a data de início, a hora, o dia do mês e o intervalo'),
    "evento_relogio" => t('o relógio de um evento personalizado (sem linha: um evento geral)'),
    "formula" => t('as fórmulas do cadastro: o identificador (o nome nas outras fórmulas, nos avisos e nos critérios), o nome, a expressão, a unidade e o grupo da versão (a raiz: todos). A mesma fórmula pode ter uma versão por grupo; vale a do grupo mais perto do relógio'),
    "foto" => t('a foto de cada relógio (a imagem inteira, o tipo e quando foi trocada)'),
    "lancamento" => t('o histórico de cada relógio: o tipo, o início (o instante, num instantâneo), o fim (numa sessão; vazio: aberta), o valor (numa leitura) e a origem (manual, rodizio, importado)'),
    "lancamento_tipo" => t('os tipos de lançamento: identificador, nome, formato (instantâneo, com valor ou sessão), unidade, a hora em que a sessão esquecida fecha sozinha (fecha_as), o grupo, a ordem, se a sessão é exclusiva, se a leitura mede o gasto e a condição (vale quando)'),
    "medicao" => t('cada medição do gasto: o lançamento (a leitura) que a fechou, se mede o uso ou o repouso, o gasto achado (% por dia), as duas leituras, o intervalo, as horas no pulso e fora, o peso (horas) e se entra na média'),
    "modo" => t('os modos de rodízio de uma coleção: nome, a forma de escolha, a ordem, o horizonte da escala inteligente (escala_dias; vazio: o modo sorteia pelos blocos), o ciclo e a marca do modo em uso (1 no ativo, vazio nos outros)'),
    "modo_bloco" => t('os blocos de um modo: nome, de onde sortear (um grupo; a raiz: todos), um relógio por dia ou um para o bloco inteiro e a ordem'),
    "modo_bloco_dia" => t('os dias da semana de um bloco (1 segunda a 7 domingo; um dia em um bloco só)'),
    "modo_bloco_relogio" => t('o relógio fixo de um bloco (sem linha: sorteia)'),
    "no" => t('a árvore dos grupos: a raiz (sem pai) é a árvore inteira e guarda o dono; cada grupo aponta para o de cima; o nome e a ordem entre irmãos. Uma árvore serve a várias coleções do mesmo dono'),
    "pedido" => t('os pedidos de acesso de uma conta a uma coleção ou a tudo de um dono (um ou outro), com o recado e até quando espera'),
    "plano" => t('o relógio de cada dia de uma coleção: o relógio, a origem (sorteio ou manual), a ação do dia (o que fazer antes de usar), o motivo da escolha e quando foi gravado'),
    "plano_bloco" => t('o bloco do modo que escolheu o relógio do dia (sem linha: escolhido à mão)'),
    "plano_livre" => t('os dias livres do plano de uma coleção: sem relógio e sem sorteio (nem a escala), para escolher à mão'),
    "push_inscricao" => t('os aparelhos (navegadores) de cada conta inscritos no Web Push: o endereço, as chaves do navegador (p256dh, auth) e o nome do aparelho'),
    "relogio" => t('os relógios de uma coleção: nome, apelido (para distinguir dois iguais), grupo, se está disponível para o rodízio, a situação na coleção (posse), se pede a cópia no banco de todos os documentos e quando foi cadastrado'),
    "sistema" => t('os valores do sistema inteiro: as marcas do cron, da cópia de segurança, do aleatório, das migrações, o endereço do sistema, o idioma da instalação e os avisos de vencimento já mandados'),
    "usuario" => t('as contas: o login, o e-mail (para recuperar a senha, os convites e os avisos da conta), o hash da senha (password_hash), o papel (master: cuida do sistema inteiro; comum), o idioma, quando vence (a conta de demonstração; vazio: oficial), se está bloqueada, quando foi criada e o último acesso'),
    "usuario_token" => t('os links de Esqueci a senha: o hash do token e até quando vale (2 horas)'),
    ];
}

// As constantes do config.php
function reconstrucao_config_php()
{
    return [
    "DB_TIPO" => t('mysql (MySQL ou MariaDB, o padrão), pgsql (PostgreSQL), sqlite ou firebird (Firebird 3+)'),
    "DB_HOST, DB_PORTA, DB_NOME, DB_USUARIO, DB_SENHA" => t('o servidor do banco (MySQL, PostgreSQL e Firebird; no Firebird, DB_NOME é o caminho do arquivo .fdb no servidor ou um apelido, e DB_HOST vazio abre o arquivo direto)'),
    "DB_ARQUIVO" => t('o arquivo do banco (SQLite), fora da pasta publicada'),
    "API_TOKEN" => t('obrigatório, com pelo menos 10 caracteres: o token do servidor (vale como o master mais antigo); sem ele o sistema inteiro para (as páginas, a API e o cron dizem o motivo)'),
    "SEGREDO_CHAVE" => t('opcional: a chave que cifra os segredos guardados no banco (as senhas do CalDAV, as chaves do Google, os tokens do Outlook, os cabeçalhos dos webhooks); sem ela, o API_TOKEN (trocar a chave perde os segredos guardados)'),
    "FUSO" => t('o fuso horário do sistema e das datas gravadas (vazio: o do PHP)'),
    "CADASTRO_ABERTO" => t('opcional: false fecha o cadastro aberto (cadastro.php): só entra quem o master cria ou quem tem um convite; sem a linha, aberto'),
    "DEMO_DIAS" => t('opcional: os dias da conta de demonstração, a criada pelo cadastro aberto (padrão 30; 0: a conta já nasce oficial). Vencida, o cron a apaga com tudo'),
    "CONVITE_DIAS" => t('opcional: quantos dias um convite ou um pedido de acesso espera (padrão 7); depois, o cron apaga'),
    "EMAIL_METODO, EMAIL_REMETENTE" => t('o envio de e-mail (os destinos de e-mail, o Esqueci a senha, os convites, os avisos da conta): smtp ou mail (o mail() do PHP); o remetente (sem ele, o SMTP_USUARIO). Sem EMAIL_METODO e com SMTP_HOST, vale smtp'),
    "SMTP_HOST, SMTP_PORTA, SMTP_SEGURANCA, SMTP_USUARIO, SMTP_SENHA" => t('o servidor SMTP: o endereço, a porta (padrão 587, ou 465 com ssl), a segurança (tls: STARTTLS, o padrão; ssl: TLS desde o começo; nenhuma) e o login'),
    "IMAP_HOST, IMAP_PORTA, IMAP_SEGURANCA, IMAP_USUARIO, IMAP_SENHA, IMAP_PASTA_ENVIADOS" => t('opcional: guarda uma cópia de cada e-mail enviado na pasta Enviados (padrão Sent) da conta, por IMAP'),
    "POP_HOST, POP_PORTA, POP_SEGURANCA, POP_USUARIO, POP_SENHA" => t('opcional: só para o teste do e-mail conferir a caixa por POP3 (alguns provedores pedem o POP antes do SMTP)'),
    "VAPID_PUBLICA, VAPID_PRIVADA, VAPID_ASSUNTO" => t('o Web Push: o par de chaves VAPID em base64url (o master gera em Minha conta) e o assunto (mailto: ou o endereço do sistema)'),
    "OUTLOOK_CLIENTE_ID, OUTLOOK_CLIENTE_SEGREDO" => t('o aplicativo registrado na Microsoft (Entra ID) para as agendas do Outlook; o endereço de retorno é api.php?recurso=outlook_retorno. Sem eles, o Outlook fica de fora (use o link ICS)'),
    "MSG_ENDPOINT, MSG_DESTINATARIO, MSG_TITULO" => t('só para a migração v25: a API de mensagem antiga (Telegram) vira o primeiro destino webhook da coleção; MSG_TITULO continua sendo o título das mensagens'),
    "DOCUMENTOS_PASTA" => t('a pasta dos arquivos dos documentos, fora da pasta publicada, com escrita para o PHP'),
    "DOCUMENTOS_LIMITE (e o nome antigo MANUAL_LIMITE)" => t('o maior documento aceito, em bytes (sem ele: 100 MB; 0 ou -1: sem limite do sistema)'),
    "DOCUMENTOS_COPIA_BANCO" => t('a cópia dos documentos no banco para o sistema inteiro: true, todos; false, nenhum; ausente, o relógio e o arquivo decidem'),
    "TEXTOS_ESTRITO" => t('opcional, para quem traduz: true faz a tradução que falta parar com erro (na API e no cron; nas telas, o texto aparece entre ⟦ ⟧ e o erro vai para o console)'),
    "BACKUP_ATIVO" => t('opcional: false desliga a cópia de segurança (sem cópia automática, sem a escrita fazer e sem o quadro da Configuração); sem a linha, ligada'),
    "BACKUP_PASTA, BACKUP_MANTER, BACKUP_DOCUMENTOS" => t('a cópia de segurança do banco: a pasta (fora da publicada; sem ela, não há cópia automática), quantas ficam (padrão 7) e se leva o conteúdo dos documentos guardado no banco'),
    "ANTIGO_HOST, ANTIGO_PORTA, ANTIGO_USUARIO, ANTIGO_SENHA" => t('só para importar o sistema anterior (importar.php)'),
    "ALEATORIO_RANDOMORG_URL, ALEATORIO_ANU_URL" => t('só para testes: as fontes dos números aleatórios (RANDOM.ORG e ANU QRNG); trocadas, os sorteios pedem os números a um servidor de teste'),
    "GOOGLE_TOKEN_URL, GOOGLE_API_URL, OUTLOOK_LOGIN_URL, OUTLOOK_GRAPH_URL" => t('só para testes: os endereços do Google e da Microsoft que as agendas usam; trocados, a sincronização conversa com um servidor de teste'),
    ];
}

// As telas: o visual, o menu e cada página, como a reescrita tem de reproduzir (os textos entre aspas são os da tela)
function reconstrucao_telas()
{
    return [
    "como_ler" => t('A interface que a reescrita tem de reproduzir, tela por tela: o visual (cores, letras, controles e componentes, com os valores exatos), o menu, e cada página com o endereço, o que ela lê e grava na API, e as partes em ordem, com cada tabela (as colunas), cada formulário (os campos com o rótulo, o tipo, o padrão e os limites), cada botão (o texto e o que ele manda para a API) e cada mensagem. Os textos entre aspas são os da tela, palavra por palavra. As capturas em docs/telas/ mostram o resultado; o estilo.css é a referência de cada medida. O código de cada página, como está agora, sai pelo recurso=codigo (os arquivos dela em codigo; o HTML que ela entrega ao navegador em recurso=codigo&arquivo=<página>&html=1&cru=1).'),
    "visual" => [
        ["id" => "cores", "titulo" => "Cores", "itens" => [
            t('Fundo da página: aço #e4e8ec. Bordas: aço escuro #c3cad2. Texto: tinta #1c2530 (também o fundo da barra do menu). Rótulos e notas: cinza #6b7684. Botões, links, aba ativa, barras de carga: safira #1f4f8f. O que fazer, recados e carga baixa: âmbar #a8661a. Fundo de cartões, quadros e tabelas: papel #fbfcfd.'),
            t('Etiquetas de estado (pílulas): em uso: fundo #e3f3e6, texto #1e6b2e, borda #a9d8b3. Em repouso e erro: fundo #fbe8e6, texto #9b2c22, borda #efb5ae. Indisponível e sem atividade: fundo #eef1f4, texto cinza, borda aço escuro. Atividade, "você" e "relógio de hoje": fundo #e6eef8, texto #1f4f8f, borda #b8cbe6. Numa sessão que não é o pulso (no sol, no winder, no carregador), outras sessões e carga: fundo #fff4e0, texto #8a5a00, borda #efd29a.'),
            t('Origem de um valor do cadastro: "informado" em azul (#e6eef8 / #1f4f8f / #b8cbe6), "padrão" em bege (#fbf1e3 / #8a5a00 / #ecd2a8), "vazio" sem etiqueta e com a linha em cinza.'),
            t('Atrasado (um prazo vencido, "faltam" e "passa" das somas): texto #9b2c22 em negrito. Caixa de erros de gravação: fundo #fdf0ee, borda #e3b4ae, texto #7a2419, cantos 10px. Linha de execução com erro: fundo #fdf2f1.'),
        ]],
        ["id" => "letras", "titulo" => t('Letras e números'), "itens" => [
            t('Fonte: "Segoe UI", "Helvetica Neue", Arial, sans-serif, 16px, entrelinha 1,5.'),
            t('Título da página (h1): grande, de 2rem a 3,4rem conforme a largura (clamp(2rem, 6vw, 3.4rem)), negrito 700, espaçamento -0,02em, entrelinha 1,05. Título de seção (h2): 1,1rem. Notas e explicações: 0,92rem, cinza. Título dos quadros do painel do relógio: 0,78rem, maiúsculas, negrito, espaçado 0,06em, cinza, com uma linha embaixo. Números em tabelas e valores com algarismos de largura igual (tabular).'),
            t('Formatos: data DD/MM/AAAA (curta: DD/MM); hora HH:MM; números com vírgula e sem zeros sobrando (1 casa por padrão); dinheiro "R$ 1.234,56"; porcentagem "12,5%"; duração pelas duas maiores partes: "1a 5m", "5d 3h", "12h 22min", "40min", "menos de 1min"; dia da semana curto: seg, ter, qua, qui, sex, sáb, dom.'),
        ]],
        ["id" => "controles", "titulo" => t('Campos, listas e botões'), "itens" => [
            t('Uma altura só para campo, lista e botão: 2,4rem, para ficarem alinhados lado a lado. Cantos 8px; campo e lista com borda 1px aço escuro e fundo branco; textarea com a mesma borda. Foco visível: contorno 2px safira afastado 2px. Campo numérico curto: 5,5rem. Números digitados aceitam vírgula ou ponto (teclado decimal no celular). No celular, os campos têm letra de 16px (o iPhone não aproxima a tela).'),
            t('Botão principal: fundo safira, texto branco, borda safira, preenchimento 0,3rem 1rem. Botão "leve": fundo transparente, texto e borda safira (as ações secundárias). Botão "discreto": sem borda, 0,85rem, preenchimento lateral 0,45rem (↑, ↓ e Excluir das linhas). O Excluir das ações de linha é vermelho: texto #9b2c22, borda #efb5ae, fundo #fbe8e6 ao passar o mouse.'),
            t('Caixa de marcar (checkbox) sempre à esquerda do texto, na mesma linha. Rótulo de campo: acima do campo nas grades de formulário; à esquerda na Configuração (veja os componentes). Botões desativados durante o envio de um formulário.'),
        ]],
        ["id" => "componentes", "titulo" => "Componentes", "itens" => [
            t('Barra do menu: fundo tinta, links #b9c3ce sem sublinhado; o da página atual em branco com uma linha branca de 2px embaixo.'),
            t('Cartão (as seções das páginas de cadastro): fundo papel, borda 1px aço escuro, cantos 12px, preenchimento 1rem, título h2 em cima.'),
            t('Quadro (o painel do relógio): igual ao cartão (cantos 12px, preenchimento 0,85rem 1rem), com o título em maiúsculas pequenas, o corpo, e um rodapé opcional (linha em cima, botões à direita, alinhados no pé: os quadros da mesma linha da grade têm a mesma altura). Quadro "largo" ocupa a linha inteira da grade.'),
            t('Pares (rótulo e valor): duas colunas, o rótulo em cinza 0,85rem à esquerda (até 55% da largura), o valor à direita; uma linha fina aço entre os pares (a última sem); um texto menor (0,78rem, cinza) embaixo do valor é a explicação dele; rótulo vazio continua a linha de cima.'),
            t('Tabela: fundo papel, cantos 10px, cabeçalho em cinza 0,9rem negrito, células com preenchimento 0,55rem 0,75rem e uma linha aço embaixo. Linha clicável: o fundo muda para #f1f4f8 ao passar e fica #e3eaf5 quando selecionada; fim de semana em #f4f6f8; o relógio de hoje com uma faixa safira de 3px na borda esquerda, e um com aviso pendente com a faixa âmbar. Tabela compacta (dos cadastros): 0,88rem, sem fundo próprio.'),
            t('Etiqueta de estado: pílula com cantos arredondados por inteiro, 0,8rem negrito, preenchimento 0,12rem 0,55rem, cores em "cores". Numa linha só; nos pares rótulo/valor (o quadro Agora do painel estreito) e nas marcas da cabeça, a comprida ("Em repouso desde 25/09 20:40") quebra a linha nos espaços (nunca no meio da palavra), com cantos de 0,8rem, e fica dentro do quadro.'),
            t('Barra de carga: trilho aço com 7px de altura (44px na tabela, 56 a 70px nos quadros), preenchido em safira até a porcentagem (âmbar com carga até 20%), seguido do número em negrito ("68%"; estimado: "~68%") e, menor, de onde a carga vem.'),
            t('Janela de data (o "só hoje" / "até sexta, 02/10" do relógio do dia): caixa branca, borda 2px tinta, cantos 3px, negrito, sombra interna fina, como a janela de data de um mostrador.'),
            t('Recado (o resultado de uma gravação): um parágrafo âmbar em negrito no topo da área (ou do painel do relógio). Erros de gravação: a caixa vermelha clara com "Não foi salvo:" e um parágrafo por erro, no topo da página.'),
            t('Abas (Cadastros e Documentos): links lado a lado com borda aço e cantos 0,4rem, preenchimento 0,35rem 0,8rem, texto safira; a ativa com fundo safira e texto branco.'),
            t('Grade de formulário (form-grade): colunas de no mínimo 14rem lado a lado, o rótulo acima de cada campo; textareas, grupos de caixas e botões na linha inteira. Linha de inclusão: campos com o rótulo em cima, lado a lado, separados do resto por uma linha tracejada acima.'),
            t('Barra de salvar (Configuração): presa no pé da tela enquanto a página rola (sticky), fundo papel quase opaco, borda, cantos 12px, sombra para cima; à esquerda "As mudanças desta página valem depois de salvar.", à direita o botão.'),
            t('Sem foto: quadrado tracejado (2px aço escuro, cantos 14px) com "Sem foto" em cinza no meio. Foto: quadrada (recortada), cantos 14px, borda aço escuro. Miniatura na tabela: 28px (36px no celular), cantos 5px.'),
            t('Ações de uma linha (Editar, ↑, ↓, Excluir): numa linha só, à direita da linha da tabela.'),
            t('Resumo em números (Execuções e Histórico): uma grade de caixinhas (4 colunas; 2 no celular), cada uma com o número grande (1,4rem) e o que ele é embaixo em cinza; a de erros com fundo vermelho claro.'),
        ]],
        ["id" => "layout", "titulo" => t('Larguras e tamanhos de tela'), "itens" => [
            t('Conteúdo centrado com margens de 1,25rem: largura máxima 56rem nas páginas comuns (Plano, Usuários), 72rem na Ficha, 76rem na Configuração, Cadastros, Critérios, Grupos, Execuções e Histórico, 60rem na Ajuda. A página Hoje usa a largura toda.'),
            t('Página Hoje: duas colunas, o meio e o painel do relógio com 22rem à direita, preso ao rolar (sticky) e com rolagem própria; sem relógio aberto, o meio ocupa tudo. Com a Configuração "Numa janela flutuante, grande": o relógio abre no meio da tela, até 78rem ou 94% da largura, de 3% do alto a 3% de baixo, cantos 16px, sombra forte, com o fundo escurecido (rgba(16, 22, 29, .55)) e os quadros lado a lado; fecha no ×, no Esc ou num clique no fundo.'),
            t('Até 900px (celular e tablet de pé): o menu recolhido; as tabelas viram fichas (cada célula com o título da coluna ao lado) ou cartões; o painel do relógio cobre a tela inteira, com o botão "× Fechar" preso no alto, e o voltar do aparelho fecha o painel; as grades viram uma coluna. De 901 a 1199px (tablet deitado): o painel "ao lado" desliza por cima, pela direita, com até 30rem, cantos 16px à esquerda e o fundo escurecido. De 1200px em diante: o painel ao lado.'),
            t('Nenhuma página rola de lado, em nenhum tamanho. A mesma informação em todos os tamanhos: nada é escondido por falta de espaço (a tabela dos relógios vira cartões com todos os campos quando as 12 colunas não cabem).'),
            t('As tabelas de um quadro se arrumam pela largura do quadro (container queries), não pela da tela: o mesmo quadro é estreito no painel ao lado e largo na ficha. Até 40rem, as Medições do gasto viram cartões; até 26rem, as tabelas dos Dados do relógio ficam mais justas e o valor quebra a linha.'),
            t('Nada sai da caixa em que está, de 320px (celular pequeno) a 1920px: as grades de colunas automáticas encolhem a coluna mínima até a largura da tela; um grupo de campos (fieldset) e os menus dentro dele encolhem com a tela (um menu de nome comprido não vaza); o menu de escolher o relógio da Ficha fica na largura da tela; o código comprido da Ajuda (uma URL de exemplo) quebra a linha. Os botões nunca partem o texto em duas linhas: os da foto (Abrir, Trocar, Remover foto) ficam numa linha, na largura da foto (6,5rem no painel ao lado e no celular); o botão de marcar do quadro Marcar fica numa linha própria, embaixo dos campos. Até 420px, as tabelas do painel e o resumo do Plano ficam mais justas (0,82rem, 0,3rem entre as colunas) e quebram dentro da célula. No Histórico do celular, as colunas são 25%, 25%, 18% e 32% (o Estado, a mais larga).'),
        ]],
        ["id" => "comportamento", "titulo" => t('Comportamento comum'), "itens" => [
            t('Cada página entrega só o esqueleto; o JavaScript dela lê a API e monta a tela. Gravar é um formulário que manda um POST para a API (recurso e acao nos campos); depois a página se remonta com os dados novos, sem recarregar, e mostra a mensagem da API no topo (ou no painel do relógio, se a gravação era dele).'),
            t('Antes de toda exclusão e de toda ação que refaz o plano, uma confirmação do navegador com o texto do que vai acontecer (os textos estão em cada página). Durante o envio os botões do formulário ficam desativados; se a conexão falhar: "Não consegui gravar: <motivo>. Confira a conexão e tente de novo."'),
            t('Sem login: a página manda para api.php?recurso=entrar&volta=<a página>, o navegador pede o usuário e a senha e volta. Banco desatualizado (503 com pendentes): a página vai para a Configuração, que mostra só o aviso e o "Aplicar agora". Erro ao ler: "Não consegui ler <o quê>: <motivo>" em âmbar, no lugar da página.'),
            t('Fotos reduzidas no navegador antes de enviar: no máximo 1200px no lado maior, JPEG 85%, com a prévia na tela; imagem que não abre: alerta "Não consegui abrir essa imagem. Use JPEG ou PNG.".'),
            t('A foto do relógio ampliada, só no detalhe do relógio (o painel, ou a ficha): duplo clique na foto (o cursor é a lupa de ampliar) ou o botão "Abrir foto" embaixo dela abre por cima de tudo uma janela flutuante (nas outras fotos, como a do "Hoje é dia de" e as miniaturas da lista, o clique abre o relógio e o duplo clique não faz nada a mais): fundo rgba(10,14,20,.82) na tela inteira, a foto no meio, no tamanho dela (os pixels da imagem em px da página), cantos 6px, fundo branco e sombra; maior que a área que se vê, em escala, sem deformar: a escala é o menor entre 1, (largura − 32px) ÷ a largura da foto e (altura − 72px) ÷ a altura da foto, com a largura e a altura sendo o menor entre o monitor (screen.availWidth/availHeight) e a janela do navegador; refeita quando a janela muda de tamanho ou de monitor. Embaixo, em branco, "<nome> · <largura> × <altura> px" e, em escala, "(mostrada a N%)". Um botão redondo "×" (branco, 2,4rem) no canto de cima, à direita. Fecha com o ×, com um clique fora da foto ou com Esc (o Esc fecha só a foto: o painel por baixo continua aberto); enquanto aberta, a página não rola.'),
            t('O título da aba do navegador é o título da página ("Relógio de hoje", "Plano", "Configuração", "Critérios de escolha", "Grupos de relógios", "Execuções do cron", "Usuários", "Cadastros", "Ajuda", "Ficha do relógio", "Documentos", "Desejos", "Estatísticas", "Hora certa", "Acessórios", "Galeria", "Passaporte do relógio" ("Passaporte — <relógio>" depois de ler); no histórico, "Histórico — <relógio>").'),
            t('O navegador de páginas (Próximos dias, Dia a dia do Plano, Rodízio manual): botões leves pequenos (padding .3rem .65rem, letra .9rem) numa linha que quebra: "<" (dica "Anterior"; desativado na primeira), os números (a 1, a última e até 2 de cada lado da atual, com "…" sem borda nos saltos; dica "Página N"; a atual em safira com letra branca, sem ser botão) e ">" (dica "Próximo"; desativado na última). Uma página só: sem navegador. Cada botão relê a API com a página.'),
        ]],
    ],
    "menu" => [
        "como" => t('Na barra escura do topo, nessa ordem, em todas as páginas; o da página atual em branco e sublinhado. A Ficha, o Histórico e os Documentos não estão no menu: abrem do painel do relógio (o histórico e os documentos numa aba nova). No celular (até 900px): a barra, presa no alto ao rolar, mostra o nome da página atual à esquerda e o botão "☰ Menu" à direita; aberto, ele vira "✕ Menu" e os itens aparecem em duas colunas, cada um num botão escuro (#27323f), o atual em safira. Depois do último item, o seletor do idioma: uma caixa pequena com o código curto de cada idioma (PT, EN, 中文, हिं, ES, FR; o nome inteiro na dica); escolher grava o cookie lingua (um ano, o caminho todo) e abre a página de novo nele. Os rótulos abaixo são os do português: em outro idioma, as traduções do dicionário das telas. À direita, antes do idioma, o que é de quem entrou (recurso=conta, parte=menu): com mais de uma coleção, o seletor da coleção (grava o cookie colecao e abre a página de novo; o nome, e "· <dono>" nas dos outros); "Aprovações (N)" em âmbar quando há pedidos ou convites esperando; "Demo: N dias" em âmbar na conta de demonstração. O Usuários só aparece para o master. O master vendo como outro usuário: uma faixa âmbar acima de tudo, "Vendo como <login>: o que aparece e o que se grava é da conta dele." e o botão "Voltar a ser <master>". A conta sem coleção nenhuma (409 sem_colecao) vai para Minha conta. A página pública (cadastro.php) não tem menu, só o idioma.'),
        "itens" => [
            ["pagina" => "index.php", "rotulo" => "Hoje"],
            ["pagina" => "plano.php", "rotulo" => "Plano"],
            ["pagina" => "desejos.php", "rotulo" => "Desejos"],
            ["pagina" => "estatisticas.php", "rotulo" => t('Estatísticas')],
            ["pagina" => "hora.php", "rotulo" => t('Hora certa')],
            ["pagina" => "acessorios.php", "rotulo" => t('Acessórios')],
            ["pagina" => "galeria.php", "rotulo" => "Galeria"],
            ["pagina" => "configuracao.php", "rotulo" => t('Configuração')],
            ["pagina" => "criterios.php", "rotulo" => t('Critérios')],
            ["pagina" => "grupos.php", "rotulo" => "Grupos"],
            ["pagina" => "execucoes.php", "rotulo" => t('Execuções do cron')],
            ["pagina" => "conta.php", "rotulo" => t('Conta')],
            ["pagina" => "usuarios.php", "rotulo" => t('Usuários')],
            ["pagina" => "cadastros.php", "rotulo" => "Cadastros"],
            ["pagina" => "ajuda.php", "rotulo" => "Ajuda"],
        ],
    ],
    "paginas" => [
        [
            "id" => "hoje",
            "pagina" => "index.php",
            "titulo" => t('Relógio de hoje'),
            "endereco" => t('index.php; index.php?r=<id> abre o relógio no painel; index.php?novo=1 abre o cadastro de um novo; index.php#manual abre o quadro Modo de rodízio no Rodízio manual'),
            "le" => [
                t('recurso=hoje (com proximos_pagina)'),
                t('recurso=proximos (a troca de página dos Próximos dias)'),
                t('recurso=ficha (o painel)'),
                t('recurso=rodizio_manual (o Rodízio manual, de 7 em 7 dias)'),
            ],
            "grava" => [
                t('lancamento: iniciar, encerrar, lancar, periodo, alterar, excluir'),
                t('rodizio: trocar_dia, modo, resortear, resortear_hoje, proxima_semana, usando, sem_relogio, manual, periodo_sortear, periodo_livre, apagar_tudo'),
                t('relogio: salvar, foto, remover_foto, excluir'),
            ],
            "partes" => [
                ["titulo" => t('O relógio do dia (um cartão grande, no alto do meio)'), "itens" => [
                    t('O relógio do cartão é o de destaque.relogio_id da API (o que tem relogios[].destaque): o relógio do dia, ou, com a Configuração "Destaque no alto da página Hoje" em "O relógio no pulso agora" (destaque_hoje = pulso), o que está no pulso agora. Quando ele não é o do dia (destaque.e = pulso): o nome dele em h1 (link que abre o painel), a janela de data com "no pulso agora" e o tipo dele, e em âmbar "Relógio do dia: <nome, link que abre o painel> (<até>)" (ou "Hoje não tem relógio do dia."), sem o motivo. Com o destaque no pulso e ninguém no pulso, o cartão do dia como sempre e, embaixo do motivo, o destaque.texto em cinza ("Nenhum relógio no pulso agora: aqui, o relógio do dia."). Os pares, o botão Pôs/Tirou do pulso e a foto são do relógio do cartão.'),
                    t('À esquerda: "<Dia da semana>, DD/MM/AAAA · <nome do modo>" em cinza; o nome do relógio do dia em h1 (link que abre o painel); a janela de data com o "até" ("só hoje", "até sexta, 02/10") e o tipo dele; o lembrete do dia em âmbar ("Pôr no winder: <relógio> (8 h)"); "Por que ele: <motivo>" (o motivo em cinza); embaixo, o botão leve discreto "Sem relógio hoje" (rodizio sem_relogio; confirma "Hoje sem relógio? O dia fica livre: ...").'),
                    t('No meio, em pares: Agora (a etiqueta "Em uso desde 07:00", "No sol desde 09:12" ou "Em repouso desde ...", na cor do estado, e o botão leve "Pôs no pulso" / "Tirou do pulso", que manda lancamento iniciar/encerrar do tipo pulso); Carga (a barra, o % e de onde vem, ou só o texto); Última leitura ("89% em 06/10 21:30", se houver); Situação (as linhas da API); Próxima manutenção ("<nome> · <data ou hoje> (<falta>)", a falta em vermelho se atrasada, ou "nenhuma prevista"; o aviso mais perto, sem o Encostado); Encostado (só quando o aviso já venceu: a falta em vermelho); Compra ("R$ valor · loja · data"); Garantia ("até DD/MM/AAAA", "(vencida)" se passou); Código.'),
                    t('À direita: a foto do relógio (ou o quadrado "Sem foto"), que abre o painel.'),
                    t('Hoje livre (hoje_livre da API: o Sem relógio hoje, ou um período livre): h1 "Hoje sem relógio", a janela "dia livre", "Nenhum relógio vai ao pulso pelo rodízio hoje. Quer usar um? ..." e o "escolher…" dos dias livres (trocar_dia de hoje: o escolhido vai para o pulso a partir de agora); sem os pares nem a foto (a não ser com o destaque no pulso).'),
                    t('Sem relógio para hoje: h1 "Nenhum relógio para hoje" e "Nenhum relógio disponível no grupo deste dia. Mude o grupo no modo de rodízio ou marque um relógio como disponível."'),
                ]],
                ["titulo" => t('Hoje é dia de (só quando há aviso)'), "itens" => [
                    t('Tabela com as colunas "O que fazer" (o nome do aviso; atrasado em vermelho), "Relógio" (link que abre o painel), "Por quê" (o texto do aviso) e, à direita, o botão do lançamento que resolve, com o texto e a ação que a API dá (resolve.botao e resolve.acao): sessão fechada: "Pôs <nome em minúsculas>" (lancamento iniciar); sessão aberta: "Tirou do carregador" (lancamento encerrar); com valor: um campo curto com a unidade de dica ("%") e "Informar <nome>" (lancamento lancar com valor); instantâneo: o nome do tipo (lancamento lancar).'),
                ]],
                ["titulo" => t('Próximos dias'), "itens" => [
                    t('Título "Próximos dias" com o link "ver o plano inteiro →" (plano.php). Tabela: Dia ("DD/MM" e o dia da semana curto, menor), Relógio (o nome; "à mão" pequeno quando escolhido à mão; o motivo embaixo, menor), Lembrete, e o "trocar por…". Fim de semana com fundo #f4f6f8. Sem plano: "Neste modo o sorteio é feito dia a dia." Os dias livres entram no meio, como na página Plano ("livre: escolha à mão", "escolher…"). Os dias vêm da API (hoje.proximos), de "Mostrar até" em "Mostrar até" (a Configuração, padrão 7); passando disso, embaixo o navegador de páginas (recurso=proximos com pagina; só a lista se remonta) e "Página N de M · <total> dias no plano, <n> por página (o "Mostrar até" da Configuração)." (link para configuracao.php). A página à mostra fica a mesma quando a página Hoje se remonta depois de uma gravação.'),
                    t('"trocar por…": uma lista com os relógios disponíveis (menos o do dia) e, num dia de amanhã em diante escolhido à mão, "↺ voltar a sortear" (valor 0); escolhido um, aparece o botão leve "Trocar" (rodizio trocar_dia com data e relogio_id).'),
                ]],
                ["titulo" => t('Modo de rodízio (um quadro que abre e fecha)'), "itens" => [
                    t('Fechado: "Modo de rodízio" (cinza) e o nome do modo em negrito, "· <forma de escolha>" (na escala: "pela maior nota") e, à direita, o link "editar".'),
                    t('Aberto, em duas colunas: à esquerda a lista "Modo de rodízio" (os modos), a explicação do modo escolhido (escala: "Monta o plano do período inteiro de uma vez..."; sorteio: "Cada bloco de dias sorteia entre os relógios do grupo escolhido...") e os campos do modo escolhido: na escala, "Período" (Uma semana, Um mês, Dois meses, Meio ano, Um ano, Dois anos = 7, 30, 60, 180, 365, 730); para cada bloco, uma lista com o nome do bloco (grupos "Sortear entre": Todos e cada grupo com recuo pela profundidade; "Relógio fixo": cada relógio disponível) e, fora da escala, a caixa "sortear um por dia".'),
                    t('À direita: "Como escolher o relógio" (Inteligente; Inteligente com sorteio; Totalmente aleatório; Fila (FIFO)) com a explicação de cada uma (na escala, "Como escolher o relógio: pela maior nota"); "Garantia de rodízio: dias sem uso, no máximo" (número de 0 a 365; só nas formas inteligente, com sorteio e na escala) com "Quem passa do limite tem prioridade, o mais tempo parado primeiro. 0 desliga. O mesmo valor vale para todos os modos que usam a nota." e "A nota vem dos critérios, que você cadastra."'),
                    t('Botão "Aplicar modo" (rodizio modo), com a confirmação "Trocar o modo apaga o plano atual. Continuar?" (com o dia já começado: "Trocar o modo refaz o plano a partir de amanhã; hoje continua o <relógio>. Continuar?") e a nota sobre o histórico que continua e o link para Cadastros (os modos).'),
                    t('"Sortear de novo": uma lista com as ações possíveis agora e o botão leve "Sortear": "a partir de amanhã" (resortear) e "inclusive hoje" (resortear_hoje) com o dia já começado no pulso, ou "a partir de hoje" (resortear); fora da escala, "a próxima semana (DD/MM a DD/MM)" (proxima_semana; em qualquer dia: a semana seguinte, de segunda a domingo, sorteada com os dias que faltam desta já contados como uso; o "Sortear de novo" de depois refaz até o fim dela). Embaixo, a explicação da opção escolhida; cada uma tem a sua confirmação.'),
                    t('Rodízio manual: a última opção da lista "Modo de rodízio" (não é um modo: o modo de sempre continua valendo fora do período). Escolhida, a explicação diz "Você escolhe o relógio de cada dia de um período. Ponha o começo e o fim e clique em OK: os dias aparecem de 7 em 7, cada um com a lista dos relógios, "Decida por mim" (o sistema escolhe) e "Sem relógio (dia livre)". Fora do período, continua valendo o modo de rodízio de sempre (<modo>)."; somem a coluna da direita, o "Aplicar modo" com a nota e o "Sortear de novo"; aparece, abaixo de uma linha, o formulário do período: "De" (data, de hoje em diante, já com hoje), "Até" (data) e o botão "OK". O endereço index.php#manual abre o quadro já no Rodízio manual (o link da página Plano).'),
                    t('OK lê recurso=rodizio_manual (de, ate, pagina) e mostra a tabela compacta (até 46rem): Dia ("DD/MM" e o dia da semana por extenso, menor, com " · hoje"; o número em safira no fim de semana), No plano agora (o relógio com "à mão" ou "sorteado" pequeno em âmbar, "dia livre" ou "—" em cinza) e Relógio do dia (uma lista: "Decida por mim", o grupo "Relógios" com os disponíveis por nome e, menos hoje, "Sem relógio (dia livre)"; de partida, a escolha que a API manda). Mais de 7 dias: embaixo, os botões leves "‹ Anterior", os números das páginas (a 1, a última e até 2 de cada lado da atual, com "…" nos saltos; a atual em safira) e "Próxima ›"; cada um relê a API. O que se escolhe fica guardado na tela ao trocar de página. Embaixo, "Página N de M · <n> dias, de DD/MM/AAAA a DD/MM/AAAA. O que você escolhe fica guardado enquanto você anda pelas páginas; nada muda antes de gravar.", os botões "Gravar o rodízio manual" (rodizio manual com de, ate e dias[data] dos que você mudou; confirma "Gravar o rodízio manual de DD/MM/AAAA a DD/MM/AAAA? Os dias ficam com os relógios escolhidos; os de "Decida por mim" são decididos agora pelo sistema."); e as notas com o critério do Decida por mim (o texto da API) e "Para apagar o que um período tem (e sortear de novo, ou deixar os dias livres): "Apagar um período", embaixo deste quadro, ou na página Plano." (link). Recusado: o motivo em âmbar acima da tabela, e as escolhas ficam; gravado: o recado no alto da página e a página remontada.'),
                    t('Abaixo do Sortear de novo, cada um abaixo de uma linha, os mesmos dois formulários da página Plano: "Apagar um período" e "Apagar tudo" (veja a página Plano).'),
                ]],
                ["titulo" => t('Relógios (a tabela da coleção)'), "itens" => [
                    t('Título "Relógios" com "<mostrados> de <total> relógios · <n> em uso" em cinza e, à direita, os botões "Limpar filtros" (leve) e "Novo relógio" (abre o cadastro no painel).'),
                    t('12 colunas, todas ordenáveis clicando no título (▲ ou ▼ ao lado; clicar de novo inverte; vazios sempre por último): Código, Relógio (miniatura e nome em negrito), Tipo, Estado (o estado_texto da API em etiqueta, na cor do estado: Em uso verde, a sessão aberta (No sol, No winder, No carregador) laranja, Em repouso vermelho, Indisponível cinza), Carga (barra e %, ou o texto de por que não tem), Última vez usado, Próxima manutenção (data ou "hoje"), Em (quanto falta; atrasado em vermelho negrito; atualizado a cada minuto), O que fazer (em âmbar negrito; o aviso de manutenção mais perto, sem o Encostado), Encostado (quanto falta para o limite de dias sem ir ao pulso, ou "atrasado …" em vermelho negrito quando já passou; "—" sem data; atualizado a cada minuto), Comprado em, Valor (à direita, "R$ 1.234,56").'),
                    t('Uma linha de filtros embaixo dos títulos: Código ("nº"), Relógio ("buscar" no nome), Tipo (os tipos que existem), Estado (Todos e os estados da API, na ordem: Em uso, cada sessão, Em repouso, Indisponível), Carga (Todas, ≤ 20%, ≤ 50%), Última vez usado (Todos, 7 dias, +7 dias, nunca), Próxima manutenção (Todas, até hoje, 7 dias, 30 dias), O que fazer (os avisos de manutenção, sem o Encostado), Encostado (Todos, Encostados, Dentro do prazo, sem data), Comprado em (Todas, 30 dias, 90 dias, este ano, sem data), Valor (Todos, ≤ 500, 500–1.500, > 1.500, sem valor). A ordem e os filtros ficam guardados na sessão do navegador.'),
                    t('Ordem inicial: os disponíveis primeiro (o de hoje, depois os com aviso), os indisponíveis no fim e em cinza. A linha do relógio de hoje tem a faixa safira à esquerda; a de um com aviso, a âmbar. Clicar numa linha (ou Enter nela) abre o relógio no painel. Rodapé: "Total dos relógios mostrados" e a soma dos valores, à direita. Sem relógios: "Nenhum relógio cadastrado. Use o botão Novo relógio."; com filtros que não deixam nenhum: "Nenhum relógio com esses filtros."'),
                    t('Quando as 12 colunas não cabem na largura que a tabela tem: cada relógio vira um cartão, com a foto e o nome na linha de cima e embaixo os mesmos campos com o nome da coluna em cima, em colunas iguais (5; 2 no celular); os títulos viram botões "Ordenar por" e os filtros ficam todos, com o nome em cima.'),
                ]],
                ["titulo" => t('O painel do relógio (à direita, flutuante ou em tela cheia)'), "itens" => [
                    t('Um botão redondo "×" no canto (no celular "× Fechar") fecha o painel; Esc também. O endereço vira index.php?r=<id>.'),
                    t('Cabeça: a foto (ou "Sem foto"; com o mouse parado em cima, a dica relogio.dica_foto da API, em linhas: o nome, o grupo e o código; o estado e a carga; a nota e o próximo dia no rodízio; a compra; e "Duplo clique (ou "Abrir foto", embaixo): a foto em tamanho grande.") com os botões leves "Abrir foto" (só com foto: abre a foto ampliada), "Escolher foto" / "Trocar foto" (abre o seletor de arquivo; escolhida, aparece a prévia e o botão "Salvar foto": relogio foto) e, com foto, "Remover foto" (discreto; confirma "Remover a foto?"); ao lado "<tipo> · <caminho no grupo>" em cinza, o nome em h1, a observação, e as etiquetas: em uso/em repouso, "indisponível", "relógio de hoje" e, fora da coleção, a situação (etiqueta lilás: "desejado", "emprestado", "vendido", "arquivado"; no desejado, sem a etiqueta do estado).'),
                    t('Num relógio desejado, no lugar dos quadros abaixo (Agora, Previsão, Gasto, Autonomia, Rodízio, Compra, Manutenções, Marcar), só o quadro Desejo (pares: Marca, Referência, Preço-alvo "R$ ...", Prioridade, Link "abrir a página" numa aba nova; vazio: "—"; no rodapé "Comprou? Em "Editar cadastro", mude a situação para "Na coleção" e preencha a compra.") e o de Documentos; os quadros largos (Dados, Histórico) e o Editar cadastro continuam.'),
                    t('Os quadros, numa grade (lado a lado onde cabem): Agora (pares: Estado ("No carregador desde 06:30", na cor do estado); Carga com a barra e "~68%" e de onde vem; Encostado (a falta, em vermelho negrito se já passou do limite de dias sem ir ao pulso; só quando há data); Situação, uma linha por item; Última leitura); Previsão (só nos que têm leitura: as frases da API, ou "Informe a carga atual para o sistema começar a prever."; embaixo, "De onde vem a conta" (título pequeno em maiúsculas cinza) e a tabela da previsao.conta, uma linha por número: o nome (e, menor, embaixo, o detalhe de como o gasto foi medido), o valor (com a unidade menor embaixo; "—" se vazio, a linha em cinza) e a origem em etiqueta: informado azul, padrão âmbar, medido verde, vazio cinza); Gasto da bateria (No pulso, Fora do pulso: o que vale "% por dia" e embaixo se é medido ou do cadastro; Janela anterior; Medições; no rodapé "Medições mais velhas que a janela saem da conta..."); Autonomia (Cheio, pelo cadastro; Cheio, pela conta; Acaba, seguindo o plano (data e hora, e "em <duração>"); No pulso sem tirar; Guardado; sem dado, o motivo em cinza); Rodízio (Nota com o link "ver a conta" (Critérios, numa aba nova); Critérios de; Próxima vez; no rodapé o botão leve "Usando hoje" (rodizio usando, com confirmação), se ele está disponível e não é o de hoje); Compra (Valor pago, Loja, Comprado em com "há N dias", Garantia "até" ou "vencida em"; sem nada: "Sem dados da compra. Preencha em "Editar cadastro"."); Compra também com "Custo por uso" ("R$ 100,00" em negrito e "3 dias de uso" embaixo; sem: "sem o valor pago" ou "ainda não foi ao pulso" em cinza) e o "há N dias" da compra vindo da API (compra.ha_dias). Documentos (cada categoria com "N arquivos", link para a página Documentos numa aba nova; o botão "Enviar documentos" ou "Abrir os documentos"); Próximas manutenções (data em negrito e o nome; ou "Nada previsto."). Depois de Documentos, Acessórios (pares: o tipo e o nome, com a observação menor embaixo; sem nenhum, "Nenhum: a pulseira extra, a caixa, o estojo, a ferramenta..."; o botão leve "Cadastrar acessórios" ou "Ver os acessórios", que abre acessorios.php?relogio=<id> numa aba nova). No rodapé de Documentos, ao lado do botão dele, o botão leve "Passaporte" (passaporte.php?id=<id> numa aba nova).'),
                    t('Marcar (um quadro na largura toda): "O que você quer marcar?" com uma lista em grupos: "Agora, ou numa hora que você disser" (cada sessão como verbo: "Pôr no sol" / "Tirar do sol" conforme está aberta; cada tipo com valor pelo nome; cada instantâneo pelo nome), "Algo que já passou" ("Um período que já passou (<sessões>)") e "Corrigir" ("Corrigir ou excluir uma marcação (últimos 14 dias)"). Escolhido um, aparecem só os campos dele: sessão: o estado aberto e "Pôs às" / "Tirou às" (data e hora, "Anda com o relógio até você mudar (vazio: agora).") e o botão com o verbo; com valor: "Agora em uso/em repouso" e a estimativa, "Leitura" (com a unidade), "Lida às", a caixa "Atualizar o gasto com esta medição" (marcada; só nos tipos que medem o gasto) e "Informar <nome>"; instantâneo: "Quando" e "Marcar <nome>"; período: "Onde" (se houver mais de uma sessão), "De" (uma hora atrás) e "Até" (agora) e "Registrar o período"; corrigir: "Qual marcação" ("DD/MM HH:MM · nome até HH:MM", "(aberta)", ": 68 %", "(rodízio)") e os campos dela com "Salvar" e "Excluir" (confirma). Nada pode ser no futuro. Os campos de data e hora andam com o relógio do navegador: "Pôs às", "Tirou às", "Lida às", "Quando" e o "Até" do período vêm com a hora de agora e acompanham o relógio (a cada 15 s e ao clicar no campo) até a pessoa mexer, e daí ficam no que ela pôs; sem mexer, "Pôs às", "Tirou às", "Lida às" e "Quando" vão vazios (a API usa o agora dela, com os segundos) e o "Até" vai com o minuto de agora; o máximo de todos (nada no futuro) também anda.'),
                    t('Editar cadastro (um quadro que abre e fecha; no relógio novo, já aberto com o título "Novo relógio"): Nome (obrigatório); Grupo ("(na raiz)" e cada grupo pelo caminho, com a nota "os campos, os lançamentos e os critérios de um grupo valem para tudo abaixo dele"); depois os campos do cadastro, só os que valem para o grupo escolhido (mudar o grupo mostra e esconde na hora): sim/não como caixa; lista como menu (com "—"); data como campo de data; texto como campo; número como campo decimal, com o padrão de dica ("vazio: 5"); a Compra num grupo com título (Data da compra, Valor pago, Onde comprou, Garantia até); a Observação; no novo, a Foto; "Situação na coleção" (menu: Na coleção, Desejado, Emprestado, Vendido, Arquivado; no novo, a do endereço, index.php?novo=1&posse=desejado, senão Na coleção; os campos só nos desejados, como o Preço-alvo e a Prioridade, aparecem só com Desejado, e a caixa do disponível só com Na coleção, na hora); "Disponível para o rodízio" (marcada no novo); "Guardar no banco a cópia de todos os documentos dele" (só quando o config.php não decide); botão "Cadastrar relógio" ou "Salvar alterações" (relogio salvar). Embaixo, "Excluir este relógio" (discreto; confirma "Excluir <nome> com todo o histórico e a foto?").'),
                    t('Carga ao longo do tempo (largo; nos que têm leitura): um gráfico de linha das leituras (eixo de 0 a 100%, guias em 0, 50 e 100%, pontos verdes em uso e vermelhos em repouso, a linha em safira), com a legenda em pares rótulo/valor, menores (Pontos: as bolinhas "leitura em uso" e "leitura em repouso"; Período: "DD/MM a DD/MM"; Escala: "de 0 a 100%"; A conta: "de onde vem cada número: no quadro Previsão"); com menos de duas leituras, "O gráfico aparece a partir de duas leituras de carga." Embaixo, "Medições do gasto" (no quadro estreito, até 40rem, como no painel ao lado: cada medição vira um cartão, a data em negrito e embaixo cada valor com o nome da coluna em maiúsculas cinza na frente): tabela Quando, Leituras ("89 → 75"), Horas ("14 h no pulso, 2 h fora"), Gasto medido ("% por dia de uso" ou "fora do pulso"), Na conta (sim; não (fora da janela); não (só histórico)), as 10 mais recentes.'),
                    t('Dados do relógio (largo): duas tabelas lado a lado (o valor numérico, a data e o sim/não numa linha só; o valor de texto, como a observação ou uma fórmula que dá texto, quebra a linha dentro da coluna, sem invadir a tabela do lado): Cadastro (Campo, Valor, Origem com a etiqueta informado/padrão/vazio) e Calculado agora (Fórmula com o identificador em código, Valor com a unidade (segundos por extenso), Versão).'),
                    t('Histórico (largo): "<n> registros desde DD/MM/AAAA HH:MM. Agora <estado> há <duração>." e a tabela das 5 linhas mais recentes (Quando com "até ..." embaixo, Duração, Estado em etiqueta ou o texto da marcação) e o botão leve "Abrir o histórico completo" (historico.php numa aba nova).'),
                    t('Depois de gravar no painel, o recado aparece no topo do painel e a página e o painel se remontam sem perder os quadros abertos nem a rolagem; excluir o relógio recarrega a página.'),
                ]],
            ],
        ],
        [
            "id" => "ficha",
            "pagina" => "ficha.php",
            "titulo" => t('Ficha do relógio'),
            "endereco" => t('ficha.php?id=<id> (sem id: o primeiro)'),
            "le" => [
                t('recurso=hoje (a lista)'),
                "recurso=ficha",
            ],
            "grava" => [
                t('as mesmas do painel do relógio'),
            ],
            "partes" => [
                ["titulo" => t('Escolha e painel'), "itens" => [
                    t('No alto, "Relógio" e uma lista com todos (os disponíveis primeiro, o estado entre parênteses nos outros: "(indisponível)", "(emprestado)"); havendo relógios fora da coleção, a lista vem em dois grupos, "Na coleção" e "Fora da coleção" (cada um com a situação: "(desejado)", "(vendido)", "(arquivado)"); trocar abre o escolhido. Embaixo, o mesmo painel do relógio da página Hoje, numa página própria de até 72rem, com os quadros lado a lado e sem o ×. Sem relógios: "Nenhum relógio cadastrado. Cadastre o primeiro." (link para index.php?novo=1).'),
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
                t('relogio: salvar (id e posse=colecao: o Comprei e o Voltar para a coleção)'),
            ],
            "partes" => [
                ["titulo" => t('Lista de desejos'), "itens" => [
                    t('h1 "Lista de desejos" com o botão "Novo desejo" à direita (index.php?novo=1&posse=desejado: o cadastro de um relógio novo já como Desejado). A nota: sem desejados, como um relógio entra na lista ("Novo desejo" ou a "Situação na coleção" do cadastro) e que ele não entra no rodízio nem nos avisos; com desejados, "<n> relógios desejados, a prioridade mais alta primeiro. Somando os preços-alvo: R$ <total> (<n> sem preço-alvo). Comprou? ...".'),
                    t('Tabela (vira fichas até 900px) Relógio (a miniatura, o nome com o link da ficha e, embaixo, menor, "marca · referência"), Prioridade, Preço-alvo ("R$ 1.234,56" ou —), Grupo (o caminho; — na raiz), Link ("abrir a página" numa aba nova, só para http:// e https://; outro texto, como está; —) e o botão leve "Comprei" (confirma "Comprou o <nome>? Ele entra na coleção, disponível para o rodízio, e a ficha dele abre para você preencher a compra."; gravado, abre ficha.php?id=<id>).'),
                ]],
                ["titulo" => t('Fora da coleção'), "itens" => [
                    t('h2 "Fora da coleção", a nota (fora do rodízio, do plano e dos avisos; o emprestado continua na tabela da página Hoje e no valor da coleção; a ficha, os documentos e o histórico ficam) e a tabela Relógio (miniatura e nome), Situação (etiqueta lilás: emprestado, vendido, arquivado), Grupo e o botão leve "Voltar para a coleção" (confirma; gravado, abre a ficha).'),
                    t('Na página Hoje, ao lado do "Novo relógio", o botão leve "Desejos (<n>) · Fora da coleção (<n>)" (só as partes que têm algum; sem nenhum, o botão some) leva a esta página.'),
                ]],
            ],
        ],
        [
            "id" => "estatisticas",
            "pagina" => "estatisticas.php",
            "titulo" => t('Estatísticas'),
            "endereco" => t('estatisticas.php?de=AAAA-MM-DD&ate=AAAA-MM-DD (de=tudo: desde o primeiro uso; sem nada: os últimos 90 dias)'),
            "le" => [
                "recurso=estatisticas",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => t('Período e resumo'), "itens" => [
                    t('h1 "Estatísticas" com "de DD/MM/AAAA a DD/MM/AAAA · N dias" em cinza à direita. Filtro num cartão: De, Até (máximo hoje), "Ver" e os atalhos 30 dias, 90 dias, 1 ano, desde o começo.'),
                    t('Quatro caixas (duas por linha até 900px): "<n>" dias com relógio no pulso (de <dias>); "<h> h" no pulso · <h> h por dia com relógio; "<usados> de <coleção>" relógios usados · <n> sem uso; "R$ <custo médio>" custo por uso, em média · coleção: R$ <valor>. Embaixo, "Mais usado: <link> (<h> h, <n> dias). Menos usado (dos que foram ao pulso): <link> (<h> h)."'),
                ]],
                ["titulo" => t('Cada relógio'), "itens" => [
                    t('Cartão com a nota do que cada coluna é e a tabela (fichas até 900px) Relógio (o link da ficha e, embaixo, menor, o grupo e a situação fora da coleção), Dias de uso, Horas ("12,5 h"), A parte (a barra horizontal safira com a largura relogios[].barra e "56,8%"), Custo por uso ("R$ 83,30" ou —), Sem uso há ("3 dias", "usado hoje", "nunca usado"), Tendência ("↑ usando mais" em verde, "↓ usando menos" em vermelho, "→ estável", "· poucos dados" em cinza; a dica com as horas de cada metade).'),
                ]],
                ["titulo" => t('Gráficos'), "itens" => [
                    t('Dois cartões lado a lado (um embaixo do outro até 900px): Por dia da semana e Por mês, cada um um gráfico de barras verticais em SVG (largura 100%, as barras safira com a altura da barra da API, o valor "14 h" em cima de cada uma quando são até 8, os rótulos embaixo: seg...dom, set/26..., no máximo uns 7 rótulos) e a nota com os números (a média por dia de cada dia da semana; as horas e quantos relógios em cada mês).'),
                ]],
                ["titulo" => t('A coleção'), "itens" => [
                    t('Cartão com a nota e as divisões em grade (lado a lado onde cabem, cada uma com pelo menos 22rem): h3 com o título e a tabela nome, "<n> relógios", a barra com a porcentagem e as horas; numa divisão estreita (até 26rem), cada linha em duas: o nome em negrito em cima e os números embaixo.'),
                ]],
            ],
        ],
        [
            "id" => "hora",
            "pagina" => "hora.php",
            "titulo" => t('Hora certa'),
            "endereco" => "hora.php",
            "le" => [
                t('recurso=hora_certa (ao abrir e a cada minuto)'),
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => t('O relógio'), "itens" => [
                    t('h1 "Hora certa" com "<fuso> · UTC<deslocamento>" em cinza. Um cartão com o mostrador em SVG (até 15rem: caixa branca de borda escura, 60 marcas, as das horas grossas; ponteiros das horas e dos minutos escuros e arredondados, o dos segundos vermelho fino, o eixo vermelho) e ao lado (embaixo, no celular) a hora digital grande (HH:MM:SS, números de largura fixa), a data por extenso, a hora UTC em cinza e a nota "O relógio deste aparelho está certo (menos de meio segundo de diferença)." ou "... está <n> s adiantado/atrasado." e de onde vem a hora.'),
                    t('O relógio da página: a diferença é agora_ms menos o meio do pedido (antes e depois); a cada virada de segundo do relógio acertado, os ponteiros e a hora digital andam (no fuso.segundos da API); a cada minuto, lê a API de novo.'),
                ]],
                ["titulo" => t('Os quadros'), "itens" => [
                    t('Três cartões lado a lado (um embaixo do outro até 900px), com pares rótulo/valor: Fuso e UTC (Fuso com a abreviação, Deslocamento, UTC (Zulu), Letra militar "P (Papa)", Grupo data-hora local e Zulu em código, ISO 8601), Calendário (Hoje, Dia do ano "286 de 365 · faltam 79", Semana (ISO), Trimestre, Dia juliano, Unix) e Lua (Fase com o símbolo, Idade, Iluminada, Próxima cheia, Próxima nova e a nota do ciclo médio). Embaixo, o cartão Como acertar com o texto da API.'),
                ]],
            ],
        ],
        [
            "id" => "acessorios",
            "pagina" => "acessorios.php",
            "titulo" => t('Acessórios'),
            "endereco" => t('acessorios.php?relogio=<id> (0: os soltos; sem ele: todos)&editar=<id do acessório>'),
            "le" => [
                t('recurso=acessorios (com relogio)'),
            ],
            "grava" => [
                t('acessorios: salvar, excluir'),
            ],
            "partes" => [
                ["titulo" => t('Lista e cadastro'), "itens" => [
                    t('h1 "Acessórios" com "<n> acessórios [do <relógio> | soltos] · 1 pulseira, 2 caixas..." em cinza. Um cartão com o filtro Relógio (Todos, Soltos (de nenhum relógio) e cada relógio; trocar abre o filtrado).'),
                    t('Tabela (fichas até 900px) Acessório, Tipo, Relógio (o link da ficha, ou "solto" em cinza), Observação (cinza; —) e as ações Editar (link: editar=<id>#form) e Excluir (botão leve discreto; confirma "Excluir o acessório <nome>?"). Vazia: "Nenhum acessório. Cadastre abaixo."'),
                    t('Cartão Novo acessório (ou "Alterar <nome>", que a página rola até ele), em grade: Nome (obrigatório, até 120, a dica "Pulseira de couro marrom"), Tipo (Pulseira, Caixa, Estojo, Ferramenta, Carregador, Peça, Outro), Relógio ("— solto (serve a vários) —" e cada relógio; no novo, o do filtro), Observação (texto, até 1000) e "Cadastrar" ou "Salvar" e "cancelar". Gravou: volta à lista, com o mesmo filtro; recusou: o alerta com os erros.'),
                ]],
            ],
        ],
        [
            "id" => "galeria",
            "pagina" => "galeria.php",
            "titulo" => "Galeria",
            "endereco" => t('galeria.php (posse=todos: também os de fora da coleção)'),
            "le" => [
                "recurso=galeria",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => t('Fotos por grupo'), "itens" => [
                    t('Uma barra com o botão "Imprimir" (window.print) e o leve "Também os de fora da coleção" / "Só a coleção". h1 "Galeria" com "<n> relógios · R$ <valor> · <n> com foto" em cinza. Um cartão por grupo (h2 com o caminho) com a grade das fotos (colunas de pelo menos 9rem: duas no celular): a foto quadrada (ou o quadrado "Sem foto"), com o link da ficha, e embaixo o nome em negrito, "marca · referência" e, menor, "ano · R$ valor · <n> dias de uso" em cinza, e a etiqueta lilás da situação fora da coleção. No pé, "Gerado em ... pelo Relojoeiro." No papel (@media print): sem o menu e sem a barra, fundo branco, quatro fotos por linha, nenhuma partida entre páginas.'),
                ]],
            ],
        ],
        [
            "id" => "passaporte",
            "pagina" => "passaporte.php",
            "titulo" => t('Passaporte do relógio'),
            "endereco" => "passaporte.php?id=<id>",
            "le" => [
                "recurso=passaporte",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => t('A ficha para imprimir'), "itens" => [
                    t('Uma barra com "Imprimir", o leve "Voltar para a ficha" e a nota de que no papel saem só os dados. A cabeça: a foto (9rem), "Passaporte do relógio · código N", o nome em h1, "marca · referência · caminho", a situação ("Na coleção · no rodízio · no sistema desde DD/MM/AAAA") e a observação.'),
                    t('Cartões (largura até 60rem; os pares lado a lado onde cabem, um embaixo do outro até 700px): Compra e Uso; Cadastro (os valores já em texto); Manutenções (cada tipo: "<n> vezes · a última em DD/MM/AAAA", e a lista das revisões e trocas de pilha); Acessórios e Documentos; Próximos avisos. Vazios: "Sem dados da compra.", "Ainda não foi ao pulso.", "Nada preenchido.", "Nenhuma marcação.", "Nenhum." No pé, "Gerado em ...". No papel: só os cartões, nenhum partido entre páginas.'),
                ]],
            ],
        ],
        [
            "id" => "plano",
            "pagina" => "plano.php",
            "titulo" => "Plano",
            "endereco" => "plano.php",
            "le" => [
                t('recurso=plano (com dias=30, 90, 365, 730 ou todos; pagina e relogio)'),
            ],
            "grava" => [
                t('rodizio: trocar_dia, periodo_sortear, periodo_livre, apagar_tudo'),
            ],
            "partes" => [
                ["titulo" => t('Cabeça e filtros'), "itens" => [
                    t('h1 "Plano" e a nota "Modo: <modo> · a escala vai até DD/MM/AAAA · o plano gravado vai até DD/MM/AAAA. Troque o relógio de qualquer dia em "trocar por…": o dia fica escolhido à mão (e a escala é refeita a partir dele)." Filtros lado a lado: Período (Próximos 30 dias, Próximos 90 dias (o padrão), Próximo ano, Próximos 2 anos, Tudo; trocar relê a API) e Relógio (Todos e cada um; filtra o dia a dia na tela).'),
                ]],
                ["titulo" => t('O período à mão'), "itens" => [
                    t('Uma nota: "Para escolher os relógios de um período, dia a dia: na página Hoje, no quadro Modo de rodízio, escolha "Rodízio manual". Para apagar a programação, veja o fim desta página." (o link Hoje vai para index.php#manual).'),
                ]],
                ["titulo" => t('Resumo do período (<n> dias)'), "itens" => [
                    t('Tabela Relógio, Dias, %, Próximo dia ("DD/MM/AAAA seg"), À mão; do que tem mais dias ao que tem menos. Embaixo, "Sem nenhum dia no período: <relógios>." Sem dias: "Nenhum dia no plano neste período."'),
                ]],
                ["titulo" => t('Dia a dia'), "itens" => [
                    t('Os dias livres (livres, da API) entram entre os do plano, na ordem das datas: no lugar do relógio, "livre: escolha à mão" em cinza com a marca "livre", e a lista do dia começa em "escolher…" (com "↺ voltar a sortear"). Um título por mês ("Outubro de 2026") e uma tabela de dias: o dia ("DD/MM" e o dia da semana, "hoje" no de hoje, com a faixa safira), o relógio (com "à mão" e o motivo embaixo), o lembrete e o "trocar por…" (como na página Hoje). Fim de semana com fundo cinza claro. Sem dias: "Neste modo o sorteio é feito semana a semana: o plano tem só a semana atual." ou "Nenhum dia." Os dias vêm da API (plano.dia_a_dia, com pagina e relogio: o filtro de relógio relê a API), de "Mostrar até" em "Mostrar até" (a Configuração, padrão 31); passando disso, o navegador de páginas embaixo (cada página relê a API e rola até o dia a dia) e "Página N de M · <total> dias, <n> por página (o "Mostrar até" da Configuração)."'),
                ]],
                ["titulo" => t('Rodapé: Apagar a programação (um cartão no fim da página, depois do dia a dia, separado por uma margem grande e uma borda de 3px aço escuro em cima)'), "itens" => [
                    t('Título h2 "Apagar a programação". "Apagar um período" (o rótulo em cinza): numa linha que quebra, "De" e "Até" (datas, de hoje em diante; um dos dois pode ficar vazio) e o botão leve "Apagar" (rodizio periodo_sortear). Embaixo, a nota "Os dias do período saem do plano, de amanhã em diante (os escolhidos à mão, os sorteados e os livres), e o modo de rodízio monta esses dias de novo. Pode deixar um lado vazio: só o começo apaga dele até o fim do plano; só o fim apaga de amanhã até ele." Confirma "Apagar os dias deste período do plano (escolhidos à mão, sorteados e livres)? O modo de rodízio monta esses dias de novo." Sem nenhuma das datas, a API recusa ("Dê o começo, o fim ou os dois ...").'),
                    t('Abaixo de uma linha, "Apagar tudo": a caixa "Excluindo hoje (apaga também o dia de hoje)" e o botão leve "Apagar tudo" (rodizio apagar_tudo, com hoje=1 marcada), e a nota "Limpa toda a programação, de amanhã em diante: os dias escolhidos à mão, os sorteados e os livres. Marcando "Excluindo hoje", apaga de hoje em diante (se sair outro relógio para hoje, ele passa a ser o do pulso a partir de agora). Depois, o modo de rodízio monta o plano de novo, do zero." Confirma (com hoje: "Apagar toda a programação, de hoje em diante (escolhidos à mão, sorteados e dias livres)? O modo de rodízio monta o plano de novo, e hoje pode sair outro relógio, que passa a ser o do pulso a partir de agora."; sem: "... de amanhã em diante ...; hoje continua como está.").'),
                ]],
            ],
        ],
        [
            "id" => "configuracao",
            "pagina" => "configuracao.php",
            "titulo" => t('Configuração'),
            "endereco" => t('configuracao.php; ?evento=<id> abre um evento para editar; #personalizados'),
            "le" => [
                t('recurso=migracoes (antes de tudo)'),
                "recurso=config",
                "recurso=backup",
            ],
            "grava" => [
                t('config: salvar, evento_salvar, evento_excluir'),
                t('migracoes: aplicar (só o master)'),
                t('backup: fazer, restaurar (só o master)'),
            ],
            "partes" => [
                ["titulo" => t('Banco desatualizado (só quando há migração pendente)'), "itens" => [
                    t('Um cartão só: "O banco está desatualizado", a explicação ("Esta versão do sistema precisa de migrações que ainda não foram aplicadas... as páginas, a API e o cron ficam parados..."), a tabela das pendentes (versão em negrito, o que traz, o arquivo em código) e o botão "Aplicar as migrações" (só o master aplica; a v25 guarda antes uma cópia do banco antigo em relojoeiro-antes-da-v25.jsonl.gz).'),
                ]],
                ["titulo" => t('Os quadros do alto (uma grade de 3 colunas; um formulário só, salvo pela barra do pé)'), "itens" => [
                    t('Cada opção numa linha: o rótulo à esquerda e o campo à direita, alinhados na mesma coluna em todos os quadros; caixas de marcar no lugar do campo; textos longos com o rótulo em cima e o campo na largura toda. Cada quadro com a explicação embaixo.'),
                    t('Rotina do dia: Manhã: relógio do dia e avisos (hora); Noite: preparar o de amanhã (hora); No pulso a partir de (hora); Pôr no pulso sozinho (caixa); No pulso até (hora); Tirar do pulso sozinho (caixa); Sessão no sol esquecida fecha às (hora).'),
                    t('Limites e contas: Carregar quando a carga chegar a (%); Solar: pôr no sol quando chegar a (%); Gasto medido: média dos últimos (dias); Aviso Encostado: dias sem ir ao pulso; Máximo de dias sem uso; Previsão: limite (%).'),
                    t('Telas: Abrir o relógio na página Hoje (Ao lado da lista; Numa janela flutuante, grande); Destaque no alto da página Hoje; Próximos dias e Plano: mostrar até (dias); Idioma padrão da coleção; Endereço do sistema (a âncora {link}: só o master muda; para os outros, o texto).'),
                    t('Cron: "Cron rodando: última execução às HH:MM." (nota) ou, em âmbar, "O cron ainda não rodou" / "O cron não roda desde ..." com a linha do crontab em código; o erro guardado numa caixa; o link "Ver todas as execuções do cron" e o registro da última rodada (o master vê o do sistema; os outros, o da coleção).'),
                    t('Sorteios (só leitura): de onde vêm os números (RANDOM.ORG, ANU QRNG, o gerador seguro do PHP) e o último lote.'),
                ]],
                ["titulo" => t('Mensagens e agendas'), "itens" => [
                    t('Um quadro com dois lados: "Destinos das mensagens" (cada destino com o nome, o tipo e "(desligado)" quando está; o link para a página Destinos; vazio: "Nenhum ainda: as mensagens da manhã, da noite e os avisos não saem para lugar nenhum.") e "Agendas" (cada agenda; o link para a página Agendas; vazio: "Nenhuma ainda."), e a explicação "Um destino é um webhook (Telegram, n8n...), um e-mail ou o Web Push do navegador; uma agenda é o Google, um CalDAV (Nextcloud, iCloud, Fastmail), o Outlook ou um link ICS...".'),
                    t('A barra de salvar no pé: "As mudanças desta página valem depois de salvar." e o botão "Salvar configuração" (config salvar).'),
                ]],
                ["titulo" => t('Eventos personalizados'), "itens" => [
                    t('A tabela dos eventos: Evento (o nome), Quando (a repetição por extenso), Relógio, Vai para (os destinos e as agendas que recebem) e as ações Editar e Excluir (Excluir mostra o que sai junto e pede a confirmação). Embaixo, o quadro que abre e fecha ("Novo evento" ou "Editar o evento <nome>"): Nome (obrigatório), Relógio (opcional), Quando dispara (uma vez, todo dia, toda semana, todo mês, a cada N dias), e só os campos da repetição escolhida. O evento novo já vai para todos os destinos.'),
                ]],
                ["titulo" => t('Cópia de segurança (no fim da página; só o master)'), "itens" => [
                    t('Título "Cópia de segurança". Com a pasta: a nota "Uma cópia do banco inteiro por dia, na rodada da noite, guardando as N mais novas, sem (ou com) o conteúdo dos documentos... Última: DD/MM/AAAA HH:MM."; sem a pasta, em âmbar, o aviso de que falta a BACKUP_PASTA.'),
                    t('A tabela compacta das cópias (da mais nova para a mais velha): Cópia (o nome do arquivo em fonte fixa), Feita em, Tamanho, Documentos (com/sem) e, à direita, "Baixar" e "Restaurar" (confirma antes). Com BACKUP_ATIVO = false, a seção inteira não aparece.'),
                ]],
            ],
        ],
        [
            "id" => "criterios",
            "pagina" => "criterios.php",
            "titulo" => t('Critérios de escolha'),
            "endereco" => t('criterios.php?escopo=<"", g:<grupo> ou r:<relógio>>; ?relogio=<id> abre a conta dele'),
            "le" => [
                "recurso=criterios",
            ],
            "grava" => [
                t('criterios: todas as ações'),
            ],
            "partes" => [
                ["titulo" => t('Explicações'), "itens" => [
                    t('h1 "Critérios de escolha do relógio". Cartão "Como a nota funciona" (lugar, conjunto, parâmetro, subparâmetro, faixas, peso efetivo e nota final, em negrito os termos). Cartão "Como a nota vira escolha": as quatro formas com a explicação, a tabela Modo de rodízio / Forma de escolha ("(o modo atual)") e a garantia de rodízio.'),
                ]],
                ["titulo" => t('Onde há critérios'), "itens" => [
                    t('Tabela Lugar, Parâmetros (quantos), Usado por ("N relógios" e os nomes em cinza) e o link "editar". Em vermelho: "Há relógios sem conjunto nenhum (nota neutra, 50): ..."'),
                ]],
                ["titulo" => t('O conjunto do lugar escolhido'), "itens" => [
                    t('"Editar os critérios de" com a lista de todos os lugares ("Todos os relógios", "Grupo: <caminho>", "Relógio: <nome>"; "— com critérios próprios" nos que têm) e "Abrir". Sem critérios próprios: "<Lugar> não tem critérios próprios: usa os de <lugar de cima>." e os botões "Criar critérios próprios, copiando os de <lugar>" e "Criar vazio" (leve).'),
                    t('Com critérios: "Critérios de <lugar>", "Usado por N relógios: ..." e a tabela Parâmetro (o nome editável), Peso no conjunto (campo curto e "%"), Subparâmetros (link para a seção; "(fora da conta)" em vermelho se nenhum), e ↑ ↓ Excluir; no rodapé "Soma" com a soma ao vivo e "fecha 100%" ou, em vermelho, "faltam N%" / "passa N%"; botão "Salvar parâmetros". Excluir confirma mostrando como o peso se divide entre os que ficam. Linha de inclusão: Novo parâmetro, Peso (%), "Incluir". Embaixo, "Excluir os critérios próprios de <lugar>" (discreto, confirma).'),
                ]],
                ["titulo" => t('Cada parâmetro'), "itens" => [
                    t('Um cartão por parâmetro: "<nome> <peso> do conjunto de <lugar>"; a tabela Subparâmetro, Mede, Peso no parâmetro, Peso efetivo no conjunto (atualizado ao digitar), ↑ ↓ Excluir, com a soma no rodapé e "Salvar subparâmetros".'),
                    t('Para cada subparâmetro, "Faixas de <nome>" (abre e fecha, com quantas faixas e o que mede): numa medida numérica, a tabela De, Até, Nota, Apagar; numa de categoria, Categoria (lista), Nota, Apagar; sempre uma linha em branco para incluir, o botão "+ faixa" (mais uma linha) e "Salvar faixas"; a regra das faixas em nota. Embaixo, "Trocar a medida para" (confirma: as faixas recomeçam) e "Mover para o parâmetro".'),
                    t('Linha de inclusão: Novo subparâmetro, Mede (lista das medidas), Peso (%), "Incluir". Sem subparâmetros, em vermelho: "Sem subparâmetros: este parâmetro não entra na conta até ganhar um."'),
                ]],
                ["titulo" => t('A nota de cada relógio agora'), "itens" => [
                    t('Um quadro que abre e fecha por relógio disponível, da maior nota para a menor: o nome em negrito, "<grupo> · critérios de <lugar>" e a nota à direita; aberto, a tabela Parâmetro, Subparâmetro, Valor medido, Faixa, Nota, Peso efetivo, Pontos, com a nota final no rodapé, e os links "editar os critérios de <lugar>" e "critérios só deste relógio". Por fim, o cartão "Restaurar os critérios iniciais" com o botão leve "Restaurar" (confirma).'),
                ]],
            ],
        ],
        [
            "id" => "grupos",
            "pagina" => "grupos.php",
            "titulo" => t('Grupos de relógios'),
            "endereco" => "grupos.php",
            "le" => [
                "recurso=arvore",
            ],
            "grava" => [
                t('arvore: novo, renomear, mover, ordem, excluir, relogios'),
            ],
            "partes" => [
                ["titulo" => t('Árvore'), "itens" => [
                    t('A explicação; a árvore como lista com recuo por nível. Cada grupo: o nome num campo com "Renomear" (discreto), "N relógios" ("· critérios próprios" com link), ↑ ↓ e Excluir (confirma dizendo para onde vão os subgrupos, os relógios e o que acontece com os critérios); os relógios dele em cinza embaixo; e "subgrupo e mover" (abre e fecha): Novo subgrupo + "Criar", Mover para dentro de (só onde não forma ciclo) + "Mover". No fim: linha de inclusão "Novo grupo", "dentro de" e "Criar". Sem grupos: "Nenhum grupo ainda."'),
                ]],
                ["titulo" => t('O grupo de cada relógio'), "itens" => [
                    t('Tabela Relógio ("(indisponível)" em cinza) e Grupo (lista com "(na raiz)" e os caminhos), e o botão "Salvar grupos" (arvore relogios, todos de uma vez).'),
                ]],
            ],
        ],
        [
            "id" => "execucoes",
            "pagina" => "execucoes.php",
            "titulo" => t('Execuções do cron'),
            "endereco" => t('execucoes.php?de=&ate=&situacao=&busca=&por=&pagina= (os filtros no endereço)'),
            "le" => [
                "recurso=cron",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => "Tudo", "itens" => [
                    t('Título com "Última execução: DD/MM/AAAA HH:MM:SS". Filtros num cartão: De, Até (padrão: os últimos 7 dias), Situação (Com atividade (padrão), Com erro, Sem atividade, Todas), Texto no registro, Por página (25, 50, 100, 200), "Filtrar" e os atalhos hoje, 7 dias, 30 dias, erros do último ano.'),
                    t('Resumo em 4 caixas: execuções no período, com atividade, com erro (vermelho se houver), a mais lenta. A nota com quantas e a retenção. Tabela Início (data e a hora em negrito embaixo), Duração ("850 ms" ou "1,5 s"), Situação (etiqueta erro / atividade / sem atividade), Registro (o texto em fonte fixa, ou "nada a fazer neste minuto"); linha com erro em vermelho claro. Paginação: « primeira, ‹ anterior, até 3 números de cada lado, próxima ›, última ».'),
                ]],
            ],
        ],
        [
            "id" => "historico",
            "pagina" => "historico.php",
            "titulo" => t('Histórico — <relógio>'),
            "endereco" => "historico.php?id=<id>&de=&ate=&estado=&por=&pagina=",
            "le" => [
                "recurso=historico",
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => "Tudo", "itens" => [
                    t('Cabeça com a foto, "Histórico · <tipo> · código N", o nome em h1 e "<n> registros desde ...". Filtros num cartão: De, Até, Estado (Todos, Em uso, Fora do rodízio, cada tipo de sessão, Em repouso, Marcações), Por página (25, 50, 100, Todos), "Filtrar" e os atalhos 7 dias, 30 dias, desde o começo.'),
                    t('Resumo em caixas: o tempo em cada estado ("5d 7h" e "em uso · 13%") e a contagem de cada marcação. A nota de quantos e de como ler os trechos. Tabela Início, Fim, Duração, Estado (etiqueta verde para uso, vermelha para repouso, laranja para as outras sessões; marcação em cinza, só o texto; "em andamento: o fim é o momento desta consulta"). Paginação: « mais recentes, ‹ anterior, números, próxima ›, mais antigos » e "ver tudo".'),
                ]],
            ],
        ],
        [
            "id" => "documentos",
            "pagina" => "documentos.php",
            "titulo" => "Documentos",
            "endereco" => t('documentos.php?relogio=<id>&cat=<categoria> (sem relógio: de todos)'),
            "le" => [
                "recurso=documentos",
                t('recurso=documento (cada arquivo)'),
            ],
            "grava" => [
                t('documentos: enviar, alterar, excluir'),
                t('relogio: salvar (a cópia no banco do relógio)'),
            ],
            "partes" => [
                ["titulo" => t('Cabeça'), "itens" => [
                    t('h1 "Documentos do <relógio>" (ou "de todos os relógios"); a lista "Relógio" (Todos e cada um, com "(N)") e "Abrir a ficha do relógio". A situação da cópia no banco (quem decide: o config.php, o relógio, com o botão "Guardar todos os dele no banco" / "Só os marcados"). Sem a pasta: o motivo em âmbar.'),
                ]],
                ["titulo" => t('Enviar arquivos (abre e fecha; aberto quando não há nenhum; só com um relógio escolhido)'), "itens" => [
                    t('Categoria (com o que ela aceita), Título ("vazio: o nome do arquivo"), Data, Descrição, Arquivos (vários; o seletor só deixa os tipos da categoria), "Guardar a cópia destes arquivos também no banco" (só quando cabe ao arquivo decidir), o limite de tamanho, "Enviar" e o andamento ("Enviando 2 de 5: foto.jpg — 40%"). Um arquivo por vez, cada foto com a miniatura feita no navegador (480px, JPEG 80%). No fim: "N documentos guardados." e os erros.'),
                ]],
                ["titulo" => t('Abas e conteúdo'), "itens" => [
                    t('Abas "Todos (N)" e cada categoria "(N)". Fotos: a galeria (quadrados de 140px, 100px no celular, com o título e a data) e o botão "▶ Apresentação"; tocar numa foto abre o visor em tela cheia (fundo #080b0f): "N de M", "▶ Apresentação" / "❚❚ Parar" (troca a cada 4 s), "Baixar", "×"; ‹ e › nos lados, setas do teclado, deslizar o dedo, Esc fecha, espaço liga a apresentação; a legenda embaixo.'),
                    t('Vídeos: o player e a lista para marcar e ordenar (↑ ↓), "▶ Assistir os marcados" (tocam em sequência; "‹ Anterior", "Próximo ›", "Fechar"). Arquivos: um cartão por arquivo com o título, a data, a categoria em etiqueta, a descrição, o nome, o tamanho e onde está guardado; PDF: "Ver aqui" (abre embutido), "Abrir noutra aba", "Baixar"; XML de NF-e: a tabela Emitente, Nota, Valor, Produto(s), Chave e "Baixar o XML"; áudio: o player; o resto: "Baixar".'),
                    t('Cada foto (no canto de cima da miniatura), cada vídeo da lista, cada cartão de arquivo e o visor das fotos têm o botão redondo "⋮" (a dica "Abrir, baixar, editar ou excluir"): ele abre o menu Abrir (a foto no visor, o vídeo no player, o PDF e o resto noutra aba; não aparece no visor), Baixar, Editar e Excluir (em vermelho). Um menu aberto de cada vez; um clique fora ou Esc fecha. Editar abre uma janela por cima da página ("Editar <título>", o nome do arquivo, e Título, Data, Categoria, Descrição, a cópia no banco quando cabe, "Salvar" e "Cancelar"); com ela aberta, as setas e o espaço do visor não agem. Excluir mostra o que sai junto ("Sai junto: o arquivo no disco (6 KB).") e pede a confirmação; excluiu no visor, o visor fecha.'),
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
                t('recurso=calcular (o testar)'),
            ],
            "grava" => [
                t('campos, lancamento_tipos, formulas, avisos, modos, documento_categorias'),
            ],
            "partes" => [
                ["titulo" => t('Em todas as abas'), "itens" => [
                    t('h1 "Cadastros" e as abas Campos, Tipos de lançamento, Fórmulas, Avisos, Modos de rodízio, Categorias de documentos. Cada aba é um cartão: o título, a explicação, a tabela com "Editar" (link que abre o formulário embaixo com os dados, no #form) e Excluir em cada linha, e o formulário ("Novo ..." ou "Alterar ...", botão "Criar" / "Salvar" e "cancelar"). Gravou: o recado no alto e a edição acaba; recusado: o que foi digitado fica e os erros aparecem no topo.'),
                ]],
                ["titulo" => "Campos", "itens" => [
                    t('Tabela Campo ("(unidade)"), Identificador (fonte fixa), Tipo, Vale para, Padrão, ações (Editar, ↑, ↓, Excluir com confirmação "Excluir o campo <nome> e os valores dele?"). Formulário: Nome (até 120), Identificador (a-z, 0-9 e _; começa com letra; até 40), Tipo (Número inteiro, Número decimal, Sim ou não, Data, Lista de opções, Texto), Unidade (até 20), Valor padrão, Vale para ("todos os relógios" e os grupos), Opções (lista: uma por linha).'),
                ]],
                ["titulo" => t('Tipos de lançamento'), "itens" => [
                    t('Tabela Tipo, Identificador, Formato (com a unidade), Fecha sozinho às, Exclusiva, Mede o gasto, Vale quando (fonte fixa), Vale para, Lançamentos (quantos), ações. Formulário: Nome, Identificador, Formato (Instantâneo; Instantâneo com valor; Sessão, com início e fim), Unidade (com valor), Fecha sozinho às (hora; vazio: não fecha), Vale para, Vale quando (fórmula), as caixas "sessão exclusiva (o relógio num lugar só)" e "mede o gasto".'),
                ]],
                ["titulo" => t('Fórmulas'), "itens" => [
                    t('Tabela Fórmula (o nome e o identificador com a unidade), Versão para, Cálculo (fonte fixa), ações. Formulário: Identificador (só na nova), Nome, Unidade, Versão para, Cálculo (textarea, fonte fixa). "Testar uma fórmula (sem gravar)": o texto da fórmula e "Testar em todos os relógios" (recurso=calcular): a tabela Relógio, Resultado, As partes da conta, ou os erros. "As funções do motor" (abre e fecha): cada função em negrito e a explicação.'),
                ]],
                ["titulo" => "Avisos", "itens" => [
                    t('Tabela Aviso, Versão para, Data prevista, Vale quando, Antecedência ("N d"), Resolve, Na escala (com o valor ou as horas simuladas), Na agenda, Na hora (sim/não), Ativo, ações. Formulário: Identificador (na novo), Nome, Antecedência (dias), Resolve (os tipos), Versão para, ativo, "na hora (Telegram no minuto em que vence)" (caixa), Na escala inteligente (não; no relógio do dia; sempre), Escala: valor do lançamento simulado, Escala: horas da sessão simulada, Data na agenda (só dentro da antecedência; qualquer data), Data prevista (fórmula), Vale quando (fórmula), Texto (até 300).'),
                ]],
                ["titulo" => t('Modos de rodízio'), "itens" => [
                    t('Tabela Modo ("(ativo)"), Forma de escolha ("· com ciclo"), Blocos (nome e dias), ações (Editar; nos outros: Ativar e Excluir). Formulário: Nome, Planejamento (sorteio pelos blocos; escala inteligente de 7 dias, 30 dias, 60 dias, 6 meses, 1 ano, 2 anos), Forma de escolha, a caixa "ciclo", e a tabela dos blocos (os que existem e duas linhas vazias): Bloco (nome), Dias (caixas seg a dom), Sortear de, Um por (um por dia; um por bloco (na semana)), Relógio fixo ("— sorteia —" e os relógios). Bloco sem dia marcado sai.'),
                ]],
                ["titulo" => t('Categorias de documentos'), "itens" => [
                    t('Tabela Categoria, Identificador, Aceita ("qualquer arquivo" ou as famílias), Ordem, Documentos, ações (Excluir só sem documentos). Formulário: Nome, Identificador (na nova), Ordem ("vazio: no fim"), Aceita (caixas imagens, vídeos, áudios, PDF, XML).'),
                ]],
            ],
        ],
        [
            "id" => "usuarios",
            "pagina" => "usuarios.php",
            "titulo" => t('Usuários'),
            "endereco" => "usuarios.php",
            "le" => [
                "recurso=usuarios",
            ],
            "grava" => [
                t('usuarios: salvar, alterar, oficializar, prorrogar, voltar_demo, bloquear, transferir, dar_acesso, excluir'),
            ],
            "partes" => [
                ["titulo" => t('O painel do master'), "itens" => [
                    t('h1 "Usuários". Cartão "Contas (N)": a tabela Login (com os selos "master" em azul, "demo" e "bloqueada" e "você"), E-mail, Criada em, Último acesso, Vence (a data e "(N dias)", ou "não vence"), Coleções (cada uma com "(N relógios)"), Vê também (as coleções de outros e "tudo de <dono>") e, à direita, "Ações", que abre: "Ver como <login>" (grava o cookie como e abre o Hoje como ele), "Tornar oficial" (na demo) ou "Voltar a demo (dias)", "Prorrogar (dias)", "Bloquear"/"Desbloquear", o quadro Alterar (Login, E-mail, Idioma, Papel, "Salvar"), a senha nova com "Trocar a senha", "Transferir a coleção" (a coleção e o novo dono), "Dar acesso" (uma coleção de outro ou "Tudo de <dono>") e "Excluir" (mostra o que sai junto: as coleções, os relógios, os arquivos).'),
                    t('Cartão "Criar conta": Login, Senha (pelo menos 6 caracteres), E-mail, Coleção (uma com o conjunto inicial; uma vazia; nenhuma), a nota "A conta criada aqui é oficial (não vence). Se o login já existir, só a senha dele é trocada." e "Criar conta".'),
                    t('Quem não é o master vê só "Só o administrador (master) cuida das contas. As suas coisas ficam em Minha conta.".'),
                ]],
            ],
        ],
        [
            "id" => "conta",
            "pagina" => "conta.php",
            "titulo" => t('Minha conta'),
            "endereco" => t('conta.php; ?convite=<token> aceita o convite ao abrir'),
            "le" => [
                t('recurso=conta'),
                t('recurso=cadastro (a lista das coleções e dos donos)'),
            ],
            "grava" => [
                t('conta: alterar, token_criar, token_revogar, push_inscrever, push_cancelar, push_testar, email_testar, vapid_gerar, excluir'),
                t('colecoes: criar, renomear, excluir, arvore_copiar, arvore_renomear, arvore_excluir'),
                t('pedidos: pedir, cancelar'),
                t('acessos: sair'),
                t('convites: aceitar'),
            ],
            "partes" => [
                ["titulo" => t('Coleções'), "itens" => [
                    t('Tabela Coleção, Código (o id, que o dono passa a quem quer pedir acesso), Dono, Você é (sua, membro, master) e as ações: "Abrir" (grava o cookie colecao) ou "aberta"; nas suas, "Renomear" (abre o nome e "Salvar") e "Excluir" (mostra o que sai junto); nas dos outros, "Sair". Sem nenhuma: o aviso âmbar "Você ainda não vê nenhuma coleção: crie uma aqui embaixo, ou peça acesso à de alguém (e espere a aprovação)." Embaixo da tabela, a nota "Para alguém pedir acesso a uma coleção sua, passe o código dela...". Depois, "Nova coleção": Nome, Começa (com o conjunto inicial; vazia; com uma árvore minha que já existe, que abre a escolha da Árvore) e "Criar coleção" (a nova passa a ser a do menu).'),
                ]],
                ["titulo" => t('Árvores de grupos'), "itens" => [
                    t('Só quando há: a explicação ("A árvore guarda os grupos e o cadastro deles... Uma árvore pode servir a várias coleções suas: o que muda nela vale para todas.") e a tabela Árvore, Grupos, Usada por, com "Renomear ou copiar" (o nome e "Renomear"; "Cópia de <nome>" e "Copiar") e "Excluir" (mostra as coleções que saem junto).'),
                ]],
                ["titulo" => t('Pedir acesso'), "itens" => [
                    t('Os pedidos esperando (O quê, Pedido em, Vence em, "Cancelar"), quantos convites há para o seu e-mail ("aceite pelo link que chegou nele"), e o formulário: Acesso a ("uma coleção (o código da coleção)" ou "todas as coleções de um dono (o código do dono)"), Código (número), o quadro recolhido "Como pedir acesso (e por que pelo código)" (uma lista numerada: por que o código, como pedir o código ao dono e onde ele o encontra, o que preencher, o que acontece depois), Recado para o dono e "Pedir acesso". Nenhuma lista de contas nem de coleções; os pedidos esperando mostram só o código ("a coleção de código 7") até a aprovação. A nota "O dono (ou o administrador) aprova...".'),
                ]],
                ["titulo" => t('Seus dados'), "itens" => [
                    t('Login, os selos master e demonstração, desde quando e "seu código: N" (o id, para pedirem acesso a todas as suas coleções), e a nota "Alguém quer ver as suas coleções? Passe o seu código..." (como passar o código e o que acontece depois); na demonstração, o aviso âmbar "Conta de demonstração: vence em N dias (data)..."; o formulário E-mail, Idioma (O da coleção e os seis), Senha atual (só para trocar a senha), Senha nova, a nota e "Salvar".'),
                ]],
                ["titulo" => t('Tokens de API'), "itens" => [
                    t('A explicação (cabeçalho X-Api-Token; vale como o seu login; só aparece ao criar); o token recém-criado numa caixa tracejada em fonte fixa (para copiar); a tabela Nome, Criado em, Último uso e "Revogar"; o formulário com o nome ("onde vai usar") e "Criar token".'),
                ]],
                ["titulo" => t('Notificações neste aparelho (Web Push)'), "itens" => [
                    t('Sem as chaves no servidor, o aviso âmbar; sem suporte no navegador, a nota (no iPhone, adicionar à tela de início); senão "Receber notificações neste aparelho" (pede a permissão, registra o push.js e inscreve). A tabela dos aparelhos (Aparelho, Desde, "Desligar") e "Mandar uma notificação de teste". Para o master, "Chaves do Web Push (master)": "Gerar chaves" mostra as duas linhas para o config.php.'),
                ]],
                ["titulo" => t('Servidor de e-mail (só o master)'), "itens" => [
                    t('A nota (configurado ou não) e, configurado, o endereço (para; vazio: só a conexão) e "Testar o e-mail".'),
                ]],
                ["titulo" => t('Excluir a conta'), "itens" => [
                    t('A explicação do que sai e "Excluir a minha conta" (botão vermelho; a API mostra tudo o que sai junto e pede a confirmação; depois, vai para o cadastro).'),
                ]],
            ],
        ],
        [
            "id" => "aprovacoes",
            "pagina" => "aprovacoes.php",
            "titulo" => t('Aprovações e convites'),
            "endereco" => t('aprovacoes.php'),
            "le" => [
                t('recurso=aprovacoes'),
                t('recurso=conta, parte=menu (o seu login)'),
            ],
            "grava" => [
                t('pedidos: aprovar, recusar'),
                t('convites: convidar, aprovar, recusar, reenviar, cancelar'),
                t('acessos: remover'),
            ],
            "partes" => [
                ["titulo" => t('Tudo'), "itens" => [
                    t('h1 "Aprovações e convites". "Pedidos de acesso": Quem (o login e o e-mail), Pede, Recado, Vence em, "Aprovar" e "Recusar" (ou "Nenhum pedido esperando."). "Convites esperando a sua aprovação" (só quando há): E-mail, Quem convidou, Para, Vence em, "Aprovar e enviar", "Recusar". "Convidar": a explicação (o link vale N dias; quem não tem conta cria pelo link; o convite de um membro espera o dono), sem e-mail no servidor o aviso âmbar (o link aparece aqui, numa caixa para copiar), E-mail, Para ver (as coleções que você vê e "Tudo meu") e "Convidar". "Convites que você fez": E-mail, Para, Situação (enviado, esperando o dono), Vence em, "Reenviar" e "Cancelar". "Quem vê as suas coleções": Quem, Vê, Desde e "Tirar o acesso" (confirma) ou "Só você.".'),
                ]],
            ],
        ],
        [
            "id" => "cadastro",
            "pagina" => "cadastro.php",
            "titulo" => t('Criar conta'),
            "endereco" => t('cadastro.php; ?convite=<token> (o convite); ?senha=<token> (a senha nova)'),
            "le" => [
                t('recurso=cadastro (sem login)'),
            ],
            "grava" => [
                t('cadastro: criar_conta, esqueci_senha, nova_senha'),
            ],
            "partes" => [
                ["titulo" => t('Tudo'), "itens" => [
                    t('A página pública: sem menu (só o idioma), sem login. Normal: h1 "Criar conta", o formulário Login (sem dois-pontos), Senha, E-mail (largo), Idioma, "Para começar" (Uma coleção minha, com o conjunto inicial; Uma coleção minha, vazia; Pedir acesso à coleção de alguém, que abre Acesso a, Código, o quadro "Como pedir acesso (e por que pelo código)" já aberto e Recado, como em Minha conta: pelo código que o dono passou, sem nenhuma lista de contas), a nota da demonstração ("A conta nova é de demonstração por N dias...") e "Criar conta"; "Já tem conta? Entrar"; e "Esqueci a senha" (o e-mail ou o login e "Mandar o link"; sem e-mail no servidor, a nota). Cadastro fechado: o aviso âmbar. Com convite: "Você foi convidado para ver <alvo>.", "Já tem conta? Entre e aceite o convite" (conta.php?convite=...) e, se o e-mail não tem conta, o formulário com o e-mail já preenchido e a caixa "Criar também uma coleção minha". Com o link da senha: "Senha nova" e "Trocar a senha". Depois de criar ou trocar: "Pronto", a mensagem e o botão "Entrar".'),
                ]],
            ],
        ],
        [
            "id" => "destinos",
            "pagina" => "destinos.php",
            "titulo" => t('Destinos'),
            "endereco" => t('destinos.php#destino-<id>'),
            "le" => [
                t('recurso=destinos'),
            ],
            "grava" => [
                t('destinos: salvar, testar, excluir'),
            ],
            "partes" => [
                ["titulo" => t('Tudo'), "itens" => [
                    t('h1 "Destinos das mensagens", os links "← Configuração" e "Agendas" e a explicação. Cada destino num cartão (id destino-<id>): Nome, Ligado, Idioma (O da coleção e os seis), o que é do tipo (o webhook: Método, Endereço, A mensagem vai (na URL, como formulário, como JSON), a tabela dos Campos com duas linhas vazias, o Corpo JSON, a tabela dos Cabeçalhos, com o valor escondido ("(guardado; vazio mantém)"); o e-mail: Para, e o aviso âmbar sem e-mail no servidor; o Web Push: Para os aparelhos de (quem vê a coleção), quantos aparelhos, o link para Minha conta, e o aviso âmbar sem as chaves), a Mensagem padrão com "Inserir âncora", "O que recebe" (a tabela Recebe, Aviso, Quando, Texto: a caixa de cada tipo e o "Personalizar" que abre o texto próprio com "Inserir âncora"), "Salvar", "Mandar um teste", "Mandar a da manhã agora", "Mandar a da noite agora", "Excluir", e "Como sai hoje" (De manhã, À noite, o texto em fonte fixa ou "(nada a enviar)"). No fim, "Novo destino": o tipo e o nome, "Criar".'),
                ]],
            ],
        ],
        [
            "id" => "agendas",
            "pagina" => "agendas.php",
            "titulo" => t('Agendas'),
            "endereco" => t('agendas.php#agenda-<id>; ?recado=<texto> (a volta da Microsoft)'),
            "le" => [
                t('recurso=agendas'),
            ],
            "grava" => [
                t('agendas: salvar, sincronizar, teste_criar, teste_remover, novo_link, outlook_conectar, excluir'),
            ],
            "partes" => [
                ["titulo" => t('Tudo'), "itens" => [
                    t('h1 "Agendas", os links "← Configuração" e "Destinos" e a explicação ("...O cron sincroniza de manhã e à noite; o link ICS o app de agenda lê quando quiser."). Cada agenda num cartão: Nome, Ligada, Eventos até quantos dias à frente, Idioma, o que é do tipo (Google: ID da agenda e a chave, o JSON colado; o caminho no servidor só o master; CalDAV: Endereço do calendário, Usuário, Senha, com a dica do Nextcloud, do iCloud e do Fastmail; Outlook: a conta conectada ou não, o calendário, "Conectar ao Outlook" (vai para a Microsoft e volta com o recado), e sem o aplicativo no servidor o aviso âmbar; link ICS: o link para assinar, com as dicas do Google, do Outlook e do iPhone, e "Trocar o link", que confirma), o Modelo do evento com "Inserir âncora" ("A primeira linha é o título do evento; o resto, a descrição."), "O que recebe", "Salvar", "Sincronizar agora", "Criar evento de teste" / "Remover evento de teste", "Excluir", e "O que tem de estar nela agora" (a data, a hora, o título e a descrição dos primeiros eventos, ou "Nada previsto no período."). No fim, "Nova agenda": o tipo e o nome, "Criar".'),
                ]],
            ],
        ],
        [
            "id" => "ajuda",
            "pagina" => "ajuda.php",
            "titulo" => "Ajuda",
            "endereco" => t('ajuda.php#<âncora>'),
            "le" => [
                t('recurso=manual (o HTML e o sumário)'),
            ],
            "grava" => [],
            "partes" => [
                ["titulo" => "Tudo", "itens" => [
                    t('h1 "Ajuda"; "Nesta página": o sumário com os títulos de nível 2 e 3 (recuados), cada um link para a sua âncora; depois o manual em HTML, com as tabelas que rolam de lado no celular, as imagens com borda e cantos, o código em fonte fixa. Abrindo com uma âncora no endereço, a página vai direto para ela.'),
                ]],
            ],
        ],
    ],
    "capturas" => [
        ["arquivo" => "docs/telas/hoje.png", "mostra" => t('a página Hoje no computador, com o painel ao lado')],
        ["arquivo" => "docs/telas/colecao.png", "mostra" => t('a tabela dos relógios com as 12 colunas e os filtros')],
        ["arquivo" => "docs/telas/historico.png", "mostra" => t('o histórico de um relógio')],
        ["arquivo" => "docs/telas/cadastros.png", "mostra" => t('a aba Campos dos Cadastros')],
        ["arquivo" => "docs/telas/celular.png", "mostra" => t('no celular: a Hoje, os cartões dos relógios e o painel em tela cheia')],
    ],
    ];
}

// O código do sistema como está agora, para a reescrita ler o original (recurso=codigo): cada arquivo com a camada e o que
// ele é. O conteúdo é lido do disco na hora do pedido, então nunca fica atrás da versão em uso. Só saem os arquivos desta
// lista, as migrações (as de $MIGRACOES) e as capturas das telas (as de reconstrucao_telas()); o resto da pasta nunca sai, e
// o que é de propósito fica de fora está em reconstrucao_nao_mostrados(), com o que ver no lugar. Arquivo novo no sistema?
// Ponha aqui também.
function reconstrucao_codigo()
{
    $lista = [
    "pagina.php" => ["tela", t('a base de todas as páginas: topo() (o head, o menu e o título) e rodape() (os scripts, com a data do arquivo na URL)')],
    "estilo.css" => ["tela", t('o estilo de todas as páginas: as cores, as letras, os controles, os componentes e os tamanhos de tela')],
    "api.js" => ["tela", t('a base do JavaScript das páginas: os textos no idioma (t(), com o dicionário de recurso=textos), ler e gravar pela API, os formatos do idioma (número, dinheiro, data, hora), o login, os formulários que gravam, as tabelas que viram fichas no celular')],
    "textos/funcoes.php" => ["tela", t('os idiomas: a lista (LINGUAS: o nome, o código curto do menu, o locale e os formatos da data e dos números), o idioma do pedido, t() e os formatos no PHP')],
    "textos/<idioma>.php" => ["tela", t('o arquivo de um idioma (en, zh-CN, hi, es, fr): o texto em português => a tradução, por parte (telas, api, ajuda, manual, reconstrucao), gerado e conferido por testes/textos.php; o que falta sai em português, ou para com erro no modo estrito (TEXTOS_ESTRITO)')],
    "index.php" => ["tela", t('o esqueleto da página Hoje (o meio, a tabela dos relógios com os títulos e os filtros, e o painel)')],
    "hoje.js" => ["tela", t('a página Hoje: o relógio do dia, os avisos de hoje, os próximos dias, o modo de rodízio e as linhas da tabela dos relógios')],
    "tabela.js" => ["tela", t('a tabela dos relógios: ordenar, filtrar e virar cartões quando as 12 colunas não cabem')],
    "painel.js" => ["tela", t('o painel do relógio (na página Hoje e na Ficha): os quadros, o Marcar, o Editar cadastro, o gráfico da carga, os dados e o histórico')],
    "foto.js" => ["tela", t('a foto reduzida no navegador antes de enviar (1200 px, JPEG 85%), com a prévia')],
    "ficha.php" => ["tela", t('a página Ficha do relógio: o painel do relógio numa página própria')],
    "plano.php" => ["tela", t('o esqueleto da página Plano')],
    "plano.js" => ["tela", t('a página Plano: o resumo do período e o dia a dia, mês a mês')],
    "configuracao.php" => ["tela", t('o esqueleto da página Configuração')],
    "configuracao.js" => ["tela", t('a página Configuração: o banco desatualizado, os quadros das opções, as mensagens, o que vai para onde, os eventos e como sai hoje')],
    "criterios.php" => ["tela", t('o esqueleto da página Critérios')],
    "criterios.js" => ["tela", t('a página Critérios: as explicações, os lugares, os parâmetros, os subparâmetros, as faixas e a nota de cada relógio')],
    "grupos.php" => ["tela", t('o esqueleto da página Grupos')],
    "grupos.js" => ["tela", t('a página Grupos: a árvore e o grupo de cada relógio')],
    "execucoes.php" => ["tela", t('o esqueleto da página Execuções do cron')],
    "execucoes.js" => ["tela", t('a página Execuções do cron: os filtros, o resumo, a tabela e a paginação')],
    "desejos.php" => ["tela", t('o esqueleto da página Desejos')],
    "desejos.js" => ["tela", t('a página Desejos: a lista de desejos (com o Comprei) e os relógios fora da coleção')],
    "estatisticas.php" => ["tela", t('o esqueleto da página Estatísticas')],
    "hora.php" => ["tela", t('o esqueleto da página Hora certa')],
    "acessorios.php" => ["tela", t('o esqueleto da página Acessórios')],
    "galeria.php" => ["tela", t('o esqueleto da página Galeria')],
    "galeria.js" => ["tela", t('a página Galeria: a coleção em fotos, por grupo, para ver e imprimir')],
    "passaporte.php" => ["tela", t('o esqueleto da página Passaporte do relógio')],
    "passaporte.js" => ["tela", t('a página Passaporte: a ficha de um relógio para imprimir ou guardar em PDF')],
    "acessorios.js" => ["tela", t('a página Acessórios: o filtro por relógio, a lista com Editar e Excluir e o cadastro')],
    "hora.js" => ["tela", t('a página Hora certa: o mostrador e a hora digital andando, acertados pela API, e os quadros do fuso, do calendário e da lua')],
    "estatisticas.js" => ["tela", t('a página Estatísticas: o período, o resumo, cada relógio, os gráficos em SVG e a coleção')],
    "historico.php" => ["tela", t('o esqueleto da página Histórico')],
    "historico.js" => ["tela", t('a página Histórico de um relógio: os filtros, o resumo do tempo e a linha do tempo')],
    "documentos.php" => ["tela", t('o esqueleto da página Documentos')],
    "documentos.js" => ["tela", t('a página Documentos: o envio, as abas, a galeria com o visor, os vídeos, os PDFs, as NF-e e a edição')],
    "cadastros.php" => ["tela", t('o esqueleto da página Cadastros')],
    "cadastros.js" => ["tela", t('a página Cadastros: as abas Campos, Tipos de lançamento, Fórmulas, Avisos, Modos de rodízio e Categorias de documentos')],
    "usuarios.php" => ["tela", t('o esqueleto da página Usuários')],
    "usuarios.js" => ["tela", t('a página Usuários: o painel do master (cada conta com o que tem e as ações: ver como, oficializar, prorrogar, voltar a demo, bloquear, alterar, trocar a senha, transferir, dar acesso, excluir) e criar conta')],
    "conta.php" => ["tela", t('o esqueleto da página Minha conta')],
    "conta.js" => ["tela", t('a página Minha conta: as coleções, as árvores, pedir acesso, os dados, os tokens, as notificações deste aparelho, o servidor de e-mail (master) e excluir a conta')],
    "aprovacoes.php" => ["tela", t('o esqueleto da página Aprovações')],
    "aprovacoes.js" => ["tela", t('a página Aprovações: os pedidos, os convites a aprovar, convidar, os convites feitos e quem vê as suas coleções')],
    "cadastro.php" => ["tela", t('o esqueleto da página pública Criar conta (sem menu, sem login)')],
    "cadastro.js" => ["tela", t('a página Criar conta: o cadastro aberto, o convite, o esqueci a senha e a senha nova')],
    "destinos.php" => ["tela", t('o esqueleto da página Destinos')],
    "destinos.js" => ["tela", t('a página Destinos: cada destino das mensagens (webhook, e-mail, Web Push), o que recebe, o teste e como sai hoje')],
    "agendas.php" => ["tela", t('o esqueleto da página Agendas')],
    "agendas.js" => ["tela", t('a página Agendas: cada agenda (Google, CalDAV, Outlook, link ICS), o que recebe, a sincronização, o evento de teste e o que tem de estar nela')],
    "push.js" => ["tela", t('o service worker do Web Push: mostra a notificação que chega e, no clique, abre o sistema')],
    "ajuda.php" => ["tela", t('o esqueleto da página Ajuda')],
    "ajuda.js" => ["tela", t('a página Ajuda: o sumário e o manual em HTML (recurso=manual)')],
    "api.php" => ["api", t('a API, a porta única: a autenticação, o formato, a ordem dos recursos (cada um no seu arquivo da pasta api/, ou ali mesmo, os pequenos), os filtros, o JSON e o XML, e no cabeçalho a documentação inteira')],
    "api/funcoes.php" => ["api", t('as funções que só a API usa (o XML, os textos legíveis do tempo, do plano, dos eventos e da agenda, os filtros das listas e a resposta) e o tratamento dos erros')],
    "api/tudo.php" => ["api", t('api.php sem recurso: tudo o que está guardado')],
    "api/hoje.php" => ["api", t('recurso=hoje: tudo o que a página Hoje mostra')],
    "api/ficha.php" => ["api", t('recurso=ficha: tudo o que o painel de um relógio mostra (ou o cadastro de um novo)')],
    "api/configuracao.php" => ["api", t('recurso=config: a Configuração inteira')],
    "api/destinos.php" => ["api", t('recurso=destinos: os destinos das mensagens da coleção, o que cada um recebe e a prévia de hoje')],
    "api/agendas.php" => ["api", t('recurso=agendas: as agendas da coleção, o que cada uma recebe e o que tem de estar nela agora')],
    "api/desejos.php" => ["api", t('recurso=desejos: a lista de desejos e os relógios fora da coleção')],
    "api/passaporte.php" => ["api", t('recurso=passaporte: a ficha de um relógio para imprimir, tudo já em texto')],
    "api/galeria.php" => ["api", t('recurso=galeria: a coleção em fotos, por grupo')],
    "api/hora_certa.php" => ["api", t('recurso=hora_certa: a hora do servidor, o fuso, o UTC, a letra militar, o calendário e a lua (sem banco)')],
    "api/estatisticas.php" => ["api", t('recurso=estatisticas: o uso da coleção num período, por relógio, dia da semana e mês, e a distribuição da coleção')],
    "api/historico.php" => ["api", t('recurso=historico: a linha do tempo de um relógio, com os filtros e o resumo')],
    "api/plano.php" => ["api", t('recurso=plano: o plano dia a dia, o resumo por relógio e as páginas')],
    "api/cron.php" => ["api", t('recurso=cron: as execuções do cron, com os filtros, o resumo e as páginas')],
    "api/arvore.php" => ["api", t('recurso=arvore: a árvore dos grupos e o grupo de cada relógio')],
    "api/cadastros.php" => ["api", t('recurso=cadastros: os campos, os tipos de lançamento, as fórmulas, os avisos, os modos e as categorias')],
    "api/calcular.php" => ["api", t('recurso=calcular: o resultado de uma fórmula para um relógio (ou para todos)')],
    "api/documentos.php" => ["api", t('recurso=documentos: os documentos de um relógio (ou de todos), as categorias e o limite')],
    "api/documento.php" => ["api", t('recurso=documento: um arquivo de um documento (o original, a miniatura, a página de um PDF)')],
    "api/backup.php" => ["api", t('recurso=backup: as cópias de segurança da pasta, e uma cópia para baixar (arquivo=)')],
    "api/migracoes.php" => ["api", t('recurso=migracoes: as migrações pendentes do banco (a leitura) e aplicar (a escrita)')],
    "api/codigo.php" => ["api", t('recurso=codigo: o código do sistema como está agora, arquivo por arquivo')],
    "api/ajuda.php" => ["api", t('recurso=ajuda: a documentação da API (cada consulta, cada escrita e o dicionário dos campos, a fonte única dele)')],
    "api/escrita.php" => ["api", t('as escritas (POST): cada recurso e ação vai para a função dele no operacoes.php, que valida, grava e responde')],
    "lib.php" => ["api", t('o núcleo: o token, o login, a árvore, os campos, os lançamentos, o motor das fórmulas, os avisos, os critérios, o rodízio, a escala e as listas do código')],
    "operacoes.php" => ["api", t('as escritas: cada ação valida, grava e devolve ok, mensagem e erros (a API chama estas funções)')],
    "colecoes.php" => ["api", t('as coleções no núcleo: a coleção do pedido, os ids da raiz, o modelo.json (a árvore e a coleção novas), os segredos cifrados, a tabela sistema, a migração v25, o usuário do pedido, as coleções que cada um vê, excluir com os arquivos, a limpeza das contas e o impacto das exclusões')],
    "alertas.php" => ["api", t('os alertas: o pedido HTTP (e a conferência dos endereços de fora), os destinos (webhook, e-mail por SMTP/IMAP/POP, Web Push com VAPID), as mensagens da manhã e da noite, as agendas (Google, CalDAV, Outlook, o link ICS) e a sincronização')],
    "contas.php" => ["api", t('as contas: o cadastro aberto, Minha conta, as coleções e as árvores (copiar, transferir), os acessos, os convites, os pedidos, os tokens pessoais, o Vendo como e o painel do master')],
    "modelo.json" => ["banco", t('o conjunto inicial de uma coleção nova, sem códigos fixos (os grupos por apelido): os grupos, os campos, os tipos, as fórmulas, os avisos, os critérios, as categorias, os modos, a configuração e as mensagens padrão, em português')],
    "banco.php" => ["api", t('o banco: MySQL/MariaDB, PostgreSQL, SQLite ou Firebird, o SQL escrito uma vez só e traduzido para cada um, e o catálogo do modelo')],
    "reconstrucao.php" => ["api", t('a reconstrução (recurso=reconstrucao): as regras de cada parte, a especificação das telas e esta lista do código')],
    "cron.php" => ["linha_de_comando", t('o cron, a cada minuto: as mensagens, o pulso sozinho, a agenda, a manutenção dos documentos, sempre mudo e registrado no banco')],
    "instalar.php" => ["linha_de_comando", t('a instalação pelo servidor (a mesma do POST recurso=instalacao)')],
    "importar.php" => ["linha_de_comando", t('a importação do sistema antigo pelo servidor (a mesma do POST recurso=importacao)')],
    "criar_usuario.php" => ["linha_de_comando", t('criar um usuário ou trocar a senha pelo servidor (o mesmo do POST recurso=usuarios, acao=salvar)')],
    "schema.sql" => ["banco", t('a estrutura do banco (no dialeto do MySQL, traduzida para os outros): as tabelas, as chaves e as regras, sem dados')],
    "config.exemplo.php" => ["servidor", t('o modelo do config.php, com cada constante explicada (o config.php de verdade não sai: veja nao_mostrados)')],
    ".htaccess" => ["servidor", t('o Apache: o que abre pelo navegador e o que fica bloqueado')],
    "nginx-relogios.conf" => ["servidor", t('o nginx: o mesmo bloqueio do .htaccess')],
    "testes/cenario.php" => ["testes", t('o roteiro de aceitação: faz pela API o que uma pessoa faz e tira a fotografia de tudo o que a API devolve')],
    "testes/comparar.php" => ["testes", t('compara duas fotografias do cenário (os quatro bancos, ou a reescrita com a versão atual)')],
    "testes/paridade.sh" => ["testes", t('o teste de paridade com um comando: os quatro bancos ao mesmo tempo, numa pasta temporária, e a comparação')],
    "testes/config.teste.php" => ["testes", t('o config dos testes (o acesso aos bancos pelo ambiente), apontado por RELOGIOS_CONFIG: o config.php de verdade fica intocado')],
    "testes/dicionario.php" => ["testes", t('gera o dicionário dos campos do cabeçalho do api.php a partir do da ajuda (a fonte única), e confere que batem')],
    "testes/campos.php" => ["testes", t('confere que todo campo das respostas da API está explicado no dicionário da ajuda')],
    "testes/isolamento.php" => ["testes", t('confere que uma conta não vê nem muda o que é de outra pelo token pessoal (todos os recursos, com os ids da outra) e que o pedido de acesso pelo código não mostra login nem nome de coleção')],
    "testes/layout.cjs" => ["testes", t('a conferência do layout: todas as telas num navegador, em 15 tamanhos, com dados de estresse, procurando o que quebra')],
    "testes/README.md" => ["testes", t('como rodar o teste de paridade e a conferência do layout')],
    "README.md" => ["documentacao", t('o manual inteiro: para quem usa, a instalação, a API e como é por dentro')],
    "PORTE.md" => ["documentacao", t('o que o sistema antigo fazia e onde está no novo')],
    "LICENSE" => ["documentacao", t('a licença')],
    ".gitignore" => ["repositorio", t('o que nunca entra no repositório (a configuração, as chaves, os bancos)')],
    ".gitattributes" => ["repositorio", t('as quebras de linha (LF em tudo) e os binários')],
    ".github/workflows/testes.yml" => ["repositorio", t('os testes no GitHub a cada push no main: a paridade nos bancos (com a documentação da API) e o layout das telas')],
    "testes/textos.php" => ["testes", t('confere os dicionários dos idiomas com o código (o texto que falta, o que sobra, o parâmetro diferente) e a origem de cada manual traduzido; junta traduções novas (--juntar)')],
    "testes/arquitetura.php" => ["testes", t('a conferência da arquitetura: as páginas sem banco, a rede das telas só pela API, o banco só no núcleo, na API e no cron, os scripts de linha de comando como atalhos da API, a guarda do api/, o bloqueio pela web, a API e o config.php documentados, as migrações e o repositório')],
    "testes/readme.php" => ["testes", t('monta o README de cada idioma (README.<idioma>.md) a partir do manual traduzido e do resto traduzido, e confere que estão em dia (--conferir)')],
    ];
    // os arquivos de cada idioma (o português é o próprio código e o README.md)
    foreach (array_keys(LINGUAS) as $l) {
        if ($l !== "pt-BR") {
            $lista["textos/" . $l . ".php"] = ["tela", t('o dicionário do idioma {1}: o texto em português => a tradução, por parte (telas, api, cadastro, ajuda, reconstrucao)', [1 => $l])];
            $lista["textos/manual/" . $l . ".md"] = ["documentacao", t('o manual da página Ajuda no idioma {1} (as seções do README para quem usa), com a origem na primeira linha', [1 => $l])];
            $lista["textos/readme/" . $l . ".md"] = ["documentacao", t('o resto do README no idioma {1} (o que não está no manual), com a origem na primeira linha', [1 => $l])];
            $lista["README." . $l . ".md"] = ["documentacao", t('o README no idioma {1}, montado por testes/readme.php (não editar à mão)', [1 => $l])];
        }
    }
    return $lista;
}

// O que fica de fora de propósito, e o que ver no lugar
function reconstrucao_nao_mostrados()
{
    return [
    ["arquivo" => "config.php", "por_que" => t('tem as senhas do banco e o API_TOKEN'),
        "o_que_ver" => t('o config.exemplo.php (o modelo, com cada constante explicada) e listas.config_php no recurso=reconstrucao')],
    ["arquivo" => t('a chave JSON do Google (google-conta-servico.json ou o caminho da Configuração)'), "por_que" => t('é uma credencial'),
        "o_que_ver" => t('a seção mensagens do recurso=reconstrucao e o README (Google Agenda): como criar a conta de serviço e a chave')],
    ["arquivo" => t('o banco de dados (o servidor MySQL/PostgreSQL/Firebird, ou o arquivo do SQLite)'), "por_que" => t('são os dados, não o código'),
        "o_que_ver" => t('o modelo em modelo_de_dados (recurso=reconstrucao) e os dados pela própria API (api.php sem recurso: tudo o que está guardado)')],
    ["arquivo" => t('a pasta dos documentos (DOCUMENTOS_PASTA)'), "por_que" => t('são os arquivos de cada relógio, não o código'),
        "o_que_ver" => t('recurso=documentos (a lista) e recurso=documento (cada arquivo)')],
    ["arquivo" => t('as senhas dos usuários'), "por_que" => t('não existem no sistema: só o hash'),
        "o_que_ver" => t('recurso=usuarios (o login e o hash) e senhas na ajuda (como conferir e migrar)')],
    ];
}

// Os arquivos que saem pelo recurso=codigo, os que existem na pasta: [arquivo => [camada, o que é]]
function codigo_arquivos()
{
    global $MIGRACOES;
    $lista = reconstrucao_codigo();
    foreach ($MIGRACOES as $versao => $m) {
        $lista[$m[0]] = ["banco", t('a migração ') . $versao . ": " . $m[1]];
    }
    foreach (reconstrucao_telas()["capturas"] as $c) {
        $lista[$c["arquivo"]] = ["imagem", t('a captura de tela: ') . $c["mostra"]];
    }
    return array_filter($lista, function ($a) { return is_file(__DIR__ . "/" . $a); }, ARRAY_FILTER_USE_KEY);
}

// O tipo de um arquivo: [linguagem, binário?, o tipo para mostrar cru]
function codigo_tipo($arquivo)
{
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    $linguagens = ["php" => "php", "js" => "javascript", "css" => "css", "sql" => "sql", "md" => "markdown", "conf" => "nginx", "png" => "png", "yml" => "yaml", "sh" => "shell", "cjs" => "javascript"];
    $linguagem = $linguagens[$ext] ?? ($arquivo === ".htaccess" ? "apache" : "texto");
    return [$linguagem, $ext === "png", $ext === "png" ? "image/png" : t('text/plain; charset=utf-8')];
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
    return array_column(reconstrucao_telas()["paginas"], "pagina");
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
        "como_usar" => t('O código do sistema como está agora, lido do disco na hora: cada arquivo com a camada, o que ele é, o tamanho e o SHA-256. Um arquivo: arquivo=<nome> (o conteúdo como está, em conteudo; a imagem em conteudo_base64); com cru=1, o arquivo puro, sem JSON (texto como text/plain, para ler no navegador). Numa página, html traz o HTML que o navegador recebe, já montado (html=1 com cru=1: só ele), e usa, os arquivos que ela carrega. Todos de uma vez: conteudo=1 (com os filtros: f[arquivos][camada]=tela traz o HTML, o CSS e o JavaScript das telas). O que fica de fora de propósito está em nao_mostrados, com o que ver no lugar.'),
        "camadas" => [
            ["camada" => "tela", "o_que_e" => t('as páginas: o esqueleto em PHP, o estilo e o JavaScript que lê a API e monta a tela')],
            ["camada" => "api", "o_que_e" => t('o back-end: a API e o que ela usa (o núcleo, as escritas, o banco, a reconstrução)')],
            ["camada" => "linha_de_comando", "o_que_e" => t('o cron e os scripts do servidor')],
            ["camada" => "banco", "o_que_e" => t('o banco inicial e as migrações')],
            ["camada" => "servidor", "o_que_e" => t('a configuração do servidor web e o modelo do config.php')],
            ["camada" => "testes", "o_que_e" => t('o roteiro de aceitação e a comparação')],
            ["camada" => "documentacao", "o_que_e" => t('o README e os outros textos')],
            ["camada" => "repositorio", "o_que_e" => t('os arquivos do git')],
            ["camada" => "imagem", "o_que_e" => t('as capturas das telas')],
        ],
        "arquivos" => array_map(function ($a) use ($conteudo) { return codigo_arquivo($a, $conteudo); }, array_keys(codigo_arquivos())),
        "nao_mostrados" => reconstrucao_nao_mostrados(),
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
    global $FUNCOES, $TIPOS_CAMPO, $FORMATOS_LANCAMENTO, $REPETICOES, $ANCORAS, $MIGRACOES, $DIAS_SEMANA;
    $secao = function ($id, $titulo, $itens) {
        return ["id" => $id, "titulo" => $titulo, "itens" => $itens];
    };
    $tabelas = array_map(function ($t) {
        return array_merge(["tabela" => $t["tabela"], "descricao" => reconstrucao_tabelas()[$t["tabela"]] ?? null], array_diff_key($t, ["tabela" => 1]));
    }, banco_catalogo());
    // uma lista {chave: descrição} do código como lista de objetos: [{<nome da chave>: chave, descricao}]
    $lista = function ($mapa, $nome) {
        return array_map(function ($k, $v) use ($nome) { return [$nome => (string)$k, "descricao" => $v]; }, array_keys($mapa), $mapa);
    };
    $funcoes = [];
    foreach ($FUNCOES as $nome => $f) {
        $funcoes[] = ["nome" => $nome, "argumentos" => $f[1] === null ? $f[0] . t(' ou mais') : ($f[0] === $f[1] ? (string)$f[0] : $f[0] . " a " . $f[1]),
            "descricao" => $f[2], "le_historico" => (bool)$f[3]];
    }
    $migracoes = [];
    foreach ($MIGRACOES as $v => $m) {
        $migracoes[] = ["versao" => $v, "arquivo" => $m[0], "traz" => $m[1], "passo_em_php" => isset($m[3])];
    }
    return [
        "o_que_e" => t('O Relojoeiro (Relógios 2) planeja o rodízio de uma coleção de relógios: escolhe o relógio de cada dia pela nota de cada um (critérios com pesos) e pelas regras do modo de rodízio, acompanha a energia de cada um (bateria, reserva de marcha, carga de luz, vida da pilha) pelas fórmulas do cadastro e pelo histórico de lançamentos, avisa o que fazer (carregar, dar corda, pôr no sol, trocar a pilha, revisão) pelos destinos das mensagens (Telegram e outros webhooks, e-mail, Web Push) e pelas agendas (Google, CalDAV, Outlook, link ICS), mede o gasto real da bateria pelas leituras e guarda os documentos de cada relógio. Esta resposta é o roteiro para reescrever o sistema inteiro do zero e chegar ao mesmo comportamento. Várias pessoas usam o mesmo sistema: cada conta tem as suas coleções (cada uma com os relógios, o histórico, o plano, as mensagens e as agendas), numa árvore de grupos que pode servir a várias coleções do mesmo dono, e pode ver as coleções de outros, por convite ou por pedido aprovado.'),
        "como_usar" => [
            t('Leia as seções na ordem: os princípios e a arquitetura, a ordem de construção, e cada módulo com as suas regras.'),
            t('O modelo de dados (modelo_de_dados) é o do banco em uso agora, lido dele: tabelas, colunas, chaves, índices e regras de validação. Os tipos vêm no dialeto desse banco; o dialeto de referência (MySQL) está no schema.sql.'),
            t('O contrato da API (cada consulta com parâmetros e exemplo, cada escrita com ações e campos, e o significado de cada campo das respostas) está em api.php?recurso=ajuda: a reescrita deve responder igual, para as telas, os scripts e quem integra continuarem funcionando.'),
            t('O conhecimento sobre relógios não está no código: está no modelo.json (o conjunto inicial de uma coleção nova: os grupos, os campos, os tipos de lançamento, as fórmulas, os avisos, os critérios dos grupos, as categorias, os modos, a configuração e as mensagens padrão, sem nenhum código fixo: os grupos se citam por apelido). O schema.sql só tem a estrutura. Leia também por recurso=cadastros e recurso=criterios. A reescrita traz esse cadastro como dado, não como código, no idioma de quem cria a coleção.'),
            t('As telas (telas) são a especificação visual: o visual com os valores exatos, o menu e cada página, parte por parte, com cada tabela, campo, botão e mensagem, palavra por palavra. A reescrita reproduz a mesma interface, não uma parecida: o que está lá existe, e o que não está não existe. As capturas em docs/telas/ mostram o resultado.'),
            t('O código de verdade, como está agora, sai pelo recurso=codigo (a lista em codigo, abaixo): o CSS, o JavaScript, cada página e o HTML que ela entrega ao navegador, a API, o banco e o resto, lido do disco na hora (arquivo=<nome>; cru=1 para o arquivo puro). Cada página em telas diz os arquivos dela (codigo). O que não sai (o config.php, as chaves, os dados) está em codigo.nao_mostrados, com o que ver no lugar.'),
            t('O README.md explica cada tela e cada opção para quem usa (as seções para quem usa saem também pela API, em recurso=manual: o texto da página Ajuda); o testes/cenario.php é o roteiro de aceitação (veja a seção testes).'),
        ],
        "secoes" => [
            $secao("principios", t('Os princípios (não negociáveis)'), [
                t('O sistema não sabe nada de relógios: o código tem só mecanismos (árvore, campos, tipos de lançamento, fórmulas, avisos, critérios, modos). Que um automático tem reserva de marcha, ou que um solar carrega no sol, está escrito em fórmulas cadastradas, que o usuário lê, edita e testa pela tela. Mudar uma regra de relógio é mudar cadastro (com uma migração), não código.'),
                t('Tudo pela API: as páginas só desenham; toda leitura e toda gravação passa pela API, a mesma que um script usa. Nenhuma regra de negócio no navegador além de mostrar e esconder campos.'),
                t('Falha segura: sem token válido, com o fuso inválido ou com o banco desatualizado (migração pendente), o sistema para e diz o motivo (a API responde 500 ou 503, o cron registra e não faz nada), em vez de rodar pela metade.'),
                t('O banco se defende: relacional e normalizado, toda ligação é chave estrangeira (obrigatória: some junto, ON DELETE CASCADE; opcional: a ligação se desfaz, SET NULL), nenhuma lista guardada como texto, e regras de validação (CHECK, NOT NULL, UNIQUE) iguais às da tela. Uma gravação errada é recusada pelo banco venha de onde vier (a API responde 409 com a regra quebrada).'),
                t('Cada coisa é de alguém: a coleção tem um dono, a árvore de grupos tem um dono, e tudo o que se lê ou se grava vale na coleção do pedido. A separação é do núcleo, não de cada consulta: a camada do banco reescreve cada comando para enxergar só a coleção (e a árvore dela), e quem não vê uma coleção recebe 404, como se ela não existisse.'),
                t('Nada some sem aviso: toda exclusão mostra antes o que sai junto (seguindo o banco em cascata, no banco inteiro: uma árvore serve a várias coleções) e só apaga com a confirmação; os arquivos do disco saem junto, na hora.'),
                t('Os mesmos resultados em MySQL/MariaDB, PostgreSQL, SQLite e Firebird: o SQL do sistema é escrito uma vez só; o que muda de banco para banco fica numa camada de tradução. Maiúsculas e acentos não contam nos textos curtos ("Relógio" = "RELOGIO"), em todos; no MySQL/MariaDB, toda tabela em utf8mb4_unicode_ci (a utf8mb4_general_ci, padrão do MariaDB, ordena diferente); no Firebird, a collation UNICODE_CI_AI.'),
                t('Datas e horas no fuso do sistema (FUSO). Nas fórmulas, o tempo é contado em dias no horário local: a meia-noite local de qualquer data é um número inteiro e a fração é a hora do dia; assim uma data e o agora entram na mesma conta sem o fuso aparecer.'),
                t('O cron é mudo e rastreável: roda a cada minuto, não escreve na saída, e registra no banco o que fez.'),
                t('Mensagens que explicam o que fazer ("Dê um nome ao relógio (até 120 caracteres)."), em seis idiomas: português (o original), inglês, chinês simplificado, hindi, espanhol e francês. Nenhum texto fixo: todo texto que alguém lê passa por t("o texto em português", parâmetros), com o português como chave (como no gettext) e os parâmetros entre chaves ({1}, {nome}) trocados depois da tradução; cada idioma tem o seu dicionário (o texto em português => a tradução), e o que falta sai em português. O idioma de cada pessoa é dela (o seletor do menu, guardado no navegador); quem não escolheu vê o padrão da instalação (config.idioma), senão o do navegador, senão português. As datas e os números seguem o idioma. Os nomes cadastrados ficam como foram escritos.'),
            ]),
            $secao("arquitetura", t('A arquitetura'), [
                t('Um back-end único, a API (api.php): GET lê (recurso=...), POST grava (recurso=... e acao=...). Responde JSON (ou XML com formato=xml). O api.php faz a porta (a autenticação, o formato, a ordem dos recursos, os filtros e a resposta); cada recurso grande fica num arquivo da pasta api/ (api/hoje.php, api/ficha.php, api/ajuda.php...), que roda dentro dele, com as mesmas variáveis. Autenticação em todo pedido: o token (cabeçalho X-Api-Token ou parâmetro token, igual ao API_TOKEN) ou o login do site (HTTP Basic com usuário e senha da tabela usuario, senha com password_hash).'),
                t('Um núcleo (lib.php): o motor das fórmulas, as variáveis de cada relógio, os avisos, as notas, o plano, a escala, a previsão, o gasto medido, o pulso sozinho, a linha do tempo e os documentos; o colecoes.php (o escopo da coleção, o modelo.json, os segredos cifrados, a configuração do sistema, a migração v25, o impacto das exclusões); o alertas.php (os destinos das mensagens: webhook, e-mail por SMTP com cópia por IMAP, Web Push com VAPID; as agendas: Google, CalDAV, Outlook, ICS); o contas.php (o cadastro aberto, a conta, as coleções e as árvores, os convites, os pedidos, os tokens e o painel do master).'),
                t('As gravações (operacoes.php e, as das contas, contas.php): uma função por recurso (op_relogio, op_lancamento, op_campos, op_conta, op_colecoes...), que valida tudo, grava e devolve {ok, mensagem, erros[], ...}. A tela e a API chamam as mesmas.'),
                t('O banco (banco.php): a conexão com as extensões do PHP (mysqli, pgsql, sqlite3, pdo_firebird), parâmetros sempre por ? (nunca texto colado no SQL), e a tradução do dialeto do MySQL para os outros: AUTO_INCREMENT, ENUM (vira CHECK ... IN), TINYINT, DATETIME, os INDEX dentro do CREATE TABLE, AFTER, MODIFY COLUMN (o SQLite refaz a tabela), INSERT IGNORE, REPLACE INTO, NOW(), <=>, LIKE sem diferenciar maiúsculas; no Firebird, também LIMIT/OFFSET (OFFSET ... ROWS FETCH NEXT ... ROWS ONLY), DATE(x) (CAST AS DATE), CONCAT (||), LENGTH/LEAST/GREATEST (OCTET_LENGTH/MINVALUE/MAXVALUE), o SELECT sem FROM (FROM RDB$DATABASE), o INSERT de várias linhas (um por linha), a tabela no (palavra reservada: "NO"), nomes de índice e de regra com até 31 letras, o TEXT como VARCHAR(8191) e a chave única de várias colunas com coluna vazia como índice único de uma expressão; e três comandos próprios das migrações: ALTER FOREIGN KEY, DROP COLUMN e ADD CONSTRAINT ... CHECK (trocando a regra sem perder a anterior se os dados não cumprem a nova).'),
                t('As páginas (index.php, plano.php, desejos.php, estatisticas.php, hora.php, acessorios.php, galeria.php, passaporte.php, ficha.php, documentos.php, historico.php, configuracao.php, criterios.php, grupos.php, cadastros.php, execucoes.php, conta.php, aprovacoes.php, usuarios.php, destinos.php, agendas.php, ajuda.php, e a pública cadastro.php) só entregam o esqueleto, sem conferir nem o login; o JavaScript de cada uma (hoje.js, painel.js, configuracao.js, ajuda.js...) chama a API e monta a tela, sem fazer conta: resumos, porcentagens e situações (o resumo do plano, a porcentagem de cada estado no histórico, o peso efetivo dos critérios, a situação do cron, o manual em HTML) vêm prontos da API. Formulários com data-recurso gravam pela API e a página se remonta com a resposta.'),
                t('A API faz tudo, inclusive o que vem antes das telas: a instalação do banco vazio (recurso=instalacao, pelo token), o primeiro usuário (recurso=usuarios pelo token), a importação do sistema anterior (recurso=importacao) e o login das páginas (recurso=entrar: a página sem login recebe 401 da API, o api.js manda para api.php?recurso=entrar&volta=<a página>, o navegador pede o usuário e a senha e a API devolve para a página). Os scripts de linha de comando (instalar.php, criar_usuario.php, importar.php) só chamam as mesmas operações.'),
                t('Fora da API, só o cron (cron.php), a cada minuto pela linha de comando, com as mesmas funções que a API usa; o que ele fez sai em recurso=cron.'),
                t('Sem framework e sem dependências: PHP 8.1+ e JavaScript puro. A reescrita pode usar outra pilha, desde que mantenha o contrato da API, o modelo de dados e as regras.'),
            ]),
            $secao("ordem", t('A ordem de construção'), [
                t('1. O banco: as tabelas do modelo_de_dados, com as chaves e as regras; a instalação (o schema inteiro num banco vazio) e as migrações (uma lista ordenada; cada uma com o SQL, um passo opcional em programa, e uma marca de aplicada: uma tabela, uma coluna ou uma linha da tabela sistema; com alguma pendente, o sistema para e a Configuração mostra o botão de aplicar). A v25 refaz o banco inteiro: copia tudo para um arquivo, cria a estrutura nova e devolve os registros na forma nova, com os mesmos ids.'),
                t('2. O conjunto inicial (modelo.json: a árvore Smartwatch / Tradicional › Mecânico › Automático e Corda manual / Tradicional › Quartzo › Pilha e Solar, os campos, os tipos de lançamento, as fórmulas, os avisos, os critérios, as categorias e os modos) como dados, entrando no idioma de quem cria a coleção.'),
                t('3. As contas e a autenticação (o login HTTP Basic, o token pessoal, o token do servidor), a coleção do pedido e o escopo dela no banco, e o esqueleto da API: os formatos, os erros (400, 401, 403, 404, 409, 413, 500, 503) e os filtros genéricos de qualquer consulta (incluir, excluir, f[], busca, ordem, limite, pagina, mostrar).'),
                t('4. A árvore, os campos (com a validação do valor pelo tipo), os relógios e as fotos.'),
                t('5. Os tipos de lançamento e os lançamentos (instantâneo, com valor, sessão; sessão exclusiva fecha as outras exclusivas no mesmo instante; sem sobreposição de sessões do mesmo tipo), e a linha do tempo.'),
                t('6. O motor das fórmulas (abaixo), as variáveis de um relógio e o botão Testar.'),
                t('7. Os avisos, a visão de cada relógio (agora, carga, situação, próxima manutenção) e a página Hoje.'),
                t('8. O gasto medido pelas leituras e a previsão da energia (autonomia).'),
                t('9. Os critérios e a nota; os modos de rodízio, o plano, a garantia de rodízio, trocar o dia, sortear de novo; a escala inteligente.'),
                t('10. O pulso sozinho e as sessões esquecidas; o cron.'),
                t('11. Os destinos das mensagens (webhook, e-mail, Web Push), as agendas (Google, CalDAV, Outlook, link ICS) e os eventos personalizados, cada um com o que recebe e o seu modelo.'),
                t('12. Os documentos (pasta, cópia no banco em três níveis, galeria, vídeos, PDF, NF-e) e a manutenção deles no cron.'),
                t('13. As telas, as contas (o cadastro aberto, Minha conta, as Aprovações, o painel do master), as execuções do cron, a ajuda (o README dentro do sistema: os mesmos trechos que recurso=manual devolve) e a importação do sistema anterior.'),
                t('14. A aceitação: o roteiro de testes nos quatro bancos dá as mesmas respostas (seção testes).'),
            ]),
            $secao("arvore_campos", t('A árvore, os campos e os relógios'), [
                t('A árvore de grupos tem quantos níveis o usuário quiser; um relógio fica num grupo só (ou na raiz). Tudo o que se cadastra num grupo (campos, tipos de lançamento, fórmulas, avisos, critérios, a origem de um bloco de modo) vale para ele e para tudo abaixo dele.'),
                t('O mesmo nome pode se repetir em ramos diferentes, mas não entre irmãos. Mover um grupo para dentro dele mesmo (ou de um de dentro) é recusado. Excluir um grupo pela tela passa antes o que era dele para o grupo de cima (os de dentro, os relógios, os campos, os tipos, as fórmulas, os avisos e os blocos que sorteavam dele); versões de fórmula e de aviso que o grupo de cima já tem saem.'),
                t('Campos: identificador (minúsculas, números e _, começando por letra, até 40; único entre campos e fórmulas, porque é o nome nas fórmulas; não muda se alguma fórmula usa), nome, tipo (listas.tipos_de_campo), unidade, valor padrão, o grupo, a ordem e a marca só nos desejados (so_desejado). Os campos de um relógio são os de todos e os de cada grupo da cadeia dele (os só nos desejados, só no desejado).'),
                t('O valor de um campo é conferido e normalizado pelo tipo: inteiro; decimal (aceita 1.079,99, 1079,99 e 1079.99, grava com ponto); sim/não (1, sim, s, true; 0, não, nao, n, false); data AAAA-MM-DD; lista (uma das opções); texto (até 1000). Vazio apaga. O valor usado nas contas é o informado, senão o padrão do campo.'),
                t('A situação na coleção de cada relógio (posse): colecao (o padrão), desejado (a lista de desejos), emprestado, vendido ou arquivado. Só o que está na coleção entra no rodízio: nas outras, o relógio fica indisponível (o banco garante, ck_relogio_posse_disponivel; a gravação força disponivel=0 e diz isso na mensagem), e por isso sai do sorteio, da escala e dos avisos sem regra a mais. Saindo da coleção, as sessões abertas dele fecham agora (com a nota "Fechei agora: ..."), os dias dele no plano de amanhã em diante saem e são sorteados de novo (com escala, gerar_escala de amanhã; sem, garantir_plano); hoje fica, mas a sessão do dia (o pulso sozinho) não abre para um relógio fora da coleção. Voltando para a coleção sem dizer o disponível, ele fica disponível. Da coleção (contam na tabela da página Hoje e no valor): na coleção e emprestado (o estado do emprestado parado é "Emprestado"); desejado, vendido e arquivado vão para fora_da_colecao (recurso=hoje) e para a página Desejos. Um campo com so_desejado=1 (o preço-alvo, a prioridade) vale só no desejado: campos_do_relogio o deixa de fora nos outros, e a gravação dos valores também. A lista de desejos (recurso=desejos) vem da prioridade mais alta para a mais baixa (a ordem das opções do campo prioridade_desejo; sem prioridade, no fim), depois pelo nome, com a soma dos preços-alvo; no painel do desejado, o quadro Desejo e os documentos tomam o lugar dos do dia a dia.'),
                t('Acessórios (tabela acessorio): nome (até 120), tipo (pulseira, caixa, estojo, ferramenta, carregador, peca, outro), o relógio de que ele é (opcional: sem ele, solto; excluir o relógio deixa solto, ON DELETE SET NULL), observação (até 1000) e quando foi cadastrado. A lista vem pelo tipo e pelo nome; a ficha traz os do relógio.'),
                t('Relógio: nome (até 120), grupo, situação na coleção, disponível (fora do rodízio e dos avisos quando não), a marca da cópia no banco dos documentos, a foto (JPEG, PNG ou WebP até 4 MB; o navegador reduz para 1200 px antes de enviar). Excluir apaga junto os valores, a foto, os documentos (e os arquivos), os lançamentos, o plano e os critérios próprios.'),
            ]),
            $secao("lancamentos", t('Os tipos de lançamento, os lançamentos e a linha do tempo'), [
                t('Formatos (listas.formatos_de_lancamento): instantâneo (corda, troca de pilha), com valor (a leitura de carga, com unidade) e sessão, com início e fim (no pulso, no winder, no sol). Só a sessão tem "fecha às" (a hora em que a esquecida aberta fecha sozinha) e pode ser exclusiva (abrir uma exclusiva fecha as outras exclusivas abertas no mesmo instante: o relógio fica num lugar só). Só o com valor pode medir o gasto. A condição ("vale quando") é uma fórmula: o tipo só vale para os relógios em que ela dá verdadeiro.'),
                t('Lançar: lancar (instantâneo ou com valor, num instante até agora), iniciar e encerrar (uma sessão; encerrar não antes do início), alternar (a sessão aberta se fecha, a fechada se abre: um botão só num atalho de celular), periodo (uma sessão inteira que já passou, sem sobrepor outra exclusiva), alterar e excluir (corrigir uma marcação). Em todas, relogio_id=hoje é o relógio do dia pelo plano. O formato de um tipo não muda depois de ter lançamentos; um tipo com lançamentos não é excluído.'),
                t('A origem: manual (pela tela ou pela API), rodizio (a sessão no pulso do relógio do dia, aberta pelo sistema ou pelo Pôs no relógio do dia) e importado.'),
                t('A linha do tempo de um relógio divide o histórico em trechos contínuos: em uso pelo rodízio, no pulso fora do rodízio, em cada tipo de sessão (no winder, no sol...) e em repouso (o que sobra); e as marcações (corda, carga, pilha, revisão) como pontos, sem duração. O resumo dá o tempo e a porcentagem em cada estado.'),
                t('O estado de agora de um relógio: em uso (uma sessão no pulso aberta), numa sessão (outra sessão aberta: o estado é o nome do tipo, "No sol", "No winder", "No carregador"; duas juntas, "No sol · No winder") ou em repouso; na tabela da página Hoje, o indisponível em repouso aparece como indisponível. Com o desde quando: o começo da sessão no pulso, o da sessão mais recente, ou o fim da última no pulso ("Em repouso desde 21:40"). A sessão esquecida aberta conta só até o "fecha às" dela. O pulso é o único tipo que o código conhece pelo identificador; os outros entram pelo nome.'),
            ]),
            $secao("formulas", t('O motor das fórmulas'), [
                t('A sintaxe é a de uma planilha: números com vírgula ou ponto, textos entre aspas, ; separando os argumentos, + - * / ^ (a potência associa à direita), as comparações = <> < <= > >= (dão 1 ou 0), o sinal, os parênteses, as variáveis e as funções (listas.funcoes_das_formulas). A precedência, da mais fraca para a mais forte: comparação, soma, produto, potência, sinal.'),
                t('Um valor vazio se propaga pela conta (vazio + 1 = vazio); a divisão por zero dá vazio. MIN e MAX ignoram os vazios. PADRAO(x; outro) troca o vazio. Um erro de escrita diz a posição ("falta fechar as aspas", "\\";\\" sobrando na posição 12").'),
                t('As variáveis de um relógio: os campos (o valor usado), as fórmulas (pelo identificador; uma fórmula pode usar outra, sem ciclo) e as que o sistema dá. Cada fórmula pode ter uma versão por grupo; vale a do grupo mais perto do relógio (a dele, senão a de cima, ... senão a de todos). Uma fórmula que não tem versão para o relógio fica vazia nele.'),
                t('As funções de histórico leem os lançamentos do relógio até o instante da conta (o primeiro argumento é o identificador do tipo, entre aspas). ACUMULA percorre o histórico como um saldo: começa no início, perde por hora, ganha por hora em cada sessão da lista, passa a valer o efeito num instantâneo da lista, sempre entre 0 e o máximo. É ela que faz a reserva de marcha e a carga de luz.'),
                t('Testar: a mesma fórmula calculada em todos os relógios (ou nos escolhidos), sem gravar, com o caminho da conta (rastro). Antes de gravar uma fórmula, o sistema a lê (erro de escrita) e confere as variáveis e os tipos que ela usa. Renomear ou excluir um campo, um tipo ou uma fórmula que outra fórmula usa é recusado, com a lista de quem usa.'),
                t('O tempo nas contas é em dias: HOJE() é a meia-noite local de hoje, AGORA() o instante, e as datas dos campos viram dias pelo mesmo relógio. As horas viram dias dividindo por 24.'),
            ]),
            $secao("avisos", t('Os avisos e a visão de cada relógio'), [
                t('Um aviso tem uma fórmula que dá a data prevista (expressao, em dias), uma condição (vale quando), a antecedência (0 a 3650 dias), o texto (até 300, com {relogio}, {data}, {quando} e {limite}), o tipo de lançamento que resolve (o botão na tela Hoje), se está ativo, uma versão por grupo (como as fórmulas), e como a escala simula a solução (escala: nao, uso ou sempre; o valor ou as horas simuladas) e se entra na agenda só dentro da janela de antecedência ou sempre.'),
                t('Um relógio fora da coleção (desejado, vendido, arquivado) não tem aviso nenhum (o emprestado tem). O aviso vale para um relógio disponível quando a condição dá verdadeiro e a data prevista existe; sai hoje quando a data menos a antecedência já chegou. Estado: atrasado (a data passou) ou em breve. O aviso de um relógio com uma sessão aberta à mão (carregando, no sol) não vai para a agenda enquanto ela estiver aberta: a data andaria a cada conta.'),
                t('Os avisos de carga usam o limite de carga: o do relógio (campo carga_minima), senão o geral da Configuração (carga_limiar); o solar usa sol_limiar. A data do Carregar é pelo gasto do estado de agora (no pulso, o de uso; guardado, o de guardado); no carregador, o Carregar some e vale o Tirar do carregador (carregado: vence quando a bateria enche no carregador).'),
                t('Um aviso pode ser "na hora" (na_hora): além do alerta da manhã, o cron manda pelos destinos que o recebem no minuto em que ele vence, uma vez, e guarda quais já foram (config.na_hora_enviados, relogio_id:identificador); um aviso sai da lista quando deixa de vencer. O botão do aviso na página Hoje vem da API (resolve.acao e resolve.botao): a sessão que resolve, aberta, se encerra ("Tirou do carregador"); fechada, se inicia ("Pôs no sol"); a leitura se informa.'),
                t('O aviso Encostado (encostado, de todos os relógios, resolve com o pulso) é só cadastro: a data prevista é PADRAO(ULTIMA_DATA("pulso"); data_compra) + CONFIG("encostado_dias") (a Configuração, padrão 30), antecedência 0; sem nunca ter ido ao pulso nem data da compra, não avisa. Não entra na próxima manutenção nem no "O que fazer": a tabela da coleção tem a coluna Encostado, o cartão do dia mostra a falta quando já venceu, e o quadro Agora do painel também.'),
                t('A visão de cada relógio (a tabela da página Hoje e o painel): em uso ou em repouso desde quando, a carga estimada agora e de onde vem (a fórmula energia e a origem), a situação (frases curtas: autonomia restante, reserva...), a próxima manutenção (o aviso mais perto, sem o Encostado), o que fazer e a coluna Encostado.'),
            ]),
            $secao("gasto", t('O gasto medido pelas leituras e a autonomia'), [
                t('Cada leitura de carga de um tipo que mede o gasto é comparada com a anterior do mesmo relógio: o intervalo vira uma medição, com a queda, as horas no pulso e as horas fora do pulso no intervalo (o horário de uso conta como pulso sozinho quando o pulso sozinho está ligado; as marcações Pôs e Tirou mandam). Uma subida é recarga: novo ponto de partida, sem medir. Só entram intervalos de 1 hora ou mais; o curto entra com peso pequeno. Um intervalo com outra sessão no meio (o carregador) não mede: a medição só separa pulso e guardado.'),
                t('Cada medição é uma conta com dois desconhecidos: queda = dias de uso × gasto em uso + dias fora × gasto fora. Com as medições da janela (medicao_janela_dias, padrão 90), os dois gastos saem juntos por mínimos quadrados, cada medição pesando as horas que cobriu. Quando elas não separam os dois (só leituras guardado, por exemplo), vale a média das medições de cada um; sem nenhuma na janela, as de antes; sem nenhuma, o do cadastro. O resultado também é dado na janela anterior, para comparar (a bateria envelhecendo).'),
                t('MEDIDO("uso") e MEDIDO("repouso") dão esses gastos às fórmulas; corrigir uma marcação de pulso recalcula as horas das medições que cobrem aquele trecho.'),
                t('A autonomia: cheio pelo cadastro e pela conta (com o gasto medido), quando acaba seguindo o plano (simulando os dias do plano), quanto dura no pulso sem tirar e guardado; e a previsão do smartwatch (a carga dia a dia, até o limite).'),
            ]),
            $secao("criterios", t('Os critérios e a nota'), [
                t('Um conjunto de critérios por lugar: todos os relógios, um grupo ou um relógio. O relógio usa o conjunto mais perto dele, inteiro (o dele, senão o do grupo, senão o de cima, ..., senão o de todos).'),
                t('O conjunto tem parâmetros com peso (somam 100%); cada parâmetro tem subparâmetros com peso (somam 100% dentro dele); cada subparâmetro lê uma variável (um campo ou uma fórmula) e transforma o valor em nota (0 a 100) pelas faixas: de um número até outro (a primeira começa em 0, sem buracos nem sobreposição, só a última sem limite) ou por categoria (campo de lista ou texto).'),
                t('A nota do parâmetro é a média das notas dos subparâmetros, pelo peso; a nota do relógio, a média dos parâmetros, pelo peso. Um subparâmetro sem valor (campo vazio, valor fora de toda faixa) sai da conta e os outros dividem o peso dele. A conta inteira de cada nota é mostrada (cada faixa, cada peso, cada ponto).'),
                t('Restaurar os critérios iniciais apaga os de todos os lugares e volta aos do cadastro inicial.'),
            ]),
            $secao("rodizio", t('Os modos de rodízio, o plano e a escala inteligente'), [
                t('Hoje sem relógio (rodizio sem_relogio): o plano de hoje sai e hoje vira um dia livre; a sessão do rodízio de hoje que já começou fecha agora (o tempo dela conta como uso), a que ainda não começou sai; a sessão do dia (o pulso sozinho) não abre num dia sem plano, e o garantir_plano não põe de volta como plano de hoje o relógio que esteve no pulso pelo rodízio num dia livre. Os outros dias não mudam. Desfaz: trocar_dia (ou usando) de hoje, ou resortear_hoje.'),
                t('Um modo é feito de blocos de dias da semana (cada dia em um bloco só). Cada bloco diz de onde escolher (um grupo, a coleção toda ou um relógio fixo) e se é um relógio por dia ou um para o bloco inteiro. A forma de escolha do modo: inteligente (a maior nota), ponderado (sorteio com a nota como chance), aleatório ou fifo (quem espera há mais tempo). Com o ciclo ligado, um relógio só volta depois que todos os disponíveis do bloco passaram. No máximo um modo em uso.'),
                t('A garantia de rodízio (max_sem_uso, padrão 21) vale antes da nota: candidato além desse limite de dias sem uso ganha direto, o mais parado primeiro. Os dias sem uso de um relógio nunca usado contam desde a compra (sem data de compra: 9999).'),
                t('O plano guarda o relógio de cada dia, o bloco, a origem (sorteio ou manual), a ação do dia e o motivo, numa frase ("Garantia de rodízio: ...", "Sorteio pela nota, entre 5 candidatos: nota 70,2, 16,4% de chance", "Escolhido à mão"). O cron monta o dia que falta (à 0h da segunda, a semana nova); trocar o relógio de um dia o marca como manual; sortear de novo refaz o período; usando hoje põe outro relógio no período atual e refaz o plano.'),
                t('Um período escolhido à mão (rodizio, acao=periodo): de, até (de hoje até 2 anos), os relógios na ordem e quantos dias seguidos cada (dias_cada): cada dia do período fica manual, com o relógio da vez (depois do último, volta ao primeiro; hoje, ele passa a ser o do pulso a partir de agora); na escala, ela é refeita a partir do começo do período. De amanhã em diante: periodo_sortear apaga o que o período tem (à mão, sorteado ou livre) e sorteia de novo; periodo_livre apaga e deixa os dias livres (a tabela plano_livre: sem relógio e sem sorteio; o sorteio e a escala pulam esses dias, e a escala não estica um bloco sobre eles). Escolher um relógio para um dia livre (trocar_dia, periodo, usando) o tira de plano_livre; trocar_dia 0 também (volta ao sorteio). Os dias manuais e os livres de amanhã em diante ficam quando se sorteia de novo ou se monta a próxima semana (hoje, o sortear de novo sorteia); trocar de modo refaz os sorteados. A API dá os dias livres em plano.livres e hoje.livres (as telas os põem entre os dias do plano, como "livre: escolha à mão").'),
                t('O Rodízio manual (a última opção da lista Modo de rodízio da página Hoje; não é um modo: fora do período vale o modo ativo): de e até (de hoje até 2 anos); recurso=rodizio_manual dá os dias de 7 em 7 (pagina), cada um com o que o plano tem e a escolha de partida do seletor: o relógio escolhido à mão, livre (dia livre) ou auto (Decida por mim: o dia sorteado ou sem relógio). rodizio acao=manual grava o período inteiro: dias[data] = o id, auto ou livre (hoje não); o dia que não vem fica com a escolha de partida. Os dias com relógio ficam manual ("Escolhido à mão no rodízio manual de DD/MM a DD/MM."); os livres vão para plano_livre; hoje com outro relógio passa a ser o do pulso a partir de agora (hoje em auto continua o que está); na escala, ela é refeita a partir do começo. O Decida por mim decide ao gravar e grava manual, com o motivo ("Decida por mim: a maior nota (62,9), entre 4 candidatos. O de ontem (X) ficou de fora." ou "Decida por mim, pela garantia de rodízio: parado há 33 dias, além do limite de 15 dias sem uso, ..."): de hoje até o fim do período, dia a dia, cada dia vira uma sessão simulada no pulso (os do plano antes do período, os escolhidos e os decididos), e no dia auto: os disponíveis, menos o de ontem e o escolhido para amanhã (se sobrar outro); a garantia de rodízio na frente (o mais tempo parado além de max_sem_uso); senão a maior nota no começo do uso do dia; empate: o menor id. A resposta diz quantos dias foram escolhidos, decididos, livres e quantos ficaram como já estavam.'),
                t('Apagar a programação (página Plano e quadro Modo de rodízio): apagar um período (periodo_sortear: os dias dele, de amanhã em diante, saem da tabela plano e o modo monta esses dias de novo; sem o de, de amanhã; sem o ate, até o último dia do plano; periodo_livre, só pela API: sai tudo e os dias ficam livres) e apagar tudo (rodizio apagar_tudo: sai toda a programação de amanhã em diante, à mão, sorteada e livre; com hoje=1, "Excluindo hoje", de hoje em diante; depois o modo ativo monta o plano de novo, gerar_escala na escala e garantir_plano até domingo no sorteio; hoje, se mudar, o novo vai para o pulso a partir de agora, como no resortear_hoje).'),
                t('As listas longas de dias vêm da API uma página por vez (plano_pagina): os Próximos dias (hoje.proximos e recurso=proximos, de hoje até o fim do plano) e o dia a dia da página Plano (plano.dia_a_dia, com o filtro relogio), com os dias livres no meio (origem livre; sem eles no filtro de relógio), cada dia com o dia da semana (dia_semana 1 a 7, dia_semana_curto); o tamanho da página é o Mostrar até da Configuração (config proximos_por_pagina, padrão 7; plano_por_pagina, padrão 31; 1 a 366; por_pagina no pedido vale por cima), e o recurso=config devolve os dois já com o padrão (mostrar_ate).'),
                t('A escala inteligente (um modo com escala_dias de 7 a 730) planeja tudo de uma vez simulando dia a dia: a nota de cada relógio com o estado simulado daquele dia; a escolha (o smartwatch fica os dias que a bateria aguenta, até 7); a sessão no pulso no horário de uso e o efeito dela em todos; os avisos (se o escolhido vai precisar de carga ou corda, anota a ação no dia e simula o lançamento que resolve). Toda manhã o cron refaz a escala a partir do estado real. Notas iguais na maior (o empate exato) se desempatam por sorteio, e o motivo diz "Empatado na nota com N outros: saiu no sorteio (<fonte>)."'),
                t('O sorteio (sorteio(min, max): o modo aleatório, o sorteio pela nota e o desempate da escala) usa números aleatórios de fora, nesta ordem: RANDOM.ORG (www.random.org/integers/?num=64&min=0&max=65535&col=1&base=10&format=plain&rnd=new: ruído atmosférico); senão o ANU QRNG (qrng.anu.edu.au/API/jsonI.php?length=64&type=uint16, o endereço antigo, sem chave: {"success": true, "data": [...]}; quântico); senão o gerador seguro do PHP (random_int). Um lote de 64 números de 16 bits por pedido, na memória do pedido; cada sorteio junta quantos pedaços de 16 bits precisar e descarta o valor que passa do último múltiplo inteiro da faixa (sem viés). Cada serviço tem 4 segundos; a resposta tem de vir completa e dentro da faixa, senão vale como falha. Se os dois falham, a config aleatorio_fora guarda quando, e por 10 minutos o sistema nem tenta (usa o do PHP); a config aleatorio_ultima guarda a fonte do último lote e quando (recurso=config: aleatorio.ultimo_lote, aleatorio.fora_desde; a Configuração mostra no quadro Sorteios). O motivo de cada dia sorteado termina com a fonte entre parênteses ("... todos com a mesma chance (RANDOM.ORG)."). Com RELOGIOS_SEMENTE (os testes), mt_rand com a semente, sem rede (a fonte: "semente de teste"). O que é segredo (o sal das senhas, os nomes dos arquivos dos documentos) fica no gerador do PHP: número que viaja pela internet não serve para segredo.'),
            ]),
            $secao("pulso", t('O pulso sozinho e as sessões esquecidas'), [
                t('O horário de uso (uso_inicio e uso_fim) diz quando o relógio do dia está no pulso. Pôr sozinho (pulso_auto_inicio): no início do horário, o cron abre a sessão no pulso do relógio do dia (origem rodizio). Tirar sozinho (pulso_auto_fim): o "fecha às" do tipo No pulso segue o fim do horário. Desligados, só as marcações Pôs e Tirou contam; o que foi marcado sempre vale. Com o relógio do dia noutra sessão exclusiva aberta (no carregador, no sol, no winder), o pôr sozinho espera; se ele saiu de uma depois do início do horário, a sessão no pulso começa quando ele saiu.'),
                t('Toda sessão esquecida aberta fecha sozinha no "fecha às" do tipo dela (a do sol, em sol_fim).'),
            ]),
            $secao("cron", t('O cron'), [
                t('A cada minuto: confere o token, o fuso e as migrações (pendente: registra e para); apaga execuções antigas (sem atividade, 7 dias; com atividade ou erro, 1 ano); monta o dia do plano que falta; fecha as sessões esquecidas; abre a sessão no pulso do dia; dispara os eventos personalizados no minuto marcado (depois de uma parada de até 7 dias, sai uma vez só, sem repetir); a manutenção dos documentos.'),
                t('Por coleção, na hora da manhã dela (horario_manha), uma vez por dia: refaz a escala a partir do estado real, manda a mensagem do dia (o relógio de hoje e os avisos) por cada destino ligado e sincroniza as agendas. Na hora da noite (horario_noite): a da véspera (o relógio de amanhã e o que preparar) e a sincronização; conta os documentos. O sistema inteiro, a cada minuto: a limpeza das contas (o aviso de vencimento da demonstração, 7 dias e 1 dia antes, por e-mail; a conta vencida sai com tudo; os convites, os pedidos e os links de senha vencidos saem; a conta comum sem nada sai), a manutenção dos documentos e, depois das 20h, uma vez por dia, a cópia de segurança.'),
                t('Os avisos na hora, a cada minuto, em cada coleção: os vencidos agora que ainda não foram vão pelos destinos que os recebem.'),
                t('A cópia de segurança: um arquivo relojoeiro-AAAA-MM-DD-HHMMSS.jsonl.gz na BACKUP_PASTA (fora da pasta publicada: dentro dela, recusada), JSON Lines comprimido, o mesmo nos quatro bancos: a primeira linha é o cabeçalho (relojoeiro_copia 1, criada, banco, versao: a última migração, documentos, tabelas); depois, para cada tabela, uma linha {tabela, colunas, binarias} e uma linha por registro com a lista dos valores (binários em base64). As tabelas vêm na ordem em que voltam (a citada antes da que cita; a que cita a si mesma, a árvore, volta em rodadas). Ficam as BACKUP_MANTER mais novas (padrão 7). O conteúdo dos documentos (documento_parte) só com BACKUP_DOCUMENTOS = true. Restaurar: só da mesma versão; antes, uma cópia do que está agora; depois, numa transação, apaga tudo (na ordem inversa) e grava a cópia; sem o conteúdo dos documentos, documento.no_banco volta a 0.'),
                t('Com BACKUP_ATIVO = false no config.php, o cron não faz a cópia de segurança e a escrita fazer é recusada.'),
                t('Mudo: avisos do PHP e erros entram no registro, nunca na saída. Grava em cron_execucao o que fez o sistema (só quando fez algo, ou deu erro) e em cron_execucao_colecao o de cada coleção; o último erro em sistema.cron_erro (sai na primeira execução sem erro) e a última rodada com atividade em sistema.cron_registro. Erro fatal sem banco vai para um arquivo na pasta temporária.'),
            ]),
            $secao("mensagens", t('As mensagens, as agendas e os eventos personalizados'), [
                t('Os destinos (listas.tipos_de_destino) de cada coleção: webhook (um pedido HTTP GET, POST ou PUT, com a mensagem na URL, como formulário ou como JSON, os campos e os cabeçalhos, estes cifrados; o Telegram é um webhook), e-mail (pelo SMTP do config.php, com STARTTLS ou TLS, e a cópia em Enviados por IMAP) e Web Push (os aparelhos de um usuário que vê a coleção; aes128gcm e VAPID ES256). Cada um tem a mensagem padrão (o modelo, com as âncoras) e o idioma, e escolhe o que recebe: o relógio do dia, a véspera, cada aviso e cada evento personalizado, cada um com o texto próprio opcional. Os itens do mesmo horário vão juntos; o webhook manda em partes de até 3000 caracteres.'),
                t('As agendas (listas.tipos_de_agenda): Google Agenda (uma conta de serviço; token por JWT RS256), CalDAV (Nextcloud, iCloud com senha de app, Fastmail: um PUT de um .ics por evento), Outlook (o Microsoft Graph, com a conta conectada pelo OAuth do aplicativo registrado) e o link ICS (um calendário que o app de agenda assina; sem login, pela chave secreta do link). O sistema calcula o que tem de estar em cada uma (de hoje até janela_dias: os avisos marcados para ela, no horário da manhã do dia previsto; o relógio do dia e a véspera, pelo plano; os eventos personalizados) e deixa a agenda igual: cria o que falta, atualiza o que mudou (a assinatura do título e da descrição) e apaga o que deixou de valer; o que já passou fica, como histórico.'),
                t('Os segredos (senhas, chaves, tokens, cabeçalhos) ficam cifrados no banco (AES-256-GCM, com a SEGREDO_CHAVE do config.php; sem ela, o API_TOKEN) e nunca voltam para a tela: a resposta diz só se estão preenchidos. Fora das coleções do master, os endereços que o usuário escreve (webhook, CalDAV, Web Push) têm de ser da internet: nada da rede do servidor.'),
                t('Evento personalizado: nome, ativo, repetição (listas.repeticoes), data (uma vez; a cada N dias: a partir dela), hora, dias da semana, dia do mês (1 a 31; mês sem o dia, o último), intervalo (1 a 3650), relógio opcional (com ele, as âncoras do relógio funcionam). Só o que a repetição usa fica gravado. O evento novo vai para todos os destinos da coleção; cada disparo fica gravado, para não disparar duas vezes.'),
            ]),
            $secao("contas", t('As contas, as coleções e o acesso'), [
                t('Contas: o login (sem dois-pontos), o e-mail, a senha (o hash), o papel (master: cuida do sistema inteiro, vê todas as coleções e o painel das contas; comum), o idioma, e o vencimento da conta de demonstração. O primeiro usuário é o master. A conta criada pelo cadastro aberto (cadastro.php, CADASTRO_ABERTO) é de demonstração por DEMO_DIAS dias, com aviso por e-mail 7 dias e 1 dia antes; vencida, o cron a apaga com tudo. O master oficializa, prorroga, volta a demonstração, bloqueia, transfere uma coleção, dá acesso, exclui e vê o sistema como outro usuário (Vendo como).'),
                t('Coleções e árvores: cada coleção tem um dono e usa uma árvore de grupos do mesmo dono; uma árvore pode servir a várias coleções, e o cadastro dela (campos, tipos, fórmulas, avisos, categorias, critérios dos grupos) vale em todas. A coleção nova nasce com o conjunto inicial (numa árvore nova), vazia (só a raiz) ou numa árvore que já existe. Transferir uma coleção leva a árvore junto quando só ela a usa; senão, o novo dono ganha uma cópia da árvore e a coleção passa para a cópia (os relógios, os valores, os lançamentos, os documentos e os blocos dos modos acompanham os ids novos).'),
                t('O acesso: o dono vê as suas; um membro vê uma coleção (colecao_usuario); quem tem tudo de um dono vê todas as dele, também as que vierem (acesso_dono). Entra-se por convite (o dono, ou o master, convida por e-mail com um link de token; o convite de um membro espera o dono aprovar; quem não tem conta cria pelo link) ou por pedido (o pedido espera o dono, ou o master, aprovar; aprovado ou recusado, a pessoa recebe um e-mail). Convites e pedidos vencem em CONVITE_DIAS dias. A conta comum que fica sem nada (sem coleção, sem acesso, sem pedido nem convite) sai.'),
                t('O escopo: a coleção do pedido (colecao=, o cookie do seletor do menu, ou a primeira) decide tudo o que se lê e se grava: a camada do banco reescreve cada SELECT, UPDATE e DELETE para enxergar só as linhas da coleção e da árvore dela (a raiz da árvore não aparece, e as colunas que a citam leem vazio, como no tempo de uma pessoa só). A configuração é por coleção; os valores do sistema inteiro (o cron, a cópia de segurança, o aleatório, as migrações, o endereço do sistema) ficam na tabela sistema.'),
                t('Os tokens pessoais (Minha conta) valem como o login do dono; o token do servidor (API_TOKEN) vale como o master mais antigo. O Esqueci a senha manda um link de token (2 horas) por e-mail.'),
            ]),
            $secao("documentos", t('Os documentos de cada relógio'), [
                t('Qualquer arquivo, cada um numa categoria (as que aceitam só algumas famílias recusam as outras), com título, data e descrição. O tipo vem do conteúdo do arquivo; quando o conteúdo diz pouco (texto, binário genérico, zip), da extensão. A família decide como abre: imagem (galeria com visor em tela cheia e apresentação, com a miniatura feita no navegador), vídeo (player, em sequência na ordem escolhida), PDF (visualizador), XML (o resumo da NF-e: emitente, número, série, data, valor, chave e produtos; lido sem baixar nada de fora), o resto para baixar. Inline só o que o navegador mostra sem rodar nada (HTML, SVG e XML sempre como download); atende pedido de pedaço (Range).'),
                t('Os arquivos ficam numa pasta fora da publicada (DOCUMENTOS_PASTA), em r<id do relógio>/ com nome aleatório. Limite por arquivo: DOCUMENTOS_LIMITE (padrão 100 MB) e os do PHP e do servidor web.'),
                t('A cópia no banco (documento_parte, pedaços de 4 MB, com o SHA-256): três níveis, e o de cima vale sobre os de baixo. O sistema (DOCUMENTOS_COPIA_BANCO: true todos; false nenhum; ausente, decidem os de baixo), o relógio (copia_banco: todos os dele) e o arquivo (copia_banco: só ele). A tela só mostra a escolha de quem decide. A cópia entra na hora no envio e em editar; pelo relógio, o cron copia aos poucos; quando ninguém mais pede, sai, mas só se o arquivo da pasta confere com ela (o documento nunca fica sem as duas).'),
                t('A manutenção (o cron, a cada minuto, até 256 MB por rodada): o arquivo que sumiu da pasta volta da cópia (conferindo o SHA-256); a cópia pedida que falta é feita; a que ninguém pede sai; a pasta de um relógio que não existe mais sai inteira; o arquivo que não é de nenhum documento sai (só depois de 10 minutos: um envio pode estar no meio). Os perdidos (sem arquivo e sem cópia) são contados à noite.'),
            ]),
            $secao("estatisticas", t('As estatísticas'), [
                t('recurso=estatisticas conta o tempo no pulso de cada relógio em cada dia do período (as sessões do tipo pulso partidas na meia-noite, a aberta até agora; as marcações antigas no pulso contam o dia, sem horas). Dela saem: os dias de uso e as horas de cada relógio, a parte de cada um, a barra (comparada com o mais usado), a tendência (a segunda metade do período contra a primeira: até 20% ou menos de 1 h de diferença é estável; menos de 1 h no total ou período de menos de 14 dias, poucos dados), o uso por dia da semana e por mês (com a média por dia e a barra comparada com o maior) e o resumo. O custo por uso e os dias de uso desde sempre são as fórmulas custo_por_uso e dias_de_uso. A coleção (na coleção e emprestados) é dividida por grupo, marca, ano da compra, situação e cada campo de lista (menos os só dos desejados). Período padrão: os últimos 90 dias; até hoje; no máximo 3661 dias; de=tudo: desde o primeiro uso.'),
            ]),
            $secao("hora_certa", t('A hora certa'), [
                t('recurso=hora_certa não usa o banco: a hora do servidor (microtime) no fuso do sistema e em UTC; a letra militar do fuso pelo deslocamento em horas cheias (A a M sem J a leste, N a Y a oeste, Z no UTC; fora da hora cheia, nenhuma); o grupo data-hora DDHHMM + letra + mês em inglês + ano com dois dígitos; o calendário pelo formato de datas (z, L, W, o); o dia juliano = segundos / 86400 + 2440587,5; a lua pela idade no ciclo sinódico médio de 29,530588853 dias a partir da lua nova de 06/01/2000 18:14 UTC (dia juliano 2451550,1), a parte iluminada (1 - cos(2π idade/ciclo)) / 2 e a fase pelos oitavos do ciclo (os limites 1,85; 5,54; 9,23; 12,92; 16,61; 20,30; 23,99; 27,68 dias).'),
            ]),
            $secao("telas", t('As telas'), [
                t('A especificação completa, tela por tela, está em telas (abaixo): o visual com os valores exatos (cores, letras, controles, componentes, larguras), o menu, e cada página com o endereço, o que ela lê e grava na API, e as partes em ordem com cada tabela, campo, botão e mensagem. Aqui, o resumo.'),
                t('Hoje: no alto, o destaque: o relógio do dia ou, com destaque_hoje = pulso, o que está no pulso agora (a API escolhe: destaque.relogio_id) (com o Pôs e o Tirou), "Hoje é dia de" (os avisos com o botão que resolve), os próximos dias com o motivo e o trocar por…, o modo de rodízio (troca ali mesmo), e a tabela da coleção com filtros e ordenação; clicar num relógio abre o painel dele, ao lado da lista ou numa janela flutuante (painel_modo).'),
                t('O painel (e a ficha, a mesma coisa numa página): a cabeça (foto, grupo, nome, marcas), quadros alinhados numa grade (Agora, Previsão, Gasto da bateria, Autonomia, Rodízio, Compra, Documentos, Próximas manutenções), cada um com rótulo e valor em duas colunas; o quadro Marcar (um menu só, "O que você quer marcar?", e embaixo só os campos daquilo); Editar cadastro; o gráfico da carga com as medições; os dados do relógio (o cadastro com a origem de cada valor e o resultado de cada fórmula com a versão); o histórico recente.'),
                t('Plano, Histórico, Documentos (com o menu ⋮ em cada arquivo), Configuração (quadros com uma opção por linha e a barra de salvar presa no pé), Destinos, Agendas, Critérios, Grupos, Cadastros, Execuções do cron, Minha conta, Aprovações, Usuários (o painel do master), Ajuda (as seções do README) e o cadastro aberto.'),
                t('Três tamanhos com o mesmo sistema visual: até 900 px (celular e tablet de pé) o menu fica recolhido, as tabelas viram cartões e o relógio abre em tela cheia (o voltar do aparelho fecha); de 901 a 1199 px o painel ao lado desliza por cima, pela direita. A mesma informação em todos os tamanhos: nenhuma coluna, filtro ou dia é escondido por falta de espaço. A tabela dos relógios tem sempre as 12 colunas; quando a tabela inteira não cabe na largura que tem (medida com os dados, com ou sem o painel aberto), cada relógio vira um cartão com os mesmos 12 campos na mesma ordem, cada um com o nome da coluna em cima (2 colunas no celular, 5 no tablet), os títulos viram botões de ordenar e os 11 filtros ficam todos. Campos com letra de 16 px no celular (o iPhone não aproxima a tela).'),
            ]),
            $secao("seguranca", t('Segurança'), [
                t('Todo pedido da API exige autenticação (o login do site, um token pessoal ou o token do servidor), menos o cadastro aberto, o link ICS e os textos das telas; as páginas são só o esqueleto, sem dado nenhum, e quem chega sem login vai para o login da API. Cada pedido só vê a coleção dele (o escopo é do banco), e a coleção que ele não vê responde 404. A cópia de segurança e as migrações são só do master. O cron e os scripts só pela linha de comando. Os arquivos internos (núcleo, banco, configuração, scripts, testes, dados, os que começam com ponto) ficam bloqueados no servidor web (.htaccess e nginx).'),
                t('SQL sempre com parâmetros; textos escapados no HTML; XML da NF-e lido sem entidades nem rede; arquivos servidos inline só dos tipos seguros; senhas com password_hash; o token comparado em tempo constante.'),
                t('Senhas: só o hash, pelo password_hash do PHP (bcrypt, $2y$<custo>$<sal de 22><resultado de 31>); o login confere com o password_verify (o bcrypt da senha digitada com o sal e o custo do hash). A API entrega o login e o hash de cada usuário (recurso=usuarios: senha_hash, senha_algoritmo, senha_custo) e aceita criar um usuário pelo hash de outro sistema (usuarios/salvar com senha_hash; bcrypt $2y$, $2b$ ou $2a$, ou argon2; o $2b$ guardado como $2y$, o mesmo cálculo). A reescrita tem de conferir pelo mesmo bcrypt, para os usuários migrarem com a mesma senha. Os arquivos dos documentos só pelo recurso=documento.'),
            ]),
            $secao("testes", t('Os testes e a aceitação'), [
                t('testes/cenario.php faz pela API o que uma pessoa faz (cadastra relógios de cada tipo, lança cordas, cargas, sol, winder e pilha, monta a escala, mexe nos critérios, nos eventos e nas fotos, roda o cron) e tira a fotografia de tudo o que a API devolve; testes/comparar.php compara duas fotografias. Com RELOGIOS_SEMENTE (o sorteio fica previsível), o roteiro rodado em MySQL, PostgreSQL, SQLite e Firebird, num banco recém-instalado, tem de dar as mesmas respostas; e a reescrita, as mesmas da versão atual. testes/paridade.sh faz tudo com um comando (os bancos recriados, a instalação, o usuário, os servidores, o roteiro e a comparação), com o config de teste apontado pela variável de ambiente RELOGIOS_CONFIG (o núcleo carrega o arquivo que ela diz; sem ela, o config.php).'),
                t('Além disso: nenhuma escrita do roteiro recusada; o dicionário da ajuda explica todos os campos das respostas; as telas sem erro de JavaScript e sem rolagem lateral no celular, no tablet e no computador.'),
                t('A documentação da API tem uma fonte só: o dicionário dos campos da ajuda (recurso=ajuda, campos); o do cabeçalho do api.php é gerado dele (testes/dicionario.php --gravar; sem o --gravar, confere). testes/campos.php confere que todo campo das respostas está explicado. Os dois rodam no testes/paridade.sh.'),
                t('testes/layout.cjs abre todas as telas (com os quadros recolhidos abertos e cada opção dos menus que mostram partes) num navegador, em 15 tamanhos de 320 a 1920px, e mede o que quebra: a página que rola de lado, o elemento que sai da caixa em que está, a etiqueta partida, o texto cortado, o botão com o texto em duas linhas e o que fica por cima de outro. O esperado: 0 problemas.'),
            ]),
        ],
        // cada página com os arquivos dela (lidos da própria página: nunca ficam atrás)
        "telas" => array_merge(reconstrucao_telas(), ["paginas" => array_map(function ($p) {
            return array_merge($p, ["codigo" => codigo_da_pagina($p["pagina"])]);
        }, reconstrucao_telas()["paginas"])]),
        "codigo" => codigo_lista(false),
        "modelo_de_dados" => [
            "banco" => banco_tipo(),
            "como_ler" => t('Cada tabela com as colunas (nome, tipo no dialeto do banco em uso, se aceita vazio, o padrão), a chave primária, as chaves estrangeiras (a coluna, a tabela.coluna de referência e o que acontece ao apagar a linha de cima: CASCADE some junto, SET NULL desfaz a ligação), as chaves únicas, os índices e as regras de validação (CHECK). As colunas de texto curto não diferenciam maiúsculas nem acentos. Três referências ficam pelo nome, de propósito: as fórmulas e os critérios citam campos e fórmulas pelo identificador, e destino_aviso.tipo_aviso e agenda_aviso.tipo_aviso citam o aviso pelo identificador (vale para todas as versões dele).'),
            "tabelas" => $tabelas,
        ],
        "listas" => [
            "funcoes_das_formulas" => $funcoes,
            "tipos_de_campo" => $lista($TIPOS_CAMPO, "tipo"),
            "formatos_de_lancamento" => $lista($FORMATOS_LANCAMENTO, "formato"),
            "repeticoes" => $lista($REPETICOES, "repeticao"),
            "dias_da_semana" => array_map(function ($n, $nome) { return ["dia" => (int)$n, "nome" => $nome]; }, array_keys($DIAS_SEMANA), $DIAS_SEMANA),
            "ancoras" => $lista($ANCORAS, "ancora"),
            "tipos_de_destino" => array_map(function ($k, $n) { return ["tipo" => $k, "nome" => t($n)]; }, array_keys(DESTINO_TIPOS), DESTINO_TIPOS),
            "tipos_de_agenda" => array_map(function ($k, $n) { return ["tipo" => $k, "nome" => t($n)]; }, array_keys(AGENDA_TIPOS), AGENDA_TIPOS),
            "migracoes" => $migracoes,
            "config_php" => array_map(function ($k, $v) { return ["constante" => $k, "descricao" => $v]; }, array_keys(reconstrucao_config_php()), reconstrucao_config_php()),
        ],
    ];
}
