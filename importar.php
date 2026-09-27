<?php
// Traz os dados do sistema antigo para este banco: php importar.php <banco antigo> [--substituir]
// Os dois bancos no mesmo servidor, com o usuário do config.php. Traz: a árvore (os grupos, com os mesmos números), os
// relógios, as fotos, os usuários, os valores dos campos (cada coluna do cadastro antigo no campo equivalente, só nos
// relógios em que o campo vale) e o histórico, como lançamentos (sessões no pulso, no winder e no sol; leituras de
// carga; cordas; trocas de pilha; revisões; as marcações antigas sem duração). O plano, a configuração e os critérios
// vêm nas etapas deles. Com --substituir, apaga antes os relógios e o histórico deste banco.
require_once __DIR__ . "/lib.php";

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit;
}
$banco = $argv[1] ?? "";
if (preg_match("/^[A-Za-z0-9_]+\$/", $banco) !== 1) {
    fwrite(STDERR, "Uso: php importar.php <banco antigo> [--substituir]\n");
    exit(1);
}
try {
    $antigo = new mysqli(DB_HOST, DB_USUARIO, DB_SENHA, $banco);
    $antigo->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "Não consegui abrir o banco " . $banco . " com o usuário do config.php (" . DB_USUARIO . "): " . $e->getMessage() . "\n"
        . "O usuário precisa poder ler o banco antigo. No mysql, como root: GRANT SELECT ON " . $banco . ".* TO '" . DB_USUARIO . "'@'localhost';\n");
    exit(1);
}
$ler = function ($comando) use ($antigo) {
    return $antigo->query($comando)->fetch_all(MYSQLI_ASSOC);
};
if ((int)valor("SELECT COUNT(*) FROM relogio") > 0 && !in_array("--substituir", $argv, true)) {
    fwrite(STDERR, "Este banco já tem relógios. Para apagar e importar de novo: php importar.php " . $banco . " --substituir\n");
    exit(1);
}
$conta = [];
db()->begin_transaction();
sql("DELETE FROM lancamento");
sql("DELETE FROM campo_valor");
sql("DELETE FROM foto");
sql("DELETE FROM relogio");

// a árvore: cada grupo com o mesmo número; primeiro sem o de cima, depois o de cima (a ordem dos números não importa)
foreach ($ler("SELECT * FROM grupo") as $g) {
    if (valor("SELECT id FROM no WHERE id = ?", [(int)$g["id"]]) !== null) {
        sql("UPDATE no SET nome = ?, ordem = ? WHERE id = ?", [$g["nome"], (int)$g["ordem"], (int)$g["id"]]);
    } else {
        sql("INSERT INTO no (id, pai_id, nome, ordem) VALUES (?, NULL, ?, ?)", [(int)$g["id"], $g["nome"], (int)$g["ordem"]]);
    }
}
foreach ($ler("SELECT * FROM grupo") as $g) {
    sql("UPDATE no SET pai_id = ? WHERE id = ?", [$g["pai_id"] === null ? null : (int)$g["pai_id"], (int)$g["id"]]);
}
nos_todos(true);
$conta["pontos da árvore"] = count($ler("SELECT id FROM grupo"));

// os relógios, com os mesmos números
foreach ($ler("SELECT * FROM relogio ORDER BY id") as $r) {
    sql("INSERT INTO relogio (id, nome, no_id, disponivel, criado) VALUES (?, ?, ?, ?, NOW())",
        [(int)$r["id"], $r["nome"], $r["grupo_id"] === null ? null : (int)$r["grupo_id"], (int)$r["disponivel"]]);
}
$conta["relógios"] = count($ler("SELECT id FROM relogio"));

foreach ($ler("SELECT * FROM foto") as $f) {
    sql("INSERT INTO foto (relogio_id, tipo, dados, atualizado) VALUES (?, ?, ?, ?)", [(int)$f["relogio_id"], $f["tipo"], $f["dados"], $f["atualizado"]]);
}
$conta["fotos"] = count($ler("SELECT relogio_id FROM foto"));

foreach ($ler("SELECT * FROM usuario") as $u) {
    sql("REPLACE INTO usuario (login, senha_hash, criado) VALUES (?, ?, ?)", [$u["login"], $u["senha_hash"], $u["criado"]]);
}
$conta["usuários"] = count($ler("SELECT id FROM usuario"));

// os valores: coluna antiga => campo (o identificador); só nos relógios em que o campo vale, e só valores que servem
$colunas = ["obs" => "observacao", "preferencia" => "preferencia", "data_compra" => "data_compra", "valor_compra" => "valor_compra", "loja" => "loja",
    "garantia_ate" => "garantia_ate", "autonomia_dias" => "autonomia_dias", "decaimento_uso" => "decaimento_uso", "decaimento_repouso" => "decaimento_repouso",
    "data_revisao" => "data_revisao", "intervalo_revisao_meses" => "intervalo_revisao_meses", "reserva_horas" => "reserva_horas", "corda_manual" => "corda_manual",
    "carga_pulso_horas" => "carga_pulso_horas", "carga_pulso_reserva" => "carga_pulso_reserva", "carga_winder_horas" => "carga_winder_horas",
    "carga_winder_reserva" => "carga_winder_reserva", "reserva_dias" => "reserva_dias", "carga_sol_horas" => "carga_sol_horas", "data_pilha" => "data_pilha",
    "vida_pilha_meses" => "vida_pilha_meses"];
