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
 *   Todo pedido, consulta ou escrita, exige o login do site (HTTP Basic: o usuário e a senha de uma conta), um token pessoal
 *   (Minha conta, Tokens de API: vale como o login do dono dele) ou o token do servidor (o API_TOKEN: vale como o master mais
 *   antigo), no cabeçalho X-Api-Token ou no parâmetro token:
 *     curl -H "X-Api-Token: segredo" "http://servidor/relojoeiro/api.php"
 *     http://servidor/relojoeiro/api.php?token=segredo
 *     curl -u lucas:senha "http://servidor/relojoeiro/api.php"
 *   Sem nenhum: 401. O token é comparado sem os espaços das pontas. A conta bloqueada, ou a de demonstração vencida, não entra.
 *   Só o cadastro aberto (recurso=cadastro), o link ICS (recurso=ics) e os textos das telas (recurso=textos) respondem sem login.
 *
 * ---------------------------------------------------------------------------------------------
 * COLEÇÕES
 * ---------------------------------------------------------------------------------------------
 *   Cada pedido vale numa coleção: colecao=<id>, senão o cookie colecao (o seletor do menu), senão a primeira que o usuário vê
 *   (a dele). A que ele não vê: 404. Tudo o que se lê e se grava é dessa coleção; a árvore de grupos e o cadastro dela (campos,
 *   tipos, fórmulas, avisos, categorias, critérios dos grupos) são da árvore que a coleção usa, comum a todas as coleções do
 *   mesmo dono que usam a mesma árvore. Sem coleção nenhuma, só a conta responde (409 com "sem_colecao" nos outros recursos).
 *   O master vê todas; com como=<login> (ou o cookie como, da faixa "Vendo como" das páginas) ele vê e grava como o outro.
 *   Toda exclusão é em dois passos: sem confirmar=1, nada sai, e a resposta (409) traz "confirmar": true e "impacto" (o que sai
 *   junto, em cascata, no banco inteiro); com confirmar=1, apaga, e os arquivos dos documentos saem do disco na hora.
 *   Toda gravação do recurso=rodizio que mexe no plano (menos acao=modo, que só marca o modo) responde também "mudancas": o
 *   que mudou do plano de hoje em diante em relação ao de antes (mudaram, iguais, texto e os dias, com antes, depois e o motivo).
 *   O relógio de hoje não se troca no próprio dia, a não ser que a Configuração deixe (trocar_hoje=1; o padrão é 0): desligado,
 *   o recurso=rodizio recusa usando, trocar_dia de hoje, resortear_hoje, sem_relogio, apagar_tudo com hoje=1, periodo começando
 *   hoje e manual que mude hoje, e o resortear vale de amanhã. As marcações do pulso continuam livres.
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
 *     "config":            {chave: valor}  toda a configuração da coleção
 *     "eventos":           [os eventos personalizados, como em recurso=eventos, com todos os disparos]
 *     "destinos":          [os destinos das mensagens, como em recurso=destinos, sem a prévia]
 *     "agendas":           [as agendas, como em recurso=agendas, com "criados": os eventos que o sistema criou em cada uma:
 *                          {"chave", "externo_id", "data", "titulo", "assinatura", "criado"}]
 *     "agendas_desejadas": [{"agenda_id", "agenda", "eventos": [o que tem de estar nela agora]}]
 *     "criterios":         os critérios inteiros, como em recurso=criterios: os conjuntos por lugar (parâmetros, subparâmetros,
 *                          faixas), as variáveis que podem medir, a nota de cada relógio com a conta, os lugares e os modos
 *     "cron":              {"ultima" (a última execução), "erro" (o erro guardado; vazio: sem erro), "execucoes": [todas as
 *                          guardadas, da mais recente: {"id", "inicio", "fim", "duracao_ms", "teve_atividade", "teve_erro", "registro"}]}
 *     "usuarios":          [{"login", "criado", ...}]  (o master vê todos, os outros a si mesmos; a senha nunca sai)
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
 *   recurso=documentos       [relogio=<id>; sem ele, de todos; ou calibre=<id>: os do calibre, que valem para todos os relógios
 *                            com ele] os documentos (o manual, a nota fiscal, fotos, vídeos, o manual técnico do calibre...):
 *                            {"relogio": {"id", "nome", "copia_banco"} ou null, "calibre": {"id", "nome", "fabricante", "relogios"}
 *                            ou null, "pasta_ok", "copia_sistema", "pasta_erro", "limite" (bytes; null: sem limite),
 *                            "categorias": [{"id", "identificador", "nome", "aceita", "ordem", "documentos"}], "relogios": [{"id",
 *                            "nome", "documentos"}], "calibres": [{"id", "nome", "documentos"}], "documentos": [{"id", "relogio_id",
 *                            "calibre_id", "categoria_id", "titulo", "data", "descricao", "nome", "tipo", "familia", "tamanho",
 *                            "miniatura", "criado", "url", "no_disco", "no_banco", "copia_banco", "copia_por", "nfe"}], "do_calibre"
 *                            (com relogio: o calibre dele com os documentos dele, {"id", "nome", "documentos"}; null sem)}
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
 *   recurso=precisao         [relogio=id] a precisão (a marcha) pelas leituras do desvio da hora e pelos acertos: de um relógio,
 *                            {"relogio_id", "relogio", "precisao": {"resumo", "medicoes", "dias", "marcha" (s/dia, + adianta: a média dos
 *                            intervalos pesada pelo tempo), "marcha_dp", "ultima", "desvio_agora" (s, + adiantado), "esperada": {"de",
 *                            "ate", "texto"}, "dentro", "por_posicao": [...], "pontos": [...], "intervalos": [...], "posicoes": [...]}}
 *                            (null sem o tipo que mede a marcha); sem relogio, {"relogios": [um resumo de cada relógio da coleção que
 *                            tem o tipo do desvio]}. Relógio que não existe: 404.
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=precisao&relogio=4"
 *   recurso=calibres         os calibres (o movimento: NH35, ST2130, B620...), da árvore: {"calibres": [{"id", "nome", "fabricante",
 *                            "link" (o manual técnico), "observacao" (as recomendações), "valores": {<identificador do campo>: valor},
 *                            "relogios": [{"id", "nome"}]}], "campos": [os campos que um calibre pode definir: {"id", "identificador",
 *                            "nome", "tipo", "unidade", "padrao", "opcoes", "grupo"}]}. O relógio com um calibre usa o valor dele
 *                            mesmo, senão o do calibre, senão o padrão do campo (a origem "calibre" nos dados da ficha e na previsão).
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=calibres"
 *   recurso=roteiro          roteiro (o texto: as linhas "nome = expressão") ou modo (o id: o roteiro efetivo dele), [dia: AAAA-MM-DD, de
 *                            hoje até um ano à frente; padrão hoje]: testa o roteiro de um modo de rodízio nesse dia, sem gravar (o
 *                            "Testar em todos os relógios" dos Modos de rodízio): {"dia", "dia_semana", "linhas": [{"nome", "expressao"}],
 *                            "erros": [...], "relogios": [{"id", "nome", "candidato", "nota", "peso", "dias", "garantia", "dias_sem_uso",
 *                            "valores": {<linha>: valor}}], "escolhido": {"relogio_id", "relogio", "motivo", "dias"} ou null}
 *                            Ex.: curl -u lucas:senha -G --data-urlencode "roteiro=candidato = NO_GRUPO(\"Tradicional\")" -d dia=2026-10-20 "http://servidor/relojoeiro/api.php?recurso=roteiro"
 *   recurso=rodizio_manual   de, ate (AAAA-MM-DD, de hoje em diante, até 2 anos), [pagina: 1, 2...]: o Rodízio manual (o quadro Modo de
 *                            rodízio da página Hoje): os dias do período de 7 em 7, cada um com o que o plano tem agora e a escolha de
 *                            partida do seletor (o relógio escolhido à mão, livre ou auto: Decida por mim): {"de", "ate", "total_dias",
 *                            "por_pagina", "pagina", "paginas", "dias": [{"data", "dia_semana", "dia_semana_nome", "hoje", "relogio_id",
 *                            "relogio", "origem", "motivo", "livre", "escolha"}], "relogios": [os disponíveis: {"id", "nome"}],
 *                            "decida_por_mim" (o critério), "formas": [as formas do "Sortear este dia por": {"chave", "nome"}]}.
 *                            Período inválido: 400 com "erro". Gravar: recurso=rodizio, acao=manual
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=rodizio_manual&de=2026-12-20&ate=2027-01-05&pagina=2"
 *   recurso=eventos          os eventos personalizados da coleção: {"eventos": [{"id", "nome", "ativo", "repeticao" (uma, diaria,
 *                            semanal, mensal, intervalo), "descricao" ("seg, qua 20:00"), "hora", "data_inicio", "dias_semana",
 *                            "dia_mes", "intervalo_dias", "relogio_id", "relogio", "destinos" e "agendas" (os nomes dos que recebem o
 *                            evento), "criado", "proximas" (as 10 próximas ocorrências, no próximo ano), "disparos": [{"ocorrencia",
 *                            "disparado"}]}]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=eventos"
 *   recurso=destinos         os destinos das mensagens (webhook, e-mail, Web Push): {"destinos": [{"id", "nome", "tipo", "tipo_nome", "ativo",
 *                            "modelo", "idioma", "recebe": [{"tipo", "nome", "quando", "envia", "modelo"}], "webhook" ({"metodo", "url",
 *                            "formato", "corpo_json", "campos", "cabecalhos": [{"nome", "preenchido"}]}), "email" ({"para"}), "webpush"
 *                            ({"usuario_id", "usuario", "aparelhos"}), "previa": {"manha", "noite"}}], "tipos", "avisos", "ancoras",
 *                            "linguas", "padrao", "email": {"configurado", "metodo"}, "push": {"configurado", "chave_publica"}, "usuarios"}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=destinos"
 *   recurso=agendas          as agendas (Google, CalDAV, Outlook, link ICS): {"agendas": [{"id", "nome", "tipo", "tipo_nome", "ativa",
 *                            "janela_dias", "modelo", "idioma", "recebe", "sincronizados", "teste_id", "google" ({"calendario_id", "chave",
 *                            "caminho", "conta_servico"}), "caldav" ({"url", "usuario", "senha_preenchida"}), "outlook" ({"conta",
 *                            "calendario_id", "conectada", "configurado", "retorno"}), "ics" ({"link"}), "previa": [eventos], "previstos"}],
 *                            "tipos", "avisos", "ancoras", "linguas", "padrao", "outlook": {"configurado", "retorno"}, "pasta"}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=agendas"
 *   recurso=ics chave=...    o calendário de uma agenda ICS (text/calendar), sem login, pela chave secreta do link
 *                            Ex.: curl "http://servidor/relojoeiro/api.php?recurso=ics&chave=Xy3..."
 *   recurso=outlook_retorno  a volta da Microsoft depois de Conectar ao Outlook (code, state): guarda a conexão e vai para Agendas (302)
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
 *                            "grupos": [{"id", "nome", "profundidade", "caminho"}], "avisos_nomes" (o filtro O que fazer, sem o Encostado), "estados" (o filtro Estado),
 *                            "relogios": [{"id", "nome", "disponivel", "tipo" (o grupo), "em_uso", "estado" (uso, sessao, repouso ou
 *                            indisponivel), "estado_texto" ("Em uso", "No sol", "No carregador", "Em repouso", "Indisponível"),
 *                            "estado_ordem", "agora" ("No sol desde 09:12"), "carga" (%), "carga_de" (de onde
 *                            vem), "ultimo" (a última vez no pulso, em segundos), "ultimo_txt", "situacao": [linhas], "manutencao":
 *                            {"nome", "data", "momento", "falta"} (o aviso de manutenção mais perto, sem o Encostado), "encostado":
 *                            {"data", "momento", "falta", "estado"} (o aviso Encostado; null sem data), "compra": {"data", "valor", "loja", "garantia_ate"},
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
 *                            "compra", "encostado": {"data", "momento", "falta", "estado"} (o aviso Encostado; null sem data), "manutencoes": [{"data", "nome"}] (as 5 próximas, sem o Encostado), "previsao" (como em recurso=previsao), "tipos": [os lançamentos
 *                            do relógio: {"identificador", "nome", "formato", "unidade", "aberta": {"inicio", "rodizio", "texto"}}],
 *                            "leituras": [as 40 últimas com valor: {"inicio", "valor", "unidade", "em_uso"}], "linha_do_tempo" (as 5
 *                            linhas mais recentes), "registros", "desde"}, "campos": [o cadastro: {"identificador", "nome", "tipo",
 *                            "unidade", "opcoes", "padrao", "no_id", "valor"}], "grupos": [{"id", "caminho", "cadeia"}]}. Sem relogio:
 *                            só "campos" e "grupos" (o cadastro de um relógio novo); relógio que não existe: 404
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=ficha&relogio=10"
 *   recurso=config           tudo o que a página Configuração mostra (da coleção): {"config": {chave: valor}, "linguas", "mostrar_ate":
 *                            {"proximos", "plano"} (quantos dias por página, já com o padrão), "aleatorio": {"fontes", "ultimo_lote",
 *                            "fora_desde"} (de onde vêm os números dos sorteios: RANDOM.ORG, senão ANU QRNG, senão o random_int), "sol_fim",
 *                            "sistema": {"url_sistema", "pode_mudar"} (o endereço do sistema: só o master muda), "repeticoes", "eventos":
 *                            [como em recurso=eventos, com "proximas_60" (as 5 dos próximos 60 dias)], "relogios": [{"id", "nome",
 *                            "disponivel"}], "destinos": [{"id", "nome", "tipo", "ativo"}], "agendas": [{"id", "nome", "tipo", "ativa"}],
 *                            "cron_estado": {"situacao" (nunca, parado ou rodando), "ultima", "minutos", "texto", "crontab", "erro",
 *                            "registro"}}
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
 *   recurso=usuarios         o master: o painel das contas: {"usuarios": [{"id", "login", "email", "papel" (master ou comum), "idioma",
 *                            "criado", "ultimo_acesso", "expira_em", "demo", "dias_restantes", "bloqueado", "colecoes": [{"id", "nome",
 *                            "relogios"}], "membro_de", "tudo_de", "pedidos", "tokens", "senha_hash", "senha_algoritmo", "senha_custo"}],
 *                            "voce", "demo_dias", "colecoes"}; os outros, só a si mesmos ({"login", "criado", "senha_hash"...}). O hash
 *                            serve para migrar os usuários com a mesma senha (veja SENHAS)
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=usuarios"
 *   recurso=conta            Minha conta: {"usuario", "vendo_como", "colecoes", "colecao", "papel", "arvores", "pedidos", "convites",
 *                            "aprovacoes", "tokens", "push": {"configurado", "chave_publica", "aparelhos"}, "email_configurado",
 *                            "smtp", "demo_dias", "linguas", "niveis"}; parte=menu: só o que o menu das páginas usa (o seletor da coleção,
 *                            a demonstração, as aprovações, a faixa Vendo como, o idioma padrão e o modo da interface: nivel e ocultos)
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=conta"
 *   recurso=colecoes         {"colecoes": [{"id", "nome", "dono_id", "dono", "papel", "no_id"}], "colecao", "arvores": [{"id", "nome",
 *                            "grupos", "colecoes"}]}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=colecoes"
 *   recurso=aprovacoes       {"pedidos", "convites_a_aprovar", "convites_feitos", "acessos": {"colecoes": [{"id", "nome", "membros"}],
 *                            "tudo"}, "minhas_colecoes", "pode_convidar", "email_configurado", "convite_dias"}
 *                            Ex.: curl -u lucas:senha "http://servidor/relojoeiro/api.php?recurso=aprovacoes"
 *   recurso=cadastro         (sem login) o cadastro aberto: {"aberto", "demo_dias", "email_configurado", "linguas", "convite"}
 *                            (convite=<token>: o convite do link). Nenhuma lista de contas nem de coleções: o pedido de acesso é
 *                            pelo código que o dono passou
 *                            Ex.: curl "http://servidor/relojoeiro/api.php?recurso=cadastro"
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
 *   recurso=manifest         o manifesto do aplicativo no celular (PWA), sem login; com o cookie do aparelho lembrado, o start_url já entra
 *   recurso=sw               o service worker do aplicativo (o sw.js com a versão e os arquivos das telas), sem login
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
 *   Resposta: {"ok", "mensagem", "erros": [...], "id"}; 400 quando recusado (os erros dizem por quê). Toda exclusão pede
 *   confirmar=1: sem ele, 409 com "confirmar": true e "impacto" (o que sai junto), e nada é apagado.
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
 *                             unidade, opcoes (lista: uma por linha), padrao, no_id (0 = todos), so_desejado, calibre (1: do
 *                             movimento, aparece no formulário do calibre); excluir (id); ordem (id, direcao)
 *                            Ex. novo: curl -u lucas:senha -d recurso=campos -d acao=novo -d identificador=resistencia_agua -d "nome=Resistência à água" -d tipo=inteiro -d unidade=m -d no_id=0 http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=campos -d acao=alterar -d id=21 -d "nome=Resistência (m)" http://servidor/relojoeiro/api.php
 *                            Ex. ordem: curl -u lucas:senha -d recurso=campos -d acao=ordem -d id=21 -d direcao=sobe http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=campos -d acao=excluir -d id=21 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=lancamento_tipos  novo, alterar (id): identificador, nome, formato (instantaneo, valor, sessao), unidade, fecha_as,
 *                             exclusiva (1 ou 0, só sessão), mede_gasto (1 ou 0, só com valor: cada leitura mede o gasto), marcha
 *                             (desvio: só com valor, a leitura do desvio da hora; acerto: só instantâneo, o acerto da hora), condicao
 *                             (vale quando: uma fórmula, 1 vale e 0 não; vazia, vale sempre; ex.: corda_manual), no_id;
 *                             excluir (id)
 *                            Ex. novo: curl -u lucas:senha -d recurso=lancamento_tipos -d acao=novo -d identificador=banho_ultrassom -d "nome=Banho ultrassônico" -d formato=instantaneo -d no_id=0 http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=lancamento_tipos -d acao=alterar -d id=11 -d fecha_as=18:00 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=lancamento_tipos -d acao=excluir -d id=11 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=formulas          nova (identificador, nome, expressao, unidade, no_id: fórmula nova, ou mais uma versão da que
 *                             existe, em outro ponto), alterar (id: nome, expressao, unidade, no_id), excluir (id)
 *                            Ex. nova: curl -u lucas:senha -d recurso=formulas -d acao=nova -d identificador=idade_dias -d "nome=Idade (dias)" --data-urlencode "expressao=AGORA() - data_compra" -d unidade=dias -d no_id=0 http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=formulas -d acao=alterar -d id=17 --data-urlencode "expressao=ARREDONDA(AGORA() - data_compra)" http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=formulas -d acao=excluir -d id=17 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=calibres          salvar (id: 0 cria; nome (até 80, sem repetir na árvore), fabricante, link (o manual técnico,
 *                             http:// ou https://), observacao (as recomendações), valores[identificador]: os valores que o
 *                             calibre define, vazio tira), excluir (id: os relógios dele ficam sem calibre)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=calibres -d acao=salvar -d id=0 -d nome=NH35 -d fabricante=Seiko -d "valores[reserva_horas]=41" http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=calibres -d acao=excluir -d id=3 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=relogio           salvar (id: 0 ou ausente cria; nome, no_id, disponivel, copia_banco (1: a cópia no banco de todos os
 *                             documentos dele; o cron copia ou tira aos poucos), calibre_id (o calibre dele; 0 ou vazio tira),
 *                             valores[identificador]: só muda o que vier; valor vazio apaga; inválido fica o que estava e a
 *                             mensagem diz), excluir (id),
 *                             foto (id, foto_base64), remover_foto (id)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=relogio -d acao=salvar -d id=10 -d "valores[preferencia]=80" -d disponivel=1 http://servidor/relojoeiro/api.php
 *                            Ex. foto: curl -u lucas:senha -d recurso=relogio -d acao=foto -d id=10 --data-urlencode foto_base64@foto.b64 http://servidor/relojoeiro/api.php
 *                            Ex. remover_foto: curl -u lucas:senha -d recurso=relogio -d acao=remover_foto -d id=10 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=relogio -d acao=excluir -d id=15 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=documentos        enviar (relogio_id, ou calibre_id: o documento do calibre, que vale para todos os relógios com
 *                             ele; categoria_id, titulo, data AAAA-MM-DD, descricao, copia_banco (1: pede a cópia
 *                             destes arquivos no banco) e o arquivo: arquivos[] como upload, vários de uma vez, ou
 *                             arquivo_base64 com arquivo_nome; a miniatura de uma foto, opcional: miniatura (upload) ou
 *                             miniatura_base64; título vazio: o nome do arquivo), alterar (id: categoria_id, titulo, data,
 *                             descricao, copia_banco; só muda o que vier; a cópia entra ou sai do banco na hora), excluir (id: o
 *                             documento, o arquivo e a cópia no banco). O arquivo vai para a pasta e, se a cópia é pedida (pelo
 *                             config.php, pelo relógio ou pelo próprio arquivo), também para o banco
 *                            Ex. enviar: curl -u lucas:senha -F recurso=documentos -F acao=enviar -F relogio_id=10 -F categoria_id=2 -F "arquivos[]=@nota.pdf" http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=documentos -d acao=alterar -d id=7 -d "titulo=No casamento" -d data=2026-09-20 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=documentos -d acao=excluir -d id=7 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=documento_categorias nova (identificador, nome, aceita[]: imagem, video, audio, pdf, xml; nenhum: qualquer
 *                             arquivo; ordem), alterar (id: nome, aceita[], ordem; o identificador não muda), excluir (id; só
 *                             sem documentos)
 *                            Ex. nova: curl -u lucas:senha -d recurso=documento_categorias -d acao=nova -d identificador=garantia -d nome=Garantia -d "aceita[]=pdf" http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=documento_categorias -d acao=excluir -d id=7 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=lancamento        lancar (relogio_id, tipo, valor, quando: AAAA-MM-DD HH:MM, vazio = agora; medir: 1 (padrão) ou 0,
 *                             num tipo que mede o gasto: com 1 a medição entra na média, com 0 fica só no histórico — a caixa
 *                             "Atualizar o gasto com esta medição"; a mensagem traz a conta), iniciar (relogio_id,
 *                             tipo, quando: abre a sessão; exclusiva fecha a outra exclusiva aberta), encerrar (relogio_id, tipo,
 *                             quando), periodo (relogio_id, tipo, inicio (quando também vale), fim: uma sessão que já passou),
 *                             alterar (id: quando ou inicio, fim, valor; só o que vier), excluir (id), alternar (relogio_id, tipo
 *                             (sessão), quando: fecha a sessão se ela está aberta, abre se está fechada: um botão só num atalho de
 *                             celular). Em todas, relogio_id=hoje é o relógio do dia, pelo plano. Nada no futuro; o tipo tem de
 *                             valer para o relógio; exclusivas não se sobrepõem. Um intervalo entre duas leituras com outra sessão
 *                             no meio (o carregador) não mede o gasto. No tipo do desvio da hora: valor (s, + adiantado, até 6 h) ou
 *                             marca (a hora que o relógio mostra, HH:MM:SS, contra a hora certa no instante instante_ms, em ms, de até
 *                             10 min atrás; sem ele, agora), posicao (onde ele ficou desde a leitura anterior: misto, pulso,
 *                             mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda, coroa_direita, winder); a
 *                             mensagem diz a marcha desde a anterior. O acerto da hora zera o desvio e recomeça a conta
 *                            Ex. alternar: curl -H "X-Api-Token: ..." -d recurso=lancamento -d acao=alternar -d relogio_id=hoje -d tipo=pulso http://servidor/relojoeiro/api.php
 *                            Ex. lancar: curl -u lucas:senha -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 http://servidor/relojoeiro/api.php
 *                            Ex. iniciar: curl -u lucas:senha -d recurso=lancamento -d acao=iniciar -d relogio_id=4 -d tipo=sol http://servidor/relojoeiro/api.php
 *                            Ex. encerrar: curl -u lucas:senha -d recurso=lancamento -d acao=encerrar -d relogio_id=4 -d tipo=sol http://servidor/relojoeiro/api.php
 *                            Ex. periodo: curl -u lucas:senha -d recurso=lancamento -d acao=periodo -d relogio_id=3 -d tipo=winder -d "inicio=2026-09-26 20:00" -d "fim=2026-09-27 07:00" http://servidor/relojoeiro/api.php
 *                            Ex. alterar: curl -u lucas:senha -d recurso=lancamento -d acao=alterar -d id=42 -d valor=70 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=lancamento -d acao=excluir -d id=42 -d confirmar=1 http://servidor/relojoeiro/api.php
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
 *                            Ex. excluir: curl -u lucas:senha -d recurso=avisos -d acao=excluir -d id=9 -d confirmar=1 http://servidor/relojoeiro/api.php
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
 *   recurso=rodizio           modo (o quadro Modo de rodízio: modo (id), selecao (o critério de escolha, só nos tipos semana,
 *                             semana_fds e dia_semana), max_sem_uso (a garantia de rodízio): grava os dois e marca o modo; o plano
 *                             gravado continua; os blocos, as semanas e o roteiro se editam em recurso=modos),
 *                             resortear (de amanhã até domingo, ou até o fim da próxima semana, se ela já foi montada; se o dia
 *                             ainda não começou no pulso e o relógio de hoje se troca no próprio dia, também hoje), resortear_hoje
 *                             (inclusive hoje: o sorteado passa a ser o do pulso a partir de agora), usando (relogio_id: o
 *                             relógio de hoje a partir de agora; motivo, opcional: a frase que fica no plano), trocar_dia (data,
 *                             relogio_id: o relógio daquele dia, à mão; hoje, como o usando; 0: o dia volta a ser sorteado;
 *                             sortear:<forma>, inteligente, ponderado, aleatorio ou fifo: o dia é sorteado agora por essa forma,
 *                             entre os disponíveis do bloco do modo ativo que cobre o dia, sem o que o dia tinha, e fica à mão
 *                             com o motivo "Sorteado à mão (<forma>): ..."), proxima_semana (em qualquer dia: a semana
 *                             seguinte inteira, com os dias que faltam desta já contados como uso), periodo (de, ate: AAAA-MM-DD, de hoje até 2 anos; relogio_ids[]: um ou vários, na
 *                             ordem; dias_cada (padrão 1): os dias do período ficam escolhidos à mão, cada relógio dias_cada dias
 *                             seguidos, e depois do último volta ao primeiro), periodo_sortear (de, ate, um dos dois pode faltar: sem
 *                             o de, de amanhã; sem o ate, até o último dia do plano; os dias do período, de amanhã em diante, à mão,
 *                             sorteados ou livres, saem do plano e o modo monta esses dias de novo), periodo_livre (de, ate, idem:
 *                             tudo sai e os dias ficam livres: sem relógio e sem sorteio, para escolher à mão; trocar_dia ou periodo
 *                             num dia livre o tira de lá), manual (o Rodízio manual: de, ate; dias[AAAA-MM-DD]: o id do relógio,
 *                             auto (Decida por mim: o sistema decide ao gravar, pelo critério do recurso=rodizio_manual),
 *                             sortear:<forma> (o dia sorteado ao gravar por essa forma: inteligente, ponderado, aleatorio ou fifo) ou livre
 *                             (hoje não); o dia que não vem fica com a escolha de partida (dias[].escolha); tudo fica gravado à mão,
 *                             com o motivo; hoje, com outro relógio, ele passa a ser o do pulso a partir de agora; hoje em auto
 *                             continua o que está; fora do período, vale o modo ativo), apagar_tudo (toda a programação de
 *                             amanhã em diante, escolhida à mão, sorteada e livre, sai; hoje=1, "Excluindo hoje": de hoje em
 *                             diante; o modo ativo monta o plano de novo; hoje, se mudar, o novo vai para o pulso a partir de agora). Os dias escolhidos à mão e os livres, de
 *                             amanhã em diante, ficam quando se sorteia de novo ou se monta a próxima semana.
 *                             Na escala inteligente: sortear de novo refaz a escala; usando segura o escolhido nos dias que
 *                             faltavam do bloco de hoje e refaz a escala depois deles; proxima_semana é recusada
 *                            Ex. modo: curl -u lucas:senha -d recurso=rodizio -d acao=modo -d modo=2 -d selecao=ponderado -d max_sem_uso=21 http://servidor/relojoeiro/api.php
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
 *   recurso=modos             salvar (id: 0 cria; nome; tipo: semana, semana_fds, dia_semana, aleatorio, escala ou personalizado
 *                             (sem ele, inferido dos blocos); selecao: inteligente, ponderado, aleatorio ou fifo (o aleatório força
 *                             aleatorio; a escala e o personalizado não a usam); escala_dias: 7 a 730, só na escala; semanas: 1 a 8,
 *                             quantas semanas à frente o plano vem montado (não na escala); blocos: [{nome, dias: [1..7], no_id (0:
 *                             todos), um_por: bloco ou dia, relogio_id: fixo (0: sorteia)}], substitui os blocos (a forma deles é
 *                             conferida pelo tipo); roteiro: as linhas "nome = expressão" do personalizado (conferidas como no
 *                             recurso=roteiro; os outros tipos geram o roteiro dos blocos); ciclo: 1 ou 0 (padrão): com o ciclo, um
 *                             relógio só volta depois que todos os candidatos passaram na semana), ativar (id: só marca o modo),
 *                             excluir (id: não o ativo)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=modos -d acao=salvar -d id=0 -d "nome=Só smartwatch" -d selecao=inteligente -d "blocos[0][nome]=Todo dia" -d "blocos[0][dias][]=1" -d "blocos[0][dias][]=7" -d "blocos[0][no_id]=1" -d "blocos[0][um_por]=dia" http://servidor/relojoeiro/api.php
 *                            Ex. ativar: curl -u lucas:senha -d recurso=modos -d acao=ativar -d id=5 http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=modos -d acao=excluir -d id=6 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=config            salvar (horario_manha, horario_noite, uso_inicio, uso_fim, sol_fim (a sessão no sol esquecida fecha
 *                             a essa hora): HH:MM; pulso_auto_inicio e pulso_auto_fim (1 ou 0: pôr no pulso sozinho no
 *                             início do horário de uso, tirar sozinho no fim; 0: só pelo Pôs, só pelo Tirou); carga_limiar (1 a 99: carregar nessa carga, o geral); sol_limiar (1 a 99: o
 *                             solar vai para o sol nessa carga); painel_modo (lado: o relógio abre no painel ao lado da
 *                             lista; flutuante: numa janela grande por cima da página); destaque_hoje (dia: o destaque no alto da
 *                             página Hoje é o relógio do dia; pulso: o relógio no pulso agora); proximos_por_pagina e plano_por_pagina (1 a
 *                             366: o "Mostrar até", quantos dias os Próximos dias da página Hoje e o dia a dia da página Plano
 *                             mostram por página; padrão 7 e 31); idioma (o da coleção); max_sem_uso (0 a 365); previsao_limite (0 a
 *                             100); medicao_janela_dias (1 a 3650: a média do gasto medido usa as medições destes últimos dias);
 *                             encostado_dias; trocar_hoje (1 ou 0: o relógio de hoje se troca no próprio dia, ou só a partir de
 *                             amanhã; vazio: 0); calibre_campos (movimento ou todos: os campos do formulário do calibre); url_sistema
 *                             (do sistema inteiro: só o master); só o que vier), evento_salvar (evento_id
 *                             ou id: 0 cria; nome, ativo, repeticao, hora, data_inicio, dias_semana, dia_mes, intervalo_dias,
 *                             relogio_id; o evento novo já vai para todos os destinos), evento_excluir (evento_id ou id, confirmar=1)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=config -d acao=salvar -d horario_manha=06:30 -d carga_limiar=25 http://servidor/relojoeiro/api.php
 *                            Ex. evento_salvar: curl -u lucas:senha -d recurso=config -d acao=evento_salvar -d evento_id=0 -d "nome=Limpar as pulseiras" -d repeticao=semanal -d "dias_semana[]=1" -d "dias_semana[]=4" -d hora=20:00 http://servidor/relojoeiro/api.php
 *                            Ex. evento_excluir: curl -u lucas:senha -d recurso=config -d acao=evento_excluir -d evento_id=3 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=destinos          salvar (id: 0 cria; tipo (webhook, email, webpush; só ao criar), nome, ativo, idioma, modelo, envia[] (os
 *                             tipos que recebe), modelo_<tipo>; webhook: metodo, url, formato (query, form, json), campo_nome[] e
 *                             campo_valor[], corpo_json, cabecalho_nome[] e cabecalho_valor[] (cifrados); email: para; webpush:
 *                             usuario_id), testar (id, qual: texto, manha ou noite), excluir (id, confirmar=1)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=destinos -d acao=salvar -d id=0 -d tipo=email -d "nome=E-mail" -d para=eu@exemplo.com http://servidor/relojoeiro/api.php
 *                            Ex. testar: curl -u lucas:senha -d recurso=destinos -d acao=testar -d id=1 -d qual=manha http://servidor/relojoeiro/api.php
 *   recurso=agendas           salvar (id: 0 cria; tipo (google, caldav, outlook, ics; só ao criar), nome, ativa, janela_dias, idioma,
 *                             modelo, envia[]; google: calendario_id, chave_json; caldav: url, usuario, senha; outlook: calendario_id),
 *                             sincronizar (id), teste_criar (id), teste_remover (id), novo_link (id: ICS), outlook_conectar (id:
 *                             devolve url, a página da Microsoft), excluir (id, confirmar=1)
 *                            Ex. salvar: curl -u lucas:senha -d recurso=agendas -d acao=salvar -d id=0 -d tipo=ics -d "nome=Celular" http://servidor/relojoeiro/api.php
 *                            Ex. sincronizar: curl -u lucas:senha -d recurso=agendas -d acao=sincronizar -d id=1 http://servidor/relojoeiro/api.php
 *   recurso=usuarios          salvar (login, senha; ou login e senha_hash, o hash de outro sistema: veja SENHAS; a conta nova, só o
 *                             master: email, idioma, colecao_nova: modelo, vazia ou nenhuma). Só o master: alterar (usuario_id ou login;
 *                             novo_login, email, idioma, papel), oficializar, prorrogar (dias), voltar_demo (dias), bloquear (bloqueado
 *                             1 ou 0), transferir (colecao_id, para: o novo dono), dar_acesso (colecao_id ou dono_id), excluir (login,
 *                             confirmar=1). O primeiro usuário sai pelo token (sem usuários, o login não entra) e é o master
 *                            Ex. salvar: curl -u lucas:senha -d recurso=usuarios -d acao=salvar -d login=visitante -d senha=segredo123 http://servidor/relojoeiro/api.php
 *                            Ex. oficializar: curl -u lucas:senha -d recurso=usuarios -d acao=oficializar -d login=visitante http://servidor/relojoeiro/api.php
 *                            Ex. excluir: curl -u lucas:senha -d recurso=usuarios -d acao=excluir -d login=visitante -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=conta             alterar (email, idioma, nivel, senha_atual e senha), token_criar (nome: devolve o token, só agora), token_revogar
 *                             (id), aparelho_lembrar (nome: o aplicativo no celular entra sem a senha por este aparelho, pelo cookie que
 *                             grava), aparelho_esquecer (id; vazio: este), push_inscrever (endpoint, p256dh, auth, aparelho), push_cancelar (id), push_testar,
 *                             smtp_salvar (o servidor de e-mail próprio: proprio=0 volta ao do sistema; host, porta, seguranca, usuario, senha,
 *                             remetente), smtp_testar (para), excluir (confirmar=1: a conta com tudo); só o master: email_testar (para), vapid_gerar
 *                            Ex. token_criar: curl -u lucas:senha -d recurso=conta -d acao=token_criar -d "nome=Atalho" http://servidor/relojoeiro/api.php
 *   recurso=colecoes          criar (nome, opcao: modelo, vazia ou arvore com arvore_id), renomear (id, nome), excluir (id, confirmar=1),
 *                             arvore_copiar (arvore_id, nome), arvore_renomear (arvore_id, nome), arvore_excluir (arvore_id, confirmar=1)
 *                            Ex. criar: curl -u lucas:senha -d recurso=colecoes -d acao=criar -d "nome=Do meu pai" -d opcao=modelo http://servidor/relojoeiro/api.php
 *   recurso=acessos           remover (usuario_id; colecao_id ou dono_id; confirmar=1: o dono tira o acesso de alguém), sair (colecao_id
 *                             ou dono_id, confirmar=1)
 *                            Ex. sair: curl -u maria:senha -d recurso=acessos -d acao=sair -d colecao_id=1 -d confirmar=1 http://servidor/relojoeiro/api.php
 *   recurso=convites          convidar (email; colecao_id ou dono_id), aprovar (id), recusar (id), reenviar (id), cancelar (id), aceitar
 *                             (token: quem já tem conta)
 *                            Ex. convidar: curl -u lucas:senha -d recurso=convites -d acao=convidar -d email=amigo@exemplo.com -d colecao_id=1 http://servidor/relojoeiro/api.php
 *   recurso=pedidos           pedir (colecao_id ou dono_id: o código que o dono passou, nunca o login; mensagem), aprovar (id),
 *                             recusar (id), cancelar (id)
 *                            Ex. pedir: curl -u maria:senha -d recurso=pedidos -d acao=pedir -d dono_id=1 http://servidor/relojoeiro/api.php
 *   recurso=cadastro          (sem login) criar_conta (login, senha, email, idioma, opcao: modelo, vazia ou pedir (colecao_id ou dono_id,
 *                             mensagem); convite: o token do link), esqueci_senha (email ou login), nova_senha (token, senha)
 *                            Ex. criar_conta: curl -d recurso=cadastro -d acao=criar_conta -d login=maria -d senha=segredo123 -d email=maria@exemplo.com -d opcao=modelo http://servidor/relojoeiro/api.php
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
 *     listas                         as listas que são cadastro, cada uma com os itens, na ordem (como em recurso=listas)
 *     listas.relogio_situacao        os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.relogio_situacao[]      um item da lista
 *     listas.relogio_situacao[].valor
 *                                    o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.relogio_situacao[].nome o nome do item
 *     listas.relogio_situacao[].id   o número do item (o id da gravação, recurso=listas)
 *     listas.relogio_situacao[].ordem
 *                                    a ordem na lista
 *     listas.relogio_situacao[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.relogio_situacao[].texto
 *                                    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.relogio_situacao[].cor  a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas.acessorio_tipo          os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.acessorio_tipo[]        um item da lista
 *     listas.acessorio_tipo[].valor  o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.acessorio_tipo[].nome   o nome do item
 *     listas.acessorio_tipo[].id     o número do item (o id da gravação, recurso=listas)
 *     listas.acessorio_tipo[].ordem  a ordem na lista
 *     listas.acessorio_tipo[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.acessorio_tipo[].texto  o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.acessorio_tipo[].cor    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas.reserva_protocolo       os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.reserva_protocolo[]     um item da lista
 *     listas.reserva_protocolo[].valor
 *                                    o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.reserva_protocolo[].nome
 *                                    o nome do item
 *     listas.reserva_protocolo[].id  o número do item (o id da gravação, recurso=listas)
 *     listas.reserva_protocolo[].ordem
 *                                    a ordem na lista
 *     listas.reserva_protocolo[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.reserva_protocolo[].texto
 *                                    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.reserva_protocolo[].cor a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas.reserva_observacao      os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.reserva_observacao[]    um item da lista
 *     listas.reserva_observacao[].valor
 *                                    o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.reserva_observacao[].nome
 *                                    o nome do item
 *     listas.reserva_observacao[].id o número do item (o id da gravação, recurso=listas)
 *     listas.reserva_observacao[].ordem
 *                                    a ordem na lista
 *     listas.reserva_observacao[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.reserva_observacao[].texto
 *                                    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.reserva_observacao[].cor
 *                                    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas.manutencao_situacao     os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.manutencao_situacao[]   um item da lista
 *     listas.manutencao_situacao[].valor
 *                                    o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.manutencao_situacao[].nome
 *                                    o nome do item
 *     listas.manutencao_situacao[].id
 *                                    o número do item (o id da gravação, recurso=listas)
 *     listas.manutencao_situacao[].ordem
 *                                    a ordem na lista
 *     listas.manutencao_situacao[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.manutencao_situacao[].texto
 *                                    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.manutencao_situacao[].cor
 *                                    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas.servico_tipo            os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.servico_tipo[]          um item da lista
 *     listas.servico_tipo[].valor    o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.servico_tipo[].nome     o nome do item
 *     listas.servico_tipo[].id       o número do item (o id da gravação, recurso=listas)
 *     listas.servico_tipo[].ordem    a ordem na lista
 *     listas.servico_tipo[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.servico_tipo[].texto    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.servico_tipo[].cor      a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas.estanqueidade           os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.estanqueidade[]         um item da lista
 *     listas.estanqueidade[].valor   o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.estanqueidade[].nome    o nome do item
 *     listas.estanqueidade[].id      o número do item (o id da gravação, recurso=listas)
 *     listas.estanqueidade[].ordem   a ordem na lista
 *     listas.estanqueidade[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.estanqueidade[].texto   o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.estanqueidade[].cor     a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas.oficina_tipo            os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.oficina_tipo[]          um item da lista
 *     listas.oficina_tipo[].valor    o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.oficina_tipo[].nome     o nome do item
 *     listas.oficina_tipo[].id       o número do item (o id da gravação, recurso=listas)
 *     listas.oficina_tipo[].ordem    a ordem na lista
 *     listas.oficina_tipo[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.oficina_tipo[].texto    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.oficina_tipo[].cor      a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas.contato_tipo            os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas.contato_tipo[]          um item da lista
 *     listas.contato_tipo[].valor    o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas.contato_tipo[].nome     o nome do item
 *     listas.contato_tipo[].id       o número do item (o id da gravação, recurso=listas)
 *     listas.contato_tipo[].ordem    a ordem na lista
 *     listas.contato_tipo[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas.contato_tipo[].texto    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas.contato_tipo[].cor      a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     manutencoes                    as manutenções da coleção, da mais nova para a mais velha (como em recurso=manutencao)
 *     manutencoes[]                  uma manutenção
 *     manutencoes[].id               o número da manutenção
 *     manutencoes[].relogio_id       o relógio
 *     manutencoes[].relogio          o nome do relógio
 *     manutencoes[].oficina_id       a oficina (Manutenção › Oficinas)
 *     manutencoes[].oficina          o nome da oficina
 *     manutencoes[].situacao         a situação agora: o identificador de um item da lista Situações da manutenção (Manutenção › Listas:
 *                                    enviado, orcamento, aprovado, peca, pronto, retirado...)
 *     manutencoes[].situacao_texto   a situação por extenso
 *     manutencoes[].situacao_comportamento
 *                                    o que a situação faz: aberta (o relógio na oficina), encerra (voltou e virou serviço) ou cancela
 *                                    (voltou sem serviço)
 *     manutencoes[].situacao_cor     a cor da situação (#rrggbb); null: sem
 *     manutencoes[].aviso            aberta: o aviso da situação de agora (Aprovar o orçamento, Retirar na oficina); null: sem aviso
 *     manutencoes[].aberta           true com o relógio na oficina agora (a situação faz aberta)
 *     manutencoes[].tipo             o tipo de serviço pedido: o identificador de um item da lista Tipos de serviço
 *     manutencoes[].tipo_texto       o tipo de serviço por extenso
 *     manutencoes[].problema         o problema (o que se pediu à oficina); null: sem
 *     manutencoes[].os               o número da ordem de serviço da oficina; null: sem
 *     manutencoes[].enviado          o dia do envio (AAAA-MM-DD)
 *     manutencoes[].previsao         o dia previsto para a volta (AAAA-MM-DD); null: sem
 *     manutencoes[].atrasada         true se ela está aberta e a previsão já passou (o aviso Cobrar a oficina)
 *     manutencoes[].dias             quantos dias o relógio ficou (ou está) na oficina: do envio à volta, ou até hoje
 *     manutencoes[].orcamento        o orçamento, em R$; null: sem
 *     manutencoes[].orcamento_data   o dia do orçamento (AAAA-MM-DD); null: sem
 *     manutencoes[].encerrado        o dia da volta (AAAA-MM-DD, numa situação que encerra ou cancela); null: aberta
 *     manutencoes[].disponivel_antes a disponibilidade para o rodízio com que o relógio volta (a de antes do envio, ou a pedida durante a
 *                                    manutenção)
 *     manutencoes[].observacao       a observação; null: sem
 *     manutencoes[].desde            quando ela entrou na situação de agora (a última mudança da história)
 *     manutencoes[].eventos          a história: cada mudança de situação, da mais velha para a mais nova
 *     manutencoes[].eventos[]        uma mudança
 *     manutencoes[].eventos[].situacao
 *                                    o identificador da situação
 *     manutencoes[].eventos[].situacao_texto
 *                                    a situação por extenso
 *     manutencoes[].eventos[].quando quando (texto AAAA-MM-DD HH:MM:SS)
 *     manutencoes[].eventos[].observacao
 *                                    a observação da mudança; null: sem
 *     manutencoes[].acessorios       o que foi junto (os acessórios da coleção)
 *     manutencoes[].acessorios[]     um acessório
 *     manutencoes[].acessorios[].id  o número do acessório
 *     manutencoes[].acessorios[].nome
 *                                    o nome do acessório
 *     manutencoes[].acessorios[].tipo_texto
 *                                    o tipo do acessório por extenso
 *     manutencoes[].documentos       os documentos do envio (os do relógio: a OS, o orçamento, as fotos da entrega)
 *     manutencoes[].documentos[]     um documento
 *     manutencoes[].documentos[].id  o número do documento
 *     manutencoes[].documentos[].titulo
 *                                    o título
 *     manutencoes[].documentos[].url o endereço do arquivo (recurso=documento)
 *     manutencoes[].servicos         os serviços que a volta registrou (recurso=servicos)
 *     manutencoes[].servicos[]       um serviço
 *     manutencoes[].servicos[].id    o número do serviço
 *     manutencoes[].servicos[].data  a data do serviço (AAAA-MM-DD)
 *     manutencoes[].servicos[].tipo_texto
 *                                    o tipo do serviço por extenso
 *     manutencoes[].servicos[].custo o custo do serviço (a mão de obra mais as peças), em R$; null: nada com custo
 *     manutencoes[].custo            o custo da manutenção: os serviços dela somados, em R$; null: nada com custo
 *     manutencoes[].criado           quando foi cadastrada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     oficinas                       as oficinas da coleção, por nome (como em recurso=oficinas)
 *     oficinas[]                     uma oficina
 *     oficinas[].id                  o número da oficina
 *     oficinas[].nome                o nome da oficina
 *     oficinas[].tipo                o tipo: o identificador de um item da lista Tipos de oficina (Manutenção › Listas)
 *     oficinas[].tipo_texto          o tipo por extenso
 *     oficinas[].propria             true na sua oficina (o tipo faz "você mesmo, em casa": a Eu mesmo (em casa))
 *     oficinas[].endereco            o endereço (a rua); null: sem
 *     oficinas[].numero              o número; null: sem
 *     oficinas[].complemento         o complemento; null: sem
 *     oficinas[].bairro              o bairro; null: sem
 *     oficinas[].cidade              a cidade; null: sem
 *     oficinas[].uf                  o estado; null: sem
 *     oficinas[].cep                 o CEP; null: sem
 *     oficinas[].endereco_texto      o endereço inteiro numa linha; null: sem endereço
 *     oficinas[].horario             o horário de atendimento; null: sem
 *     oficinas[].observacao          a observação; null: sem
 *     oficinas[].contatos            os contatos da oficina, o principal primeiro
 *     oficinas[].contatos[]          um contato
 *     oficinas[].contatos[].id       o número do contato
 *     oficinas[].contatos[].tipo     o tipo do contato: o identificador de um item da lista Tipos de contato (Manutenção › Listas:
 *                                    telefone, celular, whatsapp, email, site...)
 *     oficinas[].contatos[].tipo_texto
 *                                    o tipo do contato por extenso
 *     oficinas[].contatos[].comportamento
 *                                    o que o tipo faz: telefone, whatsapp, email, link ou texto
 *     oficinas[].contatos[].valor    o número, o e-mail ou o endereço
 *     oficinas[].contatos[].pessoa   com quem falar; null: sem
 *     oficinas[].contatos[].observacao
 *                                    a observação do contato; null: sem
 *     oficinas[].contatos[].principal
 *                                    true no contato principal
 *     oficinas[].contatos[].link     o endereço que abre o contato pelo que o tipo faz (tel:, o wa.me do WhatsApp, mailto:, o site); null:
 *                                    o texto
 *     oficinas[].abertas             quantos relógios estão nela agora
 *     oficinas[].manutencoes         quantas manutenções ela teve (todas)
 *     oficinas[].gasto               quanto ela custou: os serviços das manutenções dela somados, em R$; null: nada com custo
 *     unidades                       as unidades da árvore (Cadastros › Unidades), na ordem do cadastro
 *     unidades[]                     uma unidade
 *     unidades[].id                  o número da unidade
 *     unidades[].simbolo             o símbolo (o que vai depois do número: mm, h, R$); o que o campo, o tipo de lançamento e a fórmula
 *                                    guardam
 *     unidades[].nome                o nome por extenso, no singular (milímetro)
 *     unidades[].base                o símbolo da unidade base, a da mesma grandeza em que esta se converte; null: esta é uma base
 *     unidades[].fator               com base: quanto vale 1 desta na base (a polegada: 25,4); null na base
 *     unidades[].zero                o valor desta que é o zero da base (os 32 do °F); 0 nas outras: na base = (valor - zero) × fator
 *     unidades[].mostrar_em          o símbolo da unidade em que o valor também aparece (na ficha e no passaporte); null: nenhuma
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
 *     calibres                       os calibres da árvore, pelo nome (o recurso=calibres)
 *     calibres[]                     um calibre (o movimento)
 *     calibres[].id                  o número do calibre
 *     calibres[].nome                o nome (NH35, ST2130...)
 *     calibres[].fabricante          o fabricante; null: não informado
 *     calibres[].link                o link do manual técnico; null: sem
 *     calibres[].observacao          as recomendações (texto); null: sem
 *     calibres[].valores             os valores dos campos que ele define, pelo identificador do campo
 *     calibres[].valores.<identificador>
 *                                    o valor do campo no calibre (texto normalizado: número com ponto, data AAAA-MM-DD, sim/não 1 ou 0)
 *     calibres[].relogios            os relógios da coleção que apontam para ele, por nome
 *     calibres[].relogios[]          um relógio
 *     calibres[].relogios[].id       o número do relógio
 *     calibres[].relogios[].nome     o nome do relógio
 *     calibres[].documentos          quantos documentos o calibre tem (a página Documentos do calibre)
 *     calibres[].campos_no_formulario
 *                                    os campos que o formulário deste calibre mostra, pelo identificador, na ordem do cadastro: os da
 *                                    Configuração (calibre_campos) e, com só os do movimento, também os que ele já define
 *     calibres[].campos_no_formulario[]
 *                                    o identificador de um campo
 *     campos[]                       os campos do cadastro dos relógios (cada um vale para o grupo dele e tudo abaixo)
 *     campos[].id                    o número do campo
 *     campos[].identificador         o nome do campo nas fórmulas e nos critérios
 *     campos[].lugar                 esse grupo por extenso
 *     campos[].no_id                 o grupo em que o campo vale; null: todos os relógios
 *     campos[].nome                  o nome do campo
 *     campos[].opcoes                os valores possíveis, um por linha (a lista tem sempre; o texto e o número, quando o campo tem: o
 *                                    valor tem de ser um deles; vazio: qualquer um)
 *     campos[].ordem                 a posição do campo no cadastro
 *     campos[].secao_id              a divisória da ficha em que o campo fica (secoes[].id); null: nenhuma, no fim da ficha
 *     campos[].minimo                o menor valor que o número aceita; null: sem limite
 *     campos[].maximo                o maior valor que o número aceita; null: sem limite
 *     campos[].multiplo              verdadeiro: o campo é múltiplo (vários dos valores possíveis de uma vez, guardados um por linha; o
 *                                    valor vem com um por linha)
 *     campos[].alvo                  de quem é o campo: relogio (o cadastro do relógio), acessorio (o cadastro do acessório, na página
 *                                    Acessórios), winder (o do winder) ou posicao (o da posição do winder, na página Winders); a lista traz
 *                                    os dos relógios e depois os dos acessórios, dos winders e das posições
 *     campos[].acessorio_tipo        no campo de acessório, o tipo de acessório em que ele vale (pulseira, caixa...); null: todos (e nos
 *                                    dos relógios)
 *     campos[].serve_campo_id        no campo de acessório, o campo do relógio (campos[].id) com que ele tem de bater para o acessório
 *                                    servir no relógio (a largura da pulseira com o entre alças); null: nenhum
 *     secoes                         as divisórias da ficha (Cadastros › Divisórias), na ordem
 *     secoes[]                       uma divisória
 *     secoes[].id                    o número da divisória (o secao_id dos campos)
 *     secoes[].nome                  o nome (Identificação, Compra, Construção...)
 *     secoes[].ordem                 a posição da divisória na ficha
 *     campos[].padrao                o valor usado quando o relógio não tem o campo preenchido (texto; null: nenhum)
 *     campos[].tipo                  inteiro, decimal, sim_nao, data, lista ou texto
 *     campos[].unidade               a unidade (texto; vazio: nenhuma)
 *     config.carga_limiar            o limite de carga geral (%): carregar quando a carga chega a ele (o campo carga_minima do relógio, se
 *                                    preenchido, vale no lugar)
 *     config.na_hora_enviados        os avisos "na hora" que já foram pelo Telegram e ainda estão vencidos (relogio_id:identificador,
 *                                    separados por vírgula): o cron não manda de novo
 *     config.escala_fim              até que dia vai a escala inteligente gerada (texto AAAA-MM-DD; vazio fora da escala)
 *     config.escala_gerada           o dia em que a escala foi gerada pela última vez (texto AAAA-MM-DD)
 *     config.horario_manha           a hora da rodada da manhã do cron: o relógio do dia e os avisos (HH:MM)
 *     config.horario_noite           a hora da rodada da noite: preparar o relógio de amanhã (HH:MM)
 *     config.max_sem_uso             a garantia de rodízio: nenhum relógio passa desses dias sem uso (0 desliga)
 *     config.medicao_base            o que o gasto medido conta ("contar por"): dias (as medições dos últimos medicao_janela_dias) ou
 *                                    leituras (as últimas medicao_janela_leituras de cada tipo, em uso e em repouso)
 *     config.gasto_motor             como calcular o gasto da bateria, para todos os relógios da coleção: horas (pelas horas registradas, o
 *                                    motor original), robusto (pelas horas registradas, com leituras e cadastro), marcacao (pela marcação
 *                                    das leituras: duas taxas separadas), cadastro (só o cadastro) ou formulas (pelas fórmulas taxa_uso e
 *                                    taxa_repouso); vazio: vale o do relógio (o campo gasto_motor do cadastro), senão o padrão desse campo
 *                                    (o grupo), senão horas
 *     config.documentos_copia        a cópia dos documentos no banco, para a coleção inteira: todos (todo arquivo vai também para o banco),
 *                                    nenhum (nenhum vai, nem o que o relógio ou o arquivo pedem) ou vazio (cada relógio e cada arquivo
 *                                    decidem)
 *     config_php_copia               o DOCUMENTOS_COPIA_BANCO que o config.php ainda define (verdadeiro ou falso), que não vale mais (a
 *                                    Configuração mostra o aviso); null: não está lá
 *     config.medicao_janela_dias     o gasto medido pelas leituras usa as medições destes últimos dias, com "contar por" dias (sem nenhuma
 *                                    na janela, as de antes)
 *     config.medicao_janela_leituras o gasto medido usa as últimas tantas medições de cada tipo (em uso e em repouso), com "contar por"
 *                                    leituras (2 a 1000)
 *     config.encostado_dias          o aviso Encostado: depois de quantos dias sem ir ao pulso um relógio da coleção é avisado (1 a 3650;
 *                                    padrão 30)
 *     config.encostado_ativo         1 ou 0: o aviso Encostado está ligado (a caixa Avisar quando um relógio fica encostado, da
 *                                    Configuração: liga e desliga o aviso encostado do cadastro, o mesmo que a página Avisos; desligado,
 *                                    somem o aviso, a coluna Encostado da Hoje e o filtro)
 *     config.fuso                    o fuso horário da coleção ("Europe/Lisbon"): o de quem não escolheu o seu em Minha conta, e o do cron,
 *                                    das mensagens e das agendas dela; vazio: o do sistema (fuso_sistema)
 *     config.hora_relogio            o relógio da página Hora certa: analogico, digital, anadigi (o visor digital dentro do mostrador
 *                                    analógico) ou ambos (um ao lado do outro); vazio: ambos
 *     config.semana_inicio           o primeiro dia da semana do rodízio: segunda ou domingo (o plano vem montado até o último dia da
 *                                    semana; o ciclo, a próxima semana e o relógio para a semana inteira contam dela); vazio: segunda
 *     config.trocar_hoje             1: o relógio de hoje se troca no próprio dia; 0 ou vazio (o padrão): só a partir de amanhã (o que
 *                                    trocaria o de hoje é recusado, e o Sortear de novo vale de amanhã)
 *     config.idioma                  o idioma padrão da instalação (pt-BR, en, zh-CN, hi, es, fr; vazio: o do navegador, senão português)
 *     config.painel_modo             como o relógio abre na página Hoje: lado (no painel à direita da lista) ou flutuante (numa janela
 *                                    grande por cima da página); vazio: lado
 *     config.destaque_hoje           o destaque no alto da página Hoje: dia (o relógio do dia, o do plano) ou pulso (o relógio no pulso
 *                                    agora; sem nenhum no pulso, o do dia); vazio: dia
 *     config.calibre_campos          os campos do formulário do calibre (Cadastros › Calibres): movimento (só os campos marcados como do
 *                                    movimento, e os que o calibre já define) ou todos; vazio: movimento
 *     config.relojinho_modo          o reloginho da barra e da aba: hora (cada hora do dia tem o seu modelo), aleatorio (um sorteado a cada
 *                                    hora, podendo repetir) ou sem_repeticao (os 24 embaralhados, cada um uma vez por dia); vazio: hora
 *     config.proximos_por_pagina     o "Mostrar até" dos Próximos dias da página Hoje: quantos dias por página (1 a 366; vazio: 7)
 *     config.plano_por_pagina        o "Mostrar até" do dia a dia da página Plano: quantos dias por página (1 a 366; vazio: 31)
 *     config.atualizar_segundos      de quantos em quantos segundos as páginas (Hoje com o painel, Ficha, Plano, Totais e Histórico) leem
 *                                    tudo de novo sozinhas: 1 a 86400; 0, não leem; vazio: 60
 *     config.pulso_auto_fim          1 (ou vazio): o relógio sai do pulso sozinho no fim do horário de uso (o "fecha às" do tipo No pulso);
 *                                    0: só pelo Tirou
 *     config.pulso_auto_inicio       1 (ou vazio): o relógio do dia entra no pulso sozinho no início do horário de uso; 0: só pelo Pôs
 *     config.sol_auto_fim            1 (ou vazio): o solar sai do sol sozinho na hora de sol_fim (o "fecha às" do tipo No sol); 0: só pelo
 *                                    Tirar do sol
 *     config.sol_fim                 a hora em que a sessão no sol esquecida aberta fecha (HH:MM), guardada mesmo com o sol_auto_fim
 *                                    desligado
 *     config.previsao_limite         o limite de carga da previsão do smartwatch, em %
 *     config.sol_limiar              no solar, a carga (%) em que ele deve ir para o sol
 *     config.ultima_manha            o dia da última rodada da manhã do cron (texto AAAA-MM-DD)
 *     config.ultima_noite            o dia da última rodada da noite do cron (texto AAAA-MM-DD)
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
 *     criterios.modos[].tipo         o tipo do modo: semana, semana_fds, dia_semana, aleatorio, escala ou personalizado
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
 *     lancamento_tipos[].marcha      o que o tipo mede da marcha: desvio (a leitura do desvio da hora, em segundos), acerto (o acerto da
 *                                    hora) ou null
 *     lancamento_tipos[].winder      a sessão é numa posição de um winder (o Pôr pede a posição; o programa dela entra nas fórmulas por
 *                                    WINDER_POSICAO): 1 ou 0 (verdadeiro ou falso na exportação)
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
 *     modos[].roteiro                o roteiro escrito à mão (o personalizado); null nos outros tipos
 *     modos[].roteiro_efetivo        o roteiro que vale: o escrito, ou o gerado dos blocos e da forma de escolha
 *     modos[].semanas                quantas semanas à frente o plano vem montado (1 a 8; a escala usa o período dela)
 *     modos[].tipo                   o tipo do modo: semana, semana_fds, dia_semana, aleatorio, escala ou personalizado
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
 *     winders                        os winders, com as posições e o relógio em cada uma (como em recurso=winders, winders)
 *     winders[]                      um winder
 *     winders[].id                   o número do winder
 *     winders[].nome                 o nome ("Escritório")
 *     winders[].observacao           a observação (null)
 *     winders[].criado               quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     winders[].dados                os campos do winder com valor (Cadastros › Campos, para o winder), na ordem: o informado e, sem ele, o
 *                                    padrão do campo
 *     winders[].dados[]              um campo
 *     winders[].dados[].identificador
 *                                    o identificador do campo (o nome em valores[<identificador>] da gravação e em WINDER("identificador"))
 *     winders[].dados[].nome         o nome do campo
 *     winders[].dados[].valor        o valor (texto normalizado: número com ponto; o múltiplo, um por linha)
 *     winders[].dados[].texto        o valor para mostrar, no idioma, com a unidade
 *     winders[].dados[].origem       informado (gravado no winder) ou padrao (o padrão do campo)
 *     winders[].dados[].origem_texto a origem por extenso
 *     winders[].posicoes             as posições do winder, na ordem
 *     winders[].posicoes[]           uma posição
 *     winders[].posicoes[].id        o número da posição (o id do winder_posicoes salvar, que põe o relógio nela com relogio_id; o
 *                                    posicao_id do período)
 *     winders[].posicoes[].nome      o nome ("Pos. 1")
 *     winders[].posicoes[].ordem     a ordem dela no winder
 *     winders[].posicoes[].ligada    verdadeiro: recebe relógio; falso: desligada
 *     winders[].posicoes[].dados     os campos livres da posição com valor (Cadastros › Campos, para a posição do winder), como em dados
 *     winders[].posicoes[].dados[]   um campo
 *     winders[].posicoes[].dados[].identificador
 *                                    o identificador do campo (o nome em valores[<identificador>] da gravação e em
 *                                    WINDER_POSICAO("identificador"))
 *     winders[].posicoes[].dados[].nome
 *                                    o nome do campo
 *     winders[].posicoes[].dados[].valor
 *                                    o valor (texto normalizado: número com ponto; o múltiplo, um por linha)
 *     winders[].posicoes[].dados[].texto
 *                                    o valor para mostrar, no idioma, com a unidade
 *     winders[].posicoes[].dados[].origem
 *                                    informado (gravado na posição) ou padrao (o padrão do campo)
 *     winders[].posicoes[].dados[].origem_texto
 *                                    a origem por extenso
 *     winders[].posicoes[].programa  o programa da posição (o da página Programas); null: sem programa (a conta no winder usa a carga cheia
 *                                    por hora)
 *     winders[].posicoes[].programa.id
 *                                    o número do programa (o programa_id do winder_posicoes salvar)
 *     winders[].posicoes[].programa.nome
 *                                    o nome ("Versa 650")
 *     winders[].posicoes[].programa.texto
 *                                    o cálculo por extenso ("650 voltas por dia (325 horário + 325 anti-horário) · ciclo de 20 min, 72
 *                                    vezes por dia")
 *     winders[].posicoes[].programa.voltas_dia
 *                                    as voltas por dia, calculadas da sequência
 *     winders[].posicoes[].programa.voltas_horario
 *                                    as voltas por dia no sentido horário
 *     winders[].posicoes[].programa.voltas_anti_horario
 *                                    as voltas por dia no sentido anti-horário
 *     winders[].posicoes[].relogio   o relógio que está nela agora (a sessão no winder aberta, ligada a ela); null: livre
 *     winders[].posicoes[].relogio.relogio_id
 *                                    o número do relógio
 *     winders[].posicoes[].relogio.relogio
 *                                    o nome dele
 *     winders[].posicoes[].relogio.lancamento_id
 *                                    a sessão (o lançamento)
 *     winders[].posicoes[].relogio.tipo
 *                                    o tipo de lançamento da sessão (o encerrar dela)
 *     winders[].posicoes[].relogio.desde
 *                                    desde quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     winders[].posicoes[].relogio.desde_texto
 *                                    por extenso ("desde 07:40")
 *     winders[].posicoes[].relogio.carga
 *                                    a carga agora, em % (a fórmula energia); null: sem conta
 *     winders[].posicoes[].relogio.ganho
 *                                    o que cada hora ali dá, em horas de reserva por hora (a fórmula winder_ganho, com o programa da
 *                                    posição); null: sem a fórmula
 *     winders[].posicoes[].relogio.ganho_texto
 *                                    por extenso ("2,05 h de reserva por hora")
 *     winders[].posicoes[].relogio.falta_horas
 *                                    as horas até encher (a fórmula winder_falta); null: não enche (a posição não dá corda nele) ou sem a
 *                                    fórmula
 *     winders[].posicoes[].relogio.cheio_texto
 *                                    quando enche, por extenso ("cheio às 19:20", "cheio em 1d 4h", "cheio"); null: não enche
 *     winders[].posicoes[].relogio.foto
 *                                    a versão da foto do relógio (o instante em que foi trocada, em segundos;
 *                                    recurso=foto&relogio=<id>&v=<versão>), a figurinha do desenho do winder; null: sem foto
 *     winders[].posicoes[].lugar     o lugar fixo: de quem é a posição (o relógio que foi posto nela por último; pôr no winder de qualquer
 *                                    lugar o devolve para cá); null: de ninguém
 *     winders[].posicoes[].lugar.relogio_id
 *                                    o número do relógio dono do lugar
 *     winders[].posicoes[].lugar.relogio
 *                                    o nome dele
 *     winders[].posicoes[].lugar.fora
 *                                    verdadeiro: ele não está nela agora (o berço mostra a foto dele apagada, com o aro vermelho, e os
 *                                    botões Pôr de volta e Liberar o lugar)
 *     winders[].posicoes[].lugar.estado_texto
 *                                    onde ele está, fora ("fora: no pulso", "fora: guardado"); null: está nela
 *     winders[].posicoes[].lugar.foto
 *                                    a versão da foto do dono (como em relogio.foto); null: sem foto
 *     winders[].posicoes[].avisos_limite
 *                                    os avisos de limite da almofada do relógio que está nela (ou, vazia, do dono do lugar): a caixa ou o
 *                                    peso dele passa do que o winder aguenta (os campos winder_caixa_max e winder_peso_max); o dispensado
 *                                    com winders limite_ok não volta
 *     winders[].posicoes[].avisos_limite[]
 *                                    um aviso
 *     winders[].posicoes[].avisos_limite[].relogio_id
 *                                    o relógio do aviso (o relogio_id do limite_ok)
 *     winders[].posicoes[].avisos_limite[].texto
 *                                    o aviso por extenso ("O Steeldive tem 49 mm de caixa, e esta almofada vai até 42 mm.")
 *     winders[].ocupadas             quantas posições têm relógio agora
 *     winders[].livres               quantas posições ligadas estão sem relógio
 *     winders[].documentos           quantos documentos o winder tem (o manual, a nota, as fotos: recurso=documentos&winder=<id>)
 *     winder_programas               os programas dos winders (como em recurso=programas, programas)
 *     winder_programas[]             um programa
 *     winder_programas[].id          o número do programa
 *     winder_programas[].nome        o nome ("Versa 650")
 *     winder_programas[].medida      o tipo de cadastro: voltas (os giros em voltas) ou tempo (os giros em minutos e segundos)
 *     winder_programas[].medida_texto
 *                                    o tipo por extenso ("Por tempo")
 *     winder_programas[].velocidade  a velocidade do berço contada, em voltas por minuto; null: tirada do manual (tpd_manual) ou sem
 *     winder_programas[].tpd_manual  as voltas por dia que o manual diz, de onde a velocidade sai; null: a velocidade é a contada (ou não
 *                                    há)
 *     winder_programas[].liga_horas  as horas ligado de cada bloco; null: o dia todo
 *     winder_programas[].para_horas  as horas parado de cada bloco; null: o dia todo
 *     winder_programas[].funciona_texto
 *                                    quando funciona por extenso ("o dia todo", "liga 3 h e para 9 h")
 *     winder_programas[].observacao  a observação (null)
 *     winder_programas[].sequencia   os códigos dos movimentos, na ordem (o sequencia do salvar)
 *     winder_programas[].sequencia[] o código de um movimento (o id em movimentos)
 *     winder_programas[].calculo     o cálculo do programa (tudo o que a página mostra e ninguém digita)
 *     winder_programas[].calculo.passos
 *                                    os passos da sequência, na ordem
 *     winder_programas[].calculo.passos[]
 *                                    um passo
 *     winder_programas[].calculo.passos[].ordem
 *                                    a ordem dele (1, 2...)
 *     winder_programas[].calculo.passos[].movimento_id
 *                                    o código do movimento
 *     winder_programas[].calculo.passos[].nome
 *                                    o nome do movimento
 *     winder_programas[].calculo.passos[].tipo
 *                                    horario, anti_horario ou parado
 *     winder_programas[].calculo.passos[].tipo_texto
 *                                    o tipo por extenso ("Giro horário")
 *     winder_programas[].calculo.passos[].voltas
 *                                    as voltas do passo (0 na parada); null: sem velocidade para calcular
 *     winder_programas[].calculo.passos[].segundos
 *                                    quanto tempo o passo dura, em segundos; null: sem velocidade para calcular
 *     winder_programas[].calculo.passos[].voltas_texto
 *                                    as voltas por extenso ("10 voltas")
 *     winder_programas[].calculo.passos[].tempo_texto
 *                                    o tempo por extenso ("2 min")
 *     winder_programas[].calculo.passos[].voltas_calculadas
 *                                    verdadeiro: as voltas saíram da conta (tempo × velocidade), não do movimento
 *     winder_programas[].calculo.passos[].tempo_calculado
 *                                    verdadeiro: o tempo saiu da conta (voltas ÷ velocidade), não do movimento
 *     winder_programas[].calculo.velocidade
 *                                    a velocidade usada, em voltas por minuto; null: sem
 *     winder_programas[].calculo.velocidade_origem
 *                                    de onde ela vem: contada (a informada) ou manual (tirada das voltas por dia do manual); null: sem
 *     winder_programas[].calculo.velocidade_texto
 *                                    a velocidade por extenso ("4,51 voltas por minuto (tirada das voltas por dia do manual)"); null: sem
 *     winder_programas[].calculo.minutos_ligado_dia
 *                                    os minutos ligado por dia (1440 o dia todo; nos blocos, a parte ligada)
 *     winder_programas[].calculo.ciclo_segundos
 *                                    quanto a sequência inteira dura, em segundos; null: sem conta
 *     winder_programas[].calculo.ciclo_texto
 *                                    o ciclo por extenso ("20 min")
 *     winder_programas[].calculo.vezes_dia
 *                                    quantas vezes a sequência roda por dia (os minutos ligado ÷ o ciclo)
 *     winder_programas[].calculo.minutos_girando_dia
 *                                    os minutos girando por dia (as paradas não contam)
 *     winder_programas[].calculo.girando_texto
 *                                    o tempo girando por extenso ("2 h 24 min")
 *     winder_programas[].calculo.voltas_dia
 *                                    as voltas por dia (o TPD), calculadas: as voltas de um ciclo × as vezes por dia; null: sem conta
 *     winder_programas[].calculo.voltas_horario
 *                                    as voltas por dia no sentido horário (a carga de quem dá corda nesse sentido)
 *     winder_programas[].calculo.voltas_anti_horario
 *                                    as voltas por dia no sentido anti-horário
 *     winder_programas[].calculo.voltas_texto
 *                                    as voltas por extenso ("1.800 (900 horário + 900 anti-horário)")
 *     winder_programas[].calculo.erros
 *                                    o que impede a conta (a sequência vazia ou só com paradas, um giro em voltas num programa por tempo,
 *                                    as voltas do manual que as paradas não deixam dar...); vazio: fecha
 *     winder_programas[].calculo.erros[]
 *                                    um erro, por extenso
 *     winder_programas[].calculo.avisos
 *                                    o que vale conferir (sem a velocidade do berço, sem as voltas por dia; voltas por dia demais para um
 *                                    automático...)
 *     winder_programas[].calculo.avisos[]
 *                                    um aviso, por extenso
 *     winder_programas[].calculo.texto
 *                                    o resumo por extenso ("650 voltas por dia (só no sentido anti-horário) · ciclo de 20 min, 72 vezes por
 *                                    dia")
 *     winder_programas[].usado_em    as posições que usam o programa
 *     winder_programas[].usado_em[]  uma posição
 *     winder_programas[].usado_em[].posicao_id
 *                                    o número da posição
 *     winder_programas[].usado_em[].texto
 *                                    o winder e a posição por extenso ("Versa, Pos. 1")
 *     winder_movimentos              os movimentos dos programas (como em recurso=programas, movimentos)
 *     winder_movimentos[]            um movimento
 *     winder_movimentos[].id         o código do movimento (o que a sequência do programa usa)
 *     winder_movimentos[].nome       o nome ("Giro horário 2 min")
 *     winder_movimentos[].tipo       horario, anti_horario ou parado
 *     winder_movimentos[].tipo_texto o tipo por extenso
 *     winder_movimentos[].voltas     as voltas do giro; null: medido em tempo (ou a parada)
 *     winder_movimentos[].segundos   o tempo, em segundos; null: medido em voltas
 *     winder_movimentos[].medida     voltas ou tempo: como ele é medido
 *     winder_movimentos[].medida_texto
 *                                    a medida por extenso ("2 min", "10 voltas")
 *     winder_movimentos[].texto      o movimento por extenso ("Giro horário: 2 min")
 *     winder_movimentos[].usado_em   os programas que o usam
 *     winder_movimentos[].usado_em[] um programa
 *     winder_movimentos[].usado_em[].programa_id
 *                                    o número do programa
 *     winder_movimentos[].usado_em[].nome
 *                                    o nome dele
 *     acessorios[].id                o número do acessório
 *     acessorios[].nome              o nome
 *     acessorios[].tipo              o tipo: o identificador de um item da lista Tipos de acessório (Cadastros › Listas: pulseira, caixa,
 *                                    estojo, ferramenta, carregador, peca, outro...)
 *     acessorios[].tipo_texto        o tipo por extenso
 *     acessorios[].relogio_id        o relógio de que ele é; null: solto
 *     acessorios[].relogio           o nome desse relógio; null: solto
 *     acessorios[].observacao        a observação (null)
 *     acessorios[].criado            quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     acessorios[].valores           os campos do acessório preenchidos (Cadastros › Campos, para acessório: os do tipo dele), na ordem
 *     acessorios[].valores[]         um campo do acessório
 *     acessorios[].valores[].identificador
 *                                    o identificador do campo
 *     acessorios[].valores[].nome    o nome do campo
 *     acessorios[].valores[].valor   o valor gravado (texto normalizado: número com ponto; o múltiplo, um por linha)
 *     acessorios[].valores[].texto   o valor para mostrar, no idioma, com a unidade ("20 mm (0,79 pol)"; o múltiplo separado por vírgula)
 *     acessorios[].serve_por         o que decide em que relógio ele serve: cada campo do relógio com que um campo dele bate, com o valor
 *                                    dele ("Entre alças (largura da pulseira): 20 mm"); null: ele não preencheu nenhum desses campos
 *     acessorios[].serve_por[]       um campo do relógio e o valor que ele tem de ter
 *     acessorios[].serve_em          os relógios em que ele serve (os da coleção, emprestados e desejados cujo valor bate com o dele em
 *                                    todos esses campos); null: não dá para saber (ele não preencheu nenhum desses campos)
 *     acessorios[].serve_em[]        um relógio em que ele serve
 *     acessorios[].serve_em[].relogio_id
 *                                    o número do relógio
 *     acessorios[].serve_em[].relogio
 *                                    o nome do relógio
 *     servicos                       os serviços e diagnósticos de todos os relógios (como em recurso=servicos)
 *     servicos[]                     um serviço
 *     servicos[].id                  o número do serviço (o id da gravação)
 *     servicos[].relogio_id          o relógio do serviço
 *     servicos[].relogio             o nome do relógio
 *     servicos[].data                a data do serviço (AAAA-MM-DD)
 *     servicos[].ha_dias             há quantos dias foi (no dia da coleção)
 *     servicos[].tipo                o tipo: o identificador de um item da lista Tipos de serviço (Manutenção › Listas: revisao, pilha,
 *                                    estanqueidade, regulagem, polimento...)
 *     servicos[].tipo_texto          o tipo por extenso
 *     servicos[].oficina             a oficina do serviço (a da manutenção de que ele é o encerramento: Manutenção › Oficinas)
 *     servicos[].descricao           o que foi feito; null: não informado
 *     servicos[].mao_de_obra         a mão de obra, em R$; null: não informada
 *     servicos[].pecas               as peças trocadas, na ordem
 *     servicos[].pecas[]             uma peça
 *     servicos[].pecas[].nome        o nome da peça
 *     servicos[].pecas[].custo       o custo da peça, em R$; null: não informado
 *     servicos[].custo_pecas         a soma do custo das peças, em R$ (0 sem nenhuma com custo)
 *     servicos[].custo               o custo do serviço: a mão de obra mais as peças, em R$; null: nada com custo
 *     servicos[].estanqueidade       o resultado do teste de estanqueidade: o identificador de um item da lista Resultados do teste de
 *                                    estanqueidade (Manutenção › Listas: aprovado, reprovado...); null: não testada
 *     servicos[].estanqueidade_texto o resultado por extenso (o nome do item, em minúsculas); null: não testada
 *     servicos[].estanqueidade_aprova
 *                                    o resultado aprova a vedação (o item do resultado faz aprova)? true ou false; null: não testada
 *     servicos[].manutencao_id       a manutenção de que o serviço é o encerramento (recurso=manutencao)
 *     servicos[].oficina_id          o número da oficina
 *     servicos[].estanqueidade_bar   a pressão do teste, em bar; null: não informada
 *     servicos[].marcha_antes        a marcha antes do serviço, informada pelo profissional, em s/dia (+ adianta); null: não informada
 *     servicos[].marcha_depois       a marcha depois do serviço, informada pelo profissional, em s/dia; null: não informada
 *     servicos[].marcha_antes_texto  a marcha antes, pronta ("+25,0 s/dia"); null
 *     servicos[].marcha_depois_texto a marcha depois, pronta; null
 *     servicos[].medida_antes        a marcha medida pelo sistema antes do serviço: os intervalos do desvio da hora que terminam nos 60
 *                                    dias antes da data; null: nenhum
 *     servicos[].medida_antes.marcha a marcha, em s/dia, pesada pelo tempo de cada intervalo
 *     servicos[].medida_antes.marcha_texto
 *                                    a marcha pronta
 *     servicos[].medida_antes.intervalos
 *                                    quantos intervalos entraram
 *     servicos[].medida_depois       a marcha medida pelo sistema depois do serviço: os intervalos que começam nos 60 dias depois do dia do
 *                                    serviço; null: nenhum
 *     servicos[].medida_depois.marcha
 *                                    a marcha, em s/dia
 *     servicos[].medida_depois.marcha_texto
 *                                    a marcha pronta
 *     servicos[].medida_depois.intervalos
 *                                    quantos intervalos entraram
 *     servicos[].garantia_ate        a garantia do serviço até (AAAA-MM-DD); null: sem
 *     servicos[].garantia_vale       true se a garantia ainda vale hoje
 *     servicos[].observacao          a observação do profissional; null: sem
 *     servicos[].documentos          os documentos do relógio que são do serviço (a nota, o orçamento, as fotos), pelo título
 *     servicos[].documentos[]        um documento
 *     servicos[].documentos[].id     o número do documento
 *     servicos[].documentos[].titulo o título
 *     servicos[].documentos[].url    o endereço do arquivo (recurso=documento)
 *     servicos[].marcacao            true se o serviço virou também a marcação do tipo de lançamento que o tipo de serviço marca (o texto
 *                                    do item: revisao, pilha)
 *     servicos[].criado              quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     timegrapher                    as medições do timegrapher de todos os relógios (como em recurso=timegrapher)
 *     timegrapher[]                  uma medição
 *     timegrapher[].id               o número da medição (o id da gravação)
 *     timegrapher[].relogio_id       o relógio
 *     timegrapher[].relogio          o nome do relógio
 *     timegrapher[].quando           quando foi medida (texto AAAA-MM-DD HH:MM:SS, no fuso do pedido)
 *     timegrapher[].posicao          a posição no aparelho: mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda ou
 *                                    coroa_direita; null: não informada
 *     timegrapher[].posicao_texto    a posição por extenso
 *     timegrapher[].marcha           a marcha, em segundos por dia (+ adianta, − atrasa); null: não medida
 *     timegrapher[].marcha_texto     a marcha por extenso ("+4,5 s/dia")
 *     timegrapher[].amplitude        a amplitude do balanço, em graus; null: não medida
 *     timegrapher[].amplitude_texto  a amplitude por extenso ("290°")
 *     timegrapher[].erro_batida      o erro de batida, em milissegundos; null: não medido
 *     timegrapher[].erro_batida_texto
 *                                    o erro de batida por extenso ("0,3 ms")
 *     timegrapher[].bph              as batidas por hora (21600, 28800...); null: não informadas
 *     timegrapher[].bph_texto        as batidas por hora por extenso
 *     timegrapher[].angulo           o ângulo de levantamento (lift angle), em graus; null: não informado
 *     timegrapher[].angulo_texto     o ângulo por extenso
 *     timegrapher[].origem           manual (digitada) ou csv (importada do arquivo do aparelho)
 *     timegrapher[].origem_texto     a origem por extenso
 *     timegrapher[].observacao       a observação; null: nenhuma
 *     reserva                        os testes da reserva e as observações da energia de todos os relógios (como em recurso=reserva)
 *     reserva[]                      um registro
 *     reserva[].id                   o número do teste ou da observação (o id da gravação)
 *     reserva[].relogio_id           o relógio
 *     reserva[].relogio              o nome do relógio
 *     reserva[].tipo                 teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     reserva[].tipo_texto           o tipo por extenso
 *     reserva[].inicio               no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     reserva[].fim                  no teste, quando ele parou; null: em andamento (e nas observações)
 *     reserva[].em_andamento         true no teste ainda sem o fim
 *     reserva[].horas                a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     reserva[].horas_texto          as horas prontas ("41,5 h"); null
 *     reserva[].ate_agora            no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     reserva[].protocolo            como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     reserva[].protocolo_valor      as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     reserva[].protocolo_texto      como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     reserva[].observacao           a observação; null: sem
 *     reserva[].criado               quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
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
 *                                    cópia; a Configuração da coleção, documentos_copia, se escolhe, vale por cima)
 *     relogios[].calibre_id          o id do calibre dele (o movimento); null: sem calibre
 *     relogios[].calibre             o nome do calibre dele; null: sem calibre
 *     relogios[].posse               a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
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
 *                                    o estado do trecho: rodizio (no pulso pelo rodízio), pulso (em uso fora do plano: no pulso sem ser o
 *                                    relógio do dia), o identificador de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma
 *                                    marcação)
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
 *     relogios[].medicoes[].erro     o quanto a conta de agora erra a leitura desta medição que menos bate com as outras (a do começo ou a
 *                                    do fim), em pontos de carga (número; null fora da janela ou só no histórico)
 *     relogios[].medicoes[].leitura_fraca
 *                                    o valor da leitura desta medição que menos bate com as outras (a do começo ou a do fim) (número; null
 *                                    fora da conta)
 *     relogios[].medicoes[].fora     verdadeiro ou falso: uma leitura desta medição não bate com as outras e pesa menos da metade na conta
 *                                    (regressão robusta)
 *     relogios[].medicoes[].marcacao_de
 *                                    a marcação da leitura do começo da medição: uso ou repouso (null fora da conta)
 *     relogios[].medicoes[].marcacao_ate
 *                                    a marcação da leitura do fim da medição: uso ou repouso (null fora da conta)
 *     relogios[].medicoes[].marcacoes_iguais
 *                                    verdadeiro ou falso, no motor pela marcação (e no das fórmulas): as duas leituras têm a mesma
 *                                    marcação, e a medição entra na conta daquele gasto; com marcações diferentes, não entra em nenhum dos
 *                                    dois. null nos outros motores (eles não escolhem pela marcação)
 *     relogios[].medicoes[].na_media verdadeiro ou falso: entra na conta agora (usada e dentro da janela da Configuração,
 *                                    medicao_janela_dias)
 *     relogios[].medicoes[].peso     o peso, de 0 a 1, que a leitura desta medição que menos bate com as outras tem na conta (1: bate;
 *                                    menos: a regressão robusta a pesa menos) (número; null fora da conta)
 *     relogios[].medicoes[].peso_horas
 *                                    as horas que a medição cobriu, no pulso (uso) ou guardado (repouso) (número; a conta não pesa mais por
 *                                    horas: todas as medições pesam igual)
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
 *     relogios[].previsao.conta[]    de onde vem cada número da conta: primeiro o gasto que vale no pulso e fora dele, depois o gasto
 *                                    medido pelas leituras e os campos do cadastro (os de um gasto em que vale o medido, com usado falso)
 *     relogios[].previsao.conta[].usado
 *                                    verdadeiro: o número entra na conta; falso: um campo do cadastro que ficou fora, porque vale o gasto
 *                                    medido
 *     relogios[].previsao.conta[].vale
 *                                    verdadeiro nas duas primeiras linhas: o gasto que vale no pulso (taxa_uso) e fora dele (taxa_repouso)
 *     relogios[].previsao.conta[].campo
 *                                    o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     relogios[].previsao.conta[].detalhe
 *                                    no gasto medido, como ele foi medido ("conta dos dois gastos juntos, com 3 medições dos últimos 90
 *                                    dias"); null nos campos
 *     relogios[].previsao.conta[].nome
 *                                    o nome do campo, ou do gasto medido (Gasto em uso medido, Gasto fora do pulso medido)
 *     relogios[].previsao.conta[].origem
 *                                    de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo), vazio (sem valor), medido (pelas leituras) ou cadastro (o gasto que vale saiu do cadastro)
 *     relogios[].previsao.conta[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
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
 *     relogios[].previsao.gasto.motor
 *                                    o motor que calculou o gasto: horas, robusto, marcacao, cadastro ou formulas (veja config.gasto_motor)
 *     relogios[].previsao.gasto.motor_nome
 *                                    o nome do motor por extenso ("Pela marcação: duas taxas separadas")
 *     relogios[].previsao.gasto.motor_de
 *                                    de onde veio a escolha: colecao (a Configuração), relogio (o campo gasto_motor do relógio), grupo (o
 *                                    padrão do campo) ou padrao (nada escolhido: horas)
 *     relogios[].previsao.gasto.base o que a conta do gasto medido conta (o "contar por" da Configuração): dias ou leituras
 *     relogios[].previsao.gasto.janela_leituras
 *                                    as últimas tantas medições de cada tipo que entram, com base leituras (número)
 *     relogios[].previsao.gasto.janela_texto
 *                                    a janela por extenso: "dos últimos 90 dias" ou "das últimas 20 leituras de cada tipo"
 *     relogios[].previsao.gasto.janela_dias
 *                                    a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)
 *     relogios[].previsao.gasto.fora quantas medições da janela têm uma leitura que não bate com as outras e pesa menos da metade na conta
 *                                    (número)
 *     relogios[].previsao.gasto.medicoes
 *                                    quantas medições da janela entram na conta (número)
 *     relogios[].previsao.gasto.medicoes_antes
 *                                    quantas medições há na janela anterior, a dos antes (número)
 *     relogios[].previsao.gasto.uso.antes
 *                                    o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)
 *     relogios[].previsao.gasto.uso.cadastro
 *                                    o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)
 *     relogios[].previsao.gasto.uso.medido_dp
 *                                    a incerteza do gasto em uso medido: um desvio padrão, na mesma unidade (número; null sem medição)
 *     relogios[].previsao.gasto.uso.medicoes
 *                                    quantas medições em uso entram na conta (número; no motor da marcação, as de leituras marcadas em uso
 *                                    nas duas pontas)
 *     relogios[].previsao.gasto.uso.so_leituras
 *                                    o gasto em uso, em % por dia de uso, só pelas leituras: a mesma conta sem o cadastro como ponto de
 *                                    partida (número; null sem medição ou no motor só o cadastro)
 *     relogios[].previsao.gasto.uso.puxado
 *                                    verdadeiro: o medido ficou diferente do que só as leituras dão, porque com poucas medições o cadastro
 *                                    ainda puxa a conta
 *     relogios[].previsao.gasto.uso.medido
 *                                    o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null se as leituras
 *                                    não medem esse gasto: sem medição ou, no motor da marcação, nenhuma desse tipo)
 *     relogios[].previsao.gasto.uso.vale
 *                                    o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)
 *     relogios[].previsao.gasto.uso.campos[]
 *                                    os campos do cadastro de onde sai o gasto em uso do cadastro (o decaimento, a autonomia...): o painel
 *                                    mostra o medido ao lado deles
 *     relogios[].previsao.gasto.uso.campos[].campo
 *                                    o identificador do campo
 *     relogios[].previsao.gasto.uso.campos[].nome
 *                                    o nome do campo
 *     relogios[].previsao.gasto.uso.campos[].origem
 *                                    de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo) ou vazio (sem valor)
 *     relogios[].previsao.gasto.uso.campos[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
 *     relogios[].previsao.gasto.uso.campos[].unidade
 *                                    a unidade do campo (texto)
 *     relogios[].previsao.gasto.uso.campos[].valor
 *                                    o valor usado na conta (número; null se vazio)
 *     relogios[].previsao.gasto.uso.formula
 *                                    o identificador da fórmula do gasto em uso (taxa_uso)
 *     relogios[].previsao.gasto.repouso.antes
 *                                    o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)
 *     relogios[].previsao.gasto.repouso.cadastro
 *                                    o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)
 *     relogios[].previsao.gasto.repouso.medido_dp
 *                                    a incerteza do gasto fora do pulso medido: um desvio padrão, na mesma unidade (número; null sem
 *                                    medição)
 *     relogios[].previsao.gasto.repouso.medicoes
 *                                    quantas medições em repouso entram na conta (número; no motor da marcação, as de leituras marcadas em
 *                                    repouso nas duas pontas)
 *     relogios[].previsao.gasto.repouso.so_leituras
 *                                    o gasto fora do pulso, em % por dia, só pelas leituras: a mesma conta sem o cadastro como ponto de
 *                                    partida (número; null sem medição ou no motor só o cadastro)
 *     relogios[].previsao.gasto.repouso.puxado
 *                                    verdadeiro: o medido ficou diferente do que só as leituras dão, porque com poucas medições o cadastro
 *                                    ainda puxa a conta
 *     relogios[].previsao.gasto.repouso.medido
 *                                    o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null se as leituras
 *                                    não medem esse gasto: sem medição ou, no motor da marcação, nenhuma desse tipo)
 *     relogios[].previsao.gasto.repouso.vale
 *                                    o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)
 *     relogios[].previsao.gasto.repouso.campos[]
 *                                    os campos do cadastro de onde sai o gasto fora do pulso do cadastro (o decaimento, a autonomia...): o
 *                                    painel mostra o medido ao lado deles
 *     relogios[].previsao.gasto.repouso.campos[].campo
 *                                    o identificador do campo
 *     relogios[].previsao.gasto.repouso.campos[].nome
 *                                    o nome do campo
 *     relogios[].previsao.gasto.repouso.campos[].origem
 *                                    de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo) ou vazio (sem valor)
 *     relogios[].previsao.gasto.repouso.campos[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
 *     relogios[].previsao.gasto.repouso.campos[].unidade
 *                                    a unidade do campo (texto)
 *     relogios[].previsao.gasto.repouso.campos[].valor
 *                                    o valor usado na conta (número; null se vazio)
 *     relogios[].previsao.gasto.repouso.formula
 *                                    o identificador da fórmula do gasto fora do pulso (taxa_repouso)
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
 *                                    verdadeiro: o arquivo está inteiro na pasta dos documentos, com o tamanho do envio (falso: sumiu ou
 *                                    está cortado, e volta do banco quando for pedido ou na próxima rodada do cron; sem a cópia no banco,
 *                                    está perdido)
 *     relogios[].documentos[].no_banco
 *                                    verdadeiro: a cópia de segurança do arquivo está completa no banco (se ele sumir da pasta, volta dali)
 *     relogios[].documentos[].copia_banco
 *                                    verdadeiro: o próprio arquivo pede a cópia no banco (o terceiro nível; o relógio e a Configuração da
 *                                    coleção, se pedem, valem por cima)
 *     relogios[].documentos[].copia_por
 *                                    quem pede a cópia no banco: sistema (a Configuração da coleção, documentos_copia = todos), relogio (a
 *                                    marca do relógio) ou arquivo (a marca do próprio arquivo); null: ninguém
 *     relogios[].documentos[].relogio_id
 *                                    o relógio dele
 *     relogios[].documentos[].calibre_id
 *                                    null (os documentos dos calibres saem em recurso=documentos, calibre=)
 *     relogios[].documentos[].winder_id
 *                                    null (os documentos dos winders saem em recurso=documentos, winder=)
 *     relogios[].documentos[].tamanho
 *                                    o tamanho do arquivo, em bytes (número inteiro)
 *     relogios[].documentos[].tipo   o tipo do arquivo (MIME): image/jpeg, video/mp4, application/pdf, application/xml...
 *     relogios[].documentos[].titulo o título
 *     relogios[].documentos[].url    o endereço do arquivo, relativo à pasta do sistema (api.php?recurso=documento&id=...)
 *     relogios[].documentos[].nfe    sempre null aqui: o resumo da NF-e vem no recurso=documentos
 *     destinos[]                     os destinos das mensagens da coleção (webhook, e-mail, Web Push)
 *     destinos[].id                  o id do destino
 *     destinos[].nome                o nome (Telegram, E-mail da família...)
 *     destinos[].tipo                webhook, email ou webpush
 *     destinos[].tipo_nome           o nome do tipo, por extenso
 *     destinos[].ativo               verdadeiro ou falso: manda (desligado, não sai nada por ele)
 *     destinos[].modelo              a mensagem padrão dele, com as âncoras ({acao}, {relogio}, {motivo}...): cada item vira uma linha por
 *                                    ela
 *     destinos[].idioma              o idioma das mensagens dele (pt-BR, en, zh-CN, hi, es ou fr); null: o da coleção
 *     destinos[].fuso                o fuso das horas no texto das mensagens dele ("Europe/Lisbon"); null: o da coleção
 *     fusos[]                        os fusos horários que o PHP conhece (a lista Fuso horário de cada agenda)
 *     fusos[].nome                   o nome ("America/Sao_Paulo")
 *     fusos[].deslocamento           a diferença para o UTC agora ("-03:00")
 *     destinos[].recebe[]            o que ele recebe: cada tipo de aviso (o relógio do dia, a véspera, cada aviso do cadastro, cada evento
 *                                    personalizado)
 *     destinos[].recebe[].tipo       o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)
 *     destinos[].recebe[].nome       o nome do tipo
 *     destinos[].recebe[].quando     quando sai (manhã, noite, na hora...)
 *     destinos[].recebe[].envia      verdadeiro ou falso: recebe esse tipo
 *     destinos[].recebe[].modelo     o texto próprio desse tipo (com as âncoras); null: a mensagem padrão
 *     destinos[].webhook             o que é do webhook (null nos outros tipos)
 *     destinos[].webhook.metodo      GET, POST ou PUT
 *     destinos[].webhook.url         o endereço
 *     destinos[].webhook.formato     como a mensagem vai: query (na URL), form (formulário) ou json
 *     destinos[].webhook.corpo_json  o corpo JSON próprio, com {titulo} e {mensagem} (null: os campos viram o objeto)
 *     destinos[].webhook.campos[]    os campos do pedido
 *     destinos[].webhook.campos[].nome
 *                                    o nome do campo
 *     destinos[].webhook.campos[].valor
 *                                    o valor, com {titulo} e {mensagem}
 *     destinos[].webhook.cabecalhos[]
 *                                    os cabeçalhos do pedido (o valor fica cifrado no banco e nunca sai)
 *     destinos[].webhook.cabecalhos[].nome
 *                                    o nome do cabeçalho
 *     destinos[].webhook.cabecalhos[].preenchido
 *                                    verdadeiro ou falso: tem valor guardado
 *     destinos[].email               o que é do e-mail (null nos outros tipos)
 *     destinos[].email.para          para quem (um ou mais endereços, separados por vírgula)
 *     destinos[].webpush             o que é do Web Push (null nos outros tipos)
 *     destinos[].webpush.usuario_id  o usuário que recebe (nos aparelhos dele)
 *     destinos[].webpush.usuario     o login dele
 *     destinos[].webpush.aparelhos   quantos aparelhos dele estão inscritos
 *     agendas[]                      as agendas da coleção (Google Agenda, CalDAV, Outlook, link ICS)
 *     agendas[].id                   o id da agenda
 *     agendas[].nome                 o nome
 *     agendas[].tipo                 google, caldav, outlook ou ics
 *     agendas[].tipo_nome            o nome do tipo, por extenso
 *     agendas[].ativa                verdadeiro ou falso: o sistema mantém os eventos nela
 *     agendas[].janela_dias          quantos dias à frente ficam na agenda
 *     agendas[].modelo               o modelo do evento: a primeira linha é o título, o resto a descrição, com as âncoras
 *     agendas[].idioma               o idioma dos eventos dela; null: o da coleção
 *     agendas[].fuso                 o fuso das horas no texto dos eventos dela ("Europe/Lisbon"); null: o da coleção (o instante do evento
 *                                    no calendário não depende disto)
 *     agendas[].recebe[]             o que ele recebe: cada tipo de aviso (o relógio do dia, a véspera, cada aviso do cadastro, cada evento
 *                                    personalizado)
 *     agendas[].recebe[].tipo        o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)
 *     agendas[].recebe[].nome        o nome do tipo
 *     agendas[].recebe[].quando      quando sai (manhã, noite, na hora...)
 *     agendas[].recebe[].envia       verdadeiro ou falso: recebe esse tipo
 *     agendas[].recebe[].modelo      o texto próprio desse tipo (com as âncoras); null: a mensagem padrão
 *     agendas[].sincronizados        quantos eventos o sistema criou nela (e acompanha)
 *     agendas[].teste_id             o id do evento de teste nela; null: nenhum
 *     agendas[].google               o que é do Google Agenda (null nos outros tipos)
 *     agendas[].google.calendario_id o id da agenda do Google
 *     agendas[].google.chave         a chave da conta de serviço: json (colada, cifrada no banco), caminho (um arquivo no servidor) ou
 *                                    nenhuma
 *     agendas[].google.caminho       o caminho do arquivo da chave (só quando a chave é um caminho)
 *     agendas[].google.conta_servico o e-mail da conta de serviço (compartilhe a agenda com ele, com permissão de alterar)
 *     agendas[].caldav               o que é do CalDAV (null nos outros tipos)
 *     agendas[].caldav.url           o endereço do calendário
 *     agendas[].caldav.usuario       o usuário
 *     agendas[].caldav.senha_preenchida
 *                                    verdadeiro ou falso: tem senha guardada (cifrada; nunca sai)
 *     agendas[].outlook              o que é do Outlook (null nos outros tipos)
 *     agendas[].outlook.conta        a conta Microsoft conectada; null: ainda não
 *     agendas[].outlook.calendario_id
 *                                    o id do calendário; null: o principal
 *     agendas[].outlook.conectada    verdadeiro ou falso: a conta Microsoft está conectada
 *     agendas[].outlook.configurado  verdadeiro ou falso: o servidor tem o aplicativo da Microsoft (OUTLOOK_CLIENTE_ID)
 *     agendas[].outlook.retorno      o endereço de retorno para registrar no aplicativo da Microsoft
 *     agendas[].ics                  o que é do link ICS (null nos outros tipos)
 *     agendas[].ics.link             o link para assinar no app de agenda (quem tem o link vê os eventos)
 *     agendas[].criados[]            os eventos que o sistema criou nela
 *     agendas[].criados[].chave      a identificação do evento (a mesma dos desejados)
 *     agendas[].criados[].externo_id o id do evento no serviço da agenda
 *     agendas[].criados[].data       o dia do evento (texto AAAA-MM-DD)
 *     agendas[].criados[].titulo     o título com que foi criado
 *     agendas[].criados[].assinatura a marca do título e da descrição com que foi criado (mudou: o sistema atualiza o evento)
 *     agendas[].criados[].criado     quando foi criado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     agendas_desejadas[]            o que tem de estar em cada agenda agora, pelo que ela recebe
 *     agendas_desejadas[].agenda_id  o id da agenda
 *     agendas_desejadas[].agenda     o nome da agenda
 *     agendas_desejadas[].eventos[]  os eventos
 *     agendas_desejadas[].eventos[].chave
 *                                    a identificação do evento na agenda (a mesma enquanto nada mudar; é o que o sistema usa para atualizar
 *                                    ou remover)
 *     agendas_desejadas[].eventos[].tipo
 *                                    o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)
 *     agendas_desejadas[].eventos[].relogio_id
 *                                    o relógio do evento; null num evento geral
 *     agendas_desejadas[].eventos[].relogio
 *                                    o nome do relógio; vazio num evento geral
 *     agendas_desejadas[].eventos[].data
 *                                    o dia do evento na agenda (texto AAAA-MM-DD)
 *     agendas_desejadas[].eventos[].hora
 *                                    a hora do evento na agenda (HH:MM)
 *     agendas_desejadas[].eventos[].fazer
 *                                    a ação ("Dar corda", "Usar hoje", o nome do evento): a âncora {acao}
 *     agendas_desejadas[].eventos[].motivo
 *                                    o porquê ("a reserva acaba em 3h, 28/09 10:53"): a âncora {motivo}
 *     agendas_desejadas[].eventos[].momento
 *                                    o instante previsto do que motivou o evento (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     agendas_desejadas[].eventos[].titulo
 *                                    o título do evento, montado pelo modelo da agenda (a primeira linha)
 *     agendas_desejadas[].eventos[].descricao
 *                                    a descrição do evento, montada pelo modelo da agenda (o resto)
 *     eventos[].destinos[]           os nomes dos destinos que recebem o evento
 *     eventos[].agendas[]            os nomes das agendas que recebem o evento
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
 *     fora_da_colecao[].posse_comportamento
 *                                    o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
 *     relogios[].posse_comportamento o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
 *     relogios[].em_manutencao       a oficina em que ele está (o relógio em manutenção: indisponível, com o estado Em manutenção); null:
 *                                    não está
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
 *                                    aberta: o Tirar do carregador) ou lancar (instantâneo ou leitura); winders: o tipo que resolve é o do
 *                                    winder e ele está fora: o botão é o link para a página Winders (o pôr é só lá; sem winder cadastrado,
 *                                    o aviso vem sem resolve)
 *     avisos[].resolve.estados       true numa leitura que mede o gasto, num relógio que vai ao pulso: a tela mostra dois botões, Em uso e
 *                                    Em repouso (o estado da leitura, no campo estado do lancar)
 *     avisos[].resolve.botao         o texto do botão ("Pôs no sol", "Tirou do carregador", "Informar leitura de carga", "Corda")
 *     avisos[].texto                 o motivo por extenso
 *     avisos_nomes[]                 os nomes dos avisos da manutenção (o filtro "O que fazer" da tabela; sem o Encostado, que tem a coluna
 *                                    dele)
 *     encostado_ativo                true ou false: o aviso Encostado está ligado (a caixa Avisar quando um relógio fica encostado, da
 *                                    Configuração); false, a página esconde a coluna Encostado e o filtro, e nenhum relógio vem com
 *                                    encostado
 *     fora_da_colecao                os relógios fora da coleção (desejados, vendidos, arquivados), fora da tabela: a página Desejos e o
 *                                    seletor da ficha
 *     fora_da_colecao[]              um relógio fora da coleção
 *     fora_da_colecao[].foto         a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     fora_da_colecao[].id           o número (código)
 *     fora_da_colecao[].nome         o nome
 *     fora_da_colecao[].posse        a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
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
 *     modo.semanas                   quantas semanas à frente o plano vem montado (1 a 8; a escala usa o período dela)
 *     modo.tipo                      o tipo do modo: semana, semana_fds, dia_semana, aleatorio, escala ou personalizado
 *     modo.tipo_texto                o tipo por extenso, no idioma do pedido
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
 *     modos[].semanas                quantas semanas à frente o plano vem montado (1 a 8)
 *     modos[].tipo                   o tipo do modo: semana, semana_fds, dia_semana, aleatorio, escala ou personalizado
 *     modos[].tipo_texto             o tipo por extenso, no idioma do pedido
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
 *     proxima_semana.fim             o último dia dela (texto AAAA-MM-DD: o domingo, ou o sábado com a semana começando no domingo)
 *     proxima_semana.inicio          o primeiro dia da próxima semana (texto AAAA-MM-DD: a segunda-feira, ou o domingo com a semana
 *                                    começando no domingo)
 *     proxima_semana.ja_montada      verdadeiro ou falso: a próxima semana já está no plano
 *     semana_inicio                  o dia em que a semana do rodízio começa (a Configuração, semana_inicio): segunda ou domingo
 *     trocar_hoje                    verdadeiro: o relógio de hoje se troca no próprio dia (a Configuração, trocar_hoje); falso, o padrão:
 *                                    só a partir de amanhã, e as telas não mostram as trocas de hoje (o trocar por… do dia de hoje, o
 *                                    Usando hoje, o inclusive hoje, o Sem relógio hoje, o Excluindo hoje do Apagar tudo; no Rodízio manual,
 *                                    hoje fica parado)
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
 *     relogios[].posse               a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
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
 *     relogios[].encostado           o aviso Encostado deste relógio (não entra na manutenção); null: sem data (nunca foi ao pulso e sem
 *                                    data da compra)
 *     relogios[].encostado.data      a data (texto AAAA-MM-DD; hoje se já passou)
 *     relogios[].encostado.estado    atrasado (passou do limite de dias sem ir ao pulso), em_breve (vence agora) ou ok (ainda dentro do
 *                                    prazo)
 *     relogios[].encostado.falta     quanto falta, por extenso ("12d", "atrasado 40d")
 *     relogios[].encostado.momento   o instante previsto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))
 *     relogios[].manutencao          o aviso de manutenção mais perto, sem o Encostado; null: nenhum
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
 *     posses                         os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     posses[]                       as situações na coleção, na ordem (o menu "Situação na coleção" do cadastro)
 *     posses[].valor                 o identificador da situação, o que vai na gravação (posse)
 *     posses[].nome                  o nome ("Na coleção", "Desejado", "Emprestado", "Vendido", "Arquivado")
 *     posses[].id                    o número do item (o id da gravação, recurso=listas)
 *     posses[].ordem                 a ordem na lista
 *     posses[].comportamento         o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     posses[].texto                 o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     posses[].cor                   a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     relogio.estanqueidades         os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     relogio.estanqueidades[]       um item da lista
 *     relogio.estanqueidades[].valor o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     relogio.estanqueidades[].nome  o nome do item
 *     relogio.estanqueidades[].id    o número do item (o id da gravação, recurso=listas)
 *     relogio.estanqueidades[].ordem a ordem na lista
 *     relogio.estanqueidades[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     relogio.estanqueidades[].texto o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     relogio.estanqueidades[].cor   a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     relogio.servico_tipos          os tipos de serviço, na ordem
 *     relogio.servico_tipos[]        um tipo
 *     relogio.servico_tipos[].valor  o tipo (o tipo da gravação)
 *     relogio.servico_tipos[].nome   o nome por extenso
 *     relogio.servico_tipos[].id     o número do item (o id da gravação, recurso=listas)
 *     relogio.servico_tipos[].ordem  a ordem na lista
 *     relogio.servico_tipos[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     relogio.servico_tipos[].texto  o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     relogio.servico_tipos[].cor    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     relogio.reserva.protocolos     os protocolos do teste, na ordem
 *     relogio.reserva.protocolos[]   um protocolo
 *     relogio.reserva.protocolos[].valor
 *                                    o identificador de um jeito de carregar da lista Jeitos de carregar (Cadastros › Listas: corda,
 *                                    winder, pulso, outro...)
 *     relogio.reserva.protocolos[].nome
 *                                    o nome por extenso
 *     relogio.reserva.protocolos[].id
 *                                    o número do item (o id da gravação, recurso=listas)
 *     relogio.reserva.protocolos[].ordem
 *                                    a ordem na lista
 *     relogio.reserva.protocolos[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     relogio.reserva.protocolos[].texto
 *                                    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     relogio.reserva.protocolos[].cor
 *                                    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     relogio.reserva.tipos_observacao
 *                                    os tipos das observações, na ordem
 *     relogio.reserva.tipos_observacao[]
 *                                    um tipo
 *     relogio.reserva.tipos_observacao[].valor
 *                                    o identificador de uma observação da lista Observações da reserva (Cadastros › Listas: parou,
 *                                    carga_baixa, nota...)
 *     relogio.reserva.tipos_observacao[].nome
 *                                    o nome por extenso
 *     relogio.reserva.tipos_observacao[].id
 *                                    o número do item (o id da gravação, recurso=listas)
 *     relogio.reserva.tipos_observacao[].ordem
 *                                    a ordem na lista
 *     relogio.reserva.tipos_observacao[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     relogio.reserva.tipos_observacao[].texto
 *                                    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     relogio.reserva.tipos_observacao[].cor
 *                                    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     relogio.posse_comportamento    o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
 *     relogio.manutencao_aberta      a manutenção aberta (o relógio na oficina agora: o quadro Manutenção); null: não está em manutenção
 *     relogio.manutencao_aberta.id   o número da manutenção
 *     relogio.manutencao_aberta.relogio_id
 *                                    o relógio
 *     relogio.manutencao_aberta.relogio
 *                                    o nome do relógio
 *     relogio.manutencao_aberta.oficina_id
 *                                    a oficina (Manutenção › Oficinas)
 *     relogio.manutencao_aberta.oficina
 *                                    o nome da oficina
 *     relogio.manutencao_aberta.situacao
 *                                    a situação agora: o identificador de um item da lista Situações da manutenção (Manutenção › Listas:
 *                                    enviado, orcamento, aprovado, peca, pronto, retirado...)
 *     relogio.manutencao_aberta.situacao_texto
 *                                    a situação por extenso
 *     relogio.manutencao_aberta.situacao_comportamento
 *                                    o que a situação faz: aberta (o relógio na oficina), encerra (voltou e virou serviço) ou cancela
 *                                    (voltou sem serviço)
 *     relogio.manutencao_aberta.situacao_cor
 *                                    a cor da situação (#rrggbb); null: sem
 *     relogio.manutencao_aberta.aviso
 *                                    aberta: o aviso da situação de agora (Aprovar o orçamento, Retirar na oficina); null: sem aviso
 *     relogio.manutencao_aberta.aberta
 *                                    true com o relógio na oficina agora (a situação faz aberta)
 *     relogio.manutencao_aberta.tipo o tipo de serviço pedido: o identificador de um item da lista Tipos de serviço
 *     relogio.manutencao_aberta.tipo_texto
 *                                    o tipo de serviço por extenso
 *     relogio.manutencao_aberta.problema
 *                                    o problema (o que se pediu à oficina); null: sem
 *     relogio.manutencao_aberta.os   o número da ordem de serviço da oficina; null: sem
 *     relogio.manutencao_aberta.enviado
 *                                    o dia do envio (AAAA-MM-DD)
 *     relogio.manutencao_aberta.previsao
 *                                    o dia previsto para a volta (AAAA-MM-DD); null: sem
 *     relogio.manutencao_aberta.atrasada
 *                                    true se ela está aberta e a previsão já passou (o aviso Cobrar a oficina)
 *     relogio.manutencao_aberta.dias quantos dias o relógio ficou (ou está) na oficina: do envio à volta, ou até hoje
 *     relogio.manutencao_aberta.orcamento
 *                                    o orçamento, em R$; null: sem
 *     relogio.manutencao_aberta.orcamento_data
 *                                    o dia do orçamento (AAAA-MM-DD); null: sem
 *     relogio.manutencao_aberta.encerrado
 *                                    o dia da volta (AAAA-MM-DD, numa situação que encerra ou cancela); null: aberta
 *     relogio.manutencao_aberta.disponivel_antes
 *                                    a disponibilidade para o rodízio com que o relógio volta (a de antes do envio, ou a pedida durante a
 *                                    manutenção)
 *     relogio.manutencao_aberta.observacao
 *                                    a observação; null: sem
 *     relogio.manutencao_aberta.desde
 *                                    quando ela entrou na situação de agora (a última mudança da história)
 *     relogio.manutencao_aberta.eventos
 *                                    a história: cada mudança de situação, da mais velha para a mais nova
 *     relogio.manutencao_aberta.eventos[]
 *                                    uma mudança
 *     relogio.manutencao_aberta.eventos[].situacao
 *                                    o identificador da situação
 *     relogio.manutencao_aberta.eventos[].situacao_texto
 *                                    a situação por extenso
 *     relogio.manutencao_aberta.eventos[].quando
 *                                    quando (texto AAAA-MM-DD HH:MM:SS)
 *     relogio.manutencao_aberta.eventos[].observacao
 *                                    a observação da mudança; null: sem
 *     relogio.manutencao_aberta.acessorios
 *                                    o que foi junto (os acessórios da coleção)
 *     relogio.manutencao_aberta.acessorios[]
 *                                    um acessório
 *     relogio.manutencao_aberta.acessorios[].id
 *                                    o número do acessório
 *     relogio.manutencao_aberta.acessorios[].nome
 *                                    o nome do acessório
 *     relogio.manutencao_aberta.acessorios[].tipo_texto
 *                                    o tipo do acessório por extenso
 *     relogio.manutencao_aberta.documentos
 *                                    os documentos do envio (os do relógio: a OS, o orçamento, as fotos da entrega)
 *     relogio.manutencao_aberta.documentos[]
 *                                    um documento
 *     relogio.manutencao_aberta.documentos[].id
 *                                    o número do documento
 *     relogio.manutencao_aberta.documentos[].titulo
 *                                    o título
 *     relogio.manutencao_aberta.documentos[].url
 *                                    o endereço do arquivo (recurso=documento)
 *     relogio.manutencao_aberta.servicos
 *                                    os serviços que a volta registrou (recurso=servicos)
 *     relogio.manutencao_aberta.servicos[]
 *                                    um serviço
 *     relogio.manutencao_aberta.servicos[].id
 *                                    o número do serviço
 *     relogio.manutencao_aberta.servicos[].data
 *                                    a data do serviço (AAAA-MM-DD)
 *     relogio.manutencao_aberta.servicos[].tipo_texto
 *                                    o tipo do serviço por extenso
 *     relogio.manutencao_aberta.servicos[].custo
 *                                    o custo do serviço (a mão de obra mais as peças), em R$; null: nada com custo
 *     relogio.manutencao_aberta.custo
 *                                    o custo da manutenção: os serviços dela somados, em R$; null: nada com custo
 *     relogio.manutencao_aberta.criado
 *                                    quando foi cadastrada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.manutencoes_oficina    as manutenções do relógio (as idas à oficina), da mais nova para a mais velha
 *     relogio.manutencoes_oficina[]  uma manutenção
 *     relogio.manutencoes_oficina[].id
 *                                    o número da manutenção
 *     relogio.manutencoes_oficina[].relogio_id
 *                                    o relógio
 *     relogio.manutencoes_oficina[].relogio
 *                                    o nome do relógio
 *     relogio.manutencoes_oficina[].oficina_id
 *                                    a oficina (Manutenção › Oficinas)
 *     relogio.manutencoes_oficina[].oficina
 *                                    o nome da oficina
 *     relogio.manutencoes_oficina[].situacao
 *                                    a situação agora: o identificador de um item da lista Situações da manutenção (Manutenção › Listas:
 *                                    enviado, orcamento, aprovado, peca, pronto, retirado...)
 *     relogio.manutencoes_oficina[].situacao_texto
 *                                    a situação por extenso
 *     relogio.manutencoes_oficina[].situacao_comportamento
 *                                    o que a situação faz: aberta (o relógio na oficina), encerra (voltou e virou serviço) ou cancela
 *                                    (voltou sem serviço)
 *     relogio.manutencoes_oficina[].situacao_cor
 *                                    a cor da situação (#rrggbb); null: sem
 *     relogio.manutencoes_oficina[].aviso
 *                                    aberta: o aviso da situação de agora (Aprovar o orçamento, Retirar na oficina); null: sem aviso
 *     relogio.manutencoes_oficina[].aberta
 *                                    true com o relógio na oficina agora (a situação faz aberta)
 *     relogio.manutencoes_oficina[].tipo
 *                                    o tipo de serviço pedido: o identificador de um item da lista Tipos de serviço
 *     relogio.manutencoes_oficina[].tipo_texto
 *                                    o tipo de serviço por extenso
 *     relogio.manutencoes_oficina[].problema
 *                                    o problema (o que se pediu à oficina); null: sem
 *     relogio.manutencoes_oficina[].os
 *                                    o número da ordem de serviço da oficina; null: sem
 *     relogio.manutencoes_oficina[].enviado
 *                                    o dia do envio (AAAA-MM-DD)
 *     relogio.manutencoes_oficina[].previsao
 *                                    o dia previsto para a volta (AAAA-MM-DD); null: sem
 *     relogio.manutencoes_oficina[].atrasada
 *                                    true se ela está aberta e a previsão já passou (o aviso Cobrar a oficina)
 *     relogio.manutencoes_oficina[].dias
 *                                    quantos dias o relógio ficou (ou está) na oficina: do envio à volta, ou até hoje
 *     relogio.manutencoes_oficina[].orcamento
 *                                    o orçamento, em R$; null: sem
 *     relogio.manutencoes_oficina[].orcamento_data
 *                                    o dia do orçamento (AAAA-MM-DD); null: sem
 *     relogio.manutencoes_oficina[].encerrado
 *                                    o dia da volta (AAAA-MM-DD, numa situação que encerra ou cancela); null: aberta
 *     relogio.manutencoes_oficina[].disponivel_antes
 *                                    a disponibilidade para o rodízio com que o relógio volta (a de antes do envio, ou a pedida durante a
 *                                    manutenção)
 *     relogio.manutencoes_oficina[].observacao
 *                                    a observação; null: sem
 *     relogio.manutencoes_oficina[].desde
 *                                    quando ela entrou na situação de agora (a última mudança da história)
 *     relogio.manutencoes_oficina[].eventos
 *                                    a história: cada mudança de situação, da mais velha para a mais nova
 *     relogio.manutencoes_oficina[].eventos[]
 *                                    uma mudança
 *     relogio.manutencoes_oficina[].eventos[].situacao
 *                                    o identificador da situação
 *     relogio.manutencoes_oficina[].eventos[].situacao_texto
 *                                    a situação por extenso
 *     relogio.manutencoes_oficina[].eventos[].quando
 *                                    quando (texto AAAA-MM-DD HH:MM:SS)
 *     relogio.manutencoes_oficina[].eventos[].observacao
 *                                    a observação da mudança; null: sem
 *     relogio.manutencoes_oficina[].acessorios
 *                                    o que foi junto (os acessórios da coleção)
 *     relogio.manutencoes_oficina[].acessorios[]
 *                                    um acessório
 *     relogio.manutencoes_oficina[].acessorios[].id
 *                                    o número do acessório
 *     relogio.manutencoes_oficina[].acessorios[].nome
 *                                    o nome do acessório
 *     relogio.manutencoes_oficina[].acessorios[].tipo_texto
 *                                    o tipo do acessório por extenso
 *     relogio.manutencoes_oficina[].documentos
 *                                    os documentos do envio (os do relógio: a OS, o orçamento, as fotos da entrega)
 *     relogio.manutencoes_oficina[].documentos[]
 *                                    um documento
 *     relogio.manutencoes_oficina[].documentos[].id
 *                                    o número do documento
 *     relogio.manutencoes_oficina[].documentos[].titulo
 *                                    o título
 *     relogio.manutencoes_oficina[].documentos[].url
 *                                    o endereço do arquivo (recurso=documento)
 *     relogio.manutencoes_oficina[].servicos
 *                                    os serviços que a volta registrou (recurso=servicos)
 *     relogio.manutencoes_oficina[].servicos[]
 *                                    um serviço
 *     relogio.manutencoes_oficina[].servicos[].id
 *                                    o número do serviço
 *     relogio.manutencoes_oficina[].servicos[].data
 *                                    a data do serviço (AAAA-MM-DD)
 *     relogio.manutencoes_oficina[].servicos[].tipo_texto
 *                                    o tipo do serviço por extenso
 *     relogio.manutencoes_oficina[].servicos[].custo
 *                                    o custo do serviço (a mão de obra mais as peças), em R$; null: nada com custo
 *     relogio.manutencoes_oficina[].custo
 *                                    o custo da manutenção: os serviços dela somados, em R$; null: nada com custo
 *     relogio.manutencoes_oficina[].criado
 *                                    quando foi cadastrada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.oficinas               as oficinas da coleção (o formulário do serviço: sem manutenção, ele ganha uma já encerrada na oficina
 *                                    escolhida)
 *     relogio.oficinas[]             uma oficina
 *     relogio.oficinas[].id          o número da oficina
 *     relogio.oficinas[].nome        o nome da oficina
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
 *     campos[].do_calibre            o valor que o calibre do relógio define para o campo (o que vale quando o relógio não tem o dele);
 *                                    null: sem
 *     campos[].secao                 o nome da divisória da ficha em que o campo fica; null: nenhuma. Os campos vêm na ordem da ficha:
 *                                    pelas divisórias, os sem divisória no fim
 *     campos[].multiplo              verdadeiro: o campo é múltiplo (vários dos valores possíveis de uma vez: uma caixa para cada; valor e
 *                                    do_calibre com um por linha)
 *     campos[].regra                 o mínimo e o máximo do número em texto, para mostrar embaixo da entrada ("de 20 a 60 mm"); null: sem
 *                                    limite
 *     calibres                       os calibres da árvore, pelo nome (o menu Calibre do cadastro)
 *     calibres[]                     um calibre
 *     calibres[].id                  o número do calibre (o calibre_id da gravação)
 *     calibres[].nome                o nome
 *     calibres[].fabricante          o fabricante; null: não informado
 *     grupos[]                       os grupos (o seletor do cadastro)
 *     grupos[].cadeia[]              os ids do grupo e dos de cima
 *     grupos[].caminho               o caminho
 *     grupos[].id                    o número
 *     hoje                           hoje (texto AAAA-MM-DD)
 *     copia_sistema                  a cópia no banco pela Configuração da coleção (documentos_copia), que vale sobre o relógio e o
 *                                    arquivo: verdadeiro (todos), todo arquivo vai; falso (nenhum), nenhum vai; null (vazio), cada relógio
 *                                    decide (a caixa do cadastro só aparece então)
 *     painel_modo                    como o relógio abre na página Hoje (a Configuração): lado (no painel à direita da lista, o padrão) ou
 *                                    flutuante (numa janela grande por cima da página); no celular, os dois cobrem a tela
 *     trocar_hoje                    verdadeiro: o relógio de hoje se troca no próprio dia (a Configuração, trocar_hoje); falso, o padrão:
 *                                    só a partir de amanhã, e as telas não mostram as trocas de hoje (o trocar por… do dia de hoje, o
 *                                    Usando hoje, o inclusive hoje, o Sem relógio hoje, o Excluindo hoje do Apagar tudo; no Rodízio manual,
 *                                    hoje fica parado)
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
 *     relogio.dados[].origem         de onde vem o valor usado: informado (o do relógio), calibre (o do calibre dele, quando o relógio não
 *                                    tem), padrao (o padrão do campo) ou vazio
 *     relogio.dados[].origem_texto   de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
 *     relogio.dados[].tambem         o valor também na unidade em que a dele aparece (Cadastros › Unidades, mostrar também em), em texto
 *                                    ("1,85 pol"); null: sem número ou sem essa unidade
 *     relogio.dados[].secao          o nome da divisória da ficha do campo; null: nenhuma. Os dados vêm na ordem da ficha: pelas
 *                                    divisórias, os sem divisória no fim
 *     relogio.dados[].multiplo       verdadeiro: o campo é múltiplo (o valor e o valor_usado vêm com um por linha)
 *     relogio.dados[].tipo           inteiro, decimal, sim_nao, data, lista ou texto
 *     relogio.dados[].unidade        a unidade (texto)
 *     relogio.dados[].valor          o valor informado no relógio (texto; null: não preenchido)
 *     relogio.dados[].valor_usado    o valor que as contas usam: o informado, senão o padrão do campo (null: nenhum)
 *     relogio.desde                  desde quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.disponivel             verdadeiro ou falso: entra no rodízio
 *     relogio.copia_banco            verdadeiro: o relógio pede a cópia no banco de todos os documentos dele (o segundo dos três níveis da
 *                                    cópia; a Configuração da coleção, documentos_copia, se escolhe, vale por cima)
 *     relogio.calibre                o calibre (o movimento) do relógio; null: sem calibre
 *     relogio.calibre.id             o número do calibre
 *     relogio.calibre.nome           o nome do calibre
 *     relogio.calibre.fabricante     o fabricante; null: não informado
 *     relogio.calibre.link           o link do manual técnico; null: sem
 *     relogio.calibre.observacao     as recomendações; null: sem
 *     relogio.calibre.documentos     os documentos do calibre (o manual técnico...), na ordem das categorias
 *     relogio.calibre.documentos[]   um documento: {id, titulo, categoria, familia, url}
 *     relogio.calibre.documentos[].id
 *                                    o número do documento
 *     relogio.calibre.documentos[].titulo
 *                                    o título
 *     relogio.calibre.documentos[].categoria
 *                                    o nome da categoria
 *     relogio.calibre.documentos[].familia
 *                                    imagem, video, audio, pdf, xml ou outro
 *     relogio.calibre.documentos[].url
 *                                    o endereço do arquivo (recurso=documento)
 *     relogio.posse                  a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
 *     relogio.posse_texto            a mesma por extenso ("Na coleção", "Desejado"...)
 *     relogio.da_colecao             verdadeiro: é da coleção agora (a situação faz colecao ou emprestado); falso: desejado ou fora da
 *                                    coleção
 *     relogio.acessorios[]           os acessórios dele (o quadro Acessórios), pelo tipo e pelo nome
 *     relogio.acessorios[].id        o número do acessório
 *     relogio.acessorios[].nome      o nome
 *     relogio.acessorios[].tipo      o tipo: o identificador de um item da lista Tipos de acessório (Cadastros › Listas: pulseira, caixa,
 *                                    estojo, ferramenta, carregador, peca, outro...)
 *     relogio.acessorios[].tipo_texto
 *                                    o tipo por extenso
 *     relogio.acessorios[].relogio_id
 *                                    este relógio
 *     relogio.acessorios[].relogio   o nome dele
 *     relogio.acessorios[].observacao
 *                                    a observação (null)
 *     relogio.acessorios[].criado    quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.acessorios[].valores   os campos do acessório preenchidos (Cadastros › Campos, para acessório: os do tipo dele), na ordem
 *     relogio.acessorios[].valores[] um campo do acessório
 *     relogio.acessorios[].valores[].identificador
 *                                    o identificador do campo
 *     relogio.acessorios[].valores[].nome
 *                                    o nome do campo
 *     relogio.acessorios[].valores[].valor
 *                                    o valor gravado (texto normalizado: número com ponto; o múltiplo, um por linha)
 *     relogio.acessorios[].valores[].texto
 *                                    o valor para mostrar, no idioma, com a unidade ("20 mm (0,79 pol)"; o múltiplo separado por vírgula)
 *     relogio.acessorios[].serve_por o que decide em que relógio ele serve: cada campo do relógio com que um campo dele bate, com o valor
 *                                    dele ("Entre alças (largura da pulseira): 20 mm"); null: ele não preencheu nenhum desses campos
 *     relogio.acessorios[].serve_por[]
 *                                    um campo do relógio e o valor que ele tem de ter
 *     relogio.acessorios[].serve_em  os relógios em que ele serve (os da coleção, emprestados e desejados cujo valor bate com o dele em
 *                                    todos esses campos); null: não dá para saber (ele não preencheu nenhum desses campos)
 *     relogio.acessorios[].serve_em[]
 *                                    um relógio em que ele serve
 *     relogio.acessorios[].serve_em[].relogio_id
 *                                    o número do relógio
 *     relogio.acessorios[].serve_em[].relogio
 *                                    o nome do relógio
 *     relogio.acessorios_servem      os acessórios de outros relógios, ou soltos, que servem neste (a pulseira com a largura do entre alças
 *                                    dele): a troca possível
 *     relogio.winder_cadastrado      true: a coleção tem winder (o pôr no winder é só pela página Winders, e o período que já passou no
 *                                    winder pede a posição); false: sem winder, não há pôr nem tirar do winder (só o tirar de uma sessão
 *                                    que já estava aberta)
 *     relogio.winder_lugar           o lugar fixo dele num winder (a posição em que foi posto por último): pôr no winder daqui o devolve
 *                                    para lá, se está livre; null: sem lugar ou sem winder cadastrado
 *     relogio.winder_lugar.posicao_id
 *                                    a posição
 *     relogio.winder_lugar.texto     o winder e a posição por extenso ("Versa, Pos. 2")
 *     relogio.winder_lugar.livre     verdadeiro: a posição está ligada e sem outro relógio (o botão do painel o põe de volta)
 *     relogio.winder_lugar.motivo    por que não dá ("o lugar dele (Versa, Pos. 2) está com o Seiko 5"); null: livre
 *     relogio.winder_posicoes        todas as posições de winder, para o período que já passou no winder (a posição onde ele esteve); vazio
 *                                    sem winder cadastrado ou sem um tipo marcado como winder
 *     relogio.winder_posicoes[]      uma posição livre
 *     relogio.winder_posicoes[].id   o número da posição (o posicao_id do período)
 *     relogio.winder_posicoes[].winder_id
 *                                    o winder dela
 *     relogio.winder_posicoes[].texto
 *                                    o winder e a posição por extenso ("Escritório, Pos. 1")
 *     relogio.winder_posicoes[].programa
 *                                    o programa da posição, o nome e o cálculo ("Versa 650 · 650 voltas por dia (325 horário + 325
 *                                    anti-horário) · ciclo de 20 min, 72 vezes por dia"); null: sem programa
 *     relogio.acessorios_servem[]    um acessório que serve
 *     relogio.acessorios_servem[].id o número do acessório
 *     relogio.acessorios_servem[].nome
 *                                    o nome do acessório
 *     relogio.acessorios_servem[].tipo
 *                                    o tipo: o identificador de um item da lista Tipos de acessório (Cadastros › Listas: pulseira, caixa,
 *                                    estojo, ferramenta, carregador, peca, outro...)
 *     relogio.acessorios_servem[].tipo_texto
 *                                    o tipo, no idioma
 *     relogio.acessorios_servem[].relogio_id
 *                                    o relógio de quem ele é; null: solto
 *     relogio.acessorios_servem[].relogio
 *                                    o nome desse relógio; null: solto
 *     relogio.acessorios_servem[].serve_por
 *                                    o que decide em que relógio ele serve: cada campo do relógio com que um campo dele bate, com o valor
 *                                    dele ("Entre alças (largura da pulseira): 20 mm"); null: ele não preencheu nenhum desses campos
 *     relogio.acessorios_servem[].serve_por[]
 *                                    um campo do relógio e o valor que ele tem de ter
 *     relogio.servicos               os serviços e diagnósticos dele (o quadro Serviços e diagnósticos), do mais novo para o mais velho
 *     relogio.servicos[]             um serviço
 *     relogio.servicos[].id          o número do serviço (o id da gravação)
 *     relogio.servicos[].relogio_id  o relógio do serviço
 *     relogio.servicos[].relogio     o nome do relógio
 *     relogio.servicos[].data        a data do serviço (AAAA-MM-DD)
 *     relogio.servicos[].ha_dias     há quantos dias foi (no dia da coleção)
 *     relogio.servicos[].tipo        o tipo: o identificador de um item da lista Tipos de serviço (Manutenção › Listas: revisao, pilha,
 *                                    estanqueidade, regulagem, polimento...)
 *     relogio.servicos[].tipo_texto  o tipo por extenso
 *     relogio.servicos[].oficina     a oficina do serviço (a da manutenção de que ele é o encerramento: Manutenção › Oficinas)
 *     relogio.servicos[].descricao   o que foi feito; null: não informado
 *     relogio.servicos[].mao_de_obra a mão de obra, em R$; null: não informada
 *     relogio.servicos[].pecas       as peças trocadas, na ordem
 *     relogio.servicos[].pecas[]     uma peça
 *     relogio.servicos[].pecas[].nome
 *                                    o nome da peça
 *     relogio.servicos[].pecas[].custo
 *                                    o custo da peça, em R$; null: não informado
 *     relogio.servicos[].custo_pecas a soma do custo das peças, em R$ (0 sem nenhuma com custo)
 *     relogio.servicos[].custo       o custo do serviço: a mão de obra mais as peças, em R$; null: nada com custo
 *     relogio.servicos[].estanqueidade
 *                                    o resultado do teste de estanqueidade: o identificador de um item da lista Resultados do teste de
 *                                    estanqueidade (Manutenção › Listas: aprovado, reprovado...); null: não testada
 *     relogio.servicos[].estanqueidade_texto
 *                                    o resultado por extenso (o nome do item, em minúsculas); null: não testada
 *     relogio.servicos[].estanqueidade_aprova
 *                                    o resultado aprova a vedação (o item do resultado faz aprova)? true ou false; null: não testada
 *     relogio.servicos[].manutencao_id
 *                                    a manutenção de que o serviço é o encerramento (recurso=manutencao)
 *     relogio.servicos[].oficina_id  o número da oficina
 *     relogio.servicos[].estanqueidade_bar
 *                                    a pressão do teste, em bar; null: não informada
 *     relogio.servicos[].marcha_antes
 *                                    a marcha antes do serviço, informada pelo profissional, em s/dia (+ adianta); null: não informada
 *     relogio.servicos[].marcha_depois
 *                                    a marcha depois do serviço, informada pelo profissional, em s/dia; null: não informada
 *     relogio.servicos[].marcha_antes_texto
 *                                    a marcha antes, pronta ("+25,0 s/dia"); null
 *     relogio.servicos[].marcha_depois_texto
 *                                    a marcha depois, pronta; null
 *     relogio.servicos[].medida_antes
 *                                    a marcha medida pelo sistema antes do serviço: os intervalos do desvio da hora que terminam nos 60
 *                                    dias antes da data; null: nenhum
 *     relogio.servicos[].medida_antes.marcha
 *                                    a marcha, em s/dia, pesada pelo tempo de cada intervalo
 *     relogio.servicos[].medida_antes.marcha_texto
 *                                    a marcha pronta
 *     relogio.servicos[].medida_antes.intervalos
 *                                    quantos intervalos entraram
 *     relogio.servicos[].medida_depois
 *                                    a marcha medida pelo sistema depois do serviço: os intervalos que começam nos 60 dias depois do dia do
 *                                    serviço; null: nenhum
 *     relogio.servicos[].medida_depois.marcha
 *                                    a marcha, em s/dia
 *     relogio.servicos[].medida_depois.marcha_texto
 *                                    a marcha pronta
 *     relogio.servicos[].medida_depois.intervalos
 *                                    quantos intervalos entraram
 *     relogio.servicos[].garantia_ate
 *                                    a garantia do serviço até (AAAA-MM-DD); null: sem
 *     relogio.servicos[].garantia_vale
 *                                    true se a garantia ainda vale hoje
 *     relogio.servicos[].observacao  a observação do profissional; null: sem
 *     relogio.servicos[].documentos  os documentos do relógio que são do serviço (a nota, o orçamento, as fotos), pelo título
 *     relogio.servicos[].documentos[]
 *                                    um documento
 *     relogio.servicos[].documentos[].id
 *                                    o número do documento
 *     relogio.servicos[].documentos[].titulo
 *                                    o título
 *     relogio.servicos[].documentos[].url
 *                                    o endereço do arquivo (recurso=documento)
 *     relogio.servicos[].marcacao    true se o serviço virou também a marcação do tipo de lançamento que o tipo de serviço marca (o texto
 *                                    do item: revisao, pilha)
 *     relogio.servicos[].criado      quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.servicos_resumo        o resumo dos serviços (a resistência declarada, a estanqueidade comprovada, quantos, o custo somado, o
 *                                    último)
 *     relogio.servicos_resumo.quantos
 *                                    quantos serviços o relógio tem
 *     relogio.servicos_resumo.custo  o custo somado dos serviços, em R$; null: nenhum com custo
 *     relogio.servicos_resumo.ultimo o último serviço; null: nenhum
 *     relogio.servicos_resumo.ultimo.data
 *                                    a data dele (AAAA-MM-DD)
 *     relogio.servicos_resumo.ultimo.tipo
 *                                    o tipo
 *     relogio.servicos_resumo.ultimo.tipo_texto
 *                                    o tipo por extenso
 *     relogio.servicos_resumo.ultimo.ha_dias
 *                                    há quantos dias
 *     relogio.servicos_resumo.resistencia_declarada
 *                                    a resistência à água declarada pelo fabricante, em metros (o campo resistencia_agua_declarada do
 *                                    cadastro); null: não informada
 *     relogio.servicos_resumo.estanqueidade
 *                                    a estanqueidade comprovada: o último serviço com o teste; null: nunca testada aqui
 *     relogio.servicos_resumo.estanqueidade.data
 *                                    a data do teste
 *     relogio.servicos_resumo.estanqueidade.ha_dias
 *                                    há quantos dias
 *     relogio.servicos_resumo.estanqueidade.resultado
 *                                    o identificador do resultado (Manutenção › Listas: aprovado, reprovado...)
 *     relogio.servicos_resumo.estanqueidade.resultado_texto
 *                                    o resultado por extenso (o nome do item, em minúsculas)
 *     relogio.servicos_resumo.estanqueidade.aprova
 *                                    true se o resultado aprova a vedação; false se reprova
 *     relogio.servicos_resumo.estanqueidade.bar
 *                                    a pressão do teste, em bar; null
 *     relogio.servicos_resumo.estanqueidade.servico_id
 *                                    o serviço do teste
 *     relogio.servicos_resumo.testes_estanqueidade
 *                                    quantos serviços tiveram o teste de estanqueidade
 *     relogio.servico_tipos[].marca  true se o serviço desse tipo vira também a marcação de mesmo nome (revisao, pilha)
 *     relogio.servico_documentos     os documentos do relógio que um serviço pode citar (o formulário)
 *     relogio.servico_documentos[]   um documento
 *     relogio.servico_documentos[].id
 *                                    o número do documento
 *     relogio.servico_documentos[].titulo
 *                                    o título
 *     relogio.timegrapher            o timegrapher (o quadro Timegrapher, na seção Precisão e marcha): nos relógios que medem a marcha ou
 *                                    que já têm medição; null nos outros
 *     relogio.timegrapher.resumo     a frase da última sessão (a média de cada grandeza e a diferença entre as posições), ou o que falta
 *     relogio.timegrapher.sessao     a última sessão: as medições do mesmo dia da mais nova; null: nenhuma medição
 *     relogio.timegrapher.sessao.dia o dia (AAAA-MM-DD)
 *     relogio.timegrapher.sessao.medicoes
 *                                    quantas medições no dia
 *     relogio.timegrapher.sessao.posicoes
 *                                    quantas posições diferentes com a marcha
 *     relogio.timegrapher.sessao.delta
 *                                    a diferença de marcha entre as posições (a maior menos a menor, s/dia); null com menos de duas
 *     relogio.timegrapher.sessao.delta_texto
 *                                    a diferença por extenso
 *     relogio.timegrapher.sessao.marcha
 *                                    a marcha: a média da sessão; null sem medida
 *     relogio.timegrapher.sessao.marcha_texto
 *                                    a marcha por extenso
 *     relogio.timegrapher.sessao.amplitude
 *                                    a amplitude: a média da sessão; null sem medida
 *     relogio.timegrapher.sessao.amplitude_texto
 *                                    a amplitude por extenso
 *     relogio.timegrapher.sessao.erro_batida
 *                                    o erro de batida: a média da sessão; null sem medida
 *     relogio.timegrapher.sessao.erro_batida_texto
 *                                    o erro de batida por extenso
 *     relogio.timegrapher.sessao.bph as batidas por hora: a média da sessão; null sem medida
 *     relogio.timegrapher.sessao.bph_texto
 *                                    as batidas por hora por extenso
 *     relogio.timegrapher.sessao.angulo
 *                                    o ângulo de levantamento: a média da sessão; null sem medida
 *     relogio.timegrapher.sessao.angulo_texto
 *                                    o ângulo de levantamento por extenso
 *     relogio.timegrapher.sessao.itens[]
 *                                    cada medição da sessão (como em medicoes[])
 *     relogio.timegrapher.sessao.itens[].id
 *                                    o número da medição (o id da gravação)
 *     relogio.timegrapher.sessao.itens[].relogio_id
 *                                    o relógio
 *     relogio.timegrapher.sessao.itens[].relogio
 *                                    o nome do relógio
 *     relogio.timegrapher.sessao.itens[].quando
 *                                    quando foi medida (texto AAAA-MM-DD HH:MM:SS, no fuso do pedido)
 *     relogio.timegrapher.sessao.itens[].posicao
 *                                    a posição no aparelho: mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda ou
 *                                    coroa_direita; null: não informada
 *     relogio.timegrapher.sessao.itens[].posicao_texto
 *                                    a posição por extenso
 *     relogio.timegrapher.sessao.itens[].marcha
 *                                    a marcha, em segundos por dia (+ adianta, − atrasa); null: não medida
 *     relogio.timegrapher.sessao.itens[].marcha_texto
 *                                    a marcha por extenso ("+4,5 s/dia")
 *     relogio.timegrapher.sessao.itens[].amplitude
 *                                    a amplitude do balanço, em graus; null: não medida
 *     relogio.timegrapher.sessao.itens[].amplitude_texto
 *                                    a amplitude por extenso ("290°")
 *     relogio.timegrapher.sessao.itens[].erro_batida
 *                                    o erro de batida, em milissegundos; null: não medido
 *     relogio.timegrapher.sessao.itens[].erro_batida_texto
 *                                    o erro de batida por extenso ("0,3 ms")
 *     relogio.timegrapher.sessao.itens[].bph
 *                                    as batidas por hora (21600, 28800...); null: não informadas
 *     relogio.timegrapher.sessao.itens[].bph_texto
 *                                    as batidas por hora por extenso
 *     relogio.timegrapher.sessao.itens[].angulo
 *                                    o ângulo de levantamento (lift angle), em graus; null: não informado
 *     relogio.timegrapher.sessao.itens[].angulo_texto
 *                                    o ângulo por extenso
 *     relogio.timegrapher.sessao.itens[].origem
 *                                    manual (digitada) ou csv (importada do arquivo do aparelho)
 *     relogio.timegrapher.sessao.itens[].origem_texto
 *                                    a origem por extenso
 *     relogio.timegrapher.sessao.itens[].observacao
 *                                    a observação; null: nenhuma
 *     relogio.timegrapher.medicoes   todas as medições do relógio, da mais nova para a mais velha
 *     relogio.timegrapher.medicoes[] uma medição
 *     relogio.timegrapher.medicoes[].id
 *                                    o número da medição (o id da gravação)
 *     relogio.timegrapher.medicoes[].relogio_id
 *                                    o relógio
 *     relogio.timegrapher.medicoes[].relogio
 *                                    o nome do relógio
 *     relogio.timegrapher.medicoes[].quando
 *                                    quando foi medida (texto AAAA-MM-DD HH:MM:SS, no fuso do pedido)
 *     relogio.timegrapher.medicoes[].posicao
 *                                    a posição no aparelho: mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda ou
 *                                    coroa_direita; null: não informada
 *     relogio.timegrapher.medicoes[].posicao_texto
 *                                    a posição por extenso
 *     relogio.timegrapher.medicoes[].marcha
 *                                    a marcha, em segundos por dia (+ adianta, − atrasa); null: não medida
 *     relogio.timegrapher.medicoes[].marcha_texto
 *                                    a marcha por extenso ("+4,5 s/dia")
 *     relogio.timegrapher.medicoes[].amplitude
 *                                    a amplitude do balanço, em graus; null: não medida
 *     relogio.timegrapher.medicoes[].amplitude_texto
 *                                    a amplitude por extenso ("290°")
 *     relogio.timegrapher.medicoes[].erro_batida
 *                                    o erro de batida, em milissegundos; null: não medido
 *     relogio.timegrapher.medicoes[].erro_batida_texto
 *                                    o erro de batida por extenso ("0,3 ms")
 *     relogio.timegrapher.medicoes[].bph
 *                                    as batidas por hora (21600, 28800...); null: não informadas
 *     relogio.timegrapher.medicoes[].bph_texto
 *                                    as batidas por hora por extenso
 *     relogio.timegrapher.medicoes[].angulo
 *                                    o ângulo de levantamento (lift angle), em graus; null: não informado
 *     relogio.timegrapher.medicoes[].angulo_texto
 *                                    o ângulo por extenso
 *     relogio.timegrapher.medicoes[].origem
 *                                    manual (digitada) ou csv (importada do arquivo do aparelho)
 *     relogio.timegrapher.medicoes[].origem_texto
 *                                    a origem por extenso
 *     relogio.timegrapher.medicoes[].observacao
 *                                    a observação; null: nenhuma
 *     relogio.timegrapher.sessoes    quantas sessões (dias com medição)
 *     relogio.timegrapher.grandezas  as grandezas, para o formulário
 *     relogio.timegrapher.grandezas[]
 *                                    uma grandeza
 *     relogio.timegrapher.grandezas[].campo
 *                                    o nome do campo (marcha, amplitude, erro_batida, bph, angulo)
 *     relogio.timegrapher.grandezas[].nome
 *                                    o nome por extenso
 *     relogio.timegrapher.grandezas[].unidade
 *                                    a unidade (s/dia, °, ms, bph)
 *     relogio.timegrapher.grandezas[].minimo
 *                                    o menor valor aceito
 *     relogio.timegrapher.grandezas[].maximo
 *                                    o maior valor aceito
 *     relogio.timegrapher.posicoes   as posições do aparelho, para o formulário
 *     relogio.timegrapher.posicoes[] uma posição
 *     relogio.timegrapher.posicoes[].valor
 *                                    o código
 *     relogio.timegrapher.posicoes[].texto
 *                                    o nome por extenso
 *     relogio.reserva                a reserva medida (o quadro Reserva medida, ou Reserva de energia no solar); null: sem reserva no
 *                                    cadastro (o smartwatch, o de pilha)
 *     relogio.reserva.testar         true se o relógio faz o teste da reserva de marcha (o campo reserva_horas vale para ele: o mecânico)
 *     relogio.reserva.observar       true se registra as observações da energia (o mecânico e o solar, com o campo reserva_dias)
 *     relogio.reserva.aberto         o teste em andamento (como em testes[]); null: nenhum
 *     relogio.reserva.aberto.id      o número do teste ou da observação (o id da gravação)
 *     relogio.reserva.aberto.relogio_id
 *                                    o relógio
 *     relogio.reserva.aberto.relogio o nome do relógio
 *     relogio.reserva.aberto.tipo    teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     relogio.reserva.aberto.tipo_texto
 *                                    o tipo por extenso
 *     relogio.reserva.aberto.inicio  no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     relogio.reserva.aberto.fim     no teste, quando ele parou; null: em andamento (e nas observações)
 *     relogio.reserva.aberto.em_andamento
 *                                    true no teste ainda sem o fim
 *     relogio.reserva.aberto.horas   a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     relogio.reserva.aberto.horas_texto
 *                                    as horas prontas ("41,5 h"); null
 *     relogio.reserva.aberto.ate_agora
 *                                    no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     relogio.reserva.aberto.protocolo
 *                                    como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     relogio.reserva.aberto.protocolo_valor
 *                                    as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     relogio.reserva.aberto.protocolo_texto
 *                                    como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     relogio.reserva.aberto.observacao
 *                                    a observação; null: sem
 *     relogio.reserva.aberto.criado  quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.reserva.testes         os testes da reserva, do mais novo para o mais velho
 *     relogio.reserva.testes[]       um registro
 *     relogio.reserva.testes[].id    o número do teste ou da observação (o id da gravação)
 *     relogio.reserva.testes[].relogio_id
 *                                    o relógio
 *     relogio.reserva.testes[].relogio
 *                                    o nome do relógio
 *     relogio.reserva.testes[].tipo  teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     relogio.reserva.testes[].tipo_texto
 *                                    o tipo por extenso
 *     relogio.reserva.testes[].inicio
 *                                    no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     relogio.reserva.testes[].fim   no teste, quando ele parou; null: em andamento (e nas observações)
 *     relogio.reserva.testes[].em_andamento
 *                                    true no teste ainda sem o fim
 *     relogio.reserva.testes[].horas a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     relogio.reserva.testes[].horas_texto
 *                                    as horas prontas ("41,5 h"); null
 *     relogio.reserva.testes[].ate_agora
 *                                    no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     relogio.reserva.testes[].protocolo
 *                                    como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     relogio.reserva.testes[].protocolo_valor
 *                                    as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     relogio.reserva.testes[].protocolo_texto
 *                                    como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     relogio.reserva.testes[].observacao
 *                                    a observação; null: sem
 *     relogio.reserva.testes[].criado
 *                                    quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.reserva.observacoes    as observações da energia (parou, carga baixa, outra), da mais nova para a mais velha
 *     relogio.reserva.observacoes[]  um registro
 *     relogio.reserva.observacoes[].id
 *                                    o número do teste ou da observação (o id da gravação)
 *     relogio.reserva.observacoes[].relogio_id
 *                                    o relógio
 *     relogio.reserva.observacoes[].relogio
 *                                    o nome do relógio
 *     relogio.reserva.observacoes[].tipo
 *                                    teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     relogio.reserva.observacoes[].tipo_texto
 *                                    o tipo por extenso
 *     relogio.reserva.observacoes[].inicio
 *                                    no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     relogio.reserva.observacoes[].fim
 *                                    no teste, quando ele parou; null: em andamento (e nas observações)
 *     relogio.reserva.observacoes[].em_andamento
 *                                    true no teste ainda sem o fim
 *     relogio.reserva.observacoes[].horas
 *                                    a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     relogio.reserva.observacoes[].horas_texto
 *                                    as horas prontas ("41,5 h"); null
 *     relogio.reserva.observacoes[].ate_agora
 *                                    no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     relogio.reserva.observacoes[].protocolo
 *                                    como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     relogio.reserva.observacoes[].protocolo_valor
 *                                    as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     relogio.reserva.observacoes[].protocolo_texto
 *                                    como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     relogio.reserva.observacoes[].observacao
 *                                    a observação; null: sem
 *     relogio.reserva.observacoes[].criado
 *                                    quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.reserva.medida         a reserva medida: a mediana dos testes terminados; null: nenhum
 *     relogio.reserva.medida.testes  quantos testes terminados
 *     relogio.reserva.medida.horas   a mediana das horas de cheio até parado (resiste a um teste errado)
 *     relogio.reserva.medida.horas_texto
 *                                    a mediana pronta
 *     relogio.reserva.medida.variacao
 *                                    quanto os testes variam: um desvio padrão, em horas; null com um só
 *     relogio.reserva.medida.ultimo  quando o último teste terminou (texto AAAA-MM-DD HH:MM:SS)
 *     relogio.reserva.medida.ultimo_horas
 *                                    as horas do último teste
 *     relogio.reserva.cadastro_relogio
 *                                    a reserva de marcha no cadastro do próprio relógio, em horas (a que vale na conta, acima do calibre e
 *                                    do grupo); null: ele não tem
 *     relogio.reserva.usar_horas     o que "Usar a medida" (escrita reserva, acao=usar) gravaria no cadastro dele: a mediana em horas
 *                                    inteiras; null: sem medida, ou o cadastro já tem esse valor
 *     relogio.reserva.protocolos[].unidade
 *                                    o que o número diz (voltas, h); vazio: nada
 *     relogio.tecnico                declarado, medido e estimado: cada informação técnica numa tabelinha de três linhas, com a confiança
 *                                    (nos quadros Precisão, Reserva e Serviços e diagnósticos)
 *     relogio.tecnico[]              uma informação
 *     relogio.tecnico[].chave        marcha, reserva (de marcha, o mecânico), reserva_energia (o solar: só observações, sem calibrar) ou
 *                                    estanqueidade
 *     relogio.tecnico[].nome         o nome por extenso
 *     relogio.tecnico[].unidade      a unidade (s/dia, h, dias, m)
 *     relogio.tecnico[].declarado    o declarado: o que o fabricante, o calibre ou o cadastro diz (na reserva, sem o valor do próprio
 *                                    relógio, que vai no detalhe)
 *     relogio.tecnico[].declarado.valor
 *                                    o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     relogio.tecnico[].declarado.texto
 *                                    o valor pronto; null: não há
 *     relogio.tecnico[].declarado.detalhe
 *                                    de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     relogio.tecnico[].declarado.origem
 *                                    de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     relogio.tecnico[].declarado.origem_texto
 *                                    a origem por extenso; null
 *     relogio.tecnico[].declarado.data
 *                                    a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     relogio.tecnico[].medido       o último teste: a última marcha medida, o último teste da reserva, a última observação da energia, o
 *                                    último teste de estanqueidade
 *     relogio.tecnico[].medido.valor o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     relogio.tecnico[].medido.texto o valor pronto; null: não há
 *     relogio.tecnico[].medido.detalhe
 *                                    de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     relogio.tecnico[].medido.origem
 *                                    de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     relogio.tecnico[].medido.origem_texto
 *                                    a origem por extenso; null
 *     relogio.tecnico[].medido.data  a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     relogio.tecnico[].estimado     a estimativa de agora: a média pesada das marchas, a mediana dos testes da reserva (sem teste: o
 *                                    cadastro), a vedação pelo último teste; no solar, não calculada
 *     relogio.tecnico[].estimado.valor
 *                                    o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     relogio.tecnico[].estimado.texto
 *                                    o valor pronto; null: não há
 *     relogio.tecnico[].estimado.detalhe
 *                                    de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     relogio.tecnico[].estimado.origem
 *                                    de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     relogio.tecnico[].estimado.origem_texto
 *                                    a origem por extenso; null
 *     relogio.tecnico[].estimado.data
 *                                    a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     relogio.tecnico[].confianca    a confiança, pela regra única (quantas medições, a idade da última, quanto variam); null: nenhuma
 *                                    medição
 *     relogio.tecnico[].confianca.nivel
 *                                    alta, media ou baixa
 *     relogio.tecnico[].confianca.nivel_texto
 *                                    o nível por extenso
 *     relogio.tecnico[].confianca.medicoes
 *                                    quantas medições (ou testes)
 *     relogio.tecnico[].confianca.idade_dias
 *                                    há quantos dias foi a última
 *     relogio.tecnico[].confianca.variacao
 *                                    quanto elas variam: um desvio padrão, na unidade; null com uma só ou quando não se aplica
 *     relogio.tecnico[].confianca.tolerancia
 *                                    até quanto a variação ainda dá confiança alta (a marcha: a metade da faixa esperada, senão 5 s/dia; a
 *                                    reserva: 10% da medida, no mínimo 1 h); null: não se aplica
 *     relogio.tecnico[].confianca.texto
 *                                    o resumo das medições ("3 testes, o último há 12 dias, variação de ±1,1 h")
 *     relogio.tecnico[].confianca.motivos
 *                                    por que não é alta (menos medições que o mínimo, a última velha, variação grande); vazia na alta
 *     relogio.tecnico[].confianca.motivos[]
 *                                    um motivo, por extenso
 *     relogio.tecnico[].confianca.resumo
 *                                    a frase inteira, pronta para a tela
 *     relogio.tecnico[].fora         true se o último teste ficou fora do esperado (a marcha fora da faixa, a estanqueidade reprovada)
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
 *     relogio.leituras[].em_uso      verdadeiro ou falso: a leitura é de uso (a marcação dela, ou, sem marcação, o pulso registrado naquele
 *                                    instante)
 *     relogio.leituras[].estado      a marcação da leitura: uso ou repouso (a gravada, ou, sem marcação, a do pulso registrado naquele
 *                                    instante)
 *     relogio.leituras[].marcada     verdadeiro ou falso: a marcação foi gravada na leitura (falso: veio do pulso registrado)
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
 *     relogio.lancamentos_recentes[].estado
 *                                    numa leitura, a marcação gravada: uso, repouso ou null (sem marcação)
 *     relogio.lancamentos_recentes[].marcha
 *                                    o que o tipo mede da marcha: desvio, acerto ou null
 *     relogio.lancamentos_recentes[].posicao
 *                                    na leitura do desvio da hora, onde ele ficou desde a anterior (o código); null: não informada
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
 *                                    o estado do trecho: rodizio (no pulso pelo rodízio), pulso (em uso fora do plano: no pulso sem ser o
 *                                    relógio do dia), o identificador de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma
 *                                    marcação)
 *     relogio.linha_do_tempo[].fim   quando o trecho terminou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema); null numa marcação
 *     relogio.linha_do_tempo[].inicio
 *                                    quando o trecho começou (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.linha_do_tempo[].texto o trecho por extenso, como a tela mostra ("em uso", "no sol", "Leitura de carga 80%")
 *     relogio.linha_do_tempo[].tipo  o identificador do tipo de lançamento que originou o trecho (pulso, sol, corda, carga...); null no
 *                                    repouso
 *     relogio.encostado              o aviso Encostado deste relógio (o quadro Agora; não entra nas próximas manutenções); null: sem data
 *     relogio.encostado.data         a data (texto AAAA-MM-DD; hoje se já passou)
 *     relogio.encostado.estado       atrasado (passou do limite de dias sem ir ao pulso), em_breve (vence agora) ou ok (ainda dentro do
 *                                    prazo)
 *     relogio.encostado.falta        quanto falta, por extenso ("12d", "atrasado 40d")
 *     relogio.encostado.momento      o instante previsto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC))
 *     relogio.manutencoes[]          as 5 próximas manutenções (avisos, sem o Encostado)
 *     relogio.manutencoes[].data     a data (texto AAAA-MM-DD)
 *     relogio.manutencoes[].nome     o aviso
 *     relogio.medicao_janela_texto   a janela da conta do gasto medido por extenso (o "contar por" da Configuração: dias ou leituras)
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
 *     relogio.medicoes[].erro        o quanto a conta de agora erra a leitura desta medição que menos bate com as outras (a do começo ou a
 *                                    do fim), em pontos de carga (número; null fora da janela ou só no histórico)
 *     relogio.medicoes[].leitura_fraca
 *                                    o valor da leitura desta medição que menos bate com as outras (a do começo ou a do fim) (número; null
 *                                    fora da conta)
 *     relogio.medicoes[].fora        verdadeiro ou falso: uma leitura desta medição não bate com as outras e pesa menos da metade na conta
 *                                    (regressão robusta)
 *     relogio.medicoes[].marcacao_de a marcação da leitura do começo da medição: uso ou repouso (null fora da conta)
 *     relogio.medicoes[].marcacao_ate
 *                                    a marcação da leitura do fim da medição: uso ou repouso (null fora da conta)
 *     relogio.medicoes[].marcacoes_iguais
 *                                    verdadeiro ou falso, no motor pela marcação (e no das fórmulas): as duas leituras têm a mesma
 *                                    marcação, e a medição entra na conta daquele gasto; com marcações diferentes, não entra em nenhum dos
 *                                    dois. null nos outros motores (eles não escolhem pela marcação)
 *     relogio.medicoes[].na_media    verdadeiro ou falso: entra na conta agora (usada e dentro da janela da Configuração,
 *                                    medicao_janela_dias)
 *     relogio.medicoes[].peso        o peso, de 0 a 1, que a leitura desta medição que menos bate com as outras tem na conta (1: bate;
 *                                    menos: a regressão robusta a pesa menos) (número; null fora da conta)
 *     relogio.medicoes[].peso_horas  as horas que a medição cobriu, no pulso (uso) ou guardado (repouso) (número; a conta não pesa mais por
 *                                    horas: todas as medições pesam igual)
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
 *     relogio.previsao.conta[]       de onde vem cada número da conta: primeiro o gasto que vale no pulso e fora dele, depois o gasto
 *                                    medido pelas leituras e os campos do cadastro (os de um gasto em que vale o medido, com usado falso)
 *     relogio.previsao.conta[].usado verdadeiro: o número entra na conta; falso: um campo do cadastro que ficou fora, porque vale o gasto
 *                                    medido
 *     relogio.previsao.conta[].vale  verdadeiro nas duas primeiras linhas: o gasto que vale no pulso (taxa_uso) e fora dele (taxa_repouso)
 *     relogio.previsao.conta[].campo o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     relogio.previsao.conta[].detalhe
 *                                    no gasto medido, como ele foi medido ("conta dos dois gastos juntos, com 3 medições dos últimos 90
 *                                    dias"); null nos campos
 *     relogio.previsao.conta[].nome  o nome do campo, ou do gasto medido (Gasto em uso medido, Gasto fora do pulso medido)
 *     relogio.previsao.conta[].origem
 *                                    de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo), vazio (sem valor), medido (pelas leituras) ou cadastro (o gasto que vale saiu do cadastro)
 *     relogio.previsao.conta[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
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
 *     relogio.previsao.gasto.motor   o motor que calculou o gasto: horas, robusto, marcacao, cadastro ou formulas (veja config.gasto_motor)
 *     relogio.previsao.gasto.motor_nome
 *                                    o nome do motor por extenso ("Pela marcação: duas taxas separadas")
 *     relogio.previsao.gasto.motor_de
 *                                    de onde veio a escolha: colecao (a Configuração), relogio (o campo gasto_motor do relógio), grupo (o
 *                                    padrão do campo) ou padrao (nada escolhido: horas)
 *     relogio.previsao.gasto.base    o que a conta do gasto medido conta (o "contar por" da Configuração): dias ou leituras
 *     relogio.previsao.gasto.janela_leituras
 *                                    as últimas tantas medições de cada tipo que entram, com base leituras (número)
 *     relogio.previsao.gasto.janela_texto
 *                                    a janela por extenso: "dos últimos 90 dias" ou "das últimas 20 leituras de cada tipo"
 *     relogio.previsao.gasto.janela_dias
 *                                    a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)
 *     relogio.previsao.gasto.fora    quantas medições da janela têm uma leitura que não bate com as outras e pesa menos da metade na conta
 *                                    (número)
 *     relogio.previsao.gasto.medicoes
 *                                    quantas medições da janela entram na conta (número)
 *     relogio.previsao.gasto.medicoes_antes
 *                                    quantas medições há na janela anterior, a dos antes (número)
 *     relogio.previsao.gasto.uso.antes
 *                                    o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)
 *     relogio.previsao.gasto.uso.cadastro
 *                                    o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)
 *     relogio.previsao.gasto.uso.medido_dp
 *                                    a incerteza do gasto em uso medido: um desvio padrão, na mesma unidade (número; null sem medição)
 *     relogio.previsao.gasto.uso.medicoes
 *                                    quantas medições em uso entram na conta (número; no motor da marcação, as de leituras marcadas em uso
 *                                    nas duas pontas)
 *     relogio.previsao.gasto.uso.so_leituras
 *                                    o gasto em uso, em % por dia de uso, só pelas leituras: a mesma conta sem o cadastro como ponto de
 *                                    partida (número; null sem medição ou no motor só o cadastro)
 *     relogio.previsao.gasto.uso.puxado
 *                                    verdadeiro: o medido ficou diferente do que só as leituras dão, porque com poucas medições o cadastro
 *                                    ainda puxa a conta
 *     relogio.previsao.gasto.uso.medido
 *                                    o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null se as leituras
 *                                    não medem esse gasto: sem medição ou, no motor da marcação, nenhuma desse tipo)
 *     relogio.previsao.gasto.uso.vale
 *                                    o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)
 *     relogio.previsao.gasto.uso.campos[]
 *                                    os campos do cadastro de onde sai o gasto em uso do cadastro (o decaimento, a autonomia...): o painel
 *                                    mostra o medido ao lado deles
 *     relogio.previsao.gasto.uso.campos[].campo
 *                                    o identificador do campo
 *     relogio.previsao.gasto.uso.campos[].nome
 *                                    o nome do campo
 *     relogio.previsao.gasto.uso.campos[].origem
 *                                    de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo) ou vazio (sem valor)
 *     relogio.previsao.gasto.uso.campos[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
 *     relogio.previsao.gasto.uso.campos[].unidade
 *                                    a unidade do campo (texto)
 *     relogio.previsao.gasto.uso.campos[].valor
 *                                    o valor usado na conta (número; null se vazio)
 *     relogio.previsao.gasto.uso.formula
 *                                    o identificador da fórmula do gasto em uso (taxa_uso)
 *     relogio.previsao.gasto.repouso.antes
 *                                    o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)
 *     relogio.previsao.gasto.repouso.cadastro
 *                                    o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)
 *     relogio.previsao.gasto.repouso.medido_dp
 *                                    a incerteza do gasto fora do pulso medido: um desvio padrão, na mesma unidade (número; null sem
 *                                    medição)
 *     relogio.previsao.gasto.repouso.medicoes
 *                                    quantas medições em repouso entram na conta (número; no motor da marcação, as de leituras marcadas em
 *                                    repouso nas duas pontas)
 *     relogio.previsao.gasto.repouso.so_leituras
 *                                    o gasto fora do pulso, em % por dia, só pelas leituras: a mesma conta sem o cadastro como ponto de
 *                                    partida (número; null sem medição ou no motor só o cadastro)
 *     relogio.previsao.gasto.repouso.puxado
 *                                    verdadeiro: o medido ficou diferente do que só as leituras dão, porque com poucas medições o cadastro
 *                                    ainda puxa a conta
 *     relogio.previsao.gasto.repouso.medido
 *                                    o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null se as leituras
 *                                    não medem esse gasto: sem medição ou, no motor da marcação, nenhuma desse tipo)
 *     relogio.previsao.gasto.repouso.vale
 *                                    o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)
 *     relogio.previsao.gasto.repouso.campos[]
 *                                    os campos do cadastro de onde sai o gasto fora do pulso do cadastro (o decaimento, a autonomia...): o
 *                                    painel mostra o medido ao lado deles
 *     relogio.previsao.gasto.repouso.campos[].campo
 *                                    o identificador do campo
 *     relogio.previsao.gasto.repouso.campos[].nome
 *                                    o nome do campo
 *     relogio.previsao.gasto.repouso.campos[].origem
 *                                    de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo) ou vazio (sem valor)
 *     relogio.previsao.gasto.repouso.campos[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
 *     relogio.previsao.gasto.repouso.campos[].unidade
 *                                    a unidade do campo (texto)
 *     relogio.previsao.gasto.repouso.campos[].valor
 *                                    o valor usado na conta (número; null se vazio)
 *     relogio.previsao.gasto.repouso.formula
 *                                    o identificador da fórmula do gasto fora do pulso (taxa_repouso)
 *     relogio.previsao.leitura       a última leitura, no valor informado (número)
 *     relogio.previsao.leitura_em    quando foi a última leitura (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.previsao.limite        o limite de carga da Configuração (previsao_limite), em % (número)
 *     relogio.previsao.linhas[]      as frases da previsão, como a tela mostra (texto)
 *     relogio.previsao.precisa       quanto ele precisa ter na entrada para os dias seguidos no pulso, em % (número)
 *     relogio.previsao.proxima_entrada
 *                                    o próximo dia em que o relógio entra no rodízio (texto AAAA-MM-DD); null se não está no plano
 *     relogio.proxima                o próximo dia no plano (texto AAAA-MM-DD); null: não está no plano
 *     relogio.proxima_ate            até quando ele fica nesse dia
 *     relogio.proxima_um_dia         verdadeiro ou falso: ele fica só aquele dia (o dia seguinte do plano é de outro relógio); null sem
 *                                    próxima
 *     relogio.registros              quantos registros tem a linha do tempo inteira
 *     relogio.situacao[]             as frases de situação: as sessões abertas que o estado não mostra e a autonomia restante
 *     relogio.tipo                   o nome do grupo
 *     relogio.tipos[]                os tipos de lançamento do relógio (os botões de Lançar)
 *     relogio.tipos[].aberta         a sessão aberta desse tipo agora; null: nenhuma
 *     relogio.tipos[].aberta.inicio  desde quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio.tipos[].aberta.rodizio verdadeiro ou falso: é a sessão do rodízio
 *     relogio.tipos[].aberta.texto   por extenso ("desde 07:00 (3 h 12 min)"; numa posição de winder, com ela: "... · Escritório, Pos. 2")
 *     relogio.tipos[].aberta.posicao_id
 *                                    a posição do winder em que a sessão está; null: nenhuma
 *     relogio.tipos[].aberta.posicao o winder e a posição por extenso ("Escritório, Pos. 2"); null: nenhuma
 *     relogio.tipos[].formato        instantaneo, valor ou sessao
 *     relogio.tipos[].identificador  o tipo
 *     relogio.tipos[].mede_gasto     verdadeiro ou falso: a leitura mede o gasto (a caixa aparece)
 *     relogio.tipos[].nome           o nome
 *     relogio.tipos[].unidade        a unidade do valor
 *     relogio.tipos[].marcha         o que o tipo mede da marcha: desvio (a leitura do desvio da hora: o Marcar mostra o Testar a
 *                                    precisão), acerto (o acerto da hora) ou null
 *     relogio.tipos[].winder         verdadeiro: a sessão é numa posição de um winder (o winder é obrigatório: o pôr é só pela página
 *                                    Winders, e o período que já passou escolhe a posição, de winder_posicoes)
 *     relogio.precisao               a precisão do relógio (o quadro Precisão do painel): as leituras do desvio da hora e os acertos, a
 *                                    marcha (em segundos por dia: + adianta, − atrasa) e o desvio estimado agora; null se nenhum tipo de
 *                                    lançamento dele mede a marcha
 *     relogio.precisao.tipo_desvio   o identificador do tipo de lançamento da leitura do desvio (o lancar com marca, instante_ms e posicao)
 *     relogio.precisao.tipo_acerto   o identificador do tipo do acerto da hora; null se o relógio não tem
 *     relogio.precisao.resumo        a frase pronta ("Adianta 7,6 s por dia (média de 3 medições em 9 dias; elas variam ±2,8 s/dia).", ou o
 *                                    que falta para medir)
 *     relogio.precisao.medicoes      quantos intervalos medidos (de uma leitura, ou de um acerto, até a leitura seguinte)
 *     relogio.precisao.dias          o tempo somado dos intervalos, em dias
 *     relogio.precisao.marcha        a marcha do relógio, em s/dia: a soma das diferenças sobre a soma dos dias (um intervalo de 10 dias
 *                                    pesa dez vezes o de 1 dia); null sem medição
 *     relogio.precisao.marcha_texto  a marcha com o sinal e a unidade ("+7,6 s/dia")
 *     relogio.precisao.marcha_dp     quanto as marchas dos intervalos variam entre si (o desvio padrão, pesado pelo tempo, em s/dia); null
 *                                    com menos de dois
 *     relogio.precisao.marcha_dp_texto
 *                                    a variação por extenso ("±2,8 s/dia")
 *     relogio.precisao.ultima        a marcha do intervalo mais recente, em s/dia; null sem medição
 *     relogio.precisao.ultima_texto  a última com o sinal e a unidade
 *     relogio.precisao.desvio_agora  o desvio estimado agora, em segundos (+ adiantado): o último ponto (0 depois de um acerto) mais a
 *                                    marcha vezes o tempo desde ele; null sem leitura
 *     relogio.precisao.desvio_agora_texto
 *                                    por extenso ("8 s adiantado", "na hora")
 *     relogio.precisao.esperada      a marcha esperada (os campos marcha_min e marcha_max, do relógio ou do grupo)
 *     relogio.precisao.esperada.de   o mínimo, em s/dia; null sem
 *     relogio.precisao.esperada.ate  o máximo, em s/dia; null sem
 *     relogio.precisao.esperada.texto
 *                                    por extenso ("de −10 s/dia a +30 s/dia"); null sem nenhum dos dois
 *     relogio.precisao.dentro        verdadeiro se a última marcha está dentro da esperada; null sem a esperada ou sem medição
 *     relogio.precisao.por_posicao   a marcha em cada posição em que houve medição, na ordem das posições
 *     relogio.precisao.por_posicao[] uma posição
 *     relogio.precisao.por_posicao[].posicao
 *                                    a posição (o código)
 *     relogio.precisao.por_posicao[].posicao_texto
 *                                    a posição por extenso
 *     relogio.precisao.por_posicao[].medicoes
 *                                    quantos intervalos nela
 *     relogio.precisao.por_posicao[].dias
 *                                    o tempo deles, em dias
 *     relogio.precisao.por_posicao[].marcha
 *                                    a marcha nela, em s/dia
 *     relogio.precisao.por_posicao[].marcha_texto
 *                                    com o sinal e a unidade
 *     relogio.precisao.pontos        as leituras do desvio e os acertos da hora, do mais velho ao mais novo (o gráfico)
 *     relogio.precisao.pontos[]      um ponto
 *     relogio.precisao.pontos[].id   o id do lançamento
 *     relogio.precisao.pontos[].inicio
 *                                    quando (AAAA-MM-DD HH:MM:SS)
 *     relogio.precisao.pontos[].tipo desvio (a leitura) ou acerto (o acerto da hora)
 *     relogio.precisao.pontos[].desvio
 *                                    o desvio em segundos (+ adiantado); 0 no acerto
 *     relogio.precisao.pontos[].posicao
 *                                    onde ele ficou desde o ponto anterior (o código); null: não informada
 *     relogio.precisao.pontos[].posicao_texto
 *                                    a posição por extenso; null: não informada
 *     relogio.precisao.intervalos    os intervalos medidos, do mais novo ao mais velho
 *     relogio.precisao.intervalos[]  um intervalo: de um ponto até a leitura seguinte
 *     relogio.precisao.intervalos[].id
 *                                    o id do lançamento da leitura do fim
 *     relogio.precisao.intervalos[].de
 *                                    o começo (AAAA-MM-DD HH:MM:SS)
 *     relogio.precisao.intervalos[].ate
 *                                    o fim
 *     relogio.precisao.intervalos[].dias
 *                                    quanto tempo, em dias
 *     relogio.precisao.intervalos[].desvio_de
 *                                    o desvio no começo, em segundos (0 depois de um acerto)
 *     relogio.precisao.intervalos[].desvio_ate
 *                                    o desvio no fim
 *     relogio.precisao.intervalos[].marcha
 *                                    a marcha no intervalo, em s/dia
 *     relogio.precisao.intervalos[].marcha_texto
 *                                    com o sinal e a unidade
 *     relogio.precisao.intervalos[].posicao
 *                                    onde ele ficou (a posição da leitura do fim); null: não informada
 *     relogio.precisao.intervalos[].posicao_texto
 *                                    a posição por extenso ("não informada" sem ela)
 *     relogio.precisao.intervalos[].depois_do_acerto
 *                                    verdadeiro se o intervalo começa num acerto da hora
 *     relogio.precisao.posicoes      as posições, na ordem, para a lista da tela
 *     relogio.precisao.posicoes[]    uma posição
 *     relogio.precisao.posicoes[].valor
 *                                    o código (o posicao do lancar)
 *     relogio.precisao.posicoes[].texto
 *                                    por extenso
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
 *                                    a cópia no banco pela Configuração da coleção (documentos_copia), que vale sobre o relógio e o
 *                                    arquivo: verdadeiro (todos), todo arquivo vai; falso (nenhum), nenhum vai; null (vazio), cada relógio
 *                                    e cada arquivo decidem
 *     relogio.documentos.total       quantos documentos ele tem
 *   recurso=config
 *     mostrar_ate                    quantos dias as listas mostram por página (o "Mostrar até" da Configuração, já com o padrão)
 *     atualizar_segundos             o intervalo de atualização das páginas, já com o padrão (a Configuração, atualizar_segundos: 1 a
 *                                    86400; 0, não se atualizam sozinhas; padrão 60)
 *     relojinho                      o reloginho da barra e da aba (relojinho.js)
 *     relojinho.modo                 o modo, já com o padrão: hora, aleatorio ou sem_repeticao (a Configuração relojinho_modo)
 *     relojinho.modelos              quantos modelos o reloginho tem (24, um para cada hora do dia)
 *     mostrar_ate.proximos           os Próximos dias da página Hoje (proximos_por_pagina; padrão 7)
 *     mostrar_ate.plano              o dia a dia da página Plano (plano_por_pagina; padrão 31)
 *     aleatorio                      de onde vêm os números dos sorteios: RANDOM.ORG (ruído atmosférico); fora do ar, ANU QRNG (quântico);
 *                                    sem os dois, o gerador seguro do PHP (random_int)
 *     aleatorio.fontes[]             as fontes, na ordem em que o sistema tenta: RANDOM.ORG, ANU QRNG, gerador seguro do PHP (random_int)
 *     aleatorio.ultimo_lote          a fonte do último lote de números e quando ("RANDOM.ORG em AAAA-MM-DD HH:MM:SS"); null: nenhum ainda
 *     aleatorio.fora_desde           desde quando os dois serviços estão sem responder (AAAA-MM-DD HH:MM:SS): por 10 minutos o sistema nem
 *                                    tenta e usa o gerador do PHP; null: no ar (ou não se sabe)
 *     cron_estado                    a situação do cron, como a Configuração mostra (o cron roda a cada minuto)
 *     cron_estado.crontab            a linha do crontab que roda o sistema (com a pasta dele)
 *     cron_estado.erro               o erro guardado da última execução com erro (null: sem erro; some na primeira execução sem erro)
 *     cron_estado.minutos            há quantos minutos ele rodou pela última vez (null: nunca rodou)
 *     cron_estado.situacao           nunca (ainda não rodou), parado (mais de 10 minutos sem rodar) ou rodando
 *     cron_estado.texto              a frase da Configuração ("Cron rodando: última execução às 10:41."; parado e nunca trazem a linha do
 *                                    crontab)
 *     cron_estado.ultima             quando rodou pela última vez (AAAA-MM-DD HH:MM:SS, no fuso do sistema; null: nunca rodou)
 *     config.carga_limiar            o limite de carga geral (%): carregar quando a carga chega a ele (o campo carga_minima do relógio, se
 *                                    preenchido, vale no lugar)
 *     config.na_hora_enviados        os avisos "na hora" que já foram pelo Telegram e ainda estão vencidos (relogio_id:identificador,
 *                                    separados por vírgula): o cron não manda de novo
 *     config.escala_fim              até que dia vai a escala inteligente gerada (texto AAAA-MM-DD; vazio fora da escala)
 *     config.escala_gerada           o dia em que a escala foi gerada pela última vez (texto AAAA-MM-DD)
 *     config.horario_manha           a hora da rodada da manhã do cron: o relógio do dia e os avisos (HH:MM)
 *     config.horario_noite           a hora da rodada da noite: preparar o relógio de amanhã (HH:MM)
 *     config.max_sem_uso             a garantia de rodízio: nenhum relógio passa desses dias sem uso (0 desliga)
 *     config.medicao_base            o que o gasto medido conta ("contar por"): dias (as medições dos últimos medicao_janela_dias) ou
 *                                    leituras (as últimas medicao_janela_leituras de cada tipo, em uso e em repouso)
 *     config.gasto_motor             como calcular o gasto da bateria, para todos os relógios da coleção: horas (pelas horas registradas, o
 *                                    motor original), robusto (pelas horas registradas, com leituras e cadastro), marcacao (pela marcação
 *                                    das leituras: duas taxas separadas), cadastro (só o cadastro) ou formulas (pelas fórmulas taxa_uso e
 *                                    taxa_repouso); vazio: vale o do relógio (o campo gasto_motor do cadastro), senão o padrão desse campo
 *                                    (o grupo), senão horas
 *     config.documentos_copia        a cópia dos documentos no banco, para a coleção inteira: todos (todo arquivo vai também para o banco),
 *                                    nenhum (nenhum vai, nem o que o relógio ou o arquivo pedem) ou vazio (cada relógio e cada arquivo
 *                                    decidem)
 *     config_php_copia               o DOCUMENTOS_COPIA_BANCO que o config.php ainda define (verdadeiro ou falso), que não vale mais (a
 *                                    Configuração mostra o aviso); null: não está lá
 *     config.medicao_janela_dias     o gasto medido pelas leituras usa as medições destes últimos dias, com "contar por" dias (sem nenhuma
 *                                    na janela, as de antes)
 *     config.medicao_janela_leituras o gasto medido usa as últimas tantas medições de cada tipo (em uso e em repouso), com "contar por"
 *                                    leituras (2 a 1000)
 *     config.encostado_dias          o aviso Encostado: depois de quantos dias sem ir ao pulso um relógio da coleção é avisado (1 a 3650;
 *                                    padrão 30)
 *     config.encostado_ativo         1 ou 0: o aviso Encostado está ligado (a caixa Avisar quando um relógio fica encostado, da
 *                                    Configuração: liga e desliga o aviso encostado do cadastro, o mesmo que a página Avisos; desligado,
 *                                    somem o aviso, a coluna Encostado da Hoje e o filtro)
 *     config.idioma                  o idioma padrão da instalação (pt-BR, en, zh-CN, hi, es, fr): o de quem não escolheu outro no menu, o
 *                                    das mensagens do cron e o da API sem lingua= (vazio: o do navegador, senão português)
 *     config.fuso                    o fuso horário da coleção ("Europe/Lisbon"): o de quem não escolheu o seu em Minha conta, e o do cron,
 *                                    das mensagens e das agendas dela; vazio: o do sistema (fuso_sistema)
 *     config.hora_relogio            o relógio da página Hora certa: analogico, digital, anadigi (o visor digital dentro do mostrador
 *                                    analógico) ou ambos (um ao lado do outro); vazio: ambos
 *     config.semana_inicio           o primeiro dia da semana do rodízio: segunda ou domingo (o plano vem montado até o último dia da
 *                                    semana; o ciclo, a próxima semana e o relógio para a semana inteira contam dela); vazio: segunda
 *     config.trocar_hoje             1: o relógio de hoje se troca no próprio dia; 0 ou vazio (o padrão): só a partir de amanhã (o que
 *                                    trocaria o de hoje é recusado, e o Sortear de novo vale de amanhã)
 *     fuso_sistema                   o fuso do sistema (o FUSO do config.php, ou o do PHP): o das datas gravadas no banco e o de quem não
 *                                    escolheu outro
 *     fusos[]                        os fusos horários que o PHP conhece, em ordem alfabética (as listas Fuso horário da Configuração e de
 *                                    Minha conta)
 *     fusos[].nome                   o nome ("America/Sao_Paulo")
 *     fusos[].deslocamento           a diferença para o UTC agora ("-03:00")
 *     linguas[]                      os idiomas do sistema, na ordem (o menu Idioma padrão)
 *     linguas[].codigo               o código (pt-BR, en, zh-CN, hi, es, fr)
 *     linguas[].nome                 o nome no próprio idioma
 *     config.painel_modo             como o relógio abre na página Hoje: lado (no painel à direita da lista) ou flutuante (numa janela
 *                                    grande por cima da página); vazio: lado
 *     config.destaque_hoje           o destaque no alto da página Hoje: dia (o relógio do dia, o do plano) ou pulso (o relógio no pulso
 *                                    agora; sem nenhum no pulso, o do dia); vazio: dia
 *     config.calibre_campos          os campos do formulário do calibre (Cadastros › Calibres): movimento (só os campos marcados como do
 *                                    movimento, e os que o calibre já define) ou todos; vazio: movimento
 *     config.relojinho_modo          o reloginho da barra e da aba: hora (cada hora do dia tem o seu modelo), aleatorio (um sorteado a cada
 *                                    hora, podendo repetir) ou sem_repeticao (os 24 embaralhados, cada um uma vez por dia); vazio: hora
 *     config.proximos_por_pagina     o "Mostrar até" dos Próximos dias da página Hoje: quantos dias por página (1 a 366; vazio: 7)
 *     config.plano_por_pagina        o "Mostrar até" do dia a dia da página Plano: quantos dias por página (1 a 366; vazio: 31)
 *     config.atualizar_segundos      de quantos em quantos segundos as páginas (Hoje com o painel, Ficha, Plano, Totais e Histórico) leem
 *                                    tudo de novo sozinhas: 1 a 86400; 0, não leem; vazio: 60
 *     config.pulso_auto_fim          1 (ou vazio): o relógio sai do pulso sozinho no fim do horário de uso (o "fecha às" do tipo No pulso);
 *                                    0: só pelo Tirou
 *     config.pulso_auto_inicio       1 (ou vazio): o relógio do dia entra no pulso sozinho no início do horário de uso; 0: só pelo Pôs
 *     config.sol_auto_fim            1 (ou vazio): o solar sai do sol sozinho na hora de sol_fim (o "fecha às" do tipo No sol); 0: só pelo
 *                                    Tirar do sol
 *     config.sol_fim                 a hora em que a sessão no sol esquecida aberta fecha (HH:MM), guardada mesmo com o sol_auto_fim
 *                                    desligado
 *     config.previsao_limite         o limite de carga da previsão do smartwatch, em %
 *     config.sol_limiar              no solar, a carga (%) em que ele deve ir para o sol
 *     config.ultima_manha            o dia da última rodada da manhã do cron (texto AAAA-MM-DD)
 *     config.ultima_noite            o dia da última rodada da noite do cron (texto AAAA-MM-DD)
 *     config.uso_fim                 a hora em que ele sai do pulso (HH:MM)
 *     config.uso_inicio              a hora em que o relógio do dia vai para o pulso (HH:MM)
 *     eventos[]                      os eventos personalizados (avisos seus, com horário e repetição próprios)
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
 *     relogios[]                     os relógios (a lista do evento)
 *     relogios[].disponivel          verdadeiro ou falso
 *     relogios[].id                  o número
 *     relogios[].nome                o nome
 *     repeticoes.<repeticao>         cada repetição de evento e o nome dela
 *     sol_fim                        a hora em que a sessão no sol esquecida aberta fecha (HH:MM)
 *     sol_auto_fim                   true (o padrão): o solar sai do sol sozinho na hora de sol_fim; false: só pelo Tirar do sol
 *     trocar_hoje                    verdadeiro: o relógio de hoje se troca no próprio dia (a Configuração, trocar_hoje); falso, o padrão:
 *                                    só a partir de amanhã, e as telas não mostram as trocas de hoje (o trocar por… do dia de hoje, o
 *                                    Usando hoje, o inclusive hoje, o Sem relógio hoje, o Excluindo hoje do Apagar tudo; no Rodízio manual,
 *                                    hoje fica parado)
 *     sistema                        o que é do sistema inteiro (não da coleção)
 *     sistema.url_sistema            o endereço do sistema, para os links das mensagens ({link}), do ICS e do Outlook (vazio: o do pedido)
 *     sistema.pode_mudar             verdadeiro ou falso: quem pede pode mudar (só o master)
 *     senha_politica                 a força da senha (a Configuração, do sistema): o que toda senha nova precisa ter
 *     senha_politica.regras          o mínimo de cada regra (0: não exige)
 *     senha_politica.regras.tamanho  quantos caracteres, no mínimo
 *     senha_politica.regras.maiusculas
 *                                    quantas letras maiúsculas, no mínimo
 *     senha_politica.regras.minusculas
 *                                    quantas letras minúsculas, no mínimo
 *     senha_politica.regras.numeros  quantos números, no mínimo
 *     senha_politica.regras.simbolos quantos símbolos (o que não é letra, número nem espaço), no mínimo
 *     senha_politica.texto           a regra por extenso, para a tela ("A senha precisa ter: 8 caracteres ou mais, 1 letra maiúscula...")
 *     destinos[]                     o resumo dos destinos da coleção (a página Destinos tem o resto)
 *     destinos[].id                  o id
 *     destinos[].nome                o nome
 *     destinos[].tipo                webhook, email ou webpush
 *     destinos[].tipo_nome           o nome do tipo, por extenso
 *     destinos[].ativo               verdadeiro ou falso: manda
 *     agendas[]                      o resumo das agendas da coleção (a página Agendas tem o resto)
 *     agendas[].id                   o id
 *     agendas[].nome                 o nome
 *     agendas[].tipo                 google, caldav, outlook ou ics
 *     agendas[].tipo_nome            o nome do tipo, por extenso
 *     agendas[].ativa                verdadeiro ou falso: o sistema mantém os eventos nela
 *     cron_estado.registro           o registro da última rodada com atividade (o master vê o do sistema inteiro; os outros, o da coleção);
 *                                    null: nenhum
 *     eventos[].destinos[]           os nomes dos destinos que recebem o evento
 *     eventos[].agendas[]            os nomes das agendas que recebem o evento
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
 *     acessorio_tipos                os tipos de acessório, na ordem (o menu Tipo de acessório de um campo para acessório)
 *     acessorio_tipos[]              um tipo
 *     acessorio_tipos[].valor        o tipo: o identificador de um item da lista Tipos de acessório (Cadastros › Listas: pulseira, caixa,
 *                                    estojo, ferramenta, carregador, peca, outro...)
 *     acessorio_tipos[].nome         o nome do tipo, no idioma
 *     acessorio_tipos[].id           o número do item (o id da gravação, recurso=listas)
 *     acessorio_tipos[].ordem        a ordem na lista
 *     acessorio_tipos[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     acessorio_tipos[].texto        o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     acessorio_tipos[].cor          a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     unidades                       as unidades da árvore, para o menu Unidade dos campos, dos tipos de lançamento e das fórmulas (a aba
 *                                    Unidades usa o recurso=unidades)
 *     unidades[]                     uma unidade
 *     unidades[].simbolo             o símbolo (o que vai depois do número: mm, h, R$); o que o campo, o tipo de lançamento e a fórmula
 *                                    guardam
 *     unidades[].nome                o nome por extenso, no singular (milímetro)
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
 *     campos[].opcoes                os valores possíveis, um por linha (a lista tem sempre; o texto e o número, quando o campo tem: o
 *                                    valor tem de ser um deles; vazio: qualquer um)
 *     campos[].ordem                 a posição do campo no cadastro
 *     campos[].secao_id              a divisória da ficha em que o campo fica (secoes[].id); null: nenhuma, no fim da ficha
 *     campos[].minimo                o menor valor que o número aceita; null: sem limite
 *     campos[].maximo                o maior valor que o número aceita; null: sem limite
 *     campos[].multiplo              1: o campo é múltiplo (vários dos valores possíveis de uma vez); 0: um valor só
 *     campos[].alvo                  de quem é o campo: relogio (o cadastro do relógio), acessorio (o cadastro do acessório, na página
 *                                    Acessórios), winder (o do winder) ou posicao (o da posição do winder, na página Winders)
 *     campos[].acessorio_tipo        no campo de acessório, o tipo de acessório em que ele vale (pulseira, caixa...); null: todos (e nos
 *                                    dos relógios)
 *     campos[].serve_campo_id        no campo de acessório, o campo do relógio (campos[].id) com que ele tem de bater para o acessório
 *                                    servir no relógio (a largura da pulseira com o entre alças); null: nenhum
 *     campos[].regra                 o mínimo e o máximo do número em texto, para mostrar embaixo da entrada ("de 20 a 60 mm"); null: sem
 *                                    limite
 *     secoes                         as divisórias da ficha (Cadastros › Divisórias), na ordem
 *     secoes[]                       uma divisória
 *     secoes[].id                    o número da divisória (o secao_id dos campos)
 *     secoes[].nome                  o nome (Identificação, Compra, Construção...)
 *     secoes[].ordem                 a posição da divisória na ficha
 *     secoes[].campos                quantos campos estão nela
 *     campos[].padrao                o valor usado quando o relógio não tem o campo preenchido (texto; null: nenhum)
 *     campos[].so_desejado           1: o campo vale só nos relógios desejados (a lista de desejos); 0: em todos (do grupo)
 *     campos[].calibre               1: o campo é do movimento (aparece no formulário do calibre, com a Configuração em só os do
 *                                    movimento); 0: não
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
 *     lancamento_tipos[].marcha      o que o tipo mede da marcha: desvio (a leitura do desvio da hora, em segundos), acerto (o acerto da
 *                                    hora) ou null
 *     lancamento_tipos[].winder      a sessão é numa posição de um winder (o Pôr pede a posição; o programa dela entra nas fórmulas por
 *                                    WINDER_POSICAO): 1 ou 0 (verdadeiro ou falso na exportação)
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
 *     modos[].roteiro                o roteiro escrito à mão (o personalizado); null nos outros tipos
 *     modos[].roteiro_efetivo        o roteiro que vale: o escrito, ou o gerado dos blocos e da forma de escolha
 *     modos[].selecao                a forma de escolha
 *     modos[].semanas                quantas semanas à frente o plano vem montado (1 a 8)
 *     modos[].tipo                   o tipo: semana, semana_fds, dia_semana, aleatorio, escala ou personalizado
 *     modos[].tipo_texto             o tipo por extenso, no idioma do pedido
 *     tipos_de_modo[]                os tipos de modo, na ordem da lista da tela
 *     tipos_de_modo[].chave          a chave do tipo (o que se grava em modos salvar)
 *     tipos_de_modo[].nome           o nome do tipo, no idioma do pedido
 *     roteiro_padrao                 a expressão padrão de cada saída do roteiro, usada quando o roteiro não a define
 *     roteiro_padrao.candidato       quem entra na escolha do dia: 1 (todos)
 *     roteiro_padrao.nota            a nota: nota_criterios (a nota pelos critérios)
 *     roteiro_padrao.peso            a chance no sorteio: 0 (a maior nota ganha)
 *     roteiro_padrao.dias            quantos dias seguidos o escolhido fica: 1
 *     roteiro_padrao.garantia        quem passa na frente: quem está além da garantia de rodízio (max_sem_uso) em dias sem uso
 *     hoje                           o dia de hoje da coleção (AAAA-MM-DD), para a lista das semanas
 *     semana_inicio                  o dia em que a semana do rodízio começa (a Configuração, semana_inicio): segunda ou domingo; a lista
 *                                    das semanas e a ordem dos dias seguem ele
 *     relogios[]                     os relógios
 *     relogios[].id                  o número
 *     relogios[].nome                o nome
 *     tipos_de_campo.<tipo_de_campo> cada tipo de campo e o nome
 *     marchas_de_lancamento          o que um tipo de lançamento pode medir da marcha (a lista Mede a marcha do cadastro)
 *     marchas_de_lancamento.desvio   a leitura do desvio da hora (só com valor, em segundos)
 *     marchas_de_lancamento.acerto   o acerto da hora (só instantâneo)
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
 *     modos[].colecao_id             a coleção do modo
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
 *     linhas[].estado                o estado do trecho: rodizio (no pulso pelo rodízio), pulso (em uso fora do plano: no pulso sem ser o
 *                                    relógio do dia), o identificador de um tipo de sessão (winder, sol...), repouso (parado) ou marca (uma
 *                                    marcação)
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
 *     previsoes[].conta[]            de onde vem cada número da conta: primeiro o gasto que vale no pulso e fora dele, depois o gasto
 *                                    medido pelas leituras e os campos do cadastro (os de um gasto em que vale o medido, com usado falso)
 *     previsoes[].conta[].usado      verdadeiro: o número entra na conta; falso: um campo do cadastro que ficou fora, porque vale o gasto
 *                                    medido
 *     previsoes[].conta[].vale       verdadeiro nas duas primeiras linhas: o gasto que vale no pulso (taxa_uso) e fora dele (taxa_repouso)
 *     previsoes[].conta[].campo      o identificador do campo, ou MEDIDO("uso") / MEDIDO("repouso") para o gasto medido
 *     previsoes[].conta[].detalhe    no gasto medido, como ele foi medido ("conta dos dois gastos juntos, com 3 medições dos últimos 90
 *                                    dias"); null nos campos
 *     previsoes[].conta[].nome       o nome do campo, ou do gasto medido (Gasto em uso medido, Gasto fora do pulso medido)
 *     previsoes[].conta[].origem     de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo), vazio (sem valor), medido (pelas leituras) ou cadastro (o gasto que vale saiu do cadastro)
 *     previsoes[].conta[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
 *     previsoes[].conta[].unidade    a unidade do valor (texto)
 *     previsoes[].conta[].valor      o valor usado na conta (número; null se vazio)
 *     previsoes[].dura_ate           até que dia a carga aguenta se usar a partir de hoje (texto AAAA-MM-DD)
 *     previsoes[].dura_dias          quantos dias de uso a carga de agora aguenta (número, dias; a fórmula dias_de_carga)
 *     previsoes[].energia            a energia agora, em % (número; null sem leitura)
 *     previsoes[].gasto              os dois gastos da bateria lado a lado (as fórmulas taxa_uso e taxa_repouso; null se o relógio não as
 *                                    tem)
 *     previsoes[].gasto.conjunta     true: os dois gastos saem juntos da conta das medições; false: a média das medições de cada um
 *     previsoes[].gasto.motor        o motor que calculou o gasto: horas, robusto, marcacao, cadastro ou formulas (veja config.gasto_motor)
 *     previsoes[].gasto.motor_nome   o nome do motor por extenso ("Pela marcação: duas taxas separadas")
 *     previsoes[].gasto.motor_de     de onde veio a escolha: colecao (a Configuração), relogio (o campo gasto_motor do relógio), grupo (o
 *                                    padrão do campo) ou padrao (nada escolhido: horas)
 *     previsoes[].gasto.base         o que a conta do gasto medido conta (o "contar por" da Configuração): dias ou leituras
 *     previsoes[].gasto.janela_leituras
 *                                    as últimas tantas medições de cada tipo que entram, com base leituras (número)
 *     previsoes[].gasto.janela_texto a janela por extenso: "dos últimos 90 dias" ou "das últimas 20 leituras de cada tipo"
 *     previsoes[].gasto.janela_dias  a janela do gasto medido (medicao_janela_dias da Configuração), em dias (número)
 *     previsoes[].gasto.fora         quantas medições da janela têm uma leitura que não bate com as outras e pesa menos da metade na conta
 *                                    (número)
 *     previsoes[].gasto.medicoes     quantas medições da janela entram na conta (número)
 *     previsoes[].gasto.medicoes_antes
 *                                    quantas medições há na janela anterior, a dos antes (número)
 *     previsoes[].gasto.uso.antes    o gasto em uso, em % por dia de uso, medido na janela anterior (número; null sem medição)
 *     previsoes[].gasto.uso.cadastro o gasto em uso, em % por dia de uso, pelo cadastro, sem as medições (número)
 *     previsoes[].gasto.uso.medido_dp
 *                                    a incerteza do gasto em uso medido: um desvio padrão, na mesma unidade (número; null sem medição)
 *     previsoes[].gasto.uso.medicoes quantas medições em uso entram na conta (número; no motor da marcação, as de leituras marcadas em uso
 *                                    nas duas pontas)
 *     previsoes[].gasto.uso.so_leituras
 *                                    o gasto em uso, em % por dia de uso, só pelas leituras: a mesma conta sem o cadastro como ponto de
 *                                    partida (número; null sem medição ou no motor só o cadastro)
 *     previsoes[].gasto.uso.puxado   verdadeiro: o medido ficou diferente do que só as leituras dão, porque com poucas medições o cadastro
 *                                    ainda puxa a conta
 *     previsoes[].gasto.uso.medido   o gasto em uso, em % por dia de uso, medido pelas leituras, como o MEDIDO (número; null se as leituras
 *                                    não medem esse gasto: sem medição ou, no motor da marcação, nenhuma desse tipo)
 *     previsoes[].gasto.uso.vale     o gasto em uso, em % por dia de uso que vale na conta: o medido, senão o cadastro (número)
 *     previsoes[].gasto.uso.campos[] os campos do cadastro de onde sai o gasto em uso do cadastro (o decaimento, a autonomia...): o painel
 *                                    mostra o medido ao lado deles
 *     previsoes[].gasto.uso.campos[].campo
 *                                    o identificador do campo
 *     previsoes[].gasto.uso.campos[].nome
 *                                    o nome do campo
 *     previsoes[].gasto.uso.campos[].origem
 *                                    de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo) ou vazio (sem valor)
 *     previsoes[].gasto.uso.campos[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
 *     previsoes[].gasto.uso.campos[].unidade
 *                                    a unidade do campo (texto)
 *     previsoes[].gasto.uso.campos[].valor
 *                                    o valor usado na conta (número; null se vazio)
 *     previsoes[].gasto.uso.formula  o identificador da fórmula do gasto em uso (taxa_uso)
 *     previsoes[].gasto.repouso.antes
 *                                    o gasto fora do pulso, em % por dia, medido na janela anterior (número; null sem medição)
 *     previsoes[].gasto.repouso.cadastro
 *                                    o gasto fora do pulso, em % por dia, pelo cadastro, sem as medições (número)
 *     previsoes[].gasto.repouso.medido_dp
 *                                    a incerteza do gasto fora do pulso medido: um desvio padrão, na mesma unidade (número; null sem
 *                                    medição)
 *     previsoes[].gasto.repouso.medicoes
 *                                    quantas medições em repouso entram na conta (número; no motor da marcação, as de leituras marcadas em
 *                                    repouso nas duas pontas)
 *     previsoes[].gasto.repouso.so_leituras
 *                                    o gasto fora do pulso, em % por dia, só pelas leituras: a mesma conta sem o cadastro como ponto de
 *                                    partida (número; null sem medição ou no motor só o cadastro)
 *     previsoes[].gasto.repouso.puxado
 *                                    verdadeiro: o medido ficou diferente do que só as leituras dão, porque com poucas medições o cadastro
 *                                    ainda puxa a conta
 *     previsoes[].gasto.repouso.medido
 *                                    o gasto fora do pulso, em % por dia, medido pelas leituras, como o MEDIDO (número; null se as leituras
 *                                    não medem esse gasto: sem medição ou, no motor da marcação, nenhuma desse tipo)
 *     previsoes[].gasto.repouso.vale o gasto fora do pulso, em % por dia que vale na conta: o medido, senão o cadastro (número)
 *     previsoes[].gasto.repouso.campos[]
 *                                    os campos do cadastro de onde sai o gasto fora do pulso do cadastro (o decaimento, a autonomia...): o
 *                                    painel mostra o medido ao lado deles
 *     previsoes[].gasto.repouso.campos[].campo
 *                                    o identificador do campo
 *     previsoes[].gasto.repouso.campos[].nome
 *                                    o nome do campo
 *     previsoes[].gasto.repouso.campos[].origem
 *                                    de onde veio: informado (no cadastro do relógio), calibre (o valor do calibre dele), padrao (o padrão
 *                                    do campo) ou vazio (sem valor)
 *     previsoes[].gasto.repouso.campos[].origem_texto
 *                                    de onde veio, no idioma, para mostrar ("informado", "padrão", "vazio", "medido", "cadastro")
 *     previsoes[].gasto.repouso.campos[].unidade
 *                                    a unidade do campo (texto)
 *     previsoes[].gasto.repouso.campos[].valor
 *                                    o valor usado na conta (número; null se vazio)
 *     previsoes[].gasto.repouso.formula
 *                                    o identificador da fórmula do gasto fora do pulso (taxa_repouso)
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
 *     trocar_hoje                    verdadeiro: o relógio de hoje se troca no próprio dia (a Configuração, trocar_hoje); falso, o padrão:
 *                                    só a partir de amanhã, e as telas não mostram as trocas de hoje (o trocar por… do dia de hoje, o
 *                                    Usando hoje, o inclusive hoje, o Sem relógio hoje, o Excluindo hoje do Apagar tudo; no Rodízio manual,
 *                                    hoje fica parado)
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
 *     fora[].posse_comportamento     o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
 *     posse_colecao                  a situação (o identificador) que o Comprei e o Voltar para a coleção gravam: a primeira que faz
 *                                    colecao
 *     desejados[]                    os relógios desejados (a lista de desejos), a prioridade mais alta primeiro, depois pelo nome
 *     desejados[].caminho            o caminho do grupo ("Automáticos › Mergulho"; "todos os relógios" na raiz)
 *     desejados[].no_id              o grupo do relógio (o id do ponto da árvore); null na raiz
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
 *     fora[].no_id                   o grupo do relógio (o id do ponto da árvore); null na raiz
 *     fora[].criado                  quando entrou no sistema (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     fora[].foto                    a versão da foto (número inteiro: instante Unix (segundos desde 01/01/1970 UTC)); null: sem foto
 *     fora[].id                      o número (código)
 *     fora[].nome                    o nome
 *     fora[].posse                   a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
 *     fora[].posse_texto             a mesma por extenso
 *     fora[].tipo                    o grupo do relógio (o nome; "sem grupo" na raiz)
 *     grupos[]                       os grupos em ordem de árvore
 *     grupos[].caminho               o caminho do grupo
 *     grupos[].id                    o número do grupo
 *     prioridades[]                  as prioridades, da mais alta para a mais baixa (as opções do campo prioridade_desejo)
 *     sem_preco_alvo                 quantos desejados estão sem preço-alvo
 *     total_preco_alvo               a soma dos preços-alvo dos desejados, em R$ (os sem preço contam 0)
 *   recurso=totais
 *     relogios                       em quantos relógios os totais foram calculados
 *     relogio_ids[]                  os ids desses relógios
 *     todos                          quantos relógios a tabela tem ao todo (sem o filtro)
 *     totais[]                       os totais, na ordem do cadastro
 *     totais[].id                    o número do total
 *     totais[].coluna                a chave da coluna da tabela dos relógios onde ele vai no rodapé (codigo, nome, tipo, estado, carga,
 *                                    ultimo, manut, em, fazer, encostado, compra, valor); null: nenhuma
 *     totais[].coluna_nome           o nome da coluna, no idioma do pedido; null: nenhuma
 *     totais[].nome                  o nome do total (o título da tabelinha e do quadro)
 *     totais[].expressao             a expressão de total
 *     totais[].rodape                verdadeiro: aparece no rodapé da tabela, embaixo da coluna
 *     totais[].pagina                verdadeiro: aparece na página de totais
 *     totais[].largura               o quadro na página de totais: estreito ou largo (a linha inteira)
 *     totais[].formato               como os números saem: numero, inteiro, decimal, moeda, porcentagem, data ou dias
 *     totais[].ordem                 a posição na ordem (de 10 em 10)
 *     totais[].resultado             o total calculado nos relógios
 *     totais[].resultado.tipo        valor (um número ou texto), tabela (AGRUPADO e FAIXAS: as linhas e o total), relogio (QUEM_MAIOR e
 *                                    QUEM_MENOR) ou erro
 *     totais[].resultado.valor       o número (ou o texto); no relogio, o valor dele; null: vazio
 *     totais[].resultado.texto       o valor no formato do total, pronto para a tela ("R$ 17.997,74", "84%", "27/10/2026"; vazio: "—"); no
 *                                    relogio, o nome e o valor
 *     totais[].resultado.valor_texto no relogio: só o valor, no formato
 *     totais[].resultado.relogio_id  no relogio: o id do relógio do maior (ou do menor) valor; null: nenhum tem valor
 *     totais[].resultado.relogio     no relogio: o nome dele
 *     totais[].resultado.linhas[]    na tabela: as linhas (no AGRUPADO, do que mais tem para o que menos tem, ou na ordem dos números e dos
 *                                    meses; "outros" junta o resto, quando há limite; "sem valor", os sem a chave; nas FAIXAS, na ordem das
 *                                    faixas)
 *     totais[].resultado.linhas[].rotulo
 *                                    o rótulo da linha (o valor da chave, a faixa, "outros" ou "sem valor"; os meses como "ago/2026")
 *     totais[].resultado.linhas[].valor
 *                                    a conta da linha (sem conta, quantos relógios)
 *     totais[].resultado.linhas[].texto
 *                                    a conta no formato (nas faixas e no AGRUPADO sem conta, inteiro)
 *     totais[].resultado.linhas[].relogios
 *                                    quantos relógios estão na linha
 *     totais[].resultado.linhas[].sem
 *                                    verdadeiro na linha "sem valor"
 *     totais[].resultado.total       na tabela: a conta com todos os relógios (no AGRUPADO, a mesma conta das linhas; nas FAIXAS, quantos
 *                                    são)
 *     totais[].resultado.total_texto o total no formato
 *     totais[].resultado.erro        no erro: por que não deu (a expressão com erro, os limites das FAIXAS fora de ordem)
 *     teste                          o resultado da expressao testada (como totais[].resultado); só com expressao
 *     colunas[]                      as colunas da tabela dos relógios onde um total pode ir (sem parte=rodape)
 *     colunas[].chave                a chave (o que se grava em coluna)
 *     colunas[].nome                 o nome, no idioma do pedido
 *     formatos[]                     os formatos dos números
 *     formatos[].chave               a chave (o que se grava em formato)
 *     formatos[].nome                o nome, no idioma do pedido
 *     funcoes[]                      as funções de total (só valem nas expressões de total)
 *     funcoes[].nome                 o nome da função
 *     funcoes[].descricao            o que ela faz
 *     variaveis[]                    as variáveis da tabela, que os totais têm em cada relógio além dos campos e das fórmulas
 *     variaveis[].nome               o nome da variável
 *     variaveis[].descricao          o que ela vale
 *   recurso=precisao
 *     relogio_id                     com relogio: o id dele
 *     relogio                        com relogio: o nome dele
 *     precisao                       a precisão do relógio (o quadro Precisão do painel): as leituras do desvio da hora e os acertos, a
 *                                    marcha (em segundos por dia: + adianta, − atrasa) e o desvio estimado agora; null se nenhum tipo de
 *                                    lançamento dele mede a marcha
 *     precisao.tipo_desvio           o identificador do tipo de lançamento da leitura do desvio (o lancar com marca, instante_ms e posicao)
 *     precisao.tipo_acerto           o identificador do tipo do acerto da hora; null se o relógio não tem
 *     precisao.resumo                a frase pronta ("Adianta 7,6 s por dia (média de 3 medições em 9 dias; elas variam ±2,8 s/dia).", ou o
 *                                    que falta para medir)
 *     precisao.medicoes              quantos intervalos medidos (de uma leitura, ou de um acerto, até a leitura seguinte)
 *     precisao.dias                  o tempo somado dos intervalos, em dias
 *     precisao.marcha                a marcha do relógio, em s/dia: a soma das diferenças sobre a soma dos dias (um intervalo de 10 dias
 *                                    pesa dez vezes o de 1 dia); null sem medição
 *     precisao.marcha_texto          a marcha com o sinal e a unidade ("+7,6 s/dia")
 *     precisao.marcha_dp             quanto as marchas dos intervalos variam entre si (o desvio padrão, pesado pelo tempo, em s/dia); null
 *                                    com menos de dois
 *     precisao.marcha_dp_texto       a variação por extenso ("±2,8 s/dia")
 *     precisao.ultima                a marcha do intervalo mais recente, em s/dia; null sem medição
 *     precisao.ultima_texto          a última com o sinal e a unidade
 *     precisao.desvio_agora          o desvio estimado agora, em segundos (+ adiantado): o último ponto (0 depois de um acerto) mais a
 *                                    marcha vezes o tempo desde ele; null sem leitura
 *     precisao.desvio_agora_texto    por extenso ("8 s adiantado", "na hora")
 *     precisao.esperada              a marcha esperada (os campos marcha_min e marcha_max, do relógio ou do grupo)
 *     precisao.esperada.de           o mínimo, em s/dia; null sem
 *     precisao.esperada.ate          o máximo, em s/dia; null sem
 *     precisao.esperada.texto        por extenso ("de −10 s/dia a +30 s/dia"); null sem nenhum dos dois
 *     precisao.dentro                verdadeiro se a última marcha está dentro da esperada; null sem a esperada ou sem medição
 *     precisao.por_posicao           a marcha em cada posição em que houve medição, na ordem das posições
 *     precisao.por_posicao[]         uma posição
 *     precisao.por_posicao[].posicao a posição (o código)
 *     precisao.por_posicao[].posicao_texto
 *                                    a posição por extenso
 *     precisao.por_posicao[].medicoes
 *                                    quantos intervalos nela
 *     precisao.por_posicao[].dias    o tempo deles, em dias
 *     precisao.por_posicao[].marcha  a marcha nela, em s/dia
 *     precisao.por_posicao[].marcha_texto
 *                                    com o sinal e a unidade
 *     precisao.pontos                as leituras do desvio e os acertos da hora, do mais velho ao mais novo (o gráfico)
 *     precisao.pontos[]              um ponto
 *     precisao.pontos[].id           o id do lançamento
 *     precisao.pontos[].inicio       quando (AAAA-MM-DD HH:MM:SS)
 *     precisao.pontos[].tipo         desvio (a leitura) ou acerto (o acerto da hora)
 *     precisao.pontos[].desvio       o desvio em segundos (+ adiantado); 0 no acerto
 *     precisao.pontos[].posicao      onde ele ficou desde o ponto anterior (o código); null: não informada
 *     precisao.pontos[].posicao_texto
 *                                    a posição por extenso; null: não informada
 *     precisao.intervalos            os intervalos medidos, do mais novo ao mais velho
 *     precisao.intervalos[]          um intervalo: de um ponto até a leitura seguinte
 *     precisao.intervalos[].id       o id do lançamento da leitura do fim
 *     precisao.intervalos[].de       o começo (AAAA-MM-DD HH:MM:SS)
 *     precisao.intervalos[].ate      o fim
 *     precisao.intervalos[].dias     quanto tempo, em dias
 *     precisao.intervalos[].desvio_de
 *                                    o desvio no começo, em segundos (0 depois de um acerto)
 *     precisao.intervalos[].desvio_ate
 *                                    o desvio no fim
 *     precisao.intervalos[].marcha   a marcha no intervalo, em s/dia
 *     precisao.intervalos[].marcha_texto
 *                                    com o sinal e a unidade
 *     precisao.intervalos[].posicao  onde ele ficou (a posição da leitura do fim); null: não informada
 *     precisao.intervalos[].posicao_texto
 *                                    a posição por extenso ("não informada" sem ela)
 *     precisao.intervalos[].depois_do_acerto
 *                                    verdadeiro se o intervalo começa num acerto da hora
 *     precisao.posicoes              as posições, na ordem, para a lista da tela
 *     precisao.posicoes[]            uma posição
 *     precisao.posicoes[].valor      o código (o posicao do lancar)
 *     precisao.posicoes[].texto      por extenso
 *     relogios                       sem relogio: cada relógio da coleção que tem o tipo do desvio da hora, por nome
 *     relogios[]                     um relógio, resumido
 *     relogios[].relogio_id          o id
 *     relogios[].relogio             o nome
 *     relogios[].medicoes            quantos intervalos medidos
 *     relogios[].marcha              a marcha, em s/dia (+ adianta); null sem medição
 *     relogios[].marcha_texto        com o sinal e a unidade
 *     relogios[].ultima              a marcha do intervalo mais recente, em s/dia
 *     relogios[].ultima_texto        com o sinal e a unidade
 *     relogios[].desvio_agora        o desvio estimado agora, em segundos (+ adiantado)
 *     relogios[].desvio_agora_texto  por extenso
 *     relogios[].esperada            a marcha esperada por extenso; null sem
 *     relogios[].dentro              verdadeiro se a última está dentro da esperada; null sem a esperada ou sem medição
 *     relogios[].resumo              a frase pronta
 *   recurso=unidades
 *     unidades                       as unidades da árvore, na ordem das grandezas: cada base seguida das que se convertem nela, da menor
 *                                    para a maior
 *     unidades[]                     uma unidade
 *     unidades[].id                  o número da unidade
 *     unidades[].simbolo             o símbolo (o que vai depois do número: mm, h, R$); o que o campo, o tipo de lançamento e a fórmula
 *                                    guardam
 *     unidades[].nome                o nome por extenso, no singular (milímetro)
 *     unidades[].base                o símbolo da unidade base, a da mesma grandeza em que esta se converte; null: esta é uma base
 *     unidades[].fator               com base: quanto vale 1 desta na base (a polegada: 25,4); null na base
 *     unidades[].zero                o valor desta que é o zero da base (os 32 do °F); 0 nas outras: na base = (valor - zero) × fator
 *     unidades[].mostrar_em          o símbolo da unidade em que o valor também aparece (na ficha e no passaporte); null: nenhuma
 *     unidades[].conversao           a conversão em texto, no idioma ("1 polegada = 25,4 mm"); null na base
 *     unidades[].usos                quantos usam a unidade
 *     unidades[].usos.campos         quantos campos
 *     unidades[].usos.tipos          quantos tipos de lançamento
 *     unidades[].usos.formulas       quantas fórmulas (cada versão conta)
 *     grandezas                      as grandezas: cada base com as unidades que se convertem nela (o que o "mostrar também em" de cada uma
 *                                    pode escolher)
 *     grandezas[]                    uma grandeza
 *     grandezas[].base               o símbolo da base
 *     grandezas[].nome               o nome da base
 *     grandezas[].unidades           os símbolos das unidades dela, a base primeiro
 *     grandezas[].unidades[]         um símbolo
 *   recurso=calibres
 *     calibres                       os calibres da árvore, pelo nome
 *     calibres[]                     um calibre (o movimento)
 *     calibres[].id                  o número do calibre
 *     calibres[].nome                o nome (NH35, ST2130...)
 *     calibres[].fabricante          o fabricante; null: não informado
 *     calibres[].link                o link do manual técnico; null: sem
 *     calibres[].observacao          as recomendações (texto); null: sem
 *     calibres[].valores             os valores dos campos que ele define, pelo identificador do campo
 *     calibres[].valores.<identificador>
 *                                    o valor do campo no calibre (texto normalizado: número com ponto, data AAAA-MM-DD, sim/não 1 ou 0)
 *     calibres[].relogios            os relógios da coleção que apontam para ele, por nome
 *     calibres[].relogios[]          um relógio
 *     calibres[].relogios[].id       o número do relógio
 *     calibres[].relogios[].nome     o nome do relógio
 *     calibres[].documentos          quantos documentos o calibre tem (a página Documentos do calibre)
 *     calibres[].campos_no_formulario
 *                                    os campos que o formulário deste calibre mostra, pelo identificador, na ordem do cadastro: os da
 *                                    Configuração (calibre_campos) e, com só os do movimento, também os que ele já define
 *     calibres[].campos_no_formulario[]
 *                                    o identificador de um campo
 *     campos                         os campos do cadastro que um calibre pode definir (os da lista de desejos ficam de fora), na ordem do
 *                                    cadastro
 *     campos[]                       um campo
 *     campos[].id                    o número do campo
 *     campos[].identificador         o identificador (a chave em valores)
 *     campos[].nome                  o nome
 *     campos[].tipo                  inteiro, decimal, sim_nao, data, lista ou texto
 *     campos[].unidade               a unidade (vazio: sem)
 *     campos[].padrao                o padrão do campo; null: sem
 *     campos[].opcoes                os valores possíveis, um por linha (a lista tem sempre; o texto e o número, quando o campo tem); null:
 *                                    qualquer um
 *     campos[].multiplo              verdadeiro: o campo é múltiplo (vários dos valores possíveis de uma vez; no formulário, uma caixa para
 *                                    cada)
 *     campos[].regra                 o mínimo e o máximo do número em texto, para mostrar embaixo da entrada ("de 20 a 60 mm"); null: sem
 *                                    limite
 *     campos[].grupo                 o grupo em que o campo vale, por extenso; null: todos os relógios
 *     campos[].movimento             verdadeiro: o campo é do movimento (a marca da aba Campos, campo.calibre)
 *     campos_mostrar                 o que a Configuração pede no formulário: movimento (só os campos do movimento) ou todos
 *     campos_no_formulario           os campos do formulário de um calibre novo, pelo identificador, na ordem do cadastro
 *     campos_no_formulario[]         o identificador de um campo
 *   recurso=estatisticas
 *     relogios[].posse_comportamento o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
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
 *     relogios[].posse               a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
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
 *     local                          a hora local, no fuso do pedido (o do usuário, o da coleção ou o do sistema)
 *     local.data                     a data (texto AAAA-MM-DD)
 *     local.hora                     a hora (texto HH:MM:SS)
 *     local.iso8601                  a data e a hora com o deslocamento ("2026-10-13T14:33:50-03:00")
 *     local.dia_semana               o dia da semana ("terça-feira")
 *     local.texto                    a data por extenso ("terça-feira, 13 de outubro de 2026")
 *     fuso                           o fuso do pedido
 *     fuso.nome                      o nome ("America/Sao_Paulo")
 *     fuso.origem                    de onde veio: usuario (Minha conta), navegador (o do aparelho, por Minha conta), colecao (a
 *                                    Configuração) ou sistema (o FUSO do config.php)
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
 *     relogio                        o relógio da página (a Configuração, Relógio da Hora certa): analogico, digital, anadigi ou ambos
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
 *     tipos                          os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     tipos[]                        os tipos de acessório, na ordem (o menu Tipo)
 *     tipos[].valor                  o valor que vai na gravação (tipo)
 *     tipos[].nome                   o nome
 *     tipos[].id                     o número do item (o id da gravação, recurso=listas)
 *     tipos[].ordem                  a ordem na lista
 *     tipos[].comportamento          o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     tipos[].texto                  o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     tipos[].cor                    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     relogios[].posse_comportamento o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
 *     acessorios[]                   os acessórios, pelo tipo e pelo nome
 *     acessorios[].id                o número do acessório
 *     acessorios[].nome              o nome ("Pulseira NATO verde")
 *     acessorios[].tipo              o tipo: o identificador de um item da lista Tipos de acessório (Cadastros › Listas: pulseira, caixa,
 *                                    estojo, ferramenta, carregador, peca, outro...)
 *     acessorios[].tipo_texto        o tipo por extenso ("Pulseira", "Peça")
 *     acessorios[].relogio_id        o relógio de que ele é; null: solto (serve a vários)
 *     acessorios[].relogio           o nome desse relógio; null: solto
 *     acessorios[].observacao        a observação (null)
 *     acessorios[].criado            quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     acessorios[].valores           os campos do acessório preenchidos (Cadastros › Campos, para acessório: os do tipo dele), na ordem
 *     acessorios[].valores[]         um campo do acessório
 *     acessorios[].valores[].identificador
 *                                    o identificador do campo
 *     acessorios[].valores[].nome    o nome do campo
 *     acessorios[].valores[].valor   o valor gravado (texto normalizado: número com ponto; o múltiplo, um por linha)
 *     acessorios[].valores[].texto   o valor para mostrar, no idioma, com a unidade ("20 mm (0,79 pol)"; o múltiplo separado por vírgula)
 *     acessorios[].serve_por         o que decide em que relógio ele serve: cada campo do relógio com que um campo dele bate, com o valor
 *                                    dele ("Entre alças (largura da pulseira): 20 mm"); null: ele não preencheu nenhum desses campos
 *     acessorios[].serve_por[]       um campo do relógio e o valor que ele tem de ter
 *     acessorios[].serve_em          os relógios em que ele serve (os da coleção, emprestados e desejados cujo valor bate com o dele em
 *                                    todos esses campos); null: não dá para saber (ele não preencheu nenhum desses campos)
 *     acessorios[].serve_em[]        um relógio em que ele serve
 *     acessorios[].serve_em[].relogio_id
 *                                    o número do relógio
 *     acessorios[].serve_em[].relogio
 *                                    o nome do relógio
 *     campos                         os campos dos acessórios (Cadastros › Campos, para acessório), na ordem: o formulário mostra os do
 *                                    tipo escolhido
 *     campos[]                       um campo de acessório
 *     campos[].identificador         o identificador (o nome em valores[<identificador>] da gravação)
 *     campos[].nome                  o nome do campo
 *     campos[].tipo                  inteiro, decimal, sim_nao, data, lista ou texto
 *     campos[].unidade               a unidade (vazio: sem)
 *     campos[].acessorio_tipo        o tipo de acessório em que ele vale; null: todos
 *     campos[].opcoes                os valores possíveis, na ordem (vazio: qualquer um)
 *     campos[].opcoes[]              um valor possível
 *     campos[].multiplo              verdadeiro: o campo é múltiplo (vários dos valores possíveis de uma vez; no formulário, uma caixa para
 *                                    cada)
 *     campos[].regra                 o mínimo e o máximo do número em texto, para mostrar embaixo da entrada ("de 20 a 60 mm"); null: sem
 *                                    limite
 *     campos[].serve                 o nome do campo do relógio com que ele tem de bater para o acessório servir no relógio (o entre
 *                                    alças); null: nenhum
 *     por_tipo[]                     quantos acessórios da lista há de cada tipo (só os tipos que têm algum)
 *     por_tipo[].tipo                o tipo
 *     por_tipo[].nome                o nome do tipo
 *     por_tipo[].quantos             quantos
 *     soltos                         quantos acessórios da lista são soltos (de nenhum relógio)
 *     relogios[]                     todos os relógios, pelo nome (o menu Relógio)
 *     relogios[].id                  o número (código)
 *     relogios[].nome                o nome
 *     relogios[].posse               a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
 *   recurso=winders
 *     winders                        os winders da coleção, pelo nome
 *     winders[]                      um winder
 *     winders[].id                   o número do winder
 *     winders[].nome                 o nome ("Escritório")
 *     winders[].observacao           a observação (null)
 *     winders[].criado               quando foi cadastrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     winders[].dados                os campos do winder com valor (Cadastros › Campos, para o winder), na ordem: o informado e, sem ele, o
 *                                    padrão do campo
 *     winders[].dados[]              um campo
 *     winders[].dados[].identificador
 *                                    o identificador do campo (o nome em valores[<identificador>] da gravação e em WINDER("identificador"))
 *     winders[].dados[].nome         o nome do campo
 *     winders[].dados[].valor        o valor (texto normalizado: número com ponto; o múltiplo, um por linha)
 *     winders[].dados[].texto        o valor para mostrar, no idioma, com a unidade
 *     winders[].dados[].origem       informado (gravado no winder) ou padrao (o padrão do campo)
 *     winders[].dados[].origem_texto a origem por extenso
 *     winders[].posicoes             as posições do winder, na ordem
 *     winders[].posicoes[]           uma posição
 *     winders[].posicoes[].id        o número da posição (o id do winder_posicoes salvar, que põe o relógio nela com relogio_id; o
 *                                    posicao_id do período)
 *     winders[].posicoes[].nome      o nome ("Pos. 1")
 *     winders[].posicoes[].ordem     a ordem dela no winder
 *     winders[].posicoes[].ligada    verdadeiro: recebe relógio; falso: desligada
 *     winders[].posicoes[].dados     os campos livres da posição com valor (Cadastros › Campos, para a posição do winder), como em dados
 *     winders[].posicoes[].dados[]   um campo
 *     winders[].posicoes[].dados[].identificador
 *                                    o identificador do campo (o nome em valores[<identificador>] da gravação e em
 *                                    WINDER_POSICAO("identificador"))
 *     winders[].posicoes[].dados[].nome
 *                                    o nome do campo
 *     winders[].posicoes[].dados[].valor
 *                                    o valor (texto normalizado: número com ponto; o múltiplo, um por linha)
 *     winders[].posicoes[].dados[].texto
 *                                    o valor para mostrar, no idioma, com a unidade
 *     winders[].posicoes[].dados[].origem
 *                                    informado (gravado na posição) ou padrao (o padrão do campo)
 *     winders[].posicoes[].dados[].origem_texto
 *                                    a origem por extenso
 *     winders[].posicoes[].programa  o programa da posição (o da página Programas); null: sem programa (a conta no winder usa a carga cheia
 *                                    por hora)
 *     winders[].posicoes[].programa.id
 *                                    o número do programa (o programa_id do winder_posicoes salvar)
 *     winders[].posicoes[].programa.nome
 *                                    o nome ("Versa 650")
 *     winders[].posicoes[].programa.texto
 *                                    o cálculo por extenso ("650 voltas por dia (325 horário + 325 anti-horário) · ciclo de 20 min, 72
 *                                    vezes por dia")
 *     winders[].posicoes[].programa.voltas_dia
 *                                    as voltas por dia, calculadas da sequência
 *     winders[].posicoes[].programa.voltas_horario
 *                                    as voltas por dia no sentido horário
 *     winders[].posicoes[].programa.voltas_anti_horario
 *                                    as voltas por dia no sentido anti-horário
 *     winders[].posicoes[].relogio   o relógio que está nela agora (a sessão no winder aberta, ligada a ela); null: livre
 *     winders[].posicoes[].relogio.relogio_id
 *                                    o número do relógio
 *     winders[].posicoes[].relogio.relogio
 *                                    o nome dele
 *     winders[].posicoes[].relogio.lancamento_id
 *                                    a sessão (o lançamento)
 *     winders[].posicoes[].relogio.tipo
 *                                    o tipo de lançamento da sessão (o encerrar dela)
 *     winders[].posicoes[].relogio.desde
 *                                    desde quando (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     winders[].posicoes[].relogio.desde_texto
 *                                    por extenso ("desde 07:40")
 *     winders[].posicoes[].relogio.carga
 *                                    a carga agora, em % (a fórmula energia); null: sem conta
 *     winders[].posicoes[].relogio.ganho
 *                                    o que cada hora ali dá, em horas de reserva por hora (a fórmula winder_ganho, com o programa da
 *                                    posição); null: sem a fórmula
 *     winders[].posicoes[].relogio.ganho_texto
 *                                    por extenso ("2,05 h de reserva por hora")
 *     winders[].posicoes[].relogio.falta_horas
 *                                    as horas até encher (a fórmula winder_falta); null: não enche (a posição não dá corda nele) ou sem a
 *                                    fórmula
 *     winders[].posicoes[].relogio.cheio_texto
 *                                    quando enche, por extenso ("cheio às 19:20", "cheio em 1d 4h", "cheio"); null: não enche
 *     winders[].posicoes[].relogio.foto
 *                                    a versão da foto do relógio (o instante em que foi trocada, em segundos;
 *                                    recurso=foto&relogio=<id>&v=<versão>), a figurinha do desenho do winder; null: sem foto
 *     winders[].posicoes[].lugar     o lugar fixo: de quem é a posição (o relógio que foi posto nela por último; pôr no winder de qualquer
 *                                    lugar o devolve para cá); null: de ninguém
 *     winders[].posicoes[].lugar.relogio_id
 *                                    o número do relógio dono do lugar
 *     winders[].posicoes[].lugar.relogio
 *                                    o nome dele
 *     winders[].posicoes[].lugar.fora
 *                                    verdadeiro: ele não está nela agora (o berço mostra a foto dele apagada, com o aro vermelho, e os
 *                                    botões Pôr de volta e Liberar o lugar)
 *     winders[].posicoes[].lugar.estado_texto
 *                                    onde ele está, fora ("fora: no pulso", "fora: guardado"); null: está nela
 *     winders[].posicoes[].lugar.foto
 *                                    a versão da foto do dono (como em relogio.foto); null: sem foto
 *     winders[].posicoes[].avisos_limite
 *                                    os avisos de limite da almofada do relógio que está nela (ou, vazia, do dono do lugar): a caixa ou o
 *                                    peso dele passa do que o winder aguenta (os campos winder_caixa_max e winder_peso_max); o dispensado
 *                                    com winders limite_ok não volta
 *     winders[].posicoes[].avisos_limite[]
 *                                    um aviso
 *     winders[].posicoes[].avisos_limite[].relogio_id
 *                                    o relógio do aviso (o relogio_id do limite_ok)
 *     winders[].posicoes[].avisos_limite[].texto
 *                                    o aviso por extenso ("O Steeldive tem 49 mm de caixa, e esta almofada vai até 42 mm.")
 *     winders[].ocupadas             quantas posições têm relógio agora
 *     winders[].livres               quantas posições ligadas estão sem relógio
 *     winders[].documentos           quantos documentos o winder tem (o manual, a nota, as fotos: recurso=documentos&winder=<id>)
 *     campos_winder                  os campos dos winders (Cadastros › Campos, para o winder), na ordem: o formulário do winder
 *     campos_winder[]                um campo
 *     campos_winder[].identificador  o identificador (o nome em valores[<identificador>] da gravação)
 *     campos_winder[].nome           o nome do campo
 *     campos_winder[].tipo           inteiro, decimal, sim_nao, data, lista ou texto
 *     campos_winder[].unidade        a unidade (vazio: sem)
 *     campos_winder[].opcoes         os valores possíveis, na ordem (vazio: qualquer um)
 *     campos_winder[].opcoes[]       um valor possível
 *     campos_winder[].multiplo       verdadeiro: o campo é múltiplo (vários dos valores possíveis de uma vez; no formulário, uma caixa para
 *                                    cada)
 *     campos_winder[].regra          o mínimo e o máximo do número em texto, para mostrar embaixo da entrada; null: sem limite
 *     campos_winder[].padrao         o padrão do campo (vale quando o valor fica vazio); null: nenhum
 *     campos_winder[].padrao_texto   o padrão para mostrar, no idioma, com a unidade; null: nenhum
 *     campos_posicao                 os campos das posições (Cadastros › Campos, para a posição do winder: o programa), na ordem: o
 *                                    formulário da posição
 *     campos_posicao[]               um campo
 *     campos_posicao[].identificador o identificador (o nome em valores[<identificador>] da gravação)
 *     campos_posicao[].nome          o nome do campo
 *     campos_posicao[].tipo          inteiro, decimal, sim_nao, data, lista ou texto
 *     campos_posicao[].unidade       a unidade (vazio: sem)
 *     campos_posicao[].opcoes        os valores possíveis, na ordem (vazio: qualquer um)
 *     campos_posicao[].opcoes[]      um valor possível
 *     campos_posicao[].multiplo      verdadeiro: o campo é múltiplo (vários dos valores possíveis de uma vez; no formulário, uma caixa para
 *                                    cada)
 *     campos_posicao[].regra         o mínimo e o máximo do número em texto, para mostrar embaixo da entrada; null: sem limite
 *     campos_posicao[].padrao        o padrão do campo (vale quando o valor fica vazio); null: nenhum
 *     campos_posicao[].padrao_texto  o padrão para mostrar, no idioma, com a unidade; null: nenhum
 *     relogios[]                     os relógios disponíveis que podem ir para um winder (os que têm um tipo de lançamento marcado como
 *                                    winder), pelo nome: o Pôr da página
 *     relogios[].id                  o número (código)
 *     relogios[].nome                o nome
 *     relogios[].tipo                o tipo de lançamento de winder dele (o tipo do iniciar)
 *     relogios[].tipo_nome           o nome desse tipo ("No winder")
 *     relogios[].no_winder           verdadeiro: ele está no winder agora (a sessão do tipo dele aberta), numa posição ou sem posição (a
 *                                    sessão aberta antes de os winders serem cadastrados)
 *     relogios[].posicao_id          a posição em que ele está agora; null: fora de um winder
 *     relogios[].posicao             essa posição por extenso ("Escritório, Pos. 1"); null: fora de um winder
 *     programas                      os programas para escolher em cada posição (a página Programas), pelo nome
 *     programas[]                    um programa
 *     programas[].id                 o número (o programa_id do winder_posicoes salvar)
 *     programas[].nome               o nome
 *     programas[].texto              o cálculo por extenso (as voltas por dia, o ciclo e as vezes por dia)
 *   recurso=programas
 *     programas                      os programas dos winders, pelo nome
 *     programas[]                    um programa
 *     programas[].id                 o número do programa
 *     programas[].nome               o nome ("Versa 650")
 *     programas[].medida             o tipo de cadastro: voltas (os giros em voltas) ou tempo (os giros em minutos e segundos)
 *     programas[].medida_texto       o tipo por extenso ("Por tempo")
 *     programas[].velocidade         a velocidade do berço contada, em voltas por minuto; null: tirada do manual (tpd_manual) ou sem
 *     programas[].tpd_manual         as voltas por dia que o manual diz, de onde a velocidade sai; null: a velocidade é a contada (ou não
 *                                    há)
 *     programas[].liga_horas         as horas ligado de cada bloco; null: o dia todo
 *     programas[].para_horas         as horas parado de cada bloco; null: o dia todo
 *     programas[].funciona_texto     quando funciona por extenso ("o dia todo", "liga 3 h e para 9 h")
 *     programas[].observacao         a observação (null)
 *     programas[].sequencia          os códigos dos movimentos, na ordem (o sequencia do salvar)
 *     programas[].sequencia[]        o código de um movimento (o id em movimentos)
 *     programas[].calculo            o cálculo do programa (tudo o que a página mostra e ninguém digita)
 *     programas[].calculo.passos     os passos da sequência, na ordem
 *     programas[].calculo.passos[]   um passo
 *     programas[].calculo.passos[].ordem
 *                                    a ordem dele (1, 2...)
 *     programas[].calculo.passos[].movimento_id
 *                                    o código do movimento
 *     programas[].calculo.passos[].nome
 *                                    o nome do movimento
 *     programas[].calculo.passos[].tipo
 *                                    horario, anti_horario ou parado
 *     programas[].calculo.passos[].tipo_texto
 *                                    o tipo por extenso ("Giro horário")
 *     programas[].calculo.passos[].voltas
 *                                    as voltas do passo (0 na parada); null: sem velocidade para calcular
 *     programas[].calculo.passos[].segundos
 *                                    quanto tempo o passo dura, em segundos; null: sem velocidade para calcular
 *     programas[].calculo.passos[].voltas_texto
 *                                    as voltas por extenso ("10 voltas")
 *     programas[].calculo.passos[].tempo_texto
 *                                    o tempo por extenso ("2 min")
 *     programas[].calculo.passos[].voltas_calculadas
 *                                    verdadeiro: as voltas saíram da conta (tempo × velocidade), não do movimento
 *     programas[].calculo.passos[].tempo_calculado
 *                                    verdadeiro: o tempo saiu da conta (voltas ÷ velocidade), não do movimento
 *     programas[].calculo.velocidade a velocidade usada, em voltas por minuto; null: sem
 *     programas[].calculo.velocidade_origem
 *                                    de onde ela vem: contada (a informada) ou manual (tirada das voltas por dia do manual); null: sem
 *     programas[].calculo.velocidade_texto
 *                                    a velocidade por extenso ("4,51 voltas por minuto (tirada das voltas por dia do manual)"); null: sem
 *     programas[].calculo.minutos_ligado_dia
 *                                    os minutos ligado por dia (1440 o dia todo; nos blocos, a parte ligada)
 *     programas[].calculo.ciclo_segundos
 *                                    quanto a sequência inteira dura, em segundos; null: sem conta
 *     programas[].calculo.ciclo_texto
 *                                    o ciclo por extenso ("20 min")
 *     programas[].calculo.vezes_dia  quantas vezes a sequência roda por dia (os minutos ligado ÷ o ciclo)
 *     programas[].calculo.minutos_girando_dia
 *                                    os minutos girando por dia (as paradas não contam)
 *     programas[].calculo.girando_texto
 *                                    o tempo girando por extenso ("2 h 24 min")
 *     programas[].calculo.voltas_dia as voltas por dia (o TPD), calculadas: as voltas de um ciclo × as vezes por dia; null: sem conta
 *     programas[].calculo.voltas_horario
 *                                    as voltas por dia no sentido horário (a carga de quem dá corda nesse sentido)
 *     programas[].calculo.voltas_anti_horario
 *                                    as voltas por dia no sentido anti-horário
 *     programas[].calculo.voltas_texto
 *                                    as voltas por extenso ("1.800 (900 horário + 900 anti-horário)")
 *     programas[].calculo.erros      o que impede a conta (a sequência vazia ou só com paradas, um giro em voltas num programa por tempo,
 *                                    as voltas do manual que as paradas não deixam dar...); vazio: fecha
 *     programas[].calculo.erros[]    um erro, por extenso
 *     programas[].calculo.avisos     o que vale conferir (sem a velocidade do berço, sem as voltas por dia; voltas por dia demais para um
 *                                    automático...)
 *     programas[].calculo.avisos[]   um aviso, por extenso
 *     programas[].calculo.texto      o resumo por extenso ("650 voltas por dia (só no sentido anti-horário) · ciclo de 20 min, 72 vezes por
 *                                    dia")
 *     programas[].usado_em           as posições que usam o programa
 *     programas[].usado_em[]         uma posição
 *     programas[].usado_em[].posicao_id
 *                                    o número da posição
 *     programas[].usado_em[].texto   o winder e a posição por extenso ("Versa, Pos. 1")
 *     movimentos                     os movimentos (a página Movimentos), os giros horários, os anti-horários e as paradas
 *     movimentos[]                   um movimento
 *     movimentos[].id                o código do movimento (o que a sequência do programa usa)
 *     movimentos[].nome              o nome ("Giro horário 2 min")
 *     movimentos[].tipo              horario, anti_horario ou parado
 *     movimentos[].tipo_texto        o tipo por extenso
 *     movimentos[].voltas            as voltas do giro; null: medido em tempo (ou a parada)
 *     movimentos[].segundos          o tempo, em segundos; null: medido em voltas
 *     movimentos[].medida            voltas ou tempo: como ele é medido
 *     movimentos[].medida_texto      a medida por extenso ("2 min", "10 voltas")
 *     movimentos[].texto             o movimento por extenso ("Giro horário: 2 min")
 *     movimentos[].usado_em          os programas que o usam
 *     movimentos[].usado_em[]        um programa
 *     movimentos[].usado_em[].programa_id
 *                                    o número do programa
 *     movimentos[].usado_em[].nome   o nome dele
 *     tipos                          os tipos de movimento, na ordem
 *     tipos[]                        um tipo
 *     tipos[].valor                  horario, anti_horario ou parado (o tipo do movimentos salvar)
 *     tipos[].nome                   o nome por extenso
 *     medidas                        os tipos de cadastro do programa
 *     medidas[]                      um tipo
 *     medidas[].valor                voltas ou tempo (a medida do programas salvar)
 *     medidas[].nome                 o nome por extenso ("Por voltas")
 *     calculo                        com previa=1: o cálculo do programa do pedido, como em programas[].calculo (os erros do pedido entram
 *                                    em erros)
 *     sequencia                      com previa=1: os códigos dos movimentos da sequência do pedido, na ordem
 *     sequencia[]                    o código de um movimento
 *   recurso=manutencao
 *     filtro                         os filtros do pedido
 *     filtro.relogio                 o relógio (0: todos)
 *     filtro.oficina                 a oficina (0: todas)
 *     filtro.de                      o começo do período (vazio: sem)
 *     filtro.ate                     o fim do período (vazio: sem)
 *     hoje                           o dia de hoje na coleção (AAAA-MM-DD)
 *     abertas                        as manutenções abertas (os relógios na oficina agora), sem os filtros
 *     abertas[]                      uma manutenção
 *     abertas[].id                   o número da manutenção
 *     abertas[].relogio_id           o relógio
 *     abertas[].relogio              o nome do relógio
 *     abertas[].oficina_id           a oficina (Manutenção › Oficinas)
 *     abertas[].oficina              o nome da oficina
 *     abertas[].situacao             a situação agora: o identificador de um item da lista Situações da manutenção (Manutenção › Listas:
 *                                    enviado, orcamento, aprovado, peca, pronto, retirado...)
 *     abertas[].situacao_texto       a situação por extenso
 *     abertas[].situacao_comportamento
 *                                    o que a situação faz: aberta (o relógio na oficina), encerra (voltou e virou serviço) ou cancela
 *                                    (voltou sem serviço)
 *     abertas[].situacao_cor         a cor da situação (#rrggbb); null: sem
 *     abertas[].aviso                aberta: o aviso da situação de agora (Aprovar o orçamento, Retirar na oficina); null: sem aviso
 *     abertas[].aberta               true com o relógio na oficina agora (a situação faz aberta)
 *     abertas[].tipo                 o tipo de serviço pedido: o identificador de um item da lista Tipos de serviço
 *     abertas[].tipo_texto           o tipo de serviço por extenso
 *     abertas[].problema             o problema (o que se pediu à oficina); null: sem
 *     abertas[].os                   o número da ordem de serviço da oficina; null: sem
 *     abertas[].enviado              o dia do envio (AAAA-MM-DD)
 *     abertas[].previsao             o dia previsto para a volta (AAAA-MM-DD); null: sem
 *     abertas[].atrasada             true se ela está aberta e a previsão já passou (o aviso Cobrar a oficina)
 *     abertas[].dias                 quantos dias o relógio ficou (ou está) na oficina: do envio à volta, ou até hoje
 *     abertas[].orcamento            o orçamento, em R$; null: sem
 *     abertas[].orcamento_data       o dia do orçamento (AAAA-MM-DD); null: sem
 *     abertas[].encerrado            o dia da volta (AAAA-MM-DD, numa situação que encerra ou cancela); null: aberta
 *     abertas[].disponivel_antes     a disponibilidade para o rodízio com que o relógio volta (a de antes do envio, ou a pedida durante a
 *                                    manutenção)
 *     abertas[].observacao           a observação; null: sem
 *     abertas[].desde                quando ela entrou na situação de agora (a última mudança da história)
 *     abertas[].eventos              a história: cada mudança de situação, da mais velha para a mais nova
 *     abertas[].eventos[]            uma mudança
 *     abertas[].eventos[].situacao   o identificador da situação
 *     abertas[].eventos[].situacao_texto
 *                                    a situação por extenso
 *     abertas[].eventos[].quando     quando (texto AAAA-MM-DD HH:MM:SS)
 *     abertas[].eventos[].observacao a observação da mudança; null: sem
 *     abertas[].acessorios           o que foi junto (os acessórios da coleção)
 *     abertas[].acessorios[]         um acessório
 *     abertas[].acessorios[].id      o número do acessório
 *     abertas[].acessorios[].nome    o nome do acessório
 *     abertas[].acessorios[].tipo_texto
 *                                    o tipo do acessório por extenso
 *     abertas[].documentos           os documentos do envio (os do relógio: a OS, o orçamento, as fotos da entrega)
 *     abertas[].documentos[]         um documento
 *     abertas[].documentos[].id      o número do documento
 *     abertas[].documentos[].titulo  o título
 *     abertas[].documentos[].url     o endereço do arquivo (recurso=documento)
 *     abertas[].servicos             os serviços que a volta registrou (recurso=servicos)
 *     abertas[].servicos[]           um serviço
 *     abertas[].servicos[].id        o número do serviço
 *     abertas[].servicos[].data      a data do serviço (AAAA-MM-DD)
 *     abertas[].servicos[].tipo_texto
 *                                    o tipo do serviço por extenso
 *     abertas[].servicos[].custo     o custo do serviço (a mão de obra mais as peças), em R$; null: nada com custo
 *     abertas[].custo                o custo da manutenção: os serviços dela somados, em R$; null: nada com custo
 *     abertas[].criado               quando foi cadastrada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     manutencoes                    as manutenções dos filtros, da mais nova para a mais velha
 *     manutencoes[]                  uma manutenção
 *     manutencoes[].id               o número da manutenção
 *     manutencoes[].relogio_id       o relógio
 *     manutencoes[].relogio          o nome do relógio
 *     manutencoes[].oficina_id       a oficina (Manutenção › Oficinas)
 *     manutencoes[].oficina          o nome da oficina
 *     manutencoes[].situacao         a situação agora: o identificador de um item da lista Situações da manutenção (Manutenção › Listas:
 *                                    enviado, orcamento, aprovado, peca, pronto, retirado...)
 *     manutencoes[].situacao_texto   a situação por extenso
 *     manutencoes[].situacao_comportamento
 *                                    o que a situação faz: aberta (o relógio na oficina), encerra (voltou e virou serviço) ou cancela
 *                                    (voltou sem serviço)
 *     manutencoes[].situacao_cor     a cor da situação (#rrggbb); null: sem
 *     manutencoes[].aviso            aberta: o aviso da situação de agora (Aprovar o orçamento, Retirar na oficina); null: sem aviso
 *     manutencoes[].aberta           true com o relógio na oficina agora (a situação faz aberta)
 *     manutencoes[].tipo             o tipo de serviço pedido: o identificador de um item da lista Tipos de serviço
 *     manutencoes[].tipo_texto       o tipo de serviço por extenso
 *     manutencoes[].problema         o problema (o que se pediu à oficina); null: sem
 *     manutencoes[].os               o número da ordem de serviço da oficina; null: sem
 *     manutencoes[].enviado          o dia do envio (AAAA-MM-DD)
 *     manutencoes[].previsao         o dia previsto para a volta (AAAA-MM-DD); null: sem
 *     manutencoes[].atrasada         true se ela está aberta e a previsão já passou (o aviso Cobrar a oficina)
 *     manutencoes[].dias             quantos dias o relógio ficou (ou está) na oficina: do envio à volta, ou até hoje
 *     manutencoes[].orcamento        o orçamento, em R$; null: sem
 *     manutencoes[].orcamento_data   o dia do orçamento (AAAA-MM-DD); null: sem
 *     manutencoes[].encerrado        o dia da volta (AAAA-MM-DD, numa situação que encerra ou cancela); null: aberta
 *     manutencoes[].disponivel_antes a disponibilidade para o rodízio com que o relógio volta (a de antes do envio, ou a pedida durante a
 *                                    manutenção)
 *     manutencoes[].observacao       a observação; null: sem
 *     manutencoes[].desde            quando ela entrou na situação de agora (a última mudança da história)
 *     manutencoes[].eventos          a história: cada mudança de situação, da mais velha para a mais nova
 *     manutencoes[].eventos[]        uma mudança
 *     manutencoes[].eventos[].situacao
 *                                    o identificador da situação
 *     manutencoes[].eventos[].situacao_texto
 *                                    a situação por extenso
 *     manutencoes[].eventos[].quando quando (texto AAAA-MM-DD HH:MM:SS)
 *     manutencoes[].eventos[].observacao
 *                                    a observação da mudança; null: sem
 *     manutencoes[].acessorios       o que foi junto (os acessórios da coleção)
 *     manutencoes[].acessorios[]     um acessório
 *     manutencoes[].acessorios[].id  o número do acessório
 *     manutencoes[].acessorios[].nome
 *                                    o nome do acessório
 *     manutencoes[].acessorios[].tipo_texto
 *                                    o tipo do acessório por extenso
 *     manutencoes[].documentos       os documentos do envio (os do relógio: a OS, o orçamento, as fotos da entrega)
 *     manutencoes[].documentos[]     um documento
 *     manutencoes[].documentos[].id  o número do documento
 *     manutencoes[].documentos[].titulo
 *                                    o título
 *     manutencoes[].documentos[].url o endereço do arquivo (recurso=documento)
 *     manutencoes[].servicos         os serviços que a volta registrou (recurso=servicos)
 *     manutencoes[].servicos[]       um serviço
 *     manutencoes[].servicos[].id    o número do serviço
 *     manutencoes[].servicos[].data  a data do serviço (AAAA-MM-DD)
 *     manutencoes[].servicos[].tipo_texto
 *                                    o tipo do serviço por extenso
 *     manutencoes[].servicos[].custo o custo do serviço (a mão de obra mais as peças), em R$; null: nada com custo
 *     manutencoes[].custo            o custo da manutenção: os serviços dela somados, em R$; null: nada com custo
 *     manutencoes[].criado           quando foi cadastrada (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     oficinas                       as oficinas da coleção, por nome
 *     oficinas[]                     uma oficina
 *     oficinas[].id                  o número da oficina
 *     oficinas[].nome                o nome da oficina
 *     oficinas[].tipo                o tipo: o identificador de um item da lista Tipos de oficina (Manutenção › Listas)
 *     oficinas[].tipo_texto          o tipo por extenso
 *     oficinas[].propria             true na sua oficina (o tipo faz "você mesmo, em casa": a Eu mesmo (em casa))
 *     oficinas[].endereco            o endereço (a rua); null: sem
 *     oficinas[].numero              o número; null: sem
 *     oficinas[].complemento         o complemento; null: sem
 *     oficinas[].bairro              o bairro; null: sem
 *     oficinas[].cidade              a cidade; null: sem
 *     oficinas[].uf                  o estado; null: sem
 *     oficinas[].cep                 o CEP; null: sem
 *     oficinas[].endereco_texto      o endereço inteiro numa linha; null: sem endereço
 *     oficinas[].horario             o horário de atendimento; null: sem
 *     oficinas[].observacao          a observação; null: sem
 *     oficinas[].contatos            os contatos da oficina, o principal primeiro
 *     oficinas[].contatos[]          um contato
 *     oficinas[].contatos[].id       o número do contato
 *     oficinas[].contatos[].tipo     o tipo do contato: o identificador de um item da lista Tipos de contato (Manutenção › Listas:
 *                                    telefone, celular, whatsapp, email, site...)
 *     oficinas[].contatos[].tipo_texto
 *                                    o tipo do contato por extenso
 *     oficinas[].contatos[].comportamento
 *                                    o que o tipo faz: telefone, whatsapp, email, link ou texto
 *     oficinas[].contatos[].valor    o número, o e-mail ou o endereço
 *     oficinas[].contatos[].pessoa   com quem falar; null: sem
 *     oficinas[].contatos[].observacao
 *                                    a observação do contato; null: sem
 *     oficinas[].contatos[].principal
 *                                    true no contato principal
 *     oficinas[].contatos[].link     o endereço que abre o contato pelo que o tipo faz (tel:, o wa.me do WhatsApp, mailto:, o site); null:
 *                                    o texto
 *     oficinas[].abertas             quantos relógios estão nela agora
 *     oficinas[].manutencoes         quantas manutenções ela teve (todas)
 *     oficinas[].gasto               quanto ela custou: os serviços das manutenções dela somados, em R$; null: nada com custo
 *     resumo                         o resumo
 *     resumo.abertas                 quantos relógios estão na oficina agora
 *     resumo.atrasadas               quantas abertas passaram da previsão
 *     resumo.manutencoes             quantas manutenções os filtros trazem
 *     resumo.gasto                   o custo das manutenções dos filtros somado, em R$
 *     resumo.por_oficina             as oficinas que já tiveram manutenção
 *     resumo.por_oficina[]           uma oficina
 *     resumo.por_oficina[].oficina_id
 *                                    o número da oficina
 *     resumo.por_oficina[].oficina   o nome da oficina
 *     resumo.por_oficina[].abertas   quantos relógios estão nela agora
 *     resumo.por_oficina[].manutencoes
 *                                    quantas manutenções ela teve (todas)
 *     resumo.por_oficina[].gasto     quanto ela custou, em R$; null: nada com custo
 *     situacoes                      os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     situacoes[]                    um item da lista
 *     situacoes[].valor              o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     situacoes[].nome               o nome do item
 *     situacoes[].id                 o número do item (o id da gravação, recurso=listas)
 *     situacoes[].ordem              a ordem na lista
 *     situacoes[].comportamento      o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     situacoes[].texto              o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     situacoes[].cor                a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     tipos                          os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     tipos[]                        um item da lista
 *     tipos[].valor                  o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     tipos[].nome                   o nome do item
 *     tipos[].id                     o número do item (o id da gravação, recurso=listas)
 *     tipos[].ordem                  a ordem na lista
 *     tipos[].comportamento          o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     tipos[].texto                  o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     tipos[].cor                    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     tipos_oficina                  os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     tipos_oficina[]                um item da lista
 *     tipos_oficina[].valor          o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     tipos_oficina[].nome           o nome do item
 *     tipos_oficina[].id             o número do item (o id da gravação, recurso=listas)
 *     tipos_oficina[].ordem          a ordem na lista
 *     tipos_oficina[].comportamento  o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     tipos_oficina[].texto          o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     tipos_oficina[].cor            a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     tipos_contato                  os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     tipos_contato[]                um item da lista
 *     tipos_contato[].valor          o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     tipos_contato[].nome           o nome do item
 *     tipos_contato[].id             o número do item (o id da gravação, recurso=listas)
 *     tipos_contato[].ordem          a ordem na lista
 *     tipos_contato[].comportamento  o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     tipos_contato[].texto          o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     tipos_contato[].cor            a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     tipos[].marca                  o tipo de lançamento que o tipo de serviço marca (o texto do item); null: nenhum
 *     relogios                       os relógios da coleção (na coleção e emprestados), os que podem ir para a manutenção
 *     relogios[]                     um relógio
 *     relogios[].id                  o número do relógio
 *     relogios[].nome                o nome do relógio
 *     relogios[].posse               a situação na coleção (o identificador)
 *     relogios[].posse_texto         a situação por extenso
 *     relogios[].disponivel          true se está disponível para o rodízio agora
 *     relogios[].manutencao_id       a manutenção aberta dele; null: não está em manutenção
 *     relogios[].em_manutencao       a oficina e desde quando, por extenso; null: não está
 *     acessorios                     os acessórios da coleção (o que pode ir junto)
 *     acessorios[]                   um acessório
 *     acessorios[].id                o número do acessório
 *     acessorios[].nome              o nome do acessório
 *     acessorios[].tipo_texto        o tipo do acessório por extenso
 *     acessorios[].relogio_id        o relógio de que ele é; null: solto
 *     trocar_hoje                    true se a Configuração deixa trocar o relógio de hoje no próprio dia (o relógio de hoje que vai para a
 *                                    manutenção deixa o dia livre para escolher outro; sem ela, hoje fica sem relógio)
 *   recurso=oficinas
 *     oficinas                       as oficinas da coleção, por nome
 *     oficinas[]                     uma oficina
 *     oficinas[].id                  o número da oficina
 *     oficinas[].nome                o nome da oficina
 *     oficinas[].tipo                o tipo: o identificador de um item da lista Tipos de oficina (Manutenção › Listas)
 *     oficinas[].tipo_texto          o tipo por extenso
 *     oficinas[].propria             true na sua oficina (o tipo faz "você mesmo, em casa": a Eu mesmo (em casa))
 *     oficinas[].endereco            o endereço (a rua); null: sem
 *     oficinas[].numero              o número; null: sem
 *     oficinas[].complemento         o complemento; null: sem
 *     oficinas[].bairro              o bairro; null: sem
 *     oficinas[].cidade              a cidade; null: sem
 *     oficinas[].uf                  o estado; null: sem
 *     oficinas[].cep                 o CEP; null: sem
 *     oficinas[].endereco_texto      o endereço inteiro numa linha; null: sem endereço
 *     oficinas[].horario             o horário de atendimento; null: sem
 *     oficinas[].observacao          a observação; null: sem
 *     oficinas[].contatos            os contatos da oficina, o principal primeiro
 *     oficinas[].contatos[]          um contato
 *     oficinas[].contatos[].id       o número do contato
 *     oficinas[].contatos[].tipo     o tipo do contato: o identificador de um item da lista Tipos de contato (Manutenção › Listas:
 *                                    telefone, celular, whatsapp, email, site...)
 *     oficinas[].contatos[].tipo_texto
 *                                    o tipo do contato por extenso
 *     oficinas[].contatos[].comportamento
 *                                    o que o tipo faz: telefone, whatsapp, email, link ou texto
 *     oficinas[].contatos[].valor    o número, o e-mail ou o endereço
 *     oficinas[].contatos[].pessoa   com quem falar; null: sem
 *     oficinas[].contatos[].observacao
 *                                    a observação do contato; null: sem
 *     oficinas[].contatos[].principal
 *                                    true no contato principal
 *     oficinas[].contatos[].link     o endereço que abre o contato pelo que o tipo faz (tel:, o wa.me do WhatsApp, mailto:, o site); null:
 *                                    o texto
 *     oficinas[].abertas             quantos relógios estão nela agora
 *     oficinas[].manutencoes         quantas manutenções ela teve (todas)
 *     oficinas[].gasto               quanto ela custou: os serviços das manutenções dela somados, em R$; null: nada com custo
 *     tipos                          os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     tipos[]                        um item da lista
 *     tipos[].valor                  o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     tipos[].nome                   o nome do item
 *     tipos[].id                     o número do item (o id da gravação, recurso=listas)
 *     tipos[].ordem                  a ordem na lista
 *     tipos[].comportamento          o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     tipos[].texto                  o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     tipos[].cor                    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     tipos_contato                  os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     tipos_contato[]                um item da lista
 *     tipos_contato[].valor          o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     tipos_contato[].nome           o nome do item
 *     tipos_contato[].id             o número do item (o id da gravação, recurso=listas)
 *     tipos_contato[].ordem          a ordem na lista
 *     tipos_contato[].comportamento  o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     tipos_contato[].texto          o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     tipos_contato[].cor            a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *   recurso=listas
 *     listas                         as listas que são cadastro (as da página pedida, ou todas)
 *     listas[]                       uma lista
 *     listas[].lista                 o identificador da lista (relogio_situacao, acessorio_tipo, reserva_protocolo, reserva_observacao,
 *                                    manutencao_situacao, servico_tipo, estanqueidade, oficina_tipo, contato_tipo)
 *     listas[].nome                  o nome da lista
 *     listas[].pagina                onde ela se edita: cadastros (Cadastros › Listas) ou manutencao (Manutenção › Listas)
 *     listas[].texto                 o que quer dizer o texto do item nesta lista; null: os itens não têm texto
 *     listas[].cor                   true se os itens têm cor
 *     listas[].reservados            os identificadores que o sistema usa para outra coisa (nenhum item novo pode ter)
 *     listas[].reservados[]          um identificador reservado
 *     listas[].essenciais            o que a lista nunca fica sem (o último item que faz isso não sai nem muda o que faz)
 *     listas[].essenciais[]          um comportamento
 *     listas[].comportamentos        o que um item desta lista pode fazer (vazio: a lista não tem)
 *     listas[].comportamentos[]      um comportamento
 *     listas[].comportamentos[].valor
 *                                    o comportamento (o que se grava em comportamento)
 *     listas[].comportamentos[].nome o que ele faz, por extenso
 *     listas[].itens                 os itens da lista, na ordem (as listas que são cadastro: Cadastros › Listas e Manutenção › Listas)
 *     listas[].itens[]               um item da lista
 *     listas[].itens[].valor         o identificador do item (fixo: é o que se grava e o que a API e as fórmulas citam)
 *     listas[].itens[].nome          o nome do item
 *     listas[].itens[].id            o número do item (o id da gravação, recurso=listas)
 *     listas[].itens[].ordem         a ordem na lista
 *     listas[].itens[].comportamento o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     listas[].itens[].texto         o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     listas[].itens[].cor           a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     listas[].itens[].identificador o identificador do item (o mesmo de valor)
 *     listas[].itens[].em_uso        quantas vezes ele está em uso, em todas as coleções da árvore (em uso, ele não sai, e o que ele faz
 *                                    não muda)
 *   recurso=servicos
 *     tipos                          os tipos de serviço, na ordem
 *     tipos[]                        um tipo
 *     tipos[].valor                  o tipo (o tipo da gravação)
 *     tipos[].nome                   o nome por extenso
 *     tipos[].id                     o número do item (o id da gravação, recurso=listas)
 *     tipos[].ordem                  a ordem na lista
 *     tipos[].comportamento          o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     tipos[].texto                  o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     tipos[].cor                    a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     servicos                       os serviços (de um relógio, ou de todos), do mais novo para o mais velho
 *     servicos[]                     um serviço
 *     servicos[].id                  o número do serviço (o id da gravação)
 *     servicos[].relogio_id          o relógio do serviço
 *     servicos[].relogio             o nome do relógio
 *     servicos[].data                a data do serviço (AAAA-MM-DD)
 *     servicos[].ha_dias             há quantos dias foi (no dia da coleção)
 *     servicos[].tipo                o tipo: o identificador de um item da lista Tipos de serviço (Manutenção › Listas: revisao, pilha,
 *                                    estanqueidade, regulagem, polimento...)
 *     servicos[].tipo_texto          o tipo por extenso
 *     servicos[].oficina             a oficina do serviço (a da manutenção de que ele é o encerramento: Manutenção › Oficinas)
 *     servicos[].descricao           o que foi feito; null: não informado
 *     servicos[].mao_de_obra         a mão de obra, em R$; null: não informada
 *     servicos[].pecas               as peças trocadas, na ordem
 *     servicos[].pecas[]             uma peça
 *     servicos[].pecas[].nome        o nome da peça
 *     servicos[].pecas[].custo       o custo da peça, em R$; null: não informado
 *     servicos[].custo_pecas         a soma do custo das peças, em R$ (0 sem nenhuma com custo)
 *     servicos[].custo               o custo do serviço: a mão de obra mais as peças, em R$; null: nada com custo
 *     servicos[].estanqueidade       o resultado do teste de estanqueidade: o identificador de um item da lista Resultados do teste de
 *                                    estanqueidade (Manutenção › Listas: aprovado, reprovado...); null: não testada
 *     servicos[].estanqueidade_texto o resultado por extenso (o nome do item, em minúsculas); null: não testada
 *     servicos[].estanqueidade_aprova
 *                                    o resultado aprova a vedação (o item do resultado faz aprova)? true ou false; null: não testada
 *     servicos[].manutencao_id       a manutenção de que o serviço é o encerramento (recurso=manutencao)
 *     servicos[].oficina_id          o número da oficina
 *     servicos[].estanqueidade_bar   a pressão do teste, em bar; null: não informada
 *     servicos[].marcha_antes        a marcha antes do serviço, informada pelo profissional, em s/dia (+ adianta); null: não informada
 *     servicos[].marcha_depois       a marcha depois do serviço, informada pelo profissional, em s/dia; null: não informada
 *     servicos[].marcha_antes_texto  a marcha antes, pronta ("+25,0 s/dia"); null
 *     servicos[].marcha_depois_texto a marcha depois, pronta; null
 *     servicos[].medida_antes        a marcha medida pelo sistema antes do serviço: os intervalos do desvio da hora que terminam nos 60
 *                                    dias antes da data; null: nenhum
 *     servicos[].medida_antes.marcha a marcha, em s/dia, pesada pelo tempo de cada intervalo
 *     servicos[].medida_antes.marcha_texto
 *                                    a marcha pronta
 *     servicos[].medida_antes.intervalos
 *                                    quantos intervalos entraram
 *     servicos[].medida_depois       a marcha medida pelo sistema depois do serviço: os intervalos que começam nos 60 dias depois do dia do
 *                                    serviço; null: nenhum
 *     servicos[].medida_depois.marcha
 *                                    a marcha, em s/dia
 *     servicos[].medida_depois.marcha_texto
 *                                    a marcha pronta
 *     servicos[].medida_depois.intervalos
 *                                    quantos intervalos entraram
 *     servicos[].garantia_ate        a garantia do serviço até (AAAA-MM-DD); null: sem
 *     servicos[].garantia_vale       true se a garantia ainda vale hoje
 *     servicos[].observacao          a observação do profissional; null: sem
 *     servicos[].documentos          os documentos do relógio que são do serviço (a nota, o orçamento, as fotos), pelo título
 *     servicos[].documentos[]        um documento
 *     servicos[].documentos[].id     o número do documento
 *     servicos[].documentos[].titulo o título
 *     servicos[].documentos[].url    o endereço do arquivo (recurso=documento)
 *     servicos[].marcacao            true se o serviço virou também a marcação do tipo de lançamento que o tipo de serviço marca (o texto
 *                                    do item: revisao, pilha)
 *     servicos[].criado              quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio                        o relógio pedido; null: todos
 *     relogio.id                     o número
 *     relogio.nome                   o nome
 *     tipos[].marca                  true se o serviço desse tipo vira também a marcação de mesmo nome (revisao, pilha)
 *     resumo                         com o relógio: o resumo dos serviços dele; null: todos
 *     resumo.quantos                 quantos serviços o relógio tem
 *     resumo.custo                   o custo somado dos serviços, em R$; null: nenhum com custo
 *     resumo.ultimo                  o último serviço; null: nenhum
 *     resumo.ultimo.data             a data dele (AAAA-MM-DD)
 *     resumo.ultimo.tipo             o tipo
 *     resumo.ultimo.tipo_texto       o tipo por extenso
 *     resumo.ultimo.ha_dias          há quantos dias
 *     resumo.resistencia_declarada   a resistência à água declarada pelo fabricante, em metros (o campo resistencia_agua_declarada do
 *                                    cadastro); null: não informada
 *     resumo.estanqueidade           a estanqueidade comprovada: o último serviço com o teste; null: nunca testada aqui
 *     resumo.estanqueidade.data      a data do teste
 *     resumo.estanqueidade.ha_dias   há quantos dias
 *     resumo.estanqueidade.resultado o identificador do resultado (Manutenção › Listas: aprovado, reprovado...)
 *     resumo.estanqueidade.resultado_texto
 *                                    o resultado por extenso (o nome do item, em minúsculas)
 *     resumo.estanqueidade.aprova    true se o resultado aprova a vedação; false se reprova
 *     resumo.estanqueidade.bar       a pressão do teste, em bar; null
 *     resumo.estanqueidade.servico_id
 *                                    o serviço do teste
 *     resumo.testes_estanqueidade    quantos serviços tiveram o teste de estanqueidade
 *     documentos                     com o relógio: os documentos dele que um serviço pode citar (o formulário); null: todos
 *     documentos[]                   um documento
 *     documentos[].id                o número do documento
 *     documentos[].titulo            o título
 *   recurso=timegrapher
 *     relogio                        o relógio pedido; null: todos
 *     relogio.id                     o número
 *     relogio.nome                   o nome
 *     timegrapher                    com o relógio: o timegrapher dele (como na ficha); null sem o relógio
 *     timegrapher.resumo             a frase da última sessão (a média de cada grandeza e a diferença entre as posições), ou o que falta
 *     timegrapher.sessao             a última sessão: as medições do mesmo dia da mais nova; null: nenhuma medição
 *     timegrapher.sessao.dia         o dia (AAAA-MM-DD)
 *     timegrapher.sessao.medicoes    quantas medições no dia
 *     timegrapher.sessao.posicoes    quantas posições diferentes com a marcha
 *     timegrapher.sessao.delta       a diferença de marcha entre as posições (a maior menos a menor, s/dia); null com menos de duas
 *     timegrapher.sessao.delta_texto a diferença por extenso
 *     timegrapher.sessao.marcha      a marcha: a média da sessão; null sem medida
 *     timegrapher.sessao.marcha_texto
 *                                    a marcha por extenso
 *     timegrapher.sessao.amplitude   a amplitude: a média da sessão; null sem medida
 *     timegrapher.sessao.amplitude_texto
 *                                    a amplitude por extenso
 *     timegrapher.sessao.erro_batida o erro de batida: a média da sessão; null sem medida
 *     timegrapher.sessao.erro_batida_texto
 *                                    o erro de batida por extenso
 *     timegrapher.sessao.bph         as batidas por hora: a média da sessão; null sem medida
 *     timegrapher.sessao.bph_texto   as batidas por hora por extenso
 *     timegrapher.sessao.angulo      o ângulo de levantamento: a média da sessão; null sem medida
 *     timegrapher.sessao.angulo_texto
 *                                    o ângulo de levantamento por extenso
 *     timegrapher.sessao.itens[]     cada medição da sessão (como em medicoes[])
 *     timegrapher.sessao.itens[].id  o número da medição (o id da gravação)
 *     timegrapher.sessao.itens[].relogio_id
 *                                    o relógio
 *     timegrapher.sessao.itens[].relogio
 *                                    o nome do relógio
 *     timegrapher.sessao.itens[].quando
 *                                    quando foi medida (texto AAAA-MM-DD HH:MM:SS, no fuso do pedido)
 *     timegrapher.sessao.itens[].posicao
 *                                    a posição no aparelho: mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda ou
 *                                    coroa_direita; null: não informada
 *     timegrapher.sessao.itens[].posicao_texto
 *                                    a posição por extenso
 *     timegrapher.sessao.itens[].marcha
 *                                    a marcha, em segundos por dia (+ adianta, − atrasa); null: não medida
 *     timegrapher.sessao.itens[].marcha_texto
 *                                    a marcha por extenso ("+4,5 s/dia")
 *     timegrapher.sessao.itens[].amplitude
 *                                    a amplitude do balanço, em graus; null: não medida
 *     timegrapher.sessao.itens[].amplitude_texto
 *                                    a amplitude por extenso ("290°")
 *     timegrapher.sessao.itens[].erro_batida
 *                                    o erro de batida, em milissegundos; null: não medido
 *     timegrapher.sessao.itens[].erro_batida_texto
 *                                    o erro de batida por extenso ("0,3 ms")
 *     timegrapher.sessao.itens[].bph as batidas por hora (21600, 28800...); null: não informadas
 *     timegrapher.sessao.itens[].bph_texto
 *                                    as batidas por hora por extenso
 *     timegrapher.sessao.itens[].angulo
 *                                    o ângulo de levantamento (lift angle), em graus; null: não informado
 *     timegrapher.sessao.itens[].angulo_texto
 *                                    o ângulo por extenso
 *     timegrapher.sessao.itens[].origem
 *                                    manual (digitada) ou csv (importada do arquivo do aparelho)
 *     timegrapher.sessao.itens[].origem_texto
 *                                    a origem por extenso
 *     timegrapher.sessao.itens[].observacao
 *                                    a observação; null: nenhuma
 *     timegrapher.medicoes           todas as medições do relógio, da mais nova para a mais velha
 *     timegrapher.medicoes[]         uma medição
 *     timegrapher.medicoes[].id      o número da medição (o id da gravação)
 *     timegrapher.medicoes[].relogio_id
 *                                    o relógio
 *     timegrapher.medicoes[].relogio o nome do relógio
 *     timegrapher.medicoes[].quando  quando foi medida (texto AAAA-MM-DD HH:MM:SS, no fuso do pedido)
 *     timegrapher.medicoes[].posicao a posição no aparelho: mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda ou
 *                                    coroa_direita; null: não informada
 *     timegrapher.medicoes[].posicao_texto
 *                                    a posição por extenso
 *     timegrapher.medicoes[].marcha  a marcha, em segundos por dia (+ adianta, − atrasa); null: não medida
 *     timegrapher.medicoes[].marcha_texto
 *                                    a marcha por extenso ("+4,5 s/dia")
 *     timegrapher.medicoes[].amplitude
 *                                    a amplitude do balanço, em graus; null: não medida
 *     timegrapher.medicoes[].amplitude_texto
 *                                    a amplitude por extenso ("290°")
 *     timegrapher.medicoes[].erro_batida
 *                                    o erro de batida, em milissegundos; null: não medido
 *     timegrapher.medicoes[].erro_batida_texto
 *                                    o erro de batida por extenso ("0,3 ms")
 *     timegrapher.medicoes[].bph     as batidas por hora (21600, 28800...); null: não informadas
 *     timegrapher.medicoes[].bph_texto
 *                                    as batidas por hora por extenso
 *     timegrapher.medicoes[].angulo  o ângulo de levantamento (lift angle), em graus; null: não informado
 *     timegrapher.medicoes[].angulo_texto
 *                                    o ângulo por extenso
 *     timegrapher.medicoes[].origem  manual (digitada) ou csv (importada do arquivo do aparelho)
 *     timegrapher.medicoes[].origem_texto
 *                                    a origem por extenso
 *     timegrapher.medicoes[].observacao
 *                                    a observação; null: nenhuma
 *     timegrapher.sessoes            quantas sessões (dias com medição)
 *     timegrapher.grandezas          as grandezas, para o formulário
 *     timegrapher.grandezas[]        uma grandeza
 *     timegrapher.grandezas[].campo  o nome do campo (marcha, amplitude, erro_batida, bph, angulo)
 *     timegrapher.grandezas[].nome   o nome por extenso
 *     timegrapher.grandezas[].unidade
 *                                    a unidade (s/dia, °, ms, bph)
 *     timegrapher.grandezas[].minimo o menor valor aceito
 *     timegrapher.grandezas[].maximo o maior valor aceito
 *     timegrapher.posicoes           as posições do aparelho, para o formulário
 *     timegrapher.posicoes[]         uma posição
 *     timegrapher.posicoes[].valor   o código
 *     timegrapher.posicoes[].texto   o nome por extenso
 *     medicoes                       sem o relógio: as medições de todos, da mais nova para a mais velha
 *     medicoes[]                     uma medição
 *     medicoes[].id                  o número da medição (o id da gravação)
 *     medicoes[].relogio_id          o relógio
 *     medicoes[].relogio             o nome do relógio
 *     medicoes[].quando              quando foi medida (texto AAAA-MM-DD HH:MM:SS, no fuso do pedido)
 *     medicoes[].posicao             a posição no aparelho: mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda ou
 *                                    coroa_direita; null: não informada
 *     medicoes[].posicao_texto       a posição por extenso
 *     medicoes[].marcha              a marcha, em segundos por dia (+ adianta, − atrasa); null: não medida
 *     medicoes[].marcha_texto        a marcha por extenso ("+4,5 s/dia")
 *     medicoes[].amplitude           a amplitude do balanço, em graus; null: não medida
 *     medicoes[].amplitude_texto     a amplitude por extenso ("290°")
 *     medicoes[].erro_batida         o erro de batida, em milissegundos; null: não medido
 *     medicoes[].erro_batida_texto   o erro de batida por extenso ("0,3 ms")
 *     medicoes[].bph                 as batidas por hora (21600, 28800...); null: não informadas
 *     medicoes[].bph_texto           as batidas por hora por extenso
 *     medicoes[].angulo              o ângulo de levantamento (lift angle), em graus; null: não informado
 *     medicoes[].angulo_texto        o ângulo por extenso
 *     medicoes[].origem              manual (digitada) ou csv (importada do arquivo do aparelho)
 *     medicoes[].origem_texto        a origem por extenso
 *     medicoes[].observacao          a observação; null: nenhuma
 *     csv                            o que a importação do CSV entende
 *     csv.colunas                    as colunas: o campo e os nomes do cabeçalho que valem para ele
 *     csv.colunas[]                  uma coluna
 *     csv.colunas[].campo            o campo (quando, data, hora, posicao, marcha, amplitude, erro_batida, bph, angulo, observacao)
 *     csv.colunas[].nomes            os nomes que valem (sem acento, em minúsculas)
 *     csv.colunas[].nomes[]          um nome
 *     csv.posicoes                   as posições: o código e os nomes que valem
 *     csv.posicoes[]                 uma posição
 *     csv.posicoes[].posicao         o código
 *     csv.posicoes[].nomes           os nomes que valem
 *     csv.posicoes[].nomes[]         um nome
 *   recurso=relatorios
 *     relogios[].posse_comportamento o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
 *     relatorios                     o catálogo (sem tipo): cada relatório, na ordem do menu
 *     relatorios[].tipo              o código do relatório (o tipo= do pedido)
 *     relatorios[].grupo             o grupo dele no menu (Coleção, Uso e rodízio, Manutenção e precisão, Sistema)
 *     relatorios[].nome              o nome
 *     relatorios[].descricao         o que ele traz
 *     relatorios[].parametros        os parâmetros que ele aceita, na ordem do formulário
 *     relatorios[].parametros[].nome o nome do parâmetro no pedido (relogio, de, ate, posse, situacao)
 *     relatorios[].parametros[].rotulo
 *                                    o rótulo do campo
 *     relatorios[].parametros[].tipo o tipo do campo: relogio (a lista de relogios), data (AAAA-MM-DD) ou opcoes
 *     relatorios[].parametros[].obrigatorio
 *                                    se precisa vir (true ou false)
 *     relatorios[].parametros[].padrao
 *                                    o valor quando não vem (uma data, uma opção); null: nenhum
 *     relatorios[].parametros[].opcoes
 *                                    as opções (no tipo opcoes); null nos outros
 *     relatorios[].parametros[].opcoes[].valor
 *                                    o valor da opção
 *     relatorios[].parametros[].opcoes[].nome
 *                                    o nome dela
 *     relatorios[].parametros[].vazio
 *                                    no relógio que não é obrigatório: o que vale sem escolher (todos); null nos outros
 *     relogios                       os relógios para o parâmetro relogio (todos, também os de fora da coleção)
 *     relogios[].id                  o número
 *     relogios[].nome                o nome
 *     relogios[].posse               a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
 *     tipo                           o relatório pedido
 *     grupo                          o grupo dele
 *     titulo                         o título (o nome do relatório)
 *     subtitulo                      o que foi pedido, em texto (o relógio, o período); null: nada a dizer
 *     parametros                     os parâmetros que valeram (os que vieram e os padrões)
 *     parametros.<parametro>         o valor de um parâmetro (relogio, de, ate, posse, situacao)
 *     foto                           o endereço da foto do relógio (no passaporte, quando há foto); null: nenhuma
 *     resumo                         o resumo do alto: pares de nome e valor (os sem valor ficam de fora)
 *     resumo[].nome                  o nome
 *     resumo[].valor                 o valor, em texto
 *     secoes                         as seções do relatório, na ordem
 *     secoes[].titulo                o título da seção
 *     secoes[].tipo                  tabela ou pares
 *     secoes[].nota                  uma nota embaixo da seção (os N primeiros de M, por exemplo); null: nenhuma
 *     secoes[].colunas               na tabela: as colunas
 *     secoes[].colunas[].nome        o nome da coluna
 *     secoes[].colunas[].numero      true na coluna de números (alinhada à direita)
 *     secoes[].colunas[].registro    true na coluna de um registro gravado como saiu, no idioma de quando foi gravado (o que o cron fez): a
 *                                    tela mostra como texto pré-formatado
 *     secoes[].linhas                na tabela: as linhas, cada uma a lista das células, na ordem das colunas
 *     secoes[].linhas[][]            uma célula, em texto (— quando não há valor)
 *     secoes[].total                 na tabela: a linha do total, na ordem das colunas; null: sem total
 *     secoes[].total[]               uma célula do total
 *     secoes[].vazio                 o texto de quando a seção não tem nada
 *     secoes[].pares                 nos pares: os pares de nome e valor
 *     secoes[].pares[].nome          o nome
 *     secoes[].pares[].valor         o valor, em texto
 *     gerado_em                      quando o relatório foi gerado (AAAA-MM-DD HH:MM:SS)
 *     rodape                         a linha do rodapé (Gerado em ... pelo Relojoeiro.)
 *     csv                            o endereço do mesmo relatório numa planilha (csv=1)
 *     erro                           o motivo, quando o relatório não sai (o tipo desconhecido, o relógio que falta ou não existe, a data
 *                                    errada)
 *     tipos[]                        com o tipo desconhecido: os tipos que existem
 *   recurso=reserva
 *     reserva.protocolos             os protocolos do teste, na ordem
 *     reserva.protocolos[]           um protocolo
 *     reserva.protocolos[].valor     o identificador de um jeito de carregar da lista Jeitos de carregar (Cadastros › Listas: corda,
 *                                    winder, pulso, outro...)
 *     reserva.protocolos[].nome      o nome por extenso
 *     reserva.protocolos[].id        o número do item (o id da gravação, recurso=listas)
 *     reserva.protocolos[].ordem     a ordem na lista
 *     reserva.protocolos[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     reserva.protocolos[].texto     o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     reserva.protocolos[].cor       a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     reserva.tipos_observacao       os tipos das observações, na ordem
 *     reserva.tipos_observacao[]     um tipo
 *     reserva.tipos_observacao[].valor
 *                                    o identificador de uma observação da lista Observações da reserva (Cadastros › Listas: parou,
 *                                    carga_baixa, nota...)
 *     reserva.tipos_observacao[].nome
 *                                    o nome por extenso
 *     reserva.tipos_observacao[].id  o número do item (o id da gravação, recurso=listas)
 *     reserva.tipos_observacao[].ordem
 *                                    a ordem na lista
 *     reserva.tipos_observacao[].comportamento
 *                                    o que o item faz, nas listas que têm (o código só conhece estes: a lista diz quais em recurso=listas);
 *                                    null: a lista não tem
 *     reserva.tipos_observacao[].texto
 *                                    o texto do item, nas listas que têm (o tipo de lançamento que o tipo de serviço marca, a unidade do
 *                                    jeito de carregar, o aviso da situação da manutenção); null: sem
 *     reserva.tipos_observacao[].cor a cor do item (#rrggbb), nas listas que têm (as situações da manutenção); null: sem
 *     relogio                        o relógio pedido; null: todos
 *     relogio.id                     o número
 *     relogio.nome                   o nome
 *     reserva                        com o relógio: a reserva medida dele (como na ficha); null sem o relógio, ou se ele não tem reserva no
 *                                    cadastro
 *     reserva.testar                 true se o relógio faz o teste da reserva de marcha (o campo reserva_horas vale para ele: o mecânico)
 *     reserva.observar               true se registra as observações da energia (o mecânico e o solar, com o campo reserva_dias)
 *     reserva.aberto                 o teste em andamento (como em testes[]); null: nenhum
 *     reserva.aberto.id              o número do teste ou da observação (o id da gravação)
 *     reserva.aberto.relogio_id      o relógio
 *     reserva.aberto.relogio         o nome do relógio
 *     reserva.aberto.tipo            teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     reserva.aberto.tipo_texto      o tipo por extenso
 *     reserva.aberto.inicio          no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     reserva.aberto.fim             no teste, quando ele parou; null: em andamento (e nas observações)
 *     reserva.aberto.em_andamento    true no teste ainda sem o fim
 *     reserva.aberto.horas           a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     reserva.aberto.horas_texto     as horas prontas ("41,5 h"); null
 *     reserva.aberto.ate_agora       no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     reserva.aberto.protocolo       como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     reserva.aberto.protocolo_valor as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     reserva.aberto.protocolo_texto como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     reserva.aberto.observacao      a observação; null: sem
 *     reserva.aberto.criado          quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     reserva.testes                 os testes da reserva, do mais novo para o mais velho
 *     reserva.testes[]               um registro
 *     reserva.testes[].id            o número do teste ou da observação (o id da gravação)
 *     reserva.testes[].relogio_id    o relógio
 *     reserva.testes[].relogio       o nome do relógio
 *     reserva.testes[].tipo          teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     reserva.testes[].tipo_texto    o tipo por extenso
 *     reserva.testes[].inicio        no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     reserva.testes[].fim           no teste, quando ele parou; null: em andamento (e nas observações)
 *     reserva.testes[].em_andamento  true no teste ainda sem o fim
 *     reserva.testes[].horas         a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     reserva.testes[].horas_texto   as horas prontas ("41,5 h"); null
 *     reserva.testes[].ate_agora     no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     reserva.testes[].protocolo     como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     reserva.testes[].protocolo_valor
 *                                    as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     reserva.testes[].protocolo_texto
 *                                    como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     reserva.testes[].observacao    a observação; null: sem
 *     reserva.testes[].criado        quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     reserva.observacoes            as observações da energia (parou, carga baixa, outra), da mais nova para a mais velha
 *     reserva.observacoes[]          um registro
 *     reserva.observacoes[].id       o número do teste ou da observação (o id da gravação)
 *     reserva.observacoes[].relogio_id
 *                                    o relógio
 *     reserva.observacoes[].relogio  o nome do relógio
 *     reserva.observacoes[].tipo     teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     reserva.observacoes[].tipo_texto
 *                                    o tipo por extenso
 *     reserva.observacoes[].inicio   no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     reserva.observacoes[].fim      no teste, quando ele parou; null: em andamento (e nas observações)
 *     reserva.observacoes[].em_andamento
 *                                    true no teste ainda sem o fim
 *     reserva.observacoes[].horas    a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     reserva.observacoes[].horas_texto
 *                                    as horas prontas ("41,5 h"); null
 *     reserva.observacoes[].ate_agora
 *                                    no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     reserva.observacoes[].protocolo
 *                                    como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     reserva.observacoes[].protocolo_valor
 *                                    as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     reserva.observacoes[].protocolo_texto
 *                                    como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     reserva.observacoes[].observacao
 *                                    a observação; null: sem
 *     reserva.observacoes[].criado   quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     reserva.medida                 a reserva medida: a mediana dos testes terminados; null: nenhum
 *     reserva.medida.testes          quantos testes terminados
 *     reserva.medida.horas           a mediana das horas de cheio até parado (resiste a um teste errado)
 *     reserva.medida.horas_texto     a mediana pronta
 *     reserva.medida.variacao        quanto os testes variam: um desvio padrão, em horas; null com um só
 *     reserva.medida.ultimo          quando o último teste terminou (texto AAAA-MM-DD HH:MM:SS)
 *     reserva.medida.ultimo_horas    as horas do último teste
 *     reserva.cadastro_relogio       a reserva de marcha no cadastro do próprio relógio, em horas (a que vale na conta, acima do calibre e
 *                                    do grupo); null: ele não tem
 *     reserva.usar_horas             o que "Usar a medida" (escrita reserva, acao=usar) gravaria no cadastro dele: a mediana em horas
 *                                    inteiras; null: sem medida, ou o cadastro já tem esse valor
 *     reserva.protocolos[].unidade   o que o número diz (voltas, h); vazio: nada
 *     tecnico                        com o relógio: declarado, medido e estimado (como na ficha); null sem o relógio
 *     tecnico[]                      uma informação
 *     tecnico[].chave                marcha, reserva (de marcha, o mecânico), reserva_energia (o solar: só observações, sem calibrar) ou
 *                                    estanqueidade
 *     tecnico[].nome                 o nome por extenso
 *     tecnico[].unidade              a unidade (s/dia, h, dias, m)
 *     tecnico[].declarado            o declarado: o que o fabricante, o calibre ou o cadastro diz (na reserva, sem o valor do próprio
 *                                    relógio, que vai no detalhe)
 *     tecnico[].declarado.valor      o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     tecnico[].declarado.texto      o valor pronto; null: não há
 *     tecnico[].declarado.detalhe    de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     tecnico[].declarado.origem     de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     tecnico[].declarado.origem_texto
 *                                    a origem por extenso; null
 *     tecnico[].declarado.data       a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     tecnico[].medido               o último teste: a última marcha medida, o último teste da reserva, a última observação da energia, o
 *                                    último teste de estanqueidade
 *     tecnico[].medido.valor         o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     tecnico[].medido.texto         o valor pronto; null: não há
 *     tecnico[].medido.detalhe       de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     tecnico[].medido.origem        de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     tecnico[].medido.origem_texto  a origem por extenso; null
 *     tecnico[].medido.data          a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     tecnico[].estimado             a estimativa de agora: a média pesada das marchas, a mediana dos testes da reserva (sem teste: o
 *                                    cadastro), a vedação pelo último teste; no solar, não calculada
 *     tecnico[].estimado.valor       o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     tecnico[].estimado.texto       o valor pronto; null: não há
 *     tecnico[].estimado.detalhe     de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     tecnico[].estimado.origem      de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     tecnico[].estimado.origem_texto
 *                                    a origem por extenso; null
 *     tecnico[].estimado.data        a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     tecnico[].confianca            a confiança, pela regra única (quantas medições, a idade da última, quanto variam); null: nenhuma
 *                                    medição
 *     tecnico[].confianca.nivel      alta, media ou baixa
 *     tecnico[].confianca.nivel_texto
 *                                    o nível por extenso
 *     tecnico[].confianca.medicoes   quantas medições (ou testes)
 *     tecnico[].confianca.idade_dias há quantos dias foi a última
 *     tecnico[].confianca.variacao   quanto elas variam: um desvio padrão, na unidade; null com uma só ou quando não se aplica
 *     tecnico[].confianca.tolerancia até quanto a variação ainda dá confiança alta (a marcha: a metade da faixa esperada, senão 5 s/dia; a
 *                                    reserva: 10% da medida, no mínimo 1 h); null: não se aplica
 *     tecnico[].confianca.texto      o resumo das medições ("3 testes, o último há 12 dias, variação de ±1,1 h")
 *     tecnico[].confianca.motivos    por que não é alta (menos medições que o mínimo, a última velha, variação grande); vazia na alta
 *     tecnico[].confianca.motivos[]  um motivo, por extenso
 *     tecnico[].confianca.resumo     a frase inteira, pronta para a tela
 *     tecnico[].fora                 true se o último teste ficou fora do esperado (a marcha fora da faixa, a estanqueidade reprovada)
 *     registros                      sem o relógio: os testes e as observações de todos os relógios, do mais novo para o mais velho
 *     registros[]                    um registro
 *     registros[].id                 o número do teste ou da observação (o id da gravação)
 *     registros[].relogio_id         o relógio
 *     registros[].relogio            o nome do relógio
 *     registros[].tipo               teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     registros[].tipo_texto         o tipo por extenso
 *     registros[].inicio             no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     registros[].fim                no teste, quando ele parou; null: em andamento (e nas observações)
 *     registros[].em_andamento       true no teste ainda sem o fim
 *     registros[].horas              a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     registros[].horas_texto        as horas prontas ("41,5 h"); null
 *     registros[].ate_agora          no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     registros[].protocolo          como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     registros[].protocolo_valor    as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     registros[].protocolo_texto    como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     registros[].observacao         a observação; null: sem
 *     registros[].criado             quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *   recurso=passaporte
 *     relogio.posse_comportamento    o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
 *     gerado_em                      quando foi gerado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     relogio                        quem ele é
 *     relogio.id                     o número (código)
 *     relogio.nome                   o nome
 *     relogio.caminho                o caminho do grupo ("todos os relógios" na raiz)
 *     relogio.tipo                   o grupo (o nome; "sem grupo" na raiz)
 *     relogio.posse                  a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
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
 *     cadastro[].secao               o nome da divisória da ficha do campo; null: nenhuma (o cadastro vem na ordem da ficha)
 *     cadastro_secoes                o mesmo cadastro agrupado por divisória, na ordem delas; o grupo sem divisória vem por último
 *     cadastro_secoes[]              uma divisória com os campos preenchidos dela
 *     cadastro_secoes[].secao        o nome da divisória; null: os campos sem divisória
 *     cadastro_secoes[].campos       os campos dela, na ordem
 *     cadastro_secoes[].campos[]     um campo
 *     cadastro_secoes[].campos[].identificador
 *                                    o identificador do campo
 *     cadastro_secoes[].campos[].nome
 *                                    o nome do campo
 *     cadastro_secoes[].campos[].valor
 *                                    o valor em texto: data DD/MM/AAAA, sim ou não, número com vírgula e a unidade (R$ 1.200,50; 40 h)
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
 *     acessorios[].valores           os campos do acessório preenchidos (Cadastros › Campos, para acessório: os do tipo dele), na ordem
 *     acessorios[].valores[]         um campo do acessório
 *     acessorios[].valores[].identificador
 *                                    o identificador do campo
 *     acessorios[].valores[].nome    o nome do campo
 *     acessorios[].valores[].valor   o valor gravado (texto normalizado: número com ponto; o múltiplo, um por linha)
 *     acessorios[].valores[].texto   o valor para mostrar, no idioma, com a unidade ("20 mm (0,79 pol)"; o múltiplo separado por vírgula)
 *     acessorios[].serve_por         o que decide em que relógio ele serve: cada campo do relógio com que um campo dele bate, com o valor
 *                                    dele ("Entre alças (largura da pulseira): 20 mm"); null: ele não preencheu nenhum desses campos
 *     acessorios[].serve_por[]       um campo do relógio e o valor que ele tem de ter
 *     acessorios[].serve_em          os relógios em que ele serve (os da coleção, emprestados e desejados cujo valor bate com o dele em
 *                                    todos esses campos); null: não dá para saber (ele não preencheu nenhum desses campos)
 *     acessorios[].serve_em[]        um relógio em que ele serve
 *     acessorios[].serve_em[].relogio_id
 *                                    o número do relógio
 *     acessorios[].serve_em[].relogio
 *                                    o nome do relógio
 *     servicos_diagnosticos          os serviços e diagnósticos (como em recurso=servicos), do mais novo para o mais velho
 *     servicos_diagnosticos[]        um serviço
 *     servicos_diagnosticos[].id     o número do serviço (o id da gravação)
 *     servicos_diagnosticos[].relogio_id
 *                                    o relógio do serviço
 *     servicos_diagnosticos[].relogio
 *                                    o nome do relógio
 *     servicos_diagnosticos[].data   a data do serviço (AAAA-MM-DD)
 *     servicos_diagnosticos[].ha_dias
 *                                    há quantos dias foi (no dia da coleção)
 *     servicos_diagnosticos[].tipo   o tipo: o identificador de um item da lista Tipos de serviço (Manutenção › Listas: revisao, pilha,
 *                                    estanqueidade, regulagem, polimento...)
 *     servicos_diagnosticos[].tipo_texto
 *                                    o tipo por extenso
 *     servicos_diagnosticos[].oficina
 *                                    a oficina do serviço (a da manutenção de que ele é o encerramento: Manutenção › Oficinas)
 *     servicos_diagnosticos[].descricao
 *                                    o que foi feito; null: não informado
 *     servicos_diagnosticos[].mao_de_obra
 *                                    a mão de obra, em R$; null: não informada
 *     servicos_diagnosticos[].pecas  as peças trocadas, na ordem
 *     servicos_diagnosticos[].pecas[]
 *                                    uma peça
 *     servicos_diagnosticos[].pecas[].nome
 *                                    o nome da peça
 *     servicos_diagnosticos[].pecas[].custo
 *                                    o custo da peça, em R$; null: não informado
 *     servicos_diagnosticos[].custo_pecas
 *                                    a soma do custo das peças, em R$ (0 sem nenhuma com custo)
 *     servicos_diagnosticos[].custo  o custo do serviço: a mão de obra mais as peças, em R$; null: nada com custo
 *     servicos_diagnosticos[].estanqueidade
 *                                    o resultado do teste de estanqueidade: o identificador de um item da lista Resultados do teste de
 *                                    estanqueidade (Manutenção › Listas: aprovado, reprovado...); null: não testada
 *     servicos_diagnosticos[].estanqueidade_texto
 *                                    o resultado por extenso (o nome do item, em minúsculas); null: não testada
 *     servicos_diagnosticos[].estanqueidade_aprova
 *                                    o resultado aprova a vedação (o item do resultado faz aprova)? true ou false; null: não testada
 *     servicos_diagnosticos[].manutencao_id
 *                                    a manutenção de que o serviço é o encerramento (recurso=manutencao)
 *     servicos_diagnosticos[].oficina_id
 *                                    o número da oficina
 *     servicos_diagnosticos[].estanqueidade_bar
 *                                    a pressão do teste, em bar; null: não informada
 *     servicos_diagnosticos[].marcha_antes
 *                                    a marcha antes do serviço, informada pelo profissional, em s/dia (+ adianta); null: não informada
 *     servicos_diagnosticos[].marcha_depois
 *                                    a marcha depois do serviço, informada pelo profissional, em s/dia; null: não informada
 *     servicos_diagnosticos[].marcha_antes_texto
 *                                    a marcha antes, pronta ("+25,0 s/dia"); null
 *     servicos_diagnosticos[].marcha_depois_texto
 *                                    a marcha depois, pronta; null
 *     servicos_diagnosticos[].medida_antes
 *                                    a marcha medida pelo sistema antes do serviço: os intervalos do desvio da hora que terminam nos 60
 *                                    dias antes da data; null: nenhum
 *     servicos_diagnosticos[].medida_antes.marcha
 *                                    a marcha, em s/dia, pesada pelo tempo de cada intervalo
 *     servicos_diagnosticos[].medida_antes.marcha_texto
 *                                    a marcha pronta
 *     servicos_diagnosticos[].medida_antes.intervalos
 *                                    quantos intervalos entraram
 *     servicos_diagnosticos[].medida_depois
 *                                    a marcha medida pelo sistema depois do serviço: os intervalos que começam nos 60 dias depois do dia do
 *                                    serviço; null: nenhum
 *     servicos_diagnosticos[].medida_depois.marcha
 *                                    a marcha, em s/dia
 *     servicos_diagnosticos[].medida_depois.marcha_texto
 *                                    a marcha pronta
 *     servicos_diagnosticos[].medida_depois.intervalos
 *                                    quantos intervalos entraram
 *     servicos_diagnosticos[].garantia_ate
 *                                    a garantia do serviço até (AAAA-MM-DD); null: sem
 *     servicos_diagnosticos[].garantia_vale
 *                                    true se a garantia ainda vale hoje
 *     servicos_diagnosticos[].observacao
 *                                    a observação do profissional; null: sem
 *     servicos_diagnosticos[].documentos
 *                                    os documentos do relógio que são do serviço (a nota, o orçamento, as fotos), pelo título
 *     servicos_diagnosticos[].documentos[]
 *                                    um documento
 *     servicos_diagnosticos[].documentos[].id
 *                                    o número do documento
 *     servicos_diagnosticos[].documentos[].titulo
 *                                    o título
 *     servicos_diagnosticos[].documentos[].url
 *                                    o endereço do arquivo (recurso=documento)
 *     servicos_diagnosticos[].marcacao
 *                                    true se o serviço virou também a marcação do tipo de lançamento que o tipo de serviço marca (o texto
 *                                    do item: revisao, pilha)
 *     servicos_diagnosticos[].criado quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     tecnico                        declarado, medido e estimado (como na ficha)
 *     tecnico[]                      uma informação
 *     tecnico[].chave                marcha, reserva (de marcha, o mecânico), reserva_energia (o solar: só observações, sem calibrar) ou
 *                                    estanqueidade
 *     tecnico[].nome                 o nome por extenso
 *     tecnico[].unidade              a unidade (s/dia, h, dias, m)
 *     tecnico[].declarado            o declarado: o que o fabricante, o calibre ou o cadastro diz (na reserva, sem o valor do próprio
 *                                    relógio, que vai no detalhe)
 *     tecnico[].declarado.valor      o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     tecnico[].declarado.texto      o valor pronto; null: não há
 *     tecnico[].declarado.detalhe    de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     tecnico[].declarado.origem     de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     tecnico[].declarado.origem_texto
 *                                    a origem por extenso; null
 *     tecnico[].declarado.data       a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     tecnico[].medido               o último teste: a última marcha medida, o último teste da reserva, a última observação da energia, o
 *                                    último teste de estanqueidade
 *     tecnico[].medido.valor         o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     tecnico[].medido.texto         o valor pronto; null: não há
 *     tecnico[].medido.detalhe       de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     tecnico[].medido.origem        de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     tecnico[].medido.origem_texto  a origem por extenso; null
 *     tecnico[].medido.data          a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     tecnico[].estimado             a estimativa de agora: a média pesada das marchas, a mediana dos testes da reserva (sem teste: o
 *                                    cadastro), a vedação pelo último teste; no solar, não calculada
 *     tecnico[].estimado.valor       o número, na unidade da informação; null: não há (ou é uma faixa, como a marcha esperada)
 *     tecnico[].estimado.texto       o valor pronto; null: não há
 *     tecnico[].estimado.detalhe     de onde vem, ou por que não há ("a mediana de 3 testes", "nenhum teste ainda"); null
 *     tecnico[].estimado.origem      de onde vem o declarado: informado (o cadastro do relógio), calibre ou padrao (o do grupo); null nas
 *                                    outras linhas
 *     tecnico[].estimado.origem_texto
 *                                    a origem por extenso; null
 *     tecnico[].estimado.data        a data do último teste (texto AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS); null
 *     tecnico[].confianca            a confiança, pela regra única (quantas medições, a idade da última, quanto variam); null: nenhuma
 *                                    medição
 *     tecnico[].confianca.nivel      alta, media ou baixa
 *     tecnico[].confianca.nivel_texto
 *                                    o nível por extenso
 *     tecnico[].confianca.medicoes   quantas medições (ou testes)
 *     tecnico[].confianca.idade_dias há quantos dias foi a última
 *     tecnico[].confianca.variacao   quanto elas variam: um desvio padrão, na unidade; null com uma só ou quando não se aplica
 *     tecnico[].confianca.tolerancia até quanto a variação ainda dá confiança alta (a marcha: a metade da faixa esperada, senão 5 s/dia; a
 *                                    reserva: 10% da medida, no mínimo 1 h); null: não se aplica
 *     tecnico[].confianca.texto      o resumo das medições ("3 testes, o último há 12 dias, variação de ±1,1 h")
 *     tecnico[].confianca.motivos    por que não é alta (menos medições que o mínimo, a última velha, variação grande); vazia na alta
 *     tecnico[].confianca.motivos[]  um motivo, por extenso
 *     tecnico[].confianca.resumo     a frase inteira, pronta para a tela
 *     tecnico[].fora                 true se o último teste ficou fora do esperado (a marcha fora da faixa, a estanqueidade reprovada)
 *     timegrapher                    as medições do timegrapher, da mais nova para a mais velha (como em recurso=timegrapher)
 *     timegrapher[]                  uma medição
 *     timegrapher[].id               o número da medição (o id da gravação)
 *     timegrapher[].relogio_id       o relógio
 *     timegrapher[].relogio          o nome do relógio
 *     timegrapher[].quando           quando foi medida (texto AAAA-MM-DD HH:MM:SS, no fuso do pedido)
 *     timegrapher[].posicao          a posição no aparelho: mostrador_cima, mostrador_baixo, coroa_cima, coroa_baixo, coroa_esquerda ou
 *                                    coroa_direita; null: não informada
 *     timegrapher[].posicao_texto    a posição por extenso
 *     timegrapher[].marcha           a marcha, em segundos por dia (+ adianta, − atrasa); null: não medida
 *     timegrapher[].marcha_texto     a marcha por extenso ("+4,5 s/dia")
 *     timegrapher[].amplitude        a amplitude do balanço, em graus; null: não medida
 *     timegrapher[].amplitude_texto  a amplitude por extenso ("290°")
 *     timegrapher[].erro_batida      o erro de batida, em milissegundos; null: não medido
 *     timegrapher[].erro_batida_texto
 *                                    o erro de batida por extenso ("0,3 ms")
 *     timegrapher[].bph              as batidas por hora (21600, 28800...); null: não informadas
 *     timegrapher[].bph_texto        as batidas por hora por extenso
 *     timegrapher[].angulo           o ângulo de levantamento (lift angle), em graus; null: não informado
 *     timegrapher[].angulo_texto     o ângulo por extenso
 *     timegrapher[].origem           manual (digitada) ou csv (importada do arquivo do aparelho)
 *     timegrapher[].origem_texto     a origem por extenso
 *     timegrapher[].observacao       a observação; null: nenhuma
 *     reserva_testes                 os testes da reserva (como em recurso=reserva)
 *     reserva_testes[]               um registro
 *     reserva_testes[].id            o número do teste ou da observação (o id da gravação)
 *     reserva_testes[].relogio_id    o relógio
 *     reserva_testes[].relogio       o nome do relógio
 *     reserva_testes[].tipo          teste (o teste da reserva) ou o identificador de uma observação da lista Observações da reserva
 *                                    (Cadastros › Listas: parou, carga_baixa, nota...)
 *     reserva_testes[].tipo_texto    o tipo por extenso
 *     reserva_testes[].inicio        no teste, quando ele ficou cheio; na observação, quando foi (texto AAAA-MM-DD HH:MM:SS, no fuso do
 *                                    pedido)
 *     reserva_testes[].fim           no teste, quando ele parou; null: em andamento (e nas observações)
 *     reserva_testes[].em_andamento  true no teste ainda sem o fim
 *     reserva_testes[].horas         a reserva medida no teste: as horas de cheio até parado; null em andamento e nas observações
 *     reserva_testes[].horas_texto   as horas prontas ("41,5 h"); null
 *     reserva_testes[].ate_agora     no teste em andamento, as horas desde que ficou cheio até agora; null nos outros
 *     reserva_testes[].protocolo     como ele ficou cheio: corda (pela coroa), winder, pulso ou outro; null nas observações
 *     reserva_testes[].protocolo_valor
 *                                    as voltas da coroa, ou as horas no winder ou no pulso; null: não informado
 *     reserva_testes[].protocolo_texto
 *                                    como ficou cheio, por extenso ("cheio com 40 voltas da coroa"); null nas observações
 *     reserva_testes[].observacao    a observação; null: sem
 *     reserva_testes[].criado        quando foi registrado (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
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
 *     grupos[].relogios[].posse_comportamento
 *                                    o que a situação faz (o comportamento do item): colecao (entra no rodízio), emprestado (da coleção,
 *                                    fora do rodízio), desejado (a lista de desejos) ou fora (fora da coleção)
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
 *     grupos[].relogios[].posse      a situação na coleção: o identificador de um item da lista Situações do relógio na coleção (Cadastros
 *                                    › Listas: colecao, emprestado, desejado, vendido, arquivado...); o que ela faz está em
 *                                    posse_comportamento
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
 *   recurso=manifest
 *     id                             a identidade do aplicativo para o navegador (./: a pasta do sistema)
 *     name                           o nome do aplicativo (Relojoeiro)
 *     short_name                     o nome curto, embaixo do ícone
 *     description                    a descrição que a loja do navegador mostra ao instalar
 *     lang                           o idioma do pedido
 *     start_url                      a página que o aplicativo abre: index.php; com o cookie do aparelho lembrado,
 *                                    index.php?aparelho=<chave> (a chave vira o cookie do aplicativo e a página abre limpa)
 *     scope                          o que conta como o aplicativo (./: a pasta do sistema)
 *     display                        standalone: tela cheia, sem a barra do navegador
 *     orientation                    any: a orientação do aparelho
 *     background_color               a cor de fundo enquanto abre (a do corpo das páginas)
 *     theme_color                    a cor da barra do sistema (a do topo das páginas)
 *     icons[]                        os ícones
 *     icons[].src                    o arquivo (icone-192.png, icone-512.png)
 *     icons[].sizes                  o tamanho (192x192, 512x512)
 *     icons[].type                   image/png
 *     icons[].purpose                maskable: o ícone que o Android recorta na forma dele (o desenho fica no meio); sem ele, o ícone comum
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
 *   recurso=roteiro
 *     dia                            o dia testado (AAAA-MM-DD)
 *     dia_semana                     o dia da semana dele: 1 segunda ... 7 domingo
 *     linhas[]                       as linhas do roteiro, na ordem em que foram escritas
 *     linhas[].nome                  o nome da linha
 *     linhas[].expressao             a expressão, como foi escrita
 *     erros[]                        os erros do roteiro (escrita, nome repetido, variável ou tipo de lançamento desconhecido, uma linha
 *                                    que depende dela mesma); vazio: nenhum
 *     relogios[]                     os relógios disponíveis, na ordem do cadastro, cada um com o roteiro calculado para o dia
 *     relogios[].id                  o número do relógio
 *     relogios[].nome                o nome
 *     relogios[].candidato           verdadeiro: entra na escolha do dia
 *     relogios[].nota                a nota nesse dia (null: vazia)
 *     relogios[].peso                o peso (a chance) nesse dia (null: vazio)
 *     relogios[].dias                quantos dias seguidos ficaria (1 a 366)
 *     relogios[].garantia            verdadeiro: passa na frente de todos
 *     relogios[].dias_sem_uso        os dias sem uso dele no começo do dia (o desempate da garantia)
 *     relogios[].valores             o valor de cada linha do roteiro e das cinco saídas nesse relógio
 *     relogios[].valores.<nome>      o valor da linha (número, texto ou null)
 *     escolhido                      quem sairia nesse dia pela escolha do rodízio; null: ninguém é candidato
 *     escolhido.relogio_id           o número do relógio
 *     escolhido.relogio              o nome
 *     escolhido.motivo               o motivo, como ficaria no plano
 *     escolhido.dias                 quantos dias seguidos ficaria
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
 *                                    relógio escolhido à mão, livre, ou auto (Decida por mim: o dia sorteado ou ainda sem relógio; um dia
 *                                    sorteado à mão por uma forma também volta como auto)
 *     relogios[]                     os relógios disponíveis, para o seletor de cada dia (por nome)
 *     relogios[].id                  o número do relógio
 *     relogios[].nome                o nome
 *     decida_por_mim                 o critério do Decida por mim, em texto: a nota dos critérios recalculada dia a dia com os dias
 *                                    anteriores do período contados como uso, a garantia de rodízio na frente, sem o relógio de ontem nem o
 *                                    de amanhã (se houver outro), empate pelo cadastrado primeiro; decidido ao gravar; e o Sortear este dia
 *                                    por
 *     trocar_hoje                    verdadeiro: o relógio de hoje se troca no próprio dia (a Configuração, trocar_hoje); falso, o padrão:
 *                                    só a partir de amanhã, e as telas não mostram as trocas de hoje (o trocar por… do dia de hoje, o
 *                                    Usando hoje, o inclusive hoje, o Sem relógio hoje, o Excluindo hoje do Apagar tudo; no Rodízio manual,
 *                                    hoje fica parado)
 *     formas[]                       as formas de escolha do "Sortear este dia por" do seletor de cada dia
 *                                    (dias[AAAA-MM-DD]=sortear:<chave> no acao=manual; o mesmo relogio_id=sortear:<chave> no
 *                                    acao=trocar_dia)
 *     formas[].chave                 a chave: inteligente (a maior nota), ponderado (sorteio pela nota), aleatorio (todos com a mesma
 *                                    chance) ou fifo (o mais tempo sem uso)
 *     formas[].nome                  o nome, no idioma do pedido
 *   recurso=eventos
 *     eventos[]                      os eventos personalizados (avisos seus, com horário e repetição próprios)
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
 *     eventos[].destinos[]           os nomes dos destinos que recebem o evento
 *     eventos[].agendas[]            os nomes das agendas que recebem o evento
 *   recurso=destinos
 *     destinos[]                     os destinos das mensagens da coleção (webhook, e-mail, Web Push)
 *     destinos[].id                  o id do destino
 *     destinos[].nome                o nome (Telegram, E-mail da família...)
 *     destinos[].tipo                webhook, email ou webpush
 *     destinos[].tipo_nome           o nome do tipo, por extenso
 *     destinos[].ativo               verdadeiro ou falso: manda (desligado, não sai nada por ele)
 *     destinos[].modelo              a mensagem padrão dele, com as âncoras ({acao}, {relogio}, {motivo}...): cada item vira uma linha por
 *                                    ela
 *     destinos[].idioma              o idioma das mensagens dele (pt-BR, en, zh-CN, hi, es ou fr); null: o da coleção
 *     destinos[].fuso                o fuso das horas no texto das mensagens dele ("Europe/Lisbon"); null: o da coleção
 *     fusos[]                        os fusos horários que o PHP conhece (a lista Fuso horário de cada destino)
 *     fusos[].nome                   o nome ("America/Sao_Paulo")
 *     fusos[].deslocamento           a diferença para o UTC agora ("-03:00")
 *     destinos[].recebe[]            o que ele recebe: cada tipo de aviso (o relógio do dia, a véspera, cada aviso do cadastro, cada evento
 *                                    personalizado)
 *     destinos[].recebe[].tipo       o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)
 *     destinos[].recebe[].nome       o nome do tipo
 *     destinos[].recebe[].quando     quando sai (manhã, noite, na hora...)
 *     destinos[].recebe[].envia      verdadeiro ou falso: recebe esse tipo
 *     destinos[].recebe[].modelo     o texto próprio desse tipo (com as âncoras); null: a mensagem padrão
 *     destinos[].webhook             o que é do webhook (null nos outros tipos)
 *     destinos[].webhook.metodo      GET, POST ou PUT
 *     destinos[].webhook.url         o endereço
 *     destinos[].webhook.formato     como a mensagem vai: query (na URL), form (formulário) ou json
 *     destinos[].webhook.corpo_json  o corpo JSON próprio, com {titulo} e {mensagem} (null: os campos viram o objeto)
 *     destinos[].webhook.campos[]    os campos do pedido
 *     destinos[].webhook.campos[].nome
 *                                    o nome do campo
 *     destinos[].webhook.campos[].valor
 *                                    o valor, com {titulo} e {mensagem}
 *     destinos[].webhook.cabecalhos[]
 *                                    os cabeçalhos do pedido (o valor fica cifrado no banco e nunca sai)
 *     destinos[].webhook.cabecalhos[].nome
 *                                    o nome do cabeçalho
 *     destinos[].webhook.cabecalhos[].preenchido
 *                                    verdadeiro ou falso: tem valor guardado
 *     destinos[].email               o que é do e-mail (null nos outros tipos)
 *     destinos[].email.para          para quem (um ou mais endereços, separados por vírgula)
 *     destinos[].webpush             o que é do Web Push (null nos outros tipos)
 *     destinos[].webpush.usuario_id  o usuário que recebe (nos aparelhos dele)
 *     destinos[].webpush.usuario     o login dele
 *     destinos[].webpush.aparelhos   quantos aparelhos dele estão inscritos
 *     destinos[].previa              o que sairia por ele hoje
 *     destinos[].previa.manha        a mensagem da manhã como sairia agora (texto; vazio: nada)
 *     destinos[].previa.noite        a mensagem da noite como sairia agora (texto; vazio: nada)
 *     tipos[]                        os tipos possíveis
 *     tipos[].tipo                   o tipo
 *     tipos[].nome                   o nome do tipo
 *     ancoras.<ancora>               cada âncora que o modelo aceita ({relogio}, {acao}...) e o que ela vira
 *     linguas[]                      os idiomas
 *     linguas[].codigo               o código (pt-BR, en...)
 *     linguas[].nome                 o nome do idioma
 *     avisos[]                       os tipos de aviso que um destino ou uma agenda pode receber
 *     avisos[].tipo                  o tipo (dia, vespera, o identificador do aviso, ev<id>)
 *     avisos[].nome                  o nome
 *     avisos[].quando                quando sai
 *     padrao.modelo                  a mensagem padrão do modelo, no idioma da coleção (a de um destino novo)
 *     email.configurado              verdadeiro ou falso: os e-mails desta coleção saem (pelo servidor de e-mail próprio do dono dela, em
 *                                    Minha conta, senão pelo do sistema: EMAIL_METODO ou SMTP_HOST no config.php)
 *     email.metodo                   smtp ou mail; null: não configurado
 *     email.proprio                  verdadeiro: saem pelo servidor de e-mail próprio do dono da coleção; falso: pelo do sistema
 *     email.remetente                de quem os e-mails vêm (o remetente do servidor que vale); null: não configurado
 *     push.configurado               verdadeiro ou falso: o servidor tem as chaves do Web Push (VAPID_PUBLICA e VAPID_PRIVADA)
 *     push.chave_publica             a chave pública VAPID (base64url); null: não configurado
 *     usuarios[]                     quem vê a coleção (o Web Push vai para os aparelhos de um deles)
 *     usuarios[].id                  o id
 *     usuarios[].login               o login
 *   recurso=agendas
 *     agendas[]                      as agendas da coleção (Google Agenda, CalDAV, Outlook, link ICS)
 *     agendas[].id                   o id da agenda
 *     agendas[].nome                 o nome
 *     agendas[].tipo                 google, caldav, outlook ou ics
 *     agendas[].tipo_nome            o nome do tipo, por extenso
 *     agendas[].ativa                verdadeiro ou falso: o sistema mantém os eventos nela
 *     agendas[].janela_dias          quantos dias à frente ficam na agenda
 *     agendas[].modelo               o modelo do evento: a primeira linha é o título, o resto a descrição, com as âncoras
 *     agendas[].idioma               o idioma dos eventos dela; null: o da coleção
 *     agendas[].fuso                 o fuso das horas no texto dos eventos dela ("Europe/Lisbon"); null: o da coleção (o instante do evento
 *                                    no calendário não depende disto)
 *     fusos[]                        os fusos horários que o PHP conhece (a lista Fuso horário de cada agenda)
 *     fusos[].nome                   o nome ("America/Sao_Paulo")
 *     fusos[].deslocamento           a diferença para o UTC agora ("-03:00")
 *     agendas[].recebe[]             o que ele recebe: cada tipo de aviso (o relógio do dia, a véspera, cada aviso do cadastro, cada evento
 *                                    personalizado)
 *     agendas[].recebe[].tipo        o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)
 *     agendas[].recebe[].nome        o nome do tipo
 *     agendas[].recebe[].quando      quando sai (manhã, noite, na hora...)
 *     agendas[].recebe[].envia       verdadeiro ou falso: recebe esse tipo
 *     agendas[].recebe[].modelo      o texto próprio desse tipo (com as âncoras); null: a mensagem padrão
 *     agendas[].sincronizados        quantos eventos o sistema criou nela (e acompanha)
 *     agendas[].teste_id             o id do evento de teste nela; null: nenhum
 *     agendas[].google               o que é do Google Agenda (null nos outros tipos)
 *     agendas[].google.calendario_id o id da agenda do Google
 *     agendas[].google.chave         a chave da conta de serviço: json (colada, cifrada no banco), caminho (um arquivo no servidor) ou
 *                                    nenhuma
 *     agendas[].google.caminho       o caminho do arquivo da chave (só quando a chave é um caminho)
 *     agendas[].google.conta_servico o e-mail da conta de serviço (compartilhe a agenda com ele, com permissão de alterar)
 *     agendas[].caldav               o que é do CalDAV (null nos outros tipos)
 *     agendas[].caldav.url           o endereço do calendário
 *     agendas[].caldav.usuario       o usuário
 *     agendas[].caldav.senha_preenchida
 *                                    verdadeiro ou falso: tem senha guardada (cifrada; nunca sai)
 *     agendas[].outlook              o que é do Outlook (null nos outros tipos)
 *     agendas[].outlook.conta        a conta Microsoft conectada; null: ainda não
 *     agendas[].outlook.calendario_id
 *                                    o id do calendário; null: o principal
 *     agendas[].outlook.conectada    verdadeiro ou falso: a conta Microsoft está conectada
 *     agendas[].outlook.configurado  verdadeiro ou falso: o servidor tem o aplicativo da Microsoft (OUTLOOK_CLIENTE_ID)
 *     agendas[].outlook.retorno      o endereço de retorno para registrar no aplicativo da Microsoft
 *     agendas[].ics                  o que é do link ICS (null nos outros tipos)
 *     agendas[].ics.link             o link para assinar no app de agenda (quem tem o link vê os eventos)
 *     agendas[].previa[]             os primeiros (até 12) eventos que têm de estar nela agora
 *     agendas[].previa[].chave       a identificação do evento na agenda (a mesma enquanto nada mudar; é o que o sistema usa para atualizar
 *                                    ou remover)
 *     agendas[].previa[].tipo        o tipo: dia, vespera, o identificador do aviso, ou ev<id> (evento personalizado)
 *     agendas[].previa[].relogio_id  o relógio do evento; null num evento geral
 *     agendas[].previa[].relogio     o nome do relógio; vazio num evento geral
 *     agendas[].previa[].data        o dia do evento na agenda (texto AAAA-MM-DD)
 *     agendas[].previa[].hora        a hora do evento na agenda (HH:MM)
 *     agendas[].previa[].fazer       a ação ("Dar corda", "Usar hoje", o nome do evento): a âncora {acao}
 *     agendas[].previa[].motivo      o porquê ("a reserva acaba em 3h, 28/09 10:53"): a âncora {motivo}
 *     agendas[].previa[].momento     o instante previsto do que motivou o evento (texto AAAA-MM-DD HH:MM:SS, no fuso do sistema)
 *     agendas[].previa[].titulo      o título do evento, montado pelo modelo da agenda (a primeira linha)
 *     agendas[].previa[].descricao   a descrição do evento, montada pelo modelo da agenda (o resto)
 *     agendas[].previstos            quantos eventos têm de estar nela agora
 *     tipos[]                        os tipos possíveis
 *     tipos[].tipo                   o tipo
 *     tipos[].nome                   o nome do tipo
 *     ancoras.<ancora>               cada âncora que o modelo aceita ({relogio}, {acao}...) e o que ela vira
 *     linguas[]                      os idiomas
 *     linguas[].codigo               o código (pt-BR, en...)
 *     linguas[].nome                 o nome do idioma
 *     avisos[]                       os tipos de aviso que um destino ou uma agenda pode receber
 *     avisos[].tipo                  o tipo (dia, vespera, o identificador do aviso, ev<id>)
 *     avisos[].nome                  o nome
 *     avisos[].quando                quando sai
 *     padrao.modelo                  o modelo padrão do evento, no idioma da coleção
 *     padrao.janela_dias             a janela padrão, em dias
 *     outlook.configurado            verdadeiro ou falso: o servidor tem o aplicativo da Microsoft (OUTLOOK_CLIENTE_ID e
 *                                    OUTLOOK_CLIENTE_SEGREDO)
 *     outlook.retorno                o endereço de retorno para registrar no aplicativo da Microsoft
 *     pasta                          a pasta do sistema no servidor, para o caminho da chave do Google (só o master; null para os outros)
 *   recurso=conta
 *     usuario                        quem pede (null: o token do servidor sem nenhum usuário)
 *     usuario.id                     o id: o seu código, que você passa a quem quiser pedir acesso a todas as suas coleções
 *     usuario.login                  o login
 *     usuario.email                  o e-mail; null: nenhum
 *     usuario.papel                  master ou comum
 *     usuario.idioma                 o idioma; null: o da coleção
 *     usuario.fuso                   o fuso horário da pessoa ("America/Sao_Paulo"; "navegador": o do aparelho); null: o da coleção, senão
 *                                    o do sistema
 *     usuario.nivel                  o modo da interface: basico (o dia a dia: as telas deixam de fora as páginas e as partes do avançado)
 *                                    ou avancado (tudo); o motor é o mesmo
 *     niveis[]                       os modos da interface, para a escolha em Minha conta
 *     niveis[].codigo                basico ou avancado
 *     niveis[].nome                  o nome no idioma do pedido
 *     nivel                          (parte=menu) o modo da interface de quem pede: basico ou avancado (sem conta, como o token do
 *                                    servidor: avancado)
 *     ocultos[]                      (parte=menu) o que as telas deixam de fora nesse modo (no avançado, nada): as páginas pelo arquivo
 *                                    (modos.php, criterios.php, totais.php, grupos.php, cadastros.php, execucoes.php), que somem do menu, e
 *                                    as partes pelo nome que a tela põe nelas (previsao.conta: o "De onde vem a conta"; gasto.medicoes e
 *                                    marcha.medicoes: as tabelas das medições; dados.calculado: o "Calculado agora"; rodizio.conta: o "ver
 *                                    a conta" da nota; config.gasto: os campos do motor do gasto na Configuração)
 *     usuario.criado                 quando a conta foi criada
 *     usuario.ultimo_acesso          o último acesso
 *     usuario.expira_em              quando a conta de demonstração vence; null: oficial
 *     usuario.demo                   verdadeiro ou falso: de demonstração
 *     usuario.dias_restantes         quantos dias faltam; null: oficial
 *     usuario.bloqueado              verdadeiro ou falso: bloqueada
 *     vendo_como                     o master vendo como outro usuário: {login (o usuário visto), master (quem está vendo)}; null: não
 *     vendo_como.login               o login do usuário visto
 *     vendo_como.master              o login do master
 *     papel                          o seu papel na coleção do pedido: dono, membro ou master
 *     colecoes[]                     as coleções que você vê: as suas primeiro, depois pelo nome
 *     colecoes[].id                  o id
 *     colecoes[].nome                o nome
 *     colecoes[].dono_id             o id do dono
 *     colecoes[].dono                o login do dono
 *     colecoes[].papel               o seu papel nela: dono, membro ou master
 *     colecoes[].no_id               a raiz da árvore de grupos que ela usa
 *     colecao                        o id da coleção do pedido; null: nenhuma
 *     arvores[]                      as suas árvores de grupos
 *     arvores[].id                   o id (a raiz da árvore)
 *     arvores[].nome                 o nome
 *     arvores[].grupos               quantos grupos tem (sem a raiz)
 *     arvores[].colecoes[]           as coleções que usam a árvore
 *     arvores[].colecoes[].id        o id
 *     arvores[].colecoes[].nome      o nome
 *     pedidos[]                      os seus pedidos de acesso esperando resposta
 *     pedidos[].id                   o id
 *     pedidos[].colecao_id           o código da coleção pedida; null: o pedido é de tudo de um dono
 *     pedidos[].dono_id              o código do dono (todas as coleções dele); null: o pedido é de uma coleção
 *     pedidos[].alvo                 o que foi pedido, só pelo código ("a coleção de código 7"): o nome e o dono só aparecem depois da
 *                                    aprovação
 *     pedidos[].criado               quando
 *     pedidos[].expira               até quando espera
 *     convites[]                     os convites para o seu e-mail (aceite pelo link do e-mail)
 *     convites[].id                  o id
 *     convites[].alvo                o que o convite dá
 *     convites[].expira              até quando vale
 *     aprovacoes                     quantos pedidos e convites esperam a sua resposta (página Aprovações)
 *     tokens[]                       os seus tokens de API (o token mesmo só aparece ao criar)
 *     tokens[].id                    o id
 *     tokens[].nome                  o nome (onde ele é usado)
 *     tokens[].criado                quando foi criado
 *     tokens[].ultimo_uso            o último uso; null: nunca
 *     aparelhos[]                    os seus aparelhos lembrados (o aplicativo no celular: entram sem a senha, pela chave no cookie)
 *     aparelhos[].id                 o id
 *     aparelhos[].nome               o nome do aparelho (o que o Lembrar mandou: "Android Chrome", "iPhone Safari"); null: sem nome
 *     aparelhos[].criado             desde quando
 *     aparelhos[].ultimo_uso         o último pedido que entrou por ele; null: nunca
 *     aparelhos[].este               verdadeiro: é o aparelho por que este pedido entrou (o cookie)
 *     push                           o Web Push
 *     push.configurado               verdadeiro ou falso: o servidor tem as chaves VAPID
 *     push.chave_publica             a chave pública VAPID (o navegador inscreve com ela); null: não configurado
 *     push.aparelhos[]               os seus aparelhos inscritos
 *     push.aparelhos[].id            o id
 *     push.aparelhos[].aparelho      o nome do aparelho (o navegador)
 *     push.aparelhos[].criada        desde quando
 *     push.aparelhos[].endpoint_hash a marca do endereço do aparelho (para o navegador saber se já está inscrito)
 *     email_configurado              verdadeiro ou falso: os seus e-mails saem (pelo seu servidor de e-mail próprio, senão pelo do sistema)
 *     smtp                           o seu servidor de e-mail próprio (o quadro Envio dos meus e-mails); a senha nunca sai
 *     smtp.proprio                   verdadeiro: os seus e-mails saem pelo seu servidor; falso: pelo do sistema (e o resto é null)
 *     smtp.host                      o servidor SMTP
 *     smtp.porta                     a porta
 *     smtp.seguranca                 tls (STARTTLS), ssl ou nada
 *     smtp.usuario                   o usuário do login (vazio: sem login)
 *     smtp.senha_definida            verdadeiro ou falso: há uma senha gravada
 *     smtp.remetente                 o e-mail de quem as mensagens vêm
 *     smtp.sistema_configurado       verdadeiro ou falso: o sistema tem servidor de e-mail (o config.php), o que vale sem o seu
 *     senha_politica                 a força da senha (a Configuração, do sistema): o que toda senha nova precisa ter
 *     senha_politica.regras          o mínimo de cada regra (0: não exige)
 *     senha_politica.regras.tamanho  quantos caracteres, no mínimo
 *     senha_politica.regras.maiusculas
 *                                    quantas letras maiúsculas, no mínimo
 *     senha_politica.regras.minusculas
 *                                    quantas letras minúsculas, no mínimo
 *     senha_politica.regras.numeros  quantos números, no mínimo
 *     senha_politica.regras.simbolos quantos símbolos (o que não é letra, número nem espaço), no mínimo
 *     senha_politica.texto           a regra por extenso, para a tela ("A senha precisa ter: 8 caracteres ou mais, 1 letra maiúscula...")
 *     email                          (só o master; null para os outros) a configuração do e-mail que o sistema leu do config.php, para o
 *                                    quadro Servidor de e-mail
 *     email.metodo                   smtp, mail ou null (o envio está desligado)
 *     email.email_metodo             o EMAIL_METODO como está no config.php; null: não está lá
 *     email.remetente                o remetente (EMAIL_REMETENTE, senão o SMTP_USUARIO); vazio: falta
 *     email.smtp                     o SMTP: host, porta, seguranca (ssl, tls ou nada), usuario e senha (verdadeiro ou falso: se está
 *                                    definida; a senha mesma nunca sai)
 *     email.smtp.host                o servidor (SMTP_HOST)
 *     email.smtp.porta               a porta (SMTP_PORTA; sem ela, 465 no ssl e 587 no resto)
 *     email.smtp.seguranca           ssl, tls ou nada (SMTP_SEGURANCA)
 *     email.smtp.usuario             o usuário (SMTP_USUARIO)
 *     email.smtp.senha               verdadeiro ou falso: a SMTP_SENHA está definida
 *     email.imap                     o IMAP da cópia em Enviados (host, porta, seguranca, usuario, senha e pasta); null: não configurado
 *     email.imap.host                o servidor (IMAP_HOST)
 *     email.imap.porta               a porta (IMAP_PORTA; sem ela, 993 no ssl e 143 no resto)
 *     email.imap.seguranca           ssl, tls ou nada (IMAP_SEGURANCA)
 *     email.imap.usuario             o usuário (IMAP_USUARIO, senão o SMTP_USUARIO)
 *     email.imap.senha               verdadeiro ou falso: a senha do IMAP está definida
 *     email.imap.pasta               a pasta Enviados (IMAP_PASTA_ENVIADOS, padrão Sent)
 *     email.pop                      o POP, só para testar a conta (host, porta, seguranca, usuario e senha); null: não configurado
 *     email.pop.host                 o servidor (POP_HOST)
 *     email.pop.porta                a porta (POP_PORTA; sem ela, 995 no ssl e 110 no resto)
 *     email.pop.seguranca            ssl, tls ou nada (POP_SEGURANCA)
 *     email.pop.usuario              o usuário (POP_USUARIO)
 *     email.pop.senha                verdadeiro ou falso: a POP_SENHA está definida
 *     email.openssl                  verdadeiro ou falso: o PHP do servidor tem a extensão openssl (o ssl e o tls precisam dela)
 *     email.mail                     verdadeiro ou falso: o mail() do PHP está ligado neste servidor
 *     email.problemas[]              o que está errado ou faltando na configuração, achado sem conectar em nada (cada um, uma frase dizendo
 *                                    o que corrigir no config.php)
 *     demo_dias                      os dias da conta de demonstração
 *     master                         (parte=menu) verdadeiro ou falso: quem pede é o master (ou o master vendo como outro)
 *     atualizar_segundos             (parte=menu) de quantos em quantos segundos a página lê tudo de novo sozinha (a Configuração da
 *                                    coleção; 0: não lê; sem coleção, 0)
 *     relojinho                      (parte=menu) o reloginho da barra e da aba, no modo da Configuração da coleção (sem coleção, null: o
 *                                    modelo da hora)
 *     relojinho.modo                 hora, aleatorio ou sem_repeticao (a Configuração relojinho_modo)
 *     relojinho.dia                  o dia da ordem (AAAA-MM-DD, no fuso da coleção): o sorteio muda a cada dia
 *     relojinho.ordem[]              o modelo de cada hora do dia, a hora 0 primeiro (24 números de 0 a 23; no modo hora, a própria hora;
 *                                    no aleatório, um sorteio por hora, podendo repetir; sem repetição, os 24 embaralhados). O sorteio sai
 *                                    de um resumo da coleção, do dia e da hora: o mesmo o dia inteiro, em toda página e aparelho
 *     idioma_padrao                  (parte=menu) o idioma de quem não escolheu no menu: o da conta (Minha conta), senão o da coleção (a
 *                                    Configuração), senão o da instalação; null: nenhum (vale o do navegador). As páginas seguem este, que
 *                                    vem a cada abertura: o padrao do script do dicionário (recurso=textos&formato=js) fica em cache
 *     linguas[]                      os idiomas
 *     fuso                           o fuso horário em que este pedido roda (as datas e horas que a API mostra e recebe). O dia do rodízio
 *                                    (o plano, o relógio do dia, as rodadas do cron) é sempre o da coleção, no fuso dela
 *     fuso.nome                      o nome ("America/Sao_Paulo")
 *     fuso.origem                    de onde veio: usuario (o seu, de Minha conta), navegador (o do aparelho: o cookie fuso_navegador das
 *                                    páginas, ou fuso_pedido= no pedido), colecao (o da Configuração) ou sistema (o FUSO do config.php)
 *     fuso.sistema                   o fuso do sistema (o das datas gravadas no banco)
 *     fuso.colecao                   o fuso da coleção do pedido; null: não tem (vale o do sistema)
 *     fusos[]                        os fusos horários que o PHP conhece, em ordem alfabética (a lista Fuso horário de Minha conta)
 *     fusos[].nome                   o nome ("America/Sao_Paulo")
 *     fusos[].deslocamento           a diferença para o UTC agora ("-03:00")
 *     linguas[].codigo               o código
 *     linguas[].nome                 o nome
 *   recurso=colecoes
 *     colecoes[]                     as coleções que você vê: as suas primeiro, depois pelo nome
 *     colecoes[].id                  o id
 *     colecoes[].nome                o nome
 *     colecoes[].dono_id             o id do dono
 *     colecoes[].dono                o login do dono
 *     colecoes[].papel               o seu papel nela: dono, membro (é membro ou vê tudo do dono) ou master
 *     colecoes[].no_id               a raiz da árvore de grupos que ela usa
 *     colecao                        o id da coleção do pedido; null: nenhuma
 *     arvores[]                      as suas árvores de grupos
 *     arvores[].id                   o id (a raiz da árvore)
 *     arvores[].nome                 o nome
 *     arvores[].grupos               quantos grupos tem (sem a raiz)
 *     arvores[].colecoes[]           as coleções que usam a árvore
 *     arvores[].colecoes[].id        o id
 *     arvores[].colecoes[].nome      o nome
 *   recurso=aprovacoes
 *     pedidos[]                      os pedidos de acesso que você aprova
 *     pedidos[].id                   o id
 *     pedidos[].login                quem pede
 *     pedidos[].email                o e-mail de quem pede
 *     pedidos[].colecao_id           a coleção pedida; null: tudo de um dono
 *     pedidos[].dono_id              o dono de quem se pede tudo; null: uma coleção
 *     pedidos[].alvo                 o que foi pedido, por extenso
 *     pedidos[].mensagem             o recado de quem pede
 *     pedidos[].criado               quando
 *     pedidos[].expira               até quando espera
 *     convites_a_aprovar[]           os convites que membros fizeram para as suas coleções, esperando você
 *     convites_feitos[]              os convites que você fez
 *     convites_a_aprovar[].id        o id
 *     convites_a_aprovar[].email     para quem
 *     convites_a_aprovar[].por       o login de quem convidou
 *     convites_a_aprovar[].por_id    o id de quem convidou
 *     convites_a_aprovar[].estado    pendente (espera o dono) ou aprovado (enviado)
 *     convites_a_aprovar[].colecao_id
 *                                    a coleção; null: tudo de um dono
 *     convites_a_aprovar[].dono_id   o dono; null: uma coleção
 *     convites_a_aprovar[].alvo      o que o convite dá
 *     convites_a_aprovar[].criado    quando
 *     convites_a_aprovar[].expira    até quando vale
 *     convites_feitos[].id           o id
 *     convites_feitos[].email        para quem
 *     convites_feitos[].por          o seu login
 *     convites_feitos[].por_id       o seu id
 *     convites_feitos[].estado       pendente (espera o dono) ou aprovado (enviado)
 *     convites_feitos[].colecao_id   a coleção; null: tudo de um dono
 *     convites_feitos[].dono_id      o dono; null: uma coleção
 *     convites_feitos[].alvo         o que o convite dá
 *     convites_feitos[].criado       quando
 *     convites_feitos[].expira       até quando vale
 *     acessos                        quem vê as suas coleções
 *     acessos.colecoes[]             cada coleção sua
 *     acessos.colecoes[].id          o id
 *     acessos.colecoes[].nome        o nome
 *     acessos.colecoes[].membros[]   os membros dela
 *     acessos.colecoes[].membros[].usuario_id
 *                                    o id do membro
 *     acessos.colecoes[].membros[].login
 *                                    o login
 *     acessos.colecoes[].membros[].desde
 *                                    desde quando
 *     acessos.tudo[]                 quem vê tudo seu
 *     acessos.tudo[].usuario_id      o id
 *     acessos.tudo[].login           o login
 *     acessos.tudo[].desde           desde quando
 *     minhas_colecoes[]              as suas coleções
 *     minhas_colecoes[].id           o id
 *     minhas_colecoes[].nome         o nome
 *     pode_convidar[]                as coleções para onde você pode convidar
 *     pode_convidar[].id             o id
 *     pode_convidar[].nome           o nome
 *     pode_convidar[].dono           o login do dono
 *     pode_convidar[].papel          o seu papel nela
 *     email_configurado              verdadeiro ou falso: os seus e-mails saem, pelo seu servidor próprio ou pelo do sistema (sem isso, o
 *                                    link do convite aparece para você mandar)
 *     convite_dias                   quantos dias um convite ou um pedido espera (CONVITE_DIAS)
 *   recurso=cadastro
 *     aberto                         verdadeiro ou falso: o cadastro está aberto (CADASTRO_ABERTO); fechado, só com convite
 *     demo_dias                      os dias da conta de demonstração (0: a conta nasce oficial)
 *     email_configurado              verdadeiro ou falso: o sistema manda e-mail, pelo servidor dele ou pelo próprio de alguma conta (o
 *                                    Esqueci a senha precisa disso)
 *     senha_politica                 a força da senha (a Configuração, do sistema): o que toda senha nova precisa ter
 *     senha_politica.regras          o mínimo de cada regra (0: não exige)
 *     senha_politica.regras.tamanho  quantos caracteres, no mínimo
 *     senha_politica.regras.maiusculas
 *                                    quantas letras maiúsculas, no mínimo
 *     senha_politica.regras.minusculas
 *                                    quantas letras minúsculas, no mínimo
 *     senha_politica.regras.numeros  quantos números, no mínimo
 *     senha_politica.regras.simbolos quantos símbolos (o que não é letra, número nem espaço), no mínimo
 *     senha_politica.texto           a regra por extenso, para a tela ("A senha precisa ter: 8 caracteres ou mais, 1 letra maiúscula...")
 *     linguas[]                      os idiomas
 *     linguas[].codigo               o código
 *     linguas[].nome                 o nome
 *     convite                        com convite=<token>: o convite; false: não vale mais; null: sem convite
 *     convite.email                  para quem
 *     convite.alvo                   o que ele dá
 *     convite.expira                 até quando vale
 *     convite.tem_conta              verdadeiro ou falso: o e-mail já tem conta (então é entrar e aceitar)
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
 *     senha_politica                 a força da senha (a Configuração, do sistema): o que toda senha nova precisa ter
 *     senha_politica.regras          o mínimo de cada regra (0: não exige)
 *     senha_politica.regras.tamanho  quantos caracteres, no mínimo
 *     senha_politica.regras.maiusculas
 *                                    quantas letras maiúsculas, no mínimo
 *     senha_politica.regras.minusculas
 *                                    quantas letras minúsculas, no mínimo
 *     senha_politica.regras.numeros  quantos números, no mínimo
 *     senha_politica.regras.simbolos quantos símbolos (o que não é letra, número nem espaço), no mínimo
 *     senha_politica.texto           a regra por extenso, para a tela ("A senha precisa ter: 8 caracteres ou mais, 1 letra maiúscula...")
 *     usuarios[].id                  o id (só o master vê)
 *     usuarios[].email               o e-mail (para recuperar a senha, os convites e os avisos)
 *     usuarios[].papel               master (cuida do sistema inteiro) ou comum
 *     usuarios[].idioma              o idioma dele; null: o da coleção
 *     usuarios[].nivel               o modo da interface dele: basico ou avancado
 *     usuarios[].fuso                o fuso horário dele ("America/Sao_Paulo"; "navegador": o do aparelho); null: o da coleção, senão o do
 *                                    sistema
 *     fusos[]                        (só o master) os fusos horários que o PHP conhece (a lista Fuso horário do quadro Alterar)
 *     fusos[].nome                   o nome ("America/Sao_Paulo")
 *     fusos[].deslocamento           a diferença para o UTC agora ("-03:00")
 *     usuarios[].ultimo_acesso       o último acesso (texto AAAA-MM-DD HH:MM:SS); null: nunca entrou
 *     usuarios[].expira_em           quando a conta de demonstração vence (texto AAAA-MM-DD HH:MM:SS); null: a conta é oficial
 *     usuarios[].demo                verdadeiro ou falso: é de demonstração
 *     usuarios[].dias_restantes      quantos dias faltam para vencer; null: oficial
 *     usuarios[].bloqueado           verdadeiro ou falso: bloqueada pelo master (não entra)
 *     usuarios[].colecoes[]          as coleções dele (o dono)
 *     usuarios[].colecoes[].id       o id
 *     usuarios[].colecoes[].nome     o nome
 *     usuarios[].colecoes[].relogios quantos relógios
 *     usuarios[].membro_de[]         as coleções de outros de que ele é membro
 *     usuarios[].membro_de[].id      o id
 *     usuarios[].membro_de[].nome    o nome
 *     usuarios[].membro_de[].dono    o login do dono
 *     usuarios[].tudo_de[]           os logins dos donos de quem ele vê tudo
 *     usuarios[].pedidos             quantos pedidos de acesso dele esperam
 *     usuarios[].tokens              quantos tokens de API ele tem
 *     colecoes[]                     todas as coleções (para transferir e dar acesso)
 *     colecoes[].id                  o id
 *     colecoes[].nome                o nome
 *     colecoes[].dono_id             o id do dono
 *     colecoes[].dono                o login do dono
 *     colecoes[].papel               o papel de quem pede nela: dono, membro ou master
 *     colecoes[].no_id               a raiz da árvore de grupos que ela usa
 *     demo_dias                      os dias da conta de demonstração (DEMO_DIAS; 0: as contas novas já nascem oficiais)
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
 *     calibre                        o calibre pedido (calibre=): {id, nome, fabricante, relogios}; null: sem
 *     calibre.id                     o número do calibre
 *     calibre.nome                   o nome do calibre
 *     calibre.fabricante             o fabricante; null: não informado
 *     calibre.relogios               os relógios da coleção com este calibre, por nome
 *     calibre.relogios[]             um relógio: {id, nome}
 *     calibre.relogios[].id          o número do relógio
 *     calibre.relogios[].nome        o nome do relógio
 *     calibres                       os calibres da árvore, pelo nome (para escolher os documentos de um)
 *     calibres[]                     um calibre: {id, nome, documentos}
 *     calibres[].id                  o número do calibre
 *     calibres[].nome                o nome do calibre
 *     calibres[].documentos          quantos documentos ele tem
 *     winder                         o winder pedido (winder=): {id, nome}; null: sem
 *     winder.id                      o número do winder
 *     winder.nome                    o nome do winder
 *     winders                        os winders da coleção, pelo nome (para escolher os documentos de um)
 *     winders[]                      um winder: {id, nome, documentos}
 *     winders[].id                   o número do winder
 *     winders[].nome                 o nome do winder
 *     winders[].documentos           quantos documentos ele tem
 *     do_calibre                     com relogio: o calibre dele e os documentos do calibre, que valem para ele: {id, nome, documentos
 *                                    (como em documentos)}; null: o relógio não tem calibre
 *     do_calibre.id                  o número do calibre
 *     do_calibre.nome                o nome do calibre
 *     do_calibre.documentos          os documentos do calibre, na ordem das categorias
 *     do_calibre.documentos[]        um documento, com os mesmos campos de documentos[]
 *     relogio.id                     o número do relógio
 *     relogio.nome                   o nome do relógio
 *     relogio.copia_banco            verdadeiro: o relógio pede a cópia no banco de todos os documentos dele (o segundo dos três níveis da
 *                                    cópia; a Configuração da coleção, documentos_copia, se escolhe, vale por cima)
 *     pasta_ok                       verdadeiro: a pasta dos documentos (DOCUMENTOS_PASTA do config.php) existe e aceita gravar
 *     copia_sistema                  a cópia no banco pela Configuração da coleção (documentos_copia), que vale sobre o relógio e o
 *                                    arquivo: verdadeiro (todos), todo arquivo vai; falso (nenhum), nenhum vai; null (vazio), cada relógio
 *                                    decide (a caixa do cadastro só aparece então)
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
 *     documentos[].no_disco          verdadeiro: o arquivo está inteiro na pasta dos documentos, com o tamanho do envio (falso: sumiu ou
 *                                    está cortado, e volta do banco quando for pedido ou na próxima rodada do cron; sem a cópia no banco,
 *                                    está perdido)
 *     documentos[].no_banco          verdadeiro: a cópia de segurança do arquivo está completa no banco (se ele sumir da pasta, volta dali)
 *     documentos[].copia_banco       verdadeiro: o próprio arquivo pede a cópia no banco (o terceiro nível; o relógio e a Configuração da
 *                                    coleção, se pedem, valem por cima)
 *     documentos[].copia_por         quem pede a cópia no banco: sistema (a Configuração da coleção, documentos_copia = todos), relogio (a
 *                                    marca do relógio) ou arquivo (a marca do próprio arquivo); null: ninguém
 *     documentos[].relogio_id        o relógio dele
 *     documentos[].calibre_id        o calibre dele (os documentos do calibre); null nos de relógio e nos de winder
 *     documentos[].winder_id         o winder dele (os documentos do winder: o manual, a nota, as fotos); null nos de relógio e nos de
 *                                    calibre
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
 *     fonte                          de onde vem o texto: README.md (em português) ou textos/manual/<idioma>.md (a tradução do idioma do
 *                                    pedido)
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
 *     telas.menu.itens[].grupo       o grupo do menu em que o item fica (Rodízio, Coleção, Sistema, Conta); vazio: solto na barra
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
 *     listas.migracoes[]             as migrações, na ordem
 *     listas.migracoes[].versao      a versão (v2, v3...)
 *     listas.migracoes[].arquivo     o arquivo do SQL dela
 *     listas.migracoes[].traz        o que ela traz
 *     listas.migracoes[].passo_em_php
 *                                    verdadeiro: ela tem também um passo em programa, depois do SQL
 *     listas.config_php[]            as constantes do config.php
 *     listas.config_php[].constante  o nome (ou os nomes) da constante
 *     listas.config_php[].descricao  para que ela serve
 *     listas.tipos_de_destino[]      os tipos de destino das mensagens
 *     listas.tipos_de_destino[].tipo o código (webhook, email, webpush)
 *     listas.tipos_de_destino[].nome o nome
 *     listas.tipos_de_agenda[]       os tipos de agenda
 *     listas.tipos_de_agenda[].tipo  o código (google, caldav, outlook, ics)
 *     listas.tipos_de_agenda[].nome  o nome
 *   recurso=backup
 *     ativo                          falso: a cópia de segurança está desligada no config.php (BACKUP_ATIVO = false): o cron não faz cópia,
 *                                    a escrita fazer é recusada e a Configuração não mostra o quadro Cópia de segurança
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
 *   404 {"erro"}: recurso desconhecido (com "recursos", a lista deles), a coleção que você não vê (colecao=), o link ICS desconhecido, ou o relógio, a foto, o documento (ou o arquivo dele) ou o
 *       README.md (recurso=manual) que não existe, ou um arquivo que não sai pelo recurso=codigo ({"erro", e "o_que_ver" no
 *       que fica de fora de propósito, como o config.php, ou "arquivos", os que saem)
 *   403 {"erro"}: a cópia de segurança e as migrações, por quem não é o master
 *   409 {"ok": false, "confirmar": true, "impacto"}: uma exclusão sem confirmar=1 (nada foi apagado; o impacto diz o que sai junto);
 *       {"erro", "sem_colecao": true}: a conta sem coleção nenhuma (só a conta responde); ou {"erro": "o banco recusou a gravação:
 *       ...", "detalhe"} (um registro que outro ainda usa, um valor repetido, ou um valor fora das regras de validação do banco: o
 *       nome da regra, ck_<tabela>_<o quê>, vem no detalhe)
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
// o token pessoal (Minha conta) vale como o login do dono dele
if (!$token_ok && $quem === "" && $token !== "") {
    $quem = usuario_por_token($token);
}
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
// quem pede (o login, ou o token: o API_TOKEN do config.php vale como o master mais antigo) e em que coleção (colecao=, o
// cookie do seletor do menu, ou a primeira que ele vê): o escopo do banco, daqui para a frente, é o dessa coleção
$em_dia = !$banco_vazio && count($pendentes) === 0;
if ($em_dia && $token_ok && usuario_atual() === null) {
    usuario_definir(sem_escopo(function () { return linha("SELECT * FROM usuario WHERE papel = 'master' ORDER BY id LIMIT 1"); }));
}
// o master vendo o sistema como outro usuário (como=<login>, ou o cookie da faixa "Vendo como" das páginas)
if ($em_dia && usuario_master()) {
    $como = vendo_como($_REQUEST["como"] ?? ($_COOKIE["como"] ?? ""));
    if ($como !== null) {
        $quem = $como;
        header("X-Vendo-Como: " . rawurlencode($como));
    }
}
$colecao_negada = false;
if ($em_dia && usuario_atual() !== null) {
    $c_pedida = colecao_escolher(usuario_atual(), $d["colecao"] ?? ($_REQUEST["colecao"] ?? null), $_COOKIE["colecao"] ?? null);
    if ($c_pedida === false) {
        $colecao_negada = true;
    } elseif ($c_pedida !== null) {
        banco_escopo($c_pedida);
    }
}
// o idioma do pedido: lingua=, o cookie do seletor do menu, o do usuário, o da coleção (a Configuração), o da instalação,
// o Accept-Language
$lingua_padrao = null;
if ($em_dia) {
    try {
        $lingua_padrao = lingua_valida(usuario_atual()["idioma"] ?? null) ?? (lingua_valida(cfg("idioma")) ?? lingua_valida(sis("idioma")));
    } catch (Throwable $e) {
        $lingua_padrao = null;
    }
}
lingua_definir(lingua_do_pedido($lingua_padrao));
// o fuso do pedido: o do usuário (Minha conta), o da coleção (a Configuração) ou o do sistema (o FUSO do config.php)
if ($em_dia) {
    try {
        fuso_definir(...fuso_do_pedido(usuario_atual()));
    } catch (Throwable $e) {
        fuso_definir(null);
    }
}
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
} elseif ($recurso === "manifest") {
    // o manifesto do aplicativo (PWA): sem login (o navegador pede ao instalar); com o cookie do aparelho lembrado, o
    // start_url entra sozinho
    header("Content-Type: application/manifest+json; charset=utf-8");
    header("Cache-Control: private, no-cache");
    echo json_encode(app_manifesto(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit;
} elseif ($recurso === "sw") {
    // o service worker do aplicativo (o sw.js com a versão e a lista dos arquivos das telas na frente): sem login
    header("Content-Type: application/javascript; charset=utf-8");
    header("Cache-Control: private, no-cache");
    echo app_service_worker();
    exit;
} elseif ($recurso === "ics" && $em_dia) {
    // o link ICS de uma agenda: sem login, pela chave secreta do link (quem tem o link vê os eventos)
    $ics = ics_feed((string)($_REQUEST["chave"] ?? ""));
    if ($ics === null) {
        $codigo = 404;
        $saida = ["erro" => t("link de agenda desconhecido")];
    } else {
        header("Content-Type: text/calendar; charset=utf-8");
        header("Content-Disposition: inline; filename=\"relojoeiro.ics\"");
        echo $ics;
        exit;
    }
} elseif ($recurso === "cadastro" && $em_dia) {
    // o cadastro aberto (a página cadastro.php): sem login. Lê (se está aberto, as coleções e os donos, o convite) e grava
    // (criar a conta, esqueci a senha, a senha nova pelo link)
    if ($escrita) {
        $saida = op_cadastro($acao, $d);
        $codigo = $saida["ok"] ? 200 : 400;
    } else {
        $saida = cadastro_consulta($_REQUEST["convite"] ?? null);
    }
} elseif (!$token_ok && $quem === "") {
    $codigo = 401;
    header("WWW-Authenticate: Basic realm=\"Relogios\", charset=\"UTF-8\"");
    $saida = ["erro" => t("falta autenticação: o token (cabeçalho X-Api-Token ou parâmetro token) ou o login do site (HTTP Basic)")];
    // sem nenhum usuário, o login não tem como dar certo: diz como criar o primeiro
    if ($banco_vazio || !banco_tem_tabela("usuario") || (int)valor("SELECT COUNT(*) FROM usuario") === 0) {
        $saida["sem_usuarios"] = true;
        $saida["como"] = $banco_vazio ? t("o banco está vazio: instale com o token (POST recurso=instalacao, acao=instalar) e crie o primeiro usuário (POST recurso=usuarios, acao=salvar, login e senha)")
            : t("nenhum usuário cadastrado: crie o primeiro com o token (POST recurso=usuarios, acao=salvar, login e senha)");
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
} elseif (($recurso === "backup" || ($recurso === "migracoes" && $escrita)) && !$token_ok && !usuario_master() && banco_tem_coluna("usuario", "papel")) {
    // o banco inteiro (a cópia de segurança, as migrações) é do sistema: só o master (num banco de antes da v25, sem papéis,
    // qualquer um que entra, como antes)
    $codigo = 403;
    $saida = ["erro" => t("só o master cuida da cópia de segurança e das migrações do banco")];
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
} elseif ($colecao_negada) {
    // uma coleção que ele não vê: como se não existisse
    $codigo = 404;
    $saida = ["erro" => t("coleção não encontrada")];
} elseif (colecao_atual() === null && !$sem_banco && !in_array($recurso, ["usuarios", "conta", "colecoes", "acessos", "pedidos", "convites", "aprovacoes", "ajuda", "reconstrucao"], true)) {
    // sem coleção nenhuma (a conta que só pediu acesso e espera a aprovação; o token sem nenhum usuário): só a conta responde
    $codigo = 409;
    $saida = ["erro" => usuario_atual() === null ? t("nenhum usuário cadastrado: crie o primeiro com o token (POST recurso=usuarios, acao=salvar, login e senha); ele ganha a primeira coleção, com o conjunto inicial")
        : t("você ainda não tem coleção: crie uma (POST recurso=colecoes, acao=criar) ou espere a aprovação do seu pedido de acesso"), "sem_colecao" => true];
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
    $saida = ["avisos" => avisos_de_agora()];
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
    // os campos dos acessórios (Cadastros › Campos, para acessório): o formulário mostra os do tipo escolhido
    $saida = ["acessorios" => $lista_a,
        "campos" => array_values(array_map(function ($c) {
            return ["identificador" => $c["identificador"], "nome" => $c["nome"], "tipo" => $c["tipo"], "unidade" => $c["unidade"], "acessorio_tipo" => $c["acessorio_tipo"] ?? null,
                "opcoes" => campo_opcoes_lista($c), "multiplo" => (int)($c["multiplo"] ?? 0) === 1, "regra" => campo_regra_texto($c),
                "serve" => ($c["serve_campo_id"] ?? null) === null ? null : (array_values(array_filter(campos_todos(), function ($x) use ($c) { return (int)$x["id"] === (int)$c["serve_campo_id"]; }))[0]["nome"] ?? null)];
        }, campos_de_acessorio())),
        "tipos" => lista_opcoes("acessorio_tipo"),
        "por_tipo" => array_values(array_filter(array_map(function ($x) use ($lista_a) {
            return ["tipo" => $x["identificador"], "nome" => $x["nome"], "quantos" => count(array_filter($lista_a, function ($a) use ($x) { return $a["tipo"] === $x["identificador"]; }))];
        }, array_values(lista_itens("acessorio_tipo"))), function ($x) { return $x["quantos"] > 0; })),
        "soltos" => count(array_filter($lista_a, function ($a) { return $a["relogio_id"] === null; })),
        "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "posse" => (string)$r["posse"], "posse_comportamento" => posse_de($r)]; }, linhas("SELECT * FROM relogio ORDER BY nome"))];
} elseif ($recurso === "winders") {
    // os winders da coleção (a página Winders): cada um com as posições, o programa delas, o relógio que está em cada uma e o
    // lugar fixo, os campos do winder e da posição (o formulário), os relógios que podem ir para um winder (os que têm um
    // tipo de lançamento marcado como winder: o Pôr no winder da página) e os programas (o menu da posição)
    $agora_w = time();
    $no_winder = [];
    foreach (linhas("SELECT l.relogio_id, lw.posicao_id FROM lancamento_winder lw JOIN lancamento l ON l.id = lw.lancamento_id WHERE l.inicio <= ? AND (l.fim IS NULL OR l.fim > ?)",
        [date("Y-m-d H:i:s", $agora_w), date("Y-m-d H:i:s", $agora_w)]) as $x) {
        $no_winder[(int)$x["relogio_id"]] = (int)$x["posicao_id"];
    }
    $rel_w = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY nome, id") as $r_w) {
        $t_w = tipo_winder_do_relogio($r_w);
        if ($t_w !== null) {
            // no winder agora: a sessão do tipo dele aberta, numa posição ou fora de uma (as de antes dos winders cadastrados)
            $aberta_w = valor("SELECT COUNT(*) FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.identificador = ? AND l.inicio <= ? AND l.fim IS NULL",
                [(int)$r_w["id"], $t_w["identificador"], date("Y-m-d H:i:s", $agora_w)]);
            $rel_w[] = ["id" => (int)$r_w["id"], "nome" => $r_w["nome"], "tipo" => $t_w["identificador"], "tipo_nome" => $t_w["nome"],
                "no_winder" => (int)$aberta_w > 0,
                "posicao_id" => $no_winder[(int)$r_w["id"]] ?? null, "posicao" => isset($no_winder[(int)$r_w["id"]]) ? winder_posicao_nome($no_winder[(int)$r_w["id"]]) : null];
        }
    }
    $saida = ["winders" => winders_lista($agora_w), "campos_winder" => winder_campos_formulario(campos_de_winder()), "campos_posicao" => winder_campos_formulario(campos_de_posicao()),
        "relogios" => $rel_w, "programas" => array_map(function ($p) { return ["id" => $p["id"], "nome" => $p["nome"], "texto" => $p["calculo"]["texto"]]; }, winder_programas_lista())];
} elseif ($recurso === "programas") {
    require __DIR__ . "/api/programas.php";
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
} elseif ($recurso === "totais") {
    require __DIR__ . "/api/totais.php";
} elseif ($recurso === "precisao") {
    require __DIR__ . "/api/precisao.php";
} elseif ($recurso === "calibres") {
    require __DIR__ . "/api/calibres.php";
} elseif ($recurso === "unidades") {
    require __DIR__ . "/api/unidades.php";
} elseif ($recurso === "servicos") {
    require __DIR__ . "/api/servicos.php";
} elseif ($recurso === "manutencao" || $recurso === "oficinas") {
    require __DIR__ . "/api/manutencao.php";
} elseif ($recurso === "listas") {
    require __DIR__ . "/api/listas.php";
} elseif ($recurso === "reserva") {
    require __DIR__ . "/api/reserva.php";
} elseif ($recurso === "timegrapher") {
    require __DIR__ . "/api/timegrapher.php";
} elseif ($recurso === "relatorios") {
    require __DIR__ . "/api/relatorios.php";
} elseif ($recurso === "roteiro") {
    // o teste de um roteiro (ou do roteiro de um modo) num dia: cada relógio com as linhas calculadas, e quem sairia
    $texto = (string)($_REQUEST["roteiro"] ?? "");
    if (ctype_digit((string)($_REQUEST["modo"] ?? "")) && $texto === "") {
        $m_r = linha("SELECT * FROM modo WHERE id = ?", [(int)$_REQUEST["modo"]]);
        $texto = $m_r ? modo_roteiro_texto($m_r) : "";
    }
    $dia_r = DateTimeImmutable::createFromFormat("!Y-m-d", (string)($_REQUEST["dia"] ?? ""));
    $dia_r = $dia_r && $dia_r->format("Y-m-d") === ($_REQUEST["dia"] ?? "") ? $dia_r : hoje_da_colecao();
    if ($dia_r < hoje_da_colecao() || $dia_r > hoje_da_colecao()->modify("+366 days")) {
        $codigo = 400;
        $saida = ["erro" => t("dia: de hoje até um ano à frente (AAAA-MM-DD)")];
    } else {
        $saida = roteiro_testar($texto, $dia_r);
    }
} elseif ($recurso === "eventos") {
    $saida = ["eventos" => array_map("evento_legivel", eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome")))];
} elseif ($recurso === "destinos") {
    require __DIR__ . "/api/destinos.php";
} elseif ($recurso === "agendas") {
    require __DIR__ . "/api/agendas.php";
} elseif ($recurso === "outlook_retorno") {
    // a volta da página da Microsoft (code e state): a conexão vai para a agenda (da coleção dela, se você a vê) e a página
    // Agendas abre com o recado
    $aid = (int)explode(".", (string)($_REQUEST["state"] ?? ""))[0];
    $col_o = sem_escopo(function () use ($aid) { return valor("SELECT colecao_id FROM agenda WHERE id = ?", [$aid]); });
    if ($col_o !== null && in_array((int)$col_o, array_column(colecoes_visiveis(usuario_atual()), "id"), true)) {
        banco_escopo((int)$col_o);
        setcookie("colecao", (string)(int)$col_o, ["path" => "/", "samesite" => "Lax"]);
        [$ok_o, $msg_o] = isset($_REQUEST["error"]) ? [false, t("A Microsoft não autorizou: {1}", [1 => (string)($_REQUEST["error_description"] ?? $_REQUEST["error"])])]
            : outlook_conectar_retorno((string)($_REQUEST["code"] ?? ""), (string)($_REQUEST["state"] ?? ""));
    } else {
        [$ok_o, $msg_o] = [false, t("Agenda não encontrada.")];
    }
    $codigo = 302;
    header("Location: agendas.php?recado=" . rawurlencode($msg_o) . "#agenda-" . $aid);
    $saida = ["ok" => $ok_o, "mensagem" => $msg_o];
} elseif ($recurso === "usuarios") {
    // o master: o painel (todos, com o que cada um tem, e as coleções, para transferir e dar acesso); os outros, só a si mesmos
    $saida = usuario_master() ? ["usuarios" => usuarios_painel(), "voce" => $quem, "demo_dias" => demo_dias(), "colecoes" => colecoes_visiveis(usuario_atual()), "fusos" => fusos_lista(),
        "senha_politica" => senha_politica_api()]
        : ["usuarios" => usuarios_catalogo(), "voce" => $quem];
} elseif ($recurso === "conta") {
    $saida = ($_REQUEST["parte"] ?? "") === "menu" ? conta_menu() : conta_consulta();
} elseif ($recurso === "colecoes") {
    $saida = ["colecoes" => colecoes_visiveis(usuario_atual()), "colecao" => colecao_atual(), "arvores" => usuario_atual() !== null ? arvores_do_dono((int)usuario_atual()["id"]) : []];
} elseif ($recurso === "aprovacoes") {
    $saida = usuario_atual() !== null ? aprovacoes_consulta() : ["erro" => t("Entre com um usuário (o token do servidor não tem conta).")];
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
        $saida = ["fonte" => lingua_atual() !== "pt-BR" && manual_traduzido(lingua_atual()) !== null ? "textos/manual/" . lingua_atual() . ".md" : "README.md", "trechos" => array_map(function ($t) { return ["de" => $t[0], "ate" => $t[1]]; }, MANUAL_TRECHOS),
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
        "historico", "previsao", "plano", "proximos", "rodizio_manual", "desejos", "estatisticas", "totais", "precisao", "calibres", "unidades", "servicos", "manutencao", "oficinas", "listas", "reserva", "timegrapher", "relatorios", "hora_certa", "acessorios", "winders", "programas", "passaporte", "galeria", "textos", "eventos", "destinos", "agendas", "ics", "foto", "documentos", "documento", "usuarios", "conta", "colecoes", "aprovacoes", "cadastro", "migracoes", "instalacao", "importacao", "entrar", "backup", "ajuda", "manual", "reconstrucao", "codigo"]];
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
