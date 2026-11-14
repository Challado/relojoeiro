<?php
// As opções em três níveis: sistema → usuário → coleção. O mais específico prevalece; o geral é só um padrão, nunca um
// bloqueio. A coleção guarda a escolha dela no config (vazio herda); o dono guarda a dele em usuario_opcao (o padrão das
// coleções de que é dono; vazio herda); o master guarda a do sistema na tabela sistema (padrao_<chave>; vazio, vale o
// padrão de fábrica da tabela OPCOES). O cfg() já devolve o valor que vale (a escolha da coleção, senão a do dono, senão a
// do sistema, senão o padrão), então todo o código que lê uma opção obedece à hierarquia sem saber dela.
//
// Fora daqui, de propósito: o que é do sistema inteiro por natureza (a força da senha, o endereço do sistema, as rodadas
// do cron e os parâmetros de operação do sistema: cadastro aberto, cópia de segurança, limites da API, o SQL), que só o
// master muda; o idioma e o fuso, que seguem a pessoa que olha (a escolha dela, depois a da coleção, depois a do sistema); o
// rodízio e o passar o relógio de ontem para hoje, que têm a coluna própria no usuário (lib.php); e o que só pode ficar no
// config.php (o banco, as chaves, as pastas, o e-mail).
// chave => [grupo, tipo, padrão de fábrica, mínimo, máximo, valores (enum)]
//   tipo: hora (HH:MM), sim_nao (1 ou 0), inteiro (min a max), decimal (min a max), enum (um dos valores), lista (várias chaves dos
//   valores, separadas por vírgula, na ordem que a pessoa quer, sem repetir: as colunas de uma tabela, colunas.php)
//   grupo: rotina (Rotina do dia), limites (Limites e contas), telas (Telas)
// O padrão de fábrica é o que vale quando nenhum nível escolheu. gasto_motor e documentos_copia têm o valor "individual"
// (conforme o relógio, o grupo e o arquivo): o cfg() o devolve como vazio, o que o código sempre entendeu assim
const OPCOES = [
    "horario_manha" => ["rotina", "hora", "06:30", null, null, null],
    "horario_noite" => ["rotina", "hora", "20:00", null, null, null],
    "uso_inicio" => ["rotina", "hora", "07:00", null, null, null],
    "pulso_auto_inicio" => ["rotina", "sim_nao", "1", null, null, null],
    "uso_fim" => ["rotina", "hora", "22:00", null, null, null],
    "pulso_auto_fim" => ["rotina", "sim_nao", "1", null, null, null],
    "pulso_um_so" => ["rotina", "sim_nao", "0", null, null, null],
    "sol_fim" => ["rotina", "hora", "", null, null, null],
    "sol_auto_fim" => ["rotina", "sim_nao", "1", null, null, null],
    "semana_inicio" => ["rotina", "enum", "segunda", null, null, ["segunda", "domingo"]],
    "escala_inicio" => ["rotina", "enum", "amanha", null, null, ["amanha", "hoje"]],
    "escala_periodo" => ["rotina", "enum", "corridos", null, null, ["corridos", "semana", "mes"]],
    "semana_ultimo_dia" => ["rotina", "enum", "dia_seguinte", null, null, ["dia_seguinte", "semana_seguinte"]],
    "trocar_hoje" => ["rotina", "sim_nao", "0", null, null, null],
    "dia_sem_relogio" => ["rotina", "sim_nao", "1", null, null, null],
    "max_sem_uso" => ["limites", "inteiro", "21", 0, 365, null],
    "sorteio_casas" => ["limites", "inteiro", "2", 0, 8, null],
    "carga_limiar" => ["limites", "inteiro", "20", 1, 99, null],
    "sol_limiar" => ["limites", "inteiro", "70", 1, 99, null],
    "previsao_limite" => ["limites", "decimal", "20", 0, 100, null],
    "gasto_motor" => ["limites", "enum", "individual", null, null, ["individual", "horas", "robusto", "marcacao", "cadastro", "formulas"]],
    "medicao_base" => ["limites", "enum", "dias", null, null, ["dias", "leituras"]],
    "medicao_janela_dias" => ["limites", "inteiro", "90", 1, 3650, null],
    "medicao_janela_leituras" => ["limites", "inteiro", "20", 2, 1000, null],
    "medicao_meia_vida_dias" => ["limites", "inteiro", "0", 0, 3650, null],
    "encostado_dias" => ["limites", "inteiro", "30", 1, 3650, null],
    "documentos_copia" => ["limites", "enum", "individual", null, null, ["individual", "todos", "nenhum"]],
    "painel_modo" => ["telas", "enum", "lado", null, null, ["lado", "flutuante"]],
    "destaque_hoje" => ["telas", "enum", "dia", null, null, ["dia", "pulso"]],
    "abas_vazias" => ["telas", "enum", "sempre", null, null, ["sempre", "com_relogio"]],
    "sugestao_agora" => ["telas", "sim_nao", "0", null, null, null],
    "calibre_campos" => ["telas", "enum", "movimento", null, null, ["movimento", "todos"]],
    "relojinho_modo" => ["telas", "enum", "hora", null, null, ["hora", "aleatorio", "sem_repeticao"]],
    "hora_relogio" => ["telas", "enum", "ambos", null, null, ["ambos", "analogico", "digital", "anadigi"]],
    "proximos_por_pagina" => ["telas", "inteiro", "7", 1, 366, null],
    "plano_por_pagina" => ["telas", "inteiro", "31", 1, 366, null],
    "atualizar_segundos" => ["telas", "inteiro", "60", 0, 86400, null],
    "colunas_hoje" => ["telas", "lista", "", null, null, null],
];

