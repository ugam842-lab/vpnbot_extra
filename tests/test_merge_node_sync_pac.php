<?php

/**
 * Юнит-тест mergeNodeSyncPac(): входящий sync не должен сбрасывать
 * локальную роль ноды в 'child'. Роль — локальный ключ, назначается
 * только на join/repair, а не на приёме sync с другой ноды.
 *
 * Регрессия: parent (ugam.pro / 2.26.124.62) при входящем sync безусловно
 * перекатывался в child — короткое замыкание на авторизацию /webhook.
 *
 * Запуск: php tests/test_merge_node_sync_pac.php
 * Чистый хелпер: не трогает config.php, файлы, TG.
 */

declare(strict_types=1);

// Трейт живёт в app/traits/ (workspace) либо traits/ (в контейнере рядом с app/).
$traitPath = __DIR__ . '/../app/traits/NodeTrait.php';
if (!is_file($traitPath)) {
    $traitPath = __DIR__ . '/../traits/NodeTrait.php';
}
require $traitPath;

final class MergePacHarness
{
    use NodeTrait;

    public function callMergeNodeSyncPac(array $current, array $incoming): array
    {
        return $this->mergeNodeSyncPac($current, $incoming);
    }
}

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

$h = new MergePacHarness();

// 1. parent остаётся parent при входящем sync, где pac вообще без node_role.
$r = $h->callMergeNodeSyncPac(
    ['node_role' => 'parent', 'domain' => 'ugam.pro', 'key_a' => 1],
    ['domain' => 'other.pro', 'key_b' => 2]
);
check('parent stays parent (incoming has no role)', ($r['node_role'] ?? null) === 'parent');

// 2. parent остаётся parent, даже если incoming явно шлёт child.
$r = $h->callMergeNodeSyncPac(
    ['node_role' => 'parent', 'domain' => 'ugam.pro'],
    ['node_role' => 'child', 'domain' => 'other.pro']
);
check('parent stays parent (incoming forces child)', ($r['node_role'] ?? null) === 'parent');

// 3. child остаётся child.
$r = $h->callMergeNodeSyncPac(
    ['node_role' => 'child', 'domain' => 'ru.ugam.pro'],
    ['domain' => 'parent.pro']
);
check('child stays child', ($r['node_role'] ?? null) === 'child');

// 4. локальный домен НЕ затирается входящим (local keys win).
$r = $h->callMergeNodeSyncPac(
    ['node_role' => 'parent', 'domain' => 'ugam.pro', 'node_id' => 'shared-id'],
    ['domain' => 'incoming.example', 'node_id' => 'incoming-override']
);
check('local domain wins over incoming', ($r['domain'] ?? null) === 'ugam.pro');
check('local node_id wins over incoming', ($r['node_id'] ?? null) === 'shared-id');

// 5. не-локальный ключ из incoming применяется поверх current.
$r = $h->callMergeNodeSyncPac(
    ['node_role' => 'parent', 'some_shared_flag' => 'old'],
    ['some_shared_flag' => 'new', 'extra' => 42]
);
check('non-local key is overridden by incoming', ($r['some_shared_flag'] ?? null) === 'new');
check('new incoming key is added', ($r['extra'] ?? null) === 42);

// 6. fresh-нода (без локальной роли) берёт роль из incoming — это штатный
//    путь первой инициализации child до join; фикс не должен это ломать.
$r = $h->callMergeNodeSyncPac(
    ['domain' => 'fresh'],
    ['node_role' => 'child']
);
check('fresh node adopts role from incoming (child)', ($r['node_role'] ?? null) === 'child');

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