$conta["valores"] = 0;
$conta["valores de campo que não vale para o relógio (não trazidos)"] = 0;
$conta["valores que não servem no campo (não trazidos)"] = 0;
$existem = array_column($ler("SHOW COLUMNS FROM relogio"), "Field");
foreach ($ler("SELECT * FROM relogio") as $r) {
    $campos = campos_do_relogio(["no_id" => $r["grupo_id"]]);
    foreach ($colunas as $col => $ident) {
        if (in_array($col, $existem, true) && $r[$col] !== null && trim((string)$r[$col]) !== "") {
            if (!isset($campos[$ident])) {
                $conta["valores de campo que não vale para o relógio (não trazidos)"]++;
            } else {
                $v = valor_para_campo($campos[$ident], $r[$col]);
                if ($v[1] !== "" || $v[0] === null) {
                    $conta["valores que não servem no campo (não trazidos)"]++;
                } else {
                    sql("INSERT INTO campo_valor (relogio_id, campo_id, valor) VALUES (?, ?, ?)", [(int)$r["id"], (int)$campos[$ident]["id"], $v[0]]);
                    $conta["valores"]++;
                }
            }
        }
    }
}

// o histórico: marcações (evento) e sessões (uso_periodo), como lançamentos
$tipos = [];
foreach (lancamento_tipos(true) as $ident => $t) {
    $tipos[$ident] = (int)$t["id"];
}
// os horários da configuração antiga: a sessão esquecida aberta no pulso fecha no fim do horário de uso; no sol, no fim da tarde
$cfg_antiga = [];
foreach ($ler("SELECT chave, valor FROM config") as $c) {
    $cfg_antiga[$c["chave"]] = $c["valor"];
}
foreach (["pulso" => "uso_fim", "sol" => "sol_fim"] as $ident => $chave) {
    if (preg_match("/^([01][0-9]|2[0-3]):[0-5][0-9]\$/", $cfg_antiga[$chave] ?? "") === 1) {
        sql("UPDATE lancamento_tipo SET fecha_as = ? WHERE identificador = ?", [$cfg_antiga[$chave], $ident]);
    }
}
// os dias com algum período no pulso (de qualquer relógio): neles, a marcação "relógio do dia" não conta (o uso vem dos períodos)
$dias_controlados = [];
foreach ($ler("SELECT DISTINCT DATE(inicio) AS dia FROM uso_periodo WHERE tipo IN ('rodizio', 'pulso')") as $x) {
    $dias_controlados[$x["dia"]] = true;
}
$de_evento = ["carga" => "carga", "corda" => "corda", "pilha" => "pilha", "revisao" => "revisao", "sol" => "sol_antigo", "winder" => "winder_antigo", "pulso" => "pulso_antigo"];
$conta["lançamentos"] = 0;
$conta["marcações \"relógio do dia\" em dia sem período (viraram uma sessão no pulso, no horário de uso)"] = 0;
$conta["marcações \"relógio do dia\" em dia com período (não trazidas: o uso vem dos períodos)"] = 0;
foreach ($ler("SELECT * FROM evento ORDER BY quando") as $e) {
    if (isset($de_evento[$e["tipo"]]) && isset($tipos[$de_evento[$e["tipo"]]])) {
        sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, NULL, ?, 'importado', NOW())",
            [(int)$e["relogio_id"], $tipos[$de_evento[$e["tipo"]]], $e["quando"], $e["valor"] === null ? null : (float)$e["valor"]]);
        $conta["lançamentos"]++;
    } elseif ($e["tipo"] === "uso" && !isset($dias_controlados[substr($e["quando"], 0, 10)])) {
        // como no sistema antigo: o dia inteiro no pulso, do início ao fim do horário de uso
        $dia = substr($e["quando"], 0, 10);
        sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, 'importado', NOW())",
            [(int)$e["relogio_id"], $tipos["pulso"], $dia . " " . ($cfg_antiga["uso_inicio"] ?? "07:00") . ":00", $dia . " " . ($cfg_antiga["uso_fim"] ?? "22:00") . ":00"]);
        $dias_controlados[$dia] = true;
        $conta["marcações \"relógio do dia\" em dia sem período (viraram uma sessão no pulso, no horário de uso)"]++;
        $conta["lançamentos"]++;
    } else {
        $conta["marcações \"relógio do dia\" em dia com período (não trazidas: o uso vem dos períodos)"]++;
    }
}
$de_periodo = ["rodizio" => ["pulso", "rodizio"], "pulso" => ["pulso", "manual"], "winder" => ["winder", "manual"], "sol" => ["sol", "manual"]];
foreach ($ler("SELECT * FROM uso_periodo ORDER BY inicio") as $p) {
    $tipo = $de_periodo[$p["tipo"] ?? "rodizio"] ?? ["pulso", "manual"];
    sql("INSERT INTO lancamento (relogio_id, tipo_id, inicio, fim, valor, origem, criado) VALUES (?, ?, ?, ?, NULL, ?, NOW())",
        [(int)$p["relogio_id"], $tipos[$tipo[0]], $p["inicio"], $p["fim"], $tipo[1]]);
    $conta["lançamentos"]++;
}
db()->commit();

echo "Importado de " . $banco . ":\n";
foreach ($conta as $o_que => $n) {
    echo "  " . $o_que . ": " . $n . "\n";
}