// O que o cfg() devolve no lugar do valor guardado (o "individual" é o vazio de sempre)
const OPCOES_SAIDA = ["gasto_motor" => ["individual" => ""], "documentos_copia" => ["individual" => ""]];

// Os textos de cada opção e de cada valor, no idioma do pedido: o que as telas Minha conta e Sistema mostram
function opcoes_textos()
{
    return [
        "grupos" => ["rotina" => t("Rotina do dia"), "limites" => t("Limites e contas"), "telas" => t("Telas")],
        "titulos" => [
            "horario_manha" => t("Manhã: relógio do dia e avisos"),
            "horario_noite" => t("Noite: preparar o de amanhã"),
            "uso_inicio" => t("No pulso a partir de"),
            "pulso_auto_inicio" => t("Pôr no pulso sozinho"),
            "uso_fim" => t("No pulso até"),
            "pulso_auto_fim" => t("Tirar do pulso sozinho"),
            "pulso_um_so" => t("Ao pôr um relógio no pulso, tirar os outros"),
            "sol_fim" => t("Sessão no sol esquecida fecha às"),
            "sol_auto_fim" => t("Tirar do sol sozinho"),
            "semana_inicio" => t("A semana começa"),
            "escala_inicio" => t("A escala inteligente começa"),
            "escala_periodo" => t("A escala inteligente fecha"),
            "semana_ultimo_dia" => t("No último dia da semana, os modos por semana montam"),
            "trocar_hoje" => t("Trocar o relógio de hoje no próprio dia"),
            "dia_sem_relogio" => t("Dias sem relógio (folga)"),
            "max_sem_uso" => t("Garantia de rodízio: dias sem uso, no máximo (0 desliga)"),
            "sorteio_casas" => t("Casas decimais dos pesos"),
            "carga_limiar" => t("Carregar quando a carga chegar a (%)"),
            "sol_limiar" => t("Solar: pôr no sol quando chegar a (%)"),
            "previsao_limite" => t("Previsão: o limite de carga (%)"),
            "gasto_motor" => t("Gasto da bateria: como calcular (válido para todos os relógios)"),
            "medicao_base" => t("Gasto medido: contar por"),
            "medicao_janela_dias" => t("Gasto medido: média dos últimos (dias)"),
            "medicao_janela_leituras" => t("Gasto medido: últimas leituras (de cada tipo)"),
            "medicao_meia_vida_dias" => t("Gasto medido: meia-vida dos dados (dias; 0 desliga)"),
            "encostado_dias" => t("Aviso Encostado: dias sem ir ao pulso"),
            "documentos_copia" => t("Cópia dos documentos no banco (válido para todos os relógios)"),
            "painel_modo" => t("Abrir o relógio na página Hoje"),
            "destaque_hoje" => t("Destaque no alto da página Hoje"),
            "abas_vazias" => t("Abas das situações sem relógio (páginas Hoje e Galeria)"),
            "sugestao_agora" => t("Sugestão para agora (página Hoje)"),
            "calibre_campos" => t("Formulário do calibre (Cadastros › Calibres)"),
            "relojinho_modo" => t("Reloginho da barra (um dos {1} modelos por hora)", [1 => RELOJINHO_MODELOS]),
            "hora_relogio" => t("Relógio da Hora certa"),
            "proximos_por_pagina" => t("Próximos dias (página Hoje): mostrar até (dias por página)"),
            "plano_por_pagina" => t("Plano: mostrar até (dias por página)"),
            "atualizar_segundos" => t("Intervalo de atualização das páginas (segundos; 0 desliga)"),
            "colunas_hoje" => t("Colunas da tabela dos relógios (página Hoje): quais e em que ordem"),
        ],
        "valores" => [
            "semana_inicio" => ["segunda" => t("Na segunda-feira"), "domingo" => t("No domingo")],
            "escala_inicio" => ["amanha" => t("Amanhã, se o dia de hoje já começou no pulso"), "hoje" => t("Sempre hoje")],
            "escala_periodo" => ["corridos" => t("Nos dias corridos do período"), "semana" => t("No fim da semana (semanas inteiras)"), "mes" => t("No fim do mês (meses inteiros)")],
            "semana_ultimo_dia" => ["dia_seguinte" => t("Só o dia seguinte"), "semana_seguinte" => t("A semana seguinte inteira")],
            "gasto_motor" => ["individual" => t("Individual (no relógio ou no grupo)"), "horas" => t("1. Pelas horas registradas (o motor original)"),
                "robusto" => t("2. Pelas horas registradas, com leituras e cadastro (robusto)"), "marcacao" => t("3. Pela marcação: duas taxas separadas"),
                "cadastro" => t("4. Só o cadastro"), "formulas" => t("5. Pelas fórmulas do cadastro")],
            "medicao_base" => ["dias" => t("Dias (os últimos dias)"), "leituras" => t("Leituras (as últimas de cada tipo)")],
            "documentos_copia" => ["individual" => t("Individual (no relógio ou no arquivo)"), "todos" => t("Todos os arquivos"), "nenhum" => t("Nenhum arquivo")],
            "painel_modo" => ["lado" => t("Ao lado da lista"), "flutuante" => t("Numa janela flutuante, grande")],
            "destaque_hoje" => ["dia" => t("O relógio do dia"), "pulso" => t("O relógio no pulso agora")],
            "abas_vazias" => ["sempre" => t("Mostrar sempre (desabilitadas)"), "com_relogio" => t("Esconder: só as situações que têm relógio")],
            "calibre_campos" => ["movimento" => t("Só os campos do movimento"), "todos" => t("Todos os campos do cadastro")],
            "relojinho_modo" => ["hora" => t("Normal: cada hora tem o seu"), "aleatorio" => t("Aleatório: sorteia um a cada hora (pode repetir)"),
                "sem_repeticao" => t("Aleatório sem repetição: os 24 embaralhados, cada um uma vez por dia")],
            "colunas_hoje" => colunas_titulos("hoje"),
            "hora_relogio" => ["ambos" => t("Analógico e digital (um ao lado do outro)"), "analogico" => t("Analógico"), "digital" => t("Digital"),
                "anadigi" => t("Anadigi (o visor digital dentro do analógico)")],
        ],
    ];
}

