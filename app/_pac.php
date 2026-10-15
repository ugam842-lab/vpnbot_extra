<?php
$p = json_decode(file_get_contents('/config/pac.json'), true);
foreach (['backup','last_backup_time','autocleanlogs','reset_monthly','user_portal_enabled','pinbackup'] as $k) {
    echo $k . '=' . var_export($p[$k] ?? null, true) . "\n";
}
echo "--- subscription/wg time defaults ---\n";
foreach ($p as $k=>$v) {
    if (stripos($k,'time')!==false || stripos($k,'expire')!==false || stripos($k,'period')!==false || stripos($k,'ttl')!==false || stripos($k,'limit')!==false) {
        echo $k.'='.var_export($v,true)."\n";
    }
}
