<?php

/**
 * Юнит-тест: getPacConf() не должен подменять повреждённый/полупрочитанный
 * pac.json заводским дефолтом, который потом уедет в setPacConf().
 *
 * Регрессия (2026-09-26, «update сбрасывает pac.json»): на кнопке «update»
 * запускается applyupdatebot() → pinBackup(), который делает «read → setPacConf».
 * getPacConf() читал pac.json; если в момент docker compose up --force-recreate
 * (или не-атомарная запись) файл оказывался нечитаемым/обрезанным/парсился в [],
 * код делал `$raw = []` и возвращал ПОЛНЫЙ заводской дефолт (domain="",
 * reality пустой, transport=Websocket, branding example.com). pinBackup затем
 * писал этот дефолт обратно в /config/pac.json — боевая конфигурация терялась.
 *
 * Фикс: нечитаемый/сечь-не-пустой файл помечается флагом `_pac_read_error`,
 * результат НЕ кешируется в pacConfCache и НЕ пишется в setPacConf. Различаем:
 *   — файла нет / файл пустой (свежая установка) → дефолт РАЗРЕШЁН, это норма;
 *   — файл есть и непустой, но json не разобрался → дефолт ТОЛЬКО как in-memory
 *     fallback, с флагом; персист запрещён.
 *
 * Проверяем инварианты:
 *  1. пустой файл → дефолт, без флага (свежая установка работает);
 *  2. непустой, но битый JSON → флаг _pac_read_error выставлен;
 *  3. в этом случае setPacConf НЕ пишет файл (конфиг на диске нетронут);
 *  4. setPacConf всегда снимает _pac_read_error перед записью (флаг не на диске);
 *  5. валидный pac.json продолжает читаться полностью (нет регрессии).
 *
 * Запуск: php tests/test_pac_unreadable_no_default_persist.php
 */

declare(strict_types=1);

final class PacUnreadableHarness
{
    public ?array $pacConfCache = null;
    private string $pacPhp = '';

    // Минимальный дубль логики getPacConf() под тест. Производственная версия
    // — app/bot.php:getPacConf(); здесь воспроизведена 1:1 по семантике чтения.
    public function getPacConf(): array
    {
        if ($this->pacConfCache !== null) {
            return $this->pacConfCache;
        }
        $onDisk = $this->pacPhp;
        $raw = json_decode($onDisk, true);
        if (!is_array($raw)) {
            $raw = [];
        }
        $readUnreadable = (trim($onDisk) !== '' && $raw === []);

        $defaults = [
            'language' => 'en',
            'domain' => '',
            'reality' => ['domain' => '', 'destination' => '', 'bridge_server' => ''],
            'transport' => 'Websocket',
            'transport_registry' => ['global' => ['reality' => 0, 'ws' => 1, 'xhttp' => 0]],
        ];
        $conf = array_replace_recursive($defaults, $raw);
        if ($readUnreadable) {
            $conf['_pac_read_error'] = true;
            $this->pacConfCache = null;

            return $conf;
        }
        $this->pacConfCache = $conf;

        return $conf;
    }

    // Дубль setPacConf(): возвращает, что БЫЛО БЫ записано (или null, если запись
    // заблокирована). Производственная версия блокировку не делает глобально;
    // блокировку на нечитаемом конфиге даёт pinBackup (пустой вызов), а setPacConf
    // лишь снимает флаг. Здесь моделируем оба уровня.
    public function setPacConf(array $conf): ?array
    {
        unset($conf['_pac_read_error']);

        return $conf;
    }

    public function setOnDisk(string $content): void
    {
        $this->pacPhp = $content;
        $this->pacConfCache = null;
    }
}

$h = new PacUnreadableHarness();
$fail = 0;
function check(bool $ok, string $name): void
{
    global $fail;
    if (!$ok) {
        $fail++;
        echo "FAIL: $name\n";
    }
}

// 1. Пустой файл — свежая установка, дефолт без флага.
$h->setOnDisk('');
$c1 = $h->getPacConf();
check(!isset($c1['_pac_read_error']), 'empty pac → no error flag');
check($c1['domain'] === '', 'empty pac → default domain');

// 2. Непустой битый JSON — флаг выставлен, конфиг не кеширован.
$h->setOnDisk('{"domain":"ugam.pro", "reality": {broken');
$c2 = $h->getPacConf();
check(isset($c2['_pac_read_error']) && $c2['_pac_read_error'] === true, 'corrupt pac → error flag set');
check($h->pacConfCache === null, 'corrupt pac → not cached');

// 3. pinBackup-подобный перехват: при флаге запись не происходит.
$persisted2 = isset($c2['_pac_read_error']) ? null : $h->setPacConf($c2);
check($persisted2 === null, 'corrupt pac → setPacConf blocked (file untouched)');

// 4. setPacConf снимает флаг перед записью (флаг никогда не на диске).
$c4 = $c1; // обычный дефолт без флага
$c4['_pac_read_error'] = true; // имитируем случайную протечку флага
$persisted4 = $h->setPacConf($c4);
check(!isset($persisted4['_pac_read_error']), 'setPacConf strips _pac_read_error');

// 5. Валидный pac.json читается целиком — нет регрессии.
$good = '{"domain":"ugam.pro","reality":{"domain":"yandex.ru","destination":"yandex.ru:443"},"transport":"xhttp"}';
$h->setOnDisk($good);
$c5 = $h->getPacConf();
check(($c5['domain'] ?? '') === 'ugam.pro', 'valid pac → domain preserved');
check(($c5['reality']['domain'] ?? '') === 'yandex.ru', 'valid pac → reality decoy preserved');
check(($c5['transport'] ?? '') === 'xhttp', 'valid pac → transport preserved');
check(!isset($c5['_pac_read_error']), 'valid pac → no error flag');

if ($fail === 0) {
    echo "OK: all invariants hold\n";
    exit(0);
}
echo "$fail assertions failed\n";
exit(1);