// O valor que cada nível guarda: "" quando não escolheu (herda). Do sistema: a tabela sistema (padrao_<chave>)
function opcao_do_sistema($chave)
{
    return trim((string)sis("padrao_" . $chave));
}

// Do usuário: tudo o que ele escolheu, chave => valor (a tabela usuario_opcao; sem a tabela, as migrações pendentes, nada)
function opcoes_do_usuario($uid)
{
    static $cache = [];
    static $tem = null;
    $k = (int)$uid . ":" . ($GLOBALS["OPCOES_VERSAO"] ?? 0);
    if (!isset($cache[$k])) {
        $cache[$k] = [];
        $tem = $tem ?? banco_tem_tabela("usuario_opcao");
        if ($tem && (int)$uid > 0) {
            foreach (sem_escopo(function () use ($uid) { return linhas("SELECT chave, valor FROM usuario_opcao WHERE usuario_id = ?", [(int)$uid]); }) as $x) {
                $cache[$k][$x["chave"]] = (string)$x["valor"];
            }
        }
    }
    return $cache[$k];
}

// O dono da coleção do pedido (0: sem coleção)
function opcoes_dono_id()
{
    static $cache = [];
    $c = colecao_atual();
    if ($c === null) {
        return 0;
    }
    $k = $c . ":" . ($GLOBALS["OPCOES_VERSAO"] ?? 0);
    if (!isset($cache[$k])) {
        $cache[$k] = (int)sem_escopo(function () use ($c) { return valor("SELECT dono_id FROM colecao WHERE id = ?", [$c]); });
    }
    return $cache[$k];
}

