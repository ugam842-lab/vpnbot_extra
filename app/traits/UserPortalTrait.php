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
            'userPortalWriteToSave',
            'userPortalReplySave',
            'userPortalWgAddName',
            'userPortalWgAddSelfName',
            'userPortalWgLimitSave',
            'userPortalWgGlobalLimitSave',
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

    /**
     * Запоминает последнюю активную страницу списка пользователей/заявок и
     * списка WG-пиров, чтобы после выдачи конфига из карточки (и её
     * подэкранов) админ возвращался на ту же страницу, а не на первую.
     */
    protected function userPortalSetBackPage(array $map): void
    {
        $this->ensureUserPortalSession();
        foreach ($map as $k => $v) {
            $_SESSION['userPortalUi']['backPage'][$k] = (int) $v;
        }
    }

    protected function userPortalBackPage(string $list = 'users'): int
    {
        return (int) ($_SESSION['userPortalUi']['backPage'][$list] ?? 0);
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
        // reply-таргет: сообщение, на которое надо ответить. Ставится
        // bot.php::reply() (админ, итог после ввода) или handleUserPortalTextInput
        // (пользователь, итог после ввода). Для промпта (replyState !== null) —
        // сообщение, где нажата кнопка / введена команда.
        $replyTo = (int) ($_SESSION['userPortalUi']['reply_to'] ?? 0);
        unset($_SESSION['userPortalUi']['reply_to']);
        if ($replyState !== null && $replyTo <= 0) {
            $replyTo = (int) $this->input['message_id'];
        }
        // reply_to легален только для промпта (replyState) либо как ответ на
        // конкретный ввод. Для обычной перерисовки меню (replyState === null)
        // устаревший reply_to из прошлого ввода ведёт к «message to be replied
        // not found» (то сообщение уже удалено) — сбрасываем его, чтобы меню
        // перерисовалось свежим сообщением, а не падало.
        if ($replyState === null && $replyTo > 0) {
            $replyTo = 0;
        }

        if ($replyState !== null) {
            // Промпт ввода («введите имя», «напишите ответ»): отдельным reply-
            // сообщением на исходное сообщение, меню не трогаем.
            $r = $this->send($chat, $text, $replyTo > 0 ? $replyTo : false, $buttons ?: false, $replyPlaceholder !== false ? $replyPlaceholder : false);
            if (!empty($r['result']['message_id'])) {
                $newId = (int) $r['result']['message_id'];
                $_SESSION['reply'][$newId] = array_merge($replyState, [
                    'start_message'  => $newId,
                    'start_callback' => $this->input['callback_id'] ?? false,
                    'keep_message' => true,
                ]);
                $this->setUserPortalUiMessageId($newId);
            }

            return;
        }

        if ($messageId > 0 && $replyTo <= 0) {
            $r = $this->update($chat, $messageId, $text, $buttons ?: false, $replyPlaceholder !== false ? $replyPlaceholder : false);
            if (!empty($r['ok'])) {
                $this->setUserPortalUiMessageId($messageId);

                return;
            }
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

        $r = $this->send($chat, $text, $replyTo > 0 ? $replyTo : false, $buttons ?: false, $replyPlaceholder !== false ? $replyPlaceholder : false);
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
        $placeholder = match ($callback) {
            'userPortalImportLink'            => $this->i18n('user portal send old link'),
            'userPortalDeleteDevicePassword'  => $this->i18n('user portal enter delete password'),
            'userPortalSavePassword'          => $this->i18n('user portal enter new password'),
            'userPortalChangePasswordVerify'  => $this->i18n('user portal enter current password'),
            'userPortalRenameDeviceSave'      => $this->i18n('user portal enter device name'),
            'userPortalRenameWgSave'          => $this->i18n('user portal enter wg name'),
            'userPortalSupportSave'           => $this->i18n('support placeholder'),
            'userPortalSupportReplySave'      => $this->i18n('support placeholder'),
            'userPortalWriteToSave'           => $this->i18n('support placeholder'),
            'userPortalReplySave'             => $this->i18n('support placeholder'),
            'userPortalWgAddName'             => $this->i18n('user portal wg name placeholder'),
            'userPortalWgAddSelfName'         => $this->i18n('user portal wg name placeholder'),
            'userPortalWgLimitSave'           => $this->i18n('user portal wg limit placeholder'),
            'userPortalWgGlobalLimitSave'     => $this->i18n('user portal wg limit placeholder'),
            'userPortalExpirySave'            => $this->i18n('user portal expiry placeholder'),
            default                           => '',
        };
        // Промпт ввода ставит force_reply (фокус сразу на поле ввода). Telegram
        // не даёт force_reply вместе с inline_keyboard, поэтому кнопка «назад»
        // здесь не нужна: у force_reply в самом поле есть штатная кнопка отмены.
        // Передаём пустые кнопки — send() при $reply!==false и пустом $button
        // поставит force_reply + input_field_placeholder.
        $this->userPortalShow($text, false, $placeholder, [
            'callback' => $callback,
            'args'     => $args,
        ]);
    }

    protected function userPortalDeleteUserMessage(): void
    {
        $messageId = (int) ($this->input['message_id'] ?? 0);
        if ($messageId > 0 && empty($this->input['callback'])) {
            $this->delete($this->input['chat'], $messageId);
            // Сообщение удалено — сбросить любые указатели сессии на него,
            // иначе следующий send()/update() воткнёт reply_to на
            // несуществующий id (»message to be replied not found»).
            if ((int) ($_SESSION['userPortalUi']['message_id'] ?? 0) === $messageId) {
                unset($_SESSION['userPortalUi']['message_id']);
            }
            if ((int) ($_SESSION['userPortalUi']['reply_to'] ?? 0) === $messageId) {
                unset($_SESSION['userPortalUi']['reply_to']);
            }
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

        // Единая сущность «времени»: срок кабинета (expires_at) — источник
        // правды и для VLESS, и для Amnezia. Показываем его, а не поле
        // VLESS-подписки, чтобы «время» в обоих местах совпадало.
        $tgId = (string) ($session['telegram_id'] ?? '');
        $expiresAt = $tgId !== '' ? $this->getUserPortalExpiry($tgId) : 0;
        if ($expiresAt > 0) {
            $date = date('d.m.Y H:i', $expiresAt);
            if ($expiresAt > time()) {
                $lines[] = $this->i18n('user portal expires') . ': ' . $date . ' (' . $this->getTime($expiresAt) . ')';
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

        // Сводка «сколько ВЫДАНО» — от реального владения, а не от леджера
        // user_portal_issued: леджер пишется только при явной выдаче и ключуется
        // subscription_id, поэтому для людей без VLESS-подписки (пустой sub id)
        // счёт Amnezia/WG расходился с реальным списком конфигов. Здесь считаем
        // то же, что видно в списке «Мои конфиги AmneziaWG», чтобы «Вам выдано»
        // всегда совпадало с фактом.
        $issuedVless = $ownerSubId !== '' ? 1 : 0;
        $issuedWg = count($this->userPortalOwnedWgClients($session));
        $lines[] = '';
        $lines[] = $this->i18n('user portal issued title') . ':';
        $lines[] = '· VLESS — ' . $issuedVless . ' ' . $this->i18n('user portal issued vless');
        $lines[] = '· Amnezia/WG — ' . $issuedWg . ' ' . $this->i18n('user portal issued wg');

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

    /**
     * Компактная сводка для верхнего уровня кабинета: время жизни, трафик и
     * сколько выдано (VLESS / Amnezia). Без email, счётчика устройств, лимита
     * AWG и статуса пароля — детали уехали в экраны «Подписка» и «Мои
     * устройства», чтобы верх кабинета не был перегружен.
     */
    protected function buildUserPortalSummaryLines(array $session): array
    {
        $client = $session['client'];
        $pac = $this->getPacConf();
        $st = $this->getXrayStats();
        $lines = [];

        // Время жизни — срок кабинета (expires_at), единый для VLESS и Amnezia.
        $tgId = (string) ($session['telegram_id'] ?? '');
        $expiresAt = $tgId !== '' ? $this->getUserPortalExpiry($tgId) : 0;
        if ($expiresAt > 0) {
            $date = date('d.m.Y H:i', $expiresAt);
            if ($expiresAt > time()) {
                $lines[] = $this->i18n('user portal expires') . ': ' . $date . ' (' . $this->getTime($expiresAt) . ')';
            } else {
                $lines[] = $this->i18n('user portal expired') . ': ' . $date;
            }
        } else {
            $lines[] = $this->i18n('user portal expires') . ': ' . $this->i18n('user portal no expiry');
        }

        // Трафик.
        $totals = $this->getSubscriptionXrayTrafficTotals($st, $client, $session['clientIndex']);
        $trafficLine = $this->i18n('user portal traffic') . ': ' . $this->formatTrafficUpDown((int) $totals['download'], (int) $totals['upload']);
        $limit = $this->getClientTrafficLimitBytes($client, $pac);
        if ($limit > 0) {
            $trafficLine .= ' / ' . $this->getBytes($limit);
        }
        $lines[] = $trafficLine;

        // Сводка «сколько выдано».
        $issuedVless = (string) ($session['subscription_id'] ?? '') !== '' ? 1 : 0;
        $issuedWg = count($this->userPortalOwnedWgClients($session));
        $lines[] = '';
        $lines[] = $this->i18n('user portal issued title') . ':';
        $lines[] = '· VLESS — ' . $issuedVless . ' ' . $this->i18n('user portal issued vless');
        $lines[] = '· Amnezia/WG — ' . $issuedWg . ' ' . $this->i18n('user portal issued wg');

        return $lines;
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
            // Сохраняем id введённого сообщения как reply-таргет, ДО того как
            // userPortalDeleteUserMessage() удалит его и ДО перезаписи uiId ниже.
            // Итог действия (переименование/ответ) должен прийти ответом на него.
            $typedId = (int) ($this->input['message_id'] ?? 0);
            if ($typedId > 0) {
                $_SESSION['userPortalUi']['reply_to'] = $typedId;
            }
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
            if ($callback === 'userPortalRenameWgSave') {
                $this->userPortalRenameWgSave($message, ...$args);

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
            if ($callback === 'userPortalWriteToSave') {
                $this->userPortalWriteToSave($message, ...$args);

                return;
            }
            if ($callback === 'userPortalReplySave') {
                $this->userPortalReplySave($message, ...$args);

                return;
            }
            if ($callback === 'userPortalWgAddName') {
                $this->userPortalWgAddName($message, ...$args);

                return;
            }
            if ($callback === 'userPortalWgAddSelfName') {
                $this->userPortalWgAddSelfName($message, ...$args);

                return;
            }
            if ($callback === 'userPortalWgLimitSave') {
                $this->userPortalWgLimitSave($message, ...$args);

                return;
            }
            if ($callback === 'userPortalExpirySave') {
                $this->userPortalExpirySave($message, ...$args);

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
        if (!empty($this->input['callback'])) {
            return false;
        }
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
                // Запись-массив без поля `approved` — legacy-запись, созданная до
                // появления модерации: такие считаются одобренными (grandfathered).
                // Модерация касается только новых записей, а у них approved
                // пишется явно как 0 (recordUserPortalSeen) или 1/2 (одобрить/отклонить).
                'approved'        => (int) ($value['approved'] ?? 1),
                'empty_proto'     => (int) ($value['empty_proto'] ?? 0),
                'wg_limit'        => (int) ($value['wg_limit'] ?? 0),
                'expires_at'      => (int) ($value['expires_at'] ?? 0),
                'expiry_reminders'=> (array) ($value['expiry_reminders'] ?? []),
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
                'approved'        => 1,
                'empty_proto'     => 0,
                'wg_limit'        => 0,
                'expires_at'      => 0,
                'expiry_reminders'=> [],
            ];
        }

        return [
            'subscription_id' => '',
            'granted_at'      => 0,
            'activated'       => false,
            'first_seen_at'   => 0,
            'username'        => '',
            'name'            => '',
            'approved'        => 1,
            'empty_proto'     => 0,
            'wg_limit'        => 0,
            'expires_at'      => 0,
            'expiry_reminders'=> [],
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
        $this->withPacLock(function () use ($telegramId, $subscriptionId): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
            $username = $prev['username'];
            $name     = $prev['name'];

            // Имя/ник могут быть пустыми, если запись появилась не из /start, а через
            // grant-меню (бот знает только telegram id). Тогда резолвим через getChat,
            // чтобы в people-directory вместо голого id был @username или имя.
            if ($username === '' && $name === '') {
                $identity = $this->resolveTelegramIdentity((string) $telegramId);
                if (is_array($identity)) {
                    $username = trim((string) ($identity['username'] ?? ''));
                    $name = trim((string) ($identity['first_name'] ?? '') . ' ' . (string) ($identity['last_name'] ?? ''));
                }
            }

            $bindings[$telegramId] = [
                'subscription_id' => $subscriptionId,
                'approved'        => $prev['approved'],
                'granted_at'      => $prev['granted_at'] ?: time(),
                'activated'       => $prev['activated'],
                'first_seen_at'   => $prev['first_seen_at'] ?: time(),
                'username'        => $username,
                'name'            => $name,
                'empty_proto'     => $prev['empty_proto'],
                'wg_limit'        => $prev['wg_limit'],
                'expires_at'      => $prev['expires_at'],
                'expiry_reminders'=> $prev['expiry_reminders'],
            ];
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });
    }

    /**
     * Лимит конфигов Amnezia/WG для пользователя: per-user override в
     * user_portal_bindings[$telegramId]['wg_limit'] (>0), иначе глобальный
     * дефолт из pac.json user_portal_wg_limit (по умолчанию 15). Это НЕ лимит
     * устройств (hwid/awg в X-Ray) — это именно число владеемых WG-конфигов.
     */
    protected function getUserPortalWgLimit(string $telegramId): int
    {
        $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);
        $override = (int) ($record['wg_limit'] ?? 0);
        if ($override > 0) {
            return $override;
        }
        $pac = $this->getPacConf();
        $global = (int) ($pac['user_portal_wg_limit'] ?? 15);

        return $global > 0 ? $global : 15;
    }

    protected function setUserPortalWgLimit(string $telegramId, int $limit): void
    {
        $this->withPacLock(function () use ($telegramId, $limit): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
            $bindings[$telegramId] = array_merge($prev, ['wg_limit' => $limit]);
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });
    }

    /**
     * Срок жизни кабинета (unix timestamp, 0 = без ограничения). Единая сущность
     * «времени» пользователя: пишется параллельно в X-Ray (VLESS) и во все
     * Amnezia/WG-конфиги, а при истечении «затыкается и то и то» (cron).
     */
    protected function getUserPortalExpiry(string $telegramId): int
    {
        $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);

        return (int) ($record['expires_at'] ?? 0);
    }

    /**
     * Записать срок кабинета: pac.json (expires_at + сброс напоминаний), затем
     * каскад на X-Ray и Amnezia ВНЕ файловой блокировки pac.json — эти системы
     * живут в своих файлах (xray.json / clients1.json / wg0.conf) и не делят
     * лок с pac.json.
     */
    protected function setUserPortalExpiry(string $telegramId, int $expiresAt): void
    {
        $telegramId = trim($telegramId);
        if ($telegramId === '') {
            return;
        }
        $subscriptionId = '';
        $this->withPacLock(function () use ($telegramId, $expiresAt, &$subscriptionId): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
            // Новый срок — новые вехи напоминаний (5/3/1 день) должны отработать
            // заново относительно него.
            $bindings[$telegramId] = array_merge($prev, [
                'expires_at'       => $expiresAt > 0 ? $expiresAt : 0,
                'expiry_reminders' => [],
            ]);
            $subscriptionId = $bindings[$telegramId]['subscription_id'];
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });

        if ($subscriptionId !== '') {
            $this->setUserPortalVlessTime($subscriptionId, $expiresAt);
        }
        $this->setUserPortalWgTime($telegramId, $expiresAt);
    }

    /**
     * Человекочитаемый ввод срока («30 дней», «1 месяц», «5d», дата) → unix.
     * Возвращает 0 = снять срок, null = неверный формат. strtotime не знает
     * русских названий единиц, поэтому подменяем их на английские перед парсом.
     */
    protected function parseUserPortalExpiry(string $text): ?int
    {
        $text = trim($text);
        if ($text === '' || $text === '0') {
            return 0;
        }
        if (preg_match('~^(без|без ограничения|бессрочно|навсегда|unlimited|none|never)$~iu', $text)) {
            return 0;
        }
        $map = [
            'секунд' => 'second', 'секунды' => 'second', 'секунда' => 'second', 'сек' => 'second',
            'минут' => 'minute', 'минуты' => 'minute', 'минута' => 'minute', 'мин' => 'minute',
            'часов' => 'hour', 'часа' => 'hour', 'час' => 'hour',
            'суток' => 'day', 'сутки' => 'day', 'сутк' => 'day',
            'дней' => 'day', 'дня' => 'day', 'день' => 'day',
            'недель' => 'week', 'недели' => 'week', 'неделю' => 'week', 'недел' => 'week', 'нед' => 'week',
            'месяцев' => 'month', 'месяца' => 'month', 'месяц' => 'month', 'мес' => 'month',
            'лет' => 'year', 'года' => 'year', 'год' => 'year',
        ];
        $normalized = $text;
        foreach ($map as $ru => $en) {
            $normalized = preg_replace('~(\d+)\s*' . preg_quote($ru, '~') . '~iu', '$1 ' . $en, $normalized);
        }
        $ts = strtotime($normalized);

        return ($ts === false || $ts <= time()) ? null : $ts;
    }

    /**
     * Записать срок на все X-Ray-клиенты подписки (владелец + устройства) в
     * мастер-реестр inbounds[0] (после expandXrayRegistryClients это clients_all).
     * applyBothTransportInboundClients разнесёт `time` по транспортным inbound'ам
     * при записи. 0/отрицательный — снять срок.
     */
    protected function setUserPortalVlessTime(string $subscriptionId, int $expiresAt): void
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            return;
        }
        $xr = $this->getXray();
        if (!is_array($xr['inbounds'][0]['settings']['clients'] ?? null)) {
            return;
        }
        $clients = &$xr['inbounds'][0]['settings']['clients'];
        $changed = false;
        foreach ($clients as $i => $v) {
            if (!is_array($v) || !$this->isSubscriptionIdMatch($v, $subscriptionId)) {
                continue;
            }
            if ($expiresAt > 0) {
                if ((int) ($v['time'] ?? 0) !== $expiresAt) {
                    $clients[$i]['time'] = $expiresAt;
                    $changed = true;
                }
            } elseif (array_key_exists('time', $v)) {
                unset($clients[$i]['time']);
                $changed = true;
            }
        }
        if ($changed) {
            $this->restartXray($xr, 1);
        }
    }

    /**
     * Записать срок на все Amnezia/WG-конфиги, которыми владеет пользователь.
     * Зеркалит timerClient(): `## time` на interface + на парный peer (по AllowedIPs).
     */
    protected function setUserPortalWgTime(string $telegramId, int $expiresAt): void
    {
        $owned = $this->userPortalIssuedWgClientsByTg($telegramId);
        if ($owned === []) {
            return;
        }
        $clients = $this->readClients();
        $server  = $this->readConfig();
        $date    = $expiresAt > 0 ? date('Y-m-d H:i:s', $expiresAt) : '';
        foreach ($owned as $item) {
            $idx = (int) ($item['index'] ?? -1);
            if ($idx < 0 || !is_array($clients[$idx]['interface'] ?? null)) {
                continue;
            }
            $address = (string) ($clients[$idx]['interface']['Address'] ?? '');
            if ($expiresAt > 0) {
                $clients[$idx]['interface']['## time'] = $date;
            } else {
                unset($clients[$idx]['interface']['## time']);
            }
            if ($address === '') {
                continue;
            }
            foreach ($server['peers'] as $k => $v) {
                if (($v['AllowedIPs'] ?? '') !== $address) {
                    continue;
                }
                if ($expiresAt > 0) {
                    $server['peers'][$k]['## time'] = $date;
                } else {
                    unset($server['peers'][$k]['## time']);
                }
            }
        }
        $this->saveClients($clients);
        $this->restartWG($this->createConfig($server));
    }

    /**
     * Напоминания перед истечением кабинета: за 5/3/1 день — текст + кнопка
     * «Поддержка» (обычный тред поддержки). Вызывается из cron() раз в 10 сек.
     * Каждая веха шлётся ровно один раз (expiry_reminders хранит отправленные).
     */
    public function checkUserPortalExpiryReminders(): void
    {
        try {
            $bindings = $this->getUserPortalBindings();
            if (!is_array($bindings) || $bindings === []) {
                return;
            }
            $now = time();
            foreach ($bindings as $telegramId => $raw) {
                $record = self::normalizeUserPortalBinding($raw);
                $expiresAt = (int) ($record['expires_at'] ?? 0);
                if ($expiresAt <= 0 || $expiresAt <= $now) {
                    continue;
                }
                $daysLeft = (int) floor(($expiresAt - $now) / 86400);
                if (!in_array($daysLeft, [5, 3, 1], true)) {
                    continue;
                }
                $sent = $record['expiry_reminders'];
                if (is_array($sent) && in_array($daysLeft, $sent, true)) {
                    continue;
                }
                $this->send(
                    (int) $telegramId,
                    $this->i18n('user portal expiry reminder'),
                    0,
                    [[['text' => $this->i18n('user portal support title'), 'callback_data' => '/userPortalSupport']]],
                );
                $this->withPacLock(function () use ($telegramId, $daysLeft): void {
                    $pac = $this->getPacConf();
                    $bindings = $this->getUserPortalBindings();
                    $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
                    $sent = $prev['expiry_reminders'];
                    $sent[] = $daysLeft;
                    $sent = array_values(array_unique(array_map('intval', $sent)));
                    $bindings[$telegramId] = array_merge($prev, ['expiry_reminders' => $sent]);
                    $pac['user_portal_bindings'] = $bindings;
                    $this->setPacConf($pac);
                });
            }
        } catch (\Throwable $e) {
            // Напоминания не должны ронять cron-цикл.
        }
    }

    protected function userPortalExpiryText(int $expiresAt): string
    {
        return $expiresAt > 0 ? date('d.m.Y H:i', $expiresAt) : $this->i18n('user portal no expiry');
    }

    /**
     * Уведомить пользователя, что ему задали/сняли срок жизни кабинета.
     * Админу отдельного push не шлём: он сам выполняет действие и сразу видит
     * подтверждение вспышкой на карточке. Здесь — только получатель.
     */
    protected function notifyUserPortalExpirySet(string $telegramId, int $expiresAt): void
    {
        $telegramId = trim($telegramId);
        if ($telegramId === '' || (int) $telegramId <= 0) {
            return;
        }
        if ($expiresAt > 0) {
            $left = $expiresAt > time() ? ' (' . $this->getTime($expiresAt) . ')' : '';
            $text = $this->i18n('user portal expiry set notify') . ' ' . date('d.m.Y H:i', $expiresAt) . $left;
        } else {
            $text = $this->i18n('user portal expiry unset notify');
        }
        try {
            $this->send((int) $telegramId, $text, 0);
        } catch (\Throwable $e) {
            // Уведомление не должно ронять сохранение срока.
        }
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
        if ($telegramId === '' || $this->admin || $this->renderingAsUser) {
            return;
        }
        $username = trim((string) ($this->input['username'] ?? ''));
        $name = trim((string) ($this->input['first_name'] ?? '') . ' ' . (string) ($this->input['last_name'] ?? ''));
        $isNew = false;
        $this->withPacLock(function () use ($telegramId, $username, $name, &$isNew): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
            $isNew = (int) ($prev['first_seen_at'] ?? 0) === 0;
            // Впервые нажавший «Старт» человек получает статус pending (на модерации):
            // в личный кабинет его пустят только после кнопки «Одобрить» у админа.
            $approved = $isNew ? 0 : (int) ($prev['approved'] ?? 1);
            $bindings[$telegramId] = [
                'subscription_id' => $prev['subscription_id'],
                'granted_at'      => $prev['granted_at'],
                'activated'       => $prev['activated'],
                'first_seen_at'   => $prev['first_seen_at'] ?: time(),
                'username'        => $username !== '' ? $username : $prev['username'],
                'name'            => $name !== '' ? $name : $prev['name'],
                'approved'        => $approved,
                'empty_proto'     => $prev['empty_proto'],
                'wg_limit'        => $prev['wg_limit'],
                'expires_at'      => $prev['expires_at'],
                'expiry_reminders'=> $prev['expiry_reminders'],
            ];
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });

        if ($isNew) {
            $firstName = trim((string) ($this->input['first_name'] ?? ''));
            $lastName  = trim((string) ($this->input['last_name'] ?? ''));
            $this->notifyAdminNewStart($telegramId, $username, $name, $firstName, $lastName);
        }
    }

    /**
     * Разослать админу заявку на модерацию: новый человек нажал «Старт». В
     * личку админа приходят кнопки «Одобрить» / «Отклонить» по его Telegram id.
     * config.php грузится ровно как в админ-меню; тихая no-op при недоступном
     * файле или пустом списке админов.
     */
    protected function notifyAdminNewStart(string $telegramId, string $username, string $name, string $firstName = '', string $lastName = ''): void
    {
        // config.php лежит в каталоге приложения (/app/config.php), а не в
        // traits/. Трейт сам в app/traits, поэтому на уровень выше.
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            return;
        }
        $c = [];
        require $configFile;
        // Раскладываем заявку ВСЕМ админам (config.php хранит их массивом), а не
        // только admin[0]: единая команда должна видеть новый «Старт» у каждого.
        $admins = isset($c['admin']) && is_array($c['admin']) ? $c['admin'] : [];
        // Админы из admin_silent (config.php) заявку не получают — остаются
        // админами с полными правами, но без уведомлений о заявках.
        $silent = isset($c['admin_silent']) && is_array($c['admin_silent']) ? $c['admin_silent'] : [];
        if ($silent !== []) {
            $silentMap = array_map('strval', $silent);
            $admins = array_values(array_filter($admins, static function ($id) use ($silentMap) {
                return !in_array((string) $id, $silentMap, true);
            }));
        }
        if ($admins === []) {
            return;
        }

        // Человекочитаемая строка: имя + фамилия первым делом; ник (@username)
        // вторым; Telegram id — только как аварийный идентификатор, когда у
        // аккаунта вообще ничего не заполнено. Админ видит имя, а не «id».
        $fullName = trim($firstName . ' ' . $lastName);
        if ($fullName === '') {
            $fullName = $name;
        }
        $who = $fullName;
        if ($who === '' && $username !== '') {
            $who = '@' . $username;
        }
        if ($who === '') {
            $who = 'id ' . $telegramId;
        }

        // Мета-часть: при наличии @username держим его рядом с id для быстрой
        // сверки, но саму заявку ведёт имя.
        $meta = $username !== '' ? ' (@' . $username . ')' : '';
        $meta .= ' · id ' . $telegramId;

        $text = 'Новый пользователь нажал «Старт»: ' . $who . $meta;
        $buttons = [[
            [
                'text'          => '✅ Одобрить',
                'callback_data' => '/userPortalApprove ' . $telegramId,
            ],
            [
                'text'          => '❌ Отклонить',
                'callback_data' => '/userPortalReject ' . $telegramId,
            ],
        ], [
            [
                'text'          => $this->i18n('user portal empty proto issue'),
                'callback_data' => '/userPortalCardEmptyProto ' . $telegramId,
            ],
            [
                'text'          => '✉️ Написать',
                'callback_data' => '/userPortalWriteTo ' . $telegramId,
            ],
        ]];
        foreach ($admins as $adminId) {
            $adminId = (string) $adminId;
            if ($adminId === '' || $adminId === $telegramId) {
                continue;
            }
            try {
                $r = $this->send($adminId, $text, button: $buttons);
                $messageId = isset($r['result']['message_id']) ? (int) $r['result']['message_id'] : 0;
                if ($messageId > 0) {
                    // Запомнить message_id карточки у ЭТОГО админа, чтобы при
                    // approve/reject свернуть кнопки у всех, а не только у нажавшего.
                    $this->addModerationCard($telegramId, $adminId, $messageId, $text);
                }
            } catch (\Throwable $e) {
                // Неудачная рассылка одному админу не должна ломать портал.
            }
        }
    }

    /**
     * Рассылка остальным админам уведомления о решении по заявке. Вызывается
     * после approve/reject/write: чтобы заявка не висела непрочитанной у других
     * админов, каждый из них (кроме нажавшего) получает строку «<админ> <глагол>
     * заявку <имя юзера>». Карточку модерации мы при этом НЕ сворачиваем у
     * остальных — это их собственные сообщения в их же чатах, до которых нам
     * не добраться из контекста нажавшего; вместо этого шлём короткий текст,
     * который однозначно закрывает вопрос «что с этой заявкой».
     */
    protected function notifyAdminDecision(string $telegramId, string $verb): void
    {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            return;
        }
        $c = [];
        require $configFile;
        $admins = isset($c['admin']) && is_array($c['admin']) ? $c['admin'] : [];
        // Админы из admin_silent (config.php) заявку не получают — остаются
        // админами с полными правами, но без уведомлений о заявках.
        $silent = isset($c['admin_silent']) && is_array($c['admin_silent']) ? $c['admin_silent'] : [];
        if ($silent !== []) {
            $silentMap = array_map('strval', $silent);
            $admins = array_values(array_filter($admins, static function ($id) use ($silentMap) {
                return !in_array((string) $id, $silentMap, true);
            }));
        }
        if ($admins === []) {
            return;
        }

        // Имя нажавшего админа: из текущего контекста (from). Имя/фамилия → @ник
        // → id, тем же порядком, что и в notifyAdminNewStart.
        $actingId = (string) ($this->input['from'] ?? '');
        $acting   = 'Админ';
        if ($actingId !== '') {
            $identity = $this->resolveTelegramIdentity($actingId);
            if (is_array($identity)) {
                $name = trim((string) ($identity['first_name'] ?? '') . ' ' . (string) ($identity['last_name'] ?? ''));
                if ($name !== '') {
                    $acting = $name;
                } elseif (!empty($identity['username'])) {
                    $acting = '@' . $identity['username'];
                } else {
                    $acting = 'id ' . $actingId;
                }
            }
        }

        $who    = $this->userPortalWriteTargetName($telegramId);
        $text   = $acting . ' ' . $verb . ' заявку ' . $who . ' (id ' . $telegramId . ')';

        foreach ($admins as $adminId) {
            $adminId = (string) $adminId;
            if ($adminId === '' || $adminId === $actingId) {
                continue;
            }
            try {
                $this->send($adminId, $text);
            } catch (\Throwable $e) {
                // Неудачная рассылка одному админу не должна ломать портал.
            }
        }
    }

    /**
     * Рассылка остальным админам «Конфиг доставлен» после выдачи WG/VLESS.
     * Нажавший админ видит тост в карточке, поэтому пушим остальным (кроме
     * нажавшего), как и notifyAdminDecision. admin_silent пропускаем. $who —
     * уже готовое человекочитаемое имя получателя.
     */
    protected function notifyAdminIssue(string $who, string $kind, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            return;
        }
        $c = [];
        require $configFile;
        $admins = isset($c['admin']) && is_array($c['admin']) ? $c['admin'] : [];
        $silent = isset($c['admin_silent']) && is_array($c['admin_silent']) ? $c['admin_silent'] : [];
        if ($silent !== []) {
            $silentMap = array_map('strval', $silent);
            $admins = array_values(array_filter($admins, static function ($id) use ($silentMap) {
                return !in_array((string) $id, $silentMap, true);
            }));
        }
        if ($admins === []) {
            return;
        }

        $actingId = (string) ($this->input['from'] ?? '');
        $acting = 'Админ';
        if ($actingId !== '') {
            $identity = $this->resolveTelegramIdentity($actingId);
            if (is_array($identity)) {
                $name = trim((string) ($identity['first_name'] ?? '') . ' ' . (string) ($identity['last_name'] ?? ''));
                if ($name !== '') {
                    $acting = $name;
                } elseif (!empty($identity['username'])) {
                    $acting = '@' . $identity['username'];
                } else {
                    $acting = 'id ' . $actingId;
                }
            }
        }

        $proto = match ($kind) {
            'wg'    => 'Amnezia/WG',
            'vless' => 'VLESS',
            'link'  => $this->i18n('user portal empty proto'),
            default => 'VLESS',
        };
        $okKey = $kind === 'link' ? 'user portal empty proto delivered' : 'user portal issue config ok';
        $text = '✅ ' . $this->i18n($okKey) . ' · ' . $acting . ' → ' . $who . ' · ' . $proto . ($count > 1 ? ' ×' . $count : '');

        foreach ($admins as $adminId) {
            $adminId = (string) $adminId;
            if ($adminId === '' || $adminId === $actingId) {
                continue;
            }
            try {
                $this->send($adminId, $text);
            } catch (\Throwable $e) {
                // Неудачная рассылка одному админу не должна ломать портал.
            }
        }
    }

    /**
     * Путь к файлу, где хранятся message_id карточек модерации, разосланных
     * админам. Лежит рядом с pac.json (bind-mount /config), поэтому общий для
     * всех PHP-воркеров и переживает перезапуски контейнера.
     */
    protected function moderationCardsPath(): string
    {
        return dirname($this->pac) . '/portal_moderation.json';
    }

    /**
     * Атомарно прочитать-изменить-записать portal_moderation.json под flock.
     * $fn получает массив карточек ПО ССЫЛКЕ (сигнатура function (&$cards)) и
     * может вернуть значение, пробрасываемое наружу. Те же мотивы, что у
     * withPacLock: несколько PHP-воркеров пишут один JSON, без блокировки
     * теряются чужие карточки.
     */
    protected function mutateModerationCards(callable $fn)
    {
        $path = $this->moderationCardsPath();
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = @fopen($path . '.lock', 'c');
        if ($fp === false) {
            return null;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);

                return null;
            }
            $raw = @file_get_contents($path);
            $cards = json_decode((string) $raw, true);
            if (!is_array($cards)) {
                $cards = [];
            }
            $result = $fn($cards);
            $payload = json_encode($cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload !== false) {
                $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
                if (file_put_contents($tmp, $payload, LOCK_EX) === strlen($payload)) {
                    @rename($tmp, $path);
                } else {
                    @unlink($tmp);
                }
            }

            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Запомнить карточку модерации, отправленную конкретному админу: чтобы при
     * approve/reject свернуть кнопки не только у нажавшего, а у всех, кому ушла
     * заявка.
     */
    protected function addModerationCard(string $telegramId, string $chatId, int $messageId, string $text): void
    {
        if ($telegramId === '' || $chatId === '' || $messageId <= 0) {
            return;
        }
        $this->mutateModerationCards(function (&$cards) use ($telegramId, $chatId, $messageId, $text): void {
            if (!isset($cards[$telegramId]) || !is_array($cards[$telegramId])) {
                $cards[$telegramId] = ['text' => $text, 'cards' => []];
            }
            $cards[$telegramId]['text'] = $text;
            $cards[$telegramId]['cards'][] = ['chat' => $chatId, 'message_id' => $messageId];
        });
    }

    /**
     * Вернуть и удалить карточки модерации заявки (заявка закрыта решением).
     */
    protected function takeModerationCards(string $telegramId): array
    {
        $entry = $this->mutateModerationCards(function (&$cards) use ($telegramId) {
            $e = $cards[$telegramId] ?? null;
            unset($cards[$telegramId]);

            return $e;
        });

        return is_array($entry) ? $entry : [];
    }

    /**
     * Свернуть карточки модерации заявки у ВСЕХ админов (включая нажавшего):
     * убрать inline-кнопки и дописать итог решения. Ключевое требование цикла
     * модерации — после approve/reject кнопки «Одобрить/Отклонить/Написать»
     * исчезают у каждого, больше нажать нельзя.
     */
    protected function collapseModerationCards(string $telegramId, string $resultLabel): void
    {
        $entry = $this->takeModerationCards($telegramId);
        $cards = isset($entry['cards']) && is_array($entry['cards']) ? $entry['cards'] : [];
        $savedText = (string) ($entry['text'] ?? '');
        $collapsed = [];

        foreach ($cards as $card) {
            $chat = (string) ($card['chat'] ?? '');
            $mid  = (int) ($card['message_id'] ?? 0);
            if ($chat === '' || $mid <= 0) {
                continue;
            }
            $text = $savedText !== '' ? $savedText : (string) ($card['text'] ?? '');
            $newText = ($text !== '' ? $text . "\n" : '') . $resultLabel;
            try {
                $this->collapseMessage($chat, $mid, $newText);
                $collapsed[$chat . ':' . $mid] = true;
            } catch (\Throwable $e) {
            }
        }

        // Карточка нажавшего админа: его сообщение сворачивается всегда, даже
        // если state пуст (заявка отправлена до деплоя этого фикса).
        $selfChat = (string) ($this->input['chat'] ?? '');
        $selfMid  = (int) ($this->input['message_id'] ?? 0);
        if ($selfChat !== '' && $selfMid > 0 && !isset($collapsed[$selfChat . ':' . $selfMid])) {
            $done = (string) ($this->input['message'] ?? '');
            $newText = ($done !== '' ? $done . "\n" : '') . $resultLabel;
            try {
                $this->collapseMessage($selfChat, $selfMid, $newText);
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * Проверить, одобрен ли человек. Отсутствующая запись или legacy-запись без
     * поля `approved` считаются одобренными (модерация касается только новых).
     */
    protected function isUserPortalApproved(string $telegramId): bool
    {
        $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);
        if ((int) ($record['first_seen_at'] ?? 0) === 0 && (string) ($record['subscription_id'] ?? '') === '') {
            // Записи вообще нет — «одобрен» неприменимо, пропускаем (как раньше).
            return true;
        }

        return (int) ($record['approved'] ?? 1) === 1;
    }

    /**
     * Админ нажал «Одобрить» в заявке: ставим approved=1 и шлём человеку
     * «Ваш личный кабинет одобрен».
     */
    public function userPortalApprove(string $telegramId)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim($telegramId);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            return;
        }
        $this->withPacLock(function () use ($telegramId): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
            $prev['approved'] = 1;
            $bindings[$telegramId] = $prev;
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });

        // Уведомить остальных админов о решении, чтобы заявка не висела у них
        // непрочитанной.
        $this->notifyAdminDecision($telegramId, 'одобрил');

        // Свернуть карточку модерации у админа: убрать кнопки и зафиксировать
        // решение прямо в том же сообщении (иначе «Одобрить/Отклонить» висят и
        // создаётся впечатление задвоения). Кнопки исчезают, остаётся итог.
        $this->collapseModerationCards($telegramId, '✅ ' . $this->i18n('user portal approved'));
        try {
            $this->send($telegramId, $this->i18n('user portal approved msg'));
        } catch (\Throwable $e) {
        }
        // После «одобрено» сразу перерисовать человеку его кабинет: он теперь
        // пущен, и вместо заглушки «на модерации» видит реальное меню (при
        // пустой подписке — состояние «активной подписки нет» + кнопка «Поддержка»).
        $this->renderUserPortalAs($telegramId);
    }

    /**
     * Перерисовать личный кабинет «как другой пользователь» — для действий
     * админа (одобрение/выдача), которые меняют состояние чужого кабинета.
     * Контекст запроса (from/chat/message_id) временно подменяется на целевой
     * id, рендерится меню, затем восстанавливается. Сеансовая подпись
     * пользователя не затирается: у свеже-одобренного подписки ещё нет, поэтому
     * getUserPortalSession вернёт wg_only-сессию (запись approved=1) и покажет
     * кабинет «только Амнезия» с кнопкой «Добавить конфиг».
     */
    protected function renderUserPortalAs(string $telegramId): void
    {
        $save = [
            'from'       => $this->input['from'] ?? null,
            'chat'       => $this->input['chat'] ?? null,
            'message_id' => $this->input['message_id'] ?? null,
            'message'    => $this->input['message'] ?? null,
            'callback'   => $this->input['callback'] ?? null,
            'callback_id'=> $this->input['callback_id'] ?? null,
            'username'   => $this->input['username'] ?? null,
            'first_name' => $this->input['first_name'] ?? null,
            'last_name'  => $this->input['last_name'] ?? null,
        ];
        $wasAdmin = $this->admin;
        $wasRenderingAsUser = $this->renderingAsUser;
        $this->input['from'] = $telegramId;
        $this->input['chat'] = $telegramId;
        $this->input['message_id'] = 0;
        $this->input['message'] = '';
        $this->input['callback'] = null;
        $this->input['callback_id'] = null;
        // Подменяем и identity-поля на данные ЦЕЛЕВОГО пользователя, иначе
        // recordUserPortalSeen(), вызываемый из userPortalMenu(), возьмёт имя/ник
        // из контекста админа, который нажал кнопку — и чужой аккаунт получит
        // данные админа в people-directory. Резолвим через getChat.
        $identity = $this->resolveTelegramIdentity((string) $telegramId);
        if (is_array($identity)) {
            $this->input['username']   = trim((string) ($identity['username'] ?? ''));
            $this->input['first_name'] = trim((string) ($identity['first_name'] ?? ''));
            $this->input['last_name']  = trim((string) ($identity['last_name'] ?? ''));
        } else {
            $this->input['username']   = '';
            $this->input['first_name'] = '';
            $this->input['last_name']  = '';
        }
        // На время рендера кабинета юзера снимаем админский флаг:
        // userPortalMenu() первой же проверкой if ($this->admin) вывел бы
        // админ-панель вместо кабинета целевого пользователя.
        $this->admin = false;
        // Гасим уведомление «новый Старт» на время рендера «как другой
        // пользователь»: иначе recordUserPortalSeen() разослал бы всем админам
        // ложную заявку на каждое нажатие админом «Одобрить».
        $this->renderingAsUser = true;
        // Переключаем PHP-сессию на целевого пользователя. Сессия стартует в
        // input() с id админа и к этому моменту уже активна, поэтому
        // ensureUserPortalSession() сам её не переключит. Без переключения
        // message_id заглушки «на рассмотрении» ищется в админской сессии, не
        // находится — и userPortalShow() шлёт НОВОЕ сообщение, оставляя заглушку
        // на месте (дубль из двух сообщений). Явно закрываем админскую сессию и
        // открываем сессию цели.
        $prevSessionId = session_id();
        $hadSession = session_status() === PHP_SESSION_ACTIVE;
        if ($hadSession) {
            session_write_close();
        }
        session_id((string) $telegramId);
        session_start();
        try {
            $this->userPortalMenu();
        } finally {
            // Закрываем сессию цели (flush её message_id), затем возвращаем
            // админскую сессию — остальной код запроса должен видеть админа.
            session_write_close();
            if ($hadSession && is_string($prevSessionId) && $prevSessionId !== '') {
                session_id($prevSessionId);
                session_start();
            }
            foreach ($save as $k => $v) {
                if ($v === null) {
                    unset($this->input[$k]);
                } else {
                    $this->input[$k] = $v;
                }
            }
            $this->admin = $wasAdmin;
            $this->renderingAsUser = $wasRenderingAsUser;
        }
    }

    /**
     * Каждый админ — ещё и одобренный пользователь портала: параллельная запись
     * в каталоге. Создаётся автоматически при добавлении админа (addAdmin) и
     * лениво при первом «Перейти как пользователь», если записи вдруг нет.
     * Идемпотентна: поля подписки и выдачи не трогает — конфиг админ выдаёт
     * себе сам через карточку. approved всегда = 1 (уже одобрен).
     */
    protected function ensureAdminUserRecord(string $telegramId): void
    {
        $telegramId = trim($telegramId);
        if ($telegramId === '') {
            return;
        }
        // Резолвим личность ДО блокировки pac.json: getChat — сетевой вызов,
        // его нельзя держать под файловой блокировкой.
        $identity = $this->resolveTelegramIdentity($telegramId);
        $username = '';
        $name     = '';
        if (is_array($identity)) {
            $username = trim((string) ($identity['username'] ?? ''));
            $name     = trim((string) ($identity['first_name'] ?? '') . ' ' . (string) ($identity['last_name'] ?? ''));
        }
        $this->withPacLock(function () use ($telegramId, $username, $name): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            $prev = self::normalizeUserPortalBinding($bindings[$telegramId] ?? null);
            $bindings[$telegramId] = [
                'subscription_id' => $prev['subscription_id'],
                'granted_at'      => $prev['granted_at'],
                'activated'       => $prev['activated'],
                'first_seen_at'   => $prev['first_seen_at'] ?: time(),
                'username'        => $username !== '' ? $username : $prev['username'],
                'name'            => $name !== '' ? $name : $prev['name'],
                'approved'        => 1,
                'empty_proto'     => $prev['empty_proto'],
                'wg_limit'        => $prev['wg_limit'],
                'expires_at'      => $prev['expires_at'],
                'expiry_reminders'=> $prev['expiry_reminders'],
            ];
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });
    }

    /**
     * Нажавший — владелец бота (admin[0] в config.php)? Владелец может удалить
     * любого пользователя, включая себя; остальные админы себя удалить не могут.
     */
    protected function isUserPortalOwner(): bool
    {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            return false;
        }
        $c = [];
        require $configFile;
        $owner = isset($c['admin'][0]) ? $c['admin'][0] : null;

        return $owner !== null && (string) ($this->input['from'] ?? '') === (string) $owner;
    }

    /**
     * Список telegram-id всех админов из config.php (как строки). Нужен, чтобы
     * не-владелец админ не видел в каталоге портала других админов.
     */
    protected function getAdminTelegramIds(): array
    {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            return [];
        }
        $c = [];
        require $configFile;
        $ids = is_array($c['admin'] ?? null) ? $c['admin'] : [];

        return array_values(array_map('strval', $ids));
    }

    /**
     * «Перейти как пользователь»: админ смотрит собственный кабинет портала.
     * Лениво гарантируем его запись (approved=1), затем рендерим кабинет как
     * обычному юзеру (admin=false + renderingAsUser, чтобы не сработала ложная
     * заявка «новый Старт»). Кабинет при этом получает кнопку «Перейти в админку».
     */
    public function userPortalAsSelf()
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = (string) ($this->input['from'] ?? '');
        if ($telegramId === '') {
            return;
        }
        $this->ensureAdminUserRecord($telegramId);
        $wasAdmin            = $this->admin;
        $wasRenderingAsUser  = $this->renderingAsUser;
        $this->admin           = false;
        $this->renderingAsUser = true;
        $this->renderingAsSelf = true;
        try {
            $this->userPortalMenu();
        } finally {
            $this->admin           = $wasAdmin;
            $this->renderingAsUser = $wasRenderingAsUser;
            $this->renderingAsSelf = false;
        }
    }

    /**
     * Админ нажал «Отклонить»: ставим approved=2 (или, при пустой подписке,
     * вовсе убираем запись) и сообщаем человеку об отказе.
     */
    public function userPortalReject(string $telegramId)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim($telegramId);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            return;
        }
        $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);
        // Отказ фиксируем статусом approved = 2 у КАЖДОЙ заявки, в т.ч. без
        // подписки. Прежний путь для пустой заявки сносил запись целиком, из-за
        // чего отклонённый человек повторным /start заново создавал заявку и
        // спамил всех админов «Новый пользователь нажал Старт», а экран
        // «отклонено» для него не работал.
        $this->withPacLock(function () use ($telegramId, $record): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            $record['approved'] = 2;
            $record['empty_proto'] = 0;
            $bindings[$telegramId] = $record;
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });
        // Уведомить остальных админов об отказе.
        $this->notifyAdminDecision($telegramId, 'отклонил');
        // Свернуть карточку модерации у админа: убрать кнопки, зафиксировать отказ.
        $this->collapseModerationCards($telegramId, '❌ ' . $this->i18n('user portal rejected'));
        // Человеку сообщаем об отказе, но после этого — никаких дальнейших
        // уведомлений в этот бот: заявка закрыта.
        try {
            $this->send($telegramId, $this->i18n('user portal rejected msg'));
        } catch (\Throwable $e) {
        }
    }

    /**
     * Админ ошибочно отклонил заявку и хочет её вернуть. Сбрасываем approved
     * обратно в «на рассмотрении» (0), сообщаем человеку, перерисовываем его
     * кабинет и уведомляем остальных админов о возврате.
     */
    public function userPortalRestore(string $telegramId)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim($telegramId);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            return;
        }
        $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);
        // Возвращаем только реально отклонённую заявку: approved === 2. Иначе
        // кнопка ничего не меняет (защита от повторного нажатия).
        if ((int) $record['approved'] !== 2) {
            return;
        }
        $this->withPacLock(function () use ($telegramId, $record): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            $record['approved'] = 0;
            $bindings[$telegramId] = $record;
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });
        // Уведомить остальных админов о возврате.
        $this->notifyAdminDecision($telegramId, 'вернул');
        // Человеку — что его заявку вернули на рассмотрение.
        try {
            $this->send($telegramId, $this->i18n('user portal restored msg'));
        } catch (\Throwable $e) {
        }
        // Перерисовать человеку его кабинет (снова «на рассмотрении»).
        try {
            $this->renderUserPortalAs($telegramId);
        } catch (\Throwable $e) {
        }
        // Открыть карточку — теперь это снова pending-заявка.
        $this->userPortalCard($telegramId);
    }

    /**
     * Админ нажал «Написать» в заявке: открывает окно ввода сообщения человеку
     * (не закрывая саму карточку модерации — решение по заявке админ примет
     * отдельно кнопками «Одобрить»/«Отклонить»).
     */
    public function userPortalWriteTo(string $telegramId)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim($telegramId);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            return;
        }
        $who = $this->userPortalWriteTargetName($telegramId);
        $this->userPortalPromptInput(
            $this->i18n('user portal write prompt') . "\n\n" . $who,
            'userPortalWriteToSave',
            [(string) $telegramId],
        );
    }

    /**
     * Отправка текста админа человеку: ретранслирует строку в его чат с ботом.
     * Отправлено ровно то, что админ написал, без шапки и служебных меток.
     */
    public function userPortalWriteToSave($text, $telegramId = '')
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim((string) $telegramId);
        $text = trim((string) $text);
        if ($telegramId === '' || $text === '' || !preg_match('~^\d{5,}$~', $telegramId)) {
            return;
        }
        // Зарегистрировать сообщение админа в поддержном треде пользователя,
        // чтобы у админа (во /support) была непрерывная история переписки, а у
        // пользователя — возможность ответить (кнопка «Ответить» ниже).
        $threadId = '';
        try {
            $threadId = $this->appendSupportMessageByKey('tg:' . $telegramId, 'admin', $text, ['telegram_id' => $telegramId]);
        } catch (\Throwable $e) {
            $threadId = '';
        }

        // Кнопка «Ответить» у получателя: он может ответить на сообщение админа,
        // и ответ придёт обратно в поддержку (частная переписка, не тред-заголовком).
        $replyButton = [[
            'text'          => $this->i18n('support reply'),
            'callback_data' => '/userPortalReply ' . $telegramId,
        ]];
        try {
            $this->send($telegramId, $text, 0, $replyButton);
            $this->userPortalSetFlash('✅ ' . $this->i18n('user portal write sent'));
        } catch (\Throwable $e) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal write failed'));
        }
        // Уведомить остальных админов, что по заявке написали (заявка движется).
        $this->notifyAdminDecision($telegramId, 'написал');
        // После отправки админ возвращается в карточку той же заявки, а не в
        // user-меню: userPortalMenu() для админа (без сессии) рисует «нет
        // доступа» + кнопки подписок, что и выглядело как задвоенное меню.
        $this->userPortalCard((string) $telegramId);
    }

    /**
     * Пользователь нажал «Ответить» на сообщении админа: открывает окно ввода
     * ответа (частная переписка). Ответ уйдёт админу в поддержку, откуда тот
     * сможет ответить ещё раз или закрыть обращение.
     */
    public function userPortalReply(string $telegramId)
    {
        $telegramId = trim($telegramId);
        if (!preg_match('~^\d{5,}$~', $telegramId)) {
            return;
        }
        // Не-целевой пользователь не должен иметь возможности ответить от чужого
        // имени (callback подделывается тривиально) — пускаем только самого получателя.
        if ((string) ($this->input['from'] ?? '') !== $telegramId) {
            return;
        }
        $this->userPortalPromptInput(
            $this->i18n('support reply prompt'),
            'userPortalReplySave',
            [$telegramId],
        );
    }

    /**
     * Отправка ответа пользователя админу: кладёт текст в тот же поддержный
     * тред (tg:<id>) как 'user' и шлёт админам уведомление с кнопкой «Ответить»
     * и «Закрыть обращение» (через существующий support-стек).
     */
    public function userPortalReplySave($text, string $telegramId = '')
    {
        $telegramId = trim((string) $telegramId);
        $text = trim((string) $text);
        if ($telegramId === '' || $text === '' || !preg_match('~^\d{5,}$~', $telegramId)) {
            return;
        }
        if ((string) ($this->input['from'] ?? '') !== $telegramId) {
            return;
        }
        $threadId = '';
        try {
            $threadId = $this->appendSupportMessageByKey('tg:' . $telegramId, 'user', $text, ['telegram_id' => $telegramId]);
        } catch (\Throwable $e) {
            $threadId = '';
        }
        try {
            $this->notifySupportOwner(
                'tg:' . $telegramId,
                'bot',
                $text,
                ['telegram_id' => $telegramId],
                $threadId,
            );
        } catch (\Throwable $e) {
        }
    }

    /**
     * Человекочитаемое имя человека для уведомлений админу: имя+фамилия →
     * @username → id как крайний случай. Помогает админу понять, кому пишет.
     */
    protected function userPortalWriteTargetName(string $telegramId): string
    {
        $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);
        $name = trim((string) ($record['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $username = trim((string) ($record['username'] ?? ''));
        if ($username !== '') {
            return '@' . $username;
        }

        return 'id ' . $telegramId;
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
        $this->withPacLock(function () use ($telegramId): void {
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
        });
    }

    protected function removeUserPortalBinding(string $telegramId): void
    {
        $this->withPacLock(function () use ($telegramId): void {
            $pac = $this->getPacConf();
            $bindings = $this->getUserPortalBindings();
            unset($bindings[$telegramId]);
            $pac['user_portal_bindings'] = $bindings;
            $this->setPacConf($pac);
        });
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
     * Считать леджер «выдано» (админ явно выдал конфиг человеку) из pac.json.
     * Ключ pac — user_portal_issued: subscription_id => ['vless'=>N, 'wg'=>N].
     * Это НЕ регистрации устройств (hwid) и НЕ WG-пиры — только осознанная выдача.
     */
    protected function getUserPortalIssuedLedger(): array
    {
        $pac = $this->getPacConf();
        $ledger = $pac['user_portal_issued'] ?? [];
        return is_array($ledger) ? $ledger : [];
    }

    /**
     * Инкремент счётчика выданных конфигов для подписки. kind: 'vless' | 'wg'.
     * Возвращает новое значение счётчика (0 при невозможности, напр. пустой subId).
     */
    protected function bumpUserPortalIssued(string $subscriptionId, string $kind, int $amount = 1): int
    {
        if ($subscriptionId === '' || !in_array($kind, ['vless', 'wg'], true) || $amount < 1) {
            return 0;
        }
        $count = 0;
        $this->withPacLock(function () use ($subscriptionId, $kind, $amount, &$count): void {
            $pac = $this->getPacConf();
            $ledger = is_array($pac['user_portal_issued'] ?? null) ? $pac['user_portal_issued'] : [];
            $count = (int) ($ledger[$subscriptionId][$kind] ?? 0);
            $count += $amount;
            $ledger[$subscriptionId][$kind] = $count;
            $pac['user_portal_issued'] = $ledger;
            $this->setPacConf($pac);
        });
        return $count;
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
        // 1. Existing bindings/seen records, keyed by telegram id. Every
        //    binding has a real TG id, so this is the set of people who either
        //    pressed /start (identity captured at start) or were granted a
        //    subscription via the admin menu (identity resolved via getChat in
        //    step 1b). Raw subscription-only rows belong to xray.json and are
        //    NOT imported here — that was the "влезли подписки" bug.
        $byTg = [];
        foreach ($this->getUserPortalBindings() as $telegramId => $value) {
            $rec = self::normalizeUserPortalBinding($value);
            $rec['telegram_id'] = (string) $telegramId;
            $byTg[(string) $telegramId] = $rec;
        }

        // 1b. For bound owners without a stored name/nick — a subscription
        //     granted via the admin menu to someone who never pressed /start —
        //     resolve their TG identity via getChat so the directory shows the
        //     actual person, not a bare "ID 123456789".
        foreach ($byTg as $key => $rec) {
            if (trim((string) ($rec['name'] ?? '')) !== '' || trim((string) ($rec['username'] ?? '')) !== '') {
                continue;
            }
            if (trim((string) ($rec['telegram_id'] ?? '')) === '') {
                continue;
            }
            $identity = $this->resolveTelegramIdentity((string) $rec['telegram_id']);
            if (is_array($identity)) {
                $username = trim((string) ($identity['username'] ?? ''));
                $name = trim((string) ($identity['first_name'] ?? '') . ' ' . (string) ($identity['last_name'] ?? ''));
                if ($username !== '') {
                    $byTg[$key]['username'] = $username;
                }
                if ($name !== '') {
                    $byTg[$key]['name'] = $name;
                }
            }
        }

        // 2. Fill xray index for any bound owner that has a VLESS client.
        foreach ($byTg as $key => &$rec) {
            if (!empty($rec['subscription_id']) && !array_key_exists('index', $rec)) {
                $idx = $this->getOwnerXrayClientIndexBySubId($rec['subscription_id']);
                $rec['index'] = $idx;
            } elseif (!array_key_exists('index', $rec)) {
                $rec['index'] = null;
            }
        }
        unset($rec);

        // 3. Никакого импорта из конфигов. Справочник — только те, кто нажал
        //    /start (bindings из step 1). VLESS/WG-подписки из xray.json и
        //    clients1.json сюда не тянутся — они видны по имени в списках
        //    «Выдать подписку» и «Выдать Amnezia/WG», а не как люди в каталоге.

        // 4. Stable sort: name (username → name → id) ascending.
        uasort($byTg, function (array $a, array $b) {
            return strcasecmp($this->userPortalDisplayName($a), $this->userPortalDisplayName($b));
        });

        return $byTg;
    }

    /**
     * Display name for a directory row, in the order the owner asked for:
     * first+last name (имя в Телеграме) → @username (никнейм) → telegram id,
     * falling back to the subscription id when no TG id is known.
     */
    protected function userPortalDisplayName(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $username = trim((string) ($row['username'] ?? ''));
        if ($username !== '') {
            return '@' . $username;
        }
        $tg = trim((string) ($row['telegram_id'] ?? ''));
        if ($tg !== '') {
            return 'ID ' . $tg;
        }

        $sub = trim((string) ($row['subscription_id'] ?? ''));
        return $sub !== '' ? 'ID ' . $sub : '';
    }

    protected function resolveUserPortalGrant(): ?array
    {
        $telegramId = (string) ($this->input['from'] ?? '');
        if ($telegramId === '') {
            return null;
        }
        $subscriptionId = $this->getUserPortalBinding($telegramId);
        if ($subscriptionId !== null) {
            $resolved = $this->resolveSubscriptionClient($subscriptionId);
            if ($resolved !== null) {
                return array_merge($resolved, [
                    'telegram_id' => $telegramId,
                    'granted'     => true,
                ]);
            }
        }

        // Подписки нет (или не резолвится) — но человеку могли выдать Амнезию.
        // Тогда кабинет упрощённый: только блок Amnezia/WG + «Поддержка», без
        // VLESS-зависимых строк (трафик/лимит/пароль). Вариант А из развилки.
        $wgClients = $this->userPortalIssuedWgClientsByTg($telegramId);
        if ($wgClients === []) {
            // Ни подписки, ни выданных WG-конфигов. Раньше это значило «нет
            // доступа». С self-add одобренному пользователю (запись есть,
            // approved=1) всё равно нужен кабинет с «Добавить конфиг» — иначе
            // он видит «нет подписки» и не может сам создать первый конфиг.
            // Свежих/неодобренных (pending/rejected) сюда не пускаем: их держит
            // заглушка «на рассмотрении/отклонено» в userPortalMenu().
            $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$telegramId] ?? null);
            $hasRecord = (int) ($record['first_seen_at'] ?? 0) > 0 || (string) ($record['subscription_id'] ?? '') !== '';
            if (!$hasRecord || (int) ($record['approved'] ?? 1) !== 1) {
                return null;
            }
        }

        return [
            'telegram_id'      => $telegramId,
            'granted'          => true,
            'wg_only'          => true,
            'subscription_id'  => '',
            'client'           => null,
            'clientIndex'      => null,
        ];
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
        // Админу кабинет-заглушка «нет подписки» не нужен: его «назад»/«в
        // кабинет» (callback /userPortal) должен вести в панель заявок.
        if ($this->admin) {
            $this->userPortalUsers();

            return;
        }

        // Записать не-админа в people-directory при первом заходе (/start /menu /update).
        $this->recordUserPortalSeen((string) ($this->input['from'] ?? ''));

        // /start, /menu, /update — всегда новое сообщение, а не правка старого
        // кабинета. Раньше этот сброс стоял после pending-ветки, поэтому у
        // заявителя повторный /start правил старую заглушку, а не выводил свежее меню.
        if (preg_match('~^/(?:start|menu|update)$~', (string) ($this->input['message'] ?? ''))) {
            unset($_SESSION['userPortalUi']['message_id']);
        }

        // Пока админ не одобрил заявку, не-админ видит только заглушку: «на
        // рассмотрении» для новой заявки, «отклонено» для отклонённой — не сам
        // кабинет и не стандартный «нет доступа».
        if (!$this->admin && !$this->isUserPortalApproved((string) ($this->input['from'] ?? ''))) {
            $tgId = (string) ($this->input['from'] ?? '');
            $record = self::normalizeUserPortalBinding($this->getUserPortalBindings()[$tgId] ?? null);
            $rejected = (int) ($record['approved'] ?? 1) === 2;
            $halfApproved = !$rejected && (int) ($record['empty_proto'] ?? 0) === 1;
            if ($halfApproved) {
                // «Полуодобренный кабинет»: админ выдал empty proto, но заявка
                // ещё не одобрена. Показываем MTProto-ссылку + «Обновить» +
                // «Поддержку» — без остального кабинета (подписки ещё нет).
                $text = [$this->i18n('user portal title')];
                $text[] = $this->i18n('user portal half approved');
                $link = method_exists($this, 'linkMtproto') ? (string) $this->linkMtproto() : '';
                if ($link !== '') {
                    $text[] = '';
                    $text[] = $this->i18n('user portal empty proto title') . ':';
                    $text[] = '<pre><code>' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                }
                $data = [];
                $data[] = [[
                    'text'          => $this->i18n('update'),
                    'callback_data' => '/userPortal',
                ]];
                $data[] = [[
                    'text'          => $this->i18n('user portal support title'),
                    'callback_data' => '/userPortalSupport',
                ]];
                $this->userPortalShow(implode("\n", $text), $data);
                return;
            }
            $text = [$this->i18n('user portal title')];
            $text[] = $this->i18n($rejected ? 'user portal rejected pending' : 'user portal pending');
            $this->userPortalShow(implode("\n", $text), [[
                'text'          => $this->i18n('user portal support title'),
                'callback_data' => '/userPortalSupport',
            ]]);
            return;
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
            if (!empty($session['wg_only'])) {
                // Упрощённый кабинет «только Амнезия»: без VLESS-строк (трафик,
                // лимит, пароль — всё завязано на подписку, которой нет).
                $tgId = (string) ($session['telegram_id'] ?? '');
                $wgCount = count($this->userPortalIssuedWgClientsByTg($tgId));
                $text[] = $this->i18n('user portal issued title') . ':';
                $text[] = '· Amnezia/WG — ' . $wgCount . ' ' . $this->i18n('user portal issued wg');
                $expiresAt = $tgId !== '' ? $this->getUserPortalExpiry($tgId) : 0;
                if ($expiresAt > 0) {
                    $text[] = $this->i18n('user portal expires') . ': ' . date('d.m.Y H:i', $expiresAt) . ' (' . $this->getTime($expiresAt) . ')';
                } else {
                    $text[] = $this->i18n('user portal expires') . ': ' . $this->i18n('user portal no expiry');
                }
            } else {
                $text = array_merge($text, $this->buildUserPortalSummaryLines($session));
            }
        } else {
            $text[] = $this->getUserPortalNoAccessText();
        }

        $data = [];
        if ($session === null) {
            // Без подписки кнопку «Обновить ссылку» не показываем вообще —
            // только «Поддержку» (добавляется ниже) и текст-заглушку.
        } elseif (!empty($session['wg_only'])) {
            // Упрощённый кабинет «только Амнезия»: кнопка «Мои конфиги Амнезия»
            // видна всегда (даже при 0 — внутри пустое состояние «можно добавить»)
            // + «Добавить конфиг». Устройств, ссылок, пароля и проверки нет:
            // всё это про VLESS-подписку, которой у юзера нет.
            $data[] = [[
                'text'          => $this->i18n('user portal issued wg menu'),
                'callback_data' => '/userPortalIssuedWg',
            ]];
        } else {
            // Верхний уровень — только «Подписка VLESS» и «Мои конфиги».
            // Ссылки, устройства, пароль и проверка уехали внутрь подписки
            // (userPortalSubscription): верх кабинета больше не перегружен.
            $data[] = [[
                'text'          => $this->i18n('user portal my subscription vless'),
                'callback_data' => '/userPortalSubscription',
            ]];
            $data[] = [[
                'text'          => $this->i18n('user portal issued wg menu'),
                'callback_data' => '/userPortalIssuedWg',
            ]];
        }

        // «empty proto» (MTProto-ссылка) показывается всегда — она не зависит
        // от подписки (не Xray, не AmneziaWG, общая t.me/proxy). «Проверка»
        // остаётся только при активной подписке (ей нужны ключи).
        $data[] = [
            [
                'text'          => $this->i18n('user portal empty proto'),
                'callback_data' => '/userPortalEmptyProto',
            ],
        ];

        // Кнопка «Обновить» — та же перерисовка кабинета, что /update, но
        // inline: юзеру не нужно вводить команду руками. callback /userPortal
        // ведёт в userPortalMenu(), а userPortalShow() отредактирует сообщение
        // свежими данными либо (если ничего не менялось) покажет тост «уже
        // актуально».
        $data[] = [[
            'text'          => $this->i18n('update'),
            'callback_data' => '/userPortal',
        ]];

        $data[] = [[
            'text'          => $this->i18n('user portal support title'),
            'callback_data' => '/userPortalSupport',
        ]];

        if (!empty($this->renderingAsSelf)) {
            $data[] = [[
                'text'          => $this->i18n('user portal back to admin'),
                'callback_data' => '/userPortalUsers',
            ]];
        }

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

    public function userPortalSubscription()
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

        $ownerSubId = (string) $session['subscription_id'];
        $links = $this->buildUserSubscriptionLinks($ownerSubId);

        // Экран подписки: ссылка-кнопка (открывается тапом, копируется
        // долгим нажатием), устройства, пароль и проверка — всё внутри.
        // Сюда же уехала карточка протоколов (VLESS/AmneziaWG/Hysteria2),
        // чтобы верх кабинета оставался компактной сводкой.
        $text = [$this->i18n('user portal my subscription vless')];
        $text[] = '';
        $text = array_merge($text, $this->buildUserProtocolCardLines($session));

        $data = [];
        if ($links['page'] !== '') {
            $data[] = [[
                'text' => $this->i18n('user portal new page link'),
                'url'  => $links['page'],
            ]];
        }
        if ($links['clash'] !== '') {
            $data[] = [[
                'text' => $this->i18n('user portal new clash link'),
                'url'  => $links['clash'],
            ]];
        }
        $data[] = [[
            'text'          => $this->i18n('user portal my devices'),
            'callback_data' => '/userPortalDevices',
        ]];
        $data[] = [[
            'text'          => $this->hasSubscriptionDevicePassword($session['client'])
                ? $this->i18n('user portal change password')
                : $this->i18n('user portal set password'),
            'callback_data' => '/userPortalPassword',
        ]];
        $data[] = [[
            'text'          => $this->i18n('user portal check'),
            'callback_data' => '/userPortalCheck',
        ]];
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]];

        $this->userPortalShow(implode("\n", $text), $data);
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

    public function userPortalCheck()
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }
        $ownerSubId = $session['subscription_id'];
        $link = $this->getHwidSubscriptionFirstVless($ownerSubId);
        if ($link === '') {
            $this->userPortalShow($this->i18n('user portal check no vless'), [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortal',
            ]]);

            return;
        }
        $this->runUserPortalCheck($link, '/userPortal');
    }

    /**
     * "Проверка" for one device inside "Мои устройства".
     */
    public function userPortalCheckDevice($pageToken, $token)
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
        if ($link === '') {
            $this->userPortalShow($this->i18n('user portal check no vless'), [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0],
            ]]);

            return;
        }
        $this->runUserPortalCheck($link, '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0]);
    }

    /**
     * "empty proto" — returns the current MTProto proxy link (t.me/proxy),
     * identical to the one the admin generates/changes in the MTProto admin
     * screen: one shared secret for every client, nothing per-user.
     */
    public function userPortalEmptyProto()
    {
        // «empty proto» — общая MTProto-ссылка (t.me/proxy), не привязана ни к
        // Xray, ни к AmneziaWG, и не требует активной подписки. Показывается
        // всегда, даже до одобрения/выдачи подписки.
        $link = method_exists($this, 'linkMtproto') ? (string) $this->linkMtproto() : '';

        $text = [$this->i18n('user portal empty proto title')];
        $text[] = '';
        $text[] = $this->i18n('user portal empty proto how');
        $text[] = '';
        if ($link === '') {
            $text[] = $this->i18n('user portal empty proto empty');
        } else {
            $text[] = '<pre><code>' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        }

        $this->userPortalShow(implode("\n", $text), [[[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]]]);
    }

    /**
     * First VLESS key belonging to the subscription (any device), for the
     * subscription-level "Проверка" / "empty proto" buttons.
     */
    protected function getHwidSubscriptionFirstVless(string $ownerSubId): string
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
                return $this->linkXray((string) $client['id']);
            }
        }

        return '';
    }

    /**
     * Runs the honest end-to-end VLESS key test on the node.
     * Builds a client xray.json from the vless:// URI, dials the tunnel with real
     * traffic (api.ipify.org + cp.cloudflare.com) and reports works/fails + reason.
     */
    protected function runUserPortalCheck(string $link, string $backCallback): void
    {
        $buttons = [
            [
                [
                    'text'          => $this->i18n('user portal check restarting'),
                    'callback_data' => '/userPortalCheck',
                ],
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => $backCallback,
                ],
            ],
        ];

        $this->userPortalShow($this->i18n('user portal check running'), $buttons);

        $exit = $this->runVlessProbeOnNode($link);
        if (is_array($exit) && ($exit['ok'] ?? false)) {
            $lines = [$this->i18n('user portal check title'), ''];
            $lines[] = '✅ ' . $this->i18n('user portal check ok');
            $lines[] = '<code>' . htmlspecialchars((string) ($exit['exit_ip'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code>';
            $this->userPortalShow(implode("\n", $lines), $buttons);

            return;
        }

        $reason = is_array($exit) ? ($exit['reason'] ?? '') : '';
        $lines = [$this->i18n('user portal check title'), ''];
        $lines[] = '❌ ' . $this->i18n('user portal check fail');
        if ($reason !== '') {
            $lines[] = $this->i18n('user portal check reason') . ': ' . $reason;
        }
        $this->userPortalShow(implode("\n", $lines), $buttons);
    }

    /**
     * Sends the vless:// URI to the node's xr container and runs the xray client
     * probe. Returns ['ok'=>bool, 'exit_ip'=>string, 'reason'=>string].
     */
    protected function runVlessProbeOnNode(string $link): array
    {
        // Пробник гоняет РЕАЛЬНЫЙ xray-клиент в контейнере xr. Скрипт не зависит от
        // заранее развёрнутого файла: пишем его в persistent-маунт /logs каждый вызов
        // (идемпотентно) и тут же запускаем реальным URI клиенту - без угадывания путей.
        $probeFile = '/logs/vless-probe.sh';
        $probe     = 'rm -f ' . $probeFile . '; cat > ' . $probeFile . " <<'VLESS_PROBE_EOF'\n"
            . $this->getVlessProbeScript()
            . "\nVLESS_PROBE_EOF\n";
        $uri = escapeshellarg($link);

        // force packet-up: linkXray() для xhttp отдаёт stream-up (h2-only), но ng
        // режет http/2 на http/1.1 — packet-up проходит. Для reality packet-up и так
        // штатный. Пробник понимает флаг KILL_MODE=packet-up и перезапишет mode из URI.
        $raw = $this->ssh($probe . "KILL_MODE=packet-up sh {$probeFile} {$uri} 2>&1", 'xr');
        $raw = trim((string) $raw);

        // Probe emits structured lines on success:
        //   EXIT_IP=<ip>
        //   CLOUDFLARE=<http code>
        if (preg_match('/EXIT_IP=(\S+)/', $raw, $m)) {
            $exit = ['ok' => true, 'exit_ip' => $m[1], 'reason' => ''];
            if (preg_match('/CLOUDFLARE=(\d+)/', $raw, $c)) {
                $exit['ok'] = ($c[1] !== '0');
            }
            if (!$exit['ok']) {
                $exit['reason'] = 'tunnel up but test traffic did not pass';
            }

            return $exit;
        }

        // Failure: surface a human-readable reason.
        $reason = 'unreachable';
        if (stripos($raw, 'connection refused') !== false) {
            $reason = $this->i18n('user portal check layer connect');
        } elseif (stripos($raw, 'reset by peer') !== false || stripos($raw, 'eof') !== false) {
            $reason = $this->i18n('user portal check layer tls');
        } elseif (stripos($raw, 'timeout') !== false || stripos($raw, 'timed out') !== false) {
            $reason = $this->i18n('user portal check layer timeout');
        } elseif ($raw === '') {
            $reason = $this->i18n('user portal check layer empty');
        }
        $reason = $reason ?: 'unknown error';

        return ['ok' => false, 'exit_ip' => '', 'reason' => $reason];
    }

    /**
     * Возвращает исходник честного VLESS-пробника (реальный xray-клиент в xr).
     * Пишется на ноду каждый вызов runVlessProbeOnNode, поэтому правка здесь —
     * в один файл, без отдельного деплоя скрипта на ноду.
     *
     * KILL_MODE (опц.) — перезаписать mode из URI (нужно для xhttp: linkXray отдаёт
     * stream-up, но через ng http/1.1 работает только packet-up). reality и так packet-up.
     */
    protected function getVlessProbeScript(): string
    {
        return <<<'PROBE'
#!/bin/sh
set -u
URI="$1"
XRAY=$(command -v xray || echo /usr/bin/xray)
WORK=$(mktemp -d /tmp/vless-probe.XXXXXX)
CFG="$WORK/client.json"
LOG="$WORK/run.log"
SOCKS_PORT=10808
HTTP_PORT=10080
PID=""
trap 'cleanup' EXIT INT TERM
cleanup() { [ -n "$PID" ] && kill "$PID" 2>/dev/null; rm -rf "$WORK"; }
die() { echo "$1"; exit 0; }

# --- парсинг vless://uuid@host:port?params#name ---
BODY="${URI#vless://}"; FRAG="${BODY##*#}"
[ "$FRAG" = "$BODY" ] && FRAG=""; [ -n "$FRAG" ] && BODY="${BODY%#*}"
QP=""; case "$BODY" in *\?*) QP="${BODY#*\?}"; BODY="${BODY%%\?*}";; esac
UUID="${BODY%%@*}"; HOSTPORT="${BODY#*@}"
HOST="${HOSTPORT%%:*}"; PORT="${HOSTPORT##*:}"
case "$PORT" in ''|*[!0-9]*) PORT=443;; esac

get() { _k="$1"; _rest="$QP"
  while [ -n "$_rest" ]; do _tok="${_rest%%&*}"
    case "$_tok" in "$_k="*) printf '%s' "${_tok#$_k=}"; return 0;; esac
    case "$_rest" in *\&*) _rest="${_rest#*&}";; *) break;; esac
  done; return 1; }

