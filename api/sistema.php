<?php
// recurso=sistema: o que é do sistema inteiro (só o master), como a página Sistema mostra: o endereço do sistema, as rodadas do
// cron antes de matar a travada, os parâmetros de operação (cada um com o valor que vale e de onde vem), a força da senha, a
// situação do cron, de onde vêm os números dos sorteios e o e-mail do sistema (o que ele leu do config.php). A cópia de
// segurança (recurso=backup) e o SQL (recurso=sql) têm os recursos deles. A gravação é a da Configuração (recurso=config,
// acao=salvar: url_sistema, cron_espera_rodadas, senha_<regra>, parametros[<chave>]); o teste do e-mail, recurso=conta,
// acao=email_testar.
// Parte do api.php: roda dentro dele (o require no lugar deste bloco), com as mesmas variáveis ($recurso, $acao, $d,
// $saida, $codigo...); aberto direto, não faz nada.
if (!defined("RELOJOEIRO_API")) {
    http_response_code(404);
    exit;
}

$saida = ["url_sistema" => sis("url_sistema"), "cron_espera_rodadas" => cron_espera_rodadas(),
    "parametros" => parametros_sistema_lista(),
    "senha_politica" => senha_politica_api(),
    "cron_estado" => cron_estado(),
    "aleatorio" => aleatorio_resumo(),
    // o servidor de e-mail do config.php (as senhas só como definida ou não, os problemas achados) e para quem vai o e-mail de
    // teste, de início (o e-mail do master)
    "email" => email_config_resumo(), "email_para" => (string)(usuario_atual()["email"] ?? "")];
