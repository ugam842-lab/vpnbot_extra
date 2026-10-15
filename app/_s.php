<?php
$p = json_decode(file_get_contents('/config/pac.json'), true);
foreach (['subscription_url_signed','subscription_url_epoch'] as $k) {
    echo $k.'='.var_export($p[$k] ?? 'UNSET', true)."\n";
}
