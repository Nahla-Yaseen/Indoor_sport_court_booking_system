<?php
require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header("Location: admin-dashboard.php"); exit();
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND role='admin'");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();
    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['name']    = $admin['name'];
        $_SESSION['role']    = 'admin';
        header("Location: admin-dashboard.php"); exit();
    } else {
        $error = 'Invalid admin credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Login – Adam Indoors</title>
  <link rel="stylesheet" href="../css/style.css"/>
</head>
<body>
<nav class="navbar">
  <div class="logo">🏸 Adam <span>Indoors</span></div>
  <div class="nav-links">
    <a href="../login.php">User Login</a>
  </div>
</nav>
<div class="auth-page">
  <div class="form-card">
    <div class="card-header">
      <div class="logo-icon">🔑</div>
      <h2>Admin Login</h2>
      <p>Access the Adam Indoors admin panel</p>
    </div>
    <?php if ($error): ?><div class="alert alert-danger">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Admin Email</label>
        <input type="email" name="email" required/>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required/>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Login as Admin</button>
    </form>
    <div class="form-footer">
      <a href="../login.php">← Back to User Login</a>
    </div>
  </div>
</div>
</body>
</html>