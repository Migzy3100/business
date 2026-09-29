<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['admin', 'staff']);

$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['approved', 'rejected'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid application status.']);
    exit;
}

$stmt = $pdo->prepare('
    SELECT
        a.id,
        a.reference_no,
        a.status,
        a.business_name,
        a.owner_name,
        a.tin,
        a.line_of_business,
        a.business_area_sqm,
        a.application_type,
        u.full_name,
        b.business_name AS profile_business_name
    FROM applications a
    JOIN users u ON u.id=a.user_id
    JOIN businesses b ON b.id=a.business_id
    WHERE a.status=?
    ORDER BY a.id DESC
');
$stmt->execute([$status]);

echo json_encode([
    'success' => true,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
]);
