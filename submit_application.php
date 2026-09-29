<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
header('Content-Type: application/json');
require_auth(['applicant']);

function size_to_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    switch ($unit) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }

    return (int)$number;
}

$postMaxBytes = size_to_bytes((string)ini_get('post_max_size'));
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    echo json_encode([
        'success' => false,
        'message' => 'The selected files are too large to submit together. Please compress the PDF files or upload smaller requirement files.',
    ]);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false,'message' => 'Invalid CSRF']);
    exit;
}
$currentUser = auth_user();
$uid = (int)$currentUser['id'];
$bizId = (int)($_POST['business_id'] ?? 0);
$type = sanitize($_POST['application_type'] ?? 'new');
$cat = sanitize($_POST['business_category'] ?? ($_POST['ownership'] ?? ''));
$cap = sanitize($_POST['capitalization'] ?? ($_POST['REGNO'] ?? ''));
$ownerName = sanitize($_POST['nOwner'] ?? '');
$spouseName = sanitize($_POST['nSpouse'] ?? '');
$telephoneNo = sanitize($_POST['telNo'] ?? '');
$email = sanitize($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $accountEmail = (string)($currentUser['email'] ?? '');
    $email = filter_var($accountEmail, FILTER_VALIDATE_EMAIL) ? $accountEmail : '';
}
$homeAddress = sanitize($_POST['homeAddress'] ?? '');
$businessName = sanitize($_POST['businessName'] ?? '');
$businessAddress = sanitize($_POST['businessAddress'] ?? '');
$businessEmail = sanitize($_POST['businessEmail'] ?? '');
$businessStreet = sanitize($_POST['Bstreet'] ?? '');
$businessBarangay = sanitize($_POST['Bbarangay'] ?? '');
$businessCity = sanitize($_POST['Bcity'] ?? '');
$businessProvince = sanitize($_POST['Bprovince'] ?? '');
$businessPostalCode = sanitize($_POST['Bpost'] ?? '');
$ownership = sanitize($_POST['ownership'] ?? '');
$regNo = sanitize($_POST['REGNO'] ?? '');
$applicationDate = sanitize($_POST['dateOfApplication'] ?? '');
$applicationTime = sanitize($_POST['time'] ?? '');
$dtiRegNo = sanitize($_POST['dtiREGNO'] ?? '');
$tin = sanitize($_POST['tin'] ?? '');
$representative = sanitize($_POST['representative'] ?? '');
$cctvs = sanitize($_POST['cctvs'] ?? 'No');
$cctvCount = $cctvs === 'Yes' ? max(1, (int)($_POST['cctv_count'] ?? 0)) : null;
$lessorName = sanitize($_POST['lessor_name'] ?? '');
$monthlyRental = sanitize($_POST['monthly_rental'] ?? '');
$lessorStreet = sanitize($_POST['lessor_street'] ?? '');
$lessorBarangay = sanitize($_POST['lessor_barangay'] ?? '');
$lessorSubdivision = sanitize($_POST['lessor_subdivision'] ?? '');
$lessorCity = sanitize($_POST['lessor_city'] ?? '');
$lessorProvince = sanitize($_POST['lessor_province'] ?? '');
$lessorPostalCode = sanitize($_POST['lessor_postal_code'] ?? '');
$lessorTelNo = sanitize($_POST['lessor_tel_no'] ?? '');
$lineOfBusiness = sanitize($_POST['line_of_business'] ?? '');
$employeesMale = (int)($_POST['employees_male'] ?? 0);
$employeesFemale = (int)($_POST['employees_female'] ?? 0);
$employeesLgu = (int)($_POST['employees_lgu'] ?? 0);
$businessAreaSqm = sanitize($_POST['business_area_sqm'] ?? '');

$req = null;
$uploadedFiles = [];

$docMap = [
    'certificate_occupancy' => 'Certificate of Occupancy',
    'leased_contract' => 'Leased Contract',
    'business_registration_proof' => 'Business Registration Proof',
];

