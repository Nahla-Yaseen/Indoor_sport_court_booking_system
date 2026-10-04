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
$_detected_host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: $_detected_host);