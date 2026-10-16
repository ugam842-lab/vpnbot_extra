<?php
require '/app/timezone.php';
require '/app/bot.php';
require '/app/config.php';
require '/app/i18n.php';

class RegenerateBot extends Bot
{
    public function regenerate(): void
    {
        $this->cloakNginx();
    }
}

$bot = new RegenerateBot($c['key'], $i);
$bot->migratePacConf();
$bot->regenerate();

$live = (string) file_get_contents('/config/nginx.conf');
echo "buffering_off_count=" . substr_count($live, 'proxy_request_buffering off') . "\n";
echo "leading_bom=" . (strpos($live, "\xEF\xBB\xBF") === 0 ? '1' : '0') . "\n";
echo "DONE\n";
