<?php

/**
 * Юнит-тест интеграции L2TP/IPsec в transport-registry.
 *
 * Покрывает:
 *  1. normalizeTransportRegistry() включает 'l2tp' в fallback, выключенный по умолчанию.
 *  2. getClientTransportFlags() отдаёт 'l2tp' из global и переопределяет per-subscription.
 *  3. isL2tpEnabled() верно зависит от флага (true/false). Суффиксное зеркало isIkev2Enabled(),
 *     но на СВОЁМ флаге `l2tp`, чтобы два IPsec-соседа включались независимо.
 *  4. getL2tpHold() делегирует getIkev2Host() (единый выбор хоста с IKEv2).
 *  5. l2tpPsk(): pac['l2tp_psk'] приоритетен, иначе детерминированный fallback по getHashBot().
 *
 * Запуск: php tests/test_l2tp_transport_flag.php
 * Чистый хелпер: не трогает config.php, файлы, TG.
 */

declare(strict_types=1);

require __DIR__ . '/../app/traits/TransportRegistryTrait.php';
require __DIR__ . '/../app/traits/Ikev2Trait.php';
require __DIR__ . '/../app/traits/L2tpTrait.php';

final class L2tpHarness
{
    use TransportRegistryTrait;
    use Ikev2Trait;
    use L2tpTrait;

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

    protected function getHashBot(): string
    {
        return 'TESTHASH';
    }

    public function flags(array $client): array
    {
        return $this->getClientTransportFlags($client, $this->pac);
    }

    public function l2tpEnabled(array $client): bool
    {
        return $this->isL2tpEnabled($client);
    }

    public function l2tpHold(): string
    {
        return $this->getL2tpHold();
    }

    public function psk(): string
    {
        return $this->l2tpPsk();
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

// --- 1. fallback включает l2tp, по умолчанию выключен ---
$h = new L2tpHarness();
$h->pac = [];
$flags = $h->flags(['id' => 'c1']);
check('fallback: ключ l2tp присутствует', array_key_exists('l2tp', $flags));
check('fallback: l2tp по умолчанию 0', ($flags['l2tp'] ?? null) === 0);

// --- 2. global включает l2tp независимо от ikev2 ---
$h2 = new L2tpHarness();
$h2->pac = ['transport_registry' => ['global' => ['ws' => 1, 'ikev2' => 0, 'l2tp' => 1]]];
$flags2 = $h2->flags(['id' => 'c1']);
check('global: l2tp = 1 при ikev2 = 0 (независимость)', ($flags2['l2tp'] ?? null) === 1);

// --- 3. per-subscription override ---
$h3 = new L2tpHarness();
$h3->pac = [
    'transport_registry' => [
        'global' => ['ws' => 1, 'l2tp' => 0],
        'users' => ['subX' => ['l2tp' => 1]],
    ],
];
$flags3 = $h3->flags(['subscription_id' => 'subX']);
check('override: l2tp включён для subX', ($flags3['l2tp'] ?? null) === 1);
$flags3b = $h3->flags(['subscription_id' => 'subY']);
check('override: l2tp выключен для subY', ($flags3b['l2tp'] ?? null) === 0);

// --- 4. isL2tpEnabled следует за флагом ---
$on = new L2tpHarness();
$on->pac = ['transport_registry' => ['global' => ['l2tp' => 1]]];
check('isL2tpEnabled: true при флаге', $on->l2tpEnabled(['id' => 'c1']) === true);

$off = new L2tpHarness();
$off->pac = [];
check('isL2tpEnabled: false без флага', $off->l2tpEnabled(['id' => 'c1']) === false);

// --- 5. getL2tpHold делегирует getIkev2Host (единый выбор хоста) ---
$host1 = new L2tpHarness();
$host1->pac = ['ikev2_host' => 'ikev2.example.com', 'domain' => 'ugam.pro'];
check('hold: ikev2_host приоритетен', $host1->l2tpHold() === 'ikev2.example.com');

$host3 = new L2tpHarness();
$host3->pac = [];
check('hold: fallback на ip', $host3->l2tpHold() === '203.0.113.7');

// --- 6. l2tpPsk: pac приоритетен, fallback детерминирован ---
$psk1 = new L2tpHarness();
$psk1->pac = ['l2tp_psk' => 'my-shared-secret'];
check('psk: pac["l2tp_psk"] приоритетен', $psk1->psk() === 'my-shared-secret');

$psk2 = new L2tpHarness();
$psk2->pac = [];
$fallback = substr(hash('sha256', 'l2tp-psk:TESTHASH'), 0, 32);
check('psk: детерминированный fallback', $psk2->psk() === $fallback);
check('psk: fallback длиной 32', strlen($psk2->psk()) === 32);

// --- 7. l2tp НЕ влияет на ikev2 и наоборот (два независимых флага) ---
$iso = new L2tpHarness();
$iso->pac = ['transport_registry' => ['global' => ['l2tp' => 1]]];
check('iso: l2tp-флаг не поднимает ikev2', ($iso->flags(['id' => 'c1'])['ikev2'] ?? null) === 0);
check('iso: l2tp-флаг поднимает l2tp', ($iso->flags(['id' => 'c1'])['l2tp'] ?? null) === 1);

echo "\n{$total} tests, {$fails} failed\n";
exit($fails === 0 ? 0 : 1);
