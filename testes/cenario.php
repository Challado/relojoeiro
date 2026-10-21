<?php
// O roteiro de paridade: faz pela API o que uma pessoa faz no sistema (cadastra relógios de cada tipo, lança cordas,
// cargas, sol, winder, carregador e pilha, monta a escala, mexe nos critérios, nos eventos e nas fotos, roda o cron) e no fim tira a
// "fotografia" de tudo o que a API devolve. Rodado contra cada banco (MySQL, Postgres, SQLite, Firebird) num banco recém-instalado,
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
// hora no futuro, que a API recusa. O fuso vem da variável RELOGIOS_FUSO ou, sem ela, do config.php do sistema (o de
// RELOGIOS_CONFIG, se houver; senão, o da pasta do sistema)
$fuso = (string)getenv("RELOGIOS_FUSO");
$config_sistema = getenv("RELOGIOS_CONFIG") ?: __DIR__ . "/../config.php";
if ($fuso === "" && is_file($config_sistema)) {
    require $config_sistema;
    $fuso = defined("FUSO") ? trim((string)constant("FUSO")) : "";
}
if ($fuso !== "" && !@date_default_timezone_set($fuso)) {
    fwrite(STDERR, "Fuso desconhecido: " . $fuso . "\n");
    exit(1);
}
// As horas em que o sistema muda de comportamento: a meia-noite (o dia de hoje), as rodadas do cron (06:30 e 20:00), o começo do
// horário de uso (07:00, o da instalação, e 07:30, o que o roteiro configura: a sessão do rodízio de hoje abre ou não), as
// 18:00 (a sessão no sol fecha sozinha) e o fim do uso (22:00). Uma rodada que cruza uma delas sai diferente de outra que roda
// inteira antes; a menos de 3 minutos de uma, o roteiro espera ela passar (os bancos, rodando juntos, esperam todos)
foreach (["00:00", "06:30", "07:00", "07:30", "18:00", "20:00", "22:00"] as $virada) {
    $em = strtotime(date("Y-m-d") . " " . $virada);
    if ($em <= time()) {
        $em = strtotime("+1 day", $em);
    }
    if ($em - time() <= 180) {
        fwrite(STDERR, "Esperando passar das " . $virada . " (" . ($em - time() + 2) . " s)\n");
        sleep(max(0, $em - time() + 2));
    }
}
$falhas = [];
$passo = 0;
// o minuto em que o roteiro começa: as sessões "há N minutos" contam todas dele (e não de agora, que anda de um passo para
// outro: duas que caíssem uma de cada lado da virada do minuto dariam durações diferentes em bancos diferentes)
$MINUTO = intdiv(time(), 60) * 60;
// o instante da última escrita que pode trocar o relógio de hoje (rodízio e modos)
$ultima_troca = 0;

function chamar($metodo, $params = [])
{
    global $API, $TOKEN;
    // LINGUA=en: o roteiro inteiro nesse idioma (com TESTE_ESTRITO=1, a tradução que falta aparece no registro do roteiro)
    if ((string)getenv("LINGUA") !== "") {
        $params["lingua"] = (string)getenv("LINGUA");
    }
    $ch = curl_init($metodo === "GET" ? $API . "?" . http_build_query($params) : $API);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["X-Api-Token: " . $TOKEN], CURLOPT_TIMEOUT => 120]);
    if ($metodo === "POST") {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $corpo = (string)curl_exec($ch);
    if (preg_match('/Falta a tradução[^"]*/u', $corpo, $m) === 1) {
        fwrite(STDERR, "IDIOMA " . ($params["recurso"] ?? "") . " " . ($params["acao"] ?? "") . ": " . stripslashes($m[0]) . "\n");
    }
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tipo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $json = json_decode($corpo, true);
    return ["codigo" => $codigo, "json" => $json, "cru" => $json === null ? (preg_match("//u", $corpo) === 1 ? ["tipo" => $tipo, "texto" => $corpo] : ["tipo" => $tipo, "tamanho" => strlen($corpo), "md5" => md5($corpo)]) : null];
}

// os textos que o roteiro grava (os nomes, as observações, os textos das mensagens...): são dados de quem usa, e saem nas telas
// como foram escritos, em qualquer idioma. A conferência dos textos fixos (testes/texto-fixo.cjs) os separa do texto do sistema.
// Os códigos (só letras minúsculas sem acento, números, _ : -) ficam de fora: "livre" ou "uso" na tela é texto fixo, não dado;
// menos os logins (login, usuario), que são dados mesmo em minúsculas
function guardar_dados($params)
{
    global $dados_escritos;
    array_walk_recursive($params, function ($v, $k) use (&$dados_escritos) {
        if (is_string($v) && !in_array($k, ["recurso", "acao", "token", "senha"], true) && preg_match('/\pL/u', $v) === 1
            && (preg_match('/^[a-z0-9_:.\-]*$/', $v) !== 1 || in_array($k, ["login", "usuario"], true))) {
            $dados_escritos[$v] = true;
        }
    });
}

// uma escrita: guarda a resposta (o ok, a mensagem, os erros) para comparar, e devolve o id (com $chave, essa parte da resposta)
function escrever($params, $espera_ok = true, $chave = null)
{
    global $foto, $falhas, $passo, $ultima_troca;
    $passo++;
    // as escritas que podem trocar o relógio de hoje (rodízio, modos) fecham a sessão do rodízio do anterior agora e abrem a do
    // novo agora: uma sessão que abre e fecha no mesmo segundo some (0 s), e no segundo seguinte fica (1 s). Antes de cada uma,
    // o segundo vira: a sessão de cada relógio que passa por hoje dura ao menos 1 s em qualquer banco, rápido ou lento
    $troca = in_array($params["recurso"], ["rodizio", "modos"], true)
        || ($params["recurso"] === "lancamento" && in_array($params["acao"] ?? "", ["iniciar", "encerrar", "alternar"], true));
    while ($troca && time() <= $ultima_troca) {
        usleep(20000);
    }
    $r = chamar("POST", $params);
    guardar_dados($params);
    if ($troca) {
        $ultima_troca = time();
    }
    $j = $r["json"] ?? [];
    $foto["escritas"][sprintf("%03d", $passo) . " " . $params["recurso"] . "." . $params["acao"]] = ["codigo" => $r["codigo"], "resposta" => $j ?? $r["cru"]];
    if ($espera_ok && !($j["ok"] ?? false)) {
        $falhas[] = "passo " . $passo . " (" . $params["recurso"] . "." . $params["acao"] . "): " . json_encode($j ?? $r["cru"], JSON_UNESCAPED_UNICODE);
    }
    return $chave !== null ? ($j[$chave] ?? null) : (int)($j["id"] ?? 0);
}

function ler($nome, $params)
{
    global $foto, $ultima_troca;
    // nunca no mesmo segundo da última troca: a sessão que ela fechou "agora" tem de acabar num segundo anterior ao da
    // leitura, senão a linha do tempo sai sem o trecho em repouso do fim e o aviso diz "em 5d" num banco e "em 4d 23h" no
    // outro (no CI o SQLite lia no mesmo segundo e o Firebird segundos depois)
    while (time() <= $ultima_troca) {
        usleep(20000);
    }
    $r = chamar("GET", $params);
    $foto["leituras"][$nome] = ["codigo" => $r["codigo"], "resposta" => $r["json"] ?? $r["cru"]];
    return $r["json"];
}

function cron()
{
    global $foto, $ultima_troca;
    while (time() <= $ultima_troca) {
        usleep(20000);
    }
    $saida = [];
    exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__DIR__ . "/../cron.php") . " --forcar -v 2>&1", $saida, $codigo);
    // o cron também põe e tira relógio do pulso agora
    $ultima_troca = time();
    $foto["cron"][] = ["codigo" => $codigo, "saida" => array_slice($saida, 1)];
}

// um horário fixo do relógio, N dias atrás: o cenário sai igual em qualquer hora do dia (um período relativo a agora,
// "há 2 dias e 14 horas", cai num dia ou no outro conforme a hora em que roda, e muda as contagens por dia)
function dia($dias_atras, $hora)
{
    return date("Y-m-d", strtotime("-" . (int)$dias_atras . " days")) . " " . $hora;
}

// um instante de agora há pouco (minutos antes do minuto em que o roteiro começou): o que tem de estar no passado mesmo
// rodando logo depois da meia-noite
function ha_minutos($minutos)
{
    global $MINUTO;
    return date("Y-m-d H:i", $MINUTO - (int)$minutos * 60);
}

$foto = ["escritas" => [], "leituras" => [], "cron" => []];
$dados_escritos = [];

// os grupos do conjunto inicial (o modelo.json), pelo nome: os códigos dependem do banco (as coleções de cada pessoa
// dividem a mesma numeração). G(1) a G(8): Smartwatch, Tradicional, Mecânico, Automático, Corda manual, Quartzo, Pilha, Solar
$GRUPOS = [];
foreach ((chamar("GET", ["recurso" => "arvore"])["json"]["grupos"] ?? []) as $g) {
    $GRUPOS[$g["caminho"]] = (int)$g["id"];
}
function G($n)
{
    global $GRUPOS;
    $caminhos = [1 => "Smartwatch", 2 => "Tradicional", 3 => "Tradicional › Mecânico", 4 => "Tradicional › Mecânico › Automático", 5 => "Tradicional › Mecânico › Corda manual",
        6 => "Tradicional › Quartzo", 7 => "Tradicional › Quartzo › Pilha", 8 => "Tradicional › Quartzo › Solar"];
    if (!isset($GRUPOS[$caminhos[$n]])) {
        fwrite(STDERR, "O grupo " . $caminhos[$n] . " não está na árvore (" . implode(", ", array_keys($GRUPOS)) . ")\n");
        exit(1);
    }
    return $GRUPOS[$caminhos[$n]];
}

// ---------- a árvore e os cadastros ----------
$cron_g = escrever(["recurso" => "arvore", "acao" => "novo", "nome" => "Cronógrafos", "pai_id" => G(3)]);
escrever(["recurso" => "arvore", "acao" => "novo", "nome" => "cronógrafos", "pai_id" => G(3)], false);   // o mesmo nome, outra caixa
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
$rel["smart"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Xiaomi Band 8", "no_id" => G(1), "disponivel" => 1,
    "valores" => ["preferencia" => 71, "autonomia_dias" => 14, "data_compra" => date("Y-m-d", strtotime("-200 days")), "valor_compra" => "249.90", "loja" => "AliExpress", "estilo" => "Esportivo"]]);
$rel["auto"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Seiko 5 Sports", "no_id" => G(4), "disponivel" => 1,
    "valores" => ["preferencia" => 88, "reserva_horas" => 41, "data_compra" => date("Y-m-d", strtotime("-500 days")), "valor_compra" => "1890", "loja" => "Loja Física",
        "data_revisao" => date("Y-m-d", strtotime("-50 months")), "intervalo_revisao_meses" => 48, "resistencia_agua" => 100, "garantia_ate" => date("Y-m-d", strtotime("+20 days"))]]);
$rel["auto2"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Orient Bambino", "no_id" => G(4), "disponivel" => 1,
    "valores" => ["preferencia" => 64, "reserva_horas" => 40, "corda_manual" => 0, "carga_winder_horas" => 6, "carga_winder_reserva" => 30, "valor_compra" => "1200.50"]]);
$rel["manual"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Poljot corda manual", "no_id" => G(5), "disponivel" => 1,
    "valores" => ["preferencia" => 57, "reserva_horas" => 36]]);
$rel["pilha"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Casio F-91W", "no_id" => G(7), "disponivel" => 1,
    "valores" => ["preferencia" => 45, "data_pilha" => date("Y-m-d", strtotime("-23 months")), "vida_pilha_meses" => 24, "valor_compra" => "99"]]);
$rel["solar"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Citizen Eco-Drive", "no_id" => G(8), "disponivel" => 1,
    "valores" => ["preferencia" => 79, "reserva_dias" => 180, "carga_sol_horas" => 10, "data_compra" => date("Y-m-d", strtotime("-10 days"))]]);
$rel["crono"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Relógio Ácido Ômega", "no_id" => $cron_g, "disponivel" => 1,
    "valores" => ["preferencia" => 93, "reserva_horas" => 50]]);
