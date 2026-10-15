# Развёртывание IKEv2 и L2TP/IPsec

Как поднять нативный IPsec на хосте, где крутится vpnbot: IKEv2 (strongSwan,
EAP-MSCHAPv2) и L2TP/IPsec (IKEv1 + XAuth + PSK). И как бот потом сам доводит
секреты и профили клиентов.

## Общая схема

IPsec ставится НАТИВНО на хост (не в контейнеры): strongSwan — сам charon,
xl2tpd — терминирует PPP поверх L2TP. php-контейнер бота не трогает
`/etc/swanctl` напрямую, а ходит на хост по SSH: публичный ключ контейнера
(`/ssh/key.pub`) должен лежать в `/root/.ssh/authorized_keys` на хосте, хост
доступен из контейнера как `host.docker.internal`. Бот сам генерирует секреты,
пишет их в `config/`, одной ssh-командой копирует в `/etc/swanctl/conf.d/` и
перезагружает strongSwan.

Порты, которые обязаны быть проброшены наружу (UDP): **500** (ISAKMP),
**4500** (NAT-T), **1701** (L2TP — только для L2TP/IPsec).

## IKEv2 (native strongSwan, EAP-MSCHAPv2)

```shell
sudo IKEV2_SERVER_IP=<IP или домен> bash scripts/setup-ikev2.sh
```

Переменные:

- `IKEV2_SERVER_IP` — IP или домен, которым сервер представляется клиенту
  (иначе автоопределение по первому адресу `hostname -I`).
- `IKEV2_APP_DIR` — каталог приложения на хосте, куда кладётся копия CA
  (по умолчанию `/root/vpnbot_extra`).

Что делает:

1. Ставит strongSwan (apt/dnf — по тому, что есть).
2. Генерирует CA + сертификат сервера (SAN = IP или DNS), если их ещё нет, и
   кладёт в `/etc/swanctl/x509ca/` и `/etc/swanctl/x509/`.
3. Пишет `/etc/swanctl/conf.d/ikev2.conf` — соединение `ikev2-ugam`, пул
   клиентских адресов 10.99.0.0/24, remote auth = `eap-mschapv2`.
4. Кладёт копию CA в `config/ikev2-ca.pem` — php видит её как
   `/config/ikev2-ca.pem` (путь в env `IKV2_CA_PATH`).
5. Включает и рестартует `strongswan-swanctl`.

Что НЕ делает (это секреты — их ведёт бот, `Ikev2Trait`):

- EAP-пароли клиентов. Бот пишет их в `config/ikev2-eap.conf`, затем ssh-копией
  в `/etc/swanctl/conf.d/ikev2-eap.conf` и `swanctl --load-all`. Пути можно
  переопределить env `IKEV2_EAP_HOST_SRC` / `IKEV2_EAP_HOST_DST`.

Проверка: `swanctl --stats` на хосте.

## L2TP/IPsec (IKEv1 + XAuth + PSK)

```shell
sudo L2TP_SERVER_IP=<IP> bash scripts/setup-ikev1-l2tp.sh
```

Переменные:

- `L2TP_SERVER_IP` — IP/домен, которым сервер представляется клиенту.
- `L2TP_APP_DIR` — каталог приложения (по умолчанию `/root/vpnbot_extra`).

Что делает:

1. Ставит strongSwan + xl2tpd (на apt ещё pptpd).
2. Пишет `/etc/swanctl/conf.d/ikev1.conf` — `version = 1`, XAuth + PSK,
   transport-режим только для UDP/1701.
3. Пишет `/etc/xl2tpd/xl2tpd.conf` (пул 10.98.1.0/24) и `/etc/ppp/options.xl2tpd`
   (noauth поверх готового IPsec).
4. Добавляет MASQUERADE для 10.98.1.0/24.
5. Включает и рестартует `strongswan-swanctl` + `xl2tpd`.

Что НЕ делает (секреты — бот, `L2tpTrait`):

- Общий ключ (PSK) узла и XAuth-пароли клиентов. PSK живёт в `pac['l2tp_psk']`
  (иначе детерминированный из hash бота); бот пишет `config/l2tp-secrets.conf`,
  ssh-копией в `/etc/swanctl/conf.d/l2tp-secrets.conf` и reload. Пути — env
  `L2TP_SECRETS_HOST_SRC` / `L2TP_SECRETS_HOST_DST`.

Проверка: `ss -lnup | grep -E '1701|500|4500'` на хосте.

## После установки (обязательно для обоих)

Добавь публичный ключ php-контейнера в authorized_keys root на хосте, иначе
бот не сможет писать секреты и перезагружать strongSwan:

```shell
mkdir -p /root/.ssh
cat /root/vpnbot_extra/ssh/key.pub >> /root/.ssh/authorized_keys
```

`/root/vpnbot_extra/ssh/key.pub` — тот самый ключ, что смонтирован в контейнер
как `/ssh/key.pub`; путь приложения подставь свой, если не дефолтный.

## Идемпотентность

Оба скрипта можно запускать повторно: уже выданные профили не ломаются, а CA и
сертификат не перегенерируются, пока они на месте.
