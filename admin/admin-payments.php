<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

// Handle Mark Full Paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_full_paid') {
    $bid = intval($_POST['booking_id']);

    // Fetch booking info
    $bStmt = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
    $bStmt->execute([$bid]);
    $bookRow = $bStmt->fetch();

    if ($bookRow) {
        // Get user_id from any existing payment for this booking
        $uStmt = $pdo->prepare("SELECT user_id FROM payments WHERE booking_id=? AND status='Paid' LIMIT 1");
        $uStmt->execute([$bid]);
        $userId = $uStmt->fetchColumn();

        // Get coach total
        $coachStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total_price),0) FROM coach_bookings WHERE court_booking_id=? AND status='Confirmed'"
        );
        $coachStmt->execute([$bid]);
        $coachTotal = floatval($coachStmt->fetchColumn());

        // Total already paid for this booking (all payment rows summed)
        $paidStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount + COALESCE(wallet_applied,0)),0) FROM payments WHERE booking_id=? AND status='Paid'"
        );
        $paidStmt->execute([$bid]);
        $alreadyPaid = floatval($paidStmt->fetchColumn());

        $totalAmount = floatval($bookRow['total_price']) + $coachTotal;
        $balanceDue  = max(0, $totalAmount - $alreadyPaid);

        // Insert cash payment for remaining balance
        if ($balanceDue > 0 && $userId) {
            $pdo->prepare("
                INSERT INTO payments (booking_id, user_id, order_id, amount, wallet_applied, payment_method, status, paid_at)
                VALUES (?, ?, ?, ?, 0, 'Cash', 'Paid', NOW())
            ")->execute([$bid, $userId, 'CASH-' . $bid . '-' . time(), $balanceDue]);
        }

        // Mark booking as Completed
        $pdo->prepare("UPDATE bookings SET status='Completed' WHERE id=?")->execute([$bid]);
    }
    header("Location: admin-payments.php"); exit();
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalRevenue   = $pdo->query("SELECT COALESCE(SUM(amount + COALESCE(wallet_applied,0)),0) FROM payments WHERE status='Paid'")->fetchColumn();
$advanceRevenue = $pdo->query("SELECT COALESCE(SUM(amount + COALESCE(wallet_applied,0)),0) FROM payments WHERE status='Paid' AND (payment_method IS NULL OR payment_method != 'Cash')")->fetchColumn();

