# Админская записка по серверу

Что где лежит, как обновлять и что проверять, когда «не работает».

## Где что живёт

Проект на сервере — `/root/vpnbot`. Конфиги — `config/`, код бота — `app/`, логи — `logs/`.
Правки в `app/` подхватываются на следующем запросе: бот работает через вебхук.

Контейнеры: `up` (nginx на 443, раскидывает по SNI), `ng` (nginx 80/443), `php` (вебхук бота),
`svc` (фоновые задачи), `wg1` (AmneziaWG), `xr` (xray), `hy` (hysteria),
`tg` (MTProto), `ad` (AdGuard), `wp` (WARP).

Порты, которые обязаны быть проброшены наружу: **443/tcp** (VLESS и подписка),
**443/udp** (hysteria), **51821/udp** (AmneziaWG). Если у сервиса нет проброса — он
работает только внутри и клиенты до него не дойдут.

## Как устроен VLESS

Точка входа — `up`, он смотрит SNI в TLS-рукопожатии и по нему выбирает upstream:

- `yandex.ru` → `10.10.0.9:33443` — inbound `vless_reality` (XHTTP + Reality)
- всё остальное → `10.10.0.2:443` → `ng` → inbound `vless_xhttp` (8443, XHTTP + TLS)

Оба инбаунда обслуживает один xray в контейнере `xr`. Клиентская ссылка собирается
в `linkXray()` (`app/bot.php`) и уже отдаётся как `type=xhttp`.

**Важно:** инбаунд Reality работает поверх XHTTP, а не TCP. `flow=xtls-rprx-vision`
сюда подставлять нельзя — XHTTP его не поддерживает, и сервер отвечает
`invalid request user id`. Список клиентов раскладывается по инбаундам в
`applyBothTransportInboundClients()`; там же снимается `flow` для xhttp-копий.

Серверный xray лежит в `/root/vpnbot/bin/xray` и смонтирован в контейнер `xr`
поверх `/usr/bin/xray`. Обновление — подменить файл и перезапустить `xr`.

## Как устроен AmneziaWG

Бинарник — `/root/vpnbot/bin/amneziawg-go`, смонтирован в `wg1` поверх
`/usr/bin/amneziawg-go`. Собран из `amnezia-vpn/amneziawg-go`, тег `v3.1.20260828`.

Сборка (нужен Go ≥ 1.25):

    docker run --rm -v /root/vpnbot/bin:/out golang:1.25-bookworm sh -c "
      git clone --depth 1 --branch v3.1.20260828 https://github.com/amnezia-vpn/amneziawg-go /src
      cd /src && CGO_ENABLED=0 go build -ldflags '-s -w' -o amneziawg-go . && cp amneziawg-go /out/"

`CGO_ENABLED=0` обязателен: контейнер `wg1` на Alpine (musl), динамический бинарник
там падает с «not found».

**Ловушка:** `amneziawg-go --version` в 3.x по-прежнему печатает `0.0.20250522` —
версия в выводе не обновляется. Проверять принадлежность к 3.x надо по строкам
`header_protection_key`, `HeaderProtectionCipher` и `amneziawg-go/v3/device`
в `strings` по бинарнику.

Параметры обфускации (S1–S4, H1–H4, I1–I5) генерирует `amneziaKeys()` и хранит
в `pac.json` как `wg1_amnezia_keys`. I-параметры — это уже 3.x; на 2.0 их не было.

## Автозапуск после перезагрузки

Все контейнеры объявлены с `restart: unless-stopped`, docker включён в systemd.
Отдельно проверь, что в `/root/vpnbot/.env` заполнены `IP` и `VER`: если `IP` пуст,
`setwebhook()` падает, контейнер `php` уходит в цикл перезапуска и тянет за собой
весь стек. Это уже случалось.

## Диагностика: «не подключается»

1. **Порт доходит?** `ss -lunp | grep 51821`, `nft list ruleset | grep -E "dport 443|dport 51821"`.
   Счётчики пакетов не нулевые — трафик доходит.
2. **Xray жив?** `docker logs xr`, `/root/vpnbot/logs/xray_error`. Для подробностей
   подними `loglevel` до `debug` в `config/xray.json` и перезапусти `xr`.
3. **Реальный клиент.** Проверять туннель с самого сервера бессмысленно — он видит
   локальный путь. Нужен внешний клиент: xray с socks-инбаундом и реальной ссылкой,
   либо отдельный тестовый контейнер.
4. **Доступность из России.** `check-host.net` умеет проверять TCP-порт с российских
   узлов: `curl -H "Accept: application/json" "https://check-host.net/check-tcp?host=<IP>:443"`.
   Обязательно ставь контроль (`ya.ru`) на тех же узлах, иначе не отличить блокировку
   от проблем самого узла проверки.

## Что уже ломалось

- Amnezia у всех висела на «Подключение» — у контейнера `wg1` не был проброшен
  порт 51821/udp, сервер слушал только внутри.
- Второй раз то же самое — сервер был 2.0, а клиенты 3.x; они несовместимы.
- VLESS не работал — серверный xray 25.10.15 против клиента 26.3.27, плюс попытка
  гонять Reality по TCP с `flow`, тогда как он переведён на XHTTP.
- Заглушка на главной отдавала окно с логином и паролем — пропал
  `app/webapp/override.html`, и запрос уходил в защищённую зону nginx.
