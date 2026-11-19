<?php
// System check: open http://localhost/la_varas_system/check.php  (delete after use)
error_reporting(E_ALL); ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
echo "PHP version: " . PHP_VERSION . (version_compare(PHP_VERSION, '7.4', '>=') ? "  OK\n" : "  TOO OLD (need 7.4+)\n");
foreach (['pdo_mysql', 'mbstring', 'json'] as $e) echo "ext $e: " . (extension_loaded($e) ? "OK" : "MISSING - enable in php.ini") . "\n";
foreach (['index.html', 'cashier.html', 'customer.html', 'api.php', 'config.php', 'database.sql'] as $f) echo "file $f: " . (is_file(__DIR__ . "/$f") ? "found (" . filesize(__DIR__ . "/$f") . " bytes)" : "MISSING") . "\n";
try {
    require __DIR__ . '/config.php';
    echo "Database connection: OK\n";
    foreach (['cashiers', 'customers', 'products', 'orders', 'order_items'] as $t) {
        try { $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); echo "table $t: OK ($n rows)\n"; }
        catch (Throwable $e) { echo "table $t: MISSING - run setup.php (customers is added automatically on the first api.php call)\n"; }
    }
} catch (Throwable $e) { echo "Error: " . $e->getMessage() . "\n"; }
