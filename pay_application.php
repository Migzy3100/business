<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
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

$stmt = $pdo->prepare('SELECT a.id, a.reference_no, a.status, ap.payment_status, ap.fee_amount, u.email, u.full_name FROM applications a LEFT JOIN application_payables ap ON ap.application_id=a.id JOIN users u ON u.id=a.user_id WHERE a.id=? AND a.user_id=? LIMIT 1');
$stmt->execute([$applicationId, $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
}
if (($row['status'] ?? '') !== 'awaiting_payment') {
    echo json_encode(['success' => false, 'message' => 'Application is not awaiting payment.']);
    exit;
}
if (($row['payment_status'] ?? '') === '') {
    echo json_encode(['success' => false, 'message' => 'No payable record found for this application.']);
    exit;
}
if (($row['payment_status'] ?? '') === 'paid') {
    echo json_encode(['success' => true, 'message' => 'Payment already marked as paid.']);
    exit;
}

$proofPath = upload_file($_FILES['payment_proof'] ?? [], 'application_payment_proofs', ['jpg', 'jpeg', 'png', 'pdf']);
if (!$proofPath) {
    echo json_encode(['success' => false, 'message' => 'Please upload a valid proof of payment file (JPG, PNG, or PDF).']);
    exit;
}
$proofName = (string)($_FILES['payment_proof']['name'] ?? 'Proof of Payment');

$pdo->prepare('UPDATE application_payables SET payment_status="paid", paid_at=NOW() WHERE application_id=?')->execute([$applicationId]);
$txStmt = $pdo->prepare('
    INSERT INTO payment_transactions (application_id, user_id, amount, channel, source, status, paid_at, recorded_by, notes, proof_file_path, proof_original_name, created_at)
    VALUES (?, ?, ?, "online", "application", "paid", NOW(), ?, ?, ?, ?, NOW())
');
$txStmt->execute([
    $applicationId,
    $uid,
    (float)($row['fee_amount'] ?? 0),
    $uid,
    'User submitted online payment.',
    $proofPath,
    $proofName,
]);
add_notification($pdo, $uid, 'Payment Submitted', 'Payment for application ' . $row['reference_no'] . ' has been marked as paid and is now waiting for final approval.', 'info');
record_application_event($pdo, $applicationId, $uid, $uid, 'payment_submitted', 'Payment Submitted', 'Payment proof was submitted for application ' . $row['reference_no'] . '.', [
    'reference_no' => $row['reference_no'],
    'amount' => (float)($row['fee_amount'] ?? 0),
    'channel' => 'online',
    'proof_original_name' => $proofName,
]);
log_activity($pdo, $uid, 'application_payment_marked', $row['reference_no']);
$feeAmount = (float)($row['fee_amount'] ?? 0);
$content = '<h3>Payment Received</h3><p>Reference: <strong>' . $row['reference_no'] . '</strong></p><p>We have recorded your payment for the application payables.</p><p>Amount Paid: <strong>PHP ' . number_format($feeAmount, 2) . '</strong></p><p>Your application is now waiting for final approval.</p>';
$errorOut = null;
send_system_email(
    $pdo,
    (string)$row['email'],
    'Payment Received - ' . $row['reference_no'],
    render_email_template('Payment Confirmation', $content),
    $uid,
    $errorOut,
    []
);

echo json_encode(['success' => true, 'message' => 'Payment marked as paid.']);
