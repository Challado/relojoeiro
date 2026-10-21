#!/usr/bin/env bash
# O teste de paridade com um comando: para cada banco, um banco vazio, a instalação, o usuário de teste, um servidor com a semente
# dos sorteios fixa e o roteiro (testes/cenario.php), todos ao mesmo tempo; depois compara a fotografia de cada um com a do
# primeiro (testes/comparar.php). Tudo numa pasta temporária, com o config de teste (testes/config.teste.php): o config.php de
# verdade não é lido nem tocado.
#
#   testes/paridade.sh                           os quatro: sqlite pgsql mysql firebird
#   testes/paridade.sh sqlite mysql              só esses (o primeiro é a referência)
#   testes/paridade.sh --layout sqlite           e, no fim, a conferência do layout (testes/layout.cjs) no primeiro
#   testes/paridade.sh --manter sqlite           deixa os servidores no ar no fim (para olhar as telas); Ctrl+C derruba
#   LINGUA=zh-CN testes/paridade.sh --textos sqlite   e a conferência dos textos fixos (testes/texto-fixo.cjs) no primeiro:
#                                                nenhum texto nas telas fora do dicionário (o roteiro no mesmo idioma)
#
# Os bancos (MySQL/MariaDB, PostgreSQL, Firebird) têm de estar no ar; o acesso vem do ambiente (veja testes/config.teste.php).
# O banco de teste é APAGADO e recriado a cada rodada: o nome (TESTE_BANCO, padrão relogios_teste) tem de ter "teste".
# Depois da comparação, confere que o PHP não deu nenhum aviso (Warning, Notice, Deprecated) durante a rodada, a
# arquitetura (testes/arquitetura.php) e a documentação: o cabeçalho do api.php é o que a ajuda gera (testes/dicionario.php), os
# dicionários dos idiomas têm todos os textos do código (testes/textos.php) e todo
# campo das respostas está explicado na ajuda (testes/campos.php, no servidor do primeiro banco). Por último, em cada banco, o
# isolamento entre contas (testes/isolamento.php): uma conta não vê nem muda o que é de outra pelo token pessoal.
# LINGUA=en roda o roteiro e a conferência dos campos nesse idioma (e o layout, com --layout); TESTE_ESTRITO=1 liga o modo
# estrito dos idiomas no servidor de teste: a tradução que falta para com erro e aparece no registro do roteiro.
# Outras variáveis: TESTE_PORTA (a primeira porta, padrão 8101; uma por banco), RELOGIOS_TESTE_PASTA (a pasta da rodada;
# padrão: uma nova, temporária). Termina com 0 se tudo saiu igual, sem escrita recusada (e sem problema de layout).
set -u
cd "$(dirname "$0")/.." || exit 2
RAIZ=$(pwd)

LAYOUT=0
MANTER=0
TEXTOS=0
BANCOS=()
for a in "$@"; do
  case "$a" in
    --layout) LAYOUT=1 ;;
    --manter) MANTER=1 ;;
    --textos) TEXTOS=1 ;;
    sqlite|pgsql|mysql|firebird) BANCOS+=("$a") ;;
    *) echo "Não conheço \"$a\". Uso: testes/paridade.sh [--layout] [--textos] [--manter] [sqlite] [pgsql] [mysql] [firebird]" >&2; exit 2 ;;
  esac
done
[ ${#BANCOS[@]} -eq 0 ] && BANCOS=(sqlite pgsql mysql firebird)

export TESTE_BANCO="${TESTE_BANCO:-relogios_teste}"
case "$TESTE_BANCO" in
  *teste*) ;;
  *) echo "TESTE_BANCO (\"$TESTE_BANCO\") precisa ter \"teste\" no nome: ele é apagado e recriado." >&2; exit 2 ;;
esac
FB_ARQUIVO="${TESTE_FB_ARQUIVO:-/var/lib/firebird/3.0/data/relogios_teste.fdb}"
case "$FB_ARQUIVO" in
  *teste*) ;;
  *) echo "TESTE_FB_ARQUIVO (\"$FB_ARQUIVO\") precisa ter \"teste\" no nome: ele é apagado e recriado." >&2; exit 2 ;;
esac
PASTA="${RELOGIOS_TESTE_PASTA:-$(mktemp -d "${TMPDIR:-/tmp}/relojoeiro-paridade.XXXXXX")}"
mkdir -p "$PASTA"
export RELOGIOS_TESTE_PASTA="$PASTA"
export RELOGIOS_CONFIG="$RAIZ/testes/config.teste.php"
export RELOGIOS_SEMENTE=42
PORTA0="${TESTE_PORTA:-8101}"

SERVIDORES=()
derrubar() {
  for p in "${SERVIDORES[@]}"; do kill "$p" 2>/dev/null; done
}
trap derrubar EXIT
trap 'exit 130' INT TERM

