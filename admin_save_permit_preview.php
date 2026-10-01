<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permit_renderer.php';
header('Content-Type: application/json');
require_auth(['admin', 'staff']);

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$applicationId = (int)($_POST['application_id'] ?? 0);
if ($applicationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid application id.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM applications WHERE id=? LIMIT 1');
$stmt->execute([$applicationId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
}

$error = null;
$savedRelPath = generate_permit_file($pdo, $row, $error);
if ($savedRelPath === null) {
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Permit preview image saved.',
    'file_path' => $savedRelPath,
]);
