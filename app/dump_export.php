<?php

require __DIR__ . '/timezone.php';
require __DIR__ . '/bot.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';

$bot = new Bot($c['key'], $i);
$json = $bot->export();
file_put_contents('/config/export_backup.json', $json);
echo "written " . strlen($json) . " bytes\n";