# o banco vazio
preparar() {
  local b=$1
  case $b in
    sqlite) rm -f "$PASTA/sqlite.sqlite"* ;;
    pgsql)
      PGPASSWORD="${TESTE_PG_SENHA:-teste}" psql -h "${TESTE_PG_HOST:-127.0.0.1}" -p "${TESTE_PG_PORTA:-5432}" -U "${TESTE_PG_USUARIO:-postgres}" -q -d postgres \
        -c "DROP DATABASE IF EXISTS $TESTE_BANCO" -c "CREATE DATABASE $TESTE_BANCO" ;;
    mysql)
      mysql -h "${TESTE_MYSQL_HOST:-127.0.0.1}" -P "${TESTE_MYSQL_PORTA:-3306}" -u "${TESTE_MYSQL_USUARIO:-teste}" -p"${TESTE_MYSQL_SENHA:-teste}" \
        -e "DROP DATABASE IF EXISTS $TESTE_BANCO; CREATE DATABASE $TESTE_BANCO COLLATE utf8mb4_general_ci" ;;
    firebird)
      local u="${TESTE_FB_USUARIO:-SYSDBA}" s="${TESTE_FB_SENHA:-masterkey}" h="${TESTE_FB_HOST:-localhost}"
      echo "CONNECT '$h:$FB_ARQUIVO' USER '$u' PASSWORD '$s'; DROP DATABASE; QUIT;" | isql-fb -q >/dev/null 2>&1
      echo "CREATE DATABASE '$h:$FB_ARQUIVO' USER '$u' PASSWORD '$s' PAGE_SIZE 16384 DEFAULT CHARACTER SET UTF8; QUIT;" | isql-fb -q ;;
  esac
}

# uma rodada inteira num banco: instala, sobe o servidor, cria o primeiro usuário pela API (o token) e roda o roteiro
rodar() {
  local b=$1 porta=$2 log="$PASTA/$1.log"
  export RELOGIOS_DB=$b
  rm -rf "$PASTA/documentos_$b" "$PASTA/copias_$b"
  { preparar "$b" && php instalar.php; } > "$log" 2>&1 || { echo "FALHOU NA INSTALAÇÃO" >> "$log"; return 1; }
  if curl -s -o /dev/null "http://127.0.0.1:$porta/"; then
    echo "A porta $porta já está ocupada (outro servidor no ar?): use TESTE_PORTA para escolher outra" >> "$log"; return 1
  fi
  # 4 processos: a conferência do layout abre as telas em 15 tamanhos ao mesmo tempo
  PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$porta" -t "$RAIZ" > "$PASTA/$b.servidor.log" 2>&1 &
  echo $! > "$PASTA/$b.pid"
  local n=0
  until curl -s -o /dev/null "http://127.0.0.1:$porta/api.php"; do
    n=$((n + 1)); [ $n -gt 100 ] && { echo "O servidor não subiu" >> "$log"; return 1; }
    sleep 0.1
  done
  # o primeiro usuário, como numa instalação de verdade: pela API, com o token (é o master)
  curl -s -H "X-Api-Token: ${RELOGIOS_TOKEN:-token-de-teste-123}" --data-urlencode recurso=usuarios --data-urlencode acao=salvar --data-urlencode login=teste \
    --data-urlencode senha=Senha@123 --data-urlencode senha_confirmacao=Senha@123 "http://127.0.0.1:$porta/api.php" >> "$log" 2>&1
  echo >> "$log"
  php testes/cenario.php "http://127.0.0.1:$porta/api.php" "$PASTA/$b.json" >> "$log" 2>&1
}

echo "Pasta da rodada: $PASTA"
i=0
PIDS=()
for b in "${BANCOS[@]}"; do
  rodar "$b" $((PORTA0 + i)) &
  PIDS+=($!)
  i=$((i + 1))
done
FALHOU=0
i=0
for b in "${BANCOS[@]}"; do
  if ! wait "${PIDS[$i]}"; then
    echo "$b: o roteiro falhou (veja $PASTA/$b.log):"
    tail -5 "$PASTA/$b.log" | sed 's/^/    /'
    FALHOU=1
  fi
  [ -f "$PASTA/$b.pid" ] && SERVIDORES+=("$(cat "$PASTA/$b.pid")")
  i=$((i + 1))
done

REF=${BANCOS[0]}
if [ -f "$PASTA/$REF.json" ]; then
  echo "$REF: a referência ($(tail -1 "$PASTA/$REF.log"))"
  for b in "${BANCOS[@]:1}"; do
    if [ -f "$PASTA/$b.json" ]; then
      res=$(php testes/comparar.php "$PASTA/$REF.json" "$PASTA/$b.json" > "$PASTA/$b.comparacao.txt" 2>&1; echo $?)
      echo "$b: $(tail -1 "$PASTA/$b.comparacao.txt")"
      [ "$res" != "0" ] && { FALHOU=1; grep -A2 "^GRAVE" "$PASTA/$b.comparacao.txt" | head -30 | sed 's/^/    /'; }
    fi
  done
