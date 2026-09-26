<?php

/**
 * Юнит-тест: поиск клиента xray не должен быть привязан к inbounds[0].
 *
 * Регрессия (2026-09-26, «пользовательская часть бота опять не работает»):
 * после миграции на XHTTP+Reality рабочий inbound перестал быть нулевым —
 * inbounds[0] (vless_tls) остался пустым (0 клиентов), а реальные клиенты
 * переехали в inbounds[1] (vless_xhttp) и inbounds[2] (vless_reality).
 * Код же искал подписку, устройства и id строго в inbounds[0], поэтому
 * resolveSubscriptionClient() возвращал null → resolveUserPortalGrant() →
 * null → getUserPortalSession() → null → портал рисовал «нет доступа»,
 * а на повторный /update Telegram отвечал «message is not modified» и
 * пользователь не видел вообще ничего.
 *
 * Проверяем инварианты:
 *  1. подписка находится в любом inbound'е, а не только в нулевом;
 *  2. «сквозной» индекс клиента (как его показывает админка) считается
 *     по всем inbound'ам подряд;
 *  3. поиск по id тоже сквозной;
 *  4. device-запись (device_parent_id) резолвит ссылку своего владельца —
 *     у владельца может не быть отдельной client-записи;
 *  5. новый клиент дописывается в рабочий inbound, а не в пустой нулевой.
 *
 * Запуск: php tests/test_xray_inbound_layout.php
 */

declare(strict_types=1);

// Трейты живут в app/traits/ (workspace) либо traits/ (в контейнере рядом с app/).
$base = is_dir(__DIR__ . '/../app/traits') ? __DIR__ . '/../app' : __DIR__ . '/..';
require $base . '/traits/HwidTrait.php';
require $base . '/traits/UserPortalTrait.php';

final class LayoutHarness
{
    use HwidTrait;
    use UserPortalTrait;

    /** @var array декодированный xray.json, который вернёт getXray() */
    public array $xray = [];

    protected function getXray(): array
    {
        return $this->xray;
    }

    public function callFindByIndex(array $xray, int $i): ?array
    {
        return $this->findXrayClientByIndex($xray, $i);
    }

    public function callFindByIndexOrId(array $xray, $ref): ?array
    {
        return $this->findXrayClientByIndexOrId($xray, $ref);
    }

    public function callIsSubscriptionIdMatch(array $client, string $id): bool
    {
        return $this->isSubscriptionIdMatch($client, $id);
    }

    public function callGetClientSubscriptionId(array $client): string
    {
        return $this->getClientSubscriptionId($client);
    }

    public function callResolveSubscriptionClient(string $sub): ?array
    {
        return $this->resolveSubscriptionClient($sub);
    }

    public function callForEachXrayClient(array $xray): array
    {
        return iterator_to_array($this->forEachXrayClient($xray));
    }

    public function callAppendXrayClient(array &$xray, array $client): void
    {
        $this->appendXrayClient($xray, $client);
    }
}

/** Клиент-владелец (без device_parent_id). */
function parentClient(string $id, string $email, array $extra = []): array
{
    return array_merge(['id' => $id, 'email' => $email], $extra);
}

/** Клиент-устройство: ссылается на владельца через device_parent_id. */
function deviceClient(string $id, string $email, string $parent, string $hwid): array
{
    return [
        'id'               => $id,
        'email'            => $email,
        'device_parent_id' => $parent,
        'device_hwid'      => $hwid,
        'device_runtime'   => 1,
    ];
}