SECURITY=$(get security); SECURITY="${SECURITY:-tls}"
SNI=$(get sni);           SNI="${SNI:-$HOST}"
FP=$(get fp);             FP="${FP:-chrome}"
NET=$(get type);          NET="${NET:-tcp}"
MODE=$(get mode);         MODE="${MODE:-auto}"
FLOW=$(get flow); PBK=$(get pbk); SID=$(get sid)
urldec() { printf '%s' "$1" | sed 's/%20/ /g; s/%2F/\//g'; }
PATHQ=$(urldec "$(get path)"); SNI=$(urldec "$SNI")

# KILL_MODE перезаписывает mode из URI — критично для xhttp через ng http/1.1.
[ -n "${KILL_MODE:-}" ] && MODE="$KILL_MODE"

[ -n "$UUID" ] || die "no uuid"; [ -n "$HOST" ] || die "no host"

STREAM_SECURITY="$SECURITY"; STREAM_EXTRA=""
case "$SECURITY" in
  reality) STREAM_EXTRA=",\"realitySettings\":{\"fingerprint\":\"$FP\",\"serverName\":\"$SNI\",\"publicKey\":\"$PBK\",\"shortId\":\"$SID\",\"spiderX\":\"/\"}";;
  tls)     STREAM_EXTRA=",\"tlsSettings\":{\"serverName\":\"$SNI\",\"fingerprint\":\"$FP\"}";;
