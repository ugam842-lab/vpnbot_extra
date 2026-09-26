#!/usr/bin/env bash
#
# setup-ikev2.sh — разворачивает strongSwan (IKEv2/EAP-MSCHAPv2) НАТИВНО на хосте,
# где крутится vpnbot. Идемпотентен: повторный запуск не ломает уже выданные
# профили и не перегенерирует CA без явной просьбы.
#
# Что делает (в порядке выполнения):
#   1. Ставит strongSwan (apt/dnf — по тому, что есть).
#   2. Генерирует CA + сертификат сервера (если их ещё нет) и кладёт в
#      /etc/swanctl/x509ca/ca.pem, /etc/swanctl/x509ca/ca.srl,
#      /etc/swanctl/x509/server.pem.
#   3. Пишет статичную /etc/swanctl/conf.d/ikev2.conf (соединение + пул адресов).
#   4. Кладёт копию CA в каталог app-конфига на хосте (config/ikev2-ca.pem) —
#      её php-контейнер видит как /config/ikev2-ca.pem (IKV2_CA_PATH).
#   5. Включает и запускает strongswan-swanctl.service.
#
# НЕ делает (настраивается/сохраняется отдельно, это секреты):
#   - НЕ пишет EAP-пароли пользователей: их генерирует сам бот (Ikev2Trait) в
#     config/ikev2-eap.conf и перезагружает через ssh.
#   - НЕ кладёт приватный ключ CA в git и не включит его в этот репозиторий.
#
# Переменные окружения (опционально):
#   IKEV2_SERVER_IP   — IP/домен, которым сервер представляется клиенту
#                       (default: автоопределение по первому внешнему IP).
#   IKEV2_APP_DIR     — каталог app на хосте, куда класть config/ikev2-ca.pem
#                       (default: /root/vpnbot_extra).
#
# Запуск (на хосте, от root):
#   sudo IKEV2_SERVER_IP=2.26.124.62 bash scripts/setup-ikev2.sh
#
set -euo pipefail

IKEV2_SERVER_IP="${IKEV2_SERVER_IP:-}"
IKEV2_APP_DIR="${IKEV2_APP_DIR:-/root/vpnbot_extra}"

SWANCTL_DIR="/etc/swanctl"
CONF_D="${SWANCTL_DIR}/conf.d"
X509_DIR="${SWANCTL_DIR}/x509"
X509CA_DIR="${SWANCTL_DIR}/x509ca"

log() { printf '\033[1;32m[ikev2]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[ikev2]\033[0m %s\n' "$*"; }

if [[ $EUID -ne 0 ]]; then
    echo "Запусти от root: sudo bash scripts/setup-ikev2.sh" >&2
    exit 1
fi

# --- 1. установка strongSwan -------------------------------------------------
if ! command -v swanctl >/dev/null 2>&1 || ! command -v ipsec >/dev/null 2>&1; then
    log "Устанавливаю strongSwan…"
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update -y
        DEBIAN_FRONTEND=noninteractive apt-get install -y strongswan strongswan-swanctl \
            libcharon-extra-plugins libcharon-extauth-plugins
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y strongswan
    else
        echo "Не знаю пакетный менеджер (нужен apt или dnf)." >&2
        exit 1
    fi
fi

mkdir -p "$CONF_D" "$X509_DIR" "$X509CA_DIR"

# --- определение IP сервера --------------------------------------------------
if [[ -z "$IKEV2_SERVER_IP" ]]; then
    # Первый отвечающий внешний IP; fallback на вывод `hostname -I`.
    IKEV2_SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
    if [[ -z "$IKEV2_SERVER_IP" ]]; then
        echo "Не удалось определить IP сервера — передай IKEV2_SERVER_IP." >&2
        exit 1
    fi
    warn "IP сервера определён автоматически: $IKEV2_SERVER_IP (передай IKEV2_SERVER_IP, если это неверно)."
fi

