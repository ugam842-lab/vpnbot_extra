#!/bin/sh
# Bring up AmneziaWG 2.0 in userspace (amneziawg-go). Robust against the
# "Failed to create TUN device: device or resource busy" collision that
# happens when a previous daemon (orphaned by `&` inside a one-shot sh) is
# still holding the TUN device at the moment a new daemon tries to bind.
#
# Failures we guard against:
#   1. Lingering amneziawg-go holding the TUN fd  -> kill it and WAIT for
#      it to exit before launching, retry the bind if still busy.
#   2. Daemon not ready when `awg setconf` runs (90 peers) -> poll readiness
#      instead of a blind `sleep 1`.
set -eu

INTERFACE="${1:-wg0}"
CONFIG="/etc/amnezia/amneziawg/${INTERFACE}.conf"
if [ ! -f "$CONFIG" ]; then
    CONFIG="/etc/wireguard/${INTERFACE}.conf"
fi
if [ ! -f "$CONFIG" ]; then
    echo "awg_up: config not found for $INTERFACE" >&2
    exit 1
fi

# Kill every amneziawg-go that manages $INTERFACE and wait until they are
# gone, so their TUN device is released before we bind our own.
kill_daemons() {
    if command -v pkill >/dev/null 2>&1; then
        pkill -f "amneziawg-go.*-f.*${INTERFACE}" 2>/dev/null || true
    fi
    # Wait up to ~5s for the daemon(s) to release the TUN.
    i=0
    while pgrep -f "amneziawg-go.*-f.*${INTERFACE}" >/dev/null 2>&1; do
        i=$((i+1))
        if [ "$i" -ge 50 ]; then
            # Force-kill if it won't die gracefully.
            pkill -9 -f "amneziawg-go.*-f.*${INTERFACE}" 2>/dev/null || true
            break
        fi
        sleep 0.1
    done
}

awg_down() {
    awg-quick down "$INTERFACE" 2>/dev/null || true
    kill_daemons
    ip link del "$INTERFACE" 2>/dev/null || true
}

use_userspace() {
    [ -f /.dockerenv ] || [ "${AWG_FORCE_USERSPACE:-1}" = "1" ]
}

awg_down

if use_userspace; then
    if ! command -v amneziawg-go >/dev/null 2>&1; then
        echo "awg_up: amneziawg-go not found" >&2
        exit 1
    fi

    # Launch the daemon with retry. The TUN device can still be momentarily
    # busy right after kill_daemons (the kernel frees it asynchronously), so
    # give it a few attempts before giving up.
    go_pid=""
    attempt=0
    while [ "$attempt" -lt 20 ]; do
        attempt=$((attempt+1))
        WG_PROCESS_FOREGROUND=1 amneziawg-go -f "$INTERFACE" &
        go_pid=$!
        # Wait up to ~3s for the daemon to either die (bind failed) or stay
        # alive (bind succeeded) and become ready.
        ready=0
        j=0
        while [ "$j" -lt 30 ]; do
            if ! kill -0 "$go_pid" 2>/dev/null; then
                break   # died: "device or resource busy", retry the launch
            fi
            # Readiness: once the daemon accepts UAPI we can setconf reliably.
            if awg show "$INTERFACE" >/dev/null 2>&1; then
                ready=1
                break
            fi
            j=$((j+1))
            sleep 0.1
        done
        if [ "$ready" -eq 1 ]; then
            break
        fi
        # Not ready and possibly still colliding: reap the pid and retry.
        kill "$go_pid" 2>/dev/null || true
        wait "$go_pid" 2>/dev/null || true
        sleep 0.5
        go_pid=""
    done

    if [ -z "$go_pid" ] || ! kill -0 "$go_pid" 2>/dev/null; then
        echo "awg_up: amneziawg-go failed to start after retries" >&2
        exit 1
    fi

    # Apply the full config. setconf is also retried once because the UAPI
    # socket can take a beat to settle even after `awg show` responds.
    if ! awg-quick strip "$INTERFACE" | awg setconf "$INTERFACE" /dev/stdin 2>/dev/null; then
        sleep 1
        awg-quick strip "$INTERFACE" | awg setconf "$INTERFACE" /dev/stdin
    fi

    while IFS= read -r line; do
        key="${line%%=*}"
        key="$(echo "$key" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        value="${line#*=}"
        value="$(echo "$value" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        case "$key" in
            Address)
                for addr in $(echo "$value" | tr ',' ' '); do
                    addr="$(echo "$addr" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
                    [ -z "$addr" ] && continue
                    if echo "$addr" | grep -q ':'; then
                        ip -6 address add "$addr" dev "$INTERFACE" 2>/dev/null || true
                    else
                        ip -4 address add "$addr" dev "$INTERFACE" 2>/dev/null || true
                    fi
                done
                ;;
            MTU)
                ip link set mtu "$value" dev "$INTERFACE" 2>/dev/null || true
                ;;
        esac
    done <<EOF
$(grep -E '^(Address|MTU)[[:space:]]*=' "$CONFIG" || true)
EOF
    ip link set up dev "$INTERFACE"

    # Confirm the interface is really up so awg_up.sh exits clean rather than
    # leaving a half-brought-up device that bot.php reports as "красный флажок".
    if ! ip link show "$INTERFACE" 2>/dev/null | grep -q 'state UP'; then
        ip link set up dev "$INTERFACE" 2>/dev/null || true
    fi
else
    awg-quick up "$INTERFACE"
fi