// O que a coleção herda de uma opção que ela não escolheu: [o valor, de onde vem (usuario, sistema ou padrao)]
function opcao_herdada($chave, $uid = null)
{
    $u = opcoes_do_usuario($uid ?? opcoes_dono_id())[$chave] ?? "";
    if ($u !== "") {
        return [$u, "usuario"];
    }
    return opcao_do_sistema_ou_padrao($chave);
}

// O que o usuário herda de uma opção que ele não escolheu: [o valor, de onde vem (sistema ou padrao)]
function opcao_do_sistema_ou_padrao($chave)
{
    $s = opcao_do_sistema($chave);
    return $s !== "" ? [$s, "sistema"] : [OPCOES[$chave][2], "padrao"];
}

// O valor que o cfg() devolve para uma opção que a coleção não escolheu
function opcao_valor_herdado($chave)
{
    $v = opcao_herdada($chave)[0];
    return OPCOES_SAIDA[$chave][$v] ?? $v;
}

// Confere o valor de uma opção (do nível que for): [o erro ou null, o valor como se guarda]. Vazio é válido: herda
function opcao_conferir($chave, $valor)
{
    if (!isset(OPCOES[$chave])) {
        return [t("Opção desconhecida: {1}.", [1 => $chave]), null];
    }
    [, $tipo, , $min, $max, $valores] = OPCOES[$chave];
    // a lista (as colunas de uma tabela): o conferidor do motor de colunas, que aceita "a,b,c" ou uma lista
    if ($tipo === "lista") {
        return colunas_conferir(colunas_pagina_da_opcao($chave), $valor);
    }
    $v = is_array($valor) ? null : trim((string)$valor);
    if ($v === "") {
        return [null, ""];
    }
    $ok = false;
    $guardar = $v;
    if ($v !== null) {
        if ($tipo === "hora") {
            $ok = preg_match("/^([01][0-9]|2[0-3]):[0-5][0-9]\$/", $v) === 1;
        } elseif ($tipo === "sim_nao") {
            $ok = $v === "0" || $v === "1";
        } elseif ($tipo === "inteiro") {
            $ok = ctype_digit($v) && (int)$v >= $min && (int)$v <= $max;
            $guardar = (string)(int)$v;
        } elseif ($tipo === "decimal") {
            $n = numero_br($v);
            $ok = $n !== null && $n >= $min && $n <= $max;
            $guardar = (string)$n;
        } else {
            $ok = in_array($v, $valores, true);
        }
    }
    if ($ok) {
        return [null, $guardar];
    }
    $vazio = t("vazio: herdar do nível acima");
    if ($tipo === "hora") {
        return [t("{1}: uma hora HH:MM ({2}).", [1 => $chave, 2 => $vazio]), null];
    }
    if ($tipo === "sim_nao") {
        return [t("{1}: 1 (ligado) ou 0 (desligado) ({2}).", [1 => $chave, 2 => $vazio]), null];
    }
    if ($tipo === "inteiro" || $tipo === "decimal") {
        return [t("{1}: um número de {2} a {3} ({4}).", [1 => $chave, 2 => $min, 3 => $max, 4 => $vazio]), null];
    }
    return [t("{1}: um destes valores: {2} ({3}).", [1 => $chave, 2 => implode(", ", $valores), 3 => $vazio]), null];
}

