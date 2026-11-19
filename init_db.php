<?php
// Runs automatically on container start (Railway). Safe to run on every boot.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/config.php';
require __DIR__ . '/bootstrap.php';

try {
    ensureSchema($pdo);
    seedDatabase($pdo);
    echo "Database ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Database init failed: ' . $e->getMessage() . "\n");
    exit(1);
}
