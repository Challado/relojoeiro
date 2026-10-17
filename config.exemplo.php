<?php
// Copie para config.php e preencha. O config.php não vai nos pacotes: o seu fica.

// o banco: "mysql" (MySQL ou MariaDB, o padrão), "pgsql" (PostgreSQL), "sqlite" (SQLite, um arquivo só, sem servidor) ou
// "firebird" (Firebird 3 ou mais novo)
define("DB_TIPO", "mysql");
// MySQL, PostgreSQL e Firebird: o servidor, a porta (0: a padrão, 3306, 5432 ou 3050), o banco, o usuário e a senha. No
// Firebird, DB_NOME é o caminho do arquivo .fdb no servidor (ex.: /var/lib/firebird/3.0/data/relogios2.fdb) ou um apelido
// do databases.conf, e DB_HOST vazio abre o arquivo direto, sem servidor (o Firebird embutido)
define("DB_HOST", "127.0.0.1");
define("DB_PORTA", 0);
define("DB_NOME", "relogios2");
define("DB_USUARIO", "relogios");
define("DB_SENHA", "a senha do banco");
// SQLite: o caminho do arquivo do banco, FORA da pasta que o servidor web publica (ele é criado pelo instalar.php)
define("DB_ARQUIVO", "/var/lib/relogios2/relogios2.sqlite");

// obrigatório, com pelo menos 10 caracteres: sem ele o sistema não abre. Vale como o master mais antigo (os scripts do
// servidor); cada pessoa entra com o login dela, ou com um token pessoal (Minha conta)
define("API_TOKEN", "uma-chave-longa-e-secreta");
// a chave que cifra os segredos guardados no banco (as senhas do CalDAV, as chaves do Google, os tokens do Outlook, os
// cabeçalhos dos webhooks): quem copia o banco não leva as senhas. Sem esta linha, o API_TOKEN (trocar a chave, ou o token
// sem ela, perde os segredos guardados: é só digitar de novo)
// define("SEGREDO_CHAVE", "outra-chave-longa-e-secreta");
// o fuso horário do sistema (e das datas que o banco grava); vazio: o do PHP (date.timezone no php.ini)
define("FUSO", "America/Sao_Paulo");
// o título das mensagens (o {titulo} dos webhooks, o assunto dos e-mails, o título do Web Push)
define("MSG_TITULO", "Relógios");
// só para a migração v25 (a passagem para várias pessoas): a API de mensagem antiga (Telegram) vira o primeiro destino
// webhook da coleção. Depois da migração, os destinos ficam na página Destinos e estas duas linhas não servem mais
// define("MSG_ENDPOINT", "");
// define("MSG_DESTINATARIO", "");

// as contas: o cadastro aberto (a página cadastro.php, sem login). false: fechado, só entra quem o master cria ou quem tem
// um convite. Sem a linha: aberto
// define("CADASTRO_ABERTO", true);
// os dias da conta de demonstração (a criada pelo cadastro aberto): o aviso vai por e-mail 7 dias e 1 dia antes, e a
// vencida o cron apaga com tudo (o master torna a conta oficial no painel Usuários). 0: a conta já nasce oficial. Sem a
// linha: 30
// define("DEMO_DIAS", 30);
// quantos dias um convite ou um pedido de acesso espera (depois, o cron apaga). Sem a linha: 7
// define("CONVITE_DIAS", 7);

// o e-mail (os destinos de e-mail, o Esqueci a senha, os convites, os pedidos e os avisos da conta): smtp (o servidor
// abaixo) ou mail (o mail() do PHP, que precisa de um sendmail no servidor). Sem EMAIL_METODO e com SMTP_HOST: smtp. Sem
// os dois: nenhum e-mail sai (a tela avisa; o link do convite aparece para quem convida mandar)
// define("EMAIL_METODO", "smtp");
// define("EMAIL_REMETENTE", "relojoeiro@exemplo.com");
// o SMTP: o servidor, a porta (sem ela, 587; com ssl, 465), a segurança (tls: STARTTLS, o padrão; ssl: TLS desde o
// começo; nenhuma) e o login (no Gmail e no Outlook, uma senha de app)
// define("SMTP_HOST", "smtp.exemplo.com");
// define("SMTP_PORTA", 587);
// define("SMTP_SEGURANCA", "tls");
// define("SMTP_USUARIO", "relojoeiro@exemplo.com");
// define("SMTP_SENHA", "a senha do e-mail");
// opcional: uma cópia de cada e-mail enviado na pasta Enviados da conta, pelo IMAP (a pasta: sem a linha, Sent)
// define("IMAP_HOST", "imap.exemplo.com");
// define("IMAP_PORTA", 993);
// define("IMAP_SEGURANCA", "ssl");
// define("IMAP_USUARIO", "relojoeiro@exemplo.com");
// define("IMAP_SENHA", "a senha do e-mail");
// define("IMAP_PASTA_ENVIADOS", "Sent");
// opcional: o POP3, só para o teste do e-mail conferir a caixa (alguns provedores pedem o POP antes do SMTP)
// define("POP_HOST", "pop.exemplo.com");
// define("POP_PORTA", 995);
// define("POP_SEGURANCA", "ssl");
// define("POP_USUARIO", "relojoeiro@exemplo.com");
// define("POP_SENHA", "a senha do e-mail");

