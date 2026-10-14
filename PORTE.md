# Porte do sistema antigo para o novo

Gerado do código do sistema antigo (9.278 linhas). Cada item é algo que o sistema antigo faz e que o novo tem de fazer,
no mesmo lugar, com a mesma regra; o que muda é só o acesso ao banco (o modelo novo: árvore, campos, lançamentos,
fórmulas, avisos, critérios por lugar, modos). Um arquivo só está portado quando todos os itens dele estão feitos e testados.
Marcados com [x]: os itens da escala inteligente, dos eventos personalizados, do Google Agenda, da previsão do smartwatch e da
linha do tempo (com a migração v4), e todas as páginas, o painel, a tabela, a foto e o cron (com a migração v5: as telas do
sistema antigo, montadas no navegador a partir da API), feitos e testados. Nas páginas, sem marca ficam só três itens que o
modelo novo não tem como reproduzir igual (o PARIDADE.md diz por quê); a medição do gasto pelas leituras, que era um deles,
foi portada na migração v6. Nas seções api.php, lib.php e operacoes.php, os itens sem
marca ainda não foram conferidos um por um contra o antigo, mesmo quando a função nova já tem o equivalente.

## index.php (549 linhas, 55 itens)

- [x] seção: " data-abrir="">
- [x] seção: Nenhum relógio para hoje
- [x] seção: Hoje é dia de
- [x] seção: Próximos dias
- [x] seção: Relógios
- [x] ação: modo
- [x] botão: Aplicar modo
- [x] botão: Sortear
- [x] botão: Limpar filtros
- [x] botão: Código
- [x] botão: Relógio
- [x] botão: Tipo
- [x] botão: Estado
- [x] botão: Carga
- [x] botão: Última vezusado
- [x] botão: Próximamanutenção
- [x] botão: Em
- [x] botão: O quefazer
- [x] botão: Compradoem
- [x] botão: Valor
- [x] botão: ×
- [x] campo: Modo de rodízio
- [x] campo: Tipo
- [x] campo: Segunda a sexta
- [x] campo: Sábado e domingo
- [x] campo: Período
- [x] campo: Como escolher o relógio
- [x] campo: Garantia de rodízio: dias sem uso, no máximo
- [x] campo: Sortear de novo
- [x] coluna: Dia
- [x] coluna: Relógio
- [x] coluna: Lembrete
- [x] coluna: Código
- [x] coluna: Tipo
- [x] coluna: Estado
- [x] coluna: Carga
- [x] coluna: Última vezusado
- [x] coluna: Próximamanutenção
- [x] coluna: Em
- [x] coluna: O quefazer
- [x] coluna: Compradoem
- [x] coluna: Valor
- [x] regra: botões dos avisos: operações de um relógio (a leitura de carga dos avisos sempre atualiza o registro)
- [ ] regra: relógio aberto no painel da direita: o escolhido, senão o de hoje, senão o primeiro
- [x] regra: relógio aberto no painel da direita: só o escolhido; sem escolha, o painel não aparece
- [x] regra: tabela: disponíveis primeiro (o de hoje, depois os com aviso), indisponíveis no fim.
- [x] regra: Na tela, a ordem e os filtros escolhidos no cabeçalho valem por cima desta.
- [x] regra: relógio do dia no quadro "Hoje": a linha dele da tabela, o estado agora e a situação (sem repetir a carga)
- [x] regra: dia já começado no pulso: trocar o modo ou sortear de novo vale de amanhã em diante
- [x] regra: a forma de escolha salva de cada modo, para o JavaScript mostrar a certa ao trocar o modo na lista
- [x] regra: sortear de novo: as ações possíveis agora, com a explicação e a confirmação de cada uma
- [x] regra: campos do modo escolhido (a escuta é no documento: continua valendo depois que o bloco é atualizado sem recarregar)
- [x] regra: a explicação e os parâmetros da forma de escolha: a garantia de rodízio só aparece onde vale (inteligente, com sorteio e escala)
- [x] regra: campos e explicação do modo escolhido, e a forma de escolha salva dele
- [x] regra: lidas do próprio quadro, que é trocado depois de cada envio (o script, não)

## detalhe.php (353 linhas, 56 itens)

