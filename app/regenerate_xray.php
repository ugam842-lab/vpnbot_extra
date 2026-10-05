<?php
// One-shot: regenerate xray inbounds from the transport registry so the xhttp
// inbound lands on the port nginx proxies to (8443), not the stale 443.
// Run inside php container:  php /app/regenerate_xray.php
require '/app/timezone.php';
require '/app/bot.php';
require '/app/config.php';
require '/app/i18n.php';

class RegenerateBot extends Bot
{
    public function regenerate(): void
    {
        $this->applyTransportRegistryAndRuntime();
    }
}

$bot = new RegenerateBot($c['key'], $i);
$bot->migratePacConf();

$before = $bot->getXray()['inbounds'] ?? [];
echo "BEFORE inbounds:\n";
foreach ($before as $in) {
    echo "  port={$in['port']} proto={$in['protocol']} net=" . ($in['streamSettings']['network'] ?? '') . " tag=" . ($in['tag'] ?? '') . "\n";
}

$bot->regenerate();

$after = $bot->getXray()['inbounds'] ?? [];
echo "AFTER inbounds:\n";
foreach ($after as $in) {
    echo "  port={$in['port']} proto={$in['protocol']} net=" . ($in['streamSettings']['network'] ?? '') . " tag=" . ($in['tag'] ?? '') . "\n";
}
echo "DONE\n";
