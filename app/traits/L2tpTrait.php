<?php

/**
 * L2TP/IPsec (нативный «L2TP» в iOS/macOS, «L2TP/IPsec с общим ключом» в Windows)
 * — IKEv1 + XAuth + PSK, сосед IKEv2 на том же charon (strongSwan).
 *
 * Отличие от IKEv2: аутентификация сервера — общий ключ (PSK), а не CA-сертификат,
 * а клиент терминирует L2TP/PPP на своей стороне. Поэтому профиль выдаёт не
 * CA-PEM, а трио: сервер / логин / пароль / общий ключ (PSK).
 *
 * Shared PSK — один на весь узел (стиль VIP-ДЦ: «servername + общий ключ»),
 * хранится в pac['l2tp_psk']. Логин/пароль — на клиента, в pac['l2tp_users'][key].
 */
trait L2tpTrait
{
    /**
     * Флаг доступности L2TP/IPsec для клиента. Суффиксное зеркало isIkev2Enabled():
     * гейт на отдельном флаге `l2tp` в transport-registry, чтобы два IPsec-соседа
     * включались независимо.
     */
    protected function isL2tpEnabled(array $client): bool
    {
        $pac   = $this->getPacConf();
        $flags = $this->getClientTransportFlags($client, $pac);

        return !empty($flags['l2tp']);
    }

    /**
     * Хост/IP, на который указывает профиль. Единый выбор с IKEv2: pac['l2tp_host']
     * -> pac['ikev2_host'] -> pac['domain'] -> ip.
     */
    protected function getL2tpHold(): string
    {
        return $this->getIkev2Host();
    }

    /**
     * Общий ключ (PSK) узла. Из pac['l2tp_psk'], при отсутствии — детерминированный
     * дефолт по hash бота (не сетевой секрет, не должен быть пересказан пользователю
     * дважды — он и так выдаётся в профиле).
     */
    protected function l2tpPsk(): string
    {
        $pac = $this->getPacConf();
        $psk = trim((string) ($pac['l2tp_psk'] ?? ''));
        if ($psk !== '') {
            return $psk;
        }

        return substr(hash('sha256', 'l2tp-psk:' . $this->getHashBot()), 0, 32);
    }

    /**
     * Вернуть (или лениво создать) XAuth-credentials для L2TP/IPsec, по стабильному
     * ключу (subscription id — как у IKEv2, чтобы серверный store был единым
     * anchor'ом). Хранится в pac['l2tp_users'][key] = {username, password, created_at}.
     * idempotent: повторный вызов не меняет пароль.
     */
    protected function ensureL2tpCredentialsByKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $pac   = $this->getPacConf();
        $users = is_array($pac['l2tp_users'] ?? null) ? $pac['l2tp_users'] : [];

        $cred = $users[$key] ?? null;
        if (is_array($cred) && !empty($cred['username']) && !empty($cred['password'])) {
            return $cred;
        }

        $username = 'l_' . substr(hash('sha256', $key . ':' . $this->getHashBot()), 0, 12);
        $password = $this->l2tpRandomPassword(24);

        $users[$key] = [
            'username'   => $username,
            'password'   => $password,
            'created_at' => date('c'),
        ];
        $pac['l2tp_users'] = $users;
        $this->setPacConf($pac);

        // Серверный IKEv1-XAuth секрет синхронизируется в conf.d вместе с EAP-секретами
        // IKEv2 — единый include-файл, один swanctl --load-all.
        $this->writeL2tpSecretsConf();

