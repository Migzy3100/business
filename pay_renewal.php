<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
header('Content-Type: application/json');
require_auth(['applicant']);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!verify_csrf($in['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$uid = (int)auth_user()['id'];
$applicationId = (int)($in['application_id'] ?? 0);
$gateway = sanitize((string)($in['gateway'] ?? ''));
if (!in_array($gateway, ['gcash', 'paymaya'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment method.']);
    exit;
}
if ($applicationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid application id.']);
    exit;
}

$stmt = $pdo->prepare('
    SELECT a.id, a.reference_no, a.user_id, u.email
    FROM applications a
    JOIN users u ON u.id = a.user_id
    WHERE a.id = ? AND a.user_id = ?
    LIMIT 1
');
$stmt->execute([$applicationId, $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
}

$renewalStmt = $pdo->prepare('SELECT id, status FROM renewals WHERE application_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
$renewalStmt->execute([$applicationId, $uid]);
$renewal = $renewalStmt->fetch(PDO::FETCH_ASSOC);
if (!$renewal || (string)($renewal['status'] ?? '') !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'No pending renewal request found for this permit.']);
    exit;
}

$payableStmt = $pdo->prepare('SELECT payment_status, fee_amount FROM renewal_payables WHERE renewal_id = ? LIMIT 1');
$payableStmt->execute([(int)$renewal['id']]);
$payable = $payableStmt->fetch(PDO::FETCH_ASSOC);
if (!$payable) {
    echo json_encode(['success' => false, 'message' => 'No payable record found for this renewal request.']);
    exit;
}
if (($payable['payment_status'] ?? '') === 'paid') {
    echo json_encode(['success' => true, 'message' => 'Renewal payment already marked as paid.']);
    exit;
}

$pdo->prepare('UPDATE renewal_payables SET payment_status = "paid", paid_at = NOW() WHERE renewal_id = ?')->execute([(int)$renewal['id']]);

$txStmt = $pdo->prepare('
    INSERT INTO payment_transactions (application_id, user_id, amount, channel, source, status, paid_at, recorded_by, notes, created_at)
    VALUES (?, ?, ?, "online", "renewal", "paid", NOW(), ?, ?, NOW())
');
$txStmt->execute([
    $applicationId,
    $uid,
    (float)($payable['fee_amount'] ?? 0),
    $uid,
    'Renewal payment via ' . strtoupper($gateway),
]);
$historyStmt = $pdo->prepare('
    INSERT INTO renewal_history (renewal_id, application_id, user_id, event_type, event_status, actor_user_id, actor_role, notes, metadata_json, created_at)
    VALUES (?, ?, ?, "paid", "pending", ?, "applicant", ?, ?, NOW())
');
$historyStmt->execute([
    (int)$renewal['id'],
    $applicationId,
    $uid,
    $uid,
    'Renewal payment marked paid via ' . strtoupper($gateway) . '.',
    json_encode([
        'gateway' => $gateway,
        'amount' => (float)($payable['fee_amount'] ?? 0),
        'reference_no' => (string)$row['reference_no'],
    ]),
]);

add_notification($pdo, $uid, 'Renewal Payment Submitted', 'Your renewal payment for ' . $row['reference_no'] . ' via ' . strtoupper($gateway) . ' was recorded.', 'info');
log_activity($pdo, $uid, 'renewal_payment_marked', $row['reference_no'] . ' via ' . strtoupper($gateway));

$feeAmount = (float)($payable['fee_amount'] ?? 0);
$content = '<h3>Renewal Payment Received</h3><p>Reference: <strong>' . $row['reference_no'] . '</strong></p><p>Payment Method: <strong>' . strtoupper($gateway) . '</strong></p><p>Amount Paid: <strong>PHP ' . number_format($feeAmount, 2) . '</strong></p>';
$errorOut = null;
send_system_email(
    $pdo,
    (string)$row['email'],
    'Renewal Payment Received - ' . $row['reference_no'],
    render_email_template('Payment Confirmation', $content),
    $uid,
    $errorOut,
    []
);

echo json_encode(['success' => true, 'message' => 'Renewal payment marked as paid via ' . strtoupper($gateway) . '.']);
