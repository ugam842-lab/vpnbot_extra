<?php

/**
 * Чистый расчёт расписания автобэкапа.
 *
 * Вынесен из Bot::checkBackup(), чтобы логику можно было тестировать
 * изолированно (без config.php, файловой системы и Telegram).
 *
 * Семантика:
 *  - start / period задаются как "YYYY-MM-DD HH:MM / N hours|days|months".
 *  - Бэкап должен делаться, когда наступила точка расписания
 *    (start + N*period), на которую ещё не было бэкапа.
 *  - Результат — точка расписания, которую БЫЛА обязана закрыть этот бэкап
 *    (не текущее "сейчас"). Это делает проверку идемпотентной: повторный
 *    тик с той же точкой не порождает дубль (см. bot bug «бэкап приходит дважды»).
 */

final class BackupSchedule
{
    /**
     * Возвращает точку расписания, которую нужно зафиксировать как
     * last_backup_time (null — бэкап не нужен).
     *
     * @param int|string $start          epoch (strtotime) точки старта
     * @param int        $period         длительность периода в секундах (>0)
     * @param int        $now            текущий epoch
     * @param int        $lastBackupTime уже зафиксированная точка (0 — никогда)
     *
     * @return int|null
     */
    public static function dueAt($start, int $period, int $now, int $lastBackupTime = 0): ?int
    {
        if ($start <= 0 || $period <= 0 || $now < $start) {
            return null;
        }

        $elapsed         = $now - $start;
        $periodsElapsed  = intdiv($elapsed, $period);
        $lastScheduled   = $start + ($periodsElapsed * $period);

        if ($lastBackupTime < $lastScheduled) {
            return $lastScheduled;
        }

        return null;
    }
}
