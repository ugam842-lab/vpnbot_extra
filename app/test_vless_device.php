<?php
// Standalone unit test for per-device VLESS + AmneziaWG configs in the portal (variant B).
// Run inside the php container:  php /app/test_vless_device.php
require_once '/app/bot.php';

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? "PASS" : "FAIL") . "  $label\n";
    if (!$ok) {
        $failures++;
    }
}

class TestVlessDeviceBot extends Bot
{
    public array $xray = ['inbounds' => [['settings' => ['clients' => []]]]];
    public array $devices = [];
    public ?array $session = null;
    public string $resolvedHwid = '';
    public string $lastShownText = '';
    public array $lastShownButtons = [];
    public string $lastAck = '';

    public function __construct() {}

    public function i18n(string $menu): string { return $menu; }
    public function getPacConf() { return []; }

    public function getXray() { return $this->xray; }
    public function linkXray($i, $s = false) { return "vless://test-$i"; }
    public function getHwidDevicesByUser($uid) { return $this->devices; }
    public function getUserPortalSession(): ?array { return $this->session; }
    public function getUserPortalTokenScope(): string { return 'scope'; }
    public function resolveHwidToken($scope, $token) { return $this->resolvedHwid; }
    public function getHwidDeviceDisplayName(array $info): string { return $info['device_name'] ?? ''; }
    public function ackCallback(?string $text = null, bool $showAlert = false): void { $this->lastAck = (string) $text; }
    public function userPortalMenu() { $this->lastShownText = 'MENU'; }
    public function userPortalDevices($page = 0) { $this->lastShownText = 'DEVICES'; }

    public function isRuntimeDeviceWgEnabled(array $client): bool { return true; }
    public function runInRuntimeWgContext(callable $callback) { return $callback(); }
    public function ensureDeviceWgProfile(string $ownerSubId, string $hwid, string $deviceUuid, string $allowedIps = '0.0.0.0/0'): ?array {
        return ['interface' => ['## device_uuid' => $deviceUuid], 'peers' => []];
    }
    public function createConfig($data) { return "wg-conf\n"; }

    public function userPortalShow(string $text, $buttons = false, $replyPlaceholder = false, ?array $replyState = null): void
    {
        $this->lastShownText = $text;
        $this->lastShownButtons = $buttons ?: [];
    }

    public function tLink(string $ownerSubId, string $hwid): string { return $this->getHwidDeviceVlessLink($ownerSubId, $hwid); }
    public function tShow($pageToken, $token) { $this->userPortalDeviceVless($pageToken, $token); }
    public function tShowWg($pageToken, $token) { $this->userPortalDeviceWg($pageToken, $token); }
}

$b = new TestVlessDeviceBot();

// --- getHwidDeviceVlessLink: find device client by (parent, hwid) ---
$b->xray = [
    'inbounds' => [
        [
            'settings' => [
                'clients' => [
                    ['id' => 'owner', 'subscription_id' => 'sub_x'],
                    ['id' => 'dev_uuid', 'device_parent_id' => 'sub_x', 'device_hwid' => 'hwidA'],
                ],
            ],
        ],
    ],
];
check('link found by hwid', $b->tLink('sub_x', 'hwidA') === 'vless://test-1');
check('link empty for unknown hwid', $b->tLink('sub_x', 'hwidZ') === '');
check('link empty for other owner', $b->tLink('sub_y', 'hwidA') === '');
$b->xray = ['inbounds' => [['settings' => ['clients' => []]]]];
check('link empty when no clients', $b->tLink('sub_x', 'hwidA') === '');

// --- userPortalDeviceVless: shows link + name + back button ---
$b->xray = [
    'inbounds' => [
        [
            'settings' => [
                'clients' => [
                    ['id' => 'owner', 'subscription_id' => 'sub_x'],
                    ['id' => 'dev_uuid', 'device_parent_id' => 'sub_x', 'device_hwid' => 'hwidA'],
                ],
            ],
        ],
    ],
];
$b->session = ['subscription_id' => 'sub_x'];
$b->resolvedHwid = 'hwidA';
$b->devices = ['hwidA' => ['device_name' => 'iPhone']];
$b->tShow('0_tok', 'tok');
check('page shows link', strpos($b->lastShownText, 'vless://test-1') !== false);
check('page shows device name', strpos($b->lastShownText, 'iPhone') !== false);
check('page has back button', isset($b->lastShownButtons[0][0])
    && strpos($b->lastShownButtons[0][0]['callback_data'], '/userPortalDevices_0') !== false);

// --- userPortalDeviceVless: no device client => empty message ---
$b->xray = ['inbounds' => [['settings' => ['clients' => [['id' => 'owner', 'subscription_id' => 'sub_x']]]]]];
$b->devices = ['hwidA' => ['device_name' => 'iPhone']];
$b->tShow('0_tok', 'tok');
check('page shows empty message', strpos($b->lastShownText, 'user portal vless empty') !== false);
check('empty page does NOT show link', strpos($b->lastShownText, 'vless://') === false);

// --- userPortalDeviceVless: no session => menu ---
$b->session = null;
$b->tShow('0_tok', 'tok');
check('no session -> menu', $b->lastShownText === 'MENU');

// --- userPortalDeviceVless: empty hwid => back to devices ---
$b->session = ['subscription_id' => 'sub_x'];
$b->resolvedHwid = '';
$b->tShow('0_tok', 'tok');
check('empty hwid -> devices', $b->lastShownText === 'DEVICES');

// --- userPortalDeviceWg: shows WG config ---
$b->session = ['subscription_id' => 'sub_x'];
$b->resolvedHwid = 'hwidA';
$b->devices = ['hwidA' => ['device_name' => 'iPhone', 'device_uuid' => 'dev_uuid']];
$b->tShowWg('0_tok', 'tok');
check('wg page shows config', strpos($b->lastShownText, 'wg-conf') !== false);
check('wg page shows device name', strpos($b->lastShownText, 'iPhone') !== false);

// --- userPortalDeviceWg: no device_uuid => empty ---
$b->devices = ['hwidA' => ['device_name' => 'iPhone']];
$b->tShowWg('0_tok', 'tok');
check('wg page shows empty', strpos($b->lastShownText, 'user portal wg empty') !== false);

// --- userPortalDeviceWg: no session => menu ---
$b->session = null;
$b->tShowWg('0_tok', 'tok');
check('wg no session -> menu', $b->lastShownText === 'MENU');

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
