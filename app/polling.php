<?php

// Long-polling (getUpdates) fallback for the bot.
//
// Webhook is the primary delivery path. When the webhook endpoint is
// unreachable (DNS/nginx/cert failure) Telegram keeps sending updates to the
// webhook and the bot goes silent. This script is the fallback: it pulls
// updates via getUpdates instead, so the bot keeps answering as long as the
// Telegram API itself is reachable.
//
// Switch into polling mode by setting `polling_mode => 1` in pac.json, then
// run this script inside the php container (see docs/POLIING.md).
// `init.php` notices the flag and calls deleteWebhook() instead of
// setwebhook(), so the two modes never fight over the update stream.

require __DIR__ . '/timezone.php';
require __DIR__ . '/bot.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';
if (!empty($c['debug'] ?? false) || vpnbot_requests_logging_enabled()) {
    require __DIR__ . '/debug.php';
}

const POLLING_OFFSET_FILE = '/config/polling_offset';

function polling_read_offset(): int
{
    $v = @file_get_contents(POLLING_OFFSET_FILE);
    $v = trim((string) $v);
    return is_numeric($v) ? (int) $v : 0;
}

function polling_write_offset(int $offset): void
{
    @file_put_contents(POLLING_OFFSET_FILE, (string) $offset);
}

$bot = new Bot($c['key'], $i);

// One update may legitimately end in exit()/die() (export/download handlers),
// which would kill this loop. The offset is persisted BEFORE processing so a
// restart never re-delivers the update that caused the exit. A supervisor
// (docker restart policy / superlisa-svc / systemd) brings the loop back.
$offset = polling_read_offset();

while (true) {
    try {
        $updates = $bot->request('getUpdates', [
            'offset'          => $offset,
            'timeout'         => 15,
            'allowed_updates' => json_encode([
                'message',
                'callback_query',
                'inline_query',
                'my_chat_member',
                'channel_post',
            ]),
        ]);

        if (is_array($updates) && !empty($updates['ok']) && !empty($updates['result'])) {
            foreach ($updates['result'] as $update) {
                $id = $update['update_id'] ?? null;
                if (!is_numeric($id)) {
                    continue;
                }
                $offset = (int) $id + 1;
                polling_write_offset($offset);
                $bot->input($update);
            }
        }
    } catch (Throwable $e) {
        // Network error / API hiccup — brief backoff, keep pulling.
        sleep(2);
    }

    usleep(300000);
}