$rel["fora"] = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "No conserto", "no_id" => G(6), "disponivel" => 0, "valores" => ["preferencia" => 30]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto"], "valores" => ["preferencia" => "abc", "observacao" => "Presente do avô — ótimo"]], false);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["smart"], "valores" => ["loja" => ""]]);
escrever(["recurso" => "arvore", "acao" => "relogios", "grupo" => [$rel["crono"] => $cron_g, $rel["fora"] => G(7)]]);
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
$lanc("lancar", "smart", "carga", ["valor" => 64, "quando" => dia(1, "11:00")]);
// a marcação da leitura: "em uso" quando o sistema tem ele fora do pulso não bate: a API pergunta (confirmar: true, sem
// gravar) e só grava com confirmar=1, como a pessoa marcou; "em repouso" fora do pulso bate (medir=0: fica só no histórico);
// estado errado recusa. A marcação fica gravada na leitura (estado) e o alterar muda a marcação
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["smart"], "tipo" => "carga", "valor" => 63, "quando" => dia(1, "13:00"), "estado" => "uso"], false);
$leitura_uso = $lanc("lancar", "smart", "carga", ["valor" => 63, "quando" => dia(1, "13:00"), "estado" => "uso", "confirmar" => 1]);
$lanc("lancar", "smart", "carga", ["valor" => 62, "quando" => dia(1, "14:30"), "estado" => "repouso", "medir" => 0]);
// só o instante da leitura conta: no pulso das 14:40 às 14:50 (depois da leitura anterior) e fora dele às 14:55, "em repouso"
// bate e grava sem perguntar (antes, o pulso desde a leitura anterior fazia a API perguntar). Ontem, antes das 18:30: o
// carregador aberto há 5h30 (logo abaixo), de madrugada, começa ontem depois das 18:30 e não esbarra nesse pulso (com o pulso
// às 21:40, o roteiro rodado entre 00:00 e 03:20 tinha o carregador recusado)
$lanc("periodo", "smart", "pulso", ["inicio" => dia(1, "14:40"), "fim" => dia(1, "14:50")]);
$lanc("lancar", "smart", "carga", ["valor" => 62, "quando" => dia(1, "14:55"), "estado" => "repouso", "medir" => 0]);
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["smart"], "tipo" => "carga", "valor" => 62, "estado" => "pulso"], false);
escrever(["recurso" => "lancamento", "acao" => "alterar", "id" => $leitura_uso, "estado" => "repouso"]);
escrever(["recurso" => "lancamento", "acao" => "alterar", "id" => $leitura_uso, "estado" => "uso"]);
escrever(["recurso" => "lancamento", "acao" => "alterar", "id" => $leitura_uso, "estado" => "dormindo"], false);
// uma medição sai da conta e volta (o botão da tabela Medições do gasto), sem apagar nada; id e usada errados recusam
$med = chamar("GET", ["recurso" => "ficha", "relogio" => $rel["smart"]])["json"]["relogio"]["medicoes"] ?? [];
$med_id = (int)($med[count($med) - 1]["id"] ?? 0);
escrever(["recurso" => "lancamento", "acao" => "medicao", "id" => $med_id, "usada" => 0]);
ler("medições com uma fora da conta", ["recurso" => "ficha", "relogio" => $rel["smart"]]);
escrever(["recurso" => "lancamento", "acao" => "medicao", "id" => $med_id, "usada" => 1]);
escrever(["recurso" => "lancamento", "acao" => "medicao", "id" => $med_id, "usada" => 2], false);
escrever(["recurso" => "lancamento", "acao" => "medicao", "id" => 999999, "usada" => 1], false);
// os cinco motores do gasto, pela Configuração da coleção (vale por todos), e os outros níveis: o campo do relógio e o padrão
// do campo (o grupo); vazio em tudo, o original (horas). Valores errados recusam; o mecânico, sem leitura, não muda nada
foreach (["horas", "robusto", "marcacao", "cadastro", "formulas"] as $motor) {
    escrever(["recurso" => "config", "acao" => "salvar", "gasto_motor" => $motor]);
    ler("gasto pelo motor " . $motor, ["recurso" => "ficha", "relogio" => $rel["smart"]]);
}
escrever(["recurso" => "config", "acao" => "salvar", "gasto_motor" => "chute"], false);
escrever(["recurso" => "config", "acao" => "salvar", "gasto_motor" => ""]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["smart"], "valores" => ["gasto_motor" => "cadastro"]]);
ler("gasto pelo motor do relógio", ["recurso" => "ficha", "relogio" => $rel["smart"]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["smart"], "valores" => ["gasto_motor" => "chute"]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["smart"], "valores" => ["gasto_motor" => ""]]);
$campo_motor = 0;
foreach (chamar("GET", ["recurso" => "cadastros"])["json"]["campos"] ?? [] as $c) {
    if ($c["identificador"] === "gasto_motor") {
        $campo_motor = (int)$c["id"];
    }
}
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $campo_motor, "padrao" => "marcacao"]);
ler("gasto pelo motor do grupo", ["recurso" => "ficha", "relogio" => $rel["smart"]]);
ler("gasto do mecanico com motor", ["recurso" => "ficha", "relogio" => $rel["manual"]]);
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $campo_motor, "padrao" => ""]);
ler("gasto pelo motor padrao", ["recurso" => "ficha", "relogio" => $rel["smart"]]);
// contar por leituras (as últimas N de cada tipo) e por dias; limites errados recusam
escrever(["recurso" => "config", "acao" => "salvar", "medicao_base" => "leituras", "medicao_janela_leituras" => 2]);
ler("gasto pelas ultimas leituras", ["recurso" => "ficha", "relogio" => $rel["smart"]]);
escrever(["recurso" => "config", "acao" => "salvar", "medicao_base" => "semanas"], false);
escrever(["recurso" => "config", "acao" => "salvar", "medicao_janela_leituras" => 1], false);
escrever(["recurso" => "config", "acao" => "salvar", "medicao_base" => "dias", "medicao_janela_leituras" => 20]);
// as funções das medições pela marcação, no motor das fórmulas
ler("calcular pecas da marcacao", ["recurso" => "calcular", "relogio" => $rel["smart"], "expressao" => "LEITURAS(\"uso\") * 1000 + LEITURAS(\"repouso\"; 30) * 100 + ARREDONDA(QUEDA(\"uso\"); 1)"]);
ler("calcular taxas da marcacao", ["recurso" => "calcular", "relogio" => $rel["smart"], "expressao" => "ARREDONDA(PADRAO(MEDIA_TAXA(\"uso\"); 0) + PADRAO(MEDIANA_TAXA(\"uso\"; 0); 0) * 100 + PADRAO(ULTIMA_TAXA(\"repouso\"); 0) * 10000 + PADRAO(DIAS_MEDIDOS(\"repouso\"); 0); 2)"]);
ler("calcular funcao errada", ["recurso" => "calcular", "expressao" => "ULTIMA_TAXA(\"uso\"; 3)"]);
$lanc("periodo", "auto", "pulso", ["inicio" => dia(2, "08:00"), "fim" => dia(2, "22:00")]);
// a corda ontem às 15:06: a reserva (41 h) acaba amanhã às 08:06, e hoje fica entre 8 h e 32 h a qualquer hora (perto de zero,
// os segundos entre um banco e outro passariam da folga)
$lanc("lancar", "auto", "corda", ["quando" => dia(1, "15:06")]);
$lanc("periodo", "auto2", "winder", ["inicio" => dia(4, "08:00"), "fim" => dia(4, "20:00")]);
$lanc("periodo", "auto2", "pulso", ["inicio" => dia(9, "08:00"), "fim" => dia(9, "22:00")]);
// há 29 dias, e não 30: as fórmulas de uso olham os últimos 30 dias (HORAS("pulso"; 30), CONTAR("pulso"; 30)), e essa borda
// (agora menos 30 dias) passaria por dentro do período conforme a hora do dia
$lanc("periodo", "manual", "pulso", ["inicio" => dia(29, "09:00"), "fim" => dia(29, "19:00")]);
$lanc("lancar", "manual", "corda", ["quando" => dia(29, "09:00")]);
$lanc("lancar", "pilha", "pilha", ["quando" => dia(400, "10:00")]);
$lanc("periodo", "solar", "sol", ["inicio" => dia(8, "10:00"), "fim" => dia(8, "16:00")]);
$lanc("iniciar", "solar", "sol", ["quando" => ha_minutos(20)]);
// períodos do mesmo tipo que se encostam ou se cobrem viram um só (de 08:00 a 20:00), num tipo de sessão que não entra
// em conta nenhuma (o pulso ou o winder mudariam a energia e a nota, e com elas o relógio que o rodízio escolhe hoje)
escrever(["recurso" => "lancamento_tipos", "acao" => "novo", "identificador" => "estojo", "nome" => "No estojo", "formato" => "sessao", "no_id" => 0]);
$lanc("periodo", "crono", "estojo", ["inicio" => dia(15, "08:00"), "fim" => dia(15, "12:00")]);
$lanc("periodo", "crono", "estojo", ["inicio" => dia(15, "12:00"), "fim" => dia(15, "18:00")]);
$lanc("periodo", "crono", "estojo", ["inicio" => dia(15, "10:00"), "fim" => dia(15, "20:00")]);
// um período que encosta no que está aberto (esqueceu de marcar antes): os dois viram um só, aberto desde o começo do período
$lanc("iniciar", "crono", "estojo", ["quando" => ha_minutos(20)]);
$lanc("periodo", "crono", "estojo", ["inicio" => ha_minutos(50), "fim" => ha_minutos(20)]);
// e sai do estojo (aberto, o estado dele mudaria, e com ele o relógio que o rodízio escolhe hoje)
$lanc("encerrar", "crono", "estojo", ["quando" => ha_minutos(10)]);
// o Orient no winder de manhã (de 400 a 31 minutos atrás): chega com reserva de sobra ao fim do cenário, quando o sorteio
// o põe no pulso por alguns segundos (quanto, depende da velocidade do banco). Com a reserva perto de zero, esses segundos
// mudavam a carga em 25% de um banco para outro; longe do zero, a diferença é de milésimos
$lanc("periodo", "auto2", "winder", ["inicio" => ha_minutos(400), "fim" => ha_minutos(31)]);
$lanc("iniciar", "auto2", "winder", ["quando" => ha_minutos(30)]);
$lanc("encerrar", "auto2", "winder", ["quando" => ha_minutos(25)]);
// o carregador: um período que já passou e um aberto agora (o estado vira "No carregador" e o aviso Carregar some)
$lanc("periodo", "smart", "carregador", ["inicio" => dia(7, "20:00"), "fim" => dia(7, "23:00")]);
// há 5h30 (cheio: a carga fica em 100, igual em qualquer banco). A meia hora: a linha do tempo junta esta sessão com a que o
// alternar abre logo depois (são seguidas), e a duração delas é lida com alguns segundos de diferença em cada banco; em 5h
// redondas, ela saía "5h" num e "5h 1min" noutro; em 5h30, "5h 30min" e "5h 31min" (só números num texto)
$lanc("iniciar", "smart", "carregador", ["quando" => ha_minutos(330)]);
$lanc("lancar", "crono", "banho_ultrassom", ["quando" => dia(1, "15:00")]);
$id_errado = $lanc("lancar", "crono", "banho_ultrassom", ["quando" => dia(1, "16:00")]);
escrever(["recurso" => "lancamento", "acao" => "excluir", "id" => $id_errado, "confirmar" => 1]);
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["pilha"], "tipo" => "sol"], false);   // o tipo não vale para ele
escrever(["recurso" => "lancamento", "acao" => "iniciar", "relogio_id" => $rel["pilha"], "tipo" => "carregador"], false);   // só onde vale a leitura de carga
// alternar: fecha a sessão aberta e abre de novo (um botão só no celular); não vale para o que não é sessão. As duas no mesmo
// instante, há 90 minutos (com "agora", elas caíam às vezes em segundos diferentes e a linha do tempo ganhava 1 s de repouso
// entre elas)
$lanc("alternar", "smart", "carregador", ["quando" => ha_minutos(90)]);
$lanc("alternar", "smart", "carregador", ["quando" => ha_minutos(90)]);
escrever(["recurso" => "lancamento", "acao" => "alternar", "relogio_id" => $rel["smart"], "tipo" => "carga"], false);
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["smart"], "tipo" => "carga", "valor" => 50, "quando" => date("Y-m-d H:i", time() + 86400)], false);

// ---------- os critérios ----------
escrever(["recurso" => "criterios", "acao" => "conjunto_criar", "escopo" => "r:" . $rel["crono"], "origem" => "herdado"]);
escrever(["recurso" => "criterios", "acao" => "param_novo", "escopo" => "r:" . $rel["crono"], "nome" => "Estilo", "peso" => 15]);
escrever(["recurso" => "criterios", "acao" => "param_novo", "escopo" => "g:" . G(1), "nome" => "Idade", "peso" => 10]);

// ---------- os eventos, a configuração, os usuários ----------
escrever(["recurso" => "config", "acao" => "salvar", "max_sem_uso" => 15, "previsao_limite" => 25, "uso_inicio" => "07:30", "uso_fim" => "22:00"]);
escrever(["recurso" => "config", "acao" => "evento_salvar", "id" => 0, "nome" => "Limpar a coleção", "repeticao" => "semanal", "hora" => "10:00", "dias_semana" => "6"]);
escrever(["recurso" => "config", "acao" => "evento_salvar", "id" => 0, "nome" => "Ajustar a hora do Seiko", "repeticao" => "intervalo", "intervalo_dias" => 10, "hora" => "08:15", "relogio_id" => $rel["auto"]]);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "maria", "senha" => "Segredo@123", "senha_confirmacao" => "Segredo@123"]);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "MARIA", "senha" => "Outra@12345"], false);
// o login e o e-mail únicos: a conta que existe não é criada de novo (nem com outra caixa ou com espaços), e o salvar não
// troca a senha dela (isso é a ação senha); o login de uma conta não pode ser o e-mail de outra; o mesmo e-mail, em
// qualquer caminho (o master criando, o master mudando, o cadastro aberto), é recusado
escrever(["recurso" => "conta", "acao" => "alterar", "email" => "dono@exemplo.com"]);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => " Maria ", "senha" => "Outra@12345", "senha_confirmacao" => "Outra@12345"], false);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "DONO@exemplo.com", "senha" => "Outra@12345", "senha_confirmacao" => "Outra@12345"], false);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "outra", "senha" => "Outra@12345", "senha_confirmacao" => "Outra@12345", "email" => "Dono@Exemplo.com"], false);
escrever(["recurso" => "usuarios", "acao" => "alterar", "login" => "maria", "email" => "dono@EXEMPLO.com"], false);
escrever(["recurso" => "usuarios", "acao" => "alterar", "login" => "maria", "novo_login" => "Dono@Exemplo.com"], false);
escrever(["recurso" => "usuarios", "acao" => "alterar", "login" => "maria", "novo_login" => "TESTE"], false);
escrever(["recurso" => "cadastro", "acao" => "criar_conta", "login" => "nova", "senha" => "Outra@12345", "senha_confirmacao" => "Outra@12345", "email" => "DONO@exemplo.com", "opcao" => "modelo"], false);
escrever(["recurso" => "cadastro", "acao" => "criar_conta", "login" => "Dono@Exemplo.com", "senha" => "Outra@12345", "senha_confirmacao" => "Outra@12345", "email" => "nova@exemplo.com", "opcao" => "modelo"], false);
escrever(["recurso" => "conta", "acao" => "alterar", "email" => ""]);
// a troca da senha de outra conta pelo master (a ação senha), com a força da Configuração; a conta que não existe, recusada
escrever(["recurso" => "usuarios", "acao" => "senha", "login" => "maria", "senha" => "abc", "senha_confirmacao" => "abc"], false);
escrever(["recurso" => "usuarios", "acao" => "senha", "login" => "maria", "senha" => "Segredo@123", "senha_confirmacao" => "Segredo@123"]);
escrever(["recurso" => "usuarios", "acao" => "senha", "login" => "ninguem", "senha" => "Segredo@123", "senha_confirmacao" => "Segredo@123"], false);
// a força da senha: a fraca e a confirmação diferente são recusadas; a conferência (sem gravar) diz cada regra e a força;
// a política mais exigente na Configuração (só o master) recusa a senha que passava, e volta ao padrão
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "fraco", "senha" => "abc"], false);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "fraco", "senha" => "Forte@2026x", "senha_confirmacao" => "Forte@2026y"], false);
$conf_s = escrever(["recurso" => "cadastro", "acao" => "conferir_senha", "senha" => "abc", "senha_confirmacao" => "abd"], true, "conferencia");
if (!is_array($conf_s) || $conf_s["ok"] !== false || count(array_filter($conf_s["regras"], function ($r) { return !$r["ok"]; })) !== 5) {
    $falhas[] = "senha: a conferência de \"abc\" deu " . json_encode($conf_s, JSON_UNESCAPED_UNICODE);
}
escrever(["recurso" => "config", "acao" => "salvar", "senha_tamanho" => 3], false);
escrever(["recurso" => "config", "acao" => "salvar", "senha_tamanho" => 8, "senha_numeros" => 6, "senha_simbolos" => 6], false);
escrever(["recurso" => "config", "acao" => "salvar", "senha_tamanho" => 12, "senha_numeros" => 2]);
escrever(["recurso" => "usuarios", "acao" => "salvar", "login" => "fraco", "senha" => "Segredo@123"], false);
escrever(["recurso" => "config", "acao" => "salvar", "senha_tamanho" => 8, "senha_numeros" => 1]);
ler("politica da senha no cadastro", ["recurso" => "cadastro"]);
// o servidor de e-mail próprio (Minha conta): sem ele não há o que testar; sem servidor, com a segurança errada ou sem
// remetente, recusado; gravado, o remetente vazio vira o usuário, a senha não volta (só senha_definida) e a senha vazia
// mantém a gravada; os destinos dizem que saem por ele; e volta ao do sistema
escrever(["recurso" => "conta", "acao" => "smtp_testar", "para" => ""], false);
escrever(["recurso" => "conta", "acao" => "smtp_salvar", "proprio" => 1, "host" => ""], false);
escrever(["recurso" => "conta", "acao" => "smtp_salvar", "proprio" => 1, "host" => "smtp.exemplo.invalid", "seguranca" => "starttls"], false);
escrever(["recurso" => "conta", "acao" => "smtp_salvar", "proprio" => 1, "host" => "smtp.exemplo.invalid", "porta" => 70000], false);
escrever(["recurso" => "conta", "acao" => "smtp_salvar", "proprio" => 1, "host" => "smtp.exemplo.invalid", "usuario" => "eu", "remetente" => "sem arroba"], false);
$smtp = escrever(["recurso" => "conta", "acao" => "smtp_salvar", "proprio" => 1, "host" => "SMTP.Exemplo.invalid", "porta" => "", "seguranca" => "tls", "usuario" => "eu@exemplo.com", "senha" => "segredo-do-smtp", "remetente" => ""], true, "smtp");
escrever(["recurso" => "conta", "acao" => "smtp_salvar", "proprio" => 1, "host" => "smtp.exemplo.invalid", "porta" => 465, "seguranca" => "ssl", "usuario" => "eu@exemplo.com", "senha" => "", "remetente" => "relogios@exemplo.com"]);
$conta_smtp = ler("conta com o servidor de e-mail próprio", ["recurso" => "conta"]);
if (!is_array($smtp) || $smtp["remetente"] !== "eu@exemplo.com" || $smtp["host"] !== "smtp.exemplo.invalid" || (int)$smtp["porta"] !== 587
    || ($conta_smtp["smtp"]["senha_definida"] ?? null) !== true || ($conta_smtp["smtp"]["porta"] ?? null) !== 465 || strpos(json_encode($conta_smtp), "segredo-do-smtp") !== false) {
    $falhas[] = "smtp próprio: " . json_encode([$smtp, $conta_smtp["smtp"] ?? null], JSON_UNESCAPED_UNICODE);
}
ler("destinos com o servidor de e-mail próprio", ["recurso" => "destinos"]);
escrever(["recurso" => "conta", "acao" => "smtp_salvar", "proprio" => 0]);
// o modo da interface: desconhecido é recusado; no básico, o menu traz o que as telas deixam de fora (as páginas e as partes
// do avançado); de volta ao avançado, nada fica de fora
escrever(["recurso" => "conta", "acao" => "alterar", "nivel" => "medio"], false);
escrever(["recurso" => "conta", "acao" => "alterar", "nivel" => "basico"]);
$menu_b = ler("menu no modo básico", ["recurso" => "conta", "parte" => "menu"]);
escrever(["recurso" => "conta", "acao" => "alterar", "nivel" => "avancado"]);
$menu_a = ler("menu no modo avançado", ["recurso" => "conta", "parte" => "menu"]);
if (($menu_b["nivel"] ?? "") !== "basico" || !in_array("criterios.php", $menu_b["ocultos"] ?? [], true) || !in_array("previsao.conta", $menu_b["ocultos"] ?? [], true)
    || ($menu_a["nivel"] ?? "") !== "avancado" || ($menu_a["ocultos"] ?? null) !== []) {
    $falhas[] = "modo da interface: " . json_encode([$menu_b["nivel"] ?? null, $menu_b["ocultos"] ?? null, $menu_a["nivel"] ?? null, $menu_a["ocultos"] ?? null]);
}

