<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php"); exit();
}

require_once __DIR__ . '/includes/mailer.php';

require_once __DIR__ . '/includes/db.php';

$order_id = $_GET['order_id'] ?? '';
$payment  = null;

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

if ($order_id) {
    $stmt = $pdo->prepare("
        SELECT p.*, b.date AS booking_date,
               c.name AS court_name
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        JOIN courts c   ON b.court_id   = c.id
        WHERE p.order_id = ? AND p.user_id = ?
    ");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $payment = $stmt->fetch();

    if ($payment && $payment['status'] === 'Pending') {
        // Fallback: update payment status to Paid since return URL is reached
        $pdo->prepare("
            UPDATE payments
            SET status='Paid', payment_method='PayHere', paid_at=NOW()
            WHERE id=?
        ")->execute([$payment['id']]);

        // Mark the wallet credit as Used in pending_transactions
        if (isset($payment['wallet_applied']) && floatval($payment['wallet_applied']) > 0) {
            markWalletCreditUsed($pdo, $payment['user_id'], $payment['booking_id'], $payment['wallet_applied']);
        }

        $payment['status'] = 'Paid';
    }

    // ── Send confirmation email (only once) ─────────────────────────────────
    if ($payment && $payment['status'] === 'Paid' && empty($payment['confirmation_email_sent'])) {
        try {
            $bkStmt = $pdo->prepare("
                SELECT b.*, u.name AS user_name, u.email AS user_email,
                       c.name AS court_name
                FROM bookings b
                JOIN users  u ON b.user_id  = u.id
                JOIN courts c ON b.court_id = c.id
                WHERE b.id = ?
            ");
            $bkStmt->execute([$payment['booking_id']]);
            $bk = $bkStmt->fetch();

            // Reload fresh payment row so coach_booking_id is available
            $freshPay = $pdo->prepare("SELECT * FROM payments WHERE id=?");
            $freshPay->execute([$payment['id']]);
            $payRow = $freshPay->fetch() ?: $payment;

            if ($bk) {
                sendBookingConfirmationEmail($bk['user_email'], $bk['user_name'], $bk, $payRow);

                // Mark email as sent to prevent duplicate from payhere-notify.php
                $pdo->prepare("UPDATE payments SET confirmation_email_sent=1 WHERE id=?")
                    ->execute([$payment['id']]);
            }
        } catch (Exception $ex) {
            // Non-fatal — do not block the page
        }
    }
    // ────────────────────────────────────────────────────────────────────────
}

$status = $payment['status'] ?? 'Pending';
$initial = strtoupper(substr($_SESSION['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Payment Status – Adam Indoors</title>
  <link rel="stylesheet" href="/css/style.css"/>
  <style>
    .status-box {
      max-width: 480px; margin: 40px auto;
      background: white; border-radius: var(--r-lg);
      box-shadow: var(--shadow-lg); overflow: hidden;
      text-align: center;
    }
    .sb-header {
      padding: 36px 28px 24px;
    }
    .sb-icon { font-size: 64px; margin-bottom: 14px; }
    .sb-header h2 { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
    .sb-header p  { font-size: 14px; color: var(--text-muted); line-height: 1.7; }
    .sb-details {
      background: var(--primary-pale);
      padding: 16px 24px;
      text-align: left;
    }
    .sbd-row {
      display: flex; justify-content: space-between;
      font-size: 13px; padding: 6px 0;
      border-bottom: 1px dashed var(--gray-200);
    }
    .sbd-row:last-child { border-bottom: none; }
    .sbd-row .sdl { color: var(--text-muted); }
    .sb-actions {
      padding: 20px 24px;
      display: flex; gap: 12px;
      justify-content: center; flex-wrap: wrap;
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
      <div><strong><?= htmlspecialchars($_SESSION['name']) ?></strong><small>Member</small></div>
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
      <div class="utb-title">Payment Status</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">
      <div class="status-box">

        <?php if ($status === 'Paid'): ?>
        <div class="sb-header">
          <div class="sb-icon">🎉</div>
          <h2 style="color:var(--primary);">Payment Successful!</h2>
          <p>Your booking has been confirmed and payment received.</p>
        </div>
        <?php if ($payment): ?>
        <div class="sb-details">
          <div class="sbd-row">
            <span class="sdl">Transaction Ref</span>
            <span style="font-family:monospace;font-size:11px;">
              <?= htmlspecialchars($payment['transaction_ref'] ?? '–') ?>
            </span>
          </div>
          <div class="sbd-row">
            <span class="sdl">Court</span>
            <span><?= htmlspecialchars($payment['court_name']) ?></span>
          </div>
          <div class="sbd-row">
            <span class="sdl">Booking Date</span>
            <span><?= htmlspecialchars($payment['booking_date']) ?></span>
          </div>
          <div class="sbd-row">
            <span class="sdl">Amount Paid</span>
            <span style="font-weight:700;color:var(--primary);">
              LKR <?= number_format($payment['amount'], 2) ?>
            </span>
          </div>
          <div class="sbd-row">
            <span class="sdl">Method</span>
            <span><?= htmlspecialchars($payment['card_type'] ?? $payment['payment_method']) ?></span>
          </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="sb-header">
          <div class="sb-icon">⏳</div>
          <h2>Payment Received — Confirming...</h2>
          <p>
            We are confirming your payment with PayHere.
            This usually takes a few seconds.<br/><br/>
            Please click <strong>View My Bookings</strong> below
            and refresh after a few seconds to see the updated status.
          </p>
        </div>
        <?php endif; ?>

        <div class="sb-actions">
          <a href="/my-bookings.php" class="btn btn-primary">
            📋 View My Bookings
          </a>
          <a href="/dashboard.php" class="btn btn-outline">
            🏠 Dashboard
          </a>
        </div>

      </div>
    </div>
  </div>
</div>
</body>
</html>