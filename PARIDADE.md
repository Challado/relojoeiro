# Paridade: o sistema antigo no sistema novo

Situação desta entrega, conferida contra o código do sistema antigo (o PORTE.md tem a lista detalhada, item por item) e, nas telas,
por captura lado a lado com o sistema antigo rodando.

## Como o sistema está montado

- O `api.php` é o back-end único: toda leitura e toda gravação passa por ele (com o token, ou com o login do site).
- As páginas são só a tela. O PHP de cada uma confere o login e entrega o esqueleto; o JavaScript dela chama o `api.php` e monta o
  HTML das telas do sistema antigo com a resposta. Nenhuma página lê o banco nem chama as operações direto. Os scripts vão com a data do
  arquivo no endereço (o navegador não usa um guardado em cache depois de uma atualização).
- `api.js` (a chamada à API, o envio dos formulários e as funções de formato), um script por página (`hoje.js`, `configuracao.js`,
  `historico.js`, `execucoes.js`, `usuarios.js`, `criterios.js`, `grupos.js`, `cadastros.js`) e os do antigo: `painel.js` (o painel do
  relógio, agora montado a partir da API), `tabela.js` (o do antigo; reaplica os filtros quando as linhas chegam da API) e `foto.js` (o
  do antigo, sem mudança).
- Banco desatualizado: a API responde 503 e a página leva para a Configuração, que mostra o que falta e aplica com um botão.

## Pronto e testado

- **Telas do sistema antigo**, com o menu dele (Hoje, Configuração, Critérios, Grupos, Execuções do cron, Usuários) e Cadastros no fim,
  a única página que o antigo não tinha:
  - Hoje: o quadro do dia com a foto (Agora, Carga, Última leitura, Situação, Próxima manutenção, Compra, Garantia, Código), "Hoje é dia
    de" com o botão de cada aviso, "Próximos dias" com o lembrete, o modo de rodízio fechado com "editar" (os modos e os blocos vêm do
    cadastro: cada bloco escolhe o grupo ou um relógio fixo e "sortear um por dia"; na escala, o período), sortear de novo, a tabela com
    filtro e ordenação em cada coluna e o total, e o painel da direita (abre sem recarregar, fecha com Esc ou ×, remonta depois de gravar
    mantendo os quadros abertos e a rolagem).
  - Painel e ficha: Agora, Previsão, Rodízio (nota, "ver a conta", quando entra, "Usando hoje"), Compra, Próximas manutenções, Lançar
    (cada tipo de lançamento do relógio; período que já passou; a leitura com valor), "Editar cadastro" (os campos do grupo escolhido,
    a compra agrupada), gráfico de carga e o resumo do histórico. Além do antigo: o bloco Autonomia (cheio pelo cadastro e pela conta
    com o gasto medido, quando acaba seguindo o plano, quanto dura no pulso sem tirar e guardado), as medições do gasto junto do
    gráfico (e se cada uma entra na média) e "Dados do relógio" (o cadastro que vale para ele, com a origem de cada valor, e o
    resultado de cada fórmula agora).
  - Configuração: Geral (horários, a sessão no sol esquecida, o limite do solar, o endereço para {link}, a situação do cron), Telegram,
    Google Agenda (com o evento de teste), Mensagem padrão de cada canal com "Inserir âncora", "O que vai para onde" (cada tipo de aviso
    e cada evento personalizado, os canais e a personalizada de cada um), o cadastro do evento (só "quando dispara"; as próximas vezes),
    e "Como sai hoje" (Telegram de manhã, à noite e a agenda). Com o banco desatualizado, só o aviso e o botão de aplicar.
  - Histórico (página própria, aberta em aba nova), Execuções do cron, Usuários, Critérios e Grupos, como no antigo.
- **Mensagens pelos canais**, como no antigo: a mensagem padrão do Telegram e da agenda, a personalizada de cada aviso em cada canal, e
  as âncoras ({acao}, {relogio}, {motivo}, {carga}, {ate}, {link}...). O texto de cada aviso cadastrado é o {motivo}: "Dar corda:
  Orient 469SS058 D1SX / a reserva acaba em 3h 30min, 27/09/2026 11:53". Os eventos personalizados saem pelo modelo deles.
- **Avisos que o antigo tinha**: carga baixa em relógio guardado e informar a carga (leitura antiga ou ausente), como avisos cadastrados.
- **Cron como o antigo**: mudo; `--forcar` e `-v`; toda execução registrada, com a duração e se teve atividade (sem atividade, 7 dias;
  com atividade ou erro, 1 ano); o erro guardado para a Configuração, limpo na primeira execução sem erro, com "voltou a rodar sem erro";
  a rodada da manhã e a da noite (a escala refeita a partir do estado real, a mensagem, a agenda) e os eventos personalizados.
- **Escala inteligente, eventos personalizados, Google Agenda, previsão do smartwatch e linha do tempo** (migração v4), como descrito
  no PORTE.md.
