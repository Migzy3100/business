<?php

// Gmail SMTP settings for system email delivery.
if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', 'smtp.gmail.com');
}

if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', 587);
}

// if (!defined('MAIL_USER')) {
//     define('MAIL_USER', 'cityadmis@gmail.com');
// }
if (!defined('MAIL_USER')) {
    define('MAIL_USER', 'malaybalaycitylicensing@gmail.com');
}

// if (!defined('MAIL_PASS')) {
//     define('MAIL_PASS', 'bkbm qlcw qvir bsoy');
// }
if (!defined('MAIL_PASS')) {
    define('MAIL_PASS', 'ejpc brdf tcwo isph');
}

// if (!defined('MAIL_FROM')) {
//     define('MAIL_FROM', 'cityadmis@gmail.com');
// }
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', 'malaybalaycitylicensing@gmail.com');
}

if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'OBS Permit System');
}

if (!defined('MAIL_REPLY_TO')) {
    define('MAIL_REPLY_TO', MAIL_FROM);
}

if (!defined('MAIL_REPLY_TO_NAME')) {
    define('MAIL_REPLY_TO_NAME', MAIL_FROM_NAME);
}
