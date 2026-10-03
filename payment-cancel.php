<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php"); exit();
}

require_once __DIR__ . '/includes/db.php';

$order_id = $_GET['order_id'] ?? '';
// Leave payment as Pending so user can retry from My Bookings

$initial = strtoupper(substr($_SESSION['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Payment Cancelled – Adam Indoors</title>
  <link rel="stylesheet" href="/css/style.css"/>
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
      <div class="utb-title">Payment Cancelled</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">
      <div style="max-width:480px;margin:20px auto;text-align:center;
                  background:white;border-radius:var(--r-lg);
                  box-shadow:var(--shadow-lg);padding:40px 28px;">
        <div style="font-size:60px;margin-bottom:16px;">❌</div>
        <h2 style="font-size:22px;font-weight:700;margin-bottom:10px;">
          Payment Cancelled
        </h2>
        <p style="color:var(--text-muted);font-size:14px;line-height:1.7;">
          No amount was charged. Your booking is still reserved.<br/>
          You can try again anytime from My Bookings.
        </p>
        <div style="display:flex;gap:12px;justify-content:center;
                    flex-wrap:wrap;margin-top:24px;">
          <a href="/my-bookings.php" class="btn btn-primary">
            📋 Back to My Bookings
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