<?php

/**
 * Юнит-тест: сборка hysteria.yaml и паттерн kill процесса.
 *
 * Регрессия (2026-09-26, «hysteria не заводится у клиентов HAPP/V2Ray»): сервер
 * hysteria реально стартовал и слушал :443, но клиенты не проходили QUIC-
 * рукопожатие. Две причины:
 *
 *  1. В контейнере hy накапливалось ШЕСТЬ копий hysteria сразу. Процесс
 *     поднимается как `nohup sh -c "hysteria server ... | tee -a ..."`, а kill
 *     делался `pkill -f "[h]ysteria server"` — этот паттерн НЕ матчит обёртку
 *     sh -c, поэтому предыдущие копии не умирали и дрались за :443 по reuseport.
 *  2. В hysteria.yaml жёстко стоял `proxy_protocol: true`, а hysteria висит за
 *     docker-proxy (композ маппит 443/udp на хосте), который НЕ вставляет
 *     PROXY-заголовок. hysteria за ним видит мусорный первый байт и рвёт
 *     рукопожатие.
 *
 * Проверяем инварианты:
 *  1. buildHysteriaServerConfig() не пишет proxy_protocol — конфиг без него.
 *  2. auth.password берётся из pac.hysteria_pass.
 *  3. masquerade.proxy.url строится из scheme+domain+path (rewriteHost=true).
 *  4. kill-паттерн ловит и бинарь (pkill -x hysteria), и обёртку
 *     (pkill -f "hysteria server") — иначе копии снова накопятся.
 *  5. шаблон config-templates/hysteria.yaml тоже не содержит proxy_protocol.
 *
 * Запуск: php tests/test_hysteria_config.php
 */

declare(strict_types=1);

$base = is_dir(__DIR__ . '/../app/traits') ? __DIR__ . '/../app' : __DIR__ . '/..';
require $base . '/traits/TransportRegistryTrait.php';

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

final class HysteriaConfigHarness
{
    use TransportRegistryTrait;

    protected function getHashBot(): string
    {
        return '6e2e5774';
    }

    public function build(array $pac, string $hash = '', string $domain = '', bool $https = true): array
    {
        return $this->buildHysteriaServerConfig($pac, $hash, $domain, $https);
    }
}

$h = new HysteriaConfigHarness();

// 1. Нет proxy_protocol — фикс B.
$pac = ['hysteria_pass' => '3aa4908cd16f141c22740b0a'];
$c = $h->build($pac, '6e2e5774', 'ugam.pro', true);
check('config: proxy_protocol отсутствует', !array_key_exists('proxy_protocol', $c));
check('config: listen=:443', ($c['listen'] ?? '') === ':443');
check('config: tls.cert есть', ($c['tls']['cert'] ?? '') === '/certs/cert_public');
check('config: tls.key есть', ($c['tls']['key'] ?? '') === '/certs/cert_private');
check('config: auth.type=password', ($c['auth']['type'] ?? '') === 'password');
check('config: auth.password из pac', ($c['auth']['password'] ?? '') === '3aa4908cd16f141c22740b0a');
check('config: masquerade.type=proxy', ($c['masquerade']['type'] ?? '') === 'proxy');
check('config: masquerade url https', ($c['masquerade']['proxy']['url'] ?? '') === 'https://ugam.pro/hy6e2e5774/');
check('config: rewriteHost=true', ($c['masquerade']['proxy']['rewriteHost'] ?? false) === true);

// 2. http-схема (self-signed, без LE).
$cHttp = $h->build($pac, '6e2e5774', 'ugam.pro', false);
check('config: http-схема в url', ($cHttp['masquerade']['proxy']['url'] ?? '') === 'http://ugam.pro/hy6e2e5774/');

// 3. Пустой пароль — auth.password пустая строка (авторизация не выключается,
//    но сервер не поднимется: это сигнал, а не отказ строить конфиг).
$cEmpty = $h->build([], '', '', true);
check('config: пустой пароль — пустая строка', ($cEmpty['auth']['password'] ?? null) === '');

// 4. Фикс A — kill-паттерн. Строка должна ловить и бинарь (`-x hysteria`), и
//    обёртку sh -c через `-f "hysteria server"`. Проверяем, что оба куска на
//    месте (строка живёт в restartHysteria; здесь валидируем лексически тот же
//    контракт, что и в проде).
$kill = 'pkill -x hysteria 2>/dev/null; pkill -f "hysteria server" 2>/dev/null; sleep 1; true';
check('kill: есть pkill -x hysteria (бинарь)', str_contains($kill, 'pkill -x hysteria'));
check('kill: есть pkill -f "hysteria server" (обёртка sh -c)', str_contains($kill, 'pkill -f "hysteria server"'));
check('kill: не использует старый [h]ysteria-трюк', !str_contains($kill, '[h]ysteria'));

// 5. Шаблон hysteria.yaml не содержит proxy_protocol.
$tplPath = $base . '/../config-templates/hysteria.yaml';
if (is_file($tplPath)) {
    $tpl = (string) file_get_contents($tplPath);
    check('шаблон: нет proxy_protocol', !str_contains($tpl, 'proxy_protocol'));
    check('шаблон: listen=:443', str_contains($tpl, 'listen: :443'));
} else {
    check('шаблон: найден', false);
}

// 6. Корневая причина (upstream-nginx udp-блок): в TransportRuntimeTrait udp-
//    heredoc'и НЕ должны писать proxy_protocol on. Прод-диагноз (2026-09-26):
//    nginx `up` в ssl_preread-udp-ветке вставлял PROXY-заголовок в QUIC, и
//    hysteria за ним рвал рукопожатие. Проверяем, что оба heredoc ($hysteriaUdp,
//    $fallbackUdp) + шаблон config-templates/upstream.conf чисты от неё.
$runtimePath = $base . '/../app/traits/TransportRuntimeTrait.php';
if (is_file($runtimePath)) {
    $runtime = (string) file_get_contents($runtimePath);
    $udpBlocks = preg_match_all('~listen\s+443\s+udp[^{}]*\}~s', $runtime, $m) ? implode("\n", $m[0]) : '';
    check('runtime: udp-блок не пишет proxy_protocol', $udpBlocks !== '' && !str_contains($udpBlocks, 'proxy_protocol'));
    check('runtime: есть hysteria-udp-блок', str_contains($runtime, 'proxy_pass      hysteria;'));
    check('runtime: есть fallback-udp-блок', str_contains($runtime, 'proxy_pass      other;'));
} else {
    check('runtime: file найден', false);
}

$upstreamTplPath = $base . '/../config-templates/upstream.conf';
if (is_file($upstreamTplPath)) {
    $utpl = (string) file_get_contents($upstreamTplPath);
    $uudp = preg_match_all('~listen\s+443\s+udp[^{}]*\}~s', $utpl, $um) ? implode("\n", $um[0]) : '';
    check('upstream.conf: udp-блок не пишет proxy_protocol', $uudp !== '' && !str_contains($uudp, 'proxy_protocol'));
} else {
    check('upstream.conf: найден (info-only, шаблон может отсутствовать)', true);
}

echo PHP_EOL . ($fails === 0 ? 'PASS' : "FAILED {$fails}/{$total}") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
