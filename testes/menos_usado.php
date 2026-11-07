<?php
// O menos usado: o critério (a fórmula e o parâmetro Menos usado nas notas), a forma de escolha (o relógio com menos dias de uso no
// total; empate: o mais tempo sem uso) e a escala inteligente que escolhe a forma e os critérios que usa. Base SQLite nova e
// descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-menos-usado-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_uso($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

try {
    exigir_uso(instalar_banco()["ok"], "Não instalou a base descartável.");
    $dono = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_uso($dono["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($dono) { return linha("SELECT * FROM usuario WHERE id = ?", [$dono["usuario_id"]]); }));
    banco_escopo($dono["colecao_id"]);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");

    // ---- o modelo: a fórmula, o parâmetro de critério (peso 0: a nota de antes não muda) e a forma
    exigir_uso((int)valor("SELECT COUNT(*) FROM formula WHERE identificador = 'menos_usado'") === 1, "A fórmula menos_usado não veio no modelo.");
    exigir_uso(in_array("Menos usado", criterios_nomes(), true), "O critério Menos usado não veio no modelo.");
    exigir_uso((int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE nome = 'Menos usado' AND peso = 0") === count(criterios_config(true)),
        "Cada conjunto de critérios devia ter o Menos usado, com peso 0.");
    exigir_uso(isset(formas_de_escolha()["menos_usado"]), "A forma menos_usado não está na lista.");

    // ---- os relógios e o uso: dias de uso no total e o último dia
    $ids = [];
    foreach (["A", "B", "C", "D"] as $nome) {
        $r = op_relogio("salvar", ["nome" => $nome, "no_id" => $grupo, "disponivel" => 1]);
        exigir_uso($r["ok"], "Não criou o relógio " . $nome . ".");
        $ids[$nome] = $r["id"];
    }
    $hoje = hoje_da_colecao();
    $dia = function ($n) use ($hoje) { return $hoje->modify(($n >= 0 ? "+" : "") . $n . " day")->format("Y-m-d"); };
    // A: 5 dias, parado há 25; B: 2 dias, parado há 18; C: 2 dias, parado há 1; D: 8 dias, parado há 2
    $usos = ["A" => [-30, -29, -28, -27, -26], "B" => [-20, -19], "C" => [-2, -1], "D" => [-10, -9, -8, -7, -6, -5, -4, -3]];
    foreach ($usos as $nome => $dias) {
        foreach ($dias as $n) {
            $u = op_lancamento("periodo", ["relogio_id" => $ids[$nome], "tipo" => "pulso", "inicio" => $dia($n) . " 08:00", "fim" => $dia($n) . " 18:00"]);
            exigir_uso($u["ok"], "Não registrou o uso de " . $nome . " em " . $dia($n) . ": " . implode(" ", $u["erros"] ?? []));
        }
    }
    cfg_set("max_sem_uso", "0");
    $rels = [];
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY id") as $r) {
        $rels[(int)$r["id"]] = $r;
    }
    $m_ini = instante($hoje->format("Y-m-d"), cfg("uso_inicio"));
    $quem = function ($forma, $modo = null) use ($hoje, $m_ini, &$rels, &$ids) {
        [$id, $motivo] = escolha_do_dia($modo, $hoje, $m_ini, $rels, $forma, [], "", false);
        return [array_search($id, $ids, true), $motivo];
    };

    // ---- a forma menos usado: vai o de menos dias de uso; empate, o mais tempo parado; o motivo diz
    [$q, $motivo] = $quem("menos_usado");
    exigir_uso($q === "B", "Menos usado devia ser B (2 dias, parado há 18, empatado com C), saiu " . var_export($q, true) . ".");
    exigir_uso(strpos($motivo, "Menos usado") !== false && strpos($motivo, "2 dias de uso") !== false, "O motivo do menos usado: " . $motivo);
    // quem nunca foi usado passa na frente de todos
    $novo = op_relogio("salvar", ["nome" => "E", "no_id" => $grupo, "disponivel" => 1]);
    $rels[(int)$novo["id"]] = linha("SELECT * FROM relogio WHERE id = ?", [$novo["id"]]);
    $ids["E"] = $novo["id"];
    [$q, $motivo] = $quem("menos_usado");
    exigir_uso($q === "E" && strpos($motivo, "nenhum dia de uso") !== false, "O nunca usado devia sair primeiro: " . var_export($q, true) . " / " . $motivo);
    // as outras formas seguem como eram (a fila: o mais tempo parado; o E nunca usado, parado há 9999)
    [$q] = $quem("fifo");
    exigir_uso($q === "E", "A fila devia pegar o nunca usado.");
    unset($rels[(int)$ids["E"]]);
    [$q] = $quem("fifo");
    exigir_uso($q === "A", "A fila devia pegar o parado há 25 dias.");
    [$q] = $quem("menos_usado");
    exigir_uso($q === "B", "Sem o E, o menos usado volta a ser B.");

    // ---- o critério Menos usado na nota: pela média da coleção (com o E, que nunca foi usado: média 3,4 dias; A, com 5, é 147% → 15; B, com 2, é 59% → 80)
    $r_a = $rels[(int)$ids["A"]];
    $r_b = $rels[(int)$ids["B"]];
    exigir_uso(round(nota_do_relogio($r_a, $m_ini, ["Menos usado"])["nota"]) === 15.0, "A nota só com o Menos usado de A devia ser 15, é " . nota_do_relogio($r_a, $m_ini, ["Menos usado"])["nota"] . ".");
    exigir_uso(round(nota_do_relogio($r_b, $m_ini, ["Menos usado"])["nota"]) === 80.0, "A nota só com o Menos usado de B devia ser 80.");
    // com peso 0 ele não muda a nota de antes: todos os critérios dão o mesmo que sem ele
    $todos = nota_do_relogio($r_b, $m_ini)["nota"];
    $sem = nota_do_relogio($r_b, $m_ini, array_values(array_diff(criterios_nomes(), ["Menos usado"])))["nota"];
    exigir_uso(abs($todos - $sem) < 0.01, "O Menos usado, com peso 0, mudou a nota (" . $todos . " contra " . $sem . ").");
    exigir_uso(nota_do_relogio($r_b, $m_ini, ["Nao existe"])["nota"] === 50.0, "Sem nenhum critério que valha, a nota é 50.");

    // ---- a escala escolhe a forma e os critérios: validações
    $base = ["nome" => "Escala do menos usado", "tipo" => "escala", "escala_dias" => 7, "blocos" => [["nome" => "Todo dia", "no_id" => "", "dias" => [1, 2, 3, 4, 5, 6, 7], "um_por" => "dia"]]];
    $sem_escolha = op_modos("salvar", $base);
    exigir_uso($sem_escolha["ok"], "Não criou a escala: " . implode(" ", $sem_escolha["erros"] ?? []));
    exigir_uso(valor("SELECT selecao FROM modo WHERE id = ?", [$sem_escolha["id"]]) === "inteligente", "A escala sem escolha devia ficar com a maior nota.");
    exigir_uso(!op_modos("salvar", array_merge($base, ["id" => $sem_escolha["id"], "selecao" => "aleatorio"]))["ok"], "A escala aceitou a forma aleatório.");
    exigir_uso(!op_modos("salvar", array_merge($base, ["id" => $sem_escolha["id"], "selecao" => "nao_existe"]))["ok"], "Aceitou uma forma que não existe.");
    exigir_uso(!op_modos("salvar", array_merge($base, ["id" => $sem_escolha["id"], "criterios" => ["Nao existe"]]))["ok"], "Aceitou um critério que não existe.");
    $mu = op_modos("salvar", array_merge($base, ["id" => $sem_escolha["id"], "selecao" => "menos_usado", "criterios" => ["Menos usado", "Tempo sem uso", "Menos usado"]]));
    exigir_uso($mu["ok"], "Não gravou a escala com o menos usado: " . implode(" ", $mu["erros"] ?? []));
    exigir_uso(valor("SELECT selecao FROM modo WHERE id = ?", [$mu["id"]]) === "menos_usado", "A escala não guardou a forma.");
    $guardados = array_column(linhas("SELECT nome FROM modo_criterio WHERE modo_id = ? ORDER BY nome", [$mu["id"]]), "nome");
    exigir_uso($guardados === ["Menos usado", "Tempo sem uso"], "Os critérios da escala: " . implode(", ", $guardados));
    exigir_uso(strpos(modo_roteiro_texto(linha("SELECT * FROM modo WHERE id = ?", [$mu["id"]])), "dias_de_uso") !== false, "O roteiro da escala devia ser o do menos usado.");
    // sem o campo, os critérios ficam; com a lista vazia, voltam a valer todos
    exigir_uso(op_modos("salvar", array_merge($base, ["id" => $mu["id"], "selecao" => "menos_usado"]))["ok"] && (int)valor("SELECT COUNT(*) FROM modo_criterio WHERE modo_id = ?", [$mu["id"]]) === 2, "Salvar sem criterios apagou os critérios.");
    exigir_uso(op_modos("salvar", array_merge($base, ["id" => $mu["id"], "selecao" => "menos_usado", "criterios" => []]))["ok"] && (int)valor("SELECT COUNT(*) FROM modo_criterio WHERE modo_id = ?", [$mu["id"]]) === 0, "A lista vazia não limpou os critérios.");

    // ---- os critérios do modo mudam a escolha: Tempo sem uso pega o parado há mais tempo (A), Menos usado pega o de menos uso (B)
    $modo_t = array_merge(linha("SELECT * FROM modo WHERE id = ?", [$mu["id"]]), ["selecao" => "inteligente"]);
    sql("UPDATE modo SET selecao = 'inteligente' WHERE id = ?", [$mu["id"]]);
    foreach ([["Tempo sem uso", "A"], ["Menos usado", "B"]] as [$criterio, $esperado]) {
        exigir_uso(op_modos("salvar", array_merge($base, ["id" => $mu["id"], "selecao" => "inteligente", "criterios" => [$criterio]]))["ok"], "Não gravou o critério " . $criterio . ".");
        $GLOBALS["FORMULAS_VERSAO"]++;
        [$q] = $quem("inteligente", linha("SELECT * FROM modo WHERE id = ?", [$mu["id"]]));
        exigir_uso($q === $esperado, "Só com o critério " . $criterio . " devia sair " . $esperado . ", saiu " . var_export($q, true) . ".");
    }

    // ---- a escala gerada com o menos usado: o primeiro dia é o de menos uso, e depois o de menos uso entre os outros
    exigir_uso(op_modos("salvar", array_merge($base, ["id" => $mu["id"], "selecao" => "menos_usado", "criterios" => []]))["ok"], "Não voltou ao menos usado.");
    modo_ativar($mu["id"]);
    sql("DELETE FROM plano");
    cfg_set("escala_fim", "");
    garantir_plano($hoje);
    $plano = linhas("SELECT data, relogio_id FROM plano WHERE data >= ? ORDER BY data", [$hoje->format("Y-m-d")]);
    exigir_uso(count($plano) >= 7, "A escala não montou os dias: " . count($plano));
    $primeiros = array_slice(array_map(function ($x) use ($ids) { return array_search((int)$x["relogio_id"], $ids, true); }, $plano), 0, 7);
    // o E (nunca usado) abre a escala, e fica os dias que a bateria dá (dias_seguidos); depois vem o de menos uso entre os outros (B)
    exigir_uso($primeiros[0] === "E" && count(array_unique($primeiros)) >= 2 && end($primeiros) === "B", "A escala do menos usado devia começar pelo E e seguir pelo B: " . implode(",", $primeiros));

    // ---- a migração em quem já tinha as árvores: a fórmula e o critério voltam, sem repetir e com peso 0
    sql("DELETE FROM formula WHERE identificador = 'menos_usado'");
    sql("DELETE FROM criterio_parametro WHERE nome = 'Menos usado'");
    exigir_uso(!in_array("Menos usado", criterios_nomes(true), true), "O critério não saiu.");
    sem_escopo(function () { migracao_v59_dados(false); migracao_v59_dados(false); });
    exigir_uso((int)valor("SELECT COUNT(*) FROM formula WHERE identificador = 'menos_usado'") === 1, "A migração não devolveu a fórmula, ou repetiu.");
    exigir_uso((int)valor("SELECT COUNT(*) FROM criterio_parametro WHERE nome = 'Menos usado' AND peso = 0") === count(criterios_config(true)), "A migração não pôs o critério em cada conjunto, com peso 0, uma vez só.");
    exigir_uso((int)valor("SELECT COUNT(*) FROM criterio_faixa f JOIN criterio_sub s ON s.id = f.sub_id WHERE s.variavel = 'menos_usado'") === 6 * count(criterios_config(true)), "As faixas do critério não voltaram.");

    echo "Menos usado: o critério, a forma, a escala que escolhe a forma e os critérios, os motivos e a migração conferem.\n";
    $codigo = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n");
    $codigo = 1;
}
@exec("rm -rf " . escapeshellarg($pasta));
exit($codigo);
