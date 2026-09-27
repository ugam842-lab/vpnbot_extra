<?php

/**
 * Cross-issuance: отдаём клиенту конфиги ДРУГОГО транспорта из его карточки.
 *
 * Два направления (зеркальные):
 *   VLESS -> Amnezia/WG : из карточки xray-клиента выдаём N новых WG-пиров,
 *                         привязанных к подписке этого клиента (## owner_sub_id).
 *   Amnezia/WG -> VLESS : из карточки Amnezia-пира выдаём VLESS-конфиг того же
 *                         человека (anchor по ## owner_sub_id / subscription_id).
 *
 * Якорь подписки один: subscription_id. WG-пиры клеятся к нему полем
 * ## owner_sub_id интерфейса; у xray-клиента это subscription_id/id.
 *
 * Этот трейт держит ЧИСТУЮ логику (без TG/SSH/файлов) — её юнит-тестируют.
 * Побочные эффекты (send/upload/QR/restart*) живут в bot.php-методах, которые
 * вызывают эти пюре-хелперы.
 */

trait CrossIssueTrait
{
    /**
     * Anchor подписки для WG/Amnezia-пира: ## owner_sub_id, если он есть.
     * Пустая строка = пир ещё не привязан к подписке (бесхозный).
     */
    protected function crossIssueWgAnchor(array $wgClient): string
    {
        return (string) ($wgClient['interface']['## owner_sub_id'] ?? '');
    }

    /**
     * Сколько Amnezia-пиров у подписки уже есть (по ## owner_sub_id).
     */
    protected function crossIssueCountAwgByAnchor(array $wgClients, string $subId): int
    {
        $n = 0;
        foreach ($wgClients as $c) {
            if (!is_array($c)) {
                continue;
            }
            if ($this->crossIssueWgAnchor($c) === $subId) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Сколько ещё Amnezia-конфигов можно выдать подписке до предела awg_limit
     * (0 = «нет лимита», как и в getAwgLimit). Возвращает 0 если лимит исчерпан
     * или база не позволяет, иначе min(remaining, requested).
     */
    protected function crossIssueAwgAvailable(int $existing, int $awgLimit, int $requested): int
    {
        $requested = max(0, $requested);
        if ($requested <= 0) {
            return 0;
        }
        // 0 и ниже = без лимита.
        if ($awgLimit <= 0) {
            return $requested;
        }
        $remaining = max(0, $awgLimit - $existing);
        return (int) min($remaining, $requested);
    }

    /**
     * Какие перекрёстные транспорты доступны клиенту, по его transport-флагам.
     * Возвращает: 'awg' — можно выдать Amnezia-пиры, 'vless' — можно выдать
     * VLESS-конфиг. Чистый: не трогает registry напрямую, берёт уже готовые
     * флаги из getClientTransportFlags() вызывающего.
     */
    protected function crossIssueAvailable(array $transportFlags): array
    {
        $out = [
            'awg'    => !empty($transportFlags['awg']),
            'vless'  => $this->hasEnabledXrayTransport($transportFlags),
        ];

        return $out;
    }
}
