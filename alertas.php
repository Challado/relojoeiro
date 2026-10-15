<?php
// Os alertas: os destinos das mensagens de cada coleção (um webhook HTTP, um e-mail, o Web Push), as agendas (Google,
// CalDAV, Outlook e o link ICS), o envio de e-mail (SMTP ou o mail() do PHP, com o IMAP/POP para testar e guardar a cópia
// em Enviados) e o Web Push (com as chaves VAPID). Parte do lib.php (o lib.php inclui este arquivo).
// Cada destino e cada agenda tem a sua mensagem padrão (o modelo, com as âncoras {relogio}, {acao}, {motivo}...), escolhe
// o que recebe (o relógio do dia, a véspera, cada aviso pelo identificador, cada evento) e pode ter um modelo próprio para
// cada um. O idioma: o do destino, senão o da coleção (o Web Push: o do dono do aparelho).
if (!function_exists("cfg")) {
    http_response_code(404);
    exit;
}

const DESTINO_TIPOS = ["webhook" => "Webhook (HTTP)", "email" => "E-mail", "webpush" => "Web Push"];
const AGENDA_TIPOS = ["google" => "Google Agenda", "caldav" => "CalDAV (Nextcloud, iCloud, Fastmail...)", "outlook" => "Outlook (Microsoft 365)",
    "ics" => "Link ICS (assinar em qualquer app)"];
// (os nomes saem traduzidos no uso, t(DESTINO_TIPOS[...]); aqui, a marca para a conferência dos dicionários)
tk("Webhook (HTTP)");
tk("E-mail");
tk("Web Push");
tk("Google Agenda");
tk("CalDAV (Nextcloud, iCloud, Fastmail...)");
tk("Outlook (Microsoft 365)");
tk("Link ICS (assinar em qualquer app)");

