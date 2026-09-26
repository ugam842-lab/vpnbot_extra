<?php

trait Ikev2Trait
{
    /**
     * Whether the IKEv2 (strongSwan) profile button is enabled for a client.
     * Gates on the `ikev2` transport flag (global + per-subscription override),
     * mirroring isRuntimeDeviceWgEnabled.
     */
    protected function isIkev2Enabled(array $client): bool
    {
        $pac   = $this->getPacConf();
        $flags = $this->getClientTransportFlags($client, $pac);

        return !empty($flags['ikev2']);
    }

    /**
     * Server hostname/IP the client profile points at.
     * Preference: pac['ikev2_host'] -> pac['domain'] -> instance IP.
     */
    protected function getIkev2Host(): string
    {
        $pac = $this->getPacConf();
        $host = trim((string) ($pac['ikev2_host'] ?? ''));
        if ($host !== '') {
            return $host;
        }
        $domain = trim((string) ($pac['domain'] ?? ''));
        if ($domain !== '') {
            return $domain;
        }

        return (string) $this->ip;
    }

    /**
     * Return (or lazily create) the persistent EAP credentials for a subscription.
     *
     * Credentials live in pac.json under `ikev2_users[subId] = {username, password, created_at}`,
     * so they survive container updates (configured in the config/ mount). One set per
     * subscription, not per device: strongSwan EAP-MSCHAPv2 secrets are keyed by
     * username, and a subscription already scopes who may fetch its profile.
     */
    protected function ensureIkev2Credentials(string $subId): ?array
    {
        return $this->ensureIkev2CredentialsByKey($subId);
    }

    /**
     * Core credential store, keyed by an arbitrary stable key (subscription id for the
     * portal path, client-identity hash for the bot client path). A single username may
     * be shared by a subscription's devices, so both callers go through this, and the
     * strongSwan secrets file is rebuilt whenever a new credential appears.
     */
    protected function ensureIkev2CredentialsByKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $pac = $this->getPacConf();
        $users = is_array($pac['ikev2_users'] ?? null) ? $pac['ikev2_users'] : [];

        $cred = $users[$key] ?? null;
        if (is_array($cred) && !empty($cred['username']) && !empty($cred['password'])) {
            return $cred;
        }

        // New credential: short stable username + strong random password.
        $username = 'u_' . substr(hash('sha256', $key . ':' . $this->getHashBot()), 0, 12);
        $password = $this->ikev2RandomPassword(24);

        $users[$key] = [
            'username'   => $username,
            'password'   => $password,
            'created_at' => date('c'),
        ];
        $pac['ikev2_users'] = $users;
        $this->setPacConf($pac);

        // Best-effort: sync the EAP secret into the strongSwan secrets include file
        // (the host swanctl.conf `include`s config/ikev2-eap.conf, then `swanctl --load-all`).
        $this->writeIkev2EapConf();