// ---------- os destinos e as agendas (desligados: nada sai do servidor de teste; o link ICS é sorteado, e fica de fora) ----------
escrever(["recurso" => "destinos", "acao" => "salvar", "id" => 0, "tipo" => "webhook", "nome" => "Telegram de teste", "ativo" => 0, "metodo" => "POST", "url" => "https://exemplo.invalid/enviar",
    "formato" => "json", "campo_nome" => ["chat_id", "text"], "campo_valor" => ["123", "{mensagem}"], "cabecalho_nome" => ["Authorization"], "cabecalho_valor" => ["Bearer segredo"],
    "envia" => ["dia", "vespera", "corda"], "modelo_corda" => "Corda no {relogio}!"]);
$dest_email = escrever(["recurso" => "destinos", "acao" => "salvar", "id" => 0, "tipo" => "email", "nome" => "E-mail de teste", "ativo" => 0, "para" => "teste@exemplo.com"]);
escrever(["recurso" => "destinos", "acao" => "salvar", "id" => 0, "tipo" => "webpush", "nome" => "Celular", "ativo" => 0, "usuario_id" => 1]);
escrever(["recurso" => "destinos", "acao" => "salvar", "id" => 0, "tipo" => "webhook", "nome" => "Sem endereço", "url" => "ftp://x"], false);
escrever(["recurso" => "agendas", "acao" => "salvar", "id" => 0, "tipo" => "caldav", "nome" => "CalDAV de teste", "ativa" => 0, "url" => "https://exemplo.invalid/cal/", "usuario" => "eu", "senha" => "segredo"]);
escrever(["recurso" => "agendas", "acao" => "salvar", "id" => 0, "tipo" => "outlook", "nome" => "Outlook de teste", "ativa" => 0]);
escrever(["recurso" => "agendas", "acao" => "salvar", "id" => 0, "tipo" => "google", "nome" => "Google de teste", "ativa" => 0, "calendario_id" => "teste@group.calendar.google.com"]);

// ---------- o rodízio: a escala inteligente, e o cron ----------
$modos = ler("modos (antes)", ["incluir" => "modos"]);
$inteligente = 0;
foreach ($modos["modos"] ?? [] as $m) {
    if ($m["selecao"] === "inteligente") {
        $inteligente = (int)$m["id"];
    }
}
$em = function ($dias) { return date("Y-m-d", strtotime("+" . (int)$dias . " days")); };
// os tipos de modo: um por semana com duas semanas à frente, por dia da semana (um bloco por dia, um dia livre), um
// personalizado pelo roteiro (e um roteiro com erro, recusado), e o teste do roteiro (recurso=roteiro)
$semana2 = escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Semana dupla", "tipo" => "semana", "selecao" => "inteligente", "semanas" => 2,
    "blocos" => [["nome" => "A semana", "dias" => [1, 2, 3, 4, 5, 6, 7], "no_id" => G(2), "um_por" => "bloco", "relogio_id" => 0]]]);
escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Semana torta", "tipo" => "semana", "semanas" => 1,
    "blocos" => [["nome" => "Dias úteis", "dias" => [1, 2, 3, 4, 5], "no_id" => 0, "um_por" => "bloco", "relogio_id" => 0]]], false);
$por_dia = escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Cada dia o seu", "tipo" => "dia_semana", "selecao" => "fifo", "semanas" => 1,
    "blocos" => [["nome" => "Segunda", "dias" => [1], "no_id" => G(1), "um_por" => "dia", "relogio_id" => 0], ["nome" => "Terça", "dias" => [2], "no_id" => G(2), "um_por" => "dia", "relogio_id" => 0],
        ["nome" => "Quarta", "dias" => [3], "no_id" => 0, "um_por" => "dia", "relogio_id" => $rel["pilha"]], ["nome" => "Quinta", "dias" => [4], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0],
        ["nome" => "Sexta", "dias" => [5], "no_id" => G(1), "um_por" => "dia", "relogio_id" => 0], ["nome" => "Domingo", "dias" => [7], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0]]]);
$roteiro = "util = DIA_SEMANA() <= 5\ncandidato = SE(util; NO_GRUPO(\"Tradicional\"); 1)\npeso = SE(dias_sem_uso > 3; 100; 1)\ndias = SE(NO_GRUPO(\"Smartwatch\"); 2; 1)";
$personalizado = escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Meu roteiro", "tipo" => "personalizado", "semanas" => 1, "roteiro" => $roteiro]);
escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Roteiro errado", "tipo" => "personalizado", "roteiro" => "candidato = NO_GRUPO(\"Tradicional\"\npeso = peso + 1\nnota = inexistente"], false);
ler("roteiro", ["recurso" => "roteiro", "roteiro" => $roteiro, "dia" => $em(1)]);
ler("roteiro do modo", ["recurso" => "roteiro", "modo" => $por_dia, "dia" => $em(2)]);
ler("roteiro errado", ["recurso" => "roteiro", "roteiro" => "candidato = (\npeso = peso + 1\nnota = inexistente", "dia" => $em(3)]);
ler("roteiro em dia inválido", ["recurso" => "roteiro", "roteiro" => "candidato = 1", "dia" => "2000-01-01"]);
foreach ([$semana2, $por_dia, $personalizado] as $m_t) {
    escrever(["recurso" => "modos", "acao" => "ativar", "id" => $m_t]);
    escrever(["recurso" => "rodizio", "acao" => "resortear"]);
    ler("plano do modo " . $m_t, ["recurso" => "plano"]);
}
escrever(["recurso" => "rodizio", "acao" => "modo", "modo" => $semana2, "selecao" => "ponderado", "max_sem_uso" => 15]);
escrever(["recurso" => "rodizio", "acao" => "proxima_semana"]);
ler("cadastros com os modos", ["recurso" => "cadastros"]);
// a semana começando no domingo (a Configuração, semana_inicio): o horizonte, a próxima semana e o fim de semana como um
// trecho só (sábado e domingo, um relógio para o bloco); um valor que não existe é recusado; depois, de volta à segunda
escrever(["recurso" => "config", "acao" => "salvar", "semana_inicio" => "sabado"], false);
escrever(["recurso" => "config", "acao" => "salvar", "semana_inicio" => "domingo"]);
$fds_d = escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Fim de semana inteiro", "tipo" => "semana_fds", "selecao" => "inteligente", "semanas" => 2,
    "blocos" => [["nome" => "Segunda a sexta", "dias" => [1, 2, 3, 4, 5], "no_id" => G(1), "um_por" => "bloco", "relogio_id" => 0],
        ["nome" => "Sábado e domingo", "dias" => [6, 7], "no_id" => G(2), "um_por" => "bloco", "relogio_id" => 0]]]);
foreach ([$semana2, $fds_d] as $m_t) {
    escrever(["recurso" => "modos", "acao" => "ativar", "id" => $m_t]);
    escrever(["recurso" => "rodizio", "acao" => "resortear"]);
    ler("plano do modo " . $m_t . " com a semana no domingo", ["recurso" => "plano", "dias" => 21]);
}
escrever(["recurso" => "rodizio", "acao" => "proxima_semana"]);
ler("hoje com a semana no domingo", ["recurso" => "hoje", "incluir" => "semana_inicio,proxima_semana,proximos"]);
ler("cadastros com a semana no domingo", ["recurso" => "cadastros", "incluir" => "semana_inicio,modos"]);
escrever(["recurso" => "config", "acao" => "salvar", "semana_inicio" => "segunda"]);
// o intervalo de atualização das páginas: negativo, fração e acima de um dia não; 0 desliga; o menu de cada página leva ele
escrever(["recurso" => "config", "acao" => "salvar", "atualizar_segundos" => "-5"], false);
escrever(["recurso" => "config", "acao" => "salvar", "atualizar_segundos" => "1.5"], false);
escrever(["recurso" => "config", "acao" => "salvar", "atualizar_segundos" => "86401"], false);
escrever(["recurso" => "config", "acao" => "salvar", "atualizar_segundos" => "0"]);
ler("menu sem atualizar sozinho", ["recurso" => "conta", "parte" => "menu"]);
escrever(["recurso" => "config", "acao" => "salvar", "atualizar_segundos" => "30"]);
ler("menu atualizando de 30 em 30 s", ["recurso" => "conta", "parte" => "menu"]);
// o reloginho da barra: um modo desconhecido não; aleatório (um sorteio por hora, pode repetir) e sem repetição (os 24
// embaralhados); a ordem do dia vai no menu, a mesma em todo banco (sai da coleção e do dia), e volta ao normal
escrever(["recurso" => "config", "acao" => "salvar", "relojinho_modo" => "girando"], false);
escrever(["recurso" => "config", "acao" => "salvar", "relojinho_modo" => "aleatorio"]);
ler("menu com o reloginho aleatório", ["recurso" => "conta", "parte" => "menu"]);
escrever(["recurso" => "config", "acao" => "salvar", "relojinho_modo" => "sem_repeticao"]);
ler("menu com o reloginho sem repetição", ["recurso" => "conta", "parte" => "menu"]);
ler("config com o reloginho sem repetição", ["recurso" => "config"]);
escrever(["recurso" => "config", "acao" => "salvar", "relojinho_modo" => ""]);
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $semana2]);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
// o Sortear de novo com dias à mão e livres no caminho: sem sobrescrever, eles ficam (e a mensagem conta); com
// sobrescrever=1, sai tudo do começo do sorteio em diante e o modo monta de novo (também na próxima semana)
escrever(["recurso" => "rodizio", "acao" => "manual", "de" => $em(2), "ate" => $em(4), "dias" => [$em(2) => "livre", $em(3) => "livre", $em(4) => (string)$rel["pilha"]]]);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
ler("plano com os dias guardados", ["recurso" => "plano", "dias" => 10]);
escrever(["recurso" => "rodizio", "acao" => "resortear", "sobrescrever" => "1"]);
ler("plano sobrescrito", ["recurso" => "plano", "dias" => 10]);
escrever(["recurso" => "rodizio", "acao" => "manual", "de" => $em(8), "ate" => $em(9), "dias" => [$em(8) => "livre", $em(9) => (string)$rel["pilha"]]]);
escrever(["recurso" => "rodizio", "acao" => "proxima_semana", "sobrescrever" => "1"]);
ler("próxima semana sobrescrita", ["recurso" => "plano", "dias" => 16]);
escrever(["recurso" => "modos", "acao" => "salvar", "id" => $inteligente, "nome" => "Escala inteligente", "selecao" => "inteligente", "escala_dias" => 45,
    "blocos" => [["nome" => "Todo dia", "dias" => [1, 2, 3, 4, 5, 6, 7], "no_id" => 0, "um_por" => "dia", "relogio_id" => 0]]]);
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $inteligente]);
cron();
escrever(["recurso" => "rodizio", "acao" => "usando", "relogio_id" => $rel["crono"]]);
cron();

// ---------- a fotografia ----------
ler("tudo", ["foto" => "nao"]);
foreach (["hoje", "avisos", "criterios", "historico", "previsao", "autonomia", "plano", "eventos", "destinos", "agendas", "config", "arvore", "cadastros", "usuarios", "migracoes"] as $r) {
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
foreach ([["teste", "Senha@123"], ["TESTE", "Senha@123"], ["teste", "errada"], ["maria", "Outra@12345"]] as [$u, $s]) {
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
    if ($cj["escopo"] === "g:" . G(1)) {
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
    $pesos = ["recurso" => "criterios", "acao" => "param_pesos", "escopo" => "g:" . G(1)];
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
    escrever(["recurso" => "criterios", "acao" => "param_pesos", "escopo" => "g:" . G(1), "peso" => [$param_smart[0] => 90]], false);
}
escrever(["recurso" => "criterios", "acao" => "conjunto_excluir", "escopo" => "r:" . $rel["crono"]]);
ler("criterios editados", ["recurso" => "criterios"]);
escrever(["recurso" => "criterios", "acao" => "restaurar"]);
escrever(["recurso" => "criterios", "acao" => "param_novo", "escopo" => "g:" . G(3), "nome" => "Depois de restaurar", "peso" => 10]);

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
escrever(["recurso" => "campos", "acao" => "excluir", "id" => $id_de("campos", "resistencia_agua"), "confirmar" => 1]);
escrever(["recurso" => "lancamento_tipos", "acao" => "alterar", "id" => $id_de("lancamento_tipos", "banho_ultrassom"), "nome" => "Banho de ultrassom"]);
escrever(["recurso" => "lancamento_tipos", "acao" => "excluir", "id" => $id_de("lancamento_tipos", "banho_ultrassom"), "confirmar" => 1], false);   // tem lançamento
escrever(["recurso" => "formulas", "acao" => "alterar", "id" => $id_de("formulas", "idade_dias"), "expressao" => "ARREDONDA(HOJE() - data_compra; 1)"]);
escrever(["recurso" => "formulas", "acao" => "excluir", "id" => $id_de("formulas", "idade_dias"), "confirmar" => 1]);
escrever(["recurso" => "avisos", "acao" => "alterar", "id" => $id_de("avisos", "pulseira"), "antecedencia_dias" => 30, "escala" => "sempre", "agenda" => "sempre"]);
escrever(["recurso" => "avisos", "acao" => "excluir", "id" => $id_de("avisos", "pulseira"), "confirmar" => 1]);

// a precisão do automático: três leituras do desvio da hora (0 s há 10 dias; +50 s há 5, no pulso; +60 s há 3, mostrador para
// cima), um acerto da hora há 2 dias e a leitura pela hora que o relógio mostra (+8 s, em instante_ms: um minuto cheio de 2 a
// 3 minutos atrás, para os bancos darem a mesma conta e a mensagem dizer sempre "em <quando>": com o instante a 60 s de
// agora, um banco dizia e o outro não). As marchas: +10, +5 e +4 s/dia; a do relógio, pesada pelo tempo, 68/9 s/dia.
// E as recusas: a posição numa leitura que não é do desvio, a hora inválida e o desvio de mais de 6 horas
$base_p = intdiv(time(), 60) * 60 - 120;
foreach ([[10, "0", ""], [5, "50", "pulso"], [3, "60", "mostrador_cima"]] as [$dias_p, $valor_p, $pos_p]) {
    escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["auto"], "tipo" => "desvio", "valor" => $valor_p, "posicao" => $pos_p,
        "quando" => date("Y-m-d H:i", $base_p - $dias_p * 86400)]);
}
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["auto"], "tipo" => "acerto", "quando" => date("Y-m-d H:i", $base_p - 2 * 86400)]);
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["auto"], "tipo" => "desvio", "marca" => date("H:i:s", $base_p + 8),
    "instante_ms" => (string)($base_p * 1000), "posicao" => "misto"]);
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["smart"], "tipo" => "carga", "valor" => "50", "posicao" => "pulso"], false);
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["auto"], "tipo" => "desvio", "marca" => "25:00:00"], false);
escrever(["recurso" => "lancamento", "acao" => "lancar", "relogio_id" => $rel["auto"], "tipo" => "desvio", "valor" => "30000"], false);
$p_auto = ler("precisao do automatico", ["recurso" => "precisao", "relogio" => $rel["auto"]])["precisao"] ?? null;
$p_esperado = ["medicoes" => 3, "marcha" => round(68 / 9, 2), "ultima" => 4.0];
foreach ($p_esperado as $k_p => $v_p) {
    if (!is_array($p_auto) || abs((float)($p_auto[$k_p] ?? -999) - $v_p) > 0.001) {
        $falhas[] = "precisão: " . $k_p . " deu " . json_encode($p_auto[$k_p] ?? null) . ", esperado " . $v_p;
    }
}
$p_pos = is_array($p_auto) ? array_column($p_auto["por_posicao"], "marcha", "posicao") : [];
if (($p_pos["pulso"] ?? null) != 10 || ($p_pos["mostrador_cima"] ?? null) != 5 || ($p_pos["misto"] ?? null) != 4) {
    $falhas[] = "precisão: a marcha por posição deu " . json_encode($p_pos);
}
ler("precisao da colecao", ["recurso" => "precisao"]);
ler("calcular marcha", ["recurso" => "calcular", "relogio" => $rel["auto"], "expressao" => "ARREDONDA(MARCHA(0; \"pulso\") * 100 + MARCHA_N() + PADRAO(MARCHA_DP(30); 0) / 1000; 3)"]);
ler("calcular desvio agora", ["recurso" => "calcular", "relogio" => $rel["auto"], "expressao" => "ARREDONDA(DESVIO_AGORA(); 0) + ARREDONDA(MARCHA_ULTIMA(); 1) * 1000"]);
ler("ficha com a precisao", ["recurso" => "ficha", "relogio" => $rel["auto"]]);