// Os endereços de fora (o config.php pode trocar, para testes)
foreach (["GOOGLE_TOKEN_URL" => "https://oauth2.googleapis.com/token", "GOOGLE_API_URL" => "https://www.googleapis.com/calendar/v3",
    "OUTLOOK_LOGIN_URL" => "https://login.microsoftonline.com/common/oauth2/v2.0", "OUTLOOK_GRAPH_URL" => "https://graph.microsoft.com/v1.0"] as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Um pedido HTTP (curl): [código, corpo, cabeçalhos da resposta]
// ---------------------------------------------------------------------------------------------------------------------
function http_pedido($metodo, $url, $cabecalhos = [], $corpo = null, $usuario = null, $senha = null, $externo = false)
{
    // o endereço que o usuário escreveu (webhook, CalDAV, Web Push), fora das coleções do master: só na internet, nunca na rede
    // do servidor (o endereço fica preso ao IP conferido, para o nome não mudar de IP no meio)
    $fixo = null;
    if ($externo) {
        [$erro_url, $fixo] = url_externa($url);
        if ($erro_url !== "") {
            return [0, "", [], $erro_url];
        }
    }
    $ch = curl_init($url);
    $resp_cab = [];
    $op = [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => "Relojoeiro/1.0", CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$resp_cab) {
            $p = explode(":", $l, 2);
            if (count($p) === 2) {
                $resp_cab[strtolower(trim($p[0]))] = trim($p[1]);
            }
            return strlen($l);
        }];
    if ($corpo !== null) {
        $op[CURLOPT_POSTFIELDS] = $corpo;
    }
    if ($usuario !== null) {
        $op[CURLOPT_USERPWD] = $usuario . ":" . (string)$senha;
        $op[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    if ($fixo !== null) {
        $op[CURLOPT_RESOLVE] = [$fixo];
        $op[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $op);
    $resp = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = $resp === false ? curl_error($ch) : "";
    return [$codigo, $resp === false ? "" : (string)$resp, $resp_cab, $erro];
}

// Confere um endereço de fora: http ou https, e o nome resolve só para IPs da internet (nada de 127.0.0.1, 10.x, 192.168.x,
// 169.254.x, ::1...). Devolve [erro (vazio: serve), "host:porta:ip" para prender o nome ao IP conferido]
function url_externa($url)
{
    $p = parse_url((string)$url);
    $host = strtolower(trim((string)($p["host"] ?? ""), "[]"));
    if (!in_array(strtolower((string)($p["scheme"] ?? "")), ["http", "https"], true) || $host === "") {
        return [t("endereço inválido: use http:// ou https://"), null];
    }
    $porta = (int)($p["port"] ?? (strtolower($p["scheme"]) === "https" ? 443 : 80));
    $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : array_merge(@gethostbynamel($host) ?: [],
        array_column(@dns_get_record($host, DNS_AAAA) ?: [], "ipv6"));
    if (count($ips) === 0) {
        return [t("o endereço {1} não existe (o nome não resolve)", [1 => $host]), null];
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return [t("o endereço {1} é da rede interna do servidor: só o master usa endereços assim", [1 => $host]), null];
        }
    }
    return ["", $host . ":" . $porta . ":" . (strpos($ips[0], ":") !== false ? "[" . $ips[0] . "]" : $ips[0])];
}

// Se a coleção do pedido é de um master (os endereços da rede interna valem nela)
function colecao_do_master()
{
    $c = colecao_atual();
    return $c !== null && sem_escopo(function () use ($c) { return valor("SELECT u.papel FROM colecao c JOIN usuario u ON u.id = c.dono_id WHERE c.id = ?", [(int)$c]); }) === "master";
}

// O endereço do sistema (para os links das mensagens, do ICS e do retorno do Outlook): o da Configuração do master
// (url_sistema), senão o do pedido de agora
function url_do_sistema()
{
    $u = rtrim(sis("url_sistema"), "/");
    if ($u === "" && isset($_SERVER["HTTP_HOST"])) {
        $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") || ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https";
        $u = ($https ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"] . rtrim(str_replace("\\", "/", dirname((string)($_SERVER["SCRIPT_NAME"] ?? "/"))), "/");
    }
    return $u;
}

// ---------------------------------------------------------------------------------------------------------------------
// Os destinos da coleção
// ---------------------------------------------------------------------------------------------------------------------

// Os destinos da coleção do pedido, cada um com o que é do tipo dele e o que recebe: [id => destino]
function destinos($recarregar = false)
{
    static $cache = [];
    $c = (int)colecao_atual();
    if ($recarregar || !isset($cache[$c])) {
        $res = [];
        foreach (linhas("SELECT * FROM destino ORDER BY nome, id") as $d) {
            $id = (int)$d["id"];
            $x = ["id" => $id, "nome" => $d["nome"], "tipo" => $d["tipo"], "ativo" => (int)$d["ativo"] === 1, "modelo" => $d["modelo"], "idioma" => $d["idioma"],
                "avisos" => [], "eventos" => []];
            foreach (linhas("SELECT tipo_aviso, envia, modelo_proprio FROM destino_aviso WHERE destino_id = ?", [$id]) as $a) {
                $x["avisos"][$a["tipo_aviso"]] = ["envia" => (int)$a["envia"] === 1, "modelo" => $a["modelo_proprio"]];
            }
            foreach (linhas("SELECT evento_id, envia, modelo_proprio FROM destino_evento WHERE destino_id = ?", [$id]) as $a) {
                $x["avisos"]["ev" . (int)$a["evento_id"]] = ["envia" => (int)$a["envia"] === 1, "modelo" => $a["modelo_proprio"]];
            }
            if ($d["tipo"] === "webhook") {
                $w = linha("SELECT * FROM destino_webhook WHERE destino_id = ?", [$id]);
                $x["webhook"] = ["metodo" => $w["metodo"] ?? "GET", "url" => $w["url"] ?? "", "formato" => $w["formato"] ?? "query", "corpo_json" => $w["corpo_json"] ?? null,
                    "campos" => array_map(function ($c) { return ["nome" => $c["nome"], "valor" => $c["valor"]]; },
                        linhas("SELECT nome, valor FROM destino_webhook_campo WHERE destino_id = ? ORDER BY ordem, nome", [$id])),
                    "cabecalhos" => array_map(function ($c) { return ["nome" => $c["nome"], "valor_cifrado" => $c["valor_cifrado"]]; },
                        linhas("SELECT nome, valor_cifrado FROM destino_webhook_cabecalho WHERE destino_id = ? ORDER BY nome", [$id]))];
            } elseif ($d["tipo"] === "email") {
                $x["email"] = ["para" => (string)valor("SELECT para FROM destino_email WHERE destino_id = ?", [$id])];
            } else {
                $u = valor("SELECT usuario_id FROM destino_webpush WHERE destino_id = ?", [$id]);
                $x["webpush"] = ["usuario_id" => $u === null ? null : (int)$u];
            }
            $res[$id] = $x;
        }
        $cache[$c] = $res;
    }
    return $cache[$c];
}

// O que um destino (ou uma agenda) recebe de um tipo (dia, vespera, o identificador de um aviso, ev<id>)
function alvo_envia($alvo, $tipo)
{
    return !empty($alvo["avisos"][$tipo]["envia"]);
}

// O modelo de um tipo num destino ou numa agenda: o próprio, se tem; senão a mensagem padrão dele
function alvo_modelo($alvo, $tipo)
{
    $p = $alvo["avisos"][$tipo]["modelo"] ?? null;
    return $p !== null && trim((string)$p) !== "" ? (string)$p : (string)$alvo["modelo"];
}

// O idioma de um destino ou de uma agenda: o dele, senão o da coleção
function alvo_idioma($alvo)
{
    return lingua_valida($alvo["idioma"] ?? null) ?? (lingua_valida(cfg("idioma")) ?? "pt-BR");
}

// Os itens de uma mensagem viram o texto: cada um pelo modelo do tipo, separados por linha em branco (sem itens, vazio)
function mensagem_itens($alvo, $itens, $hoje)
{
    $blocos = [];
    foreach ($itens as $it) {
        if (alvo_envia($alvo, $it["tipo"])) {
            $blocos[] = aplicar_modelo(alvo_modelo($alvo, $it["tipo"]), contexto_mensagem($it["id"], ["acao" => $it["fazer"], "motivo" => $it["motivo"],
                "ate" => $it["ate"], "tipo_aviso" => $it["tipo"], "quantidade" => (string)count($itens)], $hoje));
        }
    }
    return implode("\n\n", array_filter($blocos, function ($b) { return $b !== ""; }));
}

// Os itens da manhã: o relógio do dia e os avisos atrasados ou em breve (cada destino escolhe quais recebe)
function itens_manha($hoje)
{
    $itens = [];
    $dia = plano_do_dia($hoje->format("Y-m-d"));
    if ($dia) {
        $ate = texto_ate($dia["data"], "hoje");
        $itens[] = ["tipo" => "dia", "id" => (int)$dia["relogio_id"], "fazer" => t("Usar hoje"), "motivo" => $ate, "ate" => $ate];
    }
    foreach (avisos_de_hoje() as $x) {
        $itens[] = ["tipo" => $x[1]["identificador"], "id" => (int)$x[0]["id"], "fazer" => $x[1]["nome"], "motivo" => $x[1]["texto"], "ate" => ""];
    }
    return $itens;
}

// Os itens da noite: se amanhã entra outro relógio, o que fazer hoje para ele estar pronto (nenhum: "É só usar.")
function itens_noite($hoje)
{
    $d = plano_do_dia($hoje->modify("+1 day")->format("Y-m-d"));
    $h = plano_do_dia($hoje->format("Y-m-d"));
    if (!$d || ($h && (int)$h["relogio_id"] === (int)$d["relogio_id"])) {
        return [];
    }
    $preparo = preparo_do_dia(linha("SELECT * FROM relogio WHERE id = ?", [(int)$d["relogio_id"]]), $d["data"]);
    return [["tipo" => "vespera", "id" => (int)$d["relogio_id"], "fazer" => t("Preparar para amanhã"),
        "motivo" => count($preparo) > 0 ? implode("\n", $preparo) : t("É só usar."), "ate" => texto_ate($d["data"], t("amanhã"))]];
}

// A mensagem de um destino para os itens que $construir monta (chamado no idioma do destino). Devolve o texto
function mensagem_do_destino($dest, $construir, $hoje)
{
    $antes = lingua_atual();
    lingua_definir(alvo_idioma($dest));
    try {
        return mensagem_itens($dest, $construir(), $hoje);
    } finally {
        lingua_definir($antes);
    }
}

// Manda os itens para todos os destinos ativos da coleção (cada um com os itens que escolheu, no idioma dele). $titulo: o
// título da mensagem (o assunto do e-mail, o título da notificação). Devolve {enviados, falhas, linhas (o registro)}
function alertas_enviar($construir, $hoje, $titulo, $o_que)
{
    $res = ["enviados" => 0, "falhas" => 0, "linhas" => []];
    foreach (destinos() as $dest) {
        if (!$dest["ativo"]) {
            continue;
        }
        $texto = mensagem_do_destino($dest, $construir, $hoje);
        if ($texto === "") {
            continue;
        }
        [$ok, $erro] = destino_enviar($dest, $texto, $titulo);
        if ($ok) {
            $res["enviados"]++;
            $res["linhas"][] = t("{1} enviado para {2}", [1 => $o_que, 2 => $dest["nome"]]);
        } else {
            $res["falhas"]++;
            $res["linhas"][] = registro_erro(t("erro: {1} não chegou a {2}: {3}", [1 => $o_que, 2 => $dest["nome"], 3 => $erro]));
        }
    }
    return $res;
}

// Manda um texto por um destino: [ok, o erro]
function destino_enviar($dest, $texto, $titulo)
{
    if ($dest["tipo"] === "webhook") {
        return webhook_enviar($dest["webhook"], $texto, $titulo);
    }
    if ($dest["tipo"] === "email") {
        return email_enviar($dest["email"]["para"], $titulo, $texto);
    }
    return webpush_enviar_usuario($dest["webpush"]["usuario_id"], $titulo, $texto);
}

// O webhook: a mensagem longa vai em partes (o Telegram aceita uns 4.000 caracteres e a mensagem pode ir na URL): passando de
// 3.000, quebra entre um bloco e outro, e cada parte é um pedido. Os campos (e o corpo JSON) são modelos com {titulo} e
// {mensagem}. Os cabeçalhos vão como estão (o valor é guardado cifrado)
function webhook_enviar($w, $texto, $titulo)
{
    $partes = [];
    $atual = "";
    foreach (explode("\n\n", $texto) as $bloco) {
        if ($atual !== "" && strlen($atual) + 2 + strlen($bloco) > 3000) {
            $partes[] = $atual;
            $atual = "";
        }
        $atual = $atual === "" ? $bloco : $atual . "\n\n" . $bloco;
    }
    if ($atual !== "") {
        $partes[] = $atual;
    }
    $cab = [];
    foreach ($w["cabecalhos"] as $c) {
        $cab[] = $c["nome"] . ": " . (string)segredo_abrir($c["valor_cifrado"]);
    }
    foreach ($partes as $parte) {
        $troca = ["{titulo}" => $titulo, "{mensagem}" => $parte];
        $campos = [];
        foreach ($w["campos"] as $c) {
            $campos[$c["nome"]] = strtr((string)$c["valor"], $troca);
        }
        $url = $w["url"];
        $corpo = null;
        $cab_p = $cab;
        if ($w["formato"] === "query") {
            $url .= count($campos) > 0 ? (strpos($url, "?") === false ? "?" : "&") . http_build_query($campos) : "";
        } elseif ($w["formato"] === "form") {
            $corpo = http_build_query($campos);
            $cab_p[] = "Content-Type: application/x-www-form-urlencoded";
        } else {
            if (trim((string)$w["corpo_json"]) !== "") {
                // dentro do JSON, o texto entra já escapado (sem as aspas: o modelo tem as aspas)
                $corpo = strtr((string)$w["corpo_json"], ["{titulo}" => substr((string)json_encode($titulo, JSON_UNESCAPED_UNICODE), 1, -1),
                    "{mensagem}" => substr((string)json_encode($parte, JSON_UNESCAPED_UNICODE), 1, -1)]);
            } else {
                $corpo = json_encode($campos, JSON_UNESCAPED_UNICODE);
            }
            $cab_p[] = "Content-Type: application/json";
        }
        [$codigo, , , $erro] = http_pedido($w["metodo"], $url, $cab_p, $corpo, null, null, !colecao_do_master());
        if ($codigo < 200 || $codigo >= 300) {
            return [false, $erro !== "" ? $erro : t("o endereço respondeu {1}", [1 => $codigo])];
        }
    }
    return [true, ""];
}

// ---------------------------------------------------------------------------------------------------------------------
// O e-mail, só para enviar: SMTP (EMAIL_METODO = "smtp", com SMTP_HOST, SMTP_PORTA, SMTP_SEGURANCA (ssl, tls ou nada),
// SMTP_USUARIO e SMTP_SENHA) ou o mail() do PHP ("mail"). EMAIL_REMETENTE é o From. Com IMAP_HOST (e IMAP_PORTA,
// IMAP_SEGURANCA, IMAP_USUARIO, IMAP_SENHA, IMAP_PASTA_ENVIADOS), cada e-mail enviado ganha uma cópia na pasta Enviados.
// POP3 (POP_HOST, POP_PORTA, POP_SEGURANCA, POP_USUARIO, POP_SENHA) só serve para testar a conta.
// ---------------------------------------------------------------------------------------------------------------------
function cfg_php($nome, $padrao = "")
{
    return defined($nome) ? constant($nome) : $padrao;
}

function email_metodo()
{
    $m = strtolower(trim((string)cfg_php("EMAIL_METODO", "")));
    if ($m === "" && trim((string)cfg_php("SMTP_HOST", "")) !== "") {
        $m = "smtp";
    }
    return in_array($m, ["smtp", "mail"], true) ? $m : null;
}

function email_remetente()
{
    $r = trim((string)cfg_php("EMAIL_REMETENTE", ""));
    return $r !== "" ? $r : trim((string)cfg_php("SMTP_USUARIO", ""));
}

// A mensagem MIME (texto, UTF-8, em base64): [cabeçalhos para o mail(), a mensagem inteira]
function email_mensagem($para, $assunto, $texto)
{
    $de = email_remetente();
    $dominio = strpos($de, "@") !== false ? substr($de, strpos($de, "@") + 1) : "relojoeiro";
    $cab = ["From: " . $de, "MIME-Version: 1.0", "Content-Type: text/plain; charset=UTF-8", "Content-Transfer-Encoding: base64",
        "Date: " . date("r"), "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . $dominio . ">"];
    $assunto_c = "=?UTF-8?B?" . base64_encode($assunto) . "?=";
    $corpo = rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $texto))), 76, "\r\n"));
    $inteira = implode("\r\n", array_merge(["To: " . $para, "Subject: " . $assunto_c], $cab)) . "\r\n\r\n" . $corpo . "\r\n";
    return [$cab, $assunto_c, $corpo, $inteira];
}

