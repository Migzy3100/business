<?php

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
require_auth(['applicant']);
$isMultipart = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
$input = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
if (!verify_csrf($input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false,'message' => 'Invalid CSRF']);
    exit;
}
$uid = (int)auth_user()['id'];
$appId = (int)($input['application_id'] ?? 0);
$controlNumber = trim((string)($input['control_number'] ?? ''));
$selectedPayables = [];
$selectedPayablesRaw = trim((string)($input['selected_payables_json'] ?? ''));
if ($selectedPayablesRaw !== '') {
    $decodedPayables = json_decode($selectedPayablesRaw, true);
    if (!is_array($decodedPayables)) {
        echo json_encode(['success' => false, 'message' => 'Invalid selected payables.']);
        exit;
    }
    $selectedPayables = $decodedPayables;
}
$paymentProofPath = null;
$paymentProofName = null;
if ($isMultipart && isset($_FILES['payment_proof']) && (int)($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $paymentProofPath = upload_file($_FILES['payment_proof'], 'renewal_payment_proofs', ['jpg', 'jpeg', 'png', 'pdf']);
    if (!$paymentProofPath) {
        echo json_encode(['success' => false, 'message' => 'Proof of payment upload failed. Please upload a JPG, PNG, or PDF file.']);
        exit;
    }
    $paymentProofName = (string)($_FILES['payment_proof']['name'] ?? 'Proof of Payment');
}
if ($selectedPayables && !$paymentProofPath) {
    echo json_encode(['success' => false, 'message' => 'Please upload proof of payment for the selected payables.']);
    exit;
}
$app = null;
if ($appId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM applications WHERE id=? AND user_id=? AND status="approved"');
    $stmt->execute([$appId,$uid]);
    $app = $stmt->fetch();
    if (!$app) {
        echo json_encode(['success' => false,'message' => 'Approved permit not found.']);
        exit;
    }
} elseif ($controlNumber === '') {
    echo json_encode(['success' => false, 'message' => 'Enter a CN or control number before submitting renewal.']);
    exit;
}
$renewalAppId = $app ? $appId : null;
try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO renewals(application_id,user_id,status,requested_at) VALUES(?,?,"on_transaction",NOW())')->execute([$renewalAppId,$uid]);
    $renewalId = (int)$pdo->lastInsertId();
    if ($renewalAppId !== null) {
        $pdo->prepare('UPDATE applications SET status="awaiting_renewal" WHERE id=? AND user_id=?')->execute([$renewalAppId, $uid]);
    }
    $historyMetadata = [
        'application_status' => (string)($app['status'] ?? 'manual_cn'),
        'control_number' => $controlNumber,
        'selected_payables' => $selectedPayables,
        'payment_proof_path' => $paymentProofPath,
        'payment_proof_name' => $paymentProofName,
    ];
    $historyStmt = $pdo->prepare('
        INSERT INTO renewal_history (renewal_id, application_id, user_id, event_type, event_status, actor_user_id, actor_role, notes, metadata_json, created_at)
        VALUES (?, ?, ?, "submitted", "on_transaction", ?, "applicant", ?, ?, NOW())
    ');
    $historyStmt->execute([
        $renewalId,
        $renewalAppId,
        $uid,
        $uid,
        'Renewal request submitted by applicant.',
        json_encode($historyMetadata),
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Failed to submit renewal request. Please make sure the renewal status migration has been applied.']);
    exit;
}
add_notification($pdo, $uid, 'Renewal Submitted', 'Your renewal request is now on transaction.', 'info');
add_role_notifications($pdo, ['admin', 'staff'], 'New Renewal Submitted', 'A renewal request from ' . (auth_user()['name'] ?? 'an applicant') . ' is ready for review.', 'info');
record_application_event($pdo, $renewalAppId, $uid, $uid, 'renewal_submitted', 'Renewal Submitted', 'Renewal request is now on transaction.', [
    'renewal_id' => $renewalId,
    'application_id' => $renewalAppId,
    'control_number' => $controlNumber,
    'status' => 'on_transaction',
]);
log_activity($pdo, $uid, 'renewal_submit', $app ? ('Application ID ' . $appId) : ('CN ' . $controlNumber));
echo json_encode(['success' => true,'message' => 'Renewal request submitted. Status: On Transaction.']);