- [x] seção: Novo relógio
- [x] seção: 
- [x] seção: Agora
- [x] seção: Previsão
- [x] seção: Rodízio
- [x] seção: Compra
- [x] seção: Próximas manutenções
- [x] seção: Lançar
- [x] seção: Carga ao longo do tempo
- [x] seção: Histórico
- [x] ação: foto
- [x] ação: remover_foto
- [x] ação: usando
- [x] ação: carga
- [x] ação: salvar
- [x] ação: excluir
- [x] botão: Remover foto
- [x] botão: Usando hoje
- [x] botão: " class="leve">
- [x] botão: Registrar
- [x] botão: Informar carga
- [x] botão: Excluir este relógio
- [x] campo: Onde
- [x] campo: De
- [x] campo: Até
- [x] campo: Carga agora
- [x] campo: Nome
- [ ] campo: Tipo
- [x] campo: Grupo
- [x] campo: Sua nota para o relógio, de 0 a 100
- [x] campo: Autonomia em uso real (dias)
- [x] campo: Decaimento em uso (% por dia)
- [x] campo: Decaimento em repouso, guardado desligado (% por dia)
- [x] campo: Reserva de marcha (horas)
- [x] campo: no pulso por
- [x] campo: dá
- [x] campo: sol direto por
- [x] campo: no winder por
- [x] campo: Reserva com carga cheia (dias)
- [x] campo: Duração da pilha (meses)
- [x] campo: Última troca de pilha
- [x] campo: Revisar a cada (meses)
- [x] campo: Última revisão
- [x] campo: Data da compra
- [x] campo: Valor pago (R$)
- [x] campo: Onde comprou
- [x] campo: Garantia até
- [x] campo: Observação
- [x] campo: Foto
- [x] coluna: Quando
- [x] coluna: Duração
- [x] coluna: Estado
- [x] regra: Painel com tudo sobre um relógio. Incluído pelo index.php e pelo ficha.php.
- [x] regra: Espera: $id (relógio), $hoje, $volta ("index" ou "ficha") e $novo (true para o formulário de relógio novo).
- [x] regra: histórico: no painel só o resumo; a linha do tempo inteira fica em historico.php, que abre numa aba nova
- [x] regra: previsão da carga do smartwatch (a mesma que a API devolve)

## ficha.php (84 linhas, 2 itens)

- [x] botão: Abrir
- [x] campo: Relógio

## historico.php (149 linhas, 14 itens)

- [x] seção: 
- [x] botão: Filtrar
- [x] campo: De
- [x] campo: Até
- [x] campo: Estado
- [x] campo: Por página
- [x] coluna: Início
- [x] coluna: Fim
- [x] coluna: Duração
- [x] coluna: Estado
- [x] regra: Histórico de um relógio em página própria (abre numa aba nova a partir do painel): a linha do tempo inteira,
- [x] regra: com filtro de período e de estado, resumo do tempo em cada estado e navegação de páginas. historico.php?id=3
- [x] regra: a linha do tempo inteira; o período corta os trechos nas bordas (um trecho que atravessa o "de" conta só a parte de dentro)
- [x] regra: endereço desta página com os filtros atuais, trocando o que for preciso

## configuracao.php (379 linhas, 53 itens)

- [x] seção: Atualização do banco
- [x] seção: O banco está desatualizado
- [x] seção: Geral
- [x] seção: Telegram (API de alerta)
- [x] seção: Google Agenda
- [x] seção: Mensagem padrão
- [x] seção: 
- [x] seção: O que vai para onde
- [x] seção: Como sai hoje
- [x] seção: Telegram de manhã
- [x] seção: Telegram à noite
- [x] seção: Agenda
- [x] ação: aplicar_migracoes
- [x] ação: salvar
- [x] ação: evento_excluir
- [x] ação: evento_salvar
- [x] ação: teste_agenda_criar
- [x] ação: teste_agenda_remover
- [x] ação: testar_manha
- [x] ação: testar_noite
- [x] ação: sincronizar
- [x] botão: Aplicar agora
- [x] botão: Criar evento de teste
- [x] botão: >Remover evento de teste
- [x] botão: " class="discreto" onclick="return confirm('Excluir o evento ?')">excluir
- [x] botão: Salvar configuração
- [x] botão: Enviar agora
- [x] botão: Sincronizar agora
- [x] campo: Manhã: relógio do dia e avisos
- [x] campo: Noite: preparar o relógio de amanhã
- [x] campo: Relógio no pulso a partir de
- [x] campo: Até
- [x] campo: Sessão no sol esquecida aberta fecha às
- [x] campo: Solar: pôr no sol quando a carga estimada chegar a (%)
- [x] campo: Endereço do sistema, para a âncora {link}
- [x] campo: ID da agenda
- [x] campo: Caminho da chave JSON no servidor
- [x] campo: Criar os eventos com quantos dias de antecedência
- [x] campo: _padrao">
- [x] campo: _corpo_
- [x] campo: Nome
- [x] campo: Relógio (opcional: com relógio, as âncoras dele funcionam)
- [x] campo: Quando dispara
- [x] campo: Hora
- [x] campo: Dia do mês
- [x] campo: A cada quantos dias
- [x] coluna: Aviso
- [x] coluna: Quando
- [x] coluna: Personalizar
- [x] regra: banco desatualizado: antes de qualquer outra coisa, mostra o que falta e aplica com um botão
- [x] regra: "Inserir âncora": põe a âncora escolhida onde está o cursor do campo
- [x] regra: Evento personalizado: mostra só os campos da repetição escolhida
- [x] regra: "Corpo próprio": mostra ou esconde o campo do texto daquele aviso

