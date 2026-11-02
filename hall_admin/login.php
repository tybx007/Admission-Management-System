<?php
$pageTitle = 'Hall Admin Login';
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';

redirectIfLoggedIn();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND role = 'hall_admin'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['user_id']   = $admin['admin_id'];
            $_SESSION['role']      = 'hall_admin';
            $_SESSION['name']      = $admin['full_name'];
            $_SESSION['hall_name'] = $admin['hall_name'];
            header('Location: /jkkniu-admission/hall_admin/dashboard.php');
            exit();
        } else {
            $error = 'Invalid credentials.';
        }
    }
}
?>

<div class="login-wrapper">
    <div class="login-card card shadow-lg">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <img src="/jkkniu-admission/assets/img/logo.png" class="login-logo mb-2" alt="JKKNIU">
                <h5 class="fw-bold">Hall Admin Login</h5>
                <p class="text-muted small">JKKNIU Admission Management</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control"
                           placeholder="Admin username" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Password</label>

                <div class="input-group">
                    <input type="password" name="password" id="passwordField"
                        class="form-control" placeholder="Enter password" required>

                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePassword()">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
                <button type="submit" class="btn btn-primary w-100 fw-bold">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
            </form>

            <div class="text-center mt-3">
                <a href="/jkkniu-admission/index.php" class="text-muted small">
                    <i class="bi bi-arrow-left"></i> Back to Home
                </a>
            </div>
        </div>
    </div>
</div>
<script>
function togglePassword() {
    const field = document.getElementById("passwordField");
    const icon = document.getElementById("eyeIcon");

    if (field.type === "password") {
        field.type = "text";
        icon.classList.remove("bi-eye");
        icon.classList.add("bi-eye-slash");
    } else {
        field.type = "password";
        icon.classList.remove("bi-eye-slash");
        icon.classList.add("bi-eye");
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