// o Web Push (as notificações no navegador e no celular): o par de chaves VAPID, em base64url (o master gera em Minha
// conta, "Chaves do Web Push"; trocar as chaves desliga os aparelhos inscritos) e o assunto (mailto: ou o endereço do
// sistema). O site tem de abrir por https (o navegador exige)
// define("VAPID_PUBLICA", "");
// define("VAPID_PRIVADA", "");
// define("VAPID_ASSUNTO", "mailto:relojoeiro@exemplo.com");

// as agendas do Outlook (Microsoft 365, Outlook.com): o aplicativo registrado no portal da Microsoft (Entra ID › Registros
// de aplicativo), com a permissão Calendars.ReadWrite e o endereço de retorno http(s)://<o seu servidor>/relojoeiro/api.php
// ?recurso=outlook_retorno. Sem as duas linhas, o Outlook fica de fora (o link ICS funciona com ele)
// define("OUTLOOK_CLIENTE_ID", "");
// define("OUTLOOK_CLIENTE_SEGREDO", "");

// os documentos dos relógios (o manual, a nota fiscal, fotos, vídeos...): a pasta onde os arquivos ficam, FORA da pasta que
// o servidor web publica, com permissão de escrita para o usuário do PHP (sem ela, a página Documentos só avisa que falta)
define("DOCUMENTOS_PASTA", "/var/lib/relogios2/documentos");
// o maior documento aceito, em bytes. Sem esta linha: 100 MB (104857600). 0 ou -1: sem limite do sistema. Valem sempre
// também o limite do PHP (upload_max_filesize e post_max_size do php.ini) e o do nginx (client_max_body_size). O
// MANUAL_LIMITE, o nome antigo, ainda vale quando esta linha não existe. Ex.: 524288000 = 500 MB
// define("DOCUMENTOS_LIMITE", 524288000);
// a cópia de segurança dos documentos dentro do banco (o arquivo vai para a pasta e também para o banco, em pedaços de 4 MB;
// se um dia ele sumir da pasta, volta sozinho do banco) é escolhida por cada dono na Configuração da coleção dele (Cópia dos
// documentos no banco: todos, nenhum, ou cada relógio e cada arquivo decidem). O DOCUMENTOS_COPIA_BANCO, que ficava aqui e
// valia para o servidor inteiro, não vale mais: se ainda estiver definido, a Configuração avisa

// a cópia de segurança do banco: uma por dia, na rodada da noite do cron, nesta pasta do servidor (fora da pasta
// publicada, com escrita para o PHP); sem esta linha, não há cópia automática. Ficam as BACKUP_MANTER mais novas (sem a
// linha: 7). Com BACKUP_DOCUMENTOS = true, a cópia leva também o conteúdo dos documentos guardado no banco (pode ficar
// grande; sem a linha, não leva: os arquivos já estão na pasta dos documentos)
// define("BACKUP_PASTA", "/var/lib/relojoeiro/copias");
// define("BACKUP_MANTER", 7);
// define("BACKUP_DOCUMENTOS", false);
// Para não usar a cópia de segurança (outra rotina já copia o banco, ou é uma instalação de teste): false desliga a cópia
// automática e a ação de fazer uma, e tira o quadro Cópia de segurança da Configuração (com o aviso de que falta a pasta).
// Sem a linha: ligada
// define("BACKUP_ATIVO", false);

// só para o importar.php, com este sistema no PostgreSQL ou no SQLite: onde está o MySQL do sistema antigo
// define("ANTIGO_HOST", "127.0.0.1");
// define("ANTIGO_PORTA", 3306);
// define("ANTIGO_USUARIO", "relogios");
// define("ANTIGO_SENHA", "a senha do banco antigo");

// só para testes: os endereços do Google e da Microsoft que as agendas usam (sem estas linhas, os de verdade); trocados, a
// sincronização conversa com um servidor de teste
// define("GOOGLE_TOKEN_URL", "https://oauth2.googleapis.com/token");
// define("GOOGLE_API_URL", "https://www.googleapis.com/calendar/v3");
// define("OUTLOOK_LOGIN_URL", "https://login.microsoftonline.com/common/oauth2/v2.0");
// define("OUTLOOK_GRAPH_URL", "https://graph.microsoft.com/v1.0");
// só para testes: as fontes dos números aleatórios dos sorteios (o RANDOM.ORG e, fora do ar, o ANU QRNG; sem os dois, o
// gerador seguro do PHP). Trocadas, os sorteios pedem os números a um servidor de teste
// define("ALEATORIO_RANDOMORG_URL", "https://www.random.org/integers/");
// define("ALEATORIO_ANU_URL", "https://qrng.anu.edu.au/API/jsonI.php");
// para quem traduz: a tradução que falta num idioma (textos/<idioma>.php) para com o erro "Falta a tradução em en
// (textos/en.php): ..." na API e no cron, e aparece entre ⟦ ⟧ nas telas (com o erro no console do navegador). Sem esta linha
// (o normal num servidor de uso), o que falta sai em português e nada para. php testes/textos.php lista tudo o que falta
// de uma vez
// define("TEXTOS_ESTRITO", true);
