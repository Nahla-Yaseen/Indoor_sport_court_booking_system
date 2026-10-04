<?php
define('PAYHERE_MODE', 'sandbox');

define('PAYHERE_MERCHANT_ID',     getenv('PAYHERE_MERCHANT_ID')     ?: '1236830');
define('PAYHERE_MERCHANT_SECRET', getenv('PAYHERE_MERCHANT_SECRET') ?: 'MTEyOTAyMjI1MTQyODgxODQ4MzMzNDMyOTQ0OTIwMTM3MTE5MTEx');

define('PAYHERE_CHECKOUT_URL',
    PAYHERE_MODE === 'sandbox'
        ? 'https://sandbox.payhere.lk/pay/checkout'
        : 'https://www.payhere.lk/pay/checkout'
);

// Application base URL
// Dynamically detect the base URL from the current request
$is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$_detected_host = ($is_https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Force HTTPS for Vercel if the host contains vercel.app
if (strpos($_detected_host, 'vercel.app') !== false) {
    $_detected_host = str_replace('http://', 'https://', $_detected_host);
}

define('APP_BASE_URL', $_detected_host);