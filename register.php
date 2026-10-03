<?php
require_once 'includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];
    if (!$name || !$email || !$phone || !$password || !$confirm) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'This email is already registered. Please login.';
        } else {
            $pdo->prepare("INSERT INTO users (name,email,phone,password,role) VALUES (?,?,?,?,'user')")
                ->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
            $success = 'Account created successfully! You can now login.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Register – Adam Indoors</title>
  <link rel="stylesheet" href="css/style.css"/>
</head>
<body>
<nav class="navbar">
  <div class="logo">🏸 Adam <span>Indoors</span></div>
  <div class="nav-links">
    <a href="index.html">Home</a>
    <a href="login.php">Login</a>
  </div>
</nav>
<div class="auth-page">
  <div class="form-card">
    <div class="card-header">
      <div class="logo-icon">🏸</div>
      <h2>Create Account</h2>
      <p>Register to book your indoor court</p>
    </div>
    <?php if ($error): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?> <a href="login.php">Login here</a></div><?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="name" placeholder="Enter your full name"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required/>
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="Enter your email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required/>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" name="phone" placeholder="07X XXX XXXX"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required/>
      </div>
      <div class="form-group">
        <div class="input-row">
          <div>
            <label>Password</label>
            <input type="password" name="password" placeholder="Min. 8 characters" required/>
          </div>
          <div>
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" placeholder="Repeat password" required/>
          </div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Create Account</button>
    </form>
    <div class="form-footer">Already have an account? <a href="login.php">Login here</a></div>
  </div>
</div>
</body>
</html>