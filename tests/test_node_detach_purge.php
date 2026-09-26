<?php

/**
 * Юнит-тест модели «независимая резервная нода» (вариант A).
 *
 * Покрывает:
 *  1. nodeDelete() больше НЕ стирает ноду — только отвязывает
 *     (registered=false, enabled=false, detach_at), token сохраняется.
 *  2. nodePurge() необратимо удаляет ноду вместе с token.
 *  3. isStandaloneNode() различает роль 'standalone' от parent/child.
 *  4. isChildNode()/isParentNode() для standalone: не child → обслуживает
 *     свой webhook (не 403), и не тянет cluster (child_nodes пуст).
 *
 * Запуск: php tests/test_node_detach_purge.php
 * Чистый хелпер: не трогает config.php, файлы, TG.
 */

declare(strict_types=1);

$traitPath = __DIR__ . '/../app/traits/NodeTrait.php';
if (!is_file($traitPath)) {
    $traitPath = __DIR__ . '/../traits/NodeTrait.php';
}
require $traitPath;

final class DetachHarness
{
    use NodeTrait;

    public array $pac = [];
    public array $input = ['chat' => 111, 'message_id' => 0, 'callback' => false];

    protected function getPacConf(): array
    {
        return $this->pac;
    }

    protected function setPacConf(array $pac): void
    {
        $this->pac = $pac;
    }

    // nodeDelete/nodePurge при удачной операции фолбэчат на view/nodes,
    // которые тянут полноценный replyMenu — глушим их.
    protected function nodeView(string $nodeId, int $page = 0, ?array $syncResult = null, string $resultLabel = 'nodes_sync_result'): void
    {
        $this->lastView = $nodeId;
    }

    protected function nodes($page = 0): void
    {
        $this->lastNodes = true;
    }

    public string $lastView = '';
    public bool $lastNodes = false;
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

$tok = bin2hex(random_bytes(32));

// --- 1. detach сохраняет ноду и token ---
$h = new DetachHarness();
$h->pac = ['child_nodes' => [
    'n1' => ['name' => 'ru', 'domain' => 'ru.ugam.pro', 'token' => $tok, 'enabled' => true, 'registered' => true],
]];
$h->nodeDelete('n1');
check('detach: нода осталась в child_nodes', isset($h->pac['child_nodes']['n1']));
check('detach: registered=false', ($h->pac['child_nodes']['n1']['registered'] ?? null) === false);
check('detach: enabled=false', ($h->pac['child_nodes']['n1']['enabled'] ?? null) === false);
check('detach: token сохранён', ($h->pac['child_nodes']['n1']['token'] ?? null) === $tok);
check('detach: detached_at проставлен', isset($h->pac['child_nodes']['n1']['detached_at']));

// --- 2. purge стирает ноду с token ---
$h2 = new DetachHarness();
$h2->pac = ['child_nodes' => [
    'n1' => ['name' => 'ru', 'domain' => 'ru.ugam.pro', 'token' => $tok, 'enabled' => true, 'registered' => true],
]];
$h2->nodePurge('n1');
check('purge: нода удалена', empty($h2->pac['child_nodes']['n1']));
check('purge: child_nodes пуст', $h2->pac['child_nodes'] === []);

// --- 3. standalone различается ---
$s = new DetachHarness();
$s->pac = ['node_role' => 'standalone', 'domain' => 'ru.ugam.pro'];
check('standalone: isStandaloneNode=true', $s->isStandaloneNode() === true);
check('standalone: isChildNode=false', $s->isChildNode() === false);
check('standalone: isParentNode=true', $s->isParentNode() === true);

$c = new DetachHarness();
$c->pac = ['node_role' => 'child', 'domain' => 'ru.ugam.pro'];
check('child: isStandaloneNode=false', $c->isStandaloneNode() === false);
check('child: isChildNode=true', $c->isChildNode() === true);

echo "\n{$total} tests, {$fails} failed\n";
exit($fails === 0 ? 0 : 1);
