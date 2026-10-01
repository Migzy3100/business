<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

foreach ([__DIR__, dirname(__DIR__)] as $smtpBase) {
    $emailTemplateFile = $smtpBase . '/smtp_email_files/email_template.php';
    if (is_file($emailTemplateFile)) {
        require_once $emailTemplateFile;
        break;
    }
}

if (!function_exists('smtp_phpmailer_load')) {
    function smtp_phpmailer_load(): bool
    {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return true;
        }

        $sourceDir = __DIR__ . '/vendor/PHPMailer/src';
        foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
            $path = $sourceDir . '/' . $file;
            if (!is_file($path)) {
                return false;
            }
            require_once $path;
        }

        return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }
}

if (!function_exists('smtp_render_email_template')) {
    function smtp_render_email_template(string $title, string $content): string
    {
        return '<!doctype html><html><head><meta charset="UTF-8"><title>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</title></head><body>' . $content . '</body></html>';
    }
}

if (!function_exists('smtp_plain_text_from_html')) {
    function smtp_plain_text_from_html(string $html): string
    {
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/\s*(p|div|h1|h2|h3|h4|li|tr)\s*>/i', "\n", (string)$text);
        return trim(html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

if (!function_exists('send_smtp_email')) {
    function send_smtp_email(string $to, string $subject, string $html, ?string &$errorOut = null, array $attachments = []): bool
    {
        $errorOut = null;
        if (!smtp_phpmailer_load()) {
            $errorOut = 'PHPMailer is unavailable on the API server.';
            return false;
        }
        if (MAIL_USER === '' || MAIL_PASS === '') {
            $errorOut = 'SMTP username or password is not configured on the API server.';
            return false;
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = MAIL_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_USER;
            $mail->Password = MAIL_PASS;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = MAIL_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = smtp_plain_text_from_html($html);

            foreach ($attachments as $attachment) {
                $path = (string)($attachment['path'] ?? '');
                if ($path !== '' && is_file($path)) {
                    $mail->addAttachment($path, (string)($attachment['name'] ?? basename($path)));
                }
            }

            return $mail->send();
        } catch (Throwable $e) {
            $errorOut = $mail->ErrorInfo ?: $e->getMessage();
            return false;
        }
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
    $error = null;
    $sent = send_smtp_email($to, $subject, $html, $error, $attachments);
    $status = $sent ? 'sent' : 'failed';

    $stmt = $pdo->prepare('INSERT INTO email_logs(user_id, email, subject, body, status, error_message, sent_at) VALUES(?,?,?,?,?,?,NOW())');
    $stmt->execute([$userId, $to, $subject, substr($html, 0, 10000), $status, $error]);
    $errorOut = $error;

    return $sent;
}
