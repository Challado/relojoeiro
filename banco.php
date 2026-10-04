<?php
// Relógios 2: o banco. O sistema fala com MySQL/MariaDB, PostgreSQL ou SQLite, cada um pela extensão nativa do PHP
// (mysqli, pgsql, sqlite3), escolhido por DB_TIPO no config.php ("mysql", o padrão; "pgsql"; "sqlite"). O resto do
// código escreve o SQL uma vez só, no dialeto do MySQL, e chama sql(), linhas(), linha(), valor(); daqui para dentro:
//   - a tradução: o "?" vira $1, $2... no Postgres; REPLACE INTO, INSERT IGNORE e NOW() viram o equivalente de cada banco;
//     os NULL vêm primeiro no ORDER BY crescente, como no MySQL; os textos com barra invertida ('a\nb') viram o texto
//     que o MySQL entende; no DDL (a instalação e as migrações): AUTO_INCREMENT, ENUM, TINYINT, DATETIME, os INDEX dentro
//     do CREATE TABLE, o AFTER e o MODIFY COLUMN;
//   - os valores que voltam iguais nos três: inteiro como inteiro, decimal como texto com as casas ("68.00"), data e hora
//     como "2026-09-27 14:05:00", o binário (a foto) como binário;
//   - maiúsculas e acentos: no MySQL, "Relógio" = "relogio" (a collation do banco). Nos outros, as colunas VARCHAR ganham
//     a collation "ci", que faz o mesmo: no Postgres, uma collation ICU não determinística; no SQLite, uma função do PHP.
//     As colunas TEXT (os textos longos: fórmulas, mensagens, registros) ficam na comparação exata.
// O schema.sql (a instalação) e as migrações futuras continuam num arquivo só: o DDL no dialeto do MySQL (a tradução
// cuida do resto) e os dados em SQL comum aos três bancos.

class BancoErro extends RuntimeException
{
    // o banco recusou a gravação por uma regra de integridade (um registro que outro usa, um valor repetido onde não
    // pode): não é o banco fora do ar, é a gravação que estava errada
    public $integridade = false;

    public function __construct($mensagem, $codigo = 0, $anterior = null, $integridade = false)
    {
        parent::__construct((string)$mensagem, (int)$codigo, $anterior);
        $this->integridade = (bool)$integridade;
    }
}

