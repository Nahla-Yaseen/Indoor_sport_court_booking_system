<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php"); exit();
}
require_once __DIR__ . '/includes/db.php';

$court_id = intval($_GET['court_id'] ?? 0);

// Fetch user's wallet balance
$uStmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id=?");
$uStmt->execute([$_SESSION['user_id']]);
$walletBal = floatval($uStmt->fetchColumn() ?? 0);
$stmt = $pdo->prepare("SELECT * FROM courts WHERE id=? AND status='active'");
$stmt->execute([$court_id]);
$court = $stmt->fetch();
if (!$court) { header("Location: /dashboard.php"); exit(); }

$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE court_id=? AND status='active' ORDER BY duration_hours ASC");
$pkgStmt->execute([$court_id]);
$packages = $pkgStmt->fetchAll();

// Get active time slots for this court
$slotStmt = $pdo->prepare("SELECT * FROM time_slots WHERE court_id=? AND is_active=1 ORDER BY slot_time ASC");
$slotStmt->execute([$court_id]);
$allSlots = $slotStmt->fetchAll();

$error = ''; $success = false;
$newBookingId = null;
$bookingDetails = [];

// ---- HANDLE BOOKING SUBMISSION ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {
    $type          = $_POST['booking_type'];
    $date          = $_POST['date'];
    $selectedSlots = json_decode($_POST['selected_slots'] ?? '[]', true);
    $package_name  = $_POST['package_name'] ?? null;
    $total_price   = floatval($_POST['total_price']);
    $need_coach    = $_POST['need_coach'] ?? 'no';

    if (empty($selectedSlots) || !$date) {
        $error = 'Please select at least one time slot.';
    } else {
        sort($selectedSlots);
        $start_time = $selectedSlots[0] . ':00';
        $lastSlot   = end($selectedSlots);
        // End time = last slot + 1 hour
        $endH       = intval(substr($lastSlot, 0, 2)) + 1;
        $end_time   = str_pad($endH, 2, '0', STR_PAD_LEFT) . ':00:00';

        // Check blocked slots
        $blocked = $pdo->prepare("SELECT id FROM blocked_slots WHERE (court_id=? OR court_id IS NULL) AND date=?");
        $blocked->execute([$court_id, $date]);
        if ($blocked->fetch()) {
            $error = '❌ This court is not available on this date. Please try another date.';
        } else {
            // Check if any selected slot is already booked
            $placeholders = implode(',', array_fill(0, count($selectedSlots), '?'));
            $params = array_merge([$court_id, $date], $selectedSlots);
            $chk = $pdo->prepare("
                SELECT slot_time FROM booked_slots
                WHERE court_id=? AND date=? AND slot_time IN ($placeholders)
            ");
            $chk->execute($params);
            $takenSlots = $chk->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($takenSlots)) {
                $takenStr = implode(', ', array_map(function($t) {
                    $h = intval(substr($t,0,2));
                    $ampm = $h >= 12 ? 'PM' : 'AM';
                    $h12  = $h > 12 ? $h-12 : ($h==0?12:$h);
                    return $h12.':00 '.($ampm);
                }, $takenSlots));
                $error = '❌ The following slots are already booked: '.$takenStr.'. Please choose different slots.';
            } else {
                // Save booking
                $slotsJson = json_encode($selectedSlots);
                $slotCount = count($selectedSlots);
                $stmt = $pdo->prepare("
                    INSERT INTO bookings
                    (user_id,court_id,booking_type,package_name,date,
                     start_time,end_time,total_price,status,selected_slots,slot_count)
                    VALUES (?,?,?,?,?,?,?,?,'Confirmed',?,?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],$court_id,$type,$package_name,
                    $date,$start_time,$end_time,$total_price,
                    $slotsJson,$slotCount
                ]);
                
                $newBookingId = $pdo->lastInsertId();

                $userStmt = $pdo->prepare("SELECT name, email FROM users WHERE id=?");
$userStmt->execute([$_SESSION['user_id']]);
$user = $userStmt->fetch();

// Only send the initial email when no coach is needed.
// When a coach IS needed, the post-payment email (mailer.php) will be sent
// after payment, showing the correct combined total (court + coach).
if ($need_coach !== 'yes') {
    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mohamedysn130@gmail.com';
        $mail->Password = 'bccadmctjoixahpj';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('mohamedysn130@gmail.com', 'Adam Indoors');
        $mail->addAddress($user['email'], $user['name']);

        $mail->isHTML(true);

        $mail->Subject = "Booking Confirmation";

        $mail->Body = "
    <h2>Booking Received</h2>

    <p>Dear {$user['name']},</p>

    <p>Thank you for your booking.</p>

    <table border='1' cellpadding='8' cellspacing='0'>
        <tr>
            <td><b>Booking ID</b></td>
            <td>{$newBookingId}</td>
        </tr>

        <tr>
            <td><b>Court</b></td>
            <td>{$court['name']}</td>
        </tr>

        <tr>
            <td><b>Date</b></td>
            <td>{$date}</td>
        </tr>

        <tr>
            <td><b>Start Time</b></td>
            <td>{$start_time}</td>
        </tr>

        <tr>
            <td><b>End Time</b></td>
            <td>{$end_time}</td>
        </tr>

        <tr>
            <td><b>Total Price</b></td>
            <td>LKR {$total_price}</td>
        </tr>

        <tr>
            <td><b>Status</b></td>
            <td>Confirmed</td>
        </tr>
    </table>

    <br>

    <p>Thank you for choosing Adam Indoors.</p>
    ";

        $mail->send();

    } catch (Exception $e) {
        // Optional: log the error instead of stopping the booking process
    }
}

// ---- IMMEDIATELY block the booked slots so others can't book them ----
$insSlot = $pdo->prepare("
    INSERT IGNORE INTO booked_slots (booking_id, court_id, date, slot_time)
    VALUES (?, ?, ?, ?)
");
foreach ($selectedSlots as $slot) {
    $insSlot->execute([$newBookingId, $court_id, $date, $slot]);
}
                $success = true;

// Calculate total = court price (coach price added separately via coach booking)
$bookingDetails = [
    'id'           => $newBookingId,
    'court_name'   => $court['name'],
    'sport'        => $court['sport'],
    'type'         => $type,
    'package_name' => $package_name,
    'date'         => $date,
    'start_time'   => $start_time,
    'end_time'     => $end_time,
    'slots'        => $selectedSlots,
    'duration'     => $slotCount,
    'total_price'  => $total_price,
    'need_coach'   => $need_coach,
];

if ($need_coach === 'yes') {
    // Go to coaches page first, payment comes after coach booking
    $params = http_build_query([
        'from_booking' => 1,
        'booking_id'   => $newBookingId,
        'date'         => $date,
        'start'        => substr($start_time, 0, 5),
        'end'          => substr($end_time, 0, 5),
        'sport'        => $court['sport'],
        'court_name'   => $court['name'],
        'duration'     => $slotCount,
        'court_price'  => $total_price,
    ]);
    header("Location: /coaches.php?$params");
    exit();
} else {
    // Go directly to payment
    header("Location: /payment.php?booking_id=" . $newBookingId);
    exit();
}
                
                
            }
        }
    }
}