// os calibres: o NH35 com o que o movimento define (a reserva, a marcha esperada, a corda pela coroa), as recusas (sem nome,
// o link que não é http, o campo que não existe, o valor que não é número, o nome repetido), o Seiko com ele (a reserva é a
// dele mesmo: 41; a marcha esperada vem do calibre), o Orient com ele e depois sem, o calibre que não existe, a função
// CALIBRE e a exclusão em dois passos (de um calibre de teste com um relógio: ele fica sem calibre)
$cal = escrever(["recurso" => "calibres", "acao" => "salvar", "id" => 0, "nome" => "NH35", "fabricante" => "Seiko Instruments", "link" => "https://exemplo.com/nh35.pdf",
    "observacao" => "Dar corda pela coroa: até 40 voltas.", "valores" => ["reserva_horas" => 50, "marcha_min" => "-20", "marcha_max" => "40", "corda_manual" => "1"]]);
escrever(["recurso" => "calibres", "acao" => "salvar", "id" => 0, "nome" => " ", "link" => "ftp://x"], false);
escrever(["recurso" => "calibres", "acao" => "salvar", "id" => 0, "nome" => "Errado", "valores" => ["nao_existe" => "1", "reserva_horas" => "muito"]], false);
escrever(["recurso" => "calibres", "acao" => "salvar", "id" => 0, "nome" => "nh35"], false);
escrever(["recurso" => "calibres", "acao" => "salvar", "id" => $cal, "valores" => ["corda_manual" => ""]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto"], "calibre_id" => $cal]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "calibre_id" => $cal]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "calibre_id" => "0"]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "calibre_id" => 999999], false);
$cal_lista = ler("calibres", ["recurso" => "calibres"]);
$f_cal = ler("ficha com o calibre", ["recurso" => "ficha", "relogio" => $rel["auto"]]);
$d_cal = array_column($f_cal["relogio"]["dados"] ?? [], null, "identificador");
if (($d_cal["reserva_horas"]["origem"] ?? "") !== "informado" || ($d_cal["marcha_max"]["origem"] ?? "") !== "calibre" || (string)($d_cal["marcha_max"]["valor_usado"] ?? "") !== "40"
    || ($f_cal["relogio"]["calibre"]["nome"] ?? "") !== "NH35" || count($cal_lista["calibres"][0]["relogios"] ?? []) !== 1) {
    $falhas[] = "calibres: a ficha deu " . json_encode([$d_cal["reserva_horas"] ?? null, $d_cal["marcha_max"] ?? null, $f_cal["relogio"]["calibre"] ?? null], JSON_UNESCAPED_UNICODE);
}
ler("calcular calibre", ["recurso" => "calcular", "expressao" => "CALIBRE(\"nh35\") * 10 + CALIBRE(" . $cal . ") + CALIBRE(\"outro\") * 100"]);
// os documentos do calibre: o manual técnico (a categoria Manual técnico) no NH35, que aparece na ficha e nos documentos do
// Seiko; o documento com relógio e calibre ao mesmo tempo (não pode); o calibre de teste com um documento: a exclusão diz o
// arquivo que sai e apaga a pasta dele (c<id>)
$cat_tec = 0;
foreach ((chamar("GET", ["recurso" => "documentos", "calibre" => $cal])["json"]["categorias"] ?? []) as $c) {
    $cat_tec = $c["identificador"] === "manual_tecnico" ? (int)$c["id"] : $cat_tec;
}
if ($cat_tec === 0) {
    $falhas[] = "calibres: a categoria Manual técnico não existe";
}
escrever(["recurso" => "documentos", "acao" => "enviar", "calibre_id" => $cal, "categoria_id" => $cat_tec, "titulo" => "Manual técnico do NH35",
    "arquivo_nome" => "nh35.pdf", "arquivo_base64" => base64_encode("%PDF-1.4 manual técnico do NH35")]);
escrever(["recurso" => "documentos", "acao" => "enviar", "calibre_id" => $cal, "relogio_id" => $rel["auto"], "categoria_id" => $cat_tec,
    "arquivo_nome" => "x.pdf", "arquivo_base64" => base64_encode("%PDF-1.4 x")], false);
escrever(["recurso" => "documentos", "acao" => "enviar", "calibre_id" => 999999, "categoria_id" => $cat_tec, "arquivo_nome" => "x.pdf", "arquivo_base64" => base64_encode("x")], false);
ler("documentos do calibre", ["recurso" => "documentos", "calibre" => $cal]);
ler("documentos do relogio com calibre", ["recurso" => "documentos", "relogio" => $rel["auto"]]);
ler("documentos de todos com calibre", ["recurso" => "documentos"]);
ler("documentos de calibre que nao existe", ["recurso" => "documentos", "calibre" => 999999]);
$f_doc = ler("ficha com o documento do calibre", ["recurso" => "ficha", "relogio" => $rel["auto"]]);
if (count($f_doc["relogio"]["calibre"]["documentos"] ?? []) !== 1) {
    $falhas[] = "calibres: a ficha não traz o documento do calibre";
}
// os campos do formulário do calibre: só os do movimento (o padrão), todos, de volta, e um valor que não vale; a marca de
// movimento num campo novo (estilo, uma lista)
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $id_de("campos", "estilo"), "calibre" => 1]);
ler("calibres so do movimento", ["recurso" => "calibres"]);
escrever(["recurso" => "config", "acao" => "salvar", "calibre_campos" => "todos"]);
ler("calibres com todos os campos", ["recurso" => "calibres"]);
ler("config com os campos do calibre", ["recurso" => "config"]);
escrever(["recurso" => "config", "acao" => "salvar", "calibre_campos" => "alguns"], false);
escrever(["recurso" => "config", "acao" => "salvar", "calibre_campos" => "movimento"]);
$cal_t = escrever(["recurso" => "calibres", "acao" => "salvar", "id" => 0, "nome" => "Calibre de teste"]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "calibre_id" => $cal_t]);
escrever(["recurso" => "documentos", "acao" => "enviar", "calibre_id" => $cal_t, "categoria_id" => $cat_tec, "titulo" => "Ficha de serviço",
    "arquivo_nome" => "ficha.txt", "arquivo_base64" => base64_encode("ficha de serviço do calibre de teste")]);
$pasta_cal = getenv("RELOGIOS_TESTE_PASTA") . "/documentos_" . getenv("RELOGIOS_DB") . "/c" . $cal_t;
if (!is_dir($pasta_cal)) {
    $falhas[] = "calibres: o documento do calibre não foi para a pasta c<id>";
}
escrever(["recurso" => "calibres", "acao" => "excluir", "id" => $cal_t], false);
escrever(["recurso" => "calibres", "acao" => "excluir", "id" => $cal_t, "confirmar" => 1]);
clearstatcache();
if (is_dir($pasta_cal)) {
    $falhas[] = "calibres: a pasta do calibre excluído ficou";
}
ler("ficha sem o calibre excluido", ["recurso" => "ficha", "relogio" => $rel["auto2"]]);

// as unidades: as prontas do modelo (a polegada em milímetros, o °F em °C), uma nova (a milha), as recusas (sem símbolo, a
// repetida, a base que tem base, o fator zero, o "mostrar também em" de outra grandeza, o campo com uma unidade que não
// existe, a exclusão da que está em uso), a troca do símbolo (vai junto para o campo que usa), o valor também em polegadas
// na ficha e a função CONVERTER
$un = ler("unidades", ["recurso" => "unidades"]);
$u_id = [];
foreach ($un["unidades"] ?? [] as $u) {
    $u_id[$u["simbolo"]] = $u["id"];
}
if (!isset($u_id["mm"], $u_id["pol"], $u_id["°F"]) || count($un["grandezas"] ?? []) < 5) {
    $falhas[] = "unidades: as do modelo não vieram (" . implode(", ", array_keys($u_id)) . ")";
}
$u_mi = escrever(["recurso" => "unidades", "acao" => "salvar", "id" => 0, "simbolo" => "mi", "nome" => "milha", "base" => "mm", "fator" => "1609344"]);
escrever(["recurso" => "unidades", "acao" => "salvar", "id" => 0, "simbolo" => " ", "nome" => "nada"], false);
escrever(["recurso" => "unidades", "acao" => "salvar", "id" => 0, "simbolo" => "MM", "nome" => "repetida"], false);
escrever(["recurso" => "unidades", "acao" => "salvar", "id" => 0, "simbolo" => "dm", "nome" => "decímetro", "base" => "cm", "fator" => "10"], false);
escrever(["recurso" => "unidades", "acao" => "salvar", "id" => 0, "simbolo" => "dm", "nome" => "decímetro", "base" => "mm", "fator" => "0"], false);
escrever(["recurso" => "unidades", "acao" => "salvar", "id" => $u_id["mm"] ?? 0, "mostrar_em" => "g"], false);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "medida_teste", "nome" => "Medida de teste", "tipo" => "decimal", "unidade" => "mmm"], false);
$c_med = escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "medida_teste", "nome" => "Medida de teste", "tipo" => "decimal", "unidade" => "mm"]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["manual"], "valores" => ["medida_teste" => "40"]]);
escrever(["recurso" => "unidades", "acao" => "excluir", "id" => $u_id["mm"] ?? 0, "confirmar" => 1], false);
escrever(["recurso" => "unidades", "acao" => "salvar", "id" => $u_id["mm"] ?? 0, "simbolo" => "milímetros"]);
$cad_u = ler("cadastros com o simbolo novo", ["recurso" => "cadastros"]);
$med_u = array_values(array_filter($cad_u["campos"] ?? [], function ($c) { return $c["identificador"] === "medida_teste"; }))[0]["unidade"] ?? null;
escrever(["recurso" => "unidades", "acao" => "salvar", "id" => $u_id["mm"] ?? 0, "simbolo" => "mm"]);
$f_un = ler("ficha com a polegada", ["recurso" => "ficha", "relogio" => $rel["manual"]]);
$d_un = array_values(array_filter($f_un["relogio"]["dados"] ?? [], function ($c) { return $c["identificador"] === "medida_teste"; }))[0] ?? [];
$conv = ler("converter", ["recurso" => "calcular", "expressao" => "CONVERTER(medida_teste; \"mm\"; \"pol\") + CONVERTER(212; \"°F\"; \"°C\")"]);
$v_conv = array_values(array_filter($conv["resultados"] ?? [], function ($x) use ($rel) { return $x["relogio_id"] === $rel["manual"]; }))[0]["valor"] ?? null;
if ($med_u !== "milímetros" || strpos((string)($d_un["tambem"] ?? ""), "pol") === false || $v_conv === null || abs($v_conv - 101.5748) > 0.001) {
    $falhas[] = "unidades: " . json_encode([$med_u, $d_un, $v_conv], JSON_UNESCAPED_UNICODE);
}
escrever(["recurso" => "unidades", "acao" => "excluir", "id" => $u_mi], false);
escrever(["recurso" => "unidades", "acao" => "excluir", "id" => $u_mi, "confirmar" => 1]);
escrever(["recurso" => "campos", "acao" => "excluir", "id" => $c_med, "confirmar" => 1]);
ler("unidades no fim", ["recurso" => "unidades"]);

// as divisórias da ficha: as do modelo (cada campo pronto na sua), uma nova (no fim), as recusas (sem nome, a repetida, a
// do campo que não existe), o campo nela (a ficha e o passaporte agrupam), subir na ordem, a exclusão (o campo fica sem
// divisória, no fim)
$cad_s = ler("divisorias", ["recurso" => "cadastros"]);
$sec_ids = [];
foreach ($cad_s["secoes"] ?? [] as $x) {
    $sec_ids[$x["nome"]] = $x["id"];
}
$marca_s = array_values(array_filter($cad_s["campos"] ?? [], function ($c) { return $c["identificador"] === "marca"; }))[0] ?? [];
if (count($sec_ids) < 7 || ($marca_s["secao_id"] ?? null) !== ($sec_ids["Identificação"] ?? -1)) {
    $falhas[] = "divisórias: as do modelo não vieram (" . implode(", ", array_keys($sec_ids)) . ")";
}
$s_nova = escrever(["recurso" => "secoes", "acao" => "salvar", "id" => 0, "nome" => "Pulseira"]);
escrever(["recurso" => "secoes", "acao" => "salvar", "id" => 0, "nome" => " "], false);
escrever(["recurso" => "secoes", "acao" => "salvar", "id" => 0, "nome" => "compra"], false);
$c_pul = escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "pulseira_teste", "nome" => "Pulseira de teste", "tipo" => "texto", "secao_id" => $s_nova]);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "outro_teste", "nome" => "Outro", "tipo" => "texto", "secao_id" => 99999], false);
escrever(["recurso" => "secoes", "acao" => "ordem", "id" => $s_nova, "direcao" => "sobe"]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["manual"], "valores" => ["pulseira_teste" => "couro"]]);
$f_s = ler("ficha com a divisoria nova", ["recurso" => "ficha", "relogio" => $rel["manual"]]);
$p_s = ler("passaporte por divisoria", ["recurso" => "passaporte", "relogio" => $rel["manual"]]);
$d_pul = array_values(array_filter($f_s["relogio"]["dados"] ?? [], function ($c) { return $c["identificador"] === "pulseira_teste"; }))[0] ?? [];
$g_pul = array_values(array_filter($p_s["cadastro_secoes"] ?? [], function ($g) { return $g["secao"] === "Pulseira"; }));
if (($d_pul["secao"] ?? null) !== "Pulseira" || count($g_pul) !== 1) {
    $falhas[] = "divisórias: " . json_encode([$d_pul, $p_s["cadastro_secoes"] ?? null], JSON_UNESCAPED_UNICODE);
}
escrever(["recurso" => "secoes", "acao" => "excluir", "id" => $s_nova], false);
escrever(["recurso" => "secoes", "acao" => "excluir", "id" => $s_nova, "confirmar" => 1]);
$cad_s2 = ler("divisorias depois da exclusao", ["recurso" => "cadastros"]);
$pul_s2 = array_values(array_filter($cad_s2["campos"] ?? [], function ($c) { return $c["identificador"] === "pulseira_teste"; }))[0] ?? [];
if (!array_key_exists("secao_id", $pul_s2) || $pul_s2["secao_id"] !== null) {
    $falhas[] = "divisórias: o campo não ficou sem divisória (" . json_encode($pul_s2, JSON_UNESCAPED_UNICODE) . ")";
}
escrever(["recurso" => "campos", "acao" => "excluir", "id" => $c_pul, "confirmar" => 1]);