// O erro de cada extensão como BancoErro, dizendo se foi uma regra de integridade: SQLSTATE 23xxx no MySQL e no Postgres,
// o código 19 (SQLITE_CONSTRAINT) no SQLite
function banco_erro($e)
{
    if ($e instanceof BancoErro) {
        return $e;
    }
    if ($e instanceof mysqli_sql_exception) {
        return new BancoErro($e->getMessage(), $e->getCode(), $e, strpos((string)$e->getSqlState(), "23") === 0);
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
        }
        if (!in_array($tipo, ["mysql", "pgsql", "sqlite"], true)) {
            throw new BancoErro("DB_TIPO no config.php tem de ser mysql, pgsql ou sqlite (está \"" . $tipo . "\")");
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
                throw new BancoErro("o PHP não tem a extensão pgsql (no php.ini: extension=pgsql)");
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
                throw new BancoErro("não consegui abrir o banco no PostgreSQL: " . trim((string)($e["message"] ?? "")));
            }
            // as datas no formato do MySQL, e o NOW() no fuso do sistema
            banco_direto("SET DateStyle TO ISO, YMD", $c);
            banco_direto("SET TIME ZONE '" . str_replace("'", "''", date_default_timezone_get()) . "'", $c);
            break;
        case "sqlite":
            if (!class_exists("SQLite3")) {
                throw new BancoErro("o PHP não tem a extensão sqlite3 (no php.ini: extension=sqlite3)");
            }
            $arquivo = defined("DB_ARQUIVO") ? (string)constant("DB_ARQUIVO") : "";
            if ($arquivo === "") {
                throw new BancoErro("com DB_TIPO sqlite, o config.php tem de definir DB_ARQUIVO (o caminho do arquivo do banco)");
            }
            try {
                $c = new SQLite3($arquivo);
            } catch (Exception $e) {
                throw new BancoErro("não consegui abrir o banco SQLite em " . $arquivo . ": " . $e->getMessage());
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
            return (int)db()->insert_id;
        case "pgsql":
            return (int)valor("SELECT lastval()");
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
    $params = array_values($params);
    $traduzido = sql_traduzir($comando);
    if (count($traduzido) !== 1 || !is_string($traduzido[0])) {
        throw new BancoErro("o comando vira mais de um no " . banco_tipo() . ": use banco_script() para ele");
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
                throw new BancoErro("o PostgreSQL não recebeu o comando: " . pg_last_error($c));
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
                    throw new BancoErro("o PostgreSQL não recebeu o comando: " . pg_last_error($c));
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

function banco_inicio()
{
    banco_direto(banco_tipo() === "mysql" ? "START TRANSACTION" : "BEGIN");
}

function banco_confirma()
{
    banco_direto("COMMIT");
}

function banco_desfaz()
{
    banco_direto("ROLLBACK");
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
        default:
            return banco_tem_tabela($tabela) && (int)valor("SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?", [$tabela, $coluna]) > 0;
    }
}

function banco_tabelas()
{
    switch (banco_tipo()) {
        case "mysql":
            return array_column(linhas("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()"), "t");
        case "pgsql":
            return array_column(linhas("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = current_schema()"), "t");
        default:
            return array_column(linhas("SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), "t");
    }
}

// Os comandos de um arquivo SQL (o schema.sql, uma migração), um a um, cada um traduzido para o banco. Os comentários
// saem; os comandos se separam pelo ";" fora dos textos. Depois, no Postgres, os contadores dos ids andam até o maior id
// gravado (o arquivo grava ids explícitos). Devolve quantos comandos rodou.
function banco_script($texto)
{
    $n = 0;
    foreach (sql_comandos($texto) as $comando) {
        foreach (sql_traduzir($comando) as $parte) {
            if (is_array($parte)) {
                sqlite_modificar_coluna($parte[1], $parte[2]);
            } else {
                banco_direto($parte);
            }
        }
        // o id gravado explícito: no MySQL o próximo id passa dele sozinho; no Postgres, logo em seguida, aqui
        if (banco_tipo() === "pgsql" && preg_match("/^\\s*INSERT\\s+(IGNORE\\s+)?INTO\\s+`?(\\w+)`?\\s*\\(\\s*`?id`?\\s*[,)]/i", $comando, $m) === 1) {
            banco_ajustar_id($m[2]);
        }
        $n++;
    }
    if (banco_tipo() === "sqlite") {
        banco_sqlite_tipos(true);
    }
    banco_ajustar_ids();
    return $n;
}

// Postgres: o próximo id de cada tabela depois do maior gravado (depois de gravar ids explícitos). Nos outros é sozinho.
function banco_ajustar_ids()
{
    if (banco_tipo() !== "pgsql") {
        return;
    }
    foreach (linhas("SELECT table_name AS t FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'id' AND is_identity = 'YES'") as $t) {
        banco_ajustar_id($t["t"]);
    }
}

function banco_ajustar_id($tabela)
{
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

// SQLite não altera uma coluna: a tabela é refeita com a coluna nova, os dados copiados, e os índices recriados
function sqlite_modificar_coluna($tabela, $definicao)
{
    $criar = (string)valor("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$tabela]);
    $indices = array_column(linhas("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$tabela]), "sql");
    $abre = strpos($criar, "(");
    $fecha = strrpos($criar, ")");
    $partes = sql_dividir(substr($criar, $abre + 1, $fecha - $abre - 1));
    $coluna = strtolower(preg_split("/\\s+/", trim($definicao))[0]);
    $achou = false;
    foreach ($partes as $i => $p) {
        if (strtolower(trim((string)preg_split("/\\s+/", trim($p))[0], "\"`")) === $coluna) {
            $partes[$i] = $definicao;
            $achou = true;
        }
    }
    if (!$achou) {
        throw new BancoErro("MODIFY COLUMN: a tabela " . $tabela . " não tem a coluna " . $coluna);
    }
    $novo = $tabela . "__novo";
    banco_direto("PRAGMA foreign_keys = OFF");
    banco_direto("BEGIN");
    banco_direto("CREATE TABLE " . $novo . " (" . implode(", ", $partes) . ")");
    banco_direto("INSERT INTO " . $novo . " SELECT * FROM " . $tabela);
    banco_direto("DROP TABLE " . $tabela);
    banco_direto("ALTER TABLE " . $novo . " RENAME TO " . $tabela);
    foreach ($indices as $ix) {
        banco_direto($ix);
    }
    banco_direto("COMMIT");
    banco_direto("PRAGMA foreign_keys = ON");
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
                $s .= $banco === "mysql" ? "`" . $p[1] . "`" : "\"" . $p[1] . "\"";
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
    } else {
        $saida = [sql_texto(sql_dml($p, $banco), $banco)];
    }
    if (count($cache) > 2000) {
        $cache = [];
    }
    return $cache[$comando] = $saida;
}

// O DML: INSERT IGNORE, REPLACE INTO, NOW() e a ordem dos NULL
function sql_dml($p, $banco)
{
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
    for ($i = 0; $i < $n; $i++) {
        $x = $p[$i];
        // INSERT IGNORE INTO: SQLite INSERT OR IGNORE; Postgres ON CONFLICT DO NOTHING no fim
        if ($palavra($i, "INSERT") && $palavra($prox($i), "IGNORE")) {
            $j = $prox($i);
            $saida[] = ["w", "INSERT"];
            if ($banco === "sqlite") {
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
            $saida[] = ["w", $banco === "pgsql" ? "IS NOT DISTINCT FROM" : "IS"];
            $i += 2;
            continue;
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
    if ($fim_insert !== null) {
        while (count($saida) > 0 && ($saida[count($saida) - 1][0] === "e" || $saida[count($saida) - 1][1] === ";")) {
            array_pop($saida);
        }
        $saida[] = ["w", $fim_insert];
    }
    return $saida;
}

// As colunas da chave primária de uma tabela no Postgres (para o REPLACE INTO)
function banco_chave_primaria($tabela)
{
    static $cache = [];
    if (!isset($cache[$tabela])) {
        $cache[$tabela] = array_column(linhas("SELECT a.attname AS c FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = to_regclass(?) AND i.indisprimary ORDER BY array_position(i.indkey, a.attnum)", [$tabela]), "c");
        if (count($cache[$tabela]) === 0) {
            throw new BancoErro("REPLACE INTO " . $tabela . ": a tabela não tem chave primária");
        }
    }
    return $cache[$tabela];
}

// As colunas de uma tabela no Postgres, na ordem (para o REPLACE INTO: as que o comando não cita voltam ao padrão)
function banco_colunas($tabela)
{
    static $cache = [];
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
        throw new BancoErro("não sei traduzir a coluna: " . $def);
    }
    [, , $nome, $tipo, , $args, $resto] = $m;
    $tipo = strtoupper($tipo);
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
            throw new BancoErro("não sei traduzir o tipo " . $tipo . " da coluna " . $tabela . "." . $nome);
    }
    // os textos do valor padrão, no formato do banco
    $resto = sql_texto(sql_pedacos($resto), $banco);
    return ["nome" => $nome, "sql" => $nome . " " . $novo . ($resto !== "" ? " " . $resto : "") . $check, "tipo" => $novo, "check" => $check, "resto" => $resto];
}

function sql_criar_tabela($comando, $banco)
{
    if (preg_match("/^\\s*CREATE\\s+TABLE\\s+(IF\\s+NOT\\s+EXISTS\\s+)?`?(\\w+)`?\\s*\\((.*)\\)([^)]*)\$/is", $comando, $m) !== 1) {
        throw new BancoErro("não sei traduzir: " . substr($comando, 0, 80));
    }
    $tabela = $m[2];
    $defs = [];
    $indices = [];
    foreach (sql_dividir($m[3]) as $parte) {
        $parte = trim((string)preg_replace("/\\s+/", " ", $parte));
        if (preg_match("/^(UNIQUE\\s+)?(INDEX|KEY)\\s*(`?\\w+`?\\s*)?\\((.*)\\)\$/i", $parte, $x) === 1) {
            $cols = str_replace("`", "", $x[4]);
            $indices[] = "CREATE " . ($x[1] !== "" ? "UNIQUE " : "") . "INDEX " . $tabela . "_" . preg_replace("/\\W+/", "_", $cols) . "_idx ON " . $tabela . " (" . $cols . ")";
        } elseif (preg_match("/^(PRIMARY\\s+KEY|FOREIGN\\s+KEY|UNIQUE|CONSTRAINT|CHECK)\\b/i", $parte) === 1) {
            $defs[] = sql_texto(sql_pedacos($parte), $banco);
        } else {
            $defs[] = sql_coluna($parte, $banco, $tabela)["sql"];
        }
    }
    return array_merge(["CREATE TABLE " . ($m[1] !== "" ? "IF NOT EXISTS " : "") . $tabela . " (\n    " . implode(",\n    ", $defs) . "\n)"], $indices);
}

function sql_alterar_tabela($comando, $banco)
{
    $comando = trim((string)preg_replace("/\\s+/", " ", $comando));
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
    throw new BancoErro("não sei traduzir: " . substr($comando, 0, 120));
}
