<?php

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/config.php';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    if (auth_bearer_user() !== null) {
        return true;
    }

    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function sanitize(string $v): string
{
    return trim(strip_tags($v));
}

function redirect(string $path): void
{
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

function is_logged_in(): bool
{
    return auth_user() !== null;
}

function auth_user(): ?array
{
    $bearerUser = auth_bearer_user();
    if ($bearerUser !== null) {
        return $bearerUser;
    }

    return $_SESSION['user'] ?? null;
}

function require_auth(array $roles = []): void
{
    $user = auth_user();
    if (!$user) {
        if (function_exists('app_is_json_request') && app_is_json_request()) {
            app_json_response(['success' => false, 'message' => 'Please login again.'], 401);
        }
        redirect('login.php');
    }
    if ($roles) {
        $role = $user['role'] ?? '';
        if (!in_array($role, $roles, true)) {
            if (function_exists('app_is_json_request') && app_is_json_request()) {
                app_json_response(['success' => false, 'message' => 'Forbidden'], 403);
            }
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }
    $m = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $m;
}

function upload_error_message(int $error): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'The file is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The file is larger than the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was received by the API.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
    ];

    return $messages[$error] ?? 'The server could not accept the uploaded file.';
}

function last_upload_error(): string
{
    return (string)($GLOBALS['last_upload_error'] ?? '');
}

// Resolves a stored 'uploads/...' path, checking the API's own uploads dir first, then the legacy parent dir.
function resolve_upload_path(string $relPath): ?string
{
    $relPath = ltrim($relPath, '/');
    foreach ([dirname(UPLOAD_DIR), dirname(__DIR__)] as $base) {
        $full = realpath($base . '/' . $relPath);
        if ($full && is_file($full)) {
            return $full;
        }
    }
    return null;
}

function upload_file(array $file, string $folder, array $allowed = ['jpg','jpeg','png','pdf','doc','docx']): ?string
{
    $GLOBALS['last_upload_error'] = '';
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        $GLOBALS['last_upload_error'] = upload_error_message((int)$uploadError);
        error_log('upload_file failed: upload error ' . $uploadError . ' for folder ' . $folder . '; ' . $GLOBALS['last_upload_error']);
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        $GLOBALS['last_upload_error'] = 'This file type is not allowed. Allowed files: ' . implode(', ', $allowed) . '.';
        error_log('upload_file failed: extension not allowed "' . $ext . '" for file ' . ($file['name'] ?? ''));
        return null;
    }
    $name = uniqid('doc_', true) . '.' . $ext;
    $destDir = UPLOAD_DIR . '/' . trim($folder, '/');
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
        $GLOBALS['last_upload_error'] = 'The upload folder could not be created on the server.';
        error_log('upload_file failed: cannot create directory ' . $destDir);
        return null;
    }
    if (!is_writable($destDir)) {
        $GLOBALS['last_upload_error'] = 'The upload folder is not writable on the server.';
        error_log('upload_file failed: directory is not writable ' . $destDir);
        return null;
    }
    $dest = $destDir . '/' . $name;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/' . trim($folder, '/') . '/' . $name;
    }
    $GLOBALS['last_upload_error'] = 'The server could not move the uploaded file into the upload folder.';
    error_log('upload_file failed: move_uploaded_file failed to ' . $dest . '; tmp=' . ($file['tmp_name'] ?? '') . '; size=' . ($file['size'] ?? ''));
    return null;
}

function set_user_session(array $user): void
{
    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
    $_SESSION['last_activity'] = time();
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64url_decode(string $value)
{
    $padded = strtr($value, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    return base64_decode($padded, true);
}

function auth_token_secret(): string
{
    return defined('AUTH_TOKEN_SECRET') ? AUTH_TOKEN_SECRET : APP_NAME;
}

function create_auth_token(array $user): string
{
    $payload = [
        'sub' => (int)$user['id'],
        'role' => (string)$user['role'],
        'iat' => time(),
        'exp' => time() + (defined('AUTH_TOKEN_TTL_SECONDS') ? AUTH_TOKEN_TTL_SECONDS : SESSION_TIMEOUT_SECONDS),
        'nonce' => bin2hex(random_bytes(12)),
    ];
    $payloadEncoded = base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = hash_hmac('sha256', $payloadEncoded, auth_token_secret(), true);
    return $payloadEncoded . '.' . base64url_encode($signature);
}

function bearer_token(): ?string
{
    $tokenHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (is_string($tokenHeader) && trim($tokenHeader) !== '') {
        return trim($tokenHeader);
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $tokenHeader = $headers['X-Auth-Token'] ?? $headers['x-auth-token'] ?? '';
        if (is_string($tokenHeader) && trim($tokenHeader) !== '') {
            return trim($tokenHeader);
        }
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', (string)$header, $matches)) {
        $queryToken = $_GET['auth_token'] ?? '';
        return is_string($queryToken) && trim($queryToken) !== '' ? trim($queryToken) : null;
    }
    return trim($matches[1]);
}

function auth_bearer_user(): ?array
{
    static $resolved = false;
    static $user = null;

    if ($resolved) {
        return $user;
    }
    $resolved = true;

    $token = bearer_token();
    if (!$token || !str_contains($token, '.')) {
        return null;
    }

    [$payloadEncoded, $signatureEncoded] = explode('.', $token, 2);
    $expectedSignature = base64url_encode(hash_hmac('sha256', $payloadEncoded, auth_token_secret(), true));
    if (!hash_equals($expectedSignature, $signatureEncoded)) {
        return null;
    }

    $payloadJson = base64url_decode($payloadEncoded);
    $payload = $payloadJson !== false ? json_decode($payloadJson, true) : null;
    if (!is_array($payload) || empty($payload['sub']) || empty($payload['exp']) || (int)$payload['exp'] < time()) {
        return null;
    }

    global $pdo;
    if (!isset($pdo) || !$pdo instanceof PDO) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, full_name, email, role, is_active FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int)$payload['sub']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (array_key_exists('is_active', $row) && (int)$row['is_active'] !== 1)) {
        return null;
    }

    $user = [
        'id' => (int)$row['id'],
        'name' => $row['full_name'],
        'email' => $row['email'],
        'role' => $row['role'],
    ];
    return $user;
}

function enforce_session_timeout(): void
{
    if (!empty($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS) {
        session_unset();
        session_destroy();
        session_start();
        if (function_exists('app_is_json_request') && app_is_json_request()) {
            app_json_response(['success' => false, 'message' => 'Session expired. Please login again.'], 401);
        }
        flash('error', 'Session expired. Please login again.');
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();
}
