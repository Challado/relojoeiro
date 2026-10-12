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
   define("BACKUP_PASTA", "/tmp/relogios_copias_" . $b);   // a pasta tem de existir; uma para cada banco
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
cópia de segurança (comprimido, com o nome do banco no cabeçalho: uns bytes de diferença, até 2%).

Para isso, o roteiro não deixa o resultado depender do segundo em que cada passo cai:

- as sessões "há N minutos" (o sol, o winder, o carregador) contam todas de um minuto só, o do começo do roteiro; o
  carregador é fechado e aberto de novo (`alternar`) num mesmo minuto redondo, e a sessão aberta tem 1h 30min e pouco quando
  é lida, longe da virada da hora cheia;
- antes de cada escrita do rodízio e dos modos (as que podem trocar o relógio de hoje), ele espera o segundo virar: a sessão
  do rodízio de cada relógio que passa por hoje dura ao menos 1 s (a que começa e acaba no mesmo segundo some, e mudaria o
  histórico e as fórmulas).
