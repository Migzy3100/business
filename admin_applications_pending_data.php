<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['admin', 'staff']);

$hasPaymentChannelCol = false;
$hasReceiptReferenceCol = false;
try {
    $hasPaymentChannelCol = (int)$pdo->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'application_payables' AND COLUMN_NAME = 'payment_channel'")->fetch()['c'] > 0;
} catch (Throwable $e) {
    $hasPaymentChannelCol = false;
}
try {
    $hasReceiptReferenceCol = (int)$pdo->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'application_payables' AND COLUMN_NAME = 'receipt_reference'")->fetch()['c'] > 0;
} catch (Throwable $e) {
    $hasReceiptReferenceCol = false;
}
$paymentChannelSelect = $hasPaymentChannelCol ? 'ap.payment_channel' : "'online' AS payment_channel";
$receiptReferenceSelect = $hasReceiptReferenceCol ? 'ap.receipt_reference' : 'NULL AS receipt_reference';
$stmt = $pdo->query('
    SELECT
        a.*,
        u.full_name,
        b.business_name AS profile_business_name,
        ap.payment_status,
        ap.fee_amount AS regulatory_fee,
        ap.garbage_fee,
        ap.sanitary_fee,
        ap.fire_safety_fee,
        ap.zoning_fee,
        ap.other_regulatory_fee,
        ap.other_fee_label,
        ap.sent_at AS payables_sent_at,
        ' . $receiptReferenceSelect . ',
        ' . $paymentChannelSelect . ',
        pt.proof_file_path,
        pt.proof_original_name
    FROM applications a
    JOIN users u ON u.id=a.user_id
    JOIN businesses b ON b.id=a.business_id
    LEFT JOIN application_payables ap ON ap.application_id=a.id
    LEFT JOIN (
        SELECT x.application_id, x.proof_file_path, x.proof_original_name
        FROM payment_transactions x
        JOIN (
            SELECT application_id, MAX(id) AS max_id
            FROM payment_transactions
            WHERE source = "application" AND proof_file_path IS NOT NULL AND proof_file_path != ""
            GROUP BY application_id
        ) latest ON latest.max_id = x.id
    ) pt ON pt.application_id = a.id
    WHERE a.status IN ("pending","awaiting_payment")
    ORDER BY a.id DESC
');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $rawStatus = (string)($row['status'] ?? '');
    $isPaidAwaiting = $rawStatus === 'awaiting_payment' && (string)($row['payment_status'] ?? '') === 'paid';
    $row['display_status_label'] = $isPaidAwaiting ? 'Paid' : ucwords(str_replace('_', ' ', $rawStatus));
    $row['display_status_badge'] = $rawStatus === 'awaiting_payment' ? 'bg-success' : 'bg-secondary';
    $row['row_class'] = $rawStatus === 'awaiting_payment' ? 'row-paid-highlight' : '';
}
unset($row);

echo json_encode(['success' => true, 'rows' => $rows]);
