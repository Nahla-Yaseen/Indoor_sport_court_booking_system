<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php"); exit();
}
require_once __DIR__ . '/includes/db.php';

$courts  = $pdo->query("SELECT * FROM courts WHERE status='active'")->fetchAll();
$initial = strtoupper(substr($_SESSION['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard – Adam Indoors</title>
  <link rel="stylesheet" href="/css/style.css"/>
</head>
<body>
<div class="user-wrapper">

  <!-- SIDEBAR -->
  <div class="user-sidebar">
    <div class="usb-brand">
      <div class="usb-icon">🏸</div>
      <div class="usb-title">
        <strong>Adam Indoors</strong>
        <small>Sports Booking</small>
      </div>
    </div>
    <div class="usb-user">
      <div class="usb-avatar"><?= $initial ?></div>
      <div>
        <strong><?= htmlspecialchars($_SESSION['name']) ?></strong>
        <small>Member</small>
      </div>
    </div>
    <div class="usb-section">Menu</div>
    <a href="/dashboard.php"         class="usb-link active">
      <span class="usb-icon-sm">📊</span> Dashboard
    </a>
    <a href="/about.php"             class="usb-link">
      <span class="usb-icon-sm">ℹ️</span> About Us
    </a>
    <a href="/my-bookings.php"       class="usb-link">
      <span class="usb-icon-sm">📋</span> My Bookings
    </a>
    <a href="/coaches.php"           class="usb-link">
      <span class="usb-icon-sm">🎽</span> Coaches
    </a>
    <a href="/my-coach-bookings.php" class="usb-link">
      <span class="usb-icon-sm">📝</span> My Coach Bookings
    </a>
    <div class="usb-section">Account</div>
    <a href="/logout.php" class="usb-link logout-link">
      <span class="usb-icon-sm">🚪</span> Logout
    </a>
    <div class="usb-bottom">© 2025 Adam Indoors</div>
  </div>

  <!-- MAIN CONTENT -->
  <div class="user-main">
    <div class="user-topbar">
      <div class="utb-title">Dashboard</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">

      <div class="page-header"
           style="border-radius:var(--r-md);margin-bottom:24px;">
        <h1>Welcome, <?= htmlspecialchars($_SESSION['name']) ?> 👋</h1>
        <p>Choose a court below to make your booking</p>
      </div>

      <div class="section-title">Choose Your Court</div>
      <div class="section-sub">
        Select a court to check availability and book a slot
      </div>

      <div class="court-grid" style="margin-top:20px;">
        <?php
        $banners = ['banner-green','banner-blue','banner-orange'];
        $icons   = ['🏸','⚽','🎾','🏏'];
        $i = 0;
        foreach ($courts as $court):
            $banner  = $banners[$i % count($banners)];
            $icon    = $icons[$i % count($icons)];
            $i++;
            $imgFile = isset($court['image']) ? $court['image'] : '';
            $hasImg  = !empty($imgFile) && file_exists(
                $_SERVER['DOCUMENT_ROOT'].'/images/courts/'.$imgFile
            );
        ?>
        <div class="court-card">
          <?php if ($hasImg): ?>
            <img src="/images/courts/<?= htmlspecialchars($imgFile) ?>"
                 style="width:100%;height:130px;object-fit:cover;"
                 alt="<?= htmlspecialchars($court['name']) ?>"/>
          <?php else: ?>
            <div class="court-banner <?= $banner ?>"><?= $icon ?></div>
          <?php endif; ?>
          <div class="court-info">
            <h3><?= htmlspecialchars($court['name']) ?></h3>
            <p><?= htmlspecialchars($court['sport']) ?> · Indoor facility</p>
            <div class="court-rate">
              LKR <?= number_format($court['rate_per_hour']) ?> / hour
            </div>
            <a href="/booking.php?court_id=<?= $court['id'] ?>"
               class="btn btn-primary btn-full">Select Court</a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
</div>
</body>
</html>