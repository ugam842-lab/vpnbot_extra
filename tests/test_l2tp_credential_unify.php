<?php

/**
 * Юнит-тест L2TP/IPsec-credentials: стабильный ключ + идемпотентность + secrets-conf.
 *
 * Покрывает:
 *  1. l2tpXrKey() для VLESS-клиента с subscription_id отдаёт голый subscription_id —
 *     тот же anchor, что у IKEv2 и портала (одна подписка = один логин/пароль).
 *  2. l2tpXrKey() без subscription_id уходит в xr_-неймспейс (не коллизит с голым id).
 *  3. l2tpClientKey() для WG-клиента стабилен по PublicKey (перестановка списка не
 *     орфанит credentials).
 *  4. ensureL2tpCredentialsByKey() идемпотентен: повторный вызов не меняет пароль.
 *  5. writeL2tpSecretsConf() рендерит PSK + по одному XAuth-секрету на клиента.
 *
 * Запуск: php tests/test_l2tp_credential_unify.php
 * Чистый хелпер: не трогает config.php, файлы, TG.
 */

declare(strict_types=1);

require __DIR__ . '/../app/traits/Ikev2Trait.php';
require __DIR__ . '/../app/traits/L2tpTrait.php';

final class L2tpCredHarness
{
    use Ikev2Trait;
    use L2tpTrait;

    public array $pac = [];
    public string $ip = '203.0.113.7';
    public int $setPacWrites = 0;
    public int $reloads = 0;
    public string $lastConf = '';

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

    protected function getName(array $interface = []): string
    {
        return (string) ($interface['name'] ?? 'client');
    }

    protected function l2tpRandomPassword(int $length = 24): string
    {
        return $this->nextPassword;
    }

    protected function reloadIkev2(): bool
    {
        $this->reloads++;

        return true;
    }

    protected function reloadL2tp(): bool
    {
        $this->reloads++;

        return true;
    }

    protected function l2tpSecretsConfPath(): string
    {
        return '/tmp/l2tp-secrets-test.conf';
    }

    public function xrKey(array $client, int $index): string
    {
        return $this->l2tpXrKey($client, $index);
    }

    public function wgKey(array $client, int $index): string
    {
        return $this->l2tpClientKey($client, $index);
    }

    public function ensureByKey(string $key): ?array
    {
        return $this->ensureL2tpCredentialsByKey($key);
    }

    public function conf(): int
    {
        return $this->writeL2tpSecretsConf();
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

// --- 1. VLESS с subscription_id → ключ = голый subscription_id ---
$h = new L2tpCredHarness();
$client = ['id' => 'vless-uuid-1111', 'subscription_id' => 'sub_42', 'email' => 'a@b.c'];
check('xrKey: с subscription_id возвращает голый sub id', $h->xrKey($client, 0) === 'sub_42');

// --- 2. VLESS без subscription_id: id → голый id (getClientSubscriptionId фолбэчится
//     на id); без id вовсе → xr_idx_-неймспейс. xr_-префикс применяется только к
//     uuid/email-фолбэку, но не к id (см. ikev2XrKey) — чтобы не коллизить с порталом. ---
$h2 = new L2tpCredHarness();
$client2 = ['id' => 'vless-uuid-1111', 'email' => 'a@b.c'];
check('xrKey: без sub id, с id → голый id', $h2->xrKey($client2, 0) === 'vless-uuid-1111');

$h2b = new L2tpCredHarness();
$client2b = ['email' => 'a@b.c'];
$key2b = $h2b->xrKey($client2b, 0);
check('xrKey: без sub id и id → xr_idx_ неймспейс', strpos($key2b, 'xr_idx_') === 0);

// --- 3. WG-клиент: стабилен по PublicKey ---
$h3 = new L2tpCredHarness();
$wg = ['PublicKey' => 'PK_AAAA', 'interface' => ['name' => 'alice']];
$kw1 = $h3->wgKey($wg, 0);
$kw2 = $h3->wgKey($wg, 5);
check('wgKey: по PublicKey не зависит от индекса', $kw1 === $kw2);
check('wgKey: ключ в wg_ неймспейсе', strpos($kw1, 'wg_') === 0);

// --- 4. идемпотентность ensureL2tpCredentialsByKey ---
$h4 = new L2tpCredHarness();
$c1 = $h4->ensureByKey('sub_42');
$h4->nextPassword = 'CHANGED-PASSWORD';
$c2 = $h4->ensureByKey('sub_42');
check('idempotent: username неизменен', ($c1['username'] ?? '') === ($c2['username'] ?? ''));
check('idempotent: password неизменен', ($c1['password'] ?? '') === ($c2['password'] ?? ''));
check('idempotent: повторный вызов не пишет pac', $h4->setPacWrites === 1);
check('idempotent: username с l_ префиксом', strpos((string) ($c1['username'] ?? ''), 'l_') === 0);

// --- 5. writeL2tpSecretsConf рендерит PSK + XAuth ---
$h5 = new L2tpCredHarness();
$h5->pac = ['l2tp_psk' => 'my-shared-secret'];
$h5->ensureByKey('sub_42');
$reloadsBeforeConf = $h5->reloads; // ensureByKey уже перезагрузил secret-файл (reload=1)
before_each();
$n = $h5->conf();
$conf = (string) @file_get_contents('/tmp/l2tp-secrets-test.conf');
check('conf: вернул 1 XAuth-секрет', $n === 1);
check('conf: содержит PSK-секцию', strpos($conf, 'ike-l2tp') !== false);
check('conf: содержит PSK-секрет', strpos($conf, 'my-shared-secret') !== false);
check('conf: содержит XAuth-секрет', strpos($conf, 'xauth-') !== false);
check('conf: перезагрузил strongSwan', $h5->reloads === $reloadsBeforeConf + 1);

// --- 6. два разных cred-логина не равны ---
$h6 = new L2tpCredHarness();
$a = $h6->ensureByKey('sub_A');
$b = $h6->ensureByKey('sub_B');
check('distinct: разные ключи → разные username', ($a['username'] ?? '') !== ($b['username'] ?? ''));

// --- 7. пустой ключ → null ---
$h7 = new L2tpCredHarness();
check('empty key: ensureByKey("") → null', $h7->ensureByKey('') === null);
check('empty key: не пишет pac', $h7->setPacWrites === 0);

echo "\n{$total} tests, {$fails} failed\n";
exit($fails === 0 ? 0 : 1);

function before_each(): void
{
    @unlink('/tmp/l2tp-secrets-test.conf');
}
