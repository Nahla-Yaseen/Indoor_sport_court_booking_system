<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$success = $error = '';

// Block coach slot
if (isset($_POST['block_coach_slot'])) {
    $coachId  = trim($_POST['coach_id']);
    $date     = $_POST['coach_date'];
    $slotTime = $_POST['coach_slot_time'];
    $reason   = trim($_POST['coach_reason'] ?? 'Admin block');
    if (!$coachId || !$date || !$slotTime) {
        $error = 'Please fill all fields.';
    } else {
        $chk = $pdo->prepare("
            SELECT id FROM blocked_time_slots
            WHERE coach_id=? AND date=? AND slot_time=? AND block_type='coach'
        ");
        $chk->execute([$coachId, $date, $slotTime]);
        if ($chk->fetch()) {
            $error = 'This slot is already blocked for this coach on that date.';
        } else {
            $pdo->prepare("
                INSERT INTO blocked_time_slots (coach_id,block_type,date,slot_time,reason)
                VALUES (?,'coach',?,?,?)
            ")->execute([$coachId, $date, $slotTime, $reason]);
            $success = 'Coach slot blocked!';
        }
    }
}

// Unblock coach slot
if (isset($_GET['unblock'])) {
    $pdo->prepare("DELETE FROM blocked_time_slots WHERE id=? AND block_type='coach'")
        ->execute([intval($_GET['unblock'])]);
    $coachId = $_GET['coach_id'] ?? '';
    $date    = $_GET['view_date'] ?? date('Y-m-d');
    header("Location: admin-availability.php?coach_id=".urlencode($coachId)."&view_date=$date");
    exit();
}

// Load all coaches grouped by sport
$coaches = $pdo->query("SELECT * FROM coaches WHERE status='active' ORDER BY sport,name")->fetchAll();
$grouped = [];
foreach ($coaches as $c) { $grouped[$c['sport']][] = $c; }

$selectedCoachId = $_GET['coach_id'] ?? ($coaches[0]['id'] ?? '');
$selectedDate    = $_GET['view_date'] ?? date('Y-m-d');
$today           = date('Y-m-d');

// Find selected coach details
$selectedCoach = null;
foreach ($coaches as $c) {
    if ($c['id'] === $selectedCoachId) { $selectedCoach = $c; break; }
}

// Get all time slots (use first court's slots as reference)
$allSlots = $pdo->query("
    SELECT DISTINCT slot_time, slot_label
    FROM time_slots WHERE is_active=1 ORDER BY slot_time ASC
")->fetchAll();

// Get coach bookings on selected date (booked slots)
$bookedOnDate = [];
if ($selectedCoachId && $selectedDate) {
    $bk = $pdo->prepare("
        SELECT slot_time FROM coach_bookings
        WHERE coach_id=? AND date=? AND status='Confirmed'
        AND start_time IS NOT NULL
    ");
    // Coach bookings store start_time not slot_time, so derive slot from start_time
    $bk2 = $pdo->prepare("
        SELECT TIME_FORMAT(start_time, '%H:00') AS slot_time
        FROM coach_bookings
        WHERE coach_id=? AND date=? AND status='Confirmed'
    ");
    $bk2->execute([$selectedCoachId, $selectedDate]);
    $bookedOnDate = $bk2->fetchAll(PDO::FETCH_COLUMN);
}

// Get admin-blocked slots for this coach on selected date
$adminBlocked = [];
if ($selectedCoachId && $selectedDate) {
    $ab = $pdo->prepare("
        SELECT * FROM blocked_time_slots
        WHERE coach_id=? AND date=? AND block_type='coach'
        ORDER BY slot_time ASC
    ");
    $ab->execute([$selectedCoachId, $selectedDate]);
    $adminBlocked = $ab->fetchAll();
}

$blockedTimes = array_column($adminBlocked, 'slot_time');

// Stats
$freeCount    = 0;
$bookedCount  = count($bookedOnDate);
$blockedCount = count($adminBlocked);
foreach ($allSlots as $sl) {
    if (!in_array($sl['slot_time'], $bookedOnDate) && !in_array($sl['slot_time'], $blockedTimes)) {
        $freeCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Availability – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    .sport-section { margin-bottom:20px; }
    .sport-label   {
      font-size:11px; font-weight:700; text-transform:uppercase;
      letter-spacing:0.8px; color:rgba(255,255,255,0.5);
      padding:10px 18px 4px;
    }
    .coach-sidebar {
      width:220px; min-width:220px;
      background:var(--primary);
      display:flex; flex-direction:column;
      height:100%;
    }
    .cs-brand {
      padding:18px; color:white;
      font-size:15px; font-weight:700;
      border-bottom:1px solid rgba(255,255,255,0.15);
      background: var(--primary-mid);
    }
    .cs-brand small { display:block; font-size:11px; font-weight:400; opacity:0.65; margin-top:3px; }
    .cs-link {
      display:flex; align-items:center; gap:8px;
      padding:10px 18px; color:rgba(255,255,255,0.75);
      font-size:12px; font-weight:500;
      text-decoration:none; transition:all 0.18s;
      border-left:3px solid transparent;
    }
    .cs-link:hover { background:rgba(255,255,255,0.1); color:white; }
    .cs-link.active { background:rgba(255,255,255,0.16); color:white; border-left-color:var(--accent); font-weight:600; }
    .cs-icon { font-size:16px; }
    .cs-id   { font-size:10px; font-family:monospace; opacity:0.6; margin-top:1px; }

    .date-slot-grid {
      display:grid;
      grid-template-columns:repeat(auto-fill, minmax(200px,1fr));
      gap:12px; margin-bottom:20px;
    }
    .dsc {
      border:2px solid; border-radius:var(--r-md);
      padding:12px 14px; display:flex;
      align-items:center; justify-content:space-between;
    }
    .dsc-free    { border-color:#43a047; background:#f1f8f1; }
    .dsc-booked  { border-color:#e53935; background:#ffebee; }
    .dsc-blocked { border-color:#ff8f00; background:#fff3e0; }
    .dsc-label   { font-size:12px; font-weight:600; }
    .dsc-free    .dsc-label { color:#1b5e20; }
    .dsc-booked  .dsc-label { color:#b71c1c; }
    .dsc-blocked .dsc-label { color:#e65100; }

    .stats-row { display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
    .stat-pill { padding:7px 16px; border-radius:20px; font-size:12px; font-weight:600; display:flex; align-items:center; gap:5px; }
    .sp-free    { background:#e8f5e9; color:#1b5e20; }
    .sp-booked  { background:#ffebee; color:#b71c1c; }
    .sp-blocked { background:#fff3e0; color:#e65100; }

    .block-form-box {
      background:var(--primary-pale); border:1.5px solid var(--primary-light);
      border-radius:var(--r-md); padding:18px 20px; margin-top:16px;
    }
    .block-form-box h4 { font-size:14px; font-weight:600; color:var(--primary); margin-bottom:12px; }

    /* Outer layout with coach sidebar */
    .av-layout { display:flex; height:calc(100vh - 64px); }
    .av-coach-list {
      width:230px; min-width:230px;
      background:var(--primary);
      overflow-y:auto; display:flex; flex-direction:column;
    }
    .av-main { flex:1; overflow-y:auto; background:var(--primary-pale); }
  </style>
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

  <!-- INNER AVAILABILITY LAYOUT -->
  <div class="admin-main" style="padding:0;display:flex;flex-direction:column;">

    <div class="page-header">
      <h1>📅 Manage Availability</h1>
      <p>Select a coach and date to view and manage their time slot availability</p>
    </div>

    <div style="display:flex;flex:1;min-height:0;">

      <!-- COACH LIST SIDEBAR -->
      <div class="av-coach-list">
        <div style="padding:14px 16px;color:rgba(255,255,255,0.5);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;border-bottom:1px solid rgba(255,255,255,0.1);">
          Select Coach
        </div>
        <?php foreach ($grouped as $sport => $sportCoaches):
          $sportIcon = $sport==='Badminton'?'🏸':($sport==='Futsal'?'⚽':($sport==='Cricket'?'🏏':($sport==='Tennis'?'🎾':'🏅')));
        ?>
          <div style="padding:10px 16px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.7px;color:rgba(255,255,255,0.4);">
            <?= $sportIcon ?> <?= htmlspecialchars($sport) ?>
          </div>
          <?php foreach ($sportCoaches as $coach):
            $isActive = ($coach['id'] === $selectedCoachId);
          ?>
          <a href="?coach_id=<?= urlencode($coach['id']) ?>&view_date=<?= $selectedDate ?>"
             class="cs-link <?= $isActive ? 'active' : '' ?>">
            <span class="cs-icon">👤</span>
            <div>
              <div><?= htmlspecialchars($coach['name']) ?></div>
              <div class="cs-id"><?= htmlspecialchars($coach['id']) ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>

      <!-- MAIN CONTENT -->
      <div class="av-main" style="padding:24px 28px;">

        <?php if ($error):   ?><div class="alert alert-danger"  style="margin-bottom:16px;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px;">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

        <?php if ($selectedCoach): ?>

        <!-- COACH HEADER -->
        <div style="background:white;border-radius:var(--r-md);padding:16px 20px;
                    margin-bottom:20px;border:1px solid var(--gray-200);
                    display:flex;align-items:center;gap:16px;box-shadow:var(--shadow-sm);">
          <div style="width:48px;height:48px;border-radius:50%;background:var(--primary-soft);
                      display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">
            👤
          </div>
          <div>
            <div style="font-size:16px;font-weight:700;color:var(--primary);">
              <?= htmlspecialchars($selectedCoach['name']) ?>
            </div>
            <div style="font-size:12px;color:var(--text-muted);">
              <span style="font-family:monospace;font-weight:600;"><?= htmlspecialchars($selectedCoach['id']) ?></span>
              &nbsp;·&nbsp; <?= htmlspecialchars($selectedCoach['sport']) ?>
              &nbsp;·&nbsp; <?= $selectedCoach['experience_years'] ?> yrs
              &nbsp;·&nbsp; LKR <?= number_format($selectedCoach['hourly_rate']) ?>/hr
            </div>
          </div>
        </div>

        <!-- DATE PICKER -->
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;margin-bottom:20px;flex-wrap:wrap;">
          <input type="hidden" name="coach_id" value="<?= htmlspecialchars($selectedCoachId) ?>"/>
          <div class="form-group" style="margin-bottom:0;">
            <label style="font-size:13px;font-weight:600;">Select Date</label>
            <input type="date" name="view_date"
                   value="<?= htmlspecialchars($selectedDate) ?>"
                   min="<?= $today ?>"/>
          </div>
          <button type="submit" class="btn btn-primary" style="height:42px;">
            🔍 View Slots
          </button>
        </form>

        <!-- STATS -->
        <div class="stats-row">
          <div class="stat-pill sp-free">🟢 Free: <?= $freeCount ?></div>
          <div class="stat-pill sp-booked">🔴 Booked: <?= $bookedCount ?></div>
          <div class="stat-pill sp-blocked">🟠 Blocked: <?= $blockedCount ?></div>
        </div>

        <!-- LEGEND -->
        <div style="display:flex;gap:16px;margin-bottom:14px;font-size:12px;font-weight:600;flex-wrap:wrap;">
          <span style="color:#1b5e20;">🟢 Free</span>
          <span style="color:#b71c1c;">🔴 Coach Session Booked</span>
          <span style="color:#e65100;">🟠 Admin Blocked</span>
        </div>

        <!-- SLOT GRID -->
        <?php if (empty($allSlots)): ?>
          <div class="alert alert-warning">No time slots available.</div>
        <?php else: ?>
        <div class="date-slot-grid">
          <?php foreach ($allSlots as $slot):
            $isBooked  = in_array($slot['slot_time'], $bookedOnDate);
            $isBlocked = in_array($slot['slot_time'], $blockedTimes);
            $class     = $isBooked ? 'dsc-booked' : ($isBlocked ? 'dsc-blocked' : 'dsc-free');
            $icon      = $isBooked ? '🔴' : ($isBlocked ? '🟠' : '🟢');
            $status    = $isBooked ? 'Booked' : ($isBlocked ? 'Admin Blocked' : 'Free');
          ?>
          <div class="dsc <?= $class ?>">
            <div>
              <div style="font-size:10px;margin-bottom:3px;"><?= $icon ?> <?= $status ?></div>
              <div class="dsc-label"><?= htmlspecialchars($slot['slot_label']) ?></div>
            </div>
            <?php if ($isBlocked):
              $bId = null;
              foreach ($adminBlocked as $ab) {
                  if ($ab['slot_time'] === $slot['slot_time']) { $bId = $ab['id']; break; }
              }
            ?>
              <a href="?coach_id=<?= urlencode($selectedCoachId) ?>&view_date=<?= $selectedDate ?>&unblock=<?= $bId ?>"
                 style="font-size:10px;font-weight:700;color:#e65100;text-decoration:none;
                        background:white;border:1px solid #e65100;
                        padding:3px 8px;border-radius:8px;"
                 onclick="return confirm('Unblock this slot?')">
                🔓 Unblock
              </a>
            <?php elseif (!$isBooked): ?>
              <button type="button"
                      style="font-size:10px;padding:3px 8px;border-radius:8px;
                             border:1px solid #e65100;background:white;
                             color:#e65100;cursor:pointer;font-family:'Poppins',sans-serif;"
                      onclick="fillBlock('<?= $slot['slot_time'] ?>')">
                🚫 Block
              </button>
            <?php else: ?>
              <span style="font-size:10px;color:#b71c1c;">Cannot change</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- BLOCK FORM -->
        <div class="block-form-box">
          <h4>🚫 Block a Slot for <?= htmlspecialchars($selectedCoach['name']) ?> on <?= htmlspecialchars($selectedDate) ?></h4>
          <form method="POST">
            <input type="hidden" name="block_coach_slot" value="1"/>
            <input type="hidden" name="coach_id"         value="<?= htmlspecialchars($selectedCoachId) ?>"/>
            <input type="hidden" name="coach_date"       value="<?= htmlspecialchars($selectedDate) ?>"/>
            <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:14px;align-items:end;">
              <div class="form-group" style="margin-bottom:0;">
                <label style="font-size:12px;">Time Slot</label>
                <select name="coach_slot_time" id="blockSlotSelect" required>
                  <option value="">-- Select Slot --</option>
                  <?php foreach ($allSlots as $slot):
                    $isTaken = in_array($slot['slot_time'], $bookedOnDate)
                            || in_array($slot['slot_time'], $blockedTimes);
                    if ($isTaken) continue;
                  ?>
                    <option value="<?= $slot['slot_time'] ?>">
                      <?= htmlspecialchars($slot['slot_label']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label style="font-size:12px;">Reason</label>
                <input type="text" name="coach_reason" id="blockReason"
                       placeholder="e.g. Unavailable" required/>
              </div>
              <div>
                <button type="submit" class="btn btn-danger" style="height:42px;">
                  🚫 Block
                </button>
              </div>
            </div>
          </form>
        </div>

        <!-- BLOCKED LIST -->
        <?php if (!empty($adminBlocked)): ?>
        <div class="card" style="margin-top:20px;">
          <div class="card-body">
            <div class="card-title" style="font-size:14px;">
              🚫 Admin Blocked Slots for <?= htmlspecialchars($selectedCoach['name']) ?> on <?= htmlspecialchars($selectedDate) ?>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr><th>Slot</th><th>Reason</th><th>Blocked At</th><th>Action</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($adminBlocked as $ab): ?>
                  <tr>
                    <td style="font-family:monospace;font-size:12px;">
                      <?= htmlspecialchars($ab['slot_time']) ?>
                    </td>
                    <td><?= htmlspecialchars($ab['reason'] ?? '–') ?></td>
                    <td><?= date('d M Y H:i', strtotime($ab['created_at'])) ?></td>
                    <td>
                      <a href="?coach_id=<?= urlencode($selectedCoachId) ?>&view_date=<?= $selectedDate ?>&unblock=<?= $ab['id'] ?>"
                         class="btn btn-success btn-sm"
                         onclick="return confirm('Unblock?')">
                        🔓 Unblock
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php endif; // empty allSlots ?>

        <?php else: ?>
        <div class="alert alert-info">Please select a coach from the left panel.</div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
function fillBlock(slotTime) {
    document.getElementById('blockSlotSelect').value = slotTime;
    document.getElementById('blockReason').focus();
}
</script>
</body>
</html>