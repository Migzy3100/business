<?php

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
require_auth(['applicant']);

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$uid = (int)auth_user()['id'];
$draftId = (int)($_POST['draft_id'] ?? 0);
if ($draftId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid draft id.']);
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM drafts WHERE id = ? AND user_id = ?');
    $stmt->execute([$draftId, $uid]);
    if ($stmt->rowCount() < 1) {
        echo json_encode(['success' => false, 'message' => 'Draft not found.']);
        exit;
    }
    log_activity($pdo, $uid, 'draft_delete', 'Draft ID ' . $draftId);
    echo json_encode(['success' => true, 'message' => 'Draft deleted successfully.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to delete draft.']);
}
