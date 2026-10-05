<?php
define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: 'sk_test_...put_your_key_here...');
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_...put_your_webhook_secret_here...');

// Application base URL
$is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$_detected_host = ($is_https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Force HTTPS for Vercel if the host contains vercel.app
if (strpos($_detected_host, 'vercel.app') !== false) {
    $_detected_host = str_replace('http://', 'https://', $_detected_host);
}

define('APP_BASE_URL', $_detected_host);