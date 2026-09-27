<?php

/**
 * Юнит-тест: портал при «Update и тишина» отдаёт тост, но НЕ задваивает меню.
 *
 * Регрессия: userPortalShow() при editMessageText с неизменным контентом
 * получал 400 «message is not modified». Ветка isMessageNotModified() молча
 * сохраняла id и выходила — пользователь не видел никакой реакции на клик
 * (бот жив, answerCallbackQuery на клик уже ушёл раньше, но «Update» выглядел
 * как тишина). Фикс: в этой ветке однократно показываем тост
 * («уже актуально»), не отправляя повторный send().
 *
 * Инварианты:
 *  1. на «not modified» НЕ вызывается send() (меню не задваивается);
 *  2. answer() вызван ровно один раз с текстом тоста;
 *  3. тост уходит именно в callback_id текущего клика;
 *  4. session message_id не сбрасывается (setUserPortalUiMessageId сохранил).
 *
 * Запуск: php tests/test_userportal_not_modified_toast.php
 */

declare(strict_types=1);

$base = is_dir(__DIR__ . '/../app/traits') ? __DIR__ . '/../app' : __DIR__ . '/..';
require $base . '/traits/UserPortalTrait.php';

function check(string $name, bool $ok): void
{
    global $fails, $total;
    $total++;
    echo $ok ? "ok    {$name}\n" : "FAIL  {$name}\n";
    if (!$ok) {
        $fails++;
    }
}

$fails = 0;
$total = 0;

final class UserPortalToastHarness
{
    use UserPortalTrait;

    public array $input = ['chat' => 809797644, 'callback_id' => 'cb-42'];
    public int $answers = 0;
    public string $toastText = '';
    public $toastCallbackId = null;
    public int $sends = 0;
    public string $sentText = '';
    public int $updates = 0;
    public int $savedMessageId = 0;

    protected function isMessageNotModified($r): bool
    {
        return !empty($r) && empty($r['ok'])
            && stripos((string) ($r['description'] ?? ''), 'not modified') !== false;
    }

    protected function resolveUserPortalMessageId(): int
    {
        return 123456;
    }

    protected function setUserPortalUiMessageId(int $messageId): void
    {
        $this->savedMessageId = $messageId;
    }

    protected function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML')
    {
        $this->updates++;

        return ['ok' => false, 'description' => 'Bad Request: message is not modified'];
    }

    protected function send($chat, $text, $to = 0, $button = false, $reply = false, $mode = 'HTML', $disable_notification = false)
    {
        $this->sends++;
        $this->sentText = (string) $text;

        return [];
    }

    protected function answer($callback_id, $textNotify = false, $notify = false)
    {
        $this->answers++;
        $this->toastCallbackId = $callback_id;
        $this->toastText = (string) $textNotify;

        return true;
    }

    protected function i18n(string $menu): string
    {
        return $menu === 'user portal up to date' ? 'уже актуально' : $menu;
    }

    public function run(): void
    {
        $this->userPortalShow('меню', false);
    }
}

$h = new UserPortalToastHarness();
$h->run();

check('not-modified: send() НЕ вызван (меню не задвоено)', $h->sends === 0);
check('not-modified: answer() вызван ровно 1 раз', $h->answers === 1);
check('not-modified: текст тоста = «уже актуально»', $h->toastText === 'уже актуально');
check('not-modified: тост ушёл в callback_id клика', $h->toastCallbackId === 'cb-42');
check('not-modified: message_id сохранён', ($h->savedMessageId ?? 0) === 123456);
check('not-modified: обновление шло через update(), не send()', $h->updates === 1);

// Контроль: без callback_id и без сообщения (чистый callback без id) — ни тоста,
// ни send(), но id всё равно сохраняется.
$h2 = new UserPortalToastHarness();
$h2->input = ['chat' => 809797644];
$h2->run();
check('без callback_id: answer() НЕ вызван', $h2->answers === 0);
check('без callback_id: send() НЕ вызван', $h2->sends === 0);
check('без callback_id: message_id сохранён', ($h2->savedMessageId ?? 0) === 123456);

// Команда-сообщение /update должна сбрасывать сохранённый message_id, чтобы
// меню перерендерилось свежим сообщением (с кнопками), а не эдитилось в старое
// и не уходило в «not modified». Проверяем на уровне userPortalMenu().
final class UserPortalMenuHarness
{
    use UserPortalTrait;

    public array $input = [];
    public array $session = [];

    protected function ensureUserPortalSession(): void
    {
        // $_SESSION уже инициализирован ниже до вызова.
    }

    protected function getUserPortalSession(): ?array
    {
        return null;
    }

    protected function getUserPortalNoAccessText(): string
    {
        return 'no access';
    }

    protected function userPortalShow(string $text, $buttons = false, $replyPlaceholder = false, ?array $replyState = null): void
    {
        $this->rendered = $text;
    }

    public string $rendered = '';

    protected function i18n(string $menu): string
    {
        return $menu;
    }
}

$_SESSION['userPortalUi']['message_id'] = 777;
$m1 = new UserPortalMenuHarness();
$m1->input = ['message' => '/update', 'from' => 8115232055];
$m1->userPortalMenu();
check('/update: message_id сброшен в $_SESSION', empty($_SESSION['userPortalUi']['message_id']));

$_SESSION['userPortalUi']['message_id'] = 778;
$m2 = new UserPortalMenuHarness();
$m2->input = ['message' => '/userPortal', 'from' => 8115232055]; // не start/menu/update
$m2->userPortalMenu();
check('другой вход: message_id НЕ сброшен', !empty($_SESSION['userPortalUi']['message_id']));
unset($_SESSION['userPortalUi']);

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
