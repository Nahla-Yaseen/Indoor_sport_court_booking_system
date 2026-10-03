<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$success = $error = '';

// Block a specific date+slot
if (isset($_POST['block_date_slot'])) {
    $courtId  = intval($_POST['block_court_id']);
    $date     = $_POST['block_date'];
    $slotTime = $_POST['block_slot_time'];
    $reason   = trim($_POST['block_reason'] ?? 'Admin block');
    if (!$courtId || !$date || !$slotTime) {
        $error = 'Please fill all fields.';
    } else {
        $chk = $pdo->prepare("
            SELECT id FROM blocked_time_slots
            WHERE court_id=? AND date=? AND slot_time=? AND block_type='court'
        ");
        $chk->execute([$courtId, $date, $slotTime]);
        if ($chk->fetch()) {
            $error = 'This slot is already blocked for that date.';
        } else {
            $pdo->prepare("
                INSERT INTO blocked_time_slots (court_id,block_type,date,slot_time,reason)
                VALUES (?,'court',?,?,?)
            ")->execute([$courtId, $date, $slotTime, $reason]);
            $success = 'Slot blocked successfully!';
        }
    }
}

// Unblock a date slot
if (isset($_GET['unblock'])) {
    $pdo->prepare("DELETE FROM blocked_time_slots WHERE id=?")
        ->execute([intval($_GET['unblock'])]);
    $cid  = intval($_GET['court_id'] ?? 0);
    $date = $_GET['view_date'] ?? date('Y-m-d');
    header("Location: admin-timeslots.php?court_id=$cid&view_date=$date");
    exit();
}

$courts        = $pdo->query("SELECT * FROM courts ORDER BY name")->fetchAll();
$selectedCourt = intval($_GET['court_id'] ?? ($courts[0]['id'] ?? 0));
$selectedDate  = $_GET['view_date'] ?? date('Y-m-d');

// Get active slots for selected court
$slots = [];
if ($selectedCourt) {
    $s = $pdo->prepare("SELECT * FROM time_slots WHERE court_id=? AND is_active=1 ORDER BY slot_time ASC");
    $s->execute([$selectedCourt]);
    $slots = $s->fetchAll();
}

// Get booked slots on selected date
$bookedOnDate = [];
if ($selectedCourt && $selectedDate) {
    $bk = $pdo->prepare("SELECT slot_time FROM booked_slots WHERE court_id=? AND date=?");
    $bk->execute([$selectedCourt, $selectedDate]);
    $bookedOnDate = $bk->fetchAll(PDO::FETCH_COLUMN);
}

// Get admin-blocked slots on selected date
$adminBlocked = [];
if ($selectedCourt && $selectedDate) {
    $ab = $pdo->prepare("
        SELECT * FROM blocked_time_slots
        WHERE court_id=? AND date=? AND block_type='court'
        ORDER BY slot_time ASC
    ");
    $ab->execute([$selectedCourt, $selectedDate]);
    $adminBlocked = $ab->fetchAll();
}

// Also check if full date is blocked
$fullBlocked = false;
if ($selectedCourt && $selectedDate) {
    $fb = $pdo->prepare("
        SELECT id FROM blocked_slots
        WHERE (court_id=? OR court_id IS NULL) AND date=?
    ");
    $fb->execute([$selectedCourt, $selectedDate]);
    $fullBlocked = (bool)$fb->fetch();
}

$blockedTimes = array_column($adminBlocked, 'slot_time');

// Count stats
$freeCount    = 0;
$bookedCount  = count($bookedOnDate);
$blockedCount = count($adminBlocked);
foreach ($slots as $sl) {
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
  <title>Time Slots – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    .court-tabs { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
    .court-tab  {
      padding:8px 20px; border-radius:20px;
      border:1.5px solid var(--gray-200);
      background:white; font-size:13px; font-weight:500;
      cursor:pointer; text-decoration:none;
      color:var(--text-muted); transition:all 0.2s;
    }
    .court-tab:hover, .court-tab.active {
      background:var(--primary-mid); color:white;
      border-color:var(--primary-mid);
    }

    .date-slot-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 12px;
      margin-bottom: 20px;
    }
    .dsc {
      border: 2px solid;
      border-radius: var(--r-md);
      padding: 12px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .dsc-free    { border-color:#43a047; background:#f1f8f1; }
    .dsc-booked  { border-color:#e53935; background:#ffebee; }
    .dsc-blocked { border-color:#ff8f00; background:#fff3e0; }
    .dsc-label   { font-size:12px; font-weight:600; }
    .dsc-free    .dsc-label { color:#1b5e20; }
    .dsc-booked  .dsc-label { color:#b71c1c; }
    .dsc-blocked .dsc-label { color:#e65100; }

    .stats-row {
      display:flex; gap:14px; margin-bottom:20px; flex-wrap:wrap;
    }
    .stat-pill {
      padding:8px 18px; border-radius:20px;
      font-size:13px; font-weight:600;
      display:flex; align-items:center; gap:6px;
    }
    .sp-free    { background:#e8f5e9; color:#1b5e20; }
    .sp-booked  { background:#ffebee; color:#b71c1c; }
    .sp-blocked { background:#fff3e0; color:#e65100; }

    .block-form-box {
      background:var(--primary-pale);
      border: 1.5px solid var(--primary-light);
      border-radius:var(--r-md);
      padding:18px 20px;
      margin-top:20px;
    }
    .block-form-box h4 {
      font-size:14px; font-weight:600;
      color:var(--primary); margin-bottom:14px;
    }
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

  <div class="admin-main">
    <div class="page-header">
      <h1>🕐 Time Slots</h1>
      <p>View and manage court time slot availability by date</p>
    </div>

    <div style="padding:28px 30px;">

      <?php if ($error):   ?><div class="alert alert-danger"  style="margin-bottom:16px;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px;">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

      <!-- COURT TABS -->
      <div class="court-tabs">
        <?php foreach ($courts as $c): ?>
          <a href="?court_id=<?= $c['id'] ?>&view_date=<?= $selectedDate ?>"
             class="court-tab <?= $selectedCourt===$c['id']?'active':'' ?>">
            🏸 <?= htmlspecialchars($c['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- DATE PICKER -->
      <form method="GET" style="display:flex;gap:12px;align-items:flex-end;margin-bottom:20px;flex-wrap:wrap;">
        <input type="hidden" name="court_id" value="<?= $selectedCourt ?>"/>
        <div class="form-group" style="margin-bottom:0;">
          <label style="font-size:13px;font-weight:600;">Select Date</label>
          <input type="date" name="view_date"
                 value="<?= htmlspecialchars($selectedDate) ?>"/>
        </div>
        <button type="submit" class="btn btn-primary" style="height:42px;">
          🔍 View Slots
        </button>
      </form>

      <?php if ($selectedCourt): ?>

        <?php if ($fullBlocked): ?>
        <div class="alert alert-danger" style="margin-bottom:16px;">
          🔒 This entire date is blocked for this court.
          <a href="admin-availability.php" style="color:var(--danger);font-weight:600;margin-left:8px;">
            Manage in Availability →
          </a>
        </div>
        <?php endif; ?>

        <!-- STATS ROW -->
        <div class="stats-row">
          <div class="stat-pill sp-free">🟢 Free: <?= $freeCount ?></div>
          <div class="stat-pill sp-booked">🔴 Booked: <?= $bookedCount ?></div>
          <div class="stat-pill sp-blocked">🟠 Admin Blocked: <?= $blockedCount ?></div>
        </div>

        <!-- LEGEND -->
        <div style="display:flex;gap:16px;margin-bottom:16px;font-size:12px;font-weight:600;flex-wrap:wrap;">
          <span style="color:#1b5e20;">🟢 Free — available for booking</span>
          <span style="color:#b71c1c;">🔴 Booked — user has booking</span>
          <span style="color:#e65100;">🟠 Admin Blocked — manually blocked</span>
        </div>

        <!-- SLOT GRID -->
        <?php if (empty($slots)): ?>
          <div class="alert alert-warning">No time slots configured for this court.</div>
        <?php else: ?>
        <div class="date-slot-grid">
          <?php foreach ($slots as $slot):
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
              <a href="?court_id=<?= $selectedCourt ?>&view_date=<?= $selectedDate ?>&unblock=<?= $bId ?>"
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
                             color:#e65100;cursor:pointer;
                             font-family:'Poppins',sans-serif;"
                      onclick="fillBlock('<?= $slot['slot_time'] ?>')">
                🚫 Block
              </button>
            <?php else: ?>
              <span style="font-size:10px;color:#b71c1c;">Cannot unbook</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- BLOCK FORM -->
        <div class="block-form-box">
          <h4>🚫 Block a Slot on <?= htmlspecialchars($selectedDate) ?></h4>
          <form method="POST">
            <input type="hidden" name="block_date_slot"  value="1"/>
            <input type="hidden" name="block_court_id"   value="<?= $selectedCourt ?>"/>
            <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:14px;align-items:end;">
              <div class="form-group" style="margin-bottom:0;">
                <label style="font-size:12px;">Time Slot</label>
                <select name="block_slot_time" id="blockSlotSelect" required>
                  <option value="">-- Select Slot --</option>
                  <?php foreach ($slots as $slot):
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
                <input type="text" name="block_reason" id="blockReason"
                       placeholder="e.g. Maintenance" required/>
              </div>
              <div>
                <button type="submit" class="btn btn-danger" style="height:42px;">
                  🚫 Block
                </button>
              </div>
            </div>
            <input type="hidden" name="block_date" value="<?= htmlspecialchars($selectedDate) ?>"/>
          </form>
        </div>

        <!-- BLOCKED SLOTS LIST -->
        <?php if (!empty($adminBlocked)): ?>
        <div class="card" style="margin-top:20px;">
          <div class="card-body">
            <div class="card-title" style="font-size:14px;">
              🚫 Admin Blocked Slots on <?= htmlspecialchars($selectedDate) ?>
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
                      <a href="?court_id=<?= $selectedCourt ?>&view_date=<?= $selectedDate ?>&unblock=<?= $ab['id'] ?>"
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

        <?php endif; ?>
      <?php endif; ?>

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