/** Мир как после миграции: нулевой inbound пуст, рабочие — дальше. */
function migratedXray(): array
{
    return [
        'inbounds' => [
            ['tag' => 'vless_tls',     'settings' => ['clients' => []]],
            ['tag' => 'vless_xhttp',   'settings' => ['clients' => [
                parentClient('4c43d9ed-9dd5-45b2-9c93-13de344b7c25', 'Puytsy'),
                deviceClient('b003d6f5-ea2d-499b-80ae-e3b2b8d8eeb4', 'Puytsy#dev-1', '4c43d9ed-9dd5-45b2-9c93-13de344b7c25', 'hw1'),
            ]]],
            ['tag' => 'vless_reality', 'settings' => ['clients' => [
                parentClient('4c43d9ed-9dd5-45b2-9c93-13de344b7c25', 'Puytsy'),
                deviceClient('b003d6f5-ea2d-499b-80ae-e3b2b8d8eeb4', 'Puytsy#dev-1', '4c43d9ed-9dd5-45b2-9c93-13de344b7c25', 'hw1'),
            ]]],
        ],
    ];
}

$fails = 0;
$total = 0;

function check(string $name, bool $ok): void
{
    global $fails, $total;
    $total++;
    echo $ok ? "ok    {$name}\n" : "FAIL  {$name}\n";
    if (!$ok) {
        $fails++;
    }
}

$h = new LayoutHarness();

// 1. Сквозной индекс: клиенты считаются по всем inbound'ам подряд.
//    Пустой inbounds[0] не должен двигать индексы.
$xray = migratedXray();
$h->xray = $xray;
check('index 0 — первый клиент inbounds[1]', $h->callFindByIndex($xray, 0)['email'] === 'Puytsy');
check('index 1 — его устройство', $h->callFindByIndex($xray, 1)['email'] === 'Puytsy#dev-1');
check('index 2 — первый клиент inbounds[2]', $h->callFindByIndex($xray, 2)['email'] === 'Puytsy');
check('index 3 — устройство из inbounds[2]', $h->callFindByIndex($xray, 3)['email'] === 'Puytsy#dev-1');
check('index 4 — за пределами', $h->callFindByIndex($xray, 4) === null);
check('index -1 — отказ', $h->callFindByIndex($xray, -1) === null);

// 2. Поиск по id сквозной и не зависит от порядка inbound'ов.
check('by-id: находит во втором inbound', $h->callFindByIndexOrId($xray, 'b003d6f5-ea2d-499b-80ae-e3b2b8d8eeb4')['email'] === 'Puytsy#dev-1');
check('by-id: нет такого — null', $h->callFindByIndexOrId($xray, '00000000-0000-0000-0000-000000000000') === null);
check('by-id: пустая строка — null', $h->callFindByIndexOrId($xray, '') === null);
// Числовая строка трактуется как индекс (совместимость с callback_data).
check('by-id: "1" — это индекс', $h->callFindByIndexOrId($xray, '1')['email'] === 'Puytsy#dev-1');

// 3. Подписка владельца резолвится из ненулевого inbound'а.
$r = $h->callResolveSubscriptionClient('4c43d9ed-9dd5-45b2-9c93-13de344b7c25');
check('resolve: владелец найден вне inbounds[0]', $r !== null && $r['subscription_id'] === '4c43d9ed-9dd5-45b2-9c93-13de344b7c25');
check('resolve: индекс сквозной, а не локальный в inbound\'е', $r !== null && $r['index'] === 0);
check('resolve: тип клиента — владелец, не устройство', $r !== null && empty($r['client']['device_parent_id']));

// Резолвер намеренно отдаёт владельца, а не устройство: device_parent_id
// ссылается на владельца, и подписка — это он.
$r = $h->callResolveSubscriptionClient('b003d6f5-ea2d-499b-80ae-e3b2b8d8eeb4');
check('resolve: id устройства не путается с подпиской владельца', $r === null);

