<?php
// hall_admin/one_time_register.php
session_start();
require_once '../config/db.php';

// ✅ Security: remove this page after creating the first hall admin!
// Optional: protect with a secret key in URL
// Example: one_time_register.php?key=MYSECRET
$SETUP_KEY = '12345';
if (!isset($_GET['key']) || $_GET['key'] !== $SETUP_KEY) {
    http_response_code(403);
    die("Forbidden");
}

// 1) Block if a hall_admin already exists


$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $hall_name = trim($_POST['hall_name'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $confirm   = trim($_POST['confirm_password'] ?? '');

    // 2) Basic validation
    if ($full_name === '' || $username === '' || $email === '' || $hall_name === '' || $password === '') {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        // 3) Ensure username/email are unique
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username=? OR email=?");
        $stmt->execute([$username, $email]);
        $exists = (int)$stmt->fetchColumn();

        if ($exists > 0) {
            $error = "Username or email already exists.";
        } else {
            // 4) Insert hall admin (Active immediately)
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO admin_users (full_name, username, email, password, role, hall_name, status, approved_at)
                VALUES (?, ?, ?, ?, 'hall_admin', ?, 'Active', NOW())
            ");
            $ok = $stmt->execute([$full_name, $username, $email, $hash, $hall_name]);

            if ($ok) {
                $success = "Hall Admin created successfully. Now delete this file for security.";
            } else {
                $error = "Failed to create Hall Admin.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>One-time Hall Admin Setup</title>
    <style>
        body{font-family:Arial;margin:30px;background:#f5f5f5}
        .card{max-width:520px;margin:auto;background:#fff;padding:22px;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.08)}
        input{width:100%;padding:10px;margin:8px 0;border:1px solid #ccc;border-radius:8px}
        button{width:100%;padding:10px;border:0;border-radius:8px;background:#1a3c5e;color:#fff;font-weight:bold;cursor:pointer}
        .err{background:#ffe1e1;padding:10px;border-radius:8px;margin-bottom:12px}
        .ok{background:#dff6dd;padding:10px;border-radius:8px;margin-bottom:12px}
        small{color:#666}
    </style>
</head>
<body>
<div class="card">
    <h2>One-time Hall Admin Registration</h2>
    <small>This page works only if no Hall Admin exists. Delete it after use.</small>
    <br><br>

    <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="ok"><?= htmlspecialchars($success) ?></div>
    <?php else: ?>
        <form method="POST">
            <label>Full Name</label>
            <input name="full_name" required>

            <label>Username</label>
            <input name="username" required>

            <label>Email</label>
            <input name="email" type="email" required>

            <label>Hall Name</label>
            <input name="hall_name" placeholder="e.g. Bangamata Sheikh Fazilatunnesa Mujib Hall" required>

            <label>Password</label>
            <input name="password" type="password" required>

            <label>Confirm Password</label>
            <input name="confirm_password" type="password" required>

            <button type="submit">Create Hall Admin</button>
        </form>
    <?php endif; ?>

</div>
</body>
</html>