// Confere vários de uma vez (chave => valor; só as chaves da tabela OPCOES): [os erros, chave => o valor como se guarda]
function opcoes_conferir($dados)
{
    $erros = [];
    $ok = [];
    foreach ($dados as $chave => $valor) {
        [$erro, $guardar] = opcao_conferir((string)$chave, $valor);
        if ($erro !== null) {
            $erros[] = $erro;
        } else {
            $ok[(string)$chave] = $guardar;
        }
    }
    return [$erros, $ok];
}

// O horário de uso de um nível (usuario ou sistema) depois da mudança: o fim depois do início, com o que o nível escolheu ou herda.
// Devolve o erro, ou null
function opcoes_conferir_horario($nivel, $uid, $ok)
{
    if (!array_key_exists("uso_inicio", $ok) && !array_key_exists("uso_fim", $ok)) {
        return null;
    }
    $vale = function ($k) use ($nivel, $uid, $ok) {
        $x = array_key_exists($k, $ok) ? $ok[$k] : ($nivel === "usuario" ? (opcoes_do_usuario((int)$uid)[$k] ?? "") : opcao_do_sistema($k));
        return $x !== "" ? $x : ($nivel === "usuario" ? opcao_do_sistema_ou_padrao($k)[0] : OPCOES[$k][2]);
    };
    return $vale("uso_fim") > $vale("uso_inicio") ? null : t("Relógio no pulso: duas horas HH:MM, o fim depois do início.");
}

// A lista que a API entrega de um nível (colecao, usuario ou sistema): cada opção com o texto, os valores possíveis, o que o
// nível escolheu ("" herda), o que herda e de onde, e o que vale (efetivo, com a origem). Para a coleção do pedido, o usuário
// do pedido ou o sistema
function opcoes_lista($nivel, $uid = null)
{
    $tx = opcoes_textos();
    $lista = [];
    foreach (OPCOES as $chave => [$grupo, $tipo, $padrao, $min, $max, $valores]) {
        // a lista de colunas: as do catálogo (as fixas e as cadastradas da árvore) e, sem escolha em nenhum nível, as que aparecem de início
        $padrao_lista = null;
        if ($tipo === "lista") {
            $valores = colunas_catalogo(colunas_pagina_da_opcao($chave));
            $padrao_lista = implode(",", colunas_padrao(colunas_pagina_da_opcao($chave)));
        }
        if ($nivel === "colecao") {
            $proprio = cfg_cru($chave);
            [$herdado, $origem_h] = opcao_herdada($chave);
        } elseif ($nivel === "usuario") {
            $proprio = opcoes_do_usuario((int)$uid)[$chave] ?? "";
            [$herdado, $origem_h] = opcao_do_sistema_ou_padrao($chave);
        } else {
            $proprio = opcao_do_sistema($chave);
            [$herdado, $origem_h] = [$padrao, "padrao"];
        }
        // a hora do sol de uma coleção sem escolha em nenhum nível é a que o tipo No sol já tem (sol_fim_hora), senão 18:00
        if ($chave === "sol_fim" && $herdado === "") {
            $herdado = $nivel === "colecao" && isset(lancamento_tipos()["sol"]) ? sol_fim_hora() : "18:00";
        }
        // a lista sem escolha em nenhum nível vale inteira, na ordem de sempre: é o que a tela mostra como herdado
        if ($tipo === "lista" && $herdado === "") {
            $herdado = $padrao_lista;
        }
        $lista[] = ["chave" => $chave, "grupo" => $grupo, "tipo" => $tipo, "titulo" => $tx["titulos"][$chave], "minimo" => $min, "maximo" => $max,
            "valores" => $valores === null ? null : array_map(function ($v) use ($tx, $chave) { return ["valor" => $v, "texto" => $tx["valores"][$chave][$v] ?? $v]; }, $valores),
            "fixos" => $tipo === "lista" ? colunas_fixas(colunas_pagina_da_opcao($chave)) : null,
            "padrao" => $padrao, "escolha" => $proprio, "herdado" => $herdado, "origem_herdado" => $origem_h,
            "efetivo" => $proprio !== "" ? $proprio : $herdado, "origem" => $proprio !== "" ? $nivel : $origem_h];
    }
    return ["grupos" => array_map(function ($g) use ($tx) { return ["chave" => $g, "texto" => $tx["grupos"][$g]]; }, array_keys($tx["grupos"])), "opcoes" => $lista];
}

