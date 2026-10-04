<?php
// Temporary debug file - REMOVE AFTER DEBUGGING
require_once dirname(__DIR__) . '/config/payhere-config.php';

$merchant_id     = PAYHERE_MERCHANT_ID;
$merchant_secret = PAYHERE_MERCHANT_SECRET;
$test_order_id   = 'ORD-TEST-001';
$test_amount     = '720.00';
$test_currency   = 'LKR';

$secret_md5 = strtoupper(md5($merchant_secret));
$hash = strtoupper(md5(
    $merchant_id . $test_order_id . $test_amount . $test_currency . $secret_md5
));

echo "<h2>PayHere Debug Info</h2>";
echo "<table border='1' cellpadding='8'>";
echo "<tr><td><b>Merchant ID</b></td><td>" . htmlspecialchars($merchant_id) . "</td></tr>";
echo "<tr><td><b>Merchant Secret (first 6 chars)</b></td><td>" . substr($merchant_secret, 0, 6) . "...</td></tr>";
echo "<tr><td><b>MD5(Merchant Secret)</b></td><td>" . $secret_md5 . "</td></tr>";
echo "<tr><td><b>Test Hash</b></td><td>" . $hash . "</td></tr>";
echo "<tr><td><b>APP_BASE_URL</b></td><td>" . htmlspecialchars(APP_BASE_URL) . "</td></tr>";
echo "<tr><td><b>PAYHERE_CHECKOUT_URL</b></td><td>" . htmlspecialchars(PAYHERE_CHECKOUT_URL) . "</td></tr>";
echo "<tr><td><b>PHP Version</b></td><td>" . phpversion() . "</td></tr>";
echo "</table>";

echo "<br><h3>Raw ENV vars:</h3>";
echo "<pre>";
echo "DB_HOST from getenv: " . (getenv('DB_HOST') ? '✓ Set' : '✗ NOT SET') . "\n";
echo "PAYHERE_MERCHANT_ID from getenv: " . (getenv('PAYHERE_MERCHANT_ID') ? '✓ Set (' . getenv('PAYHERE_MERCHANT_ID') . ')' : '✗ NOT SET (using fallback)') . "\n";
echo "PAYHERE_MERCHANT_SECRET from getenv: " . (getenv('PAYHERE_MERCHANT_SECRET') ? '✓ Set' : '✗ NOT SET (using fallback)') . "\n";
echo "APP_BASE_URL from getenv: " . (getenv('APP_BASE_URL') ? '✓ Set (' . getenv('APP_BASE_URL') . ')' : '✗ NOT SET (using fallback)') . "\n";
echo "</pre>";