// os serviços e diagnósticos: uma revisão no automático com peças, a estanqueidade aprovada, a marcha antes e depois, a
// garantia e um documento (vira também a marcação Revisão); as recusas (o teste de estanqueidade sem resultado, a data no
// futuro, a pressão sem resultado, o custo da peça que não é número, o documento de outro relógio, a garantia antes do
// serviço); a resistência declarada no cadastro; mudar o tipo desfaz a marcação e voltar a refaz; as funções
// ULTIMO_SERVICO e CUSTO_SERVICOS; o total do modelo; e a exclusão em dois passos (de um diagnóstico à parte)
$cat_nf = 0;
foreach (ler("categorias para o servico", ["recurso" => "documentos", "relogio" => $rel["auto"]])["categorias"] ?? [] as $c) {
    $cat_nf = $c["identificador"] === "nota_fiscal" ? (int)$c["id"] : $cat_nf;
}
$doc_srv = escrever(["recurso" => "documentos", "acao" => "enviar", "relogio_id" => $rel["auto"], "categoria_id" => $cat_nf, "titulo" => "Nota da revisão",
    "arquivo_nome" => "s1.pdf", "arquivo_base64" => base64_encode("%PDF-1.4 nota da revisao")]);
$doc_outro = escrever(["recurso" => "documentos", "acao" => "enviar", "relogio_id" => $rel["smart"], "categoria_id" => $cat_nf, "titulo" => "Nota de outro relógio",
    "arquivo_nome" => "s2.pdf", "arquivo_base64" => base64_encode("%PDF-1.4 nota de outro")]);
$d_srv = date("Y-m-d", strtotime("-5 days"));
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto"], "valores" => ["resistencia_agua_declarada" => 100]]);
$revisoes = function ($nome) use ($rel) {
    return (int)(ler($nome, ["recurso" => "calcular", "relogio" => $rel["auto"], "expressao" => "CONTAR(\"revisao\"; 30)"])["resultados"][0]["valor"] ?? -1);
};
$revisoes_antes = $revisoes("calcular revisoes antes do servico");
$srv = escrever(["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel["auto"], "data" => $d_srv, "tipo" => "revisao", "responsavel" => "Relojoaria Central",
    "descricao" => "Limpeza, lubrificação e regulagem.", "mao_de_obra" => "300,00", "pecas" => "Coroa; 120,50\nVedação do fundo\nVidro; 80", "estanqueidade" => "aprovado",
    "estanqueidade_bar" => "10", "marcha_antes" => "25", "marcha_depois" => "+4,5", "garantia_ate" => date("Y-m-d", strtotime("+360 days")), "documentos" => [$doc_srv],
    "observacao" => str_repeat("Observação longa do relojoeiro, sem limite de tamanho. ", 220)]);
