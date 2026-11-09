<?php
// O construtor de colunas (Cadastros › Colunas): criar e editar uma coluna no modo guiado (fonte, campo e função) e no modo expert
// (a expressão), as recusas, o formato com casas e símbolo da moeda, o catálogo da página Hoje com as colunas cadastradas (a escolha, o
// que aparece de início, a coluna que sai), o valor de cada relógio na linha da tabela (só das colunas que aparecem), a ordem e a
// exclusão. Base SQLite nova e descartável.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-construtor-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
putenv("RELOGIOS_DB=sqlite");
require dirname(__DIR__) . "/lib.php";

function exigir_cons($ok, $mensagem)
{
    if (!$ok) {
        throw new RuntimeException($mensagem);
    }
}

try {
    exigir_cons(instalar_banco()["ok"], "Não instalou a base descartável.");
    $master = op_usuarios("salvar", ["login" => "dono", "senha" => "Senha@123", "senha_confirmacao" => "Senha@123"], null);
    exigir_cons($master["ok"], "Não criou o master.");
    usuario_definir(sem_escopo(function () use ($master) { return linha("SELECT * FROM usuario WHERE id = ?", [$master["usuario_id"]]); }));
    banco_escopo($master["colecao_id"]);
    $grupo = (int)valor("SELECT id FROM no WHERE nome = 'Smartwatch'");
    $ra = op_relogio("salvar", ["nome" => "Um", "no_id" => $grupo, "disponivel" => 1]);
    $rb = op_relogio("salvar", ["nome" => "Dois", "no_id" => $grupo, "disponivel" => 1]);
    exigir_cons($ra["ok"] && $rb["ok"], "Não criou os relógios.");
    $a = (int)$ra["id"];
    $b = (int)$rb["id"];
    foreach ([["2026-02-10 10:00:00", "4.5"], ["2026-03-05 09:00:00", "-2.5"], ["2026-01-20 08:00:00", "1.0"]] as [$q, $marcha]) {
        sql("INSERT INTO timegrapher (relogio_id, quando, marcha, origem, criado) VALUES (?, ?, ?, 'manual', NOW())", [$a, $q, $marcha]);
    }
    exigir_cons(colunas_tabela_existe(), "A tabela coluna devia existir na base nova.");
    exigir_cons(count(colunas_cadastradas()) === 0 && colunas_catalogo("hoje") === array_keys(TOTAL_COLUNAS) && colunas_padrao("hoje") === array_keys(TOTAL_COLUNAS), "Sem colunas montadas o catálogo é o das 14 de sempre, criadas no cadastro pelo modelo.");

    // ---- o modo guiado: a fonte, o campo e a função viram a expressão; o formato combina com o que ela dá
    $g = op_colunas("salvar", ["id" => 0, "nome" => "Marcha média", "modo" => "guiado", "tabela" => "timegrapher", "campo" => "marcha", "funcao" => "media"]);
    exigir_cons($g["ok"], "Não criou a coluna guiada: " . implode("; ", $g["erros"] ?? []));
    $col = colunas_cadastradas()[0];
    exigir_cons($col["identificador"] === "marcha_media" && $col["chave"] === "c_marcha_media" && $col["expressao"] === "fonte_timegrapher_marcha_media" && $col["formato"] === "decimal"
        && $col["modo"] === "guiado" && $col["padrao"] === false, "A coluna guiada devia sair com a expressão e o formato da fonte: " . json_encode($col));
    $n = op_colunas("salvar", ["id" => 0, "nome" => "Medições", "modo" => "guiado", "tabela" => "timegrapher", "campo" => "id", "funcao" => "conta", "padrao" => "1"]);
    exigir_cons($n["ok"] && colunas_cadastradas()[1]["formato"] === "inteiro" && colunas_cadastradas()[1]["padrao"] === true, "A contagem devia ser inteira e aparecer de início.");
    $u = op_colunas("salvar", ["id" => 0, "nome" => "Quanto tempo usei", "modo" => "guiado", "tabela" => "relogio", "campo" => "tempo_de_uso"]);
    exigir_cons($u["ok"] && colunas_cadastradas()[2]["formato"] === "duracao" && colunas_cadastradas()[2]["expressao"] === "tempo_de_uso", "A coluna da tabela dos relógios devia ser Tempo.");

    // ---- o modo expert: a expressão escrita, com o formato, as casas e o símbolo
    $e = op_colunas("salvar", ["id" => 0, "nome" => "Custo da medição", "modo" => "expert", "expressao" => "fonte_timegrapher_id_conta * 10", "formato" => "moeda", "casas" => "1", "simbolo" => "US$"]);
    exigir_cons($e["ok"], "Não criou a coluna expert: " . implode("; ", $e["erros"] ?? []));
    $ce = colunas_cadastradas()[3];
    exigir_cons($ce["tabela"] === null && $ce["modo"] === "expert" && $ce["casas"] === 1 && $ce["simbolo"] === "US$", "A expert não guarda fonte: " . json_encode($ce));

    // ---- as recusas
    $recusa = function ($pedido, $parte, $rotulo) {
        $r = op_colunas("salvar", $pedido);
        exigir_cons(!$r["ok"] && strpos(implode(" ", $r["erros"]), $parte) !== false, $rotulo . ": devia ser recusado (" . $parte . "): " . json_encode($r["erros"] ?? []));
    };
    $recusa(["id" => 0, "nome" => "", "modo" => "guiado", "tabela" => "timegrapher", "campo" => "marcha", "funcao" => "soma"], "Dê um nome", "nome vazio");
    $recusa(["id" => 0, "nome" => "marcha MÉDIA", "modo" => "guiado", "tabela" => "timegrapher", "campo" => "marcha", "funcao" => "soma"], "Já existe uma coluna com o nome", "nome repetido");
    $recusa(["id" => 0, "nome" => "X1", "modo" => "guiado", "tabela" => "nao_existe", "campo" => "marcha", "funcao" => "soma"], "Tabela", "tabela desconhecida");
    $recusa(["id" => 0, "nome" => "X2", "modo" => "guiado", "tabela" => "timegrapher", "campo" => "nao_existe", "funcao" => "soma"], "não está na tabela", "campo desconhecido");
    $recusa(["id" => 0, "nome" => "X3", "modo" => "guiado", "tabela" => "servico", "campo" => "data", "funcao" => "soma"], "Função", "soma de uma data");
    $recusa(["id" => 0, "nome" => "X4", "modo" => "guiado", "tabela" => "relogio", "campo" => "nao_existe"], "não é uma coluna da tabela", "coluna da tabela desconhecida");
    $recusa(["id" => 0, "nome" => "X5", "modo" => "guiado", "tabela" => "timegrapher", "campo" => "marcha", "funcao" => "soma", "casas" => "7"], "Casas decimais", "casas demais");
    $recusa(["id" => 0, "nome" => "X6", "modo" => "guiado", "tabela" => "timegrapher", "campo" => "marcha", "funcao" => "soma", "formato" => "inteiro", "simbolo" => "R$"], "símbolo só vale", "símbolo fora da moeda");
    $recusa(["id" => 0, "nome" => "X7", "modo" => "guiado", "tabela" => "timegrapher", "campo" => "marcha", "funcao" => "soma", "formato" => "bonito"], "Formato", "formato desconhecido");
    $recusa(["id" => 0, "nome" => "X8", "modo" => "estranho"], "Modo", "modo desconhecido");
    $recusa(["id" => 0, "nome" => "X9", "modo" => "expert", "expressao" => "variavel_que_nao_existe + 1"], "variável desconhecida", "variável desconhecida");
    $recusa(["id" => 0, "nome" => "X10", "modo" => "expert", "expressao" => "1 +"], "erro de escrita", "erro de escrita");
    $recusa(["id" => 0, "nome" => "X11", "modo" => "expert", "expressao" => "SOMA(carga)"], "função desconhecida", "função de total numa conta de um relógio");
    $recusa(["id" => 0, "nome" => "X12", "identificador" => "Maiusculas", "modo" => "expert", "expressao" => "1"], "Identificador", "identificador inválido");
    $recusa(["id" => 0, "nome" => "Outro nome", "identificador" => "marcha_media", "modo" => "expert", "expressao" => "1"], "identificador", "identificador repetido");
    $recusa(["id" => $col["id"], "identificador" => "outro"], "não muda", "identificador mudando");
    exigir_cons(count(colunas_cadastradas()) === 4, "As recusas não podem gravar nada.");
    exigir_cons(op_colunas("salvar", ["id" => 99999, "nome" => "Z"])["ok"] === false, "Coluna que não existe.");

    // ---- editar: só muda o que vem; o identificador e a chave ficam
    $ed = op_colunas("salvar", ["id" => $col["id"], "nome" => "Marcha (média)", "funcao" => "maior"]);
    exigir_cons($ed["ok"], "Não editou: " . implode("; ", $ed["erros"] ?? []));
    $col2 = array_values(array_filter(colunas_cadastradas(), function ($c) use ($col) { return $c["id"] === $col["id"]; }))[0];
    exigir_cons($col2["chave"] === "c_marcha_media" && $col2["nome"] === "Marcha (média)" && $col2["expressao"] === "fonte_timegrapher_marcha_maior" && $col2["formato"] === "decimal", "A edição: " . json_encode($col2));

    // ---- o texto: as casas e o símbolo
    exigir_cons(total_texto(1234.5, "decimal", ["casas" => 3]) === "1.234,500" && total_texto(1234.5, "decimal") === "1.234,50", "decimal com casas");
    exigir_cons(total_texto(1234.5, "moeda", ["casas" => 0, "simbolo" => "€"]) === "€ 1.235" && total_texto(1234.5, "moeda") === "R$ 1.234,50", "moeda com símbolo e casas");
    exigir_cons(total_texto(12.345, "porcentagem", ["casas" => 1]) === "12,3%" && total_texto(12.345, "porcentagem") === "12%", "porcentagem com casas");
    exigir_cons(total_texto(3.14159, "numero", ["casas" => 2]) === "3,14" && total_texto(7, "inteiro", ["casas" => 3]) === "7", "número com casas; inteiro ignora");

    // ---- o catálogo da Hoje: as de sempre e as cadastradas; de início, só as marcadas "aparece sem ninguém escolher"
    $chaves = array_column(colunas_cadastradas(), "chave");
    exigir_cons(colunas_catalogo("hoje") === array_merge(array_keys(TOTAL_COLUNAS), $chaves), "O catálogo = as de sempre + as cadastradas, na ordem do cadastro.");
    exigir_cons(colunas_padrao("hoje") === array_merge(array_keys(TOTAL_COLUNAS), ["c_medicoes"]) && colunas_efetivas("hoje") === colunas_padrao("hoje"), "De início: as de sempre e as marcadas.");
    exigir_cons(colunas_titulos("hoje")["c_medicoes"] === "Medições", "O título da cadastrada é o nome dela.");
    exigir_cons(colunas_conferir("hoje", "nome,c_marcha_media")[0] === null && colunas_conferir("hoje", "nome,c_nao_existe")[0] !== null, "O conferidor aceita a cadastrada e recusa a que não existe.");
    $r = op_config("salvar", ["colunas_hoje" => "nome,c_marcha_media,c_custo_da_medicao,uso"]);
    exigir_cons($r["ok"] && colunas_efetivas("hoje") === ["nome", "c_marcha_media", "c_custo_da_medicao", "uso"], "A escolha com colunas cadastradas: " . implode("; ", $r["erros"] ?? []));
    $lista = colunas_da_pagina("hoje");
    exigir_cons(count($lista) === count(TOTAL_COLUNAS) + 4 && $lista[1]["chave"] === "c_marcha_media" && $lista[1]["cadastrada"] === true && $lista[1]["visivel"] && $lista[0]["cadastrada"] === false, "A lista da API: as que valem primeiro, com a marca de cadastrada.");

    // ---- o valor na linha da tabela: só das cadastradas que aparecem
    $rel = linha("SELECT * FROM relogio WHERE id = ?", [$a]);
    $l = tabela_linha($rel, time());
    exigir_cons(array_keys($l["colunas_extras"]) === ["c_marcha_media", "c_custo_da_medicao"], "Só as que aparecem têm valor na linha: " . json_encode(array_keys($l["colunas_extras"])));
    exigir_cons($l["colunas_extras"]["c_marcha_media"]["texto"] === "4,50" && abs($l["colunas_extras"]["c_marcha_media"]["valor"] - 4.5) < 0.0001, "A marcha maior do relógio Um: " . json_encode($l["colunas_extras"]["c_marcha_media"]));
    exigir_cons($l["colunas_extras"]["c_custo_da_medicao"]["texto"] === "US$ 30,0", "A conta expert com a moeda: " . json_encode($l["colunas_extras"]["c_custo_da_medicao"]));
    $lb = tabela_linha(linha("SELECT * FROM relogio WHERE id = ?", [$b]), time());
    exigir_cons($lb["colunas_extras"]["c_marcha_media"]["texto"] === "—" && $lb["colunas_extras"]["c_marcha_media"]["valor"] === null, "O relógio sem medição: vazio.");
    exigir_cons(!isset(tabela_linha($rel, time(), ["sem_colunas" => true])["colunas_extras"]["c_marcha_media"]), "Os totais pedem a linha sem as colunas cadastradas.");

    // ---- as colunas de sempre moram no cadastro (sistema = 1, do modelo.json): 14, na ordem, a Relógio fixa; só mudam de lugar e de aparecer de início
    $sis = array_values(array_filter(colunas_todas(), function ($c) { return $c["sistema"]; }));
    exigir_cons(array_column($sis, "chave") === array_keys(TOTAL_COLUNAS) && count($sis) === 14, "As 14 colunas de sempre nascem no cadastro, na ordem de sempre.");
    exigir_cons(array_column(array_filter($sis, function ($c) { return $c["fixa"]; }), "chave") === ["nome"] && colunas_fixas("hoje") === ["nome"], "A Relógio é a fixa.");
    exigir_cons((int)valor("SELECT COUNT(*) FROM coluna WHERE sistema = 1") === 14 && (int)valor("SELECT MIN(ordem) FROM coluna WHERE sistema = 1") === 10 && (int)valor("SELECT MAX(ordem) FROM coluna WHERE sistema = 1") === 140, "Estão gravadas na tabela, de 10 a 140.");
    exigir_cons((int)valor("SELECT MIN(ordem) FROM coluna WHERE sistema = 0") > 140, "As que a pessoa monta vêm depois.");
    exigir_cons(colunas_titulos("hoje")["uso"] === "Tempo de uso", "O título da do sistema vem do dicionário (no idioma do pedido).");
    $idm = fn($k) => (int)valor("SELECT id FROM coluna WHERE sistema = 1 AND identificador = ?", [$k]);
    $recusa_s = function ($acao, $pedido, $parte, $rotulo) {
        $r = op_colunas($acao, $pedido);
        exigir_cons(!$r["ok"] && strpos(implode(" ", $r["erros"]), $parte) !== false, $rotulo . ": devia ser recusado (" . $parte . "): " . json_encode($r["erros"] ?? []));
    };
    $recusa_s("salvar", ["id" => $idm("marca"), "nome" => "Fabricante"], "é do sistema", "renomear uma do sistema");
    $recusa_s("salvar", ["id" => $idm("marca"), "formato" => "inteiro"], "é do sistema", "mudar o formato de uma do sistema");
    $recusa_s("salvar", ["id" => $idm("nome"), "padrao" => "0"], "não se esconde", "esconder a Relógio de início");
    $recusa_s("excluir", ["id" => $idm("marca")], "não se exclui", "excluir uma do sistema");
    exigir_cons(op_colunas("salvar", ["id" => $idm("marca"), "nome" => "Marca", "padrao" => "0"])["ok"] && !in_array("marca", colunas_padrao("hoje"), true), "Tirar uma do sistema do que aparece de início (o mesmo nome vale: nada muda).");
    exigir_cons(!in_array("marca", colunas_efetivas("hoje"), true) || cfg_cru("colunas_hoje") !== "", "Sem escolha, a que saiu do início não aparece.");
    exigir_cons(op_colunas("subir", ["id" => $idm("tipo")])["ok"] && array_slice(array_column(colunas_todas(), "chave"), 0, 4) === ["codigo", "nome", "tipo", "marca"], "Uma do sistema sobe na ordem do cadastro.");
    exigir_cons(op_colunas("descer", ["id" => $idm("tipo")])["ok"] && op_colunas("salvar", ["id" => $idm("marca"), "padrao" => "1"])["ok"] && colunas_padrao("hoje")[2] === "marca", "Volta ao que era.");

    // ---- a ordem
    $ids = array_column(colunas_cadastradas(), "id");
    exigir_cons(op_colunas("descer", ["id" => $ids[0]])["ok"] && array_column(colunas_cadastradas(), "id")[1] === $ids[0], "Descer a primeira.");
    exigir_cons(op_colunas("subir", ["id" => $ids[0]])["ok"] && array_column(colunas_cadastradas(), "id")[0] === $ids[0], "Subir de volta.");

    // ---- excluir: a chave sai do catálogo e da escolha guardada (a lista guardada com ela continua valendo, sem ela)
    exigir_cons(op_colunas("excluir", ["id" => $col["id"]])["ok"], "Não excluiu.");
    exigir_cons(!in_array("c_marcha_media", colunas_catalogo("hoje"), true) && colunas_efetivas("hoje") === ["nome", "c_custo_da_medicao", "uso"], "A coluna excluída sai da escolha: " . json_encode(colunas_efetivas("hoje")));
    exigir_cons(!op_colunas("excluir", ["id" => $col["id"]])["ok"], "Excluir de novo.");
    // ---- a migração v62 numa árvore que já tinha colunas montadas e não tinha as de sistema: as 14 entram na frente (de 10 a 140), as montadas
    // vão para depois (a ordem sobe 1000), e rodar de novo não duplica
    sem_escopo(function () {
        $raiz = (int)valor("SELECT id FROM no WHERE pai_id IS NULL ORDER BY id LIMIT 1");
        sql("DELETE FROM coluna WHERE sistema = 1 AND no_id = ?", [$raiz]);
        sql("UPDATE coluna SET ordem = ordem % 100 WHERE no_id = ?", [$raiz]);
        $GLOBALS["COLUNAS_VERSAO"] = ($GLOBALS["COLUNAS_VERSAO"] ?? 0) + 1;
    });
    $montadas_antes = array_column(colunas_cadastradas(), "chave");
    exigir_cons(count($montadas_antes) > 0, "Para a prova da v62 tinha de haver colunas montadas.");
    migracao_v62_dados(false);
    migracao_v62_dados(false);
    $GLOBALS["COLUNAS_VERSAO"] = ($GLOBALS["COLUNAS_VERSAO"] ?? 0) + 1;
    exigir_cons((int)valor("SELECT COUNT(*) FROM coluna WHERE sistema = 1") === 14, "A v62 cria as 14 uma vez só (rodar de novo não duplica).");
    exigir_cons(array_slice(array_column(colunas_todas(), "chave"), 0, 14) === array_keys(TOTAL_COLUNAS) && array_column(colunas_cadastradas(), "chave") === $montadas_antes, "As 14 vêm na frente e as montadas, na mesma ordem de antes, depois.");
    exigir_cons((int)valor("SELECT MIN(ordem) FROM coluna WHERE sistema = 0") > 1000, "As montadas sobem 1000.");

    echo "O construtor de colunas confere: guiado e expert, as recusas, o formato (casas e moeda), o catálogo e a escolha da página Hoje, o valor na linha, a ordem e a exclusão.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    @array_map("unlink", glob($pasta . "/*") ?: []);
    @rmdir($pasta);
}
