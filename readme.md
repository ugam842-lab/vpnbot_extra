# VPNBot Extra — форк ugam842

Telegram-бот для управления VPN-сервером из Telegram.

Форк: mercurykd/vpnbot → TrimXx/vpnbot_extra → этот форк.

## Что поддерживается

- VLESS (Reality / Websocket / XHTTP)
- WireGuard / AmneziaWG
- IKEv2 (native strongSwan, EAP-MSCHAPv2)
- L2TP / IPsec (IKEv1 + XAuth + PSK)
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

## Развёртывание IKEv2 / L2TP (IPsec)

IPsec ставится нативно на хост (не в контейнер), бот ходит к нему по ssh и сам
ведёт пароли. IKEv2:

```shell
sudo IKEV2_SERVER_IP=<IP или домен> bash scripts/setup-ikev2.sh
```

Скрипт ставит strongSwan, генерит сертификат сервера, прописывает соединение и
кладёт копию сертификата туда, откуда его видит php-контейнер. L2TP/IPsec
(IKEv1 + XAuth + общий ключ):

```shell
sudo L2TP_SERVER_IP=<IP> bash scripts/setup-ikev1-l2tp.sh
```

Ставит strongSwan и xl2tpd, поднимает L2TP на 1701 и раздаёт клиентам адреса
из своей подсети. После любой из этих команд дай php-контейнеру ходить на хост
по ssh — иначе бот не сможет дописывать пароли и перезагружать strongSwan:

```shell
cat /root/vpnbot_extra/ssh/key.pub >> /root/.ssh/authorized_keys
```

Дальше бот делает остальное сам: при выдаче клиенту из портала генерит пароль
(EAP для IKEv2, общий ключ + логин/пароль для L2TP), кладёт его в config/ и по
ssh копирует в /etc/swanctl и перезагружает. Наружу должны быть открыты UDP 500,
4500 и 1701 (1701 только для L2TP). Оба скрипта можно запускать повторно —
выданные профили не сломаются.

Руководство пользователя: [docs/USER_GUIDE_RU.md](docs/USER_GUIDE_RU.md)
