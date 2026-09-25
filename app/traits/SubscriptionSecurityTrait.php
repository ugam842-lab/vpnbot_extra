<?php

trait SubscriptionSecurityTrait
{
    protected function hasSubscriptionDevicePassword(array $client): bool
    {
        return !empty($client['device_delete_password_hash'])
            || !empty($client['device_delete_password_md5']);
    }

    protected function getSubscriptionDevicePasswordHash(array $client): string
    {
        $modern = (string) ($client['device_delete_password_hash'] ?? '');
        if ($modern !== '') {
            return $modern;
        }

        return (string) ($client['device_delete_password_md5'] ?? '');
    }

    protected function isSubscriptionDevicePasswordValid(array $client, string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $modern = (string) ($client['device_delete_password_hash'] ?? '');
        if ($modern !== '') {
            return password_verify($password, $modern);
        }
        $legacy = strtolower((string) ($client['device_delete_password_md5'] ?? ''));
        if ($legacy === '') {
            return false;
        }

        return hash_equals($legacy, strtolower(md5($password)));
    }

    protected function setSubscriptionDevicePassword(array &$client, string $password): void
    {
        $client['device_delete_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        unset($client['device_delete_password_md5']);
    }

    protected function clearSubscriptionDevicePassword(array &$client): void
    {
        unset($client['device_delete_password_hash'], $client['device_delete_password_md5']);
    }

    protected function verifySubscriptionDevicePasswordWithMigration(array &$client, string $password): bool
    {
        if (!$this->isSubscriptionDevicePasswordValid($client, $password)) {
            return false;
        }
        if (empty($client['device_delete_password_hash']) && !empty($client['device_delete_password_md5'])) {
            $this->setSubscriptionDevicePassword($client, $password);
        }

        return true;
    }

    protected function createSubscriptionActionToken(string $subscriptionId, int $ttl = 3600): string
    {
        $ts = time();
        $sig = hash_hmac('sha256', $subscriptionId . '|' . $ts, $this->key);
        $payload = $subscriptionId . '|' . $ts . '|' . $sig;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    protected function verifySubscriptionActionToken(string $subscriptionId, string $token, int $ttl = 3600): bool
    {
        $token = trim($token);
        if ($token === '' || $subscriptionId === '') {
            return false;
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return false;
        }
        [$subId, $ts, $sig] = $parts;
        if ($subId !== $subscriptionId) {
            return false;
        }
        $ts = (int) $ts;
        if ($ts <= 0 || $ts < time() - $ttl || $ts > time() + 60) {
            return false;
        }
        $expected = hash_hmac('sha256', $subId . '|' . $ts, $this->key);

        return hash_equals($expected, $sig);
    }

    protected function denySubscriptionAction(string $message = 'invalid action token', int $status = 403): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message]);
        exit;
    }

    protected function requireSubscriptionActionToken(string $subscriptionId): void
    {
        $token = trim((string) ($_POST['action_token'] ?? $_SERVER['HTTP_X_SUB_ACTION_TOKEN'] ?? ''));
        if (!$this->verifySubscriptionActionToken($subscriptionId, $token)) {
            $this->denySubscriptionAction('invalid or expired action token');
        }
    }

    protected function getSubscriptionRateLimitPath(): string
    {
        return '/config/sub_action_rl.json';
    }

    protected function checkSubscriptionActionRateLimit(string $subscriptionId, string $action, int $max = 10, int $window = 300): bool
    {
        $path = $this->getSubscriptionRateLimitPath();
        $now = time();
        $data = [];
        if (is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $bucketKey = $subscriptionId . '|' . $action . '|' . $ip;
        $entries = array_values(array_filter(
            $data[$bucketKey] ?? [],
            static fn($ts) => (int) $ts > $now - $window
        ));
        if (count($entries) >= $max) {
            return false;
        }
        $entries[] = $now;
        $data[$bucketKey] = $entries;
        foreach ($data as $key => $timestamps) {
            if (!is_array($timestamps)) {
                unset($data[$key]);
                continue;
            }
            $filtered = array_values(array_filter($timestamps, static fn($ts) => (int) $ts > $now - $window));
            if ($filtered === []) {
                unset($data[$key]);
            } else {
                $data[$key] = $filtered;
            }
        }
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return true;
    }

    protected function requireSubscriptionActionRateLimit(string $subscriptionId, string $action, int $max = 10, int $window = 300): void
    {
        if (!$this->checkSubscriptionActionRateLimit($subscriptionId, $action, $max, $window)) {
            $this->denySubscriptionAction('rate limit exceeded', 429);
        }
    }

    protected function getSubscriptionUrlEpoch(?array $pac = null): int
    {
        $pac = $pac ?? $this->getPacConf();

        return max(1, (int) ($pac['subscription_url_epoch'] ?? 1));
    }

    protected function createSubscriptionUrlSig(string $subscriptionId, ?int $epoch = null): string
    {
        $epoch = $epoch ?? $this->getSubscriptionUrlEpoch();
        $digest = hash_hmac('sha256', $subscriptionId . '|' . $epoch, $this->key, true);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    protected function verifySubscriptionUrlSig(string $subscriptionId, string $sig): bool
    {
        $sig = trim($sig);
        if ($sig === '' || $subscriptionId === '') {
            return false;
        }
        $epoch = $this->getSubscriptionUrlEpoch();
        if (hash_equals($this->createSubscriptionUrlSig($subscriptionId, $epoch), $sig)) {
            return true;
        }

        return $epoch > 1 && hash_equals($this->createSubscriptionUrlSig($subscriptionId, $epoch - 1), $sig);
    }

    protected function buildSubscriptionPageUrl(string $scheme, string $domain, string $hash, string $subscriptionId): string
    {
        $subscriptionId = trim($subscriptionId);
        $query = http_build_query([
            'id' => $subscriptionId,
            'sig' => $this->createSubscriptionUrlSig($subscriptionId),
        ]);

        return "{$scheme}://{$domain}/pac{$hash}/sub?{$query}";
    }

    protected function requireSubscriptionUrlAccess(string $subscriptionId): void
    {
        $pac = $this->getPacConf();
        $sig = trim((string) ($_GET['sig'] ?? ''));
        $signedRequired = !empty($pac['subscription_url_signed']);
        if ($sig === '' && !$signedRequired) {
            return;
        }
        if ($sig === '' || !$this->verifySubscriptionUrlSig($subscriptionId, $sig)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<html><body><h2>Subscription link invalid or expired</h2><p>Please open the bot and request a new subscription link.</p></body></html>';
            exit;
        }
    }

    protected function rotateSubscriptionUrlEpoch(): int
    {
        $pac = $this->getPacConf();
        $pac['subscription_url_epoch'] = $this->getSubscriptionUrlEpoch($pac) + 1;
        $this->setPacConf($pac);

        return (int) $pac['subscription_url_epoch'];
    }

    protected function performSubscriptionDeviceDelete(string $ownerSubId, string $hwid, string $password): array
    {
        $hwid = trim($hwid);
        if ($hwid === '') {
            return ['ok' => false, 'message' => 'empty hwid'];
        }

        $resolved = $this->resolveSubscriptionClient($ownerSubId);
        if ($resolved === null) {
            return ['ok' => false, 'message' => 'subscription not found'];
        }

        $client = $resolved['client'];
        if (!$this->isSubscriptionDevicePasswordValid($client, $password)) {
            return ['ok' => false, 'message' => 'invalid password'];
        }

        $xr = $this->getXray();
        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $deviceUuid = (string) ($devices[$hwid]['device_uuid'] ?? '');
        $this->deleteHwidDevice($ownerSubId, $hwid);

        // The same client lives in every transport inbound (xhttp, reality, ws)
        // as a separate copy, so dropping it from the first one alone leaves a
        // working config behind. Devices created before the uuid field existed
        // have no device_uuid — match those by their hwid instead.
        $removed = $deviceUuid !== ''
            ? $this->removeXrayClientEverywhere($xr, 'id', $deviceUuid)
            : $this->removeXrayClientEverywhere($xr, 'device_hwid', $hwid);

        if ($removed > 0) {
            $this->restartXray($xr);
        } else {
            $this->writeXrayConfig($xr);
        }

        if ($deviceUuid !== '') {
            $this->runInRuntimeWgContext(function () use ($deviceUuid) {
                $this->deleteDeviceWgProfileByUuid($deviceUuid);
            });
        }

        return ['ok' => true];
    }

    /**
     * Drop every client matching $field=$value from every inbound it appears
     * in (and from the clients_all registry, which the inbound clients are
     * rebuilt from). Returns how many inbound entries were removed.
     */
    protected function removeXrayClientEverywhere(array &$xray, string $field, string $value): int
    {
        if ($value === '' || !isset($xray['inbounds']) || !is_array($xray['inbounds'])) {
            return 0;
        }

        $removed = 0;
        foreach ($xray['inbounds'] as $ibIdx => &$inbound) {
            $settings = &$inbound['settings'];
            foreach (['clients', 'clients_all'] as $bucket) {
                if (!isset($settings[$bucket]) || !is_array($settings[$bucket])) {
                    continue;
                }
                $kept = [];
                foreach ($settings[$bucket] as $client) {
                    if (is_array($client) && (string) ($client[$field] ?? '') === $value) {
                        if ($bucket === 'clients') {
                            $removed++;
                        }
                        continue;
                    }
                    $kept[] = $client;
                }
                $settings[$bucket] = array_values($kept);
            }
            unset($settings);
        }
        unset($inbound);

        return $removed;
    }
}
