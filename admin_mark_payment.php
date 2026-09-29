<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
header('Content-Type: application/json');
require_auth(['admin', 'staff']);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!verify_csrf($in['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$id = (int)($in['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid application id.']);
    exit;
}

$stmt = $pdo->prepare('
    SELECT a.id, a.reference_no, a.status, a.user_id, u.email, ap.payment_status, ap.fee_amount
    FROM applications a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN application_payables ap ON ap.application_id = a.id
    WHERE a.id = ?
    LIMIT 1
');
$stmt->execute([$id]);
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
    echo json_encode(['success' => true, 'message' => 'Payment is already marked as paid.']);
    exit;
}

$pdo->prepare('UPDATE application_payables SET payment_status="paid", paid_at=NOW() WHERE application_id=?')->execute([$id]);
$txStmt = $pdo->prepare('
    INSERT INTO payment_transactions (application_id, user_id, amount, channel, source, status, paid_at, recorded_by, notes, created_at)
    VALUES (?, ?, ?, "over_the_counter", "application", "paid", NOW(), ?, ?, NOW())
');
$txStmt->execute([
    $id,
    (int)$row['user_id'],
    (float)($row['fee_amount'] ?? 0),
    (int)auth_user()['id'],
    'Payment confirmed over the counter by admin/staff.',
]);

add_notification(
    $pdo,
    (int)$row['user_id'],
    'Payment Confirmed',
    'Your payment for application ' . $row['reference_no'] . ' was confirmed over the counter. The application is now waiting for final approval.',
    'success'
);
record_application_event($pdo, $id, (int)$row['user_id'], (int)auth_user()['id'], 'payment_confirmed', 'Payment Confirmed', 'Over-the-counter payment was confirmed for application ' . $row['reference_no'] . '.', [
    'reference_no' => $row['reference_no'],
    'amount' => (float)($row['fee_amount'] ?? 0),
    'channel' => 'over_the_counter',
]);
log_activity($pdo, (int)auth_user()['id'], 'application_payment_marked_otc', $row['reference_no']);

$feeAmount = (float)($row['fee_amount'] ?? 0);
$content = '<h3>Payment Confirmed</h3><p>Reference: <strong>' . $row['reference_no'] . '</strong></p><p>Your payment was confirmed over the counter.</p><p>Amount Paid: <strong>PHP ' . number_format($feeAmount, 2) . '</strong></p><p>Your application is now waiting for final approval.</p>';
$errorOut = null;
send_system_email(
    $pdo,
    (string)$row['email'],
    'Payment Confirmed - ' . $row['reference_no'],
    render_email_template('Payment Confirmation', $content),
    (int)$row['user_id'],
    $errorOut,
    []
);

echo json_encode(['success' => true, 'message' => 'Payment marked as paid (over the counter).']);