// ---------------------------------------------------------------------------------------------------------------------
// Gravar o nível do usuário e o do sistema, e o que muda em cada coleção que herda
// ---------------------------------------------------------------------------------------------------------------------

// Roda uma função com o escopo numa coleção (e volta o escopo de antes)
function opcoes_na_colecao($c, $f)
{
    $antes = $GLOBALS["BANCO_ESCOPO"] ?? null;
    banco_escopo($c);
    try {
        return $f();
    } finally {
        $GLOBALS["BANCO_ESCOPO"] = $antes;
        $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
    }
}

// O que, na coleção do escopo, depende do valor que vale de algumas opções (para ver o que mudou)
function opcoes_foto()
{
    return ["uso_fim" => cfg("uso_fim"), "pulso_auto_fim" => cfg("pulso_auto_fim"), "sol_fim" => cfg("sol_fim"), "sol_auto_fim" => cfg("sol_auto_fim"),
        "escala_inicio" => cfg("escala_inicio"), "escala_periodo" => cfg("escala_periodo"), "semana_inicio" => cfg("semana_inicio")];
}

// O que muda além do valor quando uma opção muda (na coleção do escopo): o "fecha às" dos tipos No pulso e No sol segue o fim do
// horário de uso e a hora do sol; a escala inteligente é refeita na próxima montagem quando o primeiro dia, onde ela fecha ou
// a semana mudam; e as contas guardadas das fórmulas saem. $antes: a foto de antes; $forcar: as chaves que o pedido trouxe (o
// "fecha às" se acerta mesmo sem a mudança)
function opcoes_efeitos($antes, $forcar = [])
{
    $agora = opcoes_foto();
    $mudou = function ($chaves) use ($antes, $agora, $forcar) {
        foreach ($chaves as $k) {
            if (($antes[$k] ?? null) !== $agora[$k] || in_array($k, $forcar, true)) {
                return true;
            }
        }
        return false;
    };
    // a hora do sol fica na Configuração; tirando do sol sozinho, o "fecha às" do tipo No sol é ela; sem tirar sozinho, nenhum
    if ($mudou(["sol_fim", "sol_auto_fim"]) && isset(lancamento_tipos()["sol"])) {
        sql("UPDATE lancamento_tipo SET fecha_as = ? WHERE identificador = 'sol'", [sol_tira_sozinho() ? sol_fim_hora() . ":00" : null]);
    }
    // tirando do pulso sozinho, o "fecha às" do tipo No pulso é o fim do horário de uso; sem tirar sozinho, nenhum (só o Tirou)
    if ($mudou(["pulso_auto_fim", "uso_fim"]) && isset(lancamento_tipos()["pulso"])) {
        sql("UPDATE lancamento_tipo SET fecha_as = ? WHERE identificador = 'pulso'", [pulso_tira_sozinho() ? cfg("uso_fim") . ":00" : null]);
    }
    // o primeiro dia da escala mudou: o fim dela sai, e a próxima montagem (a página Hoje, o cron) refaz a escala contando do
    // primeiro dia novo; onde a escala fecha mudou (ou a semana, com a escala fechando na semana ou no mês): o mesmo
    $semana_conta = in_array($agora["escala_periodo"], ["semana", "mes"], true) && ($antes["semana_inicio"] ?? null) !== $agora["semana_inicio"];
    if (($antes["escala_inicio"] ?? null) !== $agora["escala_inicio"] || ($antes["escala_periodo"] ?? null) !== $agora["escala_periodo"] || $semana_conta) {
        cfg_set("escala_fim", "");
    }
    $GLOBALS["FORMULAS_VERSAO"] = ($GLOBALS["FORMULAS_VERSAO"] ?? 0) + 1;
}

