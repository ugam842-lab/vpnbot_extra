<?php

/**
 * Юнит-тест: Clash-подписка reality должна идти поверх XHTTP, а не TCP+Vision.
 *
 * Регрессия: серверный reality-инбаунд переведён на XHTTP+Reality
 * (network=xhttp в TransportRegistryTrait::buildXrayInboundsByRegistry), но
 * Clash-прокси долго собирался как network=tcp + flow=xtls-rprx-vision.
 * xtls-rprx-vision — TCP-only поток; на XHTTP-инбаунде сервер отвергает
 * клиента с flow. Итог: Clash-клиент reality-ветки не проходил рукопожатие,
 * хотя серверные vless-ссылки и инбаунды были корректны.
 *
 * Проверяем инварианты для флага reality (isClashAutoTransportsEnabled=true):
 *  1. reality-прокси получает network=xhttp (а не tcp);
 *  2. у него нет flow (xtls-rprx-vision не утекает в XHTTP);
 *  3. есть reality-opts (public-key/short-id) и tls=true — без них mihomo не
 *     создаст Reality-ноду;
 *  4. есть xhttp-opts.path/mode, совпадающие с серверным путём;
 *  5. ws-ветка не сломана (network=ws, никаких reality-opts);
 *  6. xhttp-ветка (обычный XHTTP+TLS) не сломана (без reality-opts).
 *
 * Запуск: php tests/test_clash_reality_xhttp.php
 */

declare(strict_types=1);

$base = is_dir(__DIR__ . '/../app/traits') ? __DIR__ . '/../app' : __DIR__ . '/..';
require $base . '/traits/TransportRuntimeTrait.php';
require $base . '/traits/TransportRegistryTrait.php';

function check(string $name, bool $ok): void
{
    global $fails, $total;
    $total++;
    echo $ok ? "ok    {$name}\n" : "FAIL  {$name}\n";
    if (!$ok) {
        $fails++;
    }
}

$fails = 0;
$total = 0;

final class RealClashHarness
{
    use TransportRuntimeTrait;
    use TransportRegistryTrait;

    public array $forcedFlags = [];

    protected function getPacConf(): array
    {
        return [];
    }

    protected function getClientTransportFlags(array $client, array $pac): array
    {
        // Игнорируем содержимое client/pac — фиксируем флаги принудительно.
        return $this->forcedFlags;
    }

    protected function getHashBot(): string
    {
        return 'test';
    }

    protected function getClientFingerprint(?array $pac = null): string
    {
        return 'chrome';
    }

    protected function getClashTransportSuffix(string $kind, ?array $pac = null): string
    {
        return ' (' . $kind . ')';
    }

    public function run(array $proxy, string $uid, string $serverHost, int $serverPort, string $shortId, string $serverName, string $publicKey): array
    {
        $c = ['proxies' => [$proxy]];
        $this->adaptClashMainProxyForTransportFlags(
            $c,
            0,
            ['id' => $uid, 'email' => 'user'],
            [],
            'ugam.pro',
            $uid,
            $serverHost,
            $serverPort,
            $shortId,
            $serverName,
            $publicKey
        );

        return $c['proxies'][0];
    }
}

$h = new RealClashHarness();
$baseProxy = [
    'name' => 'ugam.pro',
    'type' => 'vless',
    'server' => 'ugam.pro',
    'port' => 443,
    'uuid' => 'u',
];

// --- reality ---
$h->forcedFlags = ['reality' => 1, 'ws' => 0, 'xhttp' => 0, 'hysteria' => 0, 'awg' => 0, 'ikev2' => 0];
$p = $h->run($baseProxy, 'user-uuid', 'ugam.pro', 33443, 'ab12', 'yandex.ru', 'PUBKEY==');
check('reality: network=xhttp', ($p['network'] ?? '') === 'xhttp');
check('reality: нет flow (Vision убран)', !array_key_exists('flow', $p));
check('reality: tls=true', ($p['tls'] ?? false) === true);
check('reality: есть reality-opts', isset($p['reality-opts']['public-key'], $p['reality-opts']['short-id']));
check('reality: public-key передан', ($p['reality-opts']['public-key'] ?? '') === 'PUBKEY==');
check('reality: short-id передан', ($p['reality-opts']['short-id'] ?? '') === 'ab12');
check('reality: xhttp-opts.path не пуст', ($p['xhttp-opts']['path'] ?? '') !== '');
check('reality: xhttp-opts.mode задан', ($p['xhttp-opts']['mode'] ?? '') !== '');
check('reality: port=33443', ($p['port'] ?? 0) === 33443);
check('reality: servername=SNI-донар', ($p['servername'] ?? '') === 'yandex.ru');
check('reality: ws-opts отсутствует', !isset($p['ws-opts']));

// --- ws (не должны приписать reality) ---
$h->forcedFlags = ['reality' => 0, 'ws' => 1, 'xhttp' => 0, 'hysteria' => 0, 'awg' => 0, 'ikev2' => 0];
$p = $h->run($baseProxy, 'user-uuid', 'ugam.pro', 443, '', 'ugam.pro', '');
check('ws: network=ws', ($p['network'] ?? '') === 'ws');
check('ws: нет reality-opts', !isset($p['reality-opts']));
check('ws: нет flow', !array_key_exists('flow', $p));

// --- xhttp (обычный, без reality) ---
$h->forcedFlags = ['reality' => 0, 'ws' => 0, 'xhttp' => 1, 'hysteria' => 0, 'awg' => 0, 'ikev2' => 0];
$p = $h->run($baseProxy, 'user-uuid', 'ugam.pro', 443, '', 'ugam.pro', '');
check('xhttp: network=xhttp', ($p['network'] ?? '') === 'xhttp');
check('xhttp: нет reality-opts', !isset($p['reality-opts']));
check('xhttp: нет flow', !array_key_exists('flow', $p));
check('xhttp: есть xhttp-opts', isset($p['xhttp-opts']['path']));

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