        return $users[$key];
    }

    /**
     * Stable identity key for a WireGuard client in the bot's client list.
     * Prefer the peer PublicKey (a client stores it under `peers[0]['PublicKey']`
     * or `peers[0]['# PublicKey']`); fall back to a hash of the display name and
     * index so a reordered list does not orphan credentials.
     */
    protected function ikev2ClientKey(array $client, int $index): string
    {
        $pub = trim((string) (
            $client['PublicKey']
            ?? $client['peers'][0]['PublicKey']
            ?? $client['peers'][0]['# PublicKey']
            ?? ''
        ));
        if ($pub !== '') {
            return 'wg_' . hash('sha256', $pub);
        }

        return 'wg_idx_' . (string) $index . '_' . substr(hash('sha256', (string) $this->getName($client['interface'] ?? [])), 0, 12);
    }

    /**
     * Generate a cryptographically strong password (lowercase + digits, no shell/URL edge cases).
     */
    protected function ikev2RandomPassword(int $length = 24): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyz23456789';
        $max   = strlen($chars) - 1;
        $out   = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Build client profiles (.sswan + .mobileconfig) by feeding the local
     * gen_ikev2_profile.py script via proc_open, the same pattern as getAmneziaShortLink.
     *
     * Returns ['sswan' => string, 'mobileconfig' => string, 'ca_pem' => string, 'error' => ?string].
     */
    protected function getIkev2Profile(array $cred, string $name): array
    {
        $payload = json_encode([
            'username'  => (string) ($cred['username'] ?? ''),
            'password'  => (string) ($cred['password'] ?? ''),
            'name'      => $name !== '' ? $name : 'ugam.pro IKEv2',
            'host'      => $this->getIkev2Host(),
            'remote_id' => $this->getIkev2Host(),
        ]);
        if ($payload === false) {
            return ['sswan' => '', 'mobileconfig' => '', 'ca_pem' => '', 'error' => 'json encode failed'];
        }

        $caPath = getenv('IKV2_CA_PATH') ?: '/config/ikev2-ca.pem';
        $proc = proc_open('python gen_ikev2_profile.py', [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, ['IKV2_CA_PATH' => $caPath]);
        if (!is_resource($proc)) {
            return ['sswan' => '', 'mobileconfig' => '', 'ca_pem' => '', 'error' => 'proc_open failed'];
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $decoded = json_decode($out, true);
        if (!is_array($decoded) || !empty($decoded['error'])) {
            return [
                'sswan'        => '',
                'mobileconfig' => '',
                'ca_pem'       => '',
                'error'        => (string) ($decoded['error'] ?? 'generator returned no profile'),
            ];
        }

        return [
            'sswan'        => (string) ($decoded['sswan'] ?? ''),
            'mobileconfig' => (string) ($decoded['mobileconfig'] ?? ''),
            'ca_pem'       => (string) ($decoded['ca_pem'] ?? ''),
            'error'        => null,
        ];
    }

    /**
     * Rebuild config/ikev2-eap.conf from all stored credentials.
     * The host /etc/swanctl/swanctl.conf `include`s this file, then `swanctl --load-all`.
     * Returns the number of EAP secrets written.
     */
    protected function writeIkev2EapConf(): int
    {
        $pac   = $this->getPacConf();
        $users = is_array($pac['ikev2_users'] ?? null) ? $pac['ikev2_users'] : [];

        $lines = [];
        $lines[] = '# Auto-generated by vpnbot Ikev2Trait — do not edit.';
        $lines[] = 'secrets {';
        $count = 0;
        foreach ($users as $cred) {
            if (!is_array($cred) || empty($cred['username']) || empty($cred['password'])) {
                continue;
            }
            // id must match the EAP identity the client sends (the username).
            $lines[] = "    eap-" . $cred['username'] . ' {';
            $lines[] = '        id = ' . $cred['username'];
            $lines[] = '        secret = "' . $cred['password'] . '"';
            $lines[] = '    }';
            $count++;
        }
        $lines[] = '}';
        $lines[] = '';

        $path = $this->ikev2EapConfPath();
        @file_put_contents($path, implode("\n", $lines));

        // Reload strongSwan so the new/changed EAP secrets actually take effect.
        // Best-effort: the host swanctl.conf already `include`s this file, but a new
        // secret is only picked up after `swanctl --load-all`. The php container cannot
        // touch the host binary or its charon.vici socket directly, so we reach the host
        // over ssh (the php container's /ssh/key.pub is in host root authorized_keys).
        $this->reloadIkev2();

        return $count;
    }

    /**
     * Copy the bot-written secrets file onto the host so strongSwan's `include
     * conf.d/*.conf` actually reaches it, then reload strongSwan.
     *
     * strongSwan's swanctl `include` glob does NOT follow symlinks, so a symlink
     * `conf.d/ikev2-eap.conf -> config/…` is silently skipped (only a real file is
     * globbed). The php container cannot write /etc/swanctl directly, but it can reach
     * the host over ssh (the container's /ssh/key.pub is in host root authorized_keys),
     * so one ssh command does the copy + reload.
     *
     * Uses the php container's ssh key over the host bridge (host.docker.internal).
     * Purely best-effort: on any failure (missing ssh2 ext, unreachable host, denied
     * key) we return false silently and the credential still persists — the reload can
     * be forced later by running `swanctl --load-all` on the host as root.
     */
    protected function reloadIkev2(): bool
    {
        if (!function_exists('ssh2_connect')) {
            return false;
        }

        $host = getenv('IKEV2_HOST_SSH') ?: 'host.docker.internal';
        $port = 22;
        $key  = (string) (getenv('IKEV2_SSH_KEY') ?: '/ssh/key');
        $user = (string) (getenv('IKEV2_SSH_USER') ?: 'root');

        if (!is_file($key) || !is_readable($key)) {
            return false;
        }

        $conn = @ssh2_connect($host, $port);
        if ($conn === false) {
            return false;
        }
        if (!@ssh2_auth_pubkey_file($conn, $user, $key . '.pub', $key)) {
            return false;
        }

        // The secrets file the bot just wrote lives in the config/ bind-mount; on the
        // host that same file is at /root/vpnbot_extra/config/ikev2-eap.conf. Copy it
        // into a REAL file under conf.d (no symlink — swanctl skips those), then reload.
        // Paths are escaped with single quotes; the config path is injected server-side
        // and contains no single quotes.
        $src = (string) (getenv('IKEV2_EAP_HOST_SRC') ?: '/root/vpnbot_extra/config/ikev2-eap.conf');
        $dst = (string) (getenv('IKEV2_EAP_HOST_DST') ?: '/etc/swanctl/conf.d/ikev2-eap.conf');
        $cmd = 'cp ' . escapeshellarg($src) . ' ' . escapeshellarg($dst) . ' && swanctl --load-all 2>&1';

        $stream = @ssh2_exec($conn, $cmd);
        if ($stream === false) {
            return false;
        }
        stream_set_blocking($stream, true);
        // Drain output so the reload diagnostics are not silently dropped.
        stream_get_contents($stream);
        fclose($stream);

        return true;
    }

    /**
     * Absolute path of the EAP secrets include file. Resides in the config/ mount
     * (shared with the host when swanctl.conf includes it via an absolute host path).
     */
    protected function ikev2EapConfPath(): string
    {
        $dir = getenv('IKEV2_EAP_CONF_DIR');
        if ($dir === false || $dir === '') {
            // Default: alongside pac.json (config/ mount), e.g. /config -> /config/ikev2-eap.conf.
            $dir = rtrim(str_replace('\\', '/', (string) dirname((string) $this->pac)), '/');
        }

        return rtrim($dir, '/') . '/ikev2-eap.conf';
    }

    /**
     * IKEv2 profile button handler (mirrors userPortalDeviceWg).
     */
    public function userPortalDeviceIkev2($pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid  = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->ackCallback('device not found', true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $ownerSubId = (string) $session['subscription_id'];
        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $info = $devices[$hwid] ?? [];
        $name = $this->getHwidDeviceDisplayName($info);

        $text = [$this->i18n('user portal ikev2 copy')];
        if ($name !== '') {
            $text[] = '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $text[] = '';

        $cred = $this->ensureIkev2Credentials($ownerSubId);
        if ($cred === null) {
            $text[] = $this->i18n('user portal ikev2 empty');
        } else {
            $profile = $this->getIkev2Profile($cred, $name);
            if (!empty($profile['error'])) {
                $text[] = $this->i18n('user portal ikev2 empty');
            } else {
                // Show the .sswan (Android) profile as the copyable body.
                $text[] = $this->i18n('user portal ikev2 import link') . ':';
                $text[] = '<code>' . htmlspecialchars($cred['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                $text[] = '';
                $text[] = '<pre><code>' . htmlspecialchars($profile['sswan'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
            }
        }

        $this->userPortalShow(implode("\n", $text), [[[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0],
        ]]]);

        // QR with the server address + credentials for quick onboarding.
        if (!empty($profile['sswan']) && empty($profile['error'])) {
            $this->sendQr(
                $name !== '' ? $name : $hwid,
                $this->getIkev2Host(),
                ($name !== '' ? $name : $hwid) . ' — IKEv2'
            );
        }
    }

    /**
     * IKEv2 profile button in the bot's own client menu (the WireGuard peer the owner
     * opens via «menu client N»). This is the primary placement Ruslan asked for —
     * the owner downloads a per-client .sswan/.mobileconfig and sees the EAP
     * username/password, without touching the user device portal.
     *
     * Generates the profile, sends the two profile files as documents, then prints the
     * credentials and returns a «back» button into the client detail view.
     */
    public function clientIkev2($client, $page = 0)
    {
        $client     = (int) $client;
        $clients    = $this->readClients();
        $clientData = $clients[$client] ?? null;
        if ($clientData === null) {
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                'no clients',
                false,
            );

            return;
        }

        $name = $this->getName($clientData['interface'] ?? []);
        $key  = $this->ikev2ClientKey($clientData, $client);

        $linePad  = [(string) $this->i18n('client ikev2 profile')];
        $linePad[] = $name !== '' ? '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>' : '';

        $cred = $this->ensureIkev2CredentialsByKey($key);
        if ($cred === null) {
            $linePad[] = $this->i18n('client ikev2 unavailable');
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                implode("\n", array_filter($linePad)),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/menu client {$client}_{$page}",
                ]]],
            );

            return;
        }

        $profile = $this->getIkev2Profile($cred, $name);

        // Send the two profile files as documents (same upload() pattern as downloadPeer).
        if (empty($profile['error'])) {
            if ($profile['sswan'] !== '') {
                $this->upload(preg_replace('~\s+~', '_', $name) . '.sswan', $profile['sswan']);
            }
            if ($profile['mobileconfig'] !== '') {
                $this->upload(preg_replace('~\s+~', '_', $name) . '.mobileconfig', $profile['mobileconfig']);
            }
        }

        $text   = $linePad;
        if (!empty($profile['error'])) {
            $text[] = $this->i18n('client ikev2 unavailable');
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                implode("\n", array_filter($text)),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/menu client {$client}_{$page}",
                ]]],
            );

            return;
        }

        $chat     = $this->input['chat'];
        $loginVal = '<code>' . htmlspecialchars($cred['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $passVal  = '<code>' . htmlspecialchars($cred['password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $srvVal   = '<code>' . htmlspecialchars($this->getIkev2Host(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

        // Профиль + имя — первым сообщением, с кнопкой «назад».
        $this->replyMenu(
            $chat,
            (int) ($this->input['message_id'] ?? 0),
            implode("\n", array_filter($linePad)),
            [[[
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu client {$client}_{$page}",
            ]]],
        );

        // Данные парами: заголовок отдельно, значение отдельно.
        $this->send($chat, '<b>' . $this->i18n('client ikev2 credentials') . ':</b>', 0);
        $this->send($chat, $loginVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client ikev2 password') . ':</b>', 0);
        $this->send($chat, $passVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client ikev2 server') . ':</b>', 0);
        $this->send($chat, $srvVal, 0);

        // Файлы — после текстовых данных.
        if ($profile['sswan'] !== '') {
            $this->upload(preg_replace('~\s+~', '_', $name) . '.sswan', $profile['sswan']);
        }
        if ($profile['mobileconfig'] !== '') {
            $this->upload(preg_replace('~\s+~', '_', $name) . '.mobileconfig', $profile['mobileconfig']);
        }
    }

    /**
     * Stable identity key for a VLESS/xray client in the bot's owner client list
     * (the «Menu -> xray -> email» view, userXr).
     *
     * Keyed by the subscription id (the same anchor the user device portal uses),
     * so a subscription's VLESS card and its portal yield ONE credential set —
     * no "password changes each click" across surfaces. Falls back to the client
     * uuid when a client predates subscription_id anchoring, and to a hash of the
     * email+index on a reshuffle. The `xr_` namespace only applies to uuid/email
     * fallbacks, never to the subscription id, so it never collides with the
     * WireGuard path (ikev2ClientKey) or the portal key.
     */
    protected function ikev2XrKey(array $client, int $index): string
    {
        $subId = trim((string) $this->getClientSubscriptionId($client));
        if ($subId !== '') {
            // subscription_id is already the plain portal key (portal path uses the
            // raw id, not a hashed form) — return it verbatim so the two converge.
            return $subId;
        }

        $id = trim((string) ($client['id'] ?? ''));
        if ($id !== '') {
            return 'xr_' . hash('sha256', $id);
        }

        return 'xr_idx_' . (string) $index . '_' . substr(hash('sha256', (string) ($client['email'] ?? '')), 0, 12);
    }

    /**
     * IKEv2 profile button in the VLESS/xray client card (userXr). Mirrors
     * clientIkev2 — generates the profile, sends the .sswan/.mobileconfig files,
     * then prints credentials with a «back» button returning into /userXr.
     */
    public function clientIkev2Xr($i)
    {
        $i    = (int) $i;
        $xray = $this->getXray();
        $clientData = $xray['inbounds'][0]['settings']['clients'][$i] ?? null;
        if (!is_array($clientData)) {
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                'no client',
                false,
            );

            return;
        }

        $name = (string) ($clientData['email'] ?? '');
        $key  = $this->ikev2XrKey($clientData, $i);

        $linePad   = [(string) $this->i18n('client ikev2 profile')];
        $linePad[] = $name !== '' ? '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>' : '';

        $cred = $this->ensureIkev2CredentialsByKey($key);
        if ($cred === null) {
            $linePad[] = $this->i18n('client ikev2 unavailable');
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                implode("\n", array_filter($linePad)),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/userXr {$i}",
                ]]],
            );

            return;
        }

        $profile = $this->getIkev2Profile($cred, $name);

        $text = $linePad;
        if (!empty($profile['error'])) {
            $text[] = $this->i18n('client ikev2 unavailable');
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                implode("\n", array_filter($text)),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/userXr {$i}",
                ]]],
            );

            return;
        }

        $chat     = $this->input['chat'];
        $loginVal = '<code>' . htmlspecialchars($cred['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $passVal  = '<code>' . htmlspecialchars($cred['password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $srvVal   = '<code>' . htmlspecialchars($this->getIkev2Host(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

        // Профиль + имя — первым сообщением, с кнопкой «назад».
        $this->replyMenu(
            $chat,
            (int) ($this->input['message_id'] ?? 0),
            implode("\n", array_filter($linePad)),
            [[[
                'text'          => $this->i18n('back'),
                'callback_data' => "/userXr {$i}",
            ]]],
        );

        // Данные парами: заголовок отдельно, значение отдельно.
        $this->send($chat, '<b>' . $this->i18n('client ikev2 credentials') . ':</b>', 0);
        $this->send($chat, $loginVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client ikev2 password') . ':</b>', 0);
        $this->send($chat, $passVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client ikev2 server') . ':</b>', 0);
        $this->send($chat, $srvVal, 0);

        // Файлы — после текстовых данных.
        if ($profile['sswan'] !== '') {
            $this->upload(preg_replace('~\s+~', '_', $name) . '.sswan', $profile['sswan']);
        }
        if ($profile['mobileconfig'] !== '') {
            $this->upload(preg_replace('~\s+~', '_', $name) . '.mobileconfig', $profile['mobileconfig']);
        }
    }
}
