<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$users = $pdo->query("
    SELECT u.*, COUNT(b.id) AS total_bookings
    FROM users u
    LEFT JOIN bookings b ON u.id = b.user_id
    WHERE u.role = 'user'
    GROUP BY u.id
    ORDER BY u.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Users – Adam Indoors</title>
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
      <h1>👥 Manage Users</h1>
      <p><?= count($users) ?> registered users</p>
    </div>
    <div style="padding:28px 30px;">
      <div class="card">
        <div class="card-body">
          <div class="card-title">All Registered Users</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Registered On</th><th>Total Bookings</th></tr>
              </thead>
              <tbody>
                <?php if (empty($users)): ?>
                  <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px;">No users registered yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($users as $i => $u): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['phone']) ?></td>
                    <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td>
                      <span class="badge badge-confirmed"><?= $u['total_bookings'] ?> bookings</span>
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
</body>
</html>