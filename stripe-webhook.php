<?php
// Stripe calls this server-to-server after payment.
// Log everything for debugging.

require_once __DIR__ . '/config/stripe-config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/db.php';

$logFile = __DIR__ . '/stripe-log.txt';
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

file_put_contents($logFile, date('Y-m-d H:i:s') . " WEBHOOK RECEIVED\n", FILE_APPEND);

if (empty($payload)) {
    http_response_code(400);
    exit('Empty payload');
}

// Verify Stripe Signature
if (defined('STRIPE_WEBHOOK_SECRET') && STRIPE_WEBHOOK_SECRET !== 'whsec_...put_your_webhook_secret_here...') {
    $endpoint_secret = STRIPE_WEBHOOK_SECRET;
    $sig_parts = explode(',', $sig_header);
    $t = ''; $v1 = '';
    foreach ($sig_parts as $part) {
        if (strpos($part, 't=') === 0) $t = substr($part, 2);
        if (strpos($part, 'v1=') === 0) $v1 = substr($part, 3);
    }
    
    $signed_payload = "$t.$payload";
    $signature = hash_hmac('sha256', $signed_payload, $endpoint_secret);
    
    if (!hash_equals($signature, $v1)) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " SIGNATURE MISMATCH\n", FILE_APPEND);
        http_response_code(400);
        exit('Invalid signature');
    }
}

$event = json_decode($payload, true);

if ($event['type'] === 'checkout.session.completed') {
    $session = $event['data']['object'];
    
    $order_id = $session['client_reference_id'] ?? '';
    $payment_id = $session['payment_intent'] ?? '';
    
    if (empty($order_id)) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " MISSING ORDER ID\n", FILE_APPEND);
        http_response_code(400);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id=?");
    $stmt->execute([$order_id]);
    $payment = $stmt->fetch();
    
    if (!$payment) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " ORDER NOT FOUND: $order_id\n", FILE_APPEND);
        http_response_code(404);
        exit();
    }
    
    if ($payment['status'] !== 'Paid') {
        $pdo->prepare("
            UPDATE payments
            SET status='Paid',
                transaction_ref=?,
                payment_method='Stripe',
                paid_at=NOW()
            WHERE id=?
        ")->execute([$payment_id, $payment['id']]);

        if (isset($payment['wallet_applied']) && floatval($payment['wallet_applied']) > 0) {
            markWalletCreditUsed($pdo, $payment['user_id'], $payment['booking_id'], $payment['wallet_applied']);
        }

        file_put_contents($logFile, date('Y-m-d H:i:s') . " PAYMENT MARKED PAID: order=$order_id\n", FILE_APPEND);

        // Send confirmation email
        try {
            $bookStmt = $pdo->prepare("
                SELECT b.*, u.name AS user_name, u.email AS user_email,
                       c.name AS court_name
                FROM bookings b
                JOIN users  u ON b.user_id  = u.id
                JOIN courts c ON b.court_id = c.id
                WHERE b.id = ?
            ");
            $bookStmt->execute([$payment['booking_id']]);
            $bk = $bookStmt->fetch();

            if ($bk) {
                $freshPay = $pdo->prepare("SELECT * FROM payments WHERE id=?");
                $freshPay->execute([$payment['id']]);
                $payRow = $freshPay->fetch() ?: $payment;

                if (empty($payRow['confirmation_email_sent'])) {
                    sendBookingConfirmationEmail($bk['user_email'], $bk['user_name'], $bk, $payRow);
                    $pdo->prepare("UPDATE payments SET confirmation_email_sent=1 WHERE id=?")->execute([$payment['id']]);
                }
            }
        } catch (Exception $ex) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " EMAIL ERROR: " . $ex->getMessage() . "\n", FILE_APPEND);
        }
    }
}

// Helper function to mark wallet credit as used
function markWalletCreditUsed($pdo, $userId, $bookingId, $walletApplied) {
    $walletApplied = floatval($walletApplied);
    if ($walletApplied <= 0) return;
    $stmt = $pdo->prepare("SELECT * FROM pending_transactions WHERE user_id = ? AND status = 'Available' ORDER BY created_at ASC");
    $stmt->execute([$userId]);
    $txs = $stmt->fetchAll();
    $remaining = $walletApplied;
    foreach ($txs as $tx) {
        if ($remaining <= 0) break;
        $txAmount = floatval($tx['amount']);
        if ($txAmount <= $remaining) {
            $pdo->prepare("UPDATE pending_transactions SET status = 'Used', used_at = NOW(), used_for_booking_id = ? WHERE id = ?")->execute([$bookingId, $tx['id']]);
            $remaining -= $txAmount;
        } else {
            $newUnusedAmount = $txAmount - $remaining;
            $pdo->prepare("UPDATE pending_transactions SET amount = ? WHERE id = ?")->execute([$newUnusedAmount, $tx['id']]);
            $pdo->prepare("INSERT INTO pending_transactions (user_id, original_booking_id, original_payment_id, amount, reason, status, used_at, used_for_booking_id) VALUES (?, ?, ?, ?, ?, 'Used', NOW(), ?)")->execute([
                $userId, $tx['original_booking_id'], $tx['original_payment_id'], $remaining, $tx['reason'] . ' (Used portion)', $bookingId
            ]);
            $remaining = 0;
        }
    }
}

http_response_code(200);
echo "OK";