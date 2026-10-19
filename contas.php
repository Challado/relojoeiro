<?php
// As contas: o cadastro aberto (a página cadastro.php), a conta de cada um (Minha conta), as coleções e as árvores do dono,
// os acessos, os convites, os pedidos de acesso (a página Aprovações), os tokens de API pessoais, os aparelhos do Web Push e
// o painel do master (recurso=usuarios). Sem escopo de coleção: tudo aqui é do sistema inteiro, e cada operação confere quem
// pode. A API (recurso=conta, colecoes, acessos, convites, pedidos, cadastro e usuarios) chama estas funções.

// ---------------------------------------------------------------------------------------------------------------------
// As regras do config.php (todas opcionais)
// ---------------------------------------------------------------------------------------------------------------------

// O cadastro aberto (a página cadastro.php): CADASTRO_ABERTO (sem ela, aberto). Fechado, só entra quem o master cria ou quem
// tem um convite
function cadastro_aberto()
{
    return (bool)cfg_php("CADASTRO_ABERTO", true);
}

// Os dias da conta de demonstração (a criada pelo cadastro aberto): DEMO_DIAS (sem ela, 30; 0: a conta já nasce oficial)
function demo_dias()
{
    return max(0, (int)cfg_php("DEMO_DIAS", 30));
}

// Os dias que um convite ou um pedido de acesso espera (depois, o cron apaga): CONVITE_DIAS (sem ela, 7)
function convite_dias()
{
    $n = (int)cfg_php("CONVITE_DIAS", 7);
    return $n > 0 ? $n : 7;
}

// ---------------------------------------------------------------------------------------------------------------------
// Peças
// ---------------------------------------------------------------------------------------------------------------------

// Um token novo (32 caracteres, base64url) e o hash que fica no banco (o token mesmo só aparece uma vez)
function token_novo()
{
    return b64url(random_bytes(24));
}

function token_hash($token)
{
    return hash("sha256", "relojoeiro|" . (string)$token);
}

