<?php

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
require_auth(['applicant']);

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$uid = (int)auth_user()['id'];

try {
    $stmt = $pdo->prepare('SELECT id, draft_name, payload, last_step, created_at, updated_at FROM drafts WHERE user_id = ? AND form_type = "application" ORDER BY updated_at DESC');
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to load drafts.']);
}
