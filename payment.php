<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php"); exit();
}
require_once __DIR__ . '/config/payhere-config.php';

require_once __DIR__ . '/includes/db.php';

$booking_id       = intval($_GET['booking_id']       ?? 0);
$coach_booking_id = intval($_GET['coach_booking_id'] ?? 0);
if (!$booking_id) { header("Location: /my-bookings.php"); exit(); }

// Load court booking
$stmt = $pdo->prepare("
    SELECT b.*, c.name AS court_name
    FROM bookings b
    JOIN courts c ON b.court_id = c.id
    WHERE b.id = ? AND b.user_id = ?
");
$stmt->execute([$booking_id, $_SESSION['user_id']]);
$booking = $stmt->fetch();
if (!$booking) { header("Location: /my-bookings.php"); exit(); }

// Load coach booking if exists via URL param
$coachBooking = null;
if ($coach_booking_id) {
    $cs = $pdo->prepare("
        SELECT cb.*, co.name AS coach_name
        FROM coach_bookings cb
        JOIN coaches co ON cb.coach_id = co.id
        WHERE cb.id = ? AND cb.user_id = ?
    ");
    $cs->execute([$coach_booking_id, $_SESSION['user_id']]);
    $coachBooking = $cs->fetch();
}

// If not found via URL, look up by court_booking_id
if (!$coachBooking) {
    $cs2 = $pdo->prepare("
        SELECT cb.*, co.name AS coach_name
        FROM coach_bookings cb
        JOIN coaches co ON cb.coach_id = co.id
        WHERE cb.court_booking_id = ? AND cb.user_id = ? AND cb.status = 'Confirmed'
        ORDER BY cb.id DESC LIMIT 1
    ");
    $cs2->execute([$booking_id, $_SESSION['user_id']]);
    $coachBooking = $cs2->fetch();
    if ($coachBooking) $coach_booking_id = $coachBooking['id'];
}

// Block re-payment if already paid
$already = $pdo->prepare("SELECT id FROM payments WHERE booking_id=? AND status='Paid'");
$already->execute([$booking_id]);
if ($already->fetch()) { header("Location: /my-bookings.php"); exit(); }

// Calculate prices
$courtPrice  = floatval($booking['total_price']);
$coachPrice  = $coachBooking ? floatval($coachBooking['total_price']) : 0;
$totalAmount = $courtPrice + $coachPrice;

// 20% Advance Payment Rule
$fullAmount = $totalAmount;
$advanceAmount = $fullAmount * 0.20;
$totalAmount = $advanceAmount;

// Apply available wallet balance
$creditApplied = 0;

// Retrieve the user's current wallet balance
$uStmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id=?");
$uStmt->execute([$_SESSION['user_id']]);
$walletBal = floatval($uStmt->fetchColumn());

// Reuse existing pending payment row OR create new one
$payStmt = $pdo->prepare("
    SELECT * FROM payments
    WHERE booking_id=? AND status='Pending'
    ORDER BY id DESC LIMIT 1
");
$payStmt->execute([$booking_id]);
$payment = $payStmt->fetch();

if ($payment) {
    $order_id = $payment['order_id'];
    
    // The previous wallet amount applied to this pending payment
    $prevApplied = floatval($payment['wallet_applied'] ?? 0);
    
    // Temporarily restore the previous applied amount to the wallet balance for recalculation
    $totalWalletBalForCalc = $walletBal + $prevApplied;
    
    // Recalculate based on current prices and total wallet balance
    $creditApplied = min($totalWalletBalForCalc, $advanceAmount);
    $totalAmount = max(0.00, $advanceAmount - $creditApplied);
    
    // Update the payments row
    $pdo->prepare("
        UPDATE payments 
        SET amount=?, wallet_applied=? 
        WHERE id=?
    ")->execute([$totalAmount, $creditApplied, $payment['id']]);
    
    // Update the user's wallet balance with the difference
    $walletDiff = $creditApplied - $prevApplied;
    if ($walletDiff != 0) {
        $pdo->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance - ? 
            WHERE id=?
        ")->execute([$walletDiff, $_SESSION['user_id']]);
    }
} else {
    // No pending payment exists, create a new one
    $order_id = 'ORD-' . $booking_id . '-' . time();
    $creditApplied = min($walletBal, $advanceAmount);
    $totalAmount = max(0.00, $advanceAmount - $creditApplied);
    
    $pdo->prepare("
        INSERT INTO payments
        (booking_id, coach_booking_id, user_id, order_id, amount, wallet_applied, payment_method, status)
        VALUES (?, ?, ?, ?, ?, ?, 'PayHere', 'Pending')
    ")->execute([
        $booking_id,
        $coach_booking_id ?: null,
        $_SESSION['user_id'],
        $order_id,
        $totalAmount,
        $creditApplied
    ]);
    
    if ($creditApplied > 0) {
        $pdo->prepare("
            UPDATE users 
            SET wallet_balance = wallet_balance - ? 
            WHERE id=?
        ")->execute([$creditApplied, $_SESSION['user_id']]);
    }
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

// If fully covered by credit
if ($totalAmount <= 0) {
    $pdo->prepare("
        UPDATE payments SET status='Paid', payment_method='Credit', paid_at=NOW()
        WHERE order_id=?
    ")->execute([$order_id]);
    
    // Mark the wallet credit as Used in pending_transactions
    markWalletCreditUsed($pdo, $_SESSION['user_id'], $booking_id, $creditApplied);
    
    header("Location: /payment-return.php?order_id=".urlencode($order_id));
    exit();
}

// Customer details
$u = $pdo->prepare("SELECT * FROM users WHERE id=?");
$u->execute([$_SESSION['user_id']]);
$user = $u->fetch();
$nameParts = explode(' ', trim($user['name']), 2);
$firstName = $nameParts[0];
$lastName  = $nameParts[1] ?? $nameParts[0];

$amountFormatted = number_format($totalAmount, 2, '.', '');
$currency = 'LKR';

// Handle Stripe Checkout creation when user clicks "Pay"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_with_stripe'])) {
    
    $stripe_secret = STRIPE_SECRET_KEY;
    
    // Stripe expects amount in smallest currency unit (cents/cents)
    $amount_in_cents = round($totalAmount * 100);

    // Build the POST data for Stripe
    $postData = [
        'payment_method_types' => ['card'],
        'line_items' => [
            [
                'price_data' => [
                    'currency' => 'lkr',
                    'product_data' => [
                        'name' => 'Court Booking #' . $booking_id . ($coachBooking ? ' + Coach' : ''),
                    ],
                    'unit_amount' => $amount_in_cents,
                ],
                'quantity' => 1,
            ],
        ],
        'mode' => 'payment',
        'success_url' => APP_BASE_URL . '/payment-return.php?order_id=' . urlencode($order_id),
        'cancel_url' => APP_BASE_URL . '/payment-cancel.php?order_id=' . urlencode($order_id),
        'client_reference_id' => $order_id,
        'customer_email' => $user['email']
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/checkout/sessions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret . ":");
    
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_status === 200) {
        $session = json_decode($response, true);
        header("Location: " . $session['url']);
        exit();
    } else {
        $error = json_decode($response, true);
        $stripe_error = $error['error']['message'] ?? 'Unknown Stripe Error';
    }
}

$initial = strtoupper(substr($user['name'], 0, 1));
$slots   = json_decode($booking['selected_slots'] ?? '[]', true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Payment – Adam Indoors</title>
  <link rel="stylesheet" href="/css/style.css"/>
  <style>
    .pay-wrap { max-width:540px; margin:0 auto; padding:24px 16px 40px; }
    .pay-summary {
      background: white;
      border: 1.5px solid var(--primary-light);
      border-radius: var(--r-lg);
      overflow: hidden;
      margin-bottom: 22px;
      box-shadow: var(--shadow-sm);
    }
    .ps-header {
      background: var(--primary-soft);
      padding: 14px 18px;
      font-size: 13px; font-weight: 700; color: var(--primary);
      border-bottom: 1px solid var(--primary-light);
    }
    .ps-row {
      display: flex; justify-content: space-between;
      padding: 10px 18px; font-size: 13px;
      border-bottom: 1px dashed var(--gray-200);
    }
    .ps-row .psl { color: var(--text-muted); }
    .ps-row .psv { font-weight: 500; }
    .ps-credit {
      display: flex; justify-content: space-between;
      padding: 10px 18px; font-size: 13px;
      color: var(--primary); font-weight: 600;
      background: #e8f5e9;
      border-bottom: 1px dashed var(--gray-200);
    }
    .ps-total {
      display: flex; justify-content: space-between;
      padding: 14px 18px; font-size: 17px; font-weight: 700;
      color: var(--primary);
      background: var(--primary-soft);
      border-top: 2px solid var(--primary);
    }
    .stripe-btn {
      width: 100%; padding: 16px;
      background: #635bff;
      color: white; font-size: 16px; font-weight: 700;
      border: none; border-radius: var(--r-md);
      cursor: pointer; font-family: 'Poppins', sans-serif;
      transition: all 0.2s; display: flex;
      align-items: center; justify-content: center; gap: 10px;
    }
    .stripe-btn:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,91,255,0.3); }
    .secure-badge {
      text-align: center; font-size: 12px;
      color: var(--text-muted); margin-top: 12px;
      display: flex; align-items: center;
      justify-content: center; gap: 6px;
    }
    .error-notice {
      background: #ffebee; border: 1px solid #ef5350;
      border-radius: var(--r-sm); padding: 12px;
      font-size: 13px; color: #c62828; margin-bottom: 20px;
    }
  </style>
</head>
<body>
<div class="user-wrapper">

  <div class="user-sidebar">
    <div class="usb-brand">
      <div class="usb-icon">🏸</div>
      <div class="usb-title"><strong>Adam Indoors</strong><small>Sports Booking</small></div>
    </div>
    <div class="usb-user">
      <div class="usb-avatar"><?= $initial ?></div>
      <div><strong><?= htmlspecialchars($user['name']) ?></strong><small>Member</small></div>
    </div>
    <div class="usb-section">Menu</div>
    <a href="/dashboard.php"         class="usb-link"><span class="usb-icon-sm">📊</span> Dashboard</a>
    <a href="/about.php"             class="usb-link"><span class="usb-icon-sm">ℹ️</span> About Us</a>
    <a href="/my-bookings.php"       class="usb-link"><span class="usb-icon-sm">📋</span> My Bookings</a>
    <a href="/coaches.php"           class="usb-link"><span class="usb-icon-sm">🎽</span> Coaches</a>
    <a href="/my-coach-bookings.php" class="usb-link"><span class="usb-icon-sm">📝</span> My Coach Bookings</a>
    <div class="usb-section">Account</div>
    <a href="/logout.php" class="usb-link logout-link"><span class="usb-icon-sm">🚪</span> Logout</a>
    <div class="usb-bottom">© 2025 Adam Indoors</div>
  </div>

  <div class="user-main">
    <div class="user-topbar">
      <div class="utb-title">Payment</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($user['name']) ?></span>
      </div>
    </div>

    <div class="user-content">
      <div class="pay-wrap">

        <div class="page-header" style="border-radius:var(--r-md);margin-bottom:22px;">
          <h1>💳 Complete Your Payment</h1>
          <p>Secure checkout powered by Stripe</p>
        </div>

        <?php if (isset($stripe_error)): ?>
          <div class="error-notice">
            <strong>Payment Error:</strong> <?= htmlspecialchars($stripe_error) ?>
          </div>
        <?php endif; ?>

        <!-- PAYMENT SUMMARY -->
        <div class="pay-summary">
          <div class="ps-header">📋 Payment Summary</div>

          <div class="ps-row">
            <span class="psl">Booking #</span>
            <span class="psv">#<?= $booking_id ?></span>
          </div>
          <div class="ps-row">
            <span class="psl">Court</span>
            <span class="psv"><?= htmlspecialchars($booking['court_name']) ?></span>
          </div>
          <div class="ps-row">
            <span class="psl">Type</span>
            <span class="psv">
              <?= htmlspecialchars($booking['booking_type']) ?>
              <?= $booking['package_name'] ? ' – '.$booking['package_name'] : '' ?>
            </span>
          </div>
          <div class="ps-row">
            <span class="psl">Date</span>
            <span class="psv"><?= htmlspecialchars($booking['date']) ?></span>
          </div>
          <div class="ps-row">
            <span class="psl">Time</span>
            <span class="psv">
              <?= substr($booking['start_time'],0,5) ?> –
              <?= substr($booking['end_time'],0,5) ?>
            </span>
          </div>
          <div class="ps-row">
            <span class="psl">Slots</span>
            <span class="psv">
              <?= count($slots) ?> slot<?= count($slots)>1?'s':'' ?>
            </span>
          </div>
          <div class="ps-row">
            <span class="psl">Court Fee</span>
            <span class="psv">LKR <?= number_format($courtPrice) ?></span>
          </div>

          <?php if ($coachBooking): ?>
          <div class="ps-row">
            <span class="psl">Coach (<?= htmlspecialchars($coachBooking['coach_name']) ?>)</span>
            <span class="psv">LKR <?= number_format($coachPrice) ?></span>
          </div>
          <?php endif; ?>

          <div class="ps-row">
            <span class="psl">Booking Total</span>
            <span class="psv">LKR <?= number_format($fullAmount) ?></span>
          </div>
          
          <div class="ps-row" style="background: var(--warning-soft); color: var(--warning-dark);">
            <span class="psl"><strong>20% Advance Required</strong></span>
            <span class="psv"><strong>LKR <?= number_format($advanceAmount) ?></strong></span>
          </div>

          <?php if ($creditApplied > 0): ?>
          <div class="ps-credit">
            <span>💰 Wallet Balance Applied</span>
            <span>– LKR <?= number_format($creditApplied) ?></span>
          </div>
          <?php endif; ?>

          <div class="ps-total">
            <span>Amount to Pay Now</span>
            <span>LKR <?= number_format($totalAmount, 2) ?></span>
          </div>

          <div class="ps-row" style="border-top: 1px solid var(--gray-200); margin-top: 5px;">
            <span class="psl">Balance Due at Venue (80%)</span>
            <span class="psv" style="color:var(--danger); font-weight:700;">LKR <?= number_format($fullAmount - $advanceAmount) ?></span>
          </div>
        </div>

        <!-- STRIPE FORM -->
        <form method="POST" action="">
          <input type="hidden" name="pay_with_stripe" value="1">
          <button type="submit" class="stripe-btn">
            <span>💳</span>
            <span>Pay LKR <?= number_format($totalAmount, 2) ?> with Stripe</span>
          </button>
        </form>

        <div class="secure-badge">
          🔒 Secured by Stripe
        </div>

        <div style="text-align:center;margin-top:24px;">
          <a href="/my-bookings.php"
             style="font-size:12px;color:var(--text-muted);">
            ← Cancel and go back to My Bookings
          </a>
        </div>

      </div>
    </div>
  </div>
</div>
</body>
</html>