esac
NET_EXTRA=""; NET_NAME="$NET"
case "$NET" in
  xhttp) NET_EXTRA=",\"xhttpSettings\":{\"path\":\"$PATHQ\",\"mode\":\"$MODE\"}";;
  ws)    NET_EXTRA=",\"wsSettings\":{\"path\":\"$PATHQ\"}";;
esac
FLOW_LINE=""; [ -n "$FLOW" ] && FLOW_LINE=",\"flow\":\"$FLOW\""

cat > "$CFG" <<EOF
{
  "log": {"loglevel": "warning", "access": "$LOG"},
  "inbounds": [
    {"tag": "socks", "port": $SOCKS_PORT, "listen": "127.0.0.1", "protocol": "socks", "settings": {"udp": true}},
    {"tag": "http", "port": $HTTP_PORT, "listen": "127.0.0.1", "protocol": "http"}
  ],
  "outbounds": [
    {"tag": "proxy", "protocol": "vless",
      "settings": {"vnext": [{"address": "$HOST", "port": $PORT,
        "users": [{"id": "$UUID", "encryption": "none"$FLOW_LINE}]}]},
      "streamSettings": {"network": "$NET_NAME", "security": "$STREAM_SECURITY"$STREAM_EXTRA$NET_EXTRA}},
    {"tag": "direct", "protocol": "freedom"}
  ]
}
EOF

