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

$renewalId = (int)($in['renewal_id'] ?? 0);
$decision = sanitize((string)($in['decision'] ?? ''));
if ($renewalId <= 0 || !in_array($decision, ['approved', 'rejected', 'payables'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid renewal request.']);
    exit;
}

$stmt = $pdo->prepare('
    SELECT
        r.id,
        r.application_id,
        r.user_id,
        r.status,
        a.reference_no,
        a.expiry_date,
        u.email,
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
    WHERE r.id = ?
    LIMIT 1
');
$stmt->execute([$renewalId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Renewal request not found.']);
    exit;
}
if (!in_array((string)$row['status'], ['pending', 'on_transaction'], true)) {
    echo json_encode(['success' => false, 'message' => 'Renewal request is already processed.']);
    exit;
}
$submittedMetadata = json_decode((string)($row['submitted_metadata'] ?? ''), true);
$submittedMetadata = is_array($submittedMetadata) ? $submittedMetadata : [];
$rowApplicationId = !empty($row['application_id']) ? (int)$row['application_id'] : null;
$referenceLabel = (string)($row['reference_no'] ?? '');
if ($referenceLabel === '') {
    $referenceLabel = (string)($submittedMetadata['control_number'] ?? ('Renewal ID ' . $renewalId));
}
if ($decision === 'payables' && $rowApplicationId === null) {
    echo json_encode(['success' => false, 'message' => 'Payables can only be sent for renewals linked to an application.']);
    exit;
}

$toMoney = static function ($v): float {
    $n = is_numeric($v) ? (float)$v : 0.0;
    return $n > 0 ? round($n, 2) : 0.0;
};
$garbageFee = $toMoney($in['garbage_fee'] ?? 0);
$sanitaryFee = $toMoney($in['sanitary_fee'] ?? 0);
$fireSafetyFee = $toMoney($in['fire_safety_fee'] ?? 0);
$zoningFee = $toMoney($in['zoning_fee'] ?? 0);
$otherRegulatoryFee = $toMoney($in['other_regulatory_fee'] ?? 0);
$otherFeeLabel = trim((string)($in['other_fee_label'] ?? ''));
$paymentChannel = sanitize((string)($in['payment_channel'] ?? 'online'));
if (!in_array($paymentChannel, ['online', 'over_the_counter'], true)) {
    $paymentChannel = 'online';
}
$fee = round($garbageFee + $sanitaryFee + $fireSafetyFee + $zoningFee + $otherRegulatoryFee, 2);

try {
    $pdo->beginTransaction();
    if ($decision === 'payables') {
        if ($fee <= 0) {
            throw new RuntimeException('Please enter at least one valid payable amount.');
        }
        $pdo->prepare('INSERT INTO renewal_payables(renewal_id, fee_amount, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, payment_channel, payment_status, sent_at, paid_at, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, "unpaid", NOW(), NULL, NOW()) ON DUPLICATE KEY UPDATE fee_amount=VALUES(fee_amount), garbage_fee=VALUES(garbage_fee), sanitary_fee=VALUES(sanitary_fee), fire_safety_fee=VALUES(fire_safety_fee), zoning_fee=VALUES(zoning_fee), other_regulatory_fee=VALUES(other_regulatory_fee), other_fee_label=VALUES(other_fee_label), payment_channel=VALUES(payment_channel), payment_status="unpaid", sent_at=NOW(), paid_at=NULL')
            ->execute([$renewalId, $fee, $garbageFee, $sanitaryFee, $fireSafetyFee, $zoningFee, $otherRegulatoryFee, $otherFeeLabel !== '' ? $otherFeeLabel : null, $paymentChannel]);
        $payablesHistoryStmt = $pdo->prepare('
            INSERT INTO application_payables_history
            (application_id, renewal_id, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, total_amount, payment_channel, source, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "renewal", ?, NOW())
        ');
        $payablesHistoryStmt->execute([
            $rowApplicationId,
            $renewalId,
            $garbageFee,
            $sanitaryFee,
            $fireSafetyFee,
            $zoningFee,
            $otherRegulatoryFee,
            $otherFeeLabel !== '' ? $otherFeeLabel : null,
            $fee,
            $paymentChannel,
            (int)auth_user()['id'],
        ]);
        $renewalHistoryStmt = $pdo->prepare('
            INSERT INTO renewal_history (renewal_id, application_id, user_id, event_type, event_status, actor_user_id, actor_role, notes, metadata_json, created_at)
            VALUES (?, ?, ?, "payables_sent", "pending", ?, ?, ?, ?, NOW())
        ');
        $renewalHistoryStmt->execute([
            $renewalId,
            $rowApplicationId,
            (int)$row['user_id'],
            (int)auth_user()['id'],
            (string)(auth_user()['role'] ?? 'staff'),
            'Renewal payables sent by admin/staff.',
            json_encode([
                'total_amount' => $fee,
                'payment_channel' => $paymentChannel,
                'garbage_fee' => $garbageFee,
                'sanitary_fee' => $sanitaryFee,
                'fire_safety_fee' => $fireSafetyFee,
                'zoning_fee' => $zoningFee,
                'other_regulatory_fee' => $otherRegulatoryFee,
                'other_fee_label' => $otherFeeLabel,
            ]),
        ]);
        // Keep applications.status independent from renewal workflow.
        // Renewal payables should not alter the parent application status.
    } else {
        $pdo->prepare('UPDATE renewals SET status = ?, processed_at = NOW() WHERE id = ?')->execute([$decision, $renewalId]);

        if ($decision === 'approved') {
            $newExpiry = null;
            if ($rowApplicationId !== null) {
                $today = new DateTimeImmutable('today');
                $baseDate = $today;
                if (!empty($row['expiry_date'])) {
                    $existingExpiry = DateTimeImmutable::createFromFormat('Y-m-d', (string)$row['expiry_date']) ?: null;
                    if ($existingExpiry && $existingExpiry > $today) {
                        $baseDate = $existingExpiry;
                    }
                }
                $newExpiry = $baseDate->modify('+' . (int)PERMIT_VALID_DAYS . ' days')->format('Y-m-d');
                $pdo->prepare('UPDATE applications SET status = "approved", expiry_date = ?, reviewed_at = NOW() WHERE id = ?')
                    ->execute([$newExpiry, $rowApplicationId]);
            }
        } elseif ($decision === 'rejected' && $rowApplicationId !== null) {
            $pdo->prepare('UPDATE applications SET status = "approved", reviewed_at = NOW() WHERE id = ?')
                ->execute([$rowApplicationId]);
        }
        $renewalHistoryStmt = $pdo->prepare('
            INSERT INTO renewal_history (renewal_id, application_id, user_id, event_type, event_status, actor_user_id, actor_role, notes, metadata_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $renewalHistoryStmt->execute([
            $renewalId,
            $rowApplicationId,
            (int)$row['user_id'],
            $decision === 'approved' ? 'approved' : 'rejected',
            $decision,
            (int)auth_user()['id'],
            (string)(auth_user()['role'] ?? 'staff'),
            $decision === 'approved' ? 'Renewal approved by admin/staff.' : 'Renewal rejected by admin/staff.',
            json_encode([
                'reference_no' => $referenceLabel,
                'new_expiry_date' => $decision === 'approved' ? ($newExpiry ?? null) : null,
            ]),
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Failed to process renewal decision.']);
    exit;
}

$title = $decision === 'approved' ? 'Renewal Approved' : ($decision === 'rejected' ? 'Renewal Rejected' : 'Renewal Payables Sent');
$message = $decision === 'payables'
    ? 'Payables for your renewal request (' . $referenceLabel . ') have been sent.'
    : 'Your renewal request for ' . $referenceLabel . ' was ' . $decision . '.';
add_notification($pdo, (int)$row['user_id'], $title, $message, $decision === 'approved' ? 'success' : ($decision === 'payables' ? 'warning' : 'danger'));
record_application_event($pdo, $rowApplicationId, (int)$row['user_id'], (int)auth_user()['id'], 'renewal_' . $decision, $title, $message, [
    'renewal_id' => $renewalId,
    'application_id' => $rowApplicationId,
    'reference_no' => $referenceLabel,
    'decision' => $decision,
    'new_expiry_date' => $decision === 'approved' ? ($newExpiry ?? null) : null,
]);
log_activity($pdo, (int)auth_user()['id'], 'renewal_' . $decision, 'Renewal ID ' . $renewalId . ' / ' . $referenceLabel);

$content = $decision === 'approved'
    ? '<h3>Renewal Approved</h3><p>Reference: <strong>' . $referenceLabel . '</strong></p><p>Your renewal request has been approved.</p>'
    : ($decision === 'rejected'
        ? '<h3>Renewal Rejected</h3><p>Reference: <strong>' . $referenceLabel . '</strong></p><p>Your renewal request has been rejected.</p>'
        : '<h3>Renewal Payables Sent</h3><p>Reference: <strong>' . $referenceLabel . '</strong></p><p>Total Payables: <strong>PHP ' . number_format($fee, 2) . '</strong></p><p>Your renewal is now awaiting payment.</p>');
$errorOut = null;
$emailAttachments = [];
if ($decision === 'approved' && $rowApplicationId !== null) {
    $savedStmt = $pdo->prepare('SELECT file_path, original_name FROM approved_application_files WHERE application_id=? LIMIT 1');
    $savedStmt->execute([$rowApplicationId]);
    $savedFile = $savedStmt->fetch(PDO::FETCH_ASSOC);
    if ($savedFile && !empty($savedFile['file_path'])) {
        $savedAbsPath = resolve_upload_path((string)$savedFile['file_path']);
        if ($savedAbsPath && is_file($savedAbsPath)) {
            $emailAttachments[] = [
                'path' => $savedAbsPath,
                'name' => (string)($savedFile['original_name'] ?? ('Business_Permit_' . (string)$row['reference_no'] . '.jpg')),
            ];
        }
    }
}
send_system_email($pdo, (string)$row['email'], $title, render_email_template('Renewal Update', $content), (int)$row['user_id'], $errorOut, $emailAttachments);

echo json_encode(['success' => true, 'message' => $decision === 'payables' ? 'Renewal payables sent successfully.' : ('Renewal request ' . $decision . ' successfully.')]);
