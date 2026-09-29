<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['admin', 'staff']);

$currentUser = auth_user();
$canManageAccounts = ($currentUser['role'] ?? '') === 'admin';

$hasIsActiveColumn = false;
try {
    $hasIsActiveColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetch();
    if (!$hasIsActiveColumn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_verified");
        $hasIsActiveColumn = true;
    }
} catch (Throwable $e) {
    $hasIsActiveColumn = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    if (!verify_csrf($input['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh and try again.']);
        exit;
    }

    $action = (string)($input['action'] ?? '');
    $id = (int)($input['id'] ?? 0);

    if ($action === 'toggle_user_status') {
        if (!$canManageAccounts) {
            echo json_encode(['success' => false, 'message' => 'Only admin accounts can disable or enable users.']);
            exit;
        }
        if (!$hasIsActiveColumn) {
            echo json_encode(['success' => false, 'message' => 'Account status column is missing. Please run database migration first.']);
            exit;
        }
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user selected.']);
            exit;
        }
        if ($id === (int)($currentUser['id'] ?? 0)) {
            echo json_encode(['success' => false, 'message' => 'You cannot disable your own account.']);
            exit;
        }
        $toggleStmt = $pdo->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
        $toggleStmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'User account status updated.']);
        exit;
    }

    $fullName = trim((string)($input['full_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $role = (string)($input['role'] ?? 'applicant');
    $isVerified = !empty($input['is_verified']) ? 1 : 0;
    $password = (string)($input['password'] ?? '');
    $errors = [];

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if (!in_array($role, ['admin', 'staff', 'applicant'], true)) {
        $errors[] = 'Invalid role selected.';
    }
    if ($action === 'add_user' && strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters for new users.';
    }
    if ($action === 'edit_user' && $id <= 0) {
        $errors[] = 'Invalid user selected.';
    }
    if (!in_array($action, ['add_user', 'edit_user'], true)) {
        $errors[] = 'Unknown action.';
    }
    if ($errors) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    if ($action === 'add_user') {
        $existsStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $existsStmt->execute([$email]);
        if ($existsStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }
        $insertSql = '
            INSERT INTO users (full_name, email, phone, password_hash, role, is_verified, created_at'
            . ($hasIsActiveColumn ? ', is_active' : '') . ')
            VALUES (?, ?, NULL, ?, ?, ?, NOW()' . ($hasIsActiveColumn ? ', 1' : '') . ')
        ';
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $role, $isVerified]);
        echo json_encode(['success' => true, 'message' => 'User created.']);
        exit;
    }

    $existsStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $existsStmt->execute([$email, $id]);
    if ($existsStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already exists.']);
        exit;
    }
    if ($password !== '' && strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        exit;
    }
    $updateSql = 'UPDATE users SET full_name = ?, email = ?, role = ?, is_verified = ?';
    $params = [$fullName, $email, $role, $isVerified];
    if ($password !== '') {
        $updateSql .= ', password_hash = ?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
    }
    $updateSql .= ' WHERE id = ?';
    $params[] = $id;
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($params);
    echo json_encode(['success' => true, 'message' => 'User updated.']);
    exit;
}

$rowsSql = 'SELECT id, full_name, email, role, is_verified, created_at' . ($hasIsActiveColumn ? ', is_active' : ', 1 AS is_active') . ' FROM users ORDER BY id DESC';
$rows = $pdo->query($rowsSql)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'rows' => $rows,
    'can_manage_accounts' => $canManageAccounts,
    'current_user_id' => (int)($currentUser['id'] ?? 0),
]);
