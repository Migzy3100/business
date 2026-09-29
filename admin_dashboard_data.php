<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
require_auth(['admin', 'staff']);

function dashboard_renewal_reference(array $row): string
{
    $referenceNo = trim((string)($row['reference_no'] ?? ''));
    if ($referenceNo !== '') {
        return $referenceNo;
    }

    $metadata = json_decode((string)($row['submitted_metadata'] ?? ''), true);
    if (is_array($metadata)) {
        $controlNumber = trim((string)($metadata['control_number'] ?? ''));
        if ($controlNumber !== '') {
            return $controlNumber;
        }
    }

    return 'Manual CN';
}

$totalUsers = (int)$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
$totalApps = (int)$pdo->query('SELECT COUNT(*) c FROM applications')->fetch()['c'];
$totalRenewals = (int)$pdo->query('SELECT COUNT(*) c FROM renewals')->fetch()['c'];
$pendingApps = (int)$pdo->query('SELECT COUNT(*) c FROM applications WHERE status="pending"')->fetch()['c'];
$awaitingPaymentApps = (int)$pdo->query('SELECT COUNT(*) c FROM applications WHERE status="awaiting_payment"')->fetch()['c'];
$approvedApps = (int)$pdo->query('SELECT COUNT(*) c FROM applications WHERE status="approved"')->fetch()['c'];
$rejectedApps = (int)$pdo->query('SELECT COUNT(*) c FROM applications WHERE status="rejected"')->fetch()['c'];
$expiringSoon = (int)$pdo->query('SELECT COUNT(*) c FROM applications WHERE status="approved" AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetch()['c'];
$approvalRate = $totalApps > 0 ? round(($approvedApps / $totalApps) * 100, 1) : 0;

$latest = $pdo->query('SELECT a.reference_no,a.status,a.application_type,u.full_name FROM applications a JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
foreach ($latest as &$row) {
    $row['display_status_label'] = ucwords(str_replace('_', ' ', (string)($row['status'] ?? '')));
}
unset($row);

$pendingRenewals = $pdo->query('
    SELECT
        r.id,
        r.status,
        r.requested_at,
        a.reference_no,
        u.full_name,
        (
            SELECT rh.metadata_json
            FROM renewal_history rh
            WHERE rh.renewal_id = r.id
              AND rh.event_type = "submitted"
            ORDER BY rh.id DESC
            LIMIT 1
        ) AS submitted_metadata
    FROM renewals r
    LEFT JOIN applications a ON a.id = r.application_id
    JOIN users u ON u.id = r.user_id
    WHERE r.status IN ("pending", "on_transaction")
    ORDER BY r.requested_at DESC
    LIMIT 10
')->fetchAll(PDO::FETCH_ASSOC);
foreach ($pendingRenewals as &$row) {
    $row['reference_display'] = dashboard_renewal_reference($row);
}
unset($row);

$activityLogs = $pdo->query('SELECT al.action, al.details, al.created_at, u.full_name FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
$monthlyRows = $pdo->query('SELECT DATE_FORMAT(created_at, "%Y-%m") AS ym, COUNT(*) AS total FROM applications GROUP BY DATE_FORMAT(created_at, "%Y-%m") ORDER BY ym ASC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'kpis' => [
        ['label' => 'Total Users', 'value' => $totalUsers],
        ['label' => 'Total Applications', 'value' => $totalApps],
        ['label' => 'Pending Applications', 'value' => $pendingApps],
        ['label' => 'Awaiting Payment', 'value' => $awaitingPaymentApps],
        ['label' => 'Total Renewals', 'value' => $totalRenewals],
        ['label' => 'Approval Rate (%)', 'value' => $approvalRate],
        ['label' => 'Expiring in 30 Days', 'value' => $expiringSoon],
    ],
    'status_totals' => [
        'pending' => $pendingApps,
        'awaiting_payment' => $awaitingPaymentApps,
        'approved' => $approvedApps,
        'rejected' => $rejectedApps,
    ],
    'latest' => $latest,
    'pending_renewals' => $pendingRenewals,
    'activity_logs' => $activityLogs,
    'monthly_rows' => $monthlyRows,
]);
