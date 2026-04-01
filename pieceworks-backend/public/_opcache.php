<?php
header('Content-Type: text/plain');
if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo "opcache_reset: " . ($result ? "SUCCESS" : "FAILED") . "\n";
} else {
    echo "opcache_reset not available\n";
}
if (function_exists('opcache_get_status')) {
    $s = opcache_get_status(false);
    echo "opcache enabled: " . ($s['opcache_enabled'] ? 'yes' : 'no') . "\n";
    echo "cached scripts: " . ($s['opcache_statistics']['num_cached_scripts'] ?? 'n/a') . "\n";
}
echo "DONE";
?>