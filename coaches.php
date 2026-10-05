<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php"); exit();
}
require_once __DIR__ . '/includes/db.php';

// From booking page params
$fromBooking = isset($_GET['from_booking']) && $_GET['from_booking'] == '1';
$bookingId   = intval($_GET['booking_id'] ?? 0);
$bookingDate = $_GET['date']       ?? '';
$bookingStart= $_GET['start']      ?? '';
$bookingEnd  = $_GET['end']        ?? '';
$sportFilter = $_GET['sport']      ?? '';
$courtName   = $_GET['court_name'] ?? '';
$courtPrice  = floatval($_GET['court_price'] ?? 0);
$duration    = floatval($_GET['duration']    ?? 1);

if ($sportFilter) {
    $stmt = $pdo->prepare("SELECT * FROM coaches WHERE status='active' AND sport=? ORDER BY name");
    $stmt->execute([$sportFilter]);
} else {
    $stmt = $pdo->query("SELECT * FROM coaches WHERE status='active' ORDER BY sport, name");
}
$coaches = $stmt->fetchAll();

$grouped = [];
foreach ($coaches as $c) { $grouped[$c['sport']][] = $c; }

$initial = strtoupper(substr($_SESSION['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Our Coaches – Adam Indoors</title>
  <link rel="stylesheet" href="/css/style.css"/>
  <style>
    .coaches-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 20px;
      margin-top: 16px;
      margin-bottom: 36px;
    }
    .coach-card {
      background: white;
      border-radius: var(--r-lg);
      overflow: hidden;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--gray-200);
      transition: all 0.25s;
    }
    .coach-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-md);
      border-color: var(--primary-light);
    }
    .coach-photo {
      height: 170px;
      background: var(--primary-soft);
      display: flex; align-items: center;
      justify-content: center;
      font-size: 64px;
      position: relative;
      overflow: hidden;
    }
    .coach-photo img { width:100%; height:100%; object-fit:cover; }
    .coach-id-badge {
      position: absolute; top:10px; left:10px;
      background: var(--primary);
      color: white; font-size:11px; font-weight:700;
      padding: 3px 8px; border-radius: 8px;
      font-family: monospace;
    }
    .coach-sport-badge {
      position: absolute; top:10px; right:10px;
      background: var(--primary-mid);
      color: white; font-size:11px; font-weight:600;
      padding: 3px 10px; border-radius: 12px;
    }
    .coach-body { padding: 16px 18px; }
    .coach-name { font-size:16px; font-weight:700; color:var(--primary); margin-bottom:3px; }
    .coach-exp  { font-size:12px; color:var(--text-muted); margin-bottom:8px; }
    .coach-desc { font-size:12px; color:var(--text-muted); line-height:1.7; margin-bottom:10px; }
    .coach-avail {
      background:var(--primary-soft); border-radius:var(--r-sm);
      padding:6px 10px; font-size:11px; color:var(--primary); margin-bottom:10px;
    }
    .coach-rate { font-size:15px; font-weight:700; color:var(--primary-mid); margin-bottom:10px; }
    .coach-contacts { display:flex; gap:10px; font-size:12px; color:var(--text-muted); flex-wrap:wrap; }
    .sport-section-title {
      font-size:18px; font-weight:700; color:var(--primary);
      padding: 8px 0 4px;
      border-left: 4px solid var(--primary-mid);
      padding-left: 14px;
      margin-bottom: 4px;
    }
    .booking-banner {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-mid) 100%);
      color: white; padding: 16px 22px; border-radius: var(--r-md);
      margin-bottom: 24px; display:flex; align-items:center; gap:14px;
    }
    .booking-banner .bb-icon { font-size:28px; }
    .booking-banner h4 { font-size:15px; font-weight:700; margin-bottom:4px; }
    .booking-banner p  { font-size:12px; opacity:0.88; }

    /* Book button — only shown when coming from booking flow */
    .book-coach-btn {
      display: block;
      background: var(--primary-mid);
      color: white;
      text-align: center;
      padding: 10px;
      border-radius: var(--r-sm);
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      margin-top: 12px;
      transition: all 0.2s;
    }
    .book-coach-btn:hover {
      background: var(--primary);
      color: white;
    }
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
      <div>
        <strong><?= htmlspecialchars($_SESSION['name']) ?></strong>
        <small>Member</small>
      </div>
    </div>
    <div class="usb-section">Menu</div>
    <a href="/dashboard.php"         class="usb-link"><span class="usb-icon-sm">📊</span> Dashboard</a>
    <a href="/about.php"             class="usb-link"><span class="usb-icon-sm">ℹ️</span> About Us</a>
    <a href="/my-bookings.php"       class="usb-link"><span class="usb-icon-sm">📋</span> My Bookings</a>
    <a href="/coaches.php"           class="usb-link active"><span class="usb-icon-sm">🎽</span> Coaches</a>
    <a href="/my-coach-bookings.php" class="usb-link"><span class="usb-icon-sm">📝</span> My Coach Bookings</a>
    <div class="usb-section">Account</div>
    <a href="/logout.php" class="usb-link logout-link"><span class="usb-icon-sm">🚪</span> Logout</a>
    <div class="usb-bottom">© 2025 Adam Indoors</div>
  </div>

  <div class="user-main">
    <div class="user-topbar">
      <div class="utb-title">Our Coaches</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">
      <div class="page-header" style="border-radius:var(--r-md);margin-bottom:24px;">
        <h1>🎽 Our Coaches</h1>
        <p>
          <?= $fromBooking
              ? 'Select a coach for your session'
              : 'Meet our professional coaches' ?>
        </p>
      </div>

      <!-- Show booking banner only when coming from booking flow -->
      <?php if ($fromBooking && $bookingId): ?>
      <div class="booking-banner">
        <div class="bb-icon">📋</div>
        <div>
          <h4>Your court is booked — now pick a coach</h4>
          <p>
            Date: <strong><?= htmlspecialchars($bookingDate) ?></strong>
            &nbsp;|&nbsp;
            Time: <strong><?= htmlspecialchars($bookingStart) ?> – <?= htmlspecialchars($bookingEnd) ?></strong>
            <?php if ($sportFilter): ?>
              &nbsp;|&nbsp; Sport: <strong><?= htmlspecialchars($sportFilter) ?></strong>
            <?php endif; ?>
          </p>
          <p style="margin-top:4px;">
            Or
            <a href="/payment.php?booking_id=<?= $bookingId ?>"
               style="color:var(--accent);font-weight:600;">
              skip and pay now →
            </a>
          </p>
        </div>
      </div>
      <?php endif; ?>

      <?php if (empty($coaches)): ?>
        <div class="alert alert-info">No coaches available at the moment.</div>
      <?php else: ?>
        <?php foreach ($grouped as $sport => $sportCoaches):
          $sportIcon = $sport==='Badminton'?'🏸':($sport==='Futsal'?'⚽':($sport==='Cricket'?'🏏':($sport==='Tennis'?'🎾':'🏅')));
        ?>
          <div class="sport-section-title">
            <?= $sportIcon ?> <?= htmlspecialchars($sport) ?> Coaches
          </div>
          <div class="coaches-grid">
            <?php foreach ($sportCoaches as $coach):
              $imgPath = $_SERVER['DOCUMENT_ROOT'].'/images/coaches/'.($coach['image']??'');
              $hasImg  = !empty($coach['image']) && file_exists($imgPath);
            ?>
            <div class="coach-card">
              <div class="coach-photo">
                <?php if ($hasImg): ?>
                  <img src="/images/coaches/<?= htmlspecialchars($coach['image']) ?>" alt=""/>
                <?php else: ?>👤<?php endif; ?>
                <span class="coach-id-badge"><?= htmlspecialchars($coach['id']) ?></span>
                <span class="coach-sport-badge"><?= htmlspecialchars($coach['sport']) ?></span>
              </div>
              <div class="coach-body">
                <div class="coach-name"><?= htmlspecialchars($coach['name']) ?></div>
                <div class="coach-exp">⭐ <?= $coach['experience_years'] ?> years experience</div>
                <div class="coach-desc"><?= htmlspecialchars($coach['description']) ?></div>
                <?php if ($coach['availability']): ?>
                  <div class="coach-avail">📅 <?= htmlspecialchars(str_replace('???', ' - ', $coach['availability'])) ?></div>
                <?php endif; ?>
                <div class="coach-rate">LKR <?= number_format($coach['hourly_rate']) ?> / hour</div>
                <div class="coach-contacts">
                  <?php if ($coach['phone']): ?>
                    <span>📞 <?= htmlspecialchars($coach['phone']) ?></span>
                  <?php endif; ?>
                  <?php if ($coach['email']): ?>
                    <span>✉️ <?= htmlspecialchars($coach['email']) ?></span>
                  <?php endif; ?>
                </div>

                <?php
                // Only show Book button if user came from the booking flow
                if ($fromBooking && $bookingId):
                  $bookUrl = '/coach-booking.php?coach_id='.urlencode($coach['id'])
                           . '&from_booking=1'
                           . '&court_booking_id='.$bookingId
                           . '&date='.urlencode($bookingDate)
                           . '&start='.urlencode($bookingStart)
                           . '&end='.urlencode($bookingEnd)
                           . '&court_name='.urlencode($courtName)
                           . '&court_price='.$courtPrice
                           . '&duration='.$duration;
                ?>
                  <a href="<?= $bookUrl ?>" class="book-coach-btn">
                    📅 Book <?= htmlspecialchars($coach['name']) ?>
                  </a>
                <?php endif; ?>

              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>