$today   = date('Y-m-d');
$initial = strtoupper(substr($_SESSION['name'], 0, 1));

// ---- AJAX: Get booked slots for a date ----
if (isset($_GET['ajax_slots']) && isset($_GET['date'])) {
    $ajaxDate = $_GET['date'];
    $taken = $pdo->prepare("SELECT slot_time FROM booked_slots WHERE court_id=? AND date=?");
    $taken->execute([$court_id, $ajaxDate]);
    $takenSlots = $taken->fetchAll(PDO::FETCH_COLUMN);
    header('Content-Type: application/json');
    echo json_encode(['taken' => $takenSlots]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Book <?= htmlspecialchars($court['name']) ?> – Adam Indoors</title>
  <link rel="stylesheet" href="/css/style.css"/>
  <style>
    /* TIME SLOT GRID */
    .slots-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
      gap: 10px;
      margin: 16px 0;
    }
    .slot-btn {
      padding: 12px 8px;
      border-radius: var(--r-sm);
      border: 2px solid var(--gray-200);
      background: white;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.18s;
      text-align: center;
      font-family: 'Poppins', sans-serif;
      line-height: 1.4;
    }
    .slot-btn.available {
      background: #e8f5e9;
      border-color: #43a047;
      color: #1b5e20;
    }
    .slot-btn.available:hover {
      background: #43a047;
      color: white;
      transform: translateY(-2px);
    }
    .slot-btn.selected {
      background: var(--primary-mid);
      border-color: var(--primary);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 4px 10px rgba(46,125,50,0.3);
    }
    .slot-btn.booked {
      background: #ffebee;
      border-color: #e53935;
      color: #b71c1c;
      cursor: not-allowed;
      opacity: 0.85;
    }
    .slot-btn.inactive {
      background: var(--gray-100);
      border-color: var(--gray-200);
      color: var(--gray-400);
      cursor: not-allowed;
    }
    .slot-legend {
      display: flex;
      gap: 18px;
      flex-wrap: wrap;
      margin-bottom: 16px;
      font-size: 12px;
    }
    .legend-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-weight: 500;
    }
    .legend-dot {
      width: 14px; height: 14px;
      border-radius: 4px;
      border: 2px solid;
    }
    .dot-available  { background:#e8f5e9; border-color:#43a047; }
    .dot-selected   { background:var(--primary-mid); border-color:var(--primary); }
    .dot-booked     { background:#ffebee; border-color:#e53935; }

    .search-bar {
      display: flex;
      gap: 12px;
      align-items: flex-end;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .search-bar .form-group { margin-bottom: 0; flex: 1; min-width: 180px; }

    /* COACH CHOICE */
    .coach-choice-box {
      background: white; border: 2px solid var(--primary-light);
      border-radius: var(--r-md); padding: 20px 22px; margin-top: 18px;
    }
    .coach-choice-box h4 {
      color: var(--primary); font-size: 15px;
      font-weight: 600; margin-bottom: 14px;
    }
    .choice-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .choice-card {
      border: 2px solid var(--gray-200); border-radius: var(--r-md);
      padding: 16px; text-align: center; cursor: pointer;
      transition: all 0.2s; background: var(--gray-100);
    }
    .choice-card:hover, .choice-card.selected {
      border-color: var(--primary-mid); background: var(--primary-soft);
    }
    .choice-card input[type="radio"] { display: none; }
    .choice-icon { font-size: 30px; margin-bottom: 8px; }
    .choice-card h5 { font-size: 14px; font-weight: 600; color: var(--primary); margin-bottom: 4px; }
    .choice-card p  { font-size: 12px; color: var(--text-muted); }

    /* SUCCESS SUMMARY */
    .success-summary-box {
      background: white; border-radius: var(--r-lg);
      box-shadow: var(--shadow-md); overflow: hidden;
      max-width: 600px; margin: 0 auto;
    }
    .ssb-header {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-mid) 100%);
      color: white; padding: 28px; text-align: center;
    }
    .ssb-header .ss-icon { font-size: 56px; margin-bottom: 10px; }
    .ssb-header h2 { font-size: 22px; font-weight: 700; }
    .ssb-header p  { opacity: 0.88; margin-top: 6px; font-size: 14px; }
    .ssb-body { padding: 24px 28px; }
    .ssb-section-title {
      font-size: 13px; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.6px; color: var(--primary); margin-bottom: 12px;
      padding-bottom: 6px; border-bottom: 2px solid var(--primary-soft);
    }
    .ssb-row {
      display: flex; justify-content: space-between;
      padding: 8px 0; font-size: 13px;
      border-bottom: 1px dashed var(--gray-200);
    }
    .ssb-row:last-child { border-bottom: none; }
    .ssb-row .sl { color: var(--text-muted); }
    .ssb-row .sv { font-weight: 500; color: var(--text-dark); text-align: right; max-width: 60%; }
    .ssb-total {
      display: flex; justify-content: space-between;
      padding: 14px 0 4px; font-size: 16px; font-weight: 700;
      color: var(--primary); border-top: 2px solid var(--primary-light); margin-top: 8px;
    }
    .ssb-status {
      text-align: center; background: var(--warning-soft);
      border-radius: var(--r-sm); padding: 10px; font-size: 13px;
      color: var(--warning); font-weight: 600; margin-top: 14px;
    }
    .ssb-actions {
      padding: 20px 28px; background: var(--primary-pale);
      display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;
    }

    .slot-tag {
      display: inline-block; background: var(--primary-soft);
      border: 1px solid var(--primary-light); border-radius: 4px;
      padding: 2px 8px; font-size: 11px; color: var(--primary);
      font-weight: 600; margin: 2px;
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
    <a href="/coaches.php"           class="usb-link"><span class="usb-icon-sm">🎽</span> Coaches</a>
    <a href="/my-coach-bookings.php" class="usb-link"><span class="usb-icon-sm">📝</span> My Coach Bookings</a>
    <div class="usb-section">Account</div>
    <a href="/logout.php" class="usb-link logout-link"><span class="usb-icon-sm">🚪</span> Logout</a>
    <div class="usb-bottom">© 2025 Adam Indoors</div>
  </div>

  <div class="user-main">
    <div class="user-topbar">
      <div class="utb-title">Book <?= htmlspecialchars($court['name']) ?></div>
      <div class="utb-right">
        <div class="utb-avatar"><?= $initial ?></div>
        <span><?= htmlspecialchars($_SESSION['name']) ?></span>
      </div>
    </div>

    <div class="user-content">

      <?php if ($success && !empty($bookingDetails)): ?>
      <!-- SUCCESS SUMMARY -->
      <div style="padding:10px 0 30px;">
        <div class="success-summary-box">
          <div class="ssb-header">
            <div class="ss-icon">🎉</div>
            <h2>Booking Submitted!</h2>
            <p>Your booking is pending admin confirmation.</p>
          </div>
          <div class="ssb-body">
            <div class="ssb-section-title">🏸 Booking Details</div>
            <div class="ssb-row"><span class="sl">Booking ID</span><span class="sv">#<?= $bookingDetails['id'] ?></span></div>
            <div class="ssb-row"><span class="sl">Court</span><span class="sv"><?= htmlspecialchars($bookingDetails['court_name']) ?></span></div>
            <div class="ssb-row"><span class="sl">Type</span><span class="sv"><?= htmlspecialchars($bookingDetails['type']) ?><?= $bookingDetails['package_name'] ? ' – '.$bookingDetails['package_name'] : '' ?></span></div>
            <div class="ssb-row"><span class="sl">Date</span><span class="sv"><?= htmlspecialchars($bookingDetails['date']) ?></span></div>
            <div class="ssb-row">
              <span class="sl">Time Slots</span>
              <span class="sv">
                <?php foreach ($bookingDetails['slots'] as $s):
                  $h = intval(substr($s,0,2));
                  $h2 = $h > 12 ? $h-12 : ($h==0?12:$h);
                  $am = $h >= 12 ? 'PM' : 'AM';
                  $h2e = $h+1 > 12 ? ($h+1-12) : ($h+1==0?12:($h+1));
                  $am2 = ($h+1) >= 12 ? 'PM' : 'AM';
                ?>
                  <span class="slot-tag"><?= $h2.':00'.$am.' – '.$h2e.':00'.$am2 ?></span>
                <?php endforeach; ?>
              </span>
            </div>
            <div class="ssb-row"><span class="sl">Duration</span><span class="sv"><?= $bookingDetails['duration'] ?> hour<?= $bookingDetails['duration']!=1?'s':'' ?></span></div>
            <div class="ssb-total"><span>Total Price</span><span>LKR <?= number_format($bookingDetails['total_price']) ?></span></div>
            <div class="ssb-status" style="background:var(--primary-soft);color:var(--primary);">✅ Status: Confirmed</div>
          </div>
          <div class="ssb-actions">
            <a href="/my-bookings.php" class="btn btn-primary">📋 View My Bookings</a>
            <a href="/dashboard.php"   class="btn btn-outline">🏠 Dashboard</a>
          </div>
        </div>
      </div>

      <?php else: ?>

      <div class="page-header" style="border-radius:var(--r-md);margin-bottom:24px;">
        <h1>Book <?= htmlspecialchars($court['name']) ?></h1>
        <p>LKR <?= number_format($court['rate_per_hour']) ?> per hour · Select your time slots below</p>
      </div>

      <?php if ($walletBal > 0): ?>
        <div style="max-width:760px; background:#e8f5e9; border:1.5px solid #2e7d32; color:#1b5e20; margin-bottom:20px; display:flex; align-items:center; gap:12px; border-radius:var(--r-md); padding:12px 16px; font-size:14px; font-family:'Poppins', sans-serif;">
          <span style="font-size:20px;">💡</span>
          <div>
            You have a wallet balance of <strong>LKR <?= number_format($walletBal, 2) ?></strong>. Use this wallet amount for this booking payment? <em>(It will be automatically applied at checkout)</em>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger" style="max-width:760px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div style="max-width:760px;">

        <!-- STEP 1: BOOKING TYPE -->
        <div id="step1">
          <div class="section-title">Step 1 – Choose Booking Type</div>
          <div class="section-sub">Pick how you'd like to book your court</div>
          <div class="type-grid">
            <div class="type-card" onclick="selectType('time')">
              <div class="type-icon">⏱️</div>
              <h4>Time-Based Booking</h4>
              <p>Select individual 1-hour time slots. LKR <?= number_format($court['rate_per_hour']) ?> per slot.</p>
            </div>
            <div class="type-card" onclick="selectType('package')">
              <div class="type-icon">📦</div>
              <h4>Package-Based Booking</h4>
              <p>Choose a package — consecutive slots are auto-selected from your start time.</p>
            </div>
          </div>
        </div>

        <!-- ====== TIME-BASED ====== -->
        <div id="formTime" style="display:none;margin-top:20px;">
          <div class="card"><div class="card-body">
            <div class="card-title">⏱️ Time-Based Booking — Select Your Slots</div>

            <!-- DATE SEARCH -->
            <div class="search-bar">
              <div class="form-group">
                <label>Select Date</label>
                <input type="date" id="timeDate"
                       min="<?= $today ?>" value="<?= $today ?>"/>
              </div>
              <button type="button" class="btn btn-primary"
                      onclick="loadTimeSlots()" style="height:42px;">
                🔍 Search Available Slots
              </button>
            </div>

            <!-- LEGEND -->
            <div class="slot-legend">
              <div class="legend-item">
                <div class="legend-dot dot-available"></div>
                <span style="color:#1b5e20;">Available</span>
              </div>
              <div class="legend-item">
                <div class="legend-dot dot-selected"></div>
                <span style="color:var(--primary);">Selected</span>
              </div>
              <div class="legend-item">
                <div class="legend-dot dot-booked"></div>
                <span style="color:#b71c1c;">Already Booked</span>
              </div>
            </div>

            <!-- SLOTS -->
            <div id="timeSlotsContainer" style="display:none;">
              <div class="slots-grid" id="timeSlotGrid"></div>

              <!-- SUMMARY -->
              <div id="timeSelSummary" class="summary-box" style="display:none;margin-top:16px;">
                <h4>📋 Booking Summary</h4>
                <div class="summary-row"><span class="label">Court</span><span><?= htmlspecialchars($court['name']) ?></span></div>
                <div class="summary-row"><span class="label">Date</span><span id="tSumDate">–</span></div>
                <div class="summary-row"><span class="label">Selected Slots</span><span id="tSumSlots">–</span></div>
                <div class="summary-row"><span class="label">Duration</span><span id="tSumDur">–</span></div>
                <div class="summary-row"><span class="label">Total Price</span><span id="tSumPrice">–</span></div>
              </div>

              <!-- COACH CHOICE -->
              <div id="timeCoachChoice" class="coach-choice-box" style="display:none;">
                <h4>🎽 Do you need a coach for this session?</h4>
                <div class="choice-grid">
                  <label class="choice-card" id="timeCoachYes" onclick="setTimeCoach('yes')">
                    <input type="radio" name="coach_choice_time" value="yes"/>
                    <div class="choice-icon">🎽</div>
                    <h5>Yes, I need a Coach</h5>
                    <p>Browse coaches after confirming booking</p>
                  </label>
                  <label class="choice-card selected" id="timeCoachNo" onclick="setTimeCoach('no')">
                    <input type="radio" name="coach_choice_time" value="no" checked/>
                    <div class="choice-icon">✅</div>
                    <h5>No, Thanks</h5>
                    <p>Confirm booking without a coach</p>
                  </label>
                </div>
              </div>

              <!-- CONFIRM FORM -->
              <form method="POST" id="timeConfirmForm" style="display:none;margin-top:16px;">
                <input type="hidden" name="confirm_booking" value="1"/>
                <input type="hidden" name="booking_type"  value="Time-Based"/>
                <input type="hidden" name="date"          id="timeHiddenDate"/>
                <input type="hidden" name="selected_slots"id="timeHiddenSlots"/>
                <input type="hidden" name="total_price"   id="timeHiddenPrice"/>
                <input type="hidden" name="package_name"  value=""/>
                <input type="hidden" name="need_coach"    id="timeNeedCoach" value="no"/>
                <div style="display:flex;gap:10px;">
                  <button type="button" class="btn btn-outline" onclick="goBack()">← Back</button>
                  <button type="submit" class="btn btn-success" id="timeConfirmBtn">
                    ✅ Confirm Booking
                  </button>
                </div>
              </form>
            </div>

            <div id="timeBackBtn" style="margin-top:14px;">
              <button class="btn btn-outline" onclick="goBack()">← Back</button>
            </div>
          </div></div>
        </div>

        <!-- ====== PACKAGE-BASED ====== -->
        <div id="formPackage" style="display:none;margin-top:20px;">
          <div class="card"><div class="card-body">
            <div class="card-title">📦 Package-Based Booking — Select Package & Slots</div>

            <?php if (empty($packages)): ?>
              <div class="alert alert-warning">⚠️ No packages available. Please use Time-Based booking.</div>
              <button class="btn btn-outline" onclick="goBack()">← Back</button>
            <?php else: ?>

            <!-- STEP A: SELECT PACKAGE -->
            <div id="pkgStep1">
              <p style="color:var(--text-muted);font-size:13px;margin-bottom:14px;">
                Step A: Choose a package
              </p>
              <div class="pkg-grid">
                <?php foreach ($packages as $idx => $pkg): ?>
                <div class="pkg-card" id="pkgCard<?= $idx ?>"
                     onclick="selectPkg(
                       '<?= htmlspecialchars($pkg['package_type'],ENT_QUOTES) ?>',
                       <?= intval($pkg['duration_hours']) ?>,
                       <?= floatval($pkg['price']) ?>,
                       <?= $idx ?>)">
                  <h4><?= htmlspecialchars($pkg['package_type']) ?></h4>
                  <div class="pkg-price">LKR <?= number_format($pkg['price']) ?></div>
                  <div class="pkg-hours"><?= $pkg['duration_hours'] ?> Hours</div>
                </div>
                <?php endforeach; ?>
              </div>
              <div style="margin-top:14px;">
                <button class="btn btn-outline" onclick="goBack()">← Back</button>
              </div>
            </div>

            <!-- STEP B: SELECT DATE + SEARCH SLOTS -->
            <div id="pkgStep2" style="display:none;">
              <div style="background:var(--primary-soft);border:1.5px solid var(--primary-light);
                          border-radius:var(--r-sm);padding:12px 16px;margin-bottom:16px;font-size:13px;">
                📦 <strong>Selected Package:</strong>
                <span id="pkgSelectedLabel" style="color:var(--primary);font-weight:600;"></span>
                <a href="#" onclick="resetPkg()" style="margin-left:10px;font-size:12px;color:var(--danger);">
                  Change Package
                </a>
              </div>

              <p style="color:var(--text-muted);font-size:13px;margin-bottom:14px;">
                Step B: Select date and search available slots.
                The system will highlight available consecutive slots for your package.
              </p>

              <div class="search-bar">
                <div class="form-group">
                  <label>Select Date</label>
                  <input type="date" id="pkgDate"
                         min="<?= $today ?>" value="<?= $today ?>"/>
                </div>
                <button type="button" class="btn btn-primary"
                        onclick="loadPkgSlots()" style="height:42px;">
                  🔍 Search Available Slots
                </button>
              </div>

              <div class="slot-legend">
                <div class="legend-item">
                  <div class="legend-dot dot-available"></div>
                  <span style="color:#1b5e20;">Available (click to select start)</span>
                </div>
                <div class="legend-item">
                  <div class="legend-dot dot-selected"></div>
                  <span style="color:var(--primary);">Your Package Slots</span>
                </div>
                <div class="legend-item">
                  <div class="legend-dot dot-booked"></div>
                  <span style="color:#b71c1c;">Already Booked</span>
                </div>
              </div>

              <div id="pkgSlotsContainer" style="display:none;">
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:10px;">
                  Click an available (green) slot to set your start time.
                  <span id="pkgHoursNote" style="font-weight:600;color:var(--primary);"></span>
                </p>
                <div class="slots-grid" id="pkgSlotGrid"></div>

                <!-- PACKAGE SUMMARY -->
                <div id="pkgSelSummary" class="summary-box" style="display:none;margin-top:16px;">
                  <h4>📋 Package Summary</h4>
                  <div class="summary-row"><span class="label">Court</span><span><?= htmlspecialchars($court['name']) ?></span></div>
                  <div class="summary-row"><span class="label">Package</span><span id="pSumPkg">–</span></div>
                  <div class="summary-row"><span class="label">Date</span><span id="pSumDate">–</span></div>
                  <div class="summary-row"><span class="label">Time Slots</span><span id="pSumSlots">–</span></div>
                  <div class="summary-row"><span class="label">Duration</span><span id="pSumDur">–</span></div>
                  <div class="summary-row"><span class="label">Total Price</span><span id="pSumPrice">–</span></div>
                </div>

                <!-- COACH CHOICE -->
                <div id="pkgCoachChoice" class="coach-choice-box" style="display:none;">
                  <h4>🎽 Do you need a coach for this session?</h4>
                  <div class="choice-grid">
                    <label class="choice-card" id="pkgCoachYes" onclick="setPkgCoach('yes')">
                      <input type="radio" name="coach_choice_pkg" value="yes"/>
                      <div class="choice-icon">🎽</div>
                      <h5>Yes, I need a Coach</h5>
                      <p>Browse coaches after confirming booking</p>
                    </label>
                    <label class="choice-card selected" id="pkgCoachNo" onclick="setPkgCoach('no')">
                      <input type="radio" name="coach_choice_pkg" value="no" checked/>
                      <div class="choice-icon">✅</div>
                      <h5>No, Thanks</h5>
                      <p>Confirm booking without a coach</p>
                    </label>
                  </div>
                </div>

                <!-- CONFIRM FORM -->
                <form method="POST" id="pkgConfirmForm" style="display:none;margin-top:16px;">
                  <input type="hidden" name="confirm_booking" value="1"/>
                  <input type="hidden" name="booking_type"   value="Package-Based"/>
                  <input type="hidden" name="date"           id="pkgHiddenDate"/>
                  <input type="hidden" name="selected_slots" id="pkgHiddenSlots"/>
                  <input type="hidden" name="total_price"    id="pkgHiddenPrice"/>
                  <input type="hidden" name="package_name"   id="pkgHiddenName"/>
                  <input type="hidden" name="need_coach"     id="pkgNeedCoach" value="no"/>
                  <div style="display:flex;gap:10px;">
                    <button type="button" class="btn btn-outline" onclick="goBack()">← Back</button>
                    <button type="submit" class="btn btn-success" id="pkgConfirmBtn">
                      ✅ Confirm Booking
                    </button>
                  </div>
                </form>
              </div>

           

              <div id="pkgBackBtn" style="margin-top:14px;">
                <button class="btn btn-outline" onclick="goBack()">← Back</button>
              </div>
            </div>

            <?php endif; ?>
          </div></div>
        </div>

      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
const RATE       = <?= floatval($court['rate_per_hour']) ?>;
const COURT_ID   = <?= $court_id ?>;
const ALL_SLOTS  = <?= json_encode(array_column($allSlots, 'slot_time')) ?>;
const SLOT_LABELS= <?= json_encode(array_column($allSlots, 'slot_label', 'slot_time')) ?>;
const TODAY      = '<?= $today ?>';

let takenSlots    = [];
let selectedTime  = [];  // for time-based
let pkgHours      = 0;
let pkgPrice      = 0;
let pkgName       = '';
let pkgSlotStart  = null; // for package-based
let pkgSelSlots   = [];   // for package-based

// ======= STEP 1 =======
function selectType(t) {
    document.getElementById('step1').style.display = 'none';
    document.getElementById(t==='time' ? 'formTime' : 'formPackage').style.display = 'block';
}

function goBack() {
    document.getElementById('step1').style.display       = 'block';
    document.getElementById('formTime').style.display    = 'none';
    document.getElementById('formPackage').style.display = 'none';
    selectedTime = []; pkgHours = 0; pkgPrice = 0;
    pkgSlotStart = null; pkgSelSlots = [];
}

// ======= FETCH TAKEN SLOTS =======
async function fetchTaken(date) {
    const res  = await fetch(`/booking.php?court_id=${COURT_ID}&ajax_slots=1&date=${date}`);
    const data = await res.json();
    return data.taken || [];
}

// ======= FORMAT SLOT LABEL =======
function fmtSlot(t) {
    if (SLOT_LABELS[t]) return SLOT_LABELS[t].replace(/\?+/g, '-');
    const h = parseInt(t.split(':')[0]);
    const ap1 = h >= 12 ? 'PM' : 'AM';
    const h12  = h > 12 ? h-12 : (h===0?12:h);
    const h2   = h+1;
    const ap2  = h2 >= 12 ? 'PM' : 'AM';
    const h212 = h2 > 12 ? h2-12 : (h2===0?12:h2);
    return `${h12}:00 ${ap1} - ${h212}:00 ${ap2}`;
}

// ======= TIME-BASED =======
async function loadTimeSlots() {
    const date = document.getElementById('timeDate').value;
    if (!date) { alert('Please select a date.'); return; }
    takenSlots  = await fetchTaken(date);
    selectedTime = [];
    renderTimeSlots(date);
    document.getElementById('timeSlotsContainer').style.display = 'block';
    document.getElementById('timeBackBtn').style.display        = 'none';
    updateTimeSummary(date);
}

function renderTimeSlots(date) {
    const grid = document.getElementById('timeSlotGrid');
    grid.innerHTML = '';
    ALL_SLOTS.forEach(slot => {
        const btn  = document.createElement('button');
        btn.type   = 'button';
        btn.setAttribute('data-slot', slot);
        const isTaken = takenSlots.includes(slot);
        if (isTaken) {
            btn.className   = 'slot-btn booked';
            btn.disabled    = true;
            btn.innerHTML   = `<strong>${fmtSlot(slot)}</strong><br/><small>🔴 Booked</small>`;
        } else {
            const isSel = selectedTime.includes(slot);
            btn.className   = isSel ? 'slot-btn selected' : 'slot-btn available';
            btn.innerHTML   = `<strong>${fmtSlot(slot)}</strong><br/><small>${isSel?'✅ Selected':'🟢 Available'}</small>`;
            btn.onclick     = () => toggleTimeSlot(slot, date);
        }
        grid.appendChild(btn);
    });
}

function toggleTimeSlot(slot, date) {
    const idx = selectedTime.indexOf(slot);
    if (idx === -1) {
        selectedTime.push(slot);
    } else {
        selectedTime.splice(idx, 1);
    }
    renderTimeSlots(date);
    updateTimeSummary(date);
}

function updateTimeSummary(date) {
    if (selectedTime.length === 0) {
        document.getElementById('timeSelSummary').style.display  = 'none';
        document.getElementById('timeCoachChoice').style.display = 'none';
        document.getElementById('timeConfirmForm').style.display = 'none';
        return;
    }
    const sorted = [...selectedTime].sort();
    const price  = RATE * selectedTime.length;
    const slotHtml = sorted.map(s => `<span class="slot-tag">${fmtSlot(s)}</span>`).join(' ');
    document.getElementById('tSumDate').textContent     = date;
    document.getElementById('tSumSlots').innerHTML      = slotHtml;
    document.getElementById('tSumDur').textContent      = selectedTime.length + ' hour' + (selectedTime.length!==1?'s':'');
    document.getElementById('tSumPrice').textContent    = 'LKR ' + price.toLocaleString();
    document.getElementById('timeSelSummary').style.display  = 'block';
    document.getElementById('timeCoachChoice').style.display = 'block';
    document.getElementById('timeConfirmForm').style.display = 'block';
    // Fill hidden fields
    document.getElementById('timeHiddenDate').value   = date;
    document.getElementById('timeHiddenSlots').value  = JSON.stringify(sorted);
    document.getElementById('timeHiddenPrice').value  = price;
}

function setTimeCoach(val) {
    document.getElementById('timeNeedCoach').value = val;
    document.getElementById('timeCoachYes').classList.toggle('selected', val==='yes');
    document.getElementById('timeCoachNo').classList.toggle('selected',  val==='no');
    const btn = document.getElementById('timeConfirmBtn');
    btn.textContent = val==='yes' ? '🎽 Next: Choose a Coach' : '✅ Confirm Booking';
}

// ======= PACKAGE-BASED =======
function selectPkg(name, hours, price, idx) {
    document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('pkgCard'+idx).classList.add('selected');
    pkgHours = hours; pkgPrice = price; pkgName = name;
    document.getElementById('pkgSelectedLabel').textContent =
        `${name} — ${hours} Hours — LKR ${price.toLocaleString()}`;
    document.getElementById('pkgHoursNote').textContent =
        `(${hours} consecutive slots will be selected)`;
    document.getElementById('pkgHiddenName').value  = name;
    document.getElementById('pkgHiddenPrice').value = price;
    document.getElementById('pkgStep1').style.display = 'none';
    document.getElementById('pkgStep2').style.display = 'block';
}

function resetPkg() {
    document.getElementById('pkgStep1').style.display = 'block';
    document.getElementById('pkgStep2').style.display = 'none';
    document.getElementById('pkgSlotsContainer').style.display = 'none';
    pkgSlotStart = null; pkgSelSlots = [];
}

async function loadPkgSlots() {
    const date = document.getElementById('pkgDate').value;
    if (!date) { alert('Please select a date.'); return; }
    takenSlots   = await fetchTaken(date);
    pkgSlotStart = null;
    pkgSelSlots  = [];
    renderPkgSlots(date);
    document.getElementById('pkgSlotsContainer').style.display = 'block';
    document.getElementById('pkgBackBtn').style.display        = 'none';
    updatePkgSummary(date);
}

function renderPkgSlots(date) {
    const grid = document.getElementById('pkgSlotGrid');
    grid.innerHTML = '';
    ALL_SLOTS.forEach(slot => {
        const btn   = document.createElement('button');
        btn.type    = 'button';
        btn.setAttribute('data-slot', slot);
        const isTaken = takenSlots.includes(slot);
        const isSel   = pkgSelSlots.includes(slot);

        if (isTaken) {
            btn.className   = 'slot-btn booked';
            btn.disabled    = true;
            btn.innerHTML   = `<strong>${fmtSlot(slot)}</strong><br/><small>🔴 Booked</small>`;
        } else if (isSel) {
            btn.className   = 'slot-btn selected';
            btn.innerHTML   = `<strong>${fmtSlot(slot)}</strong><br/><small>✅ Package Slot</small>`;
            btn.onclick     = () => clearPkgSelection(date);
        } else {
            // Check if this slot can be a valid start (enough consecutive free slots)
            const canStart  = canBeStart(slot);
            if (canStart) {
                btn.className = 'slot-btn available';
                btn.innerHTML = `<strong>${fmtSlot(slot)}</strong><br/><small>🟢 Click to start here</small>`;
                btn.onclick   = () => selectPkgStart(slot, date);
            } else {
                btn.className = 'slot-btn inactive';
                btn.innerHTML = `<strong>${fmtSlot(slot)}</strong><br/><small>Not enough consecutive slots</small>`;
            }
        }
        grid.appendChild(btn);
    });
}

function canBeStart(startSlot) {
    const startIdx = ALL_SLOTS.indexOf(startSlot);
    if (startIdx === -1) return false;
    if (startIdx + pkgHours > ALL_SLOTS.length) return false;
    for (let i = 0; i < pkgHours; i++) {
        if (takenSlots.includes(ALL_SLOTS[startIdx + i])) return false;
    }
    return true;
}

function selectPkgStart(startSlot, date) {
    const startIdx = ALL_SLOTS.indexOf(startSlot);
    pkgSelSlots    = ALL_SLOTS.slice(startIdx, startIdx + pkgHours);
    pkgSlotStart   = startSlot;
    renderPkgSlots(date);
    updatePkgSummary(date);
}

function clearPkgSelection(date) {
    pkgSelSlots  = [];
    pkgSlotStart = null;
    renderPkgSlots(date);
    updatePkgSummary(date);
}

function updatePkgSummary(date) {
    if (pkgSelSlots.length === 0) {
        document.getElementById('pkgSelSummary').style.display  = 'none';
        document.getElementById('pkgCoachChoice').style.display = 'none';
        document.getElementById('pkgConfirmForm').style.display = 'none';
        return;
    }
    const slotHtml = pkgSelSlots.map(s => `<span class="slot-tag">${fmtSlot(s)}</span>`).join(' ');
    document.getElementById('pSumPkg').textContent   = pkgName + ' (' + pkgHours + ' hours)';
    document.getElementById('pSumDate').textContent  = date;
    document.getElementById('pSumSlots').innerHTML   = slotHtml;
    document.getElementById('pSumDur').textContent   = pkgHours + ' hours';
    document.getElementById('pSumPrice').textContent = 'LKR ' + pkgPrice.toLocaleString();
    document.getElementById('pkgSelSummary').style.display  = 'block';
    document.getElementById('pkgCoachChoice').style.display = 'block';
    document.getElementById('pkgConfirmForm').style.display = 'block';
    document.getElementById('pkgHiddenDate').value   = date;
    document.getElementById('pkgHiddenSlots').value  = JSON.stringify(pkgSelSlots);
}

function setPkgCoach(val) {
    document.getElementById('pkgNeedCoach').value = val;
    document.getElementById('pkgCoachYes').classList.toggle('selected', val==='yes');
    document.getElementById('pkgCoachNo').classList.toggle('selected',  val==='no');
    const btn = document.getElementById('pkgConfirmBtn');
    btn.textContent = val==='yes' ? '🎽 Next: Choose a Coach' : '✅ Confirm Booking';
}
</script>
</body>
</html>