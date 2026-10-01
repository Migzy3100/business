<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/permit_renderer.php';
header('Content-Type: application/json');
require_auth(['admin','staff']);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!verify_csrf($in['csrf_token'] ?? '')) {
    echo json_encode(['success' => false,'message' => 'Invalid CSRF']);
    exit;
}
$id = (int)($in['id'] ?? 0);
$status = sanitize($in['status'] ?? '');
if (!in_array($status, ['awaiting_payment','approved','rejected','over_the_counter_mark_paid','set_permit_number','set_control_number'], true)) {
    echo json_encode(['success' => false,'message' => 'Invalid status']);
    exit;
}
$stmt = $pdo->prepare('SELECT a.*,u.email,u.full_name,ap.fee_amount AS regulatory_fee,ap.payment_status,ap.sent_at AS payables_sent_at,ap.paid_at AS payment_paid_at FROM applications a JOIN users u ON u.id=a.user_id LEFT JOIN application_payables ap ON ap.application_id=a.id WHERE a.id=?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    echo json_encode(['success' => false,'message' => 'Application not found']);
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
$hasPaymentChannelCol = false;
try {
    $hasPaymentChannelCol = (int)$pdo->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'application_payables' AND COLUMN_NAME = 'payment_channel'")->fetch()['c'] > 0;
} catch (Throwable $e) {
    $hasPaymentChannelCol = false;
}
$fee = round($garbageFee + $sanitaryFee + $fireSafetyFee + $zoningFee + $otherRegulatoryFee, 2);
$receiptReference = 'RCPT-' . date('Ymd') . '-' . random_int(1000, 9999);
$savedPermitNumber = trim((string)($row['permit_number'] ?? ''));
$canApprove = (($row['status'] ?? '') === 'awaiting_payment' && (($row['payment_status'] ?? '') === 'paid' || $savedPermitNumber !== ''));
$canSendPayables = (($row['status'] ?? '') === 'pending');
if ($status === 'set_permit_number') {
    $permitNumber = trim((string)($in['permit_number'] ?? ''));
    if ($permitNumber === '') {
        echo json_encode(['success' => false, 'message' => 'Business permit number is required.']);
        exit;
    }
    $pdo->prepare('UPDATE applications SET permit_number=? WHERE id=?')->execute([$permitNumber, $id]);
    record_application_event($pdo, $id, (int)$row['user_id'], (int)auth_user()['id'], 'permit_number_set', 'Permit Number Saved', 'Permit number was saved for application ' . $row['reference_no'] . '.', [
        'reference_no' => $row['reference_no'],
        'permit_number' => $permitNumber,
    ]);
    log_activity($pdo, (int)auth_user()['id'], 'application_permit_number_set', $row['reference_no'] . ' => ' . $permitNumber);
    echo json_encode(['success' => true, 'message' => 'Business permit number saved.']);
    exit;
}
if ($status === 'set_control_number') {
    if (!$canSendPayables) {
        echo json_encode(['success' => false, 'message' => 'Control number can only be set for pending applications.']);
        exit;
    }
    $controlNumber = trim((string)($in['control_number'] ?? ''));
    if ($controlNumber === '') {
        echo json_encode(['success' => false, 'message' => 'Control number is required.']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE applications SET status="awaiting_payment", permit_number=?, reviewed_at=NOW() WHERE id=?')->execute([$controlNumber, $id]);
        if ($hasPaymentChannelCol) {
            $pdo->prepare('INSERT INTO application_payables(application_id, receipt_reference, fee_amount, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, payment_channel, payment_status, sent_at, paid_at, created_at) VALUES(?, ?, 0, 0, 0, 0, 0, 0, NULL, "online", "unpaid", NOW(), NULL, NOW()) ON DUPLICATE KEY UPDATE receipt_reference=VALUES(receipt_reference), fee_amount=0, garbage_fee=0, sanitary_fee=0, fire_safety_fee=0, zoning_fee=0, other_regulatory_fee=0, other_fee_label=NULL, payment_channel="online", payment_status="unpaid", sent_at=NOW(), paid_at=NULL')
                ->execute([$id, $controlNumber]);
        } else {
            $pdo->prepare('INSERT INTO application_payables(application_id, receipt_reference, fee_amount, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, payment_status, sent_at, paid_at, created_at) VALUES(?, ?, 0, 0, 0, 0, 0, 0, NULL, "unpaid", NOW(), NULL, NOW()) ON DUPLICATE KEY UPDATE receipt_reference=VALUES(receipt_reference), fee_amount=0, garbage_fee=0, sanitary_fee=0, fire_safety_fee=0, zoning_fee=0, other_regulatory_fee=0, other_fee_label=NULL, payment_status="unpaid", sent_at=NOW(), paid_at=NULL')
                ->execute([$id, $controlNumber]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Failed to save control number.']);
        exit;
    }
    add_notification($pdo, (int)$row['user_id'], 'Control Number Added', 'Your application ' . $row['reference_no'] . ' is awaiting payment. Control number: ' . $controlNumber . '.', 'warning');
    record_application_event($pdo, $id, (int)$row['user_id'], (int)auth_user()['id'], 'control_number_added', 'Control Number Added', 'Application ' . $row['reference_no'] . ' is awaiting payment.', [
        'reference_no' => $row['reference_no'],
        'control_number' => $controlNumber,
        'status' => 'awaiting_payment',
    ]);
    log_activity($pdo, (int)auth_user()['id'], 'application_control_number_set', $row['reference_no'] . ' / ' . $controlNumber);
    echo json_encode(['success' => true, 'message' => 'Control number saved. Application is now awaiting payment.']);
    exit;
}
if ($status === 'awaiting_payment' || $status === 'over_the_counter_mark_paid') {
    if (!$canSendPayables) {
        echo json_encode(['success' => false, 'message' => 'Payables can only be sent for pending applications.']);
        exit;
    }
    if ($fee <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please enter at least one valid payable amount.']);
        exit;
    }
    $effectivePaymentChannel = $status === 'over_the_counter_mark_paid' ? 'over_the_counter' : $paymentChannel;
    try {
        $pdo->beginTransaction();
        if ($status === 'over_the_counter_mark_paid') {
            $pdo->prepare('UPDATE applications SET status="awaiting_payment", reviewed_at=NOW() WHERE id=?')
                ->execute([$id]);
            if ($hasPaymentChannelCol) {
                $pdo->prepare('INSERT INTO application_payables(application_id, receipt_reference, fee_amount, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, payment_channel, payment_status, sent_at, paid_at, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, "over_the_counter", "unpaid", NOW(), NULL, NOW()) ON DUPLICATE KEY UPDATE receipt_reference=VALUES(receipt_reference), fee_amount=VALUES(fee_amount), garbage_fee=VALUES(garbage_fee), sanitary_fee=VALUES(sanitary_fee), fire_safety_fee=VALUES(fire_safety_fee), zoning_fee=VALUES(zoning_fee), other_regulatory_fee=VALUES(other_regulatory_fee), other_fee_label=VALUES(other_fee_label), payment_channel="over_the_counter", payment_status="unpaid", sent_at=NOW(), paid_at=NULL')
                    ->execute([$id, $receiptReference, $fee, $garbageFee, $sanitaryFee, $fireSafetyFee, $zoningFee, $otherRegulatoryFee, $otherFeeLabel !== '' ? $otherFeeLabel : null]);
            } else {
                $pdo->prepare('INSERT INTO application_payables(application_id, receipt_reference, fee_amount, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, payment_status, sent_at, paid_at, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, "unpaid", NOW(), NULL, NOW()) ON DUPLICATE KEY UPDATE receipt_reference=VALUES(receipt_reference), fee_amount=VALUES(fee_amount), garbage_fee=VALUES(garbage_fee), sanitary_fee=VALUES(sanitary_fee), fire_safety_fee=VALUES(fire_safety_fee), zoning_fee=VALUES(zoning_fee), other_regulatory_fee=VALUES(other_regulatory_fee), other_fee_label=VALUES(other_fee_label), payment_status="unpaid", sent_at=NOW(), paid_at=NULL')
                    ->execute([$id, $receiptReference, $fee, $garbageFee, $sanitaryFee, $fireSafetyFee, $zoningFee, $otherRegulatoryFee, $otherFeeLabel !== '' ? $otherFeeLabel : null]);
            }
        } else {
            $pdo->prepare('UPDATE applications SET status="awaiting_payment", reviewed_at=NOW() WHERE id=?')
                ->execute([$id]);
            if ($hasPaymentChannelCol) {
                $pdo->prepare('INSERT INTO application_payables(application_id, receipt_reference, fee_amount, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, payment_channel, payment_status, sent_at, paid_at, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "unpaid", NOW(), NULL, NOW()) ON DUPLICATE KEY UPDATE receipt_reference=VALUES(receipt_reference), fee_amount=VALUES(fee_amount), garbage_fee=VALUES(garbage_fee), sanitary_fee=VALUES(sanitary_fee), fire_safety_fee=VALUES(fire_safety_fee), zoning_fee=VALUES(zoning_fee), other_regulatory_fee=VALUES(other_regulatory_fee), other_fee_label=VALUES(other_fee_label), payment_channel=VALUES(payment_channel), payment_status="unpaid", sent_at=NOW(), paid_at=NULL')
                    ->execute([$id, $receiptReference, $fee, $garbageFee, $sanitaryFee, $fireSafetyFee, $zoningFee, $otherRegulatoryFee, $otherFeeLabel !== '' ? $otherFeeLabel : null, $paymentChannel]);
            } else {
                $pdo->prepare('INSERT INTO application_payables(application_id, receipt_reference, fee_amount, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, payment_status, sent_at, paid_at, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, "unpaid", NOW(), NULL, NOW()) ON DUPLICATE KEY UPDATE receipt_reference=VALUES(receipt_reference), fee_amount=VALUES(fee_amount), garbage_fee=VALUES(garbage_fee), sanitary_fee=VALUES(sanitary_fee), fire_safety_fee=VALUES(fire_safety_fee), zoning_fee=VALUES(zoning_fee), other_regulatory_fee=VALUES(other_regulatory_fee), other_fee_label=VALUES(other_fee_label), payment_status="unpaid", sent_at=NOW(), paid_at=NULL')
                    ->execute([$id, $receiptReference, $fee, $garbageFee, $sanitaryFee, $fireSafetyFee, $zoningFee, $otherRegulatoryFee, $otherFeeLabel !== '' ? $otherFeeLabel : null]);
            }
        }
        $payablesHistoryStmt = $pdo->prepare('
            INSERT INTO application_payables_history
            (application_id, renewal_id, receipt_reference, garbage_fee, sanitary_fee, fire_safety_fee, zoning_fee, other_regulatory_fee, other_fee_label, total_amount, payment_channel, source, created_by, created_at)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, "application", ?, NOW())
        ');
        $payablesHistoryStmt->execute([
            $id,
            $receiptReference,
            $garbageFee,
            $sanitaryFee,
            $fireSafetyFee,
            $zoningFee,
            $otherRegulatoryFee,
            $otherFeeLabel !== '' ? $otherFeeLabel : null,
            $fee,
            $effectivePaymentChannel,
            (int)auth_user()['id'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Failed to save payables.']);
        exit;
    }
    if ($effectivePaymentChannel === 'over_the_counter') {
        add_notification($pdo, (int)$row['user_id'], 'Payables Sent', 'Your application ' . $row['reference_no'] . ' is awaiting over-the-counter payment. Receipt reference: ' . $receiptReference . '.', 'warning');
        record_application_event($pdo, $id, (int)$row['user_id'], (int)auth_user()['id'], 'payables_sent', 'Payables Sent', 'Over-the-counter payables were sent for application ' . $row['reference_no'] . '.', [
            'reference_no' => $row['reference_no'],
            'receipt_reference' => $receiptReference,
            'payment_channel' => $effectivePaymentChannel,
            'total_amount' => $fee,
            'status' => 'awaiting_payment',
        ]);
        log_activity($pdo, (int)auth_user()['id'], 'application_awaiting_payment_otc', $row['reference_no'] . ' / ' . $receiptReference);
        $content = '<h3>Payables Receipt Generated</h3><p>Application Reference: <strong>' . $row['reference_no'] . '</strong></p><p>Receipt Reference: <strong>' . $receiptReference . '</strong></p><p>Payment Method: <strong>Over the Counter</strong></p><p>Total Payables: <strong>PHP ' . number_format($fee, 2) . '</strong></p><p>Your application is now <strong>Awaiting Payment</strong>. Please settle this amount over the counter.</p>';
        $errorOut = null;
        send_system_email($pdo, $row['email'], 'Payables Receipt Generated', render_email_template('Application Update', $content), (int)$row['user_id'], $errorOut, []);
        echo json_encode([
            'success' => true,
            'message' => 'Over-the-counter receipt generated. Reference: ' . $receiptReference,
            'receipt' => [
                'receipt_reference' => $receiptReference,
                'application_reference' => (string)$row['reference_no'],
                'business_name' => (string)($row['business_name'] ?? ''),
                'owner_name' => (string)($row['owner_name'] ?? ''),
                'payment_method' => 'Over the Counter',
                'total_amount' => $fee,
                'garbage_fee' => $garbageFee,
                'sanitary_fee' => $sanitaryFee,
                'fire_safety_fee' => $fireSafetyFee,
                'zoning_fee' => $zoningFee,
                'other_regulatory_fee' => $otherRegulatoryFee,
                'other_fee_label' => $otherFeeLabel,
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    } else {
        add_notification($pdo, (int)$row['user_id'], 'Payables Sent', 'Your application ' . $row['reference_no'] . ' is awaiting payment. Receipt reference: ' . $receiptReference . '. Regulatory fee: PHP ' . number_format($fee, 2) . '.', 'warning');
        record_application_event($pdo, $id, (int)$row['user_id'], (int)auth_user()['id'], 'payables_sent', 'Payables Sent', 'Online payables were sent for application ' . $row['reference_no'] . '.', [
            'reference_no' => $row['reference_no'],
            'receipt_reference' => $receiptReference,
            'payment_channel' => $effectivePaymentChannel,
            'total_amount' => $fee,
            'status' => 'awaiting_payment',
        ]);
        log_activity($pdo, (int)auth_user()['id'], 'application_awaiting_payment', $row['reference_no'] . ' / ' . $receiptReference);
        $channelLabel = $paymentChannel === 'over_the_counter' ? 'Over the Counter' : 'Online';
        $content = '<h3>Payables Sent</h3><p>Application Reference: <strong>' . $row['reference_no'] . '</strong></p><p>Receipt Reference: <strong>' . $receiptReference . '</strong></p><p>Your application is now <strong>Awaiting Payment</strong>.</p><p>Total Payables: <strong>PHP ' . number_format($fee, 2) . '</strong></p><p>Payment Method: <strong>' . $channelLabel . '</strong></p><p>Please settle the fee so we can proceed to final approval.</p>';
        $errorOut = null;
        send_system_email($pdo, $row['email'], 'Application Awaiting Payment', render_email_template('Application Update', $content), (int)$row['user_id'], $errorOut, []);
        echo json_encode(['success' => true, 'message' => 'Payables sent successfully. Receipt reference: ' . $receiptReference]);
    }
    exit;
}
if ($status === 'approved' && !$canApprove) {
    echo json_encode(['success' => false, 'message' => 'Application can only be approved after payment is marked as paid.']);
    exit;
}
$exp = $status === 'approved' ? date('Y-m-d', strtotime('+' . PERMIT_VALID_DAYS . ' days')) : null;
$permitNo = $status === 'approved'
    ? ($savedPermitNumber !== '' ? $savedPermitNumber : ('PRM-' . date('Ymd') . '-' . random_int(1000, 9999)))
    : null;
$qr = $status === 'approved' ? APP_URL . '/verify-permit.php?ref=' . $row['reference_no'] : null;
$pdo->prepare('UPDATE applications SET status=?, reviewed_at=NOW(), expiry_date=?, permit_no=?, qr_token=? WHERE id=?')->execute([$status,$exp,$permitNo,$qr,$id]);

$emailAttachments = [];
if ($status === 'approved') {
    $savedStmt = $pdo->prepare('SELECT file_path, original_name FROM approved_application_files WHERE application_id=? LIMIT 1');
    $savedStmt->execute([$id]);
    $savedFile = $savedStmt->fetch(PDO::FETCH_ASSOC);
    $savedAbsPath = ($savedFile && !empty($savedFile['file_path'])) ? resolve_upload_path((string)$savedFile['file_path']) : null;
    if ($savedAbsPath === null) {
        // No digital copy yet: generate it now so the approval email always carries the permit.
        $permitError = null;
        $generatedPath = generate_permit_file($pdo, $row, $permitError);
        if ($generatedPath !== null) {
            $savedFile = ['file_path' => $generatedPath, 'original_name' => 'Business_Permit_' . (string)$row['reference_no'] . '.jpg'];
            $savedAbsPath = resolve_upload_path($generatedPath);
        } else {
            error_log('Permit auto-generation failed for ' . $row['reference_no'] . ': ' . $permitError);
        }
    }
    if ($savedAbsPath !== null) {
        $emailAttachments[] = [
            'path' => $savedAbsPath,
            'name' => (string)($savedFile['original_name'] ?? ('Business_Permit_' . (string)$row['reference_no'] . '.jpg')),
        ];
    }
}
add_notification($pdo, (int)$row['user_id'], 'Application ' . ucfirst($status), 'Your application ' . $row['reference_no'] . ' was ' . $status . '.', $status === 'approved' ? 'success' : 'danger');
record_application_event($pdo, $id, (int)$row['user_id'], (int)auth_user()['id'], 'application_' . $status, 'Application ' . ucfirst($status), 'Application ' . $row['reference_no'] . ' was ' . $status . '.', [
    'reference_no' => $row['reference_no'],
    'status' => $status,
    'permit_no' => $permitNo,
    'expiry_date' => $exp,
]);
log_activity($pdo, (int)auth_user()['id'], 'application_' . $status, $row['reference_no']);
if ($status === 'approved') {
    $attachmentNote = $emailAttachments
        ? '<p>A copy of your approved permit has been attached for your reference and download.</p>'
        : '<p>Your approved permit record is now available in the system.</p>';
    $content = '<h3>Application Approved</h3><p>Reference: <strong>' . $row['reference_no'] . '</strong></p><p>Your Business Permit application has been approved after successful review and verification of the submitted requirements.</p>' . $attachmentNote . '<p>Permit No: <strong>' . $permitNo . '</strong></p><p>Expiry: ' . $exp . '</p><p>QR Verify: <a href="' . $qr . '">Verify Permit</a></p>';
} elseif ($status === 'rejected') {
    $content = '<h3>Application Rejected</h3><p>Reference: <strong>' . $row['reference_no'] . '</strong></p><p>Your Business Permit application has been rejected due to incomplete, invalid, or inconsistent submitted documents. Please review the remarks provided and resubmit the necessary requirements for further evaluation.</p>';
}
$errorOut = null;
send_system_email($pdo, $row['email'], 'Application ' . ucfirst($status), render_email_template('Application Update', $content), (int)$row['user_id'], $errorOut, $emailAttachments);
echo json_encode(['success' => true,'message' => 'Application ' . $status . ' successfully.']);
