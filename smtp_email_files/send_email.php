<?php

// smtp_config.php holds the app password and is kept out of git (see smtp_config.example.php).
// Without it, the MAIL_* settings come from environment variables.
if (is_file(__DIR__ . '/smtp_config.php')) {
    require_once __DIR__ . '/smtp_config.php';
}
foreach (['MAIL_HOST' => 'smtp.gmail.com', 'MAIL_PORT' => 587, 'MAIL_USER' => '', 'MAIL_PASS' => '', 'MAIL_FROM_NAME' => 'OBS Permit System'] as $key => $default) {
    if (!defined($key)) {
        define($key, getenv($key) ?: $default);
    }
}
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', getenv('MAIL_FROM') ?: MAIL_USER);
}
if (!defined('MAIL_REPLY_TO')) {
    define('MAIL_REPLY_TO', getenv('MAIL_REPLY_TO') ?: MAIL_FROM);
}
if (!defined('MAIL_REPLY_TO_NAME')) {
    define('MAIL_REPLY_TO_NAME', getenv('MAIL_REPLY_TO_NAME') ?: MAIL_FROM_NAME);
}
require_once __DIR__ . '/phpmailer_loader.php';

use PHPMailer\PHPMailer\PHPMailer;

function smtp_plain_text_from_html(string $html): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $html);
    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/\s*(p|div|h1|h2|h3|h4|li|tr)\s*>/i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim((string)$text);
}

function send_smtp_email(string $to, string $subject, string $html, ?string &$errorOut = null, array $attachments = []): bool
{
    $errorOut = null;

    if (!smtp_phpmailer_load()) {
        $errorOut = 'PHPMailer not found. Install files in assets/vendor/PHPMailer/src/.';
        return false;
    }

    if (MAIL_USER === '' || MAIL_PASS === '') {
        $errorOut = 'SMTP username or password is missing.';
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USER;
        $mail->Password = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = MAIL_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
        $mail->addAddress($to);
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        $mail->Priority = 3;

        foreach ($attachments as $attachment) {
            $path = (string)($attachment['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                $name = (string)($attachment['name'] ?? basename($path));
                $mail->addAttachment($path, $name);
            }
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = smtp_plain_text_from_html($html);

        return $mail->send();
    } catch (Throwable $e) {
        $errorOut = $mail->ErrorInfo ?: $e->getMessage();
        return false;
    }
}
