# Подключение к VPN — короткая инструкция

У тебя два способа подключения. Оба рабочие, можно держать оба сразу как запасные.

**VLESS (ссылка-подписка)** — основной. Скорость выше, настройки обновляются сами.
**AmneziaWG (файл .conf)** — второй, на случай если первый не пройдёт. Отдельный клиент.

## Что установить

**Для VLESS — Happ.** Универсальный клиент, работает на всех платформах.

- Android: https://play.google.com/store/apps/details?id=com.happproxy
- Android (APK, если Play не открывается): https://github.com/Happ-proxy/happ-android/releases/latest/download/Happ.apk
- iPhone / iPad: https://apps.apple.com/ru/app/happ-proxy-utility-plus/id6746188973
- Windows: https://github.com/Happ-proxy/happ-desktop/releases/latest/download/setup-Happ.x64.exe
- macOS / Linux: https://github.com/Happ-proxy/happ-desktop/releases

**Альтернатива для компьютера — FlClashX.**

- Windows: https://github.com/pluralplay/FlClashX/releases/latest/download/FlClashX-windows-amd64-setup.exe
- macOS (Apple Silicon): https://github.com/pluralplay/FlClashX/releases/latest/download/FlClashX-macos-arm64.dmg
- macOS (Intel): https://github.com/pluralplay/FlClashX/releases/latest/download/FlClashX-macos-amd64.dmg
- Linux: https://github.com/pluralplay/FlClashX/releases

**Для AmneziaWG — приложение AmneziaWG.** Это не то же самое, что AmneziaVPN: нам нужен именно AmneziaWG, он открывает готовый `.conf`-файл.

- Android: https://play.google.com/store/apps/details?id=org.amnezia.awg
- iPhone / iPad: https://apps.apple.com/app/amneziawg/id6478942365
- Windows: https://github.com/amnezia-vpn/amneziawg-windows-client/releases/latest

## Как подключиться

**VLESS**

1. В боте открой свой профиль и возьми ссылку-подписку.
2. Установи Happ и открой ссылку — приложение подхватит её само.
3. Нажми «Подключить».

**AmneziaWG**

1. В боте открой свой профиль и скачай файл конфигурации (`.conf`).
2. Установи AmneziaWG.
3. В приложении выбери добавление из файла и укажи скачанный `.conf`.
4. Нажми «Подключить».

## Если не работает

Сначала убедись, что приложение не старое — обнови его. Затем пересоздай конфиг: удали старый профиль и добавь свежий из бота. Настройки со временем меняются, и старый профиль может перестать подходить.

Не подключается вообще — напиши в поддержку прямо из бота, кнопка «Поддержка». Ответ придёт туда же.
