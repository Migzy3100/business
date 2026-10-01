# SMTP Email Files

This folder contains the Gmail SMTP setup used by the OBS Permit System.

- `smtp_config.php` stores the SMTP host, email address, app password, and sender name. It is **not in git** because it holds the password.
  On a new server, copy `smtp_config.example.php` to `smtp_config.php` and put the Gmail App Password in `MAIL_PASS`,
  or set the `MAIL_USER` / `MAIL_PASS` environment variables instead.
- `PHPMailer/` contains the copied PHPMailer package.
- `phpmailer_loader.php` loads PHPMailer from `smtp_email_files/PHPMailer/src`, with Composer and `assets/vendor/PHPMailer/src` as fallbacks.
- `email_template.php` renders the HTML email layout.
- `send_email.php` sends email using Gmail SMTP.
- `test_send_email.php` sends a test email in the browser.

Open this URL to test sending:

```text
http://localhost/obs/smtp_email_files/test_send_email.php?to=your-email@example.com
```
