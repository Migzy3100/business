<?php

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
require_auth(['admin', 'staff', 'applicant']);

$uid = (int)auth_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($payload['action'] ?? '');

    if ($action === 'mark_read') {
        $id = (int)($payload['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?');
            $stmt->execute([$id, $uid]);
        } else {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?');
            $stmt->execute([$uid]);
        }
    }
}

$limit = isset($_GET['all']) ? 100 : 10;
$stmt = $pdo->prepare('SELECT id,title,message,type,is_read,created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT ' . $limit);
$stmt->execute([$uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
$countStmt->execute([$uid]);
$unreadCount = (int)$countStmt->fetchColumn();

echo json_encode(['success' => true, 'data' => $rows, 'unread_count' => $unreadCount]);
