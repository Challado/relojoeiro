# ⌚ Relógios 2 — o rodízio inteligente de uma coleção de relógios

Quem tem uma coleção de relógios conhece o problema: o favorito vai para o pulso todo dia, o resto fica na gaveta, o automático
para, o solar descarrega no escuro, a pilha acaba sem aviso e o smartwatch amanhece sem bateria justo no dia em que era a vez dele.

**Relógios 2** resolve isso. Ele decide qual relógio vai para o pulso a cada dia, avisa de manhã pelo Telegram, põe os lembretes
no Google Agenda e mantém cada relógio da coleção em ordem: com corda, com carga, com luz, com a pilha em dia e com a revisão
marcada. Tudo o que o sistema sabe fazer está no cadastro, e não no código: tipos de relógio, campos, fórmulas, avisos e
critérios de escolha se cadastram pela tela.

## O que ele faz

- **Rodízio diário com critérios de verdade.** Cada relógio recebe uma nota pelos critérios que você cadastra (carga, tempo sem
  uso, equilíbrio de uso, sua preferência, novidade...), com pesos, subparâmetros e faixas. O sorteio segue essa nota, recalculada
  dia a dia com o uso dos dias anteriores do plano. Garantia de rodízio: nenhum relógio passa de N dias parado.
- **Escala inteligente.** Monta o plano de uma semana a dois anos de uma vez, simulando dia a dia a carga de cada relógio. O
  smartwatch fica no pulso os dias que a bateria aguenta, e o plano já traz os lembretes: carregar, dar corda, pôr no sol,
  pôr no winder.
- **Modos de rodízio cadastráveis.** Blocos de dias da semana (semana e fim de semana, um relógio por dia ou um para a semana),
  sorteio por grupo, relógio fixo, quatro formas de escolha (a maior nota, sorteio pela nota, aleatório, fila) e um ciclo opcional.
- **Cada tipo de relógio com a sua física.** Reserva de marcha do mecânico (que sobe no pulso e no winder), carga de luz do solar,
  bateria do smartwatch, vida da pilha. Tudo por fórmulas, com uma versão por grupo da árvore.
- **Gasto medido pelas leituras.** Cada leitura de carga do smartwatch é comparada com a anterior: separa as horas no pulso das
  horas guardado e mede o gasto real. A média das medições dos últimos dias mostra a bateria envelhecendo.
- **Avisos que se resolvem com um toque.** "Dar corda", "Carregar", "Pôr no sol", "Trocar a pilha", "Revisão", "Garantia
  vencendo", cada um com a data prevista por fórmula e o botão que resolve. Com o "vale quando", o automático sem corda pela coroa
  recebe "Pôr no winder" em vez de "Dar corda".
- **Mensagens e agenda.** Telegram de manhã (o relógio do dia e os avisos) e à noite (preparar o de amanhã), eventos no Google
  Agenda, uma mensagem padrão por canal, a personalizada de cada aviso e eventos seus com repetição própria.
- **Linha do tempo de cada relógio.** Em uso, no winder, no sol, em repouso; quanto tempo em cada estado; cada corda, carga e
  troca de pilha.
- **Uma API que devolve tudo.** O `api.php` é o back-end único: as páginas são só a tela e leem e gravam por ele. Sem parâmetros
  ele devolve tudo o que está no sistema; com filtros, qualquer lista por qualquer campo. Cada um dos quase mil campos das
  respostas está explicado no próprio arquivo e em `?recurso=ajuda`.

## A API em três exemplos

```sh
# as autonomias de cada relógio, em segundos, para qualquer sistema de fora
curl -u usuario:senha "http://servidor/relogios2/api.php?recurso=autonomia"

# os relógios que acabam nas próximas 24 horas, só o nome e quando
curl -u usuario:senha -g "http://servidor/relogios2/api.php?recurso=autonomia&f[relogios][acaba_em_segundos][ate]=86400&mostrar[relogios]=nome,acaba_em_datacomtz"

# lançar uma leitura de carga (e medir o gasto)
curl -u usuario:senha -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 http://servidor/relogios2/api.php
```

A documentação completa (cada consulta, cada ação de escrita, os filtros e o dicionário de todos os campos) está no cabeçalho
do `api.php` e em `api.php?recurso=ajuda`.

## Como é por dentro

- **PHP 8.1+ e MySQL/MariaDB**, sem framework e sem dependências: um motor de fórmulas próprio (com funções como `HORAS("pulso"; 30)`,
  `ACUMULA`, `MEDIDO("uso")`), a árvore de grupos, e a simulação que a escala e as previsões usam.
- **As páginas são HTML e JavaScript puro:** cada uma confere o login e traz o esqueleto; o navegador chama a API e monta a tela.
- **O cron é mudo:** roda a cada minuto, faz a rodada da manhã e a da noite uma vez por dia e registra cada execução no banco, sem
  escrever nada na saída (e sem mandar e-mail do cron à toa).
- **O banco se atualiza sozinho:** as migrações (`migracao_v*.sql`) são aplicadas pela própria página de Configuração.

## Instalação

1. Crie o banco e carregue a estrutura: `mysql -u root -p relogios2 < schema.sql`
2. Copie `config.exemplo.php` para `config.php` e preencha o banco, o token da API (obrigatório, com 10 caracteres ou mais) e, se
   for usar, a API de mensagem do Telegram.
3. Crie o primeiro usuário: `php criar_usuario.php seu_login`
4. Aponte o servidor web para a pasta (há um exemplo para o nginx em `nginx-relogios.conf`, que bloqueia os arquivos internos).
5. Ponha o cron: `* * * * * php /caminho/relogios2/cron.php`
6. Para o Google Agenda: uma conta de serviço do Google, com a chave JSON no servidor (fora do repositório) e a agenda compartilhada
   com o e-mail dela; o caminho vai na página de Configuração.

## Arquivos

| Arquivo | O que é |
|---|---|
| `api.php` | a API: toda leitura e escrita do sistema, com a documentação completa no cabeçalho |
| `lib.php`, `operacoes.php` | o motor de fórmulas, o rodízio, a escala, os avisos, as mensagens e as regras de cada gravação |
| `cron.php` | as rodadas da manhã e da noite, o plano, os eventos e a agenda |
| `index.php`, `ficha.php`, `configuracao.php`, `criterios.php`, `grupos.php`, `cadastros.php`, `historico.php`, `execucoes.php`, `usuarios.php` | as páginas (só o esqueleto) |
| `*.js`, `estilo.css` | as telas, montadas no navegador a partir da API |
| `schema.sql`, `migracao_v*.sql` | a estrutura do banco e as migrações |
| `importar.php` | importa os dados do sistema anterior |
| `PORTE.md`, `PARIDADE.md` | o registro do porte da versão anterior, item por item |
