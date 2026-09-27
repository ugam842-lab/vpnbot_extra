<?php

trait UserPortalTrait
{
    protected function isUserPortalEnabled(): bool
    {
        $pac = $this->getPacConf();

        return !empty($pac['user_portal_enabled']);
    }

    protected function ensureUserPortalSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id((string) ($this->input['from'] ?? ''));
            session_start();
        }
    }

    protected function isUserPortalReplyCallback(string $callback): bool
    {
        return in_array($callback, [
            'userPortalImportLink',
            'userPortalDeleteDevicePassword',
            'userPortalSavePassword',
            'userPortalChangePasswordVerify',
            'userPortalRenameDeviceSave',
            'userPortalSupportSave',
            'userPortalSupportReplySave',
        ], true);
    }

    protected function getUserPortalUiMessageId(): ?int
    {
        $this->ensureUserPortalSession();
        $id = (int) ($_SESSION['userPortalUi']['message_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    protected function setUserPortalUiMessageId(int $messageId): void
    {
        $this->ensureUserPortalSession();
        $_SESSION['userPortalUi']['message_id'] = $messageId;
    }

    protected function userPortalSetFlash(string $text): void
    {
        $this->ensureUserPortalSession();
        $_SESSION['userPortalUi']['flash'] = $text;
    }

    protected function userPortalTakeFlash(): string
    {
        $this->ensureUserPortalSession();
        $flash = trim((string) ($_SESSION['userPortalUi']['flash'] ?? ''));
        unset($_SESSION['userPortalUi']['flash']);

        return $flash;
    }

    protected function resolveUserPortalMessageId(): int
    {
        if (!empty($this->input['callback']) && !empty($this->input['message_id'])) {
            return (int) $this->input['message_id'];
        }

        return (int) ($this->getUserPortalUiMessageId() ?? 0);
    }

    protected function userPortalShow(string $text, $buttons = false, $replyPlaceholder = false, ?array $replyState = null): void
    {
        $chat = $this->input['chat'];
        $messageId = $this->resolveUserPortalMessageId();

        if ($replyState !== null && $messageId > 0) {
            $_SESSION['reply'][$messageId] = array_merge($replyState, [
                'start_message'  => $messageId,
                'start_callback' => $this->input['callback_id'] ?? false,
                'keep_message' => true,
            ]);
        }

        if ($messageId > 0) {
            $r = $this->update($chat, $messageId, $text, $buttons ?: false, $replyPlaceholder !== false ? $replyPlaceholder : false);
            if (!empty($r['ok'])) {
                $this->setUserPortalUiMessageId($messageId);

                return;
            }
            // Edit вернул ошибку. Если это «message is not modified» — меню на
            // экране уже корректно, повторный send() только задвоит его.
            // Сохраняем id, даём тост (клик дошёл), и выходим без повторной
            // отправки. Тост возможен только для inline-клика — для команды-
            // сообщения /update id заранее сброшен (см. userPortalMenu), поэтому
            // сюда она не попадает.
            if ($this->isMessageNotModified($r)) {
                $this->setUserPortalUiMessageId($messageId);
                $cbId = $this->input['callback_id'] ?? false;
                if (!empty($cbId)) {
                    $this->answer($cbId, $this->i18n('user portal up to date'));
                }

                return;
            }
            unset($_SESSION['userPortalUi']['message_id'], $_SESSION['reply'][$messageId]);
        }

        $r = $this->send($chat, $text, false, $buttons ?: false, $replyPlaceholder !== false ? $replyPlaceholder : false);
        if (!empty($r['result']['message_id'])) {
            $newId = (int) $r['result']['message_id'];
            $this->setUserPortalUiMessageId($newId);
            if ($replyState !== null) {
                $_SESSION['reply'][$newId] = array_merge($replyState, [
                    'start_message'  => $newId,
                    'start_callback' => $this->input['callback_id'] ?? false,
                    'keep_message' => true,
                ]);
            }
        }
    }

    protected function isMessageNotModified($r): bool
    {
        return !empty($r)
            && empty($r['ok'])
            && stripos((string) ($r['description'] ?? ''), 'not modified') !== false;
    }

    protected function userPortalPromptInput(string $text, string $callback, array $args = [], $buttons = false): void
    {
        $data = is_array($buttons) ? $buttons : [];
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]];
        $placeholder = match ($callback) {
            'userPortalImportLink'            => $this->i18n('user portal send old link'),
            'userPortalDeleteDevicePassword'  => $this->i18n('user portal enter delete password'),
            'userPortalSavePassword'          => $this->i18n('user portal enter new password'),
            'userPortalChangePasswordVerify'  => $this->i18n('user portal enter current password'),
            'userPortalRenameDeviceSave'      => $this->i18n('user portal enter device name'),
            'userPortalSupportSave'           => $this->i18n('support placeholder'),
            'userPortalSupportReplySave'      => $this->i18n('support placeholder'),
            default                           => '',
        };
        $this->userPortalShow($text, $data, $placeholder, [
            'callback' => $callback,
            'args'     => $args,
        ]);
    }

    protected function userPortalDeleteUserMessage(): void
    {
        $messageId = (int) ($this->input['message_id'] ?? 0);
        if ($messageId > 0 && empty($this->input['callback'])) {
            $this->delete($this->input['chat'], $messageId);
        }
    }

    protected function getPendingUserPortalReply(): ?array
    {
        $this->ensureUserPortalSession();
        foreach ($_SESSION['reply'] ?? [] as $messageId => $reply) {
            if (!is_array($reply)) {
                continue;
            }
            $callback = (string) ($reply['callback'] ?? '');
            if ($this->isUserPortalReplyCallback($callback)) {
                return array_merge($reply, ['message_id' => $messageId]);
            }
        }

        return null;
    }

    protected function clearPendingUserPortalReply(?string $callback = null): void
    {
        $this->ensureUserPortalSession();
        foreach ($_SESSION['reply'] ?? [] as $messageId => $reply) {
            if (!is_array($reply)) {
                continue;
            }
            $cb = (string) ($reply['callback'] ?? '');
            if ($callback !== null && $cb !== $callback) {
                continue;
            }
            if ($this->isUserPortalReplyCallback($cb)) {
                if (empty($reply['keep_message'])) {
                    $this->delete($this->input['chat'], $messageId);
                }
                unset($_SESSION['reply'][$messageId]);
            }
        }
        if (empty($_SESSION['reply'])) {
            unset($_SESSION['reply']);
        }
    }

    protected function buildUserPortalAccountInfoLines(array $session): array
    {
        $client = $session['client'];
        $pac = $this->getPacConf();
        $st = $this->getXrayStats();
        $lines = [];
        $email = (string) ($client['email'] ?? '');
        if ($email !== '') {
            $lines[] = $this->i18n('user portal account') . ': <code>' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        }

        $time = (int) ($client['time'] ?? 0);
        if ($time > 0) {
            $date = date('d.m.Y H:i', $time);
            if ($time > time()) {
                $lines[] = $this->i18n('user portal expires') . ': ' . $date . ' (' . $this->getTime($time) . ')';
            } else {
                $lines[] = $this->i18n('user portal expired') . ': ' . $date;
            }
        } else {
            $lines[] = $this->i18n('user portal expires') . ': ' . $this->i18n('user portal no expiry');
        }

        $totals = $this->getSubscriptionXrayTrafficTotals($st, $client, $session['clientIndex']);
        $trafficLine = $this->i18n('user portal traffic') . ': ' . $this->formatTrafficUpDown((int) $totals['download'], (int) $totals['upload']);
        $limit = $this->getClientTrafficLimitBytes($client, $pac);
        if ($limit > 0) {
            $trafficLine .= ' / ' . $this->getBytes($limit);
        }
        $lines[] = $trafficLine;

        $ownerSubId = $session['subscription_id'];
        $deviceCount = count($this->getHwidDevicesByUser($ownerSubId));
        $lines[] = $this->i18n('hwid devices') . ': ' . $deviceCount;
        if ($this->isRuntimeDeviceWgEnabled($client)) {
            $ownerIdx = $this->getOwnerXrayClientIndexBySubId($ownerSubId);
            $awgLimit = $this->getAwgLimit($ownerIdx);
            $lines[] = $this->i18n('awg limit') . ': ' . $deviceCount . ' ' . $this->i18n('of') . ' ' . $awgLimit;
        }

        // Явная сводка «сколько выдано»: VLESS-подписка + число Amnezia/WG конфигов.
        $lines[] = '';
        $lines[] = $this->i18n('user portal issued title') . ':';
        $vlessCount = $ownerSubId !== '' ? 1 : 0;
        $lines[] = '· VLESS — ' . $vlessCount . ' ' . $this->i18n('user portal issued vless');
        $lines[] = '· Amnezia/WG — ' . $deviceCount . ' ' . $this->i18n('user portal issued wg');

        $lines[] = $this->i18n('user portal delete password') . ': ' . $this->i18n(
            $this->hasSubscriptionDevicePassword($client) ? 'user portal password set' : 'user portal password not set'
        );

        return $lines;
    }

    protected function buildUserProtocolCardLines(array $session): array
    {
        $pac = $this->getPacConf();
        $flags = $this->getClientTransportFlags($session['client'], $pac);
        $lines = [];
        $lines[] = $this->i18n('user portal protocols') . ':';

        $st = $this->getXrayStats();
        $xrTotals = $this->getSubscriptionXrayTrafficTotals($st, $session['client'], $session['clientIndex'] ?? null);
        $vlessUsed = ((int) $xrTotals['download'] + (int) $xrTotals['upload']) > 0;
        $vlessAvailable = $this->hasEnabledXrayTransport($flags);
        $lines[] = $this->formatUserProtocolLine('VLESS', $vlessAvailable, $vlessAvailable ? $vlessUsed : null);

        $devices = $this->getHwidDevicesByUser($session['subscription_id']);
        $lines[] = $this->formatUserProtocolLine('AmneziaWG', true, count($devices) > 0);

        $lines[] = $this->formatUserProtocolLine('Hysteria2', !empty($flags['hysteria']), null);

        return $lines;
    }

    protected function formatUserProtocolLine(string $name, bool $available, ?bool $used): string
    {
        $label = '· <b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b> — ';
        if (!$available) {
            return $label . $this->i18n('user portal protocol unavailable');
        }
        $usage = '';
        if ($used === true) {
            $usage = ' · ' . $this->i18n('user portal protocol used');
        } elseif ($used === false) {
            $usage = ' · ' . $this->i18n('user portal protocol idle');
        }

        return $label . $this->i18n('user portal protocol available') . $usage;
    }

    protected function shouldHandleUserPortalTextInput(): bool
    {
        if (!empty($this->input['callback'])) {
            return false;
        }
        $message = trim((string) ($this->input['message'] ?? ''));
        if ($message === '' || preg_match('~^/~', $message)) {
            return false;
        }
        if ($this->parseSubscriptionLink($message) !== null) {
            return true;
        }

        return $this->getPendingUserPortalReply() !== null;
    }

    public function handleUserPortalTextInput(): void
    {
        if (!empty($this->input['callback'])) {
            return;
        }
        $message = trim((string) ($this->input['message'] ?? ''));
        $pending = $this->getPendingUserPortalReply();
        if ($pending !== null) {
            $callback = (string) ($pending['callback'] ?? '');
            $args = $pending['args'] ?? [];
            $uiId = (int) ($pending['message_id'] ?? $pending['start_message'] ?? 0);
            if ($uiId > 0) {
                $this->input['message_id'] = $uiId;
            }
            $this->clearPendingUserPortalReply($callback);
            $this->userPortalDeleteUserMessage();
            if ($callback === 'userPortalImportLink') {
                $this->userPortalImportLink($message);

                return;
            }
            if ($callback === 'userPortalDeleteDevicePassword') {
                $this->userPortalDeleteDevicePassword($message, ...$args);

                return;
            }
            if ($callback === 'userPortalSavePassword') {
                $this->userPortalSavePassword($message, ...$args);

                return;
            }
            if ($callback === 'userPortalChangePasswordVerify') {
                $this->userPortalChangePasswordVerify($message);

                return;
            }
            if ($callback === 'userPortalRenameDeviceSave') {
                $this->userPortalRenameDeviceSave($message, ...$args);

                return;
            }
            if ($callback === 'userPortalSupportSave') {
                $this->userPortalSupportSave($message);

                return;
            }
            if ($callback === 'userPortalSupportReplySave') {
                $this->userPortalSupportReplySave($message, ...$args);

                return;
            }
        }
        if ($this->parseSubscriptionLink($message) !== null) {
            $this->userPortalDeleteUserMessage();
            $this->userPortalImportLink($message);
        }
    }

    protected function isUserPortalRequest(): bool
    {
        $message = (string) ($this->input['message'] ?? '');
        $callback = (string) ($this->input['callback'] ?? '');

        if (preg_match('~^/(?:start|menu|update)$~', $message)) {
            return true;
        }
        if (preg_match('~^/userPortal~', $callback)) {
            return true;
        }
        if (!empty($this->input['reply'])) {
            $this->ensureUserPortalSession();
            $reply = $_SESSION['reply'][$this->input['reply']] ?? null;
            if (is_array($reply)) {
                $cb = (string) ($reply['callback'] ?? '');
                if ($this->isUserPortalReplyCallback($cb)) {
                    return true;
                }
            }
        }
        $text = trim($message);
        if ($text !== '' && !preg_match('~^/~', $text)) {
            if ($this->parseSubscriptionLink($text) !== null) {
                return true;
            }
            if ($this->getPendingUserPortalReply() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a non-admin, non-bound user sends a plain text message (not a
     * command, not a subscription link). Such input is routed to the no-access
     * stub instead of being silently dropped by auth().
     */
    protected function isUserPortalUnboundText(): bool
    {
        if ($this->admin || !$this->isUserPortalEnabled()) {
            return false;
        }
        $telegramId = (string) ($this->input['from'] ?? '');
        if ($telegramId === '' || $this->getUserPortalBinding($telegramId) !== null) {
            return false;
        }
        $message = trim((string) ($this->input['message'] ?? ''));

        return $message !== '' && !preg_match('~^/~', $message);
    }

    protected function getUserPortalTokenScope(): string
    {
        return 'userPortal_' . (string) ($this->input['from'] ?? '0');
    }

    /**
     * Normalize a user_portal_bindings value to the v2 record shape.
     * Accepts a legacy bare subscription-id string, an existing v2 array, or
     * nothing — so legacy data keeps resolving without a migration pass.
     *
     * v3 adds identity + first-seen fields for the people-directory: a user who
     * pressed "Start" but has no subscription yet still gets a record, with an
     * empty subscription_id and `first_seen_at`/`username`/`name` populated, so
     * they show up in the list before any config is bound to them.
     */
    public static function normalizeUserPortalBinding($value): array
    {
        if (is_array($value)) {
            return [
                'subscription_id' => (string) ($value['subscription_id'] ?? ''),
                'granted_at'      => (int) ($value['granted_at'] ?? 0),
                'activated'       => !empty($value['activated']),
                'first_seen_at'   => (int) ($value['first_seen_at'] ?? 0),
                'username'        => (string) ($value['username'] ?? ''),
                'name'            => (string) ($value['name'] ?? ''),
            ];
        }
        if (is_string($value) && $value !== '') {
            return [
                'subscription_id' => $value,
                'granted_at'      => 0,
                'activated'       => false,
                'first_seen_at'   => 0,
                'username'        => '',
                'name'            => '',
            ];
        }

        return [
            'subscription_id' => '',
            'granted_at'      => 0,
            'activated'       => false,
            'first_seen_at'   => 0,
            'username'        => '',
            'name'            => '',
        ];
    }

    protected function getUserPortalBindings(): array
    {
        $pac = $this->getPacConf();
        $bindings = $pac['user_portal_bindings'] ?? [];
        return is_array($bindings) ? $bindings : [];
    }

    protected function getUserPortalBinding(string $telegramId): ?string
    {
        $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);
        $subscriptionId = $record['subscription_id'];

        return $subscriptionId !== '' ? $subscriptionId : null;
    }

    protected function setUserPortalBinding(string $telegramId, string $subscriptionId): void
    {
        $pac = $this->getPacConf();
        $bindings = $this->getUserPortalBindings();
        $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
        $bindings[$telegramId] = [
            'subscription_id' => $subscriptionId,
            'granted_at'      => $prev['granted_at'] ?: time(),
            'activated'       => $prev['activated'],
            'first_seen_at'   => $prev['first_seen_at'] ?: time(),
            'username'        => $prev['username'],
            'name'            => $prev['name'],
        ];
        $pac['user_portal_bindings'] = $bindings;
        $this->setPacConf($pac);
    }

    /**
     * Record that a non-admin user pressed "Start" / opened the menu, so they
     * appear in the people-directory even before any subscription is bound.
     * Idempotent on fields it doesn't own: it only fills identity + first_seen
     * for an unbound user, never overwrites a subscription once granted.
     */
    protected function recordUserPortalSeen(string $telegramId): void
    {
        $telegramId = trim($telegramId);
        if ($telegramId === '' || $this->admin) {
            return;
        }
        $pac = $this->getPacConf();
        $bindings = $this->getUserPortalBindings();
        $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
        $username = trim((string) ($this->input['username'] ?? ''));
        $name = trim((string) ($this->input['first_name'] ?? '') . ' ' . (string) ($this->input['last_name'] ?? ''));
        $bindings[$telegramId] = [
            'subscription_id' => $prev['subscription_id'],
            'granted_at'      => $prev['granted_at'],
            'activated'       => $prev['activated'],
            'first_seen_at'   => $prev['first_seen_at'] ?: time(),
            'username'        => $username !== '' ? $username : $prev['username'],
            'name'            => $name !== '' ? $name : $prev['name'],
        ];
        $pac['user_portal_bindings'] = $bindings;
        $this->setPacConf($pac);
    }

    /**
     * All portal bindings as normalized records, keyed by telegram id, each with
     * a `telegram_id` field appended. Skips empty/legacy-invalid entries.
     */
    protected function getUserPortalRecords(): array
    {
        $records = [];
        foreach ($this->getUserPortalBindings() as $telegramId => $value) {
            $record = self::normalizeUserPortalBinding($value);
            // v3: keep unbound (seen-only) records too — the directory lists every
            // person who pressed Start, not only those with a subscription.
            $record['telegram_id'] = (string) $telegramId;
            $records[(string) $telegramId] = $record;
        }

        return $records;
    }

    /**
     * Mark a bound TG user as activated (they pressed the button / entered the
     * portal). No-op when the TG id has no binding.
     */
    protected function markUserPortalActivated(string $telegramId): void
    {
        $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);
        if ($record['subscription_id'] === '') {
            return;
        }
        $record['activated'] = true;
        $pac = $this->getPacConf();
        $bindings = $this->getUserPortalBindings();
        $bindings[$telegramId] = $record;
        $pac['user_portal_bindings'] = $bindings;
        $this->setPacConf($pac);
    }

    protected function removeUserPortalBinding(string $telegramId): void
    {
        $pac = $this->getPacConf();
        $bindings = $this->getUserPortalBindings();
        unset($bindings[$telegramId]);
        $pac['user_portal_bindings'] = $bindings;
        $this->setPacConf($pac);
    }

    protected function getUserPortalBindingTelegramIds(string $subscriptionId): array
    {
        $ids = [];
        foreach ($this->getUserPortalBindings() as $telegramId => $value) {
            if (self::normalizeUserPortalBinding($value)['subscription_id'] === $subscriptionId) {
                $ids[] = (string) $telegramId;
            }
        }
        return $ids;
    }

    /**
     * The people-directory: every person who pressed Start (bound OR seen-unbound)
     * plus every client that already owns a VLESS subscription (pulled in from
     * xray.json so they aren't duplicated), deduped by telegram_id and by
     * subscription_id.
     *
     * Returns a list of rows:
     *   telegram_id   — TG id when known, else '' for sub-only owners
     *   subscription_id — '' when nothing bound yet
     *   username, name, first_seen_at, granted_at, activated
     *   index         — xray index when the owner has a VLESS client, else null
     */
    protected function getUserPortalDirectory(): array
    {
        // 1. Existing bindings/seen records, keyed by telegram id.
        $byTg = [];
        foreach ($this->getUserPortalBindings() as $telegramId => $value) {
            $rec = self::normalizeUserPortalBinding($value);
            $rec['telegram_id'] = (string) $telegramId;
            $byTg[(string) $telegramId] = $rec;
        }

        // 2. Pull in existing VLESS owners (subscription holders) not already bound.
        $xr = $this->getXray();
        foreach ($this->forEachXrayClient($xr) as $entry) {
            $v = $entry['client'];
            if (!empty($v['device_parent_id'])) {
                continue; // устройство, не владелец
            }
            if (!empty($v['off'])) {
                continue;
            }
            $subscriptionId = $this->getClientSubscriptionId($v);
            if ($subscriptionId === '') {
                continue;
            }
            // Skip if already represented by a binding with the same subscription.
            $already = false;
            foreach ($byTg as $rec) {
                if ($rec['subscription_id'] === $subscriptionId) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }
            // Owner not yet in the directory: represent them under their xray index.
            $rowKey = 'sub:' . $subscriptionId;
            $byTg[$rowKey] = [
                'subscription_id' => $subscriptionId,
                'granted_at'      => 0,
                'activated'       => false,
                'first_seen_at'   => 0,
                'username'        => '',
                'name'            => '',
                'telegram_id'     => '',
                'index'           => $entry['index'],
            ];
        }

        // 3. Fill xray index for any bound owner that has a VLESS client.
        foreach ($byTg as $key => &$rec) {
            if (!empty($rec['subscription_id']) && !array_key_exists('index', $rec)) {
                $idx = $this->getOwnerXrayClientIndexBySubId($rec['subscription_id']);
                $rec['index'] = $idx;
            } elseif (!array_key_exists('index', $rec)) {
                $rec['index'] = null;
            }
        }
        unset($rec);

        // 4. Stable sort: name (username → name → id) ascending.
        uasort($byTg, function (array $a, array $b) {
            return strcasecmp($this->userPortalDisplayName($a), $this->userPortalDisplayName($b));
        });

        return $byTg;
    }

    /**
     * Display name for a directory row: @username, else first+last name, else
     * the telegram id (or subscription id when no TG id is known).
     */
    protected function userPortalDisplayName(array $row): string
    {
        $username = trim((string) ($row['username'] ?? ''));
        if ($username !== '') {
            return '@' . $username;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $tg = trim((string) ($row['telegram_id'] ?? ''));
        if ($tg !== '') {
            return $tg;
        }

        return trim((string) ($row['subscription_id'] ?? ''));
    }

    protected function resolveUserPortalGrant(): ?array
    {
        $telegramId = (string) ($this->input['from'] ?? '');
        if ($telegramId === '') {
            return null;
        }
        $subscriptionId = $this->getUserPortalBinding($telegramId);
        if ($subscriptionId === null) {
            return null;
        }
        $resolved = $this->resolveSubscriptionClient($subscriptionId);
        if ($resolved === null) {
            return null;
        }

        return array_merge($resolved, [
            'telegram_id' => $telegramId,
            'granted'     => true,
        ]);
    }

    protected function getUserPortalWelcomeText(): string
    {
        $pac = $this->getPacConf();
        return trim((string) ($pac['user_portal_welcome'] ?? ''));
    }

    protected function getUserPortalNoAccessText(): string
    {
        $pac = $this->getPacConf();
        $custom = trim((string) ($pac['user_portal_no_access'] ?? ''));
        return $custom !== '' ? $custom : $this->i18n('user portal no access');
    }

    protected function bindUserPortalSession(string $subscriptionId): bool
    {
        $resolved = $this->resolveSubscriptionClient($subscriptionId);
        if ($resolved === null) {
            return false;
        }
        $_SESSION['userPortal'] = [
            'subId'       => $resolved['subscription_id'],
            'clientIndex' => $resolved['index'],
            'telegram_id' => (string) ($this->input['from'] ?? ''),
        ];
        $this->markUserPortalActivated((string) ($this->input['from'] ?? ''));

        return true;
    }

    protected function getUserPortalSession(): ?array
    {
        $grant = $this->resolveUserPortalGrant();
        if ($grant !== null) {
            return $grant;
        }
        $portal = $_SESSION['userPortal'] ?? null;
        if (!is_array($portal)) {
            return null;
        }
        if ((string) ($portal['telegram_id'] ?? '') !== (string) ($this->input['from'] ?? '')) {
            return null;
        }
        $resolved = $this->resolveSubscriptionClient((string) ($portal['subId'] ?? ''));
        if ($resolved === null) {
            unset($_SESSION['userPortal']);

            return null;
        }

        return array_merge($portal, [
            'subscription_id' => $resolved['subscription_id'],
            'client'          => $resolved['client'],
            'clientIndex'     => $resolved['index'],
        ]);
    }

    protected function clearUserPortalSession(): void
    {
        unset($_SESSION['userPortal']);
    }

    protected function extractUrlFromText(string $text): string
    {
        $text = trim($text);
        if (preg_match('~https?://\S+~i', $text, $m)) {
            return rtrim($m[0], ".,;)>\"'");
        }
        if ($text !== '' && !preg_match('~^https?://~i', $text)) {
            return 'https://' . ltrim($text, '/');
        }

        return $text;
    }

    protected function parseSubscriptionLink(string $raw): ?string
    {
        $url = $this->extractUrlFromText($raw);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $subscriptionId = '';
        $path = (string) ($parts['path'] ?? '');

        if (str_contains($path, '/sub')) {
            $subscriptionId = (string) ($query['id'] ?? '');
        }
        if ($subscriptionId === '' && !empty($query['s'])) {
            $subscriptionId = (string) $query['s'];
        }
        if ($subscriptionId === '' && preg_match('~/(pac[^/?#]+)~', $path, $m)) {
            $decoded = $this->decodePacUrlPayload($m[1]);
            if (is_array($decoded) && !empty($decoded['s'])) {
                $subscriptionId = (string) $decoded['s'];
            }
        }

        $subscriptionId = trim($subscriptionId);

        return $subscriptionId !== '' ? $subscriptionId : null;
    }

    protected function resolveSubscriptionClient(string $subscriptionId): ?array
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            return null;
        }
        $xr = $this->getXray();
        foreach ($this->forEachXrayClient($xr) as $entry) {
            $v = $entry['client'];
            if (!empty($v['device_parent_id'])) {
                continue;
            }
            if (!$this->isSubscriptionIdMatch($v, $subscriptionId)) {
                continue;
            }
            if (!empty($v['off'])) {
                return null;
            }

            return [
                // Индекс сквозной, как в админке (клиенты в порядке inbounds).
                'index'           => $entry['index'],
                'client'          => $v,
                'subscription_id' => $this->getClientSubscriptionId($v),
            ];
        }

        // Отдельной записи владельца может не быть — он существует как
        // device_parent_id своих устройств. Ссылка подписки указывает на него,
        // поэтому опираемся на владельца первой такой device-записи.
        foreach ($this->forEachXrayClient($xr) as $entry) {
            $v = $entry['client'];
            if (($v['device_parent_id'] ?? '') !== $subscriptionId) {
                continue;
            }

            return [
                'index'           => $entry['index'],
                'client'          => $v,
                'subscription_id' => $this->getClientSubscriptionId($v),
            ];
        }

        return null;
    }

    protected function buildUserSubscriptionLinks(string $subscriptionId): array
    {
        $pac = $this->getPacConf();
        $useCdnDomain = empty($this->getTransportRegistryGlobal($pac)['reality']);
        $domain = $this->getDomain($useCdnDomain);
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash = $this->getHashBot();

        return [
            'page'  => $this->buildSubscriptionPageUrl($scheme, $domain, $hash, $subscriptionId),
            'clash' => $this->buildPacUrl($scheme, $domain, $hash, [
                'h' => $hash,
                't' => 'cl',
                's' => $subscriptionId,
            ]),
        ];
    }

    protected function saveUserPortalDevicePassword(string $password, bool $change = false): array
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            return ['ok' => false, 'message' => $this->i18n('user portal bind first')];
        }
        $password = trim($password);
        if ($password === '') {
            return ['ok' => false, 'message' => $this->i18n('user portal empty password')];
        }

        $ownerSubId = $session['subscription_id'];
        if (!$this->checkSubscriptionActionRateLimit($ownerSubId, 'device_password_set', 5, 600)) {
            return ['ok' => false, 'message' => $this->i18n('user portal rate limit')];
        }

        $xr = $this->getXray();
        $idx = (int) $session['clientIndex'];
        if (!isset($xr['inbounds'][0]['settings']['clients'][$idx])) {
            return ['ok' => false, 'message' => $this->i18n('user portal link not found')];
        }

        $clientRef = &$xr['inbounds'][0]['settings']['clients'][$idx];
        if ($change) {
            if (empty($_SESSION['userPortal']['pw_change_ok'])) {
                return ['ok' => false, 'message' => $this->i18n('user portal password verify first')];
            }
            unset($_SESSION['userPortal']['pw_change_ok']);
        } elseif ($this->hasSubscriptionDevicePassword($clientRef)) {
            return ['ok' => false, 'message' => $this->i18n('user portal password already set')];
        }

        $this->setSubscriptionDevicePassword($clientRef, $password);
        $this->writeXrayConfig($xr);

        return ['ok' => true];
    }

    public function userPortalMenu()
    {
        // Записать не-админа в people-directory при первом заходе (/start /menu /update).
        $this->recordUserPortalSeen((string) ($this->input['from'] ?? ''));

        if (preg_match('~^/(?:start|menu|update)$~', (string) ($this->input['message'] ?? ''))) {
            unset($_SESSION['userPortalUi']['message_id']);
        }

        $session = $this->getUserPortalSession();
        $granted = !empty($session['granted']);
        $text = [$this->i18n('user portal title')];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
            $text[] = '';
        }

        if ($session !== null) {
            if ($granted) {
                $welcome = $this->getUserPortalWelcomeText();
                if ($welcome !== '') {
                    $text[] = $welcome;
                    $text[] = '';
                }
            }
            $text = array_merge($text, $this->buildUserPortalAccountInfoLines($session));
            $text[] = '';
            $text = array_merge($text, $this->buildUserProtocolCardLines($session));
        } else {
            $text[] = $this->getUserPortalNoAccessText();
        }

        $data = [];
        if ($session === null) {
            $data[] = [[
                'text'          => $this->i18n('user portal restore link'),
                'callback_data' => '/userPortalImport',
            ]];
        } else {
            $data[] = [[
                'text'          => $this->i18n('user portal my devices'),
                'callback_data' => '/userPortalDevices',
            ]];
            $links = $this->buildUserSubscriptionLinks($session['subscription_id']);
            $text[] = '';
            $text[] = $this->i18n('user portal new page link') . ':';
            $text[] = $links['page'];
            $text[] = '';
            $text[] = $this->i18n('user portal new clash link') . ':';
            $text[] = $links['clash'];

            $data[] = [[
                'text'          => $this->hasSubscriptionDevicePassword($session['client'])
                    ? $this->i18n('user portal change password')
                    : $this->i18n('user portal set password'),
                'callback_data' => '/userPortalPassword',
            ]];
        }

        $data[] = [[
            'text'          => $this->i18n('user portal support title'),
            'callback_data' => '/userPortalSupport',
        ]];

        $this->userPortalShow(implode("\n", $text), $data);
    }

    /**
     * Support threads for this user's Telegram id. Shares the store with the
     * admin-side /support view, so a thread opened here shows up there and an
     * admin reply lands back in this chat.
     */
    public function userPortalSupport()
    {
        $key      = $this->userPortalSupportKey();
        $profiles = $this->getSupportProfiles();
        $profile  = is_array($profiles[$key] ?? null) ? $profiles[$key] : null;
        $threads  = $profile !== null ? $this->supportProfileThreads($profile) : [];

        $lines = [$this->i18n('user portal support title')];
        if ($threads === []) {
            $lines[] = '';
            $lines[] = $this->i18n('user portal support empty');
        }
        $data = [];
        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                continue;
            }
            $threadId = (string) ($thread['id'] ?? '');
            $closed   = !empty($thread['closed']);
            $messages = is_array($thread['messages'] ?? null) ? $thread['messages'] : [];
            $last     = $messages === [] ? null : $messages[array_key_last($messages)];

            $lines[] = '';
            $head = $this->i18n('support thread label') . ' ' . $threadId;
            if ($closed) {
                $head .= ' · ' . $this->i18n('support closed state');
            }
            if (!empty($thread['updated_at'])) {
                $head .= ' · ' . date('d.m H:i', (int) $thread['updated_at']);
            }
            $lines[] = $head;
            if (is_array($last)) {
                $preview = mb_substr((string) ($last['text'] ?? ''), 0, 80);
                $lines[] = ($last['from'] ?? '') . ': ' . $preview;
            }
            $data[] = [[
                'text'          => $this->i18n('user portal support open'),
                'callback_data' => '/userPortalSupportThread ' . $threadId,
            ], [
                'text'          => $this->i18n($closed ? 'user portal support reopen' : 'user portal support close'),
                'callback_data' => ($closed ? '/userPortalSupportReopen ' : '/userPortalSupportClose ') . $threadId,
            ]];
        }
        $data[] = [[
            'text'          => $this->i18n('user portal support write'),
            'callback_data' => '/userPortalSupportWrite',
        ]];
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]];

        $this->userPortalShow(implode("\n", $lines), $data);
    }

    public function userPortalSupportThread($threadId)
    {
        $threadId = (string) $threadId;
        $key      = $this->userPortalSupportKey();
        $profiles = $this->getSupportProfiles();
        $profile  = is_array($profiles[$key] ?? null) ? $profiles[$key] : null;
        $thread   = $profile !== null ? $this->supportThreadById($profile, $threadId) : null;
        if ($thread === null) {
            $this->userPortalSupport();
            return;
        }
        $closed   = !empty($thread['closed']);
        $messages = is_array($thread['messages'] ?? null) ? $thread['messages'] : [];

        $lines = [$this->i18n('user portal support title'), ''];
        foreach ($messages as $m) {
            $from  = (string) ($m['from'] ?? '') === 'admin'
                ? $this->i18n('support reply head')
                : $this->i18n('user portal support title');
            $body  = htmlspecialchars((string) ($m['text'] ?? ''), ENT_QUOTES, 'UTF-8');
            $lines[] = '';
            $lines[] = "<b>{$from}</b>";
            $lines[] = $body;
        }
        if ($closed) {
            $lines[] = '';
            $lines[] = $this->i18n('user portal support closed');
        }

        $data = [];
        if (!$closed) {
            $data[] = [[
                'text'          => $this->i18n('user portal support reply'),
                'callback_data' => '/userPortalSupportReply ' . $threadId,
            ]];
        }
        $data[] = [[
            'text'          => $this->i18n($closed ? 'user portal support reopen' : 'user portal support close'),
            'callback_data' => ($closed ? '/userPortalSupportReopen ' : '/userPortalSupportClose ') . $threadId,
        ]];
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalSupport',
        ]];

        $this->userPortalShow(implode("\n", $lines), $data);
    }

    public function userPortalSupportClose($threadId)
    {
        if ($this->setSupportThreadClosed($this->userPortalSupportKey(), (string) $threadId, true)) {
            $this->userPortalSetFlash($this->i18n('user portal support closed flash'));
        }
        $this->userPortalSupport();
    }

    public function userPortalSupportReopen($threadId)
    {
        if ($this->setSupportThreadClosed($this->userPortalSupportKey(), (string) $threadId, false)) {
            $this->userPortalSetFlash($this->i18n('user portal support reopened flash'));
        }
        $this->userPortalSupport();
    }

    public function userPortalSupportWrite()
    {
        $this->userPortalPromptInput($this->i18n('user portal support prompt'), 'userPortalSupportSave', []);
    }

    public function userPortalSupportReply($threadId)
    {
        $this->userPortalPromptInput($this->i18n('user portal support reply prompt'), 'userPortalSupportReplySave', [(string) $threadId]);
    }

    public function userPortalSupportSave($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return;
        }
        $meta = ['source' => 'user portal', 'telegram_id' => (string) $this->input['from']];
        $key  = $this->userPortalSupportKey();
        $threadId = $this->supportCreateThread($key, $meta);
        if ($threadId === null) {
            return;
        }
        $this->appendSupportMessageToThread($key, $threadId, 'user', $text, $meta);
        $this->notifySupportOwner($key, 'user portal', $text, $meta, $threadId);
        $this->userPortalSetFlash($this->i18n('user portal support sent'));
        $this->userPortalSupportThread($threadId);
    }

    public function userPortalSupportReplySave($text, $threadId = '')
    {
        $threadId = (string) $threadId;
        $text     = trim((string) $text);
        if ($threadId === '' || $text === '') {
            return;
        }
        $key  = $this->userPortalSupportKey();
        $meta = ['source' => 'user portal', 'telegram_id' => (string) $this->input['from']];
        if (!$this->appendSupportMessageToThread($key, $threadId, 'user', $text, $meta)) {
            return;
        }
        $this->notifySupportOwner($key, 'user portal', $text, $meta, $threadId);
        $this->userPortalSetFlash($this->i18n('user portal support sent'));
        $this->userPortalSupportThread($threadId);
    }

    protected function userPortalSupportKey(): string
    {
        return $this->supportTicketKey(['telegram_id' => (string) $this->input['from']]);
    }

    public function userPortalImport()
    {
        $this->userPortalPromptInput($this->i18n('user portal send old link'), 'userPortalImportLink', []);
    }

    public function userPortalImportLink($text = '')
    {
        $text = trim((string) $text);
        if ($text === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal link not found'));
            $this->userPortalMenu();

            return;
        }
        $telegramId = (string) ($this->input['from'] ?? '');
        if (!$this->checkSubscriptionActionRateLimit('portal:' . $telegramId, 'import_link', 8, 600)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal rate limit'));
            $this->userPortalMenu();

            return;
        }

        $subscriptionId = $this->parseSubscriptionLink($text);
        if ($subscriptionId === null || $this->resolveSubscriptionClient($subscriptionId) === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal link not found'));
            $this->userPortalMenu();

            return;
        }

        $this->bindUserPortalSession($subscriptionId);
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal link not found'));
            $this->userPortalMenu();

            return;
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal link restored'));
        $this->userPortalMenu();
    }

    public function userPortalPassword()
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalImport();

            return;
        }
        if ($this->hasSubscriptionDevicePassword($session['client'])) {
            $this->userPortalChangePassword();

            return;
        }
        $this->userPortalPromptInput($this->i18n('user portal enter new password'), 'userPortalSavePassword', []);
    }

    public function userPortalChangePassword()
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }
        if (!$this->hasSubscriptionDevicePassword($session['client'])) {
            $this->userPortalPassword();

            return;
        }
        unset($_SESSION['userPortal']['pw_change_ok']);
        $this->userPortalPromptInput($this->i18n('user portal enter current password'), 'userPortalChangePasswordVerify', []);
    }

    public function userPortalChangePasswordVerify($password)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalMenu();

            return;
        }

        $xr = $this->getXray();
        $idx = (int) $session['clientIndex'];
        if (!isset($xr['inbounds'][0]['settings']['clients'][$idx])) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal link not found'));
            $this->userPortalMenu();

            return;
        }

        $clientRef = &$xr['inbounds'][0]['settings']['clients'][$idx];
        if (!$this->isSubscriptionDevicePasswordValid($clientRef, trim((string) $password))) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal invalid password'));
            $this->userPortalChangePassword();

            return;
        }

        $_SESSION['userPortal']['pw_change_ok'] = 1;
        $this->userPortalPromptInput($this->i18n('user portal enter new password'), 'userPortalSavePassword', ['change' => 1]);
    }

    public function userPortalSavePassword($password, $change = 0)
    {
        $result = $this->saveUserPortalDevicePassword((string) $password, !empty($change));
        if (empty($result['ok'])) {
            $this->userPortalSetFlash('⚠️ ' . (string) ($result['message'] ?? 'error'));
            if (!empty($change)) {
                $this->userPortalChangePassword();
            } else {
                $this->userPortalPassword();
            }

            return;
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal password saved'));
        $this->userPortalMenu();
    }

    public function userPortalDevices($page = 0)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalPromptInput(
                $this->i18n('user portal bind first') . "\n\n" . $this->i18n('user portal send old link'),
                'userPortalImportLink',
                [],
            );

            return;
        }

        $ownerSubId = $session['subscription_id'];
        $client = $session['client'];
        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $scope = $this->getUserPortalTokenScope();
        if (!isset($_SESSION['hwidTokens'])) {
            $_SESSION['hwidTokens'] = [];
        }
        $_SESSION['hwidTokens'][$scope] = [];

        uasort($devices, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
        $hwids = array_keys($devices);
        $perPage = max(1, $this->limit ?: 5);
        $total = count($hwids);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max((int) $page, 0), $pages - 1);
        $hwidsPage = array_slice($hwids, $page * $perPage, $perPage);

        $text = [$this->i18n('user portal my devices')];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text = array_merge($text, $this->buildUserPortalAccountInfoLines($session));
        $text[] = '';

        $data = [];
        if (!$this->hasSubscriptionDevicePassword($client)) {
            $text[] = '';
            $text[] = $this->i18n('user portal set password to delete');
            $data[] = [[
                'text'          => $this->i18n('user portal set password'),
                'callback_data' => '/userPortalPassword',
            ]];
        }

        if ($total === 0) {
            $text[] = $this->i18n('no devices');
        } else {
            $deviceTraffic = $this->getHwidDeviceTraffic($ownerSubId);
            foreach ($hwidsPage as $index => $hwid) {
                $info = $devices[$hwid];
                $number = $page * $perPage + $index + 1;
                $customName = trim((string) ($info['device_name'] ?? ''));
                $details = array_filter([
                    $info['device_os'] ?? '',
                    $info['os_version'] ?? '',
                    $info['device_model'] ?? '',
                ], fn($v) => $v !== '');
                $text[] = str_repeat('-', 40);
                if ($customName !== '') {
                    $text[] = $number . '. <b>' . htmlspecialchars($customName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
                    $text[] = '<code>' . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                } else {
                    $text[] = $number . '. <code>' . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                }
                if ($details !== []) {
                    $text[] = htmlspecialchars(implode(' ', $details), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                if (!empty($info['time'])) {
                    $text[] = date('d.m.Y H:i', (int) $info['time']);
                }
                $devDown = (int) ($deviceTraffic[$hwid]['download'] ?? 0);
                $devUp = (int) ($deviceTraffic[$hwid]['upload'] ?? 0);
                $text[] = $this->formatTrafficDisplayLine($devDown, $devUp);
                $token = $this->rememberHwidToken($scope, $hwid);
                $row = [
                    [
                        'text'          => $this->i18n('rename') . ' ' . $number,
                        'callback_data' => "/userPortalRename {$page}_{$token}",
                    ],
                    [
                        'text'          => $this->i18n('user portal device vless'),
                        'callback_data' => "/userPortalDeviceVless {$page}_{$token}",
                    ],
                ];
                if ($this->isRuntimeDeviceWgEnabled($client)) {
                    $row[] = [
                        'text'          => $this->i18n('user portal device wg'),
                        'callback_data' => "/userPortalDeviceWg {$page}_{$token}",
                    ];
                }
                if ($this->isIkev2Enabled($client)) {
                    $row[] = [
                        'text'          => $this->i18n('user portal device ikev2'),
                        'callback_data' => "/userPortalDeviceIkev2 {$page}_{$token}",
                    ];
                }
                $data[] = $row;
                if ($this->hasSubscriptionDevicePassword($client)) {
                    $data[] = [[
                        'text'          => self::hwidDeviceButtonLabel($info, $this->i18n('delete'), $number),
                        'callback_data' => "/userPortalDel {$page}_{$token}",
                    ]];
                }
            }
        }

        if ($this->hasSubscriptionDevicePassword($client)) {
            $data[] = [[
                'text'          => $this->i18n('user portal change password'),
                'callback_data' => '/userPortalPassword',
            ]];
        }

        if ($pages > 1) {
            $data[] = [
                [
                    'text'          => '<<',
                    'callback_data' => '/userPortalDevices_' . ($page - 1 >= 0 ? $page - 1 : $pages - 1),
                ],
                [
                    'text'          => ($page + 1) . '/' . $pages,
                    'callback_data' => "/userPortalDevices_{$page}",
                ],
                [
                    'text'          => '>>',
                    'callback_data' => '/userPortalDevices_' . (($page + 1) % $pages),
                ],
            ];
        }

        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]];

        $this->userPortalShow(implode("\n", $text), $data ?: false);
    }

    public function userPortalDeviceVless($pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->ackCallback('device not found', true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $ownerSubId = $session['subscription_id'];
        $link = $this->getHwidDeviceVlessLink($ownerSubId, $hwid);

        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $info = $devices[$hwid] ?? [];
        $name = $this->getHwidDeviceDisplayName($info);

        $text = [$this->i18n('user portal vless copy')];
        if ($name !== '') {
            $text[] = '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $text[] = '';
        if ($link === '') {
            $text[] = $this->i18n('user portal vless empty');
        } else {
            $text[] = '<pre><code>' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        }

        $this->userPortalShow(implode("\n", $text), [[[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0],
        ]]]);
    }

    protected function getHwidDeviceVlessLink(string $ownerSubId, string $hwid): string
    {
        $xray = $this->getXray();
        foreach (($xray['inbounds'] ?? []) as $inbound) {
            $clients = $inbound['settings']['clients'] ?? null;
            if (!is_array($clients)) {
                continue;
            }
            foreach ($clients as $client) {
                if (!is_array($client)) {
                    continue;
                }
                if (($client['device_parent_id'] ?? '') !== $ownerSubId) {
                    continue;
                }
                if ((string) ($client['device_hwid'] ?? '') !== $hwid) {
                    continue;
                }

                return $this->linkXray((string) $client['id']);
            }
        }

        return '';
    }

    public function userPortalDeviceWg($pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->ackCallback('device not found', true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $ownerSubId = $session['subscription_id'];
        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $info = $devices[$hwid] ?? [];
        $deviceUuid = (string) ($info['device_uuid'] ?? '');
        $name = $this->getHwidDeviceDisplayName($info);

        $text = [$this->i18n('user portal wg copy')];
        if ($name !== '') {
            $text[] = '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $text[] = '';

        $conf = '';
        $shortLink = '';
        if ($deviceUuid !== '') {
            $conf = $this->getHwidDeviceWgConf($ownerSubId, $hwid, $deviceUuid);
            if ($conf !== '') {
                $shortLink = $this->getHwidDeviceWgShortLink($ownerSubId, $hwid, $deviceUuid);
            }
        }

        if ($conf === '') {
            $text[] = $this->i18n('user portal wg empty');
        } else {
            if ($shortLink !== '') {
                $text[] = $this->i18n('user portal wg amnezia link') . ':';
                $text[] = '<code>' . htmlspecialchars($shortLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                $text[] = '';
            }
            $text[] = '<pre><code>' . htmlspecialchars($conf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        }

        $this->userPortalShow(implode("\n", $text), [[[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0],
        ]]]);

        if ($shortLink !== '') {
            $this->sendQr($name !== '' ? $name : $hwid, preg_replace('~^vpn://~', '', $shortLink), ($name !== '' ? $name : $hwid) . ' — AmneziaVPN');
        }
    }

    protected function getHwidDeviceWgConf(string $ownerSubId, string $hwid, string $deviceUuid): string
    {
        $wgClient = $this->runInRuntimeWgContext(function () use ($ownerSubId, $hwid, $deviceUuid) {
            return $this->ensureDeviceWgProfile($ownerSubId, $hwid, $deviceUuid);
        });
        if (!is_array($wgClient)) {
            return '';
        }

        return (string) $this->runInRuntimeWgContext(function () use ($wgClient) {
            return $this->createConfig($wgClient);
        });
    }

    protected function getHwidDeviceWgShortLink(string $ownerSubId, string $hwid, string $deviceUuid): string
    {
        $wgClient = $this->runInRuntimeWgContext(function () use ($ownerSubId, $hwid, $deviceUuid) {
            return $this->ensureDeviceWgProfile($ownerSubId, $hwid, $deviceUuid);
        });
        if (!is_array($wgClient)) {
            return '';
        }

        return (string) $this->runInRuntimeWgContext(function () use ($wgClient) {
            return $this->getAmneziaShortLink($wgClient);
        });
    }

    public function userPortalRename($pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->ackCallback('device not found', true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $devices = $this->getHwidDevicesByUser($session['subscription_id']);
        $info = $devices[$hwid] ?? [];
        $currentName = trim((string) ($info['device_name'] ?? ''));
        $prompt = $this->i18n('user portal enter device name');
        if ($currentName !== '') {
            $prompt .= "\n\n" . $this->i18n('user portal current device name') . ': <b>' . htmlspecialchars($currentName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $prompt .= "\n\n<code>" . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

        $this->userPortalPromptInput(
            $prompt,
            'userPortalRenameDeviceSave',
            [$pageToken, $token],
            [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0],
            ]],
        );
    }

    public function userPortalRenameDeviceSave($name, $pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->userPortalSetFlash('⚠️ device not found');
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $ownerSubId = $session['subscription_id'];
        if (!$this->checkSubscriptionActionRateLimit($ownerSubId, 'device_rename', 30, 600)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal rate limit'));
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $result = $this->renameHwidDevice($ownerSubId, $hwid, (string) $name);
        if (empty($result['ok'])) {
            $message = (string) ($result['message'] ?? 'error');
            if ($message === 'empty name') {
                $message = $this->i18n('user portal empty device name');
            }
            $this->userPortalSetFlash('⚠️ ' . $message);
            $this->userPortalRename($pageToken, $token);

            return;
        }

        $savedName = (string) ($result['device_name'] ?? '');
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal device renamed') . ': ' . $savedName);
        $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);
    }

    public function userPortalDel($pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }
        if (!$this->hasSubscriptionDevicePassword($session['client'])) {
            $this->ackCallback($this->i18n('user portal set password to delete'), true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->ackCallback('device not found', true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $this->userPortalPromptInput(
            $this->i18n('user portal enter delete password') . "\n\n<code>" . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>',
            'userPortalDeleteDevicePassword',
            [$pageToken, $token],
            [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0],
            ]],
        );
    }

    public function userPortalDeleteDevicePassword($password, $pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->userPortalSetFlash('⚠️ device not found');
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $ownerSubId = $session['subscription_id'];
        if (!$this->checkSubscriptionActionRateLimit($ownerSubId, 'device_delete', 10, 600)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal rate limit'));
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $result = $this->performSubscriptionDeviceDelete($ownerSubId, $hwid, trim((string) $password));
        if (empty($result['ok'])) {
            $message = (string) ($result['message'] ?? 'error');
            if ($message === 'invalid password') {
                $message = $this->i18n('user portal invalid password');
            }
            $this->userPortalSetFlash('⚠️ ' . $message);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal device deleted'));
        $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);
    }

    protected function userPortalGrantSubscriptionId(int $i): string
    {
        $xr = $this->getXray();
        $c = $this->findXrayClientByIndex($xr, $i);
        return is_array($c) ? $this->getClientSubscriptionId($c) : '';
    }

    public function userPortalGrant($i = null)
    {
        $i = (int) $i;
        $subscriptionId = $this->userPortalGrantSubscriptionId($i);
        $text = [$this->i18n('user portal grant title')];
        if ($subscriptionId === '') {
            $text[] = $this->i18n('user portal grant not found');
        } else {
            $text[] = $this->i18n('user portal grant sub') . ': <code>' . htmlspecialchars($subscriptionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            $ids = $this->getUserPortalBindingTelegramIds($subscriptionId);
            if (empty($ids)) {
                $text[] = $this->i18n('user portal grant empty');
            } else {
                $text[] = $this->i18n('user portal grant bound');
                foreach ($ids as $id) {
                    $text[] = '· <code>' . htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                }
            }
        }
        $data = [[
            [
                'text'          => $this->i18n('user portal grant set'),
                'callback_data' => "/userPortalGrantSet $i",
            ],
            [
                'text'          => $this->i18n('user portal grant revoke'),
                'callback_data' => "/userPortalGrantRevoke $i",
            ],
        ], [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/userXr $i",
            ],
        ]];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

    public function userPortalGrantWg($clientIndex)
    {
        $clientIndex = (int) $clientIndex;
        $clients = $this->readClients();
        $subscriptionId = (string) ($clients[$clientIndex]['interface']['## owner_sub_id'] ?? '');
        $text = [$this->i18n('user portal grant title')];
        if ($subscriptionId === '') {
            $text[] = $this->i18n('user portal grant not found');
        } else {
            $text[] = $this->i18n('user portal grant sub') . ': <code>' . htmlspecialchars($subscriptionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            $ids = $this->getUserPortalBindingTelegramIds($subscriptionId);
            if (empty($ids)) {
                $text[] = $this->i18n('user portal grant empty');
            } else {
                $text[] = $this->i18n('user portal grant bound');
                foreach ($ids as $id) {
                    $text[] = '· <code>' . htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                }
            }
        }
        $data = [[
            [
                'text'          => $this->i18n('user portal grant set'),
                'callback_data' => "/userPortalGrantSetWg $clientIndex",
            ],
            [
                'text'          => $this->i18n('user portal grant revoke'),
                'callback_data' => "/userPortalGrantRevokeWg $clientIndex",
            ],
        ], [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu client {$clientIndex}_0",
            ],
        ]];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

    public function userPortalGrantSetWg($clientIndex)
    {
        $clientIndex = (int) $clientIndex;
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal grant prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant placeholder'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalGrantSetSaveWg',
            'args'           => [$clientIndex],
        ];
    }

    public function userPortalGrantSetSaveWg($text, $clientIndex)
    {
        $clientIndex = (int) $clientIndex;
        $telegramId = trim((string) $text);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            $this->userPortalGrantWg($clientIndex);

            return;
        }
        $clients = $this->readClients();
        $subscriptionId = (string) ($clients[$clientIndex]['interface']['## owner_sub_id'] ?? '');
        if ($subscriptionId === '') {
            $this->userPortalGrantWg($clientIndex);

            return;
        }
        $this->setUserPortalBinding($telegramId, $subscriptionId);
        $resolved = $this->resolveSubscriptionClient($subscriptionId);
        if ($resolved !== null) {
            $this->notifySubscriptionUsers($resolved['client'], 'appeared');
        }
        $this->userPortalGrantWg($clientIndex);
    }

    public function userPortalGrantRevokeWg($clientIndex)
    {
        $clientIndex = (int) $clientIndex;
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal grant revoke prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant placeholder'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalGrantRevokeSaveWg',
            'args'           => [$clientIndex],
        ];
    }

    public function userPortalGrantRevokeSaveWg($text, $clientIndex)
    {
        $clientIndex = (int) $clientIndex;
        $telegramId = trim((string) $text);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            $this->userPortalGrantWg($clientIndex);

            return;
        }
        $this->removeUserPortalBinding($telegramId);
        $this->userPortalGrantWg($clientIndex);
    }

    public function userPortalGrantSet($i)
    {
        $i = (int) $i;
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal grant prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant placeholder'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalGrantSetSave',
            'args'           => [$i],
        ];
    }

    public function userPortalGrantSetSave($text, $i)
    {
        $i = (int) $i;
        $telegramId = trim((string) $text);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            $this->userPortalGrant($i);

            return;
        }
        $subscriptionId = $this->userPortalGrantSubscriptionId($i);
        if ($subscriptionId === '') {
            $this->userPortalGrant($i);

            return;
        }
        $this->setUserPortalBinding($telegramId, $subscriptionId);
        $resolved = $this->resolveSubscriptionClient($subscriptionId);
        if ($resolved !== null) {
            $this->notifySubscriptionUsers($resolved['client'], 'appeared');
        }
        $this->userPortalGrant($i);
    }

    public function userPortalGrantRevoke($i)
    {
        $i = (int) $i;
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal grant revoke prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant placeholder'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalGrantRevokeSave',
            'args'           => [$i],
        ];
    }

    public function userPortalGrantRevokeSave($text, $i)
    {
        $i = (int) $i;
        $telegramId = trim((string) $text);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            $this->userPortalGrant($i);

            return;
        }
        $this->removeUserPortalBinding($telegramId);
        $this->userPortalGrant($i);
    }

    public function toggleUserPortal()
    {
        $pac = $this->getPacConf();
        $pac['user_portal_enabled'] = !empty($pac['user_portal_enabled']) ? 0 : 1;
        $this->setPacConf($pac);
        $this->ackCallback('user portal: ' . $this->i18n(!empty($pac['user_portal_enabled']) ? 'on' : 'off'), true);
        $this->menu('config');
    }

    public function userPortalUsers()
    {
        if (!$this->admin) {
            return;
        }
        $text = [];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = $this->i18n('user portal users title') . ':';
        $rows = $this->getUserPortalDirectory();
        if (empty($rows)) {
            $text[] = $this->i18n('user portal users empty');
        } else {
            foreach ($rows as $key => $r) {
                $status = !empty($r['activated']) ? $this->i18n('user portal activated') : $this->i18n('user portal not activated');
                $name = $this->userPortalDisplayName($r);
                $text[] = '· ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' — ' . $status;
            }
        }
        // Один ряд — одна кнопка-человек; grant остаётся списком, revoke уходит в карточку.
        $data = [];
        foreach ($rows as $key => $r) {
            $name = $this->userPortalDisplayName($r);
            $data[] = [[
                'text'          => $name,
                'callback_data' => '/userPortalCard ' . rawurlencode((string) $key),
            ]];
        }
        $data[] = [[
            [
                'text'          => $this->i18n('user portal grant menu'),
                'callback_data' => '/userPortalGrantPrompt',
            ],
        ]];
        $data[] = [[
            [
                'text'          => $this->i18n('back'),
                'callback_data' => '/menu config',
            ],
        ]];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

    /**
     * Разрешить ключ карточки (из списка, см. getUserPortalDirectory) обратно
     * в строку каталога + канонический key. key — это 'sub:<subscription_id>'
     * для владельца без TG-id, иначе Telegram id.
     */
    protected function resolveUserPortalCardRow(string $key): ?array
    {
        $dir = $this->getUserPortalDirectory();
        if (isset($dir[$key])) {
            return [$key, $dir[$key]];
        }
        // Кнопки могут нести tg id напрямую (пустой подписки), либо sub:-
        foreach ($dir as $k => $row) {
            if ($k === $key || (string) ($row['telegram_id'] ?? '') === $key || (string) ($row['subscription_id'] ?? '') === $key) {
                return [$k, $row];
            }
        }

        return null;
    }

    /**
     * Карточка человека: имя, подписка/статус, кнопки «Привязать подписку»,
     * «Выдать VLESS», «Выдать Amnezia/WG», «Отозвать» (revoke — здесь, в карточке).
     */
    public function userPortalCard(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal grant not found'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $name = $this->userPortalDisplayName($row);
        $subId = (string) ($row['subscription_id'] ?? '');
        $tgId = (string) ($row['telegram_id'] ?? '');

        $text = [];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        if ($tgId !== '') {
            $text[] = $this->i18n('user portal grant placeholder') . ': <code>' . htmlspecialchars($tgId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        }
        if ($subId !== '') {
            $text[] = $this->i18n('user portal grant sub') . ': <code>' . htmlspecialchars($subId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            $status = !empty($row['activated']) ? $this->i18n('user portal activated') : $this->i18n('user portal not activated');
            $text[] = $this->i18n('user portal status') . ': ' . $status;
        } else {
            $text[] = $this->i18n('user portal grant empty');
        }

        $data = [[
            [
                'text'          => $this->i18n('user portal bind subject'),
                'callback_data' => '/userPortalCardBind ' . rawurlencode($canonicalKey),
            ],
        ], [
            [
                'text'          => $this->i18n('user portal issue vless'),
                'callback_data' => '/userPortalCardVless ' . rawurlencode($canonicalKey),
            ],
            [
                'text'          => $this->i18n('user portal issue wg'),
                'callback_data' => '/userPortalCardWg ' . rawurlencode($canonicalKey),
            ],
        ], [
            [
                'text'          => $this->i18n('user portal grant revoke'),
                'callback_data' => '/userPortalCardRevoke ' . rawurlencode($canonicalKey),
            ],
        ], [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalUsers',
            ],
        ]];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

    /**
     * «Привязать подписку» — спросить ID подписки и привязать к Telegram id
     * человека из карточки (или к самому sub-key, если TG нет).
     */
    public function userPortalCardBind(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null) {
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal sub prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant sub'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalCardBindSave',
            'args'           => [$canonicalKey],
        ];
    }

    public function userPortalCardBindSave($text, string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $subscriptionId = trim((string) $text);
        if ($subscriptionId === '' || $this->resolveSubscriptionClient($subscriptionId) === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal sub not found'));
            $this->userPortalCard($key);

            return;
        }
        $resolved = $this->resolveUserPortalCardRow($key);
        $telegramId = '';
        if ($resolved !== null) {
            $telegramId = (string) ($resolved[1]['telegram_id'] ?? '');
            if ($telegramId === '') {
                // sub:-строка без TG: для существующего владельца просто переходим
                // на карточку (подписка уже там), перепривязывать нечего.
                $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind by id'));
                $this->userPortalCard($key);

                return;
            }
        }
        $this->setUserPortalBinding($telegramId, $subscriptionId);
        $owner = $this->resolveSubscriptionClient($subscriptionId);
        if ($owner !== null) {
            $this->notifySubscriptionUsers($owner['client'], 'appeared');
        }
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal grant ok'));
        $this->userPortalCard($telegramId);
    }

    /**
     * «Отозвать» внутри карточки: убрать привязку (или, для sub-only владельца,
     * просто убрать человека из каталога). Затем вернуться в список.
     */
    public function userPortalCardRevoke(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null) {
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $telegramId = (string) ($row['telegram_id'] ?? '');
        if ($telegramId !== '') {
            $this->removeUserPortalBinding($telegramId);
        } else {
            // Владелец без TG-id попал из xray.json; в каталоге он появляется
            // автоматически, явного binding-записи нет — нечего удалять.
        }
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal revoke ok'));
        $this->userPortalUsers();
    }

    /**
     * «Выдать VLESS» — собрать ссылку VLESS владельца и отправить в его чат(ы)
     * по subscription_id (всем привязанным TG-id). Файл/QR — прямо в чат.
     */
    public function userPortalCardVless(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['subscription_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $subscriptionId = (string) ($row['subscription_id'] ?? '');
        $owner = $this->resolveSubscriptionClient($subscriptionId);
        if ($owner === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal sub not found'));
            $this->userPortalCard($key);

            return;
        }
        $index = $owner['index'];
        $link = $this->linkXray($index);
        if ($link === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal vless empty'));
            $this->userPortalCard($key);

            return;
        }
        $this->deliverPortalConfig($subscriptionId, $link, 'vless', $this->userPortalDisplayName($row));
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal issue config ok'));
        $this->userPortalCard($key);
    }

    /**
     * «Выдать Amnezia/WG» — выдать/доставить WG-профиль владельцу (его device).
     * Доставка тем же путём, что и VLESS.
     */
    public function userPortalCardWg(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['subscription_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $subscriptionId = (string) ($row['subscription_id'] ?? '');
        $owner = $this->resolveSubscriptionClient($subscriptionId);
        if ($owner === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal sub not found'));
            $this->userPortalCard($key);

            return;
        }
        if (!$this->isRuntimeDeviceWgEnabled($owner['client'])) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal wg empty'));
            $this->userPortalCard($key);

            return;
        }
        $ownerSubId = $owner['subscription_id'];
        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $issued = 0;
        foreach ($devices as $hwid => $info) {
            $deviceUuid = (string) ($info['device_uuid'] ?? '');
            if ($deviceUuid === '') {
                continue;
            }
            $shortLink = $this->getHwidDeviceWgShortLink($ownerSubId, $hwid, $deviceUuid);
            if ($shortLink !== '') {
                $issued++;
                $this->deliverPortalConfig($subscriptionId, preg_replace('~^vpn://~', '', $shortLink), 'wg', $this->getHwidDeviceDisplayName($info));
            }
        }
        if ($issued === 0) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('no devices'));
        } else {
            $this->userPortalSetFlash('✅ ' . $this->i18n('user portal issue config ok') . ' (' . $issued . ')');
        }
        $this->userPortalCard($key);
    }

    /**
     * Доставить конфиг/ссылку во все чаты, привязанные к subscription_id. Если
     * привязанных чатов нет — оставить в карточке (fallback), не терять выдачу.
     * kind: 'vless' | 'wg' — влияет на текст и вид файла/QR.
     */
    protected function deliverPortalConfig(string $subscriptionId, string $payload, string $kind, string $label): void
    {
        $telegramIds = array_unique($this->getUserPortalBindingTelegramIds($subscriptionId));
        if (empty($telegramIds)) {
            return;
        }
        foreach ($telegramIds as $chatId) {
            if ($chatId === '') {
                continue;
            }
            try {
                $this->sendPhoto((int) $chatId, $this->portalConfigQrFile($payload), $this->portalConfigCaption($kind, $label, $payload));
            } catch (\Throwable $e) {
                // Не фатально: доставка конфига не должна ломать операцию.
            }
        }
    }

    protected function portalConfigQrFile(string $payload): string
    {
        $qrFile = sys_get_temp_dir() . '/qr_portal_' . substr(md5($payload), 0, 12) . '.png';
        exec("qrencode -t png -o " . escapeshellarg($qrFile) . " " . escapeshellarg($payload));
        return $qrFile;
    }

    protected function portalConfigCaption(string $kind, string $label, string $payload): string
    {
        if ($kind === 'wg') {
            return '<b>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b> — AmneziaWG' . "\n" . $this->i18n('user portal wg amnezia link') . ':' . "\n" . '<code>' . htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        }

        return '<b>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b> — VLESS' . "\n" . '<code>' . htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
    }

    public function userPortalGrantPrompt()
    {
        if (!$this->admin) {
            return;
        }
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal grant prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant placeholder'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalGrantPromptSub',
            'args'           => [],
        ];
    }

    public function userPortalGrantPromptSub($text)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim((string) $text);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal invalid id'));
            $this->userPortalUsers();

            return;
        }
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal sub prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant sub'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalGrantSave',
            'args'           => [$telegramId],
        ];
    }

    public function userPortalGrantSave($text, $telegramId)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim((string) $telegramId);
        $subscriptionId = trim((string) $text);
        if ($subscriptionId === '' || $this->resolveSubscriptionClient($subscriptionId) === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal sub not found'));
            $this->userPortalUsers();

            return;
        }
        $this->setUserPortalBinding($telegramId, $subscriptionId);
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal grant ok'));
        $this->userPortalUsers();
    }

    public function userPortalRevokePrompt()
    {
        if (!$this->admin) {
            return;
        }
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal grant revoke prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant placeholder'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalRevokeSave',
            'args'           => [],
        ];
    }

    public function userPortalRevokeSave($text)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim((string) $text);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal invalid id'));
            $this->userPortalUsers();

            return;
        }
        $this->removeUserPortalBinding($telegramId);
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal revoke ok'));
        $this->userPortalUsers();
    }

    public function userPortalIssueConfigPrompt()
    {
        if (!$this->admin) {
            return;
        }
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal issue config prompt'),
            $this->input['message_id'],
            reply: $this->i18n('user portal grant placeholder'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'userPortalIssueConfigSave',
            'args'           => [],
        ];
    }

    public function userPortalIssueConfigSave($text)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim((string) $text);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal invalid id'));
            $this->userPortalUsers();

            return;
        }
        $subscriptionId = $this->getUserPortalBinding($telegramId);
        if ($subscriptionId === null || $subscriptionId === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal grant not found'));
            $this->userPortalUsers();

            return;
        }

        $resolved = $this->resolveSubscriptionClient($subscriptionId);
        if ($resolved === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal sub not found'));
            $this->userPortalUsers();

            return;
        }

        $client = $resolved['client'];
        if (!$this->isRuntimeDeviceWgEnabled($client)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal wg empty'));
            $this->userPortalUsers();

            return;
        }

        // Force-issue the Amnezia/WG profile to every device of the subscription.
        $ownerSubId = $resolved['subscription_id'];
        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $issued = 0;
        foreach ($devices as $hwid => $info) {
            $deviceUuid = (string) ($info['device_uuid'] ?? '');
            if ($deviceUuid === '') {
                continue;
            }
            $conf = $this->getHwidDeviceWgConf($ownerSubId, $hwid, $deviceUuid);
            if ($conf === '') {
                continue;
            }
            if ($this->getHwidDeviceWgShortLink($ownerSubId, $hwid, $deviceUuid) !== '') {
                $issued++;
            }
        }

        if ($issued === 0) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('no devices'));
        } else {
            $this->userPortalSetFlash('✅ ' . $this->i18n('user portal issue config ok') . ' (' . $issued . ')');
        }
        $this->userPortalUsers();
    }
}
