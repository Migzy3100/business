<?php

require_once __DIR__ . '/auth.php';
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

$stmt = $pdo->prepare('
    SELECT
        a.id, a.status, a.application_type, a.permit_no, a.permit_number,
        a.owner_name, a.spouse_name, a.telephone_no, a.email, a.home_address,
        a.business_name, a.business_address, a.business_email,
        a.business_street, a.business_barangay, a.business_city, a.business_province, a.business_postal_code,
        a.ownership, a.reg_no, a.application_date, a.application_time, a.dti_reg_no, a.tin, a.representative, a.cctvs,
        a.cctv_count, a.lessor_name, a.monthly_rental, a.lessor_street, a.lessor_barangay, a.lessor_subdivision,
        a.lessor_city, a.lessor_province, a.lessor_postal_code, a.lessor_tel_no, a.line_of_business,
        a.employees_male, a.employees_female, a.employees_lgu, a.business_area_sqm,
        ap.fee_amount AS regulatory_fee, ap.payment_status, ap.sent_at AS payables_sent_at, ap.paid_at AS payment_paid_at
    FROM applications a
    LEFT JOIN application_payables ap ON ap.application_id = a.id
    WHERE a.id = ? AND a.user_id = ?
    LIMIT 1
');
$stmt->execute([$applicationId, $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
}

$setStmt = $pdo->prepare('
    SELECT
        certificate_occupancy_path,
        leased_contract_path,
        business_registration_proof_path,
        certificate_occupancy_name,
        leased_contract_name,
        business_registration_proof_name
    FROM application_document_sets
    WHERE application_id = ?
    ORDER BY id DESC
    LIMIT 1
');
$setStmt->execute([$applicationId]);
$docs = $setStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$enrichDoc = static function (?string $path, ?string $name): array {
    $result = [
        'name' => $name ?: '',
        'path' => $path ?: '',
        'size_kb' => null,
    ];
    if (!$path) {
        return $result;
    }
    $fullPath = realpath(__DIR__ . '/../' . ltrim($path, '/'));
    if ($fullPath && is_file($fullPath)) {
        $bytes = filesize($fullPath);
        if (is_int($bytes) || is_float($bytes)) {
            $result['size_kb'] = round(((float)$bytes) / 1024, 1);
        }
    }
    return $result;
};

$documents = [
    'certificate_occupancy' => $enrichDoc($docs['certificate_occupancy_path'] ?? '', $docs['certificate_occupancy_name'] ?? ''),
    'leased_contract' => $enrichDoc($docs['leased_contract_path'] ?? '', $docs['leased_contract_name'] ?? ''),
    'business_registration_proof' => $enrichDoc($docs['business_registration_proof_path'] ?? '', $docs['business_registration_proof_name'] ?? ''),
];

$savedPermit = null;
try {
    $savedStmt = $pdo->prepare('SELECT file_path, original_name, created_at FROM approved_application_files WHERE application_id=? LIMIT 1');
    $savedStmt->execute([$applicationId]);
    $savedPermit = $savedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $savedPermit = null;
}

echo json_encode([
    'success' => true,
    'data' => array_merge($row, $docs, ['documents' => $documents, 'saved_permit' => $savedPermit]),
]);
