<?php
// A reconstrução: o roteiro para reescrever a aplicação inteira do zero, em qualquer linguagem, banco ou framework, e
// chegar ao mesmo sistema. Sai pela API (recurso=ajuda&parte=reconstrucao, ou recurso=reconstrucao). O texto descreve o
// que o sistema faz e as regras de cada parte; o modelo de dados e as listas (funções das fórmulas, tipos, âncoras,
// repetições, canais, migrações) são lidos na hora do próprio banco e do código, então nunca ficam atrás da versão em uso.
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
    "usuario" => "quem entra no site: o login e o hash da senha (password_hash)",
];

// As constantes do config.php
const RECONSTRUCAO_CONFIG_PHP = [
    "DB_TIPO" => "mysql (MySQL ou MariaDB, o padrão), pgsql (PostgreSQL) ou sqlite",
    "DB_HOST, DB_PORTA, DB_NOME, DB_USUARIO, DB_SENHA" => "o servidor do banco (MySQL e PostgreSQL)",
    "DB_ARQUIVO" => "o arquivo do banco (SQLite), fora da pasta publicada",
    "API_TOKEN" => "obrigatório, com pelo menos 10 caracteres: sem ele o sistema inteiro para (as páginas, a API e o cron dizem o motivo)",
    "FUSO" => "o fuso horário do sistema e das datas gravadas (vazio: o do PHP)",
    "MSG_ENDPOINT, MSG_DESTINATARIO, MSG_TITULO" => "a API de mensagem (Telegram): um GET com destinatario, titulo e mensagem; resposta 2xx é sucesso; sem o endereço, nada é enviado",
    "DOCUMENTOS_PASTA" => "a pasta dos arquivos dos documentos, fora da pasta publicada, com escrita para o PHP",
    "DOCUMENTOS_LIMITE (e o nome antigo MANUAL_LIMITE)" => "o maior documento aceito, em bytes (sem ele: 100 MB; 0 ou -1: sem limite do sistema)",
    "DOCUMENTOS_COPIA_BANCO" => "a cópia dos documentos no banco para o sistema inteiro: true, todos; false, nenhum; ausente, o relógio e o arquivo decidem",
    "ANTIGO_HOST, ANTIGO_PORTA, ANTIGO_USUARIO, ANTIGO_SENHA" => "só para importar o sistema anterior (importar.php)",
    "GOOGLE_TOKEN_URL, GOOGLE_API_URL" => "só para testes: os endereços do Google que a agenda usa (o do token e o da API do Google Agenda); trocados, a sincronização conversa com um servidor de teste",
];

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
                "Os mesmos resultados em MySQL/MariaDB, PostgreSQL e SQLite: o SQL do sistema é escrito uma vez só; o que muda de banco para banco "
                    . "fica numa camada de tradução. Maiúsculas e acentos não contam nos textos curtos (\"Relógio\" = \"RELOGIO\"), em todos.",
                "Datas e horas no fuso do sistema (FUSO). Nas fórmulas, o tempo é contado em dias no horário local: a meia-noite local de qualquer "
                    . "data é um número inteiro e a fração é a hora do dia; assim uma data e o agora entram na mesma conta sem o fuso aparecer.",
                "O cron é mudo e rastreável: roda a cada minuto, não escreve na saída, e registra no banco o que fez.",
                "Português em tudo o que o usuário vê: mensagens de erro explicam o que fazer (\"Dê um nome ao relógio (até 120 caracteres).\").",
            ]),
            $secao("arquitetura", "A arquitetura", [
                "Um back-end único, a API (api.php): GET lê (recurso=...), POST grava (recurso=... e acao=...). Responde JSON (ou XML com formato=xml). "
                    . "Autenticação em todo pedido: o token (cabeçalho X-Api-Token ou parâmetro token, igual ao API_TOKEN) ou o login do site (HTTP Basic "
                    . "com usuário e senha da tabela usuario, senha com password_hash).",
                "Um núcleo (lib.php): o motor das fórmulas, as variáveis de cada relógio, os avisos, as notas, o plano, a escala, a previsão, o gasto "
                    . "medido, o pulso sozinho, a linha do tempo, as mensagens, a agenda e os documentos.",
                "As gravações (operacoes.php): uma função por recurso (op_relogio, op_lancamento, op_campos...), que valida tudo, grava e devolve "
                    . "{ok, mensagem, erros[], ...}. A tela e a API chamam as mesmas.",
                "O banco (banco.php): a conexão com as extensões nativas (mysqli, pgsql, sqlite3), parâmetros sempre por ? (nunca texto colado no SQL), "
                    . "e a tradução do dialeto do MySQL para os outros: AUTO_INCREMENT, ENUM (vira CHECK ... IN), TINYINT, DATETIME, os INDEX dentro do "
                    . "CREATE TABLE, AFTER, MODIFY COLUMN (o SQLite refaz a tabela), INSERT IGNORE, REPLACE INTO, NOW(), <=>, LIKE sem diferenciar "
                    . "maiúsculas; e três comandos próprios das migrações: ALTER FOREIGN KEY, DROP COLUMN e ADD CONSTRAINT ... CHECK (trocando a regra "
                    . "sem perder a anterior se os dados não cumprem a nova).",
                "As páginas (index.php, plano.php, ficha.php, documentos.php, historico.php, configuracao.php, criterios.php, grupos.php, "
                    . "cadastros.php, execucoes.php, usuarios.php, ajuda.php) só conferem o login e entregam o esqueleto; o JavaScript de cada uma "
                    . "(hoje.js, painel.js, configuracao.js, ajuda.js...) chama a API e monta a tela, sem fazer conta: resumos, porcentagens e situações "
                    . "(o resumo do plano, a porcentagem de cada estado no histórico, o peso efetivo dos critérios, a situação do cron, o manual "
                    . "em HTML) vêm prontos da API. Formulários com data-recurso gravam pela API e a página se remonta com a resposta. Fora da API, "
                    . "de propósito: o cron, os scripts de linha de comando e a conferência do login de cada página.",
                "O cron (cron.php), a cada minuto pela linha de comando; os scripts de linha de comando: instalar.php (o banco vazio), criar_usuario.php, "
                    . "importar.php (o sistema anterior).",
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
                "14. A aceitação: o roteiro de testes nos três bancos dá as mesmas respostas (seção testes).",
            ]),
            $secao("arvore_campos", "A árvore, os campos e os relógios", [
                "A árvore de grupos tem quantos níveis o usuário quiser; um relógio fica num grupo só (ou na raiz). Tudo o que se cadastra num grupo "
                    . "(campos, tipos de lançamento, fórmulas, avisos, critérios, a origem de um bloco de modo) vale para ele e para tudo abaixo dele.",
                "O mesmo nome pode se repetir em ramos diferentes, mas não entre irmãos. Mover um grupo para dentro dele mesmo (ou de um de dentro) é "
                    . "recusado. Excluir um grupo pela tela passa antes o que era dele para o grupo de cima (os de dentro, os relógios, os campos, os "
                    . "tipos, as fórmulas, os avisos e os blocos que sorteavam dele); versões de fórmula e de aviso que o grupo de cima já tem saem.",
                "Campos: identificador (minúsculas, números e _, começando por letra, até 40; único entre campos e fórmulas, porque é o nome nas "
                    . "fórmulas; não muda se alguma fórmula usa), nome, tipo (listas.tipos_de_campo), unidade, valor padrão, o grupo e a ordem. Os campos de "
                    . "um relógio são os de todos e os de cada grupo da cadeia dele.",
                "O valor de um campo é conferido e normalizado pelo tipo: inteiro; decimal (aceita 1.079,99, 1079,99 e 1079.99, grava com ponto); "
                    . "sim/não (1, sim, s, true; 0, não, nao, n, false); data AAAA-MM-DD; lista (uma das opções); texto (até 1000). Vazio apaga. O valor "
                    . "usado nas contas é o informado, senão o padrão do campo.",
                "Relógio: nome (até 120), grupo, disponível (fora do rodízio e dos avisos quando não), a marca da cópia no banco dos documentos, a foto "
                    . "(JPEG, PNG ou WebP até 4 MB; o navegador reduz para 1200 px antes de enviar). Excluir apaga junto os valores, a foto, os "
                    . "documentos (e os arquivos), os lançamentos, o plano e os critérios próprios.",
            ]),
            $secao("lancamentos", "Os tipos de lançamento, os lançamentos e a linha do tempo", [
                "Formatos (listas.formatos_de_lancamento): instantâneo (corda, troca de pilha), com valor (a leitura de carga, com unidade) e sessão, "
                    . "com início e fim (no pulso, no winder, no sol). Só a sessão tem \"fecha às\" (a hora em que a esquecida aberta fecha sozinha) e "
                    . "pode ser exclusiva (abrir uma exclusiva fecha as outras exclusivas abertas no mesmo instante: o relógio fica num lugar só). Só "
                    . "o com valor pode medir o gasto. A condição (\"vale quando\") é uma fórmula: o tipo só vale para os relógios em que ela dá verdadeiro.",
                "Lançar: lancar (instantâneo ou com valor, num instante até agora), iniciar e encerrar (uma sessão; encerrar não antes do início), "
                    . "periodo (uma sessão inteira que já passou, sem sobrepor outra exclusiva), alterar e excluir (corrigir uma marcação). O formato de "
                    . "um tipo não muda depois de ter lançamentos; um tipo com lançamentos não é excluído.",
                "A origem: manual (pela tela ou pela API), rodizio (a sessão no pulso do relógio do dia, aberta pelo sistema ou pelo Pôs no relógio do "
                    . "dia) e importado.",
                "A linha do tempo de um relógio divide o histórico em trechos contínuos: em uso pelo rodízio, no pulso fora do rodízio, em cada tipo "
                    . "de sessão (no winder, no sol...) e em repouso (o que sobra); e as marcações (corda, carga, pilha, revisão) como pontos, sem "
                    . "duração. O resumo dá o tempo e a porcentagem em cada estado.",
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
                "O aviso vale para um relógio disponível quando a condição dá verdadeiro e a data prevista existe; sai hoje quando a data menos a "
                    . "antecedência já chegou. Estado: atrasado (a data passou) ou em breve. O aviso de um relógio com uma sessão aberta à mão "
                    . "(carregando, no sol) não vai para a agenda enquanto ela estiver aberta: a data andaria a cada conta.",
                "Os avisos de carga usam o limite de carga: o do relógio (campo carga_minima), senão o geral da Configuração (carga_limiar); o solar "
                    . "usa sol_limiar. A data do Carregar é pelo gasto do estado de agora (no pulso, o de uso; guardado, o de guardado).",
                "A visão de cada relógio (a tabela da página Hoje e o painel): em uso ou em repouso desde quando, a carga estimada agora e de onde vem "
                    . "(a fórmula energia e a origem), a situação (frases curtas: autonomia restante, reserva...), a próxima manutenção (o aviso mais "
                    . "perto) e o que fazer.",
            ]),
            $secao("gasto", "O gasto medido pelas leituras e a autonomia", [
                "Cada leitura de carga de um tipo que mede o gasto é comparada com a anterior do mesmo relógio: o intervalo vira uma medição, com a "
                    . "queda, as horas no pulso e as horas fora do pulso no intervalo (o horário de uso conta como pulso sozinho quando o pulso sozinho "
                    . "está ligado; as marcações Pôs e Tirou mandam). Uma subida é recarga: novo ponto de partida, sem medir. Só entram intervalos de 1 "
                    . "hora ou mais; o curto entra com peso pequeno.",
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
                "A escala inteligente (um modo com escala_dias de 7 a 730) planeja tudo de uma vez simulando dia a dia: a nota de cada relógio com o "
                    . "estado simulado daquele dia; a escolha (o smartwatch fica os dias que a bateria aguenta, até 7); a sessão no pulso no horário de uso "
                    . "e o efeito dela em todos; os avisos (se o escolhido vai precisar de carga ou corda, anota a ação no dia e simula o lançamento que "
                    . "resolve). Toda manhã o cron refaz a escala a partir do estado real.",
            ]),
            $secao("pulso", "O pulso sozinho e as sessões esquecidas", [
                "O horário de uso (uso_inicio e uso_fim) diz quando o relógio do dia está no pulso. Pôr sozinho (pulso_auto_inicio): no início do horário, "
                    . "o cron abre a sessão no pulso do relógio do dia (origem rodizio). Tirar sozinho (pulso_auto_fim): o \"fecha às\" do tipo No pulso "
                    . "segue o fim do horário. Desligados, só as marcações Pôs e Tirou contam; o que foi marcado sempre vale.",
                "Toda sessão esquecida aberta fecha sozinha no \"fecha às\" do tipo dela (a do sol, em sol_fim).",
            ]),
            $secao("cron", "O cron", [
                "A cada minuto: confere o token, o fuso e as migrações (pendente: registra e para); apaga execuções antigas (sem atividade, 7 dias; com "
                    . "atividade ou erro, 1 ano); monta o dia do plano que falta; fecha as sessões esquecidas; abre a sessão no pulso do dia; dispara os "
                    . "eventos personalizados no minuto marcado (depois de uma parada de até 7 dias, sai uma vez só, sem repetir); a manutenção dos "
                    . "documentos.",
                "Na hora da manhã (horario_manha), uma vez por dia: refaz a escala a partir do estado real, manda o Telegram do dia (o relógio de hoje e "
                    . "os avisos) e sincroniza a agenda. Na hora da noite (horario_noite): o Telegram da véspera (o relógio de amanhã e o que preparar) e a "
                    . "sincronização; e conta os documentos perdidos.",
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
            $secao("telas", "As telas", [
                "Hoje: o relógio do dia (com o Pôs e o Tirou), \"Hoje é dia de\" (os avisos com o botão que resolve), os próximos dias com o motivo e o "
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
                "Toda página e todo pedido da API exigem autenticação; o cron e os scripts só pela linha de comando. Os arquivos internos (núcleo, banco, "
                    . "configuração, scripts, testes, dados, os que começam com ponto) ficam bloqueados no servidor web (.htaccess e nginx-relogios.conf).",
                "SQL sempre com parâmetros; textos escapados no HTML; XML da NF-e lido sem entidades nem rede; arquivos servidos inline só dos tipos "
                    . "seguros; senhas com password_hash; o token comparado em tempo constante.",
                "A API nunca devolve senhas; os arquivos dos documentos só pelo recurso=documento.",
            ]),
            $secao("testes", "Os testes e a aceitação", [
                "testes/cenario.php faz pela API o que uma pessoa faz (cadastra relógios de cada tipo, lança cordas, cargas, sol, winder e pilha, monta a "
                    . "escala, mexe nos critérios, nos eventos e nas fotos, roda o cron) e tira a fotografia de tudo o que a API devolve; "
                    . "testes/comparar.php compara duas fotografias. Com RELOGIOS_SEMENTE (o sorteio fica previsível), o roteiro rodado em MySQL, "
                    . "PostgreSQL e SQLite, num banco recém-instalado, tem de dar as mesmas respostas; e a reescrita, as mesmas da versão atual.",
                "Além disso: nenhuma escrita do roteiro recusada; o dicionário da ajuda explica todos os campos das respostas; as telas sem erro de "
                    . "JavaScript e sem rolagem lateral no celular, no tablet e no computador.",
            ]),
        ],
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
