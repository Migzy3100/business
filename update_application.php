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

$checkStmt = $pdo->prepare('SELECT id, reference_no, status FROM applications WHERE id = ? AND user_id = ? LIMIT 1');
$checkStmt->execute([$applicationId, $uid]);
$current = $checkStmt->fetch(PDO::FETCH_ASSOC);
if (!$current) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
}

if (($current['status'] ?? '') === 'approved') {
    echo json_encode(['success' => false, 'message' => 'Approved applications cannot be edited.']);
    exit;
}

$type = sanitize($_POST['application_type'] ?? 'new');
$cat = sanitize($_POST['business_category'] ?? ($_POST['ownership'] ?? ''));
$cap = sanitize($_POST['capitalization'] ?? ($_POST['REGNO'] ?? ''));
$ownerName = sanitize($_POST['nOwner'] ?? '');
$spouseName = sanitize($_POST['nSpouse'] ?? '');
$telephoneNo = sanitize($_POST['telNo'] ?? '');
$email = sanitize($_POST['email'] ?? '');
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

$updateStmt = $pdo->prepare('
    UPDATE applications SET
        application_type = ?,
        business_category = ?,
        capitalization = ?,
        owner_name = ?,
        spouse_name = ?,
        telephone_no = ?,
        email = ?,
        home_address = ?,
        business_name = ?,
        business_address = ?,
        business_email = ?,
        business_street = ?,
        business_barangay = ?,
        business_city = ?,
        business_province = ?,
        business_postal_code = ?,
        ownership = ?,
        reg_no = ?,
        application_date = ?,
        application_time = ?,
        dti_reg_no = ?,
        tin = ?,
        representative = ?,
        cctvs = ?,
        cctv_count = ?,
        lessor_name = ?,
        monthly_rental = ?,
        lessor_street = ?,
        lessor_barangay = ?,
        lessor_subdivision = ?,
        lessor_city = ?,
        lessor_province = ?,
        lessor_postal_code = ?,
        lessor_tel_no = ?,
        line_of_business = ?,
        employees_male = ?,
        employees_female = ?,
        employees_lgu = ?,
        business_area_sqm = ?
    WHERE id = ? AND user_id = ?
');
$updateStmt->execute([
    $type,
    $cat,
    $cap,
    $ownerName,
    $spouseName,
    $telephoneNo,
    $email,
    $homeAddress,
    $businessName,
    $businessAddress,
    $businessEmail,
    $businessStreet,
    $businessBarangay,
    $businessCity,
    $businessProvince,
    $businessPostalCode,
    $ownership,
    $regNo,
    $applicationDate ?: null,
    $applicationTime ?: null,
    $dtiRegNo,
    $tin,
    $representative,
    $cctvs,
    $cctvCount,
    $lessorName,
    $monthlyRental !== '' ? $monthlyRental : null,
    $lessorStreet,
    $lessorBarangay,
    $lessorSubdivision,
    $lessorCity,
    $lessorProvince,
    $lessorPostalCode,
    $lessorTelNo,
    $lineOfBusiness,
    $employeesMale,
    $employeesFemale,
    $employeesLgu,
    $businessAreaSqm !== '' ? $businessAreaSqm : null,
    $applicationId,
    $uid,
]);

record_application_event($pdo, $applicationId, $uid, $uid, 'application_updated', 'Application Updated', 'Application ' . (string)($current['reference_no'] ?? $applicationId) . ' was updated by the applicant.', [
    'reference_no' => (string)($current['reference_no'] ?? ''),
    'status' => (string)($current['status'] ?? ''),
]);
log_activity($pdo, $uid, 'application_update', (string)($current['reference_no'] ?? $applicationId));
echo json_encode(['success' => true, 'message' => 'Application updated successfully.']);
