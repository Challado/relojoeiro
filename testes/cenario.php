<?php
// O roteiro de paridade: faz pela API o que uma pessoa faz no sistema (cadastra relógios de cada tipo, lança cordas,
// cargas, sol, winder e pilha, monta a escala, mexe nos critérios, nos eventos e nas fotos, roda o cron) e no fim tira a
// "fotografia" de tudo o que a API devolve. Rodado contra cada banco (MySQL, Postgres, SQLite) num banco recém-instalado,
// as fotografias têm de sair iguais; o testes/comparar.php diz onde não saíram.
//   php testes/cenario.php http://127.0.0.1:8081/api.php saida.json
// O cron roda como processo filho, com o mesmo ambiente deste (o config.php de teste escolhe o banco por ele).
if (PHP_SAPI !== "cli" || count($argv) < 3) {
    fwrite(STDERR, "Uso: php testes/cenario.php <endereço do api.php> <arquivo de saída>\n");
    exit(1);
}
$API = $argv[1];
$SAIDA = $argv[2];
$TOKEN = getenv("RELOGIOS_TOKEN") ?: "token-de-teste-123";
// O fuso do sistema: as datas e horas que o roteiro manda (o "quando" de cada lançamento) a API lê no FUSO do config.php.
// Montadas em outro fuso, elas caem fora do lugar: à noite no Brasil já é o dia seguinte em UTC, e "hoje 00:20" vira uma
// hora no futuro, que a API recusa. O fuso vem da variável RELOGIOS_FUSO ou, sem ela, do config.php da pasta do sistema
$fuso = (string)getenv("RELOGIOS_FUSO");
if ($fuso === "" && is_file(__DIR__ . "/../config.php")) {
    require __DIR__ . "/../config.php";
    $fuso = defined("FUSO") ? trim((string)constant("FUSO")) : "";
}
if ($fuso !== "" && !@date_default_timezone_set($fuso)) {
    fwrite(STDERR, "Fuso desconhecido: " . $fuso . "\n");
    exit(1);
}
$falhas = [];
$passo = 0;

function chamar($metodo, $params = [])
{
    global $API, $TOKEN;
    $ch = curl_init($metodo === "GET" ? $API . "?" . http_build_query($params) : $API);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["X-Api-Token: " . $TOKEN], CURLOPT_TIMEOUT => 120]);
    if ($metodo === "POST") {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $corpo = (string)curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tipo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $json = json_decode($corpo, true);
    return ["codigo" => $codigo, "json" => $json, "cru" => $json === null ? (preg_match("//u", $corpo) === 1 ? ["tipo" => $tipo, "texto" => $corpo] : ["tipo" => $tipo, "tamanho" => strlen($corpo), "md5" => md5($corpo)]) : null];
}

// uma escrita: guarda a resposta (o ok, a mensagem, os erros) para comparar, e devolve o id
function escrever($params, $espera_ok = true)
{
    global $foto, $falhas, $passo;
    $passo++;
    $r = chamar("POST", $params);
    $j = $r["json"] ?? [];
    $foto["escritas"][sprintf("%03d", $passo) . " " . $params["recurso"] . "." . $params["acao"]] = ["codigo" => $r["codigo"], "resposta" => $j ?? $r["cru"]];
    if ($espera_ok && !($j["ok"] ?? false)) {
        $falhas[] = "passo " . $passo . " (" . $params["recurso"] . "." . $params["acao"] . "): " . json_encode($j ?? $r["cru"], JSON_UNESCAPED_UNICODE);
    }
    return (int)($j["id"] ?? 0);
}

function ler($nome, $params)
{
    global $foto;
    $r = chamar("GET", $params);
    $foto["leituras"][$nome] = ["codigo" => $r["codigo"], "resposta" => $r["json"] ?? $r["cru"]];
    return $r["json"];
}

