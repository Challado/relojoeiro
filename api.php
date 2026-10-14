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
 *                   "relogios", "modos", "plano", "plano_livre", "config", "eventos", "agenda", "criterios", "cron", "usuarios", "migracoes",
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
 *                            [pagina=N, por_pagina=N, relogio=<id>: a página do "dia_a_dia"; sem por_pagina, o "Mostrar até" da
 *                            Configuração (plano_por_pagina, padrão 31); relogio: só os dias dele]
 *                            o plano gravado (sem nada: inteiro), com "escala_fim", o último dia gravado e o modo ativo, e o resumo do
 *                            período como a página Plano mostra: {"modo", "escala_fim", "plano_fim", "hoje", "plano": [como em "plano"
 *                            acima], "livres": [os dias livres do período: sem relógio e sem sorteio], "dia_a_dia": {"por_pagina",
 *                            "pagina", "paginas", "total", "relogio", "dias": [{"data", "dia_semana", "dia_semana_curto", "hoje",
 *                            "relogio_id", "relogio", "origem" (sorteio, manual ou livre), "acao", "motivo"}]} (o que a página Plano
 *                            mostra, uma página por vez, com os dias livres no meio), "resumo": {"de", "ate", "dias",
 *                            "relogios": [{"relogio_id", "relogio", "dias", "porcentagem",
 *                            "proximo", "a_mao"}], "sem_dias": [{"id", "nome"}]}, "relogios"}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=plano&de=2026-10-01&ate=2026-10-31"
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=plano&dias=90"
 *   recurso=proximos         [pagina=N, por_pagina=N] os Próximos dias da página Hoje, uma página (de hoje até o fim do plano, com os
 *                            dias livres no meio; sem por_pagina, o "Mostrar até" da Configuração: proximos_por_pagina, padrão 7):
 *                            {"por_pagina", "pagina", "paginas", "total", "relogio" (null), "dias": [como em plano.dia_a_dia]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=proximos&pagina=2"
 *   recurso=rodizio_manual   de, ate (AAAA-MM-DD, de hoje em diante, até 2 anos), [pagina: 1, 2...]: o Rodízio manual (o quadro Modo de
 *                            rodízio da página Hoje): os dias do período de 7 em 7, cada um com o que o plano tem agora e a escolha de
 *                            partida do seletor (o relógio escolhido à mão, livre ou auto: Decida por mim): {"de", "ate", "total_dias",
 *                            "por_pagina", "pagina", "paginas", "dias": [{"data", "dia_semana", "dia_semana_nome", "hoje", "relogio_id",
 *                            "relogio", "origem", "motivo", "livre", "escolha"}], "relogios": [os disponíveis: {"id", "nome"}],
 *                            "decida_por_mim" (o critério)}. Período inválido: 400 com "erro". Gravar: recurso=rodizio, acao=manual
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=rodizio_manual&de=2026-12-20&ate=2027-01-05&pagina=2"
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
 *                            "relogio", "ate" ("só hoje", "até sexta, 02/10"), "acao" (o lembrete do dia), "motivo" (por que ele saiu)}, "destaque":
 *                            {"mostra" (a Configuração: dia ou pulso), "relogio_id" (o relógio do destaque no alto da página: o do
 *                            dia, ou o que está no pulso agora), "e" (dia ou pulso), "do_dia", "no_pulso", "texto"}, "avisos": [os de hoje, atrasados
 *                            ou em breve: {"relogio_id", "relogio", "identificador", "nome", "texto" (o motivo), "estado", "resolve":
 *                            {"identificador", "nome", "formato", "unidade", "acao", "botao"} (o lançamento do botão)}], "plano": [os
 *                            próximos 62 dias: {"data", "relogio_id", "relogio", "acao", "motivo", "origem"}], "livres": [os dias livres
 *                            dos próximos 62 dias], "proximos": {os Próximos dias da página, uma página: como em recurso=proximos;
 *                            proximos_pagina=N escolhe a página}, "proxima_semana": {"segunda", "domingo", "ja_montada"}, "modos":
 *                            [{"id", "nome", "selecao", "escala_dias", "blocos": [{"id", "nome", "dias", "no_id", "um_por", "relogio_id"}]}],
 *                            "grupos": [{"id", "nome", "profundidade", "caminho"}], "avisos_nomes", "estados" (o filtro Estado),
 *                            "relogios": [{"id", "nome", "disponivel", "tipo" (o grupo), "em_uso", "estado" (uso, sessao, repouso ou
 *                            indisponivel), "estado_texto" ("Em uso", "No sol", "No carregador", "Em repouso", "Indisponível"),
 *                            "estado_ordem", "agora" ("No sol desde 09:12"), "carga" (%), "carga_de" (de onde
 *                            vem), "ultimo" (a última vez no pulso, em segundos), "ultimo_txt", "situacao": [linhas], "manutencao":
 *                            {"nome", "data", "momento", "falta"} (o aviso mais perto), "compra": {"data", "valor", "loja", "garantia_ate"},
 *                            "leitura": {"inicio", "valor", "unidade"} (a última), "de_hoje", "destaque" (é o do destaque no alto), "com_aviso",
 *                            "foto" (a versão)}], "totais":
 *                            {"relogios", "disponiveis", "em_uso", "valor"} (da coleção inteira)}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=hoje"
 *   recurso=ficha            relogio=<id>: tudo o que o painel de um relógio mostra: {"hoje", "copia_sistema", "painel_modo",
 *                            "relogio": {"id", "nome", "no_id",
 *                            "disponivel", "copia_banco", "tipo", "caminho", "observacao", "foto", "dica_foto" (a dica da foto: o
 *                            resumo do relógio e o duplo clique), "documentos": {"total",
 *                            "categorias": [{"id", "nome", "documentos"}], "pasta_ok", "copia_sistema"}, "em_uso", "estado",
 *                            "estado_texto", "agora", "carga",
 *                            "carga_de", "situacao", "nota": {"nota", "conjunto_texto"}, "proxima" (a próxima entrada no plano), "proxima_ate", "escala_fim",
 *                            "compra", "manutencoes": [{"data", "nome"}], "previsao" (como em recurso=previsao), "tipos": [os lançamentos
 *                            do relógio: {"identificador", "nome", "formato", "unidade", "aberta": {"inicio", "rodizio", "texto"}}],
 *                            "leituras": [as 40 últimas com valor: {"inicio", "valor", "unidade", "em_uso"}], "linha_do_tempo" (as 5
 *                            linhas mais recentes), "registros", "desde"}, "campos": [o cadastro: {"identificador", "nome", "tipo",
 *                            "unidade", "opcoes", "padrao", "no_id", "valor"}], "grupos": [{"id", "caminho", "cadeia"}]}. Sem relogio:
 *                            só "campos" e "grupos" (o cadastro de um relógio novo); relógio que não existe: 404
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=ficha&relogio=10"
 *   recurso=config           tudo o que a página Configuração mostra: {"config": {chave: valor} (inclusive agenda_chave), "mostrar_ate":
 *                            {"proximos", "plano"} (quantos dias por página, já com o padrão), "aleatorio": {"fontes", "ultimo_lote",
 *                            "fora_desde"} (de onde vêm os números dos sorteios: RANDOM.ORG, senão ANU QRNG, senão o random_int), "sol_fim",
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
 *   recurso=instalacao       a situação da instalação (responde também com o banco vazio, pelo token): {"banco" (mysql, pgsql,
 *                            sqlite ou firebird), "vazio", "instalado", "tabelas", "em_dia", "migracoes_pendentes": [versões], "usuarios" (quantos),
 *                            "proximo_passo" (instalar, aplicar as migrações, criar o primeiro usuário ou nada)}
 *                            Ex.: curl -H "X-Api-Token: segredo" "http://servidor/relojoeiro/api.php?recurso=instalacao"
 *   recurso=importacao       o que a importação do sistema anterior usa: {"servidor_antigo": {"host", "porta", "usuario", "de_onde"} (sem a
 *                            senha), "mysqli" (a extensão do MySQL no PHP), "relogios_neste_banco", "precisa_substituir", "traz": [o que ela
 *                            traz]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=importacao"
 *   recurso=backup           a cópia de segurança do banco (uma por dia, na rodada da noite do cron, na BACKUP_PASTA do config.php):
 *                            {"pasta_ok", "pasta_erro", "manter" (quantas ficam), "documentos" (se levam o conteúdo dos documentos),
 *                            "versao" (a do banco agora), "ultima", "copias": [{"arquivo", "bytes", "tamanho", "criada", "documentos",
 *                            "versao"}] (da mais nova para a mais velha)}. Com arquivo=<nome>: baixa aquela cópia (relojoeiro-AAAA-MM-DD-
 *                            HHMMSS.jsonl.gz: JSON Lines comprimido, o mesmo nos quatro bancos). Fazer e restaurar: na escrita
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=backup"
 *   recurso=entrar           o login das páginas: sem login, 401 (o navegador pede o usuário e a senha); com login, volta para a página
 *                            de volta=<uma página do sistema> (302) ou diz quem entrou: {"usuario" (null pelo token), "por" (login ou
 *                            token), "volta"}. É para onde o api.js manda quem abre uma página sem login
 *                            Ex.: no navegador, http://servidor/relojoeiro/api.php?recurso=entrar&volta=index.php
 *   recurso=ajuda            esta documentação em JSON: cada consulta com a descrição, os parâmetros e um exemplo; cada escrita com as ações,
 *                            os campos de cada uma e um exemplo; o dicionário de todos os campos das respostas ("campos"); a
 *                            autenticação, o formato, os erros e as funções do motor. [parte=manual: o manual, abaixo;
 *                            parte=reconstrucao: a reconstrução, abaixo; parte=codigo: o código, abaixo]
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
 *                            "telas": {"como_ler", "visual": [{"id", "titulo", "itens"}] (cores, letras, controles, componentes,
 *                            larguras e comportamento, com os valores exatos), "menu": {"como", "itens": [{"pagina", "rotulo"}]},
 *                            "paginas": [{"id", "pagina", "titulo", "endereco", "le", "grava", "partes": [{"titulo", "itens"}], "codigo"}] (cada
 *                            página de cima para baixo: as tabelas, os campos, os botões e as mensagens, com os textos da tela),
 *                            "capturas": [{"arquivo", "mostra"}]} (a especificação visual: a mesma interface, não uma parecida),
 *                            "codigo": {a lista do código, a mesma do recurso=codigo, sem o conteúdo},
 *                            "modelo_de_dados": {"banco", "como_ler", "tabelas": [cada tabela com as colunas, as chaves, os índices e as
 *                            regras, lidas do próprio banco]}, "listas": {as funções das fórmulas, os tipos de campo, os formatos de
 *                            lançamento, as repetições, os dias da semana, as âncoras, os canais, as migrações e as constantes do config.php}}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=reconstrucao"
 *   recurso=codigo           (ou recurso=ajuda&parte=codigo) o código do sistema como está agora, lido do disco na hora do pedido, para
 *                            a reescrita ler o original: {"como_usar", "camadas": [{"camada", "o_que_e"}], "arquivos": [{"arquivo",
 *                            "camada", "o_que_e", "linguagem", "bytes", "linhas", "sha256", "alterado_em", "ver", "cru"; nas páginas
 *                            também "usa" (os arquivos que ela carrega) e "html_cru"}], "nao_mostrados": [{"arquivo", "por_que",
 *                            "o_que_ver"}] (o que fica de fora de propósito: o config.php, a chave do Google, o banco, os documentos,
 *                            as senhas, e o que ver no lugar)}. Parâmetros: arquivo=<nome> (um arquivo, com o conteúdo como está em
 *                            "conteudo", ou a imagem em "conteudo_base64"; numa página, também "html": o HTML que o navegador recebe,
 *                            já montado); cru=1 com arquivo= (o arquivo puro, sem JSON: texto como text/plain, imagem como imagem);
 *                            html=1 com cru=1 (o HTML de uma página, puro); conteudo=1 sem arquivo (todos, com o conteúdo; com
 *                            f[arquivos][camada]=tela, só as telas). Só saem os arquivos do sistema (os do repositório); outro: 404.
 *                            Responde mesmo com o banco vazio ou desatualizado
 *                            Ex.: no navegador, http://servidor/relojoeiro/api.php?recurso=codigo&arquivo=estilo.css&cru=1
 *                            Ex.: curl -u lucas:senha -g "http://servidor/relojoeiro/api.php?recurso=codigo&conteudo=1&f[arquivos][camada]=tela"
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
 *                             alterar (id: quando ou inicio, fim, valor; só o que vier), excluir (id), alternar (relogio_id, tipo
 *                             (sessão), quando: fecha a sessão se ela está aberta, abre se está fechada: um botão só num atalho de
 *                             celular). Em todas, relogio_id=hoje é o relógio do dia, pelo plano. Nada no futuro; o tipo tem de
 *                             valer para o relógio; exclusivas não se sobrepõem. Um intervalo entre duas leituras com outra sessão
 *                             no meio (o carregador) não mede o gasto
 *                            Ex. alternar: curl -H "X-Api-Token: ..." -d recurso=lancamento -d acao=alternar -d relogio_id=hoje -d tipo=pulso http://servidor/relojoeiro/api.php
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
 *                             ou sempre (qualquer data); se vai pela agenda ou pelo Telegram é a Configuração), na_hora (1 ou 0:
 *                             além do alerta da manhã, o Telegram recebe o aviso no minuto em que ele vence, uma vez, até ser
 *                             resolvido; o "Tirar do carregador" vem assim), alterar (id), excluir (id)
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
 *                             seguinte inteira), periodo (de, ate: AAAA-MM-DD, de hoje até 2 anos; relogio_ids[]: um ou vários, na
 *                             ordem; dias_cada (padrão 1): os dias do período ficam escolhidos à mão, cada relógio dias_cada dias
 *                             seguidos, e depois do último volta ao primeiro), periodo_sortear (de, ate: tudo o que o período tem,
 *                             de amanhã em diante, à mão, sorteado ou livre, sai e ele é sorteado de novo), periodo_livre (de, ate:
 *                             tudo sai e os dias ficam livres: sem relógio e sem sorteio, para escolher à mão; trocar_dia ou periodo
 *                             num dia livre o tira de lá), manual (o Rodízio manual: de, ate; dias[AAAA-MM-DD]: o id do relógio,
 *                             auto (Decida por mim: o sistema decide ao gravar, pelo critério do recurso=rodizio_manual) ou livre
 *                             (hoje não); o dia que não vem fica com a escolha de partida (dias[].escolha); tudo fica gravado à mão,
 *                             com o motivo; hoje, com outro relógio, ele passa a ser o do pulso a partir de agora; hoje em auto
 *                             continua o que está; fora do período, vale o modo ativo), apagar_tudo (toda a programação de
 *                             amanhã em diante, escolhida à mão, sorteada e livre, sai; hoje=1, "Excluindo hoje": de hoje em
 *                             diante; o modo ativo monta o plano de novo; hoje, se mudar, o novo vai para o pulso a partir de agora). Os dias escolhidos à mão e os livres, de
 *                             amanhã em diante, ficam quando se sorteia de novo ou se monta a próxima semana.
 *                             Na escala inteligente: sortear de novo refaz a escala; usando segura o escolhido nos dias que
 *                             faltavam do bloco de hoje e refaz a escala depois deles; proxima_semana é recusada
 *                            Ex. modo: curl -u lucas:senha -d recurso=rodizio -d acao=modo -d modo=2 -d "bloco_alvo[8]=1" -d "bloco_cada_dia[8]=1" -d selecao=ponderado -d max_sem_uso=21 http://servidor/relojoeiro/api.php
 *                            Ex. resortear: curl -u lucas:senha -d recurso=rodizio -d acao=resortear http://servidor/relojoeiro/api.php
 *                            Ex. resortear_hoje: curl -u lucas:senha -d recurso=rodizio -d acao=resortear_hoje http://servidor/relojoeiro/api.php
 *                            Ex. usando: curl -u lucas:senha -d recurso=rodizio -d acao=usando -d relogio_id=12 http://servidor/relojoeiro/api.php
 *                            Ex. trocar_dia: curl -u lucas:senha -d recurso=rodizio -d acao=trocar_dia -d data=2026-10-09 -d relogio_id=12 http://servidor/relojoeiro/api.php
 *                            Ex. proxima_semana: curl -u lucas:senha -d recurso=rodizio -d acao=proxima_semana http://servidor/relojoeiro/api.php
 *                            Ex. periodo: curl -u lucas:senha -d recurso=rodizio -d acao=periodo -d de=2026-12-20 -d ate=2027-01-05 -d "relogio_ids[]=12" -d "relogio_ids[]=7" -d dias_cada=2 http://servidor/relojoeiro/api.php
 *                            Ex. periodo_sortear: curl -u lucas:senha -d recurso=rodizio -d acao=periodo_sortear -d de=2026-12-20 -d ate=2027-01-05 http://servidor/relojoeiro/api.php
 *                            Ex. periodo_livre: curl -u lucas:senha -d recurso=rodizio -d acao=periodo_livre -d de=2026-12-20 -d ate=2027-01-05 http://servidor/relojoeiro/api.php
 *                            Ex. apagar_tudo: curl -u lucas:senha -d recurso=rodizio -d acao=apagar_tudo -d hoje=1 http://servidor/relojoeiro/api.php
 *                            Ex. manual: curl -u lucas:senha -d recurso=rodizio -d acao=manual -d de=2026-12-20 -d ate=2027-01-05 -d "dias[2026-12-20]=12" -d "dias[2026-12-21]=auto" -d "dias[2026-12-25]=livre" http://servidor/relojoeiro/api.php
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
 *                             lista; flutuante: numa janela grande por cima da página); destaque_hoje (dia: o destaque no alto da
 *                             página Hoje é o relógio do dia; pulso: o relógio no pulso agora); proximos_por_pagina e plano_por_pagina (1 a
 *                             366: o "Mostrar até", quantos dias os Próximos dias da página Hoje e o dia a dia da página Plano
 *                             mostram por página; padrão 7 e 31);
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
 *   recurso=backup            fazer (agora: a cópia vai para a BACKUP_PASTA, e as mais velhas que passam do BACKUP_MANTER saem; a
 *                             resposta traz "arquivo"), restaurar (arquivo: o nome de uma cópia da pasta; antes, guarda uma cópia do
 *                             que está agora; depois, num passo só, troca todos os registros pelos da cópia; só uma cópia da mesma
 *                             versão do banco; sem o conteúdo dos documentos, eles voltam sem a cópia no banco e o cron refaz a pedida)
 *                            Ex. fazer: curl -u lucas:senha -d recurso=backup -d acao=fazer http://servidor/relojoeiro/api.php
 *                            Ex. restaurar: curl -u lucas:senha -d recurso=backup -d acao=restaurar -d arquivo=relojoeiro-2026-10-12-200000.jsonl.gz http://servidor/relojoeiro/api.php
 *
 * ---------------------------------------------------------------------------------------------
 * DICIONÁRIO DOS CAMPOS  (o que é, para que serve e que valor tem cada campo; o mesmo está em recurso=ajuda, "campos")
 *   Gerado do "campos" da ajuda por testes/dicionario.php: não escreva aqui. Escreva lá e rode php testes/dicionario.php --gravar
 * ---------------------------------------------------------------------------------------------
 *   [] no caminho: uma lista (o campo é de cada item); <...>: uma chave que varia (o identificador de um campo, um estado, uma função...).
 *   Datas e horas no fuso do sistema (o FUSO do config.php; sem ele, o do PHP, date.timezone); null: sem valor. Números de tempo em
 *   segundos são inteiros.
 *
 *   api.php (sem recurso)
 *     agenda.criados[]               os eventos que o sistema criou no Google Agenda
 *     agenda.criados[].assinatura    a marca do título e da descrição com que foi criado (mudou: o evento é atualizado na próxima
 *                                    sincronização)
 *     agenda.criados[].chave         a identificação do evento (a mesma dos desejados)
 *     agenda.criados[].criado        quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     agenda.criados[].data          o dia do evento (texto AAAA-MM-DD)
 *     agenda.criados[].google_id     o id do evento no Google
 *     agenda.criados[].titulo        o título com que foi criado
 *     agenda.desejados[]             o que tem de estar no Google Agenda agora: os avisos marcados para a agenda, o relógio do dia, a
 *                                    véspera e os eventos
 *     agenda.desejados[].chave       a identificação do evento na agenda (a mesma enquanto nada mudar; é o que o sistema usa para atualizar
 *                                    ou remover)
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
 *     avisos[].na_hora               1: o aviso vai pelo Telegram no minuto em que vence (uma vez, até ser resolvido); 0: só no alerta da
 *                                    manhã
 *     avisos[].antecedencia_dias     com quantos dias antes ele entra "em breve" (número)
 *     avisos[].ativo                 o aviso vale
 *     avisos[].condicao              vale quando: uma fórmula que diz para quais relógios do grupo o aviso vale (1 vale, 0 não; ex.:
 *                                    corda_manual = 0, só o automático sem corda); null: vale para todos
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
 *     config.carga_limiar            o limite de carga geral (%): carregar quando a carga chega a ele (o campo carga_minima do relógio, se
 *                                    preenchido, vale no lugar)
 *     config.cron_erro               o erro guardado da última execução com erro (texto; vazio: sem erro)
 *     config.cron_registro           o registro da última rodada com atividade (texto)
 *     config.cron_ultima_execucao    quando o cron rodou pela última vez (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     config.na_hora_enviados        os avisos "na hora" que já foram pelo Telegram e ainda estão vencidos (relogio_id:identificador,
 *                                    separados por vírgula): o cron não manda de novo
 *     config.backup_ultima           quando foi feita a última cópia de segurança (texto AAAA-MM-DD HH:MM:SS)
 *     config.escala_fim              até que dia vai a escala inteligente gerada (texto AAAA-MM-DD; vazio fora da escala)
 *     config.escala_gerada           o dia em que a escala foi gerada pela última vez (texto AAAA-MM-DD)
 *     config.horario_manha           a hora da rodada da manhã do cron: o relógio do dia e os avisos (HH:MM)
 *     config.horario_noite           a hora da rodada da noite: preparar o relógio de amanhã (HH:MM)
 *     config.max_sem_uso             a garantia de rodízio: nenhum relógio passa desses dias sem uso (0 desliga)
 *     config.medicao_janela_dias     a média do gasto medido pelas leituras usa as medições destes últimos dias (sem nenhuma na janela, a
 *                                    última)
 *     config.encostado_dias          o aviso Encostado: depois de quantos dias sem ir ao pulso um relógio da coleção é avisado (1 a 3650;
 *                                    padrão 30)
 *     config.idioma                  o idioma padrão da instalação (pt-BR, en, zh-CN, hi, es, fr; vazio: o do navegador, senão português)
 *     config.mensagens_ativas        1: o Telegram (a API de alerta) envia; 0: não envia
 *     config.painel_modo             como o relógio abre na página Hoje: lado (no painel à direita da lista) ou flutuante (numa janela
 *                                    grande por cima da página); vazio: lado
 *     config.destaque_hoje           o destaque no alto da página Hoje: dia (o relógio do dia, o do plano) ou pulso (o relógio no pulso
 *                                    agora; sem nenhum no pulso, o do dia); vazio: dia
 *     config.proximos_por_pagina     o "Mostrar até" dos Próximos dias da página Hoje: quantos dias por página (1 a 366; vazio: 7)
 *     config.plano_por_pagina        o "Mostrar até" do dia a dia da página Plano: quantos dias por página (1 a 366; vazio: 31)
 *     config.aleatorio_ultima        a fonte do último lote de números aleatórios e quando (o sistema grava; só leitura)
 *     config.aleatorio_fora          quando os dois serviços de números aleatórios falharam (segundos desde 1970; vazio: no ar); o sistema
 *                                    grava; só leitura
 *     config.migracao_v10            marca de que a migração v10 foi aplicada (1)
 *     config.migracao_v12            marca de que a migração v12 foi aplicada (1)
 *     config.migracao_v16            marca de que a migração v16 foi aplicada (1)
 *     config.migracao_v17            marca de que a migração v17 foi aplicada (1)
 *     config.migracao_v18            marca de que a migração v18 foi aplicada (1)
 *     config.migracao_v19            marca de que a migração v19 foi aplicada (1)
 *     config.migracao_v20            marca de que a migração v20 foi aplicada (1)
 *     config.pulso_auto_fim          1 (ou vazio): o relógio sai do pulso sozinho no fim do horário de uso (o "fecha às" do tipo No pulso);
 *                                    0: só pelo Tirou
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
 *     criterios.modos[].selecao      a forma de escolha: inteligente (a maior nota), ponderado (sorteio pela nota), aleatorio ou fifo (o
 *                                    mais tempo sem uso)
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
 *                                    o peso no conjunto: peso do subparâmetro × peso do parâmetro, reescalado sem os que não se aplicam, em
 *                                    % (número)
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
 *                                    o limite da medida (a última faixa vai até ele ou sem limite): o que o nome diz em "(0 a N)"; null:
 *                                    sem limite
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
 *     lancamento_tipos[].condicao    vale quando: uma fórmula que diz para quais relógios do grupo o tipo vale (1 vale, 0 não; ex.:
 *                                    corda_manual, só quem aceita corda); null: vale para todos
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
 *     modos[].ciclo                  (verdadeiro ou falso) o modo usa o ciclo: um relógio só volta depois que todos os disponíveis do bloco
 *                                    passaram na semana (na escala, pelo período dela); dentro do ciclo, a forma de escolha decide a ordem
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
 *     plano[].motivo                 por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à
 *                                    mão); null: o dia foi gravado antes de o sistema guardar o motivo
 *     plano[].origem                 sorteio (pelo modo) ou manual ("Usando hoje")
 *     plano[].relogio                o nome dele
 *     plano[].relogio_id             o relógio do dia
 *     plano_livre[]                  os dias livres do plano (texto AAAA-MM-DD): sem relógio e sem sorteio, para escolher à mão (o "Deixar
 *                                    o período livre")
 *     acessorios[]                   os acessórios (como em recurso=acessorios): id, nome, tipo, tipo_texto, relogio_id (null: solto),
 *                                    relogio, observacao, criado
 *     acessorios[].id                o número do acessório
 *     acessorios[].nome              o nome
 *     acessorios[].tipo              pulseira, caixa, estojo, ferramenta, carregador, peca ou outro
 *     acessorios[].tipo_texto        o tipo por extenso
 *     acessorios[].relogio_id        o relógio de que ele é; null: solto
 *     acessorios[].relogio           o nome desse relógio; null: solto
 *     acessorios[].observacao        a observação (null)
 *     acessorios[].criado            quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[]                     os relógios, com tudo o que se sabe de cada um
 *     relogios[].acaba_em_datacomtz  quando acaba, em ISO 8601 com o fuso (texto, ex.: 2026-10-02T18:40:00-03:00); null com o motivo
 *     relogios[].acaba_em_segundos   quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em
 *                                    segundos; igual a autonomia_estimada); null com o motivo
 *     relogios[].acaba_em_unixtimestamp
 *                                    quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde
 *                                    01/01/1970 UTC)); null com o motivo
 *     relogios[].autonomia_atual     quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no
 *                                    smartwatch, pelo gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo
 *                                    informado; nos outros, igual à prevista; a diferença para a prevista mostra a bateria envelhecendo; a
 *                                    fórmula autonomia_atual
 *     relogios[].autonomia_estimada  quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em
 *                                    que está no rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em
 *                                    motivos
 *     relogios[].autonomia_prevista  quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do
 *                                    smartwatch, a reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula
 *                                    autonomia_prevista; null se falta o dado no cadastro
 *     relogios[].avisos[]            os avisos do relógio agora (os que valem para ele e têm data prevista), do mais urgente ao mais
 *                                    distante
 *     relogios[].avisos[].agenda     a data na agenda: janela (só dentro da antecedência da agenda) ou sempre (qualquer data); se vai pela
 *                                    agenda é a Configuração
 *     relogios[].avisos[].na_hora    verdadeiro: o aviso vai pelo Telegram no minuto em que vence (uma vez), além do alerta da manhã
 *     relogios[].avisos[].data       a data prevista, pela fórmula do aviso (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].avisos[].escala     na escala inteligente: nao, uso (conferido no relógio do dia) ou sempre (também nos guardados)
 *     relogios[].avisos[].estado     atrasado (a data passou), em_breve (dentro da antecedência) ou ok
 *     relogios[].avisos[].falta_dias quanto falta para a data prevista, em dias (número; negativo: já passou)
 *     relogios[].avisos[].identificador
 *                                    o aviso (corda, carregar, sol, pilha, revisao, garantia, carga_baixa, leitura, ou um que você
 *                                    cadastrou)
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
 *                                    o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde
 *                                    01/01/1970 UTC)); segundos que faltam agora = acaba_em_unixtimestamp − hora atual
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
 *     relogios[].posse               a situação na coleção: colecao (na coleção), desejado (a lista de desejos), emprestado, vendido ou
 *                                    arquivado; fora da coleção, disponivel é sempre falso
 *     relogios[].em_uso              verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora
 *     relogios[].energia             a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para
 *                                    calcular
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
 *     relogios[].lancamentos[].fim   o fim da sessão (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null: marcação, leitura, ou sessão
 *                                    aberta
 *     relogios[].lancamentos[].id    o número do lançamento
 *     relogios[].lancamentos[].inicio
 *                                    quando (a marcação ou a leitura), ou o começo da sessão (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    sistema)
 *     relogios[].lancamentos[].origem
 *                                    manual (lançado por alguém), rodizio (a sessão do dia, pelo cron) ou importado
 *     relogios[].lancamentos[].tipo  o identificador do tipo
 *     relogios[].lancamentos[].valor o valor da leitura (número); null nos outros
 *     relogios[].linha_do_tempo[]    os trechos da linha do tempo do relógio, do mais recente para o mais antigo: cada trecho é um período
 *                                    contínuo num estado, ou uma marcação (um lançamento instantâneo)
 *     relogios[].linha_do_tempo[].duracao
 *                                    a mesma duração por extenso ("2d 3h", "40min"); null numa marcação
 *     relogios[].linha_do_tempo[].duracao_seg
 *                                    quanto o trecho durou, número inteiro, em segundos; null numa marcação
 *     relogios[].linha_do_tempo[].em_andamento
 *                                    verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)
 *     relogios[].linha_do_tempo[].estado
 *                                    o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador
 *                                    de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)
 *     relogios[].linha_do_tempo[].fim
 *                                    quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação
 *     relogios[].linha_do_tempo[].inicio
 *                                    quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogios[].linha_do_tempo[].texto
 *                                    o trecho por extenso, como a tela mostra ("em uso", "no sol", "Leitura de carga 80%")
 *     relogios[].linha_do_tempo[].tipo
 *                                    o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no
 *                                    repouso
 *     relogios[].lugar               o grupo por extenso
 *     relogios[].medicoes[]          as medições do gasto pelas leituras (cada leitura de um tipo que mede o gasto, comparada com a
 *                                    anterior), da mais recente para a mais antiga
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
 *     relogios[].medicoes[].medida   o gasto do intervalo sozinho, no histórico: uso (o gasto por dia de uso, quando o intervalo teve meio
 *                                    dia de uso ou mais, ou mais dias de uso que fora) ou repouso (o gasto por dia fora do pulso); o gasto
 *                                    que vale sai de todas as medições juntas
 *     relogios[].medicoes[].na_media verdadeiro ou falso: entra na média agora (usada e dentro da janela da Configuração,
 *                                    medicao_janela_dias)
 *     relogios[].medicoes[].peso_horas
 *                                    o peso da medição na média: as horas que ela cobriu, no pulso (uso) ou guardado (repouso) (número)
 *     relogios[].medicoes[].taxa     o gasto medido, em % por dia de uso (uso) ou por dia guardado (repouso) (número)
 *     relogios[].medicoes[].usada    verdadeiro ou falso: a caixa "Atualizar o gasto com esta medição" estava marcada (entra na média);
 *                                    falso: só histórico
 *     relogios[].motivos             por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)
 *     relogios[].motivos.<campo>     o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a
 *                                    zero em até 10 anos
 *     relogios[].no_id               o grupo do relógio; null: na raiz
 *     relogios[].nome                o nome do relógio
 *     relogios[].previsao            a previsão da energia (só nos relógios com leitura, como o smartwatch): as frases da tela e os mesmos
 *                                    números
 *     relogios[].previsao.aplica     verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)
 *     relogios[].previsao.carga_na_entrada
 *                                    com quanto ele entra nesse dia, em % (número)
 *     relogios[].previsao.carregar_antes
 *                                    verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)
 *     relogios[].previsao.chega_limite_em
 *                                    quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa
 *                                    de um ano
 *     relogios[].previsao.confianca  a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media
 *                                    ou baixa (leitura com mais de 7 dias)
 *     relogios[].previsao.conta[]    de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras
 *     relogios[].previsao.conta[].campo
 *                                    o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     relogios[].previsao.conta[].detalhe
 *                                    no gasto medido, como ele foi medido ("conta dos dois gastos juntos, com 3 medições dos últimos 90
 *                                    dias"); null nos campos
 *     relogios[].previsao.conta[].nome
 *                                    o nome do campo, ou do gasto medido (Gasto em uso medido, Gasto fora do pulso medido)
 *     relogios[].previsao.conta[].origem
 *                                    de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido
 *                                    (pelas leituras)
 *     relogios[].previsao.conta[].unidade
 *                                    a unidade do valor (texto)
 *     relogios[].previsao.conta[].valor
 *                                    o valor usado na conta (número; null se vazio)
 *     relogios[].previsao.dura_ate   até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)
 *     relogios[].previsao.dura_dias  quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)
 *     relogios[].previsao.energia    a energia agora, em % (número; null sem leitura)
 *     relogios[].previsao.gasto      os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as
 *                                    tem)
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
 *     relogios[].restante_em_uso     quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em
 *                                    segundos); null com o motivo (ex.: o automático no pulso se recarrega e não acaba)
 *     relogios[].restante_guardado   quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em
 *                                    segundos); null com o motivo
 *     relogios[].resumo_do_tempo.marcacoes.<tipo_de_marcacao>
 *                                    quantas marcações de cada tipo (corda, carga, pilha...) (número)
 *     relogios[].resumo_do_tempo.tempo.<estado>.porcentagem
 *                                    a parte desse estado no tempo total, em %
 *     relogios[].resumo_do_tempo.tempo.<estado>.segundos
 *                                    quanto tempo o relógio passou no estado <estado> (rodizio, pulso, winder, sol, repouso...), número
 *                                    inteiro, em segundos
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
 *                                    verdadeiro: a cópia de segurança do arquivo está completa no banco (se ele sumir da pasta, volta dali)
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
 *     relogios[].acaba_em_segundos   quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em
 *                                    segundos; igual a autonomia_estimada); null com o motivo
 *     relogios[].acaba_em_unixtimestamp
 *                                    quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde
 *                                    01/01/1970 UTC)); null com o motivo
 *     relogios[].autonomia_atual     quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no
 *                                    smartwatch, pelo gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo
 *                                    informado; nos outros, igual à prevista; a diferença para a prevista mostra a bateria envelhecendo; a
 *                                    fórmula autonomia_atual
 *     relogios[].autonomia_estimada  quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em
 *                                    que está no rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em
 *                                    motivos
 *     relogios[].autonomia_prevista  quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do
 *                                    smartwatch, a reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula
 *                                    autonomia_prevista; null se falta o dado no cadastro
 *     relogios[].calculado_em_unixtimestamp
 *                                    o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde
 *                                    01/01/1970 UTC)); segundos que faltam agora = acaba_em_unixtimestamp − hora atual
 *     relogios[].disponivel          verdadeiro ou falso: entra no rodízio
 *     relogios[].em_uso              verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora
 *     relogios[].energia             a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para
 *                                    calcular
 *     relogios[].id                  o número (código)
 *     relogios[].lugar               o grupo por extenso
 *     relogios[].motivos             por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)
 *     relogios[].motivos.<campo>     o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a
 *                                    zero em até 10 anos
 *     relogios[].nome                o nome
 *     relogios[].restante_em_uso     quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em
 *                                    segundos); null com o motivo (ex.: o automático no pulso se recarrega e não acaba)
 *     relogios[].restante_guardado   quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em
 *                                    segundos); null com o motivo
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
 *     avisos[].resolve.acao          o que o botão manda (recurso=lancamento): iniciar (a sessão está fechada), encerrar (a sessão está
 *                                    aberta: o Tirar do carregador) ou lancar (instantâneo ou leitura)
 *     avisos[].resolve.botao         o texto do botão ("Pôs no sol", "Tirou do carregador", "Informar leitura de carga", "Corda")
 *     avisos[].texto                 o motivo por extenso
 *     avisos_nomes[]                 os nomes dos avisos (o filtro "O que fazer" da tabela)
 *     fora_da_colecao                os relógios fora da coleção (desejados, vendidos, arquivados), fora da tabela: a página Desejos e o
 *                                    seletor da ficha
 *     fora_da_colecao[]              um relógio fora da coleção
 *     fora_da_colecao[].foto         a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     fora_da_colecao[].id           o número (código)
 *     fora_da_colecao[].nome         o nome
 *     fora_da_colecao[].posse        a situação: desejado, vendido ou arquivado
 *     fora_da_colecao[].posse_texto  a mesma por extenso ("Desejado", "Vendido", "Arquivado")
 *     fora_da_colecao[].tipo         o grupo do relógio (o nome; "—": na raiz)
 *     estados[]                      os estados da tabela, na ordem (o filtro Estado): "Em uso", o nome de cada sessão que não é o pulso
 *                                    ("No sol", "No winder", "No carregador"...), "Em repouso", "Indisponível" e "Emprestado"
 *     comecou                        verdadeiro ou falso: o dia já começou no pulso (há relógio do dia e: pondo no pulso sozinho, passou do
 *                                    uso_inicio; senão, ele já foi posto no pulso hoje)
 *     pulso_poe_sozinho              verdadeiro: o relógio do dia entra no pulso sozinho no início do horário de uso; falso: só pelo Pôs
 *     pulso_tira_sozinho             verdadeiro: o relógio sai do pulso sozinho no fim do horário de uso; falso: só pelo Tirou
 *     data                           hoje (texto AAAA-MM-DD)
 *     dia                            o relógio de hoje; null: nenhum
 *     dia.acao                       o lembrete do dia; null: nada
 *     dia.ate                        até quando ele fica ("só hoje", "até sexta, 02/10")
 *     dia.data                       hoje
 *     dia.motivo                     por que ele saiu hoje, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à mão); null:
 *                                    não foi guardado
 *     dia.relogio                    o nome dele
 *     dia.relogio_id                 o relógio de hoje
 *     destaque                       o relógio do destaque no alto da página (a Configuração destaque_hoje), já escolhido pela API
 *     destaque.do_dia                verdadeiro: o relógio do destaque é o relógio do dia
 *     destaque.e                     o que o relógio do destaque é: dia (o relógio do dia, no pulso ou não) ou pulso (outro relógio, no
 *                                    pulso agora; o do dia fica no dia)
 *     destaque.mostra                o que a Configuração pede: dia (o relógio do dia) ou pulso (o relógio no pulso agora)
 *     destaque.no_pulso              verdadeiro: o relógio do destaque está no pulso agora (havendo mais de um no pulso, conta o que foi
 *                                    por último)
 *     destaque.relogio_id            o relógio do destaque; null: nenhum (sem relógio do dia e ninguém no pulso). No pulso: o que está no
 *                                    pulso agora (havendo mais de um, o que foi ao pulso por último); ninguém no pulso, o relógio do dia
 *     destaque.texto                 numa frase, por que é ele: O relógio do dia. / O relógio do dia, no pulso agora. / No pulso agora; o
 *                                    relógio do dia é outro. / No pulso agora; hoje não tem relógio do dia. / Nenhum relógio no pulso
 *                                    agora: aqui, o relógio do dia. / Nenhum relógio para hoje e nenhum no pulso.
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
 *     modos[].ciclo                  (verdadeiro ou falso) o modo usa o ciclo: um relógio só volta depois que todos os disponíveis do bloco
 *                                    passaram na semana (na escala, pelo período dela); dentro do ciclo, a forma de escolha decide a ordem
 *     modos[].escala_dias            o horizonte da escala; null: sorteio
 *     modos[].id                     o número
 *     modos[].nome                   o nome
 *     modos[].selecao                a forma de escolha
 *     plano[]                        os próximos 62 dias do plano
 *     plano[].acao                   o lembrete do dia; null
 *     plano[].motivo                 por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à
 *                                    mão); null: o dia foi gravado antes de o sistema guardar o motivo
 *     plano[].origem                 sorteio (pelo modo) ou manual (escolhido à mão: "trocar por…" ou "Usando hoje")
 *     plano[].data                   o dia
 *     plano[].relogio                o nome
 *     plano[].relogio_id             o relógio
 *     livres[]                       os dias livres dos próximos 62 dias (texto AAAA-MM-DD): sem relógio e sem sorteio, para escolher à
 *                                    mão; a tela os põe entre os dias do plano
 *     hoje_livre                     verdadeiro: hoje é um dia livre (o Sem relógio hoje, ou um período livre): sem relógio do dia; a tela
 *                                    mostra "Hoje sem relógio" com o escolher…
 *     proximos                       os Próximos dias da página, uma página por vez (proximos_pagina=N; por_pagina=N, sem ele o "Mostrar
 *                                    até" da Configuração, proximos_por_pagina, padrão 7), de hoje até o fim do plano, com os dias livres
 *                                    no meio; a troca de página lê só eles (recurso=proximos)
 *     proximos.por_pagina            quantos dias vêm por página (o "Mostrar até" da Configuração, ou o por_pagina pedido)
 *     proximos.pagina                a página que veio (1: a primeira)
 *     proximos.paginas               quantas páginas há
 *     proximos.total                 quantos dias há, somando todas as páginas (com os dias livres)
 *     proximos.relogio               o filtro de relógio (o id; null: todos)
 *     proximos.dias[]                os dias desta página, na ordem das datas, com os dias livres no meio
 *     proximos.dias[].data           o dia (AAAA-MM-DD)
 *     proximos.dias[].dia_semana     o dia da semana: 1 segunda ... 7 domingo
 *     proximos.dias[].dia_semana_curto
 *                                    o dia da semana curto: seg, ter, qua, qui, sex, sáb, dom
 *     proximos.dias[].hoje           verdadeiro no dia de hoje
 *     proximos.dias[].relogio_id     o relógio do dia (null num dia livre)
 *     proximos.dias[].relogio        o nome dele (null num dia livre)
 *     proximos.dias[].origem         sorteio, manual (escolhido à mão) ou livre (dia livre: sem relógio e sem sorteio, para escolher à mão)
 *     proximos.dias[].acao           o lembrete do dia (o que fazer antes: carregar, dar corda...); null: nenhum
 *     proximos.dias[].motivo         por que o relógio está no dia; null: nenhum
 *     proxima_semana.domingo         o domingo dela
 *     proxima_semana.ja_montada      verdadeiro ou falso: a próxima semana já está no plano
 *     proxima_semana.segunda         a segunda-feira da próxima semana (texto AAAA-MM-DD)
 *     relogios[]                     a tabela dos relógios
 *     relogios[].agora               o estado por extenso, desde quando ("Em uso desde 07:00", "No sol desde 09:12", "Em repouso desde
 *                                    21:40")
 *     relogios[].carga               a energia agora, em % inteiro; null: indisponível ou sem dados
 *     relogios[].carga_de            de onde vem a energia ("bateria do smartwatch") ou por que não há
 *     relogios[].com_aviso           verdadeiro ou falso: tem aviso hoje
 *     relogios[].compra.data         a data da compra (texto AAAA-MM-DD; null)
 *     relogios[].compra.garantia_ate até quando vai a garantia (texto AAAA-MM-DD; null)
 *     relogios[].compra.loja         onde comprou (null)
 *     relogios[].compra.valor        o valor pago (número; null)
 *     relogios[].de_hoje             verdadeiro ou falso: é o relógio de hoje
 *     relogios[].destaque            verdadeiro ou falso: é o relógio do destaque no alto da página (o mesmo de destaque.relogio_id)
 *     relogios[].disponivel          verdadeiro ou falso: entra no rodízio
 *     relogios[].posse               a situação na coleção: colecao ou emprestado (os desejados, vendidos e arquivados ficam em
 *                                    fora_da_colecao)
 *     relogios[].posse_texto         a mesma por extenso: "Na coleção" ou "Emprestado"
 *     relogios[].em_uso              verdadeiro ou falso: no pulso agora
 *     relogios[].estado              o estado na tabela: uso (no pulso), sessao (numa sessão que não é o pulso: no sol, no winder, no
 *                                    carregador...), repouso ou indisponivel (o indisponível parado; no pulso ou numa sessão, vale o que
 *                                    ele está fazendo)
 *     relogios[].estado_texto        o estado como a tabela mostra: "Em uso", o nome da sessão aberta ("No sol", "No carregador"; duas
 *                                    juntas: "No sol · No winder"), "Em repouso", "Indisponível" ou "Emprestado" (o emprestado parado)
 *     relogios[].estado_ordem        a ordem do estado na tabela: 0 em uso, 1 numa sessão, 2 em repouso, 3 indisponível
 *     relogios[].foto                a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC), para o endereço
 *                                    dela); null: sem foto
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
 *     relogios[].situacao[]          as frases de situação: as sessões abertas que o estado não mostra e a autonomia restante ("Autonomia
 *                                    restante 1d 23min")
 *     relogios[].tipo                o grupo do relógio (o nome)
 *     relogios[].ultimo              a última vez no pulso (número inteiro: instante Unix (segundos desde 01/01/1970 UTC); 0: nunca)
 *     relogios[].ultimo_txt          a mesma por extenso ("agora · 14:32", "25/09 21:53", "nunca")
 *     totais                         os totais da coleção inteira (a tabela da página Hoje soma só os relógios que os filtros dela deixam à
 *                                    mostra)
 *     totais.disponiveis             quantos estão disponíveis para o rodízio
 *     totais.desejados               quantos relógios estão na lista de desejos
 *     totais.em_uso                  quantos estão em uso agora
 *     totais.fora                    quantos estão fora da coleção (desejados, vendidos e arquivados: os de fora_da_colecao)
 *     totais.relogios                quantos relógios há
 *     totais.valor                   a soma do valor pago (valor_compra) de todos, em R$ (os sem valor contam 0)
 *     uso_inicio                     a hora em que o relógio do dia vai para o pulso (HH:MM)
 *   recurso=ficha
 *     campos[]                       os campos do cadastro
 *     campos[].identificador         o campo
 *     campos[].no_id                 o grupo em que vale (0: todos)
 *     campos[].nome                  o nome
 *     campos[].opcoes[]              as opções (lista)
 *     campos[].padrao                o valor padrão
 *     campos[].so_desejado           verdadeiro: o campo vale só nos relógios desejados (o preço-alvo, a prioridade); no cadastro, aparece
 *                                    só com a situação "Desejado"
 *     campos[].tipo                  o tipo
 *     campos[].unidade               a unidade
 *     campos[].valor                 o valor do relógio (null)
 *     grupos[]                       os grupos (o seletor do cadastro)
 *     posses[]                       as situações na coleção, na ordem (o menu "Situação na coleção" do cadastro)
 *     posses[].nome                  o nome ("Na coleção", "Desejado", "Emprestado", "Vendido", "Arquivado")
 *     posses[].valor                 o valor que vai na gravação (posse): colecao, desejado, emprestado, vendido ou arquivado
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
 *     relogio.agora                  o estado por extenso, desde quando ("Em uso desde 07:00", "No sol desde 09:12", "Em repouso desde
 *                                    21:40")
 *     relogio.autonomia              as autonomias do relógio (as mesmas do recurso autonomia)
 *     relogio.autonomia.acaba_em_datacomtz
 *                                    quando acaba, em ISO 8601 com o fuso (texto, ex.: 2026-10-02T18:40:00-03:00); null com o motivo
 *     relogio.autonomia.acaba_em_segundos
 *                                    quantos segundos faltam para acabar, a partir de calculado_em_unixtimestamp (número inteiro, em
 *                                    segundos; igual a autonomia_estimada); null com o motivo
 *     relogio.autonomia.acaba_em_unixtimestamp
 *                                    quando a energia chega a zero seguindo o plano (número inteiro: instante Unix (segundos desde
 *                                    01/01/1970 UTC)); null com o motivo
 *     relogio.autonomia.autonomia_atual
 *                                    quanto o relógio dura com a carga cheia, pela conta do sistema (número inteiro, em segundos): no
 *                                    smartwatch, pelo gasto em uso medido pelas leituras (a média da janela da Configuração), senão pelo
 *                                    informado; nos outros, igual à prevista; a diferença para a prevista mostra a bateria envelhecendo; a
 *                                    fórmula autonomia_atual
 *     relogio.autonomia.autonomia_estimada
 *                                    quanto ainda dura a partir de agora, seguindo o plano (número inteiro, em segundos): usado nos dias em
 *                                    que está no rodízio, guardado nos outros; o mesmo número de acaba_em_segundos; null com o motivo em
 *                                    motivos
 *     relogio.autonomia.autonomia_prevista
 *                                    quanto o relógio dura com a carga cheia, pelo cadastro (número inteiro, em segundos): a autonomia do
 *                                    smartwatch, a reserva de marcha do mecânico, a reserva do solar ou a vida da pilha; a fórmula
 *                                    autonomia_prevista; null se falta o dado no cadastro
 *     relogio.autonomia.calculado_em_unixtimestamp
 *                                    o instante em que as autonomias foram calculadas (número inteiro: instante Unix (segundos desde
 *                                    01/01/1970 UTC)); segundos que faltam agora = acaba_em_unixtimestamp − hora atual
 *     relogio.autonomia.em_uso       verdadeiro ou falso: o relógio está com uma sessão no pulso aberta agora
 *     relogio.autonomia.energia      a energia agora, em % inteiro (a fórmula energia: bateria, reserva, luz ou pilha); null sem dados para
 *                                    calcular
 *     relogio.autonomia.motivos      por que cada campo acima ficou null (objeto; vazio quando todos foram calculados)
 *     relogio.autonomia.motivos.<campo>
 *                                    o motivo de o campo <campo> estar null (texto), ex.: sem dados para calcular a energia; não chega a
 *                                    zero em até 10 anos
 *     relogio.autonomia.restante_em_uso
 *                                    quanto ainda dura a partir de agora se ficar no pulso sem tirar, o dia todo (número inteiro, em
 *                                    segundos); null com o motivo (ex.: o automático no pulso se recarrega e não acaba)
 *     relogio.autonomia.restante_guardado
 *                                    quanto ainda dura a partir de agora se ficar parado depois do que já está lançado (número inteiro, em
 *                                    segundos); null com o motivo
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
 *     relogio.compra.ha_dias         há quantos dias foi a compra (número inteiro); null: sem a data
 *     relogio.compra.dias_de_uso     em quantos dias ele foi ao pulso (a fórmula dias_de_uso); null: sem a fórmula
 *     relogio.compra.custo_por_uso   o valor pago dividido pelos dias de uso, em R$ (a fórmula custo_por_uso); null: sem o valor pago ou
 *                                    sem nenhum dia de uso
 *     relogio.dados[]                os campos do cadastro que valem para o relógio (os do grupo dele e dos de cima), com o valor que as
 *                                    contas usam
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
 *     relogio.posse                  a situação na coleção: colecao, desejado, emprestado, vendido ou arquivado (fora da coleção,
 *                                    disponivel é sempre falso)
 *     relogio.posse_texto            a mesma por extenso ("Na coleção", "Desejado"...)
 *     relogio.da_colecao             verdadeiro: é da coleção agora (na coleção ou emprestado); falso: desejado, vendido ou arquivado
 *     relogio.acessorios[]           os acessórios dele (o quadro Acessórios), pelo tipo e pelo nome
 *     relogio.acessorios[].id        o número do acessório
 *     relogio.acessorios[].nome      o nome
 *     relogio.acessorios[].tipo      pulseira, caixa, estojo, ferramenta, carregador, peca ou outro
 *     relogio.acessorios[].tipo_texto
 *                                    o tipo por extenso
 *     relogio.acessorios[].relogio_id
 *                                    este relógio
 *     relogio.acessorios[].relogio   o nome dele
 *     relogio.acessorios[].observacao
 *                                    a observação (null)
 *     relogio.acessorios[].criado    quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.desejo                 o que a lista de desejos mostra (o quadro Desejo do painel): só no desejado; null nos outros
 *     relogio.desejo.link            o link da página do relógio (o campo link; null)
 *     relogio.desejo.marca           a marca (o campo marca; null)
 *     relogio.desejo.preco_alvo      até quanto pagar, em R$ (o campo preco_alvo; número; null)
 *     relogio.desejo.prioridade      a prioridade (o campo prioridade_desejo: Alta, Média, Baixa...; null)
 *     relogio.desejo.prioridade_ordem
 *                                    a ordem da prioridade nas opções do campo (1 é a mais alta; sem prioridade: depois de todas)
 *     relogio.desejo.referencia      a referência, o modelo (o campo referencia; null)
 *     relogio.em_uso                 verdadeiro ou falso: no pulso agora
 *     relogio.estado                 o estado agora: uso (no pulso), sessao (numa sessão que não é o pulso: no sol, no winder, no
 *                                    carregador...) ou repouso
 *     relogio.estado_texto           o estado como a tela mostra: "Em uso", o nome da sessão aberta ("No sol", "No carregador"; duas
 *                                    juntas: "No sol · No winder") ou "Em repouso"
 *     relogio.escala_fim             até que dia vai a escala (null fora da escala)
 *     relogio.foto                   a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     relogio.dica_foto              a dica da foto no painel (aparece com o mouse parado em cima dela), em linhas: o nome, o grupo e o
 *                                    código; o estado e a carga; a nota e o próximo dia no rodízio (ou indisponível); a compra (data e
 *                                    valor); e a frase: Duplo clique (ou "Abrir foto", embaixo): a foto em tamanho grande.
 *     relogio.id                     o número (código)
 *     relogio.leituras[]             as 40 últimas leituras com valor (o gráfico)
 *     relogio.leituras[].em_uso      verdadeiro ou falso: a leitura foi feita com o relógio no pulso
 *     relogio.leituras[].inicio      quando
 *     relogio.lancamentos_recentes[] os lançamentos dos últimos 14 dias e a sessão ainda aberta, do mais recente ao mais antigo (até 40): o
 *                                    que o quadro "Corrigir marcações" do painel mostra
 *     relogio.lancamentos_recentes[].fim
 *                                    quando a sessão terminou (texto AAAA-MM-DD HH:MM:SS); null: ainda aberta, ou um lançamento instantâneo
 *     relogio.lancamentos_recentes[].formato
 *                                    instantaneo, valor ou sessao
 *     relogio.lancamentos_recentes[].id
 *                                    o número do lançamento (para recurso=lancamento, acao=alterar ou excluir)
 *     relogio.lancamentos_recentes[].inicio
 *                                    quando foi (a sessão: quando começou), texto AAAA-MM-DD HH:MM:SS
 *     relogio.lancamentos_recentes[].nome
 *                                    o nome do tipo de lançamento
 *     relogio.lancamentos_recentes[].origem
 *                                    manual, rodizio (a sessão do dia) ou importado
 *     relogio.lancamentos_recentes[].tipo
 *                                    o identificador do tipo de lançamento (pulso, carga, corda...)
 *     relogio.lancamentos_recentes[].unidade
 *                                    a unidade do valor (texto; vazio sem valor)
 *     relogio.lancamentos_recentes[].valor
 *                                    o valor (número), num lançamento com valor; null nos outros
 *     relogio.leituras[].unidade     a unidade
 *     relogio.leituras[].valor       o valor
 *     relogio.linha_do_tempo[]       os trechos da linha do tempo do relógio, os 5 mais recentes: cada trecho é um período contínuo num
 *                                    estado, ou uma marcação (um lançamento instantâneo)
 *     relogio.linha_do_tempo[].duracao
 *                                    a mesma duração por extenso ("2d 3h", "40min"); null numa marcação
 *     relogio.linha_do_tempo[].duracao_seg
 *                                    quanto o trecho durou, número inteiro, em segundos; null numa marcação
 *     relogio.linha_do_tempo[].em_andamento
 *                                    verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)
 *     relogio.linha_do_tempo[].estado
 *                                    o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador
 *                                    de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)
 *     relogio.linha_do_tempo[].fim   quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação
 *     relogio.linha_do_tempo[].inicio
 *                                    quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.linha_do_tempo[].texto o trecho por extenso, como a tela mostra ("em uso", "no sol", "Leitura de carga 80%")
 *     relogio.linha_do_tempo[].tipo  o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no
 *                                    repouso
 *     relogio.manutencoes[]          as 5 próximas manutenções (avisos)
 *     relogio.manutencoes[].data     a data (texto AAAA-MM-DD)
 *     relogio.manutencoes[].nome     o aviso
 *     relogio.medicao_janela_dias    a janela da média do gasto medido, em dias (a da Configuração)
 *     relogio.medicoes[]             as medições do gasto pelas leituras (cada leitura de um tipo que mede o gasto, comparada com a
 *                                    anterior), da mais recente para a mais antiga
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
 *     relogio.medicoes[].medida      o gasto do intervalo sozinho, no histórico: uso (o gasto por dia de uso, quando o intervalo teve meio
 *                                    dia de uso ou mais, ou mais dias de uso que fora) ou repouso (o gasto por dia fora do pulso); o gasto
 *                                    que vale sai de todas as medições juntas
 *     relogio.medicoes[].na_media    verdadeiro ou falso: entra na média agora (usada e dentro da janela da Configuração,
 *                                    medicao_janela_dias)
 *     relogio.medicoes[].peso_horas  o peso da medição na média: as horas que ela cobriu, no pulso (uso) ou guardado (repouso) (número)
 *     relogio.medicoes[].taxa        o gasto medido, em % por dia de uso (uso) ou por dia guardado (repouso) (número)
 *     relogio.medicoes[].usada       verdadeiro ou falso: a caixa "Atualizar o gasto com esta medição" estava marcada (entra na média);
 *                                    falso: só histórico
 *     relogio.no_id                  o grupo (0: na raiz)
 *     relogio.nome                   o nome
 *     relogio.nota                   a nota nos critérios; null: indisponível
 *     relogio.nota.conjunto_texto    de onde vêm os critérios
 *     relogio.nota.nota              a nota, de 0 a 100
 *     relogio.observacao             a observação do cadastro (null)
 *     relogio.previsao               a previsão da energia (só nos relógios com leitura, como o smartwatch): as frases da tela e os mesmos
 *                                    números
 *     relogio.previsao.aplica        verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)
 *     relogio.previsao.carga_na_entrada
 *                                    com quanto ele entra nesse dia, em % (número)
 *     relogio.previsao.carregar_antes
 *                                    verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)
 *     relogio.previsao.chega_limite_em
 *                                    quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa
 *                                    de um ano
 *     relogio.previsao.confianca     a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media
 *                                    ou baixa (leitura com mais de 7 dias)
 *     relogio.previsao.conta[]       de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras
 *     relogio.previsao.conta[].campo o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     relogio.previsao.conta[].detalhe
 *                                    no gasto medido, como ele foi medido ("conta dos dois gastos juntos, com 3 medições dos últimos 90
 *                                    dias"); null nos campos
 *     relogio.previsao.conta[].nome  o nome do campo, ou do gasto medido (Gasto em uso medido, Gasto fora do pulso medido)
 *     relogio.previsao.conta[].origem
 *                                    de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido
 *                                    (pelas leituras)
 *     relogio.previsao.conta[].unidade
 *                                    a unidade do valor (texto)
 *     relogio.previsao.conta[].valor o valor usado na conta (número; null se vazio)
 *     relogio.previsao.dura_ate      até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)
 *     relogio.previsao.dura_dias     quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)
 *     relogio.previsao.energia       a energia agora, em % (número; null sem leitura)
 *     relogio.previsao.gasto         os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as
 *                                    tem)
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
 *     relogio.situacao[]             as frases de situação: as sessões abertas que o estado não mostra e a autonomia restante
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
 *     canais.<canal>.tipos           o nome do campo do formulário (acao=salvar) com os tipos que vão pelo canal (alerta_tipos[],
 *                                    agenda_tipos[])
 *     chave_agenda                   a chave do Google lida no caminho configurado; null: não lida
 *     mostrar_ate                    quantos dias as listas mostram por página (o "Mostrar até" da Configuração, já com o padrão)
 *     mostrar_ate.proximos           os Próximos dias da página Hoje (proximos_por_pagina; padrão 7)
 *     mostrar_ate.plano              o dia a dia da página Plano (plano_por_pagina; padrão 31)
 *     aleatorio                      de onde vêm os números dos sorteios: RANDOM.ORG (ruído atmosférico); fora do ar, ANU QRNG (quântico);
 *                                    sem os dois, o gerador seguro do PHP (random_int)
 *     aleatorio.fontes[]             as fontes, na ordem em que o sistema tenta: RANDOM.ORG, ANU QRNG, gerador seguro do PHP (random_int)
 *     aleatorio.ultimo_lote          a fonte do último lote de números e quando ("RANDOM.ORG em AAAA-MM-DD HH:MM:SS"); null: nenhum ainda
 *     aleatorio.fora_desde           desde quando os dois serviços estão sem responder (AAAA-MM-DD HH:MM:SS): por 10 minutos o sistema nem
 *                                    tenta e usa o gerador do PHP; null: no ar (ou não se sabe)
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
 *     config.carga_limiar            o limite de carga geral (%): carregar quando a carga chega a ele (o campo carga_minima do relógio, se
 *                                    preenchido, vale no lugar)
 *     config.cron_erro               o erro guardado da última execução com erro (texto; vazio: sem erro)
 *     config.cron_registro           o registro da última rodada com atividade (texto)
 *     config.cron_ultima_execucao    quando o cron rodou pela última vez (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     config.na_hora_enviados        os avisos "na hora" que já foram pelo Telegram e ainda estão vencidos (relogio_id:identificador,
 *                                    separados por vírgula): o cron não manda de novo
 *     config.backup_ultima           quando foi feita a última cópia de segurança (texto AAAA-MM-DD HH:MM:SS)
 *     config.escala_fim              até que dia vai a escala inteligente gerada (texto AAAA-MM-DD; vazio fora da escala)
 *     config.escala_gerada           o dia em que a escala foi gerada pela última vez (texto AAAA-MM-DD)
 *     config.horario_manha           a hora da rodada da manhã do cron: o relógio do dia e os avisos (HH:MM)
 *     config.horario_noite           a hora da rodada da noite: preparar o relógio de amanhã (HH:MM)
 *     config.max_sem_uso             a garantia de rodízio: nenhum relógio passa desses dias sem uso (0 desliga)
 *     config.medicao_janela_dias     a média do gasto medido pelas leituras usa as medições destes últimos dias (sem nenhuma na janela, a
 *                                    última)
 *     config.encostado_dias          o aviso Encostado: depois de quantos dias sem ir ao pulso um relógio da coleção é avisado (1 a 3650;
 *                                    padrão 30)
 *     config.idioma                  o idioma padrão da instalação (pt-BR, en, zh-CN, hi, es, fr): o de quem não escolheu outro no menu, o
 *                                    das mensagens do cron e o da API sem lingua= (vazio: o do navegador, senão português)
 *     linguas[]                      os idiomas do sistema, na ordem (o menu Idioma padrão)
 *     linguas[].codigo               o código (pt-BR, en, zh-CN, hi, es, fr)
 *     linguas[].nome                 o nome no próprio idioma
 *     config.mensagens_ativas        1: o Telegram (a API de alerta) envia; 0: não envia
 *     config.painel_modo             como o relógio abre na página Hoje: lado (no painel à direita da lista) ou flutuante (numa janela
 *                                    grande por cima da página); vazio: lado
 *     config.destaque_hoje           o destaque no alto da página Hoje: dia (o relógio do dia, o do plano) ou pulso (o relógio no pulso
 *                                    agora; sem nenhum no pulso, o do dia); vazio: dia
 *     config.proximos_por_pagina     o "Mostrar até" dos Próximos dias da página Hoje: quantos dias por página (1 a 366; vazio: 7)
 *     config.plano_por_pagina        o "Mostrar até" do dia a dia da página Plano: quantos dias por página (1 a 366; vazio: 31)
 *     config.aleatorio_ultima        a fonte do último lote de números aleatórios e quando (o sistema grava; só leitura)
 *     config.aleatorio_fora          quando os dois serviços de números aleatórios falharam (segundos desde 1970; vazio: no ar); o sistema
 *                                    grava; só leitura
 *     config.migracao_v10            marca de que a migração v10 foi aplicada (1)
 *     config.migracao_v12            marca de que a migração v12 foi aplicada (1)
 *     config.migracao_v16            marca de que a migração v16 foi aplicada (1)
 *     config.migracao_v17            marca de que a migração v17 foi aplicada (1)
 *     config.migracao_v18            marca de que a migração v18 foi aplicada (1)
 *     config.migracao_v19            marca de que a migração v19 foi aplicada (1)
 *     config.migracao_v20            marca de que a migração v20 foi aplicada (1)
 *     config.pulso_auto_fim          1 (ou vazio): o relógio sai do pulso sozinho no fim do horário de uso (o "fecha às" do tipo No pulso);
 *                                    0: só pelo Tirou
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
 *   recurso=cadastros
 *     avisos[]                       os avisos cadastrados (cada identificador pode ter uma versão por grupo)
 *     avisos[].agenda                a data na agenda: janela (só dentro da antecedência da agenda) ou sempre
 *     avisos[].na_hora               1: o aviso vai pelo Telegram no minuto em que vence (uma vez, até ser resolvido); 0: só no alerta da
 *                                    manhã
 *     avisos[].antecedencia_dias     com quantos dias antes ele entra "em breve" (número)
 *     avisos[].ativo                 o aviso vale
 *     avisos[].condicao              vale quando: uma fórmula que diz para quais relógios do grupo o aviso vale (1 vale, 0 não; ex.:
 *                                    corda_manual = 0, só o automático sem corda); null: vale para todos
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
 *     campos[].so_desejado           1: o campo vale só nos relógios desejados (a lista de desejos); 0: em todos (do grupo)
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
 *     lancamento_tipos[].condicao    vale quando: uma fórmula que diz para quais relógios do grupo o tipo vale (1 vale, 0 não; ex.:
 *                                    corda_manual, só quem aceita corda); null: vale para todos
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
 *   recurso=avisos
 *     avisos[]                       os avisos de todos os relógios agora, do mais urgente ao mais distante
 *     avisos[].agenda                a data na agenda: janela (só dentro da antecedência da agenda) ou sempre (qualquer data); se vai pela
 *                                    agenda é a Configuração
 *     avisos[].na_hora               verdadeiro: o aviso vai pelo Telegram no minuto em que vence (uma vez), além do alerta da manhã
 *     avisos[].data                  a data prevista, pela fórmula do aviso (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     avisos[].disponivel            verdadeiro ou falso: o relógio entra no rodízio
 *     avisos[].escala                na escala inteligente: nao, uso (conferido no relógio do dia) ou sempre (também nos guardados)
 *     avisos[].estado                atrasado (a data passou), em_breve (dentro da antecedência) ou ok
 *     avisos[].falta_dias            quanto falta para a data prevista, em dias (número; negativo: já passou)
 *     avisos[].identificador         o aviso (corda, carregar, sol, pilha, revisao, garantia, carga_baixa, leitura, ou um que você
 *                                    cadastrou)
 *     avisos[].modelo                o texto do cadastro, sem trocar as âncoras (só o {limite})
 *     avisos[].nome                  o nome do aviso ("Dar corda") — a âncora {acao}
 *     avisos[].relogio               o nome
 *     avisos[].relogio_id            o relógio
 *     avisos[].resolve               o tipo de lançamento que resolve o aviso (o botão na tela Hoje); null: nenhum
 *     avisos[].simula_horas          as horas da sessão que a escala simula para resolver o aviso (número; null: nenhuma)
 *     avisos[].simula_valor          o valor do lançamento que a escala simula para resolver o aviso (leitura) (número; null: nenhum)
 *     avisos[].texto                 o motivo por extenso, com {relogio}, {data}, {quando} e {limite} trocados — a âncora {motivo}
 *     avisos[].versao                o lugar da árvore da versão do aviso usada (texto)
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
 *     modos[].selecao                a forma de escolha: inteligente (a maior nota), ponderado (sorteio pela nota), aleatorio ou fifo (o
 *                                    mais tempo sem uso)
 *     notas[]                        a nota de cada relógio agora, com a conta
 *     notas[].conjunto               o lugar dos critérios usados: "" (todos), g:<grupo> ou r:<relógio>; null: nenhum (nota neutra, 50)
 *     notas[].conjunto_texto         esse lugar por extenso
 *     notas[].conta[]                cada subparâmetro que entrou na conta
 *     notas[].conta[].faixa          a faixa em que o valor caiu ("30 a 50", ou a categoria)
 *     notas[].conta[].nota           a nota da faixa, de 0 a 100 (número)
 *     notas[].conta[].parametro      o nome do parâmetro
 *     notas[].conta[].peso_efetivo   o peso no conjunto: peso do subparâmetro × peso do parâmetro, reescalado sem os que não se aplicam, em
 *                                    % (número)
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
 *     variaveis.<variavel>.max       o limite da medida (a última faixa vai até ele ou sem limite): o que o nome diz em "(0 a N)"; null:
 *                                    sem limite
 *     variaveis.<variavel>.nome      o nome (com a unidade)
 *     variaveis.<variavel>.tipo      numero ou categoria (campo de lista)
 *     variaveis.<variavel>.valores[] as categorias possíveis (campo de lista); vazio nos números
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
 *     linhas[]                       os trechos da linha do tempo do relógio, do mais recente para o mais antigo: cada trecho é um período
 *                                    contínuo num estado, ou uma marcação (um lançamento instantâneo)
 *     linhas[].duracao               a mesma duração por extenso ("2d 3h", "40min"); null numa marcação
 *     linhas[].duracao_seg           quanto o trecho durou, número inteiro, em segundos; null numa marcação
 *     linhas[].em_andamento          verdadeiro ou falso: o trecho ainda não terminou (o fim é o momento da consulta)
 *     linhas[].estado                o estado do trecho: rodizio (no pulso pelo rodízio), pulso (no pulso fora do rodízio), o identificador
 *                                    de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma marcação)
 *     linhas[].fim                   quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação
 *     linhas[].inicio                quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     linhas[].relogio               o nome
 *     linhas[].relogio_id            o relógio
 *     linhas[].texto                 o trecho por extenso, como a tela mostra ("em uso", "no sol", "Leitura de carga 80%")
 *     linhas[].tipo                  o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no
 *                                    repouso
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
 *   recurso=previsao
 *     previsoes[]                    a previsão de cada relógio com leitura
 *     previsoes[].aplica             verdadeiro ou falso: o relógio tem leitura com valor (senão os outros campos ficam vazios)
 *     previsoes[].carga_na_entrada   com quanto ele entra nesse dia, em % (número)
 *     previsoes[].carregar_antes     verdadeiro ou falso: a carga na entrada não basta (é preciso carregar antes)
 *     previsoes[].chega_limite_em    quando, parado, a carga chega ao limite (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null se passa
 *                                    de um ano
 *     previsoes[].confianca          a confiança da conta: alta (leitura de até 2 dias e nenhum dado vazio, ou gasto em uso medido), media
 *                                    ou baixa (leitura com mais de 7 dias)
 *     previsoes[].conta[]            de onde vem cada número da conta: os campos do cadastro usados e o gasto medido pelas leituras
 *     previsoes[].conta[].campo      o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     previsoes[].conta[].detalhe    no gasto medido, como ele foi medido ("conta dos dois gastos juntos, com 3 medições dos últimos 90
 *                                    dias"); null nos campos
 *     previsoes[].conta[].nome       o nome do campo, ou do gasto medido (Gasto em uso medido, Gasto fora do pulso medido)
 *     previsoes[].conta[].origem     de onde veio: informado (no cadastro do relógio), padrão (o do campo), vazio (sem valor) ou medido
 *                                    (pelas leituras)
 *     previsoes[].conta[].unidade    a unidade do valor (texto)
 *     previsoes[].conta[].valor      o valor usado na conta (número; null se vazio)
 *     previsoes[].dura_ate           até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)
 *     previsoes[].dura_dias          quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)
 *     previsoes[].energia            a energia agora, em % (número; null sem leitura)
 *     previsoes[].gasto              os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as
 *                                    tem)
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
 *   recurso=plano
 *     escala_fim                     até que dia vai a escala (null fora da escala)
 *     modo                           o nome do modo em uso
 *     plano[]                        o plano gravado: o relógio de cada dia
 *     plano[].acao                   o lembrete do dia (o que fazer antes: carregar, dar corda...); null: nada
 *     plano[].bloco                  o nome desse bloco
 *     plano[].bloco_id               o bloco do modo que escolheu o dia; null: escala ou manual
 *     plano[].criado                 quando o dia foi gravado no plano (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     plano[].data                   o dia (texto AAAA-MM-DD)
 *     hoje                           hoje (texto AAAA-MM-DD)
 *     livres[]                       os dias livres do período (texto AAAA-MM-DD): sem relógio e sem sorteio, para escolher à mão (o
 *                                    "Deixar o período livre"); a página Plano os põe entre os dias do plano
 *     dia_a_dia                      o dia a dia da página Plano, uma página por vez (pagina=N; por_pagina=N, sem ele o "Mostrar até" da
 *                                    Configuração, plano_por_pagina, padrão 31), no período pedido, com os dias livres no meio;
 *                                    relogio=<id>: só os dias desse relógio (sem os livres)
 *     dia_a_dia.por_pagina           quantos dias vêm por página (o "Mostrar até" da Configuração, ou o por_pagina pedido)
 *     dia_a_dia.pagina               a página que veio (1: a primeira)
 *     dia_a_dia.paginas              quantas páginas há
 *     dia_a_dia.total                quantos dias há, somando todas as páginas (com os dias livres)
 *     dia_a_dia.relogio              o filtro de relógio (o id; null: todos)
 *     dia_a_dia.dias[]               os dias desta página, na ordem das datas, com os dias livres no meio
 *     dia_a_dia.dias[].data          o dia (AAAA-MM-DD)
 *     dia_a_dia.dias[].dia_semana    o dia da semana: 1 segunda ... 7 domingo
 *     dia_a_dia.dias[].dia_semana_curto
 *                                    o dia da semana curto: seg, ter, qua, qui, sex, sáb, dom
 *     dia_a_dia.dias[].hoje          verdadeiro no dia de hoje
 *     dia_a_dia.dias[].relogio_id    o relógio do dia (null num dia livre)
 *     dia_a_dia.dias[].relogio       o nome dele (null num dia livre)
 *     dia_a_dia.dias[].origem        sorteio, manual (escolhido à mão) ou livre (dia livre: sem relógio e sem sorteio, para escolher à mão)
 *     dia_a_dia.dias[].acao          o lembrete do dia (o que fazer antes: carregar, dar corda...); null: nenhum
 *     dia_a_dia.dias[].motivo        por que o relógio está no dia; null: nenhum
 *     plano[].motivo                 por que este relógio saiu neste dia, numa frase (a garantia de rodízio, a nota, o sorteio, a escolha à
 *                                    mão); null: o dia foi gravado antes de o sistema guardar o motivo
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
 *   recurso=proximos
 *     por_pagina                     quantos dias vêm por página (o "Mostrar até" da Configuração, ou o por_pagina pedido)
 *     pagina                         a página que veio (1: a primeira)
 *     paginas                        quantas páginas há
 *     total                          quantos dias há, somando todas as páginas (com os dias livres)
 *     relogio                        o filtro de relógio (o id; null: todos)
 *     dias[]                         os dias desta página, na ordem das datas, com os dias livres no meio
 *     dias[].data                    o dia (AAAA-MM-DD)
 *     dias[].dia_semana              o dia da semana: 1 segunda ... 7 domingo
 *     dias[].dia_semana_curto        o dia da semana curto: seg, ter, qua, qui, sex, sáb, dom
 *     dias[].hoje                    verdadeiro no dia de hoje
 *     dias[].relogio_id              o relógio do dia (null num dia livre)
 *     dias[].relogio                 o nome dele (null num dia livre)
 *     dias[].origem                  sorteio, manual (escolhido à mão) ou livre (dia livre: sem relógio e sem sorteio, para escolher à mão)
 *     dias[].acao                    o lembrete do dia (o que fazer antes: carregar, dar corda...); null: nenhum
 *     dias[].motivo                  por que o relógio está no dia; null: nenhum
 *   recurso=desejos
 *     desejados[]                    os relógios desejados (a lista de desejos), a prioridade mais alta primeiro, depois pelo nome
 *     desejados[].caminho            o caminho do grupo ("Automáticos › Mergulho"; "todos os relógios" na raiz)
 *     desejados[].criado             quando entrou no sistema (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     desejados[].foto               a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     desejados[].id                 o número (código)
 *     desejados[].link               o link da página do relógio (null)
 *     desejados[].marca              a marca (null)
 *     desejados[].nome               o nome
 *     desejados[].preco_alvo         até quanto pagar, em R$ (número; null)
 *     desejados[].prioridade         a prioridade (Alta, Média, Baixa...: as opções do campo prioridade_desejo; null)
 *     desejados[].prioridade_ordem   a ordem da prioridade (1 é a mais alta; sem prioridade: depois de todas)
 *     desejados[].referencia         a referência, o modelo (null)
 *     desejados[].tipo               o grupo do relógio (o nome; "sem grupo" na raiz)
 *     fora[]                         os relógios fora da coleção que não são desejados: emprestados, vendidos e arquivados, pelo nome
 *     fora[].caminho                 o caminho do grupo
 *     fora[].criado                  quando entrou no sistema (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     fora[].foto                    a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     fora[].id                      o número (código)
 *     fora[].nome                    o nome
 *     fora[].posse                   a situação: emprestado, vendido ou arquivado
 *     fora[].posse_texto             a mesma por extenso
 *     fora[].tipo                    o grupo do relógio (o nome; "sem grupo" na raiz)
 *     grupos[]                       os grupos em ordem de árvore
 *     grupos[].caminho               o caminho do grupo
 *     grupos[].id                    o número do grupo
 *     prioridades[]                  as prioridades, da mais alta para a mais baixa (as opções do campo prioridade_desejo)
 *     sem_preco_alvo                 quantos desejados estão sem preço-alvo
 *     total_preco_alvo               a soma dos preços-alvo dos desejados, em R$ (os sem preço contam 0)
 *   recurso=estatisticas
 *     de                             o começo do período (texto AAAA-MM-DD)
 *     ate                            o fim do período (texto AAAA-MM-DD; no máximo hoje)
 *     hoje                           hoje (texto AAAA-MM-DD: o máximo das datas do filtro)
 *     resumo                         o resumo do período
 *     resumo.dias                    quantos dias o período tem
 *     resumo.dias_com_relogio        em quantos dias algum relógio foi ao pulso
 *     resumo.dias_sem_relogio        em quantos dias nenhum foi
 *     resumo.horas                   as horas no pulso de todos os relógios (uma casa)
 *     resumo.horas_por_dia           as horas no pulso por dia com relógio, em média
 *     resumo.relogios_na_colecao     quantos relógios a coleção tem agora (na coleção e emprestados)
 *     resumo.relogios_usados         quantos relógios foram ao pulso no período
 *     resumo.nao_usados              quantos relógios da coleção não foram ao pulso no período
 *     resumo.mais_usado              o mais usado (as horas, depois os dias); null: nenhum foi ao pulso
 *     resumo.mais_usado.id           o relógio
 *     resumo.mais_usado.nome         o nome
 *     resumo.mais_usado.horas        as horas no pulso no período
 *     resumo.mais_usado.usos         os dias de uso no período
 *     resumo.menos_usado             o menos usado dos que foram ao pulso; null: menos de dois foram
 *     resumo.menos_usado.id          o relógio
 *     resumo.menos_usado.nome        o nome
 *     resumo.menos_usado.horas       as horas no pulso no período
 *     resumo.menos_usado.usos        os dias de uso no período
 *     resumo.valor_colecao           a soma do valor pago dos relógios da coleção, em R$
 *     resumo.custo_por_uso_medio     a média do custo por uso dos relógios que têm um, em R$; null: nenhum tem
 *     relogios[]                     cada relógio da coleção (e os de fora dela que foram ao pulso no período), do mais usado ao menos
 *                                    usado
 *     relogios[].id                  o número (código)
 *     relogios[].nome                o nome
 *     relogios[].tipo                o grupo do relógio (o nome; "sem grupo" na raiz)
 *     relogios[].posse               a situação na coleção (colecao, emprestado, desejado, vendido, arquivado)
 *     relogios[].posse_texto         a mesma por extenso
 *     relogios[].usos                os dias de uso no período (os dias em que ele foi ao pulso)
 *     relogios[].horas               as horas no pulso no período (uma casa)
 *     relogios[].porcentagem         a parte dele nas horas no pulso de todos, em % (uma casa)
 *     relogios[].barra               a largura da barra, de 0 a 100: as horas dele comparadas com as do mais usado
 *     relogios[].ultimo_uso          o último dia no pulso (texto AAAA-MM-DD; hoje, se está no pulso); null: nunca
 *     relogios[].dias_sem_uso        há quantos dias foi ao pulso pela última vez (0: hoje); null: nunca
 *     relogios[].valor_compra        o valor pago, em R$ (null)
 *     relogios[].dias_de_uso         os dias de uso desde sempre (a fórmula dias_de_uso; null sem ela)
 *     relogios[].custo_por_uso       o custo por uso (a fórmula custo_por_uso: o valor pago dividido pelos dias de uso desde sempre), em
 *                                    R$; null: sem o valor ou sem uso
 *     relogios[].tendencia           sobe (usando mais), desce (usando menos), estavel (uma diferença de até 20% ou de menos de 1 h) ou
 *                                    sem_dados (menos de 1 h no período, ou período de menos de 14 dias): as horas da segunda metade do
 *                                    período comparadas com as da primeira
 *     relogios[].tendencia_texto     a mesma por extenso ("usando mais", "usando menos", "estável", "poucos dados")
 *     relogios[].horas_primeira_metade
 *                                    as horas no pulso na primeira metade do período
 *     relogios[].horas_segunda_metade
 *                                    as horas no pulso na segunda metade
 *     por_dia_semana[]               o uso por dia da semana, de segunda a domingo
 *     por_dia_semana[].dia           o dia da semana (1 segunda ... 7 domingo)
 *     por_dia_semana[].nome          o nome ("segunda"...)
 *     por_dia_semana[].dias          quantos desses dias o período tem
 *     por_dia_semana[].dias_com_uso  em quantos deles algum relógio foi ao pulso
 *     por_dia_semana[].horas         as horas no pulso de todos nesses dias
 *     por_dia_semana[].media_horas   as horas por dia, em média (dividindo por todos esses dias do período)
 *     por_dia_semana[].porcentagem   a parte desse dia nas horas de todos, em %
 *     por_dia_semana[].barra         a altura da barra, de 0 a 100: comparada com o dia de mais horas
 *     por_mes[]                      o uso por mês do período, em ordem
 *     por_mes[].mes                  o mês (texto AAAA-MM)
 *     por_mes[].nome                 o mês por extenso ("set/2026")
 *     por_mes[].nome_curto           curto ("set/26"), para o rótulo do gráfico
 *     por_mes[].dias                 quantos dias do mês estão no período
 *     por_mes[].dias_com_uso         em quantos algum relógio foi ao pulso
 *     por_mes[].relogios             quantos relógios foram ao pulso no mês
 *     por_mes[].horas                as horas no pulso de todos no mês
 *     por_mes[].media_horas          as horas por dia do mês no período, em média
 *     por_mes[].porcentagem          a parte do mês nas horas de todos, em %
 *     por_mes[].barra                a altura da barra, de 0 a 100: comparada com o mês de mais horas
 *     distribuicao[]                 a coleção agora (na coleção e emprestados) dividida de cada jeito: Grupo, Marca, Ano da compra,
 *                                    Situação e cada campo de lista (menos os só dos desejados)
 *     distribuicao[].titulo          o nome da divisão ("Grupo", "Marca", o nome do campo)
 *     distribuicao[].identificador   grupo, marca, ano_compra, posse ou o identificador do campo de lista
 *     distribuicao[].itens[]         cada parte, da que tem mais relógios para a que tem menos
 *     distribuicao[].itens[].nome    a parte (o caminho do grupo, "(na raiz)", a marca, o ano, a situação, a opção; "(sem)": sem o valor)
 *     distribuicao[].itens[].relogios
 *                                    quantos relógios
 *     distribuicao[].itens[].porcentagem
 *                                    a parte da coleção, em % (e a largura da barra)
 *     distribuicao[].itens[].horas   as horas no pulso desses relógios no período
 *     distribuicao[].itens[].porcentagem_horas
 *                                    a parte deles nas horas no pulso, em %
 *   recurso=hora_certa
 *     agora                          o instante da resposta (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))
 *     agora_ms                       o mesmo em milissegundos: a página acerta o relógio dela por ele, descontando metade do tempo do
 *                                    pedido
 *     local                          a hora local (o fuso do sistema)
 *     local.data                     a data (texto AAAA-MM-DD)
 *     local.hora                     a hora (texto HH:MM:SS)
 *     local.iso8601                  a data e a hora com o deslocamento ("2026-10-13T14:33:50-03:00")
 *     local.dia_semana               o dia da semana ("terça-feira")
 *     local.texto                    a data por extenso ("terça-feira, 13 de outubro de 2026")
 *     fuso                           o fuso do sistema
 *     fuso.nome                      o nome ("America/Sao_Paulo": o FUSO do config.php)
 *     fuso.deslocamento              a diferença para o UTC ("-03:00")
 *     fuso.segundos                  a mesma em segundos (-10800)
 *     fuso.abreviacao                a abreviação ("-03", "CET")
 *     fuso.horario_de_verao          verdadeiro: o horário de verão vale agora
 *     utc                            a hora UTC (Zulu)
 *     utc.data                       a data (texto AAAA-MM-DD)
 *     utc.hora                       a hora (texto HH:MM:SS)
 *     utc.iso8601                    a data e a hora com Z ("2026-10-13T17:33:50Z")
 *     militar                        o fuso militar (o alfabeto da OTAN)
 *     militar.letra                  a letra do fuso (A a M, sem J, a leste; N a Y a oeste; Z no UTC); null: o fuso não é de hora cheia
 *     militar.nome                   o nome da letra ("Papa"); null
 *     militar.grupo_data_hora_local  o grupo data-hora (DTG) local: DDHHMM, a letra, o mês em inglês e o ano ("131433P OCT 26"); null sem
 *                                    letra
 *     militar.grupo_data_hora_zulu   o mesmo em UTC ("131733Z OCT 26")
 *     calendario                     o calendário de hoje
 *     calendario.dia_do_ano          o dia do ano (1 a 366)
 *     calendario.dias_no_ano         quantos dias o ano tem (365 ou 366)
 *     calendario.faltam_no_ano       quantos dias faltam para acabar o ano
 *     calendario.semana_iso          a semana do ano pela ISO 8601 (1 a 53)
 *     calendario.ano_iso             o ano dessa semana (no começo de janeiro e no fim de dezembro pode ser outro)
 *     calendario.trimestre           o trimestre (1 a 4)
 *     calendario.bissexto            verdadeiro: o ano é bissexto
 *     calendario.dia_juliano         o dia juliano (o número dos astrônomos, cinco casas)
 *     calendario.unix                o instante Unix (segundos desde 01/01/1970 UTC)
 *     lua                            a lua agora, pelo ciclo sinódico médio (29,53 dias) a partir da lua nova de 06/01/2000: pode errar por
 *                                    um dia
 *     lua.fase                       nova, crescente, quarto_crescente, crescente_gibosa, cheia, minguante_gibosa, quarto_minguante ou
 *                                    minguante
 *     lua.nome                       a fase por extenso ("Lua cheia", "Quarto minguante")
 *     lua.simbolo                    o símbolo da fase (🌑 🌒 🌓 🌔 🌕 🌖 🌗 🌘)
 *     lua.idade_dias                 os dias desde a última lua nova (uma casa)
 *     lua.iluminacao                 a parte iluminada, em % (uma casa)
 *     lua.proxima_cheia              a próxima lua cheia (texto AAAA-MM-DD)
 *     lua.proxima_nova               a próxima lua nova (texto AAAA-MM-DD)
 *     como_acertar                   o passo a passo para acertar um relógio de ponteiros pela hora certa
 *   recurso=acessorios
 *     acessorios[]                   os acessórios, pelo tipo e pelo nome
 *     acessorios[].id                o número do acessório
 *     acessorios[].nome              o nome ("Pulseira NATO verde")
 *     acessorios[].tipo              pulseira, caixa, estojo, ferramenta, carregador, peca ou outro
 *     acessorios[].tipo_texto        o tipo por extenso ("Pulseira", "Peça")
 *     acessorios[].relogio_id        o relógio de que ele é; null: solto (serve a vários)
 *     acessorios[].relogio           o nome desse relógio; null: solto
 *     acessorios[].observacao        a observação (null)
 *     acessorios[].criado            quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     tipos[]                        os tipos de acessório, na ordem (o menu Tipo)
 *     tipos[].valor                  o valor que vai na gravação (tipo)
 *     tipos[].nome                   o nome
 *     por_tipo[]                     quantos acessórios da lista há de cada tipo (só os tipos que têm algum)
 *     por_tipo[].tipo                o tipo
 *     por_tipo[].nome                o nome do tipo
 *     por_tipo[].quantos             quantos
 *     soltos                         quantos acessórios da lista são soltos (de nenhum relógio)
 *     relogios[]                     todos os relógios, pelo nome (o menu Relógio)
 *     relogios[].id                  o número (código)
 *     relogios[].nome                o nome
 *     relogios[].posse               a situação na coleção
 *   recurso=passaporte
 *     gerado_em                      quando foi gerado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio                        quem ele é
 *     relogio.id                     o número (código)
 *     relogio.nome                   o nome
 *     relogio.caminho                o caminho do grupo ("todos os relógios" na raiz)
 *     relogio.tipo                   o grupo (o nome; "sem grupo" na raiz)
 *     relogio.posse                  a situação na coleção
 *     relogio.posse_texto            a mesma por extenso
 *     relogio.disponivel             verdadeiro: está no rodízio
 *     relogio.criado                 quando entrou no sistema (texto AAAA-MM-DD HH:MM:SS)
 *     relogio.foto                   a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     relogio.marca                  a marca (null)
 *     relogio.referencia             a referência (null)
 *     relogio.observacao             a observação (null)
 *     cadastro[]                     os campos preenchidos do cadastro (os que valem para ele), na ordem, já em texto
 *     cadastro[].identificador       o campo
 *     cadastro[].nome                o nome do campo
 *     cadastro[].valor               o valor em texto: data DD/MM/AAAA, sim ou não, número com vírgula e a unidade (R$ 1.200,50; 40 h)
 *     compra                         a compra
 *     compra.data                    a data (texto AAAA-MM-DD; null)
 *     compra.valor                   o valor pago, em R$ (null)
 *     compra.loja                    onde comprou (null)
 *     compra.garantia_ate            a garantia até (texto AAAA-MM-DD; null)
 *     uso                            o uso desde sempre
 *     uso.primeira_vez               a primeira vez no pulso (texto AAAA-MM-DD; null: nunca)
 *     uso.ultima_vez                 a última vez no pulso (hoje, se está no pulso; null: nunca)
 *     uso.no_pulso_agora             verdadeiro: está no pulso agora
 *     uso.horas_no_pulso             as horas no pulso desde sempre (uma casa)
 *     uso.dias_de_uso                os dias de uso (a fórmula dias_de_uso; null sem ela)
 *     uso.custo_por_uso              o custo por uso (a fórmula custo_por_uso), em R$; null
 *     marcacoes[]                    cada tipo de marcação instantânea (corda, troca de pilha, revisão...) que ele tem, da mais recente
 *                                    para a mais antiga
 *     marcacoes[].tipo               o tipo de lançamento (o identificador)
 *     marcacoes[].nome               o nome
 *     marcacoes[].vezes              quantas vezes
 *     marcacoes[].ultima             a última (texto AAAA-MM-DD)
 *     servicos[]                     as revisões e as trocas de pilha, da mais recente para a mais antiga
 *     servicos[].data                quando (texto AAAA-MM-DD)
 *     servicos[].tipo                revisao ou pilha
 *     servicos[].nome                o nome do tipo
 *     acessorios[]                   os acessórios dele (como em recurso=acessorios)
 *     acessorios[].id                o número do acessório
 *     acessorios[].nome              o nome
 *     acessorios[].tipo              o tipo
 *     acessorios[].tipo_texto        o tipo por extenso
 *     acessorios[].relogio_id        este relógio
 *     acessorios[].relogio           o nome dele
 *     acessorios[].observacao        a observação (null)
 *     acessorios[].criado            quando foi cadastrado
 *     documentos[]                   os documentos dele, por categoria e data
 *     documentos[].id                o número do documento
 *     documentos[].categoria         o nome da categoria
 *     documentos[].titulo            o título
 *     documentos[].data              a data do documento (texto AAAA-MM-DD; null)
 *     documentos[].nome              o nome do arquivo
 *     proximos_avisos[]              os próximos avisos (até 8), do mais urgente ao mais distante
 *     proximos_avisos[].nome         o aviso
 *     proximos_avisos[].data         a data prevista (texto AAAA-MM-DD)
 *     proximos_avisos[].texto        o motivo por extenso
 *   recurso=galeria
 *     gerado_em                      quando foi gerado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     todos                          verdadeiro: com os de fora da coleção (posse=todos)
 *     total                          quantos relógios
 *     valor_total                    a soma do valor pago, em R$
 *     com_foto                       quantos têm foto
 *     grupos[]                       os grupos, pelo caminho ("(na raiz)" primeiro)
 *     grupos[].caminho               o caminho do grupo
 *     grupos[].relogios[]            os relógios do grupo, pelo nome
 *     grupos[].relogios[].id         o número (código)
 *     grupos[].relogios[].nome       o nome
 *     grupos[].relogios[].tipo       o grupo (o nome; "sem grupo" na raiz)
 *     grupos[].relogios[].posse      a situação na coleção
 *     grupos[].relogios[].posse_texto
 *                                    a mesma por extenso
 *     grupos[].relogios[].foto       a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     grupos[].relogios[].marca      a marca (null)
 *     grupos[].relogios[].referencia a referência (null)
 *     grupos[].relogios[].ano_compra o ano da compra (null)
 *     grupos[].relogios[].valor_compra
 *                                    o valor pago, em R$ (null)
 *     grupos[].relogios[].dias_de_uso
 *                                    os dias de uso (a fórmula dias_de_uso; null)
 *   recurso=textos
 *     lingua                         o idioma da resposta (pt-BR, en, zh-CN, hi, es, fr)
 *     locale                         o locale dos números nas telas (BCP 47: pt-BR, en-US, zh-CN, hi-IN, es-ES, fr-FR)
 *     formato                        os formatos do idioma (os de LINGUAS, no textos/funcoes.php)
 *     formato.data                   a data com o ano, com d (dia), m (mês) e Y (ano) como no date() do PHP: d/m/Y, m/d/Y (en), Y-m-d
 *                                    (zh-CN)
 *     formato.data_curta             a data sem o ano: d/m, m/d (en), m-d (zh-CN)
 *     formato.decimal                o separador decimal: vírgula ou ponto
 *     formato.milhar                 o separador de milhar: ponto, vírgula ou espaço (fr)
 *     padrao                         o idioma padrão da instalação (config.idioma); null: não escolhido
 *     estrito                        o modo estrito (TEXTOS_ESTRITO no config.php): a tradução que falta aparece entre ⟦ ⟧ nas telas, com o
 *                                    erro no console, em vez de sair em português
 *     linguas[]                      os idiomas do sistema, na ordem do menu
 *     linguas[].codigo               o código
 *     linguas[].nome                 o nome no próprio idioma
 *     linguas[].locale               o locale
 *     textos                         o dicionário das telas (a parte telas do textos/<idioma>.php): o texto em português => a tradução
 *                                    (vazio em português)
 *     textos.<texto em português>    a tradução
 *   recurso=rodizio_manual
 *     de                             o primeiro dia do período (AAAA-MM-DD)
 *     ate                            o último dia do período (AAAA-MM-DD)
 *     total_dias                     quantos dias o período tem
 *     por_pagina                     quantos dias vêm por página: 7
 *     pagina                         a página que veio (1: a primeira)
 *     paginas                        quantas páginas o período tem
 *     dias[]                         os dias desta página, em ordem
 *     dias[].data                    o dia (AAAA-MM-DD)
 *     dias[].dia_semana              o dia da semana: 1 segunda ... 7 domingo
 *     dias[].dia_semana_nome         o dia da semana por extenso (segunda, terça... domingo)
 *     dias[].hoje                    verdadeiro no dia de hoje (hoje não pode ficar livre: já tem o relógio do pulso)
 *     dias[].relogio_id              o relógio que o plano tem agora nesse dia (null: nenhum)
 *     dias[].relogio                 o nome dele (null: nenhum)
 *     dias[].origem                  como ele entrou no plano: sorteio ou manual (escolhido à mão; o Decida por mim gravado também fica
 *                                    manual); null: sem relógio
 *     dias[].motivo                  por que ele está no dia (o motivo do sorteio, da escolha à mão ou do Decida por mim); null: nenhum
 *     dias[].livre                   verdadeiro se o dia está livre (sem relógio e sem sorteio)
 *     dias[].escolha                 o que o seletor do dia mostra de partida, e o que vale se o dia não vier no acao=manual: o id do
 *                                    relógio escolhido à mão, livre, ou auto (Decida por mim: o dia sorteado ou ainda sem relógio)
 *     relogios[]                     os relógios disponíveis, para o seletor de cada dia (por nome)
 *     relogios[].id                  o número do relógio
 *     relogios[].nome                o nome
 *     decida_por_mim                 o critério do Decida por mim, em texto: a nota dos critérios recalculada dia a dia com os dias
 *                                    anteriores do período contados como uso, a garantia de rodízio na frente, sem o relógio de ontem nem o
 *                                    de amanhã (se houver outro), empate pelo cadastrado primeiro; decidido ao gravar
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
 *   recurso=agenda
 *     agenda_id                      o id da agenda
 *     ativa                          verdadeiro ou falso: a agenda está ligada
 *     chave_ok                       verdadeiro ou falso: a chave do Google foi lida e o Google aceitou
 *     criados[]                      os eventos que o sistema criou no Google Agenda
 *     criados[].assinatura           a marca do título e da descrição com que foi criado (mudou: o evento é atualizado na próxima
 *                                    sincronização)
 *     criados[].chave                a identificação do evento (a mesma dos desejados)
 *     criados[].criado               quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     criados[].data                 o dia do evento (texto AAAA-MM-DD)
 *     criados[].google_id            o id do evento no Google
 *     criados[].titulo               o título com que foi criado
 *     desejados[]                    o que tem de estar no Google Agenda agora: os avisos marcados para a agenda, o relógio do dia, a
 *                                    véspera e os eventos
 *     desejados[].chave              a identificação do evento na agenda (a mesma enquanto nada mudar; é o que o sistema usa para atualizar
 *                                    ou remover)
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
 *   recurso=migracoes
 *     pendentes[]                    as migrações do banco que faltam aplicar (vazio: em dia)
 *     pendentes[].arquivo            o arquivo .sql
 *     pendentes[].traz               o que a migração traz
 *     pendentes[].versao             a versão (v2, v3...)
 *   recurso=instalacao
 *     banco                          o banco do config.php: mysql, pgsql, sqlite ou firebird
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
 *     documentos[].no_banco          verdadeiro: a cópia de segurança do arquivo está completa no banco (se ele sumir da pasta, volta dali)
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
 *     telas                          a especificação visual que a reescrita tem de reproduzir, tela por tela: o que está lá existe, o que
 *                                    não está não existe
 *     telas.como_ler                 como ler a especificação (os textos entre aspas são os da tela, palavra por palavra; o estilo.css é a
 *                                    referência de cada medida)
 *     telas.visual[]                 o sistema visual, comum a todas as páginas, em partes: cores, letras, controles, componentes, layout
 *                                    (larguras e tamanhos de tela) e comportamento
 *     telas.visual[].id              o nome curto da parte (cores, letras, controles, componentes, layout, comportamento)
 *     telas.visual[].titulo          o título da parte
 *     telas.visual[].itens[]         uma regra do visual, com os valores exatos (cores em hexadecimal, tamanhos em px ou rem, os textos da
 *                                    tela)
 *     telas.menu                     o menu do topo
 *     telas.menu.como                como o menu aparece no computador e no celular, e as páginas que não estão nele
 *     telas.menu.itens[]             os itens do menu, na ordem da barra
 *     telas.menu.itens[].pagina      o arquivo da página (index.php, plano.php...)
 *     telas.menu.itens[].rotulo      o texto do item no menu
 *     telas.paginas[]                cada página do sistema, na ordem do menu e depois as que abrem do relógio
 *     telas.paginas[].id             o nome curto da página (hoje, ficha, plano, configuracao, criterios, grupos, execucoes, historico,
 *                                    documentos, cadastros, usuarios, ajuda)
 *     telas.paginas[].pagina         o arquivo da página
 *     telas.paginas[].titulo         o título da página (o da aba do navegador)
 *     telas.paginas[].endereco       o endereço, com os parâmetros que a página aceita
 *     telas.paginas[].le[]           o que a página lê da API
 *     telas.paginas[].grava[]        o que a página grava pela API (o recurso e as ações); vazio: só lê
 *     telas.paginas[].partes[]       as partes da página, de cima para baixo
 *     telas.paginas[].partes[].titulo
 *                                    o nome da parte (e quando ela aparece)
 *     telas.paginas[].partes[].itens[]
 *                                    o que a parte tem: cada tabela com as colunas, cada formulário com os campos (rótulo, tipo, padrão e
 *                                    limites), cada botão com o texto e o que ele manda para a API, e cada mensagem
 *     telas.paginas[].codigo[]       os arquivos da página, lidos dela na hora (o pagina.php, o estilo.css, a própria página e os scripts
 *                                    que ela carrega): o código de cada um em recurso=codigo&arquivo=<nome>
 *     telas.capturas[]               as capturas de tela no repositório, para conferir o resultado
 *     telas.capturas[].arquivo       o caminho da imagem (docs/telas/...)
 *     telas.capturas[].mostra        o que ela mostra
 *     codigo                         a lista do código do sistema, como em recurso=codigo (sem o conteúdo: cada arquivo pelo
 *                                    recurso=codigo&arquivo=<nome>)
 *     codigo.como_usar               como pedir um arquivo, o arquivo puro, o HTML de uma página e todos de uma vez
 *     codigo.camadas[]               as camadas do sistema
 *     codigo.camadas[].camada        o nome curto da camada
 *     codigo.camadas[].o_que_e       o que está nela
 *     codigo.arquivos[]              cada arquivo do sistema
 *     codigo.arquivos[].arquivo      o nome do arquivo, com a pasta (estilo.css, testes/cenario.php, docs/telas/hoje.png): é o que se passa
 *                                    em arquivo=
 *     codigo.arquivos[].camada       a camada (tela, api, linha_de_comando, banco, servidor, testes, documentacao, repositorio, imagem;
 *                                    veja camadas)
 *     codigo.arquivos[].o_que_e      o que o arquivo é e o que ele faz
 *     codigo.arquivos[].linguagem    php, javascript, css, sql, markdown, apache, nginx, texto ou png
 *     codigo.arquivos[].bytes        o tamanho, em bytes
 *     codigo.arquivos[].linhas       quantas linhas (null na imagem)
 *     codigo.arquivos[].sha256       o SHA-256 do arquivo: para conferir a cópia, ou saber se mudou desde a última leitura
 *     codigo.arquivos[].alterado_em  a data da última alteração do arquivo no servidor (AAAA-MM-DD HH:MM:SS)
 *     codigo.arquivos[].ver          o endereço deste arquivo na API, com o conteúdo, em JSON
 *     codigo.arquivos[].cru          o endereço do arquivo puro, sem JSON (texto como text/plain; imagem como imagem)
 *     codigo.arquivos[].usa[]        só nas páginas: os arquivos que ela usa, na ordem (o pagina.php, o estilo.css, a própria página e os
 *                                    scripts que ela carrega)
 *     codigo.arquivos[].html_cru     só nas páginas: o endereço do HTML que o navegador recebe, puro
 *     codigo.nao_mostrados[]         o que fica de fora de propósito
 *     codigo.nao_mostrados[].arquivo o que é
 *     codigo.nao_mostrados[].por_que por que não sai
 *     codigo.nao_mostrados[].o_que_ver
 *                                    onde está o que a reescrita precisa saber dele
 *     modelo_de_dados                o modelo de dados, lido do próprio banco em uso (o que vale agora, depois de todas as migrações)
 *     modelo_de_dados.banco          o banco de onde o modelo foi lido: mysql, pgsql, sqlite ou firebird (os tipos das colunas vêm no
 *                                    dialeto dele)
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
 *   recurso=backup
 *     pasta_ok                       verdadeiro: a pasta das cópias (BACKUP_PASTA do config.php) existe, aceita gravar e fica fora da pasta
 *                                    publicada
 *     pasta_erro                     por que a pasta não serve (sem BACKUP_PASTA, não existe, sem permissão de escrita, ou dentro da pasta
 *                                    publicada); null: serve
 *     manter                         quantas cópias ficam na pasta (BACKUP_MANTER; padrão 7): a cada cópia nova, as mais velhas que passam
 *                                    disso saem
 *     documentos                     verdadeiro: as cópias levam o conteúdo dos documentos guardado no banco (BACKUP_DOCUMENTOS); falso:
 *                                    não levam (os arquivos já estão na pasta dos documentos)
 *     versao                         a versão do banco agora (a última migração): só uma cópia da mesma versão volta
 *     ultima                         quando foi feita a última cópia (texto AAAA-MM-DD HH:MM:SS); null: nenhuma
 *     copias[]                       as cópias que estão na pasta, da mais nova para a mais velha
 *     copias[].arquivo               o nome do arquivo (relojoeiro-AAAA-MM-DD-HHMMSS.jsonl.gz): é o que se passa em arquivo= para baixar ou
 *                                    restaurar
 *     copias[].bytes                 o tamanho, em bytes
 *     copias[].tamanho               o tamanho por extenso ("7 KB", "1,2 MB")
 *     copias[].criada                quando a cópia foi feita (texto AAAA-MM-DD HH:MM:SS)
 *     copias[].documentos            verdadeiro: a cópia leva o conteúdo dos documentos
 *     copias[].versao                a versão do banco da cópia (a última migração dele)
 *   recurso=codigo
 *     como_usar                      como pedir um arquivo, o arquivo puro, o HTML de uma página e todos de uma vez
 *     camadas[]                      as camadas do sistema, na ordem
 *     camadas[].camada               o nome curto da camada
 *     camadas[].o_que_e              o que está nela
 *     arquivos[]                     cada arquivo do sistema (os do repositório), pela camada
 *     arquivos[].arquivo             o nome do arquivo, com a pasta (estilo.css, testes/cenario.php, docs/telas/hoje.png): é o que se passa
 *                                    em arquivo=
 *     arquivos[].camada              a camada (tela, api, linha_de_comando, banco, servidor, testes, documentacao, repositorio, imagem;
 *                                    veja camadas)
 *     arquivos[].o_que_e             o que o arquivo é e o que ele faz
 *     arquivos[].linguagem           php, javascript, css, sql, markdown, apache, nginx, texto ou png
 *     arquivos[].bytes               o tamanho, em bytes
 *     arquivos[].linhas              quantas linhas (null na imagem)
 *     arquivos[].sha256              o SHA-256 do arquivo: para conferir a cópia, ou saber se mudou desde a última leitura
 *     arquivos[].alterado_em         a data da última alteração do arquivo no servidor (AAAA-MM-DD HH:MM:SS)
 *     arquivos[].ver                 o endereço deste arquivo na API, com o conteúdo, em JSON
 *     arquivos[].cru                 o endereço do arquivo puro, sem JSON (texto como text/plain; imagem como imagem)
 *     arquivos[].usa[]               só nas páginas: os arquivos que ela usa, na ordem (o pagina.php, o estilo.css, a própria página e os
 *                                    scripts que ela carrega)
 *     arquivos[].html_cru            só nas páginas: o endereço do HTML que o navegador recebe, puro
 *     arquivos[].conteudo            o conteúdo do arquivo, como está (texto); só com arquivo= ou conteudo=1
 *     arquivos[].conteudo_base64     o conteúdo da imagem, em base64; só com arquivo= ou conteudo=1
 *     arquivos[].html                só nas páginas, com arquivo= ou conteudo=1: o HTML que o navegador recebe, já montado (o topo com o
 *                                    menu, o esqueleto da página e os scripts)
 *     nao_mostrados[]                o que fica de fora de propósito, e o que ver no lugar
 *     nao_mostrados[].arquivo        o que é (o config.php, a chave do Google, o banco, a pasta dos documentos, as senhas)
 *     nao_mostrados[].por_que        por que não sai
 *     nao_mostrados[].o_que_ver      onde está o que a reescrita precisa saber dele
 *     arquivo                        com arquivo=: o nome do arquivo, com a pasta (estilo.css, testes/cenario.php, docs/telas/hoje.png): é
 *                                    o que se passa em arquivo=
 *     camada                         com arquivo=: a camada (tela, api, linha_de_comando, banco, servidor, testes, documentacao,
 *                                    repositorio, imagem; veja camadas)
 *     o_que_e                        com arquivo=: o que o arquivo é e o que ele faz
 *     linguagem                      com arquivo=: php, javascript, css, sql, markdown, apache, nginx, texto ou png
 *     bytes                          com arquivo=: o tamanho, em bytes
 *     linhas                         com arquivo=: quantas linhas (null na imagem)
 *     sha256                         com arquivo=: o SHA-256 do arquivo: para conferir a cópia, ou saber se mudou desde a última leitura
 *     alterado_em                    com arquivo=: a data da última alteração do arquivo no servidor (AAAA-MM-DD HH:MM:SS)
 *     ver                            com arquivo=: o endereço deste arquivo na API, com o conteúdo, em JSON
 *     cru                            com arquivo=: o endereço do arquivo puro, sem JSON (texto como text/plain; imagem como imagem)
 *     usa[]                          com arquivo=: só nas páginas: os arquivos que ela usa, na ordem (o pagina.php, o estilo.css, a própria
 *                                    página e os scripts que ela carrega)
 *     html_cru                       com arquivo=: só nas páginas: o endereço do HTML que o navegador recebe, puro
 *     conteudo                       com arquivo=: o conteúdo do arquivo, como está (texto); só com arquivo= ou conteudo=1
 *     conteudo_base64                com arquivo=: o conteúdo da imagem, em base64; só com arquivo= ou conteudo=1
 *     html                           com arquivo=: só nas páginas, com arquivo= ou conteudo=1: o HTML que o navegador recebe, já montado (o
 *                                    topo com o menu, o esqueleto da página e os scripts)
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
 *       README.md (recurso=manual) que não existe, ou um arquivo que não sai pelo recurso=codigo ({"erro", e "o_que_ver" no
 *       que fica de fora de propósito, como o config.php, ou "arquivos", os que saem)
 *   409 {"erro": "o banco recusou a gravação: ...", "detalhe"} (um registro que outro ainda usa, um valor repetido, ou um valor fora
 *       das regras de validação do banco: o nome da regra, ck_<tabela>_<o quê>, vem no detalhe)
 *   413 {"ok": false, "erros": [...]} (o envio passou do post_max_size do PHP: um arquivo grande demais)
 *   500 {"erro": "Sistema parado: ..."} (token ou fuso inválido no config.php) ou {"erro": "erro interno", "detalhe"}
 *   503 {"erro": "o banco está desatualizado: ...", "pendentes"}: só recurso=migracoes (e recurso=manual e recurso=codigo, que não usam o banco)
 *       responde até aplicar
 *   503 {"erro": "o banco está vazio: ...", "proximo_passo"}: antes da instalação só recurso=instalacao (e recurso=manual e recurso=codigo) responde
 *   503 {"erro": "o banco de dados não respondeu", "detalhe"}
 */
require_once __DIR__ . "/lib.php";
// os pedaços da API na pasta api/ só rodam dentro do api.php (abertos direto, não fazem nada)
define("RELOJOEIRO_API", true);

$formato = strtolower((string)($_REQUEST["formato"] ?? ""));
if ($formato === "" && stripos($_SERVER["HTTP_ACCEPT"] ?? "", "xml") !== false) {
    $formato = "xml";
}
if ($formato !== "xml") {
    $formato = "json";
}

require __DIR__ . "/api/funcoes.php";

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
// o idioma do pedido: lingua=, o cookie do seletor do menu, o padrão da instalação (a Configuração), o Accept-Language
$lingua_padrao = null;
if (!$banco_vazio) {
    try {
        $lingua_padrao = cfg("idioma");
    } catch (Throwable $e) {
        $lingua_padrao = null;
    }
}
lingua_definir(lingua_do_pedido($lingua_padrao));
// o que responde sem o banco (com ele vazio ou desatualizado): o manual, o código e os textos das telas, que só leem arquivos
$parte_ajuda = (string)($_REQUEST["parte"] ?? "");
$sem_banco = in_array($recurso, ["manual", "codigo", "textos"], true) || ($recurso === "ajuda" && in_array($parte_ajuda, ["manual", "codigo"], true));
if ($recurso === "textos") {
    // os textos das telas no idioma do pedido (o dicionário do t() do api.js), os idiomas e o locale. Responde sem login: é só
    // o texto da interface. formato=js: um script (window.TEXTOS_API = ...), que as páginas carregam antes dos outros, para que
    // todo t() já saia traduzido, até os de quando o script abre
    $l = LINGUAS[lingua_atual()];
    $saida = ["lingua" => lingua_atual(), "locale" => $l["locale"],
        "formato" => ["data" => $l["data"], "data_curta" => $l["data_curta"], "decimal" => $l["decimal"], "milhar" => $l["milhar"]],
        "padrao" => lingua_valida($lingua_padrao), "estrito" => textos_estrito(),
        "linguas" => array_map(function ($k) { return ["codigo" => $k, "nome" => LINGUAS[$k]["nome"], "locale" => LINGUAS[$k]["locale"]]; }, array_keys(LINGUAS)),
        "textos" => (object)textos_parte(lingua_atual(), "telas")];
    if (strtolower((string)($_REQUEST["formato"] ?? "")) === "js") {
        header("Content-Type: application/javascript; charset=utf-8");
        header("Cache-Control: private, max-age=" . (isset($_REQUEST["v"]) ? 86400 : 0));
        echo "window.TEXTOS_API = " . json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
        exit;
    }
} elseif (!$token_ok && $quem === "") {
    $codigo = 401;
    header("WWW-Authenticate: Basic realm=\"Relogios\", charset=\"UTF-8\"");
    $saida = ["erro" => t("falta autenticação: o token (cabeçalho X-Api-Token ou parâmetro token) ou o login do site (HTTP Basic)")];
    // sem nenhum usuário, o login não tem como dar certo: diz como criar o primeiro
    if ($banco_vazio || !banco_tem_tabela("usuario") || (int)valor("SELECT COUNT(*) FROM usuario") === 0) {
        $saida["sem_usuarios"] = true;
        $saida["como"] = $banco_vazio ? t("o banco está vazio: instale com o token (POST recurso=instalacao, acao=instalar) e crie o primeiro usuário (POST recurso=usuarios, acao=salvar, login e senha)")
            : t("nenhum usuário cadastrado: crie o primeiro com o token (POST recurso=usuarios, acao=salvar, login e senha) ou no servidor (php criar_usuario.php <login>)");
    }
} elseif ($envio_grande) {
    $codigo = 413;
    $saida = ["ok" => false, "mensagem" => "", "erros" => [t("O envio tem {1} MB e passou do limite do PHP do servidor (post_max_size = {2}). Envie um arquivo menor ou aumente o limite no php.ini.", [1 => str_replace(".", ",", (string)round((int)$_SERVER["CONTENT_LENGTH"] / 1048576, 1)), 2 => ini_get("post_max_size")])]];
} elseif ($recurso === "instalacao") {
    // a instalação: a situação (o banco, se está vazio e em dia, quantos usuários) e instalar, só num banco vazio
    if ($escrita && $acao === "instalar") {
        $saida = instalar_banco();
        $codigo = $saida["ok"] ? 200 : 400;
    } elseif ($escrita) {
        $codigo = 400;
        $saida = ["ok" => false, "mensagem" => "", "erros" => [t("Ação desconhecida: use instalar.")]];
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
    $saida = ["erro" => t("o banco está vazio: instale (POST recurso=instalacao, acao=instalar)"), "proximo_passo" => instalacao_situacao()["proximo_passo"]];
} elseif ($recurso === "migracoes") {
    require __DIR__ . "/api/migracoes.php";
} elseif ($banco_vazio && !$sem_banco) {
    $codigo = 503;
    $saida = ["erro" => t("o banco está vazio: instale (POST recurso=instalacao, acao=instalar)"), "proximo_passo" => instalacao_situacao()["proximo_passo"]];
} elseif (count($pendentes) > 0 && !$sem_banco) {
    // banco desatualizado: só as migrações respondem (e o manual e o código, que não usam o banco: a página Ajuda continua abrindo)
    $codigo = 503;
    $saida = ["erro" => t("o banco está desatualizado: aplique as migrações (POST recurso=migracoes, acao=aplicar)"), "pendentes" => array_map(function ($v, $m) {
        return ["versao" => $v, "arquivo" => $m[0], "traz" => $m[1]];
    }, array_keys($pendentes), array_values($pendentes))];
} elseif ($escrita) {
    require __DIR__ . "/api/escrita.php";
} elseif ($recurso === "importacao") {
    $saida = importacao_situacao();
} elseif ($recurso === "backup") {
    require __DIR__ . "/api/backup.php";
} elseif ($recurso === "foto") {
    $f = linha("SELECT tipo, dados FROM foto WHERE relogio_id = ?", [(int)($_REQUEST["relogio"] ?? 0)]);
    if ($f) {
        header("Content-Type: " . $f["tipo"]);
        header("Cache-Control: private, max-age=86400");
        echo $f["dados"];
        exit;
    }
    $codigo = 404;
    $saida = ["erro" => t("sem foto para esse relógio")];
} elseif ($recurso === "documento") {
    require __DIR__ . "/api/documento.php";
} elseif ($recurso === "documentos") {
    require __DIR__ . "/api/documentos.php";
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
    require __DIR__ . "/api/hoje.php";
} elseif ($recurso === "ficha") {
    require __DIR__ . "/api/ficha.php";
} elseif ($recurso === "config") {
    require __DIR__ . "/api/configuracao.php";
} elseif ($recurso === "historico") {
    require __DIR__ . "/api/historico.php";
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
    require __DIR__ . "/api/plano.php";
} elseif ($recurso === "desejos") {
    require __DIR__ . "/api/desejos.php";
} elseif ($recurso === "estatisticas") {
    require __DIR__ . "/api/estatisticas.php";
} elseif ($recurso === "hora_certa") {
    require __DIR__ . "/api/hora_certa.php";
} elseif ($recurso === "passaporte") {
    require __DIR__ . "/api/passaporte.php";
} elseif ($recurso === "galeria") {
    require __DIR__ . "/api/galeria.php";
} elseif ($recurso === "acessorios") {
    // os acessórios (de todos os relógios, ou de um: relogio=id; relogio=0, só os soltos), os tipos e os relógios (o menu)
    $rq_a = $_REQUEST["relogio"] ?? "";
    $lista_a = acessorios_lista(ctype_digit((string)$rq_a) && (int)$rq_a > 0 ? (int)$rq_a : null);
    if ((string)$rq_a === "0") {
        $lista_a = array_values(array_filter($lista_a, function ($a) { return $a["relogio_id"] === null; }));
    }
    $saida = ["acessorios" => $lista_a,
        "tipos" => array_map(function ($k) { return ["valor" => $k, "nome" => t(ACESSORIO_TIPOS[$k])]; }, array_keys(ACESSORIO_TIPOS)),
        "por_tipo" => array_values(array_filter(array_map(function ($k) use ($lista_a) {
            return ["tipo" => $k, "nome" => t(ACESSORIO_TIPOS[$k]), "quantos" => count(array_filter($lista_a, function ($a) use ($k) { return $a["tipo"] === $k; }))];
        }, array_keys(ACESSORIO_TIPOS)), function ($x) { return $x["quantos"] > 0; })),
        "soltos" => count(array_filter($lista_a, function ($a) { return $a["relogio_id"] === null; })),
        "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "posse" => posse_de($r)]; }, linhas("SELECT * FROM relogio ORDER BY nome"))];
} elseif ($recurso === "proximos") {
    // os Próximos dias da página Hoje, uma página (a troca de página não relê a página Hoje inteira)
    $saida = plano_pagina(date("Y-m-d"), "", null, (int)($_REQUEST["pagina"] ?? 1),
        ctype_digit((string)($_REQUEST["por_pagina"] ?? "")) ? (int)$_REQUEST["por_pagina"] : proximos_por_pagina());
} elseif ($recurso === "rodizio_manual") {
    // o rodízio manual: os dias do período (de, ate), de 7 em 7 (pagina), com o que cada um tem e a escolha de partida
    [$de_m, $ate_m, $erro_m] = rodizio_manual_periodo($_REQUEST["de"] ?? "", $_REQUEST["ate"] ?? "");
    if ($erro_m !== null) {
        $codigo = 400;
        $saida = ["erro" => $erro_m];
    } else {
        $saida = rodizio_manual_pagina($de_m, $ate_m, (int)($_REQUEST["pagina"] ?? 1));
    }
} elseif ($recurso === "eventos") {
    $saida = ["eventos" => array_map("evento_legivel", eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome")))];
} elseif ($recurso === "agenda") {
    $saida = ["ativa" => cfg("agenda_ativa") === "1", "agenda_id" => cfg("agenda_id"), "janela_dias" => (int)cfg("agenda_antecedencia"),
        "chave_ok" => cfg("agenda_id") !== "" ? google_token() !== false : null, "desejados" => agenda_legivel(),
        "criados" => linhas("SELECT chave, google_id, data, titulo, assinatura, criado FROM agenda_evento ORDER BY data, chave")];
} elseif ($recurso === "usuarios") {
    $saida = ["usuarios" => usuarios_catalogo(), "voce" => $quem];
} elseif ($recurso === "cron") {
    require __DIR__ . "/api/cron.php";
} elseif ($recurso === "arvore") {
    require __DIR__ . "/api/arvore.php";
} elseif ($recurso === "cadastros") {
    require __DIR__ . "/api/cadastros.php";
} elseif ($recurso === "calcular") {
    require __DIR__ . "/api/calcular.php";
} elseif ($recurso === "codigo" || ($recurso === "ajuda" && $parte_ajuda === "codigo")) {
    require __DIR__ . "/api/codigo.php";
} elseif ($recurso === "manual" || ($recurso === "ajuda" && ($_REQUEST["parte"] ?? "") === "manual")) {
    // o manual: o texto da página Ajuda (os trechos do README para quem usa), inteiro em markdown e seção por seção
    $md = manual_markdown();
    if ($md === "") {
        $codigo = 404;
        $saida = ["erro" => t("o README.md não está na pasta do sistema")];
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
    require __DIR__ . "/api/ajuda.php";
} elseif ($recurso === "") {
    require __DIR__ . "/api/tudo.php";
} else {
    $codigo = 404;
    $saida = ["erro" => t("recurso desconhecido"), "recursos" => ["(nenhum)", "autonomia", "hoje", "ficha", "config", "cron", "arvore", "cadastros", "calcular", "avisos", "criterios",
        "historico", "previsao", "plano", "proximos", "rodizio_manual", "desejos", "estatisticas", "hora_certa", "acessorios", "passaporte", "galeria", "textos", "eventos", "agenda", "foto", "documentos", "documento", "usuarios", "migracoes", "instalacao", "importacao", "entrar", "backup", "ajuda", "manual", "reconstrucao", "codigo"]];
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
                $info["ignorados"][] = t("{1}: não há lista nesse caminho", [1 => $caminho]);
            } else {
                $info["listas"][$caminho] = ["antes" => $conta["antes"], "passaram" => $conta["depois"], "devolvidos" => $conta["devolvidos"],
                    "pagina" => $regras["pagina"], "limite" => $regras["limite"] > 0 ? $regras["limite"] : null];
            }
        }
        // as partes da resposta: só as pedidas em incluir, menos as de excluir (a parte que não existe vai para os ignorados)
        if (!array_is_list($saida)) {
            foreach (array_merge($partes_pedidas["incluir"], $partes_pedidas["excluir"]) as $parte) {
                if (!array_key_exists($parte, $saida)) {
                    $info["ignorados"][] = t("{1}: a resposta não tem essa parte", [1 => $parte]);
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
