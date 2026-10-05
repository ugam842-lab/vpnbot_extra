<?php
$x = json_decode(file_get_contents('/config/xray.json'), true);
$all = $x['inbounds'][0]['settings']['clients_all'] ?? [];
$withTime = []; $noTime = 0; $now = time();
foreach ($all as $c) {
    if (!empty($c['time'])) {
        $withTime[] = [$c['email']??'', (int)$c['time'], !empty($c['off'])];
    } elseif (!isset($c['time'])) {
        $noTime++;
    }
}
echo "clients_with_set_time=".count($withTime)." no_time_key=$noTime now=$now\n\n";
usort($withTime, fn($a,$b)=>$a[1]<=>$b[1]);
echo "--- earliest 10 time-valued clients ---\n";
foreach (array_slice($withTime,0,10) as $w) {
    $diff = $w[1]-$now;
    echo $w[0]." t=".date('Y-m-d H:i', $w[1])." diff=".($diff>0?"+":"").round($diff/3600,1)."h off=".($w[2]?'Y':'n')."\n";
}
echo "\n--- latest 5 ---\n";
foreach (array_slice($withTime,-5) as $w) {
    $diff = $w[1]-$now;
    echo $w[0]." t=".date('Y-m-d H:i', $w[1])." diff=".($diff>0?"+":"").round($diff/3600,1)."h off=".($w[2]?'Y':'n')."\n";
}
