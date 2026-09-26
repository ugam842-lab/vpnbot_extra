<?php

/**
 * Юнит-тест интеграции IKEv2 в transport-registry.
 *
 * Покрывает:
 *  1. normalizeTransportRegistry() включает 'ikev2' в fallback (как флаг,
 *     отключённый по умолчанию).
 *  2. getClientTransportFlags() отдаёт 'ikev2' из global и переопределяет
 *     per-subscription.
 *  3. isIkev2Enabled() верно зависит от флага (true/false).
 *  4. getIkev2Host(): приоритет pac['ikev2_host'] -> pac['domain'] -> ip.
 *
 * Запуск: php tests/test_ikev2_transport_flag.php
 * Чистый хелпер: не трогает config.php, файлы, TG.
 */

declare(strict_types=1);

$regPath = __DIR__ . '/../app/traits/TransportRegistryTrait.php';
require $regPath;
$ikev2Path = __DIR__ . '/../app/traits/Ikev2Trait.php';
require $ikev2Path;

final class Ikev2Harness
{
    use TransportRegistryTrait;
    use Ikev2Trait;

    public array $pac = [];
    public string $ip = '203.0.113.7';

    protected function getPacConf(): array
    {
        return $this->pac;
    }

    protected function getClientSubscriptionId(array $client): string
    {
        return (string) ($client['subscription_id'] ?? $client['id'] ?? '');
    }

    // Публичные обёртки над protected-методами trait'ов (для вызова из глобального скоупа).
    public function flags(array $client): array
    {
        return $this->getClientTransportFlags($client, $this->pac);
    }

    public function ikev2Enabled(array $client): bool
    {
        return $this->isIkev2Enabled($client);
    }

    public function ikev2Host(): string
    {
        return $this->getIkev2Host();
    }
}

$fails = 0;
$total = 0;

function check(string $name, bool $ok): void
{
    global $fails, $total;
    $total++;
    echo ($ok ? "ok    " : "FAIL  ") . $name . "\n";
    if (!$ok) {
        $fails++;
    }
}

// --- 1. fallback включает ikev2, по умолчанию выключен ---
$h = new Ikev2Harness();
$h->pac = [];
$flags = $h->flags(['id' => 'c1']);
check('fallback: ключ ikev2 присутствует', array_key_exists('ikev2', $flags));
check('fallback: ikev2 по умолчанию 0', ($flags['ikev2'] ?? null) === 0);

// --- 2. global включает ikev2 ---
$h2 = new Ikev2Harness();
$h2->pac = ['transport_registry' => ['global' => ['ws' => 1, 'ikev2' => 1]]];
$flags2 = $h2->flags(['id' => 'c1']);
check('global: ikev2 = 1', ($flags2['ikev2'] ?? null) === 1);

// --- 3. per-subscription override ---
$h3 = new Ikev2Harness();
$h3->pac = [
    'transport_registry' => [
        'global' => ['ws' => 1, 'ikev2' => 0],
        'users' => ['subX' => ['ikev2' => 1]],
    ],
];
$flags3 = $h3->flags(['subscription_id' => 'subX']);
check('override: ikev2 включён для subX', ($flags3['ikev2'] ?? null) === 1);
$flags3b = $h3->flags(['subscription_id' => 'subY']);
check('override: ikev2 выключен для subY', ($flags3b['ikev2'] ?? null) === 0);

// --- 4. isIkev2Enabled следует за флагом ---
$on = new Ikev2Harness();
$on->pac = ['transport_registry' => ['global' => ['ikev2' => 1]]];
check('isIkev2Enabled: true при флаге', $on->ikev2Enabled(['id' => 'c1']) === true);

$off = new Ikev2Harness();
$off->pac = [];
check('isIkev2Enabled: false без флага', $off->ikev2Enabled(['id' => 'c1']) === false);

// --- 5. getIkev2Host приоритет ---
$host1 = new Ikev2Harness();
$host1->pac = ['ikev2_host' => 'ikev2.example.com', 'domain' => 'ugam.pro'];
check('host: ikev2_host приоритетен', $host1->ikev2Host() === 'ikev2.example.com');

$host2 = new Ikev2Harness();
$host2->pac = ['domain' => 'ugam.pro'];
check('host: fallback на domain', $host2->ikev2Host() === 'ugam.pro');

$host3 = new Ikev2Harness();
$host3->pac = [];
check('host: fallback на ip', $host3->ikev2Host() === '203.0.113.7');

echo "\n{$total} tests, {$fails} failed\n";
exit($fails === 0 ? 0 : 1);