if ! "$XRAY" run -config "$CFG" -test >/dev/null 2>&1; then
  "$XRAY" run -config "$CFG" -test 2>&1 | tail -3; die "config invalid"; fi

"$XRAY" run -config "$CFG" >/dev/null 2>&1 & PID=$!; sleep 1

PROXY="127.0.0.1:$HTTP_PORT"; EXIT_IP=""; CF_CODE=""
EXIT_IP=$(printf 'GET / HTTP/1.0\r\nHost: api.ipify.org\r\n\r\n' \
  | timeout 6 openssl s_client -connect api.ipify.org:443 -proxy "$PROXY" \
       -servername api.ipify.org -quiet 2>/dev/null \
  | tr -d '\r' | awk 'BEGIN{RS="\r\n\r\n"} NR==1{next} {print; exit}')
CF_CODE=$(printf 'HEAD / HTTP/1.0\r\nHost: cp.cloudflare.com\r\n\r\n' \
  | timeout 6 openssl s_client -connect cp.cloudflare.com:443 -proxy "$PROXY" \
       -servername cp.cloudflare.com -quiet 2>/dev/null \
  | tr -d '\r' | grep -m1 -iE '^HTTP/' | awk '{print $2}')
CF_CODE="${CF_CODE:-0}"

if [ -n "$EXIT_IP" ]; then
  echo "EXIT_IP=$(printf '%s' "$EXIT_IP" | sed 's/[^0-9.]//g')"
  echo "CLOUDFLARE=$CF_CODE"; exit 0; fi
