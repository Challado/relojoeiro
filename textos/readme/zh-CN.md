<!-- origem: 5f23ae9310b948dab7cdc901e0a7b1b0a2602888 -->
<!-- parte 1 -->
# ⌚ Relojoeiro — Relógios 2

🌐 [Português](README.md) · [English](README.en.md) · **中文** · [हिन्दी](README.hi.md) · [Español](README.es.md) · [Français](README.fr.md)

> **手表收藏的智能轮换。** 每天告诉你哪块表上腕，提醒你上链、充电、晒太阳和换电池，让整个收藏都被佩戴，而不只是最喜欢的那一块。多人可以使用同一个系统，每人有自己的收藏，想分享什么就分享什么。

**[▶ 立即试用，无需安装](https://servidor2.lucas.inf.br/relojoeiro/cadastro.php)**：在项目的服务器上创建一个演示账户（有效 30 天，已带一个可以录入你的手表的收藏）。

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)
![SQLite、MySQL/MariaDB、PostgreSQL 或 Firebird](https://img.shields.io/badge/%E6%95%B0%E6%8D%AE%E5%BA%93-SQLite%20%7C%20MySQL%20%7C%20PostgreSQL%20%7C%20Firebird-336791)
![无依赖](https://img.shields.io/badge/%E4%BE%9D%E8%B5%96-%E6%97%A0-2ea44f)
![REST API](https://img.shields.io/badge/API-REST%20%2B%20JSON%2FXML-0366d6)
[![许可证](https://img.shields.io/badge/%E8%AE%B8%E5%8F%AF%E8%AF%81-%E6%BA%90%E4%BB%A3%E7%A0%81%E5%8F%AF%E8%A7%81-orange)](#licença)

![今天页面：当天的手表、今天要做的事、接下来几天的排程和一块手表的面板](docs/telas/hoje.png)

<sub>**今天**页面，使用一个包含 8 块手表的演示收藏（常见型号，通过 API 录入了六周的佩戴记录）：当天的手表（Casio，7 点起戴在手腕上）、今天要做的事、接下来几天的智能排程，右侧是 Tissot 的面板，在列表旁边打开（在设置中也可以改为浮动窗口，盖在页面之上）。</sub>

| | |
|---|---|
| **[为什么存在](#por-que-isso-existe)** · [核心理念](#a-ideia-central-o-sistema-não-sabe-nada-de-relógios) · [工作原理](#como-funciona) · [它的一天](#um-dia-com-o-relógios-2) | **[1 分钟试用](#experimente-em-1-minuto)** · [界面](#as-telas) · [API](#a-api-uma-porta-só-para-tudo) · [安装](#instalação) |
| [多人使用](#várias-pessoas-contas-coleções-e-acesso) ·[常见问题](#perguntas-frequentes) · [内部结构](#como-é-por-dentro) | [如何贡献](#como-contribuir) · [许可证](#licença) |

---

## 为什么会有这个系统 {#por-que-isso-existe}

收藏手表的人都熟悉这样的场景：

- **最喜欢的那块天天戴**，而那块满怀爱意买来的表已经在抽屉里躺了两个月。
- **自动表停了**，因为周末没人戴，于是你又得重新调时间、日期和月相。
- **太阳能表在盒子的黑暗中没电了**，你却毫无察觉。
- **电池毫无预兆地耗尽**，偏偏是你今天想戴的那块。
- **智能手表一早醒来就没电了**，正好轮到它的那天。
- **机械表的保养**（"每 5 年一次"）和**保修**（"三月到期？"）都只存在记忆里，也就是说，哪儿都没有。

电子表格只能解决一半：它能记住，但不会做决定。**Relógios 2** 会做决定。它知道每块表闲置了多久、现在还有多少电量、你有多喜欢它，以及它相对于其他表被戴了多少。据此，它制定接下来几天、甚至接下来两年的计划，并把提醒放在正确的位置。

早上 Telegram 会收到一条消息：*"今天：Seiko 5。给 Orient 上链（动力储存在 15 点耗尽）。"*
晚上收到另一条：*"明天：Xiaomi Band。睡前充电：明天 14 点会降到 20%。"*
Google 日历里则记着 Casio 十一月的换电池和 Tissot 2027 年的保养。

<!-- parte 2 -->
## 1 分钟试用 {#experimente-em-1-minuto}

**无需安装：**在 [servidor2.lucas.inf.br/relojoeiro](https://servidor2.lucas.inf.br/relojoeiro/cadastro.php) 创建一个演示账户（有效 30 天；账户创建时就带一个收藏和初始集合，你录入的内容只有你能看到）。

**在你自己的机器上：**只需要带 `sqlite3` 扩展的 PHP 8.1+：不需要 Web 服务器，也不需要数据库服务器。

```sh
git clone https://github.com/Challado/relojoeiro.git && cd relojoeiro
cp config.exemplo.php config.php
```

在 `config.php` 中改两行以使用 SQLite（数据库文件放在文件夹旁边）：

```php
define("DB_TIPO", "sqlite");
define("DB_ARQUIVO", __DIR__ . "/../relogios.sqlite");
```

```sh
php instalar.php --lingua=zh-CN  # 结构和初始设置（中文）
php criar_usuario.php you        # 会询问密码
php -S localhost:8000            # 然后打开 http://localhost:8000
```

在 **今天 → 新手表** 中录入一些手表。想不等 cron 就生成排程，运行 `php cron.php --forcar`。

> PHP 内置服务器（`php -S`）只用于试用：它会提供文件夹中的任何文件，包括 `config.php`。正式使用请按照[安装](#instalação)进行。

---

<!-- parte 3 -->
## API：一扇门通往一切 {#a-api-uma-porta-só-para-tudo}

`api.php` 是**唯一的后端**。页面只是界面：每个页面的 JavaScript 调用 API 并生成 HTML，不做任何计算（摘要、百分比、合计和状态，例如计划摘要和 cron 的状态，都由 API 直接给出；界面只做属于自己的事：筛选、排序以及把表格筛选后显示的内容相加）。因此，**界面能做的一切，脚本也能做**：自动化、手机快捷方式、与 Home Assistant 集成、小组件。

API 也负责界面之前和周边的事情：**安装数据库**（`recurso=instalacao`）、**创建第一个用户**（`recurso=usuarios`，使用令牌）、**导入旧系统**（`recurso=importacao`）、**公开注册**（`recurso=cadastro`，无需登录）、**账户**（`conta`、`colecoes`、`acessos`、`convites`、`pedidos`、`aprovacoes`），以及**页面登录**（`recurso=entrar`：页面已打开而登录失效时，由 API 询问用户名和密码，再送回页面；打开页面时，页面本身在输出任何内容之前就已经要求登录）。命令行脚本（`instalar.php`、`criar_usuario.php`、`importar.php`）只是快捷方式：它们调用同样的 API 操作。

API 之外只有 **cron**（`cron.php`，系统的时钟：生成计划、开始和结束当天的时段、发送消息、同步日历、触发事件并维护文档的副本），它使用与 API 相同的函数；可以随时请求的事情在 API 中都有对应的动作（推送目标的 `testar`、日历的 `sincronizar`、`resortear`……），它做过的事显示在 `recurso=cron` 中。

**认证：** 网站登录（HTTP Basic）、**个人令牌**（在**我的账户**中创建，等同于你的登录）、cookie 中**已记住设备**的密钥（手机上应用的 cookie）或服务器令牌（`API_TOKEN`，等同于 master），放在 `X-Api-Token` 请求头或 `?token=...` 中。**收藏：** `colecao=<id>`（不传时使用菜单选择器中的收藏，否则是你的第一个）；你看不到的收藏返回 404。**删除**需要 `confirmar=1`：不带它时，响应（409）会说明会一并删除什么，什么都不会删除。
**格式：** 默认 JSON，或使用 `formato=xml` 得到 XML。**语言：** `lingua=zh-CN`（或 `Accept-Language` 请求头）：消息、帮助和重建说明都以该语言输出。

```sh
# 系统中的一切，不含照片
curl -u 用户:密码 "http://服务器/relojoeiro/api.php?foto=nao"

# 今天页面显示的内容
curl -H "X-Api-Token: $TOKEN" "http://服务器/relojoeiro/api.php?recurso=hoje"

# 未来 24 小时内会停走的手表，只要名称和时间
curl -u 用户:密码 -g "http://服务器/relojoeiro/api.php?recurso=autonomia&f[relogios][acaba_em_segundos][ate]=86400&mostrar[relogios]=nome,acaba_em_datacomtz"

# 记录一次电量读数（并测量消耗）；estado=uso 或 repouso：读数时它的状态（可选）
curl -u 用户:密码 -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 -d estado=repouso http://服务器/relojoeiro/api.php

# 现在放去晒太阳……之后再取下
curl -u 用户:密码 -d recurso=lancamento -d acao=iniciar  -d relogio_id=4 -d tipo=sol http://服务器/relojoeiro/api.php
curl -u 用户:密码 -d recurso=lancamento -d acao=encerrar -d relogio_id=4 -d tipo=sol http://服务器/relojoeiro/api.php

# 在两块手表上测试一个公式，不保存
curl -u 用户:密码 -G "http://服务器/relojoeiro/api.php" -d recurso=calcular --data-urlencode "expressao=energia * 2" -d relogio=10,12
```

**查询**（`GET`，带 `recurso=`）：

| 资源 | 返回 |
|---|---|
| *（无）* | 保存的一切：树、字段、记录类型、公式、提醒、手表（含数值、计算后的公式、提醒、记录、预测、照片和文档数据）、文档类别、模式、计划、设置、事件、日历、标准、cron、用户（含密码哈希：系统中不存在密码本身）以及迁移。`foto=nao` 不含照片。 |
| `hoje` | 今天页面显示的内容：当天的手表、提醒、接下来的日子、模式、收藏表格及其合计（手表数量、可用、使用中和支付金额） |
| `ficha` | 手表面板显示的内容（`relogio=<id>`；没有时只返回新手表的档案）：当前状态、电量、分数、预测、电池消耗、各类别的文档数量、最近的标记、档案 |
| `proximos` | 今天页面的"接下来的日子"，一页（`pagina=`、`por_pagina=`；没有时使用设置中的"最多显示"），中间包含空闲日 |
| `rodizio_manual` | 手动轮换：一个时段（`de=`、`ate=`）的日子，每页 7 天（`pagina=`），每天包含计划中当前的内容和选择框的初始选项（手动选择的手表、`livre` 或 `auto`：替我决定）、可用的手表以及"替我决定"的标准 |
| `plano` | 已保存的计划，逐日列出每次选择的理由，以及计划页面显示的时段摘要（每块手表的天数、百分比、下一天、手动的天数、没有分到日子的手表），`dia_a_dia` 每次一页（`pagina=`、`por_pagina=`、`relogio=`）。`de=` 和 `ate=`（YYYY-MM-DD；没有时为整个计划）或 `dias=N`（接下来 N 天；`dias=todos` 为从今天到结束） |
| `avisos` | 所有手表的提醒，从最紧急到最远 |
| `previsao` | 有电量读数的手表的电池预测（`relogio=` 一个 id 或用逗号分隔的多个；没有时为全部） |
| `autonomia` | 每块手表还能撑多久，以秒为单位，为外部系统精简（`relogio=` 一个或多个 id；没有时为全部） |
| `historico` | 一块手表的时间线，以及每种状态的时长。`relogio=`（一个或多个 id；`relogio_id` 也可以；没有时为全部）、`de=` 和 `ate=`（日期，或日期和时间：与时段相交的部分都会包含）、`estado=`（`rodizio`、`pulso`、`repouso`、`marca` 或某种时段类型如 `winder` 和 `sol`；一个或多个；`linha_estado` 也可以）、`ordem=`（`desc` 为默认，或 `asc`）、`limite=`（默认 100，最多 1000）和 `pagina=` |
| `criterios` | 标准组以及每块手表带计算过程的分数 |
| `eventos`、`destinos`、`agendas` | 自定义事件；消息推送目标，含每个接收的内容和今天的预览；日历，含每个日历中应有的内容 |
| `ics` | ICS 链接类型日历的日历内容（无需登录，凭链接的密钥） |
| `config`、`cron` | 收藏的设置（含推送目标和日历的摘要以及 cron 状态：从未运行、已停止或运行中）；cron 的执行记录，含时段摘要。在 `cron` 中：`de=` 和 `ate=`（日期，或日期和时间）、`situacao=`（`atividade`，默认；`erro`、`nada` 或 `todas`）、`busca=`（日志中的文本）、`limite=`（默认 100，最多 1000）和 `pagina=`；每个参数也可以加 `cron_` 前缀（`cron_de`……） |
| `arvore`、`cadastros` | 分组；定义页面显示的一切 |
| `calcular` | 一个公式（`expressao=`）在手表上的结果（`relogio=` 一个或多个 id；没有时为全部），不保存，并给出计算的各部分；公式写错：返回 400 和错误 |
| `foto` | 一块手表的照片（`relogio=<id>`）：图片本身，不是 JSON |
| `documentos` | 一块手表的文档（`relogio=<id>`；没有时为全部），含类别、每个 XML 的 NF-e 摘要、文件夹和大小限制 |
| `documento` | 一个文档的文件（`id=<id>`）：用于显示（图片、视频、音频、PDF、文本）或下载（其他，或使用 `baixar=1`）；`mini=1` 是照片的缩略图。支持分段（Range），方便视频快进 |
| `usuarios`、`migracoes` | master：账户面板，每个账户含其拥有的内容和密码哈希（用于以相同密码迁移：参见[用户与密码](#os-usuários-e-as-senhas)）；其他人只有自己的账户；尚待应用的迁移 |
| `conta`、`colecoes`、`aprovacoes` | 我的账户（收藏、树、申请、令牌、已记住的设备、Web Push 设备；`parte=menu`：只返回菜单用的部分）；收藏和树；审批页面 |
| `manifest`、`sw` | 手机上的应用，无需登录：清单（JSON；带已记住设备的 cookie 时，`start_url` 打开即登录）和 *service worker*（JavaScript）。见[手机上的应用](#o-aplicativo-no-celular) |
| `cadastro` | 公开注册，无需登录：是否开放以及邀请（`convite=<token>`）；没有任何账户或收藏的列表（申请访问凭所有者给出的编号） |
| `instalacao` | 安装状态：数据库、是否为空、是否已安装并为最新、用户数量以及下一步。数据库为空时也会响应（使用令牌） |
| `backup` | 数据库备份：是否开启、文件夹是否可用、保留多少份、最近一份以及文件夹中的备份；`arquivo=` 下载其中一份 |
| `importacao` | 导入旧系统所用的信息：旧数据库服务器（不含密码）、PHP 是否有 MySQL，以及本数据库是否已有手表 |
| `entrar` | 页面登录：未登录时返回 401（浏览器会要求输入用户名和密码）；已登录时返回 `volta=` 指定的页面（302）或说明是谁登录了 |
| `ajuda` | 完整文档：每个查询、每个写入、每个响应的每个字段以及引擎的函数 |
| `manual`（或 `ajuda&parte=manual`） | **帮助**页面的文本（本 README 中面向用户的章节：从核心理念到常见问题），markdown 格式，整体以及逐节给出，每节带标题、层级和锚点，同时给出带目录的 HTML（帮助页面就是由它生成的） |
| `reconstrucao`（或 `ajuda&parte=reconstrucao`） | **从零重写整个系统**的指南（参见[从零重写系统](#reescrever-o-sistema-do-zero)） |
| `codigo`（或 `ajuda&parte=codigo`） | **系统当前的代码**，在请求时从磁盘读取：每个文件的层级、用途、语言、大小、行数、SHA-256 和日期，以及有意排除的内容（`nao_mostrados`）。`arquivo=<名称>` 给出内容（图片为 base64；页面还给出浏览器收到的 HTML）；`cru=1` 给出原始文件，不带 JSON，便于在浏览器中阅读；`conteudo=1` 给出全部（参见[从零重写系统](#reescrever-o-sistema-do-zero)） |

**写入**（`POST`，带 `recurso=` 和 `acao=`），全部使用与界面相同的校验和消息：

| 资源 | 操作 |
|---|---|
| `relogio` | `salvar`（创建或修改）、`excluir`（连同文档）、`foto`、`remover_foto` |
| `documentos` | `enviar`（一个或多个文件，带类别、标题、日期、描述，照片还带缩略图）、`alterar`、`excluir` |
| `documento_categorias` | `nova`、`alterar`、`excluir`（仅在没有文档时） |
| `lancamento` | `lancar`（上链、电量、电池……）、`iniciar` 和 `encerrar`（戴上和取下手腕、上链盒、晒太阳、充电器）、`alternar`（时段打开时关闭，关闭时打开：手机上一个按钮就够）、`periodo`（已经过去的一段时间；当天的手表戴在手腕上时算作轮换中的使用，与它相接的同类型片段（例如仍打开的“戴上”）合并为一段）、`alterar`、`excluir`、`medicao`（把一次消耗测量放入或移出计算：`id`，`usada` 为 1 或 0）。所有操作中，`relogio_id=hoje` 都表示当天的手表 |
| `rodizio` | `usando`（我今天戴的是这块）、`trocar_dia`（某一天的手表）、`periodo`（一个时段的手表，按顺序：`de`、`ate`、`relogio_ids[]`、`dias_cada`）、`periodo_sortear`（删除时段内的日子，由模式重新安排；只填 `de`：到计划结束；只填 `ate`：从明天到它）、`periodo_livre`（清除并让这些天保持空闲，不抽选；日期规则相同）、`manual`（手动轮换：`de`、`ate`、`dias[YYYY-MM-DD]` = 手表 id、`auto` 或 `livre`）、`apagar_tudo`（清除从明天起的全部安排；`hoje=1`：从今天起；模式会重新生成计划）、`resortear`、`resortear_hoje`、`proxima_semana`、`modo`（更换模式） |
| `modos` | `salvar`、`ativar`、`excluir` |
| `arvore` | `novo`、`renomear`、`mover`、`ordem`、`excluir`（分组）以及 `relogios`（每块手表的分组） |
| `campos`、`avisos`、`lancamento_tipos` | `novo`、`alterar`、`excluir`（字段还有 `ordem`） |
| `formulas` | `nova`、`alterar`、`excluir` |
| `criterios` | `conjunto_criar`、`conjunto_excluir`、`param_novo`、`param_excluir`、`param_pesos`、`sub_novo`、`sub_excluir`、`sub_medida`、`sub_mover`、`sub_pesos`、`faixas`、`ordem`、`restaurar` |
| `config` | `salvar`、`evento_salvar`、`evento_excluir` |
| `destinos` | `salvar`（webhook、电子邮件或 Web Push，含接收内容）、`testar`（`qual=`：文本、早上或晚上）、`excluir` |
| `agendas` | `salvar`（Google、CalDAV、Outlook 或 ICS）、`sincronizar`、`teste_criar`、`teste_remover`、`novo_link`（ICS）、`outlook_conectar`、`excluir` |
| `usuarios` | `salvar`（带 `senha`，或带其他系统的 `senha_hash`；第一个用户使用令牌创建，是 master），以及仅限 master：`alterar`、`oficializar`、`prorrogar`、`voltar_demo`、`bloquear`、`transferir`、`dar_acesso`、`excluir` |
| `conta` | `alterar`（电子邮件、语言、密码）、`token_criar`、`token_revogar`、`aparelho_lembrar`（手机上的应用：此设备的密钥存入 cookie，无需密码进入）、`aparelho_esquecer`、`push_inscrever`、`push_cancelar`、`push_testar`、`excluir`；仅限 master：`email_testar`、`vapid_gerar` |
| `colecoes` | `criar`、`renomear`、`excluir`、`arvore_copiar`、`arvore_renomear`、`arvore_excluir` |
| `acessos`、`convites`、`pedidos` | `remover`、`sair`；`convidar`、`aprovar`、`recusar`、`reenviar`、`cancelar`、`aceitar`；`pedir`、`aprovar`、`recusar`、`cancelar` |
| `cadastro` | 无需登录：`criar_conta`、`esqueci_senha`、`nova_senha` |
| `migracoes` | `aplicar` |
| `instalacao` | `instalar`（仅限空数据库，使用令牌） |
| `backup` | `fazer`（立即备份）、`restaurar`（`arquivo=`：把数据库恢复到那份备份，恢复前先备份当前内容） |
| `importacao` | `importar`（`banco=` 旧数据库的名称；本数据库已有手表时用 `substituir=1`；中途停止则什么都不保存） |

**错误响应**会说明原因：查询中为 `{"erro": "..."}`，被拒绝的写入为 `{"ok": false, "mensagem", "erros": [...]}`（每个原因一条错误）：

| 代码 | 何时 |
|---|---|
| `400` | 写入未通过校验（错误会说明原因），或 `calcular` 中的公式写错 |
| `302` | `recurso=entrar` 登录正确且带 `volta=`：跳转到页面 |
| `401` | 没有令牌或网站登录，或二者错误；没有任何用户（或数据库为空）时，响应会说明该怎么做（`sem_usuarios` 和 `como`） |
| `403` | 不是 master 的人请求备份和迁移 |
| `404` | 未知资源（响应会带上资源列表）、你看不到的收藏，或不存在的手表、照片、文档（或其文件）或 `README.md`（在 `manual` 中），或不通过 `codigo` 输出的文件（对于故意排除的内容如 `config.php`，带 `o_que_ver`） |
| `409` | 没有 `confirmar=1` 的删除（带 `impacto`：会一并删除的内容；什么都没删）；没有任何收藏的账户（`sem_colecao`）；或数据库拒绝了写入：另一条记录仍在使用的记录、重复的值，或违反数据库校验规则的值（会给出规则名） |
| `413` | 上传超过服务器 PHP 的限制（`post_max_size`）：文件太大 |
| `500` | 系统停止（`config.php` 中的 `API_TOKEN` 或 `FUSO` 无效）或内部错误 |
| `503` | 数据库过期（只有 `recurso=migracoes` 和写入 `migracoes`/`aplicar` 会响应；还有不使用数据库的 `manual` 和 `codigo`）、数据库为空（安装前只有 `instalacao` 会响应，还有 `manual` 和 `codigo`）或数据库无法连接 |

### 用户与密码 {#os-usuários-e-as-senhas}

密码不会保存在任何地方：只保存它的**哈希**，由 PHP 的 `password_hash`（bcrypt）生成。哈希有 60 个字符，`$2y$12$...`：`$2y$`、成本（12 表示 2¹² 轮）、盐（22 个字符，每个密码随机生成）和结果（31 个字符）。哈希无法还原成密码：登录时，系统用哈希中的盐和成本计算输入密码的 bcrypt 并进行比较（`password_verify`）。同一个密码每次得到的哈希都不同，但都能通过验证。

**要把用户迁到另一个系统**，带上每个用户的登录名和 `senha_hash`（`recurso=usuarios`，由 master），在那边用该语言的 bcrypt 校验输入的密码与哈希：每个人都能用原来的密码登录。`$2y$`（PHP）和 `$2b$`（其他语言）是同一种计算；如果库拒绝 `$2y$`，把开头改成 `$2b$` 即可。**要从其他系统引入用户**，发送那边的哈希：`recurso=usuarios`、`acao=salvar`、`login` 和 `senha_hash`（bcrypt `$2y$`、`$2b$` 或 `$2a$`，或 argon2）。

| 语言 | 校验密码 | 生成哈希 |
|---|---|---|
| PHP | `password_verify($senha, $hash)` | `password_hash($senha, PASSWORD_DEFAULT)` |
| Python（`pip install bcrypt`） | `bcrypt.checkpw(senha.encode(), hash.encode())` | `bcrypt.hashpw(senha.encode(), bcrypt.gensalt()).decode()` |
| Node（`npm install bcryptjs`） | `require("bcryptjs").compareSync(senha, hash)` | `require("bcryptjs").hashSync(senha, 12)` |
| Java（Spring Security） | `new BCryptPasswordEncoder().matches(senha, hash)` | `new BCryptPasswordEncoder().encode(senha)` |
| C#（BCrypt.Net-Next） | `BCrypt.Net.BCrypt.Verify(senha, hash)` | `BCrypt.Net.BCrypt.HashPassword(senha)` |

```sh
# 目录：每个用户的登录名和哈希
curl -u 用户:密码 "http://服务器/relojoeiro/api.php?recurso=usuarios"

# 从其他系统引入一个用户，保留他原来的密码
curl -u 用户:密码 --data-urlencode recurso=usuarios --data-urlencode acao=salvar --data-urlencode login=maria \
     --data-urlencode 'senha_hash=$2b$12$...' http://服务器/relojoeiro/api.php
```

**通用筛选**适用于任何响应中的任何列表：

| 参数 | 作用 |
|---|---|
| `incluir=relogios,plano` / `excluir=motor` | 只要这些部分，或除这些之外的全部 |
| `f[lista][campo]=v` | 等于 `v`（或等于多个值之一：`v1,v2`） |
| `f[lista][campo][de]=` / `[ate]=` | 数字或日期的范围 |
| `f[lista][campo][contem]=` / `[diferente]=` / `[vazio]=1` | 包含文本、排除、为空 |
| `busca[lista]=texto` | 在任何字段中查找文本 |
| `ordem[lista]=-campo` | 排序（`-` 表示倒序） |
| `limite[lista]=20&pagina[lista]=2` | 分页 |
| `mostrar[lista]=id,nome` | 只要这些字段 |

嵌套字段用点号表示，如 `formulas.energia.valor`、`campos.loja.valor` 和 `compra.valor`。文本比较不区分大小写和重音。

📖 **完整文档**（每个查询、每个带示例的写入以及所有响应字段的词典）在 [`api.php`](api.php) 的文件头中，也在 API 本身中：`api.php?recurso=ajuda`（使用请求的语言）。词典只有一个来源，即帮助中的词典；文件头中的词典由它生成（`php testes/dicionario.php --gravar`）。

---

## 安装 {#instalação}

**要求：** PHP 8.1+，带 `curl` 和 `openssl`（用于 webhook、电子邮件、Web Push、日历和加密的机密）、一个 Web 服务器（nginx 或 Apache）、cron，以及**下列数据库之一**，各自通过对应的 PHP 扩展：

| 数据库 | PHP 扩展 | 版本 | 何时选择 |
|---|---|---|---|
| **SQLite** | `sqlite3` | 3.35+（在 3.53 上测试） | 最简单：一个文件，不需要数据库服务器。个人收藏绰绰有余，树莓派上也能用 |
| **MySQL / MariaDB** | `mysqli` | 使用 utf8mb4（在 MariaDB 11.4 上测试） | 你的主机已经提供，或者你已经在用 |
| **PostgreSQL** | `pgsql` | 12+，带 ICU（默认即有，在 18 上测试） | 你已经有一个在运行的 Postgres |
| **Firebird** | `pdo_firebird` | 3.0+（在 3.0.11、PHP 8.4 上测试） | 你已经有一个在运行的 Firebird |

系统在四种数据库上的行为完全相同。这由一致性测试（[`testes/`](testes/)）保证：它在四种数据库上运行同一个脚本，并比较 API 返回的一切。

```sh
# 1. 代码
git clone https://github.com/Challado/relojoeiro.git /var/www/relojoeiro
cd /var/www/relojoeiro

# 2. 配置：数据库（DB_TIPO）、API 令牌、时区、文档文件夹，以及可选的电子邮件、Web Push、Outlook 和公开注册的规则
#    （参见下方 config.php 的表格）
cp config.exemplo.php config.php
nano config.php
#    文档文件夹（DOCUMENTOS_PASTA），位于发布文件夹之外，PHP 用户可写：
#    mkdir -p /var/lib/relogios2/documentos && chown www-data /var/lib/relogios2/documentos

# 3. 空数据库（只在 MySQL、Postgres 和 Firebird 上需要；SQLite 会自己创建文件）
#    MySQL:    mysql -u root -p -e "CREATE DATABASE relogios2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
#    Postgres: createdb -U postgres relogios2
#    Firebird: echo "CREATE DATABASE 'localhost:/var/lib/firebird/3.0/data/relogios2.fdb' USER 'SYSDBA' PASSWORD '...'
#                    PAGE_SIZE 16384 DEFAULT CHARACTER SET UTF8;" | isql-fb -q

# 4. 安装：结构和初始设置，按 config.php 的数据库转换
php instalar.php --lingua=zh-CN
#    初始设置使用该语言，并成为该安装的默认语言（不带 --lingua：葡萄牙语）
#    或通过 API，使用 config.php 中的令牌（语言：lingua=zh-CN，与任何请求一样）：
#    curl -H "X-Api-Token: $TOKEN" -d recurso=instalacao -d acao=instalar -d lingua=zh-CN http://服务器/relojoeiro/api.php

# 5. 第一个用户，即 master，连同他的收藏（输入密码时不显示）
php criar_usuario.php 你的登录名
#    或通过 API，使用令牌：
#    curl -H "X-Api-Token: $TOKEN" -d recurso=usuarios -d acao=salvar -d login=你的登录名 -d senha=... http://服务器/relojoeiro/api.php

# 6. cron，每分钟一次（它是静默的：不输出任何内容，也不发邮件）
( crontab -l; echo "* * * * * php /var/www/relojoeiro/cron.php" ) | crontab -
```

`cron.php` 有两个选项，用于手动运行：`--forcar` 立即运行早上和晚上两轮，即使今天已经运行过（生成排程、发送消息并同步日历，如果它们已开启）；`-v` 在屏幕上显示它保存的日志（与 cron 运行记录页面相同）。例如：`php cron.php --forcar -v`。

使用 SQLite 时，把数据库文件（`DB_ARQUIVO`）放在 **Web 服务器发布的文件夹之外**，放在 PHP 用户可写的文件夹中（SQLite 会在旁边创建 `-wal` 和 `-shm` 文件）。

使用 Firebird 时，数据库必须以 **UTF8** 创建（上面的 `DEFAULT CHARACTER SET UTF8`）。PHP 需要 `pdo_firebird` 扩展：在 Debian 和 Ubuntu 上是 `php8.4-interbase` 包（旧名称）以及随之安装的 Firebird 客户端（`libfbclient2`）；在 `php.ini` 中写 `extension=pdo_firebird`。服务器是 `firebird3.0-server` 包（或 Firebird 官网的安装程序）。在 `config.php` 中，`DB_NOME` 是 `.fdb` 文件**在数据库服务器上**的路径（或 `databases.conf` 中的别名），`DB_USUARIO`/`DB_SENHA` 是一个 Firebird 用户（`SYSDBA` 或为系统创建的用户）。

**7. Web 服务器。** 通过网络只能打开页面、`api.php` 以及它们加载的内容（`estilo.css`、各个 `.js`、图片）。其余全部被阻止：核心（`lib.php`、`banco.php`、`operacoes.php`）、`config.php`、cron、命令行脚本、测试、`.sql`、`.json`、`.md` 和 `.sqlite` 文件，以及所有以点开头的内容，包括随 `git clone` 一起来的 `.git` 文件夹。项目已为两种服务器准备好这些配置：

- **Apache：** [`.htaccess`](.htaccess) 已经在文件夹里，自动生效。文件夹只需要在 Apache 配置中启用 `AllowOverride All`，几乎所有主机默认如此；没有它，`.htaccess` 会被忽略，什么都不会被阻止。当 PHP 以 FastCGI（PHP-FPM，大多数主机）运行时，它还能让网站登录正常工作，否则 Apache 不会把密码传给 PHP。
- **nginx：** 把 [`nginx-relogios.conf`](nginx-relogios.conf) 中的配置块复制到你网站的 `server` 中（调整 `/relojoeiro/` 和 PHP-FPM 的地址）。它还包含 `client_max_body_size 512m`，用于上传文档（参见[上传大小](#o-tamanho-dos-envios-os-documentos)）。

检查方法：打开 `https://你的服务器/relojoeiro/config.php` 和 `https://你的服务器/relojoeiro/.git/config`：两者都必须返回 **403**。

**8. 在浏览器中打开**，用创建的用户登录。初始设置已经就绪：在 **今天 → 新手表** 中录入你的手表，为每块表选择分组并填写字段。在**我的账户**中填写你的电子邮件。其他人可以通过**创建账户**（`cadastro.php`）、你的邀请进入，或由 master 在**用户**中创建账户。

### `config.php` {#o-configphp}

| 常量 | 用途 |
|---|---|
| `DB_TIPO` | 数据库：`mysql`（默认，也适用于 MariaDB）、`pgsql`、`sqlite` 或 `firebird` |
| `DB_HOST`、`DB_PORTA`、`DB_NOME`、`DB_USUARIO`、`DB_SENHA` | 数据库服务器（MySQL、Postgres 和 Firebird）；`DB_PORTA` 为 0 表示默认端口。在 Firebird 上，`DB_NOME` 是服务器上 `.fdb` 文件的路径（例如 `/var/lib/firebird/3.0/data/relogios2.fdb`）或 `databases.conf` 中的别名，`DB_HOST` 为空则直接打开文件，不经过服务器（嵌入式 Firebird） |
| `DB_ARQUIVO` | 数据库文件（SQLite），位于发布文件夹之外 |
| `API_TOKEN` | **必填**，至少 10 个字符。没有它，整个系统会停止并说明原因。 |
| `FUSO` | 系统时区，如 `America/Sao_Paulo`。为空或缺失：使用 PHP 的时区（php.ini 中的 `date.timezone`）。它是数据库保存日期所用的时区，也是未另选的人所用的时区；每个收藏（设置）和每个人（我的账户）都可以选择自己的（参见[时区](#o-fuso-horário)） |
| `SEGREDO_CHAVE` | 可选：在数据库中加密 CalDAV 密码、Google 密钥、Outlook 令牌和 webhook 请求头的密钥。没有它则使用 `API_TOKEN`（更换密钥会丢失已保存的机密：重新填写即可） |
| `MSG_TITULO` | 消息的标题（webhook 的 `{titulo}`、邮件主题、Web Push 标题）。默认：Relógios |
| `MSG_ENDPOINT`、`MSG_DESTINATARIO` | 仅用于 v25 迁移：旧的消息 API（Telegram）成为收藏的第一个 webhook 推送目标 |
| `CADASTRO_ABERTO`、`DEMO_DIAS`、`CONVITE_DIAS` | 可选：公开注册（`false`：关闭，只能凭邀请；没有这一行时开放）、演示账户的天数（默认 30；`0`：账户一创建即为正式）以及邀请或申请等待的天数（默认 7） |
| `EMAIL_METODO`、`EMAIL_REMETENTE`、`SMTP_HOST`、`SMTP_PORTA`、`SMTP_SEGURANCA`、`SMTP_USUARIO`、`SMTP_SENHA` | 电子邮件：`smtp`（指定的服务器；安全方式 `tls` 为默认，或 `ssl`、`nenhuma`）或 `mail`（PHP 的 `mail()`），以及发件人。都没有时不会发出任何邮件 |
| `IMAP_HOST`、`IMAP_PORTA`、`IMAP_SEGURANCA`、`IMAP_USUARIO`、`IMAP_SENHA`、`IMAP_PASTA_ENVIADOS` | 可选：在账户的“已发送”文件夹中保存每封已发送邮件的副本 |
| `POP_HOST`、`POP_PORTA`、`POP_SEGURANCA`、`POP_USUARIO`、`POP_SENHA` | 可选：仅供邮件测试通过 POP3 检查邮箱 |
| `VAPID_PUBLICA`、`VAPID_PRIVADA`、`VAPID_ASSUNTO` | Web Push：密钥对（master 在**我的账户**中生成）和主题（`mailto:...`） |
| `OUTLOOK_CLIENTE_ID`、`OUTLOOK_CLIENTE_SEGREDO` | 在 Microsoft 注册的应用，用于 Outlook 日历（见下文） |
| `DOCUMENTOS_PASTA` | 文档文件（说明书、发票、照片、视频）的文件夹，**位于发布文件夹之外**，PHP 用户可写。没有它，文档页面会提示缺少并且不接受上传。例如：`define("DOCUMENTOS_PASTA", "/var/lib/relogios2/documentos");` |
| `BACKUP_PASTA`、`BACKUP_MANTER`、`BACKUP_DOCUMENTOS` | 可选：数据库备份（参见[备份](#cópia-de-segurança)）。文件夹，**位于发布文件夹之外**（没有它就没有自动备份）；保留多少份（默认 7）；以及是否包含存放在数据库中的文档内容（默认：不包含）。 |
| `BACKUP_ATIVO` | 可选：`false` 关闭备份：没有自动备份，没有**立即备份**，设置中也没有**备份**框（连缺少文件夹的警告也没有）。没有它：开启 |
| `DOCUMENTOS_LIMITE` | 可选：接受的最大文档大小，以字节为单位。没有它为 100 MB（`104857600`）；`0` 或 `-1` 表示系统不设限制。旧名称 `MANUAL_LIMITE` 在它不存在时仍然有效。PHP 和 nginx 的限制仍然生效（参见[上传大小](#o-tamanho-dos-envios-os-documentos)）。例如：`define("DOCUMENTOS_LIMITE", 524288000);` 表示 500 MB |
| `ANTIGO_HOST`、`ANTIGO_PORTA`、`ANTIGO_USUARIO`、`ANTIGO_SENHA` | 可选，仅在本系统使用 Postgres、SQLite 或 Firebird 时供 `importar.php` 使用：旧系统的 MySQL 在哪里 |
| `ALEATORIO_RANDOMORG_URL`、`ALEATORIO_ANU_URL` | 可选，仅用于测试：随机数来源的地址（`https://www.random.org/integers/` 和 `https://qrng.anu.edu.au/API/jsonI.php`）。更改后，抽选会向测试服务器索取数字 |
| `GOOGLE_TOKEN_URL`、`GOOGLE_API_URL`、`OUTLOOK_LOGIN_URL`、`OUTLOOK_GRAPH_URL` | 可选，仅用于测试：日历使用的 Google 和 Microsoft 地址。改掉后，同步会与测试服务器通信 |
| `TEXTOS_ESTRITO` | 可选，供翻译者使用：`true` 让缺少的翻译报错停止（参见[语言](#os-idiomas)） |

系统读取它所在文件夹中的 `config.php`。服务器环境变量 `RELOGIOS_CONFIG` 可以指向另一个文件代替它：一致性测试就是这样用的（`testes/paridade.sh`，配合 [`testes/config.teste.php`](testes/config.teste.php)），以便在不碰真正的 `config.php` 的情况下运行。正常安装时不要设置它。

### 服务器上的推送目标与日历 {#destinos-e-agendas-no-servidor}

每个收藏在**推送目标**和**日历**页面中创建自己的推送目标和日历。需要在服务器上配置的内容：

- **Telegram（一个 webhook）：**用 @BotFather 创建一个机器人（它会给出令牌），并找到你的 `chat_id`（给机器人发一条消息，然后打开 `https://api.telegram.org/bot<token>/getUpdates`）。在**推送目标**中新建一个 webhook：方法 `GET` 或 `POST`，地址 `https://api.telegram.org/bot<token>/sendMessage`，字段 `chat_id` = 该数字，`text` = `{mensagem}`。任何其他桥接服务（n8n、Home Assistant、你自己的地址）都一样：系统把 `{titulo}` 和 `{mensagem}` 放到你指定的位置，响应为 2xx 即视为发送成功。长消息会分段发送。
- **电子邮件：**在 `config.php` 中配置 SMTP 服务器（`SMTP_HOST`、端口、安全方式、用户名和密码；Gmail 和 Outlook 需要应用专用密码）和 `EMAIL_REMETENTE`；或使用 `EMAIL_METODO = "mail"`，服务器上需有 sendmail。配置 `IMAP_*` 后，每封已发送的邮件都会在“已发送”中保存一份副本。master 可在**我的账户 › 邮件服务器**中测试：该框显示系统从 `config.php` 读到的内容（发送方式、发件人、SMTP、IMAP 和 POP；密码只显示是否*已设置*），并指出错误或缺少的地方（没有 `EMAIL_METODO`、端口与安全方式不匹配、密码为空、PHP 没有 `openssl`……）。**测试连接**会登录 SMTP、IMAP（并检查“已发送”文件夹）和 POP，但不发送任何内容；**发送一封测试邮件**会真正发到你填写的地址。之后会显示每个部分的结果以及**与服务器的对话**（逐行显示，不含密码），方便找到在哪一步停下。
- **Web Push：**网站必须通过 **https** 打开。master 在**我的账户 › Web Push 密钥**中生成密钥，并把两行复制到 `config.php`。之后，每个人在**我的账户**中为自己的设备打开通知（在 iPhone 上，先把网站添加到主屏幕），Web Push 推送目标会发往它所指向的人的设备。
- **Google 日历：**在 Google Cloud 中创建一个**服务账号**并下载 JSON 密钥；把你的日历共享给服务账号的电子邮件，并授予修改事件的权限；在**日历**中粘贴密钥的 JSON（它会加密保存在数据库中）和日历 ID。只有 master 可以不粘贴 JSON，而是填写服务器上某个文件的路径。
- **CalDAV（Nextcloud、iCloud、Fastmail）：**日历地址、用户名和密码。在 **iCloud** 上使用**应用专用密码**（appleid.apple.com › App 专用密码），日历地址位于 `caldav.icloud.com`。
- **Outlook：**在 Microsoft 门户（**Entra ID › 应用注册**）中注册一个应用，授予 `Calendars.ReadWrite` 权限，回调地址为 `https://<你的服务器>/relojoeiro/api.php?recurso=outlook_retorno`（日历页面会显示确切的地址）；把 ID 和密钥写入 `config.php`（`OUTLOOK_CLIENTE_ID` 和 `OUTLOOK_CLIENTE_SEGREDO`）。在**日历**中，**连接到 Outlook** 会跳转到 Microsoft 的页面，连接后返回。没有该应用时，请使用 ICS 链接：Outlook 可以订阅它。
- **ICS 链接：**无需配置。日历会给出一个秘密链接，可在任何应用中订阅（Google：**其他日历 › 通过网址添加**；Outlook：**从 Web 订阅**；iPhone：**已订阅的日历**）；**更换链接**会让之前的链接失效。

在 master 的收藏之外，别人填写的地址（webhook、CalDAV）必须位于互联网上：系统会拒绝服务器自身网络中的地址（`127.0.0.1`、`192.168.x`……），以免有人利用服务器探测它所在的网络。

### 上传大小（文档） {#o-tamanho-dos-envios-os-documentos}

每个文档通过一次上传完成（一起选择的多个文件逐个上传），服务器的三个限制可能在系统之前就拒绝大文件。系统每个文件最多接受 **100 MB**；`config.php` 中的 `DOCUMENTOS_LIMITE` 可以修改（以字节为单位；`0` 或 `-1`：系统不设限制）。手机拍的视频，按 500 MB 左右考虑：

| 位置 | 限制 | 默认值 | 改为 500 MB |
|---|---|---|---|
| `php.ini` | `upload_max_filesize`（单个文件）和 `post_max_size`（整个上传） | 2M 和 8M | `upload_max_filesize = 500M` 和 `post_max_size = 512M` |
| nginx | `client_max_body_size` | 1m | `512m`（[`nginx-relogios.conf`](nginx-relogios.conf) 中已有） |
| `config.php` | `DOCUMENTOS_LIMITE` | 100 MB | `524288000` |

文件直接进入文档文件夹；数据库中的副本按 4 MB 分块，每次读一块：PHP 的 `memory_limit` 和 MySQL 的 `max_allowed_packet`（16 MB 或以上）都不会限制文件大小。文档页面显示实际生效的限制（系统的限制，或因 `php.ini` 而更小；完全没有限制时显示"任意大小"）。超过限制的文件会被拒绝并提示大小；超过 `post_max_size` 的上传会返回代码 `413` 和服务器的限制。Apache 上除了 `php.ini` 之外没有其他限制需要调整。

### 数据库更新 {#atualizações-do-banco}

更新代码后，如果有新的迁移（`migracao_v*.sql`），API 会返回 `503`，**设置**页面会显示应用它的按钮（只有 master 能应用）。cron 也会停止并记录原因，而不是只运行一半。在新安装中，`schema.sql` 已经包含所有迁移的结构，初始集合在创建第一个用户时从 `modelo.json` 载入。

**v25 迁移（多人使用）**会重建整个数据库：先把所有内容的副本保存到 `relojoeiro-antes-da-v25.jsonl.gz`（在 `BACKUP_PASTA` 中，或在临时文件夹中），创建新结构，再以新形式、相同的编号写回每条记录（手表、文档和记录保留原来的 id）。最早的用户成为 master，以及收藏和树的所有者；其他人成为该收藏的成员。Telegram（`MSG_ENDPOINT`）成为第一个 webhook 推送目标，Google 日历成为第一个日历。如果中途停止，同一个按钮会从副本重新执行。

`schema.sql` 和每个迁移都是**四种数据库共用的一个文件**：结构（`CREATE TABLE`、`ALTER TABLE`）用 MySQL 方言书写，由 [`banco.php`](banco.php) 转换为 Postgres、SQLite 和 Firebird（`AUTO_INCREMENT`、`ENUM`、`TINYINT`、`DATETIME`、表内的 `INDEX`、`AFTER` 以及 `MODIFY COLUMN`，后者在 SQLite 上会重建表）。数据（`INSERT`、`UPDATE`）使用四种数据库通用的 SQL：不用 `GROUP_CONCAT`、`IF()` 或 `FIND_IN_SET`。`INSERT IGNORE`、`REPLACE INTO`、`NOW()`、`LEAST`、`GREATEST`、`LENGTH`、`DATE()`、`<=>`、`LIMIT`/`OFFSET`、不带 `FROM` 的 `SELECT`、多行 `INSERT` 和 `CONCAT` 都可以使用（`CONCAT` 不要带 NULL 参数：在 Postgres 中 NULL 会变成空文本，在 MySQL 中结果会变成 NULL）。迁移 v2 到 v8 早于这一做法，只用于升级旧的 MySQL 数据库：Postgres、SQLite 或 Firebird 数据库一创建就是当前版本。

有两个在任何数据库中都不是这样写的命令，`banco.php` 能理解，并按各数据库的方式执行：

| 命令 | 作用 |
|---|---|
| `ALTER TABLE t ALTER FOREIGN KEY (coluna) REFERENCES outra(id) ON DELETE CASCADE`（或 `SET NULL`） | 替换该列的外键（如果原来没有则创建）。在 MySQL、Postgres 和 Firebird 上，在目录中查找旧外键的名称，删除后创建新的（Firebird 上在事务中）；在 SQLite 上重建表。 |
| `ALTER TABLE t DROP COLUMN coluna` | 删除该列以及它的外键和索引（SQLite 重建表；Firebird 还会先删除使用该列的规则）。 |
| `ALTER TABLE t ADD CONSTRAINT nome CHECK (condição)` | 带名称的校验规则；如果同名规则已存在则替换。已有的行必须满足新规则，否则数据库拒绝，旧规则保持不变（Postgres 和 Firebird 上在事务中；MySQL 上新规则先以临时名称加入；SQLite 上在事务中重建表）。 |

另外，为了让中途停止的迁移可以通过同一个按钮再次运行：对已存在的列执行 `ADD COLUMN`，或用已存在的索引名执行 `ADD INDEX`，都不会做任何事。

迁移还可以有一个 **PHP 步骤**，处理四种数据库通用 SQL 做不到的事（v16 把字段选项的文本按行拆分成 `campo_opcao` 表的记录；v20 在 MySQL/MariaDB 上把以其他排序规则创建的表转换为 `utf8mb4_unicode_ci`）。它在 SQL 之后立即运行，通过设置中的按钮触发，`instalar.php` 中也会运行。

### 你可能注意到的数据库差异 {#diferenças-entre-os-bancos-que-você-pode-notar}

- **MySQL/MariaDB 上的文本顺序：** 无论数据库默认是什么，每个表都以 `utf8mb4_unicode_ci` 创建。MariaDB 默认使用 `utf8mb4_general_ci`，其排序与其他数据库不同（会把 `dias_desde_compra` 排在 `dias_de_carga` 之前）；用这种方式创建的旧安装会由迁移 v20 转换。
- **大小写和重音：** 与 MySQL 一样，"Relógio" 和 "RELOGIO" 被视为同一个名称，无论是搜索、排序还是登录。在 Postgres 上，这来自 `instalar.php` 创建的 ICU 排序规则（`ci`）。在 SQLite 上，来自一个 PHP 函数；因此在外部程序中（命令行 `sqlite3`、DB Browser），对这些列排序或比较会出现 "no such collation sequence: ci" 错误。读取和导出正常。在 Firebird 上，来自所有文本列上的 `UNICODE_CI_AI` 排序规则。
- **在 Firebird 上：**
  - 长文本（`schema.sql` 中的 `TEXT`：公式、消息、cron 日志）最多 **8191 个字符**（MySQL 上为 65535 字节），因为它们会变成 `VARCHAR(8191)`（文本 `BLOB` 在 PHP 中会以 null 的形式返回空值）；
  - 超过 31 个字符的索引名和规则名只在末尾保留完整名称 md5 的一部分，而 `no` 表是保留字：在外部程序中（`isql-fb`、FlameRobin），要写成带引号的大写 `"NO"`；
  - 多列唯一键中某一列可以为空时，会改为基于表达式的唯一索引，因为在 Firebird 中，值相同且另一列为空的两行被视为重复（在 MySQL 中则不会）；
  - `NOW()` 的时间来自 PHP（Firebird 没有按连接设置的时区），使用 `config.php` 中的 `FUSO`，与其他数据库一样。
- **带 `AFTER` 的迁移中的列顺序**只在 MySQL 上有效：在其他数据库上，新列会放在表的末尾（只影响 API 的 JSON 中键的顺序）。
- **id** 在不同数据库中可能跳过不同的数字（MySQL 在某些 `INSERT ... SELECT` 中会成块预留 id）。

### 从旧系统迁移过来 {#vindo-do-sistema-anterior}

`php importar.php <旧数据库>`（或通过 API：`recurso=importacao`、`acao=importar`、`banco=<旧数据库>`）会在一个事务中导入树、手表、照片、用户、字段以及全部历史（转换为记录）：如果中途停止，什么都不会保存。使用 `--substituir`（API 中为 `substituir=1`）时，会先清除本数据库中已有的内容。[`PORTE.md`](PORTE.md) 逐项记录了旧系统的每个行为是如何移植的。

---

<!-- parte 4 -->
## 内部结构 {#como-é-por-dentro}

- **纯 PHP 8.1+，配合 SQLite、MySQL/MariaDB、PostgreSQL 或 Firebird。** 没有框架、没有 Composer、没有依赖，可以在任何支持 PHP 的主机上运行。每种数据库通过对应的 PHP 扩展（`sqlite3`、`mysqli`、`pgsql`、`pdo_firebird`）；系统的 SQL 只写一次，由 [`banco.php`](banco.php) 转换各数据库之间的差异。
- **自研公式引擎**，带语法分析、历史函数、按分组的版本以及公式之间的依赖。
- **模拟：** 排程和预测会模拟未来的记录，并逐日重新计算公式，不保存任何东西。
- **纯 HTML 和 JavaScript 前端。** 每个 PHP 页面只提供骨架；页面的 `.js` 调用 API 并生成界面。**登录之前什么都不输出：**页面在输出第一行之前就在服务器上检查用户和密码（`lib.php` 中的 `pagina_login()`，由 `topo()` 调用），未通过则以 401 回应并弹出浏览器的密码框，不显示菜单也不显示骨架（如果取消，只显示“登录”，带**重试**和**创建账户**链接）。页面和 API 同时使用这次登录（同一个 realm）。只有**创建账户**，以及在还没有任何用户时的安装页面无需登录。脚本的 URL 中带有文件日期，避免浏览器缓存提供旧版本。
- **静默且可追踪的 cron：** 每分钟运行一次，不向输出写任何东西，并把每次运行记录在数据库中（没有活动的保留 7 天；有活动或错误的保留 1 年）。致命错误会写入数据库，如果连数据库都无法响应，则写入一个临时文件。
- **不会丢失的文档：** 每个文件都保存在文件夹中，如有要求（由系统、手表或文件本身决定），还会完整地保存在数据库中；cron 会重建丢失的文件、复制缺少的副本并清理多余的内容（参见[手表的文档](#os-documentos-do-relógio)）。
- **能自我保护的数据库：** 关系型且规范化，四种数据库上都有外键和校验规则（`CHECK`、`NOT NULL`、`UNIQUE`）：错误的写入会被数据库本身拒绝（参见[数据库模型](#o-modelo-do-banco)）。
- **安全失败：** 没有有效令牌或数据库过期时，系统会停止并说明原因，而不是只运行一半。
- **多人使用，由数据库隔离：**每个请求作用于一个收藏，数据库层会改写每条查询，使其只能看到该收藏（及其树）；任何界面或资源都无需记得筛选。你看不到的收藏返回 404，如同不存在。
- **六种语言：** 所有文本都经过 `t()`，以葡萄牙语为键，每种语言一个文件（参见[语言](#os-idiomas)）。

### 数据库模型 {#o-modelo-do-banco}

数据库是关系型且规范化的（自 v16 迁移起；自 v25 起共 60 张表）：每样东西一张表，表之间的每个关联都是一个**外键**，没有任何列表以文本形式存放在字段中。账户（`usuario`）、收藏（`colecao`，含所有者和树的根）、成员（`colecao_usuario`）、对某个所有者全部内容的访问（`acesso_dono`）、邀请、申请、令牌、Web Push 设备、推送目标（`destino` 以及每种类型一张表）和日历（`agenda` 以及每种类型一张表）。树（`no`）的根保存所有者，各分组指向上一级；它的定义（字段、类型、公式、提醒、分类）指向树中的一个分组；属于收藏的内容（手表、模式、计划、事件、设置、配件）指向收藏。可选的关联变成了单行表（配件的手表、区块的固定手表、计划某天的区块）。直接在数据库中删除也不会留下悬空的数据：

| 关联 | 删除上级时 | 适用于 |
|---|---|---|
| 必需（没有上级就没有这一行） | **一并删除**（`ON DELETE CASCADE`） | 账户会带走它的收藏和树；收藏会带走手表、模式、计划、推送目标和日历；树会带走使用它的收藏；分组会带走其中的分组、手表、字段、记录类型、公式和它的提醒；手表会带走记录、值、照片、文档、计划和它自己的标准；记录会带走消耗测量；依此类推 |
| 可选（该列可以为空） | **解除关联**（`ON DELETE SET NULL`） | 自定义事件的手表、模式区块的分组和固定手表、计划某天的区块、消除提醒的记录类型、配件的手表（配件变为独立） |

在界面上（**分组**，删除），删除分组时仍会先把属于它的内容（其下的分组、手表、字段、类型、公式和提醒）移到上一级分组；级联删除只针对直接在数据库中删除的情况。

列表变成了表：模式区块的日期（`modo_bloco_dia`）和每周事件的日期（`evento_dia`）、文档分类接受的文件类型（`documento_categoria_aceita`）、列表字段的选项（`campo_opcao`），以及每个推送目标和每个日历接收的内容（`destino_aviso`、`destino_evento`、`agenda_aviso`、`agenda_evento`）。当前使用的模式是模式的 `ativo` 列，消耗测量通过结束它的那条记录关联到手表。`config` 表只保存每个收藏的零散值，`sistema` 表保存整个系统的值。

有三种引用特意**通过名称**进行：公式和标准通过标识符引用字段和公式（就是你写的文本，例如 `HORAS("pulso"; 30)`），`destino_aviso` 和 `agenda_aviso` 通过标识符引用提醒，因为一个提醒可以有多个版本（每个分组一个），而选择对所有版本生效。当一个提醒的最后一个版本被删除时，系统会一并删除它的这些行。

**数据库会校验它保存的内容**（自迁移 v17 起），使用标准 SQL 的功能，而不仅仅依靠系统：错误的写入无论来自哪里（界面、API、有人直接改数据库）都会被拒绝。API 返回 `409`，详情中带有被违反的规则名。

| 功能 | 保证什么 |
|---|---|
| `NOT NULL` | 必填项已填写（例如每条记录和计划每一天的来源） |
| `CHECK` | 每个值都在有效范围内。共有 54 条规则，每条都有名称（`ck_<表>_<内容>`） |
| 封闭列表（`ENUM`，在其他数据库中变成 `CHECK ... IN`） | 某列接受的值：记录类型的格式、事件的重复方式、记录的来源（`manual`、`rodizio`、`importado`）以及计划某天的来源（`sorteio`、`manual`）…… |
| `UNIQUE` | 不能重复的内容：字段、类型、类别的标识符；文档的文件；**最多一个正在使用的模式** |
| `FOREIGN KEY` | 关联（见上文） |

`CHECK` 规则，按主题：

| 主题 | 数据库拒绝 |
|---|---|
| 文本 | 名称、标识符、标题、提醒文本、公式和设置键为空或只有空格 |
| 是/否标记 | 0 和 1 以外的任何值（可用、启用、独占、测量消耗、使用中、发送、数据库副本、仅心愿手表……） |
| 手表 | 不在列表中的收藏状态（`colecao`、`desejado`、`emprestado`、`vendido`、`arquivado`）；不在收藏中却可用的手表 |
| 记录类型 | 非时段格式却独占；非数值格式却"测量消耗"；非时段格式却有"结束于" |
| 记录和测量 | 结束早于开始；测得的消耗（速率）不在 0 到 100% 之间；小时数为负 |
| 提醒 | 提前量不在 0 到 3650 天之间；排程中模拟的小时数不在 0 到 48 之间 |
| 标准 | 权重不在 0 到 100% 之间；分数不在 0 到 100 之间；一个标准组同时属于某个分组**和**某块手表；区间既不是数值区间（带"从"）也不是类别；"从"为负；"到"不大于"从" |
| 轮换模式 | 智能排程不在 7 到 730 天之间；星期不在 1 到 7 之间 |
| 自定义事件 | 重复方式用不到的内容（非每月却有月中某日、非"每 N 天"却有间隔、非"一次"和"每 N 天"却有日期）以及缺少它需要的内容；月中某日不在 1 到 31 之间；间隔不在 1 到 3650 天之间；星期不在 1 到 7 之间 |
| 配件 | 名称为空；类型不在列表中（`pulseira`、`caixa`、`estojo`、`ferramenta`、`carregador`、`peca`、`outro`） |
| 文档 | 大小为零；SHA-256 不是 64 个字符；数据库副本的分块编号小于 -1（-1 是缩略图） |
| 手表照片 | 类型不是图片 |
| cron 运行记录 | 结束早于开始；时长为负 |

**最多一个正在使用的模式：** 正在使用的模式的 `ativo` 标记为 1，其他模式为空（`NULL`），该列有唯一键。标准 SQL 允许唯一键中有重复的空值，但不允许重复的 1：两个模式同时使用，数据库会拒绝。

有两条规则因为 MySQL 8 而没有加上，它不接受在 `id` 列（自增）上加 `CHECK`，也不接受在带 `ON DELETE SET NULL` 的外键列上加 `CHECK`："分组不能是自己的上级"（由系统检查，系统还会检查任何 `CHECK` 都覆盖不到的更大的循环）以及可选关联的规则。

### 文件 {#arquivos}

| 文件 | 是什么 |
|---|---|
| [`api.php`](api.php) | API，所有读写的入口（认证、格式、资源顺序、筛选、响应），文件头中有完整文档 |
| [`api/`](api/) | API 中较大的资源，每个一个文件（`hoje.php`、`ficha.php`、`configuracao.php`、`desejos.php`、`estatisticas.php`、`hora_certa.php`、`passaporte.php`、`galeria.php`、`ajuda.php`……），还有它的函数（`funcoes.php`）和写入（`escrita.php`）：它们在 `api.php` 内部运行，直接打开则什么都不做（`.htaccess` 和 `nginx-relogios.conf` 会阻止该文件夹） |
| [`lib.php`](lib.php) | 核心：登录、树、公式引擎、提醒、标准、轮换、排程、文档 |
| [`colecoes.php`](colecoes.php) | 核心中的收藏部分：请求的作用范围、`modelo.json`、加密的机密、`sistema` 表、v25 迁移和删除的影响 |
| [`alertas.php`](alertas.php) | 推送目标（webhook、电子邮件、Web Push）和日历（Google、CalDAV、Outlook、ICS） |
| [`contas.php`](contas.php) | 账户：公开注册、我的账户、收藏和树、邀请、申请、令牌以及 master 面板 |
| [`banco.php`](banco.php) | 数据库：与 MySQL、Postgres、SQLite 或 Firebird 的连接，以及 SQL 在它们之间的转换 |
| [`operacoes.php`](operacoes.php) | 每次写入的规则：校验和消息 |
| [`cron.php`](cron.php) | 计划、当天的时段、早上和晚上两轮、事件、日历以及文档的维护 |
| `index.php`、`plano.php`、`desejos.php`、`estatisticas.php`、`hora.php`、`acessorios.php`、`galeria.php`、`passaporte.php`、`ficha.php`、`documentos.php`、`historico.php`、`avisos.php`、`modos.php`、`configuracao.php`、`destinos.php`、`agendas.php`、`criterios.php`、`grupos.php`、`cadastros.php`、`execucoes.php`、`conta.php`、`aprovacoes.php`、`usuarios.php`、`cadastro.php` | 页面（只有骨架；`cadastro.php` 是公开页面） |
| `relojinho.js` | 菜单栏和网站图标上的小手表：24 种用 canvas 绘制、显示准确时间的手表，每小时一块（按顺序、抽取或不重复） |
| `sw.js`、`offline.html`、`icone-*.png` | 手机上的应用：*service worker*（由 `recurso=sw` 提供，前面带版本号：界面缓存、无连接页面和 Web Push）、**没有网络连接**页面和图标（192、512 和 180 px） |
| `ajuda.php` | 帮助页面：只有骨架；`ajuda.js` 向 API 请求文本（`recurso=manual`：README 中面向用户的章节，已在 `lib.php` 中转换为 HTML） |
| [`pagina.php`](pagina.php) | 页面顶部的菜单和页面结尾；`topo()` 在输出任何内容之前检查登录（`pagina_login()`） |
| `api.js`、`hoje.js`、`painel.js`、`tabela.js`、`foto.js`…… | 各个界面，在浏览器中根据 API 生成 |
| [`estilo.css`](estilo.css) | 外观 |
| [`textos/`](textos/) | 语言：`funcoes.php`（`t()` 函数、语言列表、请求的语言、每种语言的日期和数字格式）以及每种语言一个文件（`en.php`、`zh-CN.php`、`hi.php`、`es.php`、`fr.php`：葡萄牙语文本 => 译文，按部分划分）；直接打开则什么都不做 |
| [`schema.sql`](schema.sql) | 数据库结构（只有结构：不含数据） |
| [`modelo.json`](modelo.json) | 新收藏的初始集合（分组、字段、类型、公式、提醒、标准、分类、模式、消息），不含固定代码 |
| `migracao_v2.sql` … `migracao_v24.sql` | 迁移，通过设置页面应用 |
| [`config.exemplo.php`](config.exemplo.php) | `config.php` 的模板 |
| [`instalar.php`](instalar.php) | 把 `schema.sql` 安装到 `config.php` 指定的数据库中，任何一种都可以（与 `recurso=instalacao` 相同） |
| [`criar_usuario.php`](criar_usuario.php) | 在命令行中创建用户或修改密码（与 `recurso=usuarios`、`acao=salvar` 相同） |
| [`importar.php`](importar.php) | 导入旧系统的数据（与 `recurso=importacao` 相同） |
| [`reconstrucao.php`](reconstrucao.php) | 从零重写系统的指南（API 的 `recurso=reconstrucao`） |
| [`.htaccess`](.htaccess) | Apache 上对内部文件的保护（以及 PHP 以 FastCGI 运行时的网站登录） |
| [`nginx-relogios.conf`](nginx-relogios.conf) | 保护内部文件的 nginx 配置块 |
| [`testes/`](testes/) | 一致性测试：在每种数据库上通过 API 运行同一个脚本，以及响应的比较器；所有界面从手机到电脑的布局检查（`layout.cjs`；`LINGUA=en` 在某种语言下检查）；各语言词典的检查（`textos.php`）；每种语言 README 的生成（`readme.php`）；以及架构检查（`arquitetura.php`：代码必须遵守的规则） |
| [`docs/telas/`](docs/telas/) | 本 README 的截图 |
| `README.md`、`README.en.md`、`README.zh-CN.md`、`README.hi.md`、`README.es.md`、`README.fr.md` | 本 README：葡萄牙语原文和其他语言版本。不要手动编辑：[`testes/readme.php`](testes/readme.php) 会把翻译后的手册（`textos/manual/<语言>.md`，帮助页面显示的章节）和翻译后的其余部分（`textos/readme/<语言>.md`：开头、试用、API、安装、内部结构、如何贡献和许可证）拼接成每种语言的版本 |
| [`PORTE.md`](PORTE.md) | 从上一版本移植的记录 |

---

## 如何贡献 {#como-contribuir}

欢迎改进，途径始终是这个代码仓库：

1. 较大的改动，**请先开一个 issue** 说明你的打算：避免做了却无法合并的工作。
2. 在 GitHub 上 fork，创建分支并修改。fork 只用于准备提案（参见[许可证](#licença)）。
3. **遵循代码的风格：** 纯 PHP 和 JavaScript，无依赖，注释和消息使用葡萄牙语（翻译放在 `textos/` 中）。关于手表的知识放进初始集合（`modelo.json`），而不是代码中。
4. 修改了数据库？创建一个 `migracao_vN.sql` 并在 `lib.php` 的 `$MIGRACOES` 中登记：结构用 MySQL 方言，数据用四种数据库通用的 SQL（参见[数据库更新](#atualizações-do-banco)）。
5. 修改了 API？在帮助的词典中**解释每个新字段**：`api/ajuda.php` 中 `recurso=ajuda` 的 `"campos"`（资源对应的部分，以字段路径为键）。它是唯一的来源：`api.php` 文件头中的词典由它生成，使用 `php testes/dicionario.php --gravar`（不要手动修改文件头）。
6. 提交之前，**在四种数据库上运行一致性测试**（[`testes/`](testes/)）（GitHub 也会在每次 push 到 `main` 时运行：一致性测试、文档和布局，见 [`.github/workflows/testes.yml`](.github/workflows/testes.yml)；结果显示在 **Actions** 标签页以及每个提交旁边的 ✓ / ✗ 中）：`testes/paridade.sh` 在数据库都运行时必须以 `Tudo igual.` 结束。它还会检查文档：文件头就是帮助生成的内容，响应的每个字段都有解释。它还检查架构（[`testes/arquitetura.php`](testes/arquitetura.php)，GitHub 也会在一个不需要数据库的快速步骤中运行它）：页面只提供骨架，界面的网络请求只经过 API，数据库只由核心、API 和 cron 访问，内部文件在 Web 上被屏蔽，每个操作和每个 `config.php` 常量都有文档，以及文件开头列出的其他规则。如果 PHP 在运行期间给出任何警告（Warning、Notice、Deprecated），它也会失败。修改了界面？还要运行布局检查（`testes/paridade.sh --layout sqlite`）：`0 problemas`。
7. **每个新文本都要经过 `t()`**（`api.js` 和 `textos/funcoes.php` 中都有这个函数），以葡萄牙语为键，参数放在花括号中：`t("Excluir {1}?", {1: nome})`，绝不要写 `"Excluir " + nome + "?"`。界面和 API 中不能有直接写死的文本：GitHub 用两种方式检查。`testes/arquitetura.php` 的第 11 条规则拒绝在 `t()` 之外进入界面的引号文本（故意不翻译的内容，例如品牌名，放在 `fixo("...")` 中）；`LINGUA=zh-CN testes/paridade.sh --textos sqlite` 把整个系统切换成中文后打开所有界面，凡是既不是用户数据、也不来自词典的拉丁字母单词都会被标出。然后在 `textos/` 的五个文件中翻译，并运行 `php testes/textos.php`（一致性测试也会运行它）：必须在没有任何缺失的情况下结束。修改了 README？它的翻译带有所翻译版本的标记（`textos/manual/<语言>.md` 和 `textos/readme/<语言>.md` 的第一行），`php testes/textos.php` 会指出落后的翻译：翻译修改的部分，换上新的标记（`php testes/textos.php --manual` 和 `php testes/readme.php --original` 会给出带标记的原文），然后运行 `php testes/readme.php`，它会重新生成各个 `README.<语言>.md`。
8. 提交 pull request 时，说明对用户来说有什么变化以及你是如何测试的。

---

## 许可证 {#licença}

Relojoeiro 是**源代码可见**的软件，不是自由软件：代码公开可见，但有所有者和使用规则。简而言之（以 [`LICENSE`](LICENSE) 的葡萄牙语原文为准）：

| ✅ 可以 | ❌ 不可以 |
|---|---|
| 为你自己、你的家人或你的公司安装和使用 | 基于这些代码创建新项目，无论全部还是部分，也不能用其他语言重写 |
| 修改你自己安装的代码 | 在本仓库之外分发代码，无论原样还是修改过 |
| 在这里通过 pull request 提出改进 | 把系统作为服务提供给其他人 |
| 在 GitHub 上 fork 以准备这些提案 | 把 fork 维持为独立项目 |

其他任何用途，请通过 GitHub 申请授权（[Challado](https://github.com/Challado)）。

> 💡 **贡献，而不是复制。** 成为 Relojoeiro 的贡献者远比 fork 好：你的改进惠及所有人，历史中留有你的名字，而且你没有任何风险。而变成独立项目的 fork 违反许可证，本代码的权利会得到维护：向平台申请下架、提起索赔诉讼，甚至刑事诉讼（巴西第 9.609/98 号法律把侵犯软件著作权视为犯罪）。即使自认为有理的人也可能需要为自己辩护，这要花时间和律师费。**有疑问时，请先问：** 回答是免费的，事后的争执可不是。

---

<p align="center"><sub>献给手表比手腕多的人。⌚</sub></p>