escrever(["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel["auto"], "data" => $d_srv, "tipo" => "estanqueidade"], false);
escrever(["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel["auto"], "data" => date("Y-m-d", strtotime("+2 days")), "tipo" => "reparo"], false);
escrever(["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel["auto"], "data" => $d_srv, "tipo" => "diagnostico", "estanqueidade_bar" => "5"], false);
escrever(["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel["auto"], "data" => $d_srv, "tipo" => "reparo", "pecas" => "Mola; caro"], false);
escrever(["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel["auto"], "data" => $d_srv, "tipo" => "reparo", "garantia_ate" => date("Y-m-d", strtotime("-30 days"))], false);
escrever(["recurso" => "servicos", "acao" => "salvar", "id" => $srv, "documentos" => (string)$doc_outro], false);
escrever(["recurso" => "servicos", "acao" => "salvar", "relogio_id" => 999999, "data" => $d_srv], false);
$s_lista = ler("servicos do auto", ["recurso" => "servicos", "relogio" => $rel["auto"]]);
$s1 = $s_lista["servicos"][0] ?? [];
if (($s1["custo"] ?? null) != 500.5 || count($s1["pecas"] ?? []) !== 3 || ($s1["marcha_depois"] ?? null) != 4.5 || ($s_lista["resumo"]["estanqueidade"]["resultado"] ?? "") !== "aprovado"
    || ($s_lista["resumo"]["resistencia_declarada"] ?? null) !== 100 || empty($s1["marcacao"]) || mb_strlen((string)($s1["observacao"] ?? "")) < 12000) {
    $falhas[] = "serviços: a lista deu " . json_encode([$s1["custo"] ?? null, count($s1["pecas"] ?? []), $s1["marcha_depois"] ?? null, $s_lista["resumo"] ?? null, $s1["marcacao"] ?? null], JSON_UNESCAPED_UNICODE);
}
if ($revisoes("calcular revisoes com o servico") !== $revisoes_antes + 1) {
    $falhas[] = "serviços: a revisão não virou a marcação Revisão";
}
escrever(["recurso" => "servicos", "acao" => "salvar", "id" => $srv, "tipo" => "regulagem"]);
if ($revisoes("calcular revisoes depois de mudar o tipo") !== $revisoes_antes) {
    $falhas[] = "serviços: mudar o tipo não desfez a marcação";
}
escrever(["recurso" => "servicos", "acao" => "salvar", "id" => $srv, "tipo" => "revisao", "pecas" => "Coroa; 120,50\nVedação do fundo\nVidro; 80"]);
ler("calcular servicos", ["recurso" => "calcular", "relogio" => $rel["auto"], "expressao" => "CUSTO_SERVICOS() * 1000 + ARREDONDA(DIAS_DESDE(ULTIMO_SERVICO(\"revisao\")); 0) * 10 + ARREDONDA(DIAS_DESDE(ULTIMO_SERVICO(\"estanqueidade\")); 0) + PADRAO(ULTIMO_SERVICO(\"polimento\"); 7)"]);
ler("calcular servicos de outro relogio", ["recurso" => "calcular", "relogio" => $rel["smart"], "expressao" => "CUSTO_SERVICOS(30) + PADRAO(ULTIMO_SERVICO(); 9)"]);
ler("totais teste custo dos servicos", ["recurso" => "totais", "parte" => "teste", "expressao" => "SOMA(custo_servicos) + SOMA(servicos)", "formato" => "moeda"]);
$diag = escrever(["recurso" => "servicos", "acao" => "salvar", "relogio_id" => $rel["auto"], "data" => date("Y-m-d", strtotime("-1 day")), "tipo" => "diagnostico", "descricao" => "Conferido no timegrapher."]);
escrever(["recurso" => "servicos", "acao" => "excluir", "id" => $diag], false);
escrever(["recurso" => "servicos", "acao" => "excluir", "id" => $diag, "confirmar" => 1]);
ler("servicos de todos", ["recurso" => "servicos"]);
ler("ficha com os servicos", ["recurso" => "ficha", "relogio" => $rel["auto"]]);
ler("passaporte com os servicos", ["recurso" => "passaporte", "relogio" => $rel["auto"]]);

// a reserva medida (v36): três testes da reserva do corda manual (cheio pela coroa e no winder) e as recusas (um segundo teste
// em andamento, parar antes de encher, encher no futuro, o teste no solar, a observação noutro tipo que não tem reserva, a
// nota sem texto); a mediana, a confiança e o "usar a medida" no cadastro dele; RESERVA_MEDIDA e RESERVA_TESTES; as
// observações (do solar e do mecânico); mudar um teste; e a exclusão em dois passos
$rt1 = escrever(["recurso" => "reserva", "acao" => "iniciar", "relogio_id" => $rel["manual"], "inicio" => dia(12, "08:00"), "protocolo" => "corda", "protocolo_valor" => "40"]);
escrever(["recurso" => "reserva", "acao" => "iniciar", "relogio_id" => $rel["manual"], "inicio" => dia(11, "08:00")], false);
escrever(["recurso" => "reserva", "acao" => "parou", "id" => $rt1, "fim" => dia(13, "07:00")], false);
escrever(["recurso" => "reserva", "acao" => "parou", "relogio_id" => $rel["manual"], "fim" => dia(10, "02:00")]);
$rt2 = escrever(["recurso" => "reserva", "acao" => "iniciar", "relogio_id" => $rel["manual"], "inicio" => dia(9, "08:00"), "protocolo" => "winder", "protocolo_valor" => "8"]);
escrever(["recurso" => "reserva", "acao" => "parou", "id" => $rt2, "fim" => dia(7, "04:00")]);
$rt3 = escrever(["recurso" => "reserva", "acao" => "iniciar", "relogio_id" => $rel["manual"], "inicio" => dia(6, "08:00"), "protocolo" => "corda", "protocolo_valor" => "40",
    "observacao" => "Coroa até travar."]);
escrever(["recurso" => "reserva", "acao" => "parou", "id" => $rt3, "fim" => dia(4, "01:00")]);
escrever(["recurso" => "reserva", "acao" => "iniciar", "relogio_id" => $rel["manual"], "inicio" => date("Y-m-d", strtotime("+3 days")) . " 08:00"], false);
escrever(["recurso" => "reserva", "acao" => "iniciar", "relogio_id" => $rel["solar"], "inicio" => dia(4, "08:00")], false);
escrever(["recurso" => "reserva", "acao" => "observar", "relogio_id" => $rel["solar"], "tipo" => "carga_baixa", "quando" => dia(3, "09:00"), "observacao" => "O ponteiro dos segundos pula de 2 em 2."]);
escrever(["recurso" => "reserva", "acao" => "observar", "relogio_id" => $rel["solar"], "tipo" => "nota", "quando" => dia(3, "09:30")], false);
escrever(["recurso" => "reserva", "acao" => "observar", "relogio_id" => $rel["smart"], "tipo" => "parou", "quando" => dia(3, "09:00")], false);
$obs_m = escrever(["recurso" => "reserva", "acao" => "observar", "relogio_id" => $rel["manual"], "tipo" => "parou", "quando" => dia(2, "10:00")]);
$rs_m = ler("reserva do corda manual", ["recurso" => "reserva", "relogio" => $rel["manual"]]);
$tec_m = array_values(array_filter($rs_m["tecnico"] ?? [], function ($x) { return $x["chave"] === "reserva"; }))[0] ?? [];
if (($rs_m["reserva"]["medida"]["horas"] ?? null) != 42 || ($rs_m["reserva"]["medida"]["testes"] ?? null) !== 3 || ($rs_m["reserva"]["usar_horas"] ?? null) !== 42
    || ($tec_m["confianca"]["medicoes"] ?? null) !== 3 || ($tec_m["medido"]["valor"] ?? null) != 41) {
    $falhas[] = "reserva: a medida deu " . json_encode([$rs_m["reserva"]["medida"] ?? null, $rs_m["reserva"]["usar_horas"] ?? null, $tec_m], JSON_UNESCAPED_UNICODE);
}
escrever(["recurso" => "reserva", "acao" => "usar", "relogio_id" => $rel["manual"]]);
escrever(["recurso" => "reserva", "acao" => "usar", "relogio_id" => $rel["solar"]], false);
$calc_r = ler("calcular a reserva medida", ["recurso" => "calcular", "relogio" => $rel["manual"], "expressao" => "RESERVA_MEDIDA() * 10 + RESERVA_TESTES() + reserva_horas * 1000"]);
if (($calc_r["resultados"][0]["valor"] ?? null) != 42423) {
    $falhas[] = "reserva: RESERVA_MEDIDA, RESERVA_TESTES e o cadastro deram " . json_encode($calc_r["resultados"][0]["valor"] ?? null);
}
ler("calcular a reserva medida sem teste", ["recurso" => "calcular", "relogio" => $rel["auto"], "expressao" => "PADRAO(RESERVA_MEDIDA(); 1) + RESERVA_TESTES()"]);
escrever(["recurso" => "reserva", "acao" => "salvar", "id" => $rt3, "fim" => dia(4, "03:00"), "observacao" => "Coroa até travar; parou de madrugada."]);
escrever(["recurso" => "reserva", "acao" => "salvar", "id" => $rt3, "fim" => dia(7, "03:00")], false);
escrever(["recurso" => "reserva", "acao" => "excluir", "id" => $obs_m], false);
escrever(["recurso" => "reserva", "acao" => "excluir", "id" => $obs_m, "confirmar" => 1]);
ler("reserva do solar", ["recurso" => "reserva", "relogio" => $rel["solar"]]);
ler("reserva de todos", ["recurso" => "reserva"]);
ler("ficha com a reserva", ["recurso" => "ficha", "relogio" => $rel["manual"]]);
ler("passaporte com a reserva", ["recurso" => "passaporte", "relogio" => $rel["manual"]]);

// o timegrapher: a medição digitada (e as recusas: sem grandeza, fora dos limites, posição desconhecida, no futuro), o CSV
// do aparelho (conferir sem gravar, importar, a repetida não entra de novo, a linha que não se entende fica de fora com o
// motivo), a sessão (a média e a diferença entre as posições), a fórmula, mudar e excluir
$tg_dia = date("Y-m-d", strtotime("-2 days"));
escrever(["recurso" => "timegrapher", "acao" => "salvar", "relogio_id" => $rel["manual"], "quando" => $tg_dia . " 10:00", "posicao" => "mostrador_cima"], false);
escrever(["recurso" => "timegrapher", "acao" => "salvar", "relogio_id" => $rel["manual"], "quando" => $tg_dia . " 10:00", "amplitude" => 400], false);
escrever(["recurso" => "timegrapher", "acao" => "salvar", "relogio_id" => $rel["manual"], "quando" => $tg_dia . " 10:00", "posicao" => "lado", "marcha" => 3], false);
escrever(["recurso" => "timegrapher", "acao" => "salvar", "relogio_id" => $rel["manual"], "quando" => date("Y-m-d", strtotime("+2 days")) . " 10:00", "marcha" => 3], false);
$tg1 = escrever(["recurso" => "timegrapher", "acao" => "salvar", "relogio_id" => $rel["manual"], "quando" => $tg_dia . " 10:00", "posicao" => "mostrador_cima",
    "marcha" => "+4,5", "amplitude" => "290", "erro_batida" => "0,3", "bph" => 21600, "angulo" => 52, "observacao" => "depois da corda"]);
$csv_tg = "Date;Time;Position;Rate (s/d);Amplitude (°);Beat error (ms);BPH\n"
    . $tg_dia . ";10:05;DD;+6;285;0.4;21600\n"
    . $tg_dia . ";10:10;CU;-3;262;0.5;21600\n"
    . $tg_dia . ";10:15;9U;-1,5;258;0,4;21600\n"
    . $tg_dia . ";10:20;de lado;2;250;0.4;21600\n"
    . "31/02/2026;10:25;CL;2;250;0.4;21600\n";
$conf_tg = escrever(["recurso" => "timegrapher", "acao" => "importar", "relogio_id" => $rel["manual"], "csv" => $csv_tg, "conferir" => 1], true, "importacao");
escrever(["recurso" => "timegrapher", "acao" => "importar", "relogio_id" => $rel["manual"], "csv" => $csv_tg]);
escrever(["recurso" => "timegrapher", "acao" => "importar", "relogio_id" => $rel["manual"], "csv" => $csv_tg], false);
escrever(["recurso" => "timegrapher", "acao" => "importar", "relogio_id" => $rel["manual"], "csv" => "nome;cor\nx;y\n"], false);
escrever(["recurso" => "timegrapher", "acao" => "salvar", "id" => $tg1, "observacao" => "depois da corda cheia"]);
$tg_r = ler("timegrapher do manual", ["recurso" => "timegrapher", "relogio" => $rel["manual"]]);
if (($conf_tg["entram"] ?? null) !== 3 || ($conf_tg["recusadas"] ?? null) !== 2 || ($tg_r["timegrapher"]["sessao"]["medicoes"] ?? null) !== 4
    || (float)($tg_r["timegrapher"]["sessao"]["delta"] ?? 0) !== 9.0) {
    $falhas[] = "timegrapher: " . json_encode([$conf_tg["entram"] ?? null, $conf_tg["recusadas"] ?? null, $tg_r["timegrapher"]["sessao"] ?? null], JSON_UNESCAPED_UNICODE);
}
ler("calcular timegrapher", ["recurso" => "calcular", "relogio" => $rel["manual"], "expressao" => "TIMEGRAPHER(\"amplitude\") * 1000 + TIMEGRAPHER(\"delta\") * 10 + TIMEGRAPHER(\"medicoes\") + PADRAO(DIAS_DESDE(TIMEGRAPHER_DATA()); 0) / 100"]);
ler("calcular timegrapher sem medicao", ["recurso" => "calcular", "relogio" => $rel["auto"], "expressao" => "PADRAO(TIMEGRAPHER(\"marcha\"); -1)"]);
ler("timegrapher de todos", ["recurso" => "timegrapher"]);
ler("ficha com o timegrapher", ["recurso" => "ficha", "relogio" => $rel["manual"]]);
escrever(["recurso" => "timegrapher", "acao" => "excluir", "id" => $tg1], false);
escrever(["recurso" => "timegrapher", "acao" => "excluir", "id" => $tg1, "confirmar" => 1]);

// o Tirar do sol sozinho (sol_auto_fim): desligado, o "fecha às" do tipo No sol fica vazio (a sessão só fecha pelo Tirar do
// sol) e a hora continua guardada; ligado de novo, volta a hora
$fecha_sol = function ($nome) {
    foreach (ler($nome, ["recurso" => "cadastros"])["lancamento_tipos"] ?? [] as $t) {
        if ($t["identificador"] === "sol") {
            return $t["fecha_as"];
        }
    }
    return "sem o tipo";
};
escrever(["recurso" => "config", "acao" => "salvar", "sol_auto_fim" => 0]);
$cfg_sol = ler("configuracao sem tirar do sol sozinho", ["recurso" => "config"]);
if ($fecha_sol("tipos sem tirar do sol sozinho") !== null || ($cfg_sol["sol_auto_fim"] ?? null) !== false || ($cfg_sol["sol_fim"] ?? "") !== "18:00") {
    $falhas[] = "sol_auto_fim: desligado, o tipo No sol ainda fecha sozinho, ou a hora sumiu";
}
escrever(["recurso" => "config", "acao" => "salvar", "sol_auto_fim" => 1]);
if (substr((string)$fecha_sol("tipos com tirar do sol sozinho"), 0, 5) !== "18:00") {
    $falhas[] = "sol_auto_fim: ligado de novo, o tipo No sol não voltou a fechar às 18:00";
}

// os totais: os que vêm prontos (todos, os do rodapé de alguns relógios, os da página), o Testar (uma expressão boa, uma
// errada, uma que precisa de função de total, uma vazia), um total novo só da página, um no rodapé sem coluna (não pode), a
// ordem, e a exclusão (sem confirmar, o impacto; com, sai)
ler("totais", ["recurso" => "totais"]);
ler("totais do rodape de dois relogios", ["recurso" => "totais", "parte" => "rodape", "relogios" => $rel["smart"] . "," . $rel["crono"]]);
ler("totais da pagina", ["recurso" => "totais", "parte" => "pagina"]);
// (a média de "disponivel", e não a da carga: a reserva dos automáticos cai com os segundos, e a leitura de um banco e a
// do outro, segundos depois, viravam um número inteiro diferente: 54,5 num e 54 no outro)
ler("totais teste bom", ["recurso" => "totais", "parte" => "teste", "expressao" => "AGRUPADO(tipo; MEDIA(disponivel))", "formato" => "decimal"]);
ler("totais teste faixas", ["recurso" => "totais", "parte" => "teste", "expressao" => "FAIXAS(DIAS_DESDE(data_compra); 30; 365)"]);
ler("totais teste errado", ["recurso" => "totais", "parte" => "teste", "expressao" => "SOMA(carga"]);
ler("totais teste sem funcao de total", ["recurso" => "totais", "parte" => "teste", "expressao" => "carga + 1"]);
ler("totais teste vazio", ["recurso" => "totais", "parte" => "teste", "expressao" => ""]);
// ESCOLHA nos totais calcula só o valor escolhido (como o SE): o primeiro, o segundo e um fora da lista (vazio)
foreach (["ESCOLHA(1; SOMA(carga); CONTA())" => "primeiro", "ESCOLHA(CONTA() / CONTA() + 1; SOMA(carga); CONTA())" => "segundo",
    "ESCOLHA(3; SOMA(carga); CONTA())" => "fora"] as $x => $qual) {
    ler("totais teste escolha " . $qual, ["recurso" => "totais", "parte" => "teste", "expressao" => $x]);
}
$total_novo = escrever(["recurso" => "totais", "acao" => "salvar", "nome" => "Carga somada", "expressao" => "SOMA(carga)", "coluna" => "", "rodape" => "0", "pagina" => "1", "largura" => "largo", "formato" => "porcentagem"]);
escrever(["recurso" => "totais", "acao" => "salvar", "nome" => "Sem coluna", "expressao" => "CONTA()", "coluna" => "", "rodape" => "1"], false);
escrever(["recurso" => "totais", "acao" => "salvar", "nome" => "Errado", "expressao" => "MEDIA(", "coluna" => "zzz", "largura" => "enorme", "formato" => "x"], false);
escrever(["recurso" => "totais", "acao" => "salvar", "id" => $total_novo, "expressao" => "MAIOR(carga)"]);
escrever(["recurso" => "totais", "acao" => "subir", "id" => $total_novo]);
ler("totais com o novo", ["recurso" => "totais", "parte" => "pagina"]);
escrever(["recurso" => "totais", "acao" => "excluir", "id" => $total_novo], false);
escrever(["recurso" => "totais", "acao" => "excluir", "id" => $total_novo, "confirmar" => 1]);

// a árvore: mover, excluir um grupo com relógio dentro (o relógio sobe), e excluir relógios (vão junto o histórico, a foto,
// os valores dos campos e o plano)
$sub_g = escrever(["recurso" => "arvore", "acao" => "novo", "nome" => "Vintage", "pai_id" => $cron_g]);
escrever(["recurso" => "arvore", "acao" => "mover", "id" => $sub_g, "pai_id" => 0]);
escrever(["recurso" => "arvore", "acao" => "relogios", "grupo" => [$rel["manual"] => $sub_g]]);
escrever(["recurso" => "arvore", "acao" => "excluir", "id" => $sub_g]);
escrever(["recurso" => "arvore", "acao" => "excluir", "id" => G(1)]);   // o grupo dos smartwatches: os campos, as fórmulas, os avisos e o relógio sobem
$lancs = ler("lancamentos do auto", ["incluir" => "relogios", "f" => ["relogios" => ["id" => $rel["auto"]]], "mostrar" => ["relogios" => "lancamentos"], "foto" => "nao"]);
$l0 = $lancs["relogios"][0]["lancamentos"][0]["id"] ?? 0;
escrever(["recurso" => "lancamento", "acao" => "alterar", "id" => $l0, "fim" => dia(2, "21:00")]);
escrever(["recurso" => "lancamento", "acao" => "periodo", "relogio_id" => $rel["auto"], "tipo" => "winder", "inicio" => dia(2, "20:00"), "fim" => dia(2, "23:00")], false);   // sobrepõe o pulso
escrever(["recurso" => "relogio", "acao" => "excluir", "id" => $rel["solar"]], false);   // sem confirmar: o impacto, e nada sai
escrever(["recurso" => "relogio", "acao" => "excluir", "id" => $rel["solar"], "confirmar" => 1]);
escrever(["recurso" => "relogio", "acao" => "excluir", "id" => $rel["auto"], "confirmar" => 1]);

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
    "blocos" => [["nome" => "Dias úteis", "dias" => [1, 2, 3, 4, 5], "no_id" => G(2), "um_por" => "dia", "relogio_id" => 0],
        ["nome" => "Fim de semana", "dias" => [6, 7], "no_id" => 0, "um_por" => "bloco", "relogio_id" => $rel["smart"]]]]);
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $fila]);
escrever(["recurso" => "rodizio", "acao" => "resortear_hoje"]);
ler("plano fila", ["recurso" => "plano"]);
// um roteiro com um só candidato e o ciclo ligado: o roteiro decide primeiro quem pode sair, e só entre os candidatos entram o
// "não repetir o de ontem" e o ciclo. Ele sai todo dia (o único candidato repete, com a nota no motivo), nunca um dia vazio
$so_um = escrever(["recurso" => "modos", "acao" => "salvar", "id" => 0, "nome" => "Só um, com ciclo", "tipo" => "personalizado", "semanas" => 1,
    "ciclo" => 1, "roteiro" => "candidato = RELOGIO(\"Xiaomi Band 8\")"]);
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $so_um]);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
// a resposta diz o que mudou no plano: cada dia que mudou vai para o único candidato, e a conta fecha com o resumo
$m_so_um = end($foto["escritas"])["resposta"]["mudancas"] ?? null;
if (!is_array($m_so_um) || !isset($m_so_um["mudaram"], $m_so_um["iguais"], $m_so_um["texto"], $m_so_um["dias"]) || $m_so_um["mudaram"] !== count($m_so_um["dias"])) {
    $falhas[] = "o que mudou no plano: a resposta do Sortear de novo sem mudancas completo";
} else {
    foreach ($m_so_um["dias"] as $x) {
        if ($x["depois"] === "relogio" && (int)$x["depois_relogio_id"] !== $rel["smart"]) {
            $falhas[] = "o que mudou no plano: " . $x["data"] . " foi para " . json_encode($x["depois_relogio_id"]);
        }
    }
}
$p_so_um = ler("plano com um só candidato", ["recurso" => "plano"]);
$dias_so_um = 0;
foreach ($p_so_um["plano"] ?? [] as $d) {
    if ($d["data"] > ($p_so_um["hoje"] ?? "") && ($d["origem"] ?? "sorteio") === "sorteio") {
        $dias_so_um++;
        if ((int)$d["relogio_id"] !== $rel["smart"]) {
            $falhas[] = "roteiro com um só candidato: " . $d["data"] . " saiu " . json_encode($d["relogio_id"]);
        }
    }
}
if ($dias_so_um === 0) {
    $falhas[] = "roteiro com um só candidato: nenhum dia sorteado depois de hoje";
}
$t_so_um = ler("roteiro com um só candidato", ["recurso" => "roteiro", "modo" => $so_um, "dia" => $em(2)]);
if ((int)($t_so_um["escolhido"]["relogio_id"] ?? 0) !== $rel["smart"]) {
    $falhas[] = "roteiro com um só candidato: o teste não escolheu o único candidato";
}
escrever(["recurso" => "modos", "acao" => "ativar", "id" => $por_nome["Aleatório todo dia"] ?? 0]);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
// a próxima semana, em qualquer dia (com os dias que faltam desta já contados como uso); o sortear de novo depois vai até o fim dela
escrever(["recurso" => "rodizio", "acao" => "proxima_semana"]);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
ler("plano com a próxima semana", ["recurso" => "plano"]);
// os relógios de um período, escolhidos à mão: dois, na ordem, dois dias seguidos cada; o sortear de novo não mexe neles, e
// depois uma parte do período é sorteada de novo e outra fica livre. Um período no passado é recusado
escrever(["recurso" => "rodizio", "acao" => "periodo", "de" => $em(10), "ate" => $em(15), "relogio_ids" => [$rel["manual"], $rel["crono"]], "dias_cada" => 2]);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
ler("plano com periodo", ["recurso" => "plano", "dias" => 30]);
escrever(["recurso" => "rodizio", "acao" => "periodo_sortear", "de" => $em(14), "ate" => $em(15)]);
// parte do período livre (sem relógio e sem sorteio), e um dia livre escolhido à mão
escrever(["recurso" => "rodizio", "acao" => "periodo_livre", "de" => $em(11), "ate" => $em(13)]);
escrever(["recurso" => "rodizio", "acao" => "trocar_dia", "data" => $em(12), "relogio_id" => $rel["crono"]]);
// um dia (livre) sorteado à mão por uma forma de escolha; a forma inválida é recusada
escrever(["recurso" => "rodizio", "acao" => "trocar_dia", "data" => $em(13), "relogio_id" => "sortear:aleatorio"]);
escrever(["recurso" => "rodizio", "acao" => "trocar_dia", "data" => $em(13), "relogio_id" => "sortear:x"], false);
escrever(["recurso" => "rodizio", "acao" => "resortear"]);
ler("plano com livres", ["recurso" => "plano", "dias" => 30]);
// o rodízio manual: os dias de um período de 7 em 7; gravar com um relógio escolhido, um dia livre e o resto no Decida por
// mim (decidido ao gravar, pela nota, sem repetir o de ontem e o de amanhã). Um período no passado e um relógio que não
// existe são recusados
ler("rodizio manual", ["recurso" => "rodizio_manual", "de" => $em(16), "ate" => $em(25), "pagina" => 2]);
escrever(["recurso" => "rodizio", "acao" => "manual", "de" => $em(16), "ate" => $em(25), "dias" => [$em(16) => $rel["manual"], $em(18) => "livre", $em(19) => "auto", $em(20) => "sortear:fifo", $em(21) => "sortear:inteligente"]]);
ler("rodizio manual gravado", ["recurso" => "rodizio_manual", "de" => $em(16), "ate" => $em(25)]);
ler("rodizio manual recusado", ["recurso" => "rodizio_manual", "de" => substr(dia(3, "00:00"), 0, 10), "ate" => $em(2)]);
escrever(["recurso" => "rodizio", "acao" => "manual", "de" => $em(16), "ate" => $em(17), "dias" => [$em(17) => 99999]], false);
// as listas em páginas (o "Mostrar até" da Configuração): os Próximos dias e o dia a dia do Plano, com o filtro de relógio
escrever(["recurso" => "config", "acao" => "salvar", "proximos_por_pagina" => 3, "plano_por_pagina" => 5]);
escrever(["recurso" => "config", "acao" => "salvar", "plano_por_pagina" => 0], false);
// o destaque no alto da página Hoje (a Configuração): o relógio no pulso agora, e de volta ao relógio do dia
escrever(["recurso" => "config", "acao" => "salvar", "destaque_hoje" => "pulso"]);
ler("hoje com o destaque no pulso", ["recurso" => "hoje"]);
escrever(["recurso" => "config", "acao" => "salvar", "destaque_hoje" => "agora"], false);
escrever(["recurso" => "config", "acao" => "salvar", "destaque_hoje" => "dia"]);
ler("proximos pagina 2", ["recurso" => "proximos", "pagina" => 2]);
ler("plano pagina 2", ["recurso" => "plano", "dias" => 30, "pagina" => 2]);
ler("plano de um relogio", ["recurso" => "plano", "dias" => "todos", "relogio" => $rel["manual"]]);
escrever(["recurso" => "rodizio", "acao" => "periodo", "de" => substr(dia(3, "00:00"), 0, 10), "ate" => $em(2), "relogio_ids" => [$rel["solar"]]], false);
// o lançamento no relógio do dia, sem saber o id dele
escrever(["recurso" => "lancamento", "acao" => "periodo", "relogio_id" => "hoje", "tipo" => "pulso", "inicio" => dia(20, "08:00"), "fim" => dia(20, "09:00")]);
// um aviso que sai na hora
escrever(["recurso" => "avisos", "acao" => "alterar", "id" => (int)(array_values(array_filter($cad["avisos"] ?? [], function ($a) { return $a["identificador"] === "leitura"; }))[0]["id"] ?? 0), "na_hora" => 1]);
// a situação na coleção e a lista de desejos: dois desejados (o disponível pedido é ignorado, com a nota), um campo só nos
// desejados, o preço-alvo recusado num relógio da coleção, uma situação que não existe; emprestar, vender (os dias dele no
// plano de amanhã em diante são sorteados de novo), comprar o desejado e trazer o emprestado de volta
$desejo = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Grand Seiko SBGA211", "no_id" => G(4), "posse" => "desejado", "disponivel" => 1,
    "valores" => ["marca" => "Grand Seiko", "referencia" => "SBGA211", "link" => "https://exemplo.com/sbga211", "preco_alvo" => "25.000,00", "prioridade_desejo" => "Alta"]]);
$desejo2 = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Casio G-Shock GW-M5610", "no_id" => G(8), "posse" => "desejado",
    "valores" => ["marca" => "Casio", "prioridade_desejo" => "Baixa"]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $desejo2, "disponivel" => 1]);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "onde_vi", "nome" => "Onde vi", "tipo" => "texto", "no_id" => 0, "so_desejado" => 1]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $desejo2, "valores" => ["onde_vi" => "Na vitrine da loja"]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "valores" => ["preco_alvo" => "100", "marca" => "Orient"]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "posse" => "perdido"], false);