if [ -s "$LOG" ]; then tail -8 "$LOG"; else die "no tunnel traffic"; fi
PROBE;
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
        $this->withPacLock(function (): void {
            $pac = $this->getPacConf();
            $pac['user_portal_enabled'] = !empty($pac['user_portal_enabled']) ? 0 : 1;
            $this->setPacConf($pac);
        });
        $this->ackCallback('user portal: ' . $this->i18n(!empty($pac['user_portal_enabled']) ? 'on' : 'off'), true);
        $this->menu('config');
    }

    public function userPortalUsers(int $page = -1)
    {
        if (!$this->admin) {
            return;
        }
        // «Назад в список» из карточки/подэкранов зовёт userPortalUsers() без
        // аргумента — возвращаемся на последнюю активную страницу, а не на 1-ю.
        if ($page < 0) {
            $page = $this->userPortalBackPage('users');
        }
        $perPage = 8;
        $text = [];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = $this->i18n('user portal users title') . ':';
        $rows = $this->getUserPortalDirectory();
        // «Пользователи» = только одобренные (approved === 1). Pending-заявки
        // (0) и отклонённые (2) живут в разделе «Заявки», а не дублируются здесь.
        $approved = [];
        foreach ($rows as $rk => $rv) {
            if ((int) ($rv['approved'] ?? 1) === 1) {
                $approved[$rk] = $rv;
            }
        }
        $rows = $approved;
        // Не-владелец админ не видит в каталоге других админов — только обычных
        // пользователей и самого себя. Владелец видит всех.
        if (!$this->isUserPortalOwner()) {
            $adminIds = $this->getAdminTelegramIds();
            $self     = (string) ($this->input['from'] ?? '');
            $rows = array_filter($rows, function (array $r) use ($adminIds, $self): bool {
                $tg = (string) ($r['telegram_id'] ?? '');
                if ($tg === '' || !in_array($tg, $adminIds, true)) {
                    return true;
                }

                return $tg === $self;
            });
        }
        $keys = array_keys($rows);
        $total = count($keys);
        $pages = (int) max(1, ceil($total / $perPage));
        $page = max(0, min($page, $pages - 1));
        $this->userPortalSetBackPage(['users' => $page, 'requests' => $this->userPortalBackPage('requests')]);
        $slice = array_slice($keys, $page * $perPage, $perPage, true);
        if (empty($rows)) {
            $text[] = $this->i18n('user portal users empty');
        } else {
            // Пагинация: список людей может занимать >4096 байт — без разбивки
            // editMessageText падает «message is too long» и кнопки не рендерятся.
            foreach ($slice as $key) {
                $r = $rows[$key];
                $counts = $this->userPortalIssuedCounts($r);
                $name = $this->userPortalDisplayName($r);
                $text[] = '· ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . ' — VLESS ' . $counts['vless'] . ' · Amnezia ' . $counts['wg'];
            }
            $text[] = '';
            $text[] = $this->i18n('user portal page') . ': ' . ($page + 1) . ' / ' . $pages;
        }
        $buttons = [];
        foreach ($slice as $key) {
            $name = $this->userPortalDisplayName($rows[$key]);
            $buttons[] = [[
                'text'          => $name,
                'callback_data' => '/userPortalCard ' . rawurlencode((string) $key . '_' . $page),
            ]];
        }
        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = [
                    'text'          => '←',
                    'callback_data' => '/userPortalUsers ' . ($page - 1),
                ];
            }
            if ($page < $pages - 1) {
                $nav[] = [
                    'text'          => '→',
                    'callback_data' => '/userPortalUsers ' . ($page + 1),
                ];
            }
            if (!empty($nav)) {
                $buttons[] = $nav;
            }
        }
        $buttons[] = [[
            'text'          => $this->i18n('user portal grant menu'),
            'callback_data' => '/userPortalGrantPrompt',
        ]];
        $buttons[] = [[
            'text'          => $this->i18n('user portal view as user'),
            'callback_data' => '/userPortalAsSelf',
        ]];
        $buttons[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/menu config',
        ]];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $buttons);
    }

    /**
     * Раздел «Заявки»: ожидающие решения (approved === 0) и отклонённые
     * (approved === 2 — пока админ не удалит их полностью). Из карточки заявки
     * админ одобряет, отклоняет, возвращает или удаляет. Отклонённый висит
     * здесь со статусом «Отклонено».
     */
    public function userPortalRequests(int $page = -1)
    {
        if (!$this->admin) {
            return;
        }
        if ($page < 0) {
            $page = $this->userPortalBackPage('requests');
        }
        $perPage = 8;
        $rows = $this->getUserPortalDirectory();
        $pending = [];
        foreach ($rows as $key => $r) {
            if ((int) ($r['approved'] ?? 1) === 0 || (int) ($r['approved'] ?? 1) === 2) {
                $pending[$key] = $r;
            }
        }
        $keys   = array_keys($pending);
        $total  = count($keys);
        $pages  = (int) max(1, ceil($total / $perPage));
        $page   = max(0, min($page, $pages - 1));
        $this->userPortalSetBackPage(['requests' => $page, 'users' => $this->userPortalBackPage('users')]);
        $slice  = array_slice($keys, $page * $perPage, $perPage, true);

        $text = [];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = $this->i18n('user portal requests title') . ':';
        if (empty($pending)) {
            $text[] = $this->i18n('user portal requests empty');
        } else {
            foreach ($slice as $key) {
                $r = $pending[$key];
                $line = '· ' . htmlspecialchars($this->userPortalDisplayName($r), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if ((int) ($r['approved'] ?? 1) === 2) {
                    $line .= ' — ' . $this->i18n('user portal rejected');
                }
                $text[] = $line;
            }
            $text[] = '';
            $text[] = $this->i18n('user portal page') . ': ' . ($page + 1) . ' / ' . $pages;
        }

        $buttons = [];
        foreach ($slice as $key) {
            $buttons[] = [[
                'text'          => $this->userPortalDisplayName($pending[$key]),
                'callback_data' => '/userPortalCard ' . rawurlencode((string) $key . '_' . $page),
            ]];
        }
        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = ['text' => '←', 'callback_data' => '/userPortalRequests ' . ($page - 1)];
            }
            if ($page < $pages - 1) {
                $nav[] = ['text' => '→', 'callback_data' => '/userPortalRequests ' . ($page + 1)];
            }
            if (!empty($nav)) {
                $buttons[] = $nav;
            }
        }
        $buttons[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalUsers',
        ]];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $buttons);
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
        // Кнопка человека в списке (заявки/пользователи) несёт
        // «<key>_<page>», чтобы «Назад» возвращал на ту же страницу списка,
        // а не сбрасывал на первую.
        $backPage = 0;
        if (preg_match('~^(.*)_(\d+)$~', $key, $m)) {
            $key      = $m[1];
            $backPage = (int) $m[2];
        }
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

        // Помним страницу списка, с которого открыта карточка, чтобы возврат
        // из подэкранов (выдача/отзыв/удаление) вёл на ту же страницу.
        $approvedNum = (int) ($row['approved'] ?? 1);
        if ($approvedNum === 0 || $approvedNum === 2) {
            $this->userPortalSetBackPage(['requests' => $backPage]);
        } else {
            $this->userPortalSetBackPage(['users' => $backPage]);
        }

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
        }
        // Счётчик «сколько выдано» вместо вводящего в заблуждение «активен/не
        // активен»: человек без VLESS-подписки всё равно может владеть Amnezia/WG.
        // Рядом — лимит конфигов Amnezia (per-user override или глобальный дефолт).
        $counts = $this->userPortalIssuedCounts($row);
        $wgLimit = $tgId !== '' ? $this->getUserPortalWgLimit($tgId) : 0;
        $text[] = $this->i18n('user portal issued count') . ': VLESS ' . $counts['vless'] . ' · Amnezia ' . $counts['wg'] . ($wgLimit > 0 ? ' / ' . $this->i18n('user portal wg limit') . ' ' . $wgLimit : '');
        if ($tgId !== '') {
            $text[] = $this->i18n('user portal expires') . ': ' . $this->userPortalExpiryText($this->getUserPortalExpiry($tgId));
        }

        $data = [];
        $pending  = (int) ($row['approved'] ?? 1) === 0;
        $rejected = (int) ($row['approved'] ?? 1) === 2;
        if ($rejected) {
            $text[] = $this->i18n('user portal status') . ': ' . $this->i18n('user portal rejected');
        }
        if ($pending) {
            // Заявка (ещё не одобрена) — ровно 4 кнопки: Одобрить / Написать /
            // Выдать empty proto / Отклонить. «Выдать подписку»/«Amnezia» здесь
            // нет — выдача идёт после одобрения.
            if ($tgId !== '') {
                $data[] = [[
                    'text'          => '✅ Одобрить',
                    'callback_data' => '/userPortalApprove ' . $tgId,
                ], [
                    'text'          => '✉️ Написать',
                    'callback_data' => '/userPortalWriteTo ' . $tgId,
                ], [
                    'text'          => $this->i18n('user portal empty proto issue'),
                    'callback_data' => '/userPortalCardEmptyProto ' . rawurlencode($canonicalKey),
                ], [
                    'text'          => '❌ Отклонить',
                    'callback_data' => '/userPortalReject ' . $tgId,
                ]];
                $data[] = [[
                    'text'          => $this->i18n('back'),
                    'callback_data' => '/userPortalRequests ' . $backPage,
                ]];
            } else {
                $data[] = [[
                    'text'          => $this->i18n('back'),
                    'callback_data' => '/userPortalRequests ' . $backPage,
                ]];
            }
        } elseif ($rejected) {
            // Отклонённая заявка: статус «Отклонено» + «Вернуть» (если отклонили
            // ошибочно) и «Удалить» (удалить полностью). Выдачи/отзыва здесь нет.
            if ($tgId !== '') {
                $data[] = [[
                    'text'          => $this->i18n('user portal restore'),
                    'callback_data' => '/userPortalRestore ' . $tgId,
                ], [
                    'text'          => '✉️ Написать',
                    'callback_data' => '/userPortalWriteTo ' . $tgId,
                ]];
                $data[] = [[
                    'text'          => $this->i18n('user portal delete user'),
                    'callback_data' => '/userPortalCardDelete ' . rawurlencode($canonicalKey),
                ]];
            }
            $data[] = [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalRequests ' . $backPage,
            ]];
        } else {
            // Одобренный пользователь — меню выдачи + «Отозвать» (открывает
            // выбор: VLESS / Amnezia / всё). Руслан: «в карточке кнопка отозвать,
            // и там выбор — если есть и VLESS и Amnezia, отзываю и то и другое
            // или что-то одно; если только Amnezia — отзываю конфиг».
            // Владелец удаляет любого (включая себя); остальные админы удаляют
            // чужих пользователей, но себя — не могут (только отозвать свои
            // конфиги). Кнопку «Удалить» прячем на собственной карточке не-владельца.
            $viewingSelf = $tgId !== '' && (string) $tgId === (string) ($this->input['from'] ?? '');
            $canDelete   = $this->isUserPortalOwner() || !$viewingSelf;
            if ($tgId !== '') {
                $data[] = [[
                    'text'          => $this->i18n('user portal issue vless'),
                    'callback_data' => '/userPortalCardSubPick ' . rawurlencode($canonicalKey),
                ], [
                    'text'          => $this->i18n('user portal issue wg'),
                    'callback_data' => '/userPortalCardWg ' . rawurlencode($canonicalKey),
                ]];
                $middle = [[
                    'text'          => '✉️ Написать',
                    'callback_data' => '/userPortalWriteTo ' . $tgId,
                ], [
                    'text'          => $this->i18n('user portal empty proto'),
                    'callback_data' => '/userPortalCardEmptyProto ' . rawurlencode($canonicalKey),
                ]];
                if ($canDelete) {
                    $middle[] = [
                        'text'          => $this->i18n('user portal delete user'),
                        'callback_data' => '/userPortalCardDelete ' . rawurlencode($canonicalKey),
                    ];
                }
                $data[] = $middle;
                $data[] = [[
                    'text'          => $this->i18n('user portal wg limit') . ': ' . $wgLimit,
                    'callback_data' => '/userPortalWgLimit ' . rawurlencode($canonicalKey),
                ], [
                    'text'          => $this->i18n('user portal expiry') . ': ' . $this->userPortalExpiryText($this->getUserPortalExpiry($tgId)),
                    'callback_data' => '/userPortalExpiry ' . rawurlencode($canonicalKey),
                ]];
                $data[] = [[
                    'text'          => $this->i18n('user portal grant revoke'),
                    'callback_data' => '/userPortalCardRevoke ' . rawurlencode($canonicalKey),
                ]];
            }
            $data[] = [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalUsers ' . $backPage,
            ]];
        }
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

    /**
     * «Выдать Empty Proto» — отправить общую MTProto-ссылку (t.me/proxy)
     * человеку из карточки заявки, даже если VPN/подписки нет. Ключ карточки —
     * Telegram id заявителя (заявка может не иметь привязанной подписки).
     */
    public function userPortalCardEmptyProto(string $key)
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
        $tgId = (string) ($row['telegram_id'] ?? '');

        $link = method_exists($this, 'linkMtproto') ? (string) $this->linkMtproto() : '';
        if ($link === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal empty proto empty'));
            $this->userPortalCard($key);

            return;
        }

        // Доставить ссылку прямо в чат заявителя по Telegram id — не через
        // deliverPortalConfig (тот маршрутизирует по привязке subscription_id,
        // которой у пустой заявки может не быть).
        if ($tgId !== '') {
            $text = '<b>' . $this->i18n('user portal empty proto title') . '</b>' . "\n\n"
                . $this->i18n('user portal empty proto how') . "\n\n"
                . '<pre><code>' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
            try {
                $this->send((int) $tgId, $text);
            } catch (\Throwable $e) {
                // Не фатально: доставка ссылки не должна ломать карточку.
            }

            // Пометить заявку «полуодобренной»: пустой прото выдан, но approved
            // не трогаем — заявка остаётся в списке, пока админ не одобрит/отклонит.
            $this->withPacLock(function () use ($tgId): void {
                $pac = $this->getPacConf();
                $bindings = $this->getUserPortalBindings();
                $record = self::normalizeUserPortalBinding($bindings[$tgId] ?? null);
                $record['empty_proto'] = 1;
                $bindings[$tgId] = $record;
                $pac['user_portal_bindings'] = $bindings;
                $this->setPacConf($pac);
            });

            // Подтверждение админам, что ссылка доставлена. Заявка при этом
            // остаётся в списке: это не одобрение и не отказ, approved не трогаем.
            $this->notifyAdminIssue($this->userPortalWriteTargetName($tgId), 'link');

            // Перерисовать человеку его кабинет — у него появилась empty proto
            // ссылка, меню (со «Поддержкой») должно стать актуальным.
            $this->renderUserPortalAs($tgId);

            $this->userPortalSetFlash('✅ ' . $this->i18n('user portal empty proto delivered'));
        }

        $this->userPortalCard($key);
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
     * «Отозвать» внутри карточки — выбор, что именно отозвать: VLESS-подписку,
     * один или все Amnezia/WG-конфиги, либо всё сразу. Руслан: «в карточке
     * кнопка отозвать, и там выбор — если есть и VLESS и Amnezia, отзываю и то
     * и другое или что-то одно; если только Amnezia — отзываю конфиг».
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
        $subId      = (string) ($row['subscription_id'] ?? '');
        $name       = $this->userPortalDisplayName($row);

        $hasVless = false;
        if ($subId !== '') {
            $hasVless = $this->resolveSubscriptionClient($subId) !== null;
        }
        $wgClients = $telegramId !== '' ? $this->userPortalIssuedWgClientsByTg($telegramId) : [];

        $text = ['<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>'];
        $text[] = $this->i18n('user portal revoke menu');

        // Выбор по принципу выдачи: VLESS и/или Amnezia одной кнопкой каждый.
        // Конкретный конфиг Amnezia выбирается дальше мультивыбором
        // (userPortalWgRevokePick), а не списком кнопок здесь.
        $data = [];
        if ($hasVless) {
            $data[] = [[
                'text'          => 'VLESS — ' . $this->i18n('user portal grant revoke'),
                'callback_data' => '/userPortalCardRevokeVless ' . rawurlencode($canonicalKey),
            ]];
        }
        if (!empty($wgClients)) {
            $data[] = [[
                'text'          => 'Amnezia — ' . $this->i18n('user portal grant revoke'),
                'callback_data' => '/userPortalWgRevokePick ' . rawurlencode($canonicalKey),
            ]];
        }
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalCard ' . rawurlencode($canonicalKey . '_' . $this->userPortalBackPage('users')),
        ]];

        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

    /**
     * «Отозвать VLESS» — удалить только VLESS-подписку владельца и его
     * устройства из xray.json, не трогая Amnezia/WG-пиры и привязку каталога.
     * Повторяет шаг 1 из userPortalCardDelete, но без сноса WG-конфигов.
     */
    public function userPortalCardRevokeVless(string $key)
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
        $subId      = (string) ($row['subscription_id'] ?? '');
        if ($subId === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalCard($key);

            return;
        }

        $xr = $this->getXray();
        $removed = 0;
        foreach ($xr['inbounds'] as $inbound) {
            $clients = $inbound['settings']['clients'] ?? null;
            if (!is_array($clients)) {
                continue;
            }
            foreach ($clients as $client) {
                if (!is_array($client) || !$this->isSubscriptionIdMatch($client, $subId)) {
                    continue;
                }
                $cid = (string) ($client['id'] ?? '');
                if ($cid !== '') {
                    $removed += $this->removeXrayClientEverywhere($xr, 'id', $cid);
                }
            }
        }
        if ($removed > 0) {
            $this->restartXray($xr);
        }

        // Сообщить человеку об отзыве VLESS-подписки.
        if ($telegramId !== '') {
            try {
                $this->send($telegramId, $this->i18n('user portal revoked vless msg'));
            } catch (\Throwable $e) {
            }
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal revoke ok'));
        $this->userPortalCard($canonicalKey);
    }

    /**
     * «Удалить пользователя» — снести человека из базы целиком: его VLESS-
     * подписку (клиент владельца + все его устройства), все выданные WG-пиры,
     * запись в каталоге (binding) и счётчики леджера «выдано». В отличие от
     * «Отозвать» (которое лишь снимает привязку), после удаления запись не
     * «воскресает» из xray.json заново — клиентов больше нет.
     */
    public function userPortalCardDelete(string $key)
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
        $subId = (string) ($row['subscription_id'] ?? '');

        // Не-владелец админ не может удалить сам себя: только отозвать свои
        // конфиги. Владелец удаляет любого, включая себя.
        if ($telegramId !== '' && (string) $telegramId === (string) ($this->input['from'] ?? '') && !$this->isUserPortalOwner()) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal cannot delete self'));
            $this->userPortalCard($canonicalKey);

            return;
        }

        // 1. Снести VLESS-клиентов владельца и его устройств из xray.json.
        if ($subId !== '') {
            $xr = $this->getXray();
            $removed = 0;
            foreach ($xr['inbounds'] as $inbound) {
                $clients = $inbound['settings']['clients'] ?? null;
                if (!is_array($clients)) {
                    continue;
                }
                foreach ($clients as $client) {
                    if (!is_array($client) || !$this->isSubscriptionIdMatch($client, $subId)) {
                        continue;
                    }
                    $cid = (string) ($client['id'] ?? '');
                    if ($cid !== '') {
                        $removed += $this->removeXrayClientEverywhere($xr, 'id', $cid);
                    }
                }
            }
            if ($removed > 0) {
                $this->restartXray($xr);
            }

            // 2. Снести WG-пиры владельца.
            foreach ($this->userPortalIssuedWgClients($subId) as $wg) {
                $this->deleteClient((int) $wg['index']);
            }

            // 3. Снести устройства HWID и счётчик леджера.
            if (method_exists($this, 'deleteHwidUser')) {
                $this->deleteHwidUser($subId);
            }
            $this->withPacLock(function () use ($subId): void {
                $pac = $this->getPacConf();
                $ledger = $pac['user_portal_issued'] ?? [];
                if (is_array($ledger) && isset($ledger[$subId])) {
                    unset($ledger[$subId]);
                    $pac['user_portal_issued'] = $ledger;
                    $this->setPacConf($pac);
                }
            });
        }

        // 4. Снять привязку из каталога (если была).
        if ($telegramId !== '') {
            $this->removeUserPortalBinding($telegramId);
        }

        // 5. Сообщить человеку и админу.
        if ($telegramId !== '') {
            try {
                $this->send($telegramId, $this->i18n('user portal deleted msg'));
            } catch (\Throwable $e) {
            }
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal deleted ok'));
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
        $tgId = (string) ($row['telegram_id'] ?? '');
        // Новая VLESS-подписка наследует срок кабинета, а не свой дефолт:
        // «если у VLESS было 35 дней, но подписки нет, я выдал — подгоняется
        // под кабинет». 0 тоже применяется (снимает срок — подписка «без
        // ограничения», как и кабинет).
        if ($tgId !== '') {
            $this->setUserPortalVlessTime($subscriptionId, $this->getUserPortalExpiry($tgId));
        }
        $owner = $this->resolveSubscriptionClient($subscriptionId);
        if ($owner === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal sub not found'));
            $this->userPortalCard($key);

            return;
        }
        $index = $owner['index'];
        // Родительский UUID в HWID-runtime режиме ретайрится при первой
        // регистрации устройства (retireParentRuntimeUuid), и выданный по нему
        // vless://-линк тут же умирает — та самая «слетающая» ссылка. Вместо
        // голого parent-линка шлём ссылку на страницу подписки: она стабильна
        // и ведёт пользователя к рабочему девайсному ключу (как bot.php:10211).
        if ($this->isPermanentHwidRuntime($owner['client'])) {
            $pageUrl = $this->buildUserSubscriptionLinks($subscriptionId)['page'] ?? '';
            if ($pageUrl === '') {
                $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal vless empty'));
                $this->userPortalCard($key);

                return;
            }
            $this->deliverPortalConfig($subscriptionId, $pageUrl, 'vless', $this->userPortalDisplayName($row));
        } else {
            $link = $this->linkXray($index);
            if ($link === '') {
                $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal vless empty'));
                $this->userPortalCard($key);

                return;
            }
            $this->deliverPortalConfig($subscriptionId, $link, 'vless', $this->userPortalDisplayName($row));
        }
        $issuedCount = $this->bumpUserPortalIssued($subscriptionId, 'vless');
        $this->userPortalNotifyIssue($subscriptionId, 'vless', $issuedCount);
        $this->notifyAdminIssue($this->userPortalDisplayName($row), 'vless');
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal issue config ok'));
        $this->userPortalCard($key);
    }

    /**
     * Все Amnezia/WG-конфиги, выданные этой подписке: пиры контейнера WG
     * (clients1.json), помеченные ## owner_sub_id == subscription_id.
     * Это единственный источник правды для «выданных конфигов» — независим от
     * hwidStorage (у человека может не быть ни одного VLESS-устройства, но
     * выданные ранее WG-пиры уже живут в контейнере).
     *
     * Возвращает список: [['index' => int, 'name' => string], ...] в порядке
     * контейнера. Индекс — позиция пира в readClients(), по ней потом строим
     * конфиг/QR в userPortalIssuedWgShow().
     */
    protected function userPortalIssuedWgClients(string $subscriptionId): array
    {
        if ($subscriptionId === '') {
            return [];
        }
        $out = [];
        foreach ($this->readClients() as $index => $client) {
            if (!is_array($client) || !is_array($client['interface'] ?? null)) {
                continue;
            }
            if (($client['interface']['## owner_sub_id'] ?? '') !== $subscriptionId) {
                continue;
            }
            $out[] = [
                'index' => (int) $index,
                'name'  => $this->getName($client['interface']),
                'time'  => (string) ($client['interface']['## time'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Все Amnezia/WG-конфиги, выданные конкретному ЧЕЛОВЕКУ (telegram id),
     * независимо от VLESS-подписки. Первичный ключ владения — ## owner_tg
     * (ставится в userPortalWgIssue); фолбэк по ## owner_sub_id для пиров,
     * выданных до появления owner_tg и привязанных к подписке этого человека.
     */
    protected function userPortalIssuedWgClientsByTg(string $telegramId): array
    {
        $telegramId = trim($telegramId);
        if ($telegramId === '') {
            return [];
        }
        $out = [];
        foreach ($this->readClients() as $index => $client) {
            if (!is_array($client) || !is_array($client['interface'] ?? null)) {
                continue;
            }
            $iface = $client['interface'];
            // Первичный ключ владения — ## owner_tg (ставится в
            // userPortalWgIssue). Фолбэк по ## owner_sub_id для пиров,
            // выданных до появления owner_tg: владелец — тот, чья подписка
            // привязана к этому telegram id в user_portal_bindings.
            $ownerTg = (string) ($iface['## owner_tg'] ?? '');
            if ($ownerTg !== '') {
                if ($ownerTg !== $telegramId) {
                    continue;
                }
            } else {
                $ownerSubId = (string) ($iface['## owner_sub_id'] ?? '');
                $ownerSub   = $this->getUserPortalBinding($telegramId) ?? '';
                if ($ownerSubId === '' || $ownerSubId !== $ownerSub) {
                    continue;
                }
            }
            $out[] = [
                'index' => (int) $index,
                'name'  => $this->getName($iface),
                'time'  => (string) ($iface['## time'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Сколько конфигов выдано человеку: VLESS (0/1 — есть подписка или нет) и
     * Amnezia/WG (число WG-пиров, привязанных по telegram id). Источник правды
     * для списка людей и карточки — вместо вводящего в заблуждение «активен».
     */
    protected function userPortalIssuedCounts(array $row): array
    {
        $subId = (string) ($row['subscription_id'] ?? '');
        $tgId  = (string) ($row['telegram_id'] ?? '');
        // «VLESS 1» только если подписка реально есть в xray (та же проверка,
        // что и в меню отзыва userPortalCardRevoke). Раньше считали по непустому
        // subscription_id — карточка показывала «VLESS 1» при битой/протухшей
        // привязке, а отозвать было нечего.
        $vless = ($subId !== '' && $this->resolveSubscriptionClient($subId) !== null) ? 1 : 0;
        $wg    = $tgId !== '' ? count($this->userPortalIssuedWgClientsByTg($tgId)) : 0;

        return ['vless' => $vless, 'wg' => $wg];
    }

    /**
     * WG-конфиги, которыми владеет юзер в кабинете: при обычной подписке —
     * по subscription_id, при «только Амнезия» (без VLESS) — по telegram id.
     * Единая точка для userPortalIssuedWg / userPortalIssuedWgShow.
     */
    protected function userPortalOwnedWgClients(array $session): array
    {
        if (!empty($session['wg_only'])) {
            return $this->userPortalIssuedWgClientsByTg((string) ($session['telegram_id'] ?? ''));
        }

        return $this->userPortalIssuedWgClients((string) $session['subscription_id']);
    }
    public function userPortalCardSubPick(string $key, int $page = 0)
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
        $name = $this->userPortalDisplayName($row);

        $choices = $this->userPortalSubscriptionChoices();
        $text = [$this->i18n('user portal pick sub')];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = '<b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        $text[] = '';

        if (empty($choices)) {
            $text[] = $this->i18n('user portal no subs');
            $data = [[
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => '/userPortalCard ' . rawurlencode($canonicalKey . '_' . $this->userPortalBackPage('users')),
                ],
            ]];
            $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);

            return;
        }

        $perPage = $this->limit ?: 8;
        $keys = array_keys($choices);
        $pages = max(1, (int) ceil(count($keys) / $perPage));
        $page = max(0, min($page, $pages - 1));
        $slice = array_slice($keys, $page * $perPage, $perPage, true);

        $data = [];
        foreach ($slice as $idx) {
            $c = $choices[$idx];
            $data[] = [[
                'text'          => $c['label'],
                'callback_data' => '/userPortalCardSubIssue ' . rawurlencode($canonicalKey) . ' ' . $idx,
            ]];
        }
        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = [
                    'text'          => '←',
                    'callback_data' => '/userPortalSubPick ' . rawurlencode($canonicalKey) . '_' . ($page - 1),
                ];
            }
            $nav[] = [
                'text'          => ($page + 1) . '/' . $pages,
                'callback_data' => '/userPortalSubPick ' . rawurlencode($canonicalKey) . '_' . $page,
            ];
            if ($page < $pages - 1) {
                $nav[] = [
                    'text'          => '→',
                    'callback_data' => '/userPortalSubPick ' . rawurlencode($canonicalKey) . '_' . ($page + 1),
                ];
            }
            $data[] = $nav;
        }
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalCard ' . rawurlencode($canonicalKey . '_' . $this->userPortalBackPage('users')),
        ]];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

    /**
     * Выдать выбранную VLESS-подписку человеку из карточки: привязать
     * subscription_id человека к его Telegram id и доставить VLESS-ссылку.
     */
    public function userPortalCardSubIssue(string $key, int $index)
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

        $c = $this->findXrayClientByIndex($this->getXray(), $index);
        if ($c === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal sub not found'));
            $this->userPortalCardSubPick($canonicalKey, 0);

            return;
        }
        $subscriptionId = $this->getClientSubscriptionId($c);

        if ($telegramId !== '') {
            $this->setUserPortalBinding($telegramId, $subscriptionId);
            // Выданная VLESS-подписка подгоняется под срок кабинета.
            $this->setUserPortalVlessTime($subscriptionId, $this->getUserPortalExpiry($telegramId));
        }

        // Доставка: как в userPortalCardVless — parent-линк ретайрится в
        // HWID-режиме, поэтому шлём страницу подписки; иначе голый vless://-линк.
        $label = $this->userPortalDisplayName($row);
        if ($this->isPermanentHwidRuntime($c)) {
            $pageUrl = $this->buildUserSubscriptionLinks($subscriptionId)['page'] ?? '';
            if ($pageUrl !== '') {
                $this->deliverPortalConfig($subscriptionId, $pageUrl, 'vless', $label);
            }
        } else {
            $link = $this->linkXray($index);
            if ($link !== '') {
                $this->deliverPortalConfig($subscriptionId, $link, 'vless', $label);
            }
        }

        $issuedCount = $this->bumpUserPortalIssued($subscriptionId, 'vless');
        $this->userPortalNotifyIssue($subscriptionId, 'vless', $issuedCount);
        $this->notifyAdminIssue($this->userPortalDisplayName($row), 'vless');
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal issue config ok'));
        if ($telegramId !== '') {
            // Перерисовать кабинет получателя сразу после выдачи: как при
            // «Одобрить», иначе человек видит «нет активной подписки» до /update.
            $this->renderUserPortalAs($telegramId);
        }
        $this->userPortalCard($canonicalKey);
    }

    /**
     * «Мои конфиги AmneziaWG» — список всех выданных подписке WG-конфигов
     * (из контейнера WG) с кнопкой показа. Доступно при любом числе выдач:
     * Руслан выдаёт сколько считает нужным, все они тут и появляются.
     */
    public function userPortalIssuedWg($pageToken = 0)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }

        $ownerSubId = (string) $session['subscription_id'];
        $clients = $this->userPortalOwnedWgClients($session);
        $scope = $this->getUserPortalTokenScope();
        if (!isset($_SESSION['hwidTokens'])) {
            $_SESSION['hwidTokens'] = [];
        }
        $_SESSION['hwidTokens'][$scope] = [];

        $perPage = max(1, $this->limit ?: 5);
        $total = count($clients);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max((int) explode('_', (string) $pageToken)[0], 0), $pages - 1);
        $slice = array_slice($clients, $page * $perPage, $perPage);

        $text = [$this->i18n('user portal issued title')];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = '';

        $data = [];
        if ($total === 0) {
            $text[] = $this->i18n('user portal issued wg empty add');
        } else {
            foreach ($slice as $i => $item) {
                $number = $page * $perPage + $i + 1;
                $name = $item['name'] !== '' ? $item['name'] : ('WG ' . $number);
                $line = $number . '. <b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
                if (!empty($item['time'])) {
                    $ts = strtotime((string) $item['time']);
                    if ($ts !== false) {
                        $line .= ' — ' . $this->i18n('user portal expires') . ' ' . date('d.m.Y H:i', $ts);
                    }
                }
                $text[] = $line;
                $data[] = [
                    [
                        'text'          => $this->i18n('user portal issued wg show') . ' ' . $name,
                        'callback_data' => '/userPortalIssuedWgShow ' . $item['index'],
                    ],
                    [
                        'text'          => $this->i18n('user portal rename wg') . ' ' . $name,
                        'callback_data' => '/userPortalRenameWg ' . $item['index'],
                    ],
                ];
            }
        }

        if ($pages > 1) {
            $data[] = [
                [
                    'text'          => '<<',
                    'callback_data' => '/userPortalIssuedWg_' . ($page - 1 >= 0 ? $page - 1 : $pages - 1),
                ],
                [
                    'text'          => ($page + 1) . '/' . $pages,
                    'callback_data' => '/userPortalIssuedWg_' . $page,
                ],
                [
                    'text'          => '>>',
                    'callback_data' => '/userPortalIssuedWg_' . (($page + 1) % $pages),
                ],
            ];
        }

        $data[] = [[
            'text'          => $this->i18n('user portal wg add self'),
            'callback_data' => '/userPortalWgAddSelf',
        ]];

        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]];

        $this->userPortalShow(implode("\n", $text), $data ?: false);
    }

    /**
     * Переименовать выданный WG-конфиг: спросить новое имя, показав текущее,
     * чтобы человек не переименовал случайно не тот конфиг.
     */
    public function userPortalRenameWg($clientIndex)
    {
        $resolved = $this->userPortalIssuedWgResolve($clientIndex);
        if ($resolved === null) {
            $this->ackCallback($this->i18n('user portal issued wg empty'), true);
            $this->userPortalIssuedWg();

            return;
        }

        $prompt = $this->i18n('user portal enter wg name');
        $currentName = trim((string) ($resolved['name'] ?? ''));
        if ($currentName !== '') {
            $prompt .= "\n\n" . $this->i18n('user portal current device name') . ': <b>'
                . htmlspecialchars($currentName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $this->userPortalPromptInput($prompt, 'userPortalRenameWgSave', [$clientIndex]);
    }

    /**
     * Сохранить новое имя WG-конфига и атомарно применить его на сервере
     * (syncconf) — без обрыва соединений у остальных клиентов.
     */
    public function userPortalRenameWgSave($name, $clientIndex)
    {
        $name = trim((string) $name);
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalMenu();

            return;
        }

        $resolved = $this->userPortalIssuedWgResolve($clientIndex);
        if ($resolved === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal issued wg empty'));
            $this->userPortalIssuedWg();

            return;
        }

        if ($name === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal empty device name'));
            $this->userPortalRenameWg($clientIndex);

            return;
        }

        $ownerSubId = (string) $session['subscription_id'];
        if (!$this->checkSubscriptionActionRateLimit($ownerSubId, 'wg_rename', 30, 600)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal rate limit'));
            $this->userPortalIssuedWg();

            return;
        }

        $clients = $this->readClients();
        $clients[$clientIndex]['interface']['## name'] = $name;
        $this->saveClients($clients);

        $server = $this->readConfig();
        foreach ($server['peers'] as $k => $v) {
            if (($v['AllowedIPs'] ?? '') === ($clients[$clientIndex]['interface']['Address'] ?? '')) {
                $server['peers'][$k]['## name'] = $name;
            }
        }
        $this->restartWG($this->createConfig($server));

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal wg renamed') . ': ' . $name);
        $this->userPortalIssuedWg();
    }

    /**
     * Показать один выданный WG-конфиг по индексу пира в контейнере WG.
     * Первый шаг — развилка «зрячий / незрячий»: файлы ещё не шлём, только
     * меню. Индекс проверяем на принадлежность — чужой конфиг не отдаём.
     */
    public function userPortalIssuedWgShow($clientIndex)
    {
        $resolved = $this->userPortalIssuedWgResolve($clientIndex);
        if ($resolved === null) {
            $this->ackCallback($this->i18n('user portal issued wg empty'), true);
            $this->userPortalIssuedWg();

            return;
        }

        $text = [$this->i18n('user portal issued title')];
        if ($resolved['name'] !== '') {
            $text[] = '<b>' . htmlspecialchars($resolved['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $text[] = '';
        $text[] = $this->i18n('user portal show mode title');

        $this->userPortalShow(implode("\n", $text), [
            [[
                'text'          => $this->i18n('user portal show mode sighted'),
                'callback_data' => '/userPortalIssuedWgShowMode ' . $resolved['index'] . ' sighted',
            ]],
            [[
                'text'          => $this->i18n('user portal show mode blind'),
                'callback_data' => '/userPortalIssuedWgShowMode ' . $resolved['index'] . ' blind',
            ]],
            [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalIssuedWg',
            ]],
        ]);
    }

    /**
     * Резолв выданного WG-конфига по индексу пира: принадлежность подписке
     * или человеку (wg_only) + имя + текст .conf. null — чужой/битый индекс.
     */
    protected function userPortalIssuedWgResolve($clientIndex): ?array
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            return null;
        }
        $ownerSubId = (string) $session['subscription_id'];
        $clientIndex = (int) $clientIndex;
        $peer = $this->readClients()[$clientIndex] ?? null;
        if (!is_array($peer) || !is_array($peer['interface'] ?? null)) {
            return null;
        }
        // Владелец пира: для «только Амнезия» — telegram id (owner_tg),
        // иначе — подписка (owner_sub_id). Чужой конфиг по номеру не отдаём.
        $owned = false;
        if (!empty($session['wg_only'])) {
            $owned = (string) ($peer['interface']['## owner_tg'] ?? '') === (string) ($session['telegram_id'] ?? '');
        } else {
            $owned = (string) ($peer['interface']['## owner_sub_id'] ?? '') === $ownerSubId;
        }
        if (!$owned) {
            return null;
        }
        $name = $this->getName($peer['interface']);
        $conf = (string) $this->createConfig($peer);
        if ($conf === '') {
            return null;
        }

        return [
            'index' => $clientIndex,
            'name'  => $name,
            'conf'  => $conf,
            'peer'  => $peer,
        ];
    }

    /**
     * Шаг 2 — выбор ОС после «зрячий / незрячий». Файлы по-прежнему не шлём.
     */
    public function userPortalIssuedWgShowMode($clientIndex, $mode)
    {
        if ($mode !== 'sighted' && $mode !== 'blind') {
            $this->ackCallback($this->i18n('user portal issued wg empty'), true);

            return;
        }
        $resolved = $this->userPortalIssuedWgResolve($clientIndex);
        if ($resolved === null) {
            $this->ackCallback($this->i18n('user portal issued wg empty'), true);
            $this->userPortalIssuedWg();

            return;
        }

        $text = [$this->i18n('user portal issued title')];
        if ($resolved['name'] !== '') {
            $text[] = '<b>' . htmlspecialchars($resolved['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $text[] = '';
        $text[] = $this->i18n('user portal show os title');

        $osList = ['win', 'mac', 'linux', 'ios', 'android'];
        $data = [];
        foreach ($osList as $os) {
            $data[] = [[
                'text'          => $this->i18n('user portal show os ' . $os),
                'callback_data' => '/userPortalIssuedWgShowOs ' . $resolved['index'] . ' ' . $mode . ' ' . $os,
            ]];
        }
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalIssuedWgShow ' . $resolved['index'],
        ]];

        $this->userPortalShow(implode("\n", $text), $data);
    }

    /**
     * Шаг 3 — выдача по ОС. Файлы здесь НЕ шлём: показываем инструкцию
     * (без сырых ссылок) и кнопки «Скачать 1» / «Скачать 2» (зеркало, только
     * Android) / «Получить конфиг» / «назад». Файлы уходят по кнопке
     * «Получить конфиг» → userPortalIssuedWgGetConf().
     */
    public function userPortalIssuedWgShowOs($clientIndex, $mode, $os)
    {
        if (($mode !== 'sighted' && $mode !== 'blind') || !in_array($os, ['win', 'mac', 'linux', 'ios', 'android'], true)) {
            $this->ackCallback($this->i18n('user portal issued wg empty'), true);

            return;
        }
        $resolved = $this->userPortalIssuedWgResolve($clientIndex);
        if ($resolved === null) {
            $this->ackCallback($this->i18n('user portal issued wg empty'), true);
            $this->userPortalIssuedWg();

            return;
        }

        $isAwg = $this->getWGType() === 'awg';
        $this->ackCallback();

        $dl = $this->i18n('user portal show download');
        $buttons = [];
        if (!$isAwg) {
            $buttons[] = [['text' => $dl . ' 1', 'url' => 'https://www.wireguard.com/install/']];
        } elseif ($os === 'android') {
            $buttons[] = [['text' => 'AmneziaWG — Play', 'url' => 'https://play.google.com/store/apps/details?id=org.amnezia.awg']];
            $buttons[] = [['text' => 'AmneziaWG — APK', 'url' => 'https://github.com/amnezia-vpn/amneziawg-android/releases']];
            if ($mode === 'sighted') {
                $buttons[] = [['text' => 'AmneziaVPN — Play', 'url' => 'https://play.google.com/store/apps/details?id=org.amnezia.vpn']];
                $buttons[] = [['text' => 'AmneziaVPN — APK', 'url' => 'https://github.com/amnezia-vpn/amnezia-client/releases']];
            }
        } elseif ($os === 'ios') {
            $buttons[] = [['text' => 'AmneziaWG — App Store', 'url' => 'https://apps.apple.com/app/amneziawg/id6478942365']];
            if ($mode === 'sighted') {
                $buttons[] = [['text' => 'AmneziaVPN — App Store', 'url' => 'https://apps.apple.com/us/app/amneziavpn/id1600529900']];
            }
        } else {
            $awgUrls = [
                'win'   => 'https://github.com/amnezia-vpn/amneziawg-windows-client/releases/latest',
                'mac'   => 'https://apps.apple.com/app/amneziawg/id6478942365',
                'linux' => 'https://github.com/amnezia-vpn/amneziawg-tools/releases',
            ];
            $buttons[] = [['text' => $dl . ' 1', 'url' => $awgUrls[$os]]];
        }
        $buttons[] = [[
            'text'          => $this->i18n('user portal show get conf'),
            'callback_data' => '/userPortalIssuedWgGetConf ' . $resolved['index'] . ' ' . $mode . ' ' . $os,
        ]];
        $buttons[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalIssuedWgShowMode ' . $resolved['index'] . ' ' . $mode,
        ]];

        // Инструкция под эту ОС (в меню) + правило «не переименовывать».
        $this->userPortalShow(
            $this->i18n('user portal show ins ' . $mode . ' ' . $os) . "\n\n" . $this->i18n('user portal show rename rule'),
            $buttons
        );
    }

    /**
     * «Получить конфиг» — по кнопке из шага 3. Шлёт файлы: .conf всегда; QR —
     * зрячему на мобильных (AmneziaWG, плюс AmneziaVPN под Android — приложение
     * там есть). На десктопе и незрячему — только .conf.
     */
    public function userPortalIssuedWgGetConf($clientIndex, $mode, $os)
    {
        if (($mode !== 'sighted' && $mode !== 'blind') || !in_array($os, ['win', 'mac', 'linux', 'ios', 'android'], true)) {
            $this->ackCallback($this->i18n('user portal issued wg empty'), true);

            return;
        }
        $resolved = $this->userPortalIssuedWgResolve($clientIndex);
        if ($resolved === null) {
            $this->ackCallback($this->i18n('user portal issued wg empty'), true);
            $this->userPortalIssuedWg();

            return;
        }

        $label = $resolved['name'] !== '' ? $resolved['name'] : ('WG ' . $resolved['index']);
        $conf = $resolved['conf'];
        $isAwg = $this->getWGType() === 'awg';
        $clientName = $isAwg ? 'AmneziaWG' : 'WireGuard';
        $isMobile = in_array($os, ['ios', 'android'], true);
        $isSighted = $mode === 'sighted';

        $this->ackCallback();

        // QR — зрячему на мобильных: там его сканируют в приложении.
        if ($isSighted && $isMobile) {
            $this->sendQr($label, $conf, $label . ' — ' . $clientName);
            // AmneziaVPN — полный клиент; шлём и его ключ (vpn://) на мобильных.
            if ($isAwg && ($os === 'android' || $os === 'ios')) {
                $short = (string) $this->getAmneziaShortLink($resolved['peer']);
                if ($short !== '') {
                    $this->sendQr($label, preg_replace('~^vpn://~', '', $short), $label . ' — AmneziaVPN');
                }
            }
        }

        // .conf — всегда.
        $confFile = sys_get_temp_dir() . '/' . preg_replace(['~\s+~', '~\(~', '~\)~'], ['_'], $label) . '.conf';
        file_put_contents($confFile, $conf);
        $this->sendFile($this->input['chat'], curl_file_create($confFile), $label . ' — ' . $clientName . ' (.conf)');
        @unlink($confFile);
    }

    /**
     * «Выдать Amnezia/WG» — выбор: администратор сам решает, что выдавать.
     * Открывает меню со списком WG-пиров контейнера (WG1) + кнопкой добавить
     * новый конфиг. Никакой привязки к VLESS-устройствам нет — амнезия живёт
     * сама по себе, метка владельца ## owner_sub_id = subscription_id.
     */
    public function userPortalCardWg(string $key, int $page = -1)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $telegramId = (string) ($row['telegram_id'] ?? '');
        $subscriptionId = (string) ($row['subscription_id'] ?? '');
        $ownerSubId = '';
        if ($subscriptionId !== '') {
            $owner = $this->resolveSubscriptionClient($subscriptionId);
            if ($owner !== null) {
                $ownerSubId = $owner['subscription_id'];
            }
        }

        // Все пиры WG1-контейнера в том порядке, в котором лежат.
        $peers = $this->readClients();
        $total = count($peers);
        $perPage = max(1, $this->limit ?: 5);
        $pages = max(1, (int) ceil($total / $perPage));
        // Возврат из выдачи/добавления пира (userPortalWgIssue / WgAddCommit)
        // зовёт userPortalCardWg($key) без page — восстанавливаем последнюю
        // страницу списка пиров, а не сбрасываем на первую.
        if ($page < 0) {
            $page = $this->userPortalBackPage('wg');
        }
        $page = max(0, min($page, $pages - 1));
        $this->userPortalSetBackPage(['wg' => $page]);
        $slice = array_slice($peers, $page * $perPage, $perPage, true);

        // Мультивыбор: индексы, отмеченные админом на этой карточке.
        $select = $_SESSION['userPortalWgSelect'][(string) $canonicalKey] ?? [];
        $selectedCount = count($select);

        $text = [$this->i18n('user portal wg pick title') . ':'];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = $this->i18n('user portal wg select hint');
        $text[] = '';

        $data = [];
        if ($total === 0) {
            $text[] = $this->i18n('user portal wg pick empty');
        } else {
            foreach ($slice as $index => $client) {
                if (!is_array($client) || !is_array($client['interface'] ?? null)) {
                    continue;
                }
                $iface = $client['interface'];
                $name = $this->getName($iface);
                $label = $name !== '' ? $name : ('WG ' . $index);
                // Владелец — сам человек (## owner_tg, первично) либо его подписка.
                $already = (string) ($iface['## owner_tg'] ?? '') === $telegramId
                    || ($ownerSubId !== '' && (string) ($iface['## owner_sub_id'] ?? '') === $ownerSubId);
                $selected = isset($select[(int) $index]);
                // Срок жизни конфига — показываем в списке выдачи, чтобы было
                // видно, что он не безлимитный, и что он наследует срок кабинета.
                $expiry = '';
                if (!empty($iface['## time'])) {
                    $ts = strtotime((string) $iface['## time']);
                    if ($ts !== false) {
                        $expiry = ' · ' . $this->i18n('user portal expires') . ' ' . date('d.m.Y', $ts);
                    }
                }
                $data[] = [[
                    'text'          => ($already || $selected ? '✓ ' : '') . $label . $expiry . ($already ? ' · ' . $this->i18n('user portal wg pick bound') : ''),
                    'callback_data' => '/userPortalWgToggle ' . $index . ' ' . rawurlencode($canonicalKey) . '_' . $page,
                ]];
            }
        }

        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = [
                    'text'          => '←',
                    'callback_data' => '/userPortalWgPick ' . rawurlencode($canonicalKey) . '_' . ($page - 1),
                ];
            }
            if ($page < $pages - 1) {
                $nav[] = [
                    'text'          => '→',
                    'callback_data' => '/userPortalWgPick ' . rawurlencode($canonicalKey) . '_' . ($page + 1),
                ];
            }
            if (!empty($nav)) {
                $data[] = $nav;
            }
        }

        if ($selectedCount > 0) {
            $data[] = [[
                'text'          => $this->i18n('user portal wg send') . ' (' . $selectedCount . ')',
                'callback_data' => '/userPortalWgSend ' . rawurlencode($canonicalKey) . '_' . $page,
            ]];
        }
        $data[] = [[
            'text'          => $this->i18n('user portal wg pick add'),
            'callback_data' => '/userPortalWgAdd ' . rawurlencode($canonicalKey),
        ]];
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalCard ' . rawurlencode($canonicalKey . '_' . $this->userPortalBackPage('users')),
        ]];

        $this->userPortalShow(implode("\n", $text), $data ?: false);
    }

    /**
     * Выдать один WG-пир человеку: привязать (## owner_tg / ## owner_sub_id) и
     * сохранить. Конфиг сюда не доставляем — после выдачи человеку идёт одно
     * сводное уведомление, а конфиги он открывает из кабинета кнопкой
     * «Показать». Леждер и уведомление считает вызывающий. Возвращает подпись
     * выданного конфига или null, если пира нет.
     */
    private function issueWgPeerToUser(array &$clients, int $index, string $telegramId, string $subscriptionId): ?string
    {
        if (!isset($clients[$index]) || !is_array($clients[$index]['interface'] ?? null)) {
            return null;
        }
        // Амнезия/WG живёт независимо от VLESS-подписки: владелец пира — сам
        // человек (telegram id), а не подписка. ## owner_tg — первичный ключ
        // владения WG-конфигом; ## owner_sub_id оставляем только как «на этой
        // подписке тоже выдан» (совместимость с grant-меню, которое резолвит
        // владельца по подписке).
        $clients[$index]['interface']['## owner_tg'] = $telegramId;
        if ($subscriptionId !== '') {
            $clients[$index]['interface']['## owner_sub_id'] = $subscriptionId;
            // Привязать заявителя к кабинету: VLESS-выдача делает это в
            // userPortalCardSubIssue, а WG/Amnezia-выдача — нет, из-за чего
            // кабинет после выдачи Амнезии всё равно писал «нет подписки».
            $this->setUserPortalBinding($telegramId, $subscriptionId);
        }
        $this->saveClients($clients);

        // Единая сущность «времени»: если у кабинета задан срок, свежевыданный
        // Amnezia/WG-конфиг наследует его сразу (независимо от порядка выдачи —
        // VLESS до Амнезии или наоборот). Без срока конфиг остаётся безлимитным.
        $expiresAt = $this->getUserPortalExpiry($telegramId);
        if ($expiresAt > 0) {
            $this->setUserPortalWgTime($telegramId, $expiresAt);
        }

        // Конфиг + QR не шлём: после выдачи человеку идёт одно сводное
        // уведомление «Вам выдано N конфигов», а сам набор он открывает из
        // кабинета кнопкой «Показать».
        $peer = $clients[$index];
        $name = $this->getName($peer['interface']);
        return $name !== '' ? $name : ('WG ' . $index);
    }

    /**
     * Выдать конкретный WG-пир: привязать его к подписке (## owner_sub_id) и
     * доставить владельцу конфиг + QR из контейнера WG.
     */
    public function userPortalWgIssue(int $index, string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        // Кнопка выдачи несёт «<canonicalKey>_<page>», чтобы после выдачи вернуть
        // админа на ту же страницу списка пиров, а не сбрасывать на первую.
        $page = 0;
        if (preg_match('~^(.*)_(\d+)$~', $key, $m)) {
            $key  = $m[1];
            $page = (int) $m[2];
        }
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $telegramId = (string) ($row['telegram_id'] ?? '');
        $subscriptionId = (string) ($row['subscription_id'] ?? '');

        $clients = $this->readClients();
        if ($this->issueWgPeerToUser($clients, $index, $telegramId, $subscriptionId) === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal wg pick empty'));
            $this->userPortalCardWg($canonicalKey, $page);

            return;
        }

        if ($subscriptionId !== '') {
            $this->bumpUserPortalIssued($subscriptionId, 'wg');
        }
        // Одно сводное уведомление получателю вместо набора конфигов: человек
        // нажимает «Обновить» в кабинете и открывает конфиги кнопкой «Показать».
        $this->userPortalNotifyIssueToChat($telegramId, 'wg', 1);
        $this->notifyAdminIssue($this->userPortalWriteTargetName($telegramId), 'wg');
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal issue config ok'));
        $this->userPortalCardWg($canonicalKey, $page);
    }

    /**
     * Отметить/снять конфиг на странице мультивыбора. Уже выданные пропускаем.
     */
    public function userPortalWgToggle(int $index, string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $page = 0;
        if (preg_match('~^(.*)_(\d+)$~', $key, $m)) {
            $key  = $m[1];
            $page = (int) $m[2];
        }
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $telegramId = (string) ($row['telegram_id'] ?? '');
        $subscriptionId = (string) ($row['subscription_id'] ?? '');
        $ownerSubId = '';
        if ($subscriptionId !== '') {
            $owner = $this->resolveSubscriptionClient($subscriptionId);
            if ($owner !== null) {
                $ownerSubId = $owner['subscription_id'];
            }
        }

        $clients = $this->readClients();
        if (!isset($clients[$index]) || !is_array($clients[$index]['interface'] ?? null)) {
            $this->userPortalCardWg($canonicalKey, $page);

            return;
        }
        $iface = $clients[$index]['interface'];
        $already = (string) ($iface['## owner_tg'] ?? '') === $telegramId
            || ($ownerSubId !== '' && (string) ($iface['## owner_sub_id'] ?? '') === $ownerSubId);
        if ($already) {
            $this->userPortalCardWg($canonicalKey, $page);

            return;
        }

        $sel = $_SESSION['userPortalWgSelect'][(string) $canonicalKey] ?? [];
        if (isset($sel[(int) $index])) {
            unset($sel[(int) $index]);
        } else {
            $sel[(int) $index] = true;
        }
        $_SESSION['userPortalWgSelect'][(string) $canonicalKey] = $sel;

        $this->userPortalCardWg($canonicalKey, $page);
    }

    /**
     * Отправить все отмеченные конфиги выбранному человеку, остаться на той же
     * странице. Леждер начисляем один раз на всю пачку, уведомляем один раз.
     */
    public function userPortalWgSend(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $page = 0;
        if (preg_match('~^(.*)_(\d+)$~', $key, $m)) {
            $key  = $m[1];
            $page = (int) $m[2];
        }
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $telegramId = (string) ($row['telegram_id'] ?? '');
        $subscriptionId = (string) ($row['subscription_id'] ?? '');

        $sel = $_SESSION['userPortalWgSelect'][(string) $canonicalKey] ?? [];
        $clients = $this->readClients();
        $sent = 0;
        foreach (array_keys($sel) as $index) {
            if ($this->issueWgPeerToUser($clients, (int) $index, $telegramId, $subscriptionId) !== null) {
                $sent++;
            }
        }
        $_SESSION['userPortalWgSelect'][(string) $canonicalKey] = [];

        if ($sent > 0 && $subscriptionId !== '') {
            $this->bumpUserPortalIssued($subscriptionId, 'wg', $sent);
        }
        if ($sent > 0) {
            // Одно сводное уведомление получателю вместо набора конфигов.
            $this->userPortalNotifyIssueToChat($telegramId, 'wg', $sent);
            $this->notifyAdminIssue($this->userPortalWriteTargetName($telegramId), 'wg', $sent);
        }
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal issue config ok') . ' · ' . $sent);
        $this->userPortalCardWg($canonicalKey, $page);
    }

    /**
     * «Отозвать Amnezia/WG» — выбор выданных этому человеку конфигов
     * мультивыбором (по принципу выдачи: userPortalCardWg), затем отзыв пачкой.
     */
    public function userPortalWgRevokePick(string $key, int $page = -1)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $telegramId = (string) ($row['telegram_id'] ?? '');

        // Только выданные этому человеку конфиги, в порядке clients1.json.
        $issued = $this->userPortalIssuedWgClientsByTg($telegramId);
        $total = count($issued);
        $perPage = max(1, $this->limit ?: 5);
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page < 0) {
            $page = $this->userPortalBackPage('wgrevoke');
        }
        $page = max(0, min($page, $pages - 1));
        $this->userPortalSetBackPage(['wgrevoke' => $page]);
        $slice = array_slice($issued, $page * $perPage, $perPage, true);

        $select = $_SESSION['userPortalWgRevokeSelect'][(string) $canonicalKey] ?? [];
        $selectedCount = count($select);

        $text = [$this->i18n('user portal wg revoke pick title') . ':'];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = $this->i18n('user portal wg revoke select hint');
        $text[] = '';

        $data = [];
        if ($total === 0) {
            $text[] = $this->i18n('user portal wg revoke empty');
        } else {
            foreach ($slice as $item) {
                $index = (int) $item['index'];
                $name  = $item['name'];
                $label = $name !== '' ? $name : ('WG ' . $index);
                $selected = isset($select[$index]);
                $data[] = [[
                    'text'          => ($selected ? '✓ ' : '') . $label,
                    'callback_data' => '/userPortalWgRevokeToggle ' . $index . ' ' . rawurlencode($canonicalKey) . '_' . $page,
                ]];
            }
        }

        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = [
                    'text'          => '←',
                    'callback_data' => '/userPortalWgRevokePick ' . rawurlencode($canonicalKey) . '_' . ($page - 1),
                ];
            }
            if ($page < $pages - 1) {
                $nav[] = [
                    'text'          => '→',
                    'callback_data' => '/userPortalWgRevokePick ' . rawurlencode($canonicalKey) . '_' . ($page + 1),
                ];
            }
            if (!empty($nav)) {
                $data[] = $nav;
            }
        }

        if ($selectedCount > 0) {
            $data[] = [[
                'text'          => $this->i18n('user portal wg revoke send') . ' (' . $selectedCount . ')',
                'callback_data' => '/userPortalWgRevokeSend ' . rawurlencode($canonicalKey) . '_' . $page,
            ]];
        }
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalCardRevoke ' . rawurlencode($canonicalKey),
        ]];

        $this->userPortalShow(implode("\n", $text), $data ?: false);
    }

    /**
     * Отметить/снять выданный конфиг на странице мультивыбора отзыва.
     */
    public function userPortalWgRevokeToggle(int $index, string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $page = 0;
        if (preg_match('~^(.*)_(\d+)$~', $key, $m)) {
            $key  = $m[1];
            $page = (int) $m[2];
        }
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey] = $resolved;

        $sel = $_SESSION['userPortalWgRevokeSelect'][(string) $canonicalKey] ?? [];
        if (isset($sel[$index])) {
            unset($sel[$index]);
        } else {
            $sel[$index] = true;
        }
        $_SESSION['userPortalWgRevokeSelect'][(string) $canonicalKey] = $sel;

        $this->userPortalWgRevokePick($canonicalKey, $page);
    }

    /**
     * Отозвать все отмеченные конфиги пачкой: удалить пиры из clients1.json и
     * из wg-конфига за один проход, перезапустить WG один раз, уведомить
     * владельца одним сообщением.
     */
    public function userPortalWgRevokeSend(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $page = 0;
        if (preg_match('~^(.*)_(\d+)$~', $key, $m)) {
            $key  = $m[1];
            $page = (int) $m[2];
        }
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $telegramId = (string) ($row['telegram_id'] ?? '');

        $sel = $_SESSION['userPortalWgRevokeSelect'][(string) $canonicalKey] ?? [];
        if (empty($sel)) {
            $this->userPortalWgRevokePick($canonicalKey, $page);

            return;
        }

        $clients = $this->readClients();
        $conf    = $this->readConfig();
        $toRemove = [];
        $labels   = [];
        foreach (array_keys($sel) as $idx) {
            $idx = (int) $idx;
            if (!isset($clients[$idx]) || !is_array($clients[$idx]['interface'] ?? null)) {
                continue;
            }
            $name = $this->getName($clients[$idx]['interface']);
            $labels[] = $name !== '' ? $name : ('WG ' . $idx);
            $toRemove[] = $idx;
        }
        if (empty($toRemove)) {
            $_SESSION['userPortalWgRevokeSelect'][(string) $canonicalKey] = [];
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal wg revoke empty'));
            $this->userPortalWgRevokePick($canonicalKey, $page);

            return;
        }

        // Удалить пиры из wg-конфига и перезапустить WG один раз.
        foreach ($toRemove as $idx) {
            if (isset($conf['peers'][$idx])) {
                unset($conf['peers'][$idx]);
            }
        }
        // Удалить клиентов из clients1.json и переиндексировать один раз.
        foreach ($toRemove as $idx) {
            unset($clients[$idx]);
        }
        $this->saveClients(array_values($clients));
        $this->restartWG($this->createConfig($conf));

        $_SESSION['userPortalWgRevokeSelect'][(string) $canonicalKey] = [];

        if ($telegramId !== '') {
            $this->send(
                $telegramId,
                $this->i18n('user portal revoked wg msg') . ' ' . implode(', ', $labels),
            );
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal revoke ok') . ' · ' . count($labels));
        $this->userPortalWgRevokePick($canonicalKey, $page);
    }

    /**
     * Добавить новый WG-пир: первый шаг — выбор режима маршрутизации, как в
     * самой AmneziaWG: «весь трафик» (AllowedIPs 0.0.0.0/0, требует имя клиента)
     * либо «подсеть» (split-tunnel — только сеть точки доступа). Дальше
     * userPortalWgAddMode() и, для всего трафика, userPortalWgAddName().
     */
    public function userPortalWgAdd(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey] = $resolved;

        $text = $this->i18n('user portal wg add mode');
        $data = [
            [[
                'text'          => $this->i18n('all traffic'),
                'callback_data' => '/userPortalWgAddMode full ' . rawurlencode($canonicalKey),
            ]],
            [[
                'text'          => $this->i18n('subnet'),
                'callback_data' => '/userPortalWgAddMode subnet ' . rawurlencode($canonicalKey),
            ]],
            [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalCardWg ' . rawurlencode($canonicalKey),
            ]],
        ];
        $this->userPortalShow($text, $data);
    }

    /**
     * Второй шаг «Добавить новый конфиг»: выбран режим маршрутизации.
     * «подсеть» создаёт профиль сразу (split-tunnel); «весь трафик» просит имя.
     */
    public function userPortalWgAddMode(string $mode, string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        if ($mode === 'full') {
            $this->userPortalPromptInput(
                $this->i18n('user portal wg name prompt'),
                'userPortalWgAddName',
                [rawurlencode($key)],
            );

            return;
        }
        // «подсеть» — split-tunnel: только адрес точки доступа, без полного трафика.
        $this->userPortalWgAddCommit($key, '', false);
    }

    /**
     * Ввод имени для режима «весь трафик» (диспетчер текстового ввода).
     */
    public function userPortalWgAddName($text, string $key)
    {
        if (!$this->admin) {
            return;
        }
        $name = trim((string) $text);
        $this->userPortalWgAddCommit($key, $name, true);
    }

    /**
     * Самообслуживание: юзер жмёт «Добавить конфиг» в кабинете и вводит имя —
     * конфиг (полный тоннель) сразу его. Лимит — сумма выданных админом + своих,
     * срезается в getUserPortalWgLimit. Это НЕ админ-флоу userPortalWgAdd (тот
     * с выбором маршрутизации и мультивыбором на выдачу).
     */
    public function userPortalWgAddSelf()
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }
        $tgId = (string) ($session['telegram_id'] ?? '');
        $limit = $this->getUserPortalWgLimit($tgId);
        $total = count($this->userPortalIssuedWgClientsByTg($tgId));
        if ($total >= $limit) {
            $this->ackCallback(sprintf($this->i18n('user portal wg add self limit reached'), $total, $limit), true);

            return;
        }
        $this->userPortalPromptInput(
            $this->i18n('user portal wg add self name prompt'),
            'userPortalWgAddSelfName',
            [],
        );
    }

    /**
     * Ввод имени своего конфига (диспетчер текстового ввода, не-админ).
     */
    public function userPortalWgAddSelfName($text)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            return;
        }
        $tgId = (string) ($session['telegram_id'] ?? '');
        $subscriptionId = (string) ($session['subscription_id'] ?? '');
        $name = trim((string) $text);

        $limit = $this->getUserPortalWgLimit($tgId);
        $before = count($this->userPortalIssuedWgClientsByTg($tgId));
        if ($before >= $limit) {
            $this->userPortalSetFlash('⚠️ ' . sprintf($this->i18n('user portal wg add self limit reached'), $before, $limit));
            $this->userPortalMenu();

            return;
        }

        $ownerSubId = $subscriptionId !== '' ? $subscriptionId : ('tg-' . $tgId);
        $created = $this->runInRuntimeWgContext(fn () => $this->issueNewWgProfile($ownerSubId, $name, true));
        if ($created === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal wg empty'));
            $this->userPortalMenu();

            return;
        }

        // Новый конфиг дописан последним в clients1.json — привязываем его к
        // владельцу и сохраняем. issueWgPeerToUser уже зовёт saveClients.
        $clients = $this->readClients();
        $newIndex = count($clients) - 1;
        if ($this->issueWgPeerToUser($clients, $newIndex, $tgId, $subscriptionId) === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal wg empty'));
            $this->userPortalMenu();

            return;
        }

        if ($subscriptionId !== '') {
            $this->bumpUserPortalIssued($subscriptionId, 'wg');
        }
        $total = count($this->userPortalIssuedWgClientsByTg($tgId));
        $left  = max(0, $limit - $total);
        $this->notifyAdminSelfAdd($this->userPortalWriteTargetName($tgId), $name, $total, $left);
        $this->userPortalSetFlash('✅ ' . sprintf($this->i18n('user portal wg add self done'), $name));
        $this->userPortalMenu();
    }

    /**
     * Пуш остальным админам «юзер сам добавил себе конфиг». Формат-шаблон в
     * i18n: «Имя: себе добавлен конфиг "имя" — всего N, осталось M».
     */
    protected function notifyAdminSelfAdd(string $who, string $name, int $total, int $left): void
    {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            return;
        }
        $c = [];
        require $configFile;
        $admins = isset($c['admin']) && is_array($c['admin']) ? $c['admin'] : [];
        $silent = isset($c['admin_silent']) && is_array($c['admin_silent']) ? $c['admin_silent'] : [];
        if ($silent !== []) {
            $silentMap = array_map('strval', $silent);
            $admins = array_values(array_filter($admins, static function ($id) use ($silentMap) {
                return !in_array((string) $id, $silentMap, true);
            }));
        }
        if ($admins === []) {
            return;
        }
        $actingId = (string) ($this->input['from'] ?? '');
        $text = '🔔 ' . sprintf($this->i18n('user portal wg add self admin notify'), $who, $name, $total, $left);
        foreach ($admins as $adminId) {
            $adminId = (string) $adminId;
            if ($adminId === '' || $adminId === $actingId) {
                continue;
            }
            try {
                $this->send($adminId, $text);
            } catch (\Throwable $e) {
                // Неудачная рассылка одному админу не должна ломать портал.
            }
        }
    }

    /**
     * Кнопка «Лимит Amnezia» в карточке пользователя: админ задаёт per-user
     * лимит конфигов Amnezia/WG. 0 = «как у всех» (глобальный дефолт).
     */
    public function userPortalWgLimit(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $tgId = (string) ($row['telegram_id'] ?? '');
        $current = $this->getUserPortalWgLimit($tgId);
        $this->userPortalPromptInput(
            $this->i18n('user portal wg limit prompt') . "\n" . $this->i18n('user portal wg limit') . ': ' . $current,
            'userPortalWgLimitSave',
            [rawurlencode($canonicalKey)],
        );
    }

    /**
     * Сохранение per-user лимита (диспетчер текстового ввода, админ).
     */
    public function userPortalWgLimitSave($text, string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $tgId = (string) ($row['telegram_id'] ?? '');
        $limit = (int) trim((string) $text);
        if ($limit < 0) {
            $limit = 0;
        }
        $this->setUserPortalWgLimit($tgId, $limit);
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal wg limit') . ': ' . $this->getUserPortalWgLimit($tgId));
        $this->userPortalCard($canonicalKey);
    }

    /**
     * Кнопка «Время» в карточке пользователя: админ задаёт срок жизни кабинета.
     * Единый срок пишется параллельно в X-Ray (VLESS) и все Amnezia/WG-конфиги.
     */
    public function userPortalExpiry(string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $tgId = (string) ($row['telegram_id'] ?? '');
        $current = $this->getUserPortalExpiry($tgId);
        $this->userPortalPromptInput(
            $this->i18n('user portal expiry prompt') . "\n" . $this->i18n('user portal expires') . ': ' . $this->userPortalExpiryText($current),
            'userPortalExpirySave',
            [rawurlencode($canonicalKey)],
        );
    }

    /**
     * Сохранение срока кабинета (диспетчер текстового ввода, админ).
     */
    public function userPortalExpirySave($text, string $key)
    {
        if (!$this->admin) {
            return;
        }
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $tgId = (string) ($row['telegram_id'] ?? '');
        $parsed = $this->parseUserPortalExpiry((string) $text);
        if ($parsed === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal expiry bad format'));
            $this->userPortalExpiry($canonicalKey);

            return;
        }
        $this->setUserPortalExpiry($tgId, $parsed);
        $this->notifyUserPortalExpirySet($tgId, $parsed);
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal expires') . ': ' . $this->userPortalExpiryText($this->getUserPortalExpiry($tgId)));
        $this->userPortalCard($canonicalKey);
    }

    /**
     * Глобальный (общий) лимит WG-конфигов из настроек админа. Промпт числа.
     */
    public function userPortalWgGlobalLimit()
    {
        if (!$this->admin) {
            return;
        }
        $current = $this->getUserPortalWgLimit('');
        $this->userPortalPromptInput(
            $this->i18n('user portal wg limit global prompt') . "\n" . $this->i18n('user portal wg limit global') . ': ' . $current,
            'userPortalWgGlobalLimitSave',
            [],
        );
    }

    /**
     * Сохранение глобального лимита в pac.json (диспетчер текстового ввода, админ).
     */
    public function userPortalWgGlobalLimitSave($text)
    {
        if (!$this->admin) {
            return;
        }
        $limit = (int) trim((string) $text);
        if ($limit < 0) {
            $limit = 0;
        }
        $pac = $this->getPacConf();
        $pac['user_portal_wg_limit'] = $limit;
        $this->setPacConf($pac);
        $this->menu('config');
    }

    /**
     * Финальный шаг: создать WG-пир с выбранным именем и режимом, выдать и
     * вернуться к карточке выдачи.
     */
    protected function userPortalWgAddCommit(string $key, string $name, bool $fullTunnel)
    {
        $key = rawurldecode($key);
        $resolved = $this->resolveUserPortalCardRow($key);
        if ($resolved === null || (string) ($resolved[1]['telegram_id'] ?? '') === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalUsers();

            return;
        }
        [$canonicalKey, $row] = $resolved;
        $telegramId = (string) ($row['telegram_id'] ?? '');
        $subscriptionId = (string) ($row['subscription_id'] ?? '');
        $ownerSubId = $subscriptionId !== '' ? $subscriptionId : ('tg-' . $telegramId);

        $created = $this->runInRuntimeWgContext(fn () => $this->issueNewWgProfile($ownerSubId, $name, $fullTunnel));
        if ($created === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal wg empty'));
            $this->userPortalCardWg($canonicalKey);

            return;
        }

        // Конфиг создаётся НЕ выданным: он становится кандидатом на выдачу —
        // отмечаем его в мультивыборе, чтобы админ отправил кнопкой «Отправить N».
        // Сама выдача (привязка владельца + уведомление) — в userPortalWgSend.
        $newIndex = count($this->readClients()) - 1;
        $_SESSION['userPortalWgSelect'][(string) $canonicalKey][(int) $newIndex] = true;
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal wg config created'));

        // Новый конфиг дописывается в конец clients1.json — показываем последнюю
        // страницу, где он лежит отмеченным галочкой.
        $peers    = $this->readClients();
        $perPage  = max(1, $this->limit ?: 5);
        $lastPage = max(0, (int) ceil(count($peers) / $perPage) - 1);
        $this->userPortalCardWg($canonicalKey, $lastPage);
    }

    /**
     * Создать свежий WG-пир подписки без привязки к устройству: ключи, IP,
     * server-peer, запись в clients1.json и перезапуск WG. Возвращает новый
     * clients-элемент (для createConfig/QR) или null при сбое.
     */
    protected function issueNewWgProfile(string $ownerSubId, string $name = '', bool $fullTunnel = true): ?array
    {
        if ($ownerSubId === '') {
            return null;
        }
        $clients = $this->readClients();
        $server = $this->readConfig();
        if (!is_array($server) || empty($server['interface']['PrivateKey'])) {
            return null;
        }
        if (!isset($server['peers']) || !is_array($server['peers'])) {
            $server['peers'] = [];
        }

        $clientIp = $this->allocateWgClientIp($server, $clients);
        if ($clientIp === null) {
            return null;
        }
        $publicServerKey = trim($this->ssh("printf '%s' {$this->wgShellQuote($server['interface']['PrivateKey'])} | {$this->getWGType()} pubkey", $this->getInstanceWG()));
        $privatePeerKey = trim($this->ssh("{$this->getWGType()} genkey", $this->getInstanceWG()));
        $publicPeerKey = $this->wgPublicKeyFromPrivate($privatePeerKey);
        if ($privatePeerKey === '' || $publicPeerKey === '' || $publicServerKey === '') {
            return null;
        }

        $n = count($clients) + 1;
        $autoName = 'sub-' . substr(hash('sha1', $ownerSubId), 0, 6) . '-' . $n;
        $name = trim($name) !== '' ? trim($name) : $autoName;
        $endpointCustom = $this->getRuntimeDeviceWgEndpoint();

        // Split-tunnel («подсеть»): клиент маршрутизирует только сеть точки
        // доступа + интерфейсный адрес, а не весь трафик. Полный тоннель
        // («весь трафик») — классический 0.0.0.0/0.
        $clientRouteIps = $fullTunnel ? '0.0.0.0/0' : "$clientIp/32";

        $serverPeer = [
            '## name'         => $name,
            'PublicKey'       => $publicPeerKey,
            'AllowedIPs'      => "$clientIp/32",
        ];
        $clientPeer = [
            'PublicKey'           => $publicServerKey,
            'AllowedIPs'          => $clientRouteIps,
            'PersistentKeepalive' => 20,
        ];
        if (!empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia'])) {
            $psk = $this->presharedKey();
            $serverPeer['PresharedKey'] = $psk;
            $clientPeer['PresharedKey'] = $psk;
        }
        $server['peers'][] = $serverPeer;

        $interface = [
            '## name'         => $name,
            '## endpoint_custom' => $endpointCustom,
            'PrivateKey'      => $privatePeerKey,
            'Address'         => "$clientIp/32",
        ];
        if (!empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia'])) {
            $interface['MTU'] = (string) $this->getAwgClientMtu();
        }
        $clientConf = [
            'interface' => array_merge(
                $interface,
                !empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia']) ? $this->amneziaKeys() : []
            ),
            'peers' => [$clientPeer],
        ];
        $clients[] = $clientConf;

        $this->saveClients($clients);
        if (!$this->restartWG($this->createConfig($server))) {
            error_log("issueNewWgProfile: restartWG failed for $ownerSubId");
        }

        return $clientConf;
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
                $this->sendPhoto((int) $chatId, curl_file_create($this->portalConfigQrFile($payload)), $this->portalConfigCaption($kind, $label, $payload));
                if ($kind === 'wg') {
                    $this->send((int) $chatId, $this->i18n('user portal amnezia instructions'));
                }
            } catch (\Throwable $e) {
                // Не фатально: доставка конфига не должна ломать операцию.
            }
        }
    }

    /**
     * Уведомить человека, что ему выдали конфиг: какой протокол и сколько.
     * Идёт в тот же (те) чат(ы), что и сам конфиг, по привязке subscription_id.
     */
    protected function userPortalNotifyIssue(string $subscriptionId, string $kind, int $count): void
    {
        if ($count < 1) {
            return;
        }
        $telegramIds = array_unique($this->getUserPortalBindingTelegramIds($subscriptionId));
        if (empty($telegramIds)) {
            return;
        }
        $proto = $kind === 'wg' ? 'Amnezia/WG' : 'VLESS';
        $text = sprintf(
            '🔔 %s: %s (%d)',
            $this->i18n('user portal issued notify'),
            $proto,
            $count
        );
        foreach ($telegramIds as $chatId) {
            if ($chatId === '') {
                continue;
            }
            try {
                $this->send((int) $chatId, $text);
            } catch (\Throwable $e) {
                // Не фатально.
            }
        }
    }

    /**
     * Сводное уведомление об одной выдаче прямо в чат получателя (telegram id),
     * без резолва по подписке: WG-выдача привязана к человеку, а не к VLESS-
     * подписке. Один текст вместо набора конфигов — «Вам выдано N, нажмите
     * „Обновить“».
     */
    protected function userPortalNotifyIssueToChat(string $telegramId, string $kind, int $count): void
    {
        if ($telegramId === '' || $count < 1) {
            return;
        }
        $proto = $kind === 'wg' ? 'Amnezia/WG' : 'VLESS';
        $text = sprintf(
            '🔔 %s: %s (%d) — %s',
            $this->i18n('user portal issued notify'),
            $proto,
            $count,
            $this->i18n('user portal issued notify refresh')
        );
        try {
            $this->send((int) $telegramId, $text);
        } catch (\Throwable $e) {
            // Не фатально.
        }
    }

    /**
     * Доставить «голый» WG-конфиг текстом (для не-AWG типа, где нет Amnezia-
     * ссылки) во все привязанные к подписке чаты.
     */
    protected function deliverPortalConfigText(string $subscriptionId, string $conf, string $label): void
    {
        $telegramIds = array_unique($this->getUserPortalBindingTelegramIds($subscriptionId));
        if (empty($telegramIds)) {
            return;
        }
        $text = '<b>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b> — WG' . "\n" . '<pre><code>' . htmlspecialchars($conf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        foreach ($telegramIds as $chatId) {
            if ($chatId === '') {
                continue;
            }
            try {
                $this->send((int) $chatId, $text);
            } catch (\Throwable $e) {
                // Не фатально.
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

        $this->userPortalGrantPick($telegramId, 0);
    }

    /**
     * Список активных VLESS-подписок на выбор — вместо ручного ввода номера.
     * Подписка = xray-клиент-владелец без device_parent_id и не off.
     */
    protected function userPortalSubscriptionChoices(): array
    {
        $choices = [];
        foreach ($this->forEachXrayClient($this->getXray()) as $entry) {
            $v = $entry['client'];
            if (!empty($v['device_parent_id']) || !empty($v['off'])) {
                continue;
            }
            $subId = $this->getClientSubscriptionId($v);
            if ($subId === '') {
                continue;
            }
            $label = trim((string) ($v['email'] ?? ''));
            // email вида «Имя#dev-token» — показываем только имя (часть до #),
            // иначе в списке выдачи подписки вместо людей сырые ключи.
            $hash = strpos($label, '#');
            if ($hash !== false) {
                $label = trim(substr($label, 0, $hash));
            }
            if ($label === '') {
                $label = $subId;
            }
            $choices[$entry['index']] = ['index' => $entry['index'], 'subId' => $subId, 'label' => $label];
        }

        return $choices;
    }

    public function userPortalGrantPick($telegramId, $page)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim((string) $telegramId);
        $page = (int) $page;
        $choices = $this->userPortalSubscriptionChoices();
        $text = [];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text[] = $this->i18n('user portal pick sub') . ' (TG <code>' . htmlspecialchars($telegramId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>)';
        if (empty($choices)) {
            $text[] = $this->i18n('user portal no subs');
            $buttons = [[
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => '/userPortalUsers',
                ],
            ]];
            $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $buttons);

            return;
        }

        $perPage = 8;
        $keys = array_keys($choices);
        $pages = (int) max(1, ceil(count($keys) / $perPage));
        $page = max(0, min($page, $pages - 1));
        $slice = array_slice($keys, $page * $perPage, $perPage, true);

        $buttons = [];
        foreach ($slice as $idx) {
            $c = $choices[$idx];
            $buttons[] = [[
                'text'          => $c['label'],
                'callback_data' => '/userPortalGrantPickSave ' . $telegramId . ' ' . $idx,
            ]];
        }
        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = [
                    'text'          => '←',
                    'callback_data' => '/userPortalGrantPick ' . $telegramId . ' ' . ($page - 1),
                ];
            }
            if ($page < $pages - 1) {
                $nav[] = [
                    'text'          => '→',
                    'callback_data' => '/userPortalGrantPick ' . $telegramId . ' ' . ($page + 1),
                ];
            }
            if (!empty($nav)) {
                $buttons[] = $nav;
            }
        }
        $buttons[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortalUsers',
        ]];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $buttons);
    }

    public function userPortalGrantPickSave($telegramId, $i)
    {
        if (!$this->admin) {
            return;
        }
        $telegramId = trim((string) $telegramId);
        $i = (int) $i;
        $c = $this->findXrayClientByIndex($this->getXray(), $i);
        if ($c === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal sub not found'));
            $this->userPortalGrantPick($telegramId, 0);

            return;
        }
        $subscriptionId = $this->getClientSubscriptionId($c);
        $this->setUserPortalBinding($telegramId, $subscriptionId);
        $resolved = $this->resolveSubscriptionClient($subscriptionId);
        if ($resolved !== null) {
            $this->notifySubscriptionUsers($resolved['client'], 'appeared');
        }
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal grant ok'));
        $this->userPortalUsers();
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
