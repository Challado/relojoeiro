#!/usr/bin/env bash
# A simulação dos modos de rodízio: cada modo vivido dia a dia, com o relógio da máquina adiantado de mentira (libfaketime),
# e conferido contra as regras do README (testes/simulacao.php diz quais).
#   testes/simulacao.sh                 todos os modos
#   testes/simulacao.sh semana fifo     só os modos cujo nome tem "semana" ou "fifo"
# Num SQLite novo, numa pasta temporária, com o config de teste (testes/config.teste.php). O servidor (php -S), o cron e o
# roteiro rodam com o mesmo relógio falso: o roteiro escreve a data e a hora em $PASTA/relogio.txt e todos passam a ver
# esse instante (FAKETIME_TIMESTAMP_FILE, relido a cada leitura do relógio). Os sorteios saem da semente (RELOGIOS_SEMENTE),
# sem rede. Precisa do libfaketime (apt install faketime). Variáveis: TESTE_PORTA (padrão 8191), SIMULACAO_DIAS (os dias
# de cada modo; padrão 28).
set -u
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ"

LIB=""
for c in /usr/lib/x86_64-linux-gnu/faketime/libfaketime.so.1 /usr/lib/aarch64-linux-gnu/faketime/libfaketime.so.1 /usr/lib/faketime/libfaketime.so.1 \
  /usr/local/lib/faketime/libfaketime.so.1; do
  [ -f "$c" ] && { LIB="$c"; break; }
done
if [ -z "$LIB" ]; then
  echo "Falta o libfaketime (apt install faketime)." >&2
  exit 2
fi

PASTA="${RELOGIOS_TESTE_PASTA:-$(mktemp -d "${TMPDIR:-/tmp}/relojoeiro-simulacao.XXXXXX")}"
mkdir -p "$PASTA"
PORTA="${TESTE_PORTA:-8191}"
export RELOGIOS_TESTE_PASTA="$PASTA"
export RELOGIOS_CONFIG="$RAIZ/testes/config.teste.php"
export RELOGIOS_DB=sqlite
export RELOGIOS_SEMENTE=42
# o relógio falso: o fuso da coleção (o do config de teste), e o instante do arquivo, relido a cada vez (FAKETIME_NO_CACHE);
# o relógio monotônico (os tempos de espera do curl e do PHP) continua o de verdade
export TZ=America/Sao_Paulo
export FAKETIME_TIMESTAMP_FILE="$PASTA/relogio.txt"
export FAKETIME_NO_CACHE=1
export FAKETIME_DONT_FAKE_MONOTONIC=1
# o começo: uma segunda-feira, longe de hoje (nada do resultado depende do dia em que o teste roda)
echo "2027-01-04 05:00:00" > "$FAKETIME_TIMESTAMP_FILE"
export LD_PRELOAD="$LIB"

SERVIDOR=""
derrubar() {
  [ -n "$SERVIDOR" ] && kill "$SERVIDOR" 2>/dev/null
}
trap derrubar EXIT
trap 'exit 130' INT TERM

echo "Pasta da rodada: $PASTA"
rm -f "$PASTA/sqlite.sqlite"*
if ! php instalar.php > "$PASTA/instalacao.log" 2>&1; then
  echo "A instalação falhou:" >&2
  tail -5 "$PASTA/instalacao.log" >&2
  exit 1
fi
if curl -s -o /dev/null "http://127.0.0.1:$PORTA/"; then
  echo "A porta $PORTA já está ocupada (outro servidor no ar?): use TESTE_PORTA para escolher outra" >&2
  exit 1
fi
php -S "127.0.0.1:$PORTA" -t "$RAIZ" > "$PASTA/servidor.log" 2>&1 &
SERVIDOR=$!
n=0
until curl -s -o /dev/null "http://127.0.0.1:$PORTA/api.php"; do
  n=$((n + 1))
  [ $n -gt 100 ] && { echo "O servidor não subiu" >&2; exit 1; }
  sleep 0.1
done
# o primeiro usuário, pela API, com o token (como numa instalação de verdade)
curl -s -H "X-Api-Token: token-de-teste-123" --data-urlencode recurso=usuarios --data-urlencode acao=salvar --data-urlencode login=teste \
  --data-urlencode senha=Senha@123 --data-urlencode senha_confirmacao=Senha@123 "http://127.0.0.1:$PORTA/api.php" > "$PASTA/usuario.log" 2>&1

php testes/simulacao.php "http://127.0.0.1:$PORTA/api.php" "$PASTA" "$@"
res=$?
# os avisos do PHP no servidor também contam
avisos=$(grep -E "PHP (Warning|Notice|Deprecated|Fatal)" "$PASTA/servidor.log" | head -10)
if [ -n "$avisos" ]; then
  echo "O PHP deu avisos durante a simulação:"
  echo "$avisos" | sed 's/^/    /'
  res=1
fi
exit $res