ler("desejos", ["recurso" => "desejos"]);
ler("ficha do desejado", ["recurso" => "ficha", "relogio" => $desejo]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["pilha"], "posse" => "emprestado"]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["manual"], "posse" => "vendido"]);
ler("hoje com emprestado e vendido", ["recurso" => "hoje"]);
ler("plano depois de vender", ["recurso" => "plano", "dias" => 30]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $desejo, "posse" => "colecao", "valores" => ["data_compra" => substr(dia(0, "00:00"), 0, 10), "valor_compra" => "24500"]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["pilha"], "posse" => "colecao"]);
ler("desejos depois de comprar", ["recurso" => "desejos"]);
ler("ficha do comprado", ["recurso" => "ficha", "relogio" => $desejo]);
// e fora do rodízio daqui em diante: comprado hoje e sem corda, ele tem só segundos de linha do tempo e de reserva, e se a
// rodada do cron do fim o sorteasse para hoje, a energia e as partes do tempo dele dependeriam da velocidade do banco (o
// Firebird do CI, mais lento, saía diferente)
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $desejo, "disponivel" => 0]);
// os acessórios: de um relógio, solto, um tipo que não existe, mudar de relógio e excluir; o relógio excluído deixa o dele solto
$aces = escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Pulseira NATO verde", "tipo" => "pulseira", "relogio_id" => $rel["auto2"]]);
escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Caixa original", "tipo" => "caixa", "relogio_id" => $rel["auto2"], "observacao" => "Com o manual e o cartão"]);
escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Chave de abrir fundo", "tipo" => "ferramenta", "relogio_id" => 0]);
$temp = escrever(["recurso" => "relogio", "acao" => "salvar", "id" => 0, "nome" => "Relógio de passagem", "no_id" => 0]);
escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Carregador magnético", "tipo" => "carregador", "relogio_id" => $temp]);
escrever(["recurso" => "relogio", "acao" => "excluir", "id" => $temp, "confirmar" => 1]);
escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Chapéu", "tipo" => "chapeu"], false);
escrever(["recurso" => "acessorios", "acao" => "salvar", "id" => $aces, "relogio_id" => $rel["crono"]]);
escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Sem relógio", "relogio_id" => 99999], false);
ler("acessorios", ["recurso" => "acessorios"]);
ler("acessorios soltos", ["recurso" => "acessorios", "relogio" => 0]);
ler("ficha com acessorios", ["recurso" => "ficha", "relogio" => $rel["crono"]]);
escrever(["recurso" => "acessorios", "acao" => "excluir", "id" => $aces, "confirmar" => 1]);
// o aviso Encostado: com 5 dias, os relógios parados há mais tempo são avisados; 0 é recusado
// o aplicativo no celular: lembrar este aparelho (a chave vai num cookie, que o roteiro não guarda), a lista na conta,
// esquecer (id errado recusa; sem id e sem o cookie, não há "este"), e o manifesto e o service worker, sem login
$apa = escrever(["recurso" => "conta", "acao" => "aparelho_lembrar", "nome" => "Celular de teste"]);
ler("conta com aparelho lembrado", ["recurso" => "conta"]);
escrever(["recurso" => "conta", "acao" => "aparelho_esquecer", "id" => 999999], false);
escrever(["recurso" => "conta", "acao" => "aparelho_esquecer"], false);
escrever(["recurso" => "conta", "acao" => "aparelho_esquecer", "id" => $apa]);
ler("manifest", ["recurso" => "manifest"]);
ler("service worker", ["recurso" => "sw"]);
// o fuso horário: o da pessoa (Minha conta) vale no pedido dela: as horas do histórico saem convertidas (Nova York, uma hora
// a menos que São Paulo, o fuso do sistema); o da coleção vale para quem não escolheu; nome desconhecido é recusado; e o
// relógio da Hora certa escolhido na Configuração
escrever(["recurso" => "conta", "acao" => "alterar", "fuso" => "Marte/Olympus"], false);
escrever(["recurso" => "conta", "acao" => "alterar", "fuso" => "america/new_york"]);
ler("historico no fuso de Nova York", ["recurso" => "historico", "relogio" => $rel["smart"], "limite" => 3]);
ler("conta com o fuso", ["recurso" => "conta"]);
escrever(["recurso" => "conta", "acao" => "alterar", "fuso" => ""]);
escrever(["recurso" => "config", "acao" => "salvar", "fuso" => "Marte/Olympus"], false);
escrever(["recurso" => "config", "acao" => "salvar", "hora_relogio" => "cuco"], false);
escrever(["recurso" => "config", "acao" => "salvar", "fuso" => "Europe/Lisbon", "hora_relogio" => "anadigi"]);
ler("historico no fuso da coleção (Lisboa)", ["recurso" => "historico", "relogio" => $rel["smart"], "limite" => 3]);
ler("config com o fuso e o relógio", ["recurso" => "config"]);
escrever(["recurso" => "config", "acao" => "salvar", "fuso" => "", "hora_relogio" => ""]);
// "O do navegador": sem o cookie (e sem fuso_pedido=), vale o da coleção; com fuso_pedido=, o do pedido. O dia do rodízio
// é o da coleção em qualquer fuso. E o fuso de um destino: as horas no texto das mensagens dele
escrever(["recurso" => "conta", "acao" => "alterar", "fuso" => "navegador"]);
ler("conta no fuso do navegador, sem o cookie", ["recurso" => "conta"]);
ler("hora certa com fuso_pedido", ["recurso" => "hora_certa", "fuso_pedido" => "Asia/Tokyo"]);
ler("hoje com fuso_pedido (o dia é o da coleção)", ["recurso" => "hoje", "fuso_pedido" => "Asia/Tokyo"]);
escrever(["recurso" => "conta", "acao" => "alterar", "fuso" => ""]);
escrever(["recurso" => "destinos", "acao" => "salvar", "id" => $dest_email, "tipo" => "email", "fuso" => "Marte/Olympus"], false);
escrever(["recurso" => "destinos", "acao" => "salvar", "id" => $dest_email, "tipo" => "email", "fuso" => "Europe/Lisbon"]);
ler("destinos com o e-mail em Lisboa", ["recurso" => "destinos"]);
escrever(["recurso" => "destinos", "acao" => "salvar", "id" => $dest_email, "tipo" => "email", "fuso" => ""]);
escrever(["recurso" => "config", "acao" => "salvar", "encostado_dias" => 5]);
// a caixa Avisar quando um relógio fica encostado: desligada, o aviso some da Hoje (e da Configuração: encostado_ativo = 0)
escrever(["recurso" => "config", "acao" => "salvar", "encostado_ativo" => 0]);
ler("hoje sem o encostado", ["recurso" => "hoje"]);
ler("config sem o encostado", ["recurso" => "config"]);
escrever(["recurso" => "config", "acao" => "salvar", "encostado_ativo" => 1]);
// a cópia dos documentos no banco pela Configuração da coleção (todos, nenhum, vazio); valor errado recusa
escrever(["recurso" => "config", "acao" => "salvar", "documentos_copia" => "todos"]);
ler("config copia todos", ["recurso" => "config"]);
ler("ficha copia todos", ["recurso" => "ficha", "relogio" => $rel["smart"]]);
escrever(["recurso" => "config", "acao" => "salvar", "documentos_copia" => "nenhum"]);
ler("documentos copia nenhum", ["recurso" => "documentos", "relogio" => $rel["smart"]]);
escrever(["recurso" => "config", "acao" => "salvar", "documentos_copia" => "alguns"], false);
escrever(["recurso" => "config", "acao" => "salvar", "documentos_copia" => ""]);
// um documento cortado na pasta (uma cópia ou uma sincronização da pasta que parou no meio) volta inteiro da cópia no banco:
// ao ser pedido e na rodada do cron (documento_inteiro: o arquivo tem de ter o tamanho gravado no envio)
$cat_livre = 0;
foreach ((chamar("GET", ["recurso" => "documentos", "relogio" => $rel["smart"]])["json"]["categorias"] ?? []) as $c) {
    if ($c["aceita"] === [] && $cat_livre === 0) {
        $cat_livre = (int)$c["id"];
    }
}
$inteiro = str_repeat("Relojoeiro: o documento inteiro, conferido pelo tamanho. ", 400);
$doc_c = escrever(["recurso" => "documentos", "acao" => "enviar", "relogio_id" => $rel["smart"], "categoria_id" => $cat_livre, "titulo" => "Documento cortado",
    "arquivo_nome" => "cortado.txt", "arquivo_base64" => base64_encode($inteiro), "copia_banco" => 1]);
// corta o arquivo na pasta do relógio (a pasta dos documentos deste banco, a do config de teste) pela metade
$cortar = function () use ($rel, $inteiro) {
    $dir = getenv("RELOGIOS_TESTE_PASTA") . "/documentos_" . getenv("RELOGIOS_DB") . "/r" . $rel["smart"];
    foreach (glob($dir . "/*.txt") ?: [] as $f) {
        if ((string)file_get_contents($f) === $inteiro) {
            file_put_contents($f, substr($inteiro, 0, intdiv(strlen($inteiro), 2)));
            return $f;
        }
    }
    return null;
};
$cortado = $cortar();
if ($cortado === null) {
    $falhas[] = "documento cortado: o arquivo não está na pasta do relógio";
}
ler("documentos com um cortado", ["recurso" => "documentos", "relogio" => $rel["smart"]]);
$r_c = chamar("GET", ["recurso" => "documento", "id" => $doc_c]);
$foto["leituras"]["documento cortado pedido"] = ["codigo" => $r_c["codigo"], "resposta" => $r_c["cru"]];
if ($cortado !== null && ((string)@file_get_contents($cortado) !== $inteiro || ($r_c["cru"]["texto"] ?? "") !== $inteiro)) {
    $falhas[] = "documento cortado: pedido, não voltou inteiro do banco";
}
ler("documentos depois do pedido", ["recurso" => "documentos", "relogio" => $rel["smart"]]);
$cortado = $cortar();
cron();
if ($cortado !== null && (string)@file_get_contents($cortado) !== $inteiro) {
    $falhas[] = "documento cortado: o cron não o recriou do banco";
}
escrever(["recurso" => "documentos", "acao" => "excluir", "id" => $doc_c, "confirmar" => 1]);
escrever(["recurso" => "config", "acao" => "salvar", "encostado_dias" => 0], false);
ler("avisos com encostado", ["recurso" => "avisos"]);
// hoje sem relógio: o dia fica livre (o cron não põe ninguém no pulso nem sorteia de novo) e depois volta, escolhendo um
escrever(["recurso" => "rodizio", "acao" => "sem_relogio"]);
cron();
ler("hoje sem relogio", ["recurso" => "hoje"]);
escrever(["recurso" => "rodizio", "acao" => "trocar_dia", "data" => substr(dia(0, "00:00"), 0, 10), "relogio_id" => $rel["pilha"]]);
// hoje sorteado à mão pela nota: o sorteado vai para o pulso a partir de agora
escrever(["recurso" => "rodizio", "acao" => "trocar_dia", "data" => substr(dia(0, "00:00"), 0, 10), "relogio_id" => "sortear:ponderado"]);
// a versão do aviso Encostado para o grupo dos cronógrafos (um dia a mais): o vencimento dos dois relógios que acabaram de
// passar pelo pulso (o Casio e o Ômega, segundos um do outro) deixa de empatar no arredondamento de falta_dias, e a lista
// dos avisos sai na mesma ordem em qualquer banco (a rodada do Firebird no CI é mais lenta e virava a ordem)
escrever(["recurso" => "avisos", "acao" => "novo", "identificador" => "encostado", "nome" => "Encostado (cronógrafos)", "expressao" => "PADRAO(ULTIMA_DATA(\"pulso\"); data_compra) + CONFIG(\"encostado_dias\") + 1",
    "antecedencia_dias" => 0, "texto" => "o limite de dias sem ir ao pulso vence {quando}, {data}", "no_id" => $cron_g, "resolve" => "pulso"]);
// e outra para o grupo dos automáticos (dois dias a mais): o Orient e o Xiaomi saem do pulso a segundos um do outro (o
// "sem relógio hoje" e a troca logo depois), e os dois Encostados empatavam no arredondamento de falta_dias
escrever(["recurso" => "avisos", "acao" => "novo", "identificador" => "encostado", "nome" => "Encostado (automáticos)", "expressao" => "PADRAO(ULTIMA_DATA(\"pulso\"); data_compra) + CONFIG(\"encostado_dias\") + 2",
    "antecedencia_dias" => 0, "texto" => "o limite de dias sem ir ao pulso vence {quando}, {data}", "no_id" => G(4), "resolve" => "pulso"]);
ler("hoje depois do dia livre", ["recurso" => "hoje"]);
// apagar tudo: de amanhã em diante (o modo monta de novo), e depois de hoje em diante
escrever(["recurso" => "rodizio", "acao" => "apagar_tudo"]);
escrever(["recurso" => "rodizio", "acao" => "apagar_tudo", "hoje" => 1]);
ler("plano depois de apagar tudo", ["recurso" => "plano", "dias" => 30]);
ler("backup", ["recurso" => "backup"]);
escrever(["recurso" => "modos", "acao" => "excluir", "id" => $fila, "confirmar" => 1]);
escrever(["recurso" => "config", "acao" => "evento_excluir", "id" => 1, "confirmar" => 1]);
cron();

