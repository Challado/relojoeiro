<?php
// O teste de isolamento entre contas: duas contas comuns (ana e bia), cada uma com a sua coleção e o seu token pessoal, e uma
// terceira (caio) sem coleção nenhuma. A ana cadastra coisas marcadas com SEGREDOANA; a bia e o caio, pelos tokens deles,
// pedem todos os recursos da API, com os ids e a coleção da ana nos parâmetros, e tentam mudar e apagar o que é dela. Nada
// da ana pode aparecer nas respostas, e nada dela pode mudar. Roda num banco recém-instalado (o testes/paridade.sh o chama
// depois do roteiro, no servidor do primeiro banco), com o token do servidor do config de teste:
//   php testes/isolamento.php http://127.0.0.1:8101/api.php
// Termina com 0 se nada vazou.
if (PHP_SAPI !== "cli" || count($argv) < 2) {
    fwrite(STDERR, "Uso: php testes/isolamento.php <endereço do api.php>\n");
    exit(1);
}
$API = $argv[1];
$SERVIDOR = getenv("RELOGIOS_TOKEN") ?: "token-de-teste-123";
$MARCA = "segredoana";
$falhas = [];

// um pedido: $quem é ["token", x] ou ["basic", "login:senha"]
function pedir($quem, $metodo, $params)
{
    global $API;
    $ch = curl_init($metodo === "GET" ? $API . "?" . http_build_query($params) : $API);
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120];
    if ($quem[0] === "token") {
        $op[CURLOPT_HTTPHEADER] = ["X-Api-Token: " . $quem[1]];
    } elseif ($quem[1] !== "") {
        $op[CURLOPT_USERPWD] = $quem[1];
    }
    if ($metodo === "POST") {
        $op[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $op);
    $corpo = (string)curl_exec($ch);
    return ["codigo" => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), "corpo" => $corpo, "json" => json_decode($corpo, true)];
}

function preparar($quem, $params)
{
    $r = pedir($quem, "POST", $params);
    if (!is_array($r["json"]) || ($r["json"]["ok"] ?? false) !== true) {
        fwrite(STDERR, "A preparação falhou: " . json_encode($params) . " → " . $r["codigo"] . " " . substr($r["corpo"], 0, 300) . "\n");
        exit(1);
    }
    return $r["json"];
}

// as três contas, pelo token do servidor (o master)
$srv = ["token", $SERVIDOR];
preparar($srv, ["recurso" => "usuarios", "acao" => "salvar", "login" => "ana", "senha" => "Senha-da-ana-1", "email" => "segredoana@exemplo.com"]);
preparar($srv, ["recurso" => "usuarios", "acao" => "salvar", "login" => "bia", "senha" => "Senha-da-bia-1", "email" => "bia@exemplo.com"]);
preparar($srv, ["recurso" => "usuarios", "acao" => "salvar", "login" => "caio", "senha" => "Senha-do-caio-1", "colecao_nova" => "nenhuma"]);
$tk = [];
foreach (["ana" => "Senha-da-ana-1", "bia" => "Senha-da-bia-1", "caio" => "Senha-do-caio-1"] as $l => $s) {
    $tk[$l] = ["token", preparar(["basic", $l . ":" . $s], ["recurso" => "conta", "acao" => "token_criar", "nome" => "teste"])["token"] ?? ""];
}
$ana = $tk["ana"];

// o que a ana cadastra, com a marca
$conta_a = pedir($ana, "GET", ["recurso" => "conta"])["json"];
$col_a = (int)($conta_a["colecoes"][0]["id"] ?? 0);
$uid_a = (int)($conta_a["usuario"]["id"] ?? 0);
preparar($ana, ["recurso" => "colecoes", "acao" => "renomear", "id" => $col_a, "nome" => "Colecao SEGREDOANA"]);
$arv = pedir($ana, "GET", ["recurso" => "arvore"])["json"];
$no_a = (int)($arv["nos"][0]["id"] ?? ($arv["arvore"][0]["id"] ?? 0));
$rel_a = (int)preparar($ana, ["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Relogio SEGREDOANA", "disponivel" => 1,
    "valores" => ["observacao" => "Obs SEGREDOANA"]])["id"];
