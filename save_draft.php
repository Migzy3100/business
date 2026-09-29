<?php

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
require_auth(['applicant']);

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$uid = (int)auth_user()['id'];
$businessId = (int)($_POST['business_id'] ?? 0);
$draftId = (int)($_POST['draft_id'] ?? 0);
$draftName = sanitize($_POST['draft_name'] ?? 'Application Draft');
$payload = $_POST['payload'] ?? '';
$lastStep = (int)($_POST['last_step'] ?? 1);
$lastStep = max(1, min(3, $lastStep));

if (!is_string($payload) || trim($payload) === '') {
    echo json_encode(['success' => false, 'message' => 'Draft payload is required.']);
    exit;
}

json_decode($payload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Draft payload must be valid JSON.']);
    exit;
}

try {
    if ($draftId > 0) {
        $checkStmt = $pdo->prepare('SELECT id FROM drafts WHERE id = ? AND user_id = ? LIMIT 1');
        $checkStmt->execute([$draftId, $uid]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Draft not found.']);
            exit;
        }

        $updateStmt = $pdo->prepare('UPDATE drafts SET business_id = ?, draft_name = ?, payload = ?, last_step = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $updateStmt->execute([$businessId > 0 ? $businessId : null, $draftName ?: 'Application Draft', $payload, $lastStep, $draftId, $uid]);
        log_activity($pdo, $uid, 'draft_update', 'Draft ID ' . $draftId);

        echo json_encode(['success' => true, 'message' => 'Draft updated successfully.', 'draft_id' => $draftId]);
        exit;
    }

    $insertStmt = $pdo->prepare('INSERT INTO drafts (user_id, business_id, form_type, draft_name, payload, last_step, created_at, updated_at) VALUES (?, ?, "application", ?, ?, ?, NOW(), NOW())');
    $insertStmt->execute([$uid, $businessId > 0 ? $businessId : null, $draftName ?: 'Application Draft', $payload, $lastStep]);
    $newDraftId = (int)$pdo->lastInsertId();
    log_activity($pdo, $uid, 'draft_create', 'Draft ID ' . $newDraftId);

    echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'draft_id' => $newDraftId]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to save draft.']);
}