// a fotografia de novo, depois de tudo. Antes, 2 segundos: o trecho que começou agora (a troca do relógio de hoje) já tem
// duração em qualquer banco, rápido ou lento (lido no mesmo segundo, ele tem 0 s e não aparece na linha do tempo)
sleep(2);
ler("2 tudo", ["foto" => "nao"]);
foreach (["hoje", "avisos", "criterios", "historico", "plano", "eventos", "arvore", "cadastros", "config"] as $r) {
    ler("2 " . $r, ["recurso" => $r]);
}
// o passaporte de um relógio, um que não existe, e a galeria
ler("2 passaporte", ["recurso" => "passaporte", "relogio" => $rel["auto2"]]);
ler("2 passaporte que nao existe", ["recurso" => "passaporte", "relogio" => 99999]);
ler("2 galeria", ["recurso" => "galeria", "posse" => "todos"]);
// as estatísticas: os últimos 90 dias, desde o começo, e um período recusado
ler("2 estatisticas", ["recurso" => "estatisticas"]);
ler("2 estatisticas desde o comeco", ["recurso" => "estatisticas", "de" => "tudo"]);
ler("2 estatisticas recusadas", ["recurso" => "estatisticas", "de" => "2026-13-01"]);
// os relatórios (a porta única): o catálogo; cada um, com os parâmetros de exemplo (o relógio, o período, as opções); a
// planilha de um; e os erros (o tipo que não existe, o passaporte sem o relógio, o relógio que não existe, a data errada,
// o período ao contrário)
$cat_r = ler("2 relatorios", ["recurso" => "relatorios"]);
$tipos_r = array_column($cat_r["relatorios"] ?? [], "tipo");
if (count($tipos_r) !== 18) {
    $falhas[] = "relatórios: o catálogo tem " . count($tipos_r) . " (" . implode(", ", $tipos_r) . ")";
}
foreach ([["colecao", []], ["colecao", ["posse" => "todos"]], ["passaporte", ["relogio" => $rel["manual"]]], ["passaporte", ["relogio" => $rel["auto2"]]], ["custos", []],
    ["desejos", []], ["acessorios", []], ["acessorios", ["relogio" => $rel["crono"]]], ["documentos", []], ["historico", ["relogio" => $rel["smart"]]],
    ["historico", ["de" => substr(dia(12, "00:00"), 0, 10), "ate" => substr(dia(1, "00:00"), 0, 10)]], ["estatisticas", ["de" => "tudo"]], ["estatisticas", []],
    ["plano", []], ["avisos", []], ["avisos", ["relogio" => $rel["smart"]]], ["criterios", []], ["servicos", []], ["servicos", ["relogio" => $rel["auto2"]]],
    ["precisao", []], ["precisao", ["relogio" => $rel["auto2"]]], ["timegrapher", []], ["timegrapher", ["relogio" => $rel["manual"]]], ["reserva", []],
    ["reserva", ["relogio" => $rel["manual"]]], ["calibres", []], ["totais", []], ["cron", []], ["cron", ["situacao" => "todas"]]] as [$tp_r, $par_r]) {
    $rr = ler("2 relatorio " . $tp_r . " " . json_encode($par_r), array_merge(["recurso" => "relatorios", "tipo" => $tp_r], $par_r));
    if (!is_array($rr) || ($rr["tipo"] ?? "") !== $tp_r || !isset($rr["secoes"]) || count($rr["secoes"]) === 0) {
        $falhas[] = "relatório " . $tp_r . ": " . substr(json_encode($rr, JSON_UNESCAPED_UNICODE), 0, 300);
    }
}
ler("2 relatorio custos em csv", ["recurso" => "relatorios", "tipo" => "custos", "csv" => 1]);
foreach ([["tipo" => "nao_existe"], ["tipo" => "passaporte"], ["tipo" => "passaporte", "relogio" => 99999], ["tipo" => "historico", "de" => "2026-13-01"],
    ["tipo" => "servicos", "de" => "2026-05-01", "ate" => "2026-04-01"]] as $par_r) {
    $rr = ler("2 relatorio recusado " . json_encode($par_r), array_merge(["recurso" => "relatorios"], $par_r));
    if (!isset($rr["erro"])) {
        $falhas[] = "relatório recusado: " . json_encode($par_r) . " passou";
    }
}

// (no fim, depois das leituras que contam o tempo das sessões abertas: estes passos não atrasam aquelas leituras no banco
// mais lento.) O Orient fica com o NH35 durante as regras do valor
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "calibre_id" => $cal]);
// as regras do valor: o campo múltiplo do movimento (as complicações: o NH35 define uma; o Orient com o calibre usa a dele e
// depois marca duas, guardadas uma por linha na ordem dos valores possíveis, mesmo escritas em minúsculas), as recusas (o
// múltiplo sem valores possíveis, o mínimo acima do máximo, um valor possível que não é número, o valor fora da faixa ou
// fora dos valores possíveis, a regra nova que os valores gravados não seguem: deixar de ser múltiplo com dois, o máximo
// abaixo do gravado), as funções TEM e ITENS, o passaporte com os valores separados por vírgula e a volta (múltiplo de
// novo, com um só)
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "complicacoes_teste", "nome" => "Complicações de teste", "tipo" => "texto", "multiplo" => 1], false);
$c_comp = escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "complicacoes_teste", "nome" => "Complicações de teste", "tipo" => "lista",
    "opcoes" => "Data\nGMT\nCronógrafo", "multiplo" => 1, "calibre" => 1]);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "diametro_teste", "nome" => "Diâmetro de teste", "tipo" => "decimal", "unidade" => "mm", "minimo" => "50", "maximo" => "30"], false);
$c_diam = escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "diametro_teste", "nome" => "Diâmetro de teste", "tipo" => "decimal", "unidade" => "mm", "minimo" => "30", "maximo" => "50"]);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "largura_teste", "nome" => "Largura de teste", "tipo" => "inteiro", "unidade" => "mm", "opcoes" => "18\n20\nvinte e dois"], false);
$c_larg = escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "largura_teste", "nome" => "Largura de teste", "tipo" => "inteiro", "unidade" => "mm", "opcoes" => "18\n20\n22"]);
escrever(["recurso" => "calibres", "acao" => "salvar", "id" => $cal, "valores" => ["complicacoes_teste" => ["Data"]]]);
$f_r1 = ler("ficha com o multiplo do calibre", ["recurso" => "ficha", "relogio" => $rel["auto2"]]);
$d_r1 = array_column($f_r1["relogio"]["dados"] ?? [], null, "identificador");
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "valores" => ["complicacoes_teste" => ["cronógrafo", "Data"], "diametro_teste" => "42,5", "largura_teste" => "20"]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["manual"], "valores" => ["complicacoes_teste" => ["Lua"], "diametro_teste" => "60", "largura_teste" => "21"]]);
$f_r2 = ler("ficha com o multiplo", ["recurso" => "ficha", "relogio" => $rel["auto2"]]);
$d_r2 = array_column($f_r2["relogio"]["dados"] ?? [], null, "identificador");
$f_r3 = ler("ficha com os valores recusados", ["recurso" => "ficha", "relogio" => $rel["manual"]]);
$d_r3 = array_column($f_r3["relogio"]["dados"] ?? [], null, "identificador");
$p_r = ler("passaporte com o multiplo", ["recurso" => "passaporte", "relogio" => $rel["auto2"]]);
$p_comp = array_values(array_filter($p_r["cadastro"] ?? [], function ($c) { return $c["identificador"] === "complicacoes_teste"; }))[0]["valor"] ?? null;
$calc_r = ler("tem e itens", ["recurso" => "calcular", "relogio" => $rel["auto2"], "expressao" => "TEM(complicacoes_teste; \"data\") * 100 + TEM(complicacoes_teste; \"GMT\") * 10 + ITENS(complicacoes_teste)"]);
$v_r = $calc_r["resultados"][0]["valor"] ?? null;
if (($d_r1["complicacoes_teste"]["valor_usado"] ?? null) !== "Data" || ($d_r1["complicacoes_teste"]["origem"] ?? "") !== "calibre"
    || ($d_r2["complicacoes_teste"]["valor"] ?? null) !== "Data\nCronógrafo" || ($d_r2["complicacoes_teste"]["multiplo"] ?? null) !== true
    || (string)($d_r2["diametro_teste"]["valor"] ?? "") !== "42.5" || !isset($d_r3["diametro_teste"], $d_r3["largura_teste"], $d_r3["complicacoes_teste"])
    || $d_r3["diametro_teste"]["valor"] !== null || $d_r3["largura_teste"]["valor"] !== null || $d_r3["complicacoes_teste"]["valor"] !== null || $p_comp !== "Data, Cronógrafo" || $v_r != 102) {
    $falhas[] = "regras do valor: " . json_encode([$d_r1["complicacoes_teste"] ?? null, $d_r2["complicacoes_teste"] ?? null, $d_r2["diametro_teste"] ?? null, $d_r3["diametro_teste"] ?? null, $p_comp, $v_r], JSON_UNESCAPED_UNICODE);
}
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $c_comp, "multiplo" => 0], false);
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $c_diam, "maximo" => "40"], false);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "valores" => ["complicacoes_teste" => ["GMT"]]]);
escrever(["recurso" => "calibres", "acao" => "salvar", "id" => $cal, "valores" => ["complicacoes_teste" => ""]]);
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $c_comp, "multiplo" => 0]);
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $c_comp, "multiplo" => 1]);
$cad_r = ler("cadastros com as regras", ["recurso" => "cadastros"]);
$f_r4 = ler("ficha de volta ao multiplo", ["recurso" => "ficha", "relogio" => $rel["auto2"]]);
$d_r4 = array_column($f_r4["relogio"]["dados"] ?? [], null, "identificador");
$diam_r = array_values(array_filter($cad_r["campos"] ?? [], function ($c) { return $c["identificador"] === "diametro_teste"; }))[0] ?? [];
if (($d_r4["complicacoes_teste"]["valor"] ?? null) !== "GMT" || ($diam_r["maximo"] ?? null) != 50 || ($diam_r["regra"] ?? null) === null) {
    $falhas[] = "regras do valor, depois: " . json_encode([$d_r4["complicacoes_teste"] ?? null, $diam_r], JSON_UNESCAPED_UNICODE);
}
foreach ([$c_comp, $c_diam, $c_larg] as $c_r) {
    escrever(["recurso" => "campos", "acao" => "excluir", "id" => $c_r, "confirmar" => 1]);
}

// a construção pronta e os campos dos acessórios: os do modelo (o material da caixa na divisória Construção, as funções
// múltiplas, a largura da pulseira que bate com o entre alças), a pulseira solta de 20 mm (serve no automático, de entre
// alças 20, e não no manual, de 22), as recusas (o campo da pulseira num estojo, a largura fora da faixa, o tipo de
// acessório que não existe, o campo do relógio que não existe, mudar de relógio para acessório com valores), o campo de
// acessório de todos os tipos, a troca de tipo (os valores que não são do tipo novo saem) e a exclusão
$cad_c = ler("cadastros com a construcao", ["recurso" => "cadastros"]);
$por_ident = array_column($cad_c["campos"] ?? [], null, "identificador");
$sec_c = array_column($cad_c["secoes"] ?? [], "id", "nome");
if (($por_ident["material_caixa"]["secao_id"] ?? null) !== ($sec_c["Construção"] ?? -1) || ($por_ident["funcoes"]["multiplo"] ?? 0) !== 1
    || ($por_ident["pulseira_largura"]["alvo"] ?? "") !== "acessorio" || ($por_ident["pulseira_largura"]["serve_campo_id"] ?? null) !== ($por_ident["entre_alcas"]["id"] ?? -1)) {
    $falhas[] = "construção: o modelo não veio (" . json_encode([$por_ident["material_caixa"] ?? null, $por_ident["pulseira_largura"] ?? null], JSON_UNESCAPED_UNICODE) . ")";
}
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "valores" => ["entre_alcas" => "20", "material_caixa" => "aço inoxidável", "funcoes" => ["Data", "Dia da semana"]]]);
escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["manual"], "valores" => ["entre_alcas" => "22"]]);
$ac_p = escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Pulseira de couro 20", "tipo" => "pulseira", "relogio_id" => 0,
    "valores" => ["pulseira_largura" => "20", "pulseira_material" => "couro", "pulseira_cor" => "Marrom"]]);
escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Estojo errado", "tipo" => "estojo", "valores" => ["pulseira_largura" => "20"]], false);
escrever(["recurso" => "acessorios", "acao" => "salvar", "nome" => "Larga demais", "tipo" => "pulseira", "valores" => ["pulseira_largura" => "99"]], false);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "acessorio_x", "nome" => "X", "tipo" => "texto", "alvo" => "acessorio", "acessorio_tipo" => "foguete"], false);
escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "acessorio_x", "nome" => "X", "tipo" => "texto", "alvo" => "acessorio", "serve_campo_id" => 999999], false);
escrever(["recurso" => "campos", "acao" => "alterar", "id" => $por_ident["entre_alcas"]["id"] ?? 0, "alvo" => "acessorio"], false);
$c_nota = escrever(["recurso" => "campos", "acao" => "novo", "identificador" => "acessorio_nota_teste", "nome" => "Nota do acessório", "tipo" => "texto", "alvo" => "acessorio"]);
$ac_l = ler("acessorios com a construcao", ["recurso" => "acessorios"]);
$ac_couro = array_values(array_filter($ac_l["acessorios"] ?? [], function ($a) use ($ac_p) { return $a["id"] === $ac_p; }))[0] ?? [];
$f_ac = ler("ficha com a pulseira que serve", ["recurso" => "ficha", "relogio" => $rel["auto2"]]);
$f_ac2 = ler("ficha sem pulseira que serve", ["recurso" => "ficha", "relogio" => $rel["manual"]]);
$p_ac = ler("passaporte com a construcao", ["recurso" => "passaporte", "relogio" => $rel["auto2"]]);
$p_con = array_values(array_filter($p_ac["cadastro_secoes"] ?? [], function ($g) { return $g["secao"] === "Construção"; }))[0]["campos"] ?? [];
if (array_column($ac_couro["serve_em"] ?? [], "relogio_id") !== [$rel["auto2"]] || count($ac_couro["valores"] ?? []) !== 3
    || array_column($f_ac["relogio"]["acessorios_servem"] ?? [], "id") !== [$ac_p] || count($f_ac2["relogio"]["acessorios_servem"] ?? [1]) !== 0
    || count(array_filter($p_con, function ($c) { return $c["identificador"] === "funcoes" && $c["valor"] === "Data, Dia da semana"; })) !== 1
    || !in_array("acessorio_nota_teste", array_column($ac_l["campos"] ?? [], "identificador"), true)) {
    $falhas[] = "acessórios com campos: " . json_encode([$ac_couro, $f_ac["relogio"]["acessorios_servem"] ?? null, $p_con], JSON_UNESCAPED_UNICODE);
}
escrever(["recurso" => "acessorios", "acao" => "salvar", "id" => $ac_p, "tipo" => "estojo", "valores" => ["acessorio_nota_teste" => "virou estojo"]]);
$ac_l2 = ler("acessorio que trocou de tipo", ["recurso" => "acessorios"]);
$ac_e = array_values(array_filter($ac_l2["acessorios"] ?? [], function ($a) use ($ac_p) { return $a["id"] === $ac_p; }))[0] ?? [];
if (array_column($ac_e["valores"] ?? [], "identificador") !== ["acessorio_nota_teste"] || !array_key_exists("serve_em", $ac_e) || $ac_e["serve_em"] !== null) {
    $falhas[] = "acessório que trocou de tipo: " . json_encode($ac_e, JSON_UNESCAPED_UNICODE);
}
escrever(["recurso" => "acessorios", "acao" => "excluir", "id" => $ac_p, "confirmar" => 1]);
escrever(["recurso" => "campos", "acao" => "excluir", "id" => $c_nota, "confirmar" => 1]);

escrever(["recurso" => "relogio", "acao" => "salvar", "id" => $rel["auto2"], "calibre_id" => "0"]);

$texto = json_encode($foto, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
if ($texto === false) {
    fwrite(STDERR, "Não consegui gravar a fotografia: " . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($SAIDA, $texto);
// ao lado da fotografia (sqlite.json: sqlite.dados.json), os textos gravados (guardar_dados)
file_put_contents(preg_replace('/\.json$/', '', $SAIDA) . ".dados.json", json_encode(array_keys($dados_escritos), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo count($foto["escritas"]) . " escritas, " . count($foto["leituras"]) . " leituras, " . count($foto["cron"]) . " rodadas do cron\n";
if ($falhas) {
    echo "Escritas recusadas:\n  " . implode("\n  ", $falhas) . "\n";
    exit(1);
}
