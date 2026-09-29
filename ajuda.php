<?php
// Ajuda: como o sistema funciona, os campos do cadastro, os avisos e as telas. O texto é o do README.md (as seções para
// quem usa: sem a instalação, a API e o código), convertido para HTML aqui, para a ajuda e o README nunca discordarem.
// Não lê o banco: só o README.md do lado do servidor (o navegador não abre o .md: o .htaccess e o nginx bloqueiam).
require_once __DIR__ . "/pagina.php";

// as seções do README que vão para a ajuda, na ordem dele: da primeira até antes da segunda (vazio: até a próxima seção de
// mesmo nível)
const AJUDA_TRECHOS = [["A ideia central", "Experimente em 1 minuto"], ["As telas", "A API"], ["Perguntas frequentes", "Como é por dentro"]];

// A âncora de um título, como a do GitHub (os links do README para as próprias seções funcionam aqui também): minúsculas,
// sem pontuação, espaços viram hífens; as letras acentuadas ficam
function ajuda_ancora($titulo)
{
    $t = function_exists("mb_strtolower") ? mb_strtolower($titulo, "UTF-8") : strtolower($titulo);
    $t = preg_replace("/[^\\p{L}\\p{N} _-]/u", "", $t);
    return str_replace(" ", "-", trim((string)$t));
}

// O que vai dentro de uma linha: escapa o HTML e troca `código`, **negrito**, *itálico*, ![imagem](...) e [link](...)
function ajuda_linha($s)
{
    $codigos = [];
    $s = preg_replace_callback("/`([^`]+)`/", function ($m) use (&$codigos) {
        $codigos[] = "<code>" . htmlspecialchars($m[1], ENT_QUOTES, "UTF-8") . "</code>";
        return "\x01" . (count($codigos) - 1) . "\x02";
    }, $s);
    $s = htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
    $s = preg_replace("/!\\[([^\\]]*)\\]\\(([^)\\s]+)\\)/", "<img src=\"$2\" alt=\"$1\" loading=\"lazy\">", $s);
    $s = preg_replace_callback("/\\[([^\\]]+)\\]\\(([^)\\s]+)\\)/", function ($m) {
        // link para outra seção: âncora na página; para um arquivo do código, só o texto (o navegador não abre)
        if ($m[2][0] === "#" || preg_match("#^https?://#", $m[2]) === 1) {
            return "<a href=\"" . $m[2] . "\"" . ($m[2][0] === "#" ? "" : " rel=\"noopener\" target=\"_blank\"") . ">" . $m[1] . "</a>";
        }
        return $m[1];
    }, $s);
    $s = preg_replace("/\\*\\*(.+?)\\*\\*/", "<strong>$1</strong>", $s);
    $s = preg_replace("/(?<![\\w*])\\*(?!\\s)(.+?)(?<!\\s)\\*(?![\\w*])/u", "<em>$1</em>", $s);
    return preg_replace_callback("/\x01(\\d+)\x02/", function ($m) use ($codigos) { return $codigos[(int)$m[1]]; }, $s);
}

