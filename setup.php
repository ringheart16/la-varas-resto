<?php
// Optional one-click installer. The app also self-initializes on the first API call.
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/config.php';
require __DIR__ . '/bootstrap.php';

try {
    ensureSchema($pdo);
    seedDatabase($pdo);
    echo "Database is ready.\n\nOpen index.html to start. No further setup is needed.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Setup failed: " . $e->getMessage() . "\n";
}
