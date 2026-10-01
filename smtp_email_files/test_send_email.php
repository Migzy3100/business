<?php

require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/send_email.php';

$to = trim((string)($_GET['to'] ?? MAIL_FROM));
$subject = 'OBS SMTP Test Email';
$content = '<h3>SMTP email is working</h3><p>This test email was sent from the OBS Permit System SMTP module.</p>';
$html = smtp_render_email_template('SMTP Test', $content);
$error = null;

header('Content-Type: application/json');

echo json_encode([
    'success' => send_smtp_email($to, $subject, $html, $error),
    'to' => $to,
    'error' => $error,
]);
