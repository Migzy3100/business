<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['admin']);

$rows = $pdo->query('SELECT reference_no,status,created_at FROM applications ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'rows' => $rows,
]);
