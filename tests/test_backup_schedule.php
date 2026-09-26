<?php

/**
 * Юнит-тест расчёта расписания автобэкапа (BackupSchedule::dueAt()).
 *
 * Регрессия: ранее checkBackup() писал last_backup_time = time() (стена
 * времени), а сравнивал с точкой расписания lastScheduledBackup. При сдвиге
 * времени/перезапуске cron это давало повторный бэкап «дважды». Теперь
 * dueAt() возвращает строго точку расписания, а повторный вызов с уже
 * зафиксированной точкой возвращает null — дубль исключён.
 *
 * Запуск: php tests/test_backup_schedule.php
 * Чистый хелпер: не трогает config.php, файлы, TG.
 */

declare(strict_types=1);

require __DIR__ . '/../app/BackupSchedule.php';

$failures = 0;

function check(string $name, $got, $expected): void
{
    global $failures;
    if ($got === $expected) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name\n   expected: " . var_export($expected, true) . "\n   got:      " . var_export($got, true) . "\n";
    }
}

$HOUR = 3600;
$start = strtotime('2026-03-21 23:35');

// 1. Нет бэкапа, если параметры пусты/некорректны.
check('start<=0 -> null', BackupSchedule::dueAt(0, $HOUR, 100), null);
check('period<=0 -> null', BackupSchedule::dueAt($start, 0, 100), null);
check('now < start -> null', BackupSchedule::dueAt($start, 6 * $HOUR, $start - 1), null);

// 2. Первая точка расписания после старта — ровно она и возвращается.
check('первая точка = start+period', BackupSchedule::dueAt($start, 6 * $HOUR, $start + 6 * $HOUR + 5), $start + 6 * $HOUR);

// 3. Пропущено несколько периодов — возвращается ПОСЛЕДНЯЯ пройденная точка.
//    start + 3*period.
$now = $start + 3 * 6 * $HOUR + 10; // чуть после третьей точки
check('пропущено несколько периодов -> последняя точка', BackupSchedule::dueAt($start, 6 * $HOUR, $now, 0), $start + 3 * 6 * $HOUR);

// 4. Регрессия дубля: после фиксации точки повторный вызов с тем же now -> null.
$due = BackupSchedule::dueAt($start, 6 * $HOUR, $now, 0);
check('повторный тик с зафиксированной точкой -> null', BackupSchedule::dueAt($start, 6 * $HOUR, $now, $due), null);

// 5. lastBackupTime опережает текущую точку (часы переведены назад / перезапуск) -> null.
$future = $start + 10 * 6 * $HOUR;
check('lastBackupTime впереди -> null', BackupSchedule::dueAt($start, 6 * $HOUR, $start + 2 * 6 * $HOUR, $future), null);

// 6. lastBackupTime == текущая точка (ровно) -> null.
check('lastBackupTime == точке -> null', BackupSchedule::dueAt($start, 6 * $HOUR, $start + 6 * $HOUR + 1, $start + 6 * $HOUR), null);

// 7. lastBackupTime меньше на ровно один период — бэкап на следующую точку.
check('отставание ровно на период -> следующая точка', BackupSchedule::dueAt($start, 6 * $HOUR, $start + 6 * $HOUR + 1, $start), $start + 6 * $HOUR);

if ($failures > 0) {
    echo "\n$failures FAIL\n";
    exit(1);
}

echo "\nok — все проверки прошли\n";
exit(0);
