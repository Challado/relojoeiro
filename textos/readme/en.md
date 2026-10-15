<!-- origem: 000dd3c32011fe21353e8d398c2a7af76ab0141c -->
<!-- parte 1 -->
# ⌚ Relojoeiro — Relógios 2

🌐 [Português](README.md) · **English** · [中文](README.zh-CN.md) · [हिन्दी](README.hi.md) · [Español](README.es.md) · [Français](README.fr.md)

> **The smart rotation of a watch collection.** Every day it says which watch goes on the wrist, reminds you to wind,
> charge, put in the sun and change the battery, and makes the whole collection get worn, not just the favorite. Several
> people use the same system, each with their own collections, and share whatever they want.

**[▶ Try it now, without installing anything](https://servidor2.lucas.inf.br/relojoeiro/cadastro.php)**: create a demo account on the project's server (it lasts 30
days, already with a collection ready for you to add your watches).

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)
![SQLite, MySQL/MariaDB, PostgreSQL or Firebird](https://img.shields.io/badge/database-SQLite%20%7C%20MySQL%20%7C%20PostgreSQL%20%7C%20Firebird-336791)
![no dependencies](https://img.shields.io/badge/dependencies-none-2ea44f)
![REST API](https://img.shields.io/badge/API-REST%20%2B%20JSON%2FXML-0366d6)
[![license](https://img.shields.io/badge/license-source--available-orange)](#licença)

![The Today page: the watch of the day, what to do today, the schedule of the next days and a watch's panel](docs/telas/hoje.png)

<sub>The **Today** page with a demo collection of 8 watches (common models, six weeks of use entered through the API): the
watch of the day (the Casio, on the wrist since 7 am), what to do today, the smart schedule of the next days and, on the
right, the Tissot's panel, open next to the list (in Settings it can open floating, over the page).</sub>

| | |
|---|---|
| **[Why it exists](#por-que-isso-existe)** · [The central idea](#a-ideia-central-o-sistema-não-sabe-nada-de-relógios) · [How it works](#como-funciona) · [A day with it](#um-dia-com-o-relógios-2) | **[Try it in 1 minute](#experimente-em-1-minuto)** · [The screens](#as-telas) · [The API](#a-api-uma-porta-só-para-tudo) · [Installation](#instalação) |
| [Several people](#várias-pessoas-contas-coleções-e-acesso) ·[Frequently asked questions](#perguntas-frequentes) · [How it is inside](#como-é-por-dentro) | [How to contribute](#como-contribuir) · [License](#licença) |

---

## Why this exists {#por-que-isso-existe}

Anyone who collects watches knows the scene:

- **The favorite goes on the wrist every day**, and the one you bought with so much love has been in the drawer for two months.
- **The automatic stops** because nobody wore it over the weekend, and there you go setting the time, date and moon phase again.
- **The solar runs down in the dark** of the box, without you noticing.
- **The battery dies without warning**, right on the watch you wanted to wear today.
- **The smartwatch wakes up with no battery** on the day it was its turn.
- **The mechanical's service** ("every 5 years") and **the warranty** ("does it expire in March?") stay in your memory, that is,
  nowhere.

A spreadsheet solves half of it: it remembers, but it doesn't decide. **Relógios 2** decides. It knows how long each watch has
been idle, how much charge it has now, how much you like it and how much it has been worn compared with the rest. With that it
builds the plan for the next days, or the next two years, with the reminders already in the right place.

In the morning a Telegram message arrives: *"Today: Seiko 5. Wind the Orient (the reserve runs out at 3 pm)."*
In the evening, another: *"Tomorrow: Xiaomi Band. Charge before bed: it reaches 20% tomorrow at 2 pm."*
And Google Calendar has the Casio's battery change in November and the Tissot's service in 2027.

<!-- parte 2 -->
## Try it in 1 minute {#experimente-em-1-minuto}

**Without installing:** create a demo account at
[servidor2.lucas.inf.br/relojoeiro](https://servidor2.lucas.inf.br/relojoeiro/cadastro.php) (it lasts 30 days; the account is born
with a collection and the starter set, and only you see what you add to it).

**On your machine:** all you need is PHP 8.1+ with the `sqlite3` extension: no web server and no database server.

```sh
git clone https://github.com/Challado/relojoeiro.git && cd relojoeiro
cp config.exemplo.php config.php
```

In `config.php`, change two lines to use SQLite (the database file sits next to the folder):

```php
define("DB_TIPO", "sqlite");
define("DB_ARQUIVO", __DIR__ . "/../relogios.sqlite");
```

```sh
php instalar.php --lingua=en  # the structure and the initial set (in English)
php criar_usuario.php you     # asks for the password
php -S localhost:8000         # and open http://localhost:8000
```

Register some watches in **Today → New watch**. To build the schedule without waiting for the cron, run `php cron.php --forcar`.

> PHP's built-in server (`php -S`) is only for trying it out: it serves any file in the folder, including `config.php`. For
> real use, follow the [Installation](#instalação).

---

<!-- parte 3 -->
## The API: one door for everything {#a-api-uma-porta-só-para-tudo}

`api.php` is the **only back end**. The pages are just the screen: each one's JavaScript calls the API and builds the HTML,
without doing any calculation (the summaries, percentages, totals and statuses, like the Plan summary and the cron status, come
ready from the API; the screen keeps only what is its own: filtering, sorting and adding up what the table's filters leave in
view). That's why **everything the screen does, a script can do too**: automations, phone shortcuts, Home Assistant
integration, a widget.

The API also does what comes before and around the screens: it **installs the database** (`recurso=instalacao`), **creates
the first user** (`recurso=usuarios`, with the token), **imports the previous system** (`recurso=importacao`), the **open
sign-up** (`recurso=cadastro`, without login), the **accounts** (`conta`, `colecoes`, `acessos`, `convites`, `pedidos`,
`aprovacoes`) and does the **pages' login** (`recurso=entrar`: when the login drops with the page already open, the API asks for the user and password and sends them
back to the page; on opening, the page itself already asks, before sending anything).
The command-line scripts (`instalar.php`, `criar_usuario.php`, `importar.php`) are shortcuts: they call the same API
operations.

Outside the API there is only the **cron** (`cron.php`, the system's clock: it builds the plan, opens and closes the day's
session, sends the messages, syncs the calendars, fires the events and looks after the documents' copies), which uses the
same functions the API uses; what can be asked for on demand has an action in the API (the destinations' `testar`, the
calendars' `sincronizar`, `resortear`...), and what it did shows in `recurso=cron`.

**Authentication:** the site login (HTTP Basic), a **personal token** (created in **My account**, it works as your login) or
the server token (the `API_TOKEN`, it works as the master), in the `X-Api-Token` header or in `?token=...`. **The
collection:** `colecao=<id>` (without it, the one in the menu selector, otherwise your first one); one you don't see answers
404. **Deleting** asks for `confirmar=1`: without it, the response (409) says what would go along and nothing is deleted.
**Format:** JSON by default, or XML with `formato=xml`. **Language:** `lingua=en` (or the `Accept-Language` header): the
messages, the help and the rebuild come in that language.

```sh
# everything in the system, without the photos
curl -u user:password "http://server/relojoeiro/api.php?foto=nao"

# what the Today page shows
curl -H "X-Api-Token: $TOKEN" "http://server/relojoeiro/api.php?recurso=hoje"

# the watches that run out in the next 24 hours, only the name and when
curl -u user:password -g "http://server/relojoeiro/api.php?recurso=autonomia&f[relogios][acaba_em_segundos][ate]=86400&mostrar[relogios]=nome,acaba_em_datacomtz"

# log a charge reading (and measure the consumption)
curl -u user:password -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 http://server/relojoeiro/api.php

# put it in the sun now... and take it out later
curl -u user:password -d recurso=lancamento -d acao=iniciar  -d relogio_id=4 -d tipo=sol http://server/relojoeiro/api.php
curl -u user:password -d recurso=lancamento -d acao=encerrar -d relogio_id=4 -d tipo=sol http://server/relojoeiro/api.php

# test a formula on two watches, without saving
curl -u user:password -G "http://server/relojoeiro/api.php" -d recurso=calcular --data-urlencode "expressao=energia * 2" -d relogio=10,12
```

**Queries** (`GET`, with `recurso=`):

| Resource | Returns |
|---|---|
| *(none)* | everything stored: the tree, the fields, the entry types, the formulas, the alerts, the watches (with the values, the calculated formulas, the alerts, the entries, the forecast, the photo and the documents' data), the document categories, the modes, the plan, the settings, the events, the calendar, the criteria, the cron, the users (with the password hash: the password doesn't exist in the system) and the migrations. `foto=nao` leaves the photos out. |
| `hoje` | what the Today page shows: the watch of the day, the alerts, the next days, the modes, the collection table and its totals (how many watches, available, in use and the price paid) |
| `ficha` | what a watch's panel shows (`relogio=<id>`; without it, just a new watch's record): now, charge, score, forecast, battery consumption, how many documents in each category, recent marks, record |
| `proximos` | the Today page's Next days, one page (`pagina=`, `por_pagina=`; without it, the "Show up to" in Settings), with the free days in between |
| `rodizio_manual` | the Manual rotation: the days of a period (`de=`, `ate=`) 7 at a time (`pagina=`), each with what the plan has now and the selector's starting choice (the watch chosen by hand, `livre` or `auto`: Decide for me), the available watches and the Decide for me criterion |
| `plano` | the saved plan, day by day, with the reason for each choice, the period summary as the Plan page shows it (how many days each watch has, the percentage, the next day, how many by hand, who was left without a day) and the `dia_a_dia` one page at a time (`pagina=`, `por_pagina=`, `relogio=`). `de=` and `ate=` (YYYY-MM-DD; without them, the whole plan) or `dias=N` (the next N days; `dias=todos`, from today to the end) |
| `avisos` | the alerts of all watches, from the most urgent to the furthest away |
| `previsao` | the battery forecast of the watches with charge readings (`relogio=` one id or several separated by commas; without it, all) |
| `autonomia` | how long each watch lasts, in seconds, trimmed for outside systems (`relogio=` one id or several; without it, all) |
| `historico` | a watch's timeline, with the time in each state. `relogio=` (one id or several; `relogio_id` also works; without it, all), `de=` and `ate=` (date, or date and time: the stretch that crosses the period comes in), `estado=` (`rodizio`, `pulso`, `repouso`, `marca` or a session type like `winder` and `sol`; one or several; `linha_estado` also works), `ordem=` (`desc`, the default, or `asc`), `limite=` (default 100, maximum 1000) and `pagina=` |
| `criterios` | the criteria sets and each watch's score with the calculation |
| `eventos`, `destinos`, `agendas` | the custom events; the message destinations, with what each one receives and today's preview; the calendars, with what has to be in each one |
| `ics` | the calendar of an ICS link calendar (without login, by the link's key) |
| `config`, `cron` | the collection's settings (with the summary of the destinations and calendars and the cron status: never ran, stopped or running); the cron runs, with the period's summary. In `cron`: `de=` and `ate=` (date, or date and time), `situacao=` (`atividade`, the default; `erro`, `nada` or `todas`), `busca=` (text in the log), `limite=` (default 100, maximum 1000) and `pagina=`; each also works with `cron_` in front (`cron_de`...) |
| `arvore`, `cadastros` | the groups; everything the Definitions page shows |
| `calcular` | the result of a formula (`expressao=`) on the watches (`relogio=` one id or several; without it, all), without saving, with the parts of the calculation; a badly written formula: 400 with the errors |
| `foto` | a watch's photo (`relogio=<id>`): the image, not JSON |
| `documentos` | a watch's documents (`relogio=<id>`; without it, all of them), with the categories, the NF-e summary of each XML, the folder and the limit |
| `documento` | a document's file (`id=<id>`): to show (image, video, audio, PDF, text) or to download (the rest, or with `baixar=1`); `mini=1` is the photo's thumbnail. It serves pieces (Range), so the video can skip ahead |
| `usuarios`, `migracoes` | the master: the accounts panel, each with what it has and the password hash (to migrate with the same password: see [The users and the passwords](#os-usuários-e-as-senhas)); the others, only their own account; the migrations still to apply |
| `conta`, `colecoes`, `aprovacoes` | My account (the collections, the trees, the requests, the tokens, the Web Push devices; `parte=menu`: only the menu's part); the collections and the trees; the Approvals page |
| `cadastro` | the open sign-up, without login: whether it is open and the invitation (`convite=<token>`); no list of accounts or collections (the access request is by the code the owner gave) |
| `instalacao` | the installation status: the database, whether it is empty, installed and up to date, how many users and the next step. It also answers with the database empty, with the token |
| `backup` | the database backup: whether it is on, whether the folder works, how many are kept, the last one and the folder's copies; `arquivo=` downloads one |
| `importacao` | what the import of the previous system uses: the old database server (without the password), whether PHP has MySQL and whether this database already has watches |
| `entrar` | the pages' login: without a login, 401 (the browser asks for the user and password); with a login, it goes back to the `volta=` page (302) or says who logged in |
| `ajuda` | the whole documentation: each query, each write, each field of each response and the engine's functions |
| `manual` (or `ajuda&parte=manual`) | the text of the **Help** page (the sections of this README for users: from the central idea to the frequently asked questions), in markdown, whole and section by section, with each one's title, level and anchor, and already in HTML with the table of contents (the Help page is built from it) |
| `reconstrucao` (or `ajuda&parte=reconstrucao`) | the script to **rewrite the whole system from scratch** (see [Rewrite the system from scratch](#reescrever-o-sistema-do-zero)) |
| `codigo` (or `ajuda&parte=codigo`) | **the system's code as it is now**, read from disk at that moment: each file with the layer, what it is, the language, the size, the lines, the SHA-256 and the date, and what is left out on purpose (`nao_mostrados`). `arquivo=<name>` brings the content (an image in base64; for a page, also the HTML the browser receives); `cru=1` brings the raw file, without JSON, to read in the browser; `conteudo=1` brings all of them (see [Rewrite the system from scratch](#reescrever-o-sistema-do-zero)) |

**Writes** (`POST`, with `recurso=` and `acao=`), all with the same validations and messages as the screen:

| Resource | Actions |
|---|---|
| `relogio` | `salvar` (create or change), `excluir` (with the documents), `foto`, `remover_foto` |
| `documentos` | `enviar` (one or several files, with the category, the title, the date, the description and, for a photo, the thumbnail), `alterar`, `excluir` |
| `documento_categorias` | `nova`, `alterar`, `excluir` (only without documents) |
| `lancamento` | `lancar` (winding, charge, battery...), `iniciar` and `encerrar` (put on and take off the wrist, the winder, the sun, the charger), `alternar` (closes the session if it is open, opens it if it is closed: a single button on the phone), `periodo` (a period that has passed), `alterar`, `excluir`. In all of them, `relogio_id=hoje` is the watch of the day |
| `rodizio` | `usando` (I'm wearing this one today), `trocar_dia` (a day's watch), `periodo` (the watches of a period, in order: `de`, `ate`, `relogio_ids[]`, `dias_cada`), `periodo_sortear` (erases the period's days and the mode builds them again; only `de`: to the end of the plan; only `ate`: from tomorrow to it), `periodo_livre` (erases and leaves the days free, without a draw; the same dates), `manual` (the Manual rotation: `de`, `ate`, `dias[YYYY-MM-DD]` = the watch id, `auto` or `livre`), `apagar_tudo` (the whole schedule from tomorrow on goes away; `hoje=1`: from today on; the mode builds the plan again), `resortear`, `resortear_hoje`, `proxima_semana`, `modo` (change the mode) |
| `modos` | `salvar`, `ativar`, `excluir` |
| `arvore` | `novo`, `renomear`, `mover`, `ordem`, `excluir` (groups) and `relogios` (each watch's group) |
| `campos`, `avisos`, `lancamento_tipos` | `novo`, `alterar`, `excluir` (and `ordem` in the fields) |
| `formulas` | `nova`, `alterar`, `excluir` |
| `criterios` | `conjunto_criar`, `conjunto_excluir`, `param_novo`, `param_excluir`, `param_pesos`, `sub_novo`, `sub_excluir`, `sub_medida`, `sub_mover`, `sub_pesos`, `faixas`, `ordem`, `restaurar` |
| `config` | `salvar`, `evento_salvar`, `evento_excluir` |
| `destinos` | `salvar` (webhook, e-mail or Web Push, with what it receives), `testar` (`qual=`: text, morning or night), `excluir` |
| `agendas` | `salvar` (Google, CalDAV, Outlook or ICS), `sincronizar`, `teste_criar`, `teste_remover`, `novo_link` (ICS), `outlook_conectar`, `excluir` |
| `usuarios` | `salvar` (with the `senha`, or with another system's `senha_hash`; the first user, with the token, is the master) and, master only: `alterar`, `oficializar`, `prorrogar`, `voltar_demo`, `bloquear`, `transferir`, `dar_acesso`, `excluir` |
| `conta` | `alterar` (e-mail, language, password), `token_criar`, `token_revogar`, `push_inscrever`, `push_cancelar`, `push_testar`, `excluir`; master only: `email_testar`, `vapid_gerar` |
| `colecoes` | `criar`, `renomear`, `excluir`, `arvore_copiar`, `arvore_renomear`, `arvore_excluir` |
| `acessos`, `convites`, `pedidos` | `remover`, `sair`; `convidar`, `aprovar`, `recusar`, `reenviar`, `cancelar`, `aceitar`; `pedir`, `aprovar`, `recusar`, `cancelar` |
| `cadastro` | without login: `criar_conta`, `esqueci_senha`, `nova_senha` |
| `migracoes` | `aplicar` |
| `instalacao` | `instalar` (only on an empty database, with the token) |
| `backup` | `fazer` (a backup now), `restaurar` (`arquivo=`: takes the database back to that copy, first keeping one of what is there now) |
| `importacao` | `importar` (`banco=` the old database's name; `substituir=1` when this database already has watches; stopped halfway, nothing is saved) |

**Error responses** say the reason: `{"erro": "..."}` in queries, and `{"ok": false, "mensagem", "erros": [...]}` (one error
per reason) in a refused write:

| Code | When |
|---|---|
| `400` | write refused by the validations (the errors say why), or a badly written formula in `calcular` |
| `302` | `recurso=entrar` with the right login and `volta=`: it goes to the page |
| `401` | without the token or the site login, or with them wrong; without any user (or with the database empty), the response says what to do (`sem_usuarios` and `como`) |
| `403` | the backup and the migrations, by someone who isn't the master |
| `404` | unknown resource (the response brings the list of resources), the collection you don't see, or the watch, the photo, the document (or its file) or the `README.md` (in `manual`) that doesn't exist, or a file that doesn't come out through `codigo` (with `o_que_ver` for what is left out on purpose, like `config.php`) |
| `409` | a deletion without `confirmar=1` (with `impacto`: what would go along; nothing was deleted); the account with no collection at all (`sem_colecao`); or the database refused the write: a record another one still uses, a repeated value, or a value outside the database's validation rules (the rule's name comes along) |
| `413` | the upload went beyond the server's PHP limit (`post_max_size`): a file too big |
| `500` | system stopped (the `API_TOKEN` or the `FUSO` in `config.php` invalid) or an internal error |
| `503` | database out of date (only `recurso=migracoes` answers, and the `migracoes`/`aplicar` write; and `manual` and `codigo`, which don't use the database), empty database (before the installation only `instalacao` answers, and `manual` and `codigo`) or database down |

### The users and the passwords {#os-usuários-e-as-senhas}

The password isn't stored anywhere: only its **hash** is, made by PHP's `password_hash` (bcrypt). The hash has 60 characters,
`$2y$12$...`: the `$2y$`, the cost (12 means 2¹² rounds), the salt (22 characters, drawn for each password) and the result
(31). The hash doesn't turn back into a password: at login, the system calculates the bcrypt of the typed password with the
salt and cost in the hash and compares (`password_verify`). The same password gives different hashes each time, and all of
them match.

**To take the users to another system**, take each one's login and `senha_hash` (`recurso=usuarios`, by the master) and, there, check the
typed password against the hash with the language's bcrypt: each one logs in with the same password as always. `$2y$` (PHP)
and `$2b$` (the other languages) are the same calculation; if the library refuses `$2y$`, change the start to `$2b$`. **To
bring users from another system**, send the hash from there: `recurso=usuarios`, `acao=salvar`, `login` and `senha_hash`
(bcrypt `$2y$`, `$2b$` or `$2a$`, or argon2).

| Language | Check the password | Make the hash |
|---|---|---|
| PHP | `password_verify($senha, $hash)` | `password_hash($senha, PASSWORD_DEFAULT)` |
| Python (`pip install bcrypt`) | `bcrypt.checkpw(senha.encode(), hash.encode())` | `bcrypt.hashpw(senha.encode(), bcrypt.gensalt()).decode()` |
| Node (`npm install bcryptjs`) | `require("bcryptjs").compareSync(senha, hash)` | `require("bcryptjs").hashSync(senha, 12)` |
| Java (Spring Security) | `new BCryptPasswordEncoder().matches(senha, hash)` | `new BCryptPasswordEncoder().encode(senha)` |
| C# (BCrypt.Net-Next) | `BCrypt.Net.BCrypt.Verify(senha, hash)` | `BCrypt.Net.BCrypt.HashPassword(senha)` |

```sh
# the catalog: each one's login and hash
curl -u user:password "http://server/relojoeiro/api.php?recurso=usuarios"

# bring a user from another system, with the password they already had
curl -u user:password --data-urlencode recurso=usuarios --data-urlencode acao=salvar --data-urlencode login=maria \
     --data-urlencode 'senha_hash=$2b$12$...' http://server/relojoeiro/api.php
```

**Generic filters** work on any list of any response:

| Parameter | Does |
|---|---|
| `incluir=relogios,plano` / `excluir=motor` | only these parts, or all but these |
| `f[lista][campo]=v` | equal to `v` (or to one of several: `v1,v2`) |
| `f[lista][campo][de]=` / `[ate]=` | range of numbers or dates |
| `f[lista][campo][contem]=` / `[diferente]=` / `[vazio]=1` | text, exclusion, empty |
| `busca[lista]=texto` | the text in any field |
| `ordem[lista]=-campo` | sorts (the `-` reverses) |
| `limite[lista]=20&pagina[lista]=2` | pagination |
| `mostrar[lista]=id,nome` | only these fields |

Nested fields work with a dot, like `formulas.energia.valor`, `campos.loja.valor` and `compra.valor`. Texts are compared
ignoring case and accents.

📖 **The complete documentation** (each query, each write with an example and the dictionary of every field of the responses)
is in the header of [`api.php`](api.php) and in the API itself: `api.php?recurso=ajuda` (in the request's language). The
dictionary has a single source, the help's; the header's is generated from it (`php testes/dicionario.php --gravar`).

---

## Installation {#instalação}

**Requirements:** PHP 8.1+ with `curl` and `openssl` (the webhooks, the e-mail, Web Push, the calendars and the encrypted secrets), a web server (nginx or Apache), cron
and **one of these databases**, each through its PHP extension:

| Database | PHP extension | Version | When to choose it |
|---|---|---|---|
| **SQLite** | `sqlite3` | 3.35+ (tested on 3.53) | the simplest: a single file, no database server. More than enough for a personal collection, even on a Raspberry Pi |
| **MySQL / MariaDB** | `mysqli` | with utf8mb4 (tested on MariaDB 11.4) | your hosting already has it, or you already use it |
| **PostgreSQL** | `pgsql` | 12+, with ICU, the default (tested on 18) | you already have a Postgres running |
| **Firebird** | `pdo_firebird` | 3.0+ (tested on 3.0.11, with PHP 8.4) | you already have a Firebird running |

The system behaves the same on all four. This is checked by the parity test ([`testes/`](testes/)), which runs the same script
on the four databases and compares everything the API returns.

```sh
# 1. the code
git clone https://github.com/Challado/relojoeiro.git /var/www/relojoeiro
cd /var/www/relojoeiro

# 2. the settings: the database (DB_TIPO), the API token, the time zone, the documents folder and, if you want, the e-mail,
#    Web Push, Outlook and the open sign-up rules (see the config.php table, below)
cp config.exemplo.php config.php
nano config.php
#    the documents folder (DOCUMENTOS_PASTA), outside the published folder, writable by the PHP user:
#    mkdir -p /var/lib/relogios2/documentos && chown www-data /var/lib/relogios2/documentos

# 3. the empty database (only on MySQL, Postgres and Firebird; SQLite creates the file by itself)
#    MySQL:    mysql -u root -p -e "CREATE DATABASE relogios2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
#    Postgres: createdb -U postgres relogios2
#    Firebird: echo "CREATE DATABASE 'localhost:/var/lib/firebird/3.0/data/relogios2.fdb' USER 'SYSDBA' PASSWORD '...'
#                    PAGE_SIZE 16384 DEFAULT CHARACTER SET UTF8;" | isql-fb -q

# 4. the installation: the structure and the initial set, translated to the database in config.php
php instalar.php --lingua=en
#    the initial set comes in that language, and it becomes the installation's default (without --lingua: Portuguese)
#    or through the API, with the token from config.php (the language: lingua=en, as in any request):
#    curl -H "X-Api-Token: $TOKEN" -d recurso=instalacao -d acao=instalar -d lingua=en http://server/relojoeiro/api.php

# 5. the first user, the master, with their collection (the password is asked for without being shown)
php criar_usuario.php your_login
#    or through the API, with the token:
#    curl -H "X-Api-Token: $TOKEN" -d recurso=usuarios -d acao=salvar -d login=your_login -d senha=... http://server/relojoeiro/api.php

# 6. the cron, every minute (it is silent: it writes nothing and sends no e-mail)
( crontab -l; echo "* * * * * php /var/www/relojoeiro/cron.php" ) | crontab -
```

`cron.php` has two options, to run by hand: `--forcar` runs the morning and the evening rounds now, even if they already ran
today (it builds the schedule, sends the messages and syncs the calendar, if they are on), and `-v` shows on screen the log it
saves (the same as the Cron runs page). E.g.: `php cron.php --forcar -v`.

On SQLite, put the database file (`DB_ARQUIVO`) **outside the folder the web server publishes**, in a folder the PHP user can
write to (SQLite creates the `-wal` and `-shm` files next to it).

On Firebird, the database has to be created in **UTF8** (the `DEFAULT CHARACTER SET UTF8` above). PHP needs the
`pdo_firebird` extension: on Debian and Ubuntu, the `php8.4-interbase` package (its old name) and the Firebird client
(`libfbclient2`), which comes along; in `php.ini`, `extension=pdo_firebird`. The server, the `firebird3.0-server` package (or
the installer from the Firebird site). In `config.php`, `DB_NOME` is the path of the `.fdb` file **on the database server** (or
an alias from `databases.conf`), and `DB_USUARIO`/`DB_SENHA` a Firebird user (`SYSDBA` or one created for the system).

**7. The web server.** Over the web only the pages, `api.php` and what they load (`estilo.css`, the `.js`, the images) may
open. Everything else stays blocked: the core (`lib.php`, `banco.php`, `operacoes.php`), `config.php`, the cron, the
command-line scripts, the tests, the `.sql`, `.json`, `.md` and `.sqlite` files, and everything starting with a dot, including
the `.git` folder, which comes along with `git clone`. The project brings this ready for both servers:

- **Apache:** the [`.htaccess`](.htaccess) already comes in the folder and works by itself. The folder only needs
  `AllowOverride All` in the Apache configuration, the default on almost every hosting; without it, `.htaccess` is ignored and
  nothing is blocked. It also makes the site login work when PHP runs through FastCGI (PHP-FPM, most hostings), in which case
  Apache wouldn't pass the password on to PHP.
- **nginx:** copy the block from [`nginx-relogios.conf`](nginx-relogios.conf) into your site's `server` (adjust `/relojoeiro/`
  and the PHP-FPM address). It also brings `client_max_body_size 512m`, for uploading documents (see
  [The size of uploads](#o-tamanho-dos-envios-os-documentos)).

To check, open `https://your-server/relojoeiro/config.php` and `https://your-server/relojoeiro/.git/config`: both have to give
**403**.

**8. Open it in the browser** and log in with the user you created. The initial set is already there: register your watches
in **Today → New watch**, choose each one's group and fill in the fields. In **My account**, enter your e-mail. Other people
get in through **Create account** (`cadastro.php`), through an invitation of yours, or the master creates the account in
**Users**.

### The `config.php` {#o-configphp}

| Constant | What for |
|---|---|
| `DB_TIPO` | the database: `mysql` (the default, also for MariaDB), `pgsql`, `sqlite` or `firebird` |
| `DB_HOST`, `DB_PORTA`, `DB_NOME`, `DB_USUARIO`, `DB_SENHA` | the database server (MySQL, Postgres and Firebird); `DB_PORTA` 0 is the default port. On Firebird, `DB_NOME` is the path of the `.fdb` file on the server (e.g. `/var/lib/firebird/3.0/data/relogios2.fdb`) or an alias from `databases.conf`, and an empty `DB_HOST` opens the file directly, without a server (embedded Firebird) |
| `DB_ARQUIVO` | the database file (SQLite), outside the published folder |
| `API_TOKEN` | **required**, with 10 characters or more. Without it the whole system stops and says why. |
| `FUSO` | the time zone, like `America/Sao_Paulo`. Empty or missing: PHP's (`date.timezone` in php.ini). It applies to everything, including the dates the database saves |
| `SEGREDO_CHAVE` | optional: the key that encrypts in the database the CalDAV passwords, the Google keys, the Outlook tokens and the webhook headers. Without it, the `API_TOKEN` (changing the key loses the stored secrets: just type them again) |
| `MSG_TITULO` | the title of the messages (the webhooks' `{titulo}`, the e-mail subject, the Web Push title). Default: Relógios |
| `MSG_ENDPOINT`, `MSG_DESTINATARIO` | only for migration v25: the old message API (Telegram) becomes the collection's first webhook destination |
| `CADASTRO_ABERTO`, `DEMO_DIAS`, `CONVITE_DIAS` | optional: the open sign-up (`false`: closed, only with an invitation; without the line, open), the demo account days (default 30; `0`: the account is born official) and the days an invitation or a request waits (default 7) |
| `EMAIL_METODO`, `EMAIL_REMETENTE`, `SMTP_HOST`, `SMTP_PORTA`, `SMTP_SEGURANCA`, `SMTP_USUARIO`, `SMTP_SENHA` | the e-mail: `smtp` (the given server; the security `tls`, the default, `ssl` or `nenhuma`) or `mail` (PHP's `mail()`), and the sender. Without any, no e-mail goes out |
| `IMAP_HOST`, `IMAP_PORTA`, `IMAP_SEGURANCA`, `IMAP_USUARIO`, `IMAP_SENHA`, `IMAP_PASTA_ENVIADOS` | optional: a copy of each e-mail sent in the account's Sent folder |
| `POP_HOST`, `POP_PORTA`, `POP_SEGURANCA`, `POP_USUARIO`, `POP_SENHA` | optional: only for the e-mail test to check the mailbox via POP3 |
| `VAPID_PUBLICA`, `VAPID_PRIVADA`, `VAPID_ASSUNTO` | Web Push: the key pair (the master generates it in **My account**) and the subject (`mailto:...`) |
| `OUTLOOK_CLIENTE_ID`, `OUTLOOK_CLIENTE_SEGREDO` | the application registered with Microsoft, for the Outlook calendars (see below) |
| `DOCUMENTOS_PASTA` | the folder of the documents' files (the manual, the invoice, photos, videos), **outside the published folder**, writable by the PHP user. Without it, the Documents page says it is missing and doesn't accept uploads. E.g.: `define("DOCUMENTOS_PASTA", "/var/lib/relogios2/documentos");` |
| `DOCUMENTOS_COPIA_BANCO` | optional: the documents' backup inside the database, for the whole system. `true`: every file also goes to the database; `false`: none goes; without it: each watch and each file decide. See [The watch's documents](#os-documentos-do-relógio). |
| `BACKUP_PASTA`, `BACKUP_MANTER`, `BACKUP_DOCUMENTOS` | optional: the database backup (see [Backup](#cópia-de-segurança)). The folder, **outside the published folder** (without it, there is no automatic backup); how many are kept (default 7); and whether it takes the content of the documents stored in the database (default: no). |
| `BACKUP_ATIVO` | optional: `false` turns the backup off: no automatic copy, no **Make a backup now** and no **Backup** box in Settings (not even the warning that the folder is missing). Without it: on |
| `DOCUMENTOS_LIMITE` | optional: the largest document accepted, in bytes. Without it, 100 MB (`104857600`); `0` or `-1`, no system limit. `MANUAL_LIMITE`, the old name, still works when it doesn't exist. PHP's and nginx's limits still apply (see [The size of uploads](#o-tamanho-dos-envios-os-documentos)). E.g.: `define("DOCUMENTOS_LIMITE", 524288000);` for 500 MB |
| `ANTIGO_HOST`, `ANTIGO_PORTA`, `ANTIGO_USUARIO`, `ANTIGO_SENHA` | optional, only for `importar.php` with this system on Postgres, SQLite or Firebird: where the previous system's MySQL is |
| `ALEATORIO_RANDOMORG_URL`, `ALEATORIO_ANU_URL` | optional, only for tests: the addresses of the random number sources (`https://www.random.org/integers/` and `https://qrng.anu.edu.au/API/jsonI.php`). Changed, the draws ask a test server for the numbers |
| `GOOGLE_TOKEN_URL`, `GOOGLE_API_URL`, `OUTLOOK_LOGIN_URL`, `OUTLOOK_GRAPH_URL` | optional, only for tests: the Google and Microsoft addresses the calendars use. Changed, the sync talks to a test server |
| `TEXTOS_ESTRITO` | optional, for whoever is translating: `true` makes a missing translation stop with an error (see [The languages](#os-idiomas)) |

The system reads the `config.php` in its folder. A server environment variable, `RELOGIOS_CONFIG`, points to another file
instead: it is what the parity test uses (`testes/paridade.sh`, with [`testes/config.teste.php`](testes/config.teste.php)), to
run without touching the real `config.php`. On a normal installation, don't set it.

### Destinations and calendars on the server {#destinos-e-agendas-no-servidor}

Each collection creates its destinations and calendars on the **Destinations** and **Calendars** pages. What needs setting up
on the server:

- **Telegram (a webhook):** create a bot with @BotFather (it gives the token) and find your `chat_id` (send the bot a message
  and open `https://api.telegram.org/bot<token>/getUpdates`). In **Destinations**, a webhook: method `GET` or `POST`, address
  `https://api.telegram.org/bot<token>/sendMessage`, the fields `chat_id` = the number and `text` = `{mensagem}`. Any other
  bridge (n8n, Home Assistant, your own address) works the same: the system sends `{titulo}` and `{mensagem}` where you put
  them, and considers it sent if the response is 2xx. Long messages are split into parts.
- **E-mail:** the SMTP server in `config.php` (`SMTP_HOST`, the port, the security, the user and the password; on Gmail and
  Outlook, an app password) and the `EMAIL_REMETENTE`; or `EMAIL_METODO = "mail"`, with a sendmail on the server. With
  `IMAP_*`, each e-mail sent gets a copy in Sent. The master tests it in **My account › E-mail server**: the box shows what the system read from `config.php` (the method, the sender, SMTP, IMAP and POP; the passwords only as *set* or not) and points out what is wrong or missing (no `EMAIL_METODO`, a port that does not match the security, an empty password, PHP without `openssl`...). **Test the connections** signs in to SMTP, to IMAP (and checks the Sent folder) and to POP without sending anything; **Send a test e-mail** really sends one to the address you type. Then come the result of each part and the **conversation with the servers**, line by line (without the password), to find where it stopped.
- **Web Push:** the site has to open over **https**. The master generates the keys in **My account › Web Push keys** and copies
  the two lines into `config.php`. Then each person turns on the notifications on their device, in **My account** (on the
  iPhone, first add the site to the home screen), and a Web Push destination sends to the devices of whoever it points to.
- **Google Calendar:** in Google Cloud, create a **service account** and download the JSON key; share your calendar with the
  service account's e-mail, with permission to change events; in **Calendars**, paste the key's JSON (it stays encrypted in
  the database) and the calendar ID. Only the master can, instead of the JSON, give the path of a file on the server.
- **CalDAV (Nextcloud, iCloud, Fastmail):** the calendar address, the user and the password. On **iCloud**, an **app-specific
  password** (appleid.apple.com › App-Specific Passwords) and the calendar address at `caldav.icloud.com`.
- **Outlook:** register an application in the Microsoft portal (**Entra ID › App registrations**), with the
  `Calendars.ReadWrite` permission and the redirect address `https://<your server>/relojoeiro/api.php?recurso=outlook_retorno`
  (the Calendars page shows the exact address); put the ID and the secret in `config.php` (`OUTLOOK_CLIENTE_ID` and
  `OUTLOOK_CLIENTE_SEGREDO`). In **Calendars**, **Connect to Outlook** takes you to Microsoft's page and comes back connected.
  Without the application, use an ICS link: Outlook subscribes to it.
- **ICS link:** nothing to set up. The calendar gives a secret link to subscribe to in any app (Google: **Other calendars ›
  From URL**; Outlook: **Subscribe from web**; iPhone: **Subscribed calendar**); **Change the link** turns off the previous one.

Outside the master's collections, the addresses someone types (webhook, CalDAV) must be on the internet: the system refuses
those of the server's own network (`127.0.0.1`, `192.168.x`...), so nobody uses the server to probe its network.

### The size of uploads (the documents) {#o-tamanho-dos-envios-os-documentos}

Each document goes up in one upload (several files chosen together go up one at a time), and three server limits can refuse a
big file before the system does. The system accepts up to **100 MB** per file; `DOCUMENTOS_LIMITE` in `config.php` changes
that (in bytes; `0` or `-1`: no system limit). For phone videos, think about 500 MB:

| Where | The limit | The default | For 500 MB |
|---|---|---|---|
| `php.ini` | `upload_max_filesize` (the file) and `post_max_size` (the whole upload) | 2M and 8M | `upload_max_filesize = 500M` and `post_max_size = 512M` |
| nginx | `client_max_body_size` | 1m | `512m` (already in [`nginx-relogios.conf`](nginx-relogios.conf)) |
| `config.php` | `DOCUMENTOS_LIMITE` | 100 MB | `524288000` |

The file goes straight to the documents folder; the copy in the database goes in 4 MB pieces, read one at a time: PHP's
`memory_limit` and MySQL's `max_allowed_packet` (16 MB or more) don't limit the file size. The Documents page shows the limit
that really applies (the system's, or less because of `php.ini`; with no limit at all, "of any size"). A file above it is
refused with the size message; an upload above `post_max_size` comes back with code `413` and the server's limit. On Apache
there is no limit to touch, besides `php.ini`.

### Database updates {#atualizações-do-banco}

After updating the code, if there is a new migration (`migracao_v*.sql`), the API answers `503` and the **Settings** page
shows the button to apply it (only the master applies it). The cron also stops and logs the reason, instead of running
halfway. On a new installation, `schema.sql` already brings the structure of all the migrations, and the initial set comes
from `modelo.json`, when the first user is created.

**Migration v25 (several people)** remakes the whole database: it first keeps a copy of everything in
`relojoeiro-antes-da-v25.jsonl.gz` (in the `BACKUP_PASTA`, or in the temporary folder), creates the new structure and returns
each record in the new form, with the same numbers (the watches, the documents and the entries keep their usual ids). The
oldest user becomes the master and the owner of the collection and of the tree; the others, members of the collection.
Telegram (`MSG_ENDPOINT`) becomes the first webhook destination, and Google Calendar, the first calendar. If it stops
halfway, the same button redoes it from the copy.

`schema.sql` and each migration are **a single file for the four databases**: the structure (`CREATE TABLE`, `ALTER TABLE`) is
written in MySQL's dialect, and [`banco.php`](banco.php) translates it to Postgres, SQLite and Firebird (`AUTO_INCREMENT`,
`ENUM`, `TINYINT`, `DATETIME`, the `INDEX` inside the table, `AFTER` and `MODIFY COLUMN`, which on SQLite rebuilds the table).
The data (`INSERT`, `UPDATE`) goes in SQL common to all four: no `GROUP_CONCAT`, `IF()` or `FIND_IN_SET`. `INSERT IGNORE`,
`REPLACE INTO`, `NOW()`, `LEAST`, `GREATEST`, `LENGTH`, `DATE()`, `<=>`, `LIMIT`/`OFFSET`, `SELECT` without `FROM`, the
multi-row `INSERT` and `CONCAT` are allowed (`CONCAT` without a NULL argument: on Postgres NULL becomes empty text, on MySQL
the result becomes NULL). Migrations v2 to v8 are from before that and exist only to update old MySQL databases: a Postgres,
SQLite or Firebird database is already born at the current version.

Two commands that don't exist like this in any database, `banco.php` understands and does each one's way:

| Command | What it does |
|---|---|
| `ALTER TABLE t ALTER FOREIGN KEY (coluna) REFERENCES outra(id) ON DELETE CASCADE` (or `SET NULL`) | replaces the column's foreign key (or creates it, if there was none). On MySQL, Postgres and Firebird, it looks up the old key's name in the catalog, drops it and creates the new one (on Firebird, in a transaction); on SQLite, it rebuilds the table. |
| `ALTER TABLE t DROP COLUMN coluna` | drops the column along with its foreign key and indexes (SQLite rebuilds the table; Firebird also drops first the rules that use the column). |
| `ALTER TABLE t ADD CONSTRAINT nome CHECK (condição)` | the named validation rule; if one with that name already exists, it is replaced. The existing rows have to meet the new rule, otherwise the database refuses and the previous rule stays (on Postgres and Firebird, in a transaction; on MySQL, the new one goes in first under a temporary name; on SQLite, the table is rebuilt in a transaction). |

And, so a migration that stopped halfway can run again from the same button: `ADD COLUMN` of a column that already exists and
`ADD INDEX` with the name of an index that already exists do nothing.

A migration can also have a **PHP step**, for what the SQL common to all four can't do (v16 splits a field's options text, one
per line, into rows of the `campo_opcao` table; v20, on MySQL/MariaDB, converts to `utf8mb4_unicode_ci` the tables created in
another collation). It runs right after the SQL, from the Settings button, and also in `instalar.php`.

### Differences between the databases you may notice {#diferenças-entre-os-bancos-que-você-pode-notar}

- **The order of texts on MySQL/MariaDB:** every table is born in `utf8mb4_unicode_ci`, whatever the database's default.
  MariaDB comes with `utf8mb4_general_ci`, which sorts differently from the other databases (it puts `dias_desde_compra`
  before `dias_de_carga`); an old installation created that way is converted by migration v20.
- **Case and accents:** as on MySQL, "Relógio" and "RELOGIO" are the same name, in search, in sorting and at login. On
  Postgres, this comes from an ICU collation (`ci`) that `instalar.php` creates. On SQLite, from a PHP function; that's why, in
  an outside program (the `sqlite3` command line, DB Browser), sorting or comparing those columns gives the error "no such
  collation sequence: ci". Reading and exporting work normally. On Firebird, from the `UNICODE_CI_AI` collation, on all text
  columns.
- **On Firebird:**
  - long texts (`TEXT` in `schema.sql`: the formulas, the messages, the cron log) go up to **8191 characters** (on MySQL,
    65535 bytes), because they become `VARCHAR(8191)` (the text `BLOB` comes back empty as null in PHP);
  - index and rule names over 31 characters keep only part of the full name's md5 at the end, and the `no` table is a reserved
    word: in an outside program (`isql-fb`, FlameRobin), write `"NO"`, in quotes and in capitals;
  - the multi-column unique key where one column can be empty becomes a unique index on an expression, because on Firebird
    two rows with the same value and the other column empty count as repeated (on MySQL, they don't);
  - the time of `NOW()` comes from PHP (Firebird has no per-connection time zone), in the `FUSO` from `config.php`, like the
    others.
- **The column order** of a migration with `AFTER` only applies on MySQL: on the others the new column goes to the end of the
  table (only the order of the keys in the API's JSON changes).
- **The ids** may skip different numbers on each database (MySQL reserves ids in blocks in some `INSERT ... SELECT`).

### Coming from the previous system {#vindo-do-sistema-anterior}

`php importar.php <old_database>` (or, through the API, `recurso=importacao`, `acao=importar`, `banco=<old_database>`) brings
the tree, the watches, the photos, the users, the fields and the whole history (converted into entries), in a single
transaction: if it stops halfway, nothing is saved. With `--substituir` (in the API, `substituir=1`), it first erases what
already exists in this database. [`PORTE.md`](PORTE.md) records, item by item, how each behavior of the old system was ported.

---

<!-- parte 4 -->
## How it is inside {#como-é-por-dentro}

- **Plain PHP 8.1+, with SQLite, MySQL/MariaDB, PostgreSQL or Firebird.** No framework, no Composer and no dependencies, to
  run on any hosting with PHP. Each database through its PHP extension (`sqlite3`, `mysqli`, `pgsql`, `pdo_firebird`); the
  system's SQL is written only once and [`banco.php`](banco.php) translates what changes from one database to another.
- **Its own formula engine**, with parsing, history functions, versions per group and dependencies between formulas.
- **Simulation:** the schedule and the forecasts simulate future entries and recalculate the formulas day by day, without
  saving anything.
- **Front end in plain HTML and JavaScript.** Each PHP page only delivers the skeleton; its `.js` calls the API and builds
  the screen. **Nothing goes out before the login:** the page checks the user and the password on the server before the first
  line (`pagina_login()`, in `lib.php`, called by `topo()`) and, without them, answers 401 with the browser's password prompt,
  with no menu or skeleton (if the person cancels, only "Sign in" shows, with the links **Try again** and **Create account**).
  The login works for the page and the API at the same time (the same realm). Only **Create account** and, before any user
  exists, the installation pages pass without a login. The scripts
  carry the file's date in the URL, so the browser cache doesn't serve an old version.
- **Silent and traceable cron:** it runs every minute, writes nothing to the output and logs each run in the database
  (without activity, 7 days; with activity or an error, 1 year). A fatal error goes to the database or, if not even the
  database answers, to a temporary file.
- **Loss-proof documents:** each file stays in the folder and, if asked (by the system, by the watch or by the file), whole,
  in the database; the cron recreates what disappears, copies what is missing and cleans up what is left over (see
  [The watch's documents](#os-documentos-do-relógio)).
- **A database that defends itself:** relational and normalized, with foreign keys and validation rules (`CHECK`,
  `NOT NULL`, `UNIQUE`) on all four databases: a wrong write is refused by the database itself (see
  [The database model](#o-modelo-do-banco)).
- **Fail-safe:** without a valid token or with the database out of date, the system stops and explains why, instead of running
  halfway.
- **Several people, separated by the database:** each request applies to one collection, and the database layer rewrites
  each query to see only it (and its tree); no screen or resource needs to remember to filter. A collection you don't see
  answers 404, as if it didn't exist.
- **Six languages:** every text goes through `t()`, with the Portuguese as the key and one file per language
  (see [The languages](#os-idiomas)).

### The database model {#o-modelo-do-banco}

The database is relational and normalized (since migration v16; since v25, in 60 tables): each thing in a table, each link
between tables a **foreign key**, and no list stored as text inside a field. The accounts (`usuario`), the collections
(`colecao`, with the owner and the tree's root), the members (`colecao_usuario`), the access to everything of an owner
(`acesso_dono`), the invitations, the requests, the tokens, the Web Push devices, the destinations (`destino` and one table
per type) and the calendars (`agenda` and one table per type). The tree (`no`) has the root with the owner and the groups
pointing to the one above; its definitions (fields, types, formulas, alerts, categories) point to a group of the tree; what
belongs to the collection (watches, modes, plan, events, settings, accessories) points to the collection. The optional links
became one-row tables (an accessory's watch, a block's fixed watch, a plan day's block). Deleting directly in the database
leaves nothing dangling:

| The link | When the parent is deleted | Where |
|---|---|---|
| required (the row doesn't exist without the parent) | **goes along** (`ON DELETE CASCADE`) | an account takes its collections and trees; a collection takes the watches, the modes, the plan, the destinations and the calendars; a tree takes the collections that use it; a group takes the groups inside it, the watches, the fields, the entry types, the formulas and its alerts; a watch takes the entries, the values, the photos, the documents, the plan and its own criteria; an entry takes the consumption measurement; and so on |
| optional (the column accepts empty) | **the link is undone** (`ON DELETE SET NULL`) | a custom event's watch, a mode block's group and fixed watch, a plan day's block, the entry type that resolves an alert, an accessory's watch (the accessory becomes loose) |

On screen (**Groups**, Delete), deleting a group still first passes what was its own (the groups inside, the watches, the
fields, the types, the formulas and the alerts) to the group above; the cascade applies to whoever deletes directly in the
database.

The lists became tables: the days of a mode block (`modo_bloco_dia`) and of a weekly event (`evento_dia`), the file types a
document category accepts (`documento_categoria_aceita`), the options of a list field (`campo_opcao`) and, for each
destination and each calendar, what they receive (`destino_aviso`, `destino_evento`, `agenda_aviso`, `agenda_evento`). The
mode in use is the mode's `ativo` column, and the consumption measurement reaches the watch through the entry that closed it.
The `config` table keeps only each collection's loose values, and `sistema`, those of the whole system.

Three references stay **by name**, on purpose: the formulas and the criteria cite fields and formulas by identifier (it is
the text you write, like `HORAS("pulso"; 30)`), and `destino_aviso` and `agenda_aviso` cite the alert by identifier, because
an alert can have several versions (one per group) and the choice applies to all of them. When an alert's last version is
deleted, the system deletes its rows along with it.

**The database validates what it stores** (since migration v17), with standard SQL features, and not only the system: a
wrong write is refused wherever it comes from (the screen, the API, someone editing the database directly). The API answers
`409`, with the name of the broken rule in the detail.

| Feature | What it guarantees |
|---|---|
| `NOT NULL` | what is required is filled in (the origin of each entry and of each plan day, for example) |
| `CHECK` | each value within what is valid. There are 54 rules, each with a name (`ck_<table>_<what>`) |
| closed lists (`ENUM`, which on the other databases becomes `CHECK ... IN`) | the values a column accepts: the entry type's format, the event's repetition, the origin of the entry (`manual`, `rodizio`, `importado`) and of the plan day (`sorteio`, `manual`)... |
| `UNIQUE` | what can't repeat: the identifier of a field, of a type, of a category; a document's file; **at most one mode in use** |
| `FOREIGN KEY` | the links (above) |

The `CHECK` rules, by subject:

| Subject | The database refuses |
|---|---|
| Texts | name, identifier, title, alert text, formula and settings key empty or only spaces |
| Yes/no marks | any value other than 0 and 1 (available, active, exclusive, measures consumption, used, sends, copy in the database, only for wished ones...) |
| Watches | a status in the collection outside the list (`colecao`, `desejado`, `emprestado`, `vendido`, `arquivado`); an available watch outside the collection |
| Entry types | exclusive session outside the session format; "measures consumption" outside the value format; "closes at" outside the session format |
| Entries and measurements | end before start; measured consumption (rate) outside 0 to 100%; negative hours |
| Alerts | lead time outside 0 to 3650 days; simulated hours in the schedule outside 0 to 48 |
| Criteria | weight outside 0 to 100%; score outside 0 to 100; a criteria set of a group **and** of a watch at the same time; a range that is neither numeric (with "from") nor a category; negative "from"; "to" that doesn't go beyond "from" |
| Rotation modes | smart schedule outside 7 to 730 days; weekday outside 1 to 7 |
| Custom events | what the repetition doesn't use (the day of the month outside monthly, the interval outside "every N days", the date outside "once" and "every N days") and the lack of what it uses; day of the month outside 1 to 31; interval outside 1 to 3650 days; weekday outside 1 to 7 |
| Accessories | empty name; a type outside the list (`pulseira`, `caixa`, `estojo`, `ferramenta`, `carregador`, `peca`, `outro`) |
| Documents | zero size; SHA-256 that doesn't have 64 characters; a database copy part below -1 (-1 is the thumbnail) |
| Watch photo | a type that isn't an image |
| Cron runs | end before start; negative duration |

**At most one mode in use:** the mode's `ativo` mark is 1 on the one in use and empty (`NULL`) on the others, and the column
has a unique key. Standard SQL allows repeating empty in a unique key, but not 1: two modes in use, the database refuses.

Two rules were left out because of MySQL 8, which doesn't accept `CHECK` on the `id` column (auto-increment) nor on a
foreign key column with `ON DELETE SET NULL`: "a group is not its own parent" (the system checks it, and also checks the
bigger cycles, which no `CHECK` reaches) and the ones for the optional links.

### Files {#arquivos}

| File | What it is |
|---|---|
| [`api.php`](api.php) | the API, the door for every read and write (the authentication, the format, the order of the resources, the filters, the response), with the complete documentation in the header |
| [`api/`](api/) | the API's big resources, one file each (`hoje.php`, `ficha.php`, `configuracao.php`, `desejos.php`, `estatisticas.php`, `hora_certa.php`, `passaporte.php`, `galeria.php`, `ajuda.php`...), plus its functions (`funcoes.php`) and the writes (`escrita.php`): they run inside `api.php`, and opened directly they do nothing (`.htaccess` and `nginx-relogios.conf` block the folder) |
| [`lib.php`](lib.php) | the core: login, tree, formula engine, alerts, criteria, rotation, schedule, documents |
| [`colecoes.php`](colecoes.php) | the collections in the core: the request's scope, `modelo.json`, the encrypted secrets, the `sistema` table, migration v25 and the impact of deletions |
| [`alertas.php`](alertas.php) | the destinations (webhook, e-mail, Web Push) and the calendars (Google, CalDAV, Outlook, ICS) |
| [`contas.php`](contas.php) | the accounts: the open sign-up, My account, the collections and the trees, the invitations, the requests, the tokens and the master's panel |
| [`banco.php`](banco.php) | the database: the connection to MySQL, Postgres, SQLite or Firebird, and the translation of SQL from one to another |
| [`operacoes.php`](operacoes.php) | the rules of each write: validations and messages |
| [`cron.php`](cron.php) | the plan, the day's session, the morning and evening rounds, the events, the calendar and the documents' upkeep |
| `index.php`, `plano.php`, `desejos.php`, `estatisticas.php`, `hora.php`, `acessorios.php`, `galeria.php`, `passaporte.php`, `ficha.php`, `documentos.php`, `historico.php`, `avisos.php`, `modos.php`, `configuracao.php`, `destinos.php`, `agendas.php`, `criterios.php`, `grupos.php`, `cadastros.php`, `execucoes.php`, `conta.php`, `aprovacoes.php`, `usuarios.php`, `cadastro.php` | the pages (only the skeleton; `cadastro.php` is the public one) |
| `push.js` | the Web Push service worker |
| `ajuda.php` | the help page: only the skeleton; `ajuda.js` asks the API for the text (`recurso=manual`: the README's sections for users, already in HTML, converted in `lib.php`) |
| [`pagina.php`](pagina.php) | the top with the menu and the end of the pages ; `topo()` checks the login before sending anything (`pagina_login()`) |
| `api.js`, `hoje.js`, `painel.js`, `tabela.js`, `foto.js`, ... | the screens, built in the browser from the API |
| [`estilo.css`](estilo.css) | the look |
| [`textos/`](textos/) | the languages: `funcoes.php` (the `t()` function, the list of languages, the request's language, each language's dates and numbers) and one file per language (`en.php`, `zh-CN.php`, `hi.php`, `es.php`, `fr.php`: the Portuguese text => the translation, by part); opened directly they do nothing |
| [`schema.sql`](schema.sql) | the database structure (only the structure: no data) |
| [`modelo.json`](modelo.json) | the starter set of a new collection (groups, fields, types, formulas, alerts, criteria, categories, modes, messages), with no fixed codes |
| `migracao_v2.sql` … `migracao_v24.sql` | the migrations, applied from the Settings page |
| [`config.exemplo.php`](config.exemplo.php) | the template for `config.php` |
| [`instalar.php`](instalar.php) | installs `schema.sql` on the database in `config.php`, any of them (the same as `recurso=instalacao`) |
| [`criar_usuario.php`](criar_usuario.php) | creates a user or changes the password, from the command line (the same as `recurso=usuarios`, `acao=salvar`) |
| [`importar.php`](importar.php) | imports the data of the previous system (the same as `recurso=importacao`) |
| [`reconstrucao.php`](reconstrucao.php) | the script to rewrite the system from scratch (the API's `recurso=reconstrucao`) |
| [`.htaccess`](.htaccess) | the protection of the internal files on Apache (and the site login with PHP through FastCGI) |
| [`nginx-relogios.conf`](nginx-relogios.conf) | the nginx block that protects the internal files |
| [`testes/`](testes/) | the parity test: the same script through the API on each database, and the comparer of the responses; the layout check of all the screens, from phone to computer (`layout.cjs`; `LINGUA=en` checks in a language); the check of the languages' dictionaries (`textos.php`); the assembly of each language's README (`readme.php`); and the architecture check (`arquitetura.php`: the rules the code has to follow) |
| [`docs/telas/`](docs/telas/) | the screenshots of this README |
| `README.md`, `README.en.md`, `README.zh-CN.md`, `README.hi.md`, `README.es.md`, `README.fr.md` | this README: the original, in Portuguese, and the other languages. These aren't edited by hand: [`testes/readme.php`](testes/readme.php) assembles each one by joining the translated manual (`textos/manual/<language>.md`, the sections the Help shows) and the translated rest (`textos/readme/<language>.md`: the beginning, Try it, the API, the Installation, How it is inside, How to contribute and the License) |
| [`PORTE.md`](PORTE.md) | the record of the port of the previous version |

---

## How to contribute {#como-contribuir}

Improvements are welcome, and the way is always this repository:

1. For a big change, **open an issue first** and say what you intend: it avoids work that won't go in.
2. Fork it on GitHub, create a branch and make the change. The fork is only for preparing the proposal (see the
   [license](#licença)).
3. **Follow the code's way:** plain PHP and JavaScript, no dependencies, comments and messages in Portuguese (the
   translations go in `textos/`). What is knowledge about watches goes into the starter set (`modelo.json`), not into the code.
4. Changed the database? Create a `migracao_vN.sql` and register it in `$MIGRACOES` in `lib.php`: the structure in MySQL's
   dialect, the data in SQL common to the four databases (see [Database updates](#atualizações-do-banco)).
5. Changed the API? **Explain each new field** in the help's dictionary: the `"campos"` of `recurso=ajuda`, in `api/ajuda.php`
   (the resource's section, with the field's path as the key). It is the single source: the dictionary in the header of
   `api.php` is generated from it, with `php testes/dicionario.php --gravar` (don't write in the header by hand).
6. **Run the parity test** ([`testes/`](testes/)) on the four databases before sending (GitHub also runs it, on every push to
   `main`: the parity test, the documentation and the layout, in [`.github/workflows/testes.yml`](.github/workflows/testes.yml);
   the result shows in the **Actions** tab and in the ✓ / ✗ next to each commit): `testes/paridade.sh`, with the databases
   up, has to end with `Tudo igual.` It also checks the documentation: the header is what the help generates, and every field
   of the responses is explained. It checks the architecture ([`testes/arquitetura.php`](testes/arquitetura.php), which GitHub
   also runs in a quick step, without a database): the pages only build the skeleton, the screens' network is only the API,
   the database is only touched by the core, the API and the cron, the internal files are blocked over the web, every action
   and every `config.php` constant is documented, and the other rules listed at the top of the file. And it fails if PHP gives
   any warning (Warning, Notice, Deprecated) during the round. Touched the screens? Also run the layout check (`testes/paridade.sh --layout sqlite`):
   `0 problemas`.
7. **Every new text goes through `t()`** (`api.js` and `textos/funcoes.php` have the function), with the Portuguese as the key
   and the parameters in braces: `t("Excluir {1}?", {1: nome})`, never `"Excluir " + nome + "?"`. Then translate it in the
   five files in `textos/` and run `php testes/textos.php` (the parity test runs it too): it has to end with nothing missing.
   Changed the README? Its translations carry the mark of the version they translate (the first line of
   `textos/manual/<language>.md` and of `textos/readme/<language>.md`), and `php testes/textos.php` flags the one that fell
   behind: translate the changed passage, put the new mark (`php testes/textos.php --manual` and
   `php testes/readme.php --original` give the original with it) and run `php testes/readme.php`, which assembles the
   `README.<language>.md` files again.
8. Open the pull request saying what changes for the user and how you tested it.

---

## License {#licença}

Relojoeiro is **source-available**, not free software: the code is in view, but it has an owner and rules of use. In short
(what counts is the text of [`LICENSE`](LICENSE), in Portuguese):

| ✅ You may | ❌ You may not |
|---|---|
| install and use it for yourself, your family or your company | create a new project based on this code, in whole or in part, nor rewritten in another language |
| change the code of your installation | distribute the code, original or changed, outside this repository |
| propose improvements by pull request here | offer the system as a service to other people |
| fork it on GitHub to prepare those proposals | keep a fork as a separate project |

For any other use, ask for authorization through GitHub ([Challado](https://github.com/Challado)).

> 💡 **Contribute, don't copy.** Being a Relojoeiro contributor is much better than forking: your improvement goes in for
> everyone, with your name in the history, and you run no risk at all. A fork that becomes a project of its own violates the
> license, and the rights to this code are defended: takedown requests on the platforms, lawsuits with damages and even
> criminal action (Brazilian Law 9.609/98 treats the violation of software copyright as a crime). Even someone who thinks
> they are right may have to defend themselves, and that costs time and lawyers. **When in doubt, ask first:** the answer is
> free, the argument afterwards isn't.

---

<p align="center"><sub>Made for those who have more watches than wrists. ⌚</sub></p>
