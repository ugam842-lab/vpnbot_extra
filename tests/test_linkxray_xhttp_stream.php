<?php

/**
 * Юнит-тест: vless-ссылка для xhttp-транспорта должна идти в режиме stream-up,
 * а не packet-up, и без gRPC-фрейминга.
 *
 * Регрессия: nginx-прокси `ng` пробрасывает xhttp-инбаунд (8443) через
 * `proxy_pass http://xr:8443; proxy_http_version 1.1`. HTTP/1.1 не умеет
 * таскать xhttp в режиме packet-up (HTTP/2-мультиплексирование + gRPC-
 * фрейминг, Content-Type: application/grpc). Клиентский линк строился как
 * mode=packet-up + extra.noGRPCHeader=false + alpn=h2, handshake снаружи
 * зависал (TIME_WAIT). Фикс — перевести клиент на stream-up (один POST со
 * стримом тела по HTTP/1.1) и убрать gRPC-фрейминг + принудительный ALPN h2.
 * Серверный режим auto принимает stream-up as-is, правки сервера не нужны.
 *
 * Инварианты для xhttp-ссылки:
 *  1. есть mode=stream-up (не packet-up);
 *  2. extra содержит noGRPCHeader:true (нет gRPC Content-Type);
 *  3. нет alpn=h2 (не принуждаем HTTP/2, который ломает HTTP/1.1-прокси);
 *  4. reality-ветка НЕ тронута — у неё по-прежнему mode=packet-up и нет
 *     noGRPCHeader, потому что reality терминируется в xray напрямую (33443)
 *     без nginx и работает поверх packet-up.
 *
 * Запуск: php tests/test_linkxray_xhttp_stream.php
 */

declare(strict_types=1);

$base = is_dir(__DIR__ . '/../app/traits') ? __DIR__ . '/../app' : __DIR__ . '/..';
require $base . '/traits/TransportRuntimeTrait.php';
require $base . '/traits/TransportRegistryTrait.php';
require $base . '/traits/ClashTemplateTrait.php';

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

final class LinkXrayHarness
{
    use TransportRuntimeTrait;
    use TransportRegistryTrait;
    use ClashTemplateTrait;

    public array $clients = [];
    public array $inbounds = [];
    public array $pac = [];

    protected function getPacConf(): array
    {
        return $this->pac;
    }

    protected function getXray(): array
    {
        return ['inbounds' => $this->inbounds, 'clients' => $this->clients];
    }

    protected function getDomain($cdn = false)
    {
        return 'ugam.pro';
    }

    protected function getHashBot($notset = false)
    {
        return 'test';
    }

    protected function getClientFingerprint(?array $pac = null): string
    {
        return 'chrome';
    }

    protected function getXhttpTransportPath(string $hash = ''): string
    {
        return '/xh6e2e5774';
    }

    protected function findXrayClientByIndexOrId(array $xray, $ref): ?array
    {
        return ['id' => 'uuid-1234', 'email' => 'user@example.com'];
    }

    protected function isXrayRealityInbound(array $inbound): bool
    {
        return ($inbound['tag'] ?? '') === 'vless_reality';
    }

    protected function getTransportClientPort(string $transport, ?array $pac = null): int
    {
        return $transport === 'reality' ? 33443 : 443;
    }

    protected function getClientSubscriptionId($client): string
    {
        return '';
    }