// As coleções que herdam de um nível: as do dono (o nível do usuário) ou todas (o do sistema)
function opcoes_colecoes_afetadas($uid)
{
    return sem_escopo(function () use ($uid) {
        return array_map("intval", array_column($uid === null ? linhas("SELECT id FROM colecao ORDER BY id") : linhas("SELECT id FROM colecao WHERE dono_id = ? ORDER BY id", [(int)$uid]), "id"));
    });
}

// O erro de uma mudança de nível que deixaria uma coleção com o que não pode (o horário de uso com o fim antes do início)
class OpcoesErro extends RuntimeException
{
}

// Grava uma mudança de nível e refaz o que depende dela em cada coleção afetada (a foto de antes e a de depois). $uid: o
// usuário (o nível dele) ou null (o do sistema). $gravar roda uma vez, entre as fotos; $desfazer volta o que ficou na memória
// quando a transação desfaz. Uma coleção que ficaria com o horário de uso invertido (a dela escolheu o início, o nível, o fim)
// recusa tudo: nada muda e o OpcoesErro diz qual
function opcoes_gravar_nivel($uid, $gravar, $desfazer)
{
    try {
        banco_atomico(function () use ($uid, $gravar) {
            $fotos = [];
            foreach (opcoes_colecoes_afetadas($uid) as $c) {
                $fotos[$c] = opcoes_na_colecao($c, function () { return opcoes_foto(); });
            }
            $gravar();
            $GLOBALS["OPCOES_VERSAO"] = ($GLOBALS["OPCOES_VERSAO"] ?? 0) + 1;
            foreach ($fotos as $c => $antes) {
                opcoes_na_colecao($c, function () use ($c, $antes) {
                    if (cfg("uso_fim") <= cfg("uso_inicio")) {
                        $nome = (string)valor("SELECT nome FROM colecao WHERE id = ?", [$c]);
                        throw new OpcoesErro(t("A coleção {1} ficaria com o horário de uso invertido ({2} a {3}): o fim tem de ser depois do início.", [1 => $nome, 2 => cfg("uso_inicio"), 3 => cfg("uso_fim")]));
                    }
                    opcoes_efeitos($antes);
                });
            }
        });
    } catch (Throwable $e) {
        $desfazer();
        $GLOBALS["OPCOES_VERSAO"] = ($GLOBALS["OPCOES_VERSAO"] ?? 0) + 1;
        throw $e;
    }
}

// O nível do usuário: chave => valor (vazio apaga a escolha: herda o sistema). Já conferido por opcoes_conferir()
function opcoes_usuario_gravar($uid, $valores)
{
    if (count($valores) === 0) {
        return;
    }
    opcoes_gravar_nivel((int)$uid, function () use ($uid, $valores) {
        foreach ($valores as $chave => $v) {
            sem_escopo(function () use ($uid, $chave, $v) {
                sql("DELETE FROM usuario_opcao WHERE usuario_id = ? AND chave = ?", [(int)$uid, $chave]);
                if ($v !== "") {
                    sql("INSERT INTO usuario_opcao (usuario_id, chave, valor) VALUES (?, ?, ?)", [(int)$uid, $chave, $v]);
                }
            });
        }
    }, function () {
    });
}

// O nível do sistema: chave => valor (vazio apaga a escolha: vale o padrão de fábrica). Já conferido por opcoes_conferir()
function opcoes_sistema_gravar($valores)
{
    if (count($valores) === 0) {
        return;
    }
    $antes = [];
    foreach ($valores as $chave => $v) {
        $antes[$chave] = opcao_do_sistema($chave);
    }
    opcoes_gravar_nivel(null, function () use ($valores) {
        foreach ($valores as $chave => $v) {
            sis_set("padrao_" . $chave, $v);
        }
    }, function () use ($antes) {
        foreach ($antes as $chave => $v) {
            sis("padrao_" . $chave, $v);
        }
    });
}