// Manda um e-mail (para: um ou mais endereços, separados por vírgula): [ok, o erro]
function email_enviar($para, $assunto, $texto)
{
    $metodo = email_metodo();
    if ($metodo === null) {
        return [false, t("o envio de e-mail não está configurado (EMAIL_METODO no config.php)")];
    }
    $lista = array_values(array_filter(array_map("trim", explode(",", (string)$para)), function ($x) { return filter_var($x, FILTER_VALIDATE_EMAIL) !== false; }));
    if (count($lista) === 0 || email_remetente() === "") {
        return [false, count($lista) === 0 ? t("nenhum endereço válido em {1}", [1 => $para]) : t("falta o remetente (EMAIL_REMETENTE)")];
    }
    [$cab, $assunto_c, $corpo, $inteira] = email_mensagem(implode(", ", $lista), $assunto, $texto);
    if ($metodo === "mail") {
        $ok = @mail(implode(", ", $lista), $assunto_c, $corpo, implode("\r\n", $cab));
        $res = [$ok, $ok ? "" : t("o mail() do PHP recusou (confira o sendmail do servidor)")];
    } else {
        $res = smtp_enviar($lista, $inteira);
    }
    if ($res[0] && trim((string)cfg_php("IMAP_HOST", "")) !== "") {
        // a cópia em Enviados não muda o resultado do envio
        imap_guardar($inteira);
    }
    return $res;
}

// O registro da conversa com os servidores de e-mail, só durante o teste (email_testar liga): cada linha que vai ("> ") e
// que volta ("< "), sem a senha nem o usuário em base64 (no lugar deles, a descrição)
function email_registro($linha)
{
    if (is_array($GLOBALS["EMAIL_REGISTRO"] ?? null)) {
        foreach (preg_split("/\r?\n/", rtrim((string)$linha)) as $l) {
            $GLOBALS["EMAIL_REGISTRO"][] = $l;
        }
    }
}

// Uma conversa de linha em linha com um servidor (SMTP, IMAP, POP): abre a conexão (ssl: TLS desde o começo)
function socket_abrir($host, $porta, $seguranca)
{
    $erro = "";
    $n = 0;
    email_registro(t("Conectando em {1}:{2} ({3})...", [1 => $host, 2 => (int)$porta, 3 => $seguranca === "ssl" ? t("SSL desde o começo") : ($seguranca === "tls" ? t("STARTTLS depois de conectar") : t("sem criptografia"))]));
    if (trim((string)$host) === "") {
        throw new RuntimeException(t("falta o endereço do servidor (o *_HOST do config.php)"));
    }
    if ($seguranca !== "" && $seguranca !== "nada" && !extension_loaded("openssl")) {
        throw new RuntimeException(t("o PHP do servidor não tem a extensão openssl, que o SSL e o TLS precisam"));
    }
    $s = @stream_socket_client(($seguranca === "ssl" ? "ssl://" : "tcp://") . $host . ":" . (int)$porta, $n, $erro, 15);
    if (!$s) {
        email_registro(t("Não conectou: {1}", [1 => $erro !== "" ? $erro : t("sem resposta")]));
        throw new RuntimeException(t("não conectou em {1}:{2} ({3})", [1 => $host, 2 => $porta, 3 => $erro]));
    }
    email_registro(t("Conectou."));
    stream_set_timeout($s, 20);
    return $s;
}

// A resposta SMTP (as linhas até a que não tem hífen depois do código): [código, texto]
function smtp_ler($s)
{
    $texto = "";
    while (($l = fgets($s, 2048)) !== false) {
        $texto .= $l;
        if (strlen($l) < 4 || $l[3] !== "-") {
            break;
        }
    }
    return [(int)substr($texto, 0, 3), trim($texto)];
}

// $mostrar: o que vai para o registro no lugar do comando (o usuário e a senha em base64 não aparecem)
function smtp_comando($s, $cmd, $espera, $mostrar = null)
{
    if ($cmd !== null) {
        email_registro("> " . ($mostrar ?? $cmd));
        fwrite($s, $cmd . "\r\n");
    }
    [$c, $t] = smtp_ler($s);
    email_registro($t !== "" ? preg_replace("/^/m", "< ", $t) : "< " . t("(sem resposta)"));
    if (!in_array($c, (array)$espera, true)) {
        throw new RuntimeException(t("o servidor de e-mail recusou ({1})", [1 => $t !== "" ? $t : t("sem resposta")]));
    }
    return $t;
}

