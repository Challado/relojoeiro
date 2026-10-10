# Teste de paridade entre os bancos

O sistema tem de se comportar igual no MySQL/MariaDB, no PostgreSQL e no SQLite. Este teste confere isso de ponta a ponta,
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
   define("DB_HOST", "127.0.0.1");
   define("DB_PORTA", $b === "pgsql" ? 5432 : 3306);
   define("DB_NOME", "relogios_teste");
   define("DB_USUARIO", $b === "pgsql" ? "postgres" : "root");
   define("DB_SENHA", "...");
   define("DB_ARQUIVO", "/tmp/relogios_teste.sqlite");
   define("API_TOKEN", "token-de-teste-123");
   define("FUSO", "America/Sao_Paulo");
   define("MSG_ENDPOINT", "");
   define("MSG_DESTINATARIO", "");
   define("MSG_TITULO", "Relógios");
   ```

2. Para cada banco: o banco vazio, `php instalar.php`, `php criar_usuario.php teste senha123`, e um servidor com a
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
dentro dos textos, como "há 3 min", e os arredondamentos bem na fronteira, como 23,499 e 23,501.
