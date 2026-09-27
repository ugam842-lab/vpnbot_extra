<?php

/**
 * Юнит-тест чистой логики кросс-выдачи конфигов (CrossIssueTrait).
 *
 * Покрывает:
 *  1. crossIssueWgAnchor() отдаёт ## owner_sub_id, пустую строку для бесхозного пира.
 *  2. crossIssueCountAwgByAnchor() считает пиров именно по заданному anchor.
 *  3. crossIssueAwgAvailable(): лимит 0 = безлимит; исчерпанный лимит = 0;
 *     обрезка по остатку; запрос 0/отрицательный = 0.
 *  4. crossIssueAvailable() отражает transport-флаги (awg/vless) независимо.
 *
 * Запуск: php tests/test_cross_issue.php
 * Чистый хелпер: не трогает config.php, файлы, TG.
 */

declare(strict_types=1);

require __DIR__ . '/../app/traits/CrossIssueTrait.php';
require __DIR__ . '/../app/traits/TransportRegistryTrait.php';

final class CrossIssueHarness
{
    use CrossIssueTrait;
    use TransportRegistryTrait;

    public array $pac = [];

    protected function getPacConf(): array
    {
        return $this->pac;
    }

    public function anchor(array $wg): string
    {
        return $this->crossIssueWgAnchor($wg);
    }

    public function count(array $wgs, string $subId): int
    {
        return $this->crossIssueCountAwgByAnchor($wgs, $subId);
    }

    public function available(int $existing, int $limit, int $requested): int
    {
        return $this->crossIssueAwgAvailable($existing, $limit, $requested);
    }

    public function availableFlags(array $flags): array
    {
        return $this->crossIssueAvailable($flags);
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

// --- 1. anchor ---
$h = new CrossIssueHarness();
check('anchor: отдаёт ## owner_sub_id', $h->anchor(['interface' => ['## owner_sub_id' => 'sub_9']]) === 'sub_9');
check('anchor: пустая строка для бесхозного', $h->anchor(['interface' => []]) === '');
check('anchor: без interface — пусто', $h->anchor([]) === '');

// --- 2. count по anchor ---
$h2 = new CrossIssueHarness();
$wgs = [
    ['interface' => ['## owner_sub_id' => 'sub_9']],
    ['interface' => ['## owner_sub_id' => 'sub_other']],
    ['interface' => ['## owner_sub_id' => 'sub_9']],
    ['interface' => []],
];
check('count: два пира по sub_9', $h2->count($wgs, 'sub_9') === 2);
check('count: ноль по несуществующему anchor', $h2->count($wgs, 'nope') === 0);

// --- 3. available (пределы) ---
$h3 = new CrossIssueHarness();
check('available: лимит 0 = безлимит', $h3->available(5, 0, 10) === 10);
check('available: лимит -1 = безлимит', $h3->available(5, -1, 3) === 3);
check('available: исчерпанный лимит = 0', $h3->available(10, 10, 2) === 0);
check('available: обрезка по остатку', $h3->available(7, 10, 5) === 3);
check('available: ровно хватает', $h3->available(5, 10, 5) === 5);
check('available: запрос 0 = 0', $h3->available(0, 10, 0) === 0);
check('available: отрицательный запрос = 0', $h3->available(0, 10, -3) === 0);

// --- 4. available flags ---
$h4 = new CrossIssueHarness();
$a = $h4->availableFlags(['awg' => 1, 'reality' => 1, 'ws' => 0, 'xhttp' => 0]);
check('flags: awg on + reality on → awg+vless', $a['awg'] === true && $a['vless'] === true);

$b = $h4->availableFlags(['awg' => 0, 'reality' => 0, 'ws' => 0, 'xhttp' => 0]);
check('flags: всё off → ничего', $b['awg'] === false && $b['vless'] === false);

$c = $h4->availableFlags(['awg' => 0, 'reality' => 0, 'ws' => 1, 'xhttp' => 0]);
check('flags: ws on → только vless', $c['awg'] === false && $c['vless'] === true);

echo "\n{$total} tests, {$fails} failed\n";
exit($fails === 0 ? 0 : 1);
