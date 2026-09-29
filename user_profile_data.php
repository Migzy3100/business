<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['applicant']);

$user = auth_user();
$userId = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    if (!verify_csrf($input['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Please try again.']);
        exit;
    }

    $action = (string)($input['action'] ?? '');
    if ($action === 'update_profile') {
        $fullName = sanitize((string)($input['full_name'] ?? ''));
        $phone = sanitize((string)($input['phone'] ?? ''));
        if ($fullName === '') {
            echo json_encode(['success' => false, 'message' => 'Full name is required.']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?');
        $stmt->execute([$fullName, $phone, $userId]);
        $_SESSION['user']['name'] = $fullName;
        $_SESSION['user']['full_name'] = $fullName;
        log_activity($pdo, $userId, 'profile_update', 'User updated profile details');
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
        exit;
    }

    if ($action === 'change_password') {
        $currentPassword = (string)($input['current_password'] ?? '');
        $newPassword = (string)($input['new_password'] ?? '');
        $confirmPassword = (string)($input['confirm_password'] ?? '');
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || !password_verify($currentPassword, (string)$account['password_hash'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }
        if (strlen($newPassword) < 8) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
            exit;
        }
        if ($newPassword !== $confirmPassword) {
            echo json_encode(['success' => false, 'message' => 'New password and confirm password do not match.']);
            exit;
        }
        if (password_verify($newPassword, (string)$account['password_hash'])) {
            echo json_encode(['success' => false, 'message' => 'Please choose a different password from your current one.']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        log_activity($pdo, $userId, 'password_change', 'User changed account password');
        echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, full_name, email, phone, role, is_verified, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'profile' => $profile]);
