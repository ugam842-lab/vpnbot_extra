#!/bin/sh
# Гарантирует, что хостовые файлы ./bin/xray и ./bin/amneziawg-go существуют
# как ОБЫЧНЫЕ ФАЙЛЫ до `docker compose up`.
#
# Зачем. /bin/ в .gitignore («prod binaries, do not version»), поэтому на чистой
# ноде после git clone папки bin/ нет вовсе. docker-compose.yml монтирует эти
# бинарники как bind-файлы:
#     ./bin/xray:/usr/bin/xray:ro                     (сервис xr)
#     ./bin/amneziawg-go:/usr/bin/amneziawg-go:ro     (сервис wg1)
# Docker при отсутствующем источнике сам создаёт его КАТАЛОГОМ и падает:
#     "not a directory: Are you trying to mount a directory onto a file
#      (or vice-versa)?"   -> make: *** [makefile:u] Error 1
# Отключить автосоздание нельзя: с create_host_path: false compose отвечает
# "bind source path does not exist". Значит источник обязан быть файлом.
#
# Если файла нет — достаём бинарник из образа, который его собирает:
# xray.dockerfile кладёт xray в /usr/bin/xray, wireguard.dockerfile собирает
# amneziawg-go в /usr/bin/amneziawg-go. Если файл уже на месте (боевая нода) —
# шаг ничего не делает и прод не трогает.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# extract <хост-путь> <сервис compose> <шаблон образа> <путь в контейнере>
extract() {
    target="$1"
    service="$2"
    image_pattern="$3"
    src_path="$4"

    if [ -f "$target" ]; then
        return 0
    fi

    # docker мог уже создать путь каталогом — это и есть причина падения монтирования
    if [ -e "$target" ]; then
        rm -rf "$target"
    fi

    echo "[bin] $target отсутствует — собираю образ сервиса $service" >&2
    docker compose build "$service"

    image="$(docker compose config --images | grep -m1 "$image_pattern" || true)"
    if [ -z "$image" ]; then
        echo "[bin] ERROR: не удалось определить образ для сервиса $service ($image_pattern)" >&2
        exit 1
    fi

    container="$(docker create "$image")"
    mkdir -p "$(dirname "$target")"
    if ! docker cp "$container:$src_path" "$target"; then
        docker rm "$container" >/dev/null 2>&1 || true
        echo "[bin] ERROR: в образе $image нет $src_path" >&2
        exit 1
    fi
    docker rm "$container" >/dev/null

    chmod +x "$target"
    echo "[bin] $target готов ($(wc -c < "$target") байт, из $image)"
}

extract "$ROOT/bin/xray"         xr  'vpnbot-xr' '/usr/bin/xray'
extract "$ROOT/bin/amneziawg-go" wg1 'vpnbot-wg' '/usr/bin/amneziawg-go'

exit 0