function cron()
{
    global $foto;
    $saida = [];
    exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__DIR__ . "/../cron.php") . " --forcar -v 2>&1", $saida, $codigo);
    $foto["cron"][] = ["codigo" => $codigo, "saida" => array_slice($saida, 1)];
}

// um horário fixo do relógio, N dias atrás: o cenário sai igual em qualquer hora do dia (um período relativo a agora,
// "há 2 dias e 14 horas", cai num dia ou no outro conforme a hora em que roda, e muda as contagens por dia)
function dia($dias_atras, $hora)
{
    return date("Y-m-d", strtotime("-" . (int)$dias_atras . " days")) . " " . $hora;
}

// um instante de agora há pouco (minutos atrás): o que tem de estar no passado mesmo rodando logo depois da meia-noite
function ha_minutos($minutos)
{
    return date("Y-m-d H:i", time() - (int)$minutos * 60);
}

$foto = ["escritas" => [], "leituras" => [], "cron" => []];

// ---------- a árvore e os cadastros ----------
$cron_g = escrever(["recurso" => "arvore", "acao" => "novo", "nome" => "Cronógrafos", "pai_id" => 3]);
escrever(["recurso" => "arvore", "acao" => "novo", "nome" => "cronógrafos", "pai_id" => 3], false);   // o mesmo nome, outra caixa
escrever(["recurso" => "arvore", "acao" => "renomear", "id" => $cron_g, "nome" => "Cronógrafos automáticos"]);
escrever(["recurso" => "arvore", "acao" => "ordem", "id" => $cron_g, "direcao" => "sobe"]);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "resistencia_agua", "nome" => "Resistência à água", "tipo" => "inteiro", "unidade" => "m", "no_id" => 0]);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "RESISTENCIA_AGUA", "nome" => "Outra", "tipo" => "inteiro", "no_id" => 0], false);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "estilo", "nome" => "Estilo", "tipo" => "lista", "opcoes" => "Social\nEsportivo\nMilitar", "no_id" => 0]);
escrever(["recurso" => "lancamento_tipos", "acao" => "novo", "identificador" => "banho_ultrassom", "nome" => "Banho ultrassônico", "formato" => "instantaneo", "no_id" => 0]);
escrever(["recurso" => "formulas", "acao" => "nova", "identificador" => "idade_dias", "nome" => "Idade (dias)", "expressao" => "ARREDONDA(HOJE() - data_compra)", "unidade" => "dias", "no_id" => 0]);
escrever(["recurso" => "formulas", "acao" => "nova", "identificador" => "idade_dias", "nome" => "Idade (errada)", "expressao" => "1 +", "unidade" => "dias", "no_id" => 0], false);
escrever(["recurso" => "avisos", "acao" => "novo", "identificador" => "pulseira", "nome" => "Trocar a pulseira", "expressao" => "data_compra + 365", "antecedencia_dias" => 400, "texto" => "Pulseira do {relogio}: {quando} ({data}).", "no_id" => 0]);

// ---------- os relógios, um de cada tipo ----------
$hoje = date("Y-m-d");
$rel = [];
$rel["smart"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Xiaomi Band 8", "no_id" => 1, "disponivel" => 1,
    "valores" => ["preferencia" => 71, "autonomia_dias" => 14, "data_compra" => date("Y-m-d", strtotime("-200 days")), "valor_compra" => "249.90", "loja" => "AliExpress", "estilo" => "Esportivo"]]);
$rel["auto"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Seiko 5 Sports", "no_id" => 4, "disponivel" => 1,
    "valores" => ["preferencia" => 88, "reserva_horas" => 41, "data_compra" => date("Y-m-d", strtotime("-500 days")), "valor_compra" => "1890", "loja" => "Loja Física",
        "data_revisao" => date("Y-m-d", strtotime("-50 months")), "intervalo_revisao_meses" => 48, "resistencia_agua" => 100, "garantia_ate" => date("Y-m-d", strtotime("+20 days"))]]);
