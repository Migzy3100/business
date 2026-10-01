# SMTP Email Files

This folder contains the Gmail SMTP setup used by the OBS Permit System.

- `smtp_config.php` stores the SMTP host, email address, app password, and sender name.
- `PHPMailer/` contains the copied PHPMailer package.
- `phpmailer_loader.php` loads PHPMailer from `smtp_email_files/PHPMailer/src`, with Composer and `assets/vendor/PHPMailer/src` as fallbacks.
- `email_template.php` renders the HTML email layout.
- `send_email.php` sends email using Gmail SMTP.
- `test_send_email.php` sends a test email in the browser.

Open this URL to test sending:

```text
http://localhost/obs/smtp_email_files/test_send_email.php?to=your-email@example.com
```
