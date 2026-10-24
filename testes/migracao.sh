#!/usr/bin/env bash
# O teste da atualização: um banco instalado por uma versão anterior do sistema (com dados) passa para o código de agora
# pelas migrações, como no servidor de quem usa (git pull e Configuração › Aplicar). Para cada banco: um banco vazio, a
# instalação e o usuário de teste com o código da versão anterior (uma cópia dela numa pasta temporária, pelo git
# worktree), dados pela API dela (um relógio com valores, leituras de carga, uma sessão no pulso, um documento e um
# acessório), e então o código de agora: as migrações pendentes, aplicar, e a conferência (nenhuma pendente depois, o
# relógio e os dados de antes lidos pela API de agora, a ficha, a página Hoje, tudo sem recurso e nenhum aviso do PHP).
#
#   testes/migracao.sh                      os quatro: sqlite pgsql mysql firebird, a partir do commit anterior (HEAD~1)
#   testes/migracao.sh sqlite firebird      só esses
#   MIGRACAO_BASE=origin/main testes/migracao.sh    a partir de outra versão (um commit, uma tag, um ramo); no CI, o main de
#                                           antes do push: o mesmo caminho do servidor de quem usa ao fazer o git pull
#
# Os bancos e o acesso são os do teste de paridade (testes/config.teste.php), com outro nome de banco (TESTE_BANCO, padrão
# relogios_mig_teste; o arquivo do Firebird ganha "_mig"), APAGADO e recriado; as portas a partir de TESTE_PORTA_MIG (padrão
# 8150, duas por banco). Sem migração nova entre as duas versões, confere do mesmo jeito (o banco já fica em dia).
# Termina com 0 se tudo passou.
set -u
cd "$(dirname "$0")/.." || exit 2
RAIZ=$(pwd)

BANCOS=()
for a in "$@"; do
  case "$a" in
    sqlite|pgsql|mysql|firebird) BANCOS+=("$a") ;;
    *) echo "Não conheço \"$a\". Uso: testes/migracao.sh [sqlite] [pgsql] [mysql] [firebird]" >&2; exit 2 ;;
  esac
done
[ ${#BANCOS[@]} -eq 0 ] && BANCOS=(sqlite pgsql mysql firebird)

BASE="${MIGRACAO_BASE:-HEAD~1}"
# no CI, a base é o main de antes do push (github.event.before); num ramo novo ele vem só com zeros: o commit anterior
case "$BASE" in
  ""|0000000000000000000000000000000000000000) BASE="HEAD~1" ;;
esac
if ! git rev-parse --verify -q "$BASE^{commit}" > /dev/null; then
  echo "A versão anterior ($BASE) não está no git desta cópia (no CI: fetch-depth 0)." >&2
  exit 2
fi
export TESTE_BANCO="${TESTE_BANCO_MIG:-relogios_mig_teste}"
FB_BASE="${TESTE_FB_ARQUIVO:-/var/lib/firebird/3.0/data/relogios_teste.fdb}"
export TESTE_FB_ARQUIVO="${FB_BASE%.fdb}_mig.fdb"
case "$TESTE_BANCO$TESTE_FB_ARQUIVO" in
  *teste*teste*) ;;
  *) echo "O banco do teste precisa ter \"teste\" no nome: ele é apagado e recriado." >&2; exit 2 ;;
esac
PASTA=$(mktemp -d "${TMPDIR:-/tmp}/relojoeiro-migracao.XXXXXX")
VELHO="$PASTA/versao-anterior"
git worktree add -q --detach "$VELHO" "$BASE" || exit 2
SERVIDOR=""
limpar() {
  [ -n "$SERVIDOR" ] && kill "$SERVIDOR" 2>/dev/null
  git -C "$RAIZ" worktree remove --force "$VELHO" 2>/dev/null
}
trap limpar EXIT
trap 'exit 130' INT TERM
PORTA0="${TESTE_PORTA_MIG:-8150}"
TOKEN="X-Api-Token: token-de-teste-123"
echo "Da versão $(git log --oneline -1 "$BASE" | cut -c1-70) para a de agora ($(git log --oneline -1 HEAD | cut -c1-70))"

