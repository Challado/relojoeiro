<?php
// Relógios 2: o banco. O sistema fala com MySQL/MariaDB, PostgreSQL, SQLite ou Firebird, cada um pela extensão do PHP
// (mysqli, pgsql, sqlite3, pdo_firebird), escolhido por DB_TIPO no config.php ("mysql", o padrão; "pgsql"; "sqlite";
// "firebird"). O resto do código escreve o SQL uma vez só, no dialeto do MySQL, e chama sql(), linhas(), linha(), valor();
// daqui para dentro:
//   - a tradução: o "?" vira $1, $2... no Postgres; REPLACE INTO, INSERT IGNORE e NOW() viram o equivalente de cada banco;
//     os NULL vêm primeiro no ORDER BY crescente, como no MySQL; os textos com barra invertida ('a\nb') viram o texto
//     que o MySQL entende; no Firebird, também o LIMIT/OFFSET (OFFSET ... ROWS FETCH NEXT ... ROWS ONLY), o DATE(x)
//     (CAST(x AS DATE)), o <=> (IS NOT DISTINCT FROM), a tabela "no" (uma palavra reservada dele: vai entre aspas, "NO")
//     e o INSERT com várias linhas (um INSERT por linha); no DDL (a instalação e as migrações): AUTO_INCREMENT, ENUM,
//     TINYINT, DATETIME, os INDEX dentro do CREATE TABLE, o AFTER e o MODIFY COLUMN, e no Firebird os nomes de índice e de
//     regra com mais de 31 letras (encurtados com um pedaço do md5 do nome inteiro);
//   - os valores que voltam iguais nos quatro: inteiro como inteiro, decimal como texto com as casas ("68.00"), data e
//     hora como "2026-09-27 14:05:00", o binário (a foto) como binário;
//   - maiúsculas e acentos: no MySQL, "Relógio" = "relogio" (a collation do banco). Nos outros, as colunas VARCHAR ganham
//     a collation que faz o mesmo: no Postgres, "ci", uma collation ICU não determinística; no SQLite, "ci", uma função
//     do PHP; no Firebird, UNICODE_CI_AI (também nas colunas TEXT, como no MySQL). No Postgres e no SQLite, as colunas
//     TEXT (os textos longos: fórmulas, mensagens, registros) ficam na comparação exata. No Firebird, o TEXT é um
//     VARCHAR(8191): o texto longo vai até 8191 letras (no MySQL, 65535 bytes); o LONGTEXT (o texto livre, sem limite,
//     que nunca ordena nem agrupa) é o BLOB SUB_TYPE TEXT.
// O schema.sql (a instalação) e as migrações futuras continuam num arquivo só: o DDL no dialeto do MySQL (a tradução
// cuida do resto) e os dados em SQL comum aos quatro bancos.

class BancoErro extends RuntimeException
{
    // o banco recusou a gravação por uma regra de integridade (um registro que outro usa, um valor repetido onde não
    // pode, um valor fora da regra de validação): não é o banco fora do ar, é a gravação que estava errada
    public $integridade = false;

    public function __construct($mensagem, $codigo = 0, $anterior = null, $integridade = false)
    {
        parent::__construct((string)$mensagem, (int)$codigo, $anterior);
        $this->integridade = (bool)$integridade;
    }
}

// O erro de cada extensão como BancoErro, dizendo se foi uma regra de integridade (chave estrangeira, chave única, NOT NULL,
// CHECK): SQLSTATE 23xxx no MySQL e no Postgres (e o 3819 do CHECK no MySQL 8), o código 19 (SQLITE_CONSTRAINT) no SQLite,
// e no Firebird o SQLSTATE 23xxx ou os códigos SQL -530 (chave estrangeira), -803 (chave única), -297 (CHECK) e -625 (NOT NULL)
function banco_erro($e)
{
    if ($e instanceof BancoErro) {
        return $e;
    }
    if ($e instanceof PDOException) {
        $info = $e->errorInfo ?? [];
        $estado = (string)($info[0] ?? $e->getCode());
        $codigo = (int)($info[1] ?? 0);
        $msg = trim((string)preg_replace("/\\s+/", " ", (string)($info[2] ?? $e->getMessage())));
        return new BancoErro($msg !== "" ? $msg : $e->getMessage(), $codigo, $e, strpos($estado, "23") === 0 || in_array($codigo, [-530, -803, -297, -625], true));
    }
    if ($e instanceof mysqli_sql_exception) {
        // a regra de validação (CHECK) quebrada: no MariaDB vem como 23000; no MySQL 8, o erro 3819 (SQLSTATE HY000)
        return new BancoErro($e->getMessage(), $e->getCode(), $e, strpos((string)$e->getSqlState(), "23") === 0 || (int)$e->getCode() === 3819);
    }
    return new BancoErro($e->getMessage(), $e->getCode(), $e, banco_tipo() === "sqlite" && (int)$e->getCode() === 19);
}

function banco_tipo()
{
    static $tipo = null;
    if ($tipo === null) {
        $tipo = defined("DB_TIPO") ? strtolower(trim((string)constant("DB_TIPO"))) : "mysql";
        if ($tipo === "mariadb") {
            $tipo = "mysql";
        } elseif ($tipo === "postgres" || $tipo === "postgresql") {
            $tipo = "pgsql";
        } elseif ($tipo === "sqlite3") {
            $tipo = "sqlite";
        } elseif ($tipo === "fb" || $tipo === "interbase") {
            $tipo = "firebird";
        }
        if (!in_array($tipo, ["mysql", "pgsql", "sqlite", "firebird"], true)) {
            throw new BancoErro(t("DB_TIPO no config.php tem de ser mysql, pgsql, sqlite ou firebird (está \"{1}\")", [1 => $tipo]));
        }
    }
    return $tipo;
}

function db()
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $porta = defined("DB_PORTA") ? (int)constant("DB_PORTA") : 0;
    switch (banco_tipo()) {
        case "mysql":
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            try {
                $c = new mysqli(DB_HOST, DB_USUARIO, DB_SENHA, DB_NOME, $porta > 0 ? $porta : null);
                $c->set_charset("utf8mb4");
                // o NOW() no fuso do sistema, e não no do servidor do banco (o deslocamento de agora: "-03:00"; o nome do fuso
                // só funciona com as tabelas de fuso carregadas no MySQL)
                $c->query("SET time_zone = '" . date("P") . "'");
            } catch (mysqli_sql_exception $e) {
                throw new BancoErro($e->getMessage(), (int)$e->getCode(), $e);
            }
            break;
        case "pgsql":
            if (!function_exists("pg_connect")) {
                throw new BancoErro(t("o PHP não tem a extensão {1} (no php.ini: extension={1})", [1 => "pgsql"]));
            }
            $par = function ($v) {
                return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
            };
            $texto = "host=" . $par(DB_HOST) . " dbname=" . $par(DB_NOME) . " user=" . $par(DB_USUARIO) . " password=" . $par(DB_SENHA)
                . ($porta > 0 ? " port=" . $porta : "") . " options='--client_encoding=UTF8'";
            $antes = error_reporting(0);
            $c = pg_connect($texto, PGSQL_CONNECT_FORCE_NEW);
            error_reporting($antes);
            if ($c === false) {
                $e = error_get_last();
                $c = null;
                throw new BancoErro(t("não consegui abrir o banco no {1}: {2}", [1 => "PostgreSQL", 2 => trim((string)($e["message"] ?? ""))]));
            }
            // as datas no formato do MySQL, e o NOW() no fuso do sistema
            banco_direto("SET DateStyle TO ISO, YMD", $c);
            banco_direto("SET TIME ZONE '" . str_replace("'", "''", date_default_timezone_get()) . "'", $c);
            break;
        case "sqlite":
            if (!class_exists("SQLite3")) {
                throw new BancoErro(t("o PHP não tem a extensão {1} (no php.ini: extension={1})", [1 => "sqlite3"]));
            }
            $arquivo = defined("DB_ARQUIVO") ? (string)constant("DB_ARQUIVO") : "";
            if ($arquivo === "") {
                throw new BancoErro(t("com DB_TIPO sqlite, o config.php tem de definir DB_ARQUIVO (o caminho do arquivo do banco)"));
            }
            try {
                $c = new SQLite3($arquivo);
            } catch (Exception $e) {
                throw new BancoErro(t("não consegui abrir o banco SQLite em {1}: {2}", [1 => $arquivo, 2 => $e->getMessage()]));
            }
            $c->enableExceptions(true);
            // o cron e as páginas ao mesmo tempo: quem chega espera a vez, em vez de "database is locked"
            $c->busyTimeout(15000);
            $c->exec("PRAGMA journal_mode = WAL");
            $c->exec("PRAGMA synchronous = NORMAL");
            $c->exec("PRAGMA foreign_keys = ON");
            $c->createCollation("ci", function ($a, $b) {
                return strcmp(banco_chave_ci($a), banco_chave_ci($b));
            });
            // as funções do MySQL que o SQL do sistema usa, com o comportamento do MySQL (NULL em qualquer argumento: NULL)
            $c->createFunction("NOW", function () {
                return date("Y-m-d H:i:s");
            }, 0);
            $c->createFunction("CONCAT", function (...$a) {
                return in_array(null, $a, true) ? null : implode("", $a);
            });
            $c->createFunction("CONCAT_WS", function ($sep, ...$a) {
                return $sep === null ? null : implode($sep, array_filter($a, function ($x) {
                    return $x !== null;
                }));
            });
            $c->createFunction("LEAST", function (...$a) {
                return in_array(null, $a, true) ? null : min($a);
            });
            $c->createFunction("GREATEST", function (...$a) {
                return in_array(null, $a, true) ? null : max($a);
            });
            break;
        case "firebird":
            if (!extension_loaded("pdo_firebird")) {
                throw new BancoErro(t("o PHP não tem a extensão {1} (no php.ini: extension={1})", [1 => "pdo_firebird"]));
            }
            // o banco: DB_NOME é o caminho do arquivo .fdb no servidor (ou um apelido do databases.conf); DB_HOST vazio
            // abre o arquivo direto, sem servidor (o Firebird embutido)
            $host = trim((string)(defined("DB_HOST") ? DB_HOST : ""));
            $dsn = "firebird:dbname=" . ($host !== "" ? $host . ($porta > 0 ? "/" . $porta : "") . ":" : "") . DB_NOME . ";charset=UTF8";
            try {
                $c = new PDO($dsn, DB_USUARIO, DB_SENHA, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_CASE => PDO::CASE_LOWER]);
            } catch (PDOException $e) {
                $c = null;
                throw new BancoErro(t("não consegui abrir o banco no {1}: {2}", [1 => "Firebird", 2 => trim($e->getMessage())]));
            }
            break;
    }
    return $c;
}

// A chave de comparação sem maiúsculas nem acentos ("Relógio" e "RELOGIO" dão "relogio"), sem depender do mbstring
function banco_chave_ci($s)
{
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        foreach (["a" => "ÀÁÂÃÄÅàáâãäåĀāĂăĄą", "c" => "ÇçĆćČč", "d" => "Ďď", "e" => "ÈÉÊËèéêëĒēĘęĚě", "g" => "Ğğ", "i" => "ÌÍÎÏìíîïĪīİı",
            "l" => "Łł", "n" => "ÑñŃńŇň", "o" => "ÒÓÔÕÖØòóôõöøŌōŐő", "r" => "Řř", "s" => "ŚśŞşŠš", "t" => "Ťť", "u" => "ÙÚÛÜùúûüŪūŮůŰű",
            "y" => "ÝýÿŸ", "z" => "ŹźŻżŽž"] as $base => $letras) {
            preg_match_all("/./u", $letras, $m);
            foreach ($m[0] as $l) {
                $mapa[$l] = $base;
            }
        }
        $mapa += ["Æ" => "ae", "æ" => "ae", "Œ" => "oe", "œ" => "oe", "ß" => "ss"];
    }
    return strtolower(strtr((string)$s, $mapa));
}

// ---------------------------------------------------------------------------------------------------------------------
// Consultas
// ---------------------------------------------------------------------------------------------------------------------

// ---------------------------------------------------------------------------------------------------------------------
// O escopo da coleção. Cada pedido trabalha numa coleção (e na árvore de grupos que ela usa): com o escopo ligado
// (banco_escopo), todo comando passa por aqui antes da tradução, e cada tabela da coleção que ele lê (FROM e JOIN) vira
// a visão só da coleção, um SELECT com a condição dela; o UPDATE e o DELETE ganham a mesma condição no WHERE. Assim nenhuma
// consulta enxerga outra coleção, e o código continua escrevendo o SQL como se só houvesse uma.
// A visão mostra também a forma de antes da coleção, para o código de sempre: a raiz da árvore (o grupo sem pai, que
// guarda o dono) não aparece; quem aponta para ela aparece com o grupo vazio ("na raiz", "todos"); e as ligações opcionais
// (o relógio de um acessório, de um evento, o relógio fixo de um bloco, o bloco de um dia do plano) aparecem como a
// coluna de antes. Na gravação, o código põe a coleção e a raiz (colecao_id, no_raiz()) e grava as ligações.
// Sem escopo (a instalação, as migrações, a cópia de segurança, o cron entre uma coleção e outra, o master no painel),
// o comando vai como está: sem_escopo() roda um pedaço assim.
// ---------------------------------------------------------------------------------------------------------------------

// A condição de cada tabela da coleção, com as colunas dela: {C} é a coleção, {N} os grupos da árvore (menos a raiz), {R}
// a raiz e {RC} os relógios da coleção
const ESCOPO_CONDICOES = [
    "no" => "id IN ({N})",
    "relogio" => "colecao_id = {C}",
    "campo" => "no_id IN ({NR})",
    "lancamento_tipo" => "no_id IN ({NR})",
    "formula" => "no_id IN ({NR})",
    "total" => "no_id IN ({NR})",
    "calibre" => "no_id IN ({NR})",
    "calibre_valor" => "calibre_id IN (SELECT id FROM calibre WHERE no_id IN ({NR}))",
    "calibre_valor_item" => "calibre_id IN (SELECT id FROM calibre WHERE no_id IN ({NR}))",
    "relogio_calibre" => "relogio_id IN ({RC})",
    "aviso" => "no_id IN ({NR})",
    "documento_categoria" => "no_id IN ({NR})",
    "campo_opcao" => "campo_id IN (SELECT id FROM campo WHERE no_id IN ({NR}))",
    "unidade" => "no_id IN ({NR})",
    "campo_secao" => "no_id IN ({NR})",
    "documento_categoria_aceita" => "categoria_id IN (SELECT id FROM documento_categoria WHERE no_id IN ({NR}))",
    "criterio_parametro" => "(escopo_no_id IN ({NR}) OR escopo_relogio_id IN ({RC}))",
    "criterio_sub" => "parametro_id IN (SELECT id FROM criterio_parametro WHERE escopo_no_id IN ({NR}) OR escopo_relogio_id IN ({RC}))",
    "criterio_faixa" => "sub_id IN (SELECT s.id FROM criterio_sub s JOIN criterio_parametro p ON p.id = s.parametro_id WHERE p.escopo_no_id IN ({NR}) OR p.escopo_relogio_id IN ({RC}))",
    "foto" => "relogio_id IN ({RC})",
    "campo_valor" => "relogio_id IN ({RC})",
    "campo_valor_item" => "relogio_id IN ({RC})",
    "lancamento" => "relogio_id IN ({RC})",
    "medicao" => "lancamento_id IN (SELECT id FROM lancamento WHERE relogio_id IN ({RC}))",
    "documento" => "(relogio_id IN ({RC}) OR calibre_id IN (SELECT id FROM calibre WHERE no_id IN ({NR})) OR winder_id IN (SELECT id FROM winder WHERE colecao_id = {C}))",
    "documento_parte" => "documento_id IN (SELECT id FROM documento WHERE relogio_id IN ({RC}) OR calibre_id IN (SELECT id FROM calibre WHERE no_id IN ({NR})) OR winder_id IN (SELECT id FROM winder WHERE colecao_id = {C}))",
    "servico" => "relogio_id IN ({RC})",
    "servico_peca" => "servico_id IN (SELECT id FROM servico WHERE relogio_id IN ({RC}))",
    "servico_documento" => "servico_id IN (SELECT id FROM servico WHERE relogio_id IN ({RC}))",
    "reserva_teste" => "relogio_id IN ({RC})",
    "timegrapher" => "relogio_id IN ({RC})",
    "acessorio" => "colecao_id = {C}",
    "acessorio_relogio" => "relogio_id IN ({RC})",
    "acessorio_valor" => "acessorio_id IN (SELECT id FROM acessorio WHERE colecao_id = {C})",
    "acessorio_valor_item" => "acessorio_id IN (SELECT id FROM acessorio WHERE colecao_id = {C})",
    "winder" => "colecao_id = {C}",
    "winder_valor" => "winder_id IN (SELECT id FROM winder WHERE colecao_id = {C})",
    "winder_valor_item" => "winder_id IN (SELECT id FROM winder WHERE colecao_id = {C})",
    "winder_posicao" => "winder_id IN (SELECT id FROM winder WHERE colecao_id = {C})",
    "winder_posicao_valor" => "posicao_id IN (SELECT p.id FROM winder_posicao p JOIN winder w ON w.id = p.winder_id WHERE w.colecao_id = {C})",
    "winder_posicao_valor_item" => "posicao_id IN (SELECT p.id FROM winder_posicao p JOIN winder w ON w.id = p.winder_id WHERE w.colecao_id = {C})",
    "winder_movimento" => "colecao_id = {C}",
    "winder_programa" => "colecao_id = {C}",
    "winder_programa_passo" => "programa_id IN (SELECT id FROM winder_programa WHERE colecao_id = {C})",
    "winder_posicao_programa" => "posicao_id IN (SELECT p.id FROM winder_posicao p JOIN winder w ON w.id = p.winder_id WHERE w.colecao_id = {C})",
    "winder_lugar" => "posicao_id IN (SELECT p.id FROM winder_posicao p JOIN winder w ON w.id = p.winder_id WHERE w.colecao_id = {C})",
    "winder_limite_ok" => "winder_id IN (SELECT id FROM winder WHERE colecao_id = {C})",
    "lancamento_winder" => "lancamento_id IN (SELECT id FROM lancamento WHERE relogio_id IN ({RC}))",
    "config" => "colecao_id = {C}",
    "modo" => "colecao_id = {C}",
    "modo_bloco" => "modo_id IN (SELECT id FROM modo WHERE colecao_id = {C})",
    "modo_bloco_relogio" => "relogio_id IN ({RC})",
    "modo_bloco_dia" => "bloco_id IN (SELECT b.id FROM modo_bloco b JOIN modo m ON m.id = b.modo_id WHERE m.colecao_id = {C})",
    "plano" => "colecao_id = {C}",
    "plano_bloco" => "colecao_id = {C}",
    "plano_livre" => "colecao_id = {C}",
    "evento_personalizado" => "colecao_id = {C}",
    "evento_relogio" => "relogio_id IN ({RC})",
    "evento_dia" => "evento_id IN (SELECT id FROM evento_personalizado WHERE colecao_id = {C})",
    "evento_disparo" => "evento_id IN (SELECT id FROM evento_personalizado WHERE colecao_id = {C})",
    "destino" => "colecao_id = {C}",
    "destino_webhook" => "destino_id IN (SELECT id FROM destino WHERE colecao_id = {C})",
    "destino_webhook_campo" => "destino_id IN (SELECT id FROM destino WHERE colecao_id = {C})",
    "destino_webhook_cabecalho" => "destino_id IN (SELECT id FROM destino WHERE colecao_id = {C})",
    "destino_email" => "destino_id IN (SELECT id FROM destino WHERE colecao_id = {C})",
    "destino_webpush" => "destino_id IN (SELECT id FROM destino WHERE colecao_id = {C})",
    "destino_aviso" => "destino_id IN (SELECT id FROM destino WHERE colecao_id = {C})",
    "destino_evento" => "destino_id IN (SELECT id FROM destino WHERE colecao_id = {C})",
    "agenda" => "colecao_id = {C}",
    "agenda_google" => "agenda_id IN (SELECT id FROM agenda WHERE colecao_id = {C})",
    "agenda_caldav" => "agenda_id IN (SELECT id FROM agenda WHERE colecao_id = {C})",
    "agenda_outlook" => "agenda_id IN (SELECT id FROM agenda WHERE colecao_id = {C})",
    "agenda_ics" => "agenda_id IN (SELECT id FROM agenda WHERE colecao_id = {C})",
    "agenda_aviso" => "agenda_id IN (SELECT id FROM agenda WHERE colecao_id = {C})",
    "agenda_evento" => "agenda_id IN (SELECT id FROM agenda WHERE colecao_id = {C})",
    "agenda_sincronizado" => "agenda_id IN (SELECT id FROM agenda WHERE colecao_id = {C})",
    "cron_execucao_colecao" => "colecao_id = {C}",
];

