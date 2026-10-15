<?php
require '/app/timezone.php';
require '/app/bot.php';
require '/app/config.php';
require '/app/i18n.php';

class EnableRealityBot extends Bot
{
    public function enableReality(): void
    {
        $pac = $this->getPacConf();
        $pac = $this->normalizeTransportRegistry($pac);
        $pac['transport_registry']['global']['reality'] = 1;
        $this->setPacConf($pac);
        $this->applyTransportRegistryAndRuntime();
    }
}

$bot = new EnableRealityBot($c['key'], $i);
$bot->migratePacConf();
$bot->enableReality();

$live = json_decode((string) file_get_contents('/config/xray.json'), true) ?: [];
$tags = [];
foreach ($live['inbounds'] ?? [] as $inbound) {
    $tags[] = $inbound['tag'] ?? '?';
}
echo "inbound_tags=" . implode(',', $tags) . "\n";
echo "REALITY_ENABLED\n";