preparar($ana, ["recurso" => "relogio", "acao" => "foto", "id" => $rel_a,
    "foto_base64" => base64_encode(base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="))]);
$lan_a = (int)(preparar($ana, ["recurso" => "lancamento", "acao" => "alternar", "relogio_id" => $rel_a, "tipo" => "pulso"])["id"] ?? 0);
// um smartwatch da ana com duas leituras: a medição do gasto entre elas (a bia e o caio não podem tirá-la da conta)
$no_s = 0;
foreach ($arv["grupos"] ?? [] as $g) {
    $no_s = ($g["nome"] ?? "") === "Smartwatch" ? (int)$g["id"] : $no_s;
}
$rel_s = (int)preparar($ana, ["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Smart SEGREDOANA", "no_id" => $no_s, "disponivel" => 1])["id"];
// a primeira bem no passado (o fuso daqui pode não ser o do servidor), a segunda agora (vazio: o agora do servidor)
preparar($ana, ["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel_s, "tipo" => "carga", "valor" => 90, "quando" => date("Y-m-d H:i", time() - 30 * 3600)]);
preparar($ana, ["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel_s, "tipo" => "carga", "valor" => 88]);
$med_a = (int)(pedir($ana, "GET", ["recurso" => "ficha", "relogio" => $rel_s])["json"]["relogio"]["medicoes"][0]["id"] ?? 0);
$ace_a = (int)(preparar($ana, ["recurso" => "acessorios", "acao" => "salvar", "nome" => "Acessorio SEGREDOANA", "tipo" => "pulseira", "relogio_id" => $rel_a])["id"] ?? 0);
$evt_a = (int)(preparar($ana, ["recurso" => "config", "acao" => "evento_salvar", "id" => 0, "nome" => "Evento SEGREDOANA", "repeticao" => "semanal", "hora" => "10:00", "dias_semana" => "6"])["id"] ?? 0);
$des_a = (int)(preparar($ana, ["recurso" => "destinos", "acao" => "salvar", "id" => 0, "tipo" => "webhook", "nome" => "Destino SEGREDOANA", "ativo" => 0, "metodo" => "POST",
    "url" => "https://segredoana.exemplo.invalid/enviar"])["id"] ?? 0);
$agd_a = (int)(preparar($ana, ["recurso" => "agendas", "acao" => "salvar", "id" => 0, "tipo" => "ics", "nome" => "Agenda SEGREDOANA", "ativo" => 1])["id"] ?? 0);
$cat_a = (int)(pedir($ana, "GET", ["recurso" => "documentos"])["json"]["categorias"][0]["id"] ?? 0);
$doc_a = (int)(preparar($ana, ["recurso" => "documentos", "acao" => "enviar", "relogio_id" => $rel_a, "categoria_id" => $cat_a, "titulo" => "Doc SEGREDOANA",
    "arquivo_nome" => "segredoana.txt", "arquivo_base64" => base64_encode("conteudo SEGREDOANA")])["id"] ?? 0);
$apa_a = (int)(preparar($ana, ["recurso" => "conta", "acao" => "aparelho_lembrar", "nome" => "Aparelho SEGREDOANA"])["id"] ?? 0);
$cal_a = (int)(preparar($ana, ["recurso" => "calibres", "acao" => "salvar", "id" => 0, "nome" => "Calibre SEGREDOANA", "observacao" => "SEGREDOANA"])["id"] ?? 0);
preparar($ana, ["recurso" => "relogio", "acao" => "salvar", "id" => $rel_a, "calibre_id" => $cal_a]);
$doc_cal_a = (int)(preparar($ana, ["recurso" => "documentos", "acao" => "enviar", "calibre_id" => $cal_a, "categoria_id" => $cat_a, "titulo" => "Manual SEGREDOANA",
    "arquivo_nome" => "segredoana-calibre.txt", "arquivo_base64" => base64_encode("manual SEGREDOANA")])["id"] ?? 0);
$srv_a = (int)(preparar($ana, ["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel_a, "data" => date("Y-m-d", time() - 3 * 86400), "tipo" => "reparo",
    "responsavel" => "Servico SEGREDOANA", "pecas" => "Peca SEGREDOANA; 10", "documentos" => (string)$doc_a])["id"] ?? 0);
// um mecânico da ana com um teste da reserva terminado (a observação com a marca)
$no_mec_a = 0;
foreach ($arv["grupos"] ?? [] as $g_a) {
    $no_mec_a = $no_mec_a === 0 && mb_strpos((string)$g_a["caminho"], "Mec") !== false ? (int)$g_a["id"] : $no_mec_a;
}
$rel_m = (int)(preparar($ana, ["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Mecanico SEGREDOANA", "no_id" => $no_mec_a, "disponivel" => 1])["id"] ?? 0);
$rst_a = (int)(preparar($ana, ["recurso" => "reserva", "acao" => "iniciar", "relogio_id" => $rel_m, "inicio" => date("Y-m-d H:i", time() - 3 * 86400), "protocolo" => "corda",
    "observacao" => "Reserva SEGREDOANA"])["id"] ?? 0);
preparar($ana, ["recurso" => "reserva", "acao" => "parou", "id" => $rst_a, "fim" => date("Y-m-d H:i", time() - 86400)]);
$ids = ["colecao" => $col_a, "relogio" => $rel_a, "aparelho" => $apa_a, "lancamento" => $lan_a, "acessorio" => $ace_a, "evento" => $evt_a, "destino" => $des_a, "agenda" => $agd_a,
    "documento" => $doc_a, "no" => $no_a, "usuario" => $uid_a, "smartwatch" => $rel_s, "medicao" => $med_a, "calibre" => $cal_a, "doc_calibre" => $doc_cal_a, "servico" => $srv_a,
    "mecanico" => $rel_m, "reserva" => $rst_a];
echo "A ana: " . json_encode($ids) . "\n";
$antes = pedir($ana, "GET", ["recurso" => "ficha", "relogio" => $rel_a])["corpo"];

// os recursos de leitura e as variações de parâmetro com as coisas da ana
$recursos = ["", "importacao", "backup", "foto", "documento", "documentos", "criterios", "avisos", "autonomia", "hoje", "ficha", "config", "historico", "previsao",
    "plano", "desejos", "estatisticas", "precisao", "calibres", "servicos", "reserva", "hora_certa", "passaporte", "galeria", "acessorios", "proximos", "rodizio_manual", "eventos", "destinos", "agendas",
    "usuarios", "conta", "colecoes", "acessos", "pedidos", "convites", "aprovacoes", "cron", "arvore", "cadastros", "calcular", "migracoes", "instalacao",
    "cadastro", "entrar"];
$variacoes = [[], ["colecao" => $col_a], ["relogio" => $rel_a], ["id" => $rel_a], ["relogio_id" => $rel_a], ["como" => "ana"], ["usuario" => "ana"],
    ["login" => "ana"], ["usuario_id" => $uid_a], ["documento" => $doc_a], ["id" => $doc_a], ["calibre" => $cal_a], ["id" => $doc_cal_a], ["id" => $des_a], ["id" => $agd_a], ["id" => $evt_a],
    ["no" => $no_a], ["no_id" => $no_a], ["arvore_id" => $no_a], ["relogio" => $rel_a, "baixar" => 1]];
$n = 0;
foreach (["bia", "caio"] as $quem) {
    foreach ($recursos as $rec) {
        foreach ($variacoes as $v) {
            $p = array_merge(["recurso" => $rec], $v);
            $r = pedir($tk[$quem], "GET", $p);
            $n++;
            if (stripos($r["corpo"], $MARCA) !== false) {
                $falhas[] = "VAZOU " . $quem . " GET " . http_build_query($p) . " → " . $r["codigo"] . ": " . substr(preg_replace('/\s+/', " ", $r["corpo"]), max(0, stripos($r["corpo"], $MARCA) - 120), 240);
            }
        }
    }
    // o ICS da agenda da ana, sem a chave dela
    foreach (["", "x", (string)$agd_a] as $ch) {
        $r = pedir($tk[$quem], "GET", ["recurso" => "ics", "chave" => $ch]);
        if (stripos($r["corpo"], $MARCA) !== false) {
            $falhas[] = "VAZOU " . $quem . " ics chave=" . $ch;
        }
    }
}
echo $n . " leituras conferidas\n";

// as escritas da bia e do caio nas coisas da ana: nada pode mudar
$tentativas = [
    ["recurso" => "relogio", "acao" => "salvar", "id" => $rel_a, "nome" => "INVADIDO"],
    ["recurso" => "relogio", "acao" => "excluir", "id" => $rel_a, "confirmar" => 1],
    ["recurso" => "relogio", "acao" => "foto", "id" => $rel_a, "foto_base64" => "x"],
    ["recurso" => "relogio", "acao" => "remover_foto", "id" => $rel_a],
    ["recurso" => "lancamento", "acao" => "alternar", "relogio_id" => $rel_a, "tipo" => "pulso"],
    ["recurso" => "lancamento", "acao" => "excluir", "id" => $lan_a, "confirmar" => 1],
    ["recurso" => "lancamento", "acao" => "salvar", "id" => $lan_a, "observacao" => "INVADIDO"],
    ["recurso" => "lancamento", "acao" => "medicao", "id" => $med_a, "usada" => 0],
    ["recurso" => "acessorios", "acao" => "salvar", "id" => $ace_a, "nome" => "INVADIDO", "tipo" => "pulseira"],
    ["recurso" => "calibres", "acao" => "salvar", "id" => $cal_a, "nome" => "INVADIDO"],
    ["recurso" => "calibres", "acao" => "excluir", "id" => $cal_a, "confirmar" => 1],
    ["recurso" => "documentos", "acao" => "excluir", "id" => $doc_cal_a, "confirmar" => 1],
    ["recurso" => "documentos", "acao" => "alterar", "id" => $doc_cal_a, "titulo" => "INVADIDO"],
    ["recurso" => "documentos", "acao" => "enviar", "calibre_id" => $cal_a, "categoria_id" => $cat_a, "titulo" => "INVADIDO", "arquivo_nome" => "x.txt", "arquivo_base64" => base64_encode("x")],
    ["recurso" => "acessorios", "acao" => "excluir", "id" => $ace_a, "confirmar" => 1],
    ["recurso" => "servicos", "acao" => "salvar", "id" => $srv_a, "responsavel" => "INVADIDO"],
    ["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel_a, "data" => date("Y-m-d"), "responsavel" => "INVADIDO"],
    ["recurso" => "servicos", "acao" => "excluir", "id" => $srv_a, "confirmar" => 1],
    ["recurso" => "reserva", "acao" => "salvar", "id" => $rst_a, "observacao" => "INVADIDO"],
    ["recurso" => "reserva", "acao" => "parou", "relogio_id" => $rel_m],
    ["recurso" => "reserva", "acao" => "iniciar", "relogio_id" => $rel_m, "observacao" => "INVADIDO"],
    ["recurso" => "reserva", "acao" => "observar", "relogio_id" => $rel_m, "tipo" => "nota", "observacao" => "INVADIDO"],
    ["recurso" => "reserva", "acao" => "usar", "relogio_id" => $rel_m],
    ["recurso" => "reserva", "acao" => "excluir", "id" => $rst_a, "confirmar" => 1],
    ["recurso" => "acessorios", "acao" => "salvar", "nome" => "INVADIDO", "tipo" => "pulseira", "relogio_id" => $rel_a],
    ["recurso" => "config", "acao" => "evento_salvar", "id" => $evt_a, "nome" => "INVADIDO", "repeticao" => "semanal", "hora" => "10:00", "dias_semana" => "6"],
    ["recurso" => "config", "acao" => "evento_excluir", "evento_id" => $evt_a, "confirmar" => 1],
    ["recurso" => "destinos", "acao" => "salvar", "id" => $des_a, "tipo" => "webhook", "nome" => "INVADIDO", "url" => "https://x.invalid/"],
    ["recurso" => "destinos", "acao" => "excluir", "id" => $des_a, "confirmar" => 1],
    ["recurso" => "destinos", "acao" => "testar", "id" => $des_a],
    ["recurso" => "agendas", "acao" => "salvar", "id" => $agd_a, "tipo" => "ics", "nome" => "INVADIDO"],
    ["recurso" => "agendas", "acao" => "excluir", "id" => $agd_a, "confirmar" => 1],
    ["recurso" => "documentos", "acao" => "excluir", "id" => $doc_a, "confirmar" => 1],
    ["recurso" => "documentos", "acao" => "alterar", "id" => $doc_a, "titulo" => "INVADIDO"],
    ["recurso" => "documentos", "acao" => "enviar", "relogio_id" => $rel_a, "categoria_id" => $cat_a, "titulo" => "INVADIDO", "arquivo_nome" => "x.txt", "arquivo_base64" => base64_encode("x")],
    ["recurso" => "colecoes", "acao" => "renomear", "id" => $col_a, "nome" => "INVADIDO"],
    ["recurso" => "colecoes", "acao" => "excluir", "id" => $col_a, "confirmar" => 1],
    ["recurso" => "colecoes", "acao" => "arvore_excluir", "arvore_id" => $no_a, "confirmar" => 1],
    ["recurso" => "arvore", "acao" => "salvar", "id" => $no_a, "nome" => "INVADIDO"],
    ["recurso" => "arvore", "acao" => "excluir", "id" => $no_a, "confirmar" => 1],
    ["recurso" => "rodizio", "acao" => "trocar_dia", "data" => date("Y-m-d", time() + 86400), "relogio_id" => $rel_a],
    ["recurso" => "rodizio", "acao" => "usando", "relogio_id" => $rel_a],
    ["recurso" => "acessos", "acao" => "remover", "colecao_id" => $col_a, "usuario_id" => $uid_a, "confirmar" => 1],
    ["recurso" => "convites", "acao" => "criar", "colecao_id" => $col_a, "email" => "x@exemplo.com"],
    ["recurso" => "usuarios", "acao" => "salvar", "login" => "ana", "senha" => "INVADIDO-123"],
    ["recurso" => "config", "acao" => "salvar", "senha_tamanho" => 4, "senha_simbolos" => 0],
    ["recurso" => "usuarios", "acao" => "excluir", "login" => "ana", "confirmar" => 1],
    ["recurso" => "conta", "acao" => "alterar", "email" => "segredoana@exemplo.com"],
    ["recurso" => "conta", "acao" => "aparelho_esquecer", "id" => $apa_a],
    ["recurso" => "backup", "acao" => "fazer"],
    ["recurso" => "importacao", "acao" => "importar"],
    ["recurso" => "pedidos", "acao" => "pedir", "colecao_id" => $col_a, "mensagem" => "oi"],
    ["recurso" => "pedidos", "acao" => "pedir", "dono_id" => $uid_a],
    ["recurso" => "pedidos", "acao" => "pedir", "dono" => "ana"],
    ["recurso" => "config", "acao" => "salvar", "colecao" => $col_a, "uso_inicio" => "05:00"],
    ["recurso" => "relogio", "acao" => "salvar", "colecao" => $col_a, "id" => 0, "nome" => "INVADIDO"],
    ["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "INVADIDO", "calibre_id" => $cal_a],
];
foreach (["bia", "caio"] as $quem) {
    foreach ($tentativas as $p) {
        $r = pedir($tk[$quem], "POST", $p);
        if (stripos($r["corpo"], $MARCA) !== false) {
            $falhas[] = "VAZOU " . $quem . " POST " . http_build_query($p) . " → " . $r["codigo"] . ": " . substr($r["corpo"], 0, 300);
        }
    }
}
// a força da senha é do sistema: quem não é o master não muda (continua a do padrão)
$pol = pedir($srv, "GET", ["recurso" => "cadastro"])["json"]["senha_politica"]["regras"] ?? [];
if (($pol["tamanho"] ?? 0) !== 8 || ($pol["simbolos"] ?? 0) !== 1) {
    $falhas[] = "MUDOU a força da senha por quem não é o master: " . json_encode($pol);
}
// depois dos pedidos de acesso (ainda não aprovados): a conta de quem pediu mostra só o código, nunca o nome nem o login
foreach (["bia", "caio"] as $quem) {
    foreach (["conta", "pedidos", "aprovacoes", "colecoes", "cadastro", ""] as $rec) {
        $r = pedir($tk[$quem], "GET", ["recurso" => $rec]);
        if (stripos($r["corpo"], $MARCA) !== false || preg_match('/"(login|dono)":\s*"ana"/', $r["corpo"]) === 1) {
            $falhas[] = "VAZOU " . $quem . " GET recurso=" . $rec . " depois do pedido: " . substr(preg_replace('/\s+/', " ", $r["corpo"]), 0, 300);
        }
    }
}
// e sem login nenhum, a página Criar conta não lista contas nem coleções
$r = pedir(["basic", ""], "GET", ["recurso" => "cadastro"]);
if (stripos($r["corpo"], $MARCA) !== false || stripos($r["corpo"], '"ana"') !== false || stripos($r["corpo"], '"bia"') !== false) {
    $falhas[] = "VAZOU o cadastro sem login: " . substr($r["corpo"], 0, 300);
}
// a ana confere que tudo dela continua igual (a ficha do relógio) e que ninguém entrou como ela
$depois = pedir($ana, "GET", ["recurso" => "ficha", "relogio" => $rel_a])["corpo"];
$ver = function ($rec, $p = []) use ($ana) { return pedir($ana, "GET", array_merge(["recurso" => $rec], $p))["corpo"]; };
if (preg_replace('/"(calculado_em|agora|gerado_em)[^,]*,/', "", $antes) !== preg_replace('/"(calculado_em|agora|gerado_em)[^,]*,/', "", $depois)
    && stripos($depois, "INVADIDO") !== false) {
    $falhas[] = "MUDOU a ficha do relógio da ana";
}
foreach ([["acessorios"], ["servicos", ["relogio" => $rel_a]], ["reserva", ["relogio" => $rel_m]], ["eventos"], ["destinos"], ["agendas"], ["documentos", ["relogio" => $rel_a]], ["historico", ["relogio" => $rel_a]], ["conta"], ["colecoes"],
    ["plano"], ["arvore"]] as $x) {
    $c = $ver($x[0], $x[1] ?? []);
    if (stripos($c, "INVADIDO") !== false) {
        $falhas[] = "MUDOU " . $x[0] . " da ana: " . substr($c, max(0, stripos($c, "INVADIDO") - 150), 300);
    }
}
foreach (["Relogio SEGREDOANA" => ["ficha", ["relogio" => $rel_a]], "Acessorio SEGREDOANA" => ["acessorios"], "Servico SEGREDOANA" => ["servicos", ["relogio" => $rel_a]], "Reserva SEGREDOANA" => ["reserva", ["relogio" => $rel_m]], "Evento SEGREDOANA" => ["eventos"],
    "Destino SEGREDOANA" => ["destinos"], "Agenda SEGREDOANA" => ["agendas"], "Doc SEGREDOANA" => ["documentos", ["relogio" => $rel_a]],
    "Colecao SEGREDOANA" => ["conta"]] as $o => $x) {
    if (stripos($ver($x[0], $x[1] ?? []), $o) === false) {
        $falhas[] = "SUMIU " . $o . " da ana";
    }
}
// a medição do smartwatch da ana continua na conta
$meds = pedir($ana, "GET", ["recurso" => "ficha", "relogio" => $rel_s])["json"]["relogio"]["medicoes"] ?? [];
if ($med_a === 0 || ($meds[0]["usada"] ?? false) !== true) {
    $falhas[] = "MUDOU a medição do gasto da ana (ou ela não foi criada)";
}
if (pedir(["basic", "ana:Senha-da-ana-1"], "GET", ["recurso" => "conta"])["codigo"] !== 200) {
    $falhas[] = "A SENHA da ana mudou";
}
foreach ($falhas as $f) {
    echo $f . "\n";
}
echo count($falhas) === 0 ? "Isolamento: nada vazou, nada mudou.\n" : "Isolamento: " . count($falhas) . " problemas.\n";
exit(count($falhas) === 0 ? 0 : 1);
