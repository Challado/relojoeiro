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

1. Um `config.php` de teste que escolhe o banco pelo ambiente, por exemplo:

   ```php
   $b = getenv("RELOGIOS_DB") ?: "mysql";
   define("DB_TIPO", $b);
   define("DB_HOST", $b === "firebird" ? "localhost" : "127.0.0.1");
   define("DB_PORTA", $b === "pgsql" ? 5432 : ($b === "firebird" ? 0 : 3306));
   define("DB_NOME", $b === "firebird" ? "/var/lib/firebird/3.0/data/relogios_teste.fdb" : "relogios_teste");
   define("DB_USUARIO", $b === "pgsql" ? "postgres" : ($b === "firebird" ? "SYSDBA" : "root"));
   define("DB_SENHA", "...");
   define("DB_ARQUIVO", "/tmp/relogios_teste.sqlite");
   define("API_TOKEN", "token-de-teste-123");
   define("FUSO", "America/Sao_Paulo");
   define("MSG_ENDPOINT", "");
   define("MSG_DESTINATARIO", "");
   define("MSG_TITULO", "Relógios");
   ```

2. Para cada banco: o banco vazio (no Firebird, `CREATE DATABASE ... DEFAULT CHARACTER SET UTF8` pelo `isql-fb`), `php instalar.php`, `php criar_usuario.php teste senha123`, e um servidor com a
   semente dos sorteios fixa. Sem a semente, a escala desempata ao acaso e o plano muda de uma rodada para outra.

   ```sh
   RELOGIOS_SEMENTE=42 RELOGIOS_DB=sqlite php -S 127.0.0.1:8083 -t .
   ```

3. O cenário, com o mesmo ambiente (ele roda o cron como processo filho), e a comparação:

   ```sh
   RELOGIOS_SEMENTE=42 RELOGIOS_DB=sqlite php testes/cenario.php http://127.0.0.1:8083/api.php sqlite.json
   php testes/comparar.php mysql.json sqlite.json
   ```

O cenário monta as datas e horas no mesmo fuso do sistema: o `FUSO` do `config.php` da pasta do sistema (ou a variável
`RELOGIOS_FUSO`, se o sistema estiver noutro lugar). Assim ele roda em qualquer hora do dia, inclusive à noite no Brasil (quando
em UTC já é o dia seguinte) e logo depois da meia-noite. O token que ele manda é o do `config.php` de teste do exemplo acima
(`token-de-teste-123`); com outro `API_TOKEN`, passe-o na variável `RELOGIOS_TOKEN`.

Rode os bancos **ao mesmo tempo**, cada um no seu servidor: com minutos de diferença entre as rodadas, as contas que andam
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
comparação: os valores em segundos e as contas em horas das fórmulas (`HORAS("pulso"; 30)`).

**Em todas as horas do dia.** Com o [`faketime`](https://github.com/wolfcw/libfaketime) (`apt install faketime`), o roteiro roda
num horário simulado: `faketime -f "+3600s" ...` adianta o relógio do servidor `php -S`, do roteiro e do cron em uma hora. Só
o SQLite e o Firebird servem para isso (neles, o "agora" vem todo do PHP); o MySQL e o PostgreSQL usam o relógio do próprio
servidor do banco no `NOW()`. Rodando os dois juntos em cada horário (de hora em hora e logo depois das viradas: 00:02, 07:32,
18:02, 22:02), o resultado esperado é o mesmo `0 diferenças graves` em todos.
