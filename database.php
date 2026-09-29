<?php

require_once __DIR__ . '/config.php';

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    if (function_exists('app_is_json_request') && app_is_json_request()) {
        $payload = ['success' => false, 'message' => 'Database connection failed.'];
        if (defined('APP_ENV') && APP_ENV === 'local') {
            $payload['debug'] = $e->getMessage();
        }
        app_json_response($payload, 500);
    }

    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
