<?php

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
require_auth(['applicant']);

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$uid = (int)auth_user()['id'];
$applicationId = (int)($_POST['application_id'] ?? 0);
if ($applicationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid application id.']);
    exit;
}

$checkStmt = $pdo->prepare('SELECT id, reference_no, status FROM applications WHERE id = ? AND user_id = ? LIMIT 1');
$checkStmt->execute([$applicationId, $uid]);
$current = $checkStmt->fetch(PDO::FETCH_ASSOC);
if (!$current) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
}

if (($current['status'] ?? '') === 'approved') {
    echo json_encode(['success' => false, 'message' => 'Approved applications cannot be deleted.']);
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM application_document_sets WHERE application_id = ?')->execute([$applicationId]);
    $pdo->prepare('DELETE FROM application_files WHERE application_id = ?')->execute([$applicationId]);
    record_application_event($pdo, $applicationId, $uid, $uid, 'application_deleted', 'Application Deleted', 'Application ' . (string)($current['reference_no'] ?? $applicationId) . ' was deleted by the applicant.', [
        'reference_no' => (string)($current['reference_no'] ?? ''),
        'status' => (string)($current['status'] ?? ''),
    ]);
    $pdo->prepare('DELETE FROM applications WHERE id = ? AND user_id = ?')->execute([$applicationId, $uid]);
    log_activity($pdo, $uid, 'application_delete', (string)($current['reference_no'] ?? $applicationId));
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Application deleted successfully.']);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Failed to delete application.']);
}
