<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_GET['action'] ?? '');
$input = json_decode(file_get_contents('php://input'), true) ?: [];
if ($action === '' && isset($input['action'])) {
    $action = (string)$input['action'];
}

if ($action === 'csrf') {
    app_json_response(['success' => true, 'csrf_token' => csrf_token(), 'user' => auth_user()]);
}

if ($action === 'me') {
    $user = auth_user();
    app_json_response([
        'success' => (bool)$user,
        'user' => $user,
        'csrf_token' => csrf_token(),
        'message' => $user ? 'Authenticated.' : 'Please login again.',
    ], $user ? 200 : 401);
}

if ($action === 'logout') {
    $user = auth_user();
    if ($user) {
        log_activity($pdo, (int)$user['id'], 'logout', 'User logged out');
    }
    session_unset();
    session_destroy();
    session_start();
    app_json_response(['success' => true, 'message' => 'Logged out successfully.', 'csrf_token' => csrf_token()]);
}

if ($action === 'login') {
    $email = sanitize((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        app_json_response(['success' => false, 'message' => 'Invalid credentials.'], 401);
    }
    if (array_key_exists('is_active', $user) && (int)$user['is_active'] !== 1) {
        app_json_response(['success' => false, 'message' => 'Your account is disabled. Please contact the administrator.'], 403);
    }
    if ((int)$user['is_verified'] !== 1) {
        app_json_response(['success' => false, 'message' => 'Please verify your email first.'], 403);
    }

    log_activity($pdo, (int)$user['id'], 'login', 'User login successful');

    $sessionUser = [
        'id' => (int)$user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
    set_user_session($user);

    app_json_response([
        'success' => true,
        'message' => 'Login successful.',
        'user' => $sessionUser,
        'token' => create_auth_token($user),
        'expires_in' => defined('AUTH_TOKEN_TTL_SECONDS') ? AUTH_TOKEN_TTL_SECONDS : SESSION_TIMEOUT_SECONDS,
        'csrf_token' => csrf_token(),
        'redirect' => in_array($sessionUser['role'] ?? '', ['admin', 'staff'], true) ? 'admin/dashboard.php' : 'user/dashboard.php',
    ]);
}

if ($action === 'register') {
    $fullName = sanitize((string)($input['full_name'] ?? ''));
    $phone = sanitize((string)($input['phone'] ?? ''));
    $email = sanitize((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $passwordConfirm = (string)($input['password_confirm'] ?? '');

    if ($fullName === '' || $phone === '' || $email === '' || $password === '') {
        app_json_response(['success' => false, 'message' => 'Please complete all required registration fields.'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        app_json_response(['success' => false, 'message' => 'Please enter a valid email address.'], 422);
    }
    if (strlen($password) < 8) {
        app_json_response(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
    }
    if ($password !== $passwordConfirm) {
        app_json_response(['success' => false, 'message' => 'Passwords do not match.'], 422);
    }

    $exists = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        app_json_response(['success' => false, 'message' => 'Email already exists.'], 409);
    }

    $otp = (string)random_int(100000, 999999);
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO users(full_name,email,phone,password_hash,role,is_verified,otp_code,otp_expires_at,created_at) VALUES(?,?,?,?,"applicant",0,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),NOW())');
        $stmt->execute([$fullName, $email, $phone, $hash, $otp, OTP_EXPIRY_MINUTES]);
        $userId = (int)$pdo->lastInsertId();
        add_notification($pdo, $userId, 'Welcome', 'Registration submitted. Verify your email OTP to activate account.', 'success');
        log_activity($pdo, $userId, 'register', 'New applicant registration');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_json_response(['success' => false, 'message' => 'Registration failed.'], 500);
    }

    $content = '<h3>Verify your account</h3><p>Your OTP is <strong>' . $otp . '</strong>. It expires in ' . OTP_EXPIRY_MINUTES . ' minutes.</p>';
    $mailError = null;
    $sent = send_system_email($pdo, $email, 'Email Verification OTP', render_email_template('Verify Account', $content), $userId, $mailError);

    $message = $sent
        ? 'Registration successful. Check your email for OTP verification.'
        : 'Registration successful, but OTP email was not sent. Please contact support.';

    app_json_response(['success' => true, 'message' => $message, 'redirect' => 'verify.php', 'otp_expires_in' => OTP_EXPIRY_MINUTES * 60]);
}

if ($action === 'resend_otp') {
    $email = sanitize((string)($input['email'] ?? ''));
    $sentMessage = 'A new verification code has been sent to your email.';

    // too_soon: the current code was issued less than a minute ago.
    $stmt = $pdo->prepare('SELECT id, is_verified, (otp_expires_at IS NOT NULL AND otp_expires_at > DATE_ADD(NOW(), INTERVAL ? SECOND)) AS too_soon FROM users WHERE email=? LIMIT 1');
    $stmt->execute([OTP_EXPIRY_MINUTES * 60 - 60, $email]);
    $user = $stmt->fetch();
    // Unknown or already verified emails get the same reply, so this cannot be used to probe accounts.
    if (!$user || (int)$user['is_verified'] === 1) {
        app_json_response(['success' => true, 'message' => $sentMessage, 'otp_expires_in' => OTP_EXPIRY_MINUTES * 60]);
    }
    if ((int)$user['too_soon'] === 1) {
        app_json_response(['success' => false, 'message' => 'Please wait a minute before requesting another code.'], 429);
    }

    $otp = (string)random_int(100000, 999999);
    $pdo->prepare('UPDATE users SET otp_code=?, otp_expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?')
        ->execute([$otp, OTP_EXPIRY_MINUTES, $user['id']]);

    $content = '<h3>Verify your account</h3><p>Your OTP is <strong>' . $otp . '</strong>. It expires in ' . OTP_EXPIRY_MINUTES . ' minutes.</p>';
    $mailError = null;
    $sent = send_system_email($pdo, $email, 'Email Verification OTP', render_email_template('Verify Account', $content), (int)$user['id'], $mailError);
    if (!$sent) {
        app_json_response(['success' => false, 'message' => 'The verification email could not be sent. Please try again later.'], 502);
    }

    app_json_response(['success' => true, 'message' => $sentMessage, 'otp_expires_in' => OTP_EXPIRY_MINUTES * 60]);
}

if ($action === 'verify') {
    $email = sanitize((string)($input['email'] ?? ''));
    $otp = sanitize((string)($input['otp'] ?? ''));

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email=? AND otp_code=? AND otp_expires_at > NOW() LIMIT 1');
    $stmt->execute([$email, $otp]);
    $user = $stmt->fetch();
    if (!$user) {
        app_json_response(['success' => false, 'message' => 'Invalid or expired OTP.'], 422);
    }

    $pdo->prepare('UPDATE users SET is_verified=1, otp_code=NULL, otp_expires_at=NULL WHERE id=?')->execute([$user['id']]);
    app_json_response(['success' => true, 'message' => 'Email verified. You can now login.', 'redirect' => 'login.php']);
}

app_json_response(['success' => false, 'message' => 'Unknown session action.'], 404);
