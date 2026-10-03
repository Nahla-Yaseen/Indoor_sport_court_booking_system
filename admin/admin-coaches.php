<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$error = $success = '';
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/myproject/images/coaches/';

if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $name  = trim($_POST['name']);
    $sport = trim($_POST['sport']);
    $exp   = intval($_POST['experience_years']);
    $rate  = floatval($_POST['hourly_rate']);
    $desc  = trim($_POST['description']);
    $avail = trim($_POST['availability']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    if (!$name || !$sport || !$rate) {
        $error = 'Please fill in all required fields.';
    } else {
        // Auto-generate Coach ID: find the highest existing numeric cd## and increment
        $lastId = $pdo->query("
            SELECT id FROM coaches
            WHERE id REGEXP '^cd[0-9]+$'
            ORDER BY CAST(SUBSTRING(id,3) AS UNSIGNED) DESC
            LIMIT 1
        ")->fetchColumn();
        if ($lastId) {
            $nextNum = intval(substr($lastId, 2)) + 1;
        } else {
            $nextNum = 1;
        }
        $coach_id = 'cd' . str_pad($nextNum, 2, '0', STR_PAD_LEFT);

        $imageName = null;
        if (!empty($_FILES['image']['name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $imageName = 'coach_'.time().'_'.rand(100,999).'.'.$ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir.$imageName);
            }
        }
        $pdo->prepare("
            INSERT INTO coaches (id,name,sport,experience_years,hourly_rate,description,availability,phone,email,image)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $coach_id,
            $name,$sport,$exp,$rate,$desc,$avail,$phone,$email,$imageName
        ]);
        $success = 'Coach added successfully! (ID: ' . $coach_id . ')';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $id  = trim($_POST['coach_id']);
    $row = $pdo->prepare("SELECT image FROM coaches WHERE id=?");
    $row->execute([$id]);
    $old = $row->fetch();
    $imageName = $old['image'];
    if (!empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
            if ($imageName && file_exists($uploadDir.$imageName)) unlink($uploadDir.$imageName);
            $imageName = 'coach_'.time().'_'.rand(100,999).'.'.$ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir.$imageName);
        }
    }
    $pdo->prepare("
        UPDATE coaches SET name=?,sport=?,experience_years=?,hourly_rate=?,
        description=?,availability=?,phone=?,email=?,status=?,image=? WHERE id=?
    ")->execute([
        trim($_POST['name']),trim($_POST['sport']),
        intval($_POST['experience_years']),floatval($_POST['hourly_rate']),
        trim($_POST['description']),trim($_POST['availability']),
        trim($_POST['phone']),trim($_POST['email']),
        $_POST['status'],$imageName,$id
    ]);
    $success = 'Coach updated successfully!';
}

if (isset($_GET['delete'])) {
    $id  = trim($_GET['delete']);
    $row = $pdo->prepare("SELECT image FROM coaches WHERE id=?");
    $row->execute([$id]);
    $c = $row->fetch();
    if ($c && $c['image'] && file_exists($uploadDir.$c['image'])) unlink($uploadDir.$c['image']);
    $pdo->prepare("DELETE FROM coaches WHERE id=?")->execute([$id]);
    header("Location: admin-coaches.php"); exit();
}

