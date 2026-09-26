<?php

/**
 * Юнит-тест гейта isUserPortalRequest(): команда /update от не-админа
 * при включённом user_portal обязана считаться запросом портала (и,
 * следовательно, проходить auth()), а не отсекаться как «auth denied».
 *
 * Регрессия: 2-й аккаунт слал /update, получал тишину — auth() считал
 * команду чужой, т.к. её не было в белом списке (только start|menu).
 *
 * Запуск: php tests/test_user_portal_update_route.php
 * Не зависит от окружения: не трогает config.php, не пишет файлы, не шлёт в TG.
 */

declare(strict_types=1);

// Трейт живёт в app/traits/ (workspace) либо traits/ (в контейнере рядом с app/).
$traitPath = __DIR__ . '/../app/traits/UserPortalTrait.php';
if (!is_file($traitPath)) {
    $traitPath = __DIR__ . '/../traits/UserPortalTrait.php';
}
require $traitPath;

final class PortalRouteHarness
{
    use UserPortalTrait;

    /** @var array<string,mixed> */
    public array $fixtureInput = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    public function callIsUserPortalRequest(): bool
    {
        return $this->isUserPortalRequest();
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

// --- /update (сообщение-команда) — ключевая регрессия ---
$h = new PortalRouteHarness(['message' => '/update', 'callback' => false]);
check('/update msg -> portal request', $h->callIsUserPortalRequest() === true);

// --- /update без пробелов/аргументов тоже ---
$h = new PortalRouteHarness(['message' => '/update', 'callback' => false]);
check('/update bare -> portal request', $h->callIsUserPortalRequest() === true);

// --- старые команды не сломаны ---
$h = new PortalRouteHarness(['message' => '/start', 'callback' => false]);
check('/start msg still portal', $h->callIsUserPortalRequest() === true);

$h = new PortalRouteHarness(['message' => '/menu', 'callback' => false]);
check('/menu msg still portal', $h->callIsUserPortalRequest() === true);

// --- полные совпадения, без ложных срабатываний префикса ---
$h = new PortalRouteHarness(['message' => '/updateSomething', 'callback' => false]);
check('/updateSomething NOT portal (not exact)', $h->callIsUserPortalRequest() === false);

$h = new PortalRouteHarness(['message' => '/updates', 'callback' => false]);
check('/updates NOT portal', $h->callIsUserPortalRequest() === false);

// --- колбэки портала остаются валидны ---
$h = new PortalRouteHarness(['message' => '', 'callback' => '/userPortal']);
check('callback /userPortal -> portal request', $h->callIsUserPortalRequest() === true);

$h = new PortalRouteHarness(['message' => '', 'callback' => '/userPortalDevices']);
check('callback /userPortalDevices -> portal request', $h->callIsUserPortalRequest() === true);

// --- не-команда-текст не проходит ранний гейт (тут false без сессии) ---
$h = new PortalRouteHarness(['message' => 'hello', 'callback' => false]);
check('plain text NOT portal (no early match)', $h->callIsUserPortalRequest() === false);

// --- пустой ввод ---
$h = new PortalRouteHarness(['message' => '', 'callback' => false]);
check('empty NOT portal', $h->callIsUserPortalRequest() === false);

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
