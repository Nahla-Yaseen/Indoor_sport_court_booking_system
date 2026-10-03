<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['new_status'])) {
    $allowed   = ['Confirmed','Completed','Cancelled'];
    $newStatus = $_POST['new_status'];
    $bid       = intval($_POST['booking_id']);
    if (in_array($newStatus, $allowed)) {
        $pdo->prepare("UPDATE coach_bookings SET status=? WHERE id=?")
            ->execute([$newStatus, $bid]);
    }
    header("Location: admin-coach-bookings.php"); exit();
}

$filter  = $_GET['filter'] ?? 'All';
$allowed = ['All','Confirmed','Completed','Cancelled'];
if (!in_array($filter, $allowed)) $filter = 'All';

if ($filter === 'All') {
    $bookings = $pdo->query("
        SELECT cb.*, u.name AS user_name, u.email AS user_email,
               co.name AS coach_name, co.sport
        FROM coach_bookings cb
        JOIN users   u  ON cb.user_id  = u.id
        JOIN coaches co ON cb.coach_id = co.id
        ORDER BY cb.booked_at DESC
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT cb.*, u.name AS user_name, u.email AS user_email,
               co.name AS coach_name, co.sport
        FROM coach_bookings cb
        JOIN users   u  ON cb.user_id  = u.id
        JOIN coaches co ON cb.coach_id = co.id
        WHERE cb.status=?
        ORDER BY cb.booked_at DESC
    ");
    $stmt->execute([$filter]);
    $bookings = $stmt->fetchAll();
}

$total     = $pdo->query("SELECT COUNT(*) FROM coach_bookings")->fetchColumn();
$confirmed = $pdo->query("SELECT COUNT(*) FROM coach_bookings WHERE status='Confirmed'")->fetchColumn();
$completed = $pdo->query("SELECT COUNT(*) FROM coach_bookings WHERE status='Completed'")->fetchColumn();
$cancelled = $pdo->query("SELECT COUNT(*) FROM coach_bookings WHERE status='Cancelled'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Coach Bookings – Adam Indoors</title>
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
  <?php include 'sidebar.php'; ?>

  <div class="admin-main">
    <div class="page-header">
      <h1>📝 Coach Bookings</h1>
    </div>
    <div style="padding:28px 30px;">

      

      <!-- STATS -->
      <div class="stats-grid" style="margin-bottom:24px;">
        <div class="stat-card">
          <div class="stat-icon">📝</div>
          <div class="stat-label">Total Sessions</div>
          <div class="stat-value"><?= $total ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">✅</div>
          <div class="stat-label">Confirmed</div>
          <div class="stat-value"><?= $confirmed ?></div>
        </div>
        <div class="stat-card s-blue">
          <div class="stat-icon">🏁</div>
          <div class="stat-label">Completed</div>
          <div class="stat-value"><?= $completed ?></div>
        </div>
        <div class="stat-card s-red">
          <div class="stat-icon">❌</div>
          <div class="stat-label">Cancelled</div>
          <div class="stat-value"><?= $cancelled ?></div>
        </div>
      </div>

      <!-- FILTER TABS — no Pending -->
      <div class="filter-tabs">
        <?php foreach (['All','Confirmed','Completed','Cancelled'] as $tab): ?>
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
              <th>ID</th><th>Customer</th><th>Coach</th><th>Sport</th>
              <th>Date</th><th>Time</th><th>Duration</th>
              <th>Price (LKR)</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($bookings)): ?>
              <tr>
                <td colspan="10" style="text-align:center;color:var(--text-muted);padding:40px;">
                  No coach bookings found.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($bookings as $b): ?>
              <tr>
                <td><strong>#<?= $b['id'] ?></strong></td>
                <td>
                  <?= htmlspecialchars($b['user_name']) ?>
                  <br/><small style="color:var(--text-muted);">
                    <?= htmlspecialchars($b['user_email']) ?>
                  </small>
                </td>
                <td>
                  <?= htmlspecialchars($b['coach_name']) ?>
                  <br/><small style="color:var(--text-muted);font-family:monospace;">
                    <?= htmlspecialchars($b['coach_id']) ?>
                  </small>
                </td>
                <td><?= htmlspecialchars($b['sport']) ?></td>
                <td><?= $b['date'] ?></td>
                <td><?= substr($b['start_time'],0,5) ?> – <?= substr($b['end_time'],0,5) ?></td>
                <td><?= $b['duration_hours'] ?> hr<?= $b['duration_hours']>1?'s':'' ?></td>
                <td><?= number_format($b['total_price']) ?></td>
                <td>
                  <span class="badge badge-<?= strtolower($b['status']) ?>">
                    <?= $b['status'] ?>
                  </span>
                </td>
                <td class="table-actions">
                  <?php if ($b['status'] === 'Confirmed'): ?>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Cancel coach booking #<?= $b['id'] ?>?')">
                      <input type="hidden" name="booking_id" value="<?= $b['id'] ?>"/>
                      <input type="hidden" name="new_status"  value="Cancelled"/>
                      <button class="btn btn-danger btn-sm">❌ Cancel</button>
                    </form>
                  <?php else: ?>
                    <span style="color:var(--text-muted);font-size:12px;">No actions</span>
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