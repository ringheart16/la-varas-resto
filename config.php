<?php
// Works in XAMPP (defaults below) and on Railway (MySQL service variables).
date_default_timezone_set('Asia/Manila');

function env_value(array $keys, $default = null) {
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value === false) $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if ($value !== null && $value !== '') return $value;
    }
    return $default;
}

$host = '127.0.0.1';
$port = 3306;
$db   = 'la_varas';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$url = env_value(['MYSQL_URL', 'DATABASE_URL']);
if ($url) {
    $parts = parse_url($url);
    if ($parts !== false) {
        $host = $parts['host'] ?? $host;
        $port = (int)($parts['port'] ?? $port);
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : $user;
        $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : $pass;
        $db   = isset($parts['path']) ? ltrim($parts['path'], '/') : $db;
    }
} else {
    $host = env_value(['MYSQLHOST', 'DB_HOST'], $host);
    $port = (int)env_value(['MYSQLPORT', 'DB_PORT'], $port);
    $db   = env_value(['MYSQLDATABASE', 'DB_DATABASE'], $db);
    $user = env_value(['MYSQLUSER', 'DB_USERNAME'], $user);
    $pass = env_value(['MYSQLPASSWORD', 'DB_PASSWORD'], $pass);
}

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
];

// Retry on CLI (container startup) or initial web request so the database has time to come up.
$attempts = PHP_SAPI === 'cli' ? 30 : 3;
$pdo = null;
$lastError = null;
for ($attempt = 0; $attempt < $attempts; $attempt++) {
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        $lastError = null;
        break;
    } catch (PDOException $e) {
        $lastError = $e;
        if ($attempt + 1 < $attempts) sleep(PHP_SAPI === 'cli' ? 2 : 1);
    }
}

if (!$pdo) {
    error_log('Database connection failed: ' . $lastError->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed: {$lastError->getMessage()}\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Database connection failed. Please try again shortly.']);
    exit;
}
