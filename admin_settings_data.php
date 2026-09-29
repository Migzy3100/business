<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['admin']);

$rows = $pdo->query('SELECT * FROM settings ORDER BY setting_key ASC')->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'rows' => $rows,
]);