// As tabelas que a visão mostra na forma de antes: as colunas, na ordem; "*" marca a coluna que aponta para um grupo (a
// raiz aparece vazia), e uma coluna com SQL é a ligação opcional, vista como a coluna de antes (t_ é a tabela)
const ESCOPO_VISOES = [
    "no" => ["id", "pai_id*", "dono_id", "nome", "ordem"],
    "relogio" => ["id", "colecao_id", "nome", "apelido", "no_id*", "disponivel", "criado", "copia_banco", "posse"],
    "campo" => ["id", "identificador", "nome", "tipo", "unidade", "padrao", "no_id*", "ordem", "so_desejado", "calibre", "secao_id", "minimo", "maximo", "multiplo", "alvo", "acessorio_tipo",
        "serve_campo_id"],
    "lancamento_tipo" => ["id", "identificador", "nome", "formato", "unidade", "fecha_as", "no_id*", "ordem", "exclusiva", "mede_gasto", "marcha", "winder", "condicao"],
    "formula" => ["id", "identificador", "nome", "expressao", "unidade", "no_id*"],
    "aviso" => ["id", "identificador", "nome", "expressao", "antecedencia_dias", "texto", "ativo", "no_id*", "escala", "simula_valor", "simula_horas", "agenda",
        "condicao", "resolve_tipo_id", "na_hora"],
    "documento_categoria" => ["id", "identificador", "nome", "ordem", "no_id*"],
    "criterio_parametro" => ["id", "escopo_no_id*", "escopo_relogio_id", "nome", "peso", "ordem"],
    "modo_bloco" => ["id", "modo_id", "nome", "no_id*", "um_por", "ordem", "relogio_id" => "(SELECT x_.relogio_id FROM modo_bloco_relogio x_ WHERE x_.bloco_id = t_.id)"],
    "acessorio" => ["id", "colecao_id", "nome", "tipo", "observacao", "criado", "relogio_id" => "(SELECT x_.relogio_id FROM acessorio_relogio x_ WHERE x_.acessorio_id = t_.id)"],
    "evento_personalizado" => ["id", "colecao_id", "nome", "ativo", "repeticao", "data_inicio", "hora", "dia_mes", "intervalo_dias", "criado",
        "relogio_id" => "(SELECT x_.relogio_id FROM evento_relogio x_ WHERE x_.evento_id = t_.id)"],
    "plano" => ["colecao_id", "data", "relogio_id", "origem", "criado", "acao", "motivo",
        "bloco_id" => "(SELECT x_.bloco_id FROM plano_bloco x_ WHERE x_.colecao_id = t_.colecao_id AND x_.data = t_.data)"],
];

// Liga o escopo numa coleção (null desliga). Guarda a raiz da árvore dela e os grupos da árvore, lidos sem escopo
function banco_escopo($colecao_id)
{
    if ($colecao_id === null) {
        $GLOBALS["BANCO_ESCOPO"] = null;
        return null;
    }
    $GLOBALS["BANCO_ESCOPO"] = null;
    $raiz = valor("SELECT no_id FROM colecao WHERE id = ?", [(int)$colecao_id]);
    $GLOBALS["BANCO_ESCOPO"] = ["colecao" => (int)$colecao_id, "raiz" => $raiz === null ? 0 : (int)$raiz, "nos" => [], "versao" => 0];
    // as contas guardadas das fórmulas (os lançamentos lidos, as médias) eram de outra coleção
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    banco_escopo_nos();
    return $GLOBALS["BANCO_ESCOPO"];
}

// O escopo de agora: {colecao, raiz, nos}, ou null
function banco_escopo_atual()
{
    return $GLOBALS["BANCO_ESCOPO"] ?? null;
}

// Relê os grupos da árvore da coleção do escopo (depois de criar um grupo): da raiz para baixo, nível a nível
function banco_escopo_nos()
{
    $e = $GLOBALS["BANCO_ESCOPO"] ?? null;
    if ($e === null) {
        return;
    }
    $GLOBALS["BANCO_ESCOPO"] = null;
    $nos = [];
    $nivel = $e["raiz"] > 0 ? [$e["raiz"]] : [];
    while (count($nivel) > 0) {
        $nos = array_merge($nos, $nivel);
        $nivel = array_map("intval", array_column(linhas("SELECT id FROM no WHERE pai_id IN (" . implode(", ", $nivel) . ")"), "id"));
        $nivel = array_values(array_diff($nivel, $nos));
    }
    $e["nos"] = $nos;
    static $versao = 0;
    $e["versao"] = ++$versao;
    $GLOBALS["BANCO_ESCOPO"] = $e;
}

// Roda uma função sem o escopo (e volta o escopo de antes, mesmo se ela falhar)
function sem_escopo($f)
{
    $antes = $GLOBALS["BANCO_ESCOPO"] ?? null;
    $GLOBALS["BANCO_ESCOPO"] = null;
    try {
        return $f();
    } finally {
        $GLOBALS["BANCO_ESCOPO"] = $antes;
    }
}

// A condição de uma tabela no escopo de agora (as colunas sem prefixo; null: a tabela não é da coleção)
function escopo_condicao($tabela, $e)
{
    if (!isset(ESCOPO_CONDICOES[$tabela])) {
        return null;
    }
    $nos = array_values(array_diff($e["nos"], [$e["raiz"]]));
    $troca = ["{C}" => (string)$e["colecao"], "{R}" => (string)$e["raiz"], "{N}" => count($nos) > 0 ? implode(", ", $nos) : "0",
        "{NR}" => count($e["nos"]) > 0 ? implode(", ", $e["nos"]) : "0", "{RC}" => "SELECT id FROM relogio WHERE colecao_id = " . $e["colecao"]];
    return strtr(ESCOPO_CONDICOES[$tabela], $troca);
}

// A visão de uma tabela no escopo: o SELECT que entra no lugar dela
function escopo_visao($tabela, $e)
{
    $cond = escopo_condicao($tabela, $e);
    if (!isset(ESCOPO_VISOES[$tabela])) {
        return "(SELECT * FROM " . $tabela . " WHERE " . $cond . ")";
    }
    $cols = [];
    foreach (ESCOPO_VISOES[$tabela] as $k => $c) {
        if (is_string($k)) {
            $cols[] = $c . " AS " . $k;
        } elseif (substr($c, -1) === "*") {
            $c = substr($c, 0, -1);
            $cols[] = "CASE WHEN t_." . $c . " = " . $e["raiz"] . " THEN NULL ELSE t_." . $c . " END AS " . $c;
        } else {
            $cols[] = "t_." . $c;
        }
    }
    // (as colunas da condição, sem prefixo, são as da tabela: a única do FROM)
    return "(SELECT " . implode(", ", $cols) . " FROM " . $tabela . " t_ WHERE " . $cond . ")";
}

// O comando com o escopo aplicado (no dialeto do MySQL; a tradução vem depois)
function escopo_aplicar($comando)
{
    $e = $GLOBALS["BANCO_ESCOPO"] ?? null;
    if ($e === null) {
        return $comando;
    }
    static $cache = [];
    $chave = $e["colecao"] . ":" . $e["versao"] . ":" . $comando;
    if (isset($cache[$chave])) {
        return $cache[$chave];
    }
    $p = sql_pedacos($comando);
    $n = count($p);
    $prox = function ($i) use ($p, $n) {
        for ($j = $i + 1; $j < $n; $j++) {
            if ($p[$j][0] !== "e") {
                return $j;
            }
        }
        return null;
    };
    $w = function ($i) use ($p) {
        return $i !== null && $p[$i][0] === "w" ? strtoupper($p[$i][1]) : null;
    };
    $primeira = $w($prox(-1));
    if (!in_array($primeira, ["SELECT", "WITH", "INSERT", "REPLACE", "UPDATE", "DELETE"], true)) {
        return $cache[$chave] = $comando;
    }
    $chaves = ["WHERE", "JOIN", "LEFT", "RIGHT", "INNER", "OUTER", "CROSS", "FULL", "ON", "GROUP", "ORDER", "LIMIT", "UNION", "SET", "USING",
        "HAVING", "VALUES", "SELECT", "OFFSET", "FETCH", "FOR", "RETURNING", "EXCEPT", "INTERSECT"];
    $troca = [];
    $alvo = null;
    $alvo_pos = null;
    if ($primeira === "UPDATE") {
        $alvo_pos = $prox($prox(-1));
    } elseif ($primeira === "DELETE") {
        $alvo_pos = $prox($prox($prox(-1)));
    }
    // (no INSERT e no REPLACE, a tabela de destino vem depois do INTO e fica como está: a gravação põe a coleção)
    if ($alvo_pos !== null) {
        $alvo = strtolower($p[$alvo_pos][1]);
    }
    $na_lista = false;
    for ($i = 0; $i < $n; $i++) {
        $kw = $w($i);
        $tabela_em = null;
        if ($kw === "FROM" || $kw === "JOIN") {
            $tabela_em = $prox($i);
            $na_lista = $kw === "FROM";
        } elseif ($na_lista && $p[$i][0] === "o" && $p[$i][1] === ",") {
            $tabela_em = $prox($i);
        } elseif ($kw !== null && in_array($kw, $chaves, true)) {
            $na_lista = false;
        } elseif ($p[$i][0] === "o" && ($p[$i][1] === "(" || $p[$i][1] === ")")) {
            $na_lista = false;
        }
        if ($tabela_em === null || !in_array($p[$tabela_em][0], ["w", "q"], true) || $tabela_em === $alvo_pos) {
            continue;
        }
        $t = strtolower($p[$tabela_em][1]);
        if (!isset(ESCOPO_CONDICOES[$t])) {
            continue;
        }
        $depois = $prox($tabela_em);
        $tem_apelido = $depois !== null && (($p[$depois][0] === "w" && !in_array(strtoupper($p[$depois][1]), $chaves, true)) || $p[$depois][0] === "q");
        $troca[$tabela_em] = escopo_visao($t, $e) . ($tem_apelido ? "" : " " . $t);
    }
    $saida = "";
    $fim_alvo = null;
    if ($alvo !== null && ($primeira === "UPDATE" || $primeira === "DELETE") && isset(ESCOPO_CONDICOES[$alvo])) {
        // o WHERE do nível de fora (e onde ele acaba: ORDER BY, LIMIT ou o fim)
        $nivel = 0;
        $onde = null;
        $acaba = $n;
        for ($i = 0; $i < $n; $i++) {
            if ($p[$i][0] === "o" && $p[$i][1] === "(") {
                $nivel++;
            } elseif ($p[$i][0] === "o" && $p[$i][1] === ")") {
                $nivel--;
            } elseif ($nivel === 0 && $w($i) === "WHERE" && $onde === null) {
                $onde = $i;
            } elseif ($nivel === 0 && in_array($w($i), ["ORDER", "LIMIT", "RETURNING"], true) && $i > (int)$onde) {
                $acaba = $i;
                break;
            }
        }
        $cond = escopo_condicao($alvo, $e) . ($alvo === "no" ? " AND id <> " . $e["raiz"] : "");
        $fim_alvo = [$onde, $acaba, $cond];
    }
    for ($i = 0; $i < $n; $i++) {
        if ($fim_alvo !== null && $i === $fim_alvo[1]) {
            $saida .= $fim_alvo[0] !== null ? ") AND " . $fim_alvo[2] . " " : " WHERE " . $fim_alvo[2] . " ";
        }
        if (isset($troca[$i])) {
            $saida .= $troca[$i];
        } else {
            $saida .= sql_texto([$p[$i]], "mysql");
            if ($p[$i][0] === "e") {
                $saida .= " ";
            }
        }
        if ($fim_alvo !== null && $fim_alvo[0] === $i) {
            $saida .= " (";
        }
    }
    if ($fim_alvo !== null && $fim_alvo[1] === $n) {
        $saida .= $fim_alvo[0] !== null ? ") AND " . $fim_alvo[2] : " WHERE " . $fim_alvo[2];
    }
    if (count($cache) > 4000) {
        $cache = [];
    }
    return $cache[$chave] = trim($saida);
}

// Executa um comando com parâmetros (?); devolve as linhas (SELECT) ou true
function sql($comando, $params = [])
{
    $r = banco_executar($comando, $params);
    return $r === null ? true : banco_linhas_assoc($r);
}

function linhas($comando, $params = [])
{
    $r = banco_executar($comando, $params);
    return $r === null ? [] : banco_linhas_assoc($r);
}

function linha($comando, $params = [])
{
    $l = linhas($comando, $params);
    return $l[0] ?? null;
}

function valor($comando, $params = [])
{
    $r = banco_executar($comando, $params);
    return $r === null || count($r[1]) === 0 ? null : $r[1][0][0];
}

// O id que o último INSERT gerou (a coluna id, AUTO_INCREMENT)
function ultimo_id()
{
    switch (banco_tipo()) {
        case "mysql":
            return (int)($GLOBALS["BANCO_MYSQL_ID"] ?? db()->insert_id);
        case "pgsql":
            return (int)valor("SELECT lastval()");
        case "firebird":
            // o id que o RETURNING id do último INSERT devolveu (banco_executar)
            return (int)banco_fb_ultimo_id();
        default:
            return (int)db()->lastInsertRowID();
    }
}

function banco_linhas_assoc($r)
{
    [$nomes, $linhas] = $r;
    $saida = [];
    foreach ($linhas as $l) {
        $a = [];
        foreach ($nomes as $i => $n) {
            $a[$n] = $l[$i];
        }
        $saida[] = $a;
    }
    return $saida;
}

// Executa e devolve [nomes das colunas, linhas (listas de valores)], ou null (o comando não devolve linhas)
function banco_executar($comando, $params = [])
{
    unset($GLOBALS["BANCO_MYSQL_ID"]);
    $r = banco_executar_cru(escopo_aplicar($comando), $params);
    // um grupo novo entra na árvore do escopo. No MySQL, a consulta dos grupos zera o insert_id: o id do INSERT fica
    // guardado para o ultimo_id(), até o próximo comando
    if (isset($GLOBALS["BANCO_ESCOPO"]) && preg_match("/^\\s*INSERT\\s+(IGNORE\\s+)?INTO\\s+no\\b/i", $comando) === 1) {
        $id = banco_tipo() === "mysql" ? (int)db()->insert_id : null;
        banco_escopo_nos();
        if ($id !== null) {
            $GLOBALS["BANCO_MYSQL_ID"] = $id;
        }
    }
    return $r;
}

// O fuso do pedido, quando é outro que não o do sistema (lib.php, fuso_definir): as datas com hora que vão para o banco
// (os parâmetros "AAAA-MM-DD HH:MM[:SS]") saem do fuso do pedido para o do sistema, e as que voltam (qualquer valor
// nesse formato) fazem o caminho inverso. Só as datas com hora: a data sozinha (o dia do plano, a da compra) é um dia do
// calendário, igual em todo fuso. No fuso do sistema (o comum), nada é tocado. null desliga
function banco_fuso($fuso = false)
{
    static $atual = null;
    if ($fuso !== false) {
        $atual = $fuso === null ? null : [new DateTimeZone($fuso), new DateTimeZone($GLOBALS["FUSO_SISTEMA"] ?? date_default_timezone_get())];
    }
    return $atual;
}

function banco_fuso_converter($valor, $de, $para)
{
    if (!is_string($valor) || strlen($valor) < 16 || strlen($valor) > 26 || preg_match("/^(\\d{4}-\\d{2}-\\d{2})[ T](\\d{2}:\\d{2})(:\\d{2})?(\\.\\d+)?\$/", $valor, $m) !== 1) {
        return $valor;
    }
    // os tetos e os pisos ("9999-12-31 23:59:59", o "desde sempre") ficam como estão: convertidos, saem do calendário
    $ano = (int)substr($m[1], 0, 4);
    if ($ano < 1900 || $ano > 9998) {
        return $valor;
    }
    $d = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $m[1] . " " . $m[2] . (isset($m[3]) && $m[3] !== "" ? $m[3] : ":00"), $de);
    return $d === false ? $valor : $d->setTimezone($para)->format("Y-m-d H:i:s") . ($m[4] ?? "");
}

// O comando no banco, sem o escopo (as datas com hora convertidas de e para o fuso do pedido, quando há)
function banco_executar_cru($comando, $params = [])
{
    $fuso = banco_fuso();
    if ($fuso === null) {
        return banco_executar_banco($comando, $params);
    }
    foreach ($params as $k => $p) {
        $params[$k] = banco_fuso_converter($p, $fuso[0], $fuso[1]);
    }
    $r = banco_executar_banco($comando, $params);
    if ($r !== null) {
        foreach ($r[1] as $i => $l) {
            foreach ($l as $j => $v) {
                if (is_string($v) && strlen($v) >= 16 && ($v[4] ?? "") === "-") {
                    $r[1][$i][$j] = banco_fuso_converter($v, $fuso[1], $fuso[0]);
                }
            }
        }
    }
    return $r;
}

