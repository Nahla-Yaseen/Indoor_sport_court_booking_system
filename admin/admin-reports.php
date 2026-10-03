<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

// Date range filter
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate   = $_GET['to']   ?? date('Y-m-d');
$repType  = $_GET['type'] ?? 'overview';

// ---- ALWAYS DEFINE THESE FIRST (fixes undefined variable warning) ----
$totalPaid  = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Paid'")->fetchColumn();
$countPaid  = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='Paid'")->fetchColumn();

// ---- OVERVIEW STATS ----
$totalBookings  = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$confirmedB     = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Confirmed'")->fetchColumn();
$completedB     = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Completed'")->fetchColumn();
$cancelledB     = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Cancelled'")->fetchColumn();
$totalRevenue   = $totalPaid;
$totalCoachSess = $pdo->query("SELECT COUNT(*) FROM coach_bookings")->fetchColumn();
$totalUsers     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();

// ---- BOOKINGS IN DATE RANGE ----
$rangeStmt = $pdo->prepare("
    SELECT b.*, u.name AS user_name, c.name AS court_name,
           p.amount AS paid_amount, p.transaction_ref, p.card_type
    FROM bookings b
    JOIN users u  ON b.user_id  = u.id
    JOIN courts c ON b.court_id = c.id
    LEFT JOIN payments p ON b.id = p.booking_id AND p.status='Paid'
    WHERE b.date BETWEEN ? AND ?
    ORDER BY b.date DESC, b.booked_at DESC
");
$rangeStmt->execute([$fromDate, $toDate]);
$rangeBookings = $rangeStmt->fetchAll();

// ---- REVENUE BY COURT ----
$revByCourt = $pdo->query("
    SELECT c.name AS court_name,
           COUNT(b.id) AS total_bookings,
           COALESCE(SUM(p.amount),0) AS revenue
    FROM courts c
    LEFT JOIN bookings b ON c.id = b.court_id
              AND b.status IN ('Confirmed','Completed')
    LEFT JOIN payments p ON b.id = p.booking_id AND p.status='Paid'
    GROUP BY c.id, c.name
    ORDER BY revenue DESC
")->fetchAll();

// ---- REVENUE BY MONTH ----
$revByMonth = $pdo->query("
    SELECT DATE_FORMAT(paid_at,'%Y-%m') AS month,
           COUNT(*) AS transactions,
           SUM(amount) AS revenue
    FROM payments
    WHERE status='Paid'
    GROUP BY DATE_FORMAT(paid_at,'%Y-%m')
    ORDER BY month DESC
    LIMIT 12
")->fetchAll();

// ---- TOP USERS ----
$topUsers = $pdo->query("
    SELECT u.name, u.email,
           COUNT(b.id) AS total_bookings,
           COALESCE(SUM(p.amount),0) AS total_spent
    FROM users u
    LEFT JOIN bookings b ON u.id = b.user_id
              AND b.status IN ('Confirmed','Completed')
    LEFT JOIN payments p ON b.id = p.booking_id AND p.status='Paid'
    WHERE u.role='user'
    GROUP BY u.id
    ORDER BY total_bookings DESC
    LIMIT 10
")->fetchAll();

// ---- COACH SESSIONS IN RANGE ----
$coachRange = $pdo->prepare("
    SELECT cb.*, u.name AS user_name, co.name AS coach_name, co.sport
    FROM coach_bookings cb
    JOIN users   u  ON cb.user_id  = u.id
    JOIN coaches co ON cb.coach_id = co.id
    WHERE cb.date BETWEEN ? AND ?
    ORDER BY cb.date DESC
");
$coachRange->execute([$fromDate, $toDate]);
$coachBookings = $coachRange->fetchAll();

// ---- BOOKING TYPE BREAKDOWN ----
$typeBreak = $pdo->query("
    SELECT booking_type, COUNT(*) AS count
    FROM bookings
    GROUP BY booking_type
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reports – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    .rep-tabs { display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap; }
    .rep-tab  {
      padding:8px 20px; border-radius:20px;
      border:1.5px solid var(--gray-200);
      background:white; font-size:13px; font-weight:500;
      cursor:pointer; text-decoration:none;
      color:var(--text-muted); transition:all 0.2s;
    }
    .rep-tab:hover, .rep-tab.active {
      background:var(--primary-mid); color:white;
      border-color:var(--primary-mid);
    }

    .big-stat {
      background:white; border-radius:var(--r-md);
      box-shadow:var(--shadow-sm); padding:20px 22px;
      border-left:4px solid var(--primary-light);
      text-align:center;
    }
    .big-stat .bs-icon  { font-size:28px; margin-bottom:8px; }
    .big-stat .bs-label {
      font-size:11px; color:var(--text-muted);
      font-weight:600; text-transform:uppercase; letter-spacing:0.5px;
    }
    .big-stat .bs-val   {
      font-size:26px; font-weight:700;
      color:var(--primary); margin-top:4px;
    }

    .date-filter {
      display:flex; gap:12px; align-items:flex-end;
      background:white; border-radius:var(--r-md);
      padding:16px 20px; margin-bottom:24px;
      box-shadow:var(--shadow-sm); flex-wrap:wrap;
    }
    .date-filter .form-group { margin-bottom:0; }

    .print-btn {
      background:var(--primary-soft); color:var(--primary);
      border:1.5px solid var(--primary-light);
      padding:8px 18px; border-radius:var(--r-sm);
      font-size:13px; font-weight:600; cursor:pointer;
      font-family:'Poppins',sans-serif; transition:all 0.2s;
    }
    .print-btn:hover { background:var(--primary-mid); color:white; }

    .chart-row  { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .chart-label {
      width:140px; font-size:12px;
      color:var(--text-muted); text-align:right; flex-shrink:0;
    }
    .chart-bar-bg {
      flex:1; background:var(--gray-100);
      border-radius:4px; height:24px; overflow:hidden;
    }
    .chart-bar-fill {
      height:100%;
      background:linear-gradient(90deg,var(--primary-mid),var(--primary-light));
      border-radius:4px; transition:width 0.5s;
      display:flex; align-items:center; padding-left:8px;
    }
    .chart-bar-fill span {
      font-size:11px; color:white; font-weight:600; white-space:nowrap;
    }

    @media print {
      .no-print    { display:none!important; }
      .admin-wrapper { display:block!important; }
      .sidebar     { display:none!important; }
      .navbar      { display:none!important; }
      .admin-main  { margin:0!important; }
    }
  </style>
</head>
<body>
<nav class="navbar no-print">
  <div class="logo">🏸 Adam <span>Indoors</span></div>
  <div class="nav-links">
    <span style="color:rgba(255,255,255,0.8);font-size:13px;">Admin Panel</span>
    <a href="../logout.php" class="btn-nav">Logout</a>
  </div>
</nav>
<div class="admin-wrapper">
  <div class="no-print"><?php include __DIR__ . '/sidebar.php'; ?></div>

  <div class="admin-main">
    <div class="page-header">
      <div style="display:flex;justify-content:space-between;
                  align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
          <h1>📊 Reports</h1>
          <p>Booking overview and analytical reports</p>
        </div>
        <button class="print-btn no-print" onclick="window.print()">
          🖨️ Print / Export PDF
        </button>
      </div>
    </div>

    <div style="padding:28px 30px;">

      <!-- REPORT TYPE TABS -->
      <div class="rep-tabs no-print">
        <?php
        $tabs = [
            'overview' => '📊 Overview',
            'bookings' => '📋 Bookings',
            'revenue'  => '💰 Revenue',
            'coaches'  => '🎽 Coach Sessions',
            'users'    => '👥 Users',
        ];
        foreach ($tabs as $k => $v): ?>
          <a href="?type=<?= $k ?>&from=<?= $fromDate ?>&to=<?= $toDate ?>"
             class="rep-tab <?= $repType===$k?'active':'' ?>">
            <?= $v ?>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- DATE FILTER -->
      <form method="GET" class="date-filter no-print">
        <input type="hidden" name="type" value="<?= htmlspecialchars($repType) ?>"/>
        <div class="form-group">
          <label style="font-size:12px;font-weight:600;">From Date</label>
          <input type="date" name="from" value="<?= $fromDate ?>"/>
        </div>
        <div class="form-group">
          <label style="font-size:12px;font-weight:600;">To Date</label>
          <input type="date" name="to" value="<?= $toDate ?>"/>
        </div>
        <button type="submit" class="btn btn-primary" style="height:42px;">
          🔍 Filter
        </button>
        <a href="?type=<?= $repType ?>&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>"
           class="btn btn-outline" style="height:42px;line-height:20px;">
          This Month
        </a>
        <a href="?type=<?= $repType ?>&from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>"
           class="btn btn-outline" style="height:42px;line-height:20px;">
          This Year
        </a>
      </form>

      <?php if ($repType === 'overview'): ?>
      <!-- ===== OVERVIEW ===== -->
      <div style="text-align:center;font-size:18px;font-weight:700;
                  color:var(--primary);margin-bottom:20px;">
        System Overview — Adam Indoors
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
                  gap:16px;margin-bottom:28px;">
        <div class="big-stat">
          <div class="bs-icon">📋</div>
          <div class="bs-label">Total Bookings</div>
          <div class="bs-val"><?= $totalBookings ?></div>
        </div>
        <div class="big-stat">
          <div class="bs-icon">✅</div>
          <div class="bs-label">Confirmed</div>
          <div class="bs-val"><?= $confirmedB ?></div>
        </div>
        <div class="big-stat">
          <div class="bs-icon">🏁</div>
          <div class="bs-label">Completed</div>
          <div class="bs-val"><?= $completedB ?></div>
        </div>
        <div class="big-stat">
          <div class="bs-icon">❌</div>
          <div class="bs-label">Cancelled</div>
          <div class="bs-val"><?= $cancelledB ?></div>
        </div>
        <div class="big-stat" style="border-left-color:#7b1fa2;">
          <div class="bs-icon">🎽</div>
          <div class="bs-label">Coach Sessions</div>
          <div class="bs-val" style="color:#7b1fa2;"><?= $totalCoachSess ?></div>
        </div>
        <div class="big-stat" style="border-left-color:#1565c0;">
          <div class="bs-icon">👥</div>
          <div class="bs-label">Registered Users</div>
          <div class="bs-val" style="color:#1565c0;"><?= $totalUsers ?></div>
        </div>
        <div class="big-stat" style="border-left-color:#e65100;">
          <div class="bs-icon">💰</div>
          <div class="bs-label">Total Revenue</div>
          <div class="bs-val" style="color:#e65100;font-size:18px;">
            LKR <?= number_format($totalRevenue) ?>
          </div>
        </div>
      </div>

      <!-- BOOKING TYPE BREAKDOWN -->
      <div class="card" style="margin-bottom:24px;">
        <div class="card-body">
          <div class="card-title">📊 Booking Type Breakdown</div>
          <?php
          $totalType = array_sum(array_column($typeBreak,'count'));
          foreach ($typeBreak as $t):
            $pct = $totalType > 0 ? round($t['count']/$totalType*100) : 0;
          ?>
          <div class="chart-row">
            <div class="chart-label"><?= htmlspecialchars($t['booking_type']) ?></div>
            <div class="chart-bar-bg">
              <div class="chart-bar-fill" style="width:<?= max($pct,5) ?>%">
                <span><?= $t['count'] ?> (<?= $pct ?>%)</span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- REVENUE BY COURT -->
      <div class="card" style="margin-bottom:24px;">
        <div class="card-body">
          <div class="card-title">🏸 Revenue by Court</div>
          <?php
          $maxRev = max(array_column($revByCourt,'revenue') ?: [1]);
          foreach ($revByCourt as $rc):
            $pct = $maxRev > 0 ? round($rc['revenue']/$maxRev*100) : 0;
          ?>
          <div class="chart-row">
            <div class="chart-label"><?= htmlspecialchars($rc['court_name']) ?></div>
            <div class="chart-bar-bg">
              <div class="chart-bar-fill"
                   style="width:<?= max($pct,5) ?>%;
                          background:linear-gradient(90deg,#1565c0,#1976d2);">
                <span>
                  LKR <?= number_format($rc['revenue']) ?>
                  (<?= $rc['total_bookings'] ?> bookings)
                </span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- MONTHLY REVENUE -->
      <div class="card">
        <div class="card-body">
          <div class="card-title">📅 Monthly Revenue</div>
          <?php
          $maxMonthRev = max(array_column($revByMonth,'revenue') ?: [1]);
          foreach ($revByMonth as $rm):
            $pct = $maxMonthRev > 0 ? round($rm['revenue']/$maxMonthRev*100) : 0;
          ?>
          <div class="chart-row">
            <div class="chart-label">
              <?= date('M Y', strtotime($rm['month'].'-01')) ?>
            </div>
            <div class="chart-bar-bg">
              <div class="chart-bar-fill"
                   style="width:<?= max($pct,5) ?>%;
                          background:linear-gradient(90deg,#e65100,#f57c00);">
                <span>LKR <?= number_format($rm['revenue']) ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php elseif ($repType === 'bookings'): ?>
      <!-- ===== BOOKINGS REPORT ===== -->
      <div style="text-align:center;font-size:16px;font-weight:700;
                  color:var(--primary);margin-bottom:16px;">
        Court Bookings:
        <?= date('d M Y', strtotime($fromDate)) ?> –
        <?= date('d M Y', strtotime($toDate)) ?>
      </div>

      <div class="card">
        <div class="card-body">
          <div style="display:flex;justify-content:space-between;
                      align-items:center;margin-bottom:14px;">
            <div class="card-title" style="margin-bottom:0;">
              Total: <?= count($rangeBookings) ?> bookings
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th><th>Customer</th><th>Court</th><th>Type</th>
                  <th>Date</th><th>Slots</th><th>Amount</th>
                  <th>Paid</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rangeBookings)): ?>
                  <tr>
                    <td colspan="9" style="text-align:center;
                        color:var(--text-muted);padding:30px;">
                      No bookings in this period.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rangeBookings as $b): ?>
                  <tr>
                    <td><strong>#<?= $b['id'] ?></strong></td>
                    <td><?= htmlspecialchars($b['user_name']) ?></td>
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
                      <?php
                      $s = json_decode($b['selected_slots'] ?? '[]', true);
                      echo count($s).' slot'.(count($s)>1?'s':'');
                      ?>
                    </td>
                    <td>LKR <?= number_format($b['total_price']) ?></td>
                    <td>
                      <?= $b['paid_amount']
                          ? '<span style="color:var(--primary);font-weight:600;">LKR '.number_format($b['paid_amount']).'</span>'
                          : '<span style="color:var(--text-muted);">–</span>' ?>
                    </td>
                    <td>
                      <span class="badge badge-<?= strtolower($b['status']) ?>">
                        <?= $b['status'] ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if (!empty($rangeBookings)): ?>
          <div style="margin-top:14px;padding-top:14px;
                      border-top:2px solid var(--primary-light);
                      display:flex;justify-content:space-between;
                      font-size:14px;font-weight:700;color:var(--primary);">
            <span>Total Revenue (Paid)</span>
            <span>
              LKR <?= number_format(array_sum(array_column($rangeBookings,'paid_amount'))) ?>
            </span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php elseif ($repType === 'revenue'): ?>
      <!-- ===== REVENUE REPORT ===== -->
      <div style="text-align:center;font-size:16px;font-weight:700;
                  color:var(--primary);margin-bottom:16px;">
        Revenue Report:
        <?= date('d M Y', strtotime($fromDate)) ?> –
        <?= date('d M Y', strtotime($toDate)) ?>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
                  gap:16px;margin-bottom:24px;">
        <div class="big-stat" style="border-left-color:#e65100;">
          <div class="bs-icon">💰</div>
          <div class="bs-label">Total Revenue</div>
          <div class="bs-val" style="color:#e65100;font-size:20px;">
            LKR <?= number_format($totalRevenue) ?>
          </div>
        </div>
        <div class="big-stat">
          <div class="bs-icon">✅</div>
          <div class="bs-label">Paid Transactions</div>
          <div class="bs-val"><?= $countPaid ?></div>
        </div>
      </div>

      <!-- REVENUE BY COURT TABLE -->
      <div class="card" style="margin-bottom:24px;">
        <div class="card-body">
          <div class="card-title">Revenue by Court</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Court</th><th>Bookings</th><th>Revenue (LKR)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($revByCourt as $rc): ?>
                <tr>
                  <td><?= htmlspecialchars($rc['court_name']) ?></td>
                  <td><?= $rc['total_bookings'] ?></td>
                  <td style="font-weight:700;color:var(--primary);">
                    <?= number_format($rc['revenue']) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- MONTHLY REVENUE TABLE -->
      <div class="card">
        <div class="card-body">
          <div class="card-title">Monthly Revenue Breakdown</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Month</th><th>Transactions</th><th>Revenue (LKR)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($revByMonth as $rm): ?>
                <tr>
                  <td><?= date('F Y', strtotime($rm['month'].'-01')) ?></td>
                  <td><?= $rm['transactions'] ?></td>
                  <td style="font-weight:700;color:var(--primary);">
                    <?= number_format($rm['revenue']) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--primary-soft);">
                  <td><strong>Total</strong></td>
                  <td>
                    <strong>
                      <?= array_sum(array_column($revByMonth,'transactions')) ?>
                    </strong>
                  </td>
                  <td>
                    <strong style="color:var(--primary);">
                      LKR <?= number_format($totalRevenue) ?>
                    </strong>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php elseif ($repType === 'coaches'): ?>
      <!-- ===== COACH SESSIONS REPORT ===== -->
      <div style="text-align:center;font-size:16px;font-weight:700;
                  color:var(--primary);margin-bottom:16px;">
        Coach Sessions:
        <?= date('d M Y', strtotime($fromDate)) ?> –
        <?= date('d M Y', strtotime($toDate)) ?>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="card-title">
            Total: <?= count($coachBookings) ?> sessions
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th><th>Customer</th><th>Coach</th><th>Sport</th>
                  <th>Date</th><th>Time</th><th>Duration</th>
                  <th>Price (LKR)</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($coachBookings)): ?>
                  <tr>
                    <td colspan="9" style="text-align:center;
                        color:var(--text-muted);padding:30px;">
                      No sessions in this period.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($coachBookings as $cb): ?>
                  <tr>
                    <td><strong>#<?= $cb['id'] ?></strong></td>
                    <td><?= htmlspecialchars($cb['user_name']) ?></td>
                    <td>
                      <?= htmlspecialchars($cb['coach_name']) ?>
                      <br/>
                      <small style="font-family:monospace;color:var(--text-muted);">
                        <?= htmlspecialchars($cb['coach_id']) ?>
                      </small>
                    </td>
                    <td><?= htmlspecialchars($cb['sport']) ?></td>
                    <td><?= $cb['date'] ?></td>
                    <td>
                      <?= substr($cb['start_time'],0,5) ?> –
                      <?= substr($cb['end_time'],0,5) ?>
                    </td>
                    <td>
                      <?= $cb['duration_hours'] ?>
                      hr<?= $cb['duration_hours']>1?'s':'' ?>
                    </td>
                    <td><?= number_format($cb['total_price']) ?></td>
                    <td>
                      <span class="badge badge-<?= strtolower($cb['status']) ?>">
                        <?= $cb['status'] ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if (!empty($coachBookings)): ?>
          <div style="margin-top:14px;padding-top:14px;
                      border-top:2px solid var(--primary-light);
                      display:flex;justify-content:space-between;
                      font-size:14px;font-weight:700;color:var(--primary);">
            <span>Total Value</span>
            <span>
              LKR <?= number_format(array_sum(array_column($coachBookings,'total_price'))) ?>
            </span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php elseif ($repType === 'users'): ?>
      <!-- ===== USERS REPORT ===== -->
      <div style="text-align:center;font-size:16px;font-weight:700;
                  color:var(--primary);margin-bottom:16px;">
        User Activity Report
      </div>

      <div class="card">
        <div class="card-body">
          <div class="card-title">Top Users by Bookings</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th><th>Name</th><th>Email</th>
                  <th>Bookings</th><th>Total Spent (LKR)</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($topUsers)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center;
                        color:var(--text-muted);padding:30px;">
                      No user data.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($topUsers as $i => $u): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                      <span class="badge badge-confirmed">
                        <?= $u['total_bookings'] ?>
                      </span>
                    </td>
                    <td style="font-weight:700;color:var(--primary);">
                      <?= number_format($u['total_spent']) ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>