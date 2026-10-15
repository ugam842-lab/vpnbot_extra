<?php
require '/app/timezone.php';
require '/app/config.php';
require '/app/calc.php';
require '/app/bot.php';
require '/app/i18n.php';
$bot = new Bot($c['key'], $i);
$update = [
  'update_id' => 1,
  'message' => [
    'message_id' => 9001,
    'from' => ['id' => 8115232055, 'is_bot' => false, 'first_name' => 'T', 'username' => 't'],
    'chat' => ['id' => 8115232055, 'type' => 'private'],
    'date' => time(),
    'text' => '/update',
  ],
];
echo "isChildNode=" . var_export($bot->isChildNode(), true) . "\n";
echo "node_role=" . var_export($bot->getPacConf()['node_role'] ?? null, true) . "\n";
