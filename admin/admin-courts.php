<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$error = $success = '';
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/myproject/images/courts/';

if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $name  = trim($_POST['name']);
    $sport = trim($_POST['sport']);
    $rate  = floatval($_POST['rate']);
    if (!$name || !$sport || !$rate) {
        $error = 'Please fill in all fields.';
    } else {
        $imageName = null;
        if (!empty($_FILES['image']['name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $imageName = 'court_'.time().'_'.rand(100,999).'.'.$ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir.$imageName);
            }
        }
        $pdo->prepare("INSERT INTO courts (name,sport,rate_per_hour,image) VALUES (?,?,?,?)")
            ->execute([$name,$sport,$rate,$imageName]);

        // Create default time slots for new court
        $newCourtId = $pdo->lastInsertId();
        $defaultSlots = [
            ['06:00','6:00 AM – 7:00 AM'],['07:00','7:00 AM – 8:00 AM'],
            ['08:00','8:00 AM – 9:00 AM'],['09:00','9:00 AM – 10:00 AM'],
            ['10:00','10:00 AM – 11:00 AM'],['11:00','11:00 AM – 12:00 PM'],
            ['12:00','12:00 PM – 1:00 PM'],['13:00','1:00 PM – 2:00 PM'],
            ['14:00','2:00 PM – 3:00 PM'],['15:00','3:00 PM – 4:00 PM'],
            ['16:00','4:00 PM – 5:00 PM'],['17:00','5:00 PM – 6:00 PM'],
            ['18:00','6:00 PM – 7:00 PM'],['19:00','7:00 PM – 8:00 PM'],
            ['20:00','8:00 PM – 9:00 PM'],['21:00','9:00 PM – 10:00 PM'],
        ];
        $slotStmt = $pdo->prepare("INSERT INTO time_slots (court_id,slot_time,slot_label) VALUES (?,?,?)");
        foreach ($defaultSlots as $s) { $slotStmt->execute([$newCourtId,$s[0],$s[1]]); }

        $success = 'Court added successfully with default time slots!';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $id    = intval($_POST['court_id']);
    $eRow  = $pdo->prepare("SELECT image FROM courts WHERE id=?");
    $eRow->execute([$id]);
    $old   = $eRow->fetch();
    $imageName = $old['image'];
    if (!empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
            if ($imageName && file_exists($uploadDir.$imageName)) unlink($uploadDir.$imageName);
            $imageName = 'court_'.time().'_'.rand(100,999).'.'.$ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir.$imageName);
        }
    }
    $pdo->prepare("UPDATE courts SET name=?,sport=?,rate_per_hour=?,status=?,image=? WHERE id=?")
        ->execute([trim($_POST['name']),trim($_POST['sport']),floatval($_POST['rate']),$_POST['status'],$imageName,$id]);
    $success = 'Court updated successfully!';
}

if (isset($_GET['delete'])) {
    $id  = intval($_GET['delete']);
    $row = $pdo->prepare("SELECT image FROM courts WHERE id=?");
    $row->execute([$id]);
    $c = $row->fetch();
    if ($c && $c['image'] && file_exists($uploadDir.$c['image'])) unlink($uploadDir.$c['image']);
    $pdo->prepare("DELETE FROM courts WHERE id=?")->execute([$id]);
    header("Location: admin-courts.php"); exit();
}

