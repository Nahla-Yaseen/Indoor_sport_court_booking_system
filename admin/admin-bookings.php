<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/mailer.php';
requireAdmin();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['booking_id'], $_POST['new_status'])) {

    $newStatus = $_POST['new_status'];
    $bid       = intval($_POST['booking_id']);

    if ($newStatus === 'Cancelled') {
        $pdo->prepare("UPDATE bookings SET status='Cancelled' WHERE id=?")
            ->execute([$bid]);
        $pdo->prepare("DELETE FROM blocked_slots WHERE reason LIKE ?")
            ->execute(['%Booking #'.$bid.'%']);
        $pdo->prepare("DELETE FROM booked_slots WHERE booking_id=?")
            ->execute([$bid]);

        // Refund advance payment to user wallet
        $payStmt = $pdo->prepare(
            "SELECT * FROM payments WHERE booking_id=? AND status='Paid' ORDER BY id DESC LIMIT 1"
        );
        $payStmt->execute([$bid]);
        $payment = $payStmt->fetch();
        if ($payment) {
            $refund = floatval($payment['amount']) + floatval($payment['wallet_applied'] ?? 0);
            $pdo->prepare("UPDATE payments SET status='Cancelled' WHERE id=?")
                ->execute([$payment['id']]);
            if ($refund > 0) {
                $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id=?")
                    ->execute([$refund, $payment['user_id']]);
                $pdo->prepare("
                    INSERT INTO pending_transactions
                        (user_id, original_booking_id, original_payment_id, amount, reason, status)
                    VALUES (?, ?, ?, ?, 'Admin cancelled – advance refunded', 'Available')
                ")->execute([$payment['user_id'], $bid, $payment['id'], $refund]);
            }
        }

        // ── Send cancellation email to the customer ─────────────────────────
        try {
            $bkStmt = $pdo->prepare("
                SELECT b.*, u.name AS user_name, u.email AS user_email,
                       c.name AS court_name
                FROM bookings b
                JOIN users  u ON b.user_id  = u.id
                JOIN courts c ON b.court_id = c.id
                WHERE b.id = ?
            ");
            $bkStmt->execute([$bid]);
            $bk = $bkStmt->fetch();

            if ($bk) {
                // Build readable time-slot string
                $slotStmt = $pdo->prepare("
                    SELECT slot_time FROM booked_slots
                    WHERE booking_id = ? ORDER BY slot_time ASC
                ");
                $slotStmt->execute([$bid]);
                $slots = $slotStmt->fetchAll();
                $slotStr = !empty($slots)
                    ? implode(', ', array_map(fn($s) => $s['slot_time'], $slots))
                    : (($bk['start_time'] ?? '') . ' – ' . ($bk['end_time'] ?? ''));
                $bk['time_slots'] = $slotStr;

                $refundForEmail = isset($payment) && $payment ? $refund : 0;
                sendBookingCancellationEmail($bk['user_email'], $bk['user_name'], $bk, $refundForEmail);
            }
        } catch (Exception $ex) {
            // Email failure should not break the admin workflow
        }
        // ─────────────────────────────────────────────────────────────
    }

    $backFilter = $_POST['filter_back'] ?? 'All';
    header("Location: admin-bookings.php?filter=".$backFilter); exit();
}

$filter  = $_GET['filter'] ?? 'All';
$allowed = ['All','Confirmed','Cancelled'];
if (!in_array($filter, $allowed)) $filter = 'All';

if ($filter === 'All') {
    $bookings = $pdo->query("
        SELECT b.*, u.name AS user_name, u.email AS user_email, c.name AS court_name
        FROM bookings b
        JOIN users u  ON b.user_id  = u.id
        JOIN courts c ON b.court_id = c.id
        ORDER BY b.booked_at DESC
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT b.*, u.name AS user_name, u.email AS user_email, c.name AS court_name
        FROM bookings b
        JOIN users u  ON b.user_id  = u.id
        JOIN courts c ON b.court_id = c.id
        WHERE b.status=?
        ORDER BY b.booked_at DESC
    ");
    $stmt->execute([$filter]);
    $bookings = $stmt->fetchAll();
}

// Stats
$sTotal     = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$sConfirmed = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Confirmed'")->fetchColumn();

$sCancelled = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Cancelled'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Bookings – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
</head>
<body>
<nav class="navbar">
  <div class="logo">🏸 Adam <span>Indoors</span></div>
  <div class="nav-links">
    <span style="color:rgba(255,255,255,0.8);font-size:13px;">Admin Panel</span>
    <a href="../logout.php" class="btn-nav">Logout</a>
  </div>
</nav>
<div class="admin-wrapper">
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="admin-main">
    <div class="page-header">
      <h1>📋 Manage Bookings</h1>
      <p>View and manage all court bookings</p>
    </div>

    <div style="padding:28px 30px;">

      <!-- STATS -->
      <div class="stats-grid" style="margin-bottom:24px;">
        <div class="stat-card">
          <div class="stat-icon">📋</div>
          <div class="stat-label">Total</div>
          <div class="stat-value"><?= $sTotal ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">✅</div>
          <div class="stat-label">Confirmed</div>
          <div class="stat-value"><?= $sConfirmed ?></div>
        </div>

        <div class="stat-card s-red">
          <div class="stat-icon">❌</div>
          <div class="stat-label">Cancelled</div>
          <div class="stat-value"><?= $sCancelled ?></div>
        </div>
      </div>

      <!-- FILTER TABS -->
      <div class="filter-tabs">
        <?php foreach (['All','Confirmed','Cancelled'] as $tab): ?>
          <a href="?filter=<?= $tab ?>" class="filter-tab"
             style="<?= $filter===$tab
               ? 'background:var(--primary-mid);color:white;border-color:var(--primary-mid);'
               : '' ?>">
            <?= $tab ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Customer</th>
              <th>Court</th>
              <th>Type</th>
              <th>Date</th>
              <th>Time</th>
              <th>Slots</th>
              <th>Price (LKR)</th>
              <th>Payment</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($bookings)): ?>
              <tr>
                <td colspan="11" style="text-align:center;
                    color:var(--text-muted);padding:40px;">
                  No bookings found.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($bookings as $b):
                // Check payment status
                $payQ = $pdo->prepare("
                    SELECT payment_method, status
                    FROM payments
                    WHERE booking_id=?
                    ORDER BY id DESC LIMIT 1
                ");
                $payQ->execute([$b['id']]);
                $pay = $payQ->fetch();

                // Slot count
                $slots     = json_decode($b['selected_slots'] ?? '[]', true);
                $slotCount = count($slots);
              ?>
              <tr>
                <td><strong>#<?= $b['id'] ?></strong></td>
                <td>
                  <?= htmlspecialchars($b['user_name']) ?>
                  <br/>
                  <small style="color:var(--text-muted);">
                    <?= htmlspecialchars($b['user_email']) ?>
                  </small>
                </td>
                <td><?= htmlspecialchars($b['court_name']) ?></td>
                <td>
                  <?= htmlspecialchars($b['booking_type']) ?>
                  <?php if ($b['package_name']): ?>
                    <br/>
                    <small style="color:var(--text-muted);">
                      <?= htmlspecialchars($b['package_name']) ?>
                    </small>
                  <?php endif; ?>
                </td>
                <td><?= $b['date'] ?></td>
                <td>
                  <?= substr($b['start_time'],0,5) ?> –
                  <?= substr($b['end_time'],0,5) ?>
                </td>
                <td>
                  <?= $slotCount > 0
                      ? $slotCount.' slot'.($slotCount>1?'s':'')
                      : '–' ?>
                </td>
                <td><?= number_format($b['total_price']) ?></td>
                <td>
                  <?php if ($pay && $pay['status'] === 'Paid'): ?>
                    <span class="badge badge-confirmed" style="font-size:10px;">
                      <?= htmlspecialchars($pay['payment_method']) ?>
                    </span>
                  <?php else: ?>
                    <span style="color:var(--text-muted);font-size:11px;">
                      Unpaid
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge badge-<?= strtolower($b['status']) ?>">
                    <?= $b['status'] ?>
                  </span>
                </td>
                <td class="table-actions">
                  <?php
                    $isPaid = $pay && $pay['status'] === 'Paid';
                    if ($b['status'] === 'Confirmed' && $isPaid):
                  ?>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Cancel booking #<?= $b['id'] ?>?\nThe advance payment will be refunded to the user\'s wallet.')">
                      <input type="hidden" name="booking_id"  value="<?= $b['id'] ?>"/>
                      <input type="hidden" name="new_status"  value="Cancelled"/>
                      <input type="hidden" name="filter_back" value="<?= $filter ?>"/>
                      <button class="btn btn-danger btn-sm">❌ Cancel</button>
                    </form>
                  <?php else: ?>
                    <span style="color:var(--text-muted);font-size:11px;">No actions</span>
                  <?php endif; ?>

                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>
</body>
</html>