// 4. Ссылка подписки может указывать на владельца, которого нет отдельной
//    записью: он существует как device_parent_id своих устройств.
$orphan = ['inbounds' => [
    ['tag' => 'vless_tls', 'settings' => ['clients' => []]],
    ['tag' => 'vless_xhttp', 'settings' => ['clients' => [
        deviceClient('b003d6f5-ea2d-499b-80ae-e3b2b8d8eeb4', 'Ruslan_nl2#dev-a', '6755f6c0-45cb-46c9-aa48-c10aea770f5e', 'hw1'),
        deviceClient('c003d6f5-ea2d-499b-80ae-e3b2b8d8eeb4', 'Ruslan_nl2#dev-b', '6755f6c0-45cb-46c9-aa48-c10aea770f5e', 'hw2'),
    ]]],
]];
$h2 = new LayoutHarness();
$h2->xray = $orphan;
$r2 = $h2->callResolveSubscriptionClient('6755f6c0-45cb-46c9-aa48-c10aea770f5e');
check('resolve: владелец без своей записи найден по device_parent_id', $r2 !== null);
check('resolve: взята device-запись этого владельца', $r2 !== null && ($r2['client']['device_parent_id'] ?? '') === '6755f6c0-45cb-46c9-aa48-c10aea770f5e');
check('resolve: индекс сквозной', $r2 !== null && $r2['index'] === 0);
// Чужая подписка не резолвится в этого владельца.
check('resolve: чужой id — null', $h2->callResolveSubscriptionClient('00000000-0000-0000-0000-000000000000') === null);
check('resolve: пустой id — null', $h2->callResolveSubscriptionClient('') === null);
$h3 = new LayoutHarness();
$h3->xray = ['inbounds' => [['tag' => 'vless_tls', 'settings' => ['clients' => []]]]];
check('resolve: пустой конфиг — null', $h3->callResolveSubscriptionClient('6755f6c0-45cb-46c9-aa48-c10aea770f5e') === null);

// 5. Матчинг подписки.
check('match: по device_parent_id', $h2->callIsSubscriptionIdMatch($orphan['inbounds'][1]['settings']['clients'][0], '6755f6c0-45cb-46c9-aa48-c10aea770f5e'));
check('match: по id клиента', $h2->callIsSubscriptionIdMatch(['id' => 'abc'], 'abc'));
check('match: по legacy id', $h2->callIsSubscriptionIdMatch(['id' => 'x', 'subscription_id' => 'y', 'subscription_legacy_ids' => ['z']], 'z'));
check('match: пустой запрос — false', !$h2->callIsSubscriptionIdMatch(['id' => 'abc'], ''));
check('match: чужой id — false', !$h2->callIsSubscriptionIdMatch(['id' => 'abc', 'device_parent_id' => 'p'], 'q'));
check('match: subscription_id приоритетнее id', $h2->callGetClientSubscriptionId(['id' => 'a', 'subscription_id' => 'b']) === 'b');
check('match: без subscription_id — id', $h2->callGetClientSubscriptionId(['id' => 'a']) === 'a');

// 6. Обход: сквозной индекс и адрес записи (inbound/offset).
$entries = $h->callForEachXrayClient(migratedXray());
check('forEach: всего 4 клиента', count($entries) === 4);
check('forEach: первый — inbound 1 offset 0 index 0', $entries[0]['inbound'] === 1 && $entries[0]['offset'] === 0 && $entries[0]['index'] === 0);
check('forEach: третий — inbound 2 offset 0 index 2', $entries[2]['inbound'] === 2 && $entries[2]['offset'] === 0 && $entries[2]['index'] === 2);
check('forEach: последний — index 3', $entries[3]['index'] === 3);

// 7. Новые клиенты дописываются в рабочий inbound, не в пустой нулевой.
$target = migratedXray();
$h->callAppendXrayClient($target, parentClient('new-uuid', 'NewUser'));
check('append: пустой inbounds[0] не тронут', count($target['inbounds'][0]['settings']['clients']) === 0);
check('append: запись ушла в рабочий inbound', $target['inbounds'][1]['settings']['clients'][2]['id'] === 'new-uuid');
// Inbound без собственного списка клиентов (relay/CDN) не трогаем: иначе
// создали бы ему чужой ключ settings.clients.
$empty = ['inbounds' => [['tag' => 'relay', 'settings' => []]]];
$h->callAppendXrayClient($empty, parentClient('x', 'X'));
check('append: inbound без clients не получает мусорный ключ', !isset($empty['inbounds'][0]['settings']['clients']));

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