# o banco vazio (como no paridade.sh)
preparar() {
  case $1 in
    sqlite) rm -f "$RELOGIOS_TESTE_PASTA/sqlite.sqlite"* ;;
    pgsql)
      PGOPTIONS="--client-min-messages=warning" PGPASSWORD="${TESTE_PG_SENHA:-teste}" psql -h "${TESTE_PG_HOST:-127.0.0.1}" -p "${TESTE_PG_PORTA:-5432}" \
        -U "${TESTE_PG_USUARIO:-postgres}" -q -d postgres -c "DROP DATABASE IF EXISTS $TESTE_BANCO" -c "CREATE DATABASE $TESTE_BANCO" ;;
    mysql)
      mysql -h "${TESTE_MYSQL_HOST:-127.0.0.1}" -P "${TESTE_MYSQL_PORTA:-3306}" -u "${TESTE_MYSQL_USUARIO:-teste}" -p"${TESTE_MYSQL_SENHA:-teste}" \
        -e "DROP DATABASE IF EXISTS $TESTE_BANCO; CREATE DATABASE $TESTE_BANCO COLLATE utf8mb4_general_ci" ;;
    firebird)
      local u="${TESTE_FB_USUARIO:-SYSDBA}" s="${TESTE_FB_SENHA:-masterkey}" h="${TESTE_FB_HOST:-localhost}"
      echo "CONNECT '$h:$TESTE_FB_ARQUIVO' USER '$u' PASSWORD '$s'; DROP DATABASE; QUIT;" | isql-fb -q >/dev/null 2>&1
      echo "CREATE DATABASE '$h:$TESTE_FB_ARQUIVO' USER '$u' PASSWORD '$s' PAGE_SIZE 16384 DEFAULT CHARACTER SET UTF8; QUIT;" | isql-fb -q ;;
  esac
}

# um servidor com o código de uma pasta, na porta; espera ele responder
subir() {
  php -S "127.0.0.1:$2" -t "$1" >> "$3" 2>&1 &
  SERVIDOR=$!
  local n=0
  until curl -s -o /dev/null "http://127.0.0.1:$2/api.php"; do
    n=$((n + 1)); [ $n -gt 100 ] && return 1
    sleep 0.1
  done
}
descer() {
  kill "$SERVIDOR" 2>/dev/null; wait "$SERVIDOR" 2>/dev/null; SERVIDOR=""
}
# um valor de uma resposta JSON pelo caminho (a.b.0.c); vazio se não tem
json() {
  php -r '$j = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $j = is_array($j) && array_key_exists($k, $j) ? $j[$k] : null; } echo is_array($j) ? json_encode($j) : (is_bool($j) ? ($j ? "true" : "false") : (string)$j);' "$1"
}

