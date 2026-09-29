<?php

// api/config.php
define('APP_NAME', 'Business Online Application and Renewal System');
define('APP_BASE_DIR', basename(dirname(__DIR__)));
if (!empty($_SERVER['HTTP_HOST'])) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = strpos($scriptName, '/' . APP_BASE_DIR . '/') === 0 ? '/' . APP_BASE_DIR : '';
    define('APP_URL', $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath);
} else {
    define('APP_URL', 'http://localhost/' . APP_BASE_DIR);
}
define('APP_TIMEZONE', 'Asia/Manila');
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
define('PERMIT_VALID_DAYS', 365);
define('APP_ENV', 'local'); // local | production
define('AUTH_TOKEN_TTL_SECONDS', 7200);
define('AUTH_TOKEN_SECRET', 'change-this-long-random-secret-before-production');
define('UI_ALLOWED_ORIGINS', [
    'http://localhost',
    'http://localhost/obs',
    'http://127.0.0.1',
]);

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'obs_system');
define('DB_USER', 'root');
define('DB_PASS', '');

$smtpConfig = dirname(__DIR__) . '/smtp_email_files/smtp_config.php';
if (is_file($smtpConfig)) {
    require_once $smtpConfig;
}
if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', 'smtp.gmail.com');
}
if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', 587);
}
if (!defined('MAIL_USER')) {
    define('MAIL_USER', '');
}
if (!defined('MAIL_PASS')) {
    define('MAIL_PASS', '');
}
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', MAIL_USER);
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'OBS Permit System');
}

define('OTP_EXPIRY_MINUTES', 10);
define('SESSION_TIMEOUT_SECONDS', 7200);

if (!function_exists('app_is_json_request')) {
    function app_is_json_request(): bool
    {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

        return strpos($scriptName, '/api/') !== false
            || strpos($accept, 'application/json') !== false
            || strpos($contentType, 'application/json') !== false;
    }
}

if (!function_exists('app_json_response')) {
    function app_json_response(array $payload, int $statusCode = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}

if (app_is_json_request()) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ob_start();

    set_exception_handler(static function (Throwable $e): void {
        $payload = ['success' => false, 'message' => 'Server error. Please try again.'];
        if (defined('APP_ENV') && APP_ENV === 'local') {
            $payload['debug'] = $e->getMessage();
        }
        app_json_response($payload, 500);
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $payload = ['success' => false, 'message' => 'Server error. Please try again.'];
        if (defined('APP_ENV') && APP_ENV === 'local') {
            $payload['debug'] = $error['message'];
        }
        app_json_response($payload, 500);
    });
}

date_default_timezone_set(APP_TIMEZONE);

if (!empty($_SERVER['HTTP_ORIGIN']) && defined('UI_ALLOWED_ORIGINS')) {
    $origin = (string)$_SERVER['HTTP_ORIGIN'];
    if (in_array($origin, UI_ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Auth-Token, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Vary: Origin');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None',
        ]);
    }
    session_start();
}

// define('MAIL_HOST', 'smtp.gmail.com');
// define('MAIL_PORT', 587);
// define('MAIL_USER', 'gonzagamigue@gmail.com');
// define('MAIL_PASS', 'cojz imhh wero stap');
// define('MAIL_FROM', 'gonzagamigue@gmail.com');
// define('MAIL_FROM_NAME', 'OBS Permit System');
// define('APP_ENV', 'local'); // local | production
