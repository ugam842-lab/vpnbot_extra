#!/usr/bin/env bash
#
# setup-ikev1-l2tp.sh — разворачивает нативный L2TP/IPsec (IKEv1 + XAuth + PSK)
# на хосте, где крутится vpnbot. Сосед strongSwan IKEv2 (setup-ikev2.sh), та же
# charon-система. Идемпотентен: повторный запуск не ломает уже выданные профили.
#
# Что делает (в порядке выполнения):
#   1. Ставит strongSwan (если ещё нет) + xl2tpd (L2TP-сервер, терминирует PPP).
#   2. Пишет статичный /etc/swanctl/conf.d/ikev1.conf (соединение version=1 + пул).
#   3. Пишет /etc/xl2tpd/xl2tpd.conf + /etc/ppp/options.xl2tpd (PPP поверх L2TP).
#   4. Даёт xl2tpd доступ к /etc/ipsec.d/... не нужен — PPP-auth идёт через strongSwan
#      (XAuth). xl2tpd работает в «no auth» режиме поверх готового IPsec.
#   5. Включает сервисы strongswan-swanctl + xl2tpd.
#
# НЕ делает (секреты, их ведёт сам бот — L2tpTrait):
#   - НЕ пишет PSK и VXAuth-пароли: их генерирует бот в config/l2tp-secrets.conf
#     и перезагружает swanctl через ssh (тот же путь, что у ikev2-eap.conf).
#
# Переменные окружения (опционально):
#   L2TP_SERVER_IP  — IP/домен, которым сервер представляется клиенту.
#   L2TP_APP_DIR    — каталог app на хосте, куда класть config/ (default: /root/vpnbot_extra).
#
# Запуск (на хосте, от root):
#   sudo L2TP_SERVER_IP=2.26.124.62 bash scripts/setup-ikev1-l2tp.sh
#
set -euo pipefail

L2TP_SERVER_IP="${L2TP_SERVER_IP:-}"
L2TP_APP_DIR="${L2TP_APP_DIR:-/root/vpnbot_extra}"

SWANCTL_DIR="/etc/swanctl"
CONF_D="${SWANCTL_DIR}/conf.d"

log() { printf '\033[1;32m[l2tp]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[l2tp]\033[0m %s\n' "$*"; }

if [[ $EUID -ne 0 ]]; then
    echo "Запусти от root: sudo bash scripts/setup-ikev1-l2tp.sh" >&2
    exit 1
fi

# --- 1. установка strongSwan + xl2tpd ----------------------------------------
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

if ! command -v xl2tpd >/dev/null 2>&1; then
    log "Устанавливаю xl2tpd…"
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update -y
        DEBIAN_FRONTEND=noninteractive apt-get install -y xl2tpd pptpd
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y xl2tpd
    else
        echo "Не знаю пакетный менеджер (нужен apt или dnf)." >&2
        exit 1
    fi
fi

mkdir -p "$CONF_D"

# --- определение IP сервера --------------------------------------------------
if [[ -z "$L2TP_SERVER_IP" ]]; then
    L2TP_SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
    if [[ -z "$L2TP_SERVER_IP" ]]; then
        echo "Не удалось определить IP сервера — передай L2TP_SERVER_IP." >&2
        exit 1
    fi
    warn "IP сервера определён автоматически: $L2TP_SERVER_IP."
fi

# --- 2. IKEv1-соединение (XAuth + PSK) --------------------------------------
cat > "$CONF_D/ikev1.conf" <<EOF
# Статичный конфиг IKEv1 (XAuth + PSK) для нативного L2TP/IPsec — генерируется
# setup-ikev1-l2tp.sh; не править вручную.
connections {
    ikev1-l2tp {
        version = 1
        # Нативный L2TP требует legacy (iOS/macOS/Windows): MODP1024 + SHA1.
        local_addrs = $L2TP_SERVER_IP
        remote_addrs = %any
        proposals = aes256-sha1-modp1024,aes128-sha1-modp1024
        rekey_time = 4h
        dpd_delay = 25s
        dpd_timeout = 120s
        fragmentation = yes
        unique = never

        # Раунд 1: PSK (сервер и клиент общий ключ).
        local {
            auth = psk
            id = $L2TP_SERVER_IP
        }
        remote {
            auth = psk
            id = %any
        }
        # Раунд 2: сервер требует XAuth (логин/пароль клиента).
        local2 {
            auth = xauth
        }
        children {
            ikev1-child {
                esp_proposals = aes256-sha1-modp1024,aes128-sha1-modp1024
                mode = transport
                # Только L2TP (UDP/1701) через established IPsec SA.
                local_ts = dynamic[17/1701]
                remote_ts = dynamic[17/1701]
                start_action = none
                rekey_time = 1h
                life_time = 90m
            }
        }
    }
}
EOF
log "Записал $CONF_D/ikev1.conf"

# --- 3. xl2tpd + PPP поверх L2TP ---------------------------------------------
mkdir -p /etc/xl2tpd /etc/ppp
cat > /etc/xl2tpd/xl2tpd.conf <<EOF
[global]
ipsec saref = yes
listen-addr = $L2TP_SERVER_IP
port = 1701
access control = no
auth file = /etc/ppp/chap-secrets

[lns default]
ip range = 10.98.1.10-10.98.1.200
local ip = 10.98.1.1
require authentication = no
ppp debug = no
pppoptfile = /etc/ppp/options.xl2tpd
length bit = yes
EOF

cat > /etc/ppp/options.xl2tpd <<'EOF'
# PPP-опции для L2TP — auth уже сделан strongSwan (XAuth), здесь noauth.
noauth
name l2tpd
noccp
mtu 1400
mru 1400
ms-dns 1.1.1.1
ms-dns 8.8.8.8
ipcp-accept-local
ipcp-accept-remote
lcp-echo-interval 30
lcp-echo-failure 4
EOF
log "Записал /etc/xl2tpd/xl2tpd.conf и /etc/ppp/options.xl2tpd"

# --- 4. маскарадинг для клиентских адресов 10.98.1.0/24 уже делается через IPsec
#       updown; добавь в iptables разово, если не rule, не убираем существующие. ---
if command -v iptables >/dev/null 2>&1; then
    iptables -t nat -C POSTROUTING -s 10.98.1.0/24 -o eth0 -j MASQUERADE 2>/dev/null || \
        iptables -t nat -A POSTROUTING -s 10.98.1.0/24 -o eth0 -j MASQUERADE
    log "Правило MASQUERADE для 10.98.1.0/24 есть."
fi

# --- 5. сервисы ---------------------------------------------------------------
systemctl enable strongswan-swanctl.service 2>/dev/null || \
    systemctl enable strongswan.service 2>/dev/null || true
# НЕ вызываем swanctl --load-all отдельно: ExecStartPost у systemd-юнита уже нагружает
# конфиги при старте, а сторонний вызов с ненулевым exit-кодом убивает сервис
# (systemd трактует провал ExecStartPost как провал всего юнита). Достаточно рестарта.
systemctl restart strongswan-swanctl.service 2>/dev/null || \
    systemctl restart strongswan.service 2>/dev/null || warn "strongswan не стартанул — глянь systemctl status strongswan."

systemctl enable xl2tpd.service 2>/dev/null || true
systemctl restart xl2tpd.service 2>/dev/null || warn "xl2tpd не стартанул — глянь systemctl status xl2tpd."

log "L2TP/IPsec готов. Проверка: ss -lnup | grep -E '1701|500|4500'."
log "SSH для php-контейнера (/ssh/key.pub) — тот же, что у IKEv2; бот сам пишет secrets и reloads."
