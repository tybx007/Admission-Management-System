<?php
$pageTitle = 'Department Admin Login';
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
        $stmt = $pdo->prepare("
            SELECT a.*, d.dept_name, f.faculty_name
            FROM admin_users a
            LEFT JOIN departments d ON a.dept_id = d.dept_id
            LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
            WHERE a.username = ? AND a.role = 'dept_admin'
        ");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            if ($admin['status'] === 'Pending') {
                $error = 'Your account is pending approval by the Super Admin. Please wait for activation.';
            } elseif ($admin['status'] === 'Suspended') {
                $error = 'Your account has been suspended. Please contact the Super Admin.';
            } else {
                $_SESSION['user_id']      = $admin['admin_id'];
                $_SESSION['role']         = 'dept_admin';
                $_SESSION['name']         = $admin['full_name'];
                $_SESSION['dept_id']      = $admin['dept_id'];
                $_SESSION['dept_name']    = $admin['dept_name'];
                $_SESSION['faculty_name'] = $admin['faculty_name'];
                header('Location: /jkkniu-admission/dept_admin/dashboard.php');
                exit();
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>

<div class="login-wrapper">
    <div class="login-card card shadow-lg">
        <div class="card-body p-4">

            <!-- Header -->
            <div class="text-center mb-4">
                <img src="/jkkniu-admission/assets/img/logo.png" class="login-logo mb-2" alt="JKKNIU">
                <h5 class="fw-bold">Department Admin Login</h5>
                <p class="text-muted small">JKKNIU Admission Management System</p>
            </div>

            <!-- Error Alert -->
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" novalidate>

                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-person-fill"></i> Username
                    </label>
                    <input
                        type="text"
                        name="username"
                        class="form-control"
                        placeholder="Enter your username"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        required
                        autofocus
                    >
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-lock-fill"></i> Password
                    </label>
                    <div class="input-group">
                        <input
                            type="password"
                            name="password"
                            id="passwordField"
                            class="form-control"
                            placeholder="Enter your password"
                            required
                        >
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="togglePassword()"
                            title="Show/Hide Password"
                        >
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-bold py-2">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>

            </form>

            <hr class="my-3">

            <!-- Registration & Navigation Links -->
            <div class="text-center">
                <p class="small mb-1">
                    New department admin?
                    <a href="/jkkniu-admission/dept_admin/register.php" class="fw-semibold">
                        Register here
                    </a>
                </p>
                <p class="small mb-0 text-muted">
                    Registration requires Super Admin approval before login.
                </p>
            </div>

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
    const field   = document.getElementById('passwordField');
    const eyeIcon = document.getElementById('eyeIcon');
    if (field.type === 'password') {
        field.type    = 'text';
        eyeIcon.className = 'bi bi-eye-slash';
    } else {
        field.type    = 'password';
        eyeIcon.className = 'bi bi-eye';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
