<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

// Court booking stats
$total     = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$confirmed = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Confirmed'")->fetchColumn();

$cancelled = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Cancelled'")->fetchColumn();

// Coach session stats
try {
    $coach_total     = $pdo->query("SELECT COUNT(*) FROM coach_bookings")->fetchColumn();
    $coach_confirmed = $pdo->query("SELECT COUNT(*) FROM coach_bookings WHERE status='Confirmed'")->fetchColumn();
} catch (Exception $e) {
    $coach_total = $coach_confirmed = 0;
}

// Recent court bookings
$recent = $pdo->query("
    SELECT b.*, u.name AS user_name, c.name AS court_name
    FROM bookings b
    JOIN users  u ON b.user_id  = u.id
    JOIN courts c ON b.court_id = c.id
    ORDER BY b.booked_at DESC LIMIT 8
")->fetchAll();

// Recent coach bookings
try {
    $recentCoach = $pdo->query("
        SELECT cb.*, u.name AS user_name, co.name AS coach_name, co.sport
        FROM coach_bookings cb
        JOIN users   u  ON cb.user_id  = u.id
        JOIN coaches co ON cb.coach_id = co.id
        ORDER BY cb.booked_at DESC LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {
    $recentCoach = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard – Adam Indoors</title>
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
      <h1>Dashboard Overview</h1>
      <p>Welcome back, <?= htmlspecialchars($_SESSION['name']) ?> 👋</p>
    </div>
    <div style="padding:28px 30px;">

      <!-- COURT BOOKING STATS -->
      <div style="font-size:12px;font-weight:700;color:var(--text-muted);
                  text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;">
        Court Bookings
      </div>
      <div class="stats-grid" style="margin-bottom:28px;">
        <div class="stat-card">
          <div class="stat-icon">📋</div>
          <div class="stat-label">Total Bookings</div>
          <div class="stat-value"><?= $total ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">✅</div>
          <div class="stat-label">Confirmed</div>
          <div class="stat-value"><?= $confirmed ?></div>
        </div>

        <div class="stat-card s-red">
          <div class="stat-icon">❌</div>
          <div class="stat-label">Cancelled</div>
          <div class="stat-value"><?= $cancelled ?></div>
        </div>
      </div>

      <!-- COACH SESSION STATS -->
      <div style="font-size:12px;font-weight:700;color:var(--text-muted);
                  text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;">
        Coach Sessions
      </div>
      <div class="stats-grid" style="margin-bottom:32px;">
        <div class="stat-card" style="border-left-color:#7b1fa2;">
          <div class="stat-icon">🎽</div>
          <div class="stat-label">Total Sessions</div>
          <div class="stat-value" style="color:#7b1fa2;"><?= $coach_total ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">✅</div>
          <div class="stat-label">Confirmed Sessions</div>
          <div class="stat-value"><?= $coach_confirmed ?></div>
        </div>
      </div>

      <!-- RECENT COURT BOOKINGS — view only, no actions -->
      <div class="card" style="margin-bottom:24px;">
        <div class="card-body">
          <div style="display:flex;justify-content:space-between;
                      align-items:center;margin-bottom:16px;">
            <div class="card-title" style="margin-bottom:0;">
              🕒 Recent Court Bookings
            </div>
            <a href="admin-bookings.php" class="btn btn-outline btn-sm">
              View All & Manage →
            </a>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th><th>User</th><th>Court</th>
                  <th>Date</th><th>Time</th><th>Price</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recent)): ?>
                  <tr>
                    <td colspan="7" style="text-align:center;
                        color:var(--text-muted);padding:24px;">
                      No bookings yet.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($recent as $b): ?>
                  <tr>
                    <td><strong>#<?= $b['id'] ?></strong></td>
                    <td><?= htmlspecialchars($b['user_name']) ?></td>
                    <td><?= htmlspecialchars($b['court_name']) ?></td>
                    <td><?= $b['date'] ?></td>
                    <td>
                      <?= substr($b['start_time'],0,5) ?> –
                      <?= substr($b['end_time'],0,5) ?>
                    </td>
                    <td>LKR <?= number_format($b['total_price']) ?></td>
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
          <div style="margin-top:14px;text-align:right;">
            <a href="admin-bookings.php" style="font-size:13px;color:var(--primary);font-weight:600;">
              Go to Manage Bookings to start / complete / cancel →
            </a>
          </div>
        </div>
      </div>

      <!-- RECENT COACH SESSIONS — view only, no actions -->
      <?php if (!empty($recentCoach)): ?>
      <div class="card">
        <div class="card-body">
          <div style="display:flex;justify-content:space-between;
                      align-items:center;margin-bottom:16px;">
            <div class="card-title" style="margin-bottom:0;">
              🎽 Recent Coach Sessions
            </div>
            <a href="admin-coach-bookings.php" class="btn btn-outline btn-sm">
              View All & Manage →
            </a>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th><th>User</th><th>Coach</th><th>Sport</th>
                  <th>Date</th><th>Time</th><th>Price</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentCoach as $cb): ?>
                <tr>
                  <td><strong>#<?= $cb['id'] ?></strong></td>
                  <td><?= htmlspecialchars($cb['user_name']) ?></td>
                  <td>
                    <?= htmlspecialchars($cb['coach_name']) ?>
                    <br/>
                    <small style="color:var(--text-muted);font-family:monospace;">
                      <?= htmlspecialchars($cb['coach_id']) ?>
                    </small>
                  </td>
                  <td><?= htmlspecialchars($cb['sport']) ?></td>
                  <td><?= $cb['date'] ?></td>
                  <td>
                    <?= substr($cb['start_time'],0,5) ?> –
                    <?= substr($cb['end_time'],0,5) ?>
                  </td>
                  <td>LKR <?= number_format($cb['total_price']) ?></td>
                  <td>
                    <span class="badge badge-<?= strtolower($cb['status']) ?>">
                      <?= $cb['status'] ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div style="margin-top:14px;text-align:right;">
            <a href="admin-coach-bookings.php"
               style="font-size:13px;color:var(--primary);font-weight:600;">
              Go to Coach Bookings to complete / cancel →
            </a>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>