<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['admin', 'staff']);

function admin_renewal_submission_metadata(array $row): array
{
    $decoded = json_decode((string)($row['submitted_metadata'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function admin_renewal_payables_summary(array $metadata, array $row): array
{
    $payables = $metadata['selected_payables'] ?? [];
    if (is_array($payables) && $payables) {
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

    $lines = [];
    $total = 0.0;
    $feeMap = [
        'Garbage Fee' => 'garbage_fee',
        'Sanitary Fee' => 'sanitary_fee',
        'Fire Safety Fee' => 'fire_safety_fee',
        'Zoning Fee' => 'zoning_fee',
        trim((string)($row['other_fee_label'] ?? '')) !== '' ? (string)$row['other_fee_label'] : 'Other Regulatory Fee' => 'other_regulatory_fee',
    ];
    foreach ($feeMap as $label => $key) {
        $amount = (float)($row[$key] ?? 0);
        if ($amount > 0) {
            $lines[] = $label;
            $total += $amount;
        }
    }
    if ($total <= 0 && (float)($row['regulatory_fee'] ?? 0) > 0) {
        $lines[] = 'Regulatory Fee';
        $total = (float)$row['regulatory_fee'];
    }
    return ['names' => $lines ? implode(', ', array_slice($lines, 0, 2)) . (count($lines) > 2 ? ' +' . (count($lines) - 2) . ' more' : '') : '-', 'total' => $total];
}

$rows = $pdo->query('
    SELECT
        r.id,
        r.application_id,
        r.status,
        rp.payment_status,
        rp.fee_amount AS regulatory_fee,
        rp.garbage_fee,
        rp.sanitary_fee,
        rp.fire_safety_fee,
        rp.zoning_fee,
        rp.other_regulatory_fee,
        rp.other_fee_label,
        r.requested_at,
        r.processed_at,
        a.reference_no,
        a.business_name,
        a.expiry_date,
        u.full_name,
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
    JOIN users u ON u.id = r.user_id
    LEFT JOIN renewal_payables rp ON rp.renewal_id = r.id
    ORDER BY r.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $rawRenewalStatus = (string)($row['status'] ?? '');
    $paymentStatus = (string)($row['payment_status'] ?? '');
    $isPaidAwaitingApproval = in_array($rawRenewalStatus, ['pending', 'on_transaction'], true) && $paymentStatus === 'paid';
    $metadata = admin_renewal_submission_metadata($row);
    $row['display_status'] = $isPaidAwaitingApproval ? 'paid_awaiting_approval' : $rawRenewalStatus;
    $row['display_status_label'] = $isPaidAwaitingApproval ? 'Paid (awaiting for approval)' : ($rawRenewalStatus === 'approved' ? 'Renewed' : ucwords(str_replace('_', ' ', $rawRenewalStatus)));
    $row['display_status_badge'] = $isPaidAwaitingApproval ? 'bg-success' : 'bg-secondary';
    $row['payables_summary'] = admin_renewal_payables_summary($metadata, $row);
    $row['payment_proof_path'] = trim((string)($metadata['payment_proof_path'] ?? ''));
    $row['payment_proof_name'] = trim((string)($metadata['payment_proof_name'] ?? 'Proof of Payment'));
    $row['has_application'] = (int)($row['application_id'] ?? 0) > 0;
    $row['control_number'] = trim((string)($metadata['control_number'] ?? ''));
    $row['can_approve_renewal'] = in_array($rawRenewalStatus, ['pending', 'on_transaction'], true);
}
unset($row);

echo json_encode(['success' => true, 'rows' => $rows]);
