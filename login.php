<?php
require_once 'includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] === 'admin' ? 'admin/admin-dashboard.php' : 'dashboard.php'));
    exit();
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];
            header("Location: " . ($user['role'] === 'admin' ? 'admin/admin-dashboard.php' : 'dashboard.php'));
            exit();
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login – Adam Indoors</title>
  <link rel="stylesheet" href="css/style.css"/>
</head>
<body>
<nav class="navbar">
  <div class="logo">🏸 Adam <span>Indoors</span></div>
  <div class="nav-links">
    <a href="index.html">Home</a>
    <a href="register.php" class="btn-nav">Register</a>
  </div>
</nav>
<div class="auth-page">
  <div class="form-card">
    <div class="card-header">
      <div class="logo-icon">🔐</div>
      <h2>Welcome Back</h2>
      <p>Login to your Adam Indoors account</p>
    </div>
    <?php if ($error): ?><div class="alert alert-danger">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="Enter your email" required/>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Enter your password" required/>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Login</button>
    </form>
    <div class="divider">or</div>
    <a href="admin/admin-login.php" class="btn btn-outline btn-full">🔑 Admin Login</a>
    <div class="form-footer" style="margin-top:18px;">
      Don't have an account? <a href="register.php">Register here</a>
    </div>
  </div>
</div>
</body>
</html>