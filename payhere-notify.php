<?php
// PayHere calls this server-to-server after payment.
// Log everything for debugging.

require_once __DIR__ . '/config/payhere-config.php';
require_once __DIR__ . '/includes/mailer.php';

// Create a log file to debug
$logFile = __DIR__ . '/payhere-log.txt';
$logData = date('Y-m-d H:i:s') . " POST: " . json_encode($_POST) . "\n";
file_put_contents($logFile, $logData, FILE_APPEND);

require_once __DIR__ . '/includes/db.php';

$merchant_id      = $_POST['merchant_id']      ?? '';
$order_id         = $_POST['order_id']         ?? '';
$payhere_amount   = $_POST['payhere_amount']   ?? '';
$payhere_currency = $_POST['payhere_currency'] ?? '';
$status_code      = $_POST['status_code']      ?? '';
$md5sig           = $_POST['md5sig']           ?? '';
$payment_id       = $_POST['payment_id']       ?? '';
$method           = $_POST['method']           ?? null;
$card_holder      = $_POST['card_holder_name'] ?? null;
$card_no          = $_POST['card_no']          ?? null;

// Verify signature
$local_sig = strtoupper(md5(
    $merchant_id .
    $order_id .
    $payhere_amount .
    $payhere_currency .
    $status_code .
    strtoupper(md5(PAYHERE_MERCHANT_SECRET))
));

file_put_contents($logFile,
    date('Y-m-d H:i:s')." local_sig=$local_sig md5sig=$md5sig merchant=$merchant_id order=$order_id status=$status_code\n",
    FILE_APPEND);

if ($merchant_id !== PAYHERE_MERCHANT_ID || $local_sig !== $md5sig) {
    file_put_contents($logFile, date('Y-m-d H:i:s')." SIGNATURE MISMATCH\n", FILE_APPEND);
    http_response_code(400);
    exit('Invalid signature');
}

// Load payment row
$stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id=?");
$stmt->execute([$order_id]);
$payment = $stmt->fetch();

if (!$payment) {
    file_put_contents($logFile, date('Y-m-d H:i:s')." ORDER NOT FOUND: $order_id\n", FILE_APPEND);
    http_response_code(404);
    exit('Order not found');
}

$card_last4 = $card_no
    ? substr(preg_replace('/[^0-9X]/', '', $card_no), -4)
    : null;

if ($status_code == '2') {
    // SUCCESS
    $pdo->prepare("
        UPDATE payments
        SET status='Paid',
            transaction_ref=?,
            card_holder=?,
            card_last4=?,
            card_type=?,
            paid_at=NOW()
        WHERE id=?
    ")->execute([$payment_id, $card_holder, $card_last4, $method, $payment['id']]);

    // Mark credit as Used in pending_transactions
    if (isset($payment['wallet_applied']) && floatval($payment['wallet_applied']) > 0) {
        markWalletCreditUsed($pdo, $payment['user_id'], $payment['booking_id'], $payment['wallet_applied']);
    }

    file_put_contents($logFile, date('Y-m-d H:i:s')." PAYMENT MARKED PAID: order=$order_id\n", FILE_APPEND);

    // ── Send booking confirmation email to the customer (only if not already sent) ──
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
            // Merge fresh payment data (transaction_ref may have just been set)
            $freshPay = $pdo->prepare("SELECT * FROM payments WHERE id=?");
            $freshPay->execute([$payment['id']]);
            $payRow = $freshPay->fetch() ?: $payment;

            // Only send if payment-return.php hasn't already sent it
            if (empty($payRow['confirmation_email_sent'])) {
                sendBookingConfirmationEmail($bk['user_email'], $bk['user_name'], $bk, $payRow);

                $pdo->prepare("UPDATE payments SET confirmation_email_sent=1 WHERE id=?")
                    ->execute([$payment['id']]);

                file_put_contents($logFile, date('Y-m-d H:i:s')." CONFIRMATION EMAIL SENT to ".$bk['user_email']."\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, date('Y-m-d H:i:s')." EMAIL ALREADY SENT (skipped) for order=$order_id\n", FILE_APPEND);
            }
        }
    } catch (Exception $ex) {
        file_put_contents($logFile, date('Y-m-d H:i:s')." EMAIL ERROR: ".$ex->getMessage()."\n", FILE_APPEND);
    }
    // ──────────────────────────────────────────────────────────────────────────────

} elseif ($status_code == '0') {
    file_put_contents($logFile, date('Y-m-d H:i:s')." PAYMENT PENDING: order=$order_id\n", FILE_APPEND);

} else {
    $pdo->prepare("UPDATE payments SET status='Failed' WHERE id=?")->execute([$payment['id']]);
    if (isset($payment['wallet_applied']) && $payment['wallet_applied'] > 0) {
        $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id=?")->execute([$payment['wallet_applied'], $payment['user_id']]);
    }
    file_put_contents($logFile, date('Y-m-d H:i:s')." PAYMENT FAILED: order=$order_id status=$status_code\n", FILE_APPEND);
}

// Helper function to mark wallet credit as used in the database ledger
function markWalletCreditUsed($pdo, $userId, $bookingId, $walletApplied) {
    $walletApplied = floatval($walletApplied);
    if ($walletApplied <= 0) return;

    $stmt = $pdo->prepare("
        SELECT * FROM pending_transactions 
        WHERE user_id = ? AND status = 'Available' 
        ORDER BY created_at ASC
    ");
    $stmt->execute([$userId]);
    $txs = $stmt->fetchAll();

    $remaining = $walletApplied;
    foreach ($txs as $tx) {
        if ($remaining <= 0) break;
        
        $txAmount = floatval($tx['amount']);
        if ($txAmount <= $remaining) {
            // Use this entire transaction
            $pdo->prepare("
                UPDATE pending_transactions 
                SET status = 'Used', used_at = NOW(), used_for_booking_id = ? 
                WHERE id = ?
            ")->execute([$bookingId, $tx['id']]);
            $remaining -= $txAmount;
        } else {
            // Split the transaction:
            // 1. Update the original transaction to keep the unused balance
            $newUnusedAmount = $txAmount - $remaining;
            $pdo->prepare("
                UPDATE pending_transactions 
                SET amount = ? 
                WHERE id = ?
            ")->execute([$newUnusedAmount, $tx['id']]);

            // 2. Insert a new transaction representing the used portion
            $pdo->prepare("
                INSERT INTO pending_transactions 
                (user_id, original_booking_id, original_payment_id, amount, reason, status, used_at, used_for_booking_id)
                VALUES (?, ?, ?, ?, ?, 'Used', NOW(), ?)
            ")->execute([
                $userId,
                $tx['original_booking_id'],
                $tx['original_payment_id'],
                $remaining,
                $tx['reason'] . ' (Used portion)',
                $bookingId
            ]);
            $remaining = 0;
        }
    }
}

http_response_code(200);
echo "OK";