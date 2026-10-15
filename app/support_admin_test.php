<?php
// Unit test for the admin /support view. send()/update() are stubbed so
// nothing reaches Telegram; the ticket store is redirected to a temp file.
// Verifies that /support renders ticket CARDS (not a write prompt), sorts
// newest first, and that /supportWrite still opens the write flow.
$_SESSION = [];
require '/app/timezone.php';
require '/app/bot.php';
require '/app/config.php';
require '/app/i18n.php';

class SupportAdminTestBot extends Bot
{
    public array $sent = [];
    public string $store = '/tmp/support-admin-test.json';

    public function send($chat, $text, ?int $to = 0, $button = false, $reply = false, $mode = 'HTML', $disable_notification = false)
    {
        $this->sent[] = ['chat' => (string) $chat, 'text' => $text, 'button' => $button, 'reply' => $reply];

        return ['ok' => true, 'result' => ['message_id' => count($this->sent)]];
    }

    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML')
    {
        $this->sent[] = ['chat' => (string) $chat, 'text' => $text, 'button' => $button, 'edit' => true];

        return ['ok' => true];
    }

    protected function supportStorePath(): string
    {
        return $this->store;
    }

    public function seed(array $tickets): void
    {
        file_put_contents($this->store, json_encode($tickets, JSON_UNESCAPED_UNICODE));
    }

    public function runSupport(): void
    {
        $this->input = ['chat' => '1', 'from' => '1', 'message_id' => 42];
        $this->support();
    }

    public function runWrite(): void
    {
        $this->input = ['chat' => '1', 'from' => '1', 'message_id' => 42, 'callback_id' => '7'];
        $this->supportWrite();
    }

    public function buttons(): array
    {
        $out = [];
        foreach ($this->sent as $s) {
            if (!empty($s['button'])) {
                foreach ($s['button'] as $row) {
                    foreach ($row as $btn) {
                        $out[] = $btn['callback_data'] ?? '';
                    }
                }
            }
        }

        return $out;
    }
}

function eq(string $label, $got, $expect): void
{
    if ($got !== $expect) {
        echo "FAIL: $label\n  got:    " . var_export($got, true) . "\n  expect: " . var_export($expect, true) . "\n";
        exit(1);
    }
    echo "ok: $label\n";
}

@unlink('/tmp/support-admin-test.json');
$bot = new SupportAdminTestBot($c['key'], $i);

// 1. Empty store -> empty notice + write/back buttons, no reply buttons.
$bot->seed([]);
$bot->runSupport();
eq('empty: single update', count($bot->sent), 1);
eq('empty: shows empty text', $bot->sent[0]['text'], $bot->i18n('support empty'));
eq('empty: write + back buttons', $bot->buttons(), ['/supportWrite', '/menu']);

// 2. Two tickets -> cards, newest first, reply buttons per ticket.
$bot->sent = [];
$bot->seed([
    'sub:abc' => ['subscription_id' => 'abc', 'updated_at' => 100, 'messages' => [['from' => 'user', 'text' => 'старый', 'at' => 100]]],
    'tg:555'  => ['telegram_id' => '555', 'updated_at' => 200, 'messages' => [['from' => 'user', 'text' => 'не подключается', 'at' => 200]]],
]);
$bot->runSupport();
$text    = $bot->sent[0]['text'];
$buttons = $bot->buttons();
eq('cards: title with count', str_contains($text, $bot->i18n('support tickets') . ' (2)'), true);
eq('cards: newest first', strpos($text, 'tg:555') < strpos($text, 'sub:abc'), true);
eq('cards: tg key shown', str_contains($text, 'tg:555'), true);
eq('cards: sub key shown', str_contains($text, 'sub:abc'), true);
eq('cards: last message preview', str_contains($text, 'не подключается'), true);
eq('cards: reply buttons per ticket', in_array('/supportReply tg:555', $buttons, true) && in_array('/supportReply sub:abc', $buttons, true), true);
eq('cards: write + back present', in_array('/supportWrite', $buttons, true) && in_array('/menu', $buttons, true), true);

// 3. /supportWrite still opens the write flow.
$bot->sent = [];
$bot->runWrite();
eq('write: sends prompt', str_contains($bot->sent[0]['text'], $bot->i18n('support prompt')), true);
eq('write: force_reply armed', $bot->sent[0]['reply'], $bot->i18n('support placeholder'));
$replyKey = array_key_last($_SESSION['reply'] ?? []);
eq('write: callback is supportSave', ($_SESSION['reply'][$replyKey]['callback'] ?? null), 'supportSave');

@unlink('/tmp/support-admin-test.json');
echo "ALL PASS\n";
