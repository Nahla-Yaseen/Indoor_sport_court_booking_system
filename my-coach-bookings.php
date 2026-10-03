<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /myproject/login.php"); exit();
}
require_once __DIR__ . '/includes/db.php';

// Cancel booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    $pdo->prepare("
        UPDATE coach_bookings SET status='Cancelled'
        WHERE id=? AND user_id=? AND status IN ('Pending','Confirmed')
    ")->execute([intval($_POST['cancel_id']), $_SESSION['user_id']]);
    header("Location: /myproject/my-coach-bookings.php"); exit();
}

$filter  = $_GET['filter'] ?? 'All';
$allowed = ['All','Pending','Confirmed','Completed','Cancelled'];
if (!in_array($filter, $allowed)) $filter = 'All';

if ($filter === 'All') {
    $stmt = $pdo->prepare("
        SELECT cb.*, c.name AS coach_name, c.sport AS coach_sport
        FROM coach_bookings cb
        JOIN coaches c ON cb.coach_id = c.id
        WHERE cb.user_id = ?
        ORDER BY cb.booked_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("
        SELECT cb.*, c.name AS coach_name, c.sport AS coach_sport
        FROM coach_bookings cb
        JOIN coaches c ON cb.coach_id = c.id
        WHERE cb.user_id = ? AND cb.status = ?
        ORDER BY cb.booked_at DESC
    ");
    $stmt->execute([$_SESSION['user_id'], $filter]);
}
$bookings = $stmt->fetchAll();
$initial  = strtoupper(substr($_SESSION['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Coach Sessions – Adam Indoors</title>
  <link rel="stylesheet" href="/myproject/css/style.css"/>
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
      <div>
        <strong><?= htmlspecialchars($_SESSION['name']) ?></strong>
        <small>Member</small>
      </div>
    </div>
    <div class="usb-section">Menu</div>
    <a href="/myproject/dashboard.php"         class="usb-link"><span class="usb-icon-sm">📊</span> Dashboard</a>
    <a href="/myproject/about.php"             class="usb-link"><span class="usb-icon-sm">ℹ️</span> About Us</a>
    <a href="/myproject/coaches.php"           class="usb-link"><span class="usb-icon-sm">🏅</span> Coaches</a>
    <a href="/myproject/my-bookings.php"       class="usb-link"><span class="usb-icon-sm">📋</span> My Bookings</a>
    <a href="/myproject/my-coach-bookings.php" class="usb-link active"><span class="usb-icon-sm">🎯</span> Coach Sessions</a>
    <div class="usb-section">Account</div>
    <a href="/myproject/logout.php" class="usb-link logout-link"><span class="usb-icon-sm">🚪</span> Logout</a>
    <div class="usb-bottom">© 2025 Adam Indoors</div>
  </div>

  <div class="user-main">
    <div class="user-topbar">
      <div class="utb-title">My Coach Sessions</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">
      <div class="page-header" style="border-radius:var(--r-md); margin-bottom:24px;">
        <h1>🎯 My Coach Sessions</h1>
        <p>View and manage your personal coaching session bookings</p>
      </div>

      <div class="filter-tabs">
        <?php foreach (['All','Pending','Confirmed','Completed','Cancelled'] as $tab): ?>
          <a href="?filter=<?= $tab ?>" class="filter-tab"
             style="<?= $filter===$tab ? 'background:var(--primary-mid);color:white;border-color:var(--primary-mid);' : '' ?>">
            <?= $tab ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Coach</th>
              <th>Sport</th>
              <th>Date</th>
              <th>Start</th>
              <th>End</th>
              <th>Price (LKR)</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($bookings)): ?>
              <tr>
                <td colspan="9" style="text-align:center;color:var(--text-muted);padding:40px;">
                  No coach sessions found.
                  <a href="/myproject/coaches.php" style="color:var(--primary);font-weight:600;">Browse coaches →</a>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($bookings as $b): ?>
              <tr>
                <td><strong>#<?= $b['id'] ?></strong></td>
                <td><?= htmlspecialchars($b['coach_name']) ?></td>
                <td><?= htmlspecialchars($b['coach_sport']) ?></td>
                <td><?= $b['date'] ?></td>
                <td><?= substr($b['start_time'],0,5) ?></td>
                <td><?= substr($b['end_time'],0,5) ?></td>
                <td><?= number_format($b['total_price']) ?></td>
                <td>
                  <span class="badge badge-<?= strtolower($b['status']) ?>">
                    <?= $b['status'] ?>
                  </span>
                </td>
                <td>
                  <?php if (in_array($b['status'], ['Pending','Confirmed'])): ?>
                    <form method="POST"
                          onsubmit="return confirm('Cancel this session?')">
                      <input type="hidden" name="cancel_id" value="<?= $b['id'] ?>"/>
                      <button type="submit" class="btn btn-danger btn-sm">Cancel</button>
                    </form>
                  <?php else: ?>–<?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:20px;">
        <a href="/myproject/coaches.php" class="btn btn-outline">← Browse Coaches</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>