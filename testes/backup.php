<?php
// Regressão da restauração: cópias incompletas nunca substituem o banco ativo; as antigas válidas ainda voltam.
// Usa um SQLite novo e descartável, sem ler o config.php da instalação. php testes/backup.php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}
$pasta = sys_get_temp_dir() . "/relojoeiro-backup-teste-" . bin2hex(random_bytes(6));
mkdir($pasta, 0700, true);
putenv("RELOGIOS_CONFIG=" . __DIR__ . "/config.teste.php");
putenv("RELOGIOS_DB=sqlite");
putenv("RELOGIOS_TESTE_PASTA=" . $pasta);
define("BACKUP_MANTER", 1);
require dirname(__DIR__) . "/lib.php";

function exigir_backup($vale, $mensagem)
{
    if (!$vale) {
        throw new RuntimeException($mensagem);
    }
}

function retrato_backup()
{
    $dados = [];
    foreach (backup_tabelas() as [$t, , , $chave]) {
        $dados[$t] = linhas("SELECT * FROM " . $t . " ORDER BY " . implode(", ", $chave));
    }
    return hash("sha256", serialize($dados));
}

try {
    exigir_backup(instalar_banco()["ok"], "Não instalou o banco descartável.");
    sql("INSERT INTO usuario (id, login, senha_hash, papel, criado) VALUES (1, 'teste', ?, 'master', NOW())", [password_hash("Senha@123", PASSWORD_DEFAULT)]);
    sql("INSERT INTO no (id, pai_id, dono_id, nome) VALUES (1, NULL, 1, 'Todos')");
    sql("INSERT INTO colecao (id, dono_id, no_id, nome, criada) VALUES (1, 1, 1, 'Teste', NOW())");
    sql("INSERT INTO relogio (id, colecao_id, no_id, nome, criado) VALUES (1, 1, 1, 'Antes', NOW())");
    sql("INSERT INTO foto (relogio_id, tipo, dados, atualizado) VALUES (1, 'image/png', ?, NOW())", ["\x89PNG\0teste"]);
    // O mm mostra também em polegadas, e a polegada converte para mm: um ciclo válido de referências opcionais.
    sql("INSERT INTO unidade (id, no_id, simbolo, nome) VALUES (1, 1, 'mm', 'milímetro')");
    sql("INSERT INTO unidade (id, no_id, simbolo, nome, base_id, fator) VALUES (2, 1, 'pol', 'polegada', 1, 25.4)");
    sql("UPDATE unidade SET mostrar_em_id = 2 WHERE id = 1");
    $copia = backup_pasta() . "/relojoeiro-2000-01-01-000001.jsonl.gz";
    banco_despejar($copia, false);
    $gzip = file_get_contents($copia);
    $texto = gzdecode($gzip);
    $linhas = array_values(array_filter(explode("\n", $texto), function ($l) { return $l !== ""; }));
    $nome = "relojoeiro-2000-01-01-000002.jsonl.gz";
    $caminho = backup_pasta() . "/" . $nome;
    $casos = [];
    $casos["só cabeçalho"] = gzencode($linhas[0] . "\n");
    $casos["JSON inválido"] = gzencode($texto . "[quebrado\n");
    $casos["gzip cortado"] = substr($gzip, 0, -6);
    $crc = $gzip;
    $crc[strlen($crc) - 8] = chr(ord($crc[strlen($crc) - 8]) ^ 1);
    $casos["CRC inválido"] = $crc;
    $casos["bytes depois do gzip"] = $gzip . "sobrou";
    foreach (["tabela ausente", "tabela repetida", "coluna desconhecida", "binário inválido", "registro ausente", "registro curto", "contagem ausente"] as $caso) {
        $alteradas = [];
        $tabela = null;
        foreach ($linhas as $l) {
            $x = json_decode($l, true);
            if (isset($x["tabela"])) {
                $tabela = $x["tabela"];
                if ($caso === "coluna desconhecida" && $tabela === "relogio") $x["colunas"][0] = "coluna_que_nao_existe";
                if ($caso === "contagem ausente") unset($x["registros"]);
                if ($caso === "tabela ausente" && $tabela === "foto") continue;
            } elseif (array_is_list($x) && $tabela === "foto") {
                if ($caso === "tabela ausente" || $caso === "registro ausente") continue;
                if ($caso === "binário inválido") $x[2] = "não é base64!";
                if ($caso === "registro curto") array_pop($x);
            }
            $alteradas[] = json_encode($x);
            if ($caso === "tabela repetida" && ($x["tabela"] ?? null) === "foto") $alteradas[] = json_encode($x);
        }
        $casos[$caso] = gzencode(implode("\n", $alteradas) . "\n");
    }
    $antes = retrato_backup();
    $copias_antes = count(backup_lista());
    foreach ($casos as $caso => $conteudo) {
        file_put_contents($caminho, $conteudo);
        $r = backup_restaurar($nome);
        exigir_backup(!$r["ok"], "Aceitou " . $caso);
        exigir_backup(retrato_backup() === $antes, "Mudou o banco ao recusar " . $caso);
        exigir_backup(count(backup_lista()) === $copias_antes + 1, "Criou ou apagou cópias ao recusar " . $caso);
    }
    // O leitor da migração v25 ignora os metadados novos, sem confundi-los com registros.
    file_put_contents($copia, $gzip);
    $lidos = 0;
    banco_ler_copia($copia, function ($t, $r) use (&$lidos) { $lidos++; });
    exigir_backup($lidos > 0, "O leitor antigo não leu a cópia nova.");
    foreach ([false, true] as $antiga) {
        $restaurar = $gzip;
        if ($antiga) {
            $sem_contagem = [];
            foreach ($linhas as $l) {
                $x = json_decode($l, true);
                unset($x["verificacao"], $x["registros"]);
                $sem_contagem[] = json_encode($x);
            }
            $restaurar = gzencode(implode("\n", $sem_contagem) . "\n");
        }
        file_put_contents($caminho, $restaurar);
        sql("UPDATE relogio SET nome = 'Depois' WHERE id = 1");
        $r = backup_restaurar($nome);
        exigir_backup($r["ok"], "Recusou cópia válida: " . implode(" ", $r["erros"]));
        exigir_backup(valor("SELECT nome FROM relogio WHERE id = 1") === "Antes", "Não voltou o registro original.");
        exigir_backup(valor("SELECT dados FROM foto WHERE relogio_id = 1") === "\x89PNG\0teste", "Mudou o binário da foto.");
        exigir_backup((int)valor("SELECT mostrar_em_id FROM unidade WHERE id = 1") === 2, "Perdeu a unidade de exibição.");
        exigir_backup((int)valor("SELECT base_id FROM unidade WHERE id = 2") === 1, "Perdeu a base da conversão.");
    }
    echo count($casos) . " cópias inválidas recusadas sem alterar o banco; cópias nova e antiga restauradas.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
