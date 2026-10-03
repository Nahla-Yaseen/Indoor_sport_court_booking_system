<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /myproject/login.php"); exit();
}
require_once __DIR__ . '/includes/db.php';

// Handle cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    $cid = intval($_POST['cancel_id']);

    $payQ = $pdo->prepare("SELECT * FROM payments WHERE booking_id=?");
    $payQ->execute([$cid]);
    $payRow = $payQ->fetch();

    $pdo->prepare("UPDATE bookings SET status='Cancelled' WHERE id=? AND user_id=? AND status='Confirmed'")
        ->execute([$cid, $_SESSION['user_id']]);

    $pdo->prepare("DELETE FROM booked_slots WHERE booking_id=?")->execute([$cid]);

    if ($payRow) {
        $pdo->prepare("UPDATE payments SET status='Cancelled' WHERE id=?")
            ->execute([$payRow['id']]);

        $refundAmount = 0.00;
        if ($payRow['status'] === 'Paid') {
            $refundAmount = floatval($payRow['amount']) + floatval($payRow['wallet_applied'] ?? 0);
        } else {
            $refundAmount = floatval($payRow['wallet_applied'] ?? 0);
        }

        if ($refundAmount > 0) {
            $pdo->prepare("
                INSERT INTO pending_transactions
                (user_id, original_booking_id, original_payment_id, amount, reason, status)
                VALUES (?, ?, ?, ?, 'Booking cancelled – credit saved to wallet', 'Available')
            ")->execute([$_SESSION['user_id'], $cid, $payRow['id'], $refundAmount]);

            $pdo->prepare("
                UPDATE users 
                SET wallet_balance = wallet_balance + ? 
                WHERE id = ?
            ")->execute([$refundAmount, $_SESSION['user_id']]);
        }
    }
    header("Location: /myproject/my-bookings.php"); exit();
}

$filter  = $_GET['filter'] ?? 'All';
$allowed = ['All','Confirmed','Ongoing','Completed','Cancelled'];
if (!in_array($filter, $allowed)) $filter = 'All';

if ($filter === 'All') {
    $stmt = $pdo->prepare("
        SELECT b.*, c.name AS court_name
        FROM bookings b JOIN courts c ON b.court_id=c.id
        WHERE b.user_id=? ORDER BY b.booked_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("
        SELECT b.*, c.name AS court_name
        FROM bookings b JOIN courts c ON b.court_id=c.id
        WHERE b.user_id=? AND b.status=? ORDER BY b.booked_at DESC
    ");
    $stmt->execute([$_SESSION['user_id'], $filter]);
}
$bookings = $stmt->fetchAll();

// Wallet balance
$walletStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount),0) AS wallet_total
    FROM pending_transactions
    WHERE user_id=? AND status='Available'
");
$walletStmt->execute([$_SESSION['user_id']]);
$walletBalance = floatval($walletStmt->fetchColumn());

$initial = strtoupper(substr($_SESSION['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Bookings – Adam Indoors</title>
  <link rel="stylesheet" href="/myproject/css/style.css"/>
  <style>
    .pay-btn {
      display: block;
      background: var(--primary-mid);
      color: white; border: none;
      border-radius: var(--r-sm);
      padding: 6px 14px; font-size: 12px;
      font-weight: 600; font-family: 'Poppins', sans-serif;
      cursor: pointer; text-decoration: none;
      text-align: center; transition: all 0.2s;
      margin-bottom: 4px; width: 100%;
    }
    .pay-btn:hover { background: var(--primary-light); color: white; }

    .paid-badge {
      display: inline-block;
      background: var(--primary-soft); color: var(--primary);
      border: 1px solid var(--primary-light);
      border-radius: var(--r-sm);
      padding: 4px 10px; font-size: 11px; font-weight: 700;
    }
    .pending-badge {
      display: inline-block;
      background: #fff3e0; color: #e65100;
      border: 1px solid #ff8f00;
      border-radius: var(--r-sm);
      padding: 4px 10px; font-size: 11px; font-weight: 700;
    }

    /* WALLET BOX */
    .wallet-box {
      background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 100%);
      color: white; border-radius: var(--r-lg);
      padding: 20px 24px; margin-bottom: 22px;
      display: flex; align-items: center;
      justify-content: space-between; flex-wrap: wrap; gap: 14px;
      box-shadow: var(--shadow-md);
    }
    .wallet-left { display: flex; align-items: center; gap: 16px; }
    .wallet-icon {
      width: 52px; height: 52px; border-radius: 50%;
      background: rgba(255,255,255,0.18);
      display: flex; align-items: center;
      justify-content: center; font-size: 26px;
    }
    .wallet-label { font-size: 12px; opacity: 0.78; margin-bottom: 4px; }
    .wallet-amount { font-size: 24px; font-weight: 700; }
    .wallet-note { font-size: 11px; opacity: 0.72; margin-top: 3px; }
    .wallet-zero {
      background: var(--gray-100);
      border: 1.5px dashed var(--gray-300);
    }
    .wallet-zero .wallet-amount { color: var(--text-muted); font-size: 20px; }
    .wallet-zero .wallet-label  { color: var(--text-muted); }
    .wallet-zero .wallet-note   { color: var(--text-muted); }

    .bal-due-zero { color: var(--primary); font-weight: 600; font-size: 12px; }
    .bal-due-pos  { color: #e65100; font-weight: 700; font-size: 12px; }
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
    <a href="/myproject/dashboard.php"         class="usb-link"><span class="usb-icon-sm">📊</span> Dashboard</a>
    <a href="/myproject/about.php"             class="usb-link"><span class="usb-icon-sm">ℹ️</span> About Us</a>
    <a href="/myproject/my-bookings.php"       class="usb-link active"><span class="usb-icon-sm">📋</span> My Bookings</a>
    <a href="/myproject/coaches.php"           class="usb-link"><span class="usb-icon-sm">🎽</span> Coaches</a>
    <a href="/myproject/my-coach-bookings.php" class="usb-link"><span class="usb-icon-sm">📝</span> My Coach Bookings</a>
    <div class="usb-section">Account</div>
    <a href="/myproject/logout.php" class="usb-link logout-link"><span class="usb-icon-sm">🚪</span> Logout</a>
    <div class="usb-bottom">© 2025 Adam Indoors</div>
  </div>

  <div class="user-main">
    <div class="user-topbar">
      <div class="utb-title">My Bookings</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">

      <div class="page-header" style="border-radius:var(--r-md);margin-bottom:20px;">
        <h1>📋 My Bookings</h1>
        <p>View and manage all your court reservations</p>
      </div>

      <!-- WALLET BOX -->
      <div class="wallet-box <?= $walletBalance <= 0 ? 'wallet-zero' : '' ?>">
        <div class="wallet-left">
          <div class="wallet-icon">💰</div>
          <div>
            <div class="wallet-label">MY WALLET BALANCE</div>
            <div class="wallet-amount">
              LKR <?= number_format($walletBalance, 2) ?>
            </div>
            <div class="wallet-note">
              <?= $walletBalance > 0
                  ? 'Credit from cancelled bookings — applied automatically on next payment'
                  : 'No credits available — cancelling a paid booking adds credit here' ?>
            </div>
          </div>
        </div>
        <?php if ($walletBalance > 0): ?>
        <div style="background:rgba(255,255,255,0.15);border-radius:var(--r-md);
                    padding:10px 16px;font-size:12px;text-align:center;">
          <div style="font-size:11px;opacity:0.8;margin-bottom:4px;">Auto-applied at checkout</div>
          <div style="font-size:18px;font-weight:700;">
            LKR <?= number_format($walletBalance, 2) ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- FILTER TABS -->
      <div class="filter-tabs">
        <?php foreach (['All','Confirmed','Cancelled'] as $tab): ?>
          <a href="?filter=<?= $tab ?>" class="filter-tab"
             style="<?= $filter===$tab?'background:var(--primary-mid);color:white;border-color:var(--primary-mid);':'' ?>">
            <?= $tab ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Court</th><th>Type</th><th>Date</th>
              <th>Start</th><th>End</th>
              <th>Total (LKR)</th>
              <th>Paid (LKR)</th>
              <th>Bal Due (LKR)</th>
              <th>Status</th><th>Payment</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($bookings)): ?>
              <tr>
                <td colspan="12" style="text-align:center;
                    color:var(--text-muted);padding:40px;">
                  No bookings found.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($bookings as $b):
                 // Payment
                 $payQ = $pdo->prepare("
                     SELECT * FROM payments WHERE booking_id=?
                     ORDER BY CASE status 
                         WHEN 'Paid' THEN 1 
                         WHEN 'Pending' THEN 2 
                         WHEN 'Cancelled' THEN 3 
                         ELSE 4 
                     END ASC, id DESC LIMIT 1
                 ");
                 $payQ->execute([$b['id']]);
                 $payRow = $payQ->fetch();
                 $isPaid    = $payRow && $payRow['status'] === 'Paid';
                 $isPending = $payRow && $payRow['status'] === 'Pending';

                 // Coach price
                 $coachQ = $pdo->prepare("
                     SELECT COALESCE(SUM(total_price),0) AS cp
                     FROM coach_bookings
                     WHERE court_booking_id=? AND status='Confirmed'
                 ");
                 $coachQ->execute([$b['id']]);
                 $coachRow   = $coachQ->fetch();
                 $coachPrice = floatval($coachRow['cp']);
                 $totalShow  = floatval($b['total_price']) + $coachPrice;

                 // Calculate paid amount and balance due
                 if ($b['status'] === 'Completed') {
                     $paidAmount = $totalShow;
                     $balanceDue = 0;
                 } else {
                     $paidAmount = $isPaid ? (floatval($payRow['amount']) + floatval($payRow['wallet_applied'] ?? 0)) : 0;
                     $balanceDue = max(0, $totalShow - $paidAmount);
                 }
              ?>
              <tr>
                <td><strong>#<?= $b['id'] ?></strong></td>
                <td><?= htmlspecialchars($b['court_name']) ?></td>
                <td>
                  <?= htmlspecialchars($b['booking_type']) ?>
                  <?php if ($b['package_name']): ?>
                    <br/><small style="color:var(--text-muted);">
                      <?= htmlspecialchars($b['package_name']) ?>
                    </small>
                  <?php endif; ?>
                </td>
                <td><?= $b['date'] ?></td>
                <td><?= substr($b['start_time'],0,5) ?></td>
                <td><?= substr($b['end_time'],0,5) ?></td>
                <td>
                  <?= number_format($totalShow) ?>
                  <?php if ($coachPrice > 0): ?>
                    <br/><small style="color:var(--text-muted);">incl. coach</small>
                  <?php endif; ?>
                </td>
                <td>
                  <?= $paidAmount > 0
                      ? '<span style="color:var(--primary);font-weight:600;">'.number_format($paidAmount).'</span>'
                      : '<span style="color:var(--text-muted);">0</span>' ?>
                </td>
                <td>
                  <?php if ($b['status'] === 'Cancelled'): ?>
                    <span style="color:var(--text-muted);font-size:11px;">–</span>
                  <?php elseif ($balanceDue <= 0): ?>
                    <span class="bal-due-zero">✅ Nil</span>
                  <?php else: ?>
                    <span class="bal-due-pos">
                      LKR <?= number_format($balanceDue) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge badge-<?= strtolower($b['status']) ?>">
                    <?= $b['status'] ?>
                  </span>
                </td>
                <td>
                  <?php if ($isPaid): ?>
                    <span class="paid-badge" style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;">
                      ✅ Advance paid
                    </span>
                  <?php elseif ($b['status'] === 'Completed'): ?>
                    <span class="paid-badge">
                      ✅ Paid
                    </span>
                  <?php elseif ($isPending): ?>
                    <span class="pending-badge">⏳ Processing</span>
                  <?php elseif ($b['status'] === 'Confirmed'): ?>
                    <span style="color:var(--text-muted);font-size:11px;">Unpaid</span>
                  <?php else: ?>–<?php endif; ?>
                </td>
                <td>
                  <?php if ($b['status'] === 'Confirmed' && !$isPaid): ?>
                    <a href="/myproject/payment.php?booking_id=<?= $b['id'] ?>"
                       class="pay-btn">💳 Pay Now</a>
                  <?php endif; ?>
                  <?php if ($b['status'] === 'Confirmed'): ?>
                    <form method="POST"
                          onsubmit="return confirm('Cancel booking #<?= $b['id'] ?>?<?= $isPaid?' Paid amount will be added to your wallet.':'' ?>')">
                      <input type="hidden" name="cancel_id" value="<?= $b['id'] ?>"/>
                      <button type="submit" class="btn btn-danger btn-sm"
                              style="width:100%;padding:6px 14px;font-size:12px;">
                        Cancel
                      </button>
                    </form>
                  <?php elseif ($b['status'] === 'Cancelled' && $payRow && ($payRow['status'] === 'Cancelled' || $payRow['status'] === 'Paid')): ?>
                    <span style="font-size:11px;color:var(--warning);">💰 In Wallet</span>
                  <?php else: ?>–<?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:20px;">
        <a href="/myproject/dashboard.php" class="btn btn-outline">← Back to Dashboard</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>