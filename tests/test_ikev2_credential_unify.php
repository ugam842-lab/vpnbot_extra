<?php

/**
 * Юнит-тест унификации IKEv2-credentials по subscription_id (фикс #2-A).
 *
 * Покрывает:
 *  1. ikev2XrKey() для VLESS-клиента с subscription_id отдаёт РОВНО тот же
 *     ключ, что и портал (портал зовёт ensureIkev2Credentials($subId) с голым
 *     subscription_id). Значит VLESS-карточка и портал — один кред.
 *  2. ikev2XrKey() без subscription_id уходит в xr_-неймспейс (никогда не
 *     коллизит с голым subscription_id).
 *  3. ensureIkev2CredentialsByKey() идемпотентен: первый вызов генерирует,
 *     повторный возвращает те же username/password (пароль не меняется).
 *  4. ensureIkev2Credentials() (портал) и ensureIkev2CredentialsByKey() с тем
 *     же subscription_id делят один и тот же cred — источник «пароль меняется
 *     при каждом клике» устранён.
 *
 * Запуск: php tests/test_ikev2_credential_unify.php
 * Чистый хелпер: не трогает config.php, файлы, TG.
 */

declare(strict_types=1);

require __DIR__ . '/../app/traits/Ikev2Trait.php';

final class Ikev2CredHarness
{
    use Ikev2Trait;

    public array $pac = [];
    public string $ip = '203.0.113.7';
    public int $eapWrites = 0;
    public int $setPacWrites = 0;

    // Детерминизм: фиксированный "случайный" пароль вместо random_int.
    public string $nextPassword = 'a1b2c3d4e5f6g7h8i9j0k1';

    protected function getPacConf(): array
    {
        return $this->pac;
    }

    protected function setPacConf(array $pac): void
    {
        $this->pac = $pac;
        $this->setPacWrites++;
    }

    protected function getClientSubscriptionId(array $client): string
    {
        return (string) ($client['subscription_id'] ?? $client['id'] ?? '');
    }

    protected function getHashBot(): string
    {
        return 'TESTHASH';
    }

    protected function writeIkev2EapConf(): int
    {
        $this->eapWrites++;

        return 1;
    }

    protected function ikev2RandomPassword(int $length = 24): string
    {
        return $this->nextPassword;
    }

    // Публичные обёртки над protected-методами.
    public function xrKey(array $client, int $index): string
    {
        return $this->ikev2XrKey($client, $index);
    }

    public function ensureByKey(string $key): ?array
    {
        return $this->ensureIkev2CredentialsByKey($key);
    }

    public function ensurePortal(string $subId): ?array
    {
        return $this->ensureIkev2Credentials($subId);
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

// --- 1. VLESS-клиент с subscription_id → ключ = голый subscription_id ---
$h = new Ikev2CredHarness();
$client = ['id' => 'vless-uuid-1111', 'subscription_id' => 'sub_42', 'email' => 'a@b.c'];
check('xrKey: с subscription_id возвращает голый sub id', $h->xrKey($client, 0) === 'sub_42');

// --- 2. VLESS-клиент с пустым subscription_id И пустым id (пред-привязочный) ---
// getClientSubscriptionId() фолбэчится на id; чтобы попасть в xr_-ветку,
// и subscription_id, и id должны быть пусты.
$h2 = new Ikev2CredHarness();
$client2 = ['email' => 'a@b.c'];
$key2 = $h2->xrKey($client2, 0);
check('xrKey: без subscription_id и id уходит в xr_ неймспейс', strpos($key2, 'xr_') === 0);

// Ветка, где есть id, но нет subscription_id: ключ = id (не xr_-, не пустой).
$h2b = new Ikev2CredHarness();
$client2b = ['id' => 'vless-uuid-1111', 'email' => 'a@b.c'];
$key2b = $h2b->xrKey($client2b, 0);
check('xrKey: без subscription_id, но с id → ключ = голый id', $key2b === 'vless-uuid-1111');

// --- 3. идемпотентность ensureIkev2CredentialsByKey ---
$h3 = new Ikev2CredHarness();
$c1 = $h3->ensureByKey('sub_42');
$h3->nextPassword = 'CHANGED-PASSWORD'; // если бы генерил заново — отличался бы
$c2 = $h3->ensureByKey('sub_42');
check('idempotent: username неизменен', ($c1['username'] ?? '') === ($c2['username'] ?? ''));
check('idempotent: password неизменен', ($c1['password'] ?? '') === ($c2['password'] ?? ''));
check('idempotent: повторный вызов не пишет pac', $h3->setPacWrites === 1);

// --- 4. портал и VLESS делят один cred по subscription_id ---
$h4 = new Ikev2CredHarness();
$portal = $h4->ensurePortal('sub_42');
$vless  = $h4->ensureByKey('sub_42');
check('unify: портал и VLESS дают один username', ($portal['username'] ?? '') === ($vless['username'] ?? ''));
check('unify: портал и VLESS дают один password', ($portal['password'] ?? '') === ($vless['password'] ?? ''));
check('unify: один cred ⇒ один pac-записей (не два разных)', $h4->setPacWrites === 1);

// --- 5. пустая строка-ключ → null (не создаёт мусора) ---
$h5 = new Ikev2CredHarness();
check('empty key: ensureByKey("") → null', $h5->ensureByKey('') === null);
check('empty key: не пишет pac', $h5->setPacWrites === 0);

echo "\n{$total} tests, {$fails} failed\n";
exit($fails === 0 ? 0 : 1);