## criterios.php (459 linhas, 64 itens)

- [x] seção: Critérios de escolha do relógio
- [x] seção: Como a nota funciona
- [x] seção: Como a nota vira escolha
- [x] seção: Onde há critérios
- [x] seção: Critérios de
- [x] seção: do conjunto de
- [x] seção: A nota de cada relógio agora
- [x] seção: Restaurar os critérios iniciais
- [x] ação: conjunto_criar
- [x] ação: param_pesos
- [x] ação: param_excluir
- [x] ação: ordem
- [x] ação: param_novo
- [x] ação: conjunto_excluir
- [x] ação: sub_pesos
- [x] ação: sub_excluir
- [x] ação: faixas
- [x] ação: sub_medida
- [x] ação: sub_mover
- [x] ação: sub_novo
- [x] ação: restaurar
- [x] botão: Abrir
- [x] botão: Criar critérios próprios, copiando os de
- [x] botão: Criar vazio
- [x] botão: " name="direcao" value="sobe" class="leve discreto" type="submit" title="Subir">↑
- [x] botão: " name="direcao" value="desce" class="leve discreto" type="submit" title="Descer">↓
- [x] botão: " class="leve discreto" type="submit">Excluir
- [x] botão: Salvar parâmetros
- [x] botão: Incluir
- [x] botão: Excluir os critérios próprios de
- [x] botão: Salvar subparâmetros
- [x] botão: + faixa
- [x] botão: Salvar faixas
- [x] botão: Trocar
- [x] botão: Mover
- [x] botão: Restaurar
- [x] campo: Editar os critérios de
- [x] campo: Novo parâmetro
- [x] campo: Peso (%)
- [x] campo: Trocar a medida para
- [x] campo: Mover para o parâmetro
- [x] campo: Novo subparâmetro
- [x] campo: Mede
- [x] coluna: Modo de rodízio
- [x] coluna: Forma de escolha
- [x] coluna: Lugar
- [x] coluna: Parâmetros
- [x] coluna: Usado por
- [x] coluna: Parâmetro
- [x] coluna: Peso no conjunto
- [x] coluna: Subparâmetros
- [x] coluna: Subparâmetro
- [x] coluna: Mede
- [x] coluna: Peso no parâmetro
- [x] coluna: Peso efetivo no conjunto
- [x] coluna: Categoria
- [x] coluna: Nota
- [x] coluna: Apagar
- [x] coluna: De
- [x] coluna: Até
- [x] coluna: Valor medido
- [x] coluna: Faixa
- [x] coluna: Peso efetivo
- [x] coluna: Pontos

## grupos.php (137 linhas, 22 itens)

- [x] seção: Grupos de relógios
- [x] seção: Árvore
- [x] seção: O grupo de cada relógio
- [x] ação: renomear
- [x] ação: ordem
- [x] ação: excluir
- [x] ação: novo
- [x] ação: mover
- [x] ação: relogios
- [x] botão: Renomear
- [x] botão: " name="direcao" value="sobe" class="leve discreto" title="Subir">↑
- [x] botão: " name="direcao" value="desce" class="leve discreto" title="Descer">↓
- [x] botão: " class="leve discreto">Excluir
- [x] botão: Criar
- [x] botão: Mover
- [x] botão: Salvar grupos
- [x] campo: Novo subgrupo
- [x] campo: Mover para dentro de
- [x] campo: Novo grupo
- [x] campo: dentro de
- [x] coluna: Relógio
- [x] coluna: Grupo

## execucoes.php (139 linhas, 14 itens)

- [x] seção: Execuções do cron
- [x] botão: Filtrar
- [x] campo: De
- [x] campo: Até
- [x] campo: Situação
- [x] campo: Texto no registro
- [x] campo: Por página
- [x] coluna: Início
- [x] coluna: Duração
- [x] coluna: Situação
- [x] coluna: Registro
- [x] regra: Execuções do cron: filtro de datas, situação e texto, resumo do período e navegação de páginas
- [x] regra: período e filtros
- [x] regra: endereço desta página com os filtros atuais, trocando o que for preciso

## usuarios.php (80 linhas, 10 itens)

- [x] seção: Quem acessa
- [x] seção: Criar usuário ou trocar senha
- [x] ação: excluir
- [x] ação: salvar
- [x] botão: Excluir
- [x] botão: Salvar usuário
- [x] campo: Login
- [x] campo: Senha
- [x] coluna: Login
- [x] coluna: Criado em

## foto.php (21 linhas, 0 itens)


