<?php

/**
 * Юнит-тесты чистых хелперов awg_limit из HwidTrait,
 * без живого бота/telegram/xray-файлов.
 *
 * HwidTrait.php подключается в лёгкий класс-заглушку, который переопределяет
 * getXray()/getPacConf() синтетическими фикстурами. Проверяем ТОЛЬКО логику:
 *   - getAwgLimit: дефолт из pac.awg_device_count, кламп <=0 -> 10, per-client
 *     awg_limit (включая 0 = безлимит), отсутствие поля -> дефолт;
 *   - getOwnerXrayClientIndexBySubId: найден / не найден / device_client пропущен.
 *
 * Запуск: php tests/test_awg_limit.php   (или php -l + искать PHP runtime)
 *
 * Не зависит от окружения: не трогает config.php, не пишет файлы, не шлёт в TG.
 */

declare(strict_types=1);

// Трейт тянет только приватные/защищённые вызовы $this->getXray/getPacConf/
// getClientSubscriptionId/isSubscriptionIdMatch — заглушка покрывает их все.
// Трейт живёт в app/traits/ (workspace) либо traits/ (в контейнере рядом с app/).
$traitPath = __DIR__ . '/../app/traits/HwidTrait.php';
if (!is_file($traitPath)) {
    $traitPath = __DIR__ . '/../traits/HwidTrait.php';
}
require $traitPath;

final class AwgLimitHarness
{
    use HwidTrait;

    /** @var array<string,mixed> */
    public array $fixtureXray;
    /** @var array<string,mixed> */
    public array $fixturePac;

    public function __construct(array $xray, array $pac)
    {
        $this->fixtureXray = $xray;
        $this->fixturePac  = $pac;
    }

    // ---- overrides used by the trait ----
    protected function getXray(): array
    {
        return $this->fixtureXray;
    }

    protected function getPacConf(): array
    {
        return $this->fixturePac;
    }

    protected function getClientSubscriptionId(array $client): string
    {
        return (string) ($client['subscription_id'] ?? ($client['id'] ?? ''));
    }

    // отдаём наружу для теста (protected -> public обёртка)
    public function callGetAwgLimit(?int $idx): int
    {
        return $this->getAwgLimit($idx);
    }

    public function callGetOwnerXrayClientIndexBySubId(string $subId): ?int
    {
        return $this->getOwnerXrayClientIndexBySubId($subId);
    }
}

function makeClient(string $id, ?string $sub = null, ?string $parent = null, ?int $awg = null): array
{
    $c = ['id' => $id];
    if ($sub !== null) {
        $c['subscription_id'] = $sub;
    }
    if ($parent !== null) {
        $c['device_parent_id'] = $parent;
    }
    if ($awg !== null) {
        $c['awg_limit'] = $awg;
    }
    return $c;
}

function makeXray(array $clients): array
{
    return ['inbounds' => [0 => ['settings' => ['clients' => $clients]]]];
}

// --- заготовка набора клиентов ---
$owner = makeClient('owner-uuid', 'sub-123', null, null);      // владелец без awg_limit
$dev   = makeClient('dev-uuid', null, 'sub-123', null);        // device-клиент (пропускается)
$with3 = makeClient('u3', 'sub-456', null, 3);                 // владелец с awg_limit=3
$with0 = makeClient('u0', 'sub-789', null, 0);                 // владелец с awg_limit=0 (безлимит)

$fails = 0;
$total = 0;

function check(string $name, bool $ok): void
{
    global $fails, $total;
    $total++;
    if (!$ok) {
        $fails++;
        echo "FAIL  {$name}\n";
    } else {
        echo "ok    {$name}\n";
    }
}

echo "== getAwgLimit: default from pac ==\n";
$a = new AwgLimitHarness(makeXray([$owner]), ['awg_device_count' => 10]);
check('default = 10', $a->callGetAwgLimit(null) === 10);

$b = new AwgLimitHarness(makeXray([$owner]), ['awg_device_count' => 5]);
check('default = 5', $b->callGetAwgLimit(null) === 5);

$c = new AwgLimitHarness(makeXray([$owner]), []);   // pac без ключа
check('default missing key = 10', $c->callGetAwgLimit(null) === 10);

$d = new AwgLimitHarness(makeXray([$owner]), ['awg_device_count' => 0]);
check('default<=0 clamped to 10', $d->callGetAwgLimit(null) === 10);

$e = new AwgLimitHarness(makeXray([$owner]), ['awg_device_count' => -3]);
check('default<0 clamped to 10', $e->callGetAwgLimit(null) === 10);

echo "== getAwgLimit: per-client override ==\n";
$f = new AwgLimitHarness(makeXray([$with3]), []);
check('client awg_limit=3 -> 3', $f->callGetAwgLimit(0) === 3);

$g = new AwgLimitHarness(makeXray([$with0]), []);
check('client awg_limit=0 -> 0 (unlimited)', $g->callGetAwgLimit(0) === 0);

$h = new AwgLimitHarness(makeXray([$owner]), ['awg_device_count' => 10]);
check('client без awg_limit -> default 10', $h->callGetAwgLimit(0) === 10);

// индекс вне границ -> дефолт
check('out-of-range idx -> default', $h->callGetAwgLimit(99) === 10);

echo "== getOwnerXrayClientIndexBySubId ==\n";
$i = new AwgLimitHarness(makeXray([$owner, $dev, $with3]), []);
check('находит owner по subscription_id', $i->callGetOwnerXrayClientIndexBySubId('sub-123') === 0);
check('находит owner по id', $i->callGetOwnerXrayClientIndexBySubId('owner-uuid') === 0);
check('находит owner с awg_limit', $i->callGetOwnerXrayClientIndexBySubId('sub-456') === 2);
check('не находит несуществующий', $i->callGetOwnerXrayClientIndexBySubId('nope') === null);
check('пустой subId -> null', $i->callGetOwnerXrayClientIndexBySubId('') === null);
check('device-клиент не владелец', $i->callGetOwnerXrayClientIndexBySubId('sub-789') === null);

// device_parent_id клиент не считается owner-ом и по своему sub не находится
$j = new AwgLimitHarness(makeXray([$dev]), []);
check('device_parent_id пропущен', $j->callGetOwnerXrayClientIndexBySubId('dev-uuid') === null);

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
