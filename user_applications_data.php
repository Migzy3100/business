<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['applicant']);

$userId = (int)(auth_user()['id'] ?? 0);
$biz = $pdo->query('SELECT id FROM businesses WHERE user_id=' . $userId . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);

$hasPayableBreakdownCols = false;
$hasPaymentChannelCol = false;
$hasReceiptReferenceCol = false;
try {
    $colCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'application_payables' AND COLUMN_NAME IN ('garbage_fee','sanitary_fee','fire_safety_fee','zoning_fee','other_regulatory_fee','other_fee_label')")->fetch()['c'];
    $hasPayableBreakdownCols = $colCount >= 6;
} catch (Throwable $e) {
    $hasPayableBreakdownCols = false;
}
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

$payableSelect = $hasPayableBreakdownCols
    ? 'ap.garbage_fee, ap.sanitary_fee, ap.fire_safety_fee, ap.zoning_fee, ap.other_regulatory_fee, ap.other_fee_label'
    : '0.00 AS garbage_fee, 0.00 AS sanitary_fee, 0.00 AS fire_safety_fee, 0.00 AS zoning_fee, 0.00 AS other_regulatory_fee, NULL AS other_fee_label';
$channelSelect = $hasPaymentChannelCol ? 'ap.payment_channel' : "'online' AS payment_channel";
$receiptReferenceExpr = $hasReceiptReferenceCol ? 'ap.receipt_reference' : 'NULL';
$receiptReferenceSelect = $receiptReferenceExpr . ' AS receipt_reference';

$applications = $pdo->query('
    SELECT
        a.id, a.reference_no, a.application_type, a.business_name, a.owner_name, a.status, a.expiry_date, a.created_at,
        COALESCE(NULLIF(a.permit_number, ""), ' . $receiptReferenceExpr . ') AS permit_number,
        ap.fee_amount AS regulatory_fee, ap.payment_status, ' . $payableSelect . ', ' . $channelSelect . ', ' . $receiptReferenceSelect . ',
        af.file_path AS permit_file_path, af.original_name AS permit_file_name
    FROM applications a
    LEFT JOIN application_payables ap ON ap.application_id = a.id
    LEFT JOIN approved_application_files af ON af.application_id = a.id
    WHERE a.user_id=' . $userId . '
    ORDER BY a.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
foreach ($applications as &$row) {
    $rawStatus = (string)($row['status'] ?? '');
    $displayStatus = $rawStatus;
    if ($rawStatus === 'approved' && !empty($row['expiry_date']) && (string)$row['expiry_date'] <= $today) {
        $displayStatus = 'expired';
    }
    $permitNumber = trim((string)($row['permit_number'] ?? ''));
    $row['display_status'] = $displayStatus;
    $row['display_status_label'] = ucwords(str_replace('_', ' ', $displayStatus));
    $row['status_badge_class'] = $displayStatus === 'expired' ? 'bg-danger' : 'bg-secondary';
    $row['row_class'] = trim(($displayStatus === 'expired' ? 'application-row-expired ' : '') . ($permitNumber !== '' ? 'application-row-permit-ready' : ''));
    $row['has_permit_number'] = $permitNumber !== '';
    $row['can_pay'] = $displayStatus === 'awaiting_payment' && (string)($row['payment_status'] ?? '') !== 'paid' && (string)($row['payment_channel'] ?? 'online') === 'online';
    $row['permit_file_url'] = !empty($row['permit_file_path']) ? '../' . ltrim((string)$row['permit_file_path'], '/') : '';
}
unset($row);

echo json_encode([
    'success' => true,
    'business_id' => (int)($biz['id'] ?? 0),
    'applications' => $applications,
]);