// O SMTP: EHLO, STARTTLS (tls), AUTH LOGIN (com usuário), MAIL FROM, RCPT TO, DATA
function smtp_enviar($para, $mensagem)
{
    $seg = strtolower((string)cfg_php("SMTP_SEGURANCA", "tls"));
    $porta = (int)cfg_php("SMTP_PORTA", $seg === "ssl" ? 465 : 587);
    try {
        $s = socket_abrir((string)cfg_php("SMTP_HOST", ""), $porta, $seg);
        smtp_comando($s, null, 220);
        $eu = gethostname() ?: "relojoeiro";
        smtp_comando($s, "EHLO " . $eu, 250);
        if ($seg === "tls") {
            smtp_comando($s, "STARTTLS", 220);
            if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException(t("o TLS do servidor de e-mail falhou"));
            }
            email_registro(t("TLS ligado."));
            smtp_comando($s, "EHLO " . $eu, 250);
        }
        $u = (string)cfg_php("SMTP_USUARIO", "");
        if ($u !== "") {
            smtp_comando($s, "AUTH LOGIN", 334);
            smtp_comando($s, base64_encode($u), 334, t("(o usuário {1}, em base64)", [1 => $u]));
            smtp_comando($s, base64_encode((string)cfg_php("SMTP_SENHA", "")), 235, t("(a senha, em base64)"));
        }
        smtp_comando($s, "MAIL FROM:<" . email_remetente() . ">", 250);
        foreach ($para as $p) {
            smtp_comando($s, "RCPT TO:<" . $p . ">", [250, 251]);
        }
        smtp_comando($s, "DATA", 354);
        // as linhas que começam com ponto ganham outro (o fim do DATA é uma linha só com o ponto)
        email_registro("> " . t("(a mensagem, {1} bytes)", [1 => strlen($mensagem)]));
        fwrite($s, preg_replace("/^\\./m", "..", $mensagem) . "\r\n.\r\n");
        smtp_comando($s, null, 250);
        fwrite($s, "QUIT\r\n");
        fclose($s);
        return [true, ""];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

// O IMAP: guarda uma cópia na pasta Enviados (IMAP_PASTA_ENVIADOS, padrão "Sent"); sem erro para quem chama: [ok, erro]
function imap_guardar($mensagem, $so_testar = false)
{
    $seg = strtolower((string)cfg_php("IMAP_SEGURANCA", "ssl"));
    try {
        $s = socket_abrir((string)cfg_php("IMAP_HOST", ""), (int)cfg_php("IMAP_PORTA", $seg === "ssl" ? 993 : 143), $seg);
        $ler = function ($tag) use ($s) {
            $tudo = "";
            while (($l = fgets($s, 4096)) !== false) {
                $tudo .= $l;
                if ($tag === null || strpos($l, $tag . " ") === 0 || ($tag === "+" && $l[0] === "+")) {
                    break;
                }
            }
            email_registro($tudo !== "" ? preg_replace("/^/m", "< ", rtrim($tudo)) : "< " . t("(sem resposta)"));
            return $tudo;
        };
        $ler(null);
        if ($seg === "tls") {
            email_registro("> a0 STARTTLS");
            fwrite($s, "a0 STARTTLS\r\n");
            $ler("a0");
            if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException(t("o TLS do IMAP falhou"));
            }
            email_registro(t("TLS ligado."));
        }
        $q = function ($x) { return "\"" . addcslashes((string)$x, "\"\\") . "\""; };
        email_registro("> a1 LOGIN " . $q(cfg_php("IMAP_USUARIO", cfg_php("SMTP_USUARIO", ""))) . " " . t("(a senha)"));
        fwrite($s, "a1 LOGIN " . $q(cfg_php("IMAP_USUARIO", cfg_php("SMTP_USUARIO", ""))) . " " . $q(cfg_php("IMAP_SENHA", cfg_php("SMTP_SENHA", ""))) . "\r\n");
        if (strpos($ler("a1"), "a1 OK") === false) {
            throw new RuntimeException(t("o IMAP recusou o login"));
        }
        if ($so_testar) {
            // o teste confere também que a pasta Enviados existe (sem guardar nada)
            email_registro("> a2 STATUS " . $q(cfg_php("IMAP_PASTA_ENVIADOS", "Sent")) . " (MESSAGES)");
            fwrite($s, "a2 STATUS " . $q(cfg_php("IMAP_PASTA_ENVIADOS", "Sent")) . " (MESSAGES)\r\n");
            if (strpos($ler("a2"), "a2 OK") === false) {
                throw new RuntimeException(t("o IMAP não achou a pasta {1} (IMAP_PASTA_ENVIADOS)", [1 => cfg_php("IMAP_PASTA_ENVIADOS", "Sent")]));
            }
        } else {
            email_registro("> a2 APPEND " . $q(cfg_php("IMAP_PASTA_ENVIADOS", "Sent")) . " " . t("(a mensagem, {1} bytes)", [1 => strlen($mensagem)]));
            fwrite($s, "a2 APPEND " . $q(cfg_php("IMAP_PASTA_ENVIADOS", "Sent")) . " (\\Seen) {" . strlen($mensagem) . "}\r\n");
            if (strpos($ler("+"), "+") !== 0) {
                throw new RuntimeException(t("o IMAP não aceitou a cópia em Enviados"));
            }
            fwrite($s, $mensagem . "\r\n");
            if (strpos($ler("a2"), "a2 OK") === false) {
                throw new RuntimeException(t("o IMAP não guardou a cópia em Enviados"));
            }
        }
        email_registro("> a3 LOGOUT");
        fwrite($s, "a3 LOGOUT\r\n");
        fclose($s);
        return [true, ""];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

// O POP3: só o teste da conta (USER e PASS)
function pop_testar()
{
    $seg = strtolower((string)cfg_php("POP_SEGURANCA", "ssl"));
    try {
        $s = socket_abrir((string)cfg_php("POP_HOST", ""), (int)cfg_php("POP_PORTA", $seg === "ssl" ? 995 : 110), $seg);
        $ok = function () use ($s) {
            $l = (string)fgets($s, 2048);
            email_registro("< " . ($l !== "" ? rtrim($l) : t("(sem resposta)")));
            if (strpos($l, "+OK") !== 0) {
                throw new RuntimeException(t("o POP recusou ({1})", [1 => trim($l)]));
            }
        };
        $ok();
        email_registro("> USER " . cfg_php("POP_USUARIO", ""));
        fwrite($s, "USER " . cfg_php("POP_USUARIO", "") . "\r\n");
        $ok();
        email_registro("> PASS " . t("(a senha)"));
        fwrite($s, "PASS " . cfg_php("POP_SENHA", "") . "\r\n");
        $ok();
        email_registro("> QUIT");
        fwrite($s, "QUIT\r\n");
        fclose($s);
        return [true, ""];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

// A configuração do e-mail como o sistema a lê do config.php (o master, em Minha conta › Servidor de e-mail): o método, o
// remetente, o SMTP, o IMAP e o POP (as senhas só como "definida" ou não) e os problemas achados sem conectar em nada
function email_config_resumo()
{
    $seg = function ($c, $padrao) {
        $v = strtolower(trim((string)cfg_php($c, $padrao)));
        return $v === "" ? "nada" : $v;
    };
    $metodo_cfg = trim((string)cfg_php("EMAIL_METODO", ""));
    $smtp_seg = $seg("SMTP_SEGURANCA", "tls");
    $r = ["metodo" => email_metodo(), "email_metodo" => defined("EMAIL_METODO") ? $metodo_cfg : null, "remetente" => email_remetente(),
        "smtp" => ["host" => trim((string)cfg_php("SMTP_HOST", "")), "porta" => (int)cfg_php("SMTP_PORTA", $smtp_seg === "ssl" ? 465 : 587), "seguranca" => $smtp_seg,
            "usuario" => (string)cfg_php("SMTP_USUARIO", ""), "senha" => (string)cfg_php("SMTP_SENHA", "") !== ""],
        "imap" => null, "pop" => null, "openssl" => extension_loaded("openssl"), "mail" => function_exists("mail"), "problemas" => []];
    if (trim((string)cfg_php("IMAP_HOST", "")) !== "") {
        $s = $seg("IMAP_SEGURANCA", "ssl");
        $r["imap"] = ["host" => trim((string)cfg_php("IMAP_HOST", "")), "porta" => (int)cfg_php("IMAP_PORTA", $s === "ssl" ? 993 : 143), "seguranca" => $s,
            "usuario" => (string)cfg_php("IMAP_USUARIO", cfg_php("SMTP_USUARIO", "")), "senha" => (string)cfg_php("IMAP_SENHA", cfg_php("SMTP_SENHA", "")) !== "",
            "pasta" => (string)cfg_php("IMAP_PASTA_ENVIADOS", "Sent")];
    }
    if (trim((string)cfg_php("POP_HOST", "")) !== "") {
        $s = $seg("POP_SEGURANCA", "ssl");
        $r["pop"] = ["host" => trim((string)cfg_php("POP_HOST", "")), "porta" => (int)cfg_php("POP_PORTA", $s === "ssl" ? 995 : 110), "seguranca" => $s,
            "usuario" => (string)cfg_php("POP_USUARIO", ""), "senha" => (string)cfg_php("POP_SENHA", "") !== ""];
    }
    $p = [];
    if ($metodo_cfg !== "" && !in_array(strtolower($metodo_cfg), ["smtp", "mail"], true)) {
        $p[] = t("EMAIL_METODO = \"{1}\" não vale: use \"smtp\" ou \"mail\".", [1 => $metodo_cfg]);
    } elseif ($r["metodo"] === null) {
        $p[] = t("O envio de e-mail está desligado: falta o EMAIL_METODO (\"smtp\" ou \"mail\") no config.php.");
    }
    if ($r["metodo"] !== null) {
        if ($r["remetente"] === "") {
            $p[] = t("Falta o remetente: o EMAIL_REMETENTE (ou o SMTP_USUARIO, se for um e-mail).");
        } elseif (filter_var($r["remetente"], FILTER_VALIDATE_EMAIL) === false) {
            $p[] = t("O remetente \"{1}\" não é um e-mail: ponha um EMAIL_REMETENTE.", [1 => $r["remetente"]]);
        }
    }
    if ($r["metodo"] === "smtp") {
        if ($r["smtp"]["host"] === "") {
            $p[] = t("Falta o SMTP_HOST (o servidor de envio, como smtp.gmail.com).");
        }
        if (!in_array($smtp_seg, ["ssl", "tls", "nada"], true)) {
            $p[] = t("SMTP_SEGURANCA = \"{1}\" não vale: use \"ssl\" (porta 465), \"tls\" (porta 587) ou \"\" (sem criptografia).", [1 => $smtp_seg]);
        } elseif ($smtp_seg === "ssl" && $r["smtp"]["porta"] === 587) {
            $p[] = t("A porta 587 costuma ser a do \"tls\" (STARTTLS); com \"ssl\", a porta costuma ser a 465.");
        } elseif ($smtp_seg === "tls" && $r["smtp"]["porta"] === 465) {
            $p[] = t("A porta 465 costuma ser a do \"ssl\"; com \"tls\" (STARTTLS), a porta costuma ser a 587.");
        }
        if ($r["smtp"]["usuario"] !== "" && !$r["smtp"]["senha"]) {
            $p[] = t("O SMTP_USUARIO está definido, mas a SMTP_SENHA está vazia.");
        }
        if ($smtp_seg !== "nada" && !$r["openssl"]) {
            $p[] = t("O PHP do servidor não tem a extensão openssl, que o SSL e o TLS precisam.");
        }
    }
    if ($r["metodo"] === "mail" && !$r["mail"]) {
        $p[] = t("O mail() do PHP está desligado neste servidor (disable_functions): use o EMAIL_METODO \"smtp\".");
    }
    $r["problemas"] = $p;
    return $r;
}

// O teste da configuração do e-mail (o master, em Minha conta › Servidor de e-mail): conecta e entra no SMTP (sem mandar
// nada), no IMAP (e confere a pasta Enviados) e no POP, e, com $para, manda um e-mail de teste. {metodo, remetente, smtp,
// imap, pop, envio (cada um null ou {ok, erro}), registro (a conversa com os servidores, sem as senhas), config}
function email_testar($para = "")
{
    $GLOBALS["EMAIL_REGISTRO"] = [];
    $res = ["metodo" => email_metodo(), "remetente" => email_remetente(), "smtp" => null, "imap" => null, "pop" => null, "envio" => null];
    if ($res["metodo"] === "smtp") {
        // só a conexão e o login, sem mandar nada
        email_registro("— SMTP —");
        try {
            $seg = strtolower(trim((string)cfg_php("SMTP_SEGURANCA", "tls")));
            $s = socket_abrir((string)cfg_php("SMTP_HOST", ""), (int)cfg_php("SMTP_PORTA", $seg === "ssl" ? 465 : 587), $seg);
            smtp_comando($s, null, 220);
            smtp_comando($s, "EHLO relojoeiro", 250);
            if ($seg === "tls") {
                smtp_comando($s, "STARTTLS", 220);
                if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException(t("o TLS do servidor de e-mail falhou (o certificado do servidor, ou a porta errada)"));
                }
                email_registro(t("TLS ligado."));
                smtp_comando($s, "EHLO relojoeiro", 250);
            }
            if ((string)cfg_php("SMTP_USUARIO", "") !== "") {
                smtp_comando($s, "AUTH LOGIN", 334);
                smtp_comando($s, base64_encode((string)cfg_php("SMTP_USUARIO", "")), 334, t("(o usuário {1}, em base64)", [1 => cfg_php("SMTP_USUARIO", "")]));
                smtp_comando($s, base64_encode((string)cfg_php("SMTP_SENHA", "")), 235, t("(a senha, em base64)"));
            }
            email_registro("> QUIT");
            fwrite($s, "QUIT\r\n");
            fclose($s);
            $res["smtp"] = ["ok" => true, "erro" => null];
        } catch (Throwable $e) {
            $res["smtp"] = ["ok" => false, "erro" => $e->getMessage()];
        }
    }
    if (trim((string)cfg_php("IMAP_HOST", "")) !== "") {
        email_registro("— IMAP —");
        [$ok, $erro] = imap_guardar("", true);
        $res["imap"] = ["ok" => $ok, "erro" => $erro !== "" ? $erro : null];
    }
    if (trim((string)cfg_php("POP_HOST", "")) !== "") {
        email_registro("— POP —");
        [$ok, $erro] = pop_testar();
        $res["pop"] = ["ok" => $ok, "erro" => $erro !== "" ? $erro : null];
    }
    if ($para !== "") {
        email_registro("— " . t("envio para {1}", [1 => $para]) . " —");
        if ($res["metodo"] === "mail") {
            email_registro(t("Pelo mail() do PHP (o sendmail do servidor): o PHP só diz se aceitou a mensagem, não se ela chegou."));
        }
        [$ok, $erro] = email_enviar($para, t("Relojoeiro: teste do e-mail"), t("Este é um e-mail de teste do Relojoeiro, enviado em {1} pelo método {2}, de {3}. Se chegou, o envio está funcionando.",
            [1 => date("d/m/Y H:i:s"), 2 => $res["metodo"], 3 => email_remetente()]));
        $res["envio"] = ["ok" => $ok, "erro" => $erro !== "" ? $erro : null];
        email_registro($ok ? t("O servidor aceitou a mensagem. Confira a caixa de entrada (e a de spam) de {1}.", [1 => $para]) : t("Não foi: {1}", [1 => $erro]));
    }
    $res["registro"] = $GLOBALS["EMAIL_REGISTRO"];
    $GLOBALS["EMAIL_REGISTRO"] = null;
    $res["config"] = email_config_resumo();
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------------
// O Web Push (RFC 8030, 8291 e 8292): as chaves VAPID do config.php (VAPID_PUBLICA e VAPID_PRIVADA, em base64url; o
// recurso=conta, acao=vapid_gerar, gera um par) e VAPID_ASSUNTO (mailto: ou o endereço do sistema). A mensagem vai cifrada
// (aes128gcm) para cada aparelho inscrito do usuário; o aparelho que o serviço disser que não existe mais sai da lista.
// ---------------------------------------------------------------------------------------------------------------------
function b64url($x)
{
    return rtrim(strtr(base64_encode($x), "+/", "-_"), "=");
}

function b64url_ler($x)
{
    return (string)base64_decode(strtr((string)$x, "-_", "+/") . str_repeat("=", (4 - strlen((string)$x) % 4) % 4));
}

function vapid_configurado()
{
    return strlen(b64url_ler(cfg_php("VAPID_PUBLICA", ""))) === 65 && strlen(b64url_ler(cfg_php("VAPID_PRIVADA", ""))) === 32;
}

// Um par novo de chaves VAPID (P-256): {publica, privada}, em base64url
function vapid_gerar()
{
    $k = openssl_pkey_new(["curve_name" => "prime256v1", "private_key_type" => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($k)["ec"];
    return ["publica" => b64url("\x04" . str_pad($d["x"], 32, "\0", STR_PAD_LEFT) . str_pad($d["y"], 32, "\0", STR_PAD_LEFT)),
        "privada" => b64url(str_pad($d["d"], 32, "\0", STR_PAD_LEFT))];
}

// A chave privada P-256 (d e o ponto público) em PEM, para o openssl
function ec_pem_privada($d, $publica)
{
    $der = "\x30\x77\x02\x01\x01\x04\x20" . $d . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00" . $publica;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

// A chave pública P-256 (o ponto, 65 bytes) em PEM
function ec_pem_publica($publica)
{
    $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $publica;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// A assinatura ES256 (o JWT do VAPID): a do openssl vem em DER; o JWT quer r||s, 32 bytes cada
function es256_assinar($texto)
{
    $chave = openssl_pkey_get_private(ec_pem_privada(b64url_ler(VAPID_PRIVADA), b64url_ler(VAPID_PUBLICA)));
    $der = "";
    openssl_sign($texto, $der, $chave, OPENSSL_ALGO_SHA256);
    $p = 3 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);
    $len_r = ord($der[$p]);
    $r = substr($der, $p + 1, $len_r);
    $p += 1 + $len_r + 1;
    $s = substr($der, $p + 1, ord($der[$p]));
    $pad = function ($x) { return str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT); };
    return $pad($r) . $pad($s);
}

// Cifra a mensagem para um aparelho (aes128gcm, RFC 8291): o corpo do pedido
function webpush_cifrar($texto, $p256dh, $auth)
{
    $ua = b64url_ler($p256dh);
    $segredo = b64url_ler($auth);
    $k = openssl_pkey_new(["curve_name" => "prime256v1", "private_key_type" => OPENSSL_KEYTYPE_EC]);
    $det = openssl_pkey_get_details($k)["ec"];
    $as = "\x04" . str_pad($det["x"], 32, "\0", STR_PAD_LEFT) . str_pad($det["y"], 32, "\0", STR_PAD_LEFT);
    $compartilhado = openssl_pkey_derive(openssl_pkey_get_public(ec_pem_publica($ua)), $k, 32);
    $ikm = hash_hkdf("sha256", (string)$compartilhado, 32, "WebPush: info\0" . $ua . $as, $segredo);
    $sal = random_bytes(16);
    $cek = hash_hkdf("sha256", $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = hash_hkdf("sha256", $ikm, 12, "Content-Encoding: nonce\0", $sal);
    $tag = "";
    $cifrado = openssl_encrypt($texto . "\x02", "aes-128-gcm", $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $sal . pack("N", 4096) . chr(65) . $as . $cifrado . $tag;
}

// Manda uma notificação para um aparelho: o código da resposta do serviço de push
function webpush_mandar($inscricao, $titulo, $texto)
{
    $partes = parse_url($inscricao["endpoint"]);
    $aud = ($partes["scheme"] ?? "https") . "://" . ($partes["host"] ?? "") . (isset($partes["port"]) ? ":" . $partes["port"] : "");
    $assunto = trim((string)cfg_php("VAPID_ASSUNTO", ""));
    $jwt_cab = b64url(json_encode(["typ" => "JWT", "alg" => "ES256"]));
    $jwt_corpo = b64url(json_encode(["aud" => $aud, "exp" => time() + 12 * 3600, "sub" => $assunto !== "" ? $assunto : "mailto:relojoeiro@localhost"]));
    $jwt = $jwt_cab . "." . $jwt_corpo . "." . b64url(es256_assinar($jwt_cab . "." . $jwt_corpo));
    $corpo = webpush_cifrar(json_encode(["titulo" => $titulo, "texto" => $texto, "link" => url_do_sistema() !== "" ? url_do_sistema() . "/index.php" : ""], JSON_UNESCAPED_UNICODE),
        $inscricao["p256dh"], $inscricao["auth"]);
    [$codigo] = http_pedido("POST", $inscricao["endpoint"], ["TTL: 86400", "Urgency: normal", "Content-Encoding: aes128gcm", "Content-Type: application/octet-stream",
        "Authorization: vapid t=" . $jwt . ", k=" . VAPID_PUBLICA], $corpo, null, null, true);
    return $codigo;
}

// Manda para todos os aparelhos de um usuário: [ok (algum chegou), o erro]
function webpush_enviar_usuario($usuario_id, $titulo, $texto)
{
    if (!vapid_configurado()) {
        return [false, t("o Web Push não está configurado (VAPID_PUBLICA e VAPID_PRIVADA no config.php)")];
    }
    $aparelhos = sem_escopo(function () use ($usuario_id) { return linhas("SELECT * FROM push_inscricao WHERE usuario_id = ?", [(int)$usuario_id]); });
    if (count($aparelhos) === 0) {
        return [false, t("nenhum aparelho inscrito (ligue as notificações em Minha conta)")];
    }
    $chegou = 0;
    $erro = "";
    foreach ($aparelhos as $a) {
        $codigo = webpush_mandar($a, $titulo, mb_substr($texto, 0, 3000));
        if ($codigo >= 200 && $codigo < 300) {
            $chegou++;
        } elseif ($codigo === 404 || $codigo === 410) {
            // o aparelho saiu (desinstalou, cancelou): sai da lista
            sem_escopo(function () use ($a) { sql("DELETE FROM push_inscricao WHERE id = ?", [(int)$a["id"]]); });
        } else {
            $erro = t("o serviço de push respondeu {1}", [1 => $codigo]);
        }
    }
    return [$chegou > 0, $chegou > 0 ? "" : ($erro !== "" ? $erro : t("nenhum aparelho recebeu"))];
}

// ---------------------------------------------------------------------------------------------------------------------
// As agendas da coleção
// ---------------------------------------------------------------------------------------------------------------------
function agendas($recarregar = false)
{
    static $cache = [];
    $c = (int)colecao_atual();
    if ($recarregar || !isset($cache[$c])) {
        $res = [];
        foreach (linhas("SELECT * FROM agenda ORDER BY nome, id") as $a) {
            $id = (int)$a["id"];
            $x = ["id" => $id, "nome" => $a["nome"], "tipo" => $a["tipo"], "ativa" => (int)$a["ativa"] === 1, "janela_dias" => (int)$a["janela_dias"],
                "modelo" => $a["modelo"], "idioma" => $a["idioma"], "teste_id" => $a["teste_id"], "avisos" => []];
            foreach (linhas("SELECT tipo_aviso, envia, modelo_proprio FROM agenda_aviso WHERE agenda_id = ?", [$id]) as $t) {
                $x["avisos"][$t["tipo_aviso"]] = ["envia" => (int)$t["envia"] === 1, "modelo" => $t["modelo_proprio"]];
            }
            foreach (linhas("SELECT evento_id, envia FROM agenda_evento WHERE agenda_id = ?", [$id]) as $t) {
                $x["avisos"]["ev" . (int)$t["evento_id"]] = ["envia" => (int)$t["envia"] === 1, "modelo" => null];
            }
            $sub = ["google" => "agenda_google", "caldav" => "agenda_caldav", "outlook" => "agenda_outlook", "ics" => "agenda_ics"][$a["tipo"]];
            $x[$a["tipo"]] = linha("SELECT * FROM " . $sub . " WHERE agenda_id = ?", [$id]) ?? [];
            $x["sincronizados"] = (int)valor("SELECT COUNT(*) FROM agenda_sincronizado WHERE agenda_id = ?", [$id]);
            $res[$id] = $x;
        }
        $cache[$c] = $res;
    }
    return $cache[$c];
}

// O que tem de estar numa agenda, de hoje até o fim da janela dela (janela_dias), só os tipos que ela recebe: os avisos
// dos relógios disponíveis que não estão com uma sessão aberta à mão (carregando: a data andaria a cada conta; volta quando
// a sessão fechar), no horário da manhã do dia previsto (vencido: hoje) — a rotina (agenda "janela" no aviso) só dentro
// da janela, a manutenção ("sempre") em qualquer data; o relógio do dia e a véspera, a partir do plano; e as ocorrências
// dos eventos personalizados. Cada item: chave (a mesma enquanto nada mudar), data, hora, momento (o instante previsto),
// tipo, relogio_id, relogio, fazer e motivo.
function agenda_desejada($hoje, $ag)
{
    $lista = [];
    $quer = function ($tipo) use ($ag) { return alvo_envia($ag, $tipo); };
    $janela = max(0, (int)$ag["janela_dias"]);
    $limite = $hoje->modify("+" . $janela . " days")->format("Y-m-d");
    $manha = cfg("horario_manha") !== "" ? cfg("horario_manha") : "06:30";
    foreach (linhas("SELECT * FROM relogio WHERE disponivel = 1 ORDER BY nome") as $r) {
        if (valor("SELECT l.id FROM lancamento l JOIN lancamento_tipo t ON t.id = l.tipo_id WHERE l.relogio_id = ? AND t.formato = 'sessao' AND l.fim IS NULL LIMIT 1", [(int)$r["id"]]) === null) {
            foreach (avisos_do_relogio($r, time()) as $a) {
                $data = max($hoje->format("Y-m-d"), substr($a["data"], 0, 10));
                if ($quer($a["identificador"]) && ($a["agenda"] === "sempre" || $data <= $limite)) {
                    // o motivo: o texto do aviso, com o "quando" contado do começo do evento (não muda a cada sincronização)
                    $falta = (strtotime($a["data"]) - strtotime($data . " " . $manha)) / 86400;
                    $quando = duracao_texto($falta) === "" ? t("agora") : ($falta >= 0 ? t("em {1}", [1 => duracao_texto($falta)]) : t("há {1}", [1 => duracao_texto($falta)]));
                    $lista[] = ["chave" => $a["identificador"] . ":" . $r["id"] . ":" . $data, "data" => $data, "hora" => $manha, "momento" => $a["data"],
                        "tipo" => $a["identificador"], "relogio_id" => (int)$r["id"], "relogio" => $r["nome"], "fazer" => $a["nome"],
                        "motivo" => str_replace(["{relogio}", "{data}", "{quando}"], [$r["nome"], data_txt(strtotime($a["data"]), true), $quando], $a["modelo"])];
                }
            }
        }
    }
    // o relógio do dia (o primeiro dia de cada vez que ele entra) e a véspera, a partir do plano
    $anterior = 0;
    foreach (linhas("SELECT p.data, p.relogio_id, r.* FROM plano p JOIN relogio r ON r.id = p.relogio_id WHERE p.data BETWEEN ? AND ? ORDER BY p.data",
        [$hoje->modify("-1 day")->format("Y-m-d"), $limite]) as $p) {
        if ($anterior !== (int)$p["relogio_id"] && $p["data"] >= $hoje->format("Y-m-d")) {
            if ($quer("dia")) {
                $lista[] = ["chave" => "dia:" . $p["relogio_id"] . ":" . $p["data"], "data" => $p["data"], "hora" => $manha, "momento" => $p["data"] . " " . $manha . ":00",
                    "tipo" => "dia", "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["nome"], "fazer" => t("Usar hoje"),
                    "motivo" => t("Usar {1}.", [1 => texto_ate($p["data"], t("neste dia"))])];
            }
            $vespera = date("Y-m-d", strtotime($p["data"] . " -1 day"));
            if ($quer("vespera") && $vespera >= $hoje->format("Y-m-d")) {
                $r_v = $p;
                $r_v["id"] = $p["relogio_id"];
                $preparo = preparo_do_dia($r_v, $p["data"]);
                $lista[] = ["chave" => "vespera:" . $p["relogio_id"] . ":" . $vespera, "data" => $vespera, "hora" => cfg("horario_noite"),
                    "momento" => $vespera . " " . cfg("horario_noite") . ":00", "tipo" => "vespera", "relogio_id" => (int)$p["relogio_id"], "relogio" => $p["nome"],
                    "fazer" => t("Preparar para amanhã"), "motivo" => count($preparo) > 0 ? implode("\n", $preparo) : t("É só usar.")];
            }
        }
        $anterior = (int)$p["relogio_id"];
    }
    $fim = strtotime($limite . " 23:59:59");
    foreach (eventos_com_dias(linhas("SELECT * FROM evento_personalizado WHERE ativo = 1")) as $ev) {
        if ($quer("ev" . $ev["id"])) {
            foreach (ocorrencias($ev, max(time(), strtotime($ev["criado"])), $fim) as $ts) {
                $lista[] = ["chave" => "ev" . $ev["id"] . ":" . (int)$ev["relogio_id"] . ":" . date("Y-m-d-H-i", $ts), "data" => date("Y-m-d", $ts), "hora" => date("H:i", $ts),
                    "momento" => date("Y-m-d H:i:s", $ts), "tipo" => "ev" . $ev["id"], "relogio_id" => (int)$ev["relogio_id"],
                    "relogio" => $ev["relogio_id"] !== null ? (string)valor("SELECT nome FROM relogio WHERE id = ?", [(int)$ev["relogio_id"]]) : "",
                    "fazer" => $ev["nome"], "motivo" => ""];
            }
        }
    }
    usort($lista, function ($a, $b) { return strcmp($a["data"] . $a["hora"], $b["data"] . $b["hora"]); });
    return $lista;
}

// Um evento da agenda pelo modelo dela (o próprio do tipo, ou a mensagem padrão): a primeira linha é o título, o resto a
// descrição
function evento_formatado($m, $ag)
{
    $dias = ["", t("segunda"), t("terça"), t("quarta"), t("quinta"), t("sexta"), t("sábado"), t("domingo")];
    $ctx = contexto_mensagem((int)$m["relogio_id"], ["acao" => $m["fazer"], "motivo" => $m["motivo"], "ate" => $m["tipo"] === "dia" ? $m["motivo"] : "",
        "tipo_aviso" => $m["tipo"], "data" => data_txt(strtotime($m["data"])), "hora" => $m["hora"], "dia_semana" => $dias[(int)date("N", strtotime($m["data"]))]],
        new DateTimeImmutable("today"));
    $partes = explode("\n", aplicar_modelo(alvo_modelo($ag, $m["tipo"]), $ctx), 2);
    return ["titulo" => trim($partes[0]) !== "" ? trim($partes[0]) : $m["fazer"], "descricao" => isset($partes[1]) ? trim($partes[1]) : ""];
}

// O jeito de falar com cada tipo de agenda: [erro (null: pronto), criar(item, formatado) => [ok, id de fora], atualizar(id de
// fora, item, formatado) => ok, apagar(id de fora) => ok]
function agenda_provedor($ag)
{
    $evento_de = function ($m) {
        $ini = new DateTimeImmutable($m["data"] . " " . $m["hora"]);
        return [$ini, $ini->modify("+15 minutes")];
    };
    if ($ag["tipo"] === "google") {
        $g = $ag["google"];
        $token = google_token($ag);
        if (trim((string)($g["calendario_id"] ?? "")) === "") {
            return [t("Informe o ID da agenda do Google.")];
        }
        if (!$token) {
            return [t("Não autenticou no Google: confira a chave JSON da conta de serviço e se a Calendar API está ativa no projeto.")];
        }
        $cal = rawurlencode($g["calendario_id"]);
        $fuso = date_default_timezone_get();
        return [null,
            function ($m, $f) use ($cal, $token, $evento_de, $fuso) {
                [$ini, $fim] = $evento_de($m);
                $r = google_api($token, "POST", "/calendars/" . $cal . "/events", ["summary" => $f["titulo"], "description" => $f["descricao"],
                    "start" => ["dateTime" => $ini->format("Y-m-d\\TH:i:s"), "timeZone" => $fuso], "end" => ["dateTime" => $fim->format("Y-m-d\\TH:i:s"), "timeZone" => $fuso],
                    "reminders" => ["useDefault" => false, "overrides" => [["method" => "popup", "minutes" => 0]]],
                    "extendedProperties" => ["private" => ["relogios_chave" => $m["chave"]]]]);
                return [$r[0] >= 200 && $r[0] < 300 && !empty($r[1]["id"]), $r[1]["id"] ?? ""];
            },
            function ($ext, $m, $f) use ($cal, $token) {
                $r = google_api($token, "PATCH", "/calendars/" . $cal . "/events/" . rawurlencode($ext), ["summary" => $f["titulo"], "description" => $f["descricao"]]);
                return $r[0] >= 200 && $r[0] < 300;
            },
            function ($ext) use ($cal, $token) {
                $r = google_api($token, "DELETE", "/calendars/" . $cal . "/events/" . rawurlencode($ext), null);
                return in_array($r[0], [200, 204, 404, 410], true);
            }];
    }
    if ($ag["tipo"] === "caldav") {
        $c = $ag["caldav"];
        $url = rtrim((string)($c["url"] ?? ""), "/") . "/";
        $senha = segredo_abrir($c["senha_cifrada"] ?? "");
        if (trim((string)($c["url"] ?? "")) === "" || $senha === null) {
            return [t("Informe o endereço do calendário CalDAV, o usuário e a senha (no iCloud, uma senha de app).")];
        }
        $put = function ($uid, $m, $f) use ($url, $c, $senha, $evento_de) {
            [$ini, $fim] = $evento_de($m);
            $r = http_pedido("PUT", $url . rawurlencode($uid) . ".ics", ["Content-Type: text/calendar; charset=utf-8"],
                ics_calendario([ics_evento($uid, $ini, $fim, $f["titulo"], $f["descricao"])]), $c["usuario"], $senha, !colecao_do_master());
            return $r[0] >= 200 && $r[0] < 300;
        };
        return [null,
            function ($m, $f) use ($put) {
                $uid = "relojoeiro-" . md5($m["chave"] . "|" . colecao_atual()) . "@relojoeiro";
                return [$put($uid, $m, $f), $uid];
            },
            function ($ext, $m, $f) use ($put) {
                return $put($ext, $m, $f);
            },
            function ($ext) use ($url, $c, $senha) {
                $r = http_pedido("DELETE", $url . rawurlencode($ext) . ".ics", [], null, $c["usuario"], $senha, !colecao_do_master());
                return in_array($r[0], [200, 204, 404, 410], true);
            }];
    }
    if ($ag["tipo"] === "outlook") {
        $token = outlook_token($ag);
        if ($token === null) {
            return [trim((string)cfg_php("OUTLOOK_CLIENTE_ID", "")) === "" ? t("O Outlook não está configurado no servidor (OUTLOOK_CLIENTE_ID e OUTLOOK_CLIENTE_SEGREDO no config.php): use o link ICS.")
                : t("Conecte a conta Microsoft (botão Conectar ao Outlook).")];
        }
        $cal = trim((string)($ag["outlook"]["calendario_id"] ?? ""));
        $base = OUTLOOK_GRAPH_URL . ($cal !== "" ? "/me/calendars/" . rawurlencode($cal) : "/me");
        $fuso = date_default_timezone_get();
        $corpo = function ($m, $f) use ($evento_de, $fuso) {
            [$ini, $fim] = $evento_de($m);
            return json_encode(["subject" => $f["titulo"], "body" => ["contentType" => "text", "content" => $f["descricao"]],
                "start" => ["dateTime" => $ini->format("Y-m-d\\TH:i:s"), "timeZone" => $fuso], "end" => ["dateTime" => $fim->format("Y-m-d\\TH:i:s"), "timeZone" => $fuso],
                "isReminderOn" => true, "reminderMinutesBeforeStart" => 0], JSON_UNESCAPED_UNICODE);
        };
        $cab = ["Authorization: Bearer " . $token, "Content-Type: application/json"];
        return [null,
            function ($m, $f) use ($base, $cab, $corpo) {
                $r = http_pedido("POST", $base . "/events", $cab, $corpo($m, $f));
                $j = json_decode($r[1], true);
                return [$r[0] >= 200 && $r[0] < 300 && !empty($j["id"]), $j["id"] ?? ""];
            },
            function ($ext, $m, $f) use ($cab, $corpo) {
                $r = http_pedido("PATCH", OUTLOOK_GRAPH_URL . "/me/events/" . rawurlencode($ext), $cab, $corpo($m, $f));
                return $r[0] >= 200 && $r[0] < 300;
            },
            function ($ext) use ($cab) {
                $r = http_pedido("DELETE", OUTLOOK_GRAPH_URL . "/me/events/" . rawurlencode($ext), $cab);
                return in_array($r[0], [200, 204, 404, 410], true);
            }];
    }
    return [t("O link ICS não é sincronizado: o app de agenda lê o link quando quiser.")];
}

// Deixa uma agenda igual ao que tem de estar nela (agenda_desejada): cria o que falta, atualiza o que mudou de título ou de
// descrição, e apaga o que deixou de valer. Os eventos que já passaram ficam na agenda, como histórico.
// Devolve {criados, atualizados, removidos, erros, mensagem}
function agenda_sincronizar($ag, $hoje)
{
    $res = ["criados" => 0, "atualizados" => 0, "removidos" => 0, "erros" => 0, "mensagem" => ""];
    $antes = lingua_atual();
    lingua_definir(alvo_idioma($ag));
    try {
        $p = agenda_provedor($ag);
        if ($p[0] !== null) {
            $res["mensagem"] = $p[0];
            return $res;
        }
        [, $criar, $atualizar, $apagar] = $p;
        $desejados = [];
        foreach (agenda_desejada($hoje, $ag) as $m) {
            $desejados[$m["chave"]] = $m;
        }
        $existentes = [];
        foreach (linhas("SELECT * FROM agenda_sincronizado WHERE agenda_id = ?", [$ag["id"]]) as $ev) {
            $existentes[$ev["chave"]] = $ev;
            if (!isset($desejados[$ev["chave"]])) {
                if ($ev["data"] < $hoje->format("Y-m-d") || $apagar($ev["externo_id"])) {
                    sql("DELETE FROM agenda_sincronizado WHERE agenda_id = ? AND chave = ?", [$ag["id"], $ev["chave"]]);
                    $res["removidos"] += $ev["data"] < $hoje->format("Y-m-d") ? 0 : 1;
                } else {
                    $res["erros"]++;
                }
            }
        }
        foreach ($desejados as $chave => $m) {
            $f = evento_formatado($m, $ag);
            $assinatura = md5($f["titulo"] . "\n" . $f["descricao"]);
            if (isset($existentes[$chave])) {
                if ($existentes[$chave]["assinatura"] !== $assinatura) {
                    if ($atualizar($existentes[$chave]["externo_id"], $m, $f)) {
                        sql("UPDATE agenda_sincronizado SET titulo = ?, assinatura = ? WHERE agenda_id = ? AND chave = ?", [corta_texto($f["titulo"], 255), $assinatura, $ag["id"], $chave]);
                        $res["atualizados"]++;
                    } else {
                        $res["erros"]++;
                    }
                }
            } else {
                [$ok, $ext] = $criar($m, $f);
                if ($ok) {
                    sql("INSERT INTO agenda_sincronizado (agenda_id, chave, externo_id, data, titulo, assinatura, criado) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                        [$ag["id"], $chave, $ext, $m["data"], corta_texto($f["titulo"], 255), $assinatura]);
                    $res["criados"]++;
                } else {
                    $res["erros"]++;
                }
            }
        }
        return $res;
    } finally {
        lingua_definir($antes);
    }
}

// Todas as agendas ativas da coleção que se sincronizam (o link ICS não): as linhas do registro do cron
function agendas_sincronizar($hoje)
{
    $linhas = [];
    foreach (agendas() as $ag) {
        if (!$ag["ativa"] || $ag["tipo"] === "ics") {
            continue;
        }
        $r = agenda_sincronizar($ag, $hoje);
        $p = [1 => $ag["nome"], 2 => $r["criados"], 3 => $r["atualizados"], 4 => $r["removidos"], 5 => $r["erros"], 6 => $r["mensagem"]];
        $linhas[] = $r["mensagem"] !== "" || $r["erros"] > 0
            ? registro_erro(t("erro na agenda {1}: {2} criados, {3} atualizados, {4} removidos, {5} recusados {6}", $p))
            : t("agenda {1}: {2} criados, {3} atualizados, {4} removidos, {5} recusados {6}", $p);
    }
    return $linhas;
}

// O Google: o token de acesso pela conta de serviço (a chave JSON guardada na agenda: o conteúdo do arquivo, ou o caminho
// dele no servidor; vazio: google-conta-servico.json na pasta do sistema), um por agenda a cada pedido; false se não houver
// chave ou o Google recusar
function google_token($ag)
{
    static $tokens = [];
    $id = (int)$ag["id"];
    if (!isset($tokens[$id])) {
        $tokens[$id] = false;
        $chave_txt = (string)segredo_abrir($ag["google"]["chave_json_cifrada"] ?? "");
        // sem o JSON: o caminho gravado (só o master grava um caminho) ou, sem nada, o google-conta-servico.json da pasta do
        // sistema, só nas coleções do master (a chave do servidor não serve às coleções dos outros)
        if (ltrim($chave_txt) === "" || ltrim($chave_txt)[0] !== "{") {
            $dono_master = sem_escopo(function () { return valor("SELECT u.papel FROM colecao c JOIN usuario u ON u.id = c.dono_id WHERE c.id = ?", [(int)colecao_atual()]); }) === "master";
            $caminho = trim($chave_txt) !== "" ? trim($chave_txt) : ($dono_master ? __DIR__ . "/google-conta-servico.json" : "");
            $chave_txt = $caminho !== "" && is_file($caminho) && is_readable($caminho) ? (string)file_get_contents($caminho) : "";
        }
        $chave = json_decode($chave_txt, true);
        if (is_array($chave) && !empty($chave["private_key"]) && !empty($chave["client_email"])) {
            $agora = time();
            $cabeca = b64url(json_encode(["alg" => "RS256", "typ" => "JWT"]));
            $corpo = b64url(json_encode(["iss" => $chave["client_email"], "scope" => "https://www.googleapis.com/auth/calendar", "aud" => GOOGLE_TOKEN_URL,
                "iat" => $agora, "exp" => $agora + 3600]));
            $assinatura = "";
            if (@openssl_sign($cabeca . "." . $corpo, $assinatura, $chave["private_key"], "sha256WithRSAEncryption")) {
                $r = http_pedido("POST", GOOGLE_TOKEN_URL, ["Content-Type: application/x-www-form-urlencoded"],
                    http_build_query(["grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer", "assertion" => $cabeca . "." . $corpo . "." . b64url($assinatura)]));
                $resp = json_decode($r[1], true);
                if (!empty($resp["access_token"])) {
                    $tokens[$id] = $resp["access_token"];
                }
            }
        }
    }
    return $tokens[$id];
}

// Um pedido à API do Google Agenda: [código HTTP, resposta decodificada]
function google_api($token, $metodo, $caminho, $corpo)
{
    $cab = ["Authorization: Bearer " . $token];
    if ($corpo !== null) {
        $cab[] = "Content-Type: application/json";
    }
    $r = http_pedido($metodo, GOOGLE_API_URL . $caminho, $cab, $corpo !== null ? json_encode($corpo) : null);
    return [$r[0], json_decode($r[1], true)];
}

// O Outlook (Microsoft Graph): a conexão é pelo OAuth (o aplicativo registrado na Microsoft: OUTLOOK_CLIENTE_ID e
// OUTLOOK_CLIENTE_SEGREDO no config.php). Conectar leva a pessoa à página da Microsoft; ela volta para
// api.php?recurso=outlook_retorno com o código, que vira o token de renovação (guardado cifrado na agenda)
function outlook_retorno_url()
{
    return url_do_sistema() . "/api.php?recurso=outlook_retorno";
}

function outlook_estado($agenda_id)
{
    return (int)$agenda_id . "." . substr(hash_hmac("sha256", "outlook|" . (int)$agenda_id, segredo_chave()), 0, 32);
}

function outlook_autorizar_url($agenda_id)
{
    return OUTLOOK_LOGIN_URL . "/authorize?" . http_build_query(["client_id" => (string)cfg_php("OUTLOOK_CLIENTE_ID", ""), "response_type" => "code",
        "redirect_uri" => outlook_retorno_url(), "response_mode" => "query", "scope" => "offline_access Calendars.ReadWrite User.Read", "state" => outlook_estado($agenda_id)]);
}

// O token de acesso de uma agenda do Outlook (renova pelo token de renovação, e guarda o novo), ou null
function outlook_token($ag)
{
    static $tokens = [];
    $id = (int)$ag["id"];
    if (array_key_exists($id, $tokens)) {
        return $tokens[$id];
    }
    $tokens[$id] = null;
    $renova = segredo_abrir($ag["outlook"]["refresh_token_cifrado"] ?? "");
    if ($renova === null || trim((string)cfg_php("OUTLOOK_CLIENTE_ID", "")) === "") {
        return null;
    }
    $r = outlook_pedir_token(["grant_type" => "refresh_token", "refresh_token" => $renova]);
    if ($r !== null) {
        $tokens[$id] = $r["access_token"];
        if (!empty($r["refresh_token"]) && $r["refresh_token"] !== $renova) {
            sql("UPDATE agenda_outlook SET refresh_token_cifrado = ? WHERE agenda_id = ?", [segredo_cifrar($r["refresh_token"]), $id]);
        }
    }
    return $tokens[$id];
}

function outlook_pedir_token($dados)
{
    $r = http_pedido("POST", OUTLOOK_LOGIN_URL . "/token", ["Content-Type: application/x-www-form-urlencoded"], http_build_query(array_merge([
        "client_id" => (string)cfg_php("OUTLOOK_CLIENTE_ID", ""), "client_secret" => (string)cfg_php("OUTLOOK_CLIENTE_SEGREDO", ""),
        "redirect_uri" => outlook_retorno_url(), "scope" => "offline_access Calendars.ReadWrite User.Read"], $dados)));
    $j = json_decode($r[1], true);
    return is_array($j) && !empty($j["access_token"]) ? $j : null;
}

// O retorno da Microsoft (recurso=outlook_retorno, code e state): guarda a conexão na agenda. Devolve [ok, mensagem]
function outlook_conectar_retorno($codigo, $estado)
{
    $id = (int)explode(".", (string)$estado)[0];
    if (!hash_equals(outlook_estado($id), (string)$estado)) {
        return [false, t("O retorno da Microsoft não confere (tente conectar de novo).")];
    }
    $ag = agendas(true)[$id] ?? null;
    if ($ag === null || $ag["tipo"] !== "outlook") {
        return [false, t("Agenda não encontrada.")];
    }
    $r = outlook_pedir_token(["grant_type" => "authorization_code", "code" => (string)$codigo]);
    if ($r === null || empty($r["refresh_token"])) {
        return [false, t("A Microsoft não deu o acesso (confira o aplicativo registrado e o endereço de retorno: {1}).", [1 => outlook_retorno_url()])];
    }
    $eu = json_decode(http_pedido("GET", OUTLOOK_GRAPH_URL . "/me", ["Authorization: Bearer " . $r["access_token"]])[1], true);
    $conta = is_array($eu) ? (string)($eu["mail"] ?? ($eu["userPrincipalName"] ?? "")) : "";
    sql("UPDATE agenda_outlook SET conta = ?, refresh_token_cifrado = ? WHERE agenda_id = ?", [$conta !== "" ? corta_texto($conta, 200) : null, segredo_cifrar($r["refresh_token"]), $id]);
    agendas(true);
    return [true, t("Outlook conectado{1}.", [1 => $conta !== "" ? ": " . $conta : ""])];
}

// ---------------------------------------------------------------------------------------------------------------------
// O iCalendar (o link ICS e os eventos do CalDAV)
// ---------------------------------------------------------------------------------------------------------------------
function ics_texto($s)
{
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], (string)$s);
}

// Quebra as linhas longas do iCalendar (75 bytes, a continuação começa com espaço), sem cortar um caractere ao meio
function ics_dobrar($linha)
{
    $saida = "";
    while (strlen($linha) > 75) {
        $corte = 75;
        while ($corte > 0 && (ord($linha[$corte]) & 0xC0) === 0x80) {
            $corte--;
        }
        $saida .= substr($linha, 0, $corte) . "\r\n ";
        $linha = substr($linha, $corte);
    }
    return $saida . $linha;
}

function ics_evento($uid, $ini, $fim, $titulo, $descricao)
{
    $utc = function ($d) { return $d->setTimezone(new DateTimeZone("UTC"))->format("Ymd\\THis\\Z"); };
    return ["BEGIN:VEVENT", "UID:" . $uid, "DTSTAMP:" . gmdate("Ymd\\THis\\Z"), "DTSTART:" . $utc($ini), "DTEND:" . $utc($fim),
        "SUMMARY:" . ics_texto($titulo), "DESCRIPTION:" . ics_texto($descricao),
        "BEGIN:VALARM", "ACTION:DISPLAY", "DESCRIPTION:" . ics_texto($titulo), "TRIGGER:PT0M", "END:VALARM", "END:VEVENT"];
}

function ics_calendario($eventos, $nome = null)
{
    $l = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Relojoeiro//PT", "CALSCALE:GREGORIAN"];
    if ($nome !== null) {
        $l[] = "X-WR-CALNAME:" . ics_texto($nome);
    }
    foreach ($eventos as $e) {
        $l = array_merge($l, $e);
    }
    $l[] = "END:VCALENDAR";
    return implode("\r\n", array_map("ics_dobrar", $l)) . "\r\n";
}

// O link ICS de uma agenda (quem tem o link vê os eventos)
function ics_link($ag)
{
    $t = segredo_abrir($ag["ics"]["token_cifrado"] ?? "");
    return $t === null ? null : url_do_sistema() . "/api.php?recurso=ics&chave=" . rawurlencode($t);
}

// O conteúdo do link ICS (recurso=ics, sem login, pelo token): os eventos da agenda daqui até o fim da janela. A coleção
// vem da própria agenda
function ics_feed($token)
{
    $a = sem_escopo(function () use ($token) {
        return linha("SELECT a.id, a.colecao_id FROM agenda_ics i JOIN agenda a ON a.id = i.agenda_id WHERE i.token_hash = ?", [hash("sha256", (string)$token)]);
    });
    if (!$a) {
        return null;
    }
    banco_escopo((int)$a["colecao_id"]);
    $ag = agendas(true)[(int)$a["id"]] ?? null;
    if ($ag === null || !$ag["ativa"]) {
        return ics_calendario([], $ag["nome"] ?? null);
    }
    $antes = lingua_atual();
    lingua_definir(alvo_idioma($ag));
    $evs = [];
    foreach (agenda_desejada(new DateTimeImmutable("today"), $ag) as $m) {
        $f = evento_formatado($m, $ag);
        $ini = new DateTimeImmutable($m["data"] . " " . $m["hora"]);
        $evs[] = ics_evento("relojoeiro-" . md5($m["chave"] . "|" . $a["colecao_id"]) . "@relojoeiro", $ini, $ini->modify("+15 minutes"), $f["titulo"], $f["descricao"]);
    }
    $txt = ics_calendario($evs, $ag["nome"]);
    lingua_definir($antes);
    return $txt;
}