else
  FALHOU=1
fi

# nenhum aviso do PHP durante a rodada (no servidor e no roteiro): um Warning, Notice ou Deprecated é defeito, mesmo que a
# resposta saia certa
for b in "${BANCOS[@]}"; do
  avisos=$(grep -hE 'PHP (Warning|Notice|Deprecated|Fatal error|Parse error)' "$PASTA/$b.servidor.log" "$PASTA/$b.log" 2>/dev/null | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | sort -rn)
  if [ -n "$avisos" ]; then
    FALHOU=1; echo "$b: o PHP deu avisos durante a rodada:"; echo "$avisos" | head -15 | sed 's/^/    /'
  fi
done

# a arquitetura: as regras que o código tem de seguir (testes/arquitetura.php: as telas sem banco, a rede só pela API, o
# banco só no núcleo, na API e no cron, o bloqueio pela web, a API e o config.php documentados, as migrações, o repositório)
if php testes/arquitetura.php > "$PASTA/arquitetura.txt" 2>&1; then
  echo "arquitetura: $(tail -1 "$PASTA/arquitetura.txt")"
else
  FALHOU=1; echo "arquitetura:"; sed 's/^/    /' "$PASTA/arquitetura.txt" | head -40
fi

# a documentação: o cabeçalho do api.php é o que a ajuda gera, e todo campo das respostas está explicado na ajuda
if php testes/dicionario.php > "$PASTA/dicionario.txt" 2>&1; then
  echo "dicionário: $(tail -1 "$PASTA/dicionario.txt")"
else
  FALHOU=1; echo "dicionário:"; sed 's/^/    /' "$PASTA/dicionario.txt"
fi
# os idiomas: os dicionários de cada um têm todos os textos do código, com os mesmos parâmetros
if php testes/textos.php > "$PASTA/textos.txt" 2>&1; then
  echo "idiomas: $(tail -1 "$PASTA/textos.txt")"
else
  FALHOU=1; echo "idiomas:"; sed 's/^/    /' "$PASTA/textos.txt" | head -40
fi
if [ -f "$PASTA/$REF.json" ]; then
  if php testes/campos.php "http://127.0.0.1:$PORTA0/api.php" > "$PASTA/campos.txt" 2>&1; then
    echo "campos: $(tail -1 "$PASTA/campos.txt")"
  else
    FALHOU=1; echo "campos:"; sed 's/^/    /' "$PASTA/campos.txt" | head -40
  fi
fi

# os textos fixos antes do layout: o layout grava dados de estresse que o roteiro não gravou (e a conferência não conhece)
if [ $TEXTOS -eq 1 ]; then
  echo "Conferência dos textos fixos no $REF (${LINGUA:-zh-CN}):"
  if BASE="http://127.0.0.1:$PORTA0/" DADOS="$PASTA/$REF.dados.json" LINGUA="${LINGUA:-zh-CN}" node testes/texto-fixo.cjs > "$PASTA/texto-fixo.txt" 2>&1; then
    echo "    $(tail -1 "$PASTA/texto-fixo.txt")"
  else
    FALHOU=1; sed 's/^/    /' "$PASTA/texto-fixo.txt" | head -60
  fi
fi

if [ $LAYOUT -eq 1 ]; then
  echo "Conferência do layout no $REF:"
  if BASE="http://127.0.0.1:$PORTA0/" SAIDA="$PASTA/layout" node testes/layout.cjs; then :; else FALHOU=1; echo "    veja $PASTA/layout/agrupado.txt:"; sed 's/^/    /' "$PASTA/layout/agrupado.txt" 2>/dev/null | head -60; fi
fi

# o isolamento entre contas (testes/isolamento.php), em cada banco: a escrita das contas de teste vem depois da fotografia, da
# conferência dos campos e do layout, para não mexer neles
i=0
for b in "${BANCOS[@]}"; do
  if [ -f "$PASTA/$b.json" ]; then
    if php testes/isolamento.php "http://127.0.0.1:$((PORTA0 + i))/api.php" > "$PASTA/$b.isolamento.txt" 2>&1; then
      echo "isolamento $b: $(tail -1 "$PASTA/$b.isolamento.txt")"
    else
      FALHOU=1; echo "isolamento $b:"; sed 's/^/    /' "$PASTA/$b.isolamento.txt" | head -20
    fi
  fi
  i=$((i + 1))
done

if [ $MANTER -eq 1 ]; then
  i=0
  for b in "${BANCOS[@]}"; do echo "$b no ar: http://127.0.0.1:$((PORTA0 + i))/ (usuário teste, senha Senha@123)"; i=$((i + 1)); done
  echo "Ctrl+C derruba os servidores."
  while true; do sleep 3600; done
fi
[ $FALHOU -eq 0 ] && echo "Tudo igual." || echo "Há diferenças ou falhas."
exit $FALHOU
