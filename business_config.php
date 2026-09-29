<?php

define('BILL_DB_HOST', '192.168.10.247');
define('BILL_DB_USER', 'tmcviewer');
define('BILL_DB_PASS', 'raindrops');
define('BILL_DB_NAME', 'irgsdb2011');

function bill_inquiry_connection(): mysqli
{
    $cn = mysqli_connect(BILL_DB_HOST, BILL_DB_USER, BILL_DB_PASS, BILL_DB_NAME);

    if (!$cn) {
        throw new RuntimeException('Database connection failed.');
    }

    mysqli_set_charset($cn, 'utf8mb4');
    return $cn;
}
