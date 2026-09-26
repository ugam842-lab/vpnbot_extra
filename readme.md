# VPNBot Extra — форк ugam842

Telegram-бот для управления VPN-сервером: VLESS + Mihomo/Clash подписки, AWG (WG1), AdGuard, MTProto, Hysteria.

Цепочка форков: [mercurykd/vpnbot](https://github.com/mercurykd/vpnbot) → [TrimXx/vpnbot_extra](https://github.com/TrimXx/vpnbot_extra) → этот форк. Доработан **ugam842 совместно с AI**.

## Русский

Telegram-бот для управления VPN-сервером из Telegram.

**Руководство пользователя (RU):** [docs/USER_GUIDE_RU.md](docs/USER_GUIDE_RU.md) — как выдавать доступ, HWID, подписки, WG/AWG и типовые сценарии без технических деталей.

**Переменные Clash-шаблонов:** [docs/CLASH_TEMPLATE_RU.md](docs/CLASH_TEMPLATE_RU.md) — плейсхолдеры `~domain~`, `~ws_path~`, порты, `auto-transports`, примеры.

### Что поддерживается

- VLESS transport registry (`Reality` / `Websocket` / `XHTTP` flags) + Mihomo/Clash подписки
- WireGuard / AmneziaWG (только WG1)
- IKEv2 (strongSwan)
- AdGuardHome
- MTProto
- Hysteria
- PAC / Rule-set
- Автоматические SSL-сертификаты

### Окружение

- Ubuntu `22.04/24.04`
- Debian `11/12`

### Установка

```shell
wget -O- https://raw.githubusercontent.com/ugam842-lab/vpnbot_extra/master/scripts/init.sh | sh -s YOUR_TELEGRAM_BOT_KEY master
```

При первом запуске создаются `.env` (из `env.defaults`), `config/` (из `config-templates/`) и `override.env`.

**Куда дальше после ввода токена.** Установка поднимает контейнеры и включает вебхук на родителе (`make u`, затем `/start` бота в Telegram). Проверьте, что бот откликается в чате. Основное меню бота — команда `/start`; первичная настройка (домен, SSL, выдача доступов) — через меню бота `config`. Автозапуск после перезагрузки сервера включите один раз: `bash scripts/install_as_service.sh` (создаёт и активирует `vpnbot.service`).

### Обновление (без токена бота)

```shell
wget -O- https://raw.githubusercontent.com/ugam842-lab/vpnbot_extra/master/scripts/init.sh | sh -s -- master
```

### Важное по обновлениям

- `init.sh` по умолчанию обновляет только `app/`, чтобы не перезаписывать ваши рабочие конфиги.
- Для полного обновления репозитория используйте:

```shell
UPGRADE_SCOPE=all wget -O- https://raw.githubusercontent.com/ugam842-lab/vpnbot_extra/master/scripts/init.sh | sh -s -- master
```

- `make u` и `update/update.sh` обновлены для безопасного сценария: подтягиваются целевые кодовые пути (`app`, `update`, `makefile`, `version`) без wipe конфигов.

### Конфигурация (важно)

- **`config/`** — только на сервере, **не в git** (живые данные, секреты).
- **`config-templates/`** — шаблоны в репозитории; при `make u` / `init.sh` скрипт `bootstrap_config.sh` создаёт недостающие файлы в `config/` без перезаписи существующих.
- **`.env`** — не в git; при первом deploy копируется из `env.defaults` (порты WG1, MTProto, IMAGE и т.д.).
- Локально можно держать полный слепок бота в `config/` — git его не видит.
- Не используйте `git add -A` без проверки; `make c` для config удалён.

### Перезапуск

```shell
make r
```

### Автозапуск после перезагрузки

```shell
crontab -e
```

Добавьте:

```text
@reboot cd /root/vpnbot_extra && make r
```

### Основные доработки в форке (TrimXx → базовый слой)

- HWID runtime mode: глобальный флаг + override на подписку.
- Ленивая миграция на модель `1 устройство = 1 device UUID`.
- Совместимость старых ссылок подписки после миграции.
- Отображение подключенных устройств и трафика по устройствам в `subscription`.
- Пароль на удаление устройства + смена/сброс пароля.
- Заголовки ответа по HWID-статусу в выдаче подписки.
- Глобальные и per-user transport флаги (`Reality` / `WS` / `XHTTP` / `Hysteria` / `AWG`).
- Многодоменная схема (`domain_main + aliases`) и SAN-сертификаты.
- Runtime AWG-профили по `device_uuid` (включая выдачу в Mihomo/Clash при наличии `device_uuid`).
- Runtime AWG операции закреплены за WG1-контекстом.
- Поддержка DNS-алиасов (DoH/DoT по `main + aliases` в меню AdGuard и в Clash `dns.nameserver`).
- Кнопка `change reality server ip/domain` меняет только клиентский Reality `server` (bridge), не трогая `xray reality dest`.
- Обновлен импорт бэкапа: `pac` восстанавливается merge-способом для совместимости старых/новых бэкапов.

### Доработки ugam842 (поверх v3-trimx)

- **Мультисервер:** parent + child-ноды; VLESS-ссылки на child-ноду в подписке (retarget `sni`/`host` для TLS-транспортов), резервные URL подписки.
- **VLESS Reality → XHTTP+Reality** для обхода ТСПУ; при выключенном Reality — XHTTP без TLS, чтобы устройство добавлялось корректно.
- **Поддержка (support):** модель «профиль → треды» — несколько тредов на профиль, открытие/закрытие тредов с обеих сторон, ответ в тред из user-portal и из `/support`; в тикете админ видит ник + имя + Telegram ID; поле контакта (TG/email/WhatsApp) в заявке.
- **Unbound-user stub:** непривязанный пользователь, написавший текст, получает заглушку «нет доступа» вместо тихого сброса.
- **Уведомления об изменении подписки** админу + команда `/update` для подписки на обновления.
- **Long-polling fallback** (`polling.php`) — режим работы без вебхука.
- **Трафик up/down раздельно** в web-подписке.
- **Иерархия админов:** только owner добавляет/удаляет админов.
- **Broadcast** всем пользователям бота.
- **Поиск конфига по имени** для админа (с привязкой к Telegram ID).
- **Фикс выделения IP** для WG/Amnezia (пул заканчивался при 128 клиентах).
- **Фикс удаления устройства** в портале.
- **AmneziaWG 3.0** на сервере.
- **IKEv2 (strongSwan):** профиль клиента по `ikev2` transport-флагу (глобальный + per-subscription override), кнопка «client ikev2 profile» в выдаче, хост из `pac['ikev2_host']` → `pac['domain']` → IP инстанса.
- **Стабильность нод** (fix 2026-09-26): входящий node-sync больше не сбрасывает локальную `node_role` в `child` — роль сохраняется как локальный ключ, родитель не «скатывается» и вебхук не отдаёт 403.
- **User-portal `/update`** роутится через портал (не падает в «auth denied»), не-админ без привязанной сессии не блокируется.
- **User-portal not-modified guard:** повторный `/update` не задваивает меню (честные ошибки редактирования по-прежнему фолбэчатся в `send`).
- **Ноды не исчезают при удалении:** кнопка «удалить» заменена на «отвязать» (detach) — нода снимается с регистрации и выключается, но остаётся в списке со своим токеном; необратимый снос вынесен в `nodePurge` с подтверждением.
- **Независимая резервная нода (вариант A):** роль `standalone` — нода обслуживает собственный вебхук и клиентов, не зависит от parent и наоборот; отказ любой стороны не каскадит. Миграция — `scratch/migrate_standalone.php`.
- **Донат-страница (`app/webapp/donate.html`, `toncoin.png`) удалена из форка** — форк полностью собственный, без доната.

### IKEv2 (strongSwan) — инструкция

IKEv2 — второй тип подключения к серверу, отдельно от VLESS/AWG. Работает через strongSwan **нативно на хосте** (не в docker), авторизация по логину/паролю (EAP-MSCHAPv2), без ключей. Профиль клиента выдаётся из бота при включённом транспорте `ikev2`.

**Для админа — развернуть strongSwan на хосте.** Один раз, от root:

```bash
sudo IKEV2_SERVER_IP=<твой-IP> bash scripts/setup-ikev2.sh
```

Скрипт идемпотентен: ставит strongSwan (apt/dnf), генерирует CA + серверный сертификат (если их нет), пишет статичный `/etc/swanctl/conf.d/ikev2.conf` (соединение + пул `10.99.0.0/24`), кладёт копию CA в `config/ikev2-ca.pem` (php-контейнер видит её как `/config/ikev2-ca.pem`) и запускает `strongswan-swanctl.service`. Приватный ключ CA и EAP-пароли **не** попадают в git — они хранятся на хосте.

**Дальше мастер — включить транспорт.** В меню транспорта подписки включи флаг `ikev2` (глобально или per-subscription). Бот начнёт генерировать EAP-пароль пользователя в `config/ikev2-eap.conf` и перезагружать strongSwan по SSH. Для этого php-контейнер должен уметь ходить на хост по SSH: добавь публичный ключ контейнера (`/ssh/key.pub`) в `authorized_keys` root'а на хосте. Пока флаг выключен — кнопки профиля не показываются.

**Для пользователя — как подключиться.** В боте появится кнопка «Профиль IKEv2», где выдаются логин, пароль, сервер и два файла профиля. По всем ОС:

- **Android** — приложение **strongSwan** (Play Store). Скачай `.sswan`, «Import VPN profile → выбранный файл», введи логин/пароль, подключись.
- **iOS / macOS** — нативный IKEv2. Открой `.mobileconfig`, подтверди установку профиля в «Настройки → Profile Downloaded», введи пароль. Альтернатива — приложение strongSwan из App Store.
- **Windows** — нативный клиент: «Параметры → Сеть → VPN → Добавить VPN-подключение», тип IKEv2, введи сервер/логин/пароль (EAP). Качать сертификат CA не нужно при EAP.
- **Linux** — нативный **strongSwan** (swanctl или ipsec) с конфигом EAP-MSCHAPv2, либо NetworkManager (`nmcli` с плагином `network-manager-strongswan`).

IKEv2 удобен как запасной канал: не зависит от VLESS/AWG, стабилен на мобильных сетях и нативно поддерживается iOS/macOS/Windows без стороннего софта.

### Roadmap (v3.x)

- Модульность `bot.php`: HWID вынесен в `HwidTrait` (~1800 строк)
- Legacy SS/OC/DNSTT/Naive: заглушки и `LegacyRemovedTrait`
- Ускорение UI: кэш pac/xray/stats, blinkmenu in-place, `ackCallback` на меню
- Подпись subscription URL (`sig` + epoch) и ротация в config
- PAC decode: только legacy serialize с `allowed_classes => false`
- Transport registry v3.1: порты WS/XHTTP/Reality в `pac.transport_registry.ports`

### Деплой v3-trimx (runbook)

1. Экспорт настроек через бот (**config → export**)
2. `git pull` (или `UPGRADE_SCOPE=all` через `init.sh` для compose)
3. `docker compose build wg1 php --no-cache`
4. `docker compose up -d --remove-orphans`
5. `bash scripts/migrate_awg2.sh` (сброс `wg1_amnezia_keys` в pac)
6. `docker compose restart php xr wg1`
7. Smoke: главное меню, подписка `?t=cl`, HWID + AWG Device в Mihomo, ручной AWG QR

### Заметка по бэкапу/восстановлению

- Экспорт включает: `pac`, `xray`, `xraystats`, `hwid`, WG1, AdGuard, сертификаты, MTProto, Hysteria.
- Импорт сохраняет новые ключи `pac` при восстановлении старых бэкапов (merge с текущими дефолтами).

### Авторы

Цепочка форков: [mercurykd/vpnbot](https://github.com/mercurykd/vpnbot) → [TrimXx/vpnbot_extra](https://github.com/TrimXx/vpnbot_extra) → этот форк. Доработки этого форка — **ugam842 совместно с AI**.

---

## English

Telegram bot for managing a VPN server directly from Telegram.

### Supported stack

- VLESS transport registry (`Reality` / `Websocket` / `XHTTP` flags) + Mihomo/Clash subscriptions
- WireGuard / AmneziaWG (WG1 only)
- IKEv2 (strongSwan)
- AdGuardHome
- MTProto
- Hysteria
- PAC / Rule-set
- Automatic SSL certificates

### Environment

- Ubuntu `22.04/24.04`
- Debian `11/12`

### Install

```shell
wget -O- https://raw.githubusercontent.com/ugam842-lab/vpnbot_extra/master/scripts/init.sh | sh -s YOUR_TELEGRAM_BOT_KEY master
```

**Next steps after the token.** Install brings up the containers and sets the webhook on the parent (`make u`, then `/start` the bot in Telegram). Confirm the bot answers in chat; primary setup (domain, SSL, access grants) is under the bot's `config` menu. Enable autostart across server reboots once: `bash scripts/install_as_service.sh` (creates and activates `vpnbot.service`).

### Upgrade (without bot token)

```shell
wget -O- https://raw.githubusercontent.com/ugam842-lab/vpnbot_extra/master/scripts/init.sh | sh -s -- master
```

### Upgrade behavior

- `init.sh` updates only `app/` by default to avoid overwriting runtime configs.
- Full-repo upgrade is still available:

```shell
UPGRADE_SCOPE=all wget -O- https://raw.githubusercontent.com/ugam842-lab/vpnbot_extra/master/scripts/init.sh | sh -s -- master
```

- `make u` and `update/update.sh` are adjusted for safer upgrades: target code paths (`app`, `update`, `makefile`, `version`) are refreshed without wiping user configs.

### Restart

```shell
make r
```

### Autostart on reboot

```shell
crontab -e
```

Add:

```text
@reboot cd /root/vpnbot_extra && make r
```

### Key fork improvements (TrimXx base layer)

- HWID runtime mode with global and per-subscription override.
- Lazy migration to `1 device = 1 device UUID`.
- Backward compatibility for legacy subscription links.
- Connected device list and per-device traffic in subscription UI.
- Device deletion password flow with change/reset support.
- HWID status response headers in subscription endpoints.
- Global and per-user transport flags (`Reality` / `WS` / `XHTTP` / `Hysteria` / `AWG`).
- Multi-domain support (`domain_main + aliases`) with SAN certificates.
- Runtime AWG profiles bound to `device_uuid` (including Mihomo/Clash output when `device_uuid` is present).
- Runtime AWG operations pinned to WG1 context.
- DNS aliases support (DoH/DoT output for `main + aliases` in AdGuard menu and Clash `dns.nameserver`).
- `change reality server ip/domain` changes only client-facing Reality `server` (bridge), without changing `xray reality dest`.
- Backup import improved: `pac` is restored via merge strategy for old/new backup compatibility.
- `/mirror` script updated (socat TCP/UDP, systemd units, install/status/restart/logs/uninstall).

### ugam842 fork improvements (on top of v3-trimx)

- **Multi-server:** parent + child nodes; child-node VLESS links in the subscription (`sni`/`host` retarget for TLS transports), backup subscription URLs.
- **VLESS Reality → XHTTP+Reality** to bypass TSPU; with Reality off — XHTTP without TLS, so the device is added correctly.
- **Support:** profile→threads model — multiple threads per profile, open/close threads from both sides, reply-in-thread from user-portal and `/support`; admin sees nick + name + Telegram ID in a ticket; contact field (TG/email/WhatsApp) on the access request.
- **Unbound-user stub:** a non-bound user typing text gets a "no access" stub instead of being silently dropped.
- **Subscription-change notifications** to admin + `/update` command to subscribe.
- **Long-polling fallback** (`polling.php`) — running without a webhook.
- **Separate up/down traffic** in the web subscription.
- **Admin hierarchy:** only the owner adds/removes admins.
- **Broadcast** to all bot users.
- **Client search by name** for admin (bound to Telegram ID).
- **IP allocation fix** for WG/Amnezia (the pool ran out at 128 clients).
- **Device deletion fix** in the portal.
- **AmneziaWG 3.0** on the server.
- **IKEv2 (strongSwan):** client profile via the `ikev2` transport flag (global + per-subscription override), a "client ikev2 profile" button in the output; host resolved from `pac['ikev2_host']` → `pac['domain']` → instance IP.
- **Node stability** (fix 2026-09-26): incoming node-sync no longer forces the local `node_role` to `child` — the role is kept as a local key, so the parent can't relapse and the webhook can't return 403.
- **User-portal `/update`** routes through the portal (no more "auth denied"); a non-admin without a bound session is no longer blocked.
- **User-portal not-modified guard:** a repeated `/update` no longer duplicates the menu (genuine edit errors still fall back to `send`).

### IKEv2 (strongSwan) — setup

IKEv2 is a second connection method to the server, separate from VLESS/AWG. It runs through strongSwan **natively on the host** (not in Docker), authenticates by login/password (EAP-MSCHAPv2), keyless. The client profile is issued from the bot when the `ikev2` transport is enabled.

**Admin — deploy strongSwan on the host.** Once, as root:

```bash
sudo IKEV2_SERVER_IP=<your-IP> bash scripts/setup-ikev2.sh
```

The script is idempotent: installs strongSwan (apt/dnf), generates a CA + server cert (if missing), writes a static `/etc/swanctl/conf.d/ikev2.conf` (connection + `10.99.0.0/24` pool), copies the CA to `config/ikev2-ca.pem` (the php container sees it as `/config/ikev2-ca.pem`) and starts `strongswan-swanctl.service`. The CA private key and EAP passwords are **not** committed — they live on the host.

**Admin — enable the transport.** In the subscription transport menu, enable the `ikev2` flag (global or per-subscription). The bot then generates the user's EAP password into `config/ikev2-eap.conf` and reloads strongSwan over SSH. For this the php container must reach the host over SSH: add the container's public key (`/ssh/key.pub`) to root's `authorized_keys` on the host. While the flag is off, the profile buttons are hidden.

**User — how to connect.** A "IKEv2 profile" button appears in the bot, giving the login, password, server and two profile files. Across OSes:

- **Android** — the **strongSwan** app (Play Store). Download the `.sswan`, "Import VPN profile → select the file", enter login/password, connect.
- **iOS / macOS** — native IKEv2. Open the `.mobileconfig`, confirm install under "Settings → Profile Downloaded", enter the password. Alternative: the strongSwan app from the App Store.
- **Windows** — native client: "Settings → Network → VPN → Add a VPN connection", type IKEv2, enter server/login/password (EAP). No CA cert download needed with EAP.
- **Linux** — native **strongSwan** (swanctl or ipsec) with an EAP-MSCHAPv2 config, or NetworkManager (`nmcli` with the `network-manager-strongswan` plugin).

IKEv2 is a useful fallback channel: independent of VLESS/AWG, stable on mobile networks, and natively supported by iOS/macOS/Windows without third-party software.

### Roadmap (v3.x)

- Split `bot.php`: HWID logic moved to `HwidTrait` (~1800 lines)
- Legacy SS/OC/DNSTT/Naive stubs via `LegacyRemovedTrait`
- UI performance: pac/xray/stats cache, in-place blinkmenu, menu `ackCallback`
- Subscription URL signing (`sig` + epoch) and rotation in config menu
- PAC decode: legacy serialize only with `allowed_classes => false`
- Transport registry v3.1: WS/XHTTP/Reality ports in `pac.transport_registry.ports`

### Backup / restore notes

- Export includes: `pac`, `xray`, `xraystats`, `hwid`, WG/WG1, AdGuard, certificates, MTProto, Hysteria, OC, Shadowsocks.
- Import now preserves newly introduced `pac` keys when restoring older backups (merge with current defaults).
