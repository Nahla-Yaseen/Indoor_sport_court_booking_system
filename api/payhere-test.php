<?php
require_once dirname(__DIR__) . '/config/payhere-config.php';

$order_id = 'TEST-' . time();
$amount = '150.00';
$currency = 'LKR';

$hash = strtoupper(md5(
    PAYHERE_MERCHANT_ID . 
    $order_id . 
    $amount . 
    $currency . 
    strtoupper(md5(PAYHERE_MERCHANT_SECRET))
));
?>
<!DOCTYPE html>
<html>
<head><title>PayHere Test</title></head>
<body>
    <h2>PayHere Minimal Test</h2>
    <p>Domain: <?= htmlspecialchars(APP_BASE_URL) ?></p>
    <p>If this works, it means PayHere accepts the domain and credentials.</p>
    
    <form method="POST" action="<?= PAYHERE_CHECKOUT_URL ?>">
        <input type="hidden" name="merchant_id" value="<?= PAYHERE_MERCHANT_ID ?>">
        <input type="hidden" name="return_url" value="<?= APP_BASE_URL ?>/api/payhere-test.php?status=success">
        <input type="hidden" name="cancel_url" value="<?= APP_BASE_URL ?>/api/payhere-test.php?status=cancel">
        <input type="hidden" name="notify_url" value="<?= APP_BASE_URL ?>/payhere-notify.php">
        
        <input type="hidden" name="order_id" value="<?= $order_id ?>">
        <input type="hidden" name="items" value="Test Item">
        <input type="hidden" name="currency" value="<?= $currency ?>">
        <input type="hidden" name="amount" value="<?= $amount ?>">
        
        <input type="hidden" name="first_name" value="Test">
        <input type="hidden" name="last_name" value="User">
        <input type="hidden" name="email" value="test@example.com">
        <input type="hidden" name="phone" value="0771234567">
        <input type="hidden" name="address" value="No.1, Galle Road">
        <input type="hidden" name="city" value="Colombo">
        <input type="hidden" name="country" value="Sri Lanka">
        <input type="hidden" name="hash" value="<?= $hash ?>">
        
        <button type="submit" style="padding:10px 20px; font-size:16px; background:blue; color:white;">Pay Test Amount (LKR 150)</button>
    </form>
</body>
</html>
