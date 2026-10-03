<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php"); exit();
}
require_once __DIR__ . '/includes/db.php';

// Get coach
$coach_id = $_GET['coach_id'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM coaches WHERE id=? AND status='active'");
$stmt->execute([$coach_id]);
$coach = $stmt->fetch();
if (!$coach) { header("Location: /coaches.php"); exit(); }

// From booking page — all values passed via GET
$fromBooking    = isset($_GET['from_booking']) && $_GET['from_booking'] == '1';
$courtBookingId = intval($_GET['court_booking_id'] ?? 0);
$prefDate       = $_GET['date']       ?? date('Y-m-d');
$prefStart      = $_GET['start']      ?? '';
$prefEnd        = $_GET['end']        ?? '';
$courtName      = $_GET['court_name'] ?? '';
$courtPrice     = floatval($_GET['court_price'] ?? 0);
$prefDuration   = floatval($_GET['duration'] ?? 1);

// Auto-calculate end time from start + duration if needed
$autoEndTime = $prefEnd;
if ($prefStart && $prefDuration && !$prefEnd) {
    $startTs     = strtotime($prefDate . ' ' . $prefStart);
    $endTs       = $startTs + ($prefDuration * 3600);
    $autoEndTime = date('H:i', $endTs);
}
if (!$autoEndTime) $autoEndTime = $prefEnd;

// Load court booking to get accurate court name and price
if ($courtBookingId) {
    $cbStmt = $pdo->prepare("
        SELECT b.*, c.name AS court_name, c.rate_per_hour
        FROM bookings b
        JOIN courts c ON b.court_id = c.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $cbStmt->execute([$courtBookingId, $_SESSION['user_id']]);
    $courtBookingRow = $cbStmt->fetch();
    if ($courtBookingRow) {
        // Override with accurate DB values
        if (empty($courtName))  $courtName  = $courtBookingRow['court_name'];
        if ($courtPrice == 0)   $courtPrice = floatval($courtBookingRow['total_price']);
        if (empty($prefStart))  $prefStart  = substr($courtBookingRow['start_time'], 0, 5);
        if (empty($autoEndTime)) $autoEndTime = substr($courtBookingRow['end_time'], 0, 5);
        if (empty($prefDate))   $prefDate   = $courtBookingRow['date'];

        // Calculate duration from booking slots
        $slots = json_decode($courtBookingRow['selected_slots'] ?? '[]', true);
        if (!empty($slots) && $prefDuration == 1) {
            $prefDuration = count($slots);
        }
    }
}

$coachPrice = $coach['hourly_rate'] * $prefDuration;
$grandTotal = $courtPrice + $coachPrice;

$error   = '';
$success = false;
$coachBookingDetails = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date   = $_POST['date']             ?? $prefDate;
    $start  = $_POST['start_time']       ?? $prefStart;
    $end    = $_POST['end_time']         ?? $autoEndTime;
    $hours  = floatval($_POST['duration_hours']  ?? $prefDuration);
    $cbId   = intval($_POST['court_booking_id']  ?? 0);
    $cName  = $_POST['court_name']       ?? $courtName;
    $cPrice = floatval($_POST['court_price']     ?? $courtPrice);

    // Recalculate from POST values
    $sessionCoachPrice = $coach['hourly_rate'] * $hours;
    $sessionGrandTotal = $cPrice + $sessionCoachPrice;

    if (!$date || !$start || !$end) {
        $error = 'Session details are missing. Please go back and try again.';
    } else {
        // Format times for DB
        $startDB = strlen($start) === 5 ? $start . ':00' : $start;
        $endDB   = strlen($end)   === 5 ? $end   . ':00' : $end;

        // Check coach availability
        $chk = $pdo->prepare("
            SELECT id FROM coach_bookings
            WHERE coach_id=? AND date=? AND status NOT IN ('Cancelled')
            AND start_time < ? AND end_time > ?
        ");
        $chk->execute([$coach_id, $date, $endDB, $startDB]);

        if ($chk->fetch()) {
            $error = '❌ This coach is already booked for that time. Please choose a different coach.';
        } else {
            $pdo->prepare("
                INSERT INTO coach_bookings
                (user_id, coach_id, court_booking_id, date, start_time, end_time,
                 duration_hours, total_price, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed')
            ")->execute([
                $_SESSION['user_id'], $coach_id,
                $cbId ? $cbId : null,
                $date, $startDB, $endDB,
                intval($hours), $sessionCoachPrice
            ]);

            $newCoachBookingId = $pdo->lastInsertId();
            $success           = true;

            $coachBookingDetails = [
                'coach_booking_id' => $newCoachBookingId,
                'court_booking_id' => $cbId,
                'coach_name'       => $coach['name'],
                'coach_id'         => $coach['id'],
                'sport'            => $coach['sport'],
                'court_name'       => $cName,
                'date'             => $date,
                'start_time'       => substr($startDB, 0, 5),
                'end_time'         => substr($endDB, 0, 5),
                'duration_hours'   => intval($hours),
                'coach_price'      => $sessionCoachPrice,
                'court_price'      => $cPrice,
                'grand_total'      => $sessionGrandTotal,
            ];

            // Redirect to payment with both booking IDs
            if ($cbId) {
                header("Location: /payment.php?booking_id={$cbId}&coach_booking_id={$newCoachBookingId}");
                exit();
            }
        }
    }
}

$initial   = strtoupper(substr($_SESSION['name'], 0, 1));
$sportIcon = $coach['sport']==='Badminton' ? '🏸' :
            ($coach['sport']==='Futsal'    ? '⚽' :
            ($coach['sport']==='Cricket'   ? '🏏' : '🎾'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Confirm Coach Session – Adam Indoors</title>
  <link rel="stylesheet" href="/css/style.css"/>
  <style>
    .coach-info-bar {
      background: white; border: 1px solid var(--gray-200);
      border-radius: var(--r-md); padding: 18px 22px;
      display: flex; align-items: center; gap: 18px;
      margin-bottom: 22px; box-shadow: var(--shadow-sm);
    }
    .coach-av-lg {
      width:64px; height:64px; border-radius:50%;
      background:var(--primary-soft); display:flex;
      align-items:center; justify-content:center;
      font-size:28px; flex-shrink:0; overflow:hidden;
    }
    .coach-av-lg img { width:100%; height:100%; object-fit:cover; }

    /* SESSION PREVIEW */
    .session-preview {
      background: white;
      border: 1.5px solid var(--primary-light);
      border-radius: var(--r-lg);
      overflow: hidden;
      margin-bottom: 20px;
      box-shadow: var(--shadow-sm);
    }
    .sp-section-header {
      background: var(--primary-soft);
      padding: 10px 18px;
      font-size: 12px; font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      color: var(--primary);
      border-bottom: 1px solid var(--primary-light);
    }
    .sp-row {
      display: flex; justify-content: space-between;
      padding: 10px 18px; font-size: 13px;
      border-bottom: 1px dashed var(--gray-200);
    }
    .sp-row:last-child { border-bottom: none; }
    .sp-row .spl { color: var(--text-muted); }
    .sp-row .spv { font-weight: 500; color: var(--text-dark); }
    .sp-subtotal {
      display: flex; justify-content: space-between;
      padding: 10px 18px; font-size: 14px;
      font-weight: 600; color: var(--primary-mid);
      background: var(--primary-pale);
      border-top: 1.5px solid var(--primary-light);
    }
    .sp-grand {
      display: flex; justify-content: space-between;
      padding: 14px 18px; font-size: 17px;
      font-weight: 700; color: var(--primary);
      background: var(--primary-soft);
      border-top: 2px solid var(--primary);
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
      <div class="utb-title">Confirm Coach Session</div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">
      <div style="max-width:640px;">

        <?php if ($error): ?>
          <div class="alert alert-danger" style="margin-bottom:16px;">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- COACH INFO BAR -->
        <?php
        $imgPath = $_SERVER['DOCUMENT_ROOT'].'/images/coaches/'.($coach['image'] ?? '');
        $hasImg  = !empty($coach['image']) && file_exists($imgPath);
        ?>
        <div class="coach-info-bar">
          <div class="coach-av-lg">
            <?php if ($hasImg): ?>
              <img src="/images/coaches/<?= htmlspecialchars($coach['image']) ?>" alt=""/>
            <?php else: ?>👤<?php endif; ?>
          </div>
          <div>
            <div style="font-size:16px;font-weight:700;color:var(--primary);">
              <?= $sportIcon ?> <?= htmlspecialchars($coach['name']) ?>
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
              <span style="font-family:monospace;font-weight:600;">
                <?= htmlspecialchars($coach['id']) ?>
              </span>
              &nbsp;·&nbsp; <?= htmlspecialchars($coach['sport']) ?>
              &nbsp;·&nbsp; <?= $coach['experience_years'] ?> yrs
            </div>
            <div style="font-size:14px;font-weight:600;color:var(--primary-mid);margin-top:4px;">
              LKR <?= number_format($coach['hourly_rate']) ?> / hour
            </div>
          </div>
        </div>

        <!-- SESSION PREVIEW -->
        <div class="session-preview">

          <!-- COURT BOOKING SECTION -->
          <?php if ($courtBookingId && !empty($courtName)): ?>
          <div class="sp-section-header">🏸 Court Booking</div>
          <div class="sp-row">
            <span class="spl">Booking ID</span>
            <span class="spv"><strong>#<?= $courtBookingId ?></strong></span>
          </div>
          <div class="sp-row">
            <span class="spl">Court</span>
            <span class="spv"><?= htmlspecialchars($courtName) ?></span>
          </div>
          <div class="sp-row">
            <span class="spl">Date</span>
            <span class="spv"><?= htmlspecialchars($prefDate) ?></span>
          </div>
          <div class="sp-row">
            <span class="spl">Time</span>
            <span class="spv">
              <?= htmlspecialchars($prefStart) ?> – <?= htmlspecialchars($autoEndTime) ?>
            </span>
          </div>
          <div class="sp-row">
            <span class="spl">Duration</span>
            <span class="spv">
              <?= intval($prefDuration) ?> hour<?= $prefDuration != 1 ? 's' : '' ?>
            </span>
          </div>
          <div class="sp-subtotal">
            <span>Court Fee</span>
            <span>LKR <?= number_format($courtPrice) ?></span>
          </div>
          <?php endif; ?>

          <!-- COACH SESSION SECTION -->
          <div class="sp-section-header">🎽 Coach Session</div>
          <div class="sp-row">
            <span class="spl">Coach</span>
            <span class="spv">
              <?= htmlspecialchars($coach['name']) ?>
              <small style="color:var(--text-muted);font-family:monospace;">
                (<?= htmlspecialchars($coach['id']) ?>)
              </small>
            </span>
          </div>
          <div class="sp-row">
            <span class="spl">Sport</span>
            <span class="spv"><?= $sportIcon ?> <?= htmlspecialchars($coach['sport']) ?></span>
          </div>
          <div class="sp-row">
            <span class="spl">Date</span>
            <span class="spv"><?= htmlspecialchars($prefDate) ?></span>
          </div>
          <div class="sp-row">
            <span class="spl">Time</span>
            <span class="spv">
              <?= htmlspecialchars($prefStart) ?> – <?= htmlspecialchars($autoEndTime) ?>
            </span>
          </div>
          <div class="sp-row">
            <span class="spl">Duration</span>
            <span class="spv">
              <?= intval($prefDuration) ?> hour<?= $prefDuration != 1 ? 's' : '' ?>
            </span>
          </div>
          <div class="sp-row">
            <span class="spl">Coach Rate</span>
            <span class="spv">LKR <?= number_format($coach['hourly_rate']) ?> / hour</span>
          </div>
          <div class="sp-subtotal">
            <span>Coach Fee</span>
            <span>LKR <?= number_format($coachPrice) ?></span>
          </div>

          <!-- GRAND TOTAL -->
          <div class="sp-grand">
            <span>Total Amount</span>
            <span>LKR <?= number_format($grandTotal) ?></span>
          </div>
        </div>

        <!-- CONFIRM FORM -->
        <form method="POST">
          <input type="hidden" name="date"             value="<?= htmlspecialchars($prefDate) ?>"/>
          <input type="hidden" name="start_time"       value="<?= htmlspecialchars($prefStart) ?>"/>
          <input type="hidden" name="end_time"         value="<?= htmlspecialchars($autoEndTime) ?>"/>
          <input type="hidden" name="duration_hours"   value="<?= $prefDuration ?>"/>
          <input type="hidden" name="court_booking_id" value="<?= $courtBookingId ?>"/>
          <input type="hidden" name="court_name"       value="<?= htmlspecialchars($courtName) ?>"/>
          <input type="hidden" name="court_price"      value="<?= $courtPrice ?>"/>

          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <?php
            // Build back URL to coaches page
            $backParams = http_build_query([
                'from_booking'    => 1,
                'booking_id'      => $courtBookingId,
                'date'            => $prefDate,
                'start'           => $prefStart,
                'end'             => $autoEndTime,
                'sport'           => $coach['sport'],
                'court_name'      => $courtName,
                'duration'        => $prefDuration,
                'court_price'     => $courtPrice,
            ]);
            ?>
            <a href="/coaches.php?<?= $backParams ?>"
               class="btn btn-outline">
              ← Choose Different Coach
            </a>
            <button type="submit" class="btn btn-success" style="flex:1;">
              ✅ Confirm &amp; Proceed to Payment
            </button>
          </div>

          <p style="font-size:12px;color:var(--text-muted);text-align:center;margin-top:14px;">
            After confirming, you will be redirected to the payment page to complete your booking.
          </p>
        </form>

      </div>
    </div>
  </div>
</div>
</body>
</html>