$rel["auto2"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Orient Bambino", "no_id" => 4, "disponivel" => 1,
    "valores" => ["preferencia" => 64, "reserva_horas" => 40, "corda_manual" => 0, "carga_winder_horas" => 6, "carga_winder_reserva" => 30, "valor_compra" => "1200.50"]]);
$rel["manual"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Poljot corda manual", "no_id" => 5, "disponivel" => 1,
    "valores" => ["preferencia" => 57, "reserva_horas" => 36]]);
$rel["pilha"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Casio F-91W", "no_id" => 7, "disponivel" => 1,
    "valores" => ["preferencia" => 45, "data_pilha" => date("Y-m-d", strtotime("-23 months")), "vida_pilha_meses" => 24, "valor_compra" => "99"]]);
$rel["solar"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Citizen Eco-Drive", "no_id" => 8, "disponivel" => 1,
    "valores" => ["preferencia" => 79, "reserva_dias" => 180, "carga_sol_horas" => 10, "data_compra" => date("Y-m-d", strtotime("-10 days"))]]);
$rel["crono"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Relógio Ácido Ômega", "no_id" => $cron_g, "disponivel" => 1,
    "valores" => ["preferencia" => 93, "reserva_horas" => 50]]);
$rel["fora"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "No conserto", "no_id" => 6, "disponivel" => 0, "valores" => ["preferencia" => 30]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto"], "valores" => ["preferencia" => "abc", "observacao" => "Presente do avô — ótimo"]], false);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["smart"], "valores" => ["loja" => ""]]);
escrever(["recurso" => "arvore", "acao" => "relogios", "grupo" => [$rel["crono"] => $cron_g, $rel["fora"] => 7]]);
// a foto: um PNG de 1x1 (o binário tem de voltar igual)
$png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==";
escrever(["recurso" => "relogio", "acao" => "foto", "id" => $rel["auto"], "foto_base64" => $png]);
escrever(["recurso" => "relogio", "acao" => "foto", "id" => $rel["solar"], "foto_base64" => $png]);
escrever(["recurso" => "relogio", "acao" => "remover_foto", "id" => $rel["solar"]]);

// ---------- o histórico ----------
$lanc = function ($acao, $r, $tipo, $extra = []) use (&$rel) {
    return escrever(array_merge(["recurso" => "lancamento", "acao" => $acao, "relogio_id" => $rel[$r], "tipo" => $tipo], $extra));
};
$lanc("periodo", "smart", "pulso", ["inicio" => dia(6, "08:00"), "fim" => dia(6, "22:00")]);
$lanc("lancar", "smart", "carga", ["valor" => 100, "quando" => dia(6, "07:00")]);
$lanc("periodo", "smart", "pulso", ["inicio" => dia(5, "08:00"), "fim" => dia(5, "22:00")]);
$lanc("lancar", "smart", "carga", ["valor" => 83, "quando" => dia(4, "12:00")]);
$lanc("periodo", "smart", "pulso", ["inicio" => dia(3, "08:00"), "fim" => dia(3, "22:00")]);
$lanc("lancar", "smart", "carga", ["valor" => "71.5", "quando" => dia(2, "12:00"), "medir" => 0]);
$lanc("lancar", "smart", "carga", ["valor" => 64, "quando" => dia(1, "18:00")]);
$lanc("periodo", "auto", "pulso", ["inicio" => dia(2, "08:00"), "fim" => dia(2, "22:00")]);
$lanc("lancar", "auto", "corda", ["quando" => dia(1, "07:00")]);
$lanc("periodo", "auto2", "winder", ["inicio" => dia(4, "08:00"), "fim" => dia(4, "20:00")]);
$lanc("periodo", "auto2", "pulso", ["inicio" => dia(9, "08:00"), "fim" => dia(9, "22:00")]);
$lanc("periodo", "manual", "pulso", ["inicio" => dia(30, "09:00"), "fim" => dia(30, "19:00")]);
$lanc("lancar", "manual", "corda", ["quando" => dia(30, "09:00")]);
$lanc("lancar", "pilha", "pilha", ["quando" => dia(400, "10:00")]);
$lanc("periodo", "solar", "sol", ["inicio" => dia(8, "10:00"), "fim" => dia(8, "16:00")]);
$lanc("iniciar", "solar", "sol", ["quando" => ha_minutos(20)]);
$lanc("iniciar", "auto2", "winder", ["quando" => ha_minutos(30)]);
$lanc("encerrar", "auto2", "winder", ["quando" => ha_minutos(25)]);
$lanc("lancar", "crono", "banho_ultrassom", ["quando" => dia(1, "15:00")]);
$id_errado = $lanc("lancar", "crono", "banho_ultrassom", ["quando" => dia(1, "16:00")]);
escrever(["recurso" => "lancamento", "acao" => "excluir", "id" => $id_errado]);
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["pilha"], "tipo" => "sol"], false);   // o tipo não vale para ele
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["smart"], "tipo" => "carga", "valor" => 50, "quando" => date("Y-m-d H:i", time() + 86400)], false);

