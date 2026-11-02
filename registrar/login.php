<?php
$pageTitle = 'Registrar Login';
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
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND role = 'registrar'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['user_id'] = $admin['admin_id'];
            $_SESSION['role']    = 'registrar';
            $_SESSION['name']    = $admin['full_name'];
            header('Location: /jkkniu-admission/registrar/dashboard.php');
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
                <h5 class="fw-bold">Registrar Login</h5>
                <p class="text-muted small">JKKNIU Admission Management</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control"
                           placeholder="Registrar username" required>
                </div>
                <div class="mb-3">
                <label class="form-label">Password</label>

                <div class="input-group">
                    <input type="password" name="password" id="password"
                        class="form-control" placeholder="Enter password" required>

                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePassword()">
                        <i class="bi bi-eye" id="toggleIcon"></i>
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
    const pass = document.getElementById("password");
    const icon = document.getElementById("toggleIcon");

    if (pass.type === "password") {
        pass.type = "text";
        icon.classList.replace("bi-eye", "bi-eye-slash");
    } else {
        pass.type = "password";
        icon.classList.replace("bi-eye-slash", "bi-eye");
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