        return $users[$key];
    }

    /**
     * Стабильный ключ для WireGuard-клиента — тот же, что у IKEv2 (по PublicKey /
     * index-hash). Один WG-клиент = один набор L2TP-credentials.
     */
    protected function l2tpClientKey(array $client, int $index): string
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
     * Стабильный ключ для VLESS/xray-клиента — тот же anchor, что у IKEv2 (subscription_id
     * первичен, иначе uuid/email-hash). Одна подписка = один набор L2TP-credentials.
     */
    protected function l2tpXrKey(array $client, int $index): string
    {
        $subId = trim((string) $this->getClientSubscriptionId($client));
        if ($subId !== '') {
            return $subId;
        }

        $id = trim((string) ($client['id'] ?? ''));
        if ($id !== '') {
            return 'xr_' . hash('sha256', $id);
        }

        return 'xr_idx_' . (string) $index . '_' . substr(hash('sha256', (string) ($client['email'] ?? '')), 0, 12);
    }

    /**
     * Криптостойкий пароль (lowercase + digits).
     */
    protected function l2tpRandomPassword(int $length = 24): string
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
     * Собрать профили (.sswan + .mobileconfig) через локальный gen_l2tp_profile.py
     * (шаблон getIkev2Profile).
     */
    protected function getL2tpProfile(array $cred, string $name): array
    {
        $payload = json_encode([
            'username' => (string) ($cred['username'] ?? ''),
            'password' => (string) ($cred['password'] ?? ''),
            'psk'      => (string) $this->l2tpPsk(),
            'name'     => $name !== '' ? $name : 'ugam.pro L2TP',
            'host'     => $this->getL2tpHold(),
        ]);
        if ($payload === false) {
            return ['sswan' => '', 'mobileconfig' => '', 'error' => 'json encode failed'];
        }

        $proc = proc_open('python gen_l2tp_profile.py', [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null);
        if (!is_resource($proc)) {
            return ['sswan' => '', 'mobileconfig' => '', 'error' => 'proc_open failed'];
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
                'error'        => (string) ($decoded['error'] ?? 'generator returned no profile'),
            ];
        }

        return [
            'sswan'        => (string) ($decoded['sswan'] ?? ''),
            'mobileconfig' => (string) ($decoded['mobileconfig'] ?? ''),
            'error'        => null,
        ];
    }

    /**
     * Переписать include-файл IKEv1-XAuth секретов (PSK + клиентские XAuth) и
     * перезагрузить strongSwan. Одна PSK-секция на весь узел, по одной id/xauth на
     * клиента. Возвращает число XAuth-секретов (без PSK).
     */
    protected function writeL2tpSecretsConf(): int
    {
        $pac   = $this->getPacConf();
        $users = is_array($pac['l2tp_users'] ?? null) ? $pac['l2tp_users'] : [];

        $lines   = [];
        $lines[] = '# Auto-generated by vpnbot L2tpTrait — do not edit.';
        $lines[] = 'secrets {';
        // PSK без id — совпадает с любой IKE-identity (канонический паттерн для
        // нативного L2TP «общий ключ»; см. strongSwan swanctl.conf secrets.ike).
        $lines[] = '    ike-l2tp {';
        $lines[] = '        secret = "' . $this->l2tpPsk() . '"';
        $lines[] = '    }';
        $count = 0;
        foreach ($users as $cred) {
            if (!is_array($cred) || empty($cred['username']) || empty($cred['password'])) {
                continue;
            }
            // XAuth: серверное «xauth-<username>» id должен совпадать с XAuth-identity.
            $lines[] = '    xauth-' . $cred['username'] . ' {';
            $lines[] = '        id = ' . $cred['username'];
            $lines[] = '        secret = "' . $cred['password'] . '"';
            $lines[] = '    }';
            $count++;
        }
        $lines[] = '}';
        $lines[] = '';

        @file_put_contents($this->l2tpSecretsConfPath(), implode("\n", $lines));
        $this->reloadL2tp();

        return $count;
    }

    /**
     * Скопировать l2tp-secrets.conf на хост в conf.d и перезагрузить strongSwan.
     * Пара reloadIkev2(): тот же ssh-транспорт, но свой src/dst (l2tp, а не ikev2-eap).
     */
    protected function reloadL2tp(): bool
    {
        return $this->reloadSwanctl(
            (string) (getenv('L2TP_SECRETS_HOST_SRC') ?: '/root/vpnbot_extra/config/l2tp-secrets.conf'),
            (string) (getenv('L2TP_SECRETS_HOST_DST') ?: '/etc/swanctl/conf.d/l2tp-secrets.conf')
        );
    }

    /**
     * Абсолютный путь include-файла IKEv1-секретов (в config/ mount, рядом с pac.json).
     */
    protected function l2tpSecretsConfPath(): string
    {
        $dir = getenv('L2TP_SECRETS_CONF_DIR');
        if ($dir === false || $dir === '') {
            $dir = rtrim(str_replace('\\', '/', (string) dirname((string) $this->pac)), '/');
        }

        return rtrim($dir, '/') . '/l2tp-secrets.conf';
    }

    /**
     * Главно-меню точка входа для IPsec-профилей. `$mode` — 'ikev2' или 'l2tp'.
     * Показывает список WireGuard-клиентов (как getClients) с кнопкой-выбором,
     * которая ведёт либо на /clientIkev2, либо на /clientL2tp для выбранного клиента.
     * Вариант B: кнопка сперва просит выбрать клиента, потом генерит профиль.
     */
    public function iprofileMenu(string $mode, int $page = 0)
    {
        $mode = $mode === 'l2tp' ? 'l2tp' : 'ikev2';
        $clients = $this->readClients();
        if (empty($clients)) {
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                $this->i18n('iprofile empty'),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => '/menu',
                ]]]
            );

            return;
        }

        $count = $this->limit;
        $all   = (int) ceil(count($clients) / $count);
        $page  = min($page, max($all - 1, 0));
        $slice = array_slice($clients, $page * $count, $count, true);

        $data = [];
        foreach ($slice as $k => $v) {
            $cb = $mode === 'ikev2'
                ? "/clientIkev2 {$k}_{$page}"
                : "/clientL2tp {$k}_{$page}";
            $data[] = [[
                'text'          => $this->getName($v['interface'] ?? []),
                'callback_data' => $cb,
            ]];
        }
        if ($all > 1) {
            $data[] = [
                ['text' => '<<', 'callback_data' => "/iprofileMenu {$mode} " . ($page - 1 >= 0 ? $page - 1 : $all - 1)],
                ['text' => (string) ($page + 1), 'callback_data' => "/iprofileMenu {$mode} {$page}"],
                ['text' => '>>', 'callback_data' => "/iprofileMenu {$mode} " . ($page < $all - 1 ? $page + 1 : 0)],
            ];
        }
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/menu',
        ]];

        $this->replyMenu(
            $this->input['chat'],
            (int) ($this->input['message_id'] ?? 0),
            $mode === 'ikev2' ? $this->i18n('iprofile pick ikev2') : $this->i18n('iprofile pick l2tp'),
            $data
        );
    }

    /**
     * L2TP/IPsec профиль для WireGuard-клиента (зеркало clientIkev2). Генерит
     * .sswan/.mobileconfig, шлёт файлы и печатает сервер/логин/пароль/PSK.
     */
    public function clientL2tp($client, $page = 0)
    {
        $client     = (int) $client;
        $clients    = $this->readClients();
        $clientData = $clients[$client] ?? null;
        if ($clientData === null) {
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                $this->i18n('iprofile empty'),
                false,
            );

            return;
        }

        $name = $this->getName($clientData['interface'] ?? []);
        $key  = $this->l2tpClientKey($clientData, $client);

        $linePad   = [(string) $this->i18n('client l2tp profile')];
        $linePad[] = $name !== '' ? '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>' : '';

        $cred = $this->ensureL2tpCredentialsByKey($key);
        if ($cred === null) {
            $linePad[] = $this->i18n('client l2tp unavailable');
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                implode("\n", array_filter($linePad)),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/menu client {$client}_{$page}",
                ]]]
            );

            return;
        }

        $profile = $this->getL2tpProfile($cred, $name);

        if (!empty($profile['error'])) {
            $text   = $linePad;
            $text[] = $this->i18n('client l2tp unavailable');
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                implode("\n", array_filter($text)),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/menu client {$client}_{$page}",
                ]]]
            );

            return;
        }

        $chat     = $this->input['chat'];
        $loginVal = '<code>' . htmlspecialchars($cred['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $passVal  = '<code>' . htmlspecialchars($cred['password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $pskVal   = '<code>' . htmlspecialchars($this->l2tpPsk(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $srvVal   = '<code>' . htmlspecialchars($this->getL2tpHold(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

        $this->replyMenu(
            $chat,
            (int) ($this->input['message_id'] ?? 0),
            implode("\n", array_filter($linePad)),
            [[[
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu client {$client}_{$page}",
            ]]]
        );

        $this->send($chat, '<b>' . $this->i18n('client l2tp credentials') . ':</b>', 0);
        $this->send($chat, $loginVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client l2tp password') . ':</b>', 0);
        $this->send($chat, $passVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client l2tp psk') . ':</b>', 0);
        $this->send($chat, $pskVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client l2tp server') . ':</b>', 0);
        $this->send($chat, $srvVal, 0);

        if ($profile['sswan'] !== '') {
            $this->upload(preg_replace('~\s+~', '_', $name) . '_l2tp.sswan', $profile['sswan']);
        }
        if ($profile['mobileconfig'] !== '') {
            $this->upload(preg_replace('~\s+~', '_', $name) . '_l2tp.mobileconfig', $profile['mobileconfig']);
        }
    }

    /**
     * L2TP/IPsec профиль для VLESS/xray-клиента (зеркало clientIkev2Xr).
     */
    public function clientL2tpXr($i)
    {
        $i    = (int) $i;
        $xray = $this->getXray();
        $clientData = $xray['inbounds'][0]['settings']['clients'][$i] ?? null;
        if (!is_array($clientData)) {
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                $this->i18n('iprofile empty'),
                false,
            );

            return;
        }

        $name = (string) ($clientData['email'] ?? '');
        $key  = $this->l2tpXrKey($clientData, $i);

        $linePad   = [(string) $this->i18n('client l2tp profile')];
        $linePad[] = $name !== '' ? '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>' : '';

        $cred = $this->ensureL2tpCredentialsByKey($key);
        if ($cred === null) {
            $linePad[] = $this->i18n('client l2tp unavailable');
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                implode("\n", array_filter($linePad)),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/userXr {$i}",
                ]]]
            );

            return;
        }

        $profile = $this->getL2tpProfile($cred, $name);

        if (!empty($profile['error'])) {
            $text   = $linePad;
            $text[] = $this->i18n('client l2tp unavailable');
            $this->replyMenu(
                $this->input['chat'],
                (int) ($this->input['message_id'] ?? 0),
                implode("\n", array_filter($text)),
                [[[
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/userXr {$i}",
                ]]]
            );

            return;
        }

        $chat     = $this->input['chat'];
        $loginVal = '<code>' . htmlspecialchars($cred['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $passVal  = '<code>' . htmlspecialchars($cred['password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $pskVal   = '<code>' . htmlspecialchars($this->l2tpPsk(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $srvVal   = '<code>' . htmlspecialchars($this->getL2tpHold(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

        $this->replyMenu(
            $chat,
            (int) ($this->input['message_id'] ?? 0),
            implode("\n", array_filter($linePad)),
            [[[
                'text'          => $this->i18n('back'),
                'callback_data' => "/userXr {$i}",
            ]]]
        );

        $this->send($chat, '<b>' . $this->i18n('client l2tp credentials') . ':</b>', 0);
        $this->send($chat, $loginVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client l2tp password') . ':</b>', 0);
        $this->send($chat, $passVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client l2tp psk') . ':</b>', 0);
        $this->send($chat, $pskVal, 0);
        $this->send($chat, '<b>' . $this->i18n('client l2tp server') . ':</b>', 0);
        $this->send($chat, $srvVal, 0);

        if ($profile['sswan'] !== '') {
            $this->upload(preg_replace('~\s+~', '_', $name) . '_l2tp.sswan', $profile['sswan']);
        }
        if ($profile['mobileconfig'] !== '') {
            $this->upload(preg_replace('~\s+~', '_', $name) . '_l2tp.mobileconfig', $profile['mobileconfig']);
        }
    }
}