// ---------- os critérios ----------
escrever(["recurso" => "criterios", "acao" => "conjunto_criar", "escopo" => "r:" . $rel["crono"], "origem" => "herdado"]);
escrever(["recurso" => "criterios", "acao" => "param_novo", "escopo" => "r:" . $rel["crono"], "nome" => "Estilo", "peso" => 15]);
escrever(["recurso" => "criterios", "acao" => "param_novo", "escopo" => "g:1", "nome" => "Idade", "peso" => 10]);

// ---------- os eventos, a configuração, os usuários ----------
escrever(["recurso" => "config", "acao" => "salvar", "max_sem_uso" => 15, "previsao_limite" => 25, "uso_inicio" => "07:30", "uso_fim" => "22:00"]);
escrever(["recurso" => "config", "acao" => "evento_salvar", "id" => 0, "nome" => "Limpar a coleção", "repeticao" => "semanal", "hora" => "10:00", "dias_semana" => "6"]);
escrever(["recurso" => "config", "acao" => "evento_salvar", "id" => 0, "nome" => "Ajustar a hora do Seiko", "repeticao" => "intervalo", "intervalo_dias" => 10, "hora" => "08:15", "relogio_id" => $rel["auto"]]);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "maria", "senha" => "segredo123"]);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "MARIA", "senha" => "outra12345"], false);

// ---------- o rodízio: a escala inteligente, e o cron ----------
$modos = ler("modos (antes)", ["incluir" => "modos"]);
$inteligente = 0;
foreach ($modos["modos"] ?? [] as $m) {
    if ($m["selecao"] === "inteligente") {
        $inteligente = (int)$m["id"];
    }
}
escrever(["recurso" => "modos", "acao" => "salvar", "id" => $inteligente, "nome" => "Escala inteligente", "selecao" => "inteligente", "escala_dias" => 45,
    "blocos" => [["nome" => "Todo dia", "dias" => [1, 2, 3, 4, 5, 6, 7], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0]]]);
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $inteligente]);
cron();
escrever(["recurso" => "rodizio", "acao" => "usando", "relogio_id" => $rel["crono"]]);
cron();