function banco_executar_banco($comando, $params = [])
{
    $params = array_values($params);
    $traduzido = sql_traduzir($comando);
    if (count($traduzido) !== 1 || !is_string($traduzido[0])) {
        throw new BancoErro(t("o comando vira mais de um no {1}: use banco_script() para ele", [1 => banco_tipo()]));
    }
    $comando = $traduzido[0];
    switch (banco_tipo()) {
        case "mysql":
            try {
                $st = db()->prepare($comando);
                if (count($params) > 0) {
                    $tipos = "";
                    foreach ($params as $p) {
                        $tipos .= is_int($p) ? "i" : (is_float($p) ? "d" : "s");
                    }
                    $st->bind_param($tipos, ...$params);
                }
                $st->execute();
                $res = $st->get_result();
            } catch (mysqli_sql_exception $e) {
                throw banco_erro($e);
            }
            if ($res === false) {
                return null;
            }
            $nomes = array_map(function ($f) {
                return $f->name;
            }, $res->fetch_fields());
            return [$nomes, $res->fetch_all(MYSQLI_NUM)];

        case "pgsql":
            $c = db();
            $valores = array_map(function ($p) {
                if ($p === null) {
                    return null;
                }
                if (is_bool($p)) {
                    return $p ? "1" : "0";
                }
                if (is_string($p) && banco_binario($p)) {
                    return "\\x" . bin2hex($p);
                }
                return (string)$p;
            }, $params);
            if (!pg_send_query_params($c, $comando, $valores)) {
                throw new BancoErro(t("o PostgreSQL não recebeu o comando: {1}", [1 => pg_last_error($c)]));
            }
            $res = pg_get_result($c);
            while (pg_get_result($c) !== false) {
                // só um comando por vez: esvazia a fila
            }
            $estado = pg_result_status($res);
            if ($estado === PGSQL_FATAL_ERROR || $estado === PGSQL_BAD_RESPONSE || $estado === PGSQL_NONFATAL_ERROR) {
                $estado_sql = (string)pg_result_error_field($res, PGSQL_DIAG_SQLSTATE);
                throw new BancoErro(trim((string)pg_result_error($res)), (int)$estado_sql, null, strpos($estado_sql, "23") === 0);
            }
            if ($estado !== PGSQL_TUPLES_OK) {
                return null;
            }
            $n = pg_num_fields($res);
            $nomes = [];
            $conv = [];
            for ($i = 0; $i < $n; $i++) {
                $nomes[] = pg_field_name($res, $i);
                $conv[] = pg_field_type($res, $i);
            }
            $linhas = [];
            while (($l = pg_fetch_row($res)) !== false) {
                foreach ($l as $i => $v) {
                    if ($v === null) {
                        continue;
                    }
                    switch ($conv[$i]) {
                        case "int2":
                        case "int4":
                        case "int8":
                        case "oid":
                            $l[$i] = (int)$v;
                            break;
                        case "float4":
                        case "float8":
                            $l[$i] = (float)$v;
                            break;
                        case "bool":
                            $l[$i] = $v === "t" ? 1 : 0;
                            break;
                        case "bytea":
                            $l[$i] = pg_unescape_bytea($v);
                            break;
                    }
                }
                $linhas[] = $l;
            }
            pg_free_result($res);
            return [$nomes, $linhas];

        case "firebird":
            return banco_fb_executar($comando, $params);

        default:
            try {
                $st = db()->prepare($comando);
                foreach ($params as $i => $p) {
                    if ($p === null) {
                        $st->bindValue($i + 1, null, SQLITE3_NULL);
                    } elseif (is_int($p) || is_bool($p)) {
                        $st->bindValue($i + 1, (int)$p, SQLITE3_INTEGER);
                    } elseif (is_float($p)) {
                        $st->bindValue($i + 1, $p, SQLITE3_FLOAT);
                    } elseif (banco_binario((string)$p)) {
                        $st->bindValue($i + 1, (string)$p, SQLITE3_BLOB);
                    } else {
                        $st->bindValue($i + 1, banco_sqlite_data((string)$p), SQLITE3_TEXT);
                    }
                }
                $res = $st->execute();
                $n = $res->numColumns();
                if ($n === 0) {
                    $res->finalize();
                    $st->close();
                    return null;
                }
                $nomes = [];
                for ($i = 0; $i < $n; $i++) {
                    $nomes[] = $res->columnName($i);
                }
                $linhas = [];
                while (($l = $res->fetchArray(SQLITE3_NUM)) !== false) {
                    $linhas[] = $l;
                }
                $res->finalize();
                $st->close();
            } catch (Exception $e) {
                throw banco_erro($e);
            }
            return [$nomes, banco_sqlite_valores($nomes, $linhas)];
    }
}

// Um texto que não é UTF-8 válido (ou tem o byte zero) é binário: a foto
function banco_binario($s)
{
    return strpos($s, "\0") !== false || preg_match("//u", $s) !== 1;
}

// No SQLite a data e hora é texto: "2026-09-27 14:05" e "2026-09-27T14:05" viram "2026-09-27 14:05:00", como o MySQL
// guarda, para as comparações e a ordem das datas saírem certas
function banco_sqlite_data($s)
{
    if (strlen($s) >= 16 && strlen($s) <= 19 && preg_match("/^(\\d{4}-\\d{2}-\\d{2})[ T](\\d{2}:\\d{2})(:\\d{2})?\$/", $s, $m) === 1) {
        return $m[1] . " " . $m[2] . ($m[3] ?? "") . (isset($m[3]) && $m[3] !== "" ? "" : ":00");
    }
    return $s;
}

// No SQLite o valor volta como foi guardado; aqui ele fica como o MySQL devolve: o decimal com as casas da coluna
// ("68" numa DECIMAL(12,2) volta "68.00") e a hora com os segundos ("22:00" numa TIME volta "22:00:00"). A coluna é
// reconhecida pelo nome (o SQLite3 do PHP não diz de que coluna da tabela veio o valor): vale quando todas as colunas com
// esse nome, em todas as tabelas, têm o mesmo tipo, ou quando só uma delas guarda número (valor: DECIMAL no lançamento,
// texto no campo e na configuração)
function banco_sqlite_valores($nomes, $linhas)
{
    $tipos = banco_sqlite_tipos();
    $regra = [];
    foreach ($nomes as $i => $n) {
        if (isset($tipos[$n])) {
            $regra[$i] = $tipos[$n];
        }
    }
    if (count($regra) === 0) {
        return $linhas;
    }
    foreach ($linhas as &$l) {
        foreach ($regra as $i => $r) {
            $v = $l[$i];
            if ($v === null) {
                continue;
            }
            if (isset($r["casas"]) && (is_int($v) || is_float($v))) {
                $l[$i] = number_format((float)$v, $r["casas"], ".", "");
            } elseif (isset($r["hora"]) && is_string($v) && preg_match("/^\\d{2}:\\d{2}\$/", $v) === 1) {
                $l[$i] = $v . ":00";
            }
        }
    }
    unset($l);
    return $linhas;
}

