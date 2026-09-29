<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['applicant']);

$userId = (int)(auth_user()['id'] ?? 0);
try {
    $hasPermitNumberCol = (int)$pdo->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'permit_number'")->fetch()['c'] > 0;
} catch (Throwable $e) {
    $hasPermitNumberCol = false;
}
try {
    $hasReceiptReferenceCol = (int)$pdo->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'application_payables' AND COLUMN_NAME = 'receipt_reference'")->fetch()['c'] > 0;
} catch (Throwable $e) {
    $hasReceiptReferenceCol = false;
}
$controlNumberParts = [];
if ($hasPermitNumberCol) $controlNumberParts[] = 'NULLIF(a.permit_number, "")';
if ($hasReceiptReferenceCol) $controlNumberParts[] = 'NULLIF(ap.receipt_reference, "")';
$controlNumberExpr = $controlNumberParts ? 'COALESCE(' . implode(', ', $controlNumberParts) . ')' : 'NULL';

$apps = $pdo->query('
    SELECT
        a.id, a.reference_no, a.business_name, a.status, a.permit_no, a.expiry_date, a.created_at,
        ' . $controlNumberExpr . ' AS control_number,
        af.file_path AS permit_file_path,
        af.original_name AS permit_file_name
    FROM applications a
    LEFT JOIN application_payables ap ON ap.application_id = a.id
    LEFT JOIN approved_application_files af ON af.application_id = a.id
    WHERE a.user_id=' . $userId . '
      AND a.status="approved"
      AND ' . $controlNumberExpr . ' IS NOT NULL
      AND ' . $controlNumberExpr . ' <> ""
    ORDER BY a.created_at DESC
')->fetchAll(PDO::FETCH_ASSOC);

$renewals = $pdo->query('
    SELECT
        r.id, r.application_id, a.reference_no, r.status, r.requested_at, r.processed_at,
        a.business_name, a.permit_no, a.expiry_date,
        rp.payment_status, rp.fee_amount AS regulatory_fee, rp.garbage_fee, rp.sanitary_fee,
        rp.fire_safety_fee, rp.zoning_fee, rp.other_regulatory_fee, rp.other_fee_label,
        (
            SELECT rh.metadata_json
            FROM renewal_history rh
            WHERE rh.renewal_id = r.id
              AND rh.event_type = "submitted"
            ORDER BY rh.id DESC
            LIMIT 1
        ) AS submitted_metadata
    FROM renewals r
    LEFT JOIN applications a ON a.id = r.application_id
    LEFT JOIN renewal_payables rp ON rp.renewal_id = r.id
    WHERE r.user_id=' . $userId . '
    ORDER BY r.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

function user_renewal_metadata(array $row): array
{
    $decoded = json_decode((string)($row['submitted_metadata'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function user_renewal_payables_summary(array $metadata): array
{
    $payables = $metadata['selected_payables'] ?? [];
    if (!is_array($payables) || !$payables) return ['names' => '-', 'total' => 0.0];
    $names = [];
    $total = 0.0;
    foreach ($payables as $payable) {
        if (!is_array($payable)) continue;
        $name = trim((string)($payable['name'] ?? ''));
        if ($name !== '') $names[] = $name;
        $total += (float)($payable['total'] ?? 0);
    }
    return ['names' => $names ? implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' +' . (count($names) - 2) . ' more' : '') : '-', 'total' => $total];
}

$pendingRenewalPayablesByAppId = [];
foreach ($renewals as $row) {
    if ((string)($row['status'] ?? '') === 'pending' && (int)($row['application_id'] ?? 0) > 0 && !isset($pendingRenewalPayablesByAppId[(int)$row['application_id']])) {
        $pendingRenewalPayablesByAppId[(int)$row['application_id']] = $row;
    }
}

foreach ($apps as &$app) {
    $renewPayable = $pendingRenewalPayablesByAppId[(int)$app['id']] ?? [];
    $app['display_status'] = 'approved';
    $app['display_status_label'] = 'Approved';
    $app['badge_class'] = 'bg-success';
    $app['permit_file_url'] = !empty($app['permit_file_path']) ? '../' . ltrim((string)$app['permit_file_path'], '/') : '';
    $app['renew_payable'] = [
        'regulatory_fee' => $renewPayable['regulatory_fee'] ?? '0.00',
        'garbage_fee' => $renewPayable['garbage_fee'] ?? '0.00',
        'sanitary_fee' => $renewPayable['sanitary_fee'] ?? '0.00',
        'fire_safety_fee' => $renewPayable['fire_safety_fee'] ?? '0.00',
        'zoning_fee' => $renewPayable['zoning_fee'] ?? '0.00',
        'other_regulatory_fee' => $renewPayable['other_regulatory_fee'] ?? '0.00',
        'other_fee_label' => $renewPayable['other_fee_label'] ?? '',
    ];
}
unset($app);

$history = [];
foreach ($renewals as $row) {
    if (!in_array((string)($row['status'] ?? ''), ['approved', 'rejected'], true)) continue;
    $metadata = user_renewal_metadata($row);
    $summary = user_renewal_payables_summary($metadata);
    $history[] = [
        'renewal_id' => (int)$row['id'],
        'renewal_status' => (string)$row['status'],
        'renewal_status_label' => ucwords(str_replace('_', ' ', (string)$row['status'])),
        'requested_at' => $row['requested_at'],
        'processed_at' => $row['processed_at'],
        'reference_no' => $row['reference_no'] ?? 'Manual CN',
        'business_name' => $row['business_name'] ?? 'Manual CN Renewal',
        'permit_no' => $row['permit_no'] ?? '-',
        'expiry_date' => $row['expiry_date'] ?? '-',
        'control_number' => $metadata['control_number'] ?? '-',
        'payables_summary' => $summary,
        'payment_proof_path' => trim((string)($metadata['payment_proof_path'] ?? '')),
        'payment_proof_name' => trim((string)($metadata['payment_proof_name'] ?? 'Proof of Payment')),
    ];
}

echo json_encode(['success' => true, 'apps' => $apps, 'history' => $history]);
