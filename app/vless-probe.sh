#!/bin/sh
# vless-probe.sh — честная проверка VLESS-ключа реальным xray-клиентом.
# Живёт в контейнере xr. Принимает vless:// URI как единственный аргумент,
# строит клиентский xray.json, поднимает туннель, гоняет реальный трафик.
# На успехе печатает: EXIT_IP=<ip> и CLOUDFLARE=<http code>.
# Иначе печатает причину человекопонятной строкой.

set -u

URI="$1"
XRAY=$(command -v xray || echo /usr/local/bin/xray)
WORK=$(mktemp -d /tmp/vless-probe.XXXXXX)
CFG="$WORK/client.json"
LOG="$WORK/run.log"
SOCKS_PORT=10808
HTTP_PORT=10080
PID=""
trap 'cleanup' EXIT INT TERM

cleanup() {
  [ -n "$PID" ] && kill "$PID" 2>/dev/null
  rm -rf "$WORK"
}
die() { echo "$1"; exit 0; }

# --- парсинг vless://uuid@host:port?params#name ---
BODY="${URI#vless://}"
FRAG="${BODY##*#}"
[ "$FRAG" = "$BODY" ] && FRAG=""
[ -n "$FRAG" ] && BODY="${BODY%#*}"

QP=""
case "$BODY" in
  *\?*) QP="${BODY#*\?}"; BODY="${BODY%%\?*}" ;;
esac

UUID="${BODY%%@*}"
HOSTPORT="${BODY#*@}"
HOST="${HOSTPORT%%:*}"
PORT="${HOSTPORT##*:}"
case "$PORT" in
  ''|*[!0-9]*) PORT=443 ;;
esac

get() {
  _k="$1"; _rest="$QP"
  while [ -n "$_rest" ]; do
    _tok="${_rest%%&*}"
    case "$_tok" in
      "$_k="*) printf '%s' "${_tok#$_k=}"; return 0 ;;
    esac
    case "$_rest" in
      *\&*) _rest="${_rest#*&}" ;;
      *) break ;;
    esac
  done
  return 1
}

SECURITY=$(get security); SECURITY="${SECURITY:-tls}"
SNI=$(get sni);          SNI="${SNI:-$HOST}"
FP=$(get fp);            FP="${FP:-chrome}"
NET=$(get type);         NET="${NET:-tcp}"
MODE=$(get mode);        MODE="${MODE:-auto}"
FLOW=$(get flow)
PBK=$(get pbk)
SID=$(get sid)

# KILL_MODE перезаписывает mode из URI — критично для xhttp через ng http/1.1.
[ -n "${KILL_MODE:-}" ] && MODE="$KILL_MODE"

urldec() { printf '%s' "$1" | sed 's/%20/ /g; s/%2F/\//g'; }
PATHQ=$(urldec "$(get path)")
SNI=$(urldec "$SNI")

[ -n "$UUID" ] || die "no uuid"
[ -n "$HOST" ] || die "no host"

# --- сборка клиентского xray.json ---
# streamSettings.security должно быть "reality"/"tls", а realitySettings жить
# внутри streamSettings (не в общем блоке security). Собираем аккуратно.

STREAM_SECURITY="$SECURITY"
STREAM_EXTRA=""

case "$SECURITY" in
  reality)
    STREAM_EXTRA=",\"realitySettings\":{\"fingerprint\":\"$FP\",\"serverName\":\"$SNI\",\"publicKey\":\"$PBK\",\"shortId\":\"$SID\",\"spiderX\":\"/\"}"
    ;;
  tls)
    STREAM_EXTRA=",\"tlsSettings\":{\"serverName\":\"$SNI\",\"fingerprint\":\"$FP\"}"
    ;;
esac

NET_EXTRA=""; NET_NAME="$NET"
case "$NET" in
  xhttp) NET_EXTRA=",\"xhttpSettings\":{\"path\":\"$PATHQ\",\"mode\":\"$MODE\"}" ;;
  ws)    NET_EXTRA=",\"wsSettings\":{\"path\":\"$PATHQ\"}" ;;
esac

FLOW_LINE=""
[ -n "$FLOW" ] && FLOW_LINE=",\"flow\":\"$FLOW\""

cat > "$CFG" <<EOF
{
  "log": {"loglevel": "warning", "access": "$LOG"},
  "inbounds": [
    {"tag": "socks", "port": $SOCKS_PORT, "listen": "127.0.0.1", "protocol": "socks", "settings": {"udp": true}},
    {"tag": "http", "port": $HTTP_PORT, "listen": "127.0.0.1", "protocol": "http"}
  ],
  "outbounds": [
    {
      "tag": "proxy",
      "protocol": "vless",
      "settings": {
        "vnext": [{
          "address": "$HOST",
          "port": $PORT,
          "users": [{"id": "$UUID", "encryption": "none"$FLOW_LINE}]
        }]
      },
      "streamSettings": {
        "network": "$NET_NAME",
        "security": "$STREAM_SECURITY"$STREAM_EXTRA$NET_EXTRA
      }
    },
    {"tag": "direct", "protocol": "freedom"}
  ]
}
EOF

# валидация конфига
if ! "$XRAY" run -config "$CFG" -test >/dev/null 2>&1; then
  "$XRAY" run -config "$CFG" -test 2>&1 | tail -3
  die "config invalid"
fi

# запуск клиента
"$XRAY" run -config "$CFG" >/dev/null 2>&1 &
PID=$!
sleep 1

# реальный трафик через туннель. BusyBox wget в образе xr не умеет ходить по
# https_proxy (CONNECT), а curl нет. Используем openssl s_client -proxy
# (полный openssl 3.x в образе): он сам делает CONNECT через http-прокси
# и печатает ответ — стабильно берём и exit-IP, и HTTP-код.
PROXY="127.0.0.1:$HTTP_PORT"

EXIT_IP=""
CF_CODE=""

# IP — один проход, берём первое тело ответа (ipify отдаёт IP строкой).
EXIT_IP=$(printf 'GET / HTTP/1.0\r\nHost: api.ipify.org\r\n\r\n' \
   | timeout 6 openssl s_client -connect api.ipify.org:443 -proxy "$PROXY" \
        -servername api.ipify.org -quiet 2>/dev/null \
   | tr -d '\r' | awk 'BEGIN{RS="\r\n\r\n"} NR==1{next} {print; exit}')

CF_CODE=$(printf 'HEAD / HTTP/1.0\r\nHost: cp.cloudflare.com\r\n\r\n' \
   | timeout 6 openssl s_client -connect cp.cloudflare.com:443 -proxy "$PROXY" \
        -servername cp.cloudflare.com -quiet 2>/dev/null \
   | tr -d '\r' | grep -m1 -iE '^HTTP/' | awk '{print $2}')
CF_CODE="${CF_CODE:-0}"

if [ -n "$EXIT_IP" ]; then
  echo "EXIT_IP=$EXIT_IP"
  echo "CLOUDFLARE=${CF_CODE:-0}"
  exit 0
fi

# не вышло — смотрим лог на причину
if [ -s "$LOG" ]; then
  tail -8 "$LOG"
else
  die "no tunnel traffic"
fi