// Um trecho de markdown em HTML: títulos (com âncora), parágrafos, listas, tabelas, blocos de código, linhas e o <sub> das
// legendas. O diagrama (mermaid) fica de fora: sem a biblioteca ele seria só texto. Devolve [html, sumário]
function ajuda_html($md)
{
    $linhas = explode("\n", str_replace("\r\n", "\n", $md));
    $html = "";
    $sumario = [];
    $par = [];
    $fecha_par = function () use (&$par, &$html) {
        if (count($par) > 0) {
            $html .= "<p>" . ajuda_linha(implode(" ", $par)) . "</p>\n";
            $par = [];
        }
    };
    $n = count($linhas);
    for ($i = 0; $i < $n; $i++) {
        $l = $linhas[$i];
        if (preg_match("/^```(\\w*)/", $l, $m) === 1) {
            $fecha_par();
            $bloco = [];
            for ($i++; $i < $n && strpos($linhas[$i], "```") !== 0; $i++) {
                $bloco[] = $linhas[$i];
            }
            if ($m[1] !== "mermaid") {
                $html .= "<pre>" . htmlspecialchars(implode("\n", $bloco), ENT_QUOTES, "UTF-8") . "</pre>\n";
            }
        } elseif (preg_match("/^(#{2,4})\\s+(.*)$/", $l, $m) === 1) {
            $fecha_par();
            $nivel = strlen($m[1]);
            $ancora = ajuda_ancora($m[2]);
            $html .= "<h" . $nivel . " id=\"" . htmlspecialchars($ancora, ENT_QUOTES, "UTF-8") . "\">" . ajuda_linha($m[2]) . "</h" . $nivel . ">\n";
            if ($nivel <= 3) {
                $sumario[] = [$nivel, $m[2], $ancora];
            }
        } elseif (trim($l) === "---") {
            $fecha_par();
        } elseif (strpos(ltrim($l), "|") === 0) {
            $fecha_par();
            $tabela = [];
            for (; $i < $n && strpos(ltrim($linhas[$i]), "|") === 0; $i++) {
                $tabela[] = $linhas[$i];
            }
            $i--;
            $celulas = function ($linha) {
                return array_map("trim", explode("|", trim(trim($linha), "|")));
            };
            $html .= "<div class=\"ajuda-tabela\"><table>\n<thead><tr>";
            foreach ($celulas($tabela[0]) as $c) {
                $html .= "<th>" . ajuda_linha($c) . "</th>";
            }
            $html .= "</tr></thead>\n<tbody>\n";
            foreach (array_slice($tabela, 2) as $t) {
                $html .= "<tr>";
                foreach ($celulas($t) as $c) {
                    $html .= "<td>" . ajuda_linha($c) . "</td>";
                }
                $html .= "</tr>\n";
            }
            $html .= "</tbody></table></div>\n";
        } elseif (preg_match("/^(-|\\d+\\.)\\s+/", $l, $m) === 1) {
            $fecha_par();
            $tag = $m[1] === "-" ? "ul" : "ol";
            $html .= "<" . $tag . ">\n";
            for (; $i < $n && preg_match("/^(-|\\d+\\.)\\s+(.*)$/", $linhas[$i], $mi) === 1; $i++) {
                $item = [$mi[2]];
                // as linhas seguintes com recuo continuam o mesmo item
                while ($i + 1 < $n && preg_match("/^\\s{2,}\\S/", $linhas[$i + 1]) === 1) {
                    $item[] = trim($linhas[++$i]);
                }
                $html .= "<li>" . ajuda_linha(implode(" ", $item)) . "</li>\n";
            }
            $i--;
            $html .= "</" . $tag . ">\n";
        } elseif (strpos(ltrim($l), "<sub>") === 0) {
            // a legenda de uma imagem: até o </sub>
            $fecha_par();
            $leg = [];
            for (; $i < $n; $i++) {
                $leg[] = $linhas[$i];
                if (strpos($linhas[$i], "</sub>") !== false) {
                    break;
                }
            }
            $html .= "<p class=\"nota\">" . ajuda_linha(str_replace(["<sub>", "</sub>"], "", implode(" ", $leg))) . "</p>\n";
        } elseif (trim($l) === "") {
            $fecha_par();
        } else {
            $par[] = trim($l);
        }
    }
    $fecha_par();
    return [$html, $sumario];
}

// os trechos do README
$readme = (string)@file_get_contents(__DIR__ . "/README.md");
$md = "";
foreach (AJUDA_TRECHOS as $t) {
    $ini = strpos($readme, "\n## " . $t[0]);
    $fim = $ini === false ? false : strpos($readme, "\n## " . $t[1], $ini + 1);
    if ($ini !== false) {
        $md .= substr($readme, $ini, $fim === false ? null : $fim - $ini) . "\n";
    }
}
[$conteudo, $sumario] = ajuda_html($md);

topo("Ajuda", "ajuda.php");
?>

<main class="ajuda">
  <h1>Ajuda</h1>
<?php if ($conteudo === "") { ?>
  <p>O README.md não foi encontrado na pasta do sistema.</p>
<?php } else { ?>
  <nav class="ajuda-sumario" aria-label="Nesta página">
    <h2>Nesta página</h2>
    <ul>
<?php foreach ($sumario as $s) { ?>
      <li class="nivel-<?= $s[0] ?>"><a href="#<?= htmlspecialchars($s[2], ENT_QUOTES, "UTF-8") ?>"><?= strip_tags(ajuda_linha($s[1])) ?></a></li>
<?php } ?>
    </ul>
  </nav>
<?= $conteudo ?>
<?php } ?>
</main>
<?php rodape([]); ?>