FALHOU=0
i=0
for b in "${BANCOS[@]}"; do
  export RELOGIOS_DB=$b RELOGIOS_TESTE_PASTA="$PASTA/$b"
  mkdir -p "$RELOGIOS_TESTE_PASTA"
  LOG="$PASTA/$b.servidor.log"
  pv=$((PORTA0 + 2 * i)); pn=$((pv + 1)); i=$((i + 1))
  erros=()
  # a versão anterior: a instalação, o usuário e os dados
  export RELOGIOS_CONFIG="$VELHO/testes/config.teste.php"
  if ! { preparar "$b" && (cd "$VELHO" && php instalar.php); } > "$PASTA/$b.log" 2>&1; then
    echo "$b: a instalação da versão anterior falhou:"; tail -3 "$PASTA/$b.log" | sed 's/^/    /'; FALHOU=1; continue
  fi
  subir "$VELHO" $pv "$LOG" || { echo "$b: o servidor da versão anterior não subiu"; FALHOU=1; continue; }
  A="http://127.0.0.1:$pv/api.php"
  # o primeiro usuário pela API, com o token (o jeito de qualquer versão)
  curl -s -H "$TOKEN" --data-urlencode recurso=usuarios --data-urlencode acao=salvar --data-urlencode login=teste --data-urlencode senha=Senha@123 \
    --data-urlencode senha_confirmacao=Senha@123 "$A" >> "$PASTA/$b.log" 2>&1
  R=$(curl -s -H "$TOKEN" -d recurso=relogio -d acao=salvar -d id=0 -d "nome=Relógio de antes" -d no_id=0 -d disponivel=1 \
    -d "valores[valor_compra]=1500.50" -d "valores[observacao]=Gravado pela versão anterior" "$A" | json id)
  [ -z "$R" ] && erros+=("não gravou o relógio na versão anterior")
  curl -s -H "$TOKEN" -d recurso=lancamento -d acao=lancar -d relogio_id="$R" -d tipo=carga -d valor=90 -d "quando=$(date -d '-2 days' '+%Y-%m-%d %H:%M')" "$A" > /dev/null
  curl -s -H "$TOKEN" -d recurso=lancamento -d acao=lancar -d relogio_id="$R" -d tipo=carga -d valor=70 "$A" > /dev/null
  curl -s -H "$TOKEN" -d recurso=lancamento -d acao=periodo -d relogio_id="$R" -d tipo=pulso -d "inicio=$(date -d '-1 day' '+%Y-%m-%d') 09:00" -d "fim=$(date -d '-1 day' '+%Y-%m-%d') 18:00" "$A" > /dev/null
  CAT=$(curl -s -H "$TOKEN" "$A?recurso=documentos" | json categorias.0.id)
  curl -s -H "$TOKEN" -d recurso=documentos -d acao=enviar -d relogio_id="$R" -d categoria_id="$CAT" -d "titulo=Documento de antes" -d arquivo_nome=antes.txt \
    --data-urlencode "arquivo_base64=$(printf 'conteudo de antes' | base64)" "$A" > /dev/null
  curl -s -H "$TOKEN" -d recurso=acessorios -d acao=salvar -d "nome=Pulseira de antes" -d tipo=pulseira -d relogio_id="$R" "$A" > /dev/null
  # dois serviços: um com quem fez (vira uma oficina) e um sem (vai para a "Eu mesmo (em casa)")
  curl -s -H "$TOKEN" -d recurso=servicos -d acao=salvar -d relogio_id="$R" -d tipo=revisao -d "data=$(date -d '-30 days' '+%Y-%m-%d')" \
    -d "responsavel=Relojoaria de antes" -d mao_de_obra=300 "$A" > /dev/null
  curl -s -H "$TOKEN" -d recurso=servicos -d acao=salvar -d relogio_id="$R" -d tipo=pilha -d "data=$(date -d '-10 days' '+%Y-%m-%d')" "$A" > /dev/null
  SRV_ANTES=$(curl -s -H "$TOKEN" "$A?recurso=servicos&relogio=$R" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true)["servicos"] ?? []);')
  LANC_ANTES=$(curl -s -H "$TOKEN" "$A?recurso=historico&relogio=$R" | json total)
  descer
  # a versão de agora: as pendentes, aplicar e conferir
  export RELOGIOS_CONFIG="$RAIZ/testes/config.teste.php"
  subir "$RAIZ" $pn "$LOG" || { echo "$b: o servidor de agora não subiu"; FALHOU=1; continue; }
  A="http://127.0.0.1:$pn/api.php"
  PEND=$(curl -s -H "$TOKEN" "$A?recurso=migracoes" | php -r 'echo implode(", ", array_column(json_decode(stream_get_contents(STDIN), true)["pendentes"] ?? [], "versao"));')
  APL=$(curl -s -H "$TOKEN" -d recurso=migracoes -d acao=aplicar "$A")
  [ "$(echo "$APL" | json ok)" != "true" ] && erros+=("aplicar falhou: $(echo "$APL" | json erros)")
  [ -n "$(curl -s -H "$TOKEN" "$A?recurso=migracoes" | json pendentes.0.versao)" ] && erros+=("ainda há migração pendente depois de aplicar")
  FICHA=$(curl -s -H "$TOKEN" "$A?recurso=ficha&relogio=$R")
  [ "$(echo "$FICHA" | json relogio.nome)" != "Relógio de antes" ] && erros+=("a ficha não traz o relógio de antes: $(echo "$FICHA" | head -c 200)")
  [ "$(echo "$FICHA" | json relogio.documentos.total)" != "1" ] && erros+=("o documento de antes sumiu")
  [ "$(echo "$FICHA" | json relogio.acessorios.0.nome)" != "Pulseira de antes" ] && erros+=("o acessório de antes sumiu")
  [ "$(curl -s -H "$TOKEN" "$A?recurso=historico&relogio=$R" | json total)" != "$LANC_ANTES" ] && erros+=("o histórico mudou de tamanho")
  # os serviços de antes continuam, cada um a volta de uma manutenção encerrada; quem fez virou a oficina (sem ele, a sua)
  [ "$(curl -s -H "$TOKEN" "$A?recurso=servicos&relogio=$R" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true)["servicos"] ?? []);')" != "$SRV_ANTES" ] \
    && erros+=("os serviços de antes mudaram de número")
  MAN=$(curl -s -H "$TOKEN" "$A?recurso=manutencao&relogio=$R" | php -r '$j = json_decode(stream_get_contents(STDIN), true); $m = $j["manutencoes"] ?? [];
    $of = array_column($j["oficinas"] ?? [], "nome"); sort($of);
    echo count($m), "|", count(array_filter($m, function ($x) { return ($x["situacao_comportamento"] ?? "") === "encerra" && !$x["aberta"]; })), "|",
      implode(",", array_unique(array_column($m, "oficina"))), "|", count(array_filter($j["oficinas"] ?? [], function ($o) { return $o["propria"]; }));')
  [ "${MAN%%|*}" != "$SRV_ANTES" ] && erros+=("as manutenções dos serviços de antes: $MAN (serviços: $SRV_ANTES)")
  [ "$(echo "$MAN" | cut -d'|' -f2)" != "$SRV_ANTES" ] && erros+=("uma manutenção dos serviços de antes não ficou encerrada: $MAN")
  [ "$(echo "$MAN" | cut -d'|' -f4)" != "1" ] && erros+=("a coleção não tem a sua oficina (Eu mesmo): $MAN")
  # o "quem fez" só existe antes da v48: só quando ela é aplicada agora ele vira a oficina
  if [ "$SRV_ANTES" = "2" ] && case ", $PEND," in *", v48,"*) true ;; *) false ;; esac; then
    case ",$(echo "$MAN" | cut -d'|' -f3)," in *",Relojoaria de antes,"*) ;; *) erros+=("quem fez o serviço de antes não virou a oficina: $MAN") ;; esac
  fi
  for q in "recurso=hoje" "foto=nao" "recurso=passaporte&relogio=$R" "recurso=documentos&relogio=$R" "recurso=listas" "recurso=oficinas"; do
    c=$(curl -s -o /dev/null -w '%{http_code}' -H "$TOKEN" "$A?$q")
    [ "$c" != "200" ] && erros+=("$q respondeu $c")
  done
  descer
  avisos=$(grep -i 'warning\|fatal\|notice\|deprecated' "$LOG" | head -3)
  [ -n "$avisos" ] && erros+=("o PHP deu aviso: $avisos")
  if [ ${#erros[@]} -eq 0 ]; then
    echo "$b: atualizou (pendentes: ${PEND:-nenhuma}) e os dados de antes continuam"
  else
    echo "$b: a atualização falhou (pendentes: ${PEND:-nenhuma}):"
    printf '    %s\n' "${erros[@]}"
    FALHOU=1
  fi
done
[ $FALHOU -eq 0 ] && echo "A atualização confere." || echo "A atualização falhou."
exit $FALHOU