foreach ($docMap as $key => $label) {
    $uploaded = upload_file($_FILES[$key] ?? [], 'business_docs');
    if (!$uploaded) {
        $reason = function_exists('last_upload_error') ? last_upload_error() : '';
        $message = $label . ' upload failed.';
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
        $message .= ' Please upload a valid file (.pdf/.doc/.docx/image).';
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    $uploadedFiles[] = [
        'document_type' => $key,
        'path' => $uploaded,
        'original_name' => $_FILES[$key]['name'] ?? $label,
    ];
    if ($req === null) {
        $req = $uploaded;
    }
}

if (!$req) {
    echo json_encode(['success' => false,'message' => 'Requirement upload failed.']);
    exit;
}
$businessType = $cat !== '' ? $cat : ($ownership !== '' ? $ownership : 'Business');
$ref = 'APP-' . date('Ymd') . '-' . random_int(1000, 9999);
$pdo->beginTransaction();
try {
    if ($bizId <= 0) {
        if ($businessName === '' || $businessAddress === '') {
            throw new RuntimeException('Business name and address are required.');
        }

        $bizStmt = $pdo->prepare('
            INSERT INTO businesses(user_id, business_name, business_type, business_address, created_at)
            VALUES(?, ?, ?, ?, NOW())
        ');
        $bizStmt->execute([$uid, $businessName, $businessType, $businessAddress]);
        $bizId = (int)$pdo->lastInsertId();
    } else {
        $bizCheck = $pdo->prepare('SELECT id FROM businesses WHERE id = ? AND user_id = ? LIMIT 1');
        $bizCheck->execute([$bizId, $uid]);
        if (!$bizCheck->fetch()) {
            throw new RuntimeException('Selected business record was not found.');
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO applications(
            user_id,business_id,reference_no,application_type,business_category,capitalization,
            owner_name,spouse_name,telephone_no,business_name,business_address,business_street,
            business_barangay,business_city,business_province,business_postal_code,ownership,reg_no,email,home_address,business_email,
            application_date,application_time,dti_reg_no,tin,representative,cctvs,
            cctv_count,lessor_name,monthly_rental,lessor_street,lessor_barangay,lessor_subdivision,lessor_city,lessor_province,lessor_postal_code,lessor_tel_no,
            line_of_business,employees_male,employees_female,employees_lgu,business_area_sqm,
            status,created_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
    ');
    $stmt->execute([
        $uid, $bizId, $ref, $type, $cat, $cap,
        $ownerName, $spouseName, $telephoneNo, $businessName, $businessAddress, $businessStreet,
        $businessBarangay, $businessCity, $businessProvince, $businessPostalCode, $ownership, $regNo, $email, $homeAddress, $businessEmail,
        $applicationDate ?: null, $applicationTime ?: null, $dtiRegNo, $tin, $representative, $cctvs,
        $cctvCount, $lessorName, $monthlyRental !== '' ? $monthlyRental : null, $lessorStreet, $lessorBarangay, $lessorSubdivision, $lessorCity, $lessorProvince, $lessorPostalCode, $lessorTelNo,
        $lineOfBusiness, $employeesMale, $employeesFemale, $employeesLgu, $businessAreaSqm !== '' ? $businessAreaSqm : null,
        'pending',
    ]);

    $applicationId = (int)$pdo->lastInsertId();
    if (!empty($uploadedFiles)) {
        $fileStmt = $pdo->prepare('INSERT INTO application_files(application_id,file_path,original_name,uploaded_at) VALUES(?,?,?,NOW())');
        foreach ($uploadedFiles as $file) {
            $fileStmt->execute([$applicationId, $file['path'], $file['original_name']]);
        }

        $docByType = [];
        foreach ($uploadedFiles as $file) {
            $docByType[$file['document_type']] = $file;
        }
        $setStmt = $pdo->prepare('
            INSERT INTO application_document_sets(
                application_id,
                certificate_occupancy_path,
                leased_contract_path,
                business_registration_proof_path,
                certificate_occupancy_name,
                leased_contract_name,
                business_registration_proof_name,
                uploaded_at
            ) VALUES(?,?,?,?,?,?,?,NOW())
        ');
        $setStmt->execute([
            $applicationId,
            $docByType['certificate_occupancy']['path'] ?? '',
            $docByType['leased_contract']['path'] ?? '',
            $docByType['business_registration_proof']['path'] ?? '',
            $docByType['certificate_occupancy']['original_name'] ?? null,
            $docByType['leased_contract']['original_name'] ?? null,
            $docByType['business_registration_proof']['original_name'] ?? null,
        ]);
    }

    add_notification($pdo, $uid, 'Application Submitted', 'Reference ' . $ref . ' is now pending review.', 'info');
    add_role_notifications($pdo, ['admin', 'staff'], 'New Application Submitted', 'Reference ' . $ref . ' from ' . ($ownerName ?: 'an applicant') . ' is ready for review.', 'info');
    record_application_event($pdo, $applicationId, $uid, $uid, 'application_submitted', 'Application Submitted', 'Reference ' . $ref . ' is now pending review.', [
        'reference_no' => $ref,
        'status' => 'pending',
        'application_type' => $type,
    ]);
    log_activity($pdo, $uid, 'application_submit', $ref);
    $pdo->commit();

    $content = '<h3>Application Submitted</h3><p>Reference: <strong>' . $ref . '</strong></p><p>Your Business Permit application is currently under review by the authorized personnel. The submitted documents are being evaluated and verified. Please wait for further updates regarding your application status.</p>';
    send_system_email($pdo, $email, 'Application Submitted', render_email_template('Application Update', $content), $uid);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('submit_application failed: ' . $e->getMessage());
    $message = defined('APP_ENV') && APP_ENV === 'local'
        ? 'Failed to save application: ' . $e->getMessage()
        : 'Failed to save application.';
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

echo json_encode(['success' => true,'message' => 'Application submitted successfully. Ref: ' . $ref]);
