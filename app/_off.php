
<?php
require '/app/bot.php';
$b = new Bot(null, null);
$m = new ReflectionMethod('Bot', 'getXray');
$m->setAccessible(true);
$xr = $m->invoke($b);
$offc = []; $onc_time=[];
foreach (($xr['inbounds'] ?? []) as $ib) {
  foreach (($ib['settings']['clients'] ?? []) as $cl) {
    if (!empty($cl['off'])) {
      $flags = [];
      foreach (['off','runtime_parent_retired','hwid_disabled','hwid_limit','device_delete_password_hash'] as $f) if (isset($cl[$f])) $flags[] = $f.'='.json_encode($cl[$f]);
      $offc[] = ['id'=>substr($cl['id']??'',0,8),'sub'=>$cl['subscription_id']??'','email'=>$cl['email']??'','time'=>$cl['time']??null,'flags'=>$flags];
    }
  }
}
echo "OFF_CLIENTS=".count($offc)."\n";
foreach ($offc as $o) { echo json_encode($o, JSON_UNESCAPED_UNICODE)."\n"; }