// Os tipos declarados das colunas, pelo nome: {"valor": {"casas": 2}, "fecha_as": {"hora": 1}}. Muda com o DDL
function banco_sqlite_tipos($limpar = false)
{
    static $cache = null;
    if ($limpar) {
        $cache = null;
        return [];
    }
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $por_nome = [];
    $res = db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
    $tabelas = [];
    while (($t = $res->fetchArray(SQLITE3_NUM)) !== false) {
        $tabelas[] = $t[0];
    }
    $res->finalize();
    foreach ($tabelas as $t) {
        $res = db()->query("PRAGMA table_info(\"" . str_replace("\"", "\"\"", $t) . "\")");
        while (($c = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
            $por_nome[$c["name"]][] = strtoupper((string)$c["type"]);
        }
        $res->finalize();
    }
    foreach ($por_nome as $nome => $lista) {
        $decimais = [];
        $outros_numeros = false;
        $horas = 0;
        foreach ($lista as $tipo) {
            if (preg_match("/^(DECIMAL|NUMERIC)\\s*\\(\\s*\\d+\\s*,\\s*(\\d+)\\s*\\)/", $tipo, $m) === 1) {
                $decimais[(int)$m[2]] = true;
            } elseif (preg_match("/INT|REAL|FLOA|DOUB|DATE|TIME|NUMERIC|DECIMAL/", $tipo) === 1) {
                $outros_numeros = $outros_numeros || $tipo !== "TIME";
            }
            if ($tipo === "TIME") {
                $horas++;
            }
        }
        if (count($decimais) === 1 && !$outros_numeros) {
            $cache[$nome] = ["casas" => array_key_first($decimais)];
        } elseif ($horas === count($lista)) {
            $cache[$nome] = ["hora" => 1];
        }
    }
    return $cache;
}

// Firebird: executa com os parâmetros. Antes: a hora de agora no lugar do NOW() (a do PHP, no fuso do sistema: o Firebird
// não tem fuso por conexão), as datas como o MySQL guarda, e o RETURNING id no INSERT de uma linha numa tabela com id
// automático (o ultimo_id()). O INSERT IGNORE (marcado /*IGNORE*/ na tradução) não para numa chave repetida
function banco_fb_executar($comando, $params)
{
    $comando = banco_fb_agora($comando);
    $ignorar = strncmp($comando, "/*IGNORE*/", 10) === 0;
    $retorna = false;
    if (preg_match("/^(?:\\/\\*IGNORE\\*\\/ )?INSERT INTO (\\w+|\"\\w+\") ?\\([^)]*\\) ?VALUES ?\\(/i", $comando, $m) === 1
        && stripos($comando, " RETURNING ") === false && in_array(strtolower(trim($m[1], "\"")), banco_fb_identidades(), true)) {
        $comando = rtrim($comando, " ;") . " RETURNING id";
        $retorna = true;
    }
    try {
        $st = db()->prepare($comando);
        foreach ($params as $i => $p) {
            if ($p === null) {
                $st->bindValue($i + 1, null, PDO::PARAM_NULL);
            } elseif (is_int($p) || is_bool($p)) {
                // como texto, como o número com casas: o pdo_firebird grava um inteiro numa coluna DECIMAL(6,2) sem a escala
                // (100 vira 1,00); o banco converte o texto para o tipo da coluna
                $st->bindValue($i + 1, (string)(int)$p, PDO::PARAM_STR);
            } elseif (is_float($p)) {
                $st->bindValue($i + 1, (string)$p, PDO::PARAM_STR);
            } elseif (banco_binario((string)$p)) {
                $st->bindValue($i + 1, (string)$p, PDO::PARAM_LOB);
            } else {
                $st->bindValue($i + 1, banco_sqlite_data((string)$p), PDO::PARAM_STR);
            }
        }
        $st->execute();
    } catch (PDOException $e) {
        $erro = banco_erro($e);
        if ($ignorar && $erro->integridade && in_array($erro->getCode(), [-803], true)) {
            return null;
        }
        throw $erro;
    }
    if ($retorna) {
        $l = $st->fetch(PDO::FETCH_NUM);
        $st->closeCursor();
        banco_fb_ultimo_id($l !== false ? (int)$l[0] : 0);
        return null;
    }
    $n = $st->columnCount();
    if ($n === 0) {
        return null;
    }
    $nomes = [];
    for ($i = 0; $i < $n; $i++) {
        $nomes[] = strtolower((string)$st->getColumnMeta($i)["name"]);
    }
    $linhas = $st->fetchAll(PDO::FETCH_NUM);
    $st->closeCursor();
    return [$nomes, $linhas];
}

// O id do último INSERT no Firebird (guardado pelo banco_fb_executar)
function banco_fb_ultimo_id($novo = null)
{
    static $id = 0;
    if ($novo !== null) {
        $id = $novo;
    }
    return $id;
}

// O NOW() traduzido para o Firebird é CAST('@@AGORA@@' AS TIMESTAMP): aqui a marca vira a hora do PHP, na hora de rodar
// (a tradução fica guardada e a hora muda)
function banco_fb_agora($comando)
{
    if (strpos($comando, "'@@AGORA@@'") === false) {
        return $comando;
    }
    $agora = (new DateTimeImmutable("now"))->setTimezone(new DateTimeZone($GLOBALS["FUSO_SISTEMA"] ?? date_default_timezone_get()))->format("Y-m-d H:i:s");
    return str_replace("'@@AGORA@@'", "'" . $agora . "'", $comando);
}

// As tabelas do Firebird com a coluna id automática (IDENTITY), em minúsculas. Muda com o DDL (limpar)
function banco_fb_identidades($limpar = false)
{
    static $cache = null;
    if ($limpar) {
        $cache = null;
        return [];
    }
    if ($cache === null) {
        $cache = [];
        foreach (db()->query("SELECT TRIM(RDB\$RELATION_NAME) FROM RDB\$RELATION_FIELDS WHERE RDB\$FIELD_NAME = 'ID' AND RDB\$IDENTITY_TYPE IS NOT NULL")->fetchAll(PDO::FETCH_NUM) as $l) {
            $cache[] = strtolower($l[0]);
        }
    }
    return $cache;
}

// Um nome do Firebird: até 31 letras. O nome maior (de um índice, de uma regra) vira o começo dele, "_" e 8 letras do
// md5 do nome inteiro (o mesmo nome dá sempre o mesmo encurtado)
function banco_fb_nome($nome)
{
    return strlen($nome) <= 31 ? $nome : substr($nome, 0, 22) . "_" . substr(md5($nome), 0, 8);
}

// O nome de uma tabela no SQL do banco em uso: no Firebird, "no" é palavra reservada e vai entre aspas, em maiúsculas
function banco_tabela_sql($tabela)
{
    return banco_tipo() === "firebird" && strtolower($tabela) === "no" ? "\"NO\"" : $tabela;
}

// Um comando sem parâmetros, direto (o DDL, a sessão): sem prepare
function banco_direto($comando, $c = null)
{
    $c = $c ?? db();
    try {
        switch (banco_tipo()) {
            case "mysql":
                $c->query($comando);
                break;
            case "pgsql":
                if (!pg_send_query($c, $comando)) {
                    throw new BancoErro(t("o PostgreSQL não recebeu o comando: {1}", [1 => pg_last_error($c)]));
                }
                while (($res = pg_get_result($c)) !== false) {
                    $estado = pg_result_status($res);
                    if ($estado === PGSQL_FATAL_ERROR || $estado === PGSQL_BAD_RESPONSE) {
                        $erro = trim((string)pg_result_error($res));
                        $estado_sql = (string)pg_result_error_field($res, PGSQL_DIAG_SQLSTATE);
                        while (pg_get_result($c) !== false) {
                        }
                        throw new BancoErro($erro, (int)$estado_sql, null, strpos($estado_sql, "23") === 0);
                    }
                }
                break;
            case "firebird":
                $comando = banco_fb_agora($comando);
                try {
                    // (prepare e execute, e não o exec: no pdo_firebird, o exec perde a mensagem do erro)
                    $c->prepare($comando)->execute();
                    if (preg_match("/^\\s*(CREATE|ALTER|DROP|RECREATE)\\s/i", $comando) === 1) {
                        banco_fb_transacao("firmar");
                    }
                } catch (PDOException $e) {
                    $erro = banco_erro($e);
                    if (strncmp($comando, "/*IGNORE*/", 10) === 0 && $erro->getCode() === -803) {
                        break;
                    }
                    throw $erro;
                }
                break;
            default:
                $c->exec($comando);
        }
    } catch (mysqli_sql_exception $e) {
        throw banco_erro($e);
    } catch (BancoErro $e) {
        throw $e;
    } catch (Exception $e) {
        throw banco_erro($e);
    }
}

// A transação. No Firebird, pelo PDO (fora dela, cada comando se confirma sozinho)
function banco_inicio()
{
    if (banco_tipo() === "firebird") {
        banco_fb_transacao("inicio");
        return;
    }
    banco_direto(banco_tipo() === "mysql" ? "START TRANSACTION" : "BEGIN");
}

function banco_confirma()
{
    if (banco_tipo() === "firebird") {
        banco_fb_transacao("confirma");
        return;
    }
    banco_direto("COMMIT");
}

function banco_desfaz()
{
    if (banco_tipo() === "firebird") {
        banco_fb_transacao("desfaz");
        return;
    }
    banco_direto("ROLLBACK");
}

// Firebird: a transação pelo PDO. O pdo_firebird antigo (o do PHP 8.3) deixa a transação do autocommit sempre aberta e
// recusa o beginTransaction ("There is already an active transaction"): aí o autocommit desliga (o que estava aberto se
// confirma), a transação abre, e no fim o autocommit volta
function banco_fb_transacao($acao)
{
    static $aberta = false;
    static $religar = false;
    $c = db();
    if ($acao === "aberta") {
        return $aberta;
    }
    try {
        if ($acao === "firmar") {
            // depois de uma mudança de estrutura, fora de uma transação: a confirmação de verdade (no pdo_firebird antigo,
            // o autocommit só confirma "retendo" a transação, e a tabela mudada fica inacessível até ela fechar)
            if (!$aberta) {
                $c->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
                $c->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
            }
            return;
        }
        if ($acao === "inicio") {
            try {
                $c->beginTransaction();
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), "active transaction") === false) {
                    throw $e;
                }
                $c->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
                $religar = true;
                $c->beginTransaction();
            }
            $aberta = true;
            return;
        }
        if (!$aberta) {
            return;
        }
        $aberta = false;
        try {
            if ($acao === "confirma") {
                $c->commit();
            } else {
                $c->rollBack();
            }
        } finally {
            if ($religar) {
                $religar = false;
                $c->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
            }
        }
    } catch (PDOException $e) {
        throw banco_erro($e);
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// A estrutura: o que existe, os scripts (a instalação, as migrações) e os números dos ids
// ---------------------------------------------------------------------------------------------------------------------

function banco_tem_tabela($tabela)
{
    switch (banco_tipo()) {
        case "mysql":
            return (int)valor("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$tabela]) > 0;
        case "pgsql":
            return (int)valor("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?", [$tabela]) > 0;
        case "firebird":
            return (int)valor("SELECT COUNT(*) FROM RDB\$RELATIONS WHERE RDB\$RELATION_NAME = ? AND COALESCE(RDB\$SYSTEM_FLAG, 0) = 0", [strtoupper($tabela)]) > 0;
        default:
            return (int)valor("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$tabela]) > 0;
    }
}

function banco_tem_coluna($tabela, $coluna)
{
    switch (banco_tipo()) {
        case "mysql":
            return (int)valor("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$tabela, $coluna]) > 0;
        case "pgsql":
            return (int)valor("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?", [$tabela, $coluna]) > 0;
        case "firebird":
            return (int)valor("SELECT COUNT(*) FROM RDB\$RELATION_FIELDS WHERE RDB\$RELATION_NAME = ? AND RDB\$FIELD_NAME = ?", [strtoupper($tabela), strtoupper($coluna)]) > 0;
        default:
            return banco_tem_tabela($tabela) && (int)valor("SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?", [$tabela, $coluna]) > 0;
    }
}

function banco_tem_indice($tabela, $indice)
{
    switch (banco_tipo()) {
        case "mysql":
            return (int)valor("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?", [$tabela, $indice]) > 0;
        case "pgsql":
            return (int)valor("SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?", [$tabela, $indice]) > 0;
        case "firebird":
            return (int)valor("SELECT COUNT(*) FROM RDB\$INDICES WHERE RDB\$RELATION_NAME = ? AND RDB\$INDEX_NAME = ?", [strtoupper($tabela), strtoupper(banco_fb_nome($indice))]) > 0;
        default:
            return (int)valor("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?", [$tabela, $indice]) > 0;
    }
}

function banco_tabelas()
{
    switch (banco_tipo()) {
        case "mysql":
            return array_column(linhas("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()"), "t");
        case "pgsql":
            return array_column(linhas("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = current_schema()"), "t");
        case "firebird":
            return array_map("strtolower", array_column(linhas("SELECT TRIM(RDB\$RELATION_NAME) AS t FROM RDB\$RELATIONS WHERE COALESCE(RDB\$SYSTEM_FLAG, 0) = 0
                AND RDB\$VIEW_BLR IS NULL ORDER BY 1"), "t"));
        default:
            return array_column(linhas("SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), "t");
    }
}

// MySQL/MariaDB: as tabelas que têm alguma coluna de texto numa collation que não ordena pelo Unicode (a utf8mb4_general_ci,
// padrão do MariaDB, ou uma binária). As que valem: utf8mb4_unicode_ci (a que o sistema cria), utf8mb4_unicode_520_ci,
// utf8mb4_0900_ai_ci (o padrão do MySQL 8) e utf8mb4_uca1400_ai_ci (a do MariaDB novo). Nos outros bancos: nenhuma
function banco_tabelas_fora_da_collation()
{
    if (banco_tipo() !== "mysql") {
        return [];
    }
    return array_column(linhas("SELECT DISTINCT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND collation_name IS NOT NULL
        AND collation_name NOT IN ('utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_ai_ci') ORDER BY t"), "t");
}

// Os comandos de um arquivo SQL (o schema.sql, uma migração), um a um, cada um traduzido para o banco. Os comentários
// saem; os comandos se separam pelo ";" fora dos textos. Depois, no Postgres, os contadores dos ids andam até o maior id
// gravado (o arquivo grava ids explícitos). Devolve quantos comandos rodou.
function banco_script($texto)
{
    $n = 0;
    foreach (sql_comandos($texto) as $comando) {
        // as mudanças de estrutura que cada banco faz do seu jeito: trocar a regra de uma chave estrangeira e tirar uma coluna
        if (banco_estrutura($comando)) {
            $n++;
            continue;
        }
        foreach (sql_traduzir($comando) as $parte) {
            if (is_array($parte) && $parte[0] === "firebird_modificar") {
                firebird_modificar_coluna($parte[1], $parte[2]);
            } elseif (is_array($parte) && $parte[0] === "firebird_se_nao_existe") {
                if (!banco_tem_tabela($parte[1])) {
                    foreach ($parte[2] as $x) {
                        banco_direto($x);
                    }
                }
            } elseif (is_array($parte)) {
                sqlite_modificar_coluna($parte[1], $parte[2]);
            } else {
                banco_direto($parte);
            }
        }
        if (banco_tipo() === "firebird" && preg_match("/^\\s*(CREATE|ALTER|DROP)\\s/i", $comando) === 1) {
            banco_fb_limpar();
        }
        // o id gravado explícito: no MySQL o próximo id passa dele sozinho; no Postgres e no Firebird, logo em seguida, aqui
        if (in_array(banco_tipo(), ["pgsql", "firebird"], true) && preg_match("/^\\s*INSERT\\s+(IGNORE\\s+)?INTO\\s+`?(\\w+)`?\\s*\\(\\s*`?id`?\\s*[,)]/i", $comando, $m) === 1) {
            banco_ajustar_id($m[2]);
        }
        $n++;
    }
    if (banco_tipo() === "sqlite") {
        banco_sqlite_tipos(true);
    }
    if (banco_tipo() === "firebird") {
        banco_fb_limpar();
    }
    banco_ajustar_ids();
    return $n;
}

// Firebird: esquece o que guardou da estrutura (depois do DDL)
function banco_fb_limpar()
{
    banco_fb_identidades(true);
    banco_chave_primaria(null);
    banco_colunas(null);
}

// Postgres e Firebird: o próximo id de cada tabela depois do maior gravado (depois de gravar ids explícitos). Nos outros é
// sozinho.
function banco_ajustar_ids()
{
    if (banco_tipo() === "firebird") {
        foreach (banco_fb_identidades() as $t) {
            banco_ajustar_id($t);
        }
        return;
    }
    if (banco_tipo() !== "pgsql") {
        return;
    }
    foreach (linhas("SELECT table_name AS t FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'id' AND is_identity = 'YES'") as $t) {
        banco_ajustar_id($t["t"]);
    }
}

function banco_ajustar_id($tabela)
{
    if (banco_tipo() === "firebird") {
        // o contador da coluna IDENTITY: o próximo id é o valor dele mais 1 (RESTART WITH n faz o próximo ser n + 1)
        $gerador = trim((string)valor("SELECT RDB\$GENERATOR_NAME FROM RDB\$RELATION_FIELDS WHERE RDB\$RELATION_NAME = ? AND RDB\$FIELD_NAME = 'ID'", [strtoupper($tabela)]));
        if ($gerador === "") {
            return;
        }
        $atual = (int)valor("SELECT GEN_ID(\"" . str_replace("\"", "\"\"", $gerador) . "\", 0) FROM RDB\$DATABASE");
        $maior = (int)valor("SELECT COALESCE(MAX(id), 0) FROM " . banco_tabela_sql($tabela));
        if ($maior > $atual) {
            banco_direto("ALTER TABLE " . banco_tabela_sql($tabela) . " ALTER COLUMN id RESTART WITH " . $maior);
        }
        return;
    }
    if (banco_tipo() !== "pgsql") {
        return;
    }
    // o próximo id passa do maior gravado e nunca volta atrás (como no MySQL: um id apagado não é usado de novo)
    $t = "\"" . str_replace("\"", "\"\"", $tabela) . "\"";
    $seq = (string)valor("SELECT pg_get_serial_sequence(?, 'id')", [$t]);
    if ($seq === "") {
        return;
    }
    // o próximo que o contador daria: depois de um setval(..., false) ele ainda não foi usado e dá o próprio last_value
    $prox = (int)valor("SELECT CASE WHEN is_called THEN last_value + 1 ELSE last_value END FROM " . $seq);
    $maior = (int)valor("SELECT COALESCE(MAX(id), 0) FROM " . $t);
    banco_direto("SELECT setval('" . str_replace("'", "''", $seq) . "', " . max($prox, $maior + 1) . ", false)");
}

// As duas mudanças de estrutura que o dialeto do MySQL não escreve igual nos três bancos, como comandos das migrações:
//   ALTER TABLE t ALTER FOREIGN KEY (coluna) REFERENCES outra(id) ON DELETE CASCADE | SET NULL
//     a chave estrangeira da coluna passa a ser esta (a que havia sai; sem nenhuma, esta entra). No MySQL e no Postgres o
//     nome da restrição antiga vem do catálogo do banco (cada instalação gerou o seu); no SQLite a tabela é refeita.
//   ALTER TABLE t DROP COLUMN coluna
//     a coluna sai, com a chave estrangeira e os índices dela (no MySQL a chave sai antes; no SQLite a tabela é refeita).
//   ALTER TABLE t ADD CONSTRAINT nome CHECK (condição)
//     a regra de validação (CHECK do SQL padrão) entra com este nome; se já havia uma com ele, é trocada (a migração pode
//     rodar de novo). As linhas que já existem têm de cumprir a regra, senão o banco recusa. No SQLite a tabela é refeita.
//     No MySQL 8, a condição não pode usar a coluna id (AUTO_INCREMENT) nem uma coluna de chave estrangeira com ON DELETE
//     SET NULL (o MySQL recusa as duas).
// E, para uma migração que parou no meio poder rodar de novo: o ADD COLUMN de uma coluna que já existe e o ADD INDEX com
// nome de um índice que já existe não fazem nada.
// Devolve verdadeiro se o comando era um destes (e já rodou, ou não precisava)
function banco_estrutura($comando)
{
    $c = trim((string)preg_replace("/\\s+/", " ", $comando));
    if (preg_match("/^ALTER TABLE `?(\\w+)`? ADD (COLUMN )?`?(\\w+)`? /i", $c, $m) === 1
        && preg_match("/^(INDEX|KEY|UNIQUE|CONSTRAINT|FOREIGN|PRIMARY|CHECK)\$/i", $m[3]) !== 1 && banco_tem_coluna($m[1], $m[3])) {
        return true;
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? ADD (UNIQUE )?(INDEX|KEY) `?(\\w+)`? ?\\(/i", $c, $m) === 1 && banco_tem_indice($m[1], $m[4])) {
        return true;
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? ALTER FOREIGN KEY \\(`?(\\w+)`?\\) REFERENCES `?(\\w+)`? ?\\(`?(\\w+)`?\\) ON DELETE (CASCADE|SET NULL)\$/i", $c, $m) === 1) {
        banco_chave_estrangeira($m[1], $m[2], $m[3], $m[4], strtoupper($m[5]));
        return true;
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? DROP COLUMN `?(\\w+)`?\$/i", $c, $m) === 1) {
        banco_remover_coluna($m[1], $m[2]);
        return true;
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? ADD CONSTRAINT `?(\\w+)`? CHECK ?\\((.*)\\)\$/i", $c, $m) === 1) {
        banco_regra($m[1], $m[2], $m[3]);
        return true;
    }
    return false;
}

// A regra de validação (CHECK) de uma tabela, com nome: a que já havia com o mesmo nome sai antes
function banco_regra($tabela, $nome, $condicao)
{
    $banco = banco_tipo();
    if ($banco !== "mysql") {
        // a condição no dialeto do banco (o LIKE nas colunas "ci" do Postgres, o <=>)
        $condicao = sql_texto(sql_dml(sql_pedacos($condicao), $banco), $banco);
    }
    if ($banco === "firebird") {
        $nome = banco_fb_nome($nome);
    }
    $regra = "CONSTRAINT " . $nome . " CHECK (" . $condicao . ")";
    if ($banco === "firebird") {
        // a de antes sai e a nova entra numa transação só: se as linhas não cumprem a regra nova, a de antes fica
        $t = banco_tabela_sql($tabela);
        $existe = (int)valor("SELECT COUNT(*) FROM RDB\$RELATION_CONSTRAINTS WHERE RDB\$RELATION_NAME = ? AND RDB\$CONSTRAINT_NAME = ?", [strtoupper($tabela), strtoupper($nome)]) > 0;
        banco_inicio();
        try {
            if ($existe) {
                banco_direto("ALTER TABLE " . $t . " DROP CONSTRAINT " . $nome);
            }
            banco_direto("ALTER TABLE " . $t . " ADD " . $regra);
            banco_confirma();
        } catch (Throwable $e) {
            banco_desfaz();
            throw $e;
        }
        return;
    }
    if ($banco === "sqlite") {
        sqlite_refazer($tabela, function ($partes) use ($nome, $regra) {
            $fica = [];
            foreach ($partes as $p) {
                if (preg_match("/^CONSTRAINT\\s+[\"`]?" . preg_quote($nome, "/") . "[\"`]?\\s/i", trim($p)) !== 1) {
                    $fica[] = $p;
                }
            }
            $fica[] = $regra;
            return $fica;
        });
        return;
    }
    if ($banco === "pgsql") {
        // no Postgres a estrutura também tem transação: se as linhas não cumprem a regra nova, a de antes fica
        banco_direto("BEGIN");
        try {
            banco_direto("ALTER TABLE " . $tabela . " DROP CONSTRAINT IF EXISTS " . $nome);
            banco_direto("ALTER TABLE " . $tabela . " ADD " . $regra);
            banco_direto("COMMIT");
        } catch (Throwable $e) {
            banco_direto("ROLLBACK");
            throw $e;
        }
        return;
    }
    // MySQL: sem transação na estrutura. Trocando uma regra, a nova entra antes com um nome provisório (se as linhas não a
    // cumprem, para aí e a de antes fica); só então a de antes sai e a nova ganha o nome certo
    $existe = function ($n) use ($tabela) {
        return (int)valor("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
            AND CONSTRAINT_TYPE = 'CHECK'", [$tabela, $n]) > 0;
    };
    if ($existe($nome)) {
        $provisorio = substr($nome, 0, 56) . "_trocar";
        if ($existe($provisorio)) {
            banco_direto("ALTER TABLE `" . $tabela . "` DROP CONSTRAINT `" . $provisorio . "`");
        }
        banco_direto("ALTER TABLE `" . $tabela . "` ADD CONSTRAINT `" . $provisorio . "` CHECK (" . $condicao . ")");
        banco_direto("ALTER TABLE `" . $tabela . "` DROP CONSTRAINT `" . $nome . "`");
        banco_direto("ALTER TABLE `" . $tabela . "` ADD " . $regra);
        banco_direto("ALTER TABLE `" . $tabela . "` DROP CONSTRAINT `" . $provisorio . "`");
        return;
    }
    banco_direto("ALTER TABLE `" . $tabela . "` ADD " . $regra);
}

// Os nomes das chaves estrangeiras de uma coluna (MySQL, Postgres e Firebird)
function banco_nomes_chaves($tabela, $coluna)
{
    if (banco_tipo() === "firebird") {
        return array_column(linhas("SELECT TRIM(c.RDB\$CONSTRAINT_NAME) AS n FROM RDB\$RELATION_CONSTRAINTS c JOIN RDB\$INDEX_SEGMENTS s ON s.RDB\$INDEX_NAME = c.RDB\$INDEX_NAME
            WHERE c.RDB\$CONSTRAINT_TYPE = 'FOREIGN KEY' AND c.RDB\$RELATION_NAME = ? AND s.RDB\$FIELD_NAME = ?", [strtoupper($tabela), strtoupper($coluna)]), "n");
    }
    if (banco_tipo() === "mysql") {
        return array_column(linhas("SELECT CONSTRAINT_NAME AS n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$tabela, $coluna]), "n");
    }
    return array_column(linhas("SELECT c.conname AS n FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_attribute a ON a.attrelid = t.oid
        AND a.attnum = ANY (c.conkey) WHERE c.contype = 'f' AND t.relname = ? AND a.attname = ? AND t.relnamespace = current_schema()::regnamespace", [$tabela, $coluna]), "n");
}

function banco_chave_estrangeira($tabela, $coluna, $ref, $ref_coluna, $acao)
{
    $nova = "FOREIGN KEY (" . $coluna . ") REFERENCES " . $ref . "(" . $ref_coluna . ") ON DELETE " . $acao;
    if (banco_tipo() === "sqlite") {
        sqlite_refazer($tabela, function ($partes) use ($coluna, $nova) {
            $fica = [];
            foreach ($partes as $p) {
                if (preg_match("/^FOREIGN KEY\\s*\\(\\s*[\"`]?" . preg_quote($coluna, "/") . "[\"`]?\\s*\\)/i", trim($p)) !== 1) {
                    $fica[] = $p;
                }
            }
            $fica[] = $nova;
            return $fica;
        });
        return;
    }
    $q = banco_tipo() === "mysql" ? "`" : "\"";
    $nome_novo = $tabela . "_" . $coluna . "_fk";
    if (banco_tipo() === "firebird") {
        // a chave nova numa transação só com a saída da antiga
        $nova = "FOREIGN KEY (" . $coluna . ") REFERENCES " . banco_tabela_sql($ref) . "(" . $ref_coluna . ") ON DELETE " . $acao;
        $nome_novo = banco_fb_nome($nome_novo);
        banco_inicio();
        try {
            foreach (banco_nomes_chaves($tabela, $coluna) as $nome) {
                banco_direto("ALTER TABLE " . banco_tabela_sql($tabela) . " DROP CONSTRAINT " . $q . $nome . $q);
            }
            banco_direto("ALTER TABLE " . banco_tabela_sql($tabela) . " ADD CONSTRAINT " . $nome_novo . " " . $nova);
            banco_confirma();
        } catch (Throwable $e) {
            banco_desfaz();
            throw $e;
        }
        return;
    }
    foreach (banco_nomes_chaves($tabela, $coluna) as $nome) {
        banco_direto("ALTER TABLE " . $tabela . (banco_tipo() === "mysql" ? " DROP FOREIGN KEY " : " DROP CONSTRAINT ") . $q . $nome . $q);
    }
    banco_direto("ALTER TABLE " . $tabela . " ADD CONSTRAINT " . $nome_novo . " " . $nova);
}

function banco_remover_coluna($tabela, $coluna)
{
    if (banco_tipo() === "sqlite") {
        sqlite_refazer($tabela, function ($partes) use ($coluna) {
            $fica = [];
            foreach ($partes as $p) {
                $primeira = strtolower(trim((string)preg_split("/\\s+/", trim($p))[0], "\"`"));
                if ($primeira !== strtolower($coluna) && preg_match("/^FOREIGN KEY\\s*\\(\\s*[\"`]?" . preg_quote($coluna, "/") . "[\"`]?\\s*\\)/i", trim($p)) !== 1) {
                    $fica[] = $p;
                }
            }
            return $fica;
        }, $coluna);
        return;
    }
    if (banco_tipo() === "mysql") {
        foreach (banco_nomes_chaves($tabela, $coluna) as $nome) {
            banco_direto("ALTER TABLE `" . $tabela . "` DROP FOREIGN KEY `" . $nome . "`");
        }
    }
    if (banco_tipo() === "firebird") {
        // no Firebird a coluna só sai depois das chaves estrangeiras, dos índices e das regras que a usam
        $t = banco_tabela_sql($tabela);
        foreach (banco_nomes_chaves($tabela, $coluna) as $nome) {
            banco_direto("ALTER TABLE " . $t . " DROP CONSTRAINT \"" . $nome . "\"");
        }
        foreach (linhas("SELECT DISTINCT TRIM(i.RDB\$INDEX_NAME) AS n FROM RDB\$INDICES i JOIN RDB\$INDEX_SEGMENTS s ON s.RDB\$INDEX_NAME = i.RDB\$INDEX_NAME
            LEFT JOIN RDB\$RELATION_CONSTRAINTS c ON c.RDB\$INDEX_NAME = i.RDB\$INDEX_NAME
            WHERE i.RDB\$RELATION_NAME = ? AND s.RDB\$FIELD_NAME = ? AND c.RDB\$CONSTRAINT_NAME IS NULL", [strtoupper($tabela), strtoupper($coluna)]) as $ix) {
            banco_direto("DROP INDEX \"" . $ix["n"] . "\"");
        }
        foreach (linhas("SELECT DISTINCT TRIM(c.RDB\$CONSTRAINT_NAME) AS n FROM RDB\$RELATION_CONSTRAINTS c JOIN RDB\$CHECK_CONSTRAINTS k ON k.RDB\$CONSTRAINT_NAME = c.RDB\$CONSTRAINT_NAME
            JOIN RDB\$DEPENDENCIES d ON d.RDB\$DEPENDENT_NAME = k.RDB\$TRIGGER_NAME
            WHERE c.RDB\$CONSTRAINT_TYPE = 'CHECK' AND c.RDB\$RELATION_NAME = ? AND d.RDB\$DEPENDED_ON_NAME = ? AND d.RDB\$FIELD_NAME = ?",
            [strtoupper($tabela), strtoupper($tabela), strtoupper($coluna)]) as $k) {
            banco_direto("ALTER TABLE " . $t . " DROP CONSTRAINT \"" . $k["n"] . "\"");
        }
        banco_direto("ALTER TABLE " . $t . " DROP " . $coluna);
        return;
    }
    banco_direto("ALTER TABLE " . $tabela . " DROP COLUMN " . $coluna);
}

// SQLite não altera uma coluna nem uma chave estrangeira: a tabela é refeita com as partes que $mudar devolve (as colunas e
// as restrições do CREATE TABLE), os dados copiados (as colunas que continuam), e os índices recriados (menos os que usam a
// coluna que saiu, $sai). As outras tabelas que apontam para ela continuam apontando (o nome volta a ser o mesmo)
function sqlite_refazer($tabela, $mudar, $sai = null)
{
    $criar = (string)valor("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$tabela]);
    if ($criar === "") {
        throw new BancoErro(t("a tabela {1} não existe", [1 => $tabela]));
    }
    $indices = array_column(linhas("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$tabela]), "sql");
    $abre = strpos($criar, "(");
    $fecha = strrpos($criar, ")");
    $partes = $mudar(sql_dividir(substr($criar, $abre + 1, $fecha - $abre - 1)));
    $colunas = [];
    foreach ($partes as $p) {
        $primeira = trim((string)preg_split("/\\s+/", trim($p))[0], "\"`");
        if (preg_match("/^(PRIMARY|FOREIGN|UNIQUE|CONSTRAINT|CHECK)\$/i", $primeira) !== 1) {
            $colunas[] = $primeira;
        }
    }
    $novo = $tabela . "__novo";
    banco_direto("PRAGMA foreign_keys = OFF");
    banco_direto("BEGIN");
    try {
        banco_direto("CREATE TABLE " . $novo . " (" . implode(", ", $partes) . ")");
        // uma linha que não cumpre uma regra nova (CHECK, NOT NULL) para a cópia: nada muda e o erro sobe
        banco_direto("INSERT INTO " . $novo . " (" . implode(", ", $colunas) . ") SELECT " . implode(", ", $colunas) . " FROM " . $tabela);
        banco_direto("DROP TABLE " . $tabela);
        banco_direto("ALTER TABLE " . $novo . " RENAME TO " . $tabela);
        foreach ($indices as $ix) {
            $usa = preg_match("/\\(([^)]*)\\)\\s*\$/", $ix, $m) === 1 ? array_map(function ($x) { return strtolower(trim($x, " \"`")); }, explode(",", $m[1])) : [];
            if ($sai === null || !in_array(strtolower($sai), $usa, true)) {
                banco_direto($ix);
            }
        }
        banco_direto("COMMIT");
    } catch (Throwable $e) {
        banco_direto("ROLLBACK");
        banco_direto("PRAGMA foreign_keys = ON");
        throw $e;
    }
    banco_direto("PRAGMA foreign_keys = ON");
}

// SQLite não altera uma coluna: a tabela é refeita com a coluna nova (sqlite_refazer)
function sqlite_modificar_coluna($tabela, $definicao)
{
    $coluna = strtolower(preg_split("/\\s+/", trim($definicao))[0]);
    sqlite_refazer($tabela, function ($partes) use ($tabela, $coluna, $definicao) {
        $achou = false;
        foreach ($partes as $i => $p) {
            if (strtolower(trim((string)preg_split("/\\s+/", trim($p))[0], "\"`")) === $coluna) {
                $partes[$i] = $definicao;
                $achou = true;
            }
        }
        if (!$achou) {
            throw new BancoErro(t("MODIFY COLUMN: a tabela {1} não tem a coluna {2}", [1 => $tabela, 2 => $coluna]));
        }
        return $partes;
    });
}

// Firebird: o MODIFY COLUMN em passos, cada um só se a coluna de agora (no catálogo) é diferente: a regra do ENUM sai, o
// tipo muda (um texto não encurta no Firebird: fica o tamanho de antes, e a regra nova cuida dos valores), o NOT NULL, o
// valor padrão, e a regra nova entra. Tudo numa transação: se algo falha, a coluna fica como estava
function firebird_modificar_coluna($tabela, $definicao)
{
    $c = sql_coluna($definicao, "firebird", $tabela);
    $t = banco_tabela_sql($tabela);
    $col = $c["nome"];
    $atual = linha("SELECT f.RDB\$FIELD_TYPE AS ft, f.RDB\$FIELD_SUB_TYPE AS st, f.RDB\$CHARACTER_LENGTH AS tam, f.RDB\$FIELD_PRECISION AS pr, f.RDB\$FIELD_SCALE AS es,
        rf.RDB\$NULL_FLAG AS nn, rf.RDB\$DEFAULT_SOURCE AS padrao FROM RDB\$RELATION_FIELDS rf JOIN RDB\$FIELDS f ON f.RDB\$FIELD_NAME = rf.RDB\$FIELD_SOURCE
        WHERE rf.RDB\$RELATION_NAME = ? AND rf.RDB\$FIELD_NAME = ?", [strtoupper($tabela), strtoupper($col)]);
    if ($atual === null) {
        throw new BancoErro(t("MODIFY COLUMN: a tabela {1} não tem a coluna {2}", [1 => $tabela, 2 => $col]));
    }
    $tipo_atual = banco_fb_tipo($atual);
    $tipo_novo = preg_replace("/ CHARACTER SET UTF8\$/", "", $c["tipo"]);
    $regra = banco_fb_nome($tabela . "_" . $col . "_check");
    $tem_regra = (int)valor("SELECT COUNT(*) FROM RDB\$RELATION_CONSTRAINTS WHERE RDB\$RELATION_NAME = ? AND RDB\$CONSTRAINT_NAME = ?", [strtoupper($tabela), strtoupper($regra)]) > 0;
    $comandos = [];
    if ($tem_regra) {
        $comandos[] = "ALTER TABLE " . $t . " DROP CONSTRAINT " . $regra;
    }
    if ($tipo_novo !== $tipo_atual) {
        $encurta = preg_match("/^VARCHAR\\((\\d+)\\)\$/", $tipo_novo, $a) === 1 && preg_match("/^VARCHAR\\((\\d+)\\)\$/", $tipo_atual, $b) === 1 && (int)$a[1] < (int)$b[1];
        if (!$encurta) {
            $comandos[] = "ALTER TABLE " . $t . " ALTER COLUMN " . $col . " TYPE " . $c["tipo"];
        }
    }
    if ($c["nao_nulo"] !== ((int)$atual["nn"] === 1)) {
        $comandos[] = "ALTER TABLE " . $t . " ALTER " . $col . ($c["nao_nulo"] ? " SET NOT NULL" : " DROP NOT NULL");
    }
    $padrao_atual = trim((string)preg_replace("/^\\s*DEFAULT\\s+/i", "", (string)$atual["padrao"]));
    if ($c["padrao"] !== null && $c["padrao"] !== $padrao_atual) {
        $comandos[] = "ALTER TABLE " . $t . " ALTER COLUMN " . $col . " SET DEFAULT " . $c["padrao"];
    } elseif ($c["padrao"] === null && $padrao_atual !== "") {
        $comandos[] = "ALTER TABLE " . $t . " ALTER COLUMN " . $col . " DROP DEFAULT";
    }
    if ($c["check"] !== "") {
        $comandos[] = "ALTER TABLE " . $t . " ADD" . $c["check"];
    }
    if (count($comandos) === 0) {
        return;
    }
    banco_inicio();
    try {
        foreach ($comandos as $x) {
            banco_direto($x);
        }
        banco_confirma();
    } catch (Throwable $e) {
        banco_desfaz();
        throw $e;
    }
}

// O tipo de uma coluna do Firebird, lido do catálogo (RDB$FIELDS), no jeito que a tradução escreve: INTEGER, SMALLINT,
// BIGINT, VARCHAR(n), BLOB SUB_TYPE TEXT, BLOB SUB_TYPE BINARY, DECIMAL(p,e), TIMESTAMP, DATE, TIME, DOUBLE PRECISION
function banco_fb_tipo($f)
{
    $ft = (int)$f["ft"];
    $escala = -(int)($f["es"] ?? 0);
    if (in_array($ft, [7, 8, 16], true) && $escala > 0) {
        return "DECIMAL(" . (int)$f["pr"] . "," . $escala . ")";
    }
    switch ($ft) {
        case 7:
            return "SMALLINT";
        case 8:
            return "INTEGER";
        case 16:
            return "BIGINT";
        case 10:
        case 27:
            return "DOUBLE PRECISION";
        case 12:
            return "DATE";
        case 13:
            return "TIME";
        case 35:
            return "TIMESTAMP";
        case 14:
            return "CHAR(" . (int)$f["tam"] . ")";
        case 37:
            return "VARCHAR(" . (int)$f["tam"] . ")";
        case 261:
            return (int)$f["st"] === 1 ? "BLOB SUB_TYPE TEXT" : "BLOB SUB_TYPE BINARY";
        case 23:
            return "BOOLEAN";
    }
    return "TIPO " . $ft;
}

// ---------------------------------------------------------------------------------------------------------------------
// A tradução do SQL
// ---------------------------------------------------------------------------------------------------------------------

// Os pedaços de um comando: [tipo, texto]. Tipos: s (texto entre aspas simples, já no formato padrão: 'it''s'), p (o ?),
// w (palavra), q (nome entre crases ou aspas duplas), n (número), e (espaço), o (outro caractere). Os comentários saem.
function sql_pedacos($sql)
{
    $p = [];
    $n = strlen($sql);
    $i = 0;
    while ($i < $n) {
        $ch = $sql[$i];
        if ($ch === "'") {
            // o texto do MySQL: '' e \' são a aspa; \n, \t, \0, \\ os escapes
            $v = "";
            $i++;
            while ($i < $n) {
                $c = $sql[$i];
                if ($c === "\\" && $i + 1 < $n) {
                    $e = $sql[$i + 1];
                    $v .= ["n" => "\n", "t" => "\t", "r" => "\r", "0" => "\0", "b" => "\x08", "Z" => "\x1a"][$e] ?? $e;
                    $i += 2;
                } elseif ($c === "'" && $i + 1 < $n && $sql[$i + 1] === "'") {
                    $v .= "'";
                    $i += 2;
                } elseif ($c === "'") {
                    $i++;
                    break;
                } else {
                    $v .= $c;
                    $i++;
                }
            }
            $p[] = ["s", $v];
        } elseif ($ch === "-" && substr($sql, $i, 2) === "--") {
            $fim = strpos($sql, "\n", $i);
            $i = $fim === false ? $n : $fim;
        } elseif ($ch === "/" && substr($sql, $i, 2) === "/*") {
            $fim = strpos($sql, "*/", $i + 2);
            $i = $fim === false ? $n : $fim + 2;
        } elseif ($ch === "?") {
            $p[] = ["p", "?"];
            $i++;
        } elseif ($ch === "`" || $ch === "\"") {
            $fim = strpos($sql, $ch, $i + 1);
            $fim = $fim === false ? $n : $fim;
            $p[] = ["q", substr($sql, $i + 1, $fim - $i - 1)];
            $i = $fim + 1;
        } elseif (ctype_space($ch)) {
            $j = $i;
            while ($j < $n && ctype_space($sql[$j])) {
                $j++;
            }
            $p[] = ["e", " "];
            $i = $j;
        } elseif (ctype_alpha($ch) || $ch === "_") {
            $j = $i;
            while ($j < $n && (ctype_alnum($sql[$j]) || $sql[$j] === "_")) {
                $j++;
            }
            $p[] = ["w", substr($sql, $i, $j - $i)];
            $i = $j;
        } elseif (ctype_digit($ch)) {
            $j = $i;
            while ($j < $n && (ctype_digit($sql[$j]) || $sql[$j] === ".")) {
                $j++;
            }
            $p[] = ["n", substr($sql, $i, $j - $i)];
            $i = $j;
        } else {
            $p[] = ["o", $ch];
            $i++;
        }
    }
    return $p;
}

function sql_texto($pedacos, $banco = null)
{
    $banco = $banco ?? banco_tipo();
    $s = "";
    $n = 0;
    foreach ($pedacos as $p) {
        switch ($p[0]) {
            case "s":
                $s .= $banco === "mysql" ? "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $p[1]) . "'" : "'" . str_replace("'", "''", $p[1]) . "'";
                break;
            case "p":
                $s .= $banco === "pgsql" ? "\$" . (++$n) : "?";
                break;
            case "q":
                if ($banco === "firebird") {
                    // no Firebird o nome entre aspas diferencia maiúsculas: vai sem aspas (menos o "NO", reservado)
                    $s .= strtolower($p[1]) === "no" ? "\"NO\"" : $p[1];
                } else {
                    $s .= $banco === "mysql" ? "`" . $p[1] . "`" : "\"" . $p[1] . "\"";
                }
                break;
            case "w":
                // a tabela "no" (escrita em minúsculas; o NO de "NO ACTION" fica)
                $s .= $banco === "firebird" && $p[1] === "no" ? "\"NO\"" : $p[1];
                break;
            default:
                $s .= $p[1];
        }
    }
    return trim($s);
}

// Os comandos de um arquivo: sem comentários, separados pelo ";" que não está dentro de um texto
function sql_comandos($texto)
{
    $texto = str_replace("\r\n", "\n", (string)$texto);
    $comandos = [];
    $atual = "";
    $n = strlen($texto);
    $i = 0;
    while ($i < $n) {
        $ch = $texto[$i];
        if ($ch === "'") {
            $j = $i + 1;
            while ($j < $n) {
                if ($texto[$j] === "\\") {
                    $j += 2;
                    continue;
                }
                if ($texto[$j] === "'" && ($texto[$j + 1] ?? "") === "'") {
                    $j += 2;
                    continue;
                }
                if ($texto[$j] === "'") {
                    break;
                }
                $j++;
            }
            $atual .= substr($texto, $i, $j - $i + 1);
            $i = $j + 1;
        } elseif ($ch === "-" && substr($texto, $i, 2) === "--") {
            $fim = strpos($texto, "\n", $i);
            $i = $fim === false ? $n : $fim;
        } elseif ($ch === ";") {
            if (trim($atual) !== "") {
                $comandos[] = trim($atual);
            }
            $atual = "";
            $i++;
        } else {
            $atual .= $ch;
            $i++;
        }
    }
    if (trim($atual) !== "") {
        $comandos[] = trim($atual);
    }
    return $comandos;
}

// Divide pela vírgula que não está dentro de parênteses nem de um texto (as colunas de um CREATE TABLE)
function sql_dividir($texto)
{
    $partes = [];
    $atual = "";
    $nivel = 0;
    $n = strlen($texto);
    for ($i = 0; $i < $n; $i++) {
        $ch = $texto[$i];
        if ($ch === "'") {
            $j = $i + 1;
            while ($j < $n && !($texto[$j] === "'" && ($texto[$j + 1] ?? "") !== "'")) {
                $j += $texto[$j] === "'" || $texto[$j] === "\\" ? 2 : 1;
            }
            $atual .= substr($texto, $i, $j - $i + 1);
            $i = $j;
            continue;
        }
        if ($ch === "(") {
            $nivel++;
        } elseif ($ch === ")") {
            $nivel--;
        }
        if ($ch === "," && $nivel === 0) {
            $partes[] = trim($atual);
            $atual = "";
        } else {
            $atual .= $ch;
        }
    }
    if (trim($atual) !== "") {
        $partes[] = trim($atual);
    }
    return $partes;
}

// Um comando do MySQL no banco em uso: devolve a lista do que rodar (quase sempre um comando só; um CREATE TABLE com
// INDEX vira a tabela e os índices; um MODIFY COLUMN no SQLite vira ["sqlite_modificar", tabela, coluna])
function sql_traduzir($comando)
{
    static $cache = [];
    $banco = banco_tipo();
    if ($banco === "mysql") {
        // toda tabela nasce em utf8mb4_unicode_ci, qualquer que seja o padrão do banco (no MariaDB, a utf8mb4_general_ci
        // ordena os textos de outro jeito que os outros bancos)
        if (preg_match("/^\\s*CREATE\\s+TABLE\\b/i", $comando) === 1 && stripos($comando, "COLLATE") === false) {
            $comando = rtrim($comando, " \t\r\n;") . " DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        return [$comando];
    }
    if (isset($cache[$comando])) {
        return $cache[$comando];
    }
    $p = sql_pedacos($comando);
    $palavras = [];
    foreach ($p as $x) {
        if ($x[0] === "w") {
            $palavras[] = strtoupper($x[1]);
            if (count($palavras) === 3) {
                break;
            }
        }
    }
    $inicio = implode(" ", $palavras);
    if (strpos($inicio, "SET NAMES") === 0) {
        // a codificação da conexão do MySQL: nos outros, a conexão já é UTF-8
        $saida = [];
    } elseif (strpos($inicio, "CREATE TABLE") === 0) {
        $saida = sql_criar_tabela($comando, $banco);
    } elseif (strpos($inicio, "ALTER TABLE") === 0) {
        $saida = sql_alterar_tabela($comando, $banco);
    } elseif ($banco === "firebird") {
        // o Firebird não tem o INSERT de várias linhas: um INSERT por linha
        $saida = array_map(function ($x) use ($banco) {
            return sql_texto(sql_dml($x, $banco), $banco);
        }, sql_linhas_do_insert($p));
    } else {
        $saida = [sql_texto(sql_dml($p, $banco), $banco)];
    }
    if (count($cache) > 2000) {
        $cache = [];
    }
    return $cache[$comando] = $saida;
}

// Um INSERT ... VALUES (...), (...) (sem parâmetros) dividido num INSERT por linha; outro comando volta como está
function sql_linhas_do_insert($p)
{
    $n = count($p);
    $valores = null;
    $tem_param = false;
    foreach ($p as $i => $x) {
        if ($x[0] === "w" && strtoupper($x[1]) === "VALUES" && $valores === null) {
            $valores = $i;
        }
        $tem_param = $tem_param || $x[0] === "p";
    }
    if ($valores === null || $tem_param || !($p[0][0] === "w" && strtoupper($p[0][1]) === "INSERT")) {
        return [$p];
    }
    $grupos = [];
    $nivel = 0;
    $atual = [];
    for ($i = $valores + 1; $i < $n; $i++) {
        $x = $p[$i];
        if ($x[1] === "(" && $x[0] === "o") {
            $nivel++;
        } elseif ($x[1] === ")" && $x[0] === "o") {
            $nivel--;
        }
        if ($nivel === 0 && $x[0] === "o" && ($x[1] === "," || $x[1] === ";")) {
            $grupos[] = $atual;
            $atual = [];
            continue;
        }
        if ($nivel === 0 && $x[0] !== "o" && $x[0] !== "e") {
            // algo depois das linhas (ON DUPLICATE KEY...): como está
            return [$p];
        }
        $atual[] = $x;
    }
    $grupos[] = $atual;
    if (count($grupos) < 2) {
        return [$p];
    }
    $cabeca = array_slice($p, 0, $valores + 1);
    return array_map(function ($g) use ($cabeca) {
        return array_merge($cabeca, [["e", " "]], $g);
    }, $grupos);
}

// O DML: INSERT IGNORE, REPLACE INTO, NOW() e a ordem dos NULL (e no Firebird o LIMIT, o DATE() e o <=>)
function sql_dml($p, $banco)
{
    if ($banco === "firebird") {
        $p = sql_fb_replace($p);
    }
    $n = count($p);
    $prox = function ($i) use ($p, $n) {
        for ($j = $i + 1; $j < $n; $j++) {
            if ($p[$j][0] !== "e") {
                return $j;
            }
        }
        return null;
    };
    $palavra = function ($i, $w) use ($p) {
        return $i !== null && $p[$i][0] === "w" && strtoupper($p[$i][1]) === $w;
    };
    $saida = [];
    $fim_insert = null;
    $fechar = [];
    $antes = [];
    $no_fim = "";
    if ($banco === "firebird" && ($p[0][1] ?? "") === "UPDATE OR INSERT") {
        $fim_insert = $p[0][2];
    }
    if ($banco === "firebird") {
        // o SELECT sem FROM (SELECT 'x', 1) ganha o FROM RDB$DATABASE, a tabela de uma linha só do Firebird
        for ($i = 0; $i < $n; $i++) {
            if ($p[$i][0] !== "w" || strtoupper($p[$i][1]) !== "SELECT") {
                continue;
            }
            $nivel = 0;
            $tem_from = false;
            for ($k = $i + 1; $k < $n; $k++) {
                $y = $p[$k];
                if ($y[0] === "o" && $y[1] === "(") {
                    $nivel++;
                } elseif ($y[0] === "o" && $y[1] === ")") {
                    if ($nivel === 0) {
                        break;
                    }
                    $nivel--;
                } elseif ($nivel === 0 && $y[0] === "w" && in_array(strtoupper($y[1]), ["FROM", "UNION"], true)) {
                    $tem_from = $tem_from || strtoupper($y[1]) === "FROM";
                    break;
                }
            }
            if (!$tem_from) {
                if ($k >= $n) {
                    $no_fim .= " FROM RDB\$DATABASE";
                } else {
                    $antes[$k] = ($antes[$k] ?? "") . " FROM RDB\$DATABASE ";
                }
            }
        }
    }
    for ($i = 0; $i < $n; $i++) {
        $x = $p[$i];
        if (isset($antes[$i])) {
            $saida[] = ["w", $antes[$i]];
        }
        if (isset($fechar[$i])) {
            foreach ((array)$fechar[$i] as $y) {
                $saida[] = is_array($y) ? $y : ["w", $y];
            }
            continue;
        }
        // INSERT IGNORE INTO: SQLite INSERT OR IGNORE; Postgres ON CONFLICT DO NOTHING no fim; Firebird: a marca /*IGNORE*/
        // no começo (o banco_executar não para na chave repetida)
        if ($palavra($i, "INSERT") && $palavra($prox($i), "IGNORE")) {
            $j = $prox($i);
            $saida[] = ["w", $banco === "firebird" ? "/*IGNORE*/ INSERT" : "INSERT"];
            if ($banco === "firebird") {
                // nada no fim
            } elseif ($banco === "sqlite") {
                $saida[] = ["e", " "];
                $saida[] = ["w", "OR IGNORE"];
            } else {
                $fim_insert = " ON CONFLICT DO NOTHING";
            }
            $i = $j;
            continue;
        }
        // REPLACE INTO (o SQLite entende): Postgres INSERT ... ON CONFLICT (a chave) DO UPDATE
        if ($banco === "pgsql" && $palavra($i, "REPLACE") && $palavra($prox($i), "INTO")) {
            $t = $prox($prox($i));
            $tabela = $p[$t][1];
            $abre = $prox($t);
            $colunas = [];
            for ($j = $abre + 1; $j < $n && $p[$j][1] !== ")"; $j++) {
                if ($p[$j][0] === "w" || $p[$j][0] === "q") {
                    $colunas[] = $p[$j][1];
                }
            }
            $chave = banco_chave_primaria($tabela);
            $outras = array_values(array_diff($colunas, $chave));
            // as colunas que o comando não cita voltam ao padrão, como no REPLACE do MySQL (que apaga a linha e grava de novo)
            $trocas = array_merge(array_map(function ($c) {
                return $c . " = EXCLUDED." . $c;
            }, $outras), array_map(function ($c) {
                return $c . " = DEFAULT";
            }, array_values(array_diff(banco_colunas($tabela), $colunas, $chave))));
            $fim_insert = " ON CONFLICT (" . implode(", ", $chave) . ") DO " . (count($trocas) === 0 ? "NOTHING" : "UPDATE SET " . implode(", ", $trocas));
            $saida[] = ["w", "INSERT"];
            continue;
        }
        // a <=> b: igual, e NULL <=> NULL é verdadeiro. Postgres: IS NOT DISTINCT FROM; SQLite: IS
        if ($x[1] === "<" && ($p[$i + 1][1] ?? "") === "=" && ($p[$i + 2][1] ?? "") === ">") {
            $saida[] = ["w", $banco === "pgsql" || $banco === "firebird" ? "IS NOT DISTINCT FROM" : "IS"];
            $i += 2;
            continue;
        }
        if ($banco === "firebird") {
            // NOW(): a hora do PHP, posta na hora de rodar (banco_fb_agora)
            if ($palavra($i, "NOW")) {
                $a = $prox($i);
                $f = $a === null ? null : $prox($a);
                if ($a !== null && $p[$a][1] === "(" && $f !== null && $p[$f][1] === ")") {
                    $saida[] = ["w", "CAST('@@AGORA@@' AS TIMESTAMP)"];
                    $i = $f;
                    continue;
                }
            }
            // as funções com outro nome: LENGTH (bytes) OCTET_LENGTH; LEAST e GREATEST: MINVALUE e MAXVALUE
            if ($x[0] === "w" && ($a = $prox($i)) !== null && $p[$a][1] === "(" && in_array(strtoupper($x[1]), ["LENGTH", "LEAST", "GREATEST"], true)) {
                $saida[] = ["w", ["LENGTH" => "OCTET_LENGTH", "LEAST" => "MINVALUE", "GREATEST" => "MAXVALUE"][strtoupper($x[1])]];
                continue;
            }
            // CONCAT(a, b, ...): (a || b || ...); o parâmetro sozinho ganha um tipo (CAST(? AS VARCHAR(8191))), que o || exige
            if ($palavra($i, "CONCAT") && ($a = $prox($i)) !== null && $p[$a][1] === "(") {
                $nivel = 0;
                $inicio_arg = $a + 1;
                for ($k = $a; $k < $n; $k++) {
                    $y = $p[$k];
                    if ($y[0] === "o" && $y[1] === "(") {
                        $nivel++;
                        continue;
                    }
                    if ($y[0] === "o" && ($y[1] === ")" || $y[1] === ",") && $nivel === 1) {
                        $arg = array_values(array_filter(range($inicio_arg, $k - 1), function ($z) use ($p) { return $p[$z][0] !== "e"; }));
                        if (count($arg) === 1 && $p[$arg[0]][0] === "p") {
                            $fechar[$arg[0]] = [["w", "CAST("], ["p", "?"], ["w", " AS VARCHAR(8191))"]];
                        }
                        $inicio_arg = $k + 1;
                        if ($y[1] === ",") {
                            $fechar[$k] = " ||";
                            continue;
                        }
                    }
                    if ($y[0] === "o" && $y[1] === ")" && --$nivel === 0) {
                        break;
                    }
                }
                $saida[] = ["o", "("];
                $i = $a;
                continue;
            }
            // DATE(x): CAST(x AS DATE)
            if ($palavra($i, "DATE") && ($a = $prox($i)) !== null && $p[$a][1] === "(") {
                $nivel = 0;
                for ($k = $a; $k < $n; $k++) {
                    if ($p[$k][0] === "o" && $p[$k][1] === "(") {
                        $nivel++;
                    } elseif ($p[$k][0] === "o" && $p[$k][1] === ")" && --$nivel === 0) {
                        break;
                    }
                }
                $fechar[$k] = " AS DATE)";
                $saida[] = ["w", "CAST("];
                $i = $a;
                continue;
            }
            // LIMIT n [OFFSET m] (ou LIMIT m, n): OFFSET m ROWS FETCH NEXT n ROWS ONLY
            if ($palavra($i, "LIMIT") && ($a = $prox($i)) !== null) {
                $lim = $p[$a];
                $desloc = null;
                $fim = $a;
                $b = $prox($a);
                if ($b !== null && $p[$b][1] === ",") {
                    $desloc = $lim;
                    $fim = $prox($b);
                    $lim = $p[$fim];
                } elseif ($palavra($b, "OFFSET")) {
                    $fim = $prox($b);
                    $desloc = $p[$fim];
                }
                if ($desloc !== null) {
                    array_push($saida, ["w", "OFFSET"], ["e", " "], $desloc, ["w", " ROWS FETCH NEXT "], $lim, ["w", " ROWS ONLY"]);
                } else {
                    array_push($saida, ["w", "FETCH FIRST "], $lim, ["w", " ROWS ONLY"]);
                }
                $i = $fim;
                continue;
            }
        }
        // LIKE: no MySQL não diferencia maiúsculas (no SQLite o LIKE já é assim). No Postgres, ILIKE; mas ele não funciona
        // nas colunas com a collation "ci" (nem o LIKE, antes da versão 18): a expressão da esquerda vai para a collation
        // padrão do banco. "nome NOT LIKE '%x%'" vira "(nome) COLLATE "default" NOT ILIKE '%x%'"
        if ($banco === "pgsql" && $palavra($i, "LIKE")) {
            $fim = count($saida) - 1;
            while ($fim >= 0 && $saida[$fim][0] === "e") {
                $fim--;
            }
            $negado = $fim >= 0 && $saida[$fim][0] === "w" && strtoupper($saida[$fim][1]) === "NOT";
            if ($negado) {
                $fim--;
                while ($fim >= 0 && $saida[$fim][0] === "e") {
                    $fim--;
                }
            }
            // a expressão da esquerda: um nome (a.b) ou uma chamada com parênteses (CONCAT(...))
            $ini = $fim;
            if ($ini >= 0 && $saida[$ini][1] === ")") {
                $nivel = 0;
                for (; $ini >= 0; $ini--) {
                    if ($saida[$ini][1] === ")") {
                        $nivel++;
                    } elseif ($saida[$ini][1] === "(") {
                        $nivel--;
                        if ($nivel === 0) {
                            break;
                        }
                    }
                }
                if ($ini > 0 && $saida[$ini - 1][0] === "w") {
                    $ini--;
                }
            } else {
                while ($ini >= 2 && $saida[$ini - 1][1] === "." && ($saida[$ini - 2][0] === "w" || $saida[$ini - 2][0] === "q")) {
                    $ini -= 2;
                }
            }
            $expr = array_slice($saida, $ini, $fim - $ini + 1);
            $saida = array_slice($saida, 0, $ini);
            $saida[] = ["o", "("];
            foreach ($expr as $y) {
                $saida[] = $y;
            }
            $saida[] = ["w", ") COLLATE \"default\" " . ($negado ? "NOT " : "") . "ILIKE"];
            continue;
        }
        // NOW(): a hora de agora, sem fração, no fuso (o SQLite tem a função NOW do PHP)
        if ($banco === "pgsql" && $palavra($i, "NOW")) {
            $a = $prox($i);
            $f = $a === null ? null : $prox($a);
            if ($a !== null && $p[$a][1] === "(" && $f !== null && $p[$f][1] === ")") {
                $saida[] = ["w", "date_trunc('second', LOCALTIMESTAMP)"];
                $i = $f;
                continue;
            }
        }
        // ORDER BY: no MySQL e no SQLite o NULL vem antes no crescente e depois no decrescente; no Postgres, ao contrário
        if ($banco === "pgsql" && $palavra($i, "ORDER") && $palavra($prox($i), "BY")) {
            $j = $prox($i);
            $saida[] = ["w", "ORDER BY"];
            $nivel = 0;
            $item = [];
            $itens = [];
            for ($k = $j + 1; $k < $n; $k++) {
                $y = $p[$k];
                if ($y[1] === "(") {
                    $nivel++;
                } elseif ($y[1] === ")") {
                    if ($nivel === 0) {
                        break;
                    }
                    $nivel--;
                }
                if ($nivel === 0 && ($y[1] === "," || $y[1] === ";" || ($y[0] === "w" && in_array(strtoupper($y[1]), ["LIMIT", "OFFSET", "FETCH", "FOR", "UNION"], true)))) {
                    if ($y[1] !== ",") {
                        break;
                    }
                    $itens[] = $item;
                    $item = [];
                    continue;
                }
                $item[] = $y;
            }
            $itens[] = $item;
            foreach ($itens as $ix => $it) {
                $ultimas = [];
                foreach ($it as $y) {
                    if ($y[0] === "w") {
                        $ultimas[] = strtoupper($y[1]);
                    } elseif ($y[0] !== "e") {
                        $ultimas[] = $y[1];
                    }
                }
                $ult = end($ultimas);
                if ($ix > 0) {
                    $saida[] = ["o", ","];
                }
                foreach ($it as $y) {
                    $saida[] = $y;
                }
                if (!in_array("NULLS", $ultimas, true)) {
                    $saida[] = ["w", $ult === "DESC" ? " NULLS LAST " : " NULLS FIRST "];
                }
            }
            $i = $k - 1;
            continue;
        }
        $saida[] = $x;
    }
    if ($no_fim !== "") {
        while (count($saida) > 0 && ($saida[count($saida) - 1][0] === "e" || $saida[count($saida) - 1][1] === ";")) {
            array_pop($saida);
        }
        $saida[] = ["w", $no_fim];
    }
    if ($fim_insert !== null) {
        while (count($saida) > 0 && ($saida[count($saida) - 1][0] === "e" || $saida[count($saida) - 1][1] === ";")) {
            array_pop($saida);
        }
        $saida[] = ["w", $fim_insert];
    }
    return $saida;
}

// Firebird: REPLACE INTO t (colunas) VALUES (...) vira UPDATE OR INSERT INTO t (colunas) VALUES (...) MATCHING (chave),
// com a chave primária (ou a chave única) que o comando cita inteira. As colunas que o comando não cita voltam ao padrão
// delas (entram no comando com o valor padrão), como no REPLACE do MySQL, que apaga a linha e grava de novo. O primeiro
// pedaço da saída leva o MATCHING no terceiro item (o sql_dml põe no fim)
function sql_fb_replace($p)
{
    $n = count($p);
    $ws = [];
    foreach ($p as $i => $x) {
        if ($x[0] !== "e") {
            $ws[] = $i;
        }
        if (count($ws) === 4) {
            break;
        }
    }
    if (count($ws) < 4 || !($p[$ws[0]][0] === "w" && strtoupper($p[$ws[0]][1]) === "REPLACE" && strtoupper($p[$ws[1]][1]) === "INTO" && $p[$ws[3]][1] === "(")) {
        return $p;
    }
    $tabela = strtolower($p[$ws[2]][1]);
    $colunas = [];
    for ($j = $ws[3] + 1; $j < $n && $p[$j][1] !== ")"; $j++) {
        if ($p[$j][0] === "w" || $p[$j][0] === "q") {
            $colunas[] = strtolower($p[$j][1]);
        }
    }
    $fecha_colunas = $j;
    $chave = banco_chave_primaria($tabela);
    if (count(array_diff($chave, $colunas)) > 0) {
        foreach (banco_fb_unicas($tabela) as $u) {
            if (count(array_diff($u, $colunas)) === 0) {
                $chave = $u;
                break;
            }
        }
    }
    $padroes = banco_fb_padroes($tabela);
    $faltam = array_values(array_diff(array_keys($padroes), $colunas, $chave));
    // o grupo dos valores: o parêntese depois de VALUES
    $valores = null;
    for ($j = $fecha_colunas; $j < $n; $j++) {
        if ($p[$j][0] === "w" && strtoupper($p[$j][1]) === "VALUES") {
            $valores = $j;
            break;
        }
    }
    $fecha_valores = null;
    if ($valores !== null) {
        $nivel = 0;
        for ($j = $valores + 1; $j < $n; $j++) {
            if ($p[$j][0] === "o" && $p[$j][1] === "(") {
                $nivel++;
            } elseif ($p[$j][0] === "o" && $p[$j][1] === ")" && --$nivel === 0) {
                $fecha_valores = $j;
                break;
            }
        }
    }
    $saida = [["w", "UPDATE OR INSERT", " MATCHING (" . implode(", ", $chave) . ")"]];
    for ($j = $ws[0] + 1; $j < $n; $j++) {
        if ($j === $fecha_colunas) {
            foreach ($faltam as $c) {
                array_push($saida, ["o", ","], ["e", " "], ["w", $c]);
            }
        }
        if ($j === $fecha_valores) {
            foreach ($faltam as $c) {
                array_push($saida, ["o", ","], ["e", " "], ["w", $padroes[$c]]);
            }
        }
        $saida[] = $p[$j];
    }
    return $saida;
}

// Firebird: as colunas de uma tabela com o valor padrão de cada uma no SQL ("NULL" sem padrão), menos a do id automático
function banco_fb_padroes($tabela)
{
    static $cache = [];
    if ($tabela === null) {
        $cache = [];
        return [];
    }
    if (!isset($cache[$tabela])) {
        $cache[$tabela] = [];
        foreach (linhas("SELECT TRIM(rf.RDB\$FIELD_NAME) AS c, rf.RDB\$DEFAULT_SOURCE AS d, rf.RDB\$IDENTITY_TYPE AS i FROM RDB\$RELATION_FIELDS rf
            WHERE rf.RDB\$RELATION_NAME = ? ORDER BY rf.RDB\$FIELD_POSITION", [strtoupper($tabela)]) as $c) {
            if ($c["i"] !== null) {
                continue;
            }
            $d = trim((string)preg_replace("/^\\s*DEFAULT\\s+/i", "", (string)$c["d"]));
            $cache[$tabela][strtolower($c["c"])] = $d !== "" ? $d : "NULL";
        }
    }
    return $cache[$tabela];
}

// Firebird: as chaves únicas de uma tabela (listas de colunas)
function banco_fb_unicas($tabela)
{
    $u = [];
    foreach (linhas("SELECT TRIM(c.RDB\$CONSTRAINT_NAME) AS n, TRIM(s.RDB\$FIELD_NAME) AS c FROM RDB\$RELATION_CONSTRAINTS c JOIN RDB\$INDEX_SEGMENTS s ON s.RDB\$INDEX_NAME = c.RDB\$INDEX_NAME
        WHERE c.RDB\$RELATION_NAME = ? AND c.RDB\$CONSTRAINT_TYPE = 'UNIQUE' ORDER BY c.RDB\$CONSTRAINT_NAME, s.RDB\$FIELD_POSITION", [strtoupper($tabela)]) as $l) {
        $u[$l["n"]][] = strtolower($l["c"]);
    }
    foreach (linhas("SELECT TRIM(i.RDB\$INDEX_NAME) AS n, TRIM(s.RDB\$FIELD_NAME) AS c FROM RDB\$INDICES i JOIN RDB\$INDEX_SEGMENTS s ON s.RDB\$INDEX_NAME = i.RDB\$INDEX_NAME
        LEFT JOIN RDB\$RELATION_CONSTRAINTS k ON k.RDB\$INDEX_NAME = i.RDB\$INDEX_NAME
        WHERE i.RDB\$RELATION_NAME = ? AND i.RDB\$UNIQUE_FLAG = 1 AND k.RDB\$CONSTRAINT_NAME IS NULL ORDER BY i.RDB\$INDEX_NAME, s.RDB\$FIELD_POSITION", [strtoupper($tabela)]) as $l) {
        $u[$l["n"]][] = strtolower($l["c"]);
    }
    foreach (linhas("SELECT TRIM(i.RDB\$INDEX_NAME) AS n, CAST(i.RDB\$DESCRIPTION AS VARCHAR(2000)) AS d FROM RDB\$INDICES i
        WHERE i.RDB\$RELATION_NAME = ? AND i.RDB\$UNIQUE_FLAG = 1 AND i.RDB\$EXPRESSION_BLR IS NOT NULL", [strtoupper($tabela)]) as $l) {
        if (preg_match("/^unica:(.+)\$/", (string)$l["d"], $m) === 1) {
            $u[$l["n"]] = explode(",", $m[1]);
        }
    }
    return array_values($u);
}

// As colunas da chave primária de uma tabela no Postgres e no Firebird (para o REPLACE INTO); null esquece o guardado
function banco_chave_primaria($tabela)
{
    static $cache = [];
    if ($tabela === null) {
        $cache = [];
        banco_fb_padroes(null);
        return [];
    }
    if (!isset($cache[$tabela]) && banco_tipo() === "firebird") {
        $cache[$tabela] = array_map("strtolower", array_column(linhas("SELECT TRIM(s.RDB\$FIELD_NAME) AS c FROM RDB\$RELATION_CONSTRAINTS c
            JOIN RDB\$INDEX_SEGMENTS s ON s.RDB\$INDEX_NAME = c.RDB\$INDEX_NAME WHERE c.RDB\$RELATION_NAME = ? AND c.RDB\$CONSTRAINT_TYPE = 'PRIMARY KEY'
            ORDER BY s.RDB\$FIELD_POSITION", [strtoupper($tabela)]), "c"));
        if (count($cache[$tabela]) === 0) {
            throw new BancoErro(t("REPLACE INTO {1}: a tabela não tem chave primária", [1 => $tabela]));
        }
    }
    if (!isset($cache[$tabela])) {
        $cache[$tabela] = array_column(linhas("SELECT a.attname AS c FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = to_regclass(?) AND i.indisprimary ORDER BY array_position(i.indkey, a.attnum)", [$tabela]), "c");
        if (count($cache[$tabela]) === 0) {
            throw new BancoErro(t("REPLACE INTO {1}: a tabela não tem chave primária", [1 => $tabela]));
        }
    }
    return $cache[$tabela];
}

// As colunas de uma tabela no Postgres, na ordem (para o REPLACE INTO: as que o comando não cita voltam ao padrão); null
// esquece o guardado
function banco_colunas($tabela)
{
    static $cache = [];
    if ($tabela === null) {
        $cache = [];
        return [];
    }
    if (!isset($cache[$tabela])) {
        $cache[$tabela] = array_column(linhas("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position", [$tabela]), "c");
    }
    return $cache[$tabela];
}

// Uma coluna do MySQL no banco em uso. Tipos: INT, TINYINT, VARCHAR, CHAR, TEXT, DECIMAL, DATETIME, DATE, TIME,
// MEDIUMBLOB e ENUM (que vira VARCHAR com CHECK); AUTO_INCREMENT; o resto (NULL, NOT NULL, DEFAULT, PRIMARY KEY, UNIQUE,
// REFERENCES) passa como está
function sql_coluna($def, $banco, $tabela)
{
    if (preg_match("/^(`?)(\\w+)\\1\\s+(\\w+)\\s*(\\(((?:[^()']|'(?:[^']|'')*')*)\\))?(.*)\$/is", trim($def), $m) !== 1) {
        throw new BancoErro(t("não sei traduzir a coluna: {1}", [1 => $def]));
    }
    [, , $nome, $tipo, , $args, $resto] = $m;
    $tipo = strtoupper($tipo);
    if ($banco === "firebird") {
        return sql_coluna_fb($nome, $tipo, $args, $resto, $tabela);
    }
    $auto = preg_match("/\\bAUTO_INCREMENT\\b/i", $resto) === 1;
    $resto = trim((string)preg_replace("/\\s*\\bAUTO_INCREMENT\\b/i", "", $resto));
    $check = "";
    $ci = " COLLATE ci";
    switch ($tipo) {
        case "INT":
        case "INTEGER":
        case "BIGINT":
        case "SMALLINT":
        case "TINYINT":
            if ($auto) {
                $novo = $banco === "pgsql" ? "INTEGER GENERATED BY DEFAULT AS IDENTITY" : "INTEGER";
                if ($banco === "sqlite") {
                    $resto = preg_replace("/\\bPRIMARY KEY\\b/i", "PRIMARY KEY AUTOINCREMENT", $resto);
                }
            } elseif ($tipo === "TINYINT" || $tipo === "SMALLINT") {
                $novo = $banco === "pgsql" ? "SMALLINT" : "INTEGER";
            } else {
                $novo = $tipo === "BIGINT" ? "BIGINT" : "INTEGER";
            }
            break;
        case "VARCHAR":
        case "CHAR":
            $novo = "VARCHAR(" . trim($args) . ")" . $ci;
            break;
        case "ENUM":
            $valores = array_map("trim", sql_dividir($args));
            $maior = 1;
            foreach ($valores as $v) {
                $maior = max($maior, strlen($v) - 2);
            }
            $novo = "VARCHAR(" . $maior . ")" . $ci;
            $check = " CHECK (" . $nome . " IN (" . implode(", ", $valores) . "))";
            break;
        case "TEXT":
        case "MEDIUMTEXT":
        case "LONGTEXT":
            $novo = "TEXT";
            break;
        case "DECIMAL":
        case "NUMERIC":
            $novo = ($banco === "pgsql" ? "NUMERIC" : "DECIMAL") . "(" . preg_replace("/\\s+/", "", $args) . ")";
            break;
        case "DATETIME":
        case "TIMESTAMP":
            $novo = $banco === "pgsql" ? "TIMESTAMP(0)" : "DATETIME";
            break;
        case "TIME":
            $novo = $banco === "pgsql" ? "TIME(0)" : "TIME";
            break;
        case "DATE":
            $novo = "DATE";
            break;
        case "BLOB":
        case "MEDIUMBLOB":
        case "LONGBLOB":
            $novo = $banco === "pgsql" ? "BYTEA" : "BLOB";
            break;
        default:
            throw new BancoErro(t("não sei traduzir o tipo {1} da coluna {2}", [1 => $tipo, 2 => $tabela . "." . $nome]));
    }
    // os textos do valor padrão, no formato do banco
    $resto = sql_texto(sql_pedacos($resto), $banco);
    return ["nome" => $nome, "sql" => $nome . " " . $novo . ($resto !== "" ? " " . $resto : "") . $check, "tipo" => $novo, "check" => $check, "resto" => $resto];
}

// A coluna no Firebird. A ordem dele: nome tipo [DEFAULT x] [NOT NULL] [restrições] [COLLATE]. Os tipos: INTEGER (com
// AUTO_INCREMENT, GENERATED BY DEFAULT AS IDENTITY), SMALLINT (o TINYINT), VARCHAR(n) CHARACTER SET UTF8 (também o ENUM,
// com a regra de validação com nome: tabela_coluna_check), VARCHAR(8191) (o TEXT), DECIMAL, TIMESTAMP (o DATETIME),
// DATE, TIME e BLOB SUB_TYPE BINARY (o BLOB). Os de texto na collation UNICODE_CI_AI (sem maiúsculas nem acentos, como o
// MySQL). A coluna de uma chave primária é NOT NULL (o Firebird exige)
function sql_coluna_fb($nome, $tipo, $args, $resto, $tabela)
{
    $auto = preg_match("/\\bAUTO_INCREMENT\\b/i", $resto) === 1;
    $resto = (string)preg_replace("/\\s*\\bAUTO_INCREMENT\\b/i", "", $resto);
    $padrao = null;
    if (preg_match("/\\s*\\bDEFAULT\\s+('(?:[^']|'')*'|[\\w.-]+)/i", $resto, $d) === 1) {
        $padrao = sql_texto(sql_pedacos($d[1]), "firebird");
        $resto = str_replace($d[0], "", $resto);
    }
    $nao_nulo = preg_match("/\\bNOT\\s+NULL\\b/i", $resto) === 1 || preg_match("/\\bPRIMARY\\s+KEY\\b/i", $resto) === 1;
    $resto = (string)preg_replace("/\\s*\\bNOT\\s+NULL\\b/i", "", $resto);
    $resto = (string)preg_replace("/(?<!\\bSET)\\s+NULL\\b|^\\s*NULL\\b/i", "", $resto);
    $resto = sql_texto(sql_pedacos(trim($resto)), "firebird");
    $texto = false;
    $check = "";
    switch ($tipo) {
        case "INT":
        case "INTEGER":
        case "BIGINT":
        case "SMALLINT":
        case "TINYINT":
            if ($auto) {
                $novo = "INTEGER GENERATED BY DEFAULT AS IDENTITY";
                $nao_nulo = false;
            } else {
                $novo = $tipo === "BIGINT" ? "BIGINT" : ($tipo === "TINYINT" || $tipo === "SMALLINT" ? "SMALLINT" : "INTEGER");
            }
            break;
        case "VARCHAR":
        case "CHAR":
            $novo = "VARCHAR(" . trim($args) . ") CHARACTER SET UTF8";
            $texto = true;
            break;
        case "ENUM":
            $valores = array_map("trim", sql_dividir($args));
            $maior = 1;
            foreach ($valores as $v) {
                $maior = max($maior, strlen($v) - 2);
            }
            $novo = "VARCHAR(" . $maior . ") CHARACTER SET UTF8";
            $texto = true;
            $check = " CONSTRAINT " . banco_fb_nome($tabela . "_" . $nome . "_check") . " CHECK (" . $nome . " IN (" . implode(", ", $valores) . "))";
            break;
        case "TEXT":
        case "MEDIUMTEXT":
            // o maior VARCHAR do Firebird em UTF-8 (32765 bytes). Não o BLOB SUB_TYPE TEXT: o pdo_firebird (até o PHP 8.4)
            // devolve o BLOB vazio como NULL, e o BLOB não entra em ORDER BY, GROUP BY nem DISTINCT
            $novo = "VARCHAR(8191) CHARACTER SET UTF8";
            $texto = true;
            break;
        case "LONGTEXT":
            // o texto livre, sem limite (a observação de um serviço): o BLOB de texto, que não conta no tamanho da linha (até
            // 64 KB: duas colunas VARCHAR(8191) não cabem juntas). Só para o que nunca ordena, agrupa nem fica vazio
            // (vazio vai como NULL)
            $novo = "BLOB SUB_TYPE TEXT CHARACTER SET UTF8";
            break;
        case "DECIMAL":
        case "NUMERIC":
            $novo = "DECIMAL(" . preg_replace("/\\s+/", "", $args) . ")";
            break;
        case "DATETIME":
        case "TIMESTAMP":
            $novo = "TIMESTAMP";
            break;
        case "TIME":
            $novo = "TIME";
            break;
        case "DATE":
            $novo = "DATE";
            break;
        case "BLOB":
        case "MEDIUMBLOB":
        case "LONGBLOB":
            $novo = "BLOB SUB_TYPE BINARY";
            break;
        default:
            throw new BancoErro(t("não sei traduzir o tipo {1} da coluna {2}", [1 => $tipo, 2 => $tabela . "." . $nome]));
    }
    $sql = $nome . " " . $novo . ($padrao !== null ? " DEFAULT " . $padrao : "") . ($nao_nulo ? " NOT NULL" : "") . ($resto !== "" ? " " . $resto : "") . $check
        . ($texto ? " COLLATE UNICODE_CI_AI" : "");
    return ["nome" => $nome, "sql" => $sql, "tipo" => $novo, "check" => $check, "resto" => $resto, "padrao" => $padrao, "nao_nulo" => $nao_nulo];
}

function sql_criar_tabela($comando, $banco)
{
    if (preg_match("/^\\s*CREATE\\s+TABLE\\s+(IF\\s+NOT\\s+EXISTS\\s+)?`?(\\w+)`?\\s*\\((.*)\\)([^)]*)\$/is", $comando, $m) !== 1) {
        throw new BancoErro(t("não sei traduzir: {1}", [1 => substr($comando, 0, 80)]));
    }
    $tabela = $m[2];
    $defs = [];
    $indices = [];
    $partes = sql_dividir($m[3]);
    // no Firebird, as colunas da chave primária são NOT NULL
    $chave = [];
    foreach ($partes as $parte) {
        if (preg_match("/^\\s*PRIMARY\\s+KEY\\s*\\((.*)\\)\\s*\$/is", $parte, $x) === 1) {
            $chave = array_map(function ($c) { return strtolower(trim($c, " `\"")); }, explode(",", $x[1]));
        }
    }
    // as colunas que aceitam vazio (para a chave única no Firebird)
    $vazias = [];
    foreach ($partes as $parte) {
        $parte = trim((string)preg_replace("/\\s+/", " ", $parte));
        if (preg_match("/^(PRIMARY|FOREIGN|UNIQUE|CONSTRAINT|CHECK|INDEX|KEY)\\b/i", $parte) !== 1 && preg_match("/\\b(NOT NULL|PRIMARY KEY)\\b/i", $parte) !== 1
            && !in_array(strtolower(trim((string)preg_split("/\\s+/", $parte)[0], "`")), $chave, true)) {
            $vazias[] = strtolower(trim((string)preg_split("/\\s+/", $parte)[0], "`"));
        }
    }
    foreach ($partes as $parte) {
        $parte = trim((string)preg_replace("/\\s+/", " ", $parte));
        if ($banco === "firebird" && preg_match("/^UNIQUE\\s*\\((.*)\\)\$/i", $parte, $x) === 1) {
            $cols = array_map(function ($c) { return strtolower(trim($c, " `\"")); }, explode(",", $x[1]));
            if (count($cols) > 1 && count(array_intersect($cols, $vazias)) > 0) {
                // no Firebird, a chave única de várias colunas não aceita duas linhas iguais com uma coluna vazia (o MySQL
                // aceita): vira um índice único de uma expressão, vazia quando uma das colunas está vazia (as linhas
                // vazias não se repetem). As colunas ficam na descrição do índice (o catálogo lê de lá)
                $nome_ix = banco_fb_nome($tabela . "_" . implode("_", $cols) . "_uq");
                $indices[] = "CREATE UNIQUE INDEX " . $nome_ix . " ON " . banco_tabela_sql_em($tabela, $banco) . " COMPUTED BY (CASE WHEN "
                    . implode(" IS NULL OR ", $cols) . " IS NULL THEN NULL ELSE " . implode(" || '|' || ", $cols) . " END)";
                $indices[] = "COMMENT ON INDEX " . $nome_ix . " IS 'unica:" . implode(",", $cols) . "'";
                continue;
            }
        }
        if (preg_match("/^(UNIQUE\\s+)?(INDEX|KEY)\\s*(`?\\w+`?\\s*)?\\((.*)\\)\$/i", $parte, $x) === 1) {
            $cols = str_replace("`", "", $x[4]);
            $nome_ix = $tabela . "_" . preg_replace("/\\W+/", "_", $cols) . "_idx";
            $indices[] = "CREATE " . ($x[1] !== "" ? "UNIQUE " : "") . "INDEX " . ($banco === "firebird" ? banco_fb_nome($nome_ix) : $nome_ix) . " ON " . banco_tabela_sql_em($tabela, $banco) . " (" . $cols . ")";
        } elseif (preg_match("/^(PRIMARY\\s+KEY|FOREIGN\\s+KEY|UNIQUE|CONSTRAINT|CHECK)\\b/i", $parte) === 1) {
            if ($banco === "firebird" && preg_match("/^CONSTRAINT\\s+`?(\\w+)`?\\s+(.*)\$/is", $parte, $x) === 1) {
                $parte = "CONSTRAINT " . banco_fb_nome($x[1]) . " " . $x[2];
            }
            if ($banco !== "mysql" && preg_match("/^((?:CONSTRAINT\\s+\\S+\\s+)?CHECK\\s*)\\((.*)\\)\$/is", $parte, $x) === 1) {
                // a condição no dialeto do banco, como no banco_regra (o LIKE nas colunas "ci" do Postgres, o <=>)
                $defs[] = $x[1] . "(" . sql_texto(sql_dml(sql_pedacos($x[2]), $banco), $banco) . ")";
                continue;
            }
            $defs[] = sql_texto(sql_pedacos($parte), $banco);
        } else {
            if ($banco === "firebird" && in_array(strtolower(trim((string)preg_split("/\\s+/", $parte)[0], "`")), $chave, true) && stripos($parte, "NOT NULL") === false) {
                $parte .= " NOT NULL";
            }
            $defs[] = sql_coluna($parte, $banco, $tabela)["sql"];
        }
    }
    if ($banco === "firebird") {
        // o Firebird 3 não tem o IF NOT EXISTS: a marca vai para o banco_script decidir
        $criar = "CREATE TABLE " . banco_tabela_sql_em($tabela, $banco) . " (\n    " . implode(",\n    ", $defs) . "\n)";
        return $m[1] !== "" ? [["firebird_se_nao_existe", $tabela, array_merge([$criar], $indices)]] : array_merge([$criar], $indices);
    }
    return array_merge(["CREATE TABLE " . ($m[1] !== "" ? "IF NOT EXISTS " : "") . $tabela . " (\n    " . implode(",\n    ", $defs) . "\n)"], $indices);
}

// O nome de uma tabela no SQL de um banco (a "no", reservada no Firebird, entre aspas)
function banco_tabela_sql_em($tabela, $banco)
{
    return $banco === "firebird" && strtolower($tabela) === "no" ? "\"NO\"" : $tabela;
}

function sql_alterar_tabela($comando, $banco)
{
    $comando = trim((string)preg_replace("/\\s+/", " ", $comando));
    if ($banco === "firebird") {
        return sql_alterar_tabela_fb($comando);
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? ADD (COLUMN )?(.*?)( AFTER `?\\w+`?| FIRST)?\$/i", $comando, $m) === 1 && preg_match("/^(INDEX|KEY|UNIQUE|CONSTRAINT|FOREIGN|PRIMARY)\\b/i", $m[3]) !== 1) {
        return ["ALTER TABLE " . $m[1] . " ADD COLUMN " . sql_coluna($m[3], $banco, $m[1])["sql"]];
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? ADD (UNIQUE )?(INDEX|KEY) (`?\\w+`? )?\\((.*)\\)\$/i", $comando, $m) === 1) {
        $cols = str_replace("`", "", $m[5]);
        return ["CREATE " . ($m[2] !== "" ? "UNIQUE " : "") . "INDEX " . $m[1] . "_" . preg_replace("/\\W+/", "_", $cols) . "_idx ON " . $m[1] . " (" . $cols . ")"];
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? DROP (COLUMN )?`?(\\w+)`?\$/i", $comando, $m) === 1) {
        return ["ALTER TABLE " . $m[1] . " DROP COLUMN " . $m[3]];
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? MODIFY (COLUMN )?(.*?)( AFTER `?\\w+`?| FIRST)?\$/i", $comando, $m) === 1) {
        $c = sql_coluna($m[3], $banco, $m[1]);
        if ($banco === "sqlite") {
            return [["sqlite_modificar", $m[1], $c["sql"]]];
        }
        $t = $m[1];
        $nome = $c["nome"];
        $saida = ["ALTER TABLE " . $t . " DROP CONSTRAINT IF EXISTS " . $t . "_" . $nome . "_check",
            "ALTER TABLE " . $t . " ALTER COLUMN " . $nome . " TYPE " . $c["tipo"]];
        $saida[] = "ALTER TABLE " . $t . " ALTER COLUMN " . $nome . (preg_match("/\\bNOT NULL\\b/i", $c["resto"]) === 1 ? " SET NOT NULL" : " DROP NOT NULL");
        $saida[] = preg_match("/\\bDEFAULT\\s+('(?:[^']|'')*'|[\\w.-]+)/i", $c["resto"], $d) === 1
            ? "ALTER TABLE " . $t . " ALTER COLUMN " . $nome . " SET DEFAULT " . $d[1] : "ALTER TABLE " . $t . " ALTER COLUMN " . $nome . " DROP DEFAULT";
        if ($c["check"] !== "") {
            $saida[] = "ALTER TABLE " . $t . " ADD CONSTRAINT " . $t . "_" . $nome . "_check" . $c["check"];
        }
        return $saida;
    }
    throw new BancoErro(t("não sei traduzir: {1}", [1 => substr($comando, 0, 120)]));
}

// O ALTER TABLE no Firebird: ADD sem a palavra COLUMN, o índice com nome de até 31 letras, DROP sem COLUMN, e o MODIFY
// pelo firebird_modificar_coluna (que confere a coluna de agora no catálogo)
function sql_alterar_tabela_fb($comando)
{
    if (preg_match("/^ALTER TABLE `?(\\w+)`? ADD (COLUMN )?(.*?)( AFTER `?\\w+`?| FIRST)?\$/i", $comando, $m) === 1 && preg_match("/^(INDEX|KEY|UNIQUE|CONSTRAINT|FOREIGN|PRIMARY)\\b/i", $m[3]) !== 1) {
        return ["ALTER TABLE " . banco_tabela_sql_em($m[1], "firebird") . " ADD " . sql_coluna($m[3], "firebird", $m[1])["sql"]];
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? ADD (UNIQUE )?(INDEX|KEY) (`?\\w+`? )?\\((.*)\\)\$/i", $comando, $m) === 1) {
        $cols = str_replace("`", "", $m[5]);
        $nome = trim($m[4], " `") !== "" ? trim($m[4], " `") : $m[1] . "_" . preg_replace("/\\W+/", "_", $cols) . "_idx";
        return ["CREATE " . ($m[2] !== "" ? "UNIQUE " : "") . "INDEX " . banco_fb_nome($nome) . " ON " . banco_tabela_sql_em($m[1], "firebird") . " (" . $cols . ")"];
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? DROP (COLUMN )?`?(\\w+)`?\$/i", $comando, $m) === 1) {
        return ["ALTER TABLE " . banco_tabela_sql_em($m[1], "firebird") . " DROP " . $m[3]];
    }
    if (preg_match("/^ALTER TABLE `?(\\w+)`? MODIFY (COLUMN )?(.*?)( AFTER `?\\w+`?| FIRST)?\$/i", $comando, $m) === 1) {
        return [["firebird_modificar", $m[1], $m[3]]];
    }
    throw new BancoErro(t("não sei traduzir: {1}", [1 => substr($comando, 0, 120)]));
}

// ---------------------------------------------------------------------------------------------------------------------
// O catálogo do banco: cada tabela com as colunas (o tipo no dialeto do banco em uso, se aceita vazio, o padrão), a chave
// primária, as chaves estrangeiras (e o que acontece ao apagar a linha de cima), as chaves únicas, os índices e as regras
// de validação (CHECK). Lido do próprio banco (o que está valendo agora, depois de todas as migrações): é o modelo de
// dados que o recurso=ajuda&parte=reconstrucao devolve.
// ---------------------------------------------------------------------------------------------------------------------
function banco_catalogo()
{
    $res = [];
    $tabelas = banco_tabelas();
    sort($tabelas);
    foreach ($tabelas as $t) {
        if (strpos($t, "sqlite_") === 0) {
            continue;
        }
        $x = ["tabela" => $t, "colunas" => [], "chave_primaria" => [], "chaves_estrangeiras" => [], "unicas" => [], "indices" => [], "regras" => []];
        $tipo = banco_tipo();
        if ($tipo === "sqlite") {
            foreach (linhas("PRAGMA table_info(" . $t . ")") as $c) {
                $x["colunas"][] = ["nome" => $c["name"], "tipo" => $c["type"], "vazio" => (int)$c["notnull"] === 0 && (int)$c["pk"] === 0, "padrao" => $c["dflt_value"]];
                if ((int)$c["pk"] > 0) {
                    $x["chave_primaria"][(int)$c["pk"] - 1] = $c["name"];
                }
            }
            ksort($x["chave_primaria"]);
            // (a chave de várias colunas vem numa linha por coluna, com o mesmo id: vira uma, com as colunas separadas por vírgula)
            $fk = [];
            foreach (linhas("PRAGMA foreign_key_list(" . $t . ")") as $f) {
                $fk[$f["id"]]["de"][] = $f["from"];
                $fk[$f["id"]]["para"][] = $f["to"];
                $fk[$f["id"]]["tabela"] = $f["table"];
                $fk[$f["id"]]["d"] = strtoupper($f["on_delete"]);
            }
            foreach ($fk as $f) {
                $x["chaves_estrangeiras"][] = ["coluna" => implode(",", $f["de"]), "referencia" => $f["tabela"] . "." . implode(",", $f["para"]), "ao_apagar" => $f["d"]];
            }
            foreach (linhas("PRAGMA index_list(" . $t . ")") as $ix) {
                if ($ix["origin"] === "pk") {
                    continue;
                }
                $cols = array_column(linhas("PRAGMA index_info(" . $ix["name"] . ")"), "name");
                $x[(int)$ix["unique"] === 1 ? "unicas" : "indices"][] = $cols;
            }
            // as regras: as partes CHECK do CREATE TABLE (com nome: CONSTRAINT x CHECK; a de uma coluna ENUM: na coluna)
            $criar = (string)valor("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]);
            $abre = strpos($criar, "(");
            foreach (sql_dividir(substr($criar, $abre + 1, strrpos($criar, ")") - $abre - 1)) as $p) {
                $p = trim((string)preg_replace("/\\s+/", " ", $p));
                if (preg_match("/^CONSTRAINT [\"`]?(\\w+)[\"`]? CHECK ?\\((.*)\\)\$/i", $p, $m) === 1) {
                    $x["regras"][] = ["nome" => $m[1], "condicao" => $m[2]];
                } elseif (preg_match("/^CHECK ?\\((.*)\\)\$/i", $p, $m) === 1) {
                    $x["regras"][] = ["nome" => null, "condicao" => $m[1]];
                } elseif (preg_match("/^[\"`]?(\\w+)[\"`]? .*\\bCHECK ?\\((.*)\\)\$/i", $p, $m) === 1) {
                    $x["regras"][] = ["nome" => null, "condicao" => $m[2]];
                }
            }
        } elseif ($tipo === "firebird") {
            $x = banco_catalogo_fb($t, $x);
        } elseif ($tipo === "mysql") {
            foreach (linhas("SELECT COLUMN_NAME AS n, COLUMN_TYPE AS t, IS_NULLABLE AS v, COLUMN_DEFAULT AS d, COLUMN_KEY AS k FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$t]) as $c) {
                $x["colunas"][] = ["nome" => $c["n"], "tipo" => $c["t"], "vazio" => $c["v"] === "YES", "padrao" => $c["d"]];
            }
            foreach (linhas("SELECT INDEX_NAME AS i, NON_UNIQUE AS nu, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ORDER BY INDEX_NAME, SEQ_IN_INDEX", [$t]) as $s) {
                $ix[$s["i"]]["cols"][] = $s["c"];
                $ix[$s["i"]]["unico"] = (int)$s["nu"] === 0;
            }
            foreach ($ix ?? [] as $nome => $i) {
                if ($nome === "PRIMARY") {
                    $x["chave_primaria"] = $i["cols"];
                } else {
                    $x[$i["unico"] ? "unicas" : "indices"][] = $i["cols"];
                }
            }
            unset($ix);
            $fk = [];
            foreach (linhas("SELECT k.CONSTRAINT_NAME AS n, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc, r.DELETE_RULE AS d FROM information_schema.KEY_COLUMN_USAGE k
                JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION", [$t]) as $f) {
                $fk[$f["n"]]["de"][] = $f["c"];
                $fk[$f["n"]]["para"][] = $f["rc"];
                $fk[$f["n"]]["tabela"] = $f["rt"];
                $fk[$f["n"]]["d"] = strtoupper($f["d"]);
            }
            foreach ($fk as $f) {
                $x["chaves_estrangeiras"][] = ["coluna" => implode(",", $f["de"]), "referencia" => $f["tabela"] . "." . implode(",", $f["para"]), "ao_apagar" => $f["d"]];
            }
            foreach (linhas("SELECT c.CONSTRAINT_NAME AS n, c.CHECK_CLAUSE AS q FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t
                ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME AND t.CONSTRAINT_TYPE = 'CHECK'
                WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME = ? ORDER BY c.CONSTRAINT_NAME", [$t]) as $r) {
                $x["regras"][] = ["nome" => $r["n"], "condicao" => $r["q"]];
            }
        } else {
            foreach (linhas("SELECT column_name AS n, CASE WHEN character_maximum_length IS NOT NULL THEN data_type || '(' || character_maximum_length || ')'
                WHEN data_type = 'numeric' THEN data_type || '(' || numeric_precision || ',' || numeric_scale || ')' ELSE data_type END AS t, is_nullable AS v, column_default AS d
                FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position", [$t]) as $c) {
                $x["colunas"][] = ["nome" => $c["n"], "tipo" => $c["t"], "vazio" => $c["v"] === "YES", "padrao" => $c["d"]];
            }
            foreach (linhas("SELECT c.conname AS n, c.contype AS ti, pg_get_constraintdef(c.oid) AS def, c.confdeltype AS d,
                (SELECT string_agg(a.attname, ',' ORDER BY k.ordem) FROM unnest(c.conkey) WITH ORDINALITY AS k(num, ordem) JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.num) AS cols,
                (SELECT r.relname FROM pg_class r WHERE r.oid = c.confrelid) AS rt,
                (SELECT string_agg(a.attname, ',' ORDER BY k.ordem) FROM unnest(c.confkey) WITH ORDINALITY AS k(num, ordem) JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.num) AS rc
                FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relname = ? AND t.relnamespace = current_schema()::regnamespace ORDER BY c.conname", [$t]) as $k) {
                $cols = explode(",", (string)$k["cols"]);
                if ($k["ti"] === "p") {
                    $x["chave_primaria"] = $cols;
                } elseif ($k["ti"] === "u") {
                    $x["unicas"][] = $cols;
                } elseif ($k["ti"] === "f") {
                    $x["chaves_estrangeiras"][] = ["coluna" => implode(",", $cols), "referencia" => $k["rt"] . "." . $k["rc"],
                        "ao_apagar" => ["a" => "NO ACTION", "r" => "RESTRICT", "c" => "CASCADE", "n" => "SET NULL", "d" => "SET DEFAULT"][$k["d"]] ?? $k["d"]];
                } elseif ($k["ti"] === "c") {
                    $x["regras"][] = ["nome" => $k["n"], "condicao" => preg_replace("/^CHECK \\((.*)\\)\$/s", "\$1", (string)$k["def"])];
                }
            }
            foreach (linhas("SELECT indexdef AS d FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?", [$t]) as $i) {
                if (preg_match("/^CREATE (UNIQUE )?INDEX \\S+ ON \\S+ USING \\w+ \\((.*)\\)\$/", $i["d"], $m) === 1) {
                    $cols = array_map("trim", explode(",", str_replace("\"", "", $m[2])));
                    if ($cols !== $x["chave_primaria"] && !in_array($cols, $x["unicas"], true)) {
                        $x[$m[1] !== "" ? "unicas" : "indices"][] = $cols;
                    }
                }
            }
        }
        $res[] = $x;
    }
    return $res;
}

// O catálogo de uma tabela no Firebird (as tabelas RDB$ do próprio banco). Os nomes saem em minúsculas
function banco_catalogo_fb($t, $x)
{
    $T = strtoupper($t);
    foreach (linhas("SELECT TRIM(rf.RDB\$FIELD_NAME) AS n, f.RDB\$FIELD_TYPE AS ft, f.RDB\$FIELD_SUB_TYPE AS st, f.RDB\$CHARACTER_LENGTH AS tam, f.RDB\$FIELD_PRECISION AS pr,
        f.RDB\$FIELD_SCALE AS es, rf.RDB\$NULL_FLAG AS nn, rf.RDB\$DEFAULT_SOURCE AS d, rf.RDB\$IDENTITY_TYPE AS i FROM RDB\$RELATION_FIELDS rf
        JOIN RDB\$FIELDS f ON f.RDB\$FIELD_NAME = rf.RDB\$FIELD_SOURCE WHERE rf.RDB\$RELATION_NAME = ? ORDER BY rf.RDB\$FIELD_POSITION", [$T]) as $c) {
        $padrao = $c["d"] !== null ? trim((string)preg_replace("/^\\s*DEFAULT\\s+/i", "", (string)$c["d"])) : null;
        $padrao = $padrao === null || strtoupper($padrao) === "NULL" ? null : $padrao;
        $x["colunas"][] = ["nome" => strtolower($c["n"]), "tipo" => banco_fb_tipo($c) . ($c["i"] !== null ? " GENERATED BY DEFAULT AS IDENTITY" : ""),
            "vazio" => (int)$c["nn"] !== 1 && $c["i"] === null, "padrao" => $padrao];
    }
    $restricoes = [];
    foreach (linhas("SELECT TRIM(c.RDB\$CONSTRAINT_NAME) AS n, TRIM(c.RDB\$CONSTRAINT_TYPE) AS ti, TRIM(s.RDB\$FIELD_NAME) AS col FROM RDB\$RELATION_CONSTRAINTS c
        JOIN RDB\$INDEX_SEGMENTS s ON s.RDB\$INDEX_NAME = c.RDB\$INDEX_NAME WHERE c.RDB\$RELATION_NAME = ? ORDER BY c.RDB\$CONSTRAINT_NAME, s.RDB\$FIELD_POSITION", [$T]) as $k) {
        $restricoes[$k["n"]]["tipo"] = $k["ti"];
        $restricoes[$k["n"]]["cols"][] = strtolower($k["col"]);
    }
    foreach ($restricoes as $nome => $r) {
        if ($r["tipo"] === "PRIMARY KEY") {
            $x["chave_primaria"] = $r["cols"];
        } elseif ($r["tipo"] === "UNIQUE") {
            $x["unicas"][] = $r["cols"];
        }
    }
    foreach (linhas("SELECT TRIM(c.RDB\$CONSTRAINT_NAME) AS n, TRIM(r.RDB\$DELETE_RULE) AS d, TRIM(u.RDB\$RELATION_NAME) AS rt, TRIM(u.RDB\$INDEX_NAME) AS ri
        FROM RDB\$RELATION_CONSTRAINTS c JOIN RDB\$REF_CONSTRAINTS r ON r.RDB\$CONSTRAINT_NAME = c.RDB\$CONSTRAINT_NAME
        JOIN RDB\$RELATION_CONSTRAINTS u ON u.RDB\$CONSTRAINT_NAME = r.RDB\$CONST_NAME_UQ
        WHERE c.RDB\$RELATION_NAME = ? AND c.RDB\$CONSTRAINT_TYPE = 'FOREIGN KEY'", [$T]) as $f) {
        $rc = array_map("strtolower", array_column(linhas("SELECT TRIM(s.RDB\$FIELD_NAME) AS c FROM RDB\$INDEX_SEGMENTS s WHERE s.RDB\$INDEX_NAME = ? ORDER BY s.RDB\$FIELD_POSITION", [$f["ri"]]), "c"));
        $x["chaves_estrangeiras"][] = ["coluna" => implode(",", $restricoes[$f["n"]]["cols"] ?? []), "referencia" => strtolower($f["rt"]) . "." . implode(",", $rc),
            "ao_apagar" => strtoupper((string)$f["d"])];
    }
    usort($x["chaves_estrangeiras"], function ($a, $b) { return strcmp($a["coluna"], $b["coluna"]); });
    $ix = [];
    foreach (linhas("SELECT TRIM(i.RDB\$INDEX_NAME) AS n, i.RDB\$UNIQUE_FLAG AS u, TRIM(s.RDB\$FIELD_NAME) AS col FROM RDB\$INDICES i
        JOIN RDB\$INDEX_SEGMENTS s ON s.RDB\$INDEX_NAME = i.RDB\$INDEX_NAME LEFT JOIN RDB\$RELATION_CONSTRAINTS k ON k.RDB\$INDEX_NAME = i.RDB\$INDEX_NAME
        WHERE i.RDB\$RELATION_NAME = ? AND k.RDB\$CONSTRAINT_NAME IS NULL ORDER BY i.RDB\$INDEX_NAME, s.RDB\$FIELD_POSITION", [$T]) as $i) {
        $ix[$i["n"]]["unico"] = (int)$i["u"] === 1;
        $ix[$i["n"]]["cols"][] = strtolower($i["col"]);
    }
    // os índices de uma expressão (a chave única com colunas que aceitam vazio): as colunas vêm da descrição
    foreach (linhas("SELECT i.RDB\$UNIQUE_FLAG AS u, CAST(i.RDB\$DESCRIPTION AS VARCHAR(2000)) AS d FROM RDB\$INDICES i
        WHERE i.RDB\$RELATION_NAME = ? AND i.RDB\$EXPRESSION_BLR IS NOT NULL ORDER BY i.RDB\$INDEX_NAME", [$T]) as $i) {
        if (preg_match("/^unica:(.+)\$/", (string)$i["d"], $m) === 1) {
            $ix[] = ["unico" => (int)$i["u"] === 1, "cols" => explode(",", $m[1])];
        }
    }
    foreach ($ix as $i) {
        $x[$i["unico"] ? "unicas" : "indices"][] = $i["cols"];
    }
    foreach (linhas("SELECT DISTINCT TRIM(c.RDB\$CONSTRAINT_NAME) AS n, CAST(g.RDB\$TRIGGER_SOURCE AS VARCHAR(8000)) AS q FROM RDB\$RELATION_CONSTRAINTS c
        JOIN RDB\$CHECK_CONSTRAINTS k ON k.RDB\$CONSTRAINT_NAME = c.RDB\$CONSTRAINT_NAME JOIN RDB\$TRIGGERS g ON g.RDB\$TRIGGER_NAME = k.RDB\$TRIGGER_NAME
        WHERE c.RDB\$RELATION_NAME = ? AND c.RDB\$CONSTRAINT_TYPE = 'CHECK' ORDER BY 1", [$T]) as $r) {
        // (a regra sem nome ganha do Firebird um nome INTEG_n: sai sem nome, como nos outros bancos)
        $x["regras"][] = ["nome" => stripos($r["n"], "INTEG_") === 0 ? null : strtolower($r["n"]), "condicao" => trim((string)preg_replace("/^\\s*CHECK\\s*\\((.*)\\)\\s*\$/s", "\$1", (string)$r["q"]))];
    }
    return $x;
}
