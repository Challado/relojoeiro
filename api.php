<?php
/*
 * Relógios 2 — API. A única porta do sistema: as páginas e qualquer programa leem e gravam por aqui. As páginas do site
 * são só a tela: o JavaScript de cada uma chama este arquivo (com o login do site) e monta o HTML com a resposta.
 *
 * ---------------------------------------------------------------------------------------------
 * AUTENTICAÇÃO
 * ---------------------------------------------------------------------------------------------
 *   O config.php tem de definir API_TOKEN com pelo menos 10 caracteres (sem contar espaços nas pontas); sem isso o
 *   sistema inteiro para e responde 500 {"erro": "Sistema parado: ..."}.
 *   Todo pedido, consulta ou escrita, exige o token ou o login do site (HTTP Basic):
 *     curl -H "X-Api-Token: segredo" "http://servidor/relojoeiro/api.php"
 *     http://servidor/relojoeiro/api.php?token=segredo
 *     curl -u lucas:senha "http://servidor/relojoeiro/api.php"
 *   Sem nenhum dos dois: 401. O token é comparado sem os espaços das pontas.
 *
 * ---------------------------------------------------------------------------------------------
 * FORMATO
 * ---------------------------------------------------------------------------------------------
 *   JSON (padrão) ou XML: formato=xml, ou o cabeçalho Accept com xml. Erros saem no mesmo formato.
 *
 * ---------------------------------------------------------------------------------------------
 * CONSULTA (GET)
 * ---------------------------------------------------------------------------------------------
 *   (sem recurso)   tudo o que está guardado, sem filtro: {"arvore", "campos", "lancamento_tipos", "formulas", "avisos",
 *                   "relogios", "modos", "plano", "config", "eventos", "agenda", "criterios", "cron", "usuarios", "migracoes",
 *                   "motor"}. A senha dos usuários não existe no sistema: sai o hash dela (veja SENHAS). Parâmetro foto=nao deixa as fotos de fora (senão vêm em
 *                   base64). Os arquivos dos documentos não vêm aqui (só os dados de cada um): recurso=documento. Para pegar só uma parte, ou filtrar, ordenar e paginar qualquer lista: veja FILTROS, abaixo.
 *     "arvore":            [{"id", "pai_id", "nome", "ordem", "caminho", "profundidade"}]  em ordem de árvore
 *     "campos":            [{"id", "identificador", "nome", "tipo", "unidade", "opcoes", "padrao", "no_id", "lugar", "ordem"}]
 *                          o campo vale para os relógios do ponto (no_id) e de tudo abaixo; vazio: todos
 *     "lancamento_tipos":  [{"id", "identificador", "nome", "formato", "unidade", "fecha_as", "exclusiva", "no_id", "lugar", "ordem"}]
 *                          formato: instantaneo, valor (com uma leitura) ou sessao (com início e fim); fecha_as (HH:MM, só
 *                          sessão): a sessão esquecida aberta conta só até essa hora do dia em que começou; exclusiva: o
 *                          relógio fica num lugar só (abrir fecha a outra exclusiva aberta; períodos não se sobrepõem)
 *     "formulas":          [{"id", "identificador", "nome", "expressao", "unidade", "no_id", "lugar", "usa": {"variaveis",
 *                          "lancamentos"}}]  a mesma fórmula pode ter uma versão por ponto; vale a do mais perto do relógio
 *     "relogios":          [{"id", "nome", "no_id", "lugar", "disponivel", "copia_banco", "criado",
 *                          "foto": {"tipo", "atualizada_em", "base64"} ou null,
 *                          "documentos": [os documentos, como em recurso=documentos, sem o resumo da NF-e],
 *                          "campos": [{"identificador", "nome", "tipo", "unidade", "valor" (o gravado), "valor_usado" (com o padrão)}],
 *                          "formulas": [{"identificador", "nome", "unidade", "valor", "versao" (o lugar da versão usada)}],
 *                          "avisos": [os avisos calculados, como em recurso=avisos, sem relogio_id e relogio],
 *                          "lancamento_tipos": [os identificadores dos tipos que valem para ele],
 *                          "lancamentos": [{"id", "tipo", "inicio", "fim", "valor", "origem", "criado"}],
 *                          "previsao": {a previsão da energia, como em recurso=previsao},
 *                          "linha_do_tempo": [a linha do tempo inteira, como as linhas de recurso=historico, sem relogio_id e relogio],
 *                          "resumo_do_tempo": {"tempo": {estado: {"segundos", "texto", "porcentagem"}}, "marcacoes": {tipo: quantas}},
 *                          "medicoes": [as medições do gasto pelas leituras],
 *                          e as autonomias, em segundos, como em recurso=autonomia: "autonomia_prevista", "autonomia_atual",
 *                          "autonomia_estimada", "restante_em_uso", "restante_guardado", "acaba_em_unixtimestamp",
 *                          "acaba_em_segundos", "acaba_em_datacomtz", "calculado_em_unixtimestamp", "em_uso", "energia", "motivos"}]
 *     "modos":             [{"id", "nome", "selecao", "escala_dias" (vazio: sorteio pelos blocos; número: escala inteligente),
 *                          "ordem", "ativo", "blocos": [{"id", "nome", "dias", "no_id", "lugar", "um_por", "relogio_id"}]}]
 *     "plano":             [{"data", "relogio_id", "relogio", "bloco_id", "bloco", "origem" (sorteio ou manual), "acao" (o que fazer
 *                          no dia, anotado pela escala), "motivo" (por que este relógio saiu), "criado"}]  o plano inteiro gravado, do primeiro dia ao último (a escala
 *                          de dois anos sai inteira)
 *     "config":            {chave: valor}  toda a configuração, inclusive o caminho da chave do Google (agenda_chave)
 *     "eventos":           [os eventos personalizados, como em recurso=eventos, com todos os disparos]
 *     "agenda":            {"criados": [os eventos que o sistema criou no Google Agenda: {"chave", "google_id", "data", "titulo",
 *                          "assinatura", "criado"}], "desejados": [o que tem de estar na agenda agora, como em recurso=agenda]}
 *     "criterios":         os critérios inteiros, como em recurso=criterios: os conjuntos por lugar (parâmetros, subparâmetros,
 *                          faixas), as variáveis que podem medir, a nota de cada relógio com a conta, os lugares e os modos
 *     "cron":              {"ultima" (a última execução), "erro" (o erro guardado; vazio: sem erro), "execucoes": [todas as
 *                          guardadas, da mais recente: {"id", "inicio", "fim", "duracao_ms", "teve_atividade", "teve_erro", "registro"}]}
 *     "usuarios":          [{"login", "criado"}]  (a senha nunca sai)
 *     "migracoes":         {"pendentes": []}  (com alguma pendente, a API responde 503 e só recurso=migracoes funciona)
 *     "avisos":            [{"id", "identificador", "nome", "expressao" (a data prevista), "antecedencia_dias", "texto", "resolve",
 *                          "ativo", "no_id", "lugar", "escala" (nao, uso, sempre), "simula_valor", "simula_horas", "agenda" (janela
 *                          ou sempre: a data na agenda)}]  a mesma chave pode ter uma versão por ponto; vale a do mais perto
 *     "motor":             {"funcoes": {NOME: descrição}, "tipos_de_campo", "formatos_de_lancamento"}
 *                   Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?foto=nao"
 *   recurso=avisos    os avisos de todos os relógios agora, do mais urgente ao mais distante: [{"relogio_id", "relogio",
 *                   "disponivel", "identificador", "nome", "data", "falta_dias" (negativo: atrasado), "estado" (atrasado,
 *                   em_breve ou ok), "texto", "resolve", "versao", "escala", "simula_valor", "simula_horas", "agenda", "modelo" (o texto
 *                   com as âncoras)}]. Sem data prevista (falta um dado): o aviso não aparece.
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=avisos"
 *   recurso=calcular  expressao=<fórmula> [relogio=3 ou 1,2,3]: calcula a fórmula (sem gravar) em cada relógio:
 *                   {"expressao", "resultados": [{"relogio_id", "relogio", "valor", "partes": {nome: valor}}]}
 *                   Erro de escrita: 400 {"erro", "erros": [...]}. É o "testar" das fórmulas.
 *                            Ex.: curl -u lucas:senha -G "http://servidor/relojoeiro/api.php" --data-urlencode recurso=calcular --data-urlencode "expressao=energia * 2" -d relogio=10,12
 *   recurso=foto relogio=3   a imagem (não é JSON)
 *                            Ex.: curl -u lucas:senha -o foto.jpg "http://servidor/relojoeiro/api.php?recurso=foto&relogio=10"
 *   recurso=documentos       [relogio=<id>; sem ele, de todos] os documentos (o manual, a nota fiscal, fotos, vídeos...):
 *                            {"relogio": {"id", "nome", "copia_banco"} ou null, "pasta_ok", "copia_sistema", "pasta_erro", "limite" (bytes;
 *                            null: sem limite),
 *                            "categorias": [{"id", "identificador", "nome", "aceita", "ordem", "documentos"}], "relogios": [{"id",
 *                            "nome", "documentos"}], "documentos": [{"id", "relogio_id", "categoria_id", "titulo", "data",
 *                            "descricao", "nome", "tipo", "familia", "tamanho", "miniatura", "criado", "url", "no_disco", "no_banco",
 *                            "copia_banco", "copia_por", "nfe"}]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=documentos&relogio=10"
 *   recurso=documento id=7   o arquivo de um documento (não é JSON). Imagens, vídeos, áudios, PDF e texto vêm para mostrar;
 *                            o resto (inclusive XML, HTML e SVG), para baixar. baixar=1: sempre para baixar. mini=1: a
 *                            miniatura da foto (sem ela, a foto). Aceita o pedido de um pedaço (Range), que o vídeo usa. Se o
 *                            arquivo sumiu da pasta, ele é recriado antes a partir da cópia no banco.
 *                            Ex.: curl -u lucas:senha -o nota.pdf "http://servidor/relojoeiro/api.php?recurso=documento&id=7"
 *   recurso=criterios        {"conjuntos": [{"escopo" ("" todos, "g:<ponto>", "r:<relógio>"), "lugar", "usado_por": [ids],
 *                            "parametros": [{"id", "ordem", "escopo_no_id", "escopo_relogio_id", "nome", "peso", "subparametros":
 *                            [{"id", "ordem", "nome", "variavel", "peso", "peso_efetivo_no_conjunto", "faixas": [{"id", "de", "ate",
 *                            "categoria", "nota"}]}]}]}], "variaveis": {o que um subparâmetro pode medir: campos e fórmulas},
 *                            "notas": [{"relogio_id", "relogio", "disponivel", "nota", "conjunto", "conjunto_texto", "conta": [{"parametro",
 *                            "subparametro", "variavel", "valor", "faixa", "nota", "peso_efetivo", "pontos"}]}], "max_sem_uso"}
 *                            o relógio usa o conjunto mais perto dele, inteiro; sem conjunto nenhum, nota 50
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=criterios"
 *   recurso=historico        a linha do tempo (a mesma da página Histórico): trechos contínuos, cada um num estado — rodizio (em
 *                            uso pelo rodízio), pulso (no pulso fora do rodízio), cada tipo de sessão (winder, sol...), repouso —
 *                            e as marcações instantâneas (marca: corda, leitura de carga, troca de pilha...).
 *                            relogio=3 ou relogio=1,2 (relogio_id também vale); sem ele, todos os relógios juntos.
 *                            de, ate: data (AAAA-MM-DD: "de" às 00:00:00, "ate" às 23:59:59) ou data e hora (AAAA-MM-DD HH:MM[:SS],
 *                            espaço ou T); entra a linha que cruza o período. estado=rodizio,pulso,winder,sol,repouso,marca (um ou
 *                            vários; linha_estado também vale; sem ele, todos). limite (por página, padrão 100, máximo 1000),
 *                            pagina, ordem=asc|desc (padrão: mais recente primeiro; dois no mesmo instante, pela ordem em que foram
 *                            lançados).
 *                            {"filtro": {"relogios", "de", "ate", "estados", "ordem", "ignorados"}, "estados": {estado: texto},
 *                            "total", "pagina", "por_pagina", "paginas", "resumo": {"<id>": {"relogio", "tempo": {estado: {"segundos",
 *                            "texto", "porcentagem"}}, "marcacoes": {tipo: quantas}}}, "linhas": [{"relogio_id", "relogio", "inicio",
 *                            "fim", "em_andamento", "duracao_seg", "duracao", "estado", "tipo", "texto"}], "lancamentos": [os
 *                            lançamentos crus do período, até o limite]}. O resumo conta o tempo dentro do período (o trecho que
 *                            cruza a borda, só a parte de dentro) e ignora o filtro de estado.
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=historico&relogio=3&de=2026-09-01&estado=marca"
 *   recurso=previsao         [relogio=ids] a previsão da energia dos relógios com leitura (o smartwatch): {"previsoes": [{"relogio_id",
 *                            "relogio", "aplica" (tem leitura com valor), "linhas" (as frases da página), "energia", "dura_dias",
 *                            "dura_ate", "limite" (a Configuração, 20%), "chega_limite_em" (parado), "proxima_entrada",
 *                            "carga_na_entrada", "precisa", "carregar_antes", "leitura", "leitura_em", "confianca" (alta, média,
 *                            baixa), "conta": [{"campo", "nome", "valor", "unidade", "origem": informado, padrão ou vazio}],
 *                            "gasto": {"uso" e "repouso": {"cadastro", "medido", "antes", "vale"}, "janela_dias", "medicoes",
 *                            "medicoes_antes", "conjunta"}}]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=previsao&relogio=10"
 *   recurso=plano            [de, ate: AAAA-MM-DD; ou dias=N: os próximos N dias, de hoje em diante; dias=todos: de hoje até o fim]
 *                            o plano gravado (sem nada: inteiro), com "escala_fim", o último dia gravado e o modo ativo, e o resumo do
 *                            período como a página Plano mostra: {"modo", "escala_fim", "plano_fim", "hoje", "plano": [como em "plano"
 *                            acima], "resumo": {"de", "ate", "dias", "relogios": [{"relogio_id", "relogio", "dias", "porcentagem",
 *                            "proximo", "a_mao"}], "sem_dias": [{"id", "nome"}]}, "relogios"}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=plano&de=2026-10-01&ate=2026-10-31"
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=plano&dias=90"
 *   recurso=eventos          os eventos personalizados: {"eventos": [{"id", "nome", "ativo", "repeticao" (uma, diaria,
 *                            semanal, mensal, intervalo), "descricao" ("seg, qua 20:00"), "hora", "data_inicio", "dias_semana",
 *                            "dia_mes", "intervalo_dias", "relogio_id", "relogio", "telegram" e "agenda" (se vai por cada canal, em
 *                            "O que vai para onde"), "criado", "proximas" (as 10
 *                            próximas ocorrências, no próximo ano), "disparos": [{"ocorrencia", "disparado"}]}]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=eventos"
 *   recurso=agenda           o Google Agenda: {"ativa", "agenda_id", "janela_dias", "chave_ok" (a chave JSON foi lida e o Google
 *                            deu o token; só é conferido com agenda_id preenchido), "desejados": [o que tem de estar na agenda:
 *                            {"chave", "data", "hora", "momento", "tipo", "relogio_id", "relogio", "acao", "motivo", "titulo",
 *                            "descricao"}], "criados": [os eventos criados pelo sistema]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=agenda"
 *   recurso=autonomia        [relogio=ids] as autonomias de cada relógio, enxuto e rápido, para sistemas de fora: quanto dura cheio pelo
 *                            cadastro (autonomia_prevista) e pela conta do sistema com o gasto medido (autonomia_atual), quanto ainda
 *                            dura a partir de agora seguindo o plano (autonomia_estimada), no pulso sem tirar (restante_em_uso) e
 *                            guardado (restante_guardado), e quando acaba (acaba_em_unixtimestamp, acaba_em_segundos,
 *                            acaba_em_datacomtz com o fuso). Tudo em segundos inteiros; null com o motivo em "motivos".
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=autonomia"
 *                            Ex.: os que acabam nas próximas 24 horas:
 *                                 curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?recurso=autonomia&f[relogios][acaba_em_segundos][ate]=86400&mostrar[relogios]=nome,acaba_em_datacomtz"
 *   recurso=hoje             tudo o que a página Hoje mostra: {"data", "agora", "uso_inicio", "comecou" (o dia já começou no pulso), "pulso_poe_sozinho", "pulso_tira_sozinho",
 *                            "modo": {"id", "nome", "selecao", "escala_dias"}, "escala_fim", "max_sem_uso", "dia": {"data", "relogio_id",
 *                            "relogio", "ate" ("só hoje", "até sexta, 02/10"), "acao" (o lembrete do dia), "motivo" (por que ele saiu)}, "avisos": [os de hoje, atrasados
 *                            ou em breve: {"relogio_id", "relogio", "identificador", "nome", "texto" (o motivo), "estado", "resolve":
 *                            {"identificador", "nome", "formato", "unidade"} (o lançamento do botão)}], "plano": [os próximos 62 dias:
 *                            {"data", "relogio_id", "relogio", "acao", "motivo", "origem"}], "proxima_semana": {"segunda", "domingo", "ja_montada"}, "modos":
 *                            [{"id", "nome", "selecao", "escala_dias", "blocos": [{"id", "nome", "dias", "no_id", "um_por", "relogio_id"}]}],
 *                            "grupos": [{"id", "nome", "profundidade", "caminho"}], "avisos_nomes", "relogios": [{"id", "nome",
 *                            "disponivel", "tipo" (o grupo), "em_uso", "agora" ("Em repouso desde 21:40"), "carga" (%), "carga_de" (de onde
 *                            vem), "ultimo" (a última vez no pulso, em segundos), "ultimo_txt", "situacao": [linhas], "manutencao":
 *                            {"nome", "data", "momento", "falta"} (o aviso mais perto), "compra": {"data", "valor", "loja", "garantia_ate"},
 *                            "leitura": {"inicio", "valor", "unidade"} (a última), "de_hoje", "com_aviso", "foto" (a versão)}], "totais":
 *                            {"relogios", "disponiveis", "em_uso", "valor"} (da coleção inteira)}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=hoje"
 *   recurso=ficha            relogio=<id>: tudo o que o painel de um relógio mostra: {"hoje", "copia_sistema", "painel_modo",
 *                            "relogio": {"id", "nome", "no_id",
 *                            "disponivel", "copia_banco", "tipo", "caminho", "observacao", "foto", "documentos": {"total",
 *                            "categorias": [{"id", "nome", "documentos"}], "pasta_ok", "copia_sistema"}, "em_uso", "agora", "carga",
 *                            "carga_de", "situacao", "nota": {"nota", "conjunto_texto"}, "proxima" (a próxima entrada no plano), "proxima_ate", "escala_fim",
 *                            "compra", "manutencoes": [{"data", "nome"}], "previsao" (como em recurso=previsao), "tipos": [os lançamentos
 *                            do relógio: {"identificador", "nome", "formato", "unidade", "aberta": {"inicio", "rodizio", "texto"}}],
 *                            "leituras": [as 40 últimas com valor: {"inicio", "valor", "unidade", "em_uso"}], "linha_do_tempo" (as 5
 *                            linhas mais recentes), "registros", "desde"}, "campos": [o cadastro: {"identificador", "nome", "tipo",
 *                            "unidade", "opcoes", "padrao", "no_id", "valor"}], "grupos": [{"id", "caminho", "cadeia"}]}. Sem relogio:
 *                            só "campos" e "grupos" (o cadastro de um relógio novo); relógio que não existe: 404
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=ficha&relogio=10"
 *   recurso=config           tudo o que a página Configuração mostra: {"config": {chave: valor} (inclusive agenda_chave), "sol_fim",
 *                            "chave_agenda": {"client_email"} (a chave JSON foi lida; null: não), "pasta", "canais" (tg e ag: o nome,
 *                            a lista de avisos e a ajuda), "ancoras", "repeticoes", "tipos": [cada tipo de aviso: {"tipo" (dia, vespera,
 *                            o identificador do aviso, ev<id>), "nome", "quando", "evento" (o id, se for um evento), "canais": {"tg": {
 *                            "marcado", "proprio", "corpo"}, "ag": {...}}}], "eventos": [como em recurso=eventos, com "proximas_60" (as
 *                            5 dos próximos 60 dias)], "relogios": [{"id", "nome", "disponivel"}], "previa": {"manha", "noite" (as
 *                            mensagens do Telegram como sairiam agora), "agenda" (os 8 primeiros eventos: {"data", "hora", "titulo",
 *                            "descricao"}), "sincronizados"}, "cron_estado": {"situacao" (nunca, parado ou rodando), "ultima",
 *                            "minutos", "texto", "crontab", "erro"}}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=config"
 *   recurso=cron             as execuções do cron: de, ate (data, ou data e hora; cron_de e cron_ate também valem), situacao
 *                            (atividade, o padrão; erro, nada ou todas; cron_situacao também vale), busca (texto no registro;
 *                            cron_busca também vale), limite (padrão 100, máximo 1000), pagina: {"filtro", "ultima" (a última execução),
 *                            "resumo": {"total", "atividade", "erros", "mais_lenta_ms"} (do período, sem os outros filtros), "total",
 *                            "pagina", "por_pagina", "paginas", "execucoes": [{"inicio", "fim", "duracao_ms", "teve_atividade",
 *                            "teve_erro", "registro"}]}. Sem atividade, as execuções ficam 7 dias; com atividade ou erro, 1 ano
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=cron&situacao=erro&de=2026-09-01&busca=agenda"
 *   recurso=arvore           os grupos em ordem de árvore: {"grupos": [{"id", "pai_id", "nome", "profundidade", "caminho", "cadeia",
 *                            "subgrupos", "relogios": [nomes, direto no grupo], "criterios" (quantos parâmetros próprios)}], "relogios":
 *                            [{"id", "nome", "no_id", "disponivel"}]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=arvore"
 *   recurso=cadastros        tudo o que a página Cadastros mostra: {"campos", "lancamento_tipos" (com "lancamentos": quantos cada um
 *                            tem), "formulas", "avisos" (todas as versões, como no banco), "modos" (com "blocos"), "modo_ativo",
 *                            "max_sem_uso", "grupos", "relogios", "funcoes" (as do motor), "tipos_de_campo", "formatos_de_lancamento"}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=cadastros"
 *   recurso=usuarios         {"usuarios": [{"login", "criado", "senha_hash", "senha_algoritmo", "senha_custo"}], "voce" (o login de quem
 *                            pediu, pelo site; vazio pelo token)}. O hash serve para migrar os usuários com a mesma senha (veja SENHAS)
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=usuarios"
 *   recurso=migracoes        {"pendentes": [{"versao", "arquivo", "traz"}]} (lista vazia: o banco está em dia)
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=migracoes"
 *   recurso=instalacao       a situação da instalação (responde também com o banco vazio, pelo token): {"banco" (mysql, pgsql ou
 *                            sqlite), "vazio", "instalado", "tabelas", "em_dia", "migracoes_pendentes": [versões], "usuarios" (quantos),
 *                            "proximo_passo" (instalar, aplicar as migrações, criar o primeiro usuário ou nada)}
 *                            Ex.: curl -H "X-Api-Token: segredo" "http://servidor/relojoeiro/api.php?recurso=instalacao"
 *   recurso=importacao       o que a importação do sistema anterior usa: {"servidor_antigo": {"host", "porta", "usuario", "de_onde"} (sem a
 *                            senha), "mysqli" (a extensão do MySQL no PHP), "relogios_neste_banco", "precisa_substituir", "traz": [o que ela
 *                            traz]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=importacao"
 *   recurso=entrar           o login das páginas: sem login, 401 (o navegador pede o usuário e a senha); com login, volta para a página
 *                            de volta=<uma página do sistema> (302) ou diz quem entrou: {"usuario" (null pelo token), "por" (login ou
 *                            token), "volta"}. É para onde o api.js manda quem abre uma página sem login
 *                            Ex.: no navegador, http://servidor/relojoeiro/api.php?recurso=entrar&volta=index.php
 *   recurso=ajuda            esta documentação em JSON: cada consulta com a descrição, os parâmetros e um exemplo; cada escrita com as ações,
 *                            os campos de cada uma e um exemplo; o dicionário de todos os campos das respostas ("campos"); a
 *                            autenticação, o formato, os erros e as funções do motor. [parte=manual: o manual, abaixo;
 *                            parte=reconstrucao: a reconstrução, abaixo]
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=ajuda"
 *   recurso=manual           (ou recurso=ajuda&parte=manual) o texto da página Ajuda: os trechos do README para quem usa (a ideia
 *                            central, como funciona, o cadastro campo por campo, a Configuração, um dia com o sistema, as telas,
 *                            as perguntas frequentes e como reescrever o sistema do zero), em markdown: {"fonte" ("README.md"), "trechos": [{"de", "ate"}], "secoes":
 *                            [{"nivel", "titulo", "ancora", "texto"}], "sumario": [{"nivel", "titulo", "ancora"}], "markdown", "html" (o
 *                            que a página Ajuda mostra)}. Sem o README.md na pasta: 404. Responde mesmo com o banco desatualizado
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=manual&mostrar[secoes]=titulo,ancora" -g
 *   recurso=reconstrucao     (ou recurso=ajuda&parte=reconstrucao) o roteiro para reescrever a aplicação inteira do zero, em qualquer
 *                            linguagem e banco, chegando ao mesmo sistema: {"o_que_e", "como_usar": [textos], "secoes": [{"id", "titulo",
 *                            "itens": [textos]}] (os princípios, a arquitetura, a ordem de construção e as regras de cada parte),
 *                            "modelo_de_dados": {"banco", "como_ler", "tabelas": [cada tabela com as colunas, as chaves, os índices e as
 *                            regras, lidas do próprio banco]}, "listas": {as funções das fórmulas, os tipos de campo, os formatos de
 *                            lançamento, as repetições, os dias da semana, as âncoras, os canais, as migrações e as constantes do config.php}}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=reconstrucao"
 *   A senha dos usuários não existe no sistema: sai o hash dela (recurso=usuarios), e o bloco SENHAS ensina a conferir e a migrar.
 *
 * ---------------------------------------------------------------------------------------------
 * SENHAS  (os usuários e como migrar com eles: o mesmo texto está em recurso=ajuda, em "senhas")
 * ---------------------------------------------------------------------------------------------
 *   como_fica      a senha não fica guardada em lugar nenhum: fica só o hash dela, feito pelo password_hash do PHP com
 *                  PASSWORD_DEFAULT (bcrypt). O hash tem 60 caracteres: $2y$, o custo com dois dígitos e $ (12 é 2^12 rodadas),
 *                  o sal (22 caracteres, sorteado a cada senha) e o resultado (31). O sal e o custo vão dentro do próprio hash
 *   como_confere   o hash não se desfaz em senha (é de mão única). Para conferir, calcula-se o bcrypt da senha digitada com o
 *                  sal e o custo que estão no hash e compara-se com o resultado: é o que o password_verify do PHP faz no login.
 *                  A mesma senha dá hashes diferentes a cada cálculo (o sal muda), e todos conferem
 *   migrar_daqui   leve o login e o senha_hash de cada um (recurso=usuarios, ou a resposta sem recurso) e, no outro sistema,
 *                  confira a senha digitada contra o hash com o bcrypt dele: cada um entra lá com a mesma senha de sempre. $2y$
 *                  (PHP) e $2b$ (Python, Node, Java, C#...) são o mesmo cálculo; se a biblioteca recusar o $2y$, troque o
 *                  começo por $2b$
 *   migrar_para_ca POST recurso=usuarios, acao=salvar, login e senha_hash (um bcrypt $2y$, $2b$ ou $2a$, ou um argon2): o
 *                  usuário entra aqui com a senha que tinha lá, sem ninguém saber qual é (o $2b$ é guardado como $2y$, o mesmo
 *                  cálculo)
 *   php            conferir: password_verify($senha, $hash); fazer: password_hash($senha, PASSWORD_DEFAULT)
 *   python         pip install bcrypt; conferir: bcrypt.checkpw(senha.encode(), hash.encode()); fazer:
 *                  bcrypt.hashpw(senha.encode(), bcrypt.gensalt()).decode()
 *   node           npm install bcryptjs; conferir: require("bcryptjs").compareSync(senha, hash); fazer:
 *                  require("bcryptjs").hashSync(senha, 12)
 *   java           Spring Security: new BCryptPasswordEncoder().matches(senha, hash) (com jBCrypt, BCrypt.checkpw(senha, hash)
 *                  trocando $2y$ por $2a$)
 *   csharp         BCrypt.Net-Next: BCrypt.Net.BCrypt.Verify(senha, hash)
 *
 * ---------------------------------------------------------------------------------------------
 * FILTROS  (em qualquer consulta: a resposta sem parâmetros e todos os recursos acima)
 * ---------------------------------------------------------------------------------------------
 *   incluir=relogios,plano     só essas partes da resposta (as de cima: arvore, relogios, plano, criterios, cron...)
 *   excluir=motor,criterios    a resposta sem essas partes
 *   As regras abaixo valem para uma lista da resposta, pelo caminho dela entre os colchetes: relogios, plano, avisos, eventos,
 *   usuarios, cron.execucoes, criterios.notas, criterios.conjuntos, agenda.desejados, relogios.lancamentos, relogios.avisos,
 *   relogios.linha_do_tempo, relogio.leituras (na ficha)... Lista dentro de lista (relogios.lancamentos) vale em cada relógio.
 *   O campo é qualquer um do item; com ponto entra num objeto (compra.valor) ou, numa lista de itens com identificador, no
 *   item daquele identificador (formulas.energia.valor, campos.loja.valor, campos.preferencia.valor).
 *   f[lista][campo]=v            igual a v (vários separados por vírgula: igual a um deles); numa lista de valores, se algum for
 *   f[lista][campo][de]=v        a partir de v (números como números; datas e textos como texto)
 *   f[lista][campo][ate]=v       até v (uma data sem hora vai até o fim daquele dia)
 *   f[lista][campo][contem]=t    o texto contém t
 *   f[lista][campo][diferente]=v diferente de v (vários separados por vírgula: de todos)
 *   f[lista][campo][vazio]=1     vazio (1) ou preenchido (0)
 *   busca[lista]=t               o texto t em qualquer campo do item
 *   ordem[lista]=campo           ordena pelo campo; -campo: do maior para o menor; vários separados por vírgula
 *   limite[lista]=N, pagina[lista]=P   N itens por página, a página P
 *   mostrar[lista]=id,nome       só esses campos de cada item (com ponto também: formulas.energia.valor)
 *   Os textos são comparados sem diferença de maiúsculas e acentos ("relogio" acha "Relógio"). Verdadeiro e falso valem 1 e 0.
 *   Filtros juntos: o item tem de passar em todos. A resposta ganha "_filtros": {"listas": {caminho: {"antes", "passaram",
 *   "devolvidos", "pagina", "limite"}}, "ignorados": [o caminho sem lista, a parte que não existe]}. Sem colchetes, limite,
 *   pagina, ordem e busca continuam sendo os do recurso (historico, cron). Com filtros, objetos vazios saem como listas vazias.
 *   Ex.: só os relógios disponíveis, com o id e o nome:
 *        curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?incluir=relogios&f[relogios][disponivel]=1&mostrar[relogios]=id,nome&foto=nao"
 *   Ex.: os relógios com energia até 20%, da menor para a maior:
 *        curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?incluir=relogios&f[relogios][formulas.energia.valor][ate]=20&ordem[relogios]=formulas.energia.valor&mostrar[relogios]=nome,formulas.energia.valor&foto=nao"
 *   Ex.: as leituras de carga de setembro de dois relógios:
 *        curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?incluir=relogios&f[relogios][id]=10,12&f[relogios.lancamentos][tipo]=carga&f[relogios.lancamentos][inicio][de]=2026-09-01&f[relogios.lancamentos][inicio][ate]=2026-09-30&mostrar[relogios]=nome,lancamentos&foto=nao"
 *   Ex.: comprados na AliExpress:
 *        curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?incluir=relogios&f[relogios][campos.loja.valor][contem]=aliexpress&mostrar[relogios]=nome,campos.valor_compra.valor&foto=nao"
 *   Ex.: o plano de outubro, só o Xiaomi:
 *        curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?recurso=plano&f[plano][data][de]=2026-10-01&f[plano][data][ate]=2026-10-31&f[plano][relogio][contem]=xiaomi"
 *   Ex.: as execuções do cron com erro, 20 por página, a segunda página:
 *        curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?incluir=cron&f[cron.execucoes][teve_erro]=1&limite[cron.execucoes]=20&pagina[cron.execucoes]=2"
 *   Ex.: as notas acima de 50, da maior para a menor:
 *        curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?incluir=criterios&f[criterios.notas][nota][de]=50&ordem[criterios.notas]=-nota&mostrar[criterios.notas]=relogio,nota"
 *   Ex.: na página Hoje, só os relógios com aviso:
 *        curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?recurso=hoje&f[relogios][com_aviso]=1&mostrar[relogios]=nome,manutencao.nome"
 *   (o -g do curl deixa os colchetes passarem; no navegador, é só escrever o endereço)
 *
 * ---------------------------------------------------------------------------------------------
 * ESCRITA (POST)  tudo o que o sistema faz, com as validações e as mensagens das operações (operacoes.php)
 * ---------------------------------------------------------------------------------------------
 *   Campos em formulário ou em JSON no corpo (Content-Type: application/json); recurso e acao nos campos ou na URL.
 *   Resposta: {"ok", "mensagem", "erros": [...], "id"}; 400 quando recusado (os erros dizem por quê).
 *   recurso=arvore            novo (nome, pai_id: 0 = na raiz), renomear (id, nome), mover (id, pai_id), ordem (id, direcao:
 *                             sobe ou desce), excluir (id: o que é dele sobe para o ponto de cima: os pontos de dentro, os
 *                             relógios, os campos, os tipos de lançamento, as fórmulas e os avisos, menos as versões de fórmulas e
 *                             avisos que o de cima já tem, que saem; os blocos dos modos que sorteavam dele passam a sortear do de
 *                             cima; os critérios próprios dele saem), relogios (grupo[<relógio>]: o grupo de cada relógio, 0 = na raiz)
 *                            Ex. novo: curl -u lucas:senha -d recurso=arvore -d acao=novo -d "nome=Cronógrafos" -d pai_id=2 http://servidor/relojoeiro/api.php
 *                            Ex. renomear: curl -u lucas:senha -d recurso=arvore -d acao=renomear -d id=5 -d "nome=Automáticos" http://servidor/relojoeiro/api.php
 *                            Ex. mover: curl -u lucas:senha -d recurso=arvore -d acao=mover -d id=5 -d pai_id=0 http://servidor/relojoeiro/api.php
 *                            Ex. ordem: curl -u lucas:senha -d recurso=arvore -d acao=ordem -d id=5 -d direcao=sobe http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=arvore -d acao=excluir -d id=5 http://servidor/relojoeiro/api.php
 *                            Ex. relogios: curl -u lucas:senha -d recurso=arvore -d acao=relogios -d "grupo[13]=1" -d "grupo[4]=8" http://servidor/relojoeiro/api.php
 *   recurso=campos            novo, alterar (id): identificador, nome, tipo (inteiro, decimal, sim_nao, data, lista, texto),
 *                             unidade, opcoes (lista: uma por linha), padrao, no_id (0 = todos); excluir (id); ordem (id, direcao)
 *                            Ex. novo: curl -u lucas:senha -d recurso=campos -d acao=novo -d identificador=resistencia_agua -d "nome=Resistência à água" -d tipo=inteiro -d unidade=m -d no_id=0 http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=campos -d acao=alterar -d id=21 -d "nome=Resistência (m)" http://servidor/relojoeiro/api.php
 *                            Ex. ordem: curl -u lucas:senha -d recurso=campos -d acao=ordem -d id=21 -d direcao=sobe http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=campos -d acao=excluir -d id=21 http://servidor/relojoeiro/api.php
 *   recurso=lancamento_tipos  novo, alterar (id): identificador, nome, formato (instantaneo, valor, sessao), unidade, fecha_as,
 *                             exclusiva (1 ou 0, só sessão), mede_gasto (1 ou 0, só com valor: cada leitura mede o gasto), condicao
 *                             (vale quando: uma fórmula, 1 vale e 0 não; vazia, vale sempre; ex.: corda_manual), no_id;
 *                             excluir (id)
 *                            Ex. novo: curl -u lucas:senha -d recurso=lancamento_tipos -d acao=novo -d identificador=banho_ultrassom -d "nome=Banho ultrassônico" -d formato=instantaneo -d no_id=0 http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=lancamento_tipos -d acao=alterar -d id=11 -d fecha_as=18:00 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=lancamento_tipos -d acao=excluir -d id=11 http://servidor/relojoeiro/api.php
 *   recurso=formulas          nova (identificador, nome, expressao, unidade, no_id: fórmula nova, ou mais uma versão da que
 *                             existe, em outro ponto), alterar (id: nome, expressao, unidade, no_id), excluir (id)
 *                            Ex. nova: curl -u lucas:senha -d recurso=formulas -d acao=nova -d identificador=idade_dias -d "nome=Idade (dias)" --data-urlencode "expressao=AGORA() - data_compra" -d unidade=dias -d no_id=0 http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=formulas -d acao=alterar -d id=17 --data-urlencode "expressao=ARREDONDA(AGORA() - data_compra)" http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=formulas -d acao=excluir -d id=17 http://servidor/relojoeiro/api.php
 *   recurso=relogio           salvar (id: 0 ou ausente cria; nome, no_id, disponivel, copia_banco (1: a cópia no banco de todos os
 *                             documentos dele; o cron copia ou tira aos poucos), valores[identificador]: só muda o que vier;
 *                             valor vazio apaga; inválido fica o que estava e a mensagem diz), excluir (id),
 *                             foto (id, foto_base64), remover_foto (id)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=relogio -d acao=salvar -d id=10 -d "valores[preferencia]=80" -d disponivel=1 http://servidor/relojoeiro/api.php
 *                            Ex. foto: curl -u lucas:senha -d recurso=relogio -d acao=foto -d id=10 --data-urlencode foto_base64@foto.b64 http://servidor/relojoeiro/api.php
 *                            Ex. remover_foto: curl -u lucas:senha -d recurso=relogio -d acao=remover_foto -d id=10 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=relogio -d acao=excluir -d id=15 http://servidor/relojoeiro/api.php
 *   recurso=documentos        enviar (relogio_id, categoria_id, titulo, data AAAA-MM-DD, descricao, copia_banco (1: pede a cópia
 *                             destes arquivos no banco) e o arquivo: arquivos[] como upload, vários de uma vez, ou
 *                             arquivo_base64 com arquivo_nome; a miniatura de uma foto, opcional: miniatura (upload) ou
 *                             miniatura_base64; título vazio: o nome do arquivo), alterar (id: categoria_id, titulo, data,
 *                             descricao, copia_banco; só muda o que vier; a cópia entra ou sai do banco na hora), excluir (id: o
 *                             documento, o arquivo e a cópia no banco). O arquivo vai para a pasta e, se a cópia é pedida (pelo
 *                             config.php, pelo relógio ou pelo próprio arquivo), também para o banco
 *                            Ex. enviar: curl -u lucas:senha -F recurso=documentos -F acao=enviar -F relogio_id=10 -F categoria_id=2 -F "arquivos[]=@nota.pdf" http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=documentos -d acao=alterar -d id=7 -d "titulo=No casamento" -d data=2026-09-20 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=documentos -d acao=excluir -d id=7 http://servidor/relojoeiro/api.php
 *   recurso=documento_categorias nova (identificador, nome, aceita[]: imagem, video, audio, pdf, xml; nenhum: qualquer
 *                             arquivo; ordem), alterar (id: nome, aceita[], ordem; o identificador não muda), excluir (id; só
 *                             sem documentos)
 *                            Ex. nova: curl -u lucas:senha -d recurso=documento_categorias -d acao=nova -d identificador=garantia -d nome=Garantia -d "aceita[]=pdf" http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=documento_categorias -d acao=excluir -d id=7 http://servidor/relojoeiro/api.php
 *   recurso=lancamento        lancar (relogio_id, tipo, valor, quando: AAAA-MM-DD HH:MM, vazio = agora; medir: 1 (padrão) ou 0,
 *                             num tipo que mede o gasto: com 1 a medição entra na média, com 0 fica só no histórico — a caixa
 *                             "Atualizar o gasto com esta medição"; a mensagem traz a conta), iniciar (relogio_id,
 *                             tipo, quando: abre a sessão; exclusiva fecha a outra exclusiva aberta), encerrar (relogio_id, tipo,
 *                             quando), periodo (relogio_id, tipo, inicio (quando também vale), fim: uma sessão que já passou),
 *                             alterar (id: quando ou inicio, fim, valor; só o que vier), excluir (id). Nada no futuro; o tipo tem
 *                             de valer para o relógio; exclusivas não se sobrepõem
 *                            Ex. lancar: curl -u lucas:senha -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 http://servidor/relojoeiro/api.php
 *                            Ex. iniciar: curl -u lucas:senha -d recurso=lancamento -d acao=iniciar -d relogio_id=4 -d tipo=sol http://servidor/relojoeiro/api.php
 *                            Ex. encerrar: curl -u lucas:senha -d recurso=lancamento -d acao=encerrar -d relogio_id=4 -d tipo=sol http://servidor/relojoeiro/api.php
 *                            Ex. periodo: curl -u lucas:senha -d recurso=lancamento -d acao=periodo -d relogio_id=3 -d tipo=winder -d "inicio=2026-09-26 20:00" -d "fim=2026-09-27 07:00" http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=lancamento -d acao=alterar -d id=42 -d valor=70 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=lancamento -d acao=excluir -d id=42 http://servidor/relojoeiro/api.php
 *   recurso=avisos            novo (identificador, nome, expressao: a data prevista, condicao: vale quando (uma fórmula, 1 vale e 0
 *                             não; vazia, vale sempre; ex.: corda_manual = 0), antecedencia_dias, texto com {relogio},
 *                             {data}, {quando} e {limite}, resolve: o tipo de lançamento, ativo, no_id, escala: nao, uso (conferido no
 *                             relógio do dia na escala inteligente) ou sempre (também nos guardados), simula_valor e simula_horas
 *                             (o lançamento que a escala simula para resolver), agenda: janela (na agenda, só dentro da antecedência)
 *                             ou sempre (qualquer data); se vai pela agenda ou pelo Telegram é a Configuração), alterar (id), excluir (id)
 *                            Ex. novo: curl -u lucas:senha -d recurso=avisos -d acao=novo -d identificador=pulseira -d "nome=Trocar a pulseira" --data-urlencode "expressao=data_compra + 365" -d antecedencia_dias=15 -d "texto=vence {quando}, {data}" -d no_id=0 http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=avisos -d acao=alterar -d id=9 -d antecedencia_dias=30 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=avisos -d acao=excluir -d id=9 http://servidor/relojoeiro/api.php
 *   recurso=criterios         escopo: "" (todos), g:<ponto> ou r:<relógio>. conjunto_criar (escopo, origem: herdado ou vazio),
 *                             conjunto_excluir (escopo), param_novo (escopo, nome, peso), param_pesos (escopo, nome[id], peso[id]:
 *                             todos os do conjunto, somando 100), param_excluir (id), sub_novo (parametro_id, nome, variavel
 *                             (metrica também vale), peso), sub_pesos (parametro_id, nome[id], peso[id]: somando 100), sub_excluir
 *                             (id), sub_medida (id, variavel (metrica também vale): as faixas recomeçam), sub_mover (id,
 *                             parametro_id), ordem (tipo: parametro ou sub, id, direcao),
 *                             faixas (sub_id, de[], ate[], categoria[], nota[], apagar[]), restaurar (volta aos critérios iniciais).
 *                             Incluir e excluir redistribuem os pesos na proporção; a mensagem traz a conta
 *                            Ex. conjunto_criar: curl -u lucas:senha -d recurso=criterios -d acao=conjunto_criar -d escopo=r:10 -d origem=herdado http://servidor/relojoeiro/api.php
 *                            Ex. conjunto_excluir: curl -u lucas:senha -d recurso=criterios -d acao=conjunto_excluir -d escopo=r:10 http://servidor/relojoeiro/api.php
 *                            Ex. param_novo: curl -u lucas:senha -d recurso=criterios -d acao=param_novo -d escopo=g:1 -d "nome=Estilo" -d peso=10 http://servidor/relojoeiro/api.php
 *                            Ex. param_pesos: curl -u lucas:senha -d recurso=criterios -d acao=param_pesos -d escopo=g:1 -d "peso[5]=35" -d "peso[6]=10" http://servidor/relojoeiro/api.php
 *                            Ex. param_excluir: curl -u lucas:senha -d recurso=criterios -d acao=param_excluir -d id=29 http://servidor/relojoeiro/api.php
 *                            Ex. sub_novo: curl -u lucas:senha -d recurso=criterios -d acao=sub_novo -d parametro_id=5 -d "nome=Carga" -d variavel=energia -d peso=20 http://servidor/relojoeiro/api.php
 *                            Ex. sub_pesos: curl -u lucas:senha -d recurso=criterios -d acao=sub_pesos -d parametro_id=5 -d "peso[6]=60" -d "peso[7]=40" http://servidor/relojoeiro/api.php
 *                            Ex. sub_excluir: curl -u lucas:senha -d recurso=criterios -d acao=sub_excluir -d id=7 http://servidor/relojoeiro/api.php
 *                            Ex. sub_medida: curl -u lucas:senha -d recurso=criterios -d acao=sub_medida -d id=7 -d variavel=dias_de_carga http://servidor/relojoeiro/api.php
 *                            Ex. sub_mover: curl -u lucas:senha -d recurso=criterios -d acao=sub_mover -d id=7 -d parametro_id=8 http://servidor/relojoeiro/api.php
 *                            Ex. ordem: curl -u lucas:senha -d recurso=criterios -d acao=ordem -d tipo=parametro -d id=5 -d direcao=desce http://servidor/relojoeiro/api.php
 *                            Ex. faixas: curl -u lucas:senha -d recurso=criterios -d acao=faixas -d sub_id=6 -d "de[0]=0" -d "ate[0]=50" -d "nota[0]=30" -d "de[1]=50" -d "ate[1]=100" -d "nota[1]=100" http://servidor/relojoeiro/api.php
 *                            Ex. restaurar: curl -u lucas:senha -d recurso=criterios -d acao=restaurar http://servidor/relojoeiro/api.php
 *   recurso=rodizio           modo (o quadro Modo de rodízio: modo (id), bloco_alvo[<bloco>] (0 ou o grupo; r:<id>, um relógio fixo),
 *                             bloco_cada_dia[<bloco>] (presente: um por dia), selecao, escala_dias, max_sem_uso: grava e ativa o modo;
 *                             se o dia ainda não começou no pulso, o plano novo vale já de hoje),
 *                             resortear (de amanhã até domingo; se o dia ainda não começou no pulso, também hoje), resortear_hoje
 *                             (inclusive hoje: o sorteado passa a ser o do pulso a partir de agora), usando (relogio_id: o
 *                             relógio de hoje a partir de agora), trocar_dia (data, relogio_id: o relógio daquele dia, à mão;
 *                             hoje, como o usando; 0: o dia volta a ser sorteado), proxima_semana (só no domingo: a semana
 *                             seguinte inteira).
 *                             Na escala inteligente: sortear de novo refaz a escala; usando segura o escolhido nos dias que
 *                             faltavam do bloco de hoje e refaz a escala depois deles; proxima_semana é recusada
 *                            Ex. modo: curl -u lucas:senha -d recurso=rodizio -d acao=modo -d modo=2 -d "bloco_alvo[8]=1" -d "bloco_cada_dia[8]=1" -d selecao=ponderado -d max_sem_uso=21 http://servidor/relojoeiro/api.php
 *                            Ex. resortear: curl -u lucas:senha -d recurso=rodizio -d acao=resortear http://servidor/relojoeiro/api.php
 *                            Ex. resortear_hoje: curl -u lucas:senha -d recurso=rodizio -d acao=resortear_hoje http://servidor/relojoeiro/api.php
 *                            Ex. usando: curl -u lucas:senha -d recurso=rodizio -d acao=usando -d relogio_id=12 http://servidor/relojoeiro/api.php
 *                            Ex. trocar_dia: curl -u lucas:senha -d recurso=rodizio -d acao=trocar_dia -d data=2026-10-09 -d relogio_id=12 http://servidor/relojoeiro/api.php
 *                            Ex. proxima_semana: curl -u lucas:senha -d recurso=rodizio -d acao=proxima_semana http://servidor/relojoeiro/api.php
 *   recurso=modos             salvar (id: 0 cria; nome, selecao: inteligente, ponderado, aleatorio ou fifo; escala_dias: vazio ou 0
 *                             = sorteio pelos blocos, 7 a 730 = escala inteligente com esse horizonte; blocos: [{nome, dias:
 *                             [1..7], no_id (0: todos), um_por: bloco ou dia, relogio_id: fixo (0: sorteia)}], substitui os
 *                             blocos; ciclo: 1 ou 0 (padrão): com o ciclo, um relógio só volta depois que todos os disponíveis do bloco
 *                             passaram na semana — na escala, pelo período dela), ativar (id: o plano de amanhã em diante é refeito),
 *                             excluir (id: não o ativo)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=modos -d acao=salvar -d id=0 -d "nome=Só smartwatch" -d selecao=inteligente -d "blocos[0][nome]=Todo dia" -d "blocos[0][dias][]=1" -d "blocos[0][dias][]=7" -d "blocos[0][no_id]=1" -d "blocos[0][um_por]=dia" http://servidor/relojoeiro/api.php
 *                            Ex. ativar: curl -u lucas:senha -d recurso=modos -d acao=ativar -d id=5 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=modos -d acao=excluir -d id=6 http://servidor/relojoeiro/api.php
 *   recurso=config            salvar (horario_manha, horario_noite, uso_inicio, uso_fim, sol_fim (a sessão no sol esquecida fecha
 *                             a essa hora): HH:MM; pulso_auto_inicio e pulso_auto_fim (1 ou 0: pôr no pulso sozinho no
 *                             início do horário de uso, tirar sozinho no fim; 0: só pelo Pôs, só pelo Tirou); carga_limiar (1 a 99: carregar nessa carga, o geral); sol_limiar (1 a 99: o
 *                             solar vai para o sol nessa carga); url_sistema; painel_modo (lado: o relógio abre no painel ao lado da
 *                             lista; flutuante: numa janela grande por cima da página);
 *                             alerta_ativo (ou mensagens_ativas) e agenda_ativa: 1 ou 0; agenda_id; agenda_chave; agenda_antecedencia
 *                             (1 a 365); max_sem_uso (0 a 365); previsao_limite (0 a 100); medicao_janela_dias (1 a 3650: a média
 *                             do gasto medido usa as medições destes últimos dias); os canais: alerta_tipos[] e agenda_tipos[]
 *                             (os tipos de aviso que vão por cada um: dia, vespera, os identificadores dos avisos, ev<id>), tg_padrao
 *                             e ag_padrao (a mensagem padrão), tg_proprio_<tipo> e ag_proprio_<tipo> (1 ou 0), tg_corpo_<tipo> e
 *                             ag_corpo_<tipo> (a mensagem personalizada); só o que vier), testar_manha, testar_noite (mandam a
 *                             mensagem agora), evento_salvar (evento_id ou id: 0 cria; nome, ativo, repeticao, hora, data_inicio,
 *                             dias_semana, dia_mes, intervalo_dias, relogio_id; o evento novo já vai pelo Telegram), evento_excluir
 *                             (evento_id ou id), teste_agenda_criar, teste_agenda_remover, sincronizar
 *                            Ex. salvar: curl -u lucas:senha -d recurso=config -d acao=salvar -d horario_manha=06:30 -d alerta_ativo=1 --data-urlencode "tg_padrao={acao}: {relogio}" -d tg_proprio_corda=1 --data-urlencode "tg_corpo_corda=Corda no {relogio}!" http://servidor/relojoeiro/api.php
 *                            Ex. testar_manha: curl -u lucas:senha -d recurso=config -d acao=testar_manha http://servidor/relojoeiro/api.php
 *                            Ex. testar_noite: curl -u lucas:senha -d recurso=config -d acao=testar_noite http://servidor/relojoeiro/api.php
 *                            Ex. evento_salvar: curl -u lucas:senha -d recurso=config -d acao=evento_salvar -d evento_id=0 -d "nome=Limpar as pulseiras" -d repeticao=semanal -d "dias_semana[]=1" -d "dias_semana[]=4" -d hora=20:00 http://servidor/relojoeiro/api.php
 *                            Ex. evento_excluir: curl -u lucas:senha -d recurso=config -d acao=evento_excluir -d evento_id=3 http://servidor/relojoeiro/api.php
 *                            Ex. teste_agenda_criar: curl -u lucas:senha -d recurso=config -d acao=teste_agenda_criar http://servidor/relojoeiro/api.php
 *                            Ex. teste_agenda_remover: curl -u lucas:senha -d recurso=config -d acao=teste_agenda_remover http://servidor/relojoeiro/api.php
 *                            Ex. sincronizar: curl -u lucas:senha -d recurso=config -d acao=sincronizar http://servidor/relojoeiro/api.php
 *   recurso=usuarios          salvar (login, senha; ou login e senha_hash, o hash de outro sistema: veja SENHAS), excluir (login). O
 *                             primeiro usuário sai pelo token (sem usuários, o login não entra)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=usuarios -d acao=salvar -d login=visitante -d senha=segredo123 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=usuarios -d acao=excluir -d login=visitante http://servidor/relojoeiro/api.php
 *   recurso=migracoes         aplicar: aplica as migrações pendentes, na ordem: o SQL de cada uma e, se ela tiver, o passo em PHP (a única escrita
 *                            aceita com o banco desatualizado)
 *                            Ex. aplicar: curl -u lucas:senha -d recurso=migracoes -d acao=aplicar http://servidor/relojoeiro/api.php
 *   recurso=instalacao        instalar: instala o banco do config.php, só num banco vazio (o schema.sql traduzido para o banco, com os
 *                             passos em PHP das migrações), pelo token (antes da instalação não há usuários); a resposta traz
 *                             "comandos", "tabelas" e "segundos". Depois: o primeiro usuário, em recurso=usuarios, acao=salvar
 *                            Ex. instalar: curl -H "X-Api-Token: segredo" -d recurso=instalacao -d acao=instalar http://servidor/relojoeiro/api.php
 *   recurso=importacao        importar (banco: o nome do banco antigo, MySQL/MariaDB; substituir=1: apaga antes os relógios e o histórico
 *                             deste banco, obrigatório quando ele já tem relógios): traz a árvore, os relógios, as fotos, os usuários,
 *                             os valores dos campos e o histórico; tudo numa transação (parou no meio: nada fica gravado); a resposta
 *                             traz "contagem" (quantos de cada)
 *                            Ex. importar: curl -u lucas:senha -d recurso=importacao -d acao=importar -d banco=relogios -d substituir=1 http://servidor/relojoeiro/api.php
 *
 * ---------------------------------------------------------------------------------------------
 * DICIONÁRIO DOS CAMPOS  (o que é, para que serve e que valor tem cada campo; o mesmo está em recurso=ajuda, "campos")
 * ---------------------------------------------------------------------------------------------
 *   [] no caminho: uma lista (o campo é de cada item); <...>: uma chave que varia (o identificador de um campo, um estado,
 *   uma função...). Datas e horas no fuso do sistema (o FUSO do config.php; sem ele, o do PHP, date.timezone); "null": sem valor. Números de tempo em segundos são inteiros.
 *
 *   api.php (sem recurso)
 *     agenda.criados[]               os eventos que o sistema criou no Google Agenda
 *     agenda.criados[].assinatura    a marca do título e da descrição com que foi criado (mudou: o evento é atualizado na próxima sincronização)
 *     agenda.criados[].chave         a identificação do evento (a mesma dos desejados)
 *     agenda.criados[].criado        quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     agenda.criados[].data          o dia do evento (texto AAAA-MM-DD)
 *     agenda.criados[].google_id     o id do evento no Google
 *     agenda.criados[].titulo        o título com que foi criado
 *     agenda.desejados[]             o que tem de estar no Google Agenda agora: os avisos marcados para a agenda, o relógio do dia, a véspera e os
 *                                    eventos
 *     agenda.desejados[].chave       a identificação do evento na agenda (a mesma enquanto nada mudar; é o que o sistema usa para atualizar ou remover)
 *     agenda.desejados[].data        o dia do evento na agenda (texto AAAA-MM-DD)
 *     agenda.desejados[].descricao   a descrição do evento, montada pela mensagem do canal da agenda
 *     agenda.desejados[].fazer       a ação ("Dar corda", "Usar hoje", o nome do evento) — a âncora {acao}
 *     agenda.desejados[].hora        a hora do evento na agenda (HH:MM)
 *     agenda.desejados[].momento     o instante previsto do que motivou o evento (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     agenda.desejados[].motivo      o porquê ("a reserva acaba em 3h, 28/09 10:53") — a âncora {motivo}
 *     agenda.desejados[].relogio     o nome do relógio; vazio num evento geral
 *     agenda.desejados[].relogio_id  o relógio do evento; null num evento geral
 *     agenda.desejados[].tipo        o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)
 *     agenda.desejados[].titulo      o título do evento, montado pela mensagem do canal da agenda
 *     arvore[]                       os grupos, em ordem de árvore
 *     arvore[].caminho               o caminho por extenso ("Tradicional › Quartzo › Solar")
 *     arvore[].id                    o número do grupo
 *     arvore[].nome                  o nome do grupo
 *     arvore[].ordem                 a posição entre os irmãos
 *     arvore[].pai_id                o grupo de cima; null: na raiz
 *     arvore[].profundidade          o nível na árvore (0: na raiz)
 *     avisos[]                       os avisos cadastrados (cada identificador pode ter uma versão por grupo)
 *     avisos[].agenda                a data na agenda: janela (só dentro da antecedência da agenda) ou sempre
 *     avisos[].antecedencia_dias     com quantos dias antes ele entra "em breve" (número)
 *     avisos[].ativo                 o aviso vale
 *     avisos[].condicao              vale quando: uma fórmula que diz para quais relógios do grupo o aviso vale (1 vale, 0 não; ex.: corda_manual = 0,
 *                                    só o automático sem corda); null: vale para todos
 *     avisos[].escala                na escala inteligente: nao, uso ou sempre
 *     avisos[].expressao             a fórmula da data prevista
 *     avisos[].id                    o número da versão
 *     avisos[].identificador         o aviso (o tipo dele nos canais)
 *     avisos[].lugar                 esse grupo por extenso
 *     avisos[].no_id                 o grupo desta versão; null: todos
 *     avisos[].nome                  o nome do aviso — a âncora {acao}
 *     avisos[].resolve               o tipo de lançamento que resolve (o botão na tela Hoje); null: nenhum
 *     avisos[].simula_horas          as horas da sessão que a escala simula para resolver (número; null)
 *     avisos[].simula_valor          o valor do lançamento que a escala simula para resolver (número; null)
 *     avisos[].texto                 o motivo, com {relogio}, {data}, {quando} e {limite} — a âncora {motivo}
 *     campos[]                       os campos do cadastro dos relógios (cada um vale para o grupo dele e tudo abaixo)
 *     campos[].id                    o número do campo
 *     campos[].identificador         o nome do campo nas fórmulas e nos critérios
 *     campos[].lugar                 esse grupo por extenso
 *     campos[].no_id                 o grupo em que o campo vale; null: todos os relógios
 *     campos[].nome                  o nome do campo
 *     campos[].opcoes                as opções de um campo de lista, uma por linha (texto; vazio nos outros)
 *     campos[].ordem                 a posição do campo no cadastro
 *     campos[].padrao                o valor usado quando o relógio não tem o campo preenchido (texto; null: nenhum)
 *     campos[].tipo                  inteiro, decimal, sim_nao, data, lista ou texto
 *     campos[].unidade               a unidade (texto; vazio: nenhuma)
 *     config.ag_padrao               a mensagem padrão da agenda: a primeira linha é o título do evento, o resto a descrição
 *     config.agenda_antecedencia     com quantos dias de antecedência os eventos são criados na agenda
 *     config.agenda_ativa            1: cria os eventos no Google Agenda; 0: não
 *     config.agenda_chave            o caminho da chave JSON da conta de serviço do Google, no servidor
 *     config.agenda_id               o id da agenda do Google
 *     config.agenda_teste_id         o id do evento de teste na agenda (vazio: nenhum)
 *     config.carga_limiar            o limite de carga geral (%): carregar quando a carga chega a ele (o campo carga_minima do relógio, se preenchido, vale no lugar)
 *     config.cron_erro               o erro guardado da última execução com erro (texto; vazio: sem erro)
 *     config.cron_registro           o registro da última rodada com atividade (texto)
 *     config.cron_ultima_execucao    quando o cron rodou pela última vez (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     config.escala_fim              até que dia vai a escala inteligente gerada (texto AAAA-MM-DD; vazio fora da escala)
 *     config.escala_gerada           o dia em que a escala foi gerada pela última vez (texto AAAA-MM-DD)
 *     config.horario_manha           a hora da rodada da manhã do cron: o relógio do dia e os avisos (HH:MM)
 *     config.horario_noite           a hora da rodada da noite: preparar o relógio de amanhã (HH:MM)
 *     config.max_sem_uso             a garantia de rodízio: nenhum relógio passa desses dias sem uso (0 desliga)
 *     config.medicao_janela_dias     a média do gasto medido pelas leituras usa as medições destes últimos dias (sem nenhuma na janela, a última)
 *     config.mensagens_ativas        1: o Telegram (a API de alerta) envia; 0: não envia
 *     config.painel_modo             como o relógio abre na página Hoje: lado (no painel à direita da lista) ou flutuante (numa janela
 *                                    grande por cima da página); vazio: lado
 *     config.migracao_v10            marca de que a migração v10 foi aplicada (1)
 *     config.migracao_v12            marca de que a migração v12 foi aplicada (1)
 *     config.migracao_v16            marca de que a migração v16 foi aplicada (1)
 *     config.migracao_v17            marca de que a migração v17 foi aplicada (1)
 *     config.pulso_auto_fim          1 (ou vazio): o relógio sai do pulso sozinho no fim do horário de uso (o "fecha às" do tipo No pulso); 0: só pelo Tirou
 *     config.pulso_auto_inicio       1 (ou vazio): o relógio do dia entra no pulso sozinho no início do horário de uso; 0: só pelo Pôs
 *     config.previsao_limite         o limite de carga da previsão do smartwatch, em %
 *     config.sol_limiar              no solar, a carga (%) em que ele deve ir para o sol
 *     config.tg_padrao               a mensagem padrão do Telegram, com âncoras ({acao}, {relogio}, {motivo}...)
 *     config.ultima_manha            o dia da última rodada da manhã do cron (texto AAAA-MM-DD)
 *     config.ultima_noite            o dia da última rodada da noite do cron (texto AAAA-MM-DD)
 *     config.url_sistema             o endereço do sistema, para a âncora {link}
 *     config.uso_fim                 a hora em que ele sai do pulso (HH:MM)
 *     config.uso_inicio              a hora em que o relógio do dia vai para o pulso (HH:MM)
 *     criterios.conjuntos[]          os conjuntos de critérios, um por lugar que tem critérios próprios
 *     criterios.conjuntos[].escopo   o lugar: "" (todos os relógios), g:<grupo> ou r:<relógio>
 *     criterios.conjuntos[].lugar    o lugar por extenso
 *     criterios.conjuntos[].parametros[]
 *                                    os parâmetros do conjunto, em ordem
 *     criterios.conjuntos[].parametros[].escopo_no_id
 *                                    o grupo do conjunto; null se não é de grupo
 *     criterios.conjuntos[].parametros[].escopo_relogio_id
 *                                    o relógio do conjunto; null se não é de relógio
 *     criterios.conjuntos[].parametros[].id
 *                                    o número do parâmetro
 *     criterios.conjuntos[].parametros[].nome
 *                                    o nome do parâmetro
 *     criterios.conjuntos[].parametros[].ordem
 *                                    a posição dele no conjunto
 *     criterios.conjuntos[].parametros[].peso
 *                                    o peso no conjunto, em % (os do conjunto somam 100)
 *     criterios.conjuntos[].parametros[].subparametros[]
 *                                    os subparâmetros, em ordem
 *     criterios.conjuntos[].parametros[].subparametros[].faixas[]
 *                                    as faixas: o valor medido vira uma nota de 0 a 100
 *     criterios.conjuntos[].parametros[].subparametros[].faixas[].ate
 *                                    o fim da faixa (não entra, menos na última); null: sem limite
 *     criterios.conjuntos[].parametros[].subparametros[].faixas[].categoria
 *                                    a categoria (medida de lista); null numa faixa de números
 *     criterios.conjuntos[].parametros[].subparametros[].faixas[].de
 *                                    o começo da faixa (entra); null numa faixa de categoria
 *     criterios.conjuntos[].parametros[].subparametros[].faixas[].id
 *                                    o número da faixa
 *     criterios.conjuntos[].parametros[].subparametros[].faixas[].nota
 *                                    a nota da faixa, de 0 a 100
 *     criterios.conjuntos[].parametros[].subparametros[].id
 *                                    o número do subparâmetro
 *     criterios.conjuntos[].parametros[].subparametros[].nome
 *                                    o nome do subparâmetro
 *     criterios.conjuntos[].parametros[].subparametros[].ordem
 *                                    a posição dele no parâmetro
 *     criterios.conjuntos[].parametros[].subparametros[].peso
 *                                    o peso no parâmetro, em % (os do parâmetro somam 100)
 *     criterios.conjuntos[].parametros[].subparametros[].peso_efetivo_no_conjunto
 *                                    peso do subparâmetro × peso do parâmetro ÷ 100, em %
 *     criterios.conjuntos[].parametros[].subparametros[].variavel
 *                                    o que ele mede: o identificador de um campo ou de uma fórmula
 *     criterios.conjuntos[].usado_por[]
 *                                    os relógios disponíveis que usam este conjunto (ids)
 *     criterios.lugares[]            os lugares que podem ter critérios: todos os relógios, cada grupo (em ordem de árvore) e cada relógio
 *     criterios.lugares[].escopo     o lugar: "" (todos), g:<grupo> ou r:<relógio>
 *     criterios.lugares[].herda      o lugar de quem ele herda os critérios quando não tem os seus; null: ninguém (nota neutra)
 *     criterios.lugares[].herda_texto
 *                                    esse lugar por extenso
 *     criterios.lugares[].lugar      o lugar por extenso, curto
 *     criterios.lugares[].proprio    verdadeiro ou falso: o lugar tem critérios próprios
 *     criterios.lugares[].texto      o lugar como a lista da página mostra ("Grupo: Tradicional › Mecânico")
 *     criterios.max_sem_uso          a garantia de rodízio em dias (0: desligada)
 *     criterios.modos[]              os modos de rodízio e a forma de escolher de cada um
 *     criterios.modos[].ativo        verdadeiro ou falso: é o modo em uso
 *     criterios.modos[].escala       verdadeiro ou falso: o modo é escala inteligente (escolhe sempre pela maior nota)
 *     criterios.modos[].nome         o nome do modo
 *     criterios.modos[].selecao      a forma de escolha: inteligente (a maior nota), ponderado (sorteio pela nota), aleatorio ou fifo (o mais tempo sem
 *                                    uso)
 *     criterios.notas[]              a nota de cada relógio agora, com a conta
 *     criterios.notas[].conjunto     o lugar dos critérios usados: "" (todos), g:<grupo> ou r:<relógio>; null: nenhum (nota neutra, 50)
 *     criterios.notas[].conjunto_texto
 *                                    esse lugar por extenso
 *     criterios.notas[].conta[]      cada subparâmetro que entrou na conta
 *     criterios.notas[].conta[].faixa
 *                                    a faixa em que o valor caiu ("30 a 50", ou a categoria)
 *     criterios.notas[].conta[].nota a nota da faixa, de 0 a 100 (número)
 *     criterios.notas[].conta[].parametro
 *                                    o nome do parâmetro
 *     criterios.notas[].conta[].peso_efetivo
 *                                    o peso no conjunto: peso do subparâmetro × peso do parâmetro, reescalado sem os que não se aplicam, em % (número)
 *     criterios.notas[].conta[].pontos
 *                                    nota × peso efetivo ÷ 100 (número): quanto somou na nota
 *     criterios.notas[].conta[].subparametro
 *                                    o nome do subparâmetro
 *     criterios.notas[].conta[].valor
 *                                    o valor medido (número, ou o texto de uma lista)
 *     criterios.notas[].conta[].variavel
 *                                    o que o subparâmetro mede (o identificador do campo ou da fórmula)
 *     criterios.notas[].disponivel   verdadeiro ou falso: o relógio está disponível para o rodízio
 *     criterios.notas[].grupo        o grupo do relógio (o caminho na árvore)
 *     criterios.notas[].nota         a nota final, de 0 a 100 (número): a soma dos pontos
 *     criterios.notas[].relogio      o nome do relógio
 *     criterios.notas[].relogio_id   o relógio
 *     criterios.variaveis.<variavel> o que um subparâmetro pode medir: cada campo (menos texto) e cada fórmula, pelo identificador
 *     criterios.variaveis.<variavel>.max
 *                                    o limite da medida (a última faixa vai até ele ou sem limite): o que o nome diz em "(0 a N)"; null: sem limite
 *     criterios.variaveis.<variavel>.nome
 *                                    o nome (com a unidade)
 *     criterios.variaveis.<variavel>.tipo
 *                                    numero ou categoria (campo de lista)
 *     criterios.variaveis.<variavel>.valores[]
 *                                    as categorias possíveis (campo de lista); vazio nos números
 *     cron.erro                      o erro guardado (texto; vazio: sem erro; some na primeira execução sem erro)
 *     cron.execucoes[]               as execuções do cron guardadas (sem atividade: 7 dias; com atividade ou erro: 1 ano), da mais recente
 *     cron.execucoes[].duracao_ms    quanto durou, em milissegundos (número inteiro)
 *     cron.execucoes[].fim           quando terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     cron.execucoes[].id            o número da execução
 *     cron.execucoes[].inicio        quando começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     cron.execucoes[].registro      o que foi feito, linha por linha (texto; vazio: nada a fazer)
 *     cron.execucoes[].teve_atividade
 *                                    verdadeiro ou falso: fez alguma coisa (rodada, plano, evento, sincronização)
 *     cron.execucoes[].teve_erro     verdadeiro ou falso: houve erro
 *     cron.ultima                    quando o cron rodou pela última vez (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[]                      os eventos personalizados (avisos seus, com horário e repetição próprios)
 *     eventos[].agenda               verdadeiro ou falso: o evento vai para o Google Agenda
 *     eventos[].ativo                verdadeiro ou falso: o evento dispara
 *     eventos[].criado               quando o evento foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].data_inicio          a data (uma vez só) ou o começo da contagem (a cada N dias) (texto AAAA-MM-DD); null nas outras
 *     eventos[].descricao            a repetição por extenso ("seg, qua 20:00")
 *     eventos[].dia_mes              o dia do mês (mensal), de 1 a 31; null nas outras
 *     eventos[].dias_semana          os dias da semana (semanal), de 1 (segunda) a 7 (domingo), separados por vírgula; null nas outras
 *     eventos[].disparos[]           as vezes em que já disparou
 *     eventos[].disparos[].disparado quando o cron mandou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].disparos[].ocorrencia
 *                                    a vez que disparou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema, a do cadastro)
 *     eventos[].hora                 a hora do disparo (HH:MM)
 *     eventos[].id                   o número do evento (o tipo dele nos canais é ev<id>)
 *     eventos[].intervalo_dias       de quantos em quantos dias (intervalo); null nas outras
 *     eventos[].nome                 o nome do evento (texto)
 *     eventos[].proximas[]           as 10 próximas vezes em que dispara (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].relogio              o nome desse relógio; null: evento geral
 *     eventos[].relogio_id           o relógio do evento (as âncoras dele funcionam na mensagem); null: evento geral
 *     eventos[].repeticao            quando dispara: uma (uma vez só), diaria, semanal, mensal ou intervalo (a cada N dias)
 *     eventos[].telegram             verdadeiro ou falso: o evento vai pelo Telegram (marcado em "O que vai para onde")
 *     formulas[]                     as fórmulas (cada identificador pode ter uma versão por grupo; vale a do grupo mais perto do relógio)
 *     formulas[].expressao           a conta (texto, na escrita das fórmulas do motor)
 *     formulas[].id                  o número da versão
 *     formulas[].identificador       o nome da fórmula nas outras fórmulas e nos critérios
 *     formulas[].lugar               esse grupo por extenso
 *     formulas[].no_id               o grupo desta versão; null: todos
 *     formulas[].nome                o nome da fórmula
 *     formulas[].unidade             a unidade do resultado (texto)
 *     formulas[].usa.lancamentos[]   os tipos de lançamento que a conta usa
 *     formulas[].usa.variaveis[]     os campos e fórmulas que a conta usa
 *     lancamento_tipos[]             os tipos de lançamento: o que se registra num relógio
 *     lancamento_tipos[].condicao    vale quando: uma fórmula que diz para quais relógios do grupo o tipo vale (1 vale, 0 não; ex.: corda_manual, só
 *                                    quem aceita corda); null: vale para todos
 *     lancamento_tipos[].exclusiva   a sessão é exclusiva: o relógio fica num lugar só (abrir fecha a outra exclusiva)
 *     lancamento_tipos[].fecha_as    sessão esquecida aberta fecha sozinha a essa hora do dia em que começou (HH:MM; null: não fecha)
 *     lancamento_tipos[].formato     instantaneo (uma marcação: corda), valor (uma leitura: carga) ou sessao (com início e fim: pulso, sol)
 *     lancamento_tipos[].id          o número do tipo
 *     lancamento_tipos[].identificador
 *                                    o nome do tipo nas fórmulas (HORAS("pulso"; 30))
 *     lancamento_tipos[].lugar       esse grupo por extenso
 *     lancamento_tipos[].mede_gasto  cada leitura desse tipo mede o gasto, comparando com a anterior (a função MEDIDO)
 *     lancamento_tipos[].no_id       o grupo em que o tipo vale; null: todos
 *     lancamento_tipos[].nome        o nome do tipo
 *     lancamento_tipos[].ordem       a posição do tipo
 *     lancamento_tipos[].unidade     a unidade do valor (texto)
 *     migracoes.pendentes[]          as migrações do banco que faltam aplicar (vazio: em dia)
 *     migracoes.pendentes[].arquivo  o arquivo .sql
 *     migracoes.pendentes[].traz     o que a migração traz
 *     migracoes.pendentes[].versao   a versão (v2, v3...)
 *     modos[]                        os modos de rodízio
 *     modos[].ativo                  verdadeiro ou falso: é o modo em uso
 *     modos[].blocos[]               os blocos de dias da semana do modo
 *     modos[].blocos[].dias[]        os dias da semana do bloco, de 1 (segunda) a 7 (domingo)
 *     modos[].blocos[].id            o número do bloco
 *     modos[].blocos[].lugar         esse grupo por extenso
 *     modos[].blocos[].no_id         o grupo de onde sortear; null: todos
 *     modos[].blocos[].nome          o nome do bloco
 *     modos[].blocos[].relogio_id    o relógio fixo do bloco; null: sorteia
 *     modos[].blocos[].um_por        dia (um relógio por dia) ou bloco (um para o bloco inteiro na semana)
 *     modos[].ciclo                  (verdadeiro ou falso) o modo usa o ciclo: um relógio só volta depois que todos os disponíveis do bloco passaram na
 *                                    semana (na escala, pelo período dela); dentro do ciclo, a forma de escolha decide a ordem
 *     modos[].escala_dias            escala inteligente com esse horizonte, em dias (7 a 730); null: sorteio pelos blocos
 *     modos[].id                     o número do modo
 *     modos[].nome                   o nome do modo
 *     modos[].ordem                  a posição do modo
 *     modos[].selecao                a forma de escolha: inteligente, ponderado, aleatorio ou fifo
 *     motor.formatos_de_lancamento.<formato>
 *                                    cada formato de lançamento e o nome dele
 *     motor.funcoes.<funcao>         cada função do motor de fórmulas e como usar (texto)
 *     motor.tipos_de_campo.<tipo_de_campo>
 *                                    cada tipo de campo e o nome dele
 *     plano[]                        o plano gravado: o relógio de cada dia
 *     plano[].acao                   o lembrete do dia (o que fazer antes: carregar, dar corda...); null: nada
 *     plano[].bloco                  o nome desse bloco
 *     plano[].bloco_id               o bloco do modo que escolheu o dia; null: escala ou manual
 *     plano[].criado                 quando o dia foi gravado no plano (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     plano[].data                   o dia (texto AAAA-MM-DD)
 *     plano[].motivo                 por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null: o dia foi gravado antes de o sistema guardar o motivo
 *     plano[].origem                 sorteio (pelo modo) ou manual ("Usando hoje")
 *     plano[].relogio                o nome dele
 *     plano[].relogio_id             o relógio do dia
 *     relogios[]                     os relógios, com tudo o que se sabe de cada um
 *     relogios[].acaba_em_datacomtz  quando acaba, em ISO 8601 com o fuso (texto, ex.: 2026-10-02T18:40:00-03:00); null com o motivo
 *     relogios[].acaba_em_segundos   quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em segundos; igual a
 *                                    autonomia_estimada); null com o motivo
 *     relogios[].acaba_em_unixtimestamp
 *                                    quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde 01/01/1970 UTC));
 *                                    null com o motivo
 *     relogios[].autonomia_atual     quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no smartwatch, pelo
 *                                    gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo informado; nos outros, igual à
 *                                    prevista; a diferença para a prevista mostra a bateria envelhecendo; a fórmula autonomia_atual
 *     relogios[].autonomia_estimada  quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em que está no
 *                                    rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em motivos
 *     relogios[].autonomia_prevista  quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do smartwatch, a
 *                                    reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula autonomia_prevista; null se falta
 *                                    o dado no cadastro
 *     relogios[].avisos[]            os avisos do relógio agora (os que valem para ele e têm data prevista), do mais urgente ao mais distante
 *     relogios[].avisos[].agenda     a data na agenda: janela (só dentro da antecedência da agenda) ou sempre (qualquer data); se vai pela agenda é a
 *                                    Configuração
 *     relogios[].avisos[].data       a data prevista, pela fórmula do aviso (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].avisos[].escala     na escala inteligente: nao, uso (conferido no relógio do dia) ou sempre (também nos guardados)
 *     relogios[].avisos[].estado     atrasado (a data passou), em_breve (dentro da antecedência) ou ok
 *     relogios[].avisos[].falta_dias quanto falta para a data prevista, em dias (número; negativo: já passou)
 *     relogios[].avisos[].identificador
 *                                    o aviso (corda, carregar, sol, pilha, revisao, garantia, carga_baixa, leitura, ou um que você cadastrou)
 *     relogios[].avisos[].modelo     o texto do cadastro, sem trocar as âncoras (só o {limite})
 *     relogios[].avisos[].nome       o nome do aviso ("Dar corda") — a âncora {acao}
 *     relogios[].avisos[].resolve    o tipo de lançamento que resolve o aviso (o botão na tela Hoje); null: nenhum
 *     relogios[].avisos[].simula_horas
 *                                    as horas da sessão que a escala simula para resolver o aviso (número; null: nenhuma)
 *     relogios[].avisos[].simula_valor
 *                                    o valor do lançamento que a escala simula para resolver o aviso (leitura) (número; null: nenhum)
 *     relogios[].avisos[].texto      o motivo por extenso, com {relogio}, {data}, {quando} e {limite} trocados — a âncora {motivo}
 *     relogios[].avisos[].versao     o lugar da árvore da versão do aviso usada (texto)
 *     relogios[].calculado_em_unixtimestamp
 *                                    o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde 01/01/1970 UTC));
 *                                    segundos que faltam agora = acaba_em_unixtimestamp − hora atual
 *     relogios[].campos[]            os campos do cadastro que valem para ele
 *     relogios[].campos[].identificador
 *                                    o campo
 *     relogios[].campos[].nome       o nome do campo
 *     relogios[].campos[].tipo       o tipo do campo (inteiro, decimal, sim_nao, data, lista, texto)
 *     relogios[].campos[].unidade    a unidade
 *     relogios[].campos[].valor      o valor gravado no relógio (texto; null: não preenchido)
 *     relogios[].campos[].valor_usado
 *                                    o valor que as contas usam: o gravado, ou o padrão do campo
 *     relogios[].criado              quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].disponivel          verdadeiro ou falso: entra no rodízio
 *     relogios[].copia_banco         verdadeiro: o relógio pede a cópia no banco de todos os documentos dele (o segundo dos três níveis da
 *                                    cópia; o DOCUMENTOS_COPIA_BANCO do config.php, se definido, vale por cima)
 *     relogios[].em_uso              verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora
 *     relogios[].energia             a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para calcular
 *     relogios[].formulas[]          o resultado agora de cada fórmula que vale para ele
 *     relogios[].formulas[].identificador
 *                                    a fórmula
 *     relogios[].formulas[].nome     o nome da fórmula
 *     relogios[].formulas[].unidade  a unidade do resultado
 *     relogios[].formulas[].valor    o resultado agora (número ou texto; null: vazio)
 *     relogios[].formulas[].versao   o grupo da versão usada
 *     relogios[].foto                a foto; null: sem foto
 *     relogios[].foto.atualizada_em  quando a foto foi trocada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].foto.base64         a imagem em base64; null com foto=nao
 *     relogios[].foto.tipo           o tipo da imagem (image/jpeg, image/png, image/webp)
 *     relogios[].id                  o número (código) do relógio
 *     relogios[].lancamento_tipos[]  os identificadores dos tipos de lançamento que valem para ele (texto)
 *     relogios[].lancamentos[]       todos os lançamentos do relógio, do mais antigo para o mais recente
 *     relogios[].lancamentos[].criado
 *                                    quando foi gravado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].lancamentos[].fim   o fim da sessão (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null: marcação, leitura, ou sessão aberta
 *     relogios[].lancamentos[].id    o número do lançamento
 *     relogios[].lancamentos[].inicio
 *                                    quando (a marcação ou a leitura), ou o começo da sessão (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].lancamentos[].origem
 *                                    manual (lançado por alguém), rodizio (a sessão do dia, pelo cron) ou importado
 *     relogios[].lancamentos[].tipo  o identificador do tipo
 *     relogios[].lancamentos[].valor o valor da leitura (número); null nos outros
 *     relogios[].linha_do_tempo[]    os trechos da linha do tempo do relógio, do mais recente para o mais antigo: cada trecho é um período contínuo num
 *                                    estado, ou uma marcação (um lançamento instantâneo)
 *     relogios[].linha_do_tempo[].duracao
 *                                    a mesma duração por extenso ("2d 3h", "40min"); null numa marcação
 *     relogios[].linha_do_tempo[].duracao_seg
 *                                    quanto o trecho durou, número inteiro, em segundos; null numa marcação
 *     relogios[].linha_do_tempo[].em_andamento
 *                                    verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)
 *     relogios[].linha_do_tempo[].estado
 *                                    o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador de um tipo
 *                                    de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)
 *     relogios[].linha_do_tempo[].fim
 *                                    quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação
 *     relogios[].linha_do_tempo[].inicio
 *                                    quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].linha_do_tempo[].texto
 *                                    o trecho por extenso, como a tela mostra ("em uso", "no sol", "Leitura de carga 80%")
 *     relogios[].linha_do_tempo[].tipo
 *                                    o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no repouso
 *     relogios[].lugar               o grupo por extenso
 *     relogios[].medicoes[]          as medições do gasto pelas leituras (cada leitura de um tipo que mede o gasto, comparada com a anterior), da mais
 *                                    recente para a mais antiga
 *     relogios[].medicoes[].ate_valor
 *                                    a leitura que fechou a medição (número)
 *     relogios[].medicoes[].criado   quando a medição foi gravada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].medicoes[].de_valor a leitura de antes (número, na unidade da leitura)
 *     relogios[].medicoes[].fim      quando foi a leitura que fechou a medição (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].medicoes[].horas_guardado
 *                                    as horas guardado entre as duas leituras (número)
 *     relogios[].medicoes[].horas_pulso
 *                                    as horas no pulso entre as duas leituras (número)
 *     relogios[].medicoes[].id       o número da medição
 *     relogios[].medicoes[].inicio   quando foi a leitura de antes (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].medicoes[].lancamento_id
 *                                    o lançamento (a leitura) que fechou a medição
 *     relogios[].medicoes[].medida   o gasto do intervalo sozinho, no histórico: uso (o gasto por dia de uso, quando o intervalo teve
 *                                    meio dia de uso ou mais, ou mais dias de uso que fora) ou repouso (o gasto por dia fora do pulso);
 *                                    o gasto que vale sai de todas as medições juntas
 *     relogios[].medicoes[].na_media verdadeiro ou falso: entra na média agora (usada e dentro da janela da Configuração, medicao_janela_dias)
 *     relogios[].medicoes[].peso_horas
 *                                    o peso da medição na média: as horas que ela cobriu, no pulso (uso) ou guardado (repouso) (número)
 *     relogios[].medicoes[].taxa     o gasto medido, em % por dia de uso (uso) ou por dia guardado (repouso) (número)
 *     relogios[].medicoes[].usada    verdadeiro ou falso: a caixa "Atualizar o gasto com esta medição" estava marcada (entra na média); falso: só
 *                                    histórico
 *     relogios[].motivos             por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)
 *     relogios[].motivos.<campo>     o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a zero em até 10
 *                                    anos
 *     relogios[].no_id               o grupo do relógio; null: na raiz
 *     relogios[].nome                o nome do relógio
 *     relogios[].previsao            a previsão da energia (só nos relógios com leitura, como o smartwatch): as frases da tela e os mesmos números
 *     relogios[].previsao.aplica     verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)
 *     relogios[].previsao.carga_na_entrada
 *                                    com quanto ele entra nesse dia, em % (número)
 *     relogios[].previsao.carregar_antes
 *                                    verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)
 *     relogios[].previsao.chega_limite_em
 *                                    quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa de um ano
 *     relogios[].previsao.confianca  a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media ou baixa
 *                                    (leitura com mais de 7 dias)
 *     relogios[].previsao.conta[]    de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras
 *     relogios[].previsao.conta[].campo
 *                                    o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     relogios[].previsao.conta[].nome
 *                                    o nome do campo (ou do gasto medido, com quantas medições entraram)
 *     relogios[].previsao.conta[].origem
 *                                    de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido (pelas
 *                                    leituras)
 *     relogios[].previsao.conta[].unidade
 *                                    a unidade do valor (texto)
 *     relogios[].previsao.conta[].valor
 *                                    o valor usado na conta (número; null se vazio)
 *     relogios[].previsao.dura_ate   até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)
 *     relogios[].previsao.dura_dias  quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)
 *     relogios[].previsao.energia    a energia agora, em % (número; null sem leitura)
 *     relogios[].previsao.gasto      os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as tem)
 *     relogios[].previsao.gasto.conjunta
 *                                    true: os dois gastos saem juntos da conta das medições; false: a média das medições de cada um
 *     relogios[].previsao.gasto.janela_dias
 *                                    a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)
 *     relogios[].previsao.gasto.medicoes
 *                                    quantas medições há na janela (número)
 *     relogios[].previsao.gasto.medicoes_antes
 *                                    quantas medições há na janela anterior, a dos antes (número)
 *     relogios[].previsao.gasto.uso.antes
 *                                    o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)
 *     relogios[].previsao.gasto.uso.cadastro
 *                                    o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)
 *     relogios[].previsao.gasto.uso.medido
 *                                    o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null sem medição)
 *     relogios[].previsao.gasto.uso.vale
 *                                    o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)
 *     relogios[].previsao.gasto.repouso.antes
 *                                    o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)
 *     relogios[].previsao.gasto.repouso.cadastro
 *                                    o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)
 *     relogios[].previsao.gasto.repouso.medido
 *                                    o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null sem medição)
 *     relogios[].previsao.gasto.repouso.vale
 *                                    o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)
 *     relogios[].previsao.leitura    a última leitura, no valor informado (número)
 *     relogios[].previsao.leitura_em quando foi a última leitura (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].previsao.limite     o limite de carga da Configuração (previsao_limite), em % (número)
 *     relogios[].previsao.linhas[]   as frases da previsão, como a tela mostra (texto)
 *     relogios[].previsao.precisa    quanto ele precisa ter na entrada para os dias seguidos no pulso, em % (número)
 *     relogios[].previsao.proxima_entrada
 *                                    o próximo dia em que o relógio entra no rodízio (texto AAAA-MM-DD); null se não está no plano
 *     relogios[].restante_em_uso     quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em segundos); null
 *                                    com o motivo (ex.: o automático no pulso se recarrega e não acaba)
 *     relogios[].restante_guardado   quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em segundos);
 *                                    null com o motivo
 *     relogios[].resumo_do_tempo.marcacoes.<tipo_de_marcacao>
 *                                    quantas marcações de cada tipo (corda, carga, pilha...) (número)
 *     relogios[].resumo_do_tempo.tempo.<estado>.porcentagem
 *                                    a parte desse estado no tempo total, em %
 *     relogios[].resumo_do_tempo.tempo.<estado>.segundos
 *                                    quanto tempo o relógio passou no estado <estado> (rodizio, pulso, winder, sol, repouso...), número inteiro, em
 *                                    segundos
 *     relogios[].resumo_do_tempo.tempo.<estado>.texto
 *                                    o mesmo tempo por extenso
 *     usuarios[]                     quem acessa o site, com o hash da senha (a senha não existe no sistema)
 *     usuarios[].criado              quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     usuarios[].login               o login
 *     usuarios[].senha_algoritmo     o cálculo do hash: bcrypt (o padrão do password_hash do PHP), ou argon2i/argon2id
 *     usuarios[].senha_custo         o custo do bcrypt (quantas rodadas: 2 elevado a ele), que também está escrito no próprio hash
 *                                    ($2y$12$...: 12); null no argon2
 *     usuarios[].senha_hash          o hash da senha (a senha não existe no sistema): $2y$<custo>$ seguido do sal (22 caracteres) e do
 *                                    resultado (31). Não se desfaz em senha: confere-se a senha digitada contra ele (veja senhas na ajuda).
 *                                    Levado para outro sistema que confira por bcrypt, o usuário entra lá com a mesma senha; e volta para
 *                                    cá por usuarios/salvar com senha_hash
 *
 *     documento_categorias[]         as categorias dos documentos, na ordem
 *     documento_categorias[].aceita[]
 *                                    os tipos de arquivo que ela aceita (imagem, video, audio, pdf, xml); lista vazia: qualquer arquivo
 *     documento_categorias[].documentos
 *                                    quantos documentos ela tem
 *     documento_categorias[].id      o número da categoria
 *     documento_categorias[].identificador
 *                                    o identificador (minúsculas, números e _)
 *     documento_categorias[].nome    o nome
 *     documento_categorias[].ordem   a ordem em que ela aparece (número)
 *     relogios[].documentos[]        os documentos do relógio (o manual, a nota fiscal, fotos, vídeos...): os dados de cada um; o arquivo
 *                                    vem pelo recurso=documento
 *     relogios[].documentos[].categoria_id
 *                                    a categoria dele (as categorias dos documentos)
 *     relogios[].documentos[].criado quando foi enviado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].documentos[].data   a data do documento: a ocasião da foto, a data da nota (texto AAAA-MM-DD; null: sem data)
 *     relogios[].documentos[].descricao
 *                                    a descrição (texto; null: sem descrição)
 *     relogios[].documentos[].familia
 *                                    como ele abre, pelo tipo do arquivo: imagem (a galeria), video (o player), audio, pdf (o
 *                                    visualizador), xml (o resumo da nota e o download) ou outro (o download)
 *     relogios[].documentos[].id     o número do documento (para recurso=documento e para alterar ou excluir)
 *     relogios[].documentos[].miniatura
 *                                    verdadeiro: a foto tem miniatura (recurso=documento com mini=1)
 *     relogios[].documentos[].nome   o nome do arquivo enviado
 *     relogios[].documentos[].no_disco
 *                                    verdadeiro: o arquivo está na pasta dos documentos (falso: ele volta do banco quando for pedido ou na
 *                                    próxima rodada do cron; sem a cópia no banco, está perdido)
 *     relogios[].documentos[].no_banco
 *                                    verdadeiro: a cópia de segurança do arquivo está completa no banco (se ele sumir da pasta, volta
 *                                    dali)
 *     relogios[].documentos[].copia_banco
 *                                    verdadeiro: o próprio arquivo pede a cópia no banco (o terceiro nível; o relógio e o config.php, se
 *                                    pedem, valem por cima)
 *     relogios[].documentos[].copia_por
 *                                    quem pede a cópia no banco: sistema (o DOCUMENTOS_COPIA_BANCO = true do config.php), relogio (a marca
 *                                    do relógio) ou arquivo (a marca do próprio arquivo); null: ninguém pede, ou o config.php (false) não
 *                                    deixa, e o arquivo fica só na pasta (a cópia que houver, o cron tira)
 *     relogios[].documentos[].relogio_id
 *                                    o relógio dele
 *     relogios[].documentos[].tamanho
 *                                    o tamanho do arquivo, em bytes (número inteiro)
 *     relogios[].documentos[].tipo   o tipo do arquivo (MIME): image/jpeg, video/mp4, application/pdf, application/xml...
 *     relogios[].documentos[].titulo o título
 *     relogios[].documentos[].url    o endereço do arquivo, relativo à pasta do sistema (api.php?recurso=documento&id=...)
 *     relogios[].documentos[].nfe    sempre null aqui: o resumo da NF-e vem no recurso=documentos
 *   recurso=autonomia
 *     calculado_em_unixtimestamp     o instante da conta (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))
 *     relogios[]                     um relógio por item, com as autonomias
 *     relogios[].acaba_em_datacomtz  quando acaba, em ISO 8601 com o fuso (texto, ex.: 2026-10-02T18:40:00-03:00); null com o motivo
 *     relogios[].acaba_em_segundos   quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em segundos; igual a
 *                                    autonomia_estimada); null com o motivo
 *     relogios[].acaba_em_unixtimestamp
 *                                    quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde 01/01/1970 UTC));
 *                                    null com o motivo
 *     relogios[].autonomia_atual     quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no smartwatch, pelo
 *                                    gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo informado; nos outros, igual à
 *                                    prevista; a diferença para a prevista mostra a bateria envelhecendo; a fórmula autonomia_atual
 *     relogios[].autonomia_estimada  quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em que está no
 *                                    rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em motivos
 *     relogios[].autonomia_prevista  quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do smartwatch, a
 *                                    reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula autonomia_prevista; null se falta
 *                                    o dado no cadastro
 *     relogios[].calculado_em_unixtimestamp
 *                                    o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde 01/01/1970 UTC));
 *                                    segundos que faltam agora = acaba_em_unixtimestamp − hora atual
 *     relogios[].disponivel          verdadeiro ou falso: entra no rodízio
 *     relogios[].em_uso              verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora
 *     relogios[].energia             a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para calcular
 *     relogios[].id                  o número (código)
 *     relogios[].lugar               o grupo por extenso
 *     relogios[].motivos             por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)
 *     relogios[].motivos.<campo>     o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a zero em até 10
 *                                    anos
 *     relogios[].nome                o nome
 *     relogios[].restante_em_uso     quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em segundos); null
 *                                    com o motivo (ex.: o automático no pulso se recarrega e não acaba)
 *     relogios[].restante_guardado   quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em segundos);
 *                                    null com o motivo
 *
 *   recurso=hoje
 *     agora                          o instante da resposta (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))
 *     avisos[]                       os avisos de hoje (atrasados ou em breve), um por relógio e aviso
 *     avisos[].estado                atrasado ou em_breve
 *     avisos[].identificador         o aviso
 *     avisos[].nome                  o nome do aviso
 *     avisos[].relogio               o nome do relógio
 *     avisos[].relogio_id            o relógio
 *     avisos[].resolve               o lançamento do botão que resolve; null: nenhum
 *     avisos[].resolve.formato       o formato (instantaneo, valor, sessao)
 *     avisos[].resolve.identificador o tipo de lançamento
 *     avisos[].resolve.nome          o nome dele
 *     avisos[].resolve.unidade       a unidade do valor
 *     avisos[].texto                 o motivo por extenso
 *     avisos_nomes[]                 os nomes dos avisos (o filtro "O que fazer" da tabela)
 *     comecou                        verdadeiro ou falso: o dia já começou no pulso (há relógio do dia e: pondo no pulso sozinho,
 *                                    passou do uso_inicio; senão, ele já foi posto no pulso hoje)
 *     pulso_poe_sozinho              verdadeiro: o relógio do dia entra no pulso sozinho no início do horário de uso; falso: só pelo Pôs
 *     pulso_tira_sozinho             verdadeiro: o relógio sai do pulso sozinho no fim do horário de uso; falso: só pelo Tirou
 *     data                           hoje (texto AAAA-MM-DD)
 *     dia                            o relógio de hoje; null: nenhum
 *     dia.acao                       o lembrete do dia; null: nada
 *     dia.ate                        até quando ele fica ("só hoje", "até sexta, 02/10")
 *     dia.data                       hoje
 *     dia.motivo                     por que ele saiu hoje, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null: não foi guardado
 *     dia.relogio                    o nome dele
 *     dia.relogio_id                 o relógio de hoje
 *     escala_fim                     até que dia vai a escala (texto AAAA-MM-DD); null fora da escala
 *     grupos[]                       os grupos, em ordem de árvore
 *     grupos[].caminho               o caminho
 *     grupos[].id                    o número
 *     grupos[].nome                  o nome
 *     grupos[].profundidade          o nível (0: raiz)
 *     max_sem_uso                    a garantia de rodízio em dias
 *     modo                           o modo de rodízio em uso
 *     modo.escala_dias               o horizonte da escala inteligente, em dias; null: sorteio pelos blocos
 *     modo.id                        o número do modo
 *     modo.nome                      o nome
 *     modo.selecao                   a forma de escolha (inteligente, ponderado, aleatorio, fifo)
 *     modos[]                        os modos de rodízio (o quadro Modo de rodízio)
 *     modos[].blocos[]               os blocos
 *     modos[].blocos[].dias          os dias da semana, de 1 a 7, separados por vírgula
 *     modos[].blocos[].id            o número do bloco
 *     modos[].blocos[].no_id         o grupo de onde sortear (0: todos)
 *     modos[].blocos[].nome          o nome
 *     modos[].blocos[].relogio_id    o relógio fixo; null: sorteia
 *     modos[].blocos[].um_por        dia ou bloco
 *     modos[].ciclo                  (verdadeiro ou falso) o modo usa o ciclo: um relógio só volta depois que todos os disponíveis do bloco passaram na
 *                                    semana (na escala, pelo período dela); dentro do ciclo, a forma de escolha decide a ordem
 *     modos[].escala_dias            o horizonte da escala; null: sorteio
 *     modos[].id                     o número
 *     modos[].nome                   o nome
 *     modos[].selecao                a forma de escolha
 *     plano[]                        os próximos 62 dias do plano
 *     plano[].acao                   o lembrete do dia; null
 *     plano[].motivo                 por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null: o dia foi gravado antes de o sistema guardar o motivo
 *     plano[].origem                 sorteio (pelo modo) ou manual (escolhido à mão: "trocar por…" ou "Usando hoje")
 *     plano[].data                   o dia
 *     plano[].relogio                o nome
 *     plano[].relogio_id             o relógio
 *     proxima_semana.domingo         o domingo dela
 *     proxima_semana.ja_montada      verdadeiro ou falso: a próxima semana já está no plano
 *     proxima_semana.segunda         a segunda-feira da próxima semana (texto AAAA-MM-DD)
 *     relogios[]                     a tabela dos relógios
 *     relogios[].agora               o estado por extenso ("Em uso desde 07:00")
 *     relogios[].carga               a energia agora, em % inteiro; null: indisponível ou sem dados
 *     relogios[].carga_de            de onde vem a energia ("bateria do smartwatch") ou por que não há
 *     relogios[].com_aviso           verdadeiro ou falso: tem aviso hoje
 *     relogios[].compra.data         a data da compra (texto AAAA-MM-DD; null)
 *     relogios[].compra.garantia_ate até quando vai a garantia (texto AAAA-MM-DD; null)
 *     relogios[].compra.loja         onde comprou (null)
 *     relogios[].compra.valor        o valor pago (número; null)
 *     relogios[].de_hoje             verdadeiro ou falso: é o relógio de hoje
 *     relogios[].disponivel          verdadeiro ou falso: entra no rodízio
 *     relogios[].em_uso              verdadeiro ou falso: no pulso agora
 *     relogios[].foto                a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC), para o endereço dela); null: sem
 *                                    foto
 *     relogios[].id                  o número (código)
 *     relogios[].leitura             a última leitura com valor; null: nenhuma
 *     relogios[].leitura.inicio      quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].leitura.unidade     a unidade
 *     relogios[].leitura.valor       o valor
 *     relogios[].manutencao          o aviso mais perto; null: nenhum
 *     relogios[].manutencao.data     a data (texto AAAA-MM-DD; hoje se já passou)
 *     relogios[].manutencao.falta    quanto falta, por extenso ("10h 49min", "atrasado 2d")
 *     relogios[].manutencao.momento  o instante previsto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))
 *     relogios[].manutencao.nome     o aviso
 *     relogios[].nome                o nome
 *     relogios[].situacao[]          as frases de situação ("Autonomia restante 1d 23min")
 *     relogios[].tipo                o grupo do relógio (o nome)
 *     relogios[].ultimo              a última vez no pulso (número inteiro: instante Unix (segundos desde 01/01/1970 UTC); 0: nunca)
 *     relogios[].ultimo_txt          a mesma por extenso ("agora · 14:32", "25/09 21:53", "nunca")
 *     totais                         os totais da coleção inteira (a tabela da página Hoje soma só os relógios que os filtros dela deixam à
 *                                    mostra)
 *     totais.disponiveis             quantos estão disponíveis para o rodízio
 *     totais.em_uso                  quantos estão em uso agora
 *     totais.relogios                quantos relógios há
 *     totais.valor                   a soma do valor pago (valor_compra) de todos, em R$ (os sem valor contam 0)
 *     uso_inicio                     a hora em que o relógio do dia vai para o pulso (HH:MM)
 *
 *   recurso=ficha
 *     campos[]                       os campos do cadastro
 *     campos[].identificador         o campo
 *     campos[].no_id                 o grupo em que vale (0: todos)
 *     campos[].nome                  o nome
 *     campos[].opcoes[]              as opções (lista)
 *     campos[].padrao                o valor padrão
 *     campos[].tipo                  o tipo
 *     campos[].unidade               a unidade
 *     campos[].valor                 o valor do relógio (null)
 *     grupos[]                       os grupos (o seletor do cadastro)
 *     grupos[].cadeia[]              os ids do grupo e dos de cima
 *     grupos[].caminho               o caminho
 *     grupos[].id                    o número
 *     hoje                           hoje (texto AAAA-MM-DD)
 *     copia_sistema                  a cópia no banco pelo config.php (DOCUMENTOS_COPIA_BANCO), que vale sobre o relógio e o arquivo:
 *                                    verdadeiro, todo arquivo vai; falso, nenhum vai; null (sem a constante), cada relógio decide (a caixa
 *                                    do cadastro só aparece então)
 *     painel_modo                    como o relógio abre na página Hoje (a Configuração): lado (no painel à direita da lista, o padrão) ou
 *                                    flutuante (numa janela grande por cima da página); no celular, os dois cobrem a tela
 *     relogio                        o relógio; null: o cadastro de um relógio novo
 *     relogio.agora                  o estado por extenso
 *     relogio.autonomia              as autonomias do relógio (as mesmas do recurso autonomia)
 *     relogio.autonomia.acaba_em_datacomtz
 *                                    quando acaba, em ISO 8601 com o fuso (texto, ex.: 2026-10-02T18:40:00-03:00); null com o motivo
 *     relogio.autonomia.acaba_em_segundos
 *                                    quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em segundos; igual a
 *                                    autonomia_estimada); null com o motivo
 *     relogio.autonomia.acaba_em_unixtimestamp
 *                                    quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde 01/01/1970 UTC));
 *                                    null com o motivo
 *     relogio.autonomia.autonomia_atual
 *                                    quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no smartwatch, pelo
 *                                    gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo informado; nos outros, igual à
 *                                    prevista; a diferença para a prevista mostra a bateria envelhecendo; a fórmula autonomia_atual
 *     relogio.autonomia.autonomia_estimada
 *                                    quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em que está no
 *                                    rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em motivos
 *     relogio.autonomia.autonomia_prevista
 *                                    quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do smartwatch, a
 *                                    reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula autonomia_prevista; null se falta
 *                                    o dado no cadastro
 *     relogio.autonomia.calculado_em_unixtimestamp
 *                                    o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde 01/01/1970 UTC));
 *                                    segundos que faltam agora = acaba_em_unixtimestamp − hora atual
 *     relogio.autonomia.em_uso       verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora
 *     relogio.autonomia.energia      a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para calcular
 *     relogio.autonomia.motivos      por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)
 *     relogio.autonomia.motivos.<campo>
 *                                    o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a zero em até 10
 *                                    anos
 *     relogio.autonomia.restante_em_uso
 *                                    quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em segundos); null
 *                                    com o motivo (ex.: o automático no pulso se recarrega e não acaba)
 *     relogio.autonomia.restante_guardado
 *                                    quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em segundos);
 *                                    null com o motivo
 *     relogio.calculos[]             o resultado agora de cada fórmula que vale para o relógio
 *     relogio.calculos[].identificador
 *                                    a fórmula
 *     relogio.calculos[].nome        o nome da fórmula
 *     relogio.calculos[].unidade     a unidade do resultado (s: segundos)
 *     relogio.calculos[].valor       o resultado agora (número ou texto; null: vazio)
 *     relogio.calculos[].versao      o grupo da versão da fórmula usada
 *     relogio.caminho                o grupo por extenso
 *     relogio.carga                  a energia agora, em %; null sem dados
 *     relogio.carga_de               de onde vem a energia
 *     relogio.compra.data            a data da compra
 *     relogio.compra.garantia_ate    até quando vai a garantia
 *     relogio.compra.loja            onde comprou
 *     relogio.compra.valor           o valor pago
 *     relogio.dados[]                os campos do cadastro que valem para o relógio (os do grupo dele e dos de cima), com o valor que as contas usam
 *     relogio.dados[].identificador  o campo
 *     relogio.dados[].nome           o nome do campo
 *     relogio.dados[].origem         de onde vem o valor usado: informado, padrão (o do campo) ou vazio
 *     relogio.dados[].tipo           inteiro, decimal, sim_nao, data, lista ou texto
 *     relogio.dados[].unidade        a unidade (texto)
 *     relogio.dados[].valor          o valor informado no relógio (texto; null: não preenchido)
 *     relogio.dados[].valor_usado    o valor que as contas usam: o informado, senão o padrão do campo (null: nenhum)
 *     relogio.desde                  desde quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.disponivel             verdadeiro ou falso: entra no rodízio
 *     relogio.copia_banco            verdadeiro: o relógio pede a cópia no banco de todos os documentos dele (o segundo dos três níveis da
 *                                    cópia; o DOCUMENTOS_COPIA_BANCO do config.php, se definido, vale por cima)
 *     relogio.em_uso                 verdadeiro ou falso: no pulso agora
 *     relogio.escala_fim             até que dia vai a escala (null fora da escala)
 *     relogio.foto                   a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     relogio.id                     o número (código)
 *     relogio.leituras[]             as 40 últimas leituras com valor (o gráfico)
 *     relogio.leituras[].em_uso      verdadeiro ou falso: a leitura foi feita com o relógio no pulso
 *     relogio.leituras[].inicio      quando
 *     relogio.lancamentos_recentes[] os lançamentos dos últimos 14 dias e a sessão ainda aberta, do mais recente ao mais antigo (até 40): o que o quadro "Corrigir marcações" do painel mostra
 *     relogio.lancamentos_recentes[].fim quando a sessão terminou (texto AAAA-MM-DD HH:MM:SS); null: ainda aberta, ou um lançamento instantâneo
 *     relogio.lancamentos_recentes[].formato instantaneo, valor ou sessao
 *     relogio.lancamentos_recentes[].id o número do lançamento (para recurso=lancamento, acao=alterar ou excluir)
 *     relogio.lancamentos_recentes[].inicio quando foi (a sessão: quando começou), texto AAAA-MM-DD HH:MM:SS
 *     relogio.lancamentos_recentes[].nome o nome do tipo de lançamento
 *     relogio.lancamentos_recentes[].origem manual, rodizio (a sessão do dia) ou importado
 *     relogio.lancamentos_recentes[].tipo o identificador do tipo de lançamento (pulso, carga, corda...)
 *     relogio.lancamentos_recentes[].unidade a unidade do valor (texto; vazio sem valor)
 *     relogio.lancamentos_recentes[].valor o valor (número), num lançamento com valor; null nos outros
 *     relogio.leituras[].unidade     a unidade
 *     relogio.leituras[].valor       o valor
 *     relogio.linha_do_tempo[]       os trechos da linha do tempo do relógio, os 5 mais recentes: cada trecho é um período contínuo num estado, ou uma
 *                                    marcação (um lançamento instantâneo)
 *     relogio.linha_do_tempo[].duracao
 *                                    a mesma duração por extenso ("2d 3h", "40min"); null numa marcação
 *     relogio.linha_do_tempo[].duracao_seg
 *                                    quanto o trecho durou, número inteiro, em segundos; null numa marcação
 *     relogio.linha_do_tempo[].em_andamento
 *                                    verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)
 *     relogio.linha_do_tempo[].estado
 *                                    o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador de um tipo
 *                                    de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)
 *     relogio.linha_do_tempo[].fim   quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação
 *     relogio.linha_do_tempo[].inicio
 *                                    quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.linha_do_tempo[].texto o trecho por extenso, como a tela mostra ("em uso", "no sol", "Leitura de carga 80%")
 *     relogio.linha_do_tempo[].tipo  o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no repouso
 *     relogio.manutencoes[]          as 5 próximas manutenções (avisos)
 *     relogio.manutencoes[].data     a data (texto AAAA-MM-DD)
 *     relogio.manutencoes[].nome     o aviso
 *     relogio.medicao_janela_dias    a janela da média do gasto medido, em dias (a da Configuração)
 *     relogio.medicoes[]             as medições do gasto pelas leituras (cada leitura de um tipo que mede o gasto, comparada com a anterior), da mais
 *                                    recente para a mais antiga
 *     relogio.medicoes[].ate_valor   a leitura que fechou a medição (número)
 *     relogio.medicoes[].criado      quando a medição foi gravada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.medicoes[].de_valor    a leitura de antes (número, na unidade da leitura)
 *     relogio.medicoes[].fim         quando foi a leitura que fechou a medição (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.medicoes[].horas_guardado
 *                                    as horas guardado entre as duas leituras (número)
 *     relogio.medicoes[].horas_pulso as horas no pulso entre as duas leituras (número)
 *     relogio.medicoes[].id          o número da medição
 *     relogio.medicoes[].inicio      quando foi a leitura de antes (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.medicoes[].lancamento_id
 *                                    o lançamento (a leitura) que fechou a medição
 *     relogio.medicoes[].medida      o gasto do intervalo sozinho, no histórico: uso (o gasto por dia de uso, quando o intervalo teve
 *                                    meio dia de uso ou mais, ou mais dias de uso que fora) ou repouso (o gasto por dia fora do pulso);
 *                                    o gasto que vale sai de todas as medições juntas
 *     relogio.medicoes[].na_media    verdadeiro ou falso: entra na média agora (usada e dentro da janela da Configuração, medicao_janela_dias)
 *     relogio.medicoes[].peso_horas  o peso da medição na média: as horas que ela cobriu, no pulso (uso) ou guardado (repouso) (número)
 *     relogio.medicoes[].taxa        o gasto medido, em % por dia de uso (uso) ou por dia guardado (repouso) (número)
 *     relogio.medicoes[].usada       verdadeiro ou falso: a caixa "Atualizar o gasto com esta medição" estava marcada (entra na média); falso: só
 *                                    histórico
 *     relogio.no_id                  o grupo (0: na raiz)
 *     relogio.nome                   o nome
 *     relogio.nota                   a nota nos critérios; null: indisponível
 *     relogio.nota.conjunto_texto    de onde vêm os critérios
 *     relogio.nota.nota              a nota, de 0 a 100
 *     relogio.observacao             a observação do cadastro (null)
 *     relogio.previsao               a previsão da energia (só nos relógios com leitura, como o smartwatch): as frases da tela e os mesmos números
 *     relogio.previsao.aplica        verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)
 *     relogio.previsao.carga_na_entrada
 *                                    com quanto ele entra nesse dia, em % (número)
 *     relogio.previsao.carregar_antes
 *                                    verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)
 *     relogio.previsao.chega_limite_em
 *                                    quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa de um ano
 *     relogio.previsao.confianca     a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media ou baixa
 *                                    (leitura com mais de 7 dias)
 *     relogio.previsao.conta[]       de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras
 *     relogio.previsao.conta[].campo o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     relogio.previsao.conta[].nome  o nome do campo (ou do gasto medido, com quantas medições entraram)
 *     relogio.previsao.conta[].origem
 *                                    de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido (pelas
 *                                    leituras)
 *     relogio.previsao.conta[].unidade
 *                                    a unidade do valor (texto)
 *     relogio.previsao.conta[].valor o valor usado na conta (número; null se vazio)
 *     relogio.previsao.dura_ate      até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)
 *     relogio.previsao.dura_dias     quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)
 *     relogio.previsao.energia       a energia agora, em % (número; null sem leitura)
 *     relogio.previsao.gasto         os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as tem)
 *     relogio.previsao.gasto.conjunta
 *                                    true: os dois gastos saem juntos da conta das medições; false: a média das medições de cada um
 *     relogio.previsao.gasto.janela_dias
 *                                    a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)
 *     relogio.previsao.gasto.medicoes
 *                                    quantas medições há na janela (número)
 *     relogio.previsao.gasto.medicoes_antes
 *                                    quantas medições há na janela anterior, a dos antes (número)
 *     relogio.previsao.gasto.uso.antes
 *                                    o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)
 *     relogio.previsao.gasto.uso.cadastro
 *                                    o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)
 *     relogio.previsao.gasto.uso.medido
 *                                    o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null sem medição)
 *     relogio.previsao.gasto.uso.vale
 *                                    o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)
 *     relogio.previsao.gasto.repouso.antes
 *                                    o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)
 *     relogio.previsao.gasto.repouso.cadastro
 *                                    o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)
 *     relogio.previsao.gasto.repouso.medido
 *                                    o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null sem medição)
 *     relogio.previsao.gasto.repouso.vale
 *                                    o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)
 *     relogio.previsao.leitura       a última leitura, no valor informado (número)
 *     relogio.previsao.leitura_em    quando foi a última leitura (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.previsao.limite        o limite de carga da Configuração (previsao_limite), em % (número)
 *     relogio.previsao.linhas[]      as frases da previsão, como a tela mostra (texto)
 *     relogio.previsao.precisa       quanto ele precisa ter na entrada para os dias seguidos no pulso, em % (número)
 *     relogio.previsao.proxima_entrada
 *                                    o próximo dia em que o relógio entra no rodízio (texto AAAA-MM-DD); null se não está no plano
 *     relogio.proxima                o próximo dia no plano (texto AAAA-MM-DD); null: não está no plano
 *     relogio.proxima_ate            até quando ele fica nesse dia
 *     relogio.registros              quantos registros tem a linha do tempo inteira
 *     relogio.situacao[]             as frases de situação
 *     relogio.tipo                   o nome do grupo
 *     relogio.tipos[]                os tipos de lançamento do relógio (os botões de Lançar)
 *     relogio.tipos[].aberta         a sessão aberta desse tipo agora; null: nenhuma
 *     relogio.tipos[].aberta.inicio  desde quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.tipos[].aberta.rodizio verdadeiro ou falso: é a sessão do rodízio
 *     relogio.tipos[].aberta.texto   por extenso ("desde 07:00 (3 h 12 min)")
 *     relogio.tipos[].formato        instantaneo, valor ou sessao
 *     relogio.tipos[].identificador  o tipo
 *     relogio.tipos[].mede_gasto     verdadeiro ou falso: a leitura mede o gasto (a caixa aparece)
 *     relogio.tipos[].nome           o nome
 *     relogio.tipos[].unidade        a unidade do valor
 *
 *     relogio.documentos             os documentos do relógio, resumidos (a lista: recurso=documentos&relogio=id)
 *     relogio.documentos.categorias[]
 *                                    as categorias em que ele tem documentos, na ordem
 *     relogio.documentos.categorias[].documentos
 *                                    quantos documentos ele tem nela
 *     relogio.documentos.categorias[].id
 *                                    o número da categoria (para documentos.php?relogio=...&cat=...)
 *     relogio.documentos.categorias[].nome
 *                                    o nome da categoria
 *     relogio.documentos.pasta_ok    verdadeiro: a pasta dos documentos (DOCUMENTOS_PASTA do config.php) está pronta para receber arquivos
 *     relogio.documentos.copia_sistema
 *                                    a cópia no banco pelo config.php (DOCUMENTOS_COPIA_BANCO), que vale sobre o relógio e o arquivo:
 *                                    verdadeiro, todo arquivo vai; falso, nenhum vai; null (sem a constante), cada relógio e cada arquivo
 *                                    decidem
 *     relogio.documentos.total       quantos documentos ele tem
 *   recurso=config
 *     ancoras.<ancora>               cada âncora que as mensagens aceitam ({relogio}, {acao}...) e o que ela vira
 *     canais.<canal>.ajuda           a explicação do canal
 *     canais.<canal>.nome            o nome do canal (Telegram, Google Agenda)
 *     canais.<canal>.tipos           o nome do campo do formulário (acao=salvar) com os tipos que vão pelo canal (alerta_tipos[], agenda_tipos[])
 *     chave_agenda                   a chave do Google lida no caminho configurado; null: não lida
 *     chave_agenda.client_email      o e-mail da conta de serviço (compartilhar a agenda com ele)
 *     cron_estado                    a situação do cron, como a Configuração mostra (o cron roda a cada minuto)
 *     cron_estado.crontab            a linha do crontab que roda o sistema (com a pasta dele)
 *     cron_estado.erro               o erro guardado da última execução com erro (null: sem erro; some na primeira execução sem erro)
 *     cron_estado.minutos            há quantos minutos ele rodou pela última vez (null: nunca rodou)
 *     cron_estado.situacao           nunca (ainda não rodou), parado (mais de 10 minutos sem rodar) ou rodando
 *     cron_estado.texto              a frase da Configuração ("Cron rodando: última execução às 10:41."; parado e nunca trazem a linha do
 *                                    crontab)
 *     cron_estado.ultima             quando rodou pela última vez (AAAA-MM-DD HH:MM:SS, no fuso do sistema; null: nunca rodou)
 *     config.ag_padrao               a mensagem padrão da agenda: a primeira linha é o título do evento, o resto a descrição
 *     config.agenda_antecedencia     com quantos dias de antecedência os eventos são criados na agenda
 *     config.agenda_ativa            1: cria os eventos no Google Agenda; 0: não
 *     config.agenda_chave            o caminho da chave JSON da conta de serviço do Google, no servidor
 *     config.agenda_id               o id da agenda do Google
 *     config.agenda_teste_id         o id do evento de teste na agenda (vazio: nenhum)
 *     config.carga_limiar            o limite de carga geral (%): carregar quando a carga chega a ele (o campo carga_minima do relógio, se preenchido, vale no lugar)
 *     config.cron_erro               o erro guardado da última execução com erro (texto; vazio: sem erro)
 *     config.cron_registro           o registro da última rodada com atividade (texto)
 *     config.cron_ultima_execucao    quando o cron rodou pela última vez (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     config.escala_fim              até que dia vai a escala inteligente gerada (texto AAAA-MM-DD; vazio fora da escala)
 *     config.escala_gerada           o dia em que a escala foi gerada pela última vez (texto AAAA-MM-DD)
 *     config.horario_manha           a hora da rodada da manhã do cron: o relógio do dia e os avisos (HH:MM)
 *     config.horario_noite           a hora da rodada da noite: preparar o relógio de amanhã (HH:MM)
 *     config.max_sem_uso             a garantia de rodízio: nenhum relógio passa desses dias sem uso (0 desliga)
 *     config.medicao_janela_dias     a média do gasto medido pelas leituras usa as medições destes últimos dias (sem nenhuma na janela, a última)
 *     config.mensagens_ativas        1: o Telegram (a API de alerta) envia; 0: não envia
 *     config.painel_modo             como o relógio abre na página Hoje: lado (no painel à direita da lista) ou flutuante (numa janela
 *                                    grande por cima da página); vazio: lado
 *     config.migracao_v10            marca de que a migração v10 foi aplicada (1)
 *     config.migracao_v12            marca de que a migração v12 foi aplicada (1)
 *     config.migracao_v16            marca de que a migração v16 foi aplicada (1)
 *     config.migracao_v17            marca de que a migração v17 foi aplicada (1)
 *     config.pulso_auto_fim          1 (ou vazio): o relógio sai do pulso sozinho no fim do horário de uso (o "fecha às" do tipo No pulso); 0: só pelo Tirou
 *     config.pulso_auto_inicio       1 (ou vazio): o relógio do dia entra no pulso sozinho no início do horário de uso; 0: só pelo Pôs
 *     config.previsao_limite         o limite de carga da previsão do smartwatch, em %
 *     config.sol_limiar              no solar, a carga (%) em que ele deve ir para o sol
 *     config.tg_padrao               a mensagem padrão do Telegram, com âncoras ({acao}, {relogio}, {motivo}...)
 *     config.ultima_manha            o dia da última rodada da manhã do cron (texto AAAA-MM-DD)
 *     config.ultima_noite            o dia da última rodada da noite do cron (texto AAAA-MM-DD)
 *     config.url_sistema             o endereço do sistema, para a âncora {link}
 *     config.uso_fim                 a hora em que ele sai do pulso (HH:MM)
 *     config.uso_inicio              a hora em que o relógio do dia vai para o pulso (HH:MM)
 *     eventos[]                      os eventos personalizados (avisos seus, com horário e repetição próprios)
 *     eventos[].agenda               verdadeiro ou falso: o evento vai para o Google Agenda
 *     eventos[].ativo                verdadeiro ou falso: o evento dispara
 *     eventos[].criado               quando o evento foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].data_inicio          a data (uma vez só) ou o começo da contagem (a cada N dias) (texto AAAA-MM-DD); null nas outras
 *     eventos[].descricao            a repetição por extenso ("seg, qua 20:00")
 *     eventos[].dia_mes              o dia do mês (mensal), de 1 a 31; null nas outras
 *     eventos[].dias_semana          os dias da semana (semanal), de 1 (segunda) a 7 (domingo), separados por vírgula; null nas outras
 *     eventos[].disparos[]           as vezes em que já disparou
 *     eventos[].disparos[].disparado quando o cron mandou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].disparos[].ocorrencia
 *                                    a vez que disparou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema, a do cadastro)
 *     eventos[].hora                 a hora do disparo (HH:MM)
 *     eventos[].id                   o número do evento (o tipo dele nos canais é ev<id>)
 *     eventos[].intervalo_dias       de quantos em quantos dias (intervalo); null nas outras
 *     eventos[].nome                 o nome do evento (texto)
 *     eventos[].proximas[]           as 10 próximas vezes em que dispara (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].proximas_60[]        as 5 próximas vezes nos próximos 60 dias
 *     eventos[].relogio              o nome desse relógio; null: evento geral
 *     eventos[].relogio_id           o relógio do evento (as âncoras dele funcionam na mensagem); null: evento geral
 *     eventos[].repeticao            quando dispara: uma (uma vez só), diaria, semanal, mensal ou intervalo (a cada N dias)
 *     eventos[].telegram             verdadeiro ou falso: o evento vai pelo Telegram (marcado em "O que vai para onde")
 *     pasta                          a pasta do sistema no servidor (para a linha do crontab)
 *     previa.agenda[]                os 8 primeiros eventos da agenda
 *     previa.agenda[].data           o dia
 *     previa.agenda[].descricao      a descrição
 *     previa.agenda[].hora           a hora
 *     previa.agenda[].titulo         o título
 *     previa.manha                   a mensagem da manhã do Telegram como sairia agora (texto)
 *     previa.noite                   a mensagem da noite como sairia agora (texto; vazio: nada a enviar)
 *     previa.sincronizados           quantos eventos o sistema tem na agenda
 *     relogios[]                     os relógios (a lista do evento)
 *     relogios[].disponivel          verdadeiro ou falso
 *     relogios[].id                  o número
 *     relogios[].nome                o nome
 *     repeticoes.<repeticao>         cada repetição de evento e o nome dela
 *     sol_fim                        a hora em que a sessão no sol esquecida aberta fecha (HH:MM)
 *     tipos[]                        os tipos de aviso ("O que vai para onde"): o dia, a véspera, os avisos cadastrados e os eventos
 *     tipos[].canais.<canal>.corpo   a mensagem personalizada (texto)
 *     tipos[].canais.<canal>.marcado verdadeiro ou falso: o tipo vai por esse canal
 *     tipos[].canais.<canal>.proprio verdadeiro ou falso: tem mensagem personalizada nesse canal
 *     tipos[].evento                 o id do evento personalizado; null nos outros
 *     tipos[].nome                   o nome
 *     tipos[].quando                 manhã ou noite
 *     tipos[].tipo                   o tipo (dia, vespera, identificador, ev<id>)
 *
 *   recurso=cron
 *     execucoes[]                    as execuções do cron guardadas (sem atividade: 7 dias; com atividade ou erro: 1 ano), da mais recente
 *     execucoes[].duracao_ms         quanto durou, em milissegundos (número inteiro)
 *     execucoes[].fim                quando terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     execucoes[].id                 o número da execução
 *     execucoes[].inicio             quando começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     execucoes[].registro           o que foi feito, linha por linha (texto; vazio: nada a fazer)
 *     execucoes[].teve_atividade     verdadeiro ou falso: fez alguma coisa (rodada, plano, evento, sincronização)
 *     execucoes[].teve_erro          verdadeiro ou falso: houve erro
 *     filtro.ate                     o fim do período
 *     filtro.busca                   o texto pedido
 *     filtro.de                      o começo do período pedido (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     filtro.situacao                a situação pedida (atividade, erro, nada, todas)
 *     pagina                         a página
 *     paginas                        quantas páginas
 *     por_pagina                     quantas por página
 *     resumo.atividade               quantas com atividade
 *     resumo.erros                   quantas com erro
 *     resumo.mais_lenta_ms           a mais lenta, em milissegundos
 *     resumo.total                   quantas execuções no período
 *     total                          quantas passaram nos filtros
 *     ultima                         quando o cron rodou pela última vez
 *
 *   recurso=arvore
 *     grupos[]                       os grupos em ordem de árvore
 *     grupos[].cadeia[]              os ids do grupo e dos de cima
 *     grupos[].caminho               o caminho
 *     grupos[].criterios             quantos parâmetros de critérios próprios ele tem
 *     grupos[].id                    o número
 *     grupos[].nome                  o nome
 *     grupos[].pai_id                o grupo de cima; null: raiz
 *     grupos[].profundidade          o nível
 *     grupos[].relogios[]            os nomes dos relógios direto nele
 *     grupos[].subgrupos             quantos grupos direto dentro dele
 *     relogios[]                     o grupo de cada relógio
 *     relogios[].disponivel          verdadeiro ou falso
 *     relogios[].id                  o número
 *     relogios[].no_id               o grupo (0: raiz)
 *     relogios[].nome                o nome
 *
 *   recurso=cadastros
 *     avisos[]                       os avisos cadastrados (cada identificador pode ter uma versão por grupo)
 *     avisos[].agenda                a data na agenda: janela (só dentro da antecedência da agenda) ou sempre
 *     avisos[].antecedencia_dias     com quantos dias antes ele entra "em breve" (número)
 *     avisos[].ativo                 o aviso vale
 *     avisos[].condicao              vale quando: uma fórmula que diz para quais relógios do grupo o aviso vale (1 vale, 0 não; ex.: corda_manual = 0,
 *                                    só o automático sem corda); null: vale para todos
 *     avisos[].escala                na escala inteligente: nao, uso ou sempre
 *     avisos[].expressao             a fórmula da data prevista
 *     avisos[].id                    o número da versão
 *     avisos[].identificador         o aviso (o tipo dele nos canais)
 *     avisos[].no_id                 o grupo desta versão; null: todos
 *     avisos[].nome                  o nome do aviso — a âncora {acao}
 *     avisos[].resolve               o tipo de lançamento que resolve (o botão na tela Hoje); null: nenhum
 *     avisos[].resolve_tipo_id       o número desse tipo de lançamento (a chave estrangeira no banco); null: nenhum
 *     avisos[].simula_horas          as horas da sessão que a escala simula para resolver (número; null)
 *     avisos[].simula_valor          o valor do lançamento que a escala simula para resolver (número; null)
 *     avisos[].texto                 o motivo, com {relogio}, {data}, {quando} e {limite} — a âncora {motivo}
 *     campos[]                       os campos do cadastro dos relógios (cada um vale para o grupo dele e tudo abaixo)
 *     campos[].id                    o número do campo
 *     campos[].identificador         o nome do campo nas fórmulas e nos critérios
 *     campos[].no_id                 o grupo em que o campo vale; null: todos os relógios
 *     campos[].nome                  o nome do campo
 *     campos[].opcoes                as opções de um campo de lista, uma por linha (texto; vazio nos outros)
 *     campos[].ordem                 a posição do campo no cadastro
 *     campos[].padrao                o valor usado quando o relógio não tem o campo preenchido (texto; null: nenhum)
 *     campos[].tipo                  inteiro, decimal, sim_nao, data, lista ou texto
 *     campos[].unidade               a unidade (texto; vazio: nenhuma)
 *     formatos_de_lancamento.<formato>
 *                                    cada formato de lançamento e o nome
 *     formulas[]                     as fórmulas (cada identificador pode ter uma versão por grupo; vale a do grupo mais perto do relógio)
 *     formulas[].expressao           a conta (texto, na escrita das fórmulas do motor)
 *     formulas[].id                  o número da versão
 *     formulas[].identificador       o nome da fórmula nas outras fórmulas e nos critérios
 *     formulas[].no_id               o grupo desta versão; null: todos
 *     formulas[].nome                o nome da fórmula
 *     formulas[].unidade             a unidade do resultado (texto)
 *     funcoes.<funcao>               cada função do motor e como usar
 *     grupos[]                       os grupos
 *     grupos[].caminho               o caminho
 *     grupos[].id                    o número
 *     lancamento_tipos[]             os tipos de lançamento: o que se registra num relógio
 *     lancamento_tipos[].condicao    vale quando: uma fórmula que diz para quais relógios do grupo o tipo vale (1 vale, 0 não; ex.: corda_manual, só
 *                                    quem aceita corda); null: vale para todos
 *     lancamento_tipos[].exclusiva   a sessão é exclusiva: o relógio fica num lugar só (abrir fecha a outra exclusiva)
 *     lancamento_tipos[].fecha_as    sessão esquecida aberta fecha sozinha a essa hora do dia em que começou (HH:MM; null: não fecha)
 *     lancamento_tipos[].formato     instantaneo (uma marcação: corda), valor (uma leitura: carga) ou sessao (com início e fim: pulso, sol)
 *     lancamento_tipos[].id          o número do tipo
 *     lancamento_tipos[].identificador
 *                                    o nome do tipo nas fórmulas (HORAS("pulso"; 30))
 *     lancamento_tipos[].lancamentos quantos lançamentos desse tipo existem (número)
 *     lancamento_tipos[].mede_gasto  cada leitura desse tipo mede o gasto, comparando com a anterior (a função MEDIDO)
 *     lancamento_tipos[].no_id       o grupo em que o tipo vale; null: todos
 *     lancamento_tipos[].nome        o nome do tipo
 *     lancamento_tipos[].ordem       a posição do tipo
 *     lancamento_tipos[].unidade     a unidade do valor (texto)
 *     max_sem_uso                    a garantia de rodízio em dias
 *     modo_ativo                     o id do modo em uso
 *     modos[]                        os modos (como no banco)
 *     modos[].blocos[]               os blocos
 *     modos[].ativo                  1: o modo em uso (só um); 0: não
 *     modos[].blocos[].dias          os dias (1 a 7) separados por vírgula (no banco, uma linha por dia na tabela modo_bloco_dia)
 *     modos[].blocos[].id            o número
 *     modos[].blocos[].modo_id       o modo
 *     modos[].blocos[].no_id         o grupo de onde sortear; null: todos
 *     modos[].blocos[].nome          o nome
 *     modos[].blocos[].ordem         a posição
 *     modos[].blocos[].relogio_id    o relógio fixo; null
 *     modos[].blocos[].um_por        dia ou bloco
 *     modos[].ciclo                  1: usa o ciclo (um relógio só volta depois que todos do bloco passaram); 0: não
 *     modos[].escala_dias            o horizonte da escala; null
 *     modos[].id                     o número
 *     modos[].nome                   o nome
 *     modos[].ordem                  a posição
 *     modos[].selecao                a forma de escolha
 *     relogios[]                     os relógios
 *     relogios[].id                  o número
 *     relogios[].nome                o nome
 *     tipos_de_campo.<tipo_de_campo> cada tipo de campo e o nome
 *
 *     documento_categorias[]         as categorias dos documentos, na ordem
 *     documento_categorias[].aceita[]
 *                                    os tipos de arquivo que ela aceita (imagem, video, audio, pdf, xml); lista vazia: qualquer arquivo
 *     documento_categorias[].documentos
 *                                    quantos documentos ela tem
 *     documento_categorias[].id      o número da categoria
 *     documento_categorias[].identificador
 *                                    o identificador (minúsculas, números e _)
 *     documento_categorias[].nome    o nome
 *     documento_categorias[].ordem   a ordem em que ela aparece (número)
 *   recurso=calcular
 *     expressao                      a fórmula calculada
 *     resultados[]                   o resultado em cada relógio
 *     resultados[].partes.<parte>    cada parte da conta (campo, fórmula ou função) e o valor que teve
 *     resultados[].relogio           o nome
 *     resultados[].relogio_id        o relógio
 *     resultados[].valor             o resultado (número ou texto; null: vazio)
 *
 *   recurso=avisos
 *     avisos[]                       os avisos de todos os relógios agora, do mais urgente ao mais distante
 *     avisos[].agenda                a data na agenda: janela (só dentro da antecedência da agenda) ou sempre (qualquer data); se vai pela agenda é a
 *                                    Configuração
 *     avisos[].data                  a data prevista, pela fórmula do aviso (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     avisos[].disponivel            verdadeiro ou falso: o relógio entra no rodízio
 *     avisos[].escala                na escala inteligente: nao, uso (conferido no relógio do dia) ou sempre (também nos guardados)
 *     avisos[].estado                atrasado (a data passou), em_breve (dentro da antecedência) ou ok
 *     avisos[].falta_dias            quanto falta para a data prevista, em dias (número; negativo: já passou)
 *     avisos[].identificador         o aviso (corda, carregar, sol, pilha, revisao, garantia, carga_baixa, leitura, ou um que você cadastrou)
 *     avisos[].modelo                o texto do cadastro, sem trocar as âncoras (só o {limite})
 *     avisos[].nome                  o nome do aviso ("Dar corda") — a âncora {acao}
 *     avisos[].relogio               o nome
 *     avisos[].relogio_id            o relógio
 *     avisos[].resolve               o tipo de lançamento que resolve o aviso (o botão na tela Hoje); null: nenhum
 *     avisos[].simula_horas          as horas da sessão que a escala simula para resolver o aviso (número; null: nenhuma)
 *     avisos[].simula_valor          o valor do lançamento que a escala simula para resolver o aviso (leitura) (número; null: nenhum)
 *     avisos[].texto                 o motivo por extenso, com {relogio}, {data}, {quando} e {limite} trocados — a âncora {motivo}
 *     avisos[].versao                o lugar da árvore da versão do aviso usada (texto)
 *
 *   recurso=criterios
 *     conjuntos[]                    os conjuntos de critérios, um por lugar que tem critérios próprios
 *     conjuntos[].escopo             o lugar: "" (todos os relógios), g:<grupo> ou r:<relógio>
 *     conjuntos[].lugar              o lugar por extenso
 *     conjuntos[].parametros[]       os parâmetros do conjunto, em ordem
 *     conjuntos[].parametros[].escopo_no_id
 *                                    o grupo do conjunto; null se não é de grupo
 *     conjuntos[].parametros[].escopo_relogio_id
 *                                    o relógio do conjunto; null se não é de relógio
 *     conjuntos[].parametros[].id    o número do parâmetro
 *     conjuntos[].parametros[].nome  o nome do parâmetro
 *     conjuntos[].parametros[].ordem a posição dele no conjunto
 *     conjuntos[].parametros[].peso  o peso no conjunto, em % (os do conjunto somam 100)
 *     conjuntos[].parametros[].subparametros[]
 *                                    os subparâmetros, em ordem
 *     conjuntos[].parametros[].subparametros[].faixas[]
 *                                    as faixas: o valor medido vira uma nota de 0 a 100
 *     conjuntos[].parametros[].subparametros[].faixas[].ate
 *                                    o fim da faixa (não entra, menos na última); null: sem limite
 *     conjuntos[].parametros[].subparametros[].faixas[].categoria
 *                                    a categoria (medida de lista); null numa faixa de números
 *     conjuntos[].parametros[].subparametros[].faixas[].de
 *                                    o começo da faixa (entra); null numa faixa de categoria
 *     conjuntos[].parametros[].subparametros[].faixas[].id
 *                                    o número da faixa
 *     conjuntos[].parametros[].subparametros[].faixas[].nota
 *                                    a nota da faixa, de 0 a 100
 *     conjuntos[].parametros[].subparametros[].id
 *                                    o número do subparâmetro
 *     conjuntos[].parametros[].subparametros[].nome
 *                                    o nome do subparâmetro
 *     conjuntos[].parametros[].subparametros[].ordem
 *                                    a posição dele no parâmetro
 *     conjuntos[].parametros[].subparametros[].peso
 *                                    o peso no parâmetro, em % (os do parâmetro somam 100)
 *     conjuntos[].parametros[].subparametros[].peso_efetivo_no_conjunto
 *                                    peso do subparâmetro × peso do parâmetro ÷ 100, em %
 *     conjuntos[].parametros[].subparametros[].variavel
 *                                    o que ele mede: o identificador de um campo ou de uma fórmula
 *     conjuntos[].usado_por[]        os relógios disponíveis que usam este conjunto (ids)
 *     lugares[]                      os lugares que podem ter critérios: todos os relógios, cada grupo (em ordem de árvore) e cada relógio
 *     lugares[].escopo               o lugar: "" (todos), g:<grupo> ou r:<relógio>
 *     lugares[].herda                o lugar de quem ele herda os critérios quando não tem os seus; null: ninguém (nota neutra)
 *     lugares[].herda_texto          esse lugar por extenso
 *     lugares[].lugar                o lugar por extenso, curto
 *     lugares[].proprio              verdadeiro ou falso: o lugar tem critérios próprios
 *     lugares[].texto                o lugar como a lista da página mostra ("Grupo: Tradicional › Mecânico")
 *     max_sem_uso                    a garantia de rodízio em dias
 *     modos[]                        os modos de rodízio e a forma de escolher de cada um
 *     modos[].ativo                  verdadeiro ou falso: é o modo em uso
 *     modos[].escala                 verdadeiro ou falso: o modo é escala inteligente (escolhe sempre pela maior nota)
 *     modos[].nome                   o nome do modo
 *     modos[].selecao                a forma de escolha: inteligente (a maior nota), ponderado (sorteio pela nota), aleatorio ou fifo (o mais tempo sem
 *                                    uso)
 *     notas[]                        a nota de cada relógio agora, com a conta
 *     notas[].conjunto               o lugar dos critérios usados: "" (todos), g:<grupo> ou r:<relógio>; null: nenhum (nota neutra, 50)
 *     notas[].conjunto_texto         esse lugar por extenso
 *     notas[].conta[]                cada subparâmetro que entrou na conta
 *     notas[].conta[].faixa          a faixa em que o valor caiu ("30 a 50", ou a categoria)
 *     notas[].conta[].nota           a nota da faixa, de 0 a 100 (número)
 *     notas[].conta[].parametro      o nome do parâmetro
 *     notas[].conta[].peso_efetivo   o peso no conjunto: peso do subparâmetro × peso do parâmetro, reescalado sem os que não se aplicam, em % (número)
 *     notas[].conta[].pontos         nota × peso efetivo ÷ 100 (número): quanto somou na nota
 *     notas[].conta[].subparametro   o nome do subparâmetro
 *     notas[].conta[].valor          o valor medido (número, ou o texto de uma lista)
 *     notas[].conta[].variavel       o que o subparâmetro mede (o identificador do campo ou da fórmula)
 *     notas[].disponivel             verdadeiro ou falso: o relógio está disponível para o rodízio
 *     notas[].grupo                  o grupo do relógio (o caminho na árvore)
 *     notas[].nota                   a nota final, de 0 a 100 (número): a soma dos pontos
 *     notas[].relogio                o nome do relógio
 *     notas[].relogio_id             o relógio
 *     variaveis.<variavel>           o que um subparâmetro pode medir: cada campo (menos texto) e cada fórmula, pelo identificador
 *     variaveis.<variavel>.max       o limite da medida (a última faixa vai até ele ou sem limite): o que o nome diz em "(0 a N)"; null: sem limite
 *     variaveis.<variavel>.nome      o nome (com a unidade)
 *     variaveis.<variavel>.tipo      numero ou categoria (campo de lista)
 *     variaveis.<variavel>.valores[] as categorias possíveis (campo de lista); vazio nos números
 *
 *   recurso=historico
 *     estados.<estado>               cada estado possível da linha do tempo e o nome dele
 *     filtro.ate                     o fim pedido (null)
 *     filtro.de                      o começo pedido (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema; null)
 *     filtro.estados[]               os estados pedidos
 *     filtro.ignorados[]             o que foi pedido e não existe
 *     filtro.ordem                   desc ou asc
 *     filtro.relogios[]              os relógios pedidos
 *     lancamentos[]                  os lançamentos crus do período
 *     lancamentos[].fim              o fim (null)
 *     lancamentos[].id               o número
 *     lancamentos[].inicio           quando
 *     lancamentos[].origem           manual, rodizio ou importado
 *     lancamentos[].relogio          o nome
 *     lancamentos[].relogio_id       o relógio
 *     lancamentos[].tipo             o tipo
 *     lancamentos[].valor            o valor (null)
 *     linhas[]                       os trechos da linha do tempo do relógio, do mais recente para o mais antigo: cada trecho é um período contínuo num
 *                                    estado, ou uma marcação (um lançamento instantâneo)
 *     linhas[].duracao               a mesma duração por extenso ("2d 3h", "40min"); null numa marcação
 *     linhas[].duracao_seg           quanto o trecho durou, número inteiro, em segundos; null numa marcação
 *     linhas[].em_andamento          verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)
 *     linhas[].estado                o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador de um tipo
 *                                    de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)
 *     linhas[].fim                   quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação
 *     linhas[].inicio                quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     linhas[].relogio               o nome
 *     linhas[].relogio_id            o relógio
 *     linhas[].texto                 o trecho por extenso, como a tela mostra ("em uso", "no sol", "Leitura de carga 80%")
 *     linhas[].tipo                  o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no repouso
 *     pagina                         a página
 *     paginas                        quantas páginas
 *     por_pagina                     quantas por página
 *     resumo.<relogio_id>.desde      desde quando
 *     resumo.<relogio_id>.foto       a versão da foto; null
 *     resumo.<relogio_id>.marcacoes.<tipo_de_marcacao>
 *                                    quantas marcações de cada tipo no período
 *     resumo.<relogio_id>.registros  quantos registros ele tem no total
 *     resumo.<relogio_id>.relogio    o nome do relógio
 *     resumo.<relogio_id>.tempo.<estado>.porcentagem
 *                                    a parte do tempo, em %
 *     resumo.<relogio_id>.tempo.<estado>.segundos
 *                                    o tempo no estado, no período pedido, número inteiro, em segundos
 *     resumo.<relogio_id>.tempo.<estado>.texto
 *                                    por extenso
 *     resumo.<relogio_id>.tipo       o grupo
 *     total                          quantas linhas passaram nos filtros
 *
 *   recurso=previsao
 *     previsoes[]                    a previsão de cada relógio com leitura
 *     previsoes[].aplica             verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)
 *     previsoes[].carga_na_entrada   com quanto ele entra nesse dia, em % (número)
 *     previsoes[].carregar_antes     verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)
 *     previsoes[].chega_limite_em    quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa de um ano
 *     previsoes[].confianca          a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media ou baixa
 *                                    (leitura com mais de 7 dias)
 *     previsoes[].conta[]            de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras
 *     previsoes[].conta[].campo      o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     previsoes[].conta[].nome       o nome do campo (ou do gasto medido, com quantas medições entraram)
 *     previsoes[].conta[].origem     de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido (pelas
 *                                    leituras)
 *     previsoes[].conta[].unidade    a unidade do valor (texto)
 *     previsoes[].conta[].valor      o valor usado na conta (número; null se vazio)
 *     previsoes[].dura_ate           até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)
 *     previsoes[].dura_dias          quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)
 *     previsoes[].energia            a energia agora, em % (número; null sem leitura)
 *     previsoes[].gasto              os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as tem)
 *     previsoes[].gasto.conjunta     true: os dois gastos saem juntos da conta das medições; false: a média das medições de cada um
 *     previsoes[].gasto.janela_dias  a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)
 *     previsoes[].gasto.medicoes     quantas medições há na janela (número)
 *     previsoes[].gasto.medicoes_antes
 *                                    quantas medições há na janela anterior, a dos antes (número)
 *     previsoes[].gasto.uso.antes    o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)
 *     previsoes[].gasto.uso.cadastro o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)
 *     previsoes[].gasto.uso.medido   o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null sem medição)
 *     previsoes[].gasto.uso.vale     o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)
 *     previsoes[].gasto.repouso.antes
 *                                    o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)
 *     previsoes[].gasto.repouso.cadastro
 *                                    o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)
 *     previsoes[].gasto.repouso.medido
 *                                    o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null sem medição)
 *     previsoes[].gasto.repouso.vale o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)
 *     previsoes[].leitura            a última leitura, no valor informado (número)
 *     previsoes[].leitura_em         quando foi a última leitura (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     previsoes[].limite             o limite de carga da Configuração (previsao_limite), em % (número)
 *     previsoes[].linhas[]           as frases da previsão, como a tela mostra (texto)
 *     previsoes[].precisa            quanto ele precisa ter na entrada para os dias seguidos no pulso, em % (número)
 *     previsoes[].proxima_entrada    o próximo dia em que o relógio entra no rodízio (texto AAAA-MM-DD); null se não está no plano
 *     previsoes[].relogio            o nome
 *     previsoes[].relogio_id         o relógio
 *
 *   recurso=plano
 *     escala_fim                     até que dia vai a escala (null fora da escala)
 *     hoje                           hoje (texto AAAA-MM-DD)
 *     modo                           o nome do modo em uso
 *     plano[]                        o plano gravado: o relógio de cada dia
 *     plano[].acao                   o lembrete do dia (o que fazer antes: carregar, dar corda...); null: nada
 *     plano[].bloco                  o nome desse bloco
 *     plano[].bloco_id               o bloco do modo que escolheu o dia; null: escala ou manual
 *     plano[].criado                 quando o dia foi gravado no plano (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     plano[].data                   o dia (texto AAAA-MM-DD)
 *     plano[].motivo                 por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null: o dia foi gravado antes de o sistema guardar o motivo
 *     plano[].origem                 sorteio (pelo modo) ou manual (escolhido à mão: "Usando hoje" ou trocar_dia)
 *     plano[].relogio                o nome dele
 *     plano[].relogio_id             o relógio do dia
 *     plano_fim                      o último dia gravado no plano (AAAA-MM-DD; null: plano vazio), qualquer que seja o período pedido
 *     resumo                         o resumo do período (os dias da resposta de hoje em diante), como a página Plano mostra
 *     resumo.ate                     o último dia contado (null: nenhum)
 *     resumo.de                      o primeiro dia contado (null: nenhum)
 *     resumo.dias                    quantos dias o período tem no plano
 *     resumo.relogios[]              cada relógio com dia no período, do que tem mais dias ao que tem menos (no empate, pelo nome)
 *     resumo.relogios[].a_mao        quantos desses dias foram escolhidos à mão (o trocar por… ou o estou usando)
 *     resumo.relogios[].dias         quantos dias ele tem no período
 *     resumo.relogios[].porcentagem  a parte do período, em % (uma casa)
 *     resumo.relogios[].proximo      o próximo dia dele no período (AAAA-MM-DD)
 *     resumo.relogios[].relogio      o nome do relógio
 *     resumo.relogios[].relogio_id   o número do relógio
 *     resumo.sem_dias[]              os relógios disponíveis sem nenhum dia no período
 *     resumo.sem_dias[].id           o número do relógio
 *     resumo.sem_dias[].nome         o nome do relógio
 *     relogios[]                     os relógios, para trocar o de um dia (trocar_dia)
 *     relogios[].disponivel          verdadeiro ou falso: entra no rodízio (só o disponível pode ser escolhido para um dia)
 *     relogios[].id                  o número do relógio
 *     relogios[].nome                o nome
 *
 *   recurso=eventos
 *     eventos[]                      os eventos personalizados (avisos seus, com horário e repetição próprios)
 *     eventos[].agenda               verdadeiro ou falso: o evento vai para o Google Agenda
 *     eventos[].ativo                verdadeiro ou falso: o evento dispara
 *     eventos[].criado               quando o evento foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].data_inicio          a data (uma vez só) ou o começo da contagem (a cada N dias) (texto AAAA-MM-DD); null nas outras
 *     eventos[].descricao            a repetição por extenso ("seg, qua 20:00")
 *     eventos[].dia_mes              o dia do mês (mensal), de 1 a 31; null nas outras
 *     eventos[].dias_semana          os dias da semana (semanal), de 1 (segunda) a 7 (domingo), separados por vírgula; null nas outras
 *     eventos[].disparos[]           as vezes em que já disparou
 *     eventos[].disparos[].disparado quando o cron mandou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].disparos[].ocorrencia
 *                                    a vez que disparou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema, a do cadastro)
 *     eventos[].hora                 a hora do disparo (HH:MM)
 *     eventos[].id                   o número do evento (o tipo dele nos canais é ev<id>)
 *     eventos[].intervalo_dias       de quantos em quantos dias (intervalo); null nas outras
 *     eventos[].nome                 o nome do evento (texto)
 *     eventos[].proximas[]           as 10 próximas vezes em que dispara (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     eventos[].relogio              o nome desse relógio; null: evento geral
 *     eventos[].relogio_id           o relógio do evento (as âncoras dele funcionam na mensagem); null: evento geral
 *     eventos[].repeticao            quando dispara: uma (uma vez só), diaria, semanal, mensal ou intervalo (a cada N dias)
 *     eventos[].telegram             verdadeiro ou falso: o evento vai pelo Telegram (marcado em "O que vai para onde")
 *
 *   recurso=agenda
 *     agenda_id                      o id da agenda
 *     ativa                          verdadeiro ou falso: a agenda está ligada
 *     chave_ok                       verdadeiro ou falso: a chave do Google foi lida e o Google aceitou
 *     criados[]                      os eventos que o sistema criou no Google Agenda
 *     criados[].assinatura           a marca do título e da descrição com que foi criado (mudou: o evento é atualizado na próxima sincronização)
 *     criados[].chave                a identificação do evento (a mesma dos desejados)
 *     criados[].criado               quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     criados[].data                 o dia do evento (texto AAAA-MM-DD)
 *     criados[].google_id            o id do evento no Google
 *     criados[].titulo               o título com que foi criado
 *     desejados[]                    o que tem de estar no Google Agenda agora: os avisos marcados para a agenda, o relógio do dia, a véspera e os
 *                                    eventos
 *     desejados[].chave              a identificação do evento na agenda (a mesma enquanto nada mudar; é o que o sistema usa para atualizar ou remover)
 *     desejados[].data               o dia do evento na agenda (texto AAAA-MM-DD)
 *     desejados[].descricao          a descrição do evento, montada pela mensagem do canal da agenda
 *     desejados[].fazer              a ação ("Dar corda", "Usar hoje", o nome do evento) — a âncora {acao}
 *     desejados[].hora               a hora do evento na agenda (HH:MM)
 *     desejados[].momento            o instante previsto do que motivou o evento (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     desejados[].motivo             o porquê ("a reserva acaba em 3h, 28/09 10:53") — a âncora {motivo}
 *     desejados[].relogio            o nome do relógio; vazio num evento geral
 *     desejados[].relogio_id         o relógio do evento; null num evento geral
 *     desejados[].tipo               o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)
 *     desejados[].titulo             o título do evento, montado pela mensagem do canal da agenda
 *     janela_dias                    a antecedência da agenda, em dias
 *
 *   recurso=usuarios
 *     usuarios[]                     quem acessa
 *     usuarios[].criado              quando foi criado
 *     usuarios[].login               o login
 *     usuarios[].senha_algoritmo     o cálculo do hash: bcrypt (o padrão do password_hash do PHP), ou argon2i/argon2id
 *     usuarios[].senha_custo         o custo do bcrypt (quantas rodadas: 2 elevado a ele), que também está escrito no próprio hash
 *                                    ($2y$12$...: 12); null no argon2
 *     usuarios[].senha_hash          o hash da senha (a senha não existe no sistema): $2y$<custo>$ seguido do sal (22 caracteres) e do
 *                                    resultado (31). Não se desfaz em senha: confere-se a senha digitada contra ele (veja senhas na ajuda).
 *                                    Levado para outro sistema que confira por bcrypt, o usuário entra lá com a mesma senha; e volta para
 *                                    cá por usuarios/salvar com senha_hash
 *     voce                           o login de quem pediu (pelo site; vazio pelo token)
 *
 *   recurso=migracoes
 *     pendentes[]                    as migrações do banco que faltam aplicar (vazio: em dia)
 *     pendentes[].arquivo            o arquivo .sql
 *     pendentes[].traz               o que a migração traz
 *     pendentes[].versao             a versão (v2, v3...)
 *   recurso=instalacao
 *     banco                          o banco do config.php: mysql, pgsql ou sqlite
 *     em_dia                         instalado e sem migração pendente
 *     instalado                      o banco tem as tabelas do sistema
 *     migracoes_pendentes[]          as versões das migrações que faltam aplicar (vazio com o banco vazio ou em dia)
 *     proximo_passo                  o que fazer agora: instalar (POST recurso=instalacao, acao=instalar), aplicar as migrações, criar o
 *                                    primeiro usuário (POST recurso=usuarios, acao=salvar, com o token) ou nada
 *     tabelas                        quantas tabelas o banco tem
 *     usuarios                       quantos usuários existem (0: o login das páginas ainda não tem como entrar)
 *     vazio                          o banco não tem nenhuma tabela: só a instalação responde, pelo token
 *   recurso=importacao
 *     mysqli                         o PHP tem a extensão do MySQL (mysqli), que a importação usa para ler o banco antigo
 *     precisa_substituir             este banco já tem relógios: a importação pede substituir=1 (apaga antes os relógios e o histórico)
 *     relogios_neste_banco           quantos relógios este banco tem agora
 *     servidor_antigo                onde a importação procura o banco antigo (MySQL/MariaDB); a senha nunca sai
 *     servidor_antigo.de_onde        de onde vêm esses dados: os ANTIGO_* do config.php, ou os DB_* (com este sistema no MySQL)
 *     servidor_antigo.host           o servidor
 *     servidor_antigo.porta          a porta (null: a padrão)
 *     servidor_antigo.usuario        o usuário que lê o banco antigo
 *     traz[]                         o que a importação traz, em texto
 *   recurso=entrar
 *     por                            como o pedido entrou: login (o do site, HTTP Basic) ou token
 *     usuario                        o login de quem entrou (null pelo token)
 *     volta                          a página para onde ele foi mandado (302), ou null
 *
 *   recurso=documentos
 *     relogio                        o relógio pedido: {id, nome, copia_banco}; null: os documentos de todos
 *     relogio.id                     o número do relógio
 *     relogio.nome                   o nome do relógio
 *     relogio.copia_banco            verdadeiro: o relógio pede a cópia no banco de todos os documentos dele (o segundo dos três níveis da
 *                                    cópia; o DOCUMENTOS_COPIA_BANCO do config.php, se definido, vale por cima)
 *     pasta_ok                       verdadeiro: a pasta dos documentos (DOCUMENTOS_PASTA do config.php) existe e aceita gravar
 *     copia_sistema                  a cópia no banco pelo config.php (DOCUMENTOS_COPIA_BANCO), que vale sobre o relógio e o arquivo:
 *                                    verdadeiro, todo arquivo vai; falso, nenhum vai; null (sem a constante), cada relógio e cada arquivo
 *                                    decidem
 *     pasta_erro                     o motivo de a pasta não servir (texto); null: ela serve
 *     limite                         o maior arquivo aceito, em bytes (número inteiro): o DOCUMENTOS_LIMITE do config.php (sem ele, 100
 *                                    MB), ou menos pelo upload_max_filesize e o post_max_size do PHP; null: sem limite
 *     relogios[]                     todos os relógios, pelo nome (para escolher outro)
 *     relogios[].documentos          quantos documentos ele tem
 *     relogios[].id                  o número do relógio
 *     relogios[].nome                o nome do relógio
 *     categorias[]                   as categorias dos documentos, na ordem
 *     categorias[].aceita[]          os tipos de arquivo que ela aceita (imagem, video, audio, pdf, xml); lista vazia: qualquer arquivo
 *     categorias[].documentos        quantos documentos ela tem (com relogio: só os daquele relógio)
 *     categorias[].id                o número da categoria
 *     categorias[].identificador     o identificador (minúsculas, números e _)
 *     categorias[].nome              o nome
 *     categorias[].ordem             a ordem em que ela aparece (número)
 *     documentos[]                   os documentos do relógio (o manual, a nota fiscal, fotos, vídeos...): os dados de cada um; o arquivo
 *                                    vem pelo recurso=documento
 *     documentos[].categoria_id      a categoria dele (as categorias dos documentos)
 *     documentos[].criado            quando foi enviado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     documentos[].data              a data do documento: a ocasião da foto, a data da nota (texto AAAA-MM-DD; null: sem data)
 *     documentos[].descricao         a descrição (texto; null: sem descrição)
 *     documentos[].familia           como ele abre, pelo tipo do arquivo: imagem (a galeria), video (o player), audio, pdf (o
 *                                    visualizador), xml (o resumo da nota e o download) ou outro (o download)
 *     documentos[].id                o número do documento (para recurso=documento e para alterar ou excluir)
 *     documentos[].miniatura         verdadeiro: a foto tem miniatura (recurso=documento com mini=1)
 *     documentos[].nome              o nome do arquivo enviado
 *     documentos[].no_disco          verdadeiro: o arquivo está na pasta dos documentos (falso: ele volta do banco quando for pedido ou na
 *                                    próxima rodada do cron; sem a cópia no banco, está perdido)
 *     documentos[].no_banco          verdadeiro: a cópia de segurança do arquivo está completa no banco (se ele sumir da pasta, volta
 *                                    dali)
 *     documentos[].copia_banco       verdadeiro: o próprio arquivo pede a cópia no banco (o terceiro nível; o relógio e o config.php, se
 *                                    pedem, valem por cima)
 *     documentos[].copia_por         quem pede a cópia no banco: sistema (o DOCUMENTOS_COPIA_BANCO = true do config.php), relogio (a marca
 *                                    do relógio) ou arquivo (a marca do próprio arquivo); null: ninguém pede, ou o config.php (false) não
 *                                    deixa, e o arquivo fica só na pasta (a cópia que houver, o cron tira)
 *     documentos[].relogio_id        o relógio dele
 *     documentos[].tamanho           o tamanho do arquivo, em bytes (número inteiro)
 *     documentos[].tipo              o tipo do arquivo (MIME): image/jpeg, video/mp4, application/pdf, application/xml...
 *     documentos[].titulo            o título
 *     documentos[].url               o endereço do arquivo, relativo à pasta do sistema (api.php?recurso=documento&id=...)
 *     documentos[].nfe               o resumo da NF-e, quando o arquivo é o XML de uma nota fiscal eletrônica; null: não é
 *     documentos[].nfe.chave         a chave de acesso da nota (44 dígitos)
 *     documentos[].nfe.cnpj          o CNPJ (ou o CPF) do emitente
 *     documentos[].nfe.data          a data de emissão (texto AAAA-MM-DD)
 *     documentos[].nfe.emitente      o nome do emitente (a loja)
 *     documentos[].nfe.numero        o número da nota
 *     documentos[].nfe.produtos[]    os produtos da nota
 *     documentos[].nfe.produtos[].descricao
 *                                    a descrição do produto
 *     documentos[].nfe.produtos[].quantidade
 *                                    a quantidade (número)
 *     documentos[].nfe.produtos[].valor
 *                                    o valor do produto (número, em R$)
 *     documentos[].nfe.serie         a série da nota
 *     documentos[].nfe.valor         o valor total da nota (número, em R$)
 *   recurso=manual
 *     fonte                          de onde vem o texto: README.md
 *     trechos[]                      os trechos do README que entram, na ordem: cada um da seção "de" até antes da seção "ate"
 *     trechos[].de                   o título da seção em que o trecho começa
 *     trechos[].ate                  o título da seção em que o trecho para (ela fica de fora)
 *     secoes[]                       cada título do manual (## a ####), na ordem
 *     secoes[].nivel                 o nível do título: 2 (seção), 3 ou 4 (dentro dela)
 *     secoes[].titulo                o título, como no README (em markdown)
 *     secoes[].ancora                a âncora do título, como a do GitHub e a da página Ajuda (ajuda.php#ancora)
 *     secoes[].texto                 o texto da seção até o próximo título, em markdown (tabelas, listas, código, imagens)
 *     markdown                       o manual inteiro, em markdown: o que a página Ajuda converte para HTML
 *     html                           o manual inteiro já em HTML, como a página Ajuda mostra: títulos com âncora, parágrafos, listas,
 *                                    tabelas, código, imagens e as legendas (o diagrama mermaid fica de fora)
 *     sumario[]                      o sumário da página Ajuda: os títulos de nível 2 e 3, na ordem
 *     sumario[].ancora               a âncora do título (ajuda.php#ancora)
 *     sumario[].nivel                o nível do título: 2 ou 3
 *     sumario[].titulo               o título em texto puro (sem a marcação do markdown)
 *   recurso=reconstrucao
 *     o_que_e                        o que o sistema é e faz, num parágrafo
 *     como_usar[]                    como ler esta resposta e onde está o resto (a API em recurso=ajuda, o cadastro inicial no schema.sql e
 *                                    em recurso=cadastros e recurso=criterios, o README, os testes)
 *     secoes[]                       as partes do roteiro, na ordem: os princípios, a arquitetura, a ordem de construção e as regras de
 *                                    cada parte do sistema
 *     secoes[].id                    o nome curto da parte (principios, arquitetura, ordem, arvore_campos, lancamentos, formulas, avisos,
 *                                    gasto, criterios, rodizio, pulso, cron, mensagens, documentos, telas, seguranca, testes)
 *     secoes[].titulo                o título da parte
 *     secoes[].itens[]               as regras e as explicações daquela parte, uma por texto
 *     modelo_de_dados                o modelo de dados, lido do próprio banco em uso (o que vale agora, depois de todas as migrações)
 *     modelo_de_dados.banco          o banco de onde o modelo foi lido: mysql, pgsql ou sqlite (os tipos das colunas vêm no dialeto dele)
 *     modelo_de_dados.como_ler       como ler as tabelas, e as três referências que ficam pelo nome, de propósito
 *     modelo_de_dados.tabelas[]      cada tabela, em ordem alfabética
 *     modelo_de_dados.tabelas[].tabela
 *                                    o nome da tabela
 *     modelo_de_dados.tabelas[].descricao
 *                                    o que a tabela guarda
 *     modelo_de_dados.tabelas[].colunas[]
 *                                    as colunas, na ordem da tabela
 *     modelo_de_dados.tabelas[].colunas[].nome
 *                                    o nome da coluna
 *     modelo_de_dados.tabelas[].colunas[].tipo
 *                                    o tipo, no dialeto do banco em uso
 *     modelo_de_dados.tabelas[].colunas[].vazio
 *                                    verdadeiro: aceita vazio (NULL)
 *     modelo_de_dados.tabelas[].colunas[].padrao
 *                                    o valor padrão, como o banco escreve (null: nenhum)
 *     modelo_de_dados.tabelas[].chave_primaria[]
 *                                    as colunas da chave primária
 *     modelo_de_dados.tabelas[].chaves_estrangeiras[]
 *                                    as ligações com outras tabelas
 *     modelo_de_dados.tabelas[].chaves_estrangeiras[].coluna
 *                                    a coluna desta tabela
 *     modelo_de_dados.tabelas[].chaves_estrangeiras[].referencia
 *                                    a tabela.coluna para onde ela aponta
 *     modelo_de_dados.tabelas[].chaves_estrangeiras[].ao_apagar
 *                                    o que acontece ao apagar a linha de cima: CASCADE (some junto) ou SET NULL (a ligação se desfaz)
 *     modelo_de_dados.tabelas[].unicas[]
 *                                    cada chave única: a lista das colunas dela
 *     modelo_de_dados.tabelas[].unicas[][]
 *                                    uma coluna da chave única, na ordem
 *     modelo_de_dados.tabelas[].indices[]
 *                                    cada índice que não é único: a lista das colunas dele
 *     modelo_de_dados.tabelas[].indices[][]
 *                                    uma coluna do índice, na ordem
 *     modelo_de_dados.tabelas[].regras[]
 *                                    as regras de validação (CHECK) da tabela, inclusive as das listas fechadas
 *     modelo_de_dados.tabelas[].regras[].nome
 *                                    o nome da regra (ck_<tabela>_<o quê>; null: a de uma lista fechada, sem nome)
 *     modelo_de_dados.tabelas[].regras[].condicao
 *                                    a condição que toda linha tem de cumprir, como o banco escreve
 *     listas                         as listas do código que a reescrita precisa ter iguais
 *     listas.funcoes_das_formulas[]  as funções do motor das fórmulas
 *     listas.funcoes_das_formulas[].nome
 *                                    o nome da função
 *     listas.funcoes_das_formulas[].argumentos
 *                                    quantos argumentos ela aceita ("2", "1 a 2", "3 ou mais")
 *     listas.funcoes_das_formulas[].descricao
 *                                    o que ela devolve
 *     listas.funcoes_das_formulas[].le_historico
 *                                    verdadeiro: lê os lançamentos do relógio (o primeiro argumento é um tipo de lançamento, entre aspas)
 *     listas.tipos_de_campo[]        os tipos de campo do cadastro
 *     listas.tipos_de_campo[].tipo   o tipo (inteiro, decimal, sim_nao, data, lista, texto)
 *     listas.tipos_de_campo[].descricao
 *                                    o que ele é, e como entra nas contas
 *     listas.formatos_de_lancamento[]
 *                                    os formatos dos tipos de lançamento
 *     listas.formatos_de_lancamento[].formato
 *                                    o formato (instantaneo, valor, sessao)
 *     listas.formatos_de_lancamento[].descricao
 *                                    o que ele é
 *     listas.repeticoes[]            as repetições dos eventos personalizados
 *     listas.repeticoes[].repeticao  a repetição (uma, diaria, semanal, mensal, intervalo)
 *     listas.repeticoes[].descricao  como ela aparece na tela
 *     listas.dias_da_semana[]        os dias da semana como o sistema numera
 *     listas.dias_da_semana[].dia    o número (1 segunda a 7 domingo)
 *     listas.dias_da_semana[].nome   o nome
 *     listas.ancoras[]               as âncoras das mensagens (entre chaves no texto)
 *     listas.ancoras[].ancora        a âncora, sem as chaves
 *     listas.ancoras[].descricao     o que ela vira
 *     listas.canais[]                os canais das mensagens
 *     listas.canais[].canal          o código do canal (tg: Telegram, ag: Google Agenda)
 *     listas.canais[].nome           o nome
 *     listas.canais[].ajuda          como a mensagem sai nele
 *     listas.migracoes[]             as migrações, na ordem
 *     listas.migracoes[].versao      a versão (v2, v3...)
 *     listas.migracoes[].arquivo     o arquivo do SQL dela
 *     listas.migracoes[].traz        o que ela traz
 *     listas.migracoes[].passo_em_php
 *                                    verdadeiro: ela tem também um passo em programa, depois do SQL
 *     listas.config_php[]            as constantes do config.php
 *     listas.config_php[].constante  o nome (ou os nomes) da constante
 *     listas.config_php[].descricao  para que ela serve
 *   _filtros (em qualquer consulta com filtros)
 *     _filtros                       aparece quando a consulta usa filtros (incluir, excluir, f, busca, ordem, limite, pagina, mostrar)
 *     _filtros.ignorados[]           o que foi pedido e não existe (caminho sem lista, parte que não existe)
 *     _filtros.listas.<lista>.antes  quantos itens a lista tinha
 *     _filtros.listas.<lista>.devolvidos
 *                                    quantos vieram (a página)
 *     _filtros.listas.<lista>.limite o limite por página; null: sem
 *     _filtros.listas.<lista>.pagina a página
 *     _filtros.listas.<lista>.passaram
 *                                    quantos passaram nos filtros
 * ---------------------------------------------------------------------------------------------
 * ERROS  (no formato pedido)
 * ---------------------------------------------------------------------------------------------
 *   400 pedido recusado: uma escrita que não passou nas validações ({"ok": false, "mensagem", "erros": [...]}, um erro por
 *       motivo) ou uma fórmula mal escrita no recurso=calcular ({"erro", "erros"})
 *   302 recurso=entrar com o login certo e volta=<página>: vai para a página
 *   401 {"erro"}: sem o token nem o login do site, ou com eles errados; sem nenhum usuário (ou com o banco vazio), também
 *       "sem_usuarios": true e "como" (o que fazer: instalar, criar o primeiro usuário pelo token)
 *   404 {"erro"}: recurso desconhecido (com "recursos", a lista deles), ou o relógio, a foto, o documento (ou o arquivo dele) ou o
 *       README.md (recurso=manual) que não existe
 *   409 {"erro": "o banco recusou a gravação: ...", "detalhe"} (um registro que outro ainda usa, um valor repetido, ou um valor fora
 *       das regras de validação do banco: o nome da regra, ck_<tabela>_<o quê>, vem no detalhe)
 *   413 {"ok": false, "erros": [...]} (o envio passou do post_max_size do PHP: um arquivo grande demais)
 *   500 {"erro": "Sistema parado: ..."} (token ou fuso inválido no config.php) ou {"erro": "erro interno", "detalhe"}
 *   503 {"erro": "o banco está desatualizado: ...", "pendentes"}: só recurso=migracoes (e recurso=manual, que não usa o banco)
 *       responde até aplicar
 *   503 {"erro": "o banco está vazio: ...", "proximo_passo"}: antes da instalação só recurso=instalacao (e recurso=manual) responde
 *   503 {"erro": "o banco de dados não respondeu", "detalhe"}
 */
require_once __DIR__ . "/lib.php";

$formato = strtolower((string)($_REQUEST["formato"] ?? ""));
if ($formato === "" && stripos($_SERVER["HTTP_ACCEPT"] ?? "", "xml") !== false) {
    $formato = "xml";
}
if ($formato !== "xml") {
    $formato = "json";
}

// XML: cada chave vira um elemento; listas repetem o nome no singular ("item" quando não há um)
function xml_de($v, $nivel)
{
    $res = "";
    $recuo = str_repeat("  ", $nivel);
    foreach ((array)$v as $k => $x) {
        $tag = is_int($k) ? "item" : preg_replace("/[^A-Za-z0-9_]/", "_", (string)$k);
        if (preg_match("/^[A-Za-z_]/", $tag) !== 1) {
            $tag = "c_" . $tag;
        }
        if (is_array($x) || is_object($x)) {
            $res .= $recuo . "<" . $tag . ">\n" . xml_de($x, $nivel + 1) . $recuo . "</" . $tag . ">\n";
        } elseif ($x === null) {
            $res .= $recuo . "<" . $tag . "/>\n";
        } else {
            $res .= $recuo . "<" . $tag . ">" . htmlspecialchars(is_bool($x) ? ($x ? "true" : "false") : (string)$x, ENT_XML1) . "</" . $tag . ">\n";
        }
    }
    return $res;
}

// O resumo do tempo para a resposta: cada estado com segundos, texto ("13d 3h") e porcentagem, e as marcações por tipo
function resumo_do_tempo($f)
{
    $soma = array_sum($f["tempo"]);
    $tempo = [];
    foreach ($f["tempo"] as $k => $seg) {
        $tempo[$k] = ["segundos" => $seg, "texto" => duracao_texto($seg / 86400) !== "" ? duracao_texto($seg / 86400) : "0min",
            "porcentagem" => $soma > 0 ? round($seg * 100 / $soma, 1) : 0];
    }
    return ["tempo" => $tempo, "marcacoes" => (object)$f["marcas"]];
}

// Uma linha da linha do tempo para a resposta
function linha_legivel($l)
{
    return ["inicio" => date("Y-m-d H:i:s", $l["inicio"]), "fim" => $l["fim"] === null ? null : date("Y-m-d H:i:s", $l["fim"]), "em_andamento" => $l["em_andamento"],
        "duracao_seg" => $l["fim"] === null ? null : $l["fim"] - $l["inicio"],
        "duracao" => $l["fim"] === null ? null : (duracao_texto(($l["fim"] - $l["inicio"]) / 86400) !== "" ? duracao_texto(($l["fim"] - $l["inicio"]) / 86400) : "0min"),
        "estado" => $l["estado"], "tipo" => $l["tipo"], "texto" => $l["texto"]];
}

// As medições do gasto de um relógio para a resposta (todas, da mais recente para a mais antiga), dizendo se cada uma entra
// na média agora (usada e dentro da janela da Configuração)
function medicoes_legivel($id)
{
    $limite = time() - max(1, (int)cfg("medicao_janela_dias")) * 86400;
    return array_map(function ($m) use ($limite) {
        return ["id" => (int)$m["id"], "lancamento_id" => (int)$m["lancamento_id"], "medida" => $m["medida"], "taxa" => (float)$m["taxa"], "de_valor" => (float)$m["de_valor"],
            "ate_valor" => (float)$m["ate_valor"], "inicio" => $m["inicio"], "fim" => $m["fim"], "horas_pulso" => (float)$m["horas_pulso"], "horas_guardado" => (float)$m["horas_guardado"],
            "peso_horas" => (float)$m["peso_horas"], "usada" => (int)$m["usada"] === 1, "na_media" => (int)$m["usada"] === 1 && strtotime($m["fim"]) > $limite, "criado" => $m["criado"]];
    }, linhas("SELECT m.* FROM medicao m JOIN lancamento l ON l.id = m.lancamento_id WHERE l.relogio_id = ? ORDER BY m.fim DESC, m.id DESC", [(int)$id]));
}

// Um evento personalizado para a resposta, com as próximas ocorrências e os disparos
function evento_legivel($ev)
{
    return ["id" => (int)$ev["id"], "nome" => $ev["nome"], "ativo" => (int)$ev["ativo"] === 1, "repeticao" => $ev["repeticao"],
        "descricao" => descricao_repeticao($ev), "hora" => substr($ev["hora"], 0, 5), "data_inicio" => $ev["data_inicio"], "dias_semana" => $ev["dias_semana"],
        "dia_mes" => $ev["dia_mes"] === null ? null : (int)$ev["dia_mes"], "intervalo_dias" => $ev["intervalo_dias"] === null ? null : (int)$ev["intervalo_dias"],
        "relogio_id" => $ev["relogio_id"] === null ? null : (int)$ev["relogio_id"],
        "relogio" => $ev["relogio_id"] === null ? null : valor("SELECT nome FROM relogio WHERE id = ?", [(int)$ev["relogio_id"]]),
        "telegram" => canal_aviso("tg", "ev" . $ev["id"])["envia"], "agenda" => canal_aviso("ag", "ev" . $ev["id"])["envia"],
        "criado" => $ev["criado"],
        "proximas" => (int)$ev["ativo"] === 1 ? array_map(function ($t) { return date("Y-m-d H:i:s", $t); }, array_slice(ocorrencias($ev, time() + 1, time() + 366 * 86400), 0, 10)) : [],
        "disparos" => linhas("SELECT ocorrencia, disparado FROM evento_disparo WHERE evento_id = ? ORDER BY ocorrencia DESC", [(int)$ev["id"]])];
}

// O plano gravado para a resposta, entre duas datas (vazias: sem limite)
function plano_legivel($de, $ate)
{
    return array_map(function ($p) {
        return ["data" => $p["data"], "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["relogio"], "bloco_id" => $p["bloco_id"] === null ? null : (int)$p["bloco_id"],
            "bloco" => $p["bloco"], "origem" => $p["origem"], "acao" => $p["acao"], "motivo" => $p["motivo"], "criado" => $p["criado"]];
    }, linhas("SELECT p.*, r.nome AS relogio, b.nome AS bloco FROM plano p JOIN relogio r ON r.id = p.relogio_id LEFT JOIN modo_bloco b ON b.id = p.bloco_id
        WHERE p.data >= ? AND p.data <= ? ORDER BY p.data", [$de !== "" ? $de : "0001-01-01", $ate !== "" ? $ate : "9999-12-31"]));
}

// O resumo de um período do plano (os dias de hoje em diante da lista): quantos dias cada relógio tem, a porcentagem, o
// próximo dia dele e quantos foram escolhidos à mão; e os relógios disponíveis sem nenhum dia (o resumo da página Plano)
function plano_resumo($plano, $hoje)
{
    $dias = array_values(array_filter($plano, function ($p) use ($hoje) { return $p["data"] >= $hoje; }));
    $conta = [];
    foreach ($dias as $p) {
        $k = $p["relogio_id"];
        if (!isset($conta[$k])) {
            $conta[$k] = ["relogio_id" => $k, "relogio" => $p["relogio"], "dias" => 0, "porcentagem" => 0.0, "proximo" => $p["data"], "a_mao" => 0];
        }
        $conta[$k]["dias"]++;
        $conta[$k]["a_mao"] += $p["origem"] === "manual" ? 1 : 0;
    }
    $lista = array_values($conta);
    foreach ($lista as $i => $c) {
        $lista[$i]["porcentagem"] = round($c["dias"] * 100 / count($dias), 1);
    }
    usort($lista, function ($a, $b) { return $b["dias"] <=> $a["dias"] ?: strcmp(texto_normal($a["relogio"]), texto_normal($b["relogio"])); });
    $sem = array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"]]; }, array_values(array_filter(linhas("SELECT id, nome FROM relogio WHERE disponivel = 1 ORDER BY nome"),
        function ($r) use ($conta) { return !isset($conta[(int)$r["id"]]); })));
    return ["de" => count($dias) > 0 ? $dias[0]["data"] : null, "ate" => count($dias) > 0 ? $dias[count($dias) - 1]["data"] : null, "dias" => count($dias),
        "relogios" => $lista, "sem_dias" => $sem];
}

// A situação do cron, como a Configuração mostra: nunca rodou, parado (mais de 10 minutos sem rodar; ele roda a cada
// minuto) ou rodando; com a última execução, há quantos minutos, a frase e a linha do crontab
function cron_estado()
{
    $ultima = cfg("cron_ultima_execucao");
    $minutos = $ultima !== "" ? max(0, (int)floor((time() - strtotime($ultima)) / 60)) : null;
    $situacao = $ultima === "" ? "nunca" : ($minutos > 10 ? "parado" : "rodando");
    $linha = "* * * * * php " . __DIR__ . "/cron.php";
    $texto = $situacao === "nunca" ? "O cron ainda não rodou. Linha do crontab: " . $linha
        : ($situacao === "parado" ? "O cron não roda desde " . date("d/m H:i", strtotime($ultima)) . ". Confira o crontab: " . $linha
            : "Cron rodando: última execução às " . date("H:i", strtotime($ultima)) . ".");
    return ["situacao" => $situacao, "ultima" => $ultima !== "" ? $ultima : null, "minutos" => $minutos, "texto" => $texto, "crontab" => $linha,
        "erro" => cfg("cron_erro") !== "" ? cfg("cron_erro") : null];
}

// O que tem de estar na agenda, com o título e a descrição pelo modelo
function agenda_legivel()
{
    return array_map(function ($m) {
        return array_merge($m, evento_formatado($m));
    }, agenda_desejada(new DateTimeImmutable("today")));
}
// ---------------------------------------------------------------------------------------------------------------------
// Filtros de qualquer consulta (GET): valem para toda lista da resposta, pelo caminho dela ("relogios", "plano",
// "relogios.lancamentos", "criterios.notas"...), e para todo campo de cada item (com ponto para entrar num objeto:
// "compra.valor"; numa lista de itens com identificador, o identificador escolhe o item: "formulas.energia.valor").
// ---------------------------------------------------------------------------------------------------------------------

// Um valor em texto para comparar: minúsculas e sem acento (lista ou objeto: o JSON dele; verdadeiro e falso: 1 e 0)
function texto_normal($v)
{
    $t = is_array($v) || is_object($v) ? (string)json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? "1" : "0") : (string)$v);
    $t = function_exists("mb_strtolower") ? mb_strtolower($t, "UTF-8") : strtolower($t);
    return strtr($t, ["á" => "a", "à" => "a", "â" => "a", "ã" => "a", "ä" => "a", "é" => "e", "è" => "e", "ê" => "e", "ë" => "e", "í" => "i", "ì" => "i", "î" => "i",
        "ï" => "i", "ó" => "o", "ò" => "o", "ô" => "o", "õ" => "o", "ö" => "o", "ú" => "u", "ù" => "u", "û" => "u", "ü" => "u", "ç" => "c", "ñ" => "n"]);
}

// O valor de um campo de um item, pelo caminho com pontos ("compra.valor", "formulas.energia.valor"); não existe: null
function valor_do_campo($item, $caminho)
{
    $v = $item;
    foreach (explode(".", $caminho) as $parte) {
        $achou = null;
        if (is_array($v) && array_key_exists($parte, $v)) {
            $achou = $v[$parte];
        } elseif (is_array($v) && array_is_list($v)) {
            // numa lista de itens com identificador, o identificador escolhe o item
            foreach ($v as $x) {
                if (is_array($x) && (string)($x["identificador"] ?? "") === $parte) {
                    $achou = $x;
                }
            }
        }
        $v = $achou;
    }
    return $v;
}

// Aplica as regras de uma lista (filtros, busca, ordem, página, campos mostrados) em toda lista que estiver no caminho;
// $conta soma, de todas elas, quantos itens havia, quantos passaram nos filtros e quantos foram devolvidos
function filtrar_lista(&$no, $partes, $regras, &$conta)
{
    if (count($partes) === 0) {
        if (is_array($no) && array_is_list($no)) {
            $conta["listas"]++;
            $conta["antes"] += count($no);
            $lista = array_values(array_filter($no, function ($item) use ($regras) {
                $passa = true;
                foreach ($regras["f"] as $campo => $cond) {
                    $v = valor_do_campo($item, (string)$campo);
                    $vt = texto_normal($v);
                    // sem operador: igual a um dos valores (separados por vírgula); com operador: cada um tem de valer
                    $ops = is_array($cond) ? $cond : ["igual" => $cond];
                    foreach ($ops as $op => $alvo) {
                        $alvo = (string)$alvo;
                        if ($op === "igual" || $op === "diferente") {
                            $algum = false;
                            foreach (explode(",", $alvo) as $a) {
                                $a = trim($a);
                                $igual = is_numeric($a) && is_numeric($v) ? (float)$a == (float)$v : texto_normal($a) === $vt;
                                // numa lista (de textos ou de números), vale se algum item for igual
                                if (is_array($v) && array_is_list($v)) {
                                    $igual = count(array_filter($v, function ($x) use ($a) { return texto_normal($x) === texto_normal($a); })) > 0;
                                }
                                $algum = $algum || $igual;
                            }
                            $passa = $passa && ($op === "igual" ? $algum : !$algum);
                        } elseif ($op === "contem") {
                            $passa = $passa && $alvo !== "" && strpos($vt, texto_normal($alvo)) !== false;
                        } elseif ($op === "vazio") {
                            $e_vazio = $v === null || $v === "" || (is_array($v) && count($v) === 0);
                            $passa = $passa && ($alvo === "1" ? $e_vazio : !$e_vazio);
                        } elseif ($op === "de" || $op === "ate") {
                            // números como números; datas e textos como texto (uma data só no "até" vai até o fim daquele dia)
                            $cmp = 0;
                            $vs = is_bool($v) ? ($v ? "1" : "0") : (is_array($v) ? "" : (string)$v);
                            if (is_numeric($v) && is_numeric($alvo)) {
                                $cmp = (float)$v <=> (float)$alvo;
                            } else {
                                $cmp = strcmp($op === "ate" && strlen($alvo) === 10 && strlen($vs) > 10 ? substr($vs, 0, 10) : $vs, $alvo);
                            }
                            $passa = $passa && $v !== null && $vs !== "" && ($op === "de" ? $cmp >= 0 : $cmp <= 0);
                        } else {
                            $passa = false;
                        }
                    }
                }
                if ($regras["busca"] !== "") {
                    $passa = $passa && strpos(texto_normal($item), texto_normal($regras["busca"])) !== false;
                }
                return $passa;
            }));
            $conta["depois"] += count($lista);
            if (count($regras["ordem"]) > 0) {
                usort($lista, function ($a, $b) use ($regras) {
                    $res = 0;
                    foreach ($regras["ordem"] as $campo) {
                        $desc = substr($campo, 0, 1) === "-";
                        $campo = ltrim($campo, "-+");
                        $va = valor_do_campo($a, $campo);
                        $vb = valor_do_campo($b, $campo);
                        $c = is_numeric($va) && is_numeric($vb) ? (float)$va <=> (float)$vb : strcmp(texto_normal($va), texto_normal($vb));
                        if ($res === 0) {
                            $res = $desc ? -$c : $c;
                        }
                    }
                    return $res;
                });
            }
            if ($regras["limite"] > 0) {
                $lista = array_slice($lista, ($regras["pagina"] - 1) * $regras["limite"], $regras["limite"]);
            }
            if (count($regras["mostrar"]) > 0) {
                $lista = array_map(function ($item) use ($regras) {
                    $res = $item;
                    if (is_array($item) && !array_is_list($item)) {
                        $res = [];
                        foreach ($regras["mostrar"] as $campo) {
                            $res[$campo] = valor_do_campo($item, $campo);
                        }
                    }
                    return $res;
                }, $lista);
            }
            $conta["devolvidos"] += count($lista);
            $no = $lista;
        }
    } elseif (is_array($no)) {
        if (array_is_list($no)) {
            // uma lista no meio do caminho: as regras valem para a lista de dentro de cada item
            foreach ($no as $i => $item) {
                filtrar_lista($no[$i], $partes, $regras, $conta);
            }
        } elseif (array_key_exists($partes[0], $no)) {
            filtrar_lista($no[$partes[0]], array_slice($partes, 1), $regras, $conta);
        }
    }
}

// Os critérios: os conjuntos por lugar (parâmetros, subparâmetros e faixas, com quem usa cada um), as variáveis que podem
// medir, a nota de cada relógio com a conta, os lugares (quem tem critérios próprios e de quem herda) e os modos. Usado pelo
// recurso=criterios e pela resposta sem parâmetros.
function montar_criterios()
{
    // os conjuntos de critérios, um por lugar, com quem usa cada um, e a nota de cada relógio com a conta
    $conjuntos = [];
    $notas = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
        $n = nota_do_relogio($r, time());
        $notas[] = array_merge(["relogio_id" => (int)$r["id"], "relogio" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1], $n);
        if ((int)$r["disponivel"] === 1) {
            $conjuntos[(string)$n["conjunto"]][] = (int)$r["id"];
        }
    }
    $lista = [];
    foreach (criterios_config() as $chave => $ps) {
        $lista[] = ["escopo" => (string)$chave, "lugar" => escopo_texto((string)$chave), "usado_por" => $conjuntos[(string)$chave] ?? [], "parametros" => array_map(function ($p) {
            return ["id" => (int)$p["id"], "ordem" => (int)$p["ordem"], "escopo_no_id" => $p["escopo_no_id"] === null ? null : (int)$p["escopo_no_id"],
                "escopo_relogio_id" => $p["escopo_relogio_id"] === null ? null : (int)$p["escopo_relogio_id"], "nome" => $p["nome"], "peso" => (float)$p["peso"],
                "subparametros" => array_map(function ($sb) use ($p) {
                    return ["id" => (int)$sb["id"], "ordem" => (int)$sb["ordem"], "nome" => $sb["nome"], "variavel" => $sb["variavel"], "peso" => (float)$sb["peso"],
                        "peso_efetivo_no_conjunto" => round((float)$sb["peso"] * (float)$p["peso"] / 100, 2),
                        "faixas" => array_map(function ($f) {
                            return ["id" => (int)$f["id"], "de" => $f["de"] === null ? null : (float)$f["de"], "ate" => $f["ate"] === null ? null : (float)$f["ate"],
                                "categoria" => $f["categoria"], "nota" => (float)$f["nota"]];
                        }, $sb["faixas"])];
                }, $p["subs"])];
        }, $ps)];
    }
    // os lugares que podem ter critérios (todos, cada grupo em ordem de árvore, cada relógio), de quem cada um herda, e os modos
    $lugares = [["escopo" => "", "texto" => "Todos os relógios"]];
    foreach (nos_em_ordem() as $o) {
        $lugares[] = ["escopo" => "g:" . $o[0], "texto" => "Grupo: " . no_caminho($o[0])];
    }
    foreach (linhas("SELECT id, nome FROM relogio ORDER BY nome") as $x) {
        $lugares[] = ["escopo" => "r:" . $x["id"], "texto" => "Relógio: " . $x["nome"]];
    }
    foreach ($lugares as $i => $l) {
        $lugares[$i]["lugar"] = escopo_texto($l["escopo"]);
        $lugares[$i]["proprio"] = isset(criterios_config()[$l["escopo"]]);
        $lugares[$i]["herda"] = conjunto_herdado($l["escopo"]);
        $lugares[$i]["herda_texto"] = $lugares[$i]["herda"] !== null ? escopo_texto($lugares[$i]["herda"]) : null;
    }
    foreach ($notas as $i => $x) {
        $notas[$i]["grupo"] = no_caminho(valor("SELECT no_id FROM relogio WHERE id = ?", [$x["relogio_id"]]));
    }
    return ["conjuntos" => $lista, "variaveis" => variaveis_disponiveis(), "notas" => $notas, "max_sem_uso" => (int)cfg("max_sem_uso"), "lugares" => $lugares,
        "modos" => array_map(function ($m) { return ["nome" => $m["nome"], "selecao" => $m["selecao"], "escala" => $m["escala_dias"] !== null, "ativo" => (int)$m["id"] === modo_ativo()]; },
            linhas("SELECT * FROM modo ORDER BY ordem, id"))];
}


function responde($codigo, $saida, $formato)
{
    http_response_code($codigo);
    if ($formato === "xml") {
        header("Content-Type: application/xml; charset=utf-8");
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<resposta>\n" . xml_de($saida, 1) . "</resposta>\n";
    } else {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

// erro que escapar: sai no formato pedido
set_exception_handler(function ($t) use ($formato) {
    if ($t instanceof BancoErro && $t->integridade) {
        // o banco recusou a gravação: um registro que outro ainda usa, ou um valor repetido onde não pode
        responde(409, ["erro" => "o banco recusou a gravação: ela quebraria uma regra dos dados (um registro que outro ainda usa, "
            . "um valor repetido onde não pode, ou um valor fora das regras de validação do banco)", "detalhe" => $t->getMessage()], $formato);
        return;
    }
    $banco = $t instanceof BancoErro || $t instanceof mysqli_sql_exception;
    responde($banco ? 503 : 500, ["erro" => $banco ? "o banco de dados não respondeu" : "erro interno", "detalhe" => $t->getMessage()], $formato);
});

$token = (string)($_SERVER["HTTP_X_API_TOKEN"] ?? ($_REQUEST["token"] ?? ""));
$quem = usuario_autenticado();
$token_ok = hash_equals(trim((string)API_TOKEN), trim($token));
$escrita = $_SERVER["REQUEST_METHOD"] === "POST";
$d = $_POST;
if ($escrita && stripos($_SERVER["CONTENT_TYPE"] ?? "", "json") !== false) {
    $corpo = json_decode((string)file_get_contents("php://input"), true);
    $d = is_array($corpo) ? array_merge($_POST, $corpo) : $_POST;
}
// um envio maior que o post_max_size do PHP chega vazio (sem nenhum campo nem arquivo): o motivo, em vez de "recurso desconhecido"
$envio_grande = $escrita && count($_POST) === 0 && count($_FILES) === 0 && ini_bytes(ini_get("post_max_size")) > 0
    && (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > ini_bytes(ini_get("post_max_size"));
$recurso = strtolower(trim((string)($d["recurso"] ?? ($_REQUEST["recurso"] ?? ""))));
$acao = (string)($d["acao"] ?? ($_REQUEST["acao"] ?? ""));
$codigo = 200;
$saida = [];

// as migrações pendentes: as da lista ($MIGRACOES, no lib.php) cuja marca (tabela ou tabela.coluna) ainda não existe
$pendentes = migracoes_pendentes();
// o banco vazio (antes da instalação): só a instalação responde
$banco_vazio = count($pendentes) > 0 && count(banco_tabelas()) === 0;
if (!$token_ok && $quem === "") {
    $codigo = 401;
    header("WWW-Authenticate: Basic realm=\"Relogios\", charset=\"UTF-8\"");
    $saida = ["erro" => "falta autenticação: o token (cabeçalho X-Api-Token ou parâmetro token) ou o login do site (HTTP Basic)"];
    // sem nenhum usuário, o login não tem como dar certo: diz como criar o primeiro
    if ($banco_vazio || !banco_tem_tabela("usuario") || (int)valor("SELECT COUNT(*) FROM usuario") === 0) {
        $saida["sem_usuarios"] = true;
        $saida["como"] = $banco_vazio ? "o banco está vazio: instale com o token (POST recurso=instalacao, acao=instalar) e crie o primeiro usuário (POST recurso=usuarios, acao=salvar, login e senha)"
            : "nenhum usuário cadastrado: crie o primeiro com o token (POST recurso=usuarios, acao=salvar, login e senha) ou no servidor (php criar_usuario.php <login>)";
    }
} elseif ($envio_grande) {
    $codigo = 413;
    $saida = ["ok" => false, "mensagem" => "", "erros" => ["O envio tem " . str_replace(".", ",", (string)round((int)$_SERVER["CONTENT_LENGTH"] / 1048576, 1))
        . " MB e passou do limite do PHP do servidor (post_max_size = " . ini_get("post_max_size") . "). Envie um arquivo menor ou aumente o limite no php.ini."]];
} elseif ($recurso === "instalacao") {
    // a instalação: a situação (o banco, se está vazio e em dia, quantos usuários) e instalar, só num banco vazio
    if ($escrita && $acao === "instalar") {
        $saida = instalar_banco();
        $codigo = $saida["ok"] ? 200 : 400;
    } elseif ($escrita) {
        $codigo = 400;
        $saida = ["ok" => false, "mensagem" => "", "erros" => ["Ação desconhecida: use instalar."]];
    } else {
        $saida = instalacao_situacao();
    }
} elseif ($recurso === "entrar") {
    // o login das páginas: quem chega sem login recebeu o 401 acima (o navegador pede o usuário e a senha); com o login
    // certo, volta para a página de onde veio (volta=index.php?r=3, só uma página do sistema) ou diz quem entrou
    $volta = (string)($_REQUEST["volta"] ?? "");
    if (preg_match("/^[a-z_]+\\.php(\\?[^\\s#]*)?(#\\S*)?\$/", $volta) !== 1) {
        $volta = "";
    }
    if ($volta !== "" && !$escrita) {
        $codigo = 302;
        header("Location: " . $volta);
    }
    $saida = ["usuario" => $quem !== "" ? $quem : null, "por" => $quem !== "" ? "login" : "token", "volta" => $volta !== "" ? $volta : null];
} elseif ($recurso === "migracoes" && $banco_vazio) {
    $codigo = 503;
    $saida = ["erro" => "o banco está vazio: instale (POST recurso=instalacao, acao=instalar)", "proximo_passo" => instalacao_situacao()["proximo_passo"]];
} elseif ($recurso === "migracoes") {
    // as migrações: a lista das pendentes, e aplicar (a única escrita aceita com o banco desatualizado)
    if ($escrita && $acao === "aplicar") {
        $aplicadas = [];
        foreach ($pendentes as $versao => $m) {
            // os comandos do arquivo, um a um, cada um traduzido para o banco em uso (os comentários saem)
            banco_script((string)file_get_contents(__DIR__ . "/" . $m[0]));
            // o passo em PHP da migração, se ela tem (o que o SQL comum aos três bancos não faz bem)
            if (isset($m[3])) {
                call_user_func($m[3]);
            }
            $aplicadas[] = $versao . " (" . $m[1] . ")";
        }
        $saida = ["ok" => true, "mensagem" => count($aplicadas) > 0 ? "Aplicadas: " . implode("; ", $aplicadas) . "." : "Nada a aplicar: o banco está em dia.", "erros" => [], "aplicadas" => $aplicadas];
    } elseif ($escrita) {
        $codigo = 400;
        $saida = ["ok" => false, "mensagem" => "", "erros" => ["Ação desconhecida: use aplicar."]];
    } else {
        $saida = ["pendentes" => array_map(function ($v, $m) {
            return ["versao" => $v, "arquivo" => $m[0], "traz" => $m[1]];
        }, array_keys($pendentes), array_values($pendentes))];
    }
} elseif ($banco_vazio && !($recurso === "manual" || ($recurso === "ajuda" && ($_REQUEST["parte"] ?? "") === "manual"))) {
    $codigo = 503;
    $saida = ["erro" => "o banco está vazio: instale (POST recurso=instalacao, acao=instalar)", "proximo_passo" => instalacao_situacao()["proximo_passo"]];
} elseif (count($pendentes) > 0 && !($recurso === "manual" || ($recurso === "ajuda" && ($_REQUEST["parte"] ?? "") === "manual"))) {
    // banco desatualizado: só as migrações respondem (e o manual, que não usa o banco: a página Ajuda continua abrindo)
    $codigo = 503;
    $saida = ["erro" => "o banco está desatualizado: aplique as migrações (POST recurso=migracoes, acao=aplicar)", "pendentes" => array_map(function ($v, $m) {
        return ["versao" => $v, "arquivo" => $m[0], "traz" => $m[1]];
    }, array_keys($pendentes), array_values($pendentes))];
} elseif ($escrita) {
    $ops = ["arvore" => "op_arvore", "campos" => "op_campos", "lancamento_tipos" => "op_lancamento_tipos", "formulas" => "op_formulas", "relogio" => "op_relogio",
        "lancamento" => "op_lancamento", "avisos" => "op_avisos", "criterios" => "op_criterios",
        "rodizio" => "op_rodizio", "modos" => "op_modos", "config" => "op_config", "documentos" => "op_documentos", "documento_categorias" => "op_documento_categorias"];
    if (isset($ops[$recurso])) {
        $saida = $ops[$recurso]($acao, $d);
    } elseif ($recurso === "usuarios") {
        $saida = op_usuarios($acao, $d, $quem !== "" ? $quem : "(token da API)");
    } elseif ($recurso === "importacao") {
        // a importação do sistema anterior pode demorar (o histórico inteiro): sem o limite de tempo do PHP
        @set_time_limit(0);
        $saida = op_importacao($acao, $d);
    } else {
        $saida = ["ok" => false, "mensagem" => "", "erros" => ["este recurso não aceita escrita; os que aceitam: " . implode(", ", array_merge(array_keys($ops), ["usuarios", "importacao", "instalacao", "migracoes"]))]];
    }
    $codigo = $saida["ok"] ? 200 : 400;
} elseif ($recurso === "importacao") {
    $saida = importacao_situacao();
} elseif ($recurso === "foto") {
    $f = linha("SELECT tipo, dados FROM foto WHERE relogio_id = ?", [(int)($_REQUEST["relogio"] ?? 0)]);
    if ($f) {
        header("Content-Type: " . $f["tipo"]);
        header("Cache-Control: private, max-age=86400");
        echo $f["dados"];
        exit;
    }
    $codigo = 404;
    $saida = ["erro" => "sem foto para esse relógio"];
} elseif ($recurso === "documento") {
    // o arquivo de um documento (não é JSON): baixar=1 força o download; mini=1, a miniatura da foto (sem ela, a foto)
    $doc = linha("SELECT * FROM documento WHERE id = ?", [(int)($_REQUEST["id"] ?? 0)]);
    $pasta = documentos_pasta();
    $mini = ($_REQUEST["mini"] ?? "") === "1" && $doc && $doc["miniatura"] !== null && $doc["miniatura"] !== "";
    $caminho = $doc && $pasta !== null ? $pasta . "/" . ($mini ? $doc["miniatura"] : $doc["arquivo"]) : null;
    // o arquivo sumiu da pasta: volta da cópia no banco antes de sair
    if ($caminho !== null && strpos($caminho, "..") === false && !is_file($caminho)) {
        documento_restaurar($doc);
    }
    if ($caminho !== null && strpos($caminho, "..") === false && is_file($caminho)) {
        documento_enviar($doc, $caminho, ($_REQUEST["baixar"] ?? "") === "1", $mini);
        exit;
    }
    $codigo = 404;
    $saida = ["erro" => $doc ? "o arquivo do documento não está na pasta dos documentos" . ($pasta === null ? " (" . documentos_pasta_erro() . ")" : ((int)$doc["no_banco"] === 1 ? " e a cópia no banco não pôde ser recriada" : " e não tem cópia no banco")) : "documento não encontrado"];
} elseif ($recurso === "documentos") {
    // os documentos de um relógio (relogio=<id>; sem ele, de todos), com as categorias, os relógios, a pasta e o limite
    $rid = (int)($_REQUEST["relogio"] ?? 0);
    $r = $rid > 0 ? linha("SELECT id, nome, copia_banco FROM relogio WHERE id = ?", [$rid]) : null;
    if ($rid > 0 && !$r) {
        $codigo = 404;
        $saida = ["erro" => "relógio não encontrado"];
    } else {
        $saida = ["relogio" => $r ? ["id" => (int)$r["id"], "nome" => $r["nome"], "copia_banco" => (int)$r["copia_banco"] === 1] : null, "pasta_ok" => documentos_pasta() !== null,
            "copia_sistema" => documentos_copia_sistema(),
            "pasta_erro" => documentos_pasta_erro() !== "" ? documentos_pasta_erro() : null, "limite" => documentos_limite(),
            "categorias" => documento_categorias_lista($r ? $rid : null),
            "relogios" => array_map(function ($x) { return ["id" => (int)$x["id"], "nome" => $x["nome"], "documentos" => (int)$x["documentos"]]; },
                linhas("SELECT r.id, r.nome, (SELECT COUNT(*) FROM documento d WHERE d.relogio_id = r.id) AS documentos FROM relogio r ORDER BY r.nome")),
            "documentos" => array_map("documento_info", documentos_do_relogio($r ? $rid : null))];
    }
} elseif ($recurso === "criterios") {
    $saida = montar_criterios();
} elseif ($recurso === "avisos") {
    // os avisos de todos os relógios agora, do mais urgente (ou mais atrasado) para o mais distante
    $todos = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
        foreach (avisos_do_relogio($r, time()) as $a) {
            $todos[] = array_merge(["relogio_id" => (int)$r["id"], "relogio" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1], $a);
        }
    }
    usort($todos, function ($x, $y) {
        return $x["falta_dias"] <=> $y["falta_dias"];
    });
    $saida = ["avisos" => $todos];
} elseif ($recurso === "autonomia") {
    // as autonomias de cada relógio, enxuto e rápido, para sistemas de fora (relogio=ids: só esses)
    $agora = time();
    $ids_a = array_values(array_filter(array_map("intval", explode(",", (string)($_REQUEST["relogio"] ?? "")))));
    $lista_a = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
        if (count($ids_a) === 0 || in_array((int)$r["id"], $ids_a, true)) {
            $lista_a[] = array_merge(["id" => (int)$r["id"], "nome" => $r["nome"], "lugar" => no_caminho($r["no_id"]), "disponivel" => (int)$r["disponivel"] === 1],
                autonomia_do_relogio($r, $agora));
        }
    }
    $saida = ["calculado_em_unixtimestamp" => $agora, "relogios" => $lista_a];
} elseif ($recurso === "hoje") {
    // tudo o que a página Hoje mostra: o dia, os avisos de hoje, os próximos dias, o modo de rodízio e a tabela dos relógios
    $hoje = new DateTimeImmutable("today");
    garantir_plano($hoje);
    sessao_do_dia(time());
    $agora = time();
    $dia = plano_do_dia($hoje->format("Y-m-d"));
    $n = nos_todos();
    $modo = linha("SELECT * FROM modo WHERE id = ?", [modo_ativo()]);
    $pendentes_h = [];
    $avisos_h = [];
    foreach (avisos_de_hoje() as $x) {
        $pendentes_h[(int)$x[0]["id"]] = true;
        $t = $x[1]["resolve"] !== null ? (lancamento_tipos()[$x[1]["resolve"]] ?? null) : null;
        $avisos_h[] = ["relogio_id" => (int)$x[0]["id"], "relogio" => $x[0]["nome"], "identificador" => $x[1]["identificador"], "nome" => $x[1]["nome"], "texto" => $x[1]["texto"],
            "estado" => $x[1]["estado"], "resolve" => $t ? ["identificador" => $t["identificador"], "nome" => $t["nome"], "formato" => $t["formato"], "unidade" => $t["unidade"]] : null];
    }
    $fotos = [];
    foreach (linhas("SELECT relogio_id, atualizado FROM foto") as $f) {
        $fotos[(int)$f["relogio_id"]] = strtotime($f["atualizado"]);
    }
    $rels = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
        $v = visao_relogio($r, $agora);
        $val = valores_do_relogio((int)$r["id"]);
        $leitura = linha("SELECT l.inicio, l.valor, t.unidade FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.formato = 'valor'
            ORDER BY l.inicio DESC, l.id DESC LIMIT 1", [(int)$r["id"]]);
        $rels[] = ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1, "tipo" => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "—",
            "em_uso" => $v["em_uso"], "agora" => $v["texto"], "carga" => (int)$r["disponivel"] === 1 ? $v["energia"] : null, "carga_de" => $v["de"], "ultimo" => $v["ultimo"],
            "ultimo_txt" => $v["em_uso"] ? "agora · " . date("H:i", $agora) : ($v["ultimo"] > 0 ? date(date("Y", $v["ultimo"]) === date("Y", $agora) ? "d/m H:i" : "d/m/Y H:i", $v["ultimo"]) : "nunca"),
            "situacao" => $v["situacao"], "manutencao" => $v["prox"] ? ["nome" => $v["prox"]["nome"], "data" => max($hoje->format("Y-m-d"), substr($v["prox"]["data"], 0, 10)),
                "momento" => $v["prox_momento"], "falta" => $v["prox_falta"]] : null,
            "compra" => ["data" => $val["data_compra"] ?? null, "valor" => isset($val["valor_compra"]) ? (float)$val["valor_compra"] : null, "loja" => $val["loja"] ?? null,
                "garantia_ate" => $val["garantia_ate"] ?? null],
            "leitura" => $leitura ? ["inicio" => $leitura["inicio"], "valor" => (float)$leitura["valor"], "unidade" => $leitura["unidade"]] : null,
            "de_hoje" => $dia !== null && (int)$dia["relogio_id"] === (int)$r["id"], "com_aviso" => isset($pendentes_h[(int)$r["id"]]), "foto" => $fotos[(int)$r["id"]] ?? null];
    }
    $modos_h = [];
    foreach (linhas("SELECT * FROM modo ORDER BY ordem, id") as $m) {
        $modos_h[] = ["id" => (int)$m["id"], "nome" => $m["nome"], "selecao" => $m["selecao"], "escala_dias" => $m["escala_dias"] === null ? null : (int)$m["escala_dias"], "ciclo" => (int)$m["ciclo"] === 1,
            "blocos" => array_map(function ($b) {
                return ["id" => (int)$b["id"], "nome" => $b["nome"], "dias" => $b["dias"], "no_id" => $b["no_id"] === null ? 0 : (int)$b["no_id"], "um_por" => $b["um_por"],
                    "relogio_id" => $b["relogio_id"] === null ? null : (int)$b["relogio_id"]];
            }, modo_blocos((int)$m["id"]))];
    }
    $seg = $hoje->modify("next monday");
    $avisos_nomes = [];
    foreach (avisos_todos() as $versoes) {
        $avisos_nomes[] = $versoes[0]["nome"];
    }
    $saida = ["data" => $hoje->format("Y-m-d"), "agora" => $agora, "uso_inicio" => cfg("uso_inicio"), "comecou" => $dia !== null && dia_comecou($agora),
        "pulso_poe_sozinho" => pulso_poe_sozinho(), "pulso_tira_sozinho" => pulso_tira_sozinho(),
        "modo" => $modo ? ["id" => (int)$modo["id"], "nome" => $modo["nome"], "selecao" => $modo["selecao"], "escala_dias" => $modo["escala_dias"] === null ? null : (int)$modo["escala_dias"]] : null,
        "escala_fim" => cfg("escala_fim") !== "" ? cfg("escala_fim") : null, "max_sem_uso" => (int)cfg("max_sem_uso"),
        "dia" => $dia ? ["data" => $dia["data"], "relogio_id" => (int)$dia["relogio_id"], "relogio" => $dia["nome"], "ate" => texto_ate($dia["data"], "hoje"), "acao" => $dia["acao"], "motivo" => $dia["motivo"]] : null,
        "avisos" => $avisos_h,
        "plano" => array_map(function ($p) { return ["data" => $p["data"], "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["nome"], "acao" => $p["acao"], "motivo" => $p["motivo"], "origem" => $p["origem"]]; },
            linhas("SELECT p.*, r.nome FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data >= ? ORDER BY p.data LIMIT 62", [$hoje->format("Y-m-d")])),
        "proxima_semana" => ["segunda" => $seg->format("Y-m-d"), "domingo" => $seg->modify("+6 days")->format("Y-m-d"),
            "ja_montada" => (int)valor("SELECT COUNT(*) FROM plano WHERE data BETWEEN ? AND ?", [$seg->format("Y-m-d"), $seg->modify("+6 days")->format("Y-m-d")]) > 1],
        "modos" => $modos_h,
        "grupos" => array_map(function ($o) use ($n) { return ["id" => $o[0], "nome" => $n[$o[0]]["nome"], "profundidade" => $o[1], "caminho" => no_caminho($o[0])]; }, nos_em_ordem()),
        "avisos_nomes" => array_values(array_unique($avisos_nomes)),
        "relogios" => $rels,
        // os totais da coleção inteira (a tabela da página soma só o que os filtros dela deixam à mostra)
        "totais" => ["relogios" => count($rels), "disponiveis" => count(array_filter($rels, function ($r) { return $r["disponivel"]; })),
            "em_uso" => count(array_filter($rels, function ($r) { return $r["em_uso"]; })),
            "valor" => round(array_sum(array_map(function ($r) { return $r["compra"]["valor"] ?? 0; }, $rels)), 2)]];
} elseif ($recurso === "ficha") {
    // tudo o que o painel de um relógio mostra (relogio=<id>); sem relogio, só o que o cadastro de um relógio novo precisa
    $hoje = new DateTimeImmutable("today");
    $agora = time();
    $n = nos_todos();
    $r = linha("SELECT * FROM relogio WHERE id = ?", [(int)($_REQUEST["relogio"] ?? 0)]);
    $valores = $r ? valores_do_relogio((int)$r["id"]) : [];
    $campos = [];
    foreach (campos_todos() as $ident => $c) {
        $campos[] = ["identificador" => $ident, "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "opcoes" => array_values(array_filter(array_map("trim", explode("\n", (string)$c["opcoes"])))),
            "padrao" => $c["padrao"], "no_id" => $c["no_id"] === null ? 0 : (int)$c["no_id"], "valor" => $valores[$ident] ?? null];
    }
    $saida = ["hoje" => $hoje->format("Y-m-d"), "relogio" => null, "copia_sistema" => documentos_copia_sistema(), "painel_modo" => painel_modo(), "campos" => $campos,
        "grupos" => array_map(function ($o) use ($n) { return ["id" => $o[0], "caminho" => no_caminho($o[0]), "cadeia" => no_cadeia($o[0])]; }, nos_em_ordem())];
    if ((int)($_REQUEST["relogio"] ?? 0) > 0 && !$r) {
        $codigo = 404;
        $saida = ["erro" => "relógio não encontrado"];
    } elseif ($r) {
        $id = (int)$r["id"];
        $v = visao_relogio($r, $agora);
        $proxima = valor("SELECT data FROM plano WHERE relogio_id = ? AND data >= ? ORDER BY data LIMIT 1", [$id, $hoje->format("Y-m-d")]);
        $abertas = [];
        foreach ($v["abertas"] as $a) {
            $abertas[$a["tipo"]] = $a;
        }
        $tipos = [];
        foreach (lancamento_tipos_do_relogio($r) as $ident => $t) {
            if (substr($ident, -7) !== "_antigo") {
                $tipos[] = ["identificador" => $ident, "nome" => $t["nome"], "formato" => $t["formato"], "unidade" => $t["unidade"], "mede_gasto" => (int)$t["mede_gasto"] === 1,
                    "aberta" => isset($abertas[$ident]) ? ["inicio" => date("Y-m-d H:i:s", $abertas[$ident]["inicio"]), "rodizio" => $abertas[$ident]["origem"] === "rodizio",
                        "texto" => ($abertas[$ident]["origem"] === "rodizio" ? "no rodízio " : "") . "desde " . date("H:i", $abertas[$ident]["inicio"]) . " ("
                            . intdiv((int)floor(($agora - $abertas[$ident]["inicio"]) / 60), 60) . " h " . ((int)floor(($agora - $abertas[$ident]["inicio"]) / 60) % 60) . " min)"] : null];
            }
        }
        $leituras = [];
        foreach (array_reverse(linhas("SELECT l.inicio, l.valor, t.unidade FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
            WHERE l.relogio_id = ? AND t.formato = 'valor' ORDER BY l.inicio DESC, l.id DESC LIMIT 40", [$id])) as $l) {
            $leituras[] = ["inicio" => $l["inicio"], "valor" => (float)$l["valor"], "unidade" => $l["unidade"],
                "em_uso" => valor("SELECT l.id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = 'pulso'
                    AND l.inicio <= ? AND COALESCE(l.fim, ?) >= ? LIMIT 1", [$id, $l["inicio"], $l["inicio"], $l["inicio"]]) !== null];
        }
        $lt = linha_do_tempo($id, 0, PHP_INT_MAX, []);
        $foto = valor("SELECT atualizado FROM foto WHERE relogio_id = ?", [$id]);
        $nota = (int)$r["disponivel"] === 1 ? nota_do_relogio($r, $agora) : null;
        $saida["relogio"] = ["id" => $id, "nome" => $r["nome"], "no_id" => $r["no_id"] === null ? 0 : (int)$r["no_id"], "disponivel" => (int)$r["disponivel"] === 1,
            "copia_banco" => (int)$r["copia_banco"] === 1,
            "tipo" => isset($n[(int)$r["no_id"]]) ? $n[(int)$r["no_id"]]["nome"] : "sem grupo", "caminho" => no_caminho($r["no_id"]), "observacao" => $valores["observacao"] ?? null,
            "foto" => $foto !== null ? strtotime($foto) : null, "documentos" => (function () use ($id) {
                $cats = array_values(array_filter(documento_categorias_lista($id), function ($c) { return $c["documentos"] > 0; }));
                return ["total" => array_sum(array_column($cats, "documentos")), "categorias" => array_map(function ($c) { return ["id" => $c["id"], "nome" => $c["nome"], "documentos" => $c["documentos"]]; }, $cats),
                    "pasta_ok" => documentos_pasta() !== null, "copia_sistema" => documentos_copia_sistema()];
            })(), "em_uso" => $v["em_uso"], "agora" => $v["texto"], "carga" => $v["energia"], "carga_de" => $v["de"], "situacao" => $v["situacao"],
            "nota" => $nota ? ["nota" => $nota["nota"], "conjunto_texto" => $nota["conjunto_texto"]] : null,
            "proxima" => $proxima, "proxima_ate" => $proxima !== null ? texto_ate($proxima, "neste dia") : null,
            "escala_fim" => valor("SELECT escala_dias FROM modo WHERE id = ?", [modo_ativo()]) !== null ? cfg("escala_fim") : null,
            "compra" => ["data" => $valores["data_compra"] ?? null, "valor" => isset($valores["valor_compra"]) ? (float)$valores["valor_compra"] : null, "loja" => $valores["loja"] ?? null,
                "garantia_ate" => $valores["garantia_ate"] ?? null],
            "manutencoes" => array_map(function ($a) use ($hoje) { return ["data" => max($hoje->format("Y-m-d"), substr($a["data"], 0, 10)), "nome" => $a["nome"]]; }, array_slice($v["avisos"], 0, 5)),
            "previsao" => previsao_energia($r, $agora), "tipos" => $tipos, "leituras" => $leituras,
            "medicoes" => medicoes_legivel($id), "medicao_janela_dias" => max(1, (int)cfg("medicao_janela_dias")),
            // os lançamentos dos últimos 14 dias (e a sessão ainda aberta), para corrigir: o quadro "Corrigir marcações"
            "lancamentos_recentes" => array_map(function ($l) {
                return ["id" => (int)$l["id"], "tipo" => $l["identificador"], "nome" => $l["nome"], "formato" => $l["formato"], "unidade" => $l["unidade"],
                    "inicio" => $l["inicio"], "fim" => $l["fim"], "valor" => $l["valor"] === null ? null : (float)$l["valor"], "origem" => $l["origem"]];
            }, linhas("SELECT l.id, t.identificador, t.nome, t.formato, t.unidade, l.inicio, l.fim, l.valor, l.origem FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id
                WHERE l.relogio_id = ? AND l.inicio <= ? AND (l.inicio >= ? OR l.fim IS NULL OR l.fim >= ?) ORDER BY l.inicio DESC, l.id DESC LIMIT 40",
                [$id, date("Y-m-d H:i:s", $agora), date("Y-m-d H:i:s", $agora - 14 * 86400), date("Y-m-d H:i:s", $agora - 14 * 86400)])),
            "linha_do_tempo" => array_map("linha_legivel", array_slice($lt["linhas"], 0, 5)), "registros" => count($lt["linhas"]),
            "desde" => count($lt["linhas"]) > 0 ? date("Y-m-d H:i:s", $lt["linhas"][count($lt["linhas"]) - 1]["inicio"]) : null];
        // os dados do relógio: os campos do cadastro que valem para ele (o informado, senão o padrão) e o resultado de cada fórmula
        $dados = [];
        foreach (campos_do_relogio($r) as $ident => $c) {
            $dados[] = ["identificador" => $ident, "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "valor" => $valores[$ident] ?? null,
                "valor_usado" => ($valores[$ident] ?? "") !== "" ? $valores[$ident] : $c["padrao"],
                "origem" => ($valores[$ident] ?? "") !== "" ? "informado" : ($c["padrao"] !== null && $c["padrao"] !== "" ? "padrão" : "vazio")];
        }
        $calculos = [];
        foreach (formulas_todas() as $ident => $versoes) {
            $f = mais_perto($versoes, $r);
            if ($f !== null) {
                $cf = ["r" => $r, "momento" => $agora, "rastro" => [], "pilha" => [], "valores" => $valores];
                $calculos[] = ["identificador" => $ident, "nome" => $f["nome"], "unidade" => $f["unidade"], "valor" => variavel_valor($ident, $cf), "versao" => no_caminho($f["no_id"])];
            }
        }
        $saida["relogio"] = array_merge($saida["relogio"], ["autonomia" => autonomia_do_relogio($r, $agora), "dados" => $dados, "calculos" => $calculos]);
    }
} elseif ($recurso === "config") {
    // tudo o que a página Configuração mostra: os valores, os canais (os avisos de cada um, a mensagem padrão e as
    // personalizadas), os eventos personalizados, a situação do cron e como as mensagens e a agenda saem hoje
    $hoje = new DateTimeImmutable("today");
    garantir_plano($hoje);
    $valores_c = [];
    foreach (linhas("SELECT chave, valor FROM config ORDER BY chave") as $c) {
        $valores_c[$c["chave"]] = $c["valor"];
    }
    $caminho = cfg("agenda_chave") !== "" ? cfg("agenda_chave") : __DIR__ . "/google-conta-servico.json";
    $json_c = is_file($caminho) && is_readable($caminho) ? json_decode((string)file_get_contents($caminho), true) : null;
    $tipos_c = [];
    foreach (tipos_de_aviso() as $k => $t) {
        $canais_t = [];
        foreach ($CANAIS as $canal => $c) {
            $l = canal_aviso($canal, $k);
            $canais_t[$canal] = ["marcado" => $l["envia"], "proprio" => $l["propria"], "corpo" => $l["corpo"]];
        }
        $tipos_c[] = ["tipo" => $k, "nome" => $t[0], "quando" => $t[1], "evento" => strpos($k, "ev") === 0 && ctype_digit(substr($k, 2)) ? (int)substr($k, 2) : null, "canais" => $canais_t];
    }
    $previa = [];
    foreach (agenda_desejada($hoje) as $m) {
        if (count($previa) < 8) {
            $previa[] = array_merge(["data" => $m["data"], "hora" => $m["hora"]], evento_formatado($m));
        }
    }
    $saida = ["config" => (object)$valores_c, "sol_fim" => isset(lancamento_tipos()["sol"]) && lancamento_tipos()["sol"]["fecha_as"] !== null ? substr(lancamento_tipos()["sol"]["fecha_as"], 0, 5) : "",
        "chave_agenda" => is_array($json_c) && !empty($json_c["private_key"]) && !empty($json_c["client_email"]) ? ["client_email" => $json_c["client_email"]] : null,
        "pasta" => __DIR__, "canais" => $CANAIS, "ancoras" => $ANCORAS, "repeticoes" => $REPETICOES, "tipos" => $tipos_c,
        "eventos" => array_map(function ($ev) {
            return array_merge(evento_legivel($ev), ["proximas_60" => array_map(function ($t) { return date("Y-m-d H:i:s", $t); },
                array_slice(ocorrencias($ev, time(), time() + 60 * 86400), 0, 5))]);
        }, eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome"))),
        "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1]; },
            linhas("SELECT id, nome, disponivel FROM relogio ORDER BY nome")),
        "previa" => ["manha" => montar_mensagem($hoje), "noite" => montar_mensagem_noite($hoje), "agenda" => $previa,
            "sincronizados" => (int)valor("SELECT COUNT(*) FROM agenda_evento")], "cron_estado" => cron_estado()];
} elseif ($recurso === "historico") {
    // os relógios pedidos (relogio=3 ou 1,2; relogio_id também vale); sem nenhum, todos
    $ids_h = array_values(array_filter(array_map("intval", array_merge(explode(",", (string)($_REQUEST["relogio"] ?? "")), explode(",", (string)($_REQUEST["relogio_id"] ?? ""))))));
    $rels_h = array_values(array_filter(linhas("SELECT id, nome FROM relogio ORDER BY nome"), function ($x) use ($ids_h) {
        return count($ids_h) === 0 || in_array((int)$x["id"], $ids_h, true);
    }));
    // o período: data (de às 00:00:00, ate às 23:59:59) ou data e hora; o que não entende vai para "ignorados"
    $per = [0, PHP_INT_MAX];
    $filtro = ["de" => null, "ate" => null];
    $ignorados = [];
    foreach (["de" => 0, "ate" => 1] as $campo => $pos) {
        $v = str_replace("T", " ", trim((string)($_REQUEST[$campo] ?? "")));
        if ($v !== "") {
            if (preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2}(:[0-9]{2})?)?\$/", $v) === 1 && strtotime($v) !== false) {
                $v .= strlen($v) === 10 ? ($campo === "de" ? " 00:00:00" : " 23:59:59") : (strlen($v) === 16 ? ($campo === "de" ? ":00" : ":59") : "");
                $per[$pos] = $campo === "de" ? strtotime($v) : strtotime($v) + 1;
                $filtro[$campo] = $v;
            } else {
                $ignorados[] = $campo . " = " . $v . " (formato: AAAA-MM-DD ou AAAA-MM-DD HH:MM)";
            }
        }
    }
    $estados_h = array_values(array_intersect(array_filter(array_map("trim", explode(",", (string)($_REQUEST["estado"] ?? "") . "," . (string)($_REQUEST["linha_estado"] ?? "")))),
        array_keys(estados_da_linha())));
    $linhas_h = [];
    $resumos = [];
    foreach ($rels_h as $x) {
        $f_h = linha_do_tempo((int)$x["id"], $per[0], $per[1], $estados_h);
        foreach ($f_h["linhas"] as $l) {
            $linhas_h[] = array_merge(["relogio_id" => (int)$x["id"], "relogio" => $x["nome"], "ts" => $l["inicio"]], linha_legivel($l));
        }
        $todas = linha_do_tempo((int)$x["id"], 0, PHP_INT_MAX, [])["linhas"];
        $rx = linha("SELECT no_id FROM relogio WHERE id = ?", [(int)$x["id"]]);
        $foto_h = valor("SELECT atualizado FROM foto WHERE relogio_id = ?", [(int)$x["id"]]);
        $resumos[(string)$x["id"]] = array_merge(["relogio" => $x["nome"], "tipo" => isset(nos_todos()[(int)$rx["no_id"]]) ? nos_todos()[(int)$rx["no_id"]]["nome"] : "sem grupo",
            "foto" => $foto_h !== null ? strtotime($foto_h) : null, "registros" => count($todas),
            "desde" => count($todas) > 0 ? date("Y-m-d H:i:s", $todas[count($todas) - 1]["inicio"]) : null], resumo_do_tempo($f_h));
    }
    $asc = ($_REQUEST["ordem"] ?? "") === "asc";
    usort($linhas_h, function ($a, $b) use ($asc) { return $asc ? $a["ts"] <=> $b["ts"] : $b["ts"] <=> $a["ts"]; });
    $por_h = max(1, min(1000, (int)($_REQUEST["limite"] ?? 100)));
    $pag_h = max(1, (int)($_REQUEST["pagina"] ?? 1));
    $ids_rel = array_map("intval", array_column($rels_h, "id"));
    $saida = ["filtro" => ["relogios" => $ids_rel, "de" => $filtro["de"], "ate" => $filtro["ate"], "estados" => $estados_h, "ordem" => $asc ? "asc" : "desc", "ignorados" => $ignorados],
        "estados" => estados_da_linha(), "total" => count($linhas_h), "pagina" => $pag_h, "por_pagina" => $por_h, "paginas" => max(1, (int)ceil(count($linhas_h) / $por_h)),
        "resumo" => (object)$resumos,
        "linhas" => array_map(function ($l) { unset($l["ts"]); return $l; }, array_slice($linhas_h, ($pag_h - 1) * $por_h, $por_h)),
        "lancamentos" => count($ids_rel) === 0 ? [] : linhas("SELECT l.id, l.relogio_id, r.nome AS relogio, t.identificador AS tipo, l.inicio, l.fim, l.valor, l.origem
            FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id JOIN relogio r ON r.id = l.relogio_id WHERE l.relogio_id IN (" . implode(",", $ids_rel) . ")
            AND l.inicio < ? AND COALESCE(l.fim, l.inicio) >= ? ORDER BY l.inicio " . ($asc ? "ASC, l.id ASC" : "DESC, l.id DESC") . " LIMIT " . $por_h,
            [date("Y-m-d H:i:s", min($per[1], 253402300799)), date("Y-m-d H:i:s", $per[0])])];
} elseif ($recurso === "previsao") {
    $ids_p = array_values(array_filter(array_map("intval", explode(",", (string)($_REQUEST["relogio"] ?? "")))));
    $prev = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
        if (count($ids_p) === 0 || in_array((int)$r["id"], $ids_p, true)) {
            $prev[] = array_merge(["relogio_id" => (int)$r["id"], "relogio" => $r["nome"]], previsao_energia($r, time()));
        }
    }
    $saida = ["previsoes" => $prev];
} elseif ($recurso === "plano") {
    $de_p = preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}\$/", (string)($_REQUEST["de"] ?? "")) === 1 ? (string)$_REQUEST["de"] : "";
    $ate_p = preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}\$/", (string)($_REQUEST["ate"] ?? "")) === 1 ? (string)$_REQUEST["ate"] : "";
    // dias=N: os próximos N dias, de hoje em diante (no lugar de de e ate); dias=todos: de hoje até o fim do plano
    $dias_p = strtolower(trim((string)($_REQUEST["dias"] ?? "")));
    if ($dias_p === "todos") {
        $de_p = date("Y-m-d");
        $ate_p = "";
    } elseif (ctype_digit($dias_p) && (int)$dias_p > 0) {
        $de_p = date("Y-m-d");
        $ate_p = date("Y-m-d", strtotime("+" . ((int)$dias_p - 1) . " days"));
    }
    $plano_p = plano_legivel($de_p, $ate_p);
    $saida = ["modo" => valor("SELECT nome FROM modo WHERE id = ?", [modo_ativo()]), "escala_fim" => cfg("escala_fim") !== "" ? cfg("escala_fim") : null,
        "plano_fim" => valor("SELECT MAX(data) FROM plano"), "hoje" => date("Y-m-d"), "plano" => $plano_p, "resumo" => plano_resumo($plano_p, date("Y-m-d")),
        "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1]; },
            linhas("SELECT id, nome, disponivel FROM relogio ORDER BY nome"))];
} elseif ($recurso === "eventos") {
    $saida = ["eventos" => array_map("evento_legivel", eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome")))];
} elseif ($recurso === "agenda") {
    $saida = ["ativa" => cfg("agenda_ativa") === "1", "agenda_id" => cfg("agenda_id"), "janela_dias" => (int)cfg("agenda_antecedencia"),
        "chave_ok" => cfg("agenda_id") !== "" ? google_token() !== false : null, "desejados" => agenda_legivel(),
        "criados" => linhas("SELECT chave, google_id, data, titulo, assinatura, criado FROM agenda_evento ORDER BY data, chave")];
} elseif ($recurso === "usuarios") {
    $saida = ["usuarios" => usuarios_catalogo(), "voce" => $quem];
} elseif ($recurso === "cron") {
    // as execuções do cron, com filtro de período (cron_de e cron_ate, ou de e ate: data, ou data e hora), situação
    // (atividade, erro, nada ou todas; padrão: atividade) e texto no registro (cron_busca ou busca), resumo do período e páginas
    $per_c = [];
    foreach (["de" => "00:00:00", "ate" => "23:59:59"] as $k => $hora) {
        $v = str_replace("T", " ", trim((string)($_REQUEST["cron_" . $k] ?? ($_REQUEST[$k] ?? ""))));
        $per_c[$k] = preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2}(:[0-9]{2})?)?\$/", $v) === 1
            ? $v . (strlen($v) === 10 ? " " . $hora : (strlen($v) === 16 ? ($k === "de" ? ":00" : ":59") : "")) : ($k === "de" ? "0001-01-01 00:00:00" : "9999-12-31 23:59:59");
    }
    $situacao = in_array($_REQUEST["cron_situacao"] ?? ($_REQUEST["situacao"] ?? ""), ["atividade", "erro", "nada", "todas"], true) ? ($_REQUEST["cron_situacao"] ?? $_REQUEST["situacao"]) : "atividade";
    $busca = trim((string)($_REQUEST["cron_busca"] ?? ($_REQUEST["busca"] ?? "")));
    $onde = "inicio BETWEEN ? AND ?";
    $params = [$per_c["de"], $per_c["ate"]];
    $resumo = linha("SELECT COUNT(*) AS total, COALESCE(SUM(teve_atividade), 0) AS atividade, COALESCE(SUM(teve_erro), 0) AS erros,
        COALESCE(MAX(duracao_ms), 0) AS mais_lenta FROM cron_execucao WHERE " . $onde, $params);
    $onde .= ["atividade" => " AND teve_atividade = 1", "erro" => " AND teve_erro = 1", "nada" => " AND teve_atividade = 0", "todas" => ""][$situacao];
    if ($busca !== "") {
        $onde .= " AND registro LIKE ?";
        $params[] = "%" . $busca . "%";
    }
    $total = (int)valor("SELECT COUNT(*) FROM cron_execucao WHERE " . $onde, $params);
    $por = max(1, min(1000, (int)($_REQUEST["limite"] ?? 100)));
    $paginas = max(1, (int)ceil($total / $por));
    $pagina = min($paginas, max(1, (int)($_REQUEST["pagina"] ?? 1)));
    $saida = ["filtro" => ["de" => $per_c["de"], "ate" => $per_c["ate"], "situacao" => $situacao, "busca" => $busca], "ultima" => cfg("cron_ultima_execucao"),
        "resumo" => ["total" => (int)$resumo["total"], "atividade" => (int)$resumo["atividade"], "erros" => (int)$resumo["erros"], "mais_lenta_ms" => (int)$resumo["mais_lenta"]],
        "total" => $total, "pagina" => $pagina, "por_pagina" => $por, "paginas" => $paginas,
        "execucoes" => array_map(function ($x) {
            return ["id" => (int)$x["id"], "inicio" => $x["inicio"], "fim" => $x["fim"], "duracao_ms" => (int)$x["duracao_ms"], "teve_atividade" => (int)$x["teve_atividade"] === 1,
                "teve_erro" => (int)$x["teve_erro"] === 1, "registro" => $x["registro"]];
        }, linhas("SELECT * FROM cron_execucao WHERE " . $onde . " ORDER BY inicio DESC, id DESC LIMIT " . $por . " OFFSET " . (($pagina - 1) * $por), $params))];
} elseif ($recurso === "arvore") {
    // a árvore (os grupos) em ordem, com os relógios direto em cada grupo e quantos parâmetros de critérios ele tem, e o grupo de cada relógio
    $n = nos_todos();
    $rels_a = linhas("SELECT id, nome, no_id, disponivel FROM relogio ORDER BY nome");
    $crit = [];
    foreach (linhas("SELECT escopo_no_id, COUNT(*) AS q FROM criterio_parametro WHERE escopo_no_id IS NOT NULL GROUP BY escopo_no_id") as $x) {
        $crit[(int)$x["escopo_no_id"]] = (int)$x["q"];
    }
    $saida = ["grupos" => array_map(function ($o) use ($n, $rels_a, $crit) {
        return ["id" => $o[0], "pai_id" => $n[$o[0]]["pai_id"] === null ? null : (int)$n[$o[0]]["pai_id"], "nome" => $n[$o[0]]["nome"], "profundidade" => $o[1], "caminho" => no_caminho($o[0]),
            "cadeia" => no_cadeia($o[0]), "subgrupos" => count(array_filter($n, function ($x) use ($o) { return (int)$x["pai_id"] === $o[0]; })),
            "relogios" => array_values(array_map(function ($r) { return $r["nome"]; }, array_filter($rels_a, function ($r) use ($o) { return (int)$r["no_id"] === $o[0]; }))),
            "criterios" => $crit[$o[0]] ?? 0];
    }, nos_em_ordem()), "relogios" => array_map(function ($r) {
        return ["id" => (int)$r["id"], "nome" => $r["nome"], "no_id" => $r["no_id"] === null ? 0 : (int)$r["no_id"], "disponivel" => (int)$r["disponivel"] === 1];
    }, $rels_a)];
} elseif ($recurso === "cadastros") {
    // tudo o que a página Cadastros mostra e edita: os campos, os tipos de lançamento (com quantos lançamentos cada um tem),
    // as fórmulas e os avisos (todas as versões), os modos com os blocos, os pontos da árvore, os relógios e as funções do motor
    $n = nos_todos();
    $com_id = function ($linhas) {
        return array_map(function ($x) {
            foreach (["id", "no_id", "ordem", "ativo", "exclusiva", "mede_gasto", "modo_id", "relogio_id", "escala_dias", "ciclo", "resolve_tipo_id"] as $k) {
                // a marca do modo em uso é 1 ou vazia (NULL) no banco: aqui, 1 ou 0
                if (array_key_exists($k, $x) && ($x[$k] !== null || ($k === "ativo" && array_key_exists("selecao", $x)))) {
                    $x[$k] = (int)$x[$k];
                }
            }
            return $x;
        }, $linhas);
    };
    $saida = ["campos" => $com_id(campos_com_opcoes(linhas("SELECT * FROM campo ORDER BY ordem, id"))),
        "lancamento_tipos" => $com_id(linhas("SELECT t.*, (SELECT COUNT(*) FROM lancamento l WHERE l.tipo_id = t.id) AS lancamentos FROM lancamento_tipo t ORDER BY t.ordem, t.id")),
        "formulas" => $com_id(linhas("SELECT * FROM formula ORDER BY identificador, id")),
        "avisos" => $com_id(linhas(AVISO_SELECT . " ORDER BY a.identificador, a.id")),
        "modos" => array_map(function ($m) use ($com_id) {
            $m = $com_id([$m])[0];
            $m["blocos"] = $com_id(modo_blocos($m["id"]));
            return $m;
        }, linhas("SELECT * FROM modo ORDER BY ordem, id")),
        "modo_ativo" => modo_ativo(), "max_sem_uso" => (int)cfg("max_sem_uso"),
        "grupos" => array_map(function ($o) { return ["id" => $o[0], "caminho" => no_caminho($o[0])]; }, nos_em_ordem()),
        "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"]]; }, linhas("SELECT id, nome FROM relogio ORDER BY nome")),
        "documento_categorias" => documento_categorias_lista(),
        "funcoes" => array_map(function ($f) { return $f[2]; }, $FUNCOES), "tipos_de_campo" => $TIPOS_CAMPO, "formatos_de_lancamento" => $FORMATOS_LANCAMENTO];
} elseif ($recurso === "calcular") {
    $expr = (string)($_REQUEST["expressao"] ?? "");
    // a mesma conferência das fórmulas gravadas: escrita, variáveis e tipos de lançamento
    $erros = formula_validar("", $expr);
    if (count($erros) > 0) {
        $codigo = 400;
        $saida = ["erro" => "a fórmula não pode ser calculada", "erros" => $erros];
    } else {
        $ids = array_filter(array_map("intval", explode(',', (string)($_REQUEST["relogio"] ?? ""))));
        $res = [];
        foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
            if (count($ids) === 0 || in_array((int)$r["id"], $ids, true)) {
                $ctx = ["r" => $r, "momento" => time(), "rastro" => [], "pilha" => [], "valores" => valores_do_relogio((int)$r["id"])];
                $valor = formula_calcular(formula_ler($expr), $ctx);
                $res[] = ["relogio_id" => (int)$r["id"], "relogio" => $r["nome"], "valor" => $valor, "partes" => (object)$ctx["rastro"]];
            }
        }
        $saida = ["expressao" => $expr, "resultados" => $res];
    }
} elseif ($recurso === "manual" || ($recurso === "ajuda" && ($_REQUEST["parte"] ?? "") === "manual")) {
    // o manual: o texto da página Ajuda (os trechos do README para quem usa), inteiro em markdown e seção por seção
    $md = manual_markdown();
    if ($md === "") {
        $codigo = 404;
        $saida = ["erro" => "o README.md não está na pasta do sistema"];
    } else {
        [$html_m, $sumario_m] = manual_html($md);
        $saida = ["fonte" => "README.md", "trechos" => array_map(function ($t) { return ["de" => $t[0], "ate" => $t[1]]; }, MANUAL_TRECHOS),
            "secoes" => manual_secoes($md), "sumario" => array_map(function ($x) {
                return ["nivel" => $x[0], "titulo" => html_entity_decode(strip_tags(manual_linha($x[1])), ENT_QUOTES, "UTF-8"), "ancora" => $x[2]];
            }, $sumario_m), "markdown" => $md, "html" => $html_m];
    }
} elseif ($recurso === "reconstrucao" || ($recurso === "ajuda" && ($_REQUEST["parte"] ?? "") === "reconstrucao")) {
    // a reconstrução: o roteiro para reescrever a aplicação do zero (o texto em reconstrucao.php; o modelo de dados e as
    // listas, lidos na hora do banco e do código)
    require_once __DIR__ . "/reconstrucao.php";
    $saida = reconstrucao();
} elseif ($recurso === "ajuda") {
    // a mesma lista que está no cabeçalho deste arquivo: cada consulta com os parâmetros e um exemplo; cada escrita com as ações,
    // os campos de cada uma e um exemplo
    $saida = [
        "sem_parametros" => "api.php devolve tudo o que está guardado, sem filtro: arvore, campos, lancamento_tipos, formulas, avisos, relogios (com os valores "
            . "dos campos, o resultado de cada fórmula, os avisos, os lançamentos, a previsão e a linha do tempo inteira com o resumo), modos, plano (inteiro), "
            . "config (inteira), eventos (com os disparos), agenda, criterios (com a nota de cada relógio), cron (todas as execuções guardadas), usuarios "
            . "(os logins), migracoes e motor, e as categorias dos documentos (documento_categorias). A senha dos usuários não existe no sistema: sai o hash dela (veja senhas). Os arquivos dos documentos não vêm (só os dados de cada um, em relogios[].documentos): eles saem pelo recurso=documento. "
            . "Para pegar só uma parte, ou filtrar, ordenar e paginar: veja filtros.",
        "autenticacao" => "token (cabeçalho X-Api-Token ou parâmetro token; o API_TOKEN do config.php, obrigatório, com pelo menos 10 caracteres) "
            . "ou o login do site (HTTP Basic), em todo pedido. Ex.: curl -H \"X-Api-Token: segredo\" http://servidor/relojoeiro/api.php?recurso=hoje; curl -u lucas:senha http://servidor/relojoeiro/api.php?recurso=hoje",
        "formato" => "JSON (padrão) ou XML: formato=xml, ou o cabeçalho Accept com xml. Ex.: http://servidor/relojoeiro/api.php?recurso=plano&formato=xml",
        "consulta" => [
            "(nenhum)" => ["descricao" => "tudo o que está guardado, sem filtro (os relógios com os campos, as fórmulas, os avisos, os lançamentos, a previsão e a linha do tempo; modos, plano, configuração, eventos, agenda e o motor)",
                "parametros" => ["foto" => "nao: sem as fotos em base64 (a resposta fica bem menor)"],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?foto=nao\""],
            "hoje" => ["descricao" => "tudo o que a página Hoje mostra: o dia e o relógio dele, os avisos de hoje (com o lançamento que resolve cada um), os próximos 62 dias do plano, os modos com os blocos, os grupos, a tabela dos relógios e os totais da coleção (quantos, disponíveis, em uso e o valor pago)",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=hoje\""],
            "ficha" => ["descricao" => "tudo o que o painel de um relógio mostra: agora, carga e de onde vem, situação, nota, quando entra no rodízio, compra, próximas manutenções, previsão, o gasto da bateria, quantos documentos o relógio tem em cada categoria, os tipos de lançamento dele (com a sessão aberta), as últimas leituras, as marcações dos últimos 14 dias, o resumo do histórico e o cadastro; sem relogio, só o cadastro de um relógio novo; relógio que não existe: 404",
                "parametros" => ["relogio" => "o id do relógio (vazio: o cadastro de um relógio novo)"],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=ficha&relogio=10\""],
            "config" => ["descricao" => "tudo o que a página Configuração mostra: os valores (inclusive o caminho da chave do Google), os canais com a mensagem padrão e a personalizada de cada tipo de aviso, os eventos personalizados, a chave do Google lida ou não, como as mensagens e a agenda saem agora e a situação do cron (nunca rodou, parado ou rodando)",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=config\""],
            "cron" => ["descricao" => "as execuções do cron, com o resumo do período (total, com atividade, com erro, a mais lenta) e páginas; sem atividade elas ficam 7 dias, com atividade ou erro, 1 ano",
                "parametros" => ["de, ate" => "data (AAAA-MM-DD) ou data e hora (AAAA-MM-DD HH:MM[:SS], espaço ou T); cron_de e cron_ate também valem", "situacao" => "atividade (padrão), erro, nada ou todas; cron_situacao também vale", "busca" => "texto no registro; cron_busca também vale", "limite" => "por página (padrão 100, máximo 1000)", "pagina" => "a página (padrão 1)"],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=cron&situacao=erro&de=2026-09-01&busca=agenda\""],
            "arvore" => ["descricao" => "os grupos em ordem de árvore (com o caminho, os de cima, quantos subgrupos, os relógios direto neles e quantos parâmetros de critérios próprios) e o grupo de cada relógio",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=arvore\""],
            "cadastros" => ["descricao" => "tudo o que a página Cadastros mostra: campos, tipos de lançamento (com quantos lançamentos cada um tem), fórmulas e avisos (todas as versões), modos com os blocos, grupos, relógios e as funções do motor",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=cadastros\""],
            "calcular" => ["descricao" => "calcula uma fórmula sem gravar, em cada relógio (ou nos pedidos), com as partes da conta (o testar da página Cadastros); fórmula com erro de escrita: 400 com os erros",
                "parametros" => ["expressao" => "a fórmula", "relogio" => "um id ou vários separados por vírgula (vazio: todos)"],
                "exemplo" => "curl -u lucas:senha -G \"http://servidor/relojoeiro/api.php\" --data-urlencode recurso=calcular --data-urlencode \"expressao=energia * 2\" -d relogio=10,12"],
            "avisos" => ["descricao" => "os avisos de todos os relógios agora, do mais urgente ao mais distante, com a data prevista, quanto falta, o estado (atrasado, em_breve, ok) e o texto",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=avisos\""],
            "criterios" => ["descricao" => "os conjuntos de critérios por lugar (parâmetros, subparâmetros, faixas), as variáveis que podem medir (com o limite), os lugares (quem tem critérios próprios e de quem herda), os modos e a nota de cada relógio com a conta",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=criterios\""],
            "historico" => ["descricao" => "a linha do tempo: trechos contínuos por estado (rodizio, pulso fora do rodízio, cada tipo de sessão, repouso) e as marcações, com o resumo do tempo em cada estado e os lançamentos crus do período",
                "parametros" => ["relogio" => "um id ou vários (relogio_id também vale; vazio: todos)", "de, ate" => "data ou data e hora; entra a linha que cruza o período", "estado" => "rodizio, pulso, winder, sol, repouso, marca... (um ou vários; linha_estado também vale)", "limite" => "por página (padrão 100, máximo 1000)", "pagina" => "a página", "ordem" => "desc (padrão, mais recente primeiro) ou asc; dois no mesmo instante saem pela ordem em que foram lançados (o id)"],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=historico&relogio=3&de=2026-09-01&estado=marca\""],
            "previsao" => ["descricao" => "a previsão da energia dos relógios com leitura (o smartwatch): quanto dura usando, quando chega ao limite parado, com quanto entra no próximo rodízio e quanto precisa, e a confiança da conta",
                "parametros" => ["relogio" => "um id ou vários (vazio: todos)"],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=previsao&relogio=10\""],
            "plano" => ["descricao" => "o plano gravado (a escala de dois anos sai inteira), com o modo ativo, o fim da escala e o último dia gravado; cada dia com o relógio, o bloco, a origem (sorteio ou manual) e o lembrete; e o resumo do período (de hoje em diante), como a página Plano mostra: quantos dias cada relógio tem, a porcentagem, o próximo dia, quantos à mão e os relógios sem nenhum dia",
                "parametros" => ["de, ate" => "datas AAAA-MM-DD (vazio: o plano inteiro)", "dias" => "N: os próximos N dias, de hoje em diante (no lugar de de e ate); todos: de hoje até o fim do plano"],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=plano&de=2026-10-01&ate=2026-10-31\""],
            "eventos" => ["descricao" => "os eventos personalizados, com quando disparam, se vão pelo Telegram e pela agenda, as 10 próximas ocorrências e os disparos",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=eventos\""],
            "agenda" => ["descricao" => "o Google Agenda: se está ativa, a chave lida, o que tem de estar na agenda agora (com o título e a descrição pelo modelo) e o que o sistema já criou",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=agenda\""],
            "foto" => ["descricao" => "a foto de um relógio (a imagem, não é JSON)",
                "parametros" => ["relogio" => "o id do relógio"],
                "exemplo" => "curl -u lucas:senha -o foto.jpg \"http://servidor/relojoeiro/api.php?recurso=foto&relogio=10\""],
            "documentos" => ["descricao" => "os documentos de um relógio (ou de todos): o manual, a nota fiscal em PDF e em XML (com o resumo da NF-e), fotos, vídeos e o que mais for, com as categorias (e quantos documentos cada uma tem), os relógios (com quantos), se a pasta dos documentos está pronta, se a cópia de segurança no banco está ligada, onde está cada arquivo (na pasta, no banco) e o maior arquivo aceito",
                "parametros" => ["relogio" => "o id do relógio (sem ele: de todos)"],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=documentos&relogio=10\""],
            "documento" => ["descricao" => "o arquivo de um documento (não é JSON): imagens, vídeos, áudios, PDF e texto vêm para mostrar; o resto (inclusive XML, HTML e SVG), para baixar; aceita o pedido de um pedaço (Range), que o vídeo usa para avançar; se o arquivo sumiu da pasta, ele é recriado antes a partir da cópia no banco",
                "parametros" => ["id" => "o id do documento", "baixar" => "1: sempre para baixar", "mini" => "1: a miniatura da foto (sem ela, a foto)"],
                "exemplo" => "curl -u lucas:senha -o nota.pdf \"http://servidor/relojoeiro/api.php?recurso=documento&id=7\""],
            "usuarios" => ["descricao" => "os logins, cada um com o hash da senha (o algoritmo e o custo), para migrar os usuários com a mesma senha (veja senhas), e quem está pedindo (pelo login do site); a senha não existe no sistema",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=usuarios\""],
            "migracoes" => ["descricao" => "as migrações que faltam aplicar no banco (lista vazia: está em dia); com o banco desatualizado, só ele (e o manual, que não usa o banco) responde",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=migracoes\""],
            "instalacao" => ["descricao" => "a situação da instalação: o banco, se está vazio, instalado e em dia, as migrações que faltam, quantos usuários há e o próximo passo (instalar, aplicar as migrações, criar o primeiro usuário ou nada); responde também com o banco vazio, pelo token",
                "parametros" => [],
                "exemplo" => "curl -H \"X-Api-Token: segredo\" \"http://servidor/relojoeiro/api.php?recurso=instalacao\""],
            "importacao" => ["descricao" => "o que a importação do sistema anterior usa: o servidor do banco antigo (sem a senha) e de onde ele vem no config.php, se o PHP tem a extensão do MySQL, quantos relógios este banco já tem (com algum, a importação pede substituir=1) e o que ela traz",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=importacao\""],
            "entrar" => ["descricao" => "o login das páginas: sem login, 401 (o navegador pede o usuário e a senha); com login, vai para a página de volta (302) ou diz quem entrou (usuario, por, volta). É para onde o api.js manda quem abre uma página sem login",
                "parametros" => ["volta" => "uma página do sistema para onde ir depois de entrar (index.php, plano.php?..., ajuda.php#...); outra coisa não vale"],
                "exemplo" => "no navegador: http://servidor/relojoeiro/api.php?recurso=entrar&volta=index.php"],
            "autonomia" => ["descricao" => "as autonomias de cada relógio, enxuto e rápido, para sistemas de fora, em segundos inteiros: prevista (cheio, pelo cadastro), atual (cheio, pela conta do sistema com o gasto medido), estimada (quanto ainda dura seguindo o plano), restante_em_uso (no pulso sem tirar), restante_guardado (parado) e quando acaba (acaba_em_unixtimestamp, acaba_em_segundos, acaba_em_datacomtz); null com o motivo em motivos",
                "parametros" => ["relogio" => "um id ou vários separados por vírgula (vazio: todos)"],
                "exemplo" => "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?recurso=autonomia&f[relogios][acaba_em_segundos][ate]=86400&mostrar[relogios]=nome,acaba_em_datacomtz\""],
            "ajuda" => ["descricao" => "esta explicação: os recursos, os parâmetros, as ações de escrita com os campos, os exemplos, o dicionário de todos os campos (campos) e as funções do motor; com parte=manual, o manual (veja manual); com parte=reconstrucao, a reconstrução (veja reconstrucao)",
                "parametros" => ["parte" => "manual: em vez desta explicação, o texto da página Ajuda (o mesmo que recurso=manual); reconstrucao: o roteiro para reescrever a aplicação do zero (o mesmo que recurso=reconstrucao)"],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=ajuda&parte=reconstrucao\""],
            "manual" => ["descricao" => "o texto da página Ajuda: os trechos do README para quem usa (a ideia central, como funciona, o cadastro campo por campo, a Configuração, um dia com o sistema, as telas, as perguntas frequentes e como reescrever o sistema do zero), em markdown, inteiro e seção por seção (título, nível, âncora e texto), e já em HTML com o sumário, como a página Ajuda mostra; sem o README.md na pasta: 404; responde mesmo com o banco desatualizado. O mesmo que recurso=ajuda&parte=manual",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?recurso=manual&mostrar[secoes]=titulo,ancora\""],
            "reconstrucao" => ["descricao" => "o roteiro para reescrever a aplicação inteira do zero, em qualquer linguagem e banco, chegando ao mesmo sistema: o que ele é, os princípios, a arquitetura, a ordem de construção e as regras de cada parte (árvore e campos, lançamentos, fórmulas, avisos, gasto medido, critérios, rodízio e escala, pulso sozinho, cron, mensagens e agenda, documentos, telas, segurança, testes), o modelo de dados lido do próprio banco (tabelas, colunas, chaves, índices e regras de validação) e as listas do código (funções das fórmulas, tipos de campo, formatos de lançamento, repetições, dias da semana, âncoras, canais, migrações e as constantes do config.php). O mesmo que recurso=ajuda&parte=reconstrucao",
                "parametros" => [],
                "exemplo" => "curl -u lucas:senha \"http://servidor/relojoeiro/api.php?recurso=reconstrucao\""],
        ],
        "escrita" => [
            "como" => "POST, com os campos em formulário ou em JSON no corpo (Content-Type: application/json); recurso e acao nos campos ou na URL. "
                . "Resposta: {ok, mensagem, erros, id}; 400 quando recusado (os erros dizem por quê). Ex. em JSON: curl -u lucas:senha -H \"Content-Type: application/json\" "
                . "-d '{\"recurso\":\"lancamento\",\"acao\":\"lancar\",\"relogio_id\":10,\"tipo\":\"carga\",\"valor\":68}' http://servidor/relojoeiro/api.php",
            "arvore" => [
                "novo" => ["campos" => "nome, pai_id (0 = na raiz)", "exemplo" => "curl -u lucas:senha -d recurso=arvore -d acao=novo -d \"nome=Cronógrafos\" -d pai_id=2 http://servidor/relojoeiro/api.php"],
                "renomear" => ["campos" => "id, nome", "exemplo" => "curl -u lucas:senha -d recurso=arvore -d acao=renomear -d id=5 -d \"nome=Automáticos\" http://servidor/relojoeiro/api.php"],
                "mover" => ["campos" => "id, pai_id (0 = na raiz; não pode ir para dentro dele mesmo)", "exemplo" => "curl -u lucas:senha -d recurso=arvore -d acao=mover -d id=5 -d pai_id=0 http://servidor/relojoeiro/api.php"],
                "ordem" => ["campos" => "id, direcao (sobe ou desce)", "exemplo" => "curl -u lucas:senha -d recurso=arvore -d acao=ordem -d id=5 -d direcao=sobe http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id; o que é dele (subgrupos, relógios, campos, tipos de lançamento, fórmulas, avisos) sobe para o de cima, menos as "
                    . "versões de fórmulas e avisos que o de cima já tem (essas saem); os blocos dos modos que sorteavam dele passam a sortear do de cima; "
                    . "os critérios próprios saem (os relógios usam os do lugar mais perto, acima)", "exemplo" => "curl -u lucas:senha -d recurso=arvore -d acao=excluir -d id=5 http://servidor/relojoeiro/api.php"],
                "relogios" => ["campos" => "grupo[<id do relógio>] = o grupo (0 = na raiz), um ou vários", "exemplo" => "curl -u lucas:senha -d recurso=arvore -d acao=relogios -d \"grupo[13]=1\" -d \"grupo[4]=8\" http://servidor/relojoeiro/api.php"],
            ],
            "campos" => [
                "novo" => ["campos" => "identificador, nome, tipo (inteiro, decimal, sim_nao, data, lista, texto), unidade, opcoes (lista: uma por linha), padrao, no_id (0 = todos)", "exemplo" => "curl -u lucas:senha -d recurso=campos -d acao=novo -d identificador=resistencia_agua -d \"nome=Resistência à água\" -d tipo=inteiro -d unidade=m -d no_id=0 http://servidor/relojoeiro/api.php"],
                "alterar" => ["campos" => "id e os mesmos campos (só o que vier muda)", "exemplo" => "curl -u lucas:senha -d recurso=campos -d acao=alterar -d id=21 -d \"nome=Resistência (m)\" http://servidor/relojoeiro/api.php"],
                "ordem" => ["campos" => "id, direcao (sobe ou desce)", "exemplo" => "curl -u lucas:senha -d recurso=campos -d acao=ordem -d id=21 -d direcao=sobe http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id (os valores dele saem junto)", "exemplo" => "curl -u lucas:senha -d recurso=campos -d acao=excluir -d id=21 http://servidor/relojoeiro/api.php"],
            ],
            "lancamento_tipos" => [
                "novo" => ["campos" => "identificador, nome, formato (instantaneo, valor, sessao), unidade (com valor), fecha_as (sessão: HH:MM; vazio: não fecha sozinha), exclusiva (1 ou 0, só sessão), mede_gasto (1 ou 0, só com valor: cada leitura mede o gasto, comparando com a anterior), condicao (vale quando: uma fórmula, 1 vale e 0 não; vazia, vale sempre; ex.: corda_manual), no_id", "exemplo" => "curl -u lucas:senha -d recurso=lancamento_tipos -d acao=novo -d identificador=banho_ultrassom -d \"nome=Banho ultrassônico\" -d formato=instantaneo -d no_id=0 http://servidor/relojoeiro/api.php"],
                "alterar" => ["campos" => "id e os mesmos campos", "exemplo" => "curl -u lucas:senha -d recurso=lancamento_tipos -d acao=alterar -d id=11 -d fecha_as=18:00 http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id (só sem lançamentos)", "exemplo" => "curl -u lucas:senha -d recurso=lancamento_tipos -d acao=excluir -d id=11 http://servidor/relojoeiro/api.php"],
            ],
            "formulas" => [
                "nova" => ["campos" => "identificador, nome, expressao, unidade, no_id: uma fórmula nova, ou mais uma versão de uma que existe, em outro ponto da árvore", "exemplo" => "curl -u lucas:senha -d recurso=formulas -d acao=nova -d identificador=idade_dias -d \"nome=Idade (dias)\" --data-urlencode \"expressao=AGORA() - data_compra\" -d unidade=dias -d no_id=0 http://servidor/relojoeiro/api.php"],
                "alterar" => ["campos" => "id: nome, expressao, unidade, no_id", "exemplo" => "curl -u lucas:senha -d recurso=formulas -d acao=alterar -d id=17 --data-urlencode \"expressao=ARREDONDA(AGORA() - data_compra)\" http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id (a versão)", "exemplo" => "curl -u lucas:senha -d recurso=formulas -d acao=excluir -d id=17 http://servidor/relojoeiro/api.php"],
            ],
            "relogio" => [
                "salvar" => ["campos" => "id (0 ou ausente cria), nome, no_id, disponivel (1 ou 0), copia_banco (1 ou 0: a cópia no banco de todos os documentos dele; o cron copia ou tira aos poucos), valores[<identificador do campo>]: só muda o que vier; valor vazio apaga", "exemplo" => "curl -u lucas:senha -d recurso=relogio -d acao=salvar -d id=10 -d \"valores[preferencia]=80\" -d disponivel=1 http://servidor/relojoeiro/api.php"],
                "foto" => ["campos" => "id, foto_base64 (JPEG, PNG ou WebP, até 4 MB; data:image/...;base64,... ou só o base64)", "exemplo" => "curl -u lucas:senha -d recurso=relogio -d acao=foto -d id=10 --data-urlencode foto_base64@foto.b64 http://servidor/relojoeiro/api.php"],
                "remover_foto" => ["campos" => "id", "exemplo" => "curl -u lucas:senha -d recurso=relogio -d acao=remover_foto -d id=10 http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id (com todo o histórico, a foto e os documentos, inclusive os arquivos deles)", "exemplo" => "curl -u lucas:senha -d recurso=relogio -d acao=excluir -d id=15 http://servidor/relojoeiro/api.php"],
            ],
            "documentos" => [
                "enviar" => ["campos" => "relogio_id, categoria_id, titulo (vazio: o nome do arquivo), data (AAAA-MM-DD), descricao, copia_banco (1: pede a cópia destes arquivos no banco; o config.php e o relógio, se pedem, valem por cima) e o arquivo: arquivos[] (upload; vários de uma vez) ou arquivo_base64 com arquivo_nome; a miniatura de uma foto, opcional: miniatura (upload, JPEG, PNG ou WebP até 1 MB) ou miniatura_base64. Cada arquivo até o limite (recurso=documentos, limite) e do tipo que a categoria aceita. O arquivo vai para a pasta e, se a cópia é pedida (pelo config.php, pelo relógio ou pelo próprio arquivo), também para o banco, em pedaços de 4 MB (se a cópia falhar, o documento fica e a mensagem avisa; o cron tenta de novo). Responde ids: os documentos guardados",
                    "exemplo" => "curl -u lucas:senha -F recurso=documentos -F acao=enviar -F relogio_id=10 -F categoria_id=2 -F \"arquivos[]=@nota.pdf\" http://servidor/relojoeiro/api.php"],
                "alterar" => ["campos" => "id, categoria_id, titulo, data, descricao, copia_banco (1 ou 0): só muda o que vier; a cópia entra ou sai do banco na hora (só sai se o arquivo da pasta confere com ela)", "exemplo" => "curl -u lucas:senha -d recurso=documentos -d acao=alterar -d id=7 -d \"titulo=No casamento\" -d data=2026-09-20 http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id: o documento, o arquivo dele na pasta e a cópia no banco", "exemplo" => "curl -u lucas:senha -d recurso=documentos -d acao=excluir -d id=7 http://servidor/relojoeiro/api.php"],
            ],
            "documento_categorias" => [
                "nova" => ["campos" => "identificador, nome, aceita[] (imagem, video, audio, pdf, xml; nenhum: qualquer arquivo), ordem (vazio: no fim)", "exemplo" => "curl -u lucas:senha -d recurso=documento_categorias -d acao=nova -d identificador=garantia -d nome=Garantia -d \"aceita[]=pdf\" http://servidor/relojoeiro/api.php"],
                "alterar" => ["campos" => "id, nome, aceita[], ordem (o identificador não muda)", "exemplo" => "curl -u lucas:senha -d recurso=documento_categorias -d acao=alterar -d id=7 -d nome=Garantias http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id (só uma categoria sem documentos)", "exemplo" => "curl -u lucas:senha -d recurso=documento_categorias -d acao=excluir -d id=7 http://servidor/relojoeiro/api.php"],
            ],
            "lancamento" => [
                "lancar" => ["campos" => "relogio_id, tipo (instantâneo ou com valor), valor (com valor), quando (AAAA-MM-DD HH:MM; vazio: agora), medir (1, o padrão, ou 0: num tipo que mede o gasto, com 1 a medição entra na média; com 0 fica só no histórico)", "exemplo" => "curl -u lucas:senha -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 http://servidor/relojoeiro/api.php"],
                "iniciar" => ["campos" => "relogio_id, tipo (sessão), quando: abre a sessão; uma exclusiva fecha a outra exclusiva aberta", "exemplo" => "curl -u lucas:senha -d recurso=lancamento -d acao=iniciar -d relogio_id=4 -d tipo=sol http://servidor/relojoeiro/api.php"],
                "encerrar" => ["campos" => "relogio_id, tipo, quando: fecha a sessão aberta", "exemplo" => "curl -u lucas:senha -d recurso=lancamento -d acao=encerrar -d relogio_id=4 -d tipo=sol http://servidor/relojoeiro/api.php"],
                "periodo" => ["campos" => "relogio_id, tipo, inicio (quando também vale), fim: uma sessão que já passou (espaço ou T entre data e hora)", "exemplo" => "curl -u lucas:senha -d recurso=lancamento -d acao=periodo -d relogio_id=3 -d tipo=winder -d \"inicio=2026-09-26 20:00\" -d \"fim=2026-09-27 07:00\" http://servidor/relojoeiro/api.php"],
                "alterar" => ["campos" => "id: quando ou inicio e fim, valor (só o que vier)", "exemplo" => "curl -u lucas:senha -d recurso=lancamento -d acao=alterar -d id=42 -d valor=70 http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id", "exemplo" => "curl -u lucas:senha -d recurso=lancamento -d acao=excluir -d id=42 http://servidor/relojoeiro/api.php"],
            ],
            "avisos" => [
                "novo" => ["campos" => "identificador, nome, expressao (a data prevista), condicao (vale quando: uma fórmula, 1 vale e 0 não; vazia, vale sempre; ex.: corda_manual = 0), antecedencia_dias, texto (o motivo, com {relogio}, {data}, {quando}, {limite}), resolve (o tipo de lançamento), ativo, no_id, escala (nao, uso, sempre), simula_valor, simula_horas, agenda (janela ou sempre)", "exemplo" => "curl -u lucas:senha -d recurso=avisos -d acao=novo -d identificador=pulseira -d \"nome=Trocar a pulseira\" --data-urlencode \"expressao=data_compra + 365\" -d antecedencia_dias=15 -d \"texto=vence {quando}, {data}\" -d no_id=0 http://servidor/relojoeiro/api.php"],
                "alterar" => ["campos" => "id e os mesmos campos (menos o identificador)", "exemplo" => "curl -u lucas:senha -d recurso=avisos -d acao=alterar -d id=9 -d antecedencia_dias=30 http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id (a versão)", "exemplo" => "curl -u lucas:senha -d recurso=avisos -d acao=excluir -d id=9 http://servidor/relojoeiro/api.php"],
            ],
            "criterios" => [
                "conjunto_criar" => ["campos" => "escopo (\"\" todos, g:<grupo>, r:<relógio>), origem (herdado: copia o que ele usa hoje; vazio)", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=conjunto_criar -d escopo=r:10 -d origem=herdado http://servidor/relojoeiro/api.php"],
                "conjunto_excluir" => ["campos" => "escopo (volta a herdar o de cima)", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=conjunto_excluir -d escopo=r:10 http://servidor/relojoeiro/api.php"],
                "param_novo" => ["campos" => "escopo, nome, peso (os outros abrem espaço na proporção)", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=param_novo -d escopo=g:1 -d \"nome=Estilo\" -d peso=10 http://servidor/relojoeiro/api.php"],
                "param_pesos" => ["campos" => "escopo, nome[<id>], peso[<id>]: todos os do conjunto, somando 100", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=param_pesos -d escopo=g:1 -d \"peso[5]=35\" -d \"peso[6]=10\" http://servidor/relojoeiro/api.php"],
                "param_excluir" => ["campos" => "id (o peso dele vai para os outros na proporção)", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=param_excluir -d id=29 http://servidor/relojoeiro/api.php"],
                "sub_novo" => ["campos" => "parametro_id, nome, variavel (o identificador de um campo ou fórmula; metrica também vale), peso", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=sub_novo -d parametro_id=5 -d \"nome=Carga\" -d variavel=energia -d peso=20 http://servidor/relojoeiro/api.php"],
                "sub_pesos" => ["campos" => "parametro_id, nome[<id>], peso[<id>]: somando 100", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=sub_pesos -d parametro_id=5 -d \"peso[6]=60\" -d \"peso[7]=40\" http://servidor/relojoeiro/api.php"],
                "sub_excluir" => ["campos" => "id", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=sub_excluir -d id=7 http://servidor/relojoeiro/api.php"],
                "sub_medida" => ["campos" => "id, variavel (metrica também vale; as faixas recomeçam)", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=sub_medida -d id=7 -d variavel=dias_de_carga http://servidor/relojoeiro/api.php"],
                "sub_mover" => ["campos" => "id, parametro_id (o de destino, de qualquer lugar)", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=sub_mover -d id=7 -d parametro_id=8 http://servidor/relojoeiro/api.php"],
                "ordem" => ["campos" => "tipo (parametro ou sub), id, direcao (sobe ou desce)", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=ordem -d tipo=parametro -d id=5 -d direcao=desce http://servidor/relojoeiro/api.php"],
                "faixas" => ["campos" => "sub_id, de[], ate[], categoria[], nota[], apagar[] (todas as faixas do subparâmetro de uma vez; a última vai até o limite da medida ou sem limite)", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=faixas -d sub_id=6 -d \"de[0]=0\" -d \"ate[0]=50\" -d \"nota[0]=30\" -d \"de[1]=50\" -d \"ate[1]=100\" -d \"nota[1]=100\" http://servidor/relojoeiro/api.php"],
                "restaurar" => ["campos" => "(nada): volta aos critérios iniciais", "exemplo" => "curl -u lucas:senha -d recurso=criterios -d acao=restaurar http://servidor/relojoeiro/api.php"],
            ],
            "rodizio" => [
                "modo" => ["campos" => "modo (o id), bloco_alvo[<bloco>] (0, o grupo, ou r:<id> para um relógio fixo), bloco_cada_dia[<bloco>] (presente: um por dia), selecao, escala_dias, max_sem_uso: grava e ativa o modo", "exemplo" => "curl -u lucas:senha -d recurso=rodizio -d acao=modo -d modo=2 -d \"bloco_alvo[8]=1\" -d \"bloco_cada_dia[8]=1\" -d selecao=ponderado -d max_sem_uso=21 http://servidor/relojoeiro/api.php"],
                "resortear" => ["campos" => "(nada): refaz o plano de amanhã até domingo (ou até o fim da escala); se o dia ainda não começou no pulso, também hoje", "exemplo" => "curl -u lucas:senha -d recurso=rodizio -d acao=resortear http://servidor/relojoeiro/api.php"],
                "resortear_hoje" => ["campos" => "(nada): inclusive hoje; se sair outro relógio, ele passa a ser o do pulso a partir de agora", "exemplo" => "curl -u lucas:senha -d recurso=rodizio -d acao=resortear_hoje http://servidor/relojoeiro/api.php"],
                "usando" => ["campos" => "relogio_id: o relógio de hoje, a partir de agora (na escala, fica os dias que faltavam do bloco de hoje)", "exemplo" => "curl -u lucas:senha -d recurso=rodizio -d acao=usando -d relogio_id=12 http://servidor/relojoeiro/api.php"],
                "trocar_dia" => ["campos" => "data (AAAA-MM-DD, de hoje em diante), relogio_id: o relógio daquele dia, escolhido à mão (hoje: como o usando; na escala, ela é refeita a partir do dia); relogio_id 0: o dia volta a ser sorteado", "exemplo" => "curl -u lucas:senha -d recurso=rodizio -d acao=trocar_dia -d data=2026-10-09 -d relogio_id=12 http://servidor/relojoeiro/api.php"],
                "proxima_semana" => ["campos" => "(nada): só no domingo, monta ou refaz a semana seguinte inteira; recusada na escala", "exemplo" => "curl -u lucas:senha -d recurso=rodizio -d acao=proxima_semana http://servidor/relojoeiro/api.php"],
            ],
            "modos" => [
                "salvar" => ["campos" => "id (0 cria), nome, selecao (inteligente, ponderado, aleatorio, fifo), escala_dias (vazio: sorteio pelos blocos; 7 a 730: escala), blocos[n][nome], blocos[n][dias][], blocos[n][no_id], blocos[n][um_por] (dia ou bloco), blocos[n][relogio_id] (0: sorteia); substitui os blocos; ciclo (1 ou 0, o padrão: com o ciclo, um relógio só volta depois que todos os disponíveis do bloco passaram na semana; na escala, pelo período dela)", "exemplo" => "curl -u lucas:senha -d recurso=modos -d acao=salvar -d id=0 -d \"nome=Só smartwatch\" -d selecao=inteligente -d \"blocos[0][nome]=Todo dia\" -d \"blocos[0][dias][]=1\" -d \"blocos[0][dias][]=7\" -d \"blocos[0][no_id]=1\" -d \"blocos[0][um_por]=dia\" http://servidor/relojoeiro/api.php"],
                "ativar" => ["campos" => "id (o plano de amanhã em diante é refeito)", "exemplo" => "curl -u lucas:senha -d recurso=modos -d acao=ativar -d id=5 http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "id (não o ativo)", "exemplo" => "curl -u lucas:senha -d recurso=modos -d acao=excluir -d id=6 http://servidor/relojoeiro/api.php"],
            ],
            "config" => [
                "salvar" => ["campos" => "horario_manha, horario_noite, uso_inicio, uso_fim, sol_fim (HH:MM), pulso_auto_inicio e pulso_auto_fim (1 ou 0), carga_limiar (1 a 99), sol_limiar (1 a 99), url_sistema, painel_modo (lado: o relógio abre no painel ao lado da lista; flutuante: numa janela grande por cima da página), alerta_ativo e agenda_ativa (1 ou 0), agenda_id, agenda_chave, agenda_antecedencia (1 a 365), max_sem_uso, previsao_limite, medicao_janela_dias (1 a 3650: a janela da média do gasto medido), alerta_tipos[] e agenda_tipos[] (os tipos de aviso de cada canal: dia, vespera, os identificadores dos avisos, ev<id>), tg_padrao e ag_padrao, tg_proprio_<tipo> e ag_proprio_<tipo> (1 ou 0), tg_corpo_<tipo> e ag_corpo_<tipo>; só o que vier muda", "exemplo" => "curl -u lucas:senha -d recurso=config -d acao=salvar -d horario_manha=06:30 -d alerta_ativo=1 --data-urlencode \"tg_padrao={acao}: {relogio}\" -d tg_proprio_corda=1 --data-urlencode \"tg_corpo_corda=Corda no {relogio}!\" http://servidor/relojoeiro/api.php"],
                "testar_manha" => ["campos" => "(nada): manda a mensagem da manhã agora", "exemplo" => "curl -u lucas:senha -d recurso=config -d acao=testar_manha http://servidor/relojoeiro/api.php"],
                "testar_noite" => ["campos" => "(nada): manda a mensagem da noite agora", "exemplo" => "curl -u lucas:senha -d recurso=config -d acao=testar_noite http://servidor/relojoeiro/api.php"],
                "evento_salvar" => ["campos" => "evento_id (ou id; 0 cria), nome, ativo, repeticao (uma, diaria, semanal, mensal, intervalo), hora (HH:MM), data_inicio, dias_semana (1 a 7), dia_mes, intervalo_dias, relogio_id; o evento novo já vai pelo Telegram", "exemplo" => "curl -u lucas:senha -d recurso=config -d acao=evento_salvar -d evento_id=0 -d \"nome=Limpar as pulseiras\" -d repeticao=semanal -d \"dias_semana[]=1\" -d \"dias_semana[]=4\" -d hora=20:00 http://servidor/relojoeiro/api.php"],
                "evento_excluir" => ["campos" => "evento_id (ou id)", "exemplo" => "curl -u lucas:senha -d recurso=config -d acao=evento_excluir -d evento_id=3 http://servidor/relojoeiro/api.php"],
                "teste_agenda_criar" => ["campos" => "(nada): cria um evento de teste daqui a 10 minutos", "exemplo" => "curl -u lucas:senha -d recurso=config -d acao=teste_agenda_criar http://servidor/relojoeiro/api.php"],
                "teste_agenda_remover" => ["campos" => "(nada): remove o evento de teste", "exemplo" => "curl -u lucas:senha -d recurso=config -d acao=teste_agenda_remover http://servidor/relojoeiro/api.php"],
                "sincronizar" => ["campos" => "(nada): sincroniza o Google Agenda agora", "exemplo" => "curl -u lucas:senha -d recurso=config -d acao=sincronizar http://servidor/relojoeiro/api.php"],
            ],
            "usuarios" => [
                "salvar" => ["campos" => "login, senha (6 caracteres ou mais; login que existe: troca a senha); ou login e senha_hash (o hash de outro sistema, bcrypt ou argon2: o usuário entra com a senha de lá; veja senhas); o primeiro usuário sai pelo token (sem usuários, o login não entra)", "exemplo" => "curl -u lucas:senha -d recurso=usuarios -d acao=salvar -d login=visitante -d senha=segredo123 http://servidor/relojoeiro/api.php"],
                "excluir" => ["campos" => "login (não o próprio)", "exemplo" => "curl -u lucas:senha -d recurso=usuarios -d acao=excluir -d login=visitante http://servidor/relojoeiro/api.php"],
            ],
            "migracoes" => [
                "aplicar" => ["campos" => "(nada): aplica as migrações pendentes, na ordem: o SQL de cada uma e, se ela tiver, o passo em PHP", "exemplo" => "curl -u lucas:senha -d recurso=migracoes -d acao=aplicar http://servidor/relojoeiro/api.php"],
            ],
            "instalacao" => [
                "instalar" => ["campos" => "(nada): instala o banco do config.php, só num banco vazio (o schema.sql traduzido para o banco, com os passos em PHP das migrações), pelo token (antes da instalação não há usuários); a resposta traz comandos, tabelas e segundos. Depois: o primeiro usuário, em recurso=usuarios, acao=salvar", "exemplo" => "curl -H \"X-Api-Token: segredo\" -d recurso=instalacao -d acao=instalar http://servidor/relojoeiro/api.php"],
            ],
            "importacao" => [
                "importar" => ["campos" => "banco (o nome do banco antigo, MySQL/MariaDB), substituir (1: apaga antes os relógios e o histórico deste banco; obrigatório quando ele já tem relógios): traz a árvore, os relógios, as fotos, os usuários, os valores dos campos e o histórico, tudo numa transação (parou no meio: nada fica gravado); a resposta traz contagem (quantos de cada)", "exemplo" => "curl -u lucas:senha -d recurso=importacao -d acao=importar -d banco=relogios -d substituir=1 http://servidor/relojoeiro/api.php"],
            ],
        ],
        "senhas" => [
            "como_fica" => "a senha não fica guardada em lugar nenhum: fica só o hash dela, feito pelo password_hash do PHP com PASSWORD_DEFAULT (bcrypt). O hash tem 60 caracteres: \$2y\$, o custo com dois dígitos e \$ (12 é 2^12 rodadas), o sal (22 caracteres, sorteado a cada senha) e o resultado (31). O sal e o custo vão dentro do próprio hash",
            "como_confere" => "o hash não se desfaz em senha (é de mão única). Para conferir, calcula-se o bcrypt da senha digitada com o sal e o custo que estão no hash e compara-se com o resultado: é o que o password_verify do PHP faz no login. A mesma senha dá hashes diferentes a cada cálculo (o sal muda), e todos conferem",
            "migrar_daqui" => "leve o login e o senha_hash de cada um (recurso=usuarios, ou a resposta sem recurso) e, no outro sistema, confira a senha digitada contra o hash com o bcrypt dele: cada um entra lá com a mesma senha de sempre. \$2y\$ (PHP) e \$2b\$ (Python, Node, Java, C#...) são o mesmo cálculo; se a biblioteca recusar o \$2y\$, troque o começo por \$2b\$",
            "migrar_para_ca" => "POST recurso=usuarios, acao=salvar, login e senha_hash (um bcrypt \$2y\$, \$2b\$ ou \$2a\$, ou um argon2): o usuário entra aqui com a senha que tinha lá, sem ninguém saber qual é (o \$2b\$ é guardado como \$2y\$, o mesmo cálculo)",
            "php" => "conferir: password_verify(\$senha, \$hash); fazer: password_hash(\$senha, PASSWORD_DEFAULT)",
            "python" => "pip install bcrypt; conferir: bcrypt.checkpw(senha.encode(), hash.encode()); fazer: bcrypt.hashpw(senha.encode(), bcrypt.gensalt()).decode()",
            "node" => "npm install bcryptjs; conferir: require(\"bcryptjs\").compareSync(senha, hash); fazer: require(\"bcryptjs\").hashSync(senha, 12)",
            "java" => "Spring Security: new BCryptPasswordEncoder().matches(senha, hash) (com jBCrypt, BCrypt.checkpw(senha, hash) trocando \$2y\$ por \$2a\$)",
            "csharp" => "BCrypt.Net-Next: BCrypt.Net.BCrypt.Verify(senha, hash)",
        ],
        "filtros" => [
            "onde" => "em qualquer consulta: a resposta sem parâmetros e todos os recursos",
            "incluir" => "incluir=relogios,plano: só essas partes da resposta",
            "excluir" => "excluir=motor,criterios: a resposta sem essas partes",
            "lista" => "as regras valem para uma lista da resposta, pelo caminho dela entre os colchetes: relogios, plano, avisos, eventos, usuarios, cron.execucoes, "
                . "criterios.notas, criterios.conjuntos, agenda.desejados, relogios.lancamentos, relogios.avisos, relogios.linha_do_tempo, relogio.leituras (na ficha)...; "
                . "lista dentro de lista vale em cada item",
            "campo" => "qualquer campo do item; com ponto entra num objeto (compra.valor) ou, numa lista de itens com identificador, no item daquele identificador "
                . "(formulas.energia.valor, campos.loja.valor)",
            "f[lista][campo]=v" => "igual a v (vários separados por vírgula: igual a um deles; numa lista de valores, se algum for)",
            "f[lista][campo][de]=v" => "a partir de v (números como números; datas e textos como texto)",
            "f[lista][campo][ate]=v" => "até v (uma data sem hora vai até o fim daquele dia)",
            "f[lista][campo][contem]=t" => "o texto contém t",
            "f[lista][campo][diferente]=v" => "diferente de v (vários separados por vírgula: de todos)",
            "f[lista][campo][vazio]=1" => "vazio (1) ou preenchido (0)",
            "busca[lista]=t" => "o texto t em qualquer campo do item",
            "ordem[lista]=campo" => "ordena pelo campo; -campo: do maior para o menor; vários separados por vírgula",
            "limite[lista]=N, pagina[lista]=P" => "N itens por página, a página P",
            "mostrar[lista]=id,nome" => "só esses campos de cada item (com ponto também)",
            "comparacao" => "textos sem diferença de maiúsculas e acentos; verdadeiro e falso valem 1 e 0; filtros juntos: o item tem de passar em todos",
            "resposta" => "ganha _filtros: {listas: {caminho: {antes, passaram, devolvidos, pagina, limite}}, ignorados: [o caminho sem lista, a parte que não existe]}; "
                . "com filtros, objetos vazios saem como listas vazias",
            "sem_colchetes" => "limite, pagina, ordem e busca sem colchetes continuam sendo os do recurso (historico, cron)",
            "exemplos" => [
                "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?incluir=relogios&f[relogios][disponivel]=1&mostrar[relogios]=id,nome&foto=nao\"",
                "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?incluir=relogios&f[relogios][formulas.energia.valor][ate]=20&ordem[relogios]=formulas.energia.valor&mostrar[relogios]=nome,formulas.energia.valor&foto=nao\"",
                "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?incluir=relogios&f[relogios][id]=10,12&f[relogios.lancamentos][tipo]=carga&f[relogios.lancamentos][inicio][de]=2026-09-01&mostrar[relogios]=nome,lancamentos&foto=nao\"",
                "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?incluir=relogios&f[relogios][campos.loja.valor][contem]=aliexpress&mostrar[relogios]=nome,campos.valor_compra.valor&foto=nao\"",
                "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?recurso=plano&f[plano][data][de]=2026-10-01&f[plano][data][ate]=2026-10-31&f[plano][relogio][contem]=xiaomi\"",
                "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?incluir=cron&f[cron.execucoes][teve_erro]=1&limite[cron.execucoes]=20&pagina[cron.execucoes]=2\"",
                "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?incluir=criterios&f[criterios.notas][nota][de]=50&ordem[criterios.notas]=-nota&mostrar[criterios.notas]=relogio,nota\"",
                "curl -u lucas:senha -g \"http://servidor/relojoeiro/api.php?recurso=hoje&f[relogios][com_aviso]=1&mostrar[relogios]=nome,manutencao.nome\"",
            ],
        ],
        "campos" => [
            "como_ler" => "[] no caminho: uma lista (o campo é de cada item); <...>: uma chave que varia. Datas e horas no fuso do sistema (o FUSO do config.php; sem ele, o do PHP, date.timezone); null: sem valor. Números de tempo em segundos são inteiros.",
            "api.php (sem recurso)" => [
                "agenda.criados[]" => "os eventos que o sistema criou no Google Agenda",
                "agenda.criados[].assinatura" => "a marca do título e da descrição com que foi criado (mudou: o evento é atualizado na próxima sincronização)",
                "agenda.criados[].chave" => "a identificação do evento (a mesma dos desejados)",
                "agenda.criados[].criado" => "quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "agenda.criados[].data" => "o dia do evento (texto AAAA-MM-DD)",
                "agenda.criados[].google_id" => "o id do evento no Google",
                "agenda.criados[].titulo" => "o título com que foi criado",
                "agenda.desejados[]" => "o que tem de estar no Google Agenda agora: os avisos marcados para a agenda, o relógio do dia, a véspera e os eventos",
                "agenda.desejados[].chave" => "a identificação do evento na agenda (a mesma enquanto nada mudar; é o que o sistema usa para atualizar ou remover)",
                "agenda.desejados[].data" => "o dia do evento na agenda (texto AAAA-MM-DD)",
                "agenda.desejados[].descricao" => "a descrição do evento, montada pela mensagem do canal da agenda",
                "agenda.desejados[].fazer" => "a ação (\"Dar corda\", \"Usar hoje\", o nome do evento) — a âncora {acao}",
                "agenda.desejados[].hora" => "a hora do evento na agenda (HH:MM)",
                "agenda.desejados[].momento" => "o instante previsto do que motivou o evento (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "agenda.desejados[].motivo" => "o porquê (\"a reserva acaba em 3h, 28/09 10:53\") — a âncora {motivo}",
                "agenda.desejados[].relogio" => "o nome do relógio; vazio num evento geral",
                "agenda.desejados[].relogio_id" => "o relógio do evento; null num evento geral",
                "agenda.desejados[].tipo" => "o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)",
                "agenda.desejados[].titulo" => "o título do evento, montado pela mensagem do canal da agenda",
                "arvore[]" => "os grupos, em ordem de árvore",
                "arvore[].caminho" => "o caminho por extenso (\"Tradicional › Quartzo › Solar\")",
                "arvore[].id" => "o número do grupo",
                "arvore[].nome" => "o nome do grupo",
                "arvore[].ordem" => "a posição entre os irmãos",
                "arvore[].pai_id" => "o grupo de cima; null: na raiz",
                "arvore[].profundidade" => "o nível na árvore (0: na raiz)",
                "avisos[]" => "os avisos cadastrados (cada identificador pode ter uma versão por grupo)",
                "avisos[].agenda" => "a data na agenda: janela (só dentro da antecedência da agenda) ou sempre",
                "avisos[].antecedencia_dias" => "com quantos dias antes ele entra \"em breve\" (número)",
                "avisos[].ativo" => "o aviso vale",
                "avisos[].condicao" => "vale quando: uma fórmula que diz para quais relógios do grupo o aviso vale (1 vale, 0 não; ex.: corda_manual = 0, só o automático sem corda); null: vale para todos",
                "avisos[].escala" => "na escala inteligente: nao, uso ou sempre",
                "avisos[].expressao" => "a fórmula da data prevista",
                "avisos[].id" => "o número da versão",
                "avisos[].identificador" => "o aviso (o tipo dele nos canais)",
                "avisos[].lugar" => "esse grupo por extenso",
                "avisos[].no_id" => "o grupo desta versão; null: todos",
                "avisos[].nome" => "o nome do aviso — a âncora {acao}",
                "avisos[].resolve" => "o tipo de lançamento que resolve (o botão na tela Hoje); null: nenhum",
                "avisos[].simula_horas" => "as horas da sessão que a escala simula para resolver (número; null)",
                "avisos[].simula_valor" => "o valor do lançamento que a escala simula para resolver (número; null)",
                "avisos[].texto" => "o motivo, com {relogio}, {data}, {quando} e {limite} — a âncora {motivo}",
                "campos[]" => "os campos do cadastro dos relógios (cada um vale para o grupo dele e tudo abaixo)",
                "campos[].id" => "o número do campo",
                "campos[].identificador" => "o nome do campo nas fórmulas e nos critérios",
                "campos[].lugar" => "esse grupo por extenso",
                "campos[].no_id" => "o grupo em que o campo vale; null: todos os relógios",
                "campos[].nome" => "o nome do campo",
                "campos[].opcoes" => "as opções de um campo de lista, uma por linha (texto; vazio nos outros)",
                "campos[].ordem" => "a posição do campo no cadastro",
                "campos[].padrao" => "o valor usado quando o relógio não tem o campo preenchido (texto; null: nenhum)",
                "campos[].tipo" => "inteiro, decimal, sim_nao, data, lista ou texto",
                "campos[].unidade" => "a unidade (texto; vazio: nenhuma)",
                "config.ag_padrao" => "a mensagem padrão da agenda: a primeira linha é o título do evento, o resto a descrição",
                "config.agenda_antecedencia" => "com quantos dias de antecedência os eventos são criados na agenda",
                "config.agenda_ativa" => "1: cria os eventos no Google Agenda; 0: não",
                "config.agenda_chave" => "o caminho da chave JSON da conta de serviço do Google, no servidor",
                "config.agenda_id" => "o id da agenda do Google",
                "config.agenda_teste_id" => "o id do evento de teste na agenda (vazio: nenhum)",
                "config.carga_limiar" => "o limite de carga geral (%): carregar quando a carga chega a ele (o campo carga_minima do relógio, se preenchido, vale no lugar)",
                "config.cron_erro" => "o erro guardado da última execução com erro (texto; vazio: sem erro)",
                "config.cron_registro" => "o registro da última rodada com atividade (texto)",
                "config.cron_ultima_execucao" => "quando o cron rodou pela última vez (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "config.escala_fim" => "até que dia vai a escala inteligente gerada (texto AAAA-MM-DD; vazio fora da escala)",
                "config.escala_gerada" => "o dia em que a escala foi gerada pela última vez (texto AAAA-MM-DD)",
                "config.horario_manha" => "a hora da rodada da manhã do cron: o relógio do dia e os avisos (HH:MM)",
                "config.horario_noite" => "a hora da rodada da noite: preparar o relógio de amanhã (HH:MM)",
                "config.max_sem_uso" => "a garantia de rodízio: nenhum relógio passa desses dias sem uso (0 desliga)",
                "config.medicao_janela_dias" => "a média do gasto medido pelas leituras usa as medições destes últimos dias (sem nenhuma na janela, a última)",
                "config.mensagens_ativas" => "1: o Telegram (a API de alerta) envia; 0: não envia",
                "config.painel_modo" => "como o relógio abre na página Hoje: lado (no painel à direita da lista) ou flutuante (numa janela grande por cima da página); vazio: lado",
                "config.migracao_v10" => "marca de que a migração v10 foi aplicada (1)",
                "config.migracao_v12" => "marca de que a migração v12 foi aplicada (1)",
                "config.migracao_v16" => "marca de que a migração v16 foi aplicada (1)",
                "config.migracao_v17" => "marca de que a migração v17 foi aplicada (1)",
                "config.pulso_auto_fim" => "1 (ou vazio): o relógio sai do pulso sozinho no fim do horário de uso (o \"fecha às\" do tipo No pulso); 0: só pelo Tirou",
                "config.pulso_auto_inicio" => "1 (ou vazio): o relógio do dia entra no pulso sozinho no início do horário de uso; 0: só pelo Pôs",
                "config.previsao_limite" => "o limite de carga da previsão do smartwatch, em %",
                "config.sol_limiar" => "no solar, a carga (%) em que ele deve ir para o sol",
                "config.tg_padrao" => "a mensagem padrão do Telegram, com âncoras ({acao}, {relogio}, {motivo}...)",
                "config.ultima_manha" => "o dia da última rodada da manhã do cron (texto AAAA-MM-DD)",
                "config.ultima_noite" => "o dia da última rodada da noite do cron (texto AAAA-MM-DD)",
                "config.url_sistema" => "o endereço do sistema, para a âncora {link}",
                "config.uso_fim" => "a hora em que ele sai do pulso (HH:MM)",
                "config.uso_inicio" => "a hora em que o relógio do dia vai para o pulso (HH:MM)",
                "criterios.conjuntos[]" => "os conjuntos de critérios, um por lugar que tem critérios próprios",
                "criterios.conjuntos[].escopo" => "o lugar: \"\" (todos os relógios), g:<grupo> ou r:<relógio>",
                "criterios.conjuntos[].lugar" => "o lugar por extenso",
                "criterios.conjuntos[].parametros[]" => "os parâmetros do conjunto, em ordem",
                "criterios.conjuntos[].parametros[].escopo_no_id" => "o grupo do conjunto; null se não é de grupo",
                "criterios.conjuntos[].parametros[].escopo_relogio_id" => "o relógio do conjunto; null se não é de relógio",
                "criterios.conjuntos[].parametros[].id" => "o número do parâmetro",
                "criterios.conjuntos[].parametros[].nome" => "o nome do parâmetro",
                "criterios.conjuntos[].parametros[].ordem" => "a posição dele no conjunto",
                "criterios.conjuntos[].parametros[].peso" => "o peso no conjunto, em % (os do conjunto somam 100)",
                "criterios.conjuntos[].parametros[].subparametros[]" => "os subparâmetros, em ordem",
                "criterios.conjuntos[].parametros[].subparametros[].faixas[]" => "as faixas: o valor medido vira uma nota de 0 a 100",
                "criterios.conjuntos[].parametros[].subparametros[].faixas[].ate" => "o fim da faixa (não entra, menos na última); null: sem limite",
                "criterios.conjuntos[].parametros[].subparametros[].faixas[].categoria" => "a categoria (medida de lista); null numa faixa de números",
                "criterios.conjuntos[].parametros[].subparametros[].faixas[].de" => "o começo da faixa (entra); null numa faixa de categoria",
                "criterios.conjuntos[].parametros[].subparametros[].faixas[].id" => "o número da faixa",
                "criterios.conjuntos[].parametros[].subparametros[].faixas[].nota" => "a nota da faixa, de 0 a 100",
                "criterios.conjuntos[].parametros[].subparametros[].id" => "o número do subparâmetro",
                "criterios.conjuntos[].parametros[].subparametros[].nome" => "o nome do subparâmetro",
                "criterios.conjuntos[].parametros[].subparametros[].ordem" => "a posição dele no parâmetro",
                "criterios.conjuntos[].parametros[].subparametros[].peso" => "o peso no parâmetro, em % (os do parâmetro somam 100)",
                "criterios.conjuntos[].parametros[].subparametros[].peso_efetivo_no_conjunto" => "peso do subparâmetro × peso do parâmetro ÷ 100, em %",
                "criterios.conjuntos[].parametros[].subparametros[].variavel" => "o que ele mede: o identificador de um campo ou de uma fórmula",
                "criterios.conjuntos[].usado_por[]" => "os relógios disponíveis que usam este conjunto (ids)",
                "criterios.lugares[]" => "os lugares que podem ter critérios: todos os relógios, cada grupo (em ordem de árvore) e cada relógio",
                "criterios.lugares[].escopo" => "o lugar: \"\" (todos), g:<grupo> ou r:<relógio>",
                "criterios.lugares[].herda" => "o lugar de quem ele herda os critérios quando não tem os seus; null: ninguém (nota neutra)",
                "criterios.lugares[].herda_texto" => "esse lugar por extenso",
                "criterios.lugares[].lugar" => "o lugar por extenso, curto",
                "criterios.lugares[].proprio" => "verdadeiro ou falso: o lugar tem critérios próprios",
                "criterios.lugares[].texto" => "o lugar como a lista da página mostra (\"Grupo: Tradicional › Mecânico\")",
                "criterios.max_sem_uso" => "a garantia de rodízio em dias (0: desligada)",
                "criterios.modos[]" => "os modos de rodízio e a forma de escolher de cada um",
                "criterios.modos[].ativo" => "verdadeiro ou falso: é o modo em uso",
                "criterios.modos[].escala" => "verdadeiro ou falso: o modo é escala inteligente (escolhe sempre pela maior nota)",
                "criterios.modos[].nome" => "o nome do modo",
                "criterios.modos[].selecao" => "a forma de escolha: inteligente (a maior nota), ponderado (sorteio pela nota), aleatorio ou fifo (o mais tempo sem uso)",
                "criterios.notas[]" => "a nota de cada relógio agora, com a conta",
                "criterios.notas[].conjunto" => "o lugar dos critérios usados: \"\" (todos), g:<grupo> ou r:<relógio>; null: nenhum (nota neutra, 50)",
                "criterios.notas[].conjunto_texto" => "esse lugar por extenso",
                "criterios.notas[].conta[]" => "cada subparâmetro que entrou na conta",
                "criterios.notas[].conta[].faixa" => "a faixa em que o valor caiu (\"30 a 50\", ou a categoria)",
                "criterios.notas[].conta[].nota" => "a nota da faixa, de 0 a 100 (número)",
                "criterios.notas[].conta[].parametro" => "o nome do parâmetro",
                "criterios.notas[].conta[].peso_efetivo" => "o peso no conjunto: peso do subparâmetro × peso do parâmetro, reescalado sem os que não se aplicam, em % (número)",
                "criterios.notas[].conta[].pontos" => "nota × peso efetivo ÷ 100 (número): quanto somou na nota",
                "criterios.notas[].conta[].subparametro" => "o nome do subparâmetro",
                "criterios.notas[].conta[].valor" => "o valor medido (número, ou o texto de uma lista)",
                "criterios.notas[].conta[].variavel" => "o que o subparâmetro mede (o identificador do campo ou da fórmula)",
                "criterios.notas[].disponivel" => "verdadeiro ou falso: o relógio está disponível para o rodízio",
                "criterios.notas[].grupo" => "o grupo do relógio (o caminho na árvore)",
                "criterios.notas[].nota" => "a nota final, de 0 a 100 (número): a soma dos pontos",
                "criterios.notas[].relogio" => "o nome do relógio",
                "criterios.notas[].relogio_id" => "o relógio",
                "criterios.variaveis.<variavel>" => "o que um subparâmetro pode medir: cada campo (menos texto) e cada fórmula, pelo identificador",
                "criterios.variaveis.<variavel>.max" => "o limite da medida (a última faixa vai até ele ou sem limite): o que o nome diz em \"(0 a N)\"; null: sem limite",
                "criterios.variaveis.<variavel>.nome" => "o nome (com a unidade)",
                "criterios.variaveis.<variavel>.tipo" => "numero ou categoria (campo de lista)",
                "criterios.variaveis.<variavel>.valores[]" => "as categorias possíveis (campo de lista); vazio nos números",
                "cron.erro" => "o erro guardado (texto; vazio: sem erro; some na primeira execução sem erro)",
                "cron.execucoes[]" => "as execuções do cron guardadas (sem atividade: 7 dias; com atividade ou erro: 1 ano), da mais recente",
                "cron.execucoes[].duracao_ms" => "quanto durou, em milissegundos (número inteiro)",
                "cron.execucoes[].fim" => "quando terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "cron.execucoes[].id" => "o número da execução",
                "cron.execucoes[].inicio" => "quando começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "cron.execucoes[].registro" => "o que foi feito, linha por linha (texto; vazio: nada a fazer)",
                "cron.execucoes[].teve_atividade" => "verdadeiro ou falso: fez alguma coisa (rodada, plano, evento, sincronização)",
                "cron.execucoes[].teve_erro" => "verdadeiro ou falso: houve erro",
                "cron.ultima" => "quando o cron rodou pela última vez (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[]" => "os eventos personalizados (avisos seus, com horário e repetição próprios)",
                "eventos[].agenda" => "verdadeiro ou falso: o evento vai para o Google Agenda",
                "eventos[].ativo" => "verdadeiro ou falso: o evento dispara",
                "eventos[].criado" => "quando o evento foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].data_inicio" => "a data (uma vez só) ou o começo da contagem (a cada N dias) (texto AAAA-MM-DD); null nas outras",
                "eventos[].descricao" => "a repetição por extenso (\"seg, qua 20:00\")",
                "eventos[].dia_mes" => "o dia do mês (mensal), de 1 a 31; null nas outras",
                "eventos[].dias_semana" => "os dias da semana (semanal), de 1 (segunda) a 7 (domingo), separados por vírgula; null nas outras",
                "eventos[].disparos[]" => "as vezes em que já disparou",
                "eventos[].disparos[].disparado" => "quando o cron mandou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].disparos[].ocorrencia" => "a vez que disparou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema, a do cadastro)",
                "eventos[].hora" => "a hora do disparo (HH:MM)",
                "eventos[].id" => "o número do evento (o tipo dele nos canais é ev<id>)",
                "eventos[].intervalo_dias" => "de quantos em quantos dias (intervalo); null nas outras",
                "eventos[].nome" => "o nome do evento (texto)",
                "eventos[].proximas[]" => "as 10 próximas vezes em que dispara (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].relogio" => "o nome desse relógio; null: evento geral",
                "eventos[].relogio_id" => "o relógio do evento (as âncoras dele funcionam na mensagem); null: evento geral",
                "eventos[].repeticao" => "quando dispara: uma (uma vez só), diaria, semanal, mensal ou intervalo (a cada N dias)",
                "eventos[].telegram" => "verdadeiro ou falso: o evento vai pelo Telegram (marcado em \"O que vai para onde\")",
                "formulas[]" => "as fórmulas (cada identificador pode ter uma versão por grupo; vale a do grupo mais perto do relógio)",
                "formulas[].expressao" => "a conta (texto, na escrita das fórmulas do motor)",
                "formulas[].id" => "o número da versão",
                "formulas[].identificador" => "o nome da fórmula nas outras fórmulas e nos critérios",
                "formulas[].lugar" => "esse grupo por extenso",
                "formulas[].no_id" => "o grupo desta versão; null: todos",
                "formulas[].nome" => "o nome da fórmula",
                "formulas[].unidade" => "a unidade do resultado (texto)",
                "formulas[].usa.lancamentos[]" => "os tipos de lançamento que a conta usa",
                "formulas[].usa.variaveis[]" => "os campos e fórmulas que a conta usa",
                "lancamento_tipos[]" => "os tipos de lançamento: o que se registra num relógio",
                "lancamento_tipos[].condicao" => "vale quando: uma fórmula que diz para quais relógios do grupo o tipo vale (1 vale, 0 não; ex.: corda_manual, só quem aceita corda); null: vale para todos",
                "lancamento_tipos[].exclusiva" => "a sessão é exclusiva: o relógio fica num lugar só (abrir fecha a outra exclusiva)",
                "lancamento_tipos[].fecha_as" => "sessão esquecida aberta fecha sozinha a essa hora do dia em que começou (HH:MM; null: não fecha)",
                "lancamento_tipos[].formato" => "instantaneo (uma marcação: corda), valor (uma leitura: carga) ou sessao (com início e fim: pulso, sol)",
                "lancamento_tipos[].id" => "o número do tipo",
                "lancamento_tipos[].identificador" => "o nome do tipo nas fórmulas (HORAS(\"pulso\"; 30))",
                "lancamento_tipos[].lugar" => "esse grupo por extenso",
                "lancamento_tipos[].mede_gasto" => "cada leitura desse tipo mede o gasto, comparando com a anterior (a função MEDIDO)",
                "lancamento_tipos[].no_id" => "o grupo em que o tipo vale; null: todos",
                "lancamento_tipos[].nome" => "o nome do tipo",
                "lancamento_tipos[].ordem" => "a posição do tipo",
                "lancamento_tipos[].unidade" => "a unidade do valor (texto)",
                "migracoes.pendentes[]" => "as migrações do banco que faltam aplicar (vazio: em dia)",
                "migracoes.pendentes[].arquivo" => "o arquivo .sql",
                "migracoes.pendentes[].traz" => "o que a migração traz",
                "migracoes.pendentes[].versao" => "a versão (v2, v3...)",
                "modos[]" => "os modos de rodízio",
                "modos[].ativo" => "verdadeiro ou falso: é o modo em uso",
                "modos[].blocos[]" => "os blocos de dias da semana do modo",
                "modos[].blocos[].dias[]" => "os dias da semana do bloco, de 1 (segunda) a 7 (domingo)",
                "modos[].blocos[].id" => "o número do bloco",
                "modos[].blocos[].lugar" => "esse grupo por extenso",
                "modos[].blocos[].no_id" => "o grupo de onde sortear; null: todos",
                "modos[].blocos[].nome" => "o nome do bloco",
                "modos[].blocos[].relogio_id" => "o relógio fixo do bloco; null: sorteia",
                "modos[].blocos[].um_por" => "dia (um relógio por dia) ou bloco (um para o bloco inteiro na semana)",
                "modos[].ciclo" => "(verdadeiro ou falso) o modo usa o ciclo: um relógio só volta depois que todos os disponíveis do bloco passaram na semana (na escala, pelo período dela); dentro do ciclo, a forma de escolha decide a ordem",
                "modos[].escala_dias" => "escala inteligente com esse horizonte, em dias (7 a 730); null: sorteio pelos blocos",
                "modos[].id" => "o número do modo",
                "modos[].nome" => "o nome do modo",
                "modos[].ordem" => "a posição do modo",
                "modos[].selecao" => "a forma de escolha: inteligente, ponderado, aleatorio ou fifo",
                "motor.formatos_de_lancamento.<formato>" => "cada formato de lançamento e o nome dele",
                "motor.funcoes.<funcao>" => "cada função do motor de fórmulas e como usar (texto)",
                "motor.tipos_de_campo.<tipo_de_campo>" => "cada tipo de campo e o nome dele",
                "plano[]" => "o plano gravado: o relógio de cada dia",
                "plano[].acao" => "o lembrete do dia (o que fazer antes: carregar, dar corda...); null: nada",
                "plano[].bloco" => "o nome desse bloco",
                "plano[].bloco_id" => "o bloco do modo que escolheu o dia; null: escala ou manual",
                "plano[].criado" => "quando o dia foi gravado no plano (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "plano[].data" => "o dia (texto AAAA-MM-DD)",
                "plano[].motivo" => "por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null: o dia foi gravado antes de o sistema guardar o motivo",
                "plano[].origem" => "sorteio (pelo modo) ou manual (\"Usando hoje\")",
                "plano[].relogio" => "o nome dele",
                "plano[].relogio_id" => "o relógio do dia",
                "relogios[]" => "os relógios, com tudo o que se sabe de cada um",
                "relogios[].acaba_em_datacomtz" => "quando acaba, em ISO 8601 com o fuso (texto, ex.: 2026-10-02T18:40:00-03:00); null com o motivo",
                "relogios[].acaba_em_segundos" => "quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em segundos; igual a autonomia_estimada); null com o motivo",
                "relogios[].acaba_em_unixtimestamp" => "quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null com o motivo",
                "relogios[].autonomia_atual" => "quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no smartwatch, pelo gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo informado; nos outros, igual à prevista; a diferença para a prevista mostra a bateria envelhecendo; a fórmula autonomia_atual",
                "relogios[].autonomia_estimada" => "quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em que está no rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em motivos",
                "relogios[].autonomia_prevista" => "quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do smartwatch, a reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula autonomia_prevista; null se falta o dado no cadastro",
                "relogios[].avisos[]" => "os avisos do relógio agora (os que valem para ele e têm data prevista), do mais urgente ao mais distante",
                "relogios[].avisos[].agenda" => "a data na agenda: janela (só dentro da antecedência da agenda) ou sempre (qualquer data); se vai pela agenda é a Configuração",
                "relogios[].avisos[].data" => "a data prevista, pela fórmula do aviso (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].avisos[].escala" => "na escala inteligente: nao, uso (conferido no relógio do dia) ou sempre (também nos guardados)",
                "relogios[].avisos[].estado" => "atrasado (a data passou), em_breve (dentro da antecedência) ou ok",
                "relogios[].avisos[].falta_dias" => "quanto falta para a data prevista, em dias (número; negativo: já passou)",
                "relogios[].avisos[].identificador" => "o aviso (corda, carregar, sol, pilha, revisao, garantia, carga_baixa, leitura, ou um que você cadastrou)",
                "relogios[].avisos[].modelo" => "o texto do cadastro, sem trocar as âncoras (só o {limite})",
                "relogios[].avisos[].nome" => "o nome do aviso (\"Dar corda\") — a âncora {acao}",
                "relogios[].avisos[].resolve" => "o tipo de lançamento que resolve o aviso (o botão na tela Hoje); null: nenhum",
                "relogios[].avisos[].simula_horas" => "as horas da sessão que a escala simula para resolver o aviso (número; null: nenhuma)",
                "relogios[].avisos[].simula_valor" => "o valor do lançamento que a escala simula para resolver o aviso (leitura) (número; null: nenhum)",
                "relogios[].avisos[].texto" => "o motivo por extenso, com {relogio}, {data}, {quando} e {limite} trocados — a âncora {motivo}",
                "relogios[].avisos[].versao" => "o lugar da árvore da versão do aviso usada (texto)",
                "relogios[].calculado_em_unixtimestamp" => "o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); segundos que faltam agora = acaba_em_unixtimestamp − hora atual",
                "relogios[].campos[]" => "os campos do cadastro que valem para ele",
                "relogios[].campos[].identificador" => "o campo",
                "relogios[].campos[].nome" => "o nome do campo",
                "relogios[].campos[].tipo" => "o tipo do campo (inteiro, decimal, sim_nao, data, lista, texto)",
                "relogios[].campos[].unidade" => "a unidade",
                "relogios[].campos[].valor" => "o valor gravado no relógio (texto; null: não preenchido)",
                "relogios[].campos[].valor_usado" => "o valor que as contas usam: o gravado, ou o padrão do campo",
                "relogios[].criado" => "quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].disponivel" => "verdadeiro ou falso: entra no rodízio",
                "relogios[].copia_banco" => "verdadeiro: o relógio pede a cópia no banco de todos os documentos dele (o segundo dos três níveis da cópia; o DOCUMENTOS_COPIA_BANCO do config.php, se definido, vale por cima)",
                "relogios[].em_uso" => "verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora",
                "relogios[].energia" => "a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para calcular",
                "relogios[].formulas[]" => "o resultado agora de cada fórmula que vale para ele",
                "relogios[].formulas[].identificador" => "a fórmula",
                "relogios[].formulas[].nome" => "o nome da fórmula",
                "relogios[].formulas[].unidade" => "a unidade do resultado",
                "relogios[].formulas[].valor" => "o resultado agora (número ou texto; null: vazio)",
                "relogios[].formulas[].versao" => "o grupo da versão usada",
                "relogios[].foto" => "a foto; null: sem foto",
                "relogios[].foto.atualizada_em" => "quando a foto foi trocada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].foto.base64" => "a imagem em base64; null com foto=nao",
                "relogios[].foto.tipo" => "o tipo da imagem (image/jpeg, image/png, image/webp)",
                "relogios[].id" => "o número (código) do relógio",
                "relogios[].lancamento_tipos[]" => "os identificadores dos tipos de lançamento que valem para ele (texto)",
                "relogios[].lancamentos[]" => "todos os lançamentos do relógio, do mais antigo para o mais recente",
                "relogios[].lancamentos[].criado" => "quando foi gravado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].lancamentos[].fim" => "o fim da sessão (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null: marcação, leitura, ou sessão aberta",
                "relogios[].lancamentos[].id" => "o número do lançamento",
                "relogios[].lancamentos[].inicio" => "quando (a marcação ou a leitura), ou o começo da sessão (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].lancamentos[].origem" => "manual (lançado por alguém), rodizio (a sessão do dia, pelo cron) ou importado",
                "relogios[].lancamentos[].tipo" => "o identificador do tipo",
                "relogios[].lancamentos[].valor" => "o valor da leitura (número); null nos outros",
                "relogios[].linha_do_tempo[]" => "os trechos da linha do tempo do relógio, do mais recente para o mais antigo: cada trecho é um período contínuo num estado, ou uma marcação (um lançamento instantâneo)",
                "relogios[].linha_do_tempo[].duracao" => "a mesma duração por extenso (\"2d 3h\", \"40min\"); null numa marcação",
                "relogios[].linha_do_tempo[].duracao_seg" => "quanto o trecho durou, número inteiro, em segundos; null numa marcação",
                "relogios[].linha_do_tempo[].em_andamento" => "verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)",
                "relogios[].linha_do_tempo[].estado" => "o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)",
                "relogios[].linha_do_tempo[].fim" => "quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação",
                "relogios[].linha_do_tempo[].inicio" => "quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].linha_do_tempo[].texto" => "o trecho por extenso, como a tela mostra (\"em uso\", \"no sol\", \"Leitura de carga 80%\")",
                "relogios[].linha_do_tempo[].tipo" => "o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no repouso",
                "relogios[].lugar" => "o grupo por extenso",
                "relogios[].medicoes[]" => "as medições do gasto pelas leituras (cada leitura de um tipo que mede o gasto, comparada com a anterior), da mais recente para a mais antiga",
                "relogios[].medicoes[].ate_valor" => "a leitura que fechou a medição (número)",
                "relogios[].medicoes[].criado" => "quando a medição foi gravada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].medicoes[].de_valor" => "a leitura de antes (número, na unidade da leitura)",
                "relogios[].medicoes[].fim" => "quando foi a leitura que fechou a medição (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].medicoes[].horas_guardado" => "as horas guardado entre as duas leituras (número)",
                "relogios[].medicoes[].horas_pulso" => "as horas no pulso entre as duas leituras (número)",
                "relogios[].medicoes[].id" => "o número da medição",
                "relogios[].medicoes[].inicio" => "quando foi a leitura de antes (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].medicoes[].lancamento_id" => "o lançamento (a leitura) que fechou a medição",
                "relogios[].medicoes[].medida" => "o gasto do intervalo sozinho, no histórico: uso (o gasto por dia de uso, quando o intervalo teve meio dia de uso ou mais, ou mais dias de uso que fora) ou repouso (o gasto por dia fora do pulso); o gasto que vale sai de todas as medições juntas",
                "relogios[].medicoes[].na_media" => "verdadeiro ou falso: entra na média agora (usada e dentro da janela da Configuração, medicao_janela_dias)",
                "relogios[].medicoes[].peso_horas" => "o peso da medição na média: as horas que ela cobriu, no pulso (uso) ou guardado (repouso) (número)",
                "relogios[].medicoes[].taxa" => "o gasto medido, em % por dia de uso (uso) ou por dia guardado (repouso) (número)",
                "relogios[].medicoes[].usada" => "verdadeiro ou falso: a caixa \"Atualizar o gasto com esta medição\" estava marcada (entra na média); falso: só histórico",
                "relogios[].motivos" => "por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)",
                "relogios[].motivos.<campo>" => "o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a zero em até 10 anos",
                "relogios[].no_id" => "o grupo do relógio; null: na raiz",
                "relogios[].nome" => "o nome do relógio",
                "relogios[].previsao" => "a previsão da energia (só nos relógios com leitura, como o smartwatch): as frases da tela e os mesmos números",
                "relogios[].previsao.aplica" => "verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)",
                "relogios[].previsao.carga_na_entrada" => "com quanto ele entra nesse dia, em % (número)",
                "relogios[].previsao.carregar_antes" => "verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)",
                "relogios[].previsao.chega_limite_em" => "quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa de um ano",
                "relogios[].previsao.confianca" => "a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media ou baixa (leitura com mais de 7 dias)",
                "relogios[].previsao.conta[]" => "de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras",
                "relogios[].previsao.conta[].campo" => "o identificador do campo, ou MEDIDO(\"uso\") / MEDIDO(\"repouso\") para o gasto medido",
                "relogios[].previsao.conta[].nome" => "o nome do campo (ou do gasto medido, com quantas medições entraram)",
                "relogios[].previsao.conta[].origem" => "de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido (pelas leituras)",
                "relogios[].previsao.conta[].unidade" => "a unidade do valor (texto)",
                "relogios[].previsao.conta[].valor" => "o valor usado na conta (número; null se vazio)",
                "relogios[].previsao.dura_ate" => "até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)",
                "relogios[].previsao.dura_dias" => "quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)",
                "relogios[].previsao.energia" => "a energia agora, em % (número; null sem leitura)",
                "relogios[].previsao.gasto" => "os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as tem)",
                "relogios[].previsao.gasto.conjunta" => "true: os dois gastos saem juntos da conta das medições; false: a média das medições de cada um",
                "relogios[].previsao.gasto.janela_dias" => "a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)",
                "relogios[].previsao.gasto.medicoes" => "quantas medições há na janela (número)",
                "relogios[].previsao.gasto.medicoes_antes" => "quantas medições há na janela anterior, a dos antes (número)",
                "relogios[].previsao.gasto.uso.antes" => "o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)",
                "relogios[].previsao.gasto.uso.cadastro" => "o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)",
                "relogios[].previsao.gasto.uso.medido" => "o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null sem medição)",
                "relogios[].previsao.gasto.uso.vale" => "o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)",
                "relogios[].previsao.gasto.repouso.antes" => "o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)",
                "relogios[].previsao.gasto.repouso.cadastro" => "o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)",
                "relogios[].previsao.gasto.repouso.medido" => "o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null sem medição)",
                "relogios[].previsao.gasto.repouso.vale" => "o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)",
                "relogios[].previsao.leitura" => "a última leitura, no valor informado (número)",
                "relogios[].previsao.leitura_em" => "quando foi a última leitura (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].previsao.limite" => "o limite de carga da Configuração (previsao_limite), em % (número)",
                "relogios[].previsao.linhas[]" => "as frases da previsão, como a tela mostra (texto)",
                "relogios[].previsao.precisa" => "quanto ele precisa ter na entrada para os dias seguidos no pulso, em % (número)",
                "relogios[].previsao.proxima_entrada" => "o próximo dia em que o relógio entra no rodízio (texto AAAA-MM-DD); null se não está no plano",
                "relogios[].restante_em_uso" => "quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em segundos); null com o motivo (ex.: o automático no pulso se recarrega e não acaba)",
                "relogios[].restante_guardado" => "quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em segundos); null com o motivo",
                "relogios[].resumo_do_tempo.marcacoes.<tipo_de_marcacao>" => "quantas marcações de cada tipo (corda, carga, pilha...) (número)",
                "relogios[].resumo_do_tempo.tempo.<estado>.porcentagem" => "a parte desse estado no tempo total, em %",
                "relogios[].resumo_do_tempo.tempo.<estado>.segundos" => "quanto tempo o relógio passou no estado <estado> (rodizio, pulso, winder, sol, repouso...), número inteiro, em segundos",
                "relogios[].resumo_do_tempo.tempo.<estado>.texto" => "o mesmo tempo por extenso",
                "usuarios[]" => "quem acessa o site, com o hash da senha (a senha não existe no sistema)",
                "usuarios[].criado" => "quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "usuarios[].login" => "o login",
                "usuarios[].senha_algoritmo" => "o cálculo do hash: bcrypt (o padrão do password_hash do PHP), ou argon2i/argon2id",
                "usuarios[].senha_custo" => "o custo do bcrypt (quantas rodadas: 2 elevado a ele), que também está escrito no próprio hash ($2y$12$...: 12); null no argon2",
                "usuarios[].senha_hash" => "o hash da senha (a senha não existe no sistema): $2y$<custo>$ seguido do sal (22 caracteres) e do resultado (31). Não se desfaz em senha: confere-se a senha digitada contra ele (veja senhas na ajuda). Levado para outro sistema que confira por bcrypt, o usuário entra lá com a mesma senha; e volta para cá por usuarios/salvar com senha_hash",
                "documento_categorias[]" => "as categorias dos documentos, na ordem",
                "documento_categorias[].aceita[]" => "os tipos de arquivo que ela aceita (imagem, video, audio, pdf, xml); lista vazia: qualquer arquivo",
                "documento_categorias[].documentos" => "quantos documentos ela tem",
                "documento_categorias[].id" => "o número da categoria",
                "documento_categorias[].identificador" => "o identificador (minúsculas, números e _)",
                "documento_categorias[].nome" => "o nome",
                "documento_categorias[].ordem" => "a ordem em que ela aparece (número)",
                "relogios[].documentos[]" => "os documentos do relógio (o manual, a nota fiscal, fotos, vídeos...): os dados de cada um; o arquivo vem pelo recurso=documento",
                "relogios[].documentos[].categoria_id" => "a categoria dele (as categorias dos documentos)",
                "relogios[].documentos[].criado" => "quando foi enviado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].documentos[].data" => "a data do documento: a ocasião da foto, a data da nota (texto AAAA-MM-DD; null: sem data)",
                "relogios[].documentos[].descricao" => "a descrição (texto; null: sem descrição)",
                "relogios[].documentos[].familia" => "como ele abre, pelo tipo do arquivo: imagem (a galeria), video (o player), audio, pdf (o visualizador), xml (o resumo da nota e o download) ou outro (o download)",
                "relogios[].documentos[].id" => "o número do documento (para recurso=documento e para alterar ou excluir)",
                "relogios[].documentos[].miniatura" => "verdadeiro: a foto tem miniatura (recurso=documento com mini=1)",
                "relogios[].documentos[].nome" => "o nome do arquivo enviado",
                "relogios[].documentos[].no_disco" => "verdadeiro: o arquivo está na pasta dos documentos (falso: ele volta do banco quando for pedido ou na próxima rodada do cron; sem a cópia no banco, está perdido)",
                "relogios[].documentos[].no_banco" => "verdadeiro: a cópia de segurança do arquivo está completa no banco (se ele sumir da pasta, volta dali)",
                "relogios[].documentos[].copia_banco" => "verdadeiro: o próprio arquivo pede a cópia no banco (o terceiro nível; o relógio e o config.php, se pedem, valem por cima)",
                "relogios[].documentos[].copia_por" => "quem pede a cópia no banco: sistema (o DOCUMENTOS_COPIA_BANCO = true do config.php), relogio (a marca do relógio) ou arquivo (a marca do próprio arquivo); null: ninguém pede, ou o config.php (false) não deixa, e o arquivo fica só na pasta (a cópia que houver, o cron tira)",
                "relogios[].documentos[].relogio_id" => "o relógio dele",
                "relogios[].documentos[].tamanho" => "o tamanho do arquivo, em bytes (número inteiro)",
                "relogios[].documentos[].tipo" => "o tipo do arquivo (MIME): image/jpeg, video/mp4, application/pdf, application/xml...",
                "relogios[].documentos[].titulo" => "o título",
                "relogios[].documentos[].url" => "o endereço do arquivo, relativo à pasta do sistema (api.php?recurso=documento&id=...)",
                "relogios[].documentos[].nfe" => "sempre null aqui: o resumo da NF-e vem no recurso=documentos",
            ],
            "autonomia" => [
                "calculado_em_unixtimestamp" => "o instante da conta (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))",
                "relogios[]" => "um relógio por item, com as autonomias",
                "relogios[].acaba_em_datacomtz" => "quando acaba, em ISO 8601 com o fuso (texto, ex.: 2026-10-02T18:40:00-03:00); null com o motivo",
                "relogios[].acaba_em_segundos" => "quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em segundos; igual a autonomia_estimada); null com o motivo",
                "relogios[].acaba_em_unixtimestamp" => "quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null com o motivo",
                "relogios[].autonomia_atual" => "quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no smartwatch, pelo gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo informado; nos outros, igual à prevista; a diferença para a prevista mostra a bateria envelhecendo; a fórmula autonomia_atual",
                "relogios[].autonomia_estimada" => "quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em que está no rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em motivos",
                "relogios[].autonomia_prevista" => "quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do smartwatch, a reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula autonomia_prevista; null se falta o dado no cadastro",
                "relogios[].calculado_em_unixtimestamp" => "o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); segundos que faltam agora = acaba_em_unixtimestamp − hora atual",
                "relogios[].disponivel" => "verdadeiro ou falso: entra no rodízio",
                "relogios[].em_uso" => "verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora",
                "relogios[].energia" => "a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para calcular",
                "relogios[].id" => "o número (código)",
                "relogios[].lugar" => "o grupo por extenso",
                "relogios[].motivos" => "por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)",
                "relogios[].motivos.<campo>" => "o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a zero em até 10 anos",
                "relogios[].nome" => "o nome",
                "relogios[].restante_em_uso" => "quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em segundos); null com o motivo (ex.: o automático no pulso se recarrega e não acaba)",
                "relogios[].restante_guardado" => "quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em segundos); null com o motivo",
            ],
            "hoje" => [
                "agora" => "o instante da resposta (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))",
                "avisos[]" => "os avisos de hoje (atrasados ou em breve), um por relógio e aviso",
                "avisos[].estado" => "atrasado ou em_breve",
                "avisos[].identificador" => "o aviso",
                "avisos[].nome" => "o nome do aviso",
                "avisos[].relogio" => "o nome do relógio",
                "avisos[].relogio_id" => "o relógio",
                "avisos[].resolve" => "o lançamento do botão que resolve; null: nenhum",
                "avisos[].resolve.formato" => "o formato (instantaneo, valor, sessao)",
                "avisos[].resolve.identificador" => "o tipo de lançamento",
                "avisos[].resolve.nome" => "o nome dele",
                "avisos[].resolve.unidade" => "a unidade do valor",
                "avisos[].texto" => "o motivo por extenso",
                "avisos_nomes[]" => "os nomes dos avisos (o filtro \"O que fazer\" da tabela)",
                "comecou" => "verdadeiro ou falso: o dia já começou no pulso (há relógio do dia e: pondo no pulso sozinho, passou do uso_inicio; senão, ele já foi posto no pulso hoje)",
                "pulso_poe_sozinho" => "verdadeiro: o relógio do dia entra no pulso sozinho no início do horário de uso; falso: só pelo Pôs",
                "pulso_tira_sozinho" => "verdadeiro: o relógio sai do pulso sozinho no fim do horário de uso; falso: só pelo Tirou",
                "data" => "hoje (texto AAAA-MM-DD)",
                "dia" => "o relógio de hoje; null: nenhum",
                "dia.acao" => "o lembrete do dia; null: nada",
                "dia.ate" => "até quando ele fica (\"só hoje\", \"até sexta, 02/10\")",
                "dia.data" => "hoje",
                "dia.motivo" => "por que ele saiu hoje, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null: não foi guardado",
                "dia.relogio" => "o nome dele",
                "dia.relogio_id" => "o relógio de hoje",
                "escala_fim" => "até que dia vai a escala (texto AAAA-MM-DD); null fora da escala",
                "grupos[]" => "os grupos, em ordem de árvore",
                "grupos[].caminho" => "o caminho",
                "grupos[].id" => "o número",
                "grupos[].nome" => "o nome",
                "grupos[].profundidade" => "o nível (0: raiz)",
                "max_sem_uso" => "a garantia de rodízio em dias",
                "modo" => "o modo de rodízio em uso",
                "modo.escala_dias" => "o horizonte da escala inteligente, em dias; null: sorteio pelos blocos",
                "modo.id" => "o número do modo",
                "modo.nome" => "o nome",
                "modo.selecao" => "a forma de escolha (inteligente, ponderado, aleatorio, fifo)",
                "modos[]" => "os modos de rodízio (o quadro Modo de rodízio)",
                "modos[].blocos[]" => "os blocos",
                "modos[].blocos[].dias" => "os dias da semana, de 1 a 7, separados por vírgula",
                "modos[].blocos[].id" => "o número do bloco",
                "modos[].blocos[].no_id" => "o grupo de onde sortear (0: todos)",
                "modos[].blocos[].nome" => "o nome",
                "modos[].blocos[].relogio_id" => "o relógio fixo; null: sorteia",
                "modos[].blocos[].um_por" => "dia ou bloco",
                "modos[].ciclo" => "(verdadeiro ou falso) o modo usa o ciclo: um relógio só volta depois que todos os disponíveis do bloco passaram na semana (na escala, pelo período dela); dentro do ciclo, a forma de escolha decide a ordem",
                "modos[].escala_dias" => "o horizonte da escala; null: sorteio",
                "modos[].id" => "o número",
                "modos[].nome" => "o nome",
                "modos[].selecao" => "a forma de escolha",
                "plano[]" => "os próximos 62 dias do plano",
                "plano[].acao" => "o lembrete do dia; null",
                "plano[].motivo" => "por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null: o dia foi gravado antes de o sistema guardar o motivo",
                "plano[].origem" => "sorteio (pelo modo) ou manual (escolhido à mão: \"trocar por…\" ou \"Usando hoje\")",
                "plano[].data" => "o dia",
                "plano[].relogio" => "o nome",
                "plano[].relogio_id" => "o relógio",
                "proxima_semana.domingo" => "o domingo dela",
                "proxima_semana.ja_montada" => "verdadeiro ou falso: a próxima semana já está no plano",
                "proxima_semana.segunda" => "a segunda-feira da próxima semana (texto AAAA-MM-DD)",
                "relogios[]" => "a tabela dos relógios",
                "relogios[].agora" => "o estado por extenso (\"Em uso desde 07:00\")",
                "relogios[].carga" => "a energia agora, em % inteiro; null: indisponível ou sem dados",
                "relogios[].carga_de" => "de onde vem a energia (\"bateria do smartwatch\") ou por que não há",
                "relogios[].com_aviso" => "verdadeiro ou falso: tem aviso hoje",
                "relogios[].compra.data" => "a data da compra (texto AAAA-MM-DD; null)",
                "relogios[].compra.garantia_ate" => "até quando vai a garantia (texto AAAA-MM-DD; null)",
                "relogios[].compra.loja" => "onde comprou (null)",
                "relogios[].compra.valor" => "o valor pago (número; null)",
                "relogios[].de_hoje" => "verdadeiro ou falso: é o relógio de hoje",
                "relogios[].disponivel" => "verdadeiro ou falso: entra no rodízio",
                "relogios[].em_uso" => "verdadeiro ou falso: no pulso agora",
                "relogios[].foto" => "a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC), para o endereço dela); null: sem foto",
                "relogios[].id" => "o número (código)",
                "relogios[].leitura" => "a última leitura com valor; null: nenhuma",
                "relogios[].leitura.inicio" => "quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogios[].leitura.unidade" => "a unidade",
                "relogios[].leitura.valor" => "o valor",
                "relogios[].manutencao" => "o aviso mais perto; null: nenhum",
                "relogios[].manutencao.data" => "a data (texto AAAA-MM-DD; hoje se já passou)",
                "relogios[].manutencao.falta" => "quanto falta, por extenso (\"10h 49min\", \"atrasado 2d\")",
                "relogios[].manutencao.momento" => "o instante previsto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))",
                "relogios[].manutencao.nome" => "o aviso",
                "relogios[].nome" => "o nome",
                "relogios[].situacao[]" => "as frases de situação (\"Autonomia restante 1d 23min\")",
                "relogios[].tipo" => "o grupo do relógio (o nome)",
                "relogios[].ultimo" => "a última vez no pulso (número inteiro: instante Unix (segundos desde 01/01/1970 UTC); 0: nunca)",
                "relogios[].ultimo_txt" => "a mesma por extenso (\"agora · 14:32\", \"25/09 21:53\", \"nunca\")",
                "totais" => "os totais da coleção inteira (a tabela da página Hoje soma só os relógios que os filtros dela deixam à mostra)",
                "totais.disponiveis" => "quantos estão disponíveis para o rodízio",
                "totais.em_uso" => "quantos estão em uso agora",
                "totais.relogios" => "quantos relógios há",
                "totais.valor" => "a soma do valor pago (valor_compra) de todos, em R$ (os sem valor contam 0)",
                "uso_inicio" => "a hora em que o relógio do dia vai para o pulso (HH:MM)",
            ],
            "ficha" => [
                "campos[]" => "os campos do cadastro",
                "campos[].identificador" => "o campo",
                "campos[].no_id" => "o grupo em que vale (0: todos)",
                "campos[].nome" => "o nome",
                "campos[].opcoes[]" => "as opções (lista)",
                "campos[].padrao" => "o valor padrão",
                "campos[].tipo" => "o tipo",
                "campos[].unidade" => "a unidade",
                "campos[].valor" => "o valor do relógio (null)",
                "grupos[]" => "os grupos (o seletor do cadastro)",
                "grupos[].cadeia[]" => "os ids do grupo e dos de cima",
                "grupos[].caminho" => "o caminho",
                "grupos[].id" => "o número",
                "hoje" => "hoje (texto AAAA-MM-DD)",
                "copia_sistema" => "a cópia no banco pelo config.php (DOCUMENTOS_COPIA_BANCO), que vale sobre o relógio e o arquivo: verdadeiro, todo arquivo vai; falso, nenhum vai; null (sem a constante), cada relógio decide (a caixa do cadastro só aparece então)",
                "painel_modo" => "como o relógio abre na página Hoje (a Configuração): lado (no painel à direita da lista, o padrão) ou flutuante (numa janela grande por cima da página); no celular, os dois cobrem a tela",
                "relogio" => "o relógio; null: o cadastro de um relógio novo",
                "relogio.agora" => "o estado por extenso",
                "relogio.autonomia" => "as autonomias do relógio (as mesmas do recurso autonomia)",
                "relogio.autonomia.acaba_em_datacomtz" => "quando acaba, em ISO 8601 com o fuso (texto, ex.: 2026-10-02T18:40:00-03:00); null com o motivo",
                "relogio.autonomia.acaba_em_segundos" => "quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em segundos; igual a autonomia_estimada); null com o motivo",
                "relogio.autonomia.acaba_em_unixtimestamp" => "quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null com o motivo",
                "relogio.autonomia.autonomia_atual" => "quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no smartwatch, pelo gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo informado; nos outros, igual à prevista; a diferença para a prevista mostra a bateria envelhecendo; a fórmula autonomia_atual",
                "relogio.autonomia.autonomia_estimada" => "quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em que está no rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em motivos",
                "relogio.autonomia.autonomia_prevista" => "quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do smartwatch, a reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula autonomia_prevista; null se falta o dado no cadastro",
                "relogio.autonomia.calculado_em_unixtimestamp" => "o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); segundos que faltam agora = acaba_em_unixtimestamp − hora atual",
                "relogio.autonomia.em_uso" => "verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora",
                "relogio.autonomia.energia" => "a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para calcular",
                "relogio.autonomia.motivos" => "por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)",
                "relogio.autonomia.motivos.<campo>" => "o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a zero em até 10 anos",
                "relogio.autonomia.restante_em_uso" => "quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em segundos); null com o motivo (ex.: o automático no pulso se recarrega e não acaba)",
                "relogio.autonomia.restante_guardado" => "quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em segundos); null com o motivo",
                "relogio.calculos[]" => "o resultado agora de cada fórmula que vale para o relógio",
                "relogio.calculos[].identificador" => "a fórmula",
                "relogio.calculos[].nome" => "o nome da fórmula",
                "relogio.calculos[].unidade" => "a unidade do resultado (s: segundos)",
                "relogio.calculos[].valor" => "o resultado agora (número ou texto; null: vazio)",
                "relogio.calculos[].versao" => "o grupo da versão da fórmula usada",
                "relogio.caminho" => "o grupo por extenso",
                "relogio.carga" => "a energia agora, em %; null sem dados",
                "relogio.carga_de" => "de onde vem a energia",
                "relogio.compra.data" => "a data da compra",
                "relogio.compra.garantia_ate" => "até quando vai a garantia",
                "relogio.compra.loja" => "onde comprou",
                "relogio.compra.valor" => "o valor pago",
                "relogio.dados[]" => "os campos do cadastro que valem para o relógio (os do grupo dele e dos de cima), com o valor que as contas usam",
                "relogio.dados[].identificador" => "o campo",
                "relogio.dados[].nome" => "o nome do campo",
                "relogio.dados[].origem" => "de onde vem o valor usado: informado, padrão (o do campo) ou vazio",
                "relogio.dados[].tipo" => "inteiro, decimal, sim_nao, data, lista ou texto",
                "relogio.dados[].unidade" => "a unidade (texto)",
                "relogio.dados[].valor" => "o valor informado no relógio (texto; null: não preenchido)",
                "relogio.dados[].valor_usado" => "o valor que as contas usam: o informado, senão o padrão do campo (null: nenhum)",
                "relogio.desde" => "desde quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogio.disponivel" => "verdadeiro ou falso: entra no rodízio",
                "relogio.copia_banco" => "verdadeiro: o relógio pede a cópia no banco de todos os documentos dele (o segundo dos três níveis da cópia; o DOCUMENTOS_COPIA_BANCO do config.php, se definido, vale por cima)",
                "relogio.em_uso" => "verdadeiro ou falso: no pulso agora",
                "relogio.escala_fim" => "até que dia vai a escala (null fora da escala)",
                "relogio.foto" => "a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto",
                "relogio.id" => "o número (código)",
                "relogio.leituras[]" => "as 40 últimas leituras com valor (o gráfico)",
                "relogio.leituras[].em_uso" => "verdadeiro ou falso: a leitura foi feita com o relógio no pulso",
                "relogio.leituras[].inicio" => "quando",
                "relogio.lancamentos_recentes[]" => "os lançamentos dos últimos 14 dias e a sessão ainda aberta, do mais recente ao mais antigo (até 40): o que o quadro \"Corrigir marcações\" do painel mostra",
                "relogio.lancamentos_recentes[].fim" => "quando a sessão terminou (texto AAAA-MM-DD HH:MM:SS); null: ainda aberta, ou um lançamento instantâneo",
                "relogio.lancamentos_recentes[].formato" => "instantaneo, valor ou sessao",
                "relogio.lancamentos_recentes[].id" => "o número do lançamento (para recurso=lancamento, acao=alterar ou excluir)",
                "relogio.lancamentos_recentes[].inicio" => "quando foi (a sessão: quando começou), texto AAAA-MM-DD HH:MM:SS",
                "relogio.lancamentos_recentes[].nome" => "o nome do tipo de lançamento",
                "relogio.lancamentos_recentes[].origem" => "manual, rodizio (a sessão do dia) ou importado",
                "relogio.lancamentos_recentes[].tipo" => "o identificador do tipo de lançamento (pulso, carga, corda...)",
                "relogio.lancamentos_recentes[].unidade" => "a unidade do valor (texto; vazio sem valor)",
                "relogio.lancamentos_recentes[].valor" => "o valor (número), num lançamento com valor; null nos outros",
                "relogio.leituras[].unidade" => "a unidade",
                "relogio.leituras[].valor" => "o valor",
                "relogio.linha_do_tempo[]" => "os trechos da linha do tempo do relógio, os 5 mais recentes: cada trecho é um período contínuo num estado, ou uma marcação (um lançamento instantâneo)",
                "relogio.linha_do_tempo[].duracao" => "a mesma duração por extenso (\"2d 3h\", \"40min\"); null numa marcação",
                "relogio.linha_do_tempo[].duracao_seg" => "quanto o trecho durou, número inteiro, em segundos; null numa marcação",
                "relogio.linha_do_tempo[].em_andamento" => "verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)",
                "relogio.linha_do_tempo[].estado" => "o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)",
                "relogio.linha_do_tempo[].fim" => "quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação",
                "relogio.linha_do_tempo[].inicio" => "quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogio.linha_do_tempo[].texto" => "o trecho por extenso, como a tela mostra (\"em uso\", \"no sol\", \"Leitura de carga 80%\")",
                "relogio.linha_do_tempo[].tipo" => "o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no repouso",
                "relogio.manutencoes[]" => "as 5 próximas manutenções (avisos)",
                "relogio.manutencoes[].data" => "a data (texto AAAA-MM-DD)",
                "relogio.manutencoes[].nome" => "o aviso",
                "relogio.medicao_janela_dias" => "a janela da média do gasto medido, em dias (a da Configuração)",
                "relogio.medicoes[]" => "as medições do gasto pelas leituras (cada leitura de um tipo que mede o gasto, comparada com a anterior), da mais recente para a mais antiga",
                "relogio.medicoes[].ate_valor" => "a leitura que fechou a medição (número)",
                "relogio.medicoes[].criado" => "quando a medição foi gravada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogio.medicoes[].de_valor" => "a leitura de antes (número, na unidade da leitura)",
                "relogio.medicoes[].fim" => "quando foi a leitura que fechou a medição (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogio.medicoes[].horas_guardado" => "as horas guardado entre as duas leituras (número)",
                "relogio.medicoes[].horas_pulso" => "as horas no pulso entre as duas leituras (número)",
                "relogio.medicoes[].id" => "o número da medição",
                "relogio.medicoes[].inicio" => "quando foi a leitura de antes (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogio.medicoes[].lancamento_id" => "o lançamento (a leitura) que fechou a medição",
                "relogio.medicoes[].medida" => "o gasto do intervalo sozinho, no histórico: uso (o gasto por dia de uso, quando o intervalo teve meio dia de uso ou mais, ou mais dias de uso que fora) ou repouso (o gasto por dia fora do pulso); o gasto que vale sai de todas as medições juntas",
                "relogio.medicoes[].na_media" => "verdadeiro ou falso: entra na média agora (usada e dentro da janela da Configuração, medicao_janela_dias)",
                "relogio.medicoes[].peso_horas" => "o peso da medição na média: as horas que ela cobriu, no pulso (uso) ou guardado (repouso) (número)",
                "relogio.medicoes[].taxa" => "o gasto medido, em % por dia de uso (uso) ou por dia guardado (repouso) (número)",
                "relogio.medicoes[].usada" => "verdadeiro ou falso: a caixa \"Atualizar o gasto com esta medição\" estava marcada (entra na média); falso: só histórico",
                "relogio.no_id" => "o grupo (0: na raiz)",
                "relogio.nome" => "o nome",
                "relogio.nota" => "a nota nos critérios; null: indisponível",
                "relogio.nota.conjunto_texto" => "de onde vêm os critérios",
                "relogio.nota.nota" => "a nota, de 0 a 100",
                "relogio.observacao" => "a observação do cadastro (null)",
                "relogio.previsao" => "a previsão da energia (só nos relógios com leitura, como o smartwatch): as frases da tela e os mesmos números",
                "relogio.previsao.aplica" => "verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)",
                "relogio.previsao.carga_na_entrada" => "com quanto ele entra nesse dia, em % (número)",
                "relogio.previsao.carregar_antes" => "verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)",
                "relogio.previsao.chega_limite_em" => "quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa de um ano",
                "relogio.previsao.confianca" => "a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media ou baixa (leitura com mais de 7 dias)",
                "relogio.previsao.conta[]" => "de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras",
                "relogio.previsao.conta[].campo" => "o identificador do campo, ou MEDIDO(\"uso\") / MEDIDO(\"repouso\") para o gasto medido",
                "relogio.previsao.conta[].nome" => "o nome do campo (ou do gasto medido, com quantas medições entraram)",
                "relogio.previsao.conta[].origem" => "de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido (pelas leituras)",
                "relogio.previsao.conta[].unidade" => "a unidade do valor (texto)",
                "relogio.previsao.conta[].valor" => "o valor usado na conta (número; null se vazio)",
                "relogio.previsao.dura_ate" => "até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)",
                "relogio.previsao.dura_dias" => "quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)",
                "relogio.previsao.energia" => "a energia agora, em % (número; null sem leitura)",
                "relogio.previsao.gasto" => "os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as tem)",
                "relogio.previsao.gasto.conjunta" => "true: os dois gastos saem juntos da conta das medições; false: a média das medições de cada um",
                "relogio.previsao.gasto.janela_dias" => "a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)",
                "relogio.previsao.gasto.medicoes" => "quantas medições há na janela (número)",
                "relogio.previsao.gasto.medicoes_antes" => "quantas medições há na janela anterior, a dos antes (número)",
                "relogio.previsao.gasto.uso.antes" => "o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)",
                "relogio.previsao.gasto.uso.cadastro" => "o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)",
                "relogio.previsao.gasto.uso.medido" => "o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null sem medição)",
                "relogio.previsao.gasto.uso.vale" => "o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)",
                "relogio.previsao.gasto.repouso.antes" => "o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)",
                "relogio.previsao.gasto.repouso.cadastro" => "o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)",
                "relogio.previsao.gasto.repouso.medido" => "o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null sem medição)",
                "relogio.previsao.gasto.repouso.vale" => "o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)",
                "relogio.previsao.leitura" => "a última leitura, no valor informado (número)",
                "relogio.previsao.leitura_em" => "quando foi a última leitura (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogio.previsao.limite" => "o limite de carga da Configuração (previsao_limite), em % (número)",
                "relogio.previsao.linhas[]" => "as frases da previsão, como a tela mostra (texto)",
                "relogio.previsao.precisa" => "quanto ele precisa ter na entrada para os dias seguidos no pulso, em % (número)",
                "relogio.previsao.proxima_entrada" => "o próximo dia em que o relógio entra no rodízio (texto AAAA-MM-DD); null se não está no plano",
                "relogio.proxima" => "o próximo dia no plano (texto AAAA-MM-DD); null: não está no plano",
                "relogio.proxima_ate" => "até quando ele fica nesse dia",
                "relogio.registros" => "quantos registros tem a linha do tempo inteira",
                "relogio.situacao[]" => "as frases de situação",
                "relogio.tipo" => "o nome do grupo",
                "relogio.tipos[]" => "os tipos de lançamento do relógio (os botões de Lançar)",
                "relogio.tipos[].aberta" => "a sessão aberta desse tipo agora; null: nenhuma",
                "relogio.tipos[].aberta.inicio" => "desde quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "relogio.tipos[].aberta.rodizio" => "verdadeiro ou falso: é a sessão do rodízio",
                "relogio.tipos[].aberta.texto" => "por extenso (\"desde 07:00 (3 h 12 min)\")",
                "relogio.tipos[].formato" => "instantaneo, valor ou sessao",
                "relogio.tipos[].identificador" => "o tipo",
                "relogio.tipos[].mede_gasto" => "verdadeiro ou falso: a leitura mede o gasto (a caixa aparece)",
                "relogio.tipos[].nome" => "o nome",
                "relogio.tipos[].unidade" => "a unidade do valor",
                "relogio.documentos" => "os documentos do relógio, resumidos (a lista: recurso=documentos&relogio=id)",
                "relogio.documentos.categorias[]" => "as categorias em que ele tem documentos, na ordem",
                "relogio.documentos.categorias[].documentos" => "quantos documentos ele tem nela",
                "relogio.documentos.categorias[].id" => "o número da categoria (para documentos.php?relogio=...&cat=...)",
                "relogio.documentos.categorias[].nome" => "o nome da categoria",
                "relogio.documentos.pasta_ok" => "verdadeiro: a pasta dos documentos (DOCUMENTOS_PASTA do config.php) está pronta para receber arquivos",
                "relogio.documentos.copia_sistema" => "a cópia no banco pelo config.php (DOCUMENTOS_COPIA_BANCO), que vale sobre o relógio e o arquivo: verdadeiro, todo arquivo vai; falso, nenhum vai; null (sem a constante), cada relógio e cada arquivo decidem",
                "relogio.documentos.total" => "quantos documentos ele tem",
            ],
            "config" => [
                "ancoras.<ancora>" => "cada âncora que as mensagens aceitam ({relogio}, {acao}...) e o que ela vira",
                "canais.<canal>.ajuda" => "a explicação do canal",
                "canais.<canal>.nome" => "o nome do canal (Telegram, Google Agenda)",
                "canais.<canal>.tipos" => "o nome do campo do formulário (acao=salvar) com os tipos que vão pelo canal (alerta_tipos[], agenda_tipos[])",
                "chave_agenda" => "a chave do Google lida no caminho configurado; null: não lida",
                "chave_agenda.client_email" => "o e-mail da conta de serviço (compartilhar a agenda com ele)",
                "cron_estado" => "a situação do cron, como a Configuração mostra (o cron roda a cada minuto)",
                "cron_estado.crontab" => "a linha do crontab que roda o sistema (com a pasta dele)",
                "cron_estado.erro" => "o erro guardado da última execução com erro (null: sem erro; some na primeira execução sem erro)",
                "cron_estado.minutos" => "há quantos minutos ele rodou pela última vez (null: nunca rodou)",
                "cron_estado.situacao" => "nunca (ainda não rodou), parado (mais de 10 minutos sem rodar) ou rodando",
                "cron_estado.texto" => "a frase da Configuração (\"Cron rodando: última execução às 10:41.\"; parado e nunca trazem a linha do crontab)",
                "cron_estado.ultima" => "quando rodou pela última vez (AAAA-MM-DD HH:MM:SS, no fuso do sistema; null: nunca rodou)",
                "config.ag_padrao" => "a mensagem padrão da agenda: a primeira linha é o título do evento, o resto a descrição",
                "config.agenda_antecedencia" => "com quantos dias de antecedência os eventos são criados na agenda",
                "config.agenda_ativa" => "1: cria os eventos no Google Agenda; 0: não",
                "config.agenda_chave" => "o caminho da chave JSON da conta de serviço do Google, no servidor",
                "config.agenda_id" => "o id da agenda do Google",
                "config.agenda_teste_id" => "o id do evento de teste na agenda (vazio: nenhum)",
                "config.carga_limiar" => "o limite de carga geral (%): carregar quando a carga chega a ele (o campo carga_minima do relógio, se preenchido, vale no lugar)",
                "config.cron_erro" => "o erro guardado da última execução com erro (texto; vazio: sem erro)",
                "config.cron_registro" => "o registro da última rodada com atividade (texto)",
                "config.cron_ultima_execucao" => "quando o cron rodou pela última vez (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "config.escala_fim" => "até que dia vai a escala inteligente gerada (texto AAAA-MM-DD; vazio fora da escala)",
                "config.escala_gerada" => "o dia em que a escala foi gerada pela última vez (texto AAAA-MM-DD)",
                "config.horario_manha" => "a hora da rodada da manhã do cron: o relógio do dia e os avisos (HH:MM)",
                "config.horario_noite" => "a hora da rodada da noite: preparar o relógio de amanhã (HH:MM)",
                "config.max_sem_uso" => "a garantia de rodízio: nenhum relógio passa desses dias sem uso (0 desliga)",
                "config.medicao_janela_dias" => "a média do gasto medido pelas leituras usa as medições destes últimos dias (sem nenhuma na janela, a última)",
                "config.mensagens_ativas" => "1: o Telegram (a API de alerta) envia; 0: não envia",
                "config.painel_modo" => "como o relógio abre na página Hoje: lado (no painel à direita da lista) ou flutuante (numa janela grande por cima da página); vazio: lado",
                "config.migracao_v10" => "marca de que a migração v10 foi aplicada (1)",
                "config.migracao_v12" => "marca de que a migração v12 foi aplicada (1)",
                "config.migracao_v16" => "marca de que a migração v16 foi aplicada (1)",
                "config.migracao_v17" => "marca de que a migração v17 foi aplicada (1)",
                "config.pulso_auto_fim" => "1 (ou vazio): o relógio sai do pulso sozinho no fim do horário de uso (o \"fecha às\" do tipo No pulso); 0: só pelo Tirou",
                "config.pulso_auto_inicio" => "1 (ou vazio): o relógio do dia entra no pulso sozinho no início do horário de uso; 0: só pelo Pôs",
                "config.previsao_limite" => "o limite de carga da previsão do smartwatch, em %",
                "config.sol_limiar" => "no solar, a carga (%) em que ele deve ir para o sol",
                "config.tg_padrao" => "a mensagem padrão do Telegram, com âncoras ({acao}, {relogio}, {motivo}...)",
                "config.ultima_manha" => "o dia da última rodada da manhã do cron (texto AAAA-MM-DD)",
                "config.ultima_noite" => "o dia da última rodada da noite do cron (texto AAAA-MM-DD)",
                "config.url_sistema" => "o endereço do sistema, para a âncora {link}",
                "config.uso_fim" => "a hora em que ele sai do pulso (HH:MM)",
                "config.uso_inicio" => "a hora em que o relógio do dia vai para o pulso (HH:MM)",
                "eventos[]" => "os eventos personalizados (avisos seus, com horário e repetição próprios)",
                "eventos[].agenda" => "verdadeiro ou falso: o evento vai para o Google Agenda",
                "eventos[].ativo" => "verdadeiro ou falso: o evento dispara",
                "eventos[].criado" => "quando o evento foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].data_inicio" => "a data (uma vez só) ou o começo da contagem (a cada N dias) (texto AAAA-MM-DD); null nas outras",
                "eventos[].descricao" => "a repetição por extenso (\"seg, qua 20:00\")",
                "eventos[].dia_mes" => "o dia do mês (mensal), de 1 a 31; null nas outras",
                "eventos[].dias_semana" => "os dias da semana (semanal), de 1 (segunda) a 7 (domingo), separados por vírgula; null nas outras",
                "eventos[].disparos[]" => "as vezes em que já disparou",
                "eventos[].disparos[].disparado" => "quando o cron mandou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].disparos[].ocorrencia" => "a vez que disparou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema, a do cadastro)",
                "eventos[].hora" => "a hora do disparo (HH:MM)",
                "eventos[].id" => "o número do evento (o tipo dele nos canais é ev<id>)",
                "eventos[].intervalo_dias" => "de quantos em quantos dias (intervalo); null nas outras",
                "eventos[].nome" => "o nome do evento (texto)",
                "eventos[].proximas[]" => "as 10 próximas vezes em que dispara (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].proximas_60[]" => "as 5 próximas vezes nos próximos 60 dias",
                "eventos[].relogio" => "o nome desse relógio; null: evento geral",
                "eventos[].relogio_id" => "o relógio do evento (as âncoras dele funcionam na mensagem); null: evento geral",
                "eventos[].repeticao" => "quando dispara: uma (uma vez só), diaria, semanal, mensal ou intervalo (a cada N dias)",
                "eventos[].telegram" => "verdadeiro ou falso: o evento vai pelo Telegram (marcado em \"O que vai para onde\")",
                "pasta" => "a pasta do sistema no servidor (para a linha do crontab)",
                "previa.agenda[]" => "os 8 primeiros eventos da agenda",
                "previa.agenda[].data" => "o dia",
                "previa.agenda[].descricao" => "a descrição",
                "previa.agenda[].hora" => "a hora",
                "previa.agenda[].titulo" => "o título",
                "previa.manha" => "a mensagem da manhã do Telegram como sairia agora (texto)",
                "previa.noite" => "a mensagem da noite como sairia agora (texto; vazio: nada a enviar)",
                "previa.sincronizados" => "quantos eventos o sistema tem na agenda",
                "relogios[]" => "os relógios (a lista do evento)",
                "relogios[].disponivel" => "verdadeiro ou falso",
                "relogios[].id" => "o número",
                "relogios[].nome" => "o nome",
                "repeticoes.<repeticao>" => "cada repetição de evento e o nome dela",
                "sol_fim" => "a hora em que a sessão no sol esquecida aberta fecha (HH:MM)",
                "tipos[]" => "os tipos de aviso (\"O que vai para onde\"): o dia, a véspera, os avisos cadastrados e os eventos",
                "tipos[].canais.<canal>.corpo" => "a mensagem personalizada (texto)",
                "tipos[].canais.<canal>.marcado" => "verdadeiro ou falso: o tipo vai por esse canal",
                "tipos[].canais.<canal>.proprio" => "verdadeiro ou falso: tem mensagem personalizada nesse canal",
                "tipos[].evento" => "o id do evento personalizado; null nos outros",
                "tipos[].nome" => "o nome",
                "tipos[].quando" => "manhã ou noite",
                "tipos[].tipo" => "o tipo (dia, vespera, identificador, ev<id>)",
            ],
            "cron" => [
                "execucoes[]" => "as execuções do cron guardadas (sem atividade: 7 dias; com atividade ou erro: 1 ano), da mais recente",
                "execucoes[].duracao_ms" => "quanto durou, em milissegundos (número inteiro)",
                "execucoes[].fim" => "quando terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "execucoes[].id" => "o número da execução",
                "execucoes[].inicio" => "quando começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "execucoes[].registro" => "o que foi feito, linha por linha (texto; vazio: nada a fazer)",
                "execucoes[].teve_atividade" => "verdadeiro ou falso: fez alguma coisa (rodada, plano, evento, sincronização)",
                "execucoes[].teve_erro" => "verdadeiro ou falso: houve erro",
                "filtro.ate" => "o fim do período",
                "filtro.busca" => "o texto pedido",
                "filtro.de" => "o começo do período pedido (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "filtro.situacao" => "a situação pedida (atividade, erro, nada, todas)",
                "pagina" => "a página",
                "paginas" => "quantas páginas",
                "por_pagina" => "quantas por página",
                "resumo.atividade" => "quantas com atividade",
                "resumo.erros" => "quantas com erro",
                "resumo.mais_lenta_ms" => "a mais lenta, em milissegundos",
                "resumo.total" => "quantas execuções no período",
                "total" => "quantas passaram nos filtros",
                "ultima" => "quando o cron rodou pela última vez",
            ],
            "arvore" => [
                "grupos[]" => "os grupos em ordem de árvore",
                "grupos[].cadeia[]" => "os ids do grupo e dos de cima",
                "grupos[].caminho" => "o caminho",
                "grupos[].criterios" => "quantos parâmetros de critérios próprios ele tem",
                "grupos[].id" => "o número",
                "grupos[].nome" => "o nome",
                "grupos[].pai_id" => "o grupo de cima; null: raiz",
                "grupos[].profundidade" => "o nível",
                "grupos[].relogios[]" => "os nomes dos relógios direto nele",
                "grupos[].subgrupos" => "quantos grupos direto dentro dele",
                "relogios[]" => "o grupo de cada relógio",
                "relogios[].disponivel" => "verdadeiro ou falso",
                "relogios[].id" => "o número",
                "relogios[].no_id" => "o grupo (0: raiz)",
                "relogios[].nome" => "o nome",
            ],
            "cadastros" => [
                "avisos[]" => "os avisos cadastrados (cada identificador pode ter uma versão por grupo)",
                "avisos[].agenda" => "a data na agenda: janela (só dentro da antecedência da agenda) ou sempre",
                "avisos[].antecedencia_dias" => "com quantos dias antes ele entra \"em breve\" (número)",
                "avisos[].ativo" => "o aviso vale",
                "avisos[].condicao" => "vale quando: uma fórmula que diz para quais relógios do grupo o aviso vale (1 vale, 0 não; ex.: corda_manual = 0, só o automático sem corda); null: vale para todos",
                "avisos[].escala" => "na escala inteligente: nao, uso ou sempre",
                "avisos[].expressao" => "a fórmula da data prevista",
                "avisos[].id" => "o número da versão",
                "avisos[].identificador" => "o aviso (o tipo dele nos canais)",
                "avisos[].no_id" => "o grupo desta versão; null: todos",
                "avisos[].nome" => "o nome do aviso — a âncora {acao}",
                "avisos[].resolve" => "o tipo de lançamento que resolve (o botão na tela Hoje); null: nenhum",
                "avisos[].resolve_tipo_id" => "o número desse tipo de lançamento (a chave estrangeira no banco); null: nenhum",
                "avisos[].simula_horas" => "as horas da sessão que a escala simula para resolver (número; null)",
                "avisos[].simula_valor" => "o valor do lançamento que a escala simula para resolver (número; null)",
                "avisos[].texto" => "o motivo, com {relogio}, {data}, {quando} e {limite} — a âncora {motivo}",
                "campos[]" => "os campos do cadastro dos relógios (cada um vale para o grupo dele e tudo abaixo)",
                "campos[].id" => "o número do campo",
                "campos[].identificador" => "o nome do campo nas fórmulas e nos critérios",
                "campos[].no_id" => "o grupo em que o campo vale; null: todos os relógios",
                "campos[].nome" => "o nome do campo",
                "campos[].opcoes" => "as opções de um campo de lista, uma por linha (texto; vazio nos outros)",
                "campos[].ordem" => "a posição do campo no cadastro",
                "campos[].padrao" => "o valor usado quando o relógio não tem o campo preenchido (texto; null: nenhum)",
                "campos[].tipo" => "inteiro, decimal, sim_nao, data, lista ou texto",
                "campos[].unidade" => "a unidade (texto; vazio: nenhuma)",
                "formatos_de_lancamento.<formato>" => "cada formato de lançamento e o nome",
                "formulas[]" => "as fórmulas (cada identificador pode ter uma versão por grupo; vale a do grupo mais perto do relógio)",
                "formulas[].expressao" => "a conta (texto, na escrita das fórmulas do motor)",
                "formulas[].id" => "o número da versão",
                "formulas[].identificador" => "o nome da fórmula nas outras fórmulas e nos critérios",
                "formulas[].no_id" => "o grupo desta versão; null: todos",
                "formulas[].nome" => "o nome da fórmula",
                "formulas[].unidade" => "a unidade do resultado (texto)",
                "funcoes.<funcao>" => "cada função do motor e como usar",
                "grupos[]" => "os grupos",
                "grupos[].caminho" => "o caminho",
                "grupos[].id" => "o número",
                "lancamento_tipos[]" => "os tipos de lançamento: o que se registra num relógio",
                "lancamento_tipos[].condicao" => "vale quando: uma fórmula que diz para quais relógios do grupo o tipo vale (1 vale, 0 não; ex.: corda_manual, só quem aceita corda); null: vale para todos",
                "lancamento_tipos[].exclusiva" => "a sessão é exclusiva: o relógio fica num lugar só (abrir fecha a outra exclusiva)",
                "lancamento_tipos[].fecha_as" => "sessão esquecida aberta fecha sozinha a essa hora do dia em que começou (HH:MM; null: não fecha)",
                "lancamento_tipos[].formato" => "instantaneo (uma marcação: corda), valor (uma leitura: carga) ou sessao (com início e fim: pulso, sol)",
                "lancamento_tipos[].id" => "o número do tipo",
                "lancamento_tipos[].identificador" => "o nome do tipo nas fórmulas (HORAS(\"pulso\"; 30))",
                "lancamento_tipos[].lancamentos" => "quantos lançamentos desse tipo existem (número)",
                "lancamento_tipos[].mede_gasto" => "cada leitura desse tipo mede o gasto, comparando com a anterior (a função MEDIDO)",
                "lancamento_tipos[].no_id" => "o grupo em que o tipo vale; null: todos",
                "lancamento_tipos[].nome" => "o nome do tipo",
                "lancamento_tipos[].ordem" => "a posição do tipo",
                "lancamento_tipos[].unidade" => "a unidade do valor (texto)",
                "max_sem_uso" => "a garantia de rodízio em dias",
                "modo_ativo" => "o id do modo em uso",
                "modos[]" => "os modos (como no banco)",
                "modos[].blocos[]" => "os blocos",
                "modos[].ativo" => "1: o modo em uso (só um); 0: não",
                "modos[].blocos[].dias" => "os dias (1 a 7) separados por vírgula (no banco, uma linha por dia na tabela modo_bloco_dia)",
                "modos[].blocos[].id" => "o número",
                "modos[].blocos[].modo_id" => "o modo",
                "modos[].blocos[].no_id" => "o grupo de onde sortear; null: todos",
                "modos[].blocos[].nome" => "o nome",
                "modos[].blocos[].ordem" => "a posição",
                "modos[].blocos[].relogio_id" => "o relógio fixo; null",
                "modos[].blocos[].um_por" => "dia ou bloco",
                "modos[].ciclo" => "1: usa o ciclo (um relógio só volta depois que todos do bloco passaram); 0: não",
                "modos[].escala_dias" => "o horizonte da escala; null",
                "modos[].id" => "o número",
                "modos[].nome" => "o nome",
                "modos[].ordem" => "a posição",
                "modos[].selecao" => "a forma de escolha",
                "relogios[]" => "os relógios",
                "relogios[].id" => "o número",
                "relogios[].nome" => "o nome",
                "tipos_de_campo.<tipo_de_campo>" => "cada tipo de campo e o nome",
                "documento_categorias[]" => "as categorias dos documentos, na ordem",
                "documento_categorias[].aceita[]" => "os tipos de arquivo que ela aceita (imagem, video, audio, pdf, xml); lista vazia: qualquer arquivo",
                "documento_categorias[].documentos" => "quantos documentos ela tem",
                "documento_categorias[].id" => "o número da categoria",
                "documento_categorias[].identificador" => "o identificador (minúsculas, números e _)",
                "documento_categorias[].nome" => "o nome",
                "documento_categorias[].ordem" => "a ordem em que ela aparece (número)",
            ],
            "calcular" => [
                "expressao" => "a fórmula calculada",
                "resultados[]" => "o resultado em cada relógio",
                "resultados[].partes.<parte>" => "cada parte da conta (campo, fórmula ou função) e o valor que teve",
                "resultados[].relogio" => "o nome",
                "resultados[].relogio_id" => "o relógio",
                "resultados[].valor" => "o resultado (número ou texto; null: vazio)",
            ],
            "avisos" => [
                "avisos[]" => "os avisos de todos os relógios agora, do mais urgente ao mais distante",
                "avisos[].agenda" => "a data na agenda: janela (só dentro da antecedência da agenda) ou sempre (qualquer data); se vai pela agenda é a Configuração",
                "avisos[].data" => "a data prevista, pela fórmula do aviso (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "avisos[].disponivel" => "verdadeiro ou falso: o relógio entra no rodízio",
                "avisos[].escala" => "na escala inteligente: nao, uso (conferido no relógio do dia) ou sempre (também nos guardados)",
                "avisos[].estado" => "atrasado (a data passou), em_breve (dentro da antecedência) ou ok",
                "avisos[].falta_dias" => "quanto falta para a data prevista, em dias (número; negativo: já passou)",
                "avisos[].identificador" => "o aviso (corda, carregar, sol, pilha, revisao, garantia, carga_baixa, leitura, ou um que você cadastrou)",
                "avisos[].modelo" => "o texto do cadastro, sem trocar as âncoras (só o {limite})",
                "avisos[].nome" => "o nome do aviso (\"Dar corda\") — a âncora {acao}",
                "avisos[].relogio" => "o nome",
                "avisos[].relogio_id" => "o relógio",
                "avisos[].resolve" => "o tipo de lançamento que resolve o aviso (o botão na tela Hoje); null: nenhum",
                "avisos[].simula_horas" => "as horas da sessão que a escala simula para resolver o aviso (número; null: nenhuma)",
                "avisos[].simula_valor" => "o valor do lançamento que a escala simula para resolver o aviso (leitura) (número; null: nenhum)",
                "avisos[].texto" => "o motivo por extenso, com {relogio}, {data}, {quando} e {limite} trocados — a âncora {motivo}",
                "avisos[].versao" => "o lugar da árvore da versão do aviso usada (texto)",
            ],
            "criterios" => [
                "conjuntos[]" => "os conjuntos de critérios, um por lugar que tem critérios próprios",
                "conjuntos[].escopo" => "o lugar: \"\" (todos os relógios), g:<grupo> ou r:<relógio>",
                "conjuntos[].lugar" => "o lugar por extenso",
                "conjuntos[].parametros[]" => "os parâmetros do conjunto, em ordem",
                "conjuntos[].parametros[].escopo_no_id" => "o grupo do conjunto; null se não é de grupo",
                "conjuntos[].parametros[].escopo_relogio_id" => "o relógio do conjunto; null se não é de relógio",
                "conjuntos[].parametros[].id" => "o número do parâmetro",
                "conjuntos[].parametros[].nome" => "o nome do parâmetro",
                "conjuntos[].parametros[].ordem" => "a posição dele no conjunto",
                "conjuntos[].parametros[].peso" => "o peso no conjunto, em % (os do conjunto somam 100)",
                "conjuntos[].parametros[].subparametros[]" => "os subparâmetros, em ordem",
                "conjuntos[].parametros[].subparametros[].faixas[]" => "as faixas: o valor medido vira uma nota de 0 a 100",
                "conjuntos[].parametros[].subparametros[].faixas[].ate" => "o fim da faixa (não entra, menos na última); null: sem limite",
                "conjuntos[].parametros[].subparametros[].faixas[].categoria" => "a categoria (medida de lista); null numa faixa de números",
                "conjuntos[].parametros[].subparametros[].faixas[].de" => "o começo da faixa (entra); null numa faixa de categoria",
                "conjuntos[].parametros[].subparametros[].faixas[].id" => "o número da faixa",
                "conjuntos[].parametros[].subparametros[].faixas[].nota" => "a nota da faixa, de 0 a 100",
                "conjuntos[].parametros[].subparametros[].id" => "o número do subparâmetro",
                "conjuntos[].parametros[].subparametros[].nome" => "o nome do subparâmetro",
                "conjuntos[].parametros[].subparametros[].ordem" => "a posição dele no parâmetro",
                "conjuntos[].parametros[].subparametros[].peso" => "o peso no parâmetro, em % (os do parâmetro somam 100)",
                "conjuntos[].parametros[].subparametros[].peso_efetivo_no_conjunto" => "peso do subparâmetro × peso do parâmetro ÷ 100, em %",
                "conjuntos[].parametros[].subparametros[].variavel" => "o que ele mede: o identificador de um campo ou de uma fórmula",
                "conjuntos[].usado_por[]" => "os relógios disponíveis que usam este conjunto (ids)",
                "lugares[]" => "os lugares que podem ter critérios: todos os relógios, cada grupo (em ordem de árvore) e cada relógio",
                "lugares[].escopo" => "o lugar: \"\" (todos), g:<grupo> ou r:<relógio>",
                "lugares[].herda" => "o lugar de quem ele herda os critérios quando não tem os seus; null: ninguém (nota neutra)",
                "lugares[].herda_texto" => "esse lugar por extenso",
                "lugares[].lugar" => "o lugar por extenso, curto",
                "lugares[].proprio" => "verdadeiro ou falso: o lugar tem critérios próprios",
                "lugares[].texto" => "o lugar como a lista da página mostra (\"Grupo: Tradicional › Mecânico\")",
                "max_sem_uso" => "a garantia de rodízio em dias",
                "modos[]" => "os modos de rodízio e a forma de escolher de cada um",
                "modos[].ativo" => "verdadeiro ou falso: é o modo em uso",
                "modos[].escala" => "verdadeiro ou falso: o modo é escala inteligente (escolhe sempre pela maior nota)",
                "modos[].nome" => "o nome do modo",
                "modos[].selecao" => "a forma de escolha: inteligente (a maior nota), ponderado (sorteio pela nota), aleatorio ou fifo (o mais tempo sem uso)",
                "notas[]" => "a nota de cada relógio agora, com a conta",
                "notas[].conjunto" => "o lugar dos critérios usados: \"\" (todos), g:<grupo> ou r:<relógio>; null: nenhum (nota neutra, 50)",
                "notas[].conjunto_texto" => "esse lugar por extenso",
                "notas[].conta[]" => "cada subparâmetro que entrou na conta",
                "notas[].conta[].faixa" => "a faixa em que o valor caiu (\"30 a 50\", ou a categoria)",
                "notas[].conta[].nota" => "a nota da faixa, de 0 a 100 (número)",
                "notas[].conta[].parametro" => "o nome do parâmetro",
                "notas[].conta[].peso_efetivo" => "o peso no conjunto: peso do subparâmetro × peso do parâmetro, reescalado sem os que não se aplicam, em % (número)",
                "notas[].conta[].pontos" => "nota × peso efetivo ÷ 100 (número): quanto somou na nota",
                "notas[].conta[].subparametro" => "o nome do subparâmetro",
                "notas[].conta[].valor" => "o valor medido (número, ou o texto de uma lista)",
                "notas[].conta[].variavel" => "o que o subparâmetro mede (o identificador do campo ou da fórmula)",
                "notas[].disponivel" => "verdadeiro ou falso: o relógio está disponível para o rodízio",
                "notas[].grupo" => "o grupo do relógio (o caminho na árvore)",
                "notas[].nota" => "a nota final, de 0 a 100 (número): a soma dos pontos",
                "notas[].relogio" => "o nome do relógio",
                "notas[].relogio_id" => "o relógio",
                "variaveis.<variavel>" => "o que um subparâmetro pode medir: cada campo (menos texto) e cada fórmula, pelo identificador",
                "variaveis.<variavel>.max" => "o limite da medida (a última faixa vai até ele ou sem limite): o que o nome diz em \"(0 a N)\"; null: sem limite",
                "variaveis.<variavel>.nome" => "o nome (com a unidade)",
                "variaveis.<variavel>.tipo" => "numero ou categoria (campo de lista)",
                "variaveis.<variavel>.valores[]" => "as categorias possíveis (campo de lista); vazio nos números",
            ],
            "historico" => [
                "estados.<estado>" => "cada estado possível da linha do tempo e o nome dele",
                "filtro.ate" => "o fim pedido (null)",
                "filtro.de" => "o começo pedido (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema; null)",
                "filtro.estados[]" => "os estados pedidos",
                "filtro.ignorados[]" => "o que foi pedido e não existe",
                "filtro.ordem" => "desc ou asc",
                "filtro.relogios[]" => "os relógios pedidos",
                "lancamentos[]" => "os lançamentos crus do período",
                "lancamentos[].fim" => "o fim (null)",
                "lancamentos[].id" => "o número",
                "lancamentos[].inicio" => "quando",
                "lancamentos[].origem" => "manual, rodizio ou importado",
                "lancamentos[].relogio" => "o nome",
                "lancamentos[].relogio_id" => "o relógio",
                "lancamentos[].tipo" => "o tipo",
                "lancamentos[].valor" => "o valor (null)",
                "linhas[]" => "os trechos da linha do tempo do relógio, do mais recente para o mais antigo: cada trecho é um período contínuo num estado, ou uma marcação (um lançamento instantâneo)",
                "linhas[].duracao" => "a mesma duração por extenso (\"2d 3h\", \"40min\"); null numa marcação",
                "linhas[].duracao_seg" => "quanto o trecho durou, número inteiro, em segundos; null numa marcação",
                "linhas[].em_andamento" => "verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)",
                "linhas[].estado" => "o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)",
                "linhas[].fim" => "quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação",
                "linhas[].inicio" => "quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "linhas[].relogio" => "o nome",
                "linhas[].relogio_id" => "o relógio",
                "linhas[].texto" => "o trecho por extenso, como a tela mostra (\"em uso\", \"no sol\", \"Leitura de carga 80%\")",
                "linhas[].tipo" => "o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no repouso",
                "pagina" => "a página",
                "paginas" => "quantas páginas",
                "por_pagina" => "quantas por página",
                "resumo.<relogio_id>.desde" => "desde quando",
                "resumo.<relogio_id>.foto" => "a versão da foto; null",
                "resumo.<relogio_id>.marcacoes.<tipo_de_marcacao>" => "quantas marcações de cada tipo no período",
                "resumo.<relogio_id>.registros" => "quantos registros ele tem no total",
                "resumo.<relogio_id>.relogio" => "o nome do relógio",
                "resumo.<relogio_id>.tempo.<estado>.porcentagem" => "a parte do tempo, em %",
                "resumo.<relogio_id>.tempo.<estado>.segundos" => "o tempo no estado, no período pedido, número inteiro, em segundos",
                "resumo.<relogio_id>.tempo.<estado>.texto" => "por extenso",
                "resumo.<relogio_id>.tipo" => "o grupo",
                "total" => "quantas linhas passaram nos filtros",
            ],
            "previsao" => [
                "previsoes[]" => "a previsão de cada relógio com leitura",
                "previsoes[].aplica" => "verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)",
                "previsoes[].carga_na_entrada" => "com quanto ele entra nesse dia, em % (número)",
                "previsoes[].carregar_antes" => "verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)",
                "previsoes[].chega_limite_em" => "quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa de um ano",
                "previsoes[].confianca" => "a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media ou baixa (leitura com mais de 7 dias)",
                "previsoes[].conta[]" => "de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras",
                "previsoes[].conta[].campo" => "o identificador do campo, ou MEDIDO(\"uso\") / MEDIDO(\"repouso\") para o gasto medido",
                "previsoes[].conta[].nome" => "o nome do campo (ou do gasto medido, com quantas medições entraram)",
                "previsoes[].conta[].origem" => "de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido (pelas leituras)",
                "previsoes[].conta[].unidade" => "a unidade do valor (texto)",
                "previsoes[].conta[].valor" => "o valor usado na conta (número; null se vazio)",
                "previsoes[].dura_ate" => "até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)",
                "previsoes[].dura_dias" => "quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)",
                "previsoes[].energia" => "a energia agora, em % (número; null sem leitura)",
                "previsoes[].gasto" => "os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as tem)",
                "previsoes[].gasto.conjunta" => "true: os dois gastos saem juntos da conta das medições; false: a média das medições de cada um",
                "previsoes[].gasto.janela_dias" => "a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)",
                "previsoes[].gasto.medicoes" => "quantas medições há na janela (número)",
                "previsoes[].gasto.medicoes_antes" => "quantas medições há na janela anterior, a dos antes (número)",
                "previsoes[].gasto.uso.antes" => "o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)",
                "previsoes[].gasto.uso.cadastro" => "o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)",
                "previsoes[].gasto.uso.medido" => "o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null sem medição)",
                "previsoes[].gasto.uso.vale" => "o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)",
                "previsoes[].gasto.repouso.antes" => "o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)",
                "previsoes[].gasto.repouso.cadastro" => "o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)",
                "previsoes[].gasto.repouso.medido" => "o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null sem medição)",
                "previsoes[].gasto.repouso.vale" => "o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)",
                "previsoes[].leitura" => "a última leitura, no valor informado (número)",
                "previsoes[].leitura_em" => "quando foi a última leitura (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "previsoes[].limite" => "o limite de carga da Configuração (previsao_limite), em % (número)",
                "previsoes[].linhas[]" => "as frases da previsão, como a tela mostra (texto)",
                "previsoes[].precisa" => "quanto ele precisa ter na entrada para os dias seguidos no pulso, em % (número)",
                "previsoes[].proxima_entrada" => "o próximo dia em que o relógio entra no rodízio (texto AAAA-MM-DD); null se não está no plano",
                "previsoes[].relogio" => "o nome",
                "previsoes[].relogio_id" => "o relógio",
            ],
            "plano" => [
                "escala_fim" => "até que dia vai a escala (null fora da escala)",
                "modo" => "o nome do modo em uso",
                "plano[]" => "o plano gravado: o relógio de cada dia",
                "plano[].acao" => "o lembrete do dia (o que fazer antes: carregar, dar corda...); null: nada",
                "plano[].bloco" => "o nome desse bloco",
                "plano[].bloco_id" => "o bloco do modo que escolheu o dia; null: escala ou manual",
                "plano[].criado" => "quando o dia foi gravado no plano (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "plano[].data" => "o dia (texto AAAA-MM-DD)",
                "hoje" => "hoje (texto AAAA-MM-DD)",
                "plano[].motivo" => "por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null: o dia foi gravado antes de o sistema guardar o motivo",
                "plano[].origem" => "sorteio (pelo modo) ou manual (escolhido à mão: \"Usando hoje\" ou trocar_dia)",
                "plano[].relogio" => "o nome dele",
                "plano[].relogio_id" => "o relógio do dia",
                "plano_fim" => "o último dia gravado no plano (AAAA-MM-DD; null: plano vazio), qualquer que seja o período pedido",
                "resumo" => "o resumo do período (os dias da resposta de hoje em diante), como a página Plano mostra",
                "resumo.ate" => "o último dia contado (null: nenhum)",
                "resumo.de" => "o primeiro dia contado (null: nenhum)",
                "resumo.dias" => "quantos dias o período tem no plano",
                "resumo.relogios[]" => "cada relógio com dia no período, do que tem mais dias ao que tem menos (no empate, pelo nome)",
                "resumo.relogios[].a_mao" => "quantos desses dias foram escolhidos à mão (o trocar por… ou o estou usando)",
                "resumo.relogios[].dias" => "quantos dias ele tem no período",
                "resumo.relogios[].porcentagem" => "a parte do período, em % (uma casa)",
                "resumo.relogios[].proximo" => "o próximo dia dele no período (AAAA-MM-DD)",
                "resumo.relogios[].relogio" => "o nome do relógio",
                "resumo.relogios[].relogio_id" => "o número do relógio",
                "resumo.sem_dias[]" => "os relógios disponíveis sem nenhum dia no período",
                "resumo.sem_dias[].id" => "o número do relógio",
                "resumo.sem_dias[].nome" => "o nome do relógio",
                "relogios[]" => "os relógios, para trocar o de um dia (trocar_dia)",
                "relogios[].disponivel" => "verdadeiro ou falso: entra no rodízio (só o disponível pode ser escolhido para um dia)",
                "relogios[].id" => "o número do relógio",
                "relogios[].nome" => "o nome",
            ],
            "eventos" => [
                "eventos[]" => "os eventos personalizados (avisos seus, com horário e repetição próprios)",
                "eventos[].agenda" => "verdadeiro ou falso: o evento vai para o Google Agenda",
                "eventos[].ativo" => "verdadeiro ou falso: o evento dispara",
                "eventos[].criado" => "quando o evento foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].data_inicio" => "a data (uma vez só) ou o começo da contagem (a cada N dias) (texto AAAA-MM-DD); null nas outras",
                "eventos[].descricao" => "a repetição por extenso (\"seg, qua 20:00\")",
                "eventos[].dia_mes" => "o dia do mês (mensal), de 1 a 31; null nas outras",
                "eventos[].dias_semana" => "os dias da semana (semanal), de 1 (segunda) a 7 (domingo), separados por vírgula; null nas outras",
                "eventos[].disparos[]" => "as vezes em que já disparou",
                "eventos[].disparos[].disparado" => "quando o cron mandou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].disparos[].ocorrencia" => "a vez que disparou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema, a do cadastro)",
                "eventos[].hora" => "a hora do disparo (HH:MM)",
                "eventos[].id" => "o número do evento (o tipo dele nos canais é ev<id>)",
                "eventos[].intervalo_dias" => "de quantos em quantos dias (intervalo); null nas outras",
                "eventos[].nome" => "o nome do evento (texto)",
                "eventos[].proximas[]" => "as 10 próximas vezes em que dispara (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "eventos[].relogio" => "o nome desse relógio; null: evento geral",
                "eventos[].relogio_id" => "o relógio do evento (as âncoras dele funcionam na mensagem); null: evento geral",
                "eventos[].repeticao" => "quando dispara: uma (uma vez só), diaria, semanal, mensal ou intervalo (a cada N dias)",
                "eventos[].telegram" => "verdadeiro ou falso: o evento vai pelo Telegram (marcado em \"O que vai para onde\")",
            ],
            "agenda" => [
                "agenda_id" => "o id da agenda",
                "ativa" => "verdadeiro ou falso: a agenda está ligada",
                "chave_ok" => "verdadeiro ou falso: a chave do Google foi lida e o Google aceitou",
                "criados[]" => "os eventos que o sistema criou no Google Agenda",
                "criados[].assinatura" => "a marca do título e da descrição com que foi criado (mudou: o evento é atualizado na próxima sincronização)",
                "criados[].chave" => "a identificação do evento (a mesma dos desejados)",
                "criados[].criado" => "quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "criados[].data" => "o dia do evento (texto AAAA-MM-DD)",
                "criados[].google_id" => "o id do evento no Google",
                "criados[].titulo" => "o título com que foi criado",
                "desejados[]" => "o que tem de estar no Google Agenda agora: os avisos marcados para a agenda, o relógio do dia, a véspera e os eventos",
                "desejados[].chave" => "a identificação do evento na agenda (a mesma enquanto nada mudar; é o que o sistema usa para atualizar ou remover)",
                "desejados[].data" => "o dia do evento na agenda (texto AAAA-MM-DD)",
                "desejados[].descricao" => "a descrição do evento, montada pela mensagem do canal da agenda",
                "desejados[].fazer" => "a ação (\"Dar corda\", \"Usar hoje\", o nome do evento) — a âncora {acao}",
                "desejados[].hora" => "a hora do evento na agenda (HH:MM)",
                "desejados[].momento" => "o instante previsto do que motivou o evento (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "desejados[].motivo" => "o porquê (\"a reserva acaba em 3h, 28/09 10:53\") — a âncora {motivo}",
                "desejados[].relogio" => "o nome do relógio; vazio num evento geral",
                "desejados[].relogio_id" => "o relógio do evento; null num evento geral",
                "desejados[].tipo" => "o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)",
                "desejados[].titulo" => "o título do evento, montado pela mensagem do canal da agenda",
                "janela_dias" => "a antecedência da agenda, em dias",
            ],
            "usuarios" => [
                "usuarios[]" => "quem acessa",
                "usuarios[].criado" => "quando foi criado",
                "usuarios[].login" => "o login",
                "usuarios[].senha_algoritmo" => "o cálculo do hash: bcrypt (o padrão do password_hash do PHP), ou argon2i/argon2id",
                "usuarios[].senha_custo" => "o custo do bcrypt (quantas rodadas: 2 elevado a ele), que também está escrito no próprio hash ($2y$12$...: 12); null no argon2",
                "usuarios[].senha_hash" => "o hash da senha (a senha não existe no sistema): $2y$<custo>$ seguido do sal (22 caracteres) e do resultado (31). Não se desfaz em senha: confere-se a senha digitada contra ele (veja senhas na ajuda). Levado para outro sistema que confira por bcrypt, o usuário entra lá com a mesma senha; e volta para cá por usuarios/salvar com senha_hash",
                "voce" => "o login de quem pediu (pelo site; vazio pelo token)",
            ],
            "migracoes" => [
                "pendentes[]" => "as migrações do banco que faltam aplicar (vazio: em dia)",
                "pendentes[].arquivo" => "o arquivo .sql",
                "pendentes[].traz" => "o que a migração traz",
                "pendentes[].versao" => "a versão (v2, v3...)",
            ],
            "instalacao" => [
                "banco" => "o banco do config.php: mysql, pgsql ou sqlite",
                "em_dia" => "instalado e sem migração pendente",
                "instalado" => "o banco tem as tabelas do sistema",
                "migracoes_pendentes[]" => "as versões das migrações que faltam aplicar (vazio com o banco vazio ou em dia)",
                "proximo_passo" => "o que fazer agora: instalar (POST recurso=instalacao, acao=instalar), aplicar as migrações, criar o primeiro usuário (POST recurso=usuarios, acao=salvar, com o token) ou nada",
                "tabelas" => "quantas tabelas o banco tem",
                "usuarios" => "quantos usuários existem (0: o login das páginas ainda não tem como entrar)",
                "vazio" => "o banco não tem nenhuma tabela: só a instalação responde, pelo token",
            ],
            "importacao" => [
                "mysqli" => "o PHP tem a extensão do MySQL (mysqli), que a importação usa para ler o banco antigo",
                "precisa_substituir" => "este banco já tem relógios: a importação pede substituir=1 (apaga antes os relógios e o histórico)",
                "relogios_neste_banco" => "quantos relógios este banco tem agora",
                "servidor_antigo" => "onde a importação procura o banco antigo (MySQL/MariaDB); a senha nunca sai",
                "servidor_antigo.de_onde" => "de onde vêm esses dados: os ANTIGO_* do config.php, ou os DB_* (com este sistema no MySQL)",
                "servidor_antigo.host" => "o servidor",
                "servidor_antigo.porta" => "a porta (null: a padrão)",
                "servidor_antigo.usuario" => "o usuário que lê o banco antigo",
                "traz[]" => "o que a importação traz, em texto",
            ],
            "entrar" => [
                "por" => "como o pedido entrou: login (o do site, HTTP Basic) ou token",
                "usuario" => "o login de quem entrou (null pelo token)",
                "volta" => "a página para onde ele foi mandado (302), ou null",
            ],
            "documentos" => [
                "relogio" => "o relógio pedido: {id, nome, copia_banco}; null: os documentos de todos",
                "relogio.id" => "o número do relógio",
                "relogio.nome" => "o nome do relógio",
                "relogio.copia_banco" => "verdadeiro: o relógio pede a cópia no banco de todos os documentos dele (o segundo dos três níveis da cópia; o DOCUMENTOS_COPIA_BANCO do config.php, se definido, vale por cima)",
                "pasta_ok" => "verdadeiro: a pasta dos documentos (DOCUMENTOS_PASTA do config.php) existe e aceita gravar",
                "copia_sistema" => "a cópia no banco pelo config.php (DOCUMENTOS_COPIA_BANCO), que vale sobre o relógio e o arquivo: verdadeiro, todo arquivo vai; falso, nenhum vai; null (sem a constante), cada relógio e cada arquivo decidem",
                "pasta_erro" => "o motivo de a pasta não servir (texto); null: ela serve",
                "limite" => "o maior arquivo aceito, em bytes (número inteiro): o DOCUMENTOS_LIMITE do config.php (sem ele, 100 MB), ou menos pelo upload_max_filesize e o post_max_size do PHP; null: sem limite",
                "relogios[]" => "todos os relógios, pelo nome (para escolher outro)",
                "relogios[].documentos" => "quantos documentos ele tem",
                "relogios[].id" => "o número do relógio",
                "relogios[].nome" => "o nome do relógio",
                "categorias[]" => "as categorias dos documentos, na ordem",
                "categorias[].aceita[]" => "os tipos de arquivo que ela aceita (imagem, video, audio, pdf, xml); lista vazia: qualquer arquivo",
                "categorias[].documentos" => "quantos documentos ela tem (com relogio: só os daquele relógio)",
                "categorias[].id" => "o número da categoria",
                "categorias[].identificador" => "o identificador (minúsculas, números e _)",
                "categorias[].nome" => "o nome",
                "categorias[].ordem" => "a ordem em que ela aparece (número)",
                "documentos[]" => "os documentos do relógio (o manual, a nota fiscal, fotos, vídeos...): os dados de cada um; o arquivo vem pelo recurso=documento",
                "documentos[].categoria_id" => "a categoria dele (as categorias dos documentos)",
                "documentos[].criado" => "quando foi enviado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)",
                "documentos[].data" => "a data do documento: a ocasião da foto, a data da nota (texto AAAA-MM-DD; null: sem data)",
                "documentos[].descricao" => "a descrição (texto; null: sem descrição)",
                "documentos[].familia" => "como ele abre, pelo tipo do arquivo: imagem (a galeria), video (o player), audio, pdf (o visualizador), xml (o resumo da nota e o download) ou outro (o download)",
                "documentos[].id" => "o número do documento (para recurso=documento e para alterar ou excluir)",
                "documentos[].miniatura" => "verdadeiro: a foto tem miniatura (recurso=documento com mini=1)",
                "documentos[].nome" => "o nome do arquivo enviado",
                "documentos[].no_disco" => "verdadeiro: o arquivo está na pasta dos documentos (falso: ele volta do banco quando for pedido ou na próxima rodada do cron; sem a cópia no banco, está perdido)",
                "documentos[].no_banco" => "verdadeiro: a cópia de segurança do arquivo está completa no banco (se ele sumir da pasta, volta dali)",
                "documentos[].copia_banco" => "verdadeiro: o próprio arquivo pede a cópia no banco (o terceiro nível; o relógio e o config.php, se pedem, valem por cima)",
                "documentos[].copia_por" => "quem pede a cópia no banco: sistema (o DOCUMENTOS_COPIA_BANCO = true do config.php), relogio (a marca do relógio) ou arquivo (a marca do próprio arquivo); null: ninguém pede, ou o config.php (false) não deixa, e o arquivo fica só na pasta (a cópia que houver, o cron tira)",
                "documentos[].relogio_id" => "o relógio dele",
                "documentos[].tamanho" => "o tamanho do arquivo, em bytes (número inteiro)",
                "documentos[].tipo" => "o tipo do arquivo (MIME): image/jpeg, video/mp4, application/pdf, application/xml...",
                "documentos[].titulo" => "o título",
                "documentos[].url" => "o endereço do arquivo, relativo à pasta do sistema (api.php?recurso=documento&id=...)",
                "documentos[].nfe" => "o resumo da NF-e, quando o arquivo é o XML de uma nota fiscal eletrônica; null: não é",
                "documentos[].nfe.chave" => "a chave de acesso da nota (44 dígitos)",
                "documentos[].nfe.cnpj" => "o CNPJ (ou o CPF) do emitente",
                "documentos[].nfe.data" => "a data de emissão (texto AAAA-MM-DD)",
                "documentos[].nfe.emitente" => "o nome do emitente (a loja)",
                "documentos[].nfe.numero" => "o número da nota",
                "documentos[].nfe.produtos[]" => "os produtos da nota",
                "documentos[].nfe.produtos[].descricao" => "a descrição do produto",
                "documentos[].nfe.produtos[].quantidade" => "a quantidade (número)",
                "documentos[].nfe.produtos[].valor" => "o valor do produto (número, em R$)",
                "documentos[].nfe.serie" => "a série da nota",
                "documentos[].nfe.valor" => "o valor total da nota (número, em R$)",
            ],
            "manual" => [
                "fonte" => "de onde vem o texto: README.md",
                "trechos[]" => "os trechos do README que entram, na ordem: cada um da seção \"de\" até antes da seção \"ate\"",
                "trechos[].de" => "o título da seção em que o trecho começa",
                "trechos[].ate" => "o título da seção em que o trecho para (ela fica de fora)",
                "secoes[]" => "cada título do manual (## a ####), na ordem",
                "secoes[].nivel" => "o nível do título: 2 (seção), 3 ou 4 (dentro dela)",
                "secoes[].titulo" => "o título, como no README (em markdown)",
                "secoes[].ancora" => "a âncora do título, como a do GitHub e a da página Ajuda (ajuda.php#ancora)",
                "secoes[].texto" => "o texto da seção até o próximo título, em markdown (tabelas, listas, código, imagens)",
                "markdown" => "o manual inteiro, em markdown: o que a página Ajuda converte para HTML",
                "html" => "o manual inteiro já em HTML, como a página Ajuda mostra: títulos com âncora, parágrafos, listas, tabelas, código, imagens e as legendas (o diagrama mermaid fica de fora)",
                "sumario[]" => "o sumário da página Ajuda: os títulos de nível 2 e 3, na ordem",
                "sumario[].ancora" => "a âncora do título (ajuda.php#ancora)",
                "sumario[].nivel" => "o nível do título: 2 ou 3",
                "sumario[].titulo" => "o título em texto puro (sem a marcação do markdown)",
            ],
            "reconstrucao" => [
                "o_que_e" => "o que o sistema é e faz, num parágrafo",
                "como_usar[]" => "como ler esta resposta e onde está o resto (a API em recurso=ajuda, o cadastro inicial no schema.sql e em recurso=cadastros e recurso=criterios, o README, os testes)",
                "secoes[]" => "as partes do roteiro, na ordem: os princípios, a arquitetura, a ordem de construção e as regras de cada parte do sistema",
                "secoes[].id" => "o nome curto da parte (principios, arquitetura, ordem, arvore_campos, lancamentos, formulas, avisos, gasto, criterios, rodizio, pulso, cron, mensagens, documentos, telas, seguranca, testes)",
                "secoes[].titulo" => "o título da parte",
                "secoes[].itens[]" => "as regras e as explicações daquela parte, uma por texto",
                "modelo_de_dados" => "o modelo de dados, lido do próprio banco em uso (o que vale agora, depois de todas as migrações)",
                "modelo_de_dados.banco" => "o banco de onde o modelo foi lido: mysql, pgsql ou sqlite (os tipos das colunas vêm no dialeto dele)",
                "modelo_de_dados.como_ler" => "como ler as tabelas, e as três referências que ficam pelo nome, de propósito",
                "modelo_de_dados.tabelas[]" => "cada tabela, em ordem alfabética",
                "modelo_de_dados.tabelas[].tabela" => "o nome da tabela",
                "modelo_de_dados.tabelas[].descricao" => "o que a tabela guarda",
                "modelo_de_dados.tabelas[].colunas[]" => "as colunas, na ordem da tabela",
                "modelo_de_dados.tabelas[].colunas[].nome" => "o nome da coluna",
                "modelo_de_dados.tabelas[].colunas[].tipo" => "o tipo, no dialeto do banco em uso",
                "modelo_de_dados.tabelas[].colunas[].vazio" => "verdadeiro: aceita vazio (NULL)",
                "modelo_de_dados.tabelas[].colunas[].padrao" => "o valor padrão, como o banco escreve (null: nenhum)",
                "modelo_de_dados.tabelas[].chave_primaria[]" => "as colunas da chave primária",
                "modelo_de_dados.tabelas[].chaves_estrangeiras[]" => "as ligações com outras tabelas",
                "modelo_de_dados.tabelas[].chaves_estrangeiras[].coluna" => "a coluna desta tabela",
                "modelo_de_dados.tabelas[].chaves_estrangeiras[].referencia" => "a tabela.coluna para onde ela aponta",
                "modelo_de_dados.tabelas[].chaves_estrangeiras[].ao_apagar" => "o que acontece ao apagar a linha de cima: CASCADE (some junto) ou SET NULL (a ligação se desfaz)",
                "modelo_de_dados.tabelas[].unicas[]" => "cada chave única: a lista das colunas dela",
                "modelo_de_dados.tabelas[].unicas[][]" => "uma coluna da chave única, na ordem",
                "modelo_de_dados.tabelas[].indices[]" => "cada índice que não é único: a lista das colunas dele",
                "modelo_de_dados.tabelas[].indices[][]" => "uma coluna do índice, na ordem",
                "modelo_de_dados.tabelas[].regras[]" => "as regras de validação (CHECK) da tabela, inclusive as das listas fechadas",
                "modelo_de_dados.tabelas[].regras[].nome" => "o nome da regra (ck_<tabela>_<o quê>; null: a de uma lista fechada, sem nome)",
                "modelo_de_dados.tabelas[].regras[].condicao" => "a condição que toda linha tem de cumprir, como o banco escreve",
                "listas" => "as listas do código que a reescrita precisa ter iguais",
                "listas.funcoes_das_formulas[]" => "as funções do motor das fórmulas",
                "listas.funcoes_das_formulas[].nome" => "o nome da função",
                "listas.funcoes_das_formulas[].argumentos" => "quantos argumentos ela aceita (\"2\", \"1 a 2\", \"3 ou mais\")",
                "listas.funcoes_das_formulas[].descricao" => "o que ela devolve",
                "listas.funcoes_das_formulas[].le_historico" => "verdadeiro: lê os lançamentos do relógio (o primeiro argumento é um tipo de lançamento, entre aspas)",
                "listas.tipos_de_campo[]" => "os tipos de campo do cadastro",
                "listas.tipos_de_campo[].tipo" => "o tipo (inteiro, decimal, sim_nao, data, lista, texto)",
                "listas.tipos_de_campo[].descricao" => "o que ele é, e como entra nas contas",
                "listas.formatos_de_lancamento[]" => "os formatos dos tipos de lançamento",
                "listas.formatos_de_lancamento[].formato" => "o formato (instantaneo, valor, sessao)",
                "listas.formatos_de_lancamento[].descricao" => "o que ele é",
                "listas.repeticoes[]" => "as repetições dos eventos personalizados",
                "listas.repeticoes[].repeticao" => "a repetição (uma, diaria, semanal, mensal, intervalo)",
                "listas.repeticoes[].descricao" => "como ela aparece na tela",
                "listas.dias_da_semana[]" => "os dias da semana como o sistema numera",
                "listas.dias_da_semana[].dia" => "o número (1 segunda a 7 domingo)",
                "listas.dias_da_semana[].nome" => "o nome",
                "listas.ancoras[]" => "as âncoras das mensagens (entre chaves no texto)",
                "listas.ancoras[].ancora" => "a âncora, sem as chaves",
                "listas.ancoras[].descricao" => "o que ela vira",
                "listas.canais[]" => "os canais das mensagens",
                "listas.canais[].canal" => "o código do canal (tg: Telegram, ag: Google Agenda)",
                "listas.canais[].nome" => "o nome",
                "listas.canais[].ajuda" => "como a mensagem sai nele",
                "listas.migracoes[]" => "as migrações, na ordem",
                "listas.migracoes[].versao" => "a versão (v2, v3...)",
                "listas.migracoes[].arquivo" => "o arquivo do SQL dela",
                "listas.migracoes[].traz" => "o que ela traz",
                "listas.migracoes[].passo_em_php" => "verdadeiro: ela tem também um passo em programa, depois do SQL",
                "listas.config_php[]" => "as constantes do config.php",
                "listas.config_php[].constante" => "o nome (ou os nomes) da constante",
                "listas.config_php[].descricao" => "para que ela serve",
            ],
            "_filtros (em qualquer consulta com filtros)" => [
                "_filtros" => "aparece quando a consulta usa filtros (incluir, excluir, f, busca, ordem, limite, pagina, mostrar)",
                "_filtros.ignorados[]" => "o que foi pedido e não existe (caminho sem lista, parte que não existe)",
                "_filtros.listas.<lista>.antes" => "quantos itens a lista tinha",
                "_filtros.listas.<lista>.devolvidos" => "quantos vieram (a página)",
                "_filtros.listas.<lista>.limite" => "o limite por página; null: sem",
                "_filtros.listas.<lista>.pagina" => "a página",
                "_filtros.listas.<lista>.passaram" => "quantos passaram nos filtros",
            ],
        ],
        "parametros_gerais" => [
            "recurso" => "o recurso (vazio: tudo)", "acao" => "a ação (escrita)", "formato" => "json (padrão) ou xml", "token" => "o token da API; também no cabeçalho X-Api-Token, ou o login do site (HTTP Basic) no lugar dele",
        ],
        "erros" => ["400" => "pedido recusado: uma escrita que não passou nas validações ({ok: false, mensagem, erros: [...]}, um erro por motivo) ou uma fórmula mal escrita no recurso=calcular ({erro, erros})",
            "302" => "recurso=entrar com o login certo e volta=<página>: vai para a página",
            "401" => "sem o token nem o login do site, ou com eles errados; sem nenhum usuário (ou com o banco vazio), também sem_usuarios: true e como (o que fazer: instalar, criar o primeiro usuário pelo token)",
            "404" => "recurso desconhecido (com recursos, a lista deles), ou o relógio, a foto, o documento (ou o arquivo dele) ou o README.md (recurso=manual) que não existe",
            "409" => "o banco recusou a gravação (um registro que outro ainda usa, um valor repetido, ou um valor fora das regras de validação do banco: o nome da regra, ck_<tabela>_<o quê>, vem no detalhe)",
            "413" => "o envio passou do limite do PHP do servidor (post_max_size): um arquivo grande demais",
            "500" => "sistema parado (token ou fuso inválido no config.php) ou erro interno", "503" => "banco desatualizado (só recurso=migracoes responde, e o recurso=manual, que não usa o banco), banco vazio (antes da instalação só recurso=instalacao responde, e o manual) ou banco fora do ar"],
        "motor" => array_map(function ($f) { return $f[2]; }, $GLOBALS["FUNCOES"]),
        "escrita_das_formulas" => "números com vírgula ou ponto; textos entre aspas; argumentos separados por ponto e vírgula; operações + - * / ^; "
            . "comparações = <> < <= > >= (dão 1 ou 0); variáveis: os identificadores dos campos e das fórmulas; vazio se propaga; divisão por zero: vazio",
    ];
} elseif ($recurso === "") {
    $com_foto = ($_REQUEST["foto"] ?? "") !== "nao";
    $arvore = [];
    foreach (nos_em_ordem() as $o) {
        $x = nos_todos()[$o[0]];
        $arvore[] = ["id" => $o[0], "pai_id" => $x["pai_id"] === null ? null : (int)$x["pai_id"], "nome" => $x["nome"], "ordem" => (int)$x["ordem"],
            "caminho" => no_caminho($o[0]), "profundidade" => $o[1]];
    }
    $campos = [];
    foreach (campos_todos() as $c) {
        $campos[] = ["id" => (int)$c["id"], "identificador" => $c["identificador"], "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"],
            "opcoes" => $c["opcoes"], "padrao" => $c["padrao"], "no_id" => $c["no_id"] === null ? null : (int)$c["no_id"], "lugar" => no_caminho($c["no_id"]), "ordem" => (int)$c["ordem"]];
    }
    $tipos_l = [];
    foreach (lancamento_tipos() as $t) {
        $tipos_l[] = ["id" => (int)$t["id"], "identificador" => $t["identificador"], "nome" => $t["nome"], "formato" => $t["formato"], "unidade" => $t["unidade"],
            "fecha_as" => $t["fecha_as"] === null ? null : substr($t["fecha_as"], 0, 5), "exclusiva" => (int)$t["exclusiva"] === 1, "mede_gasto" => (int)$t["mede_gasto"] === 1, "condicao" => $t["condicao"],
            "no_id" => $t["no_id"] === null ? null : (int)$t["no_id"], "lugar" => no_caminho($t["no_id"]), "ordem" => (int)$t["ordem"]];
    }
    $formulas = [];
    foreach (formulas_todas() as $versoes) {
        foreach ($versoes as $f) {
            try {
                $usa = formula_referencias(formula_ler($f["expressao"]));
            } catch (ErroFormula $e) {
                $usa = ["erro" => $e->getMessage()];
            }
            $formulas[] = ["id" => (int)$f["id"], "identificador" => $f["identificador"], "nome" => $f["nome"], "expressao" => $f["expressao"], "unidade" => $f["unidade"],
                "no_id" => $f["no_id"] === null ? null : (int)$f["no_id"], "lugar" => no_caminho($f["no_id"]), "usa" => $usa];
        }
    }
    $relogios = [];
    foreach (linhas("SELECT * FROM relogio ORDER BY nome") as $r) {
        $valores = valores_do_relogio((int)$r["id"]);
        $cs = [];
        foreach (campos_do_relogio($r) as $ident => $c) {
            $cs[] = ["identificador" => $ident, "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "valor" => $valores[$ident] ?? null,
                "valor_usado" => ($valores[$ident] ?? "") !== "" ? $valores[$ident] : $c["padrao"]];
        }
        $fs = [];
        foreach (formulas_todas() as $ident => $versoes) {
            $f = mais_perto($versoes, $r);
            if ($f !== null) {
                $ctx = ["r" => $r, "momento" => time(), "rastro" => [], "pilha" => [], "valores" => $valores];
                $fs[] = ["identificador" => $ident, "nome" => $f["nome"], "unidade" => $f["unidade"], "valor" => variavel_valor($ident, $ctx), "versao" => no_caminho($f["no_id"])];
            }
        }
        $foto = linha("SELECT tipo, atualizado" . ($com_foto ? ", dados" : "") . " FROM foto WHERE relogio_id = ?", [(int)$r["id"]]);
        $relogios[] = ["id" => (int)$r["id"], "nome" => $r["nome"], "no_id" => $r["no_id"] === null ? null : (int)$r["no_id"], "lugar" => no_caminho($r["no_id"]),
            "disponivel" => (int)$r["disponivel"] === 1, "copia_banco" => (int)$r["copia_banco"] === 1, "criado" => $r["criado"],
            "foto" => $foto ? ["tipo" => $foto["tipo"], "atualizada_em" => $foto["atualizado"], "base64" => $com_foto ? base64_encode($foto["dados"]) : null] : null,
            "documentos" => array_map(function ($x) { return documento_info($x, false); }, documentos_do_relogio((int)$r["id"])),
            "campos" => $cs, "formulas" => $fs, "avisos" => avisos_do_relogio($r, time()),
            "lancamento_tipos" => array_keys(lancamento_tipos_do_relogio($r)),
            "lancamentos" => array_map(function ($l) {
                return ["id" => (int)$l["id"], "tipo" => $l["tipo"], "inicio" => $l["inicio"], "fim" => $l["fim"], "valor" => $l["valor"] === null ? null : (float)$l["valor"],
                    "origem" => $l["origem"], "criado" => $l["criado"]];
            }, linhas("SELECT l.*, t.identificador AS tipo FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? ORDER BY l.inicio, l.id", [(int)$r["id"]])),
            "previsao" => previsao_energia($r, time())];
        $lt = linha_do_tempo((int)$r["id"], 0, PHP_INT_MAX, []);
        $relogios[count($relogios) - 1]["linha_do_tempo"] = array_map("linha_legivel", $lt["linhas"]);
        $relogios[count($relogios) - 1]["resumo_do_tempo"] = resumo_do_tempo($lt);
        $relogios[count($relogios) - 1]["medicoes"] = medicoes_legivel((int)$r["id"]);
        $relogios[count($relogios) - 1] = array_merge($relogios[count($relogios) - 1], autonomia_do_relogio($r, time()));
    }
    $modos = [];
    foreach (linhas("SELECT * FROM modo ORDER BY ordem, id") as $m) {
        $modos[] = ["id" => (int)$m["id"], "nome" => $m["nome"], "selecao" => $m["selecao"], "escala_dias" => $m["escala_dias"] === null ? null : (int)$m["escala_dias"], "ciclo" => (int)$m["ciclo"] === 1,
            "ordem" => (int)$m["ordem"], "ativo" => (int)$m["id"] === modo_ativo(),
            "blocos" => array_map(function ($b) {
                return ["id" => (int)$b["id"], "nome" => $b["nome"], "dias" => array_map("intval", explode(",", $b["dias"])), "no_id" => $b["no_id"] === null ? null : (int)$b["no_id"],
                    "lugar" => no_caminho($b["no_id"]), "um_por" => $b["um_por"], "relogio_id" => $b["relogio_id"] === null ? null : (int)$b["relogio_id"]];
            }, modo_blocos((int)$m["id"]))];
    }
    $config = [];
    foreach (linhas("SELECT chave, valor FROM config ORDER BY chave") as $c) {
        $config[$c["chave"]] = $c["valor"];
    }
    $avisos = [];
    foreach (avisos_todos() as $versoes) {
        foreach ($versoes as $a) {
            $avisos[] = ["id" => (int)$a["id"], "identificador" => $a["identificador"], "nome" => $a["nome"], "expressao" => $a["expressao"], "condicao" => $a["condicao"],
                "antecedencia_dias" => (float)$a["antecedencia_dias"], "texto" => $a["texto"], "resolve" => $a["resolve"], "ativo" => (int)$a["ativo"] === 1,
                "no_id" => $a["no_id"] === null ? null : (int)$a["no_id"], "lugar" => no_caminho($a["no_id"]), "escala" => $a["escala"],
                "simula_valor" => $a["simula_valor"] === null ? null : (float)$a["simula_valor"], "simula_horas" => $a["simula_horas"] === null ? null : (float)$a["simula_horas"],
                "agenda" => $a["agenda"]];
        }
    }
    $saida = ["arvore" => $arvore, "campos" => $campos, "lancamento_tipos" => $tipos_l, "formulas" => $formulas, "avisos" => $avisos, "relogios" => $relogios,
        "documento_categorias" => documento_categorias_lista(), "modos" => $modos, "plano" => plano_legivel("", ""), "config" => (object)$config,
        "eventos" => array_map("evento_legivel", eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome"))),
        "agenda" => ["criados" => linhas("SELECT chave, google_id, data, titulo, assinatura, criado FROM agenda_evento ORDER BY data, chave"), "desejados" => agenda_legivel()],
        "criterios" => montar_criterios(),
        "cron" => ["ultima" => cfg("cron_ultima_execucao"), "erro" => cfg("cron_erro"), "execucoes" => array_map(function ($x) {
            return ["id" => (int)$x["id"], "inicio" => $x["inicio"], "fim" => $x["fim"], "duracao_ms" => (int)$x["duracao_ms"], "teve_atividade" => (int)$x["teve_atividade"] === 1,
                "teve_erro" => (int)$x["teve_erro"] === 1, "registro" => $x["registro"]];
        }, linhas("SELECT * FROM cron_execucao ORDER BY inicio DESC, id DESC"))],
        "usuarios" => usuarios_catalogo(),
        "migracoes" => ["pendentes" => []],
        "motor" => ["funcoes" => array_map(function ($f) { return $f[2]; }, $FUNCOES), "tipos_de_campo" => $TIPOS_CAMPO, "formatos_de_lancamento" => $FORMATOS_LANCAMENTO]];
} else {
    $codigo = 404;
    $saida = ["erro" => "recurso desconhecido", "recursos" => ["(nenhum)", "autonomia", "hoje", "ficha", "config", "cron", "arvore", "cadastros", "calcular", "avisos", "criterios",
        "historico", "previsao", "plano", "eventos", "agenda", "foto", "documentos", "documento", "usuarios", "migracoes", "instalacao", "importacao", "entrar", "ajuda", "manual", "reconstrucao"]];
}
// Os filtros de qualquer consulta (GET): incluir e excluir (as partes da resposta), e por lista (o caminho entre os colchetes):
// f[lista][campo] (igual; ou [de], [ate], [contem], [diferente], [vazio]), busca[lista], ordem[lista], limite[lista],
// pagina[lista], mostrar[lista]. Os parâmetros limite, pagina, ordem e busca sem colchetes continuam sendo os do recurso
// (histórico, cron). A resposta ganha "_filtros": quantos itens havia em cada lista, quantos passaram e quantos vieram.
if (!$escrita && $codigo === 200 && is_array($saida)) {
    $pede = function ($nome) {
        return isset($_GET[$nome]) && is_array($_GET[$nome]) ? $_GET[$nome] : [];
    };
    $caminhos = array_unique(array_merge(array_keys($pede("f")), array_keys($pede("busca")), array_keys($pede("ordem")), array_keys($pede("limite")),
        array_keys($pede("pagina")), array_keys($pede("mostrar"))));
    $partes_pedidas = [];
    foreach (["incluir", "excluir"] as $k) {
        $partes_pedidas[$k] = isset($_GET[$k]) && !is_array($_GET[$k]) && trim((string)$_GET[$k]) !== "" ? array_map("trim", explode(",", (string)$_GET[$k])) : [];
    }
    if (count($caminhos) > 0 || count($partes_pedidas["incluir"]) > 0 || count($partes_pedidas["excluir"]) > 0) {
        // o JSON de volta em listas e objetos do PHP (os objetos vazios passam a sair como listas vazias)
        $saida = json_decode((string)json_encode($saida), true);
        $info = ["listas" => [], "ignorados" => []];
        foreach ($caminhos as $caminho) {
            $f = $pede("f")[$caminho] ?? [];
            $regras = ["f" => is_array($f) ? $f : [], "busca" => (string)($pede("busca")[$caminho] ?? ""),
                "ordem" => array_values(array_filter(array_map("trim", explode(",", (string)($pede("ordem")[$caminho] ?? ""))))),
                "limite" => max(0, (int)($pede("limite")[$caminho] ?? 0)), "pagina" => max(1, (int)($pede("pagina")[$caminho] ?? 1)),
                "mostrar" => array_values(array_filter(array_map("trim", explode(",", (string)($pede("mostrar")[$caminho] ?? "")))))];
            $conta = ["listas" => 0, "antes" => 0, "depois" => 0, "devolvidos" => 0];
            filtrar_lista($saida, explode(".", (string)$caminho), $regras, $conta);
            if ($conta["listas"] === 0) {
                $info["ignorados"][] = $caminho . ": não há lista nesse caminho";
            } else {
                $info["listas"][$caminho] = ["antes" => $conta["antes"], "passaram" => $conta["depois"], "devolvidos" => $conta["devolvidos"],
                    "pagina" => $regras["pagina"], "limite" => $regras["limite"] > 0 ? $regras["limite"] : null];
            }
        }
        // as partes da resposta: só as pedidas em incluir, menos as de excluir (a parte que não existe vai para os ignorados)
        if (!array_is_list($saida)) {
            foreach (array_merge($partes_pedidas["incluir"], $partes_pedidas["excluir"]) as $parte) {
                if (!array_key_exists($parte, $saida)) {
                    $info["ignorados"][] = $parte . ": a resposta não tem essa parte";
                }
            }
            $saida = array_filter($saida, function ($parte) use ($partes_pedidas) {
                return (count($partes_pedidas["incluir"]) === 0 || in_array((string)$parte, $partes_pedidas["incluir"], true))
                    && !in_array((string)$parte, $partes_pedidas["excluir"], true);
            }, ARRAY_FILTER_USE_KEY);
            $saida["_filtros"] = $info;
        }
    }
}
responde($codigo, $saida, $formato);
