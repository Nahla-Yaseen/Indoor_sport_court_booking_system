<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /myproject/login.php"); exit();
}
$initial = strtoupper(substr($_SESSION['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>About Us – Adam Indoors</title>
  <link rel="stylesheet" href="/myproject/css/style.css"/>
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
    <a href="/myproject/dashboard.php"         class="usb-link"><span class="usb-icon-sm">📊</span> Dashboard</a>
    <a href="/myproject/about.php"             class="usb-link"><span class="usb-icon-sm">ℹ️</span> About Us</a>
    <a href="/myproject/coaches.php"           class="usb-link"><span class="usb-icon-sm">🏅</span> Coaches</a>
    <a href="/myproject/my-bookings.php"       class="usb-link"><span class="usb-icon-sm">📋</span> My Bookings</a>
    <a href="/myproject/my-coach-bookings.php" class="usb-link"><span class="usb-icon-sm">🎯</span> Coach Sessions</a>
    <div class="usb-bottom">© 2025 Adam Indoors</div>
  </div>

  <!-- MAIN -->
  <div class="user-main">
    <div class="user-topbar">
      <div class="utb-title">About Us</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">

      <!-- Smaller green hero box — no subtitle text -->
      <div style="background:linear-gradient(135deg,var(--primary) 0%,var(--primary-mid) 100%);
                  color:white; padding:28px 40px; border-radius:var(--r-lg);
                  text-align:center; margin-bottom:24px;">
        <div style="font-size:42px; margin-bottom:10px;">🏸</div>
        <h1 style="font-size:22px; font-weight:700;">Adam Indoors</h1>
      </div>

      <!-- Info Cards -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
        <div class="card">
          <div class="card-body" style="text-align:center;">
            <div style="font-size:28px;margin-bottom:10px;">📍</div>
            <h3 style="font-size:13px;font-weight:600;color:var(--primary);margin-bottom:6px;">Location</h3>
            <p style="font-size:12px;color:var(--text-muted);">An-Noorpura, Panagamuwa,<br/>Kurunegala, Sri Lanka</p>
          </div>
        </div>
        <div class="card">
          <div class="card-body" style="text-align:center;">
            <div style="font-size:28px;margin-bottom:10px;">📞</div>
            <h3 style="font-size:13px;font-weight:600;color:var(--primary);margin-bottom:6px;">Contact</h3>
            <p style="font-size:12px;color:var(--text-muted);">0777 123 486<br/>Mr. A.R.M. Harees</p>
          </div>
        </div>
        <div class="card">
          <div class="card-body" style="text-align:center;">
            <div style="font-size:28px;margin-bottom:10px;">🕐</div>
            <h3 style="font-size:13px;font-weight:600;color:var(--primary);margin-bottom:6px;">Opening Hours</h3>
            <p style="font-size:12px;color:var(--text-muted);">Every Day<br/>6:00 AM – 10:00 PM</p>
          </div>
        </div>
        <div class="card">
          <div class="card-body" style="text-align:center;">
            <div style="font-size:28px;margin-bottom:10px;">🏟️</div>
            <h3 style="font-size:13px;font-weight:600;color:var(--primary);margin-bottom:6px;">Our Courts</h3>
            <p style="font-size:12px;color:var(--text-muted);">Badminton Court<br/>Futsal Court</p>
          </div>
        </div>
      </div>

      <!-- About Text -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-body">
          <div class="card-title">🏢 About Our Facility</div>
          <p style="color:var(--text-muted);font-size:14px;line-height:1.9;">
            Adam Indoors is a modern indoor sports facility located in Kurunegala, Sri Lanka.
            We provide high-quality courts for badminton and futsal, catering to players of all
            skill levels — from casual players to competitive teams. Our online booking system
            makes it easy to reserve courts without calling or visiting in person.
          </p>
        </div>
      </div>

      <!-- Pricing Table -->
      <div class="card">
        <div class="card-body">
          <div class="card-title">💰 Pricing</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Court</th>
                  <th>Rate/Hour</th>
                  <th>Standard (3hrs)</th>
                  <th>Extended (6hrs)</th>
                  <th>Tournament (10hrs)</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>🏸 Badminton Court</td>
                  <td>LKR 600</td>
                  <td>LKR 1,500</td>
                  <td>LKR 2,800</td>
                  <td>LKR 4,500</td>
                </tr>
                <tr>
                  <td>⚽ Futsal Court</td>
                  <td>LKR 1,200</td>
                  <td>LKR 1,500</td>
                  <td>LKR 2,800</td>
                  <td>LKR 4,500</td>
                </tr>
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