$courts = $pdo->query("SELECT * FROM courts ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Courts – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    .court-thumb { width:52px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--gray-200); }
    .img-preview { width:100%;height:120px;object-fit:cover;border-radius:var(--r-sm);margin-top:8px;display:none;border:1.5px solid var(--gray-200); }
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
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="admin-main">
    <div class="page-header">
      <h1>🏸 Manage Courts</h1>
      <p>Add, edit or remove indoor courts</p>
    </div>
    <div style="padding:28px 30px;">

      <!-- ADD FORM -->
      <div class="card" style="margin-bottom:28px;">
        <div class="card-body">
          <div class="card-title">➕ Add New Court</div>
          <?php if ($error):   ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
          <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add"/>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;align-items:end;">
              <div class="form-group" style="margin-bottom:0;">
                <label>Court Name</label>
                <input type="text" name="name" placeholder="e.g. Badminton Court A" required/>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label>Sport Type</label>
                <input type="text" name="sport" placeholder="e.g. Badminton" required/>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label>Rate (LKR/hour)</label>
                <input type="number" name="rate" placeholder="600" min="1" required/>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label>Court Image <small style="color:var(--text-muted);">(optional)</small></label>
                <input type="file" name="image" accept="image/*" onchange="previewImg(this,'addPreview')"/>
              </div>
            </div>
            <img id="addPreview" class="img-preview" alt="Preview"/>
            <div style="margin-top:16px;">
              <button type="submit" class="btn btn-primary">➕ Add Court</button>
            </div>
          </form>
        </div>
      </div>

      <!-- COURTS TABLE -->
      <div class="card">
        <div class="card-body">
          <div class="card-title">All Courts (<?= count($courts) ?>)</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Image</th><th>#</th><th>Court Name</th><th>Sport</th><th>Rate/Hour</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if (empty($courts)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px;">No courts found.</td></tr>
                <?php else: ?>
                  <?php foreach ($courts as $i => $c): ?>
                  <tr>
                    <td>
                      <?php if (!empty($c['image']) && file_exists($uploadDir.$c['image'])): ?>
                        <img src="/myproject/images/courts/<?= htmlspecialchars($c['image']) ?>" class="court-thumb" alt=""/>
                      <?php else: ?>
                        <div style="width:52px;height:40px;background:var(--primary-soft);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:18px;">🏸</div>
                      <?php endif; ?>
                    </td>
                    <td><?= $i+1 ?></td>
                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                    <td><?= htmlspecialchars($c['sport']) ?></td>
                    <td>LKR <?= number_format($c['rate_per_hour']) ?>/hr</td>
                    <td><span class="badge <?= $c['status']==='active'?'badge-confirmed':'badge-cancelled' ?>"><?= strtoupper($c['status']) ?></span></td>
                    <td class="table-actions">
                      <button class="btn btn-outline btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($c)) ?>)">✏️ Edit</button>
                      <a href="?delete=<?= $c['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this court?')">🗑️ Delete</a>
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
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal">
  <div class="modal-box" style="max-width:520px;">
    <h3>✏️ Edit Court</h3>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"   value="update"/>
      <input type="hidden" name="court_id" id="editId"/>
      <div class="form-group"><label>Court Name</label><input type="text" name="name" id="editName" required/></div>
      <div class="form-group"><label>Sport Type</label><input type="text" name="sport" id="editSport" required/></div>
      <div class="form-group"><label>Rate (LKR/hour)</label><input type="number" name="rate" id="editRate" required/></div>
      <div class="form-group">
        <label>Status</label>
        <select name="status" id="editStatus">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="form-group">
        <label>Court Image <small style="color:var(--text-muted);">(leave blank to keep existing)</small></label>
        <input type="file" name="image" accept="image/*" onchange="previewImg(this,'editPreview')"/>
        <img id="editCurrentImg" class="img-preview" style="display:block;" alt="Current"/>
        <img id="editPreview"    class="img-preview" alt="New Preview"/>
      </div>

      <!-- ✅ CANCEL BUTTON ADDED HERE -->
      <div class="modal-actions">
        <button type="button" class="btn btn-outline"
                onclick="closeEditModal()">
          ✖ Cancel
        </button>
        <button type="submit" class="btn btn-primary">
          💾 Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(c) {
    document.getElementById('editId').value     = c.id;
    document.getElementById('editName').value   = c.name;
    document.getElementById('editSport').value  = c.sport;
    document.getElementById('editRate').value   = c.rate_per_hour;
    document.getElementById('editStatus').value = c.status;
    const img = document.getElementById('editCurrentImg');
    if (c.image) { img.src = '/myproject/images/courts/'+c.image; img.style.display='block'; }
    else           { img.style.display='none'; }
    document.getElementById('editPreview').style.display = 'none';
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
}
function previewImg(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; preview.style.display='block'; };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
</body>
</html>