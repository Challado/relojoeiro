<?php
// recurso=config: a Configuração inteira.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

// tudo o que a página Configuração mostra (as opções da Rotina do dia, dos Limites e contas e das Telas, nos três níveis; o idioma
// e o fuso da coleção), os eventos personalizados, os relógios, e os resumos dos destinos e das agendas (que têm as páginas
// deles). As opções do rodízio e dos avisos ficam nas páginas deles (recurso=opcoes); o que é do sistema inteiro, na página
// Sistema (recurso=sistema)
$hoje = hoje_da_colecao();
garantir_plano($hoje);
$valores_c = [];
foreach (linhas("SELECT chave, valor FROM config ORDER BY chave") as $c) {
    $valores_c[$c["chave"]] = $c["valor"];
}
// o aviso Encostado ligado ou não (a caixa da Configuração liga e desliga o aviso do cadastro, o mesmo que a página Avisos)
$valores_c["encostado_ativo"] = encostado_ativo() ? "1" : "0";
$grupos_c = OPCOES_PAGINAS["configuracao.php"];
$niveis_c = opcoes_niveis($grupos_c);
$saida = ["config" => (object)$valores_c,
    // as opções das três hierarquias (sistema → usuário → coleção) dos assuntos desta página: cada uma com o que a coleção
    // escolheu (vazio herda), o que herda e de onde, e o que vale
    "opcoes" => $niveis_c["colecao"],
    // os idiomas (o menu Idioma padrão)
    "linguas" => array_map(function ($k) { return ["codigo" => $k, "nome" => LINGUAS[$k]["nome"]]; }, array_keys(LINGUAS)),
    // os fusos (o campo Fuso horário: vazio é o do sistema) e o do sistema
    "fuso_sistema" => fuso_sistema(), "fusos" => fusos_lista(),
    "mostrar_ate" => ["proximos" => proximos_por_pagina(), "plano" => plano_por_pagina()],
    "atualizar_segundos" => atualizar_segundos(),
    // o reloginho da barra: o modo (hora, aleatorio ou sem_repeticao) e quantos modelos ele tem
    "relojinho" => ["modo" => relojinho_modo(), "modelos" => RELOJINHO_MODELOS],
    "sol_fim" => isset(lancamento_tipos()["sol"]) ? sol_fim_hora() : "", "sol_auto_fim" => sol_tira_sozinho(),
    // trocar o relógio de hoje no próprio dia (a caixa da Configuração; desligado, o padrão: a troca vale a partir de amanhã)
    "trocar_hoje" => trocar_hoje_permitido(),
    // o padrão do sistema de cada opção destes assuntos (a aba Sistema: só o master vê e muda; os outros, null); e, como na
    // v66 (os clientes que já liam aqui), o endereço do sistema, as rodadas do cron e os parâmetros de operação (só o master)
    "sistema" => ["pode_mudar" => $niveis_c["sistema"]["pode_mudar"], "padroes" => $niveis_c["sistema"]["opcoes"],
        "url_sistema" => sis("url_sistema"), "cron_espera_rodadas" => cron_espera_rodadas(),
        "parametros" => usuario_master() ? parametros_sistema_lista_compat() : null],
    // o nível do dono (a aba Minhas coleções): o padrão dele para as coleções de que é dono. Só o dono da coleção do pedido o vê
    // e muda (pode_mudar); para os outros, opcoes vem null. Como na v66: o rodízio e o relógio de ontem do dono (true, false ou
    // null: herda; null também para quem não é o dono) e o que o sistema faz valer nos dois
    "dono" => $niveis_c["dono"] + (function () use ($niveis_c) {
        $pode = $niveis_c["dono"]["pode_mudar"];
        $uid = (int)(usuario_atual()["id"] ?? 0);
        return ["rodizio_ativo" => $pode ? usuario_preferencia_sim_nao($uid, "rodizio_ativo") : null,
            "recupera_ontem" => $pode ? usuario_preferencia_sim_nao($uid, "recupera_ontem") : null,
            "rodizio_sistema" => opcao_do_sistema_ou_padrao("rodizio_ativo")[0] === "1", "recupera_ontem_sistema" => opcao_do_sistema_ou_padrao("recupera_ontem")[0] === "1"];
    })(),
    // como na v66 (as páginas novas leem recurso=opcoes e recurso=sistema; os clientes de antes, daqui): o rodízio e o relógio
    // de ontem da coleção (o que vale, a escolha de cada nível e a origem), a situação do cron (o resumo que todo usuário vê da
    // própria coleção), de onde vêm os números dos sorteios e a força da senha
    "rodizio" => rodizio_configuracao(),
    "recupera_ontem" => recupera_ontem_configuracao(),
    "cron_estado" => cron_estado(),
    "aleatorio" => aleatorio_resumo(),
    "senha_politica" => senha_politica_api(),
    // o DOCUMENTOS_COPIA_BANCO do config.php não vale mais (a cópia é escolhida aqui, por coleção): definido, a página avisa
    "config_php_copia" => defined("DOCUMENTOS_COPIA_BANCO") ? (bool)DOCUMENTOS_COPIA_BANCO : null,
    "repeticoes" => $REPETICOES,
    "eventos" => array_map(function ($ev) {
        return array_merge(evento_legivel($ev), ["proximas_60" => array_map(function ($t) { return date("Y-m-d H:i:s", $t); },
            array_slice(ocorrencias($ev, time(), time() + 60 * 86400), 0, 5))]);
    }, eventos_com_dias(linhas("SELECT * FROM evento_personalizado ORDER BY nome"))),
    "relogios" => array_map(function ($r) { return ["id" => (int)$r["id"], "nome" => $r["nome"], "disponivel" => (int)$r["disponivel"] === 1]; },
        linhas("SELECT id, nome, disponivel FROM relogio ORDER BY nome")),
    "destinos" => array_values(array_map(function ($x) { return ["id" => $x["id"], "nome" => $x["nome"], "tipo" => $x["tipo"], "tipo_nome" => t(DESTINO_TIPOS[$x["tipo"]] ?? $x["tipo"]), "ativo" => $x["ativo"]]; }, destinos())),
    "agendas" => array_values(array_map(function ($x) { return ["id" => $x["id"], "nome" => $x["nome"], "tipo" => $x["tipo"], "tipo_nome" => t(AGENDA_TIPOS[$x["tipo"]] ?? $x["tipo"]), "ativa" => $x["ativa"]]; }, agendas()))];
