<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

$emailTemplateFile = __DIR__ . '/../smtp_email_files/email_template.php';
$sendEmailFile = __DIR__ . '/../smtp_email_files/send_email.php';

if (is_file($emailTemplateFile)) {
    require_once $emailTemplateFile;
}

if (is_file($sendEmailFile)) {
    require_once $sendEmailFile;
}

if (!function_exists('smtp_phpmailer_load')) {
    function smtp_phpmailer_load(): bool
    {
        return false;
    }
}

if (!function_exists('smtp_render_email_template')) {
    function smtp_render_email_template(string $title, string $content): string
    {
        return '<!doctype html><html><head><meta charset="UTF-8"><title>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</title></head><body>'
            . $content
            . '</body></html>';
    }
}

if (!function_exists('send_smtp_email')) {
    function send_smtp_email(string $to, string $subject, string $html, ?string &$errorOut = null, array $attachments = []): bool
    {
        $errorOut = 'SMTP email files are not installed on the API server.';
        return false;
    }
}

function phpmailer_available(): bool
{
    return smtp_phpmailer_load();
}

function render_email_template(string $title, string $content): string
{
    return smtp_render_email_template($title, $content);
}

function send_system_email(PDO $pdo, string $to, string $subject, string $html, ?int $userId = null, ?string &$errorOut = null, array $attachments = []): bool
{
    $status = 'failed';
    $error = null;
    if (send_smtp_email($to, $subject, $html, $error, $attachments)) {
        $status = 'sent';
    }

    $stmt = $pdo->prepare('INSERT INTO email_logs(user_id, email, subject, body, status, error_message, sent_at) VALUES(?,?,?,?,?,?,NOW())');
    $stmt->execute([$userId, $to, $subject, substr($html, 0, 10000), $status, $error]);
    $errorOut = $error;

    return $status === 'sent';
}
