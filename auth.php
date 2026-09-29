<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

function log_activity(PDO $pdo, ?int $userId, string $action, string $details = ''): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_logs(user_id, action, details, ip_address, created_at) VALUES(?,?,?,?,NOW())');
    $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
}

function add_notification(PDO $pdo, int $userId, string $title, string $message, string $type = 'info'): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications(user_id, title, message, type, is_read, created_at) VALUES(?,?,?,?,0,NOW())');
    $stmt->execute([$userId, $title, $message, $type]);
}

function add_role_notifications(PDO $pdo, array $roles, string $title, string $message, string $type = 'info'): void
{
    if (!$roles) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE role IN (' . $placeholders . ') AND is_active=1');
    $userStmt->execute($roles);

    $notificationStmt = $pdo->prepare('INSERT INTO notifications(user_id, title, message, type, is_read, created_at) VALUES(?,?,?,?,0,NOW())');
    foreach ($userStmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $notificationStmt->execute([(int)$userId, $title, $message, $type]);
    }
}

function record_application_event(
    PDO $pdo,
    ?int $applicationId,
    ?int $userId,
    ?int $actorUserId,
    string $eventType,
    string $title,
    string $message = '',
    array $metadata = []
): void {
    $stmt = $pdo->prepare('
        INSERT INTO application_events(application_id, user_id, actor_user_id, event_type, title, message, metadata_json, ip_address, created_at)
        VALUES(?,?,?,?,?,?,?,?,NOW())
    ');
    $stmt->execute([
        $applicationId,
        $userId,
        $actorUserId,
        $eventType,
        $title,
        $message,
        $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        $_SERVER['REMOTE_ADDR'] ?? 'CLI',
    ]);
}
