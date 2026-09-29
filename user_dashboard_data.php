<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['applicant']);

$user = auth_user();
$userId = (int)($user['id'] ?? 0);

$stats = $pdo->query('SELECT status, COUNT(*) c FROM applications WHERE user_id=' . $userId . ' GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
$recentTransactions = $pdo->query('SELECT reference_no, application_type, status, created_at FROM applications WHERE user_id=' . $userId . ' ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);

$statusMap = ['pending' => 0, 'awaiting_payment' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($stats as $row) {
    $status = (string)($row['status'] ?? '');
    if (array_key_exists($status, $statusMap)) {
        $statusMap[$status] = (int)$row['c'];
    }
}
foreach ($recentTransactions as &$row) {
    $row['display_type'] = ucfirst((string)($row['application_type'] ?? ''));
    $row['display_status'] = ucwords(str_replace('_', ' ', (string)($row['status'] ?? '')));
}
unset($row);

echo json_encode([
    'success' => true,
    'stats' => $statusMap,
    'recent_transactions' => $recentTransactions,
]);