// ---------- a fotografia ----------
ler("tudo", ["foto" => "nao"]);
foreach (["hoje", "avisos", "criterios", "historico", "previsao", "autonomia", "plano", "eventos", "agenda", "config", "arvore", "cadastros", "usuarios", "migracoes"] as $r) {
    ler($r, ["recurso" => $r]);
}
foreach ($rel as $nome => $id) {
    ler("ficha " . $nome, ["recurso" => "ficha", "relogio" => $id]);
    ler("historico " . $nome, ["recurso" => "historico", "relogio" => $id, "limite" => 1000]);
}
ler("foto auto", ["recurso" => "foto", "relogio" => $rel["auto"]]);
ler("tudo com fotos", ["incluir" => "relogios", "mostrar" => ["relogios" => "id,nome,foto"]]);
ler("calcular", ["recurso" => "calcular", "expressao" => "energia * 2 + HORAS(\"pulso\"; 30)"]);
ler("filtro nome", ["incluir" => "relogios", "f" => ["relogios" => ["nome" => ["contem" => "relogio"]]], "mostrar" => ["relogios" => "id,nome"], "foto" => "nao"]);
ler("filtro energia", ["incluir" => "relogios", "f" => ["relogios" => ["formulas.energia.valor" => ["ate" => 60]]], "ordem" => ["relogios" => "-formulas.energia.valor"], "mostrar" => ["relogios" => "nome,formulas.energia.valor"], "foto" => "nao"]);
ler("cron", ["recurso" => "cron", "situacao" => "todas", "busca" => "plano"]);
ler("cron maiusculas", ["recurso" => "cron", "situacao" => "todas", "busca" => "PLANO"]);
ler("xml", ["recurso" => "usuarios", "formato" => "xml"]);
ler("historico filtrado", ["recurso" => "historico", "de" => dia(10, "00:00"), "ate" => dia(1, "23:59"), "estado" => "pulso,marca", "ordem" => "asc", "limite" => 5, "pagina" => 2]);
ler("busca e paginas", ["incluir" => "relogios", "busca" => ["relogios" => "ÔMEGA"], "mostrar" => ["relogios" => "id,nome"], "foto" => "nao"]);
ler("ordem por nome", ["incluir" => "relogios", "ordem" => ["relogios" => "nome"], "limite" => ["relogios" => 3], "pagina" => ["relogios" => 2], "mostrar" => ["relogios" => "nome"], "foto" => "nao"]);

// ---------- a segunda fase: o que muda a estrutura (exclusões em cascata), os outros modos e os critérios por inteiro ----------
// o login pelo site (HTTP Basic): a senha conferida no banco, e o login sem diferença de maiúsculas
foreach ([["teste", "senha123"], ["TESTE", "senha123"], ["teste", "errada"], ["maria", "outra12345"]] as [$u, $s]) {
    $ch = curl_init($API . "?recurso=usuarios");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $u . ":" . $s, CURLOPT_TIMEOUT => 60]);
    $corpo = (string)curl_exec($ch);
    $foto["leituras"]["login " . $u . ":" . $s] = ["codigo" => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), "resposta" => json_decode($corpo, true)];
}

