# VPNBot Extra — форк ugam842

Telegram-бот для управления VPN-сервером из Telegram.

Форк: mercurykd/vpnbot → TrimXx/vpnbot_extra → этот форк.

## Что поддерживается

- VLESS (Reality / Websocket / XHTTP)
- WireGuard / AmneziaWG
- AdGuardHome
- MTProto
- Hysteria
- PAC / Rule-set
- автоматические SSL-сертификаты

## Окружение

Ubuntu 22.04/24.04, Debian 11/12.

## Установка

```shell
wget -O- https://raw.githubusercontent.com/ugam842-lab/vpnbot_extra/master/scripts/init.sh | sh -s YOUR_TELEGRAM_BOT_KEY master
```

## Обновление (без токена бота)

```shell
wget -O- https://raw.githubusercontent.com/ugam842-lab/vpnbot_extra/master/scripts/init.sh | sh -s -- master
```

Полное обновление репозитория: `UPGRADE_SCOPE=all` перед той же командой.

## Перезапуск

```shell
make r
```

## Автозапуск после перезагрузки

```shell
crontab -e
```

Добавьте:

```text
@reboot cd /root/vpnbot_extra && make r
```

## Что появилось в форке

- Транспорт XHTTP + Reality (обход ТСПУ)
- Hysteria
- Портал выдачи: выдача конфигов, «Вам выдано», отзыв
- Переключение админ / пользователь в портале
- Чистая установка сама достаёт бинарники (`scripts/ensure_bin.sh`)

Руководство пользователя: [docs/USER_GUIDE_RU.md](docs/USER_GUIDE_RU.md)
