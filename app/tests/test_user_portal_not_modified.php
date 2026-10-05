<?php

/**
 * Юнит-тест userPortalShow() на «message is not modified».
 *
 * Регрессия: при повторном /update session userPortalUi.message_id указывал на
 * уже показанное меню; update() (editMessageText) возвращал 400 «message is not
 * modified», код сбрасывал session id и звал send() — меню задваивалось, а с
 * виду бот «не откликался». Фикс: на «not modified» выйти тихо (экран уже
 * корректен), на честную ошибку редактирования — как раньше, send().
 *
 * Запуск: php tests/test_user_portal_not_modified.php
 * Чистый хелпер: подменяем update()/send(), не трогаем config.php/TG/файлы.
 */

declare(strict_types=1);

// Трейт живёт в app/traits/ (workspace) либо traits/ (в контейнере рядом с app/).
$traitPath = __DIR__ . '/../app/traits/UserPortalTrait.php';
if (!is_file($traitPath)) {
    $traitPath = __DIR__ . '/../traits/UserPortalTrait.php';
}
require $traitPath;

final class NotModifiedHarness
{
    use UserPortalTrait;

    /** @var array<string,mixed> */
    public array $input;
    /** @var int сколько раз звался send() */
    public int $sendCalls = 0;
    /** @var int сколько раз звался update() */
    public int $updateCalls = 0;
    /** @var mixed результат, который вернёт update() */
    public $updateResult;
    /** @var int реальный сохранённый message_id после userPortalShow() */
    public int $savedMessageId = 0;
    /** @var int внутреннее хранилище message_id портала вместо $_SESSION */
    private int $__uiMessageId;

    public function __construct(int $uiMessageId)
    {
        $this->input = ['chat' => 111, 'message_id' => 0, 'callback' => false];
        $this->__uiMessageId = $uiMessageId;
    }

    protected function ensureUserPortalSession(): void
    {
        // no-op: в тесте сессией управляем сами, изолируемся от session_start().
    }

    protected function getUserPortalUiMessageId(): ?int
    {
        return $this->__uiMessageId > 0 ? $this->__uiMessageId : null;
    }

    protected function setUserPortalUiMessageId(int $messageId): void
    {
        $this->__uiMessageId = $messageId;
        $this->savedMessageId = $messageId;
    }

    protected function update($chat, $messageId, $text, $buttons = false, $replyPlaceholder = false)
    {
        $this->updateCalls++;

        return $this->updateResult;
    }

    protected function send($chat, $text, $replyTo = false, $buttons = false, $replyPlaceholder = false)
    {
        $this->sendCalls++;

        return ['result' => ['message_id' => 999]];
    }

    public function callUserPortalShow(): void
    {
        $this->userPortalShow('menu');
    }

    public function callIsMessageNotModified($r): bool
    {
        return $this->isMessageNotModified($r);
    }
}

$fails = 0;
$total = 0;

function check(string $name, bool $ok): void
{
    global $fails, $total;
    $total++;
    echo $ok ? "ok    {$name}\n" : "FAIL  {$name}\n";
    if (!$ok) {
        $fails++;
    }
}

// 1. «message is not modified» — тихий выход: id сохранён, send НЕ зван.
$h = new NotModifiedHarness(4218);
$h->updateResult = ['ok' => false, 'description' => 'Bad Request: message is not modified'];
$h->callUserPortalShow();
check('not-modified: no send()', $h->sendCalls === 0);
check('not-modified: update() called once', $h->updateCalls === 1);
check('not-modified: message_id kept', $h->savedMessageId === 4218);

// 2. Вариация регистра и формулировки.
$h = new NotModifiedHarness(4218);
$h->updateResult = ['ok' => false, 'description' => 'message is not modified: specified new message content'];
$h->callUserPortalShow();
check('not-modified (variant): no send()', $h->sendCalls === 0);
check('not-modified (variant): id kept', $h->savedMessageId === 4218);

// 3. Честная ошибка редактирования — фолбэк в send(), id из нового сообщения.
$h = new NotModifiedHarness(4218);
$h->updateResult = ['ok' => false, 'description' => 'Bad Request: message to edit not found'];
$h->callUserPortalShow();
check('edit-not-found: falls back to send()', $h->sendCalls === 1);
check('edit-not-found: new id from send()', $h->savedMessageId === 999);

// 4. Другая ошибка (напр. «chat not found») — тоже фолбэк, не тихий выход.
$h = new NotModifiedHarness(4218);
$h->updateResult = ['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'];
$h->callUserPortalShow();
check('other error: falls back to send()', $h->sendCalls === 1);

// 5. Успешный update — как раньше: id сохранён, send НЕ зван.
$h = new NotModifiedHarness(4218);
$h->updateResult = ['ok' => true, 'result' => ['message_id' => 4218]];
$h->callUserPortalShow();
check('ok: no send()', $h->sendCalls === 0);
check('ok: id kept', $h->savedMessageId === 4218);

// 6. Нет сохранённого id вовсе (messageId=0) — сразу send(), update не зван.
$h = new NotModifiedHarness(0);
$h->updateResult = ['ok' => false, 'description' => 'message is not modified'];
$h->callUserPortalShow();
check('no-id: send() directly, no update()', $h->sendCalls === 1 && $h->updateCalls === 0);
check('no-id: new id saved', $h->savedMessageId === 999);

// 7. Пустой/malformed ответ update — helper не ловит «not modified», фолбэк.
$h = new NotModifiedHarness(4218);
$h->updateResult = ['description' => ''];
$h->callUserPortalShow();
check('empty result: falls back to send()', $h->sendCalls === 1);

// 8. isMessageNotModified() напрямую через прокси.
$h = new NotModifiedHarness(4218);
check('helper: positive', $h->callIsMessageNotModified(['ok' => false, 'description' => 'message is not modified']));
check('helper: negative (not found)', !$h->callIsMessageNotModified(['ok' => false, 'description' => 'message to edit not found']));
check('helper: negative (ok)', !$h->callIsMessageNotModified(['ok' => true]));
check('helper: negative (empty)', !$h->callIsMessageNotModified([]));

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