// os critérios: pesos, faixas, subparâmetros movidos, ordem, conjunto excluído, e no fim o restaurar
$crit = ler("criterios (fase 2)", ["recurso" => "criterios"]);
$param_smart = [];
$nomes_param = [];
$subs = [];
foreach ($crit["conjuntos"] ?? [] as $cj) {
    if ($cj["escopo"] === "g:1") {
        foreach ($cj["parametros"] as $pm) {
            $param_smart[] = (int)$pm["id"];
            $nomes_param[(int)$pm["id"]] = $pm["nome"];
            foreach ($pm["subparametros"] as $sb) {
                $subs[] = (int)$sb["id"];
            }
        }
    }
}
if (count($param_smart) >= 2 && count($subs) >= 2) {
    $pesos = ["recurso" => "criterios", "acao" => "param_pesos", "escopo" => "g:1"];
    foreach ($param_smart as $k => $id) {
        $pesos["peso"][$id] = $k === 0 ? 100 - 5 * (count($param_smart) - 1) : 5;
        $pesos["nome"][$id] = $nomes_param[$id];
    }
    escrever($pesos);
    $sub_novo = escrever(["recurso" => "criterios", "acao" => "sub_novo", "parametro_id" => $param_smart[0], "nome" => "Dias de carga", "variavel" => "dias_de_carga", "peso" => 20]);
    escrever(["recurso" => "criterios", "acao" => "faixas", "sub_id" => $subs[0], "de" => [0, 40, 80], "ate" => [40, 80, ""], "nota" => [10, 60, 100]]);
    escrever(["recurso" => "criterios", "acao" => "sub_medida", "id" => $subs[1], "variavel" => "energia"]);
    escrever(["recurso" => "criterios", "acao" => "sub_mover", "id" => $subs[1], "parametro_id" => $param_smart[1]]);
    escrever(["recurso" => "criterios", "acao" => "ordem", "tipo" => "parametro", "id" => $param_smart[1], "direcao" => "sobe"]);
    foreach (ler("criterios (sub novo)", ["recurso" => "criterios"])["conjuntos"] ?? [] as $cj) {
        foreach ($cj["parametros"] as $pm) {
            foreach ($pm["subparametros"] as $sb) {
                if ($sb["nome"] === "Dias de carga") {
                    $sub_novo = (int)$sb["id"];
                }
            }
        }
    }
    escrever(["recurso" => "criterios", "acao" => "sub_excluir", "id" => $sub_novo]);
    escrever(["recurso" => "criterios", "acao" => "param_pesos", "escopo" => "g:1", "peso" => [$param_smart[0] => 90]], false);
}
escrever(["recurso" => "criterios", "acao" => "conjunto_excluir", "escopo" => "r:" . $rel["crono"]]);
ler("criterios editados", ["recurso" => "criterios"]);
escrever(["recurso" => "criterios", "acao" => "restaurar"]);
escrever(["recurso" => "criterios", "acao" => "param_novo", "escopo" => "g:3", "nome" => "Depois de restaurar", "peso" => 10]);

// os cadastros alterados e excluídos
$cad = ler("cadastros (fase 2)", ["recurso" => "cadastros"]);
$id_de = function ($lista, $ident) use ($cad) {
    foreach ($cad[$lista] ?? [] as $x) {
        if (($x["identificador"] ?? "") === $ident) {
            return (int)$x["id"];
        }
    }
    return 0;
};
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $id_de("campos", "estilo"), "nome" => "Estilo do relógio", "opcoes" => "Social\nEsportivo\nMilitar\nDress"]);
escrever(["recurso" => "campos", "acao" => "ordem", "id" => $id_de("campos", "estilo"), "direcao" => "sobe"]);
escrever(["recurso" => "campos", "acao" => "excluir", "id" => $id_de("campos", "resistencia_agua")]);
escrever(["recurso" => "lancamento_tipos", "acao" => "alterar", "id" => $id_de("lancamento_tipos", "banho_ultrassom"), "nome" => "Banho de ultrassom"]);
escrever(["recurso" => "lancamento_tipos", "acao" => "excluir", "id" => $id_de("lancamento_tipos", "banho_ultrassom")], false);   // tem lançamento
escrever(["recurso" => "formulas", "acao" => "alterar", "id" => $id_de("formulas", "idade_dias"), "expressao" => "ARREDONDA(HOJE() - data_compra; 1)"]);
escrever(["recurso" => "formulas", "acao" => "excluir", "id" => $id_de("formulas", "idade_dias")]);
escrever(["recurso" => "avisos", "acao" => "alterar", "id" => $id_de("avisos", "pulseira"), "antecedencia_dias" => 30, "escala" => "sempre", "agenda" => "sempre"]);
escrever(["recurso" => "avisos", "acao" => "excluir", "id" => $id_de("avisos", "pulseira")]);