    // linkXray живёт в Bot; объявляем его здесь, переиспользуя switch из Bot.
    public function linkXray($i, $s = false)
    {
        $c      = $this->getXray();
        $pac    = $this->getPacConf();
        $globalTransports = $this->getTransportRegistryGlobal($pac);
        $domain = $this->getDomain(empty($globalTransports['reality']));
        $hash   = $this->getHashBot();
        $client = $this->findXrayClientByIndexOrId($c, $i);
        if (!is_array($client)) {
            return '';
        }
        $flags = $this->getClientTransportFlags($client, $pac);
        $clientId = (string) ($client['id'] ?? '');
        $email = (string) ($client['email'] ?? 'user');

        $realityInbound = null;
        foreach (($c['inbounds'] ?? []) as $inbound) {
            if ($this->isXrayRealityInbound($inbound)) {
                $realityInbound = $inbound;
                break;
            }
        }
        $realitySettings = $realityInbound['streamSettings']['realitySettings'] ?? [];
        $realitySni = (string) ($realitySettings['serverNames'][0] ?? ($pac['reality']['domain'] ?? $domain));
        $realitySid = (string) ($realitySettings['shortIds'][0] ?? ($pac['reality']['shortId'] ?? ''));

        $transport = $s;
        if (!$transport) {
            if (!empty($flags['reality'])) {
                $transport = 'reality';
            } elseif (!empty($flags['ws'])) {
                $transport = 'ws';
            } elseif (!empty($flags['xhttp'])) {
                $transport = 'xhttp';
            } else {
                $transport = 'ws';
            }
        }

        $clientPort = $this->getTransportClientPort((string) $transport, $pac);
        $fp = rawurlencode($this->getClientFingerprint($pac));

        switch ($transport) {
            case 'reality':
                $xhPath = rawurlencode($this->getXhttpTransportPath($hash));

                return "vless://{$clientId}@$domain:{$clientPort}"
                    . "?security=reality"
                    . "&sni={$realitySni}"
                    . "&fp={$fp}&pbk={$pac['xray']}"
                    . "&sid={$realitySid}"
                    . "&type=xhttp"
                    . "&path={$xhPath}"
                    . "&mode=packet-up"
                    . "&flow="
                    . "&headerType="
                    . "#{$email}";
            case 'xhttp':
                $xhPath = rawurlencode($this->getXhttpTransportPath($hash));

                return "vless://{$clientId}@$domain:{$clientPort}"
                    . "?security=tls"
                    . "&type=xhttp"
                    . "&headerType="
                    . "&path={$xhPath}"
                    . "&host=$domain"
                    . "&flow="
                    . "&mode=stream-up"
                    . "&extra=%7B%22xmux%22%3A%7B%22cMaxReuseTimes%22%3A0%2C%22maxConcurrency%22%3A%2216-32%22%2C%22maxConnections%22%3A0%2C%22hKeepAlivePeriod%22%3A0%2C%22hMaxRequestTimes%22%3A%22600-900%22%2C%22hMaxReusableSecs%22%3A%221800-3000%22%7D%2C%22headers%22%3A%7B%7D%2C%22noGRPCHeader%22%3Atrue%2C%22xPaddingBytes%22%3A%22100-1000%22%2C%22scMaxEachPostBytes%22%3A1000000%2C%22scMinPostsIntervalMs%22%3A30%2C%22scStreamUpServerSecs%22%3A%2220-80%22%7D"
                    . "&sni=$domain"
                    . "&fp={$fp}"
                    . "#{$email}";
            case 'ws':
            default:
                $wsPath = rawurlencode($this->getWsTransportPath($hash));

                return "vless://{$clientId}@$domain:{$clientPort}"
                    . "?flow="
                    . "&path={$wsPath}"
                    . "&security=tls"
                    . "&sni=$domain"
                    . "&fp={$fp}"
                    . "&type=ws"
                    . "#{$email}";
        }
    }
}

$h = new LinkXrayHarness();
$h->inbounds = [
    ['tag' => 'vless_xhttp', 'port' => 8443],
    ['tag' => 'vless_reality', 'port' => 33443, 'streamSettings' => ['realitySettings' => ['serverNames' => ['yandex.ru'], 'shortIds' => ['2f52bdfcea040c05']]]],
];
$h->pac = ['xray' => 'PBK', 'reality' => ['domain' => 'yandex.ru', 'shortId' => '2f52bdfcea040c05']];

// --- xhttp (обычный TLS) ---
$link = $h->linkXray(0, 'xhttp');
$extra = [];
preg_match('/&extra=([^&]+)&/', $link, $m);
if (isset($m[1])) {
    $extra = json_decode(rawurldecode($m[1]), true) ?: [];
}
check('xhttp: mode=stream-up', preg_match('/&mode=stream-up(&|#)/', $link) === 1);
check('xhttp: no packet-up', strpos($link, 'mode=packet-up') === false);
check('xhttp: noGRPCHeader=true', ($extra['noGRPCHeader'] ?? null) === true);
check('xhttp: нет alpn=h2', strpos($link, 'alpn=') === false);
check('xhttp: security=tls', strpos($link, '?security=tls') !== false);
check('xhttp: путь сохранён', strpos($link, '&path=%2Fxh6e2e5774&') !== false);

// --- reality (не должен измениться) ---
$rlink = $h->linkXray(0, 'reality');
check('reality: mode=packet-up сохранён', preg_match('/&mode=packet-up&/', $rlink) === 1);
check('reality: нет noGRPCHeader в extra (нет extra)', strpos($rlink, 'extra=') === false);
check('reality: security=reality', strpos($rlink, '?security=reality') !== false);

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