# --- 2. CA + сертификат сервера ----------------------------------------------
if [[ ! -s "$X509CA_DIR/ca.pem" || ! -s "$X509_DIR/server.pem" ]]; then
    log "Генерирую CA и сертификат сервера…"
    TMP="$(mktemp -d)"
    trap 'rm -rf "$TMP"' EXIT

    # CA
    openssl genrsa -out "$TMP/ca.key" 2048
    openssl req -x509 -new -nodes -key "$TMP/ca.key" -sha256 -days 3650 \
        -subj "/CN=ugam.pro IKEv2 CA" -out "$X509CA_DIR/ca.pem"

    # Серверный ключ + CSR + подпись CA. SAN = IP и (если есть) DNS-имя.
    openssl genrsa -out "$TMP/server.key" 2048
    openssl req -new -key "$TMP/server.key" -subj "/CN=$IKEV2_SERVER_IP" -out "$TMP/server.csr"
    SAN="IP:$IKEV2_SERVER_IP"
    if [[ "$IKEV2_SERVER_IP" =~ [A-Za-z] ]]; then
        SAN="DNS:$IKEV2_SERVER_IP"
    fi
    cat > "$TMP/ext.cnf" <<EOF
subjectAltName=$SAN
keyUsage=digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
EOF
    openssl x509 -req -in "$TMP/server.csr" -CA "$X509CA_DIR/ca.pem" \
        -CAkey "$TMP/ca.key" -CAcreateserial -CAserial "$X509CA_DIR/ca.srl" \
        -days 3650 -sha256 -extfile "$TMP/ext.cnf" -out "$X509_DIR/server.pem"

    chmod 600 "$X509_DIR/server.pem"
    log "Сертификат сервера: $X509_DIR/server.pem (SAN: $SAN)"
else
    log "CA и сертификат сервера уже есть — не перегенерирую."
fi

# --- 3. статичный конфиг соединения ------------------------------------------
cat > "$CONF_D/ikev2.conf" <<EOF
# Статичный конфиг IKEv2 — генерируется setup-ikev2.sh; не править вручную.
connections {
    ikev2-ugam {
        version = 2
        proposals = aes256-sha256-modp2048,aes128-sha256-modp2048
        local_addrs = %any
        remote_addrs = %any
        rekey_time = 4h
        dpd_delay = 25s
        dpd_timeout = 120s
        fragmentation = yes
        unique = replace
        pools = ikev2-pool

        local {
            auth = pubkey
            certs = server.pem
            id = $IKEV2_SERVER_IP
        }
        remote {
            auth = eap-mschapv2
            eap_id = %any
        }
        children {
            ikev2-child {
                local_ts = 0.0.0.0/0
                esp_proposals = aes256-sha256-modp2048,aes128-sha256-modp2048
                mode = tunnel
                start_action = trap
                rekey_time = 1h
                life_time = 90m
            }
        }
    }
}

pools {
    ikev2-pool {
        addrs = 10.99.0.0/24
        dns = 1.1.1.1, 8.8.8.8
    }
}
EOF
log "Записал $CONF_D/ikev2.conf"

# --- 4. копия CA в каталог app (для php-контейнера) -------------------------
APP_CONF="${IKEV2_APP_DIR}/config"
mkdir -p "$APP_CONF"
cp "$X509CA_DIR/ca.pem" "$APP_CONF/ikev2-ca.pem"
chmod 644 "$APP_CONF/ikev2-ca.pem"
log "Копия CA → $APP_CONF/ikev2-ca.pem (видна php как /config/ikev2-ca.pem)."

# --- 5. сервис ---------------------------------------------------------------
systemctl enable strongswan-swanctl.service 2>/dev/null || \
    systemctl enable strongswan.service 2>/dev/null || true
systemctl restart strongswan-swanctl.service 2>/dev/null || \
    systemctl restart strongswan.service 2>/dev/null || true
swanctl --load-all 2>&1 | sed 's/^/[swanctl] /' || warn "swanctl --load-all вернул ошибку — глянь вывод выше."

log "IKEv2 готов. Проверка статуса: swanctl --stats"
log "SSH для php-контейнера (/ssh/key.pub) добавь в authorized_keys на хосте, чтобы бот мог перезагружать EAP."