$coaches = $pdo->query("SELECT * FROM coaches ORDER BY sport, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Coaches – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    .coach-thumb { width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--primary-soft); }
    .img-preview{
    width:120px;
    height:120px;
    object-fit:cover;
    border-radius:10px;
    margin-top:10px;
    display:none;
}
    .modal-backdrop{
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 9999;
}

.modal-backdrop.open{
    display: flex;
}

.modal-box{
    background:#fff;
    width:95%;
    max-width:600px;
    max-height:90vh;
    overflow-y:auto;
    border-radius:12px;
    padding:25px;
}

.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:20px;
    position:sticky;
    bottom:0;
    background:#fff;
    padding-top:10px;
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
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="admin-main">
    <div class="page-header">
      <h1>🎽 Manage Coaches</h1>
      <p>Add, edit or remove coaches</p>
    </div>
    <div style="padding:28px 30px;">

      <!-- ADD FORM -->
      <div class="card" style="margin-bottom:28px;">
        <div class="card-body">
          <div class="card-title">➕ Add New Coach</div>
          <?php if ($error):   ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
          <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add"/>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
              <div class="form-group"><label>Full Name <span style="color:red;">*</span></label><input type="text" name="name" required/></div>
              <div class="form-group"><label>Sport <span style="color:red;">*</span></label><input type="text" name="sport" required/></div>
              <div class="form-group"><label>Experience (Years)</label><input type="number" name="experience_years" min="0" max="50"/></div>
              <div class="form-group"><label>Hourly Rate (LKR) <span style="color:red;">*</span></label><input type="number" name="hourly_rate" min="1" required/></div>
              <div class="form-group"><label>Phone</label><input type="tel" name="phone"/></div>
              <div class="form-group"><label>Email</label><input type="email" name="email"/></div>
            </div>
            <div class="form-group"><label>Availability</label><input type="text" name="availability" placeholder="e.g. Mon–Fri: 6AM–10PM"/></div>
            <div class="form-group"><label>Description</label><textarea name="description" rows="3" style="resize:vertical;"></textarea></div>
            <div class="form-group">
              <label>Photo <small style="color:var(--text-muted);">(optional)</small></label>
              <input type="file" name="image" accept="image/*" onchange="previewImg(this,'addPreview')"/>
              <img id="addPreview" class="img-preview" alt="Preview"/>
            </div>
            <button type="submit" class="btn btn-primary">➕ Add Coach</button>
          </form>
        </div>
      </div>

      <!-- COACHES TABLE -->
      <div class="card">
        <div class="card-body">
          <div class="card-title">All Coaches (<?= count($coaches) ?>)</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Photo</th><th>ID</th><th>Name</th><th>Sport</th><th>Exp</th><th>Rate/Hr</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if (empty($coaches)): ?>
                  <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:24px;">No coaches found.</td></tr>
                <?php else: ?>
                  <?php foreach ($coaches as $c): ?>
                  <tr>
                    <td>
                      <?php if (!empty($c['image']) && file_exists($uploadDir.$c['image'])): ?>
                        <img src="/myproject/images/coaches/<?= htmlspecialchars($c['image']) ?>" class="coach-thumb" alt=""/>
                      <?php else: ?>
                        <div style="width:48px;height:48px;border-radius:50%;background:var(--primary-soft);display:flex;align-items:center;justify-content:center;font-size:20px;">👤</div>
                      <?php endif; ?>
                    </td>
                    <td><span style="font-family:monospace;font-size:12px;font-weight:600;color:var(--primary);"><?= htmlspecialchars($c['id']) ?></span></td>
                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                    <td><?= htmlspecialchars($c['sport']) ?></td>
                    <td><?= $c['experience_years'] ?? 0 ?> yrs</td>
                    <td>LKR <?= number_format($c['hourly_rate'] ?? 0) ?></td>
                    <td><span class="badge <?= $c['status']==='active'?'badge-confirmed':'badge-cancelled' ?>"><?= strtoupper($c['status']) ?></span></td>
                    <td class="table-actions">
                      <button class="btn btn-outline btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($c)) ?>)">✏️ Edit</button>
                      <a href="?delete=<?= urlencode($c['id']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this coach?')">🗑️ Delete</a>
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
  <div class="modal-box" style="max-width:580px;">
    <h3>✏️ Edit Coach</h3>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"   value="update"/>
      <input type="hidden" name="coach_id" id="editId"/>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group"><label>Full Name</label><input type="text" name="name" id="editName" required/></div>
        <div class="form-group"><label>Sport</label><input type="text" name="sport" id="editSport" required/></div>
        <div class="form-group"><label>Experience (Years)</label><input type="number" name="experience_years" id="editExp" min="0" max="50"/></div>
        <div class="form-group"><label>Hourly Rate (LKR)</label><input type="number" name="hourly_rate" id="editRate" required/></div>
        <div class="form-group"><label>Phone</label><input type="tel" name="phone" id="editPhone"/></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail"/></div>
      </div>
      <div class="form-group"><label>Availability</label><input type="text" name="availability" id="editAvail"/></div>
      <div class="form-group"><label>Description</label><textarea name="description" id="editDesc" rows="3" style="resize:vertical;"></textarea></div>
      <div class="form-group">
        <label>Status</label>
        <select name="status" id="editStatus">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
     <div class="form-group">

<label>
Photo
<small style="color:#777;">(Leave blank to keep existing photo)</small>
</label>

<input
type="file"
name="image"
accept="image/*"
onchange="previewImg(this,'editPreview')">

<div style="display:flex;gap:15px;flex-wrap:wrap;margin-top:10px;">

<div>
<p style="margin:0;font-size:12px;">Current</p>
<img id="editCurrentImg"
class="img-preview"
style="display:none;">
</div>

<div>
<p style="margin:0;font-size:12px;">New</p>
<img id="editPreview"
class="img-preview"
style="display:none;">
</div>

</div>

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
    document.getElementById('editExp').value    = c.experience_years || 0;
    document.getElementById('editRate').value   = c.hourly_rate || 0;
    document.getElementById('editPhone').value  = c.phone  || '';
    document.getElementById('editEmail').value  = c.email  || '';
    document.getElementById('editAvail').value  = c.availability || '';
    document.getElementById('editDesc').value   = c.description  || '';
    document.getElementById('editStatus').value = c.status || 'active';
    const img = document.getElementById('editCurrentImg');
    if (c.image) { img.src = '/myproject/images/coaches/'+c.image; img.style.display='block'; }
    else           { img.style.display='none'; }
    document.getElementById('editPreview').style.display = 'none';
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
}
function previewImg(input,id){

    const preview=document.getElementById(id);

    if(input.files && input.files[0]){

        const reader=new FileReader();

        reader.onload=function(e){

            preview.src=e.target.result;
            preview.style.display="block";

            // Scroll to bottom so Save button stays visible
            document.querySelector(".modal-box").scrollTop =
                document.querySelector(".modal-box").scrollHeight;
        };

        reader.readAsDataURL(input.files[0]);
    }

}
</script>
</body>
</html>