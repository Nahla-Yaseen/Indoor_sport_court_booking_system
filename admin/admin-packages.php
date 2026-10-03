<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$error = $success = '';

if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $court_id = intval($_POST['court_id']);
    $pkg_type = trim($_POST['package_type']);
    $duration = intval($_POST['duration_hours']);
    $price    = floatval($_POST['price']);
    if (!$court_id || !$pkg_type || !$duration || !$price) {
        $error = 'Please fill in all fields.';
    } else {
        $pdo->prepare("INSERT INTO packages (court_id,package_type,duration_hours,price) VALUES (?,?,?,?)")
            ->execute([$court_id, $pkg_type, $duration, $price]);
        $success = 'Package added successfully!';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $pdo->prepare("UPDATE packages SET court_id=?,package_type=?,duration_hours=?,price=?,status=? WHERE id=?")
        ->execute([
            intval($_POST['court_id']),
            trim($_POST['package_type']),
            intval($_POST['duration_hours']),
            floatval($_POST['price']),
            $_POST['status'],
            intval($_POST['package_id'])
        ]);
    $success = 'Package updated successfully!';
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM packages WHERE id=?")->execute([intval($_GET['delete'])]);
    header("Location: admin-packages.php?deleted=1"); exit();
}
if (isset($_GET['deleted'])) $success = 'Package deleted successfully!';

$courts   = $pdo->query("SELECT * FROM courts WHERE status='active' ORDER BY name")->fetchAll();
$packages = $pdo->query("
    SELECT p.*, c.name AS court_name
    FROM packages p JOIN courts c ON p.court_id = c.id
    ORDER BY c.name, p.duration_hours
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Packages – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
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
      <h1>📦 Manage Packages</h1>
      <p>Create and manage booking packages for each court</p>
    </div>
    <div style="padding:28px 30px;">
      <div class="card" style="margin-bottom:28px;">
        <div class="card-body">
          <div class="card-title">➕ Add New Package</div>
          <?php if ($error):   ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
          <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
          <form method="POST">
            <input type="hidden" name="action" value="add"/>
            <div style="display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr auto;gap:14px;align-items:end;">
              <div class="form-group" style="margin-bottom:0;">
                <label>Select Court</label>
                <select name="court_id" required>
                  <option value="">-- Select Court --</option>
                  <?php foreach ($courts as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label>Package Type</label>
                <input type="text" name="package_type" placeholder="e.g. Standard" required/>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label>Duration (Hours)</label>
                <input type="number" name="duration_hours" placeholder="3" min="1" max="24" required/>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label>Price (LKR)</label>
                <input type="number" name="price" placeholder="1500" min="1" required/>
              </div>
              <div>
                <button type="submit" class="btn btn-primary">Add</button>
              </div>
            </div>
          </form>
        </div>
      </div>
      <div class="card">
        <div class="card-body">
          <div class="card-title">All Packages (<?= count($packages) ?>)</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>ID</th><th>Court</th><th>Package Type</th><th>Duration</th><th>Price (LKR)</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if (empty($packages)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:40px;">No packages found. Add one above.</td></tr>
                <?php else: ?>
                  <?php foreach ($packages as $p): ?>
                  <tr>
                    <td><strong>#<?= $p['id'] ?></strong></td>
                    <td><?= htmlspecialchars($p['court_name']) ?></td>
                    <td style="font-weight:600;color:var(--primary);"><?= htmlspecialchars($p['package_type']) ?></td>
                    <td><?= $p['duration_hours'] ?> hr<?= $p['duration_hours']>1?'s':'' ?></td>
                    <td>LKR <?= number_format($p['price']) ?></td>
                    <td><span class="badge <?= $p['status']==='active'?'badge-confirmed':'badge-cancelled' ?>"><?= strtoupper($p['status']) ?></span></td>
                    <td class="table-actions">
                      <button class="btn btn-outline btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($p)) ?>)">✏️ Edit</button>
                      <a href="?delete=<?= $p['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this package?')">🗑️ Delete</a>
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
  <div class="modal-box" style="max-width:480px;">
    <h3>✏️ Edit Package</h3>
    <form method="POST">
      <input type="hidden" name="action"     value="update"/>
      <input type="hidden" name="package_id" id="editPkgId"/>
      <div class="form-group">
        <label>Court</label>
        <select name="court_id" id="editCourtId" required>
          <?php foreach ($courts as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Package Type</label><input type="text" name="package_type" id="editPkgType" required/></div>
      <div class="form-group"><label>Duration (Hours)</label><input type="number" name="duration_hours" id="editDuration" min="1" max="24" required/></div>
      <div class="form-group"><label>Price (LKR)</label><input type="number" name="price" id="editPrice" min="1" required/></div>
      <div class="form-group">
        <label>Status</label>
        <select name="status" id="editStatus">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('editModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(p) {
    document.getElementById('editPkgId').value    = p.id;
    document.getElementById('editCourtId').value  = p.court_id;
    document.getElementById('editPkgType').value  = p.package_type;
    document.getElementById('editDuration').value = p.duration_hours;
    document.getElementById('editPrice').value    = p.price;
    document.getElementById('editStatus').value   = p.status;
    document.getElementById('editModal').classList.add('open');
}
</script>
</body>
</html>