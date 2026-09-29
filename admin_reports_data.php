<?php
require_once __DIR__ . '/auth.php';

require_auth(['admin']);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="applications_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ref', 'User', 'Status', 'Created']);
    $rows = $pdo->query('SELECT reference_no,user_id,status,created_at FROM applications')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

header('Content-Type: application/json');

$summary = $pdo->query('SELECT status, COUNT(*) AS total FROM applications GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
foreach ($summary as &$row) {
    $row['display_status_label'] = ucwords(str_replace('_', ' ', (string)($row['status'] ?? '')));
}
unset($row);

$typeSummary = $pdo->query('SELECT application_type, COUNT(*) AS total FROM applications GROUP BY application_type')->fetchAll(PDO::FETCH_ASSOC);
$monthlyRows = $pdo->query('SELECT DATE_FORMAT(created_at, "%Y-%m") AS ym, COUNT(*) AS total FROM applications GROUP BY DATE_FORMAT(created_at, "%Y-%m") ORDER BY ym ASC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);

$totalApplications = (int)$pdo->query('SELECT COUNT(*) AS c FROM applications')->fetch()['c'];
$approvedApplications = (int)$pdo->query('SELECT COUNT(*) AS c FROM applications WHERE status="approved"')->fetch()['c'];
$pendingApplications = (int)$pdo->query('SELECT COUNT(*) AS c FROM applications WHERE status="pending"')->fetch()['c'];
$rejectedApplications = (int)$pdo->query('SELECT COUNT(*) AS c FROM applications WHERE status="rejected"')->fetch()['c'];
$awaitingPaymentApplications = (int)$pdo->query('SELECT COUNT(*) AS c FROM applications WHERE status="awaiting_payment"')->fetch()['c'];
$approvalRate = $totalApplications > 0 ? round(($approvedApplications / $totalApplications) * 100, 1) : 0.0;

echo json_encode([
    'success' => true,
    'summary' => $summary,
    'type_summary' => $typeSummary,
    'monthly_rows' => $monthlyRows,
    'totals' => [
        'total' => $totalApplications,
        'approved' => $approvedApplications,
        'pending' => $pendingApplications,
        'awaiting_payment' => $awaitingPaymentApplications,
        'rejected' => $rejectedApplications,
        'approval_rate' => $approvalRate,
    ],
]);
