<?php

function smtp_phpmailer_load(): bool
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }

    $localVendorDir = __DIR__ . '/PHPMailer/src';
    if (smtp_phpmailer_load_from_directory($localVendorDir)) {
        return true;
    }

    $composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($composerAutoload)) {
        require_once $composerAutoload;

        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return true;
        }
    }

    $vendorDir = dirname(__DIR__) . '/assets/vendor/PHPMailer/src';
    return smtp_phpmailer_load_from_directory($vendorDir);
}

function smtp_phpmailer_load_from_directory(string $vendorDir): bool
{
    $requiredFiles = [
        $vendorDir . '/Exception.php',
        $vendorDir . '/PHPMailer.php',
        $vendorDir . '/SMTP.php',
    ];

    foreach ($requiredFiles as $file) {
        if (!is_file($file)) {
            return false;
        }
    }

    foreach ($requiredFiles as $file) {
        require_once $file;
    }

    return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
}