// Pending revenue = balance still owed across active bookings with at least one payment
$pendingRevStmt = $pdo->query("
    SELECT
        b.id,
        (b.total_price + COALESCE((
            SELECT SUM(cb.total_price) FROM coach_bookings cb
            WHERE cb.court_booking_id = b.id AND cb.status='Confirmed'
        ),0)) AS total_amt,
        COALESCE((
            SELECT SUM(p2.amount + COALESCE(p2.wallet_applied,0))
            FROM payments p2
            WHERE p2.booking_id = b.id AND p2.status='Paid'
        ),0) AS paid_amt
    FROM bookings b
    WHERE b.status NOT IN ('Cancelled','Completed')
      AND EXISTS (SELECT 1 FROM payments px WHERE px.booking_id=b.id AND px.status='Paid')
");
$pendingRevenue = 0;
foreach ($pendingRevStmt->fetchAll() as $pr) {
    $pendingRevenue += max(0, floatval($pr['total_amt']) - floatval($pr['paid_amt']));
}

$countWallet = $pdo->query("SELECT COUNT(*) FROM users WHERE wallet_balance > 0")->fetchColumn();
$totalWallet = $pdo->query("SELECT COALESCE(SUM(wallet_balance),0) FROM users WHERE wallet_balance > 0")->fetchColumn();

// ── Payment rows — ONE row per booking, aggregated ───────────────────────────
$paymentsQuery = "
    SELECT
        b.id                AS booking_id,
        b.date              AS booking_date,
        b.total_price       AS booking_amount,
        b.status            AS booking_status,
        u.name              AS user_name,
        u.email             AS user_email,
        c.name              AS court_name,
        COALESCE((
            SELECT SUM(cb.total_price)
            FROM coach_bookings cb
            WHERE cb.court_booking_id = b.id AND cb.status = 'Confirmed'
        ), 0)               AS coach_amount,
        COALESCE((
            SELECT SUM(p2.amount + COALESCE(p2.wallet_applied,0))
            FROM payments p2
            WHERE p2.booking_id = b.id AND p2.status = 'Paid'
        ), 0)               AS total_paid,
        (SELECT MAX(p3.paid_at) FROM payments p3 WHERE p3.booking_id = b.id AND p3.status='Paid') AS last_paid_at,
        (SELECT MIN(p4.id)     FROM payments p4 WHERE p4.booking_id = b.id AND p4.status='Paid') AS first_payment_id,
        (SELECT COUNT(*)       FROM payments p5 WHERE p5.booking_id = b.id AND p5.status='Paid' AND p5.payment_method='Cash') AS has_cash_payment
    FROM bookings b
    JOIN users  u ON b.user_id  = u.id
    JOIN courts c ON b.court_id = c.id
    WHERE EXISTS (SELECT 1 FROM payments px WHERE px.booking_id = b.id AND px.status = 'Paid')
    ORDER BY last_paid_at DESC
";
$payments = $pdo->query($paymentsQuery)->fetchAll();

// Pending (wallet credit) transactions
$pendingTx = $pdo->query("
    SELECT pt.*, u.name AS user_name, u.email AS user_email,
           b.date AS orig_date, c.name AS court_name
    FROM pending_transactions pt
    JOIN users    u ON pt.user_id            = u.id
    JOIN bookings b ON pt.original_booking_id = b.id
    JOIN courts   c ON b.court_id             = c.id
    ORDER BY pt.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Payments – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    .overview-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
      margin-bottom: 28px;
    }
    .overview-card {
      background: #fff;
      border-radius: 14px;
      padding: 22px 24px;
      box-shadow: 0 2px 12px rgba(0,0,0,.07);
      border-left: 5px solid var(--primary);
      display: flex;
      align-items: center;
      gap: 16px;
      transition: transform .18s, box-shadow .18s;
    }
    .overview-card:hover { transform: translateY(-3px); box-shadow: 0 6px 22px rgba(0,0,0,.12); }
    .overview-card.s-green  { border-left-color: #2e7d32; }
    .overview-card.s-blue   { border-left-color: #1565c0; }
    .overview-card.s-orange { border-left-color: #e65100; }
    .overview-card.s-purple { border-left-color: #6a1b9a; }
    .overview-card.s-teal   { border-left-color: #00695c; }
    .ov-icon {
      width: 52px; height: 52px;
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 24px; flex-shrink: 0;
    }
    .s-green  .ov-icon { background: #e8f5e9; }
    .s-blue   .ov-icon { background: #e3f2fd; }
    .s-orange .ov-icon { background: #fff3e0; }
    .s-purple .ov-icon { background: #f3e5f5; }
    .s-teal   .ov-icon { background: #e0f2f1; }
    .ov-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); margin-bottom: 4px; }
    .ov-value { font-size: 22px; font-weight: 800; line-height: 1; }
    .s-green  .ov-value { color: #2e7d32; }
    .s-orange .ov-value { color: #e65100; }
    .s-purple .ov-value { color: #6a1b9a; }
    .s-teal   .ov-value { color: #00695c; }
    .s-blue   .ov-value { color: #1565c0; }

    .mark-paid-btn {
      display: inline-flex; align-items: center; gap: 5px;
      background: var(--primary); color: #fff;
      border: none; border-radius: var(--r-sm);
      padding: 7px 13px; font-size: 12px; font-weight: 600;
      cursor: pointer; transition: background .2s, transform .15s;
      white-space: nowrap;
    }
    .mark-paid-btn:hover { background: #1b5e20; transform: translateY(-1px); }
    .bal-due  { color: #e65100; font-weight: 700; }
    .bal-zero { color: var(--primary); font-weight: 600; }
    .adv-amount { color: var(--primary); font-weight: 700; }

    .badge-full-paid { background:#e8f5e9; color:#1b5e20; border:1px solid #a5d6a7; font-weight:700; padding:4px 10px; border-radius:20px; font-size:11px; display:inline-block; }
    .badge-adv-paid  { background:#fff8e1; color:#e65100; border:1px solid #ffe082; font-weight:700; padding:4px 10px; border-radius:20px; font-size:11px; display:inline-block; }
    .badge-full-comp { background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; font-weight:700; padding:4px 10px; border-radius:20px; font-size:11px; display:inline-block; }
  </style>
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
      <h1>💳 Payments</h1>
      <p>View all payment transactions and pending credits</p>
    </div>

    <div style="padding:28px 30px;">

      <!-- OVERVIEW STATS (3 per row) -->
      <div class="overview-grid">

        <div class="overview-card s-green">
          <div class="ov-icon">💰</div>
          <div>
            <div class="ov-label">Total Revenue</div>
            <div class="ov-value">LKR <?= number_format($totalRevenue) ?></div>
          </div>
        </div>

        <div class="overview-card s-blue">
          <div class="ov-icon">📥</div>
          <div>
            <div class="ov-label">Advance Payment Revenue</div>
            <div class="ov-value">LKR <?= number_format($advanceRevenue) ?></div>
          </div>
        </div>

        <div class="overview-card s-orange">
          <div class="ov-icon">⏳</div>
          <div>
            <div class="ov-label">Pending Payment Revenue</div>
            <div class="ov-value">LKR <?= number_format($pendingRevenue) ?></div>
          </div>
        </div>

        <div class="overview-card s-purple">
          <div class="ov-icon">👤</div>
          <div>
            <div class="ov-label">Wallet Balance Users</div>
            <div class="ov-value"><?= $countWallet ?></div>
          </div>
        </div>

        <div class="overview-card s-teal">
          <div class="ov-icon">💳</div>
          <div>
            <div class="ov-label">Total Wallet Balance</div>
            <div class="ov-value">LKR <?= number_format($totalWallet) ?></div>
          </div>
        </div>

        <div></div>

      </div>

      <!-- PAYMENT TRANSACTIONS TABLE -->
      <div class="card" style="margin-bottom:24px;">
        <div class="card-body">
          <div class="card-title" style="margin-bottom:16px;">💳 Payment Transactions</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Booking ID</th>
                  <th>Customer</th>
                  <th>Court Name</th>
                  <th>Paid Date</th>
                  <th>Total (LKR)</th>
                  <th>Advance Paid (LKR)</th>
                  <th>Balance Due (LKR)</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($payments)): ?>
                  <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:30px;">No payments found.</td></tr>
                <?php else: ?>
                  <?php foreach ($payments as $p):
                    $totalAmt   = floatval($p['booking_amount']) + floatval($p['coach_amount']);
                    $advancePd  = floatval($p['total_paid']);
                    $balanceDue = max(0, $totalAmt - $advancePd);
                    $isCompleted = $p['booking_status'] === 'Completed';
                    $hasCash     = intval($p['has_cash_payment']) > 0;

                    if ($isCompleted || $hasCash) {
                        $statusBadge = '<span class="badge-full-comp">💳 Full Payment Completed</span>';
                    } elseif ($balanceDue <= 0) {
                        $statusBadge = '<span class="badge-full-paid">✅ Fully Paid</span>';
                    } else {
                        $statusBadge = '<span class="badge-adv-paid">⏳ Advance Paid</span>';
                    }
                  ?>
                  <tr>
                    <td><strong>#<?= $p['booking_id'] ?></strong></td>
                    <td>
                      <?= htmlspecialchars($p['user_name']) ?>
                      <br/><small style="color:var(--text-muted);"><?= htmlspecialchars($p['user_email']) ?></small>
                    </td>
                    <td>
                      <?= htmlspecialchars($p['court_name']) ?>
                      <?php if ($p['coach_amount'] > 0): ?>
                        <br/><small style="color:var(--text-muted);">incl. coach</small>
                      <?php endif; ?>
                    </td>
                    <td><?= $p['last_paid_at'] ? date('d M Y H:i', strtotime($p['last_paid_at'])) : '–' ?></td>
                    <td style="font-weight:600;"><?= number_format($totalAmt) ?></td>
                    <td class="adv-amount"><?= number_format($advancePd) ?></td>
                    <td>
                      <?php if ($balanceDue <= 0 || $isCompleted || $hasCash): ?>
                        <span class="bal-zero">LKR 0</span>
                      <?php else: ?>
                        <span class="bal-due">LKR <?= number_format($balanceDue) ?></span>
                      <?php endif; ?>
                    </td>
                    <td><?= $statusBadge ?></td>
                    <td>
                      <?php if (!$isCompleted && !$hasCash && $balanceDue > 0 && $p['booking_status'] !== 'Cancelled'): ?>
                        <form method="POST"
                              onsubmit="return confirm('Mark booking #<?= $p['booking_id'] ?> as Full Payment Completed?\nRemaining LKR <?= number_format($balanceDue) ?> will be recorded as Cash payment.')">
                          <input type="hidden" name="action"     value="mark_full_paid"/>
                          <input type="hidden" name="booking_id" value="<?= $p['booking_id'] ?>"/>
                          <button class="mark-paid-btn">💰 Full Payment Completed</button>
                        </form>
                      <?php else: ?>
                        <span style="color:var(--text-muted);font-size:11px;">–</span>
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

      <!-- WALLET CREDIT LOG -->
      <div class="card">
        <div class="card-body">
          <div class="card-title">⏳ Wallet Credit Log (Cancelled Bookings)</div>
          <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
            Credits added to user wallets from cancelled paid bookings.
          </p>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th><th>Customer</th><th>Original Booking</th><th>Court</th>
                  <th>Amount (LKR)</th><th>Reason</th><th>Status</th><th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($pendingTx)): ?>
                  <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:30px;">No wallet credits.</td></tr>
                <?php else: ?>
                  <?php foreach ($pendingTx as $pt): ?>
                  <tr>
                    <td><strong>#<?= $pt['id'] ?></strong></td>
                    <td>
                      <?= htmlspecialchars($pt['user_name']) ?>
                      <br/><small style="color:var(--text-muted);"><?= htmlspecialchars($pt['user_email']) ?></small>
                    </td>
                    <td><strong>#<?= $pt['original_booking_id'] ?></strong></td>
                    <td><?= htmlspecialchars($pt['court_name']) ?></td>
                    <td style="font-weight:700;color:var(--warning);"><?= number_format($pt['amount']) ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($pt['reason']) ?></td>
                    <td>
                      <span class="badge <?= $pt['status']==='Available'?'badge-pending':'badge-confirmed' ?>">
                        <?= $pt['status'] ?>
                      </span>
                    </td>
                    <td><?= date('d M Y', strtotime($pt['created_at'])) ?></td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
</body>
</html>