## cron.php (177 linhas, 25 itens)

- [x] regra: Rodar a cada minuto. O script é MUDO: não escreve nada na saída, nem em caso de erro,
- [x] regra: então o cron não manda e-mail. Cada execução fica registrada no banco (tabela cron_execucao) e aparece
- [x] regra: na página "Execuções do cron"; a última rodada e o erro, se houver, também na Configuração.
- [x] regra: * * * * *  php /caminho/relogios/cron.php
- [x] regra: --forcar   roda a manhã e a noite agora, mesmo que já tenham rodado hoje
- [x] regra: -v         mostra o registro na tela (para rodar à mão)
- [x] regra: A rodada da manhã e a da noite acontecem nos horários da Configuração, uma vez por dia cada.
- [x] regra: Os eventos personalizados disparam no minuto marcado.
- [x] regra: nada de erro na saída: avisos do PHP entram no registro
- [x] regra: erro fatal ou exceção: grava no banco; se nem o banco responder, num arquivo na pasta temporária
- [x] regra: o erro fica guardado à parte, para a Configuração mostrar, e é limpo na primeira execução sem erro
- [x] regra: toda execução fica na tabela, com ou sem atividade (página "Execuções do cron")
- [x] regra: sem a tabela (banco desatualizado) ou sem banco: o registro acima já foi para a configuração ou para o arquivo
- [x] regra: banco desatualizado: registra o que falta e não roda nada, em vez de quebrar no meio
- [x] regra: token da API inválido: o sistema inteiro para, o cron também (registra o motivo, sem escrever na saída)
- [x] regra: execuções antigas: sem atividade ficam 7 dias; com atividade ou erro, 1 ano
- [x] regra: o plano, a cada minuto: se um dia que já deveria existir ainda não existe (à 0h da segunda, a semana nova), ele é
- [x] regra: montado agora, pelo modo atual. Nos outros minutos só confere, e não registra nada.
- [x] regra: manhã ----------
- [x] regra: a escala se replaneja a partir do estado real; os outros modos só sorteiam na virada do período.
- [x] regra: O período no pulso de cada dia (e dos que faltarem) é criado pelo garantir_plano, a partir do plano.
- [x] regra: noite ----------
- [x] regra: eventos personalizados ----------
- [x] regra: dispara a ocorrência que venceu e ainda não saiu; depois de uma parada (até 7 dias), sai uma vez só
- [x] regra: saiu de um erro (guardado, ou no último registro): registra que voltou ao normal; o erro guardado é limpo no encerramento

## painel.js (196 linhas, 5 itens)

- [x] função: mostrarCamposDoTipo
- [x] função: prepararPainel
- [x] função: mostrarPainel
- [x] função: abrirNoPainel
- [x] função: aplicarResposta

## tabela.js (245 linhas, 0 itens)


## foto.js (29 linhas, 0 itens)


## api.php (1473 linhas, 52 itens)

- [ ] função `xml_de`: Gera XML sem depender da extensão php-xml
- [ ] função `param`: ---------- filtros ----------
- [ ] função `lista_param`: lista separada por vírgula, em minúsculas
- [ ] função `limite_de`: limite_<seção>, senão limite geral, senão sem limite
- [ ] função `quer_topo`: Seção geral da resposta pedida? Sem incluir=, todas. Com incluir=, só as citadas; e "relogios" vem junto sempre que alguma seção de dentro do relógio é citada.
- [ ] função `quer_rel`: Seção de dentro de cada relógio pedida? Se incluir= não cita nenhuma seção de relógio, vêm todas.
- [ ] função `no_intervalo`: data dentro de de= e ate= (aceita só a data ou data e hora)
- [ ] função `filtro_cron`: Filtro das execuções do cron a partir de $_REQUEST. Período: cron_de (maior ou igual) e cron_ate (menor ou igual), um, outro ou os dois; sem eles, de e ate gerais. Aceita data (AAAA-MM-DD) ou data e hora (AAAA-MM-DD HH:MM ou HH:MM:SS, com espaço ou T). Data só: "de" começa às 00:00:00, "ate" vai até 23:59:59. Situação: cron_situacao (ou situacao) = atividade, erro, nada ou todas. Texto: cron_busca
- [ ] função `execucao_cron`: Uma execução do cron na resposta
- [x] função `periodo_pedido`: Um relógio com tudo o que o sistema sabe dele, respeitando os filtros Período pedido em de/ate, para a linha do tempo: data (AAAA-MM-DD: "de" às 00:00:00, "ate" às 23:59:59) ou data e hora (AAAA-MM-DD HH:MM[:SS], espaço ou T). Devolve [de em segundos, ate em segundos, o filtro aplicado, o que foi ignorado].
- [x] função `resumo_do_tempo`: Resumo do tempo para a resposta: cada estado com segundos, texto ("13d 3h") e porcentagem, e as marcações por tipo
- [x] função `linha_legivel`: Uma linha da linha do tempo para a resposta
- [ ] função `manutencoes_legiveis`: Manutenções para a resposta: o momento exato vira data e hora legível (por dentro ele é um instante em segundos)
- [ ] recurso: ajuda
- [ ] recurso: avisos
- [ ] recurso: baterias
- [ ] recurso: config
- [ ] recurso: cron
- [ ] recurso: foto
- [x] recurso: historico
- [ ] recurso: hoje
- [ ] recurso: manutencoes
- [ ] recurso: migracoes
- [x] recurso: plano
- [ ] recurso: relogios
- [ ] recurso: tudo
- [ ] recurso: usuarios
- [x] parâmetro: ate
- [ ] parâmetro: busca
- [ ] parâmetro: compacto
- [ ] parâmetro: cron_
- [ ] parâmetro: cron_busca
- [ ] parâmetro: cron_situacao
- [x] parâmetro: de
- [ ] parâmetro: disponivel
- [ ] parâmetro: em_uso
- [ ] parâmetro: foto
- [ ] parâmetro: id
- [x] parâmetro: limite
- [ ] parâmetro: limite_
- [ ] parâmetro: nome
- [x] parâmetro: ordem
- [x] parâmetro: pagina
- [x] parâmetro: relogio
- [x] parâmetro: relogio_id
- [ ] parâmetro: situacao
- [ ] parâmetro: tipo
- [x] parâmetro: estado
- [ ] parâmetro: excluir
- [ ] parâmetro: incluir
- [x] parâmetro: linha_estado
- [ ] parâmetro: subtipo