// a árvore: mover, excluir um grupo com relógio dentro (o relógio sobe), e excluir relógios (vão junto o histórico, a foto,
// os valores dos campos e o plano)
$sub_g = escrever(["recurso" => "arvore", "acao" => "novo", "nome" => "Vintage", "pai_id" => $cron_g]);
escrever(["recurso" => "arvore", "acao" => "mover", "id" => $sub_g, "pai_id" => 0]);
escrever(["recurso" => "arvore", "acao" => "relogios", "grupo" => [$rel["manual"] => $sub_g]]);
escrever(["recurso" => "arvore", "acao" => "excluir", "id" => $sub_g]);
escrever(["recurso" => "arvore", "acao" => "excluir", "id" => 1]);   // o grupo dos smartwatches: os campos, as fórmulas, os avisos e o relógio sobem
$lancs = ler("lancamentos do auto", ["incluir" => "relogios", "f" => ["relogios" => ["id" => $rel["auto"]]], "mostrar" => ["relogios" => "lancamentos"], "foto" => "nao"]);
$l0 = $lancs["relogios"][0]["lancamentos"][0]["id"] ?? 0;
escrever(["recurso" => "lancamento", "acao" => "alterar", "id" => $l0, "fim" => dia(2, "21:00")]);
escrever(["recurso" => "lancamento", "acao" => "periodo", "relogio_id" => $rel["auto"], "tipo" => "winder", "inicio" => dia(2, "20:00"), "fim" => dia(2, "23:00")], false);   // sobrepõe o pulso
escrever(["recurso" => "relogio", "acao" => "excluir", "id" => $rel["solar"]]);
escrever(["recurso" => "relogio", "acao" => "excluir", "id" => $rel["auto"]]);

// os outros modos: semana e fim de semana (sorteio pela nota), fila com ciclo, aleatório; sortear de novo; a semana que vem
$modos = ler("modos (fase 2)", ["incluir" => "modos"]);
$por_nome = [];
foreach ($modos["modos"] ?? [] as $m) {
    $por_nome[$m["nome"]] = (int)$m["id"];
}
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $por_nome["Semana e fim de semana"] ?? 0]);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
ler("plano ponderado", ["recurso" => "plano"]);
$fila = escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Fila com ciclo", "selecao" => "fifo", "ciclo" => 1,
    "blocos" => [["nome" => "Dias úteis", "dias" => [1, 2, 3, 4, 5], "no_id" => 2, "um_por" => "dia", "relogio_id" => 0],
        ["nome" => "Fim de semana", "dias" => [6, 7], "no_id" => 0, "um_por" => "bloco", "relogio_id" => $rel["smart"]]]]);
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $fila]);
escrever(["recurso" => "rodizio", "acao" => "resortear_hoje"]);
ler("plano fila", ["recurso" => "plano"]);
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $por_nome["Aleatório todo dia"] ?? 0]);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
if (date("N") === "7") {
    escrever(["recurso" => "rodizio", "acao" => "proxima_semana"]);
}
escrever(["recurso" => "modos", "acao" => "excluir", "id" => $fila]);
escrever(["recurso" => "config", "acao" => "evento_excluir", "id" => 1]);
cron();

// a fotografia de novo, depois de tudo. Antes, 2 segundos: o trecho que começou agora (a troca do relógio de hoje) já tem
// duração em qualquer banco, rápido ou lento (lido no mesmo segundo, ele tem 0 s e não aparece na linha do tempo)
sleep(2);
ler("2 tudo", ["foto" => "nao"]);
foreach (["hoje", "avisos", "criterios", "historico", "plano", "eventos", "arvore", "cadastros", "config"] as $r) {
    ler("2 " . $r, ["recurso" => $r]);
}

$texto = json_encode($foto, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
if ($texto === false) {
    fwrite(STDERR, "Não consegui gravar a fotografia: " . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($SAIDA, $texto);
echo count($foto["escritas"]) . " escritas, " . count($foto["leituras"]) . " leituras, " . count($foto["cron"]) . " rodadas do cron\n";
if ($falhas) {
    echo "Escritas recusadas:\n  " . implode("\n  ", $falhas) . "\n";
    exit(1);
}