- **Gasto medido pelas leituras** (migração v6), como no sistema antigo: cada leitura de carga é comparada com a anterior; subiu é
  recarga (novo ponto de partida), menos de 12 horas é pouco para medir, e senão o intervalo é separado em horas no pulso e guardado e
  mede o gasto em uso ou guardado. A caixa "Atualizar o gasto com esta medição" (marcada por padrão; nos avisos, sempre) decide se a
  medição entra na média. Diferente do antigo, a medição não sobrescreve o cadastro: vai para a tabela medicao, e o gasto que vale é a
  média das medições dos últimos N dias (Configuração, padrão 90), pesada pelas horas de cada uma; sem nenhuma na janela, a última; sem
  nenhuma, o informado. A função MEDIDO("uso") / MEDIDO("repouso") põe isso nas fórmulas (taxa_uso e taxa_repouso do smartwatch).
- **Autonomias em segundos**, em cada relógio da API e no recurso=autonomia (enxuto, para sistemas de fora): autonomia_prevista (cheio,
  pelo cadastro), autonomia_atual (cheio, pelo gasto medido), autonomia_estimada (quanto ainda dura seguindo o plano), restante_em_uso,
  restante_guardado, acaba_em_unixtimestamp, acaba_em_segundos e acaba_em_datacomtz (ISO 8601 com o fuso); null com o motivo. O
  "quanto falta" é simulado pelo motor (parado, no pulso direto, seguindo o plano), até 10 anos à frente.
- **Dicionário de todos os campos da API** (o que é, para que serve, que valor tem), no cabeçalho do api.php e no recurso=ajuda
  ("campos"), conferido automaticamente contra tudo o que as consultas devolvem.
- **API sem parâmetros**: devolve absolutamente tudo o que está no sistema (inclusive os critérios com a nota de cada relógio, todas as
  execuções do cron guardadas, os logins e o caminho da chave do Google); só a senha dos usuários fica de fora. **Filtros em qualquer
  consulta**: incluir e excluir partes da resposta, e em qualquer lista, por qualquer campo (igual, de, até, contém, diferente, vazio),
  busca no item inteiro, ordem, página e campos mostrados (f[lista][campo], busca[lista], ordem[lista], limite[lista], pagina[lista],
  mostrar[lista]), com exemplos no cabeçalho do api.php e no recurso=ajuda.
- **API**: além do "tudo" e dos recursos de antes, `hoje`, `ficha`, `config`, `cron`, `arvore` e `cadastros` (o que cada tela mostra),
  `historico`, `previsao`, `plano`, `eventos` e `agenda`; na escrita, a ação `modo` do rodízio, `relogios` da árvore (o grupo de cada
  relógio) e os canais da Configuração. Tudo no cabeçalho do `api.php` e no `recurso=ajuda`.

## Corrigido no caminho

- O automático sem corda pela coroa (o campo "Aceita corda pela coroa" desmarcado) recebia o aviso "Dar corda" e o botão Corda.
  Como no antigo, agora recebe "Pôr no winder" (resolvido pelo "Pôs no winder"), e o botão de corda só aparece para quem aceita
  corda. Sem regra fixa no código: os tipos de lançamento e os avisos ganharam o "vale quando" (migração v8), uma fórmula no
  cadastro que diz para quais relógios do grupo eles valem (corda: corda_manual; winder: corda_manual = 0).
- No sorteio por blocos ("sortear um por dia"), cada dia era escolhido pelo uso real, sem contar os dias que o próprio sorteio
  tinha acabado de pôr no plano: o mesmo relógio saía de novo a cada dia que não fosse o seguinte (três vezes na semana), e quem
  estava além da garantia de rodízio continuava "parado" a semana toda. Agora o sorteio segue os critérios recalculados a cada dia:
  cada dia do plano vira uma sessão simulada no pulso, só de uso (conta o último uso e as horas de uso; a carga não é gasta, porque
  não se sabe se o relógio vai ser carregado), e cada dia é sorteado pela nota do começo daquele dia. O ciclo (migração v7) é uma
  opção de cada modo, desligada por padrão: com ele, quem já passou na semana não volta enquanto houver relógio do bloco que ainda
  não passou (na escala, pelo período dela); dentro do ciclo, a forma de escolha decide a ordem.
- A energia do smartwatch gastava o decaimento em uso por 24 h no pulso; no antigo, é por dia de uso (15 h). Função `HORAS_USO()` (v4).
- "Sortear de novo, inclusive hoje" não trocava o relógio depois que o dia começava no pulso.
- As faixas dos critérios da energia e da sua nota (que vão até 100) não podiam ser salvas: a medida tinha perdido o limite do antigo.
  O limite vem do nome, em "(0 a N)"; a v5 põe "(0 a 100)" no nome da energia.
- Incluir um parâmetro nos critérios devolvia a seção errada (o id lido depois de uma consulta que o zera).
- O período que já passou era recusado com a data do navegador ("2026-09-26T10:00"); a API aceita o "T".

## O que o modelo novo não reproduz igual

- O relógio tem um lugar só na árvore (o grupo); o antigo tinha o tipo e o grupo separados. O cadastro mostra os campos pelo grupo.
- Os avisos são calculados por fórmula, para todo relógio do lugar deles; o antigo decidia alguns pelo plano (por exemplo, "carregar"
  só para o smartwatch que entra no rodízio nos próximos dias). Por isso a lista "Hoje é dia de" pode trazer mais avisos que a do antigo.

## Ainda a conferir

- As seções api.php, lib.php e operacoes.php do PORTE.md, item por item (as funções equivalentes existem; falta a conferência uma a uma).