## operacoes.php (1122 linhas, 8 itens)

- [ ] função `numero_br`: Número digitado com vírgula ou ponto, em duas casas; null se não é número
- [ ] função `pct_br`: Porcentagem para mostrar: "12,5%"
- [ ] função `op_criterios`: Critérios de escolha (página Critérios e API recurso=criterios), por lugar da árvore. Cada lugar — todos os relógios (""), um grupo ("g:<id>") ou um relógio ("r:<id>") — pode ter o seu conjunto: parâmetros com peso no conjunto (somam 100), cada um com subparâmetros (peso no parâmetro, somam 100), cada um com faixas. Incluir abre espaço na proporção dos outros do mesmo conjunto (ou do mesmo parâmet
- [ ] função `op_grupos`: Grupos de relógios (página Grupos e API recurso=grupos): a árvore e o grupo de cada relógio. Ações: novo, renomear, mover, ordem, excluir, relogios. Devolve: ok, mensagem, erros.
- [ ] função `op_relogio`: Um relógio (páginas Hoje e ficha, e API recurso=relogio): salvar (cria sem id, altera com id), excluir, foto, remover_foto, usando (usando hoje), carga (leitura de %), sessões no pulso, no winder e no sol (pulso_inicio, pulso_fim, pulso_periodo e os mesmos de winder e sol), rodizio_inicio, rodizio_fim, e os lançamentos simples (corda, revisao, pilha...). Campo inválido não para o salvar: fica o va
- [ ] função `op_rodizio`: O rodízio (página Hoje e API recurso=rodizio): modo (troca o modo e os campos dele, a forma de escolha do modo e a garantia de rodízio; refaz o plano), resortear (de amanhã em diante, se o dia já começou no pulso; senão, de hoje) e resortear_hoje (inclusive hoje: o sorteado passa a ser o do pulso). Devolve: ok, mensagem, erros.
- [x] função `op_config`: Configuração (página Configuração e API recurso=config): salvar (canais, horários, agenda, modelos de mensagem, limite do solar), evento_salvar e evento_excluir (eventos personalizados), testar_manha e testar_noite (mandam a mensagem agora), teste_agenda_criar e teste_agenda_remover, sincronizar (a agenda). Devolve: ok, mensagem, erros (e id, no evento salvo).
- [ ] função `op_usuarios`: Usuários do site (página Usuários e API recurso=usuarios): salvar (cria, ou troca a senha de quem já existe; senha de pelo menos 6 caracteres) e excluir (não o usuário conectado, e sobra pelo menos um). $atual: quem está fazendo. Devolve: ok, mensagem, erros.

## lib.php (2902 linhas, 67 itens)

- [ ] função `migracoes_pendentes`: Migrações que ainda não foram aplicadas neste banco, na ordem
- [ ] função `aplicar_migracoes`: Aplica as migrações pendentes, na ordem, parando na primeira que falhar. Devolve o que aconteceu.
- [ ] função `sql`: Executa com prepared statement. Devolve o resultado de um SELECT, ou true nos outros comandos.
- [ ] função `cfg`: Configuração em memória: lida do banco uma vez por requisição
- [ ] função `periodos_uso`: Quando o relógio esteve no pulso, em pares de timestamps [início, fim]. Dias de antes do controle de horário (sem período gravado para NENHUM relógio) usam o registro de uso do dia, no horário de uso padrão. Dia que já tem período de qualquer relógio é controlado pelos períodos.
- [ ] função `periodos_carga`: Quando o relógio esteve carregando no winder ou no sol ($tipo), em pares [início, fim]. Sessão aberta: no winder, vai até agora (não fecha sozinho); no sol, até agora ou até o fim da tarde (sol_fim), o que vier antes. Carregam, mas não são uso: não entram nos períodos no pulso.
- [ ] função `horas_no_pulso`: Horas no pulso entre dois instantes
- [ ] função `no_pulso_em`: O relógio estava no pulso nesse instante?
- [ ] função `estado_agora`: Estado de agora e desde quando: em uso desde o início do período atual, em repouso desde o fim do último
- [ ] função `horas_dia_de_uso`: Horas de um dia de uso (das 7h às 22h = 15 horas). O decaimento em uso é por dia de uso, não por 24 horas.
- [ ] função `estado`: Estado real de um relogio a partir do historico de eventos
- [ ] função `gerar_periodo`: Modos 1 a 4: sorteia o periodo que contem $hoje e grava no plano. Semana e fim de semana: cada bloco pode sortear um relógio por dia (semana_cada_dia, fds_cada_dia) em vez de um para o bloco. Por dia da semana: o dia pode ter um relógio fixo (dia_N = "r:<id>"); indisponível, sorteia entre os do mesmo tipo.
- [x] função `gerar_escala`: Modo 5: simula o horizonte dia a dia a partir do estado real e grava o plano inteiro
- [ ] função `registrar_carga`: Garante o plano de hoje e o de amanhã. Sortear amanhã já de véspera é o que permite avisar para carregar antes. Leitura de carga de um smartwatch: um valor só, a carga de agora. O sistema decide o resto: - abaixo da leitura anterior e perto do que a estimativa previa: é gasto. O intervalo é separado em horas no pulso e horas guardado e dá o decaimento de uso ou de repouso; - acima da leitura anter
- [ ] função `garantir_uso`: Período no pulso de cada dia: o relógio do plano, no horário de uso. Cria o de hoje e os que faltarem desde o último registrado (até 14 dias para trás), e o registro de uso correspondente.
- [ ] função `carga_percentual`: Carga em percentual, para qualquer tipo. Devolve ["pct" => número ou null, "de" => o que o número significa]. smartwatch: a bateria estimada automático / corda manual: a reserva de marcha que sobra (100% ao dar corda, winder, pulso, ou ao sair do pulso) solar: a carga pela luz, estimada: 100% depois de um dia no sol; guardado no escuro perde 100% ÷ reserva por dia; no pulso, a luz do dia repõe o q
- [ ] função `ultimo_uso`: Última vez no pulso: o fim do último período (ou agora, se está no pulso), e também o "usei no pulso, fora do rodízio". Devolve o timestamp, ou 0 se nunca.
- [ ] função `tipos_de_aviso`: Tipos de aviso: os fixos e os eventos criados na Configuração, com a chave "ev<id>"
- [x] função `descricao_repeticao`: "todo dia 20:00", "dom, qua 20:00", "dia 1, 09:00", "a cada 90 dias, 09:00", "25/09/2026 14:00"
- [x] função `ocorrencias`: Ocorrências de um evento personalizado entre dois instantes (timestamps), em ordem
- [x] função `ocorrencias_agenda`: Ocorrências dos eventos personalizados ativos dentro da janela da agenda, no formato das manutenções
- [ ] função `sessao_carga`: Sessões de carga: automático no pulso fora do rodízio e no winder; solar no sol. $acao: <lugar>_inicio    abre a sessão agora (e fecha agora a de outro lugar, se estiver aberta: o relógio não fica em dois) <lugar>_fim       fecha agora <lugar>_periodo   registra um período já passado ($ini e $fim: AAAA-MM-DD HH:MM, com espaço ou T) <lugar>: pulso, winder ou sol. A carga sobe pelo tempo exato, pela
- [x] função `formato_intervalo`: Tempo entre dois instantes, curto, pelo calendário: a partir de um ano, anos meses dias ("2a 3m 10d"); de um mês, meses dias ("1m 10d"); de um dia, dias horas ("5d 3h"); de uma hora, horas minutos ("2h 40m"); menos, minutos ("30m"). Unidade zerada não aparece; menos de um minuto: "" (quem chama decide o que mostrar).
- [x] função `historico_filtrado`: Linha do tempo filtrada, usada pela página do histórico e pela API (para as duas darem o mesmo número): $de e $ate em segundos (0 e PHP_INT_MAX: sem limite); entra a linha que cruza o período. $estados: lista de estados (rodizio, pulso, winder, sol, repouso, marca), vazia = todos. Devolve as linhas, o tempo em cada estado dentro do período (o trecho que atravessa uma borda conta só a parte de dent
- [ ] função `formato_falta`: Quanto falta até um instante ("1m 10d", "2h 40m", "30m"). Já passou: "atrasado 3d". Menos de um minuto: "agora".
- [x] função `previsao_smart`: Previsão da carga de um smartwatch: quanto dura se usar a partir de hoje, quando chega a 20% parado, com quanto entra no próximo rodízio e quanto precisa, e a confiança da conta. Devolve as frases (as do painel) e os mesmos números, separados.
- [ ] função `usuario_autenticado`: O usuário do site que veio no pedido (HTTP Basic, com a senha conferida), ou "" se não veio ou não confere. Usado pelas páginas (auth.php) e pela API (a escrita exige o login do site ou o token).
- [ ] função `selecao_do_modo`: A forma de escolha (seleção) de um modo de rodízio: cada modo guarda a sua (selecao_<modo>); sem ela, vale a escolha geral de antes (selecao_modo), e sem nenhuma, inteligente com sorteio. A escala inteligente escolhe sempre pela maior nota.
- [ ] função `redistribuir`: Redistribui pesos na proporção de cada um para somar $alvo (100): usada ao excluir (os que ficam crescem) e ao incluir (os que já existiam abrem espaço). Duas casas; a sobra de arredondamento vai para o maior, para a soma fechar exata. $pesos: [id => peso]. Todos zerados: divide igual.
- [ ] função `grupos_todos`: Grupos de relógios: todos, por id (lidos uma vez por requisição; $recarregar depois de mudar a árvore)
- [ ] função `grupo_cadeia`: Os grupos de cima para baixo, da raiz até o grupo $id: [id, id, ...] (vazio: na raiz ou grupo que não existe)
- [ ] função `grupos_em_ordem`: Os grupos em ordem de árvore (cada um seguido dos seus subgrupos): [[id, profundidade], ...], profundidade 0 = na raiz
- [ ] função `mesmo_nome`: Dois nomes iguais, sem diferenciar maiúsculas de minúsculas, inclusive acentuadas (É = é), e sem depender do mbstring
- [ ] função `grupo_caminho`: O caminho de um grupo por extenso: "Smartwatch › Huawei › HyperOS"
- [ ] função `criterios_config`: Os critérios cadastrados, por lugar da árvore: [chave do lugar => [parâmetros, cada um com os subparâmetros, cada um com as faixas]]. Chave: "" (todos os relógios), "g:<id>" (um grupo) ou "r:<id>" (um relógio). Lidos uma vez por requisição.
- [ ] função `escopo_chave`: A chave do lugar de um parâmetro: "" (todos), "g:<id>" ou "r:<id>"
- [ ] função `escopo_texto`: O lugar por extenso: "todos os relógios", o caminho do grupo, ou "relógio: nome"
- [ ] função `conjunto_do_relogio`: O conjunto de critérios que vale para um relógio: o mais perto dele que existe (o dele; senão o do grupo dele; senão o do grupo de cima; ... ; senão o de todos). Devolve a chave do lugar, ou null se não há conjunto nenhum.
- [ ] função `conjunto_herdado`: O conjunto que um lugar herda quando não tem o seu: o do grupo de cima mais perto que tenha, ou o de todos
- [ ] função `metricas_reais`: As medidas de um relógio agora, para os critérios. null: não se aplica a este relógio (sai da conta).
- [ ] função `nota_de_metricas`: A nota de 0 a 100 de um relógio nos critérios, com a conta inteira, pelo conjunto que vale para ele (conjunto_do_relogio). Para cada subparâmetro com medida: a faixa em que o valor cai dá a nota. O parâmetro é a média das notas dos seus subparâmetros, pelos pesos deles; a nota final é a média dos parâmetros, pelos pesos deles. Subparâmetro sem medida (ex.: autonomia num relógio que não é smartwatc
- [x] função `linha_do_tempo`: Linha do tempo de um relógio, do primeiro registro até agora: trechos contínuos, cada um com o seu estado — em uso pelo rodízio, em uso fora do rodízio, no winder, no sol ou em repouso (o resto) —, e no meio as marcações instantâneas (corda, leitura de carga, troca de pilha, revisão, marcações antigas). Onde dois estados se sobrepõem, vale o pulso, depois o winder, depois o sol. Cada linha: inicio
- [x] função `corta_texto`: Corta um texto em até $max caracteres sem partir acento (não depende da extensão mbstring, que pode não estar instalada)
- [ ] função `relogios_em_uso`: Relógios no pulso agora pelo rodízio (ids). O automático no outro braço (sessão no pulso) não conta: ele está ali de propósito, e é isso que a correção de "dois relógios em uso" precisa ignorar.
- [ ] função `pulso_rodizio`: Relógio do rodízio no pulso. O padrão continua: o relógio do dia fica no pulso do início ao fim do horário de uso. rodizio_fim     (Tirou) encerra agora o período no pulso, sem mudar o relógio do dia — vale para qualquer relógio que esteja no pulso pelo rodízio (inclusive o de um dia que ficou marcado a mais) rodizio_inicio  (Pôs) volta a contar a partir de agora até o fim do horário de uso; só o 
- [x] função `usar_hoje`: "Usando hoje": o relógio escolhido vira o do rodízio pelo resto do período atual, e a escala é refeita a partir do dia seguinte, já contando esse uso
- [x] função `garantir_plano`: Nos modos de semana (um por semana, semana e fim de semana, por dia da semana), o plano vem montado até o domingo desta semana, para se saber durante a semana o que vai ser usado; a semana seguinte é sorteada quando ela começa. No domingo, a segunda já sai sorteada, para a mensagem de véspera (preparar o relógio de amanhã) ter o que dizer. Aleatório todo dia: hoje e amanhã. A escala inteligente ge
- [ ] função `hoje_comecou`: O dia de hoje já começou no pulso: algum período do rodízio de hoje já começou (mesmo que depois tenha tido "Tirou"). Aí trocar o modo ou sortear de novo não mexe em hoje: o relógio que está (ou esteve) no pulso continua sendo o do dia.
- [ ] função `trocar_modo`: Trocar de formato apaga o plano atual e gera um novo. Se o dia de hoje já começou no pulso, hoje fica com o relógio do dia (só este dia, marcado como escolhido, para a escala não replanejá-lo) e o plano novo começa amanhã.
- [ ] função `alertas`: Avisos de hoje: corda, sol, carga e pilha, a partir do estado real
- [ ] função `lembrete_plano`: Do lembrete do plano, fica só o que os avisos de estado não cobrem
- [ ] função `montar_mensagem_noite`: Aviso da noite: se amanhã entra outro relógio, o que fazer hoje para ele estar pronto
- [ ] função `texto_ate`: "só hoje" / "só amanhã" (conforme $quando), ou "até sexta, 25/09"
- [ ] função `contexto_mensagem`: Valores das âncoras. Com $id, os do relógio; sempre, os gerais. $extra sobrepõe.
- [ ] função `aplicar_modelo`: Troca as âncoras {nome} pelos valores e limpa o que sobra de âncora vazia no fim da linha (" — ", "()")
- [ ] função `modelo_do_aviso`: O modelo de um aviso num canal: o personalizado do tipo, se marcado e preenchido; senão a mensagem padrão do canal
- [ ] função `mensagem_telegram`: Telegram: cada aviso é uma mensagem pelo seu modelo; as do mesmo horário vão agrupadas, separadas por linha em branco. Sem avisos, não há mensagem.
- [ ] função `enviar_em_partes`: Envia o grupo de mensagens. O Telegram aceita uns 4.000 caracteres por mensagem, e a API de alerta recebe o texto na URL (GET): passando de 3.000 caracteres, o grupo é quebrado entre um aviso e outro e as partes vão uma atrás da outra. Devolve quantas partes falharam.
- [x] função `evento_formatado`: Evento da agenda pelo seu modelo: a primeira linha é o título, o resto é a descrição
- [x] função `preparo_vespera`: O que fazer na noite anterior, conforme o tipo. Usado na mensagem da noite e no evento da agenda.
- [ ] função `registrar_evento`: Usado pela página e pela API
- [x] função `manutencoes`: Tudo o que precisa ser feito daqui pra frente, com data projetada. Rotina (corda, sol, carga) só dentro da janela; manutenção (pilha, revisão) sempre.
- [x] função `sincronizar_agenda`: Deixa a agenda igual à lista de manutenções: cria o que falta e apaga o que deixou de valer. Eventos que já passaram ficam na agenda como histórico.
- [x] função `agenda_teste_criar`: Evento de teste na agenda: daqui a 10 minutos, com os modelos aplicados a um relógio de exemplo. Devolve a mensagem para a tela; o id do evento fica na configuração para poder removê-lo.
- [ ] função `resumo_relogio`: Linhas de estado e ações que fazem sentido para cada tipo. Usado nos cartões e na ficha.
- [ ] função `salvar_foto`: Grava a foto vinda do navegador (data URL já reduzida). Usado no cadastro e na ficha.
- [ ] função `fotos_versao`: Versão da foto para a URL: muda quando a foto muda, então o navegador pode guardar em cache

## estilo.css (736 linhas, 0 itens)

