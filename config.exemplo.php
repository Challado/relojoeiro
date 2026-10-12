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

// obrigatório, com pelo menos 10 caracteres: sem ele o sistema não abre
define("API_TOKEN", "uma-chave-longa-e-secreta");
// o fuso horário do sistema (e das datas que o banco grava); vazio: o do PHP (date.timezone no php.ini)
define("FUSO", "America/Sao_Paulo");
// a API de mensagem (Telegram): o endereço, o destinatário e o título das mensagens; sem o endereço, nada é enviado
define("MSG_ENDPOINT", "");
define("MSG_DESTINATARIO", "");
define("MSG_TITULO", "Relógios");

// os documentos dos relógios (o manual, a nota fiscal, fotos, vídeos...): a pasta onde os arquivos ficam, FORA da pasta que
// o servidor web publica, com permissão de escrita para o usuário do PHP (sem ela, a página Documentos só avisa que falta)
define("DOCUMENTOS_PASTA", "/var/lib/relogios2/documentos");
// o maior documento aceito, em bytes. Sem esta linha: 100 MB (104857600). 0 ou -1: sem limite do sistema. Valem sempre
// também o limite do PHP (upload_max_filesize e post_max_size do php.ini) e o do nginx (client_max_body_size). O
// MANUAL_LIMITE, o nome antigo, ainda vale quando esta linha não existe. Ex.: 524288000 = 500 MB
// define("DOCUMENTOS_LIMITE", 524288000);
// a cópia de segurança dos documentos dentro do banco, para o sistema inteiro: o arquivo vai para a pasta e também para o
// banco (em pedaços de 4 MB); se um dia ele sumir da pasta (um backup do banco restaurado noutro servidor), volta sozinho
// do banco. true: todo arquivo vai, de todo relógio. false: nenhum vai (o banco fica menor). Sem esta linha: cada relógio
// (no cadastro dele) e cada arquivo (no envio e em editar) decidem
// define("DOCUMENTOS_COPIA_BANCO", true);

// a cópia de segurança do banco: uma por dia, na rodada da noite do cron, nesta pasta do servidor (fora da pasta
// publicada, com escrita para o PHP); sem esta linha, não há cópia automática. Ficam as BACKUP_MANTER mais novas (sem a
// linha: 7). Com BACKUP_DOCUMENTOS = true, a cópia leva também o conteúdo dos documentos guardado no banco (pode ficar
// grande; sem a linha, não leva: os arquivos já estão na pasta dos documentos)
// define("BACKUP_PASTA", "/var/lib/relojoeiro/copias");
// define("BACKUP_MANTER", 7);
// define("BACKUP_DOCUMENTOS", false);

// só para o importar.php, com este sistema no PostgreSQL ou no SQLite: onde está o MySQL do sistema antigo
// define("ANTIGO_HOST", "127.0.0.1");
// define("ANTIGO_PORTA", 3306);
// define("ANTIGO_USUARIO", "relogios");
// define("ANTIGO_SENHA", "a senha do banco antigo");

// só para testes: os endereços do Google que a agenda usa (sem estas linhas, os do Google de verdade); trocados, a
// sincronização da agenda conversa com um servidor de teste
// define("GOOGLE_TOKEN_URL", "https://oauth2.googleapis.com/token");
// define("GOOGLE_API_URL", "https://www.googleapis.com/calendar/v3");
// só para testes: as fontes dos números aleatórios dos sorteios (o RANDOM.ORG e, fora do ar, o ANU QRNG; sem os dois, o
// gerador seguro do PHP). Trocadas, os sorteios pedem os números a um servidor de teste
// define("ALEATORIO_RANDOMORG_URL", "https://www.random.org/integers/");
// define("ALEATORIO_ANU_URL", "https://qrng.anu.edu.au/API/jsonI.php");