// Os aparelhos lembrados (o aplicativo no celular): "Lembrar este aparelho" (Minha conta) cria uma chave, grava o hash dela
// na tabela usuario_aparelho e a chave num cookie do navegador (só HTTP, um ano); daí em diante o pedido que chega sem o
// login do site mas com o cookie entra como o dono da chave (usuario_autenticado, no lib.php). "Esquecer" apaga; trocar a
// senha apaga todos. O cookie vale na pasta do sistema
function aparelho_cookie($chave, $apagar = false)
{
    $pasta = rtrim(str_replace("\\", "/", dirname((string)($_SERVER["SCRIPT_NAME"] ?? "/"))), "/") . "/";
    $https = (!empty($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off") || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
    setcookie("aparelho", $apagar ? "" : (string)$chave, ["expires" => $apagar ? time() - 86400 : time() + 365 * 86400, "path" => $pasta,
        "secure" => $https, "httponly" => true, "samesite" => "Lax"]);
}

// O usuário dono da chave de um aparelho lembrado (o cookie), ou "" (chave desconhecida, conta bloqueada ou vencida)
function usuario_por_aparelho($chave)
{
    if (trim((string)$chave) === "" || !banco_tem_tabela("usuario_aparelho")) {
        return "";
    }
    return sem_escopo(function () use ($chave) {
        $u = linha("SELECT u.*, a.id AS aparelho_id, a.ultimo_uso FROM usuario_aparelho a JOIN usuario u ON u.id = a.usuario_id WHERE a.chave_hash = ?", [token_hash(trim((string)$chave))]);
        if ($u === null || (int)$u["bloqueado"] === 1 || ($u["expira_em"] !== null && strtotime((string)$u["expira_em"]) <= time())) {
            return "";
        }
        if ($u["ultimo_uso"] === null || strtotime((string)$u["ultimo_uso"]) < time() - 60) {
            sql("UPDATE usuario_aparelho SET ultimo_uso = NOW() WHERE id = ?", [(int)$u["aparelho_id"]]);
        }
        $id_a = (int)$u["aparelho_id"];
        unset($u["aparelho_id"], $u["ultimo_uso"]);
        $u["ultimo_acesso"] = $u["ultimo_acesso"] ?? null;
        usuario_definir($u);
        $GLOBALS["APARELHO_ATUAL"] = $id_a;
        return (string)$u["login"];
    });
}

// O pedido entrou pelo login do site, mas trouxe o cookie de um aparelho lembrado do mesmo usuário: marca qual é (a tabela
// de Minha conta diz "este"; o Esquecer sem id apaga ele), e conta o uso
function aparelho_deste_pedido($uid)
{
    if (!isset($_COOKIE["aparelho"]) || trim((string)$_COOKIE["aparelho"]) === "" || !banco_tem_tabela("usuario_aparelho")) {
        return;
    }
    sem_escopo(function () use ($uid) {
        $a = linha("SELECT id, ultimo_uso FROM usuario_aparelho WHERE chave_hash = ? AND usuario_id = ?", [token_hash(trim((string)$_COOKIE["aparelho"])), (int)$uid]);
        if ($a !== null) {
            if ($a["ultimo_uso"] === null || strtotime((string)$a["ultimo_uso"]) < time() - 60) {
                sql("UPDATE usuario_aparelho SET ultimo_uso = NOW() WHERE id = ?", [(int)$a["id"]]);
            }
            $GLOBALS["APARELHO_ATUAL"] = (int)$a["id"];
        }
    });
}

// A chave que chegou na URL (o start_url do aplicativo instalado, index.php?aparelho=...): válida, vira o cookie; de um
// jeito ou de outro a página abre de novo sem ela (a chave não fica na barra de endereço nem no histórico)
function aparelho_pela_url($chave)
{
    if (usuario_por_aparelho($chave) !== "") {
        aparelho_cookie($chave);
    }
    $uri = (string)($_SERVER["REQUEST_URI"] ?? "index.php");
    $limpa = preg_replace("/([?&])aparelho=[^&#]*&?/", "$1", $uri);
    $limpa = rtrim((string)$limpa, "?&");
    header("Location: " . ($limpa !== "" ? $limpa : "index.php"));
    exit;
}

// O id do aparelho lembrado por que este pedido entrou (o cookie), ou null
function aparelho_atual()
{
    return $GLOBALS["APARELHO_ATUAL"] ?? null;
}

// O login de uma conta nova: até 60 caracteres, sem dois-pontos (o HTTP Basic separa o login da senha no primeiro) nem
// espaços nas pontas. Devolve o erro, ou ""
function login_erro($login)
{
    if ($login === "" || mb_strlen($login, "UTF-8") > 60) {
        return t("Informe o login (até 60 caracteres).");
    }
    if (strpos($login, ":") !== false || preg_match("/[\\x00-\\x1f]/", $login) === 1 || trim($login) !== $login) {
        return t("O login não pode ter dois-pontos, caracteres de controle nem espaços nas pontas.");
    }
    return "";
}

// Um usuário como a API mostra (sem o hash da senha)
function usuario_publico($u)
{
    $exp = $u["expira_em"] ?? null;
    return ["id" => (int)$u["id"], "login" => $u["login"], "email" => $u["email"] ?? null, "papel" => $u["papel"], "idioma" => $u["idioma"] ?? null, "fuso" => $u["fuso"] ?? null,
        "criado" => $u["criado"], "ultimo_acesso" => $u["ultimo_acesso"] ?? null, "expira_em" => $exp, "demo" => $exp !== null,
        "dias_restantes" => $exp !== null ? max(0, (int)ceil((strtotime((string)$exp) - time()) / 86400)) : null, "bloqueado" => (int)($u["bloqueado"] ?? 0) === 1];
}

function usuario_por_id($id)
{
    return sem_escopo(function () use ($id) { return linha("SELECT * FROM usuario WHERE id = ?", [(int)$id]); });
}

// O usuário pelo id, ou pelo login (o painel do master e os scripts citam um ou outro)
function usuario_do_pedido($d)
{
    if (isset($d["usuario_id"]) && (int)$d["usuario_id"] > 0) {
        return usuario_por_id((int)$d["usuario_id"]);
    }
    $login = trim((string)($d["login"] ?? ""));
    return $login === "" ? null : sem_escopo(function () use ($login) { return linha("SELECT * FROM usuario WHERE login = ?", [$login]); });
}

function colecao_por_id($id)
{
    return sem_escopo(function () use ($id) { return linha("SELECT c.*, u.login AS dono FROM colecao c JOIN usuario u ON u.id = c.dono_id WHERE c.id = ?", [(int)$id]); });
}

// Um e-mail no idioma de quem recebe ($assunto e $texto marcados com tk(), com as {n} de $vars). [ok, erro]
function email_para($email, $lingua, $assunto, $texto, $vars = [])
{
    if (trim((string)$email) === "") {
        return [false, t("sem e-mail")];
    }
    if (email_metodo() === null) {
        return [false, t("o envio de e-mail não está configurado no servidor (EMAIL_METODO ou SMTP_HOST no config.php)")];
    }
    $antes = lingua_atual();
    lingua_definir(lingua_valida($lingua) ?? (lingua_valida(sis("idioma")) ?? $antes));
    $r = email_enviar((string)$email, t($assunto, $vars), t($texto, $vars));
    lingua_definir($antes);
    return $r;
}

// O alvo de um convite ou de um pedido, por extenso: "a coleção X (de fulano)" ou "todas as coleções de fulano"
function alvo_texto($colecao_id, $dono_id)
{
    if ($colecao_id !== null) {
        $c = colecao_por_id((int)$colecao_id);
        return $c ? t("a coleção {1} (de {2})", [1 => $c["nome"], 2 => $c["dono"]]) : t("uma coleção que não existe mais");
    }
    $u = usuario_por_id((int)$dono_id);
    return $u ? t("todas as coleções de {1}", [1 => $u["login"]]) : t("um dono que não existe mais");
}

// O alvo de um pedido ainda não aprovado, para quem pediu: só o código que ele digitou ("a coleção de código 7", "todas as
// coleções do dono de código 3"). O nome da coleção e o login do dono só aparecem depois da aprovação: quem tenta códigos ao
// acaso não descobre quem tem conta
function alvo_codigo($colecao_id, $dono_id)
{
    return $colecao_id !== null ? t("a coleção de código {1}", [1 => (int)$colecao_id]) : t("todas as coleções do dono de código {1}", [1 => (int)$dono_id]);
}

// Quem cuida de um alvo (aprova o pedido, o convite de um membro): o dono, e o master. Confere o usuário do pedido
function cuida_do_alvo($colecao_id, $dono_id)
{
    $u = usuario_atual();
    if ($u === null) {
        return false;
    }
    if (usuario_master()) {
        return true;
    }
    if ($colecao_id !== null) {
        $c = colecao_por_id((int)$colecao_id);
        return $c !== null && (int)$c["dono_id"] === (int)$u["id"];
    }
    return (int)$dono_id === (int)$u["id"];
}

// Se o usuário já vê o alvo (é o dono, membro, ou tem tudo do dono)
function ja_ve_alvo($uid, $colecao_id, $dono_id)
{
    return sem_escopo(function () use ($uid, $colecao_id, $dono_id) {
        if ($colecao_id !== null) {
            return valor("SELECT 1 FROM colecao c WHERE c.id = ? AND (c.dono_id = ? OR EXISTS (SELECT 1 FROM colecao_usuario m WHERE m.colecao_id = c.id AND m.usuario_id = ?)
                OR EXISTS (SELECT 1 FROM acesso_dono a WHERE a.dono_id = c.dono_id AND a.usuario_id = ?))", [(int)$colecao_id, (int)$uid, (int)$uid, (int)$uid]) !== null;
        }
        return (int)$dono_id === (int)$uid || valor("SELECT 1 FROM acesso_dono WHERE dono_id = ? AND usuario_id = ?", [(int)$dono_id, (int)$uid]) !== null;
    });
}

// Dá o acesso: membro da coleção, ou tudo do dono (o que já tinha fica)
function acesso_dar($uid, $colecao_id, $dono_id)
{
    if (ja_ve_alvo($uid, $colecao_id, $dono_id)) {
        return;
    }
    sem_escopo(function () use ($uid, $colecao_id, $dono_id) {
        if ($colecao_id !== null) {
            sql("INSERT INTO colecao_usuario (colecao_id, usuario_id, desde) VALUES (?, ?, NOW())", [(int)$colecao_id, (int)$uid]);
        } else {
            sql("INSERT INTO acesso_dono (dono_id, usuario_id, desde) VALUES (?, ?, NOW())", [(int)$dono_id, (int)$uid]);
        }
    });
}

// O alvo: colecao_id (uma coleção) ou dono_id (todas as coleções de um dono), um ou outro; para o master e para quem cuida das
// próprias coleções (convites, acessos), o dono também pelo login: dono=<login>. Num pedido de acesso ($so_codigo: o pedido e o
// cadastro), só pelo código (a chave) que o dono passou, nunca pelo login, e um erro só para o código que não serve: nada no
// pedido diz quem tem conta. [colecao_id, dono_id, erro]
function alvo_do_pedido($d, $so_codigo = false)
{
    $c = (int)($d["colecao_id"] ?? 0);
    $dono = (int)($d["dono_id"] ?? 0);
    if (!$so_codigo && $dono === 0 && trim((string)($d["dono"] ?? "")) !== "") {
        $dono = (int)sem_escopo(function () use ($d) { return valor("SELECT id FROM usuario WHERE login = ?", [trim((string)$d["dono"])]); });
    }
    if (($c > 0) === ($dono > 0)) {
        return [null, null, $so_codigo ? t("Diga o código: colecao_id (o código de uma coleção) ou dono_id (o código de um dono, para todas as coleções dele), um ou outro.")
            : t("Diga o alvo: colecao_id (uma coleção) ou dono_id (todas as coleções de um dono), um ou outro.")];
    }
    if ($so_codigo && (($c > 0 && colecao_por_id($c) === null) || ($dono > 0 && (int)sem_escopo(function () use ($dono) { return valor("SELECT COUNT(*) FROM colecao WHERE dono_id = ?", [$dono]); }) === 0))) {
        return [null, null, t("Nenhuma coleção com este código: confira o código que o dono passou.")];
    }
    if ($c > 0 && colecao_por_id($c) === null) {
        return [null, null, t("Coleção não encontrada.")];
    }
    if ($dono > 0 && usuario_por_id($dono) === null) {
        return [null, null, t("Dono não encontrado.")];
    }
    return [$c > 0 ? $c : null, $dono > 0 ? $dono : null, ""];
}

// ---------------------------------------------------------------------------------------------------------------------
// As árvores: copiar uma árvore inteira (os grupos e o cadastro dela: campos, opções, tipos, fórmulas, avisos, categorias e
// os critérios dos grupos) para um dono. Devolve [nova raiz, mapas: tabela => [id antigo => id novo]]. Sem escopo
// ---------------------------------------------------------------------------------------------------------------------
function linha_copiar($tabela, $linha, $troca)
{
    unset($linha["id"]);
    foreach ($troca as $k => $v) {
        $linha[$k] = $v;
    }
    $cols = array_keys($linha);
    sql("INSERT INTO " . banco_tabela_sql($tabela) . " (" . implode(", ", $cols) . ") VALUES (" . implode(", ", array_fill(0, count($cols), "?")) . ")", array_values($linha));
    return ultimo_id();
}

function arvore_copiar($raiz, $dono_id, $nome)
{
    return sem_escopo(function () use ($raiz, $dono_id, $nome) {
        $m = ["no" => []];
        $r = linha("SELECT * FROM no WHERE id = ?", [(int)$raiz]);
        $m["no"][(int)$raiz] = linha_copiar("no", $r, ["dono_id" => (int)$dono_id, "pai_id" => null, "nome" => corta_texto($nome, 80)]);
        $fila = [(int)$raiz];
        while (count($fila) > 0) {
            $pai = array_shift($fila);
            foreach (linhas("SELECT * FROM no WHERE pai_id = ? ORDER BY id", [$pai]) as $n) {
                $m["no"][(int)$n["id"]] = linha_copiar("no", $n, ["pai_id" => $m["no"][$pai]]);
                $fila[] = (int)$n["id"];
            }
        }
        $nos = implode(", ", array_keys($m["no"]));
        foreach (["campo", "lancamento_tipo", "formula", "documento_categoria"] as $t) {
            $m[$t] = [];
            foreach (linhas("SELECT * FROM " . $t . " WHERE no_id IN (" . $nos . ") ORDER BY id") as $x) {
                $m[$t][(int)$x["id"]] = linha_copiar($t, $x, ["no_id" => $m["no"][(int)$x["no_id"]]]);
            }
        }
        $m["aviso"] = [];
        foreach (linhas("SELECT * FROM aviso WHERE no_id IN (" . $nos . ") ORDER BY id") as $x) {
            $m["aviso"][(int)$x["id"]] = linha_copiar("aviso", $x, ["no_id" => $m["no"][(int)$x["no_id"]],
                "resolve_tipo_id" => $x["resolve_tipo_id"] !== null ? ($m["lancamento_tipo"][(int)$x["resolve_tipo_id"]] ?? null) : null]);
        }
        foreach ($m["campo"] as $de => $para) {
            foreach (linhas("SELECT * FROM campo_opcao WHERE campo_id = ?", [$de]) as $x) {
                linha_copiar("campo_opcao", $x, ["campo_id" => $para]);
            }
        }
        foreach ($m["documento_categoria"] as $de => $para) {
            foreach (linhas("SELECT * FROM documento_categoria_aceita WHERE categoria_id = ?", [$de]) as $x) {
                linha_copiar("documento_categoria_aceita", $x, ["categoria_id" => $para]);
            }
        }
        $m["criterio_parametro"] = [];
        foreach (linhas("SELECT * FROM criterio_parametro WHERE escopo_no_id IN (" . $nos . ") ORDER BY id") as $p) {
            $pid = linha_copiar("criterio_parametro", $p, ["escopo_no_id" => $m["no"][(int)$p["escopo_no_id"]]]);
            $m["criterio_parametro"][(int)$p["id"]] = $pid;
            foreach (linhas("SELECT * FROM criterio_sub WHERE parametro_id = ? ORDER BY id", [(int)$p["id"]]) as $s) {
                $sid = linha_copiar("criterio_sub", $s, ["parametro_id" => $pid]);
                foreach (linhas("SELECT * FROM criterio_faixa WHERE sub_id = ? ORDER BY id", [(int)$s["id"]]) as $f) {
                    linha_copiar("criterio_faixa", $f, ["sub_id" => $sid]);
                }
            }
        }
        return [$m["no"][(int)$raiz], $m];
    });
}

// As árvores de um dono: [{id (a raiz), nome, grupos, colecoes: [{id, nome}]}]
function arvores_do_dono($dono_id)
{
    return sem_escopo(function () use ($dono_id) {
        $res = [];
        foreach (linhas("SELECT id, nome FROM no WHERE pai_id IS NULL AND dono_id = ? ORDER BY nome, id", [(int)$dono_id]) as $r) {
            $res[] = ["id" => (int)$r["id"], "nome" => $r["nome"], "grupos" => count(arvore_ids((int)$r["id"])) - 1,
                "colecoes" => array_map(function ($c) { return ["id" => (int)$c["id"], "nome" => $c["nome"]]; },
                    linhas("SELECT id, nome FROM colecao WHERE no_id = ? ORDER BY nome", [(int)$r["id"]]))];
        }
        return $res;
    });
}

// Os ids de uma árvore inteira (a raiz e todos os grupos abaixo dela)
function arvore_ids($raiz)
{
    return sem_escopo(function () use ($raiz) {
        $ids = [(int)$raiz];
        $fila = [(int)$raiz];
        while (count($fila) > 0) {
            $pai = array_shift($fila);
            foreach (linhas("SELECT id FROM no WHERE pai_id = ?", [$pai]) as $n) {
                $ids[] = (int)$n["id"];
                $fila[] = (int)$n["id"];
            }
        }
        return $ids;
    });
}

// Passa uma coleção para outro dono. A árvore vai junto quando só ela a usa; quando o antigo dono a usa em outras coleções,
// o novo dono ganha uma cópia dela, e os relógios, os valores, os lançamentos, os documentos e os blocos dos modos da coleção
// passam para os grupos, os campos, os tipos e as categorias da cópia. Quem era membro continua; o novo dono deixa de ser
// membro (passa a ser o dono). Devolve o erro, ou ""
function colecao_transferir($cid, $novo_dono)
{
    $c = colecao_por_id($cid);
    $u = usuario_por_id($novo_dono);
    if ($c === null || $u === null) {
        return t("Coleção ou usuário não encontrado.");
    }
    if ((int)$c["dono_id"] === (int)$u["id"]) {
        return t("{1} já é o dono desta coleção.", [1 => $u["login"]]);
    }
    return sem_escopo(function () use ($c, $u) {
        if (valor("SELECT id FROM colecao WHERE dono_id = ? AND nome = ?", [(int)$u["id"], $c["nome"]]) !== null) {
            return t("{1} já tem uma coleção chamada {2}: renomeie uma delas antes.", [1 => $u["login"], 2 => $c["nome"]]);
        }
        $cid = (int)$c["id"];
        $outras = (int)valor("SELECT COUNT(*) FROM colecao WHERE no_id = ? AND id <> ?", [(int)$c["no_id"], $cid]);
        if ($outras === 0) {
            sql("UPDATE no SET dono_id = ? WHERE id = ?", [(int)$u["id"], (int)$c["no_id"]]);
        } else {
            [$raiz, $m] = arvore_copiar((int)$c["no_id"], (int)$u["id"], (string)valor("SELECT nome FROM no WHERE id = ?", [(int)$c["no_id"]]));
            $rel = "SELECT id FROM relogio WHERE colecao_id = " . $cid;
            $trocas = [["relogio", "no_id", "no", "colecao_id = " . $cid], ["campo_valor", "campo_id", "campo", "relogio_id IN (" . $rel . ")"],
                ["lancamento", "tipo_id", "lancamento_tipo", "relogio_id IN (" . $rel . ")"], ["documento", "categoria_id", "documento_categoria", "relogio_id IN (" . $rel . ")"],
                ["modo_bloco", "no_id", "no", "modo_id IN (SELECT id FROM modo WHERE colecao_id = " . $cid . ")"]];
            foreach ($trocas as [$t, $col, $mapa, $onde]) {
                // de um em um, pelos ids antigos que a coleção usa (os novos são maiores que todos os antigos: não se cruzam)
                foreach (array_map("intval", array_column(linhas("SELECT DISTINCT " . $col . " AS x FROM " . $t . " WHERE " . $onde . " AND " . $col . " IS NOT NULL"), "x")) as $de) {
                    if (isset($m[$mapa][$de])) {
                        sql("UPDATE " . $t . " SET " . $col . " = ? WHERE " . $col . " = ? AND " . $onde, [$m[$mapa][$de], $de]);
                    }
                }
            }
            sql("UPDATE colecao SET no_id = ? WHERE id = ?", [$raiz, $cid]);
        }
        sql("UPDATE colecao SET dono_id = ? WHERE id = ?", [(int)$u["id"], $cid]);
        sql("DELETE FROM colecao_usuario WHERE colecao_id = ? AND usuario_id = ?", [$cid, (int)$u["id"]]);
        return "";
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// Consultas
// ---------------------------------------------------------------------------------------------------------------------

// Minha conta (recurso=conta): quem eu sou, as coleções que vejo (e a do pedido), as minhas árvores, os pedidos e convites
// que esperam, quantas aprovações esperam por mim, os meus tokens de API e os meus aparelhos do Web Push
function conta_consulta()
{
    $u = usuario_atual();
    if ($u === null) {
        return ["usuario" => null, "colecoes" => [], "colecao" => null];
    }
    $uid = (int)$u["id"];
    return sem_escopo(function () use ($u, $uid) {
        $real = $GLOBALS["USUARIO_REAL"] ?? null;
        return ["usuario" => usuario_publico($u), "vendo_como" => $real !== null ? ["login" => $u["login"], "master" => $real["login"]] : null,
            "colecoes" => colecoes_visiveis($u), "colecao" => colecao_atual(), "papel" => papel_na_colecao(),
            "arvores" => arvores_do_dono($uid),
            "pedidos" => array_map(function ($p) { return ["id" => (int)$p["id"], "colecao_id" => $p["colecao_id"] !== null ? (int)$p["colecao_id"] : null,
                "dono_id" => $p["dono_id"] !== null ? (int)$p["dono_id"] : null, "alvo" => alvo_codigo($p["colecao_id"], $p["dono_id"]), "criado" => $p["criado"], "expira" => $p["expira"]]; },
                linhas("SELECT * FROM pedido WHERE usuario_id = ? ORDER BY criado", [$uid])),
            "convites" => array_map(function ($v) { return ["id" => (int)$v["id"], "alvo" => alvo_texto($v["colecao_id"], $v["dono_id"]), "expira" => $v["expira"]]; },
                (string)$u["email"] !== "" ? linhas("SELECT * FROM convite WHERE email = ? AND estado = 'aprovado' ORDER BY criado", [$u["email"]]) : []),
            "aprovacoes" => count(aprovacoes_pedidos()) + count(aprovacoes_convites()),
            "tokens" => array_map(function ($t) { return ["id" => (int)$t["id"], "nome" => $t["nome"], "criado" => $t["criado"], "ultimo_uso" => $t["ultimo_uso"]]; },
                linhas("SELECT id, nome, criado, ultimo_uso FROM api_token WHERE usuario_id = ? ORDER BY criado", [$uid])),
            // os aparelhos lembrados (o aplicativo no celular), com qual é o deste pedido
            "aparelhos" => array_map(function ($a) { return ["id" => (int)$a["id"], "nome" => $a["nome"], "criado" => $a["criado"], "ultimo_uso" => $a["ultimo_uso"], "este" => (int)$a["id"] === aparelho_atual()]; },
                linhas("SELECT id, nome, criado, ultimo_uso FROM usuario_aparelho WHERE usuario_id = ? ORDER BY criado, id", [$uid])),
            "push" => ["configurado" => vapid_configurado(), "chave_publica" => vapid_configurado() ? cfg_php("VAPID_PUBLICA", "") : null,
                "aparelhos" => array_map(function ($p) { return ["id" => (int)$p["id"], "aparelho" => $p["aparelho"], "criada" => $p["criada"], "endpoint_hash" => $p["endpoint_hash"]]; },
                    linhas("SELECT id, aparelho, criada, endpoint_hash FROM push_inscricao WHERE usuario_id = ? ORDER BY criada", [$uid]))],
            "email_configurado" => email_metodo() !== null,
            // a configuração do e-mail lida do config.php, para o master testar (as senhas só como definida ou não)
            "email" => usuario_master() ? email_config_resumo() : null,
            "demo_dias" => demo_dias(), "linguas" => array_map(function ($k) { return ["codigo" => $k, "nome" => LINGUAS[$k]["nome"]]; }, array_keys(LINGUAS)),
            // o fuso em vigor neste pedido (e de onde veio), o do sistema e a lista para escolher
            "fuso" => ["nome" => fuso_atual(), "origem" => fuso_origem(), "sistema" => fuso_sistema(), "colecao" => colecao_atual() !== null ? (fuso_valido(cfg("fuso")) ?? null) : null],
            "fusos" => fusos_lista()];
    });
}

// Os pedidos de acesso que o usuário do pedido aprova (os das coleções dele e os de tudo dele; o master, todos)
function aprovacoes_pedidos()
{
    $u = usuario_atual();
    if ($u === null) {
        return [];
    }
    return sem_escopo(function () use ($u) {
        $todos = linhas("SELECT p.*, u.login, u.email, c.dono_id AS c_dono FROM pedido p JOIN usuario u ON u.id = p.usuario_id LEFT JOIN colecao c ON c.id = p.colecao_id ORDER BY p.criado");
        $res = [];
        foreach ($todos as $p) {
            $dono = $p["colecao_id"] !== null ? (int)$p["c_dono"] : (int)$p["dono_id"];
            if ($u["papel"] === "master" || $dono === (int)$u["id"]) {
                $res[] = ["id" => (int)$p["id"], "login" => $p["login"], "email" => $p["email"], "colecao_id" => $p["colecao_id"] !== null ? (int)$p["colecao_id"] : null,
                    "dono_id" => $p["dono_id"] !== null ? (int)$p["dono_id"] : null, "alvo" => alvo_texto($p["colecao_id"], $p["dono_id"]), "mensagem" => $p["mensagem"],
                    "criado" => $p["criado"], "expira" => $p["expira"]];
            }
        }
        return $res;
    });
}

// Os convites que um membro fez e esperam o dono (o master vê todos)
function aprovacoes_convites()
{
    $u = usuario_atual();
    if ($u === null) {
        return [];
    }
    return array_values(array_filter(convites_lista("pendente"), function ($v) { return cuida_do_alvo($v["colecao_id"], $v["dono_id"]); }));
}

function convites_lista($estado = null)
{
    return sem_escopo(function () use ($estado) {
        return array_map(function ($v) {
            return ["id" => (int)$v["id"], "email" => $v["email"], "por" => $v["login"], "por_id" => (int)$v["convidado_por"], "estado" => $v["estado"],
                "colecao_id" => $v["colecao_id"] !== null ? (int)$v["colecao_id"] : null, "dono_id" => $v["dono_id"] !== null ? (int)$v["dono_id"] : null,
                "alvo" => alvo_texto($v["colecao_id"], $v["dono_id"]), "criado" => $v["criado"], "expira" => $v["expira"]];
        }, linhas("SELECT v.*, u.login FROM convite v JOIN usuario u ON u.id = v.convidado_por" . ($estado !== null ? " WHERE v.estado = ?" : "") . " ORDER BY v.criado", $estado !== null ? [$estado] : []));
    });
}

// Aprovações (recurso=aprovacoes): os pedidos que eu aprovo, os convites de membros que esperam por mim, os convites que eu
// fiz, e quem vê as minhas coleções (os membros de cada uma e quem tem tudo meu), para tirar o acesso
function aprovacoes_consulta()
{
    $u = usuario_atual();
    $uid = (int)$u["id"];
    return sem_escopo(function () use ($u, $uid) {
        $minhas = linhas("SELECT id, nome FROM colecao WHERE dono_id = ? ORDER BY nome", [$uid]);
        return ["pedidos" => aprovacoes_pedidos(), "convites_a_aprovar" => aprovacoes_convites(),
            "convites_feitos" => array_values(array_filter(convites_lista(), function ($v) use ($uid) { return $v["por_id"] === $uid; })),
            "acessos" => ["colecoes" => array_map(function ($c) {
                return ["id" => (int)$c["id"], "nome" => $c["nome"], "membros" => array_map(function ($m) { return ["usuario_id" => (int)$m["id"], "login" => $m["login"], "desde" => $m["desde"]]; },
                    linhas("SELECT u.id, u.login, m.desde FROM colecao_usuario m JOIN usuario u ON u.id = m.usuario_id WHERE m.colecao_id = ? ORDER BY u.login", [(int)$c["id"]]))];
            }, $minhas), "tudo" => array_map(function ($m) { return ["usuario_id" => (int)$m["id"], "login" => $m["login"], "desde" => $m["desde"]]; },
                linhas("SELECT u.id, u.login, a.desde FROM acesso_dono a JOIN usuario u ON u.id = a.usuario_id WHERE a.dono_id = ? ORDER BY u.login", [$uid]))],
            "minhas_colecoes" => array_map(function ($c) { return ["id" => (int)$c["id"], "nome" => $c["nome"]]; }, $minhas),
            "pode_convidar" => array_map(function ($c) { return ["id" => $c["id"], "nome" => $c["nome"], "dono" => $c["dono"], "papel" => $c["papel"]]; },
                array_values(array_filter(colecoes_visiveis($u), function ($c) { return $c["papel"] !== "master" || usuario_master(); }))),
            "email_configurado" => email_metodo() !== null, "convite_dias" => convite_dias()];
    });
}

// O cadastro aberto (recurso=cadastro, sem login): se está aberto, os dias da demo e os idiomas; com convite=<token>, o
// convite (o alvo e o e-mail). Nenhuma lista de contas nem de coleções: quem pede acesso digita o código que o dono passou
function cadastro_consulta($convite)
{
    return sem_escopo(function () use ($convite) {
        $res = ["aberto" => cadastro_aberto(), "demo_dias" => demo_dias(), "email_configurado" => email_metodo() !== null,
            "linguas" => array_map(function ($k) { return ["codigo" => $k, "nome" => LINGUAS[$k]["nome"]]; }, array_keys(LINGUAS)),
            "convite" => null];
        if ($convite !== null && $convite !== "") {
            $v = linha("SELECT * FROM convite WHERE token_hash = ? AND estado = 'aprovado' AND expira > ?", [token_hash($convite), date("Y-m-d H:i:s")]);
            $res["convite"] = $v ? ["email" => $v["email"], "alvo" => alvo_texto($v["colecao_id"], $v["dono_id"]), "expira" => $v["expira"],
                "tem_conta" => valor("SELECT id FROM usuario WHERE email = ?", [$v["email"]]) !== null] : false;
        }
        return $res;
    });
}

// O painel do master (recurso=usuarios, para o master): todos os usuários, com o que cada um tem
function usuarios_painel()
{
    return sem_escopo(function () {
        return array_map(function ($u) {
            $info = senha_hash_info((string)$u["senha_hash"]);
            $id = (int)$u["id"];
            return array_merge(usuario_publico($u), ["senha_hash" => $u["senha_hash"], "senha_algoritmo" => $info[0], "senha_custo" => $info[1],
                "colecoes" => array_map(function ($c) { return ["id" => (int)$c["id"], "nome" => $c["nome"], "relogios" => (int)$c["n"]]; },
                    linhas("SELECT c.id, c.nome, (SELECT COUNT(*) FROM relogio r WHERE r.colecao_id = c.id) AS n FROM colecao c WHERE c.dono_id = ? ORDER BY c.nome", [$id])),
                "membro_de" => array_map(function ($c) { return ["id" => (int)$c["id"], "nome" => $c["nome"], "dono" => $c["dono"]]; },
                    linhas("SELECT c.id, c.nome, d.login AS dono FROM colecao_usuario m JOIN colecao c ON c.id = m.colecao_id JOIN usuario d ON d.id = c.dono_id WHERE m.usuario_id = ? ORDER BY c.nome", [$id])),
                "tudo_de" => array_column(linhas("SELECT d.login FROM acesso_dono a JOIN usuario d ON d.id = a.dono_id WHERE a.usuario_id = ? ORDER BY d.login", [$id]), "login"),
                "pedidos" => (int)valor("SELECT COUNT(*) FROM pedido WHERE usuario_id = ?", [$id]), "tokens" => (int)valor("SELECT COUNT(*) FROM api_token WHERE usuario_id = ?", [$id])]);
        }, linhas("SELECT * FROM usuario ORDER BY CASE WHEN papel = 'master' THEN 0 ELSE 1 END, login"));
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// Escritas
// ---------------------------------------------------------------------------------------------------------------------

// recurso=cadastro (sem login): criar_conta, esqueci_senha, nova_senha
function op_cadastro($acao, $d)
{
    $erros = [];
    $msg = "";
    $extra = [];
    if ($acao === "criar_conta") {
        $login = (string)($d["login"] ?? "");
        $senha = (string)($d["senha"] ?? "");
        $email = trim((string)($d["email"] ?? ""));
        $lingua = lingua_valida($d["idioma"] ?? null) ?? lingua_atual();
        $opcao = (string)($d["opcao"] ?? "modelo");
        $convite = trim((string)($d["convite"] ?? ""));
        $v = $convite !== "" ? sem_escopo(function () use ($convite) {
            return linha("SELECT * FROM convite WHERE token_hash = ? AND estado = 'aprovado' AND expira > ?", [token_hash($convite), date("Y-m-d H:i:s")]);
        }) : null;
        $alvo = [null, null, ""];
        if ($convite !== "" && $v === null) {
            $erros[] = t("Convite desconhecido ou vencido.");
        } elseif ($v === null && !cadastro_aberto()) {
            $erros[] = t("O cadastro está fechado: só entra quem tem um convite.");
        }
        if (($e = login_erro($login)) !== "") {
            $erros[] = $e;
        }
        if (strlen($senha) < 6) {
            $erros[] = t("Informe uma senha de pelo menos 6 caracteres (ou o senha_hash de outro sistema).");
        }
        if ($email === "" || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = t("Informe um e-mail válido (para recuperar a senha e receber os avisos da conta).");
        }
        if (!in_array($opcao, ["modelo", "vazia", "pedir", "nenhuma"], true)) {
            $erros[] = t("opcao: modelo (uma coleção com o conjunto inicial), vazia (uma coleção sem nada) ou pedir (pedir acesso a uma coleção ou a tudo de um dono).");
        } elseif ($opcao === "pedir" && $v === null) {
            $alvo = alvo_do_pedido($d, true);
            if ($alvo[2] !== "") {
                $erros[] = $alvo[2];
            }
        } elseif ($opcao === "nenhuma" && $v === null) {
            $erros[] = t("Sem convite, a conta nova precisa de uma coleção (modelo ou vazia) ou de um pedido de acesso (pedir).");
        }
        if (count($erros) === 0 && sem_escopo(function () use ($login) { return valor("SELECT id FROM usuario WHERE login = ?", [$login]); }) !== null) {
            $erros[] = t("Este login já existe: escolha outro.");
        }
        if (count($erros) === 0 && sem_escopo(function () use ($email) { return valor("SELECT id FROM usuario WHERE email = ?", [$email]); }) !== null) {
            $erros[] = t("Este e-mail já tem conta: entre com ela, ou use o Esqueci a senha.");
        }
        if (count($erros) === 0) {
            $dias = demo_dias();
            $expira = $dias > 0 ? date("Y-m-d H:i:s", time() + $dias * 86400) : null;
            $r = conta_criar($login, password_hash($senha, PASSWORD_DEFAULT), $email, $expira, $lingua, in_array($opcao, ["modelo", "vazia"], true) ? $opcao : "nenhuma");
            $uid = $r["usuario_id"];
            $msg = t("Conta {1} criada.", [1 => $login]);
            if ($v !== null) {
                acesso_dar($uid, $v["colecao_id"], $v["dono_id"]);
                sem_escopo(function () use ($v) { sql("DELETE FROM convite WHERE id = ?", [(int)$v["id"]]); });
                $msg .= " " . t("Você já vê {1}.", [1 => alvo_texto($v["colecao_id"], $v["dono_id"])]);
            } elseif ($opcao === "pedir") {
                pedido_criar($uid, $alvo[0], $alvo[1], (string)($d["mensagem"] ?? ""));
                $msg .= " " . t("Pedido de acesso enviado ({1}): quando for aprovado, a coleção aparece para você.", [1 => alvo_codigo($alvo[0], $alvo[1])]);
            }
            if ($expira !== null) {
                $msg .= " " . t("É uma conta de demonstração de {1} dias: para continuar depois disso, peça ao administrador para torná-la oficial.", [1 => $dias]);
            }
            email_para($email, $lingua, tk("Relojoeiro: a sua conta {1} foi criada"), tk("Olá, {1}. A sua conta no Relojoeiro foi criada. Entre em {2} com o seu login e a sua senha."),
                [1 => $login, 2 => url_do_sistema() . "/index.php"]);
            $extra = ["usuario_id" => $uid, "colecao_id" => $r["colecao_id"], "expira_em" => $expira];
        }
    } elseif ($acao === "esqueci_senha") {
        // sempre a mesma resposta (não diz se o e-mail ou o login existe)
        $quem = trim((string)($d["email"] ?? ($d["login"] ?? "")));
        if ($quem === "") {
            $erros[] = t("Informe o e-mail ou o login da conta.");
        } elseif (email_metodo() === null) {
            $erros[] = t("O servidor não envia e-mail: peça ao administrador para trocar a sua senha.");
        } else {
            $u = sem_escopo(function () use ($quem) { return linha("SELECT * FROM usuario WHERE email = ? OR login = ?", [$quem, $quem]); });
            if ($u && (string)$u["email"] !== "") {
                $tk_ = token_novo();
                sem_escopo(function () use ($u, $tk_) {
                    sql("DELETE FROM usuario_token WHERE usuario_id = ?", [(int)$u["id"]]);
                    sql("INSERT INTO usuario_token (usuario_id, token_hash, criado, expira) VALUES (?, ?, ?, ?)", [(int)$u["id"], token_hash($tk_), date("Y-m-d H:i:s"), date("Y-m-d H:i:s", time() + 7200)]);
                });
                email_para($u["email"], $u["idioma"], tk("Relojoeiro: trocar a senha de {1}"), tk("Olá, {1}. Para escolher uma senha nova, abra este link (vale por 2 horas): {2}. Se não foi você quem pediu, ignore este e-mail."),
                    [1 => $u["login"], 2 => url_do_sistema() . "/cadastro.php?senha=" . $tk_]);
            }
            $msg = t("Se a conta existe e tem e-mail, o link para trocar a senha foi enviado (vale por 2 horas).");
        }
    } elseif ($acao === "nova_senha") {
        $tk_ = trim((string)($d["token"] ?? ""));
        $senha = (string)($d["senha"] ?? "");
        $t_ = sem_escopo(function () use ($tk_) { return linha("SELECT * FROM usuario_token WHERE token_hash = ? AND expira > ?", [token_hash($tk_), date("Y-m-d H:i:s")]); });
        if ($t_ === null) {
            $erros[] = t("Link de senha desconhecido ou vencido: peça outro.");
        } elseif (strlen($senha) < 6) {
            $erros[] = t("Informe uma senha de pelo menos 6 caracteres (ou o senha_hash de outro sistema).");
        } else {
            sem_escopo(function () use ($t_, $senha) {
                sql("UPDATE usuario SET senha_hash = ? WHERE id = ?", [password_hash($senha, PASSWORD_DEFAULT), (int)$t_["usuario_id"]]);
                sql("DELETE FROM usuario_token WHERE usuario_id = ?", [(int)$t_["usuario_id"]]);
            });
            $msg = t("Senha trocada: entre com a senha nova.");
        }
    }
    return resultado($erros, $msg, $extra);
}

// Um pedido de acesso (e o aviso por e-mail ao dono). Devolve o id
function pedido_criar($uid, $colecao_id, $dono_id, $mensagem)
{
    $id = sem_escopo(function () use ($uid, $colecao_id, $dono_id, $mensagem) {
        sql("INSERT INTO pedido (usuario_id, colecao_id, dono_id, mensagem, criado, expira) VALUES (?, ?, ?, ?, ?, ?)",
            [(int)$uid, $colecao_id, $dono_id, trim($mensagem) !== "" ? corta_texto(trim($mensagem), 500) : null, date("Y-m-d H:i:s"), date("Y-m-d H:i:s", time() + convite_dias() * 86400)]);
        return ultimo_id();
    });
    $dono = $colecao_id !== null ? usuario_por_id((int)colecao_por_id($colecao_id)["dono_id"]) : usuario_por_id($dono_id);
    $quem = usuario_por_id($uid);
    if ($dono !== null && $quem !== null) {
        email_para($dono["email"], $dono["idioma"], tk("Relojoeiro: {1} pede acesso"), tk("{1} pede acesso ({2}). Aprove ou recuse em {3} (o pedido vence em {4} dias)."),
            [1 => $quem["login"], 2 => alvo_texto($colecao_id, $dono_id), 3 => url_do_sistema() . "/aprovacoes.php", 4 => convite_dias()]);
    }
    return $id;
}

// Manda (ou devolve) o link de um convite aprovado: um token novo a cada vez. Devolve [link, enviado]
function convite_mandar($convite_id)
{
    $tk_ = token_novo();
    $v = sem_escopo(function () use ($convite_id, $tk_) {
        sql("UPDATE convite SET token_hash = ? WHERE id = ?", [token_hash($tk_), (int)$convite_id]);
        return linha("SELECT v.*, u.login FROM convite v JOIN usuario u ON u.id = v.convidado_por WHERE v.id = ?", [(int)$convite_id]);
    });
    $link = url_do_sistema() . "/cadastro.php?convite=" . $tk_;
    [$ok] = email_para($v["email"], null, tk("Relojoeiro: {1} convida você"), tk("{1} convida você para ver {2} no Relojoeiro. Para aceitar, abra este link (vale até {3}): {4}"),
        [1 => $v["login"], 2 => alvo_texto($v["colecao_id"], $v["dono_id"]), 3 => data_txt(strtotime((string)$v["expira"]), true), 4 => $link]);
    return [$link, $ok];
}

// recurso=conta: alterar (email, idioma, senha_atual + senha), excluir (a própria conta, com confirmar=1), token_criar
// (nome), token_revogar (id), aparelho_lembrar (nome: o aplicativo no celular entra sem a senha por este aparelho; grava o
// cookie), aparelho_esquecer (id; vazio: este aparelho), push_inscrever (endpoint, p256dh, auth, aparelho), push_cancelar (id ou endpoint),
// push_testar, e, só o master: email_testar (para), vapid_gerar
function op_conta($acao, $d)
{
    $u = usuario_atual();
    if ($u === null) {
        return resultado([t("Entre com um usuário (o token do servidor não tem conta).")], "");
    }
    $uid = (int)$u["id"];
    $erros = [];
    $msg = "";
    $extra = [];
    if ($acao === "alterar") {
        $campos = [];
        if (array_key_exists("email", $d)) {
            $email = trim((string)$d["email"]);
            if ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $erros[] = t("E-mail inválido.");
            } elseif ($email !== "" && sem_escopo(function () use ($email, $uid) { return valor("SELECT id FROM usuario WHERE email = ? AND id <> ?", [$email, $uid]); }) !== null) {
                $erros[] = t("Este e-mail já é de outra conta.");
            } else {
                $campos["email"] = $email !== "" ? $email : null;
            }
        }
        if (array_key_exists("idioma", $d)) {
            $campos["idioma"] = lingua_valida($d["idioma"]);
        }
        // o fuso horário da pessoa (vazio: o da coleção, senão o do sistema)
        if (array_key_exists("fuso", $d)) {
            if (trim((string)$d["fuso"]) !== "" && trim((string)$d["fuso"]) !== "navegador" && fuso_valido($d["fuso"]) === null) {
                $erros[] = t("Fuso horário desconhecido: use um nome como America/Sao_Paulo ou Europe/Lisbon (a lista de Minha conta), navegador (o do aparelho) ou vazio (o da coleção).");
            } else {
                $campos["fuso"] = trim((string)$d["fuso"]) === "navegador" ? "navegador" : fuso_valido($d["fuso"]);
            }
        }
        if ((string)($d["senha"] ?? "") !== "") {
            if (!password_verify((string)($d["senha_atual"] ?? ""), (string)$u["senha_hash"]) && !isset($GLOBALS["USUARIO_REAL"])) {
                $erros[] = t("A senha atual não confere.");
            } elseif (strlen((string)$d["senha"]) < 6) {
                $erros[] = t("Informe uma senha de pelo menos 6 caracteres (ou o senha_hash de outro sistema).");
            } else {
                $campos["senha_hash"] = password_hash((string)$d["senha"], PASSWORD_DEFAULT);
            }
        }
        if (count($erros) === 0 && count($campos) > 0) {
            sem_escopo(function () use ($campos, $uid) {
                sql("UPDATE usuario SET " . implode(", ", array_map(function ($k) { return $k . " = ?"; }, array_keys($campos))) . " WHERE id = ?", array_merge(array_values($campos), [$uid]));
                // a senha nova desfaz os aparelhos lembrados: cada um entra de novo com ela (e lembra, se quiser)
                if (isset($campos["senha_hash"])) {
                    sql("DELETE FROM usuario_aparelho WHERE usuario_id = ?", [$uid]);
                }
            });
            if (isset($campos["senha_hash"]) && aparelho_atual() !== null) {
                aparelho_cookie("", true);
            }
            $msg = isset($campos["senha_hash"]) ? t("Conta atualizada. Os aparelhos lembrados foram esquecidos: entre de novo com a senha nova.") : t("Conta atualizada.");
        } elseif (count($erros) === 0) {
            $erros[] = t("Nada para mudar: mande email, idioma, fuso ou senha (com senha_atual).");
        }
    } elseif ($acao === "excluir") {
        if ($u["papel"] === "master" && (int)sem_escopo(function () { return valor("SELECT COUNT(*) FROM usuario WHERE papel = 'master'"); }) <= 1) {
            $erros[] = t("Precisa sobrar pelo menos um master.");
        } else {
            $n = usuario_excluir($uid);
            $msg = t("Conta {1} excluída, com tudo o que era dela{2}.", [1 => $u["login"], 2 => $n > 0 ? t(" ({1} arquivos)", [1 => $n]) : ""]);
        }
    } elseif ($acao === "token_criar") {
        $nome = trim((string)($d["nome"] ?? ""));
        if ($nome === "" || mb_strlen($nome, "UTF-8") > 80) {
            $erros[] = t("Dê um nome ao token (até 80 caracteres), para saber onde ele está em uso.");
        } else {
            $tk_ = token_novo();
            sem_escopo(function () use ($uid, $nome, $tk_) { sql("INSERT INTO api_token (usuario_id, nome, token_hash, criado) VALUES (?, ?, ?, NOW())", [$uid, $nome, token_hash($tk_)]); });
            $msg = t("Token {1} criado. Copie agora: ele não aparece de novo.", [1 => $nome]);
            $extra = ["token" => $tk_, "id" => ultimo_id()];
        }
    } elseif ($acao === "token_revogar") {
        $id = (int)($d["id"] ?? 0);
        if (sem_escopo(function () use ($id, $uid) { return valor("SELECT id FROM api_token WHERE id = ? AND usuario_id = ?", [$id, $uid]); }) === null) {
            $erros[] = t("Token não encontrado.");
        } else {
            sem_escopo(function () use ($id) { sql("DELETE FROM api_token WHERE id = ?", [$id]); });
            $msg = t("Token revogado: quem o usava perdeu o acesso.");
        }
    } elseif ($acao === "aparelho_lembrar") {
        // o aplicativo no celular: a chave deste aparelho (o cookie) e o hash dela no banco
        $nome = corta_texto(trim((string)($d["nome"] ?? ($_SERVER["HTTP_USER_AGENT"] ?? ""))), 200);
        $ch_ = token_novo();
        sem_escopo(function () use ($uid, $nome, $ch_) { sql("INSERT INTO usuario_aparelho (usuario_id, nome, chave_hash, criado) VALUES (?, ?, ?, NOW())", [$uid, $nome !== "" ? $nome : null, token_hash($ch_)]); });
        aparelho_cookie($ch_);
        $msg = t("Este aparelho fica lembrado: o aplicativo (e este navegador) entra sem pedir a senha. Para desfazer, Esquecer.");
        $extra = ["id" => ultimo_id()];
    } elseif ($acao === "aparelho_esquecer") {
        $id = (int)($d["id"] ?? 0);
        if ($id === 0) {
            $id = (int)(aparelho_atual() ?? 0);
        }
        if ($id === 0 || sem_escopo(function () use ($id, $uid) { return valor("SELECT id FROM usuario_aparelho WHERE id = ? AND usuario_id = ?", [$id, $uid]); }) === null) {
            $erros[] = t("Aparelho não encontrado.");
        } else {
            sem_escopo(function () use ($id) { sql("DELETE FROM usuario_aparelho WHERE id = ?", [$id]); });
            if ($id === (int)(aparelho_atual() ?? 0)) {
                aparelho_cookie("", true);
            }
            $msg = t("Aparelho esquecido: ele volta a pedir a senha.");
        }
    } elseif ($acao === "push_inscrever") {
        $endpoint = trim((string)($d["endpoint"] ?? ""));
        $p256 = trim((string)($d["p256dh"] ?? ""));
        $auth = trim((string)($d["auth"] ?? ""));
        if (preg_match("#^https://#", $endpoint) !== 1 || strlen($endpoint) > 1000 || strlen(b64url_ler($p256)) !== 65 || strlen(b64url_ler($auth)) !== 16) {
            $erros[] = t("Inscrição do Web Push inválida (endpoint https, p256dh e auth, como o navegador entrega).");
        } elseif (!vapid_configurado()) {
            $erros[] = t("o Web Push não está configurado (VAPID_PUBLICA e VAPID_PRIVADA no config.php)");
        } else {
            $h = hash("sha256", $endpoint);
            sem_escopo(function () use ($h, $uid, $endpoint, $p256, $auth, $d) {
                sql("DELETE FROM push_inscricao WHERE endpoint_hash = ?", [$h]);
                sql("INSERT INTO push_inscricao (usuario_id, endpoint, endpoint_hash, p256dh, auth, aparelho, criada) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$uid, $endpoint, $h, $p256, $auth, corta_texto(trim((string)($d["aparelho"] ?? "")), 200) ?: null]);
            });
            $msg = t("Este aparelho recebe as notificações.");
        }
    } elseif ($acao === "push_cancelar") {
        $id = (int)($d["id"] ?? 0);
        $h = trim((string)($d["endpoint"] ?? "")) !== "" ? hash("sha256", trim((string)$d["endpoint"])) : "";
        $n = sem_escopo(function () use ($id, $h, $uid) {
            $n = (int)valor("SELECT COUNT(*) FROM push_inscricao WHERE usuario_id = ? AND (id = ? OR endpoint_hash = ?)", [$uid, $id, $h]);
            sql("DELETE FROM push_inscricao WHERE usuario_id = ? AND (id = ? OR endpoint_hash = ?)", [$uid, $id, $h]);
            return $n;
        });
        if ($n > 0) {
            $msg = t("O aparelho não recebe mais as notificações.");
        } else {
            $erros[] = t("Aparelho não encontrado.");
        }
    } elseif ($acao === "push_testar") {
        [$ok, $erro] = webpush_enviar_usuario($uid, t("Relojoeiro"), t("Teste do Web Push: se você está lendo isto, as notificações chegam neste aparelho."));
        if ($ok) {
            $msg = t("Notificação de teste enviada.");
        } else {
            $erros[] = $erro;
        }
    } elseif ($acao === "email_testar") {
        // o servidor de e-mail do config.php (só o master): a conexão do SMTP, do IMAP e do POP, e um e-mail de teste (para:
        // o endereço; vazio, só as conexões). A resposta traz o teste inteiro: o resultado de cada parte, a conversa com os
        // servidores (sem as senhas) e a configuração lida
        $para = trim((string)($d["para"] ?? ""));
        if (!usuario_master()) {
            $erros[] = t("Só o master testa o servidor de e-mail.");
        } elseif (email_metodo() === null) {
            $erros[] = t("o envio de e-mail não está configurado no servidor (EMAIL_METODO ou SMTP_HOST no config.php)");
            $extra = ["teste" => ["metodo" => null, "remetente" => email_remetente(), "smtp" => null, "imap" => null, "pop" => null, "envio" => null, "registro" => [], "config" => email_config_resumo()]];
        } elseif ($para !== "" && filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = t("E-mail inválido.");
        } else {
            $r = email_testar($para);
            $extra = ["teste" => $r];
            $partes = [];
            foreach (["smtp" => "SMTP", "imap" => "IMAP", "pop" => "POP", "envio" => t("envio")] as $k => $nome) {
                if ($r[$k] !== null) {
                    $partes[] = $nome . ": " . ($r[$k]["ok"] ? t("ok") : t("erro ({1})", [1 => $r[$k]["erro"]]));
                }
            }
            $falhou = count(array_filter([$r["smtp"], $r["imap"], $r["pop"], $r["envio"]], function ($x) { return $x !== null && !$x["ok"]; })) > 0;
            if ($falhou) {
                $erros[] = t("Teste do e-mail ({1}): {2}.", [1 => $r["metodo"], 2 => implode("; ", $partes)]);
            } else {
                $msg = t("Teste do e-mail ({1}): {2}.", [1 => $r["metodo"], 2 => count($partes) > 0 ? implode("; ", $partes) : t("configurado")]);
            }
        }
    } elseif ($acao === "vapid_gerar") {
        if (!usuario_master()) {
            $erros[] = t("Só o master gera as chaves do Web Push.");
        } else {
            $par = vapid_gerar();
            $msg = t("Chaves novas geradas: copie as duas linhas para o config.php (trocar as chaves desliga os aparelhos já inscritos).");
            $extra = ["config" => "define(\"VAPID_PUBLICA\", \"" . $par["publica"] . "\");\ndefine(\"VAPID_PRIVADA\", \"" . $par["privada"] . "\");", "chaves" => $par];
        }
    }
    return resultado($erros, $msg, $extra);
}

// recurso=colecoes: criar (nome; opcao modelo, vazia ou arvore, com arvore_id: uma árvore minha que já existe), renomear
// (id, nome), excluir (id, com confirmar=1), arvore_copiar (arvore_id, nome), arvore_renomear (arvore_id, nome),
// arvore_excluir (arvore_id, com confirmar=1). Só o dono (e o master) mexe nas coleções e nas árvores dele
function op_colecoes($acao, $d)
{
    $u = usuario_atual();
    if ($u === null) {
        return resultado([t("Entre com um usuário (o token do servidor não tem conta).")], "");
    }
    $uid = (int)$u["id"];
    $erros = [];
    $msg = "";
    $extra = [];
    $nome = trim((string)($d["nome"] ?? ""));
    $c = isset($d["id"]) ? colecao_por_id((int)$d["id"]) : null;
    $ar = isset($d["arvore_id"]) ? sem_escopo(function () use ($d) { return linha("SELECT * FROM no WHERE id = ? AND pai_id IS NULL", [(int)$d["arvore_id"]]); }) : null;
    $dono_ok = function ($dono) use ($uid) { return (int)$dono === $uid || usuario_master(); };
    if ($acao === "criar") {
        $opcao = (string)($d["opcao"] ?? "modelo");
        $dono = usuario_master() && isset($d["dono_id"]) ? (int)$d["dono_id"] : $uid;
        if ($nome === "" || mb_strlen($nome, "UTF-8") > 120) {
            $erros[] = t("Dê um nome à coleção (até 120 caracteres).");
        } elseif (!in_array($opcao, ["modelo", "vazia", "arvore"], true)) {
            $erros[] = t("opcao: modelo (com o conjunto inicial), vazia (sem nada) ou arvore (com arvore_id: uma árvore sua que já existe).");
        } elseif ($opcao === "arvore" && ($ar === null || (int)$ar["dono_id"] !== $dono)) {
            $erros[] = t("Árvore não encontrada entre as do dono da coleção.");
        } elseif (usuario_por_id($dono) === null) {
            $erros[] = t("Dono não encontrado.");
        } elseif (sem_escopo(function () use ($dono, $nome) { return valor("SELECT id FROM colecao WHERE dono_id = ? AND nome = ?", [$dono, $nome]); }) !== null) {
            $erros[] = t("Já existe uma coleção com este nome.");
        } else {
            $lingua = lingua_atual();
            if ($opcao === "arvore") {
                [$raiz, $ids] = [(int)$ar["id"], ["raiz" => (int)$ar["id"]]];
            } else {
                [$raiz, $ids] = arvore_criar($dono, t("Grupos de {1}", [1 => $nome]), $lingua, $opcao === "modelo");
            }
            $cid = colecao_criar($dono, $nome, $raiz, $ids, $lingua);
            $msg = t("Coleção {1} criada.", [1 => $nome]);
            $extra = ["id" => $cid, "arvore_id" => $raiz];
        }
    } elseif ($acao === "renomear") {
        if ($c === null || !$dono_ok($c["dono_id"])) {
            $erros[] = t("Coleção não encontrada entre as suas.");
        } elseif ($nome === "" || mb_strlen($nome, "UTF-8") > 120) {
            $erros[] = t("Dê um nome à coleção (até 120 caracteres).");
        } elseif (sem_escopo(function () use ($c, $nome) { return valor("SELECT id FROM colecao WHERE dono_id = ? AND nome = ? AND id <> ?", [(int)$c["dono_id"], $nome, (int)$c["id"]]); }) !== null) {
            $erros[] = t("Já existe uma coleção com este nome.");
        } else {
            sem_escopo(function () use ($c, $nome) { sql("UPDATE colecao SET nome = ? WHERE id = ?", [$nome, (int)$c["id"]]); });
            $msg = t("Coleção renomeada para {1}.", [1 => $nome]);
        }
    } elseif ($acao === "excluir") {
        if ($c === null || !$dono_ok($c["dono_id"])) {
            $erros[] = t("Coleção não encontrada entre as suas.");
        } else {
            $n = colecao_excluir((int)$c["id"]);
            $msg = t("Coleção {1} excluída, com tudo o que era dela{2}. A árvore de grupos continua (use arvore_excluir para tirá-la).", [1 => $c["nome"], 2 => $n > 0 ? t(" ({1} arquivos)", [1 => $n]) : ""]);
        }
    } elseif (in_array($acao, ["arvore_copiar", "arvore_renomear", "arvore_excluir"], true)) {
        if ($ar === null || !$dono_ok($ar["dono_id"])) {
            $erros[] = t("Árvore não encontrada entre as suas.");
        } elseif ($acao !== "arvore_excluir" && ($nome === "" || mb_strlen($nome, "UTF-8") > 80)) {
            $erros[] = t("Dê um nome à árvore (até 80 caracteres).");
        } elseif ($acao === "arvore_copiar") {
            [$raiz] = arvore_copiar((int)$ar["id"], (int)$ar["dono_id"], $nome);
            $msg = t("Árvore copiada: {1}. Crie uma coleção com ela (opcao=arvore).", [1 => $nome]);
            $extra = ["arvore_id" => $raiz];
        } elseif ($acao === "arvore_renomear") {
            sem_escopo(function () use ($ar, $nome) { sql("UPDATE no SET nome = ? WHERE id = ?", [$nome, (int)$ar["id"]]); });
            $msg = t("Árvore renomeada para {1}.", [1 => $nome]);
        } else {
            $rel = relogios_das_colecoes(array_map("intval", array_column(sem_escopo(function () use ($ar) { return linhas("SELECT id FROM colecao WHERE no_id = ?", [(int)$ar["id"]]); }), "id")));
            sem_escopo(function () use ($ar) { sql("DELETE FROM no WHERE id = ?", [(int)$ar["id"]]); });
            $n = relogios_apagar_arquivos($rel);
            $msg = t("Árvore {1} excluída, com as coleções que a usavam{2}.", [1 => $ar["nome"], 2 => $n > 0 ? t(" ({1} arquivos)", [1 => $n]) : ""]);
        }
    }
    return resultado($erros, $msg, $extra);
}

// recurso=acessos: remover (usuario_id; colecao_id ou dono_id: o dono, ou o master, tira o acesso de alguém), sair
// (colecao_id ou dono_id: eu deixo de ver). Tirar um acesso não apaga nada além dele (confirmar=1 do mesmo jeito)
function op_acessos($acao, $d)
{
    $u = usuario_atual();
    if ($u === null) {
        return resultado([t("Entre com um usuário (o token do servidor não tem conta).")], "");
    }
    [$col, $dono, $erro] = alvo_do_pedido($d);
    $quem = $acao === "sair" ? (int)$u["id"] : (int)($d["usuario_id"] ?? 0);
    if ($erro !== "") {
        return resultado([$erro], "");
    }
    if ($acao === "remover" && !cuida_do_alvo($col, $dono)) {
        return resultado([t("Só o dono (ou o master) tira o acesso de alguém.")], "");
    }
    if (!in_array($acao, ["remover", "sair"], true)) {
        return resultado([], "");
    }
    $n = sem_escopo(function () use ($col, $dono, $quem) {
        $t = $col !== null ? ["colecao_usuario", "colecao_id", $col] : ["acesso_dono", "dono_id", $dono];
        $n = (int)valor("SELECT COUNT(*) FROM " . $t[0] . " WHERE " . $t[1] . " = ? AND usuario_id = ?", [$t[2], $quem]);
        sql("DELETE FROM " . $t[0] . " WHERE " . $t[1] . " = ? AND usuario_id = ?", [$t[2], $quem]);
        return $n;
    });
    if ($n === 0) {
        return resultado([t("Acesso não encontrado (o dono não sai da própria coleção: ele a exclui ou a transfere).")], "");
    }
    return resultado([], $acao === "sair" ? t("Você não vê mais {1}.", [1 => alvo_texto($col, $dono)])
        : t("{1} não vê mais {2}.", [1 => usuario_por_id($quem)["login"] ?? "?", 2 => alvo_texto($col, $dono)]));
}

// recurso=convites: convidar (email; colecao_id ou dono_id. Do dono ou do master, sai na hora; de um membro, espera o dono
// aprovar), aprovar (id), recusar (id), cancelar (id: quem convidou), reenviar (id), aceitar (token: a conta que já existe)
function op_convites($acao, $d)
{
    $u = usuario_atual();
    if ($u === null) {
        return resultado([t("Entre com um usuário (o token do servidor não tem conta).")], "");
    }
    $uid = (int)$u["id"];
    $erros = [];
    $msg = "";
    $extra = [];
    $v = isset($d["id"]) ? sem_escopo(function () use ($d) { return linha("SELECT * FROM convite WHERE id = ?", [(int)$d["id"]]); }) : null;
    if ($acao === "convidar") {
        $email = trim((string)($d["email"] ?? ""));
        [$col, $dono, $erro] = alvo_do_pedido($d);
        if ($email === "" || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros[] = t("E-mail inválido.");
        } elseif ($erro !== "") {
            $erros[] = $erro;
        } elseif (!ja_ve_alvo($uid, $col, $dono) && !usuario_master()) {
            $erros[] = t("Você só convida para o que você vê.");
        } elseif ($dono !== null && !cuida_do_alvo(null, $dono)) {
            $erros[] = t("Só o dono (ou o master) convida para tudo dele.");
        } else {
            $aprovado = cuida_do_alvo($col, $dono);
            $id = sem_escopo(function () use ($email, $uid, $col, $dono, $aprovado) {
                sql("INSERT INTO convite (email, convidado_por, colecao_id, dono_id, estado, token_hash, criado, expira) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$email, $uid, $col, $dono, $aprovado ? "aprovado" : "pendente", token_hash(token_novo()), date("Y-m-d H:i:s"), date("Y-m-d H:i:s", time() + convite_dias() * 86400)]);
                return ultimo_id();
            });
            $extra = ["id" => $id];
            if ($aprovado) {
                [$link, $foi] = convite_mandar($id);
                $msg = $foi ? t("Convite enviado para {1}.", [1 => $email]) : t("Convite criado. O e-mail não saiu: mande este link para {1} (ele só aparece agora).", [1 => $email]);
                $extra["link"] = $foi ? null : $link;
            } else {
                $msg = t("Convite criado: espera o dono aprovar.");
            }
        }
    } elseif (in_array($acao, ["aprovar", "recusar", "reenviar", "cancelar"], true)) {
        if ($v === null) {
            $erros[] = t("Convite não encontrado.");
        } elseif ($acao === "cancelar" && (int)$v["convidado_por"] !== $uid && !cuida_do_alvo($v["colecao_id"], $v["dono_id"])) {
            $erros[] = t("Só quem convidou (ou o dono) cancela o convite.");
        } elseif ($acao !== "cancelar" && !cuida_do_alvo($v["colecao_id"], $v["dono_id"]) && !($acao === "reenviar" && (int)$v["convidado_por"] === $uid)) {
            $erros[] = t("Só o dono (ou o master) aprova ou recusa o convite.");
        } elseif ($acao === "recusar" || $acao === "cancelar") {
            sem_escopo(function () use ($v) { sql("DELETE FROM convite WHERE id = ?", [(int)$v["id"]]); });
            $msg = $acao === "recusar" ? t("Convite recusado.") : t("Convite cancelado.");
        } elseif ($acao === "reenviar" && $v["estado"] !== "aprovado") {
            $erros[] = t("O convite ainda espera o dono aprovar.");
        } else {
            sem_escopo(function () use ($v) { sql("UPDATE convite SET estado = 'aprovado' WHERE id = ?", [(int)$v["id"]]); });
            [$link, $foi] = convite_mandar((int)$v["id"]);
            $msg = $foi ? t("Convite enviado para {1}.", [1 => $v["email"]]) : t("Convite criado. O e-mail não saiu: mande este link para {1} (ele só aparece agora).", [1 => $v["email"]]);
            $extra = ["link" => $foi ? null : $link];
        }
    } elseif ($acao === "aceitar") {
        $tk_ = trim((string)($d["token"] ?? ""));
        $v = sem_escopo(function () use ($tk_) { return linha("SELECT * FROM convite WHERE token_hash = ? AND estado = 'aprovado' AND expira > ?", [token_hash($tk_), date("Y-m-d H:i:s")]); });
        if ($v === null) {
            $erros[] = t("Convite desconhecido ou vencido.");
        } else {
            acesso_dar($uid, $v["colecao_id"], $v["dono_id"]);
            sem_escopo(function () use ($v) { sql("DELETE FROM convite WHERE id = ?", [(int)$v["id"]]); });
            $msg = t("Convite aceito: você vê {1}.", [1 => alvo_texto($v["colecao_id"], $v["dono_id"])]);
            $extra = ["colecao_id" => $v["colecao_id"] !== null ? (int)$v["colecao_id"] : null];
        }
    }
    return resultado($erros, $msg, $extra);
}

// recurso=pedidos: pedir (colecao_id ou dono_id, mensagem), aprovar (id), recusar (id), cancelar (id: o meu)
function op_pedidos($acao, $d)
{
    $u = usuario_atual();
    if ($u === null) {
        return resultado([t("Entre com um usuário (o token do servidor não tem conta).")], "");
    }
    $uid = (int)$u["id"];
    $erros = [];
    $msg = "";
    $extra = [];
    $p = isset($d["id"]) ? sem_escopo(function () use ($d) { return linha("SELECT * FROM pedido WHERE id = ?", [(int)$d["id"]]); }) : null;
    if ($acao === "pedir") {
        [$col, $dono, $erro] = alvo_do_pedido($d, true);
        if ($erro !== "") {
            $erros[] = $erro;
        } elseif (ja_ve_alvo($uid, $col, $dono)) {
            $erros[] = t("Você já vê {1}.", [1 => alvo_texto($col, $dono)]);
        } elseif (sem_escopo(function () use ($uid, $col, $dono) { return valor("SELECT id FROM pedido WHERE usuario_id = ? AND " . ($col !== null ? "colecao_id = ?" : "dono_id = ?"), [$uid, $col ?? $dono]); }) !== null) {
            $erros[] = t("Você já pediu este acesso: espere a resposta.");
        } else {
            $extra = ["id" => pedido_criar($uid, $col, $dono, (string)($d["mensagem"] ?? ""))];
            $msg = t("Pedido de acesso enviado ({1}): quando for aprovado, a coleção aparece para você.", [1 => alvo_codigo($col, $dono)]);
        }
    } elseif (in_array($acao, ["aprovar", "recusar", "cancelar"], true)) {
        if ($p === null) {
            $erros[] = t("Pedido não encontrado.");
        } elseif ($acao === "cancelar" && (int)$p["usuario_id"] !== $uid) {
            $erros[] = t("Só quem pediu cancela o pedido.");
        } elseif ($acao !== "cancelar" && !cuida_do_alvo($p["colecao_id"], $p["dono_id"])) {
            $erros[] = t("Só o dono (ou o master) aprova ou recusa o pedido.");
        } else {
            $quem = usuario_por_id((int)$p["usuario_id"]);
            if ($acao === "aprovar") {
                acesso_dar((int)$p["usuario_id"], $p["colecao_id"], $p["dono_id"]);
            }
            sem_escopo(function () use ($p) { sql("DELETE FROM pedido WHERE id = ?", [(int)$p["id"]]); });
            if ($acao !== "cancelar" && $quem !== null) {
                email_para($quem["email"], $quem["idioma"], $acao === "aprovar" ? tk("Relojoeiro: acesso aprovado") : tk("Relojoeiro: acesso recusado"),
                    $acao === "aprovar" ? tk("Olá, {1}. O seu pedido de acesso foi aprovado ({2}). Entre em {3}.") : tk("Olá, {1}. O seu pedido de acesso foi recusado ({2})."),
                    [1 => $quem["login"], 2 => alvo_texto($p["colecao_id"], $p["dono_id"]), 3 => url_do_sistema() . "/index.php"]);
            }
            $msg = $acao === "aprovar" ? t("Pedido aprovado: {1} vê {2}.", [1 => $quem["login"] ?? "?", 2 => alvo_texto($p["colecao_id"], $p["dono_id"])])
                : ($acao === "recusar" ? t("Pedido recusado.") : t("Pedido cancelado."));
        }
    }
    return resultado($erros, $msg, $extra);
}

// recurso=usuarios: salvar (login e senha, ou senha_hash: cria a conta, ou troca a senha; o master, de qualquer um; os
// outros, só a própria), e, só o master: alterar (usuario_id ou login; novo_login, email, idioma, papel), oficializar,
// prorrogar (dias), voltar_demo (dias), bloquear (bloqueado 1 ou 0), transferir (colecao_id, para: o login do novo dono),
// dar_acesso (usuario_id ou login; colecao_id ou dono_id), excluir (usuario_id ou login, com confirmar=1)
function op_usuarios($acao, $d, $atual)
{
    $erros = [];
    $msg = "";
    $extra = [];
    $alvo = trim((string)($d["login"] ?? ""));
    $nova = (string)($d["senha"] ?? "");
    // a senha, ou o hash pronto de outro sistema (senha_hash: bcrypt ou argon2, como os do password_hash do PHP), para
    // trazer um usuário com a mesma senha sem saber qual é
    $hash_pronto = trim((string)($d["senha_hash"] ?? ""));
    $u = usuario_do_pedido($d);
    if ($acao === "salvar") {
        if ($alvo === "" || strlen($alvo) > 60) {
            $erros[] = t("Informe o login (até 60 caracteres).");
        } elseif ($hash_pronto !== "" && $nova !== "") {
            $erros[] = t("Mande a senha ou o senha_hash, não os dois.");
        } elseif ($hash_pronto !== "" && (strlen($hash_pronto) > 255 || senha_hash_info($hash_pronto)[0] === null)) {
            $erros[] = t("senha_hash: um hash que o password_verify do PHP entenda (bcrypt, que começa com \$2y\$, \$2b\$ ou \$2a\$, ou argon2, com \$argon2).");
        } elseif ($hash_pronto === "" && strlen($nova) < 6) {
            $erros[] = t("Informe uma senha de pelo menos 6 caracteres (ou o senha_hash de outro sistema).");
        } else {
            // o $2b$ (o bcrypt de Python, Node, Java...) é o mesmo cálculo que o $2y$ do PHP: guarda como $2y$
            $hash = $hash_pronto !== "" ? (strncmp($hash_pronto, '$2b$', 4) === 0 ? '$2y$' . substr($hash_pronto, 4) : $hash_pronto) : password_hash($nova, PASSWORD_DEFAULT);
            $existe = sem_escopo(function () use ($alvo) { return linha("SELECT id, login FROM usuario WHERE login = ?", [$alvo]); });
            $eu = usuario_atual();
            if (!usuario_master() && $eu !== null && ($existe === null || (int)$existe["id"] !== (int)$eu["id"])) {
                // quem não é o master só troca a própria senha
                $erros[] = t("Só o master cria usuários e troca a senha de outro.");
            } elseif ($existe) {
                sem_escopo(function () use ($hash, $existe) { sql("UPDATE usuario SET senha_hash = ? WHERE id = ?", [$hash, (int)$existe["id"]]); });
                $msg = t("Senha de {1} trocada{2}", [1 => $existe["login"], 2 => $hash_pronto !== "" ? t(" (pelo hash informado).") : "."]);
            } else {
                // a conta nova: o primeiro usuário é o master; os outros, contas oficiais (criadas pelo master), cada uma
                // com a sua coleção, com o conjunto inicial (colecao_nova=nenhuma: sem coleção; vazia: só a árvore vazia)
                $opcao = in_array($d["colecao_nova"] ?? "", ["modelo", "vazia", "nenhuma"], true) ? $d["colecao_nova"] : "modelo";
                $email = trim((string)($d["email"] ?? ""));
                if (($e = login_erro($alvo)) !== "") {
                    $erros[] = $e;
                } elseif ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $erros[] = t("E-mail inválido.");
                } elseif ($email !== "" && sem_escopo(function () use ($email) { return valor("SELECT id FROM usuario WHERE email = ?", [$email]); }) !== null) {
                    $erros[] = t("Este e-mail já é de outra conta.");
                } else {
                    $r = conta_criar($alvo, $hash, $email, null, lingua_valida($d["idioma"] ?? null) ?? lingua_atual(), $opcao);
                    $msg = t("Usuário {1} criado{2}", [1 => $alvo, 2 => $hash_pronto !== "" ? t(" (pelo hash informado: entra com a senha do outro sistema).") : "."])
                        . ($r["colecao_id"] !== null ? " " . t("A coleção dele foi criada.") : "");
                    $extra = ["usuario_id" => $r["usuario_id"], "colecao_id" => $r["colecao_id"]];
                }
            }
        }
        return resultado($erros, $msg, $extra);
    }
    if (!usuario_master()) {
        return resultado([t("Só o master cuida das contas dos outros (as suas coisas ficam em recurso=conta).")], "");
    }
    if ($u === null) {
        return resultado([t("Usuário não encontrado.")], "");
    }
    $id = (int)$u["id"];
    $muda = function ($sql, $valores) use ($id) { sem_escopo(function () use ($sql, $valores, $id) { sql("UPDATE usuario SET " . $sql . " WHERE id = ?", array_merge($valores, [$id])); }); };
    $masters = (int)sem_escopo(function () { return valor("SELECT COUNT(*) FROM usuario WHERE papel = 'master'"); });
    if ($acao === "excluir") {
        if ($u["login"] === $atual) {
            $erros[] = t("Você não pode excluir o usuário com que está conectado.");
        } elseif ($u["papel"] === "master" && $masters <= 1) {
            $erros[] = t("Precisa sobrar pelo menos um master.");
        } else {
            // o usuário sai com tudo o que é dele (as coleções, as árvores, os relógios e os arquivos deles)
            usuario_excluir($id);
            $msg = t("Usuário {1} excluído.", [1 => $u["login"]]);
        }
    } elseif ($acao === "alterar") {
        $campos = [];
        $novo = trim((string)($d["novo_login"] ?? ""));
        if ($novo !== "" && $novo !== $u["login"]) {
            if (($e = login_erro($novo)) !== "") {
                $erros[] = $e;
            } elseif (sem_escopo(function () use ($novo) { return valor("SELECT id FROM usuario WHERE login = ?", [$novo]); }) !== null) {
                $erros[] = t("Este login já existe: escolha outro.");
            } else {
                $campos["login"] = $novo;
            }
        }
        if (array_key_exists("email", $d)) {
            $email = trim((string)$d["email"]);
            if ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $erros[] = t("E-mail inválido.");
            } elseif ($email !== "" && sem_escopo(function () use ($email, $id) { return valor("SELECT id FROM usuario WHERE email = ? AND id <> ?", [$email, $id]); }) !== null) {
                $erros[] = t("Este e-mail já é de outra conta.");
            } else {
                $campos["email"] = $email !== "" ? $email : null;
            }
        }
        if (array_key_exists("idioma", $d)) {
            $campos["idioma"] = lingua_valida($d["idioma"]);
        }
        if (array_key_exists("fuso", $d)) {
            if (trim((string)$d["fuso"]) !== "" && trim((string)$d["fuso"]) !== "navegador" && fuso_valido($d["fuso"]) === null) {
                $erros[] = t("Fuso horário desconhecido: use um nome como America/Sao_Paulo ou Europe/Lisbon (a lista de Minha conta), navegador (o do aparelho) ou vazio (o da coleção).");
            } else {
                $campos["fuso"] = trim((string)$d["fuso"]) === "navegador" ? "navegador" : fuso_valido($d["fuso"]);
            }
        }
        if (isset($d["papel"]) && $d["papel"] !== $u["papel"]) {
            if (!in_array($d["papel"], ["master", "comum"], true)) {
                $erros[] = t("papel: master ou comum.");
            } elseif ($d["papel"] === "comum" && $masters <= 1) {
                $erros[] = t("Precisa sobrar pelo menos um master.");
            } else {
                $campos["papel"] = $d["papel"];
                if ($d["papel"] === "master") {
                    $campos["expira_em"] = null;
                }
            }
        }
        if (count($erros) === 0 && count($campos) > 0) {
            $muda(implode(", ", array_map(function ($k) { return $k . " = ?"; }, array_keys($campos))), array_values($campos));
            $msg = t("Usuário {1} atualizado.", [1 => $campos["login"] ?? $u["login"]]);
        } elseif (count($erros) === 0) {
            $erros[] = t("Nada para mudar: mande novo_login, email, idioma, fuso ou papel.");
        }
    } elseif ($acao === "oficializar") {
        $muda("expira_em = ?", [null]);
        $msg = t("A conta {1} agora é oficial (não vence).", [1 => $u["login"]]);
    } elseif ($acao === "prorrogar" || $acao === "voltar_demo") {
        $dias = (int)($d["dias"] ?? ($acao === "voltar_demo" ? (demo_dias() > 0 ? demo_dias() : 30) : 0));
        if ($dias < 1 || $dias > 3650) {
            $erros[] = t("dias: de 1 a 3650.");
        } elseif ($u["papel"] === "master") {
            $erros[] = t("A conta do master não vence.");
        } else {
            $base = $acao === "prorrogar" && $u["expira_em"] !== null && strtotime((string)$u["expira_em"]) > time() ? strtotime((string)$u["expira_em"]) : time();
            $ate = date("Y-m-d H:i:s", $base + $dias * 86400);
            $muda("expira_em = ?", [$ate]);
            // os avisos de vencimento recomeçam
            $av = json_decode(sis("demo_avisos"), true);
            if (is_array($av) && isset($av[(string)$id])) {
                unset($av[(string)$id]);
                sis_set("demo_avisos", json_encode($av));
            }
            $msg = t("A conta {1} vence em {2}.", [1 => $u["login"], 2 => data_txt(strtotime($ate), true)]);
        }
    } elseif ($acao === "bloquear") {
        $b = (string)($d["bloqueado"] ?? "1") === "0" ? 0 : 1;
        if ($b === 1 && $u["login"] === $atual) {
            $erros[] = t("Você não pode bloquear o usuário com que está conectado.");
        } else {
            $muda("bloqueado = ?", [$b]);
            $msg = $b === 1 ? t("A conta {1} está bloqueada: não entra até ser desbloqueada.", [1 => $u["login"]]) : t("A conta {1} foi desbloqueada.", [1 => $u["login"]]);
        }
    } elseif ($acao === "transferir") {
        $para = sem_escopo(function () use ($d) { return linha("SELECT id FROM usuario WHERE login = ? OR id = ?", [trim((string)($d["para"] ?? "")), (int)($d["para_id"] ?? 0)]); });
        $c = colecao_por_id((int)($d["colecao_id"] ?? 0));
        if ($c === null || (int)$c["dono_id"] !== $id) {
            $erros[] = t("Coleção não encontrada entre as de {1}.", [1 => $u["login"]]);
        } elseif ($para === null) {
            $erros[] = t("O novo dono (para: o login) não existe.");
        } elseif (($e = colecao_transferir((int)$c["id"], (int)$para["id"])) !== "") {
            $erros[] = $e;
        } else {
            $msg = t("A coleção {1} agora é de {2}.", [1 => $c["nome"], 2 => usuario_por_id((int)$para["id"])["login"]]);
        }
    } elseif ($acao === "dar_acesso") {
        [$col, $dono, $erro] = alvo_do_pedido($d);
        if ($erro !== "") {
            $erros[] = $erro;
        } elseif (ja_ve_alvo($id, $col, $dono)) {
            $erros[] = t("{1} já vê {2}.", [1 => $u["login"], 2 => alvo_texto($col, $dono)]);
        } else {
            acesso_dar($id, $col, $dono);
            $msg = t("{1} vê {2}.", [1 => $u["login"], 2 => alvo_texto($col, $dono)]);
        }
    }
    return resultado($erros, $msg, $extra);
}

// ---------------------------------------------------------------------------------------------------------------------
// Quem pede: o token pessoal (Minha conta) e o "Vendo como" do master
// ---------------------------------------------------------------------------------------------------------------------

// O token pessoal: vale como o login do dono dele (a conta bloqueada ou vencida não entra). Devolve o login, ou ""
function usuario_por_token($token)
{
    if (trim((string)$token) === "" || !banco_tem_tabela("api_token")) {
        return "";
    }
    return sem_escopo(function () use ($token) {
        $u = linha("SELECT u.*, t.id AS token_id, t.ultimo_uso FROM api_token t JOIN usuario u ON u.id = t.usuario_id WHERE t.token_hash = ?", [token_hash(trim((string)$token))]);
        if ($u === null || (int)$u["bloqueado"] === 1 || ($u["expira_em"] !== null && strtotime((string)$u["expira_em"]) <= time())) {
            return "";
        }
        if ($u["ultimo_uso"] === null || strtotime((string)$u["ultimo_uso"]) < time() - 60) {
            sql("UPDATE api_token SET ultimo_uso = NOW() WHERE id = ?", [(int)$u["token_id"]]);
        }
        $id_t = (int)$u["token_id"];
        unset($u["token_id"], $u["ultimo_uso"]);
        $u["ultimo_acesso"] = $u["ultimo_acesso"] ?? null;
        usuario_definir($u);
        $GLOBALS["TOKEN_PESSOAL"] = $id_t;
        return (string)$u["login"];
    });
}

// O master vê o sistema como outro usuário (o parâmetro como=<login>, ou o cookie como, que a faixa "Vendo como" das
// páginas grava e apaga): daí para a frente, o pedido é desse usuário (as coleções dele, o que ele pode). Devolve o login
// dele, ou null (não é o master, ou o login não existe)
function vendo_como($login)
{
    $login = trim((string)$login);
    if ($login === "" || !usuario_master() || $login === usuario_atual()["login"]) {
        return null;
    }
    $alvo = sem_escopo(function () use ($login) { return linha("SELECT * FROM usuario WHERE login = ?", [$login]); });
    if ($alvo === null) {
        return null;
    }
    $GLOBALS["USUARIO_REAL"] = usuario_atual();
    usuario_definir($alvo);
    return (string)$alvo["login"];
}

// O pedaço de Minha conta que o menu das páginas usa: o seletor de coleção, a faixa "Vendo como", o aviso da demo, quantas
// aprovações esperam e o idioma padrão
function conta_menu()
{
    $u = usuario_atual();
    if ($u === null) {
        return ["usuario" => null, "colecoes" => [], "colecao" => null];
    }
    $real = $GLOBALS["USUARIO_REAL"] ?? null;
    return ["usuario" => ["login" => $u["login"], "papel" => $u["papel"], "demo" => $u["expira_em"] !== null,
            "dias_restantes" => $u["expira_em"] !== null ? max(0, (int)ceil((strtotime((string)$u["expira_em"]) - time()) / 86400)) : null],
        "vendo_como" => $real !== null ? ["login" => $u["login"], "master" => $real["login"]] : null,
        "master" => usuario_master() || ($real !== null && $real["papel"] === "master"),
        // o idioma de quem não escolheu no menu (o api.js segue este, que vem a cada página; o script do dicionário fica em cache)
        "idioma_padrao" => lingua_valida($u["idioma"] ?? null) ?? (lingua_valida(cfg("idioma")) ?? lingua_valida(sis("idioma"))),
        // de quantos em quantos segundos a página lê tudo de novo sozinha (a Configuração; 0: não lê)
        "atualizar_segundos" => colecao_atual() !== null ? atualizar_segundos() : 0,
        "colecoes" => array_map(function ($c) { return ["id" => $c["id"], "nome" => $c["nome"], "dono" => $c["dono"], "papel" => $c["papel"]]; }, colecoes_visiveis($u)),
        "colecao" => colecao_atual(), "aprovacoes" => count(aprovacoes_pedidos()) + count(aprovacoes_convites())];
}
